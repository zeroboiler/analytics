<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\TrackingPreferenceService;

/**
 * Pipeline filter that checks per-user tracking preferences.
 *
 * When a user has opted out of tracking (via TrackingPreferenceService),
 * this filter drops the event by returning null. This operates independently
 * from consent signals — tracking preferences suppress dispatch even when
 * consent is granted.
 *
 * @see \ZeroBoiler\Analytics\Services\TrackingPreferenceService
 *
 * @since 1.0.0
 */
final class TrackingPreferenceFilter
{
    private TrackingPreferenceService $preferenceService;

    private bool $checkClientSuppression;

    /**
     * @param  TrackingPreferenceService  $preferenceService  Per-user tracking preference service
     * @param  bool  $checkClientSuppression  Whether to also check anonymous client ID suppression
     */
    public function __construct(
        TrackingPreferenceService $preferenceService,
        bool $checkClientSuppression = true,
    ): void {
        $this->preferenceService = $preferenceService;
        $this->checkClientSuppression = $checkClientSuppression;
    }

    /**
     * Filter an event based on tracking preferences.
     *
     * Returns null if the user or client has opted out of tracking.
     *
     * @return AnalyticsEvent|null The event if tracking is allowed, null if suppressed
     */
    public function __invoke(AnalyticsEvent $event): ?AnalyticsEvent
    {
        if ($this->preferenceService->shouldTrack($event->userId, $this->checkClientSuppression ? $event->clientId : null)) {
            return $event;
        }

        // Event is suppressed — return null to drop from pipeline
        return null;
    }
}
