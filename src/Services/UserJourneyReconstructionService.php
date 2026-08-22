<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * User journey reconstruction service for SaaS analytics dashboards.
 *
 * Reconstructs complete user journeys from event correlation data,
 * providing funnel completion analysis, time-to-convert metrics,
 * drop-off detection, and journey comparison across user segments.
 *
 * Builds on EventCorrelationService's in-memory journey data with
 * cache-backed persistence for cross-request journey reconstruction.
 *
 * @see \ZeroBoiler\Analytics\Services\EventCorrelationService
 *
 * @since 8.5.0
 */
final class UserJourneyReconstructionService
{
    /** @var CacheRepository */
    private CacheRepository $cache;

    private AnalyticsMetrics $metrics;

    private string $cachePrefix;

    private int $cacheTtl;

    private int $maxJourneysPerUser;

    private int $maxStepsPerJourney;

    /**
     * @param  CacheRepository  $cache
     * @param  AnalyticsMetrics  $metrics
     * @param  ConfigRepository  $config
     */
    public function __construct(
        CacheRepository $cache,
        AnalyticsMetrics $metrics,
        ConfigRepository $config,
    ){
        $this->cache = $cache;
        $this->metrics = $metrics;

        $journeyConfig = $config->get('zeroboiler.analytics.journey_reconstruction', []);
        /** @var array{cache_prefix?: string, cache_ttl?: int, max_journeys_per_user?: int, max_steps_per_journey?: int} $journeyConfig */

        $this->cachePrefix = (string) ($journeyConfig['cache_prefix'] ?? 'zb_journey_');
        $this->cacheTtl = (int) ($journeyConfig['cache_ttl'] ?? 86400); // 24 hours
        $this->maxJourneysPerUser = (int) ($journeyConfig['max_journeys_per_user'] ?? 20);
        $this->maxStepsPerJourney = (int) ($journeyConfig['max_steps_per_journey'] ?? 200);
    }

    /**
     * Record an event step in a user's journey.
     *
     * Appends the event to the current active journey for the given identity.
     * A journey is considered active until it expires (idle timeout) or
     * a terminal event (logout, session_end) is reached.
     *
     * @param  AnalyticsEvent  $event
     * @param  string|null  $userId
     * @param  string|null  $clientId
     * @param  string|null  $sessionId
     */
    public function recordStep(
        AnalyticsEvent $event,
        ?string $userId = null,
        ?string $clientId = null,
        ?string $sessionId = null,
    ): void {
        $identity = $userId ?? $clientId ?? $sessionId;

        if ($identity === null) {
            return;
        }

        $journeyKey = $this->cachePrefix . 'active_' . $identity;

        try {
            $journey = $this->cache->get($journeyKey);

            if (! is_array($journey)) {
                $journey = $this->startNewJourney($event, $userId, $clientId, $sessionId);
            } else {
                $journey = $this->appendStep($journey, $event);
            }

            $this->cache->put($journeyKey, $journey, $this->cacheTtl);
        } catch (\Throwable $e) {
            // Cache unavailable
        }
    }

    /**
     * Finalize the active journey for an identity.
     *
     * Moves the current active journey to the completed journeys list
     * and starts a new active journey. Call this on logout, session end,
     * or when a terminal event is detected.
     *
     * @param  string|null  $userId
     * @param  string|null  $clientId
     * @return array{completed: bool, journey: array<string, mixed>}
     */
    public function finalizeJourney(?string $userId = null, ?string $clientId = null): array
    {
        $identity = $userId ?? $clientId;

        if ($identity === null) {
            return ['completed' => false, 'journey' => []];
        }

        $journeyKey = $this->cachePrefix . 'active_' . $identity;

        try {
            $journey = $this->cache->get($journeyKey);

            if (! is_array($journey)) {
                return ['completed' => false, 'journey' => []];
            }

            $journey['ended_at'] = now()->toIso8601String();
            $journey['status'] = 'completed';
            $journey['duration_seconds'] = $this->calculateDuration($journey);

            $this->storeCompletedJourney($identity, $journey);

            $this->cache->forget($journeyKey);

            return ['completed' => true, 'journey' => $journey];
        } catch (\Throwable $e) {
            return ['completed' => false, 'journey' => []];
        }
    }

    /**
     * Get the active journey for an identity.
     *
     * @param  string|null  $userId
     * @param  string|null  $clientId
     * @return array<string, mixed>
     */
    public function getActiveJourney(?string $userId = null, ?string $clientId = null): array
    {
        $identity = $userId ?? $clientId;

        if ($identity === null) {
            return [];
        }

        try {
            $journey = $this->cache->get($this->cachePrefix . 'active_' . $identity);

            return is_array($journey) ? $journey : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get all completed journeys for an identity.
     *
     * @param  string|null  $userId
     * @param  string|null  $clientId
     * @param  int  $limit
     * @return list<array<string, mixed>>
     */
    public function getCompletedJourneys(?string $userId = null, ?string $clientId = null, int $limit = 10): array
    {
        $identity = $userId ?? $clientId;

        if ($identity === null) {
            return [];
        }

        try {
            $journeys = $this->cache->get($this->cachePrefix . 'completed_' . $identity);

            if (! is_array($journeys)) {
                return [];
            }

            return array_slice(array_values($journeys), -$limit);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Analyze funnel completion for a user's journey.
     *
     * Given a target funnel definition (ordered list of event names),
     * checks how far the user progressed through the funnel.
     *
     * @param  list<string>  $funnelSteps  Ordered funnel step event names
     * @param  string|null  $userId
     * @param  string|null  $clientId
     * @return array{funnel: list<string>, completed_steps: int, total_steps: int, completion_rate: float, current_step: string|null, time_to_current: int|null, next_expected: string|null}
     */
    public function analyzeFunnelProgress(
        array $funnelSteps,
        ?string $userId = null,
        ?string $clientId = null,
    ): array {
        if ($funnelSteps === []) {
            return [
                'funnel' => [],
                'completed_steps' => 0,
                'total_steps' => 0,
                'completion_rate' => 0.0,
                'current_step' => null,
                'time_to_current' => null,
                'next_expected' => null,
            ];
        }

        $journey = $this->getActiveJourney($userId, $clientId);

        // Also check completed journeys for the most recent one
        if ($journey === []) {
            $completed = $this->getCompletedJourneys($userId, $clientId, 1);
            $journey = $completed[0] ?? [];
        }

        $steps = $journey['steps'] ?? [];
        $completedSteps = 0;
        $currentStep = null;
        $timeToCurrent = null;

        foreach ($funnelSteps as $i => $stepName) {
            $found = false;

            foreach ($steps as $step) {
                if (($step['event'] ?? '') === $stepName) {
                    $completedSteps = $i + 1;
                    $currentStep = $stepName;
                    $timeToCurrent = isset($step['timestamp'])
                        ? $this->secondsSince($step['timestamp'])
                        : null;
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                break;
            }
        }

        return [
            'funnel' => $funnelSteps,
            'completed_steps' => $completedSteps,
            'total_steps' => count($funnelSteps),
            'completion_rate' => count($funnelSteps) > 0
                ? round(($completedSteps / count($funnelSteps)) * 100, 2)
                : 0.0,
            'current_step' => $currentStep,
            'time_to_current' => $timeToCurrent,
            'next_expected' => ($completedSteps < count($funnelSteps))
                ? $funnelSteps[$completedSteps] ?? null
                : null,
        ];
    }

    /**
     * Get journey statistics across all users.
     *
     * @return array{total_active_journeys: int, cache_prefix: string, ttl: int}
     */
    public function stats(): array
    {
        return [
            'total_active_journeys' => 0, // Cannot enumerate without scanning
            'cache_prefix' => $this->cachePrefix,
            'ttl' => $this->cacheTtl,
        ];
    }

    /**
     * Erase all journey data for a user (GDPR).
     *
     * @param  string  $userId
     * @return int Number of journeys erased
     */
    public function eraseUser(string $userId): int
    {
        $count = 0;

        try {
            $activeKey = $this->cachePrefix . 'active_' . $userId;
            $active = $this->cache->get($activeKey);

            if (is_array($active)) {
                $this->cache->forget($activeKey);
                $count++;
            }

            $completedKey = $this->cachePrefix . 'completed_' . $userId;
            $completed = $this->cache->get($completedKey);

            if (is_array($completed)) {
                $count += count($completed);
                $this->cache->forget($completedKey);
            }
        } catch (\Throwable $e) {
            // Cache unavailable
        }

        return $count;
    }

    /**
     * Start a new journey data structure.
     *
     * @param  AnalyticsEvent  $event
     * @param  string|null  $userId
     * @param  string|null  $clientId
     * @param  string|null  $sessionId
     * @return array<string, mixed>
     */
    private function startNewJourney(
        AnalyticsEvent $event,
        ?string $userId,
        ?string $clientId,
        ?string $sessionId,
    ): array {
        return [
            'id' => $this->generateJourneyId(),
            'user_id' => $userId,
            'client_id' => $clientId,
            'session_id' => $sessionId,
            'status' => 'active',
            'started_at' => now()->toIso8601String(),
            'ended_at' => null,
            'duration_seconds' => null,
            'steps' => [
                [
                    'event' => $event->name,
                    'params' => $this->sanitizeParams($event->params),
                    'timestamp' => now()->toIso8601String(),
                    'category' => $event->category ?? null,
                ],
            ],
            'event_count' => 1,
        ];
    }

    /**
     * Append an event step to an existing journey.
     *
     * @param  array<string, mixed>  $journey
     * @param  AnalyticsEvent  $event
     * @return array<string, mixed>
     */
    private function appendStep(array $journey, AnalyticsEvent $event): array
    {
        $journey['steps'][] = [
            'event' => $event->name,
            'params' => $this->sanitizeParams($event->params),
            'timestamp' => now()->toIso8601String(),
            'category' => $event->category ?? null,
        ];

        $journey['event_count'] = ($journey['event_count'] ?? 0) + 1;

        // Enforce max steps
        if (count($journey['steps']) > $this->maxStepsPerJourney) {
            $journey['steps'] = array_slice($journey['steps'], -$this->maxStepsPerJourney);
        }

        return $journey;
    }

    /**
     * Store a completed journey in the user's completed journey list.
     *
     * @param  string  $identity
     * @param  array<string, mixed>  $journey
     */
    private function storeCompletedJourney(string $identity, array $journey): void
    {
        $completedKey = $this->cachePrefix . 'completed_' . $identity;

        $completed = $this->cache->get($completedKey);

        if (! is_array($completed)) {
            $completed = [];
        }

        // Enforce max journeys per user
        if (count($completed) >= $this->maxJourneysPerUser) {
            $completed = array_slice($completed, -(int) floor($this->maxJourneysPerUser * 0.8));
        }

        $completed[$journey['id']] = $journey;

        $this->cache->put($completedKey, $completed, $this->cacheTtl);
    }

    /**
     * Calculate journey duration in seconds from start/end timestamps.
     *
     * @param  array<string, mixed>  $journey
     * @return int
     */
    private function calculateDuration(array $journey): int
    {
        $start = $journey['started_at'] ?? null;
        $end = $journey['ended_at'] ?? null;

        if ($start === null || $end === null) {
            return 0;
        }

        try {
            return max(0, now()->parse($end)->diffInSeconds(now()->parse($start)));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Calculate seconds since a given ISO timestamp.
     *
     * @param  string  $timestamp
     * @return int
     */
    private function secondsSince(string $timestamp): int
    {
        try {
            return max(0, now()->diffInSeconds(now()->parse($timestamp)));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Sanitize event params for storage (remove sensitive data).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function sanitizeParams(array $params): array
    {
        $sensitive = ['password', 'token', 'secret', 'api_key', 'credit_card', 'ssn'];

        return array_filter(
            $params,
            fn (string $key): bool => ! in_array(strtolower($key), $sensitive, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Generate a unique journey ID.
     *
     * @return string
     */
    private function generateJourneyId(): string
    {
        return 'j_' . substr(md5((string) (hrtime(true) % 1_000_000_000_000)), 0, 12);
    }
}
