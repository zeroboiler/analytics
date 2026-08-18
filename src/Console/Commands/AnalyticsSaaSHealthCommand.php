<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\SaaSHealthScoreAggregator;
use ZeroBoiler\Analytics\Services\EventThrottleService;

/**
 * SaaS Analytics Health Dashboard command.
 *
 * Displays a comprehensive health dashboard for the SaaS analytics
 * instrumentation, including:
 * - Composite health score with grade
 * - Per-dimension breakdown
 * - Event throttle configuration and stats
 * - Quick action recommendations for weak dimensions
 *
 * @since 242.0.0
 */
final class AnalyticsSaaSHealthCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:saas-health
        {--reset-cache : Invalidate the health score cache}
        {--json : Output as JSON}
        {--min-score= : Fail if score is below this threshold}';

    /** @var string */
    protected $description = 'Display SaaS analytics health dashboard with composite score and dimension breakdown';

    /**
     * Execute the console command.
     */
    public function handle(SaaSHealthScoreAggregator $aggregator): int
    {
        if ($this->option('reset-cache')) {
            $aggregator->invalidate();
            $this->info('Health score cache invalidated.');

            return self::SUCCESS;
        }

        $health = $aggregator->compute();

        if ($this->option('json')) {
            $this->line(json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->displayDashboard($health);

        // Check threshold
        $minScore = $this->option('min-score');
        if ($minScore !== null && (float) $health['score'] < (float) $minScore) {
            $this->error("Health score {$health['score']} is below minimum threshold {$minScore}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Display the health dashboard to the console.
     *
     * @param  array{score: float, grade: string, dimensions: array<string, array{score: float, max: float, weight: float, status: string}>, evaluated_at: string, cache_hit: bool}  $health
     */
    private function displayDashboard(array $health): void
    {
        $this->components->info('SaaS Analytics Health Dashboard');

        // Score header
        $grade = $health['grade'];
        $score = $health['score'];
        $color = match (true) {
            $grade === 'A+' || $grade === 'A' => 'green',
            $grade === 'B' => 'blue',
            $grade === 'C' => 'yellow',
            default => 'red',
        };

        $this->newLine();
        $this->line("  <fg={$color}>Composite Score: {$score}/100 (Grade: {$grade})</>");
        $this->line("  Evaluated: {$health['evaluated_at']}" . ($health['cache_hit'] ? ' (cached)' : ' (fresh)'));
        $this->newLine();

        // Dimension breakdown
        $headers = ['Dimension', 'Score', 'Weight', 'Status'];
        $rows = [];

        foreach ($health['dimensions'] as $name => $dim) {
            $display = str_replace('_', ' ', $name);
            $display = ucfirst($display);
            $statusIcon = match ($dim['status']) {
                'healthy' => '<fg=green>✓</>',
                'warning' => '<fg=yellow>⚠</>',
                'critical' => '<fg=red>✗</>',
                default => '?',
            };

            $rows[] = [
                $display,
                number_format($dim['score'], 1) . '/' . number_format($dim['max'], 0),
                number_format($dim['weight'] * 100, 1) . '%',
                $statusIcon . ' ' . $dim['status'],
            ];
        }

        $this->table($headers, $rows);

        // Weak dimensions recommendations
        $weakDimensions = $aggregator = new class {
            public function getWeak(): array
            {
                return [];
            }
        };

        // Get weak dimensions from the score
        $weak = [];
        foreach ($health['dimensions'] as $name => $dim) {
            if ($dim['status'] === 'critical') {
                $weak[] = ucfirst(str_replace('_', ' ', $name));
            }
        }

        if ($weak !== []) {
            $this->newLine();
            $this->components->warn('Critical dimensions needing attention:');
            foreach ($weak as $dim) {
                $this->line("  • {$dim}");
            }
        }

        $this->newLine();
        $this->components->tip('Use --reset-cache to force fresh evaluation, --json for machine-readable output.');
    }
}
