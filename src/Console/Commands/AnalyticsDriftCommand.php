<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\SchemaDriftRecord;
use ZeroBoiler\Analytics\DTO\SchemaMigrationPlan;
use ZeroBoiler\Analytics\Services\EventSchemaDriftDetectorService;

/**
 * Analytics Schema Drift Detector CLI Command.
 *
 * Detects schema drift in event payloads across observation windows,
 * generates migration plans, and reports on schema stability trends.
 *
 * Modes:
 * - detect: Scan all events for schema drift between two windows
 * - trend: Analyze schema evolution trend for a specific event
 * - plan: Generate a migration plan for a detected drift
 * - summary: Comprehensive drift summary across all events
 *
 * @see \ZeroBoiler\Analytics\Services\EventSchemaDriftDetectorService
 *
 * @since 223.0.0
 */
final class AnalyticsDriftCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:drift
        {mode=detect : Analysis mode: detect, trend, plan, summary}
        {--event= : Specific event name (for trend/plan modes)}
        {--baseline= : Baseline window date (Y-m-d, default: 7 days ago)}
        {--current= : Current window date (Y-m-d, default: today)}
        {--windows=7 : Number of observation windows for trend analysis}
        {--json : Output results as JSON}
        {--severity= : Filter by severity: breaking, non_breaking}
    ';

    /** @var string */
    protected $description = 'Detect event schema drift, analyze trends, and generate migration plans';

    /**
     * Execute the console command.
     */
    public function handle(EventSchemaDriftDetectorService $detector): int
    {
        $mode = $this->argument('mode');

        return match ($mode) {
            'detect' => $this->runDetect($detector),
            'trend' => $this->runTrend($detector),
            'plan' => $this->runPlan($detector),
            'summary' => $this->runSummary($detector),
            default => $this->invalidMode($mode),
        };
    }

    /**
     * Run schema drift detection across all events.
     */
    private function runDetect(EventSchemaDriftDetectorService $detector): int
    {
        $baseline = $this->option('baseline') ?? date('Y-m-d', strtotime('-7 days'));
        $current = $this->option('current') ?? date('Y-m-d');
        $jsonOutput = $this->option('json');
        $severityFilter = $this->option('severity');

        $this->components->info("Scanning for schema drift: {$baseline} → {$current}");

        $drifts = $detector->detectDriftAll($baseline, $current);

        if ($severityFilter !== null) {
            $drifts = array_filter(
                $drifts,
                static fn (SchemaDriftRecord $d): bool => $d->severity === $severityFilter,
            );
            $drifts = array_values($drifts);
        }

        if ($drifts === []) {
            $this->components->info('No schema drift detected.');

            return self::SUCCESS;
        }

        // Sort by drift score descending
        usort($drifts, static fn (SchemaDriftRecord $a, SchemaDriftRecord $b): int => $b->driftScore <=> $a->driftScore);

        if ($jsonOutput) {
            $this->line(json_encode(array_map(
                static fn (SchemaDriftRecord $d): array => $d->toArray(),
                $drifts,
            ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->table(
            ['Event', 'Severity', 'Drift Score', 'Changes', 'Fields Δ', 'Providers'],
            array_map(static function (SchemaDriftRecord $d): array {
                return [
                    $d->eventName,
                    $d->severity === 'breaking' ? '🔴 BREAKING' : '🟡 non-breaking',
                    (string) $d->driftScore,
                    (string) count($d->changes),
                    "{$d->totalFieldsBaseline} → {$d->totalFieldsCurrent}",
                    implode(', ', $d->affectedProviders),
                ];
            }, $drifts),
        );

        // Show details for each drift
        foreach ($drifts as $drift) {
            $this->newLine();
            $this->components->twoColumnDetail(
                "  📋 {$drift->eventName}",
                "Snapshot: " . substr($drift->currentSnapshot, 0, 12),
            );

            foreach ($drift->changes as $change) {
                $icon = match ($change['type']) {
                    'added' => '➕',
                    'removed' => '➖',
                    'type_changed' => '🔄',
                    default => '❓',
                };

                $this->components->twoColumnDetail(
                    "    {$icon} {$change['field']}",
                    "{$change['type']} ({$change['severity']})",
                );
            }
        }

        $breaking = array_filter($drifts, static fn (SchemaDriftRecord $d): bool => $d->severity === 'breaking');
        if (count($breaking) > 0) {
            $this->newLine();
            $this->components->warn(count($breaking) . ' breaking drift(s) detected — run "analytics:drift plan --event=<name>" to generate migration plans.');
        }

        return self::SUCCESS;
    }

    /**
     * Run schema drift trend analysis for a specific event.
     */
    private function runTrend(EventSchemaDriftDetectorService $detector): int
    {
        $eventName = $this->option('event');

        if ($eventName === null) {
            $this->components->error('--event is required for trend mode.');

            return self::FAILURE;
        }

        $windowCount = (int) $this->option('windows');
        $jsonOutput = $this->option('json');

        $this->components->info("Analyzing schema trend for '{$eventName}' ({$windowCount} windows)");

        $trend = $detector->analyzeTrend($eventName, $windowCount);

        if ($jsonOutput) {
            $this->line(json_encode($trend->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $gradeColor = match ($trend->stabilityGrade) {
            'A', 'B' => 'green',
            'C' => 'yellow',
            'D', 'E' => 'red',
            default => 'red',
        };

        $this->newLine();
        $this->components->twoColumnDetail('Event', $trend->eventName);
        $this->components->twoColumnDetail('Stability Grade', "<fg={$gradeColor}>{$trend->stabilityGrade}</>");
        $this->components->twoColumnDetail('Instability Score', (string) $trend->instabilityScore);
        $this->components->twoColumnDetail('Drift Frequency', (string) $trend->driftFrequency);
        $this->components->twoColumnDetail('Total Drifts', (string) $trend->totalDriftsDetected);
        $this->components->twoColumnDetail('Observation Windows', (string) $trend->observationWindows);

        if ($trend->topChangedFields !== []) {
            $this->newLine();
            $this->components->info('Top Changed Fields:');
            foreach ($trend->topChangedFields as $i => $field) {
                $this->components->twoColumnDetail("  " . ($i + 1) . '.', $field);
            }
        }

        if ($trend->recommendations !== []) {
            $this->newLine();
            $this->components->info('Recommendations:');
            foreach ($trend->recommendations as $rec) {
                $this->line("  • {$rec}");
            }
        }

        // Window history table
        if ($trend->windowHistory !== []) {
            $this->newLine();
            $this->table(
                ['Window', 'Snapshot', 'Fields', 'Drift Score'],
                array_map(static fn (array $w): array => [
                    $w['window'],
                    substr($w['snapshot'], 0, 12),
                    (string) $w['field_count'],
                    (string) $w['drift_score'],
                ], $trend->windowHistory),
            );
        }

        return self::SUCCESS;
    }

    /**
     * Generate a migration plan for a detected drift.
     */
    private function runPlan(EventSchemaDriftDetectorService $detector): int
    {
        $eventName = $this->option('event');

        if ($eventName === null) {
            $this->components->error('--event is required for plan mode.');

            return self::FAILURE;
        }

        $baseline = $this->option('baseline') ?? date('Y-m-d', strtotime('-7 days'));
        $current = $this->option('current') ?? date('Y-m-d');
        $jsonOutput = $this->option('json');

        $this->components->info("Generating migration plan for '{$eventName}' ({$baseline} → {$current})");

        $drift = $detector->detectDrift($eventName, $baseline, $current);

        if ($drift === null) {
            $this->components->info("No schema drift detected for '{$eventName}'.");

            return self::SUCCESS;
        }

        $plan = $detector->generateMigrationPlan($drift);

        if ($jsonOutput) {
            $this->line(json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $riskColor = match ($plan->riskLevel) {
            'critical' => 'red',
            'high' => 'yellow',
            default => 'green',
        };

        $this->newLine();
        $this->components->twoColumnDetail('Event', $plan->eventName);
        $this->components->twoColumnDetail('Risk Level', "<fg={$riskColor}>{$plan->riskLevel}</>");
        $this->components->twoColumnDetail('Breaking Changes', $plan->hasBreakingChanges() ? 'YES' : 'No');
        $this->components->twoColumnDetail('Affected Consumers', (string) $plan->estimatedImpactConsumers);
        $this->components->twoColumnDetail('Steps', (string) count($plan->steps));

        if ($plan->prerequisites !== []) {
            $this->newLine();
            $this->components->info('Prerequisites:');
            foreach ($plan->prerequisites as $prereq) {
                $this->line("  ◆ {$prereq}");
            }
        }

        $this->newLine();
        $this->components->info('Migration Steps:');

        foreach ($plan->steps as $i => $step) {
            $urgencyIcon = match ($step['urgency']) {
                'critical' => '🔴',
                'high' => '🟡',
                default => '🟢',
            };

            $this->newLine();
            $fieldDisplay = $step['field'] ?? 'N/A';
            $this->line("  {$urgencyIcon} Step " . ($i + 1) . ": [{$step['action']}] {$fieldDisplay}");
            $this->components->twoColumnDetail('    Description', $step['description']);
            $this->components->twoColumnDetail('    Urgency', $step['urgency']);
            $this->components->twoColumnDetail('    Consumers', implode(', ', $step['affected_consumers']));

            if ($step['code_example'] !== null) {
                $this->newLine();
                $this->line("    <fg=cyan>Code:</>");
                foreach (explode("\n", $step['code_example']) as $line) {
                    $this->line("      {$line}");
                }
            }
        }

        $this->newLine();
        $this->components->info('Rollback Strategy:');
        $this->line("  {$plan->rollbackStrategy}");

        return self::SUCCESS;
    }

    /**
     * Run comprehensive drift summary.
     */
    private function runSummary(EventSchemaDriftDetectorService $detector): int
    {
        $baseline = $this->option('baseline') ?? date('Y-m-d', strtotime('-7 days'));
        $current = $this->option('current') ?? date('Y-m-d');
        $jsonOutput = $this->option('json');

        $this->components->info("Generating drift summary: {$baseline} → {$current}");

        $summary = $detector->driftSummary($baseline, $current);

        if ($jsonOutput) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->twoColumnDetail('Total Events Tracked', (string) $summary['total_events']);
        $this->components->twoColumnDetail('Drifted Events', (string) $summary['drifted_events']);
        $this->components->twoColumnDetail('Stable Events', (string) $summary['stable_events']);
        $this->components->twoColumnDetail('Breaking Drifts', (string) $summary['breaking_count']);
        $this->components->twoColumnDetail('Non-Breaking Drifts', (string) $summary['non_breaking_count']);
        $this->components->twoColumnDetail('Avg Drift Score', (string) $summary['avg_drift_score']);

        if ($summary['most_drifted'] !== []) {
            $this->newLine();
            $this->components->info('Most Drifted Events:');
            foreach ($summary['most_drifted'] as $i => $name) {
                $this->components->twoColumnDetail('  ' . ($i + 1) . '.', $name);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Handle invalid mode.
     */
    private function invalidMode(string $mode): int
    {
        $this->components->error("Invalid mode: '{$mode}'. Use: detect, trend, plan, summary.");

        return self::FAILURE;
    }
}
