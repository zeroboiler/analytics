<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\PLGScoringService;

/**
 * Artisan command to display PLG (Product-Led Growth) scores.
 *
 * Computes and displays PLG scores for individual identities or
 * aggregate segment distribution across all tracked users.
 *
 * @since 6.0.0
 */
final class AnalyticsPLGScoreCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'analytics:plg
        {--identity= : Specific user/client ID to score}
        {--batch : Score all identities from event stream}
        {--distribution : Show segment distribution}
        {--invalidate= : Invalidate cached score for an identity}
        {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compute and display PLG (Product-Led Growth) scores';

    /**
     * Execute the console command.
     */
    #[Override]
    #[Override]
    public function handle(PLGScoringService $service): int
    {
        $identity = $this->option('identity');
        $batch = $this->boolOption('batch');
        $distribution = $this->boolOption('distribution');
        $invalidate = $this->option('invalidate');
        $asJson = $this->boolOption('json');

        if ($invalidate !== null && is_string($invalidate) && $invalidate !== '') {
            $service->invalidateScore($invalidate);
            $this->info("Invalidated cached PLG score for: {$invalidate}");

            return self::SUCCESS;
        }

        if ($distribution) {
            return $this->showDistribution($service, $asJson);
        }

        if ($batch) {
            return $this->showBatch($service, $asJson);
        }

        if ($identity !== null && is_string($identity) && $identity !== '') {
            return $this->showSingleScore($service, $identity, $asJson);
        }

        // Default: show aggregate stats
        return $this->showAggregate($service, $asJson);
    }

    /**
     * Show PLG score for a single identity.
     */
    private function showSingleScore(PLGScoringService $service, string $identity, bool $asJson): int
    {
        try {
            $score = $service->score($identity);
        } catch (\Throwable $e) {
            $this->error("Failed to compute PLG score: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($asJson) {
            $this->line(json_encode($score, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("═══ PLG Score: {$identity} ═══");
        $this->newLine();
        $this->line("Overall Score:  {$score['score']}/100 (Grade: {$score['grade']})");
        $this->line("Segment:        {$score['segment']}");
        $this->newLine();
        $this->info('── Dimension Scores ──');
        $this->table(
            ['Dimension', 'Score', 'Weight'],
            [
                ['Activation', $score['activation'] . '/100', '30%'],
                ['Engagement', $score['engagement'] . '/100', '30%'],
                ['Retention', $score['retention'] . '/100', '25%'],
                ['Feature Breadth', $score['feature_breadth'] . '/100', '15%'],
            ],
        );

        if (! empty($score['signals'])) {
            $this->newLine();
            $this->info('── Detected Signals ──');
            foreach (array_slice($score['signals'], 0, 10) as $signal) {
                $this->line("  • {$signal}");
            }
        }

        $this->newLine();
        $this->line("Computed at: {$score['computed_at']}");

        return self::SUCCESS;
    }

    /**
     * Show batch scores for all identities.
     */
    private function showBatch(PLGScoringService $service, bool $asJson): int
    {
        try {
            $stats = $service->aggregateStats();
            $scores = $stats['avg_score'] > 0 ? 'See aggregate stats' : 'No cached scores found';
        } catch (\Throwable $e) {
            $this->error("Batch scoring failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($asJson) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("═══ PLG Aggregate Stats ═══");
        $this->newLine();
        $this->line("Average Score:      {$stats['avg_score']}/100");
        $this->line("Total Cached:       {$stats['total_cached']}");
        $this->newLine();

        $dist = $stats['grade_distribution'] ?? [];
        $this->info('── Grade Distribution ──');
        $this->table(
            ['Grade', 'Count'],
            array_map(fn (string $g, int $c): array => [$g, $c], array_keys($dist), array_values($dist)),
        );

        return self::SUCCESS;
    }

    /**
     * Show segment distribution.
     */
    private function showDistribution(PLGScoringService $service, bool $asJson): int
    {
        try {
            // Get aggregate stats as a proxy for distribution
            $stats = $service->aggregateStats();
        } catch (\Throwable $e) {
            $this->error("Distribution computation failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($asJson) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("═══ PLG Segment Distribution ═══");
        $this->newLine();
        $this->line("Average Score: {$stats['avg_score']}/100");
        $this->line("Total Cached:  {$stats['total_cached']}");
        $this->newLine();

        $dist = $stats['grade_distribution'] ?? [];
        $total = array_sum($dist);

        $segments = [
            'champions' => ['label' => 'Champions (A)', 'threshold' => 80],
            'loyal' => ['label' => 'Loyal (B)', 'threshold' => 65],
            'potential' => ['label' => 'Potential (C)', 'threshold' => 50],
            'at_risk' => ['label' => 'At Risk (D)', 'threshold' => 35],
            'dormant' => ['label' => 'Dormant (F)', 'threshold' => 0],
        ];

        $rows = [];
        $runningTotal = 0;

        foreach ($segments as $key => $segment) {
            $count = $dist[array_search(
                $segment['threshold'] >= 80 ? 'A' : ($segment['threshold'] >= 65 ? 'B' : ($segment['threshold'] >= 50 ? 'C' : ($segment['threshold'] >= 35 ? 'D' : 'F'))),
                array_keys($dist),
                true,
            )] ?? 0;
            $runningTotal += $count;
            $pct = $total > 0 ? round(($count / $total) * 100, 1) : 0.0;

            $rows[] = [$segment['label'], $count, $pct . '%'];
        }

        $this->table(['Segment', 'Count', '%'], $rows);

        return self::SUCCESS;
    }

    /**
     * Show aggregate stats (default mode).
     */
    private function showAggregate(PLGScoringService $service, bool $asJson): int
    {
        return $this->showBatch($service, $asJson);
    }

    /**
     * Get a boolean option value, defaulting to false.
     */
    private function boolOption(string $name): bool
    {
        $value = $this->option($name);

        return (bool) $value;
    }
}
