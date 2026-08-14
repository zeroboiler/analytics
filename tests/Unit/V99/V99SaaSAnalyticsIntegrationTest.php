<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests\Unit\V99;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;

/**
 * Integration test for v99.0.0 SaaS analytics upgrades.
 *
 * Validates config structure, lifecycle mapping constant,
 * ecommerce format converter completeness, and Inertia prop schema.
 *
 * @since 99.0.0
 * @covers \ZeroBoiler\Analytics\Services\LifecycleEventMapper
 * @covers \ZeroBoiler\Analytics\Events\EventCatalog
 * @covers \ZeroBoiler\Analytics\Support\EcommerceFormatConverter
 */
final class V99SaaSAnalyticsIntegrationTest extends TestCase
{
    // ── Lifecycle Event Mapper ─────────────────────────────────────────

    /**
     * Verify the DEFAULT_MAPPING_COUNT constant is positive and reasonable.
     */
    public function testLifecycleMappingCountConstantIsPositive(): void
    {
        $this->assertGreaterThan(0, LifecycleEventMapper::DEFAULT_MAPPING_COUNT);
        $this->assertLessThan(200, LifecycleEventMapper::DEFAULT_MAPPING_COUNT, 'Mapping count unexpectedly high');
    }

    /**
     * Verify the DEFAULT_MAPPINGS constant is defined and is an array.
     */
    public function testDefaultMappingsConstantIsArray(): void
    {
        $ref = new \ReflectionClass(LifecycleEventMapper::class);
        $this->assertTrue($ref->hasConstant('DEFAULT_MAPPINGS'));

        $mappings = $ref->getConstant('DEFAULT_MAPPINGS');
        $this->assertIsArray($mappings);
        $this->assertNotEmpty($mappings, 'DEFAULT_MAPPINGS should not be empty');
    }

    /**
     * Verify each mapping has required keys: source, target.
     */
    public function testAllMappingsHaveRequiredKeys(): void
    {
        $ref = new \ReflectionClass(LifecycleEventMapper::class);
        $mappings = $ref->getConstant('DEFAULT_MAPPINGS');

        foreach ($mappings as $key => $mapping) {
            $this->assertArrayHasKey('source', $mapping, "Mapping '{$key}' is missing 'source' key");
            $this->assertArrayHasKey('target', $mapping, "Mapping '{$key}' is missing 'target' key");
            $this->assertIsString($mapping['source'], "Mapping '{$key}' source must be a string");
            $this->assertIsString($mapping['target'], "Mapping '{$key}' target must be a string");
            $this->assertNotEmpty($mapping['source'], "Mapping '{$key}' source must not be empty");
            $this->assertNotEmpty($mapping['target'], "Mapping '{$key}' target must not be empty");
        }
    }

    /**
     * Verify all built-in Laravel auth events have mappings.
     */
    public function testBuiltInAuthEventsAreMapped(): void
    {
        $ref = new \ReflectionClass(LifecycleEventMapper::class);
        $mappings = $ref->getConstant('DEFAULT_MAPPINGS');

        $requiredAuthMappings = [
            'auth.login',
            'auth.register',
            'auth.logout',
        ];

        foreach ($requiredAuthMappings as $key) {
            $this->assertArrayHasKey($key, $mappings, "Built-in auth mapping '{$key}' is missing");
        }
    }

    /**
     * Verify SaaS lifecycle events have mappings.
     */
    public function testSaaSLifecycleEventsAreMapped(): void
    {
        $ref = new \ReflectionClass(LifecycleEventMapper::class);
        $mappings = $ref->getConstant('DEFAULT_MAPPINGS');

        $requiredSaaSKeys = [
            'saas.trial_start',
            'saas.trial_end',
            'saas.subscription_created',
            'saas.plan_upgrade',
            'saas.plan_downgrade',
            'saas.cancellation',
        ];

        foreach ($requiredSaaSKeys as $key) {
            $this->assertArrayHasKey($key, $mappings, "SaaS lifecycle mapping '{$key}' is missing");
        }
    }

    /**
     * Verify engagement events have mappings.
     */
    public function testEngagementEventsAreMapped(): void
    {
        $ref = new \ReflectionClass(LifecycleEventMapper::class);
        $mappings = $ref->getConstant('DEFAULT_MAPPINGS');

        $requiredEngagementKeys = [
            'engagement.search',
            'engagement.form_submit',
            'engagement.consent_granted',
            'engagement.consent_withdrawn',
            'engagement.error',
        ];

        foreach ($requiredEngagementKeys as $key) {
            $this->assertArrayHasKey($key, $mappings, "Engagement mapping '{$key}' is missing");
        }
    }

    // ── Event Catalog ───────────────────────────────────────────────────

    /**
     * Verify event catalog has all required categories.
     */
    public function testEventCatalogHasRequiredCategories(): void
    {
        $byCategory = EventCatalog::byCategory();

        $requiredCategories = ['ecommerce', 'saas', 'engagement'];

        foreach ($requiredCategories as $category) {
            $this->assertArrayHasKey($category, $byCategory, "Event catalog is missing category '{$category}'");
            $this->assertGreaterThan(0, count($byCategory[$category]), "Category '{$category}' has no events");
        }
    }

    /**
     * Verify event catalog has cross-provider coverage for purchase.
     */
    public function testPurchaseEventHasCrossProviderCoverage(): void
    {
        $ga4Names = EventCatalog::allGa4Names();
        $metaNames = EventCatalog::allMetaNames();
        $posthogNames = EventCatalog::allPosthogNames();

        $this->assertContains('purchase', $ga4Names, 'GA4 catalog missing purchase event');
        $this->assertContains('Purchase', $metaNames, 'Meta catalog missing Purchase event');
    }

    /**
     * Verify event catalog is non-empty and has reasonable size.
     */
    public function testEventCatalogIsComprehensive(): void
    {
        $totalCount = EventCatalog::count();

        $this->assertGreaterThan(50, $totalCount, 'Event catalog should have at least 50 events');
        $this->assertLessThan(1000, $totalCount, 'Event catalog has unexpectedly many events');
    }

    // ── Ecommerce Format Converter ─────────────────────────────────────

    /**
     * Verify universal converter supports all required providers.
     */
    public function testUniversalConverterSupportsAllProviders(): void
    {
        $converterClass = \ZeroBoiler\Analytics\Support\EcommerceFormatConverter::class;
        $ref = new \ReflectionClass($converterClass);

        // Verify toGa4Format method exists
        $this->assertTrue($ref->hasMethod('toGa4Format'));

        // Verify ga4ToMetaAuto exists
        $this->assertTrue($ref->hasMethod('ga4ToMetaAuto'));

        // Verify ga4ToPlausibleAuto exists
        $this->assertTrue($ref->hasMethod('ga4ToPlausibleAuto'));

        // Verify ga4ToMixpanelPurchase exists
        $this->assertTrue($ref->hasMethod('ga4ToMixpanelPurchase'));

        // Verify ga4ToAmplitudePurchase exists
        $this->assertTrue($ref->hasMethod('ga4ToAmplitudePurchase'));

        // Verify ga4ToTiktokPurchase exists
        $this->assertTrue($ref->hasMethod('ga4ToTiktokPurchase'));

        // Verify ga4ToLinkedinPurchase exists
        $this->assertTrue($ref->hasMethod('ga4ToLinkedinPurchase'));

        // Verify buildForAllProviders exists
        $this->assertTrue($ref->hasMethod('buildForAllProviders'));
    }

    /**
     * Verify normalizeGa4Item returns required fields.
     */
    public function testNormalizeGa4ItemReturnsRequiredFields(): void
    {
        $result = \ZeroBoiler\Analytics\Support\EcommerceFormatConverter::normalizeGa4Item([
            'id' => 'SKU-001',
            'name' => 'Test Product',
        ]);

        $this->assertArrayHasKey('item_id', $result);
        $this->assertSame('SKU-001', $result['item_id']);
        $this->assertArrayHasKey('price', $result);
        $this->assertSame(0.0, $result['price']);
        $this->assertArrayHasKey('quantity', $result);
        $this->assertSame(1, $result['quantity']);
    }

    /**
     * Verify calculateTotalValue computes correctly.
     */
    public function testCalculateTotalValueIsCorrect(): void
    {
        $items = [
            ['price' => 10.0, 'quantity' => 2],
            ['price' => 5.50, 'quantity' => 1],
        ];

        $total = \ZeroBoiler\Analytics\Support\EcommerceFormatConverter::calculateTotalValue($items);

        $this->assertEquals(25.5, $total);
    }

    // ── Config Structure Validation ────────────────────────────────────

    /**
     * Verify the published config file exists and has required sections.
     */
    public function testConfigFileExistsAndHasRequiredSections(): void
    {
        $configPath = dirname(__DIR__, 3) . '/config/zeroboiler.php';
        $this->assertFileExists($configPath, 'config/zeroboiler.php should exist');

        $content = file_get_contents($configPath);
        $this->assertIsString($content);
        $this->assertNotEmpty($content);

        // Verify new config sections exist
        $this->assertStringContainsString("'lifecycle' =>", $content, 'Config missing lifecycle section');
        $this->assertStringContainsString("'api' =>", $content, 'Config missing api section');
        $this->assertStringContainsString("'client_auto_track' =>", $content, 'Config missing client_auto_track section');
        $this->assertStringContainsString("'queue' =>", $content, 'Config missing queue section');
    }

    // ── Tracker Interface Compliance ────────────────────────────────────

    /**
     * Verify all tracker classes implement TrackerInterface.
     */
    public function testAllTrackersImplementInterface(): void
    {
        $trackers = [
            \ZeroBoiler\Analytics\Trackers\PlausibleTracker::class,
            \ZeroBoiler\Analytics\Trackers\PosthogTracker::class,
        ];

        $interface = \ZeroBoiler\Analytics\Trackers\TrackerInterface::class;

        foreach ($trackers as $tracker) {
            $this->assertTrue(
                is_subclass_of($tracker, $interface),
                "{$tracker} must implement {$interface}",
            );
        }
    }

    /**
     * Verify PlausibleTracker has required methods.
     */
    public function testPlausibleTrackerHasRequiredMethods(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Analytics\Trackers\PlausibleTracker::class);

        $requiredMethods = ['track', 'isEnabled', 'headScripts', 'bodyScripts', 'setConsent', 'getConsent'];

        foreach ($requiredMethods as $method) {
            $this->assertTrue($ref->hasMethod($method), "PlausibleTracker missing method: {$method}");
        }
    }

    /**
     * Verify PosthogTracker has identity methods.
     */
    public function testPosthogTrackerHasIdentityMethods(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Analytics\Trackers\PosthogTracker::class);

        $requiredMethods = ['identify', 'alias', 'reset', 'trackWithPerson', 'isFeatureEnabled', 'deletePerson'];

        foreach ($requiredMethods as $method) {
            $this->assertTrue($ref->hasMethod($method), "PosthogTracker missing method: {$method}");
        }
    }

    // ── User Identity Tracker ───────────────────────────────────────────

    /**
     * Verify UserIdentityTracker class exists and has core methods.
     */
    public function testUserIdentityTrackerStructure(): void
    {
        $ref = new \ReflectionClass(\ZeroBoiler\Analytics\Tracking\UserIdentityTracker::class);

        $requiredMethods = ['identify', 'onLogin', 'onRegister', 'onLogout'];

        foreach ($requiredMethods as $method) {
            $this->assertTrue($ref->hasMethod($method), "UserIdentityTracker missing method: {$method}");
        }
    }

    // ── TypeScript Definitions ──────────────────────────────────────────

    /**
     * Verify TypeScript definitions include v99.0.0 types.
     */
    public function testTypeScriptDefinitionsIncludeAutoTrack(): void
    {
        $typesPath = dirname(__DIR__, 3) . '/resources/js/analytics.d.ts';
        $this->assertFileExists($typesPath, 'TypeScript definitions should exist');

        $content = file_get_contents($typesPath);
        $this->assertIsString($content);

        $this->assertStringContainsString('AutoTrackConfig', $content, 'Missing AutoTrackConfig interface');
        $this->assertStringContainsString('autoTrack:', $content, 'Missing autoTrack prop');
        $this->assertStringContainsString('idleTimeout:', $content, 'Missing idleTimeout in AutoTrackConfig');
        $this->assertStringContainsString('errorIgnorePatterns:', $content, 'Missing errorIgnorePatterns in AutoTrackConfig');
    }

    /**
     * Verify TypeScript definitions include all provider types.
     */
    public function testTypeScriptDefinitionsIncludeAllProviders(): void
    {
        $typesPath = dirname(__DIR__, 3) . '/resources/js/analytics.d.ts';
        $content = file_get_contents($typesPath);

        $requiredProviders = [
            'ga4MeasurementId',
            'gtmContainerId',
            'metaPixelId',
            'plausibleDomain',
            'posthogHost',
            'amplitudeApiKey',
            'mixpanelToken',
        ];

        foreach ($requiredProviders as $prop) {
            $this->assertStringContainsString($prop . '?', $content, "Missing {$prop} prop");
        }
    }

    /**
     * Verify TypeScript version is 99.0.0.
     */
    public function testTypeScriptVersionIsCurrent(): void
    {
        $typesPath = dirname(__DIR__, 3) . '/resources/js/analytics.d.ts';
        $content = file_get_contents($typesPath);

        $this->assertStringContainsString('@version 99.0.0', $content, 'TypeScript version should be 99.0.0');
    }
}
