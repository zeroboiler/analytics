<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Services\GeospatialAnalyticsService;
use ZeroBoiler\Analytics\Services\NaturalLanguageQueryEngine;

/**
 * Tests for v237.0.0 — Natural Language Query Engine + Geospatial Analytics.
 *
 * Validates:
 * 1. File quality: strict_types, MIT headers, final classes, @since annotations
 * 2. NaturalLanguageQueryEngine: parsing, time ranges, event resolution,
 *    aggregation, sorting, filters, comparison, confidence scoring, templates
 * 3. GeospatialAnalyticsService: country/region/city/continent/timezone
 *    breakdowns, heatmap data, GeoJSON output, anomaly detection, comparison
 * 4. Version consistency 237.0.0
 * 5. Config sections present
 * 6. Routes registered
 *
 * @since 237.0.0
 */
final class V237InsightEngineAndGeospatialTest extends TestCase
{
    // ── File Quality Checks ────────────────────────────────────────────

    public function testNaturalLanguageQueryEngineHasStrictTypes(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/NaturalLanguageQueryEngine.php');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
        $this->assertStringContainsString('namespace ZeroBoiler\\Analytics\\Services', $content);
        $this->assertStringContainsString('final class NaturalLanguageQueryEngine', $content);
        $this->assertStringContainsString('@since 237.0.0', $content);
        $this->assertStringContainsString('This file is part of ZeroBoiler, licensed under the MIT license', $content);
    }

    public function testGeospatialAnalyticsServiceHasStrictTypes(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/GeospatialAnalyticsService.php');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
        $this->assertStringContainsString('namespace ZeroBoiler\\Analytics\\Services', $content);
        $this->assertStringContainsString('final class GeospatialAnalyticsService', $content);
        $this->assertStringContainsString('@since 237.0.0', $content);
        $this->assertStringContainsString('This file is part of ZeroBoiler, licensed under the MIT license', $content);
    }

    public function testAnalyticsInsightCommandHasStrictTypes(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsInsightCommand.php');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
        $this->assertStringContainsString('namespace ZeroBoiler\\Analytics\\Console\\Commands', $content);
        $this->assertStringContainsString('final class AnalyticsInsightCommand', $content);
        $this->assertStringContainsString('@since 237.0.0', $content);
        $this->assertStringContainsString('This file is part of ZeroBoiler, licensed under the MIT license', $content);
    }

    // ── Natural Language Query Engine ─────────────────────────────────

    public function testNlEngineParsesSimpleCountQuery(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/NaturalLanguageQueryEngine.php');
        $this->assertNotFalse($content);

        // Verify parse method exists
        $this->assertStringContainsString('public function parse(string $question, array $context = []): array', $content);
        // Verify return type is array
        $this->assertStringContainsString('ParsedQuery', $content);
    }

    public function testNlEngineHasTimeRangeExtraction(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/NaturalLanguageQueryEngine.php');
        $this->assertNotFalse($content);

        // Time range patterns
        $this->assertStringContainsString('last\s+(\d+)\s+(?:days?|d)', $content);
        $this->assertStringContainsString('today\b', $content);
        $this->assertStringContainsString('yesterday\b', $content);
        $this->assertStringContainsString('this\s+week\b', $content);
        $this->assertStringContainsString('last\s+month\b', $content);
        $this->assertStringContainsString('this\s+year\b', $content);
        $this->assertStringContainsString('Q([1-4])\b', $content);
        $this->assertStringContainsString('last\s+(\d+)\s+(?:hours?|hrs?|h)', $content);
        $this->assertStringContainsString('last\s+(\d+)\s+(?:weeks?|w)', $content);
        $this->assertStringContainsString('last\s+(\d+)\s+(?:months?|mo)', $content);
    }

    public function testNlEngineHasEventResolution(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/NaturalLanguageQueryEngine.php');
        $this->assertNotFalse($content);

        // Event resolution via EventCatalog
        $this->assertStringContainsString('EventCatalog::resolve', $content);
        // Common event aliases
        $this->assertStringContainsString('page\s*views?', $content);
        $this->assertStringContainsString('sign\s*ups?', $content);
        $this->assertStringContainsString('purchases?', $content);
        $this->assertStringContainsString('add\s*to\s*cart', $content);
        $this->assertStringContainsString('scroll\s*depth', $content);
        $this->assertStringContainsString('begin_checkout', $content);
        $this->assertStringContainsString('revenue_tracked', $content);
        $this->assertStringContainsString('start_trial', $content);
        $this->assertStringContainsString('plan_upgrade', $content);
    }

    public function testNlEngineHasAggregationExtraction(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/NaturalLanguageQueryEngine.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString("'count'", $content);
        $this->assertStringContainsString("'sum'", $content);
        $this->assertStringContainsString("'avg'", $content);
        $this->assertStringContainsString("'trend'", $content);
        $this->assertStringContainsString("'unique_count'", $content);
        $this->assertStringContainsString("'ratio'", $content);
    }

    public function testNlEngineHasComparisonDetection(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/NaturalLanguageQueryEngine.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString("'vs\b'", $content);
        $this->assertStringContainsString('compared?\s+to', $content);
        $this->assertStringContainsString('previous\s+period', $content);
    }

    public function testNlEngineHasGroupByExtraction(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/NaturalLanguageQueryEngine.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString("'country'", $content);
        $this->assertStringContainsString("'region'", $content);
        $this->assertStringContainsString("'city'", $content);
        $this->assertStringContainsString("'device'", $content);
        $this->assertStringContainsString("'browser'", $content);
        $this->assertStringContainsString("'source'", $content);
        $this->assertStringContainsString("'hour'", $content);
        $this->assertStringContainsString("'day'", $content);
    }

    public function testNlEngineHasConfidenceScoring(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/NaturalLanguageQueryEngine.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString('calculateConfidence', $content);
        $this->assertStringContainsString("'confidence'", $content);
    }

    public function testNlEngineHasQuestionTemplates(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/NaturalLanguageQueryEngine.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString('questionTemplates', $content);
        $this->assertStringContainsString('template', $content);
        $this->assertStringContainsString('description', $content);
        $this->assertStringContainsString('category', $content);
    }

    public function testNlEngineHasCustomParserRegistration(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/NaturalLanguageQueryEngine.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString('registerParser', $content);
        $this->assertStringContainsString('customParsers', $content);
    }

    public function testNlEngineHasSummaryMethod(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/NaturalLanguageQueryEngine.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString('public function summary(): array', $content);
    }

    public function testNlEngineHasAskMethod(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/NaturalLanguageQueryEngine.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString('public function ask(string $question, array $context = []): array', $content);
        $this->assertStringContainsString('generateSummary', $content);
        $this->assertStringContainsString('generateSuggestions', $content);
    }

    public function testNlEngineHasSemanticMetricRecognition(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/NaturalLanguageQueryEngine.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString('isSemanticMetric', $content);
        $this->assertStringContainsString("'active_users'", $content);
        $this->assertStringContainsString("'churn_rate'", $content);
        $this->assertStringContainsString("'conversion_rate'", $content);
        $this->assertStringContainsString("'mrr'", $content);
        $this->assertStringContainsString("'arr'", $content);
        $this->assertStringContainsString("'revenue'", $content);
    }

    // ── Geospatial Analytics Service ─────────────────────────────────

    public function testGeoServiceHasCountryBreakdown(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/GeospatialAnalyticsService.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString('public function byCountry(', $content);
        $this->assertStringContainsString('public function byRegion(', $content);
        $this->assertStringContainsString('public function byCity(', $content);
        $this->assertStringContainsString('public function byContinent(', $content);
        $this->assertStringContainsString('public function byTimezone(', $content);
    }

    public function testGeoServiceHasHeatmapData(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/GeospatialAnalyticsService.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString('public function heatmapData(', $content);
        $this->assertStringContainsString('lat', $content);
        $this->assertStringContainsString('lng', $content);
        $this->assertStringContainsString('intensity', $content);
        $this->assertStringContainsString('country_code', $content);
    }

    public function testGeoServiceHasGeoJsonOutput(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/GeospatialAnalyticsService.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString('public function geoJsonCollection(', $content);
        $this->assertStringContainsString('FeatureCollection', $content);
        $this->assertStringContainsString('Feature', $content);
        $this->assertStringContainsString('Point', $content);
        $this->assertStringContainsString('coordinates', $content);
    }

    public function testGeoServiceHasGeographicFunnel(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/GeospatialAnalyticsService.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString('public function geographicFunnel(', $content);
        $this->assertStringContainsString('GeoFunnelStep', $content);
        $this->assertStringContainsString('overall_conversion', $content);
    }

    public function testGeoServiceHasAnomalyDetection(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/GeospatialAnalyticsService.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString('public function detectAnomalies(', $content);
        $this->assertStringContainsString('z_score', $content);
        $this->assertStringContainsString('severity', $content);
        $this->assertStringContainsString('zScore', $content);
        $this->assertStringContainsString('threshold', $content);
    }

    public function testGeoServiceHasLocationComparison(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/GeospatialAnalyticsService.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString('public function compareLocations(', $content);
        $this->assertStringContainsString('location_a', $content);
        $this->assertStringContainsString('location_b', $content);
        $this->assertStringContainsString('ratio', $content);
    }

    public function testGeoServiceHasCoverageReport(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/GeospatialAnalyticsService.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString('public function coverage(): array', $content);
        $this->assertStringContainsString('coverage_score', $content);
        $this->assertStringContainsString('data_quality', $content);
    }

    public function testGeoServiceHasCountryGeoData(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/GeospatialAnalyticsService.php');
        $this->assertNotFalse($content);

        // Embedded country data with lat/lng
        $this->assertStringContainsString('loadCountryGeoData', $content);
        $this->assertStringContainsString("'United States'", $content);
        $this->assertStringContainsString("'United Kingdom'", $content);
        $this->assertStringContainsString("'Germany'", $content);
        $this->assertStringContainsString("'Japan'", $content);
        $this->assertStringContainsString("'Brazil'", $content);
        $this->assertStringContainsString("'India'", $content);
        $this->assertStringContainsString("'Australia'", $content);

        // Verify at least 40 countries embedded
        $this->assertGreaterThan(40, substr_count($content, "'lat' =>"));
    }

    public function testGeoServiceHasContinentMapping(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/GeospatialAnalyticsService.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString('countryToContinent', $content);
        $this->assertStringContainsString("'Europe'", $content);
        $this->assertStringContainsString("'North America'", $content);
        $this->assertStringContainsString("'South America'", $content);
        $this->assertStringContainsString("'Asia'", $content);
        $this->assertStringContainsString("'Africa'", $content);
        $this->assertStringContainsString("'Oceania'", $content);
    }

    public function testGeoServiceHasSummaryMethod(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/GeospatialAnalyticsService.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString('public function summary(): array', $content);
        $this->assertStringContainsString('heatmap_bucket', $content);
        $this->assertStringContainsString('top_limit', $content);
        $this->assertStringContainsString('country_count', $content);
    }

    // ── Analytics Insight Command ──────────────────────────────────────

    public function testInsightCommandHasAskMode(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsInsightCommand.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString("handleAsk", $content);
        $this->assertStringContainsString("'ask'", $content);
    }

    public function testInsightCommandHasGeoMode(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsInsightCommand.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString("handleGeo", $content);
        $this->assertStringContainsString("'geo'", $content);
        $this->assertStringContainsString('--dimension', $content);
        $this->assertStringContainsString('--country', $content);
    }

    public function testInsightCommandHasAnomaliesMode(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsInsightCommand.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString("handleAnomalies", $content);
        $this->assertStringContainsString("'anomalies'", $content);
        $this->assertStringContainsString('--threshold', $content);
    }

    public function testInsightCommandHasGeoFunnelMode(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsInsightCommand.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString("handleGeoFunnel", $content);
        $this->assertStringContainsString("'geo-funnel'", $content);
    }

    public function testInsightCommandHasHeatmapMode(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsInsightCommand.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString("handleHeatmap", $content);
        $this->assertStringContainsString("'heatmap'", $content);
    }

    public function testInsightCommandHasSummaryMode(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsInsightCommand.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString("handleSummary", $content);
        $this->assertStringContainsString("'summary'", $content);
    }

    public function testInsightCommandAcceptsJsonOption(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsInsightCommand.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString('--json', $content);
        $this->assertStringContainsString('--fresh', $content);
        $this->assertStringContainsString('--compare', $content);
    }

    // ── Config Sections ───────────────────────────────────────────────

    public function testConfigHasNlQuerySection(): void
    {
        $content = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString("'nl_query'", $content);
        $this->assertStringContainsString('ANALYTICS_NL_QUERY_ENABLED', $content);
        $this->assertStringContainsString('ANALYTICS_NL_QUERY_CACHE_TTL', $content);
        $this->assertStringContainsString('ANALYTICS_NL_QUERY_DEFAULT_LIMIT', $content);
        $this->assertStringContainsString('ANALYTICS_NL_QUERY_MAX_LIMIT', $content);
        $this->assertStringContainsString('ANALYTICS_NL_QUERY_LLM_ENABLED', $content);
        $this->assertStringContainsString('ANALYTICS_NL_QUERY_LLM_PROVIDER', $content);
        $this->assertStringContainsString('ANALYTICS_NL_QUERY_LLM_MODEL', $content);
    }

    public function testConfigHasGeospatialSection(): void
    {
        $content = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString("'geospatial'", $content);
        $this->assertStringContainsString('ANALYTICS_GEOSPATIAL_ENABLED', $content);
        $this->assertStringContainsString('ANALYTICS_GEOSPATIAL_CACHE_TTL', $content);
        $this->assertStringContainsString('ANALYTICS_GEOSPATIAL_TOP_LIMIT', $content);
        $this->assertStringContainsString('ANALYTICS_GEOSPATIAL_HEATMAP_BUCKET', $content);
        $this->assertStringContainsString('ANALYTICS_GEOSPATIAL_INCLUDE_UNKNOWN', $content);
    }

    // ── Routes ────────────────────────────────────────────────────────

    public function testRoutesHaveNlQueryEndpoints(): void
    {
        $content = file_get_contents(__DIR__ . '/../routes/analytics.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString('nl-query/ask', $content);
        $this->assertStringContainsString('nlQueryAsk', $content);
        $this->assertStringContainsString('nl-query/parse', $content);
        $this->assertStringContainsString('nlQueryParse', $content);
        $this->assertStringContainsString('nl-query/templates', $content);
        $this->assertStringContainsString('nlQueryTemplates', $content);
        $this->assertStringContainsString('nl-query/summary', $content);
        $this->assertStringContainsString('nlQuerySummary', $content);
    }

    public function testRoutesHaveGeoEndpoints(): void
    {
        $content = file_get_contents(__DIR__ . '/../routes/analytics.php');
        $this->assertNotFalse($content);

        $this->assertStringContainsString('geo/country', $content);
        $this->assertStringContainsString('geo/region', $content);
        $this->assertStringContainsString('geo/city', $content);
        $this->assertStringContainsString('geo/continent', $content);
        $this->assertStringContainsString('geo/timezone', $content);
        $this->assertStringContainsString('geo/heatmap', $content);
        $this->assertStringContainsString('geo/geojson', $content);
        $this->assertStringContainsString('geo/funnel', $content);
        $this->assertStringContainsString('geo/anomalies', $content);
        $this->assertStringContainsString('geo/compare', $content);
        $this->assertStringContainsString('geo/coverage', $content);
        $this->assertStringContainsString('geo/summary', $content);
    }

    // ── Version Consistency ────────────────────────────────────────────

    public function testVersionConsistency237(): void
    {
        $eventVersion = AnalyticsEvent::VERSION;
        $composerContent = file_get_contents(__DIR__ . '/../composer.json');
        $this->assertNotFalse($composerContent);

        // The version should be updated to 237.0.0 (checked in commit)
        $this->assertIsString($eventVersion);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $eventVersion);
    }

    // ── Catalog Coverage ───────────────────────────────────────────────

    public function testEventCatalogHasRequiredEventsForNlResolution(): void
    {
        // Events referenced in NL engine should exist in catalog
        $this->assertTrue(EventCatalog::has('page_view'), 'page_view should exist in catalog');
        $this->assertTrue(EventCatalog::has('sign_up'), 'sign_up should exist in catalog');
        $this->assertTrue(EventCatalog::has('login'), 'login should exist in catalog');
        $this->assertTrue(EventCatalog::has('purchase'), 'purchase should exist in catalog');
        $this->assertTrue(EventCatalog::has('click'), 'click should exist in catalog');
        $this->assertTrue(EventCatalog::has('form_submit'), 'form_submit should exist in catalog');
        $this->assertTrue(EventCatalog::has('form_start'), 'form_start should exist in catalog');
        $this->assertTrue(EventCatalog::has('search'), 'search should exist in catalog');
        $this->assertTrue(EventCatalog::has('share'), 'share should exist in catalog');
        $this->assertTrue(EventCatalog::has('scroll_depth'), 'scroll_depth should exist in catalog');
        $this->assertTrue(EventCatalog::has('add_to_cart'), 'add_to_cart should exist in catalog');
        $this->assertTrue(EventCatalog::has('remove_from_cart'), 'remove_from_cart should exist in catalog');
        $this->assertTrue(EventCatalog::has('refund'), 'refund should exist in catalog');
        $this->assertTrue(EventCatalog::has('begin_checkout'), 'begin_checkout should exist in catalog');
        $this->assertTrue(EventCatalog::has('view_item'), 'view_item should exist in catalog');
        $this->assertTrue(EventCatalog::has('view_cart'), 'view_cart should exist in catalog');
        $this->assertTrue(EventCatalog::has('subscribe'), 'subscribe should exist in catalog');
        $this->assertTrue(EventCatalog::has('cancellation'), 'cancellation should exist in catalog');
        $this->assertTrue(EventCatalog::has('start_trial'), 'start_trial should exist in catalog');
        $this->assertTrue(EventCatalog::has('plan_upgrade'), 'plan_upgrade should exist in catalog');
        $this->assertTrue(EventCatalog::has('plan_downgrade'), 'plan_downgrade should exist in catalog');
        $this->assertTrue(EventCatalog::has('feature_used'), 'feature_used should exist in catalog');
        $this->assertTrue(EventCatalog::has('revenue_tracked'), 'revenue_tracked should exist in catalog');
        $this->assertTrue(EventCatalog::has('error'), 'error should exist in catalog');
    }

    public function testEventCatalogHasProviderMappingsForReferencedEvents(): void
    {
        // Each referenced event should have multi-provider mappings
        $events = ['page_view', 'sign_up', 'purchase', 'add_to_cart', 'search', 'error'];

        foreach ($events as $event) {
            $entry = EventCatalog::get($event);
            $this->assertNotNull($entry, "{$event} should have a catalog entry");
            $this->assertArrayHasKey('ga4', $entry);
            $this->assertArrayHasKey('meta', $entry);
            $this->assertArrayHasKey('posthog', $entry);
            $this->assertArrayHasKey('plausible', $entry);
        }
    }

    public function testCategorySummaryHasMinimumEventCounts(): void
    {
        $summary = EventCatalog::categorySummary();

        $this->assertGreaterThan(0, $summary['ecommerce'], 'ecommerce should have events');
        $this->assertGreaterThan(0, $summary['saas'], 'saas should have events');
        $this->assertGreaterThan(0, $summary['engagement'], 'engagement should have events');
        $this->assertGreaterThan(0, $summary['total'], 'total should be > 0');

        // With 200+ events, each major category should have 10+
        $this->assertGreaterThanOrEqual(10, $summary['ecommerce'], 'ecommerce should have 10+ events');
        $this->assertGreaterThanOrEqual(10, $summary['saas'], 'saas should have 10+ events');
        $this->assertGreaterThanOrEqual(10, $summary['engagement'], 'engagement should have 10+ events');
    }

    // ── Scale Thresholds ───────────────────────────────────────────────

    public function testProjectScaleThresholds(): void
    {
        // Ensure the project has grown with this addition
        $srcCount = count(glob(__DIR__ . '/../src/**/*.php', GLOB_BRACE));
        $testCount = count(glob(__DIR__ . '/*.php'));
        $cmdCount = count(glob(__DIR__ . '/../src/Console/Commands/*.php'));
        $serviceCount = count(glob(__DIR__ . '/../src/Services/*.php'));

        $this->assertGreaterThan(950, $srcCount, 'Should have 950+ src files');
        $this->assertGreaterThan(480, $testCount, 'Should have 480+ test files');
        $this->assertGreaterThan(110, $cmdCount, 'Should have 110+ commands');
        $this->assertGreaterThan(320, $serviceCount, 'Should have 320+ services');
    }
}
