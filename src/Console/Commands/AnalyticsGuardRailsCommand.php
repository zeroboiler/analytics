<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Services\TrackingGuardRailsService;
use ZeroBoiler\Analytics\Services\EventStreamService;

/**
 * Analytics Guard Rails command — tracking quality monitoring dashboard.
 *
 * Runs the full guard rails check and displays a multi-dimensional
 * quality score with violations, recommendations, and coverage analysis.
 * Inspired by Amplitude Compass, Mixpanel Data Governance, and Segment Protocols.
 *
 * @see \ZeroBoiler\Analytics\Services\TrackingGuardRailsService
 *
 * @since 8.9.0
 */
final class AnalyticsGuardRailsCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:guard-rails
        {--json : Output as JSON}
        {--violations : Show violations only}
        {--quick : Quick score only}
        {--clear-cache : Clear guard rails cache before check}';

    /** @var string */
    protected $description = 'Run analytics guard rails check — tracking quality monitoring';

    /**
     * Execute the console command.
     */
    #[Override]
    public function handle(
        TrackingGuardRailsService $guardRails,
        EventStreamService $streamService,
        ConfigRepository $config,
    ): int
    {
        if ($this->option('clear-cache')) {
            $guardRails->clearCache();
            $this->info('Guard rails cache cleared.');
        }

        if (! $guardRails->isEnabled()) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'enabled' => false,
                    'message' => 'Guard rails are disabled in configuration',
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            $this->warn('Guard rails are disabled. Set ANALYTICS_GUARD_RAILS_ENABLED=true to enable.');

            return self::SUCCESS;
        }

        // Gather metrics from the event stream service
        $metrics = $this->gatherMetrics($streamService, $config);

        // Run the check
        if ($this->option('quick')) {
            $result = $guardRails->quickScore($metrics);

            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            $this->outputQuickScore($result);

            return self::SUCCESS;
        }

        $report = $guardRails->check($metrics);

        if ($this->option('violations')) {
            $minSeverity = 'info';
            $violations = $guardRails->violations($metrics, $minSeverity);

            if ($this->option('json')) {
                $this->line(json_encode([
                    'score' => $report['score'],
                    'grade' => $report['grade'],
                    'violation_count' => count($violations),
                    'violations' => $violations,
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            return $this->outputViolations($violations, $report['score'], $report['grade']);
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        return $this->outputFullReport($report);
    }

    /**
     * Gather metrics from event stream service and config.
     *
     * @return array{total_events: int, tracked_event_names: list<string>, identity_linked_count: int, total_clients: int, consent_log_enabled: bool, consent_default: string}
     */
    private function gatherMetrics(EventStreamService $streamService, ConfigRepository $config): array
    {
        $stats = $streamService->getStats();
        $events = $streamService->getRecentEvents(500);
        $consentConfig = $config->get('zeroboiler.analytics.consent', []);
        /** @var array{log_enabled?: bool, default?: string} $consentConfig */

        $trackedNames = [];
        foreach ($events as $event) {
            $name = $event['event'] ?? null;
            if (is_string($name) && $name !== '' && ! in_array($name, $trackedNames, true)) {
                $trackedNames[] = $name;
            }
        }

        return [
            'total_events' => (int) ($stats['total_events'] ?? 0),
            'tracked_event_names' => $trackedNames,
            'identity_linked_count' => (int) ($stats['identity_linked_count'] ?? 0),
            'total_clients' => (int) ($stats['unique_clients'] ?? 0),
            'consent_log_enabled' => (bool) ($consentConfig['log_enabled'] ?? false),
            'consent_default' => (string) ($consentConfig['default'] ?? 'granted'),
        ];
    }

    /**
     * Output the full report to the console.
     *
     * @param  array<string, mixed>  $report
     * @return int
     */
    private function outputFullReport(array $report): int
    {
        $score = $report['score'];
        $grade = $report['grade'];

        // Header
        $this->newLine();
        $this->components->info('ZeroBoiler Analytics — Guard Rails Report');

        // Score badge
        $gradeColor = match ($grade) {
            'A' => 'green',
            'B' => 'green',
            'C' => 'yellow',
            'D' => 'red',
            'F' => 'red',
            default => 'gray',
        };

        $this->newLine();
        $this->line("  Quality Score: <{$gradeColor}>{$score}/100 (Grade {$grade})</{$gradeColor}>");
        $this->line("  Generated: {$report['generated_at']}");

        // Dimensions
        $this->newLine();
        $this->line('  <options=bold>Dimensions:</options>');
        foreach ($report['dimensions'] as $key => $dim) {
            $dimScore = $dim['score'];
            $weight = $dim['weight'];
            $status = $dim['status'];
            $label = $dim['label'];

            $statusColor = match ($status) {
                'excellent' => 'green',
                'good' => 'green',
                'fair' => 'yellow',
                'poor' => 'red',
                'critical' => 'red',
                default => 'gray',
            };

            $bar = $this->renderBar($dimScore);
            $this->line("    <options=bold>{$label}</options> ({$weight}%) <{$statusColor}>{$dimScore}/100</{$statusColor}> {$bar}");
        }

        // Coverage
        $coverage = $report['coverage'];
        $this->newLine();
        $this->line('  <options=bold>Coverage:</options>');
        $this->line("    Core events tracked: {$coverage['completeness']}%");
        $this->line("    Total events: {$coverage['total_events']}");

        if ($coverage['core_tracked'] !== []) {
            $this->line('    Tracked: ' . implode(', ', $coverage['core_tracked']));
        }

        if ($coverage['core_missing'] !== []) {
            $this->line('    <fg=red>Missing: ' . implode(', ', $coverage['core_missing']) . '</fg=red>');
        }

        // Naming
        $naming = $report['naming'];
        $this->newLine();
        $this->line('  <options=bold>Naming Convention:</options>');
        $this->line('    Compliance: ' . (naming['rate'] * 100) . '% ({$naming[\'compliant\']}/{$naming[\'total\']})');

        if ($naming['violations'] !== []) {
            $display = array_slice($naming['violations'], 0, 10);
            foreach ($display as $violation) {
                $this->line("    <fg=yellow>  ⚠ {$violation}</fg=yellow>");
            }
            $remaining = count($naming['violations']) - 10;
            if ($remaining > 0) {
                $this->line("    <fg=gray>  ... and {$remaining} more</fg=gray>");
            }
        }

        // Violations
        $this->newLine();
        $this->outputViolationsInline($report['violations']);

        // Recommendations
        $this->newLine();
        $this->outputRecommendations($report['recommendations']);

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Output quick score to the console.
     *
     * @param  array{score: int, grade: string, label: string, generated_at: string}  $result
     */
    private function outputQuickScore(array $result): void
    {
        $gradeColor = match ($result['grade']) {
            'A', 'B' => 'green',
            'C' => 'yellow',
            'D', 'F' => 'red',
            default => 'gray',
        };

        $this->line("<{$gradeColor}>{$result['score']}/100 ({$result['grade']}) — {$result['label']}</{$gradeColor}>");
    }

    /**
     * Output violations in full report mode.
     *
     * @param  list<array{severity: string, dimension: string, message: string, recommendation: string}>  $violations
     */
    private function outputViolationsInline(array $violations): void
    {
        if ($violations === []) {
            $this->line('  <fg=green>✓ No violations detected</fg=green>');

            return;
        }

        $this->line('  <options=bold>Violations:</options>');

        $critical = array_filter($violations, fn (array $v): bool => $v['severity'] === 'critical');
        $warnings = array_filter($violations, fn (array $v): bool => $v['severity'] === 'warning');
        $info = array_filter($violations, fn (array $v): bool => $v['severity'] === 'info');

        foreach ($critical as $v) {
            $this->line("    <fg=red>✗ [CRITICAL] {$v['message']}</fg=red>");
            $this->line("      <fg=gray>  → {$v['recommendation']}</fg=gray>");
        }

        foreach ($warnings as $v) {
            $this->line("    <fg=yellow>⚠ [WARNING] {$v['message']}</fg=yellow>");
            $this->line("      <fg=gray>  → {$v['recommendation']}</fg=gray>");
        }

        foreach ($info as $v) {
            $this->line("    <fg=blue>ℹ [INFO] {$v['message']}</fg=blue>");
        }
    }

    /**
     * Output violations in violations-only mode.
     *
     * @param  list<array{severity: string, dimension: string, message: string, recommendation: string}>  $violations
     * @return int
     */
    private function outputViolations(array $violations, int $score, string $grade): int
    {
        if ($violations === []) {
            $this->components->info("Guard Rails: {$score}/100 ({$grade}) — No violations");

            return self::SUCCESS;
        }

        $count = count($violations);
        $criticalCount = count(array_filter($violations, fn (array $v): bool => $v['severity'] === 'critical'));
        $warningCount = count(array_filter($violations, fn (array $v): bool => $v['severity'] === 'warning'));

        $this->components->warn("Guard Rails: {$score}/100 ({$grade}) — {$count} violations ({$criticalCount} critical, {$warningCount} warnings)");

        foreach ($violations as $v) {
            $color = match ($v['severity']) {
                'critical' => 'red',
                'warning' => 'yellow',
                default => 'blue',
            };
            $icon = match ($v['severity']) {
                'critical' => '✗',
                'warning' => '⚠',
                default => 'ℹ',
            };
            $this->line("  <{$color}>{$icon} [{$v['dimension']}] {$v['message']}</{$color}>");
            $this->line("    <fg=gray>→ {$v['recommendation']}</fg=gray>");
        }

        return $criticalCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Output recommendations.
     *
     * @param  list<string>  $recommendations
     */
    private function outputRecommendations(array $recommendations): void
    {
        if ($recommendations === []) {
            return;
        }

        $this->line('  <options=bold>Recommendations:</options>');
        foreach (array_slice($recommendations, 0, 10) as $i => $rec) {
            $num = $i + 1;
            $this->line("    <fg=cyan>{$num}.</fg=cyan> {$rec}");
        }
        $remaining = count($recommendations) - 10;
        if ($remaining > 0) {
            $this->line("    <fg=gray>... and {$remaining} more recommendations</fg=gray>");
        }
    }

    /**
     * Render a simple text-based progress bar.
     *
     * @param  int  $score  0-100
     * @return string
     */
    private function renderBar(int $score): string
    {
        $width = 20;
        $filled = (int) round($score / 100 * $width);
        $empty = $width - $filled;

        $color = match (true) {
            $score >= 70 => 'green',
            $score >= 40 => 'yellow',
            default => 'red',
        };

        return "<{$color}>" . str_repeat('█', $filled) . '</' . $color . '>' . str_repeat('░', $empty);
    }
}
