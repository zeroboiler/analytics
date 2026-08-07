<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\\Analytics\\AnalyticsManager;
use ZeroBoiler\\Analytics\\Events\\EventCatalog;
use ZeroBoiler\\Analytics\\Events\\Ecommerce\\EcommerceEvents;
use ZeroBoiler\\Analytics\\Events\\SaaS\\SaaSEvents;
use ZeroBoiler\\Analytics\\Events\\Engagement\\EngagementEvents;

beforeEach(function (): void {
    $this->manager = new AnalyticsManager(
        new Illuminate\\Config\\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'ga4' => ['enabled' => false, 'measurement_id' => '', 'api_secret' => ''],
                    'gtm' => ['enabled' => false, 'container_id' => ''],
                    'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                    'plausible' => ['enabled' => false, 'domain' => '', 'api_key' => ''],
                    'posthog' => ['enabled' => false, 'api_key' => '', 'host' => ''],
                    'webhook' => ['enabled' => false, 'url' => '', 'secret' => '', 'timeout' => 5, 'retries' => 1, 'sign' => false, 'headers' => []],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false, 'log_events' => false],
                ],
            ],
        ]),
    );
});

describe('V38 JS Client Completeness', function (): void {
    test('version is 2.38.0', function (): void {
        expect($this->manager->version())->toBe('2.38.0');
    });

    test('event catalog has ecommerce events with meta pixel mappings', function (): void {
        $catalog = EventCatalog::all();
        expect($catalog)->toHaveKey('view_item');
        expect($catalog['view_item']['meta'])->toBe('ViewContent');
        expect($catalog['add_to_cart']['meta'])->toBe('AddToCart');
        expect($catalog['purchase']['meta'])->toBe('Purchase');
        expect($catalog['refund']['meta'])->toBe('Refund');
    });

    test('event catalog has SaaS events with correct ga4 and meta mappings', function (): void {
        $saasCatalog = SaaSEvents::all();
        expect($saasCatalog)->toHaveKey('sign_up');
        expect($saasCatalog)->toHaveKey('login');
        expect($saasCatalog)->toHaveKey('start_trial');
        expect($saasCatalog)->toHaveKey('subscribe');
        expect($saasCatalog)->toHaveKey('plan_upgrade');
        expect($saasCatalog)->toHaveKey('cancellation');
        expect($saasCatalog)->toHaveKey('subscription_renewal');

        // Verify GA4 mapping for subscribe → purchase
        expect($saasCatalog['subscribe']['ga4'])->toBe('purchase');

        // Verify Meta mapping for sign_up → CompleteRegistration
        expect($saasCatalog['sign_up']['meta'])->toBe('CompleteRegistration');
    });

    test('event catalog has engagement events including all required types', function (): void {
        $engagementCatalog = EngagementEvents::all();

        // Core engagement events
        expect($engagementCatalog)->toHaveKey('page_view');
        expect($engagementCatalog)->toHaveKey('scroll_depth');
        expect($engagementCatalog)->toHaveKey('click');
        expect($engagementCatalog)->toHaveKey('form_start');
        expect($engagementCatalog)->toHaveKey('form_submit');
        expect($engagementCatalog)->toHaveKey('search');
        expect($engagementCatalog)->toHaveKey('share');
        expect($engagementCatalog)->toHaveKey('error');

        // Performance events
        expect($engagementCatalog)->toHaveKey('web_vitals');
        expect($engagementCatalog)->toHaveKey('js_error');
        expect($engagementCatalog)->toHaveKey('timing');

        // Session events
        expect($engagementCatalog)->toHaveKey('session_start');
        expect($engagementCatalog)->toHaveKey('session_end');

        // Content events
        expect($engagementCatalog)->toHaveKey('outbound_click');
        expect($engagementCatalog)->toHaveKey('file_download');
        expect($engagementCatalog)->toHaveKey('video_play');

        // Notification
        expect($engagementCatalog)->toHaveKey('notification');
    });

    test('event catalog total count is consistent', function (): void {
        $ecommerceCount = EcommerceEvents::count();
        $saasCount = SaaSEvents::count();
        $engagementCount = EngagementEvents::count();
        $total = EventCatalog::count();

        expect($total)->toBe($ecommerceCount + $saasCount + $engagementCount);
        expect($ecommerceCount)->toBeGreaterThanOrEqual(12);
        expect($saasCount)->toBeGreaterThanOrEqual(20);
        expect($engagementCount)->toBeGreaterThanOrEqual(21);
    });

    test('EventCatalog validate returns valid result', function (): void {
        $result = EventCatalog::validate();

        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
    });

    test('EventCatalog byCategory returns all three categories', function (): void {
        $byCategory = EventCatalog::byCategory();

        expect($byCategory)->toHaveKeys(['ecommerce', 'saas', 'engagement']);
        expect(count($byCategory['ecommerce']))->toBe(EcommerceEvents::count());
        expect(count($byCategory['saas']))->toBe(SaaSEvents::count());
        expect(count($byCategory['engagement']))->toBe(EngagementEvents::count());
    });

    test('EventCatalog byProvider returns all four providers', function (): void {
        $byProvider = EventCatalog::byProvider();

        expect($byProvider)->toHaveKeys(['ga4', 'meta', 'posthog', 'plausible']);
        expect($byProvider['ga4'])->toBeNonEmpty();
        expect($byProvider['meta'])->toBeNonEmpty();
        expect($byProvider['posthog'])->toBeNonEmpty();
        expect($byProvider['plausible'])->toBeNonEmpty();
    });

    test('all event entries have required keys', function (): void {
        $required = EventCatalog::requiredKeys();
        expect($required)->toContain('name');
        expect($required)->toContain('class');
        expect($required)->toContain('ga4');
        expect($required)->toContain('category');
    });

    test('EventCatalog search finds matching events', function (): void {
        $results = EventCatalog::search('purchase');

        expect($results)->toBeNonEmpty();

        $names = array_column($results, 'name');
        expect($names)->toContain('purchase');
    });

    test('EventCatalog getCategory returns correct category for events', function (): void {
        expect(EventCatalog::getCategory('purchase'))->toBe('ecommerce');
        expect(EventCatalog::getCategory('sign_up'))->toBe('saas');
        expect(EventCatalog::getCategory('page_view'))->toBe('engagement');
        expect(EventCatalog::getCategory('nonexistent'))->toBeNull();
    });

    test('EventCatalog classFor returns correct class for events', function (): void {
        expect(EventCatalog::classFor('purchase'))->toBeString();
        expect(EventCatalog::classFor('login'))->toBeString();
        expect(EventCatalog::classFor('page_view'))->toBeString();
        expect(EventCatalog::classFor('nonexistent'))->toBeNull();
    });

    test('Meta Pixel ecommerce mappings cover all core events', function (): void {
        $ecommerce = EcommerceEvents::all();
        $metaMapped = EcommerceEvents::metaNames();

        // All 12 events should have Meta mappings
        expect(count($metaMapped))->toBe(EcommerceEvents::count());

        // Verify key mappings
        $catalog = EcommerceEvents::all();
        expect($catalog['purchase']['meta'])->toBe('Purchase');
        expect($catalog['begin_checkout']['meta'])->toBe('InitiateCheckout');
        expect($catalog['add_to_wishlist']['meta'])->toBe('AddToWishlist');
    });
});

describe('V38 JS Client Feature Parity', function (): void {
    test('js client file exports all new convenience functions', function (): void {
        $js = file_get_contents(__DIR__.'/../resources/js/analytics.js');
        expect($js)->not->toBeFalse();

        // SaaS convenience
        expect(str_contains($js, 'export async function trackSaaSEvent'))->toBeTrue();

        // Search
        expect(str_contains($js, 'export async function trackSearch'))->toBeTrue();

        // Share
        expect(str_contains($js, 'export async function trackShare'))->toBeTrue();

        // File download
        expect(str_contains($js, 'export async function trackFileDownload'))->toBeTrue();
        expect(str_contains($js, 'export function initFileDownloadTracking'))->toBeTrue();

        // Video
        expect(str_contains($js, 'export async function trackVideoPlay'))->toBeTrue();

        // Notification
        expect(str_contains($js, 'export async function trackNotification'))->toBeTrue();

        // Outbound click
        expect(str_contains($js, 'export async function trackOutboundClick'))->toBeTrue();
    });

    test('js client has version 2.38.0', function (): void {
        $js = file_get_contents(__DIR__.'/../resources/js/analytics.js');
        expect($js)->not->toBeFalse();
        expect(str_contains($js, "'2.38.0'"))->toBeTrue();
    });

    test('typescript definitions include all new types', function (): void {
        $dts = file_get_contents(__DIR__.'/../resources/js/analytics.d.ts');
        expect($dts)->not->toBeFalse();
        expect(str_contains($dts, '2.38.0'))->toBeTrue();

        // New interfaces
        expect(str_contains($dts, 'SearchOptions'))->toBeTrue();
        expect(str_contains($dts, 'FileDownloadData'))->toBeTrue();
        expect(str_contains($dts, 'FileDownloadTrackingOptions'))->toBeTrue();
        expect(str_contains($dts, 'VideoPlayData'))->toBeTrue();
        expect(str_contains($dts, 'OutboundClickOptions'))->toBeTrue();
        expect(str_contains($dts, 'TrackingPreference'))->toBeTrue();

        // New function declarations
        expect(str_contains($dts, 'trackSearch'))->toBeTrue();
        expect(str_contains($dts, 'trackShare'))->toBeTrue();
        expect(str_contains($dts, 'trackFileDownload'))->toBeTrue();
        expect(str_contains($dts, 'initFileDownloadTracking'))->toBeTrue();
        expect(str_contains($dts, 'trackVideoPlay'))->toBeTrue();
        expect(str_contains($dts, 'trackNotification'))->toBeTrue();
        expect(str_contains($dts, 'trackSaaSEvent'))->toBeTrue();
        expect(str_contains($dts, 'trackOutboundClick'))->toBeTrue();
        expect(str_contains($dts, 'getTrackingPreference'))->toBeTrue();
        expect(str_contains($dts, 'initSessionHeartbeat'))->toBeTrue();
        expect(str_contains($dts, 'stopSessionHeartbeat'))->toBeTrue();
        expect(str_contains($dts, 'isHeartbeatActive'))->toBeTrue();
    });
});
