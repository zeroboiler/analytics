<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Automatic event category and tag enrichment pipeline stage.
 *
 * Automatically enriches events with metadata from the EventCatalog:
 * - `_category`: event category (ecommerce, saas, engagement)
 * - `_provider_map`: GA4, Meta, PostHog, Plausible mappings
 * - `_event_class`: fully-qualified analytics event class name
 * - `_priority`: event priority (critical for revenue, normal for most, low for noise)
 *
 * Uses the `_zb_` prefix for all injected metadata to prevent conflicts
 * with user-specified event parameters.
 *
 * @since 8.0.0
 */
final class EventClassificationEnricher
{
    /** Events considered critical priority. */
    private const CRITICAL_EVENTS = [
        'purchase', 'subscribe', 'sign_up', 'trial_converted', 'payment_succeeded',
    ];

    /** Events considered low priority. */
    private const LOW_PRIORITY_EVENTS = [
        'page_view', 'scroll_depth', 'screen_view', 'session_start', 'session_end',
        'timing', 'web_vitals',
    ];

    /**
     * Enrich an event with classification metadata from the EventCatalog.
     *
     * @param  AnalyticsEvent  $event  The event to enrich
     * @return AnalyticsEvent  The enriched event (new instance)
     */
    public function enrich(AnalyticsEvent $event): AnalyticsEvent
    {
        $eventName = $event->name();
        $catalogEntry = EventCatalog::get($eventName);

        if ($catalogEntry === null) {
            // Unknown event — add minimal metadata
            return $event->withParam('_zb_category', 'custom')
                ->withParam('_zb_known', false)
                ->withParam('_zb_priority', $this->inferPriority($eventName));
        }

        $enriched = $event;

        // Category
        $enriched = $enriched->withParam('_zb_category', $catalogEntry['category']);

        // Known event flag
        $enriched = $enriched->withParam('_zb_known', true);

        // Provider mappings
        $providerMap = [];
        $providerMap['ga4'] = $catalogEntry['ga4'] ?? $eventName;
        $providerMap['meta'] = $catalogEntry['meta'] ?? null;
        $providerMap['posthog'] = $catalogEntry['posthog'] ?? $eventName;
        $providerMap['plausible'] = $catalogEntry['plausible'] ?? null;
        $enriched = $enriched->withParam('_zb_provider_map', $providerMap);

        // Event class
        $enriched = $enriched->withParam('_zb_event_class', $catalogEntry['class']);

        // Priority
        $priority = $this->getEventPriority($eventName);
        $enriched = $enriched->withParam('_zb_priority', $priority);

        return $enriched;
    }

    /**
     * Get the priority level for a known event.
     *
     * @return 'critical'|'high'|'normal'|'low'|'background'
     */
    public function getEventPriority(string $eventName): string
    {
        if (in_array($eventName, self::CRITICAL_EVENTS, true)) {
            return 'critical';
        }

        if (in_array($eventName, self::LOW_PRIORITY_EVENTS, true)) {
            return 'low';
        }

        // Revenue events get high priority
        $catalogEntry = EventCatalog::get($eventName);
        if ($catalogEntry !== null && $catalogEntry['category'] === 'ecommerce') {
            return 'high';
        }

        // SaaS conversion events get high priority
        $saasHighPriority = [
            'plan_upgrade', 'plan_downgrade', 'cancellation',
            'subscription_renewal', 'trial_start', 'trial_converted',
        ];
        if (in_array($eventName, $saasHighPriority, true)) {
            return 'high';
        }

        return 'normal';
    }

    /**
     * Infer priority for unknown (custom) events.
     *
     * Uses heuristics based on event name patterns.
     *
     * @return 'critical'|'high'|'normal'|'low'|'background'
     */
    private function inferPriority(string $eventName): string
    {
        $lower = strtolower($eventName);

        // Revenue patterns
        $revenuePatterns = ['purchase', 'payment', 'revenue', 'subscription', 'checkout'];
        foreach ($revenuePatterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return 'high';
            }
        }

        // High-value patterns
        $highPatterns = ['signup', 'register', 'trial', 'upgrade', 'convert', 'activation'];
        foreach ($highPatterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return 'high';
            }
        }

        // Low-value patterns
        $lowPatterns = ['scroll', 'hover', 'impression', 'view', 'timer', 'heartbeat', 'ping'];
        foreach ($lowPatterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return 'low';
            }
        }

        return 'normal';
    }

    /**
     * Batch enrich multiple events.
     *
     * @param  list<AnalyticsEvent>  $events
     * @return list<AnalyticsEvent>
     */
    public function enrichBatch(array $events): array
    {
        return array_map(fn (AnalyticsEvent $event): AnalyticsEvent => $this->enrich($event), $events);
    }
}
