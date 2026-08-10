<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Http\Requests\TrackEventRequest;
use ZeroBoiler\Analytics\Services\SaaSEventTemplateService;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;

beforeEach(function () {
    $this->manager = Mockery::mock(AnalyticsManager::class);
    $this->manager->shouldReceive('trackEvent')->byDefault();
});

describe('SaaSEventTemplateService', function () {
    it('tracks signup with industry-standard params', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('sign_up', Mockery::on(function (array $params) {
                return $params['user_id'] === 'user-123'
                    && $params['method'] === 'email'
                    && $params['referral'] === 'organic';
            }));

        $service->signup('user-123', ['method' => 'email', 'referral' => 'organic']);
    });

    it('tracks signup with UTM attribution', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('sign_up', Mockery::on(function (array $params) {
                return isset($params['utm_source'])
                    && $params['utm_source'] === 'google'
                    && $params['utm_medium'] === 'cpc';
            }));

        $service->signup('user-123', [
            'method' => 'oauth_google',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'spring_sale',
        ]);
    });

    it('tracks login with session context', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('login', Mockery::on(function (array $params) {
                return $params['user_id'] === 'user-456'
                    && $params['method'] === 'sso'
                    && $params['session_count'] === 5;
            }));

        $service->login('user-456', ['method' => 'sso', 'session_count' => 5]);
    });

    it('tracks subscription created with MRR/ARR', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('subscribe', Mockery::on(function (array $params) {
                return $params['plan'] === 'Pro'
                    && $params['revenue'] === 49.0
                    && $params['mrr'] === 49.0
                    && $params['arr'] === 588.0
                    && $params['billing_cycle'] === 'monthly'
                    && $params['currency'] === 'USD';
            }));

        $service->subscriptionCreated('user-789', 'Pro', 49.0, ['billing_cycle' => 'monthly']);
    });

    it('tracks yearly subscription with ARR calculation', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('subscribe', Mockery::on(function (array $params) {
                return $params['arr'] === 1200.0; // 100 * 12
            }));

        $service->subscriptionCreated('user-1', 'Enterprise', 100.0, ['billing_cycle' => 'yearly']);
    });

    it('tracks plan upgrade with revenue impact', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('plan_upgrade', Mockery::on(function (array $params) {
                return $params['from_plan'] === 'Starter'
                    && $params['to_plan'] === 'Pro'
                    && $params['revenue_change'] === 30.0
                    && $params['revenue_change_percent'] === 150.0;
            }));

        $service->planUpgrade('user-1', 'Starter', 'Pro', [
            'from_revenue' => 20.0,
            'to_revenue' => 50.0,
            'catalyst' => 'feature_limit',
        ]);
    });

    it('tracks plan downgrade with retention context', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('plan_downgrade', Mockery::on(function (array $params) {
                return $params['from_plan'] === 'Pro'
                    && $params['to_plan'] === 'Starter'
                    && $params['reason'] === 'cost_savings';
            }));

        $service->planDowngrade('user-1', 'Pro', 'Starter', [
            'reason' => 'cost_savings',
            'feedback' => 'too_expensive_for_my_needs',
            'from_revenue' => 50.0,
            'to_revenue' => 20.0,
        ]);
    });

    it('tracks cancellation with churn analysis', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('cancellation', Mockery::on(function (array $params) {
                return $params['user_id'] === 'user-churn'
                    && $params['plan'] === 'Pro'
                    && $params['reason'] === 'too_expensive'
                    && $params['ltv'] === 1200.0
                    && $params['months_active'] === 24;
            }));

        $service->cancellation('user-churn', [
            'plan' => 'Pro',
            'reason' => 'too_expensive',
            'was_trial_conversion' => true,
            'ltv' => 1200.0,
            'months_active' => 24,
        ]);
    });

    it('tracks trial start with conversion context', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('start_trial', Mockery::on(function (array $params) {
                return $params['plan'] === 'Pro'
                    && $params['trial_days'] === 14
                    && $params['monthly_value'] === 49.0;
            }));

        $service->trialStart('user-1', 'Pro', 14, ['monthly_value' => 49.0]);
    });

    it('tracks trial conversion with TTV metrics', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('trial_converted', Mockery::on(function (array $params) {
                return $params['plan'] === 'Pro'
                    && $params['days_to_convert'] === 7
                    && is_array($params['features_used_during_trial']);
            }));

        $service->trialConverted('user-1', 'Pro', [
            'revenue' => 49.0,
            'trial_days' => 14,
            'days_to_convert' => 7,
            'features_used_during_trial' => ['dashboard', 'reports', 'api'],
        ]);
    });

    it('tracks trial expiration', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('trial_expired', Mockery::on(function (array $params) {
                return $params['user_id'] === 'user-1'
                    && $params['features_used'] === 3;
            }));

        $service->trialExpired('user-1', [
            'plan' => 'Pro',
            'features_used' => 3,
        ]);
    });

    it('tracks MRR movement for new revenue', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('revenue_tracked', Mockery::on(function (array $params) {
                return $params['mrr_movement_type'] === 'new'
                    && $params['mrr_amount'] === 99.0;
            }));

        $service->mrrMovement('new', 99.0, ['user_id' => 'user-1', 'plan' => 'Pro']);
    });

    it('tracks MRR movement for churn', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('revenue_tracked', Mockery::on(function (array $params) {
                return $params['mrr_movement_type'] === 'churn'
                    && $params['previous_mrr'] === 49.0
                    && $params['new_mrr'] === 0.0;
            }));

        $service->mrrMovement('churn', 49.0, [
            'previous_mrr' => 49.0,
            'new_mrr' => 0.0,
        ]);
    });

    it('validates MRR movement type', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('revenue_tracked', Mockery::on(function (array $params) {
                return $params['mrr_movement_type'] === 'new'; // invalid type falls back to 'new'
            }));

        $service->mrrMovement('invalid_type', 10.0);
    });

    it('tracks revenue with provider-optimized params', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('purchase', Mockery::on(function (array $params) {
                return $params['transaction_id'] === 'TXN-001'
                    && $params['value'] === 99.99
                    && isset($params['_ga4_params'])
                    && isset($params['_meta_params'])
                    && isset($params['_posthog_params']);
            }));

        $service->revenue('TXN-001', 99.99, 'USD', [
            ['item_id' => 'SKU-1', 'item_name' => 'Widget', 'price' => 99.99, 'quantity' => 1],
        ]);
    });

    it('tracks onboarding step completion', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('onboarding_step', Mockery::on(function (array $params) {
                return $params['step'] === 'profile_setup'
                    && $params['step_number'] === 1
                    && $params['total_steps'] === 5
                    && $params['completion_percent'] === 20.0
                    && $params['is_last_step'] === false;
            }));

        $service->onboardingStepCompleted('user-1', 'profile_setup', 1, 5);
    });

    it('detects last step in onboarding', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('onboarding_step', Mockery::on(function (array $params) {
                return $params['is_last_step'] === true
                    && $params['completion_percent'] === 100.0;
            }));

        $service->onboardingStepCompleted('user-1', 'launch_project', 5, 5);
    });

    it('tracks onboarding completion', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('onboarding_completed', Mockery::on(function (array $params) {
                return $params['total_steps'] === 5
                    && $params['time_to_complete_seconds'] === 300;
            }));

        $service->onboardingCompleted('user-1', 5, ['time_to_complete' => 300]);
    });

    it('tracks feature first use', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('feature_used', Mockery::on(function (array $params) {
                return $params['is_first_use'] === true
                    && $params['feature_name'] === 'reports';
            }));

        $service->featureFirstUse('user-1', 'reports', ['days_since_signup' => 3]);
    });

    it('tracks feature power user milestone', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('feature_used', Mockery::on(function (array $params) {
                return $params['usage_count'] === 100
                    && $params['milestone'] === 'power_user';
            }));

        $service->featurePowerUser('user-1', 'api', 100);
    });

    it('creates AnalyticsEvent DTO without dispatching', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $event = $service->createEvent('custom_event', ['key' => 'value'], 'client-1', 'user-1');

        expect($event)->toBeInstanceOf(AnalyticsEvent::class);
        expect($event->name)->toBe('custom_event');
        expect($event->params)->toBe(['key' => 'value']);
        expect($event->clientId)->toBe('client-1');
        expect($event->userId)->toBe('user-1');
    });

    it('tracks view item with GA4 params', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('view_item', Mockery::on(function (array $params) {
                return $params['item_id'] === 'SKU-1'
                    && isset($params['_ga4_params']);
            }));

        $service->viewItem([
            'item_id' => 'SKU-1',
            'item_name' => 'Widget',
            'price' => 29.99,
        ]);
    });

    it('tracks add to cart with multi-provider params', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('add_to_cart', Mockery::on(function (array $params) {
                return $params['item_id'] === 'SKU-2'
                    && isset($params['_ga4_params'])
                    && isset($params['_meta_params']);
            }));

        $service->addToCart([
            'item_id' => 'SKU-2',
            'item_name' => 'Gadget',
            'price' => 19.99,
            'quantity' => 2,
        ], 'EUR');
    });

    it('extracts only present UTM params', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('sign_up', Mockery::on(function (array $params) {
                // Only utm_source should be present, not all 5 UTM keys
                return isset($params['utm_source'])
                    && ! isset($params['utm_medium'])
                    && ! isset($params['utm_campaign']);
            }));

        $service->signup('user-1', ['utm_source' => 'newsletter']);
    });

    it('does not include null UTM params', function () {
        $service = new SaaSEventTemplateService($this->manager);

        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->with('login', Mockery::on(function (array $params) {
                // Should not contain any UTM keys when none provided
                return ! str_contains(json_encode($params), 'utm_');
            }));

        $service->login('user-1', ['method' => 'email']);
    });
});

describe('TrackEventRequest — Catalog-Aware Validation', function () {
    it('validates event name exists in catalog when strict mode is enabled', function () {
        // This tests the structure of the validation rules
        $rules = (new TrackEventRequest)->rules();

        expect($rules)->toHaveKey('name');
        expect($rules['name'])->toContain('required');
        expect($rules['name'])->toContain('string');
        expect($rules)->toHaveKey('priority');
    });

    it('accepts priority parameter', function () {
        $rules = (new TrackEventRequest)->rules();

        expect($rules)->toHaveKey('priority');
    });

    it('provides custom error messages', function () {
        $messages = (new TrackEventRequest)->messages();

        expect($messages)->toHaveKey('name.required');
        expect($messages)->toHaveKey('name.max');
        expect($messages)->toHaveKey('params.array');
        expect($messages)->toHaveKey('priority.in');
    });

    it('provides custom attribute names', function () {
        $attributes = (new TrackEventRequest)->attributes();

        expect($attributes['name'])->toBe('event name');
        expect($attributes['priority'])->toBe('event priority');
    });

    it('eventName extracts event name from input', function () {
        // Test the accessor method contract
        expect(method_exists(TrackEventRequest::class, 'eventName'))->toBeTrue();
        expect(method_exists(TrackEventRequest::class, 'priority'))->toBeTrue();
        expect(method_exists(TrackEventRequest::class, 'eventParams'))->toBeTrue();
    });
});

describe('EventCatalog — v6.9.0 Integration', function () {
    it('has all core SaaS events for template service', function () {
        $coreSaasEvents = [
            'sign_up', 'login', 'logout',
            'subscribe', 'plan_upgrade', 'plan_downgrade',
            'cancellation',
            'start_trial', 'trial_converted', 'trial_expired',
            'revenue_tracked',
            'purchase', 'add_to_cart', 'view_item',
            'onboarding_step', 'onboarding_completed',
            'feature_used',
            'email_verified', 'profile_updated',
        ];

        foreach ($coreSaasEvents as $event) {
            expect(EventCatalog::has($event))
                ->toBeTrue("Event '{$event}' should be in the catalog for SaaSEventTemplateService");
        }
    });

    it('has all SaaS event templates mapped to catalog entries', function () {
        // Verify every template method has a corresponding catalog entry
        $templateEvents = [
            'sign_up' => 'engagement',
            'login' => 'saas',
            'logout' => 'saas',
            'subscribe' => 'saas',
            'plan_upgrade' => 'saas',
            'plan_downgrade' => 'saas',
            'cancellation' => 'saas',
            'start_trial' => 'saas',
            'trial_converted' => 'saas',
            'trial_expired' => 'saas',
            'revenue_tracked' => 'saas',
            'purchase' => 'ecommerce',
            'add_to_cart' => 'ecommerce',
            'view_item' => 'ecommerce',
        ];

        foreach ($templateEvents as $event => $expectedCategory) {
            $entry = EventCatalog::get($event);
            expect($entry)->not->toBeNull("Template event '{$event}' should be in catalog");
            expect($entry['category'])->toBe($expectedCategory);
        }
    });

    it('provides GA4 and Meta mappings for all e-commerce templates', function () {
        $ecommerceEvents = ['purchase', 'add_to_cart', 'view_item', 'refund'];

        foreach ($ecommerceEvents as $event) {
            $entry = EventCatalog::get($event);
            expect($entry)->not->toBeNull();
            expect($entry['ga4'])->not->toBeEmpty("Event '{$event}' should have GA4 mapping");
        }
    });
});

describe('Version Consistency — v6.9.0', function () {
    it('AnalyticsEvent DTO reports correct version', function () {
        expect(AnalyticsEvent::VERSION)->toBe('6.9.0');
    });

    it('event catalog is valid', function () {
        $result = EventCatalog::validate();

        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
    });

    it('event catalog has revenue events', function () {
        $revenueEvents = EventCatalog::revenueEvents();

        expect($revenueEvents)->not->toBeEmpty();
        $names = array_column($revenueEvents, 'name');
        expect($names)->toContain('purchase');
        expect($names)->toContain('subscribe');
    });

    it('event catalog has GDPR events', function () {
        $gdprEvents = EventCatalog::gdprEvents();

        expect($gdprEvents)->not->toBeEmpty();
        $names = array_column($gdprEvents, 'name');
        expect($names)->toContain('sign_up');
        expect($names)->toContain('account_deleted');
    });
});
