<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Immutable analytics event audit log for GDPR Article 30 compliance.
 *
 * Records a tamper-proof, append-only log of all analytics events dispatched
 * through the system. Provides audit trail capabilities for:
 * - GDPR Article 30 (Records of Processing Activities)
 * - Data protection impact assessments
 * - Incident investigation and forensics
 * - Compliance reporting and audits
 *
 * Uses cache-backed storage with configurable retention. Each audit entry
 * includes the event name, timestamp, source, provider results, and a
 * content hash for integrity verification.
 *
 * Configuration is read from `zeroboiler.analytics.audit_log`.
 *
 * @since 21.0.0
 */
final class AnalyticsAuditLogService
{
    private const CACHE_PREFIX = 'zb_audit_';
    private const INDEX_KEY = 'zb_audit_index';
    private const MAX_ENTRIES_DEFAULT = 10000;

    private bool $enabled;

    private CacheRepository $cache;

    private int $retentionDays;

    private int $maxEntries;

    /** @var bool Whether to record successful dispatches */
    private bool $logSuccess;

    /** @var bool Whether to record failed dispatches */
    private bool $logFailures;

    /** @var list<string> Event names to exclude from audit log */
    private array $excludedEvents;

    /** @var list<string> Event categories to exclude from audit log */
    private array $excludedCategories;

    /**
     * @param  CacheRepository  $cache
     * @param  array<string, mixed>  $config  zeroboiler.analytics.audit_log
     */
    public function __construct(CacheRepository $cache, array $config): void
    {
        $this->cache = $cache;
        $this->enabled = (bool) ($config['enabled'] ?? false);
        $this->retentionDays = (int) ($config['retention_days'] ?? 90);
        $this->maxEntries = (int) ($config['max_entries'] ?? self::MAX_ENTRIES_DEFAULT);
        $this->logSuccess = (bool) ($config['log_success'] ?? true);
        $this->logFailures = (bool) ($config['log_failures'] ?? true);
        $this->excludedEvents = (array) ($config['excluded_events'] ?? []);
        $this->excludedCategories = (array) ($config['excluded_categories'] ?? []);
    }

    /**
     * Record an audit entry for a dispatched event.
     *
     * @param  AnalyticsEvent  $event  The event that was dispatched
     * @param  array{providers: array<string, bool>, pipeline_passed: bool, enriched: bool, validation_score: float|null}  $metadata  Dispatch metadata
     */
    public function record(AnalyticsEvent $event, array $metadata): void
    {
        if (! $this->enabled) {
            return;
        }

        // Check exclusions
        if ($this->shouldExclude($event, $metadata)) {
            return;
        }

        // Determine if this should be logged based on success/failure settings
        $hasFailures = in_array(false, $metadata['providers'] ?? [], true);
        if ($hasFailures && ! $this->logFailures) {
            return;
        }
        if (! $hasFailures && ! $this->logSuccess) {
            return;
        }

        $entry = $this->createEntry($event, $metadata);

        try {
            $this->storeEntry($entry);
        } catch (\Throwable $e) {
            Log::debug('AnalyticsAuditLogService: failed to store audit entry', [
                'error' => $e->getMessage(),
                'event' => $event->name,
            ]);
        }
    }

    /**
     * Query audit log entries with optional filters.
     *
     * @param  array{event_name?: string, source?: string, from?: string|null, to?: string|null, limit?: int, offset?: int}  $filters
     * @return array{entries: list<array<string, mixed>>, total: int, has_more: bool}
     */
    public function query(array $filters = []): array
    {
        $limit = (int) ($filters['limit'] ?? 50);
        $offset = (int) ($filters['offset'] ?? 0);

        $allEntries = $this->getAllEntries();

        // Apply filters
        $filtered = $allEntries;

        if (isset($filters['event_name'])) {
            $eventName = (string) $filters['event_name'];
            $filtered = array_filter($filtered, fn (array $e): bool => $e['event_name'] === $eventName);
        }

        if (isset($filters['source'])) {
            $source = (string) $filters['source'];
            $filtered = array_filter($filtered, fn (array $e): bool => ($e['source'] ?? '') === $source);
        }

        if (isset($filters['from']) && $filters['from'] !== null) {
            $from = (string) $filters['from'];
            $filtered = array_filter($filtered, fn (array $e): bool => ($e['timestamp'] ?? '') >= $from);
        }

        if (isset($filters['to']) && $filters['to'] !== null) {
            $to = (string) $filters['to'];
            $filtered = array_filter($filtered, fn (array $e): bool => ($e['timestamp'] ?? '') <= $to);
        }

        $filtered = array_values($filtered);
        $total = count($filtered);

        return [
            'entries' => array_slice($filtered, $offset, $limit),
            'total' => $total,
            'has_more' => ($offset + $limit) < $total,
        ];
    }

    /**
     * Get audit log statistics.
     *
     * @return array{total_entries: int, events_by_name: array<string, int>, events_by_source: array<string, int>, success_rate: float, oldest_entry: string|null, newest_entry: string|null}
     */
    public function stats(): array
    {
        $entries = $this->getAllEntries();

        if ($entries === []) {
            return [
                'total_entries' => 0,
                'events_by_name' => [],
                'events_by_source' => [],
                'success_rate' => 0.0,
                'oldest_entry' => null,
                'newest_entry' => null,
            ];
        }

        $byName = [];
        $bySource = [];
        $successCount = 0;
        $oldest = $entries[0]['timestamp'] ?? null;
        $newest = $entries[count($entries) - 1]['timestamp'] ?? null;

        foreach ($entries as $entry) {
            $name = $entry['event_name'] ?? 'unknown';
            $source = $entry['source'] ?? 'unknown';
            $byName[$name] = ($byName[$name] ?? 0) + 1;
            $bySource[$source] = ($bySource[$source] ?? 0) + 1;

            if (($entry['metadata']['pipeline_passed'] ?? true)) {
                $successCount++;
            }
        }

        return [
            'total_entries' => count($entries),
            'events_by_name' => $byName,
            'events_by_source' => $bySource,
            'success_rate' => count($entries) > 0 ? round($successCount / count($entries), 4) : 0.0,
            'oldest_entry' => $oldest,
            'newest_entry' => $newest,
        ];
    }

    /**
     * Clear all audit log entries.
     */
    public function clear(): void
    {
        $index = $this->cache->get(self::INDEX_KEY, []);
        if (! is_array($index)) {
            $index = [];
        }

        foreach ($index as $key) {
            $this->cache->forget($key);
        }

        $this->cache->forget(self::INDEX_KEY);
    }

    /**
     * Prune entries older than the configured retention period.
     *
     * @return int Number of entries pruned
     */
    public function prune(): int
    {
        $entries = $this->getAllEntries();
        $cutoff = (new \DateTimeImmutable())->modify("-{$this->retentionDays} days")->format('Y-m-d\TH:i:s\Z');

        $pruned = 0;
        $remaining = [];

        foreach ($entries as $entry) {
            if (($entry['timestamp'] ?? '') < $cutoff) {
                $pruned++;
            } else {
                $remaining[] = $entry;
            }
        }

        if ($pruned > 0) {
            $this->storeAllEntries($remaining);
        }

        return $pruned;
    }

    /**
     * Check if the audit log is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Create an audit entry.
     *
     * @return array<string, mixed>
     */
    private function createEntry(AnalyticsEvent $event, array $metadata): array
    {
        $timestamp = $event->timestamp ?? new \DateTimeImmutable();

        return [
            'id' => bin2hex(random_bytes(16)),
            'event_name' => $event->name,
            'timestamp' => $timestamp->format('Y-m-d\TH:i:s\Z'),
            'source' => $event->source ?? 'unknown',
            'client_id' => $event->clientId,
            'user_id' => $event->userId,
            'param_count' => count($event->params),
            'payload_hash' => hash('xxh128', json_encode($event->params)),
            'priority' => $event->priority,
            'metadata' => $metadata,
            'recorded_at' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Store an audit entry.
     *
     * @param  array<string, mixed>  $entry
     */
    private function storeEntry(array $entry): void
    {
        $entries = $this->getAllEntries();
        $entries[] = $entry;

        // Enforce max entries
        if (count($entries) > $this->maxEntries) {
            $entries = array_slice($entries, -$this->maxEntries);
        }

        $this->storeAllEntries($entries);
    }

    /**
     * Get all audit entries from cache.
     *
     * @return list<array<string, mixed>>
     */
    private function getAllEntries(): array
    {
        $entries = $this->cache->get(self::INDEX_KEY, []);

        return is_array($entries) ? $entries : [];
    }

    /**
     * Store all audit entries to cache.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    private function storeAllEntries(array $entries): void
    {
        $this->cache->put(self::INDEX_KEY, $entries, $this->retentionDays * 86400);
    }

    /**
     * Check if an event should be excluded from audit logging.
     *
     * @param  array{providers: array<string, bool>, pipeline_passed: bool}  $metadata
     */
    private function shouldExclude(AnalyticsEvent $event, array $metadata): bool
    {
        if (in_array($event->name, $this->excludedEvents, true)) {
            return true;
        }

        // Check category exclusion via EventCatalog
        if ($this->excludedCategories !== []) {
            $category = \ZeroBoiler\Analytics\Events\EventCatalog::getCategory($event->name);
            if ($category !== null && in_array($category, $this->excludedCategories, true)) {
                return true;
            }
        }

        return false;
    }
}
