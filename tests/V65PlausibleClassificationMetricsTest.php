<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventClassificationService;
use ZeroBoiler\Analytics\Services\SubscriptionMetricsCalculator;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;

beforeEach(function (): void {
    // No setup needed — all test services are stateless
});

// ── EcommerceFormatConverter: Plausible Conversion ───────────────────

describe('EcommerceFormatConverter — Plausible conversion', function (): void {
    test('ga4ToPlausiblePurchase converts purchase params correctly', function (): void {
        $result = EcommerceFormatConverter::ga4ToPlausiblePurchase([
            'transaction_id' => 'TXN-001',
            'value' => 99.99,
            'currency' => 'USD',
            'tax' => 8.50,
            'shipping' => 5.00,
            'coupon' => 'SAVE10',
            'items' => [
                ['item_id' => 'SKU-1', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2],
            ],
        ]);

        expect($result['event_name'])->toBe('purchase');
        expect($result['props']['transaction_id'])->toBe('TXN-001');
        expect($result['props']['revenue'])->toBe('99.99');
        expect($result['props']['currency'])->toBe('USD');
        expect($result['props']['coupon'])->toBe('SAVE10');
        expect($result['props']['items'])->toBe('Widget');
        expect($result['props']['item_count'])->toBe('1');
        expect($result['props']['tax'])->toBe('8.5');
        expect($result['props']['shipping'])->toBe('5');
    });

    test('ga4ToPlausiblePurchase filters empty values', function (): void {
        $result = EcommerceFormatConverter::ga4ToPlausiblePurchase([
            'transaction_id' => 'TXN-002',
            'value' => 50.00,
            'currency' => 'EUR',
            'items' => [],
        ]);

        expect($result['event_name'])->toBe('purchase');
        expect($result['props']['transaction_id'])->toBe('TXN-002');
        expect($result['props']['revenue'])->toBe('50');
        expect($result['props']['items'])->toBe('');
        expect($result['props']['coupon'])->toBe('');
        // Empty string is filtered out
        expect(isset($result['props']['coupon']))->toBeFalse();
    });

    test('ga4ToPlausibleRefund converts refund params', function (): void {
        $result = EcommerceFormatConverter::ga4ToPlausibleRefund([
            'transaction_id' => 'TXN-001',
            'value' => 49.99,
            'currency' => 'USD',
        ]);

        expect($result['event_name'])->toBe('refund');
        expect($result['props']['transaction_id'])->toBe('TXN-001');
        expect($result['props']['refund_value'])->toBe('49.99');
    });

    test('ga4ToPlausibleAddToCart converts cart params', function (): void {
        $result = EcommerceFormatConverter::ga4ToPlausibleAddToCart([
            'value' => 29.99,
            'currency' => 'USD',
            'items' => [
                ['item_id' => 'SKU-1', 'item_name' => 'Premium Plan', 'price' => 29.99, 'quantity' => 1],
            ],
        ]);

        expect($result['event_name'])->toBe('add_to_cart');
        expect($result['props']['item_name'])->toBe('Premium Plan');
        expect($result['props']['item_id'])->toBe('SKU-1');
        expect($result['props']['value'])->toBe('29.99');
    });

    test('ga4ToPlausibleBeginCheckout converts checkout params', function (): void {
        $result = EcommerceFormatConverter::ga4ToPlausibleBeginCheckout([
            'value' => 99.99,
            'currency' => 'USD',
            'coupon' => 'WELCOME20',
            'items' => [
                ['item_name' => 'Pro Plan'],
                ['item_name' => 'Add-on Pack'],
            ],
        ]);

        expect($result['event_name'])->toBe('begin_checkout');
        expect($result['props']['items'])->toBe('Pro Plan, Add-on Pack');
        expect($result['props']['item_count'])->toBe('2');
        expect($result['props']['coupon'])->toBe('WELCOME20');
    });

    test('buildPlausiblePurchase creates a valid AnalyticsEvent', function (): void {
        $event = EcommerceFormatConverter::buildPlausiblePurchase(
            'TXN-100',
            199.99,
            'USD',
            [
                ['item_id' => 'SKU-A', 'item_name' => 'Enterprise', 'price' => 199.99, 'quantity' => 1],
            ],
            ['coupon' => 'FIRST100'],
        );

        expect($event)->toBeInstanceOf(AnalyticsEvent::class);
        expect($event->name)->toBe('purchase');
        expect($event->params['transaction_id'])->toBe('TXN-100');
        expect($event->params['revenue'])->toBe('199.99');
    });

    test('ga4ToPlausiblePurchase handles missing items gracefully', function (): void {
        $result = EcommerceFormatConverter::ga4ToPlausiblePurchase([
            'transaction_id' => 'TXN-EMPTY',
            'value' => 10.00,
            'currency' => 'USD',
        ]);

        expect($result['event_name'])->toBe('purchase');
        expect($result['props']['items'])->toBe('');
    });

    test('ga4ToPlausibleAddToCart handles empty items', function (): void {
        $result = EcommerceFormatConverter::ga4ToPlausibleAddToCart([
            'value' => 0,
            'currency' => 'USD',
        ]);

        expect($result['event_name'])->toBe('add_to_cart');
        expect($result['props']['item_name'])->toBe('');
    });
});

// ── EventClassificationService ─────────────────────────────────────────

describe('EventClassificationService', function (): void {
    $classifier = new EventClassificationService;

    test('classifies revenue events as critical', function () use ($classifier): void {
        expect($classifier->classify('purchase'))->toBe(EventClassificationService::TIER_CRITICAL);
        expect($classifier->classify('refund'))->toBe(EventClassificationService::TIER_CRITICAL);
        expect($classifier->classify('subscription'))->toBe(EventClassificationService::TIER_CRITICAL);
        expect($classifier->classify('payment_succeeded'))->toBe(EventClassificationService::TIER_CRITICAL);
        expect($classifier->classify('payment_failed'))->toBe(EventClassificationService::TIER_CRITICAL);
        expect($classifier->classify('revenue_tracked'))->toBe(EventClassificationService::TIER_CRITICAL);
    });

    test('classifies funnel events as monetization', function () use ($classifier): void {
        expect($classifier->classify('sign_up'))->toBe(EventClassificationService::TIER_MONETIZATION);
        expect($classifier->classify('trial_start'))->toBe(EventClassificationService::TIER_MONETIZATION);
        expect($classifier->classify('plan_upgrade'))->toBe(EventClassificationService::TIER_MONETIZATION);
        expect($classifier->classify('begin_checkout'))->toBe(EventClassificationService::TIER_MONETIZATION);
        expect($classifier->classify('add_to_cart'))->toBe(EventClassificationService::TIER_MONETIZATION);
        expect($classifier->classify('view_item'))->toBe(EventClassificationService::TIER_MONETIZATION);
        expect($classifier->classify('feature_limit_reached'))->toBe(EventClassificationService::TIER_MONETIZATION);
    });

    test('classifies engagement events correctly', function () use ($classifier): void {
        expect($classifier->classify('page_view'))->toBe(EventClassificationService::TIER_ENGAGEMENT);
        expect($classifier->classify('scroll_depth'))->toBe(EventClassificationService::TIER_ENGAGEMENT);
        expect($classifier->classify('form_submit'))->toBe(EventClassificationService::TIER_ENGAGEMENT);
        expect($classifier->classify('search'))->toBe(EventClassificationService::TIER_ENGAGEMENT);
        expect($classifier->classify('share'))->toBe(EventClassificationService::TIER_ENGAGEMENT);
        expect($classifier->classify('video_play'))->toBe(EventClassificationService::TIER_ENGAGEMENT);
        expect($classifier->classify('feature_used'))->toBe(EventClassificationService::TIER_ENGAGEMENT);
    });

    test('classifies operational events correctly', function () use ($classifier): void {
        expect($classifier->classify('login'))->toBe(EventClassificationService::TIER_OPERATIONAL);
        expect($classifier->classify('logout'))->toBe(EventClassificationService::TIER_OPERATIONAL);
        expect($classifier->classify('session_start'))->toBe(EventClassificationService::TIER_OPERATIONAL);
        expect($classifier->classify('password_changed'))->toBe(EventClassificationService::TIER_OPERATIONAL);
        expect($classifier->classify('cohort_assigned'))->toBe(EventClassificationService::TIER_OPERATIONAL);
        expect($classifier->classify('team_created'))->toBe(EventClassificationService::TIER_OPERATIONAL);
    });

    test('isRevenueImpacting returns true for critical and monetization', function () use ($classifier): void {
        expect($classifier->isRevenueImpacting('purchase'))->toBeTrue();
        expect($classifier->isRevenueImpacting('sign_up'))->toBeTrue();
        expect($classifier->isRevenueImpacting('page_view'))->toBeFalse();
        expect($classifier->isRevenueImpacting('login'))->toBeFalse();
    });

    test('isDroppable returns true for engagement and operational', function () use ($classifier): void {
        expect($classifier->isDroppable('page_view'))->toBeTrue();
        expect($classifier->isDroppable('login'))->toBeTrue();
        expect($classifier->isDroppable('purchase'))->toBeFalse();
        expect($classifier->isDroppable('begin_checkout'))->toBeFalse();
    });

    test('custom overrides take precedence', function (): void {
        $custom = new EventClassificationService([
            'custom_event' => 'critical',
            'page_view' => 'operational',
        ]);

        expect($custom->classify('custom_event'))->toBe('critical');
        expect($custom->classify('page_view'))->toBe('operational');
    });

    test('classifyBatch groups events by tier', function () use ($classifier): void {
        $result = $classifier->classifyBatch([
            'purchase', 'sign_up', 'page_view', 'login', 'refund', 'search',
        ]);

        expect($result['critical'])->toContain('purchase');
        expect($result['critical'])->toContain('refund');
        expect($result['monetization'])->toContain('sign_up');
        expect($result['engagement'])->toContain('page_view');
        expect($result['engagement'])->toContain('search');
        expect($result['operational'])->toContain('login');
    });

    test('tierWeight returns correct weights', function (): void {
        $svc = new EventClassificationService;
        expect($svc->tierWeight('critical'))->toBe(4);
        expect($svc->tierWeight('monetization'))->toBe(3);
        expect($svc->tierWeight('engagement'))->toBe(2);
        expect($svc->tierWeight('operational'))->toBe(1);
    });

    test('getDispatchPriority maps tiers to gate levels', function () use ($classifier): void {
        expect($classifier->getDispatchPriority('purchase'))->toBe('critical');
        expect($classifier->getDispatchPriority('sign_up'))->toBe('normal');
        expect($classifier->getDispatchPriority('page_view'))->toBe('low');
        expect($classifier->getDispatchPriority('login'))->toBe('background');
    });

    test('unknown events with revenue keyword default to monetization', function () use ($classifier): void {
        expect($classifier->classify('my_revenue_event'))->toBe(EventClassificationService::TIER_MONETIZATION);
    });

    test('unknown events with error keyword default to operational', function () use ($classifier): void {
        expect($classifier->classify('custom_error_event'))->toBe(EventClassificationService::TIER_OPERATIONAL);
    });

    test('completely unknown events default to engagement', function () use ($classifier): void {
        expect($classifier->classify('xyz_random_event'))->toBe(EventClassificationService::TIER_ENGAGEMENT);
    });

    test('tierToPriorityMap returns correct mapping', function (): void {
        $map = EventClassificationService::tierToPriorityMap();
        expect($map)->toBe([
            'critical' => 'critical',
            'monetization' => 'normal',
            'engagement' => 'low',
            'operational' => 'background',
        ]);
    });

    test('getEventsInTier returns all events for a tier', function () use ($classifier): void {
        $critical = $classifier->getEventsInTier('critical');
        expect($critical)->toContain('purchase');
        expect($critical)->toContain('subscription');
        expect($critical)->not->toContain('page_view');
    });

    test('case insensitive classification', function () use ($classifier): void {
        expect($classifier->classify('PURCHASE'))->toBe(EventClassificationService::TIER_CRITICAL);
        expect($classifier->classify('Page_View'))->toBe(EventClassificationService::TIER_ENGAGEMENT);
    });
});

// ── SubscriptionMetricsCalculator ──────────────────────────────────────

describe('SubscriptionMetricsCalculator', function (): void {
    $sampleSubscriptions = [
        ['amount' => 29.00, 'plan' => 'Pro', 'status' => 'active'],
        ['amount' => 99.00, 'plan' => 'Enterprise', 'status' => 'active'],
        ['amount' => 0.00, 'plan' => 'Free', 'status' => 'active'],
        ['amount' => 29.00, 'plan' => 'Pro', 'status' => 'trialing'],
        ['amount' => 29.00, 'plan' => 'Pro', 'status' => 'cancelled'],
    ];

    test('calculateMrr sums active and trialing subscriptions', function () use ($sampleSubscriptions): void {
        $result = SubscriptionMetricsCalculator::calculateMrr($sampleSubscriptions);

        expect($result['mrr'])->toBe(157.0); // 29 + 99 + 0 + 29 = 157
        expect($result['subscriber_count'])->toBe(4); // active + trialing
        expect($result['by_plan']['Pro'])->toBe(58.0);
        expect($result['by_plan']['Enterprise'])->toBe(99.0);
    });

    test('mrrToArr converts MRR to ARR', function (): void {
        expect(SubscriptionMetricsCalculator::mrrToArr(1000.0))->toBe(12000.0);
        expect(SubscriptionMetricsCalculator::mrrToArr(157.0))->toBe(1884.0);
    });

    test('churnRate calculates correctly', function (): void {
        $result = SubscriptionMetricsCalculator::churnRate(1000, 50);

        expect($result['rate'])->toBe(0.05);
        expect($result['percentage'])->toBe(5.0);
        expect($result['customers_lost'])->toBe(50);
        expect($result['customers_remaining'])->toBe(950);
    });

    test('churnRate handles zero customers', function (): void {
        $result = SubscriptionMetricsCalculator::churnRate(0, 5);

        expect($result['rate'])->toBe(0.0);
        expect($result['percentage'])->toBe(0.0);
    });

    test('revenueChurnRate calculates correctly', function (): void {
        $result = SubscriptionMetricsCalculator::revenueChurnRate(10000.0, 500.0);

        expect($result['rate'])->toBe(0.05);
        expect($result['percentage'])->toBe(5.0);
        expect($result['mrr_lost'])->toBe(500.0);
    });

    test('netRevenueRetention calculates expansion', function (): void {
        $result = SubscriptionMetricsCalculator::netRevenueRetention(10000, 2000, 500, 300);

        // (10000 + 2000 - 500 - 300) / 10000 = 1.12
        expect($result['percentage'])->toBe(112.0);
        expect($result['expansion'])->toBe(2000.0);
        expect($result['contraction'])->toBe(500.0);
        expect($result['churn'])->toBe(300.0);
    });

    test('netRevenueRetention handles zero starting MRR', function (): void {
        $result = SubscriptionMetricsCalculator::netRevenueRetention(0, 100, 0, 0);

        expect($result['nrr'])->toBe(0.0);
    });

    test('arpu calculates correctly', function (): void {
        $result = SubscriptionMetricsCalculator::arpu(5000.0, 1000, 200);

        expect($result['arpu'])->toBe(5.0);
        expect($result['arppu'])->toBe(25.0);
        expect($result['paying_ratio'])->toBe(0.2);
    });

    test('customerLifetimeValue calculates correctly', function (): void {
        $result = SubscriptionMetricsCalculator::customerLifetimeValue(25.0, 0.05);

        // 25 * (1 / 0.05) = 25 * 20 = 500
        expect($result['clv_monthly'])->toBe(500.0);
        expect($result['months'])->toBe(20.0);
    });

    test('customerLifetimeValue handles zero churn', function (): void {
        $result = SubscriptionMetricsCalculator::customerLifetimeValue(25.0, 0);

        expect($result['clv_monthly'])->toBe(0.0);
        expect($result['assumption'])->toBe('zero churn — infinite CLV');
    });

    test('customerLifetimeValue handles 100% churn', function (): void {
        $result = SubscriptionMetricsCalculator::customerLifetimeValue(25.0, 1.0);

        expect($result['clv_monthly'])->toBe(0.0);
        expect($result['assumption'])->toBe('100% churn — zero CLV');
    });

    test('clvToCacRatio evaluates correctly', function (): void {
        $excellent = SubscriptionMetricsCalculator::clvToCacRatio(1500.0, 100.0);
        expect($excellent['ratio'])->toBe(15.0);
        expect($excellent['healthy'])->toBeTrue();

        $healthy = SubscriptionMetricsCalculator::clvToCacRatio(300.0, 100.0);
        expect($healthy['ratio'])->toBe(3.0);
        expect($healthy['healthy'])->toBeTrue();

        $marginal = SubscriptionMetricsCalculator::clvToCacRatio(50.0, 100.0);
        expect($marginal['ratio'])->toBe(0.5);
        expect($marginal['healthy'])->toBeFalse();
    });

    test('clvToCacRatio handles zero CAC', function (): void {
        $result = SubscriptionMetricsCalculator::clvToCacRatio(500.0, 0);

        expect($result['ratio'])->toBe(0.0);
        expect($result['healthy'])->toBeFalse();
    });

    test('runway calculates correctly', function (): void {
        $result = SubscriptionMetricsCalculator::runway(100000.0, 10000.0);

        expect($result['months'])->toBe(10.0);
        expect($result['healthy'])->toBeTrue();
    });

    test('runway handles zero burn', function (): void {
        $result = SubscriptionMetricsCalculator::runway(100000.0, 0);

        expect($result['months'])->toBe(0.0);
        expect($result['healthy'])->toBeTrue();
    });

    test('momGrowth calculates growth', function (): void {
        $result = SubscriptionMetricsCalculator::momGrowth(12000, 10000);

        expect($result['rate'])->toBe(0.2);
        expect($result['percentage'])->toBe(20.0);
        expect($result['direction'])->toBe('growth');
    });

    test('momGrowth calculates decline', function (): void {
        $result = SubscriptionMetricsCalculator::momGrowth(9000, 10000);

        expect($result['percentage'])->toBe(-10.0);
        expect($result['direction'])->toBe('decline');
    });

    test('dashboardSummary computes all metrics', function () use ($sampleSubscriptions): void {
        $summary = SubscriptionMetricsCalculator::dashboardSummary([
            'subscriptions' => $sampleSubscriptions,
            'total_users' => 500,
            'paying_users' => 50,
            'mrr_previous' => 130.0,
            'customers_at_start' => 1000,
            'customers_lost' => 40,
            'mrr_lost' => 580.0,
            'expansion' => 800.0,
            'contraction' => 200.0,
            'current_cash' => 500000.0,
            'monthly_burn' => 40000.0,
            'cac' => 150.0,
        ]);

        expect($summary['mrr']['mrr'])->toBe(157.0);
        expect($summary['arr'])->toBe(1884.0);
        expect($summary['churn']['percentage'])->toBe(4.0);
        expect($summary['arpu']['arpu'])->toBe(0.31);
        expect($summary['runway']['months'])->toBe(12.5);
        expect($summary['growth']['direction'])->toBe('growth');
    });
});
