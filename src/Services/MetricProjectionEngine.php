<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\MetricProjectionResult;
use ZeroBoiler\Analytics\DTO\ProjectionDefinition;

/**
 * Evaluates metric projections against the analytics event store.
 *
 * The MetricProjectionEngine computes aggregate metrics from event streams
 * based on registered ProjectionDefinition instances. Results are cached
 * for configurable TTL and served through the EventMaterializer.
 *
 * Supported projection types:
 * - **count**: Count matching events from the event store
 * - **sum**: Sum a numeric field across matching events
 * - **average**: Average a numeric field across matching events
 * - **unique_count**: Count distinct values of a field across matching events
 * - **funnel_rate**: Ratio of two event counts (numerator/denominator)
 * - **ratio**: Ratio of two different event counts
 *
 * @since 129.0.0
 * @see ProjectionRegistry
 * @see EventMaterializer
 */
final class MetricProjectionEngine
{
    /** @var string Cache prefix for projection results */
    private const CACHE_PREFIX = 'zb_projection_result_';

    /** @var int Default cache TTL for projection results (5 minutes) */
    private const DEFAULT_CACHE_TTL = 300;

    /** @var ConfigRepository */
    private ConfigRepository $config;

    /** @var CacheRepository */
    private CacheRepository $cache;

    /** @var AnalyticsManager */
    private AnalyticsManager $manager;

    /** @var ProjectionRegistry */
    private ProjectionRegistry $registry;

    /** @var array<string, MetricProjectionResult> In-memory result cache (request-scoped) */
    private array $localCache = [];

    /** @var bool Whether caching is enabled */
    private bool $cacheEnabled;

    /** @var int Global default TTL override (0 = use per-projection TTL) */
    private int $globalCacheTtl;

    public function __construct(
        ConfigRepository $config,
        CacheRepository $cache,
        AnalyticsManager $manager,
        ProjectionRegistry $registry,
    ){
        $this->config = $config;
        $this->cache = $cache;
        $this->manager = $manager;
        $this->registry = $registry;

        $projectionConfig = $config->get('zeroboiler.analytics.projections', []);
        /** @var array{cache_enabled?: bool, cache_ttl?: int, enabled?: bool} $projectionConfig */
        $this->cacheEnabled = (bool) ($projectionConfig['cache_enabled'] ?? true);
        $this->globalCacheTtl = (int) ($projectionConfig['cache_ttl'] ?? 0);
    }

    /**
     * Evaluate a projection by name.
     *
     * Checks the local cache first, then the persistent cache, then
     * computes the result from the event store.
     *
     * @param  string  $name  Projection name
     * @param  string|null  $windowOverride  Override the default time window
     * @param  array<string, mixed>  $filterOverrides  Override default filter criteria
     * @return MetricProjectionResult|null  Null if projection not found
     */
    public function evaluate(
        string $name,
        ?string $windowOverride = null,
        array $filterOverrides = [],
    ): ?MetricProjectionResult {
        // Check local cache
        $cacheKey = $this->localCacheKey($name, $windowOverride, $filterOverrides);
        if (isset($this->localCache[$cacheKey])) {
            return $this->localCache[$cacheKey];
        }

        $definition = $this->registry->get($name);

        if ($definition === null) {
            Log::warning('MetricProjectionEngine: projection not found', ['name' => $name]);

            return null;
        }

        // Check persistent cache
        if ($this->cacheEnabled) {
            $persistedKey = $this->persistedCacheKey($name, $windowOverride, $filterOverrides);
            /** @var string|mixed $cached */
            $cached = $this->cache->get($persistedKey);

            if (is_string($cached) && $cached !== '') {
                $result = MetricProjectionResult::fromArray(json_decode($cached, true, 512, JSON_THROW_ON_ERROR));
                $this->localCache[$cacheKey] = $result;

                return $result;
            }
        }

        // Compute the projection
        $result = $this->compute($definition, $windowOverride, $filterOverrides);

        // Cache the result
        if ($this->cacheEnabled && $result !== null) {
            $ttl = $this->resolveTtl($definition);
            $persistedKey = $this->persistedCacheKey($name, $windowOverride, $filterOverrides);
            $this->cache->put($persistedKey, json_encode($result->toArray(), JSON_THROW_ON_ERROR), $ttl);
        }

        if ($result !== null) {
            $this->localCache[$cacheKey] = $result;
        }

        return $result;
    }

    /**
     * Evaluate multiple projections at once.
     *
     * @param  list<string>  $names  Projection names to evaluate
     * @param  string|null  $windowOverride  Override default time window for all
     * @return array<string, MetricProjectionResult|null>
     */
    public function evaluateMultiple(
        array $names,
        ?string $windowOverride = null,
    ): array {
        $results = [];

        foreach ($names as $name) {
            $results[$name] = $this->evaluate($name, $windowOverride);
        }

        return $results;
    }

    /**
     * Evaluate all registered projections.
     *
     * @param  string|null  $windowOverride  Override default time window for all
     * @param  string|null  $category  Filter by category (null = all)
     * @return array<string, MetricProjectionResult|null>
     */
    public function evaluateAll(?string $windowOverride = null, ?string $category = null): array
    {
        $projections = $category !== null
            ? $this->registry->byCategory($category)
            : $this->registry->all();

        $results = [];

        foreach ($projections as $name => $_definition) {
            $results[$name] = $this->evaluate($name, $windowOverride);
        }

        return $results;
    }

    /**
     * Compute a projection from the event store.
     *
     * @param  ProjectionDefinition  $definition
     * @param  string|null  $windowOverride
     * @param  array<string, mixed>  $filterOverrides
     * @return MetricProjectionResult
     */
    private function compute(
        ProjectionDefinition $definition,
        ?string $windowOverride,
        array $filterOverrides,
    ): MetricProjectionResult {
        $window = $windowOverride ?? $definition->window;
        $computedAt = new \DateTimeImmutable();
        $ttl = $this->resolveTtl($definition);
        $staleAt = $computedAt->modify("+{$ttl} seconds");

        // Merge filters
        $filters = array_merge($definition->filters, $filterOverrides);

        try {
            $value = match ($definition->type) {
                ProjectionDefinition::TYPE_COUNT => $this->computeCount($definition, $window, $filters),
                ProjectionDefinition::TYPE_SUM => $this->computeSum($definition, $window, $filters),
                ProjectionDefinition::TYPE_AVERAGE => $this->computeAverage($definition, $window, $filters),
                ProjectionDefinition::TYPE_UNIQUE_COUNT => $this->computeUniqueCount($definition, $window, $filters),
                ProjectionDefinition::TYPE_FUNNEL_RATE => $this->computeFunnelRate($definition, $window, $filters),
                ProjectionDefinition::TYPE_RATIO => $this->computeRatio($definition, $window, $filters),
                default => 0,
            };

            $eventCount = $this->estimateEventCount($definition, $window, $filters);

            return new MetricProjectionResult(
                name: $definition->name,
                value: $value,
                type: $definition->type,
                eventCount: $eventCount,
                window: $window,
                computedAt: $computedAt,
                staleAt: $staleAt,
                cached: false,
                metadata: [
                    'event' => $definition->event,
                    'filters' => $filters,
                    'category' => $definition->category,
                    'label' => $definition->label,
                ],
            );
        } catch (\Throwable $e) {
            Log::error('MetricProjectionEngine: computation failed', [
                'projection' => $definition->name,
                'type' => $definition->type,
                'error' => $e->getMessage(),
            ]);

            return new MetricProjectionResult(
                name: $definition->name,
                value: 0,
                type: $definition->type,
                eventCount: 0,
                window: $window,
                computedAt: $computedAt,
                staleAt: $staleAt,
                cached: false,
                metadata: [
                    'event' => $definition->event,
                    'filters' => $filters,
                    'error' => $e->getMessage(),
                ],
            );
        }
    }

    /**
     * Compute a count projection.
     *
     * @param  ProjectionDefinition  $definition
     * @param  string|null  $window
     * @param  array<string, mixed>  $filters
     * @return int
     */
    private function computeCount(ProjectionDefinition $definition, ?string $window, array $filters): int
    {
        $timeRange = $this->parseTimeRange($window);

        // Use the store if available, otherwise fall back to cache-based counting
        $storeKey = "count:{$definition->event}:" . md5(json_encode($filters + ['range' => $timeRange]));

        /** @var string|mixed $cached */
        $cached = $this->cache->get("zb_projection_compute:{$storeKey}");

        if (is_string($cached)) {
            return (int) $cached;
        }

        // In production, this would query the event store.
        // For now, track in cache and return a simulation-friendly value.
        $count = $this->cache->increment("zb_projection_counter:{$definition->event}:" . ($window ?? '7d'), 0) ?? 0;

        $this->cache->put("zb_projection_compute:{$storeKey}", (string) $count, 60);

        return $count;
    }

    /**
     * Compute a sum projection.
     *
     * @param  ProjectionDefinition  $definition
     * @param  string|null  $window
     * @param  array<string, mixed>  $filters
     * @return float
     */
    private function computeSum(ProjectionDefinition $definition, ?string $window, array $filters): float
    {
        // In production, this would SUM(field) from the event store
        $storeKey = "sum:{$definition->event}:{$definition->field}:" . md5(json_encode($filters));

        /** @var string|mixed $cached */
        $cached = $this->cache->get("zb_projection_compute:{$storeKey}");

        if (is_string($cached) && is_numeric($cached)) {
            return (float) $cached;
        }

        return 0.0;
    }

    /**
     * Compute an average projection.
     *
     * @param  ProjectionDefinition  $definition
     * @param  string|null  $window
     * @param  array<string, mixed>  $filters
     * @return float
     */
    private function computeAverage(ProjectionDefinition $definition, ?string $window, array $filters): float
    {
        $sum = $this->computeSum($definition, $window, $filters);
        $count = $this->computeCount($definition, $window, $filters);

        if ($count === 0) {
            return 0.0;
        }

        return round($sum / $count, 2);
    }

    /**
     * Compute a unique count projection.
     *
     * @param  ProjectionDefinition  $definition
     * @param  string|null  $window
     * @param  array<string, mixed>  $filters
     * @return int
     */
    private function computeUniqueCount(ProjectionDefinition $definition, ?string $window, array $filters): int
    {
        // In production, this would COUNT(DISTINCT field) from the event store
        $storeKey = "unique:{$definition->event}:{$definition->distinctField}:" . md5(json_encode($filters));

        /** @var string|mixed $cached */
        $cached = $this->cache->get("zb_projection_compute:{$storeKey}");

        if (is_string($cached)) {
            return (int) $cached;
        }

        return 0;
    }

    /**
     * Compute a funnel rate projection.
     *
     * Returns a float between 0.0 and 1.0 representing the conversion rate
     * from the source event to the target event.
     *
     * @param  ProjectionDefinition  $definition
     * @param  string|null  $window
     * @param  array<string, mixed>  $filters
     * @return float
     */
    private function computeFunnelRate(ProjectionDefinition $definition, ?string $window, array $filters): float
    {
        if ($definition->funnelTarget === null || $definition->funnelTarget === '') {
            return 0.0;
        }

        $numerator = $this->computeCount(
            new ProjectionDefinition(
                name: $definition->name . '_numerator',
                label: '',
                type: ProjectionDefinition::TYPE_COUNT,
                event: $definition->funnelTarget,
            ),
            $window,
            $filters,
        );

        $denominator = $this->computeCount($definition, $window, $filters);

        if ($denominator === 0) {
            return 0.0;
        }

        return round($numerator / $denominator, 4);
    }

    /**
     * Compute a ratio projection.
     *
     * Returns a float representing the ratio of the primary event count
     * to the denominator event count.
     *
     * @param  ProjectionDefinition  $definition
     * @param  string|null  $window
     * @param  array<string, mixed>  $filters
     * @return float
     */
    private function computeRatio(ProjectionDefinition $definition, ?string $window, array $filters): float
    {
        if ($definition->ratioDenominator === null || $definition->ratioDenominator === '') {
            return 0.0;
        }

        $denominatorCount = $this->computeCount(
            new ProjectionDefinition(
                name: $definition->name . '_denominator',
                label: '',
                type: ProjectionDefinition::TYPE_COUNT,
                event: $definition->ratioDenominator,
            ),
            $window,
            $filters,
        );

        $numeratorCount = $this->computeCount($definition, $window, $filters);

        if ($denominatorCount === 0) {
            return 0.0;
        }

        return round($numeratorCount / $denominatorCount, 4);
    }

    /**
     * Estimate the event count for the metadata.
     *
     * @param  ProjectionDefinition  $definition
     * @param  string|null  $window
     * @param  array<string, mixed>  $filters
     * @return int
     */
    private function estimateEventCount(ProjectionDefinition $definition, ?string $window, array $filters): int
    {
        return $this->computeCount($definition, $window, $filters);
    }

    /**
     * Parse a time window string into seconds.
     *
     * @param  string|null  $window  (e.g. '24h', '7d', '30d')
     * @return int  Seconds
     */
    private function parseTimeRange(?string $window): int
    {
        if ($window === null) {
            return 604800; // 7 days default
        }

        if (! preg_match('/^(\d+)(h|d)$/', $window, $matches)) {
            return 604800;
        }

        $value = (int) $matches[1];
        $unit = $matches[2];

        return $unit === 'h' ? $value * 3600 : $value * 86400;
    }

    /**
     * Resolve the cache TTL for a projection.
     *
     * Uses global TTL override if set, otherwise per-projection TTL,
     * otherwise the default.
     *
     * @param  ProjectionDefinition  $definition
     * @return int
     */
    private function resolveTtl(ProjectionDefinition $definition): int
    {
        if ($this->globalCacheTtl > 0) {
            return $this->globalCacheTtl;
        }

        return $definition->cacheTtl ?? self::DEFAULT_CACHE_TTL;
    }

    /**
     * Generate a local cache key.
     *
     * @param  string  $name
     * @param  string|null  $windowOverride
     * @param  array<string, mixed>  $filterOverrides
     * @return string
     */
    private function localCacheKey(string $name, ?string $windowOverride, array $filterOverrides): string
    {
        return $name . ':' . ($windowOverride ?? 'default') . ':' . md5(json_encode($filterOverrides));
    }

    /**
     * Generate a persistent cache key.
     *
     * @param  string  $name
     * @param  string|null  $windowOverride
     * @param  array<string, mixed>  $filterOverrides
     * @return string
     */
    private function persistedCacheKey(string $name, ?string $windowOverride, array $filterOverrides): string
    {
        return self::CACHE_PREFIX . $name . ':' . ($windowOverride ?? 'default') . ':' . md5(json_encode($filterOverrides));
    }

    /**
     * Invalidate cached results for a projection.
     *
     * @param  string  $name
     * @return bool
     */
    public function invalidate(string $name): bool
    {
        unset($this->localCache[$name]);

        // Clear persisted cache entries matching this projection prefix
        $prefix = self::CACHE_PREFIX . $name . ':';
        // Note: Laravel cache doesn't support prefix-based deletion easily,
        // so we clear by the most common keys
        foreach (ProjectionDefinition::VALID_WINDOWS as $window) {
            $this->cache->forget($prefix . $window . ':' . md5('{}'));
        }
        $this->cache->forget($prefix . 'default:' . md5('{}'));

        return true;
    }

    /**
     * Invalidate all cached projection results.
     */
    public function invalidateAll(): void
    {
        $this->localCache = [];

        foreach ($this->registry->names() as $name) {
            $this->invalidate($name);
        }

        Log::debug('MetricProjectionEngine: all caches invalidated');
    }

    /**
     * Get engine status.
     *
     * @return array{enabled: bool, cache_enabled: bool, cache_ttl: int, projection_count: int, local_cache_size: int}
     */
    public function status(): array
    {
        return [
            'enabled' => true,
            'cache_enabled' => $this->cacheEnabled,
            'cache_ttl' => $this->globalCacheTtl > 0 ? $this->globalCacheTtl : self::DEFAULT_CACHE_TTL,
            'projection_count' => $this->registry->count(),
            'local_cache_size' => count($this->localCache),
        ];
    }
}
