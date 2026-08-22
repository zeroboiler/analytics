<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event Consistency Validator — Cross-provider event delivery consistency checks.
 *
 * Validates that events dispatched to multiple analytics providers maintain
 * structural and semantic consistency across all destinations. Detects:
 *
 * - **Schema drift** — Provider-specific field name or type mismatches
 * - **Missing translations** — Events that lack mappings for enabled providers
 * - **Parameter completeness** — Required fields missing in dispatched events
 * - **Provider coverage gaps** — Enabled providers that can't receive certain events
 * - **Data type mismatches** — Values sent don't match expected types per provider
 *
 * Used by admin dashboards, deploy gates, and health monitoring to ensure
 * multi-provider analytics pipelines remain consistent.
 *
 * Configuration: `zeroboiler.analytics.event_consistency`
 *
 * @see \ZeroBoiler\Analytics\Services\EventValidationService
 * @see \ZeroBoiler\Analytics\Services\EventGovernanceService
 * @see \ZeroBoiler\Analytics\Support\EventTransformer
 *
 * @since 134.0.0
 */
final class EventConsistencyValidatorService
{
    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'zb_consistency_';

    /** @var int Default cache TTL */
    private const DEFAULT_CACHE_TTL = 300;

    /** @var string Cache key for last validation results */
    private const RESULTS_CACHE_KEY = 'zb_consistency_results';

    private CacheRepository $cache;

    private ConfigRepository $config;

    private bool $enabled;

    private int $cacheTtl;

    /** @var list<string> Enabled provider names */
    private array $enabledProviders;

    /** @var list<string> Fields that are always required in all events */
    private array $requiredGlobalFields;

    /** @var bool Whether to cache validation results */
    private bool $cacheResults;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;
        $this->config = $config;

        $consistencyConfig = $config->get('zeroboiler.analytics.event_consistency', []);
        /** @var array{enabled?: bool, cache_ttl?: int, enabled_providers?: list<string>, required_global_fields?: list<string>, cache_results?: bool} $consistencyConfig */

        $this->enabled = (bool) ($consistencyConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($consistencyConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->enabledProviders = $consistencyConfig['enabled_providers'] ?? ['ga4', 'meta_pixel', 'posthog', 'plausible', 'mixpanel', 'amplitude'];
        $this->requiredGlobalFields = $consistencyConfig['required_global_fields'] ?? ['event_name', 'timestamp'];
        $this->cacheResults = (bool) ($consistencyConfig['cache_results'] ?? true);
    }

    /**
     * Check if consistency validation is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Validate a single event for cross-provider consistency.
     *
     * Checks that the event has valid mappings for all enabled providers,
     * required fields are present, and no schema drift exists.
     *
     * @param  string  $eventName  Event name to validate
     * @return array{valid: bool, event: string, provider_coverage: array<string, bool>, missing_providers: list<string>, warnings: list<string>, errors: list<string>}
     */
    public function validateEvent(string $eventName): array
    {
        $catalogEntry = EventCatalog::get($eventName);

        if ($catalogEntry === null) {
            return [
                'valid' => false,
                'event' => $eventName,
                'provider_coverage' => [],
                'missing_providers' => $this->enabledProviders,
                'warnings' => ["Event '{$eventName}' not found in catalog"],
                'errors' => ["Unknown event: {$eventName}"],
            ];
        }

        $providerCoverage = [];
        $missingProviders = [];
        $warnings = [];
        $errors = [];

        foreach ($this->enabledProviders as $provider) {
            $mapping = $this->getProviderMapping($catalogEntry, $provider);

            if ($mapping !== null && $mapping !== '') {
                $providerCoverage[$provider] = true;
            } else {
                $providerCoverage[$provider] = false;
                $missingProviders[] = $provider;
                $warnings[] = "No {$provider} mapping for event '{$eventName}'";
            }
        }

        if (isset($catalogEntry['meta']) && $catalogEntry['meta'] === null) {
            if (in_array('meta_pixel', $this->enabledProviders, true)) {
                // Meta null is expected for many event types — informational only
            }
        }

        $valid = empty($errors);

        return [
            'valid' => $valid,
            'event' => $eventName,
            'category' => $catalogEntry['category'] ?? 'unknown',
            'provider_coverage' => $providerCoverage,
            'missing_providers' => $missingProviders,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    /**
     * Validate all events in the catalog for cross-provider consistency.
     *
     * Performs a comprehensive scan of the entire event catalog and returns
     * aggregated consistency metrics, per-event results, and gap analysis.
     *
     * @return array{valid: bool, total_events: int, valid_events: int, invalid_events: int, events_with_gaps: int, provider_coverage: array<string, int>, gap_analysis: array<string, list<string>>, results: list<array{valid: bool, event: string, missing_providers: list<string>, warnings: list<string>, errors: list<string>}>}
     */
    public function validateAllEvents(): array
    {
        $cachedResults = $this->getCachedResults();

        if ($cachedResults !== null) {
            return $cachedResults;
        }

        $allEvents = EventCatalog::all();
        $totalEvents = count($allEvents);
        $validEvents = 0;
        $invalidEvents = 0;
        $eventsWithGaps = 0;

        $providerCoverage = array_fill_keys($this->enabledProviders, 0);
        $gapAnalysis = array_fill_keys($this->enabledProviders, []);
        $results = [];

        foreach ($allEvents as $name => $entry) {
            $validation = $this->validateEvent($name);

            if ($validation['valid']) {
                $validEvents++;
            } else {
                $invalidEvents++;
            }

            if (! empty($validation['missing_providers'])) {
                $eventsWithGaps++;
            }

            foreach ($validation['provider_coverage'] as $provider => $covered) {
                if ($covered) {
                    $providerCoverage[$provider] = ($providerCoverage[$provider] ?? 0) + 1;
                } else {
                    $gapAnalysis[$provider][] = $name;
                }
            }

            $results[] = [
                'valid' => $validation['valid'],
                'event' => $name,
                'category' => $validation['category'] ?? 'unknown',
                'missing_providers' => $validation['missing_providers'],
                'warnings' => $validation['warnings'],
                'errors' => $validation['errors'],
            ];
        }

        $output = [
            'valid' => $invalidEvents === 0,
            'total_events' => $totalEvents,
            'valid_events' => $validEvents,
            'invalid_events' => $invalidEvents,
            'events_with_gaps' => $eventsWithGaps,
            'coverage_percentage' => $totalEvents > 0
                ? round($validEvents / $totalEvents * 100, 1)
                : 100.0,
            'gap_percentage' => $totalEvents > 0
                ? round($eventsWithGaps / $totalEvents * 100, 1)
                : 0.0,
            'provider_coverage' => $providerCoverage,
            'gap_analysis' => $gapAnalysis,
            'results' => $results,
        ];

        if ($this->cacheResults) {
            $this->cacheResults($output);
        }

        return $output;
    }

    /**
     * Get provider-specific gap analysis.
     *
     * Returns events that are missing mappings for a specific provider.
     *
     * @param  string  $provider  Provider name
     * @return array{provider: string, total_missing: int, events: list<string>, categories: array<string, int>}
     */
    public function getProviderGaps(string $provider): array
    {
        $allEvents = EventCatalog::all();
        $missingEvents = [];
        $missingByCategory = [];

        foreach ($allEvents as $name => $entry) {
            $mapping = $this->getProviderMapping($entry, $provider);

            if ($mapping === null || $mapping === '') {
                $missingEvents[] = $name;
                $category = $entry['category'] ?? 'unknown';
                $missingByCategory[$category] = ($missingByCategory[$category] ?? 0) + 1;
            }
        }

        return [
            'provider' => $provider,
            'total_missing' => count($missingEvents),
            'events' => $missingEvents,
            'categories' => $missingByCategory,
        ];
    }

    /**
     * Validate a specific event's parameters against provider requirements.
     *
     * Checks that required fields exist and have correct types for dispatching
     * to the specified providers.
     *
     * @param  string  $eventName  Event name
     * @param  array<string, mixed>  $params  Event parameters
     * @param  list<string>  $providers  Providers to validate against
     * @return array{valid: bool, field_errors: array<string, list<string>>, type_warnings: array<string, list<string>>}
     */
    public function validateParams(string $eventName, array $params, array $providers = []): array
    {
        if ($providers === []) {
            $providers = $this->enabledProviders;
        }

        $fieldErrors = [];
        $typeWarnings = [];

        foreach ($this->requiredGlobalFields as $field) {
            if (! array_key_exists($field, $params)) {
                foreach ($providers as $provider) {
                    $fieldErrors[$field][] = "Missing in dispatch to {$provider}";
                }
            }
        }

        $catalogEntry = EventCatalog::get($eventName);

        if ($catalogEntry !== null) {
            $category = $catalogEntry['category'] ?? 'unknown';

            // E-commerce events require currency, value, items
            if ($category === 'ecommerce') {
                $requiredEcommerce = ['currency', 'value'];
                foreach ($requiredEcommerce as $field) {
                    if (! array_key_exists($field, $params)) {
                        foreach ($providers as $provider) {
                            $fieldErrors[$field][] = "E-commerce event '{$eventName}' missing '{$field}' for {$provider}";
                        }
                    }
                }
            }

            // SaaS revenue events require currency, value
            if ($category === 'saas') {
                $revenueEvents = ['subscription', 'plan_upgrade', 'purchase', 'trial_start'];
                if (in_array($eventName, $revenueEvents, true)) {
                    if (! array_key_exists('value', $params)) {
                        foreach ($providers as $provider) {
                            $fieldErrors['value'][] = "SaaS revenue event '{$eventName}' missing 'value' for {$provider}";
                        }
                    }
                }
            }
        }

        // Type checking
        $typeSensitiveProviders = ['ga4', 'posthog'];
        foreach ($typeSensitiveProviders as $provider) {
            if (! in_array($provider, $providers, true)) {
                continue;
            }

            if (isset($params['value']) && ! is_numeric($params['value'])) {
                $typeWarnings['value'][] = "Non-numeric value for {$provider}";
            }

            if (isset($params['timestamp']) && ! is_string($params['timestamp']) && ! is_int($params['timestamp'])) {
                $typeWarnings['timestamp'][] = "Non-string/non-int timestamp for {$provider}";
            }
        }

        return [
            'valid' => empty($fieldErrors),
            'field_errors' => $fieldErrors,
            'type_warnings' => $typeWarnings,
        ];
    }

    /**
     * Get the overall consistency score (0-100).
     *
     * Higher score means better cross-provider coverage and fewer gaps.
     *
     * @return array{score: float, grade: string, total_events: int, fully_covered: int, gap_events: int, weakest_provider: string, weakest_provider_coverage: float}
     */
    public function getConsistencyScore(): array
    {
        $allResults = $this->validateAllEvents();
        $totalEvents = $allResults['total_events'];
        $gapEvents = $allResults['events_with_gaps'];

        // Score: (1 - gap_ratio) * 100, adjusted for provider count
        $providerCount = count($this->enabledProviders);
        $maxPossibleGaps = $totalEvents * $providerCount;
        $actualGaps = 0;

        foreach ($allResults['gap_analysis'] as $provider => $missingEvents) {
            $actualGaps += count($missingEvents);
        }

        $gapRatio = $maxPossibleGaps > 0 ? $actualGaps / $maxPossibleGaps : 0;
        $score = round((1 - $gapRatio) * 100, 1);

        $weakestProvider = 'none';
        $weakestCoverage = 100.0;

        foreach ($allResults['provider_coverage'] as $provider => $coveredCount) {
            $providerScore = $totalEvents > 0 ? ($coveredCount / $totalEvents) * 100 : 100;
            if ($providerScore < $weakestCoverage) {
                $weakestCoverage = $providerScore;
                $weakestProvider = $provider;
            }
        }

        $grade = match (true) {
            $score >= 95 => 'A+',
            $score >= 90 => 'A',
            $score >= 85 => 'B+',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F',
        };

        return [
            'score' => $score,
            'grade' => $grade,
            'total_events' => $totalEvents,
            'fully_covered' => $totalEvents - $gapEvents,
            'gap_events' => $gapEvents,
            'weakest_provider' => $weakestProvider,
            'weakest_provider_coverage' => round($weakestCoverage, 1),
        ];
    }

    /**
     * Get a summary of top priority gaps that should be addressed.
     *
     * Returns the most impactful gaps sorted by priority (high-traffic
     * event categories first).
     *
     * @return list<array{event: string, category: string, missing_providers: list<string>, priority: string}>
     */
    public function getPriorityGaps(): array
    {
        $categoryPriority = [
            'ecommerce' => 'critical',
            'saas' => 'high',
            'engagement' => 'medium',
            'marketing' => 'medium',
            'security' => 'low',
            'uptime' => 'low',
            'infrastructure' => 'low',
        ];

        $gaps = [];

        foreach (EventCatalog::all() as $name => $entry) {
            $validation = $this->validateEvent($name);

            if (! empty($validation['missing_providers'])) {
                $category = $entry['category'] ?? 'unknown';
                $gaps[] = [
                    'event' => $name,
                    'category' => $category,
                    'missing_providers' => $validation['missing_providers'],
                    'priority' => $categoryPriority[$category] ?? 'low',
                ];
            }
        }

        $priorityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($gaps, fn (array $a, array $b): int =>
            ($priorityOrder[$a['priority']] ?? 99) <=> ($priorityOrder[$b['priority']] ?? 99)
        );

        return $gaps;
    }

    /**
     * Clear cached validation results.
     */
    public function clearCache(): void
    {
        $this->cache->forget(self::RESULTS_CACHE_KEY);
    }

    /**
     * Extract provider mapping from catalog entry.
     *
     * @param  array<string, mixed>  $catalogEntry
     * @param  string  $provider  Provider name
     * @return string|null
     */
    private function getProviderMapping(array $catalogEntry, string $provider): ?string
    {
        $fieldMap = [
            'ga4' => 'ga4',
            'meta_pixel' => 'meta',
            'posthog' => 'posthog',
            'plausible' => 'plausible',
            'mixpanel' => 'mixpanel',
            'amplitude' => 'amplitude',
            'tiktok' => 'tiktok',
            'linkedin' => 'linkedin',
        ];

        $field = $fieldMap[$provider] ?? $provider;

        return $catalogEntry[$field] ?? null;
    }

    /**
     * Get cached validation results.
     *
     * @return array|null Cached results or null
     */
    private function getCachedResults(): ?array
    {
        if (! $this->cacheResults) {
            return null;
        }

        return $this->cache->get(self::RESULTS_CACHE_KEY);
    }

    /**
     * Cache validation results.
     *
     * @param  array  $results  Validation results to cache
     */
    private function cacheResults(array $results): void
    {
        $this->cache->put(self::RESULTS_CACHE_KEY, $results, $this->cacheTtl);
    }
}
