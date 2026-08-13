<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Enrichment;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Contract for analytics event enrichment plugins.
 *
 * Enrichment plugins can add, transform, or remove event parameters
 * before events are dispatched to analytics providers. They participate
 * in the event pipeline and run in priority order.
 *
 * Implement this interface in your own packages to hook into the
 * ZeroBoiler enrichment pipeline. Register via EventEnrichmentRegistry.
 *
 * Example implementation:
 * ```php
 * final class GeoEnrichmentPlugin implements EventEnrichmentPlugin
 * {
 *     public function name(): string { return 'geo_enrichment'; }
 *     public function priority(): int { return 100; }
 *     public function shouldEnrich(AnalyticsEvent $event): bool { return true; }
 *     public function enrich(AnalyticsEvent $event): AnalyticsEvent
 *     {
 *         // Add geo data to event params...
 *         return $event;
 *     }
 * }
 * ```
 *
 * @since 57.0.0
 */
interface EventEnrichmentPlugin
{
    /**
     * Unique plugin identifier.
     *
     * Must be unique across all registered enrichment plugins.
     * Used as the config key for enable/disable toggling.
     */
    public function name(): string;

    /**
     * Plugin execution priority.
     *
     * Higher values run first. Default recommended: 0.
     * Built-in enrichers use priority range 0-1000.
     */
    public function priority(): int;

    /**
     * Determine whether this plugin should process the given event.
     *
     * Called before enrich() to short-circuit processing for irrelevant events.
     * Return false to skip enrichment for this event.
     *
     * @param  AnalyticsEvent  $event  The event about to be processed
     */
    public function shouldEnrich(AnalyticsEvent $event): bool;

    /**
     * Enrich or transform the analytics event.
     *
     * Must return the (possibly modified) event. To drop the event
     * entirely, return null. Return the same instance if unmodified,
     * or create a new AnalyticsEvent with updated params.
     *
     * @param  AnalyticsEvent  $event  The event to enrich
     * @return AnalyticsEvent|null The enriched event, or null to drop it
     */
    public function enrich(AnalyticsEvent $event): ?AnalyticsEvent;
}
