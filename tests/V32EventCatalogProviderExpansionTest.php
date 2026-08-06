<?php

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;

describe('v3.2 — EventCatalog PostHog & Plausible provider support', function () {
    it('byProvider includes posthog and plausible keys', function () {
        $providers = EventCatalog::byProvider();

        expect($providers)->toHaveKeys(['ga4', 'meta', 'posthog', 'plausible']);
    });

    it('byProvider returns non-empty posthog names', function () {
        $providers = EventCatalog::byProvider();

        expect($providers['posthog'])->not->toBeEmpty();
        expect(count($providers['posthog']))->toBeGreaterThan(0);
    });

    it('byProvider returns non-empty plausible names', function () {
        $providers = EventCatalog::byProvider();

        expect($providers['plausible'])->not->toBeEmpty();
    });

    it('byProvider plausible filters out unsupported events', function () {
        $providers = EventCatalog::byProvider();

        // scroll_depth, click, session_start/end, form_start/submit should not be in plausible
        expect($providers['plausible'])->not->toContain('scroll_depth');
        expect($providers['plausible'])->not->toContain('session_start');
        expect($providers['plausible'])->not->toContain('session_end');
    });

    it('byProvider plausible maps page_view to pageview', function () {
        $providers = EventCatalog::byProvider();

        expect($providers['plausible'])->toContain('pageview');
    });

    it('byProvider posthog maps sign_up to $signup', function () {
        $providers = EventCatalog::byProvider();

        expect($providers['posthog'])->toContain('$signup');
    });

    it('byProvider posthog maps login to $identify', function () {
        $providers = EventCatalog::byProvider();

        expect($providers['posthog'])->toContain('$identify');
    });

    it('allPosthogNames returns mapped names', function () {
        $names = EventCatalog::allPosthogNames();

        expect($names)->toBeArray();
        expect($names)->toContain('$signup');
        expect($names)->toContain('$identify');
        expect($names)->toContain('logout');
    });

    it('allPlausibleNames returns filtered names', function () {
        $names = EventCatalog::allPlausibleNames();

        expect($names)->toBeArray();
        expect($names)->toContain('pageview');
        expect($names)->not->toContain('scroll_depth');
        expect($names)->not->toContain('session_start');
    });

    it('allPosthogNames count matches expected', function () {
        $names = EventCatalog::allPosthogNames();

        // Should have at least as many as the total events
        expect(count($names))->toBeGreaterThanOrEqual(EventCatalog::count());
    });

    it('allPlausibleNames is smaller than total events', function () {
        $plausibleCount = count(EventCatalog::allPlausibleNames());
        $totalCount = EventCatalog::count();

        // Plausible filters out many event types
        expect($plausibleCount)->toBeLessThan($totalCount);
    });

    it('ga4 and meta still present in byProvider', function () {
        $providers = EventCatalog::byProvider();

        expect($providers['ga4'])->not->toBeEmpty();
        expect($providers['meta'])->not->toBeEmpty();
    });
});
