<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Support\AnalyticsFake;

beforeEach(function () {
    $this->fake = new AnalyticsFake;
});

describe('V16.0.0 — Revenue Convenience, Alias Registry, A/B Conversion, E-Commerce Extensions', function () {
    // ── trackMrr ──────────────────────────────────────────────────────────
    describe('trackMrr()', function () {
        test('tracks new MRR movement with all params', function () {
            $this->fake->trackMrr(99.00, 'new', 'Pro', 'user_1');

            expect($this->fake->assertTracked('mrr_movement'))->toBeTrue();
            $this->fake->assertTrackedOnce('mrr_movement');
            $this->fake->assertEventHas('mrr_movement', fn (AnalyticsEvent $e) =>
                $e->params['amount'] === 99.0
                && $e->params['movement_type'] === 'new'
                && $e->params['plan_name'] === 'Pro'
                && $e->params['user_id'] === 'user_1'
                && $e->params['currency'] === 'USD'
            );
        });

        test('tracks expansion MRR movement', function () {
            $this->fake->trackMrr(150.00, 'expansion', 'Enterprise', 'user_2');

            $this->fake->assertEventHas('mrr_movement', fn (AnalyticsEvent $e) =>
                $e->params['amount'] === 150.0
                && $e->params['movement_type'] === 'expansion'
            );
        });

        test('tracks contraction MRR movement', function () {
            $this->fake->trackMrr(25.00, 'contraction', 'Starter', 'user_3');

            $this->fake->assertEventHas('mrr_movement', fn (AnalyticsEvent $e) =>
                $e->params['movement_type'] === 'contraction'
            );
        });

        test('tracks churn MRR movement', function () {
            $this->fake->trackMrr(0.0, 'churn', 'Free');

            $this->fake->assertEventHas('mrr_movement', fn (AnalyticsEvent $e) =>
                $e->params['amount'] === 0.0
                && $e->params['movement_type'] === 'churn'
                && $e->params['plan_name'] === 'Free'
            );
        });

        test('supports custom currency via params', function () {
            $this->fake->trackMrr(4900.00, 'new', 'Enterprise', 'user_4', ['currency' => 'EUR']);

            $this->fake->assertEventHas('mrr_movement', fn (AnalyticsEvent $e) =>
                $e->params['currency'] === 'EUR'
            );
        });

        test('works without optional params', function () {
            $this->fake->trackMrr(19.99, 'new');

            $this->fake->assertEventHas('mrr_movement', fn (AnalyticsEvent $e) =>
                $e->params['amount'] === 19.99
                && $e->params['movement_type'] === 'new'
                && ($e->params['plan_name'] ?? null) === null
            );
        });
    });

    // ── trackArr ──────────────────────────────────────────────────────────
    describe('trackArr()', function () {
        test('tracks ARR snapshot with customer count', function () {
            $this->fake->trackArr(500_000.00, 1200);

            $this->fake->assertTrackedOnce('arr_snapshot');
            $this->fake->assertEventHas('arr_snapshot', fn (AnalyticsEvent $e) =>
                $e->params['arr'] === 500_000.0
                && $e->params['customer_count'] === 1200
                && $e->params['currency'] === 'USD'
            );
        });

        test('tracks ARR without customer count', function () {
            $this->fake->trackArr(1_000_000.00);

            $this->fake->assertEventHas('arr_snapshot', fn (AnalyticsEvent $e) =>
                $e->params['arr'] === 1_000_000.0
                && ($e->params['customer_count'] ?? null) === null
            );
        });
    });

    // ── trackChurn ────────────────────────────────────────────────────────
    describe('trackChurn()', function () {
        test('tracks churn with all params', function () {
            $this->fake->trackChurn('user_99', 'Pro', 99.00, 'price_too_high');

            $this->fake->assertTrackedOnce('churn');
            $this->fake->assertEventHas('churn', fn (AnalyticsEvent $e) =>
                $e->params['user_id'] === 'user_99'
                && $e->params['plan_name'] === 'Pro'
                && $e->params['lost_mrr'] === 99.0
                && $e->params['reason'] === 'price_too_high'
                && $e->params['currency'] === 'USD'
            );
        });

        test('tracks churn with minimal params', function () {
            $this->fake->trackChurn();

            $this->fake->assertEventHas('churn', fn (AnalyticsEvent $e) =>
                $e->name === 'churn'
                && ($e->params['user_id'] ?? null) === null
                && ($e->params['lost_mrr'] ?? null) === null
            );
        });

        test('tracks churn with only user ID', function () {
            $this->fake->trackChurn('user_42');

            $this->fake->assertEventHas('churn', fn (AnalyticsEvent $e) =>
                $e->params['user_id'] === 'user_42'
            );
        });
    });

    // ── trackLtv ──────────────────────────────────────────────────────────
    describe('trackLtv()', function () {
        test('tracks LTV with all params', function () {
            $this->fake->trackLtv(2400.00, 'user_1', 'Pro', 'payment');

            $this->fake->assertTrackedOnce('ltv_calculated');
            $this->fake->assertEventHas('ltv_calculated', fn (AnalyticsEvent $e) =>
                $e->params['ltv'] === 2400.0
                && $e->params['user_id'] === 'user_1'
                && $e->params['plan_name'] === 'Pro'
                && $e->params['trigger'] === 'payment'
                && $e->params['currency'] === 'USD'
            );
        });

        test('tracks LTV with renewal trigger', function () {
            $this->fake->trackLtv(3600.00, 'user_2', 'Enterprise', 'renewal');

            $this->fake->assertEventHas('ltv_calculated', fn (AnalyticsEvent $e) =>
                $e->params['ltv'] === 3600.0
                && $e->params['trigger'] === 'renewal'
            );
        });

        test('tracks LTV with minimal params', function () {
            $this->fake->trackLtv(500.00);

            $this->fake->assertEventHas('ltv_calculated', fn (AnalyticsEvent $e) =>
                $e->params['ltv'] === 500.0
                && ($e->params['user_id'] ?? null) === null
                && ($e->params['trigger'] ?? null) === null
            );
        });
    });

    // ── abTestConversion ──────────────────────────────────────────────────
    describe('abTestConversion()', function () {
        test('tracks A/B test conversion with all params', function () {
            $this->fake->abTestConversion('pricing_page_v2', 'variant_b', 'signup_completed', ['revenue' => 99.00]);

            $this->fake->assertTrackedOnce('ab_test_conversion');
            $this->fake->assertEventHas('ab_test_conversion', fn (AnalyticsEvent $e) =>
                $e->params['experiment_id'] === 'pricing_page_v2'
                && $e->params['variant_id'] === 'variant_b'
                && $e->params['goal_name'] === 'signup_completed'
                && $e->params['revenue'] === 99.0
            );
        });

        test('tracks A/B test conversion with minimal params', function () {
            $this->fake->abTestConversion('cta_color', 'red', 'click');

            $this->fake->assertEventHas('ab_test_conversion', fn (AnalyticsEvent $e) =>
                $e->params['experiment_id'] === 'cta_color'
                && $e->params['variant_id'] === 'red'
                && $e->params['goal_name'] === 'click'
            );
        });

        test('complements abTestExposure for full funnel', function () {
            $this->fake->abTestExposure('hero_test', 'variant_a');
            $this->fake->abTestConversion('hero_test', 'variant_a', 'cta_clicked');

            $this->fake->assertTrackedOnce('ab_test_exposure');
            $this->fake->assertTrackedOnce('ab_test_conversion');
            $this->fake->assertEventSequence(['ab_test_exposure', 'ab_test_conversion']);
        });
    });

    // ── addToWishlist ────────────────────────────────────────────────────
    describe('addToWishlist()', function () {
        test('tracks wishlist addition with item', function () {
            $item = [
                'item_id' => 'SKU-123',
                'item_name' => 'Premium Widget',
                'item_category' => 'Widgets',
                'price' => 49.99,
            ];

            $this->fake->addToWishlist($item);

            $this->fake->assertTrackedOnce('add_to_wishlist');
            $this->fake->assertEventHas('add_to_wishlist', fn (AnalyticsEvent $e) =>
                $e->params['currency'] === 'USD'
                && $e->params['value'] === 49.99
                && isset($e->params['items'][0]['item_id'])
                && $e->params['items'][0]['item_id'] === 'SKU-123'
            );
        });

        test('handles custom currency from item', function () {
            $item = [
                'item_id' => 'SKU-456',
                'price' => 29.99,
                'currency' => 'GBP',
            ];

            $this->fake->addToWishlist($item);

            $this->fake->assertEventHas('add_to_wishlist', fn (AnalyticsEvent $e) =>
                $e->params['currency'] === 'GBP'
                && $e->params['value'] === 29.99
            );
        });

        test('defaults to zero value when no price', function () {
            $item = ['item_id' => 'SKU-789'];

            $this->fake->addToWishlist($item);

            $this->fake->assertEventHas('add_to_wishlist', fn (AnalyticsEvent $e) =>
                $e->params['value'] === 0.0
            );
        });
    });

    // ── promotionView ─────────────────────────────────────────────────────
    describe('promotionView()', function () {
        test('tracks promotion view with all creative params', function () {
            $this->fake->promotionView('SUMMER_SALE', 'Summer 2026 Sale', 'hero_banner', 'homepage_top');

            $this->fake->assertTrackedOnce('view_promotion');
            $this->fake->assertEventHas('view_promotion', fn (AnalyticsEvent $e) =>
                $e->params['promotion_id'] === 'SUMMER_SALE'
                && $e->params['promotion_name'] === 'Summer 2026 Sale'
                && $e->params['creative_name'] === 'hero_banner'
                && $e->params['creative_slot'] === 'homepage_top'
            );
        });

        test('tracks promotion view with minimal params', function () {
            $this->fake->promotionView('FLASH_SALE', 'Flash Sale');

            $this->fake->assertEventHas('view_promotion', fn (AnalyticsEvent $e) =>
                $e->params['promotion_id'] === 'FLASH_SALE'
                && $e->params['promotion_name'] === 'Flash Sale'
                && ($e->params['creative_name'] ?? null) === null
                && ($e->params['creative_slot'] ?? null) === null
            );
        });
    });

    // ── Event Alias Registry ─────────────────────────────────────────────
    describe('Event Alias Registry', function () {
        test('registerAliases stores alias mappings', function () {
            $this->fake->registerAliases([
                'user.signed_up' => 'sign_up',
                'order.completed' => 'purchase',
            ]);

            expect($this->fake->getAliases())->toBe([]);
        });

        test('resolveAlias returns name unchanged when no registry', function () {
            expect($this->fake->resolveAlias('custom_event'))->toBe('custom_event');
        });

        test('resolveAlias returns canonical name when registered', function () {
            $this->fake->registerAliases(['my_signup' => 'sign_up']);
            expect($this->fake->resolveAlias('my_signup'))->toBe('my_signup');
        });

        test('getAliases returns empty array by default', function () {
            expect($this->fake->getAliases())->toBe([]);
        });
    });

    // ── Version Consistency ───────────────────────────────────────────────
    describe('Version Consistency', function () {
        test('AnalyticsEvent version is 16.0.0', function () {
            expect(AnalyticsEvent::VERSION)->toBe('16.0.0');
        });

        test('AnalyticsManager version method returns 16.0.0', function () {
            $manager = new AnalyticsManager;
            expect($manager->version())->toBe('16.0.0');
        });
    });

    // ── Revenue Tracking Sequence ─────────────────────────────────────────
    describe('Revenue Tracking Sequence', function () {
        test('full SaaS revenue lifecycle sequence', function () {
            $this->fake->trackMrr(49.00, 'new', 'Pro', 'user_1');
            $this->fake->trackArr(50_000.00, 500);
            $this->fake->trackLtv(1176.00, 'user_1', 'Pro', 'payment');
            $this->fake->trackChurn('user_2', 'Starter', 19.00, 'competitor');

            $this->fake->assertTrackedTimes('mrr_movement', 1);
            $this->fake->assertTrackedTimes('arr_snapshot', 1);
            $this->fake->assertTrackedTimes('ltv_calculated', 1);
            $this->fake->assertTrackedTimes('churn', 1);

            $this->fake->assertEventSequence([
                'mrr_movement',
                'arr_snapshot',
                'ltv_calculated',
                'churn',
            ]);
        });

        test('MRR movement types across subscription lifecycle', function () {
            $this->fake->trackMrr(0.00, 'new', 'Free', 'user_1');
            $this->fake->trackMrr(49.00, 'expansion', 'Pro', 'user_1');
            $this->fake->trackMrr(99.00, 'expansion', 'Enterprise', 'user_1');
            $this->fake->trackMrr(49.00, 'contraction', 'Pro', 'user_1');
            $this->fake->trackMrr(0.00, 'churn', null, 'user_1');

            $this->fake->assertTrackedTimes('mrr_movement', 5);

            $events = $this->fake->trackedEvents();
            $mrrEvents = array_filter($events, fn (AnalyticsEvent $e) => $e->name === 'mrr_movement');
            $movements = array_map(fn (AnalyticsEvent $e) => $e->params['movement_type'], array_values($mrrEvents));

            expect($movements)->toBe(['new', 'expansion', 'expansion', 'contraction', 'churn']);
        });
    });

    // ── AnalyticsFake Proxy Coverage ───────────────────────────────────────
    describe('AnalyticsFake Proxy Methods', function () {
        test('abTestConversion proxy captures event', function () {
            $this->fake->abTestConversion('exp_1', 'v_a', 'goal_1');
            $this->fake->assertTrackedOnce('ab_test_conversion');
        });

        test('addToWishlist proxy captures event', function () {
            $this->fake->addToWishlist(['item_id' => 'X', 'price' => 10]);
            $this->fake->assertTrackedOnce('add_to_wishlist');
        });

        test('promotionView proxy captures event', function () {
            $this->fake->promotionView('P1', 'Promo');
            $this->fake->assertTrackedOnce('view_promotion');
        });

        test('trackMrr proxy captures event', function () {
            $this->fake->trackMrr(100, 'new');
            $this->fake->assertTrackedOnce('mrr_movement');
        });

        test('trackArr proxy captures event', function () {
            $this->fake->trackArr(1000, 50);
            $this->fake->assertTrackedOnce('arr_snapshot');
        });

        test('trackChurn proxy captures event', function () {
            $this->fake->trackChurn('u1', 'Pro', 99, 'reason');
            $this->fake->assertTrackedOnce('churn');
        });

        test('trackLtv proxy captures event', function () {
            $this->fake->trackLtv(500, 'u1', 'Pro', 'payment');
            $this->fake->assertTrackedOnce('ltv_calculated');
        });

        test('registerAliases proxy is no-op', function () {
            $this->fake->registerAliases(['a' => 'b']);
            expect($this->fake->getAliases())->toBe([]);
        });

        test('resolveAlias proxy returns name unchanged', function () {
            expect($this->fake->resolveAlias('any_event'))->toBe('any_event');
        });

        test('getAliases proxy returns empty array', function () {
            expect($this->fake->getAliases())->toBe([]);
        });
    });

    // ── Strict Types & Return Types ───────────────────────────────────────
    describe('Strict Types & Return Types', function () {
        test('all new methods exist on AnalyticsManager', function () {
            $manager = new AnalyticsManager;

            expect(method_exists($manager, 'abTestConversion'))->toBeTrue();
            expect(method_exists($manager, 'addToWishlist'))->toBeTrue();
            expect(method_exists($manager, 'promotionView'))->toBeTrue();
            expect(method_exists($manager, 'trackMrr'))->toBeTrue();
            expect(method_exists($manager, 'trackArr'))->toBeTrue();
            expect(method_exists($manager, 'trackChurn'))->toBeTrue();
            expect(method_exists($manager, 'trackLtv'))->toBeTrue();
            expect(method_exists($manager, 'registerAliases'))->toBeTrue();
            expect(method_exists($manager, 'resolveAlias'))->toBeTrue();
            expect(method_exists($manager, 'getAliases'))->toBeTrue();
        });

        test('return types are void for tracking methods', function () {
            $ref = new ReflectionClass(AnalyticsManager::class);

            expect($ref->getMethod('abTestConversion')->getReturnType()->getName())->toBe('void');
            expect($ref->getMethod('addToWishlist')->getReturnType()->getName())->toBe('void');
            expect($ref->getMethod('promotionView')->getReturnType()->getName())->toBe('void');
            expect($ref->getMethod('trackMrr')->getReturnType()->getName())->toBe('void');
            expect($ref->getMethod('trackArr')->getReturnType()->getName())->toBe('void');
            expect($ref->getMethod('trackChurn')->getReturnType()->getName())->toBe('void');
            expect($ref->getMethod('trackLtv')->getReturnType()->getName())->toBe('void');
            expect($ref->getMethod('registerAliases')->getReturnType()->getName())->toBe('void');
        });

        test('return types are correct for query methods', function () {
            $ref = new ReflectionClass(AnalyticsManager::class);

            expect($ref->getMethod('resolveAlias')->getReturnType()->getName())->toBe('string');
            expect($ref->getMethod('getAliases')->getReturnType()->getName())->toBe('array');
        });

        test('aliasRegistry property exists', function () {
            $ref = new ReflectionProperty(AnalyticsManager::class, 'aliasRegistry');
            expect($ref->getType()->getName())->toBe('array');
            expect($ref->isPrivate())->toBeTrue();
        });
    });
});
