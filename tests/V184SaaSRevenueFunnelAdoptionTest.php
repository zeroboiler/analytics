<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Services\SaaSFeatureAdoptionTracker;
use ZeroBoiler\Analytics\Services\SaaSRevenueFunnelService;

beforeEach(function (): void {
    $this->cache = mock(CacheRepository::class);
    $this->config = mock(ConfigRepository::class);
    $this->manager = mock(AnalyticsManager::class);
});

// ─── SaaSRevenueFunnelService Tests ────────────────────────────────────

describe('SaaSRevenueFunnelService', function (): void {
    describe('construction', function (): void {
        test('constructs with default config when enabled', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.revenue_funnel', [])
                ->andReturn(['enabled' => true]);

            $service = new SaaSRevenueFunnelService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            expect($service->isEnabled())->toBeTrue();
        });

        test('constructs as disabled when config says so', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.revenue_funnel', [])
                ->andReturn(['enabled' => false]);

            $service = new SaaSRevenueFunnelService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            expect($service->isEnabled())->toBeFalse();
        });
    });

    describe('getStages', function (): void {
        test('returns all 7 default funnel stages', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.revenue_funnel', [])
                ->andReturn([]);

            $service = new SaaSRevenueFunnelService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $stages = $service->getStages();
            expect($stages)->toHaveCount(7);
            expect(array_keys($stages))->toEqual([
                'visit', 'signup', 'trial_start', 'activation', 'trial_convert', 'expansion', 'renewal',
            ]);
        });
    });

    describe('recordStageEntry', function (): void {
        test('does nothing when disabled', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.revenue_funnel', [])
                ->andReturn(['enabled' => false]);

            $this->manager->shouldNotReceive('trackEvent');

            $service = new SaaSRevenueFunnelService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->recordStageEntry('user-1', 'signup');
        });

        test('tracks analytics event and updates cache counts', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.revenue_funnel', [])
                ->andReturn(['enabled' => true]);

            $this->manager->shouldReceive('trackEvent')
                ->once()
                ->andReturnNull();

            $this->cache->shouldReceive('get')->andReturn(0);
            $this->cache->shouldReceive('put')->andReturnTrue();

            $service = new SaaSRevenueFunnelService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->recordStageEntry('user-1', 'signup', ['plan' => 'pro']);
        });
    });

    describe('getSnapshot', function (): void {
        test('returns funnel snapshot with stage metrics', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.revenue_funnel', [])
                ->andReturn(['enabled' => true]);

            $this->cache->shouldReceive('get')->andReturn(0);
            $this->cache->shouldReceive('put')->andReturnTrue();

            $service = new SaaSRevenueFunnelService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $snapshot = $service->getSnapshot();

            expect($snapshot)->toHaveKey('stages');
            expect($snapshot)->toHaveKey('total_entered');
            expect($snapshot)->toHaveKey('overall_conversion');
            expect($snapshot)->toHaveKey('bottlenecks');
            expect($snapshot)->toHaveKey('computed_at');
            expect($snapshot)->toHaveKey('period');
            expect($snapshot['total_entered'])->toBe(0);
            expect($snapshot['period'])->toBe('all');
        });
    });

    describe('getConversionRate', function (): void {
        test('returns null when from stage has zero count', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.revenue_funnel', [])
                ->andReturn(['enabled' => true]);

            $this->cache->shouldReceive('get')
                ->with('zb_rev_funnel_stage_signup', 0)
                ->andReturn(0);

            $service = new SaaSRevenueFunnelService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            expect($service->getConversionRate('signup', 'trial_start'))->toBeNull();
        });

        test('returns correct conversion rate', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.revenue_funnel', [])
                ->andReturn(['enabled' => true]);

            $this->cache->shouldReceive('get')
                ->with('zb_rev_funnel_stage_signup', 0)
                ->andReturn(100);
            $this->cache->shouldReceive('get')
                ->with('zb_rev_funnel_stage_trial_start', 0)
                ->andReturn(25);

            $service = new SaaSRevenueFunnelService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            expect($service->getConversionRate('signup', 'trial_start'))->toBe(0.25);
        });
    });

    describe('detectBottlenecks', function (): void {
        test('returns empty array when no bottlenecks', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.revenue_funnel', [])
                ->andReturn(['enabled' => true]);

            // All stages have 0 count → no conversion rates → no bottlenecks
            $this->cache->shouldReceive('get')->andReturn(0);
            $this->cache->shouldReceive('put')->andReturnTrue();

            $service = new SaaSRevenueFunnelService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $bottlenecks = $service->detectBottlenecks();
            expect($bottlenecks)->toBeEmpty();
        });
    });

    describe('clearCache', function (): void {
        test('forgets all stage cache keys', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.revenue_funnel', [])
                ->andReturn(['enabled' => true]);

            // 7 stage forgets + timing forgets + snapshot forget
            $this->cache->shouldReceive('forget')->andReturnTrue();

            $service = new SaaSRevenueFunnelService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->clearCache();
        });
    });

    describe('getCohortFunnel', function (): void {
        test('returns stage metrics per cohort', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.revenue_funnel', [])
                ->andReturn(['enabled' => true]);

            $this->cache->shouldReceive('get')->andReturn(0);

            $service = new SaaSRevenueFunnelService(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $cohort = $service->getCohortFunnel('2026-W33');
            expect($cohort)->toHaveCount(7);
            expect($cohort['signup'])->toHaveKey('count');
            expect($cohort['signup'])->toHaveKey('conversion_rate');
            expect($cohort['signup'])->toHaveKey('drop_off_rate');
        });
    });
});

// ─── SaaSFeatureAdoptionTracker Tests ──────────────────────────────────

describe('SaaSFeatureAdoptionTracker', function (): void {
    describe('construction', function (): void {
        test('constructs with default config when enabled', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.feature_adoption', [])
                ->andReturn(['enabled' => true]);

            $service = new SaaSFeatureAdoptionTracker(
                $this->manager,
                $this->cache,
                $this->config,
            );

            expect($service->isEnabled())->toBeTrue();
        });

        test('constructs as disabled when config says so', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.feature_adoption', [])
                ->andReturn(['enabled' => false]);

            $service = new SaaSFeatureAdoptionTracker(
                $this->manager,
                $this->cache,
                $this->config,
            );

            expect($service->isEnabled())->toBeFalse();
        });
    });

    describe('getFeatureMetrics', function (): void {
        test('returns zero metrics when no data', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.feature_adoption', [])
                ->andReturn(['enabled' => true]);

            $this->cache->shouldReceive('get')->andReturn(0, []);

            $service = new SaaSFeatureAdoptionTracker(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $metrics = $service->getFeatureMetrics('dashboard');

            expect($metrics['adoption_rate'])->toBe(0.0);
            expect($metrics['total_adopters'])->toBe(0);
            expect($metrics['usage_frequency'])->toBe(0.0);
        });
    });

    describe('recordFeatureUse', function (): void {
        test('does nothing when disabled', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.feature_adoption', [])
                ->andReturn(['enabled' => false]);

            $this->manager->shouldNotReceive('trackEvent');

            $service = new SaaSFeatureAdoptionTracker(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->recordFeatureUse('dashboard', 'user-1');
        });

        test('does nothing for feature names exceeding 100 chars', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.feature_adoption', [])
                ->andReturn(['enabled' => true]);

            $this->manager->shouldNotReceive('trackEvent');

            $service = new SaaSFeatureAdoptionTracker(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->recordFeatureUse(str_repeat('x', 101), 'user-1');
        });

        test('tracks event and updates cache for valid feature', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.feature_adoption', [])
                ->andReturn(['enabled' => true]);

            $this->manager->shouldReceive('trackEvent')->once()->andReturnNull();
            $this->cache->shouldReceive('get')->andReturn(0, [], []);
            $this->cache->shouldReceive('put')->andReturnTrue();

            $service = new SaaSFeatureAdoptionTracker(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->recordFeatureUse('api_keys', 'user-1', ['plan' => 'enterprise']);
        });
    });

    describe('getAdoptionSnapshot', function (): void {
        test('returns snapshot with computed_at and period', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.feature_adoption', [])
                ->andReturn(['enabled' => true]);

            $this->cache->shouldReceive('get')
                ->with('zb_feature_adoption_snapshot_all')
                ->andReturn(null);
            $this->cache->shouldReceive('get')
                ->with('zb_feature_adoption_feature_list')
                ->andReturn([]);
            $this->cache->shouldReceive('put')->andReturnTrue();

            $service = new SaaSFeatureAdoptionTracker(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $snapshot = $service->getAdoptionSnapshot();

            expect($snapshot)->toHaveKey('features');
            expect($snapshot)->toHaveKey('computed_at');
            expect($snapshot)->toHaveKey('period');
            expect($snapshot['period'])->toBe('all');
        });
    });

    describe('getTopFeatures', function (): void {
        test('returns empty array when no features tracked', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.feature_adoption', [])
                ->andReturn(['enabled' => true]);

            $this->cache->shouldReceive('get')->andReturn(null, [], 0, []);
            $this->cache->shouldReceive('put')->andReturnTrue();

            $service = new SaaSFeatureAdoptionTracker(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $top = $service->getTopFeatures(5);
            expect($top)->toBeEmpty();
        });
    });

    describe('getLowAdoptionFeatures', function (): void {
        test('returns features below threshold', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.feature_adoption', [])
                ->andReturn(['enabled' => true]);

            $this->cache->shouldReceive('get')->andReturn(null, [], 0, []);
            $this->cache->shouldReceive('put')->andReturnTrue();

            $service = new SaaSFeatureAdoptionTracker(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $low = $service->getLowAdoptionFeatures(0.10);
            expect($low)->toBeEmpty();
        });
    });

    describe('clearFeatureCache', function (): void {
        test('forgets all cache keys for a feature', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.feature_adoption', [])
                ->andReturn(['enabled' => true, 'stickiness_windows' => [7, 14, 30]]);

            $this->cache->shouldReceive('forget')->andReturnTrue();

            $service = new SaaSFeatureAdoptionTracker(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $service->clearFeatureCache('dashboard');
        });
    });

    describe('compareCohorts', function (): void {
        test('returns comparison when no features tracked', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.feature_adoption', [])
                ->andReturn(['enabled' => true]);

            $this->cache->shouldReceive('get')
                ->with('zb_feature_adoption_feature_list')
                ->andReturn([]);

            $service = new SaaSFeatureAdoptionTracker(
                $this->manager,
                $this->cache,
                $this->config,
            );

            $comparison = $service->compareCohorts('2026-W33', '2026-W32');
            expect($comparison)->toBeEmpty();
        });
    });
});

// ─── Class Finality + Construction Audit ────────────────────────────────

describe('Phase 184 Production Readiness', function (): void {
    test('SaaSRevenueFunnelService is final', function (): void {
        $reflection = new ReflectionClass(SaaSRevenueFunnelService::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    test('SaaSFeatureAdoptionTracker is final', function (): void {
        $reflection = new ReflectionClass(SaaSFeatureAdoptionTracker::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    test('SaaSRevenueFunnelService constructor has void return type', function (): void {
        $constructor = new ReflectionMethod(SaaSRevenueFunnelService::class, '__construct');
        expect($constructor->getReturnType()?->getName())->toBe('void');
    });

    test('SaaSFeatureAdoptionTracker constructor has void return type', function (): void {
        $constructor = new ReflectionMethod(SaaSFeatureAdoptionTracker::class, '__construct');
        expect($constructor->getReturnType()?->getName())->toBe('void');
    });

    test('SaaSRevenueFunnelService has declare(strict_types=1)', function (): void {
        $contents = file_get_contents((string) realpath(__DIR__ . '/../src/Services/SaaSRevenueFunnelService.php'));
        expect($contents)->toContain('declare(strict_types=1)');
    });

    test('SaaSFeatureAdoptionTracker has declare(strict_types=1)', function (): void {
        $contents = file_get_contents((string) realpath(__DIR__ . '/../src/Services/SaaSFeatureAdoptionTracker.php'));
        expect($contents)->toContain('declare(strict_types=1)');
    });

    test('SaaSRevenueFunnelService has MIT license header', function (): void {
        $contents = file_get_contents((string) realpath(__DIR__ . '/../src/Services/SaaSRevenueFunnelService.php'));
        expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
    });

    test('SaaSFeatureAdoptionTracker has MIT license header', function (): void {
        $contents = file_get_contents((string) realpath(__DIR__ . '/../src/Services/SaaSFeatureAdoptionTracker.php'));
        expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
    });

    test('SaaSRevenueFunnelService public methods have return type declarations', function (): void {
        $reflection = new ReflectionClass(SaaSRevenueFunnelService::class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        $noReturnType = [];

        foreach ($methods as $method) {
            if ($method->getName() === '__construct') {
                continue;
            }
            if ($method->getReturnType() === null) {
                $noReturnType[] = $method->getName();
            }
        }

        expect($noReturnType)->toBeEmpty();
    });

    test('SaaSFeatureAdoptionTracker public methods have return type declarations', function (): void {
        $reflection = new ReflectionClass(SaaSFeatureAdoptionTracker::class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        $noReturnType = [];

        foreach ($methods as $method) {
            if ($method->getName() === '__construct') {
                continue;
            }
            if ($method->getReturnType() === null) {
                $noReturnType[] = $method->getName();
            }
        }

        expect($noReturnType)->toBeEmpty();
    });

    test('source file counts: 845+ src, 427+ tests', function (): void {
        $srcFiles = glob(__DIR__ . '/../src/**/*.php', GLOB_BRACE);
        $testFiles = glob(__DIR__ . '/*.php');
        $subTests = glob(__DIR__ . '/**/*.php', GLOB_BRACE);

        expect(count($srcFiles))->toBeGreaterThanOrEqual(845);
        expect(count($testFiles) + count($subTests))->toBeGreaterThanOrEqual(427);
    });
});
