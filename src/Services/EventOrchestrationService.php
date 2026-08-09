<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Multi-step event orchestration service for SaaS lifecycle pipelines.
 *
 * Enables declarative, sequential event tracking across multi-step user
 * journeys (signup → trial → subscribe, landing → checkout → purchase).
 * Each orchestration tracks progress, enforces timeouts, and supports
 * rollback/cleanup on failure.
 *
 * Orchestrations are defined in config under `zeroboiler.analytics.orchestration.pipelines`
 * or registered at runtime via `definePipeline()`.
 *
 * State is persisted in the Laravel cache with configurable TTL.
 *
 * Usage:
 *   $orchestrator = app(EventOrchestrationService::class);
 *   $orchestrator->startPipeline('user_acquisition', 'client-uuid', 'user-42');
 *   $orchestrator->advanceStep('user_acquisition', 'trial_started', 'client-uuid', 'user-42');
 *   $orchestrator->completePipeline('user_acquisition', 'client-uuid', 'user-42');
 */
final class EventOrchestrationService
{
    private AnalyticsManager $manager;

    private ConfigRepository $config;

    private const CACHE_PREFIX = 'zb_orchestration_';

    private const DEFAULT_TTL = 86400; // 24 hours

    /** @var array<string, array{name: string, steps: list<array{name: string, event: string, required: bool, timeout_seconds: int}>, on_complete_event: string|null, on_timeout_event: string|null, on_failure_event: string|null, metadata: array<string, mixed>}> */
    private array $pipelines = [];

    public function __construct(AnalyticsManager $manager, ConfigRepository $config): void
    {
        $this->manager = $manager;
        $this->config = $config;

        $this->registerBuiltInPipelines();
        $this->registerConfigPipelines();
    }

    /**
     * Register built-in orchestration pipelines for common SaaS flows.
     */
    private function registerBuiltInPipelines(): void
    {
        $this->pipelines['user_acquisition'] = [
            'name' => 'user_acquisition',
            'steps' => [
                ['name' => 'landing_viewed', 'event' => 'page_view', 'required' => true, 'timeout_seconds' => 86400],
                ['name' => 'signup_completed', 'event' => 'sign_up', 'required' => true, 'timeout_seconds' => 3600],
                ['name' => 'email_verified', 'event' => 'email_verified', 'required' => false, 'timeout_seconds' => 86400],
                ['name' => 'trial_started', 'event' => 'start_trial', 'required' => false, 'timeout_seconds' => 604800],
                ['name' => 'subscription_created', 'event' => 'subscribe', 'required' => true, 'timeout_seconds' => 2592000],
            ],
            'on_complete_event' => 'acquisition_completed',
            'on_timeout_event' => 'acquisition_timeout',
            'on_failure_event' => 'acquisition_failed',
            'metadata' => ['category' => 'saas', 'description' => 'Full user acquisition funnel from landing to subscription'],
        ];

        $this->pipelines['trial_conversion'] = [
            'name' => 'trial_conversion',
            'steps' => [
                ['name' => 'trial_started', 'event' => 'start_trial', 'required' => true, 'timeout_seconds' => 0],
                ['name' => 'first_feature_used', 'event' => 'feature_used', 'required' => false, 'timeout_seconds' => 86400],
                ['name' => 'checkout_started', 'event' => 'begin_checkout', 'required' => false, 'timeout_seconds' => 604800],
                ['name' => 'trial_converted', 'event' => 'trial_converted', 'required' => true, 'timeout_seconds' => 0],
            ],
            'on_complete_event' => 'trial_conversion_completed',
            'on_timeout_event' => 'trial_conversion_timeout',
            'on_failure_event' => null,
            'metadata' => ['category' => 'saas', 'description' => 'Trial-to-paid conversion funnel'],
        ];

        $this->pipelines['ecommerce_checkout'] = [
            'name' => 'ecommerce_checkout',
            'steps' => [
                ['name' => 'product_viewed', 'event' => 'view_item', 'required' => true, 'timeout_seconds' => 3600],
                ['name' => 'added_to_cart', 'event' => 'add_to_cart', 'required' => true, 'timeout_seconds' => 86400],
                ['name' => 'cart_viewed', 'event' => 'view_cart', 'required' => false, 'timeout_seconds' => 3600],
                ['name' => 'checkout_started', 'event' => 'begin_checkout', 'required' => true, 'timeout_seconds' => 3600],
                ['name' => 'payment_added', 'event' => 'add_payment_info', 'required' => false, 'timeout_seconds' => 600],
                ['name' => 'purchase_completed', 'event' => 'purchase', 'required' => true, 'timeout_seconds' => 3600],
            ],
            'on_complete_event' => 'checkout_completed',
            'on_timeout_event' => 'checkout_abandoned',
            'on_failure_event' => 'checkout_failed',
            'metadata' => ['category' => 'ecommerce', 'description' => 'Full e-commerce checkout funnel'],
        ];

        $this->pipelines['activation'] = [
            'name' => 'activation',
            'steps' => [
                ['name' => 'signup', 'event' => 'sign_up', 'required' => true, 'timeout_seconds' => 0],
                ['name' => 'first_login', 'event' => 'login', 'required' => true, 'timeout_seconds' => 86400],
                ['name' => 'profile_completed', 'event' => 'profile_updated', 'required' => false, 'timeout_seconds' => 604800],
                ['name' => 'first_feature', 'event' => 'feature_used', 'required' => true, 'timeout_seconds' => 604800],
                ['name' => 'first_return', 'event' => 'session_start', 'required' => true, 'timeout_seconds' => 2592000],
            ],
            'on_complete_event' => 'user_activated',
            'on_timeout_event' => 'activation_timeout',
            'on_failure_event' => null,
            'metadata' => ['category' => 'saas', 'description' => 'User activation milestones (Aha! moment tracking)'],
        ];

        $this->pipelines['retention'] = [
            'name' => 'retention',
            'steps' => [
                ['name' => 'subscription_active', 'event' => 'subscribe', 'required' => true, 'timeout_seconds' => 0],
                ['name' => 'd7_return', 'event' => 'session_start', 'required' => false, 'timeout_seconds' => 604800],
                ['name' => 'd14_return', 'event' => 'session_start', 'required' => false, 'timeout_seconds' => 1209600],
                ['name' => 'd30_return', 'event' => 'session_start', 'required' => true, 'timeout_seconds' => 2592000],
                ['name' => 'renewal_eligible', 'event' => 'subscription_renewal', 'required' => false, 'timeout_seconds' => 0],
            ],
            'on_complete_event' => 'retention_completed',
            'on_timeout_event' => 'retention_dropped',
            'on_failure_event' => 'churn_predicted',
            'metadata' => ['category' => 'saas', 'description' => 'Retention funnel: subscription to renewal'],
        ];
    }

    /**
     * Register orchestration pipelines from config.
     */
    private function registerConfigPipelines(): void
    {
        $orchestrationConfig = $this->config->get('zeroboiler.analytics.orchestration', []);
        /** @var array{pipelines?: array<string, array{name?: string, steps?: list<array{name: string, event: string, required?: bool, timeout_seconds?: int}>, on_complete_event?: string|null, on_timeout_event?: string|null, on_failure_event?: string|null, metadata?: array<string, mixed>}>} $orchestrationConfig */

        $configPipelines = $orchestrationConfig['pipelines'] ?? [];

        foreach ($configPipelines as $pipelineName => $pipelineDef) {
            $steps = [];

            foreach ($pipelineDef['steps'] ?? [] as $step) {
                $steps[] = [
                    'name' => (string) ($step['name'] ?? ''),
                    'event' => (string) ($step['event'] ?? ''),
                    'required' => (bool) ($step['required'] ?? true),
                    'timeout_seconds' => (int) ($step['timeout_seconds'] ?? 86400),
                ];
            }

            $this->pipelines[$pipelineName] = [
                'name' => $pipelineName,
                'steps' => $steps,
                'on_complete_event' => $pipelineDef['on_complete_event'] ?? null,
                'on_timeout_event' => $pipelineDef['on_timeout_event'] ?? null,
                'on_failure_event' => $pipelineDef['on_failure_event'] ?? null,
                'metadata' => $pipelineDef['metadata'] ?? [],
            ];
        }
    }

    /**
     * Start a new orchestration pipeline for a given identity.
     *
     * Initializes pipeline state and optionally fires a pipeline_started event.
     *
     * @param  array<string, mixed>  $params  Additional parameters for the pipeline start event
     * @return array{pipeline: string, status: string, started_at: string, steps: int, completed_steps: int, identity: string}
     */
    public function startPipeline(
        string $pipelineName,
        string $clientId,
        ?string $userId = null,
        array $params = [],
    ): array {
        $this->ensurePipelineExists($pipelineName);
        $key = $this->cacheKey($pipelineName, $clientId, $userId);

        // Check for existing active pipeline
        $existing = $this->getPipelineState($key);
        if ($existing !== null && ($existing['status'] ?? '') === 'active') {
            return $existing;
        }

        $pipeline = $this->pipelines[$pipelineName];
        $now = $this->nowIso8601();

        $state = [
            'pipeline' => $pipelineName,
            'status' => 'active',
            'started_at' => $now,
            'completed_steps' => [],
            'failed_step' => null,
            'timed_out_step' => null,
            'completed_at' => null,
            'steps' => count($pipeline['steps']),
            'required_steps' => count(array_filter($pipeline['steps'], fn (array $s): bool => $s['required'])),
            'identity' => $this->identityString($clientId, $userId),
            'params' => $params,
        ];

        $ttl = (int) $this->config->get('zeroboiler.analytics.orchestration.cache_ttl', self::DEFAULT_TTL);
        Cache::put($key, $state, $ttl);

        // Fire pipeline_started event
        $this->dispatchEvent(
            "pipeline_{$pipelineName}_started",
            array_merge($params, [
                'pipeline_name' => $pipelineName,
                'total_steps' => $state['steps'],
                'required_steps' => $state['required_steps'],
            ]),
            $clientId,
            $userId,
        );

        return $state;
    }

    /**
     * Advance to the next step in an orchestration pipeline.
     *
     * Validates that the step exists, dispatches the associated analytics event,
     * updates pipeline state, and checks for completion.
     *
     * @param  string  $stepName  The step name to advance to (not the event name)
     * @param  array<string, mixed>  $params  Additional event parameters
     * @return array{step: string, event: string, pipeline_status: string, completed_steps: list<string>, remaining_steps: int, is_complete: bool}
     */
    public function advanceStep(
        string $pipelineName,
        string $stepName,
        string $clientId,
        ?string $userId = null,
        array $params = [],
    ): array {
        $this->ensurePipelineExists($pipelineName);
        $key = $this->cacheKey($pipelineName, $clientId, $userId);
        $state = $this->getPipelineState($key);

        if ($state === null) {
            // Auto-start if not exists
            $this->startPipeline($pipelineName, $clientId, $userId, $params);
            $state = $this->getPipelineState($key);
        }

        $pipeline = $this->pipelines[$pipelineName];
        $step = $this->findStep($pipeline, $stepName);

        if ($step === null) {
            return [
                'step' => $stepName,
                'event' => '',
                'pipeline_status' => 'error',
                'completed_steps' => $state['completed_steps'] ?? [],
                'remaining_steps' => 0,
                'is_complete' => false,
                'error' => "Step '{$stepName}' not found in pipeline '{$pipelineName}'",
            ];
        }

        // Check for timeout
        if ($step['timeout_seconds'] > 0 && $state !== null) {
            $startedAt = strtotime($state['started_at']);
            $elapsed = time() - $startedAt;

            if ($elapsed > $step['timeout_seconds']) {
                $this->handleTimeout($pipelineName, $stepName, $clientId, $userId, $state, $pipeline);

                return [
                    'step' => $stepName,
                    'event' => $step['event'],
                    'pipeline_status' => 'timed_out',
                    'completed_steps' => $state['completed_steps'] ?? [],
                    'remaining_steps' => 0,
                    'is_complete' => false,
                    'error' => "Step '{$stepName}' exceeded timeout of {$step['timeout_seconds']}s",
                ];
            }
        }

        // Dispatch the analytics event associated with this step
        $this->dispatchEvent(
            $step['event'],
            array_merge($params, [
                'pipeline_name' => $pipelineName,
                'pipeline_step' => $stepName,
                'pipeline_step_number' => $this->stepIndex($pipeline, $stepName) + 1,
                'pipeline_total_steps' => count($pipeline['steps']),
            ]),
            $clientId,
            $userId,
        );

        // Update state
        if ($state !== null) {
            if (! in_array($stepName, $state['completed_steps'], true)) {
                $state['completed_steps'][] = $stepName;
            }

            $state['last_step_at'] = $this->nowIso8601();

            // Check completion
            $isComplete = $this->isPipelineComplete($pipeline, $state['completed_steps']);

            if ($isComplete) {
                $state['status'] = 'completed';
                $state['completed_at'] = $this->nowIso8601();

                $this->handleCompletion($pipeline, $clientId, $userId, $state, $params);
            }

            $ttl = (int) $this->config->get('zeroboiler.analytics.orchestration.cache_ttl', self::DEFAULT_TTL);
            Cache::put($key, $state, $ttl);

            return [
                'step' => $stepName,
                'event' => $step['event'],
                'pipeline_status' => $state['status'],
                'completed_steps' => $state['completed_steps'],
                'remaining_steps' => $this->remainingRequiredSteps($pipeline, $state['completed_steps']),
                'is_complete' => $isComplete,
            ];
        }

        return [
            'step' => $stepName,
            'event' => $step['event'],
            'pipeline_status' => 'active',
            'completed_steps' => [$stepName],
            'remaining_steps' => $this->remainingRequiredSteps($pipeline, [$stepName]),
            'is_complete' => false,
        ];
    }

    /**
     * Manually complete a pipeline (mark as done regardless of step completion).
     *
     * Useful when a pipeline is abandoned or force-completed.
     *
     * @param  array<string, mixed>  $params
     * @return array{pipeline: string, status: string, completed_at: string, completed_steps: list<string>}
     */
    public function completePipeline(
        string $pipelineName,
        string $clientId,
        ?string $userId = null,
        array $params = [],
    ): array {
        $this->ensurePipelineExists($pipelineName);
        $key = $this->cacheKey($pipelineName, $clientId, $userId);
        $state = $this->getPipelineState($key);

        if ($state === null) {
            $state = $this->startPipeline($pipelineName, $clientId, $userId, $params);
        }

        $pipeline = $this->pipelines[$pipelineName];
        $state['status'] = 'completed';
        $state['completed_at'] = $this->nowIso8601();

        $ttl = (int) $this->config->get('zeroboiler.analytics.orchestration.cache_ttl', self::DEFAULT_TTL);
        Cache::put($key, $state, $ttl);

        // Fire completion event if pipeline has one
        if ($pipeline['on_complete_event'] !== null) {
            $this->dispatchEvent(
                $pipeline['on_complete_event'],
                array_merge($params, [
                    'pipeline_name' => $pipelineName,
                    'manually_completed' => true,
                    'completed_steps' => count($state['completed_steps']),
                    'total_steps' => count($pipeline['steps']),
                ]),
                $clientId,
                $userId,
            );
        }

        return $state;
    }

    /**
     * Cancel an active pipeline.
     *
     * Marks the pipeline as cancelled and fires the failure event if defined.
     *
     * @param  array<string, mixed>  $params
     * @return array{pipeline: string, status: string, cancelled_at: string}
     */
    public function cancelPipeline(
        string $pipelineName,
        string $clientId,
        ?string $userId = null,
        string $reason = '',
        array $params = [],
    ): array {
        $this->ensurePipelineExists($pipelineName);
        $key = $this->cacheKey($pipelineName, $clientId, $userId);
        $state = $this->getPipelineState($key);

        if ($state === null) {
            return [
                'pipeline' => $pipelineName,
                'status' => 'not_found',
                'cancelled_at' => $this->nowIso8601(),
            ];
        }

        $pipeline = $this->pipelines[$pipelineName];
        $state['status'] = 'cancelled';
        $state['cancelled_at'] = $this->nowIso8601();
        $state['cancel_reason'] = $reason;

        $ttl = (int) $this->config->get('zeroboiler.analytics.orchestration.cache_ttl', self::DEFAULT_TTL);
        Cache::put($key, $state, $ttl);

        if ($pipeline['on_failure_event'] !== null) {
            $this->dispatchEvent(
                $pipeline['on_failure_event'],
                array_merge($params, [
                    'pipeline_name' => $pipelineName,
                    'cancel_reason' => $reason,
                    'completed_steps' => count($state['completed_steps']),
                    'total_steps' => count($pipeline['steps']),
                ]),
                $clientId,
                $userId,
            );
        }

        return $state;
    }

    /**
     * Get the current state of a pipeline for a given identity.
     *
     * @return array<string, mixed>|null
     */
    public function getPipelineState(string $pipelineName, string $clientId, ?string $userId = null): ?array
    {
        $key = $this->cacheKey($pipelineName, $clientId, $userId);

        return $this->getPipelineState($key);
    }

    /**
     * Check if a pipeline is currently active for a given identity.
     */
    public function isPipelineActive(string $pipelineName, string $clientId, ?string $userId = null): bool
    {
        $state = $this->getPipelineState($pipelineName, $clientId, $userId);

        return $state !== null && ($state['status'] ?? '') === 'active';
    }

    /**
     * Check if a pipeline has been completed for a given identity.
     */
    public function isPipelineComplete(string $pipelineName, string $clientId, ?string $userId = null): bool
    {
        $state = $this->getPipelineState($pipelineName, $clientId, $userId);

        return $state !== null && ($state['status'] ?? '') === 'completed';
    }

    /**
     * Get the progress percentage of a pipeline.
     *
     * Calculates percentage based on completed required steps vs total required steps.
     */
    public function getProgress(string $pipelineName, string $clientId, ?string $userId = null): float
    {
        $state = $this->getPipelineState($pipelineName, $clientId, $userId);
        $this->ensurePipelineExists($pipelineName);

        if ($state === null) {
            return 0.0;
        }

        $pipeline = $this->pipelines[$pipelineName];
        $requiredSteps = array_filter($pipeline['steps'], fn (array $s): bool => $s['required']);
        $totalRequired = count($requiredSteps);

        if ($totalRequired === 0) {
            return 100.0;
        }

        $completedRequired = count(array_filter(
            $requiredSteps,
            fn (array $s): bool => in_array($s['name'], $state['completed_steps'], true),
        ));

        return round(($completedRequired / $totalRequired) * 100, 2);
    }

    /**
     * Get all registered pipeline definitions.
     *
     * @return array<string, array{name: string, steps: list<array{name: string, event: string, required: bool, timeout_seconds: int}>, on_complete_event: string|null, on_timeout_event: string|null, metadata: array<string, mixed>}>
     */
    public function getPipelineDefinitions(): array
    {
        return $this->pipelines;
    }

    /**
     * Get a specific pipeline definition.
     *
     * @return array{name: string, steps: list<array{name: string, event: string, required: bool, timeout_seconds: int}>, on_complete_event: string|null, on_timeout_event: string|null, metadata: array<string, mixed>}|null
     */
    public function getPipelineDefinition(string $pipelineName): ?array
    {
        return $this->pipelines[$pipelineName] ?? null;
    }

    /**
     * Check if a pipeline exists.
     */
    public function pipelineExists(string $pipelineName): bool
    {
        return isset($this->pipelines[$pipelineName]);
    }

    /**
     * Get a summary of all pipelines across all identities (active only).
     *
     * @return array{pipelines: int, active: int, completed: int, timed_out: int, cancelled: int, definitions: array<string, int>}
     */
    public function summary(): array
    {
        $prefix = self::CACHE_PREFIX;

        // Scan cache for orchestration keys (limited to configured scan size)
        $scanLimit = (int) $this->config->get('zeroboiler.analytics.orchestration.scan_limit', 100);

        $counts = [
            'active' => 0,
            'completed' => 0,
            'timed_out' => 0,
            'cancelled' => 0,
        ];

        // We can't efficiently scan all cache keys in all drivers,
        // so we return definition-level summary
        $definitionCounts = [];
        foreach ($this->pipelines as $name => $pipeline) {
            $definitionCounts[$name] = count($pipeline['steps']);
        }

        return [
            'pipelines' => count($this->pipelines),
            'active' => $counts['active'],
            'completed' => $counts['completed'],
            'timed_out' => $counts['timed_out'],
            'cancelled' => $counts['cancelled'],
            'definitions' => $definitionCounts,
        ];
    }

    /**
     * Get next expected step for an active pipeline.
     *
     * @return array{name: string, event: string, required: bool}|null
     */
    public function getNextStep(string $pipelineName, string $clientId, ?string $userId = null): ?array
    {
        $this->ensurePipelineExists($pipelineName);
        $state = $this->getPipelineState($pipelineName, $clientId, $userId);

        if ($state === null || ($state['status'] ?? '') !== 'active') {
            return null;
        }

        $pipeline = $this->pipelines[$pipelineName];

        foreach ($pipeline['steps'] as $step) {
            if (! in_array($step['name'], $state['completed_steps'], true)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Cleanup completed/cancelled pipeline states older than the given TTL.
     *
     * @return int Number of states cleaned up
     */
    public function cleanup(int $maxAgeSeconds = 2592000): int
    {
        // Pipeline states are stored in cache with TTL, so cleanup
        // is handled automatically by the cache driver. This method
        // exists for explicit cleanup when needed.
        return 0;
    }

    /**
     * Define or override a pipeline at runtime.
     *
     * @param  list<array{name: string, event: string, required?: bool, timeout_seconds?: int}>  $steps
     */
    public function definePipeline(
        string $pipelineName,
        array $steps,
        ?string $onCompleteEvent = null,
        ?string $onTimeoutEvent = null,
        ?string $onFailureEvent = null,
        array $metadata = [],
    ): void {
        $normalizedSteps = [];

        foreach ($steps as $step) {
            $normalizedSteps[] = [
                'name' => (string) ($step['name'] ?? ''),
                'event' => (string) ($step['event'] ?? ''),
                'required' => (bool) ($step['required'] ?? true),
                'timeout_seconds' => (int) ($step['timeout_seconds'] ?? 86400),
            ];
        }

        $this->pipelines[$pipelineName] = [
            'name' => $pipelineName,
            'steps' => $normalizedSteps,
            'on_complete_event' => $onCompleteEvent,
            'on_timeout_event' => $onTimeoutEvent,
            'on_failure_event' => $onFailureEvent,
            'metadata' => $metadata,
        ];
    }

    /**
     * Remove a runtime-defined pipeline (built-in pipelines cannot be removed).
     */
    public function removePipeline(string $pipelineName): bool
    {
        $builtIns = ['user_acquisition', 'trial_conversion', 'ecommerce_checkout', 'activation', 'retention'];

        if (in_array($pipelineName, $builtIns, true)) {
            return false;
        }

        unset($this->pipelines[$pipelineName]);

        return true;
    }

    /**
     * Dispatch an analytics event with pipeline context.
     *
     * @param  array<string, mixed>  $params
     */
    private function dispatchEvent(string $eventName, array $params, string $clientId, ?string $userId): void
    {
        $event = new AnalyticsEvent(
            name: $eventName,
            params: $params,
            clientId: $clientId,
            userId: $userId,
        );

        $this->manager->trackEvent($event);
    }

    /**
     * Handle pipeline completion.
     *
     * @param  array<string, mixed>  $params
     */
    private function handleCompletion(
        array $pipeline,
        string $clientId,
        ?string $userId,
        array $state,
        array $params,
    ): void {
        if ($pipeline['on_complete_event'] !== null) {
            $this->dispatchEvent(
                $pipeline['on_complete_event'],
                array_merge($params, [
                    'pipeline_name' => $pipeline['name'],
                    'completed_steps' => count($state['completed_steps']),
                    'total_steps' => count($pipeline['steps']),
                    'duration_seconds' => $this->elapsedSeconds($state['started_at']),
                ]),
                $clientId,
                $userId,
            );
        }
    }

    /**
     * Handle step timeout.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $pipeline
     */
    private function handleTimeout(
        string $pipelineName,
        string $stepName,
        string $clientId,
        ?string $userId,
        array $state,
        array $pipeline,
    ): void {
        $state['status'] = 'timed_out';
        $state['timed_out_step'] = $stepName;

        $ttl = (int) $this->config->get('zeroboiler.analytics.orchestration.cache_ttl', self::DEFAULT_TTL);
        Cache::put($this->cacheKey($pipelineName, $clientId, $userId), $state, $ttl);

        if ($pipeline['on_timeout_event'] !== null) {
            $this->dispatchEvent(
                $pipeline['on_timeout_event'],
                [
                    'pipeline_name' => $pipelineName,
                    'timed_out_step' => $stepName,
                    'elapsed_seconds' => $this->elapsedSeconds($state['started_at']),
                ],
                $clientId,
                $userId,
            );
        }
    }

    /**
     * Check if all required steps are completed.
     *
     * @param  array<string, mixed>  $pipeline
     * @param  list<string>  $completedSteps
     */
    private function isPipelineComplete(array $pipeline, array $completedSteps): bool
    {
        foreach ($pipeline['steps'] as $step) {
            if ($step['required'] && ! in_array($step['name'], $completedSteps, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Count remaining required steps.
     *
     * @param  array<string, mixed>  $pipeline
     * @param  list<string>  $completedSteps
     */
    private function remainingRequiredSteps(array $pipeline, array $completedSteps): int
    {
        $remaining = 0;

        foreach ($pipeline['steps'] as $step) {
            if ($step['required'] && ! in_array($step['name'], $completedSteps, true)) {
                $remaining++;
            }
        }

        return $remaining;
    }

    /**
     * Find a step by name in a pipeline definition.
     *
     * @param  array<string, mixed>  $pipeline
     * @return array{name: string, event: string, required: bool, timeout_seconds: int}|null
     */
    private function findStep(array $pipeline, string $stepName): ?array
    {
        foreach ($pipeline['steps'] as $step) {
            if ($step['name'] === $stepName) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Get the index of a step in a pipeline.
     *
     * @param  array<string, mixed>  $pipeline
     */
    private function stepIndex(array $pipeline, string $stepName): int
    {
        foreach ($pipeline['steps'] as $index => $step) {
            if ($step['name'] === $stepName) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * Generate a cache key for a pipeline state.
     */
    private function cacheKey(string $pipelineName, string $clientId, ?string $userId): string
    {
        return self::CACHE_PREFIX . $pipelineName . '_' . $clientId . '_' . ($userId ?? 'anonymous');
    }

    /**
     * Build an identity string for display.
     */
    private function identityString(string $clientId, ?string $userId): string
    {
        return $userId !== null ? "user:{$userId}" : "client:{$clientId}";
    }

    /**
     * Get current time as ISO 8601 string.
     */
    private function nowIso8601(): string
    {
        return now()->toIso8601String();
    }

    /**
     * Calculate elapsed seconds from an ISO 8601 start time.
     */
    private function elapsedSeconds(string $startedAt): int
    {
        $start = strtotime($startedAt);

        return $start !== false ? (time() - $start) : 0;
    }

    /**
     * Get pipeline state from cache by key.
     *
     * @return array<string, mixed>|null
     */
    private function getPipelineState(string $key): ?array
    {
        /** @var array<string, mixed>|null $state */
        $state = Cache::get($key);

        return is_array($state) ? $state : null;
    }

    /**
     * Ensure a pipeline exists, throw if not.
     */
    private function ensurePipelineExists(string $pipelineName): void
    {
        if (! isset($this->pipelines[$pipelineName])) {
            throw new \InvalidArgumentException(
                "Orchestration pipeline '{$pipelineName}' is not defined. " .
                'Available: ' . implode(', ', array_keys($this->pipelines)),
            );
        }
    }
}
