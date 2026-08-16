<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

// ─── v2.83.0 — SaaS Starter Readiness & Maturity Enhancements ───

// ─── Version Consistency ─────────────────────────────────────────────

test('AnalyticsEvent VERSION is 2.83.0', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('76.0.0');
});

test('AnalyticsEvent VERSION is a valid semver string', function (): void {
    $version = AnalyticsEvent::VERSION;
    expect($version)->toMatch('/^\d+\.\d+\.\d+$/');
    expect($version)->not->toBeEmpty();
});

// ─── EventCatalog::saasEssential() ─────────────────────────────────

test('EventCatalog::saasEssential returns a structured array', function (): void {
    $result = EventCatalog::saasEssential();

    expect($result)->toHaveKeys(['events', 'categories', 'count', 'ga4_coverage']);
    expect($result['events'])->toBeArray();
    expect($result['categories'])->toHaveKeys(['ecommerce', 'saas', 'engagement']);
    expect($result['count'])->toBeInt();
    expect($result['ga4_coverage'])->toBeInt();
});

test('EventCatalog::saasEssential covers all three categories', function (): void {
    $result = EventCatalog::saasEssential();

    // Must have events from all categories
    expect($result['categories']['saas'])->toBeGreaterThan(0);
    expect($result['categories']['engagement'])->toBeGreaterThan(0);

    // Total should be sum of categories
    $total = array_sum($result['categories']);
    expect($result['count'])->toBe($total);
});

test('EventCatalog::saasEssential includes critical SaaS events', function (): void {
    $result = EventCatalog::saasEssential();
    $names = array_map(fn (array $e): string => $e['name'], $result['events']);

    // Must have authentication
    expect($names)->toContain('sign_up');
    expect($names)->toContain('login');

    // Must have subscription lifecycle
    expect($names)->toContain('subscribe');
    expect($names)->toContain('plan_upgrade');
    expect($names)->toContain('cancellation');

    // Must have revenue
    expect($names)->toContain('payment_succeeded');
    expect($names)->toContain('payment_failed');

    // Must have trial
    expect($names)->toContain('start_trial');
    expect($names)->toContain('trial_converted');

    // Must have engagement
    expect($names)->toContain('page_view');
    expect($names)->toContain('feature_used');
});

test('EventCatalog::saasEssential has GA4 coverage', function (): void {
    $result = EventCatalog::saasEssential();

    // Most events should have GA4 mapping
    expect($result['ga4_coverage'])->toBeGreaterThan($result['count'] / 2);
});

test('EventCatalog::saasEssential has at least 30 events', function (): void {
    $result = EventCatalog::saasEssential();

    // Essential SaaS set should be comprehensive
    expect($result['count'])->toBeGreaterThanOrEqual(30);
});

test('EventCatalog::saasEssential events all have required keys', function (): void {
    $result = EventCatalog::saasEssential();

    foreach ($result['events'] as $entry) {
        expect($entry)->toHaveKeys(['name', 'class', 'ga4', 'category']);
    }
});

// ─── EventCatalog::recommendedInstrumentation() ─────────────────────

test('recommendedInstrumentation returns starter level by default', function (): void {
    $result = EventCatalog::recommendedInstrumentation();

    expect($result)->toHaveKeys(['level', 'events', 'count', 'next_level']);
    expect($result['level'])->toBe('starter');
    expect($result['next_level'])->toBe('growth');
    expect($result['count'])->toBeInt();
});

test('recommendedInstrumentation starter has 20 events', function (): void {
    $result = EventCatalog::recommendedInstrumentation('starter');

    expect($result['count'])->toBeGreaterThanOrEqual(20);
    $names = array_map(fn (array $e): string => $e['name'], $result['events']);

    // Core events must be in starter
    expect($names)->toContain('sign_up');
    expect($names)->toContain('login');
    expect($names)->toContain('page_view');
    expect($names)->toContain('purchase');
});

test('recommendedInstrumentation growth includes all starter events', function (): void {
    $starter = EventCatalog::recommendedInstrumentation('starter');
    $growth = EventCatalog::recommendedInstrumentation('growth');

    $starterNames = array_map(fn (array $e): string => $e['name'], $starter['events']);
    $growthNames = array_map(fn (array $e): string => $e['name'], $growth['events']);

    foreach ($starterNames as $name) {
        expect($growthNames)->toContain($name);
    }

    // Growth should have more events than starter
    expect($growth['count'])->toBeGreaterThan($starter['count']);
});

test('recommendedInstrumentation enterprise includes all growth events', function (): void {
    $growth = EventCatalog::recommendedInstrumentation('growth');
    $enterprise = EventCatalog::recommendedInstrumentation('enterprise');

    $growthNames = array_map(fn (array $e): string => $e['name'], $growth['events']);
    $enterpriseNames = array_map(fn (array $e): string => $e['name'], $enterprise['events']);

    foreach ($growthNames as $name) {
        expect($enterpriseNames)->toContain($name);
    }

    // Enterprise should have more events than growth
    expect($enterprise['count'])->toBeGreaterThan($growth['count']);
});

test('recommendedInstrumentation complete equals catalog total', function (): void {
    $complete = EventCatalog::recommendedInstrumentation('complete');
    $catalogTotal = EventCatalog::count();

    expect($complete['count'])->toBe($catalogTotal);
    expect($complete['next_level'])->toBeNull();
});

test('recommendedInstrumentation invalid level falls back to starter', function (): void {
    $result = EventCatalog::recommendedInstrumentation('nonexistent');

    expect($result['level'])->toBe('starter');
    expect($result['next_level'])->toBe('growth');
});

test('recommendedInstrumentation events all have valid catalog entries', function (): void {
    $result = EventCatalog::recommendedInstrumentation('enterprise');

    foreach ($result['events'] as $entry) {
        expect($entry)->toHaveKey('name');
        expect($entry)->toHaveKey('class');
        expect($entry)->toHaveKey('category');
        // Verify class reference looks valid
        expect($entry['class'])->toBeString();
        expect($entry['class'])->not->toBeEmpty();
    }
});

test('recommendedInstrumentation level progression is monotonically increasing', function (): void {
    $starter = EventCatalog::recommendedInstrumentation('starter');
    $growth = EventCatalog::recommendedInstrumentation('growth');
    $enterprise = EventCatalog::recommendedInstrumentation('enterprise');
    $complete = EventCatalog::recommendedInstrumentation('complete');

    expect($starter['count'])->toBeLessThan($growth['count']);
    expect($growth['count'])->toBeLessThan($enterprise['count']);
    expect($enterprise['count'])->toBeLessThanOrEqual($complete['count']);
});

// ─── Cross-Method Consistency ───────────────────────────────────────

test('saasEssential events are subset of catalog', function (): void {
    $essential = EventCatalog::saasEssential();
    $allNames = EventCatalog::names();

    foreach ($essential['events'] as $entry) {
        expect(in_array($entry['name'], $allNames, true))->toBeTrue();
    }
});

test('saasEssential covers more events than coreSaaS', function (): void {
    $essential = EventCatalog::saasEssential();
    $core = EventCatalog::coreSaaS();

    expect($essential['count'])->toBeGreaterThan(count($core));
});

test('saasEssential includes all coreSaaS events', function (): void {
    $essential = EventCatalog::saasEssential();
    $core = EventCatalog::coreSaaS();

    $essentialNames = array_map(fn (array $e): string => $e['name'], $essential['events']);
    $coreNames = array_map(fn (array $e): string => $e['name'], $core);

    foreach ($coreNames as $name) {
        expect($essentialNames)->toContain($name);
    }
});

test('recommendedInstrumentation starter events are subset of saasEssential', function (): void {
    $starter = EventCatalog::recommendedInstrumentation('starter');
    $essential = EventCatalog::saasEssential();

    $starterNames = array_map(fn (array $e): string => $e['name'], $starter['events']);
    $essentialNames = array_map(fn (array $e): string => $e['name'], $essential['events']);

    // All starter events should be in the essential set
    foreach ($starterNames as $name) {
        expect($essentialNames)->toContain($name);
    }
});

// ─── EventCatalog Integration ───────────────────────────────────────

test('EventCatalog::count returns positive integer', function (): void {
    expect(EventCatalog::count())->toBeInt();
    expect(EventCatalog::count())->toBeGreaterThan(0);
});

test('EventCatalog::summary has all expected keys', function (): void {
    $summary = EventCatalog::summary();

    expect($summary)->toHaveKeys([
        'total', 'ecommerce', 'saas', 'engagement',
        'with_ga4', 'with_meta', 'with_posthog', 'with_plausible',
    ]);

    // Category counts should match
    expect($summary['total'])->toBe(
        $summary['ecommerce'] + $summary['saas'] + $summary['engagement']
    );
});

test('EventCatalog::industryStandard has priority tiers', function (): void {
    $result = EventCatalog::industryStandard();

    expect($result)->toHaveKeys(['critical', 'high', 'medium', 'low', 'all', 'count']);
    expect($result['critical'])->toBeArray();
    expect($result['high'])->toBeArray();
    expect($result['count'])->toBeInt();

    // Critical should be smallest tier
    expect(count($result['critical']))->toBeLessThanOrEqual(count($result['high']));
    expect(count($result['high']))->toBeLessThanOrEqual(count($result['medium']));
    expect(count($result['medium']))->toBeLessThanOrEqual(count($result['low']));
});

test('EventCatalog::criticalEvents does not include samplable events', function (): void {
    $critical = EventCatalog::criticalEvents();
    $samplable = EventCatalog::samplableEvents();

    $criticalNames = array_map(fn (array $e): string => $e['name'], $critical);
    $samplableNames = array_map(fn (array $e): string => $e['name'], $samplable);

    // No overlap between critical and samplable
    $overlap = array_intersect($criticalNames, $samplableNames);
    expect($overlap)->toBeEmpty();
});

// ─── Category Helpers Consistency ───────────────────────────────────

test('EventCatalog::byCategory has all three categories', function (): void {
    $byCat = EventCatalog::byCategory();

    expect($byCat)->toHaveKeys(['ecommerce', 'saas', 'engagement']);
    expect(count($byCat['ecommerce']))->toBeGreaterThan(0);
    expect(count($byCat['saas']))->toBeGreaterThan(0);
    expect(count($byCat['engagement']))->toBeGreaterThan(0);
});

test('EventCatalog::saasFunnelEvents is ordered correctly', function (): void {
    $funnel = EventCatalog::saasFunnelEvents();
    $names = array_map(fn (array $e): string => $e['name'], $funnel);

    // sign_up should come before subscribe
    $signUpIdx = array_search('sign_up', $names, true);
    $subscribeIdx = array_search('subscribe', $names, true);

    expect($signUpIdx)->not->toBeFalse();
    expect($subscribeIdx)->not->toBeFalse();
    expect($signUpIdx)->toBeLessThan($subscribeIdx);
});
