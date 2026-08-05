<?php

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\SaaS\CancellationEvent;
use ZeroBoiler\Analytics\Events\SaaS\FeatureUsedEvent;
use ZeroBoiler\Analytics\Events\SaaS\LoginEvent;
use ZeroBoiler\Analytics\Events\SaaS\LogoutEvent;
use ZeroBoiler\Analytics\Events\SaaS\PlanDowngradeEvent;
use ZeroBoiler\Analytics\Events\SaaS\PlanUpgradeEvent;
use ZeroBoiler\Analytics\Events\SaaS\SignUpEvent;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionEvent;
use ZeroBoiler\Analytics\Events\SaaS\TrialEndEvent;
use ZeroBoiler\Analytics\Events\SaaS\TrialStartEvent;

describe('SaaS Lifecycle Events', function () {
    describe('SignUpEvent', function () {
        it('creates with method', function () {
            $event = new SignUpEvent('google');

            expect($event->name)->toBe('sign_up');
            expect($event->params['method'])->toBe('google');
        });

        it('creates without method', function () {
            $event = new SignUpEvent;

            expect($event->name)->toBe('sign_up');
            expect($event->params)->toBeEmpty();
        });

        it('filters out null method', function () {
            $event = new SignUpEvent(null);

            expect($event->params)->toBeEmpty();
        });
    });

    describe('LoginEvent', function () {
        it('creates with method', function () {
            $event = new LoginEvent('email');

            expect($event->name)->toBe('login');
            expect($event->params['method'])->toBe('email');
        });

        it('creates without method', function () {
            $event = new LoginEvent;

            expect($event->name)->toBe('login');
            expect($event->params)->toBeEmpty();
        });
    });

    describe('LogoutEvent', function () {
        it('creates with no parameters', function () {
            $event = new LogoutEvent;

            expect($event->name)->toBe('logout');
            expect($event->params)->toBe([]);
        });
    });

    describe('TrialStartEvent', function () {
        it('creates with plan and trial days', function () {
            $event = new TrialStartEvent('pro', 14);

            expect($event->name)->toBe('start_trial');
            expect($event->params['plan_name'])->toBe('pro');
            expect($event->params['trial_days'])->toBe(14);
        });

        it('creates with only plan', function () {
            $event = new TrialStartEvent('business');

            expect($event->params['plan_name'])->toBe('business');
            expect($event->params)->not->toHaveKey('trial_days');
        });

        it('creates with no parameters', function () {
            $event = new TrialStartEvent;

            expect($event->params)->toBeEmpty();
        });
    });

    describe('TrialEndEvent', function () {
        it('creates with converted outcome', function () {
            $event = new TrialEndEvent('converted', 'pro');

            expect($event->name)->toBe('trial_end');
            expect($event->params['outcome'])->toBe('converted');
            expect($event->params['plan_name'])->toBe('pro');
        });

        it('creates with expired outcome', function () {
            $event = new TrialEndEvent('expired');

            expect($event->params['outcome'])->toBe('expired');
            expect($event->params)->not->toHaveKey('plan_name');
        });
    });

    describe('SubscriptionEvent', function () {
        it('creates with all parameters', function () {
            $event = new SubscriptionEvent(
                planName: 'Pro Plan',
                value: 29.99,
                currency: 'EUR',
                billingCycle: 'monthly',
                transactionId: 'TXN-123',
                isRenewal: false,
            );

            expect($event->name)->toBe('subscribe');
            expect($event->params['plan_name'])->toBe('Pro Plan');
            expect($event->params['value'])->toBe(29.99);
            expect($event->params['currency'])->toBe('EUR');
            expect($event->params['billing_cycle'])->toBe('monthly');
            expect($event->params['transaction_id'])->toBe('TXN-123');
            expect($event->params['is_renewal'])->toBeFalse();
        });

        it('creates as a renewal', function () {
            $event = new SubscriptionEvent('Basic', 9.99, isRenewal: true);

            expect($event->params['is_renewal'])->toBeTrue();
        });

        it('filters out null optional params', function () {
            $event = new SubscriptionEvent('Free', 0);

            expect($event->params)->not->toHaveKey('billing_cycle');
            expect($event->params)->not->toHaveKey('transaction_id');
            expect($event->params)->not->toHaveKey('is_renewal');
        });

        it('defaults to USD currency', function () {
            $event = new SubscriptionEvent('Test', 1.0);

            expect($event->params['currency'])->toBe('USD');
        });
    });

    describe('PlanUpgradeEvent', function () {
        it('creates with plan names', function () {
            $event = new PlanUpgradeEvent('starter', 'pro');

            expect($event->name)->toBe('plan_upgrade');
            expect($event->params['from_plan'])->toBe('starter');
            expect($event->params['to_plan'])->toBe('pro');
        });

        it('includes price difference', function () {
            $event = new PlanUpgradeEvent('basic', 'enterprise', 50.00);

            expect($event->params['price_difference'])->toBe(50.00);
        });

        it('filters out null price difference', function () {
            $event = new PlanUpgradeEvent('a', 'b');

            expect($event->params)->not->toHaveKey('price_difference');
        });
    });

    describe('PlanDowngradeEvent', function () {
        it('creates with plan names', function () {
            $event = new PlanDowngradeEvent('enterprise', 'pro');

            expect($event->name)->toBe('plan_downgrade');
            expect($event->params['from_plan'])->toBe('enterprise');
            expect($event->params['to_plan'])->toBe('pro');
        });
    });

    describe('CancellationEvent', function () {
        it('creates with all parameters', function () {
            $event = new CancellationEvent(
                planName: 'pro',
                reason: 'too_expensive',
                isTrial: false,
            );

            expect($event->name)->toBe('cancellation');
            expect($event->params['plan_name'])->toBe('pro');
            expect($event->params['reason'])->toBe('too_expensive');
            expect($event->params['is_trial'])->toBeFalse();
        });

        it('creates as trial cancellation', function () {
            $event = new CancellationEvent('trial', 'not_needed', true);

            expect($event->params['is_trial'])->toBeTrue();
        });

        it('creates with no parameters', function () {
            $event = new CancellationEvent;

            expect($event->params)->toBeEmpty();
        });
    });

    describe('FeatureUsedEvent', function () {
        it('creates with feature name', function () {
            $event = new FeatureUsedEvent('export_csv');

            expect($event->name)->toBe('feature_used');
            expect($event->params['feature_name'])->toBe('export_csv');
        });

        it('includes metadata', function () {
            $event = new FeatureUsedEvent('api_keys', [
                'count' => 5,
                'type' => 'read_write',
            ]);

            expect($event->params['feature_name'])->toBe('api_keys');
            expect($event->params['count'])->toBe(5);
            expect($event->params['type'])->toBe('read_write');
        });

        it('filters out empty metadata values', function () {
            $event = new FeatureUsedEvent('search', [
                'query' => 'test',
                'empty' => '',
            ]);

            expect($event->params['query'])->toBe('test');
            expect($event->params)->not->toHaveKey('empty');
        });
    });

    describe('Event class hierarchy', function () {
        it('all SaaS events extend AnalyticsEvent', function () {
            $events = [
                new SignUpEvent('email'),
                new LoginEvent('google'),
                new LogoutEvent,
                new TrialStartEvent('pro', 14),
                new TrialEndEvent('converted'),
                new SubscriptionEvent('pro', 29.99),
                new PlanUpgradeEvent('basic', 'pro'),
                new PlanDowngradeEvent('pro', 'basic'),
                new CancellationEvent('pro', 'too_expensive'),
                new FeatureUsedEvent('export'),
            ];

            foreach ($events as $event) {
                expect($event)->toBeInstanceOf(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::class);
            }
        });

        it('all SaaS events are readonly and final', function () {
            $classes = [
                SignUpEvent::class,
                LoginEvent::class,
                LogoutEvent::class,
                TrialStartEvent::class,
                TrialEndEvent::class,
                SubscriptionEvent::class,
                PlanUpgradeEvent::class,
                PlanDowngradeEvent::class,
                CancellationEvent::class,
                FeatureUsedEvent::class,
            ];

            foreach ($classes as $class) {
                $reflection = new ReflectionClass($class);
                expect($reflection->isReadOnly())->toBeTrue()
                    ->and($reflection->isFinal())->toBeTrue();
            }
        });
    });
});
