<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * Audit trail service for event replay operations.
 *
 * Records every event replay action with full context: who triggered it,
 * which events were replayed, provider-level success/failure, timestamps,
 * and execution duration. Provides search, filtering, and statistics
 * for compliance, debugging, and operational dashboards.
 *
 * Cache-backed storage with configurable retention. Each replay operation
 * gets a unique audit ID for cross-referencing with logs and monitoring.
 *
 * Configuration is read from `zeroboiler.analytics.replay_audit`.
 *
 * @since 39.0.0
 */
final class EventReplayAuditService
{
    /** @var string Cache key prefix for audit entries */
    private string $cachePrefix;

    /** @var int Retention TTL in seconds for audit entries */
    private int $retentionTtl;

    /** @var int Maximum number of audit entries to retain */
    private int $maxEntries;

    /** @var bool Whether replay audit logging is enabled */
    private bool $enabled;

    /** @var bool Whether to automatically record replay calls from EventArchiveService */
    private bool $autoRecord;

    private CacheRepository $cache;

    /**
     * Create a new EventReplayAuditService instance.
     *
     * @param  CacheRepository  $cache  Cache repository instance
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $auditConfig = $config->get('zeroboiler.analytics.replay_audit', []);
        /** @var array{enabled?: bool, cache_prefix?: string, retention_ttl?: int, max_entries?: int, auto_record?: bool} $auditConfig */

        $this->cache = $cache;
        $this->enabled = (bool) ($auditConfig['enabled'] ?? true);
        $this->cachePrefix = (string) ($auditConfig['cache_prefix'] ?? 'zb_replay_audit_');
        $this->retentionTtl = (int) ($auditConfig['retention_ttl'] ?? 2592000); // 30 days
        $this->maxEntries = (int) ($auditConfig['max_entries'] ?? 5000);
        $this->autoRecord = (bool) ($auditConfig['auto_record'] ?? true);
    }

    /**
     * Record a single-event replay operation.
     *
     * @param  string  $eventName  Name of the replayed event
     * @param  int|null  $archiveId  Archive ID of the replayed event (if from archive)
     * @param  string|null  $clientId  Client ID associated with the event
     * @param  string|null  $userId  User ID who triggered the replay (null for system)
     * @param  array<string, bool>  $providerResults  Provider name → success boolean
     * @param  string  $source  Origin of the replay: 'archive', 'dlq', 'manual', 'api', 'command'
     * @param  float|null  $durationMs  Execution time in milliseconds
     * @return string Audit ID (UUID-like hex string)
     */
    public function recordSingle(
        string $eventName,
        ?int $archiveId = null,
        ?string $clientId = null,
        ?string $userId = null,
        array $providerResults = [],
        string $source = 'manual',
        ?float $durationMs = null,
    ): string {
        if (! $this->enabled) {
            return '';
        }

        $auditId = $this->generateAuditId();
        $successCount = count(array_filter($providerResults));
        $failCount = count($providerResults) - $successCount;

        $entry = [
            'audit_id' => $auditId,
            'type' => 'single',
            'event_name' => $eventName,
            'archive_id' => $archiveId,
            'client_id' => $clientId,
            'triggered_by' => $userId,
            'source' => $source,
            'provider_results' => $providerResults,
            'providers_succeeded' => $successCount,
            'providers_failed' => $failCount,
            'success' => $failCount === 0,
            'duration_ms' => $durationMs !== null ? round($durationMs, 2) : null,
            'recorded_at' => now()->format('c'),
        ];

        $this->store($entry);

        Log::info('ZeroBoiler Analytics: Event replay audited', [
            'audit_id' => $auditId,
            'type' => 'single',
            'event' => $eventName,
            'source' => $source,
            'success' => $entry['success'],
        ]);

        return $auditId;
    }

    /**
     * Record a bulk replay operation.
     *
     * @param  int  $totalEvents  Total events in the batch
     * @param  int  $replayed  Successfully replayed count
     * @param  int  $failed  Failed replay count
     * @param  array{name?: string, client_id?: string, dispatched?: bool|null}  $filters  Filters used for the batch
     * @param  string|null  $userId  User ID who triggered the replay
     * @param  string  $source  Origin: 'archive', 'dlq', 'manual', 'api', 'command'
     * @param  float|null  $durationMs  Execution time in milliseconds
     * @return string Audit ID
     */
    public function recordBulk(
        int $totalEvents,
        int $replayed,
        int $failed,
        array $filters = [],
        ?string $userId = null,
        string $source = 'manual',
        ?float $durationMs = null,
    ): string {
        if (! $this->enabled) {
            return '';
        }

        $auditId = $this->generateAuditId();

        $entry = [
            'audit_id' => $auditId,
            'type' => 'bulk',
            'total_events' => $totalEvents,
            'replayed' => $replayed,
            'failed' => $failed,
            'filters' => $filters,
            'triggered_by' => $userId,
            'source' => $source,
            'success' => $failed === 0,
            'success_rate' => $totalEvents > 0
                ? round(($replayed / $totalEvents) * 100, 2)
                : null,
            'duration_ms' => $durationMs !== null ? round($durationMs, 2) : null,
            'recorded_at' => now()->format('c'),
        ];

        $this->store($entry);

        Log::info('ZeroBoiler Analytics: Bulk replay audited', [
            'audit_id' => $auditId,
            'type' => 'bulk',
            'total' => $totalEvents,
            'replayed' => $replayed,
            'failed' => $failed,
            'source' => $source,
        ]);

        return $auditId;
    }

    /**
     * Search audit entries with optional filters.
     *
     * @param  array{source?: string, type?: string, event_name?: string, triggered_by?: string, success?: bool|null, since?: string|null, until?: string|null}  $filters
     * @param  int  $limit  Maximum results (default: 50)
     * @param  int  $offset  Pagination offset (default: 0)
     * @return array{entries: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function search(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $lastId = $this->lastId();

        if ($lastId === 0) {
            return ['entries' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
        }

        $results = [];
        $scanned = 0;
        $maxScan = $this->maxEntries;

        for ($id = $lastId; $id >= 1 && $scanned < $maxScan; $id--) {
            $entry = $this->getEntry($id);

            if ($entry === null) {
                $scanned++;
                continue;
            }

            $scanned++;

            if ($this->matchesFilters($entry, $filters)) {
                $results[] = $entry;
            }

            if (count($results) >= $limit + $offset) {
                break;
            }
        }

        $total = count($results);
        $entries = array_slice($results, $offset, $limit);

        // Reverse to chronological order
        $entries = array_values(array_reverse($entries));

        return [
            'entries' => $entries,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Get a single audit entry by audit ID.
     *
     * @return array<string, mixed>|null
     */
    public function getByAuditId(string $auditId): ?array
    {
        $lastId = $this->lastId();

        for ($id = $lastId; $id >= 1; $id--) {
            $entry = $this->getEntry($id);

            if ($entry !== null && ($entry['audit_id'] ?? null) === $auditId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Get replay statistics for operational dashboards.
     *
     * @param  string|null  $period  'day', 'week', 'month', or null for all time
     * @return array{total_replays: int, single_replays: int, bulk_replays: int, successful: int, failed: int, success_rate: float|null, avg_duration_ms: float|null, by_source: array<string, int>, recent_failures: int}
     */
    public function statistics(?string $period = null): array
    {
        $allEntries = $this->search([], limit: $this->maxEntries, offset: 0);

        $entries = $allEntries['entries'];

        if ($period !== null) {
            $since = $this->periodToTimestamp($period);
            $entries = array_filter($entries, fn (array $e): bool => ($e['recorded_at'] ?? '') >= $since);
        }

        $total = count($entries);
        $singleCount = 0;
        $bulkCount = 0;
        $successCount = 0;
        $failCount = 0;
        $totalDuration = 0.0;
        $durationCount = 0;
        $bySource = [];
        $recentFailures = 0;

        foreach ($entries as $entry) {
            $isBulk = ($entry['type'] ?? 'single') === 'bulk';

            if ($isBulk) {
                $bulkCount++;
                $successCount += (int) ($entry['replayed'] ?? 0);
                $failCount += (int) ($entry['failed'] ?? 0);
            } else {
                $singleCount++;
                if (($entry['success'] ?? true) === false) {
                    $failCount++;
                    $recentFailures++;
                } else {
                    $successCount++;
                }
            }

            $source = $entry['source'] ?? 'unknown';
            $bySource[$source] = ($bySource[$source] ?? 0) + 1;

            if (($entry['duration_ms'] ?? null) !== null) {
                $totalDuration += (float) $entry['duration_ms'];
                $durationCount++;
            }
        }

        return [
            'total_replays' => $total,
            'single_replays' => $singleCount,
            'bulk_replays' => $bulkCount,
            'successful' => $successCount,
            'failed' => $failCount,
            'success_rate' => ($successCount + $failCount) > 0
                ? round(($successCount / ($successCount + $failCount)) * 100, 2)
                : null,
            'avg_duration_ms' => $durationCount > 0
                ? round($totalDuration / $durationCount, 2)
                : null,
            'by_source' => $bySource,
            'recent_failures' => $recentFailures,
        ];
    }

    /**
     * Get the total number of audit entries.
     */
    public function totalCount(): int
    {
        return $this->lastId();
    }

    /**
     * Clear all audit entries.
     *
     * @return int Number of entries cleared
     */
    public function clear(): int
    {
        $lastId = $this->lastId();

        if ($lastId === 0) {
            return 0;
        }

        $cleared = 0;

        for ($id = 1; $id <= $lastId; $id++) {
            if ($this->cache->forget($this->entryKey($id))) {
                $cleared++;
            }
        }

        $this->cache->forget($this->counterKey());
        $this->cache->forget($this->indexKey());

        return $cleared;
    }

    /**
     * Check if replay audit logging is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if auto-recording is enabled (for archive/DLQ replay hooks).
     */
    public function isAutoRecord(): bool
    {
        return $this->autoRecord;
    }

    /**
     * Get service summary for health checks.
     *
     * @return array{enabled: bool, auto_record: bool, total_entries: int, max_entries: int, retention_ttl: int, cache_prefix: string, utilization: float}
     */
    public function summary(): array
    {
        $total = $this->totalCount();

        return [
            'enabled' => $this->enabled,
            'auto_record' => $this->autoRecord,
            'total_entries' => $total,
            'max_entries' => $this->maxEntries,
            'retention_ttl' => $this->retentionTtl,
            'cache_prefix' => $this->cachePrefix,
            'utilization' => $this->maxEntries > 0
                ? round(($total / $this->maxEntries) * 100, 2)
                : 0.0,
        ];
    }

    /**
     * Store an audit entry in the cache.
     */
    private function store(array $entry): void
    {
        $id = $this->lastId() + 1;

        $this->cache->put($this->entryKey($id), $entry, $this->retentionTtl);
        $this->cache->put($this->counterKey(), $id, $this->retentionTtl);

        // Maintain audit ID → entry ID index for fast lookups
        $index = $this->getIndex();
        $index[$entry['audit_id']] = $id;
        $this->cache->put($this->indexKey(), $index, $this->retentionTtl);

        // Evict oldest entries if at capacity
        if ($id > $this->maxEntries) {
            $evictStart = 1;
            $evictEnd = $id - $this->maxEntries;

            for ($i = $evictStart; $i <= $evictEnd; $i++) {
                $this->cache->forget($this->entryKey($i));
            }
        }
    }

    /**
     * Get a single entry by sequential ID.
     *
     * @return array<string, mixed>|null
     */
    private function getEntry(int $id): ?array
    {
        /** @var array<string, mixed>|null $entry */
        $entry = $this->cache->get($this->entryKey($id));

        return is_array($entry) ? $entry : null;
    }

    /**
     * Get the audit ID → entry ID index.
     *
     * @return array<string, int>
     */
    private function getIndex(): array
    {
        /** @var array<string, int>|null $index */
        $index = $this->cache->get($this->indexKey());

        return is_array($index) ? $index : [];
    }

    /**
     * Check if an entry matches the given search filters.
     *
     * @param  array<string, mixed>  $entry
     * @param  array{source?: string, type?: string, event_name?: string, triggered_by?: string, success?: bool|null, since?: string|null, until?: string|null}  $filters
     */
    private function matchesFilters(array $entry, array $filters): bool
    {
        if (isset($filters['source']) && $filters['source'] !== '') {
            if (($entry['source'] ?? '') !== $filters['source']) {
                return false;
            }
        }

        if (isset($filters['type']) && $filters['type'] !== '') {
            if (($entry['type'] ?? '') !== $filters['type']) {
                return false;
            }
        }

        if (isset($filters['event_name']) && $filters['event_name'] !== '') {
            $entryName = $entry['event_name'] ?? '';
            if (! str_contains(strtolower($entryName), strtolower($filters['event_name']))) {
                return false;
            }
        }

        if (isset($filters['triggered_by']) && $filters['triggered_by'] !== '') {
            if (($entry['triggered_by'] ?? '') !== $filters['triggered_by']) {
                return false;
            }
        }

        if (isset($filters['success']) && $filters['success'] !== null) {
            if (($entry['success'] ?? true) !== $filters['success']) {
                return false;
            }
        }

        if (isset($filters['since']) && $filters['since'] !== '') {
            if (($entry['recorded_at'] ?? '') < $filters['since']) {
                return false;
            }
        }

        if (isset($filters['until']) && $filters['until'] !== '') {
            if (($entry['recorded_at'] ?? '') > $filters['until']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the last sequential entry ID.
     */
    private function lastId(): int
    {
        return (int) ($this->cache->get($this->counterKey()) ?? 0);
    }

    /**
     * Generate a unique audit ID (12-character hex string).
     */
    private function generateAuditId(): string
    {
        return bin2hex(random_bytes(6));
    }

    /**
     * Convert a period string to an ISO 8601 timestamp.
     */
    private function periodToTimestamp(string $period): string
    {
        return match ($period) {
            'day' => now()->subDay()->format('c'),
            'week' => now()->subWeek()->format('c'),
            'month' => now()->subMonth()->format('c'),
            default => '1970-01-01T00:00:00+00:00',
        };
    }

    private function entryKey(int $id): string
    {
        return "{$this->cachePrefix}entry_{$id}";
    }

    private function counterKey(): string
    {
        return "{$this->cachePrefix}last_id";
    }

    private function indexKey(): string
    {
        return "{$this->cachePrefix}audit_index";
    }
}
