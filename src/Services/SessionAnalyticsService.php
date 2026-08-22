<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;

/**
 * Session-level analytics aggregation service.
 *
 * Provides session-based event counting, session summaries, and
 * session-level engagement metrics. Designed for dashboards and
 * real-time monitoring of user sessions.
 *
 * @since 1.0.0
 */
final class SessionAnalyticsService
{
    /** @var array<string, array{events: list<string>, started_at: string|null, last_event_at: string|null, user_id: string|null, client_id: string|null, page_count: int}> */
    private array $sessions = [];

    /** @var int */
    private int $maxSessions;

    private AnalyticsManager $manager;

    private QueuedAnalyticsDispatcher $queue;

    private bool $async;

    /**
     * @param  AnalyticsManager  $manager
     * @param  QueuedAnalyticsDispatcher  $queue
     * @param  bool  $async  Use async dispatch
     * @param  int  $maxSessions  Maximum tracked sessions in memory
     */
    public function __construct(
        AnalyticsManager $manager,
        QueuedAnalyticsDispatcher $queue,
        bool $async = true,
        int $maxSessions = 10000,
    ){
        $this->manager = $manager;
        $this->queue = $queue;
        $this->async = $async;
        $this->maxSessions = $maxSessions;
    }

    /**
     * Record an event within a session.
     *
     * @param  string  $sessionId  Unique session identifier
     * @param  string  $eventName  Analytics event name
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string|null  $userId  Authenticated user ID
     * @param  string|null  $clientId  Client tracking ID
     */
    public function recordEvent(
        string $sessionId,
        string $eventName,
        array $params = [],
        ?string $userId = null,
        ?string $clientId = null,
    ): void {
        $now = date('c');

        if (! isset($this->sessions[$sessionId])) {
            if (count($this->sessions) >= $this->maxSessions) {
                // Evict oldest session (first key)
                array_shift($this->sessions);
            }

            $this->sessions[$sessionId] = [
                'events' => [],
                'started_at' => $now,
                'last_event_at' => $now,
                'user_id' => $userId,
                'client_id' => $clientId,
                'page_count' => 0,
            ];
        }

        $this->sessions[$sessionId]['events'][] = $eventName;
        $this->sessions[$sessionId]['last_event_at'] = $now;

        if ($userId !== null) {
            $this->sessions[$sessionId]['user_id'] = $userId;
        }
        if ($clientId !== null) {
            $this->sessions[$sessionId]['client_id'] = $clientId;
        }

        if ($eventName === 'page_view' || $eventName === 'screen_view') {
            $this->sessions[$sessionId]['page_count']++;
        }
    }

    /**
     * Get a session summary.
     *
     * @param  string  $sessionId
     * @return array{session_id: string, event_count: int, page_count: int, event_types: list<string>, unique_events: int, duration_estimate: int|null, user_id: string|null, client_id: string|null, started_at: string|null, last_event_at: string|null}|null
     */
    public function getSessionSummary(string $sessionId): ?array
    {
        $session = $this->sessions[$sessionId] ?? null;

        if ($session === null) {
            return null;
        }

        $startedAt = $session['started_at'] !== null
            ? strtotime($session['started_at'])
            : null;
        $lastEventAt = $session['last_event_at'] !== null
            ? strtotime($session['last_event_at'])
            : null;

        return [
            'session_id' => $sessionId,
            'event_count' => count($session['events']),
            'page_count' => $session['page_count'],
            'event_types' => array_values(array_unique($session['events'])),
            'unique_events' => count(array_unique($session['events'])),
            'duration_estimate' => ($startedAt !== null && $lastEventAt !== null)
                ? $lastEventAt - $startedAt
                : null,
            'user_id' => $session['user_id'],
            'client_id' => $session['client_id'],
            'started_at' => $session['started_at'],
            'last_event_at' => $session['last_event_at'],
        ];
    }

    /**
     * Get aggregated stats across all tracked sessions.
     *
     * @return array{total_sessions: int, total_events: int, total_page_views: int, avg_events_per_session: float, event_frequency: array<string, int>, sessions_with_identity: int, sessions_anonymous: int}
     */
    public function getAggregatedStats(): array
    {
        $totalSessions = count($this->sessions);
        $totalEvents = 0;
        $totalPageViews = 0;
        $eventFrequency = [];
        $sessionsWithIdentity = 0;

        foreach ($this->sessions as $session) {
            $eventCount = count($session['events']);
            $totalEvents += $eventCount;
            $totalPageViews += $session['page_count'];

            foreach ($session['events'] as $event) {
                $eventFrequency[$event] = ($eventFrequency[$event] ?? 0) + 1;
            }

            if ($session['user_id'] !== null) {
                $sessionsWithIdentity++;
            }
        }

        arsort($eventFrequency);

        return [
            'total_sessions' => $totalSessions,
            'total_events' => $totalEvents,
            'total_page_views' => $totalPageViews,
            'avg_events_per_session' => $totalSessions > 0
                ? round($totalEvents / $totalSessions, 2)
                : 0.0,
            'event_frequency' => $eventFrequency,
            'sessions_with_identity' => $sessionsWithIdentity,
            'sessions_anonymous' => $totalSessions - $sessionsWithIdentity,
        ];
    }

    /**
     * End a session and dispatch a session summary event.
     *
     * Sends an `analytics_session_summary` event with aggregated stats
     * to all configured providers.
     *
     * @param  string  $sessionId
     * @param  array<string, mixed>  $extraParams  Additional params to include
     */
    public function endSession(string $sessionId, array $extraParams = []): void
    {
        $summary = $this->getSessionSummary($sessionId);

        if ($summary === null) {
            return;
        }

        $eventParams = array_merge([
            'session_id' => $sessionId,
            'session_event_count' => $summary['event_count'],
            'session_page_count' => $summary['page_count'],
            'session_unique_events' => $summary['unique_events'],
            'session_duration_estimate' => $summary['duration_estimate'],
            'session_event_types' => $summary['event_types'],
        ], $extraParams);

        if ($this->async) {
            $this->queue->dispatch(
                new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
                    name: 'analytics_session_summary',
                    params: $eventParams,
                    userId: $summary['user_id'],
                    clientId: $summary['client_id'],
                ),
            );
        } else {
            $this->manager->trackEvent(
                new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
                    name: 'analytics_session_summary',
                    params: $eventParams,
                    userId: $summary['user_id'],
                    clientId: $summary['client_id'],
                ),
            );
        }

        unset($this->sessions[$sessionId]);
    }

    /**
     * Get the number of currently tracked sessions.
     */
    public function trackedSessionCount(): int
    {
        return count($this->sessions);
    }

    /**
     * Clear all tracked sessions from memory.
     */
    public function flush(): void
    {
        $this->sessions = [];
    }
}
