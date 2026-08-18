<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Trackers;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;

/**
 * Shared functionality for all analytics trackers.
 *
 * Provides consent rendering (used by GA4 and GTM trackers),
 * safe HTTP dispatch with error logging (used by GA4 and Meta Pixel trackers),
 * and a default batch implementation that falls back to sequential track() calls.
 *
 * @since 1.0.0
 */
trait TrackerHelpers
{
    protected ConsentState $consent;

    /**
     * Render the gtag consent default initialization snippet.
     *
     * @return string HTML script tag or empty string if no consent signals are set.
     */
    protected function renderConsentDefault(): string
    {
        if (empty($this->consent->signals)) {
            return '';
        }

        $json = json_encode($this->consent->signals, JSON_THROW_ON_ERROR);

        return "<script>\n  window.dataLayer = window.dataLayer || [];\n  function gtag(){dataLayer.push(arguments);}\n  gtag('consent', 'default', {$json});\n</script>\n";
    }

    /**
     * Check if analytics_storage consent is denied.
     *
     * All trackers should respect this flag before sending events.
     */
    protected function isAnalyticsDenied(): bool
    {
        return $this->consent->isDenied('analytics_storage');
    }

    /**
     * Default batch implementation — falls back to sequential track() calls.
     *
     * Trackers with native batch APIs (GA4, Meta, PostHog) should override
     * this method with a single HTTP request containing all events.
     *
     * @param  list<AnalyticsEvent>  $events  Events to dispatch
     * @return int  Number of events dispatched
     *
     * @since 243.0.0
     */
    protected function defaultTrackBatch(array $events): int
    {
        if (empty($events) || !$this->isEnabled()) {
            return 0;
        }

        $count = 0;

        foreach ($events as $event) {
            $this->track($event);
            $count++;
        }

        return $count;
    }

    abstract public function isEnabled(): bool;
}
