<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\AnalyticsEventReliabilityService;
use ZeroBoiler\Analytics\Services\AnalyticsPipelineProfilerService;

test('pipeline profiler records dispatch measurements', function (): void {
    $cache = app(CacheRepository::class);
    $manager = app(AnalyticsManager::class);

    $profiler = new AnalyticsPipelineProfilerService($manager, $cache, [
        'slow_threshold_ms' => 500.0,
        'critical_threshold_ms' => 1000.0,
    ]);

    $profiler->record('ga4', 45.0, 'page_view');
    $profiler->record('ga4', 120.0, 'sign_up');
    $profiler->record('meta', 200.0, 'purchase');

    $summary = $profiler->requestSummary();

    expect($summary['dispatch_count'])->toBe(3);
    expect($summary['total_latency_ms'])->toBeGreaterThan(0);
    expect($summary['avg_latency_ms'])->toBeGreaterThan(0);
});

test('pipeline profiler computes provider profile with percentiles', function (): void {
    $cache = app(CacheRepository::class);
    $manager = app(AnalyticsManager::class);

    $profiler = new AnalyticsPipelineProfilerService($manager, $cache);

    // Record 20 samples with known latency
    for ($i = 0; $i < 20; $i++) {
        $profiler->record('ga4', (float) (50 + $i * 10), 'page_view');
    }

    $profile = $profiler->providerProfile('ga4');

    expect($profile['count'])->toBe(20);
    expect($profile['min'])->toBeLessThan($profile['max']);
    expect($profile['avg'])->toBeGreaterThan(0);
    expect($profile['p50'])->toBeGreaterThan(0);
    expect($profile['p95'])->toBeGreaterThanOrEqual($profile['p50']);
    expect($profile['p99'])->toBeGreaterThanOrEqual($profile['p95']);
    expect($profile['buckets'])->toBeArray();
    expect($profile['buckets'])->toHaveKeys(['fast', 'normal', 'slow', 'very_slow', 'timeout']);
});

test('pipeline profiler profile returns empty for unknown provider', function (): void {
    $cache = app(CacheRepository::class);
    $manager = app(AnalyticsManager::class);

    $profiler = new AnalyticsPipelineProfilerService($manager, $cache);

    $profile = $profiler->providerProfile('nonexistent_provider');

    expect($profile['count'])->toBe(0);
    expect($profile['min'])->toBe(0.0);
    expect($profile['avg'])->toBe(0.0);
});

test('pipeline profiler records slow events', function (): void {
    $cache = app(CacheRepository::class);
    $manager = app(AnalyticsManager::class);

    $profiler = new AnalyticsPipelineProfilerService($manager, $cache, [
        'slow_threshold_ms' => 100.0,
        'critical_threshold_ms' => 500.0,
    ]);

    $profiler->record('ga4', 150.0, 'page_view'); // slow
    $profiler->record('meta', 600.0, 'purchase');  // critical

    $slowEvents = $profiler->slowEvents();

    expect($slowEvents)->toHaveCount(2);
    expect($slowEvents[0]['provider'])->toBe('ga4');
    expect($slowEvents[0]['event'])->toBe('page_view');
    expect($slowEvents[0]['latency_ms'])->toBe(150.0);
    expect($slowEvents[1]['provider'])->toBe('meta');
    expect($slowEvents[1]['latency_ms'])->toBe(600.0);
});

test('pipeline profiler dashboard aggregates all metrics', function (): void {
    $cache = app(CacheRepository::class);
    $manager = app(AnalyticsManager::class);

    $profiler = new AnalyticsPipelineProfilerService($manager, $cache);

    $profiler->record('ga4', 50.0, 'page_view');
    $profiler->record('meta', 100.0, 'purchase');

    $dashboard = $profiler->dashboard();

    expect($dashboard)->toHaveKey('version');
    expect($dashboard)->toHaveKey('providers');
    expect($dashboard)->toHaveKey('categories');
    expect($dashboard)->toHaveKey('slow_events');
    expect($dashboard)->toHaveKey('slow_threshold_ms');
    expect($dashboard)->toHaveKey('critical_threshold_ms');
    expect($dashboard)->toHaveKey('request');
    expect($dashboard)->toHaveKey('degraded_providers');
    expect($dashboard['version'])->toBeString();
    expect($dashboard['request']['dispatch_count'])->toBe(2);
});

test('pipeline profiler flush clears all data', function (): void {
    $cache = app(CacheRepository::class);
    $manager = app(AnalyticsManager::class);

    $profiler = new AnalyticsPipelineProfilerService($manager, $cache);

    $profiler->record('ga4', 50.0, 'page_view');
    expect($profiler->requestSummary()['dispatch_count'])->toBe(1);

    $profiler->flush();

    expect($profiler->requestSummary()['dispatch_count'])->toBe(0);
});

test('event reliability service records success and failure', function (): void {
    $cache = app(CacheRepository::class);

    $reliability = new AnalyticsEventReliabilityService($cache);

    $reliability->recordSuccess('ga4', 'page_view');
    $reliability->recordSuccess('ga4', 'page_view');
    $reliability->recordSuccess('ga4', 'page_view');
    $reliability->recordFailure('ga4', 'page_view', 'timeout');

    $score = $reliability->providerScore('ga4');

    expect($score['total_count'])->toBe(4);
    expect($score['success_count'])->toBe(3);
    expect($score['failure_count'])->toBe(1);
    expect($score['success_rate'])->toBe(0.75);
    expect($score['grade'])->toBe('D');
    expect($score['is_degraded'])->toBeTrue();
});

test('event reliability returns N/A for provider with no traffic', function (): void {
    $cache = app(CacheRepository::class);

    $reliability = new AnalyticsEventReliabilityService($cache);

    $score = $reliability->providerScore('ga4');

    expect($score['score'])->toBe(1.0);
    expect($score['grade'])->toBe('N/A');
    expect($score['total_count'])->toBe(0);
});

test('event reliability overall score averages active providers', function (): void {
    $cache = app(CacheRepository::class);

    $reliability = new AnalyticsEventReliabilityService($cache);

    // ga4: 9/10 = 0.90 (C grade)
    for ($i = 0; $i < 9; $i++) {
        $reliability->recordSuccess('ga4', 'page_view');
    }
    $reliability->recordFailure('ga4', 'page_view', 'timeout');

    // meta: 10/10 = 1.0 (A+ grade)
    for ($i = 0; $i < 10; $i++) {
        $reliability->recordSuccess('meta', 'purchase');
    }

    $overall = $reliability->overallScore();

    expect($overall['provider_count'])->toBe(2);
    expect($overall['score'])->toBe(0.95); // avg(0.90, 1.0)
    expect($overall['grade'])->toBe('B');
    expect($overall['degraded_count'])->toBe(1);
    expect($overall['critical_count'])->toBe(0);
});

test('event reliability grades are correct', function (): void {
    $cache = app(CacheRepository::class);

    $reliability = new AnalyticsEventReliabilityService($cache);

    // 100% success
    $reliability->recordSuccess('test', 'e');
    expect($reliability->providerScore('test')['grade'])->toBe('A+');

    // Simulate various rates
    $reliability->flush();

    // Test letter grades via the private gradeFromRate logic indirectly
    // 1/100 = 0.01 → F
    $reliability->recordSuccess('test', 'e');
    for ($i = 0; $i < 99; $i++) {
        $reliability->recordFailure('test', 'e', 'err');
    }
    expect($reliability->providerScore('test')['grade'])->toBe('F');
});

test('event reliability dedup stats', function (): void {
    $cache = app(CacheRepository::class);

    $reliability = new AnalyticsEventReliabilityService($cache);

    $reliability->recordSuccess('ga4', 'page_view');
    $reliability->recordDedupCollision('page_view');

    $stats = $reliability->dedupStats();

    expect($stats['collisions'])->toBe(1);
    expect($stats['total'])->toBe(2); // 1 success + 1 dedup
    expect($stats['rate'])->toBe(0.5);
});

test('event reliability dashboard', function (): void {
    $cache = app(CacheRepository::class);

    $reliability = new AnalyticsEventReliabilityService($cache);

    $reliability->recordSuccess('ga4', 'page_view');
    $reliability->recordFailure('meta', 'purchase', 'connection refused');

    $dashboard = $reliability->dashboard();

    expect($dashboard)->toHaveKey('version');
    expect($dashboard)->toHaveKey('overall');
    expect($dashboard)->toHaveKey('providers');
    expect($dashboard)->toHaveKey('recent_failures');
    expect($dashboard)->toHaveKey('dedup');
    expect($dashboard)->toHaveKey('thresholds');
    expect($dashboard['thresholds']['warning'])->toBe(0.90);
    expect($dashboard['thresholds']['critical'])->toBe(0.75);
    expect($dashboard['recent_failures'])->toHaveCount(1);
});

test('event reliability flush clears all data', function (): void {
    $cache = app(CacheRepository::class);

    $reliability = new AnalyticsEventReliabilityService($cache);

    $reliability->recordSuccess('ga4', 'page_view');
    $reliability->recordFailure('ga4', 'page_view', 'timeout');
    $reliability->recordDedupCollision('page_view');

    $reliability->flush();

    $score = $reliability->providerScore('ga4');
    expect($score['total_count'])->toBe(0);

    $stats = $reliability->dedupStats();
    expect($stats['collisions'])->toBe(0);

    $failures = $reliability->recentFailures();
    expect($failures)->toBeEmpty();
});

test('pipeline profiler latency bucket distribution', function (): void {
    $cache = app(CacheRepository::class);
    $manager = app(AnalyticsManager::class);

    $profiler = new AnalyticsPipelineProfilerService($manager, $cache, [
        'slow_threshold_ms' => 500.0,
    ]);

    // Record samples in different buckets
    $profiler->record('ga4', 10.0, 'e1');   // fast (<50ms)
    $profiler->record('ga4', 100.0, 'e2');  // normal (<200ms)
    $profiler->record('ga4', 400.0, 'e3');  // slow (<500ms)
    $profiler->record('ga4', 700.0, 'e4');  // very_slow (<1000ms)
    $profiler->record('ga4', 1500.0, 'e5'); // timeout (>=1000ms)

    $profile = $profiler->providerProfile('ga4');

    expect($profile['buckets']['fast'])->toBe(1);
    expect($profile['buckets']['normal'])->toBe(1);
    expect($profile['buckets']['slow'])->toBe(1);
    expect($profile['buckets']['very_slow'])->toBe(1);
    expect($profile['buckets']['timeout'])->toBe(1);
    expect($profile['slow_count'])->toBe(2); // 700ms + 1500ms >= 500ms threshold
    expect($profile['critical_count'])->toBe(1); // 1500ms >= 1000ms
});

test('pipeline profiler category detection from EventCatalog', function (): void {
    $cache = app(CacheRepository::class);
    $manager = app(AnalyticsManager::class);

    $profiler = new AnalyticsPipelineProfilerService($manager, $cache);

    // These events should be categorizable via EventCatalog::getCategory
    $profiler->record('ga4', 50.0, 'page_view');     // engagement
    $profiler->record('ga4', 50.0, 'purchase');       // ecommerce
    $profiler->record('ga4', 50.0, 'sign_up');        // saas
    $profiler->record('ga4', 50.0, 'custom_event');   // custom (not in catalog)

    $categoryProfile = $profiler->categoryProfile();

    expect($categoryProfile)->toHaveKey('engagement');
    expect($categoryProfile)->toHaveKey('ecommerce');
    expect($categoryProfile)->toHaveKey('saas');
    expect($categoryProfile['engagement']['count'])->toBeGreaterThanOrEqual(1);
    expect($categoryProfile['ecommerce']['count'])->toBeGreaterThanOrEqual(1);
    expect($categoryProfile['saas']['count'])->toBeGreaterThanOrEqual(1);
});
