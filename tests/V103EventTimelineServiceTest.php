<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventTimelineService;
use ZeroBoiler\Analytics\Services\IdentityResolutionService;
use PHPUnit\Framework\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepo;

/**
 * @covers \ZeroBoiler\Analytics\Services\EventTimelineService
 *
 * @since 75.0.0
 */
final class V103EventTimelineServiceTest extends TestCase
{
    private CacheRepo $cache;

    private IdentityResolutionService $identityService;

    private array $config;

    protected function setUp(): void
    {
        parent::setUp();

        $store = new ArrayStore();
        $this->cache = new CacheRepo($store);
        $this->identityService = $this->createMock(IdentityResolutionService::class);
        $this->config = [
            'enabled' => true,
            'cache_ttl' => 3600,
            'max_entries' => 500,
            'session_timeout' => 1800,
            'gap_thresholds' => [
                'trial_start_to_login' => 172800,
                'signup_to_trial' => 604800,
            ],
        ];
    }

    private function createService(array $overrides = []): EventTimelineService
    {
        return new EventTimelineService(
            $this->cache,
            $this->identityService,
            array_merge($this->config, $overrides),
        );
    }

    private function makeEvent(string $name, array $params = [], ?string $userId = null, ?int $timestamp = null): AnalyticsEvent
    {
        return new AnalyticsEvent(
            name: $name,
            params: $params,
            clientId: 'client-123',
            userId: $userId,
            timestamp: $timestamp !== null
                ? (new \DateTimeImmutable('@' . $timestamp))
                : null,
        );
    }

    // ─── isEnabled ───────────────────────────────────────────────────────

    public function test_enabled_by_default(): void
    {
        $service = $this->createService();
        $this->assertTrue($service->isEnabled());
    }

    public function test_disabled_when_config_disabled(): void
    {
        $service = $this->createService(['enabled' => false]);
        $this->assertFalse($service->isEnabled());
    }

    // ─── record ──────────────────────────────────────────────────────────

    public function test_record_stores_event_in_timeline(): void
    {
        $service = $this->createService();
        $event = $this->makeEvent('page_view');

        $service->record('client-123', $event, ['ga4', 'meta']);

        $timeline = $service->getTimeline('client-123');

        $this->assertCount(1, $timeline);
        $this->assertSame('page_view', $timeline[0]['event_name']);
        $this->assertSame('client-123', $timeline[0]['client_id']);
        $this->assertSame(['ga4', 'meta'], $timeline[0]['providers']);
    }

    public function test_record_appends_category_from_catalog(): void
    {
        $service = $this->createService();
        $event = $this->makeEvent('sign_up');

        $service->record('client-abc', $event);

        $timeline = $service->getTimeline('client-abc');

        $this->assertSame('saas', $timeline[0]['category']);
    }

    public function test_record_multiple_events_preserves_order(): void
    {
        $service = $this->createService();
        $event1 = $this->makeEvent('page_view', [], null, 1000);
        $event2 = $this->makeEvent('login', [], 'user-1', 2000);
        $event3 = $this->makeEvent('purchase', [], 'user-1', 3000);

        $service->record('client-xyz', $event1);
        $service->record('client-xyz', $event2);
        $service->record('client-xyz', $event3);

        $timeline = $service->getTimeline('client-xyz');

        // Newest first (reversed)
        $this->assertCount(3, $timeline);
        $this->assertSame('purchase', $timeline[0]['event_name']);
        $this->assertSame('login', $timeline[1]['event_name']);
        $this->assertSame('page_view', $timeline[2]['event_name']);
    }

    public function test_record_does_nothing_when_disabled(): void
    {
        $service = $this->createService(['enabled' => false]);
        $event = $this->makeEvent('page_view');

        $service->record('client-123', $event);

        $this->assertCount(0, $service->getTimeline('client-123'));
    }

    // ─── getTimeline ────────────────────────────────────────────────────

    public function test_get_timeline_returns_empty_for_unknown_client(): void
    {
        $service = $this->createService();

        $this->assertCount(0, $service->getTimeline('nonexistent'));
    }

    public function test_get_timeline_respects_limit(): void
    {
        $service = $this->createService(['max_entries' => 100]);

        for ($i = 0; $i < 10; $i++) {
            $service->record('client-lim', $this->makeEvent('event_' . $i, [], null, 1000 + $i));
        }

        $timeline = $service->getTimeline('client-lim', 3);

        $this->assertCount(3, $timeline);
    }

    public function test_get_timeline_respects_offset(): void
    {
        $service = $this->createService();

        for ($i = 0; $i < 5; $i++) {
            $service->record('client-off', $this->makeEvent('event_' . $i, [], null, 1000 + $i));
        }

        $timeline = $service->getTimeline('client-off', 0, 2);

        // Newest first: event_4, event_3, event_2, event_1, event_0
        // After offset 2: event_2, event_1, event_0
        $this->assertCount(3, $timeline);
        $this->assertSame('event_2', $timeline[0]['event_name']);
    }

    // ─── getTimelineCount ────────────────────────────────────────────────

    public function test_timeline_count_matches_recorded_events(): void
    {
        $service = $this->createService();

        $service->record('client-count', $this->makeEvent('page_view'));
        $service->record('client-count', $this->makeEvent('click'));
        $service->record('client-count', $this->makeEvent('search'));

        $this->assertSame(3, $service->getTimelineCount('client-count'));
    }

    // ─── getUserTimeline ────────────────────────────────────────────────

    public function test_get_user_timeline_merges_client_timelines(): void
    {
        $this->identityService->method('getClientIdsForUser')
            ->with('user-42')
            ->willReturn(['client-a', 'client-b']);

        $service = $this->createService();

        $service->record('client-a', $this->makeEvent('page_view', [], 'user-42', 1000));
        $service->record('client-a', $this->makeEvent('login', [], 'user-42', 2000));
        $service->record('client-b', $this->makeEvent('purchase', [], 'user-42', 3000));

        $timeline = $service->getUserTimeline('user-42');

        // Chronological (oldest first)
        $this->assertCount(3, $timeline);
        $this->assertSame('page_view', $timeline[0]['event_name']);
        $this->assertSame('login', $timeline[1]['event_name']);
        $this->assertSame('purchase', $timeline[2]['event_name']);
    }

    public function test_get_user_timeline_respects_limit(): void
    {
        $this->identityService->method('getClientIdsForUser')
            ->with('user-42')
            ->willReturn(['client-a', 'client-b']);

        $service = $this->createService();

        $service->record('client-a', $this->makeEvent('page_view', [], null, 1000));
        $service->record('client-a', $this->makeEvent('login', [], null, 2000));
        $service->record('client-b', $this->makeEvent('purchase', [], null, 3000));

        $timeline = $service->getUserTimeline('user-42', 2);

        // Returns last 2 events chronologically
        $this->assertCount(2, $timeline);
    }

    // ─── detectGaps ─────────────────────────────────────────────────────

    public function test_detect_gaps_finds_no_gaps_when_events_complete(): void
    {
        $service = $this->createService();

        $now = time();
        $service->record('client-gap', $this->makeEvent('start_trial', [], null, $now - 3600));
        $service->record('client-gap', $this->makeEvent('login', [], null, $now - 1800));

        $gaps = $service->detectGaps('client-gap');

        $this->assertCount(0, $gaps);
    }

    public function test_detect_gaps_returns_empty_when_no_thresholds(): void
    {
        $service = $this->createService(['gap_thresholds' => []]);

        $service->record('client-gap2', $this->makeEvent('start_trial'));

        $gaps = $service->detectGaps('client-gap2');

        $this->assertCount(0, $gaps);
    }

    public function test_detect_gaps_returns_empty_for_empty_timeline(): void
    {
        $service = $this->createService();

        $gaps = $service->detectGaps('nonexistent');

        $this->assertCount(0, $gaps);
    }

    // ─── getSessionGroups ────────────────────────────────────────────────

    public function test_session_groups_single_session(): void
    {
        $service = $this->createService(['session_timeout' => 1800]);

        $service->record('client-sess', $this->makeEvent('page_view', [], null, 1000));
        $service->record('client-sess', $this->makeEvent('click', [], null, 1100));
        $service->record('client-sess', $this->makeEvent('login', [], null, 1200));

        $sessions = $service->getSessionGroups('client-sess');

        $this->assertCount(1, $sessions);
        $this->assertSame(3, $sessions[0]['event_count']);
        $this->assertSame(1000, $sessions[0]['start_time']);
        $this->assertSame(1200, $sessions[0]['end_time']);
    }

    public function test_session_groups_splits_on_timeout(): void
    {
        $service = $this->createService(['session_timeout' => 500]);

        $service->record('client-sess2', $this->makeEvent('page_view', [], null, 1000));
        $service->record('client-sess2', $this->makeEvent('click', [], null, 1100));
        // Gap > 500 seconds
        $service->record('client-sess2', $this->makeEvent('login', [], null, 2000));
        $service->record('client-sess2', $this->makeEvent('purchase', [], null, 2100));

        $sessions = $service->getSessionGroups('client-sess2');

        $this->assertCount(2, $sessions);
        $this->assertSame(2, $sessions[0]['event_count']);
        $this->assertSame(2, $sessions[1]['event_count']);
        $this->assertSame('page_view', $sessions[0]['events'][0]['event_name']);
        $this->assertSame('login', $sessions[1]['events'][0]['event_name']);
    }

    public function test_session_groups_empty_for_unknown_client(): void
    {
        $service = $this->createService();

        $this->assertCount(0, $service->getSessionGroups('nonexistent'));
    }

    // ─── clearTimeline / clearUserTimelines ─────────────────────────────

    public function test_clear_timeline_removes_all_events(): void
    {
        $service = $this->createService();

        $service->record('client-clear', $this->makeEvent('page_view'));
        $service->record('client-clear', $this->makeEvent('click'));

        $this->assertSame(2, $service->getTimelineCount('client-clear'));

        $service->clearTimeline('client-clear');

        $this->assertSame(0, $service->getTimelineCount('client-clear'));
    }

    public function test_clear_user_timelines_clears_all_linked_clients(): void
    {
        $this->identityService->method('getClientIdsForUser')
            ->with('user-99')
            ->willReturn(['client-x', 'client-y']);

        $service = $this->createService();

        $service->record('client-x', $this->makeEvent('page_view'));
        $service->record('client-y', $this->makeEvent('login'));

        $service->clearUserTimelines('user-99');

        $this->assertSame(0, $service->getTimelineCount('client-x'));
        $this->assertSame(0, $service->getTimelineCount('client-y'));
    }

    // ─── stats ──────────────────────────────────────────────────────────

    public function test_stats_returns_configured_values(): void
    {
        $service = $this->createService();

        $stats = $service->stats();

        $this->assertTrue($stats['enabled']);
        $this->assertSame(3600, $stats['cache_ttl']);
        $this->assertSame(500, $stats['max_entries']);
        $this->assertSame(1800, $stats['session_timeout']);
        $this->assertSame(2, $stats['gap_thresholds_count']);
    }

    public function test_stats_reflects_disabled_state(): void
    {
        $service = $this->createService(['enabled' => false]);

        $stats = $service->stats();

        $this->assertFalse($stats['enabled']);
    }

    // ─── max entries FIFO eviction ───────────────────────────────────────

    public function test_max_entries_eviction_trims_oldest(): void
    {
        $service = $this->createService(['max_entries' => 3]);

        $service->record('client-fifo', $this->makeEvent('event_1', [], null, 1000));
        $service->record('client-fifo', $this->makeEvent('event_2', [], null, 2000));
        $service->record('client-fifo', $this->makeEvent('event_3', [], null, 3000));
        // This should evict event_1
        $service->record('client-fifo', $this->makeEvent('event_4', [], null, 4000));

        $timeline = $service->getTimeline('client-fifo');

        $this->assertCount(3, $timeline);
        // Newest first: event_4, event_3, event_2 (event_1 evicted)
        $names = array_column($timeline, 'event_name');
        $this->assertNotContains('event_1', $names);
    }

    // ─── user_id and params propagation ─────────────────────────────────

    public function test_record_propagates_user_id_and_params(): void
    {
        $service = $this->createService();
        $event = $this->makeEvent('purchase', ['value' => 99.99], 'user-55', 5000);

        $service->record('client-uid', $event, ['ga4']);

        $timeline = $service->getTimeline('client-uid');

        $this->assertSame('user-55', $timeline[0]['user_id']);
        $this->assertSame(['value' => 99.99], $timeline[0]['params']);
        $this->assertSame('ecommerce', $timeline[0]['category']);
    }
}
