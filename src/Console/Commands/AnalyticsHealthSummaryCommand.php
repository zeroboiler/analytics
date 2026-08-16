<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\ProductMarketFitScoringService;
use ZeroBoiler\Analytics\Services\UnifiedHealthEndpointService;

/**
 * Analytics unified health summary command.
 *
 * Provides a combined health check across all subsystems plus
 * PMF scoring summary. Designed for ops dashboards and CI health gates.
 *
 * @since 47.0.0
 */
final class AnalyticsHealthSummaryCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:health-summary
        {--mode=full : Output mode (full, liveness, readiness, pmf, pmf-grade)}
        {--json : Output as JSON}
    ';

    /** @var string */
    protected $description = 'Unified health summary across all analytics subsystems';

    /**
     * Execute the console command.
     */
    #[Override]
    public function handle(
        UnifiedHealthEndpointService $health,
        ProductMarketFitScoringService $pmf,
    ): int {
        $mode = (string) $this->option('mode');
        $asJson = (bool) $this->option('json');

        return match ($mode) {
            'liveness' => $this->showLiveness($health, $asJson),
            'readiness' => $this->showReadiness($health, $asJson),
            'pmf' => $this->showPmf($pmf, $asJson),
            'pmf-grade' => $this->showPmfGrade($pmf, $asJson),
            default => $this->showFull($health, $pmf, $asJson),
        };
    }

    /**
     * Show full unified health check.
     *
     * @return int Exit code
     */
    private function showFull(UnifiedHealthEndpointService $health, ProductMarketFitScoringService $pmf, bool $asJson): int
    {
        $report = $health->check();

        // Attach PMF scoring
        $cachedPmf = $pmf->getCachedScore();
        $report['pmf'] = $cachedPmf ?? ['cached' => false, 'message' => 'Run PMF computation to cache a score'];

        if ($asJson) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $statusColor = match ($report['status']) {
            'healthy' => 'info',
            'warning' => 'warn',
            'critical' => 'error',
            default => 'line',
        };

        $this->components->{$statusColor}("Unified Health Status: {$report['status']} (score: {$report['score']}/100)");

        // Subsystems table
        $rows = [];
        foreach ($report['subsystems'] as $name => $subsystem) {
            $rows[] = [$name, $subsystem['status'], (string) $subsystem['score']];
        }
        $this->table(['Subsystem', 'Status', 'Score'], $rows);

        // Warnings
        if (! empty($report['warnings'])) {
            $this->warn('Warnings:');
            foreach ($report['warnings'] as $warning) {
                $this->line("  - {$warning}");
            }
        }

        // Recommendations
        if (! empty($report['recommendations'])) {
            $this->line("\nRecommendations:");
            foreach ($report['recommendations'] as $rec) {
                $this->line("  → {$rec}");
            }
        }

        // PMF summary
        if (isset($report['pmf']) && is_array($report['pmf']) && isset($report['pmf']['score'])) {
            $this->newLine();
            $this->components->info("PMF Score: {$report['pmf']['score']}/100 (Grade: {$report['pmf']['grade']})");
        } elseif (isset($report['pmf']['message'])) {
            $this->newLine();
            $this->comment('PMF: ' . $report['pmf']['message']);
        }

        return $report['status'] === 'critical' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Show liveness probe result.
     *
     * @return int Exit code
     */
    private function showLiveness(UnifiedHealthEndpointService $health, bool $asJson): int
    {
        $result = $health->liveness();

        if ($asJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $color = $result['status'] === 'healthy' ? 'info' : 'error';
        $this->components->{$color}("Liveness: {$result['status']}");

        return $result['status'] === 'healthy' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Show readiness probe result.
     *
     * @return int Exit code
     */
    private function showReadiness(UnifiedHealthEndpointService $health, bool $asJson): int
    {
        $result = $health->readiness();

        if ($asJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $color = $result['ready'] ? 'info' : 'error';
        $this->components->{$color}("Readiness: " . ($result['ready'] ? 'READY' : 'NOT READY'));

        foreach ($result['subsystems'] as $name => $status) {
            $this->line("  {$name}: {$status}");
        }

        return $result['ready'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Show PMF scoring details.
     *
     * @return int Exit code
     */
    private function showPmf(ProductMarketFitScoringService $pmf, bool $asJson): int
    {
        $cached = $pmf->getCachedScore();

        if ($asJson) {
            $this->line(json_encode([
                'cached' => $cached,
                'config' => $pmf->getConfigSummary(),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($cached !== null) {
            $this->components->info("PMF Score: {$cached['score']}/100 ({$cached['grade']})");

            $rows = [];
            foreach ($cached['signals'] as $name => $signal) {
                $rows[] = [
                    $name,
                    $signal['value'] !== null ? (string) $signal['value'] : 'N/A',
                    (string) $signal['score'],
                    (string) $signal['weight'],
                    (string) $signal['contribution'],
                ];
            }
            $this->table(['Signal', 'Value', 'Score', 'Weight', 'Contribution'], $rows);

            if (! empty($cached['recommendations'])) {
                $this->newLine();
                $this->comment('Recommendations:');
                foreach ($cached['recommendations'] as $rec) {
                    $this->line("  → {$rec}");
                }
            }
        } else {
            $this->warn('No cached PMF score available. Compute and cache a score first.');
            $this->comment('Config: ' . json_encode($pmf->getConfigSummary(), JSON_PRETTY_PRINT));
        }

        return self::SUCCESS;
    }

    /**
     * Show PMF grade only (compact output).
     *
     * @return int Exit code
     */
    private function showPmfGrade(ProductMarketFitScoringService $pmf, bool $asJson): int
    {
        $cached = $pmf->getCachedScore();

        $data = $cached !== null
            ? ['score' => $cached['score'], 'grade' => $cached['grade'], 'cached' => true]
            : ['score' => null, 'grade' => null, 'cached' => false];

        if ($asJson) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($data['cached']) {
            $this->line("{$data['score']}/100 [{$data['grade']}]");
        } else {
            $this->line('no cached score');
        }

        return self::SUCCESS;
    }
}
