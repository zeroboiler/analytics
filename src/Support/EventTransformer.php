<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event transformer for cross-provider format conversion.
 *
 * Centralizes all event name and parameter transformations between
 * GA4, Meta Pixel, PostHog, Plausible, and generic formats.
 * Provides both individual transforms and bulk conversion helpers.
 */
final class EventTransformer
{
    // ── GA4 ↔ Meta Pixel Event Name Mapping ────────────────────────────

    /**
     * Map GA4 e-commerce event names to Meta Pixel equivalents.
     *
     * @return array<string, string|null>
     */
    private static function ga4ToMetaEventMap(): array
    {
        return [
            'view_item' => 'ViewContent',
            'add_to_cart' => 'AddToCart',
            'remove_from_cart' => null,
            'view_cart' => null,
            'begin_checkout' => 'InitiateCheckout',
            'add_payment_info' => 'AddPaymentInfo',
            'purchase' => 'Purchase',
            'refund' => null,
            'add_to_wishlist' => 'AddToWishlist',
            'select_item' => null,
            'select_promotion' => null,
            'view_promotion' => null,
        ];
    }

    /**
     * Get the Meta Pixel event name for a GA4 event.
     */
    public static function ga4ToMetaEventName(string $ga4Event): ?string
    {
        return self::ga4ToMetaEventMap()[$ga4Event] ?? null;
    }

    /**
     * Check if a GA4 event has a Meta Pixel equivalent.
     */
    public static function hasMetaEquivalent(string $ga4Event): bool
    {
        return self::ga4ToMetaEventName($ga4Event) !== null;
    }

    // ── GA4 ↔ Meta Pixel Parameter Conversion ───────────────────────────

    /**
     * Convert GA4 items array to Meta Pixel contents format.
     *
     * @param  array<int, array<string, mixed>>  $items  GA4-format items
     * @return array{content_ids: list<string>, contents: array<int, array<string, mixed>>, num_items: int}
     */
    public static function ga4ItemsToMetaContents(array $items): array
    {
        $contentIds = [];
        $contents = [];

        foreach ($items as $item) {
            $contentIds[] = (string) ($item['item_id'] ?? '');
            $contents[] = [
                'id' => (string) ($item['item_id'] ?? ''),
                'quantity' => (int) ($item['quantity'] ?? 1),
                'item_price' => (float) ($item['price'] ?? 0),
                'name' => (string) ($item['item_name'] ?? ''),
                'category' => (string) ($item['item_category'] ?? ''),
            ];
        }

        return [
            'content_ids' => $contentIds,
            'contents' => $contents,
            'num_items' => array_sum(array_column($contents, 'quantity')),
        ];
    }

    /**
     * Convert GA4 event data to Meta Pixel parameters.
     *
     * @param  string  $ga4Event  GA4 event name
     * @param  array<string, mixed>  $data  GA4 event parameters
     * @return array<string, mixed>  Meta Pixel formatted parameters
     */
    public static function ga4ToMetaParams(string $ga4Event, array $data): array
    {
        $params = [
            'value' => $data['value'] ?? null,
            'currency' => $data['currency'] ?? 'USD',
        ];

        if (isset($data['items']) && is_array($data['items'])) {
            $metaItems = self::ga4ItemsToMetaContents($data['items']);
            $params['contents'] = $metaItems['contents'];
            $params['content_ids'] = $metaItems['content_ids'];
            $params['num_items'] = $metaItems['num_items'];
        }

        if ($ga4Event === 'purchase' && isset($data['transaction_id'])) {
            $params['content_ids'] ??= array_column($data['items'] ?? [], 'item_id');
        }

        if (isset($data['content_name'])) {
            $params['content_name'] = $data['content_name'];
        }

        if (isset($data['content_type'])) {
            $params['content_type'] = $data['content_type'];
        }

        return array_filter($params, fn (mixed $v): bool => $v !== null);
    }

    // ── SaaS Event → Provider Format Conversion ─────────────────────────

    /**
     * Map SaaS event names to PostHog event names.
     *
     * PostHog uses $prefix for special events.
     *
     * @return array<string, string>
     */
    public static function saasToPosthogEventMap(): array
    {
        return [
            'sign_up' => '$signup',
            'login' => '$identify',
            'logout' => 'logout',
            'start_trial' => 'start_trial',
            'cancellation' => 'cancellation',
        ];
    }

    // ── Bulk Transform ───────────────────────────────────────────────────

    /**
     * Map event names to Plausible-compatible custom event names.
     *
     * Plausible uses pageview by default for navigation. Custom events
     * are sent as-is but page_view events are mapped to 'pageview'.
     *
     * @return array<string, string|null>
     */
    public static function toPlausibleEventMap(): array
    {
        return [
            'page_view' => 'pageview',
            'scroll_depth' => null,  // Not supported by Plausible
            'click' => null,        // Not supported by Plausible
            'session_start' => null,
            'session_end' => null,
            'form_start' => null,
            'form_submit' => null,
        ];
    }

    /**
     * Transform an event name for Plausible.
     *
     * @return string|null Transformed event name or null if not applicable for Plausible
     */
    public static function toPlausibleEventName(string $eventName): ?string
    {
        return self::toPlausibleEventMap()[$eventName] ?? $eventName;
    }

    /**
     * Transform a single event for a specific provider.
     *
     * @param  AnalyticsEvent  $event  Original event
     * @param  'ga4'|'meta'|'posthog'  $provider  Target provider
     * @return AnalyticsEvent  Transformed event
     */
    public static function transformForProvider(AnalyticsEvent $event, string $provider): AnalyticsEvent
    {
        $name = $event->name;
        $params = $event->params;

        return match ($provider) {
            'meta' => self::transformForMeta($name, $params, $event),
            'posthog' => self::transformForPosthog($name, $params, $event),
            'plausible' => self::transformForPlausible($name, $params, $event),
            default => $event, // ga4, webhook use original format
        };
    }

    /**
     * Transform an event for Meta Pixel.
     *
     * @param  string  $name  Original event name
     * @param  array<string, mixed>  $params  Original params
     * @param  AnalyticsEvent  $event  Original event (for clientId, userId)
     * @return AnalyticsEvent  Transformed event
     */
    private static function transformForMeta(
        string $name,
        array $params,
        AnalyticsEvent $event,
    ): AnalyticsEvent {
        // E-commerce events: convert items format
        if (self::hasMetaEquivalent($name)) {
            $metaEventName = self::ga4ToMetaEventName($name);

            return new AnalyticsEvent(
                name: $metaEventName ?? $name,
                params: self::ga4ToMetaParams($name, $params),
                clientId: $event->clientId,
                userId: $event->userId,
            );
        }

        // Non-ecommerce events: pass through unchanged
        return $event;
    }

    /**
     * Transform an event for PostHog.
     *
     * @param  string  $name  Original event name
     * @param  array<string, mixed>  $params  Original params
     * @param  AnalyticsEvent  $event  Original event
     * @return AnalyticsEvent  Transformed event
     */
    private static function transformForPosthog(
        string $name,
        array $params,
        AnalyticsEvent $event,
    ): AnalyticsEvent {
        $posthogMap = self::saasToPosthogEventMap();

        if (isset($posthogMap[$name])) {
            return new AnalyticsEvent(
                name: $posthogMap[$name],
                params: $params,
                clientId: $event->clientId,
                userId: $event->userId,
            );
        }

        return $event;
    }

    /**
     * Transform an event for Plausible.
     *
     * Plausible only supports pageview and custom events.
     * Unsupported events (scroll, click, session, form) return null to skip dispatch.
     *
     * @param  string  $name  Original event name
     * @param  array<string, mixed>  $params  Original params
     * @param  AnalyticsEvent  $event  Original event
     * @return AnalyticsEvent  Transformed event
     */
    private static function transformForPlausible(
        string $name,
        array $params,
        AnalyticsEvent $event,
    ): AnalyticsEvent {
        $plausibleName = self::toPlausibleEventName($name);

        if ($plausibleName === null) {
            // Event not supported by Plausible — return original (tracker will handle)
            return $event;
        }

        if ($plausibleName !== $name) {
            return new AnalyticsEvent(
                name: $plausibleName,
                params: $params,
                clientId: $event->clientId,
                userId: $event->userId,
            );
        }

        return $event;
    }
}
