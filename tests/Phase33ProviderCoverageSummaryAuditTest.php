<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;

/**
 * Phase 33 Production Audit — Provider Coverage Parity & Summary Enhancement
 *
 * Verifies enhancements to EventCatalog for full provider coverage parity:
 * 1. metaNameFor() — symmetrical provider lookup for Meta Pixel
 * 2. allMetaMappings() — complete Meta mapping table
 * 3. summary() — includes infrastructure, tiktok, linkedin counts
 * 4. providerCoverage() — includes tiktok and linkedin event name lists
 * 5. byProvider() — has all 8 providers
 * 6. Version consistency across all package files
 *
 * @since 105.0.0
 */
it('phase33: EventCatalog::metaNameFor returns correct Meta Pixel event names', function () {
    // Core events with Meta Pixel mappings
    expect(EventCatalog::metaNameFor('purchase'))->toBe('Purchase');
    expect(EventCatalog::metaNameFor('view_item'))->toBe('ViewContent');
    expect(EventCatalog::metaNameFor('add_to_cart'))->toBe('AddToCart');
    expect(EventCatalog::metaNameFor('page_view'))->toBe('PageView');
    expect(EventCatalog::metaNameFor('sign_up'))->toBe('CompleteRegistration');
    expect(EventCatalog::metaNameFor('search'))->toBe('Search');

    // Events with null Meta mapping (no Meta Pixel support)
    expect(EventCatalog::metaNameFor('scroll_depth'))->toBeNull();
    expect(EventCatalog::metaNameFor('hover'))->toBeNull();

    // Non-existent event falls back to null
    expect(EventCatalog::metaNameFor('nonexistent_event_xyz'))->toBeNull();
});

it('phase33: EventCatalog::allMetaMappings returns complete mapping table', function () {
    $mappings = EventCatalog::allMetaMappings();

    expect($mappings)->toBeArray();
    expect(count($mappings))->toBeGreaterThanOrEqual(85); // At least total catalog size

    // All purchase-related events have Meta mappings
    expect($mappings['purchase'])->toBe('Purchase');
    expect($mappings['add_to_cart'])->toBe('AddToCart');
    expect($mappings['refund'])->toBe('Refund');
    expect($mappings['begin_checkout'])->toBe('InitiateCheckout');

    // Null entries for events without Meta support
    expect($mappings['scroll_depth'])->toBeNull();
});

it('phase33: EventCatalog::summary includes infrastructure and tiktok/linkedin provider counts', function () {
    $summary = EventCatalog::summary();

    // Category counts
    expect($summary)->toHaveKey('infrastructure');
    expect($summary['infrastructure'])->toBeInt()->toBeGreaterThan(0);

    // Provider coverage counts
    expect($summary)->toHaveKey('with_tiktok');
    expect($summary)->toHaveKey('with_linkedin');
    expect($summary['with_tiktok'])->toBeInt();
    expect($summary['with_linkedin'])->toBeInt();

    // Existing counts still present
    expect($summary)->toHaveKey('total');
    expect($summary)->toHaveKey('ecommerce');
    expect($summary)->toHaveKey('saas');
    expect($summary)->toHaveKey('engagement');
    expect($summary)->toHaveKey('security');
    expect($summary)->toHaveKey('uptime');
    expect($summary)->toHaveKey('with_ga4');
    expect($summary)->toHaveKey('with_meta');
    expect($summary)->toHaveKey('with_posthog');
    expect($summary)->toHaveKey('with_plausible');
    expect($summary)->toHaveKey('with_mixpanel');
    expect($summary)->toHaveKey('with_amplitude');

    // Total matches sum of categories
    expect($summary['total'])->toBe(
        $summary['ecommerce']
        + $summary['saas']
        + $summary['engagement']
        + $summary['security']
        + $summary['uptime']
        + $summary['infrastructure'],
    );
});

it('phase33: EventCatalog::providerCoverage includes tiktok and linkedin event lists', function () {
    $coverage = EventCatalog::providerCoverage();

    // All 8 provider event lists present
    expect($coverage)->toHaveKey('ga4');
    expect($coverage)->toHaveKey('meta');
    expect($coverage)->toHaveKey('posthog');
    expect($coverage)->toHaveKey('plausible');
    expect($coverage)->toHaveKey('mixpanel');
    expect($coverage)->toHaveKey('amplitude');
    expect($coverage)->toHaveKey('tiktok');
    expect($coverage)->toHaveKey('linkedin');

    // TikTok and LinkedIn are arrays
    expect($coverage['tiktok'])->toBeArray();
    expect($coverage['linkedin'])->toBeArray();

    // Counts section includes all 8 providers
    expect($coverage['counts'])->toHaveKey('ga4');
    expect($coverage['counts'])->toHaveKey('meta');
    expect($coverage['counts'])->toHaveKey('posthog');
    expect($coverage['counts'])->toHaveKey('plausible');
    expect($coverage['counts'])->toHaveKey('mixpanel');
    expect($coverage['counts'])->toHaveKey('amplitude');
    expect($coverage['counts'])->toHaveKey('tiktok');
    expect($coverage['counts'])->toHaveKey('linkedin');

    // Count matches event list length
    expect($coverage['counts']['tiktok'])->toBe(count($coverage['tiktok']));
    expect($coverage['counts']['linkedin'])->toBe(count($coverage['linkedin']));
});

it('phase33: EventCatalog::byProvider returns all 8 providers', function () {
    $byProvider = EventCatalog::byProvider();

    expect($byProvider)->toHaveKeys(['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin']);

    // Each provider is a list of event names
    foreach ($byProvider as $provider => $events) {
        expect($events)->toBeArray();
        // Deduplicated (no duplicate names within a provider)
        expect($events)->toEqual(array_values(array_unique($events)));
    }
});

it('phase33: All provider NameFor methods are symmetrical', function () {
    $event = 'purchase';

    // All 8 providers have lookup methods
    expect(EventCatalog::posthogNameFor($event))->toBeString();
    expect(EventCatalog::metaNameFor($event))->toBeString();
    expect(EventCatalog::plausibleNameFor($event))->toBeString();
    expect(EventCatalog::mixpanelNameFor($event))->toBeString();
    expect(EventCatalog::amplitudeNameFor($event))->toBeString();
    expect(EventCatalog::tiktokNameFor($event))->toBeString();
    expect(EventCatalog::linkedinNameFor($event))->toBeString();

    // GA4 lookup via get()
    $entry = EventCatalog::get($event);
    expect($entry)->not->toBeNull();
    expect($entry['ga4'])->toBeString();
});

it('phase33: Version consistency across all package files', function () {
    $version = AnalyticsEvent::VERSION;
    expect($version)->toBe('115.0.0');

    // Composer.json
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe($version);

    // package.json
    $pkg = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);
    expect($pkg['version'])->toBe($version);

    // analytics.js
    $jsContent = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect(str_contains($jsContent, "@version {$version}"))->toBeTrue();

    // README.md
    $readmeContent = file_get_contents(__DIR__ . '/../README.md');
    expect(str_contains($readmeContent, "version-{$version}"))->toBeTrue();
});

it('phase33: All provider mapping tables exist for core providers', function () {
    // posthog (original)
    expect(method_exists(EventCatalog::class, 'allPosthogMappings'))->toBeTrue();
    $posthogMappings = EventCatalog::allPosthogMappings();
    expect(count($posthogMappings))->toBeGreaterThanOrEqual(85);

    // plausible (pre-existing)
    expect(method_exists(EventCatalog::class, 'allPlausibleMappings'))->toBeTrue();
    $plausibleMappings = EventCatalog::allPlausibleMappings();
    expect(count($plausibleMappings))->toBeGreaterThanOrEqual(85);

    // meta (new in v105)
    expect(method_exists(EventCatalog::class, 'allMetaMappings'))->toBeTrue();
    $metaMappings = EventCatalog::allMetaMappings();
    expect(count($metaMappings))->toBeGreaterThanOrEqual(85);

    // All mapping tables have same key set (catalog event names)
    expect(array_keys($posthogMappings))->toEqual(array_keys($plausibleMappings));
    expect(array_keys($posthogMappings))->toEqual(array_keys($metaMappings));
});
