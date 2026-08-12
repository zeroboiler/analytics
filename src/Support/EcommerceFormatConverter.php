<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * E-commerce format converter for cross-provider data transformation.
 *
 * Provides bidirectional conversion between GA4, Meta Pixel, and generic
 * e-commerce event formats. Handles items/contents arrays, revenue fields,
 * shipping/tax/coupon parameters, and purchase/refund specific structures.
 *
 * Unlike EventTransformer (which focuses on event names), this service
 * focuses on the detailed parameter structure differences between providers.
 *
 * @since 1.0.0
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

    // ── GA4 → Meta Pixel Item-Level Conversions (v2.66.0) ─────────

    /**
     * Convert GA4 view_item params to Meta Pixel ViewContent format.
     *
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array{content_ids: list<string>, contents: array<int, array<string, mixed>>, content_name: string, content_type: string, value: float, currency: string}
     */
    public static function ga4ToMetaView(array $ga4Params): array
    {
        $items = $ga4Params['items'] ?? [];
        /** @var array<int, array<string, mixed>> $items */
        $metaItems = self::ga4ToMetaContents($items);

        return [
            'content_ids' => $metaItems['content_ids'],
            'contents' => $metaItems['contents'],
            'content_name' => (string) ($ga4Params['content_name'] ?? ($items[0]['item_name'] ?? '')),
            'content_type' => 'product',
            'value' => $metaItems['value'],
            'currency' => (string) ($ga4Params['currency'] ?? 'USD'),
        ];
    }

    /**
     * Convert GA4 add_to_cart params to Meta Pixel AddToCart format.
     *
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array{content_ids: list<string>, contents: array<int, array<string, mixed>>, content_name: string, content_type: string, value: float, currency: string}
     */
    public static function ga4ToMetaAddToCart(array $ga4Params): array
    {
        $items = $ga4Params['items'] ?? [];
        /** @var array<int, array<string, mixed>> $items */
        $metaItems = self::ga4ToMetaContents($items);

        return [
            'content_ids' => $metaItems['content_ids'],
            'contents' => $metaItems['contents'],
            'content_name' => (string) ($items[0]['item_name'] ?? ''),
            'content_type' => 'product',
            'value' => $metaItems['value'],
            'currency' => (string) ($ga4Params['currency'] ?? 'USD'),
        ];
    }

    /**
     * Convert GA4 begin_checkout params to Meta Pixel InitiateCheckout format.
     *
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array{content_ids: list<string>, contents: array<int, array<string, mixed>>, num_items: int, value: float, currency: string}
     */
    public static function ga4ToMetaBeginCheckout(array $ga4Params): array
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
        ];
    }

    /**
     * Convert GA4 add_payment_info params to Meta Pixel AddPaymentInfo format.
     *
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array{content_ids: list<string>, contents: array<int, array<string, mixed>>, content_type: string, value: float, currency: string}
     */
    public static function ga4ToMetaAddPaymentInfo(array $ga4Params): array
    {
        $items = $ga4Params['items'] ?? [];
        /** @var array<int, array<string, mixed>> $items */
        $metaItems = self::ga4ToMetaContents($items);

        return [
            'content_ids' => $metaItems['content_ids'],
            'contents' => $metaItems['contents'],
            'content_type' => 'product',
            'value' => $metaItems['value'],
            'currency' => (string) ($ga4Params['currency'] ?? 'USD'),
        ];
    }

    /**
     * Universal GA4 → Meta converter for any e-commerce event.
     *
     * Automatically selects the correct Meta event name and formats
     * parameters based on the GA4 event name.
     *
     * Supported events: view_item, add_to_cart, begin_checkout,
     * add_payment_info, purchase, refund, add_to_wishlist.
     *
     * @param  string  $ga4EventName  GA4 event name
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array{meta_event: string, meta_params: array<string, mixed>}|null  Meta event data, or null if not supported
     */
    public static function ga4ToMetaAuto(string $ga4EventName, array $ga4Params): ?array
    {
        return match ($ga4EventName) {
            'view_item' => [
                'meta_event' => 'ViewContent',
                'meta_params' => self::ga4ToMetaView($ga4Params),
            ],
            'add_to_cart' => [
                'meta_event' => 'AddToCart',
                'meta_params' => self::ga4ToMetaAddToCart($ga4Params),
            ],
            'begin_checkout' => [
                'meta_event' => 'InitiateCheckout',
                'meta_params' => self::ga4ToMetaBeginCheckout($ga4Params),
            ],
            'add_payment_info' => [
                'meta_event' => 'AddPaymentInfo',
                'meta_params' => self::ga4ToMetaAddPaymentInfo($ga4Params),
            ],
            'purchase' => [
                'meta_event' => 'Purchase',
                'meta_params' => self::ga4ToMetaPurchase($ga4Params),
            ],
            'refund' => [
                'meta_event' => 'Refund',
                'meta_params' => self::ga4ToMetaRefund($ga4Params),
            ],
            'add_to_wishlist' => [
                'meta_event' => 'AddToWishlist',
                'meta_params' => [
                    'content_type' => 'product',
                    'content_ids' => [(string) (($ga4Params['items'][0]['item_id'] ?? ''))],
                    'value' => (float) (($ga4Params['items'][0]['price'] ?? 0)),
                    'currency' => (string) ($ga4Params['currency'] ?? 'USD'),
                ],
            ],
            default => null,
        };
    }

    // ── GA4 → Plausible Conversion ────────────────────────────────────

    /**
     * Convert GA4 purchase event params to Plausible custom event properties.
     *
     * Plausible custom events accept a flat `props` object with string values.
     * Revenue is serialized as a string for Plausible compatibility.
     *
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array{event_name: string, props: array<string, string>}
     */
    public static function ga4ToPlausiblePurchase(array $ga4Params): array
    {
        $items = $ga4Params['items'] ?? [];
        /** @var array<int, array<string, mixed>> $items */
        $itemNames = array_map(
            fn (array $item): string => (string) ($item['item_name'] ?? $item['item_id'] ?? 'unknown'),
            $items,
        );

        return [
            'event_name' => 'purchase',
            'props' => array_filter([
                'transaction_id' => (string) ($ga4Params['transaction_id'] ?? ''),
                'revenue' => (string) round((float) ($ga4Params['value'] ?? 0), 2),
                'currency' => (string) ($ga4Params['currency'] ?? 'USD'),
                'coupon' => (string) ($ga4Params['coupon'] ?? ''),
                'items' => implode(', ', $itemNames),
                'item_count' => (string) count($items),
                'tax' => (string) round((float) ($ga4Params['tax'] ?? 0), 2),
                'shipping' => (string) round((float) ($ga4Params['shipping'] ?? 0), 2),
            ], fn (string $v): bool => $v !== ''),
        ];
    }

    /**
     * Convert GA4 refund event params to Plausible custom event properties.
     *
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array{event_name: string, props: array<string, string>}
     */
    public static function ga4ToPlausibleRefund(array $ga4Params): array
    {
        return [
            'event_name' => 'refund',
            'props' => array_filter([
                'transaction_id' => (string) ($ga4Params['transaction_id'] ?? ''),
                'refund_value' => (string) round((float) ($ga4Params['value'] ?? 0), 2),
                'currency' => (string) ($ga4Params['currency'] ?? 'USD'),
            ], fn (string $v): bool => $v !== ''),
        ];
    }

    /**
     * Convert GA4 add_to_cart event params to Plausible custom event properties.
     *
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array{event_name: string, props: array<string, string>}
     */
    public static function ga4ToPlausibleAddToCart(array $ga4Params): array
    {
        $items = $ga4Params['items'] ?? [];
        /** @var array<int, array<string, mixed>> $items */
        $firstItem = $items[0] ?? [];

        return [
            'event_name' => 'add_to_cart',
            'props' => array_filter([
                'item_name' => (string) ($firstItem['item_name'] ?? $firstItem['item_id'] ?? ''),
                'item_id' => (string) ($firstItem['item_id'] ?? ''),
                'value' => (string) round((float) ($ga4Params['value'] ?? 0), 2),
                'currency' => (string) ($ga4Params['currency'] ?? 'USD'),
            ], fn (string $v): bool => $v !== ''),
        ];
    }

    /**
     * Convert GA4 begin_checkout event params to Plausible custom event properties.
     *
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array{event_name: string, props: array<string, string>}
     */
    public static function ga4ToPlausibleBeginCheckout(array $ga4Params): array
    {
        $items = $ga4Params['items'] ?? [];
        /** @var array<int, array<string, mixed>> $items */
        $itemNames = array_map(
            fn (array $item): string => (string) ($item['item_name'] ?? $item['item_id'] ?? ''),
            $items,
        );

        return [
            'event_name' => 'begin_checkout',
            'props' => array_filter([
                'value' => (string) round((float) ($ga4Params['value'] ?? 0), 2),
                'currency' => (string) ($ga4Params['currency'] ?? 'USD'),
                'coupon' => (string) ($ga4Params['coupon'] ?? ''),
                'items' => implode(', ', $itemNames),
                'item_count' => (string) count($items),
            ], fn (string $v): bool => $v !== ''),
        ];
    }

    /**
     * Build a Plausible-formatted purchase event from GA4-style items.
     *
     * @param  string  $transactionId  Transaction ID
     * @param  float  $value  Revenue
     * @param  string  $currency  Currency code
     * @param  array<int, array{item_id: string, item_name?: string, item_category?: string, price: float, quantity: int}>  $items  Line items
     * @param  array{coupon?: string, tax?: float, shipping?: float}  $options  Optional params
     * @return AnalyticsEvent  Plausible-optimized event
     */
    public static function buildPlausiblePurchase(
        string $transactionId,
        float $value,
        string $currency,
        array $items,
        array $options = [],
    ): AnalyticsEvent {
        $plausible = self::ga4ToPlausiblePurchase(
            array_merge(self::buildGa4Purchase($transactionId, $value, $currency, $items), $options),
        );

        return new AnalyticsEvent(
            name: $plausible['event_name'],
            params: $plausible['props'],
        );
    }

    // ── Universal GA4 → Plausible Converter (v5.9.0) ─────────────────

    /**
     * Convert GA4 view_item event params to Plausible custom event properties.
     *
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array{event_name: string, props: array<string, string>}
     *
     * @since 7.4.0
     */
    public static function ga4ToPlausibleViewItem(array $ga4Params): array
    {
        $items = $ga4Params['items'] ?? [];
        /** @var array<int, array<string, mixed>> $items */
        $firstItem = $items[0] ?? [];

        return [
            'event_name' => 'view_item',
            'props' => array_filter([
                'item_name' => (string) ($firstItem['item_name'] ?? $firstItem['item_id'] ?? ''),
                'item_id' => (string) ($firstItem['item_id'] ?? ''),
                'value' => (string) round((float) ($ga4Params['value'] ?? ($firstItem['price'] ?? 0)), 2),
                'currency' => (string) ($ga4Params['currency'] ?? 'USD'),
            ], fn (string $v): bool => $v !== ''),
        ];
    }

    /**
     * Universal GA4 → Plausible converter for any e-commerce event.
     *
     * Automatically selects the correct Plausible event name and formats
     * parameters based on the GA4 event name.
     *
     * Supported events: purchase, refund, add_to_cart, begin_checkout, view_item.
     *
     * @param  string  $ga4EventName  GA4 event name
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array{plausible_event: string, plausible_params: array<string, string>}|null  Plausible event data, or null if not supported
     */
    public static function ga4ToPlausibleAuto(string $ga4EventName, array $ga4Params): ?array
    {
        return match ($ga4EventName) {
            'purchase' => [
                'plausible_event' => 'purchase',
                'plausible_params' => self::ga4ToPlausiblePurchase($ga4Params)['props'],
            ],
            'refund' => [
                'plausible_event' => 'refund',
                'plausible_params' => self::ga4ToPlausibleRefund($ga4Params)['props'],
            ],
            'add_to_cart' => [
                'plausible_event' => 'add_to_cart',
                'plausible_params' => self::ga4ToPlausibleAddToCart($ga4Params)['props'],
            ],
            'begin_checkout' => [
                'plausible_event' => 'begin_checkout',
                'plausible_params' => self::ga4ToPlausibleBeginCheckout($ga4Params)['props'],
            ],
            'view_item' => [
                'plausible_event' => 'view_item',
                'plausible_params' => self::ga4ToPlausibleViewItem($ga4Params)['props'],
            ],
            default => null,
        };
    }

    // ── PostHog CAPI (Server-Side Conversions API) — v7.4.0 ──────────

    /**
     * Build PostHog-formatted ViewContent / view_item properties from GA4-style items.
     *
     * PostHog uses '$set' and custom event properties for product views.
     * Items are serialized as a structured array compatible with PostHog's
     * product analytics feature.
     *
     * @param  array{item_id: string, item_name?: string, item_category?: string, price: float, quantity?: int, currency?: string}  $item  Viewed item
     * @param  string  $currency  ISO 4217 currency code
     * @return array<string, mixed>  PostHog event properties
     *
     * @since 7.4.0
     */
    public static function buildPosthogViewItem(
        array $item,
        string $currency = 'USD',
    ): array {
        return [
            '$currency' => $currency,
            'value' => (float) ($item['price'] ?? 0),
            'items' => [
                [
                    'sku' => (string) ($item['item_id'] ?? ''),
                    'name' => (string) ($item['item_name'] ?? ''),
                    'category' => (string) ($item['item_category'] ?? ''),
                    'price' => (float) ($item['price'] ?? 0),
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'variant' => (string) ($item['item_variant'] ?? ''),
                    'brand' => (string) ($item['item_brand'] ?? ''),
                ],
            ],
        ];
    }

    /**
     * Build PostHog-formatted AddToCart properties from GA4-style items.
     *
     * @param  array{item_id: string, item_name?: string, item_category?: string, price: float, quantity: int}  $item  Cart item
     * @param  string  $currency  ISO 4217 currency code
     * @return array<string, mixed>  PostHog event properties
     *
     * @since 7.4.0
     */
    public static function buildPosthogAddToCart(
        array $item,
        string $currency = 'USD',
    ): array {
        return [
            '$currency' => $currency,
            'value' => (float) ($item['price'] ?? 0) * (int) ($item['quantity'] ?? 1),
            'items' => [
                [
                    'sku' => (string) ($item['item_id'] ?? ''),
                    'name' => (string) ($item['item_name'] ?? ''),
                    'category' => (string) ($item['item_category'] ?? ''),
                    'price' => (float) ($item['price'] ?? 0),
                    'quantity' => (int) ($item['quantity'] ?? 1),
                ],
            ],
        ];
    }

    /**
     * Build PostHog-formatted BeginCheckout properties from GA4-style items.
     *
     * @param  array<int, array{item_id: string, item_name?: string, item_category?: string, price: float, quantity: int}>  $items  Cart items
     * @param  float  $value  Total cart value
     * @param  string  $currency  ISO 4217 currency code
     * @param  array{coupon?: string}  $options  Optional params
     * @return array<string, mixed>  PostHog event properties
     *
     * @since 7.4.0
     */
    public static function buildPosthogBeginCheckout(
        array $items,
        float $value,
        string $currency = 'USD',
        array $options = [],
    ): array {
        $posthogItems = self::ga4ToPosthogProperties($items);

        $params = array_merge($posthogItems, [
            '$currency' => $currency,
            'value' => $value,
        ]);

        if (isset($options['coupon'])) {
            $params['coupon'] = (string) $options['coupon'];
        }

        return $params;
    }

    /**
     * Build a PostHog-formatted ViewContent / view_item event.
     *
     * @param  array{item_id: string, item_name?: string, item_category?: string, price: float}  $item  Viewed item
     * @param  string  $currency  ISO 4217 currency code
     * @return AnalyticsEvent  PostHog-optimized event
     *
     * @since 7.4.0
     */
    public static function buildPosthogViewItemEvent(
        array $item,
        string $currency = 'USD',
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            name: '$view_item',
            params: self::buildPosthogViewItem($item, $currency),
        );
    }

    /**
     * Build a PostHog-formatted AddToCart event.
     *
     * @param  array{item_id: string, item_name?: string, item_category?: string, price: float, quantity: int}  $item  Cart item
     * @param  string  $currency  ISO 4217 currency code
     * @return AnalyticsEvent  PostHog-optimized event
     *
     * @since 7.4.0
     */
    public static function buildPosthogAddToCartEvent(
        array $item,
        string $currency = 'USD',
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            name: 'add_to_cart',
            params: self::buildPosthogAddToCart($item, $currency),
        );
    }

    // ── Universal Provider Format Conversion (v5.9.0) ────────────────

    /**
     * Convert any e-commerce event from GA4 format to a target provider format.
     *
     * Dispatches to the correct provider-specific converter based on the target
     * provider name. Supports 'meta', 'posthog', and 'plausible' targets.
     * For 'ga4', returns the input unchanged.
     *
     * @param  string  $targetProvider  Target provider ('meta', 'posthog', 'plausible', 'ga4')
     * @param  string  $ga4EventName  GA4 event name (e.g. 'purchase', 'add_to_cart')
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array{provider_event: string|null, provider_params: array<string, mixed>}  Converted event data, or original if no conversion needed
     */
    public static function toGa4Format(
        string $targetProvider,
        string $ga4EventName,
        array $ga4Params,
    ): array {
        return match ($targetProvider) {
            'meta' => self::ga4ToMetaAuto($ga4EventName, $ga4Params)
                ?? ['provider_event' => null, 'provider_params' => $ga4Params],
            'posthog' => [
                'provider_event' => EventCatalog::posthogNameFor($ga4EventName),
                'provider_params' => self::ga4ToPosthogPurchase($ga4Params),
            ],
            'plausible' => self::ga4ToPlausibleAuto($ga4EventName, $ga4Params)
                ?? ['plausible_event' => null, 'plausible_params' => []],
            default => ['provider_event' => $ga4EventName, 'provider_params' => $ga4Params],
        };
    }

    /**
     * Convert any e-commerce event from a source provider back to GA4 format.
     *
     * Supports 'meta' and 'posthog' as source providers. For other providers
     * or unknown event types, returns the input unchanged.
     *
     * @param  string  $sourceProvider  Source provider ('meta', 'posthog', 'ga4')
     * @param  string  $eventName  Source provider's event name
     * @param  array<string, mixed>  $params  Source provider's event parameters
     * @return array{ga4_event: string, ga4_params: array<string, mixed>}  GA4-format event data
     */
    public static function fromGa4Format(
        string $sourceProvider,
        string $eventName,
        array $params,
    ): array {
        return match ($sourceProvider) {
            'meta' => match ($eventName) {
                'Purchase' => [
                    'ga4_event' => 'purchase',
                    'ga4_params' => self::metaToGa4Purchase($params),
                ],
                'AddToCart' => [
                    'ga4_event' => 'add_to_cart',
                    'ga4_params' => array_merge(
                        self::metaToGa4Items($params['contents'] ?? []),
                        [
                            'currency' => (string) ($params['currency'] ?? 'USD'),
                            'value' => (float) ($params['value'] ?? 0),
                        ],
                    ),
                ],
                default => ['ga4_event' => $eventName, 'ga4_params' => $params],
            },
            'posthog' => [
                'ga4_event' => $eventName,
                'ga4_params' => $params,
            ],
            default => ['ga4_event' => $eventName, 'ga4_params' => $params],
        };
    }

    // ── GA4 → Mixpanel E-Commerce Conversion ───────────────────────────

    /**
     * Convert GA4 items array to Mixpanel e-commerce properties format.
     *
     * Mixpanel uses a flat structure with `$products` array for e-commerce.
     *
     * @param  array<int, array<string, mixed>>  $items  GA4-format items
     * @return array{products: array<int, array<string, mixed>>, total_revenue: float, product_count: int}
     *
     * @since 18.0.0
     */
    public static function ga4ToMixpanelProperties(array $items): array
    {
        $products = [];
        $totalRevenue = 0.0;

        foreach ($items as $item) {
            $price = (float) ($item['price'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 1);

            $products[] = [
                '$product_id' => (string) ($item['item_id'] ?? ''),
                '$name' => (string) ($item['item_name'] ?? ''),
                '$category' => (string) ($item['item_category'] ?? ''),
                '$price' => $price,
                '$quantity' => $quantity,
                '$variant' => (string) ($item['item_variant'] ?? ''),
                '$brand' => (string) ($item['item_brand'] ?? ''),
            ];

            $totalRevenue += $price * $quantity;
        }

        return [
            'products' => $products,
            'total_revenue' => $totalRevenue,
            'product_count' => count($products),
        ];
    }

    /**
     * Convert GA4 purchase params to Mixpanel 'Purchase' event properties.
     *
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array<string, mixed>  Mixpanel event properties
     *
     * @since 18.0.0
     */
    public static function ga4ToMixpanelPurchase(array $ga4Params): array
    {
        $items = $ga4Params['items'] ?? [];
        /** @var array<int, array<string, mixed>> $items */
        $mixpanelItems = self::ga4ToMixpanelProperties($items);

        return array_merge($mixpanelItems, [
            '$revenue' => (float) ($ga4Params['value'] ?? $mixpanelItems['total_revenue']),
            '$currency' => (string) ($ga4Params['currency'] ?? 'USD'),
            '$transaction_id' => (string) ($ga4Params['transaction_id'] ?? ''),
            '$coupon' => (string) ($ga4Params['coupon'] ?? ''),
            '$tax' => (float) ($ga4Params['tax'] ?? 0),
            '$shipping' => (float) ($ga4Params['shipping'] ?? 0),
        ]);
    }

    /**
     * Convert GA4 refund params to Mixpanel event properties.
     *
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array<string, mixed>  Mixpanel event properties
     *
     * @since 18.0.0
     */
    public static function ga4ToMixpanelRefund(array $ga4Params): array
    {
        $items = $ga4Params['items'] ?? [];
        /** @var array<int, array<string, mixed>> $items */
        $mixpanelItems = self::ga4ToMixpanelProperties($items);

        return array_merge($mixpanelItems, [
            '$revenue' => (float) ($ga4Params['value'] ?? $mixpanelItems['total_revenue']),
            '$currency' => (string) ($ga4Params['currency'] ?? 'USD'),
            '$transaction_id' => (string) ($ga4Params['transaction_id'] ?? ''),
        ]);
    }

    // ── GA4 → Amplitude E-Commerce Conversion ──────────────────────────

    /**
     * Convert GA4 items array to Amplitude e-commerce event properties.
     *
     * Amplitude uses `Event Properties` with a `$items` array.
     *
     * @param  array<int, array<string, mixed>>  $items  GA4-format items
     * @return array{items: array<int, array<string, mixed>>, total_amount: float, item_count: int}
     *
     * @since 18.0.0
     */
    public static function ga4ToAmplitudeProperties(array $items): array
    {
        $ampItems = [];
        $totalAmount = 0.0;

        foreach ($items as $item) {
            $price = (float) ($item['price'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 1);

            $ampItems[] = [
                'productId' => (string) ($item['item_id'] ?? ''),
                'productName' => (string) ($item['item_name'] ?? ''),
                'category' => (string) ($item['item_category'] ?? ''),
                'price' => $price,
                'quantity' => $quantity,
                'variant' => (string) ($item['item_variant'] ?? ''),
                'brand' => (string) ($item['item_brand'] ?? ''),
            ];

            $totalAmount += $price * $quantity;
        }

        return [
            'items' => $ampItems,
            'total_amount' => $totalAmount,
            'item_count' => count($ampItems),
        ];
    }

    /**
     * Convert GA4 purchase params to Amplitude 'Completed Order' event properties.
     *
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array<string, mixed>  Amplitude event properties
     *
     * @since 18.0.0
     */
    public static function ga4ToAmplitudePurchase(array $ga4Params): array
    {
        $items = $ga4Params['items'] ?? [];
        /** @var array<int, array<string, mixed>> $items */
        $ampItems = self::ga4ToAmplitudeProperties($items);

        return array_merge($ampItems, [
            'revenue' => (float) ($ga4Params['value'] ?? $ampItems['total_amount']),
            'currency' => (string) ($ga4Params['currency'] ?? 'USD'),
            'transactionId' => (string) ($ga4Params['transaction_id'] ?? ''),
            'coupon' => (string) ($ga4Params['coupon'] ?? ''),
            'tax' => (float) ($ga4Params['tax'] ?? 0),
            'shipping' => (float) ($ga4Params['shipping'] ?? 0),
            'affiliation' => (string) ($ga4Params['affiliation'] ?? ''),
        ]);
    }

    /**
     * Convert GA4 refund params to Amplitude event properties.
     *
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array<string, mixed>  Amplitude event properties
     *
     * @since 18.0.0
     */
    public static function ga4ToAmplitudeRefund(array $ga4Params): array
    {
        $items = $ga4Params['items'] ?? [];
        /** @var array<int, array<string, mixed>> $items */
        $ampItems = self::ga4ToAmplitudeProperties($items);

        return array_merge($ampItems, [
            'revenue' => (float) ($ga4Params['value'] ?? $ampItems['total_amount']),
            'currency' => (string) ($ga4Params['currency'] ?? 'USD'),
            'transactionId' => (string) ($ga4Params['transaction_id'] ?? ''),
        ]);
    }

    // ── GA4 → TikTok E-Commerce Conversion (v35.0.0) ─────────────────

    /**
     * Convert GA4 items array to TikTok e-commerce properties format.
     *
     * TikTok uses `contents` array with content_id, content_name, content_category,
     * quantity, and price fields for advanced matching.
     *
     * @param  array<int, array<string, mixed>>  $items  GA4-format items
     * @return array{contents: array<int, array<string, mixed>>, total_value: float, num_items: int}
     *
     * @since 35.0.0
     */
    public static function ga4ToTiktokProperties(array $items): array
    {
        $contents = [];
        $totalValue = 0.0;

        foreach ($items as $item) {
            $price = (float) ($item['price'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 1);

            $contents[] = [
                'content_id' => (string) ($item['item_id'] ?? ''),
                'content_name' => (string) ($item['item_name'] ?? ''),
                'content_category' => (string) ($item['item_category'] ?? ''),
                'quantity' => $quantity,
                'price' => $price,
            ];

            $totalValue += $price * $quantity;
        }

        return [
            'contents' => $contents,
            'total_value' => $totalValue,
            'num_items' => count($contents),
        ];
    }

    /**
     * Convert GA4 purchase params to TikTok 'CompletePayment' event properties.
     *
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array<string, mixed>  TikTok event properties
     *
     * @since 35.0.0
     */
    public static function ga4ToTiktokPurchase(array $ga4Params): array
    {
        $items = $ga4Params['items'] ?? [];
        /** @var array<int, array<string, mixed>> $items */
        $tiktokItems = self::ga4ToTiktokProperties($items);

        return array_merge($tiktokItems, [
            'value' => (float) ($ga4Params['value'] ?? $tiktokItems['total_value']),
            'currency' => (string) ($ga4Params['currency'] ?? 'USD'),
            'transaction_id' => (string) ($ga4Params['transaction_id'] ?? ''),
            'coupon' => (string) ($ga4Params['coupon'] ?? ''),
            'tax' => (float) ($ga4Params['tax'] ?? 0),
            'shipping' => (float) ($ga4Params['shipping'] ?? 0),
        ]);
    }

    /**
     * Convert GA4 refund params to TikTok event properties.
     *
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array<string, mixed>  TikTok event properties
     *
     * @since 35.0.0
     */
    public static function ga4ToTiktokRefund(array $ga4Params): array
    {
        $items = $ga4Params['items'] ?? [];
        /** @var array<int, array<string, mixed>> $items */
        $tiktokItems = self::ga4ToTiktokProperties($items);

        return array_merge($tiktokItems, [
            'value' => (float) ($ga4Params['value'] ?? $tiktokItems['total_value']),
            'currency' => (string) ($ga4Params['currency'] ?? 'USD'),
            'transaction_id' => (string) ($ga4Params['transaction_id'] ?? ''),
        ]);
    }

    /**
     * Convert GA4 add_to_cart params to TikTok event properties.
     *
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array<string, mixed>  TikTok event properties
     *
     * @since 35.0.0
     */
    public static function ga4ToTiktokAddToCart(array $ga4Params): array
    {
        $items = $ga4Params['items'] ?? [];
        /** @var array<int, array<string, mixed>> $items */
        $tiktokItems = self::ga4ToTiktokProperties($items);

        return array_merge($tiktokItems, [
            'value' => (float) ($ga4Params['value'] ?? $tiktokItems['total_value']),
            'currency' => (string) ($ga4Params['currency'] ?? 'USD'),
        ]);
    }

    /**
     * Build TikTok-formatted purchase event from GA4-style items.
     *
     * @param  string  $transactionId  Transaction ID
     * @param  float  $value  Revenue
     * @param  string  $currency  Currency code
     * @param  array<int, array{item_id: string, item_name?: string, item_category?: string, price: float, quantity: int}>  $items  Line items
     * @param  array{coupon?: string, tax?: float, shipping?: float}  $options  Optional params
     * @return AnalyticsEvent  TikTok-optimized event
     *
     * @since 35.0.0
     */
    public static function buildTiktokPurchase(
        string $transactionId,
        float $value,
        string $currency,
        array $items,
        array $options = [],
    ): AnalyticsEvent {
        $tiktok = self::ga4ToTiktokPurchase(
            array_merge(self::buildGa4Purchase($transactionId, $value, $currency, $items), $options),
        );

        return new AnalyticsEvent(
            name: 'CompletePayment',
            params: $tiktok,
        );
    }

    // ── GA4 → LinkedIn E-Commerce Conversion (v35.0.0) ────────────────

    /**
     * Convert GA4 purchase params to LinkedIn conversion event format.
     *
     * LinkedIn Conversions API uses a flat structure with conversion_id,
     * event_name, value, and currency.
     *
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array{value: float, currency: string, transaction_id: string, items_count: int}
     *
     * @since 35.0.0
     */
    public static function ga4ToLinkedinPurchase(array $ga4Params): array
    {
        $items = $ga4Params['items'] ?? [];
        /** @var array<int, array<string, mixed>> $items */

        return [
            'value' => (float) ($ga4Params['value'] ?? 0),
            'currency' => (string) ($ga4Params['currency'] ?? 'USD'),
            'transaction_id' => (string) ($ga4Params['transaction_id'] ?? ''),
            'items_count' => count($items),
        ];
    }

    /**
     * Convert GA4 add_to_cart params to LinkedIn event format.
     *
     * @param  array<string, mixed>  $ga4Params  GA4 event parameters
     * @return array{value: float, currency: string}
     *
     * @since 35.0.0
     */
    public static function ga4ToLinkedinAddToCart(array $ga4Params): array
    {
        return [
            'value' => (float) ($ga4Params['value'] ?? 0),
            'currency' => (string) ($ga4Params['currency'] ?? 'USD'),
        ];
    }

    /**
     * Build LinkedIn-formatted purchase event from GA4-style items.
     *
     * @param  string  $transactionId  Transaction ID
     * @param  float  $value  Revenue
     * @param  string  $currency  Currency code
     * @return AnalyticsEvent  LinkedIn-optimized event
     *
     * @since 35.0.0
     */
    public static function buildLinkedinPurchase(
        string $transactionId,
        float $value,
        string $currency,
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            name: 'purchase',
            params: self::ga4ToLinkedinPurchase([
                'transaction_id' => $transactionId,
                'value' => $value,
                'currency' => $currency,
            ]),
        );
    }

    // ── Universal Multi-Provider Builder ────────────────────────────────

    /**
     * Build e-commerce event parameters for all 10 supported providers at once.
     *
     * Returns a map of provider name → formatted event parameters for the
     * given event type (purchase, refund, add_to_cart, view_item).
     *
     * @param  'purchase'|'refund'|'add_to_cart'|'view_item'  $eventType
     * @param  array<string, mixed>  $ga4Params  GA4-format event parameters
     * @return array{ga4: array<string, mixed>, meta: array<string, mixed>, posthog: array<string, mixed>, mixpanel: array<string, mixed>, amplitude: array<string, mixed>, plausible: array{event_name: string, props: array<string, string>}|null, tiktok: array<string, mixed>, linkedin: array<string, mixed>}
     *
     * @since 18.0.0
     */
    public static function buildForAllProviders(string $eventType, array $ga4Params): array
    {
        return [
            'ga4' => $ga4Params,
            'meta' => match ($eventType) {
                'purchase' => self::ga4ToMetaPurchase($ga4Params),
                'refund' => self::ga4ToMetaRefund($ga4Params),
                default => ['value' => (float) ($ga4Params['value'] ?? 0), 'currency' => (string) ($ga4Params['currency'] ?? 'USD')],
            },
            'posthog' => match ($eventType) {
                'purchase' => self::ga4ToPosthogPurchase($ga4Params),
                'refund' => self::ga4ToPosthogRefund($ga4Params),
                default => ['value' => (float) ($ga4Params['value'] ?? 0), 'currency' => (string) ($ga4Params['currency'] ?? 'USD')],
            },
            'mixpanel' => match ($eventType) {
                'purchase' => self::ga4ToMixpanelPurchase($ga4Params),
                'refund' => self::ga4ToMixpanelRefund($ga4Params),
                default => self::ga4ToMixpanelProperties($ga4Params['items'] ?? []),
            },
            'amplitude' => match ($eventType) {
                'purchase' => self::ga4ToAmplitudePurchase($ga4Params),
                'refund' => self::ga4ToAmplitudeRefund($ga4Params),
                default => self::ga4ToAmplitudeProperties($ga4Params['items'] ?? []),
            },
            'plausible' => self::ga4ToPlausibleAuto($eventType, $ga4Params),
            'tiktok' => match ($eventType) {
                'purchase' => self::ga4ToTiktokPurchase($ga4Params),
                'refund' => self::ga4ToTiktokRefund($ga4Params),
                'add_to_cart' => self::ga4ToTiktokAddToCart($ga4Params),
                default => self::ga4ToTiktokProperties($ga4Params['items'] ?? []),
            },
            'linkedin' => match ($eventType) {
                'purchase' => self::ga4ToLinkedinPurchase($ga4Params),
                'add_to_cart' => self::ga4ToLinkedinAddToCart($ga4Params),
                default => ['value' => (float) ($ga4Params['value'] ?? 0), 'currency' => (string) ($ga4Params['currency'] ?? 'USD')],
            },
        ];
    }
}
