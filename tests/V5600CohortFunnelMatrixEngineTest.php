<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsCohortFunnelCommand;
use ZeroBoiler\Analytics\Services\CohortFunnelMatrixService;

beforeEach(function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.cohort_funnel_matrix', [])
        ->andReturn([
            'enabled' => true,
            'cache_ttl' => 600,
            'max_cohorts' => 24,
            'max_steps' => 20,
            'cohort_dimensions' => ['period', 'source', 'plan', 'tier', 'device'],
            'custom_funnels' => [],
        ]);

    $cache->shouldReceive('remember')
        ->andReturnUsing(function (string $key, int $ttl, \Closure $callback) {
            return $callback();
        });

    $this->service = new CohortFunnelMatrixService($cache, $config);
    $this->cache = $cache;
    $this->config = $config;
});

describe('CohortFunnelMatrixService', function (): void {
    describe('construction', function (): void {
        it('constructs with default config', function (): void {
            $cache = mock(CacheRepository::class);
            $config = mock(ConfigRepository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.cohort_funnel_matrix', [])
                ->andReturn([]);

            $service = new CohortFunnelMatrixService($cache, $config);
            expect($service->isEnabled())->toBeFalse();
        });

        it('constructs with enabled config', function (): void {
            expect($this->service->isEnabled())->toBeTrue();
        });

        it('respects max_cohorts and max_steps', function (): void {
            $summary = $this->service->configSummary();
            expect($summary['max_cohorts'])->toBe(24);
            expect($summary['max_steps'])->toBe(20);
        });
    });

    describe('configSummary', function (): void {
        it('returns full config summary', function (): void {
            $summary = $this->service->configSummary();

            expect($summary)->toHaveKeys([
                'enabled', 'cache_ttl', 'max_cohorts', 'max_steps',
                'funnel_templates', 'cohort_dimensions',
            ]);
            expect($summary['enabled'])->toBeTrue();
            expect($summary['cache_ttl'])->toBe(600);
            expect($summary['funnel_templates'])->toBe([
                'onboarding', 'purchase', 'saas_conversion', 'engagement',
            ]);
            expect($summary['cohort_dimensions'])->toContain('period', 'source', 'plan', 'tier', 'device');
        });
    });

    describe('funnelTemplates', function (): void {
        it('returns all four default templates', function (): void {
            $templates = $this->service->funnelTemplates();

            expect($templates)->toHaveKeys(['onboarding', 'purchase', 'saas_conversion', 'engagement']);
            expect($templates['onboarding'])->toBe([
                'sign_up', 'email_verified', 'profile_completed', 'first_feature_used', 'trial_started',
            ]);
            expect($templates['purchase'])->toBe([
                'view_item', 'add_to_cart', 'begin_checkout', 'add_payment_info', 'purchase',
            ]);
            expect($templates['saas_conversion'])->toBe([
                'sign_up', 'trial_start', 'feature_used', 'plan_upgrade', 'subscribe',
            ]);
            expect($templates['engagement'])->toBe([
                'page_view', 'scroll_depth', 'form_start', 'form_submit', 'share',
            ]);
        });

        it('returns null for unknown template', function (): void {
            expect($this->service->funnelTemplate('nonexistent'))->toBeNull();
        });

        it('returns a specific template', function (): void {
            expect($this->service->funnelTemplate('purchase'))
                ->toBe(['view_item', 'add_to_cart', 'begin_checkout', 'add_payment_info', 'purchase']);
        });

        it('registers custom funnel template at runtime', function (): void {
            $this->service->registerFunnelTemplate('custom_checkout', [
                'landing', 'signup', 'payment', 'done',
            ]);

            expect($this->service->funnelTemplate('custom_checkout'))
                ->toBe(['landing', 'signup', 'payment', 'done']);
            expect($this->service->funnelTemplates())->toHaveKey('custom_checkout');
        });
    });

    describe('buildMatrix', function (): void {
        it('builds a basic matrix with two cohorts and five steps', function (): void {
            $cohorts = ['2026-W28', '2026-W29'];
            $steps = ['sign_up', 'email_verified', 'profile_completed', 'first_feature_used', 'trial_started'];

            $data = [
                '2026-W28' => [
                    'sign_up' => ['count' => 500, 'users' => ['u1', 'u2'], 'timestamps' => [100, 200]],
                    'email_verified' => ['count' => 400, 'users' => ['u1'], 'timestamps' => [500]],
                    'profile_completed' => ['count' => 300, 'users' => ['u1'], 'timestamps' => [1000]],
                    'first_feature_used' => ['count' => 200, 'users' => ['u1'], 'timestamps' => [1500]],
                    'trial_started' => ['count' => 150, 'users' => ['u1'], 'timestamps' => [2000]],
                ],
                '2026-W29' => [
                    'sign_up' => ['count' => 600, 'users' => ['u3', 'u4'], 'timestamps' => [300, 400]],
                    'email_verified' => ['count' => 500, 'users' => ['u3'], 'timestamps' => [700]],
                    'profile_completed' => ['count' => 380, 'users' => ['u3'], 'timestamps' => [1200]],
                    'first_feature_used' => ['count' => 250, 'users' => ['u3'], 'timestamps' => [1700]],
                    'trial_started' => ['count' => 200, 'users' => ['u3'], 'timestamps' => [2200]],
                ],
            ];

            $result = $this->service->buildMatrix($cohorts, $steps, $data);

            expect($result['cohorts'])->toBe($cohorts);
            expect($result['steps'])->toBe($steps);
            expect(count($result['matrix']))->toBe(10); // 2 cohorts × 5 steps

            // First cell: first cohort, first step
            expect($result['matrix'][0])->toMatchArray([
                'cohort' => '2026-W28',
                'step' => 'sign_up',
                'count' => 500,
                'rate' => 100.0,
                'cumulative_rate' => 100.0,
            ]);

            // Summary
            expect($result['summary']['total_cohorts'])->toBe(2);
            expect($result['summary']['total_steps'])->toBe(5);
            expect($result['summary']['avg_conversion'])->toBeGreaterThan(0.0);
            expect($result['summary']['best_cohort'])->not->toBeNull();
            expect($result['summary']['worst_cohort'])->not->toBeNull();
            expect($result['summary']['bottleneck_step'])->not->toBeNull();
        });

        it('returns disabled matrix when service is disabled', function (): void {
            $cache = mock(CacheRepository::class);
            $config = mock(ConfigRepository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.cohort_funnel_matrix', [])
                ->andReturn(['enabled' => false]);

            $disabled = new CohortFunnelMatrixService($cache, $config);

            $result = $disabled->buildMatrix(['c1'], ['step1'], ['c1' => ['step1' => ['count' => 10, 'users' => [], 'timestamps' => []]]]);

            expect($result['cohorts'])->toBe([]);
            expect($result['matrix'])->toBe([]);
            expect($result['summary']['total_cohorts'])->toBe(0);
        });

        it('enforces max_cohorts limit', function (): void {
            $cohorts = array_map(fn (int $i): string => "c{$i}", range(1, 30));
            $steps = ['step1', 'step2'];

            $data = [];
            foreach ($cohorts as $c) {
                $data[$c] = [
                    'step1' => ['count' => 100, 'users' => [], 'timestamps' => [100]],
                    'step2' => ['count' => 50, 'users' => [], 'timestamps' => [200]],
                ];
            }

            $result = $this->service->buildMatrix($cohorts, $steps, $data);

            // Should only have 24 cohorts (max_cohorts = 24)
            expect(count($result['cohorts']))->toBe(24);
            expect($result['summary']['total_cohorts'])->toBe(24);
        });

        it('computes time_to_convert correctly', function (): void {
            $cohorts = ['c1'];
            $steps = ['step1', 'step2'];
            $data = [
                'c1' => [
                    'step1' => ['count' => 100, 'users' => [], 'timestamps' => [1000]],
                    'step2' => ['count' => 80, 'users' => [], 'timestamps' => [1300]],
                ],
            ];

            $result = $this->service->buildMatrix($cohorts, $steps, $data);

            // Second step should have time_to_convert = 300 seconds
            $step2Cell = $result['matrix'][1];
            expect($step2Cell['time_to_convert'])->toBe(300.0);
        });

        it('handles empty cohort data gracefully', function (): void {
            $result = $this->service->buildMatrix([], ['step1', 'step2'], []);

            expect($result['cohorts'])->toBe([]);
            expect($result['matrix'])->toBe([]);
            expect($result['summary']['avg_conversion'])->toBe(0.0);
        });

        it('handles single-step funnel', function (): void {
            $cohorts = ['c1', 'c2'];
            $steps = ['sign_up'];

            $data = [
                'c1' => ['sign_up' => ['count' => 100, 'users' => [], 'timestamps' => []]],
                'c2' => ['sign_up' => ['count' => 200, 'users' => [], 'timestamps' => []]],
            ];

            $result = $this->service->buildMatrix($cohorts, $steps, $data);

            expect(count($result['matrix']))->toBe(2);
            expect($result['summary']['avg_conversion'])->toBe(100.0);
        });
    });

    describe('buildFromTemplate', function (): void {
        it('builds matrix from onboarding template', function (): void {
            $cohorts = ['c1'];
            $steps = $this->service->funnelTemplate('onboarding');

            $data = [];
            foreach ($steps as $step) {
                $data['c1'][$step] = ['count' => 100, 'users' => [], 'timestamps' => [1000]];
            }

            $result = $this->service->buildFromTemplate('onboarding', $cohorts, $data);

            expect($result['steps'])->toBe($steps);
            expect($result['summary']['total_steps'])->toBe(5);
        });

        it('returns disabled matrix for unknown template', function (): void {
            $result = $this->service->buildFromTemplate('unknown', ['c1'], []);

            expect($result['cohorts'])->toBe([]);
            expect($result['matrix'])->toBe([]);
        });
    });

    describe('compareCohorts', function (): void {
        it('compares two cohorts step by step', function (): void {
            $steps = ['sign_up', 'email_verified', 'trial_started'];
            $data = [
                'c1' => [
                    'sign_up' => ['count' => 500, 'users' => [], 'timestamps' => [100]],
                    'email_verified' => ['count' => 400, 'users' => [], 'timestamps' => [500]],
                    'trial_started' => ['count' => 200, 'users' => [], 'timestamps' => [1000]],
                ],
                'c2' => [
                    'sign_up' => ['count' => 600, 'users' => [], 'timestamps' => [100]],
                    'email_verified' => ['count' => 300, 'users' => [], 'timestamps' => [500]],
                    'trial_started' => ['count' => 100, 'users' => [], 'timestamps' => [1000]],
                ],
            ];

            $result = $this->service->compareCohorts('c1', 'c2', $steps, $data);

            expect($result['cohort_a'])->toBe('c1');
            expect($result['cohort_b'])->toBe('c2');
            expect($result['steps'])->toBe($steps);
            expect(count($result['comparison']))->toBe(3);

            // Check delta calculations
            $emailStep = $result['comparison'][1];
            expect($emailStep['step'])->toBe('email_verified');
            expect($emailStep['count_a'])->toBe(400);
            expect($emailStep['count_b'])->toBe(300);
            expect($emailStep['count_delta'])->toBe(-100);
        });

        it('returns empty comparison when disabled', function (): void {
            $cache = mock(CacheRepository::class);
            $config = mock(ConfigRepository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.cohort_funnel_matrix', [])
                ->andReturn(['enabled' => false]);

            $disabled = new CohortFunnelMatrixService($cache, $config);

            $result = $disabled->compareCohorts('a', 'b', ['step1'], []);

            expect($result['comparison'])->toBe([]);
        });
    });

    describe('heatmap', function (): void {
        it('generates a heatmap matrix', function (): void {
            $cohorts = ['c1', 'c2'];
            $steps = ['sign_up', 'verified', 'active'];
            $data = [
                'c1' => [
                    'sign_up' => ['count' => 100, 'users' => [], 'timestamps' => []],
                    'verified' => ['count' => 60, 'users' => [], 'timestamps' => []],
                    'active' => ['count' => 30, 'users' => [], 'timestamps' => []],
                ],
                'c2' => [
                    'sign_up' => ['count' => 200, 'users' => [], 'timestamps' => []],
                    'verified' => ['count' => 150, 'users' => [], 'timestamps' => []],
                    'active' => ['count' => 80, 'users' => [], 'timestamps' => []],
                ],
            ];

            $result = $this->service->heatmap($cohorts, $steps, $data);

            expect($result['cohorts'])->toBe($cohorts);
            expect($result['steps'])->toBe($steps);
            expect($result['heatmap']['c1']['verified'])->toBe(60.0);
            expect($result['heatmap']['c2']['verified'])->toBe(75.0);
            expect($result['min'])->toBe(0.0);
            expect($result['max'])->toBeGreaterThan(0.0);
        });

        it('returns empty heatmap when disabled', function (): void {
            $cache = mock(CacheRepository::class);
            $config = mock(ConfigRepository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.cohort_funnel_matrix', [])
                ->andReturn(['enabled' => false]);

            $disabled = new CohortFunnelMatrixService($cache, $config);

            $result = $disabled->heatmap(['c1'], ['step1'], []);

            expect($result['heatmap'])->toBe([]);
            expect($result['min'])->toBe(0.0);
            expect($result['max'])->toBe(0.0);
        });
    });

    describe('velocityIndex', function (): void {
        it('computes velocity index for a cohort', function (): void {
            $steps = ['sign_up', 'verified', 'active'];
            $stepData = [
                'sign_up' => ['count' => 100, 'timestamps' => [1000]],
                'verified' => ['count' => 80, 'timestamps' => [2000]],
                'active' => ['count' => 60, 'timestamps' => [3500]],
            ];

            $result = $this->service->velocityIndex('c1', $steps, $stepData);

            expect($result)->toHaveKeys([
                'velocity_index', 'total_time_seconds', 'steps_completed',
                'total_steps', 'avg_step_time',
            ]);
            expect($result['steps_completed'])->toBe(3);
            expect($result['total_steps'])->toBe(3);
            expect($result['velocity_index'])->toBeGreaterThan(0.0);
            expect($result['velocity_index'])->toBeLessThanOrEqual(100.0);
            expect($result['avg_step_time'])->not->toBeNull();
        });

        it('returns zero velocity for disabled service', function (): void {
            $cache = mock(CacheRepository::class);
            $config = mock(ConfigRepository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.cohort_funnel_matrix', [])
                ->andReturn(['enabled' => false]);

            $disabled = new CohortFunnelMatrixService($cache, $config);

            $result = $disabled->velocityIndex('c1', ['step1'], ['step1' => ['count' => 10, 'timestamps' => []]]);

            expect($result['velocity_index'])->toBe(0.0);
            expect($result['steps_completed'])->toBe(0);
        });

        it('handles cohort with zero counts', function (): void {
            $steps = ['step1', 'step2', 'step3'];
            $stepData = [
                'step1' => ['count' => 0, 'timestamps' => []],
                'step2' => ['count' => 0, 'timestamps' => []],
                'step3' => ['count' => 0, 'timestamps' => []],
            ];

            $result = $this->service->velocityIndex('c1', $steps, $stepData);

            expect($result['steps_completed'])->toBe(0);
            expect($result['velocity_index'])->toBe(0.0);
        });
    });

    describe('stepPerformanceAnalysis', function (): void {
        it('analyzes step performance across cohorts', function (): void {
            $cohorts = ['c1', 'c2', 'c3'];
            $steps = ['sign_up', 'verified', 'active'];

            $data = [
                'c1' => [
                    'sign_up' => ['count' => 100, 'users' => [], 'timestamps' => []],
                    'verified' => ['count' => 80, 'users' => [], 'timestamps' => []],
                    'active' => ['count' => 40, 'users' => [], 'timestamps' => []],
                ],
                'c2' => [
                    'sign_up' => ['count' => 200, 'users' => [], 'timestamps' => []],
                    'verified' => ['count' => 150, 'users' => [], 'timestamps' => []],
                    'active' => ['count' => 90, 'users' => [], 'timestamps' => []],
                ],
                'c3' => [
                    'sign_up' => ['count' => 150, 'users' => [], 'timestamps' => []],
                    'verified' => ['count' => 100, 'users' => [], 'timestamps' => []],
                    'active' => ['count' => 30, 'users' => [], 'timestamps' => []],
                ],
            ];

            $result = $this->service->stepPerformanceAnalysis($cohorts, $steps, $data);

            expect($result['best_step']['step'])->toBe('sign_up');
            expect($result['best_step']['avg_rate'])->toBe(100.0);
            expect($result['worst_step']['step'])->not->toBe('');
            expect($result['most_variable']['step'])->not->toBe('');
            expect(count($result['step_summary']))->toBe(3);
        });

        it('returns empty analysis when disabled', function (): void {
            $cache = mock(CacheRepository::class);
            $config = mock(ConfigRepository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.cohort_funnel_matrix', [])
                ->andReturn(['enabled' => false]);

            $disabled = new CohortFunnelMatrixService($cache, $config);

            $result = $disabled->stepPerformanceAnalysis(['c1'], ['step1'], []);

            expect($result['step_summary'])->toBe([]);
        });
    });

    describe('dropoffRanking', function (): void {
        it('ranks funnel steps by drop-off severity', function (): void {
            $cohorts = ['c1', 'c2'];
            $steps = ['sign_up', 'verified', 'active', 'purchased'];

            $data = [
                'c1' => [
                    'sign_up' => ['count' => 100, 'users' => [], 'timestamps' => []],
                    'verified' => ['count' => 80, 'users' => [], 'timestamps' => []],
                    'active' => ['count' => 30, 'users' => [], 'timestamps' => []],
                    'purchased' => ['count' => 20, 'users' => [], 'timestamps' => []],
                ],
                'c2' => [
                    'sign_up' => ['count' => 200, 'users' => [], 'timestamps' => []],
                    'verified' => ['count' => 150, 'users' => [], 'timestamps' => []],
                    'active' => ['count' => 60, 'users' => [], 'timestamps' => []],
                    'purchased' => ['count' => 50, 'users' => [], 'timestamps' => []],
                ],
            ];

            $result = $this->service->dropoffRanking($cohorts, $steps, $data);

            expect(count($result))->toBe(3); // 4 steps - 1 = 3 transitions
            expect($result[0]['severity'])->not->toBe('');
            expect($result[0])->toHaveKeys(['step', 'avg_dropoff_count', 'avg_dropoff_rate', 'severity']);
        });

        it('returns empty for single-step funnel', function (): void {
            $result = $this->service->dropoffRanking(['c1'], ['step1'], [
                'c1' => ['step1' => ['count' => 10, 'users' => [], 'timestamps' => []]],
            ]);

            expect($result)->toBe([]);
        });

        it('classifies severity correctly', function (): void {
            $cohorts = ['c1'];
            $steps = ['step1', 'step2', 'step3', 'step4'];

            $data = [
                'c1' => [
                    'step1' => ['count' => 1000, 'users' => [], 'timestamps' => []],
                    'step2' => ['count' => 100, 'users' => [], 'timestamps' => []],   // 90% dropoff → critical
                    'step3' => ['count' => 60, 'users' => [], 'timestamps' => []],     // 40% dropoff → high
                    'step4' => ['count' => 50, 'users' => [], 'timestamps' => []],     // ~17% dropoff → medium
                ],
            ];

            $result = $this->service->dropoffRanking($cohorts, $steps, $data);

            // Should be sorted by severity (highest first)
            expect($result[0]['step'])->toBe('step2');
            expect($result[0]['severity'])->toBe('critical');
        });
    });

    describe('buildMatrixCached', function (): void {
        it('uses cache wrapper', function (): void {
            $cohorts = ['c1'];
            $steps = ['step1'];

            $data = ['c1' => ['step1' => ['count' => 10, 'users' => [], 'timestamps' => []]]];

            $result = $this->service->buildMatrixCached('test_key', $cohorts, $steps, $data);

            expect($result['cohorts'])->toBe($cohorts);
            expect($result['steps'])->toBe($steps);
        });
    });

    describe('clearCache', function (): void {
        it('returns true after clearing', function (): void {
            expect($this->service->clearCache())->toBeTrue();
        });
    });

    describe('version consistency', function (): void {
        it('service has proper namespace and class name', function (): void {
            expect(class_exists(CohortFunnelMatrixService::class))->toBeTrue();
            expect((new \ReflectionClass(CohortFunnelMatrixService::class))->getNamespaceName())
                ->toBe('ZeroBoiler\\Analytics\\Services');
        });

        it('command has proper namespace', function (): void {
            expect(class_exists(AnalyticsCohortFunnelCommand::class))->toBeTrue();
            expect((new \ReflectionClass(AnalyticsCohortFunnelCommand::class))->getNamespaceName())
                ->toBe('ZeroBoiler\\Analytics\\Console\\Commands');
        });
    });
});

describe('AnalyticsCohortFunnelCommand', function (): void {
    it('has correct signature', function (): void {
        $command = new AnalyticsCohortFunnelCommand;
        $signature = (new \ReflectionClass($command))->getProperty('signature')->getValue($command);

        expect($signature)->toContain('zb:analytics:cohort-funnel');
        expect($signature)->toContain('{action}');
        expect($signature)->toContain('--template');
        expect($signature)->toContain('--cohorts');
        expect($signature)->toContain('--steps');
        expect($signature)->toContain('--json');
    });
});
