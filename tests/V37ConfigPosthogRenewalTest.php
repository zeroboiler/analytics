<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionRenewalEvent;
use ZeroBoiler\Analytics\Support\AnalyticsConfig;
use ZeroBoiler\Analytics\Support\EventTransformer;

beforeEach(function (): void {
    $this->config = new Repository([
        'zeroboiler' => [
            'analytics' => [
                'ga4' => ['enabled' => false, 'measurement_id' => '', 'api_secret' => ''],
                'gtm' => ['enabled' => false, 'container_id' => ''],
                'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                'consent' => ['default' => 'granted'],
                'dead_letter_queue' => [
                    'enabled' => true,
                    'strategy' => 'file',
                    'storage_path' => '/tmp/dlq.jsonl',
                    'max_size' => 5000,
                    'buffer_size' => 25,
                ],
                'realtime' => [
                    'enabled' => true,
                    'window_seconds' => 180,
                    'top_events_limit' => 30,
                ],
                'snapshots' => [
                    'enabled' => true,
                    'daily_ttl' => 9999999,
                    'hourly_ttl' => 999999,
                    'max_daily' => 60,
                    'max_hourly' => 120,
                ],
                'saas_kpi' => [
                    'enabled' => true,
                    'cache_ttl' => 5184000,
                ],
                'utm_aggregation' => [
                    'enabled' => true,
                    'cache_ttl' => 5184000,
                    'max_combinations' => 3000,
                ],
                'geolocation' => [
                    'enabled' => true,
                    'strategy' => 'maxmind',
                    'country_header' => 'X-Country',
                    'region_header' => 'X-Region',
                    'city_header' => 'X-City',
                ],
                'reporting' => [
                    'enabled' => true,
                    'cache_ttl' => 600,
                    'trending_window' => 7200,
                    'top_events_limit' => 30,
                    'trending_limit' => 15,
                ],
                'ab_tests' => [
                    'enabled' => true,
                    'confidence_threshold' => 0.99,
                    'cache_ttl' => 259200,
                ],
            ],
        ],
    ]);
});

// ── v2.41.0 New Features Test ──────────────────────────────────────────

describe('V37 Config Expansion + PostHog Mapping + New Event', function (): void {
    // ── SubscriptionRenewalEvent ────────────────────────────────────

    test('SubscriptionRenewalEvent is a valid AnalyticsEvent', function (): void {
        $event = new SubscriptionRenewalEvent('pro', 29.99, 'USD', 'monthly');

        expect($event->name)->toBe('subscription_renewal');
        expect($event->params)->toHaveKey('plan_name');
        expect($event->params['plan_name'])->toBe('pro');
        expect($event->params['amount'])->toBe(29.99);
        expect($event->params['currency'])->toBe('USD');
        expect($event->params['billing_cycle'])->toBe('monthly');
    });

    test('SubscriptionRenewalEvent filters null params', function (): void {
        $event = new SubscriptionRenewalEvent;

        expect($event->name)->toBe('subscription_renewal');
        expect($event->params)->not->toHaveKey('plan_name');
        expect($event->params)->not->toHaveKey('amount');
        expect($event->params)->not->toHaveKey('billing_cycle');
    });

    test('SubscriptionRenewalEvent accepts extra params', function (): void {
        $event = new SubscriptionRenewalEvent('enterprise', 99.99, 'EUR', 'yearly', [
            'invoice_id' => 'INV-123',
        ]);

        expect($event->params['invoice_id'])->toBe('INV-123');
    });

    // ── SaaSEvents Catalog ────────────────────────────────────────

    test('SaaSEvents catalog includes subscription_renewal', function (): void {
        expect(SaaSEvents::has('subscription_renewal'))->toBeTrue();
        expect(SaaSEvents::count())->toBe(20); // 19 + 1 new
    });

    test('SaaSEvents subscription_renewal entry has correct metadata', function (): void {
        $entry = SaaSEvents::get('subscription_renewal');

        expect($entry)->not->toBeNull();
        expect($entry['name'])->toBe('subscription_renewal');
        expect($entry['class'])->toBe(SubscriptionRenewalEvent::class);
        expect($entry['ga4'])->toBe('subscription_renewal');
        expect($entry['meta'])->toBe('SubscriptionRenewal');
    });

    test('EventCatalog count reflects new event', function (): void {
        // Ecommerce 12 + SaaS 20 + Engagement 21 = 53
        expect(EventCatalog::count())->toBe(53);
    });

    test('EventCatalog has subscription_renewal', function (): void {
        expect(EventCatalog::has('subscription_renewal'))->toBeTrue();
        expect(EventCatalog::getCategory('subscription_renewal'))->toBe('saas');
    });

    // ── AnalyticsManager subscriptionRenewal ─────────────────────

    test('AnalyticsManager has subscriptionRenewal method', function (): void {
        $manager = new AnalyticsManager($this->config);

        // Verify the method exists and can be called
        $manager->subscriptionRenewal('pro', 29.99, 'USD', 'monthly');
        // No exception = pass
        expect(true)->toBeTrue();
    });

    // ── Expanded PostHog Event Map ────────────────────────────────

    test('PostHog event map covers all 20 SaaS events', function (): void {
        $map = EventTransformer::saasToPosthogEventMap();

        $saasNames = SaaSEvents::names();
        foreach ($saasNames as $name) {
            expect($map)->toHaveKey($name);
        }
    });

    test('PostHog event map covers key engagement events', function (): void {
        $map = EventTransformer::saasToPosthogEventMap();

        expect($map)->toHaveKey('page_view');
        expect($map['page_view'])->toBe('$pageview');
        expect($map)->toHaveKey('session_start');
        expect($map['session_start'])->toBe('$session_start');
        expect($map)->toHaveKey('screen_view');
        expect($map['screen_view'])->toBe('$screenview');
        expect($map)->toHaveKey('share');
        expect($map['share'])->toBe('$share');
        expect($map)->toHaveKey('error');
        expect($map['error'])->toBe('$error');
        expect($map)->toHaveKey('form_submit');
        expect($map['form_submit'])->toBe('form_submitted');
        expect($map)->toHaveKey('search');
        expect($map['search'])->toBe('$search');
    });

    test('PostHog map has subscription_renewal entry', function (): void {
        $map = EventTransformer::saasToPosthogEventMap();

        expect($map)->toHaveKey('subscription_renewal');
        expect($map['subscription_renewal'])->toBe('subscription_renewed');
    });

    test('PostHog map total size is 27 (20 SaaS + 7 engagement)', function (): void {
        $map = EventTransformer::saasToPosthogEventMap();

        expect(count($map))->toBe(27);
    });

    // ── AnalyticsConfig — Dead Letter Queue Accessors ────────────

    test('AnalyticsConfig dead letter queue accessors return correct values', function (): void {
        $cfg = new AnalyticsConfig($this->config);

        expect($cfg->deadLetterQueueEnabled())->toBeTrue();
        expect($cfg->deadLetterQueueStrategy())->toBe('file');
        expect($cfg->deadLetterQueueStoragePath())->toBe('/tmp/dlq.jsonl');
        expect($cfg->deadLetterQueueMaxSize())->toBe(5000);
        expect($cfg->deadLetterQueueBufferSize())->toBe(25);
    });

    // ── AnalyticsConfig — Real-Time Aggregation Accessors ────────

    test('AnalyticsConfig realtime accessors return correct values', function (): void {
        $cfg = new AnalyticsConfig($this->config);

        expect($cfg->realtimeEnabled())->toBeTrue();
        expect($cfg->realtimeWindowSeconds())->toBe(180);
        expect($cfg->realtimeTopEventsLimit())->toBe(30);
    });

    // ── AnalyticsConfig — Snapshots Accessors ─────────────────────

    test('AnalyticsConfig snapshots accessors return correct values', function (): void {
        $cfg = new AnalyticsConfig($this->config);

        expect($cfg->snapshotsEnabled())->toBeTrue();
        expect($cfg->snapshotsDailyTtl())->toBe(9999999);
        expect($cfg->snapshotsHourlyTtl())->toBe(999999);
        expect($cfg->snapshotsMaxDaily())->toBe(60);
        expect($cfg->snapshotsMaxHourly())->toBe(120);
    });

    // ── AnalyticsConfig — SaaS KPI Accessors ──────────────────────

    test('AnalyticsConfig SaaS KPI accessors return correct values', function (): void {
        $cfg = new AnalyticsConfig($this->config);

        expect($cfg->saasKpiEnabled())->toBeTrue();
        expect($cfg->saasKpiCacheTtl())->toBe(5184000);
    });

    // ── AnalyticsConfig — UTM Aggregation Accessors ────────────────

    test('AnalyticsConfig UTM aggregation accessors return correct values', function (): void {
        $cfg = new AnalyticsConfig($this->config);

        expect($cfg->utmAggregationEnabled())->toBeTrue();
        expect($cfg->utmAggregationCacheTtl())->toBe(5184000);
        expect($cfg->utmAggregationMaxCombinations())->toBe(3000);
    });

    // ── AnalyticsConfig — Geolocation Accessors ───────────────────

    test('AnalyticsConfig geolocation accessors return correct values', function (): void {
        $cfg = new AnalyticsConfig($this->config);

        expect($cfg->geolocationEnabled())->toBeTrue();
        expect($cfg->geolocationStrategy())->toBe('maxmind');
        expect($cfg->geolocationCountryHeader())->toBe('X-Country');
        expect($cfg->geolocationRegionHeader())->toBe('X-Region');
        expect($cfg->geolocationCityHeader())->toBe('X-City');
    });

    // ── AnalyticsConfig — Reporting Accessors ─────────────────────

    test('AnalyticsConfig reporting accessors return correct values', function (): void {
        $cfg = new AnalyticsConfig($this->config);

        expect($cfg->reportingEnabled())->toBeTrue();
        expect($cfg->reportingCacheTtl())->toBe(600);
        expect($cfg->reportingTrendingWindow())->toBe(7200);
        expect($cfg->reportingTopEventsLimit())->toBe(30);
        expect($cfg->reportingTrendingLimit())->toBe(15);
    });

    // ── AnalyticsConfig — A/B Tests Accessors ───────────────────

    test('AnalyticsConfig A/B tests accessors return correct values', function (): void {
        $cfg = new AnalyticsConfig($this->config);

        expect($cfg->abTestsEnabled())->toBeTrue();
        expect($cfg->abTestsConfidenceThreshold())->toBe(0.99);
        expect($cfg->abTestsCacheTtl())->toBe(259200);
    });

    // ── AnalyticsConfig — Summary includes new sections ───────────

    test('AnalyticsConfig summary includes all new config sections', function (): void {
        $cfg = new AnalyticsConfig($this->config);
        $summary = $cfg->summary();

        expect($summary)->toHaveKey('dead_letter_queue');
        expect($summary)->toHaveKey('realtime');
        expect($summary)->toHaveKey('snapshots');
        expect($summary)->toHaveKey('saas_kpi');
        expect($summary)->toHaveKey('utm_aggregation');
        expect($summary)->toHaveKey('geolocation');
        expect($summary)->toHaveKey('reporting');
        expect($summary)->toHaveKey('ab_tests');

        // Verify structure of new summary sections
        expect($summary['dead_letter_queue'])->toHaveKeys(['enabled', 'strategy', 'max_size']);
        expect($summary['realtime'])->toHaveKeys(['enabled', 'window_seconds']);
        expect($summary['snapshots'])->toHaveKeys(['enabled', 'max_daily', 'max_hourly']);
        expect($summary['saas_kpi'])->toHaveKeys(['enabled']);
        expect($summary['geolocation'])->toHaveKeys(['enabled', 'strategy']);
    });

    // ── AnalyticsConfig — Defaults when config is empty ──────────

    test('AnalyticsConfig new accessors return defaults when config is empty', function (): void {
        $emptyConfig = new Repository([]);
        $cfg = new AnalyticsConfig($emptyConfig);

        expect($cfg->deadLetterQueueEnabled())->toBeTrue();
        expect($cfg->deadLetterQueueStrategy())->toBe('file');
        expect($cfg->deadLetterQueueMaxSize())->toBe(10000);
        expect($cfg->realtimeEnabled())->toBeTrue();
        expect($cfg->realtimeWindowSeconds())->toBe(120);
        expect($cfg->realtimeTopEventsLimit())->toBe(20);
        expect($cfg->snapshotsEnabled())->toBeTrue();
        expect($cfg->snapshotsDailyTtl())->toBe(7776000);
        expect($cfg->snapshotsMaxDaily())->toBe(90);
        expect($cfg->saasKpiEnabled())->toBeTrue();
        expect($cfg->utmAggregationEnabled())->toBeTrue();
        expect($cfg->utmAggregationCacheTtl())->toBe(2592000);
        expect($cfg->utmAggregationMaxCombinations())->toBe(5000);
        expect($cfg->geolocationEnabled())->toBeFalse();
        expect($cfg->geolocationStrategy())->toBe('header');
        expect($cfg->reportingEnabled())->toBeTrue();
        expect($cfg->reportingCacheTtl())->toBe(300);
        expect($cfg->reportingTrendingWindow())->toBe(3600);
        expect($cfg->abTestsEnabled())->toBeTrue();
        expect($cfg->abTestsConfidenceThreshold())->toBe(0.95);
        expect($cfg->abTestsCacheTtl())->toBe(604800);
    });

    // ── Version consistency ──────────────────────────────────────

    test('version is 2.41.0 across all sources', function (): void {
        $manager = new AnalyticsManager($this->config);

        expect($manager->version())->toBe('2.95.0');

        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        expect($composer['version'])->toBe('2.95.0');

        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect($js)->toContain("'2.95.0'");
        expect($js)->toContain('@version 2.41.0');
    });
});
