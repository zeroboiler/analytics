<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter as SupportConverter;

/**
 * Converts e-commerce analytics events between all 8 supported provider formats.
 *
 * Service-layer entry point for e-commerce format conversion. Delegates to
 * Support\EcommerceFormatConverter for the heavy lifting, while adding:
 * - Canonical event name resolution via EventCatalog
 * - Universal `toProvider()` dispatcher
 * - `toAllProviders()` bulk conversion
 * - GA4 ↔ Meta bidirectional convenience methods
 * - Provider parity validation
 *
 * Supported providers: GA4, Meta Pixel, PostHog, Mixpanel, Amplitude,
 * Plausible, TikTok, LinkedIn.
 *
 * Supported e-commerce events:
 * - view_item, add_to_cart, remove_from_cart, view_cart
 * - add_to_wishlist, select_item, select_promotion, view_promotion
 * - begin_checkout, add_payment_info, purchase, refund
 *
 * @see \ZeroBoiler\Analytics\Support\EcommerceFormatConverter
 * @see \ZeroBoiler\Analytics\Events\EventCatalog
 *
 * @since 262.0.0
 * @since 273.0.0  Full 8-provider parity (delegates to Support\EcommerceFormatConverter)
 */
final class EcommerceFormatConverter
{
    /** @var list<string> All supported provider identifiers */
    private const PROVIDERS = [
        'ga4', 'meta', 'posthog', 'mixpanel', 'amplitude', 'plausible', 'tiktok', 'linkedin',
    ];

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
     * Convert an event payload to PostHog format.
     *
     * PostHog uses flat event properties with $-prefixed special fields.
     * Items use `sku`, `name`, `category`, `price`, `quantity`, `variant`, `brand`.
     * Currency is passed as `$currency`.
     *
     * @param  string  $eventName  Canonical catalog event name
     * @param  array<string, mixed>  $params  Event parameters
     * @return array{event: string, properties: array<string, mixed>}  PostHog-formatted payload
     */
    public function toPostHog(string $eventName, array $params): array
    {
        $entry = EventCatalog::get($eventName);
        $posthogName = $entry['posthog'] ?? $eventName;

        $properties = [];

        // Standard e-commerce params
        if (isset($params['value'])) {
            $properties['value'] = (float) $params['value'];
        }
        if (isset($params['currency'])) {
            $properties['$currency'] = (string) $params['currency'];
        }
        if (isset($params['transaction_id'])) {
            $properties['transaction_id'] = (string) $params['transaction_id'];
        }
        if (isset($params['coupon'])) {
            $properties['coupon'] = (string) $params['coupon'];
        }
        if (isset($params['tax'])) {
            $properties['tax'] = (float) $params['tax'];
        }
        if (isset($params['shipping'])) {
            $properties['shipping'] = (float) $params['shipping'];
        }

        // Convert items to PostHog format
        if (isset($params['items']) && is_array($params['items'])) {
            $properties = array_merge($properties, SupportConverter::ga4ToPosthogProperties($params['items']));
        }

        // Copy remaining params
        foreach ($params as $key => $value) {
            if (! isset($properties[$key]) && ! in_array($key, ['items', 'user_data', 'custom_properties'], true)) {
                $properties[$key] = $value;
            }
        }

        return [
            'event' => $posthogName,
            'properties' => $properties,
        ];
    }

    /**
     * Convert an event payload to Mixpanel format.
     *
     * Mixpanel uses flat event properties. Items are sent as `items` array
     * with `sku`, `name`, `price`, `quantity`, `category`, `variant`, `brand`.
     *
     * @param  string  $eventName  Canonical catalog event name
     * @param  array<string, mixed>  $params  Event parameters
     * @return array{event: string, properties: array<string, mixed>}  Mixpanel-formatted payload
     */
    public function toMixpanel(string $eventName, array $params): array
    {
        $entry = EventCatalog::get($eventName);
        $mixpanelName = $entry['mixpanel'] ?? $eventName;

        $properties = [];

        if (isset($params['value'])) {
            $properties['value'] = (float) $params['value'];
        }
        if (isset($params['currency'])) {
            $properties['currency'] = (string) $params['currency'];
        }
        if (isset($params['transaction_id'])) {
            $properties['transaction_id'] = (string) $params['transaction_id'];
        }
        if (isset($params['coupon'])) {
            $properties['coupon'] = (string) $params['coupon'];
        }
        if (isset($params['tax'])) {
            $properties['tax'] = (float) $params['tax'];
        }
        if (isset($params['shipping'])) {
            $properties['shipping'] = (float) $params['shipping'];
        }

        // Convert items to Mixpanel format via Support converter
        if (isset($params['items']) && is_array($params['items'])) {
            $properties = array_merge($properties, SupportConverter::ga4ToMixpanelProperties($params['items']));
        }

        // Copy remaining params
        foreach ($params as $key => $value) {
            if (! isset($properties[$key]) && ! in_array($key, ['items', 'user_data', 'custom_properties'], true)) {
                $properties[$key] = $value;
            }
        }

        return [
            'event' => $mixpanelName,
            'properties' => $properties,
        ];
    }

    /**
     * Convert an event payload to Amplitude format.
     *
     * Amplitude uses event properties with Revenue objects for monetary values.
     * Items use `sku`, `name`, `category`, `price`, `quantity`, `variant`, `brand`.
     *
     * @param  string  $eventName  Canonical catalog event name
     * @param  array<string, mixed>  $params  Event parameters
     * @return array{event_type: string, event_properties: array<string, mixed>}  Amplitude-formatted payload
     */
    public function toAmplitude(string $eventName, array $params): array
    {
        $entry = EventCatalog::get($eventName);
        $amplitudeName = $entry['amplitude'] ?? $eventName;

        $eventProperties = [];

        if (isset($params['value'])) {
            $eventProperties['value'] = (float) $params['value'];
        }
        if (isset($params['currency'])) {
            $eventProperties['currency'] = (string) $params['currency'];
        }
        if (isset($params['transaction_id'])) {
            $eventProperties['transaction_id'] = (string) $params['transaction_id'];
        }
        if (isset($params['coupon'])) {
            $eventProperties['coupon'] = (string) $params['coupon'];
        }

        // Convert items to Amplitude format via Support converter
        if (isset($params['items']) && is_array($params['items'])) {
            $properties = SupportConverter::ga4ToAmplitudeProperties($params['items']);
            $eventProperties['items'] = $properties['items'] ?? [];
            $eventProperties['total_value'] = $properties['total_value'] ?? 0.0;
        }

        // Copy remaining params
        foreach ($params as $key => $value) {
            if (! isset($eventProperties[$key]) && ! in_array($key, ['items', 'user_data', 'custom_properties'], true)) {
                $eventProperties[$key] = $value;
            }
        }

        return [
            'event_type' => $amplitudeName,
            'event_properties' => $eventProperties,
        ];
    }

    /**
     * Convert an event payload to Plausible format.
     *
     * Plausible uses a simple {name, props, url, referrer, domain} structure.
     * Revenue is sent as `revenue` prop. Items are sent as JSON-encoded `items` prop.
     *
     * @param  string  $eventName  Canonical catalog event name
     * @param  array<string, mixed>  $params  Event parameters
     * @return array{name: string, props: array<string, mixed>}  Plausible-formatted payload
     */
    public function toPlausible(string $eventName, array $params): array
    {
        $entry = EventCatalog::get($eventName);
        $plausibleName = $entry['plausible'] ?? $eventName;

        // Plausible falls back to canonical name if no mapping
        if ($plausibleName === null) {
            $plausibleName = $eventName;
        }

        $props = [];

        if (isset($params['value'])) {
            $props['revenue'] = (float) ($params['value'] * 100); // Plausible expects cents
        }
        if (isset($params['currency'])) {
            $props['currency'] = (string) $params['currency'];
        }
        if (isset($params['transaction_id'])) {
            $props['transaction_id'] = (string) $params['transaction_id'];
        }
        if (isset($params['coupon'])) {
            $props['coupon'] = (string) $params['coupon'];
        }

        // Plausible stores items as a JSON-encoded string in props
        if (isset($params['items']) && is_array($params['items']) && $params['items'] !== []) {
            $props['items'] = json_encode($params['items'], JSON_THROW_ON_ERROR);
            $props['num_items'] = count($params['items']);
        }

        // Copy remaining params
        foreach ($params as $key => $value) {
            if (! isset($props[$key]) && ! in_array($key, ['items', 'user_data', 'custom_properties'], true)) {
                $props[$key] = $value;
            }
        }

        return [
            'name' => $plausibleName,
            'props' => $props,
        ];
    }

    /**
     * Convert an event payload to TikTok Pixel format.
     *
     * TikTok uses flat event properties with `contents` array (id, quantity, price),
     * `content_type`, `content_id`, `value`, and `currency`.
     *
     * @param  string  $eventName  Canonical catalog event name
     * @param  array<string, mixed>  $params  Event parameters
     * @return array{event: string, properties: array<string, mixed>}  TikTok-formatted payload
     */
    public function toTikTok(string $eventName, array $params): array
    {
        $entry = EventCatalog::get($eventName);
        $tiktokName = $entry['tiktok'] ?? null;

        // TikTok falls back to canonical name if no mapping
        if ($tiktokName === null) {
            $tiktokName = $eventName;
        }

        $properties = [];

        if (isset($params['value'])) {
            $properties['value'] = (float) $params['value'];
        }
        if (isset($params['currency'])) {
            $properties['currency'] = (string) $params['currency'];
        }
        if (isset($params['transaction_id'])) {
            $properties['content_id'] = (string) $params['transaction_id'];
        }
        if (isset($params['coupon'])) {
            $properties['coupon'] = (string) $params['coupon'];
        }

        // Convert items to TikTok format via Support converter
        if (isset($params['items']) && is_array($params['items']) && $params['items'] !== []) {
            $tiktokProps = SupportConverter::ga4ToTiktokProperties($params['items']);
            $properties['contents'] = $tiktokProps['contents'] ?? [];
            $properties['content_type'] = 'product';
            if (isset($tiktokProps['content_id']) && $tiktokProps['content_id'] !== '') {
                $properties['content_id'] = $tiktokProps['content_id'];
            }
        } elseif (isset($params['item_id'])) {
            // Single item shorthand
            $properties['content_id'] = (string) $params['item_id'];
            $properties['content_type'] = 'product';
        }

        // Copy remaining params
        foreach ($params as $key => $value) {
            if (! isset($properties[$key]) && ! in_array($key, ['items', 'user_data', 'custom_properties', 'transaction_id', 'item_id'], true)) {
                $properties[$key] = $value;
            }
        }

        return [
            'event' => $tiktokName,
            'properties' => $properties,
        ];
    }

    /**
     * Convert an event payload to LinkedIn Insight Tag format.
     *
     * LinkedIn uses flat event properties with `currency`, and a simple
     * `conversionValue` for revenue. Items are passed as a JSON-encoded string.
     *
     * @param  string  $eventName  Canonical catalog event name
     * @param  array<string, mixed>  $params  Event parameters
     * @return array{event: string, properties: array<string, mixed>}  LinkedIn-formatted payload
     */
    public function toLinkedIn(string $eventName, array $params): array
    {
        $entry = EventCatalog::get($eventName);
        $linkedinName = $entry['linkedin'] ?? null;

        // LinkedIn falls back to canonical name if no mapping
        if ($linkedinName === null) {
            $linkedinName = $eventName;
        }

        $properties = [];

        if (isset($params['value'])) {
            $properties['conversionValue'] = (float) $params['value'];
        }
        if (isset($params['currency'])) {
            $properties['currency'] = (string) $params['currency'];
        }
        if (isset($params['transaction_id'])) {
            $properties['transactionId'] = (string) $params['transaction_id'];
        }

        // LinkedIn stores items as a JSON-encoded array in properties
        if (isset($params['items']) && is_array($params['items']) && $params['items'] !== []) {
            $properties['items'] = json_encode($params['items'], JSON_THROW_ON_ERROR);
            $properties['numItems'] = count($params['items']);
        }

        // Copy remaining params
        foreach ($params as $key => $value) {
            if (! isset($properties[$key]) && ! in_array($key, ['items', 'user_data', 'custom_properties', 'transaction_id'], true)) {
                $properties[$key] = $value;
            }
        }

        return [
            'event' => $linkedinName,
            'properties' => $properties,
        ];
    }

    /**
     * Universal provider dispatcher.
     *
     * Converts an event payload to any supported provider format.
     * Uses the provider identifier as the first argument.
     *
     * @param  'ga4'|'meta'|'posthog'|'mixpanel'|'amplitude'|'plausible'|'tiktok'|'linkedin'  $provider  Target provider
     * @param  string  $eventName  Canonical catalog event name
     * @param  array<string, mixed>  $params  Event parameters
     * @return array<string, mixed>  Provider-formatted payload
     *
     * @throws \InvalidArgumentException  If provider is not supported
     */
    public function toProvider(string $provider, string $eventName, array $params): array
    {
        return match ($provider) {
            'ga4' => $this->toGa4($eventName, $params),
            'meta' => $this->toMeta($eventName, $params),
            'posthog' => $this->toPostHog($eventName, $params),
            'mixpanel' => $this->toMixpanel($eventName, $params),
            'amplitude' => $this->toAmplitude($eventName, $params),
            'plausible' => $this->toPlausible($eventName, $params),
            'tiktok' => $this->toTikTok($eventName, $params),
            'linkedin' => $this->toLinkedIn($eventName, $params),
            default => throw new \InvalidArgumentException(
                "Unsupported provider: {$provider}. Supported: " . implode(', ', self::PROVIDERS),
            ),
        };
    }

    /**
     * Convert an event payload to all 8 provider formats in one call.
     *
     * Returns a keyed array where each key is a provider identifier and
     * each value is the provider-formatted payload. Useful for:
     * - Server-side multi-provider event forwarding
     * - Event replay across providers
     * - Multi-provider payload generation for CAPI
     *
     * @param  string  $eventName  Canonical catalog event name
     * @param  array<string, mixed>  $params  Event parameters
     * @return array{ga4: array<string, mixed>, meta: array<string, mixed>, posthog: array<string, mixed>, mixpanel: array<string, mixed>, amplitude: array<string, mixed>, plausible: array<string, mixed>, tiktok: array<string, mixed>, linkedin: array<string, mixed>}
     */
    public function toAllProviders(string $eventName, array $params): array
    {
        return [
            'ga4' => $this->toGa4($eventName, $params),
            'meta' => $this->toMeta($eventName, $params),
            'posthog' => $this->toPostHog($eventName, $params),
            'mixpanel' => $this->toMixpanel($eventName, $params),
            'amplitude' => $this->toAmplitude($eventName, $params),
            'plausible' => $this->toPlausible($eventName, $params),
            'tiktok' => $this->toTikTok($eventName, $params),
            'linkedin' => $this->toLinkedIn($eventName, $params),
        ];
    }

    /**
     * Check which providers have a native catalog mapping for this event.
     *
     * @param  string  $eventName  Canonical catalog event name
     * @return array{provider: string, mapped: bool, provider_name: string|null}[]
     */
    public function providerMappingStatus(string $eventName): array
    {
        $entry = EventCatalog::get($eventName);

        return array_map(fn (string $provider): array => [
            'provider' => $provider,
            'mapped' => ($entry[$provider] ?? null) !== null,
            'provider_name' => $entry[$provider] ?? null,
        ], self::PROVIDERS);
    }

    /**
     * Check if an event has full 8-provider support.
     *
     * An event has full support when every provider has a non-null mapping
     * in the event catalog. E-commerce events like `purchase` and `add_to_cart`
     * should have full support, while niche events may not.
     *
     * @param  string  $eventName  Canonical catalog event name
     */
    public function hasFullProviderSupport(string $eventName): bool
    {
        $entry = EventCatalog::get($eventName);

        foreach (self::PROVIDERS as $provider) {
            if (($entry[$provider] ?? null) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the list of supported provider identifiers.
     *
     * @return list<string>
     */
    public function supportedProviders(): array
    {
        return self::PROVIDERS;
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
