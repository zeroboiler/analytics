<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\EventCatalog;

// ─── Provider Coverage Parity (v100.2.0) ──────────────────────────────────

test('providerCoverageParity returns correct structure', function (): void {
    $result = EventCatalog::providerCoverageParity();

    expect($result)->toHaveKey('total');
    expect($result)->toHaveKey('providers');
    expect($result['total'])->toBeInt()->toBeGreaterThan(0);
    expect($result['providers'])->toBeArray();

    $providers = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];

    foreach ($providers as $provider) {
        expect($result['providers'])->toHaveKey($provider);
        $p = $result['providers'][$provider];
        expect($p)->toHaveKey('mapped');
        expect($p)->toHaveKey('coverage');
        expect($p)->toHaveKey('gaps');
        expect($p['mapped'])->toBeInt();
        expect($p['coverage'])->toBeFloat();
        expect($p['gaps'])->toBeArray();
    }
});

test('providerCoverageParity coverage values are 0-100 range', function (): void {
    $result = EventCatalog::providerCoverageParity();

    foreach ($result['providers'] as $name => $provider) {
        expect($provider['coverage'])->toBeGreaterThanOrEqual(0.0);
        expect($provider['coverage'])->toBeLessThanOrEqual(100.0);
        expect($provider['mapped'] + count($provider['gaps']))->toBe($result['total']);
    }
});

test('providerCoverageParity ga4 has 100% coverage', function (): void {
    $result = EventCatalog::providerCoverageParity();

    // GA4 is the primary provider — every event should have a ga4 mapping
    expect($result['providers']['ga4']['coverage'])->toBe(100.0);
    expect($result['providers']['ga4']['gaps'])->toBeEmpty();
});

// ─── Event Provider Mapping ──────────────────────────────────────────────

test('eventProviderMapping returns correct structure for known event', function (): void {
    $result = EventCatalog::eventProviderMapping('purchase');

    expect($result)->toHaveKey('event');
    expect($result)->toHaveKey('providers');
    expect($result)->toHaveKey('mapped_count');
    expect($result)->toHaveKey('total_providers');
    expect($result['event'])->toBe('purchase');
    expect($result['total_providers'])->toBe(8);
    expect($result['mapped_count'])->toBeGreaterThan(0);
    expect($result['providers'])->toHaveKey('ga4');
    expect($result['providers']['ga4'])->toBe('purchase');
});

test('eventProviderMapping returns empty for unknown event', function (): void {
    $result = EventCatalog::eventProviderMapping('nonexistent_event_xyz');

    expect($result['event'])->toBe('nonexistent_event_xyz');
    expect($result['providers'])->toBeEmpty();
    expect($result['mapped_count'])->toBe(0);
    expect($result['total_providers'])->toBe(8);
});

test('eventProviderMapping null entry is handled safely', function (): void {
    $result = EventCatalog::eventProviderMapping('zzz_nonexistent');

    expect($result)->toBeArray();
    expect($result['mapped_count'])->toBe(0);
});

// ─── Fully Mapped Events ─────────────────────────────────────────────────

test('fullyMappedEvents returns array of events with all provider mappings', function (): void {
    $result = EventCatalog::fullyMappedEvents();

    expect($result)->toBeArray();
    $providers = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];

    foreach ($result as $event) {
        foreach ($providers as $provider) {
            $value = $event[$provider] ?? null;
            expect($value)->not->toBeNull();
            expect($value)->not->toBe('');
        }
    }
});

test('fullyMappedEvents count does not exceed total events', function (): void {
    $result = EventCatalog::fullyMappedEvents();
    $total = count(EventCatalog::all());

    expect(count($result))->toBeLessThanOrEqual($total);
});

// ─── Least Mapped Events ─────────────────────────────────────────────────

test('leastMappedEvents returns correct structure', function (): void {
    $result = EventCatalog::leastMappedEvents(5);

    expect($result)->toBeArray();
    expect(count($result))->toBeLessThanOrEqual(5);

    foreach ($result as $item) {
        expect($item)->toHaveKey('event');
        expect($item)->toHaveKey('category');
        expect($item)->toHaveKey('mapped_count');
        expect($item)->toHaveKey('gaps');
        expect($item['mapped_count'])->toBeInt()->toBeGreaterThanOrEqual(0);
        expect($item['gaps'])->toBeArray();
    }
});

test('leastMappedEvents is sorted ascending by mapped_count', function (): void {
    $result = EventCatalog::leastMappedEvents(20);

    for ($i = 1; $i < count($result); $i++) {
        expect($result[$i]['mapped_count'])->toBeGreaterThanOrEqual($result[$i - 1]['mapped_count']);
    }
});

test('leastMappedEvents default limit is 10', function (): void {
    $result = EventCatalog::leastMappedEvents();

    expect(count($result))->toBeLessThanOrEqual(10);
});

// ─── Event Priority Score ────────────────────────────────────────────────

test('eventPriorityScore returns 0 for unknown event', function (): void {
    expect(EventCatalog::eventPriorityScore('nonexistent_xyz'))->toBe(0);
});

test('eventPriorityScore returns value in 0-100 range', function (): void {
    $names = EventCatalog::names();

    foreach (array_slice($names, 0, 10) as $name) {
        $score = EventCatalog::eventPriorityScore($name);
        expect($score)->toBeInt()->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100);
    }
});

test('eventPriorityScore ecommerce events have base weight of 30', function (): void {
    $ecommerce = EventCatalog::category('ecommerce');
    $firstEvent = array_key_first($ecommerce);
    $score = EventCatalog::eventPriorityScore($firstEvent);

    // ecommerce base = 30, plus provider bonus + tag bonus
    expect($score)->toBeGreaterThanOrEqual(30);
});

test('eventPriorityScore saas events have base weight of 25', function (): void {
    $saas = EventCatalog::category('saas');
    $firstEvent = array_key_first($saas);
    $score = EventCatalog::eventPriorityScore($firstEvent);

    expect($score)->toBeGreaterThanOrEqual(25);
});

// ─── Top Priority Events ──────────────────────────────────────────────────

test('topPriorityEvents returns correct structure', function (): void {
    $result = EventCatalog::topPriorityEvents(5);

    expect($result)->toBeArray();
    expect(count($result))->toBeLessThanOrEqual(5);

    foreach ($result as $item) {
        expect($item)->toHaveKey('event');
        expect($item)->toHaveKey('category');
        expect($item)->toHaveKey('priority');
        expect($item)->toHaveKey('tags');
        expect($item['priority'])->toBeInt();
        expect($item['tags'])->toBeArray();
    }
});

test('topPriorityEvents is sorted descending by priority', function (): void {
    $result = EventCatalog::topPriorityEvents(30);

    for ($i = 1; $i < count($result); $i++) {
        expect($result[$i]['priority'])->toBeLessThanOrEqual($result[$i - 1]['priority']);
    }
});

// ─── Recommended Instrumentation By Score ─────────────────────────────────

test('recommendedInstrumentationByScore returns correct structure for single tier', function (): void {
    $result = EventCatalog::recommendedInstrumentationByScore('starter');

    expect($result)->toBeArray();
    foreach ($result as $item) {
        expect($item)->toHaveKey('event');
        expect($item)->toHaveKey('priority');
        expect($item['priority'])->toBeGreaterThanOrEqual(60);
    }
});

test('recommendedInstrumentationByScore intermediate tier has scores 40-59', function (): void {
    $result = EventCatalog::recommendedInstrumentationByScore('intermediate');

    foreach ($result as $item) {
        expect($item['priority'])->toBeGreaterThanOrEqual(40);
        expect($item['priority'])->toBeLessThan(60);
    }
});

test('recommendedInstrumentationByScore advanced tier has scores below 40', function (): void {
    $result = EventCatalog::recommendedInstrumentationByScore('advanced');

    foreach ($result as $item) {
        expect($item['priority'])->toBeLessThan(40);
    }
});

test('recommendedInstrumentationByScore all tiers returns three keys', function (): void {
    $result = EventCatalog::recommendedInstrumentationByScore('all');

    expect($result)->toHaveKeys(['starter', 'intermediate', 'advanced']);
    expect($result['starter'])->toBeArray();
    expect($result['intermediate'])->toBeArray();
    expect($result['advanced'])->toBeArray();
});

test('recommendedInstrumentationByScore unknown tier falls back to starter', function (): void {
    $starter = EventCatalog::recommendedInstrumentationByScore('starter');
    $fallback = EventCatalog::recommendedInstrumentationByScore('unknown_tier');

    expect($fallback)->toBe($starter);
});

test('recommendedInstrumentationByScore total events across tiers equals catalog count', function (): void {
    $result = EventCatalog::recommendedInstrumentationByScore('all');
    $total = count(EventCatalog::all());

    expect(count($result['starter']) + count($result['intermediate']) + count($result['advanced']))->toBe($total);
});

// ─── Provider list consistency ───────────────────────────────────────────

test('all coverage methods use the same 8 providers', function (): void {
    $expectedProviders = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];

    // Verify providerCoverageParity
    $parity = EventCatalog::providerCoverageParity();
    expect(array_keys($parity['providers']))->toBe($expectedProviders);

    // Verify eventProviderMapping for a known event
    $mapping = EventCatalog::eventProviderMapping('purchase');
    expect(array_keys($mapping['providers']))->toBe($expectedProviders);
});

// ─── @since annotation verification ─────────────────────────────────────

test('new methods have @since 100.2.0 annotation', function (): void {
    $rc = new ReflectionClass(EventCatalog::class);
    $content = file_get_contents($rc->getFileName());

    expect($content)->toContain('providerCoverageParity');
    expect($content)->toContain('eventProviderMapping');
    expect($content)->toContain('fullyMappedEvents');
    expect($content)->toContain('leastMappedEvents');
    expect($content)->toContain('eventPriorityScore');
    expect($content)->toContain('topPriorityEvents');
    expect($content)->toContain('recommendedInstrumentationByScore');

    // Verify @since on each
    $methods = [
        'providerCoverageParity',
        'eventProviderMapping',
        'fullyMappedEvents',
        'leastMappedEvents',
        'eventPriorityScore',
        'topPriorityEvents',
        'recommendedInstrumentationByScore',
    ];

    foreach ($methods as $method) {
        $pattern = '/public static function ' . $method . '/';
        expect(preg_match($pattern, $content))->toBe(1);

        // Check that @since 100.2.0 appears before the method
        $methodPos = strpos($content, 'public static function ' . $method);
        $sincePos = strpos($content, '@since 100.2.0', max(0, $methodPos - 500));
        expect($sincePos)->not->toBeFalse();
        expect($sincePos)->toBeLessThan($methodPos);
    }
});

// ─── Strict types on EventCatalog ────────────────────────────────────────

test('EventCatalog declares strict_types', function (): void {
    $rc = new ReflectionClass(EventCatalog::class);
    $content = file_get_contents($rc->getFileName());

    expect($content)->toContain('declare(strict_types=1)');
});

test('EventCatalog is final', function (): void {
    expect((new ReflectionClass(EventCatalog::class))->isFinal())->toBeTrue();
});
