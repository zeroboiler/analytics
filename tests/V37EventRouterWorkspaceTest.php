<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventRouterService;
use ZeroBoiler\Analytics\Services\AnalyticsWorkspaceService;

/**
 * Tests for EventRouterService and AnalyticsWorkspaceService (v37.0.0).
 *
 * @covers \ZeroBoiler\Analytics\Services\EventRouterService
 * @covers \ZeroBoiler\Analytics\Services\AnalyticsWorkspaceService
 */
final class V37EventRouterWorkspaceTest extends \PHPUnit\Framework\TestCase
{
    // ─── EventRouterService Tests ───────────────────────────────────────

    public function test_router_all_providers_constant_contains_all_ten(): void
    {
        $providers = EventRouterService::allProviders();

        $this->assertCount(10, $providers);
        $this->assertContains('ga4', $providers);
        $this->assertContains('gtm', $providers);
        $this->assertContains('meta_pixel', $providers);
        $this->assertContains('plausible', $providers);
        $this->assertContains('posthog', $providers);
        $this->assertContains('mixpanel', $providers);
        $this->assertContains('amplitude', $providers);
        $this->assertContains('webhook', $providers);
        $this->assertContains('tiktok', $providers);
        $this->assertContains('linkedin', $providers);
    }

    public function test_router_is_disabled_by_default(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')
            ->with('zeroboiler.analytics.event_router')
            ->willReturn([]);

        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $router = new EventRouterService($config, $cache);

        $this->assertFalse($router->isEnabled());
    }

    public function test_router_can_be_enabled_via_config(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')
            ->with('zeroboiler.analytics.event_router')
            ->willReturn(['enabled' => true]);

        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $router = new EventRouterService($config, $cache);

        $this->assertTrue($router->isEnabled());
    }

    public function test_router_category_routes_filter_providers(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')
            ->with('zeroboiler.analytics.event_router')
            ->willReturn([
                'enabled' => true,
                'category_routes' => [
                    'ecommerce' => ['ga4', 'meta_pixel'],
                ],
            ]);

        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $router = new EventRouterService($config, $cache);

        $manager = $this->createMockAnalyticsManager(['ga4', 'meta_pixel', 'posthog']);
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 99.99],
            clientId: 'client-123',
        );

        $routed = $router->route($event, $manager);

        $this->assertContains('ga4', $routed);
        $this->assertContains('meta_pixel', $routed);
        $this->assertNotContains('posthog', $routed);
    }

    public function test_router_deny_list_blocks_providers(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')
            ->with('zeroboiler.analytics.event_router')
            ->willReturn([
                'enabled' => true,
                'deny_list' => [
                    'scroll_depth' => ['meta_pixel', 'tiktok'],
                ],
            ]);

        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $router = new EventRouterService($config, $cache);

        $manager = $this->createMockAnalyticsManager(['ga4', 'meta_pixel', 'tiktok', 'posthog']);
        $event = new AnalyticsEvent(
            name: 'scroll_depth',
            params: ['depth' => 75],
            clientId: 'client-123',
        );

        $routed = $router->route($event, $manager);

        $this->assertContains('ga4', $routed);
        $this->assertContains('posthog', $routed);
        $this->assertNotContains('meta_pixel', $routed);
        $this->assertNotContains('tiktok', $routed);
    }

    public function test_router_allow_list_restricts_providers(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')
            ->with('zeroboiler.analytics.event_router')
            ->willReturn([
                'enabled' => true,
                'allow_list' => [
                    'purchase' => ['ga4', 'meta_pixel'],
                ],
            ]);

        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $router = new EventRouterService($config, $cache);

        $manager = $this->createMockAnalyticsManager(['ga4', 'meta_pixel', 'posthog', 'mixpanel']);
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 49.99],
            clientId: 'client-123',
        );

        $routed = $router->route($event, $manager);

        $this->assertContains('ga4', $routed);
        $this->assertContains('meta_pixel', $routed);
        $this->assertNotContains('posthog', $routed);
        $this->assertNotContains('mixpanel', $routed);
    }

    public function test_router_cost_optimization_excludes_expensive_providers(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')
            ->with('zeroboiler.analytics.event_router')
            ->willReturn([
                'enabled' => true,
                'cost_optimized' => true,
                'cost_threshold' => 0.3,
            ]);

        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $router = new EventRouterService($config, $cache);

        $manager = $this->createMockAnalyticsManager(['ga4', 'posthog', 'mixpanel']);
        $event = new AnalyticsEvent(
            name: 'scroll_depth',
            params: ['depth' => 50],
            clientId: 'client-123',
            priority: 'low',
        );

        $routed = $router->route($event, $manager);

        // ga4 cost = 0.2 (≤ 0.3), posthog cost = 0.5 (> 0.3), mixpanel cost = 0.45 (> 0.3)
        $this->assertContains('ga4', $routed);
        $this->assertNotContains('posthog', $routed);
        $this->assertNotContains('mixpanel', $routed);
    }

    public function test_router_cost_optimization_does_not_affect_critical_events(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')
            ->with('zeroboiler.analytics.event_router')
            ->willReturn([
                'enabled' => true,
                'cost_optimized' => true,
                'cost_threshold' => 0.1,
            ]);

        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $router = new EventRouterService($config, $cache);

        $manager = $this->createMockAnalyticsManager(['ga4', 'posthog']);
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 199.99],
            clientId: 'client-123',
            priority: 'critical',
        );

        $routed = $router->route($event, $manager);

        // Critical events should NOT be cost-optimized
        $this->assertContains('ga4', $routed);
        $this->assertContains('posthog', $routed);
    }

    public function test_router_routing_summary(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')
            ->with('zeroboiler.analytics.event_router')
            ->willReturn([
                'enabled' => true,
                'category_routes' => ['ecommerce' => ['ga4']],
                'pattern_rules' => [['pattern' => 'scroll_*', 'providers' => ['ga4'], 'type' => 'glob']],
                'deny_list' => ['hover' => ['meta_pixel']],
            ]);

        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $router = new EventRouterService($config, $cache);

        $summary = $router->getRoutingSummary();

        $this->assertTrue($summary['enabled']);
        $this->assertCount(1, $summary['category_routes']);
        $this->assertEquals(1, $summary['pattern_rules_count']);
        $this->assertEquals(1, $summary['deny_list_count']);
    }

    public function test_router_validate_rules_detects_unknown_provider(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')
            ->with('zeroboiler.analytics.event_router')
            ->willReturn([
                'enabled' => true,
                'category_routes' => [
                    'ecommerce' => ['ga4', 'nonexistent_provider'],
                ],
            ]);

        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $router = new EventRouterService($config, $cache);

        $result = $router->validateRules();

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('nonexistent_provider', $result['errors'][0]);
    }

    public function test_router_validate_rules_detects_empty_defaults(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')
            ->with('zeroboiler.analytics.event_router')
            ->willReturn([
                'enabled' => true,
                'default_providers' => [],
            ]);

        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $router = new EventRouterService($config, $cache);

        $result = $router->validateRules();

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('empty', $result['errors'][0]);
    }

    public function test_router_route_with_reasoning(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')
            ->with('zeroboiler.analytics.event_router')
            ->willReturn([
                'enabled' => true,
                'deny_list' => [
                    'scroll_depth' => ['meta_pixel'],
                ],
            ]);

        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $router = new EventRouterService($config, $cache);

        $manager = $this->createMockAnalyticsManager(['ga4', 'meta_pixel']);
        $event = new AnalyticsEvent(
            name: 'scroll_depth',
            params: ['depth' => 50],
            clientId: 'client-123',
        );

        $result = $router->routeWithReasoning($event, $manager);

        $this->assertArrayHasKey('providers', $result);
        $this->assertArrayHasKey('rules_applied', $result);
        $this->assertArrayHasKey('dropped', $result);
        $this->assertFalse($result['dropped']);
        $this->assertContains('deny_list', $result['rules_applied']);
        $this->assertContains('ga4', $result['providers']);
        $this->assertNotContains('meta_pixel', $result['providers']);
    }

    public function test_router_should_send_to(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')
            ->with('zeroboiler.analytics.event_router')
            ->willReturn(['enabled' => false]);

        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $router = new EventRouterService($config, $cache);

        $manager = $this->createMockAnalyticsManager(['ga4', 'posthog']);
        $event = new AnalyticsEvent(
            name: 'page_view',
            params: [],
            clientId: 'client-123',
        );

        $this->assertTrue($router->shouldSendTo($event, 'ga4', $manager));
        $this->assertFalse($router->shouldSendTo($event, 'meta_pixel', $manager));
    }

    public function test_router_cache_operations(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')
            ->with('zeroboiler.analytics.event_router')
            ->willReturn(['enabled' => true]);

        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $router = new EventRouterService($config, $cache);

        // Cache miss
        $cache->method('get')
            ->with('zb_router_page_view')
            ->willReturn(null);

        $this->assertNull($router->getCachedRoute('page_view'));

        // Cache put
        $cache->expects($this->once())
            ->method('put')
            ->with('zb_router_page_view', ['ga4', 'posthog'], 300);

        $router->cacheRoute('page_view', ['ga4', 'posthog']);
    }

    // ─── AnalyticsWorkspaceService Tests ────────────────────────────────

    public function test_workspace_is_disabled_by_default(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')
            ->with('zeroboiler.analytics.workspace')
            ->willReturn([]);

        $workspace = new AnalyticsWorkspaceService($cache, $config);

        $this->assertFalse($workspace->isEnabled());
    }

    public function test_workspace_can_be_enabled(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')
            ->with('zeroboiler.analytics.workspace')
            ->willReturn(['enabled' => true]);

        $workspace = new AnalyticsWorkspaceService($cache, $config);

        $this->assertTrue($workspace->isEnabled());
    }

    public function test_workspace_overview_returns_full_summary(): void
    {
        $cacheData = [
            'zb_workspace_ws-001:users:1d' => 42,
            'zb_workspace_ws-001:users:7d' => 120,
            'zb_workspace_ws-001:users:30d' => 350,
            'zb_workspace_ws-001:total_events' => 1500,
            'zb_workspace_ws-001:top_events' => [
                'page_view' => 500,
                'click' => 300,
                'feature_used' => 200,
            ],
            'zb_workspace_ws-001:revenue:total' => 5000.0,
            'zb_workspace_ws-001:revenue:mrr' => 1200.0,
        ];

        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')
            ->willReturnCallback(fn (string $key) => $cacheData[$key] ?? null);

        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')
            ->with('zeroboiler.analytics.workspace')
            ->willReturn(['enabled' => true, 'funnels' => []]);

        $workspace = new AnalyticsWorkspaceService($cache, $config);
        $overview = $workspace->getOverview('ws-001');

        $this->assertEquals('ws-001', $overview['workspace_id']);
        $this->assertEquals(42, $overview['active_users']['dau']);
        $this->assertEquals(120, $overview['active_users']['wau']);
        $this->assertEquals(350, $overview['active_users']['mau']);
        $this->assertEquals(1500, $overview['total_events']);
        $this->assertEquals(5000.0, $overview['revenue']['total']);
        $this->assertEquals(1200.0, $overview['revenue']['mrr']);
        $this->assertArrayHasKey('computed_at', $overview);
        $this->assertNotEmpty($overview['top_events']);
    }

    public function test_workspace_overview_empty_workspace(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')->willReturn(null);

        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')
            ->with('zeroboiler.analytics.workspace')
            ->willReturn(['enabled' => true, 'funnels' => []]);

        $workspace = new AnalyticsWorkspaceService($cache, $config);
        $overview = $workspace->getOverview('ws-empty');

        $this->assertEquals(0, $overview['active_users']['dau']);
        $this->assertEquals(0, $overview['total_events']);
        $this->assertEquals(0.0, $overview['revenue']['total']);
        $this->assertEquals(0.0, $overview['engagement_score']);
    }

    public function test_workspace_engagement_score(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')
            ->willReturnMap([
                ['zb_workspace_ws-001:total_events', 250],
                ['zb_workspace_ws-001:user_set', ['user1' => time(), 'user2' => time()]],
            ]);

        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')
            ->with('zeroboiler.analytics.workspace')
            ->willReturn(['enabled' => true, 'funnels' => []]);

        $workspace = new AnalyticsWorkspaceService($cache, $config);
        $overview = $workspace->getOverview('ws-001');

        // 2 users, 250 events, 125 events/user → 125/50 * 100 = 250 → capped at 100
        $this->assertEquals(100.0, $overview['engagement_score']);
    }

    public function test_workspace_top_events_sorted_by_count(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')
            ->with('zb_workspace_ws-001:top_events')
            ->willReturn([
                'page_view' => 100,
                'click' => 500,
                'feature_used' => 50,
            ]);

        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')
            ->with('zeroboiler.analytics.workspace')
            ->willReturn(['enabled' => true]);

        $workspace = new AnalyticsWorkspaceService($cache, $config);
        $top = $workspace->getTopEvents('ws-001', 10);

        $this->assertCount(3, $top);
        $this->assertEquals('click', $top[0]['name']);
        $this->assertEquals(500, $top[0]['count']);
        $this->assertEquals('page_view', $top[1]['name']);
        $this->assertEquals('feature_used', $top[2]['name']);
    }

    public function test_workspace_compare_sorts_by_engagement(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('get')
            ->willReturnCallback(function (string $key) {
                if (str_contains($key, ':total_events')) {
                    return str_contains($key, 'ws-high') ? 1000 : 100;
                }
                if (str_contains($key, ':user_set')) {
                    return str_contains($key, 'ws-high')
                        ? ['u1' => time(), 'u2' => time()]
                        : ['u1' => time()];
                }
                return null;
            });

        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')
            ->with('zeroboiler.analytics.workspace')
            ->willReturn(['enabled' => true, 'funnels' => []]);

        $workspace = new AnalyticsWorkspaceService($cache, $config);
        $comparison = $workspace->compareWorkspaces(['ws-low', 'ws-high']);

        $this->assertCount(2, $comparison);
        // ws-high should be first (higher engagement)
        $this->assertEquals('ws-high', $comparison[0]['workspace_id']);
    }

    public function test_workspace_config_summary(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')
            ->with('zeroboiler.analytics.workspace')
            ->willReturn([
                'enabled' => true,
                'cache_ttl' => 7200,
                'max_events_per_summary' => 2000,
                'engagement_events' => ['page_view', 'click'],
                'funnels' => ['signup' => ['name' => 'Signup', 'steps' => ['sign_up']]],
            ]);

        $workspace = new AnalyticsWorkspaceService($cache, $config);
        $summary = $workspace->getConfigSummary();

        $this->assertTrue($summary['enabled']);
        $this->assertEquals(7200, $summary['cache_ttl']);
        $this->assertEquals(2000, $summary['max_events_per_summary']);
        $this->assertEquals(2, $summary['engagement_events_count']);
        $this->assertEquals(1, $summary['funnels_count']);
    }

    public function test_workspace_record_event_disabled_does_nothing(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->expects($this->never())->method('put');

        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $config->method('get')
            ->with('zeroboiler.analytics.workspace')
            ->willReturn(['enabled' => false]);

        $workspace = new AnalyticsWorkspaceService($cache, $config);

        $event = new AnalyticsEvent(
            name: 'page_view',
            params: [],
            clientId: 'client-123',
        );

        // Should silently return without error
        $workspace->recordEvent($event, 'ws-001');
        $this->assertTrue(true); // No exception thrown
    }

    public function test_version_is_37(): void
    {
        $this->assertSame('37.0.0', AnalyticsEvent::VERSION);
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    /**
     * Create a mock AnalyticsManager with specified enabled providers.
     *
     * @param  list<string>  $enabledProviders
     * @return \ZeroBoiler\Analytics\AnalyticsManager
     */
    private function createMockAnalyticsManager(array $enabledProviders): \ZeroBoiler\Analytics\AnalyticsManager
    {
        $ga4 = $this->createMock(\ZeroBoiler\Analytics\Trackers\GA4Tracker::class);
        $ga4->method('isEnabled')->willReturn(in_array('ga4', $enabledProviders, true));

        $gtm = $this->createMock(\ZeroBoiler\Analytics\Trackers\GTMTracker::class);
        $gtm->method('isEnabled')->willReturn(in_array('gtm', $enabledProviders, true));

        $meta = $this->createMock(\ZeroBoiler\Analytics\Trackers\MetaPixelTracker::class);
        $meta->method('isEnabled')->willReturn(in_array('meta_pixel', $enabledProviders, true));

        $plausible = $this->createMock(\ZeroBoiler\Analytics\Trackers\PlausibleTracker::class);
        $plausible->method('isEnabled')->willReturn(in_array('plausible', $enabledProviders, true));

        $posthog = $this->createMock(\ZeroBoiler\Analytics\Trackers\PosthogTracker::class);
        $posthog->method('isEnabled')->willReturn(in_array('posthog', $enabledProviders, true));

        $mixpanel = $this->createMock(\ZeroBoiler\Analytics\Trackers\MixpanelTracker::class);
        $mixpanel->method('isEnabled')->willReturn(in_array('mixpanel', $enabledProviders, true));

        $amplitude = $this->createMock(\ZeroBoiler\Analytics\Trackers\AmplitudeTracker::class);
        $amplitude->method('isEnabled')->willReturn(in_array('amplitude', $enabledProviders, true));

        $webhook = $this->createMock(\ZeroBoiler\Analytics\Trackers\WebhookTracker::class);
        $webhook->method('isEnabled')->willReturn(in_array('webhook', $enabledProviders, true));

        $tiktok = $this->createMock(\ZeroBoiler\Analytics\Trackers\TikTokTracker::class);
        $tiktok->method('isEnabled')->willReturn(in_array('tiktok', $enabledProviders, true));

        $linkedin = $this->createMock(\ZeroBoiler\Analytics\Trackers\LinkedInTracker::class);
        $linkedin->method('isEnabled')->willReturn(in_array('linkedin', $enabledProviders, true));

        // Use reflection to set the private tracker properties
        $manager = new class extends \ZeroBoiler\Analytics\AnalyticsManager {
            public function __construct() {}
        };

        $ref = new \ReflectionClass($manager);

        $this->setPrivateProperty($manager, 'ga4', $ga4);
        $this->setPrivateProperty($manager, 'gtm', $gtm);
        $this->setPrivateProperty($manager, 'meta', $meta);
        $this->setPrivateProperty($manager, 'plausible', $plausible);
        $this->setPrivateProperty($manager, 'posthog', $posthog);
        $this->setPrivateProperty($manager, 'mixpanel', $mixpanel);
        $this->setPrivateProperty($manager, 'amplitude', $amplitude);
        $this->setPrivateProperty($manager, 'webhook', $webhook);
        $this->setPrivateProperty($manager, 'tiktok', $tiktok);
        $this->setPrivateProperty($manager, 'linkedin', $linkedin);

        return $manager;
    }

    /**
     * Set a private/protected property via reflection.
     */
    private function setPrivateProperty(object $obj, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj, $property);
        $ref->setAccessible(true);
        $ref->setValue($obj, $value);
    }
}
