<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\AnalyticsReadinessService;

/**
 * SaaS Starter production readiness check.
 *
 * Runs a comprehensive checklist validating provider configuration,
 * consent defaults, queue setup, identity tracking, event validation,
 * GDPR compliance, and recommended settings for production readiness.
 *
 * Returns 0 if all required checks pass and score meets minimum threshold.
 * Returns 1 if any required check fails or score is below threshold.
 *
 * @since 1.0.0
 */
final class AnalyticsReadinessCommand extends Command
{
    protected $signature = 'zb:analytics:readiness
        {--json : Output as JSON}
        {--no-cache : Force fresh assessment (ignore cache)}';

    protected $description = 'Run production readiness check for ZeroBoiler Analytics';

    private readonly AnalyticsReadinessService $service;

    public function __construct(AnalyticsReadinessService $service): void
    {
        parent::__construct();
        $this->service = $service;
    }

    /**
     * Execute the readiness check.
     */
    #[\Override]
    public function handle(): int
    {
        if ($this->option('no-cache')) {
            $this->service->invalidateCache();
            $report = $this->service->assess();
        } else {
            $report = $this->service->assessCached();
        }

        if ($this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $report->ready ? 0 : 1;
        }

        $this->renderReport($report);

        return $report->ready ? 0 : 1;
    }

    /**
     * Render a human-readable readiness report.
     */
    private function renderReport(mixed $report): void
    {
        $this->newLine();
        $this->info('🚀 ZeroBoiler Analytics — Production Readiness Check');
        $this->newLine();

        // Score bar
        $score = $report->score;
        $bar = $this->buildScoreBar($score);
        $gradeColor = match ($report->grade) {
            'A' => 'green',
            'B' => 'green',
            'C' => 'yellow',
            'D' => 'red',
            'F' => 'red',
            default => 'white',
        };

        $this->line("  Score: <fg={$gradeColor};options=bold>{$score}%</> (Grade {$report->grade})");
        $this->line("  {$bar}");
        $this->line("  {$report->passCount}/{$report->totalChecks} checks passed" . ($report->failCount > 0 ? ", {$report->failCount} failed" : '') . ($report->warnCount > 0 ? ", {$report->warnCount} warnings" : ''));
        $this->newLine();

        // Required checks
        $this->line('<fg=cyan;options=bold>REQUIRED CHECKS</>');
        $this->line('─────────────────────────');

        foreach ($report->results as $name => $result) {
            $icon = $this->statusIcon($result->status);
            $label = $result->message ?? $name;
            $color = match ($result->status) {
                'pass' => 'green',
                'warn' => 'yellow',
                'fail' => 'red',
                default => 'white',
            };
            $this->line("  {$icon} <fg={$color}>{$label}</>");
        }

        $this->newLine();

        // Verdict
        if ($report->ready) {
            $this->line("<fg=green;options=bold>✅ READY FOR PRODUCTION</> (score {$score}% >= {$report->minimumScore}%, all required checks pass)");
        } else {
            $reason = $report->requiredFails > 0
                ? "{$report->requiredFails} required check(s) failed"
                : "score {$score}% < minimum {$report->minimumScore}%";
            $this->line("<fg=red;options=bold>❌ NOT READY FOR PRODUCTION</> — {$reason}");
            $this->newLine();
            $this->line('  Fix the failed checks above before deploying to production.');
        }

        $this->newLine();
    }

    /**
     * Build a visual score bar.
     *
     * @return string
     */
    private function buildScoreBar(int $score): string
    {
        $width = 30;
        $filled = (int) round(($score / 100) * $width);
        $empty = $width - $filled;

        $bar = str_repeat('█', $filled) . str_repeat('░', $empty);

        $color = $score >= 80 ? 'green' : ($score >= 50 ? 'yellow' : 'red');

        return "<fg={$color}>{$bar}</>";
    }

    /**
     * Get a status icon for a check result.
     *
     * @param  string  $status
     * @return string
     */
    private function statusIcon(string $status): string
    {
        return match ($status) {
            'pass' => '✓',
            'warn' => '⚠',
            'fail' => '✗',
            default => '?',
        };
    }
}
