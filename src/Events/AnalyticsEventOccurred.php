<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Laravel event dispatched after an analytics event is tracked.
 *
 * Enables other packages and application code to react to
 * analytics events without modifying the core analytics logic.
 * Use Laravel's event listener system to hook into any event.
 *
 * @version 5.0.0
 *
 * @example
 * // Listen to all analytics events
 * Event::listen(AnalyticsEventOccurred::class, function (AnalyticsEventOccurred $event) {
 *     if ($event->analyticsEvent->name === 'purchase') {
 *         // Trigger fulfillment, send email, update CRM...
 *     }
 * });
 *
 * @since 1.0.0
 */
final class AnalyticsEventOccurred
{
    /**
     * Create a new analytics event occurrence.
     *
     * @param  AnalyticsEvent  $analyticsEvent  The tracked analytics event
     * @param  array{ga4: bool, gtm: bool, meta: bool, plausible: bool, posthog: bool, webhook: bool}  $dispatchedTo  Which providers received the event
     * @param  array<string, mixed>  $context  Additional context (request ID, tenant, etc.)
     */
    public function __construct(
        public readonly AnalyticsEvent $analyticsEvent,
        public readonly array $dispatchedTo = [],
        public readonly array $context = [],
    ): void {}
}
