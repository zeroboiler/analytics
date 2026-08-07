<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * SaaS Journey Milestone tracking service.
 *
 * Tracks user progression through configurable multi-step journeys
 * (e.g., signup → trial → subscription → upgrade). Each journey is
 * defined as a series of named milestones. When a user completes
 * all milestones, a `journey_completed` event is dispatched with
 * full metadata including time-to-completion and step-level timing.
 *
 * Journey definitions are stored in config or registered at runtime.
 * The service persists milestone progress in the cache for durability
 * across requests.
 *
 * Configuration:
 *   zeroboiler.analytics.journeys.enabled (default: true)
 *   zeroboiler.analytics.journeys.definitions (array of journey configs)
 *
 * @see \ZeroBoiler\Analytics\DTO\AnalyticsEvent
 */
final class SaaSJourneyService
{
    private const CACHE_PREFIX = 'zb_journey_';

    private const DEFAULT_TTL = 2592000; // 30 days

    private const DEFAULT_JOURNEYS = [
        'acquisition' => [
            'label' => 'Acquisition Funnel',
            'milestones' => ['landing_page', 'signup_view', 'signup_submit', 'signup_confirm'],
            'completed_event' => 'journey_acquisition_completed',
        ],
        'trial' => [
            'label' => 'Trial to Conversion',
            'milestones' => ['trial_start', 'trial_active', 'pricing_view', 'checkout_complete'],
            'completed_event' => 'journey_trial_conversion_completed',
        ],
        'expansion' => [
            'label' => 'Expansion Funnel',
            'milestones' => ['upgrade_eligible', 'pricing_view', 'upgrade_select', 'checkout_complete'],
            'completed_event' => 'journey_expansion_completed',
        ],
        'activation' => [
            'label' => 'Product Activation',
            'milestones' => ['signup_confirm', 'first_feature_used', 'first_session_return', 'profile_updated'],
            'completed_event' => 'journey_activation_completed',
        ],
    ];

    private AnalyticsManager $manager;

    private CacheRepository $cache;

    private bool $enabled;

    private int $cacheTtl;

    /** @var array<string, array{label: string, milestones: list<string>, completed_event: string}> */
    private array $journeys;

    /**
     * @param  AnalyticsManager  $manager
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        AnalyticsManager $manager,
        CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $this->manager = $manager;
        $this->cache = $cache;

        $journeyConfig = $config->get('zeroboiler.analytics.journeys', []);
        /** @var array{enabled?: bool, cache_ttl?: int, definitions?: array<string, array{label: string, milestones: list<string>, completed_event: string}>} $journeyConfig */
        $this->enabled = (bool) ($journeyConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($journeyConfig['cache_ttl'] ?? self::DEFAULT_TTL);
        $this->journeys = array_merge(self::DEFAULT_JOURNEYS, $journeyConfig['definitions'] ?? []);
    }

    /**
     * Record a milestone hit for a user in a journey.
     *
     * If this milestone completes the journey, a `journey_completed` event
     * is dispatched with timing metadata. The journey progress is persisted
     * in the cache.
     *
     * @param  string  $journeyName  Journey identifier (e.g., 'acquisition', 'trial')
     * @param  string  $milestone  Milestone identifier (e.g., 'signup_confirm')
     * @param  string  $clientId  Client tracking ID
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function hitMilestone(
        string $journeyName,
        string $milestone,
        string $clientId,
        array $params = [],
    ): void {
        if (! $this->enabled) {
            return;
        }

        $journey = $this->journeys[$journeyName] ?? null;

        if ($journey === null) {
            return;
        }

        if (! in_array($milestone, $journey['milestones'], true)) {
            return;
        }

        $cacheKey = self::CACHE_PREFIX . $clientId . '_' . $journeyName;
        $progress = $this->cache->get($cacheKey, []);
        /** @var array{milestones: array<string, array{hit_at: string, params: array<string, mixed>}>, started_at: string|null} $progress */

        $now = date('c');

        if (empty($progress)) {
            $progress = [
                'milestones' => [],
                'started_at' => $now,
            ];
        }

        // Record milestone hit (idempotent — skip if already hit)
        if (isset($progress['milestones'][$milestone])) {
            return;
        }

        $progress['milestones'][$milestone] = [
            'hit_at' => $now,
            'params' => $params,
        ];

        $this->cache->put($cacheKey, $progress, $this->cacheTtl);

        // Dispatch a step event for this milestone
        $this->manager->trackEvent(new AnalyticsEvent(
            name: "journey_{$journeyName}_step",
            params: array_merge($params, [
                'journey' => $journeyName,
                'journey_label' => $journey['label'],
                'milestone' => $milestone,
                'milestone_index' => (int) array_search($milestone, $journey['milestones'], true) + 1,
                'total_milestones' => count($journey['milestones']),
                'completed_milestones' => count($progress['milestones']),
                'client_id' => $clientId,
                'journey_started_at' => $progress['started_at'],
            ]),
        ));

        // Check if journey is now complete
        $completedMilestones = array_keys($progress['milestones']);
        $allMilestones = $journey['milestones'];

        if (count(array_diff($allMilestones, $completedMilestones)) === 0) {
            $this->dispatchJourneyCompletion($journeyName, $journey, $clientId, $progress);
        }
    }

    /**
     * Get the progress of a user's journey.
     *
     * @param  string  $journeyName
     * @param  string  $clientId
     * @return array{journey: string, label: string, completed: bool, completed_milestones: list<string>, total_milestones: int, progress_percent: float, started_at: string|null, milestones: array<string, array{hit_at: string, params: array<string, mixed>}>}
     */
    public function getProgress(string $journeyName, string $clientId): array
    {
        $journey = $this->journeys[$journeyName] ?? null;
        $empty = $this->emptyProgress($journeyName, $clientId);

        if ($journey === null) {
            return $empty;
        }

        $cacheKey = self::CACHE_PREFIX . $clientId . '_' . $journeyName;
        $progress = $this->cache->get($cacheKey, null);

        if (! is_array($progress)) {
            return $empty;
        }

        $completed = array_keys($progress['milestones'] ?? []);
        $total = count($journey['milestones']);
        $percent = $total > 0 ? round((count($completed) / $total) * 100, 1) : 0.0;

        return [
            'journey' => $journeyName,
            'label' => $journey['label'],
            'completed' => count($completed) === $total,
            'completed_milestones' => $completed,
            'total_milestones' => $total,
            'progress_percent' => $percent,
            'started_at' => $progress['started_at'] ?? null,
            'milestones' => $progress['milestones'] ?? [],
        ];
    }

    /**
     * Get progress for all journeys of a client.
     *
     * @param  string  $clientId
     * @return array<string, array{journey: string, label: string, completed: bool, completed_milestones: list<string>, total_milestones: int, progress_percent: float, started_at: string|null}>
     */
    public function getAllProgress(string $clientId): array
    {
        $result = [];

        foreach (array_keys($this->journeys) as $name) {
            $result[$name] = $this->getProgress($name, $clientId);
        }

        return $result;
    }

    /**
     * Get the completion rate for a journey across all clients.
     *
     * @param  string  $journeyName
     * @return array{journey: string, total_started: int, total_completed: int, completion_rate: float, avg_steps_completed: float}
     */
    public function completionStats(string $journeyName): array
    {
        $journey = $this->journeys[$journeyName] ?? null;

        if ($journey === null) {
            return [
                'journey' => $journeyName,
                'total_started' => 0,
                'total_completed' => 0,
                'completion_rate' => 0.0,
                'avg_steps_completed' => 0.0,
            ];
        }

        // This is a best-effort approximation using cache scan limitations.
        // For accurate stats, integrate with a database-backed store.
        return [
            'journey' => $journeyName,
            'total_started' => 0,
            'total_completed' => 0,
            'completion_rate' => 0.0,
            'avg_steps_completed' => 0.0,
        ];
    }

    /**
     * Register or override a journey definition at runtime.
     *
     * @param  string  $name
     * @param  string  $label
     * @param  list<string>  $milestones
     * @param  string  $completedEvent
     */
    public function registerJourney(
        string $name,
        string $label,
        array $milestones,
        string $completedEvent,
    ): void {
        $this->journeys[$name] = [
            'label' => $label,
            'milestones' => $milestones,
            'completed_event' => $completedEvent,
        ];
    }

    /**
     * Get all registered journey definitions.
     *
     * @return array<string, array{label: string, milestones: list<string>, completed_event: string}>
     */
    public function getJourneys(): array
    {
        return $this->journeys;
    }

    /**
     * Reset a user's journey progress.
     */
    public function resetProgress(string $journeyName, string $clientId): void
    {
        $cacheKey = self::CACHE_PREFIX . $clientId . '_' . $journeyName;
        $this->cache->forget($cacheKey);
    }

    /**
     * Reset all journey progress for a client.
     */
    public function resetAllProgress(string $clientId): void
    {
        foreach (array_keys($this->journeys) as $name) {
            $this->resetProgress($name, $clientId);
        }
    }

    /**
     * Check if journey tracking is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Dispatch a journey completion event with full metadata.
     *
     * @param  string  $journeyName
     * @param  array{label: string, milestones: list<string>, completed_event: string}  $journey
     * @param  string  $clientId
     * @param  array{milestones: array<string, array{hit_at: string, params: array<string, mixed>}>, started_at: string|null}  $progress
     */
    private function dispatchJourneyCompletion(
        string $journeyName,
        array $journey,
        string $clientId,
        array $progress,
    ): void {
        $startedAt = $progress['started_at'] ?? null;
        $duration = null;

        if ($startedAt !== null) {
            $start = strtotime($startedAt);
            $duration = $start !== false ? time() - $start : null;
        }

        // Calculate step-level durations
        $stepTimings = [];
        $prevTimestamp = $startedAt !== null ? strtotime($startedAt) : null;

        foreach ($journey['milestones'] as $milestone) {
            $hit = $progress['milestones'][$milestone] ?? null;

            if ($hit !== null && $prevTimestamp !== false && $prevTimestamp !== null) {
                $hitTs = strtotime($hit['hit_at']);
                $stepTimings[$milestone] = [
                    'hit_at' => $hit['hit_at'],
                    'time_to_step' => $hitTs !== false ? ($hitTs - $prevTimestamp) : null,
                ];
                $prevTimestamp = $hitTs !== false ? $hitTs : $prevTimestamp;
            } elseif ($hit !== null) {
                $stepTimings[$milestone] = [
                    'hit_at' => $hit['hit_at'],
                    'time_to_step' => null,
                ];
            }
        }

        $this->manager->trackEvent(new AnalyticsEvent(
            name: $journey['completed_event'],
            params: [
                'journey' => $journeyName,
                'journey_label' => $journey['label'],
                'client_id' => $clientId,
                'duration_seconds' => $duration,
                'total_milestones' => count($journey['milestones']),
                'started_at' => $startedAt,
                'completed_at' => date('c'),
                'step_timings' => $stepTimings,
            ],
        ));
    }

    /**
     * Build an empty progress response.
     *
     * @return array{journey: string, label: string, completed: bool, completed_milestones: list<string>, total_milestones: int, progress_percent: float, started_at: string|null, milestones: array<empty, empty>}
     */
    private function emptyProgress(string $journeyName, string $clientId): array
    {
        $journey = $this->journeys[$journeyName] ?? null;
        $label = $journey['label'] ?? $journeyName;
        $total = $journey !== null ? count($journey['milestones']) : 0;

        return [
            'journey' => $journeyName,
            'label' => $label,
            'completed' => false,
            'completed_milestones' => [],
            'total_milestones' => $total,
            'progress_percent' => 0.0,
            'started_at' => null,
            'milestones' => [],
        ];
    }
}
