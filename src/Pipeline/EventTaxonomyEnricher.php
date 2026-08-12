<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Pipeline middleware that automatically tags events with semantic taxonomy labels.
 *
 * Enriches events with metadata fields:
 * - `zb_category`: semantic category (conversion, intent, engagement, navigation, transaction, identity, error, search)
 * - `zb_catalog_match`: whether the event exists in the official catalog
 * - `zb_provider_count`: number of providers that support this event
 *
 * Enables downstream services (funnel analytics, quality scoring, reporting)
 * to filter and group events by semantic category without manual tagging.
 *
 * @since 25.0.0
 */
final class EventTaxonomyEnricher
{
    /**
     * Enrich an event with auto-classified taxonomy tags.
     *
     * @return AnalyticsEvent The enriched event with taxonomy metadata
     */
    public function __invoke(AnalyticsEvent $event): AnalyticsEvent
    {
        $eventName = strtolower($event->name);
        $category = $this->classify($eventName, $event->params);
        $catalogEntry = EventCatalog::get($eventName);
        $catalogMatch = $catalogEntry !== null;

        $providerCount = 0;
        if ($catalogMatch) {
            foreach (['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude'] as $provider) {
                if (! empty($catalogEntry[$provider])) {
                    $providerCount++;
                }
            }
        }

        $enrichedParams = array_merge($event->params, [
            'zb_taxonomy_category' => $category,
            'zb_catalog_match' => $catalogMatch,
            'zb_provider_count' => $providerCount,
        ]);

        return new AnalyticsEvent(
            name: $event->name,
            params: $enrichedParams,
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
        );
    }

    /**
     * Classify an event name into a semantic category.
     *
     * Uses pattern matching on event names and parameter indicators
     * to auto-classify events without requiring manual tagging.
     *
     * @param  array<string, mixed>  $params
     * @return 'conversion'|'intent'|'engagement'|'navigation'|'transaction'|'identity'|'error'|'search'
     */
    public function classify(string $eventName, array $params = []): string
    {
        // Transaction events (revenue-bearing)
        if (str_contains($eventName, 'purchase') ||
            str_contains($eventName, 'refund') ||
            str_contains($eventName, 'payment') ||
            str_contains($eventName, 'checkout') ||
            str_contains($eventName, 'cart') ||
            str_contains($eventName, 'order') ||
            str_contains($eventName, 'revenue') ||
            str_contains($eventName, 'mrr') ||
            str_contains($eventName, 'invoice') ||
            str_contains($eventName, 'billing')) {
            return 'transaction';
        }

        // Identity events
        if (str_contains($eventName, 'sign_up') ||
            str_contains($eventName, 'signup') ||
            str_contains($eventName, 'register') ||
            str_contains($eventName, 'login') ||
            str_contains($eventName, 'logout') ||
            str_contains($eventName, 'identify') ||
            str_contains($eventName, 'password') ||
            str_contains($eventName, 'email_verified') ||
            str_contains($eventName, 'account')) {
            return 'identity';
        }

        // Conversion events
        if (str_contains($eventName, 'conversion') ||
            str_contains($eventName, 'trial_start') ||
            str_contains($eventName, 'trial_convert') ||
            str_contains($eventName, 'subscription') ||
            str_contains($eventName, 'plan_upgrade') ||
            str_contains($eventName, 'plan_change') ||
            str_contains($eventName, 'activation') ||
            str_contains($eventName, 'first_value') ||
            str_contains($eventName, 'milestone')) {
            return 'conversion';
        }

        // Error events
        if (str_contains($eventName, 'error') ||
            str_contains($eventName, 'exception') ||
            str_contains($eventName, 'crash') ||
            str_contains($eventName, 'js_error') ||
            str_contains($eventName, 'rate_limit') ||
            str_contains($eventName, 'sla_breach') ||
            str_contains($eventName, 'service_down')) {
            return 'error';
        }

        // Search events
        if (str_contains($eventName, 'search') ||
            str_contains($eventName, 'query') ||
            str_contains($eventName, 'filter')) {
            return 'search';
        }

        // Navigation events
        if (str_contains($eventName, 'page_view') ||
            str_contains($eventName, 'screen_view') ||
            str_contains($eventName, 'navigate') ||
            str_contains($eventName, 'outbound') ||
            str_contains($eventName, 'route')) {
            return 'navigation';
        }

        // Intent events
        if (str_contains($eventName, 'click') ||
            str_contains($eventName, 'view_item') ||
            str_contains($eventName, 'view_promo') ||
            str_contains($eventName, 'select') ||
            str_contains($eventName, 'wishlist') ||
            str_contains($eventName, 'add_to') ||
            str_contains($eventName, 'impression') ||
            str_contains($eventName, 'feature_used') ||
            str_contains($eventName, 'feature_adopted')) {
            return 'intent';
        }

        // Default to engagement for all other events
        return 'engagement';
    }

    /**
     * Get all supported taxonomy categories.
     *
     * @return list<string>
     */
    public static function categories(): array
    {
        return [
            'conversion',
            'intent',
            'engagement',
            'navigation',
            'transaction',
            'identity',
            'error',
            'search',
        ];
    }
}
