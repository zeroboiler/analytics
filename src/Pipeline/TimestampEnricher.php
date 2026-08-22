<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Adds a timestamp and session context to analytics events.
 *
 * Enriches events with:
 * - `event_timestamp`: ISO 8601 timestamp
 * - `event_epoch`: Unix epoch (seconds)
 * - `session_id`: Custom session identifier (if provided)
 *
 * @since 1.0.0
 */
final readonly class TimestampEnricher
{
    private ?string $sessionId;

    public function __construct(?string $sessionId = null){
        $this->sessionId = $sessionId;
    }

    /**
     * Enrich the event with timestamp and session data.
     *
     * @return AnalyticsEvent|null
     */
    public function __invoke(AnalyticsEvent $event): ?AnalyticsEvent
    {
        $additional = [
            'event_timestamp' => date('c'),
            'event_epoch' => time(),
        ];

        if ($this->sessionId !== null && $this->sessionId !== '') {
            $additional['session_id'] = $this->sessionId;
        }

        return new AnalyticsEvent(
            name: $event->name,
            params: array_merge($event->params, $additional),
            clientId: $event->clientId,
            userId: $event->userId,
        );
    }
}
