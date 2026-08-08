<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EventPriorityCalculator;

test('EventPriorityCalculator classifies events into AARRR categories', function (): void {
    $calculator = new EventPriorityCalculator;

    expect($calculator->classify('sign_up'))->toBe('acquisition');
    expect($calculator->classify('login'))->toBe('activation');
    expect($calculator->classify('session_start'))->toBe('retention');
    expect($calculator->classify('purchase'))->toBe('revenue');
    expect($calculator->classify('share'))->toBe('referral');
    expect($calculator->classify('error'))->toBe('operational');
    expect($calculator->classify('unknown_event'))->toBe('operational');
});

test('EventPriorityCalculator classifies all catalog events', function (): void {
    $calculator = new EventPriorityCalculator;
    $classified = $calculator->classifyAll();

    expect($classified)->toHaveKeys([
        'acquisition', 'activation', 'retention', 'revenue', 'referral', 'operational',
    ]);

    // Revenue should have the most events
    expect(count($classified['revenue']))->toBeGreaterThanOrEqual(20);
    expect(count($classified['acquisition']))->toBeGreaterThanOrEqual(3);
    expect(count($classified['activation']))->toBeGreaterThanOrEqual(5);
    expect(count($classified['retention']))->toBeGreaterThanOrEqual(4);
});

test('EventPriorityCalculator category counts sum to total', function (): void {
    $calculator = new EventPriorityCalculator;
    $counts = $calculator->categoryCounts();

    $sum = $counts['acquisition']
        + $counts['activation']
        + $counts['retention']
        + $counts['revenue']
        + $counts['referral']
        + $counts['operational'];

    expect($sum)->toBe($counts['total']);
    expect($counts['total'])->toBe(EventCatalog::count());
});

test('EventPriorityCalculator maturity score returns valid structure', function (): void {
    $calculator = new EventPriorityCalculator;
    $result = $calculator->maturityScore();

    expect($result)->toHaveKeys(['score', 'grade', 'details']);
    expect($result['score'])->toBeInt();
    expect($result['score'])->toBeGreaterThanOrEqual(0);
    expect($result['score'])->toBeLessThanOrEqual(100);
    expect($result['grade'])->toBeString();

    // Details should have the expected sub-scores
    expect($result['details'])->toHaveKeys([
        'critical_events', 'aarr_categories', 'providers', 'catalog_size',
    ]);

    // Critical events breakdown
    expect($result['details']['critical_events'])->toHaveKeys([
        'present', 'total', 'score', 'max_score', 'missing',
    ]);
    expect($result['details']['critical_events']['total'])->toBe(8);
});

test('EventPriorityCalculator maturity score is high for this catalog', function (): void {
    $calculator = new EventPriorityCalculator;
    $result = $calculator->maturityScore();

    // This catalog has 84+ events and should score at least 80 (industry standard)
    expect($result['score'])->toBeGreaterThanOrEqual(80);
});

test('EventPriorityCalculator onboarding checklist returns valid structure', function (): void {
    $calculator = new EventPriorityCalculator;
    $checklist = $calculator->onboardingChecklist();

    expect($checklist)->toHaveKeys(['checklist', 'summary']);

    // Checklist should have AARRR categories (not operational)
    expect($checklist['checklist'])->toHaveKeys([
        'acquisition', 'activation', 'retention', 'revenue', 'referral',
    ]);
    expect($checklist['checklist'])->not->toHaveKey('operational');

    // Summary
    expect($checklist['summary'])->toHaveKeys(['total', 'tracked', 'completion', 'gaps']);
    expect($checklist['summary']['completion'])->toBeFloat();
    expect($checklist['summary']['completion'])->toBeGreaterThanOrEqual(0.0);
    expect($checklist['summary']['completion'])->toBeLessThanOrEqual(100.0);

    // Each checklist item should have event, tracked, priority
    foreach ($checklist['checklist'] as $category => $items) {
        foreach ($items as $item) {
            expect($item)->toHaveKeys(['event', 'tracked', 'priority']);
            expect(in_array($item['priority'], ['critical', 'high', 'medium', 'low'], true))->toBeTrue();
        }
    }
});

test('EventPriorityCalculator funnel readiness returns valid structure', function (): void {
    $calculator = new EventPriorityCalculator;
    $result = $calculator->funnelReadiness();

    expect($result)->toHaveKeys([
        'signup_funnel', 'purchase_funnel', 'subscription_funnel', 'overall',
    ]);

    foreach (['signup_funnel', 'purchase_funnel', 'subscription_funnel'] as $funnel) {
        expect($result[$funnel])->toHaveKeys(['steps', 'present', 'missing', 'score']);
        expect($result[$funnel]['score'])->toBeFloat();
        expect($result[$funnel]['score'])->toBeGreaterThanOrEqual(0.0);
        expect($result[$funnel]['score'])->toBeLessThanOrEqual(100.0);
    }

    expect($result['overall'])->toBeFloat();
    expect($result['overall'])->toBeGreaterThanOrEqual(0.0);
    expect($result['overall'])->toBeLessThanOrEqual(100.0);
});

test('EventPriorityCalculator funnel readiness is high for this catalog', function (): void {
    $calculator = new EventPriorityCalculator;
    $result = $calculator->funnelReadiness();

    // All funnels should be fully covered in this catalog
    expect($result['signup_funnel']['score'])->toBe(100.0);
    expect($result['purchase_funnel']['score'])->toBe(100.0);
    expect($result['subscription_funnel']['score'])->toBe(100.0);
    expect($result['overall'])->toBe(100.0);
});

test('EventPriorityCalculator getEventPriority returns valid levels', function (): void {
    $calculator = new EventPriorityCalculator;

    // Critical events
    expect($calculator->getEventPriority('sign_up'))->toBe('critical');
    expect($calculator->getEventPriority('purchase'))->toBe('critical');
    expect($calculator->getEventPriority('cancellation'))->toBe('critical');

    // Revenue events are high
    expect($calculator->getEventPriority('add_to_cart'))->toBe('high');
    expect($calculator->getEventPriority('refund'))->toBe('high');

    // Acquisition/activation are high
    expect($calculator->getEventPriority('email_verified'))->toBe('high');
    expect($calculator->getEventPriority('feature_used'))->toBe('high');

    // Retention/referral are medium
    expect($calculator->getEventPriority('session_start'))->toBe('medium');
    expect($calculator->getEventPriority('team_created'))->toBe('medium');

    // Operational is low
    expect($calculator->getEventPriority('click'))->toBe('low');
    expect($calculator->getEventPriority('js_error'))->toBe('low');
});

test('EventPriorityCalculator under instrumented categories', function (): void {
    $calculator = new EventPriorityCalculator;
    $deficits = $calculator->underInstrumentedCategories();

    // This catalog is comprehensive so should have no deficits
    expect($deficits)->toBeEmpty();
});

test('EventPriorityCalculator events by category returns arrays', function (): void {
    $calculator = new EventPriorityCalculator;

    $revenue = $calculator->eventsByCategory('revenue');
    expect($revenue)->toBeArray();
    expect(count($revenue))->toBeGreaterThanOrEqual(20);

    $acquisition = $calculator->eventsByCategory('acquisition');
    expect($acquisition)->toBeArray();
    expect(count($acquisition))->toBeGreaterThanOrEqual(3);

    // Unknown category returns empty
    expect($calculator->eventsByCategory('nonexistent'))->toBeEmpty();
});

test('EventCatalog::industryStandard returns valid structure', function (): void {
    $result = EventCatalog::industryStandard();

    expect($result)->toHaveKeys(['critical', 'high', 'medium', 'low', 'all', 'count']);
    expect($result['count'])->toBeInt();
    expect($result['count'])->toBeGreaterThan(0);

    // Each priority level is an array of event entries
    foreach (['critical', 'high', 'medium', 'low'] as $level) {
        expect($result[$level])->toBeArray();
        foreach ($result[$level] as $entry) {
            expect($entry)->toHaveKey('name');
            expect($entry)->toHaveKey('category');
        }
    }

    // All should contain all priority events
    expect(count($result['all']))->toBeGreaterThanOrEqual(count($result['critical']));
    expect(count($result['all']))->toBeGreaterThanOrEqual(count($result['high']));
});

test('EventCatalog::industryStandard critical events are all tracked', function (): void {
    $result = EventCatalog::industryStandard();

    // All critical events should exist in the catalog
    foreach ($result['critical'] as $entry) {
        expect(EventCatalog::has($entry['name']))->toBeTrue();
    }
});

test('EventCatalog::eventCategory delegates to EventPriorityCalculator', function (): void {
    expect(EventCatalog::eventCategory('sign_up'))->toBe('acquisition');
    expect(EventCatalog::eventCategory('purchase'))->toBe('revenue');
    expect(EventCatalog::eventCategory('login'))->toBe('activation');
});

test('EventCatalog::eventPriority delegates to EventPriorityCalculator', function (): void {
    expect(EventCatalog::eventPriority('sign_up'))->toBe('critical');
    expect(EventCatalog::eventPriority('purchase'))->toBe('critical');
    expect(EventCatalog::eventPriority('click'))->toBe('low');
});

test('AnalyticsManager maturityScore returns consistent result', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $result = $manager->maturityScore();

    expect($result)->toHaveKey('score');
    expect($result['score'])->toBeInt();
    expect($result['score'])->toBeGreaterThanOrEqual(80);
    expect($result)->toHaveKey('grade');
});

test('AnalyticsManager onboardingChecklist returns completion percentage', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $result = $manager->onboardingChecklist();

    expect($result['summary']['completion'])->toBeGreaterThanOrEqual(90.0);
    expect($result['summary']['gaps'])->toBeArray();
});

test('AnalyticsManager funnelReadiness returns high scores', function (): void {
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $result = $manager->funnelReadiness();

    expect($result['overall'])->toBe(100.0);
    expect($result['signup_funnel']['score'])->toBe(100.0);
    expect($result['purchase_funnel']['score'])->toBe(100.0);
    expect($result['subscription_funnel']['score'])->toBe(100.0);
});

test('EventPriorityCalculator critical events include all 8 required events', function (): void {
    $reflection = new ReflectionClass(EventPriorityCalculator::class);
    $property = $reflection->getProperty('CRITICAL_SAAS_EVENTS');
    $property->setAccessible(true);
    $critical = $property->getValue();

    expect($critical)->toBe([
        'sign_up', 'login', 'start_trial', 'subscribe',
        'plan_upgrade', 'cancellation', 'page_view', 'purchase',
    ]);
    expect(count($critical))->toBe(8);
});
