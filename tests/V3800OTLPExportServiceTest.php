<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\OTLPExportService;

/**
 * Tests for the OpenTelemetry (OTLP) Export Service.
 *
 * @covers \ZeroBoiler\Analytics\Services\OTLPExportService
 *
 * @since 38.0.0
 */
final class V3800OTLPExportServiceTest extends TestCase
{
    /**
     * Create an OTLPExportService with mock config and cache.
     *
     * @param  array<string, mixed>  $configOverrides
     */
    private function createService(array $configOverrides = []): OTLPExportService
    {
        $config = new class implements \Illuminate\Contracts\Config\Repository {
            /** @var array<string, mixed> */
            private array $values;

            /** @param array<string, mixed> $values */
            public function __construct(array $values = []) {
                $this->values = $values;
            }

            #[\Override]
            public function has(string $key): bool {
                return array_key_exists($key, $this->values);
            }

            #[\Override]
            public function get(string $key, mixed $default = null): mixed {
                return $this->values[$key] ?? $default;
            }

            #[\Override]
            public function all(): array {
                return $this->values;
            }

            #[\Override]
            public function set(string|array $key, mixed $value = null): void {
                if (is_array($key)) {
                    $this->values = array_merge($this->values, $key);
                } else {
                    $this->values[$key] = $value;
                }
            }

            #[\Override]
            public function prepend(string $key, mixed $value): void {}

            #[\Override]
            public function push(string $key, mixed $value): void {}
        };

        $config->set('zeroboiler.analytics.otel', array_merge([
            'enabled' => true,
            'endpoint' => 'http://localhost:4318/v1/traces',
            'headers' => '',
            'timeout' => 5,
            'max_batch_size' => 100,
            'debug' => false,
            'cache_prefix' => 'zb_otel_test_',
            'cache_ttl' => 300,
            'resource_attributes' => [
                'service.name' => 'test-analytics',
            ],
        ], $configOverrides));

        $cache = new class implements \Illuminate\Contracts\Cache\Repository {
            /** @var array<string, mixed> */
            private array $store = [];

            #[\Override]
            public function get(string|array $key, mixed $default = null): mixed {
                return $this->store[$key] ?? $default;
            }

            #[\Override]
            public function set(string|array $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null): bool {
                $this->store[$key] = $value;

                return true;
            }

            #[\Override]
            public function forget(string|array $key): bool {
                unset($this->store[$key]);

                return true;
            }

            #[\Override]
            public function has(string|array $key): bool {
                return isset($this->store[$key]);
            }

            #[\Override]
            public function pull(string|array $key, mixed $default = null): mixed {
                $value = $this->get($key, $default);
                $this->forget($key);

                return $value;
            }

            #[\Override]
            public function put(string|array $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null): bool {
                return $this->set($key, $value, $ttl);
            }

            #[\Override]
            public function many(array $keys): array {
                $result = [];
                foreach ($keys as $key) {
                    $result[$key] = $this->get($key);
                }

                return $result;
            }

            #[\Override]
            public function putMany(array $values, \DateTimeInterface|\DateInterval|int|null $ttl = null): bool {
                foreach ($values as $key => $value) {
                    $this->put($key, $value, $ttl);
                }

                return true;
            }

            #[\Override]
            public function flush(): bool {
                $this->store = [];

                return true;
            }

            #[\Override]
            public function increment(string $key, int $value = 1, int|null $ttl = null): int|false {
                $current = $this->get($key, 0);
                $this->put($key, $current + $value, $ttl);

                return $current + $value;
            }

            #[\Override]
            public function decrement(string $key, int $value = 1, int|null $ttl = null): int|false {
                $current = $this->get($key, 0);
                $this->put($key, $current - $value, $ttl);

                return $current - $value;
            }

            #[\Override]
            public function forever(string|array $key, mixed $value): bool {
                return $this->set($key, $value);
            }

            #[\Override]
            public function remember(string $key, \Closure|\DateInterval|int|null $ttl, \Closure $callback): mixed {
                return $this->get($key) ?? tap($callback(), fn ($value) => $this->put($key, $value, $ttl instanceof \Closure ? null : $ttl));
            }

            #[\Override]
            public function rememberForever(string $key, \Closure $callback): mixed {
                return $this->get($key) ?? tap($callback(), fn ($value) => $this->forever($key, $value));
            }

            #[\Override]
            public function missing(string|array $key): bool {
                return ! $this->has($key);
            }

            #[\Override]
            public function lock(string $key, int $seconds, ?string $owner = null): \Illuminate\Contracts\Cache\Lock {
                throw new \LogicException('Not implemented');
            }

            #[\Override]
            public function getPrefix(): string {
                return '';
            }
        };

        return new OTLPExportService($config, $cache);
    }

    // ─── Construction ───────────────────────────────────────────────

    public function test_service_constructs_with_defaults(): void
    {
        $service = $this->createService();

        $this->assertInstanceOf(OTLPExportService::class, $service);
        $this->assertTrue($service->isEnabled());
    }

    public function test_service_is_disabled_when_config_disabled(): void
    {
        $service = $this->createService(['enabled' => false]);

        $this->assertFalse($service->isEnabled());
    }

    public function test_service_uses_custom_endpoint(): void
    {
        $service = $this->createService(['endpoint' => 'http://custom:4318/v1/traces']);

        $this->assertSame('http://custom:4318/v1/traces', $service->getEndpoint());
    }

    // ─── Event to Span Conversion ──────────────────────────────────

    public function test_event_to_span_basic_conversion(): void
    {
        $service = $this->createService();
        $event = new AnalyticsEvent(
            name: 'page_view',
            params: ['url' => '/home'],
            clientId: 'client-123',
            userId: 'user-456',
        );

        $span = $service->eventToSpan($event);

        $this->assertNotNull($span);
        $this->assertArrayHasKey('traceId', $span);
        $this->assertArrayHasKey('spanId', $span);
        $this->assertSame('page_view', $span['name']);
        $this->assertArrayHasKey('attributes', $span);
        $this->assertArrayHasKey('startTimeUnixNano', $span);
        $this->assertArrayHasKey('endTimeUnixNano', $span);
    }

    public function test_event_to_span_includes_client_id_attribute(): void
    {
        $service = $this->createService();
        $event = new AnalyticsEvent(
            name: 'click',
            params: [],
            clientId: 'cli-abc',
        );

        $span = $service->eventToSpan($event);

        $this->assertNotNull($span);
        $clientAttr = $this->findAttribute($span['attributes'], 'analytics.client_id');
        $this->assertNotNull($clientAttr);
        $this->assertSame('cli-abc', $clientAttr['value']['stringValue']);
    }

    public function test_event_to_span_includes_user_id_and_enduser_id(): void
    {
        $service = $this->createService();
        $event = new AnalyticsEvent(
            name: 'login',
            params: [],
            userId: 'user-789',
        );

        $span = $service->eventToSpan($event);

        $this->assertNotNull($span);
        $userIdAttr = $this->findAttribute($span['attributes'], 'analytics.user_id');
        $enduserIdAttr = $this->findAttribute($span['attributes'], 'enduser.id');

        $this->assertNotNull($userIdAttr);
        $this->assertSame('user-789', $userIdAttr['value']['stringValue']);
        $this->assertNotNull($enduserIdAttr);
        $this->assertSame('user-789', $enduserIdAttr['value']['stringValue']);
    }

    public function test_event_to_span_category_mapping(): void
    {
        $service = $this->createService();

        // Engagement events should be INTERNAL (kind=1)
        $engagementEvent = new AnalyticsEvent(name: 'page_view');
        $engagementSpan = $service->eventToSpan($engagementEvent);
        $this->assertSame(1, $engagementSpan['kind']);

        // We test with a purchase event (ecommerce) — should be CLIENT (kind=3)
        $ecomEvent = new AnalyticsEvent(name: 'purchase');
        $ecomSpan = $service->eventToSpan($ecomEvent);
        $this->assertSame(3, $ecomSpan['kind']);
    }

    public function test_event_to_span_preserves_string_param(): void
    {
        $service = $this->createService();
        $event = new AnalyticsEvent(name: 'search', params: ['query' => 'laravel']);

        $span = $service->eventToSpan($event);
        $attr = $this->findAttribute($span['attributes'], 'query');
        $this->assertNotNull($attr);
        $this->assertSame('laravel', $attr['value']['stringValue']);
    }

    public function test_event_to_span_integer_param(): void
    {
        $service = $this->createService();
        $event = new AnalyticsEvent(name: 'add_to_cart', params: ['quantity' => 5]);

        $span = $service->eventToSpan($event);
        $attr = $this->findAttribute($span['attributes'], 'quantity');
        $this->assertNotNull($attr);
        $this->assertSame(5, $attr['value']['intValue']);
    }

    public function test_event_to_span_float_param(): void
    {
        $service = $this->createService();
        $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99]);

        $span = $service->eventToSpan($event);
        $attr = $this->findAttribute($span['attributes'], 'value');
        $this->assertNotNull($attr);
        $this->assertSame(99.99, $attr['value']['doubleValue']);
    }

    public function test_event_to_span_bool_param(): void
    {
        $service = $this->createService();
        $event = new AnalyticsEvent(name: 'feature_used', params: ['is_new' => true]);

        $span = $service->eventToSpan($event);
        $attr = $this->findAttribute($span['attributes'], 'is_new');
        $this->assertNotNull($attr);
        $this->assertTrue($attr['value']['boolValue']);
    }

    public function test_event_to_span_array_param_json_encoded(): void
    {
        $service = $this->createService();
        $event = new AnalyticsEvent(name: 'form_submit', params: ['fields' => ['name', 'email']]);

        $span = $service->eventToSpan($event);
        $attr = $this->findAttribute($span['attributes'], 'fields');
        $this->assertNotNull($attr);
        $this->assertSame('["name","email"]', $attr['value']['stringValue']);
    }

    public function test_event_to_span_includes_sdk_metadata(): void
    {
        $service = $this->createService();
        $event = new AnalyticsEvent(name: 'click');

        $span = $service->eventToSpan($event);

        $nameAttr = $this->findAttribute($span['attributes'], 'analytics.event.name');
        $this->assertNotNull($nameAttr);
        $this->assertSame('click', $nameAttr['value']['stringValue']);

        $sdkAttr = $this->findAttribute($span['attributes'], 'analytics.sdk.name');
        $this->assertNotNull($sdkAttr);
        $this->assertSame('zeroboiler-analytics', $sdkAttr['value']['stringValue']);

        $versionAttr = $this->findAttribute($span['attributes'], 'analytics.sdk.version');
        $this->assertNotNull($versionAttr);
        $this->assertSame('38.0.0', $versionAttr['value']['stringValue']);
    }

    public function test_event_to_span_deterministic_trace_id(): void
    {
        $service = $this->createService();

        $event1 = new AnalyticsEvent(name: 'test', clientId: 'cli-1', userId: 'usr-1');
        $event2 = new AnalyticsEvent(name: 'test', clientId: 'cli-1', userId: 'usr-1');
        $event3 = new AnalyticsEvent(name: 'test', clientId: 'cli-2', userId: 'usr-1');

        $span1 = $service->eventToSpan($event1);
        $span2 = $service->eventToSpan($event2);
        $span3 = $service->eventToSpan($event3);

        // Same client+user → same trace ID
        $this->assertSame($span1['traceId'], $span2['traceId']);
        // Different client → different trace ID
        $this->assertNotSame($span1['traceId'], $span3['traceId']);
    }

    public function test_event_to_span_timestamp_in_nanoseconds(): void
    {
        $service = $this->createService();
        $event = new AnalyticsEvent(name: 'click');

        $span = $service->eventToSpan($event);

        // OTLP requires nanoseconds — should be a very large integer
        $this->assertGreaterThan(1_000_000_000_000, $span['startTimeUnixNano']);
        $this->assertGreaterThan($span['startTimeUnixNano'], $span['endTimeUnixNano']);
    }

    // ─── Param to Attribute Conversion ─────────────────────────────

    public function test_param_to_attribute_sanitizes_key(): void
    {
        $service = $this->createService();

        $result = $service->paramToAttribute('user.name.first', 'John');
        $this->assertSame('user_name_first', $result['key']);
        $this->assertSame('John', $result['value']['stringValue']);
    }

    public function test_param_to_attribute_limits_string_length(): void
    {
        $service = $this->createService();
        $longValue = str_repeat('a', 600);

        $result = $service->paramToAttribute('key', $longValue);
        $this->assertSame(500, mb_strlen($result['value']['stringValue']));
    }

    public function test_param_to_attribute_null_becomes_string(): void
    {
        $service = $this->createService();

        $result = $service->paramToAttribute('key', null);
        $this->assertSame('null', $result['value']['stringValue']);
    }

    // ─── Payload Building ───────────────────────────────────────────

    public function test_build_payload_produces_valid_otlp_structure(): void
    {
        $service = $this->createService();
        $event = new AnalyticsEvent(name: 'test', params: ['value' => 42]);
        $span = $service->eventToSpan($event);

        $payload = $service->buildPayload([$span]);
        $decoded = json_decode($payload, true);

        $this->assertNotNull($decoded);
        $this->assertArrayHasKey('resourceSpans', $decoded);
        $this->assertCount(1, $decoded['resourceSpans']);

        $resourceSpan = $decoded['resourceSpans'][0];
        $this->assertArrayHasKey('resource', $resourceSpan);
        $this->assertArrayHasKey('scopeSpans', $resourceSpan);
        $this->assertArrayHasKey('attributes', $resourceSpan['resource']);

        // Should have service.name
        $serviceAttr = null;
        foreach ($resourceSpan['resource']['attributes'] as $attr) {
            if ($attr['key'] === 'service.name') {
                $serviceAttr = $attr;
                break;
            }
        }
        $this->assertNotNull($serviceAttr);
        $this->assertSame('test-analytics', $serviceAttr['value']['stringValue']);
    }

    public function test_build_payload_includes_scope_information(): void
    {
        $service = $this->createService();
        $event = new AnalyticsEvent(name: 'test');
        $span = $service->eventToSpan($event);

        $payload = $service->buildPayload([$span]);
        $decoded = json_decode($payload, true);

        $scope = $decoded['resourceSpans'][0]['scopeSpans'][0]['scope'];
        $this->assertSame('zeroboiler.analytics', $scope['name']);
        $this->assertSame('38.0.0', $scope['version']);
    }

    public function test_build_payload_with_custom_resource_attributes(): void
    {
        $service = $this->createService([
            'resource_attributes' => [
                'service.name' => 'my-service',
                'deployment.environment' => 'staging',
                'custom.attr' => 'custom-value',
            ],
        ]);

        $event = new AnalyticsEvent(name: 'test');
        $span = $service->eventToSpan($event);
        $payload = $service->buildPayload([$span]);
        $decoded = json_decode($payload, true);

        $attrs = $decoded['resourceSpans'][0]['resource']['attributes'];

        $envAttr = null;
        $customAttr = null;
        foreach ($attrs as $attr) {
            if ($attr['key'] === 'deployment.environment') {
                $envAttr = $attr;
            }
            if ($attr['key'] === 'custom_attr') {
                $customAttr = $attr;
            }
        }

        $this->assertNotNull($envAttr);
        $this->assertSame('staging', $envAttr['value']['stringValue']);
        $this->assertNotNull($customAttr);
        $this->assertSame('custom-value', $customAttr['value']['stringValue']);
    }

    // ─── Export Stats ───────────────────────────────────────────────

    public function test_stats_returns_initial_state(): void
    {
        $service = $this->createService();
        $stats = $service->stats();

        $this->assertTrue($stats['enabled']);
        $this->assertSame(0, $stats['success']);
        $this->assertSame(0, $stats['failure']);
        $this->assertSame(0, $stats['exported']);
        $this->assertNull($stats['last_error']);
        $this->assertSame(0.0, $stats['avg_latency_ms']);
        $this->assertSame(0.0, $stats['success_rate']);
    }

    public function test_stats_disabled_when_export_off(): void
    {
        $service = $this->createService(['enabled' => false]);
        $stats = $service->stats();

        $this->assertFalse($stats['enabled']);
    }

    public function test_reset_stats_clears_all(): void
    {
        $service = $this->createService();
        $service->resetStats();

        $stats = $service->stats();
        $this->assertSame(0, $stats['success']);
        $this->assertSame(0, $stats['failure']);
        $this->assertSame(0, $stats['exported']);
        $this->assertNull($stats['last_error']);
    }

    // ─── Validation ─────────────────────────────────────────────────

    public function test_validate_with_good_config(): void
    {
        $service = $this->createService();
        $result = $service->validate();

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function test_validate_with_empty_endpoint(): void
    {
        $service = $this->createService(['endpoint' => '']);
        $result = $service->validate();

        $this->assertFalse($result['valid']);
        $this->assertContains('OTLP endpoint is not configured', $result['errors']);
    }

    public function test_validate_with_invalid_endpoint_scheme(): void
    {
        $service = $this->createService(['endpoint' => 'ftp://collector']);
        $result = $service->validate();

        $this->assertFalse($result['valid']);
        $this->assertContains('OTLP endpoint must start with http:// or https://', $result['errors']);
    }

    public function test_validate_disabled_warnings(): void
    {
        $service = $this->createService(['enabled' => false]);
        $result = $service->validate();

        $this->assertTrue($result['valid']);
        $this->assertContains('OTLP export is disabled', $result['warnings']);
    }

    // ─── Enable/Disable ─────────────────────────────────────────────

    public function test_enable_disable_runtime(): void
    {
        $service = $this->createService(['enabled' => false]);

        $this->assertFalse($service->isEnabled());
        $service->enable();
        $this->assertTrue($service->isEnabled());
        $service->disable();
        $this->assertFalse($service->isEnabled());
    }

    // ─── Export Batch ───────────────────────────────────────────────

    public function test_export_batch_returns_empty_when_disabled(): void
    {
        $service = $this->createService(['enabled' => false]);
        $events = [
            new AnalyticsEvent(name: 'click'),
            new AnalyticsEvent(name: 'page_view'),
        ];

        $result = $service->exportBatch($events);

        $this->assertSame(0, $result['exported']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(2, $result['total']);
    }

    public function test_export_batch_returns_empty_for_empty_events(): void
    {
        $service = $this->createService();

        $result = $service->exportBatch([]);

        $this->assertSame(0, $result['exported']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['total']);
    }

    // ─── Export Single ──────────────────────────────────────────────

    public function test_export_returns_false_when_disabled(): void
    {
        $service = $this->createService(['enabled' => false]);
        $event = new AnalyticsEvent(name: 'click');

        $this->assertFalse($service->export($event));
    }

    // ─── Version Consistency ────────────────────────────────────────

    public function test_version_matches_across_all_markers(): void
    {
        $this->assertSame('38.0.0', AnalyticsEvent::VERSION);

        $json = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        $this->assertSame('38.0.0', $json['version']);

        $pkgJson = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);
        $this->assertSame('38.0.0', $pkgJson['version']);
    }

    public function test_service_file_uses_strict_types(): void
    {
        $contents = file_get_contents(__DIR__ . '/../src/Services/OTLPExportService.php');
        $this->assertStringContainsString('declare(strict_types=1)', $contents);
    }

    public function test_service_file_is_final_class(): void
    {
        $contents = file_get_contents(__DIR__ . '/../src/Services/OTLPExportService.php');
        $this->assertStringContainsString('final class OTLPExportService', $contents);
    }

    public function test_command_file_uses_strict_types(): void
    {
        $contents = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsOTLPCommand.php');
        $this->assertStringContainsString('declare(strict_types=1)', $contents);
    }

    public function test_command_file_is_final_class(): void
    {
        $contents = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsOTLPCommand.php');
        $this->assertStringContainsString('final class AnalyticsOTLPCommand', $contents);
    }

    // ─── Helper ─────────────────────────────────────────────────────

    /**
     * Find an attribute by key in an OTLP attributes array.
     *
     * @param  list<array{key: string, value: mixed}>  $attributes
     * @return array{key: string, value: mixed}|null
     */
    private function findAttribute(array $attributes, string $key): ?array
    {
        foreach ($attributes as $attr) {
            if ($attr['key'] === $key) {
                return $attr;
            }
        }

        return null;
    }
}
