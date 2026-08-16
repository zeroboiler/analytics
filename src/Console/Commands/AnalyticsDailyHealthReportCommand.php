<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\AnalyticsDailyHealthReportService;

/**
 * Analytics daily health report command.
 *
 * Generates a comprehensive health report aggregating all analytics
 * subsystem health signals into a single scored view with actionable
 * recommendations. Designed for daily cron execution by SaaS operators.
 *
 * @since 116.0.0
 */
final class AnalyticsDailyHealthReportCommand extends Command
{
    protected $signature = 'zb:analytics:health-report
        {--force : Force regeneration bypassing cache}
        {--json : Output as JSON (for API/webhook delivery)}
        {--domain=* : Show only specific domain scores (provider_health, pipeline_health, etc.)}
        {--compact : Compact output (score + grade only)}
        {--clear-cache : Clear cached health report}';

    protected $description = 'Generate comprehensive daily analytics health report';

    /**
     * Execute the health report command.
     */
    #[Override]
    public function handle(AnalyticsDailyHealthReportService $service): int
    {
        // Handle cache clear
        if ($this->option('clear-cache')) {
            $service->clearCache();
            $this->info('✓ Health report cache cleared.');

            return self::SUCCESS;
        }

        // Generate report
        $forceRefresh = (bool) $this->option('force');
        $report = $service->generate($forceRefresh);

        // JSON output
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        // Domain filter
        $domainFilter = $this->option('domain');
        if (! empty($domainFilter)) {
            $this->showFilteredDomains($report, $domainFilter);

            return self::SUCCESS;
        }

        // Compact output
        if ($this->option('compact')) {
            $this->showCompact($report);

            return self::SUCCESS;
        }

        // Full report
        $this->showFullReport($report);

        return self::SUCCESS;
    }

    /**
     * Display the full health report.
     *
     * @param  array<string, mixed>  $report
     */
    private function showFullReport(array $report): void
    {
        $this->showHeader($report);
        $this->showOverallScore($report);
        $this->showDomainScores($report);
        $this->showCriticalIssues($report);
        $this->showWarnings($report);
        $this->showRecommendations($report);
        $this->showMetadata($report);
    }

    /**
     * Show report header.
     *
     * @param  array<string, mixed>  $report
     */
    private function showHeader(array $report): void
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info('  🏥 ZeroBoiler Analytics — Daily Health Report');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->line("  Generated: <comment>{$report['generated_at']}</comment>");
        $this->line("  Version:   <comment>{$report['metadata']['version']}</comment>");
        $this->newLine();
    }

    /**
     * Show overall score with grade badge.
     *
     * @param  array<string, mixed>  $report
     */
    private function showOverallScore(array $report): void
    {
        $score = $report['overall_score'];
        $grade = $report['grade'];
        $status = $this->statusEmoji($score);

        $this->info("  Overall Score: {$status} <options=bold>{$score}/100</options=bold> (Grade: <options=bold>{$grade}</options=bold>)");
        $this->newLine();
    }

    /**
     * Show per-domain scores with visual bar.
     *
     * @param  array<string, mixed>  $report
     */
    private function showDomainScores(array $report): void
    {
        $this->info('  ┌─ Domain Scores');
        $this->info('  │');

        $domains = $report['domains'];
        $weights = AnalyticsDailyHealthReportService::domainWeights();

        foreach ($domains as $name => $domain) {
            $score = $domain['score'];
            $status = $domain['status'];
            $emoji = $this->domainStatusEmoji($status);
            $weight = $weights[$name] ?? 0;
            $bar = $this->scoreBar($score);
            $label = str_replace('_', ' ', (string) $name);
            $label = ucwords($label);

            $this->line("  │  {$emoji} {$label}");
            $this->line("  │     Score: {$score}/100 | Weight: {$weight}% | Status: {$status}");
            $this->line("  │     {$bar}");
        }

        $this->info('  │');
        $this->info('  └───────────────────────────────────────────────────────────');
        $this->newLine();
    }

    /**
     * Show critical issues section.
     *
     * @param  array<string, mixed>  $report
     */
    private function showCriticalIssues(array $report): void
    {
        $critical = $report['critical_issues'];

        if (empty($critical)) {
            $this->info('  ✅ No critical issues detected.');

            return;
        }

        $this->error("  🚨 Critical Issues ({$this->countLabel(count($critical))})");

        foreach ($critical as $i => $issue) {
            $this->line("  {$this->numberLabel($i + 1)} <fg=red>{$issue['message']}</>");
            $this->line("     Domain: <comment>{$issue['domain']}</comment>");
            $this->line("     Fix: <fg=cyan>{$issue['recommendation']}</>");
        }
    }

    /**
     * Show warnings section.
     *
     * @param  array<string, mixed>  $report
     */
    private function showWarnings(array $report): void
    {
        $warnings = $report['warnings'];

        if (empty($warnings)) {
            $this->newLine();

            return;
        }

        $this->newLine();
        $this->warn("  ⚠️  Warnings ({$this->countLabel(count($warnings))})");

        foreach ($warnings as $i => $issue) {
            $this->line("  {$this->numberLabel($i + 1)} <fg=yellow>{$issue['message']}</>");
            $this->line("     Domain: <comment>{$issue['domain']}</comment>");
        }
    }

    /**
     * Show recommendations section.
     *
     * @param  array<string, mixed>  $report
     */
    private function showRecommendations(array $report): void
    {
        $recommendations = $report['recommendations'];

        if (empty($recommendations)) {
            $this->newLine();
            $this->info('  📋 No additional recommendations — your analytics setup is in good shape!');

            return;
        }

        $this->newLine();
        $this->info('  📋 Recommendations');

        foreach ($recommendations as $rec) {
            $priorityEmoji = match ($rec['priority']) {
                'critical' => '🔴',
                'high' => '🟠',
                'medium' => '🟡',
                'low' => '🟢',
                default => '⚪',
            };

            $this->line("  {$priorityEmoji} [{$rec['priority']}] {$rec['action']}");
            $this->line("     Impact: <comment>{$rec['impact']}</comment>");
        }
    }

    /**
     * Show report metadata.
     *
     * @param  array<string, mixed>  $report
     */
    private function showMetadata(array $report): void
    {
        $meta = $report['metadata'];
        $this->newLine();
        $this->info('  ─────────────────────────────────────────────────────────────');
        $this->line("  📊 Catalog Events: {$meta['catalog_events']} | Providers: {$meta['provider_count']} | Config Sections: {$meta['config_sections']}");
        $this->info('  ─────────────────────────────────────────────────────────────');
        $this->newLine();
    }

    /**
     * Show compact score output.
     *
     * @param  array<string, mixed>  $report
     */
    private function showCompact(array $report): void
    {
        $score = $report['overall_score'];
        $grade = $report['grade'];

        $this->line("{$score}/100 [{$grade}]");

        foreach ($report['domains'] as $name => $domain) {
            $this->line("  {$name}: {$domain['score']}/100 [{$domain['status']}]");
        }
    }

    /**
     * Show filtered domain scores.
     *
     * @param  array<string, mixed>  $report
     * @param  list<string>  $domains
     */
    private function showFilteredDomains(array $report, array $domains): void
    {
        foreach ($domains as $domain) {
            if (! isset($report['domains'][$domain])) {
                $valid = AnalyticsDailyHealthReportService::healthDomains();
                $this->error("Unknown domain: {$domain}. Valid: " . implode(', ', $valid));

                continue;
            }

            $d = $report['domains'][$domain];
            $label = str_replace('_', ' ', $domain);
            $label = ucwords($label);
            $this->line("{$label}: {$d['score']}/100 [{$d['status']}]");

            foreach ($d['issues'] as $issue) {
                $this->line("  - [{$issue['severity']}] {$issue['message']}");
            }
        }
    }

    /**
     * Get emoji for overall score.
     */
    private function statusEmoji(int $score): string
    {
        return match (true) {
            $score >= 80 => '🟢',
            $score >= 60 => '🟡',
            $score >= 40 => '🟠',
            default => '🔴',
        };
    }

    /**
     * Get emoji for domain status.
     */
    private function domainStatusEmoji(string $status): string
    {
        return match ($status) {
            'healthy' => '✅',
            'degraded' => '⚠️',
            'critical' => '❌',
            default => '⚪',
        };
    }

    /**
     * Generate a visual score bar.
     */
    private function scoreBar(int $score): string
    {
        $filled = (int) round($score / 5);
        $empty = 20 - $filled;

        $color = match (true) {
            $score >= 80 => '<fg=green>',
            $score >= 60 => '<fg=yellow>',
            $score >= 40 => '<fg=red>',
            default => '<fg=red>',
        };

        return $color . str_repeat('█', $filled) . '<fg=gray>' . str_repeat('░', $empty) . '</> ' . $score . '%';
    }

    /**
     * Format count label.
     */
    private function countLabel(int $count): string
    {
        return $count === 1 ? '1 issue' : "{$count} issues";
    }

    /**
     * Format number label.
     */
    private function numberLabel(int $n): string
    {
        return $n < 10 ? " {$n}." : "{$n}.";
    }
}
