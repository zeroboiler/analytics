<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event replay audit ledger.
 *
 * Records an immutable audit trail of all event replay operations,
 * including who triggered the replay, which events were replayed,
 * the original and replay dispatch outcomes, and timestamps.
 *
 * Supports:
 * - Per-replay operation ledger entries with unique operation IDs
 * - Event-level replay tracking (original vs. replay dispatch IDs)
 * - Aggregated replay statistics (success/failure rates, common failure reasons)
 * - Configurable retention policy with automatic cleanup
 * - Diagnostic summary for admin dashboards and compliance reporting
 *
 * Each replay operation is assigned a unique ID and tracked end-to-end,
 * enabling full traceability from trigger to dispatch result.
 *
 * @since 206.0.0
 * @see \ZeroBoiler\Analytics\Services\EventDispatchLatencyTracker
 */
final class EventReplayAuditLedger
{
    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'zb_replay_ledger_';

    /** @var string Index cache key for operation IDs */
    private const INDEX_KEY = 'zb_replay_ledger_index';

    /** @var int Default maximum operations to retain */
    private const DEFAULT_MAX_OPERATIONS = 500;

    /** @var int Default TTL for ledger data (seconds) */
    private const DEFAULT_TTL = 86400;

    private CacheRepository $cache;

    private ConfigRepository $config;

    /** @var int Maximum number of operations to retain */
    private int $maxOperations;

    /** @var int Cache TTL in seconds */
    private int $ttl;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;
        $this->config = $config;

        $replayConfig = $config->get('zeroboiler.analytics.replay_ledger', []);
        /** @var array{max_operations?: int, ttl?: int} $replayConfig */
        $this->maxOperations = (int) ($replayConfig['max_operations'] ?? self::DEFAULT_MAX_OPERATIONS);
        $this->ttl = (int) ($replayConfig['ttl'] ?? self::DEFAULT_TTL);
    }

    /**
     * Record a replay operation.
     *
     * Creates a new ledger entry with a unique operation ID, records the
     * trigger source, scope (single/batch/DLQ), and event counts.
     *
     * @param  string  $triggeredBy  Who or what triggered the replay (user ID, command name, or 'system')
     * @param  string  $source  Replay source ('dlq', 'archive', 'manual', 'scheduled')
     * @param  string  $scope  Replay scope ('single', 'batch', 'category', 'date_range')
     * @param  int  $eventCount  Number of events in the replay
     * @param  array<string, mixed>  $metadata  Additional context (filter criteria, time range, etc.)
     * @return string  Unique operation ID
     */
    public function recordOperation(
        string $triggeredBy,
        string $source,
        string $scope,
        int $eventCount,
        array $metadata = [],
    ): string {
        $operationId = $this->generateOperationId();

        $entry = [
            'operation_id' => $operationId,
            'triggered_by' => $triggeredBy,
            'source' => $source,
            'scope' => $scope,
            'event_count' => $eventCount,
            'metadata' => $metadata,
            'started_at' => microtime(true),
            'completed_at' => null,
            'duration_ms' => null,
            'status' => 'in_progress',
            'events' => [],
            'stats' => [
                'dispatched' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'skipped' => 0,
            ],
        ];

        $this->storeOperation($operationId, $entry);
        $this->addToIndex($operationId);

        return $operationId;
    }

    /**
     * Record a single event replay result within an operation.
     *
     * @param  string  $operationId  Operation ID
     * @param  AnalyticsEvent  $event  The replayed event
     * @param  string  $result  Result status ('dispatched', 'failed', 'skipped', 'filtered')
     * @param  string|null  $provider  Target provider (if applicable)
     * @param  string|null  $error  Error message if failed
     */
    public function recordEventResult(
        string $operationId,
        AnalyticsEvent $event,
        string $result,
        ?string $provider = null,
        ?string $error = null,
    ): void {
        $operation = $this->getOperation($operationId);

        if ($operation === null) {
            return;
        }

        $operation['events'][] = [
            'event_name' => $event->name,
            'event_id' => $event->id,
            'client_id' => $event->clientId,
            'result' => $result,
            'provider' => $provider,
            'error' => $error,
            'recorded_at' => microtime(true),
        ];

        // Update counters
        match ($result) {
            'dispatched' => $operation['stats']['dispatched']++,
            'succeeded' => $operation['stats']['succeeded']++,
            'failed' => $operation['stats']['failed']++,
            'skipped', 'filtered' => $operation['stats']['skipped']++,
            default => null,
        };

        $this->storeOperation($operationId, $operation);
    }

    /**
     * Complete a replay operation.
     *
     * Marks the operation as completed with final stats and duration.
     *
     * @param  string  $operationId  Operation ID
     * @param  string  $finalStatus  Final status ('completed', 'partial', 'failed')
     */
    public function completeOperation(string $operationId, string $finalStatus = 'completed'): void
    {
        $operation = $this->getOperation($operationId);

        if ($operation === null) {
            return;
        }

        $operation['completed_at'] = microtime(true);
        $operation['duration_ms'] = round(($operation['completed_at'] - $operation['started_at']) * 1000, 2);
        $operation['status'] = $finalStatus;

        $this->storeOperation($operationId, $operation);
    }

    /**
     * Get a specific replay operation by ID.
     *
     * @param  string  $operationId  Operation ID
     * @return array<string, mixed>|null Operation entry or null
     */
    public function getOperation(string $operationId): ?array
    {
        $key = self::CACHE_PREFIX . $operationId;

        /** @var array<string, mixed>|null $entry */
        $entry = $this->cache->get($key);

        return $entry;
    }

    /**
     * Get a paginated list of replay operations (most recent first).
     *
     * @param  int  $offset  Number of operations to skip
     * @param  int  $limit  Number of operations to return
     * @return array{operations: list<array<string, mixed>>, total: int, has_more: bool}
     */
    public function listOperations(int $offset = 0, int $limit = 25): array
    {
        /** @var list<string>|null $index */
        $index = $this->cache->get(self::INDEX_KEY, []);

        $total = count($index);
        $sliced = array_slice($index, $offset, $limit);

        $operations = [];
        foreach ($sliced as $operationId) {
            $entry = $this->getOperation($operationId);

            if ($entry !== null) {
                // Strip full event details for listing (performance)
                $operations[] = [
                    'operation_id' => $entry['operation_id'],
                    'triggered_by' => $entry['triggered_by'],
                    'source' => $entry['source'],
                    'scope' => $entry['scope'],
                    'event_count' => $entry['event_count'],
                    'status' => $entry['status'],
                    'duration_ms' => $entry['duration_ms'],
                    'started_at' => $entry['started_at'],
                    'completed_at' => $entry['completed_at'],
                    'stats' => $entry['stats'],
                ];
            }
        }

        return [
            'operations' => $operations,
            'total' => $total,
            'has_more' => ($offset + $limit) < $total,
        ];
    }

    /**
     * Get aggregated replay statistics.
     *
     * @return array{total_operations: int, by_status: array<string, int>, by_source: array<string, int>, total_events_replayed: int, total_succeeded: int, total_failed: int, total_skipped: int, success_rate: float, avg_duration_ms: float|null}
     */
    public function aggregatedStats(): array
    {
        /** @var list<string>|null $index */
        $index = $this->cache->get(self::INDEX_KEY, []);

        $byStatus = [];
        $bySource = [];
        $totalEvents = 0;
        $totalSucceeded = 0;
        $totalFailed = 0;
        $totalSkipped = 0;
        $durations = [];

        foreach ($index as $operationId) {
            $entry = $this->getOperation($operationId);

            if ($entry === null) {
                continue;
            }

            $status = $entry['status'];
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

            $source = $entry['source'];
            $bySource[$source] = ($bySource[$source] ?? 0) + 1;

            $totalEvents += $entry['event_count'];
            $totalSucceeded += $entry['stats']['succeeded'];
            $totalFailed += $entry['stats']['failed'];
            $totalSkipped += $entry['stats']['skipped'];

            if ($entry['duration_ms'] !== null) {
                $durations[] = $entry['duration_ms'];
            }
        }

        return [
            'total_operations' => count($index),
            'by_status' => $byStatus,
            'by_source' => $bySource,
            'total_events_replayed' => $totalEvents,
            'total_succeeded' => $totalSucceeded,
            'total_failed' => $totalFailed,
            'total_skipped' => $totalSkipped,
            'success_rate' => $totalEvents > 0
                ? round(($totalSucceeded + $totalFailed > 0 ? $totalSucceeded / ($totalSucceeded + $totalFailed) : 0), 4)
                : 0.0,
            'avg_duration_ms' => ! empty($durations) ? round(array_sum($durations) / count($durations), 2) : null,
        ];
    }

    /**
     * Get failure reason breakdown for recent replay operations.
     *
     * @param  int  $limit  Maximum operations to scan
     * @return array<string, int> Error message → count mapping
     */
    public function failureReasons(int $limit = 50): array
    {
        /** @var list<string>|null $index */
        $index = $this->cache->get(self::INDEX_KEY, []);

        $reasons = [];

        foreach (array_slice($index, 0, $limit) as $operationId) {
            $entry = $this->getOperation($operationId);

            if ($entry === null) {
                continue;
            }

            foreach ($entry['events'] ?? [] as $eventRecord) {
                if (($eventRecord['result'] ?? '') === 'failed' && ($eventRecord['error'] ?? '') !== '') {
                    $reason = $eventRecord['error'];
                    $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                }
            }
        }

        // Sort by frequency descending
        arsort($reasons);

        return $reasons;
    }

    /**
     * Get a diagnostic summary for admin dashboards.
     *
     * @return array{enabled: bool, max_operations: int, ttl: int, total_operations: int, recent_stats: array{total_operations: int, by_status: array<string, int>, by_source: array<string, int>, total_events_replayed: int, total_succeeded: int, total_failed: int, success_rate: float, avg_duration_ms: float|null}, top_failure_reasons: array<string, int>, last_operation: array<string, mixed>|null}
     */
    public function diagnosticSummary(): array
    {
        $stats = $this->aggregatedStats();

        /** @var list<string>|null $index */
        $index = $this->cache->get(self::INDEX_KEY, []);
        $lastOperationId = ! empty($index) ? $index[0] : null;
        $lastOperation = $lastOperationId !== null ? $this->getOperation($lastOperationId) : null;

        return [
            'enabled' => true,
            'max_operations' => $this->maxOperations,
            'ttl' => $this->ttl,
            'total_operations' => $stats['total_operations'],
            'recent_stats' => $stats,
            'top_failure_reasons' => array_slice($this->failureReasons(10), 0, 5),
            'last_operation' => $lastOperation,
        ];
    }

    /**
     * Prune old operations to stay within the max limit.
     *
     * Removes oldest operations when the total exceeds max_operations.
     *
     * @return int Number of operations pruned
     */
    public function prune(): int
    {
        /** @var list<string>|null $index */
        $index = $this->cache->get(self::INDEX_KEY, []);

        $total = count($index);

        if ($total <= $this->maxOperations) {
            return 0;
        }

        $pruneCount = $total - $this->maxOperations;
        $toPrune = array_slice($index, $this->maxOperations);

        foreach ($toPrune as $operationId) {
            $this->cache->forget(self::CACHE_PREFIX . $operationId);
        }

        $newIndex = array_slice($index, 0, $this->maxOperations);
        $this->cache->put(self::INDEX_KEY, $newIndex, $this->ttl);

        return $pruneCount;
    }

    /**
     * Clear all replay ledger data.
     */
    public function clear(): void
    {
        /** @var list<string>|null $index */
        $index = $this->cache->get(self::INDEX_KEY, []);

        foreach ($index as $operationId) {
            $this->cache->forget(self::CACHE_PREFIX . $operationId);
        }

        $this->cache->forget(self::INDEX_KEY);
    }

    /**
     * Store an operation entry in cache.
     *
     * @param  string  $operationId  Operation ID
     * @param  array<string, mixed>  $entry  Operation data
     */
    private function storeOperation(string $operationId, array $entry): void
    {
        $key = self::CACHE_PREFIX . $operationId;
        $this->cache->put($key, $entry, $this->ttl);
    }

    /**
     * Add an operation ID to the index (most recent first).
     *
     * Enforces the max operations limit by pruning oldest entries.
     *
     * @param  string  $operationId  Operation ID to add
     */
    private function addToIndex(string $operationId): void
    {
        $key = self::INDEX_KEY;

        /** @var list<string> $index */
        $index = $this->cache->get($key, []);

        // Prepend (newest first)
        array_unshift($index, $operationId);

        // Enforce max limit
        if (count($index) > $this->maxOperations) {
            $pruned = array_splice($index, $this->maxOperations);
            foreach ($pruned as $prunedId) {
                $this->cache->forget(self::CACHE_PREFIX . $prunedId);
            }
        }

        $this->cache->put($key, $index, $this->ttl);
    }

    /**
     * Generate a unique operation ID.
     *
     * Format: replay_<timestamp>_<random_hex>
     */
    private function generateOperationId(): string
    {
        return 'replay_' . time() . '_' . bin2hex(random_bytes(8));
    }
}
