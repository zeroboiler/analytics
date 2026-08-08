<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventBucketsService;
use ZeroBoiler\Analytics\Services\SaaSHealthScoreService;
use ZeroBoiler\Analytics\Services\UserJourneyService;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;

beforeEach(function (): void {
    $this->cache = app('cache');
    $this->cache->flush();
});

// ── Event Buckets Service ──────────────────────────────────────

test('EventBucketsService class exists', function (): void {
    expect(class_exists(EventBucketsService::class))->toBeTrue();
});

test('EventBucketsService record and retrieve buckets', function (): void {
    $config = mock(Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.event_buckets', [])->andReturn([
        'enabled' => true,
        'cache_ttl' => 3600,
        'max_series' => 50,
        'max_buckets_per_series' => 1000,
    ]);

    $service = new EventBucketsService($this->cache, $config);

    // Record events
    $service->record('page_views', 'page_view', 'user-1', 'client-1', 0.0);
    $service->record('page_views', 'page_view', 'user-2', 'client-2', 0.0);

    // Get hourly buckets
    $buckets = $service->getBuckets('page_views', 'hour', 24);
    expect($buckets)->toHaveCount(1);
    expect($buckets[0]['count'])->toBe(2);
    expect($buckets[0]['unique_users'])->toBe(2);
    expect($buckets[0]['unique_clients'])->toBe(2);
});

test('EventBucketsService tracks per-event breakdown', function (): void {
    $config = mock(Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.event_buckets', [])->andReturn([
        'enabled' => true,
        'cache_ttl' => 3600,
    ]);

    $service = new EventBucketsService($this->cache, $config);

    $service->record('mixed_events', 'page_view', 'user-1');
    $service->record('mixed_events', 'button_click', 'user-1');
    $service->record('mixed_events', 'page_view', 'user-2');

    $buckets = $service->getBuckets('mixed_events', 'hour', 24);
    expect($buckets[0]['count'])->toBe(3);
    expect($buckets[0]['events'])->toHaveKey('page_view');
    expect($buckets[0]['events']['page_view'])->toBe(2);
    expect($buckets[0]['events']['button_click'])->toBe(1);
});

test('EventBucketsService summary aggregates correctly', function (): void {
    $config = mock(Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.event_buckets', [])->andReturn([
        'enabled' => true,
        'cache_ttl' => 3600,
    ]);

    $service = new EventBucketsService($this->cache, $config);

    $service->record('revenue_events', 'purchase', 'user-1', 'client-1', 99.99);
    $service->record('revenue_events', 'purchase', 'user-2', 'client-2', 49.99);

    $summary = $service->summary('revenue_events', 'hour', 24);
    expect($summary['total_events'])->toBe(2);
    expect($summary['total_value'])->toBe(149.98);
    expect($summary['bucket_count'])->toBe(1);
});

test('EventBucketsService compare two series', function (): void {
    $config = mock(Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.event_buckets', [])->andReturn([
        'enabled' => true,
        'cache_ttl' => 3600,
    ]);

    $service = new EventBucketsService($this->cache, $config);

    $service->record('signups', 'sign_up', 'user-1');
    $service->record('signups', 'sign_up', 'user-2');
    $service->record('signups', 'sign_up', 'user-3');
    $service->record('trials', 'start_trial', 'user-1');
    $service->record('trials', 'start_trial', 'user-2');

    $comparison = $service->compare('signups', 'trials', 'hour', 24);
    expect($comparison)->toHaveCount(1);
    expect($comparison[0]['a_count'])->toBe(3);
    expect($comparison[0]['b_count'])->toBe(2);
    expect($comparison[0]['ratio'])->toBeCloseTo(0.6667, 3);
});

test('EventBucketsService series list works', function (): void {
    $config = mock(Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.event_buckets', [])->andReturn([
        'enabled' => true,
        'cache_ttl' => 3600,
    ]);

    $service = new EventBucketsService($this->cache, $config);

    $service->record('test_series', 'click');
    $list = $service->seriesList();
    expect($list)->toContain('test_series');
});

test('EventBucketsService delete series works', function (): void {
    $config = mock(Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.event_buckets', [])->andReturn([
        'enabled' => true,
        'cache_ttl' => 3600,
    ]);

    $service = new EventBucketsService($this->cache, $config);

    $service->record('temp_series', 'click');
    expect($service->seriesList())->toContain('temp_series');

    $service->deleteSeries('temp_series');
    expect($service->seriesList())->not->toContain('temp_series');
});

test('EventBucketsService does nothing when disabled', function (): void {
    $config = mock(Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.event_buckets', [])->andReturn([
        'enabled' => false,
    ]);

    $service = new EventBucketsService($this->cache, $config);
    expect($service->isEnabled())->toBeFalse();

    $service->record('test', 'click');
    $buckets = $service->getBuckets('test', 'hour');
    expect($buckets)->toBeEmpty();
});

test('EventBucketsService available granularities', function (): void {
    $granularities = EventBucketsService::availableGranularities();
    expect($granularities)->toContain('minute');
    expect($granularities)->toContain('hour');
    expect($granularities)->toContain('day');
    expect($granularities)->toContain('week');
    expect($granularities)->toContain('month');
});

// ── SaaS Health Score Service ──────────────────────────────────

test('SaaSHealthScoreService class exists', function (): void {
    expect(class_exists(SaaSHealthScoreService::class))->toBeTrue();
});

test('SaaSHealthScoreService calculates with overrides', function (): void {
    $config = mock(Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.health_score', [])->andReturn([
        'enabled' => true,
        'cache_ttl' => 3600,
    ]);

    $kpiTracker = mock(ZeroBoiler\Analytics\Services\SaasKpiTracker::class);
    $kpiTracker->shouldReceive('getActiveSubscriberCount')->andReturn(120);
    $kpiTracker->shouldReceive('getArpu')->andReturn(49.99);
    $kpiTracker->shouldReceive('getChurnRate')->andReturn(0.03);
    $kpiTracker->shouldReceive('getTrialConversionRate')->andReturn(0.35);
    $kpiTracker->shouldReceive('getClv')->andReturn(500.0);
    $kpiTracker->shouldReceive('getMrrHistory')->with(2)->andReturn([
        ['timestamp' => time() - 86400, 'mrr' => 4800.0],
        ['timestamp' => time(), 'mrr' => 5000.0],
    ]);

    $service = new SaaSHealthScoreService($this->cache, $config, $kpiTracker);

    $result = $service->calculate();
    expect($result)->toHaveKey('score');
    expect($result)->toHaveKey('grade');
    expect($result)->toHaveKey('sub_scores');
    expect($result['score'])->toBeGreaterThanOrEqual(0);
    expect($result['score'])->toBeLessThanOrEqual(100);
    expect($result['grade'])->toBeIn(['A', 'B', 'C', 'D', 'F']);
});

test('SaaSHealthScoreService grade assignment', function (): void {
    $config = mock(Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.health_score', [])->andReturn([
        'enabled' => true,
    ]);

    $kpiTracker = mock(ZeroBoiler\Analytics\Services\SaasKpiTracker::class);
    $service = new SaaSHealthScoreService($this->cache, $config, $kpiTracker);

    expect($service->grade(95))->toBe('A');
    expect($service->grade(85))->toBe('B');
    expect($service->grade(65))->toBe('C');
    expect($service->grade(45))->toBe('D');
    expect($service->grade(10))->toBe('F');
});

test('SaaSHealthScoreService engagement sub-score', function (): void {
    $config = mock(Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.health_score', [])->andReturn([
        'enabled' => true,
        'weights' => ['engagement' => 0.25, 'revenue' => 0.30, 'conversion' => 0.25, 'retention' => 0.20],
    ]);

    $kpiTracker = mock(ZeroBoiler\Analytics\Services\SaasKpiTracker::class);
    $service = new SaaSHealthScoreService($this->cache, $config, $kpiTracker);

    $score = $service->engagementScore([
        'active_users' => 100,
        'total_users_target' => 200,
        'arpu' => 50.0,
        'features_used' => 8,
        'sessions_per_day' => 250,
    ]);

    expect($score['score'])->toBeGreaterThanOrEqual(0);
    expect($score['score'])->toBeLessThanOrEqual(100);
    expect($score['weight'])->toBe(0.25);
    expect($score['factors'])->toHaveKey('active_ratio');
});

test('SaaSHealthScoreService revenue sub-score', function (): void {
    $config = mock(Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.health_score', [])->andReturn([
        'enabled' => true,
        'weights' => ['engagement' => 0.25, 'revenue' => 0.30, 'conversion' => 0.25, 'retention' => 0.20],
    ]);

    $kpiTracker = mock(ZeroBoiler\Analytics\Services\SaasKpiTracker::class);
    $service = new SaaSHealthScoreService($this->cache, $config, $kpiTracker);

    $score = $service->revenueScore([
        'churn_rate' => 0.02,
        'arpu' => 80.0,
        'clv' => 600.0,
    ]);

    expect($score['score'])->toBeGreaterThanOrEqual(0);
    expect($score['weight'])->toBe(0.30);
});

test('SaaSHealthScoreService conversion sub-score', function (): void {
    $config = mock(Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.health_score', [])->andReturn([
        'enabled' => true,
        'weights' => ['engagement' => 0.25, 'revenue' => 0.30, 'conversion' => 0.25, 'retention' => 0.20],
    ]);

    $kpiTracker = mock(ZeroBoiler\Analytics\Services\SaasKpiTracker::class);
    $service = new SaaSHealthScoreService($this->cache, $config, $kpiTracker);

    $score = $service->conversionScore([
        'trial_conversion' => 0.40,
        'signups' => 100,
        'trials' => 70,
        'arpu' => 50.0,
    ]);

    expect($score['score'])->toBeGreaterThanOrEqual(0);
    expect($score['weight'])->toBe(0.25);
});

test('SaaSHealthScoreService retention sub-score', function (): void {
    $config = mock(Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.health_score', [])->andReturn([
        'enabled' => true,
        'weights' => ['engagement' => 0.25, 'revenue' => 0.30, 'conversion' => 0.25, 'retention' => 0.20],
    ]);

    $kpiTracker = mock(ZeroBoiler\Analytics\Services\SaasKpiTracker::class);
    $service = new SaaSHealthScoreService($this->cache, $config, $kpiTracker);

    $score = $service->retentionScore([
        'churn_rate' => 0.01,
        'avg_subscription_age' => 200,
        'renewal_rate' => 0.90,
        'plan_stability' => 0.85,
    ]);

    expect($score['score'])->toBeGreaterThanOrEqual(0);
    expect($score['weight'])->toBe(0.20);
});

test('SaaSHealthScoreService returns disabled response when disabled', function (): void {
    $config = mock(Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.health_score', [])->andReturn([
        'enabled' => false,
    ]);

    $kpiTracker = mock(ZeroBoiler\Analytics\Services\SaasKpiTracker::class);
    $service = new SaaSHealthScoreService($this->cache, $config, $kpiTracker);

    expect($service->isEnabled())->toBeFalse();

    $result = $service->calculate();
    expect($result['score'])->toBe(0);
    expect($result['grade'])->toBe('N/A');
});

test('SaaSHealthScoreService history tracking', function (): void {
    $config = mock(Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.health_score', [])->andReturn([
        'enabled' => true,
    ]);

    $kpiTracker = mock(ZeroBoiler\Analytics\Services\SaasKpiTracker::class);
    $kpiTracker->shouldReceive('getActiveSubscriberCount')->andReturn(50);
    $kpiTracker->shouldReceive('getArpu')->andReturn(25.0);
    $kpiTracker->shouldReceive('getChurnRate')->andReturn(0.05);
    $kpiTracker->shouldReceive('getTrialConversionRate')->andReturn(0.30);
    $kpiTracker->shouldReceive('getClv')->andReturn(250.0);
    $kpiTracker->shouldReceive('getMrrHistory')->with(2)->andReturn([]);

    $service = new SaaSHealthScoreService($this->cache, $config, $kpiTracker);

    $service->calculate();
    $service->calculate();

    $history = $service->history(10);
    expect($history)->toHaveCount(2);
    expect($history[0])->toHaveKey('score');
    expect($history[0])->toHaveKey('grade');
    expect($history[0])->toHaveKey('calculated_at');
});

// ── User Journey Timeline ─────────────────────────────────────

test('UserJourneyService class exists', function (): void {
    expect(class_exists(UserJourneyService::class))->toBeTrue();
});

test('UserJourneyService record and retrieve journey', function (): void {
    $manager = mock(AnalyticsManager::class);
    $queue = mock(QueuedAnalyticsDispatcher::class);

    $service = new UserJourneyService($manager, $queue, async: false);

    $service->recordStep('session-1', 'page_view', ['url' => '/home'], 'user-1', 'client-1', '/home');
    $service->recordStep('session-1', 'button_click', ['element' => 'cta'], 'user-1', 'client-1', '/home');
    $service->recordStep('session-1', 'sign_up', ['method' => 'email'], 'user-1', 'client-1', '/signup');

    $journey = $service->getJourney('session-1');
    expect($journey)->not->toBeNull();
    expect($journey['step_count'])->toBe(3);
    expect($journey['user_id'])->toBe('user-1');
    expect($journey['client_id'])->toBe('client-1');
    expect($journey['event_sequence'])->toContain('page_view');
    expect($journey['event_sequence'])->toContain('button_click');
    expect($journey['event_sequence'])->toContain('sign_up');
});

test('UserJourneyService page flow extraction', function (): void {
    $manager = mock(AnalyticsManager::class);
    $queue = mock(QueuedAnalyticsDispatcher::class);

    $service = new UserJourneyService($manager, $queue, async: false);

    $service->recordStep('flow-1', 'page_view', [], null, null, '/home');
    $service->recordStep('flow-1', 'click', [], null, null, '/home');
    $service->recordStep('flow-1', 'page_view', [], null, null, '/pricing');
    $service->recordStep('flow-1', 'page_view', [], null, null, '/pricing');
    $service->recordStep('flow-1', 'sign_up', [], null, null, '/signup');

    $pageFlow = $service->getPageFlow('flow-1');
    expect($pageFlow)->toBe(['/home', '/pricing', '/signup']);
});

test('UserJourneyService most common patterns', function (): void {
    $manager = mock(AnalyticsManager::class);
    $queue = mock(QueuedAnalyticsDispatcher::class);

    $service = new UserJourneyService($manager, $queue, async: false);

    // Create two identical journeys
    $service->recordStep('p1', 'page_view', [], null, null, '/home');
    $service->recordStep('p1', 'add_to_cart', [], null, null, '/product');
    $service->recordStep('p1', 'purchase', [], null, null, '/checkout');

    $service->recordStep('p2', 'page_view', [], null, null, '/home');
    $service->recordStep('p2', 'add_to_cart', [], null, null, '/product');
    $service->recordStep('p2', 'purchase', [], null, null, '/checkout');

    $patterns = $service->mostCommonPatterns(0, 10);
    expect($patterns)->toHaveCount(1);
    expect($patterns[0]['pattern'])->toBe('page_view → add_to_cart → purchase');
    expect($patterns[0]['count'])->toBe(2);
});

test('UserJourneyService drop-off points', function (): void {
    $manager = mock(AnalyticsManager::class);
    $queue = mock(QueuedAnalyticsDispatcher::class);

    $service = new UserJourneyService($manager, $queue, async: false);

    // Two journeys ending at different points
    $service->recordStep('d1', 'page_view', [], null, null, '/home');
    $service->recordStep('d1', 'exit', [], null, null, '/home');

    $service->recordStep('d2', 'page_view', [], null, null, '/home');
    $service->recordStep('d2', 'exit', [], null, null, '/home');

    $service->recordStep('d3', 'page_view', [], null, null, '/pricing');
    $service->recordStep('d3', 'purchase', [], null, null, '/checkout');

    $dropOffs = $service->dropOffPoints(10);
    expect($dropOffs)->toHaveCount(2);
    expect($dropOffs[0]['event'])->toBe('exit');
    expect($dropOffs[0]['drop_offs'])->toBe(2);
    expect($dropOffs[0]['rate'])->toBeCloseTo(66.67, 1);
});

test('UserJourneyService funnel conversion', function (): void {
    $manager = mock(AnalyticsManager::class);
    $queue = mock(QueuedAnalyticsDispatcher::class);

    $service = new UserJourneyService($manager, $queue, async: false);

    // 3 journeys: 2 complete, 1 abandoned
    $service->recordStep('f1', 'page_view', []);
    $service->recordStep('f1', 'add_to_cart', []);
    $service->recordStep('f1', 'purchase', []);

    $service->recordStep('f2', 'page_view', []);
    $service->recordStep('f2', 'add_to_cart', []);
    $service->recordStep('f2', 'purchase', []);

    $service->recordStep('f3', 'page_view', []);
    $service->recordStep('f3', 'add_to_cart', []);

    $funnel = $service->funnelConversion(['page_view', 'add_to_cart', 'purchase']);
    expect($funnel['entrances'])->toBe(3);
    expect($funnel['completions'])->toBe(2);
    expect($funnel['conversion_rate'])->toBeCloseTo(66.67, 1);
});

test('UserJourneyService stats', function (): void {
    $manager = mock(AnalyticsManager::class);
    $queue = mock(QueuedAnalyticsDispatcher::class);

    $service = new UserJourneyService($manager, $queue, async: false);

    $service->recordStep('s1', 'page_view', [], 'user-1', 'client-1');
    $service->recordStep('s2', 'click', [], null, 'client-2');

    $stats = $service->getStats();
    expect($stats['total_journeys'])->toBe(2);
    expect($stats['journeys_with_identity'])->toBe(1);
    expect($stats['journeys_anonymous'])->toBe(1);
});

test('UserJourneyService journey count', function (): void {
    $manager = mock(AnalyticsManager::class);
    $queue = mock(QueuedAnalyticsDispatcher::class);

    $service = new UserJourneyService($manager, $queue, async: false);
    expect($service->count())->toBe(0);

    $service->recordStep('j1', 'click');
    expect($service->count())->toBe(1);
});

test('UserJourneyService flush clears all', function (): void {
    $manager = mock(AnalyticsManager::class);
    $queue = mock(QueuedAnalyticsDispatcher::class);

    $service = new UserJourneyService($manager, $queue, async: false);

    $service->recordStep('x1', 'click');
    $service->flush();
    expect($service->count())->toBe(0);
});

// ── Routes & Integration ──────────────────────────────────────

test('routes file contains new bucket endpoints', function (): void {
    $content = file_get_contents(base_path('routes/analytics.php'));
    expect($content)->toContain('eventBucketList');
    expect($content)->toContain('eventBuckets');
    expect($content)->toContain('eventBucketSummary');
    expect($content)->toContain('eventBucketCompare');
});

test('routes file contains new health score endpoints', function (): void {
    $content = file_get_contents(base_path('routes/analytics.php'));
    expect($content)->toContain('healthScore');
    expect($content)->toContain('healthScoreCalculate');
    expect($content)->toContain('healthScoreHistory');
});

test('routes file contains new journey endpoints', function (): void {
    $content = file_get_contents(base_path('routes/analytics.php'));
    expect($content)->toContain('journeyTimeline');
    expect($content)->toContain('journeyStats');
    expect($content)->toContain('journeyPatterns');
    expect($content)->toContain('journeyDropOffs');
    expect($content)->toContain('journeySearch');
    expect($content)->toContain('journeyFunnel');
});

test('config has event_buckets section', function (): void {
    $config = include base_path('config/zeroboiler.php');
    expect($config['analytics'])->toHaveKey('event_buckets');
    expect($config['analytics']['event_buckets'])->toHaveKey('enabled');
    expect($config['analytics']['event_buckets'])->toHaveKey('cache_ttl');
});

test('config has health_score section', function (): void {
    $config = include base_path('config/zeroboiler.php');
    expect($config['analytics'])->toHaveKey('health_score');
    expect($config['analytics']['health_score'])->toHaveKey('enabled');
    expect($config['analytics']['health_score'])->toHaveKey('weights');
});

test('composer.json version is 2.55.0', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true);
    expect($composer['version'])->toBe('2.91.0');
});

test('EventBucketsService records value tracking', function (): void {
    $config = mock(Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.event_buckets', [])->andReturn([
        'enabled' => true,
        'cache_ttl' => 3600,
    ]);

    $service = new EventBucketsService($this->cache, $config);

    $service->record('sales', 'purchase', 'user-1', 'client-1', 299.99);
    $service->record('sales', 'purchase', 'user-2', 'client-2', 149.99);
    $service->record('sales', 'refund', 'user-1', 'client-1', -29.99);

    $summary = $service->summary('sales', 'hour', 24);
    expect($summary['total_value'])->toBeCloseTo(419.99, 2);
    expect($summary['total_events'])->toBe(3);
});

test('SaaSHealthScoreService clear works', function (): void {
    $config = mock(Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.health_score', [])->andReturn([
        'enabled' => true,
    ]);

    $kpiTracker = mock(ZeroBoiler\Analytics\Services\SaasKpiTracker::class);
    $kpiTracker->shouldReceive('getActiveSubscriberCount')->andReturn(10);
    $kpiTracker->shouldReceive('getArpu')->andReturn(10.0);
    $kpiTracker->shouldReceive('getChurnRate')->andReturn(0.1);
    $kpiTracker->shouldReceive('getTrialConversionRate')->andReturn(0.2);
    $kpiTracker->shouldReceive('getClv')->andReturn(100.0);
    $kpiTracker->shouldReceive('getMrrHistory')->with(2)->andReturn([]);

    $service = new SaaSHealthScoreService($this->cache, $config, $kpiTracker);

    $service->calculate();
    expect($service->current())->not->toBeNull();

    $service->clear();
    expect($service->current())->toBeNull();
});
