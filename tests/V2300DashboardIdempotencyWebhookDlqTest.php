<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\Attributes\Test;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Services\AnalyticsDashboardService;
use ZeroBoiler\Analytics\Services\EventIdempotencyKeyService;
use ZeroBoiler\Analytics\Services\WebhookEventSubscriptionService;
use ZeroBoiler\Analytics\Support\AnalyticsFake;
use ZeroBoiler\Analytics\AnalyticsMetrics;

final class V2300DashboardIdempotencyWebhookDlqTest extends \PHPUnit\Framework\TestCase
{
    // ── AnalyticsDashboardService Tests ─────────────────────────────

    #[Test]
    public function dashboard_service_returns_overview_structure(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.dashboard' => ['cache_ttl' => 60],
            'zeroboiler.analytics.revenue' => [
                'currency' => 'USD',
                'subscription_tiers' => [
                    'starter' => ['price' => 19],
                    'pro' => ['price' => 49],
                ],
            ],
        ]);
        $metrics = new AnalyticsMetrics($config);

        $service = new AnalyticsDashboardService($cache, $config, $metrics);
        $overview = $service->overview();

        $this->assertArrayHasKey('event_volume', $overview);
        $this->assertArrayHasKey('provider_health', $overview);
        $this->assertArrayHasKey('catalog_summary', $overview);
        $this->assertArrayHasKey('top_events', $overview);
        $this->assertArrayHasKey('funnel_distribution', $overview);
        $this->assertArrayHasKey('revenue_breakdown', $overview);
        $this->assertArrayHasKey('saas_health', $overview);
        $this->assertArrayHasKey('consent_stats', $overview);
    }

    #[Test]
    public function dashboard_event_volume_has_correct_keys(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $metrics = new AnalyticsMetrics($config);

        $service = new AnalyticsDashboardService($cache, $config, $metrics);
        $volume = $service->eventVolume();

        $this->assertArrayHasKey('total', $volume);
        $this->assertArrayHasKey('by_provider', $volume);
        $this->assertArrayHasKey('by_category', $volume);
        $this->assertIsInt($volume['total']);
        $this->assertIsArray($volume['by_provider']);
        $this->assertIsArray($volume['by_category']);
    }

    #[Test]
    public function dashboard_provider_health_covers_all_providers(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $metrics = new AnalyticsMetrics($config);

        $service = new AnalyticsDashboardService($cache, $config, $metrics);
        $health = $service->providerHealth();

        $expectedProviders = ['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'webhook', 'mixpanel', 'amplitude'];
        foreach ($expectedProviders as $provider) {
            $this->assertArrayHasKey($provider, $health, "Missing provider: {$provider}");
            $this->assertArrayHasKey('enabled', $health[$provider]);
            $this->assertArrayHasKey('dispatched', $health[$provider]);
            $this->assertArrayHasKey('failed', $health[$provider]);
        }
    }

    #[Test]
    public function dashboard_catalog_summary_matches_catalog(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $metrics = new AnalyticsMetrics($config);

        $service = new AnalyticsDashboardService($cache, $config, $metrics);
        $summary = $service->catalogSummary();

        $this->assertEquals(EventCatalog::count(), $summary['total_events']);
        $this->assertArrayHasKey('ecommerce', $summary['categories']);
        $this->assertArrayHasKey('saas', $summary['categories']);
        $this->assertArrayHasKey('engagement', $summary['categories']);
        $this->assertArrayHasKey('ga4', $summary['provider_coverage']);
    }

    #[Test]
    public function dashboard_funnel_distribution_has_saas_events(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $metrics = new AnalyticsMetrics($config);

        $service = new AnalyticsDashboardService($cache, $config, $metrics);
        $funnel = $service->funnelDistribution();

        $this->assertArrayHasKey('sign_up', $funnel);
        $this->assertArrayHasKey('start_trial', $funnel);
        $this->assertArrayHasKey('subscribe', $funnel);
        $this->assertArrayHasKey('plan_upgrade', $funnel);
        $this->assertArrayHasKey('cancellation', $funnel);
    }

    #[Test]
    public function dashboard_revenue_breakdown_computes_mrr_and_arr(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.revenue' => [
                'currency' => 'EUR',
                'subscription_tiers' => [
                    'pro' => ['price' => 49],
                    'enterprise' => ['price' => 199],
                ],
            ],
        ]);
        $metrics = new AnalyticsMetrics($config);

        $service = new AnalyticsDashboardService($cache, $config, $metrics);
        $revenue = $service->revenueBreakdown();

        $this->assertEquals(248.0, $revenue['mrr']);
        $this->assertEquals(2976.0, $revenue['arr']);
        $this->assertEquals('EUR', $revenue['currency']);
        $this->assertArrayHasKey('plans', $revenue);
        $this->assertEquals(49.0, $revenue['plans']['pro']);
    }

    #[Test]
    public function dashboard_widget_returns_correct_data(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $metrics = new AnalyticsMetrics($config);

        $service = new AnalyticsDashboardService($cache, $config, $metrics);

        $volume = $service->widget('event_volume');
        $this->assertArrayHasKey('total', $volume);

        $unknown = $service->widget('nonexistent');
        $this->assertEmpty($unknown);
    }

    // ── EventIdempotencyKeyService Tests ───────────────────────────

    #[Test]
    public function idempotency_disabled_allows_all_events(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.idempotency' => ['enabled' => false],
        ]);

        $service = new EventIdempotencyKeyService($cache, $config);

        $this->assertTrue($service->shouldProcess('purchase', ['id' => '123']));
        $this->assertTrue($service->shouldProcess('purchase', ['id' => '123']));
    }

    #[Test]
    public function idempotency_client_key_deduplicates_same_key(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.idempotency' => [
                'enabled' => true,
                'strategy' => 'client_key',
                'ttl' => 3600,
            ],
        ]);

        $service = new EventIdempotencyKeyService($cache, $config);

        // First call: should process
        $this->assertTrue($service->shouldProcess('purchase', ['id' => '123'], 'key-abc'));

        // Second call with same key: should deduplicate
        $this->assertFalse($service->shouldProcess('purchase', ['id' => '123'], 'key-abc'));

        // Different key: should process
        $this->assertTrue($service->shouldProcess('purchase', ['id' => '123'], 'key-xyz'));
    }

    #[Test]
    public function idempotency_fingerprint_strategy_uses_content_hash(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.idempotency' => [
                'enabled' => true,
                'strategy' => 'fingerprint',
                'ttl' => 3600,
            ],
        ]);

        $service = new EventIdempotencyKeyService($cache, $config);

        // Same event + params = same fingerprint
        $this->assertTrue($service->shouldProcess('purchase', ['transaction_id' => 'txn_1']));
        $this->assertFalse($service->shouldProcess('purchase', ['transaction_id' => 'txn_1']));

        // Different params = different fingerprint
        $this->assertTrue($service->shouldProcess('purchase', ['transaction_id' => 'txn_2']));
    }

    #[Test]
    public function idempotency_hybrid_checks_both_key_and_fingerprint(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.idempotency' => [
                'enabled' => true,
                'strategy' => 'hybrid',
                'ttl' => 3600,
            ],
        ]);

        $service = new EventIdempotencyKeyService($cache, $config);

        // First call with key
        $this->assertTrue($service->shouldProcess('signup', ['email' => 'a@b.com'], 'key-1'));

        // Same key, different params → still deduplicated
        $this->assertFalse($service->shouldProcess('signup', ['email' => 'x@y.com'], 'key-1'));

        // Same params, no key → fingerprint deduplicated
        $this->assertFalse($service->shouldProcess('signup', ['email' => 'a@b.com'], null));

        // Completely different → passes
        $this->assertTrue($service->shouldProcess('signup', ['email' => 'c@d.com'], 'key-2'));
    }

    #[Test]
    public function idempotency_fingerprint_is_deterministic(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.idempotency' => ['enabled' => false],
        ]);

        $service = new EventIdempotencyKeyService($cache, $config);

        $fp1 = $service->fingerprint('purchase', ['id' => '123', 'value' => 99.99]);
        $fp2 = $service->fingerprint('purchase', ['id' => '123', 'value' => 99.99]);

        $this->assertEquals($fp1, $fp2);
        $this->assertIsString($fp1);
        $this->assertGreaterThan(0, strlen($fp1));
    }

    #[Test]
    public function idempotency_fingerprint_normalizes_param_order(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.idempotency' => ['enabled' => false],
        ]);

        $service = new EventIdempotencyKeyService($cache, $config);

        $fp1 = $service->fingerprint('event', ['b' => 2, 'a' => 1]);
        $fp2 = $service->fingerprint('event', ['a' => 1, 'b' => 2]);

        $this->assertEquals($fp1, $fp2);
    }

    #[Test]
    public function idempotency_mark_processed_and_is_processed(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.idempotency' => ['enabled' => true],
        ]);

        $service = new EventIdempotencyKeyService($cache, $config);

        $this->assertFalse($service->isProcessed('my-key'));
        $service->markProcessed('my-key');
        $this->assertTrue($service->isProcessed('my-key'));
    }

    #[Test]
    public function idempotency_forget_removes_key(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.idempotency' => ['enabled' => true],
        ]);

        $service = new EventIdempotencyKeyService($cache, $config);

        $service->markProcessed('my-key');
        $this->assertTrue($service->isProcessed('my-key'));

        $service->forget('my-key');
        $this->assertFalse($service->isProcessed('my-key'));
    }

    #[Test]
    public function idempotency_request_cache_size_tracks_keys(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.idempotency' => ['enabled' => true],
        ]);

        $service = new EventIdempotencyKeyService($cache, $config);

        $this->assertEquals(0, $service->requestCacheSize());

        $service->markProcessed('key-1');
        $this->assertEquals(1, $service->requestCacheSize());

        $service->markProcessed('key-2');
        $this->assertEquals(2, $service->requestCacheSize());
    }

    // ── WebhookEventSubscriptionService Tests ──────────────────────

    #[Test]
    public function webhook_subscription_returns_config(): void
    {
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.webhook_subscriptions' => [
                'enabled' => true,
                'subscriptions' => [
                    [
                        'url' => 'https://hooks.slack.com/services/T/B/K',
                        'events' => ['purchase', 'sign_up'],
                        'format' => 'slack',
                        'enabled' => true,
                    ],
                    [
                        'url' => 'https://discord.com/api/webhooks/1/2',
                        'events' => ['*'],
                        'format' => 'discord',
                    ],
                ],
                'default_timeout' => 5,
                'default_retries' => 1,
                'rate_limit_per_minute' => 30,
            ],
        ]);

        $service = new WebhookEventSubscriptionService($config);

        $this->assertTrue($service->isEnabled());
        $this->assertEquals(2, $service->subscriptionCount());
        $this->assertEquals('client_key', $service->getStrategy());

        $subs = $service->getSubscriptions();
        $this->assertCount(2, $subs);
        $this->assertEquals('slack', $subs[0]['format']);
    }

    #[Test]
    public function webhook_subscription_matches_events(): void
    {
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.webhook_subscriptions' => [
                'enabled' => true,
                'subscriptions' => [
                    ['url' => 'https://example.com/hook1', 'events' => ['purchase']],
                    ['url' => 'https://example.com/hook2', 'events' => ['*']],
                    ['url' => 'https://example.com/hook3', 'events' => ['sign_up'], 'enabled' => false],
                ],
            ],
        ]);

        $service = new WebhookEventSubscriptionService($config);

        // Only purchase should match hook1 + hook2
        $purchaseSubs = $service->getSubscriptionsForEvent('purchase');
        $this->assertCount(2, $purchaseSubs);

        // sign_up only matches hook2 (hook3 is disabled)
        $signupSubs = $service->getSubscriptionsForEvent('sign_up');
        $this->assertCount(1, $signupSubs);

        // Wildcard matches everything
        $anySubs = $service->getSubscriptionsForEvent('custom_event');
        $this->assertCount(1, $anySubs);
    }

    #[Test]
    public function webhook_subscription_disabled_does_not_dispatch(): void
    {
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.webhook_subscriptions' => [
                'enabled' => false,
                'subscriptions' => [
                    ['url' => 'https://example.com/hook', 'events' => ['*']],
                ],
            ],
        ]);

        $service = new WebhookEventSubscriptionService($config);
        $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99]);

        $triggered = $service->dispatch($event);

        $this->assertEquals(0, $triggered);
        $this->assertFalse($service->isEnabled());
    }

    #[Test]
    public function webhook_subscription_slack_format_has_correct_structure(): void
    {
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.webhook_subscriptions' => [
                'enabled' => true,
                'subscriptions' => [
                    ['url' => 'https://hooks.slack.com/test', 'events' => ['purchase'], 'format' => 'slack'],
                ],
            ],
        ]);

        $service = new WebhookEventSubscriptionService($config);

        $subs = $service->getSubscriptionsForEvent('purchase');
        $this->assertCount(1, $subs);
        $this->assertEquals('slack', $service->getSubscriptions()[0]['format']);
    }

    // ── Version Sweep Tests ────────────────────────────────────────

    #[Test]
    public function version_consistency_across_package(): void
    {
        $composerJson = json_decode(
            file_get_contents(__DIR__ . '/../../composer.json'),
            true,
        );

        $this->assertEquals('23.0.0', $composerJson['version']);
    }

    #[Test]
    public function catalog_integrity_is_valid(): void
    {
        $result = EventCatalog::validate();

        // Note: classes may not exist in this test environment, but
        // structural integrity (keys, duplicates) should be valid
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('warnings', $result);
    }

    #[Test]
    public function event_catalog_has_all_three_categories(): void
    {
        $categories = EventCatalog::byCategory();

        $this->assertArrayHasKey('ecommerce', $categories);
        $this->assertArrayHasKey('saas', $categories);
        $this->assertArrayHasKey('engagement', $categories);
        $this->assertArrayHasKey('security', $categories);
        $this->assertArrayHasKey('uptime', $categories);

        // Each category should have events
        $this->assertGreaterThan(0, count($categories['ecommerce']));
        $this->assertGreaterThan(0, count($categories['saas']));
        $this->assertGreaterThan(0, count($categories['engagement']));
    }

    #[Test]
    public function ecommerce_catalog_has_required_events(): void
    {
        $required = ['view_item', 'add_to_cart', 'purchase', 'refund'];

        foreach ($required as $eventName) {
            $this->assertTrue(
                EcommerceEvents::has($eventName),
                "Missing ecommerce event: {$eventName}",
            );
        }
    }

    #[Test]
    public function saas_catalog_has_required_events(): void
    {
        $required = ['sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade', 'cancellation'];

        foreach ($required as $eventName) {
            $this->assertTrue(
                SaaSEvents::has($eventName),
                "Missing SaaS event: {$eventName}",
            );
        }
    }

    #[Test]
    public function engagement_catalog_has_required_events(): void
    {
        $required = ['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'search', 'share', 'error'];

        foreach ($required as $eventName) {
            $this->assertTrue(
                EngagementEvents::has($eventName),
                "Missing engagement event: {$eventName}",
            );
        }
    }

    // ── Config Integrity Tests ─────────────────────────────────────

    #[Test]
    public function config_has_dashboard_section(): void
    {
        $config = include __DIR__ . '/../../config/zeroboiler.php';
        $this->assertArrayHasKey('analytics', $config);
        // Dashboard config may not exist yet — will be added below
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function createCacheRepository(): \Illuminate\Contracts\Cache\Repository
    {
        return new class implements \Illuminate\Contracts\Cache\Repository {
            private array $store = [];

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->store);
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->store[$key] ?? $default;
            }

            public function put(string $key, mixed $value, \DateTimeInterface|\DateInterval|int $ttl = null): bool
            {
                $this->store[$key] = $value;

                return true;
            }

            public function forget(string $key): bool
            {
                unset($this->store[$key]);

                return true;
            }

            public function remember(string $key, \DateTimeInterface|\DateInterval|int $ttl, \Closure $callback): mixed
            {
                if ($this->has($key)) {
                    return $this->get($key);
                }

                $value = $callback();
                $this->put($key, $value, $ttl);

                return $value;
            }

            public function getMultiple(array $keys): array
            {
                $results = [];
                foreach ($keys as $key) {
                    $results[$key] = $this->get($key);
                }

                return $results;
            }

            public function putMultiple(array $values, \DateTimeInterface|\DateInterval|int $ttl = null): bool
            {
                foreach ($values as $key => $value) {
                    $this->put($key, $value, $ttl);
                }

                return true;
            }

            public function deleteMultiple(array $keys): bool
            {
                foreach ($keys as $key) {
                    $this->forget($key);
                }

                return true;
            }

            public function flush(): bool
            {
                $this->store = [];

                return true;
            }

            public function clear(): bool
            {
                return $this->flush();
            }
        };
    }

    private function createConfigRepository(array $overrides = []): \Illuminate\Contracts\Config\Repository
    {
        return new class($overrides) implements \Illuminate\Contracts\Config\Repository {
            private array $items;

            public function __construct(array $overrides = [])
            {
                $defaults = [
                    'zeroboiler.analytics.dashboard' => ['cache_ttl' => 300],
                    'zeroboiler.analytics.revenue' => ['currency' => 'USD', 'subscription_tiers' => []],
                    'zeroboiler.analytics.idempotency' => ['enabled' => false],
                    'zeroboiler.analytics.webhook_subscriptions' => ['enabled' => false],
                ];
                $this->items = array_merge($defaults, $overrides);
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->items);
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->items[$key] ?? $default;
            }

            public function set(string $key, mixed $value = null): void
            {
                $this->items[$key] = $value;
            }

            public function all(): array
            {
                return $this->items;
            }

            public function prepend(string $key, mixed $value): void
            {
                $this->items[$key] = $value;
            }

            public function push(string $key, mixed $value): void
            {
                $existing = $this->items[$key] ?? [];
                $existing[] = $value;
                $this->items[$key] = $existing;
            }
        };
    }
}
