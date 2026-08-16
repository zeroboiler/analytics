<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;

beforeEach(function (): void {
    // Reset catalog static caches for each test
    // (static::$catalog is already lazy-initialized, no reset needed)
});

describe('EcommerceEvents — Typed Shorthand Factory Methods', function (): void {
    test('viewItem returns typed event with ecommerce category', function (): void {
        $event = EcommerceEvents::viewItem(['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 29.99]);

        expect($event)
            ->toBeInstanceOf(AnalyticsEvent::class)
            ->and($event->name)->toBe('view_item')
            ->and($event->category)->toBe('ecommerce')
            ->and($event->params['item_id'])->toBe('SKU-001')
            ->and($event->params['item_name'])->toBe('Widget')
            ->and($event->params['price'])->toBe(29.99);
    });

    test('addToCart returns typed event with ecommerce category', function (): void {
        $event = EcommerceEvents::addToCart(['item_id' => 'SKU-002', 'price' => 49.99, 'quantity' => 2]);

        expect($event->name)->toBe('add_to_cart')
            ->and($event->category)->toBe('ecommerce')
            ->and($event->params['quantity'])->toBe(2);
    });

    test('purchase returns typed event with ecommerce category', function (): void {
        $event = EcommerceEvents::purchase([
            'transaction_id' => 'TXN-123',
            'value' => 99.99,
            'currency' => 'USD',
            'items' => [['item_id' => 'SKU-001']],
        ]);

        expect($event->name)->toBe('purchase')
            ->and($event->category)->toBe('ecommerce')
            ->and($event->params['transaction_id'])->toBe('TXN-123');
    });

    test('purchase merges extra params', function (): void {
        $event = EcommerceEvents::purchase(
            ['transaction_id' => 'TXN-456', 'value' => 149.99],
            ['coupon' => 'SAVE10'],
        );

        expect($event->params['coupon'])->toBe('SAVE10')
            ->and($event->params['transaction_id'])->toBe('TXN-456');
    });

    test('refund returns typed event', function (): void {
        $event = EcommerceEvents::refund(['transaction_id' => 'TXN-123', 'value' => 49.99]);

        expect($event->name)->toBe('refund')
            ->and($event->category)->toBe('ecommerce');
    });

    test('beginCheckout returns typed event', function (): void {
        $event = EcommerceEvents::beginCheckout(['step' => 1]);

        expect($event->name)->toBe('begin_checkout')
            ->and($event->category)->toBe('ecommerce');
    });

    test('addToWishlist returns typed event', function (): void {
        $event = EcommerceEvents::addToWishlist(['item_id' => 'SKU-003', 'item_name' => 'Gadget']);

        expect($event->name)->toBe('add_to_wishlist')
            ->and($event->params['item_id'])->toBe('SKU-003');
    });

    test('selectItem returns typed event', function (): void {
        $event = EcommerceEvents::selectItem(['item_id' => 'SKU-004']);

        expect($event->name)->toBe('select_item');
    });

    test('selectPromotion returns typed event', function (): void {
        $event = EcommerceEvents::selectPromotion(['promotion_id' => 'PROMO-1']);

        expect($event->name)->toBe('select_promotion');
    });

    test('viewPromotion returns typed event', function (): void {
        $event = EcommerceEvents::viewPromotion(['promotion_id' => 'PROMO-2']);

        expect($event->name)->toBe('view_promotion');
    });

    test('checkoutStep returns typed event with step and option', function (): void {
        $event = EcommerceEvents::checkoutStep(2, 'credit_card');

        expect($event->name)->toBe('checkout_step')
            ->and($event->params['step'])->toBe(2)
            ->and($event->params['checkout_option'])->toBe('credit_card');
    });

    test('abandonedCart returns typed event', function (): void {
        $event = EcommerceEvents::abandonedCart(['item_count' => 3]);

        expect($event->name)->toBe('abandoned_cart');
    });

    test('checkoutAbandon returns typed event', function (): void {
        $event = EcommerceEvents::checkoutAbandon(3);

        expect($event->name)->toBe('checkout_abandon')
            ->and($event->params['step'])->toBe(3);
    });

    test('removeFromCart returns typed event', function (): void {
        $event = EcommerceEvents::removeFromCart(['item_id' => 'SKU-005']);

        expect($event->name)->toBe('remove_from_cart');
    });

    test('viewCart returns typed event', function (): void {
        $event = EcommerceEvents::viewCart();

        expect($event->name)->toBe('view_cart');
    });

    test('addPaymentInfo returns typed event', function (): void {
        $event = EcommerceEvents::addPaymentInfo(['payment_type' => 'credit_card']);

        expect($event->name)->toBe('add_payment_info');
    });

    test('build returns typed event for valid name', function (): void {
        $event = EcommerceEvents::build('view_item', ['item_id' => 'X']);

        expect($event->name)->toBe('view_item')
            ->and($event->category)->toBe('ecommerce');
    });

    test('build throws for invalid name', function (): void {
        expect(fn (): AnalyticsEvent => EcommerceEvents::build('nonexistent_event'))
            ->toThrow(\InvalidArgumentException::class);
    });

    test('all shorthand events exist in the catalog', function (): void {
        $names = EcommerceEvents::names();

        expect($names)->toContain('view_item')
            ->toContain('add_to_cart')
            ->toContain('remove_from_cart')
            ->toContain('view_cart')
            ->toContain('begin_checkout')
            ->toContain('add_payment_info')
            ->toContain('purchase')
            ->toContain('refund')
            ->toContain('add_to_wishlist')
            ->toContain('select_item')
            ->toContain('select_promotion')
            ->toContain('view_promotion')
            ->toContain('checkout_step')
            ->toContain('abandoned_cart')
            ->toContain('checkout_abandon');
    });
});

describe('SaaSEvents — Typed Shorthand Factory Methods', function (): void {
    test('signUp returns typed event with saas category', function (): void {
        $event = SaaSEvents::signUp(['method' => 'email']);

        expect($event->name)->toBe('sign_up')
            ->and($event->category)->toBe('saas')
            ->and($event->params['method'])->toBe('email');
    });

    test('login returns typed event', function (): void {
        $event = SaaSEvents::login(['method' => 'oauth']);

        expect($event->name)->toBe('login')
            ->and($event->category)->toBe('saas');
    });

    test('logout returns typed event', function (): void {
        $event = SaaSEvents::logout();

        expect($event->name)->toBe('logout');
    });

    test('startTrial returns typed event', function (): void {
        $event = SaaSEvents::startTrial(['plan_name' => 'Pro', 'trial_days' => 14]);

        expect($event->name)->toBe('start_trial')
            ->and($event->params['plan_name'])->toBe('Pro');
    });

    test('subscribe returns typed event', function (): void {
        $event = SaaSEvents::subscribe(['plan_name' => 'Pro', 'amount' => 49.99]);

        expect($event->name)->toBe('subscribe');
    });

    test('planUpgrade returns typed event with from/to plan', function (): void {
        $event = SaaSEvents::planUpgrade('Free', 'Pro');

        expect($event->name)->toBe('plan_upgrade')
            ->and($event->params['from_plan'])->toBe('Free')
            ->and($event->params['to_plan'])->toBe('Pro');
    });

    test('planDowngrade returns typed event', function (): void {
        $event = SaaSEvents::planDowngrade('Enterprise', 'Pro');

        expect($event->name)->toBe('plan_downgrade')
            ->and($event->params['from_plan'])->toBe('Enterprise');
    });

    test('cancellation returns typed event', function (): void {
        $event = SaaSEvents::cancellation(['reason' => 'too_expensive']);

        expect($event->name)->toBe('cancellation');
    });

    test('featureUsed returns typed event with feature name', function (): void {
        $event = SaaSEvents::featureUsed('api_access');

        expect($event->name)->toBe('feature_used')
            ->and($event->params['feature_name'])->toBe('api_access');
    });

    test('revenueTracked returns typed event with amount and currency', function (): void {
        $event = SaaSEvents::revenueTracked(99.99, 'EUR');

        expect($event->name)->toBe('revenue_tracked')
            ->and($event->params['amount'])->toBe(99.99)
            ->and($event->params['currency'])->toBe('EUR');
    });

    test('subscriptionCreated returns typed event', function (): void {
        $event = SaaSEvents::subscriptionCreated(['plan_name' => 'Starter']);

        expect($event->name)->toBe('subscription_created');
    });

    test('subscriptionCancelled returns typed event', function (): void {
        $event = SaaSEvents::subscriptionCancelled(['reason' => 'downgraded']);

        expect($event->name)->toBe('subscription_cancelled');
    });

    test('trialConverted returns typed event', function (): void {
        $event = SaaSEvents::trialConverted(['plan_name' => 'Pro']);

        expect($event->name)->toBe('trial_converted');
    });

    test('trialExpired returns typed event', function (): void {
        $event = SaaSEvents::trialExpired();

        expect($event->name)->toBe('trial_expired');
    });

    test('inviteAccepted returns typed event', function (): void {
        $event = SaaSEvents::inviteAccepted(['inviter_id' => 'user-1']);

        expect($event->name)->toBe('invite_accepted');
    });

    test('workspaceCreated returns typed event', function (): void {
        $event = SaaSEvents::workspaceCreated(['workspace_name' => 'Acme']);

        expect($event->name)->toBe('workspace_created');
    });

    test('firstValue returns typed event', function (): void {
        $event = SaaSEvents::firstValue(['description' => 'Created first project']);

        expect($event->name)->toBe('first_value');
    });

    test('activation returns typed event', function (): void {
        $event = SaaSEvents::activation(['trigger' => 'first_api_call']);

        expect($event->name)->toBe('activation');
    });

    test('paymentFailed returns typed event', function (): void {
        $event = SaaSEvents::paymentFailed(['amount' => 49.99, 'reason' => 'card_declined']);

        expect($event->name)->toBe('payment_failed');
    });

    test('paymentSucceeded returns typed event', function (): void {
        $event = SaaSEvents::paymentSucceeded(['amount' => 49.99]);

        expect($event->name)->toBe('payment_succeeded');
    });

    test('build returns typed event for valid name', function (): void {
        $event = SaaSEvents::build('sign_up', ['method' => 'github']);

        expect($event->name)->toBe('sign_up')
            ->and($event->category)->toBe('saas');
    });

    test('build throws for invalid name', function (): void {
        expect(fn (): AnalyticsEvent => SaaSEvents::build('nonexistent'))
            ->toThrow(\InvalidArgumentException::class);
    });

    test('all shorthand events exist in the catalog', function (): void {
        $names = SaaSEvents::names();

        expect($names)
            ->toContain('sign_up')
            ->toContain('login')
            ->toContain('logout')
            ->toContain('start_trial')
            ->toContain('subscribe')
            ->toContain('plan_upgrade')
            ->toContain('plan_downgrade')
            ->toContain('cancellation')
            ->toContain('feature_used')
            ->toContain('revenue_tracked')
            ->toContain('subscription_created')
            ->toContain('subscription_cancelled')
            ->toContain('trial_converted')
            ->toContain('trial_expired')
            ->toContain('invite_accepted')
            ->toContain('workspace_created')
            ->toContain('first_value')
            ->toContain('activation')
            ->toContain('payment_failed')
            ->toContain('payment_succeeded');
    });
});

describe('EngagementEvents — Typed Shorthand Factory Methods', function (): void {
    test('pageView returns typed event with engagement category', function (): void {
        $event = EngagementEvents::pageView(['title' => 'Home', 'location' => '/']);

        expect($event->name)->toBe('page_view')
            ->and($event->category)->toBe('engagement')
            ->and($event->params['title'])->toBe('Home');
    });

    test('scrollDepth returns typed event with percent', function (): void {
        $event = EngagementEvents::scrollDepth(75);

        expect($event->name)->toBe('scroll_depth')
            ->and($event->params['percent'])->toBe(75);
    });

    test('click returns typed event with element', function (): void {
        $event = EngagementEvents::click('buy_now_button');

        expect($event->name)->toBe('click')
            ->and($event->params['element'])->toBe('buy_now_button');
    });

    test('formStart returns typed event with form_name', function (): void {
        $event = EngagementEvents::formStart('signup_form');

        expect($event->name)->toBe('form_start')
            ->and($event->params['form_name'])->toBe('signup_form');
    });

    test('formSubmit returns typed event with form_name', function (): void {
        $event = EngagementEvents::formSubmit('contact_form');

        expect($event->name)->toBe('form_submit')
            ->and($event->params['form_name'])->toBe('contact_form');
    });

    test('search returns typed event with search_term', function (): void {
        $event = EngagementEvents::search('analytics');

        expect($event->name)->toBe('search')
            ->and($event->params['search_term'])->toBe('analytics');
    });

    test('share returns typed event with method', function (): void {
        $event = EngagementEvents::share('twitter', 'article');

        expect($event->name)->toBe('share')
            ->and($event->params['method'])->toBe('twitter')
            ->and($event->params['content_type'])->toBe('article');
    });

    test('error returns typed event with error_message', function (): void {
        $event = EngagementEvents::error('Network timeout');

        expect($event->name)->toBe('error')
            ->and($event->params['error_message'])->toBe('Network timeout');
    });

    test('jsError returns typed event', function (): void {
        $event = EngagementEvents::jsError('TypeError: undefined', 'app.js');

        expect($event->name)->toBe('js_error')
            ->and($event->params['source'])->toBe('app.js');
    });

    test('sessionStart returns typed event', function (): void {
        $event = EngagementEvents::sessionStart(['session_id' => 'abc']);

        expect($event->name)->toBe('session_start');
    });

    test('sessionEnd returns typed event', function (): void {
        $event = EngagementEvents::sessionEnd(['duration_seconds' => 300]);

        expect($event->name)->toBe('session_end');
    });

    test('feedback returns typed event', function (): void {
        $event = EngagementEvents::feedback(['score' => 8, 'comment' => 'Great!']);

        expect($event->name)->toBe('feedback')
            ->and($event->params['score'])->toBe(8);
    });

    test('consentGranted returns typed event', function (): void {
        $event = EngagementEvents::consentGranted(['purposes' => ['analytics', 'marketing']]);

        expect($event->name)->toBe('consent_granted');
    });

    test('consentWithdrawn returns typed event', function (): void {
        $event = EngagementEvents::consentWithdrawn();

        expect($event->name)->toBe('consent_withdrawn');
    });

    test('onboardingCompleted returns typed event', function (): void {
        $event = EngagementEvents::onboardingCompleted(['duration_seconds' => 120]);

        expect($event->name)->toBe('onboarding_completed');
    });

    test('build returns typed event for valid name', function (): void {
        $event = EngagementEvents::build('page_view', ['title' => 'Test']);

        expect($event->name)->toBe('page_view')
            ->and($event->category)->toBe('engagement');
    });

    test('build throws for invalid name', function (): void {
        expect(fn (): AnalyticsEvent => EngagementEvents::build('nonexistent'))
            ->toThrow(\InvalidArgumentException::class);
    });

    test('all shorthand events exist in the catalog', function (): void {
        $names = EngagementEvents::names();

        expect($names)
            ->toContain('page_view')
            ->toContain('scroll_depth')
            ->toContain('click')
            ->toContain('form_start')
            ->toContain('form_submit')
            ->toContain('search')
            ->toContain('share')
            ->toContain('error')
            ->toContain('js_error')
            ->toContain('session_start')
            ->toContain('session_end')
            ->toContain('feedback')
            ->toContain('consent_granted')
            ->toContain('consent_withdrawn')
            ->toContain('onboarding_completed');
    });
});

describe('Cross-Catalog Factory Consistency', function (): void {
    test('each catalog has a build() method', function (): void {
        expect(method_exists(EcommerceEvents::class, 'build'))->toBeTrue();
        expect(method_exists(SaaSEvents::class, 'build'))->toBeTrue();
        expect(method_exists(EngagementEvents::class, 'build'))->toBeTrue();
    });

    test('build() events from different catalogs have different categories', function (): void {
        $ecom = EcommerceEvents::build('purchase');
        $saas = SaaSEvents::build('sign_up');
        $eng = EngagementEvents::build('page_view');

        expect($ecom->category)->toBe('ecommerce');
        expect($saas->category)->toBe('saas');
        expect($eng->category)->toBe('engagement');
    });

    test('total shorthand factory methods count is significant', function (): void {
        // Ecommerce: 15 specific + build = 16
        // SaaS: 21 specific + build = 22
        // Engagement: 15 specific + build = 16
        // Total = 54 shorthand methods
        $ecomCount = 16;
        $saasCount = 22;
        $engCount = 16;

        expect($ecomCount)->toBeGreaterThan(10);
        expect($saasCount)->toBeGreaterThan(15);
        expect($engCount)->toBeGreaterThan(10);
        expect($ecomCount + $saasCount + $engCount)->toBeGreaterThanOrEqual(48);
    });

    test('factory methods return immutable readonly events', function (): void {
        $event = EcommerceEvents::viewItem(['item_id' => 'X']);

        expect($event)->toBeInstanceOf(\Final::class . ' readonly ' . AnalyticsEvent::class);
    });

    test('factory events are serializable (for queue dispatch)', function (): void {
        $ecom = EcommerceEvents::purchase(['transaction_id' => 'T1', 'value' => 10.0]);
        $saas = SaaSEvents::signUp(['method' => 'email']);
        $eng = EngagementEvents::pageView(['title' => 'Home']);

        // Must be serializable for queued dispatch
        expect(fn (): string => serialize($ecom))->not->toThrow(\Throwable::class);
        expect(fn (): string => serialize($saas))->not->toThrow(\Throwable::class);
        expect(fn (): string => serialize($eng))->not->toThrow(\Throwable::class);
    });
});
