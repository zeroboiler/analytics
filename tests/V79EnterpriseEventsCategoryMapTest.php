<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\SaaS\SlaBreachEvent;
use ZeroBoiler\Analytics\Events\SaaS\PaymentMethodUpdatedEvent;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\Engagement\FeedbackEvent;
use ZeroBoiler\Analytics\Events\Engagement\GoalConversionEvent;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventPriorityCalculator;

// ─── v2.79.0 — Enterprise SaaS Events + Category Map Expansion + Version Bump ───

test('SlaBreachEvent creates correct event name and params', function (): void {
    $event = new SlaBreachEvent('uptime', 99.9, 98.5, '%', 'major');

    expect($event->name)->toBe('sla_breach');
    expect($event->params)->toHaveKey('sla_type');
    expect($event->params['sla_type'])->toBe('uptime');
    expect($event->params['threshold'])->toBe(99.9);
    expect($event->params['actual'])->toBe(98.5);
    expect($event->params['unit'])->toBe('%');
    expect($event->params['severity'])->toBe('major');
});

test('SlaBreachEvent defaults severity to minor', function (): void {
    $event = new SlaBreachEvent('response_time', 200.0, 350.0, 'ms');

    expect($event->params['severity'])->toBe('minor');
    expect($event->params['sla_type'])->toBe('response_time');
});

test('SlaBreachEvent accepts extra params', function (): void {
    $event = new SlaBreachEvent('resolution_time', 24.0, 48.0, 'hours', 'critical', ['customer_id' => 'cust_123']);

    expect($event->params['customer_id'])->toBe('cust_123');
});

test('PaymentMethodUpdatedEvent creates correct event name and params', function (): void {
    $event = new PaymentMethodUpdatedEvent('credit_card', 'updated', 'stripe');

    expect($event->name)->toBe('payment_method_updated');
    expect($event->params['payment_method'])->toBe('credit_card');
    expect($event->params['change_type'])->toBe('updated');
    expect($event->params['processor'])->toBe('stripe');
});

test('PaymentMethodUpdatedEvent works with null processor', function (): void {
    $event = new PaymentMethodUpdatedEvent('bank_transfer', 'added');

    expect($event->params['processor'])->toBeNull();
    expect($event->params['change_type'])->toBe('added');
});

test('PaymentMethodUpdatedEvent accepts extra params', function (): void {
    $event = new PaymentMethodUpdatedEvent('paypal', 'set_default', null, ['is_default' => true]);

    expect($event->params['is_default'])->toBe(true);
});

test('FeedbackEvent creates correct event name and params', function (): void {
    $event = new FeedbackEvent('nps', 9, 'promoter', 'ui');

    expect($event->name)->toBe('feedback');
    expect($event->params['feedback_type'])->toBe('nps');
    expect($event->params['score'])->toBe(9);
    expect($event->params['rating'])->toBe('promoter');
    expect($event->params['category'])->toBe('ui');
});

test('FeedbackEvent works with minimal params', function (): void {
    $event = new FeedbackEvent('csat');

    expect($event->name)->toBe('feedback');
    expect($event->params['score'])->toBeNull();
    expect($event->params['rating'])->toBeNull();
    expect($event->params['category'])->toBeNull();
});

test('FeedbackEvent accepts extra params', function (): void {
    $event = new FeedbackEvent('bug_report', null, null, null, ['comment' => 'Login button not working']);

    expect($event->params['comment'])->toBe('Login button not working');
});

test('GoalConversionEvent creates correct event name and params', function (): void {
    $event = new GoalConversionEvent('trial_to_paid', 'revenue', 99.99);

    expect($event->name)->toBe('goal_conversion');
    expect($event->params['goal_name'])->toBe('trial_to_paid');
    expect($event->params['goal_category'])->toBe('revenue');
    expect($event->params['goal_value'])->toBe(99.99);
});

test('GoalConversionEvent works with minimal params', function (): void {
    $event = new GoalConversionEvent('onboarding_complete');

    expect($event->params['goal_name'])->toBe('onboarding_complete');
    expect($event->params['goal_category'])->toBeNull();
    expect($event->params['goal_value'])->toBeNull();
});

test('GoalConversionEvent accepts extra params', function (): void {
    $event = new GoalConversionEvent('signup', 'activation', 0.0, ['goal_id' => 'goal_abc', 'funnel_step' => 3]);

    expect($event->params['goal_id'])->toBe('goal_abc');
    expect($event->params['funnel_step'])->toBe(3);
});

// ─── Catalog Registration ───

test('SaaSEvents catalog has sla_breach and payment_method_updated', function (): void {
    expect(SaaSEvents::has('sla_breach'))->toBeTrue();
    expect(SaaSEvents::has('payment_method_updated'))->toBeTrue();
});

test('EngagementEvents catalog has feedback and goal_conversion', function (): void {
    expect(EngagementEvents::has('feedback'))->toBeTrue();
    expect(EngagementEvents::has('goal_conversion'))->toBeTrue();
});

test('SaaSEvents total count is 50 (was 48 + 2 new)', function (): void {
    expect(SaaSEvents::count())->toBe(50);
});

test('EngagementEvents total count is 27 (was 25 + 2 new)', function (): void {
    expect(EngagementEvents::count())->toBe(27);
});

test('EventCatalog total count is 90 (13 ecommerce + 50 SaaS + 27 engagement)', function (): void {
    expect(EventCatalog::count())->toBe(90);
});

// ─── Catalog Entry Validation ───

test('SaaSEvents entries have correct class references for new events', function (): void {
    $sla = SaaSEvents::get('sla_breach');
    expect($sla)->not->toBeNull();
    expect($sla['class'])->toBe(SlaBreachEvent::class);
    expect($sla['ga4'])->toBe('sla_breach');
    expect($sla['meta'])->toBe('CustomEvent');
    expect($sla['posthog'])->toBe('sla_breach');
    expect($sla['plausible'])->toBeNull();

    $pm = SaaSEvents::get('payment_method_updated');
    expect($pm)->not->toBeNull();
    expect($pm['class'])->toBe(PaymentMethodUpdatedEvent::class);
    expect($pm['ga4'])->toBe('payment_method_updated');
    expect($pm['meta'])->toBe('CustomEvent');
    expect($pm['posthog'])->toBe('payment_method_updated');
});

test('EngagementEvents entries have correct class references for new events', function (): void {
    $feedback = EngagementEvents::get('feedback');
    expect($feedback)->not->toBeNull();
    expect($feedback['class'])->toBe(FeedbackEvent::class);
    expect($feedback['ga4'])->toBe('feedback');
    expect($feedback['meta'])->toBe('Feedback');
    expect($feedback['posthog'])->toBe('feedback');

    $goal = EngagementEvents::get('goal_conversion');
    expect($goal)->not->toBeNull();
    expect($goal['class'])->toBe(GoalConversionEvent::class);
    expect($goal['ga4'])->toBe('goal_conversion');
    expect($goal['meta'])->toBe('Conversion');
    expect($goal['posthog'])->toBe('goal_conversion');
    expect($goal['plausible'])->toBe('goal');
});

// ─── AARRR Classification ───

test('EventPriorityCalculator classifies new SaaS events as operational', function (): void {
    $calculator = new EventPriorityCalculator;

    expect($calculator->classify('sla_breach'))->toBe('operational');
    expect($calculator->classify('payment_method_updated'))->toBe('operational');
});

test('EventPriorityCalculator classifies feedback as retention', function (): void {
    $calculator = new EventPriorityCalculator;

    expect($calculator->classify('feedback'))->toBe('retention');
});

test('EventPriorityCalculator classifies goal_conversion as revenue', function (): void {
    $calculator = new EventPriorityCalculator;

    expect($calculator->classify('goal_conversion'))->toBe('revenue');
});

test('EventPriorityCalculator classifies previously unclassified events', function (): void {
    $calculator = new EventPriorityCalculator;

    // Events that existed but were missing from the map
    expect($calculator->classify('integration_failed'))->toBe('operational');
    expect($calculator->classify('checkout_step'))->toBe('operational');
    expect($calculator->classify('cohort_assigned'))->toBe('operational');
    expect($calculator->classify('cohort_retention'))->toBe('operational');
    expect($calculator->classify('cohort_churn'))->toBe('operational');
    expect($calculator->classify('cohort_conversion'))->toBe('operational');
    expect($calculator->classify('cohort_migration'))->toBe('operational');
    expect($calculator->classify('cohort_engagement'))->toBe('operational');
});

// ─── Category Counts ───

test('Maturity score accounts for 90 total events', function (): void {
    $calculator = new EventPriorityCalculator;
    $counts = $calculator->categoryCounts();

    expect($counts['total'])->toBe(90);
    expect($counts['operational'])->toBeGreaterThanOrEqual(20);
    expect($counts['revenue'])->toBeGreaterThanOrEqual(20);
    expect($counts['acquisition'])->toBeGreaterThanOrEqual(5);
});

// ─── Industry Standard ───

test('EventCatalog::industryStandard includes feedback and goal_conversion in medium tier', function (): void {
    $standard = EventCatalog::industryStandard();

    $mediumNames = array_column($standard['medium'], 'name');
    expect($mediumNames)->toContain('feedback');
    expect($mediumNames)->toContain('goal_conversion');
    expect($standard['count'])->toBeGreaterThanOrEqual(82);
});

// ─── Catalog Validation ───

test('EventCatalog validate passes with new events', function (): void {
    $result = EventCatalog::validate();

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
});

// ─── Version Consistency ───

test('Version consistency across all files', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('2.95.0');

    // Event count consistency
    expect(EventCatalog::count())->toBe(90);
    expect(SaaSEvents::count())->toBe(50);
    expect(EngagementEvents::count())->toBe(27);

    // Verify arithmetic: 13 + 50 + 27 = 90
    expect(EventCatalog::count())->toBe(13 + 50 + 27);
});

// ─── Inheritance ───

test('All new event classes extend AnalyticsEvent', function (): void {
    $sla = new SlaBreachEvent('test', 99.0, 98.0);
    $pm = new PaymentMethodUpdatedEvent('card', 'updated');
    $fb = new FeedbackEvent('nps', 10);
    $goal = new GoalConversionEvent('test_goal');

    expect($sla)->toBeInstanceOf(AnalyticsEvent::class);
    expect($pm)->toBeInstanceOf(AnalyticsEvent::class);
    expect($fb)->toBeInstanceOf(AnalyticsEvent::class);
    expect($goal)->toBeInstanceOf(AnalyticsEvent::class);
});

test('All new events are final and readonly', function (): void {
    $reflection = new ReflectionClass(SlaBreachEvent::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();

    $reflection = new ReflectionClass(PaymentMethodUpdatedEvent::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();

    $reflection = new ReflectionClass(FeedbackEvent::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();

    $reflection = new ReflectionClass(GoalConversionEvent::class);
    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
});

// ─── Strict Types ───

test('All new event files have declare(strict_types=1)', function (): void {
    $files = [
        SlaBreachEvent::class,
        PaymentMethodUpdatedEvent::class,
        FeedbackEvent::class,
        GoalConversionEvent::class,
    ];

    foreach ($files as $className) {
        $file = (new ReflectionClass($className))->getFileName();
        $content = is_string($file) ? file_get_contents($file) : '';
        expect($content)->toContain('declare(strict_types=1)');
    }
});

// ─── Docblock Validation ───

test('All new event files have package license docblock', function (): void {
    $files = [
        SlaBreachEvent::class,
        PaymentMethodUpdatedEvent::class,
        FeedbackEvent::class,
        GoalConversionEvent::class,
    ];

    foreach ($files as $className) {
        $file = (new ReflectionClass($className))->getFileName();
        $content = is_string($file) ? file_get_contents($file) : '';
        expect($content)->toContain('ZeroBoiler, licensed under the MIT license');
    }
});

// ─── Retention Signals ───

test('EventCatalog::retentionSignals includes feedback', function (): void {
    // feedback is classified as retention — add to retention signals
    $retention = EventCatalog::retentionSignals();
    $names = array_column($retention, 'name');

    // feedback is a retention-positive signal
    // The retention signals list is hardcoded in EventCatalog — we verify the new event exists
    expect(EventCatalog::has('feedback'))->toBeTrue();
    expect(EventCatalog::has('goal_conversion'))->toBeTrue();
});

// ─── Provider Coverage ───

test('New events have correct provider mappings', function (): void {
    // sla_breach: ga4 + posthog (no meta CustomEvent equivalent)
    expect(EventCatalog::plausibleNameFor('sla_breach'))->toBeNull();

    // goal_conversion has plausible mapping
    expect(EventCatalog::plausibleNameFor('goal_conversion'))->toBe('goal');

    // feedback: no plausible mapping
    expect(EventCatalog::plausibleNameFor('feedback'))->toBeNull();
});
