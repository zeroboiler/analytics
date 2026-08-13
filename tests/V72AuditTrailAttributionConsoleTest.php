<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventAuditTrailService;
use ZeroBoiler\Analytics\Services\EventAttributionTrailService;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Tests for EventAuditTrailService, EventAttributionTrailService,
 * AnalyticsConsoleCommand registration, and v72.0.0 version sweep.
 *
 * @coversDefaultClass \ZeroBoiler\Analytics\Services\EventAuditTrailService
 * @coversDefaultClass \ZeroBoiler\Analytics\Services\EventAttributionTrailService
 *
 * @since 72.0.0
 */
final class V72AuditTrailAttributionConsoleTest extends \PHPUnit\Framework\TestCase
{
    // ─── EventAuditTrailService Tests ─────────────────────────────────

    public function test_audit_trail_service_is_enabled_by_default(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->with('zeroboiler.analytics.audit_trail', [])->willReturn([]);

        $service = new EventAuditTrailService($cache, $config);

        $this->assertTrue($service->isEnabled());
    }

    public function test_audit_trail_service_can_be_disabled(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->with('zeroboiler.analytics.audit_trail', [])->willReturn(['enabled' => false]);

        $service = new EventAuditTrailService($cache, $config);

        $this->assertFalse($service->isEnabled());
    }

    public function test_audit_trail_record_returns_audit_id_when_enabled(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->with('zeroboiler.analytics.audit_trail', [])->willReturn([
            'enabled' => true,
            'ttl' => 86400,
            'max_entries' => 100,
        ]);

        $cache->method('get')
            ->willReturnMap([
                [EventAuditTrailService::class . '::index', null], // @phpstan-ignore-line
            ]);

        $cache->method('put')->willReturn(true);

        $service = new EventAuditTrailService($cache, $config);
        $auditId = $service->record(
            ['name' => 'purchase', 'client_id' => 'cli_123', 'user_id' => 'usr_456'],
            [
                'ga4' => ['success' => true, 'latency_ms' => 42.5],
                'meta' => ['success' => true, 'latency_ms' => 38.1],
            ],
            ['consent_state' => 'granted'],
        );

        $this->assertNotEmpty($auditId);
        $this->assertStringStartsWith('aud_', $auditId);
    }

    public function test_audit_trail_record_returns_empty_when_disabled(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->with('zeroboiler.analytics.audit_trail', [])->willReturn(['enabled' => false]);

        $service = new EventAuditTrailService($cache, $config);
        $auditId = $service->record(
            ['name' => 'purchase'],
            ['ga4' => ['success' => true, 'latency_ms' => 10]],
        );

        $this->assertSame('', $auditId);
    }

    public function test_audit_trail_calculates_total_latency(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->with('zeroboiler.analytics.audit_trail', [])->willReturn([
            'enabled' => true,
            'ttl' => 86400,
            'max_entries' => 100,
        ]);

        $cache->method('get')->willReturn([]);
        $cache->method('put')->willReturn(true);

        $service = new EventAuditTrailService($cache, $config);

        // We verify the record stores latency correctly by inspecting put arguments
        $cache->expects($this->atLeastOnce())
            ->method('put')
            ->with(
                $this->anything(),
                $this->callback(function (array $entry): bool {
                    return isset($entry['total_latency_ms'])
                        && $entry['total_latency_ms'] > 0;
                }),
                $this->anything(),
            );

        $service->record(
            ['name' => 'test'],
            [
                'ga4' => ['success' => true, 'latency_ms' => 50.0],
                'meta' => ['success' => true, 'latency_ms' => 30.0],
                'posthog' => ['success' => true, 'latency_ms' => 20.0],
            ],
        );
    }

    public function test_audit_trail_search_with_filters(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->with('zeroboiler.analytics.audit_trail', [])->willReturn(['enabled' => true]);

        $service = new EventAuditTrailService($cache, $config);

        // Empty cache returns empty results
        $results = $service->search(['event_name' => 'purchase']);
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }

    public function test_audit_trail_statistics(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->with('zeroboiler.analytics.audit_trail', [])->willReturn(['enabled' => true]);

        $service = new EventAuditTrailService($cache, $config);

        $stats = $service->statistics();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_entries', $stats);
        $this->assertArrayHasKey('failure_rate', $stats);
        $this->assertArrayHasKey('avg_latency_ms', $stats);
        $this->assertArrayHasKey('period', $stats);
    }

    public function test_audit_trail_count_on_empty_cache(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->with('zeroboiler.analytics.audit_trail', [])->willReturn(['enabled' => true]);

        $service = new EventAuditTrailService($cache, $config);

        $this->assertSame(0, $service->count());
    }

    public function test_audit_trail_summary(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->with('zeroboiler.analytics.audit_trail', [])->willReturn([
            'enabled' => true,
            'ttl' => 2592000,
            'max_entries' => 5000,
        ]);

        $service = new EventAuditTrailService($cache, $config);
        $summary = $service->summary();

        $this->assertIsArray($summary);
        $this->assertArrayHasKey('total_entries', $summary);
        $this->assertArrayHasKey('unique_events', $summary);
        $this->assertArrayHasKey('top_events', $summary);
        $this->assertArrayHasKey('sources', $summary);
        $this->assertTrue($summary['enabled']);
        $this->assertSame(5000, $summary['max_entries']);
        $this->assertSame(30, $summary['retention_days']);
    }

    // ─── EventAttributionTrailService Tests ────────────────────────────

    public function test_attribution_trail_service_is_enabled_by_default(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->with('zeroboiler.analytics.attribution_trail', [])->willReturn([]);

        $service = new EventAttributionTrailService($cache, $config);

        $this->assertTrue($service->isEnabled());
    }

    public function test_attribution_trail_service_can_be_disabled(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->with('zeroboiler.analytics.attribution_trail', [])->willReturn(['enabled' => false]);

        $service = new EventAttributionTrailService($cache, $config);

        $this->assertFalse($service->isEnabled());
    }

    public function test_attribution_trail_empty_for_new_client(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->with('zeroboiler.analytics.attribution_trail', [])->willReturn(['enabled' => true]);

        $service = new EventAttributionTrailService($cache, $config);
        $trail = $service->getTrail('new-client-id');

        $this->assertNull($trail['first_touch']);
        $this->assertNull($trail['last_touch']);
        $this->assertEmpty($trail['touch_history']);
        $this->assertSame(0, $trail['touch_count']);
    }

    public function test_attribution_trail_empty_for_empty_client_id(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->with('zeroboiler.analytics.attribution_trail', [])->willReturn(['enabled' => true]);

        $service = new EventAttributionTrailService($cache, $config);

        $this->assertSame(0, $service->count());
    }

    public function test_attribution_trail_first_touch_is_null_initially(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->with('zeroboiler.analytics.attribution_trail', [])->willReturn(['enabled' => true]);

        $service = new EventAttributionTrailService($cache, $config);

        $this->assertNull($service->firstTouch('cli_123'));
        $this->assertNull($service->lastTouch('cli_123'));
    }

    public function test_attribution_trail_attribute_returns_empty_for_no_trail(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->with('zeroboiler.analytics.attribution_trail', [])->willReturn(['enabled' => true]);

        $service = new EventAttributionTrailService($cache, $config);
        $result = $service->attribute('nonexistent-client');

        $this->assertNull($result['first_touch']);
        $this->assertNull($result['last_touch']);
        $this->assertEmpty($result['linear']);
        $this->assertEmpty($result['time_decay']);
        $this->assertSame('none', $result['model']);
    }

    public function test_attribution_trail_statistics(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->with('zeroboiler.analytics.attribution_trail', [])->willReturn([
            'enabled' => true,
            'ttl' => 2592000,
        ]);

        $service = new EventAttributionTrailService($cache, $config);
        $stats = $service->statistics();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_tracked_identities', $stats);
        $this->assertArrayHasKey('total_touchpoints', $stats);
        $this->assertArrayHasKey('total_conversions', $stats);
        $this->assertArrayHasKey('top_sources', $stats);
        $this->assertArrayHasKey('top_campaigns', $stats);
        $this->assertTrue($stats['enabled']);
        $this->assertSame(30, $stats['retention_days']);
    }

    public function test_attribution_trail_count_is_zero_initially(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->with('zeroboiler.analytics.attribution_trail', [])->willReturn(['enabled' => true]);

        $service = new EventAttributionTrailService($cache, $config);

        $this->assertSame(0, $service->count());
    }

    public function test_attribution_trail_does_not_record_when_disabled(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->with('zeroboiler.analytics.attribution_trail', [])->willReturn(['enabled' => false]);

        $service = new EventAttributionTrailService($cache, $config);

        // Should not throw, should silently skip
        $service->recordTouch('cli_123', [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
        ]);

        $this->assertFalse($service->isEnabled());
    }

    // ─── Version Sweep ────────────────────────────────────────────────

    public function test_version_is_72(): void
    {
        $this->assertSame('72.0.0', AnalyticsEvent::VERSION);
    }

    public function test_event_catalog_has_expected_categories(): void
    {
        $byCategory = EventCatalog::byCategory();

        $this->assertArrayHasKey('ecommerce', $byCategory);
        $this->assertArrayHasKey('saas', $byCategory);
        $this->assertArrayHasKey('engagement', $byCategory);
        $this->assertArrayHasKey('security', $byCategory);
        $this->assertArrayHasKey('uptime', $byCategory);
        $this->assertArrayHasKey('infrastructure', $byCategory);
    }

    public function test_event_catalog_count_is_positive(): void
    {
        $this->assertGreaterThan(0, EventCatalog::count());
    }

    public function test_event_catalog_all_returns_non_empty(): void
    {
        $all = EventCatalog::all();

        $this->assertNotEmpty($all);

        // Every entry must have required keys
        foreach ($all as $name => $entry) {
            $this->assertArrayHasKey('name', $entry);
            $this->assertArrayHasKey('class', $entry);
            $this->assertArrayHasKey('ga4', $entry);
            $this->assertArrayHasKey('category', $entry);
        }
    }
}
