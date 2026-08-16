<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests\Feature\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\SaaSPlatformAuditService;

/**
 * @covers \ZeroBoiler\Analytics\Services\SaaSPlatformAuditService
 */
final class SaaSPlatformAuditServiceTest extends \PHPUnit\Framework\TestCase
{
    private SaaSPlatformAuditService $service;

    protected function setUp(): void
    {
        $this->service = new SaaSPlatformAuditService;
    }

    public function test_service_has_fourteen_audit_categories(): void
    {
        $this->assertSame(14, $this->service->categoryCount());
    }

    public function test_category_names_are_comprehensive(): void
    {
        $names = $this->service->categoryNames();

        $this->assertContains('event_catalog', $names);
        $this->assertContains('providers', $names);
        $this->assertContains('consent_compliance', $names);
        $this->assertContains('identity_resolution', $names);
        $this->assertContains('ecommerce', $names);
        $this->assertContains('saas_lifecycle', $names);
        $this->assertContains('engagement', $names);
        $this->assertContains('pipeline', $names);
        $this->assertContains('api_sdk', $names);
        $this->assertContains('queue', $names);
        $this->assertContains('admin_tooling', $names);
        $this->assertContains('testing_quality', $names);
        $this->assertContains('documentation', $names);
        $this->assertContains('production_readiness', $names);
        $this->assertCount(14, $names);
    }

    public function test_audit_returns_expected_structure(): void
    {
        $result = $this->service->audit();

        $this->assertArrayHasKey('overall_score', $result);
        $this->assertArrayHasKey('grade', $result);
        $this->assertArrayHasKey('categories', $result);
        $this->assertArrayHasKey('issues', $result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('version', $result);
    }

    public function test_audit_overall_score_is_numeric(): void
    {
        $result = $this->service->audit();

        $this->assertIsFloat($result['overall_score']);
        $this->assertGreaterThanOrEqual(0.0, $result['overall_score']);
        $this->assertLessThanOrEqual(100.0, $result['overall_score']);
    }

    public function test_audit_grade_is_valid(): void
    {
        $result = $this->service->audit();

        $validGrades = ['A+', 'A', 'B+', 'B', 'C+', 'C', 'D'];
        $this->assertContains($result['grade'], $validGrades);
    }

    public function test_audit_version_matches_package_version(): void
    {
        $result = $this->service->audit();

        $this->assertSame(AnalyticsEvent::VERSION, $result['version']);
    }

    public function test_audit_timestamp_is_iso8601(): void
    {
        $result = $this->service->audit();

        $parsed = \DateTimeImmutable::createFromFormat('c', $result['timestamp']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $parsed);
    }

    public function test_audit_categories_have_required_keys(): void
    {
        $result = $this->service->audit();

        foreach ($result['categories'] as $name => $category) {
            $this->assertArrayHasKey('score', $category, "Category '{$name}' missing 'score'");
            $this->assertArrayHasKey('max', $category, "Category '{$name}' missing 'max'");
            $this->assertArrayHasKey('checks', $category, "Category '{$name}' missing 'checks'");
            $this->assertIsFloat($category['score']);
            $this->assertIsFloat($category['max']);
            $this->assertIsArray($category['checks']);
            $this->assertGreaterThan(0, $category['max'], "Category '{$name}' has zero max weight");
        }
    }

    public function test_audit_checks_have_required_keys(): void
    {
        $result = $this->service->audit();

        foreach ($result['categories'] as $name => $category) {
            foreach ($category['checks'] as $i => $check) {
                $this->assertArrayHasKey('check', $check, "Check #{$i} in '{$name}' missing 'check'");
                $this->assertArrayHasKey('status', $check, "Check #{$i} in '{$name}' missing 'status'");
                $this->assertArrayHasKey('weight', $check, "Check #{$i} in '{$name}' missing 'weight'");
                $this->assertIsString($check['check']);
                $this->assertIsFloat($check['weight']);
                $this->assertGreaterThan(0, $check['weight'], "Check #{$i} in '{$name}' has zero weight");
                $this->assertContains(
                    $check['status'],
                    ['pass', 'fail', 'warn', 'info'],
                    "Check #{$i} in '{$name}' has invalid status: {$check['status']}",
                );
            }
        }
    }

    public function test_audit_issues_is_array(): void
    {
        $result = $this->service->audit();

        $this->assertIsArray($result['issues']);
    }

    public function test_all_fourteen_categories_present_in_result(): void
    {
        $result = $this->service->audit();

        $expectedCategories = $this->service->categoryNames();
        foreach ($expectedCategories as $cat) {
            $this->assertArrayHasKey($cat, $result['categories'], "Missing category: {$cat}");
        }
        $this->assertCount(14, $result['categories']);
    }

    public function test_event_catalog_category_has_core_saas_checks(): void
    {
        $result = $this->service->audit();
        $checkNames = array_column($result['categories']['event_catalog']['checks'], 'check');

        $this->assertContains('SaaS core event: sign_up', $checkNames);
        $this->assertContains('SaaS core event: login', $checkNames);
        $this->assertContains('SaaS core event: trial_start', $checkNames);
        $this->assertContains('SaaS core event: subscription_created', $checkNames);
        $this->assertContains('SaaS core event: plan_upgrade', $checkNames);
        $this->assertContains('SaaS core event: cancellation', $checkNames);
    }

    public function test_event_catalog_category_has_core_ecommerce_checks(): void
    {
        $result = $this->service->audit();
        $checkNames = array_column($result['categories']['event_catalog']['checks'], 'check');

        $this->assertContains('E-commerce core event: view_item', $checkNames);
        $this->assertContains('E-commerce core event: add_to_cart', $checkNames);
        $this->assertContains('E-commerce core event: purchase', $checkNames);
        $this->assertContains('E-commerce core event: refund', $checkNames);
    }

    public function test_event_catalog_category_has_core_engagement_checks(): void
    {
        $result = $this->service->audit();
        $checkNames = array_column($result['categories']['event_catalog']['checks'], 'check');

        $this->assertContains('Engagement core event: page_view', $checkNames);
        $this->assertContains('Engagement core event: scroll_depth', $checkNames);
        $this->assertContains('Engagement core event: click', $checkNames);
        $this->assertContains('Engagement core event: search', $checkNames);
    }

    public function test_providers_category_checks_multiple_providers(): void
    {
        $result = $this->service->audit();
        $checkNames = array_column($result['categories']['providers']['checks'], 'check');

        $this->assertContains('GA4 configured and enabled', $checkNames);
        $this->assertContains('GTM configured and enabled', $checkNames);
        $this->assertContains('Meta Pixel configured and enabled', $checkNames);
        $this->assertContains('Plausible configured and enabled', $checkNames);
        $this->assertContains('PostHog configured and enabled', $checkNames);
    }

    public function test_consent_category_checks_gdpr_features(): void
    {
        $result = $this->service->audit();
        $checkNames = array_column($result['categories']['consent_compliance']['checks'], 'check');

        $this->assertContains('GDPR consent default configured', $checkNames);
        $this->assertContains('Granular consent purposes defined (necessary required)', $checkNames);
        $this->assertContains('Consent state logging enabled', $checkNames);
    }

    public function test_identity_category_checks_linking(): void
    {
        $result = $this->service->audit();
        $checkNames = array_column($result['categories']['identity_resolution']['checks'], 'check');

        $this->assertContains('Client ID cookie configured', $checkNames);
        $this->assertContains('Auto-link client ID ↔ user ID on auth', $checkNames);
        $this->assertContains('API identity resolution endpoints (/identity/*)', $checkNames);
    }

    public function test_pipeline_category_checks_enrichment(): void
    {
        $result = $this->service->audit();
        $checkNames = array_column($result['categories']['pipeline']['checks'], 'check');

        $this->assertContains('Event Pipeline', $checkNames);
        $this->assertContains('Consent Filter', $checkNames);
        $this->assertContains('Event Deduplication', $checkNames);
        $this->assertContains('Sampling Filter', $checkNames);
    }

    public function test_api_sdk_category_checks_core_endpoints(): void
    {
        $result = $this->service->audit();
        $checkNames = array_column($result['categories']['api_sdk']['checks'], 'check');

        $this->assertContains('API endpoint: POST /api/analytics/events (Single event tracking)', $checkNames);
        $this->assertContains('API endpoint: POST /api/analytics/batch (Batch event tracking)', $checkNames);
        $this->assertContains('API endpoint: POST /api/analytics/identify (User identification)', $checkNames);
        $this->assertContains('API endpoint: POST /api/analytics/consent (Consent update)', $checkNames);
        $this->assertContains('API endpoint: GET /api/analytics/health (Health check)', $checkNames);
    }

    public function test_queue_category_checks_async_features(): void
    {
        $result = $this->service->audit();
        $checkNames = array_column($result['categories']['queue']['checks'], 'check');

        $this->assertContains('Queue-based async dispatch enabled', $checkNames);
        $this->assertContains('Queued Analytics Dispatcher', $checkNames);
        $this->assertContains('Track Analytics Event Job', $checkNames);
    }

    public function test_admin_tooling_checks_essential_commands(): void
    {
        $result = $this->service->audit();
        $checkNames = array_column($result['categories']['admin_tooling']['checks'], 'check');

        $this->assertContains('Command: zb:analytics:overview — Pipeline overview', $checkNames);
        $this->assertContains('Command: zb:analytics:test — Provider test', $checkNames);
        $this->assertContains('Command: zb:analytics:health — Health check', $checkNames);
        $this->assertContains('Command: zb:analytics:readiness — Readiness gate', $checkNames);
    }

    public function test_testing_quality_checks_tooling(): void
    {
        $result = $this->service->audit();
        $checkNames = array_column($result['categories']['testing_quality']['checks'], 'check');

        $this->assertContains('PHPStan configuration (phpstan.neon or phpstan.dist.neon)', $checkNames);
        $this->assertContains('Pest configuration (pest.php)', $checkNames);
        $this->assertContains('CI scripts in composer.json (lint, analyse, test)', $checkNames);
    }

    public function test_production_readiness_checks_service_provider(): void
    {
        $result = $this->service->audit();
        $checkNames = array_column($result['categories']['production_readiness']['checks'], 'check');

        $this->assertContains('AnalyticsServiceProvider (auto-discovery)', $checkNames);
        $this->assertContains('Health check endpoint (GET /api/analytics/health)', $checkNames);
    }

    public function test_quick_audit_returns_expected_structure(): void
    {
        $result = $this->service->quickAudit();

        $this->assertArrayHasKey('overall_score', $result);
        $this->assertArrayHasKey('grade', $result);
        $this->assertArrayHasKey('categories', $result);
        $this->assertIsFloat($result['overall_score']);
        $this->assertIsArray($result['categories']);
        $this->assertCount(14, $result['categories']);
    }

    public function test_quick_audit_category_scores_are_percentages(): void
    {
        $result = $this->service->quickAudit();

        foreach ($result['categories'] as $name => $score) {
            $this->assertIsFloat($score, "Category '{$name}' score is not float");
            $this->assertGreaterThanOrEqual(0.0, $score, "Category '{$name}' score below 0");
            $this->assertLessThanOrEqual(100.0, $score, "Category '{$name}' score above 100");
        }
    }

    public function test_audit_is_idempotent(): void
    {
        $result1 = $this->service->audit();
        $result2 = $this->service->audit();

        // Version and overall structure should match
        $this->assertSame($result1['version'], $result2['version']);
        $this->assertSame($result1['grade'], $result2['grade']);
        $this->assertSame($result1['overall_score'], $result2['overall_score']);
    }

    public function test_audit_scores_match_category_count(): void
    {
        $result = $this->service->audit();

        $totalCatScore = 0.0;
        $totalCatMax = 0.0;

        foreach ($result['categories'] as $category) {
            $totalCatScore += $category['score'];
            $totalCatMax += $category['max'];
        }

        $expectedPct = $totalCatMax > 0 ? round(($totalCatScore / $totalCatMax) * 100, 1) : 0.0;
        $this->assertSame($expectedPct, $result['overall_score']);
    }

    public function test_engagement_category_checks_js_features(): void
    {
        $result = $this->service->audit();
        $checkNames = array_column($result['categories']['engagement']['checks'], 'check');

        $this->assertContains('JS client: Scroll depth tracking', $checkNames);
        $this->assertContains('JS client: Custom event tracking', $checkNames);
        $this->assertContains('JS client: Page view tracking', $checkNames);
        $this->assertContains('JS client: Inertia SPA page view tracking', $checkNames);
    }

    public function test_ecommerce_category_checks_core_services(): void
    {
        $result = $this->service->audit();
        $checkNames = array_column($result['categories']['ecommerce']['checks'], 'check');

        $this->assertContains('E-commerce Events Catalog', $checkNames);
        $this->assertContains('E-commerce Analytics Service', $checkNames);
        $this->assertContains('E-commerce Format Converter', $checkNames);
        $this->assertContains('Google Tag Manager Service', $checkNames);
        $this->assertContains('Meta Pixel Service', $checkNames);
    }

    public function test_saas_lifecycle_category_checks_mapper(): void
    {
        $result = $this->service->audit();
        $checkNames = array_column($result['categories']['saas_lifecycle']['checks'], 'check');

        $this->assertContains('SaaS Events Catalog', $checkNames);
        $this->assertContains('Lifecycle Event Mapper', $checkNames);
        $this->assertContains('Lifecycle Event Subscriber', $checkNames);
        $this->assertContains('Default lifecycle mappings ≥ 50', $checkNames);
    }

    public function test_documentation_category_checks_core_files(): void
    {
        $result = $this->service->audit();
        $checkNames = array_column($result['categories']['documentation']['checks'], 'check');

        $this->assertContains('README.md', $checkNames);
        $this->assertContains('Config file (config/zeroboiler.php)', $checkNames);
        $this->assertContains('Analytics Facade', $checkNames);
    }
}
