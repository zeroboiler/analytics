<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline\Validation;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Unified, composable multi-stage event validation pipeline.
 *
 * Orchestrates a chain of named validation stages (catalog membership, schema,
 * PII scanning, data quality, GDPR compliance) into a single validation pass.
 * Each stage produces structured diagnostics with error codes, severity levels,
 * and performance metrics.
 *
 * The pipeline supports:
 * - Configurable stage enablement (skip individual stages without removing them)
 * - Early termination on critical errors (configurable)
 * - Aggregated validation report with per-stage and overall scoring
 * - Event metrics accumulation for monitoring
 *
 * Usage:
 *   $pipeline = new EventValidationPipeline();
 *   $pipeline->addStage(new CatalogMembershipStage());
 *   $pipeline->addStage(new PiiScanningStage());
 *
 *   $report = $pipeline->validate($event);
 *   // $report['valid'] === true|false
 *   // $report['stages']['catalog_membership']['passed'] === true|false
 *   // $report['score'] === 0.0-1.0
 *
 * @phpstan-type ValidationReport array{valid: bool, event_name: string, score: float, stage_count: int, passed_count: int, failed_count: int, skipped_count: int, total_errors: int, total_warnings: int, stages: array<string, array{passed: bool, errors: list<array{code: string, message: string, field?: string, severity: 'error'|'warning'|'info'}>, metrics: array{checked: int, failed: int, skipped: int}, duration_ms: float}>, errors: list<array{stage: string, code: string, message: string, field?: string, severity: 'error'|'warning'|'info'}>}
 * @phpstan-type PipelineSummary array{total_events: int, valid_events: int, invalid_events: int, average_score: float, stage_summaries: array<string, array{passed: int, failed: int, skipped: int, avg_duration_ms: float}>, top_errors: list<array{code: string, count: int, last_message: string}>}
 *
 * @since 69.0.0
 */
final class EventValidationPipeline
{
    /** @var list<ValidationStageInterface> */
    private array $stages = [];

    private bool $failFast;

    /**
     * @param  bool  $failFast  Stop on first critical error (severity=error)
     */
    public function __construct(bool $failFast = false){
        $this->failFast = $failFast;
    }

    /**
     * Add a validation stage to the pipeline.
     *
     * Stages are automatically sorted by priority (lower = runs first).
     */
    public function addStage(ValidationStageInterface $stage): self
    {
        $this->stages[] = $stage;
        $this->sortByPriority();

        return $this;
    }

    /**
     * Add multiple validation stages at once.
     *
     * @param  list<ValidationStageInterface>  $stages
     */
    public function addStages(array $stages): self
    {
        foreach ($stages as $stage) {
            $this->stages[] = $stage;
        }
        $this->sortByPriority();

        return $this;
    }

    /**
     * Remove a stage by name.
     */
    public function removeStage(string $name): self
    {
        $this->stages = array_values(
            array_filter(
                $this->stages,
                fn (ValidationStageInterface $s): bool => $s->name() !== $name,
            ),
        );

        return $this;
    }

    /**
     * Enable or disable a stage by name.
     */
    public function toggleStage(string $name, bool $enabled): self
    {
        foreach ($this->stages as $stage) {
            if ($stage->name() === $name) {
                // Stages control their own enabled state — we wrap them
                if (! $enabled) {
                    $this->stages = array_values(
                        array_filter(
                            $this->stages,
                            fn (ValidationStageInterface $s): bool => $s->name() !== $name,
                        ),
                    );
                }

                return $this;
            }
        }

        return $this;
    }

    /**
     * Create a pipeline with all built-in validation stages pre-configured.
     *
     * Adds catalog membership, schema validation, PII scanning, data quality,
     * and compliance stages with sensible defaults.
     *
     * @param  array<string, mixed>  $config  Per-stage configuration overrides
     */
    public static function withDefaults(array $config = []): self
    {
        return (new self)
            ->addStage(new CatalogMembershipStage($config['catalog_membership'] ?? []))
            ->addStage(new SchemaValidationStage($config['schema_validation'] ?? []))
            ->addStage(new PiiScanningStage($config['pii_scanning'] ?? []))
            ->addStage(new DataQualityStage($config['data_quality'] ?? []))
            ->addStage(new ComplianceValidationStage($config['compliance'] ?? []));
    }

    /**
     * Create a pipeline with all built-in stages and fail-fast enabled.
     *
     * @param  array<string, mixed>  $config  Per-stage configuration overrides
     */
    public static function withFailFast(array $config = []): self
    {
        return (new self(true))
            ->addStage(new CatalogMembershipStage($config['catalog_membership'] ?? []))
            ->addStage(new SchemaValidationStage($config['schema_validation'] ?? []))
            ->addStage(new PiiScanningStage($config['pii_scanning'] ?? []))
            ->addStage(new DataQualityStage($config['data_quality'] ?? []))
            ->addStage(new ComplianceValidationStage($config['compliance'] ?? []));
    }

    /**
     * Validate an event through all pipeline stages.
     *
     * @return ValidationReport
     */
    public function validate(AnalyticsEvent $event): array
    {
        $overallValid = true;
        $stages = [];
        $allErrors = [];
        $totalErrors = 0;
        $totalWarnings = 0;
        $passedCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        foreach ($this->stages as $stage) {
            if (! $stage->enabled()) {
                $skippedCount++;
                $stages[$stage->name()] = [
                    'passed' => true,
                    'errors' => [],
                    'metrics' => ['checked' => 0, 'failed' => 0, 'skipped' => 1],
                    'duration_ms' => 0.0,
                ];

                continue;
            }

            $start = hrtime(true);
            $result = $stage->validate($event);
            $duration = (hrtime(true) - $start) / 1_000_000;

            $stageFailed = ! $result['passed'];
            if ($stageFailed) {
                $overallValid = false;
                $failedCount++;
            } else {
                $passedCount++;
            }

            $stageErrors = $result['errors'];
            foreach ($stageErrors as $error) {
                $allErrors[] = array_merge(['stage' => $stage->name()], $error);
                if ($error['severity'] === 'error') {
                    $totalErrors++;
                } else {
                    $totalWarnings++;
                }
            }

            $stages[$stage->name()] = [
                'passed' => $result['passed'],
                'errors' => $stageErrors,
                'metrics' => $result['metrics'],
                'duration_ms' => round($duration, 3),
            ];

            // Fail-fast: abort on critical errors
            if ($this->failFast && $stageFailed) {
                $hasCriticalError = false;
                foreach ($stageErrors as $err) {
                    if ($err['severity'] === 'error') {
                        $hasCriticalError = true;
                        break;
                    }
                }
                if ($hasCriticalError) {
                    break;
                }
            }
        }

        // Compute overall score (0.0 - 1.0)
        $score = $this->computeScore($passedCount, $failedCount, $skippedCount, $totalErrors, $totalWarnings);

        return [
            'valid' => $overallValid,
            'event_name' => $event->name,
            'score' => $score,
            'stage_count' => count($this->stages),
            'passed_count' => $passedCount,
            'failed_count' => $failedCount,
            'skipped_count' => $skippedCount,
            'total_errors' => $totalErrors,
            'total_warnings' => $totalWarnings,
            'stages' => $stages,
            'errors' => $allErrors,
        ];
    }

    /**
     * Get all registered stage names.
     *
     * @return list<string>
     */
    public function stageNames(): array
    {
        return array_map(
            fn (ValidationStageInterface $s): string => $s->name(),
            $this->stages,
        );
    }

    /**
     * Get stage descriptions.
     *
     * @return array<string, string>
     */
    public function stageDescriptions(): array
    {
        $descriptions = [];
        foreach ($this->stages as $stage) {
            $descriptions[$stage->name()] = $stage->description();
        }

        return $descriptions;
    }

    /**
     * Get pipeline summary statistics.
     *
     * @return array{stages: list<string>, enabled_stages: list<string>, disabled_stages: list<string>, fail_fast: bool}
     */
    public function summary(): array
    {
        $enabled = [];
        $disabled = [];

        foreach ($this->stages as $stage) {
            if ($stage->enabled()) {
                $enabled[] = $stage->name();
            } else {
                $disabled[] = $stage->name();
            }
        }

        return [
            'stages' => array_map(fn (ValidationStageInterface $s): string => $s->name(), $this->stages),
            'enabled_stages' => $enabled,
            'disabled_stages' => $disabled,
            'fail_fast' => $this->failFast,
        ];
    }

    /**
     * Get the number of registered stages.
     */
    public function count(): int
    {
        return count($this->stages);
    }

    /**
     * Sort stages by priority (ascending).
     */
    private function sortByPriority(): void
    {
        usort($this->stages, fn (ValidationStageInterface $a, ValidationStageInterface $b): int => $a->priority() <=> $b->priority());
    }

    /**
     * Compute overall validation score.
     */
    private function computeScore(int $passed, int $failed, int $skipped, int $errors, int $warnings): float
    {
        $total = $passed + $failed;
        if ($total === 0) {
            return 1.0;
        }

        $baseScore = $passed / $total;

        // Penalize for errors (heavier) and warnings (lighter)
        $penaltyFactor = 1.0 - (min($errors * 0.15, 0.6)) - (min($warnings * 0.05, 0.2));

        return max(0.0, min(1.0, $baseScore * $penaltyFactor));
    }
}
