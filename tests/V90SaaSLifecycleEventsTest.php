<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\AccountDeletedEvent;
use ZeroBoiler\Analytics\Events\SaaS\PlanChangedEvent;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionCancelledEvent;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionCreatedEvent;
use ZeroBoiler\Analytics\Events\SaaS\TrialExpiredEvent;

/**
 * @covers \ZeroBoiler\Analytics\Events\SaaS\AccountDeletedEvent
 * @covers \ZeroBoiler\Analytics\Events\SaaS\SubscriptionCreatedEvent
 * @covers \ZeroBoiler\Analytics\Events\SaaS\SubscriptionCancelledEvent
 * @covers \ZeroBoiler\Analytics\Events\SaaS\TrialExpiredEvent
 * @covers \ZeroBoiler\Analytics\Events\SaaS\PlanChangedEvent
 */
class V90SaaSLifecycleEventsTest extends TestCase
{
    // ── AccountDeletedEvent ────────────────────────────────────────────

    public function testAccountDeletedEventIsAnalyticsEvent(): void
    {
        $event = new AccountDeletedEvent();
        $this->assertInstanceOf(AnalyticsEvent::class, $event);
    }

    public function testAccountDeletedEventName(): void
    {
        $event = new AccountDeletedEvent();
        $this->assertSame('account_deleted', $event->name);
    }

    public function testAccountDeletedEventWithAllParams(): void
    {
        $event = new AccountDeletedEvent(
            reason: 'gdpr_request',
            method: 'self_service',
            accountAgeDays: 365,
            lastPlan: 'pro',
        );

        $this->assertSame('account_deleted', $event->name);
        $this->assertSame('gdpr_request', $event->params['reason']);
        $this->assertSame('self_service', $event->params['method']);
        $this->assertSame(365, $event->params['account_age_days']);
        $this->assertSame('pro', $event->params['last_plan']);
    }

    public function testAccountDeletedEventFiltersNullParams(): void
    {
        $event = new AccountDeletedEvent();
        $this->assertEmpty($event->params);
    }

    public function testAccountDeletedEventPartialParams(): void
    {
        $event = new AccountDeletedEvent(reason: 'gdpr_request', lastPlan: 'starter');
        $this->assertSame('gdpr_request', $event->params['reason']);
        $this->assertSame('starter', $event->params['last_plan']);
        $this->assertArrayNotHasKey('method', $event->params);
    }

    // ── SubscriptionCreatedEvent ───────────────────────────────────────

    public function testSubscriptionCreatedEventIsAnalyticsEvent(): void
    {
        $event = new SubscriptionCreatedEvent('pro');
        $this->assertInstanceOf(AnalyticsEvent::class, $event);
    }

    public function testSubscriptionCreatedEventName(): void
    {
        $event = new SubscriptionCreatedEvent('enterprise');
        $this->assertSame('subscription_created', $event->name);
    }

    public function testSubscriptionCreatedEventWithAllParams(): void
    {
        $event = new SubscriptionCreatedEvent(
            plan: 'business',
            value: 99.99,
            currency: 'EUR',
            billingCycle: 'monthly',
            source: 'trial_conversion',
        );

        $this->assertSame('business', $event->params['plan']);
        $this->assertSame(99.99, $event->params['value']);
        $this->assertSame('EUR', $event->params['currency']);
        $this->assertSame('monthly', $event->params['billing_cycle']);
        $this->assertSame('trial_conversion', $event->params['source']);
    }

    public function testSubscriptionCreatedEventPlanOnly(): void
    {
        $event = new SubscriptionCreatedEvent('starter');
        $this->assertSame('starter', $event->params['plan']);
        $this->assertCount(1, $event->params);
    }

    // ── SubscriptionCancelledEvent ─────────────────────────────────────

    public function testSubscriptionCancelledEventIsAnalyticsEvent(): void
    {
        $event = new SubscriptionCancelledEvent('pro');
        $this->assertInstanceOf(AnalyticsEvent::class, $event);
    }

    public function testSubscriptionCancelledEventName(): void
    {
        $event = new SubscriptionCancelledEvent('enterprise');
        $this->assertSame('subscription_cancelled', $event->name);
    }

    public function testSubscriptionCancelledEventWithFullContext(): void
    {
        $event = new SubscriptionCancelledEvent(
            plan: 'business',
            reason: 'too_expensive',
            flow: 'self_service',
            effectiveDate: '2026-09-01',
            retentionOfferAccepted: false,
        );

        $this->assertSame('business', $event->params['plan']);
        $this->assertSame('too_expensive', $event->params['reason']);
        $this->assertSame('self_service', $event->params['flow']);
        $this->assertSame('2026-09-01', $event->params['effective_date']);
        $this->assertFalse($event->params['retention_offer_accepted']);
    }

    public function testSubscriptionCancelledEventPlanOnly(): void
    {
        $event = new SubscriptionCancelledEvent('pro');
        $this->assertSame('pro', $event->params['plan']);
        $this->assertCount(1, $event->params);
    }

    // ── TrialExpiredEvent ────────────────────────────────────────────

    public function testTrialExpiredEventIsAnalyticsEvent(): void
    {
        $event = new TrialExpiredEvent();
        $this->assertInstanceOf(AnalyticsEvent::class, $event);
    }

    public function testTrialExpiredEventName(): void
    {
        $event = new TrialExpiredEvent();
        $this->assertSame('trial_expired', $event->name);
    }

    public function testTrialExpiredEventWithParams(): void
    {
        $event = new TrialExpiredEvent(
            plan: 'pro',
            trialLengthDays: 14,
            featuresUsedCount: 3,
            lastActivity: '2026-08-01T10:00:00Z',
        );

        $this->assertSame('pro', $event->params['plan']);
        $this->assertSame(14, $event->params['trial_length_days']);
        $this->assertSame(3, $event->params['features_used_count']);
        $this->assertSame('2026-08-01T10:00:00Z', $event->params['last_activity']);
    }

    public function testTrialExpiredEventEmptyParams(): void
    {
        $event = new TrialExpiredEvent();
        $this->assertEmpty($event->params);
    }

    // ── PlanChangedEvent ─────────────────────────────────────────────

    public function testPlanChangedEventIsAnalyticsEvent(): void
    {
        $event = new PlanChangedEvent('starter', 'pro');
        $this->assertInstanceOf(AnalyticsEvent::class, $event);
    }

    public function testPlanChangedEventName(): void
    {
        $event = new PlanChangedEvent('starter', 'pro');
        $this->assertSame('plan_changed', $event->name);
    }

    public function testPlanChangedEventWithAllParams(): void
    {
        $event = new PlanChangedEvent(
            fromPlan: 'starter',
            toPlan: 'enterprise',
            direction: 'upgrade',
            reason: 'user_initiated',
            priceDifference: 50.00,
            currency: 'USD',
        );

        $this->assertSame('starter', $event->params['from_plan']);
        $this->assertSame('enterprise', $event->params['to_plan']);
        $this->assertSame('upgrade', $event->params['direction']);
        $this->assertSame('user_initiated', $event->params['reason']);
        $this->assertSame(50.00, $event->params['price_difference']);
        $this->assertSame('USD', $event->params['currency']);
    }

    public function testPlanChangedEventRequiredOnly(): void
    {
        $event = new PlanChangedEvent('pro', 'starter');
        $this->assertSame('pro', $event->params['from_plan']);
        $this->assertSame('starter', $event->params['to_plan']);
        $this->assertCount(2, $event->params);
    }

    public function testPlanChangedEventLateralDirection(): void
    {
        $event = new PlanChangedEvent(
            fromPlan: 'pro',
            toPlan: 'business',
            direction: 'lateral',
            reason: 'admin',
        );

        $this->assertSame('lateral', $event->params['direction']);
        $this->assertSame('admin', $event->params['reason']);
    }

    // ── EventCatalog Integration ──────────────────────────────────────

    public function testCatalogHasAccountDeleted(): void
    {
        $this->assertTrue(EventCatalog::has('account_deleted'));
    }

    public function testCatalogHasSubscriptionCreated(): void
    {
        $this->assertTrue(EventCatalog::has('subscription_created'));
    }

    public function testCatalogHasSubscriptionCancelled(): void
    {
        $this->assertTrue(EventCatalog::has('subscription_cancelled'));
    }

    public function testCatalogHasTrialExpired(): void
    {
        $this->assertTrue(EventCatalog::has('trial_expired'));
    }

    public function testCatalogHasPlanChanged(): void
    {
        $this->assertTrue(EventCatalog::has('plan_changed'));
    }

    public function testCatalogClassForNewEvents(): void
    {
        $this->assertSame(AccountDeletedEvent::class, EventCatalog::classFor('account_deleted'));
        $this->assertSame(SubscriptionCreatedEvent::class, EventCatalog::classFor('subscription_created'));
        $this->assertSame(SubscriptionCancelledEvent::class, EventCatalog::classFor('subscription_cancelled'));
        $this->assertSame(TrialExpiredEvent::class, EventCatalog::classFor('trial_expired'));
        $this->assertSame(PlanChangedEvent::class, EventCatalog::classFor('plan_changed'));
    }

    public function testNewEventsInCatalogCount(): void
    {
        $total = EventCatalog::count();
        // Previous versions had ~91 events; v2.90 adds 5 new SaaS events → expect 96+
        $this->assertGreaterThanOrEqual(96, $total);
    }

    public function testVersionConsistency(): void
    {
        $this->assertSame('5.0.0', AnalyticsEvent::VERSION);
    }

    // ── GDPR Events Helper ───────────────────────────────────────────

    public function testGdprEventsIncludesAccountDeleted(): void
    {
        $gdprEvents = EventCatalog::gdprEvents();
        $gdprNames = array_column($gdprEvents, 'name');
        $this->assertContains('account_deleted', $gdprNames);
        $this->assertContains('subscription_cancelled', $gdprNames);
    }

    // ── Billing Events Helper ─────────────────────────────────────────

    public function testBillingEventsIncludesNewEvents(): void
    {
        $billingEvents = EventCatalog::billingEvents();
        $billingNames = array_column($billingEvents, 'name');
        $this->assertContains('subscription_created', $billingNames);
        $this->assertContains('subscription_cancelled', $billingNames);
        $this->assertContains('plan_changed', $billingNames);
    }
}
