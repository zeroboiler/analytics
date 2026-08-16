<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\MrrMovementEvent;
use ZeroBoiler\Analytics\Events\SaaS\FeatureFlagEvaluatedEvent;
use ZeroBoiler\Analytics\Events\SaaS\GrowthMilestoneEvent;
use ZeroBoiler\Analytics\Services\RevenueWaterfallService;
use ZeroBoiler\Analytics\Services\FeatureFlagAnalyticsService;
use ZeroBoiler\Analytics\Services\SaaSGrowthMetricsService;

beforeEach(function (): void {
    $this->cache = mock(CacheRepository::class);
    $this->config = mock(ConfigRepository::class);
    $this->manager = mock(AnalyticsManager::class);

    $this->cache->shouldReceive('get')->andReturn([]);
    $this->cache->shouldReceive('forget')->andReturn(true);
    $this->cache->shouldReceive('put')->andReturn(true);
    $this->manager->shouldReceive('trackEvent');
    $this->manager->shouldReceive('track');

    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.revenue_waterfall', [])
        ->andReturn(['cache_ttl' => 300, 'currency' => 'USD']);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.feature_flags', [])
        ->andReturn(['cache_ttl' => 300]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.growth_metrics', [])
        ->andReturn(['cache_ttl' => 3600, 'activation_events' => []]);
});

// ─── Event Classes ───────────────────────────────────────────────────

describe('MrrMovementEvent', function (): void {
    it('creates a valid new MRR movement event', function (): void {
        $event = new MrrMovementEvent(
            movementType: 'new',
            amount: 49.99,
            customerId: 'cust_123',
            planId: 'pro',
        );

        expect($event->name)->toBe('mrr_movement');
        expect($event->params['movement_type'])->toBe('new');
        expect($event->params['amount'])->toBe(49.99);
        expect($event->params['customer_id'])->toBe('cust_123');
        expect($event->params['plan_id'])->toBe('pro');
    });

    it('creates a churn movement event with currency', function (): void {
        $event = new MrrMovementEvent(
            movementType: 'churn',
            amount: 29.00,
            currency: 'EUR',
            reason: 'customer_requested',
        );

        expect($event->name)->toBe('mrr_movement');
        expect($event->params['movement_type'])->toBe('churn');
        expect($event->params['amount'])->toBe(29.00);
        expect($event->params['currency'])->toBe('EUR');
        expect($event->params['reason'])->toBe('customer_requested');
    });

    it('creates expansion with previous plan context', function (): void {
        $event = new MrrMovementEvent(
            movementType: 'expansion',
            amount: 20.00,
            planId: 'enterprise',
            previousPlanId: 'pro',
        );

        expect($event->params['movement_type'])->toBe('expansion');
        expect($event->params['plan_id'])->toBe('enterprise');
        expect($event->params['previous_plan_id'])->toBe('pro');
    });

    it('creates reactivation movement', function (): void {
        $event = new MrrMovementEvent(
            movementType: 'reactivation',
            amount: 19.00,
            customerId: 'cust_456',
        );

        expect($event->params['movement_type'])->toBe('reactivation');
        expect($event->params['customer_id'])->toBe('cust_456');
    });
});

describe('FeatureFlagEvaluatedEvent', function (): void {
    it('creates a feature flag evaluation event', function (): void {
        $event = new FeatureFlagEvaluatedEvent(
            flagKey: 'new_dashboard_v2',
            variant: 'treatment',
        );

        expect($event->name)->toBe('feature_flag_evaluated');
        expect($event->params['flag_key'])->toBe('new_dashboard_v2');
        expect($event->params['variant'])->toBe('treatment');
        expect($event->params['is_first_exposure'])->toBe(false);
    });

    it('creates a first-exposure evaluation event', function (): void {
        $event = new FeatureFlagEvaluatedEvent(
            flagKey: 'new_dashboard_v2',
            variant: 'treatment',
            isFirstExposure: true,
            evaluationReason: 'page_load',
        );

        expect($event->params['is_first_exposure'])->toBe(true);
        expect($event->params['evaluation_reason'])->toBe('page_load');
    });

    it('creates evaluation with experiment context', function (): void {
        $event = new FeatureFlagEvaluatedEvent(
            flagKey: 'checkout_flow_v3',
            variant: 'control',
            experimentId: 'exp_789',
            flagType: 'multivariate',
        );

        expect($event->params['experiment_id'])->toBe('exp_789');
        expect($event->params['flag_type'])->toBe('multivariate');
    });
});

describe('GrowthMilestoneEvent', function (): void {
    it('creates a growth milestone event', function (): void {
        $event = new GrowthMilestoneEvent(
            milestoneType: 'activation',
            milestoneName: 'Completed first project',
        );

        expect($event->name)->toBe('growth_milestone');
        expect($event->params['milestone_type'])->toBe('activation');
        expect($event->params['milestone_name'])->toBe('Completed first project');
    });

    it('creates milestone with value and time context', function (): void {
        $event = new GrowthMilestoneEvent(
            milestoneType: 'power_user',
            milestoneName: 'Sent 1000 messages',
            milestoneValue: 1000,
            daysSinceSignup: 14,
        );

        expect($event->params['milestone_value'])->toBe(1000);
        expect($event->params['days_since_signup'])->toBe(14);
    });

    it('creates revenue tier milestone', function (): void {
        $event = new GrowthMilestoneEvent(
            milestoneType: 'revenue_tier',
            milestoneName: 'Reached $10k MRR',
            milestoneValue: 10000,
            previousMilestone: 'Reached $5k MRR',
        );

        expect($event->params['milestone_type'])->toBe('revenue_tier');
        expect($event->params['previous_milestone'])->toBe('Reached $5k MRR');
    });
});

// ─── Event Catalog ──────────────────────────────────────────────────

describe('Event Catalog — v78.0.0 entries', function (): void {
    it('has mrr_movement in SaaS catalog', function (): void {
        expect(EventCatalog::has('mrr_movement'))->toBeTrue();
        $entry = EventCatalog::get('mrr_movement');
        expect($entry)->not->toBeNull();
        expect($entry['name'])->toBe('mrr_movement');
        expect($entry['category'])->toBe('saas');
        expect($entry['ga4'])->toBe('mrr_movement');
    });

    it('has feature_flag_evaluated in SaaS catalog', function (): void {
        expect(EventCatalog::has('feature_flag_evaluated'))->toBeTrue();
        $entry = EventCatalog::get('feature_flag_evaluated');
        expect($entry)->not->toBeNull();
        expect($entry['posthog'])->toBe('$feature_flag');
    });

    it('has growth_milestone in SaaS catalog', function (): void {
        expect(EventCatalog::has('growth_milestone'))->toBeTrue();
        $entry = EventCatalog::get('growth_milestone');
        expect($entry)->not->toBeNull();
        expect($entry['mixpanel'])->toBe('Growth Milestone');
    });

    it('all new events have correct classes', function (): void {
        expect(EventCatalog::get('mrr_movement')['class'])->toBe(MrrMovementEvent::class);
        expect(EventCatalog::get('feature_flag_evaluated')['class'])->toBe(FeatureFlagEvaluatedEvent::class);
        expect(EventCatalog::get('growth_milestone')['class'])->toBe(GrowthMilestoneEvent::class);
    });
});

// ─── RevenueWaterfallService ─────────────────────────────────────────

describe('RevenueWaterfallService', function (): void {
    it('records an MRR movement and dispatches event', function (): void {
        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->withArgs(fn (MrrMovementEvent $e): bool => $e->params['movement_type'] === 'new' && $e->params['amount'] === 49.99);

        $service = new RevenueWaterfallService($this->cache, $this->manager, $this->config);
        $service->recordMovement('new', 49.99, ['customer_id' => 'cust_1']);
    });

    it('throws on invalid movement type', function (): void {
        $service = new RevenueWaterfallService($this->cache, $this->manager, $this->config);

        expect(fn (): mixed => $service->recordMovement('invalid_type', 10.0))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('returns movement summary with correct structure', function (): void {
        $service = new RevenueWaterfallService($this->cache, $this->manager, $this->config);
        $summary = $service->movementSummary();

        expect($summary)->toHaveKeys(['new', 'expansion', 'contraction', 'reactivation', 'churn']);
        foreach (['new', 'expansion', 'contraction', 'reactivation', 'churn'] as $type) {
            expect($summary[$type])->toHaveKeys(['count', 'amount', 'avg_deal_size']);
        }
    });

    it('returns waterfall data with correct structure', function (): void {
        $service = new RevenueWaterfallService($this->cache, $this->manager, $this->config);
        $waterfall = $service->waterfall('current_month');

        expect($waterfall)->toHaveKeys([
            'starting_mrr', 'new', 'expansion', 'contraction',
            'reactivation', 'churn', 'net_change', 'ending_mrr',
            'growth_rate', 'currency', 'period',
        ]);
        expect($waterfall['currency'])->toBe('USD');
        expect($waterfall['period'])->toBe('current_month');
    });

    it('returns MRR trend data', function (): void {
        $service = new RevenueWaterfallService($this->cache, $this->manager, $this->config);
        $trend = $service->mrrTrend(6);

        expect($trend)->toBeArray();
        expect(count($trend))->toBe(6);
        foreach ($trend as $point) {
            expect($point)->toHaveKeys(['period', 'starting_mrr', 'ending_mrr', 'net_change']);
        }
    });

    it('returns net MRR retention rate', function (): void {
        $service = new RevenueWaterfallService($this->cache, $this->manager, $this->config);
        $retention = $service->netMrrRetentionRate('current_month');

        expect($retention)->toHaveKeys(['rate', 'starting_mrr', 'net_mrr', 'period']);
    });
});

// ─── FeatureFlagAnalyticsService ───────────────────────────────────

describe('FeatureFlagAnalyticsService', function (): void {
    it('tracks feature flag evaluation and dispatches event', function (): void {
        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->withArgs(fn (FeatureFlagEvaluatedEvent $e): bool => $e->params['flag_key'] === 'test_flag');

        $service = new FeatureFlagAnalyticsService($this->cache, $this->manager, $this->config);
        $service->trackEvaluation('test_flag', 'treatment');
    });

    it('tracks conversion attributed to a variant', function (): void {
        $this->manager->shouldReceive('track')
            ->once()
            ->withArgs(function (string $name, array $params): bool {
                return $name === 'feature_flag_conversion'
                    && $params['flag_key'] === 'ab_test_1'
                    && $params['variant'] === 'control';
            });

        $service = new FeatureFlagAnalyticsService($this->cache, $this->manager, $this->config);
        $service->trackConversion('ab_test_1', 'control', 'purchase', ['value' => 49.99]);
    });

    it('returns all flags with zero evaluations initially', function (): void {
        $service = new FeatureFlagAnalyticsService($this->cache, $this->manager, $this->config);
        $flags = $service->allFlags();

        expect($flags)->toBeArray();
    });

    it('returns variant distribution', function (): void {
        $service = new FeatureFlagAnalyticsService($this->cache, $this->manager, $this->config);
        $dist = $service->variantDistribution('test_flag');

        expect($dist)->toHaveKeys(['flag_key', 'total_exposures', 'variants']);
        expect($dist['flag_key'])->toBe('test_flag');
    });

    it('returns conversion rates', function (): void {
        $service = new FeatureFlagAnalyticsService($this->cache, $this->manager, $this->config);
        $rates = $service->conversionRates('test_flag');

        expect($rates)->toHaveKeys(['flag_key', 'variants']);
    });

    it('returns adoption summary', function (): void {
        $service = new FeatureFlagAnalyticsService($this->cache, $this->manager, $this->config);
        $adoption = $service->adoptionSummary();

        expect($adoption)->toBeArray();
    });
});

// ─── SaaSGrowthMetricsService ───────────────────────────────────────

describe('SaaSGrowthMetricsService', function (): void {
    it('tracks a growth milestone and dispatches event', function (): void {
        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->withArgs(fn (GrowthMilestoneEvent $e): bool => $e->params['milestone_type'] === 'activation');

        $service = new SaaSGrowthMetricsService($this->cache, $this->manager, $this->config);
        $service->trackMilestone('activation', 'Completed first project');
    });

    it('throws on invalid milestone type', function (): void {
        $service = new SaaSGrowthMetricsService($this->cache, $this->manager, $this->config);

        expect(fn (): mixed => $service->trackMilestone('invalid_type', 'Something'))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('returns activation rate with correct structure', function (): void {
        $service = new SaaSGrowthMetricsService($this->cache, $this->manager, $this->config);
        $rate = $service->activationRate('last_30_days');

        expect($rate)->toHaveKeys(['rate', 'activated_count', 'total_signups', 'period', 'activation_events']);
    });

    it('returns stickiness rate with correct structure', function (): void {
        $service = new SaaSGrowthMetricsService($this->cache, $this->manager, $this->config);
        $stickiness = $service->stickinessRate('last_30_days');

        expect($stickiness)->toHaveKeys(['stickiness', 'dau', 'mau', 'period']);
    });

    it('returns virality coefficient with correct structure', function (): void {
        $service = new SaaSGrowthMetricsService($this->cache, $this->manager, $this->config);
        $virality = $service->viralityCoefficient();

        expect($virality)->toHaveKeys(['k_factor', 'invites_per_user', 'invite_conversion_rate', 'total_invites', 'total_conversions']);
    });

    it('returns retention curve with correct structure', function (): void {
        $service = new SaaSGrowthMetricsService($this->cache, $this->manager, $this->config);
        $retention = $service->retentionCurve();

        expect($retention)->toHaveKeys(['cohort_size', 'day_1', 'day_3', 'day_7', 'day_14', 'day_30']);
    });

    it('returns growth milestones', function (): void {
        $service = new SaaSGrowthMetricsService($this->cache, $this->manager, $this->config);
        $milestones = $service->milestones();

        expect($milestones)->toBeArray();
    });

    it('returns dashboard summary with all sections', function (): void {
        $service = new SaaSGrowthMetricsService($this->cache, $this->manager, $this->config);
        $dashboard = $service->dashboardSummary();

        expect($dashboard)->toHaveKeys(['activation', 'stickiness', 'virality', 'retention', 'milestones_count']);
        expect($dashboard['activation'])->toHaveKey('rate');
        expect($dashboard['stickiness'])->toHaveKey('rate');
        expect($dashboard['virality'])->toHaveKey('k_factor');
    });
});

// ─── Version Consistency ───────────────────────────────────────────

describe('Version consistency v78.0.0', function (): void {
    it('has correct version in AnalyticsEvent', function (): void {
        expect(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION)->toBe('78.0.0');
    });

    it('has 3 new events in SaaS catalog', function (): void {
        $saasEvents = \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::all();
        expect(isset($saasEvents['mrr_movement']))->toBeTrue();
        expect(isset($saasEvents['feature_flag_evaluated']))->toBeTrue();
        expect(isset($saasEvents['growth_milestone']))->toBeTrue();
    });

    it('new events are included in global catalog', function (): void {
        $all = EventCatalog::all();
        expect(isset($all['mrr_movement']))->toBeTrue();
        expect(isset($all['feature_flag_evaluated']))->toBeTrue();
        expect(isset($all['growth_milestone']))->toBeTrue();
    });
});
