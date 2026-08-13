<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event Timeline Service — chronological event timeline for SaaS analytics.
 *
 * Provides user and client-scoped event timelines with session grouping,
 * funnel annotation, gap detection, and provider coverage overlay.
 * Inspired by Amplitude User Lookup, Mixpanel User Profile, and
 * PostHog User Activity feeds.
 *
 * @since 75.0.0
 */
final class EventTimelineService
{
    private readonly string $cachePrefix;

    private readonly int $cacheTtl;

    private readonly int $maxEntries;

    private readonly int $sessionTimeout;

    /** @var array<string, int> */
    private readonly array $gapThresholds;

    private readonly bool $enabled;

    /**
     * @param  CacheRepository  $cache  Laravel cache repository
     * @param  array{enabled: bool, cache_ttl: int, max_entries: int, session_timeout: int, gap_thresholds: array<string, int>}  $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly IdentityResolutionService $identityService,
        array $config = [],
    ): void {
        $this->enabled = $config['enabled'] ?? true;
        $this->cacheTtl = $config['cache_ttl'] ?? 3600;
        $this->maxEntries = $config['max_entries'] ?? 500;
        $this->sessionTimeout = $config['session_timeout'] ?? 1800;
        $this->gapThresholds = $config['gap_thresholds'] ?? [];
        $this->cachePrefix = 'zb_timeline_';
    }

    /**
     * Check if the timeline service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Record an event to a client's timeline.
     *
     * Appends the event to the cached timeline for the given client ID.
     * Automatically trims to max_entries when exceeded (FIFO eviction).
     *
     * @param  string  $clientId  The client ID to record the event for
     * @param  AnalyticsEvent  $event  The analytics event to record
     * @param  list<string>  $providers  Provider names that received the event (e.g., ['ga4', 'meta'])
     */
    public function record(string $clientId, AnalyticsEvent $event, array $providers = []): void
    {
        if (! $this->enabled) {
            return;
        }

        $key = $this->cachePrefix . $clientId;
        $timeline = $this->cache->get($key, []);

        $entry = [
            'event_name' => $event->name,
            'event_id' => $this->generateEventId($event, $clientId),
            'client_id' => $clientId,
            'user_id' => $event->userId,
            'params' => $event->params,
            'providers' => $providers,
            'timestamp' => $event->timestamp?->getTimestamp() ?? time(),
            'category' => $this->resolveCategory($event->name),
        ];

        $timeline[] = $entry;

        // FIFO eviction if exceeding max entries
        if (count($timeline) > $this->maxEntries) {
            $timeline = array_slice($timeline, -$this->maxEntries);
        }

        $this->cache->put($key, $timeline, $this->cacheTtl);
    }

    /**
     * Get the chronological event timeline for a client ID.
     *
     * Events are returned newest-first with metadata including category,
     * provider coverage, and timestamp.
     *
     * @param  string  $clientId  The client ID to fetch timeline for
     * @param  positive-int|0  $limit  Maximum events to return (0 = no limit)
     * @param  positive-int|0  $offset  Number of events to skip (for pagination)
     * @return list<array{event_name: string, event_id: string|null, client_id: string, user_id: string|null, params: array<string, mixed>, providers: list<string>, timestamp: int, category: string|null}>
     */
    public function getTimeline(string $clientId, int $limit = 0, int $offset = 0): array
    {
        $key = $this->cachePrefix . $clientId;
        $timeline = $this->cache->get($key, []);

        // Newest first
        $timeline = array_reverse($timeline);

        if ($offset > 0) {
            $timeline = array_slice($timeline, $offset);
        }

        if ($limit > 0) {
            $timeline = array_slice($timeline, 0, $limit);
        }

        return array_values($timeline);
    }

    /**
     * Get the event count for a client's timeline.
     */
    public function getTimelineCount(string $clientId): int
    {
        $key = $this->cachePrefix . $clientId;

        return count($this->cache->get($key, []));
    }

    /**
     * Get a user-scoped timeline by merging timelines from all linked client IDs.
     *
     * Resolves all client IDs linked to the given user via IdentityResolutionService,
     * fetches each client's timeline, merges them, and returns sorted by timestamp.
     *
     * @param  string  $userId  The user ID to fetch timeline for
     * @param  positive-int|0  $limit  Maximum events to return
     * @return list<array{event_name: string, event_id: string|null, client_id: string, user_id: string|null, params: array<string, mixed>, providers: list<string>, timestamp: int, category: string|null}>
     */
    public function getUserTimeline(string $userId, int $limit = 0): array
    {
        $clientIds = $this->identityService->getClientIdsForUser($userId);

        $merged = [];

        foreach ($clientIds as $clientId) {
            $key = $this->cachePrefix . $clientId;
            $entries = $this->cache->get($key, []);
            $merged = array_merge($merged, $entries);
        }

        // Sort by timestamp ascending (oldest first for chronological reading)
        usort($merged, fn (array $a, array $b): int => ($a['timestamp'] ?? 0) <=> ($b['timestamp'] ?? 0));

        if ($limit > 0) {
            $merged = array_slice($merged, -$limit);
        }

        return array_values($merged);
    }

    /**
     * Detect time gaps between critical lifecycle events.
     *
     * Analyzes the timeline for pairs of events where the time difference
     * exceeds configured gap thresholds. Useful for identifying churn-risk
     * users who stalled mid-funnel.
     *
     * @param  string  $clientId  The client ID to analyze
     * @return list<array{type: string, from: string, to: string|null, elapsed_seconds: int, threshold_seconds: int, timestamp: int}>
     */
    public function detectGaps(string $clientId): array
    {
        $timeline = $this->cache->get($this->cachePrefix . $clientId, []);
        $gaps = [];

        if ($timeline === [] || $this->gapThresholds === []) {
            return $gaps;
        }

        // Build event name → timestamp lookup
        $byName = [];
        foreach ($timeline as $entry) {
            $name = $entry['event_name'] ?? '';
            $ts = $entry['timestamp'] ?? 0;
            $byName[$name][] = $ts;
        }

        foreach ($this->gapThresholds as $gapType => $thresholdSeconds) {
            // Parse "trial_start_to_login" → from_event="trial_start"/"start_trial", to_event="login"
            $parts = $this->parseGapType($gapType);
            $fromEvents = $parts['from'];
            $toEvent = $parts['to'];

            $fromTimestamps = [];

            foreach ($fromEvents as $fromEvent) {
                if (isset($byName[$fromEvent])) {
                    $fromTimestamps = array_merge($fromTimestamps, $byName[$fromEvent]);
                }
            }

            foreach ($fromTimestamps as $fromTs) {
                // Check if "to" event exists after "from" event
                $toTimestamps = $byName[$toEvent] ?? [];

                $matched = false;
                foreach ($toTimestamps as $toTs) {
                    if ($toTs > $fromTs) {
                        $matched = true;
                        break;
                    }
                }

                if (! $matched) {
                    $elapsed = time() - $fromTs;
                    if ($elapsed > $thresholdSeconds) {
                        $gaps[] = [
                            'type' => $gapType,
                            'from' => $fromEvents[0],
                            'to' => $toEvent,
                            'elapsed_seconds' => $elapsed,
                            'threshold_seconds' => $thresholdSeconds,
                            'timestamp' => $fromTs,
                        ];
                    }
                }
            }
        }

        // Sort by severity (largest gap first)
        usort($gaps, fn (array $a, array $b): int => $b['elapsed_seconds'] <=> $a['elapsed_seconds']);

        return $gaps;
    }

    /**
     * Group timeline events into sessions.
     *
     * Events are grouped into sessions based on the configured session timeout.
     * A new session starts when the time between consecutive events exceeds
     * the session timeout. Sessions are returned chronologically.
     *
     * @param  string  $clientId  The client ID to group events for
     * @return list<array{session_id: string, start_time: int, end_time: int, event_count: int, events: list<array{event_name: string, timestamp: int}>}>
     */
    public function getSessionGroups(string $clientId): array
    {
        $timeline = $this->cache->get($this->cachePrefix . $clientId, []);

        if ($timeline === []) {
            return [];
        }

        $sessions = [];
        $currentSession = null;

        foreach ($timeline as $entry) {
            $ts = $entry['timestamp'] ?? 0;

            if ($currentSession === null) {
                $currentSession = [
                    'session_id' => $this->generateSessionId($ts, $clientId),
                    'start_time' => $ts,
                    'end_time' => $ts,
                    'event_count' => 0,
                    'events' => [],
                ];
            } elseif ($ts - $currentSession['end_time'] > $this->sessionTimeout) {
                $sessions[] = $currentSession;
                $currentSession = [
                    'session_id' => $this->generateSessionId($ts, $clientId),
                    'start_time' => $ts,
                    'end_time' => $ts,
                    'event_count' => 0,
                    'events' => [],
                ];
            }

            $currentSession['end_time'] = $ts;
            $currentSession['event_count']++;
            $currentSession['events'][] = [
                'event_name' => $entry['event_name'] ?? '',
                'timestamp' => $ts,
            ];
        }

        if ($currentSession !== null) {
            $sessions[] = $currentSession;
        }

        return $sessions;
    }

    /**
     * Clear the timeline for a specific client ID.
     *
     * GDPR-compliant: call this from `forget()` or data erasure flows.
     */
    public function clearTimeline(string $clientId): void
    {
        $this->cache->forget($this->cachePrefix . $clientId);
    }

    /**
     * Clear all timelines for a user (all linked client IDs).
     */
    public function clearUserTimelines(string $userId): void
    {
        $clientIds = $this->identityService->getClientIdsForUser($userId);

        foreach ($clientIds as $clientId) {
            $this->cache->forget($this->cachePrefix . $clientId);
        }
    }

    /**
     * Get service statistics.
     *
     * @return array{enabled: bool, cache_ttl: int, max_entries: int, session_timeout: int, gap_thresholds_count: int}
     */
    public function stats(): array
    {
        return [
            'enabled' => $this->enabled,
            'cache_ttl' => $this->cacheTtl,
            'max_entries' => $this->maxEntries,
            'session_timeout' => $this->sessionTimeout,
            'gap_thresholds_count' => count($this->gapThresholds),
        ];
    }

    /**
     * Resolve the event category from the event name.
     *
     * @return string|null Category name or null if not found in catalog
     */
    private function resolveCategory(string $eventName): ?string
    {
        return \ZeroBoiler\Analytics\Events\EventCatalog::getCategory($eventName);
    }

    /**
     * Parse a gap type string into from/to event name candidates.
     *
     * E.g., "trial_start_to_login" → from=["trial_start", "start_trial"], to="login"
     *
     * @return array{from: list<string>, to: string}
     */
    private function parseGapType(string $gapType): array
    {
        $parts = explode('_to_', $gapType, 2);

        if (count($parts) !== 2) {
            return ['from' => [$gapType], 'to' => ''];
        }

        $fromRaw = strtolower($parts[0]);
        $to = strtolower($parts[1]);

        // Map common aliases
        $fromAliases = [
            'trial_start' => ['trial_start', 'start_trial'],
            'signup' => ['sign_up', 'signup'],
            'trial_end' => ['trial_end'],
            'purchase' => ['purchase'],
            'login' => ['login'],
            'subscribe' => ['subscribe'],
            'plan_upgrade' => ['plan_upgrade'],
            'cancellation' => ['cancellation'],
        ];

        $fromEvents = $fromAliases[$fromRaw] ?? [$fromRaw];

        return ['from' => $fromEvents, 'to' => $to];
    }

    /**
     * Generate a deterministic event ID from event data.
     */
    private function generateEventId(AnalyticsEvent $event, string $clientId): string
    {
        $ts = $event->timestamp?->getTimestamp() ?? time();

        return 'evt_' . substr(md5($event->name . '_' . $clientId . '_' . $ts), 0, 16);
    }

    /**
     * Generate a deterministic session ID from timestamp and client ID.
     */
    private function generateSessionId(int $timestamp, string $clientId): string
    {
        return 'sess_' . substr(md5($clientId . '_' . $timestamp), 0, 12);
    }
}
