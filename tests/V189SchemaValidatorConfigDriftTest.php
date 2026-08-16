<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\ConfigDriftDetectionService;
use ZeroBoiler\Analytics\Services\EventSchemaValidatorService;

/**
 * V189 — Cross-Provider Schema Validation + Config Drift Detection — Industry-Standard SaaS Analytics Upgrade.
 *
 * Validates the new EventSchemaValidatorService and ConfigDriftDetectionService:
 * - Schema validation for GA4, Meta, PostHog, Plausible, Mixpanel, Amplitude
 * - Ecommerce item validation (required/recommended fields, max items)
 * - Batch validation with aggregate reports
 * - Config baseline capture, drift detection, severity classification
 * - Drift history tracking and clearing
 * - Source quality (strict_types, final, return types, MIT headers, @since)
 * - Version consistency across all entry points
 */
test('v189 schema validator: service file quality', function (): void {
    $path = __DIR__ . '/../src/Services/EventSchemaValidatorService.php';
    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);
    expect($content)->toContain('declare(strict_types=1)');
    expect($content)->toContain('final class EventSchemaValidatorService');
    expect($content)->toContain('public function __construct');
    expect($content)->toContain('): void');
    expect($content)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
    expect($content)->toContain('@since 189.0.0');
});

test('v189 schema validator: validate method returns correct structure', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventSchemaValidatorService($cache, $config);
    expect(method_exists($service, 'validate'))->toBeTrue();
    expect(method_exists($service, 'validateBatch'))->toBeTrue();
    expect(method_exists($service, 'validateForProvider'))->toBeTrue();

    $event = new AnalyticsEvent(name: 'test_click', params: ['element' => 'button']);
    $result = $service->validate($event);

    expect($result)->toHaveKeys(['valid', 'issues']);
    expect($result['valid'])->toBeBool();
    expect($result['issues'])->toBeArray();
});

test('v189 schema validator: validateForGA4 detects long event name', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventSchemaValidatorService($cache, $config);

    $longName = str_repeat('a', 50);
    $event = new AnalyticsEvent(name: $longName, params: []);
    $issues = $service->validateForGA4($event);

    $hasLongNameIssue = collect($issues)->contains(fn (array $i): bool => $i['code'] === 'GA4_EVENT_NAME_TOO_LONG');
    expect($hasLongNameIssue)->toBeTrue();
});

test('v189 schema validator: validateForGA4 detects reserved params', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventSchemaValidatorService($cache, $config);

    $event = new AnalyticsEvent(name: 'page_view', params: ['page_location' => 'https://example.com']);
    $issues = $service->validateForGA4($event);

    $hasReservedIssue = collect($issues)->contains(fn (array $i): bool => $i['code'] === 'GA4_RESERVED_PARAM');
    expect($hasReservedIssue)->toBeTrue();
});

test('v189 schema validator: validateForGA4 detects missing item_id in ecommerce', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventSchemaValidatorService($cache, $config);

    $event = new AnalyticsEvent(
        name: 'purchase',
        category: 'ecommerce',
        params: [
            'transaction_id' => 'TXN-123',
            'value' => 99.99,
            'items' => [
                ['item_name' => 'Widget', 'price' => 49.99], // missing item_id
            ],
        ],
    );
    $issues = $service->validateForGA4($event);

    $hasMissingItem = collect($issues)->contains(fn (array $i): bool => $i['code'] === 'GA4_MISSING_ITEM_FIELD');
    expect($hasMissingItem)->toBeTrue();
});

test('v189 schema validator: validateForGA4 detects too many items', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventSchemaValidatorService($cache, $config);

    $items = array_fill(0, 201, ['item_id' => 'SKU-' . 1, 'item_name' => 'Item', 'price' => 10.0]);
    $event = new AnalyticsEvent(name: 'purchase', category: 'ecommerce', params: ['items' => $items]);
    $issues = $service->validateForGA4($event);

    $hasTooMany = collect($issues)->contains(fn (array $i): bool => $i['code'] === 'GA4_TOO_MANY_ITEMS');
    expect($hasTooMany)->toBeTrue();
});

test('v189 schema validator: validateForMeta detects missing value in purchase', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventSchemaValidatorService($cache, $config);

    $event = new AnalyticsEvent(name: 'purchase', params: ['transaction_id' => 'TXN-123']);
    $issues = $service->validateForMeta($event);

    $hasMissingValue = collect($issues)->contains(fn (array $i): bool => $i['code'] === 'META_MISSING_VALUE');
    expect($hasMissingValue)->toBeTrue();
});

test('v189 schema validator: validateForPostHog detects reserved keys', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventSchemaValidatorService($cache, $config);

    $event = new AnalyticsEvent(name: 'custom_event', params: ['$distinct_id' => 'user_123']);
    $issues = $service->validateForPostHog($event);

    $hasReserved = collect($issues)->contains(fn (array $i): bool => $i['code'] === 'POSTHOG_RESERVED_KEY');
    expect($hasReserved)->toBeTrue();
});

test('v189 schema validator: validateForMixpanel detects too many props', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventSchemaValidatorService($cache, $config);

    $params = array_combine(
        array_map(fn (int $i): string => 'prop_' . $i, range(1, 256)),
        array_fill(0, 256, 'value'),
    );
    $event = new AnalyticsEvent(name: 'custom_event', params: $params);
    $issues = $service->validateForMixpanel($event);

    $hasTooMany = collect($issues)->contains(fn (array $i): bool => $i['code'] === 'MIXPANEL_TOO_MANY_PROPS');
    expect($hasTooMany)->toBeTrue();
});

test('v189 schema validator: validateForAmplitude detects reserved keys', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventSchemaValidatorService($cache, $config);

    $event = new AnalyticsEvent(name: 'custom_event', params: ['device_id' => 'dev_123']);
    $issues = $service->validateForAmplitude($event);

    $hasReserved = collect($issues)->contains(fn (array $i): bool => $i['code'] === 'AMPLITUDE_RESERVED_KEY');
    expect($hasReserved)->toBeTrue();
});

test('v189 schema validator: batch validation returns aggregate report', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventSchemaValidatorService($cache, $config);

    $events = [
        new AnalyticsEvent(name: 'page_view', params: []),
        new AnalyticsEvent(name: str_repeat('x', 50), params: ['page_location' => 'https://example.com']),
        new AnalyticsEvent(name: 'purchase', params: ['transaction_id' => 'TXN-1']),
    ];

    $report = $service->validateBatch($events);

    expect($report)->toHaveKeys(['total', 'valid', 'invalid', 'warnings', 'errors', 'by_provider', 'issues']);
    expect($report['total'])->toBe(3);
    expect($report['by_provider'])->toBeArray();
});

test('v189 schema validator: providerRules returns documentation for all 6 providers', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventSchemaValidatorService($cache, $config);
    $rules = $service->providerRules();

    expect($rules)->toHaveKeys(['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude']);

    foreach ($rules as $provider => $ruleSet) {
        expect($ruleSet)->toHaveKeys(['max_params', 'max_event_name_length', 'reserved_params', 'required_ecommerce_fields', 'notes']);
        expect($ruleSet['max_params'])->toBeInt();
        expect($ruleSet['max_event_name_length'])->toBeInt();
        expect($ruleSet['reserved_params'])->toBeArray();
        expect($ruleSet['required_ecommerce_fields'])->toBeArray();
    }
});

test('v189 schema validator: stats returns expected structure', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventSchemaValidatorService($cache, $config);
    $stats = $service->stats();

    expect($stats)->toHaveKeys(['enabled', 'strict_mode', 'providers', 'max_event_name_length', 'max_params_count', 'provider_rules_count']);
    expect($stats['enabled'])->toBeBool();
    expect($stats['provider_rules_count'])->toBe(6);
});

test('v189 schema validator: isEnabled and getActiveProviders return correct types', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventSchemaValidatorService($cache, $config);
    expect($service->isEnabled())->toBeBool();
    expect($service->isStrictMode())->toBeBool();
    expect($service->getActiveProviders())->toBeArray();
});

test('v189 schema validator: clearCache does not throw', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventSchemaValidatorService($cache, $config);
    $service->clearCache();
    expect(true)->toBeTrue();
});

test('v189 schema validator: summary returns expected structure', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventSchemaValidatorService($cache, $config);
    $summary = $service->summary();

    expect($summary)->toHaveKeys(['total_validated', 'error_rate', 'warning_rate', 'top_issues', 'last_run']);
    expect($summary['total_validated'])->toBeInt();
    expect($summary['error_rate'])->toBeFloat();
});

test('v189 config drift: service file quality', function (): void {
    $path = __DIR__ . '/../src/Services/ConfigDriftDetectionService.php';
    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);
    expect($content)->toContain('declare(strict_types=1)');
    expect($content)->toContain('final class ConfigDriftDetectionService');
    expect($content)->toContain('public function __construct');
    expect($content)->toContain('): void');
    expect($content)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
    expect($content)->toContain('@since 189.0.0');
});

test('v189 config drift: capture and detect baseline', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new ConfigDriftDetectionService($cache, $config);
    expect(method_exists($service, 'captureBaseline'))->toBeTrue();
    expect(method_exists($service, 'detect'))->toBeTrue();
    expect(method_exists($service, 'detectSection'))->toBeTrue();

    // Capture baseline
    $result = $service->captureBaseline('test_v189');

    expect($result)->toHaveKeys(['label', 'captured_at', 'sections', 'keys']);
    expect($result['label'])->toBe('test_v189');
    expect($result['sections'])->toBeGreaterThan(0);
    expect($result['keys'])->toBeGreaterThan(0);

    // Detect no drift immediately after capture
    $drift = $service->detect('test_v189');

    expect($drift['drift_detected'])->toBeFalse();
    expect($drift['drift_count'])->toBe(0);
    expect($drift)->toHaveKeys(['drift_detected', 'drift_count', 'critical', 'warnings', 'info', 'changes', 'baseline_info']);
    expect($drift['baseline_info']['label'])->toBe('test_v189');

    // Cleanup
    $service->clearBaseline('test_v189');
});

test('v189 config drift: getBaseline returns null when no baseline', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new ConfigDriftDetectionService($cache, $config);
    $baseline = $service->getBaseline('nonexistent_v189_baseline');

    expect($baseline)->toBeNull();
});

test('v189 config drift: getBaseline returns data after capture', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new ConfigDriftDetectionService($cache, $config);
    $service->captureBaseline('test_v189_existing');

    $baseline = $service->getBaseline('test_v189_existing');

    expect($baseline)->not->toBeNull();
    expect($baseline['exists'])->toBeTrue();
    expect($baseline['label'])->toBe('test_v189_existing');
    expect($baseline['captured_at'])->toBeString();
    expect($baseline['sections'])->toBeGreaterThan(0);

    $service->clearBaseline('test_v189_existing');
});

test('v189 config drift: detectSection returns correct structure', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new ConfigDriftDetectionService($cache, $config);
    $service->captureBaseline('test_v189_section');

    $result = $service->detectSection('ga4', 'test_v189_section');

    expect($result)->toHaveKeys(['drift_detected', 'changes']);
    expect($result['drift_detected'])->toBeBool();
    expect($result['changes'])->toBeArray();

    $service->clearBaseline('test_v189_section');
});

test('v189 config drift: history tracking works', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new ConfigDriftDetectionService($cache, $config);
    $service->captureBaseline('test_v189_history');
    $service->clearHistory('test_v189_history');

    // Detect (no drift) records history
    $service->detect('test_v189_history');

    $history = $service->getHistory('test_v189_history');
    expect($history)->toBeArray();
    expect(count($history))->toBeGreaterThanOrEqual(1);

    if (isset($history[0])) {
        expect($history[0])->toHaveKeys(['detected_at', 'drift_count']);
    }

    // Clear history
    $service->clearHistory('test_v189_history');
    expect($service->getHistory('test_v189_history'))->toBeEmpty();

    $service->clearBaseline('test_v189_history');
});

test('v189 config drift: quickSummary returns expected structure', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new ConfigDriftDetectionService($cache, $config);

    $summary = $service->quickSummary();

    expect($summary)->toHaveKeys(['enabled', 'baseline_exists', 'drift_detected', 'last_checked', 'drift_count']);
    expect($summary['enabled'])->toBeBool();
});

test('v189 config drift: stats returns expected structure', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new ConfigDriftDetectionService($cache, $config);
    $stats = $service->stats();

    expect($stats)->toHaveKeys(['enabled', 'baseline_label', 'ttl', 'monitored_sections', 'critical_sections', 'warning_sections', 'ignored_keys']);
    expect($stats['monitored_sections'])->toBe(15);
    expect($stats['critical_sections'])->toBe(6);
    expect($stats['warning_sections'])->toBe(5);
});

test('v189 config drift: getMonitoredSections and getIgnoredKeys return arrays', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new ConfigDriftDetectionService($cache, $config);
    expect($service->getMonitoredSections())->toBeArray();
    expect($service->getIgnoredKeys())->toBeArray();
    expect(count($service->getMonitoredSections()))->toBe(15);
});

test('v189 config drift: isEnabled returns bool', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new ConfigDriftDetectionService($cache, $config);
    expect($service->isEnabled())->toBeBool();
});

test('v189 config drift: clearBaseline and clearHistory do not throw', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new ConfigDriftDetectionService($cache, $config);

    // Should not throw even for non-existent baselines
    $service->clearBaseline('nonexistent_v189');
    $service->clearHistory('nonexistent_v189');

    expect(true)->toBeTrue();
});

test('v189 cross-service: schema validator and config drift are independent', function (): void {
    $cache = app('cache');
    $config = app('config');

    $schemaService = new EventSchemaValidatorService($cache, $config);
    $driftService = new ConfigDriftDetectionService($cache, $config);

    // Schema validator validates events
    $event = new AnalyticsEvent(name: 'button_click', params: []);
    $validationResult = $schemaService->validate($event);
    expect($validationResult)->toHaveKey('valid');

    // Config drift monitors config
    $driftResult = $driftService->detect();
    expect($driftResult)->toHaveKey('drift_detected');

    // They don't interfere
    expect($validationResult['valid'])->toBeBool();
    expect($driftResult['drift_detected'])->toBeBool();
});

test('v189 version sweep: AnalyticsEvent::VERSION is 189.0.0', function (): void {
    // After version update
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('189.0.0');

    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($js)->toContain('@version 189.0.0');

    $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
    expect($dts)->toContain('@version 189.0.0');

    $readme = file_get_contents(__DIR__ . '/../README.md');
    expect($readme)->toContain('version-189.0.0');
});

test('v189 service count minimum', function (): void {
    $services = glob(__DIR__ . '/../src/Services/*.php');
    expect(count($services))->toBeGreaterThanOrEqual(390); // 389 from v188 + 1 new (drift was pre-imported)
});

test('v189 src file count minimum', function (): void {
    $srcFiles = glob(__DIR__ . '/../src/**/*.php', GLOB_BRACE);
    $directFiles = glob(__DIR__ . '/../src/*.php');
    $allFiles = array_merge($srcFiles, $directFiles);
    // Deduplicate
    $allFiles = array_unique($allFiles);
    expect(count($allFiles))->toBeGreaterThanOrEqual(855); // 854 from v188 + 2 new services (includes other files)
});

test('v189 test count minimum', function (): void {
    $tests = glob(__DIR__ . '/*Test.php');
    expect(count($tests))->toBeGreaterThanOrEqual(434); // 433 from v188 + new test file
});
