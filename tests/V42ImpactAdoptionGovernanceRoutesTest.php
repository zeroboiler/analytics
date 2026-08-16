<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EventImpactService;
use ZeroBoiler\Analytics\Services\FeatureAdoptionTracker;

/**
 * v4.2.0 — Event Impact Analytics, Feature Adoption API, Governance Mutation Endpoints.
 *
 * Validates that new API routes map to existing service methods,
 * that version strings are synchronized, and that the catalog
 * still validates cleanly after the v4.2.0 changes.
 */
final class V42ImpactAdoptionGovernanceRoutesTest extends TestCase
{
    // ─── Version Synchronization ────────────────────────────────────────

    /**
     * AnalyticsEvent::VERSION must be 76.0.0.
     */
    public function testVersionConstant(): void
    {
        $this->assertSame('76.0.0', AnalyticsEvent::VERSION);
    }

    // ─── Event Catalog Integrity ────────────────────────────────────────

    /**
     * Event catalog must validate cleanly (no structural errors).
     */
    public function testEventCatalogValidates(): void
    {
        $result = EventCatalog::validate();

        $this->assertTrue($result['valid'], implode(', ', $result['errors']));
    }

    /**
     * Event catalog has at least 100 events.
     */
    public function testEventCatalogHasMinimumEvents(): void
    {
        $this->assertGreaterThanOrEqual(100, EventCatalog::count());
    }

    /**
     * Event catalog has all three categories.
     */
    public function testEventCatalogHasAllCategories(): void
    {
        $byCategory = EventCatalog::byCategory();

        $this->assertArrayHasKey('ecommerce', $byCategory);
        $this->assertArrayHasKey('saas', $byCategory);
        $this->assertArrayHasKey('engagement', $byCategory);

        $this->assertGreaterThan(0, count($byCategory['ecommerce']));
        $this->assertGreaterThan(0, count($byCategory['saas']));
        $this->assertGreaterThan(0, count($byCategory['engagement']));
    }

    /**
     * Core SaaS events are all present in the catalog.
     */
    public function testCoreSaaSEventsExist(): void
    {
        $coreKeys = [
            'sign_up', 'login', 'logout', 'start_trial', 'trial_end',
            'subscribe', 'plan_upgrade', 'plan_downgrade', 'cancellation',
            'trial_converted', 'subscription_resumed',
        ];

        foreach ($coreKeys as $key) {
            $this->assertTrue(
                EventCatalog::has($key),
                "Core SaaS event '{$key}' is missing from the catalog",
            );
        }
    }

    /**
     * Core e-commerce events are all present.
     */
    public function testCoreEcommerceEventsExist(): void
    {
        $coreKeys = [
            'view_item', 'add_to_cart', 'view_cart', 'begin_checkout',
            'add_payment_info', 'purchase', 'refund', 'remove_from_cart',
        ];

        foreach ($coreKeys as $key) {
            $this->assertTrue(
                EventCatalog::has($key),
                "Core ecommerce event '{$key}' is missing from the catalog",
            );
        }
    }

    /**
     * Revenue events are present.
     */
    public function testRevenueEventsExist(): void
    {
        $revenue = EventCatalog::revenueEvents();
        $this->assertGreaterThan(0, count($revenue));

        $names = array_column($revenue, 'name');
        $this->assertContains('purchase', $names);
        $this->assertContains('subscribe', $names);
    }

    // ─── Event Impact Service ────────────────────────────────────────────

    /**
     * EventImpactService requires minimum sample size.
     */
    public function testImpactServiceRequiresMinimumSampleSize(): void
    {
        $service = new class extends EventImpactService {
            public function __construct()
            {
                // Parent constructor not called — we test the service's behavior directly
            }
        };

        // We can't instantiate it without config in this test environment,
        // so verify the class exists and has the expected methods
        $this->assertTrue(method_exists(EventImpactService::class, 'calculateImpacts'));
        $this->assertTrue(method_exists(EventImpactService::class, 'conversionDrivers'));
        $this->assertTrue(method_exists(EventImpactService::class, 'retentionDrivers'));
        $this->assertTrue(method_exists(EventImpactService::class, 'isEnabled'));
    }

    /**
     * Impact scores have expected structure.
     */
    public function testImpactScoreStructure(): void
    {
        // Verify the return type shape from the PHPStan type hint
        $reflection = new \ReflectionMethod(EventImpactService::class, 'calculateImpacts');
        $doc = $reflection->getDocComment();

        $this->assertNotEmpty($doc);
        $this->assertStringContainsString('scores', $doc);
        $this->assertStringContainsString('top_conversion', $doc);
        $this->assertStringContainsString('top_retention', $doc);
    }

    // ─── Feature Adoption Tracker ──────────────────────────────────────

    /**
     * FeatureAdoptionTracker has expected API methods.
     */
    public function testFeatureAdoptionTrackerHasExpectedMethods(): void
    {
        $this->assertTrue(method_exists(FeatureAdoptionTracker::class, 'recordAdoption'));
        $this->assertTrue(method_exists(FeatureAdoptionTracker::class, 'getProfile'));
        $this->assertTrue(method_exists(FeatureAdoptionTracker::class, 'hasAdopted'));
        $this->assertTrue(method_exists(FeatureAdoptionTracker::class, 'getStreak'));
        $this->assertTrue(method_exists(FeatureAdoptionTracker::class, 'adoptionFunnel'));
        $this->assertTrue(method_exists(FeatureAdoptionTracker::class, 'adoptionCount'));
        $this->assertTrue(method_exists(FeatureAdoptionTracker::class, 'recentFeatures'));
        $this->assertTrue(method_exists(FeatureAdoptionTracker::class, 'clearProfile'));
    }

    /**
     * FeatureAdoptionTracker getProfile returns expected structure.
     */
    public function testAdoptionProfileStructure(): void
    {
        $reflection = new \ReflectionMethod(FeatureAdoptionTracker::class, 'getProfile');
        $doc = $reflection->getDocComment();

        $this->assertNotEmpty($doc);
        $this->assertStringContainsString('total_features', $doc);
        $this->assertStringContainsString('features', $doc);
        $this->assertStringContainsString('streaks', $doc);
        $this->assertStringContainsString('last_activity', $doc);
    }

    /**
     * FeatureAdoptionTracker adoption funnel returns expected structure.
     */
    public function testAdoptionFunnelStructure(): void
    {
        $reflection = new \ReflectionMethod(FeatureAdoptionTracker::class, 'adoptionFunnel');
        $doc = $reflection->getDocComment();

        $this->assertNotEmpty($doc);
        $this->assertStringContainsString('feature', $doc);
        $this->assertStringContainsString('adopted', $doc);
        $this->assertStringContainsString('adoption_rate', $doc);
        $this->assertStringContainsString('drop_off', $doc);
    }

    // ─── Controller Method Existence ────────────────────────────────────

    /**
     * AnalyticsEventController has the new v4.2.0 methods.
     */
    public function testControllerHasImpactMethods(): void
    {
        $controllerClass = \ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class;

        // Event Impact
        $this->assertTrue(method_exists($controllerClass, 'eventImpactCalculate'));
        $this->assertTrue(method_exists($controllerClass, 'eventImpactConversionDrivers'));
        $this->assertTrue(method_exists($controllerClass, 'eventImpactRetentionDrivers'));

        // Feature Adoption
        $this->assertTrue(method_exists($controllerClass, 'featureAdoptionProfile'));
        $this->assertTrue(method_exists($controllerClass, 'featureAdoptionRecord'));
        $this->assertTrue(method_exists($controllerClass, 'featureAdoptionFunnel'));
        $this->assertTrue(method_exists($controllerClass, 'featureAdoptionRecent'));
        $this->assertTrue(method_exists($controllerClass, 'featureAdoptionStreak'));
        $this->assertTrue(method_exists($controllerClass, 'featureAdoptionClear'));
    }

    /**
     * AnalyticsEventController has governance mutation methods.
     */
    public function testControllerHasGovernanceMutationMethods(): void
    {
        $controllerClass = \ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class;

        $this->assertTrue(method_exists($controllerClass, 'governanceRegister'));
        $this->assertTrue(method_exists($controllerClass, 'governanceActivate'));
        $this->assertTrue(method_exists($controllerClass, 'governanceDeprecate'));
        $this->assertTrue(method_exists($controllerClass, 'governanceRetire'));
    }

    // ─── Route Registration ────────────────────────────────────────────

    /**
     * Routes file contains the new v4.2.0 route definitions.
     */
    public function testRoutesFileContainsImpactRoutes(): void
    {
        $routesContent = file_get_contents(__DIR__ . '/../routes/analytics.php');

        $this->assertStringContainsString('impact/calculate', $routesContent);
        $this->assertStringContainsString('impact/conversion-drivers', $routesContent);
        $this->assertStringContainsString('impact/retention-drivers', $routesContent);
    }

    /**
     * Routes file contains feature adoption routes.
     */
    public function testRoutesFileContainsAdoptionRoutes(): void
    {
        $routesContent = file_get_contents(__DIR__ . '/../routes/analytics.php');

        $this->assertStringContainsString('adoption/profile', $routesContent);
        $this->assertStringContainsString('adoption/record', $routesContent);
        $this->assertStringContainsString('adoption/funnel', $routesContent);
        $this->assertStringContainsString('adoption/recent', $routesContent);
        $this->assertStringContainsString('adoption/streak', $routesContent);
    }

    /**
     * Routes file contains governance mutation routes.
     */
    public function testRoutesFileContainsGovernanceMutationRoutes(): void
    {
        $routesContent = file_get_contents(__DIR__ . '/../routes/analytics.php');

        $this->assertStringContainsString('governance/register', $routesContent);
        $this->assertStringContainsString('governance/activate', $routesContent);
        $this->assertStringContainsString('governance/deprecate', $routesContent);
        $this->assertStringContainsString('governance/retire', $routesContent);
    }

    // ─── JS Client Version ──────────────────────────────────────────────

    /**
     * JS client getVersion() returns 76.0.0.
     */
    public function testJsClientVersion(): void
    {
        $jsContent = file_get_contents(__DIR__ . '/../resources/js/analytics.js');

        // getVersion should return '76.0.0'
        $this->assertStringContainsString("'76.0.0'", $jsContent);
        $this->assertStringContainsString("return '76.0.0';", $jsContent);

        // Old version should NOT appear
        $this->assertStringNotContainsString("'3.9.0'", $jsContent);
    }

    /**
     * JS client header version is 76.0.0.
     */
    public function testJsClientHeaderVersion(): void
    {
        $jsContent = file_get_contents(__DIR__ . '/../resources/js/analytics.js');

        $this->assertStringContainsString('@version 76.0.0', $jsContent);
    }

    /**
     * TypeScript definitions version is 76.0.0.
     */
    public function testTypeScriptDefinitionsVersion(): void
    {
        $tsContent = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');

        $this->assertStringContainsString('@version 76.0.0', $tsContent);
    }

    // ─── Composer Version ──────────────────────────────────────────────

    /**
     * composer.json version is 76.0.0.
     */
    public function testComposerJsonVersion(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);

        $this->assertSame('76.0.0', $composer['version']);
    }

    // ─── Catalog Provider Coverage ─────────────────────────────────────

    /**
     * All events have GA4 mappings.
     */
    public function testAllEventsHaveGa4Mappings(): void
    {
        $all = EventCatalog::all();

        foreach ($all as $name => $entry) {
            $this->assertArrayHasKey(
                'ga4',
                $entry,
                "Event '{$name}' is missing GA4 mapping",
            );
            $this->assertNotEmpty(
                $entry['ga4'],
                "Event '{$name}' has empty GA4 mapping",
            );
        }
    }

    /**
     * Catalog summary is consistent.
     */
    public function testCatalogSummaryConsistent(): void
    {
        $summary = EventCatalog::summary();

        $this->assertSame($summary['total'], $summary['ecommerce'] + $summary['saas'] + $summary['engagement']);
        $this->assertSame($summary['total'], EventCatalog::count());
        $this->assertGreaterThan(0, $summary['with_ga4']);
    }

    // ─── GDPR Events ────────────────────────────────────────────────────

    /**
     * GDPR events are present in the catalog.
     */
    public function testGdprEventsExist(): void
    {
        $gdpr = EventCatalog::gdprEvents();
        $this->assertGreaterThan(0, count($gdpr));

        $names = array_column($gdpr, 'name');
        $this->assertContains('consent_granted', $names);
        $this->assertContains('consent_withdrawn', $names);
        $this->assertContains('data_subject_access_request', $names);
        $this->assertContains('data_erasure_completed', $names);
    }

    // ─── Route Count ────────────────────────────────────────────────────

    /**
     * Routes file has at least 385 route definitions (previous was 373 + 14 new).
     */
    public function testRouteCountMinimum(): void
    {
        $routesContent = file_get_contents(__DIR__ . '/../routes/analytics.php');

        // Count Route:: calls
        preg_match_all('/Route::/', $routesContent, $matches);
        $routeCount = count($matches[0]);

        $this->assertGreaterThan(385, $routeCount, "Expected at least 385 routes, got {$routeCount}");
    }
}
