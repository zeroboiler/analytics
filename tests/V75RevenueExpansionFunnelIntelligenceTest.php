<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\DTO\EventPriority;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionValueChangedEvent;
use ZeroBoiler\Analytics\Events\SaaS\UsageQuotaReachedEvent;
use ZeroBoiler\Analytics\Events\SaaS\BillingRetryEvent;
use ZeroBoiler\Analytics\Tracking\ServerSideTracker;

describe('v2.75.0 — Revenue Movement, Expansion Signals & Funnel Intelligence', function () {
    describe('Version consistency', function () {
        it('AnalyticsEvent VERSION is 2.75.0', function () {
            expect(AnalyticsEvent::VERSION)->toBe('74.0.0');
        });

        it('composer.json version matches AnalyticsEvent VERSION', function () {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['version'])->toBe('74.0.0');
        });

        it('AnalyticsManager version returns 2.75.0', function () {
            $manager = new \ZeroBoiler\Analytics\AnalyticsManager(null);
            expect($manager->version())->toBe('74.0.0');
        });
    });

    describe('SubscriptionValueChangedEvent — MRR movement tracker', function () {
        it('constructs with required parameters', function () {
            $event = new SubscriptionValueChangedEvent(
                plan: 'Pro',
                previousValue: 49.0,
                newValue: 99.0,
            );

            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('subscription_value_changed');
            expect($event->params['plan'])->toBe('Pro');
            expect($event->params['previous_value'])->toBe(49.0);
            expect($event->params['new_value'])->toBe(99.0);
            expect($event->params['delta'])->toBe(50.0);
            expect($event->params['currency'])->toBe('USD');
        });

        it('computes negative delta for downgrades', function () {
            $event = new SubscriptionValueChangedEvent(
                plan: 'Starter',
                previousValue: 99.0,
                newValue: 19.0,
            );

            expect($event->params['delta'])->toBe(-80.0);
        });

        it('includes all optional parameters', function () {
            $event = new SubscriptionValueChangedEvent(
                plan: 'Enterprise',
                previousValue: 199.0,
                newValue: 399.0,
                currency: 'EUR',
                billingCycle: 'yearly',
                reason: 'add_on',
                userId: 'user_456',
                clientId: 'cli_abc',
            );

            expect($event->params['currency'])->toBe('EUR');
            expect($event->params['billing_cycle'])->toBe('yearly');
            expect($event->params['reason'])->toBe('add_on');
            expect($event->userId)->toBe('user_456');
            expect($event->clientId)->toBe('cli_abc');
        });

        it('is registered in SaaSEvents catalog', function () {
            expect(SaaSEvents::has('subscription_value_changed'))->toBeTrue();

            $entry = SaaSEvents::get('subscription_value_changed');
            expect($entry['class'])->toBe(SubscriptionValueChangedEvent::class);
            expect($entry['ga4'])->toBe('subscription_value_changed');
            expect($entry['meta'])->toBe('SubscriptionValueChanged');
            expect($entry['posthog'])->toBe('subscription_value_changed');
        });

        it('is readonly', function () {
            $ref = new ReflectionClass(SubscriptionValueChangedEvent::class);
            expect($ref->isReadOnly())->toBeTrue();
        });

        it('is in EventCatalog all()', function () {
            expect(EventCatalog::has('subscription_value_changed'))->toBeTrue();

            $entry = EventCatalog::get('subscription_value_changed');
            expect($entry['category'])->toBe('saas');
        });

        it('is in ServerSideTracker customEventMap', function () {
            $manager = new \ZeroBoiler\Analytics\AnalyticsManager(null);
            $tracker = new ServerSideTracker($manager, app('config'));

            // Verify the mapping exists in the class's default map
            $ref = new ReflectionClass(ServerSideTracker::class);
            $prop = $ref->getProperty('customEventMap');
            $map = $prop->getValue($tracker);

            expect($map)->toHaveKey('subscription.value_changed');
            expect($map['subscription.value_changed'])->toBe(SubscriptionValueChangedEvent::class);
        });
    });

    describe('UsageQuotaReachedEvent — expansion signal', function () {
        it('constructs with required parameters', function () {
            $event = new UsageQuotaReachedEvent(
                feature: 'api_calls',
                plan: 'Starter',
                currentUsage: 10000,
                limit: 10000,
            );

            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('usage_quota_reached');
            expect($event->params['feature'])->toBe('api_calls');
            expect($event->params['plan'])->toBe('Starter');
            expect($event->params['current_usage'])->toBe(10000);
            expect($event->params['limit'])->toBe(10000);
        });

        it('auto-calculates usage percentage', function () {
            $event = new UsageQuotaReachedEvent(
                feature: 'storage_gb',
                plan: 'Pro',
                currentUsage: 75,
                limit: 100,
            );

            expect($event->params['usage_percentage'])->toBe(75.0);
        });

        it('includes optional unit parameter', function () {
            $event = new UsageQuotaReachedEvent(
                feature: 'team_members',
                plan: 'Starter',
                currentUsage: 5,
                limit: 5,
                unit: 'seats',
            );

            expect($event->params['unit'])->toBe('seats');
        });

        it('allows override usage percentage', function () {
            $event = new UsageQuotaReachedEvent(
                feature: 'exports',
                plan: 'Free',
                currentUsage: 500,
                limit: 1000,
                usagePercentage: 50.0,
            );

            expect($event->params['usage_percentage'])->toBe(50.0);
        });

        it('is registered in SaaSEvents catalog', function () {
            expect(SaaSEvents::has('usage_quota_reached'))->toBeTrue();

            $entry = SaaSEvents::get('usage_quota_reached');
            expect($entry['class'])->toBe(UsageQuotaReachedEvent::class);
            expect($entry['ga4'])->toBe('usage_quota_reached');
            expect($entry['meta'])->toBe('UsageQuotaReached');
        });

        it('is readonly', function () {
            $ref = new ReflectionClass(UsageQuotaReachedEvent::class);
            expect($ref->isReadOnly())->toBeTrue();
        });

        it('is in EventCatalog all()', function () {
            expect(EventCatalog::has('usage_quota_reached'))->toBeTrue();

            $entry = EventCatalog::get('usage_quota_reached');
            expect($entry['category'])->toBe('saas');
        });

        it('is in ServerSideTracker customEventMap', function () {
            $ref = new ReflectionClass(ServerSideTracker::class);
            $prop = $ref->getProperty('customEventMap');
            $map = $prop->getValue(new \ZeroBoiler\Analytics\AnalyticsManager(null), app('config'));

            expect($map)->toHaveKey('usage.quota_reached');
            expect($map['usage.quota_reached'])->toBe(UsageQuotaReachedEvent::class);
        });
    });

    describe('BillingRetryEvent — dunning lifecycle tracker', function () {
        it('constructs with required parameters', function () {
            $event = new BillingRetryEvent(
                status: 'attempted',
                attemptNumber: 1,
            );

            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('billing_retry');
            expect($event->params['status'])->toBe('attempted');
            expect($event->params['attempt_number'])->toBe(1);
            expect($event->params['currency'])->toBe('USD');
        });

        it('tracks succeeded retry', function () {
            $event = new BillingRetryEvent(
                status: 'succeeded',
                attemptNumber: 2,
                plan: 'Pro',
                amount: 49.0,
            );

            expect($event->params['status'])->toBe('succeeded');
            expect($event->params['plan'])->toBe('Pro');
            expect($event->params['amount'])->toBe(49.0);
        });

        it('tracks exhausted retries with failure reason', function () {
            $event = new BillingRetryEvent(
                status: 'exhausted',
                attemptNumber: 3,
                plan: 'Enterprise',
                amount: 199.0,
                currency: 'EUR',
                failureReason: 'card_declined',
                userId: 'user_789',
            );

            expect($event->params['status'])->toBe('exhausted');
            expect($event->params['failure_reason'])->toBe('card_declined');
            expect($event->params['currency'])->toBe('EUR');
            expect($event->userId)->toBe('user_789');
        });

        it('is registered in SaaSEvents catalog', function () {
            expect(SaaSEvents::has('billing_retry'))->toBeTrue();

            $entry = SaaSEvents::get('billing_retry');
            expect($entry['class'])->toBe(BillingRetryEvent::class);
            expect($entry['ga4'])->toBe('billing_retry');
            expect($entry['meta'])->toBe('BillingRetry');
            expect($entry['posthog'])->toBe('billing_retry');
        });

        it('is readonly', function () {
            $ref = new ReflectionClass(BillingRetryEvent::class);
            expect($ref->isReadOnly())->toBeTrue();
        });

        it('is in EventCatalog all()', function () {
            expect(EventCatalog::has('billing_retry'))->toBeTrue();

            $entry = EventCatalog::get('billing_retry');
            expect($entry['category'])->toBe('saas');
        });

        it('is in ServerSideTracker customEventMap', function () {
            $ref = new ReflectionClass(ServerSideTracker::class);
            $prop = $ref->getProperty('customEventMap');
            $map = $prop->getValue(new \ZeroBoiler\Analytics\AnalyticsManager(null), app('config'));

            expect($map)->toHaveKey('billing.retry');
            expect($map['billing.retry'])->toBe(BillingRetryEvent::class);
        });
    });

    describe('Event Catalog counts', function () {
        it('total catalog is now 84 events (81 + 3 new)', function () {
            expect(EventCatalog::count())->toBeGreaterThanOrEqual(84);
        });

        it('SaaS catalog is now 46 events (43 + 3 new)', function () {
            expect(SaaSEvents::count())->toBeGreaterThanOrEqual(46);
        });

        it('Ecommerce catalog unchanged at 13', function () {
            expect(EcommerceEvents::count())->toBe(13);
        });

        it('Engagement catalog unchanged at 25', function () {
            expect(EngagementEvents::count())->toBeGreaterThanOrEqual(25);
        });

        it('summary() reflects correct counts', function () {
            $summary = EventCatalog::summary();

            expect($summary['total'])->toBe(84);
            expect($summary['ecommerce'])->toBe(13);
            expect($summary['saas'])->toBe(46);
            expect($summary['engagement'])->toBe(25);
        });
    });

    describe('EventCatalog::criticalEvents()', function () {
        it('returns non-empty list of critical events', function () {
            $events = EventCatalog::criticalEvents();

            expect($events)->not->toBeEmpty();
            // All returned entries should have the expected keys
            foreach ($events as $event) {
                expect($event)->toHaveKeys(['name', 'class', 'ga4', 'category']);
            }
        });

        it('includes revenue-impacting events', function () {
            $events = EventCatalog::criticalEvents();
            $names = array_column($events, 'name');

            expect($names)->toContain('purchase');
            expect($names)->toContain('subscribe');
            expect($names)->toContain('sign_up');
            expect($names)->toContain('plan_upgrade');
            expect($names)->toContain('add_to_cart');
        });

        it('includes new v2.75.0 revenue events', function () {
            $events = EventCatalog::criticalEvents();
            $names = array_column($events, 'name');

            expect($names)->toContain('subscription_value_changed');
            expect($names)->toContain('billing_retry');
        });

        it('all critical events exist in catalog', function () {
            $events = EventCatalog::criticalEvents();

            foreach ($events as $event) {
                expect(EventCatalog::has($event['name']))->toBeTrue();
            }
        });
    });

    describe('EventCatalog::samplableEvents()', function () {
        it('returns non-empty list of samplable events', function () {
            $events = EventCatalog::samplableEvents();

            expect($events)->not->toBeEmpty();
        });

        it('includes engagement/low-criticality events', function () {
            $events = EventCatalog::samplableEvents();
            $names = array_column($events, 'name');

            expect($names)->toContain('page_view');
            expect($names)->toContain('scroll_depth');
            expect($names)->toContain('click');
            expect($names)->toContain('web_vitals');
        });

        it('does not include revenue events', function () {
            $events = EventCatalog::samplableEvents();
            $names = array_column($events, 'name');

            expect($names)->not->toContain('purchase');
            expect($names)->not->toContain('subscribe');
            expect($names)->not->toContain('sign_up');
        });

        it('all samplable events exist in catalog', function () {
            $events = EventCatalog::samplableEvents();

            foreach ($events as $event) {
                expect(EventCatalog::has($event['name']))->toBeTrue();
            }
        });
    });

    describe('EventCatalog::checkoutFunnel()', function () {
        it('returns ordered checkout funnel events', function () {
            $funnel = EventCatalog::checkoutFunnel();
            $names = array_column($funnel, 'name');

            expect($names)->not->toBeEmpty();
            expect($names)->toContain('view_item');
            expect($names)->toContain('add_to_cart');
            expect($names)->toContain('view_cart');
            expect($names)->toContain('begin_checkout');
            expect($names)->toContain('add_payment_info');
            expect($names)->toContain('purchase');
            expect($names)->toContain('refund');
        });

        it('maintains correct funnel order', function () {
            $funnel = EventCatalog::checkoutFunnel();
            $names = array_column($funnel, 'name');

            $viewItemIdx = array_search('view_item', $names);
            $addToCartIdx = array_search('add_to_cart', $names);
            $purchaseIdx = array_search('purchase', $names);

            expect($viewItemIdx)->toBeLessThan($addToCartIdx);
            expect($addToCartIdx)->toBeLessThan($purchaseIdx);
        });

        it('all checkout events belong to ecommerce category', function () {
            $funnel = EventCatalog::checkoutFunnel();

            foreach ($funnel as $event) {
                expect($event['category'])->toBe('ecommerce');
            }
        });
    });

    describe('EventCatalog::activationFunnel()', function () {
        it('returns activation funnel events', function () {
            $funnel = EventCatalog::activationFunnel();
            $names = array_column($funnel, 'name');

            expect($names)->not->toBeEmpty();
            expect($names)->toContain('sign_up');
            expect($names)->toContain('email_verified');
            expect($names)->toContain('onboarding_step');
            expect($names)->toContain('feature_used');
            expect($names)->toContain('subscribe');
        });

        it('all activation events exist in catalog', function () {
            $funnel = EventCatalog::activationFunnel();

            foreach ($funnel as $event) {
                expect(EventCatalog::has($event['name']))->toBeTrue();
            }
        });
    });

    describe('EventCatalog::retentionSignals()', function () {
        it('returns retention signal events', function () {
            $signals = EventCatalog::retentionSignals();
            $names = array_column($signals, 'name');

            expect($names)->not->toBeEmpty();
            // Churn signals
            expect($names)->toContain('cancellation');
            expect($names)->toContain('account_deactivated');
            expect($names)->toContain('plan_downgrade');
            // Retention positive signals
            expect($names)->toContain('login');
            expect($names)->toContain('feature_used');
            expect($names)->toContain('milestone_reached');
        });

        it('includes new v2.75.0 expansion signals', function () {
            $signals = EventCatalog::retentionSignals();
            $names = array_column($signals, 'name');

            expect($names)->toContain('usage_quota_reached');
            expect($names)->toContain('billing_retry');
            expect($names)->toContain('subscription_value_changed');
        });

        it('all retention signal events exist in catalog', function () {
            $signals = EventCatalog::retentionSignals();

            foreach ($signals as $event) {
                expect(EventCatalog::has($event['name']))->toBeTrue();
            }
        });
    });

    describe('Catalog integrity', function () {
        it('validate() reports no errors', function () {
            $result = EventCatalog::validate();

            expect($result['valid'])->toBeTrue();
            expect($result['errors'])->toBeEmpty();
        });

        it('no duplicate event names across categories', function () {
            $all = EventCatalog::all();
            $names = array_keys($all);
            $unique = array_unique($names);

            expect(count($names))->toBe(count($unique));
        });

        it('all SaaS event classes have FQCN format', function () {
            $catalog = SaaSEvents::all();

            foreach ($catalog as $name => $entry) {
                expect($entry['class'])->toStartWith('ZeroBoiler\\Analytics\\');
            }
        });

        it('all event entries have required keys', function () {
            $required = EventCatalog::requiredKeys();
            $all = EventCatalog::all();

            foreach ($all as $name => $entry) {
                foreach ($required as $key) {
                    expect($entry)->toHaveKey($key);
                }
            }
        });
    });
});
