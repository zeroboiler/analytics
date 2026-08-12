<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Store;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\Contracts\AnalyticsEventStoreInterface;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Cache-backed event store for ephemeral event persistence.
 *
 * Stores events in the configured cache driver (Redis, Memcached, file, etc.).
 * Events are stored with a TTL and automatically expire. This is useful for
 * real-time dashboards, short-lived event replay, and environments where
 * database persistence is not needed.
 *
 * Note: Cache stores are volatile — events can be lost on cache flush or restart.
 * For persistent storage, use DatabaseEventStore instead.
 *
 * @since 30.0.0
 */
final class CacheEventStore implements AnalyticsEventStoreInterface
{
    /**
     * Cache key prefix for event storage.
     */
    private const CACHE_PREFIX = 'zb_event_store:';

    /**
     * Default TTL in seconds (24 hours).
     */
    private const DEFAULT_TTL = 86400;

    /**
     * Cache key for the event index set (stores all event IDs).
     */
    private const INDEX_KEY = 'zb_event_store:index';

    /**
     * Create a new cache event store instance.
     *
     * @param  string|null  $store  Cache store name (default, redis, etc.)
     * @param  positive-int  $ttl  Time-to-live in seconds
     */
    public function __construct(
        private readonly ?string $store = null,
        private readonly int $ttl = self::DEFAULT_TTL,
    ): void {}

    /**
     * {@inheritdoc}
     */
    public function store(AnalyticsEvent $event): ?string
    {
        $id = (string) \Illuminate\Support\Str::uuid();

        try {
            $serialized = $this->serialize($event);

            Cache::store($this->store)->put(
                self::CACHE_PREFIX . $id,
                $serialized,
                $this->ttl,
            );

            // Track in index for query support
            $this->addToIndex($id, $event);

            return $id;
        } catch (\Throwable $e) {
            Log::warning('ZeroBoiler: Failed to persist event to cache', [
                'event' => $event->name,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function storeBatch(array $events): array
    {
        $ids = [];

        foreach ($events as $event) {
            $id = $this->store($event);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * {@inheritdoc}
     */
    public function retrieve(string $id): ?AnalyticsEvent
    {
        $serialized = Cache::store($this->store)->get(self::CACHE_PREFIX . $id);

        if ($serialized === null) {
            return null;
        }

        return $this->unserialize($serialized);
    }

    /**
     * {@inheritdoc}
     */
    public function query(array $filters = []): array
    {
        $index = $this->getIndex();

        $events = [];

        foreach ($index as $id => $meta) {
            // Apply filters against metadata stored in index
            if (!$this->matchesFilters($meta, $filters)) {
                continue;
            }

            $event = $this->retrieve($id);

            if ($event !== null) {
                $events[] = $event;
            }
        }

        // Sort by timestamp descending
        usort($events, function (AnalyticsEvent $a, AnalyticsEvent $b) {
            $aTime = $a->timestamp?->getTimestamp() ?? 0;
            $bTime = $b->timestamp?->getTimestamp() ?? 0;

            return $bTime <=> $aTime;
        });

        // Apply limit/offset
        $limit = $filters['limit'] ?? 100;
        $offset = $filters['offset'] ?? 0;

        return array_slice($events, $offset, min($limit, 1000));
    }

    /**
     * {@inheritdoc}
     */
    public function count(array $filters = []): int
    {
        $index = $this->getIndex();

        $count = 0;

        foreach ($index as $meta) {
            if ($this->matchesFilters($meta, $filters)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(array $filters = []): int
    {
        $index = $this->getIndex();
        $deleted = 0;

        foreach ($index as $id => $meta) {
            if (!$this->matchesFilters($meta, $filters)) {
                continue;
            }

            Cache::store($this->store)->forget(self::CACHE_PREFIX . $id);
            $this->removeFromIndex($id);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteById(string $id): bool
    {
        $exists = Cache::store($this->store)->has(self::CACHE_PREFIX . $id);

        if (!$exists) {
            return false;
        }

        Cache::store($this->store)->forget(self::CACHE_PREFIX . $id);
        $this->removeFromIndex($id);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function purge(): bool
    {
        try {
            $index = $this->getIndex();

            foreach (array_keys($index) as $id) {
                Cache::store($this->store)->forget(self::CACHE_PREFIX . $id);
            }

            Cache::store($this->store)->forget(self::INDEX_KEY);

            return true;
        } catch (\Throwable $e) {
            Log::error('ZeroBoiler: Failed to purge cache event store', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function aggregateBy(string $groupBy, array $filters = []): array
    {
        $index = $this->getIndex();

        /** @var array<string, int> $aggregates */
        $aggregates = [];

        foreach ($index as $meta) {
            if (!$this->matchesFilters($meta, $filters)) {
                continue;
            }

            $key = $meta[$groupBy] ?? 'unknown';

            $aggregates[$key] = ($aggregates[$key] ?? 0) + 1;
        }

        arsort($aggregates);

        return $aggregates;
    }

    /**
     * {@inheritdoc}
     */
    public function isHealthy(): bool
    {
        try {
            Cache::store($this->store)->put('zb_health_check', '1', 10);
            $result = Cache::store($this->store)->get('zb_health_check');
            Cache::store($this->store)->forget('zb_health_check');

            return $result === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Serialize an event for cache storage.
     *
     * @param  AnalyticsEvent  $event
     * @return string JSON-serialized event
     */
    private function serialize(AnalyticsEvent $event): string
    {
        return json_encode([
            'name' => $event->name,
            'params' => $event->params,
            'timestamp' => $event->timestamp?->format('Y-m-d H:i:s'),
            'client_id' => $event->clientId,
            'user_id' => $event->userId,
            'priority' => $event->priority,
            'source' => $event->source,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Unserialize a cached event back to DTO.
     *
     * @param  string  $serialized
     * @return AnalyticsEvent
     */
    private function unserialize(string $serialized): AnalyticsEvent
    {
        /** @var array{name: string, params: array<string, mixed>, timestamp?: string|null, client_id?: string|null, user_id?: string|null, priority?: string|null, source?: string|null} $data */
        $data = json_decode($serialized, true, 512, JSON_THROW_ON_ERROR);

        $timestamp = isset($data['timestamp']) && is_string($data['timestamp'])
            ? \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $data['timestamp']) ?: null
            : null;

        return new AnalyticsEvent(
            name: $data['name'],
            params: $data['params'],
            clientId: is_string($data['client_id'] ?? null) ? $data['client_id'] : null,
            userId: is_string($data['user_id'] ?? null) ? $data['user_id'] : null,
            timestamp: $timestamp,
            priority: is_string($data['priority'] ?? null) ? $data['priority'] : null,
            source: is_string($data['source'] ?? null) ? $data['source'] : null,
        );
    }

    /**
     * Add event metadata to the index for query support.
     *
     * @param  string  $id
     * @param  AnalyticsEvent  $event
     */
    private function addToIndex(string $id, AnalyticsEvent $event): void
    {
        $index = $this->getIndex();
        $params = $event->params;

        $index[$id] = [
            'name' => $event->name,
            'category' => is_string($params['_category'] ?? null) ? $params['_category'] : null,
            'provider' => is_string($params['_provider'] ?? null) ? $params['_provider'] : null,
            'user_id' => $event->userId,
            'client_id' => $event->clientId,
            'source' => $event->source,
            'timestamp' => $event->timestamp?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
            'priority' => $event->priority ?? 'normal',
        ];

        Cache::store($this->store)->put(self::INDEX_KEY, $index, $this->ttl);
    }

    /**
     * Remove an event from the index.
     *
     * @param  string  $id
     */
    private function removeFromIndex(string $id): void
    {
        $index = $this->getIndex();
        unset($index[$id]);

        Cache::store($this->store)->put(self::INDEX_KEY, $index, $this->ttl);
    }

    /**
     * Get the full event index from cache.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getIndex(): array
    {
        /** @var array<string, array<string, mixed>>|null $index */
        $index = Cache::store($this->store)->get(self::INDEX_KEY);

        return is_array($index) ? $index : [];
    }

    /**
     * Check if index metadata matches query filters.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $filters
     */
    private function matchesFilters(array $meta, array $filters): bool
    {
        if (isset($filters['event_name']) && ($meta['name'] ?? null) !== $filters['event_name']) {
            return false;
        }

        if (isset($filters['category']) && ($meta['category'] ?? null) !== $filters['category']) {
            return false;
        }

        if (array_key_exists('provider', $filters) && ($meta['provider'] ?? null) !== $filters['provider']) {
            return false;
        }

        if (isset($filters['user_id']) && ($meta['user_id'] ?? null) !== $filters['user_id']) {
            return false;
        }

        if (isset($filters['client_id']) && ($meta['client_id'] ?? null) !== $filters['client_id']) {
            return false;
        }

        if (isset($filters['source']) && ($meta['source'] ?? null) !== $filters['source']) {
            return false;
        }

        if (isset($filters['from']) && ($meta['timestamp'] ?? '') < $filters['from']) {
            return false;
        }

        if (isset($filters['to']) && ($meta['timestamp'] ?? '') > $filters['to']) {
            return false;
        }

        return true;
    }
}
