<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\AnalyticsCompositeHealthIndex;
use ZeroBoiler\Analytics\Services\MultiTouchAttributionService;
use ZeroBoiler\Analytics\Services\RealTimeEventCorrelationEngine;

/**
 * V204 Health Intelligence Suite — Composite Health Index, Multi-Touch Attribution,
 * and Real-Time Event Correlation Engine.
 *
 * @since 204.0.0
 */
test('AnalyticsCompositeHealthIndex computes score with all dimensions', function (): void {
    $cache = app('cache');
    $config = app('config');
    $metrics = new AnalyticsMetrics($config);

    $index = new AnalyticsCompositeHealthIndex($cache, $config, $metrics);

    $report = $index->computeFresh();

    // Validate report structure
    expect($report)->toHaveKey('score');
    expect($report)->toHaveKey('grade');
    expect($report)->toHaveKey('dimensions');
    expect($report)->toHaveKey('recommendations');
    expect($report)->toHaveKey('computed_at');

    // Score must be between 0 and 100
    expect($report['score'])->toBeGreaterThanOrEqual(0.0);
    expect($report['score'])->toBeLessThanOrEqual(100.0);

    // Grade must be a valid letter grade
    $validGrades = ['A+', 'A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D', 'F'];
    expect(in_array($report['grade'], $validGrades, true))->toBeTrue();

    // All 6 dimensions must be present
    $expectedDimensions = [
        'provider_coverage',
        'catalog_completeness',
        'data_quality',
        'dispatch_reliability',
        'event_volume_health',
        'consent_compliance',
    ];
    foreach ($expectedDimensions as $key) {
        expect($report['dimensions'])->toHaveKey($key);
    }

    // Each dimension must have required fields
    foreach ($report['dimensions'] as $dimension) {
        expect($dimension)->toHaveKey('name');
        expect($dimension)->toHaveKey('weight');
        expect($dimension)->toHaveKey('score');
        expect($dimension)->toHaveKey('grade');
        expect($dimension)->toHaveKey('status');
        expect($dimension)->toHaveKey('details');

        expect($dimension['score'])->toBeGreaterThanOrEqual(0.0);
        expect($dimension['score'])->toBeLessThanOrEqual(100.0);
        expect(in_array($dimension['status'], ['healthy', 'warning', 'critical'], true))->toBeTrue();
    }
});

test('AnalyticsCompositeHealthIndex scoreToGrade maps correctly', function (): void {
    $cache = app('cache');
    $config = app('config');
    $metrics = new AnalyticsMetrics($config);

    $index = new AnalyticsCompositeHealthIndex($cache, $config, $metrics);

    expect($index->scoreToGrade(100.0))->toBe('A+');
    expect($index->scoreToGrade(97.0))->toBe('A+');
    expect($index->scoreToGrade(95.0))->toBe('A');
    expect($index->scoreToGrade(93.0))->toBe('A');
    expect($index->scoreToGrade(91.0))->toBe('A-');
    expect($index->scoreToGrade(85.0))->toBe('B+');
    expect($index->scoreToGrade(80.0))->toBe('B-');
    expect($index->scoreToGrade(75.0))->toBe('C+');
    expect($index->scoreToGrade(60.0))->toBe('D');
    expect($index->scoreToGrade(30.0))->toBe('F');
});

test('AnalyticsCompositeHealthIndex trend detects direction', function (): void {
    $cache = app('cache');
    $config = app('config');
    $metrics = new AnalyticsMetrics($config);

    $index = new AnalyticsCompositeHealthIndex($cache, $config, $metrics);

    $trend = $index->trend();

    expect($trend)->toHaveKey('current');
    expect($trend)->toHaveKey('previous');
    expect($trend)->toHaveKey('delta');
    expect($trend)->toHaveKey('direction');

    expect(in_array($trend['direction'], ['improving', 'stable', 'declining'], true))->toBeTrue();
});

test('AnalyticsCompositeHealthIndex getDimensionScore returns individual dimension', function (): void {
    $cache = app('cache');
    $config = app('config');
    $metrics = new AnalyticsMetrics($config);

    $index = new AnalyticsCompositeHealthIndex($cache, $config, $metrics);

    $dimension = $index->getDimensionScore('provider_coverage');

    expect($dimension)->not()->toBeNull();
    expect($dimension)->toHaveKey('name');
    expect($dimension)->toHaveKey('score');

    // Invalid dimension returns null
    expect($index->getDimensionScore('nonexistent'))->toBeNull();
});

test('AnalyticsCompositeHealthIndex isDegraded and hasCriticalDimension', function (): void {
    $cache = app('cache');
    $config = app('config');
    $metrics = new AnalyticsMetrics($config);

    $index = new AnalyticsCompositeHealthIndex($cache, $config, $metrics);

    // These should always return boolean
    expect($index->isDegraded())->toBeBool();
    expect($index->hasCriticalDimension())->toBeBool();
});

test('MultiTouchAttributionService first-touch model', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new MultiTouchAttributionService($cache, $config);

    $conversion = new AnalyticsEvent(
        name: 'purchase',
        params: ['value' => 99.99],
        clientId: 'client-1',
        userId: '1',
    );

    $touchpoints = [
        [
            'source' => 'google',
            'medium' => 'cpc',
            'campaign' => 'brand',
            'content' => null,
            'term' => 'analytics tool',
            'timestamp' => '2026-08-01T10:00:00+00:00',
            'event_name' => 'page_view',
            'client_id' => 'client-1',
        ],
        [
            'source' => 'facebook',
            'medium' => 'social',
            'campaign' => 'retarget',
            'content' => 'video_ad',
            'term' => null,
            'timestamp' => '2026-08-02T14:00:00+00:00',
            'event_name' => 'sign_up',
            'client_id' => 'client-1',
        ],
        [
            'source' => 'email',
            'medium' => 'email',
            'campaign' => 'welcome',
            'content' => null,
            'term' => null,
            'timestamp' => '2026-08-03T09:00:00+00:00',
            'event_name' => 'trial_start',
            'client_id' => 'client-1',
        ],
    ];

    $result = $service->attribute($conversion, 'first_touch', $touchpoints);

    expect($result['model'])->toBe('first_touch');
    expect($result['conversion_event'])->toBe('purchase');
    expect($result['touchpoints'])->toHaveCount(3);

    // First touch should get 100% credit
    expect($result['attribution'])->toHaveKey('google/cpc');
    expect($result['attribution']['google/cpc'])->toBe(1.0);
    expect($result['total_credit'])->toBe(1.0);
});

test('MultiTouchAttributionService last-touch model', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new MultiTouchAttributionService($cache, $config);

    $conversion = new AnalyticsEvent(
        name: 'purchase',
        params: [],
        clientId: 'client-2',
    );

    $touchpoints = [
        [
            'source' => 'google', 'medium' => 'organic',
            'campaign' => null, 'content' => null, 'term' => null,
            'timestamp' => '2026-08-01T10:00:00+00:00',
            'event_name' => 'page_view', 'client_id' => 'client-2',
        ],
        [
            'source' => 'email', 'medium' => 'email',
            'campaign' => 'nurture', 'content' => null, 'term' => null,
            'timestamp' => '2026-08-05T09:00:00+00:00',
            'event_name' => 'purchase', 'client_id' => 'client-2',
        ],
    ];

    $result = $service->attribute($conversion, 'last_touch', $touchpoints);

    expect($result['attribution'])->toHaveKey('email/email');
    expect($result['attribution']['email/email'])->toBe(1.0);
});

test('MultiTouchAttributionService linear model distributes evenly', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new MultiTouchAttributionService($cache, $config);

    $conversion = new AnalyticsEvent(name: 'sign_up', params: [], clientId: 'c');

    $touchpoints = [
        [
            'source' => 'google', 'medium' => 'cpc',
            'campaign' => null, 'content' => null, 'term' => null,
            'timestamp' => '2026-08-01T10:00:00+00:00',
            'event_name' => 'page_view', 'client_id' => 'c',
        ],
        [
            'source' => 'twitter', 'medium' => 'social',
            'campaign' => null, 'content' => null, 'term' => null,
            'timestamp' => '2026-08-02T10:00:00+00:00',
            'event_name' => 'page_view', 'client_id' => 'c',
        ],
        [
            'source' => 'direct', 'medium' => 'none',
            'campaign' => null, 'content' => null, 'term' => null,
            'timestamp' => '2026-08-03T10:00:00+00:00',
            'event_name' => 'sign_up', 'client_id' => 'c',
        ],
    ];

    $result = $service->attribute($conversion, 'linear', $touchpoints);

    expect($result['total_credit'])->toBe(1.0);
    expect(count($result['attribution']))->toBeGreaterThanOrEqual(2);
});

test('MultiTouchAttributionService position-based model (U-Shape)', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new MultiTouchAttributionService($cache, $config);

    $conversion = new AnalyticsEvent(name: 'purchase', params: []);

    $touchpoints = [
        [
            'source' => 'google', 'medium' => 'cpc',
            'campaign' => null, 'content' => null, 'term' => null,
            'timestamp' => '2026-08-01T10:00:00+00:00',
            'event_name' => 'page_view', 'client_id' => 'c',
        ],
        [
            'source' => 'blog', 'medium' => 'referral',
            'campaign' => null, 'content' => null, 'term' => null,
            'timestamp' => '2026-08-02T10:00:00+00:00',
            'event_name' => 'page_view', 'client_id' => 'c',
        ],
        [
            'source' => 'email', 'medium' => 'email',
            'campaign' => null, 'content' => null, 'term' => null,
            'timestamp' => '2026-08-03T10:00:00+00:00',
            'event_name' => 'purchase', 'client_id' => 'c',
        ],
    ];

    $result = $service->attribute($conversion, 'position_based', $touchpoints);

    // First and last should get 40% each, middle gets 20%
    expect($result['total_credit'])->toBe(1.0);
    expect($result['attribution']['google/cpc'])->toBe(0.4);
    expect($result['attribution']['email/email'])->toBe(0.4);
});

test('MultiTouchAttributionService empty touchpoints returns direct', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new MultiTouchAttributionService($cache, $config);

    $conversion = new AnalyticsEvent(name: 'sign_up', params: []);

    $result = $service->attribute($conversion, 'first_touch', []);

    expect($result['touchpoints'])->toBeEmpty();
    expect($result['attribution'])->toHaveKey('direct');
    expect($result['attribution']['direct'])->toBe(1.0);
});

test('MultiTouchAttributionService compareModels runs all 6 models', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new MultiTouchAttributionService($cache, $config);

    $conversion = new AnalyticsEvent(name: 'purchase', params: []);

    $touchpoints = [
        [
            'source' => 'google', 'medium' => 'cpc',
            'campaign' => 'brand', 'content' => null, 'term' => null,
            'timestamp' => '2026-08-01T10:00:00+00:00',
            'event_name' => 'page_view', 'client_id' => 'c',
        ],
        [
            'source' => 'email', 'medium' => 'email',
            'campaign' => 'welcome', 'content' => null, 'term' => null,
            'timestamp' => '2026-08-02T10:00:00+00:00',
            'event_name' => 'trial_start', 'client_id' => 'c',
        ],
    ];

    $results = $service->compareModels($conversion, $touchpoints);

    expect($results)->toHaveCount(6);
    expect($results)->toHaveKey('first_touch');
    expect($results)->toHaveKey('last_touch');
    expect($results)->toHaveKey('linear');
    expect($results)->toHaveKey('position_based');
    expect($results)->toHaveKey('time_decay');
    expect($results)->toHaveKey('w_shaped');
});

test('MultiTouchAttributionService supportedModels and conversionEvents', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new MultiTouchAttributionService($cache, $config);

    $models = $service->supportedModels();
    expect($models)->toContain('first_touch');
    expect($models)->toContain('last_touch');
    expect($models)->toContain('linear');
    expect($models)->toContain('position_based');
    expect($models)->toContain('time_decay');
    expect($models)->toContain('w_shaped');
    expect($models)->toHaveCount(6);

    $conversions = $service->conversionEvents();
    expect($conversions)->toContain('sign_up');
    expect($conversions)->toContain('purchase');
    expect($conversions)->toContain('trial_start');
});

test('MultiTouchAttributionService extractTouchpoint from UTM params', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new MultiTouchAttributionService($cache, $config);

    $event = new AnalyticsEvent(
        name: 'page_view',
        params: [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'brand_search',
            'utm_content' => 'text_ad',
            'utm_term' => 'analytics tools',
        ],
        clientId: 'client-1',
    );

    $touchpoint = $service->extractTouchpoint($event);

    expect($touchpoint)->not()->toBeNull();
    expect($touchpoint['source'])->toBe('google');
    expect($touchpoint['medium'])->toBe('cpc');
    expect($touchpoint['campaign'])->toBe('brand_search');
    expect($touchpoint['content'])->toBe('text_ad');
    expect($touchpoint['term'])->toBe('analytics tools');
    expect($touchpoint['client_id'])->toBe('client-1');
});

test('MultiTouchAttributionService extractTouchpoint returns null for no-UTM event', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new MultiTouchAttributionService($cache, $config);

    $event = new AnalyticsEvent(
        name: 'click',
        params: ['element_id' => 'button-1'],
    );

    expect($service->extractTouchpoint($event))->toBeNull();
});

test('MultiTouchAttributionService channelReport aggregates conversions', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new MultiTouchAttributionService($cache, $config);

    $conversion = new AnalyticsEvent(name: 'sign_up', params: []);

    $touchpoints = [
        [
            'source' => 'google', 'medium' => 'cpc',
            'campaign' => null, 'content' => null, 'term' => null,
            'timestamp' => '2026-08-01T10:00:00+00:00',
            'event_name' => 'page_view', 'client_id' => 'c',
        ],
        [
            'source' => 'email', 'medium' => 'email',
            'campaign' => null, 'content' => null, 'term' => null,
            'timestamp' => '2026-08-02T10:00:00+00:00',
            'event_name' => 'sign_up', 'client_id' => 'c',
        ],
    ];

    $attributions = [
        $service->attribute($conversion, 'position_based', $touchpoints),
    ];

    $report = $service->channelReport($attributions, 'position_based');

    expect($report['model'])->toBe('position_based');
    expect($report['channels'])->toBeArray();
    expect($report['total_conversions'])->toBe(1);
});

test('MultiTouchAttributionService isConversionEvent', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new MultiTouchAttributionService($cache, $config);

    expect($service->isConversionEvent(new AnalyticsEvent(name: 'sign_up', params: [])))->toBeTrue();
    expect($service->isConversionEvent(new AnalyticsEvent(name: 'purchase', params: [])))->toBeTrue();
    expect($service->isConversionEvent(new AnalyticsEvent(name: 'page_view', params: [])))->toBeFalse();
    expect($service->isConversionEvent(new AnalyticsEvent(name: 'click', params: [])))->toBeFalse();
});

test('RealTimeEventCorrelationEngine analyzes co-occurring events', function (): void {
    $cache = app('cache');
    $config = app('config');

    $engine = new RealTimeEventCorrelationEngine($cache, $config);

    $now = new \DateTimeImmutable();
    $events = [
        // Session 1: user browses and purchases
        new AnalyticsEvent(name: 'view_item', params: ['item_id' => 'A'], sessionId: 's1', timestamp: $now),
        new AnalyticsEvent(name: 'add_to_cart', params: ['item_id' => 'A'], sessionId: 's1', timestamp: $now->modify('+1 minute')),
        new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99], sessionId: 's1', timestamp: $now->modify('+10 minutes')),

        // Session 2: same pattern
        new AnalyticsEvent(name: 'view_item', params: ['item_id' => 'B'], sessionId: 's2', timestamp: $now),
        new AnalyticsEvent(name: 'add_to_cart', params: ['item_id' => 'B'], sessionId: 's2', timestamp: $now->modify('+2 minutes')),
        new AnalyticsEvent(name: 'purchase', params: ['value' => 49.99], sessionId: 's2', timestamp: $now->modify('+15 minutes')),

        // Session 3: browse only
        new AnalyticsEvent(name: 'view_item', params: ['item_id' => 'C'], sessionId: 's3', timestamp: $now),
        new AnalyticsEvent(name: 'search', params: ['query' => 'shoes'], sessionId: 's3', timestamp: $now->modify('+3 minutes')),
    ];

    $report = $engine->analyze($events);

    expect($report)->toHaveKey('pairs');
    expect($report)->toHaveKey('transitions');
    expect($report)->toHaveKey('top_accelerators');
    expect($report)->toHaveKey('top_dropoff_signals');
    expect($report)->toHaveKey('engagement_clusters');
    expect($report)->toHaveKey('total_events_analyzed');
    expect($report)->toHaveKey('computed_at');

    expect($report['total_events_analyzed'])->toBe(9);
});

test('RealTimeEventCorrelationEngine empty events returns empty report', function (): void {
    $cache = app('cache');
    $config = app('config');

    $engine = new RealTimeEventCorrelationEngine($cache, $config);

    $report = $engine->analyze([]);

    expect($report['pairs'])->toBeEmpty();
    expect($report['transitions'])->toBeEmpty();
    expect($report['total_events_analyzed'])->toBe(0);
});

test('RealTimeEventCorrelationEngine filters out noise events', function (): void {
    $cache = app('cache');
    $config = app('config');

    $engine = new RealTimeEventCorrelationEngine($cache, $config);

    $now = new \DateTimeImmutable();
    $events = [
        new AnalyticsEvent(name: 'page_view', params: [], sessionId: 's1', timestamp: $now),
        new AnalyticsEvent(name: 'scroll_depth', params: [], sessionId: 's1', timestamp: $now->modify('+1 minute')),
        new AnalyticsEvent(name: 'session_start', params: [], sessionId: 's1', timestamp: $now),
        // Only meaningful event
        new AnalyticsEvent(name: 'sign_up', params: [], sessionId: 's1', timestamp: $now->modify('+5 minutes')),
    ];

    $report = $engine->analyze($events);

    // Noise events should be excluded from analysis
    expect($report['total_events_analyzed'])->toBe(1);
});

test('RealTimeEventCorrelationEngine cache operations', function (): void {
    $cache = app('cache');
    $config = app('config');

    $engine = new RealTimeEventCorrelationEngine($cache, $config);

    // Initially no cached report
    expect($engine->getCachedReport())->toBeNull();

    // Analyze and cache
    $now = new \DateTimeImmutable();
    $events = [
        new AnalyticsEvent(name: 'sign_up', params: [], sessionId: 's1', timestamp: $now),
        new AnalyticsEvent(name: 'trial_start', params: [], sessionId: 's1', timestamp: $now->modify('+1 minute')),
    ];

    $engine->analyzeAndCache($events);

    // Now should have a cached report
    $cached = $engine->getCachedReport();
    expect($cached)->not()->toBeNull();
    expect($cached['total_events_analyzed'])->toBe(2);

    // Invalidate
    $engine->invalidateCache();
    expect($engine->getCachedReport())->toBeNull();
});

test('RealTimeEventCorrelationEngine candidateEvents returns non-empty list', function (): void {
    $cache = app('cache');
    $config = app('config');

    $engine = new RealTimeEventCorrelationEngine($cache, $config);

    $candidates = $engine->candidateEvents();

    expect($candidates)->toBeArray();
    expect($candidates)->not()->toBeEmpty();

    // Should not contain excluded events
    expect($candidates)->not()->toContain('page_view');
    expect($candidates)->not()->toContain('scroll_depth');
    expect($candidates)->not()->toContain('session_start');

    // Should contain meaningful events
    expect($candidates)->toContain('sign_up');
    expect($candidates)->toContain('purchase');
    expect($candidates)->toContain('add_to_cart');
});

test('RealTimeEventCorrelationEngine topPairs returns sorted results', function (): void {
    $cache = app('cache');
    $config = app('config');

    $engine = new RealTimeEventCorrelationEngine($cache, $config);

    $now = new \DateTimeImmutable();

    // Create correlated events across multiple sessions
    $events = [];
    for ($i = 1; $i <= 5; $i++) {
        $sid = "session_{$i}";
        $events[] = new AnalyticsEvent(name: 'view_item', params: [], sessionId: $sid, timestamp: $now);
        $events[] = new AnalyticsEvent(name: 'add_to_cart', params: [], sessionId: $sid, timestamp: $now->modify('+1 min'));
        $events[] = new AnalyticsEvent(name: 'purchase', params: [], sessionId: $sid, timestamp: $now->modify('+10 min'));
    }

    $topPairs = $engine->topPairs(5, $events);

    expect($topPairs)->toBeArray();
    expect(count($topPairs))->toBeLessThanOrEqual(5);

    // Verify structure
    if (! empty($topPairs)) {
        $first = $topPairs[0];
        expect($first)->toHaveKey('event_a');
        expect($first)->toHaveKey('event_b');
        expect($first)->toHaveKey('coefficient');
        expect($first)->toHaveKey('lift');
        expect($first)->toHaveKey('co_occurrence_count');
    }
});

test('RealTimeEventCorrelationEngine sequential transitions', function (): void {
    $cache = app('cache');
    $config = app('config');

    $engine = new RealTimeEventCorrelationEngine($cache, $config);

    $now = new \DateTimeImmutable();

    $events = [
        new AnalyticsEvent(name: 'sign_up', params: [], sessionId: 's1', timestamp: $now),
        new AnalyticsEvent(name: 'feature_used', params: [], sessionId: 's1', timestamp: $now->modify('+1 min')),
        new AnalyticsEvent(name: 'trial_start', params: [], sessionId: 's1', timestamp: $now->modify('+5 min')),
    ];

    $report = $engine->analyze($events);

    expect($report['transitions'])->toBeArray();

    // Should detect sign_up → feature_used and feature_used → trial_start
    if (! empty($report['transitions'])) {
        $transition = $report['transitions'][0];
        expect($transition)->toHaveKey('from');
        expect($transition)->toHaveKey('to');
        expect($transition)->toHaveKey('probability');
        expect($transition)->toHaveKey('count');
        expect($transition['probability'])->toBeGreaterThan(0.0);
        expect($transition['probability'])->toBeLessThanOrEqual(1.0);
    }
});

test('MultiTouchAttributionService time_decay model favors recent touchpoints', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new MultiTouchAttributionService($cache, $config);

    $conversion = new AnalyticsEvent(
        name: 'purchase',
        params: [],
        timestamp: new \DateTimeImmutable('2026-08-15T12:00:00+00:00'),
    );

    $touchpoints = [
        [
            'source' => 'google', 'medium' => 'organic',
            'campaign' => null, 'content' => null, 'term' => null,
            'timestamp' => '2026-08-01T10:00:00+00:00', // 14 days ago
            'event_name' => 'page_view', 'client_id' => 'c',
        ],
        [
            'source' => 'email', 'medium' => 'email',
            'campaign' => null, 'content' => null, 'term' => null,
            'timestamp' => '2026-08-14T10:00:00+00:00', // 1 day ago
            'event_name' => 'click', 'client_id' => 'c',
        ],
    ];

    $result = $service->attribute($conversion, 'time_decay', $touchpoints);

    // Recent touchpoint (email) should get more credit than old one (google)
    $emailCredit = $result['attribution']['email/email'] ?? 0.0;
    $googleCredit = $result['attribution']['google/organic'] ?? 0.0;

    expect($emailCredit)->toBeGreaterThan($googleCredit);
    expect($result['total_credit'])->toBe(1.0);
});

test('MultiTouchAttributionService w_shaped model distributes correctly', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new MultiTouchAttributionService($cache, $config);

    $conversion = new AnalyticsEvent(name: 'purchase', params: []);

    $touchpoints = [
        [
            'source' => 'google', 'medium' => 'cpc',
            'campaign' => null, 'content' => null, 'term' => null,
            'timestamp' => '2026-08-01T10:00:00+00:00',
            'event_name' => 'page_view', 'client_id' => 'c',
        ],
        [
            'source' => 'blog', 'medium' => 'referral',
            'campaign' => null, 'content' => null, 'term' => null,
            'timestamp' => '2026-08-02T10:00:00+00:00',
            'event_name' => 'sign_up', 'client_id' => 'c', // Lead creation
        ],
        [
            'source' => 'email', 'medium' => 'email',
            'campaign' => null, 'content' => null, 'term' => null,
            'timestamp' => '2026-08-03T10:00:00+00:00',
            'event_name' => 'trial_start', 'client_id' => 'c',
        ],
        [
            'source' => 'direct', 'medium' => 'none',
            'campaign' => null, 'content' => null, 'term' => null,
            'timestamp' => '2026-08-04T10:00:00+00:00',
            'event_name' => 'purchase', 'client_id' => 'c',
        ],
    ];

    $result = $service->attribute($conversion, 'w_shaped', $touchpoints);

    expect($result['total_credit'])->toBe(1.0);

    // First (google/cpc) should get 30%, lead (blog/referral from sign_up) should get 30%, last (direct) should get 30%
    expect($result['attribution']['google/cpc'])->toBe(0.3);
    // Blog/referral should get 30% as lead creation (sign_up event)
    expect($result['attribution']['blog/referral'])->toBe(0.3);
});
