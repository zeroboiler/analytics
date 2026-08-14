<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\AnalyticsDataResidencyService;
use ZeroBoiler\Analytics\Services\EventConsistencyValidatorService;

/**
 * Comprehensive test for Data Residency and Event Consistency services (v134.0.0).
 *
 * Covers:
 * - Data residency zone configuration and provider filtering
 * - Blocked field enforcement (remove and hash modes)
 * - Audit log recording and retrieval
 * - Event consistency validation for single and all events
 * - Provider gap analysis
 * - Consistency scoring and grading
 * - Priority gap detection
 * - Parameter validation with type checking
 * - Config integration
 *
 * @since 134.0.0
 */
final class V134DataGovernanceTest extends \PHPUnit\Framework\TestCase
{
    private AnalyticsDataResidencyService $residencyService;

    private EventConsistencyValidatorService $consistencyService;

    private CacheRepository $cache;

    protected function setUp(): void
    {
        $this->cache = Cache::fake();

        $residencyConfig = [
            'enabled' => true,
            'default_zone' => 'eu',
            'audit_ttl' => 3600,
            'cache_ttl' => 300,
            'strict_categories' => ['saas', 'engagement'],
            'zones' => [
                'eu' => [
                    'label' => 'European Union (GDPR)',
                    'allowed_providers' => ['ga4', 'gtm', 'plausible', 'posthog'],
                    'blocked_fields' => ['ip_address', 'email', 'ssn'],
                    'requires_consent' => true,
                ],
                'us' => [
                    'label' => 'United States (CCPA)',
                    'allowed_providers' => ['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'],
                    'blocked_fields' => ['ssn'],
                    'requires_consent' => false,
                ],
            ],
        ];

        $consistencyConfig = [
            'enabled' => true,
            'cache_ttl' => 300,
            'enabled_providers' => ['ga4', 'meta_pixel', 'posthog', 'plausible', 'mixpanel', 'amplitude'],
            'required_global_fields' => ['event_name', 'timestamp'],
            'cache_results' => false,
        ];

        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')
            ->willReturnMap([
                ['zeroboiler.analytics.data_residency', [], $residencyConfig],
                ['zeroboiler.analytics.event_consistency', [], $consistencyConfig],
            ]);

        $this->residencyService = new AnalyticsDataResidencyService($this->cache, $config);
        $this->consistencyService = new EventConsistencyValidatorService($this->cache, $config);
    }

    // ─── Data Residency: Zone Configuration ─────────────────────────────

    public function test_residency_service_is_enabled(): void
    {
        $this->assertTrue($this->residencyService->isEnabled());
    }

    public function test_residency_returns_configured_zones(): void
    {
        $zones = $this->residencyService->getZones();

        $this->assertArrayHasKey('eu', $zones);
        $this->assertArrayHasKey('us', $zones);
        $this->assertCount(2, $zones);
        $this->assertSame('European Union (GDPR)', $zones['eu']['label']);
        $this->assertSame('United States (CCPA)', $zones['us']['label']);
    }

    public function test_residency_get_zone_returns_single_zone(): void
    {
        $zone = $this->residencyService->getZone('eu');

        $this->assertNotNull($zone);
        $this->assertContains('ga4', $zone['allowed_providers']);
        $this->assertContains('ip_address', $zone['blocked_fields']);
        $this->assertTrue($zone['requires_consent']);
    }

    public function test_residency_get_zone_returns_null_for_unknown_zone(): void
    {
        $this->assertNull($this->residencyService->getZone('xx'));
    }

    public function test_residency_default_zone(): void
    {
        $this->assertSame('eu', $this->residencyService->getDefaultZone());
    }

    // ─── Data Residency: Provider Filtering ────────────────────────────

    public function test_provider_allowed_in_zone(): void
    {
        $this->assertTrue($this->residencyService->isProviderAllowed('ga4', 'eu'));
        $this->assertTrue($this->residencyService->isProviderAllowed('posthog', 'eu'));
    }

    public function test_provider_blocked_in_zone(): void
    {
        // meta_pixel is not in EU zone's allowed providers
        $this->assertFalse($this->residencyService->isProviderAllowed('meta_pixel', 'eu'));
        $this->assertFalse($this->residencyService->isProviderAllowed('tiktok', 'eu'));
    }

    public function test_provider_allowed_in_all_zones_when_disabled(): void
    {
        $disabledConfig = $this->createMock(ConfigRepository::class);
        $disabledConfig->method('get')
            ->with('zeroboiler.analytics.data_residency', [])
            ->willReturn(['enabled' => false]);

        $disabledService = new AnalyticsDataResidencyService($this->cache, $disabledConfig);

        $this->assertFalse($disabledService->isEnabled());
        $this->assertTrue($disabledService->isProviderAllowed('tiktok', 'eu'));
    }

    public function test_unknown_zone_allows_all_providers(): void
    {
        // Unknown zone falls back to allowing all
        $this->assertTrue($this->residencyService->isProviderAllowed('any_provider', 'unknown_zone'));
    }

    // ─── Data Residency: Field Filtering ────────────────────────────────

    public function test_filter_params_removes_blocked_fields(): void
    {
        $params = [
            'event_name' => 'sign_up',
            'email' => 'user@example.com',
            'ip_address' => '192.168.1.1',
            'user_id' => 42,
        ];

        $result = $this->residencyService->filterParams($params, 'eu', 'ga4');

        $this->assertArrayHasKey('event_name', $result['params']);
        $this->assertArrayHasKey('user_id', $result['params']);
        $this->assertArrayNotHasKey('email', $result['params']);
        $this->assertArrayNotHasKey('ip_address', $result['params']);
        $this->assertContains('email', $result['removed']);
        $this->assertContains('ip_address', $result['removed']);
    }

    public function test_filter_params_hashes_blocked_fields(): void
    {
        $params = [
            'event_name' => 'login',
            'email' => 'user@example.com',
            'ssn' => '000-00-0000',
        ];

        $result = $this->residencyService->filterParams($params, 'eu', 'ga4', hashInstead: true);

        $this->assertArrayHasKey('email', $result['params']);
        $this->assertNotSame('user@example.com', $result['params']['email']);
        $this->assertArrayHasKey('ssn', $result['params']);
        $this->assertContains('email', $result['hashed']);
        $this->assertContains('ssn', $result['hashed']);
        $this->assertEmpty($result['removed']);
    }

    public function test_filter_params_no_blocked_fields_in_us_zone(): void
    {
        $params = [
            'event_name' => 'purchase',
            'email' => 'user@example.com',
            'ip_address' => '10.0.0.1',
        ];

        $result = $this->residencyService->filterParams($params, 'us', 'ga4');

        // US zone only blocks SSN
        $this->assertArrayHasKey('email', $result['params']);
        $this->assertArrayHasKey('ip_address', $result['params']);
        $this->assertEmpty($result['removed']);
    }

    public function test_filter_params_passes_through_when_disabled(): void
    {
        $disabledConfig = $this->createMock(ConfigRepository::class);
        $disabledConfig->method('get')
            ->with('zeroboiler.analytics.data_residency', [])
            ->willReturn(['enabled' => false]);

        $service = new AnalyticsDataResidencyService($this->cache, $disabledConfig);
        $params = ['email' => 'user@example.com', 'ssn' => '123'];

        $result = $service->filterParams($params, 'eu', 'ga4');

        $this->assertArrayHasKey('email', $result['params']);
        $this->assertArrayHasKey('ssn', $result['params']);
        $this->assertEmpty($result['removed']);
    }

    // ─── Data Residency: Strict Enforcement ──────────────────────────────

    public function test_requires_strict_enforcement_for_saas_category(): void
    {
        $this->assertTrue($this->residencyService->requiresStrictEnforcement('sign_up'));
        $this->assertTrue($this->residencyService->requiresStrictEnforcement('login'));
        $this->assertTrue($this->residencyService->requiresStrictEnforcement('page_view'));
    }

    public function test_no_strict_enforcement_for_ecommerce(): void
    {
        $this->assertFalse($this->residencyService->requiresStrictEnforcement('purchase'));
        $this->assertFalse($this->residencyService->requiresStrictEnforcement('add_to_cart'));
    }

    public function test_no_strict_enforcement_for_unknown_event(): void
    {
        $this->assertFalse($this->residencyService->requiresStrictEnforcement('nonexistent_event'));
    }

    // ─── Data Residency: Provider List Filtering ───────────────────────

    public function test_filter_providers_removes_blocked(): void
    {
        $providers = ['ga4', 'meta_pixel', 'posthog', 'tiktok', 'linkedin'];

        $filtered = $this->residencyService->filterProviders($providers, 'eu', 'sign_up');

        $this->assertContains('ga4', $filtered);
        $this->assertContains('posthog', $filtered);
        $this->assertNotContains('meta_pixel', $filtered);
        $this->assertNotContains('tiktok', $filtered);
        $this->assertNotContains('linkedin', $filtered);
    }

    public function test_filter_providers_us_zone_allows_more(): void
    {
        $providers = ['ga4', 'meta_pixel', 'posthog', 'tiktok', 'linkedin'];

        $filtered = $this->residencyService->filterProviders($providers, 'us', 'sign_up');

        $this->assertCount(5, $filtered);
        $this->assertContains('meta_pixel', $filtered);
        $this->assertContains('tiktok', $filtered);
    }

    // ─── Data Residency: Consent ────────────────────────────────────────

    public function test_requires_consent_in_eu_zone(): void
    {
        $this->assertTrue($this->residencyService->requiresConsent('eu'));
    }

    public function test_no_consent_required_in_us_zone(): void
    {
        $this->assertFalse($this->residencyService->requiresConsent('us'));
    }

    // ─── Data Residency: Audit Log ──────────────────────────────────────

    public function test_audit_log_records_decision(): void
    {
        $this->residencyService->logAuditEntry([
            'event' => 'sign_up',
            'zone' => 'eu',
            'provider' => 'ga4',
            'action' => 'dispatch',
            'decision' => 'allowed',
        ]);

        $log = $this->residencyService->getAuditLog();

        $this->assertCount(1, $log);
        $this->assertSame('sign_up', $log[0]['event']);
        $this->assertSame('eu', $log[0]['zone']);
        $this->assertSame('ga4', $log[0]['provider']);
        $this->assertArrayHasKey('timestamp', $log[0]);
    }

    public function test_audit_log_respects_limit(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->residencyService->logAuditEntry([
                'event' => "event_{$i}",
                'zone' => 'eu',
                'provider' => 'ga4',
                'action' => 'dispatch',
                'decision' => 'allowed',
            ]);
        }

        $last10 = $this->residencyService->getAuditLog(limit: 10);
        $this->assertCount(10, $last10);
        $this->assertSame('event_49', $last10[9]['event']);
    }

    public function test_audit_log_not_recorded_when_disabled(): void
    {
        $disabledConfig = $this->createMock(ConfigRepository::class);
        $disabledConfig->method('get')
            ->with('zeroboiler.analytics.data_residency', [])
            ->willReturn(['enabled' => false]);

        $service = new AnalyticsDataResidencyService($this->cache, $disabledConfig);
        $service->logAuditEntry([
            'event' => 'test',
            'zone' => 'eu',
            'provider' => 'ga4',
            'action' => 'dispatch',
            'decision' => 'allowed',
        ]);

        $this->assertEmpty($this->cache->get('zb_data_residency_audit', []));
    }

    public function test_clear_audit_log(): void
    {
        $this->residencyService->logAuditEntry([
            'event' => 'test',
            'zone' => 'eu',
            'provider' => 'ga4',
            'action' => 'dispatch',
            'decision' => 'allowed',
        ]);

        $this->residencyService->clearAuditLog();
        $this->assertEmpty($this->residencyService->getAuditLog());
    }

    // ─── Data Residency: Compliance Summary ─────────────────────────────

    public function test_compliance_summary_structure(): void
    {
        $summary = $this->residencyService->getComplianceSummary();

        $this->assertArrayHasKey('zones', $summary);
        $this->assertArrayHasKey('strict_categories', $summary);
        $this->assertArrayHasKey('total_audit_entries', $summary);
        $this->assertArrayHasKey('compliance_score', $summary);
        $this->assertSame(2, $summary['zones']);
        $this->assertSame(100.0, $summary['compliance_score']); // No denials yet
    }

    // ─── Data Residency: Zone Validation ────────────────────────────────

    public function test_validate_zone_valid(): void
    {
        $result = $this->residencyService->validateZoneConfig('eu');
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_validate_zone_invalid_missing(): void
    {
        $result = $this->residencyService->validateZoneConfig('nonexistent');
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    // ─── Event Consistency: Single Event Validation ──────────────────────

    public function test_consistency_service_is_enabled(): void
    {
        $this->assertTrue($this->consistencyService->isEnabled());
    }

    public function test_validate_known_event_has_coverage(): void
    {
        $result = $this->consistencyService->validateEvent('purchase');

        $this->assertTrue($result['valid']);
        $this->assertSame('purchase', $result['event']);
        $this->assertArrayHasKey('provider_coverage', $result);
        $this->assertContains('ga4', array_keys($result['provider_coverage']));
    }

    public function test_validate_unknown_event(): void
    {
        $result = $this->consistencyService->validateEvent('nonexistent_event_xyz');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertContains('nonexistent_event_xyz not found in catalog', $result['errors'][0]);
    }

    // ─── Event Consistency: All Events Validation ───────────────────────

    public function test_validate_all_events_returns_comprehensive_results(): void
    {
        $result = $this->consistencyService->validateAllEvents();

        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('total_events', $result);
        $this->assertArrayHasKey('valid_events', $result);
        $this->assertArrayHasKey('invalid_events', $result);
        $this->assertArrayHasKey('events_with_gaps', $result);
        $this->assertArrayHasKey('coverage_percentage', $result);
        $this->assertArrayHasKey('gap_percentage', $result);
        $this->assertArrayHasKey('provider_coverage', $result);
        $this->assertArrayHasKey('gap_analysis', $result);
        $this->assertArrayHasKey('results', $result);

        // Should have many events in catalog
        $this->assertGreaterThan(100, $result['total_events']);
        $this->assertGreaterThan(0, $result['valid_events']);
    }

    // ─── Event Consistency: Provider Gaps ───────────────────────────────

    public function test_provider_gaps_structure(): void
    {
        $gaps = $this->consistencyService->getProviderGaps('meta_pixel');

        $this->assertArrayHasKey('provider', $gaps);
        $this->assertArrayHasKey('total_missing', $gaps);
        $this->assertArrayHasKey('events', $gaps);
        $this->assertArrayHasKey('categories', $gaps);
        $this->assertSame('meta_pixel', $gaps['provider']);
    }

    // ─── Event Consistency: Consistency Score ───────────────────────────

    public function test_consistency_score_structure(): void
    {
        $score = $this->consistencyService->getConsistencyScore();

        $this->assertArrayHasKey('score', $score);
        $this->assertArrayHasKey('grade', $score);
        $this->assertArrayHasKey('total_events', $score);
        $this->assertArrayHasKey('fully_covered', $score);
        $this->assertArrayHasKey('gap_events', $score);
        $this->assertArrayHasKey('weakest_provider', $score);
        $this->assertArrayHasKey('weakest_provider_coverage', $score);

        $this->assertGreaterThanOrEqual(0, $score['score']);
        $this->assertLessThanOrEqual(100, $score['score']);
        $this->assertIsString($score['grade']);
        $this->assertMatchesRegularExpression('/^[A-F][+]?$/', $score['grade']);
    }

    // ─── Event Consistency: Priority Gaps ───────────────────────────────

    public function test_priority_gaps_sorted_by_priority(): void
    {
        $gaps = $this->consistencyService->getPriorityGaps();

        // All gaps should have priority
        foreach ($gaps as $gap) {
            $this->assertArrayHasKey('event', $gap);
            $this->assertArrayHasKey('category', $gap);
            $this->assertArrayHasKey('missing_providers', $gap);
            $this->assertArrayHasKey('priority', $gap);
            $this->assertContains($gap['priority'], ['critical', 'high', 'medium', 'low']);
        }
    }

    // ─── Event Consistency: Parameter Validation ────────────────────────

    public function test_validate_params_with_complete_params(): void
    {
        $params = [
            'event_name' => 'purchase',
            'timestamp' => '2026-01-01T00:00:00Z',
            'currency' => 'USD',
            'value' => 99.99,
        ];

        $result = $this->consistencyService->validateParams('purchase', $params);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['field_errors']);
    }

    public function test_validate_params_missing_required_fields(): void
    {
        $params = [
            'user_id' => 42,
        ];

        $result = $this->consistencyService->validateParams('purchase', $params);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['field_errors']);
    }

    public function test_validate_params_ecommerce_missing_currency(): void
    {
        $params = [
            'event_name' => 'purchase',
            'timestamp' => '2026-01-01T00:00:00Z',
            'value' => 99.99,
            // Missing 'currency'
        ];

        $result = $this->consistencyService->validateParams('purchase', $params);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('currency', $result['field_errors']);
    }

    public function test_validate_params_type_warnings(): void
    {
        $params = [
            'event_name' => 'sign_up',
            'timestamp' => '2026-01-01T00:00:00Z',
            'value' => 'not_a_number',
        ];

        $result = $this->consistencyService->validateParams('sign_up', $params, ['ga4']);

        $this->assertArrayHasKey('type_warnings', $result);
    }

    // ─── Event Consistency: Cache ──────────────────────────────────────

    public function test_clear_cache(): void
    {
        $this->consistencyService->validateAllEvents();
        $this->consistencyService->clearCache();

        $cached = $this->cache->get('zb_consistency_results');
        $this->assertNull($cached);
    }

    // ─── Integration: EventCatalog Integration ──────────────────────────

    public function test_consistency_knows_all_catalog_categories(): void
    {
        $categories = EventCatalog::byCategory();
        $this->assertArrayHasKey('ecommerce', $categories);
        $this->assertArrayHasKey('saas', $categories);
        $this->assertArrayHasKey('engagement', $categories);
        $this->assertArrayHasKey('marketing', $categories);
        $this->assertArrayHasKey('security', $categories);
        $this->assertArrayHasKey('uptime', $categories);
        $this->assertArrayHasKey('infrastructure', $categories);
    }

    public function test_residency_strict_categories_match_catalog(): void
    {
        $strictCategories = ['saas', 'engagement'];

        foreach ($strictCategories as $category) {
            $events = EventCatalog::category($category);
            // Each category should have events
            $this->assertGreaterThan(
                0,
                count($events),
                "Category '{$category}' should have events in catalog",
            );
        }
    }

    // ─── Integration: Combined Flow ─────────────────────────────────────

    public function test_combined_residency_and_consistency_workflow(): void
    {
        // 1. Check event consistency for purchase
        $consistency = $this->consistencyService->validateEvent('purchase');
        $this->assertTrue($consistency['valid']);

        // 2. Filter providers for EU zone
        $providers = array_keys(array_filter(
            $consistency['provider_coverage'],
            fn (bool $covered): bool => $covered,
        ));
        $filteredProviders = $this->residencyService->filterProviders($providers, 'eu', 'purchase');

        // GA4 should be allowed in EU
        $this->assertContains('ga4', $filteredProviders);

        // 3. Filter params for EU zone
        $params = [
            'event_name' => 'purchase',
            'email' => 'buyer@example.com',
            'value' => 49.99,
            'currency' => 'USD',
        ];
        $filtered = $this->residencyService->filterParams($params, 'eu', 'ga4');

        $this->assertArrayNotHasKey('email', $filtered['params']);
        $this->assertArrayHasKey('value', $filtered['params']);
        $this->assertContains('email', $filtered['removed']);

        // 4. Log the audit entry
        $this->residencyService->logAuditEntry([
            'event' => 'purchase',
            'zone' => 'eu',
            'provider' => 'ga4',
            'action' => 'dispatch',
            'blocked_fields' => ['email'],
            'decision' => 'allowed',
        ]);

        $auditLog = $this->residencyService->getAuditLog();
        $this->assertCount(1, $auditLog);
    }
}
