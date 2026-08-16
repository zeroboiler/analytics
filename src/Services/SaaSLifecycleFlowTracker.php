<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks multi-step SaaS lifecycle flows with unique flow IDs and step timing.
 *
 * Monitors user journeys through sequential SaaS lifecycle events (e.g.
 * signup → trial_start → trial_converted → plan_upgrade) by assigning a
 * unique flow ID and tracking timing between each step. Enables funnel
 * analysis, time-to-conversion metrics, and drop-off detection at the flow level.
 *
 * Flow state is cached with configurable TTL for cross-request persistence.
 * When a user completes a flow, the tracker records the total duration and
 * fires a `flow_completed` event with all step timestamps and metadata.
 *
 * Supported built-in flows:
 * - signup_flow: sign_up → email_verified → trial_start → trial_converted
 * - upgrade_flow: trial_converted → plan_upgrade
 * - activation_flow: sign_up → feature_used → trial_start
 *
 * Custom flows can be registered via config or programmatically.
 *
 * @since 152.0.0
 */
final class SaaSLifecycleFlowTracker
{
    /** @var array<string, list<string>> Built-in flow definitions (flow_name => [step1, step2, ...]) */
    private const BUILT_IN_FLOWS = [
        'signup_flow' => ['sign_up', 'email_verified', 'trial_start', 'trial_converted'],
        'upgrade_flow' => ['trial_converted', 'plan_upgrade'],
        'activation_flow' => ['sign_up', 'feature_used', 'trial_start'],
        'onboarding_flow' => ['sign_up', 'email_verified', 'profile_updated', 'team_created'],
        'expansion_flow' => ['subscribe', 'plan_upgrade', 'team_member_joined', 'integration_connected'],
    ];

    /** @var array<string, list<string>> */
    private array $flows;

    private string $cachePrefix;

    private int $cacheTtl;

    /**
     * @param  AnalyticsManager  $manager  Central analytics manager
     * @param  CacheRepository  $cache  Cache store for flow state persistence
     * @param  array<string, list<string>>|null  $customFlows  Custom flow definitions (optional)
     * @param  string  $cachePrefix  Cache key prefix
     * @param  int  $cacheTtl  Flow state TTL in seconds (default: 7 days)
     */
    public function __construct(
        private readonly AnalyticsManager $manager,
        private readonly CacheRepository $cache,
        ?array $customFlows = null,
        string $cachePrefix = 'zb_flow_',
        int $cacheTtl = 604800,
    ): void {
        $this->flows = array_merge(self::BUILT_IN_FLOWS, $customFlows ?? []);
        $this->cachePrefix = $cachePrefix;
        $this->cacheTtl = $cacheTtl;
    }

    /**
     * Start tracking a new flow for a given identity.
     *
     * Creates a unique flow ID, initializes the flow state, and records
     * the first step. Returns the flow ID for subsequent step() calls.
     *
     * @param  string  $flowName  Flow name (must be a registered flow)
     * @param  string  $identity  User ID or client ID
     * @param  array<string, mixed>  $params  Optional initial event parameters
     * @return string|null  Flow ID, or null if flow name is not registered
     */
    public function start(string $flowName, string $identity, array $params = []): ?string
    {
        if (! isset($this->flows[$flowName])) {
            return null;
        }

        $flowId = Str::uuid()->toString();
        $steps = $this->flows[$flowName];

        if ($steps === []) {
            return null;
        }

        $state = [
            'flow_id' => $flowId,
            'flow_name' => $flowName,
            'identity' => $identity,
            'steps' => $steps,
            'current_step' => 0,
            'completed' => false,
            'step_timestamps' => [],
            'params' => $params,
            'started_at' => microtime(true),
        ];

        $this->cacheFlowState($flowId, $state);
        $this->registerFlowForIdentity($identity, $flowId);

        // Record the first step event
        $this->recordStep($flowId, $state, $steps[0], $params);

        return $flowId;
    }

    /**
     * Record the next step in an ongoing flow.
     *
     * Validates that the event name matches the expected next step in the flow.
     * If it does, advances the flow state and records timing. If the flow is
     * complete after this step, fires a flow_completed event.
     *
     * @param  string  $flowId  Flow ID from start()
     * @param  string  $eventName  Event name to record as the next step
     * @param  array<string, mixed>  $params  Optional event parameters for this step
     * @return bool  True if the step was recorded, false if invalid or flow not found
     */
    public function recordStepEvent(string $flowId, string $eventName, array $params = []): bool
    {
        $state = $this->getFlowState($flowId);

        if ($state === null || $state['completed']) {
            return false;
        }

        $expectedStep = $state['steps'][$state['current_step']] ?? null;

        if ($expectedStep === null || $eventName !== $expectedStep) {
            return false;
        }

        $this->recordStep($flowId, $state, $eventName, $params);

        return true;
    }

    /**
     * Attempt to advance a flow by event name (auto-detect flow).
     *
     * Searches all active flows for the given identity to find one where
     * the given event name matches the next expected step. This enables
     * "fire-and-forget" usage where the caller doesn't track the flow ID.
     *
     * @param  string  $eventName  Event name to record
     * @param  string  $identity  User ID or client ID
     * @param  array<string, mixed>  $params  Optional event parameters
     * @return string|null  Flow ID if a matching flow was advanced, null otherwise
     */
    public function advanceByEvent(string $eventName, string $identity, array $params = []): ?string
    {
        // Look up active flows for this identity
        $activeFlows = $this->getActiveFlowsForIdentity($identity);

        foreach ($activeFlows as $flowId => $state) {
            $expectedStep = $state['steps'][$state['current_step']] ?? null;

            if ($expectedStep === $eventName) {
                $success = $this->recordStepEvent($flowId, $eventName, $params);

                if ($success) {
                    return $flowId;
                }
            }
        }

        return null;
    }

    /**
     * Manually complete a flow (useful when a flow ends without all steps).
     *
     * Records a flow_completed event with 'aborted' status and the current
     * step index. Useful for tracking partial completions and drop-offs.
     *
     * @param  string  $flowId  Flow ID
     * @param  string|null  $reason  Reason for manual completion (e.g. 'user_cancelled', 'session_timeout')
     * @return bool  True if the flow was found and completed
     */
    public function complete(string $flowId, ?string $reason = null): bool
    {
        $state = $this->getFlowState($flowId);

        if ($state === null || $state['completed']) {
            return false;
        }

        $state['completed'] = true;
        $state['completed_reason'] = $reason ?? 'manual';
        $state['completed_at'] = microtime(true);
        $this->cacheFlowState($flowId, $state);

        $this->fireFlowCompletedEvent($state, $reason ?? 'manual');

        return true;
    }

    /**
     * Get the current state of a flow.
     *
     * @param  string  $flowId  Flow ID
     * @return array<string, mixed>|null  Flow state, or null if not found/expired
     */
    public function getFlowState(string $flowId): ?array
    {
        /** @var array<string, mixed>|null $state */
        $state = $this->cache->get($this->cachePrefix . $flowId);

        return is_array($state) ? $state : null;
    }

    /**
     * Get all registered flow definitions.
     *
     * @return array<string, list<string>>
     */
    public function getRegisteredFlows(): array
    {
        return $this->flows;
    }

    /**
     * Check if a flow name is registered.
     */
    public function hasFlow(string $flowName): bool
    {
        return isset($this->flows[$flowName]);
    }

    /**
     * Get the progress of a flow as a fraction (0.0 to 1.0).
     *
     * @param  string  $flowId  Flow ID
     * @return float|null  Progress fraction, or null if flow not found
     */
    public function getProgress(string $flowId): ?float
    {
        $state = $this->getFlowState($flowId);

        if ($state === null) {
            return null;
        }

        $totalSteps = count($state['steps']);

        if ($totalSteps === 0) {
            return 1.0;
        }

        return (float) $state['current_step'] / $totalSteps;
    }

    /**
     * Record a step within a flow.
     *
     * @param  string  $flowId  Flow ID
     * @param  array<string, mixed>  $state  Mutable flow state reference
     * @param  string  $eventName  Event name for this step
     * @param  array<string, mixed>  $params  Event parameters
     */
    private function recordStep(string $flowId, array &$state, string $eventName, array $params): void
    {
        $now = microtime(true);
        $stepIndex = $state['current_step'];
        $steps = $state['steps'];

        $state['step_timestamps'][$eventName] = $now;

        // Calculate step duration (time since previous step)
        $prevTimestamp = null;
        if ($stepIndex > 0) {
            $prevStepName = $steps[$stepIndex - 1];
            $prevTimestamp = $state['step_timestamps'][$prevStepName] ?? null;
        }

        $stepDuration = ($prevTimestamp !== null) ? $now - $prevTimestamp : null;

        // Attach flow context to the event
        $flowParams = array_merge($params, [
            'flow_id' => $flowId,
            'flow_name' => $state['flow_name'],
            'flow_step' => $stepIndex + 1,
            'flow_total_steps' => count($steps),
            'flow_step_duration' => $stepDuration !== null ? round($stepDuration, 3) : null,
        ]);

        $event = new AnalyticsEvent(
            name: $eventName,
            params: $flowParams,
            clientId: $state['identity'],
            source: 'flow_tracker',
            category: 'saas',
        );

        $this->manager->trackEvent($event);

        // Advance to next step
        $state['current_step'] = $stepIndex + 1;

        // Check if flow is complete
        if ($state['current_step'] >= count($steps)) {
            $state['completed'] = true;
            $state['completed_at'] = $now;
            $state['completed_reason'] = 'natural';
            $this->fireFlowCompletedEvent($state, 'natural');
        }

        $this->cacheFlowState($flowId, $state);
    }

    /**
     * Fire a flow_completed event with full flow metadata.
     *
     * @param  array<string, mixed>  $state  Completed flow state
     * @param  string  $reason  Completion reason
     */
    private function fireFlowCompletedEvent(array $state, string $reason): void
    {
        $startedAt = $state['started_at'] ?? microtime(true);
        $completedAt = $state['completed_at'] ?? microtime(true);
        $totalDuration = round($completedAt - $startedAt, 3);

        $stepDurations = [];
        $timestamps = $state['step_timestamps'] ?? [];
        $steps = $state['steps'] ?? [];

        for ($i = 0; $i < count($steps) - 1; $i++) {
            $currentStep = $steps[$i];
            $nextStep = $steps[$i + 1];

            if (isset($timestamps[$currentStep], $timestamps[$nextStep])) {
                $stepDurations["{$currentStep}_to_{$nextStep}"] = round(
                    $timestamps[$nextStep] - $timestamps[$currentStep],
                    3,
                );
            }
        }

        $completedEvent = new AnalyticsEvent(
            name: 'flow_completed',
            params: [
                'flow_id' => $state['flow_id'],
                'flow_name' => $state['flow_name'],
                'completed_reason' => $reason,
                'total_duration_seconds' => $totalDuration,
                'steps_completed' => $state['current_step'],
                'total_steps' => count($steps),
                'completion_rate' => count($steps) > 0
                    ? round((float) $state['current_step'] / count($steps), 4)
                    : 0.0,
                'step_durations' => $stepDurations,
            ],
            clientId: $state['identity'],
            source: 'flow_tracker',
            category: 'saas',
        );

        $this->manager->trackEvent($completedEvent);
    }

    /**
     * Store flow state in cache.
     *
     * @param  string  $flowId  Flow ID
     * @param  array<string, mixed>  $state  Flow state to cache
     */
    private function cacheFlowState(string $flowId, array $state): void
    {
        $this->cache->put($this->cachePrefix . $flowId, $state, $this->cacheTtl);
    }

    /**
     * Get active (non-completed) flows for a given identity.
     *
     * This is a best-effort lookup. For production use with many concurrent users,
     * consider using a dedicated cache prefix per identity.
     *
     * @param  string  $identity  User ID or client ID
     * @return array<string, array<string, mixed>>  Active flow states keyed by flow ID
     */
    private function getActiveFlowsForIdentity(string $identity): array
    {
        // We store a reverse index: identity -> [flowId, flowId, ...]
        $indexKey = $this->cachePrefix . 'identity_' . $identity;
        /** @var list<string>|null $flowIds */
        $flowIds = $this->cache->get($indexKey);

        if (! is_array($flowIds) || $flowIds === []) {
            return [];
        }

        $activeFlows = [];
        $stillActive = [];

        foreach ($flowIds as $fid) {
            $state = $this->getFlowState($fid);

            if ($state !== null && ! $state['completed']) {
                $activeFlows[$fid] = $state;
                $stillActive[] = $fid;
            }
        }

        // Update the reverse index (remove completed flows)
        $this->cache->put($indexKey, $stillActive, $this->cacheTtl);

        // Also register new flow IDs in the reverse index during start()
        return $activeFlows;
    }

    /**
     * Register a flow ID in the identity reverse index.
     *
     * Called during start() to enable advanceByEvent() lookups.
     *
     * @param  string  $identity  User ID or client ID
     * @param  string  $flowId  Flow ID to register
     */
    private function registerFlowForIdentity(string $identity, string $flowId): void
    {
        $indexKey = $this->cachePrefix . 'identity_' . $identity;
        /** @var list<string>|null $flowIds */
        $flowIds = $this->cache->get($indexKey);

        if (! is_array($flowIds)) {
            $flowIds = [];
        }

        if (! in_array($flowId, $flowIds, true)) {
            $flowIds[] = $flowId;
            $this->cache->put($indexKey, $flowIds, $this->cacheTtl);
        }
    }

    /**
     * Get a diagnostic summary of all flow tracker features.
     *
     * @return array<string, mixed>
     */
    public function diagnosticSummary(): array
    {
        return [
            'built_in_flows' => array_keys(self::BUILT_IN_FLOWS),
            'registered_flows' => array_keys($this->flows),
            'custom_flows' => array_diff(array_keys($this->flows), array_keys(self::BUILT_IN_FLOWS)),
            'cache_prefix' => $this->cachePrefix,
            'cache_ttl' => $this->cacheTtl,
            'flow_count' => count($this->flows),
            'max_steps_in_flow' => max(array_map(count(...), $this->flows)),
        ];
    }
}
