<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Support\SaaSFormatConverter;

describe('SaaSFormatConverter', function () {
    describe('sign_up conversion', function () {
        it('converts sign_up to Meta CompleteRegistration format', function () {
            $params = [
                'method' => 'email',
                'source' => 'organic',
                'value' => 0.0,
                'currency' => 'USD',
            ];

            $meta = SaaSFormatConverter::signUpToMeta($params);

            expect($meta)->toBeArray()
                ->and($meta['status'])->toBe('completed')
                ->and($meta['value'])->toBe(0.0)
                ->and($meta['currency'])->toBe('USD')
                ->and($meta['method'])->toBe('email')
                ->and($meta['predicted_ltv'])->toBeNull();
        });

        it('converts sign_up with predicted_ltv to Meta format', function () {
            $params = [
                'method' => 'google_sso',
                'predicted_ltv' => 2400.0,
                'currency' => 'EUR',
            ];

            $meta = SaaSFormatConverter::signUpToMeta($params);

            expect($meta['predicted_ltv'])->toBe(2400.0)
                ->and($meta['currency'])->toBe('EUR')
                ->and($meta['method'])->toBe('google_sso');
        });

        it('converts sign_up to PostHog properties', function () {
            $params = [
                'method' => 'github',
                'source' => 'developer_marketing',
                'plan' => 'pro',
                'referral_code' => 'FRIEND2024',
            ];

            $posthog = SaaSFormatConverter::signUpToPosthog($params);

            expect($posthog)->toBeArray()
                ->and($posthog['signup_method'])->toBe('github')
                ->and($posthog['signup_source'])->toBe('developer_marketing')
                ->and($posthog['plan'])->toBe('pro')
                ->and($posthog['$signup_code'])->toBe('FRIEND2024')
                ->and($posthog['is_paid'])->toBeFalse();
        });

        it('converts sign_up to GA4 format', function () {
            $params = [
                'method' => 'email',
                'value' => 0.0,
                'currency' => 'USD',
                'referral_code' => 'WELCOME10',
            ];

            $ga4 = SaaSFormatConverter::signUpToGa4($params);

            expect($ga4)->toBeArray()
                ->and($ga4['method'])->toBe('email')
                ->and($ga4['value'])->toBe(0.0)
                ->and($ga4['currency'])->toBe('USD')
                ->and($ga4['coupon'])->toBe('WELCOME10');
        });
    });

    describe('login conversion', function () {
        it('converts login to Meta format', function () {
            $params = ['method' => 'email'];

            $meta = SaaSFormatConverter::loginToMeta($params);

            expect($meta['method'])->toBe('email')
                ->and($meta['content_name'])->toBe('login');
        });

        it('converts login to PostHog properties', function () {
            $params = [
                'method' => 'sso',
                'source' => 'dashboard',
                'is_first_login' => true,
            ];

            $posthog = SaaSFormatConverter::loginToPosthog($params);

            expect($posthog['login_method'])->toBe('sso')
                ->and($posthog['login_source'])->toBe('dashboard')
                ->and($posthog['is_first_login'])->toBeTrue();
        });
    });

    describe('trial_start conversion', function () {
        it('converts trial_start to Meta StartTrial format', function () {
            $params = [
                'value' => 0.0,
                'currency' => 'USD',
                'trial_days' => 14,
                'plan' => 'pro',
                'predicted_ltv' => 960.0,
            ];

            $meta = SaaSFormatConverter::trialStartToMeta($params);

            expect($meta['value'])->toBe(0.0)
                ->and($meta['trial_days'])->toBe(14)
                ->and($meta['plan'])->toBe('pro')
                ->and($meta['predicted_ltv'])->toBe(960.0)
                ->and($meta['content_name'])->toBe('start_trial');
        });

        it('converts trial_start to PostHog properties', function () {
            $params = [
                'trial_days' => 14,
                'plan' => 'enterprise',
                'value' => 0.0,
                'currency' => 'USD',
            ];

            $posthog = SaaSFormatConverter::trialStartToPosthog($params);

            expect($posthog['trial_days'])->toBe(14)
                ->and($posthog['plan'])->toBe('enterprise')
                ->and($posthog['trial_value'])->toBe(0.0)
                ->and($posthog['trial_currency'])->toBe('USD');
        });

        it('converts trial_start to GA4 format', function () {
            $params = [
                'method' => 'email',
                'value' => 0.0,
                'currency' => 'USD',
                'trial_days' => 14,
                'plan' => 'pro',
            ];

            $ga4 = SaaSFormatConverter::trialStartToGa4($params);

            expect($ga4['method'])->toBe('email')
                ->and($ga4['trial_days'])->toBe(14)
                ->and($ga4['plan'])->toBe('pro')
                ->and($ga4['coupon'])->toBeNull();
        });
    });

    describe('subscription conversion', function () {
        it('converts subscription to Meta Subscribe format', function () {
            $params = [
                'value' => 49.99,
                'currency' => 'USD',
                'plan' => 'pro',
                'billing_cycle' => 'monthly',
                'subscription_id' => 'sub_12345',
            ];

            $meta = SaaSFormatConverter::subscriptionToMeta($params);

            expect($meta['value'])->toBe(49.99)
                ->and($meta['currency'])->toBe('USD')
                ->and($meta['plan'])->toBe('pro')
                ->and($meta['billing_cycle'])->toBe('monthly')
                ->and($meta['is_trial'])->toBeFalse();
        });

        it('converts subscription with revenue key to Meta format', function () {
            $params = [
                'revenue' => 99.00,
                'currency' => 'EUR',
                'plan' => 'enterprise',
            ];

            $meta = SaaSFormatConverter::subscriptionToMeta($params);

            expect($meta['value'])->toBe(99.00)
                ->and($meta['currency'])->toBe('EUR');
        });

        it('converts subscription to PostHog properties', function () {
            $params = [
                'value' => 49.99,
                'currency' => 'USD',
                'plan' => 'pro',
                'billing_cycle' => 'monthly',
                'subscription_id' => 'sub_12345',
                'is_trial' => true,
            ];

            $posthog = SaaSFormatConverter::subscriptionToPosthog($params);

            expect($posthog['value'])->toBe(49.99)
                ->and($posthog['plan'])->toBe('pro')
                ->and($posthog['billing_cycle'])->toBe('monthly')
                ->and($posthog['subscription_id'])->toBe('sub_12345')
                ->and($posthog['is_trial'])->toBeTrue();
        });

        it('converts subscription to GA4 purchase-like format', function () {
            $params = [
                'value' => 49.99,
                'currency' => 'USD',
                'plan' => 'pro',
                'plan_name' => 'Pro Plan',
                'subscription_id' => 'sub_12345',
            ];

            $ga4 = SaaSFormatConverter::subscriptionToGa4($params);

            expect($ga4['transaction_id'])->toBe('sub_12345')
                ->and($ga4['value'])->toBe(49.99)
                ->and($ga4['currency'])->toBe('USD')
                ->and($ga4['items'])->toBeArray()
                ->and($ga4['items'])->toHaveCount(1)
                ->and($ga4['items'][0]['item_id'])->toBe('pro')
                ->and($ga4['items'][0]['item_name'])->toBe('Pro Plan')
                ->and($ga4['items'][0]['price'])->toBe(49.99);
        });
    });

    describe('plan_upgrade conversion', function () {
        it('converts plan_upgrade to Meta format', function () {
            $params = [
                'from_plan' => 'starter',
                'to_plan' => 'pro',
                'value' => 30.0,
                'currency' => 'USD',
                'upgrade_type' => 'manual',
            ];

            $meta = SaaSFormatConverter::planUpgradeToMeta($params);

            expect($meta['from_plan'])->toBe('starter')
                ->and($meta['to_plan'])->toBe('pro')
                ->and($meta['value'])->toBe(30.0)
                ->and($meta['upgrade_type'])->toBe('manual');
        });

        it('converts plan_upgrade with previous_plan/new_plan aliases', function () {
            $params = [
                'previous_plan' => 'starter',
                'new_plan' => 'enterprise',
                'revenue_delta' => 80.0,
                'currency' => 'USD',
            ];

            $meta = SaaSFormatConverter::planUpgradeToMeta($params);

            expect($meta['from_plan'])->toBe('starter')
                ->and($meta['to_plan'])->toBe('enterprise')
                ->and($meta['value'])->toBe(80.0);
        });

        it('converts plan_upgrade to PostHog properties', function () {
            $params = [
                'from_plan' => 'starter',
                'to_plan' => 'pro',
                'value' => 30.0,
                'currency' => 'USD',
            ];

            $posthog = SaaSFormatConverter::planUpgradeToPosthog($params);

            expect($posthog['from_plan'])->toBe('starter')
                ->and($posthog['to_plan'])->toBe('pro')
                ->and($posthog['value_delta'])->toBe(30.0)
                ->and($posthog['currency'])->toBe('USD');
        });

        it('converts plan_upgrade to GA4 format with items', function () {
            $params = [
                'previous_plan' => 'starter',
                'new_plan' => 'enterprise',
                'value' => 180.0,
                'currency' => 'USD',
            ];

            $ga4 = SaaSFormatConverter::planUpgradeToGa4($params);

            expect($ga4['from_plan'])->toBe('starter')
                ->and($ga4['to_plan'])->toBe('enterprise')
                ->and($ga4['items'][0]['item_id'])->toBe('enterprise')
                ->and($ga4['items'][0]['price'])->toBe(180.0)
                ->and($ga4['items'][0]['quantity'])->toBe(1);
        });
    });

    describe('cancellation conversion', function () {
        it('converts cancellation to Meta format', function () {
            $params = [
                'plan' => 'pro',
                'reason' => 'too_expensive',
                'cancellation_type' => 'immediate',
                'lost_revenue' => 49.99,
                'currency' => 'USD',
            ];

            $meta = SaaSFormatConverter::cancellationToMeta($params);

            expect($meta['plan'])->toBe('pro')
                ->and($meta['reason'])->toBe('too_expensive')
                ->and($meta['cancellation_type'])->toBe('immediate')
                ->and($meta['value'])->toBe(49.99);
        });

        it('converts cancellation to PostHog properties', function () {
            $params = [
                'plan' => 'enterprise',
                'reason' => 'switched_competitor',
                'lost_revenue' => 199.0,
                'currency' => 'USD',
                'tenure_days' => 365,
                'nps_before' => 7,
            ];

            $posthog = SaaSFormatConverter::cancellationToPosthog($params);

            expect($posthog['plan'])->toBe('enterprise')
                ->and($posthog['reason'])->toBe('switched_competitor')
                ->and($posthog['lost_mrr'])->toBe(199.0)
                ->and($posthog['tenure_days'])->toBe(365)
                ->and($posthog['nps_before'])->toBe(7);
        });

        it('converts cancellation to GA4 format', function () {
            $params = [
                'plan' => 'pro',
                'reason' => 'missing_features',
                'lost_revenue' => 49.99,
                'currency' => 'USD',
            ];

            $ga4 = SaaSFormatConverter::cancellationToGa4($params);

            expect($ga4['plan'])->toBe('pro')
                ->and($ga4['reason'])->toBe('missing_features')
                ->and($ga4['value'])->toBe(49.99)
                ->and($ga4['currency'])->toBe('USD');
        });
    });

    describe('generic convertForProvider', function () {
        it('routes sign_up to correct provider converters', function () {
            $params = ['method' => 'email', 'value' => 0.0, 'currency' => 'USD'];

            $meta = SaaSFormatConverter::convertForProvider('sign_up', $params, 'meta');
            $ga4 = SaaSFormatConverter::convertForProvider('sign_up', $params, 'ga4');
            $posthog = SaaSFormatConverter::convertForProvider('sign_up', $params, 'posthog');

            expect($meta['status'])->toBe('completed')
                ->and($ga4['method'])->toBe('email')
                ->and($posthog['signup_method'])->toBe('email');
        });

        it('routes trial_start to correct provider converters', function () {
            $params = ['trial_days' => 14, 'plan' => 'pro'];

            $meta = SaaSFormatConverter::convertForProvider('start_trial', $params, 'meta');

            expect($meta['trial_days'])->toBe(14)
                ->and($meta['plan'])->toBe('pro');
        });

        it('passes through unknown events unchanged', function () {
            $params = ['custom' => 'value'];

            $result = SaaSFormatConverter::convertForProvider('custom_event', $params, 'meta');

            expect($result)->toBe($params);
        });
    });

    describe('buildProviderEvent', function () {
        it('builds a Meta-optimized sign_up AnalyticsEvent', function () {
            $event = SaaSFormatConverter::buildProviderEvent(
                'sign_up',
                ['method' => 'email', 'value' => 0.0],
                'meta',
                'client-123',
                'user-456',
            );

            expect($event)->toBeInstanceOf(AnalyticsEvent::class)
                ->and($event->name)->toBe('sign_up')
                ->and($event->clientId)->toBe('client-123')
                ->and($event->userId)->toBe('user-456')
                ->and($event->category)->toBe('saas')
                ->and($event->params['status'])->toBe('completed');
        });

        it('builds a GA4-optimized subscription AnalyticsEvent', function () {
            $event = SaaSFormatConverter::buildProviderEvent(
                'subscribe',
                ['value' => 99.0, 'currency' => 'USD', 'plan' => 'pro', 'subscription_id' => 'sub_1'],
                'ga4',
            );

            expect($event->params['transaction_id'])->toBe('sub_1')
                ->and($event->params['items'])->toHaveCount(1);
        });
    });

    describe('PostHog user properties', function () {
        it('builds $set properties for sign_up', function () {
            $props = SaaSFormatConverter::posthogUserProperties('sign_up', [
                'email' => 'user@example.com',
                'name' => 'Jane Doe',
                'method' => 'github',
                'plan' => 'pro',
            ]);

            expect($props['email'])->toBe('user@example.com')
                ->and($props['name'])->toBe('Jane Doe')
                ->and($props['plan'])->toBe('pro')
                ->and($props['signup_method'])->toBe('github')
                ->and($props['signup_date'])->toBe(date('Y-m-d'));
        });

        it('builds $set properties for subscription', function () {
            $props = SaaSFormatConverter::posthogUserProperties('subscription', [
                'plan' => 'enterprise',
                'billing_cycle' => 'annual',
                'value' => 199.0,
            ]);

            expect($props['plan'])->toBe('enterprise')
                ->and($props['subscription_status'])->toBe('active')
                ->and($props['billing_cycle'])->toBe('annual')
                ->and($props['mrr'])->toBe(199.0);
        });

        it('builds $set properties for cancellation', function () {
            $props = SaaSFormatConverter::posthogUserProperties('cancellation', [
                'reason' => 'too_expensive',
                'plan' => 'pro',
            ]);

            expect($props['subscription_status'])->toBe('cancelled')
                ->and($props['cancellation_reason'])->toBe('too_expensive')
                ->and($props['cancelled_plan'])->toBe('pro');
        });

        it('builds empty properties for unknown events', function () {
            $props = SaaSFormatConverter::posthogUserProperties('unknown_event', []);

            expect($props)->toBeArray()->toBeEmpty();
        });
    });

    describe('GA4 user properties', function () {
        it('builds user_properties for sign_up', function () {
            $props = SaaSFormatConverter::ga4UserProperties('sign_up', [
                'user_id' => 'usr_1',
                'method' => 'email',
            ]);

            expect($props['user_id'])->toBe('usr_1')
                ->and($props['signup_method'])->toBe('email')
                ->and($props['user_type'])->toBe('registered');
        });

        it('builds user_properties for subscription', function () {
            $props = SaaSFormatConverter::ga4UserProperties('subscribe', [
                'plan' => 'pro',
                'billing_cycle' => 'monthly',
            ]);

            expect($props['subscription_status'])->toBe('active')
                ->and($props['plan'])->toBe('pro')
                ->and($props['billing_cycle'])->toBe('monthly');
        });

        it('builds user_properties for cancellation', function () {
            $props = SaaSFormatConverter::ga4UserProperties('cancellation', [
                'reason' => 'missing_features',
            ]);

            expect($props['subscription_status'])->toBe('cancelled')
                ->and($props['cancellation_reason'])->toBe('missing_features');
        });
    });

    describe('buildRevenueParams', function () {
        it('builds GA4 revenue params', function () {
            $params = SaaSFormatConverter::buildRevenueParams(
                'ga4', 49.99, 'USD', 'pro', 'monthly', 'sub_123'
            );

            expect($params['value'])->toBe(49.99)
                ->and($params['currency'])->toBe('USD')
                ->and($params['transaction_id'])->toBe('sub_123')
                ->and($params['items'][0]['item_id'])->toBe('pro');
        });

        it('builds Meta revenue params', function () {
            $params = SaaSFormatConverter::buildRevenueParams(
                'meta', 99.0, 'EUR', 'enterprise'
            );

            expect($params['value'])->toBe(99.0)
                ->and($params['currency'])->toBe('EUR')
                ->and($params['plan'])->toBe('enterprise')
                ->and($params['content_name'])->toBe('revenue');
        });

        it('builds PostHog revenue params', function () {
            $params = SaaSFormatConverter::buildRevenueParams(
                'posthog', 199.0, 'USD', 'enterprise', 'annual', 'sub_456'
            );

            expect($params['revenue'])->toBe(199.0)
                ->and($params['$currency'])->toBe('USD')
                ->and($params['subscription_id'])->toBe('sub_456')
                ->and($params['billing_cycle'])->toBe('annual');
        });
    });
});
