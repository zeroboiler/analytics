<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaSStarterEvents;

/**
 * Validates SaaSStarterEvents::providerCoverage() and providerCoverageSummary().
 *
 * @since 266.0.0
 */
test('SaaSStarterEvents::providerCoverage returns all 20 starter events', function (): void {
    $coverage = SaaSStarterEvents::providerCoverage();

    expect(count($coverage))->toBe(SaaSStarterEvents::count());

    // Every starter event must have a coverage entry
    foreach (SaaSStarterEvents::names() as $name) {
        expect(isset($coverage[$name]))->toBeTrue("Missing coverage entry for {$name}");
    }
});

test('providerCoverage entries have correct structure', function (): void {
    $coverage = SaaSStarterEvents::providerCoverage();

    foreach ($coverage as $name => $entry) {
        expect($entry)->toHaveKeys([
            'event', 'label', 'category', 'providers',
            'covered_count', 'total_providers', 'coverage_pct', 'fully_covered',
        ]);
        expect($entry['event'])->toBe($name);
        expect($entry['total_providers'])->toBe(8);
        expect($entry['covered_count'])->toBeGreaterThanOrEqual(0);
        expect($entry['covered_count'])->toBeLessThanOrEqual(8);
        expect($entry['coverage_pct'])->toBeGreaterThanOrEqual(0.0);
        expect($entry['coverage_pct'])->toBeLessThanOrEqual(100.0);
        expect($entry['fully_covered'])->toBeBool();

        // Provider map must have exactly 8 keys
        expect(count($entry['providers']))->toBe(8);
        expect($entry['providers'])->toHaveKeys([
            'ga4', 'meta', 'posthog', 'plausible',
            'mixpanel', 'amplitude', 'tiktok', 'linkedin',
        ]);
    }
});

test('providerCoverage GA4 covers 100% of starter events', function (): void {
    $coverage = SaaSStarterEvents::providerCoverage();

    foreach ($coverage as $name => $entry) {
        expect($entry['providers']['ga4'])->not->toBeNull("GA4 mapping missing for {$name}");
    }
});

test('providerCoverage fully_covered is consistent with covered_count', function (): void {
    $coverage = SaaSStarterEvents::providerCoverage();

    foreach ($coverage as $name => $entry) {
        $expectedFully = $entry['covered_count'] === 8;
        expect($entry['fully_covered'])->toBe($expectedFully);
    }
});

test('providerCoverageSummary returns correct structure', function (): void {
    $summary = SaaSStarterEvents::providerCoverageSummary();

    expect($summary)->toHaveKeys([
        'providers', 'overall_pct', 'fully_covered_events', 'total_events',
    ]);

    expect($summary['total_events'])->toBe(SaaSStarterEvents::count());
    expect($summary['overall_pct'])->toBeGreaterThanOrEqual(0.0);
    expect($summary['overall_pct'])->toBeLessThanOrEqual(100.0);
    expect($summary['fully_covered_events'])->toBeGreaterThanOrEqual(0);
    expect($summary['fully_covered_events'])->toBeLessThanOrEqual($summary['total_events']);
});

test('providerCoverageSummary has all 8 providers', function (): void {
    $summary = SaaSStarterEvents::providerCoverageSummary();

    expect($summary['providers'])->toHaveKeys([
        'ga4', 'meta', 'posthog', 'plausible',
        'mixpanel', 'amplitude', 'tiktok', 'linkedin',
    ]);

    foreach ($summary['providers'] as $provider => $data) {
        expect($data)->toHaveKeys(['covered', 'total', 'pct', 'uncovered_events']);
        expect($data['covered'])->toBeGreaterThanOrEqual(0);
        expect($data['covered'])->toBeLessThanOrEqual($data['total']);
        expect($data['pct'])->toBeGreaterThanOrEqual(0.0);
        expect($data['pct'])->toBeLessThanOrEqual(100.0);
        expect($data['total'])->toBe(SaaSStarterEvents::count());
    }
});

test('providerCoverageSummary uncovered_events are truly uncovered', function (): void {
    $summary = SaaSStarterEvents::providerCoverageSummary();
    $coverage = SaaSStarterEvents::providerCoverage();

    foreach ($summary['providers'] as $provider => $data) {
        foreach ($data['uncovered_events'] as $eventName) {
            $mapped = $coverage[$eventName]['providers'][$provider] ?? null;
            expect($mapped)->toBeNull(
                "Event {$eventName} should not be in uncovered list for {$provider} (has mapping: {$mapped})"
            );
        }
    }
});

test('providerCoverageSummary GA4 has 100% coverage', function (): void {
    $summary = SaaSStarterEvents::providerCoverageSummary();

    expect($summary['providers']['ga4']['pct'])->toBe(100.0);
    expect($summary['providers']['ga4']['covered'])->toBe(SaaSStarterEvents::count());
    expect($summary['providers']['ga4']['uncovered_events'])->toBe([]);
});

test('providerCoverage values are consistent with EventCatalog', function (): void {
    $coverage = SaaSStarterEvents::providerCoverage();

    foreach ($coverage as $name => $entry) {
        $catalogEntry = EventCatalog::get($name);
        expect($catalogEntry)->not->toBeNull("Event {$name} not found in EventCatalog");

        foreach (['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'] as $provider) {
            $expected = $catalogEntry[$provider] ?? null;
            expect($entry['providers'][$provider])->toBe($expected);
        }
    }
});

test('SaaSStarterEvents::providerCoverage file quality checks', function (): void {
    $ref = new ReflectionClass(SaaSStarterEvents::class);

    // Class is final
    expect($ref->isFinal())->toBeTrue();

    // Has providerCoverage method
    expect($ref->hasMethod('providerCoverage'))->toBeTrue();
    $method = $ref->getMethod('providerCoverage');
    expect($method->isPublic())->toBeTrue();
    expect($method->isStatic())->toBeTrue();
    expect($method->hasReturnType())->toBeTrue();

    // Has providerCoverageSummary method
    expect($ref->hasMethod('providerCoverageSummary'))->toBeTrue();
    $method2 = $ref->getMethod('providerCoverageSummary');
    expect($method2->isPublic())->toBeTrue();
    expect($method2->isStatic())->toBeTrue();
    expect($method2->hasReturnType())->toBeTrue();
});
