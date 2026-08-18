<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
/**
 * Event Inspector Service — debug-mode lifecycle event tracking.
 *
 * Captures detailed information about each stage of the analytics event
 * lifecycle when debug mode is enabled: dispatch → middleware pipeline →
 * enrichment → validation → provider dispatch. Provides an in-memory trace
 * buffer for the most recent events, useful for:
 *
 * - Developer toolbars and debug overlays
 * - CLI-based event inspection (zb:analytics:debug)
 * - Troubleshooting event delivery failures
 * - Pipeline performance profiling (time per stage)
 *
 * All data is stored in cache with a short TTL and limited capacity.
 * Disabled by default; enable via `zeroboiler.analytics.inspector.enabled`.
 *
 * Config: `zeroboiler.analytics.inspector`
 *
 * @since 68.0.0
 */
final class EventInspectorService
{
    private const CACHE_PREFIX = 'zb_inspector_';

    private const DEFAULT_MAX_TRACES = 500;

    private const DEFAULT_TTL = 300; // 5 minutes

    private const DEFAULT_MAX_STAGES = 20;

    private CacheRepository $cache;

    private bool $enabled;

    private int $maxTraces;

    private int $ttl;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  bool  $enabled  Whether event inspection is enabled
     * @param  int  $maxTraces  Maximum trace entries to keep
     * @param  int  $ttl  Cache TTL in seconds
     */
    public function __construct(
        CacheRepository $cache,
        bool $enabled = false,
        int $maxTraces = self::DEFAULT_MAX_TRACES,
        int $ttl = self::DEFAULT_TTL,
    ): void {
        $this->cache = $cache;
        $this->enabled = $enabled;
        $this->maxTraces = $maxTraces;
        $this->ttl = $ttl;
    }

    /**
     * Record a trace for an event lifecycle.
     *
     * @param  string  $eventId  Unique event identifier (hash or UUID)
     * @param  string  $eventName  Event name
     * @param  string  $stage  Lifecycle stage (dispatch, middleware, enrichment, validation, provider_dispatch, complete, error)
     * @param  array<string, mixed>  $context  Stage-specific context data
     * @param  float|null  $durationMs  Duration of this stage in milliseconds
     * @return bool  Whether the trace was stored
     */
    public function recordTrace(
        string $eventId,
        string $eventName,
        string $stage,
        array $context = [],
        ?float $durationMs = null,
    ): bool {
        if (! $this->enabled) {
            return false;
        }

        $entry = [
            'id' => $eventId,
            'event' => $eventName,
            'stage' => $stage,
            'context' => $context,
            'duration_ms' => $durationMs !== null ? round($durationMs, 3) : null,
            'timestamp' => now()->toIso8601String(),
            'microtime' => microtime(true),
        ];

        $key = self::CACHE_PREFIX . 'trace:' . $eventId;
        /** @var list<array<string, mixed>> $traces */
        $traces = $this->cache->get($key, []);
        $traces[] = $entry;

        if (count($traces) > self::DEFAULT_MAX_STAGES) {
            $traces = array_slice($traces, -self::DEFAULT_MAX_STAGES);
        }

        $this->cache->put($key, $traces, $this->ttl);

        // Also add to the global index
        $this->addToIndex($eventId, $eventName);

        return true;
    }

    /**
     * Get the full trace for a specific event.
     *
     * @param  string  $eventId  Event identifier
     * @return array{event_id: string, stages: list<array<string, mixed>>, total_duration_ms: float|null, stage_count: int, has_errors: bool}
     */
    public function getTrace(string $eventId): array
    {
        $key = self::CACHE_PREFIX . 'trace:' . $eventId;
        /** @var list<array<string, mixed>> $stages */
        $stages = $this->cache->get($key, []);

        $totalDuration = null;

        if (count($stages) >= 2) {
            $first = $stages[0]['microtime'] ?? null;
            $last = $stages[count($stages) - 1]['microtime'] ?? null;

            if ($first !== null && $last !== null) {
                $totalDuration = round(($last - $first) * 1000, 3);
            }
        }

        $hasErrors = false;

        foreach ($stages as $stage) {
            if (($stage['stage'] ?? '') === 'error') {
                $hasErrors = true;
                break;
            }
        }

        return [
            'event_id' => $eventId,
            'stages' => $stages,
            'total_duration_ms' => $totalDuration,
            'stage_count' => count($stages),
            'has_errors' => $hasErrors,
        ];
    }

    /**
     * Get the list of recent event traces (index only, no stage details).
     *
     * @param  int  $limit  Max events to return
     * @return list<array{event_id: string, event_name: string, recorded_at: string}>
     */
    public function recentEvents(int $limit = 50): array
    {
        $indexKey = self::CACHE_PREFIX . 'index';
        /** @var list<array<string, string>> $index */
        $index = $this->cache->get($indexKey, []);

        return array_slice($index, -$limit);
    }

    /**
     * Get recent traces with full stage details.
     *
     * @param  int  $limit  Max traces to return
     * @return list<array{event_id: string, event_name: string, recorded_at: string, trace: array<string, mixed>}>
     */
    public function recentTraces(int $limit = 10): array
    {
        $recent = $this->recentEvents($limit);
        $result = [];

        foreach (array_reverse($recent) as $entry) {
            $trace = $this->getTrace($entry['event_id']);

            $result[] = [
                'event_id' => $entry['event_id'],
                'event_name' => $entry['event_name'],
                'recorded_at' => $entry['recorded_at'],
                'trace' => $trace,
            ];
        }

        return $result;
    }

    /**
     * Get inspector summary statistics.
     *
     * @return array{enabled: bool, total_traced: int, recent_count: int, stages_available: list<string>}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'total_traced' => count($this->recentEvents($this->maxTraces)),
            'recent_count' => count($this->recentEvents(50)),
            'stages_available' => [
                'dispatch',
                'middleware',
                'enrichment',
                'validation',
                'provider_dispatch',
                'complete',
                'error',
            ],
        ];
    }

    /**
     * Check if the inspector is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enable the inspector at runtime (useful for debugging).
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Disable the inspector at runtime.
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Clear all inspector data.
     */
    public function clear(): void
    {
        $index = $this->recentEvents($this->maxTraces);

        foreach ($index as $entry) {
            $this->cache->forget(self::CACHE_PREFIX . 'trace:' . $entry['event_id']);
        }

        $this->cache->forget(self::CACHE_PREFIX . 'index');
    }

    /**
     * Add an event to the trace index.
     *
     * @param  string  $eventId
     * @param  string  $eventName
     */
    private function addToIndex(string $eventId, string $eventName): void
    {
        $indexKey = self::CACHE_PREFIX . 'index';
        /** @var list<array<string, string>> $index */
        $index = $this->cache->get($indexKey, []);

        // Avoid duplicates
        foreach ($index as $existing) {
            if ($existing['event_id'] === $eventId) {
                return;
            }
        }

        $index[] = [
            'event_id' => $eventId,
            'event_name' => $eventName,
            'recorded_at' => now()->toIso8601String(),
        ];

        if (count($index) > $this->maxTraces) {
            $index = array_slice($index, -$this->maxTraces);
        }

        $this->cache->put($indexKey, $index, $this->ttl);
    }
}
