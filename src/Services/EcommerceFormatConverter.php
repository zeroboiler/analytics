<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Converts e-commerce analytics events between GA4 and Meta Pixel formats.
 *
 * Provides industry-standard format transformation for multi-provider
 * e-commerce tracking. When events are dispatched to both GA4 and Meta,
 * the payload structures differ significantly:
 *
 * GA4 uses `items[]` array with item-level parameters.
 * Meta uses `content_ids[]`, `contents[]`, `value`, and `currency`.
 *
 * This converter handles the mapping bidirectionally and provides
 * convenience methods for the most common e-commerce event types.
 *
 * @since 255.0.0
 */
final class EcommerceFormatConverter
{
    /**
     * Convert an AnalyticsEvent to GA4 e-commerce format.
     *
     * GA4 expects:
     * ```json
     * {
     *   "currency": "USD",
     *   "value": 99.99,
     *   "transaction_id": "TX-123",
     *   "items": [{ "item_id": "SKU-1", "item_name": "Widget", "price": 49.99, "quantity": 2 }]
     * }
     * ```
     *
     * @return array{event: string, params: array<string, mixed>}
     */
    public function toGa4(AnalyticsEvent $event): array
    {
        $params = $event->params;
        $ga4EventName = $this->resolveGa4Name($event);

        // Build GA4 items array from flat or nested params
        $ga4Params = $this->buildGa4Params($params);

        return [
            'event' => $ga4EventName,
            'params' => $ga4Params,
        ];
    }

    /**
     * Convert an AnalyticsEvent to Meta Pixel (CAPI) format.
     *
     * Meta expects:
     * ```json
     * {
     *   "event_id": "evt_123",
     *   "event_name": "Purchase",
     *   "event_time": 1690000000,
     *   "user_data": { "client_user_agent": "...", "fbp": "..." },
     *   "custom_data": {
     *     "currency": "USD",
     *     "value": 99.99,
     *     "content_ids": ["SKU-1"],
     *     "contents": [{ "id": "SKU-1", "quantity": 2, "item_price": 49.99 }],
     *     "content_type": "product",
     *     "num_items": 2
     *   },
     *   "action_source": "website"
     * }
     * ```
     *
     * @param  array{fbp?: string, fbc?: string, client_user_agent?: string, external_id?: string}  $userData  Optional Meta user data
     * @return array{event_name: string, event_id: string|null, event_time: int, custom_data: array<string, mixed>, user_data: array<string, string|null>, action_source: string}
     */
    public function toMeta(AnalyticsEvent $event, array $userData = []): array
    {
        $params = $event->params;
        $metaEventName = $this->resolveMetaName($event);

        return [
            'event_name' => $metaEventName,
            'event_id' => $event->id ?? $this->generateEventId(),
            'event_time' => $event->timestamp?->getTimestamp() ?? time(),
            'custom_data' => $this->buildMetaCustomData($params),
            'user_data' => $this->buildMetaUserData($userData),
            'action_source' => 'website',
        ];
    }

    /**
     * Convert an AnalyticsEvent to both GA4 and Meta formats.
     *
     * Returns both payloads in a single call for efficient multi-provider dispatch.
     *
     * @param  array<string, string|null>  $metaUserData  Optional Meta user data
     * @return array{ga4: array{event: string, params: array<string, mixed>}, meta: array{event_name: string, event_id: string|null, event_time: int, custom_data: array<string, mixed>, user_data: array<string, string|null>, action_source: string}}
     */
    public function toBoth(AnalyticsEvent $event, array $metaUserData = []): array
    {
        return [
            'ga4' => $this->toGa4($event),
            'meta' => $this->toMeta($event, $metaUserData),
        ];
    }

    /**
     * Build a GA4 items array from event params.
     *
     * Handles both flat single-item params and pre-structured `items` arrays.
     *
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    public function buildGa4Items(array $params): array
    {
        // If items array already provided, normalize each item
        if (isset($params['items']) && is_array($params['items'])) {
            $items = [];
            foreach ($params['items'] as $item) {
                if (is_array($item)) {
                    $items[] = $this->normalizeGa4Item($item);
                }
            }

            return $items;
        }

        // Build single-item array from flat params
        if (isset($params['item_id'])) {
            return [$this->normalizeGa4Item($params)];
        }

        return [];
    }

    /**
     * Convert a GA4 purchase event to Meta's Server-Side API format.
     *
     * This is a specialized converter for the most critical conversion event.
     *
     * @param  array{transaction_id: string, value: float, currency?: string, items?: list<array<string, mixed>>, item_id?: string, price?: float, quantity?: int, item_name?: string}  $ga4Params
     * @param  array{fbp?: string, fbc?: string, client_user_agent?: string, external_id?: string, em?: string, fn?: string, ln?: string, ph?: string, ct?: string, zp?: string, country?: string, st?: string}  $userData
     * @return array{event_name: string, event_id: string, event_time: int, custom_data: array<string, mixed>, user_data: array<string, string|null>, action_source: string}
     */
    public static function purchaseGa4ToMeta(array $ga4Params, array $userData = []): array
    {
        $items = $ga4Params['items'] ?? [];
        if (isset($ga4Params['item_id']) && $items === []) {
            $items = [[
                'item_id' => $ga4Params['item_id'],
                'item_name' => $ga4Params['item_name'] ?? '',
                'price' => $ga4Params['price'] ?? $ga4Params['value'],
                'quantity' => $ga4Params['quantity'] ?? 1,
            ]];
        }

        $contentIds = [];
        $contents = [];
        $numItems = 0;

        foreach ($items as $item) {
            $id = (string) ($item['item_id'] ?? '');
            $quantity = (int) ($item['quantity'] ?? 1);
            $price = (float) ($item['price'] ?? 0);

            if ($id !== '') {
                $contentIds[] = $id;
            }

            $contents[] = [
                'id' => $id,
                'quantity' => $quantity,
                'item_price' => $price,
            ];
            $numItems += $quantity;
        }

        $customData = [
            'currency' => (string) ($ga4Params['currency'] ?? 'USD'),
            'value' => (float) ($ga4Params['value'] ?? 0),
        ];

        if ($contentIds !== []) {
            $customData['content_ids'] = $contentIds;
        }

        if ($contents !== []) {
            $customData['contents'] = $contents;
            $customData['content_type'] = 'product';
            $customData['num_items'] = $numItems;
        }

        return [
            'event_name' => 'Purchase',
            'event_id' => 'meta_' . ($ga4Params['transaction_id'] ?? uniqid('evt_', true)),
            'event_time' => time(),
            'custom_data' => $customData,
            'user_data' => array_filter(
                array_merge([
                    'client_user_agent' => null,
                    'fbp' => null,
                    'fbc' => null,
                    'external_id' => null,
                ], $userData),
                fn (mixed $v): bool => $v !== null,
            ),
            'action_source' => 'website',
        ];
    }

    /**
     * Convert a GA4 items array to Meta contents array.
     *
     * @param  list<array{item_id?: string, quantity?: int, price?: float, item_name?: string}>  $ga4Items
     * @return array{content_ids: list<string>, contents: list<array{id: string, quantity: int, item_price: float}>, content_type: string, num_items: int}
     */
    public static function itemsGa4ToMeta(array $ga4Items): array
    {
        $contentIds = [];
        $contents = [];
        $numItems = 0;

        foreach ($ga4Items as $item) {
            $id = (string) ($item['item_id'] ?? '');
            $quantity = (int) ($item['quantity'] ?? 1);
            $price = (float) ($item['price'] ?? 0);

            if ($id !== '') {
                $contentIds[] = $id;
            }

            $contents[] = [
                'id' => $id,
                'quantity' => $quantity,
                'item_price' => $price,
            ];
            $numItems += $quantity;
        }

        $result = [
            'content_type' => 'product',
            'num_items' => $numItems,
        ];

        if ($contentIds !== []) {
            $result['content_ids'] = $contentIds;
        }

        if ($contents !== []) {
            $result['contents'] = $contents;
        }

        return $result;
    }

    // ── Internal Helpers ────────────────────────────────────────────

    /**
     * Resolve the GA4 event name for an analytics event.
     */
    private function resolveGa4Name(AnalyticsEvent $event): string
    {
        // Check event catalog for provider-specific name
        $catalog = \ZeroBoiler\Analytics\Events\EventCatalog::get($event->name);

        if ($catalog !== null && isset($catalog['ga4'])) {
            return (string) $catalog['ga4'];
        }

        return $event->name;
    }

    /**
     * Resolve the Meta Pixel event name for an analytics event.
     */
    private function resolveMetaName(AnalyticsEvent $event): string
    {
        $catalog = \ZeroBoiler\Analytics\Events\EventCatalog::get($event->name);

        if ($catalog !== null && isset($catalog['meta']) && $catalog['meta'] !== null) {
            return (string) $catalog['meta'];
        }

        return $event->name;
    }

    /**
     * Build GA4-formatted params from generic event params.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function buildGa4Params(array $params): array
    {
        $ga4Params = [];

        // Copy known GA4 e-commerce fields
        $ga4Fields = [
            'currency', 'value', 'transaction_id', 'tax', 'shipping',
            'coupon', 'payment_type', 'shipping_tier', 'affiliation',
            'checkout_step', 'checkout_option',
        ];

        foreach ($ga4Fields as $field) {
            if (array_key_exists($field, $params)) {
                $ga4Params[$field] = $params[$field];
            }
        }

        // Build items array
        $items = $this->buildGa4Items($params);
        if ($items !== []) {
            $ga4Params['items'] = $items;
        }

        return $ga4Params;
    }

    /**
     * Build Meta custom_data from generic event params.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function buildMetaCustomData(array $params): array
    {
        $customData = [];

        if (isset($params['currency'])) {
            $customData['currency'] = (string) $params['currency'];
        }

        if (isset($params['value'])) {
            $customData['value'] = (float) $params['value'];
        }

        // Map items to Meta format
        $items = $params['items'] ?? [];
        if ($items === [] && isset($params['item_id'])) {
            $items = [[
                'item_id' => $params['item_id'],
                'item_name' => $params['item_name'] ?? '',
                'price' => $params['price'] ?? 0,
                'quantity' => $params['quantity'] ?? 1,
            ]];
        }

        if ($items !== []) {
            $metaItems = self::itemsGa4ToMeta($items);
            $customData = array_merge($customData, $metaItems);
        }

        // Map transaction_id
        if (isset($params['transaction_id'])) {
            $customData['order_id'] = (string) $params['transaction_id'];
        }

        return $customData;
    }

    /**
     * Build Meta user_data array.
     *
     * @param  array<string, string|null>  $userData
     * @return array<string, string|null>
     */
    private function buildMetaUserData(array $userData): array
    {
        return array_filter(array_merge([
            'client_user_agent' => null,
            'fbp' => null,
            'fbc' => null,
            'external_id' => null,
        ], $userData), fn (mixed $v): bool => $v !== null);
    }

    /**
     * Normalize a single item to GA4 format.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeGa4Item(array $item): array
    {
        $ga4Item = [];

        $itemFields = [
            'item_id', 'item_name', 'affiliation', 'coupon',
            'discount', 'index', 'item_brand', 'item_category',
            'item_category2', 'item_category3', 'item_category4',
            'item_list_id', 'item_list_name', 'item_variant',
            'location_id', 'price', 'quantity',
        ];

        foreach ($itemFields as $field) {
            if (array_key_exists($field, $item)) {
                $ga4Item[$field] = $item[$field];
            }
        }

        return $ga4Item;
    }

    /**
     * Generate a unique event ID for Meta deduplication.
     */
    private function generateEventId(): string
    {
        return 'meta_' . bin2hex(random_bytes(12));
    }
}
