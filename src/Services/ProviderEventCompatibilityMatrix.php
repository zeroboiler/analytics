<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\SimpleCache\InvalidArgumentException;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Provider event compatibility matrix — comprehensive gap analysis across all providers.
 *
 * Analyzes which events from the EventCatalog are supported by which providers
 * and identifies gaps where events are not mapped to a specific provider.
 *
 * Provides:
 * - Per-provider coverage percentage
 * - Event-level gap analysis (which events are missing from which providers)
 * - Provider readiness scoring (how well each provider covers the full catalog)
 * - Event popularity ranking (which events have the most/least provider support)
 * - Gap closure recommendations (prioritized list of events to add mappings for)
 *
 * Config: `zeroboiler.analytics.provider_matrix`
 *
 * @since 46.0.0
 */
final class ProviderEventCompatibilityMatrix
{
    private bool $enabled;

    private string $cachePrefix;

    private int $cacheTtl;

    private CacheRepository $cache;

    /** @var list<string> All supported providers */
    private const PROVIDERS = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude'];

    /**
     * Create a new ProviderEventCompatibilityMatrix.
     *
     * @param  CacheRepository  $cache  Cache repository for computed results
     * @param  ConfigRepository  $config  Application config repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $matrixConfig = $config->get('zeroboiler.analytics.provider_matrix', []);
        /** @var array{enabled?: bool, cache_prefix?: string, cache_ttl?: int} $matrixConfig */

        $this->enabled = (bool) ($matrixConfig['enabled'] ?? true);
        $this->cachePrefix = (string) ($matrixConfig['cache_prefix'] ?? 'zb_pem_');
        $this->cacheTtl = (int) ($matrixConfig['cache_ttl'] ?? 3600);
    }

    /**
     * Get the full compatibility matrix.
     *
     * Returns a 2D matrix: event_name × provider → mapped|null.
     *
     * @return array{events: int, providers: list<string>, matrix: array<string, array<string, string|null>>}
     */
    public function getMatrix(): array
    {
        $catalog = EventCatalog::all();
        $matrix = [];

        foreach ($catalog as $eventName => $entry) {
            $matrix[$eventName] = [
                'ga4' => $entry['ga4'] ?? $eventName,
                'meta' => $entry['meta'] ?? null,
                'posthog' => $entry['posthog'] ?? $eventName,
                'plausible' => $entry['plausible'] ?? null,
                'mixpanel' => $entry['mixpanel'] ?? null,
                'amplitude' => $entry['amplitude'] ?? null,
            ];
        }

        return [
            'events' => count($matrix),
            'providers' => self::PROVIDERS,
            'matrix' => $matrix,
        ];
    }

    /**
     * Get per-provider coverage percentage and stats.
     *
     * @return array<string, array{provider: string, total_events: int, mapped_count: int, coverage_pct: float, unmapped: list<string>}>
     */
    public function getProviderCoverage(): array
    {
        try {
            $cacheKey = $this->cachePrefix . 'provider_coverage';

            /** @var array<string, array{provider: string, total_events: int, mapped_count: int, coverage_pct: float, unmapped: list<string>}>|null $cached */
            $cached = $this->cache->get($cacheKey, null);

            if ($cached !== null) {
                return $cached;
            }
        } catch (InvalidArgumentException $e) {
            // Fall through to compute
        }

        $catalog = EventCatalog::all();
        $totalEvents = count($catalog);
        $coverage = [];

        foreach (self::PROVIDERS as $provider) {
            $mappedCount = 0;
            $unmapped = [];

            foreach ($catalog as $eventName => $entry) {
                $mapped = $entry[$provider] ?? null;
                if ($mapped !== null && $mapped !== '') {
                    $mappedCount++;
                } else {
                    $unmapped[] = $eventName;
                }
            }

            $coverage[$provider] = [
                'provider' => $provider,
                'total_events' => $totalEvents,
                'mapped_count' => $mappedCount,
                'coverage_pct' => $totalEvents > 0 ? round(($mappedCount / $totalEvents) * 100, 2) : 0.0,
                'unmapped' => $unmapped,
            ];
        }

        try {
            $this->cache->put($this->cachePrefix . 'provider_coverage', $coverage, $this->cacheTtl);
        } catch (InvalidArgumentException $e) {
            // Ignore cache errors
        }

        return $coverage;
    }

    /**
     * Analyze gaps for a specific event across all providers.
     *
     * @param  string  $eventName  The event name to analyze
     * @return array{event: string, providers: array<string, string|null>, gap_count: int, has_ga4: bool, has_meta: bool, has_posthog: bool, has_plausible: bool, has_mixpanel: bool, has_amplitude: bool}
     */
    public function analyzeEventGaps(string $eventName): array
    {
        $entry = EventCatalog::get($eventName);

        if ($entry === null) {
            return [
                'event' => $eventName,
                'providers' => array_fill_keys(self::PROVIDERS, null),
                'gap_count' => count(self::PROVIDERS),
                'has_ga4' => false,
                'has_meta' => false,
                'has_posthog' => false,
                'has_plausible' => false,
                'has_mixpanel' => false,
                'has_amplitude' => false,
            ];
        }

        $mappings = [];
        $gapCount = 0;

        foreach (self::PROVIDERS as $provider) {
            $mapped = $entry[$provider] ?? null;
            $mappings[$provider] = $mapped;
            if ($mapped === null || $mapped === '') {
                $gapCount++;
            }
        }

        return [
            'event' => $eventName,
            'providers' => $mappings,
            'gap_count' => $gapCount,
            'has_ga4' => ($entry['ga4'] ?? null) !== null,
            'has_meta' => ($entry['meta'] ?? null) !== null,
            'has_posthog' => ($entry['posthog'] ?? null) !== null,
            'has_plausible' => ($entry['plausible'] ?? null) !== null,
            'has_mixpanel' => ($entry['mixpanel'] ?? null) !== null,
            'has_amplitude' => ($entry['amplitude'] ?? null) !== null,
        ];
    }

    /**
     * Get provider readiness scores — how production-ready each provider is.
     *
     * Scores (0-100) based on:
     * - Event coverage (40% weight)
     * - Named mapping specificity (30% weight) — provider-specific names vs generic
     * - Category coverage (30% weight) — events across all categories
     *
     * @return array{scores: array<string, array{provider: string, score: float, coverage_weight: float, specificity_weight: float, category_weight: float}>, recommendation: string}
     */
    public function getReadinessScores(): array
    {
        $coverage = $this->getProviderCoverage();
        $catalog = EventCatalog::all();
        $totalEvents = count($catalog);

        // Count events per category
        $categoryCounts = [];
        foreach ($catalog as $eventName => $entry) {
            $cat = $entry['category'] ?? 'unknown';
            $categoryCounts[$cat] = ($categoryCounts[$cat] ?? 0) + 1;
        }
        $totalCategories = count($categoryCounts);

        $scores = [];

        foreach (self::PROVIDERS as $provider) {
            $providerData = $coverage[$provider] ?? [];
            $mappedCount = $providerData['mapped_count'] ?? 0;

            // Coverage weight (40%)
            $coverageScore = $totalEvents > 0 ? ($mappedCount / $totalEvents) * 40 : 0;

            // Specificity weight (30%) — events with provider-specific names
            $specificCount = 0;
            foreach ($catalog as $entry) {
                $mapped = $entry[$provider] ?? null;
                if ($mapped !== null && $mapped !== '' && $mapped !== $entry['name']) {
                    $specificCount++;
                }
            }
            $specificityScore = $mappedCount > 0 ? ($specificCount / $mappedCount) * 30 : 0;

            // Category weight (30%) — at least one event per category
            $coveredCategories = 0;
            foreach ($categoryCounts as $cat => $count) {
                $catEvents = EventCatalog::category($cat);
                foreach ($catEvents as $entry) {
                    $mapped = $entry[$provider] ?? null;
                    if ($mapped !== null && $mapped !== '') {
                        $coveredCategories++;
                        break;
                    }
                }
            }
            $categoryScore = $totalCategories > 0 ? ($coveredCategories / $totalCategories) * 30 : 0;

            $scores[$provider] = [
                'provider' => $provider,
                'score' => round($coverageScore + $specificityScore + $categoryScore, 2),
                'coverage_weight' => round($coverageScore, 2),
                'specificity_weight' => round($specificityScore, 2),
                'category_weight' => round($categoryScore, 2),
            ];
        }

        // Find best provider
        $bestProvider = 'ga4';
        $bestScore = 0;
        foreach ($scores as $p => $data) {
            if ($data['score'] > $bestScore) {
                $bestScore = $data['score'];
                $bestProvider = $p;
            }
        }

        return [
            'scores' => $scores,
            'recommendation' => "Best coverage: {$bestProvider} ({$bestScore}/100)",
        ];
    }

    /**
     * Get events ranked by provider support (most to least supported).
     *
     * @param  int  $limit  Maximum events to return
     * @return list<array{event: string, provider_count: int, providers: list<string>, category: string|null}>
     */
    public function eventPopularityRanking(int $limit = 25): array
    {
        $catalog = EventCatalog::all();
        $ranked = [];

        foreach ($catalog as $eventName => $entry) {
            $supportedProviders = [];
            foreach (self::PROVIDERS as $provider) {
                $mapped = $entry[$provider] ?? null;
                if ($mapped !== null && $mapped !== '') {
                    $supportedProviders[] = $provider;
                }
            }

            $ranked[] = [
                'event' => $eventName,
                'provider_count' => count($supportedProviders),
                'providers' => $supportedProviders,
                'category' => $entry['category'] ?? null,
            ];
        }

        // Sort by provider count descending
        usort($ranked, fn (array $a, array $b): int => $b['provider_count'] <=> $a['provider_count']);

        return array_slice($ranked, 0, $limit);
    }

    /**
     * Get gap closure recommendations — prioritized events needing mappings.
     *
     * Prioritizes by:
     * 1. Number of providers missing the event
     * 2. Event category importance (ecommerce and saas ranked higher)
     *
     * @param  string  $provider  Provider to analyze gaps for
     * @param  int  $limit  Maximum recommendations
     * @return list<array{event: string, category: string|null, missing_providers: list<string>, priority: string}>
     */
    public function getGapRecommendations(string $provider, int $limit = 25): array
    {
        $catalog = EventCatalog::all();
        $gaps = [];

        // Category importance weights
        $categoryWeights = [
            'ecommerce' => 3,
            'saas' => 3,
            'engagement' => 2,
            'security' => 2,
            'infrastructure' => 1,
            'uptime' => 1,
        ];

        foreach ($catalog as $eventName => $entry) {
            $mapped = $entry[$provider] ?? null;
            if ($mapped === null || $mapped === '') {
                $category = $entry['category'] ?? 'unknown';
                $weight = $categoryWeights[$category] ?? 1;

                // Count how many providers are missing this event
                $missingProviders = [];
                foreach (self::PROVIDERS as $p) {
                    $pMapped = $entry[$p] ?? null;
                    if ($pMapped === null || $pMapped === '') {
                        $missingProviders[] = $p;
                    }
                }

                $gaps[] = [
                    'event' => $eventName,
                    'category' => $category,
                    'missing_providers' => $missingProviders,
                    'priority' => $weight >= 3 ? 'high' : ($weight >= 2 ? 'medium' : 'low'),
                    '_weight' => $weight * count($missingProviders),
                ];
            }
        }

        // Sort by weight descending
        usort($gaps, fn (array $a, array $b): int => $b['_weight'] <=> $a['_weight']);

        // Remove internal weight field
        $result = [];
        foreach (array_slice($gaps, 0, $limit) as $gap) {
            unset($gap['_weight']);
            $result[] = $gap;
        }

        return $result;
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get a summary of the provider matrix.
     *
     * @return array{enabled: bool, providers: list<string>, catalog_size: int, cache_ttl: int}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'providers' => self::PROVIDERS,
            'catalog_size' => EventCatalog::count(),
            'cache_ttl' => $this->cacheTtl,
        ];
    }

    /**
     * Clear cached analysis results.
     */
    public function clearCache(): void
    {
        try {
            $this->cache->forget($this->cachePrefix . 'provider_coverage');
        } catch (InvalidArgumentException $e) {
            // Ignore cache errors
        }
    }
}
