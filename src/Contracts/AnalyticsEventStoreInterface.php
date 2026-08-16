<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Contracts;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Contract for analytics event persistence backends.
 *
 * Implementations handle the storage and retrieval of analytics events
 * across different backends (database, cache, external warehouses, etc.).
 *
 * The event store is the foundational persistence layer that enables
 * event replay, historical queries, data lake exports, and audit trails.
 *
 * @since 30.0.0
 */
interface AnalyticsEventStoreInterface
{
    /**
     * Persist a single analytics event.
     *
     * @param  AnalyticsEvent  $event  The event to store
     * @return string|null Storage identifier (e.g. database ID) or null on failure
     */
    public function store(AnalyticsEvent $event): ?string;

    /**
     * Persist multiple events in a single batch operation.
     *
     * Implementations should optimize for bulk inserts where possible.
     *
     * @param  array<AnalyticsEvent>  $events  Events to store
     * @return array<string> Storage identifiers for successfully stored events
     */
    public function storeBatch(array $events): array;

    /**
     * Retrieve a single event by its storage identifier.
     *
     * @param  string  $id  Storage identifier
     * @return AnalyticsEvent|null The event, or null if not found
     */
    public function retrieve(string $id): ?AnalyticsEvent;

    /**
     * Query events matching the given filters.
     *
     * @param  array{
     *     event_name?: string,
     *     category?: string,
     *     provider?: string|null,
     *     user_id?: string|null,
     *     client_id?: string|null,
     *     from?: string|null,
     *     to?: string|null,
     *     limit?: int,
     *     offset?: int,
     *     sort?: string,
     *     direction?: string,
     * }  $filters  Query filters
     * @return array<AnalyticsEvent> Matching events
     */
    public function query(array $filters = []): array;

    /**
     * Count events matching the given filters.
     *
     * @param  array<string, mixed>  $filters  Query filters (same as query())
     * @return int Number of matching events
     */
    public function count(array $filters = []): int;

    /**
     * Delete events matching the given filters.
     *
     * Used by GDPR erasure, data retention policies, and manual cleanup.
     *
     * @param  array<string, mixed>  $filters  Query filters (same as query())
     * @return int Number of deleted events
     */
    public function delete(array $filters = []): int;

    /**
     * Delete a single event by its storage identifier.
     *
     * @param  string  $id  Storage identifier
     * @return bool True if deleted, false if not found
     */
    public function deleteById(string $id): bool;

    /**
     * Purge all events. Use with extreme caution.
     *
     * @return bool True if successful
     */
    public function purge(): bool;

    /**
     * Get aggregated event counts grouped by the specified dimension.
     *
     * @param  string  $groupBy  Dimension to group by (event_name, category, provider, hour, day, month)
     * @param  array<string, mixed>  $filters  Query filters (same as query())
     * @return array<string, int> Grouped counts
     */
    public function aggregateBy(string $groupBy, array $filters = []): array;

    /**
     * Check if the store backend is healthy and reachable.
     *
     * @return bool True if the store is operational
     */
    public function isHealthy(): bool;
}
