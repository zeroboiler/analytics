<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Trackers;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;

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
}
