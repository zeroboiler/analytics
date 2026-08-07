<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * E-commerce format converter for cross-provider data transformation.
 *
 * Provides bidirectional conversion between GA4, Meta Pixel, and generic
 * e-commerce event formats. Handles items/contents arrays, revenue fields,
 * shipping/tax/coupon parameters, and purchase/refund specific structures.
 *
 * Unlike EventTransformer (which focuses on event names), this service
 * focuses on the detailed parameter structure differences between providers.
 */
final class EcommerceFormatConverter
{
    // ── GA4 → Meta Pixel Items Conversion ────────────────────────────

    /**
     * Convert GA4 items array to Meta Pixel contents format.
     *
     * GA4 format: [{item_id, item_name, price, quantity, ...}]
     * Meta format: [{id, quantity, item_price, item_name}]
     *
     * @param  array<int, array<string, mixed>>  $items  GA4-format items
     * @return array{content_ids: list<string>, contents: array<int, array<string, mixed>>, num_items: int, value: float}
     */
    public static function ga4ToMetaContents(array $items): array
    {
        $contentIds = [];
        $contents = [];
        $totalValue = 0.0;

        foreach ($items as $item) {
            $price = (float) ($item['price'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 1);

            $contentIds[] = (string) ($item['item_id'] ?? '');
            $contents[] = [
                'id' => (string) ($item['item_id'] ?? ''),
                'quantity' => $quantity,
                'item_price' => $price,
                'item_name' => (string) ($item['item_name'] ?? ''),
                'category' => (string) ($item['item_category'] ?? ''),
            ];

            $totalValue += $price * $quantity;
        }

        return [
            'content_ids' => $contentIds,
            'contents' => $contents,
            'num_items' => count($contents),
            'value' => $totalValue,
        ];
    }

    /**
     * Convert Meta Pixel contents array to GA4 items format (reverse conversion).
     *
     * Meta format: [{id, quantity, item_price, item_name}]
     * GA4 format: [{item_id, item_name, price, quantity, ...}]
     *
     * @param  array<int, array<string, mixed>>  $contents  Meta-format contents
     * @return array<int, array<string, mixed>>
     */
    public static function metaToGa4Items(array $contents): array
    {
        $items = [];

        foreach ($contents as $content) {
            $items[] = [
                'item_id' => (string) ($content['id'] ?? ''),
                'item_name' => (string) ($content['item_name'] ?? ''),
                'price' => (float) ($content['item_price'] ?? 0),
                'quantity' => (int) ($content['quantity'] ?? 1),
                'item_category' => (string) ($content['category'] ?? ''),
            ];
        }

        return $items;
    }

    // ── GA4 Purchase → Meta Pixel Purchase Conversion ────────────────

    /**
     * Convert GA4 purchase event params to Meta Pixel Purchase format.
     *
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array{content_ids: list<string>, contents: array<int, array<string, mixed>>, num_items: int, value: float, currency: string, content_type: string}
     */
    public static function ga4ToMetaPurchase(array $ga4Params): array
    {
        $items = $ga4Params['items'] ?? [];
        /** @var array<int, array<string, mixed>> $items */
        $metaItems = self::ga4ToMetaContents($items);

        return [
            'content_ids' => $metaItems['content_ids'],
            'contents' => $metaItems['contents'],
            'num_items' => $metaItems['num_items'],
            'value' => (float) ($ga4Params['value'] ?? $metaItems['value']),
            'currency' => (string) ($ga4Params['currency'] ?? 'USD'),
            'content_type' => 'product',
        ];
    }

    /**
     * Convert Meta Pixel Purchase params to GA4 purchase format (reverse).
     *
     * @param  array<string, mixed>  $metaParams  Meta Pixel event parameters
     * @return array{transaction_id: string, value: float, currency: string, items: array<int, array<string, mixed>>}
     */
    public static function metaToGa4Purchase(array $metaParams): array
    {
        $contents = $metaParams['contents'] ?? [];
        /** @var array<int, array<string, mixed>> $contents */

        return [
            'transaction_id' => (string) ($metaParams['content_ids'][0] ?? $metaParams['order_id'] ?? ''),
            'value' => (float) ($metaParams['value'] ?? 0),
            'currency' => (string) ($metaParams['currency'] ?? 'USD'),
            'items' => self::metaToGa4Items($contents),
        ];
    }

    // ── GA4 Refund → Meta Pixel Refund Conversion ────────────────────

    /**
     * Convert GA4 refund event params to Meta Pixel format.
     *
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array{content_ids: list<string>, contents: array<int, array<string, mixed>>, num_items: int, value: float, currency: string, content_type: string}
     */
    public static function ga4ToMetaRefund(array $ga4Params): array
    {
        $items = $ga4Params['items'] ?? [];
        /** @var array<int, array<string, mixed>> $items */
        $metaItems = self::ga4ToMetaContents($items);

        return [
            'content_ids' => $metaItems['content_ids'],
            'contents' => $metaItems['contents'],
            'num_items' => $metaItems['num_items'],
            'value' => (float) ($ga4Params['value'] ?? $metaItems['value']),
            'currency' => (string) ($ga4Params['currency'] ?? 'USD'),
            'content_type' => 'product',
        ];
    }

    // ── Convenience Builders ─────────────────────────────────────────

    /**
     * Build GA4-format purchase parameters.
     *
     * @param  string  $transactionId  Order/transaction ID
     * @param  float  $value  Total revenue
     * @param  string  $currency  ISO 4217 currency code
     * @param  array<int, array{item_id: string, item_name?: string, item_category?: string, price: float, quantity: int}>  $items  Line items
     * @param  array{tax?: float, shipping?: float, coupon?: string, affiliation?: string, shipping_tier?: string, payment_type?: string}  $options  Optional params
     * @return array<string, mixed>  GA4 event parameters
     */
    public static function buildGa4Purchase(
        string $transactionId,
        float $value,
        string $currency,
        array $items,
        array $options = [],
    ): array {
        $params = [
            'transaction_id' => $transactionId,
            'value' => $value,
            'currency' => $currency,
            'items' => $items,
        ];

        // Optional GA4 parameters
        if (isset($options['tax'])) {
            $params['tax'] = (float) $options['tax'];
        }
        if (isset($options['shipping'])) {
            $params['shipping'] = (float) $options['shipping'];
        }
        if (isset($options['coupon'])) {
            $params['coupon'] = (string) $options['coupon'];
        }
        if (isset($options['affiliation'])) {
            $params['affiliation'] = (string) $options['affiliation'];
        }
        if (isset($options['shipping_tier'])) {
            $params['shipping_tier'] = (string) $options['shipping_tier'];
        }
        if (isset($options['payment_type'])) {
            $params['payment_type'] = (string) $options['payment_type'];
        }

        return $params;
    }

    /**
     * Build GA4-format refund parameters.
     *
     * @param  string  $transactionId  Original transaction ID being refunded
     * @param  float  $value  Refund amount
     * @param  string  $currency  ISO 4217 currency code
     * @param  array<int, array{item_id: string, item_name?: string, price: float, quantity: int}>  $items  Refunded line items (empty for full refund)
     * @return array<string, mixed>  GA4 event parameters
     */
    public static function buildGa4Refund(
        string $transactionId,
        float $value,
        string $currency,
        array $items = [],
    ): array {
        $params = [
            'transaction_id' => $transactionId,
            'value' => $value,
            'currency' => $currency,
        ];

        if (! empty($items)) {
            $params['items'] = $items;
        }

        return $params;
    }

    /**
     * Build GA4-format add_to_cart parameters.
     *
     * @param  array{item_id: string, item_name?: string, item_category?: string, price: float, quantity: int}  $item  Cart item
     * @param  string  $currency  ISO 4217 currency code
     * @param  string|null  $itemListName  Optional item list name
     * @return array<string, mixed>  GA4 event parameters
     */
    public static function buildGa4AddToCart(
        array $item,
        string $currency = 'USD',
        ?string $itemListName = null,
    ): array {
        $params = [
            'currency' => $currency,
            'value' => (float) ($item['price'] ?? 0) * (int) ($item['quantity'] ?? 1),
            'items' => [$item],
        ];

        if ($itemListName !== null) {
            $params['item_list_name'] = $itemListName;
        }

        return $params;
    }

    /**
     * Build GA4-format view_item parameters.
     *
     * @param  array{item_id: string, item_name?: string, item_category?: string, price: float, quantity?: int, currency?: string}  $item  Viewed item
     * @param  string  $currency  ISO 4217 currency code
     * @return array<string, mixed>  GA4 event parameters
     */
    public static function buildGa4ViewItem(
        array $item,
        string $currency = 'USD',
    ): array {
        return [
            'currency' => $currency,
            'value' => (float) ($item['price'] ?? 0),
            'items' => [$item],
        ];
    }

    // ── Meta Pixel Convenience Builders ──────────────────────────────

    /**
     * Build Meta Pixel Purchase content format from a GA4-style items array.
     *
     * @param  string  $contentName  Content/product name for Meta
     * @param  string  $contentCategory  Content category for Meta
     * @param  array<int, array{item_id: string, item_name?: string, price: float, quantity: int}>  $items  GA4-format items
     * @param  string  $currency  ISO 4217 currency code
     * @param  string|null  $contentType  Content type (default: 'product')
     * @return array<string, mixed>  Meta Pixel event parameters
     */
    public static function buildMetaPurchase(
        string $contentName,
        string $contentCategory,
        array $items,
        string $currency = 'USD',
        ?string $contentType = null,
    ): array {
        $metaItems = self::ga4ToMetaContents($items);

        $params = [
            'content_name' => $contentName,
            'content_category' => $contentCategory,
            'content_ids' => $metaItems['content_ids'],
            'contents' => $metaItems['contents'],
            'num_items' => $metaItems['num_items'],
            'value' => $metaItems['value'],
            'currency' => $currency,
        ];

        if ($contentType !== null) {
            $params['content_type'] = $contentType;
        }

        return $params;
    }

    /**
     * Build Meta Pixel AddToCart content format.
     *
     * @param  string  $contentName  Product name
     * @param  string  $contentCategory  Product category
     * @param  array{item_id: string, item_name?: string, price: float, quantity: int}  $item  GA4-format item
     * @param  string  $currency  ISO 4217 currency code
     * @return array<string, mixed>  Meta Pixel event parameters
     */
    public static function buildMetaAddToCart(
        string $contentName,
        string $contentCategory,
        array $item,
        string $currency = 'USD',
    ): array {
        $metaItems = self::ga4ToMetaContents([$item]);

        return [
            'content_name' => $contentName,
            'content_category' => $contentCategory,
            'content_ids' => $metaItems['content_ids'],
            'contents' => $metaItems['contents'],
            'value' => $metaItems['value'],
            'currency' => $currency,
        ];
    }

    // ── Cross-Provider Event Builder ─────────────────────────────────

    // ── GA4 → PostHog Items Conversion ────────────────────────────────

    /**
     * Convert GA4 items array to PostHog properties format.
     *
     * PostHog uses `$items` property with a flat structure optimized for
     * product analytics and cohort analysis.
     *
     * @param  array<int, array<string, mixed>>  $items  GA4-format items
     * @return array{items: array<int, array<string, mixed>>, total_value: float, item_count: int}
     */
    public static function ga4ToPosthogProperties(array $items): array
    {
        $posthogItems = [];
        $totalValue = 0.0;

        foreach ($items as $item) {
            $price = (float) ($item['price'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 1);

            $posthogItems[] = [
                'sku' => (string) ($item['item_id'] ?? ''),
                'name' => (string) ($item['item_name'] ?? ''),
                'category' => (string) ($item['item_category'] ?? ''),
                'price' => $price,
                'quantity' => $quantity,
                'variant' => (string) ($item['item_variant'] ?? ''),
                'brand' => (string) ($item['item_brand'] ?? ''),
            ];

            $totalValue += $price * $quantity;
        }

        return [
            'items' => $posthogItems,
            'total_value' => $totalValue,
            'item_count' => count($posthogItems),
        ];
    }

    /**
     * Convert GA4 purchase event params to PostHog 'purchase' event properties.
     *
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array<string, mixed>  PostHog event properties
     */
    public static function ga4ToPosthogPurchase(array $ga4Params): array
    {
        $items = $ga4Params['items'] ?? [];
        /** @var array<int, array<string, mixed>> $items */
        $posthogItems = self::ga4ToPosthogProperties($items);

        return array_merge($posthogItems, [
            '$currency' => (string) ($ga4Params['currency'] ?? 'USD'),
            'value' => (float) ($ga4Params['value'] ?? $posthogItems['total_value']),
            'transaction_id' => (string) ($ga4Params['transaction_id'] ?? ''),
            'coupon' => (string) ($ga4Params['coupon'] ?? ''),
            'tax' => (float) ($ga4Params['tax'] ?? 0),
            'shipping' => (float) ($ga4Params['shipping'] ?? 0),
            'affiliation' => (string) ($ga4Params['affiliation'] ?? ''),
        ]);
    }

    /**
     * Convert GA4 refund event params to PostHog event properties.
     *
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array<string, mixed>  PostHog event properties
     */
    public static function ga4ToPosthogRefund(array $ga4Params): array
    {
        $items = $ga4Params['items'] ?? [];
        /** @var array<int, array<string, mixed>> $items */
        $posthogItems = self::ga4ToPosthogProperties($items);

        return array_merge($posthogItems, [
            '$currency' => (string) ($ga4Params['currency'] ?? 'USD'),
            'value' => (float) ($ga4Params['value'] ?? $posthogItems['total_value']),
            'transaction_id' => (string) ($ga4Params['transaction_id'] ?? ''),
        ]);
    }

    /**
     * Build PostHog-formatted purchase properties from GA4-style items.
     *
     * @param  string  $transactionId  Transaction/order ID
     * @param  float  $value  Total revenue
     * @param  string  $currency  ISO 4217 currency code
     * @param  array<int, array{item_id: string, item_name?: string, item_category?: string, price: float, quantity: int}>  $items  Line items
     * @param  array{coupon?: string, tax?: float, shipping?: float, affiliation?: string}  $options  Optional params
     * @return array<string, mixed>  PostHog event properties
     */
    public static function buildPosthogPurchase(
        string $transactionId,
        float $value,
        string $currency,
        array $items,
        array $options = [],
    ): array
    {
        $posthogItems = self::ga4ToPosthogProperties($items);

        $props = array_merge($posthogItems, [
            '$currency' => $currency,
            'value' => $value,
            'transaction_id' => $transactionId,
        ]);

        if (isset($options['coupon'])) {
            $props['coupon'] = (string) $options['coupon'];
        }
        if (isset($options['tax'])) {
            $props['tax'] = (float) $options['tax'];
        }
        if (isset($options['shipping'])) {
            $props['shipping'] = (float) $options['shipping'];
        }
        if (isset($options['affiliation'])) {
            $props['affiliation'] = (string) $options['affiliation'];
        }

        return $props;
    }

    /**
     * Build a purchase event optimized for a specific provider.
     *
     * Supports GA4, Meta Pixel, and PostHog output formats.
     *
     * @param  'ga4'|'meta'|'posthog'  $provider  Target provider
     * @param  string  $transactionId  Transaction ID
     * @param  float  $value  Revenue
     * @param  string  $currency  Currency code
     * @param  array<int, array{item_id: string, item_name?: string, item_category?: string, price: float, quantity: int}>  $items  Line items
     * @param  array<string, mixed>  $extraParams  Additional provider-specific parameters
     * @return AnalyticsEvent  Provider-optimized event
     */
    public static function buildPurchaseEvent(
        string $provider,
        string $transactionId,
        float $value,
        string $currency,
        array $items,
        array $extraParams = [],
    ): AnalyticsEvent {
        $params = match ($provider) {
            'ga4' => array_merge(
                self::buildGa4Purchase($transactionId, $value, $currency, $items),
                $extraParams,
            ),
            'meta' => array_merge(
                self::buildMetaPurchase(
                    (string) ($extraParams['content_name'] ?? $transactionId),
                    (string) ($extraParams['content_category'] ?? ''),
                    $items,
                    $currency,
                ),
                $extraParams,
            ),
            'posthog' => array_merge(
                self::buildPosthogPurchase($transactionId, $value, $currency, $items, $extraParams),
                $extraParams,
            ),
            default => array_merge(
                self::buildGa4Purchase($transactionId, $value, $currency, $items),
                $extraParams,
            ),
        };

        $eventName = match ($provider) {
            'meta' => 'Purchase',
            'posthog' => 'purchase',
            default => 'purchase',
        };

        return new AnalyticsEvent(
            name: $eventName,
            params: $params,
        );
    }

    /**
     * Calculate total revenue from GA4-format items array.
     *
     * @param  array<int, array{price: float, quantity: int}>  $items
     */
    public static function calculateTotalValue(array $items): float
    {
        $total = 0.0;

        foreach ($items as $item) {
            $price = (float) ($item['price'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 1);
            $total += $price * $quantity;
        }

        return $total;
    }

    /**
     * Normalize a single item to GA4 format (minimally required fields).
     *
     * Ensures every item has item_id, price, and quantity.
     *
     * @param  array<string, mixed>  $item  Raw item data
     * @return array{item_id: string, price: float, quantity: int, item_name?: string, item_category?: string, item_variant?: string, item_brand?: string}
     */
    public static function normalizeGa4Item(array $item): array
    {
        return [
            'item_id' => (string) ($item['item_id'] ?? $item['id'] ?? ''),
            'item_name' => (string) ($item['item_name'] ?? $item['name'] ?? ''),
            'item_category' => (string) ($item['item_category'] ?? $item['category'] ?? ''),
            'price' => (float) ($item['price'] ?? 0),
            'quantity' => (int) ($item['quantity'] ?? 1),
            ...array_filter(
                $item,
                fn (string $key): bool => in_array($key, ['item_variant', 'item_brand', 'affiliation', 'discount', 'index', 'location_id'], true),
                ARRAY_FILTER_USE_KEY,
            ),
        ];
    }

    /**
     * Normalize a batch of items to GA4 format.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{item_id: string, price: float, quantity: int, item_name?: string, item_category?: string}>
     */
    public static function normalizeGa4Items(array $items): array
    {
        return array_map(
            fn (array $item): array => self::normalizeGa4Item($item),
            $items,
        );
    }
}
