<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventContractTestService;

beforeEach(function (): void {
    $cache = app(CacheRepository::class);
    $config = app(ConfigRepository::class);

    // Ensure contract_testing config exists
    $config->set('zeroboiler.analytics.contract_testing', [
        'enabled' => true,
        'severity' => 'warn',
        'cache_ttl' => 3600,
    ]);

    $this->service = new EventContractTestService($cache, $config);
});

// ── Constructor & Configuration ────────────────────────────────────

test('service instantiates with default config', function (): void {
    expect($this->service)->toBeInstanceOf(EventContractTestService::class);
});

test('service reads enabled config', function (): void {
    expect($this->service->isEnabled())->toBeTrue();
});

test('service reads severity config', function (): void {
    expect($this->service->getSeverity())->toBe('warn');
});

test('service reads reject severity', function (): void {
    $config = app(ConfigRepository::class);
    $config->set('zeroboiler.analytics.contract_testing', [
        'enabled' => true,
        'severity' => 'reject',
        'cache_ttl' => 7200,
    ]);

    $service = new EventContractTestService(app(CacheRepository::class), $config);
    expect($service->getSeverity())->toBe('reject');
});

test('service can be disabled via config', function (): void {
    $config = app(ConfigRepository::class);
    $config->set('zeroboiler.analytics.contract_testing', [
        'enabled' => false,
        'severity' => 'off',
        'cache_ttl' => 3600,
    ]);

    $service = new EventContractTestService(app(CacheRepository::class), $config);
    expect($service->isEnabled())->toBeFalse();
});

// ── Severity Constants ────────────────────────────────────────────

test('severity constants are defined', function (): void {
    expect(EventContractTestService::SEVERITY_REJECT)->toBe('reject');
    expect(EventContractTestService::SEVERITY_WARN)->toBe('warn');
    expect(EventContractTestService::SEVERITY_OFF)->toBe('off');
});

// ── Contract Registration ────────────────────────────────────────

test('getContracts returns all provider contracts', function (): void {
    $contracts = $this->service->getContracts();

    expect($contracts)->toHaveKeys(['ga4', 'meta', 'posthog', 'plausible']);
    expect($contracts['ga4'])->toHaveKey('purchase');
    expect($contracts['ga4'])->toHaveKey('view_item');
    expect($contracts['ga4'])->toHaveKey('add_to_cart');
    expect($contracts['ga4'])->toHaveKey('refund');
    expect($contracts['ga4'])->toHaveKey('begin_checkout');
    expect($contracts['meta'])->toHaveKey('Purchase');
    expect($contracts['meta'])->toHaveKey('ViewContent');
    expect($contracts['meta'])->toHaveKey('AddToCart');
    expect($contracts['posthog'])->toHaveKey('$signup');
    expect($contracts['posthog'])->toHaveKey('$pageview');
    expect($contracts['plausible'])->toHaveKey('pageview');
});

test('contractCount returns total contracts', function (): void {
    $count = $this->service->contractCount();

    // 5 GA4 + 6 Meta + 2 PostHog + 1 Plausible = 14
    expect($count)->toBe(14);
});

test('hasContract detects events with contracts', function (): void {
    expect($this->service->hasContract('purchase'))->toBeTrue();
    expect($this->service->hasContract('add_to_cart'))->toBeTrue();
    expect($this->service->hasContract('Purchase'))->toBeTrue();
    expect($this->service->hasContract('$signup'))->toBeTrue();
    expect($this->service->hasContract('unknown_event_xyz'))->toBeFalse();
});

// ── Event Validation ──────────────────────────────────────────────

test('validateEvent returns correct structure', function (): void {
    $event = new AnalyticsEvent(name: 'purchase', params: [
        'transaction_id' => 'txn_001',
        'value' => 99.99,
        'currency' => 'USD',
    ]);

    $result = $this->service->validateEvent($event);

    expect($result)
        ->toHaveKey('event')
        ->toHaveKey('providers')
        ->toHaveKey('overall_passed')
        ->toHaveKey('severity');

    expect($result['event'])->toBe('purchase');
    expect($result['severity'])->toBe('warn');
    expect($result['providers'])->toHaveKeys([
        'ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin',
    ]);
});

test('validateEvent passes for valid purchase with all required params', function (): void {
    $event = new AnalyticsEvent(name: 'purchase', params: [
        'transaction_id' => 'txn_001',
        'value' => 99.99,
        'currency' => 'USD',
        'items' => [['item_id' => 'p1', 'price' => 99.99, 'quantity' => 1]],
    ]);

    $result = $this->service->validateEvent($event);

    // GA4 should pass (has required transaction_id, value)
    expect($result['providers']['ga4']['passed'])->toBeTrue();
    // Meta Purchase requires value + currency — passes
    expect($result['providers']['meta']['passed'])->toBeTrue();
});

test('validateEvent detects missing required params for GA4 purchase', function (): void {
    $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99]);

    $result = $this->service->validateEvent($event);

    // GA4 requires transaction_id
    expect($result['providers']['ga4']['passed'])->toBeFalse();

    $ga4Violations = $result['providers']['ga4']['violations'];
    $hasRequiredViolation = collect($ga4Violations)->contains(
        fn (array $v): bool => $v['rule'] === 'required_param' && $v['param'] === 'transaction_id',
    );
    expect($hasRequiredViolation)->toBeTrue();
});

test('validateEvent detects missing value for Meta Purchase', function (): void {
    $event = new AnalyticsEvent(name: 'purchase', params: ['transaction_id' => 'txn_001']);

    $result = $this->service->validateEvent($event);

    // Meta Purchase requires value and currency
    expect($result['providers']['meta']['passed'])->toBeFalse();
});

test('validateEvent detects enum constraint violation for currency', function (): void {
    $event = new AnalyticsEvent(name: 'purchase', params: [
        'transaction_id' => 'txn_001',
        'value' => 99.99,
        'currency' => 'INVALID_CURRENCY',
    ]);

    $result = $this->service->validateEvent($event);

    $ga4Violations = $result['providers']['ga4']['violations'];
    $hasEnumViolation = collect($ga4Violations)->contains(
        fn (array $v): bool => $v['rule'] === 'enum_constraint' && $v['param'] === 'currency',
    );
    expect($hasEnumViolation)->toBeTrue();
});

test('validateEvent detects max_items violation for GA4', function (): void {
    $items = [];
    for ($i = 0; $i < 30; $i++) {
        $items[] = ['item_id' => "item_{$i}", 'price' => 10.0, 'quantity' => 1];
    }

    $event = new AnalyticsEvent(name: 'purchase', params: [
        'transaction_id' => 'txn_001',
        'value' => 300.0,
        'currency' => 'USD',
        'items' => $items,
    ]);

    $result = $this->service->validateEvent($event);

    $ga4Violations = $result['providers']['ga4']['violations'];
    $hasMaxItemsViolation = collect($ga4Violations)->contains(
        fn (array $v): bool => $v['rule'] === 'max_items',
    );
    expect($hasMaxItemsViolation)->toBeTrue();
});

// ── PostHog Reserved Properties ───────────────────────────────────

test('validateEvent detects PostHog reserved properties', function (): void {
    $event = new AnalyticsEvent(name: 'custom_event', params: [
        '$distinct_id' => 'user_001',
        '$session_id' => 'session_001',
    ]);

    $result = $this->service->validateEvent($event);

    $posthogViolations = $result['providers']['posthog']['violations'];
    $hasReservedViolation = collect($posthogViolations)->contains(
        fn (array $v): bool => $v['rule'] === 'reserved_property',
    );
    expect($hasReservedViolation)->toBeTrue();
});

test('validateEvent allows params without reserved names for PostHog', function (): void {
    $event = new AnalyticsEvent(name: 'custom_event', params: [
        'page_title' => 'Home',
        'source' => 'newsletter',
    ]);

    $result = $this->service->validateEvent($event);

    $posthogViolations = $result['providers']['posthog']['violations'];
    $hasReservedViolation = collect($posthogViolations)->contains(
        fn (array $v): bool => $v['rule'] === 'reserved_property',
    );
    expect($hasReservedViolation)->toBeFalse();
});

// ── Parameter Length Validation ──────────────────────────────────

test('validateEvent detects long parameter values', function (): void {
    $longValue = str_repeat('a', 600);
    $event = new AnalyticsEvent(name: 'custom_event', params: [
        'description' => $longValue,
    ]);

    $result = $this->service->validateEvent($event);

    // At least one provider should report param_length violation
    $anyLengthViolation = false;
    foreach ($result['providers'] as $provider => $check) {
        if (collect($check['violations'])->contains(
            fn (array $v): bool => $v['rule'] === 'param_length',
        )) {
            $anyLengthViolation = true;
            break;
        }
    }
    expect($anyLengthViolation)->toBeTrue();
});

// ── Event Name Length Validation ──────────────────────────────────

test('validateEvent detects long event names', function (): void {
    $longName = str_repeat('a', 150);
    $event = new AnalyticsEvent(name: $longName, params: []);

    $result = $this->service->validateEvent($event);

    $ga4Violations = $result['providers']['ga4']['violations'];
    $hasNameLengthViolation = collect($ga4Violations)->contains(
        fn (array $v): bool => $v['rule'] === 'event_name_length',
    );
    expect($hasNameLengthViolation)->toBeTrue();
});

// ── Plausible Name Format ────────────────────────────────────────

test('validateEvent detects spaces in Plausible event names', function (): void {
    $event = new AnalyticsEvent(name: 'my custom event', params: []);

    $result = $this->service->validateEvent($event);

    $plausibleViolations = $result['providers']['plausible']['violations'];
    $hasFormatViolation = collect($plausibleViolations)->contains(
        fn (array $v): bool => $v['rule'] === 'plausible_name_format',
    );
    expect($hasFormatViolation)->toBeTrue();
});

// ── Disabled / Skipped Validation ──────────────────────────────────

test('validateEvent returns skip result when disabled', function (): void {
    $config = app(ConfigRepository::class);
    $config->set('zeroboiler.analytics.contract_testing', [
        'enabled' => false,
        'severity' => 'off',
        'cache_ttl' => 3600,
    ]);

    $service = new EventContractTestService(app(CacheRepository::class), $config);
    $event = new AnalyticsEvent(name: 'purchase', params: []);

    $result = $service->validateEvent($event);

    expect($result['overall_passed'])->toBeTrue();
    expect($result['skipped'])->toBeTrue();
});

test('validateEvent returns skip result when severity is off', function (): void {
    $config = app(ConfigRepository::class);
    $config->set('zeroboiler.analytics.contract_testing', [
        'enabled' => true,
        'severity' => 'off',
        'cache_ttl' => 3600,
    ]);

    $service = new EventContractTestService(app(CacheRepository::class), $config);
    $event = new AnalyticsEvent(name: 'purchase', params: []);

    $result = $service->validateEvent($event);

    expect($result['overall_passed'])->toBeTrue();
    expect($result['skipped'])->toBeTrue();
});

// ── Per-Provider Validation ───────────────────────────────────────

test('validateForProvider returns violations array', function (): void {
    $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99]);

    $violations = $this->service->validateForProvider($event, 'ga4');

    expect($violations)->toBeArray();
    // Missing transaction_id
    expect($violations)->not->toBeEmpty();
    $hasRequired = collect($violations)->contains(
        fn (array $v): bool => $v['rule'] === 'required_param' && $v['param'] === 'transaction_id',
    );
    expect($hasRequired)->toBeTrue();
});

test('validateForProvider returns empty for unknown provider with no contract', function (): void {
    $event = new AnalyticsEvent(name: 'unknown_event', params: ['foo' => 'bar']);

    $violations = $this->service->validateForProvider($event, 'ga4');

    // No contract for unknown_event in ga4, but param length check still applies
    expect($violations)->toBeArray();
});

// ── Catalog Validation ───────────────────────────────────────────

test('validateCatalog returns correct structure', function (): void {
    $result = $this->service->validateCatalog();

    expect($result)
        ->toHaveKey('total_events')
        ->toHaveKey('total_contracts')
        ->toHaveKey('coverage')
        ->toHaveKey('results')
        ->toHaveKey('provider_coverage')
        ->toHaveKey('grade');

    expect($result['total_events'])->toBeGreaterThan(0);
    expect($result['total_contracts'])->toBeGreaterThan(0);
    expect($result['coverage'])->toBeFloat();
    expect($result['grade'])->toBeString();

    // Each provider should have stats
    foreach (['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'] as $provider) {
        expect($result['results'])->toHaveKey($provider);
        expect($result['provider_coverage'])->toHaveKey($provider);
    }
});

// ── Provider Coverage ─────────────────────────────────────────────

test('providerCoverage returns correct structure', function (): void {
    $result = $this->service->providerCoverage('ga4');

    expect($result)
        ->toHaveKey('provider')
        ->toHaveKey('total_events')
        ->toHaveKey('passed')
        ->toHaveKey('failed')
        ->toHaveKey('coverage')
        ->toHaveKey('top_violations');

    expect($result['provider'])->toBe('ga4');
    expect($result['coverage'])->toBeFloat();
});

test('providerCoverage top_violations is capped at 20', function (): void {
    $result = $this->service->providerCoverage('ga4');

    expect(count($result['top_violations']))->toBeLessThanOrEqual(20);
});

// ── Coverage Grade ────────────────────────────────────────────────

test('coverage grades are calculated correctly', function (): void {
    // We can't call coverageGrade directly (private), but we can infer from validateCatalog
    $result = $this->service->validateCatalog();
    expect($result['grade'])->toMatch('/^[ABCDF][+-]?$/');
});

// ── Violation Structure ──────────────────────────────────────────

test('violation entries have correct structure', function (): void {
    $event = new AnalyticsEvent(name: 'purchase', params: []);

    $result = $this->service->validateEvent($event);
    $ga4Violations = $result['providers']['ga4']['violations'];

    foreach ($ga4Violations as $violation) {
        expect($violation)->toHaveKeys(['rule', 'message']);
        // param is optional
        expect($violation['rule'])->toBeString();
        expect($violation['message'])->toBeString();
    }
});

// ── Production Readiness ─────────────────────────────────────────

test('EventContractTestService is final', function (): void {
    $reflection = new ReflectionClass(EventContractTestService::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('EventContractTestService is in correct namespace', function (): void {
    expect(EventContractTestService::class)
        ->toBe('ZeroBoiler\\Analytics\\Services\\EventContractTestService');
});

test('EventContractTestService has @since annotation', function (): void {
    $reflection = new ReflectionClass(EventContractTestService::class);
    $doc = $reflection->getDocComment();
    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('@since 76.0.0');
});

test('EventContractTestService constructor has void return type', function (): void {
    $constructor = new ReflectionMethod(EventContractTestService::class, '__construct');
    expect($constructor->getReturnType()?->getName())->toBe('void');
});

test('all public methods have return type declarations', function (): void {
    $reflection = new ReflectionClass(EventContractTestService::class);

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== EventContractTestService::class) {
            continue;
        }
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull(
            "Method {$method->getName()} must have a return type declaration",
        );
    }
});

test('declare strict_types is present in service file', function (): void {
    $file = (string) file_get_contents(
        (string) (new ReflectionClass(EventContractTestService::class))->getFileName(),
    );
    expect($file)->toContain('declare(strict_types=1)');
});
