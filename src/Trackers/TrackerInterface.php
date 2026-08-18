<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Trackers;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;

/**
 * @since 1.0.0
 */
interface TrackerInterface
{
    /**
     * Track an analytics event.
     */
    public function track(AnalyticsEvent $event): void;

    /**
     * Check if the tracker is enabled and properly configured.
     */
    public function isEnabled(): bool;

    /**
     * Get the script tags for head section.
     */
    public function headScripts(): string;

    /**
     * Get the script tags for body section.
     */
    public function bodyScripts(): string;

    /**
     * Update the tracker's consent state.
     */
    public function setConsent(ConsentState $state): void;

    /**
     * Get the current consent state applied to this tracker.
     */
    public function getConsent(): ConsentState;

    /**
     * Track multiple analytics events in a single batch dispatch.
     *
     * Providers that support batch APIs (GA4 Measurement Protocol, Meta CAPI,
     * PostHog /batch, Plausible /api/v2/event batch) will send all events
     * in one HTTP request. Providers without native batch support fall back
     * to sequential track() calls.
     *
     * @param  list<AnalyticsEvent>  $events  Events to dispatch
     * @return int  Number of events successfully dispatched
     *
     * @since 243.0.0
     */
    public function trackBatch(array $events): int;
}
