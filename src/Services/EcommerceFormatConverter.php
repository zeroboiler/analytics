<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Converts e-commerce analytics events between GA4 and Meta Pixel formats.
 *
 * Provides bidirectional conversion for the core e-commerce events:
 * - view_item / ViewContent
 * - add_to_cart / AddToCart
 * - purchase / Purchase
 * - refund / Refund
 * - begin_checkout / InitiateCheckout
 * - add_to_wishlist / AddToWishlist
 *
 * Each converter method accepts a generic event payload and returns
 * the provider-specific format. This is useful for:
 * - Server-side event forwarding (GA4 MP → Meta CAPI)
 * - Unified event logging / replay
 * - Multi-provider payload generation
 *
 * @since 262.0.0
 */
final class EcommerceFormatConverter
{
    /**
     * Convert an event payload to GA4 Measurement Protocol format.
     *
     * GA4 expects params as a flat object with specific naming conventions
     * (currency, value, items array with item_id, item_name, price, quantity).
     *
     * @param  string  $eventName  Canonical catalog event name (e.g. 'purchase')
     * @param  array<string, mixed>  $params  Event parameters
     * @return array{name: string, params: array<string, mixed>}  GA4-formatted payload
     */
    public function toGa4(string $eventName, array $params): array
    {
        $entry = EventCatalog::get($eventName);
        $ga4Name = $entry['ga4'] ?? $eventName;

        $ga4Params = [];

        // Copy standard GA4 e-commerce params
        if (isset($params['transaction_id'])) {
            $ga4Params['transaction_id'] = (string) $params['transaction_id'];
        }
        if (isset($params['value'])) {
            $ga4Params['value'] = (float) $params['value'];
        }
        if (isset($params['currency'])) {
            $ga4Params['currency'] = (string) $params['currency'];
        }
        if (isset($params['tax'])) {
            $ga4Params['tax'] = (float) $params['tax'];
        }
        if (isset($params['shipping'])) {
            $ga4Params['shipping'] = (float) $params['shipping'];
        }
        if (isset($params['coupon'])) {
            $ga4Params['coupon'] = (string) $params['coupon'];
        }

        // Convert items array to GA4 format
        if (isset($params['items']) && is_array($params['items'])) {
            $ga4Params['items'] = $this->convertItemsToGa4($params['items']);
        }

        // Copy any remaining params
        foreach ($params as $key => $value) {
            if (! isset($ga4Params[$key]) && ! in_array($key, ['items', 'user_data', 'custom_properties'], true)) {
                $ga4Params[$key] = $value;
            }
        }

        return [
            'name' => $ga4Name,
            'params' => $ga4Params,
        ];
    }

    /**
     * Convert an event payload to Meta Pixel / CAPI format.
     *
     * Meta expects: event_name, custom_data (value, currency, content_ids,
     * content_type, contents array with id, quantity, item_price), user_data.
     *
     * @param  string  $eventName  Canonical catalog event name (e.g. 'purchase')
     * @param  array<string, mixed>  $params  Event parameters
     * @return array{event: string, custom_data: array<string, mixed>, user_data?: array<string, mixed>}  Meta-formatted payload
     */
    public function toMeta(string $eventName, array $params): array
    {
        $entry = EventCatalog::get($eventName);
        $metaName = $entry['meta'] ?? null;

        if ($metaName === null) {
            // No Meta mapping — return null-safe structure
            return [
                'event' => $eventName,
                'custom_data' => $params,
            ];
        }

        $customData = [];

        // Standard e-commerce params
        if (isset($params['value'])) {
            $customData['value'] = (float) $params['value'];
        }
        if (isset($params['currency'])) {
            $customData['currency'] = (string) $params['currency'];
        }

        // Convert items to Meta contents format
        if (isset($params['items']) && is_array($params['items'])) {
            $metaItems = [];
            $contentIds = [];

            foreach ($params['items'] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $metaItem = [];
                if (isset($item['item_id'])) {
                    $metaItem['id'] = (string) $item['item_id'];
                    $contentIds[] = (string) $item['item_id'];
                }
                if (isset($item['quantity'])) {
                    $metaItem['quantity'] = (int) $item['quantity'];
                }
                if (isset($item['price'])) {
                    $metaItem['item_price'] = (float) $item['price'];
                }
                $metaItems[] = $metaItem;
            }

            if ($contentIds !== []) {
                $customData['content_ids'] = $contentIds;
                $customData['content_type'] = 'product';
            }
            if ($metaItems !== []) {
                $customData['contents'] = $metaItems;
            }
        }

        // Single item shorthand
        if (isset($params['item_id']) && ! isset($params['items'])) {
            $customData['content_ids'] = [(string) $params['item_id']];
            $customData['content_type'] = 'product';
            if (isset($params['quantity'])) {
                $customData['contents'] = [
                    ['id' => (string) $params['item_id'], 'quantity' => (int) $params['quantity']]
                    +(isset($params['price']) ? ['item_price' => (float) $params['price']] : []),
                ];
            }
        }

        // Copy transaction data
        if (isset($params['transaction_id'])) {
            $customData['content_name'] = (string) $params['transaction_id'];
        }

        // Merge remaining non-standard params
        foreach ($params as $key => $value) {
            if (! isset($customData[$key]) && ! in_array($key, ['items', 'user_data', 'item_id', 'transaction_id'], true)) {
                $customData[$key] = $value;
            }
        }

        $result = [
            'event' => $metaName,
            'custom_data' => $customData,
        ];

        if (isset($params['user_data']) && is_array($params['user_data'])) {
            $result['user_data'] = $params['user_data'];
        }

        return $result;
    }

    /**
     * Convert a single item or items array to GA4 item format.
     *
     * GA4 items use: item_id, item_name, item_category, item_variant,
     * price, quantity, index.
     *
     * @param  list<array<string, mixed>>  $items  Raw items array
     * @return list<array<string, mixed>>  GA4-formatted items
     */
    private function convertItemsToGa4(array $items): array
    {
        $ga4Items = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $ga4Item = [];

            // Map common field names
            $fieldMap = [
                'item_id' => 'item_id',
                'id' => 'item_id',
                'item_name' => 'item_name',
                'name' => 'item_name',
                'item_category' => 'item_category',
                'category' => 'item_category',
                'item_variant' => 'item_variant',
                'variant' => 'item_variant',
                'item_brand' => 'item_brand',
                'brand' => 'item_brand',
                'price' => 'price',
                'quantity' => 'quantity',
            ];

            foreach ($fieldMap as $from => $to) {
                if (isset($item[$from]) && ! isset($item[$to])) {
                    $ga4Item[$to] = $item[$from];
                } elseif (isset($item[$to])) {
                    $ga4Item[$to] = $item[$to];
                }
            }

            // Auto-add index if not present
            if (! isset($ga4Item['index'])) {
                $ga4Item['index'] = $index;
            }

            $ga4Items[] = $ga4Item;
        }

        return $ga4Items;
    }

    /**
     * Convert from GA4 format to Meta format.
     *
     * Convenience method for server-side event forwarding.
     * Accepts a GA4-formatted payload and converts to Meta CAPI format.
     *
     * @param  array{name: string, params: array<string, mixed>}  $ga4Payload
     * @return array{event: string, custom_data: array<string, mixed>}
     */
    public function ga4ToMeta(array $ga4Payload): array
    {
        $name = $ga4Payload['name'] ?? '';
        $params = $ga4Payload['params'] ?? [];

        // Reverse-resolve the canonical name from GA4 name
        $canonical = $this->resolveGa4ToCanonical($name);

        return $this->toMeta($canonical ?? $name, $params);
    }

    /**
     * Convert from Meta format to GA4 format.
     *
     * Accepts a Meta-formatted payload and converts to GA4 MP format.
     *
     * @param  array{event: string, custom_data?: array<string, mixed>}  $metaPayload
     * @return array{name: string, params: array<string, mixed>}
     */
    public function metaToGa4(array $metaPayload): array
    {
        $name = $metaPayload['event'] ?? '';
        $params = $metaPayload['custom_data'] ?? $metaPayload;

        // Reverse-resolve the canonical name from Meta name
        $canonical = $this->resolveMetaToCanonical($name);

        return $this->toGa4($canonical ?? $name, $params);
    }

    /**
     * Resolve a GA4 event name back to its canonical catalog name.
     *
     * @return string|null  Canonical name or null if not found
     */
    private function resolveGa4ToCanonical(string $ga4Name): ?string
    {
        foreach (EventCatalog::all() as $name => $entry) {
            if (($entry['ga4'] ?? '') === $ga4Name) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Resolve a Meta event name back to its canonical catalog name.
     *
     * @return string|null  Canonical name or null if not found
     */
    private function resolveMetaToCanonical(string $metaName): ?string
    {
        foreach (EventCatalog::all() as $name => $entry) {
            if (($entry['meta'] ?? null) === $metaName) {
                return $name;
            }
        }

        return null;
    }
}
