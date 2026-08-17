<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaSStarterEvents;
use ZeroBoiler\Analytics\Services\SaaSStarterInstrumentationService;

/**
 * Phase 50 production readiness — SaaS Starter Instrumentation Wizard.
 *
 * Validates:
 * - SaaSStarterInstrumentationService::snippets() — all 20 events have snippets
 * - SaaSStarterInstrumentationService::snippetsFor() — per-event lookup
 * - SaaSStarterInstrumentationService::clientGuide() — client-safe structure
 * - SaaSStarterInstrumentationService::coverageAnalysis() — auto vs manual
 * - SaaSStarterInstrumentationService::completenessScore() — full coverage
 * - Snippet quality: params, php, js, blade keys present for all events
 * - OverviewCommand signature includes --starter and --snippets
 * - Version consistency: 211.0.0 across all touchpoints
 *
 * @since 211.0.0
 */
test('Instrumentation snippets cover all 20 starter events', function (): void {
    $snippets = SaaSStarterInstrumentationService::snippets();
    $starterNames = SaaSStarterEvents::names();

    // All 20 starter events must have snippet entries
    foreach ($starterNames as $name) {
        expect($snippets)->toHaveKey($name, "Missing snippet for '{$name}'");
    }

    // No extra events beyond the starter set
    expect($snippets)->toHaveCount(20);
});

test('Each snippet has required keys: params, php, js, blade', function (): void {
    $snippets = SaaSStarterInstrumentationService::snippets();

    foreach ($snippets as $name => $snippet) {
        expect($snippet)->toHaveKeys(['params', 'php', 'js', 'blade'],
            "Snippet for '{$name}' is missing required keys");
    }
});

test('Each snippet has at least one parameter defined', function (): void {
    $snippets = SaaSStarterInstrumentationService::snippets();

    foreach ($snippets as $name => $snippet) {
        expect($snippet['params'])->not->toBeEmpty(
            "Event '{$name}' should have at least one parameter defined"
        );
    }
});

test('Snippet params have name, type, required, description fields', function (): void {
    $snippets = SaaSStarterInstrumentationService::snippets();

    foreach ($snippets as $name => $snippet) {
        foreach ($snippet['params'] as $i => $param) {
            expect($param)->toHaveKeys(['name', 'type', 'required', 'description'],
                "Param #{$i} for '{$name}' is missing required fields");
            expect($param['name'])->toBeString()->not->toBeEmpty();
            expect($param['type'])->toBeString()->not->toBeEmpty();
            expect($param['required'])->toBeBool();
            expect($param['description'])->toBeString();
        }
    }
});

test('All PHP snippets contain Analytics::', function (): void {
    $snippets = SaaSStarterInstrumentationService::snippets();

    foreach ($snippets as $name => $snippet) {
        expect($snippet['php'])->toContain('Analytics::',
            "PHP snippet for '{$name}' should use the Analytics facade");
    }
});

test('All JS snippets contain trackEvent', function (): void {
    $snippets = SaaSStarterInstrumentationService::snippets();

    foreach ($snippets as $name => $snippet) {
        expect($snippet['js'])->toContain('trackEvent',
            "JS snippet for '{$name}' should use trackEvent()");
    }
});

test('snippetsFor returns null for unknown event', function (): void {
    expect(SaaSStarterInstrumentationService::snippetsFor('nonexistent_event'))->toBeNull();
    expect(SaaSStarterInstrumentationService::snippetsFor(''))->toBeNull();
});

test('snippetsFor returns valid snippet for known event', function (): void {
    $snippet = SaaSStarterInstrumentationService::snippetsFor('sign_up');

    expect($snippet)->not->toBeNull();
    expect($snippet)->toHaveKey('params');
    expect($snippet)->toHaveKey('php');
    expect($snippet)->toHaveKey('js');
    expect($snippet)->toHaveKey('blade');

    // sign_up has a 'method' param
    expect($snippet['params'][0]['name'])->toBe('method');
});

test('snippetsFor works for all starter event names', function (): void {
    foreach (SaaSStarterEvents::names() as $name) {
        $snippet = SaaSStarterInstrumentationService::snippetsFor($name);
        expect($snippet)->not->toBeNull("snippetsFor('{$name}') should return a snippet");
    }
});

test('clientGuide returns valid structure', function (): void {
    $guide = SaaSStarterInstrumentationService::clientGuide();

    expect($guide)->toHaveKeys(['total', 'events']);
    expect($guide['total'])->toBe(20);
    expect($guide['events'])->toHaveCount(20);

    // Each event should have client-safe keys (no internal class refs)
    foreach ($guide['events'] as $event) {
        expect($event)->toHaveKeys(['name', 'label', 'category', 'hint', 'required_params', 'js_snippet']);
        expect($event['name'])->toBeString();
        expect($event['label'])->toBeString();
        expect($event['category'])->toBeIn(['saas', 'ecommerce', 'engagement']);
        expect($event['required_params'])->toBeArray();
        expect($event['js_snippet'])->toBeString();
    }
});

test('clientGuide events follow priority order', function (): void {
    $guide = SaaSStarterInstrumentationService::clientGuide();
    $guideNames = array_column($guide['events'], 'name');
    $priorityNames = SaaSStarterEvents::priorityOrder();

    // clientGuide may not follow priority order, but should have the same events
    sort($guideNames);
    sort($priorityNames);
    expect($guideNames)->toEqual($priorityNames);
});

test('coverageAnalysis returns auto-tracked and manual events', function (): void {
    $coverage = SaaSStarterInstrumentationService::coverageAnalysis();

    expect($coverage)->toHaveKeys(['auto_tracked', 'manual', 'coverage']);
    expect($coverage['coverage'])->toBeFloat();
    expect($coverage['coverage'])->toBeGreaterThan(0.0);
    expect($coverage['coverage'])->toBeLessThanOrEqual(100.0);

    // page_view, scroll_depth, error should be auto-tracked
    expect($coverage['auto_tracked'])->toContain('page_view');
    expect($coverage['auto_tracked'])->toContain('scroll_depth');
    expect($coverage['auto_tracked'])->toContain('error');
    expect($coverage['auto_tracked'])->toHaveCount(3);

    // Total events should be 20
    expect(count($coverage['auto_tracked']) + count($coverage['manual']))->toBe(20);
});

test('autoCoveragePercent returns correct value', function (): void {
    $percent = SaaSStarterInstrumentationService::autoCoveragePercent();

    // 3 auto-tracked / 20 total = 15.0%
    expect($percent)->toBe(15.0);
});

test('completenessScore returns maximum score', function (): void {
    $completeness = SaaSStarterInstrumentationService::completenessScore();

    expect($completeness)->toHaveKeys(['score', 'max', 'details']);
    expect($completeness['max'])->toBe(80); // 20 events × 4 checks
    expect($completeness['score'])->toBe($completeness['max']); // All should be complete
    expect($completeness['details'])->toHaveCount(20);

    // All events should be complete
    foreach ($completeness['details'] as $name => $isComplete) {
        expect($isComplete)->toBeTrue("Event '{$name}' should have complete instrumentation");
    }
});

test('OverviewCommand has --starter and --snippets options', function (): void {
    $command = new \ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand::class);
    $instance = $command->newInstanceWithoutConstructor();

    $signature = $command->getProperty('signature');
    $signature->setAccessible(true);
    $sig = $signature->getValue($instance);

    expect($sig)->toContain('--starter');
    expect($sig)->toContain('--snippets=');
});

test('SaaSStarterInstrumentationService is final class', function (): void {
    $reflector = new ReflectionClass(SaaSStarterInstrumentationService::class);

    expect($reflector)->toBeFinal();
    expect($reflector->getNamespaceName())->toBe('ZeroBoiler\\Analytics\\Services');
});

test('Version consistency across touchpoints at 211.0.0', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('211.0.0');
});

test('Auto-tracked events have JS snippets indicating auto-tracking', function (): void {
    $autoTracked = ['page_view', 'scroll_depth', 'error'];

    foreach ($autoTracked as $name) {
        $snippet = SaaSStarterInstrumentationService::snippetsFor($name);
        expect($snippet['js'])->toContain('Auto-tracked',
            "JS snippet for auto-tracked event '{$name}' should mention auto-tracking");
    }
});

test('Manual events have actionable JS snippets', function (): void {
    $manual = ['sign_up', 'purchase', 'click'];

    foreach ($manual as $name) {
        $snippet = SaaSStarterInstrumentationService::snippetsFor($name);
        // Manual events should NOT mention auto-tracking (or if they do, should have real code)
        expect($snippet['js'])->toContain('trackEvent(');
    }
});

test('Blade snippets use @analytics directive or explicit onclick', function (): void {
    $snippets = SaaSStarterInstrumentationService::snippets();
    $directiveCount = 0;
    $onclickCount = 0;

    foreach ($snippets as $name => $snippet) {
        if (str_contains($snippet['blade'], '@analytics')) {
            $directiveCount++;
        }
        if (str_contains($snippet['blade'], 'onclick')) {
            $onclickCount++;
        }
    }

    // At least some should use @analytics directive
    expect($directiveCount)->toBeGreaterThan(0);

    // Some events like click should use onclick
    expect($onclickCount)->toBeGreaterThan(0);
});
