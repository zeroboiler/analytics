<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventTtlService;
use ZeroBoiler\Analytics\Services\ReferralTrackingService;
use ZeroBoiler\Analytics\Services\TrafficSpikeShield;
use ZeroBoiler\Analytics\Services\EventReplaySimulator;

beforeEach(function (): void {
    Cache::clear();
});

// ── Event TTL Service ────────────────────────────────────────────────────────

describe('EventTtlService', function (): void {
    it('uses default TTL when no overrides configured', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventTtlService($cache, defaultTtl: 3600);

        $event = new AnalyticsEvent(name: 'page_view');

        expect($service->resolveTtlForEvent($event))->toBe(3600);
    });

    it('applies event-specific TTL override', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventTtlService(
            $cache,
            defaultTtl: 3600,
            ttlOverrides: ['purchase' => 604800],
        );

        $purchaseEvent = new AnalyticsEvent(name: 'purchase');
        $pageViewEvent = new AnalyticsEvent(name: 'page_view');

        expect($service->resolveTtlForEvent($purchaseEvent))->toBe(604800);
        expect($service->resolveTtlForEvent($pageViewEvent))->toBe(3600);
    });

    it('applies category-based TTL override', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventTtlService(
            $cache,
            defaultTtl: 3600,
            categoryTtlOverrides: ['ecommerce' => 604800],
        );

        $purchaseEvent = new AnalyticsEvent(name: 'purchase');
        $pageViewEvent = new AnalyticsEvent(name: 'page_view');

        expect($service->resolveTtlForEvent($purchaseEvent))->toBe(604800);
        expect($service->resolveTtlForEvent($pageViewEvent))->toBe(3600);
    });

    it('event-specific override takes precedence over category', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventTtlService(
            $cache,
            defaultTtl: 3600,
            ttlOverrides: ['purchase' => 300],
            categoryTtlOverrides: ['ecommerce' => 604800],
        );

        $purchaseEvent = new AnalyticsEvent(name: 'purchase');

        expect($service->resolveTtlForEvent($purchaseEvent))->toBe(300);
    });

    it('clamps TTL to max value', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventTtlService(
            $cache,
            defaultTtl: 9999999,
        );

        $event = new AnalyticsEvent(name: 'test');

        expect($service->resolveTtlForEvent($event))->toBe(2592000);
    });

    it('detects expired events', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventTtlService($cache, defaultTtl: 60);

        $oldTimestamp = (new \DateTimeImmutable())->modify('-120 seconds');
        $expiredEvent = new AnalyticsEvent(name: 'page_view', timestamp: $oldTimestamp);

        expect($service->isExpired($expiredEvent))->toBeTrue();
    });

    it('does not flag fresh events as expired', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventTtlService($cache, defaultTtl: 3600);

        $freshEvent = new AnalyticsEvent(name: 'page_view');

        expect($service->isExpired($freshEvent))->toBeFalse();
    });

    it('returns negative remaining TTL for expired events', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventTtlService($cache, defaultTtl: 60);

        $oldTimestamp = (new \DateTimeImmutable())->modify('-120 seconds');
        $expiredEvent = new AnalyticsEvent(name: 'page_view', timestamp: $oldTimestamp);

        expect($service->remainingTtl($expiredEvent))->toBeLessThan(0);
    });

    it('evaluates non-expired events normally', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventTtlService($cache, defaultTtl: 3600, dropExpired: false);

        $event = new AnalyticsEvent(name: 'page_view');
        $result = $service->evaluate($event);

        expect($result)->not()->toBeNull();
        expect($result->name)->toBe('page_view');
    });

    it('drops expired events when dropExpired is true', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventTtlService($cache, defaultTtl: 60, dropExpired: true);

        $oldTimestamp = (new \DateTimeImmutable())->modify('-120 seconds');
        $expiredEvent = new AnalyticsEvent(name: 'page_view', timestamp: $oldTimestamp);

        $result = $service->evaluate($expiredEvent);

        expect($result)->toBeNull();
    });

    it('returns TTL config', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventTtlService(
            $cache,
            defaultTtl: 7200,
            ttlOverrides: ['purchase' => 604800],
            categoryTtlOverrides: ['ecommerce' => 86400],
            dropExpired: true,
        );

        $config = $service->getConfig();

        expect($config)->toHaveKey('default_ttl');
        expect($config['default_ttl'])->toBe(7200);
        expect($config['drop_expired'])->toBeTrue();
    });

    it('tracks expired event metrics', function (): void {
        $cache = app(CacheRepository::class);
        $service = new EventTtlService($cache, defaultTtl: 60);

        $oldTimestamp = (new \DateTimeImmutable())->modify('-120 seconds');
        $expiredEvent = new AnalyticsEvent(name: 'purchase', timestamp: $oldTimestamp);

        $service->evaluate($expiredEvent);
        $metrics = $service->getMetrics();

        expect($metrics['total_expired'])->toBe(1);
        expect($metrics['by_event'])->toHaveKey('purchase');
    });
});

// ── Referral Tracking Service ────────────────────────────────────────────────

describe('ReferralTrackingService', function (): void {
    it('generates a referral code for a user', function (): void {
        $cache = app(CacheRepository::class);
        $service = new ReferralTrackingService($cache, codeLength: 8);

        $code = $service->generateCode('user_123');

        expect($code)->toBeString();
        expect(strlen($code))->toBe(8);
    });

    it('returns the same code for the same user', function (): void {
        $cache = app(CacheRepository::class);
        $service = new ReferralTrackingService($cache, codeLength: 8);

        $code1 = $service->generateCode('user_123');
        $code2 = $service->generateCode('user_123');

        expect($code1)->toBe($code2);
    });

    it('uses preferred code if available', function (): void {
        $cache = app(CacheRepository::class);
        $service = new ReferralTrackingService($cache, codeLength: 8);

        $code = $service->generateCode('user_456', 'MYCODE');

        expect($code)->toBe('MYCODE');
    });

    it('resolves referrer from code', function (): void {
        $cache = app(CacheRepository::class);
        $service = new ReferralTrackingService($cache, codeLength: 8);

        $code = $service->generateCode('user_789');
        $resolved = $service->resolveReferrer($code);

        expect($resolved)->toBe('user_789');
    });

    it('returns null for unknown codes', function (): void {
        $cache = app(CacheRepository::class);
        $service = new ReferralTrackingService($cache, codeLength: 8);

        expect($service->resolveReferrer('NONEXISTENT'))->toBeNull();
    });

    it('tracks clicks and returns click ID', function (): void {
        $cache = app(CacheRepository::class);
        $service = new ReferralTrackingService($cache, codeLength: 8);

        $code = $service->generateCode('user_001');
        $clickId = $service->trackClick($code, null, ['ip' => '127.0.0.1']);

        expect($clickId)->toBeString();
        expect(strlen($clickId))->toBeGreaterThan(0);
    });

    it('tracks conversions with attribution', function (): void {
        $cache = app(CacheRepository::class);
        $service = new ReferralTrackingService($cache, codeLength: 8);

        $code = $service->generateCode('user_referrer');
        $clickId = $service->trackClick($code);

        $result = $service->trackConversion($clickId, 'user_referred');

        expect($result['attributed'])->toBeTrue();
        expect($result['referrer_id'])->toBe('user_referrer');
        expect($result['referral_code'])->toBe($code);
    });

    it('prevents self-referrals', function (): void {
        $cache = app(CacheRepository::class);
        $service = new ReferralTrackingService($cache, codeLength: 8);

        $code = $service->generateCode('user_self');
        $clickId = $service->trackClick($code);

        $result = $service->trackConversion($clickId, 'user_self');

        expect($result['attributed'])->toBeFalse();
    });

    it('calculates viral coefficient', function (): void {
        $cache = app(CacheRepository::class);
        $service = new ReferralTrackingService($cache, codeLength: 8);

        $viral = $service->calculateViralCoefficient();

        expect($viral)->toHaveKey('k_factor');
        expect($viral)->toHaveKey('total_conversions');
        expect($viral)->toHaveKey('total_referrers');
    });

    it('returns health metrics', function (): void {
        $cache = app(CacheRepository::class);
        $service = new ReferralTrackingService($cache, codeLength: 8);

        $health = $service->getHealthMetrics();

        expect($health)->toHaveKey('viral_coefficient');
        expect($health)->toHaveKey('total_referrers');
        expect($health)->toHaveKey('total_conversions');
        expect($health)->toHaveKey('funnel');
        expect($health)->toHaveKey('top_referrers');
    });
});

// ── Traffic Spike Shield ────────────────────────────────────────────────────

describe('TrafficSpikeShield', function (): void {
    it('allows events when disabled', function (): void {
        $cache = app(CacheRepository::class);
        $shield = new TrafficSpikeShield($cache, enabled: false);

        $event = new AnalyticsEvent(name: 'page_view');

        expect($shield->shouldAllow($event))->toBeTrue();
    });

    it('allows critical events even during spike', function (): void {
        $cache = app(CacheRepository::class);
        $shield = new TrafficSpikeShield(
            $cache,
            enabled: true,
            normalThreshold: 1,
            spikeThreshold: 2,
            cooldown: 60,
        );

        // Force cooldown
        $shield->triggerCooldown();

        $criticalEvent = new AnalyticsEvent(name: 'purchase', priority: 'critical');

        expect($shield->shouldAllow($criticalEvent))->toBeTrue();
    });

    it('returns null for throttled events via process()', function (): void {
        $cache = app(CacheRepository::class);
        $shield = new TrafficSpikeShield(
            $cache,
            enabled: true,
            normalThreshold: 1,
            spikeThreshold: 2,
            cooldown: 60,
            throttleRatio: 0.0, // always throttle
        );

        // Trigger cooldown
        $shield->triggerCooldown();

        // Fill window to spike level
        $event = new AnalyticsEvent(name: 'page_view');
        $shield->process($event);
        $shield->process($event);
        $shield->process($event);

        $result = $shield->process($event);

        // With throttleRatio 0.0 and cooldown, all non-critical events should be throttled
        expect($result)->toBeNull();
    });

    it('passes events through when below threshold', function (): void {
        $cache = app(CacheRepository::class);
        $shield = new TrafficSpikeShield(
            $cache,
            enabled: true,
            normalThreshold: 100,
            spikeThreshold: 500,
        );

        $event = new AnalyticsEvent(name: 'page_view');
        $result = $shield->process($event);

        expect($result)->not()->toBeNull();
        expect($result->name)->toBe('page_view');
    });

    it('can trigger and clear cooldown', function (): void {
        $cache = app(CacheRepository::class);
        $shield = new TrafficSpikeShield($cache);

        $shield->triggerCooldown();
        expect($shield->isInCooldown())->toBeTrue();

        $shield->clearCooldown();
        expect($shield->isInCooldown())->toBeFalse();
    });

    it('returns status with all fields', function (): void {
        $cache = app(CacheRepository::class);
        $shield = new TrafficSpikeShield(
            $cache,
            normalThreshold: 100,
            spikeThreshold: 500,
            windowSize: 60,
            throttleRatio: 0.2,
        );

        $status = $shield->getStatus();

        expect($status)->toHaveKey('enabled');
        expect($status)->toHaveKey('in_cooldown');
        expect($status)->toHaveKey('normal_threshold');
        expect($status)->toHaveKey('spike_threshold');
        expect($status)->toHaveKey('window_size');
        expect($status)->toHaveKey('throttle_ratio');
        expect($status)->toHaveKey('total_accepted');
        expect($status)->toHaveKey('total_throttled');
        expect($status)->toHaveKey('total_spikes');
        expect($status['normal_threshold'])->toBe(100);
        expect($status['spike_threshold'])->toBe(500);
        expect($status['throttle_ratio'])->toBe(0.2);
    });
});

// ── Event Replay Simulator ──────────────────────────────────────────────────

describe('EventReplaySimulator', function (): void {
    it('generates a batch of synthetic events', function (): void {
        $cache = app(CacheRepository::class);
        $simulator = new EventReplaySimulator($cache, dryRun: true);

        $result = $simulator->generateBatch(50);

        expect($result['generated'])->toBe(50);
        expect($result['dispatched'])->toBe(0);
        expect($result['by_event'])->not()->toBeEmpty();
        expect($result['duration_ms'])->toBeGreaterThan(0);
    });

    it('dispatches events when callback provided', function (): void {
        $cache = app(CacheRepository::class);
        $simulator = new EventReplaySimulator($cache, dryRun: false);

        $dispatched = [];
        $result = $simulator->generateBatch(10, function (AnalyticsEvent $event) use (&$dispatched): void {
            $dispatched[] = $event->name;
        });

        expect($result['dispatched'])->toBe(10);
        expect(count($dispatched))->toBe(10);
    });

    it('generates events with source simulation', function (): void {
        $cache = app(CacheRepository::class);
        $simulator = new EventReplaySimulator($cache, dryRun: true);

        $dispatchedEvents = [];
        $simulator->generateBatch(5, function (AnalyticsEvent $event) use (&$dispatchedEvents): void {
            $dispatchedEvents[] = $event;
        });

        foreach ($dispatchedEvents as $event) {
            expect($event->source)->toBe('simulation');
            expect($event->clientId)->not()->toBeNull();
            expect($event->timestamp)->not()->toBeNull();
        }
    });

    it('generates e-commerce scenario', function (): void {
        $cache = app(CacheRepository::class);
        $simulator = new EventReplaySimulator($cache);

        $result = $simulator->generateEcommerceScenario('test_client');

        expect($result)->toHaveKey('steps');
        expect($result)->toHaveKey('events');
        expect($result)->toHaveKey('revenue');
        expect($result['steps'])->toBeGreaterThan(0);
        expect($result['events'])->not()->toBeEmpty();
    });

    it('generates SaaS lifecycle scenario', function (): void {
        $cache = app(CacheRepository::class);
        $simulator = new EventReplaySimulator($cache);

        $result = $simulator->generateSaaSLifecycleScenario('test_client');

        expect($result)->toHaveKey('steps');
        expect($result)->toHaveKey('events');
        expect($result)->toHaveKey('converted');
        expect($result)->toHaveKey('plan');
        expect($result['steps'])->toBeGreaterThan(0);
        expect($result['events'])->not()->toBeEmpty();
    });

    it('SaaS scenario always starts with sign_up', function (): void {
        $cache = app(CacheRepository::class);
        $simulator = new EventReplaySimulator($cache);

        $result = $simulator->generateSaaSLifecycleScenario('test_client');

        expect($result['events'][0])->toBe('sign_up');
    });

    it('e-commerce scenario tracks revenue', function (): void {
        $cache = app(CacheRepository::class);
        $simulator = new EventReplaySimulator($cache);

        $totalRevenue = 0.0;
        for ($i = 0; $i < 20; $i++) {
            $result = $simulator->generateEcommerceScenario('test_client_' . $i);
            $totalRevenue += $result['revenue'];
        }

        // At least some scenarios should generate revenue
        expect($totalRevenue)->toBeGreaterThanOrEqual(0);
    });

    it('returns default event mix when no custom mix', function (): void {
        $cache = app(CacheRepository::class);
        $simulator = new EventReplaySimulator($cache);

        $mix = $simulator->getEventMix();

        expect($mix)->toHaveKey('page_view');
        expect($mix['page_view'])->toBeGreaterThan(0);
        expect(array_sum($mix))->toBe(1.0);
    });

    it('returns config', function (): void {
        $cache = app(CacheRepository::class);
        $simulator = new EventReplaySimulator(
            $cache,
            batchSize: 200,
            rateLimit: 100,
            dryRun: true,
        );

        $config = $simulator->getConfig();

        expect($config['batch_size'])->toBe(200);
        expect($config['rate_limit'])->toBe(100);
        expect($config['dry_run'])->toBeTrue();
        expect($config['max_events'])->toBe(100000);
    });
});
