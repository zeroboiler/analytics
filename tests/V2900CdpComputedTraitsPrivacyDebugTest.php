<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\CustomerProfileUnificationService;
use ZeroBoiler\Analytics\Services\ComputedTraitsService;
use ZeroBoiler\Analytics\Services\PrivacyReportGeneratorService;
use ZeroBoiler\Analytics\Services\EventDebugCaptureService;

/**
 * V2900 — CDP Profile Unification, Computed Traits, Privacy Reports, Event Debug Capture.
 *
 * Validates the v29.0.0 industry-standard SaaS analytics upgrade:
 * - CustomerProfileUnificationService (CDP profile building, merging, traits, segments, external IDs)
 * - ComputedTraitsService (rule evaluation, custom computers, multiple operations)
 * - PrivacyReportGeneratorService (GDPR Article 30, CCPA inventory, consent audit, DSAR)
 * - EventDebugCaptureService (capture, replay, simulate, batch, filters, observers)
 * - Version sweep: 28.0.0 → 29.0.0 across all files
 * - Config expansion: 4 new config sections (cdp, computed_traits, privacy_report, debug_capture)
 */
test('v29.0.0: version sweep — AnalyticsEvent is 29.0.0', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('29.0.0');
});

test('v29.0.0: version sweep — composer.json is 29.0.0', function (): void {
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('29.0.0');
});

test('v29.0.0: version sweep — package.json is 29.0.0', function (): void {
    $pkg = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);
    expect($pkg['version'])->toBe('29.0.0');
});

test('v29.0.0: version sweep — JS analytics.js is 29.0.0', function (): void {
    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($js)->toContain('@version 29.0.0');
    expect($js)->toContain("'29.0.0'");
});

test('v29.0.0: version sweep — Svelte composables are 29.0.0', function (): void {
    $analytics = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
    expect($analytics)->toContain('@version 29.0.0');

    $perf = file_get_contents(__DIR__ . '/../resources/js/usePerformanceTracker.svelte.js');
    expect($perf)->toContain('@version 29.0.0');

    $config = file_get_contents(__DIR__ . '/../resources/js/useAnalyticsConfig.svelte.js');
    expect($config)->toContain('@version 29.0.0');
});

test('v29.0.0: version sweep — README badge is 29.0.0', function (): void {
    $readme = file_get_contents(__DIR__ . '/../README.md');
    expect($readme)->toContain('version-29.0.0');
});

// ─── CustomerProfileUnificationService ──────────────────────────────────

test('cdp: service class exists and is final', function (): void {
    expect(class_exists(CustomerProfileUnificationService::class))->toBeTrue();

    $reflection = new ReflectionClass(CustomerProfileUnificationService::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('cdp: constructor injects cache, config, propertiesStore, identityResolution', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);
    $identityResolution = app(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class);

    $service = new CustomerProfileUnificationService(
        $cache,
        $config,
        $propertiesStore,
        $identityResolution,
    );

    expect($service)->toBeInstanceOf(CustomerProfileUnificationService::class);
    expect($service->isEnabled())->toBeTrue();
});

test('cdp: getProfile returns correct structure', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);
    $identityResolution = app(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class);

    $service = new CustomerProfileUnificationService(
        $cache,
        $config,
        $propertiesStore,
        $identityResolution,
    );

    $profile = $service->getProfile('test-user-123');

    expect($profile)->toHaveKey('identity');
    expect($profile)->toHaveKey('traits');
    expect($profile)->toHaveKey('segments');
    expect($profile)->toHaveKey('events');
    expect($profile)->toHaveKey('lifetime');
    expect($profile)->toHaveKey('computed_at');

    expect($profile['identity'])->toHaveKey('user_id');
    expect($profile['identity'])->toHaveKey('client_ids');
    expect($profile['identity'])->toHaveKey('canonical_id');
    expect($profile['identity'])->toHaveKey('external_ids');
    expect($profile['events'])->toHaveKey('total');
    expect($profile['events'])->toHaveKey('by_category');
    expect($profile['events'])->toHaveKey('recent');
    expect($profile['lifetime'])->toHaveKey('account_age_days');
    expect($profile['lifetime'])->toHaveKey('first_seen');
    expect($profile['lifetime'])->toHaveKey('last_active');
    expect($profile['lifetime'])->toHaveKey('session_count');
    expect($profile['lifetime'])->toHaveKey('total_revenue');
    expect($profile['lifetime']['total_revenue'])->toBeFloat();
});

test('cdp: setTrait and getTrait work', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);
    $identityResolution = app(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class);

    $service = new CustomerProfileUnificationService(
        $cache,
        $config,
        $propertiesStore,
        $identityResolution,
    );

    $service->setTrait('test-cdp-user', 'plan', 'enterprise');
    expect($service->getTrait('test-cdp-user', 'plan'))->toBe('enterprise');

    $service->setTrait('test-cdp-user', 'company', 'Acme Inc');
    expect($service->getTrait('test-cdp-user', 'company'))->toBe('Acme Inc');
});

test('cdp: setTraits merges multiple traits', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);
    $identityResolution = app(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class);

    $service = new CustomerProfileUnificationService(
        $cache,
        $config,
        $propertiesStore,
        $identityResolution,
    );

    $service->setTraits('test-cdp-batch', [
        'plan' => 'pro',
        'role' => 'admin',
        'team_size' => 15,
    ]);

    $traits = $service->getTraits('test-cdp-batch');
    expect($traits['plan'])->toBe('pro');
    expect($traits['role'])->toBe('admin');
    expect($traits['team_size'])->toBe(15);
});

test('cdp: getTraits filters internal keys', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);
    $identityResolution = app(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class);

    $service = new CustomerProfileUnificationService(
        $cache,
        $config,
        $propertiesStore,
        $identityResolution,
    );

    $service->setTrait('test-cdp-filter', 'public_name', 'John');
    $service->setTrait('test-cdp-filter', '_internal_key', 'should-not-appear');

    $traits = $service->getTraits('test-cdp-filter');
    expect($traits)->toHaveKey('public_name');
    expect($traits)->not->toHaveKey('_internal_key');
});

test('cdp: updateFromEvent increments counters', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);
    $identityResolution = app(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class);

    $service = new CustomerProfileUnificationService(
        $cache,
        $config,
        $propertiesStore,
        $identityResolution,
    );

    $service->updateFromEvent('test-cdp-events', 'page_view', 'engagement');
    $service->updateFromEvent('test-cdp-events', 'page_view', 'engagement');
    $service->updateFromEvent('test-cdp-events', 'purchase', 'ecommerce', ['value' => 49.99]);

    $profile = $service->getProfile('test-cdp-events');
    expect($profile['events']['total'])->toBe(3);
    expect($profile['events']['by_category'])->toHaveKey('engagement');
    expect($profile['events']['by_category']['engagement'])->toBe(2);
    expect($profile['events']['by_category'])->toHaveKey('ecommerce');
    expect($profile['lifetime']['total_revenue'])->toBe(49.99);
});

test('cdp: addExternalId and getExternalIds work', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);
    $identityResolution = app(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class);

    $service = new CustomerProfileUnificationService(
        $cache,
        $config,
        $propertiesStore,
        $identityResolution,
    );

    $service->addExternalId('test-cdp-ext', 'stripe', 'cus_12345');
    $service->addExternalId('test-cdp-ext', 'hubspot', 'hub_67890');

    $externalIds = $service->getExternalIds('test-cdp-ext');
    expect($externalIds)->toHaveKey('stripe');
    expect($externalIds['stripe'])->toBe('cus_12345');
    expect($externalIds['hubspot'])->toBe('hub_67890');
});

test('cdp: exportProfile returns clean structure', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);
    $identityResolution = app(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class);

    $service = new CustomerProfileUnificationService(
        $cache,
        $config,
        $propertiesStore,
        $identityResolution,
    );

    $export = $service->exportProfile('test-cdp-export');
    expect($export)->toHaveKey('user_id');
    expect($export)->toHaveKey('traits');
    expect($export)->toHaveKey('external_ids');
    expect($export)->toHaveKey('segments');
    expect($export)->toHaveKey('computed_at');
});

test('cdp: stats returns correct structure', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);
    $identityResolution = app(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class);

    $service = new CustomerProfileUnificationService(
        $cache,
        $config,
        $propertiesStore,
        $identityResolution,
    );

    $stats = $service->stats();
    expect($stats)->toHaveKey('enabled');
    expect($stats)->toHaveKey('enrichers');
    expect($stats)->toHaveKey('profile_ttl');
    expect($stats)->toHaveKey('max_recent_events');
});

test('cdp: registerEnricher adds custom enricher', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);
    $identityResolution = app(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class);

    $service = new CustomerProfileUnificationService(
        $cache,
        $config,
        $propertiesStore,
        $identityResolution,
    );

    $service->registerEnricher(function (string $id, array $profile): array {
        $profile['traits']['enriched'] = true;

        return $profile;
    });

    // Build a fresh profile (bypass cache)
    $cache->forget('zb_cdp_profile_test-cdp-enrich');

    $profile = $service->getProfile('test-cdp-enrich');
    expect($profile['traits'])->toHaveKey('enriched');
    expect($profile['traits']['enriched'])->toBeTrue();
});

// ─── ComputedTraitsService ──────────────────────────────────────────────

test('computed traits: service class exists and is final', function (): void {
    expect(class_exists(ComputedTraitsService::class))->toBeTrue();

    $reflection = new ReflectionClass(ComputedTraitsService::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('computed traits: addRule and evaluate exist operation', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);

    $service = new ComputedTraitsService($cache, $config, $propertiesStore);
    $service->setTrait = fn (string $id, string $k, mixed $v): mixed => $propertiesStore->set($id, $k, $v);

    $service->addRule('has_plan', 'plan', 'exists', null, 'has_plan', 'bool');

    $propertiesStore->set('ct-test-1', 'plan', 'enterprise');

    $result = $service->evaluate('ct-test-1');
    expect($result['has_plan'])->toBeTrue();
});

test('computed traits: eq and neq operations', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);

    $service = new ComputedTraitsService($cache, $config, $propertiesStore);

    $service->addRule('is_free', 'plan', 'eq', 'free', 'is_free', 'bool');
    $service->addRule('is_paying', 'plan', '!=', 'free', 'is_paying', 'bool');

    $propertiesStore->set('ct-test-2', 'plan', 'free');

    $result = $service->evaluate('ct-test-2');
    expect($result['is_free'])->toBeTrue();
    expect($result['is_paying'])->toBeFalse();

    $propertiesStore->set('ct-test-2', 'plan', 'pro');
    $cache->forget('zb_ct_ct-test-2');

    $result = $service->evaluate('ct-test-2');
    expect($result['is_free'])->toBeFalse();
    expect($result['is_paying'])->toBeTrue();
});

test('computed traits: gt and lte operations', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);

    $service = new ComputedTraitsService($cache, $config, $propertiesStore);

    $service->addRule('high_value', 'total_revenue', '>', 100, 'high_value', 'bool');
    $service->addRule('low_events', 'event_count', '<=', 10, 'low_events', 'bool');

    $propertiesStore->set('ct-test-3', 'total_revenue', 150.0);
    $propertiesStore->set('ct-test-3', 'event_count', 5);

    $result = $service->evaluate('ct-test-3');
    expect($result['high_value'])->toBeTrue();
    expect($result['low_events'])->toBeTrue();
});

test('computed traits: contains operation', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);

    $service = new ComputedTraitsService($cache, $config, $propertiesStore);

    $service->addRule('has_gmail', 'email', 'contains', '@gmail', 'has_gmail', 'bool');

    $propertiesStore->set('ct-test-4', 'email', 'user@gmail.com');

    $result = $service->evaluate('ct-test-4');
    expect($result['has_gmail'])->toBeTrue();
});

test('computed traits: in and not_in operations', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);

    $service = new ComputedTraitsService($cache, $config, $propertiesStore);

    $service->addRule('is_enterprise_plan', 'plan', 'in', ['enterprise', 'business'], 'is_enterprise_plan', 'bool');
    $service->addRule('is_not_free', 'plan', 'not_in', ['free', 'trial'], 'is_not_free', 'bool');

    $propertiesStore->set('ct-test-5', 'plan', 'enterprise');

    $result = $service->evaluate('ct-test-5');
    expect($result['is_enterprise_plan'])->toBeTrue();
    expect($result['is_not_free'])->toBeTrue();
});

test('computed traits: custom computer works', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);

    $service = new ComputedTraitsService($cache, $config, $propertiesStore);

    $service->registerComputer('computed_ltv', function (array $traits): float {
        $revenue = (float) ($traits['total_revenue'] ?? 0);
        $events = (int) ($traits['_total_events'] ?? 1);

        return $events > 0 ? round($revenue / $events, 2) : 0.0;
    });

    $propertiesStore->set('ct-test-6', 'total_revenue', 500);
    $propertiesStore->set('ct-test-6', '_total_events', 50);

    $result = $service->evaluate('ct-test-6');
    expect($result['computed_ltv'])->toBe(10.0);
});

test('computed traits: getRules and removeRule work', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);

    $service = new ComputedTraitsService($cache, $config, $propertiesStore);

    $service->addRule('temp_rule', 'email', 'exists', null, 'has_email', 'bool');
    expect($service->getRules())->toHaveKey('temp_rule');

    $service->removeRule('temp_rule');
    expect($service->getRules())->not->toHaveKey('temp_rule');
});

test('computed traits: stats returns correct structure', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);

    $service = new ComputedTraitsService($cache, $config, $propertiesStore);

    $stats = $service->stats();
    expect($stats)->toHaveKey('enabled');
    expect($stats)->toHaveKey('rules');
    expect($stats)->toHaveKey('custom_computers');
    expect($stats)->toHaveKey('cache_ttl');
});

// ─── PrivacyReportGeneratorService ──────────────────────────────────────

test('privacy report: service class exists and is final', function (): void {
    expect(class_exists(PrivacyReportGeneratorService::class))->toBeTrue();

    $reflection = new ReflectionClass(PrivacyReportGeneratorService::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('privacy report: generateArticle30Report returns GDPR structure', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);
    $identityResolution = app(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class);
    $cdp = new CustomerProfileUnificationService($cache, $config, $propertiesStore, $identityResolution);

    $service = new PrivacyReportGeneratorService(
        $cache,
        $config,
        $propertiesStore,
        $identityResolution,
        $cdp,
    );

    $report = $service->generateArticle30Report(true);

    expect($report['report_type'])->toBe('GDPR Article 30 — Records of Processing Activities');
    expect($report)->toHaveKey('jurisdiction');
    expect($report)->toHaveKey('organization');
    expect($report)->toHaveKey('generated_at');
    expect($report)->toHaveKey('controller');
    expect($report)->toHaveKey('processing_activities');
    expect($report['controller'])->toHaveKey('name');
    expect($report['controller'])->toHaveKey('dpo_contact');
    expect($report['controller'])->toHaveKey('role');
    expect(is_array($report['processing_activities']))->toBeTrue();
    expect(count($report['processing_activities']))->toBeGreaterThanOrEqual(5);

    // Validate processing activity structure
    foreach ($report['processing_activities'] as $activity) {
        expect($activity)->toHaveKey('name');
        expect($activity)->toHaveKey('purpose');
        expect($activity)->toHaveKey('legal_basis');
        expect($activity)->toHaveKey('data_categories');
        expect($activity)->toHaveKey('retention');
        expect($activity)->toHaveKey('technical_measures');
        expect($activity)->toHaveKey('recipients');
    }
});

test('privacy report: generateCcpaInventory returns CCPA structure', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);
    $identityResolution = app(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class);
    $cdp = new CustomerProfileUnificationService($cache, $config, $propertiesStore, $identityResolution);

    $service = new PrivacyReportGeneratorService(
        $cache,
        $config,
        $propertiesStore,
        $identityResolution,
        $cdp,
    );

    $report = $service->generateCcpaInventory(true);

    expect($report['report_type'])->toBe('CCPA Data Inventory');
    expect($report['jurisdiction'])->toBe('CCPA (California)');
    expect($report)->toHaveKey('data_inventory');

    foreach ($report['data_inventory'] as $item) {
        expect($item)->toHaveKey('field');
        expect($item)->toHaveKey('category');
        expect($item)->toHaveKey('source');
        expect($item)->toHaveKey('purpose');
        expect($item)->toHaveKey('shared');
        expect($item)->toHaveKey('retention');
        expect($item)->toHaveKey('sensitive');
    }
});

test('privacy report: generateConsentAudit returns compliance status', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);
    $identityResolution = app(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class);
    $cdp = new CustomerProfileUnificationService($cache, $config, $propertiesStore, $identityResolution);

    $service = new PrivacyReportGeneratorService(
        $cache,
        $config,
        $propertiesStore,
        $identityResolution,
        $cdp,
    );

    $report = $service->generateConsentAudit(true);

    expect($report['report_type'])->toBe('Consent Compliance Audit');
    expect($report)->toHaveKey('consent_config');
    expect($report)->toHaveKey('compliance_status');
    expect($report)->toHaveKey('recommendations');

    expect($report['compliance_status'])->toHaveKey('consent_default_safe');
    expect($report['compliance_status'])->toHaveKey('all_purposes_configured');
    expect($report['compliance_status'])->toHaveKey('logging_active');
    expect($report['compliance_status'])->toHaveKey('gdpr_ready');

    expect($report['consent_config'])->toHaveKey('default_state');
    expect($report['consent_config'])->toHaveKey('purposes');
    expect($report['consent_config'])->toHaveKey('logging_enabled');
});

test('privacy report: generateDataSubjectReport returns DSAR structure', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);
    $identityResolution = app(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class);
    $cdp = new CustomerProfileUnificationService($cache, $config, $propertiesStore, $identityResolution);

    $service = new PrivacyReportGeneratorService(
        $cache,
        $config,
        $propertiesStore,
        $identityResolution,
        $cdp,
    );

    $report = $service->generateDataSubjectReport('test-dsar-user');

    expect($report['report_type'])->toBe('Data Subject Access Report');
    expect($report['request_type'])->toBe('Personal Data Access');
    expect($report)->toHaveKey('subject');
    expect($report)->toHaveKey('data');
    expect($report)->toHaveKey('processing_activities');
    expect($report)->toHaveKey('retention_info');

    expect($report['subject'])->toHaveKey('identity');
    expect($report['data'])->toHaveKey('profile');
    expect($report['data'])->toHaveKey('traits');
    expect($report['data'])->toHaveKey('external_ids');
    expect($report['data'])->toHaveKey('identity_links');
});

test('privacy report: generateFullReport returns combined structure', function (): void {
    $cache = app('cache');
    $config = app('config');
    $propertiesStore = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);
    $identityResolution = app(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class);
    $cdp = new CustomerProfileUnificationService($cache, $config, $propertiesStore, $identityResolution);

    $service = new PrivacyReportGeneratorService(
        $cache,
        $config,
        $propertiesStore,
        $identityResolution,
        $cdp,
    );

    $report = $service->generateFullReport(true);

    expect($report)->toHaveKey('article_30');
    expect($report)->toHaveKey('ccpa_inventory');
    expect($report)->toHaveKey('consent_audit');
    expect($report)->toHaveKey('generated_at');
});

// ─── EventDebugCaptureService ──────────────────────────────────────────

test('debug capture: service class exists and is final', function (): void {
    expect(class_exists(EventDebugCaptureService::class))->toBeTrue();

    $reflection = new ReflectionClass(EventDebugCaptureService::class);
    expect($reflection->isFinal())->toBeTrue();
});

test('debug capture: disabled by default', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventDebugCaptureService($cache, $config);

    // Default config has debug_capture.enabled = false
    expect($service->isEnabled())->toBeFalse();
});

test('debug capture: simulate creates event with source=simulation', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventDebugCaptureService($cache, $config);

    $event = $service->simulate('test_event', ['key' => 'value'], 'client-123', 'user-456');

    expect($event)->toBeInstanceOf(AnalyticsEvent::class);
    expect($event->name)->toBe('test_event');
    expect($event->params)->toBe(['key' => 'value']);
    expect($event->clientId)->toBe('client-123');
    expect($event->userId)->toBe('user-456');
    expect($event->source)->toBe('simulation');
});

test('debug capture: capture returns null when disabled', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventDebugCaptureService($cache, $config);

    $event = new AnalyticsEvent('page_view', ['path' => '/test']);
    $captureId = $service->capture($event);

    expect($captureId)->toBeNull();
});

test('debug capture: stats returns correct structure', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventDebugCaptureService($cache, $config);

    $stats = $service->stats();
    expect($stats)->toHaveKey('enabled');
    expect($stats)->toHaveKey('captured_count');
    expect($stats)->toHaveKey('max_events');
    expect($stats)->toHaveKey('capture_ttl');
    expect($stats)->toHaveKey('observers');
});

// ─── Config Expansion ───────────────────────────────────────────────────

test('config: cdp section exists with correct keys', function (): void {
    $config = include __DIR__ . '/../config/zeroboiler.php';
    $analytics = $config['analytics'];

    expect($analytics)->toHaveKey('cdp');
    expect($analytics['cdp'])->toHaveKey('enabled');
    expect($analytics['cdp'])->toHaveKey('debug');
    expect($analytics['cdp'])->toHaveKey('cache_prefix');
    expect($analytics['cdp'])->toHaveKey('profile_ttl');
    expect($analytics['cdp'])->toHaveKey('max_recent_events');
});

test('config: computed_traits section exists with correct keys', function (): void {
    $config = include __DIR__ . '/../config/zeroboiler.php';
    $analytics = $config['analytics'];

    expect($analytics)->toHaveKey('computed_traits');
    expect($analytics['computed_traits'])->toHaveKey('enabled');
    expect($analytics['computed_traits'])->toHaveKey('debug');
    expect($analytics['computed_traits'])->toHaveKey('cache_prefix');
    expect($analytics['computed_traits'])->toHaveKey('cache_ttl');
    expect($analytics['computed_traits'])->toHaveKey('rules');
});

test('config: privacy_report section exists with correct keys', function (): void {
    $config = include __DIR__ . '/../config/zeroboiler.php';
    $analytics = $config['analytics'];

    expect($analytics)->toHaveKey('privacy_report');
    expect($analytics['privacy_report'])->toHaveKey('enabled');
    expect($analytics['privacy_report'])->toHaveKey('cache_prefix');
    expect($analytics['privacy_report'])->toHaveKey('report_ttl');
    expect($analytics['privacy_report'])->toHaveKey('organization_name');
    expect($analytics['privacy_report'])->toHaveKey('dpo_contact');
    expect($analytics['privacy_report'])->toHaveKey('jurisdiction');
});

test('config: debug_capture section exists with correct keys', function (): void {
    $config = include __DIR__ . '/../config/zeroboiler.php';
    $analytics = $config['analytics'];

    expect($analytics)->toHaveKey('debug_capture');
    expect($analytics['debug_capture'])->toHaveKey('enabled');
    expect($analytics['debug_capture'])->toHaveKey('debug');
    expect($analytics['debug_capture'])->toHaveKey('cache_prefix');
    expect($analytics['debug_capture'])->toHaveKey('capture_ttl');
    expect($analytics['debug_capture'])->toHaveKey('max_events');
});

// ─── ServiceProvider Registration ────────────────────────────────────────

test('service provider: v29.0.0 singleton registrations exist', function (): void {
    $providerSource = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

    expect($providerSource)->toContain('CustomerProfileUnificationService::class');
    expect($providerSource)->toContain('ComputedTraitsService::class');
    expect($providerSource)->toContain('PrivacyReportGeneratorService::class');
    expect($providerSource)->toContain('EventDebugCaptureService::class');
    expect($providerSource)->toContain('v29.0.0 — Customer Profile Unification Service');
    expect($providerSource)->toContain('v29.0.0 — Computed Traits Engine');
    expect($providerSource)->toContain('v29.0.0 — Privacy Report Generator');
    expect($providerSource)->toContain('v29.0.0 — Event Debug Capture Service');
});

// ─── File Integrity ────────────────────────────────────────────────────

test('v29.0.0: new service files exist', function (): void {
    expect(file_exists(__DIR__ . '/../src/Services/CustomerProfileUnificationService.php'))->toBeTrue();
    expect(file_exists(__DIR__ . '/../src/Services/ComputedTraitsService.php'))->toBeTrue();
    expect(file_exists(__DIR__ . '/../src/Services/PrivacyReportGeneratorService.php'))->toBeTrue();
    expect(file_exists(__DIR__ . '/../src/Services/EventDebugCaptureService.php'))->toBeTrue();
});

test('v29.0.0: new service files declare strict types', function (): void {
    $files = [
        'CustomerProfileUnificationService.php',
        'ComputedTraitsService.php',
        'PrivacyReportGeneratorService.php',
        'EventDebugCaptureService.php',
    ];

    foreach ($files as $file) {
        $content = file_get_contents(__DIR__ . '/../src/Services/' . $file);
        expect($content)->toContain('declare(strict_types=1)');
    }
});

test('v29.0.0: AnalyticsIntegrityCommand expected version is 29.0.0', function (): void {
    $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsIntegrityCommand.php');
    expect($content)->toContain("'29.0.0'");
    expect($content)->not->toContain("'28.0.0'");
});

test('v29.0.0: no 28.0.0 references remain in key files', function (): void {
    $keyFiles = [
        'composer.json',
        'package.json',
        'src/DTO/AnalyticsEvent.php',
    ];

    foreach ($keyFiles as $file) {
        $content = file_get_contents(__DIR__ . '/../' . $file);
        expect($content)->not->toContain('28.0.0');
    }

    // JS files
    $jsFiles = [
        'resources/js/analytics.js',
        'resources/js/useAnalytics.svelte.js',
        'resources/js/usePerformanceTracker.svelte.js',
        'resources/js/useAnalyticsConfig.svelte.js',
    ];

    foreach ($jsFiles as $file) {
        $content = file_get_contents(__DIR__ . '/../' . $file);
        expect($content)->not->toContain('28.0.0');
    }
});
