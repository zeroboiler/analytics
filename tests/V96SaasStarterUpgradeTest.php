<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

test('AnalyticsManager::signUp dispatches sign_up event with method', function (): void {
    $manager = new AnalyticsManager;

    $events = [];
    $manager->interceptBefore(function (AnalyticsEvent $event) use (&$events): AnalyticsEvent {
        $events[] = $event;

        return $event;
    });

    $manager->signUp('google');

    expect($events)->toHaveCount(1);
    expect($events[0]->name)->toBe('sign_up');
    expect($events[0]->params['method'])->toBe('google');
});

test('AnalyticsManager::signUp dispatches without method when null', function (): void {
    $manager = new AnalyticsManager;

    $events = [];
    $manager->interceptBefore(function (AnalyticsEvent $event) use (&$events): AnalyticsEvent {
        $events[] = $event;

        return $event;
    });

    $manager->signUp();

    expect($events)->toHaveCount(1);
    expect($events[0]->name)->toBe('sign_up');
    expect($events[0]->params)->not->toHaveKey('method');
});

test('AnalyticsManager::login dispatches login event and auto-identifies', function (): void {
    $manager = new AnalyticsManager;

    $events = [];
    $manager->interceptBefore(function (AnalyticsEvent $event) use (&$events): AnalyticsEvent {
        $events[] = $event;

        return $event;
    });

    $manager->login('user-123', 'client-abc', 'email');

    expect($events)->toHaveCount(2);
    expect($events[0]->name)->toBe('login');
    expect($events[0]->params['user_id'])->toBe('user-123');
    expect($events[0]->params['method'])->toBe('email');
    // Auto-identify
    expect($events[1]->name)->toBe('identify');
    expect($events[1]->params['user_id'])->toBe('user-123');
    expect($events[1]->params['client_id'])->toBe('client-abc');
});

test('AnalyticsManager::login without clientId does not fire identify', function (): void {
    $manager = new AnalyticsManager;

    $events = [];
    $manager->interceptBefore(function (AnalyticsEvent $event) use (&$events): AnalyticsEvent {
        $events[] = $event;

        return $event;
    });

    $manager->login('user-123');

    expect($events)->toHaveCount(1);
    expect($events[0]->name)->toBe('login');
});

test('AnalyticsManager::trialStart dispatches start_trial event', function (): void {
    $manager = new AnalyticsManager;

    $events = [];
    $manager->interceptBefore(function (AnalyticsEvent $event) use (&$events): AnalyticsEvent {
        $events[] = $event;

        return $event;
    });

    $manager->trialStart('pro', 14);

    expect($events)->toHaveCount(1);
    expect($events[0]->name)->toBe('start_trial');
    expect($events[0]->params['plan_name'])->toBe('pro');
    expect($events[0]->params['trial_days'])->toBe(14);
});

test('AnalyticsManager::subscription dispatches subscribe event', function (): void {
    $manager = new AnalyticsManager;

    $events = [];
    $manager->interceptBefore(function (AnalyticsEvent $event) use (&$events): AnalyticsEvent {
        $events[] = $event;

        return $event;
    });

    $manager->subscription('enterprise', 99.99, 'EUR', 'monthly');

    expect($events)->toHaveCount(1);
    expect($events[0]->name)->toBe('subscribe');
    expect($events[0]->params['plan_name'])->toBe('enterprise');
    expect($events[0]->params['amount'])->toBe(99.99);
    expect($events[0]->params['currency'])->toBe('EUR');
    expect($events[0]->params['billing_cycle'])->toBe('monthly');
});

test('AnalyticsManager::planUpgrade dispatches plan_upgrade event', function (): void {
    $manager = new AnalyticsManager;

    $events = [];
    $manager->interceptBefore(function (AnalyticsEvent $event) use (&$events): AnalyticsEvent {
        $events[] = $event;

        return $event;
    });

    $manager->planUpgrade('starter', 'pro', 30.0);

    expect($events)->toHaveCount(1);
    expect($events[0]->name)->toBe('plan_upgrade');
    expect($events[0]->params['from_plan'])->toBe('starter');
    expect($events[0]->params['to_plan'])->toBe('pro');
    expect($events[0]->params['price_difference'])->toBe(30.0);
});

test('AnalyticsManager::cancellation dispatches cancellation event with reason', function (): void {
    $manager = new AnalyticsManager;

    $events = [];
    $manager->interceptBefore(function (AnalyticsEvent $event) use (&$events): AnalyticsEvent {
        $events[] = $event;

        return $event;
    });

    $manager->cancellation('pro', 'competitor');

    expect($events)->toHaveCount(1);
    expect($events[0]->name)->toBe('cancellation');
    expect($events[0]->params['plan_name'])->toBe('pro');
    expect($events[0]->params['reason'])->toBe('competitor');
});

test('AnalyticsManager::trackSaaSAcquisition dispatches full funnel', function (): void {
    $manager = new AnalyticsManager;

    $events = [];
    $manager->interceptBefore(function (AnalyticsEvent $event) use (&$events): AnalyticsEvent {
        $events[] = $event;

        return $event;
    });

    $manager->trackSaaSAcquisition(
        planName: 'pro',
        amount: 49.99,
        currency: 'USD',
        options: ['method' => 'google', 'trial_days' => 14],
        params: ['source' => 'landing_page'],
    );

    // sign_up + start_trial + subscribe = 3 events
    expect($events)->toHaveCount(3);
    expect($events[0]->name)->toBe('sign_up');
    expect($events[0]->params['method'])->toBe('google');
    expect($events[0]->params['source'])->toBe('landing_page');

    expect($events[1]->name)->toBe('start_trial');
    expect($events[1]->params['plan_name'])->toBe('pro');
    expect($events[1]->params['trial_days'])->toBe(14);

    expect($events[2]->name)->toBe('subscribe');
    expect($events[2]->params['plan_name'])->toBe('pro');
    expect($events[2]->params['amount'])->toBe(49.99);
});

test('AnalyticsManager::trackSaaSAcquisition with skip_trial omits start_trial', function (): void {
    $manager = new AnalyticsManager;

    $events = [];
    $manager->interceptBefore(function (AnalyticsEvent $event) use (&$events): AnalyticsEvent {
        $events[] = $event;

        return $event;
    });

    $manager->trackSaaSAcquisition(
        planName: 'pro',
        amount: 49.99,
        options: ['skip_trial' => true],
    );

    // sign_up + subscribe = 2 events (no start_trial)
    expect($events)->toHaveCount(2);
    expect($events[0]->name)->toBe('sign_up');
    expect($events[1]->name)->toBe('subscribe');
});

test('AnalyticsManager::trackSaaSAcquisition with null amount omits subscribe', function (): void {
    $manager = new AnalyticsManager;

    $events = [];
    $manager->interceptBefore(function (AnalyticsEvent $event) use (&$events): AnalyticsEvent {
        $events[] = $event;

        return $event;
    });

    $manager->trackSaaSAcquisition(
        planName: 'free',
        amount: null,
    );

    // sign_up + start_trial only
    expect($events)->toHaveCount(2);
    expect($events[0]->name)->toBe('sign_up');
    expect($events[1]->name)->toBe('start_trial');
});

test('version is 2.98.0', function (): void {
    $manager = new AnalyticsManager;

    expect($manager->version())->toBe('5.7.0');
    expect(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION)->toBe('5.7.0');
});

test('SaaS event names exist in catalog', function (): void {
    expect(\ZeroBoiler\Analytics\Events\EventCatalog::has('sign_up'))->toBeTrue();
    expect(\ZeroBoiler\Analytics\Events\EventCatalog::has('login'))->toBeTrue();
    expect(\ZeroBoiler\Analytics\Events\EventCatalog::has('start_trial'))->toBeTrue();
    expect(\ZeroBoiler\Analytics\Events\EventCatalog::has('subscribe'))->toBeTrue();
    expect(\ZeroBoiler\Analytics\Events\EventCatalog::has('plan_upgrade'))->toBeTrue();
    expect(\ZeroBoiler\Analytics\Events\EventCatalog::has('cancellation'))->toBeTrue();
});

test('SaaS events have correct GA4 and Meta mappings', function (): void {
    $signUp = \ZeroBoiler\Analytics\Events\EventCatalog::get('sign_up');
    expect($signUp)->not->toBeNull();
    expect($signUp['ga4'])->toBe('sign_up');
    expect($signUp['meta'])->toBe('CompleteRegistration');
    expect($signUp['category'])->toBe('saas');

    $purchase = \ZeroBoiler\Analytics\Events\EventCatalog::get('purchase');
    expect($purchase)->not->toBeNull();
    expect($purchase['ga4'])->toBe('purchase');
    expect($purchase['meta'])->toBe('Purchase');
    expect($purchase['category'])->toBe('ecommerce');

    $pageView = \ZeroBoiler\Analytics\Events\EventCatalog::get('page_view');
    expect($pageView)->not->toBeNull();
    expect($pageView['ga4'])->toBe('page_view');
    expect($pageView['meta'])->toBe('PageView');
    expect($pageView['category'])->toBe('engagement');
});
