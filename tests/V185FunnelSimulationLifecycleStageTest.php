<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Services\FunnelSimulationService;
use ZeroBoiler\Analytics\Services\SaaSLifecycleStageService;

beforeEach(function (): void {
    $this->cache = mock(CacheRepository::class);
    $this->config = mock(ConfigRepository::class);
    $this->manager = mock(AnalyticsManager::class);

    // Default config returns for funnel simulation
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.funnel_simulation', [])
        ->andReturn(['enabled' => true, 'simulations' => 1000, 'seed' => 42]);

    // Default config returns for lifecycle stages
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.lifecycle_stages', [])
        ->andReturn(['enabled' => true]);

    $this->cache->shouldReceive('put')->zeroOrMoreTimes();
});

// ─── FunnelSimulationService Tests ────────────────────────────────────

describe('FunnelSimulationService', function (): void {
    describe('construction', function (): void {
        test('constructs with default config when enabled', function (): void {
            $service = new FunnelSimulationService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            expect($service->isEnabled())->toBeTrue();
        });

        test('constructs as disabled when config says so', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.funnel_simulation', [])
                ->andReturn(['enabled' => false]);

            $service = new FunnelSimulationService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            expect($service->isEnabled())->toBeFalse();
        });

        test('respects custom simulation count from config', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.funnel_simulation', [])
                ->andReturn(['enabled' => true, 'simulations' => 5000, 'seed' => 99]);

            $service = new FunnelSimulationService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            expect($service->getSimulationsCount())->toBe(5000);
        });
    });

    describe('setObservedData', function (): void {
        test('stores rates and counts correctly', function (): void {
            $service = new FunnelSimulationService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->setObservedData(
                ['signup' => 0.4, 'trial' => 0.6],
                ['signup' => 100, 'trial' => 80],
            );

            $data = $service->getObservedData();

            expect($data['rates']['signup'])->toBe(0.4);
            expect($data['counts']['signup'])->toBe(100);
            expect($data['rates']['trial'])->toBe(0.6);
            expect($data['counts']['trial'])->toBe(80);
        });
    });

    describe('recordObservation', function (): void {
        test('accumulates observations correctly', function (): void {
            $service = new FunnelSimulationService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->recordObservation('signup', true);
            $service->recordObservation('signup', false);
            $service->recordObservation('signup', true);
            $service->recordObservation('signup', true);

            $data = $service->getObservedData();

            expect($data['counts']['signup'])->toBe(4);
            expect($data['rates']['signup'])->toBe(0.75);
        });

        test('initializes new stage correctly', function (): void {
            $service = new FunnelSimulationService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->recordObservation('trial', true);

            $data = $service->getObservedData();

            expect($data['counts']['trial'])->toBe(1);
            expect($data['rates']['trial'])->toBe(1.0);
        });
    });

    describe('simulateStage', function (): void {
        test('returns result structure with all required keys', function (): void {
            $service = new FunnelSimulationService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->setObservedData(
                ['signup' => 0.5],
                ['signup' => 200],
            );

            $result = $service->simulateStage('signup');

            expect($result)->toHaveKey('mean');
            expect($result)->toHaveKey('std_error');
            expect($result)->toHaveKey('ci_90');
            expect($result)->toHaveKey('ci_95');
            expect($result)->toHaveKey('ci_99');
            expect($result)->toHaveKey('risk_below');
            expect($result)->toHaveKey('simulations');
            expect($result)->toHaveKey('stage');
            expect($result)->toHaveKey('observed_rate');
            expect($result)->toHaveKey('observed_n');
            expect($result['stage'])->toBe('signup');
            expect($result['observed_rate'])->toBe(0.5);
            expect($result['observed_n'])->toBe(200);
        });

        test('mean is close to observed rate with sufficient data', function (): void {
            $service = new FunnelSimulationService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->setObservedData(
                ['signup' => 0.35],
                ['signup' => 500],
            );

            $result = $service->simulateStage('signup');

            // Mean should be within 5% of observed rate
            expect(abs($result['mean'] - 0.35))->toBeLessThan(0.05);
        });

        test('returns insufficient_data flag for low observations', function (): void {
            $service = new FunnelSimulationService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->setObservedData(
                ['signup' => 0.5],
                ['signup' => 5],
            );

            $result = $service->simulateStage('signup');

            expect($result['insufficient_data'])->toBeTrue();
            expect($result['mean'])->toBe(0.5);
        });

        test('ci_90 lower <= mean <= ci_90 upper', function (): void {
            $service = new FunnelSimulationService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->setObservedData(
                ['trial' => 0.25],
                ['trial' => 300],
            );

            $result = $service->simulateStage('trial');

            expect($result['ci_90']['lower'])->toBeLessThanOrEqual($result['mean']);
            expect($result['ci_90']['upper'])->toBeGreaterThanOrEqual($result['mean']);
            expect($result['ci_95']['lower'])->toBeLessThanOrEqual($result['ci_90']['lower']);
            expect($result['ci_95']['upper'])->toBeGreaterThanOrEqual($result['ci_90']['upper']);
        });

        test('risk_below is between 0 and 1', function (): void {
            $service = new FunnelSimulationService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->setObservedData(
                ['signup' => 0.5],
                ['signup' => 200],
            );

            $result = $service->simulateStage('signup');

            expect($result['risk_below'])->toBeGreaterThanOrEqual(0.0);
            expect($result['risk_below'])->toBeLessThanOrEqual(1.0);
        });
    });

    describe('runSimulation', function (): void {
        test('returns full simulation snapshot structure', function (): void {
            $this->cache->shouldReceive('get')->andReturn(null);

            $service = new FunnelSimulationService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->setObservedData(
                ['signup' => 0.4, 'trial' => 0.6, 'activation' => 0.7],
                ['signup' => 500, 'trial' => 300, 'activation' => 200],
            );

            $snapshot = $service->runSimulation(['signup', 'trial', 'activation']);

            expect($snapshot)->toHaveKey('funnel');
            expect($snapshot)->toHaveKey('stages');
            expect($snapshot)->toHaveKey('overall_conversion');
            expect($snapshot)->toHaveKey('probability_profile');
            expect($snapshot)->toHaveKey('risk_summary');
            expect($snapshot)->toHaveKey('target_analysis');
            expect($snapshot)->toHaveKey('recommendations');
            expect($snapshot)->toHaveKey('computed_at');
            expect($snapshot)->toHaveKey('seed');
            expect($snapshot)->toHaveKey('n_simulations');
        });

        test('overall conversion is product of per-stage conversions', function (): void {
            $this->cache->shouldReceive('get')->andReturn(null);

            $service = new FunnelSimulationService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->setObservedData(
                ['signup' => 0.5, 'trial' => 0.5],
                ['signup' => 400, 'trial' => 200],
            );

            $snapshot = $service->runSimulation(['signup', 'trial']);

            // Overall should be close to 0.5 * 0.5 = 0.25
            $overall = $snapshot['overall_conversion']['mean'];
            expect(abs($overall - 0.25))->toBeLessThan(0.1);
        });

        test('probability_profile has all percentile keys', function (): void {
            $this->cache->shouldReceive('get')->andReturn(null);

            $service = new FunnelSimulationService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->setObservedData(
                ['signup' => 0.4],
                ['signup' => 300],
            );

            $snapshot = $service->runSimulation(['signup']);
            $profile = $snapshot['probability_profile'];

            expect($profile)->toHaveKeys(['p10', 'p25', 'p50', 'p75', 'p90']);
            expect($profile['p10'])->toBeLessThanOrEqual($profile['p50']);
            expect($profile['p50'])->toBeLessThanOrEqual($profile['p90']);
        });

        test('risk_summary has required structure', function (): void {
            $this->cache->shouldReceive('get')->andReturn(null);

            $service = new FunnelSimulationService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->setObservedData(
                ['signup' => 0.5],
                ['signup' => 200],
            );

            $snapshot = $service->runSimulation(['signup']);

            expect($snapshot['risk_summary'])->toHaveKey('high_risk_stages');
            expect($snapshot['risk_summary'])->toHaveKey('overall_risk');
            expect($snapshot['risk_summary']['overall_risk'])->toBeIn(['low', 'medium', 'high']);
        });

        test('target_analysis works with target rate', function (): void {
            $this->cache->shouldReceive('get')->andReturn(null);

            $service = new FunnelSimulationService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->setObservedData(
                ['signup' => 0.3],
                ['signup' => 200],
            );

            $snapshot = $service->runSimulation(['signup'], 0.4);

            expect($snapshot['target_analysis']['target'])->toBe(0.4);
            expect($snapshot['target_analysis']['probability_of_achieving'])->toBeFloat();
            expect($snapshot['target_analysis']['gap_to_target'])->toBeFloat();
        });

        test('caches simulation results', function (): void {
            $this->cache->shouldReceive('get')->andReturn(null);
            $this->cache->shouldReceive('put')->once();

            $service = new FunnelSimulationService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->setObservedData(
                ['signup' => 0.5],
                ['signup' => 100],
            );

            $service->runSimulation(['signup']);
        });

        test('generates recommendations', function (): void {
            $this->cache->shouldReceive('get')->andReturn(null);

            $service = new FunnelSimulationService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->setObservedData(
                ['signup' => 0.1, 'trial' => 0.8],
                ['signup' => 200, 'trial' => 200],
            );

            $snapshot = $service->runSimulation(['signup', 'trial']);

            expect($snapshot['recommendations'])->not->toBeEmpty();
        });
    });

    describe('whatIfSimulation', function (): void {
        test('returns baseline, improved, and delta', function (): void {
            $this->cache->shouldReceive('get')->andReturn(null);

            $service = new FunnelSimulationService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->setObservedData(
                ['signup' => 0.3, 'trial' => 0.5],
                ['signup' => 200, 'trial' => 150],
            );

            $result = $service->whatIfSimulation(['signup', 'trial'], 'signup', 1.5);

            expect($result)->toHaveKey('baseline');
            expect($result)->toHaveKey('improved');
            expect($result)->toHaveKey('delta');
            expect($result['delta'])->toHaveKey('absolute_lift');
            expect($result['delta'])->toHaveKey('relative_lift');
            expect($result['delta'])->toHaveKey('p_improvement_significant');
        });

        test('positive improvement produces positive absolute lift', function (): void {
            $this->cache->shouldReceive('get')->andReturn(null);

            $service = new FunnelSimulationService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->setObservedData(
                ['signup' => 0.3],
                ['signup' => 200],
            );

            $result = $service->whatIfSimulation(['signup'], 'signup', 2.0);

            expect($result['delta']['absolute_lift'])->toBeGreaterThan(0.0);
        });

        test('restores original rate after simulation', function (): void {
            $this->cache->shouldReceive('get')->andReturn(null);

            $service = new FunnelSimulationService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->setObservedData(
                ['signup' => 0.3],
                ['signup' => 200],
            );

            $result = $service->whatIfSimulation(['signup'], 'signup', 1.5);
            $data = $service->getObservedData();

            // Rate should be restored to original
            expect($data['rates']['signup'])->toBe(0.3);
        });
    });

    describe('quickSummary', function (): void {
        test('returns quick summary structure', function (): void {
            $service = new FunnelSimulationService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $snapshot = [
                'overall_conversion' => [
                    'mean' => 0.12,
                    'ci_95' => ['lower' => 0.08, 'upper' => 0.16],
                ],
                'risk_summary' => [
                    'high_risk_stages' => ['trial'],
                    'overall_risk' => 'medium',
                ],
                'target_analysis' => [
                    'probability_of_achieving' => 0.35,
                ],
                'recommendations' => ['Focus on trial conversion'],
            ];

            $summary = $service->quickSummary($snapshot);

            expect($summary)->toHaveKey('overall_mean');
            expect($summary)->toHaveKey('overall_ci_95');
            expect($summary)->toHaveKey('risk_level');
            expect($summary)->toHaveKey('high_risk_stages');
            expect($summary)->toHaveKey('target_probability');
            expect($summary)->toHaveKey('top_recommendation');
            expect($summary['overall_mean'])->toBe(0.12);
            expect($summary['risk_level'])->toBe('medium');
            expect($summary['top_recommendation'])->toBe('Focus on trial conversion');
        });
    });
});

// ─── SaaSLifecycleStageService Tests ────────────────────────────────────

describe('SaaSLifecycleStageService', function (): void {
    describe('construction', function (): void {
        test('constructs with default config when enabled', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            expect($service->isEnabled())->toBeTrue();
        });

        test('constructs as disabled when config says so', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.lifecycle_stages', [])
                ->andReturn(['enabled' => false]);

            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            expect($service->isEnabled())->toBeFalse();
        });
    });

    describe('getStages', function (): void {
        test('returns all 6 default stages', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $stages = $service->getStages();

            expect($stages)->toHaveKeys(['prospect', 'trial', 'active', 'engaged', 'at_risk', 'churned']);
        });

        test('each stage has required definition fields', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $stages = $service->getStages();

            foreach ($stages as $key => $definition) {
                expect($definition)->toHaveKey('key');
                expect($definition)->toHaveKey('name');
                expect($definition)->toHaveKey('description');
                expect($definition)->toHaveKey('order');
                expect($definition)->toHaveKey('color');
                expect($definition['key'])->toBe($key);
            }
        });

        test('getStageKeys returns stages in order', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $keys = $service->getStageKeys();

            expect($keys)->toBe(['prospect', 'trial', 'active', 'engaged', 'at_risk', 'churned']);
        });
    });

    describe('determineStage', function (): void {
        test('identifies churned user from cancellation event', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $signals = [
                'events' => ['sign_up', 'subscription.created', 'subscription.cancelled'],
                'days_since_last_activity' => 2,
            ];

            $result = $service->determineStage('user-1', $signals);

            expect($result['stage'])->toBe('churned');
            expect($result['user_id'])->toBe('user-1');
            expect($result['reasons'])->toContain('Subscription cancelled detected');
        });

        test('identifies prospect with only signup', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $signals = [
                'events' => ['sign_up'],
                'days_since_last_activity' => 1,
                'events_per_week' => 0.0,
            ];

            $result = $service->determineStage('user-2', $signals);

            expect($result['stage'])->toBe('prospect');
        });

        test('identifies trial user', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $signals = [
                'events' => ['sign_up', 'start_trial'],
                'days_since_last_activity' => 1,
                'events_per_week' => 5.0,
                'features_used' => 2,
            ];

            $result = $service->determineStage('user-3', $signals);

            expect($result['stage'])->toBe('trial');
        });

        test('identifies active subscriber', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $signals = [
                'events' => ['sign_up', 'subscription.created'],
                'days_since_last_activity' => 1,
                'events_per_week' => 8.0,
                'dau_streak' => 3,
                'features_used' => 3,
            ];

            $result = $service->determineStage('user-4', $signals);

            expect($result['stage'])->toBe('active');
        });

        test('identifies engaged power user', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $signals = [
                'events' => ['sign_up', 'subscription.created'],
                'days_since_last_activity' => 0,
                'events_per_week' => 25.0,
                'dau_streak' => 10,
                'features_used' => 8,
            ];

            $result = $service->determineStage('user-5', $signals);

            expect($result['stage'])->toBe('engaged');
        });

        test('identifies at_risk subscriber with low activity', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $signals = [
                'events' => ['sign_up', 'subscription.created'],
                'days_since_last_activity' => 20,
                'events_per_week' => 0.5,
                'dau_streak' => 0,
                'features_used' => 1,
            ];

            $result = $service->determineStage('user-6', $signals);

            expect($result['stage'])->toBe('at_risk');
        });

        test('returns score between 0 and 1', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $signals = [
                'events' => ['sign_up', 'subscription.created'],
                'days_since_last_activity' => 1,
                'events_per_week' => 10.0,
                'dau_streak' => 5,
                'features_used' => 4,
            ];

            $result = $service->determineStage('user-7', $signals);

            expect($result['score'])->toBeGreaterThanOrEqual(0.0);
            expect($result['score'])->toBeLessThanOrEqual(1.0);
        });

        test('returns record with all required keys', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $signals = [
                'events' => ['sign_up'],
                'days_since_last_activity' => 0,
            ];

            $result = $service->determineStage('user-8', $signals);

            expect($result)->toHaveKeys([
                'user_id',
                'stage',
                'previous_stage',
                'score',
                'entered_at',
                'signals',
                'reasons',
            ]);
            expect($result['reasons'])->not->toBeEmpty();
        });

        test('inactive trial user becomes prospect', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $signals = [
                'events' => ['sign_up', 'start_trial'],
                'days_since_last_activity' => 30,
                'events_per_week' => 0.0,
                'features_used' => 0,
            ];

            $result = $service->determineStage('user-9', $signals);

            expect($result['stage'])->toBe('prospect');
        });
    });

    describe('computeDistribution', function (): void {
        test('computes correct stage counts', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $userSignals = [
                'u1' => ['events' => ['sign_up'], 'days_since_last_activity' => 1, 'events_per_week' => 0],
                'u2' => ['events' => ['sign_up', 'start_trial'], 'days_since_last_activity' => 1, 'events_per_week' => 5, 'features_used' => 2],
                'u3' => ['events' => ['sign_up', 'subscription.created'], 'days_since_last_activity' => 1, 'events_per_week' => 8, 'dau_streak' => 3, 'features_used' => 3],
                'u4' => ['events' => ['sign_up', 'subscription.created', 'subscription.cancelled'], 'days_since_last_activity' => 2],
                'u5' => ['events' => ['sign_up', 'subscription.created'], 'days_since_last_activity' => 20, 'events_per_week' => 0.5],
            ];

            $distribution = $service->computeDistribution($userSignals);

            expect($distribution['total_users'])->toBe(5);
            expect($distribution['stages']['prospect']['count'])->toBe(1);
            expect($distribution['stages']['trial']['count'])->toBe(1);
            expect($distribution['stages']['active']['count'])->toBe(1);
            expect($distribution['stages']['churned']['count'])->toBe(1);
            expect($distribution['stages']['at_risk']['count'])->toBe(1);
        });

        test('percentages sum to approximately 100', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $userSignals = [
                'u1' => ['events' => ['sign_up'], 'days_since_last_activity' => 1],
                'u2' => ['events' => ['sign_up', 'start_trial'], 'days_since_last_activity' => 1, 'events_per_week' => 5, 'features_used' => 2],
                'u3' => ['events' => ['sign_up', 'subscription.created'], 'days_since_last_activity' => 1, 'events_per_week' => 8, 'dau_streak' => 3, 'features_used' => 3],
            ];

            $distribution = $service->computeDistribution($userSignals);

            $totalPct = 0.0;
            foreach ($distribution['stages'] as $stage) {
                $totalPct += $stage['percentage'];
            }

            expect($totalPct)->toBe(100.0);
        });

        test('returns distribution structure with computed_at', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $distribution = $service->computeDistribution([]);

            expect($distribution)->toHaveKey('stages');
            expect($distribution)->toHaveKey('total_users');
            expect($distribution)->toHaveKey('computed_at');
            expect($distribution['total_users'])->toBe(0);
        });
    });

    describe('analyzeTransitions', function (): void {
        test('computes transition flow between periods', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $previous = [
                'stages' => [
                    'prospect' => ['count' => 10],
                    'trial' => ['count' => 5],
                    'active' => ['count' => 20],
                    'engaged' => ['count' => 5],
                    'at_risk' => ['count' => 3],
                    'churned' => ['count' => 2],
                ],
                'total_users' => 45,
            ];

            $current = [
                'stages' => [
                    'prospect' => ['count' => 8],
                    'trial' => ['count' => 7],
                    'active' => ['count' => 22],
                    'engaged' => ['count' => 5],
                    'at_risk' => ['count' => 1],
                    'churned' => ['count' => 2],
                ],
                'total_users' => 45,
            ];

            $result = $service->analyzeTransitions($current, $previous);

            expect($result)->toHaveKey('transitions');
            expect($result)->toHaveKey('net_changes');
            expect($result)->toHaveKey('health_indicators');
            expect($result['net_changes']['active'])->toBe(2);
            expect($result['net_changes']['prospect'])->toBe(-2);
        });

        test('flags concerning trends', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $previous = [
                'stages' => [
                    'prospect' => ['count' => 10],
                    'trial' => ['count' => 10],
                    'active' => ['count' => 10],
                    'engaged' => ['count' => 10],
                    'at_risk' => ['count' => 5],
                    'churned' => ['count' => 5],
                ],
                'total_users' => 50,
            ];

            $current = [
                'stages' => [
                    'prospect' => ['count' => 5],
                    'trial' => ['count' => 5],
                    'active' => ['count' => 5],
                    'engaged' => ['count' => 5],
                    'at_risk' => ['count' => 15],
                    'churned' => ['count' => 15],
                ],
                'total_users' => 50,
            ];

            $result = $service->analyzeTransitions($current, $previous);

            expect($result['health_indicators']['healthy_flow'])->toBeFalse();
            expect($result['health_indicators']['concern_stages'])->not->toBeEmpty();
        });
    });

    describe('cohortBreakdown', function (): void {
        test('groups users by cohort', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $userSignals = [
                'u1' => ['events' => ['sign_up'], 'days_since_last_activity' => 1, 'cohort' => '2026-W01'],
                'u2' => ['events' => ['sign_up'], 'days_since_last_activity' => 1, 'cohort' => '2026-W01'],
                'u3' => ['events' => ['sign_up', 'subscription.created'], 'days_since_last_activity' => 1, 'events_per_week' => 8, 'dau_streak' => 3, 'features_used' => 3, 'cohort' => '2026-W02'],
            ];

            $result = $service->cohortBreakdown($userSignals);

            expect($result)->toHaveKey('cohorts');
            expect($result)->toHaveKey('overall');
            expect($result['cohorts'])->toHaveKey('2026-W01');
            expect($result['cohorts'])->toHaveKey('2026-W02');
            expect($result['overall']['total_users'])->toBe(3);
        });
    });

    describe('stageRecommendations', function (): void {
        test('returns recommendations for prospect', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $recs = $service->stageRecommendations('prospect');

            expect($recs)->not->toBeEmpty();
            expect($recs[0])->toBeString();
        });

        test('returns recommendations for engaged', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $recs = $service->stageRecommendations('engaged');

            expect($recs)->not->toBeEmpty();
            expect($recs[0])->toBeString();
        });

        test('returns recommendations for churned', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $recs = $service->stageRecommendations('churned');

            expect($recs)->not->toBeEmpty();
            expect($recs[0])->toBeString();
        });

        test('returns fallback for unknown stage', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $recs = $service->stageRecommendations('unknown');

            expect($recs)->not->toBeEmpty();
        });
    });

    describe('quickSummary', function (): void {
        test('returns summary structure', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $distribution = [
                'stages' => [
                    'prospect' => ['count' => 10],
                    'trial' => ['count' => 5],
                    'active' => ['count' => 20],
                    'engaged' => ['count' => 10],
                    'at_risk' => ['count' => 3],
                    'churned' => ['count' => 2],
                ],
                'total_users' => 50,
            ];

            $summary = $service->quickSummary($distribution);

            expect($summary)->toHaveKey('healthy_ratio');
            expect($summary)->toHaveKey('at_risk_count');
            expect($summary)->toHaveKey('churned_count');
            expect($summary)->toHaveKey('engaged_count');
            expect($summary)->toHaveKey('top_stage');
            expect($summary)->toHaveKey('recommendation');
            expect($summary['healthy_ratio'])->toBe(0.6);
            expect($summary['top_stage'])->toBe('active');
        });

        test('detects high at-risk proportion', function (): void {
            $service = new SaaSLifecycleStageService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $distribution = [
                'stages' => [
                    'prospect' => ['count' => 5],
                    'trial' => ['count' => 2],
                    'active' => ['count' => 5],
                    'engaged' => ['count' => 2],
                    'at_risk' => ['count' => 30],
                    'churned' => ['count' => 6],
                ],
                'total_users' => 50,
            ];

            $summary = $service->quickSummary($distribution);

            expect($summary['recommendation'])->toContain('at-risk');
        });
    });
});

// ─── Version Consistency Tests ────────────────────────────────────────────

describe('Version 185 Production Readiness', function (): void {
    test('FunnelSimulationService has declare strict types', function (): void {
        $reflection = new \ReflectionClass(FunnelSimulationService::class);
        $file = $reflection->getFileName();

        expect($file)->not->toBeFalse();

        $contents = file_get_contents($file);
        expect($contents)->toContain('declare(strict_types=1)');
    });

    test('SaaSLifecycleStageService has declare strict types', function (): void {
        $reflection = new \ReflectionClass(SaaSLifecycleStageService::class);
        $file = $reflection->getFileName();

        expect($file)->not->toBeFalse();

        $contents = file_get_contents($file);
        expect($contents)->toContain('declare(strict_types=1)');
    });

    test('FunnelSimulationService is final', function (): void {
        $reflection = new \ReflectionClass(FunnelSimulationService::class);

        expect($reflection->isFinal())->toBeTrue();
    });

    test('SaaSLifecycleStageService is final', function (): void {
        $reflection = new \ReflectionClass(SaaSLifecycleStageService::class);

        expect($reflection->isFinal())->toBeTrue();
    });

    test('FunnelSimulationService constructor is void return', function (): void {
        $method = new \ReflectionMethod(FunnelSimulationService::class, '__construct');

        expect($method->getReturnType()?->getName())->toBe('void');
    });

    test('SaaSLifecycleStageService constructor is void return', function (): void {
        $method = new \ReflectionMethod(SaaSLifecycleStageService::class, '__construct');

        expect($method->getReturnType()?->getName())->toBe('void');
    });

    test('FunnelSimulationService key methods have return types', function (): void {
        $class = new \ReflectionClass(FunnelSimulationService::class);

        $methods = ['isEnabled', 'simulateStage', 'runSimulation', 'whatIfSimulation', 'quickSummary', 'getObservedData'];
        foreach ($methods as $methodName) {
            $method = $class->getMethod($methodName);
            expect($method->hasReturnType(), "{$methodName} must have return type")->toBeTrue();
        }
    });

    test('SaaSLifecycleStageService key methods have return types', function (): void {
        $class = new \ReflectionClass(SaaSLifecycleStageService::class);

        $methods = ['isEnabled', 'determineStage', 'computeDistribution', 'analyzeTransitions', 'cohortBreakdown', 'quickSummary', 'getStages', 'getStageKeys'];
        foreach ($methods as $methodName) {
            $method = $class->getMethod($methodName);
            expect($method->hasReturnType(), "{$methodName} must have return type")->toBeTrue();
        }
    });

    test('both services have MIT license headers', function (): void {
        $simFile = (new \ReflectionClass(FunnelSimulationService::class))->getFileName();
        $stageFile = (new \ReflectionClass(SaaSLifecycleStageService::class))->getFileName();

        expect($simFile)->not->toBeFalse();
        expect($stageFile)->not->toBeFalse();

        expect(file_get_contents($simFile))->toContain('MIT license');
        expect(file_get_contents($stageFile))->toContain('MIT license');
    });
});
