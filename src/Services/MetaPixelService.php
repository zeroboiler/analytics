<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Trackers\MetaPixelTracker;

/**
 * High-level service wrapper for Meta Pixel (Conversions API).
 *
 * Provides convenience methods for tracking standard Meta Pixel events
 * (PageView, ViewContent, Lead, Purchase, CompleteRegistration, custom).
 *
 * Resolved from the container as a singleton; receives the MetaPixelTracker
 * instance from the AnalyticsManager.
 *
 * @since 1.0.0
 */
final class MetaPixelService
{
    public function __construct(
        protected MetaPixelTracker $tracker,
    ){}

    /**
     * Track a PageView event.
     */
    public function trackPageView(): void
    {
        $this->tracker->track(new AnalyticsEvent(name: 'PageView'));
    }

    /**
     * Track a ViewContent event.
     *
     * @param  array<string, mixed>  $params
     */
    public function trackViewContent(array $params = []): void
    {
        $this->tracker->track(new AnalyticsEvent(
            name: 'ViewContent',
            params: $params,
        ));
    }

    /**
     * Track a Lead event.
     *
     * @param  array<string, mixed>  $params
     */
    public function trackLead(array $params = []): void
    {
        $this->tracker->track(new AnalyticsEvent(
            name: 'Lead',
            params: $params,
        ));
    }

    /**
     * Track a CompleteRegistration event.
     *
     * @param  array<string, mixed>  $params
     */
    public function trackCompleteRegistration(array $params = []): void
    {
        $this->tracker->track(new AnalyticsEvent(
            name: 'CompleteRegistration',
            params: $params,
        ));
    }

    /**
     * Track a Purchase event.
     *
     * @param  array<string, mixed>  $params
     */
    public function trackPurchase(array $params = []): void
    {
        $this->tracker->track(new AnalyticsEvent(
            name: 'Purchase',
            params: $params,
        ));
    }

    /**
     * Track a custom event.
     *
     * @param  array<string, mixed>  $params
     */
    public function trackCustom(string $eventName, array $params = []): void
    {
        $this->tracker->track(new AnalyticsEvent(
            name: $eventName,
            params: $params,
        ));
    }

    /**
     * Get the underlying tracker.
     */
    public function getTracker(): MetaPixelTracker
    {
        return $this->tracker;
    }
}
