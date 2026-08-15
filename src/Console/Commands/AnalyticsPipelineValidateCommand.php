<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Pipeline\Validation\EventValidationPipeline;

/**
 * Validates the event validation pipeline and tests it against catalog events.
 *
 * Provides a single-command diagnostic for operators to verify pipeline health,
 * test individual events against the validation pipeline, and get a summary
 * of pipeline stage coverage.
 *
 * @since 69.0.0
 */
final class AnalyticsPipelineValidateCommand extends Command
{
    protected $signature = 'zb:analytics:pipeline:validate
        {--event= : Validate a specific event by name}
        {--all : Validate all catalog events}
        {--json : Output as JSON}
        {--fail-fast : Stop on first critical error}
        {--stages : Show stage details and descriptions}
        {--summary-only : Show only the pipeline summary}';

    protected $description = 'Validate events through the multi-stage validation pipeline';

    private ?EventValidationPipeline $pipeline = null;

    #[Override]
    #[Override]
    public function handle(): int
    {
        $this->pipeline = EventValidationPipeline::withDefaults(
            $this->getStageConfigs(),
        );

        $asJson = (bool) $this->option('json');
        $failFast = (bool) $this->option('fail-fast');

        if ($failFast) {
            // Rebuild with fail-fast enabled
            $this->pipeline = EventValidationPipeline::withFailFast(
                $this->getStageConfigs(),
            );
        }

        // Show pipeline info
        $pipelineSummary = $this->pipeline->summary();

        if ((bool) $this->option('stages')) {
            return $asJson
                ? $this->outputStagesJson($pipelineSummary)
                : $this->outputStagesTable($pipelineSummary);
        }

        $eventName = $this->option('event');

        if ($eventName !== null) {
            return $asJson
                ? $this->validateSingleEventJson((string) $eventName)
                : $this->validateSingleEvent((string) $eventName);
        }

        if ((bool) $this->option('all')) {
            return $asJson
                ? $this->validateAllEventsJson()
                : $this->validateAllEvents();
        }

        // Default: show pipeline summary
        return $asJson
            ? $this->outputSummaryJson($pipelineSummary)
            : $this->outputSummary($pipelineSummary);
    }

    /**
     * Validate a single event and display results.
     */
    private function validateSingleEvent(string $eventName): int
    {
        $event = $this->buildTestEvent($eventName);

        if ($event === null) {
            $this->error("Event '{$eventName}' not found in EventCatalog.");

            return self::FAILURE;
        }

        $report = $this->pipeline->validate($event);

        $this->line('');
        $this->line("  Pipeline validation for: <info>{$event->name}</info>");
        $this->line('  ' . str_repeat('─', 60));

        // Overall result
        if ($report['valid']) {
            $this->line("  Status: <fg=green>PASSED</fg=green> (score: " . number_format($report['score'], 2) . ')');
        } else {
            $this->line("  Status: <fg=red>FAILED</fg=red> (score: " . number_format($report['score'], 2) . ')');
        }

        $this->line("  Stages: {$report['passed_count']}/{$report['stage_count']} passed, {$report['skipped_count']} skipped");
        $this->line("  Errors: {$report['total_errors']}, Warnings: {$report['total_warnings']}");
        $this->line('');

        // Per-stage results
        foreach ($report['stages'] as $stageName => $stageResult) {
            $icon = $stageResult['passed'] ? '<fg=green>✓</fg=green>' : '<fg=red>✗</fg=red>';
            $metrics = "checked={$stageResult['metrics']['checked']} failed={$stageResult['metrics']['failed']}";
            $duration = number_format($stageResult['duration_ms'], 3) . 'ms';

            $this->line("  {$icon} <comment>{$stageName}</comment> ({$duration}) [{$metrics}]");

            foreach ($stageResult['errors'] as $error) {
                $severity = $error['severity'] === 'error'
                    ? '<fg=red>ERROR</fg=red>'
                    : '<fg=yellow>WARN</fg=yellow>';
                $field = isset($error['field']) ? " [{$error['field']}]" : '';
                $this->line("      {$severity}: {$error['code']} — {$error['message']}{$field}");
            }
        }

        return $report['valid'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Validate all catalog events.
     */
    private function validateAllEvents(): int
    {
        $allEvents = EventCatalog::all();
        $total = count($allEvents);
        $passed = 0;
        $failed = 0;
        $errors = 0;
        $warnings = 0;

        $this->line('');
        $this->line("  Validating <info>{$total}</info> catalog events through pipeline...");
        $this->line('  ' . str_repeat('─', 60));

        // Sample up to 50 events for performance
        $sampleSize = min($total, 50);
        $sampled = array_slice($allEvents, 0, $sampleSize, true);
        $step = max(1, (int) floor($total / $sampleSize));

        $bar = $this->output->createProgressBar($sampleSize);
        $bar->start();

        foreach ($sampled as $name => $entry) {
            $event = $this->buildTestEvent($name);
            if ($event === null) {
                $bar->advance();

                continue;
            }

            $report = $this->pipeline->validate($event);

            if ($report['valid']) {
                $passed++;
            } else {
                $failed++;
            }
            $errors += $report['total_errors'];
            $warnings += $report['total_warnings'];

            $bar->advance();
        }

        $bar->finish();
        $this->line('');

        $avgScore = ($passed + $failed) > 0
            ? $passed / ($passed + $failed)
            : 1.0;

        $this->line("  <info>Results</info> (sampled {$sampleSize}/{$total}):");
        $this->line("  Passed:  <fg=green>{$passed}</fg=green>");
        $this->line("  Failed:  <fg=red>{$failed}</fg=red>");
        $this->line("  Errors:  {$errors}");
        $this->line("  Warnings: {$warnings}");
        $this->line("  Avg Score: " . number_format($avgScore * 100, 1) . '%');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Output pipeline summary.
     */
    private function outputSummary(array $pipelineSummary): int
    {
        $this->line('');
        $this->line('  <info>Event Validation Pipeline</info>');
        $this->line('  ' . str_repeat('─', 60));
        $this->line('  Stages:     ' . implode(', ', $pipelineSummary['stages']));
        $this->line('  Enabled:    ' . (empty($pipelineSummary['enabled_stages']) ? 'none' : implode(', ', $pipelineSummary['enabled_stages'])));
        $this->line('  Disabled:   ' . (empty($pipelineSummary['disabled_stages']) ? 'none' : implode(', ', $pipelineSummary['disabled_stages'])));
        $this->line('  Fail-fast:  ' . ($pipelineSummary['fail_fast'] ? 'yes' : 'no'));
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Output stages table.
     */
    private function outputStagesTable(array $pipelineSummary): int
    {
        $this->line('');
        $this->line('  <info>Pipeline Stages</info>');
        $this->line('');

        $headers = ['Stage', 'Status', 'Description'];
        $rows = [];

        foreach ($this->pipeline->stageDescriptions() as $name => $description) {
            $enabled = in_array($name, $pipelineSummary['enabled_stages'], true);
            $rows[] = [$name, $enabled ? '<fg=green>enabled</fg=green>' : '<fg=yellow>disabled</fg=yellow>', $description];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    private function validateSingleEventJson(string $eventName): int
    {
        $event = $this->buildTestEvent($eventName);
        if ($event === null) {
            $this->line(json_encode(['error' => "Event '{$eventName}' not found in EventCatalog"], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $report = $this->pipeline->validate($event);
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $report['valid'] ? self::SUCCESS : self::FAILURE;
    }

    private function validateAllEventsJson(): int
    {
        $allEvents = EventCatalog::all();
        $total = count($allEvents);
        $sampleSize = min($total, 50);
        $sampled = array_slice($allEvents, 0, $sampleSize, true);

        $results = [];
        $passed = 0;
        $failed = 0;

        foreach ($sampled as $name => $entry) {
            $event = $this->buildTestEvent($name);
            if ($event === null) {
                continue;
            }

            $report = $this->pipeline->validate($event);
            $results[$name] = [
                'valid' => $report['valid'],
                'score' => $report['score'],
                'errors' => $report['total_errors'],
                'warnings' => $report['total_warnings'],
            ];

            if ($report['valid']) {
                $passed++;
            } else {
                $failed++;
            }
        }

        $this->line(json_encode([
            'sampled' => $sampleSize,
            'total' => $total,
            'passed' => $passed,
            'failed' => $failed,
            'results' => $results,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function outputSummaryJson(array $pipelineSummary): int
    {
        $this->line(json_encode($pipelineSummary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function outputStagesJson(array $pipelineSummary): int
    {
        $this->line(json_encode([
            'summary' => $pipelineSummary,
            'descriptions' => $this->pipeline->stageDescriptions(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * Build a synthetic test event for pipeline validation.
     */
    private function buildTestEvent(string $name): ?AnalyticsEvent
    {
        $entry = EventCatalog::get($name);
        if ($entry === null) {
            return null;
        }

        // Build minimal test params based on category
        $params = match (EventCatalog::getCategory($name)) {
            'ecommerce' => [
                'item_id' => 'test-001',
                'item_name' => 'Test Product',
                'currency' => 'USD',
                'value' => 29.99,
            ],
            'saas' => [
                'user_id' => 'user-test-001',
                'plan' => 'pro',
            ],
            'engagement' => [
                'page_url' => '/test',
                'page_title' => 'Test Page',
            ],
            default => [
                'source' => 'pipeline_validate',
            ],
        };

        return new AnalyticsEvent(
            name: $name,
            params: $params,
        );
    }

    /**
     * Get stage configuration from config repository.
     *
     * @return array<string, mixed>
     */
    private function getStageConfigs(): array
    {
        try {
            $config = app(\Illuminate\Contracts\Config\Repository::class);

            return $config->get('zeroboiler.analytics.validation_pipeline', []);
        } catch (\Throwable) {
            return [];
        }
    }
}
