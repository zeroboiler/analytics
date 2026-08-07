<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\Services\EventBroadcasterService;
use ZeroBoiler\Analytics\Services\TenantIsolationService;
use ZeroBoiler\Analytics\Services\DataRetentionPolicyService;
use ZeroBoiler\Analytics\Services\AnalyticsGateService;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * @covers \ZeroBoiler\Analytics\Services\EventBroadcasterService
 * @covers \ZeroBoiler\Analytics\Services\TenantIsolationService
 * @covers \ZeroBoiler\Analytics\Services\DataRetentionPolicyService
 * @covers \ZeroBoiler\Analytics\Services\AnalyticsGateService
 */
final class V30EnterpriseFeaturesTest extends TestCase
{
    // ── EventBroadcasterService Tests ──────────────────────────────────

    public function testBroadcasterDisabledByDefault(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.broadcast', [])->willReturn([]);

        $service = new EventBroadcasterService($config);

        $this->assertFalse($service->isEnabled());
    }

    public function testBroadcasterCanBeEnabled(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.broadcast', [])->willReturn([
            'enabled' => true,
            'channel_prefix' => 'live',
            'private_channels' => false,
        ]);

        $service = new EventBroadcasterService($config);

        $this->assertTrue($service->isEnabled());
        $this->assertSame('live', $service->getChannelPrefix());
        $this->assertSame('live.events', $service->channelName('events'));
        $this->assertSame('live', $service->channelName());
    }

    public function testBroadcasterDoesNotBroadcastWhenDisabled(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.broadcast', [])->willReturn([]);

        $service = new EventBroadcasterService($config);
        $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99]);

        $this->assertFalse($service->broadcast($event));
    }

    public function testBroadcasterFiltersByValueThreshold(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.broadcast', [])->willReturn([
            'enabled' => true,
            'value_threshold' => 50.0,
        ]);

        $service = new EventBroadcasterService($config);

        // Below threshold — should not broadcast (custom event, not in ALWAYS_BROADCAST)
        $lowEvent = new AnalyticsEvent(name: 'button_click', params: ['value' => 10.0]);
        $this->assertFalse($service->broadcast($lowEvent));

        // Above threshold — should attempt broadcast (broadcaster not available, returns false)
        $highEvent = new AnalyticsEvent(name: 'button_click', params: ['value' => 100.0]);
        $result = $service->broadcast($highEvent);
        // Returns false because no broadcaster is available (no container)
        $this->assertFalse($result);
    }

    public function testBroadcasterAlwaysBroadcastsCriticalEvents(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.broadcast', [])->willReturn([
            'enabled' => true,
            'filter_events' => ['custom_only'],
            'value_threshold' => 1000.0,
        ]);

        $service = new EventBroadcasterService($config);

        // These are in ALWAYS_BROADCAST — should pass filter even with restrictive config
        $purchaseEvent = new AnalyticsEvent(name: 'purchase', params: ['value' => 1.0]);
        $signupEvent = new AnalyticsEvent(name: 'sign_up', params: []);
        $errorEvent = new AnalyticsEvent(name: 'error', params: []);

        // All attempt broadcast (broadcaster unavailable → false, but shouldBroadcast returns true)
        $this->assertFalse($service->broadcast($purchaseEvent)); // No broadcaster
        $this->assertFalse($service->broadcast($signupEvent)); // No broadcaster
        $this->assertFalse($service->broadcast($errorEvent)); // No broadcaster
    }

    public function testBroadcasterChannelName(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.broadcast', [])->willReturn([
            'enabled' => true,
            'channel_prefix' => 'analytics.v2',
        ]);

        $service = new EventBroadcasterService($config);

        $this->assertSame('analytics.v2.events', $service->channelName('events'));
        $this->assertSame('analytics.v2.alerts', $service->channelName('alerts'));
        $this->assertSame('analytics.v2', $service->channelName());
    }

    // ── TenantIsolationService Tests ──────────────────────────────────

    public function testTenantIsolationDisabledByDefault(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.tenant', [])->willReturn([]);

        $service = new TenantIsolationService($cache, $config);

        $this->assertFalse($service->isEnabled());
        $this->assertNull($service->resolveTenantId());
    }

    public function testTenantIsolationEnabled(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.tenant', [])->willReturn([
            'enabled' => true,
            'resolution_strategy' => 'header',
            'tenant_header' => 'X-Tenant',
            'events_per_hour' => 1000,
        ]);

        $service = new TenantIsolationService($cache, $config);

        $this->assertTrue($service->isEnabled());
        $this->assertSame('user_attribute', $service->getResolutionStrategy());
        $this->assertSame([
            'enabled' => true,
            'strategy' => 'header',
            'tenants' => 0,
            'header' => 'X-Tenant',
            'rate_limit' => 1000,
        ], $service->summary());
    }

    public function testTenantEventEnrichmentReturnsUnchangedWhenDisabled(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.tenant', [])->willReturn([]);

        $service = new TenantIsolationService($cache, $config);
        $event = new AnalyticsEvent(name: 'page_view', params: []);

        $enriched = $service->enrichEvent($event);

        $this->assertSame($event, $enriched);
        $this->assertArrayNotHasKey('tenant_id', $enriched->params);
    }

    public function testTenantSetAndGetConfig(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('put')->willReturn(true);

        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.tenant', [])->willReturn([
            'enabled' => true,
            'cache_prefix' => 'zb_tenant_',
            'cache_ttl' => 3600,
        ]);

        $service = new TenantIsolationService($cache, $config);

        $service->setTenantConfig('tenant_123', ['analytics_enabled' => false, 'disabled_events' => ['error']]);

        $tenantConfig = $service->getTenantConfig('tenant_123');

        $this->assertFalse($tenantConfig['analytics_enabled']);
        $this->assertSame(['error'], $tenantConfig['disabled_events']);
    }

    public function testTenantResetConfig(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('put')->willReturn(true);
        $cache->method('forget')->willReturn(true);

        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.tenant', [])->willReturn([
            'enabled' => true,
            'cache_prefix' => 'zb_tenant_',
            'cache_ttl' => 3600,
        ]);

        $service = new TenantIsolationService($cache, $config);

        $service->setTenantConfig('tenant_456', ['analytics_enabled' => true]);
        $this->assertNotEmpty($service->getTenantConfig('tenant_456'));

        $service->resetTenantConfig('tenant_456');
        $this->assertEmpty($service->getTenantConfig('tenant_456'));
    }

    public function testTenantShouldTrackReturnsTrueWhenDisabled(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.tenant', [])->willReturn([]);

        $service = new TenantIsolationService($cache, $config);
        $event = new AnalyticsEvent(name: 'purchase', params: []);

        $this->assertTrue($service->shouldTrack($event, 'tenant_1'));
    }

    public function testTenantShouldTrackBlocksDisabledEvents(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')->willReturn(null);

        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.tenant', [])->willReturn([
            'enabled' => true,
            'overrides' => [
                'tenant_acme' => [
                    'disabled_events' => ['error', 'js_error'],
                    'analytics_enabled' => true,
                ],
            ],
            'cache_prefix' => 'zb_tenant_',
        ]);

        $service = new TenantIsolationService($cache, $config);

        $errorEvent = new AnalyticsEvent(name: 'error', params: []);
        $purchaseEvent = new AnalyticsEvent(name: 'purchase', params: []);

        $this->assertFalse($service->shouldTrack($errorEvent, 'tenant_acme'));
        $this->assertTrue($service->shouldTrack($purchaseEvent, 'tenant_acme'));
    }

    // ── DataRetentionPolicyService Tests ──────────────────────────────

    public function testRetentionDisabledByDefault(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.retention', [])->willReturn([]);

        $service = new DataRetentionPolicyService($cache, $config);

        $this->assertFalse($service->isEnabled());
    }

    public function testRetentionAllowsAllWhenDisabled(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.retention', [])->willReturn([]);

        $service = new DataRetentionPolicyService($cache, $config);
        $event = new AnalyticsEvent(name: 'page_view', params: []);

        $this->assertTrue($service->isWithinRetention($event));
    }

    public function testRetentionEnabledWithDefaultPeriods(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('put')->willReturn(true);

        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.retention', [])->willReturn([
            'enabled' => true,
            'auto_expire' => true,
            'pii_categories' => ['pii'],
        ]);

        $service = new DataRetentionPolicyService($cache, $config);

        $this->assertTrue($service->isEnabled());

        // Engagement: 30 days
        $engagementEvent = new AnalyticsEvent(name: 'page_view', params: []);
        $this->assertSame(30, $service->getRetentionDays($engagementEvent));

        // SaaS: 90 days
        $saasEvent = new AnalyticsEvent(name: 'sign_up', params: []);
        $this->assertSame(90, $service->getRetentionDays($saasEvent));

        // E-commerce: 365 days
        $ecommerceEvent = new AnalyticsEvent(name: 'purchase', params: []);
        $this->assertSame(365, $service->getRetentionDays($ecommerceEvent));

        // Custom retention period
        $service->setRetentionForCategory('engagement', 60);
        $this->assertSame(60, $service->getRetentionDays($engagementEvent));
    }

    public function testRetentionGetExpiryDate(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.retention', [])->willReturn([
            'enabled' => true,
        ]);

        $service = new DataRetentionPolicyService($cache, $config);
        $event = new AnalyticsEvent(name: 'purchase', params: []);

        $expiry = $service->getExpiryDate($event);

        $this->assertNotNull($expiry);
        $this->assertStringContainsString('T', $expiry);
    }

    public function testRetentionPeriodsAreClamped(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('put')->willReturn(true);

        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.retention', [])->willReturn([
            'enabled' => true,
            'periods' => [
                'engagement' => 0,      // Clamped to 1
                'custom' => 99999,      // Clamped to 3650
            ],
        ]);

        $service = new DataRetentionPolicyService($cache, $config);

        // 0 is clamped to minimum (1 day)
        $this->assertSame(1, $service->getRetentionForCategory('engagement'));
        // 99999 is clamped to maximum (3650 days = 10 years)
        $this->assertSame(3650, $service->getRetentionForCategory('custom'));
    }

    public function testRetentionIsPiiCategory(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.retention', [])->willReturn([
            'enabled' => true,
            'pii_categories' => ['pii', 'personal'],
        ]);

        $service = new DataRetentionPolicyService($cache, $config);

        $this->assertTrue($service->isPiiCategory('pii'));
        $this->assertTrue($service->isPiiCategory('personal'));
        $this->assertFalse($service->isPiiCategory('engagement'));
    }

    public function testRetentionSummary(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('put')->willReturn(true);
        $cache->method('get')->willReturn(0);

        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.retention', [])->willReturn([
            'enabled' => true,
            'auto_expire' => false,
            'pii_categories' => ['pii'],
        ]);

        $service = new DataRetentionPolicyService($cache, $config);
        $summary = $service->summary();

        $this->assertTrue($summary['enabled']);
        $this->assertFalse($summary['auto_expire']);
        $this->assertSame(['pii'], $summary['pii_categories']);
        $this->assertArrayHasKey('periods', $summary);
        $this->assertArrayHasKey('tracked_events', $summary);
    }

    public function testRetentionRecordEvent(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('put')->willReturn(true);
        $cache->method('get')->willReturnCallback(function (string $key, mixed $default) {
            return $key === 'zb_retention_count_engagement' ? 5 : $default;
        });

        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.retention', [])->willReturn([
            'enabled' => true,
        ]);

        $service = new DataRetentionPolicyService($cache, $config);
        $service->recordEvent(new AnalyticsEvent(name: 'page_view', params: []));

        // In-memory counter should be incremented
        $summary = $service->summary();
        $this->assertSame(1, $summary['in_memory_counters']['engagement']);
    }

    // ── AnalyticsGateService Tests ───────────────────────────────────

    public function testGateDisabledByDefault(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.gate', [])->willReturn([]);

        $service = new AnalyticsGateService($cache, $config);

        $this->assertFalse($service->isEnabled());
        // When gate is disabled, all features are allowed
        $this->assertTrue($service->allows('events'));
        $this->assertTrue($service->allows('predictions'));
        $this->assertTrue($service->allows('nonexistent_feature'));
    }

    public function testGateEnabledWithDefaultPlan(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.gate', [])->willReturn([
            'enabled' => true,
            'default_plan' => 'free',
        ]);

        $service = new AnalyticsGateService($cache, $config);

        $this->assertTrue($service->isEnabled());
        // Free plan: events and pageviews allowed
        $this->assertTrue($service->allows('events'));
        $this->assertTrue($service->allows('pageviews'));
        // Free plan: ecommerce not allowed
        $this->assertFalse($service->allows('ecommerce'));
        $this->assertFalse($service->allows('cohorts'));
        $this->assertFalse($service->allows('predictions'));
    }

    public function testGateGlobalOverride(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.gate', [])->willReturn([
            'enabled' => true,
            'default_plan' => 'free',
            'features' => [
                'ecommerce' => true, // Override: enable ecommerce even on free
            ],
        ]);

        $service = new AnalyticsGateService($cache, $config);

        // Global override enables ecommerce even on free plan
        $this->assertTrue($service->allows('ecommerce'));
        // Cohorts still disabled (no override, not in free plan)
        $this->assertFalse($service->allows('cohorts'));
    }

    public function testGateProPlanFeatures(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.gate', [])->willReturn([
            'enabled' => true,
            'default_plan' => 'pro',
        ]);

        $service = new AnalyticsGateService($cache, $config);

        // Pro plan has ecommerce, cohorts, funnels, export, alerts
        $this->assertTrue($service->allows('events'));
        $this->assertTrue($service->allows('ecommerce'));
        $this->assertTrue($service->allows('cohorts'));
        $this->assertTrue($service->allows('funnels'));
        $this->assertTrue($service->allows('export'));
        $this->assertTrue($service->allows('alerts'));
        $this->assertTrue($service->allows('profile'));
        $this->assertTrue($service->allows('attribution'));
        // But not predictions, broadcast, or multi_tenant
        $this->assertFalse($service->allows('predictions'));
        $this->assertFalse($service->allows('broadcast'));
        $this->assertFalse($service->allows('multi_tenant'));
    }

    public function testGateEnterprisePlanFeatures(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.gate', [])->willReturn([
            'enabled' => true,
            'default_plan' => 'enterprise',
        ]);

        $service = new AnalyticsGateService($cache, $config);

        // Enterprise has everything
        $this->assertTrue($service->allows('events'));
        $this->assertTrue($service->allows('ecommerce'));
        $this->assertTrue($service->allows('predictions'));
        $this->assertTrue($service->allows('broadcast'));
        $this->assertTrue($service->allows('multi_tenant'));
    }

    public function testGateUnknownFeature(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.gate', [])->willReturn([
            'enabled' => true,
            'default_plan' => 'enterprise',
        ]);

        $service = new AnalyticsGateService($cache, $config);

        // Unknown features return false when gate is enabled
        $this->assertFalse($service->allows('totally_fake_feature'));
    }

    public function testGateAllowsEventByCategory(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.gate', [])->willReturn([
            'enabled' => true,
            'default_plan' => 'free',
        ]);

        $service = new AnalyticsGateService($cache, $config);

        // page_view maps to 'pageviews' feature (allowed on free)
        $pageViewEvent = new AnalyticsEvent(name: 'page_view', params: []);
        $this->assertTrue($service->allowsEvent($pageViewEvent));

        // purchase maps to 'ecommerce' feature (not allowed on free)
        $purchaseEvent = new AnalyticsEvent(name: 'purchase', params: []);
        $this->assertFalse($service->allowsEvent($purchaseEvent));

        // Generic event maps to 'events' feature (allowed on free)
        $customEvent = new AnalyticsEvent(name: 'button_click', params: []);
        $this->assertTrue($service->allowsEvent($customEvent));
    }

    public function testGateGetAvailableFeatures(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.gate', [])->willReturn([
            'enabled' => true,
            'default_plan' => 'free',
        ]);

        $service = new AnalyticsGateService($cache, $config);
        $features = $service->getAvailableFeatures();

        $this->assertTrue($features['events']);
        $this->assertTrue($features['pageviews']);
        $this->assertFalse($features['ecommerce']);
        $this->assertFalse($features['predictions']);
    }

    public function testGateFeatureDefinitions(): void
    {
        $definitions = AnalyticsGateService::getFeatureDefinitions();

        $this->assertArrayHasKey('events', $definitions);
        $this->assertArrayHasKey('ecommerce', $definitions);
        $this->assertArrayHasKey('predictions', $definitions);
        $this->assertArrayHasKey('multi_tenant', $definitions);
        $this->assertSame('Track custom analytics events', $definitions['events']['description']);
        $this->assertTrue($definitions['events']['default']);
        $this->assertFalse($definitions['predictions']['default']);
        $this->assertSame(['cohorts', 'funnels'], $definitions['predictions']['depends_on']);
    }

    public function testGatePlanTiers(): void
    {
        $tiers = AnalyticsGateService::getPlanTiers();

        $this->assertArrayHasKey('free', $tiers);
        $this->assertArrayHasKey('pro', $tiers);
        $this->assertArrayHasKey('enterprise', $tiers);
        $this->assertArrayHasKey('starter', $tiers);
        $this->assertSame('Free', $tiers['free']['label']);
        $this->assertTrue($tiers['free']['features']['events']);
        $this->assertTrue($tiers['pro']['features']['cohorts']);
        $this->assertTrue($tiers['enterprise']['features']['predictions']);
        $this->assertTrue($tiers['enterprise']['features']['multi_tenant']);
    }

    public function testGateSetUserFeatureOverride(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('put')->willReturn(true);
        $cache->method('get')->willReturn(null);

        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.gate', [])->willReturn([
            'enabled' => true,
            'default_plan' => 'free',
            'cache_prefix' => 'zb_gate_',
            'cache_ttl' => 1800,
        ]);

        $service = new AnalyticsGateService($cache, $config);

        // Free plan doesn't have ecommerce
        $this->assertFalse($service->allows('ecommerce', 'user_123'));

        // Override for specific user
        $service->setUserFeature('user_123', 'ecommerce', true);
        $this->assertTrue($service->allows('ecommerce', 'user_123'));

        // Other users still don't have it
        $this->assertFalse($service->allows('ecommerce', 'user_456'));
    }

    public function testGateSummary(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')->with('zeroboiler.analytics.gate', [])->willReturn([
            'enabled' => true,
            'default_plan' => 'pro',
            'plan_attribute' => 'subscription_plan',
        ]);

        $service = new AnalyticsGateService($cache, $config);
        $summary = $service->summary();

        $this->assertTrue($summary['enabled']);
        $this->assertSame('pro', $summary['default_plan']);
        $this->assertSame('subscription_plan', $summary['plan_attribute']);
        $this->assertSame(12, $summary['features_count']);
        $this->assertArrayHasKey('available_features', $summary);
    }

    // ── Version Consistency ──────────────────────────────────────────

    public function testVersionConsistency(): void
    {
        // Verify all version strings reference the same version
        $composerJson = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        $version = $composerJson['version'];

        $this->assertSame('2.40.0', $version);
    }

    // ── Integration Checks ──────────────────────────────────────────

    public function testAllNewServicesAreFinal(): void
    {
        $finalClasses = [
            EventBroadcasterService::class,
            TenantIsolationService::class,
            DataRetentionPolicyService::class,
            AnalyticsGateService::class,
        ];

        foreach ($finalClasses as $class) {
            $reflection = new \ReflectionClass($class);
            $this->assertTrue(
                $reflection->isFinal(),
                $class . ' must be final',
            );
        }
    }

    public function testAllNewServicesHaveStrictTypes(): void
    {
        $serviceFiles = [
            __DIR__ . '/../src/Services/EventBroadcasterService.php',
            __DIR__ . '/../src/Services/TenantIsolationService.php',
            __DIR__ . '/../src/Services/DataRetentionPolicyService.php',
            __DIR__ . '/../src/Services/AnalyticsGateService.php',
        ];

        foreach ($serviceFiles as $file) {
            $contents = file_get_contents($file);
            $this->assertStringContainsString(
                'declare(strict_types=1);',
                $contents,
                basename($file) . ' must declare strict types',
            );
        }
    }

    public function testNewServiceFilesExist(): void
    {
        $expectedFiles = [
            __DIR__ . '/../src/Services/EventBroadcasterService.php',
            __DIR__ . '/../src/Services/TenantIsolationService.php',
            __DIR__ . '/../src/Services/DataRetentionPolicyService.php',
            __DIR__ . '/../src/Services/AnalyticsGateService.php',
        ];

        foreach ($expectedFiles as $file) {
            $this->assertFileExists($file);
            $this->assertGreaterThan(0, filesize($file));
        }
    }

    public function testServiceProviderHasNewServiceBindings(): void
    {
        $providerContent = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

        $this->assertStringContainsString('EventBroadcasterService', $providerContent);
        $this->assertStringContainsString('TenantIsolationService', $providerContent);
        $this->assertStringContainsString('DataRetentionPolicyService', $providerContent);
        $this->assertStringContainsString('AnalyticsGateService', $providerContent);
    }

    public function testNewRoutesRegistered(): void
    {
        $providerContent = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

        $this->assertStringContainsString("'analytics/broadcast'", $providerContent);
        $this->assertStringContainsString("'analytics/tenant'", $providerContent);
        $this->assertStringContainsString("'analytics/retention'", $providerContent);
        $this->assertStringContainsString("'analytics/gate'", $providerContent);
    }

    public function testConfigHasNewAccessors(): void
    {
        $configContent = file_get_contents(__DIR__ . '/../src/Support/AnalyticsConfig.php');

        $this->assertStringContainsString('broadcastEnabled', $configContent);
        $this->assertStringContainsString('broadcastChannelPrefix', $configContent);
        $this->assertStringContainsString('tenantEnabled', $configContent);
        $this->assertStringContainsString('retentionPolicyEnabled', $configContent);
        $this->assertStringContainsString('gateEnabled', $configContent);
        $this->assertStringContainsString('gateDefaultPlan', $configContent);
    }
}
