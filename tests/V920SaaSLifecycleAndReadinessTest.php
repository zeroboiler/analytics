<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Services\SaaSLifecycleObserver;
use ZeroBoiler\Analytics\Services\AnalyticsReadinessScoreService;

describe('SaaSLifecycleObserver', function (): void {
    beforeEach(function (): void {
        $this->cache = Mockery::mock(CacheRepository::class);
        $this->config = Mockery::mock(ConfigRepository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle_observer', [])
            ->andReturn([]);
        $this->observer = new SaaSLifecycleObserver($this->cache, $this->config);
    });

    afterEach(function (): void {
        Mockery::close();
    });

    describe('record() — activation tracking', function (): void {
        it('initializes default signals for new identity', function (): void {
            $this->cache->shouldReceive('get')
                ->with('zb_lifecycle_user_123')
                ->andReturn(null);
            $this->cache->shouldReceive('put')
                ->with('zb_lifecycle_user_123', Mockery::type('array'), 3600)
                ->once();

            $result = $this->observer->record('trial_start', 'user_123');

            expect($result)->toBeArray();
            expect($result['activation_score'])->toBe(0);
            expect($result['activation_steps'])->toContain('trial_started');
            expect($result['session_count'])->toBe(0);
        });

        it('accumulates activation score from multiple events', function (): void {
            $existing = [
                'activation_score' => 20,
                'activation_steps' => ['trial_started'],
                'activation_step_scores' => ['trial_started' => 0],
                'session_count' => 1,
                'first_login_at' => time() - 86400,
                'last_login_at' => time() - 86400,
            ];

            $this->cache->shouldReceive('get')
                ->with('zb_lifecycle_user_123')
                ->andReturn($existing);
            $this->cache->shouldReceive('put')
                ->once();

            $result = $this->observer->record('feature_used', 'user_123', [
                'feature_name' => 'dashboard',
            ]);

            expect($result['activation_steps'])->toContain('feature_engagement');
            expect($result['features_used'])->toContain('dashboard');
            expect($result['feature_adoption_count'])->toBe(1);
        });

        it('caps activation score at 100', function (): void {
            $signals = [];
            for ($i = 0; $i < 8; $i++) {
                $signals['activation_score'] = 100;
                $signals['activation_steps'] = ['trial_started', 'first_login', 'feature_engagement', 'intent_signal', 'purchase_intent', 'conversion', 'expansion', 'trial_completed'];
                $signals['activation_step_scores'] = ['trial_started' => 0, 'first_login' => 15, 'feature_engagement' => 20, 'intent_signal' => 25, 'purchase_intent' => 30, 'conversion' => 80, 'expansion' => 90, 'trial_completed' => 100];
            }

            $this->cache->shouldReceive('get')
                ->andReturn($signals);
            $this->cache->shouldReceive('put')
                ->once();

            $result = $this->observer->record('login', 'user_123');

            expect($result['activation_score'])->toBeLessThanOrEqual(100);
        });
    });

    describe('record() — churn risk', function (): void {
        it('tracks churn risk indicators', function (): void {
            $this->cache->shouldReceive('get')
                ->andReturn(null);
            $this->cache->shouldReceive('put')
                ->once();

            $result = $this->observer->record('feature_limit_reached', 'user_456');

            expect($result['churn_risk_score'])->toBe(20);
            expect($result['churn_indicators'])->toContain('feature_limit_reached');
            expect($result['churn_risk_score'])->toBeLessThanOrEqual(100);
        });

        it('accumulates churn risk from multiple indicators', function (): void {
            $existing = [
                'churn_risk_score' => 20,
                'churn_indicators' => ['feature_limit_reached'],
                'churn_indicator_counts' => ['feature_limit_reached' => 1],
            ];

            $this->cache->shouldReceive('get')
                ->andReturn($existing);
            $this->cache->shouldReceive('put')
                ->once();

            $result = $this->observer->record('billing_retry', 'user_456');

            expect($result['churn_indicators'])->toContain('feature_limit_reached');
            expect($result['churn_indicators'])->toContain('billing_retry');
            expect($result['churn_risk_score'])->toBeGreaterThan(20);
        });
    });

    describe('record() — expansion momentum', function (): void {
        it('tracks expansion revenue events', function (): void {
            $this->cache->shouldReceive('get')
                ->andReturn(null);
            $this->cache->shouldReceive('put')
                ->once();

            $result = $this->observer->record('plan_upgrade', 'user_789', ['value' => 30.00]);

            expect($result['expansion_momentum'])->toBe(15.0);
            expect($result['total_expansion_value'])->toBe(30.0);
            expect($result['expansion_event_count'])->toBe(1);
        });
    });

    describe('record() — funnel progress', function (): void {
        it('tracks signup funnel completion', function (): void {
            $this->cache->shouldReceive('get')
                ->andReturn(null);
            $this->cache->shouldReceive('put')
                ->once();

            $result = $this->observer->record('subscription', 'user_abc');

            expect($result['funnel_progress'])->toContain('page_view');
            expect($result['funnel_progress'])->toContain('subscription');
            expect($result['funnel_completion_pct'])->toBeGreaterThan(0.0);
        });
    });

    describe('activationScore()', function (): void {
        it('returns structured activation assessment', function (): void {
            $signals = [
                'activation_score' => 45,
                'activation_steps' => ['trial_started', 'feature_engagement'],
            ];

            $this->cache->shouldReceive('get')
                ->with('zb_lifecycle_user_123')
                ->andReturn($signals);

            $result = $this->observer->activationScore('user_123');

            expect($result['score'])->toBe(45);
            expect($result['grade'])->toBe('C');
            expect($result['completed_steps'])->toBe(['trial_started', 'feature_engagement']);
            expect($result['signals'])->toBeArray();
        });
    });

    describe('churnRisk()', function (): void {
        it('returns structured churn risk assessment', function (): void {
            $signals = [
                'churn_risk_score' => 55,
                'churn_indicators' => ['billing_retry', 'feature_limit_reached'],
            ];

            $this->cache->shouldReceive('get')
                ->with('zb_lifecycle_user_123')
                ->andReturn($signals);

            $result = $this->observer->churnRisk('user_123');

            expect($result['risk_score'])->toBe(55);
            expect($result['risk_level'])->toBe('high');
            expect($result['recommendation'])->toBeString();
            expect($result['indicators'])->toHaveCount(2);
        });
    });

    describe('forget() — GDPR', function (): void {
        it('deletes cached signals', function (): void {
            $this->cache->shouldReceive('forget')
                ->with('zb_lifecycle_user_123')
                ->once();

            $this->observer->forget('user_123');
        });
    });

    describe('static helpers', function (): void {
        it('provides trial step map', function (): void {
            $map = SaaSLifecycleObserver::trialStepMap();

            expect($map)->toBeArray();
            expect($map)->toHaveKey('trial_start');
            expect($map)->toHaveKey('subscription');
            expect($map['trial_start'])->toBe('trial_started');
        });

        it('provides trial weights', function (): void {
            $weights = SaaSLifecycleObserver::trialWeights();

            expect($weights)->toBeArray();
            expect($weights['trial_converted'])->toBe(100);
            expect($weights['trial_start'])->toBe(0);
        });

        it('provides churn risk weights', function (): void {
            $weights = SaaSLifecycleObserver::churnRiskWeights();

            expect($weights)->toBeArray();
            expect($weights['billing_retry'])->toBe(35);
        });
    });

    describe('aggregateMetrics()', function (): void {
        it('returns empty metrics when no cached summary exists', function (): void {
            $this->cache->shouldReceive('get')
                ->andReturn(null);

            $result = $this->observer->aggregateMetrics();

            expect($result['total_tracked'])->toBe(0);
            expect($result['avg_activation'])->toBe(0.0);
            expect($result['expansion_momentum'])->toBe(0.0);
        });

        it('returns cached summary when available', function (): void {
            $summary = [
                'total_tracked' => 42,
                'avg_activation' => 67.5,
                'avg_churn_risk' => 23.0,
            ];

            $this->cache->shouldReceive('get')
                ->andReturn($summary);

            $result = $this->observer->aggregateMetrics();

            expect($result['total_tracked'])->toBe(42);
            expect($result['avg_activation'])->toBe(67.5);
        });
    });

    describe('storeAggregateSummary()', function (): void {
        it('persists aggregate summary to cache', function (): void {
            $metrics = ['total_tracked' => 100, 'avg_activation' => 80.0];
            $this->cache->shouldReceive('put')
                ->with('zb_lifecycle_aggregate_summary', $metrics, 3600)
                ->once();

            $this->observer->storeAggregateSummary($metrics);
        });
    });
});

describe('AnalyticsReadinessScoreService', function (): void {
    beforeEach(function (): void {
        $this->config = Mockery::mock(ConfigRepository::class);
    });

    afterEach(function (): void {
        Mockery::close();
    });

    describe('compute()', function (): void {
        it('returns full readiness assessment with defaults', function (): void {
            // Mock all config reads
            $this->config->shouldReceive('get')
                ->andReturnUsing(function (string $key, mixed $default = null) {
                    $defaults = [
                        'zeroboiler.analytics.ga4.enabled' => false,
                        'zeroboiler.analytics.gtm.enabled' => false,
                        'zeroboiler.analytics.meta_pixel.enabled' => false,
                        'zeroboiler.analytics.plausible.enabled' => false,
                        'zeroboiler.analytics.posthog.enabled' => false,
                        'zeroboiler.analytics.webhook.enabled' => false,
                        'zeroboiler.analytics.auto_track.events' => [],
                        'zeroboiler.analytics.identity.cookie_name' => 'zb_analytics_id',
                        'zeroboiler.analytics.identity.link_on_auth' => true,
                        'zeroboiler.analytics.consent.default' => 'granted',
                        'zeroboiler.analytics.consent.purposes' => [],
                        'zeroboiler.analytics.queue.enabled' => true,
                        'zeroboiler.analytics.queue.connection' => null,
                        'zeroboiler.analytics.ecommerce.currency' => 'USD',
                        'zeroboiler.analytics.auto_track.enabled' => true,
                        'zeroboiler.analytics.lifecycle.enabled' => true,
                        'zeroboiler.analytics.api.enabled' => true,
                    ];

                    return $defaults[$key] ?? $default;
                });

            $service = new AnalyticsReadinessScoreService($this->config);
            $result = $service->compute();

            expect($result)->toHaveKeys(['score', 'grade', 'dimensions', 'recommendations', 'computed_at']);
            expect($result['score'])->toBeInt();
            expect($result['grade'])->toBeString();
            expect($result['dimensions'])->toBeArray();

            // Should have 8 dimensions
            expect(count($result['dimensions']))->toBe(8);
        });

        it('returns higher score when providers are enabled', function (): void {
            $this->config->shouldReceive('get')
                ->andReturnUsing(function (string $key, mixed $default = null) {
                    $providerKeys = [
                        'zeroboiler.analytics.ga4.enabled',
                        'zeroboiler.analytics.gtm.enabled',
                        'zeroboiler.analytics.meta_pixel.enabled',
                        'zeroboiler.analytics.plausible.enabled',
                        'zeroboiler.analytics.posthog.enabled',
                        'zeroboiler.analytics.webhook.enabled',
                    ];

                    if (in_array($key, $providerKeys, true)) {
                        return $key === 'zeroboiler.analytics.ga4.enabled';
                    }

                    return $default;
                });

            $service = new AnalyticsReadinessScoreService($this->config);
            $result = $service->compute();

            // Provider dimension should contribute to score
            expect($result['dimensions']['providers']['score'])->toBeGreaterThan(0);
        });
    });

    describe('isReady()', function (): void {
        it('returns true when score >= 60', function (): void {
            $this->config->shouldReceive('get')
                ->andReturnUsing(function (string $key) {
                    $ga4 = 'zeroboiler.analytics.ga4.enabled';
                    if ($key === $ga4) return true;
                    return match ($key) {
                        'zeroboiler.analytics.gtm.enabled' => false,
                        'zeroboiler.analytics.meta_pixel.enabled' => false,
                        'zeroboiler.analytics.plausible.enabled' => false,
                        'zeroboiler.analytics.posthog.enabled' => false,
                        'zeroboiler.analytics.webhook.enabled' => false,
                        'zeroboiler.analytics.auto_track.events' => [
                            'auth.login' => true,
                            'auth.register' => true,
                            'subscription.created' => true,
                            'trial.started' => true,
                            'feature.used' => true,
                        ],
                        'zeroboiler.analytics.identity.cookie_name' => 'zb_id',
                        'zeroboiler.analytics.identity.link_on_auth' => true,
                        'zeroboiler.analytics.consent.default' => 'denied',
                        'zeroboiler.analytics.consent.purposes' => ['a', 'b', 'c'],
                        'zeroboiler.analytics.queue.enabled' => true,
                        'zeroboiler.analytics.queue.connection' => 'redis',
                        'zeroboiler.analytics.ecommerce.currency' => 'USD',
                        'zeroboiler.analytics.auto_track.enabled' => true,
                        'zeroboiler.analytics.lifecycle.enabled' => true,
                        'zeroboiler.analytics.api.enabled' => true,
                        default => null,
                    };
                });

            $service = new AnalyticsReadinessScoreService($this->config);

            expect($service->isReady())->toBeTrue();
            expect($service->score())->toBeGreaterThanOrEqual(60);
        });
    });

    describe('dimensionDefinitions()', function (): void {
        it('returns all 8 dimension definitions', function (): void {
            $defs = AnalyticsReadinessScoreService::dimensionDefinitions();

            expect($defs)->toHaveCount(8);
            expect($defs)->toHaveKey('providers');
            expect($defs)->toHaveKey('catalog');
            expect($defs)->toHaveKey('identity');
            expect($defs)->toHaveKey('consent');
            expect($defs)->toHaveKey('queue');
            expect($defs)->toHaveKey('ecommerce');
            expect($defs)->toHaveKey('saas_lifecycle');
            expect($defs)->toHaveKey('client');

            expect($defs['providers']['label'])->toBe('Provider Configuration');
            expect($defs['providers']['max'])->toBe(15);
        });
    });
});
