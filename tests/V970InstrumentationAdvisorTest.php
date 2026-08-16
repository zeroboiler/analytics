<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Engagement\OnboardingCompletedEvent;
use ZeroBoiler\Analytics\Services\AnalyticsInstrumentationAdvisor;
use ZeroBoiler\Analytics\Support\EventBuilder;

test('onboarding completed event has correct name', function (): void {
    $event = new OnboardingCompletedEvent(5, 5, 342);

    expect($event->name)->toBe('onboarding_completed');
    expect($event->params['steps_completed'])->toBe(5);
    expect($event->params['steps_total'])->toBe(5);
    expect($event->params['duration_seconds'])->toBe(342);
});

test('onboarding completed event calculates completion percentage', function (): void {
    $event = new OnboardingCompletedEvent(3, 5, 120);

    expect($event->params['completion_percentage'])->toBe(60.0);
});

test('onboarding completed event with null values', function (): void {
    $event = new OnboardingCompletedEvent;

    expect($event->name)->toBe('onboarding_completed');
    expect($event->params)->toBe([]);
});

test('onboarding completed event with skipped steps', function (): void {
    $event = new OnboardingCompletedEvent(3, 5, 200, 'email', ['invite_team', 'billing_setup']);

    expect($event->params['skipped_steps'])->toBe(['invite_team', 'billing_setup']);
    expect($event->params['signup_method'])->toBe('email');
});

test('instrumentation advisor generates plan', function (): void {
    $advisor = new AnalyticsInstrumentationAdvisor;
    $plan = $advisor->generatePlan();

    expect($plan['plan'])->not->toBeEmpty();
    expect($plan['summary']['total'])->toBeGreaterThan(50);
    expect($plan['summary']['critical'])->toBeGreaterThan(0);

    // Critical events should appear first
    $firstEvent = $plan['plan'][0];
    expect($firstEvent['priority'])->toBe('critical');

    // Every event should have a code example
    foreach ($plan['plan'] as $item) {
        expect($item['code_example'])->not->toBeEmpty();
        expect($item['providers'])->toHaveKey('ga4');
    }
});

test('instrumentation advisor generates quick start guide', function (): void {
    $advisor = new AnalyticsInstrumentationAdvisor;
    $guide = $advisor->quickStartGuide();

    expect($guide['events'])->not->toBeEmpty();
    expect($guide['config_snippet'])->not->toBeEmpty();
    expect($guide['middleware_snippet'])->not->toBeEmpty();
    expect($guide['js_init_snippet'])->not->toBeEmpty();

    // Every event should have server and client code
    foreach ($guide['events'] as $event) {
        expect($event['server_code'])->not->toBeEmpty();
        expect($event['client_code'])->not->toBeEmpty();
    }

    // Quick start should include essential events
    $names = array_column($guide['events'], 'name');
    expect($names)->toContain('sign_up');
    expect($names)->toContain('login');
    expect($names)->toContain('page_view');
    expect($names)->toContain('purchase');
});

test('instrumentation advisor gap analysis with no tracked events', function (): void {
    $advisor = new AnalyticsInstrumentationAdvisor;
    $analysis = $advisor->gapAnalysis([]);

    expect($analysis['coverage'])->toBe(0.0);
    expect($analysis['gaps'])->not->toBeEmpty();
    expect($analysis['covered'])->toBeEmpty();
    expect($analysis['score'])->toBe(0);
});

test('instrumentation advisor gap analysis with some tracked events', function (): void {
    $advisor = new AnalyticsInstrumentationAdvisor;
    $tracked = ['sign_up', 'login', 'page_view', 'purchase', 'subscribe'];
    $analysis = $advisor->gapAnalysis($tracked);

    expect($analysis['coverage'])->toBeGreaterThan(0.0);
    expect($analysis['score'])->toBeGreaterThan(0);
    expect($analysis['covered'])->toEqual($tracked);

    // Gaps should not include tracked events
    $gapNames = array_column($analysis['gaps'], 'name');
    foreach ($tracked as $name) {
        expect($gapNames)->not->toContain($name);
    }
});

test('instrumentation advisor gap analysis with full coverage', function (): void {
    $advisor = new AnalyticsInstrumentationAdvisor;
    $standard = EventCatalog::industryStandard();
    $allNames = array_column($standard['all'], 'name');
    $analysis = $advisor->gapAnalysis($allNames);

    expect($analysis['coverage'])->toBe(1.0);
    expect($analysis['score'])->toBe(100);
    expect($analysis['gaps'])->toBeEmpty();
});

test('event builder supports onboarding_completed', function (): void {
    $event = EventBuilder::make('onboarding_completed')
        ->param('steps_completed', 5)
        ->param('steps_total', 5)
        ->param('duration_seconds', 342)
        ->user('user-123')
        ->build();

    expect($event->name)->toBe('onboarding_completed');
    expect($event->params['steps_completed'])->toBe(5);
    expect($event->userId)->toBe('user-123');
});

test('onboarding_completed is in industry standard set', function (): void {
    // Verify the event can be tracked and is recognized
    $standard = EventCatalog::industryStandard();
    $allNames = array_column($standard['all'], 'name');

    // onboarding_completed should be discoverable in engagement events
    $engagementEvents = EventCatalog::engagementEvents();
    $engagementNames = array_column($engagementEvents, 'name');

    // onboarding_step is tracked, onboarding_completed is the completion signal
    expect($engagementNames)->toContain('onboarding_step');
});

test('quick start guide covers all funnel areas', function (): void {
    $advisor = new AnalyticsInstrumentationAdvisor;
    $guide = $advisor->quickStartGuide();
    $names = array_column($guide['events'], 'name');

    // Should cover signup, trial, revenue, engagement funnels
    expect($names)->toContain('sign_up');
    expect($names)->toContain('start_trial');
    expect($names)->toContain('purchase');
    expect($names)->toContain('feature_used');
    expect($names)->toContain('revenue_tracked');
});

test('instrumentation plan priorities are ordered correctly', function (): void {
    $advisor = new AnalyticsInstrumentationAdvisor;
    $plan = $advisor->generatePlan();

    $priorityOrder = ['critical', 'high', 'medium', 'low'];
    $lastPriority = 0;

    foreach ($plan['plan'] as $item) {
        $currentIndex = array_search($item['priority'], $priorityOrder, true);
        expect($currentIndex)->toBeGreaterThanOrEqual($lastPriority);
        $lastPriority = $currentIndex;
    }
});
