<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\SaaSCoverageReportService;

/**
 * Generates a comprehensive SaaS analytics coverage audit report.
 *
 * Evaluates all 12 core capabilities required for industry-standard
 * product analytics and reports a weighted score (0-100) with
 * letter grade (A+ to F).
 *
 * Options:
 *   --json       Output as machine-readable JSON
 *   --summary    Show only score and grade
 *   --missing    Show only missing/partial capabilities
 *   --clear-cache  Clear the cached report before running
 *
 * @since 67.0.0
 */
final class AnalyticsCoverageCommand extends Command
{
    protected $signature = 'zb:analytics:coverage
        {--json : Output as machine-readable JSON}
        {--summary : Show score and grade only}
        {--missing : Show only missing and partial capabilities}
        {--clear-cache : Clear cached report before running}';

    protected $description = 'Generate SaaS analytics coverage audit report (12 capabilities)';

    private SaaSCoverageReportService $service;

    public function __construct(SaaSCoverageReportService $service): void
    {
        parent::__construct();
        $this->service = $service;
    }

    /**
     * Execute the coverage audit command.
     */
    #[Override]
    public function handle(): int
    {
        $outputJson = (bool) $this->option('json');
        $summaryOnly = (bool) $this->option('summary');
        $missingOnly = (bool) $this->option('missing');
        $clearCache = (bool) $this->option('clear-cache');

        if ($clearCache) {
            $this->service->clearCache();
        }

        $report = $this->service->auditCached();

        if ($outputJson) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($summaryOnly) {
            $summary = $this->service->summary();
            $gradeColor = $this->gradeColor($summary['grade']);
            $this->line("Score: <{$gradeColor}>{$summary['score']}</> / 100");
            $this->line("Grade: <{$gradeColor}>{$summary['grade']}</>");
            $this->line("Implemented: <info>{$summary['implemented']}</> / {$summary['total']}");

            return self::SUCCESS;
        }

        // Full report
        $gradeColor = $this->gradeColor($report['grade']);
        $this->info('📊 SaaS Analytics Coverage Report');
        $this->line('   Version: <info>' . $report['version'] . '</info>');
        $this->newLine();
        $this->line("   Score: <{$gradeColor}>{$report['score']}</>/100");
        $this->line("   Grade: <{$gradeColor}>{$report['grade']}</>");
        $this->newLine();

        // Capability table
        $rows = [];
        foreach ($report['capabilities'] as $key => $cap) {
            if ($missingOnly && $cap['status'] === 'implemented') {
                continue;
            }

            $statusIcon = match ($cap['status']) {
                'implemented' => '✅',
                'partial' => '⚠️ ',
                default => '❌',
            };

            $rows[] = [
                $statusIcon,
                $cap['label'],
                $cap['status'],
                $cap['weight'],
                implode(', ', $cap['evidence']),
            ];
        }

        $this->table(
            ['', 'Capability', 'Status', 'Weight', 'Evidence'],
            $rows,
        );

        // Recommendations
        $allRecommendations = [];
        foreach ($report['capabilities'] as $cap) {
            if ($cap['recommendations'] !== []) {
                foreach ($cap['recommendations'] as $rec) {
                    $allRecommendations[] = ["[{$cap['label']}]", $rec];
                }
            }
        }

        if ($allRecommendations !== []) {
            $this->newLine();
            $this->info('💡 Recommendations');
            $this->table(['Area', 'Action'], $allRecommendations);
        } else {
            $this->newLine();
            $this->info('🎉 All 12 capabilities are fully implemented!');
        }

        $this->newLine();
        $this->comment('Use --summary for score only, --missing for gaps only, --json for machine-readable output.');

        return self::SUCCESS;
    }

    /**
     * Get the console color for a grade.
     */
    private function gradeColor(string $grade): string
    {
        if (str_starts_with($grade, 'A')) {
            return 'info';
        }

        if (str_starts_with($grade, 'B')) {
            return 'comment';
        }

        if (str_starts_with($grade, 'C')) {
            return 'warning';
        }

        return 'error';
    }
}
