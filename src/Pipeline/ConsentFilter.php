<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Filters events based on consent state.
 *
 * Drops events when analytics_storage consent is denied.
 * Returns null to signal the pipeline to abort the event.
 */
final readonly class ConsentFilter
{
    private bool $analyticsGranted;

    public function __construct(bool $analyticsGranted = true)
    {
        $this->analyticsGranted = $analyticsGranted;
    }

    /**
     * Filter the event based on consent state.
     *
     * @return AnalyticsEvent|null Returns null if consent is denied
     */
    public function __invoke(AnalyticsEvent $event): ?AnalyticsEvent
    {
        if (! $this->analyticsGranted) {
            return null; // Drop the event
        }

        return $event;
    }
}
