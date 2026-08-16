<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;

/**
 * Lightweight session replay service for user journey reconstruction.
 *
 * Stores and retrieves recent analytics events per session in the cache,
 * enabling support teams and product managers to reconstruct what a user
 * did during their session. Useful for debugging, support ticket resolution,
 * and user behavior analysis.
 *
 * Events are stored in a ring buffer per session with configurable TTL.
 * Supports retrieval as a timeline with ordering and metadata.
 *
 * @see \ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController
 *
 * @since 1.0.0
 */
final class SessionReplayService
{
    /**
     * Version for cache key compatibility.
     */
    public const VERSION = '5.0.0';

    private const CACHE_PREFIX = 'zb_session_replay_';

    private const DEFAULT_MAX_EVENTS = 200;

    private const DEFAULT_TTL = 3600; // 1 hour

    private CacheRepository $cache;

    private int $maxEvents;

    private int $ttl;

    /**
     * @param  CacheRepository  $cache  Cache driver instance
     * @param  int  $maxEvents  Maximum events per session (ring buffer size)
     * @param  int  $ttl  Session replay TTL in seconds
     */
    public function __construct(
        CacheRepository $cache,
        int $maxEvents = self::DEFAULT_MAX_EVENTS,
        int $ttl = self::DEFAULT_TTL,
    ): void  {
        $this->cache = $cache;
        $this->maxEvents = $maxEvents;
        $this->ttl = $ttl;
    }

    /**
     * Record an event for session replay.
     *
     * @param  string  $sessionId  Session identifier
     * @param  array{name: string, params?: array<string, mixed>, client_id?: string|null, user_id?: string|null, timestamp?: int|null, category?: string|null, priority?: string|null}  $event  Event data
     */
    public function record(string $sessionId, array $event): void
    {
        $key = self::CACHE_PREFIX . $sessionId;
        $events = $this->getEvents($key);

        // Add timestamp if not provided
        if (! isset($event['timestamp'])) {
            $event['timestamp'] = time();
        }

        // Add sequence number
        $event['_seq'] = count($events);

        $events[] = $event;

        // Ring buffer: keep only the most recent N events
        if (count($events) > $this->maxEvents) {
            $events = array_slice($events, -$this->maxEvents);
        }

        $this->cache->put($key, $events, $this->ttl);
    }

    /**
     * Get the session replay timeline for a given session.
     *
     * @param  string  $sessionId  Session identifier
     * @return array{session_id: string, event_count: int, duration_seconds: int|null, events: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function getTimeline(string $sessionId): array
    {
        $events = $this->getSessionEvents($sessionId);

        $duration = null;
        if (count($events) >= 2) {
            $first = $events[0]['timestamp'] ?? null;
            $last = $events[count($events) - 1]['timestamp'] ?? null;

            if ($first !== null && $last !== null) {
                $duration = (int) ($last - $first);
            }
        }

        // Event name summary
        $summary = [];
        foreach ($events as $event) {
            $name = $event['name'] ?? 'unknown';
            $summary[$name] = ($summary[$name] ?? 0) + 1;
        }

        // Sort summary by count descending
        arsort($summary);

        return [
            'session_id' => $sessionId,
            'event_count' => count($events),
            'duration_seconds' => $duration,
            'events' => $events,
            'summary' => $summary,
        ];
    }

    /**
     * Get session events for a given session.
     *
     * @param  string  $sessionId
     * @return list<array<string, mixed>>
     */
    public function getSessionEvents(string $sessionId): array
    {
        return $this->getEvents(self::CACHE_PREFIX . $sessionId);
    }

    /**
     * Get a condensed session summary (event names and counts only).
     *
     * @param  string  $sessionId
     * @return array{event_count: int, top_events: array<string, int>, has_revenue_events: bool, has_error_events: bool}
     */
    public function getSessionSummary(string $sessionId): array
    {
        $events = $this->getSessionEvents($sessionId);
        $summary = [];
        $hasRevenue = false;
        $hasErrors = false;

        $revenueEvents = [
            'purchase', 'subscribe', 'payment_succeeded', 'trial_converted',
            'plan_upgrade', 'add_to_cart', 'begin_checkout', 'revenue_tracked',
        ];

        $errorEvents = ['error', 'js_error', 'payment_failed', 'billing_retry'];

        foreach ($events as $event) {
            $name = $event['name'] ?? 'unknown';
            $summary[$name] = ($summary[$name] ?? 0) + 1;

            if (in_array($name, $revenueEvents, true)) {
                $hasRevenue = true;
            }

            if (in_array($name, $errorEvents, true)) {
                $hasErrors = true;
            }
        }

        arsort($summary);

        return [
            'event_count' => count($events),
            'top_events' => array_slice($summary, 0, 10),
            'has_revenue_events' => $hasRevenue,
            'has_error_events' => $hasErrors,
        ];
    }

    /**
     * Get the session replay for a specific user across all their recent sessions.
     *
     * Searches for sessions associated with the given user ID.
     * Note: This requires sessions to be indexed by user ID when recorded.
     *
     * @param  string  $userId  User identifier
     * @return list<array{session_id: string, event_count: int, duration_seconds: int|null}>
     */
    public function getUserSessions(string $userId): array
    {
        $indexKey = self::CACHE_PREFIX . 'user_' . $userId;
        /** @var list<string>|null $sessionIds */
        $sessionIds = $this->cache->get($indexKey);

        if ($sessionIds === null || $sessionIds === []) {
            return [];
        }

        $sessions = [];

        foreach (array_slice($sessionIds, -10) as $sessionId) {
            $timeline = $this->getTimeline($sessionId);
            $sessions[] = [
                'session_id' => $sessionId,
                'event_count' => $timeline['event_count'],
                'duration_seconds' => $timeline['duration_seconds'],
            ];
        }

        return $sessions;
    }

    /**
     * Index a session for user lookup.
     *
     * @param  string  $userId  User identifier
     * @param  string  $sessionId  Session identifier
     */
    public function indexSessionForUser(string $userId, string $sessionId): void
    {
        $indexKey = self::CACHE_PREFIX . 'user_' . $userId;
        /** @var list<string>|null $sessionIds */
        $sessionIds = $this->cache->get($indexKey) ?? [];

        // Avoid duplicates
        if (! in_array($sessionId, $sessionIds, true)) {
            $sessionIds[] = $sessionId;
        }

        // Keep last 50 sessions
        if (count($sessionIds) > 50) {
            $sessionIds = array_slice($sessionIds, -50);
        }

        $this->cache->put($indexKey, $sessionIds, $this->ttl);
    }

    /**
     * Clear the session replay data for a given session.
     */
    public function clearSession(string $sessionId): void
    {
        $this->cache->forget(self::CACHE_PREFIX . $sessionId);
    }

    /**
     * Clear all session replay data for a given user.
     */
    public function clearUserSessions(string $userId): void
    {
        $indexKey = self::CACHE_PREFIX . 'user_' . $userId;
        /** @var list<string>|null $sessionIds */
        $sessionIds = $this->cache->get($indexKey);

        if ($sessionIds !== null) {
            foreach ($sessionIds as $sessionId) {
                $this->cache->forget(self::CACHE_PREFIX . $sessionId);
            }
        }

        $this->cache->forget($indexKey);
    }

    /**
     * Get the total number of active sessions in the replay buffer.
     *
     * Note: This is a convenience method that returns 0 since cache keys
     * cannot be enumerated. Use tracking counters for accurate counts.
     */
    public function activeSessionCount(): int
    {
        // Cache drivers don't support key enumeration efficiently.
        // Return counter-based value if available.
        $count = $this->cache->get(self::CACHE_PREFIX . 'counter');

        return is_int($count) ? $count : 0;
    }

    /**
     * Increment the active session counter.
     */
    public function incrementSessionCounter(): void
    {
        $key = self::CACHE_PREFIX . 'counter';
        $this->cache->increment($key);
    }

    /**
     * Check if replay is enabled in config.
     */
    public static function isEnabled(): bool
    {
        return (bool) config('zeroboiler.analytics.session_replay.enabled', false);
    }

    /**
     * Get events from cache.
     *
     * @param  string  $key  Cache key
     * @return list<array<string, mixed>>
     */
    private function getEvents(string $key): array
    {
        /** @var list<array<string, mixed>>|null $events */
        $events = $this->cache->get($key);

        return is_array($events) ? $events : [];
    }
}
