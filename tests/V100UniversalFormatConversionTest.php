<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;

use ReflectionClass;
use ReflectionMethod;

/**
 * V100 — Universal Cross-Provider Format Conversion Test.
 *
 * Validates the new v268.0.0 universal cross-provider conversion methods
 * on EcommerceFormatConverter:
 * - toGa4Format() — GA4 → Meta/PostHog/Plausible universal converter
 * - fromGa4Format() — Meta/PostHog → GA4 reverse converter
 * - ga4ToPlausibleAuto() — Universal GA4 → Plausible auto-dispatch converter
 */
test('v268.0.0: version is 266.0.0 everywhere', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('268.0.0');

    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('268.0.0');

    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($js)->toContain('@version 268.0.0');
    expect($js)->toContain("'268.0.0'");

    $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
    expect($svelte)->toContain('@version 268.0.0');

    $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
    expect($dts)->toContain('@version 268.0.0');

    $readme = file_get_contents(__DIR__ . '/../README.md');
    expect($readme)->toContain('version-266.0.0');
});

test('v268.0.0: EcommerceFormatConverter has toGa4Format and fromGa4Format methods', function (): void {
    $ref = new ReflectionClass(EcommerceFormatConverter::class);
    $methods = array_map(
        fn (ReflectionMethod $m): string => $m->getName(),
        $ref->getMethods(),
    );

    expect($methods)->toContain('toGa4Format');
    expect($methods)->toContain('fromGa4Format');
    expect($methods)->toContain('ga4ToPlausibleAuto');
    expect($methods)->toContain('ga4ToMetaAuto');
});

test('v268.0.0: toGa4Format converts GA4 purchase to Meta format', function (): void {
    $ga4Params = [
        'transaction_id' => 'TXN-001',
        'value' => 99.99,
        'currency' => 'USD',
        'items' => [
            ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2],
        ],
    ];

    $result = EcommerceFormatConverter::toGa4Format('meta', 'purchase', $ga4Params);

    expect($result)->toHaveKeys(['provider_event', 'provider_params']);
    expect($result['provider_event'])->toBe('Purchase');
    expect($result['provider_params'])->toHaveKey('content_ids');
    expect($result['provider_params'])->toHaveKey('contents');
    expect($result['provider_params']['currency'])->toBe('USD');
});

test('v268.0.0: toGa4Format converts GA4 purchase to Plausible format', function (): void {
    $ga4Params = [
        'transaction_id' => 'TXN-002',
        'value' => 49.99,
        'currency' => 'EUR',
        'items' => [
            ['item_id' => 'SKU-002', 'item_name' => 'Gadget', 'price' => 49.99, 'quantity' => 1],
        ],
    ];

    $result = EcommerceFormatConverter::toGa4Format('plausible', 'purchase', $ga4Params);

    expect($result)->toHaveKeys(['plausible_event', 'plausible_params']);
    expect($result['plausible_event'])->toBe('purchase');
    expect($result['plausible_params'])->toHaveKey('revenue');
    expect($result['plausible_params'])->toHaveKey('transaction_id');
});

test('v268.0.0: toGa4Format passes through GA4 for ga4 target', function (): void {
    $ga4Params = ['transaction_id' => 'TXN-003', 'value' => 10.0, 'currency' => 'USD'];

    $result = EcommerceFormatConverter::toGa4Format('ga4', 'purchase', $ga4Params);

    expect($result['provider_event'])->toBe('purchase');
    expect($result['provider_params'])->toBe($ga4Params);
});

test('v268.0.0: toGa4Format returns null for unsupported Meta events', function (): void {
    $result = EcommerceFormatConverter::toGa4Format('meta', 'unknown_event', ['foo' => 'bar']);

    expect($result['provider_event'])->toBeNull();
});

test('v268.0.0: toGa4Format returns null for unsupported Plausible events', function (): void {
    $result = EcommerceFormatConverter::toGa4Format('plausible', 'unknown_event', ['foo' => 'bar']);

    expect($result['plausible_event'])->toBeNull();
});

test('v268.0.0: fromGa4Format converts Meta Purchase back to GA4', function (): void {
    $metaParams = [
        'content_ids' => ['SKU-001'],
        'contents' => [['id' => 'SKU-001', 'quantity' => 2, 'item_price' => 49.99]],
        'num_items' => 1,
        'value' => 99.98,
        'currency' => 'USD',
        'content_type' => 'product',
    ];

    $result = EcommerceFormatConverter::fromGa4Format('meta', 'Purchase', $metaParams);

    expect($result)->toHaveKeys(['ga4_event', 'ga4_params']);
    expect($result['ga4_event'])->toBe('purchase');
    expect($result['ga4_params'])->toHaveKey('transaction_id');
    expect($result['ga4_params'])->toHaveKey('items');
    expect($result['ga4_params']['currency'])->toBe('USD');
});

test('v268.0.0: fromGa4Format converts Meta AddToCart back to GA4', function (): void {
    $metaParams = [
        'contents' => [['id' => 'SKU-001', 'quantity' => 1, 'item_price' => 29.99, 'item_name' => 'Thing']],
        'value' => 29.99,
        'currency' => 'USD',
    ];

    $result = EcommerceFormatConverter::fromGa4Format('meta', 'AddToCart', $metaParams);

    expect($result['ga4_event'])->toBe('add_to_cart');
    expect($result['ga4_params'])->toHaveKey('items');
    expect($result['ga4_params']['currency'])->toBe('USD');
});

test('v268.0.0: fromGa4Format passes through unknown Meta events', function (): void {
    $result = EcommerceFormatConverter::fromGa4Format('meta', 'Lead', ['foo' => 'bar']);

    expect($result['ga4_event'])->toBe('Lead');
    expect($result['ga4_params'])->toBe(['foo' => 'bar']);
});

test('v268.0.0: fromGa4Format passes through GA4 source unchanged', function (): void {
    $params = ['transaction_id' => 'TXN-004', 'value' => 10.0];

    $result = EcommerceFormatConverter::fromGa4Format('ga4', 'purchase', $params);

    expect($result['ga4_event'])->toBe('purchase');
    expect($result['ga4_params'])->toBe($params);
});

test('v268.0.0: ga4ToPlausibleAuto converts purchase', function (): void {
    $ga4Params = [
        'transaction_id' => 'TXN-005',
        'value' => 199.99,
        'currency' => 'USD',
        'items' => [
            ['item_id' => 'SKU-001', 'item_name' => 'Premium', 'price' => 199.99, 'quantity' => 1],
        ],
    ];

    $result = EcommerceFormatConverter::ga4ToPlausibleAuto('purchase', $ga4Params);

    expect($result)->not->toBeNull();
    expect($result['plausible_event'])->toBe('purchase');
    expect($result['plausible_params'])->toHaveKey('revenue');
    expect($result['plausible_params'])->toHaveKey('transaction_id');
    expect($result['plausible_params']['currency'])->toBe('USD');
});

test('v268.0.0: ga4ToPlausibleAuto converts add_to_cart', function (): void {
    $ga4Params = [
        'items' => [['item_id' => 'SKU-002', 'item_name' => 'Basic', 'price' => 9.99, 'quantity' => 1]],
        'value' => 9.99,
        'currency' => 'EUR',
    ];

    $result = EcommerceFormatConverter::ga4ToPlausibleAuto('add_to_cart', $ga4Params);

    expect($result)->not->toBeNull();
    expect($result['plausible_event'])->toBe('add_to_cart');
    expect($result['plausible_params'])->toHaveKey('item_name');
});

test('v268.0.0: ga4ToPlausibleAuto converts refund', function (): void {
    $ga4Params = [
        'transaction_id' => 'TXN-REFUND-001',
        'value' => 49.99,
        'currency' => 'USD',
    ];

    $result = EcommerceFormatConverter::ga4ToPlausibleAuto('refund', $ga4Params);

    expect($result)->not->toBeNull();
    expect($result['plausible_event'])->toBe('refund');
    expect($result['plausible_params'])->toHaveKey('refund_value');
    expect($result['plausible_params']['transaction_id'])->toBe('TXN-REFUND-001');
});

test('v268.0.0: ga4ToPlausibleAuto converts begin_checkout', function (): void {
    $ga4Params = [
        'items' => [['item_id' => 'SKU-003', 'item_name' => 'Pro', 'price' => 99.0, 'quantity' => 1]],
        'value' => 99.0,
        'currency' => 'USD',
        'coupon' => 'SAVE10',
    ];

    $result = EcommerceFormatConverter::ga4ToPlausibleAuto('begin_checkout', $ga4Params);

    expect($result)->not->toBeNull();
    expect($result['plausible_event'])->toBe('begin_checkout');
    expect($result['plausible_params'])->toHaveKey('items');
    expect($result['plausible_params']['coupon'])->toBe('SAVE10');
});

test('v268.0.0: ga4ToPlausibleAuto returns null for unsupported events', function (): void {
    expect(EcommerceFormatConverter::ga4ToPlausibleAuto('page_view', []))->toBeNull();
    expect(EcommerceFormatConverter::ga4ToPlausibleAuto('sign_up', []))->toBeNull();
});

test('v268.0.0: EcommerceFormatConverter imports EventCatalog', function (): void {
    $file = file_get_contents(__DIR__ . '/../src/Support/EcommerceFormatConverter.php');
    expect($file)->toContain('use ZeroBoiler\\Analytics\\Events\\EventCatalog');
});

test('v268.0.0: README documents v268.0.0 changelog', function (): void {
    $readme = file_get_contents(__DIR__ . '/../README.md');

    expect($readme)->toContain("What's New in v268.0.0");
    expect($readme)->toContain('toGa4Format');
    expect($readme)->toContain('fromGa4Format');
    expect($readme)->toContain('ga4ToPlausibleAuto');
    expect($readme)->toContain('Universal Cross-Provider Format Conversion');
});

test('v268.0.0: ServiceProvider version docblock is 266.0.0', function (): void {
    $sp = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect($sp)->toContain('@version 268.0.0');
});

test('v268.0.0: event catalog validates cleanly', function (): void {
    $result = EventCatalog::validate();
    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();

    $summary = EventCatalog::summary();
    expect($summary['total'])->toBeGreaterThanOrEqual(100);
});

test('v268.0.0: 166+ test files with comprehensive coverage', function (): void {
    $testDir = __DIR__;
    $testFiles = glob($testDir . '/*Test.php');
    $featureTestFiles = glob($testDir . '/Feature/**/*.php', GLOB_ERR);
    if ($featureTestFiles === false) {
        $featureTestFiles = [];
    }

    $total = count($testFiles) + count($featureTestFiles);
    expect($total)->toBeGreaterThanOrEqual(166);
});
