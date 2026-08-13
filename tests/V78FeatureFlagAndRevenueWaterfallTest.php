<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\Events\SaaS\FeatureFlagEvaluatedEvent;
use ZeroBoiler\Analytics\Events\SaaS\GrowthMilestoneEvent;
use ZeroBoiler\Analytics\Events\SaaS\MrrMovementEvent;
use ZeroBoiler\Analytics\Services\FeatureFlagAnalyticsService;
use ZeroBoiler\Analytics\Services\RevenueWaterfallService;

beforeEach(function (): void {
    $this->cache = Cache::store('array');
});

describe('FeatureFlagEvaluatedEvent', function (): void {
    test('constructs with required parameters', function (): void {
        $event = new FeatureFlagEvaluatedEvent(
            flagKey: 'new_dashboard_v2',
            variant: 'treatment',
        );

        expect($event->name)->toBe('feature_flag_evaluated')
            ->and($event->params)->toHaveKey('flag_key')
            ->and($event->params['flag_key'])->toBe('new_dashboard_v2')
            ->and($event->params['variant'])->toBe('treatment');
    });

    test('includes optional parameters', function (): void {
        $event = new FeatureFlagEvaluatedEvent(
            flagKey: 'ab_test_1',
            variant: 'control',
            isFirstExposure: true,
            evaluationReason: 'page_load',
            experimentId: 'exp_123',
            flagType: 'boolean',
        );

        expect($event->params)
            ->toHaveKey('is_first_exposure')->and($event->params['is_first_exposure'])->toBeTrue()
            ->toHaveKey('evaluation_reason')->and($event->params['evaluation_reason'])->toBe('page_load')
            ->toHaveKey('experiment_id')->and($event->params['experiment_id'])->toBe('exp_123')
            ->toHaveKey('flag_type')->and($event->params['flag_type'])->toBe('boolean');
    });

    test('filters out null parameters', function (): void {
        $event = new FeatureFlagEvaluatedEvent(
            flagKey: 'flag_1',
            variant: 'on',
            evaluationReason: null,
            experimentId: null,
            flagType: null,
        );

        expect($event->params)->not->toHaveKeys(['evaluation_reason', 'experiment_id', 'flag_type']);
    });

    test('is final readonly', function (): void {
        $ref = new ReflectionClass(FeatureFlagEvaluatedEvent::class);

        expect($ref->isFinal())->toBeTrue()
            ->and($ref->isReadOnly())->toBeTrue();
    });
});

describe('GrowthMilestoneEvent', function (): void {
    test('constructs with required parameters', function (): void {
        $event = new GrowthMilestoneEvent(
            milestoneType: 'activation',
            milestoneName: 'Sent 100 messages',
        );

        expect($event->name)->toBe('growth_milestone')
            ->and($event->params['milestone_type'])->toBe('activation')
            ->and($event->params['milestone_name'])->toBe('Sent 100 messages');
    });

    test('includes optional parameters', function (): void {
        $event = new GrowthMilestoneEvent(
            milestoneType: 'revenue_tier',
            milestoneName: 'Reached $10k MRR',
            milestoneValue: 10000,
            daysSinceSignup: 45,
            previousMilestone: 'Reached $5k MRR',
        );

        expect($event->params)
            ->toHaveKey('milestone_value')->and($event->params['milestone_value'])->toBe(10000)
            ->toHaveKey('days_since_signup')->and($event->params['days_since_signup'])->toBe(45)
            ->toHaveKey('previous_milestone')->and($event->params['previous_milestone'])->toBe('Reached $5k MRR');
    });

    test('is final readonly', function (): void {
        $ref = new ReflectionClass(GrowthMilestoneEvent::class);

        expect($ref->isFinal())->toBeTrue()
            ->and($ref->isReadOnly())->toBeTrue();
    });
});

describe('MrrMovementEvent', function (): void {
    test('constructs with required parameters', function (): void {
        $event = new MrrMovementEvent(
            movementType: 'new',
            amount: 49.00,
        );

        expect($event->name)->toBe('mrr_movement')
            ->and($event->params['movement_type'])->toBe('new')
            ->and($event->params['amount'])->toBe(49.0);
    });

    test('includes all optional parameters', function (): void {
        $event = new MrrMovementEvent(
            movementType: 'expansion',
            amount: 20.00,
            customerId: 'cust_123',
            planId: 'plan_pro',
            previousPlanId: 'plan_starter',
            currency: 'EUR',
            billingCycle: 'monthly',
            reason: 'auto_upgrade',
            effectiveDate: '2026-09-01',
        );

        expect($event->params)
            ->toHaveKey('customer_id')->and($event->params['customer_id'])->toBe('cust_123')
            ->toHaveKey('plan_id')->and($event->params['plan_id'])->toBe('plan_pro')
            ->toHaveKey('previous_plan_id')->and($event->params['previous_plan_id'])->toBe('plan_starter')
            ->toHaveKey('currency')->and($event->params['currency'])->toBe('EUR')
            ->toHaveKey('billing_cycle')->and($event->params['billing_cycle'])->toBe('monthly')
            ->toHaveKey('reason')->and($event->params['reason'])->toBe('auto_upgrade')
            ->toHaveKey('effective_date')->and($event->params['effective_date'])->toBe('2026-09-01');
    });

    test('filters null optional parameters', function (): void {
        $event = new MrrMovementEvent(
            movementType: 'churn',
            amount: 99.00,
            customerId: null,
            planId: null,
            previousPlanId: null,
            currency: null,
            billingCycle: null,
            reason: null,
            effectiveDate: null,
        );

        expect($event->params)->not->toHaveKeys([
            'customer_id',
            'plan_id',
            'previous_plan_id',
            'currency',
            'billing_cycle',
            'reason',
            'effective_date',
        ]);
    });

    test('is final readonly', function (): void {
        $ref = new ReflectionClass(MrrMovementEvent::class);

        expect($ref->isFinal())->toBeTrue()
            ->and($ref->isReadOnly())->toBeTrue();
    });
});

describe('FeatureFlagAnalyticsService', function (): void {
    test('constructs with dependencies', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.feature_flags', [])
            ->andReturn([]);

        $manager = mock(ZeroBoiler\Analytics\AnalyticsManager::class);

        $service = new FeatureFlagAnalyticsService($this->cache, $manager, $config);

        expect($service)->toBeInstanceOf(FeatureFlagAnalyticsService::class);
    });

    test('is final class', function (): void {
        $ref = new ReflectionClass(FeatureFlagAnalyticsService::class);
        expect($ref->isFinal())->toBeTrue();
    });

    test('allFlags returns empty when no exposures tracked', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.feature_flags', [])
            ->andReturn([]);

        $manager = mock(ZeroBoiler\Analytics\AnalyticsManager::class);

        $service = new FeatureFlagAnalyticsService($this->cache, $manager, $config);
        $result = $service->allFlags();

        expect($result)->toBe([]);
    });

    test('normalizeVariant maps boolean-like values', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.feature_flags', [])
            ->andReturn([]);

        $manager = mock(ZeroBoiler\Analytics\AnalyticsManager::class);
        $manager->shouldReceive('trackEvent');

        $service = new FeatureFlagAnalyticsService($this->cache, $manager, $config);

        // Use reflection to test private method
        $method = new ReflectionMethod($service, 'normalizeVariant');
        $method->setAccessible(true);

        expect($method->invoke($service, 'control'))->toBe('control')
            ->and($method->invoke($service, 'off'))->toBe('control')
            ->and($method->invoke($service, 'false'))->toBe('control')
            ->and($method->invoke($service, 'treatment'))->toBe('treatment')
            ->and($method->invoke($service, 'on'))->toBe('treatment')
            ->and($method->invoke($service, 'enabled'))->toBe('treatment')
            ->and($method->invoke($service, 'variant_a'))->toBe('treatment')
            ->and($method->invoke($service, 'custom_name'))->toBe('on');
    });
});

describe('RevenueWaterfallService', function (): void {
    test('constructs with dependencies', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.revenue_waterfall', [])
            ->andReturn([]);

        $manager = mock(ZeroBoiler\Analytics\AnalyticsManager::class);

        $service = new RevenueWaterfallService($this->cache, $manager, $config);

        expect($service)->toBeInstanceOf(RevenueWaterfallService::class);
    });

    test('is final class', function (): void {
        $ref = new ReflectionClass(RevenueWaterfallService::class);
        expect($ref->isFinal())->toBeTrue();
    });

    test('movementSummary returns all types with zero values initially', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.revenue_waterfall', [])
            ->andReturn([]);

        $manager = mock(ZeroBoiler\Analytics\AnalyticsManager::class);

        $service = new RevenueWaterfallService($this->cache, $manager, $config);
        $summary = $service->movementSummary();

        expect($summary)->toHaveKeys(['new', 'expansion', 'contraction', 'reactivation', 'churn']);
        foreach (['new', 'expansion', 'contraction', 'reactivation', 'churn'] as $type) {
            expect($summary[$type]['count'])->toBe(0)
                ->and($summary[$type]['amount'])->toBe(0.0)
                ->and($summary[$type]['avg_deal_size'])->toBe(0.0);
        }
    });

    test('recordMovement throws on invalid type', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.revenue_waterfall', [])
            ->andReturn([]);

        $manager = mock(ZeroBoiler\Analytics\AnalyticsManager::class);

        $service = new RevenueWaterfallService($this->cache, $manager, $config);

        $service->recordMovement('invalid_type', 100.0);
    })->throws(\InvalidArgumentException::class, 'Invalid MRR movement type: invalid_type');

    test('recordMovement accepts all valid types', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.revenue_waterfall', [])
            ->andReturn([]);

        $manager = mock(ZeroBoiler\Analytics\AnalyticsManager::class);
        $manager->shouldReceive('trackEvent');

        $service = new RevenueWaterfallService($this->cache, $manager, $config);

        foreach (['new', 'expansion', 'contraction', 'reactivation', 'churn'] as $type) {
            $service->recordMovement($type, 50.0);
        }

        $summary = $service->movementSummary();
        foreach (['new', 'expansion', 'contraction', 'reactivation', 'churn'] as $type) {
            expect($summary[$type]['count'])->toBe(1)
                ->and($summary[$type]['amount'])->toBe(50.0)
                ->and($summary[$type]['avg_deal_size'])->toBe(50.0);
        }
    });

    test('clearCache does not throw', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.revenue_waterfall', [])
            ->andReturn([]);

        $manager = mock(ZeroBoiler\Analytics\AnalyticsManager::class);

        $service = new RevenueWaterfallService($this->cache, $manager, $config);
        $service->clearCache();

        expect(true)->toBeTrue();
    });
});
