<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\DataQualityScoringEngine;
use ZeroBoiler\Analytics\Services\EventCorrelationMatrixService;
use ZeroBoiler\Analytics\Services\EventReplayAuditTrailService;
use ZeroBoiler\Analytics\Services\FeatureFlagAnalyticsBridge;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;

/**
 * V203 Intelligence Suite — Correlation, Quality, Audit, and Feature Flag Bridge.
 *
 * @since 203.0.0
 */
test('DataQualityScoringEngine scores ecommerce purchase event', function (): void {
    $engine = new DataQualityScoringEngine(freshnessThreshold: 10);

    $event = new AnalyticsEvent(
        name: 'purchase',
        params: [
            'transaction_id' => 'TXN-001',
            'value' => 99.99,
            'currency' => 'USD',
            'item_id' => 'SKU-123',
        ],
        clientId: 'client-123',
        userId: '1',
        category: 'ecommerce',
    );

    $result = $engine->scoreEvent($event);

    expect($result['score'])->toBeFloat();
    expect($result['score'])->toBeGreaterThanOrEqual(0.0);
    expect($result['score'])->toBeLessThanOrEqual(100.0);
    expect($result['grade'])->toBeString();
    expect(in_array($result['grade'], ['A+', 'A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'D', 'F'], true))->toBeTrue();
    expect($result['dimensions'])->toHaveKey('completeness');
    expect($result['dimensions'])->toHaveKey('consistency');
    expect($result['dimensions'])->toHaveKey('timeliness');
    expect($result['dimensions'])->toHaveKey('validity');
    expect($result['dimensions'])->toHaveKey('uniqueness');
});

test('DataQualityScoringEngine scores SaaS sign_up event', function (): void {
    $engine = new DataQualityScoringEngine();

    $event = new AnalyticsEvent(
        name: 'sign_up',
        params: ['plan' => 'pro', 'period' => 'monthly'],
        clientId: 'client-456',
        userId: '2',
        category: 'saas',
    );

    $result = $engine->scoreEvent($event);

    expect($result['score'])->toBeFloat();
    expect($result['dimensions']['completeness']['score'])->toBeGreaterThan(0.0);
    expect($result['dimensions']['validity']['score'])->toBeGreaterThan(0.0);
});

test('DataQualityScoringEngine scores minimal engagement event', function (): void {
    $engine = new DataQualityScoringEngine();

    $event = new AnalyticsEvent(
        name: 'click',
        params: [],
        clientId: null,
        userId: null,
        category: 'engagement',
    );

    $result = $engine->scoreEvent($event);

    // Event without identity should have lower uniqueness score
    expect($result['dimensions']['uniqueness']['score'])->toBeLessThan(100.0);
    expect($result['score'])->toBeFloat();
});

test('DataQualityScoringEngine scores batch of events', function (): void {
    $engine = new DataQualityScoringEngine();

    $events = [
        new AnalyticsEvent(name: 'page_view', params: ['page_url' => 'https://example.com'], clientId: 'c1', category: 'engagement'),
        new AnalyticsEvent(name: 'purchase', params: ['transaction_id' => 'TX-1', 'value' => 50, 'currency' => 'EUR'], clientId: 'c2', category: 'ecommerce'),
        new AnalyticsEvent(name: 'sign_up', params: ['plan' => 'free'], clientId: 'c3', userId: '1', category: 'saas'),
    ];

    $batch = $engine->scoreBatch($events);

    expect($batch['avg_score'])->toBeFloat();
    expect($batch['min_score'])->toBeFloat();
    expect($batch['max_score'])->toBeFloat();
    expect($batch['min_score'])->toBeLessThanOrEqual($batch['max_score']);
    expect($batch['grade_distribution'])->toBeArray();
    expect($batch['dimension_averages'])->toBeArray();
});

test('DataQualityScoringEngine scoreDimension returns correct dimension', function (): void {
    $engine = new DataQualityScoringEngine();

    $event = new AnalyticsEvent(name: 'test', params: [], clientId: 'c1');

    $completeness = $engine->scoreDimension($event, 'completeness');
    $validity = $engine->scoreDimension($event, 'validity');
    $unknown = $engine->scoreDimension($event, 'unknown_dimension');

    expect($completeness)->toBeFloat();
    expect($validity)->toBeFloat();
    expect($unknown)->toBe(0.0);
});

test('DataQualityScoringEngine diagnosticSummary returns config', function (): void {
    $engine = new DataQualityScoringEngine(freshnessThreshold: 30);

    $summary = $engine->diagnosticSummary();

    expect($summary['weights'])->toBeArray();
    expect($summary['weights'])->toHaveKey('completeness');
    expect($summary['freshness_threshold'])->toBe(30);
    expect($summary['categories'])->toBeArray();
});

test('EventCorrelationMatrixService returns structure', function (): void {
    $service = new EventCorrelationMatrixService(
        cache: new \Illuminate\Cache\ArrayStore,
        streamService: null,
    );

    $matrix = $service->computeMatrix();

    expect($matrix)->toHaveKey('matrix');
    expect($matrix)->toHaveKey('metadata');
    expect($matrix['matrix'])->toBeArray();
    expect($matrix['metadata'])->toHaveKey('events');
    expect($matrix['metadata'])->toHaveKey('window');
    expect($matrix['metadata'])->toHaveKey('computed_at');
});

test('EventCorrelationMatrixService topCorrelations returns list', function (): void {
    $service = new EventCorrelationMatrixService(
        cache: new \Illuminate\Cache\ArrayStore,
        streamService: null,
    );

    $top = $service->topCorrelations(limit: 5);

    expect($top)->toBeArray();
    // Empty when no events have been tracked
});

test('EventCorrelationMatrixService conversionCorrelation returns structure', function (): void {
    $service = new EventCorrelationMatrixService(
        cache: new \Illuminate\Cache\ArrayStore,
        streamService: null,
    );

    $correlation = $service->conversionCorrelation('page_view', 'purchase');

    expect($correlation)->toHaveKey('predictor');
    expect($correlation)->toHaveKey('conversion');
    expect($correlation)->toHaveKey('lift');
    expect($correlation)->toHaveKey('confidence');
    expect($correlation)->toHaveKey('sample_size');
    expect($correlation)->toHaveKey('interpretation');
});

test('EventCorrelationMatrixService retentionCorrelation returns structure', function (): void {
    $service = new EventCorrelationMatrixService(
        cache: new \Illuminate\Cache\ArrayStore,
        streamService: null,
    );

    $retention = $service->retentionCorrelation('sign_up', ['D1', 'D7', 'D30']);

    expect($retention)->toHaveKey('event');
    expect($retention)->toHaveKey('intervals');
    expect($retention['intervals'])->toHaveKey('D1');
    expect($retention['intervals'])->toHaveKey('D7');
    expect($retention['intervals'])->toHaveKey('D30');
});

test('EventReplayAuditTrailService records and retrieves replay', function (): void {
    $service = new EventReplayAuditTrailService(
        cache: new \Illuminate\Cache\ArrayStore,
    );

    $entryId = $service->recordReplay([
        'type' => 'manual',
        'triggered_by' => 'admin',
        'source' => 'dlq',
        'event_count' => 5,
        'status' => 'pending',
    ]);

    expect($entryId)->toBeString();
    expect(str_starts_with($entryId, 'replay_'))->toBeTrue();

    $entry = $service->getEntry($entryId);

    expect($entry)->not->toBeNull();
    expect($entry['id'])->toBe($entryId);
    expect($entry['type'])->toBe('manual');
    expect($entry['triggered_by'])->toBe('admin');
    expect($entry['event_count'])->toBe(5);
    expect($entry['checksum'])->toBeString();
});

test('EventReplayAuditTrailService records result', function (): void {
    $service = new EventReplayAuditTrailService(
        cache: new \Illuminate\Cache\ArrayStore,
    );

    $entryId = $service->recordReplay(['type' => 'batch', 'status' => 'pending']);
    $service->recordResult($entryId, 'success', ['dispatched' => 3, 'failed' => 0], 150);

    $entry = $service->getEntry($entryId);

    expect($entry['status'])->toBe('success');
    expect($entry['result'])->toBe(['dispatched' => 3, 'failed' => 0]);
    expect($entry['duration_ms'])->toBe(150);
});

test('EventReplayAuditTrailService listEntries with filters', function (): void {
    $service = new EventReplayAuditTrailService(
        cache: new \Illuminate\Cache\ArrayStore,
    );

    $service->recordReplay(['type' => 'manual', 'status' => 'success', 'source' => 'dlq']);
    $service->recordReplay(['type' => 'batch', 'status' => 'success', 'source' => 'archive']);
    $service->recordReplay(['type' => 'manual', 'status' => 'failed', 'source' => 'dlq']);

    $all = $service->listEntries();
    expect($all['total'])->toBe(3);

    $manual = $service->listEntries(['type' => 'manual']);
    expect($manual['total'])->toBe(2);

    $failed = $service->listEntries(['status' => 'failed']);
    expect($failed['total'])->toBe(1);
});

test('EventReplayAuditTrailService statistics', function (): void {
    $service = new EventReplayAuditTrailService(
        cache: new \Illuminate\Cache\ArrayStore,
    );

    $service->recordReplay(['type' => 'manual', 'status' => 'success', 'event_count' => 10]);
    $service->recordReplay(['type' => 'batch', 'status' => 'success', 'event_count' => 25]);

    $stats = $service->statistics();

    expect($stats['total_entries'])->toBe(2);
    expect($stats['total_events_replayed'])->toBe(35);
    expect($stats['by_type'])->toHaveKey('manual');
    expect($stats['by_type'])->toHaveKey('batch');
    expect($stats['success_rate'])->toBe(100.0);
});

test('EventReplayAuditTrailService integrity verification', function (): void {
    $service = new EventReplayAuditTrailService(
        cache: new \Illuminate\Cache\ArrayStore,
    );

    $entryId = $service->recordReplay(['type' => 'test']);

    $result = $service->verifyIntegrity($entryId);

    expect($result['valid'])->toBeTrue();
    expect($result['entry_id'])->toBe($entryId);
});

test('EventReplayAuditTrailService prune', function (): void {
    $service = new EventReplayAuditTrailService(
        cache: new \Illuminate\Cache\ArrayStore,
    );

    $pruned = $service->prune();

    expect($pruned)->toBeInt();
});

test('FeatureFlagAnalyticsBridge registers and tracks evaluation', function (): void {
    $bridge = new FeatureFlagAnalyticsBridge(
        cache: new \Illuminate\Cache\ArrayStore,
    );

    $bridge->registerMapping('new_dashboard', 'feature_impression', ['feature' => 'dashboard']);

    expect($bridge->hasMapping('new_dashboard'))->toBeTrue();
    expect($bridge->hasMapping('nonexistent'))->toBeFalse();

    $event = $bridge->trackEvaluation(
        flagKey: 'new_dashboard',
        variant: true,
        userId: 'user_1',
        clientId: 'client_1',
    );

    expect($event)->not->toBeNull();
    expect($event->name)->toBe('feature_impression');
    expect($event->params['flag_key'])->toBe('new_dashboard');
    expect($event->params['variant'])->toBeTrue();
    expect($event->source)->toBe('feature_flag');
});

test('FeatureFlagAnalyticsBridge deduplicates evaluations', function (): void {
    $bridge = new FeatureFlagAnalyticsBridge(
        cache: new \Illuminate\Cache\ArrayStore,
    );

    $bridge->registerMapping('dark_mode', 'feature_impression');

    $first = $bridge->trackEvaluation('dark_mode', true, 'user_1');
    $second = $bridge->trackEvaluation('dark_mode', true, 'user_1');

    expect($first)->not->toBeNull();
    expect($second)->toBeNull(); // Deduplicated
});

test('FeatureFlagAnalyticsBridge tracks conversions', function (): void {
    $bridge = new FeatureFlagAnalyticsBridge(
        cache: new \Illuminate\Cache\ArrayStore,
    );

    $event = $bridge->trackConversion(
        flagKey: 'new_checkout',
        conversionEvent: 'purchase',
        variant: true,
        userId: 'user_1',
    );

    expect($event)->toBeInstanceOf(AnalyticsEvent::class);
    expect($event->name)->toBe('purchase');
    expect($event->params['flag_key'])->toBe('new_checkout');
    expect($event->params['conversion_type'])->toBe('feature_flag');
});

test('FeatureFlagAnalyticsBridge conversion rate', function (): void {
    $bridge = new FeatureFlagAnalyticsBridge(
        cache: new \Illuminate\Cache\ArrayStore,
    );

    $rate = $bridge->conversionRate('test_flag', 'purchase');

    expect($rate)->toHaveKey('flag_key');
    expect($rate)->toHaveKey('conversion_event');
    expect($rate)->toHaveKey('total_exposures');
    expect($rate)->toHaveKey('total_conversions');
    expect($rate)->toHaveKey('conversion_rate');
    expect($rate)->toHaveKey('by_variant');
});

test('FeatureFlagAnalyticsBridge removeMapping', function (): void {
    $bridge = new FeatureFlagAnalyticsBridge(
        cache: new \Illuminate\Cache\ArrayStore,
    );

    $bridge->registerMapping('temp_flag', 'temp_event');
    expect($bridge->hasMapping('temp_flag'))->toBeTrue();

    $bridge->removeMapping('temp_flag');
    expect($bridge->hasMapping('temp_flag'))->toBeFalse();
});

test('FeatureFlagAnalyticsBridge diagnosticSummary', function (): void {
    $bridge = new FeatureFlagAnalyticsBridge(
        cache: new \Illuminate\Cache\ArrayStore,
    );

    $bridge->registerMapping('flag_a', 'event_a');
    $bridge->registerMapping('flag_b', 'event_b');

    $summary = $bridge->diagnosticSummary();

    expect($summary['registered_mappings'])->toBe(2);
    expect($summary['mapping_keys'])->toContain('flag_a');
    expect($summary['mapping_keys'])->toContain('flag_b');
});

test('EventCorrelationMatrixService diagnosticSummary', function (): void {
    $service = new EventCorrelationMatrixService(
        cache: new \Illuminate\Cache\ArrayStore,
    );

    $summary = $service->diagnosticSummary();

    expect($summary['default_window'])->toBe(604800);
    expect($summary['min_sample_size'])->toBe(30);
    expect($summary['cache_ttl'])->toBe(3600);
});

test('EventReplayAuditTrailService diagnosticSummary', function (): void {
    $service = new EventReplayAuditTrailService(
        cache: new \Illuminate\Cache\ArrayStore,
    );

    $summary = $service->diagnosticSummary();

    expect($summary['max_entries'])->toBe(10000);
    expect($summary['cache_ttl'])->toBe(2592000);
    expect($summary['cache_prefix'])->toBeString();
});

test('DataQualityScoringEngine validity detects bad types', function (): void {
    $engine = new DataQualityScoringEngine();

    // Valid numeric types
    $goodEvent = new AnalyticsEvent(
        name: 'purchase',
        params: ['value' => 99.99, 'quantity' => 3, 'currency' => 'USD'],
        clientId: 'c1',
        category: 'ecommerce',
    );

    $goodResult = $engine->scoreEvent($goodEvent);
    expect($goodResult['dimensions']['validity']['score'])->toBeGreaterThan(50.0);

    // Invalid types — string where numeric expected
    $badEvent = new AnalyticsEvent(
        name: 'purchase',
        params: ['value' => 'not_a_number', 'quantity' => 'abc', 'currency' => 'USD'],
        clientId: 'c1',
        category: 'ecommerce',
    );

    $badResult = $engine->scoreEvent($badEvent);
    // Score should be lower due to type mismatches
    expect($badResult['dimensions']['validity']['score'])->toBeLessThanOrEqual($goodResult['dimensions']['validity']['score']);
});

test('Event catalog roundtrip consistency', function (): void {
    // Verify all three catalogs have valid entries
    $ecommerce = EcommerceEvents::all();
    $saas = SaaSEvents::all();
    $engagement = EngagementEvents::all();

    expect($ecommerce)->toBeArray();
    expect($saas)->toBeArray();
    expect($engagement)->toBeArray();

    expect(EcommerceEvents::count())->toBeGreaterThan(0);
    expect(SaaSEvents::count())->toBeGreaterThan(0);
    expect(EngagementEvents::count())->toBeGreaterThan(0);

    // Verify required keys
    foreach ($ecommerce as $name => $entry) {
        expect($entry)->toHaveKey('name');
        expect($entry)->toHaveKey('class');
        expect($entry)->toHaveKey('ga4');
        expect($entry)->toHaveKey('meta');
        expect($entry)->toHaveKey('posthog');
    }

    // Verify categories
    expect(EcommerceEvents::category())->toBe('ecommerce');
    expect(SaaSEvents::category())->toBe('saas');
    expect(EngagementEvents::category())->toBe('engagement');
});

test('Version consistency across DTO and catalogs', function (): void {
    $version = AnalyticsEvent::VERSION;

    expect($version)->toBeString();
    expect(str_starts_with($version, '202'))->toBeTrue();

    // Event catalog should contain entries for all three categories
    $catalog = EventCatalog::summary();

    expect($catalog['total_events'])->toBeGreaterThan(0);
    expect($catalog['categories'])->toHaveKey('ecommerce');
    expect($catalog['categories'])->toHaveKey('saas');
    expect($catalog['categories'])->toHaveKey('engagement');
});

test('DataQualityScoringEngine empty batch returns zeros', function (): void {
    $engine = new DataQualityScoringEngine();

    $batch = $engine->scoreBatch([]);

    expect($batch['avg_score'])->toBe(0.0);
    expect($batch['min_score'])->toBe(0.0);
    expect($batch['max_score'])->toBe(0.0);
    expect($batch['total_issues'])->toBe(0);
});

test('EventReplayAuditTrailService getEntry returns null for unknown', function (): void {
    $service = new EventReplayAuditTrailService(
        cache: new \Illuminate\Cache\ArrayStore,
    );

    expect($service->getEntry('nonexistent_id'))->toBeNull();
});

test('EventReplayAuditTrailService verifyIntegrity for unknown entry', function (): void {
    $service = new EventReplayAuditTrailService(
        cache: new \Illuminate\Cache\ArrayStore,
    );

    $result = $service->verifyIntegrity('nonexistent');

    expect($result['valid'])->toBeFalse();
    expect($result['computed_checksum'])->toBe('entry_not_found');
});
