<?php

declare(strict_types=1);

/**
 * @license MIT
 *
 * Tests for v254.0.0 — EcommerceFormatConverter multi-provider expansion.
 *
 * Validates:
 * - Meta → GA4 reverse conversions (ViewContent, AddToCart, InitiateCheckout, AddPaymentInfo, AddToWishlist)
 * - buildForAllProviders expanded coverage for view_item, add_to_cart, begin_checkout, add_payment_info, add_to_wishlist
 * - fromGa4Format uses new reverse conversion methods
 * - supportedEventTypes() and hasFullProviderSupport() utility methods
 * - File quality checks (strict_types, MIT header, final class, @since annotations)
 * - Version consistency
 *
 * @since 254.0.0
 */

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;

test('EcommerceFormatConverter has strict_types and MIT header', function (): void {
    $reflection = new ReflectionClass(EcommerceFormatConverter::class);
    $content = file_get_contents($reflection->getFileName());
    expect($content)->toContain('declare(strict_types=1)');
    expect($content)->toContain('MIT');
});

test('metaToGa4View converts ViewContent to view_item format', function (): void {
    $result = EcommerceFormatConverter::metaToGa4View([
        'currency' => 'EUR',
        'value' => 29.99,
        'content_name' => 'Widget Pro',
        'content_type' => 'product',
        'contents' => [
            ['id' => 'SKU-001', 'quantity' => 1, 'item_price' => 29.99],
        ],
    ]);

    expect($result)->toBeArray();
    expect($result)->toHaveKey('currency');
    expect($result)->toHaveKey('value');
    expect($result)->toHaveKey('items');
    expect($result['currency'])->toBe('EUR');
    expect($result['value'])->toBe(29.99);
    expect($result['content_name'])->toBe('Widget Pro');
    expect($result['items'])->toBeArray();
});

test('metaToGa4AddToCart converts AddToCart to add_to_cart format', function (): void {
    $result = EcommerceFormatConverter::metaToGa4AddToCart([
        'currency' => 'USD',
        'value' => 49.99,
        'contents' => [
            ['id' => 'SKU-002', 'quantity' => 2, 'item_price' => 24.99],
        ],
    ]);

    expect($result)->toBeArray();
    expect($result['currency'])->toBe('USD');
    expect($result['value'])->toBe(49.99);
    expect($result['items'])->toBeArray();
    expect(count($result['items']))->toBe(1);
});

test('metaToGa4BeginCheckout converts InitiateCheckout to begin_checkout format', function (): void {
    $result = EcommerceFormatConverter::metaToGa4BeginCheckout([
        'currency' => 'GBP',
        'value' => 99.97,
        'num_items' => 3,
        'contents' => [
            ['id' => 'SKU-003', 'quantity' => 1, 'item_price' => 33.32],
            ['id' => 'SKU-004', 'quantity' => 2, 'item_price' => 33.32],
        ],
    ]);

    expect($result)->toBeArray();
    expect($result['currency'])->toBe('GBP');
    expect($result['value'])->toBe(99.97);
    expect($result['num_items'])->toBe(3);
    expect($result['items'])->toBeArray();
});

test('metaToGa4AddPaymentInfo converts AddPaymentInfo to add_payment_info format', function (): void {
    $result = EcommerceFormatConverter::metaToGa4AddPaymentInfo([
        'currency' => 'USD',
        'value' => 150.00,
        'content_type' => 'product',
        'contents' => [
            ['id' => 'SKU-005', 'quantity' => 1, 'item_price' => 150.00],
        ],
    ]);

    expect($result)->toBeArray();
    expect($result['currency'])->toBe('USD');
    expect($result['value'])->toBe(150.0);
    expect($result['content_type'])->toBe('product');
    expect($result['items'])->toBeArray();
});

test('metaToGa4View handles empty contents gracefully', function (): void {
    $result = EcommerceFormatConverter::metaToGa4View([]);

    expect($result)->toBeArray();
    expect($result['currency'])->toBe('USD');
    expect($result['value'])->toBe(0.0);
    expect($result['items'])->toBe([]);
});

test('metaToGa4AddToCart handles empty contents gracefully', function (): void {
    $result = EcommerceFormatConverter::metaToGa4AddToCart([]);

    expect($result)->toBeArray();
    expect($result['items'])->toBe([]);
    expect($result['value'])->toBe(0.0);
});

test('fromGa4Format converts Meta ViewContent to GA4 view_item', function (): void {
    $result = EcommerceFormatConverter::fromGa4Format('meta', 'ViewContent', [
        'currency' => 'EUR',
        'value' => 29.99,
        'content_name' => 'Widget',
        'contents' => [
            ['id' => 'SKU-001', 'quantity' => 1, 'item_price' => 29.99],
        ],
    ]);

    expect($result['ga4_event'])->toBe('view_item');
    expect($result['ga4_params'])->toBeArray();
    expect($result['ga4_params']['currency'])->toBe('EUR');
});

test('fromGa4Format converts Meta InitiateCheckout to GA4 begin_checkout', function (): void {
    $result = EcommerceFormatConverter::fromGa4Format('meta', 'InitiateCheckout', [
        'currency' => 'USD',
        'value' => 99.99,
        'num_items' => 2,
        'contents' => [
            ['id' => 'SKU-001', 'quantity' => 2, 'item_price' => 49.99],
        ],
    ]);

    expect($result['ga4_event'])->toBe('begin_checkout');
    expect($result['ga4_params']['num_items'])->toBe(2);
});

test('fromGa4Format converts Meta AddPaymentInfo to GA4 add_payment_info', function (): void {
    $result = EcommerceFormatConverter::fromGa4Format('meta', 'AddPaymentInfo', [
        'currency' => 'USD',
        'value' => 49.99,
        'contents' => [
            ['id' => 'SKU-001', 'quantity' => 1, 'item_price' => 49.99],
        ],
    ]);

    expect($result['ga4_event'])->toBe('add_payment_info');
    expect($result['ga4_params'])->toBeArray();
});

test('fromGa4Format converts Meta AddToWishlist to GA4 add_to_wishlist', function (): void {
    $result = EcommerceFormatConverter::fromGa4Format('meta', 'AddToWishlist', [
        'currency' => 'USD',
        'value' => 29.99,
        'contents' => [
            ['id' => 'SKU-001', 'quantity' => 1, 'item_price' => 29.99],
        ],
    ]);

    expect($result['ga4_event'])->toBe('add_to_wishlist');
    expect($result['ga4_params'])->toBeArray();
    expect($result['ga4_params']['currency'])->toBe('USD');
});

test('fromGa4Format converts Meta Purchase to GA4 purchase (unchanged)', function (): void {
    $result = EcommerceFormatConverter::fromGa4Format('meta', 'Purchase', [
        'currency' => 'USD',
        'value' => 99.99,
        'content_ids' => ['SKU-001'],
        'contents' => [
            ['id' => 'SKU-001', 'quantity' => 1, 'item_price' => 99.99],
        ],
    ]);

    expect($result['ga4_event'])->toBe('purchase');
    expect($result['ga4_params'])->toBeArray();
});

test('fromGa4Format converts Meta AddToCart using new method', function (): void {
    $result = EcommerceFormatConverter::fromGa4Format('meta', 'AddToCart', [
        'currency' => 'USD',
        'value' => 49.99,
        'contents' => [
            ['id' => 'SKU-002', 'quantity' => 2, 'item_price' => 24.99],
        ],
    ]);

    expect($result['ga4_event'])->toBe('add_to_cart');
    expect($result['ga4_params'])->toBeArray();
    expect($result['ga4_params']['items'])->toBeArray();
});

test('buildForAllProviders returns Meta view_item conversion', function (): void {
    $ga4Params = [
        'currency' => 'USD',
        'value' => 29.99,
        'items' => [
            ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 29.99, 'quantity' => 1],
        ],
    ];

    $result = EcommerceFormatConverter::buildForAllProviders('view_item', $ga4Params);

    expect($result)->toHaveKey('meta');
    expect($result['meta'])->toBeArray();
    expect($result['meta'])->toHaveKey('content_type');
    expect($result['meta']['content_type'])->toBe('product');
});

test('buildForAllProviders returns Meta add_to_cart conversion', function (): void {
    $ga4Params = [
        'currency' => 'USD',
        'value' => 49.99,
        'items' => [
            ['item_id' => 'SKU-002', 'item_name' => 'Gadget', 'price' => 49.99, 'quantity' => 1],
        ],
    ];

    $result = EcommerceFormatConverter::buildForAllProviders('add_to_cart', $ga4Params);

    expect($result['meta'])->toBeArray();
    expect($result['meta'])->toHaveKey('contents');
});

test('buildForAllProviders returns Meta begin_checkout conversion', function (): void {
    $ga4Params = [
        'currency' => 'USD',
        'value' => 99.99,
        'items' => [
            ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 1],
            ['item_id' => 'SKU-002', 'item_name' => 'Gadget', 'price' => 50.00, 'quantity' => 1],
        ],
    ];

    $result = EcommerceFormatConverter::buildForAllProviders('begin_checkout', $ga4Params);

    expect($result['meta'])->toBeArray();
    expect($result['meta'])->toHaveKey('num_items');
    expect($result['meta']['num_items'])->toBe(2);
});

test('buildForAllProviders returns PostHog view_item conversion', function (): void {
    $ga4Params = [
        'currency' => 'EUR',
        'value' => 19.99,
        'items' => [
            ['item_id' => 'SKU-003', 'item_name' => 'Gizmo', 'price' => 19.99, 'quantity' => 1],
        ],
    ];

    $result = EcommerceFormatConverter::buildForAllProviders('view_item', $ga4Params);

    expect($result['posthog'])->toBeArray();
    expect($result['posthog'])->toHaveKey('$currency');
    expect($result['posthog']['$currency'])->toBe('EUR');
});

test('buildForAllProviders returns PostHog add_to_cart conversion', function (): void {
    $ga4Params = [
        'currency' => 'USD',
        'value' => 39.99,
        'items' => [
            ['item_id' => 'SKU-004', 'item_name' => 'Thing', 'price' => 39.99, 'quantity' => 1],
        ],
    ];

    $result = EcommerceFormatConverter::buildForAllProviders('add_to_cart', $ga4Params);

    expect($result['posthog'])->toBeArray();
    expect($result['posthog'])->toHaveKey('value');
});

test('buildForAllProviders returns PostHog begin_checkout conversion', function (): void {
    $ga4Params = [
        'currency' => 'USD',
        'value' => 79.98,
        'items' => [
            ['item_id' => 'SKU-001', 'item_name' => 'A', 'price' => 39.99, 'quantity' => 2],
        ],
    ];

    $result = EcommerceFormatConverter::buildForAllProviders('begin_checkout', $ga4Params);

    expect($result['posthog'])->toBeArray();
    expect($result['posthog'])->toHaveKey('value');
    expect($result['posthog'])->toHaveKey('items');
});

test('buildForAllProviders returns LinkedIn view_item with price', function (): void {
    $ga4Params = [
        'currency' => 'USD',
        'items' => [
            ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 29.99, 'quantity' => 1],
        ],
    ];

    $result = EcommerceFormatConverter::buildForAllProviders('view_item', $ga4Params);

    expect($result['linkedin'])->toBeArray();
    expect($result['linkedin']['value'])->toBe(29.99);
    expect($result['linkedin']['currency'])->toBe('USD');
});

test('buildForAllProviders returns LinkedIn begin_checkout with num_items', function (): void {
    $ga4Params = [
        'currency' => 'USD',
        'value' => 59.98,
        'items' => [
            ['item_id' => 'SKU-001', 'price' => 29.99, 'quantity' => 1],
            ['item_id' => 'SKU-002', 'price' => 29.99, 'quantity' => 1],
        ],
    ];

    $result = EcommerceFormatConverter::buildForAllProviders('begin_checkout', $ga4Params);

    expect($result['linkedin'])->toBeArray();
    expect($result['linkedin']['num_items'])->toBe(2);
});

test('buildForAllProviders returns LinkedIn add_payment_info', function (): void {
    $ga4Params = [
        'currency' => 'USD',
        'value' => 59.98,
        'items' => [
            ['item_id' => 'SKU-001', 'price' => 29.99, 'quantity' => 2],
        ],
    ];

    $result = EcommerceFormatConverter::buildForAllProviders('add_payment_info', $ga4Params);

    expect($result['linkedin'])->toBeArray();
    expect($result['linkedin']['value'])->toBe(59.98);
});

test('buildForAllProviders returns LinkedIn add_to_wishlist with first item price', function (): void {
    $ga4Params = [
        'currency' => 'USD',
        'items' => [
            ['item_id' => 'SKU-001', 'price' => 45.00, 'quantity' => 1],
        ],
    ];

    $result = EcommerceFormatConverter::buildForAllProviders('add_to_wishlist', $ga4Params);

    expect($result['linkedin'])->toBeArray();
    expect($result['linkedin']['value'])->toBe(45.0);
});

test('buildForAllProviders returns Meta add_payment_info conversion', function (): void {
    $ga4Params = [
        'currency' => 'USD',
        'value' => 149.99,
        'items' => [
            ['item_id' => 'SKU-001', 'item_name' => 'Premium', 'price' => 149.99, 'quantity' => 1],
        ],
    ];

    $result = EcommerceFormatConverter::buildForAllProviders('add_payment_info', $ga4Params);

    expect($result['meta'])->toBeArray();
    expect($result['meta'])->toHaveKey('content_type');
    expect($result['meta']['content_type'])->toBe('product');
    expect($result['meta'])->toHaveKey('contents');
});

test('buildForAllProviders returns Meta add_to_wishlist conversion', function (): void {
    $ga4Params = [
        'currency' => 'USD',
        'value' => 29.99,
        'items' => [
            ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 29.99, 'quantity' => 1],
        ],
    ];

    $result = EcommerceFormatConverter::buildForAllProviders('add_to_wishlist', $ga4Params);

    expect($result['meta'])->toBeArray();
    expect($result['meta'])->toHaveKey('content_type');
    expect($result['meta']['content_type'])->toBe('product');
    expect($result['meta'])->toHaveKey('content_ids');
});

test('buildForAllProviders has all 8 provider keys', function (): void {
    $ga4Params = [
        'currency' => 'USD',
        'value' => 0,
        'items' => [],
    ];

    $result = EcommerceFormatConverter::buildForAllProviders('purchase', $ga4Params);

    expect($result)->toHaveKeys([
        'ga4', 'meta', 'posthog', 'mixpanel', 'amplitude', 'plausible', 'tiktok', 'linkedin',
    ]);
});

test('supportedEventTypes returns 15 event types', function (): void {
    $types = EcommerceFormatConverter::supportedEventTypes();

    expect($types)->toBeArray();
    expect(count($types))->toBe(15);
    expect($types)->toContain('purchase');
    expect($types)->toContain('view_item');
    expect($types)->toContain('add_to_cart');
    expect($types)->toContain('begin_checkout');
    expect($types)->toContain('add_payment_info');
    expect($types)->toContain('add_to_wishlist');
    expect($types)->toContain('refund');
    expect($types)->toContain('select_item');
    expect($types)->toContain('abandoned_cart');
});

test('hasFullProviderSupport returns true for fully-supported events', function (): void {
    expect(EcommerceFormatConverter::hasFullProviderSupport('purchase'))->toBeTrue();
    expect(EcommerceFormatConverter::hasFullProviderSupport('view_item'))->toBeTrue();
    expect(EcommerceFormatConverter::hasFullProviderSupport('add_to_cart'))->toBeTrue();
    expect(EcommerceFormatConverter::hasFullProviderSupport('begin_checkout'))->toBeTrue();
    expect(EcommerceFormatConverter::hasFullProviderSupport('add_payment_info'))->toBeTrue();
    expect(EcommerceFormatConverter::hasFullProviderSupport('add_to_wishlist'))->toBeTrue();
    expect(EcommerceFormatConverter::hasFullProviderSupport('refund'))->toBeTrue();
});

test('hasFullProviderSupport returns false for catalog-only events', function (): void {
    expect(EcommerceFormatConverter::hasFullProviderSupport('remove_from_cart'))->toBeFalse();
    expect(EcommerceFormatConverter::hasFullProviderSupport('view_cart'))->toBeFalse();
    expect(EcommerceFormatConverter::hasFullProviderSupport('select_item'))->toBeFalse();
    expect(EcommerceFormatConverter::hasFullProviderSupport('abandoned_cart'))->toBeFalse();
    expect(EcommerceFormatConverter::hasFullProviderSupport('unknown_event'))->toBeFalse();
});

test('version consistency across entry points', function (): void {
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('254.0.0');

    $package = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);
    expect($package['version'])->toBe('254.0.0');

    expect(AnalyticsEvent::VERSION)->toBe('254.0.0');
});

test('EcommerceFormatConverter file quality checks', function (): void {
    $reflection = new ReflectionClass(EcommerceFormatConverter::class);
    $content = file_get_contents($reflection->getFileName());

    // strict_types
    expect($content)->toContain('declare(strict_types=1)');

    // MIT header
    expect($content)->toContain('MIT');

    // @since annotations on new methods
    expect($content)->toContain('@since 254.0.0');

    // New methods exist
    expect(method_exists(EcommerceFormatConverter::class, 'metaToGa4View'))->toBeTrue();
    expect(method_exists(EcommerceFormatConverter::class, 'metaToGa4AddToCart'))->toBeTrue();
    expect(method_exists(EcommerceFormatConverter::class, 'metaToGa4BeginCheckout'))->toBeTrue();
    expect(method_exists(EcommerceFormatConverter::class, 'metaToGa4AddPaymentInfo'))->toBeTrue();
    expect(method_exists(EcommerceFormatConverter::class, 'supportedEventTypes'))->toBeTrue();
    expect(method_exists(EcommerceFormatConverter::class, 'hasFullProviderSupport'))->toBeTrue();

    // Return type declarations
    $metaToGa4View = new ReflectionMethod(EcommerceFormatConverter::class, 'metaToGa4View');
    expect($metaToGa4View->getReturnType()?->getName())->toBe('array');

    $supportedTypes = new ReflectionMethod(EcommerceFormatConverter::class, 'supportedEventTypes');
    expect($supportedTypes->getReturnType()?->getName())->toBe('array');

    $hasFullSupport = new ReflectionMethod(EcommerceFormatConverter::class, 'hasFullProviderSupport');
    expect($hasFullSupport->getReturnType()?->getName())->toBe('bool');
});
