<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Provider dispatch order optimization service.
 *
 * Dynamically determines the optimal dispatch order for each event across
 * all enabled analytics providers. Unlike static routing rules, this service
 * considers real-time provider health, cost constraints, SLA targets,
 * budget utilization, and consent state to produce an intelligent dispatch plan.
 *
 * Provider scoring factors (per dispatch):
 * - **Health score** (0-100): provider connectivity and recent success rate
 * - **SLA compliance** (0-100): how well the provider meets its P95 latency target
 * - **Budget utilization** (0-100): remaining budget percentage (higher = more room)
 * - **Event coverage** (0-100): does this provider support this event type?
 * - **Cost efficiency** (0-100): cost-per-event normalized score
 * - **Consent readiness** (0 or 100): is consent granted for this provider's purpose?
 *
 * The final dispatch order is sorted by composite score (descending).
 * Providers scoring below the minimum threshold are excluded entirely.
 *
 * Configuration: `zeroboiler.analytics.dispatch_order`
 *
 * Inspired by AWS Route 53 health-based routing, CloudFlare load balancing,
 * and Segment's intelligent event routing.
 *
 * @see \ZeroBoiler\Analytics\Services\ProviderSLAMonitor
 * @see \ZeroBoiler\Analytics\Services\ProviderHealthMonitor
 * @see \ZeroBoiler\Analytics\Services\EventBudgetService
 *
 * @since 190.0.0
 */
final class ProviderDispatchOrderService
{
    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'zb_dispatch_order_';

    /** @var int Default cache TTL (5 minutes) */
    private const DEFAULT_TTL = 300;

    /** @var int Minimum score for provider inclusion (0-100) */
    private const MIN_INCLUSION_SCORE = 25;

    /** @var list<string> All supported provider identifiers */
    private const ALL_PROVIDERS = [
        'ga4', 'gtm', 'meta_pixel', 'posthog', 'plausible',
        'mixpanel', 'amplitude', 'tiktok', 'linkedin', 'webhook',
    ];

    /** @var array<string, array{weight: float, description: string}> Scoring factor definitions */
    private const SCORING_FACTORS = [
        'health' => ['weight' => 0.25, 'description' => 'Provider connectivity and success rate'],
        'sla' => ['weight' => 0.20, 'description' => 'SLA compliance (P95 latency, error rate)'],
        'budget' => ['weight' => 0.15, 'description' => 'Remaining dispatch budget utilization'],
        'coverage' => ['weight' => 0.25, 'description' => 'Provider support for this event type'],
        'cost' => ['weight' => 0.10, 'description' => 'Cost efficiency (lower cost = higher score)'],
        'consent' => ['weight' => 0.05, 'description' => 'GDPR consent readiness for this provider'],
    ];

    private readonly bool $enabled;
    private readonly int $cacheTtl;
    private readonly float $minScore;
    private readonly array $providerWeights;
    private readonly array $excludedProviders;
    private readonly bool $respectRouting;
    private readonly CacheRepository $cache;
    private readonly ConfigRepository $config;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;
        $this->config = $config;

        $cfg = $config->get('zeroboiler.analytics.dispatch_order', []);

        /** @var array{enabled?: bool, cache_ttl?: int, min_score?: float, provider_weights?: array<string, float>, excluded_providers?: list<string>, respect_routing?: bool} $cfg */

        $this->enabled = (bool) ($cfg['enabled'] ?? true);
        $this->cacheTtl = (int) ($cfg['cache_ttl'] ?? self::DEFAULT_TTL);
        $this->minScore = (float) ($cfg['min_score'] ?? self::MIN_INCLUSION_SCORE);
        $this->providerWeights = $cfg['provider_weights'] ?? [];
        $this->excludedProviders = $cfg['excluded_providers'] ?? [];
        $this->respectRouting = (bool) ($cfg['respect_routing'] ?? true);
    }

    /**
     * Determine the optimal dispatch order for a given event.
     *
     * Returns a ranked list of providers that should receive this event,
     * sorted by composite score (highest first). Providers scoring below
     * the minimum threshold are excluded.
     *
     * @param  AnalyticsEvent  $event  The event to dispatch
     * @return array{providers: list<array{name: string, score: float, factors: array<string, float>, excluded: bool, reasons: list<string>}>, event: string, category: string, total_considered: int, total_selected: int, computed_at: int}
     */
    public function dispatchPlan(AnalyticsEvent $event): array
    {
        $eventName = $event->name;
        $category = $event->category ?? EventCatalog::getCategory($eventName) ?? 'unknown';
        $timestamp = time();

        $providers = [];
        $totalConsidered = 0;
        $totalSelected = 0;

        foreach (self::ALL_PROVIDERS as $provider) {
            // Skip globally excluded providers
            if (in_array($provider, $this->excludedProviders, true)) {
                $providers[] = $this->excludedEntry($provider, ['Globally excluded via config']);
                continue;
            }

            // Skip disabled providers
            if (! $this->isProviderEnabled($provider)) {
                $providers[] = $this->excludedEntry($provider, ['Provider is disabled']);
                continue;
            }

            $totalConsidered++;
            $factors = $this->scoreFactors($provider, $event, $category);
            $composite = $this->computeCompositeScore($factors, $provider);
            $reasons = $this->exclusionReasons($provider, $event, $category, $composite);
            $excluded = count($reasons) > 0;

            if (! $excluded) {
                $totalSelected++;
            }

            $providers[] = [
                'name' => $provider,
                'score' => round($composite, 2),
                'factors' => array_map(fn (float $v): float => round($v, 2), $factors),
                'excluded' => $excluded,
                'reasons' => $reasons,
            ];
        }

        // Sort by score descending (excluded items go to bottom)
        usort($providers, function (array $a, array $b): int {
            if ($a['excluded'] !== $b['excluded']) {
                return $a['excluded'] ? 1 : -1;
            }
            return $b['score'] <=> $a['score'];
        });

        return [
            'providers' => $providers,
            'event' => $eventName,
            'category' => $category,
            'total_considered' => $totalConsidered,
            'total_selected' => $totalSelected,
            'computed_at' => $timestamp,
        ];
    }

    /**
     * Get the ordered list of selected (non-excluded) provider names for an event.
     *
     * Convenience method that returns just the provider identifiers
     * in optimal dispatch order.
     *
     * @param  AnalyticsEvent  $event
     * @return list<string> Provider names in optimal dispatch order
     */
    public function orderedProviders(AnalyticsEvent $event): array
    {
        $plan = $this->dispatchPlan($event);

        return array_values(array_map(
            fn (array $p): string => $p['name'],
            array_filter($plan['providers'], fn (array $p): bool => ! $p['excluded']),
        ));
    }

    /**
     * Get the composite score breakdown for a specific provider + event.
     *
     * Useful for debugging why a provider was or wasn't selected.
     *
     * @param  string  $provider  Provider identifier
     * @param  AnalyticsEvent  $event
     * @return array{provider: string, event: string, composite_score: float, factors: array<string, float>, excluded: bool, reasons: list<string>}
     */
    public function providerScoreBreakdown(string $provider, AnalyticsEvent $event): array
    {
        $category = $event->category ?? EventCatalog::getCategory($event->name) ?? 'unknown';
        $factors = $this->scoreFactors($provider, $event, $category);
        $composite = $this->computeCompositeScore($factors, $provider);
        $reasons = $this->exclusionReasons($provider, $event, $category, $composite);

        return [
            'provider' => $provider,
            'event' => $event->name,
            'composite_score' => round($composite, 2),
            'factors' => array_map(fn (float $v): float => round($v, 2), $factors),
            'excluded' => count($reasons) > 0,
            'reasons' => $reasons,
        ];
    }

    /**
     * Get a summary of dispatch order preferences across all providers.
     *
     * Aggregated view showing average scores and inclusion rates
     * for use in admin dashboards.
     *
     * @return array{enabled: bool, min_score: float, providers: array<string, array{avg_score: float, inclusion_rate: float, weight_override: float|null}>, scoring_factors: array<string, array{weight: float, description: string}>}
     */
    public function summary(): array
    {
        $providerStats = [];

        foreach (self::ALL_PROVIDERS as $provider) {
            $weightOverride = $this->providerWeights[$provider] ?? null;
            $healthScore = $this->getHealthScore($provider);
            $budgetScore = $this->getBudgetScore($provider);

            $providerStats[$provider] = [
                'avg_score' => round(($healthScore + $budgetScore) / 2, 2),
                'inclusion_rate' => ($healthScore >= $this->minScore && $budgetScore >= $this->minScore) ? 1.0 : 0.0,
                'weight_override' => $weightOverride !== null ? round($weightOverride, 2) : null,
            ];
        }

        $factorDescriptions = [];
        foreach (self::SCORING_FACTORS as $factor => $def) {
            $factorDescriptions[$factor] = [
                'weight' => $def['weight'],
                'description' => $def['description'],
            ];
        }

        return [
            'enabled' => $this->enabled,
            'min_score' => $this->minScore,
            'providers' => $providerStats,
            'scoring_factors' => $factorDescriptions,
        ];
    }

    /**
     * Get the configured scoring factor weights.
     *
     * @return array<string, array{weight: float, description: string}>
     */
    public function scoringFactors(): array
    {
        $factors = [];
        foreach (self::SCORING_FACTORS as $factor => $def) {
            $factors[$factor] = [
                'weight' => $def['weight'],
                'description' => $def['description'],
            ];
        }

        return $factors;
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if a specific provider is enabled in config.
     */
    public function isProviderEnabled(string $provider): bool
    {
        return (bool) ($this->config->get("zeroboiler.analytics.{$this->mapConfigKey($provider)}.enabled", false));
    }

    /**
     * Get the minimum inclusion score threshold.
     */
    public function getMinScore(): float
    {
        return $this->minScore;
    }

    /**
     * Get the list of globally excluded providers.
     *
     * @return list<string>
     */
    public function getExcludedProviders(): array
    {
        return $this->excludedProviders;
    }

    /**
     * Clear the dispatch order cache.
     */
    public function clearCache(): void
    {
        // Iterative cache clearing without wildcards
        $keys = ['health', 'budget', 'sla', 'cost'];
        foreach (self::ALL_PROVIDERS as $provider) {
            foreach ($keys as $key) {
                $this->cache->forget(self::CACHE_PREFIX . "{$provider}_{$key}");
            }
        }
    }

    /**
     * Compute per-factor scores for a provider and event combination.
     *
     * @param  string  $provider
     * @param  AnalyticsEvent  $event
     * @param  string  $category
     * @return array<string, float> Factor name → score (0-100)
     */
    private function scoreFactors(string $provider, AnalyticsEvent $event, string $category): array
    {
        return [
            'health' => $this->getHealthScore($provider),
            'sla' => $this->getSLAScore($provider),
            'budget' => $this->getBudgetScore($provider),
            'coverage' => $this->getCoverageScore($provider, $event),
            'cost' => $this->getCostScore($provider),
            'consent' => $this->getConsentScore($provider),
        ];
    }

    /**
     * Compute the weighted composite score from individual factor scores.
     *
     * @param  array<string, float>  $factors
     * @param  string  $provider  For weight override lookup
     * @return float Composite score (0-100)
     */
    private function computeCompositeScore(array $factors, string $provider): float
    {
        $composite = 0.0;

        foreach (self::SCORING_FACTORS as $factor => $def) {
            $weight = $def['weight'];
            $score = $factors[$factor] ?? 0.0;

            $composite += ($score * $weight);
        }

        // Apply provider-specific weight multiplier if configured
        $override = $this->providerWeights[$provider] ?? null;
        if ($override !== null && $override > 0) {
            $composite *= $override;
        }

        return min(100.0, max(0.0, $composite));
    }

    /**
     * Determine exclusion reasons for a provider + event combination.
     *
     * @param  string  $provider
     * @param  AnalyticsEvent  $event
     * @param  string  $category
     * @param  float  $compositeScore
     * @return list<string> Empty list if provider should be included
     */
    private function exclusionReasons(string $provider, AnalyticsEvent $event, string $category, float $compositeScore): array
    {
        $reasons = [];

        // Below minimum score threshold
        if ($compositeScore < $this->minScore) {
            $reasons[] = "Composite score {$compositeScore} below minimum {$this->minScore}";
        }

        // No event coverage (provider doesn't support this event type)
        $coverage = $this->getCoverageScore($provider, $event);
        if ($coverage <= 0.0) {
            $reasons[] = 'No mapping for this event type in provider catalog';
        }

        // Consent not granted
        $consent = $this->getConsentScore($provider);
        if ($consent <= 0.0 && $provider !== 'webhook') {
            $reasons[] = 'Consent not granted for this provider';
        }

        // Budget exhausted
        $budget = $this->getBudgetScore($provider);
        if ($budget <= 0.0) {
            $reasons[] = 'Dispatch budget exhausted for this provider';
        }

        return $reasons;
    }

    /**
     * Score provider health (0-100).
     *
     * Reads from ProviderHealthMonitor cache or defaults to 100 (assumed healthy).
     */
    private function getHealthScore(string $provider): float
    {
        $cacheKey = self::CACHE_PREFIX . "{$provider}_health";

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null && is_float($cached)) {
            return $cached;
        }

        // Default: assume healthy when no monitoring data available
        return 100.0;
    }

    /**
     * Score provider SLA compliance (0-100).
     *
     * Reads from ProviderSLAMonitor cache or defaults to 100.
     */
    private function getSLAScore(string $provider): float
    {
        $cacheKey = self::CACHE_PREFIX . "{$provider}_sla";

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null && is_float($cached)) {
            return $cached;
        }

        return 100.0;
    }

    /**
     * Score remaining budget utilization (0-100).
     *
     * 100 = plenty of budget remaining, 0 = exhausted.
     * Reads from EventBudgetService cache or defaults to 100.
     */
    private function getBudgetScore(string $provider): float
    {
        $cacheKey = self::CACHE_PREFIX . "{$provider}_budget";

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null && is_float($cached)) {
            return $cached;
        }

        return 100.0;
    }

    /**
     * Score provider event coverage (0 or 100).
     *
     * Checks whether this provider has a mapping for the given event
     * in the EventCatalog. Returns 100 if mapped, 0 if not.
     */
    private function getCoverageScore(string $provider, AnalyticsEvent $event): float
    {
        $catalogEntry = EventCatalog::get($event->name);

        if ($catalogEntry === null) {
            // Unknown event — allow dispatch to all providers (might be custom)
            return 100.0;
        }

        $mapped = $catalogEntry[$this->mapCatalogKey($provider)] ?? null;

        return ($mapped !== null && $mapped !== '') ? 100.0 : 0.0;
    }

    /**
     * Score cost efficiency (0-100).
     *
     * Uses cost-per-event estimates. Free providers score 100.
     * Higher cost = lower score. Reads from EventCostEstimator defaults.
     */
    private function getCostScore(string $provider): float
    {
        $costs = [
            'ga4' => 0.0,
            'gtm' => 0.0,
            'meta_pixel' => 0.0,
            'posthog' => 0.00025,
            'plausible' => 0.0001,
            'mixpanel' => 0.0002,
            'amplitude' => 0.0003,
            'tiktok' => 0.0,
            'linkedin' => 0.0,
            'webhook' => 0.00005,
        ];

        $cost = $costs[$provider] ?? 0.0;

        // Free = 100, $0.001/event = 0
        return max(0.0, 100.0 - ($cost * 1000000.0));
    }

    /**
     * Score consent readiness (0 or 100).
     *
     * Checks if consent has been granted for the analytics purpose
     * associated with this provider. Webhook provider always passes.
     */
    private function getConsentScore(string $provider): float
    {
        // Webhook dispatch doesn't require user consent (server-to-server)
        if ($provider === 'webhook') {
            return 100.0;
        }

        $consentDefault = $this->config->get('zeroboiler.analytics.consent.default', 'granted');

        if ($consentDefault === 'granted') {
            return 100.0;
        }

        // Check cache for actual consent state
        $cacheKey = self::CACHE_PREFIX . 'consent_' . $provider;
        $consented = $this->cache->get($cacheKey);

        if ($consented !== null && is_bool($consented)) {
            return $consented ? 100.0 : 0.0;
        }

        // Default to granted when consent state is unknown
        return 100.0;
    }

    /**
     * Build an excluded provider entry.
     *
     * @param  string  $provider
     * @param  list<string>  $reasons
     * @return array{name: string, score: float, factors: array<string, float>, excluded: true, reasons: list<string>}
     */
    private function excludedEntry(string $provider, array $reasons): array
    {
        return [
            'name' => $provider,
            'score' => 0.0,
            'factors' => [],
            'excluded' => true,
            'reasons' => $reasons,
        ];
    }

    /**
     * Map provider identifier to config key.
     *
     * Converts internal provider names to the config array key format.
     */
    private function mapConfigKey(string $provider): string
    {
        return match ($provider) {
            'meta_pixel' => 'meta_pixel',
            default => $provider,
        };
    }

    /**
     * Map provider identifier to EventCatalog key.
     *
     * Converts internal provider names to the catalog field names.
     */
    private function mapCatalogKey(string $provider): string
    {
        return match ($provider) {
            'meta_pixel' => 'meta',
            'webhook' => 'webhook',
            default => $provider,
        };
    }

    /**
     * Get stats for monitoring and dashboards.
     *
     * @return array{enabled: bool, min_score: float, provider_count: int, scoring_factor_count: int, excluded_count: int}
     */
    public function stats(): array
    {
        return [
            'enabled' => $this->enabled,
            'min_score' => $this->minScore,
            'provider_count' => count(self::ALL_PROVIDERS),
            'scoring_factor_count' => count(self::SCORING_FACTORS),
            'excluded_count' => count($this->excludedProviders),
        ];
    }
}
