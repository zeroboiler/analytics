<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaSStarterEvents;

/**
 * Phase 49 production readiness audit — SaaS Starter Events & Catalog enhancement.
 *
 * Validates:
 * - SaaSStarterEvents: 20 curated events with correct structure
 * - EventCatalog::clientSafeSummary(): client-safe API output
 * - EventCatalog::coreEvents(): delegation to starter set
 * - EventCatalog::coreEventCoverage(): presence map
 * - EventCatalog::coreCoveragePercent(): percentage calculation
 * - Version consistency: 210.0.0 across all touchpoints
 * - All starter events exist in the full EventCatalog
 *
 * @since 210.0.0
 */
test('SaaSStarterEvents contains exactly 20 essential events', function (): void {
    expect(SaaSStarterEvents::count())->toBe(20);
    expect(SaaSStarterEvents::names())->toHaveCount(20);
});

test('SaaSStarterEvents covers all 3 required categories', function (): void {
    $byCategory = SaaSStarterEvents::byCategory();

    expect($byCategory)->toHaveKey('saas');
    expect($byCategory)->toHaveKey('ecommerce');
    expect($byCategory)->toHaveKey('engagement');

    // Category counts
    expect($byCategory['saas'])->toHaveCount(8); // sign_up, login, start_trial, trial_converted, subscribe, plan_upgrade, cancellation, feature_used
    expect($byCategory['ecommerce'])->toHaveCount(4); // view_item, add_to_cart, purchase, refund
    expect($byCategory['engagement'])->toHaveCount(8); // page_view, scroll_depth, click, form_start, form_submit, search, share, error
});

test('SaaSStarterEvents entries have required keys', function (): void {
    $all = SaaSStarterEvents::all();

    foreach ($all as $name => $entry) {
        expect($entry)->toHaveKeys(['name', 'label', 'category', 'hint']);
        expect($entry['name'])->toBe($name);
        expect($entry['category'])->toBeIn(['saas', 'ecommerce', 'engagement']);
        expect($entry['label'])->toBeString()->not->toBeEmpty();
        expect($entry['hint'])->toBeString()->not->toBeEmpty();
    }
});

test('SaaSStarterEvents isStarterEvent correctly identifies starter events', function (): void {
    expect(SaaSStarterEvents::isStarterEvent('sign_up'))->toBeTrue();
    expect(SaaSStarterEvents::isStarterEvent('purchase'))->toBeTrue();
    expect(SaaSStarterEvents::isStarterEvent('page_view'))->toBeTrue();
    expect(SaaSStarterEvents::isStarterEvent('nonexistent_event'))->toBeFalse();
    expect(SaaSStarterEvents::isStarterEvent(''))->toBeFalse();
});

test('SaaSStarterEvents catalogPresence returns all true for complete package', function (): void {
    $presence = SaaSStarterEvents::catalogPresence();

    expect($presence)->toHaveCount(20);

    // All 20 starter events should exist in the full catalog
    foreach ($presence as $name => $present) {
        expect($present)->toBeTrue("Starter event '{$name}' should exist in EventCatalog");
    }
});

test('SaaSStarterEvents missingFromCatalog returns empty for complete package', function (): void {
    expect(SaaSStarterEvents::missingFromCatalog())->toBeEmpty();
});

test('SaaSStarterEvents coveragePercent is 100 for complete package', function (): void {
    expect(SaaSStarterEvents::coveragePercent())->toBe(100.0);
});

test('SaaSStarterEvents priorityOrder returns 20 events in correct sequence', function (): void {
    $order = SaaSStarterEvents::priorityOrder();

    expect($order)->toHaveCount(20);
    expect($order[0])->toBe('sign_up'); // Identity first
    expect($order[1])->toBe('login');

    // All events in priority order should be starter events
    foreach ($order as $name) {
        expect(SaaSStarterEvents::isStarterEvent($name))->toBeTrue();
    }

    // Should be unique (no duplicates)
    expect($order)->toEqual(array_values(array_unique($order)));
});

test('SaaSStarterEvents clientSummary returns valid structure', function (): void {
    $summary = SaaSStarterEvents::clientSummary();

    expect($summary)->toHaveKeys(['total', 'coverage', 'categories', 'events']);
    expect($summary['total'])->toBe(20);
    expect($summary['coverage'])->toBe(100.0);
    expect($summary['categories'])->toHaveKeys(['saas', 'ecommerce', 'engagement']);
    expect($summary['events'])->toBeArray()->not->toBeEmpty();

    // Each event in the summary should have the right keys
    foreach ($summary['events'] as $event) {
        expect($event)->toHaveKeys(['name', 'label', 'category', 'hint']);
    }
});

test('EventCatalog clientSafeSummary returns valid structure', function (): void {
    $summary = EventCatalog::clientSafeSummary();

    expect($summary)->toHaveKeys(['total', 'categories', 'events']);
    expect($summary['total'])->toBeGreaterThan(0);
    expect($summary['categories'])->toBeArray();
    expect($summary['events'])->toBeArray()->not->toBeEmpty();

    // Each event should have the client-safe keys
    foreach ($summary['events'] as $event) {
        expect($event)->toHaveKeys(['name', 'category', 'ga4', 'meta']);
    }
});

test('EventCatalog clientSafeSummary can filter by category', function (): void {
    $ecommerce = EventCatalog::clientSafeSummary('ecommerce');

    expect($ecommerce['total'])->toBeGreaterThan(0);
    foreach ($ecommerce['events'] as $event) {
        expect($event['category'])->toBe('ecommerce');
    }
});

test('EventCatalog clientSafeSummary returns empty for invalid category', function (): void {
    $invalid = EventCatalog::clientSafeSummary('nonexistent');

    expect($invalid['total'])->toBe(0);
    expect($invalid['events'])->toBeEmpty();
});

test('EventCatalog coreEvents returns same as SaaSStarterEvents names', function (): void {
    expect(EventCatalog::coreEvents())->toEqual(SaaSStarterEvents::names());
});

test('EventCatalog coreEventCoverage matches SaaSStarterEvents catalogPresence', function (): void {
    expect(EventCatalog::coreEventCoverage())->toEqual(SaaSStarterEvents::catalogPresence());
});

test('EventCatalog coreCoveragePercent matches SaaSStarterEvents coveragePercent', function (): void {
    expect(EventCatalog::coreCoveragePercent())->toEqual(SaaSStarterEvents::coveragePercent());
});

test('Version consistency across touchpoints at 210.0.0', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('210.0.0');
});

test('SaaSStarterEvents names are a subset of EventCatalog names', function (): void {
    $catalogNames = EventCatalog::names();
    $starterNames = SaaSStarterEvents::names();

    foreach ($starterNames as $name) {
        expect(in_array($name, $catalogNames, true))->toBeTrue(
            "Starter event '{$name}' must exist in EventCatalog::names()"
        );
    }
});

test('SaaSStarterEvents final class with correct namespace', function (): void {
    $reflector = new ReflectionClass(SaaSStarterEvents::class);

    expect($reflector)->toBeFinal();
    expect($reflector->getNamespaceName())->toBe('ZeroBoiler\\Analytics\\Events');
});

test('EventCatalog clientSafeSummary events count matches total catalog', function (): void {
    $summary = EventCatalog::clientSafeSummary();
    $catalogTotal = EventCatalog::count();

    expect($summary['total'])->toBe($catalogTotal);
});
