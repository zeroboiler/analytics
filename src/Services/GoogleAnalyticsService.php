<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Trackers\GA4Tracker;

/**
 * High-level service wrapper for Google Analytics 4.
 *
 * Provides convenience methods for common GA4 tracking scenarios
 * (page views, purchases, signups, custom events).
 *
 * Resolved from the container as a singleton; receives the GA4Tracker
 * instance from the AnalyticsManager.
 */
class GoogleAnalyticsService
{
    public function __construct(
        protected GA4Tracker $tracker,
    ) {}

    /**
     * Track a page view.
     */
    public function trackPageView(string $url, ?string $title = null, ?string $clientId = null): void
    {
        $event = new AnalyticsEvent(
            name: 'page_view',
            params: array_filter([
                'page_location' => $url,
                'page_title' => $title,
            ]),
            clientId: $clientId,
        );

        $this->tracker->track($event);
    }

    /**
     * Track a purchase event.
     *
     * @param  array<string, mixed>  $transactionData
     */
    public function trackPurchase(array $transactionData, ?string $clientId = null): void
    {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: $transactionData,
            clientId: $clientId,
        );

        $this->tracker->track($event);
    }

    /**
     * Track a signup event.
     */
    public function trackSignup(string $method = 'email', ?string $clientId = null): void
    {
        $event = new AnalyticsEvent(
            name: 'sign_up',
            params: ['method' => $method],
            clientId: $clientId,
        );

        $this->tracker->track($event);
    }

    /**
     * Track a custom event.
     *
     * @param  array<string, mixed>  $params
     */
    public function trackCustom(string $eventName, array $params = [], ?string $clientId = null): void
    {
        $event = new AnalyticsEvent(
            name: $eventName,
            params: $params,
            clientId: $clientId,
        );

        $this->tracker->track($event);
    }

    /**
     * Get the underlying tracker.
     */
    public function getTracker(): GA4Tracker
    {
        return $this->tracker;
    }
}
