<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Store;

use ZeroBoiler\Analytics\Contracts\AnalyticsEventStoreInterface;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Null event store — no-op implementation for testing and disabled mode.
 *
 * Implements the store interface with empty operations. All methods return
 * empty results without any side effects. Use this when event persistence
 * is disabled or in test environments.
 *
 * @since 30.0.0
 */
final class NullEventStore implements AnalyticsEventStoreInterface
{
    /**
     * {@inheritdoc}
     */
    public function store(AnalyticsEvent $event): ?string
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function storeBatch(array $events): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function retrieve(string $id): ?AnalyticsEvent
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function query(array $filters = []): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function count(array $filters = []): int
    {
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(array $filters = []): int
    {
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteById(string $id): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function purge(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function aggregateBy(string $groupBy, array $filters = []): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function isHealthy(): bool
    {
        return true;
    }
}
