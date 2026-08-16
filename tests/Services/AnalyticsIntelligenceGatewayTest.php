<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests\ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Services\AnalyticsIntelligenceGateway;

/**
 * Unit tests for the Analytics Intelligence Gateway service.
 *
 * @covers \ZeroBoiler\Analytics\Services\AnalyticsIntelligenceGateway
 *
 * @since 71.0.0
 */
final class AnalyticsIntelligenceGatewayTest extends TestCase
{
    private AnalyticsIntelligenceGateway $gateway;

    private ConfigRepository $config;

    protected function setUp(): void
    {
        $manager = $this->createMock(AnalyticsManager::class);

        // Configure GA4 mock
        $ga4Mock = $this->createMock(\ZeroBoiler\Analytics\Trackers\GA4Tracker::class);
        $ga4Mock->method('isEnabled')->willReturn(true);
        $ga4Mock->method('getMeasurementId')->willReturn('G-TEST123');

        $manager->method('ga4')->willReturn($ga4Mock);

        // Configure GTM mock (disabled)
        $gtmMock = $this->createMock(\ZeroBoiler\Analytics\Trackers\GTMTracker::class);
        $gtmMock->method('isEnabled')->willReturn(false);
        $gtmMock->method('getContainerId')->willReturn('');
        $manager->method('gtm')->willReturn($gtmMock);

        // Configure Meta mock (disabled)
        $metaMock = $this->createMock(\ZeroBoiler\Analytics\Trackers\MetaPixelTracker::class);
        $metaMock->method('isEnabled')->willReturn(false);
        $metaMock->method('getPixelId')->willReturn('');
        $manager->method('meta')->willReturn($metaMock);

        // Configure Plausible mock
        $plausibleMock = $this->createMock(\ZeroBoiler\Analytics\Trackers\PlausibleTracker::class);
        $plausibleMock->method('isEnabled')->willReturn(false);
        $plausibleMock->method('getDomain')->willReturn('');
        $manager->method('plausible')->willReturn($plausibleMock);

        // Configure PostHog mock
        $posthogMock = $this->createMock(\ZeroBoiler\Analytics\Trackers\PosthogTracker::class);
        $posthogMock->method('isEnabled')->willReturn(false);
        $posthogMock->method('getHost')->willReturn('');
        $manager->method('posthog')->willReturn($posthogMock);

        // Configure Mixpanel mock
        $mixpanelMock = $this->createMock(\ZeroBoiler\Analytics\Trackers\MixpanelTracker::class);
        $mixpanelMock->method('isEnabled')->willReturn(false);
        $mixpanelMock->method('getToken')->willReturn('');
        $manager->method('mixpanel')->willReturn($mixpanelMock);

        // Configure Amplitude mock
        $amplitudeMock = $this->createMock(\ZeroBoiler\Analytics\Trackers\AmplitudeTracker::class);
        $amplitudeMock->method('isEnabled')->willReturn(false);
        $amplitudeMock->method('getApiKey')->willReturn('');
        $manager->method('amplitude')->willReturn($amplitudeMock);

        // Configure TikTok mock
        $tiktokMock = $this->createMock(\ZeroBoiler\Analytics\Trackers\TikTokTracker::class);
        $tiktokMock->method('isEnabled')->willReturn(false);
        $tiktokMock->method('getPixelId')->willReturn('');
        $manager->method('tiktok')->willReturn($tiktokMock);

        // Configure LinkedIn mock
        $linkedinMock = $this->createMock(\ZeroBoiler\Analytics\Trackers\LinkedInTracker::class);
        $linkedinMock->method('isEnabled')->willReturn(false);
        $linkedinMock->method('getPartnerId')->willReturn('');
        $manager->method('linkedin')->willReturn($linkedinMock);

        $this->config = $this->createMock(ConfigRepository::class);
        $this->config->method('get')->willReturnMap([
            ['zeroboiler.analytics.identity.cookie_name', 'zb_analytics_id', 'zb_analytics_id'],
            ['zeroboiler.analytics.queue.enabled', true, true],
            ['zeroboiler.analytics.pipeline', [], ['auto_utm' => true, 'auto_timestamp' => false]],
            ['zeroboiler.analytics.consent.purposes', [], []],
            ['zeroboiler.analytics.consent.default', 'granted', 'granted'],
            ['zeroboiler.analytics.consent.log_enabled', false, false],
            ['zeroboiler.analytics.consent.log_ttl', 7776000, 7776000],
            ['zeroboiler.analytics.ecommerce', [], ['currency' => 'USD']],
            ['zeroboiler.analytics.revenue.subscription_tiers', [], []],
            ['zeroboiler.analytics.identity', [], ['link_on_auth' => true]],
            ['zeroboiler.analytics.identity.cookie_ttl', 525600, 525600],
            ['zeroboiler.analytics.identity.cookie_secure', true, true],
            ['zeroboiler.analytics.identity.cookie_samesite', 'Lax', 'Lax'],
            ['zeroboiler.analytics.client_auto_track', [], ['page_views' => true]],
            ['zeroboiler.analytics.performance', [], ['enabled' => false]],
            ['zeroboiler.analytics.debug', [], ['enabled' => false]],
            ['zeroboiler.analytics.track_links', [], []],
            ['zeroboiler.analytics.dedup', [], []],
            ['zeroboiler.analytics.sampling', [], []],
            ['zeroboiler.analytics.geolocation', [], []],
            ['zeroboiler.analytics.regional_consent', [], []],
            ['zeroboiler.analytics.cross_domain', [], []],
            ['zeroboiler.analytics.observability', [], []],
            ['zeroboiler.analytics.transformation.enabled', true, true],
            ['zeroboiler.analytics.transformation.cache_ttl', 3600, 3600],
            ['zeroboiler.analytics.transformation.strict', false, false],
            ['zeroboiler.analytics.transformation.mappings', [], []],
            ['zeroboiler.analytics.pii_sanitization.enabled', false, false],
            ['zeroboiler.analytics.pii_sanitization.strategy', 'hash', 'hash'],
            ['zeroboiler.analytics.pii_sanitization.custom_fields', [], []],
            ['zeroboiler.analytics.validation.strict', false, false],
            ['zeroboiler.analytics.validation.whitelist', [], []],
            ['zeroboiler.analytics.validation.max_event_name_length', 100, 100],
            ['zeroboiler.analytics.validation.deduplication_window', 10, 10],
            ['zeroboiler.analytics.lifecycle', [], []],
            ['zeroboiler.analytics.identity.cookie_domain', null, null],
        ]);

        $this->gateway = new AnalyticsIntelligenceGateway($manager, $this->config);
    }

    // ── Dashboard Tests ───────────────────────────────────────────

    public function testDashboardReturnsAllSections(): void
    {
        $dashboard = $this->gateway->dashboard();

        $this->assertArrayHasKey('timestamp', $dashboard);
        $this->assertArrayHasKey('version', $dashboard);
        $this->assertArrayHasKey('provider_health', $dashboard);
        $this->assertArrayHasKey('catalog_coverage', $dashboard);
        $this->assertArrayHasKey('anomaly_summary', $dashboard);
        $this->assertArrayHasKey('funnel_health', $dashboard);
        $this->assertArrayHasKey('churn_signals', $dashboard);
        $this->assertArrayHasKey('revenue_health', $dashboard);
        $this->assertArrayHasKey('pipeline_health', $dashboard);
        $this->assertArrayHasKey('data_quality', $dashboard);
        $this->assertArrayHasKey('fallback_status', $dashboard);
        $this->assertArrayHasKey('budget_utilization', $dashboard);
        $this->assertArrayHasKey('privacy_compliance', $dashboard);
        $this->assertArrayHasKey('transformation_status', $dashboard);
        $this->assertArrayHasKey('overall_score', $dashboard);
        $this->assertArrayHasKey('overall_grade', $dashboard);
        $this->assertArrayHasKey('alerts', $dashboard);
    }

    public function testDashboardOverallScoreIsInt(): void
    {
        $dashboard = $this->gateway->dashboard();

        $this->assertIsInt($dashboard['overall_score']);
        $this->assertGreaterThanOrEqual(0, $dashboard['overall_score']);
        $this->assertLessThanOrEqual(100, $dashboard['overall_score']);
    }

    public function testDashboardOverallGradeIsValid(): void
    {
        $dashboard = $this->gateway->dashboard();

        $validGrades = ['A+', 'A', 'B', 'C', 'D', 'E', 'F'];
        $this->assertContains($dashboard['overall_grade'], $validGrades);
    }

    public function testDashboardAlertsIsList(): void
    {
        $dashboard = $this->gateway->dashboard();

        $this->assertIsArray($dashboard['alerts']);
    }

    public function testDashboardFilterByInclude(): void
    {
        $dashboard = $this->gateway->dashboard(['include' => ['provider_health', 'version']]);

        $this->assertArrayHasKey('provider_health', $dashboard);
        $this->assertArrayHasKey('version', $dashboard);
        $this->assertArrayNotHasKey('catalog_coverage', $dashboard);
        $this->assertArrayNotHasKey('anomaly_summary', $dashboard);
    }

    public function testDashboardFilterByExclude(): void
    {
        $dashboard = $this->gateway->dashboard(['exclude' => ['churn_signals', 'fallback_status']]);

        $this->assertArrayHasKey('provider_health', $dashboard);
        $this->assertArrayNotHasKey('churn_signals', $dashboard);
        $this->assertArrayNotHasKey('fallback_status', $dashboard);
    }

    // ── Provider Health Tests ──────────────────────────────────────

    public function testProviderHealthHasEnabledCount(): void
    {
        $dashboard = $this->gateway->dashboard();
        $providerHealth = $dashboard['provider_health'];

        $this->assertArrayHasKey('enabled_count', $providerHealth);
        $this->assertArrayHasKey('total', $providerHealth);
        $this->assertSame(10, $providerHealth['total']);
        $this->assertGreaterThanOrEqual(1, $providerHealth['enabled_count']);
    }

    public function testProviderHealthGA4Enabled(): void
    {
        $dashboard = $this->gateway->dashboard();
        $providers = $dashboard['provider_health']['providers'];

        $this->assertTrue($providers['ga4']['enabled']);
        $this->assertTrue($providers['ga4']['configured']);
        $this->assertTrue($providers['ga4']['healthy']);
    }

    public function testProviderHealthDisabledProviders(): void
    {
        $dashboard = $this->gateway->dashboard();
        $providers = $dashboard['provider_health']['providers'];

        $this->assertFalse($providers['gtm']['enabled']);
        $this->assertFalse($providers['meta_pixel']['enabled']);
    }

    // ── Catalog Coverage Tests ──────────────────────────────────────

    public function testCatalogCoverageHasTotal(): void
    {
        $dashboard = $this->gateway->dashboard();
        $coverage = $dashboard['catalog_coverage'];

        $this->assertArrayHasKey('total', $coverage);
        $this->assertArrayHasKey('by_category', $coverage);
        $this->assertArrayHasKey('industry_standard_coverage', $coverage);
        $this->assertArrayHasKey('starter_coverage', $coverage);
        $this->assertArrayHasKey('essential_coverage', $coverage);
        $this->assertArrayHasKey('instrumented', $coverage);
        $this->assertArrayHasKey('gap_count', $coverage);
        $this->assertArrayHasKey('top_gaps', $coverage);
    }

    public function testCatalogCoveragePercentageValid(): void
    {
        $dashboard = $this->gateway->dashboard();
        $coverage = $dashboard['catalog_coverage'];

        $this->assertGreaterThanOrEqual(0.0, $coverage['industry_standard_coverage']);
        $this->assertLessThanOrEqual(100.0, $coverage['industry_standard_coverage']);
    }

    // ── Anomaly Summary Tests ───────────────────────────────────────

    public function testAnomalySummaryNotConfiguredWithoutService(): void
    {
        $dashboard = $this->gateway->dashboard();
        $anomaly = $dashboard['anomaly_summary'];

        $this->assertFalse($anomaly['enabled']);
        $this->assertSame('not_configured', $anomaly['status']);
        $this->assertSame(0, $anomaly['recent_anomalies']);
    }

    // ── Funnel Health Tests ─────────────────────────────────────────

    public function testFunnelHealthNotConfiguredWithoutService(): void
    {
        $dashboard = $this->gateway->dashboard();
        $funnel = $dashboard['funnel_health'];

        $this->assertNull($funnel['signup_to_trial']);
        $this->assertNull($funnel['trial_to_paid']);
        $this->assertSame('not_configured', $funnel['status']);
        $this->assertIsArray($funnel['events_tracked']);
    }

    // ── Churn Signals Tests ────────────────────────────────────────

    public function testChurnSignalsNotConfiguredWithoutService(): void
    {
        $dashboard = $this->gateway->dashboard();
        $churn = $dashboard['churn_signals'];

        $this->assertFalse($churn['enabled']);
        $this->assertSame('unknown', $churn['risk_level']);
        $this->assertSame('not_configured', $churn['status']);
        $this->assertIsArray($churn['signal_events']);
    }

    // ── Revenue Health Tests ────────────────────────────────────────

    public function testRevenueHealthHasBillingEvents(): void
    {
        $dashboard = $this->gateway->dashboard();
        $revenue = $dashboard['revenue_health'];

        $this->assertArrayHasKey('billing_events_tracked', $revenue);
        $this->assertArrayHasKey('revenue_events', $revenue);
        $this->assertArrayHasKey('ecommerce_currency', $revenue);
        $this->assertArrayHasKey('status', $revenue);
        $this->assertSame('USD', $revenue['ecommerce_currency']);
    }

    // ── Pipeline Health Tests ──────────────────────────────────────

    public function testPipelineHealthHasQueueStatus(): void
    {
        $dashboard = $this->gateway->dashboard();
        $pipeline = $dashboard['pipeline_health'];

        $this->assertArrayHasKey('queue_enabled', $pipeline);
        $this->assertArrayHasKey('auto_utm', $pipeline);
        $this->assertArrayHasKey('pii_enabled', $pipeline);
        $this->assertArrayHasKey('sampling_enabled', $pipeline);
        $this->assertArrayHasKey('validation_strict', $pipeline);
        $this->assertArrayHasKey('status', $pipeline);
    }

    // ── Data Quality Tests ─────────────────────────────────────────

    public function testDataQualityHasMetrics(): void
    {
        $dashboard = $this->gateway->dashboard();
        $quality = $dashboard['data_quality'];

        $this->assertArrayHasKey('dedup_window', $quality);
        $this->assertArrayHasKey('pii_strategy', $quality);
        $this->assertArrayHasKey('quality_score', $quality);
        $this->assertGreaterThanOrEqual(0.0, $quality['quality_score']);
        $this->assertLessThanOrEqual(100.0, $quality['quality_score']);
    }

    // ── Privacy Compliance Tests ───────────────────────────────────

    public function testPrivacyComplianceHasConsentDefault(): void
    {
        $dashboard = $this->gateway->dashboard();
        $privacy = $dashboard['privacy_compliance'];

        $this->assertArrayHasKey('consent_default', $privacy);
        $this->assertArrayHasKey('consent_log_enabled', $privacy);
        $this->assertArrayHasKey('gdpr_compliant', $privacy);
        $this->assertArrayHasKey('status', $privacy);
    }

    // ── Transformation Status Tests ─────────────────────────────────

    public function testTransformationStatusHasEnabled(): void
    {
        $dashboard = $this->gateway->dashboard();
        $transform = $dashboard['transformation_status'];

        $this->assertArrayHasKey('enabled', $transform);
        $this->assertArrayHasKey('cache_ttl', $transform);
        $this->assertArrayHasKey('strict', $transform);
        $this->assertArrayHasKey('mapping_count', $transform);
        $this->assertTrue($transform['enabled']);
        $this->assertSame(3600, $transform['cache_ttl']);
    }

    // ── Heartbeat Tests ────────────────────────────────────────────

    public function testHeartbeatReturnsMinimalPayload(): void
    {
        $heartbeat = $this->gateway->heartbeat();

        $this->assertArrayHasKey('status', $heartbeat);
        $this->assertArrayHasKey('version', $heartbeat);
        $this->assertArrayHasKey('timestamp', $heartbeat);
        $this->assertArrayHasKey('enabled_providers', $heartbeat);
        $this->assertArrayHasKey('total_providers', $heartbeat);
        $this->assertArrayHasKey('catalog_events', $heartbeat);
        $this->assertArrayHasKey('score', $heartbeat);
        $this->assertArrayHasKey('grade', $heartbeat);
    }

    public function testHeartbeatStatusIsValid(): void
    {
        $heartbeat = $this->gateway->heartbeat();

        $this->assertContains(
            $heartbeat['status'],
            ['healthy', 'degraded', 'critical'],
        );
    }

    public function testHeartbeatScoreIsInt(): void
    {
        $heartbeat = $this->gateway->heartbeat();

        $this->assertIsInt($heartbeat['score']);
        $this->assertGreaterThanOrEqual(0, $heartbeat['score']);
        $this->assertLessThanOrEqual(100, $heartbeat['score']);
    }

    public function testHeartbeatGradeIsValid(): void
    {
        $heartbeat = $this->gateway->heartbeat();

        $validGrades = ['A+', 'A', 'B', 'C', 'D', 'E', 'F'];
        $this->assertContains($heartbeat['grade'], $validGrades);
    }

    public function testHeartbeatTotalProvidersIsTen(): void
    {
        $heartbeat = $this->gateway->heartbeat();

        $this->assertSame(10, $heartbeat['total_providers']);
    }

    // ── Fallback / Budget Tests ────────────────────────────────────

    public function testFallbackStatusNotConfiguredWithoutService(): void
    {
        $dashboard = $this->gateway->dashboard();
        $fallback = $dashboard['fallback_status'];

        $this->assertFalse($fallback['enabled']);
        $this->assertSame('not_configured', $fallback['status']);
    }

    public function testBudgetUtilizationNotConfiguredWithoutService(): void
    {
        $dashboard = $this->gateway->dashboard();
        $budget = $dashboard['budget_utilization'];

        $this->assertFalse($budget['enabled']);
        $this->assertNull($budget['utilization_percent']);
        $this->assertSame('not_configured', $budget['status']);
    }

    // ── Integration: Dashboard Consistency ─────────────────────────

    public function testDashboardAndHeartbeatVersionConsistent(): void
    {
        $dashboard = $this->gateway->dashboard();
        $heartbeat = $this->gateway->heartbeat();

        $this->assertSame($dashboard['version'], $heartbeat['version']);
    }

    public function testMultipleDashboardCallsAreIndependent(): void
    {
        $first = $this->gateway->dashboard();
        $second = $this->gateway->dashboard();

        // Score should be identical for the same config state
        $this->assertSame($first['overall_score'], $second['overall_score']);
        $this->assertSame($first['overall_grade'], $second['overall_grade']);
    }
}
