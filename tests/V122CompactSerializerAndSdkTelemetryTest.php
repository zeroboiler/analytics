<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventCompactSerializer;
use ZeroBoiler\Analytics\Services\AnalyticsSdkTelemetryCollector;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Phase 122 — Event Compact Serializer & SDK Telemetry Collector.
 *
 * Tests binary serialization/deserialization round-trip fidelity,
 * compression ratios, CRC validation, edge cases, and SDK telemetry
 * collection, aggregation, version distribution, and health detection.
 *
 * @covers \ZeroBoiler\Analytics\Services\EventCompactSerializer
 * @covers \ZeroBoiler\Analytics\Services\AnalyticsSdkTelemetryCollector
 *
 * @since 122.0.0
 */
final class V122CompactSerializerAndSdkTelemetryTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════════
    // EventCompactSerializer Tests
    // ═══════════════════════════════════════════════════════════════════

    public function test_serializer_metadata_returns_expected_structure(): void
    {
        $serializer = new EventCompactSerializer;
        $metadata = $serializer->metadata();

        $this->assertSame(1, $metadata['version']);
        $this->assertSame(65535, $metadata['max_batch']);
        $this->assertSame(255, $metadata['max_name_length']);
        $this->assertSame(65535, $metadata['max_value_length']);
    }

    public function test_serialize_single_event_roundtrip(): void
    {
        $serializer = new EventCompactSerializer;

        $event = new AnalyticsEvent(
            name: 'button_click',
            params: ['element' => 'buy_now', 'x' => 100, 'y' => 200],
            clientId: 'client_abc123',
            userId: 'user_456',
        );

        $payload = $serializer->serialize($event);
        $this->assertIsString($payload);
        $this->assertGreaterThan(0, strlen($payload));

        // Verify base64url encoding (no +, /, or = chars)
        $this->assertDoesNotMatchRegularExpression('#[+/=]#', $payload);

        // Round-trip
        $deserialized = $serializer->deserialize($payload);
        $this->assertCount(1, $deserialized);

        $recovered = $deserialized[0];
        $this->assertSame('button_click', $recovered->name);
        $this->assertSame('client_abc123', $recovered->clientId);
        $this->assertSame('user_456', $recovered->userId);
        $this->assertSame('buy_now', $recovered->params['element']);
        $this->assertSame(100, $recovered->params['x']);
        $this->assertSame(200, $recovered->params['y']);
    }

    public function test_serialize_batch_roundtrip(): void
    {
        $serializer = new EventCompactSerializer;

        $events = [
            new AnalyticsEvent(name: 'page_view', params: ['url' => '/home']),
            new AnalyticsEvent(name: 'add_to_cart', params: ['item_id' => 'SKU-001', 'quantity' => 2]),
            new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99, 'currency' => 'USD']),
        ];

        $payload = $serializer->serializeBatch($events);
        $deserialized = $serializer->deserialize($payload);

        $this->assertCount(3, $deserialized);
        $this->assertSame('page_view', $deserialized[0]->name);
        $this->assertSame('add_to_cart', $deserialized[1]->name);
        $this->assertSame('purchase', $deserialized[2]->name);
        $this->assertSame('/home', $deserialized[0]->params['url']);
        $this->assertSame('SKU-001', $deserialized[1]->params['item_id']);
        $this->assertSame(99.99, $deserialized[2]->params['value']);
    }

    public function test_serialize_handles_all_param_types(): void
    {
        $serializer = new EventCompactSerializer;

        $event = new AnalyticsEvent(
            name: 'test_event',
            params: [
                'null_val' => null,
                'bool_true' => true,
                'bool_false' => false,
                'int_val' => 42,
                'neg_int' => -100,
                'float_val' => 3.14,
                'string_val' => 'hello world',
                'array_val' => [1, 2, 3],
                'assoc_val' => ['key' => 'value', 'nested' => ['a' => 1]],
                'empty_string' => '',
            ],
        );

        $payload = $serializer->serialize($event);
        $deserialized = $serializer->deserialize($payload);

        $this->assertCount(1, $deserialized);
        $p = $deserialized[0]->params;

        $this->assertNull($p['null_val']);
        $this->assertTrue($p['bool_true']);
        $this->assertFalse($p['bool_false']);
        $this->assertSame(42, $p['int_val']);
        $this->assertSame(-100, $p['neg_int']);
        $this->assertSame(3.14, $p['float_val']);
        $this->assertSame('hello world', $p['string_val']);
        $this->assertSame([1, 2, 3], $p['array_val']);
        $this->assertSame(['key' => 'value', 'nested' => ['a' => 1]], $p['assoc_val']);
        $this->assertSame('', $p['empty_string']);
    }

    public function test_serialize_throws_on_empty_batch(): void
    {
        $serializer = new EventCompactSerializer;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot serialize empty event batch');

        $serializer->serializeBatch([]);
    }

    public function test_deserialize_throws_on_invalid_payload(): void
    {
        $serializer = new EventCompactSerializer;

        $this->expectException(\InvalidArgumentException::class);
        $serializer->deserialize('not-a-valid-payload');
    }

    public function test_deserialize_throws_on_corrupted_crc(): void
    {
        $serializer = new EventCompactSerializer;

        $event = new AnalyticsEvent(name: 'test', params: []);
        $payload = $serializer->serialize($event);

        // Corrupt the payload by changing one character
        $corrupted = substr($payload, 0, -1) . (\substr($payload, -1) === 'A' ? 'B' : 'A');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CRC32 checksum mismatch');
        $serializer->deserialize($corrupted);
    }

    public function test_deserialize_throws_on_wrong_version(): void
    {
        $serializer = new EventCompactSerializer;

        $event = new AnalyticsEvent(name: 'test', params: []);
        $payload = $serializer->serialize($event);

        // Decode, modify version byte, re-encode
        $binary = base64_decode(strtr($payload, '-_', '+/') . str_repeat('=', 4 - strlen($payload) % 4));
        $binary[0] = chr(99); // Invalid version
        $corrupted = rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported format version');
        $serializer->deserialize($corrupted);
    }

    public function test_compression_ratio_returns_float(): void
    {
        $serializer = new EventCompactSerializer;

        $events = [
            new AnalyticsEvent(name: 'button_click', params: ['element' => 'buy_now', 'page' => '/products']),
            new AnalyticsEvent(name: 'page_view', params: ['url' => '/dashboard', 'referrer' => 'https://google.com']),
        ];

        $ratio = $serializer->compressionRatio($events);

        $this->assertIsFloat($ratio);
        $this->assertGreaterThan(0.0);
        $this->assertLessThanOrEqual(1.0);
    }

    public function test_estimate_size_returns_positive_int(): void
    {
        $serializer = new EventCompactSerializer;

        $events = [
            new AnalyticsEvent(name: 'test_event', params: ['key' => 'value']),
        ];

        $size = $serializer->estimateSize($events);

        $this->assertIsInt($size);
        $this->assertGreaterThan(0);
    }

    public function test_serialize_event_without_user_or_client_id(): void
    {
        $serializer = new EventCompactSerializer;

        $event = new AnalyticsEvent(
            name: 'anonymous_event',
            params: ['source' => 'public'],
        );

        $payload = $serializer->serialize($event);
        $deserialized = $serializer->deserialize($payload);

        $this->assertCount(1, $deserialized);
        $this->assertSame('anonymous_event', $deserialized[0]->name);
        $this->assertNull($deserialized[0]->clientId);
        $this->assertNull($deserialized[0]->userId);
        $this->assertSame('public', $deserialized[0]->params['source']);
    }

    public function test_serialize_empty_params(): void
    {
        $serializer = new EventCompactSerializer;

        $event = new AnalyticsEvent(name: 'no_params_event', params: []);

        $payload = $serializer->serialize($event);
        $deserialized = $serializer->deserialize($payload);

        $this->assertCount(1, $deserialized);
        $this->assertSame('no_params_event', $deserialized[0]->name);
        $this->assertSame([], $deserialized[0]->params);
    }

    public function test_serialize_large_batch(): void
    {
        $serializer = new EventCompactSerializer;

        $events = [];
        for ($i = 0; $i < 100; $i++) {
            $events[] = new AnalyticsEvent(
                name: "event_{$i}",
                params: ['index' => $i, 'data' => str_repeat('x', $i * 10)],
            );
        }

        $payload = $serializer->serializeBatch($events);
        $deserialized = $serializer->deserialize($payload);

        $this->assertCount(100, $deserialized);
        $this->assertSame('event_0', $deserialized[0]->name);
        $this->assertSame('event_99', $deserialized[99]->name);
    }

    public function test_serialize_unicode_params(): void
    {
        $serializer = new EventCompactSerializer;

        $event = new AnalyticsEvent(
            name: 'search_query',
            params: ['query' => '日本語テスト 🎉 äöü ñ', 'locale' => 'tr-TR'],
        );

        $payload = $serializer->serialize($event);
        $deserialized = $serializer->deserialize($payload);

        $this->assertCount(1, $deserialized);
        $this->assertSame('日本語テスト 🎉 äöü ñ', $deserialized[0]->params['query']);
        $this->assertSame('tr-TR', $deserialized[0]->params['locale']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // AnalyticsSdkTelemetryCollector Tests
    // ═══════════════════════════════════════════════════════════════════

    private function createMockCache(): CacheRepository
    {
        return new class implements CacheRepository
        {
            /** @var array<string, mixed> */
            private array $store = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->store[$key] ?? $default;
            }

            public function set(string $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null): bool
            {
                $this->store[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->store[$key]);

                return true;
            }

            public function clear(): bool
            {
                $this->store = [];

                return true;
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->store);
            }

            public function forget(string $key): bool
            {
                return $this->delete($key);
            }

            public function put(string $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null): bool
            {
                return $this->set($key, $value, $ttl);
            }

            public function remember(string $key, \DateTimeInterface|\DateInterval|int|null $ttl, \Closure $callback): mixed
            {
                if ($this->has($key)) {
                    return $this->get($key);
                }

                $value = $callback();
                $this->put($key, $value, $ttl);

                return $value;
            }

            public function rememberForever(string $key, \Closure $callback): mixed
            {
                if ($this->has($key)) {
                    return $this->get($key);
                }

                $value = $callback();
                $this->put($key, $value);

                return $value;
            }

            public function pull(string $key, mixed $default = null): mixed
            {
                $value = $this->get($key, $default);
                $this->forget($key);

                return $value;
            }

            public function add(string $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null): bool
            {
                if ($this->has($key)) {
                    return false;
                }

                return $this->put($key, $value, $ttl);
            }

            public function increment(string $key, int $amount = 1): int|bool
            {
                $current = (int) $this->get($key, 0);
                $this->put($key, $current + $amount);

                return $current + $amount;
            }

            public function decrement(string $key, int $amount = 1): int|bool
            {
                return $this->increment($key, -$amount);
            }

            public function tags(mixed $names): \Illuminate\Cache\TaggedCache
            {
                throw new \LogicException('Not implemented in mock');
            }

            public function flush(): bool
            {
                return $this->clear();
            }
        };
    }

    private function createMockConfig(array $overrides = []): ConfigRepository
    {
        $defaults = [
            'zeroboiler.analytics.sdk_telemetry' => [
                'enabled' => true,
                'cache_ttl' => 86400,
                'aggregation_window' => 3600,
                'collect_page_load' => true,
                'collect_connection_type' => true,
                'collect_memory_usage' => true,
                'collect_battery_status' => false,
                'collect_error_rates' => true,
            ],
        ];

        return new class ($defaults, $overrides) implements ConfigRepository
        {
            /** @var array<string, mixed> */
            private array $config;

            /**
             * @param  array<string, mixed>  $defaults
             * @param  array<string, mixed>  $overrides
             */
            public function __construct(array $defaults, array $overrides)
            {
                $this->config = array_replace_recursive($defaults, $overrides);
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->config);
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->config[$key] ?? $default;
            }

            public function set(string|array $key, mixed $value = null): void
            {
                if (is_array($key)) {
                    foreach ($key as $k => $v) {
                        $this->config[$k] = $v;
                    }
                } else {
                    $this->config[$key] = $value;
                }
            }

            public function prepend(string $key, mixed $value): void
            {
                $current = $this->get($key, []);
                $this->config[$key] = is_array($current)
                    ? [$value, ...$current]
                    : $value;
            }

            public function push(string $key, mixed $value): void
            {
                $current = $this->get($key, []);
                $this->config[$key] = is_array($current)
                    ? [...$current, $value]
                    : $value;
            }

            public function all(): array
            {
                return $this->config;
            }
        };
    }

    public function test_telemetry_collector_is_disabled_by_default(): void
    {
        $cache = $this->createMockCache();
        $config = $this->createMockConfig([
            'zeroboiler.analytics.sdk_telemetry' => ['enabled' => false],
        ]);

        $collector = new AnalyticsSdkTelemetryCollector($cache, $config);

        $this->assertFalse($collector->isEnabled());
        $this->assertFalse($collector->collect(['sdk_version' => '1.0.0']));
    }

    public function test_telemetry_collect_single_point(): void
    {
        $cache = $this->createMockCache();
        $config = $this->createMockConfig();

        $collector = new AnalyticsSdkTelemetryCollector($cache, $config);

        $result = $collector->collect([
            'sdk_version' => '1.2.0',
            'platform' => 'web',
            'page_load_ms' => 1200,
            'connection_type' => '4g',
            'memory_usage_mb' => 45,
            'errors_count' => 0,
        ]);

        $this->assertTrue($result);
        $this->assertSame(1, $collector->getBufferCount());
    }

    public function test_telemetry_collect_rejects_empty_payload(): void
    {
        $cache = $this->createMockCache();
        $config = $this->createMockConfig();

        $collector = new AnalyticsSdkTelemetryCollector($cache, $config);

        $this->assertFalse($collector->collect([]));
    }

    public function test_telemetry_collect_batch(): void
    {
        $cache = $this->createMockCache();
        $config = $this->createMockConfig();

        $collector = new AnalyticsSdkTelemetryCollector($cache, $config);

        $result = $collector->collectBatch([
            ['sdk_version' => '1.0.0', 'platform' => 'web'],
            ['sdk_version' => '1.0.0', 'platform' => 'ios'],
            [],
        ]);

        $this->assertSame(2, $result['collected']);
        $this->assertSame(1, $result['rejected']);
    }

    public function test_telemetry_summary_returns_expected_structure(): void
    {
        $cache = $this->createMockCache();
        $config = $this->createMockConfig();

        $collector = new AnalyticsSdkTelemetryCollector($cache, $config);

        // Collect some data
        $collector->collect([
            'sdk_version' => '1.2.0',
            'platform' => 'web',
            'page_load_ms' => 1500,
            'errors_count' => 2,
        ]);
        $collector->collect([
            'sdk_version' => '1.2.0',
            'platform' => 'web',
            'page_load_ms' => 2500,
            'errors_count' => 1,
        ]);

        $summary = $collector->summary();

        $this->assertArrayHasKey('total_clients', $summary);
        $this->assertArrayHasKey('sdk_versions', $summary);
        $this->assertArrayHasKey('performance', $summary);
        $this->assertArrayHasKey('health', $summary);
        $this->assertArrayHasKey('platforms', $summary);
        $this->assertArrayHasKey('connection_types', $summary);
        $this->assertSame(2, $summary['total_clients']);

        // Performance metrics
        $perf = $summary['performance'];
        $this->assertNotNull($perf['avg_page_load_ms']);
        $this->assertEqualsWithDelta(2000.0, $perf['avg_page_load_ms'], 1.0);
    }

    public function test_telemetry_client_history(): void
    {
        $cache = $this->createMockCache();
        $config = $this->createMockConfig();

        $collector = new AnalyticsSdkTelemetryCollector($cache, $config);

        $collector->collect([
            'client_id' => 'client_001',
            'sdk_version' => '1.0.0',
            'page_load_ms' => 1000,
        ]);

        $history = $collector->clientHistory('client_001');

        $this->assertArrayHasKey('entries', $history);
        $this->assertArrayHasKey('summary', $history);
        $this->assertGreaterThan(0, $history['summary']['total_entries']);
        $this->assertSame('1.0.0', $history['summary']['sdk_version']);
    }

    public function test_telemetry_client_history_unknown_client(): void
    {
        $cache = $this->createMockCache();
        $config = $this->createMockConfig();

        $collector = new AnalyticsSdkTelemetryCollector($cache, $config);

        $history = $collector->clientHistory('unknown_client');

        $this->assertSame([], $history['entries']);
        $this->assertSame(0, $history['summary']['total_entries']);
        $this->assertNull($history['summary']['sdk_version']);
    }

    public function test_telemetry_version_distribution(): void
    {
        $cache = $this->createMockCache();
        $config = $this->createMockConfig();

        $collector = new AnalyticsSdkTelemetryCollector($cache, $config);

        $collector->collect(['sdk_version' => '1.0.0', 'platform' => 'web']);
        $collector->collect(['sdk_version' => '1.0.0', 'platform' => 'ios']);
        $collector->collect(['sdk_version' => '2.0.0', 'platform' => 'android']);

        $dist = $collector->versionDistribution();

        $this->assertArrayHasKey('versions', $dist);
        $this->assertArrayHasKey('latest_version', $dist);
        $this->assertArrayHasKey('outdated_clients', $dist);
        $this->assertSame('2.0.0', $dist['latest_version']);
        $this->assertSame(2, $dist['outdated_clients']);
    }

    public function test_telemetry_health_issues_empty_when_no_data(): void
    {
        $cache = $this->createMockCache();
        $config = $this->createMockConfig();

        $collector = new AnalyticsSdkTelemetryCollector($cache, $config);

        $issues = $collector->healthIssues();

        $this->assertSame([], $issues);
    }

    public function test_telemetry_health_issues_disabled(): void
    {
        $cache = $this->createMockCache();
        $config = $this->createMockConfig([
            'zeroboiler.analytics.sdk_telemetry' => ['enabled' => false],
        ]);

        $collector = new AnalyticsSdkTelemetryCollector($cache, $config);

        $this->assertSame([], $collector->healthIssues());
    }

    public function test_telemetry_clear_all(): void
    {
        $cache = $this->createMockCache();
        $config = $this->createMockConfig();

        $collector = new AnalyticsSdkTelemetryCollector($cache, $config);

        $collector->collect(['sdk_version' => '1.0.0']);
        $collector->collect(['sdk_version' => '1.0.0']);

        $cleared = $collector->clearAll();

        $this->assertGreaterThan(0, $cleared);
    }

    public function test_telemetry_clear_client_history(): void
    {
        $cache = $this->createMockCache();
        $config = $this->createMockConfig();

        $collector = new AnalyticsSdkTelemetryCollector($cache, $config);

        $collector->collect(['client_id' => 'to_clear', 'sdk_version' => '1.0.0']);

        $result = $collector->clearClientHistory('to_clear');

        $this->assertTrue($result);

        // After clearing, history should be empty
        $history = $collector->clientHistory('to_clear');
        $this->assertSame([], $history['entries']);
    }

    public function test_telemetry_config_returns_expected_settings(): void
    {
        $cache = $this->createMockCache();
        $config = $this->createMockConfig();

        $collector = new AnalyticsSdkTelemetryCollector($cache, $config);

        $cfg = $collector->getConfig();

        $this->assertTrue($cfg['enabled']);
        $this->assertSame(86400, $cfg['cache_ttl']);
        $this->assertSame(3600, $cfg['aggregation_window']);
        $this->assertTrue($cfg['collect_page_load']);
        $this->assertTrue($cfg['collect_connection_type']);
        $this->assertTrue($cfg['collect_memory_usage']);
        $this->assertFalse($cfg['collect_battery_status']);
        $this->assertTrue($cfg['collect_error_rates']);
    }

    public function test_telemetry_sanitizes_long_strings(): void
    {
        $cache = $this->createMockCache();
        $config = $this->createMockConfig();

        $collector = new AnalyticsSdkTelemetryCollector($cache, $config);

        $longClient = str_repeat('a', 200);
        $longUserAgent = str_repeat('b', 500);
        $longUrl = str_repeat('c', 5000);

        $result = $collector->collect([
            'client_id' => $longClient,
            'user_agent' => $longUserAgent,
            'page_url' => $longUrl,
            'sdk_version' => '1.0.0',
        ]);

        $this->assertTrue($result);

        $history = $collector->clientHistory($longClient);

        // Should be truncated
        $this->assertSame(64, strlen($history['entries'][0]['client_id']));
    }

    public function test_telemetry_validates_numeric_fields(): void
    {
        $cache = $this->createMockCache();
        $config = $this->createMockConfig();

        $collector = new AnalyticsSdkTelemetryCollector($cache, $config);

        $result = $collector->collect([
            'sdk_version' => '1.0.0',
            'page_load_ms' => 'not_a_number',
            'memory_usage_mb' => 'invalid',
            'errors_count' => 'abc',
        ]);

        $this->assertTrue($result);
        $this->assertSame(1, $collector->getBufferCount());
    }

    public function test_serializer_php85_strict_types_compliance(): void
    {
        $serializer = new EventCompactSerializer;

        // Verify all public methods have proper return types
        $reflection = new \ReflectionClass($serializer);

        $publicMethods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($publicMethods as $method) {
            $returnType = $method->getReturnType();

            $this->assertNotNull(
                $returnType,
                "Method {$method->getName()} is missing return type declaration"
            );
        }
    }

    public function test_telemetry_collector_php85_strict_types_compliance(): void
    {
        $cache = $this->createMockCache();
        $config = $this->createMockConfig();
        $collector = new AnalyticsSdkTelemetryCollector($cache, $config);

        $reflection = new \ReflectionClass($collector);

        $publicMethods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($publicMethods as $method) {
            $returnType = $method->getReturnType();

            $this->assertNotNull(
                $returnType,
                "Method {$method->getName()} is missing return type declaration"
            );
        }
    }
}
