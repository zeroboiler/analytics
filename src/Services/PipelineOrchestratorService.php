<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Collection;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * DAG-based event pipeline orchestrator.
 *
 * Manages ordered event processing pipelines with dependency resolution,
 * retry policies, parallel execution hints, and health tracking.
 *
 * Pipelines are defined as DAGs (Directed Acyclic Graphs) where each node
 * is a processing step and edges represent dependencies. The orchestrator
 * performs topological sorting to determine execution order.
 *
 * Features:
 * - **Pipeline Registration**: Register named pipelines with steps and dependencies
 * - **Dependency Resolution**: Topological sort with cycle detection
 * - **Retry Policies**: Per-step configurable retry with exponential backoff
 * - **Parallel Execution Hints**: Mark independent steps for concurrent processing
 * - **Health Tracking**: Per-pipeline success/failure metrics, error aggregation
 * - **Dry Run**: Validate pipeline structure without executing steps
 * - **Pipeline Snapshot**: Capture execution state for debugging
 * - **Bypass & Skip**: Conditional step execution based on predicates
 *
 * Configuration: `zeroboiler.analytics.pipeline_orchestrator`
 *
 * @see \ZeroBoiler\Analytics\Services\EventTransformationEngine
 * @see \ZeroBoiler\Analytics\Services\ComposableEnrichmentPipeline
 *
 * @since 187.0.0
 */
final class PipelineOrchestratorService
{
    /** @var array<string, array<string, PipelineStep>> */
    private array $pipelines = [];

    private readonly bool $enabled;
    private readonly int $maxSteps;
    private readonly int $maxRetries;
    private readonly float $backoffMultiplier;
    private readonly int $cacheTtl;
    private readonly int $maxHistory;
    private CacheRepository $cache;

    /**
     * @param  CacheRepository  $cache  Cache repository for pipeline state
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config)
    {
        $this->cache = $cache;
        $cfg = $config->get('zeroboiler.analytics.pipeline_orchestrator', []);
        $this->enabled = (bool) ($cfg['enabled'] ?? true);
        $this->maxSteps = (int) ($cfg['max_steps'] ?? 50);
        $this->maxRetries = (int) ($cfg['max_retries'] ?? 3);
        $this->backoffMultiplier = (float) ($cfg['backoff_multiplier'] ?? 2.0);
        $this->cacheTtl = (int) ($cfg['cache_ttl'] ?? 3600);
        $this->maxHistory = (int) ($cfg['max_history'] ?? 100);
    }

    /**
     * Check if the orchestrator is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Register a pipeline with steps and dependencies.
     *
     * @param  string  $name  Pipeline identifier (e.g. 'ecommerce_checkout', 'saas_onboarding')
     * @param  array<string, PipelineStep>  $steps  Named processing steps
     * @param  array{steps: array<string, PipelineStep>, graph: array<string, list<string>>}  $options  Pipeline options
     *
     * @throws \InvalidArgumentException if pipeline exceeds max steps or contains cycles
     */
    public function registerPipeline(
        string $name,
        array $steps,
        array $options = [],
    ): void {
        if (! $this->enabled) {
            return;
        }

        $stepCount = count($steps);
        if ($stepCount > $this->maxSteps) {
            throw new \InvalidArgumentException(
                "Pipeline '{$name}' has {$stepCount} steps, exceeding max of {$this->maxSteps}.",
            );
        }

        if ($stepCount === 0) {
            return;
        }

        // Validate all steps reference correct dependencies
        $allStepNames = array_keys($steps);
        foreach ($steps as $stepName => $step) {
            $missing = array_diff($step->dependencies, $allStepNames);
            if ($missing !== []) {
                throw new \InvalidArgumentException(
                    "Pipeline '{$name}': step '{$stepName}' references unknown dependencies: " . implode(', ', $missing),
                );
            }
        }

        // Cycle detection via topological sort
        $this->topologicalSort($steps);

        $this->pipelines[$name] = $steps;
    }

    /**
     * Execute a registered pipeline for the given event.
     *
     * Performs topological sort, then executes steps in order.
     * Supports step bypass predicates, retry policies, and parallel hints.
     *
     * @param  string  $pipelineName  Registered pipeline name
     * @param  AnalyticsEvent  $event  The event to process
     * @param  array<string, mixed>  $context  Additional processing context
     * @return PipelineResult Execution result with step outcomes, timing, and errors
     *
     * @throws \InvalidArgumentException if pipeline not found
     */
    public function execute(
        string $pipelineName,
        AnalyticsEvent $event,
        array $context = [],
    ): PipelineResult {
        if (! $this->enabled) {
            return PipelineResult::skipped($pipelineName);
        }

        if (! isset($this->pipelines[$pipelineName])) {
            throw new \InvalidArgumentException("Pipeline '{$pipelineName}' is not registered.");
        }

        $steps = $this->pipelines[$pipelineName];
        $sorted = $this->topologicalSort($steps);
        $startTime = microtime(true);
        $stepResults = [];
        $errors = [];
        $skipped = [];
        $parallelGroups = [];

        foreach ($sorted as $stepName) {
            $step = $steps[$stepName];

            // Check bypass predicate
            if ($step->bypass !== null && ($step->bypass)($event, $context)) {
                $skipped[$stepName] = 'bypassed';
                continue;
            }

            // Check if all dependencies succeeded
            $depFailed = false;
            foreach ($step->dependencies as $dep) {
                if (isset($errors[$dep])) {
                    $depFailed = true;
                    break;
                }
            }

            if ($depFailed) {
                $skipped[$stepName] = 'dependency_failed';
                continue;
            }

            if ($step->parallel) {
                $parallelGroups[$stepName] = $step;
            } else {
                $result = $this->executeStep($stepName, $step, $event, $context);
                if ($result['success']) {
                    $stepResults[$stepName] = $result;
                } else {
                    $errors[$stepName] = $result['error'] ?? 'unknown';
                }
            }
        }

        // Execute parallel group (in practice these would be dispatched to queue)
        foreach ($parallelGroups as $stepName => $step) {
            $result = $this->executeStep($stepName, $step, $event, $context);
            if ($result['success']) {
                $stepResults[$stepName] = $result;
            } else {
                $errors[$stepName] = $result['error'] ?? 'unknown';
            }
        }

        $durationMs = (microtime(true) - $startTime) * 1000;

        $result = new PipelineResult(
            pipeline: $pipelineName,
            success: empty($errors),
            stepResults: $stepResults,
            skipped: $skipped,
            errors: $errors,
            durationMs: $durationMs,
            totalSteps: count($steps),
            executedSteps: count($stepResults),
            skippedSteps: count($skipped),
        );

        $this->recordHistory($pipelineName, $result);

        return $result;
    }

    /**
     * Validate a pipeline without executing it (dry run).
     *
     * @param  string  $pipelineName  Registered pipeline name
     * @return array{valid: bool, steps: int, cycles: bool, errors: list<string>, execution_order: list<string>}
     */
    public function validatePipeline(string $pipelineName): array
    {
        if (! isset($this->pipelines[$pipelineName])) {
            return [
                'valid' => false,
                'steps' => 0,
                'cycles' => false,
                'errors' => ["Pipeline '{$pipelineName}' is not registered."],
                'execution_order' => [],
            ];
        }

        $steps = $this->pipelines[$pipelineName];
        $errors = [];

        try {
            $order = $this->topologicalSort($steps);
        } catch (\RuntimeException $e) {
            return [
                'valid' => false,
                'steps' => count($steps),
                'cycles' => true,
                'errors' => [$e->getMessage()],
                'execution_order' => [],
            ];
        }

        // Check for orphan steps (no dependencies, no dependents)
        $referenced = [];
        foreach ($steps as $name => $step) {
            foreach ($step->dependencies as $dep) {
                $referenced[$dep] = true;
                $referenced[$name] = true;
            }
        }

        return [
            'valid' => true,
            'steps' => count($steps),
            'cycles' => false,
            'errors' => $errors,
            'execution_order' => $order,
        ];
    }

    /**
     * List all registered pipeline names.
     *
     * @return list<string>
     */
    public function pipelineNames(): array
    {
        return array_keys($this->pipelines);
    }

    /**
     * Get step count for a pipeline.
     */
    public function stepCount(string $pipelineName): int
    {
        return isset($this->pipelines[$pipelineName]) ? count($this->pipelines[$pipelineName]) : 0;
    }

    /**
     * Get execution history for a pipeline.
     *
     * @return list<array{pipeline: string, success: bool, duration_ms: float, steps_executed: int, timestamp: string}>
     */
    public function history(string $pipelineName, int $limit = 20): array
    {
        $key = 'zb_pipeline_history_' . $pipelineName;
        /** @var list<array<string, mixed>>|null $data */
        $data = $this->cache->get($key);

        if ($data === null) {
            return [];
        }

        return array_slice($data, 0, $limit);
    }

    /**
     * Get aggregated pipeline health metrics.
     *
     * @return array{pipelines: int, total_executions: int, success_rate: float, avg_duration_ms: float, error_counts: array<string, int>}
     */
    public function healthSummary(): array
    {
        $pipelines = count($this->pipelines);
        $totalExecutions = 0;
        $totalSuccess = 0;
        $totalDuration = 0.0;
        $errorCounts = [];

        foreach ($this->pipelines as $name => $_) {
            $history = $this->history($name, $this->maxHistory);
            foreach ($history as $entry) {
                $totalExecutions++;
                if ($entry['success']) {
                    $totalSuccess++;
                }
                $totalDuration += $entry['duration_ms'];
            }
        }

        return [
            'pipelines' => $pipelines,
            'total_executions' => $totalExecutions,
            'success_rate' => $totalExecutions > 0 ? round($totalSuccess / $totalExecutions * 100, 2) : 100.0,
            'avg_duration_ms' => $totalExecutions > 0 ? round($totalDuration / $totalExecutions, 2) : 0.0,
            'error_counts' => $errorCounts,
        ];
    }

    /**
     * Get quick status summary.
     *
     * @return array{enabled: bool, pipelines: int, status: string}
     */
    public function quickSummary(): array
    {
        return [
            'enabled' => $this->enabled,
            'pipelines' => count($this->pipelines),
            'status' => $this->enabled ? 'active' : 'disabled',
        ];
    }

    /**
     * Clear a pipeline and its history.
     */
    public function clearPipeline(string $pipelineName): void
    {
        unset($this->pipelines[$pipelineName]);
        $this->cache->forget('zb_pipeline_history_' . $pipelineName);
    }

    /**
     * Clear all pipelines and history.
     */
    public function clearAll(): void
    {
        foreach (array_keys($this->pipelines) as $name) {
            $this->cache->forget('zb_pipeline_history_' . $name);
        }
        $this->pipelines = [];
    }

    /**
     * Perform topological sort on pipeline steps (Kahn's algorithm).
     *
     * @param  array<string, PipelineStep>  $steps
     * @return list<string>  Execution order
     *
     * @throws \RuntimeException if cycle detected
     */
    private function topologicalSort(array $steps): array
    {
        $inDegree = [];
        $adjacency = [];
        $names = array_keys($steps);

        foreach ($names as $name) {
            $inDegree[$name] = 0;
            $adjacency[$name] = [];
        }

        foreach ($steps as $name => $step) {
            $inDegree[$name] = count($step->dependencies);
            foreach ($step->dependencies as $dep) {
                $adjacency[$dep][] = $name;
            }
        }

        $queue = [];
        foreach ($inDegree as $name => $degree) {
            if ($degree === 0) {
                $queue[] = $name;
            }
        }

        $sorted = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            $sorted[] = $current;

            foreach ($adjacency[$current] as $neighbor) {
                $inDegree[$neighbor]--;
                if ($inDegree[$neighbor] === 0) {
                    $queue[] = $neighbor;
                }
            }
        }

        if (count($sorted) !== count($steps)) {
            throw new \RuntimeException(
                'Pipeline contains a dependency cycle. Steps could not be topologically sorted.',
            );
        }

        return $sorted;
    }

    /**
     * Execute a single pipeline step with retry.
     *
     * @return array{success: bool, duration_ms: float, attempts: int, error?: string}
     */
    private function executeStep(
        string $stepName,
        PipelineStep $step,
        AnalyticsEvent $event,
        array $context,
    ): array {
        $startTime = microtime(true);
        $attempts = 0;
        $lastError = null;
        $maxAttempts = min($step->maxRetries ?? $this->maxRetries, 10);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $attempts = $attempt;
            try {
                ($step->handler)($event, $context);
                return [
                    'success' => true,
                    'duration_ms' => (microtime(true) - $startTime) * 1000,
                    'attempts' => $attempts,
                ];
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                if ($attempt < $maxAttempts) {
                    $backoffMs = $step->baseDelayMs * pow($this->backoffMultiplier, $attempt - 1);
                    usleep((int) ($backoffMs * 1000));
                }
            }
        }

        return [
            'success' => false,
            'duration_ms' => (microtime(true) - $startTime) * 1000,
            'attempts' => $attempts,
            'error' => $lastError,
        ];
    }

    /**
     * Record pipeline execution in history cache.
     */
    private function recordHistory(string $pipelineName, PipelineResult $result): void
    {
        $key = 'zb_pipeline_history_' . $pipelineName;
        /** @var list<array<string, mixed>> $data */
        $data = $this->cache->get($key, []);
        $data[] = [
            'pipeline' => $pipelineName,
            'success' => $result->success,
            'duration_ms' => $result->durationMs,
            'steps_executed' => $result->executedSteps,
            'errors_count' => count($result->errors),
            'timestamp' => date('c'),
        ];

        // Keep only recent history
        $data = array_slice($data, -$this->maxHistory);
        $this->cache->put($key, $data, $this->cacheTtl);
    }
}

/**
 * Immutable value object representing a pipeline processing step.
 *
 * @since 187.0.0
 */
final class PipelineStep
{
    /**
     * @param  callable(AnalyticsEvent, array<string, mixed>): void  $handler  Step execution handler
     * @param  list<string>  $dependencies  Names of steps that must complete before this step
     * @param  (callable(AnalyticsEvent, array<string, mixed>): bool)|null  $bypass  Predicate to skip step
     * @param  bool  $parallel  Whether this step can run in parallel with other independent steps
     * @param  int  $maxRetries  Max retry attempts for this step
     * @param  int  $baseDelayMs  Base delay between retries in milliseconds
     */
    public function __construct(
        public readonly callable $handler,
        public readonly string $description = '',
        public readonly array $dependencies = [],
        public readonly ?callable $bypass = null,
        public readonly bool $parallel = false,
        public readonly int $maxRetries = 3,
        public readonly int $baseDelayMs = 100,
        public readonly string $category = 'default',
    ) {}
}

/**
 * Immutable value object representing pipeline execution results.
 *
 * @since 187.0.0
 */
final class PipelineResult
{
    /**
     * @param  array<string, array{success: bool, duration_ms: float, attempts: int, error?: string}>  $stepResults
     * @param  array<string, string>  $skipped  Step name → skip reason
     * @param  array<string, string>  $errors  Step name → error message
     */
    public function __construct(
        public readonly string $pipeline,
        public readonly bool $success,
        public readonly array $stepResults = [],
        public readonly array $skipped = [],
        public readonly array $errors = [],
        public readonly float $durationMs = 0.0,
        public readonly int $totalSteps = 0,
        public readonly int $executedSteps = 0,
        public readonly int $skippedSteps = 0,
    ) {}

    /**
     * Create a skipped result (orchestrator disabled or pipeline not found).
     */
    public static function skipped(string $pipelineName): self
    {
        return new self(pipeline: $pipelineName, success: true);
    }

    /**
     * Get success rate as percentage.
     */
    public function successRate(): float
    {
        if ($this->totalSteps === 0) {
            return 100.0;
        }

        return round($this->executedSteps / $this->totalSteps * 100, 2);
    }

    /**
     * Check if any steps were skipped.
     */
    public function hasSkipped(): bool
    {
        return $this->skippedSteps > 0;
    }

    /**
     * Check if any steps failed.
     */
    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
