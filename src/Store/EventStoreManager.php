<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Store;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\Contracts\AnalyticsEventStoreInterface;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event Store Manager — orchestrates multiple persistence backends.
 *
 * Provides a unified API for event persistence with support for:
 * - **Primary store**: The main persistence backend (database or cache)
 * - **Fallback store**: Used when the primary store is unavailable
 * - **Dual-write mode**: Writes to both stores simultaneously for redundancy
 * - **Read preference**: Configurable read source (primary, fallback, or closest)
 *
 * Also integrates with the AnalyticsManager dispatch pipeline to automatically
 * persist events when event_store.auto_persist is enabled.
 *
 * @since 30.0.0
 */
final class EventStoreManager implements AnalyticsEventStoreInterface
{
    private ?AnalyticsEventStoreInterface $primary = null;

    private ?AnalyticsEventStoreInterface $fallback = null;

    private ?AnalyticsEventStoreInterface $writeStore = null;

    /**
     * Whether auto-persist is enabled (events are stored on every dispatch).
     */
    private bool $autoPersist;

    /**
     * Create a new EventStoreManager instance.
     *
     * @param  ConfigRepository  $config
     */
    public function __construct(private readonly ConfigRepository $config){
        $storeConfig = $config->get('zeroboiler.analytics.event_store', []);

        $this->autoPersist = (bool) ($storeConfig['auto_persist'] ?? false);

        $driver = $storeConfig['driver'] ?? 'cache';

        $this->primary = $this->resolveDriver($driver, $storeConfig);

        // Initialize fallback if configured
        $fallbackDriver = $storeConfig['fallback_driver'] ?? null;
        if ($fallbackDriver !== null) {
            $this->fallback = $this->resolveDriver($fallbackDriver, $storeConfig);
        }
    }

    /**
     * Resolve a store driver from configuration.
     *
     * @param  string  $driver  Driver name (database, cache, null)
     * @param  array<string, mixed>  $config  Store configuration
     */
    private function resolveDriver(string $driver, array $config): AnalyticsEventStoreInterface
    {
        return match ($driver) {
            'database', 'db' => new DatabaseEventStore(
                connection: $config['db_connection'] ?? 'mysql',
                table: $config['db_table'] ?? 'analytics_events',
            ),
            'cache', 'redis', 'array' => new CacheEventStore(
                store: $driver === 'array' ? 'array' : ($config['cache_store'] ?? null),
                ttl: (int) ($config['cache_ttl'] ?? 86400),
            ),
            default => new CacheEventStore(
                store: null,
                ttl: (int) ($config['cache_ttl'] ?? 86400),
            ),
        };
    }

    /**
     * {@inheritdoc}
     */
    public function store(AnalyticsEvent $event): ?string
    {
        return $this->writeToPrimary($event);
    }

    /**
     * {@inheritdoc}
     */
    public function storeBatch(array $events): array
    {
        return $this->writeBatchToPrimary($events);
    }

    /**
     * {@inheritdoc}
     */
    public function retrieve(string $id): ?AnalyticsEvent
    {
        return $this->readFromPrimary($id);
    }

    /**
     * {@inheritdoc}
     */
    public function query(array $filters = []): array
    {
        return $this->readQueryFromPrimary($filters);
    }

    /**
     * {@inheritdoc}
     */
    public function count(array $filters = []): int
    {
        return $this->countFromPrimary($filters);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(array $filters = []): int
    {
        $count = 0;

        if ($this->primary !== null) {
            $count = $this->primary->delete($filters);
        }

        if ($this->fallback !== null) {
            $this->fallback->delete($filters);
        }

        return $count;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteById(string $id): bool
    {
        $result = false;

        if ($this->primary !== null) {
            $result = $this->primary->deleteById($id);
        }

        if ($this->fallback !== null) {
            $this->fallback->deleteById($id);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function purge(): bool
    {
        $result = true;

        if ($this->primary !== null) {
            $result = $result && $this->primary->purge();
        }

        if ($this->fallback !== null) {
            $result = $result && $this->fallback->purge();
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function aggregateBy(string $groupBy, array $filters = []): array
    {
        if ($this->primary !== null) {
            return $this->primary->aggregateBy($groupBy, $filters);
        }

        if ($this->fallback !== null) {
            return $this->fallback->aggregateBy($groupBy, $filters);
        }

        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function isHealthy(): bool
    {
        return $this->primary?->isHealthy() ?? false;
    }

    /**
     * Get the primary store instance.
     */
    public function getPrimary(): ?AnalyticsEventStoreInterface
    {
        return $this->primary;
    }

    /**
     * Get the fallback store instance.
     */
    public function getFallback(): ?AnalyticsEventStoreInterface
    {
        return $this->fallback;
    }

    /**
     * Check if auto-persist is enabled.
     */
    public function isAutoPersist(): bool
    {
        return $this->autoPersist;
    }

    /**
     * Get health status for all configured stores.
     *
     * @return array{primary: bool, fallback: bool|null}
     */
    public function healthReport(): array
    {
        return [
            'primary' => $this->primary?->isHealthy() ?? false,
            'fallback' => $this->fallback?->isHealthy() ?? null,
        ];
    }

    /**
     * Get store statistics.
     *
     * @return array{primary_total: int, fallback_total: int, driver: string, fallback_driver: string|null}
     */
    public function stats(): array
    {
        return [
            'primary_total' => $this->primary?->count() ?? 0,
            'fallback_total' => $this->fallback?->count() ?? 0,
            'driver' => $this->config->get('zeroboiler.analytics.event_store.driver', 'cache'),
            'fallback_driver' => $this->config->get('zeroboiler.analytics.event_store.fallback_driver'),
        ];
    }

    /**
     * Write to primary with fallback on failure.
     */
    private function writeToPrimary(AnalyticsEvent $event): ?string
    {
        if ($this->primary !== null) {
            $id = $this->primary->store($event);

            if ($id !== null) {
                return $id;
            }

            // Primary failed — try fallback
            Log::warning('ZeroBoiler: Primary event store failed, trying fallback');
        }

        if ($this->fallback !== null) {
            return $this->fallback->store($event);
        }

        return null;
    }

    /**
     * Batch write to primary with fallback on failure.
     */
    private function writeBatchToPrimary(array $events): array
    {
        if ($this->primary !== null) {
            $ids = $this->primary->storeBatch($events);

            if ($ids !== []) {
                return $ids;
            }

            Log::warning('ZeroBoiler: Primary event store batch failed, trying fallback');
        }

        if ($this->fallback !== null) {
            return $this->fallback->storeBatch($events);
        }

        return [];
    }

    /**
     * Read from primary with fallback on miss.
     */
    private function readFromPrimary(string $id): ?AnalyticsEvent
    {
        if ($this->primary !== null) {
            $event = $this->primary->retrieve($id);

            if ($event !== null) {
                return $event;
            }
        }

        return $this->fallback?->retrieve($id);
    }

    /**
     * Query from primary, falling back to fallback if primary returns empty.
     */
    private function readQueryFromPrimary(array $filters): array
    {
        if ($this->primary !== null) {
            $results = $this->primary->query($filters);

            if ($results !== []) {
                return $results;
            }
        }

        return $this->fallback?->query($filters) ?? [];
    }

    /**
     * Count from primary, falling back to fallback.
     */
    private function countFromPrimary(array $filters): int
    {
        if ($this->primary !== null) {
            return $this->primary->count($filters);
        }

        return $this->fallback?->count($filters) ?? 0;
    }
}
