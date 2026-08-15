<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\SaaSReadinessGateService;

/**
 * SaaS Analytics Readiness Gate command.
 *
 * Validates all 12 core SaaS analytics capabilities required for
 * industry-standard product analytics. Designed for use in CI/CD
 * pipelines to prevent broken analytics from reaching production.
 *
 * Returns exit code 0 (SUCCESS) when score >= 80, otherwise returns
 * exit code 1 (FAILURE) to block deployment.
 *
 * @since 91.0.0
 */
final class AnalyticsReadinessGateCommand extends Command
{
    protected $signature = 'zb:analytics:readiness-gate
        {--json : Output as JSON}
        {--threshold=80 : Minimum score to pass (0-100)}
        {--verbose : Show all individual checks}';

    protected $description = 'Validate all 12 SaaS analytics capabilities for CI/CD gate';

    /**
     * Execute the readiness gate check.
     */
    #[Override]
    #[Override]
    public function handle(SaaSReadinessGateService $gate): int
    {
        $threshold = (int) $this->option('threshold');
        $threshold = max(0, min(100, $threshold));

        $result = $gate->validate();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['score'] >= $threshold ? self::SUCCESS : self::FAILURE;
        }

        // Header
        $this->info('🔒 ZeroBoiler Analytics — SaaS Readiness Gate');
        $this->newLine();

        // Score display
        $scoreColor = $result['score'] >= $threshold ? 'info' : 'error';
        $statusLabel = $result['score'] >= $threshold ? '✅ PASSED' : '❌ FAILED';
        $this->$scoreColor(sprintf(
            '  Score: %d/100  Grade: %s  Threshold: %d  %s',
            $result['score'],
            $result['grade'],
            $threshold,
            $statusLabel,
        ));
        $this->newLine();

        // Per-capability breakdown
        $verbose = (bool) $this->option('verbose');

        foreach ($result['capabilities'] as $capability) {
            $icon = match ($capability['status']) {
                'passed' => '✅',
                'partial' => '⚠️ ',
                'failed' => '❌',
                default => '❓',
            };

            $this->line(sprintf('  %s %s', $icon, $capability['label']));

            if ($verbose) {
                foreach ($capability['checks'] as $check) {
                    $checkIcon = $check['passed'] ? '  ✓' : '  ✗';
                    $this->line(sprintf('    %s %s', $checkIcon, $check['message']));
                }
            } else {
                // Show only failing checks
                foreach ($capability['checks'] as $check) {
                    if (! $check['passed']) {
                        $this->error(sprintf('    ✗ %s', $check['message']));
                    }
                }
            }
        }

        $this->newLine();

        // Summary
        $totalChecks = 0;
        $passedChecks = 0;
        foreach ($result['capabilities'] as $capability) {
            foreach ($capability['checks'] as $check) {
                $totalChecks++;
                if ($check['passed']) {
                    $passedChecks++;
                }
            }
        }

        $this->line(sprintf('  Summary: %d/%d checks passed (%d%%)', $passedChecks, $totalChecks, $result['score']));

        if ($result['score'] < $threshold) {
            $this->newLine();
            $this->error('  Readiness gate FAILED — analytics pipeline is not production-ready.');
            $this->error(sprintf('  Score %d is below threshold %d.', $result['score'], $threshold));

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('  Readiness gate PASSED — analytics pipeline is production-ready.');

        return self::SUCCESS;
    }
}
