<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;
use ZeroBoiler\Analytics\Events\Infrastructure\InfrastructureEvents;
use ZeroBoiler\Analytics\Events\Marketing\MarketingEvents;
use ZeroBoiler\Analytics\Events\SaaS\CustomerSuccessEvents;
use ZeroBoiler\Analytics\Services\EventParameterSchemaValidator;
use ZeroBoiler\Analytics\Services\SaaSLifecycleFlowTracker;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;
use ZeroBoiler\Analytics\Support\EventBuilder;

/**
 * Phase 40 Production Readiness Audit — SaaS Industry-Standard Analytics v150.0.0
 *
 * Comprehensive audit covering all 12 planned SaaS analytics features plus
 * v149 additions (SaaSLifecycleFlowTracker, EventParameterSchemaValidator).
 *
 * @since 150.0.0
 */
final class V149ProductionReadinessAuditTest extends TestCase
{
    // ── 1. Event Catalog Completeness ───────────────────────────────────

    public function test_event_catalog_has_all_eight_categories(): void
    {
        $byCategory = EventCatalog::byCategory();

        $this->assertArrayHasKey('ecommerce', $byCategory);
        $this->assertArrayHasKey('saas', $byCategory);
        $this->assertArrayHasKey('engagement', $byCategory);
        $this->assertArrayHasKey('security', $byCategory);
        $this->assertArrayHasKey('uptime', $byCategory);
        $this->assertArrayHasKey('infrastructure', $byCategory);
        $this->assertArrayHasKey('marketing', $byCategory);
        $this->assertArrayHasKey('customer_success', $byCategory);
        $this->assertCount(8, $byCategory);
    }

    public function test_event_catalog_has_minimum_210_events(): void
    {
        $this->assertGreaterThanOrEqual(210, EventCatalog::count());
    }

    // ── 2. E-commerce Catalog: ViewItem, AddToCart, Purchase, Refund ────

    public function test_ecommerce_catalog_has_core_events(): void
    {
        $coreEvents = ['view_item', 'add_to_cart', 'purchase', 'refund'];
        foreach ($coreEvents as $eventName) {
            $this->assertTrue(EcommerceEvents::has($eventName), "Missing ecommerce event: {$eventName}");
            $entry = EcommerceEvents::get($eventName);
            $this->assertNotNull($entry, "Ecommerce entry null for: {$eventName}");
            $this->assertNotEmpty($entry['ga4'], "GA4 mapping missing for: {$eventName}");
            $this->assertNotEmpty($entry['class'], "Class mapping missing for: {$eventName}");
        }
    }

    // ── 3. SaaS Catalog: SignUp, Login, TrialStart, Subscription, etc ───

    public function test_saas_catalog_has_core_lifecycle_events(): void
    {
        $coreEvents = ['sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade', 'cancellation'];
        foreach ($coreEvents as $eventName) {
            $this->assertTrue(SaaSEvents::has($eventName), "Missing SaaS event: {$eventName}");
            $entry = SaaSEvents::get($eventName);
            $this->assertNotNull($entry, "SaaS entry null for: {$eventName}");
            $this->assertNotEmpty($entry['ga4'], "GA4 mapping missing for: {$eventName}");
            $this->assertNotEmpty($entry['class'], "Class mapping missing for: {$eventName}");
            $this->assertNotEmpty($entry['posthog'], "PostHog mapping missing for: {$eventName}");
        }
    }

    // ── 4. Engagement Catalog: PageView, ScrollDepth, Click, Form, etc ──

    public function test_engagement_catalog_has_core_events(): void
    {
        $coreEvents = ['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'search', 'share', 'error'];
        foreach ($coreEvents as $eventName) {
            $this->assertTrue(EngagementEvents::has($eventName), "Missing engagement event: {$eventName}");
            $entry = EngagementEvents::get($eventName);
            $this->assertNotNull($entry, "Engagement entry null for: {$eventName}");
        }
    }

    // ── 5. Provider Mappings (GA4, Meta, PostHog, Plausible) ────────────

    public function test_core_events_have_provider_mappings(): void
    {
        $testEvents = ['view_item', 'purchase', 'sign_up', 'page_view', 'login'];
        foreach ($testEvents as $eventName) {
            $entry = EventCatalog::get($eventName);
            $this->assertNotNull($entry, "No catalog entry for: {$eventName}");
            $this->assertNotEmpty($entry['ga4'], "GA4 mapping missing for: {$eventName}");
        }
    }

    public function test_event_catalog_has_all_provider_names(): void
    {
        $ga4Names = EventCatalog::allGa4Names();
        $metaNames = EventCatalog::allMetaNames();
        $posthogNames = EventCatalog::allPosthogNames();

        $this->assertNotEmpty($ga4Names);
        $this->assertNotEmpty($metaNames);
        $this->assertNotEmpty($posthogNames);
    }

    // ── 6. Event Name Resolution ────────────────────────────────────────

    public function test_event_catalog_resolves_snake_case(): void
    {
        $this->assertSame('view_item', EventCatalog::resolve('view_item'));
        $this->assertSame('sign_up', EventCatalog::resolve('sign_up'));
        $this->assertSame('page_view', EventCatalog::resolve('page_view'));
    }

    public function test_event_catalog_resolves_camel_case(): void
    {
        $this->assertSame('view_item', EventCatalog::resolve('ViewItem'));
        $this->assertSame('add_to_cart', EventCatalog::resolve('AddToCart'));
        $this->assertSame('sign_up', EventCatalog::resolve('SignUp'));
        $this->assertSame('page_view', EventCatalog::resolve('PageView'));
    }

    public function test_event_catalog_resolves_unknown_returns_null(): void
    {
        $this->assertNull(EventCatalog::resolve('nonexistent_event_xyz'));
    }

    // ── 7. EventBuilder Fluent API ──────────────────────────────────────

    public function test_event_builder_creates_events_fluently(): void
    {
        $event = EventBuilder::make('purchase')
            ->param('transaction_id', 'TXN-123')
            ->param('value', 99.99)
            ->param('currency', 'USD')
            ->client('client-abc')
            ->user('user-123')
            ->build();

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('purchase', $event->name);
        $this->assertSame('TXN-123', $event->params['transaction_id']);
        $this->assertSame(99.99, $event->params['value']);
        $this->assertSame('USD', $event->params['currency']);
        $this->assertSame('client-abc', $event->clientId);
        $this->assertSame('user-123', $event->userId);
    }

    // ── 8. EcommerceFormatConverter GA4↔Meta ─────────────────────────────

    public function test_ecommerce_converter_ga4_to_meta(): void
    {
        $ga4Items = [
            ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2, 'item_category' => 'Electronics'],
            ['item_id' => 'SKU-002', 'item_name' => 'Gadget', 'price' => 29.99, 'quantity' => 1, 'item_category' => 'Accessories'],
        ];

        $result = EcommerceFormatConverter::ga4ToMetaContents($ga4Items);

        $this->assertCount(2, $result['contents']);
        $this->assertCount(2, $result['content_ids']);
        $this->assertSame(2, $result['num_items']);
        $this->assertEqualsWithDelta(129.97, $result['value'], 0.01);
        $this->assertSame('SKU-001', $result['contents'][0]['id']);
        $this->assertSame(2, $result['contents'][0]['quantity']);
    }

    public function test_ecommerce_converter_meta_to_ga4(): void
    {
        $metaContents = [
            ['id' => 'SKU-001', 'quantity' => 3, 'item_price' => 19.99, 'item_name' => 'Thing'],
        ];

        $result = EcommerceFormatConverter::metaToGa4Items($metaContents);

        $this->assertCount(1, $result);
        $this->assertSame('SKU-001', $result[0]['item_id']);
        $this->assertSame(3, $result[0]['quantity']);
        $this->assertEqualsWithDelta(19.99, $result[0]['price'], 0.01);
    }

    // ── 9. AnalyticsEvent DTO ────────────────────────────────────────────

    public function test_analytics_event_version(): void
    {
        $this->assertSame('150.0.0', AnalyticsEvent::VERSION);
    }

    public function test_analytics_event_with_category(): void
    {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 99.99],
            category: 'ecommerce',
            sessionId: 'sess-abc',
        );

        $this->assertSame('purchase', $event->name);
        $this->assertSame('ecommerce', $event->category);
        $this->assertSame('sess-abc', $event->sessionId);
    }

    public function test_analytics_event_from_array(): void
    {
        $event = AnalyticsEvent::fromArray([
            'name' => 'sign_up',
            'params' => ['method' => 'google'],
            'client_id' => 'cid-1',
            'user_id' => 'uid-1',
            'priority' => 'critical',
            'source' => 'server',
            'category' => 'saas',
        ]);

        $this->assertSame('sign_up', $event->name);
        $this->assertSame('google', $event->params['method']);
        $this->assertSame('cid-1', $event->clientId);
        $this->assertSame('uid-1', $event->userId);
        $this->assertSame('critical', $event->priority);
        $this->assertSame('server', $event->source);
        $this->assertSame('saas', $event->category);
    }

    // ── 10. EventParameterSchemaValidator ────────────────────────────────

    public function test_schema_validator_validates_purchase_event(): void
    {
        $validator = new EventParameterSchemaValidator(strictMode: false);

        $validEvent = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'TXN-123',
                'currency' => 'USD',
                'value' => 99.99,
            ],
        );

        $result = $validator->validate($validEvent);
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_schema_validator_catches_missing_required_params(): void
    {
        $validator = new EventParameterSchemaValidator(strictMode: false);

        $invalidEvent = new AnalyticsEvent(
            name: 'purchase',
            params: ['currency' => 'USD'],
            // Missing: transaction_id, value
        );

        $result = $validator->validate($invalidEvent);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('transaction_id', $result['errors'][0]);
    }

    public function test_schema_validator_catches_type_mismatch(): void
    {
        $validator = new EventParameterSchemaValidator(strictMode: false);

        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'TXN-123',
                'currency' => 'USD',
                'value' => 'not-a-number', // Should be float
            ],
        );

        $result = $validator->validate($event);
        $this->assertFalse($result['valid']);
        $typeErrors = array_filter($result['errors'], fn (string $e): bool => str_contains($e, 'type'));
        $this->assertNotEmpty($typeErrors);
    }

    public function test_schema_validator_detects_pii_params(): void
    {
        $validator = new EventParameterSchemaValidator(strictMode: false);

        $event = new AnalyticsEvent(
            name: 'login',
            params: [
                'method' => 'email',
                'password' => 'secret123', // PII violation
            ],
        );

        $result = $validator->validate($event);
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['pii_violations']);
    }

    public function test_schema_validator_warns_on_unknown_events(): void
    {
        $validator = new EventParameterSchemaValidator(strictMode: false);

        $event = new AnalyticsEvent(
            name: 'custom_unknown_event',
            params: ['foo' => 'bar'],
        );

        $result = $validator->validate($event);
        // Should not be valid in strict mode but pass with warnings in non-strict
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_schema_validator_warns_on_enum_violation(): void
    {
        $validator = new EventParameterSchemaValidator(strictMode: false);

        $event = new AnalyticsEvent(
            name: 'subscribe',
            params: [
                'plan' => 'enterprise',
                'billing_cycle' => 'hourly', // Not in enum: monthly, yearly, weekly, lifetime
            ],
        );

        $result = $validator->validate($event);
        $this->assertTrue($result['valid']); // Enum violations are warnings, not errors
        $this->assertNotEmpty($result['warnings']);
        $enumWarnings = array_filter($result['warnings'], fn (string $w): bool => str_contains($w, 'allowed set'));
        $this->assertNotEmpty($enumWarnings);
    }

    public function test_schema_validator_custom_schema_registration(): void
    {
        $validator = new EventParameterSchemaValidator();

        $validator->registerSchema('my_custom_event', [
            'widget_id' => ['required' => true, 'type' => 'string'],
            'score' => ['required' => false, 'type' => 'float', 'max_length' => 100],
        ]);

        $this->assertContains('my_custom_event', $validator->getSchemaEventNames());

        // Valid custom event
        $result = $validator->validate(new AnalyticsEvent(
            name: 'my_custom_event',
            params: ['widget_id' => 'W-001', 'score' => 95.5],
        ));
        $this->assertTrue($result['valid']);

        // Missing required
        $result2 = $validator->validate(new AnalyticsEvent(
            name: 'my_custom_event',
            params: ['score' => 95.5],
        ));
        $this->assertFalse($result2['valid']);
    }

    public function test_schema_validator_batch_validation(): void
    {
        $validator = new EventParameterSchemaValidator(strictMode: false);

        $events = [
            new AnalyticsEvent(name: 'purchase', params: ['transaction_id' => 'T1', 'currency' => 'USD', 'value' => 10.0]),
            new AnalyticsEvent(name: 'sign_up', params: ['method' => 'google']),
            new AnalyticsEvent(name: 'purchase', params: ['currency' => 'USD']), // Missing required
        ];

        $result = $validator->validateBatch($events);
        $this->assertSame(3, $result['total']);
        $this->assertSame(2, $result['valid']);
        $this->assertSame(1, $result['invalid']);
    }

    public function test_schema_validator_diagnostic_summary(): void
    {
        $validator = new EventParameterSchemaValidator();
        $summary = $validator->diagnosticSummary();

        $this->assertArrayHasKey('builtin_schema_count', $summary);
        $this->assertArrayHasKey('custom_schema_count', $summary);
        $this->assertArrayHasKey('total_schema_count', $summary);
        $this->assertArrayHasKey('pii_blocked_params', $summary);
        $this->assertArrayHasKey('strict_mode', $summary);
        $this->assertGreaterThanOrEqual(12, $summary['builtin_schema_count']);
    }

    // ── 11. SaaSLifecycleFlowTracker ──────────────────────────────────────

    public function test_flow_tracker_has_builtin_flows(): void
    {
        $tracker = $this->createFlowTracker();
        $flows = $tracker->getRegisteredFlows();

        $this->assertArrayHasKey('signup_flow', $flows);
        $this->assertArrayHasKey('upgrade_flow', $flows);
        $this->assertArrayHasKey('activation_flow', $flows);
        $this->assertArrayHasKey('onboarding_flow', $flows);
        $this->assertArrayHasKey('expansion_flow', $flows);
        $this->assertCount(5, $flows);
    }

    public function test_flow_tracker_has_flow_method(): void
    {
        $tracker = $this->createFlowTracker();
        $this->assertTrue($tracker->hasFlow('signup_flow'));
        $this->assertFalse($tracker->hasFlow('nonexistent_flow'));
    }

    public function test_flow_tracker_returns_null_for_unknown_flow(): void
    {
        $tracker = $this->createFlowTracker();
        $flowId = $tracker->start('nonexistent_flow', 'user-1');
        $this->assertNull($flowId);
    }

    public function test_flow_tracker_diagnostic_summary(): void
    {
        $tracker = $this->createFlowTracker();
        $summary = $tracker->diagnosticSummary();

        $this->assertArrayHasKey('built_in_flows', $summary);
        $this->assertArrayHasKey('registered_flows', $summary);
        $this->assertArrayHasKey('custom_flows', $summary);
        $this->assertArrayHasKey('cache_prefix', $summary);
        $this->assertArrayHasKey('cache_ttl', $summary);
        $this->assertArrayHasKey('flow_count', $summary);
        $this->assertArrayHasKey('max_steps_in_flow', $summary);
        $this->assertSame(5, $summary['flow_count']);
        $this->assertGreaterThanOrEqual(2, $summary['max_steps_in_flow']);
    }

    public function test_flow_tracker_custom_flows(): void
    {
        $customFlows = [
            'custom_checkout' => ['cart_view', 'checkout_start', 'payment', 'confirmation'],
        ];

        $tracker = $this->createFlowTracker(customFlows: $customFlows);
        $flows = $tracker->getRegisteredFlows();

        $this->assertArrayHasKey('custom_checkout', $flows);
        $this->assertCount(6, $flows); // 5 built-in + 1 custom
        $this->assertSame(4, count($flows['custom_checkout']));
    }

    public function test_flow_tracker_progress_calculation(): void
    {
        $tracker = $this->createFlowTracker();

        // Non-existent flow returns null
        $this->assertNull($tracker->getProgress('nonexistent-id'));
    }

    // ── 12. Cross-Feature Integration ───────────────────────────────────

    public function test_event_builder_with_catalog_event(): void
    {
        $entry = EventCatalog::get('purchase');
        $this->assertNotNull($entry);

        $event = EventBuilder::fromCatalog('purchase')
            ->param('transaction_id', 'T-999')
            ->param('value', 49.99)
            ->build();

        $this->assertNotNull($event);
        $this->assertSame('purchase', $event->name);
    }

    public function test_all_categories_have_nonzero_event_counts(): void
    {
        $byCategory = EventCatalog::byCategory();

        foreach ($byCategory as $category => $events) {
            $this->assertGreaterThan(
                0,
                count($events),
                "Category '{$category}' has zero events",
            );
        }
    }

    public function test_security_uptime_infrastructure_marketing_customer_success_have_events(): void
    {
        $this->assertGreaterThan(0, SecurityEvents::count());
        $this->assertGreaterThan(0, UptimeEvents::count());
        $this->assertGreaterThan(0, InfrastructureEvents::count());
        $this->assertGreaterThan(0, MarketingEvents::count());
        $this->assertGreaterThan(0, CustomerSuccessEvents::count());
    }

    public function test_catalog_category_mapping_consistency(): void
    {
        // Every event returned by all() should have a valid category via getCategory()
        $all = EventCatalog::all();
        $sample = array_slice($all, 0, 20, true);

        foreach ($sample as $name => $entry) {
            $category = EventCatalog::getCategory($name);
            $this->assertNotNull($category, "getCategory returned null for: {$name}");
            $this->assertContains($category, [
                'ecommerce', 'saas', 'engagement', 'security',
                'uptime', 'infrastructure', 'marketing', 'customer_success',
            ], "Unexpected category '{$category}' for event: {$name}");
        }
    }

    public function test_catalog_by_provider_method(): void
    {
        $byProvider = EventCatalog::byProvider();

        $this->assertArrayHasKey('ga4', $byProvider);
        $this->assertArrayHasKey('meta', $byProvider);
        $this->assertArrayHasKey('posthog', $byProvider);
        $this->assertArrayHasKey('plausible', $byProvider);
        $this->assertArrayHasKey('mixpanel', $byProvider);
        $this->assertArrayHasKey('amplitude', $byProvider);
        $this->assertArrayHasKey('tiktok', $byProvider);
        $this->assertArrayHasKey('linkedin', $byProvider);

        $this->assertNotEmpty($byProvider['ga4']);
        $this->assertNotEmpty($byProvider['meta']);
        $this->assertNotEmpty($byProvider['posthog']);
    }

    // ── 13. Strict Types & Code Quality ─────────────────────────────────

    public function test_analytics_event_dto_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(AnalyticsEvent::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_event_builder_is_final(): void
    {
        $reflection = new \ReflectionClass(EventBuilder::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function test_ecommerce_format_converter_is_final(): void
    {
        $reflection = new \ReflectionClass(EcommerceFormatConverter::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function test_schema_validator_is_final(): void
    {
        $reflection = new \ReflectionClass(EventParameterSchemaValidator::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function test_flow_tracker_is_final(): void
    {
        $reflection = new \ReflectionClass(SaaSLifecycleFlowTracker::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function test_new_services_have_strict_types(): void
    {
        $files = [
            __DIR__ . '/../../src/Services/SaaSLifecycleFlowTracker.php',
            __DIR__ . '/../../src/Services/EventParameterSchemaValidator.php',
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $this->assertNotFalse($contents);
            $this->assertStringContainsString('declare(strict_types=1)', $contents);
            $this->assertStringContainsString('This file is part of ZeroBoiler', $contents);
        }
    }

    // ── Helper Methods ──────────────────────────────────────────────────

    private function createFlowTracker(?array $customFlows = null): SaaSLifecycleFlowTracker
    {
        $cache = new class implements \Illuminate\Contracts\Cache\Repository
        {
            /** @var array<string, mixed> */
            private array $store = [];

            public function get($key, $default = null): mixed
            {
                return $this->store[$key] ?? $default;
            }

            public function put($key, $value, $ttl = null): bool
            {
                $this->store[$key] = $value;
                return true;
            }

            public function forget($key): bool
            {
                unset($this->store[$key]);
                return true;
            }

            public function has($key): bool
            {
                return array_key_exists($key, $this->store);
            }

            public function getMultiple($keys, $default = null): array
            {
                $result = [];
                foreach ($keys as $key) {
                    $result[$key] = $this->store[$key] ?? $default;
                }
                return $result;
            }

            public function putMany($values, $ttl = null): bool
            {
                foreach ($values as $key => $value) {
                    $this->store[$key] = $value;
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

            public function decrement($key, $value = 1): int|false
            {
                $current = $this->store[$key] ?? 0;
                $this->store[$key] = $current - $value;
                return $this->store[$key];
            }

            public function increment($key, $value = 1): int|false
            {
                $current = $this->store[$key] ?? 0;
                $this->store[$key] = $current + $value;
                return $this->store[$key];
            }

            public function pull($key, $default = null): mixed
            {
                $value = $this->store[$key] ?? $default;
                unset($this->store[$key]);
                return $value;
            }

            public function remember($key, $ttl, \Closure $callback): mixed
            {
                if ($this->has($key)) {
                    return $this->get($key);
                }
                $value = $callback();
                $this->put($key, $value, $ttl);
                return $value;
            }

            public function rememberForever($key, \Closure $callback): mixed
            {
                if ($this->has($key)) {
                    return $this->get($key);
                }
                $value = $callback();
                $this->put($key, $value);
                return $value;
            }

            public function sear($key, \Closure $callback): mixed
            {
                return $this->rememberForever($key, $callback);
            }

            public function tags(mixed $names): \Illuminate\Cache\TaggedCache
            {
                throw new \LogicException('Not implemented');
            }

            public function getDefaultCacheTime(): int
            {
                return 3600;
            }

            public function setDefaultCacheTime(int $seconds): static
            {
                return $this;
            }

            public function getPrefix(): string
            {
                return 'test_';
            }

            public function getStore(): \Illuminate\Contracts\Cache\Store
            {
                throw new \LogicException('Not implemented');
            }

            public function lock($name, $seconds = 0, $owner = null): \Illuminate\Contracts\Cache\Lock
            {
                throw new \LogicException('Not implemented');
            }

            public function macro($name, $macro): void {}

            public function mixin($mixin, $replace = true): void {}

            public function macroCall($method, $parameters): mixed
            {
                throw new \BadMethodCallException("Method {$method} does not exist.");
            }
        };

        return new SaaSLifecycleFlowTracker(
            $this->createMock(\ZeroBoiler\Analytics\AnalyticsManager::class),
            $cache,
            $customFlows,
        );
    }
}
