<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EventFingerprintService;
use ZeroBoiler\Analytics\AnalyticsManager;

// ─── v2.84.0 — Event Fingerprint Service, Inertia Props, Version Unification ───

// ─── Version Consistency ─────────────────────────────────────────────

test('AnalyticsEvent VERSION is 2.84.0', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('2.91.0');
});

test('AnalyticsEvent VERSION is a valid semver string', function (): void {
    $version = AnalyticsEvent::VERSION;
    expect($version)->toMatch('/^\d+\.\d+\.\d+$/');
    expect($version)->not->toBeEmpty();
});

// ─── EventFingerprintService ────────────────────────────────────────

test('EventFingerprintService can be instantiated without cache', function (): void {
    $service = new EventFingerprintService(null);

    expect($service)->toBeInstanceOf(EventFingerprintService::class);
    expect($service->isEnabled())->toBeTrue();
    expect($service->getWindowSeconds())->toBe(10);
    expect($service->getMaxFingerprints())->toBe(10000);
});

test('EventFingerprintService returns summary with all keys', function (): void {
    $service = new EventFingerprintService(null);
    $summary = $service->summary();

    expect($summary)->toHaveKeys(['enabled', 'window_seconds', 'max_fingerprints', 'cache_prefix']);
    expect($summary['enabled'])->toBeTrue();
    expect($summary['window_seconds'])->toBeInt();
    expect($summary['max_fingerprints'])->toBeInt();
    expect($summary['cache_prefix'])->toBeString();
});

test('EventFingerprintService generates deterministic fingerprints', function (): void {
    $service = new EventFingerprintService(null);

    $event1 = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99, 'currency' => 'USD']);
    $event2 = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99, 'currency' => 'USD']);

    $fp1 = $service->fingerprint($event1);
    $fp2 = $service->fingerprint($event2);

    expect($fp1)->toBe($fp2);
    expect($fp1)->toBeString();
    expect(strlen($fp1))->toBeGreaterThan(0);
});

test('EventFingerprintService different events produce different fingerprints', function (): void {
    $service = new EventFingerprintService(null);

    $purchase = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99]);
    $signup = new AnalyticsEvent(name: 'sign_up', params: ['method' => 'email']);

    expect($service->fingerprint($purchase))->not->toBe($service->fingerprint($signup));
});

test('EventFingerprintService param order does not affect fingerprint', function (): void {
    $service = new EventFingerprintService(null);

    $event1 = new AnalyticsEvent(name: 'click', params: ['button' => 'buy', 'page' => '/home']);
    $event2 = new AnalyticsEvent(name: 'click', params: ['page' => '/home', 'button' => 'buy']);

    expect($service->fingerprint($event1))->toBe($service->fingerprint($event2));
});

test('EventFingerprintService null values are excluded from fingerprint', function (): void {
    $service = new EventFingerprintService(null);

    $event1 = new AnalyticsEvent(name: 'purchase', params: ['value' => 50.0, 'coupon' => null]);
    $event2 = new AnalyticsEvent(name: 'purchase', params: ['value' => 50.0]);

    expect($service->fingerprint($event1))->toBe($service->fingerprint($event2));
});

test('EventFingerprintService handles client ID and user ID in fingerprint', function (): void {
    $service = new EventFingerprintService(null);

    $eventA = new AnalyticsEvent(name: 'page_view', clientId: 'client-123', userId: 'user-456');
    $eventB = new AnalyticsEvent(name: 'page_view', clientId: 'client-123', userId: 'user-456');
    $eventC = new AnalyticsEvent(name: 'page_view', clientId: 'client-999', userId: 'user-456');

    expect($service->fingerprint($eventA))->toBe($service->fingerprint($eventB));
    expect($service->fingerprint($eventA))->not->toBe($service->fingerprint($eventC));
});

test('EventFingerprintService float normalization', function (): void {
    $service = new EventFingerprintService(null);

    $event1 = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.123456789]);
    $event2 = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.123457]);

    expect($service->fingerprint($event1))->toBe($service->fingerprint($event2));
});

test('EventFingerprintService isDuplicate returns false without cache', function (): void {
    $service = new EventFingerprintService(null);
    $event = new AnalyticsEvent(name: 'page_view', params: []);

    expect($service->isDuplicate($event))->toBeFalse();
});

test('EventFingerprintService handles boolean params in fingerprint', function (): void {
    $service = new EventFingerprintService(null);

    $event1 = new AnalyticsEvent(name: 'form_submit', params: ['newsletter_opt_in' => true]);
    $event2 = new AnalyticsEvent(name: 'form_submit', params: ['newsletter_opt_in' => true]);
    $event3 = new AnalyticsEvent(name: 'form_submit', params: ['newsletter_opt_in' => false]);

    expect($service->fingerprint($event1))->toBe($service->fingerprint($event2));
    expect($service->fingerprint($event1))->not->toBe($service->fingerprint($event3));
});

test('EventFingerprintService handles nested params', function (): void {
    $service = new EventFingerprintService(null);

    $event1 = new AnalyticsEvent(name: 'purchase', params: [
        'items' => [['id' => 'SKU-1', 'qty' => 2], ['id' => 'SKU-2', 'qty' => 1]],
        'total' => 150.0,
    ]);
    $event2 = new AnalyticsEvent(name: 'purchase', params: [
        'items' => [['id' => 'SKU-1', 'qty' => 2], ['id' => 'SKU-2', 'qty' => 1]],
        'total' => 150.0,
    ]);

    expect($service->fingerprint($event1))->toBe($service->fingerprint($event2));
});

// ─── EventCatalog Integration ────────────────────────────────────────

test('EventCatalog::count returns correct total', function (): void {
    $total = EventCatalog::count();

    expect($total)->toBeInt();
    expect($total)->toBeGreaterThanOrEqual(90);
});

test('EventCatalog::validate returns valid result', function (): void {
    $result = EventCatalog::validate();

    expect($result)->toHaveKeys(['valid', 'errors', 'warnings']);
    expect($result['valid'])->toBeBool();
    expect($result['errors'])->toBeArray();
    expect($result['warnings'])->toBeArray();
});

test('EventCatalog::recommendedInstrumentation accepts all valid levels', function (): void {
    foreach (['starter', 'growth', 'enterprise', 'complete'] as $level) {
        $result = EventCatalog::recommendedInstrumentation($level);

        expect($result)->toHaveKeys(['level', 'events', 'count', 'next_level']);
        expect($result['level'])->toBe($level);
        expect($result['events'])->toBeArray();
        expect($result['count'])->toBeInt();
    }
});

test('EventCatalog::recommendedInstrumentation handles invalid level gracefully', function (): void {
    $result = EventCatalog::recommendedInstrumentation('invalid');

    expect($result['level'])->toBe('starter');
});

test('EventCatalog::industryStandard returns all priority tiers', function (): void {
    $result = EventCatalog::industryStandard();

    expect($result)->toHaveKeys(['critical', 'high', 'medium', 'low', 'all', 'count']);
    expect($result['critical'])->toBeArray();
    expect($result['high'])->toBeArray();
    expect($result['medium'])->toBeArray();
    expect($result['low'])->toBeArray();
    expect($result['count'])->toBeGreaterThan(0);
    expect($result['count'])->toBe(count($result['all']));
});

test('EventCatalog::eventPriority returns valid priority levels', function (): void {
    $priorities = ['critical', 'high', 'medium', 'low'];
    $testEvents = ['purchase', 'sign_up', 'page_view', 'scroll_depth'];

    foreach ($testEvents as $event) {
        $priority = EventCatalog::eventPriority($event);
        expect($priority)->toBeIn($priorities);
    }
});

test('EventCatalog::eventCategory returns valid AARRR categories', function (): void {
    $categories = ['acquisition', 'activation', 'retention', 'revenue', 'referral', 'operational'];
    $testEvents = ['sign_up', 'feature_used', 'login', 'purchase', 'share', 'payment_failed'];

    foreach ($testEvents as $event) {
        $category = EventCatalog::eventCategory($event);
        expect($category)->toBeIn($categories);
    }
});

// ─── AnalyticsManager Version ────────────────────────────────────────

test('AnalyticsManager version method returns string', function (): void {
    // We test the version method exists by verifying it returns a string
    // that matches our expected version
    $manager = new AnalyticsManager(null);

    expect($manager->version())->toBe('2.91.0');
});

// ─── Inertia Props Validation ──────────────────────────────────────

test('EventFingerprintService summary has consistent types', function (): void {
    $service = new EventFingerprintService(null);
    $summary = $service->summary();

    expect(is_bool($summary['enabled']))->toBeTrue();
    expect(is_int($summary['window_seconds']))->toBeTrue();
    expect(is_int($summary['max_fingerprints']))->toBeTrue();
    expect(is_string($summary['cache_prefix']))->toBeTrue();
});

test('EventFingerprintService fingerprint is 32-char hex-like', function (): void {
    $service = new EventFingerprintService(null);
    $event = new AnalyticsEvent(name: 'test_event', params: ['key' => 'value']);

    $fp = $service->fingerprint($event);

    // XXH128 produces 32 hex characters
    expect(strlen($fp))->toBe(32);
    expect($fp)->toMatch('/^[a-f0-9]{32}$/');
});

// ─── Provider Coverage ──────────────────────────────────────────────

test('EventCatalog::providerCoverage returns all providers', function (): void {
    $coverage = EventCatalog::providerCoverage();

    expect($coverage)->toHaveKeys(['ga4', 'meta', 'posthog', 'plausible', 'counts']);
    expect($coverage['counts'])->toHaveKeys(['ga4', 'meta', 'posthog', 'plausible']);
    expect($coverage['counts']['ga4'])->toBeGreaterThan(0);
    expect($coverage['counts']['posthog'])->toBeGreaterThan(0);
});

test('EventCatalog::saasEssential includes at least 35 events', function (): void {
    $result = EventCatalog::saasEssential();

    expect($result['count'])->toBeGreaterThanOrEqual(35);
});

// ─── Funnel Readiness Validation ────────────────────────────────────

test('EventCatalog::saasFunnelEvents returns ordered funnel', function (): void {
    $funnel = EventCatalog::saasFunnelEvents();

    expect($funnel)->toBeArray();
    expect(count($funnel))->toBeGreaterThanOrEqual(10);

    // Must start with sign_up and include subscribe, plan_upgrade
    $names = array_map(fn (array $e): string => $e['name'], $funnel);
    expect($names[0])->toBe('sign_up');
    expect($names)->toContain('subscribe');
    expect($names)->toContain('plan_upgrade');
    expect($names)->toContain('cancellation');
});

test('EventCatalog::checkoutFunnel returns ordered checkout steps', function (): void {
    $funnel = EventCatalog::checkoutFunnel();

    expect($funnel)->toBeArray();
    $names = array_map(fn (array $e): string => $e['name'], $funnel);

    // view_item should come before purchase
    $viewItemIdx = array_search('view_item', $names, true);
    $purchaseIdx = array_search('purchase', $names, true);

    expect($viewItemIdx)->toBeLessThan($purchaseIdx);
});

// ─── Critical Events Validation ──────────────────────────────────────

test('EventCatalog::criticalEvents does not include samplable events', function (): void {
    $critical = EventCatalog::criticalEvents();
    $samplable = EventCatalog::samplableEvents();

    $criticalNames = array_map(fn (array $e): string => $e['name'], $critical);
    $samplableNames = array_map(fn (array $e): string => $e['name'], $samplable);

    $overlap = array_intersect($criticalNames, $samplableNames);

    expect($overlap)->toBeEmpty();
});

// ─── PLG Events ────────────────────────────────────────────────────

test('EventCatalog::plgEvents includes feature_adopted and expansion_revenue', function (): void {
    $plg = EventCatalog::plgEvents();
    $names = array_map(fn (array $e): string => $e['name'], $plg);

    expect($names)->toContain('feature_adopted');
    expect($names)->toContain('expansion_revenue');
    expect($names)->toContain('sign_up');
    expect($names)->toContain('plan_upgrade');
});
