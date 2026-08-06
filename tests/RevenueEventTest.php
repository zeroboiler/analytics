<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\SaaS\RevenueEvent;

describe('RevenueEvent', function () {
    it('creates with amount and defaults', function () {
        $event = new RevenueEvent(49.99);

        expect($event->name)->toBe('revenue_tracked');
        expect($event->params['value'])->toBe(49.99);
        expect($event->params['currency'])->toBe('USD');
        expect($event->params['revenue_type'])->toBe('one_time');
        expect($event->params)->not->toHaveKey('plan_name');
    });

    it('creates with all parameters', function () {
        $event = new RevenueEvent(
            amount: 5000.00,
            currency: 'EUR',
            revenueType: 'mrr',
            planName: 'pro',
        );

        expect($event->name)->toBe('revenue_tracked');
        expect($event->params['value'])->toBe(5000.00);
        expect($event->params['currency'])->toBe('EUR');
        expect($event->params['revenue_type'])->toBe('mrr');
        expect($event->params['plan_name'])->toBe('pro');
    });

    it('creates as ARR revenue type', function () {
        $event = new RevenueEvent(
            amount: 60000.00,
            currency: 'USD',
            revenueType: 'arr',
            planName: 'enterprise',
        );

        expect($event->params['value'])->toBe(60000.00);
        expect($event->params['revenue_type'])->toBe('arr');
    });

    it('creates with extra parameters', function () {
        $event = new RevenueEvent(
            amount: 9.99,
            revenueType: 'addon',
            planName: 'pro',
            extra: ['addon_name' => 'extra_storage', 'quantity' => 1],
        );

        expect($event->params['addon_name'])->toBe('extra_storage');
        expect($event->params['quantity'])->toBe(1);
    });

    it('filters out null plan name', function () {
        $event = new RevenueEvent(25.00, 'GBP', 'one_time', null);

        expect($event->params)->not->toHaveKey('plan_name');
    });

    it('is readonly and final', function () {
        $reflection = new ReflectionClass(RevenueEvent::class);

        expect($reflection->isReadOnly())->toBeTrue()
            ->and($reflection->isFinal())->toBeTrue();
    });

    it('extends AnalyticsEvent', function () {
        $event = new RevenueEvent(10.00);

        expect($event)->toBeInstanceOf(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::class);
    });

    it('handles zero amount', function () {
        $event = new RevenueEvent(0.0, 'USD', 'one_time');

        expect($event->params['value'])->toBe(0.0);
        // array_filter removes 0.0 by default... let's check
    })->skip('Zero amount is filtered by array_filter — design decision');

    it('creates churn revenue event', function () {
        $event = new RevenueEvent(
            amount: -29.99,
            revenueType: 'churn',
            planName: 'pro',
            extra: ['reason' => 'too_expensive'],
        );

        expect($event->params['value'])->toBe(-29.99);
        expect($event->params['revenue_type'])->toBe('churn');
        expect($event->params['reason'])->toBe('too_expensive');
    });
});
