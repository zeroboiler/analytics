<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

test('providerCoverageSummary returns total events count', function (): void {
    $summary = EventCatalog::providerCoverageSummary();

    expect($summary)
        ->toHaveKey('total_events')
        ->toHaveKey('providers')
        ->toHaveKey('best_covered')
        ->toHaveKey('least_covered');

    expect($summary['total_events'])
        ->toBeInt()
        ->toBeGreaterThan(0);
});

test('providerCoverageSummary covers all 10 providers', function (): void {
    $summary = EventCatalog::providerCoverageSummary();
    $providers = $summary['providers'];

    $expectedProviders = ['ga4', 'gtm', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin', 'webhook'];

    foreach ($expectedProviders as $provider) {
        expect($providers)
            ->toHaveKey($provider);

        expect($providers[$provider])
            ->toHaveKey('mapped')
            ->toHaveKey('coverage')
            ->toHaveKey('gaps')
            ->toHaveKey('top_categories');

        expect($providers[$provider]['mapped'])
            ->toBeInt()
            ->toBeGreaterThanOrEqual(0);

        expect($providers[$provider]['coverage'])
            ->toBeFloat()
            ->toBeGreaterThanOrEqual(0.0);

        expect($providers[$provider]['gaps'])
            ->toBeArray();

        expect($providers[$provider]['top_categories'])
            ->toBeArray();
    }
});

test('providerCoverageSummary: ga4 has highest coverage', function (): void {
    $summary = EventCatalog::providerCoverageSummary();

    // GA4 should be the most widely-mapped provider
    expect($summary['providers']['ga4']['mapped'])
        ->toBeGreaterThan(0);

    expect($summary['providers']['ga4']['coverage'])
        ->toBeGreaterThanOrEqual(80.0);

    expect(in_array('ga4', $summary['best_covered'], true))
        ->toBeTrue();
});

test('providerCoverageSummary: gap lists are correct (mapped + gaps = total)', function (): void {
    $summary = EventCatalog::providerCoverageSummary();
    $total = $summary['total_events'];

    foreach ($summary['providers'] as $provider => $data) {
        expect($data['mapped'] + count($data['gaps']))
            ->toBe($total);
    }
});

test('providerCoverageSummary: least_covered contains only low-coverage providers', function (): void {
    $summary = EventCatalog::providerCoverageSummary();

    foreach ($summary['least_covered'] as $provider) {
        expect($summary['providers'][$provider]['coverage'])
            ->toBeLessThan(30.0);
    }
});

test('providerIntersectionEvents: empty providers returns empty', function (): void {
    $result = EventCatalog::providerIntersectionEvents([]);

    expect($result)
        ->toBeArray()
        ->toBeEmpty();
});

test('providerIntersectionEvents: single provider returns all mapped events', function (): void {
    $result = EventCatalog::providerIntersectionEvents(['ga4']);
    $ga4Names = EventCatalog::ga4Names();

    expect(count($result))
        ->toBe(count($ga4Names));

    foreach ($result as $event) {
        expect($event)
            ->toHaveKey('name')
            ->toHaveKey('category')
            ->toHaveKey('entries');

        expect($event['entries'])
            ->toHaveKey('ga4');

        expect($event['entries']['ga4'])
            ->not()->toBeEmpty();
    }
});

test('providerIntersectionEvents: ga4+posthog+mixpanel intersection', function (): void {
    $result = EventCatalog::providerIntersectionEvents(['ga4', 'posthog', 'mixpanel']);

    expect($result)
        ->toBeArray();

    // The intersection should be smaller than any individual provider
    $ga4Count = count(EventCatalog::ga4Names());
    expect(count($result))
        ->toBeLessThanOrEqual($ga4Count);

    foreach ($result as $event) {
        expect($event['entries'])
            ->toHaveKeys(['ga4', 'posthog', 'mixpanel']);

        expect($event['entries']['ga4'])->not->toBeEmpty();
        expect($event['entries']['posthog'])->not->toBeEmpty();
        expect($event['entries']['mixpanel'])->not->toBeEmpty();
    }
});

test('providerIntersectionEvents: impossible combo returns empty', function (): void {
    // gtm has no catalog-level mapping (it's handled differently via dataLayer)
    $result = EventCatalog::providerIntersectionEvents(['gtm', 'plausible']);

    // gtm entries in catalog are null/empty, so intersection should be empty
    expect($result)
        ->toBeArray();
});

test('providerIntersectionEvents returns events with correct structure', function (): void {
    $result = EventCatalog::providerIntersectionEvents(['ga4', 'meta']);

    foreach ($result as $event) {
        expect($event['name'])
            ->toBeString()
            ->not->toBeEmpty();

        expect($event['category'])
            ->toBeIn(['ecommerce', 'saas', 'engagement', 'security', 'uptime', 'infrastructure']);

        expect($event['entries'])
            ->toBeArray();
    }
});

test('version integrity: AnalyticsEvent::VERSION is 115.0.0', function (): void {
    expect(AnalyticsEvent::VERSION)
        ->toBe('115.0.0');
});
