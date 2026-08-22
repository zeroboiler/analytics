<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
/**
 * Event Replay Audit Trail Service.
 *
 * Maintains a tamper-evident audit trail of all event replay operations.
 * Each replay is recorded with full context: who triggered it, when,
 * which events were replayed, to which providers, and the outcome.
 *
 * Supports compliance requirements for GDPR audit logging and
 * provides a complete chain of custody for event data.
 *
 * @since 203.0.0
 */
final class EventReplayAuditTrailService
{
    /** @var CacheRepository */
    private CacheRepository $cache;

    /** @var int Maximum audit entries to retain */
    private int $maxEntries;

    /** @var int Cache TTL for audit entries (seconds) */
    private int $cacheTtl;

    /** @var string Cache key prefix */
    private string $cachePrefix;

    /**
     * @param  CacheRepository  $cache  Cache repository for audit trail storage
     */
    public function __construct(CacheRepository $cache){
        $this->cache = $cache;
        $this->maxEntries = 10000;
        $this->cacheTtl = 2592000; // 30 days
        $this->cachePrefix = 'zb_analytics_replay_audit_';
    }

    /**
     * Record a replay operation in the audit trail.
     *
     * @param  array<string, mixed>  $context  Replay context
     * @return string  Audit entry ID
     */
    public function recordReplay(array $context): string
    {
        $entryId = $this->generateEntryId();

        $entry = [
            'id' => $entryId,
            'timestamp' => date('c'),
            'type' => $context['type'] ?? 'manual',
            'triggered_by' => $context['triggered_by'] ?? 'system',
            'triggered_by_id' => $context['triggered_by_id'] ?? null,
            'source' => $context['source'] ?? 'dlq',
            'event_count' => $context['event_count'] ?? 0,
            'events' => $context['events'] ?? [],
            'providers' => $context['providers'] ?? [],
            'status' => $context['status'] ?? 'pending',
            'result' => $context['result'] ?? null,
            'duration_ms' => $context['duration_ms'] ?? null,
            'filters' => $context['filters'] ?? [],
            'metadata' => $context['metadata'] ?? [],
            'checksum' => $this->computeChecksum($context),
        ];

        $this->appendEntry($entryId, $entry);

        return $entryId;
    }

    /**
     * Record the result of a replay operation.
     *
     * @param  string  $entryId  Audit entry ID from recordReplay()
     * @param  string  $status  Result status (success|partial|failed)
     * @param  array<string, mixed>  $result  Result details
     * @param  int|null  $durationMs  Duration in milliseconds
     */
    public function recordResult(string $entryId, string $status, array $result, ?int $durationMs = null): void
    {
        $entry = $this->getEntry($entryId);

        if ($entry === null) {
            return;
        }

        $entry['status'] = $status;
        $entry['result'] = $result;
        $entry['duration_ms'] = $durationMs;
        $entry['completed_at'] = date('c');

        $this->appendEntry($entryId, $entry);
    }

    /**
     * Get a specific audit entry by ID.
     *
     * @param  string  $entryId  Audit entry ID
     * @return array<string, mixed>|null  Audit entry or null
     */
    public function getEntry(string $entryId): ?array
    {
        $data = $this->cache->get($this->cachePrefix . $entryId);

        return is_array($data) ? $data : null;
    }

    /**
     * List audit entries with optional filtering.
     *
     * @param  array{type?: string, status?: string, triggered_by?: string, since?: string, until?: string, limit?: int, offset?: int}  $filters
     * @return array{entries: list<array<string, mixed>>, total: int, filters: array}
     */
    public function listEntries(array $filters = []): array
    {
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $allEntries = $this->getAllEntries();
        $filtered = $this->applyFilters($allEntries, $filters);

        $total = count($filtered);
        $entries = array_slice($filtered, $offset, $limit);

        return [
            'entries' => $entries,
            'total' => $total,
            'filters' => $filters,
        ];
    }

    /**
     * Get audit trail statistics.
     *
     * @return array{total_entries: int, by_type: array<string, int>, by_status: array<string, int>, by_source: array<string, int>, total_events_replayed: int, avg_duration_ms: float|null, last_replay: string|null, success_rate: float}
     */
    public function statistics(): array
    {
        $entries = $this->getAllEntries();

        $byType = [];
        $byStatus = [];
        $bySource = [];
        $totalEvents = 0;
        $totalDuration = 0.0;
        $durationCount = 0;
        $successCount = 0;

        foreach ($entries as $entry) {
            // By type
            $type = $entry['type'] ?? 'unknown';
            $byType[$type] = ($byType[$type] ?? 0) + 1;

            // By status
            $status = $entry['status'] ?? 'unknown';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

            if ($status === 'success') {
                $successCount++;
            }

            // By source
            $source = $entry['source'] ?? 'unknown';
            $bySource[$source] = ($bySource[$source] ?? 0) + 1;

            // Event counts
            $totalEvents += (int) ($entry['event_count'] ?? 0);

            // Duration
            if (isset($entry['duration_ms']) && is_numeric($entry['duration_ms'])) {
                $totalDuration += (float) $entry['duration_ms'];
                $durationCount++;
            }
        }

        $avgDuration = $durationCount > 0 ? round($totalDuration / $durationCount, 2) : null;
        $successRate = count($entries) > 0 ? round(($successCount / count($entries)) * 100, 2) : 0.0;
        $lastReplay = $entries !== [] ? ($entries[0]['timestamp'] ?? null) : null;

        return [
            'total_entries' => count($entries),
            'by_type' => $byType,
            'by_status' => $byStatus,
            'by_source' => $bySource,
            'total_events_replayed' => $totalEvents,
            'avg_duration_ms' => $avgDuration,
            'last_replay' => $lastReplay,
            'success_rate' => $successRate,
        ];
    }

    /**
     * Verify the integrity of an audit entry.
     *
     * Compares the stored checksum against a recomputed checksum to detect tampering.
     *
     * @param  string  $entryId  Audit entry ID
     * @return array{valid: bool, entry_id: string, stored_checksum: string|null, computed_checksum: string}
     */
    public function verifyIntegrity(string $entryId): array
    {
        $entry = $this->getEntry($entryId);

        if ($entry === null) {
            return [
                'valid' => false,
                'entry_id' => $entryId,
                'stored_checksum' => null,
                'computed_checksum' => 'entry_not_found',
            ];
        }

        $storedChecksum = $entry['checksum'] ?? null;
        $context = $entry;
        unset($context['checksum'], $context['completed_at'], $context['status'], $context['result'], $context['duration_ms']);
        $computedChecksum = $this->computeChecksum($context);

        return [
            'valid' => $storedChecksum === $computedChecksum,
            'entry_id' => $entryId,
            'stored_checksum' => $storedChecksum,
            'computed_checksum' => $computedChecksum,
        ];
    }

    /**
     * Prune old audit entries beyond retention period.
     *
     * @return int  Number of entries pruned
     */
    public function prune(): int
    {
        $entries = $this->getAllEntries();
        $count = count($entries);
        $pruned = 0;

        if ($count > $this->maxEntries) {
            $toRemove = $count - $this->maxEntries;
            for ($i = $count - 1; $i >= $count - $toRemove; $i--) {
                $entryId = $entries[$i]['id'] ?? null;
                if ($entryId !== null) {
                    $this->cache->forget($this->cachePrefix . $entryId);
                    $pruned++;
                }
            }
        }

        return $pruned;
    }

    /**
     * Get a diagnostic summary of the audit trail state.
     *
     * @return array{total_entries: int, max_entries: int, cache_ttl: int, cache_prefix: string}
     */
    public function diagnosticSummary(): array
    {
        return [
            'total_entries' => count($this->getAllEntries()),
            'max_entries' => $this->maxEntries,
            'cache_ttl' => $this->cacheTtl,
            'cache_prefix' => $this->cachePrefix,
        ];
    }

    /**
     * Generate a unique audit entry ID.
     */
    private function generateEntryId(): string
    {
        return 'replay_' . bin2hex(random_bytes(8));
    }

    /**
     * Append an entry to the audit index and store it.
     */
    private function appendEntry(string $entryId, array $entry): void
    {
        $this->cache->put($this->cachePrefix . $entryId, $entry, $this->cacheTtl);

        $index = $this->getOrCreateIndex();
        array_unshift($index, $entryId);

        // Trim to max entries
        if (count($index) > $this->maxEntries) {
            $index = array_slice($index, 0, $this->maxEntries);
        }

        $this->cache->put($this->cachePrefix . 'index', $index, $this->cacheTtl);
    }

    /**
     * Get or create the audit index.
     *
     * @return list<string>  List of entry IDs in reverse chronological order
     */
    private function getOrCreateIndex(): array
    {
        $index = $this->cache->get($this->cachePrefix . 'index');

        return is_array($index) ? $index : [];
    }

    /**
     * Get all audit entries from the index.
     *
     * @return list<array<string, mixed>>
     */
    private function getAllEntries(): array
    {
        $index = $this->getOrCreateIndex();
        $entries = [];

        foreach ($index as $entryId) {
            $entry = $this->cache->get($this->cachePrefix . $entryId);
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Apply filters to the audit entry list.
     *
     * @param  list<array<string, mixed>>  $entries  All audit entries
     * @param  array  $filters  Filter criteria
     * @return list<array<string, mixed>>
     */
    private function applyFilters(array $entries, array $filters): array
    {
        $filtered = $entries;

        if (isset($filters['type']) && is_string($filters['type']) && $filters['type'] !== '') {
            $filtered = array_filter($filtered, fn (array $e): bool => ($e['type'] ?? '') === $filters['type']);
        }

        if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '') {
            $filtered = array_filter($filtered, fn (array $e): bool => ($e['status'] ?? '') === $filters['status']);
        }

        if (isset($filters['triggered_by']) && is_string($filters['triggered_by']) && $filters['triggered_by'] !== '') {
            $filtered = array_filter($filtered, fn (array $e): bool => ($e['triggered_by'] ?? '') === $filters['triggered_by']);
        }

        if (isset($filters['source']) && is_string($filters['source']) && $filters['source'] !== '') {
            $filtered = array_filter($filtered, fn (array $e): bool => ($e['source'] ?? '') === $filters['source']);
        }

        if (isset($filters['since']) && is_string($filters['since'])) {
            $since = strtotime($filters['since']);
            if ($since !== false) {
                $filtered = array_filter($filtered, fn (array $e): bool => strtotime($e['timestamp'] ?? '') >= $since);
            }
        }

        if (isset($filters['until']) && is_string($filters['until'])) {
            $until = strtotime($filters['until']);
            if ($until !== false) {
                $filtered = array_filter($filtered, fn (array $e): bool => strtotime($e['timestamp'] ?? '') <= $until);
            }
        }

        return array_values($filtered);
    }

    /**
     * Compute a checksum for audit entry integrity verification.
     *
     * @param  array<string, mixed>  $data  Data to checksum
     */
    private function computeChecksum(array $data): string
    {
        $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return hash('xxh128', $payload);
    }
}
