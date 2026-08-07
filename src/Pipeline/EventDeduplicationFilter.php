<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventDeduplicationService;

/**
 * Pipeline filter that deduplicates analytics events.
 *
 * Uses EventDeduplicationService to compute an event fingerprint
 * (based on event name, client ID, user ID, and params hash) and
 * drops duplicate events within the configured deduplication window.
 *
 * This prevents double-counting from SPA re-renders, rapid clicks,
 * or race conditions in batch processing.
 *
 * @see \ZeroBoiler\Analytics\Services\EventDeduplicationService
 */
final class EventDeduplicationFilter
{
    private EventDeduplicationService $deduplicationService;

    /**
     * @param  EventDeduplicationService  $deduplicationService  Cache-based event deduplication service
     */
    public function __construct(EventDeduplicationService $deduplicationService): void
    {
        $this->deduplicationService = $deduplicationService;
    }

    /**
     * Filter duplicate events from the pipeline.
     *
     * Returns null if the event is a duplicate within the deduplication window.
     * The isDuplicate() method internally records the fingerprint when the
     * event is not a duplicate, so no separate record() call is needed.
     *
     * @return AnalyticsEvent|null The event if unique, null if duplicate
     */
    public function __invoke(AnalyticsEvent $event): ?AnalyticsEvent
    {
        $isDuplicate = $this->deduplicationService->isDuplicate(
            eventName: $event->name,
            clientId: $event->clientId,
            userId: $event->userId,
            params: $event->params,
        );

        if ($isDuplicate) {
            return null;
        }

        return $event;
    }
}
