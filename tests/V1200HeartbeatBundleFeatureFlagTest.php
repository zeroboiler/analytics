<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\AnalyticsHeartbeatMonitor;
use ZeroBoiler\Analytics\Services\SaaSFeatureFlagObserver;
use ZeroBoiler\Analytics\Services\SaaSBundleEventService;

beforeEach(function (): void {
    $this->manager = mock(AnalyticsManager::class);
});

// ─── AnalyticsHeartbeatMonitor Tests ─────────────────────────────────

describe('AnalyticsHeartbeatMonitor', function (): void {
    beforeEach(function (): void {
        $this->cache = mock(CacheRepository::class);
        $this->config = mock(ConfigRepository::class);

        // Default config returns
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.heartbeat.ttl', 300)
            ->andReturn(300);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.heartbeat.stale_threshold', 600)
            ->andReturn(600);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.heartbeat.failure_threshold', 5)
            ->andReturn(5);

        // Allow persistState calls throughout all tests
        $this->cache->shouldReceive('put')
            ->with('zb_heartbeat_state', \Mockery::type('array'), 86400)
            ->byDefault();

        // Allow loadState to read (returns null = no persisted state)
        $this->cache->shouldReceive('get')
            ->with('zb_heartbeat_state')
            ->andReturn(null)
            ->byDefault();

        $this->monitor = new AnalyticsHeartbeatMonitor($this->cache, $this->config);
    });

    test('pulse() returns healthy status when no failures', function (): void {
        $this->cache->shouldReceive('put')->once();
        $this->cache->shouldReceive('get')
            ->with('zb_heartbeat_first_pulse')
            ->andReturn(null);
        $this->cache->shouldReceive('put')
            ->with('zb_heartbeat_first_pulse', \Mockery::type('int'), 86400)
            ->once();
        $this->cache->shouldReceive('get')
            ->with('zb_heartbeat_history', [])
            ->andReturn([]);
        $this->cache->shouldReceive('put')
            ->with('zb_heartbeat_history', \Mockery::type('array'), 86400)
            ->once();

        $result = $this->monitor->pulse();

        expect($result['status'])->toBe('healthy');
        expect($result['providers'])->toBe(0);
        expect($result['queue_depth'])->toBe(0);
        expect($result['events_processed'])->toBe(0);
        expect($result['events_failed'])->toBe(0);
    });

    test('pulse() returns degraded status when failures occurred', function (): void {
        $this->monitor->recordFailure('ga4', 'timeout');
        $this->cache->shouldReceive('put')->with('zb_heartbeat_current', \Mockery::type('array'), \Mockery::type('int'))->once();
        $this->cache->shouldReceive('put')->with('zb_heartbeat_history', \Mockery::type('array'), 86400)->once();

        $result = $this->monitor->pulse();

        expect($result['status'])->toBe('degraded');
        expect($result['events_failed'])->toBe(1);
    });

    test('recordDispatch() marks provider as healthy', function (): void {
        $this->monitor->recordFailure('meta', 'error');
        $this->monitor->recordDispatch('meta');

        $states = $this->monitor->providerStates();
        expect($states['meta']['state'])->toBe('closed');
        expect($states['meta']['failures'])->toBe(0);
    });

    test('recordFailure() opens circuit after threshold', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.heartbeat.failure_threshold', 5)
            ->andReturn(3);

        $monitor = new AnalyticsHeartbeatMonitor($this->cache, $this->config);

        $monitor->recordFailure('ga4', 'err1');
        $monitor->recordFailure('ga4', 'err2');
        $monitor->recordFailure('ga4', 'err3');

        $states = $monitor->providerStates();
        expect($states['ga4']['state'])->toBe('open');
        expect($states['ga4']['failures'])->toBe(3);
    });

    test('current() returns unknown when no pulse recorded', function (): void {
        $this->cache->shouldReceive('get')
            ->with('zb_heartbeat_current')
            ->andReturn(null);

        $result = $this->monitor->current();

        expect($result['status'])->toBe('unknown');
        expect($result['stale'])->toBeTrue();
    });

    test('current() returns stale when pulse is too old', function (): void {
        $this->cache->shouldReceive('get')
            ->with('zb_heartbeat_current')
            ->andReturn([
                'status' => 'healthy',
                'timestamp' => time() - 900,
                'providers' => 2,
                'queue_depth' => 0,
            ]);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.heartbeat.stale_threshold', 600)
            ->andReturn(600);

        $result = $this->monitor->current();

        expect($result['stale'])->toBeTrue();
    });

    test('aggregateStats() computes uptime and averages from history', function (): void {
        $this->cache->shouldReceive('get')
            ->with('zb_heartbeat_history', [])
            ->andReturn([
                ['status' => 'healthy', 'timestamp' => time() - 60, 'queue_depth' => 10, 'events_processed' => 5, 'events_failed' => 0],
                ['status' => 'degraded', 'timestamp' => time() - 30, 'queue_depth' => 20, 'events_processed' => 3, 'events_failed' => 1],
                ['status' => 'healthy', 'timestamp' => time(), 'queue_depth' => 5, 'events_processed' => 8, 'events_failed' => 0],
            ]);

        $stats = $this->monitor->aggregateStats(60);

        expect($stats['uptime_pct'])->toBe(66.67); // 2/3 healthy
        expect($stats['avg_queue_depth'])->toBe(11.7); // (10+20+5)/3
        expect($stats['peak_queue_depth'])->toBe(20);
        expect($stats['total_events'])->toBe(16);
        expect($stats['total_failures'])->toBe(1);
        expect($stats['samples'])->toBe(3);
    });

    test('aggregateStats() returns zeros for empty history', function (): void {
        $this->cache->shouldReceive('get')
            ->with('zb_heartbeat_history', [])
            ->andReturn([]);

        $stats = $this->monitor->aggregateStats();

        expect($stats['uptime_pct'])->toBe(0.0);
        expect($stats['samples'])->toBe(0);
    });

    test('resetProvider() resets circuit to closed', function (): void {
        $this->monitor->recordFailure('posthog', 'err');
        $this->monitor->resetProvider('posthog');

        $states = $this->monitor->providerStates();
        expect($states['posthog']['state'])->toBe('closed');
        expect($states['posthog']['failures'])->toBe(0);
    });

    test('isAlive() returns false when stale', function (): void {
        $this->cache->shouldReceive('get')
            ->with('zb_heartbeat_current')
            ->andReturn(null);

        expect($this->monitor->isAlive())->toBeFalse();
    });

    test('clear() removes all cache keys and resets state', function (): void {
        $this->monitor->recordFailure('ga4', 'err');
        $this->monitor->setQueueDepth(42);

        $this->cache->shouldReceive('forget')->with('zb_heartbeat_current')->once();
        $this->cache->shouldReceive('forget')->with('zb_heartbeat_history')->once();
        $this->cache->shouldReceive('forget')->with('zb_heartbeat_first_pulse')->once();
        $this->cache->shouldReceive('forget')->with('zb_heartbeat_state')->once();

        $this->monitor->clear();

        expect($this->monitor->providerStates())->toBeEmpty();
    });
});

// ─── SaaSFeatureFlagObserver Tests ───────────────────────────────────

describe('SaaSFeatureFlagObserver', function (): void {
    beforeEach(function (): void {
        $this->config = mock(ConfigRepository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.feature_flags', [])
            ->andReturn([
                'enabled' => true,
                'track_exposures' => true,
                'track_conversions' => true,
                'ignored_flags' => ['internal_debug'],
            ]);
        $this->config->shouldReceive('get')
            ->with('app.env', 'production')
            ->andReturn('testing');

        $this->observer = new SaaSFeatureFlagObserver($this->manager, $this->config);
    });

    test('recordEvaluation() dispatches ab_test_exposure event', function (): void {
        $this->manager->shouldReceive('trackEvent')
            ->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'ab_test_exposure'
                    && $event->params['flag_name'] === 'new_onboarding'
                    && $event->params['variant'] === 'variant_b'
                    && $event->params['source'] === 'launchdarkly';
            })
            ->once();

        $result = $this->observer->recordEvaluation('new_onboarding', 'variant_b', 'user_1', ['source' => 'launchdarkly']);

        expect($result)->toBeTrue();
    });

    test('recordEvaluation() ignores flagged flags', function (): void {
        $this->manager->shouldNotReceive('trackEvent');

        $result = $this->observer->recordEvaluation('internal_debug', 'control', 'user_1');

        expect($result)->toBeFalse();
    });

    test('recordEvaluation() deduplicates consecutive identical evaluations', function (): void {
        $this->manager->shouldReceive('trackEvent')->once();

        $this->observer->recordEvaluation('new_onboarding', 'variant_b', 'user_1');
        $result = $this->observer->recordEvaluation('new_onboarding', 'variant_b', 'user_1');

        expect($result)->toBeFalse();
    });

    test('recordConversion() dispatches goal_conversion event', function (): void {
        $this->manager->shouldReceive('trackEvent')
            ->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'goal_conversion'
                    && $event->params['flag_name'] === 'new_onboarding'
                    && $event->params['variant'] === 'variant_b'
                    && $event->params['conversion_name'] === 'signup_completed';
            })
            ->once();

        $result = $this->observer->recordConversion('new_onboarding', 'variant_b', 'signup_completed', 'user_1');

        expect($result)->toBeTrue();
    });

    test('shouldIgnoreFlag() returns true for ignored flags', function (): void {
        expect($this->observer->shouldIgnoreFlag('internal_debug'))->toBeTrue();
        expect($this->observer->shouldIgnoreFlag('new_onboarding'))->toBeFalse();
    });

    test('isEnabled() returns config value', function (): void {
        expect($this->observer->isEnabled())->toBeTrue();
    });

    test('summary() returns tracking statistics', function (): void {
        $this->manager->shouldReceive('trackEvent')->twice();

        $this->observer->recordEvaluation('flag_a', 'v1', 'user_1');
        $this->observer->recordConversion('flag_a', 'v1', 'signup', 'user_1');

        $summary = $this->observer->summary();

        expect($summary['enabled'])->toBeTrue();
        expect($summary['exposures_tracked'])->toBe(1);
        expect($summary['ignored_flags'])->toBe(1);
    });

    test('reset() clears dedup state', function (): void {
        $this->manager->shouldReceive('trackEvent')->twice();

        $this->observer->recordEvaluation('flag_a', 'v1', 'user_1');
        $this->observer->reset();

        // After reset, same evaluation should not be deduped
        $result = $this->observer->recordEvaluation('flag_a', 'v1', 'user_1');
        expect($result)->toBeTrue();
    });

    test('recordEvaluation() returns false when disabled', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.feature_flags', [])
            ->andReturn(['enabled' => false]);

        $observer = new SaaSFeatureFlagObserver($this->manager, $config);

        expect($observer->isEnabled())->toBeFalse();
        expect($observer->recordEvaluation('flag', 'v1'))->toBeFalse();
    });
});

// ─── SaaSBundleEventService Tests ─────────────────────────────────────

describe('SaaSBundleEventService', function (): void {

    beforeEach(function (): void {
        $this->service = new SaaSBundleEventService($this->manager);
    });

    test('startBundle() returns bundle ID and fires journey_start', function (): void {
        $this->manager->shouldReceive('trackEvent')
            ->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'journey_start'
                    && isset($event->params['bundle_id'])
                    && $event->params['journey_name'] === 'signup_funnel';
            })
            ->once();

        $bundleId = $this->service->startBundle('signup_funnel', 'user_1');

        expect($bundleId)->toBeString();
        expect(str_starts_with($bundleId, 'bnd_'))->toBeTrue();
    });

    test('addToBundle() enriches event with bundle context', function (): void {
        $this->manager->shouldReceive('trackEvent')->twice(); // journey_start + sign_up

        $bundleId = $this->service->startBundle('signup_funnel', 'user_1');
        $result = $this->service->addToBundle($bundleId, 'sign_up', ['method' => 'email']);

        expect($result)->toBeTrue();

        $bundle = $this->service->getBundle($bundleId);
        expect($bundle['events'][0]['event'])->toBe('sign_up');
        expect($bundle['events'][0]['params']['bundle_id'])->toBe($bundleId);
        expect($bundle['events'][0]['params']['bundle_step'])->toBe(1);
    });

    test('addToBundle() returns false for non-existent bundle', function (): void {
        expect($this->service->addToBundle('nonexistent', 'sign_up'))->toBeFalse();
    });

    test('addToBundle() returns false for completed bundle', function (): void {
        $this->manager->shouldReceive('trackEvent')->times(4); // start + 3 events

        $bundleId = $this->service->startBundle('signup_funnel', 'user_1');
        $this->service->completeBundle($bundleId, 'subscribe');

        expect($this->service->addToBundle($bundleId, 'another_event'))->toBeFalse();
    });

    test('completeBundle() fires journey_completed with sequence', function (): void {
        $this->manager->shouldReceive('trackEvent')
            ->withArgs(fn (AnalyticsEvent $e): bool => $e->name === 'journey_start')
            ->once();
        $this->manager->shouldReceive('trackEvent')
            ->withArgs(fn (AnalyticsEvent $e): bool => $e->name === 'sign_up')
            ->once();
        $this->manager->shouldReceive('trackEvent')
            ->withArgs(fn (AnalyticsEvent $e): bool => $e->name === 'subscribe')
            ->once();
        $this->manager->shouldReceive('trackEvent')
            ->withArgs(function (AnalyticsEvent $e): bool {
                return $e->name === 'journey_completed'
                    && ($e->params['steps_completed'] ?? 0) === 2
                    && is_array($e->params['event_sequence'])
                    && count($e->params['event_sequence']) === 2;
            })
            ->once();

        $bundleId = $this->service->startBundle('signup_funnel', 'user_1');
        $this->service->addToBundle($bundleId, 'sign_up');
        $result = $this->service->completeBundle($bundleId, 'subscribe');

        expect($result)->toBeTrue();

        $bundle = $this->service->getBundle($bundleId);
        expect($bundle['completed'])->toBeTrue();
    });

    test('abandonBundle() fires journey_abandoned', function (): void {
        $this->manager->shouldReceive('trackEvent')
            ->withArgs(fn (AnalyticsEvent $e): bool => $e->name === 'journey_start')
            ->once();
        $this->manager->shouldReceive('trackEvent')
            ->withArgs(fn (AnalyticsEvent $e): bool => $e->name === 'sign_up')
            ->once();
        $this->manager->shouldReceive('trackEvent')
            ->withArgs(function (AnalyticsEvent $e): bool {
                return $e->name === 'journey_abandoned'
                    && ($e->params['last_step'] ?? '') === 'sign_up'
                    && ($e->params['next_expected_step'] ?? '') === 'email_verified'
                    && ($e->params['reason'] ?? '') === 'user_closed_tab';
            })
            ->once();

        $bundleId = $this->service->startBundle('signup_funnel', 'user_1');
        $this->service->addToBundle($bundleId, 'sign_up');
        $result = $this->service->abandonBundle($bundleId, 'user_closed_tab');

        expect($result)->toBeTrue();
    });

    test('activeBundles() returns only non-completed bundles', function (): void {
        $this->manager->shouldReceive('trackEvent')->times(3); // start + start + complete(2)

        $id1 = $this->service->startBundle('signup_funnel', 'user_1');
        $id2 = $this->service->startBundle('activation_funnel', 'user_2');
        $this->service->completeBundle($id2, 'first_value');

        $active = $this->service->activeBundles();

        expect(count($active))->toBe(1);
        expect(isset($active[$id1]))->toBeTrue();
    });

    test('journeyTemplates() returns predefined templates', function (): void {
        $templates = SaaSBundleEventService::journeyTemplates();

        expect(isset($templates['signup_funnel']))->toBeTrue();
        expect(isset($templates['activation_funnel']))->toBeTrue();
        expect(isset($templates['billing_funnel']))->toBeTrue();
        expect(isset($templates['churn_funnel']))->toBeTrue();
    });

    test('getExpectedSteps() returns empty array for unknown journey', function (): void {
        expect($this->service->getExpectedSteps('nonexistent'))->toBe([]);
    });

    test('summary() returns bundle statistics', function (): void {
        $this->manager->shouldReceive('trackEvent')->times(5);

        $id1 = $this->service->startBundle('signup_funnel', 'user_1');
        $this->service->addToBundle($id1, 'sign_up');

        $id2 = $this->service->startBundle('activation_funnel', 'user_2');
        $this->service->addToBundle($id2, 'sign_up');
        $this->service->completeBundle($id2, 'first_value');

        $summary = $this->service->summary();

        expect($summary['total'])->toBe(2);
        expect($summary['active'])->toBe(1);
        expect($summary['completed'])->toBe(1);
        expect($summary['avg_steps'])->toBe(1.5); // (1 + 2) / 2
    });

    test('clear() removes all bundles', function (): void {
        $this->manager->shouldReceive('trackEvent')->once();

        $this->service->startBundle('signup_funnel', 'user_1');
        $this->service->clear();

        expect($this->service->summary()['total'])->toBe(0);
    });

    test('completion percentage is computed correctly', function (): void {
        $this->manager->shouldReceive('trackEvent')
            ->times(5); // start + 3 adds + completed

        $bundleId = $this->service->startBundle('signup_funnel', 'user_1');
        // signup_funnel expects: sign_up, email_verified, start_trial, subscribe = 4 steps
        $this->service->addToBundle($bundleId, 'sign_up');
        $this->service->addToBundle($bundleId, 'email_verified');
        $result = $this->service->completeBundle($bundleId, 'subscribe');

        expect($result)->toBeTrue();
        $bundle = $this->service->getBundle($bundleId);
        expect($bundle['events'])->toHaveCount(3);
    });
});

// ─── Config Integration Tests ───────────────────────────────────────

describe('Config Integration (v120.0.0)', function (): void {
    test('heartbeat config has expected keys', function (): void {
        $config = include __DIR__ . '/../config/zeroboiler.php';

        expect(isset($config['analytics']['heartbeat']))->toBeTrue();
        expect(isset($config['analytics']['heartbeat']['enabled']))->toBeTrue();
        expect(isset($config['analytics']['heartbeat']['ttl']))->toBeTrue();
        expect(isset($config['analytics']['heartbeat']['stale_threshold']))->toBeTrue();
        expect(isset($config['analytics']['heartbeat']['failure_threshold']))->toBeTrue();
    });

    test('bundling config has expected keys', function (): void {
        $config = include __DIR__ . '/../config/zeroboiler.php';

        expect(isset($config['analytics']['bundling']))->toBeTrue();
        expect(isset($config['analytics']['bundling']['enabled']))->toBeTrue();
        expect(isset($config['analytics']['bundling']['auto_track_journeys']))->toBeTrue();
        expect(isset($config['analytics']['bundling']['bundle_id_prefix']))->toBeTrue();
    });

    test('feature_flags config has exposure/conversion tracking keys', function (): void {
        $config = include __DIR__ . '/../config/zeroboiler.php';

        expect(isset($config['analytics']['feature_flags']['track_exposures']))->toBeTrue();
        expect(isset($config['analytics']['feature_flags']['track_conversions']))->toBeTrue();
        expect(isset($config['analytics']['feature_flags']['ignored_flags']))->toBeTrue();
        expect(is_array($config['analytics']['feature_flags']['ignored_flags']))->toBeTrue();
    });
});

// ─── Version Consistency Test ───────────────────────────────────────

describe('Version Consistency', function (): void {
    test('version is 120.0.0 across all files', function (): void {
        $composerJson = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        $composerVersion = $composerJson['version'] ?? null;

        expect($composerVersion)->toBe('120.0.0');

        $spContent = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
        expect(str_contains($spContent, '@version 120.0.0'))->toBeTrue();

        $jsContent = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect(str_contains($jsContent, '@version 120.0.0'))->toBeTrue();
    });
});
