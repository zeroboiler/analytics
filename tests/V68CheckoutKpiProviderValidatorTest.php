<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\CheckoutFlowTracker;
use ZeroBoiler\Analytics\Services\ProviderEventValidator;
use ZeroBoiler\Analytics\Services\SaaSKpiCalculatorService;

beforeEach(function (): void {
    $this->cache = Mockery::mock(Illuminate\Contracts\Cache\Repository::class);
    $this->config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
    $this->manager = Mockery::mock(AnalyticsManager::class);

    // Default config returns
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.checkout_tracking', [])
        ->andReturn(['enabled' => true, 'cache_ttl' => 86400, 'currency' => 'USD']);

    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.saas_kpi_calc', [])
        ->andReturn([
            'enabled' => true,
            'cache_ttl' => 300,
            'mrr_goal' => 10000,
            'churn_warning' => 0.05,
            'ltv_cac_target' => 3.0,
            'quick_ratio_target' => 4.0,
            'rule_of_40_target' => 40.0,
        ]);

    $this->manager->shouldReceive('trackEvent')
        ->andReturnNull();
});

// ── CheckoutFlowTracker Tests ──────────────────────────────────

describe('CheckoutFlowTracker', function (): void {
    it('starts a checkout flow and returns initial state', function (): void {
        $tracker = new CheckoutFlowTracker($this->manager, $this->cache, $this->config);

        $this->cache->shouldReceive('put')
            ->once()
            ->withArgs(function (string $key, array $state, int $ttl): bool {
                expect($key)->toStartWith('zb_checkout_');
                expect($state['step'])->toBe('cart_review');
                expect($state['step_index'])->toBe(1);
                expect($state['items'])->toBeArray();
                expect($state['completed'])->toBeFalse();
                expect($ttl)->toBe(86400);

                return true;
            });

        $items = [
            ['item_id' => 'prod-1', 'item_name' => 'Widget', 'price' => 29.99, 'quantity' => 2],
            ['item_id' => 'prod-2', 'item_name' => 'Gadget', 'price' => 9.99, 'quantity' => 1],
        ];

        $result = $tracker->startCheckout('client-uuid', $items, 'USD', 'SAVE10');

        expect($result['step'])->toBe('cart_review');
        expect($result['step_index'])->toBe(1);
        expect($result['value'])->toBe(69.97); // 29.99*2 + 9.99
        expect($result['item_count'])->toBe(2);
        expect($result['checkout_id'])->toStartWith('cko_');
    });

    it('advances checkout to next step', function (): void {
        $tracker = new CheckoutFlowTracker($this->manager, $this->cache, $this->config);

        $existingState = [
            'checkout_id' => 'cko_test123',
            'step' => 'cart_review',
            'step_index' => 1,
            'started_at' => time() - 120,
            'steps' => [
                ['step' => 'cart_review', 'timestamp' => time() - 120],
            ],
            'value' => 29.99,
            'item_count' => 1,
            'currency' => 'USD',
            'items' => [['item_id' => 'prod-1', 'price' => 29.99, 'quantity' => 1]],
            'coupon' => null,
            'completed' => false,
        ];

        $this->cache->shouldReceive('get')
            ->with('zb_checkout_client-uuid')
            ->andReturn($existingState);

        $this->cache->shouldReceive('put')
            ->once()
            ->withArgs(function (string $key, array $state, int $ttl): bool {
                expect($state['step'])->toBe('shipping_info');
                expect($state['step_index'])->toBe(2);
                expect(count($state['steps']))->toBe(2);
                expect($state['completed'])->toBeFalse();

                return true;
            });

        $result = $tracker->advanceStep('client-uuid', 'shipping_info', [
            'shipping_method' => 'express',
        ]);

        expect($result)->not->toBeNull();
        expect($result['step'])->toBe('shipping_info');
        expect($result['previous_step'])->toBe('cart_review');
        expect($result['time_on_previous'])->toBeGreaterThanOrEqual(119);
    });

    it('rejects advancing to a step that is not after current', function (): void {
        $tracker = new CheckoutFlowTracker($this->manager, $this->cache, $this->config);

        $existingState = [
            'checkout_id' => 'cko_test123',
            'step' => 'shipping_info',
            'step_index' => 2,
            'started_at' => time(),
            'steps' => [
                ['step' => 'cart_review', 'timestamp' => time()],
                ['step' => 'shipping_info', 'timestamp' => time()],
            ],
            'value' => 29.99,
            'item_count' => 1,
            'currency' => 'USD',
            'items' => [],
            'coupon' => null,
            'completed' => false,
        ];

        $this->cache->shouldReceive('get')
            ->with('zb_checkout_client-uuid')
            ->andReturn($existingState);

        // Try to go back to cart_review — should be rejected
        $result = $tracker->advanceStep('client-uuid', 'cart_review');

        expect($result)->toBeNull();
    });

    it('completes checkout and fires purchase event', function (): void {
        $tracker = new CheckoutFlowTracker($this->manager, $this->cache, $this->config);

        $existingState = [
            'checkout_id' => 'cko_test123',
            'step' => 'payment_info',
            'step_index' => 3,
            'started_at' => time() - 600,
            'steps' => [
                ['step' => 'cart_review', 'timestamp' => time() - 600],
                ['step' => 'shipping_info', 'timestamp' => time() - 480],
                ['step' => 'payment_info', 'timestamp' => time() - 120],
            ],
            'value' => 59.98,
            'item_count' => 2,
            'currency' => 'EUR',
            'items' => [
                ['item_id' => 'prod-1', 'item_name' => 'Widget', 'price' => 29.99, 'quantity' => 2],
            ],
            'coupon' => null,
            'completed' => false,
        ];

        $this->cache->shouldReceive('get')
            ->with('zb_checkout_client-uuid')
            ->andReturn($existingState);

        $this->cache->shouldReceive('put')
            ->once()
            ->withArgs(function (string $key, array $state, int $ttl): bool {
                expect($state['completed'])->toBeTrue();
                expect($state['transaction_id'])->toBe('TXN-001');
                expect($state['final_value'])->toBe(59.98);

                return true;
            });

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->andReturnNull();

        $result = $tracker->completeCheckout(
            'client-uuid',
            'TXN-001',
            59.98,
            'EUR',
            ['tax' => 10.0, 'shipping' => 5.0],
        );

        expect($result)->not->toBeNull();
        expect($result['completed'])->toBeTrue();
        expect($result['transaction_id'])->toBe('TXN-001');
        expect($result['value'])->toBe(59.98);
        expect($result['total_steps'])->toBe(3);
        expect($result['total_time'])->toBeGreaterThanOrEqual(599);
    });

    it('abandons checkout and fires abandon event', function (): void {
        $tracker = new CheckoutFlowTracker($this->manager, $this->cache, $this->config);

        $existingState = [
            'checkout_id' => 'cko_test123',
            'step' => 'shipping_info',
            'step_index' => 2,
            'started_at' => time() - 3600,
            'steps' => [
                ['step' => 'cart_review', 'timestamp' => time() - 3600],
                ['step' => 'shipping_info', 'timestamp' => time() - 3400],
            ],
            'value' => 99.99,
            'item_count' => 3,
            'currency' => 'USD',
            'items' => [],
            'coupon' => null,
            'completed' => false,
        ];

        $this->cache->shouldReceive('get')
            ->with('zb_checkout_client-uuid')
            ->andReturn($existingState);

        $this->cache->shouldReceive('forget')
            ->once()
            ->with('zb_checkout_client-uuid');

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->andReturnNull();

        $result = $tracker->abandonCheckout('client-uuid', 'payment_failed');

        expect($result)->not->toBeNull();
        expect($result['abandoned_at_step'])->toBe('shipping_info');
        expect($result['abandoned_at_index'])->toBe(2);
        expect($result['value'])->toBe(99.99);
        expect($result['total_time'])->toBeGreaterThanOrEqual(3399);
    });

    it('returns null for advance when no active checkout exists', function (): void {
        $tracker = new CheckoutFlowTracker($this->manager, $this->cache, $this->config);

        $this->cache->shouldReceive('get')
            ->with('zb_checkout_client-uuid')
            ->andReturn(null);

        $result = $tracker->advanceStep('client-uuid', 'payment_info');

        expect($result)->toBeNull();
    });

    it('returns empty state when disabled', function (): void {
        $this->config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.checkout_tracking', [])
            ->andReturn(['enabled' => false]);

        $tracker = new CheckoutFlowTracker($this->manager, $this->cache, $this->config);

        $result = $tracker->startCheckout('client-uuid', []);

        expect($result['checkout_id'])->toBe('');
        expect($result['step_index'])->toBe(0);
    });

    it('provides valid checkout step constants', function (): void {
        expect(CheckoutFlowTracker::STEPS)->toBe([
            'cart_review',
            'shipping_info',
            'payment_info',
            'order_review',
            'confirmation',
        ]);

        expect(CheckoutFlowTracker::isValidStep('cart_review'))->toBeTrue();
        expect(CheckoutFlowTracker::isValidStep('invalid_step'))->toBeFalse();

        expect(CheckoutFlowTracker::stepIndex('shipping_info'))->toBe(2);
        expect(CheckoutFlowTracker::stepIndex('nonexistent'))->toBe(0);
    });

    it('computes step timing correctly', function (): void {
        $tracker = new CheckoutFlowTracker($this->manager, $this->cache, $this->config);

        $now = time();
        $state = [
            'checkout_id' => 'cko_test',
            'step' => 'order_review',
            'step_index' => 4,
            'started_at' => $now - 600,
            'steps' => [
                ['step' => 'cart_review', 'timestamp' => $now - 600],
                ['step' => 'shipping_info', 'timestamp' => $now - 480],
                ['step' => 'payment_info', 'timestamp' => $now - 300],
                ['step' => 'order_review', 'timestamp' => $now - 60],
            ],
            'value' => 50.0,
            'item_count' => 2,
            'currency' => 'USD',
            'items' => [],
            'coupon' => null,
            'completed' => false,
        ];

        $this->cache->shouldReceive('get')
            ->with('zb_checkout_client-uuid')
            ->andReturn($state);

        $timings = $tracker->getStepTiming('client-uuid');

        expect($timings)->toHaveCount(4);
        expect($timings[0]['step'])->toBe('cart_review');
        expect($timings[0]['duration_seconds'])->toBe(120); // 600-480
        expect($timings[1]['step'])->toBe('shipping_info');
        expect($timings[1]['duration_seconds'])->toBe(180); // 480-300
    });

    it('returns funnel steps definition', function (): void {
        $tracker = new CheckoutFlowTracker($this->manager, $this->cache, $this->config);

        $funnel = $tracker->funnelSteps();

        expect($funnel['steps'])->toHaveCount(5);
        expect($funnel['steps'][0]['name'])->toBe('cart_review');
        expect($funnel['steps'][0]['label'])->toBe('Cart Review');
        expect($funnel['steps'][4]['name'])->toBe('confirmation');
    });
});

// ── SaaSKpiCalculatorService Tests ─────────────────────────────

describe('SaaSKpiCalculatorService', function (): void {
    beforeEach(function (): void {
        $this->kpi = new SaaSKpiCalculatorService($this->cache, $this->config);
    });

    it('calculates MRR from mixed billing cycles', function (): void {
        $subscriptions = [
            ['amount' => 49.00, 'billing_cycle' => 'monthly', 'status' => 'active'],
            ['amount' => 480.00, 'billing_cycle' => 'annually', 'status' => 'active'],
            ['amount' => 99.00, 'billing_cycle' => 'monthly', 'status' => 'active'],
            ['amount' => 150.00, 'billing_cycle' => 'quarterly', 'status' => 'active'],
            ['amount' => 29.00, 'billing_cycle' => 'monthly', 'status' => 'active'],
            ['amount' => 100.00, 'billing_cycle' => 'monthly', 'status' => 'cancelled'], // excluded
            ['amount' => 0.00, 'billing_cycle' => 'monthly', 'status' => 'trialing'], // included
        ];

        // 49 + 480/12 + 99 + 150/3 + 29 + 0 = 49 + 40 + 99 + 50 + 29 + 0 = 267
        expect($this->kpi->mrr($subscriptions))->toBe(267.0);
    });

    it('calculates ARR from MRR', function (): void {
        expect($this->kpi->arr(1000.0))->toBe(12000.0);
    });

    it('calculates ARPU', function (): void {
        expect($this->kpi->arpu(5000.0, 100))->toBe(50.0);
        expect($this->kpi->arpu(5000.0, 0))->toBe(0.0);
    });

    it('calculates churn rate', function (): void {
        expect($this->kpi->churnRate(5, 100))->toBe(0.05);
        expect($this->kpi->churnRate(0, 100))->toBe(0.0);
        expect($this->kpi->churnRate(10, 0))->toBe(0.0);
    });

    it('calculates revenue churn rate', function (): void {
        expect($this->kpi->revenueChurnRate(500, 10000))->toBe(0.05);
        expect($this->kpi->revenueChurnRate(0, 10000))->toBe(0.0);
    });

    it('calculates LTV using ARPU / churn rate', function (): void {
        // LTV = 50 / 0.05 = 1000
        expect($this->kpi->ltv(50.0, 0.05))->toBe(1000.0);
        // Zero churn = infinite LTV → returns 0
        expect($this->kpi->ltv(50.0, 0.0))->toBe(0.0);
    });

    it('calculates LTV:CAC ratio', function (): void {
        expect($this->kpi->ltvCacRatio(3000.0, 500.0))->toBe(6.0);
        expect($this->kpi->ltvCacRatio(1000.0, 0.0))->toBe(0.0);
    });

    it('calculates payback period', function (): void {
        expect($this->kpi->paybackPeriod(500.0, 50.0))->toBe(10.0);
        expect($this->kpi->paybackPeriod(500.0, 0.0))->toBe(0.0);
    });

    it('calculates Net Revenue Retention', function (): void {
        // (10000 + 2000 - 500 - 300) / 10000 = 11200/10000 = 1.12
        expect($this->kpi->netRevenueRetention(10000, 2000, 500, 300))->toBe(1.12);
    });

    it('calculates Gross Revenue Retention', function (): void {
        // (10000 - 500 - 300) / 10000 = 9200/10000 = 0.92
        expect($this->kpi->grossRevenueRetention(10000, 500, 300))->toBe(0.92);
    });

    it('calculates Quick Ratio', function (): void {
        // (3000 + 1500) / (500 + 200) = 4500/700 = 6.43
        expect($this->kpi->quickRatio(3000, 1500, 500, 200))->toBe(6.43);
    });

    it('calculates Rule of 40', function (): void {
        expect($this->kpi->ruleOf40(50.0, -5.0))->toBe(45.0);
        expect($this->kpi->ruleOf40(20.0, 10.0))->toBe(30.0);
    });

    it('calculates trial conversion rate', function (): void {
        expect($this->kpi->trialConversionRate(25, 100))->toBe(0.25);
    });

    it('calculates activation rate', function (): void {
        expect($this->kpi->activationRate(60, 200))->toBe(0.3);
    });

    it('computes full dashboard with health assessment', function (): void {
        $data = [
            'subscriptions' => [
                ['amount' => 49.00, 'billing_cycle' => 'monthly', 'status' => 'active'],
                ['amount' => 99.00, 'billing_cycle' => 'monthly', 'status' => 'active'],
            ],
            'active_subscribers' => 100,
            'churned_customers' => 3,
            'start_customers' => 100,
            'mrr_lost' => 147.0,
            'start_mrr' => 4900.0,
            'expansion_mrr' => 800.0,
            'contraction_mrr' => 200.0,
            'new_mrr' => 1500.0,
            'churn_mrr' => 147.0,
            'cac' => 200.0,
            'trial_conversions' => 30,
            'total_trials' => 100,
            'activated_users' => 70,
            'total_signups' => 100,
            'growth_rate' => 40.0,
            'profit_margin' => 5.0,
        ];

        $dashboard = $this->kpi->computeDashboard($data);

        expect($dashboard['mrr'])->toBe(148.0);
        expect($dashboard['arr'])->toBe(1776.0);
        expect($dashboard['arpu'])->toBe(1.48);
        expect($dashboard['churn_rate'])->toBe(0.03);
        expect($dashboard['trial_conversion_rate'])->toBe(0.3);
        expect($dashboard['activation_rate'])->toBe(0.7);
        expect($dashboard['rule_of_40'])->toBe(45.0);
        expect($dashboard['health'])->toHaveKey('overall');
        expect($dashboard['health'])->toHaveKey('churn');
        expect($dashboard['health'])->toHaveKey('ltv_cac');
        expect($dashboard['health'])->toHaveKey('nrr');
        expect(in_array($dashboard['health']['overall'], ['healthy', 'warning', 'critical'], true))->toBeTrue();
    });

    it('assesses health correctly', function (): void {
        // Healthy scenario
        $health = $this->kpi->assessHealth(0.02, 5.0, 1.15, 6.0, 55.0);
        expect($health['churn'])->toBe('healthy');
        expect($health['ltv_cac'])->toBe('healthy');
        expect($health['nrr'])->toBe('healthy');
        expect($health['quick_ratio'])->toBe('healthy');
        expect($health['overall'])->toBe('healthy');

        // Warning scenario — high churn, low LTV:CAC
        $health = $this->kpi->assessHealth(0.08, 1.5, 0.95, 2.0, 30.0);
        expect($health['churn'])->toBe('warning');
        expect($health['ltv_cac'])->toBe('warning');
        expect($health['nrr'])->toBe('warning');
    });

    it('returns configured benchmarks', function (): void {
        $benchmarks = $this->kpi->getBenchmarks();

        expect($benchmarks['mrr_goal'])->toBe(10000.0);
        expect($benchmarks['churn_warning'])->toBe(0.05);
        expect($benchmarks['ltv_cac_target'])->toBe(3.0);
        expect($benchmarks['quick_ratio_target'])->toBe(4.0);
        expect($benchmarks['rule_of_40_target'])->toBe(40.0);
    });
});

// ── ProviderEventValidator Tests ───────────────────────────────

describe('ProviderEventValidator', function (): void {
    beforeEach(function (): void {
        $this->validator = new ProviderEventValidator;
    });

    it('validates GA4 event with valid items', function (): void {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'TXN-001',
                'value' => 99.99,
                'currency' => 'USD',
                'items' => [
                    ['item_id' => 'prod-1', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2],
                ],
            ],
            clientId: 'client-1',
        );

        $result = $this->validator->validateGa4($event);

        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
    });

    it('catches GA4 items with missing required fields', function (): void {
        $event = new AnalyticsEvent(
            name: 'add_to_cart',
            params: [
                'items' => [
                    ['item_name' => 'Widget'], // missing item_id and price
                ],
            ],
            clientId: 'client-1',
        );

        $result = $this->validator->validateGa4($event);

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->toContain("Item at index 0 is missing required field 'item_id'");
        expect($result['errors'])->toContain("Item at index 0 is missing required field 'price'");
    });

    it('catches GA4 too many items', function (): void {
        $items = [];
        for ($i = 0; $i < 30; $i++) {
            $items[] = ['item_id' => "item-{$i}", 'price' => 10.0, 'quantity' => 1];
        }

        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['items' => $items, 'transaction_id' => 'TXN-001'],
            clientId: 'client-1',
        );

        $result = $this->validator->validateGa4($event);

        expect($result['valid'])->toBeFalse();
        expect($result['errors'][0])->toContain('max 25 items');
    });

    it('catches GA4 invalid currency code', function (): void {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['currency' => 'invalid', 'items' => []],
            clientId: 'client-1',
        );

        $result = $this->validator->validateGa4($event);

        expect($result['valid'])->toBeFalse();
        expect($result['errors'][0])->toContain('Invalid ISO 4217');
    });

    it('warns on GA4 missing transaction_id for purchase', function (): void {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 50.0, 'currency' => 'USD', 'items' => []],
            clientId: 'client-1',
        );

        $result = $this->validator->validateGa4($event);

        expect($result['valid'])->toBeTrue();
        expect($result['warnings'][0])->toContain('missing');
    });

    it('validates Meta Pixel event parameters', function (): void {
        $event = new AnalyticsEvent(
            name: 'Purchase',
            params: [
                'value' => 59.99,
                'currency' => 'EUR',
                'content_ids' => ['prod-1'],
                'contents' => [['id' => 'prod-1', 'quantity' => 1, 'item_price' => 59.99]],
                'num_items' => 1,
                'content_type' => 'product',
            ],
            clientId: 'client-1',
        );

        $result = $this->validator->validateMeta($event);

        expect($result['valid'])->toBeTrue();
    });

    it('catches Meta num_items mismatch', function (): void {
        $event = new AnalyticsEvent(
            name: 'AddToCart',
            params: [
                'contents' => [['id' => 'p1'], ['id' => 'p2'], ['id' => 'p3']],
                'num_items' => 2,
            ],
            clientId: 'client-1',
        );

        $result = $this->validator->validateMeta($event);

        expect($result['valid'])->toBeTrue();
        expect($result['warnings'][0])->toContain('num_items');
    });

    it('validates PostHog reserved properties', function (): void {
        $event = new AnalyticsEvent(
            name: '$pageview',
            params: [
                '$distinct_id' => 'user-1',
                'custom_prop' => 'value',
            ],
            clientId: 'client-1',
        );

        $result = $this->validator->validatePosthog($event);

        expect($result['valid'])->toBeFalse();
        expect($result['errors'][0])->toContain('$distinct_id');
        expect($result['errors'][0])->toContain('reserved');
    });

    it('warns on PostHog non-$ currency', function (): void {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['currency' => 'USD'],
            clientId: 'client-1',
        );

        $result = $this->validator->validatePosthog($event);

        expect($result['valid'])->toBeTrue();
        expect($result['warnings'][0])->toContain('$currency');
    });

    it('validates Plausible event names (no spaces)', function (): void {
        $event = new AnalyticsEvent(
            name: 'button click',
            params: ['label' => 'Buy Now'],
            clientId: 'client-1',
        );

        $result = $this->validator->validatePlausible($event);

        expect($result['valid'])->toBeFalse();
        expect($result['errors'][0])->toContain('spaces');
    });

    it('warns on Plausible event with params', function (): void {
        $event = new AnalyticsEvent(
            name: 'signup',
            params: ['source' => 'landing_page'],
            clientId: 'client-1',
        );

        $result = $this->validator->validatePlausible($event);

        expect($result['valid'])->toBeTrue();
        expect($result['warnings'][0])->toContain('properties');
    });

    it('validates across all providers simultaneously', function (): void {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'TXN-001',
                'value' => 99.99,
                'currency' => 'USD',
                'items' => [
                    ['item_id' => 'prod-1', 'price' => 99.99, 'quantity' => 1],
                ],
            ],
            clientId: 'client-1',
        );

        $result = $this->validator->validateAll($event, ['ga4', 'meta', 'posthog']);

        expect($result['valid'])->toBeTrue();
        expect($result['providers'])->toHaveKey('ga4');
        expect($result['providers'])->toHaveKey('meta');
        expect($result['providers'])->toHaveKey('posthog');
        expect($result['providers']['ga4']['valid'])->toBeTrue();
    });

    it('reports overall invalid when any provider fails', function (): void {
        $badEvent = new AnalyticsEvent(
            name: 'button click',
            params: [],
            clientId: 'client-1',
        );

        $result = $this->validator->validateAll($badEvent, ['plausible', 'ga4']);

        // Plausible fails on spaces in event name
        expect($result['valid'])->toBeFalse();
        expect($result['providers']['plausible']['valid'])->toBeFalse();
    });

    it('provides static constants accessors', function (): void {
        expect(ProviderEventValidator::ga4RequiredItemFields())->toBe(['item_id', 'price']);
        expect(ProviderEventValidator::ga4MaxItems())->toBe(25);
        expect(ProviderEventValidator::posthogReservedProperties())->toContain('$distinct_id');
    });
});
