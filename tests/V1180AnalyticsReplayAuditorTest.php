<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\AnalyticsReplayAuditor;

/**
 * Tests for the AnalyticsReplayAuditor service.
 *
 * @covers \ZeroBoiler\Analytics\Services\AnalyticsReplayAuditor
 *
 * @since 118.0.0
 */
final class V1180AnalyticsReplayAuditorTest extends TestCase
{
    private CacheRepository&MockObject $cache;
    private ConfigRepository&MockObject $config;
    private AnalyticsReplayAuditor $auditor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = $this->createMock(CacheRepository::class);
        $this->config = $this->createMock(ConfigRepository::class);

        // Default config: replay audit enabled
        $this->config->method('get')
            ->willReturnMap([
                ['zeroboiler.analytics.replay_audit', [], [
                    'enabled' => true,
                    'ttl' => 604800,
                    'max_attempts' => 3,
                    'replay_ttl' => 86400,
                ]],
                ['zeroboiler.analytics.replay_audit.ttl', 604800, 604800],
            ]);

        $this->auditor = new AnalyticsReplayAuditor($this->cache, $this->config);
    }

    public function test_record_replay_success(): void
    {
        $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99]);

        $this->cache->expects($this->once())
            ->method('put')
            ->with(
                $this->stringContains('zb_replay_audit_'),
                $this->callback(fn (array $entry): bool =>
                    $entry['event_name'] === 'purchase' &&
                    $entry['provider'] === 'ga4' &&
                    $entry['success'] === true
                ),
                604800,
            );

        $result = $this->auditor->record($event, 'ga4', true);

        $this->assertArrayHasKey('audit_id', $result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertSame('purchase', $result['event_name']);
        $this->assertSame('ga4', $result['provider']);
        $this->assertTrue($result['success']);
    }

    public function test_record_replay_failure_with_error(): void
    {
        $event = new AnalyticsEvent(name: 'page_view', params: []);

        $this->cache->expects($this->once())
            ->method('put')
            ->with(
                $this->anything(),
                $this->callback(fn (array $entry): bool =>
                    $entry['success'] === false &&
                    $entry['error'] === 'Network timeout'
                ),
                $this->anything(),
            );

        $result = $this->auditor->record($event, 'meta', false, 'Network timeout', 2);

        $this->assertSame(2, $result['attempt']);
        $this->assertFalse($result['success']);
        $this->assertSame('Network timeout', $result['error']);
    }

    public function test_summary_returns_empty_when_no_data(): void
    {
        $this->cache->method('get')
            ->with('zb_replay_audit_global_summary', $this->anything())
            ->willReturn(null);

        $summary = $this->auditor->summary();

        $this->assertSame(0, $summary['total_replays']);
        $this->assertSame(0, $summary['successful']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame(0.0, $summary['success_rate']);
        $this->assertSame([], $summary['by_provider']);
    }

    public function test_summary_returns_cached_stats(): void
    {
        $cachedStats = [
            'total' => 100,
            'successful' => 85,
            'failed' => 15,
            'by_provider' => [
                'ga4' => ['total' => 60, 'success' => 55, 'failed' => 5],
                'meta' => ['total' => 40, 'success' => 30, 'failed' => 10],
            ],
        ];

        $this->cache->method('get')
            ->with('zb_replay_audit_global_summary', $this->anything())
            ->willReturn($cachedStats);

        $summary = $this->auditor->summary();

        $this->assertSame(100, $summary['total_replays']);
        $this->assertSame(85, $summary['successful']);
        $this->assertSame(15, $summary['failed']);
        $this->assertSame(85.0, $summary['success_rate']);
        $this->assertCount(2, $summary['by_provider']);
    }

    public function test_validate_replay_allowed(): void
    {
        // No prior audit entries
        $this->cache->method('get')
            ->willReturn(null);

        $event = new AnalyticsEvent(name: 'purchase', params: []);

        $result = $this->auditor->validateReplay($event, 'ga4');

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['reason']);
    }

    public function test_validate_replay_blocked_when_max_attempts_exceeded(): void
    {
        $auditId = md5('purchase:ga4');

        $this->cache->method('get')
            ->willReturnMap([
                ['zb_replay_audit_' . $auditId, null, ['attempt' => 3, 'success' => false]],
            ]);

        $event = new AnalyticsEvent(name: 'purchase', params: []);

        $result = $this->auditor->validateReplay($event, 'ga4');

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('Maximum replay attempts', $result['reason']);
    }

    public function test_validate_replay_blocked_when_disabled(): void
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')
            ->willReturnMap([
                ['zeroboiler.analytics.replay_audit', [], ['enabled' => false, 'max_attempts' => 3, 'replay_ttl' => 86400]],
                ['zeroboiler.analytics.replay_audit.ttl', 604800, 604800],
            ]);

        $auditor = new AnalyticsReplayAuditor($this->cache, $config);
        $event = new AnalyticsEvent(name: 'test', params: []);

        $result = $auditor->validateReplay($event, 'ga4');

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('globally disabled', $result['reason']);
    }

    public function test_increment_stats_success(): void
    {
        $initialStats = [
            'total' => 10,
            'successful' => 8,
            'failed' => 2,
            'by_provider' => [
                'ga4' => ['total' => 10, 'success' => 8, 'failed' => 2],
            ],
        ];

        $this->cache->method('get')
            ->with('zb_replay_audit_global_summary', $this->anything())
            ->willReturn($initialStats);

        $this->cache->expects($this->once())
            ->method('put')
            ->with(
                'zb_replay_audit_global_summary',
                $this->callback(function (array $stats): bool {
                    return $stats['total'] === 11 &&
                        $stats['successful'] === 9 &&
                        $stats['by_provider']['ga4']['success'] === 9;
                }),
                604800,
            );

        $this->auditor->incrementStats('ga4', true);
    }

    public function test_increment_stats_failure(): void
    {
        $initialStats = [
            'total' => 5,
            'successful' => 4,
            'failed' => 1,
            'by_provider' => [],
        ];

        $this->cache->method('get')
            ->with('zb_replay_audit_global_summary', $this->anything())
            ->willReturn($initialStats);

        $this->cache->expects($this->once())
            ->method('put')
            ->with(
                $this->anything(),
                $this->callback(function (array $stats): bool {
                    return $stats['total'] === 6 &&
                        $stats['failed'] === 2 &&
                        isset($stats['by_provider']['meta']['failed']);
                }),
                $this->anything(),
            );

        $this->auditor->incrementStats('meta', false);
    }

    public function test_clear_forgets_global_summary(): void
    {
        $this->cache->expects($this->once())
            ->method('forget')
            ->with('zb_replay_audit_global_summary');

        $this->auditor->clear();
    }

    public function test_get_for_event_returns_cached_entries(): void
    {
        $entries = [
            ['event_name' => 'purchase', 'provider' => 'ga4', 'success' => true],
            ['event_name' => 'purchase', 'provider' => 'meta', 'success' => false],
        ];

        $this->cache->method('get')
            ->with('zb_replay_audit_summary_purchase', null)
            ->willReturn($entries);

        $result = $this->auditor->getForEvent('purchase', 50);

        $this->assertCount(2, $result);
    }

    public function test_get_for_event_returns_empty_when_no_data(): void
    {
        $this->cache->method('get')
            ->willReturn(null);

        $result = $this->auditor->getForEvent('nonexistent');

        $this->assertSame([], $result);
    }
}
