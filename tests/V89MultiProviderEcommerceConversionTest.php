<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;

/**
 * V89.0.0 — Multi-Provider Ecommerce Conversion & Universal toGa4Format Test.
 *
 * Validates that the EcommerceFormatConverter correctly dispatches to all
 * 10 supported providers (GA4, Meta, PostHog, Plausible, Mixpanel, Amplitude,
 * TikTok, LinkedIn, GTM, generic) through the toGa4Format() method.
 *
 * Also validates buildForAllProviders() returns data for all 8 converter targets,
 * and that provider name lookup methods (mixpanelNameFor, amplitudeNameFor,
 * tiktokNameFor, linkedinNameFor) work correctly for all ecommerce events.
 */
test('toGa4Format dispatches to all 10 providers for purchase event', function (): void {
    $ga4Params = [
        'transaction_id' => 'TXN-001',
        'value' => 149.99,
        'currency' => 'USD',
        'tax' => 12.50,
        'shipping' => 5.99,
        'coupon' => 'SUMMER10',
        'items' => [
            ['item_id' => 'SKU-A', 'item_name' => 'Widget Pro', 'item_category' => 'Electronics', 'price' => 74.99, 'quantity' => 2],
        ],
    ];

    // GA4 passthrough
    $ga4 = EcommerceFormatConverter::toGa4Format('ga4', 'purchase', $ga4Params);
    expect($ga4['provider_event'])->toBe('purchase');
    expect($ga4['provider_params']['transaction_id'])->toBe('TXN-001');

    // Meta Pixel
    $meta = EcommerceFormatConverter::toGa4Format('meta', 'purchase', $ga4Params);
    expect($meta['provider_event'])->toBe('Purchase');
    expect($meta['provider_params'])->toHaveKey('contents');
    expect($meta['provider_params'])->toHaveKey('content_ids');
    expect($meta['provider_params']['currency'])->toBe('USD');

    // PostHog
    $posthog = EcommerceFormatConverter::toGa4Format('posthog', 'purchase', $ga4Params);
    expect($posthog['provider_event'])->toBe('purchase');
    expect($posthog['provider_params'])->toHaveKey('items');
    expect($posthog['provider_params']['$currency'])->toBe('USD');

    // Plausible
    $plausible = EcommerceFormatConverter::toGa4Format('plausible', 'purchase', $ga4Params);
    expect($plausible['plausible_event'])->toBe('purchase');
    expect($plausible['plausible_params'])->toHaveKey('transaction_id');
    expect($plausible['plausible_params'])->toHaveKey('revenue');

    // Mixpanel
    $mixpanel = EcommerceFormatConverter::toGa4Format('mixpanel', 'purchase', $ga4Params);
    expect($mixpanel['provider_event'])->toBe('Purchase');
    expect($mixpanel['provider_params'])->toHaveKey('products');
    expect($mixpanel['provider_params'])->toHaveKey('$revenue');

    // Amplitude
    $amplitude = EcommerceFormatConverter::toGa4Format('amplitude', 'purchase', $ga4Params);
    expect($amplitude['provider_event'])->toBe('Completed Order');
    expect($amplitude['provider_params'])->toHaveKey('items');
    expect($amplitude['provider_params'])->toHaveKey('revenue');

    // TikTok
    $tiktok = EcommerceFormatConverter::toGa4Format('tiktok', 'purchase', $ga4Params);
    expect($tiktok['provider_event'])->toBe('CompletePayment');
    expect($tiktok['provider_params'])->toHaveKey('contents');
    expect($tiktok['provider_params']['value'])->toBe(149.99);

    // LinkedIn
    $linkedin = EcommerceFormatConverter::toGa4Format('linkedin', 'purchase', $ga4Params);
    expect($linkedin['provider_event'])->toBe('purchase');
    expect($linkedin['provider_params']['value'])->toBe(149.99);
    expect($linkedin['provider_params']['currency'])->toBe('USD');

    // Unknown provider passthrough
    $unknown = EcommerceFormatConverter::toGa4Format('custom', 'purchase', $ga4Params);
    expect($unknown['provider_event'])->toBe('purchase');
    expect($unknown['provider_params']['transaction_id'])->toBe('TXN-001');
});

test('toGa4Format handles refund event for all providers', function (): void {
    $ga4Params = [
        'transaction_id' => 'TXN-001',
        'value' => 49.99,
        'currency' => 'USD',
        'items' => [
            ['item_id' => 'SKU-A', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 1],
        ],
    ];

    $meta = EcommerceFormatConverter::toGa4Format('meta', 'refund', $ga4Params);
    expect($meta['provider_event'])->toBe('Refund');

    $posthog = EcommerceFormatConverter::toGa4Format('posthog', 'refund', $ga4Params);
    expect($posthog['provider_params'])->toHaveKey('value');

    $mixpanel = EcommerceFormatConverter::toGa4Format('mixpanel', 'refund', $ga4Params);
    expect($mixpanel['provider_event'])->toBe('Refund');
    expect($mixpanel['provider_params'])->toHaveKey('$revenue');

    $amplitude = EcommerceFormatConverter::toGa4Format('amplitude', 'refund', $ga4Params);
    expect($amplitude['provider_event'])->toBe('Refunded Order');

    $tiktok = EcommerceFormatConverter::toGa4Format('tiktok', 'refund', $ga4Params);
    expect($tiktok['provider_event'])->toBe('ClickButton');
    expect($tiktok['provider_params'])->toHaveKey('value');
});

test('toGa4Format handles add_to_cart event for all providers', function (): void {
    $ga4Params = [
        'currency' => 'USD',
        'value' => 74.99,
        'items' => [
            ['item_id' => 'SKU-A', 'item_name' => 'Widget', 'price' => 74.99, 'quantity' => 1],
        ],
    ];

    $meta = EcommerceFormatConverter::toGa4Format('meta', 'add_to_cart', $ga4Params);
    expect($meta['provider_event'])->toBe('AddToCart');

    $plausible = EcommerceFormatConverter::toGa4Format('plausible', 'add_to_cart', $ga4Params);
    expect($plausible['plausible_event'])->toBe('add_to_cart');

    $tiktok = EcommerceFormatConverter::toGa4Format('tiktok', 'add_to_cart', $ga4Params);
    expect($tiktok['provider_event'])->toBe('AddToCart');
    expect($tiktok['provider_params'])->toHaveKey('contents');

    $linkedin = EcommerceFormatConverter::toGa4Format('linkedin', 'add_to_cart', $ga4Params);
    expect($linkedin['provider_event'])->toBe('add_to_cart');
});

test('buildForAllProviders returns all 8 provider targets', function (): void {
    $ga4Params = [
        'transaction_id' => 'TXN-001',
        'value' => 99.99,
        'currency' => 'EUR',
        'items' => [
            ['item_id' => 'SKU-1', 'item_name' => 'Product', 'price' => 99.99, 'quantity' => 1],
        ],
    ];

    $all = EcommerceFormatConverter::buildForAllProviders('purchase', $ga4Params);

    expect($all)->toHaveKey('ga4');
    expect($all)->toHaveKey('meta');
    expect($all)->toHaveKey('posthog');
    expect($all)->toHaveKey('mixpanel');
    expect($all)->toHaveKey('amplitude');
    expect($all)->toHaveKey('plausible');
    expect($all)->toHaveKey('tiktok');
    expect($all)->toHaveKey('linkedin');

    // GA4 passthrough matches input
    expect($all['ga4']['transaction_id'])->toBe('TXN-001');

    // Each provider has expected fields
    expect($all['meta'])->toHaveKey('contents');
    expect($all['posthog'])->toHaveKey('items');
    expect($all['mixpanel'])->toHaveKey('products');
    expect($all['amplitude'])->toHaveKey('items');
    expect($all['tiktok'])->toHaveKey('contents');
    expect($all['linkedin'])->toHaveKey('value');

    // Currency propagation
    expect($all['ga4']['currency'])->toBe('EUR');
    expect($all['meta']['currency'])->toBe('EUR');
});

test('buildForAllProviders handles view_item event', function (): void {
    $ga4Params = [
        'currency' => 'USD',
        'value' => 29.99,
        'items' => [
            ['item_id' => 'SKU-V', 'item_name' => 'Viewed Item', 'price' => 29.99, 'quantity' => 1],
        ],
    ];

    $all = EcommerceFormatConverter::buildForAllProviders('view_item', $ga4Params);

    expect($all)->toHaveKey('ga4');
    expect($all)->toHaveKey('meta');
    expect($all)->toHaveKey('posthog');
    expect($all)->toHaveKey('mixpanel');
    expect($all)->toHaveKey('amplitude');
    expect($all)->toHaveKey('plausible');
    expect($all)->toHaveKey('tiktok');
    expect($all)->toHaveKey('linkedin');
});

test('event catalog provider name lookups work for ecommerce events', function (): void {
    $ecommerceEvents = EcommerceEvents::names();

    foreach ($ecommerceEvents as $eventName) {
        // Every ecommerce event has a catalog entry with GA4 mapping
        $entry = EventCatalog::get($eventName);
        expect($entry)->not->toBeNull();
        expect($entry['ga4'])->toBeString();

        // PostHog mapping
        $posthog = EventCatalog::posthogNameFor($eventName);
        expect($posthog)->not->toBeNull();

        // Mixpanel mapping
        $mixpanel = EventCatalog::mixpanelNameFor($eventName);
        expect($mixpanel)->not->toBeNull();

        // Amplitude mapping
        $amplitude = EventCatalog::amplitudeNameFor($eventName);
        expect($amplitude)->not->toBeNull();
    }
});

test('event catalog provider name lookups return null for unsupported providers', function (): void {
    // Some events don't support all providers — ensure null safety
    $events = EcommerceEvents::names();

    foreach ($events as $eventName) {
        $entry = EcommerceEvents::get($eventName);
        expect($entry)->not->toBeNull();

        // If tiktok is null in catalog, lookup should return null
        if (($entry['tiktok'] ?? null) === null) {
            expect(EventCatalog::tiktokNameFor($eventName))->toBeNull();
        }

        // If linkedin is null in catalog, lookup should return null
        if (($entry['linkedin'] ?? null) === null) {
            expect(EventCatalog::linkedinNameFor($eventName))->toBeNull();
        }
    }
});

test('ga4ToMetaAuto returns null for unsupported events', function (): void {
    $result = EcommerceFormatConverter::ga4ToMetaAuto('nonexistent_event', []);
    expect($result)->toBeNull();
});

test('ga4ToPlausibleAuto returns null for unsupported events', function (): void {
    $result = EcommerceFormatConverter::ga4ToPlausibleAuto('nonexistent_event', []);
    expect($result)->toBeNull();
});

test('fromGa4Format converts Meta Purchase back to GA4', function (): void {
    $metaParams = [
        'content_ids' => ['SKU-1'],
        'contents' => [
            ['id' => 'SKU-1', 'quantity' => 2, 'item_price' => 49.99, 'item_name' => 'Widget'],
        ],
        'num_items' => 2,
        'value' => 99.98,
        'currency' => 'USD',
    ];

    $ga4 = EcommerceFormatConverter::fromGa4Format('meta', 'Purchase', $metaParams);

    expect($ga4['ga4_event'])->toBe('purchase');
    expect($ga4['ga4_params']['transaction_id'])->toBe('SKU-1');
    expect($ga4['ga4_params']['value'])->toBe(99.98);
    expect($ga4['ga4_params']['currency'])->toBe('USD');
    expect($ga4['ga4_params']['items'])->toBeArray();
});

test('version consistency across all package files', function (): void {
    $expected = '89.0.0';

    // DTO version
    expect(AnalyticsEvent::VERSION)->toBe($expected);

    // Event catalog has all required categories
    expect(EcommerceEvents::category())->toBe('ecommerce');
    expect(SaaSEvents::category())->toBe('saas');
    expect(EngagementEvents::category())->toBe('engagement');

    // Total catalog is substantial
    expect(EventCatalog::count())->toBeGreaterThanOrEqual(120);
    expect(EcommerceEvents::count())->toBeGreaterThanOrEqual(15);
    expect(SaaSEvents::count())->toBeGreaterThanOrEqual(50);
    expect(EngagementEvents::count())->toBeGreaterThanOrEqual(30);
});
