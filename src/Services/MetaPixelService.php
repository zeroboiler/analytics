<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Trackers\MetaPixelTracker;

class MetaPixelService
{
    public function __construct(
        protected MetaPixelTracker $tracker,
    ) {}

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
