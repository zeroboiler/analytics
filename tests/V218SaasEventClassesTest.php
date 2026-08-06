<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\SaaS\SignUpEvent;
use ZeroBoiler\Analytics\Events\SaaS\LoginEvent;
use ZeroBoiler\Analytics\Events\SaaS\LogoutEvent;
use ZeroBoiler\Analytics\Events\SaaS\TrialStartEvent;
use ZeroBoiler\Analytics\Events\SaaS\TrialEndEvent;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionEvent;
use ZeroBoiler\Analytics\Events\SaaS\PlanUpgradeEvent;
use ZeroBoiler\Analytics\Events\SaaS\PlanDowngradeEvent;
use ZeroBoiler\Analytics\Events\SaaS\CancellationEvent;
use ZeroBoiler\Analytics\Events\SaaS\FeatureUsedEvent;
use ZeroBoiler\Analytics\Events\SaaS\RevenueEvent;
use ZeroBoiler\Analytics\Events\SaaS\CohortAssignedEvent;
use ZeroBoiler\Analytics\Events\SaaS\CohortRetentionEvent;
use ZeroBoiler\Analytics\Events\SaaS\CohortChurnEvent;
use ZeroBoiler\Analytics\Events\SaaS\CohortConversionEvent;
use ZeroBoiler\Analytics\Events\SaaS\CohortMigrationEvent;
use ZeroBoiler\Analytics\Events\SaaS\CohortEngagementEvent;

/**
 * Tests for all 17 typed SaaS event classes.
 *
 * Validates constructor parameters, DTO conversion, and param structure
 * for every SaaS lifecycle event including the 6 cohort events.
 */
final class V218SaasEventClassesTest extends TestCase
{
    public function test_sign_up_event(): void
    {
        $event = new SignUpEvent(method: 'google');

        $this->assertSame('sign_up', $event->name);
        $this->assertSame('google', $event->params['method']);
    }

    public function test_login_event(): void
    {
        $event = new LoginEvent(method: 'sanctum');

        $this->assertSame('login', $event->name);
        $this->assertSame('sanctum', $event->params['method']);
    }

    public function test_logout_event(): void
    {
        $event = new LogoutEvent;

        $this->assertSame('logout', $event->name);
    }

    public function test_trial_start_event(): void
    {
        $event = new TrialStartEvent(plan: 'pro', trialDays: 14);

        $this->assertSame('trial_start', $event->name);
        $this->assertSame('pro', $event->params['plan']);
        $this->assertSame(14, $event->params['trial_days']);
    }

    public function test_trial_end_event(): void
    {
        $event = new TrialEndEvent(outcome: 'converted', planName: 'pro');

        $this->assertSame('trial_end', $event->name);
        $this->assertSame('converted', $event->params['outcome']);
    }

    public function test_subscription_event(): void
    {
        $event = new SubscriptionEvent(plan: 'business', value: 99.99, currency: 'EUR');

        $this->assertSame('subscription', $event->name);
        $this->assertSame('business', $event->params['plan']);
        $this->assertSame(99.99, $event->params['value']);
        $this->assertSame('EUR', $event->params['currency']);
    }

    public function test_plan_upgrade_event(): void
    {
        $event = new PlanUpgradeEvent(fromPlan: 'starter', toPlan: 'pro');

        $this->assertSame('plan_upgrade', $event->name);
        $this->assertSame('starter', $event->params['from_plan']);
        $this->assertSame('pro', $event->params['to_plan']);
    }

    public function test_plan_downgrade_event(): void
    {
        $event = new PlanDowngradeEvent(fromPlan: 'pro', toPlan: 'starter');

        $this->assertSame('plan_downgrade', $event->name);
        $this->assertSame('pro', $event->params['from_plan']);
    }

    public function test_cancellation_event(): void
    {
        $event = new CancellationEvent(plan: 'pro', reason: 'too_expensive');

        $this->assertSame('cancellation', $event->name);
        $this->assertSame('too_expensive', $event->params['reason']);
    }

    public function test_feature_used_event(): void
    {
        $event = new FeatureUsedEvent(feature: 'export', usageCount: 5);

        $this->assertSame('feature_used', $event->name);
        $this->assertSame('export', $event->params['feature']);
        $this->assertSame(5, $event->params['usage_count']);
    }

    public function test_revenue_event(): void
    {
        $event = new RevenueEvent(amount: 5000.00, revenueType: 'mrr', planName: 'business');

        $this->assertSame('revenue_tracked', $event->name);
        $this->assertSame(5000.00, $event->params['amount']);
        $this->assertSame('mrr', $event->params['revenue_type']);
    }

    public function test_cohort_assigned_event(): void
    {
        $event = new CohortAssignedEvent(cohortName: '2026-W32', trigger: 'signup');

        $this->assertSame('cohort_assigned', $event->name);
        $this->assertSame('2026-W32', $event->params['cohort_name']);
    }

    public function test_cohort_retention_event(): void
    {
        $event = new CohortRetentionEvent(cohortName: '2026-W32', daysSinceStart: 7);

        $this->assertSame('cohort_retention', $event->name);
        $this->assertSame(7, $event->params['days_since_start']);
    }

    public function test_cohort_churn_event(): void
    {
        $event = new CohortChurnEvent(cohortName: '2026-W32', daysSinceStart: 15, reason: 'too_expensive');

        $this->assertSame('cohort_churn', $event->name);
        $this->assertSame(15, $event->params['days_since_start']);
    }

    public function test_cohort_conversion_event(): void
    {
        $event = new CohortConversionEvent(cohortName: '2026-W32', conversionType: 'trial_to_paid');

        $this->assertSame('cohort_conversion', $event->name);
        $this->assertSame('trial_to_paid', $event->params['conversion_type']);
    }

    public function test_cohort_migration_event(): void
    {
        $event = new CohortMigrationEvent(fromCohort: '2026-W32', toCohort: '2026-W33');

        $this->assertSame('cohort_migration', $event->name);
        $this->assertSame('2026-W32', $event->params['from_cohort']);
        $this->assertSame('2026-W33', $event->params['to_cohort']);
    }

    public function test_cohort_engagement_event(): void
    {
        $event = new CohortEngagementEvent(
            cohortName: '2026-W32',
            engagementRate: 85.5,
            eventCount: 120,
            period: 'weekly',
        );

        $this->assertSame('cohort_engagement', $event->name);
        $this->assertSame(85.5, $event->params['engagement_rate']);
        $this->assertSame('weekly', $event->params['period']);
    }

    public function test_all_saas_events_are_analytics_events(): void
    {
        $events = [
            new SignUpEvent(method: 'email'),
            new LoginEvent(method: 'web'),
            new LogoutEvent,
            new TrialStartEvent(plan: 'pro', trialDays: 14),
            new TrialEndEvent(outcome: 'converted', planName: 'pro'),
            new SubscriptionEvent(plan: 'business', value: 99.99, currency: 'USD'),
            new PlanUpgradeEvent(fromPlan: 'starter', toPlan: 'pro'),
            new PlanDowngradeEvent(fromPlan: 'pro', toPlan: 'starter'),
            new CancellationEvent(plan: 'pro', reason: 'price'),
            new FeatureUsedEvent(feature: 'export', usageCount: 1),
            new RevenueEvent(amount: 100.00, revenueType: 'one_time'),
            new CohortAssignedEvent(cohortName: '2026-W32', trigger: 'signup'),
            new CohortRetentionEvent(cohortName: '2026-W32', daysSinceStart: 7),
            new CohortChurnEvent(cohortName: '2026-W32', daysSinceStart: 30, reason: 'churn'),
            new CohortConversionEvent(cohortName: '2026-W32', conversionType: 'trial_to_paid'),
            new CohortMigrationEvent(fromCohort: '2026-W32', toCohort: '2026-W33'),
            new CohortEngagementEvent(cohortName: '2026-W32', engagementRate: 85.0, eventCount: 100, period: 'weekly'),
        ];

        foreach ($events as $event) {
            $this->assertInstanceOf(AnalyticsEvent::class, $event);
        }

        $this->assertCount(17, $events);
    }

    public function test_saas_revenue_events_have_monetary_params(): void
    {
        $subscription = new SubscriptionEvent(plan: 'pro', value: 29.99, currency: 'USD');
        $revenue = new RevenueEvent(amount: 5000.00, revenueType: 'mrr');

        $this->assertIsFloat($subscription->params['value']);
        $this->assertIsFloat($revenue->params['amount']);
    }
}
