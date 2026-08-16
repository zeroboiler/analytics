<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\SaaS\FeatureAdoptedEvent;
use ZeroBoiler\Analytics\Events\SaaS\ExpansionRevenueEvent;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventPriorityCalculator;

// ─── v2.78.0 — PLG Events + Version Consistency + Catalog Expansion ───

test('FeatureAdoptedEvent creates correct event name and params', function (): void {
    $event = new FeatureAdoptedEvent('export', 'core');

    expect($event->name)->toBe('feature_adopted');
    expect($event->params)->toHaveKey('feature_name');
    expect($event->params['feature_name'])->toBe('export');
    expect($event->params['feature_category'])->toBe('core');
});

test('FeatureAdoptedEvent accepts extra params', function (): void {
    $event = new FeatureAdoptedEvent('api_access', 'premium', ['team_size' => 5]);

    expect($event->name)->toBe('feature_adopted');
    expect($event->params['feature_name'])->toBe('api_access');
    expect($event->params['feature_category'])->toBe('premium');
    expect($event->params['team_size'])->toBe(5);
});

test('FeatureAdoptedEvent works with null category', function (): void {
    $event = new FeatureAdoptedEvent('team_collaboration');

    expect($event->name)->toBe('feature_adopted');
    expect($event->params['feature_name'])->toBe('team_collaboration');
    expect($event->params['feature_category'])->toBeNull();
});

test('ExpansionRevenueEvent creates correct event name and params', function (): void {
    $event = new ExpansionRevenueEvent(49.99, 'addon');

    expect($event->name)->toBe('expansion_revenue');
    expect($event->params['amount'])->toBe(49.99);
    expect($event->params['expansion_source'])->toBe('addon');
    expect($event->params['currency'])->toBe('USD');
});

test('ExpansionRevenueEvent accepts custom currency', function (): void {
    $event = new ExpansionRevenueEvent(150.00, 'seat_expansion', 'EUR');

    expect($event->name)->toBe('expansion_revenue');
    expect($event->params['amount'])->toBe(150.0);
    expect($event->params['currency'])->toBe('EUR');
    expect($event->params['expansion_source'])->toBe('seat_expansion');
});

test('ExpansionRevenueEvent accepts extra params', function (): void {
    $event = new ExpansionRevenueEvent(29.99, 'cross_sell', 'USD', ['product' => 'analytics_addon']);

    expect($event->params['product'])->toBe('analytics_addon');
});

test('SaaSEvents catalog has feature_adopted and expansion_revenue', function (): void {
    expect(SaaSEvents::has('feature_adopted'))->toBeTrue();
    expect(SaaSEvents::has('expansion_revenue'))->toBeTrue();
});

test('SaaSEvents total count is 48 (was 46 + 2 PLG events)', function (): void {
    expect(SaaSEvents::count())->toBe(50);
});

test('EventCatalog total count is 86 (13 ecommerce + 48 SaaS + 25 engagement)', function (): void {
    expect(EventCatalog::count())->toBe(90);
});

test('SaaSEvents entries have correct class references', function (): void {
    $adopted = SaaSEvents::get('feature_adopted');
    expect($adopted)->not->toBeNull();
    expect($adopted['class'])->toBe(FeatureAdoptedEvent::class);
    expect($adopted['ga4'])->toBe('feature_adopted');
    expect($adopted['meta'])->toBe('FeatureAdopted');
    expect($adopted['posthog'])->toBe('feature_adopted');
    expect($adopted['plausible'])->toBeNull();

    $expansion = SaaSEvents::get('expansion_revenue');
    expect($expansion)->not->toBeNull();
    expect($expansion['class'])->toBe(ExpansionRevenueEvent::class);
    expect($expansion['ga4'])->toBe('expansion_revenue');
    expect($expansion['meta'])->toBe('Purchase');
    expect($expansion['posthog'])->toBe('expansion_revenue');
});

test('EventCatalog::plgEvents returns PLG-specific events', function (): void {
    $plg = EventCatalog::plgEvents();

    expect($plg)->not->toBeEmpty();
    expect(count($plg))->toBeGreaterThanOrEqual(13);

    $names = array_column($plg, 'name');
    expect($names)->toContain('feature_adopted');
    expect($names)->toContain('expansion_revenue');
    expect($names)->toContain('sign_up');
    expect($names)->toContain('trial_converted');
    expect($names)->toContain('plan_upgrade');
    expect($names)->toContain('share');
});

test('EventCatalog::productGrowthEvents includes new PLG events', function (): void {
    $growth = EventCatalog::productGrowthEvents();
    $names = array_column($growth, 'name');

    expect($names)->toContain('feature_adopted');
    expect($names)->toContain('expansion_revenue');
});

test('EventCatalog::billingEvents includes expansion_revenue', function (): void {
    $billing = EventCatalog::billingEvents();
    $names = array_column($billing, 'name');

    expect($names)->toContain('expansion_revenue');
});

test('EventPriorityCalculator classifies new events as referral', function (): void {
    $calculator = new EventPriorityCalculator;

    expect($calculator->classify('feature_adopted'))->toBe('referral');
    expect($calculator->classify('expansion_revenue'))->toBe('referral');
});

test('EventPriorityCalculator getEventPriority returns medium for new events', function (): void {
    $calculator = new EventPriorityCalculator;

    // Referral category = medium priority
    expect($calculator->getEventPriority('feature_adopted'))->toBe('medium');
    expect($calculator->getEventPriority('expansion_revenue'))->toBe('medium');
});

test('EventCatalog::industryStandard includes new events in medium tier', function (): void {
    $standard = EventCatalog::industryStandard();

    $mediumNames = array_column($standard['medium'], 'name');
    expect($mediumNames)->toContain('feature_adopted');
    expect($mediumNames)->toContain('expansion_revenue');
});

test('EventCatalog validate passes with new events', function (): void {
    $result = EventCatalog::validate();

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
});

test('AnalyticsManager featureAdopted dispatches correct event', function (): void {
    $manager = app(AnalyticsManager::class);
    $manager->setDebug(true);

    // Should not throw
    $manager->featureAdopted('export', 'core');
    $manager->featureAdopted('api_access', null, ['env' => 'production']);
});

test('AnalyticsManager expansionRevenue dispatches correct event', function (): void {
    $manager = app(AnalyticsManager::class);
    $manager->setDebug(true);

    // Should not throw
    $manager->expansionRevenue(99.99, 'seat_expansion');
    $manager->expansionRevenue(150.00, 'addon', 'EUR');
});

test('AnalyticsManager plgEvents returns catalog PLG events', function (): void {
    $manager = app(AnalyticsManager::class);
    $plg = $manager->plgEvents();

    expect($plg)->not->toBeEmpty();
    $names = array_column($plg, 'name');
    expect($names)->toContain('feature_adopted');
    expect($names)->toContain('expansion_revenue');
});

test('Version consistency across all files', function (): void {
    // AnalyticsEvent VERSION constant
    expect(AnalyticsEvent::VERSION)->toBe('76.0.0');

    // Event count consistency
    expect(EventCatalog::count())->toBe(90);
    expect(SaaSEvents::count())->toBe(50);

    // SaaSEvents count = EcommerceEvents + EngagementEvents + 50
    expect(EventCatalog::count())->toBe(
        13 + 50 + 27,
    );
});

test('Maturity score accounts for new events (90 total)', function (): void {
    $calculator = new EventPriorityCalculator;
    $counts = $calculator->categoryCounts();

    expect($counts['total'])->toBe(90);
    expect($counts['referral'])->toBeGreaterThanOrEqual(7); // was 5 + 2 new
    expect($counts['acquisition'])->toBeGreaterThanOrEqual(5);
    expect($counts['activation'])->toBeGreaterThanOrEqual(12);
});

test('FeatureAdoptedEvent and ExpansionRevenueEvent extend AnalyticsEvent', function (): void {
    $adopted = new FeatureAdoptedEvent('test', 'cat');
    $expansion = new ExpansionRevenueEvent(10.0, 'addon');

    expect($adopted)->toBeInstanceOf(AnalyticsEvent::class);
    expect($expansion)->toBeInstanceOf(AnalyticsEvent::class);
});

test('Facade has featureAdopted and expansionRevenue proxy methods', function (): void {
    $manager = app(AnalyticsManager::class);
    $manager->setDebug(true);

    // Test via facade proxy
    \ZeroBoiler\Analytics\Facades\Analytics::featureAdopted('dashboard', 'premium');
    \ZeroBoiler\Analytics\Facades\Analytics::expansionRevenue(25.0, 'cross_sell', 'GBP');
});
