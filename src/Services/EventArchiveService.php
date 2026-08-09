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
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Persistent event archive service for SaaS analytics debugging and replay.
 *
 * Stores dispatched analytics events in a cache-backed ring buffer with
 * search, filter, and pagination support. Enables admin dashboards to
 * inspect recent event activity, debug dispatch issues, and replay
 * specific events for reprocessing.
 *
 * Features:
 * - Configurable retention TTL and maximum event count
 * - Search by event name, client ID, user ID
 * - Filter by event category, time range, dispatch status
 * - Paginated listing with cursor-based pagination
 * - Single event and bulk replay to active providers
 * - Event count statistics per event name
 * - Cache-backed storage (works with file, redis, database drivers)
 *
 * Configuration is read from `zeroboiler.analytics.archive`.
 *
 * @version 4.6.0
 */
final class EventArchiveService
{
    /** @var string Cache key prefix for archived events */
    private string $cachePrefix;

    /** @var int Default retention TTL in seconds (24 hours) */
    private int $retentionTtl;

    /** @var int Maximum number of events to archive before eviction */
    private int $maxEvents;

    /** @var bool Whether archiving is enabled */
    private bool $enabled;

    /** @var list<string> Event names to always archive (empty = all) */
    private array $alwaysArchive;

    /** @var list<string> Event names to never archive */
    private array $neverArchive;

    private CacheRepository $cache;

    private AnalyticsManager $manager;

    /**
     * Create a new EventArchiveService instance.
     *
     * @param  CacheRepository  $cache  Cache repository instance
     * @param  AnalyticsManager  $manager  Analytics manager for replay dispatch
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(
        CacheRepository $cache,
        AnalyticsManager $manager,
        ConfigRepository $config,
    ): void {
        $archiveConfig = $config->get('zeroboiler.analytics.archive', []);
        /** @var array{enabled?: bool, cache_prefix?: string, retention_ttl?: int, max_events?: int, always_archive?: list<string>, never_archive?: list<string>} $archiveConfig */

        $this->cache = $cache;
        $this->manager = $manager;
        $this->enabled = (bool) ($archiveConfig['enabled'] ?? false);
        $this->cachePrefix = (string) ($archiveConfig['cache_prefix'] ?? 'zb_archive_');
        $this->retentionTtl = (int) ($archiveConfig['retention_ttl'] ?? 86400);
        $this->maxEvents = (int) ($archiveConfig['max_events'] ?? 10000);
        $this->alwaysArchive = $archiveConfig['always_archive'] ?? [];
        $this->neverArchive = $archiveConfig['never_archive'] ?? [];
    }

    /**
     * Archive a dispatched analytics event.
     *
     * Stores the event in the cache-backed archive with auto-incrementing
     * ID and metadata. Events exceeding max_events are evicted (FIFO).
     *
     * @param  AnalyticsEvent  $event  The dispatched event
     * @param  bool  $dispatched  Whether the event was successfully dispatched to at least one provider
     * @param  list<string>  $providers  List of provider names that received the event
     */
    public function archive(AnalyticsEvent $event, bool $dispatched = true, array $providers = []): void
    {
        if (! $this->enabled) {
            return;
        }

        if (! $this->shouldArchive($event->name)) {
            return;
        }

        $id = $this->nextId();

        $entry = [
            'id' => $id,
            'name' => $event->name,
            'params' => $this->sanitizeParams($event->params),
            'client_id' => $event->clientId,
            'user_id' => $event->userId,
            'dispatched' => $dispatched,
            'providers' => $providers,
            'timestamp' => $event->timestamp?->format('c') ?? now()->format('c'),
            'archived_at' => now()->format('c'),
        ];

        $this->cache->put($this->eventKey($id), $entry, $this->retentionTtl);
        $this->cache->put($this->countKey(), $id, $this->retentionTtl);

        // Evict oldest events if at capacity
        if ($id > $this->maxEvents) {
            $evictStart = 1;
            $evictEnd = $id - $this->maxEvents;

            for ($i = $evictStart; $i <= $evictEnd; $i++) {
                $this->cache->forget($this->eventKey($i));
            }
        }

        // Update per-event-name counter
        $this->incrementNameCount($event->name);
    }

    /**
     * Get a single archived event by ID.
     *
     * @param  int  $id  Event archive ID
     * @return array{id: int, name: string, params: array<string, mixed>, client_id: string|null, user_id: string|null, dispatched: bool, providers: list<string>, timestamp: string, archived_at: string}|null
     */
    public function get(int $id): ?array
    {
        /** @var array{id: int, name: string, params: array<string, mixed>, client_id: string|null, user_id: string|null, dispatched: bool, providers: list<string>, timestamp: string, archived_at: string}|null $entry */
        $entry = $this->cache->get($this->eventKey($id));

        return is_array($entry) ? $entry : null;
    }

    /**
     * Search archived events with filters and pagination.
     *
     * @param  array{name?: string, client_id?: string, user_id?: string, dispatched?: bool|null, since?: string|null, until?: string|null}  $filters  Search filters
     * @param  int  $limit  Maximum results per page (default: 50)
     * @param  int  $offset  Number of events to skip (default: 0)
     * @return array{events: list<array{id: int, name: string, params: array<string, mixed>, client_id: string|null, user_id: string|null, dispatched: bool, providers: list<string>, timestamp: string, archived_at: string}>, total: int, limit: int, offset: int}
     */
    public function search(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $lastId = $this->lastId();

        if ($lastId === 0) {
            return ['events' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
        }

        $results = [];
        $scanned = 0;
        $maxScan = $this->maxEvents;

        // Scan backwards from most recent to oldest
        for ($id = $lastId; $id >= 1 && $scanned < $maxScan; $id--) {
            $entry = $this->get($id);

            if ($entry === null) {
                $scanned++;
                continue;
            }

            $scanned++;

            if ($this->matchesFilters($entry, $filters)) {
                $results[] = $entry;
            }

            // Stop early if we have enough results after offset
            if (count($results) >= $limit + $offset) {
                break;
            }
        }

        // Calculate total matching events (approximate by counting all matches)
        $total = count($results);

        // Apply offset
        $events = array_slice($results, $offset, $limit);

        // Events are in reverse chronological order; restore chronological order
        $events = array_values(array_reverse($events));

        return [
            'events' => $events,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Replay a single archived event to all active providers.
     *
     * Re-dispatches the event through the AnalyticsManager, creating a new
     * dispatch cycle. The replayed event gets a new archive entry.
     *
     * @param  int  $id  Archive event ID
     * @return bool Whether the replay was successful
     */
    public function replay(int $id): bool
    {
        $entry = $this->get($id);

        if ($entry === null) {
            return false;
        }

        $event = new AnalyticsEvent(
            name: $entry['name'],
            params: $entry['params'],
            clientId: $entry['client_id'],
            userId: $entry['user_id'],
        );

        $this->manager->track($event);

        Log::info('ZeroBoiler Analytics: Archived event replayed', [
            'archive_id' => $id,
            'event' => $entry['name'],
        ]);

        return true;
    }

    /**
     * Bulk replay all events matching given filters.
     *
     * @param  array{name?: string, client_id?: string, user_id?: string, dispatched?: bool|null, since?: string|null, until?: string|null}  $filters  Search filters
     * @return array{replayed: int, failed: int, total: int}
     */
    public function replayBulk(array $filters = []): array
    {
        $results = $this->search($filters, limit: 1000, offset: 0);
        $replayed = 0;
        $failed = 0;

        foreach ($results['events'] as $entry) {
            $success = $this->replay($entry['id']);

            if ($success) {
                $replayed++;
            } else {
                $failed++;
            }
        }

        return [
            'replayed' => $replayed,
            'failed' => $failed,
            'total' => $results['total'],
        ];
    }

    /**
     * Get event count statistics grouped by event name.
     *
     * Returns a sorted list of event names with their archive counts.
     * Useful for admin dashboards showing event distribution.
     *
     * @param  int  $limit  Maximum event names to return (default: 20)
     * @return array<array{name: string, count: int}>
     */
    public function eventCounts(int $limit = 20): array
    {
        /** @var array<string, int>|null $counts */
        $counts = $this->cache->get($this->nameCountsKey());

        if (! is_array($counts)) {
            return [];
        }

        arsort($counts);

        return array_slice(
            array_map(fn (string $name, int $count): array => ['name' => $name, 'count' => $count], array_keys($counts), array_values($counts)),
            0,
            $limit,
        );
    }

    /**
     * Get the total number of archived events.
     */
    public function totalArchived(): int
    {
        return $this->lastId();
    }

    /**
     * Clear all archived events.
     *
     * @return int Number of events cleared
     */
    public function clear(): int
    {
        $lastId = $this->lastId();

        if ($lastId === 0) {
            return 0;
        }

        $cleared = 0;

        for ($id = 1; $id <= $lastId; $id++) {
            if ($this->cache->forget($this->eventKey($id))) {
                $cleared++;
            }
        }

        $this->cache->forget($this->countKey());
        $this->cache->forget($this->nameCountsKey());

        return $cleared;
    }

    /**
     * Delete a single archived event by ID.
     */
    public function delete(int $id): bool
    {
        $entry = $this->get($id);

        if ($entry === null) {
            return false;
        }

        $this->cache->forget($this->eventKey($id));
        $this->decrementNameCount($entry['name']);

        return true;
    }

    /**
     * Check if an event name should be archived based on filter rules.
     */
    private function shouldArchive(string $name): bool
    {
        // Never archive blacklist
        if (in_array($name, $this->neverArchive, true)) {
            return false;
        }

        // Always archive whitelist
        if ($this->alwaysArchive !== [] && in_array($name, $this->alwaysArchive, true)) {
            return true;
        }

        // If whitelist is set, only archive whitelisted events
        if ($this->alwaysArchive !== []) {
            return false;
        }

        return true;
    }

    /**
     * Check if an archive entry matches all given filters.
     *
     * @param  array<string, mixed>  $entry  Archive entry
     * @param  array{name?: string, client_id?: string, user_id?: string, dispatched?: bool|null, since?: string|null, until?: string|null}  $filters
     */
    private function matchesFilters(array $entry, array $filters): bool
    {
        if (isset($filters['name']) && $filters['name'] !== '') {
            if (! str_contains(strtolower($entry['name']), strtolower($filters['name']))) {
                return false;
            }
        }

        if (isset($filters['client_id']) && $filters['client_id'] !== '') {
            if ($entry['client_id'] !== $filters['client_id']) {
                return false;
            }
        }

        if (isset($filters['user_id']) && $filters['user_id'] !== '') {
            if ($entry['user_id'] !== $filters['user_id']) {
                return false;
            }
        }

        if (isset($filters['dispatched']) && $filters['dispatched'] !== null) {
            if ($entry['dispatched'] !== $filters['dispatched']) {
                return false;
            }
        }

        if (isset($filters['since']) && $filters['since'] !== '') {
            if ($entry['timestamp'] < $filters['since']) {
                return false;
            }
        }

        if (isset($filters['until']) && $filters['until'] !== '') {
            if ($entry['timestamp'] > $filters['until']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the next auto-incrementing event ID.
     */
    private function nextId(): int
    {
        return $this->lastId() + 1;
    }

    /**
     * Get the last archived event ID.
     */
    private function lastId(): int
    {
        return (int) ($this->cache->get($this->countKey()) ?? 0);
    }

    /**
     * Increment the per-event-name counter.
     */
    private function incrementNameCount(string $name): void
    {
        /** @var array<string, int> $counts */
        $counts = $this->cache->get($this->nameCountsKey(), []);
        $counts[$name] = ($counts[$name] ?? 0) + 1;
        $this->cache->put($this->nameCountsKey(), $counts, $this->retentionTtl);
    }

    /**
     * Decrement the per-event-name counter.
     */
    private function decrementNameCount(string $name): void
    {
        /** @var array<string, int> $counts */
        $counts = $this->cache->get($this->nameCountsKey(), []);

        if (isset($counts[$name])) {
            $counts[$name]--;

            if ($counts[$name] <= 0) {
                unset($counts[$name]);
            }

            $this->cache->put($this->nameCountsKey(), $counts, $this->retentionTtl);
        }
    }

    /**
     * Get the cache key for a specific event ID.
     */
    private function eventKey(int $id): string
    {
        return "{$this->cachePrefix}event_{$id}";
    }

    /**
     * Get the cache key for the last event ID counter.
     */
    private function countKey(): string
    {
        return "{$this->cachePrefix}last_id";
    }

    /**
     * Get the cache key for the per-name event counts.
     */
    private function nameCountsKey(): string
    {
        return "{$this->cachePrefix}name_counts";
    }

    /**
     * Sanitize event parameters for archive storage.
     *
     * Removes potentially large or sensitive values to keep
     * archive entries lightweight.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function sanitizeParams(array $params): array
    {
        $sanitized = [];

        foreach ($params as $key => $value) {
            // Skip internal trace/metadata params (prefixed with _)
            if (str_starts_with((string) $key, '_trace_')) {
                continue;
            }

            // Truncate long string values
            if (is_string($value) && mb_strlen($value) > 500) {
                $value = mb_substr($value, 0, 500) . '...';
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }
}
