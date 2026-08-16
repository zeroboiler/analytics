<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\Marketing\MarketingEvents;
use ZeroBoiler\Analytics\Events\Infrastructure\InfrastructureEvents;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;

test('eventCatalogSummary returns all seven categories', function (): void {
    $ecommerce = EcommerceEvents::count();
    $saas = SaaSEvents::count();
    $engagement = EngagementEvents::count();
    $marketing = MarketingEvents::count();
    $infrastructure = InfrastructureEvents::count();
    $security = SecurityEvents::count();
    $uptime = UptimeEvents::count();

    // Ensure all 7 categories have non-zero counts
    expect($ecommerce)->toBeGreaterThan(0);
    expect($saas)->toBeGreaterThan(0);
    expect($engagement)->toBeGreaterThan(0);
    expect($marketing)->toBeGreaterThan(0);
    expect($infrastructure)->toBeGreaterThan(0);
    expect($security)->toBeGreaterThan(0);
    expect($uptime)->toBeGreaterThan(0);

    // Total from EventCatalog::count() equals sum of all categories
    $expectedTotal = $ecommerce + $saas + $engagement + $marketing + $infrastructure + $security + $uptime;
    expect(EventCatalog::count())->toBe($expectedTotal);
});

test('individual category catalogs have consistent counts with unified catalog', function (): void {
    $ecommerceCount = EcommerceEvents::count();
    $saasCount = SaaSEvents::count();
    $engagementCount = EngagementEvents::count();
    $marketingCount = MarketingEvents::count();
    $infrastructureCount = InfrastructureEvents::count();
    $securityCount = SecurityEvents::count();
    $uptimeCount = UptimeEvents::count();

    // All categories should have at least 5 events
    expect($ecommerceCount)->toBeGreaterThanOrEqual(5);
    expect($saasCount)->toBeGreaterThanOrEqual(10);
    expect($engagementCount)->toBeGreaterThanOrEqual(15);
    expect($marketingCount)->toBeGreaterThanOrEqual(10);
    expect($infrastructureCount)->toBeGreaterThanOrEqual(5);
    expect($securityCount)->toBeGreaterThanOrEqual(3);
    expect($uptimeCount)->toBeGreaterThanOrEqual(3);

    // Total from EventCatalog::all() should equal sum of individual catalogs
    $catalogAll = EventCatalog::all();
    $totalFromAll = count($catalogAll);
    $sumOfCategories = $ecommerceCount + $saasCount + $engagementCount
        + $marketingCount + $infrastructureCount + $securityCount + $uptimeCount;

    expect($totalFromAll)->toBe($sumOfCategories);
    expect(EventCatalog::count())->toBe($sumOfCategories);
});

test('EventCatalog::all includes marketing, infrastructure, security, and uptime', function (): void {
    $all = EventCatalog::all();

    // Verify each category contributes events
    $ecommerceItem = EcommerceEvents::names()[0] ?? null;
    $saasItem = SaaSEvents::names()[0] ?? null;
    $engagementItem = EngagementEvents::names()[0] ?? null;
    $marketingItem = MarketingEvents::names()[0] ?? null;
    $infraItem = InfrastructureEvents::names()[0] ?? null;
    $securityItem = SecurityEvents::names()[0] ?? null;
    $uptimeItem = UptimeEvents::names()[0] ?? null;

    expect($ecommerceItem)->not->toBeNull();
    expect($saasItem)->not->toBeNull();
    expect($engagementItem)->not->toBeNull();
    expect($marketingItem)->not->toBeNull();
    expect($infraItem)->not->toBeNull();
    expect($securityItem)->not->toBeNull();
    expect($uptimeItem)->not->toBeNull();

    // All should exist in unified catalog with correct category
    expect($all[$ecommerceItem]['category'])->toBe('ecommerce');
    expect($all[$saasItem]['category'])->toBe('saas');
    expect($all[$engagementItem]['category'])->toBe('engagement');
    expect($all[$marketingItem]['category'])->toBe('marketing');
    expect($all[$infraItem]['category'])->toBe('infrastructure');
    expect($all[$securityItem]['category'])->toBe('security');
    expect($all[$uptimeItem]['category'])->toBe('uptime');
});

test('EventCatalog::byCategory includes all seven categories', function (): void {
    $byCategory = EventCatalog::byCategory();

    expect($byCategory)->toHaveKeys([
        'ecommerce',
        'saas',
        'engagement',
        'security',
        'uptime',
        'infrastructure',
        'marketing',
    ]);

    // Each category should be non-empty
    foreach (array_keys($byCategory) as $category) {
        expect(count($byCategory[$category]))->toBeGreaterThan(0);
    }
});

test('EventCatalog::category method resolves all seven categories', function (): void {
    // Valid categories should return non-empty arrays
    $categories = ['ecommerce', 'saas', 'engagement', 'security', 'uptime', 'infrastructure', 'marketing'];
    foreach ($categories as $category) {
        $events = EventCatalog::category($category);
        expect($events)->toBeArray();
        expect(count($events))->toBeGreaterThan(0);
    }

    // Invalid category returns empty
    expect(EventCatalog::category('nonexistent'))->toBe([]);
});
