<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;

test('v8.6.0 — e-commerce shorthand functions exist in JS client', function (): void {
    $jsPath = base_path('resources/js/analytics.js');
    $content = file_get_contents($jsPath);

    expect($content)->toContain('export async function trackPurchase(');
    expect($content)->toContain('export async function trackRefund(');
    expect($content)->toContain('export async function trackViewItem(');
    expect($content)->toContain('export async function trackAddToCart(');
    expect($content)->toContain('export async function trackRemoveFromCart(');
    expect($content)->toContain('export async function trackBeginCheckout(');
});

test('v8.6.0 — TypeScript definitions include e-commerce shorthands', function (): void {
    $tsPath = base_path('resources/js/analytics.d.ts');
    $content = file_get_contents($tsPath);

    expect($content)->toContain('export function trackPurchase(');
    expect($content)->toContain('export function trackRefund(');
    expect($content)->toContain('export function trackViewItem(');
    expect($content)->toContain('export function trackAddToCart(');
    expect($content)->toContain('export function trackRemoveFromCart(');
    expect($content)->toContain('export function trackBeginCheckout(');
    expect($content)->toContain('export interface PurchaseData');
    expect($content)->toContain('export interface RefundData');
    expect($content)->toContain('export interface CheckoutData');
});

test('v8.6.0 — Svelte composable imports new shorthand functions', function (): void {
    $sveltePath = base_path('resources/js/useAnalytics.svelte.js');
    $content = file_get_contents($sveltePath);

    expect($content)->toContain('trackPurchase,');
    expect($content)->toContain('trackRefund,');
    expect($content)->toContain('trackViewItem,');
    expect($content)->toContain('trackAddToCart,');
    expect($content)->toContain('trackRemoveFromCart,');
    expect($content)->toContain('trackBeginCheckout,');
});

test('v8.6.0 — useEcommerce composable exposes trackRemoveFromCart', function (): void {
    $sveltePath = base_path('resources/js/useAnalytics.svelte.js');
    $content = file_get_contents($sveltePath);

    expect($content)->toContain('trackRemoveFromCart: trackRemoveFromCartComposable');
});

test('v8.6.0 — JS client sends ecommerce to PostHog and Plausible', function (): void {
    $jsPath = base_path('resources/js/analytics.js');
    $content = file_get_contents($jsPath);

    // trackPurchase includes PostHog capture
    expect($content)->toContain("window.posthog.capture('purchase'");
    // trackPurchase includes Plausible custom event
    expect($content)->toContain("window.plausible('purchase'");
    // trackAddToCart includes PostHog
    expect($content)->toContain("window.posthog.capture('add_to_cart'");
    // trackAddToCart includes Plausible
    expect($content)->toContain("window.plausible('add_to_cart'");
    // trackRefund includes PostHog
    expect($content)->toContain("window.posthog.capture('refund'");
});

test('v8.6.0 — no JS syntax errors (duplicate declarations)', function (): void {
    $jsPath = base_path('resources/js/analytics.js');
    $content = file_get_contents($jsPath);

    // initInertiaPageViewTracker should only have one export declaration
    $exportCount = substr_count($content, 'export function initInertiaPageViewTracker(');
    expect($exportCount)->toBe(1);

    // Legacy version should be renamed
    expect($content)->toContain('export function initInertiaPageViewTrackerLegacy()');

    // getCookie should only have one declaration
    $getCookieCount = substr_count($content, 'function getCookie(');
    expect($getCookieCount)->toBe(1);
});

test('v8.6.0 — Bearer token template literal is properly formatted', function (): void {
    $jsPath = base_path('resources/js/analytics.js');
    $content = file_get_contents($jsPath);

    // Should NOT contain broken *** syntax
    expect($content)->not->toContain('Authorization: ***');

    // Should contain proper Bearer token
    expect($content)->toContain('`Bearer ${getAuthToken()}`');
});

test('v8.6.0 — version consistency across all entry points', function (): void {
    // PHP VERSION constant
    expect(AnalyticsEvent::VERSION)->toBe('8.9.0');

    // composer.json
    $composer = json_decode(file_get_contents(base_path('composer.json')), true);
    expect($composer['version'])->toBe('8.9.0');

    // package.json
    $package = json_decode(file_get_contents(base_path('package.json')), true);
    expect($package['version'])->toBe('8.9.0');

    // JS client header
    $jsContent = file_get_contents(base_path('resources/js/analytics.js'));
    expect($jsContent)->toContain('@version 8.9.0');

    // JS getVersion()
    expect($jsContent)->toContain("return '8.9.0'");

    // TypeScript definitions
    $tsContent = file_get_contents(base_path('resources/js/analytics.d.ts'));
    expect($tsContent)->toContain('@version 8.9.0');

    // Svelte composable
    $svelteContent = file_get_contents(base_path('resources/js/useAnalytics.svelte.js'));
    expect($svelteContent)->toContain('@version 8.9.0');
});

test('v8.6.0 — e-commerce event catalog coverage', function (): void {
    // All core e-commerce events are cataloged
    expect(EcommerceEvents::has('view_item'))->toBeTrue();
    expect(EcommerceEvents::has('add_to_cart'))->toBeTrue();
    expect(EcommerceEvents::has('remove_from_cart'))->toBeTrue();
    expect(EcommerceEvents::has('begin_checkout'))->toBeTrue();
    expect(EcommerceEvents::has('add_payment_info'))->toBeTrue();
    expect(EcommerceEvents::has('purchase'))->toBeTrue();
    expect(EcommerceEvents::has('refund'))->toBeTrue();

    // All have GA4 mappings
    expect(EcommerceEvents::get('purchase')['ga4'])->toBe('purchase');
    expect(EcommerceEvents::get('refund')['ga4'])->toBe('refund');

    // All have Meta mappings
    expect(EcommerceEvents::get('purchase')['meta'])->toBe('Purchase');
    expect(EcommerceEvents::get('add_to_cart')['meta'])->toBe('AddToCart');
    expect(EcommerceEvents::get('view_item')['meta'])->toBe('ViewContent');
    expect(EcommerceEvents::get('begin_checkout')['meta'])->toBe('InitiateCheckout');

    // All have PostHog mappings
    expect(EcommerceEvents::get('purchase')['posthog'])->toBe('purchase');
    expect(EcommerceEvents::get('add_to_cart')['posthog'])->toBe('add_to_cart');
});

test('v8.6.0 — event catalog summary includes e-commerce count', function (): void {
    $summary = EventCatalog::summary();

    expect($summary)->toHaveKey('ecommerce');
    expect($summary)->toHaveKey('saas');
    expect($summary)->toHaveKey('engagement');
    expect($summary['ecommerce'])->toBeGreaterThanOrEqual(15);
});

test('v8.6.0 — EcommerceFormatConverter handles GA4 ↔ Meta conversion', function (): void {
    $converter = new EcommerceFormatConverter;

    $ga4Purchase = [
        'transaction_id' => 'TXN-001',
        'value' => 99.99,
        'currency' => 'USD',
        'items' => [
            ['item_id' => 'SKU-1', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2],
        ],
    ];

    $meta = $converter->toMetaFormat('purchase', $ga4Purchase);
    expect($meta)->not->toBeNull();
    expect($meta['value'])->toBe(99.99);
    expect($meta['currency'])->toBe('USD');
});
