<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Comprehensive audit trail for every dispatched analytics event.
 *
 * Records detailed dispatch context for each event: unique audit ID,
 * event name, client/user identity, timestamp, per-provider dispatch
 * results (success/failure/latency), pipeline stage timings, consent
 * state at dispatch time, and source channel.
 *
 * Cache-backed with configurable retention. Supports search by event name,
 * client ID, user ID, and time-range queries. Provides summary statistics
 * and GDPR-compliant data erasure.
 *
 * Inspired by Segment Audit Log, Datadog Audit Trail, and Snowflake Access History.
 *
 * @since 72.0.0
 */
final class EventAuditTrailService
{
    private const CACHE_PREFIX = 'zb_audit_trail_';
    private const INDEX_KEY = 'zb_audit_trail_index';
    private const STATS_KEY = 'zb_audit_trail_stats';

    private CacheRepository $cache;
    private bool $enabled;
    private int $ttl;
    private int $maxEntries;

    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;
        $auditConfig = $config->get('zeroboiler.analytics.audit_trail', []);
        /** @var array{enabled?: bool, ttl?: int, max_entries?: int} $auditConfig */
        $this->enabled = (bool) ($auditConfig['enabled'] ?? true);
        $this->ttl = (int) ($auditConfig['ttl'] ?? 2592000); // 30 days
        $this->maxEntries = (int) ($auditConfig['max_entries'] ?? 10000);
    }

    /**
     * Check if the audit trail service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Record a dispatched event in the audit trail.
     *
     * @param  array{name: string, client_id?: string|null, user_id?: string|null, source?: string|null, priority?: string|null}  $eventContext
     * @param  array<string, array{success: bool, latency_ms: float, error?: string|null}>  $providerResults
     * @param  array<string, mixed>  $metadata
     * @return string Audit ID
     */
    public function record(array $eventContext, array $providerResults, array $metadata = []): string
    {
        if (! $this->enabled) {
            return '';
        }

        $auditId = $this->generateAuditId();
        $now = time();

        $entry = [
            'audit_id' => $auditId,
            'event_name' => $eventContext['name'] ?? 'unknown',
            'client_id' => $eventContext['client_id'] ?? null,
            'user_id' => $eventContext['user_id'] ?? null,
            'source' => $eventContext['source'] ?? 'server',
            'priority' => $eventContext['priority'] ?? 'normal',
            'timestamp' => $now,
            'providers' => $providerResults,
            'total_latency_ms' => $this->calculateTotalLatency($providerResults),
            'provider_count' => count($providerResults),
            'success_count' => count(array_filter($providerResults, static fn (array $r): bool => $r['success'])),
            'failure_count' => count(array_filter($providerResults, static fn (array $r): bool => ! $r['success'])),
            'consent_state' => $metadata['consent_state'] ?? null,
            'pipeline_stages' => $metadata['pipeline_stages'] ?? [],
            'metadata' => $metadata,
        ];

        // Store the entry
        $this->cache->put(
            self::CACHE_PREFIX . $auditId,
            $entry,
            $this->ttl,
        );

        // Add to index (ring buffer — bounded by maxEntries)
        $this->addToIndex($auditId, $entry);

        // Update stats
        $this->incrementStats($entry);

        return $auditId;
    }

    /**
     * Look up an audit trail entry by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getByAuditId(string $auditId): ?array
    {
        /** @var array<string, mixed>|null $entry */
        $entry = $this->cache->get(self::CACHE_PREFIX . $auditId);

        return is_array($entry) ? $entry : null;
    }

    /**
     * Search audit trail entries by criteria.
     *
     * @param  array{event_name?: string, client_id?: string, user_id?: string, source?: string, from?: int, to?: int, limit?: int, offset?: int}  $filters
     * @return list<array<string, mixed>>
     */
    public function search(array $filters = []): array
    {
        $index = $this->getIndex();
        $results = [];

        $from = (int) ($filters['from'] ?? 0);
        $to = (int) ($filters['to'] ?? PHP_INT_MAX);
        $limit = (int) ($filters['limit'] ?? 50);
        $offset = (int) ($filters['offset'] ?? 0);

        // Filter in reverse chronological order
        $filtered = [];
        $indexReversed = array_reverse($index, true);

        foreach ($indexReversed as $id => $summary) {
            if (isset($filters['event_name']) && ($summary['event_name'] ?? '') !== $filters['event_name']) {
                continue;
            }
            if (isset($filters['client_id']) && ($summary['client_id'] ?? '') !== $filters['client_id']) {
                continue;
            }
            if (isset($filters['user_id']) && ($summary['user_id'] ?? '') !== $filters['user_id']) {
                continue;
            }
            if (isset($filters['source']) && ($summary['source'] ?? '') !== $filters['source']) {
                continue;
            }
            $ts = (int) ($summary['timestamp'] ?? 0);
            if ($ts < $from || $ts > $to) {
                continue;
            }

            $filtered[] = $id;
        }

        // Apply offset and limit
        $sliced = array_slice($filtered, $offset, $limit);

        foreach ($sliced as $id) {
            $entry = $this->getByAuditId($id);
            if ($entry !== null) {
                $results[] = $entry;
            }
        }

        return $results;
    }

    /**
     * Get audit trail statistics.
     *
     * @param  string  $period  'all'|'day'|'week'|'month'
     */
    public function statistics(string $period = 'all'): array
    {
        $stats = $this->getStats();

        $now = time();
        $cutoff = match ($period) {
            'day' => $now - 86400,
            'week' => $now - 604800,
            'month' => $now - 2592000,
            default => 0,
        };

        $index = $this->getIndex();
        $filteredCount = 0;
        $filteredFailures = 0;
        $filteredLatencyTotal = 0.0;

        foreach ($index as $summary) {
            $ts = (int) ($summary['timestamp'] ?? 0);
            if ($ts < $cutoff) {
                continue;
            }
            $filteredCount++;
            $filteredFailures += (int) ($summary['failure_count'] ?? 0);
            $filteredLatencyTotal += (float) ($summary['total_latency_ms'] ?? 0);
        }

        return [
            'total_entries' => $filteredCount,
            'total_failures' => $filteredFailures,
            'failure_rate' => $filteredCount > 0 ? round($filteredFailures / $filteredCount, 4) : 0.0,
            'avg_latency_ms' => $filteredCount > 0 ? round($filteredLatencyTotal / $filteredCount, 2) : 0.0,
            'period' => $period,
            'global_stats' => $stats,
        ];
    }

    /**
     * Get recent audit trail entries.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 20): array
    {
        return $this->search(['limit' => $limit]);
    }

    /**
     * Get the total number of audit trail entries.
     */
    public function count(): int
    {
        return count($this->getIndex());
    }

    /**
     * Clear the entire audit trail.
     */
    public function clear(): void
    {
        $index = $this->getIndex();

        foreach ($index as $id => $_) {
            $this->cache->forget(self::CACHE_PREFIX . $id);
        }

        $this->cache->forget(self::INDEX_KEY);
        $this->cache->forget(self::STATS_KEY);
    }

    /**
     * GDPR-compliant data erasure for a specific client or user.
     *
     * Removes all audit trail entries matching the given identifier.
     *
     * @param  'client'|'user'  $type
     */
    public function eraseFor(string $type, string $identifier): int
    {
        $index = $this->getIndex();
        $erased = 0;

        foreach ($index as $id => $summary) {
            $field = $type === 'client' ? 'client_id' : 'user_id';
            if (($summary[$field] ?? '') === $identifier) {
                $this->cache->forget(self::CACHE_PREFIX . $id);
                unset($index[$id]);
                $erased++;
            }
        }

        $this->cache->put(self::INDEX_KEY, $index, $this->ttl + 86400);

        return $erased;
    }

    /**
     * Get a summary of the audit trail.
     */
    public function summary(): array
    {
        $index = $this->getIndex();

        $eventCounts = [];
        $sourceCounts = [];
        $failureEvents = 0;

        foreach ($index as $summary) {
            $eventName = $summary['event_name'] ?? 'unknown';
            $source = $summary['source'] ?? 'server';

            $eventCounts[$eventName] = ($eventCounts[$eventName] ?? 0) + 1;
            $sourceCounts[$source] = ($sourceCounts[$source] ?? 0) + 1;

            if (($summary['failure_count'] ?? 0) > 0) {
                $failureEvents++;
            }
        }

        arsort($eventCounts);

        return [
            'total_entries' => count($index),
            'unique_events' => count($eventCounts),
            'top_events' => array_slice($eventCounts, 0, 10, true),
            'sources' => $sourceCounts,
            'entries_with_failures' => $failureEvents,
            'enabled' => $this->enabled,
            'max_entries' => $this->maxEntries,
            'retention_days' => (int) ($this->ttl / 86400),
        ];
    }

    /**
     * Generate a unique audit ID.
     */
    private function generateAuditId(): string
    {
        return 'aud_' . bin2hex(random_bytes(12));
    }

    /**
     * Calculate total latency from provider results.
     *
     * @param  array<string, array{success: bool, latency_ms: float, error?: string|null}>  $providerResults
     */
    private function calculateTotalLatency(array $providerResults): float
    {
        $total = 0.0;

        foreach ($providerResults as $result) {
            $total += (float) ($result['latency_ms'] ?? 0);
        }

        return round($total, 2);
    }

    /**
     * Add an entry to the index (ring buffer).
     *
     * @param  array<string, mixed>  $entry
     */
    private function addToIndex(string $auditId, array $entry): void
    {
        $index = $this->getIndex();

        $index[$auditId] = [
            'audit_id' => $auditId,
            'event_name' => $entry['event_name'] ?? 'unknown',
            'client_id' => $entry['client_id'] ?? null,
            'user_id' => $entry['user_id'] ?? null,
            'source' => $entry['source'] ?? 'server',
            'timestamp' => $entry['timestamp'] ?? time(),
            'total_latency_ms' => $entry['total_latency_ms'] ?? 0,
            'provider_count' => $entry['provider_count'] ?? 0,
            'success_count' => $entry['success_count'] ?? 0,
            'failure_count' => $entry['failure_count'] ?? 0,
        ];

        // Enforce max entries (FIFO eviction)
        if (count($index) > $this->maxEntries) {
            $excess = count($index) - $this->maxEntries;
            $keys = array_keys($index);

            for ($i = 0; $i < $excess; $i++) {
                $evictKey = $keys[$i];
                $this->cache->forget(self::CACHE_PREFIX . $evictKey);
                unset($index[$evictKey]);
            }
        }

        $this->cache->put(self::INDEX_KEY, $index, $this->ttl + 86400);
    }

    /**
     * Increment global statistics counters.
     *
     * @param  array<string, mixed>  $entry
     */
    private function incrementStats(array $entry): void
    {
        $stats = $this->getStats();

        $stats['total_events'] = ($stats['total_events'] ?? 0) + 1;
        $stats['total_dispatched'] = ($stats['total_dispatched'] ?? 0) + (int) ($entry['provider_count'] ?? 0);
        $stats['total_successes'] = ($stats['total_successes'] ?? 0) + (int) ($entry['success_count'] ?? 0);
        $stats['total_failures'] = ($stats['total_failures'] ?? 0) + (int) ($entry['failure_count'] ?? 0);
        $stats['total_latency_ms'] = ($stats['total_latency_ms'] ?? 0) + (float) ($entry['total_latency_ms'] ?? 0);

        $this->cache->put(self::STATS_KEY, $stats, $this->ttl + 86400);
    }

    /**
     * Get the audit trail index.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getIndex(): array
    {
        /** @var array<string, array<string, mixed>>|null $index */
        $index = $this->cache->get(self::INDEX_KEY);

        return is_array($index) ? $index : [];
    }

    /**
     * Get the audit trail global statistics.
     *
     * @return array<string, mixed>
     */
    private function getStats(): array
    {
        /** @var array<string, mixed>|null $stats */
        $stats = $this->cache->get(self::STATS_KEY);

        return is_array($stats) ? $stats : [];
    }
}
