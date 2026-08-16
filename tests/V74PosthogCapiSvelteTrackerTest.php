<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;

/**
 * V7.4.0 — PostHog CAPI + Svelte Tracker + E-Commerce Expansion Test.
 *
 * Validates:
 * 1. PostHog CAPI event builders (buildPosthogViewItem, buildPosthogAddToCart, buildPosthogBeginCheckout)
 * 2. PostHog CAPI event objects (buildPosthogViewItemEvent, buildPosthogAddToCartEvent)
 * 3. Plausible view_item conversion (ga4ToPlausibleViewItem, ga4ToPlausibleAuto with view_item)
 * 4. Universal converter ga4ToPlausibleAuto expanded to 5 events
 * 5. Config structure for PostHog CAPI (capi_enabled, capture_path)
 * 6. JS client version consistency (7.4.0)
 * 7. EcommerceFormatConverter completeness — all PostHog + Plausible methods
 * 8. Event catalog integrity — no regressions in category counts
 */
test('PostHog CAPI: buildPosthogViewItem generates correct PostHog properties', function (): void {
    $params = EcommerceFormatConverter::buildPosthogViewItem(
        ['item_id' => 'SKU-1', 'item_name' => 'Widget', 'item_category' => 'Gadgets', 'price' => 29.99],
        'USD',
    );

    expect($params)->toBeArray();
    expect($params['$currency'])->toBe('USD');
    expect($params['value'])->toBe(29.99);
    expect($params['items'])->toBeArray();
    expect($params['items'][0]['sku'])->toBe('SKU-1');
    expect($params['items'][0]['name'])->toBe('Widget');
    expect($params['items'][0]['category'])->toBe('Gadgets');
    expect($params['items'][0]['price'])->toBe(29.99);
    expect($params['items'][0]['quantity'])->toBe(1);
});

test('PostHog CAPI: buildPosthogAddToCart calculates total value', function (): void {
    $params = EcommerceFormatConverter::buildPosthogAddToCart(
        ['item_id' => 'SKU-2', 'item_name' => 'Gadget', 'price' => 19.99, 'quantity' => 3],
        'EUR',
    );

    expect($params)->toBeArray();
    expect($params['$currency'])->toBe('EUR');
    expect($params['value'])->toBe(59.97); // 19.99 * 3
    expect($params['items'][0]['sku'])->toBe('SKU-2');
    expect($params['items'][0]['quantity'])->toBe(3);
});

test('PostHog CAPI: buildPosthogBeginCheckout with multiple items and coupon', function (): void {
    $items = [
        ['item_id' => 'SKU-1', 'item_name' => 'Widget', 'price' => 29.99, 'quantity' => 1],
        ['item_id' => 'SKU-2', 'item_name' => 'Gadget', 'price' => 19.99, 'quantity' => 2],
    ];

    $params = EcommerceFormatConverter::buildPosthogBeginCheckout(
        $items,
        69.97,
        'USD',
        ['coupon' => 'SUMMER20'],
    );

    expect($params)->toBeArray();
    expect($params['$currency'])->toBe('USD');
    expect($params['value'])->toBe(69.97);
    expect($params['coupon'])->toBe('SUMMER20');
    expect($params['items'])->toBeArray();
    expect(count($params['items']))->toBe(2);
    expect($params['items'][0]['sku'])->toBe('SKU-1');
    expect($params['items'][1]['sku'])->toBe('SKU-2');
});

test('PostHog CAPI: buildPosthogViewItemEvent returns AnalyticsEvent with correct name', function (): void {
    $event = EcommerceFormatConverter::buildPosthogViewItemEvent(
        ['item_id' => 'SKU-1', 'item_name' => 'Widget', 'price' => 29.99],
    );

    expect($event)->toBeInstanceOf(AnalyticsEvent::class);
    expect($event->name)->toBe('$view_item');
    expect($event->params)->toBeArray();
    expect($event->params['$currency'])->toBe('USD');
    expect($event->params['value'])->toBe(29.99);
});

test('PostHog CAPI: buildPosthogAddToCartEvent returns AnalyticsEvent with correct name', function (): void {
    $event = EcommerceFormatConverter::buildPosthogAddToCartEvent(
        ['item_id' => 'SKU-2', 'item_name' => 'Gadget', 'price' => 19.99, 'quantity' => 2],
        'EUR',
    );

    expect($event)->toBeInstanceOf(AnalyticsEvent::class);
    expect($event->name)->toBe('add_to_cart');
    expect($event->params['$currency'])->toBe('EUR');
    expect($event->params['value'])->toBe(39.98);
});

test('PostHog CAPI: buildPosthogViewItem defaults quantity to 1', function (): void {
    $params = EcommerceFormatConverter::buildPosthogViewItem(
        ['item_id' => 'SKU-1', 'item_name' => 'Widget', 'price' => 29.99],
    );

    expect($params['items'][0]['quantity'])->toBe(1);
});

test('PostHog CAPI: buildPosthogViewItem includes variant and brand when provided', function (): void {
    $params = EcommerceFormatConverter::buildPosthogViewItem(
        ['item_id' => 'SKU-1', 'item_name' => 'Widget', 'price' => 29.99, 'item_variant' => 'red', 'item_brand' => 'Acme'],
    );

    expect($params['items'][0]['variant'])->toBe('red');
    expect($params['items'][0]['brand'])->toBe('Acme');
});

test('Plausible: ga4ToPlausibleViewItem converts view_item correctly', function (): void {
    $result = EcommerceFormatConverter::ga4ToPlausibleViewItem([
        'items' => [
            ['item_id' => 'SKU-1', 'item_name' => 'Widget', 'price' => 29.99],
        ],
        'currency' => 'USD',
    ]);

    expect($result)->toBeArray();
    expect($result['event_name'])->toBe('view_item');
    expect($result['props']['item_name'])->toBe('Widget');
    expect($result['props']['item_id'])->toBe('SKU-1');
    expect($result['props']['value'])->toBe('29.99');
    expect($result['props']['currency'])->toBe('USD');
});

test('Plausible: ga4ToPlausibleAuto supports view_item', function (): void {
    $result = EcommerceFormatConverter::ga4ToPlausibleAuto('view_item', [
        'items' => [
            ['item_id' => 'SKU-1', 'item_name' => 'Widget', 'price' => 49.99],
        ],
        'currency' => 'EUR',
    ]);

    expect($result)->not->toBeNull();
    expect($result['plausible_event'])->toBe('view_item');
    expect($result['plausible_params']['item_name'])->toBe('Widget');
    expect($result['plausible_params']['currency'])->toBe('EUR');
});

test('Plausible: ga4ToPlausibleAuto returns null for unsupported events', function (): void {
    $result = EcommerceFormatConverter::ga4ToPlausibleAuto('custom_event', []);

    expect($result)->toBeNull();
});

test('Plausible: ga4ToPlausibleAuto supports all 5 e-commerce events', function (): void {
    $ga4Params = [
        'items' => [['item_id' => 'SKU-1', 'item_name' => 'W', 'price' => 10.0, 'quantity' => 2]],
        'currency' => 'USD',
        'value' => 20.0,
        'transaction_id' => 'TXN-1',
    ];

    $purchaseResult = EcommerceFormatConverter::ga4ToPlausibleAuto('purchase', $ga4Params);
    expect($purchaseResult)->not->toBeNull();
    expect($purchaseResult['plausible_event'])->toBe('purchase');

    $refundResult = EcommerceFormatConverter::ga4ToPlausibleAuto('refund', $ga4Params);
    expect($refundResult)->not->toBeNull();
    expect($refundResult['plausible_event'])->toBe('refund');

    $addToCartResult = EcommerceFormatConverter::ga4ToPlausibleAuto('add_to_cart', $ga4Params);
    expect($addToCartResult)->not->toBeNull();
    expect($addToCartResult['plausible_event'])->toBe('add_to_cart');

    $beginCheckoutResult = EcommerceFormatConverter::ga4ToPlausibleAuto('begin_checkout', $ga4Params);
    expect($beginCheckoutResult)->not->toBeNull();
    expect($beginCheckoutResult['plausible_event'])->toBe('begin_checkout');

    $viewItemResult = EcommerceFormatConverter::ga4ToPlausibleAuto('view_item', $ga4Params);
    expect($viewItemResult)->not->toBeNull();
    expect($viewItemResult['plausible_event'])->toBe('view_item');
});

test('PostHog CAPI: buildPosthogBeginCheckout without coupon omits coupon key', function (): void {
    $params = EcommerceFormatConverter::buildPosthogBeginCheckout(
        [['item_id' => 'SKU-1', 'item_name' => 'Widget', 'price' => 29.99, 'quantity' => 1]],
        29.99,
    );

    expect($params)->toBeArray();
    expect(array_key_exists('coupon', $params))->toBeFalse();
    expect($params['$currency'])->toBe('USD');
});

test('PostHog CAPI: buildPosthogViewItem handles empty item gracefully', function (): void {
    $params = EcommerceFormatConverter::buildPosthogViewItem([]);

    expect($params)->toBeArray();
    expect($params['$currency'])->toBe('USD');
    expect($params['value'])->toBe(0.0);
    expect($params['items'])->toBeArray();
    expect($params['items'][0]['sku'])->toBe('');
    expect($params['items'][0]['name'])->toBe('');
});

test('Config structure: PostHog config has expected keys', function (): void {
    $config = include __DIR__ . '/../config/zeroboiler.php';

    expect($config)->toBeArray();
    expect(isset($config['analytics']['posthog']))->toBeTrue();

    $posthog = $config['analytics']['posthog'];

    expect($posthog['enabled'])->toBeFalse();
    expect($posthog['api_key'])->toBe('');
    expect($posthog['host'])->toBe('https://eu.posthog.com');
    expect($posthog['project_id'])->toBe('');
    expect($posthog['capi_enabled'])->toBe(true);
    expect($posthog['capture_path'])->toBe('/capture/');
});

test('Version consistency: all version strings are 7.4.0', function (): void {
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('7.4.0');

    // Check JS client version (read first 20 lines)
    $jsContent = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect(str_contains($jsContent, '@version 7.4.0'))->toBeTrue();

    // Check TypeScript version
    $dtsContent = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
    expect(str_contains($dtsContent, '@version 7.4.0'))->toBeTrue();

    // Check ServiceProvider version
    $spContent = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect(str_contains($spContent, '@version 7.4.0'))->toBeTrue();
});

test('Event catalog integrity: no regressions in category counts', function (): void {
    expect(EventCatalog::count())->toBeGreaterThanOrEqual(100);
    expect(EcommerceEvents::count())->toBeGreaterThanOrEqual(15);
    expect(SaaSEvents::count())->toBeGreaterThanOrEqual(50);
    expect(EngagementEvents::count())->toBeGreaterThanOrEqual(30);
});

test('EcommerceFormatConverter: all PostHog methods exist and are static', function (): void {
    $reflection = new ReflectionClass(EcommerceFormatConverter::class);

    // PostHog methods
    expect($reflection->hasMethod('buildPosthogViewItem'))->toBeTrue();
    expect($reflection->hasMethod('buildPosthogAddToCart'))->toBeTrue();
    expect($reflection->hasMethod('buildPosthogBeginCheckout'))->toBeTrue();
    expect($reflection->hasMethod('buildPosthogViewItemEvent'))->toBeTrue();
    expect($reflection->hasMethod('buildPosthogAddToCartEvent'))->toBeTrue();
    expect($reflection->hasMethod('ga4ToPosthogPurchase'))->toBeTrue();
    expect($reflection->hasMethod('ga4ToPosthogRefund'))->toBeTrue();

    // Plausible methods
    expect($reflection->hasMethod('ga4ToPlausiblePurchase'))->toBeTrue();
    expect($reflection->hasMethod('ga4ToPlausibleRefund'))->toBeTrue();
    expect($reflection->hasMethod('ga4ToPlausibleAddToCart'))->toBeTrue();
    expect($reflection->hasMethod('ga4ToPlausibleBeginCheckout'))->toBeTrue();
    expect($reflection->hasMethod('ga4ToPlausibleViewItem'))->toBeTrue();
    expect($reflection->hasMethod('ga4ToPlausibleAuto'))->toBeTrue();

    // GA4/Meta methods
    expect($reflection->hasMethod('ga4ToMetaPurchase'))->toBeTrue();
    expect($reflection->hasMethod('ga4ToMetaRefund'))->toBeTrue();
    expect($reflection->hasMethod('ga4ToMetaAuto'))->toBeTrue();

    // Universal converters
    expect($reflection->hasMethod('toGa4Format'))->toBeTrue();
    expect($reflection->hasMethod('fromGa4Format'))->toBeTrue();

    // All should be static
    $posthogMethods = ['buildPosthogViewItem', 'buildPosthogAddToCart', 'buildPosthogBeginCheckout'];
    foreach ($posthogMethods as $method) {
        $m = $reflection->getMethod($method);
        expect($m->isStatic())->toBeTrue();
        expect($m->isPublic())->toBeTrue();
    }
});

test('JS client: initSveltePageTracker function is exported', function (): void {
    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect(str_contains($js, 'export function initSveltePageTracker'))->toBeTrue();
});

test('TypeScript: initSveltePageTracker type is declared', function (): void {
    $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
    expect(str_contains($dts, 'export function initSveltePageTracker'))->toBeTrue();
});

test('PostHog CAPI: currency defaults to USD when not specified', function (): void {
    $params = EcommerceFormatConverter::buildPosthogViewItem(
        ['item_id' => 'SKU-1', 'item_name' => 'Widget', 'price' => 10.0],
    );

    expect($params['$currency'])->toBe('USD');

    $addParams = EcommerceFormatConverter::buildPosthogAddToCart(
        ['item_id' => 'SKU-1', 'item_name' => 'Widget', 'price' => 10.0, 'quantity' => 1],
    );

    expect($addParams['$currency'])->toBe('USD');

    $checkoutParams = EcommerceFormatConverter::buildPosthogBeginCheckout(
        [['item_id' => 'SKU-1', 'item_name' => 'Widget', 'price' => 10.0, 'quantity' => 1]],
        10.0,
    );

    expect($checkoutParams['$currency'])->toBe('USD');
});
