<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Middleware;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Middleware that filters events based on consent state.
 *
 * When analytics consent is denied, all events are dropped.
 * When ad consent is denied, advertising-related events are dropped.
 */
final readonly class ConsentGateMiddleware implements AnalyticsMiddlewareInterface
{
    private bool $analyticsGranted;

    private bool $adGranted;

    /**
     * @param  bool  $analyticsGranted  Whether analytics_storage consent is granted
     * @param  bool  $adGranted  Whether ad_storage consent is granted
     */
    public function __construct(bool $analyticsGranted, bool $adGranted = true)
    {
        $this->analyticsGranted = $analyticsGranted;
        $this->adGranted = $adGranted;
    }

    public function process(AnalyticsEvent $event): ?AnalyticsEvent
    {
        // Block all events when analytics consent is denied
        if (! $this->analyticsGranted) {
            return null;
        }

        // Block advertising events when ad consent is denied
        if (! $this->adGranted && $this->isAdRelatedEvent($event->name)) {
            return null;
        }

        return $event;
    }

    public function priority(): int
    {
        return 5; // Very high priority — check consent first
    }

    public function name(): string
    {
        return 'consent_gate';
    }

    /**
     * Determine if an event is advertising-related.
     */
    private function isAdRelatedEvent(string $eventName): bool
    {
        $adEventPatterns = [
            'ad_', '_ad_', 'remarketing', 'retargeting',
        ];

        foreach ($adEventPatterns as $pattern) {
            if (str_contains($eventName, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
