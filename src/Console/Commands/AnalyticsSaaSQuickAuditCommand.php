<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\SaaSStarterQuickAuditService;

/**
 * Runs a comprehensive SaaS Starter production readiness audit.
 *
 * Validates all 12 industry-standard SaaS analytics features against
 * the current installation and returns an A+ through F grade with
 * per-feature scores, actionable gap analysis, and a prioritized
 * remediation list.
 *
 * Use for CI/CD readiness gates, pre-deploy checks, and onboarding audits.
 *
 * @since 252.0.0
 *
 * @see \ZeroBoiler\Analytics\Services\SaaSStarterQuickAuditService
 */
final class AnalyticsSaaSQuickAuditCommand extends Command
{
    protected $signature = 'zb:analytics:saas-audit
        {--json : Output as JSON}
        {--gaps : Show only gaps (failed checks)}
        {--score : Show only score and grade}\n        {--gates : Exit with code 1 if not production-ready}';

    protected $description = 'Run SaaS Starter production readiness audit (12-feature scorecard)';

    private SaaSStarterQuickAuditService $auditService;

    public function __construct(SaaSStarterQuickAuditService $auditService): void
    {
        parent::__construct();
        $this->auditService = $auditService;
    }

    /**
     * Execute the SaaS starter audit.
     */
    #[\Override]
    public function handle(): int
    {
        $outputJson = (bool) $this->option('json');
        $gapsOnly = (bool) $this->option('gaps');
        $scoreOnly = (bool) $this->option('score');
        $gates = (bool) $this->option('gates');

        // Quick score mode
        if ($scoreOnly) {
            $quick = $this->auditService->quickScore();

            if ($outputJson) {
                $this->line(json_encode($quick, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

                return $gates && $quick['score'] < 80.0 ? self::FAILURE : self::SUCCESS;
            }

            $gradeColor = $this->gradeColor($quick['grade']);
            $this->line("");
            $this->line("  SaaS Starter Score: <fg={$gradeColor}>{$quick['score']}/100 ({$quick['grade']})</>");
            $this->line("");

            return $gates && $quick['score'] < 80.0 ? self::FAILURE : self::SUCCESS;
        }

        // Full audit
        $audit = $this->auditService->audit();

        if ($gapsOnly) {
            return $this->renderGaps($audit, $outputJson, $gates);
        }

        if ($outputJson) {
            $this->line(json_encode($audit, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $gates && ! $this->auditService->isProductionReady() ? self::FAILURE : self::SUCCESS;
        }

        $this->renderAudit($audit);

        return $gates && ! $this->auditService->isProductionReady() ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Render the full audit report to the console.
     *
     * @param array{score: float, grade: string, features: array<string, array{label: string, score: int, max: int, weight: float, checks: list<array{pass: bool, description: string}>}>, gaps: list<array{feature: string, severity: string, finding: string, remediation: string}>, summary: array{total_checks: int, passed: int, failed: int, warnings: int, feature_count: int, catalog_events: int, starter_coverage: float}} $audit
     */
    private function renderAudit(array $audit): void
    {
        $gradeColor = $this->gradeColor($audit['grade']);

        $this->line('');
        $this->line('  📊 SaaS Starter Production Readiness Audit');
        $this->line('  ' . str_repeat('─', 50));
        $this->line("");

        // Header score
        $this->line(sprintf(
            '  Overall: <fg=%s;options=bold>%s / 100  (%s)</fg>',
            $gradeColor,
            $audit['score'],
            $audit['grade'],
        ));
        $this->line("");

        // Per-feature table
        $this->line('  Feature Scores:');
        $this->line('  ' . str_repeat('─', 50));

        foreach ($audit['features'] as $key => $feature) {
            $bar = $this->renderBar($feature['score'], $feature['max'], 20);
            $color = $this->scoreColor($feature['score'], $feature['max']);
            $pct = $feature['max'] > 0 ? round(($feature['score'] / $feature['max']) * 100) : 0;

            $this->line(sprintf(
                '  <fg=cyan>%-28s</> <fg=%s>%s</> %3d%%',
                $feature['label'],
                $color,
                $bar,
                $pct,
            ));
        }

        $this->line('');

        // Summary
        $s = $audit['summary'];
        $this->line('  Summary:');
        $this->line('  ' . str_repeat('─', 50));
        $this->line("  Checks passed:   <fg=green>{$s['passed']}/{$s['total_checks']}</>");
        $this->line("  Checks failed:   <fg=red>{$s['failed']}</>");
        $this->line("  Catalog events:  {$s['catalog_events']}");
        $this->line("  Starter coverage: {$s['starter_coverage']}%");
        $this->line("");

        // Gaps (only if any)
        if ($audit['gaps'] !== []) {
            $this->line('  ⚠  Gaps (' . count($audit['gaps']) . '):');
            $this->line('  ' . str_repeat('─', 50));

            foreach (array_slice($audit['gaps'], 0, 15) as $gap) {
                $icon = $gap['severity'] === 'critical' ? '❌' : ($gap['severity'] === 'warning' ? '⚠️ ' : 'ℹ️ ');
                $color = $gap['severity'] === 'critical' ? 'red' : ($gap['severity'] === 'warning' ? 'yellow' : 'gray');

                $this->line(sprintf(
                    '  %s <fg=%s>[%s]</> %s',
                    $icon,
                    $color,
                    strtoupper($gap['severity']),
                    $gap['finding'],
                ));
            }

            if (count($audit['gaps']) > 15) {
                $this->line(sprintf('  ... and %d more. Use --gaps for full list.', count($audit['gaps']) - 15));
            }

            $this->line('');
        }

        // Production readiness verdict
        $ready = $this->auditService->isProductionReady();
        if ($ready) {
            $this->info('  ✅ Production Ready — all 12 features meet SaaS starter requirements.');
        } else {
            $this->warn('  ⚠️  Not production ready — resolve critical gaps above.');
        }

        $this->line('');
    }

    /**
     * Render only gaps.
     *
     * @param array{gaps: list<array{feature: string, severity: string, finding: string, remediation: string}>} $audit
     */
    private function renderGaps(array $audit, bool $outputJson, bool $gates): int
    {
        if ($outputJson) {
            $this->line(json_encode($audit['gaps'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $gates && $audit['gaps'] !== [] ? self::FAILURE : self::SUCCESS;
        }

        if ($audit['gaps'] === []) {
            $this->info('  ✅ No gaps found — all checks passed.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  ⚠  SaaS Starter Gaps (' . count($audit['gaps']) . '):');
        $this->line('  ' . str_repeat('─', 60));

        foreach ($audit['gaps'] as $i => $gap) {
            $severityIcon = $gap['severity'] === 'critical' ? '❌' : ($gap['severity'] === 'warning' ? '⚠️ ' : 'ℹ️ ');
            $color = $gap['severity'] === 'critical' ? 'red' : ($gap['severity'] === 'warning' ? 'yellow' : 'gray');

            $this->line(sprintf(
                '  %s <fg=%s>[%s]</> %s',
                $severityIcon,
                $color,
                strtoupper($gap['severity']),
                $gap['finding'],
            ));
            $this->line(sprintf('      <fg=gray>↳ %s</>', $gap['remediation']));

            if ($i < count($audit['gaps']) - 1) {
                $this->line('');
            }
        }

        $this->line('');

        return $gates ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Render a visual progress bar.
     */
    private function renderBar(int $score, int $max, int $width): string
    {
        $filled = $max > 0 ? (int) round(($score / $max) * $width) : 0;
        $empty = $width - $filled;

        return str_repeat('█', $filled) . str_repeat('░', $empty);
    }

    /**
     * Get ANSI color for a score.
     */
    private function scoreColor(int $score, int $max): string
    {
        $pct = $max > 0 ? $score / $max : 0;

        if ($pct >= 0.9) {
            return 'green';
        }
        if ($pct >= 0.7) {
            return 'cyan';
        }
        if ($pct >= 0.5) {
            return 'yellow';
        }

        return 'red';
    }

    /**
     * Get ANSI color for a grade letter.
     */
    private function gradeColor(string $grade): string
    {
        return match ($grade) {
            'A+', 'A', 'A-' => 'green',
            'B+', 'B', 'B-' => 'cyan',
            'C+', 'C', 'C-' => 'yellow',
            'D+', 'D', 'D-' => 'red',
            default       => 'red',
        };
    }
}
