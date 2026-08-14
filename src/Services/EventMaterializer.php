<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\MetricProjectionResult;
use ZeroBoiler\Analytics\DTO\ProjectionDefinition;

/**
 * Materializes metric projection results into queryable, cache-backed views.
 *
 * The EventMaterializer sits between the MetricProjectionEngine and consumers
 * (API endpoints, dashboard widgets, CLI commands). It provides:
 *
 * - Pre-computed materialized views for frequently-accessed projections
 * - Automatic refresh on invalidation triggers
 * - Bulk materialization for dashboard rendering
 * - Projected metric snapshots for time-series analysis
 *
 * @since 128.0.0
 * @see MetricProjectionEngine
 * @see ProjectionRegistry
 */
final class EventMaterializer
{
    /** @var string Cache prefix for materialized views */
    private const CACHE_PREFIX = 'zb_materialized_';

    /** @var int Default refresh interval for materialized views (5 minutes) */
    private const DEFAULT_REFRESH_INTERVAL = 300;

    /** @var MetricProjectionEngine */
    private readonly MetricProjectionEngine $engine;

    /** @var ProjectionRegistry */
    private readonly ProjectionRegistry $registry;

    /** @var array<string, \DateTimeImmutable> Last refresh timestamps */
    private array $lastRefresh = [];

    public function __construct(
        MetricProjectionEngine $engine,
        ProjectionRegistry $registry,
    ): void {
        $this->engine = $engine;
        $this->registry = $registry;
    }

    /**
     * Get a materialized projection result.
     *
     * Returns the cached result if still fresh, otherwise evaluates
     * and caches the projection.
     *
     * @param  string  $name  Projection name
     * @return MetricProjectionResult|null
     */
    public function get(string $name): ?MetricProjectionResult
    {
        return $this->engine->evaluate($name);
    }

    /**
     * Get multiple materialized projection results.
     *
     * @param  list<string>  $names
     * @return array<string, MetricProjectionResult|null>
     */
    public function getMultiple(array $names): array
    {
        return $this->engine->evaluateMultiple($names);
    }

    /**
     * Get all materialized projections as a dashboard-ready structure.
     *
     * Returns projections grouped by category with computed values,
     * suitable for rendering in an analytics dashboard.
     *
     * @param  string|null  $category  Filter by category (null = all)
     * @param  string|null  $windowOverride  Override default time window
     * @return array{metrics: array<string, array<string, mixed>>, categories: array<string, int>, total: int}
     */
    public function dashboard(?string $category = null, ?string $windowOverride = null): array
    {
        $projections = $category !== null
            ? $this->registry->byCategory($category)
            : $this->registry->all();

        $metrics = [];
        $categoryCounts = [];

        foreach ($projections as $name => $definition) {
            $result = $this->engine->evaluate($name, $windowOverride);

            $cat = $definition->category ?? 'uncategorized';
            $categoryCounts[$cat] = ($categoryCounts[$cat] ?? 0) + 1;

            $metrics[$name] = [
                'name' => $name,
                'label' => $definition->label,
                'type' => $definition->type,
                'category' => $cat,
                'value' => $result?->value ?? 0,
                'window' => $result?->window ?? $definition->window,
                'cached' => $result?->cached ?? false,
                'stale' => $result?->isStale() ?? false,
                'computed_at' => $result?->computedAt?->format('c'),
                'description' => $definition->description,
                'tags' => $definition->tags,
            ];
        }

        return [
            'metrics' => $metrics,
            'categories' => $categoryCounts,
            'total' => count($metrics),
        ];
    }

    /**
     * Materialize a single projection and record the refresh time.
     *
     * @param  string  $name
     * @return MetricProjectionResult|null
     */
    public function refresh(string $name): ?MetricProjectionResult
    {
        // Invalidate the engine cache for this projection
        $this->engine->invalidate($name);

        $result = $this->engine->evaluate($name);

        $this->lastRefresh[$name] = new \DateTimeImmutable();

        Log::debug('EventMaterializer: refreshed projection', [
            'name' => $name,
            'value' => $result?->value,
        ]);

        return $result;
    }

    /**
     * Refresh all projections.
     *
     * @return array<string, MetricProjectionResult|null>
     */
    public function refreshAll(): array
    {
        $this->engine->invalidateAll();

        $results = [];

        foreach ($this->registry->names() as $name) {
            $results[$name] = $this->refresh($name);
        }

        Log::debug('EventMaterializer: refreshed all projections', [
            'count' => count($results),
        ]);

        return $results;
    }

    /**
     * Get the last refresh timestamp for a projection.
     *
     * @param  string  $name
     * @return \DateTimeImmutable|null
     */
    public function lastRefreshAt(string $name): ?\DateTimeImmutable
    {
        return $this->lastRefresh[$name] ?? null;
    }

    /**
     * Get all projection names that are stale.
     *
     * A projection is considered stale if it hasn't been refreshed
     * within the default refresh interval.
     *
     * @param  int|null  $thresholdSeconds  Custom staleness threshold (null = use default)
     * @return list<string>
     */
    public function staleProjections(?int $thresholdSeconds = null): array
    {
        $threshold = $thresholdSeconds ?? self::DEFAULT_REFRESH_INTERVAL;
        $stale = [];
        $now = new \DateTimeImmutable();

        foreach ($this->registry->names() as $name) {
            $lastRefresh = $this->lastRefresh[$name] ?? null;

            if ($lastRefresh === null) {
                $stale[] = $name;
            } else {
                $diff = $now->getTimestamp() - $lastRefresh->getTimestamp();

                if ($diff >= $threshold) {
                    $stale[] = $name;
                }
            }
        }

        return $stale;
    }

    /**
     * Export all materialized metrics as a flat array.
     * Useful for API responses and exports.
     *
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $results = $this->engine->evaluateAll();
        $export = [];

        foreach ($results as $name => $result) {
            $definition = $this->registry->get($name);

            $export[$name] = [
                'name' => $name,
                'label' => $definition?->label ?? $name,
                'type' => $definition?->type ?? 'count',
                'value' => $result?->value ?? 0,
                'category' => $definition?->category,
                'window' => $result?->window,
                'event_count' => $result?->eventCount ?? 0,
                'computed_at' => $result?->computedAt?->format('c'),
            ];
        }

        return $export;
    }

    /**
     * Get a summary of the materializer state.
     *
     * @return array{projection_count: int, refreshed_count: int, stale_count: int, categories: array<string, int>}
     */
    public function summary(): array
    {
        $registrySummary = $this->registry->summary();
        $stale = $this->staleProjections();

        return [
            'projection_count' => $registrySummary['count'],
            'refreshed_count' => count($this->lastRefresh),
            'stale_count' => count($stale),
            'categories' => $registrySummary['categories'],
        ];
    }
}
