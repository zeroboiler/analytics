<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Support\SaaSEventHelpers;

beforeEach(function (): void {
    $this->manager = new AnalyticsManager;
    $this->helpers = new SaaSEventHelpers($this->manager);
    $this->events = [];

    $this->manager->interceptBefore(function (AnalyticsEvent $event): AnalyticsEvent {
        $this->events[] = $event;

        return $event;
    });
});

test('SaaSEventHelpers::signUp dispatches sign_up with method', function (): void {
    $this->helpers->signUp('google');

    expect($this->events)->toHaveCount(1);
    expect($this->events[0]->name)->toBe('sign_up');
    expect($this->events[0]->params['method'])->toBe('google');
});

test('SaaSEventHelpers::signUp omits method when null', function (): void {
    $this->helpers->signUp();

    expect($this->events)->toHaveCount(1);
    expect($this->events[0]->name)->toBe('sign_up');
    expect($this->events[0]->params)->not->toHaveKey('method');
});

test('SaaSEventHelpers::signUp merges extra params', function (): void {
    $this->helpers->signUp('email', ['referral' => 'friend', 'campaign' => 'launch']);

    expect($this->events)->toHaveCount(1);
    expect($this->events[0]->params['method'])->toBe('email');
    expect($this->events[0]->params['referral'])->toBe('friend');
    expect($this->events[0]->params['campaign'])->toBe('launch');
});

test('SaaSEventHelpers::login dispatches login and identify', function (): void {
    $this->helpers->login('user-123', 'client-abc', 'sso');

    expect($this->events)->toHaveCount(2);
    expect($this->events[0]->name)->toBe('login');
    expect($this->events[0]->params['user_id'])->toBe('user-123');
    expect($this->events[0]->params['method'])->toBe('sso');
    expect($this->events[1]->name)->toBe('identify');
    expect($this->events[1]->params['user_id'])->toBe('user-123');
});

test('SaaSEventHelpers::trialStart dispatches start_trial with plan and duration', function (): void {
    $this->helpers->trialStart('pro', 14);

    expect($this->events)->toHaveCount(1);
    expect($this->events[0]->name)->toBe('start_trial');
    expect($this->events[0]->params['plan'])->toBe('pro');
    expect($this->events[0]->params['duration_days'])->toBe(14);
});

test('SaaSEventHelpers::trialStart works with null params', function (): void {
    $this->helpers->trialStart();

    expect($this->events)->toHaveCount(1);
    expect($this->events[0]->name)->toBe('start_trial');
    expect($this->events[0]->params)->not->toHaveKey('plan');
    expect($this->events[0]->params)->not->toHaveKey('duration_days');
});

test('SaaSEventHelpers::subscription dispatches subscribe with billing context', function (): void {
    $this->helpers->subscription('pro', 49.00, 'USD', 'monthly');

    expect($this->events)->toHaveCount(1);
    expect($this->events[0]->name)->toBe('subscribe');
    expect($this->events[0]->params['plan'])->toBe('pro');
    expect($this->events[0]->params['value'])->toBe(49.0);
    expect($this->events[0]->params['currency'])->toBe('USD');
    expect($this->events[0]->params['billing_cycle'])->toBe('monthly');
});

test('SaaSEventHelpers::planUpgrade dispatches with from/to plan', function (): void {
    $this->helpers->planUpgrade('starter', 'pro', 30.0);

    expect($this->events)->toHaveCount(1);
    expect($this->events[0]->name)->toBe('plan_upgrade');
    expect($this->events[0]->params['from_plan'])->toBe('starter');
    expect($this->events[0]->params['to_plan'])->toBe('pro');
    expect($this->events[0]->params['value_difference'])->toBe(30.0);
});

test('SaaSEventHelpers::planDowngrade dispatches with transition', function (): void {
    $this->helpers->planDowngrade('pro', 'starter', -30.0);

    expect($this->events)->toHaveCount(1);
    expect($this->events[0]->name)->toBe('plan_downgrade');
    expect($this->events[0]->params['from_plan'])->toBe('pro');
    expect($this->events[0]->params['to_plan'])->toBe('starter');
    expect($this->events[0]->params['value_difference'])->toBe(-30.0);
});

test('SaaSEventHelpers::cancellation dispatches with reason and lost revenue', function (): void {
    $this->helpers->cancellation('too_expensive', 'pro', 49.0);

    expect($this->events)->toHaveCount(1);
    expect($this->events[0]->name)->toBe('cancellation');
    expect($this->events[0]->params['reason'])->toBe('too_expensive');
    expect($this->events[0]->params['plan'])->toBe('pro');
    expect($this->events[0]->params['lost_revenue'])->toBe(49.0);
});

test('SaaSEventHelpers::featureUsed dispatches with feature name', function (): void {
    $this->helpers->featureUsed('export');

    expect($this->events)->toHaveCount(1);
    expect($this->events[0]->name)->toBe('feature_used');
    expect($this->events[0]->params['feature'])->toBe('export');
});

test('SaaSEventHelpers::featureUsed merges extra params', function (): void {
    $this->helpers->featureUsed('api_access', ['endpoint_count' => 5, 'category' => 'integration']);

    expect($this->events)->toHaveCount(1);
    expect($this->events[0]->params['feature'])->toBe('api_access');
    expect($this->events[0]->params['endpoint_count'])->toBe(5);
    expect($this->events[0]->params['category'])->toBe('integration');
});

test('SaaSEventHelpers::teamCreated dispatches with name and member count', function (): void {
    $this->helpers->teamCreated('Engineering', 5);

    expect($this->events)->toHaveCount(1);
    expect($this->events[0]->name)->toBe('team_created');
    expect($this->events[0]->params['team_name'])->toBe('Engineering');
    expect($this->events[0]->params['member_count'])->toBe(5);
});

test('SaaSEventHelpers::inviteSent dispatches with role and channel', function (): void {
    $this->helpers->inviteSent('admin', 'email');

    expect($this->events)->toHaveCount(1);
    expect($this->events[0]->name)->toBe('invite_sent');
    expect($this->events[0]->params['role'])->toBe('admin');
    expect($this->events[0]->params['channel'])->toBe('email');
});

test('SaaSEventHelpers::paymentFailed dispatches with reason and amount', function (): void {
    $this->helpers->paymentFailed('card_declined', 99.00, 'USD');

    expect($this->events)->toHaveCount(1);
    expect($this->events[0]->name)->toBe('payment_failed');
    expect($this->events[0]->params['reason'])->toBe('card_declined');
    expect($this->events[0]->params['amount'])->toBe(99.0);
    expect($this->events[0]->params['currency'])->toBe('USD');
});

test('SaaSEventHelpers::manager returns underlying AnalyticsManager', function (): void {
    $manager = $this->helpers->manager();

    expect($manager)->toBe($this->manager);
});

test('SaaSEventHelpers filters null values from params', function (): void {
    $this->helpers->subscription(null, null, null, null);

    expect($this->events)->toHaveCount(1);
    expect($this->events[0]->name)->toBe('subscribe');
    expect($this->events[0]->params)->toBe([]);
});

test('SaaSEventHelpers respects PHP 8.5 syntax with strict types', function (): void {
    $helpers = new SaaSEventHelpers(new AnalyticsManager);

    // Verify the class is instantiable (PHP 8.5 readonly promotion, strict types)
    expect($helpers)->toBeInstanceOf(SaaSEventHelpers::class);
});
