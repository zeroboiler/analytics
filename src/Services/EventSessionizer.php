<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Session-aware event aggregation service for real-time SaaS dashboards.
 *
 * Groups events by session (client ID + session ID) and computes per-session
 * metrics: event counts, unique events, session duration, engagement scoring,
 * and conversion funnel progress. Designed for live dashboard widgets that
 * show "active sessions" and "session engagement distribution".
 *
 * Uses the cache driver as a ring buffer — sessions expire automatically via TTL.
 *
 * Inspired by Amplitude Session Explorer, Mixpanel Session Replay, and
 * PostHog Session Analytics.
 *
 * @since 8.0.0
 */
final class EventSessionizer
{
    /** @var non-empty-string */
    private string $cachePrefix;

    private int $sessionTtl;

    private int $maxSessionsPerClient;

    private CacheRepository $cache;

    /**
     * @param  CacheRepository  $cache
     * @param  array{cache_prefix?: string, session_ttl?: int, max_sessions_per_client?: int}  $config
     */
    public function __construct(CacheRepository $cache, array $config = []): void
    {
        $this->cache = $cache;
        $this->cachePrefix = $config['cache_prefix'] ?? 'zb_session_';
        $this->sessionTtl = $config['session_ttl'] ?? 1800; // 30 minutes
        $this->maxSessionsPerClient = $config['max_sessions_per_client'] ?? 50;
    }

    /**
     * Record an event in a session context.
     *
     * Extracts client_id and session_id from the event params, increments
     * counters, and updates session metadata (first event, last event,
     * unique events, duration estimate).
     *
     * @return array{session_id: string, event_count: int, unique_events: int, duration_estimate: float|null, engagement_score: float}
     */
    public function record(AnalyticsEvent $event): array
    {
        $clientId = $event->param('client_id') ?? $event->param('clientId') ?? 'anonymous';
        $sessionId = $event->param('session_id') ?? $event->param('sessionId') ?? $this->deriveSessionId($clientId);

        if (! is_string($clientId)) {
            $clientId = (string) $clientId;
        }
        if (! is_string($sessionId)) {
            $sessionId = (string) $sessionId;
        }

        $cacheKey = $this->cachePrefix . $clientId . ':' . $sessionId;

        /** @var array{event_count: int, unique_events: array<string, bool>, first_event: string|null, last_event: string|null, first_timestamp: float|null, last_timestamp: float|null, conversion_events: array<string, bool>}|null $session */
        $session = $this->cache->get($cacheKey);

        if ($session === null) {
            $session = $this->initializeSession($event, $sessionId);
        }

        $eventName = $event->name();
        $now = microtime(true);

        $session['event_count']++;
        $session['unique_events'][$eventName] = true;
        $session['last_event'] = $eventName;
        $session['last_timestamp'] = $now;

        // Track conversion events
        $conversionEvents = ['purchase', 'subscribe', 'sign_up', 'start_trial', 'trial_converted'];
        if (in_array($eventName, $conversionEvents, true)) {
            $session['conversion_events'][$eventName] = true;
        }

        $durationEstimate = $this->calculateDuration($session);
        $engagementScore = $this->calculateEngagement($session, $durationEstimate);

        // Store back in cache
        $this->cache->put($cacheKey, $session, $this->sessionTtl);

        // Prune old sessions for this client (keep max N)
        $this->pruneClientSessions($clientId);

        return [
            'session_id' => $sessionId,
            'event_count' => $session['event_count'],
            'unique_events' => count($session['unique_events']),
            'duration_estimate' => $durationEstimate,
            'engagement_score' => $engagementScore,
        ];
    }

    /**
     * Get session data for a specific session.
     *
     * @return array{session_id: string, event_count: int, unique_events: int, unique_event_names: list<string>, duration_estimate: float|null, engagement_score: float, first_event: string|null, last_event: string|null, has_conversion: bool, conversion_events: list<string>}|null
     */
    public function getSession(string $clientId, string $sessionId): ?array
    {
        $cacheKey = $this->cachePrefix . $clientId . ':' . $sessionId;

        /** @var array{event_count: int, unique_events: array<string, bool>, first_event: string|null, last_event: string|null, first_timestamp: float|null, last_timestamp: float|null, conversion_events: array<string, bool>}|null $session */
        $session = $this->cache->get($cacheKey);

        if ($session === null) {
            return null;
        }

        $durationEstimate = $this->calculateDuration($session);

        return [
            'session_id' => $sessionId,
            'event_count' => $session['event_count'],
            'unique_events' => count($session['unique_events']),
            'unique_event_names' => array_keys($session['unique_events']),
            'duration_estimate' => $durationEstimate,
            'engagement_score' => $this->calculateEngagement($session, $durationEstimate),
            'first_event' => $session['first_event'],
            'last_event' => $session['last_event'],
            'has_conversion' => count($session['conversion_events']) > 0,
            'conversion_events' => array_keys($session['conversion_events']),
        ];
    }

    /**
     * Get all active sessions for a client.
     *
     * Scans the cache prefix for sessions belonging to a client.
     * Performance depends on cache driver — Redis is recommended for production.
     *
     * @return list<array{session_id: string, event_count: int, unique_events: int, duration_estimate: float|null, engagement_score: float}>
     */
    public function getClientSessions(string $clientId): array
    {
        // Note: Full scan requires Redis or similar driver with key scanning.
        // For cache drivers without key scanning, this returns an empty array.
        // Use the track method to maintain a session index.
        $indexKey = $this->cachePrefix . 'index:' . $clientId;

        /** @var list<string>|null $sessionIds */
        $sessionIds = $this->cache->get($indexKey);

        if ($sessionIds === null || $sessionIds === []) {
            return [];
        }

        $sessions = [];
        foreach ($sessionIds as $sessionId) {
            $sessionData = $this->getSession($clientId, $sessionId);
            if ($sessionData !== null) {
                $sessions[] = $sessionData;
            }
        }

        return $sessions;
    }

    /**
     * Get aggregated session statistics across all active sessions.
     *
     * Useful for dashboard overview widgets: "X active sessions, avg Y events,
     * avg Z seconds, N% conversion rate".
     *
     * @return array{total_sessions: int, total_events: int, avg_events_per_session: float, avg_duration: float|null, conversion_rate: float, avg_engagement: float, top_events: list<string>}
     */
    public function aggregateStats(string $clientId): array
    {
        $sessions = $this->getClientSessions($clientId);

        if ($sessions === []) {
            return [
                'total_sessions' => 0,
                'total_events' => 0,
                'avg_events_per_session' => 0.0,
                'avg_duration' => null,
                'conversion_rate' => 0.0,
                'avg_engagement' => 0.0,
                'top_events' => [],
            ];
        }

        $totalEvents = 0;
        $totalDuration = 0.0;
        $durationCount = 0;
        $totalEngagement = 0.0;
        $conversionCount = 0;
        $eventFrequency = [];

        foreach ($sessions as $session) {
            $totalEvents += $session['event_count'];

            if ($session['duration_estimate'] !== null) {
                $totalDuration += $session['duration_estimate'];
                $durationCount++;
            }

            $totalEngagement += $session['engagement_score'];

            if ($session['has_conversion']) {
                $conversionCount++;
            }

            if (isset($session['unique_event_names'])) {
                foreach ($session['unique_event_names'] as $eventName) {
                    $eventFrequency[$eventName] = ($eventFrequency[$eventName] ?? 0) + 1;
                }
            }
        }

        // Sort by frequency descending
        arsort($eventFrequency);

        return [
            'total_sessions' => count($sessions),
            'total_events' => $totalEvents,
            'avg_events_per_session' => count($sessions) > 0 ? round($totalEvents / count($sessions), 2) : 0.0,
            'avg_duration' => $durationCount > 0 ? round($totalDuration / $durationCount, 2) : null,
            'conversion_rate' => count($sessions) > 0 ? round($conversionCount / count($sessions) * 100, 2) : 0.0,
            'avg_engagement' => count($sessions) > 0 ? round($totalEngagement / count($sessions), 2) : 0.0,
            'top_events' => array_slice(array_keys($eventFrequency), 0, 10),
        ];
    }

    /**
     * End a session explicitly (e.g., on logout or tab close).
     *
     * Marks the session as completed and stores final metrics.
     *
     * @return array{session_id: string, event_count: int, unique_events: int, duration: float|null, engagement_score: float, completed: bool}|null
     */
    public function endSession(string $clientId, string $sessionId): ?array
    {
        $sessionData = $this->getSession($clientId, $sessionId);

        if ($sessionData === null) {
            return null;
        }

        $completedKey = $this->cachePrefix . 'completed:' . $clientId . ':' . $sessionId;
        $completedSession = array_merge($sessionData, ['completed' => true]);
        $this->cache->put($completedKey, $completedSession, $this->sessionTtl * 2);
        $this->cache->forget($this->cachePrefix . $clientId . ':' . $sessionId);

        // Remove from index
        $this->removeSessionFromIndex($clientId, $sessionId);

        return $completedSession;
    }

    /**
     * Calculate session duration estimate.
     *
     * @param  array{first_timestamp: float|null, last_timestamp: float|null}  $session
     * @return float|null Duration in seconds, or null if only one event
     */
    private function calculateDuration(array $session): ?float
    {
        if ($session['first_timestamp'] !== null && $session['last_timestamp'] !== null) {
            return round($session['last_timestamp'] - $session['first_timestamp'], 2);
        }

        return null;
    }

    /**
     * Calculate engagement score (0-100) for a session.
     *
     * Based on: event diversity (unique events / event count), event frequency,
     * and whether the session contains conversion events.
     *
     * @param  array{event_count: int, unique_events: array<string, bool>, conversion_events: array<string, bool>}  $session
     */
    private function calculateEngagement(array $session, ?float $duration): float
    {
        $score = 0.0;

        // Diversity factor (0-30): unique events ratio
        if ($session['event_count'] > 0) {
            $diversity = count($session['unique_events']) / $session['event_count'];
            $score += min($diversity * 30, 30);
        }

        // Frequency factor (0-30): more events = more engaged
        $eventScore = min($session['event_count'] * 2, 30);
        $score += $eventScore;

        // Duration factor (0-20): longer sessions = more engaged
        if ($duration !== null) {
            $durationScore = min($duration / 60 * 20, 20);
            $score += $durationScore;
        }

        // Conversion bonus (0-20): sessions with conversions are highly engaged
        if (count($session['conversion_events']) > 0) {
            $score += 20;
        }

        return round(min($score, 100), 2);
    }

    /**
     * Initialize a new session record.
     *
     * @return array{session_id: string, event_count: int, unique_events: array<string, bool>, first_event: string|null, last_event: string|null, first_timestamp: float|null, last_timestamp: float|null, conversion_events: array<string, bool>}
     */
    private function initializeSession(AnalyticsEvent $event, string $sessionId): array
    {
        $eventName = $event->name();
        $conversionEvents = ['purchase', 'subscribe', 'sign_up', 'start_trial', 'trial_converted'];
        $now = microtime(true);

        $session = [
            'session_id' => $sessionId,
            'event_count' => 0,
            'unique_events' => [],
            'first_event' => null,
            'last_event' => null,
            'first_timestamp' => null,
            'last_timestamp' => null,
            'conversion_events' => [],
        ];

        // Add to client session index
        $this->addSessionToIndex(
            $event->param('client_id') ?? $event->param('clientId') ?? 'anonymous',
            $sessionId
        );

        return $session;
    }

    /**
     * Add a session ID to the client's session index.
     */
    private function addSessionToIndex(string $clientId, string $sessionId): void
    {
        $indexKey = $this->cachePrefix . 'index:' . $clientId;

        /** @var list<string>|null $sessionIds */
        $sessionIds = $this->cache->get($indexKey) ?? [];

        if (! in_array($sessionId, $sessionIds, true)) {
            $sessionIds[] = $sessionId;
            // Keep only the most recent N sessions
            $sessionIds = array_slice($sessionIds, -$this->maxSessionsPerClient);
            $this->cache->put($indexKey, $sessionIds, $this->sessionTtl);
        }
    }

    /**
     * Remove a session from the client's session index.
     */
    private function removeSessionFromIndex(string $clientId, string $sessionId): void
    {
        $indexKey = $this->cachePrefix . 'index:' . $clientId;

        /** @var list<string>|null $sessionIds */
        $sessionIds = $this->cache->get($indexKey) ?? [];

        $sessionIds = array_values(array_filter(
            $sessionIds,
            static fn (string $id): bool => $id !== $sessionId,
        ));

        $this->cache->put($indexKey, $sessionIds, $this->sessionTtl);
    }

    /**
     * Prune old sessions for a client to prevent unbounded growth.
     *
     * Removes sessions from the index that no longer exist in the cache.
     */
    private function pruneClientSessions(string $clientId): void
    {
        $indexKey = $this->cachePrefix . 'index:' . $clientId;

        /** @var list<string>|null $sessionIds */
        $sessionIds = $this->cache->get($indexKey);

        if ($sessionIds === null || count($sessionIds) <= $this->maxSessionsPerClient) {
            return;
        }

        // Keep only the most recent N
        $trimmed = array_slice($sessionIds, -$this->maxSessionsPerClient);
        $this->cache->put($indexKey, $trimmed, $this->sessionTtl);

        // Clean up evicted sessions
        $evicted = array_slice($sessionIds, 0, -$this->maxSessionsPerClient);
        foreach ($evicted as $sessionId) {
            $this->cache->forget($this->cachePrefix . $clientId . ':' . $sessionId);
        }
    }

    /**
     * Derive a session ID from the client ID when not provided.
     *
     * Generates a time-based session ID that groups events within a time window.
     *
     * @return non-empty-string
     */
    private function deriveSessionId(string $clientId): string
    {
        // Round to 30-minute window for automatic session grouping
        $timeWindow = (int) (time() / $this->sessionTtl);

        return hash('xxh128', $clientId . ':' . $timeWindow);
    }
}
