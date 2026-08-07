<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\PurchaseEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\RefundEvent;
use ZeroBoiler\Analytics\Events\Engagement\ErrorEvent;
use ZeroBoiler\Analytics\Events\Engagement\FormSubmitEvent;
use ZeroBoiler\Analytics\Events\Engagement\SearchEvent;
use ZeroBoiler\Analytics\Events\SaaS\CancellationEvent;
use ZeroBoiler\Analytics\Events\SaaS\FeatureUsedEvent;
use ZeroBoiler\Analytics\Events\SaaS\LoginEvent;
use ZeroBoiler\Analytics\Events\SaaS\LogoutEvent;
use ZeroBoiler\Analytics\Events\SaaS\PlanDowngradeEvent;
use ZeroBoiler\Analytics\Events\SaaS\PlanUpgradeEvent;
use ZeroBoiler\Analytics\Events\SaaS\SignUpEvent;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionEvent;
use ZeroBoiler\Analytics\Events\SaaS\TrialEndEvent;
use ZeroBoiler\Analytics\Events\SaaS\TrialStartEvent;
use ZeroBoiler\Analytics\Services\EventCorrelationService;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;

beforeEach(function (): void {
    $this->config = mock(ConfigRepository::class);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.ga4', [])
        ->andReturn(['enabled' => false]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.gtm', [])
        ->andReturn(['enabled' => false]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.meta_pixel', [])
        ->andReturn(['enabled' => false]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.plausible', [])
        ->andReturn([]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.posthog', [])
        ->andReturn([]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.webhook', [])
        ->andReturn([]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.consent.default', 'granted')
        ->andReturn('granted');
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.debug', [])
        ->andReturn(['enabled' => false]);
    $this->manager = new AnalyticsManager($this->config);
});

// ═══════════════════════════════════════════════════════════════════════
// LifecycleEventMapper Tests
// ═══════════════════════════════════════════════════════════════════════

describe('LifecycleEventMapper — construction and config', function (): void {
    it('initializes with default mappings', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn(['enabled' => true]);

        $mapper = new LifecycleEventMapper($this->manager, $this->config);

        expect($mapper->isEnabled())->toBeTrue();
        expect($mapper->count())->toBeGreaterThan(0);
    });

    it('initializes as disabled when config says so', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn(['enabled' => false]);

        $mapper = new LifecycleEventMapper($this->manager, $this->config);

        expect($mapper->isEnabled())->toBeFalse();
    });

    it('respects event toggle configuration', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn([
                'enabled' => true,
                'events' => [
                    'auth.login' => true,
                    'auth.register' => false,
                ],
            ]);

        $mapper = new LifecycleEventMapper($this->manager, $this->config);

        expect($mapper->isEnabled())->toBeTrue();
        $enabledKeys = $mapper->enabledEventKeys();
        expect($enabledKeys)->toContain('auth.login');
        expect($enabledKeys)->not->toContain('auth.register');
    });

    it('merges custom mappings with defaults', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn([
                'enabled' => true,
                'custom_mappings' => [
                    'custom.event' => [
                        'source' => 'custom.event',
                        'target' => 'SomeClass',
                        'priority' => 200,
                    ],
                ],
            ]);

        $mapper = new LifecycleEventMapper($this->manager, $this->config);
        $mappings = $mapper->getMappings();

        expect($mappings)->toHaveKey('auth.login');
        expect($mappings)->toHaveKey('custom.event');
        expect($mappings['custom.event']['priority'])->toBe(200);
    });

    it('overrides defaults when override_defaults is true', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn([
                'enabled' => true,
                'override_defaults' => true,
                'custom_mappings' => [
                    'only.event' => [
                        'source' => 'only.event',
                        'target' => 'SomeClass',
                        'priority' => 100,
                    ],
                ],
            ]);

        $mapper = new LifecycleEventMapper($this->manager, $this->config);

        expect($mapper->count())->toBe(1);
        expect($mapper->getMappings())->toHaveKey('only.event');
        expect($mapper->getMappings())->not->toHaveKey('auth.login');
    });

    it('provides a summary with categories', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn(['enabled' => true]);

        $mapper = new LifecycleEventMapper($this->manager, $this->config);
        $summary = $mapper->summary();

        expect($summary)->toHaveKey('enabled');
        expect($summary)->toHaveKey('total_mappings');
        expect($summary)->toHaveKey('enabled_count');
        expect($summary)->toHaveKey('categories');
        expect($summary)->toHaveKey('event_keys');
        expect($summary['enabled'])->toBeTrue();
        expect($summary['total_mappings'])->toBeGreaterThan(0);
        expect($summary['categories'])->toBeArray();
    });
});

describe('LifecycleEventMapper — registration', function (): void {
    it('registers listeners on dispatcher when enabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn(['enabled' => true]);

        $dispatcher = mock(EventDispatcher::class);
        $dispatcher->shouldReceive('listen')
            ->atLeast(1)
            ->andReturnNull();

        $mapper = new LifecycleEventMapper($this->manager, $this->config);
        $mapper->register($dispatcher);

        // Should have registered at least auth.login
        $dispatcher->shouldHaveReceived('listen')->atLeast(1);
    });

    it('does not register when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn(['enabled' => false]);

        $dispatcher = mock(EventDispatcher::class);
        $dispatcher->shouldNotReceive('listen');

        $mapper = new LifecycleEventMapper($this->manager, $this->config);
        $mapper->register($dispatcher);
    });
});

// ═══════════════════════════════════════════════════════════════════════
// EventCorrelationService Tests
// ═══════════════════════════════════════════════════════════════════════

describe('EventCorrelationService — recording events', function (): void {
    beforeEach(function (): void {
        $this->metrics = mock(AnalyticsMetrics::class);
        $this->cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $this->cache->shouldReceive('get')->andReturnNull();
        $this->cache->shouldReceive('put')->andReturnNull();
        $this->cache->shouldReceive('forget')->andReturnNull();
        $this->correlation = new EventCorrelationService(
            $this->metrics,
            $this->cache,
            300,
            5,
            100,
            false,
        );
    });

    it('records events and builds event counts', function (): void {
        $event = new AnalyticsEvent(name: 'page_view', params: []);
        $this->correlation->record($event, 'session-1');

        $summary = $this->correlation->summary();
        expect($summary['total_events'])->toBe(1);
        expect($summary['unique_events'])->toBe(1);
    });

    it('records multiple events for same user as a journey', function (): void {
        $this->correlation->record(new AnalyticsEvent(name: 'sign_up', params: []), 'session-1', 'user-1');
        $this->correlation->record(new AnalyticsEvent(name: 'start_trial', params: []), 'session-1', 'user-1');
        $this->correlation->record(new AnalyticsEvent(name: 'subscription.created', params: []), 'session-1', 'user-1');

        $journeys = $this->correlation->topJourneys(10);
        expect($journeys)->toHaveCount(1);
        expect($journeys[0]['steps'])->toBe(['sign_up', 'start_trial', 'subscription.created']);
        expect($journeys[0]['step_count'])->toBe(3);
    });

    it('tracks transitions between events', function (): void {
        $this->correlation->record(new AnalyticsEvent(name: 'page_view', params: []), 's1', 'u1');
        $this->correlation->record(new AnalyticsEvent(name: 'add_to_cart', params: []), 's1', 'u1');
        $this->correlation->record(new AnalyticsEvent(name: 'purchase', params: []), 's1', 'u1');

        $transitions = $this->correlation->topTransitions(10);
        expect($transitions)->toHaveCount(2);

        $fromPageView = array_filter($transitions, fn (array $t): bool => $t['from'] === 'page_view');
        expect($fromPageView)->not->toBeEmpty();
    });

    it('separates journeys by user ID', function (): void {
        $this->correlation->record(new AnalyticsEvent(name: 'sign_up', params: []), 's1', 'user-1');
        $this->correlation->record(new AnalyticsEvent(name: 'login', params: []), 's2', 'user-2');

        $summary = $this->correlation->summary();
        expect($summary['unique_users'])->toBe(2);
    });

    it('limits journey length per user', function (): void {
        $service = new EventCorrelationService(
            $this->metrics,
            $this->cache,
            300,
            5,
            3, // max 3 steps per user
            false,
        );

        for ($i = 0; $i < 10; $i++) {
            $service->record(new AnalyticsEvent(name: "event_{$i}", params: []), 's1', 'u1');
        }

        $journeys = $service->topJourneys(10);
        expect($journeys[0]['step_count'])->toBeLessThanOrEqual(3);
    });
});

describe('EventCorrelationService — pattern detection', function (): void {
    beforeEach(function (): void {
        $this->metrics = mock(AnalyticsMetrics::class);
        $this->cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $this->cache->shouldReceive('get')->andReturnNull();
        $this->cache->shouldReceive('put')->andReturnNull();
        $this->cache->shouldReceive('forget')->andReturnNull();
        $this->correlation = new EventCorrelationService(
            $this->metrics,
            $this->cache,
            300,
            5,
            100,
            false,
        );
    });

    it('detects frequent 2-event patterns', function (): void {
        // Simulate 3 users with the same journey
        for ($u = 1; $u <= 3; $u++) {
            $this->correlation->record(new AnalyticsEvent(name: 'sign_up', params: []), "s{$u}", "u{$u}");
            $this->correlation->record(new AnalyticsEvent(name: 'start_trial', params: []), "s{$u}", "u{$u}");
            $this->correlation->record(new AnalyticsEvent(name: 'purchase', params: []), "s{$u}", "u{$u}");
        }

        // Add a different journey
        $this->correlation->record(new AnalyticsEvent(name: 'login', params: []), 's4', 'u4');

        $patterns = $this->correlation->frequentPatterns(2, 20);
        expect($patterns)->not->toBeEmpty();

        // The sign_up→start_trial pattern should appear
        $signupTrial = array_filter($patterns, fn (array $p): bool =>
            count($p['pattern']) === 2
            && $p['pattern'][0] === 'sign_up'
            && $p['pattern'][1] === 'start_trial'
        );
        expect($signupTrial)->not->toBeEmpty();
    });

    it('detects longer patterns when max_pattern_length allows', function (): void {
        for ($u = 1; $u <= 3; $u++) {
            $this->correlation->record(new AnalyticsEvent(name: 'sign_up', params: []), "s{$u}", "u{$u}");
            $this->correlation->record(new AnalyticsEvent(name: 'start_trial', params: []), "s{$u}", "u{$u}");
            $this->correlation->record(new AnalyticsEvent(name: 'purchase', params: []), "s{$u}", "u{$u}");
        }

        $patterns = $this->correlation->frequentPatterns(3, 20);
        $longPatterns = array_filter($patterns, fn (array $p): bool => count($p['pattern']) === 3);
        expect($longPatterns)->not->toBeEmpty();
    });

    it('respects the limit parameter', function (): void {
        for ($u = 1; $u <= 3; $u++) {
            $this->correlation->record(new AnalyticsEvent(name: "event_a_{$u}", params: []), "s{$u}", "u{$u}");
            $this->correlation->record(new AnalyticsEvent(name: "event_b_{$u}", params: []), "s{$u}", "u{$u}");
        }

        $patterns = $this->correlation->frequentPatterns(2, 1);
        expect($patterns)->toHaveCount(1);
    });
});

describe('EventCorrelationService — conversion rates', function (): void {
    beforeEach(function (): void {
        $this->metrics = mock(AnalyticsMetrics::class);
        $this->cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $this->cache->shouldReceive('get')->andReturnNull();
        $this->cache->shouldReceive('put')->andReturnNull();
        $this->cache->shouldReceive('forget')->andReturnNull();
        $this->correlation = new EventCorrelationService(
            $this->metrics,
            $this->cache,
            300,
            5,
            100,
            false,
        );
    });

    it('calculates conversion rate for a sequence', function (): void {
        // 2 users complete full funnel
        for ($u = 1; $u <= 2; $u++) {
            $this->correlation->record(new AnalyticsEvent(name: 'sign_up', params: []), "s{$u}", "u{$u}");
            $this->correlation->record(new AnalyticsEvent(name: 'start_trial', params: []), "s{$u}", "u{$u}");
            $this->correlation->record(new AnalyticsEvent(name: 'subscription.created', params: []), "s{$u}", "u{$u}");
        }

        // 1 user drops off at trial
        $this->correlation->record(new AnalyticsEvent(name: 'sign_up', params: []), 's4', 'u4');
        $this->correlation->record(new AnalyticsEvent(name: 'start_trial', params: []), 's4', 'u4');

        $rate = $this->correlation->conversionRate(['sign_up', 'start_trial', 'subscription.created']);

        expect($rate['total_starting'])->toBe(3);
        expect($rate['total_completed'])->toBe(2);
        expect($rate['conversion_rate'])->toBe(66.67);
        expect($rate['drop_off'])->toHaveCount(3);
    });

    it('returns zeros for empty sequence', function (): void {
        $rate = $this->correlation->conversionRate(['nonexistent']);
        expect($rate['total_starting'])->toBe(0);
        expect($rate['conversion_rate'])->toBe(0.0);
    });

    it('handles single-element sequence gracefully', function (): void {
        $rate = $this->correlation->conversionRate(['sign_up']);
        expect($rate['conversion_rate'])->toBe(0.0);
    });
});

describe('EventCorrelationService — prediction', function (): void {
    beforeEach(function (): void {
        $this->metrics = mock(AnalyticsMetrics::class);
        $this->cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $this->cache->shouldReceive('get')->andReturnNull();
        $this->cache->shouldReceive('put')->andReturnNull();
        $this->cache->shouldReceive('forget')->andReturnNull();
        $this->correlation = new EventCorrelationService(
            $this->metrics,
            $this->cache,
            300,
            5,
            100,
            false,
        );
    });

    it('predicts next events from transition data', function (): void {
        for ($u = 1; $u <= 5; $u++) {
            $this->correlation->record(new AnalyticsEvent(name: 'sign_up', params: []), "s{$u}", "u{$u}");
            $this->correlation->record(new AnalyticsEvent(name: 'start_trial', params: []), "s{$u}", "u{$u}");
        }
        // 1 user goes to purchase instead
        $this->correlation->record(new AnalyticsEvent(name: 'sign_up', params: []), 's6', 'u6');
        $this->correlation->record(new AnalyticsEvent(name: 'purchase', params: []), 's6', 'u6');

        $predictions = $this->correlation->predictNext('sign_up', 5);

        expect($predictions)->not->toBeEmpty();
        // start_trial should be predicted as highest probability
        expect($predictions[0]['event'])->toBe('start_trial');
        expect($predictions[0]['probability'])->toBeGreaterThan(0);
    });

    it('returns empty for unknown events', function (): void {
        $predictions = $this->correlation->predictNext('never_happened', 5);
        expect($predictions)->toBeEmpty();
    });
});

describe('EventCorrelationService — correlation matrix', function (): void {
    beforeEach(function (): void {
        $this->metrics = mock(AnalyticsMetrics::class);
        $this->cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $this->cache->shouldReceive('get')->andReturnNull();
        $this->cache->shouldReceive('put')->andReturnNull();
        $this->cache->shouldReceive('forget')->andReturnNull();
        $this->correlation = new EventCorrelationService(
            $this->metrics,
            $this->cache,
            300,
            5,
            100,
            false,
        );
    });

    it('builds a co-occurrence matrix', function (): void {
        $this->correlation->record(new AnalyticsEvent(name: 'sign_up', params: []), 's1', 'u1');
        $this->correlation->record(new AnalyticsEvent(name: 'start_trial', params: []), 's1', 'u1');

        $this->correlation->record(new AnalyticsEvent(name: 'sign_up', params: []), 's2', 'u2');
        $this->correlation->record(new AnalyticsEvent(name: 'start_trial', params: []), 's2', 'u2');

        $matrix = $this->correlation->correlationMatrix(['sign_up', 'start_trial']);

        expect($matrix['events'])->toBe(['sign_up', 'start_trial']);
        expect($matrix['matrix']['sign_up']['start_trial'])->toBe(2);
    });

    it('builds matrix from all events when empty list given', function (): void {
        $this->correlation->record(new AnalyticsEvent(name: 'sign_up', params: []), 's1', 'u1');
        $this->correlation->record(new AnalyticsEvent(name: 'login', params: []), 's2', 'u2');

        $matrix = $this->correlation->correlationMatrix();
        expect($matrix['events'])->toContain('sign_up');
        expect($matrix['events'])->toContain('login');
    });
});

describe('EventCorrelationService — clear and cache', function (): void {
    it('clears all data', function (): void {
        $metrics = mock(AnalyticsMetrics::class);
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('forget')->andReturnNull();
        $service = new EventCorrelationService($metrics, $cache, 300, 5, 100, false);

        $service->record(new AnalyticsEvent(name: 'test', params: []), 's1', 'u1');
        expect($service->summary()['total_events'])->toBe(1);

        $service->clear();
        expect($service->summary()['total_events'])->toBe(0);
    });

    it('invalidates cache', function (): void {
        $metrics = mock(AnalyticsMetrics::class);
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('forget')->with('zeroboiler.analytics.correlation.patterns')->once();
        $service = new EventCorrelationService($metrics, $cache, 300, 5, 100, true);

        $service->invalidateCache();
    });
});

// ═══════════════════════════════════════════════════════════════════════
// Integration: Version + Config Consistency
// ═══════════════════════════════════════════════════════════════════════

describe('v2.28.0 — Version and config consistency', function (): void {
    it('AnalyticsManager returns version 2.28.0', function (): void {
        expect($manager->version())->toBe('2.37.0');
    });

    it('lifecycle config section has correct structure', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn([
                'enabled' => true,
                'override_defaults' => false,
                'events' => [
                    'auth.login' => true,
                    'subscription.created' => true,
                ],
                'custom_mappings' => [],
            ]);

        $mapper = new LifecycleEventMapper($this->manager, $this->config);
        expect($mapper->isEnabled())->toBeTrue();
        expect($mapper->lifecycleEnabled())->toBeTrue();
    });

    it('correlation service initializes with config-driven parameters', function (): void {
        $metrics = mock(AnalyticsMetrics::class);
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturnNull();
        $cache->shouldReceive('put')->andReturnNull();
        $cache->shouldReceive('forget')->andReturnNull();

        $service = new EventCorrelationService($metrics, $cache, 600, 8, 200, true);

        $summary = $service->summary();
        expect($summary['total_events'])->toBe(0);
        expect($summary['unique_users'])->toBe(0);
    });
});
