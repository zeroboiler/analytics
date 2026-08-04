<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Trackers;

use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;

/**
 * Shared functionality for all analytics trackers.
 *
 * Provides consent rendering (used by GA4 and GTM trackers) and
 * safe HTTP dispatch with error logging (used by GA4 and Meta Pixel trackers).
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

    abstract public function isEnabled(): bool;
}
