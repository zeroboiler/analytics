<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsIntegrityCommand;

/**
 * v18.0.0 — Version sweep alignment, JS client version, package.json sync,
 * EcommerceFormatConverter Mixpanel/Amplitude converters, and Meta mapping coverage.
 *
 * @covers \ZeroBoiler\Analytics\Support\EcommerceFormatConverter
 * @covers \ZeroBoiler\Analytics\DTO\AnalyticsEvent
 * @covers \ZeroBoiler\Analytics\Events\EventCatalog
 */
test('AnalyticsEvent VERSION is 18.0.0', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('18.0.0');
});

test('composer.json version is 18.0.0', function (): void {
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('18.0.0');
});

test('package.json version is 18.0.0', function (): void {
    $pkg = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);
    expect($pkg['version'])->toBe('18.0.0');
});

test('JS client getVersion returns 18.0.0', function (): void {
    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($js)->toContain("return '18.0.0'");
    // Ensure old version is not present
    expect($js)->not->toContain("return '17.0.0'");
});

test('AnalyticsIntegrityCommand EXPECTED_VERSION is 18.0.0', function (): void {
    $reflection = new ReflectionClass(AnalyticsIntegrityCommand::class);
    $constant = $reflection->getConstant('EXPECTED_VERSION');
    expect($constant)->toBe('18.0.0');
});

test('EcommerceFormatConverter ga4ToMixpanelProperties converts items correctly', function (): void {
    $items = [
        ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 29.99, 'quantity' => 2, 'item_variant' => 'blue'],
    ];

    $result = EcommerceFormatConverter::ga4ToMixpanelProperties($items);

    expect($result['product_count'])->toBe(1);
    expect($result['total_revenue'])->toBe(59.98);
    expect($result['products'][0]['$product_id'])->toBe('SKU-001');
    expect($result['products'][0]['$name'])->toBe('Widget');
    expect($result['products'][0]['$price'])->toBe(29.99);
    expect($result['products'][0]['$quantity'])->toBe(2);
    expect($result['products'][0]['$variant'])->toBe('blue');
});

test('EcommerceFormatConverter ga4ToMixpanelPurchase adds revenue fields', function (): void {
    $ga4Params = [
        'transaction_id' => 'TXN-123',
        'value' => 59.98,
        'currency' => 'EUR',
        'items' => [
            ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 29.99, 'quantity' => 2],
        ],
    ];

    $result = EcommerceFormatConverter::ga4ToMixpanelPurchase($ga4Params);

    expect($result['$revenue'])->toBe(59.98);
    expect($result['$currency'])->toBe('EUR');
    expect($result['$transaction_id'])->toBe('TXN-123');
    expect($result['products'])->toHaveCount(1);
});

test('EcommerceFormatConverter ga4ToMixpanelRefund converts correctly', function (): void {
    $ga4Params = [
        'transaction_id' => 'TXN-123',
        'value' => 29.99,
        'currency' => 'USD',
        'items' => [
            ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 29.99, 'quantity' => 1],
        ],
    ];

    $result = EcommerceFormatConverter::ga4ToMixpanelRefund($ga4Params);

    expect($result['$revenue'])->toBe(29.99);
    expect($result['$currency'])->toBe('USD');
    expect($result['$transaction_id'])->toBe('TXN-123');
});

test('EcommerceFormatConverter ga4ToAmplitudeProperties converts items correctly', function (): void {
    $items = [
        ['item_id' => 'SKU-001', 'item_name' => 'Gadget', 'price' => 49.99, 'quantity' => 3],
    ];

    $result = EcommerceFormatConverter::ga4ToAmplitudeProperties($items);

    expect($result['item_count'])->toBe(1);
    expect($result['total_amount'])->toBe(149.97);
    expect($result['items'][0]['productId'])->toBe('SKU-001');
    expect($result['items'][0]['productName'])->toBe('Gadget');
    expect($result['items'][0]['price'])->toBe(49.99);
    expect($result['items'][0]['quantity'])->toBe(3);
});

test('EcommerceFormatConverter ga4ToAmplitudePurchase adds revenue fields', function (): void {
    $ga4Params = [
        'transaction_id' => 'TXN-456',
        'value' => 149.97,
        'currency' => 'GBP',
        'coupon' => 'SAVE10',
        'tax' => 12.50,
        'shipping' => 5.99,
        'items' => [
            ['item_id' => 'SKU-001', 'item_name' => 'Gadget', 'price' => 49.99, 'quantity' => 3],
        ],
    ];

    $result = EcommerceFormatConverter::ga4ToAmplitudePurchase($ga4Params);

    expect($result['revenue'])->toBe(149.97);
    expect($result['currency'])->toBe('GBP');
    expect($result['transactionId'])->toBe('TXN-456');
    expect($result['coupon'])->toBe('SAVE10');
    expect($result['tax'])->toBe(12.50);
    expect($result['shipping'])->toBe(5.99);
});

test('EcommerceFormatConverter ga4ToAmplitudeRefund converts correctly', function (): void {
    $ga4Params = [
        'transaction_id' => 'TXN-456',
        'value' => 49.99,
        'currency' => 'USD',
        'items' => [
            ['item_id' => 'SKU-001', 'item_name' => 'Gadget', 'price' => 49.99, 'quantity' => 1],
        ],
    ];

    $result = EcommerceFormatConverter::ga4ToAmplitudeRefund($ga4Params);

    expect($result['revenue'])->toBe(49.99);
    expect($result['currency'])->toBe('USD');
    expect($result['transactionId'])->toBe('TXN-456');
});

test('EcommerceFormatConverter buildForAllProviders returns all 5 provider formats', function (): void {
    $ga4Params = [
        'transaction_id' => 'TXN-789',
        'value' => 99.99,
        'currency' => 'USD',
        'items' => [
            ['item_id' => 'SKU-001', 'item_name' => 'Product', 'price' => 99.99, 'quantity' => 1],
        ],
    ];

    $result = EcommerceFormatConverter::buildForAllProviders('purchase', $ga4Params);

    expect($result)->toHaveKey('ga4');
    expect($result)->toHaveKey('meta');
    expect($result)->toHaveKey('posthog');
    expect($result)->toHaveKey('mixpanel');
    expect($result)->toHaveKey('amplitude');

    // GA4 passes through unchanged
    expect($result['ga4']['transaction_id'])->toBe('TXN-789');

    // Meta format
    expect($result['meta']['content_type'])->toBe('product');
    expect($result['meta']['value'])->toBe(99.99);

    // PostHog format
    expect($result['posthog']['value'])->toBe(99.99);
    expect($result['posthog']['$currency'])->toBe('USD');

    // Mixpanel format
    expect($result['mixpanel']['$revenue'])->toBe(99.99);

    // Amplitude format
    expect($result['amplitude']['revenue'])->toBe(99.99);
});

test('EcommerceFormatConverter buildForAllProviders handles refund event type', function (): void {
    $ga4Params = [
        'transaction_id' => 'TXN-REFUND',
        'value' => 25.00,
        'currency' => 'USD',
        'items' => [
            ['item_id' => 'SKU-002', 'item_name' => 'Item', 'price' => 25.00, 'quantity' => 1],
        ],
    ];

    $result = EcommerceFormatConverter::buildForAllProviders('refund', $ga4Params);

    expect($result['meta']['value'])->toBe(25.00);
    expect($result['posthog']['value'])->toBe(25.00);
    expect($result['mixpanel']['$revenue'])->toBe(25.00);
    expect($result['amplitude']['revenue'])->toBe(25.00);
});

test('EcommerceFormatConverter buildForAllProviders handles add_to_cart with default handler', function (): void {
    $ga4Params = [
        'value' => 49.99,
        'currency' => 'USD',
        'items' => [
            ['item_id' => 'SKU-003', 'item_name' => 'Cart Item', 'price' => 49.99, 'quantity' => 1],
        ],
    ];

    $result = EcommerceFormatConverter::buildForAllProviders('add_to_cart', $ga4Params);

    // Default handler — all providers should have value
    expect($result['meta']['value'])->toBe(49.99);
    expect($result['posthog']['value'])->toBe(49.99);
});

test('EcommerceFormatConverter Mixpanel handles empty items gracefully', function (): void {
    $result = EcommerceFormatConverter::ga4ToMixpanelProperties([]);

    expect($result['products'])->toBe([]);
    expect($result['total_revenue'])->toBe(0.0);
    expect($result['product_count'])->toBe(0);
});

test('EcommerceFormatConverter Amplitude handles empty items gracefully', function (): void {
    $result = EcommerceFormatConverter::ga4ToAmplitudeProperties([]);

    expect($result['items'])->toBe([]);
    expect($result['total_amount'])->toBe(0.0);
    expect($result['item_count'])->toBe(0);
});

test('JS client Meta mapping includes refund and add_to_wishlist', function (): void {
    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');

    // Verify refund now maps to Meta
    expect($js)->toContain("'refund': 'Refund'");
    // Verify view_cart maps to Meta
    expect($js)->toContain("'view_cart': 'ViewCart'");
    // Verify add_to_wishlist mapping exists
    expect($js)->toContain("'add_to_wishlist': 'AddToWishlist'");
});

test('EventCatalog summary has all 6 provider coverage fields', function (): void {
    $summary = EventCatalog::summary();

    expect($summary)->toHaveKeys([
        'total', 'ecommerce', 'saas', 'engagement', 'security', 'uptime',
        'with_ga4', 'with_meta', 'with_posthog', 'with_plausible',
        'with_mixpanel', 'with_amplitude',
    ]);

    expect($summary['total'])->toBeGreaterThan(0);
    expect($summary['with_ga4'])->toBeGreaterThan(0);
    expect($summary['with_mixpanel'])->toBeGreaterThan(0);
    expect($summary['with_amplitude'])->toBeGreaterThan(0);
});

test('Svelte composables have version 18.0.0', function (): void {
    $svelteAnalytics = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
    $svelteConfig = file_get_contents(__DIR__ . '/../resources/js/useAnalyticsConfig.svelte.js');
    $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');

    expect($svelteAnalytics)->toContain('@version 18.0.0');
    expect($svelteConfig)->toContain('@version 18.0.0');
    expect($dts)->toContain('@version 18.0.0');
});

test('version alignment — all version markers are 18.0.0', function (): void {
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    $pkg = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);
    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    $svelteA = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
    $svelteC = file_get_contents(__DIR__ . '/../resources/js/useAnalyticsConfig.svelte.js');
    $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');

    $expected = '18.0.0';

    expect($composer['version'])->toBe($expected);
    expect($pkg['version'])->toBe($expected);
    expect($js)->toContain("'{$expected}'");
    expect(AnalyticsEvent::VERSION)->toBe($expected);
    expect($svelteA)->toContain("@version {$expected}");
    expect($svelteC)->toContain("@version {$expected}");
    expect($dts)->toContain("@version {$expected}");

    $reflection = new ReflectionClass(AnalyticsIntegrityCommand::class);
    expect($reflection->getConstant('EXPECTED_VERSION'))->toBe($expected);
});
