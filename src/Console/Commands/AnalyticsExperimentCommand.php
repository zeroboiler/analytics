<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\ExperimentAnalysisEngine;

/**
 * CLI command for experiment statistical analysis.
 *
 * Provides an interactive terminal for running hypothesis tests,
 * computing sample sizes, checking sequential tests, and assessing
 * experiment health.
 *
 * @since 75.0.0
 */
final class AnalyticsExperimentCommand extends Command
{
    protected $signature = 'zb:analytics:experiment
        {action : Analysis action to perform}
        {--experiment= : Experiment ID (for analyze, sequential)}
        {--control= : Control variant ID}
        {--method=both : Analysis method (frequentist|bayesian|both)}
        {--metric=conversion_rate : Metric type (conversion_rate|revenue|continuous)}
        {--baseline= : Baseline conversion rate for sample size calc}
        {--mde= : Minimum Detectable Effect (relative, e.g. 0.10)}
        {--sample-size= : Sample size per variant (for MDE calc)}
        {--power=0.80 : Statistical power}
        {--alpha=0.05 : Significance level}
        {--peek= : Current peek number for sequential test}
        {--max-peeks=10 : Maximum peeks for sequential test}
        {--z-score= : Z-score for sequential test}
        {--correction=bonferroni : Multi-variant correction method}
        {--json : Output as JSON}';

    protected $description = 'Run statistical analysis on A/B experiments (frequentist, Bayesian, MDE, sequential)';

    /**
     * Execute the console command.
     */
    public function handle(ExperimentAnalysisEngine $engine): int
    {
        $action = $this->argument('action');
        $asJson = $this->option('json');

        return match ($action) {
            'health' => $this->actionHealth($engine, $asJson),
            'sample-size' => $this->actionSampleSize($engine, $asJson),
            'mde' => $this->actionMDE($engine, $asJson),
            'sequential' => $this->actionSequential($engine, $asJson),
            'quick' => $this->actionQuick($engine, $asJson),
            default => $this->actionAnalyze($engine, $action, $asJson),
        };
    }

    /**
     * Analyze an experiment by ID.
     */
    private function actionAnalyze(ExperimentAnalysisEngine $engine, string $experimentId, bool $asJson): int
    {
        $cached = $engine->getCachedAnalysis($experimentId);

        if ($cached !== null) {
            $this->displayResult($cached, $asJson, 'Cached analysis');

            return self::SUCCESS;
        }

        $this->error("No analysis found for experiment: {$experimentId}");
        $this->line('Run analysis via API first: POST /api/analytics/experiments/analyze');

        return self::FAILURE;
    }

    /**
     * Assess experiment data health.
     */
    private function actionHealth(ExperimentAnalysisEngine $engine, bool $asJson): int
    {
        $this->info('📊 Experiment Health Check');
        $this->newLine();

        // Demo data for health check
        $variants = $this->promptVariants();

        $result = $engine->assessExperimentHealth($variants);

        if ($asJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        // Display checks
        foreach ($result['checks'] as $check) {
            $icon = match ($check['status']) {
                'pass' => '✅',
                'warn' => '⚠️',
                'fail' => '❌',
            };

            $this->line("  {$icon} <comment>{$check['name']}</comment>: {$check['message']}");
        }

        $this->newLine();
        $this->line("  Overall: " . ($result['healthy'] ? '<info>✅ Healthy</info>' : '<error>❌ Unhealthy</error>'));

        $summary = $result['summary'];
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Exposures', $summary['total_exposures']],
                ['Total Conversions', $summary['total_conversions']],
                ['Variants', $summary['num_variants']],
                ['Overall Rate', $summary['overall_rate']],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * Calculate required sample size.
     */
    private function actionSampleSize(ExperimentAnalysisEngine $engine, bool $asJson): int
    {
        $baseline = (float) ($this->option('baseline') ?? $this->ask('Baseline conversion rate (e.g., 0.05)', '0.05'));
        $mde = (float) ($this->option('mde') ?? $this->ask('Minimum Detectable Effect — relative (e.g., 0.10 for 10%)', '0.10'));
        $power = (float) $this->option('power');
        $alpha = (float) $this->option('alpha');
        $numVariants = (int) $this->ask('Number of variants (including control)', '2');

        $result = $engine->calculateSampleSize($baseline, $mde, $alpha, $power, $numVariants);

        if ($asJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('📐 Sample Size Calculator');
        $this->newLine();
        $this->table(
            ['Parameter', 'Value'],
            [
                ['Baseline Rate', $baseline],
                ['MDE (Relative)', $mde],
                ['MDE (Absolute)', $result['mde_absolute']],
                ['Power', $result['power']],
                ['Alpha', $result['alpha']],
                ['Variants', $result['num_variants']],
                ['Correction', $result['correction']],
            ],
        );

        $this->newLine();
        $this->info('Required Sample Size:');
        $this->table(
            ['Group', 'Sample Size'],
            [
                ['Total', number_format($result['total_sample_size'])],
                ['Per Variant', number_format($result['per_variant'])],
                ['Control', number_format($result['control'])],
                ['Treatment', number_format($result['treatment'])],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * Calculate MDE for a given sample size.
     */
    private function actionMDE(ExperimentAnalysisEngine $engine, bool $asJson): int
    {
        $baseline = (float) ($this->option('baseline') ?? $this->ask('Baseline conversion rate', '0.05'));
        $sampleSize = (int) ($this->option('sample-size') ?? $this->ask('Sample size per variant', '1000'));
        $power = (float) $this->option('power');
        $alpha = (float) $this->option('alpha');

        $result = $engine->calculateMDE($baseline, $sampleSize, $alpha, $power);

        if ($asJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('🎯 MDE Calculator');
        $this->newLine();
        $this->table(
            ['Parameter', 'Value'],
            [
                ['Baseline Rate', $baseline],
                ['Sample Size', number_format($sampleSize)],
                ['MDE (Relative)', $result['mde_relative']],
                ['MDE (Absolute)', $result['mde_absolute']],
                ['Treatment Rate', $result['treatment_rate']],
                ['Detectable Uplift', $result['detectable_uplift_pct'] . '%'],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * Run sequential test check.
     */
    private function actionSequential(ExperimentAnalysisEngine $engine, bool $asJson): int
    {
        $peek = (int) ($this->option('peek') ?? $this->ask('Current peek number', '1'));
        $maxPeeks = (int) $this->option('max-peeks');
        $zScore = (float) ($this->option('z-score') ?? $this->ask('Current z-score', '0.0'));

        $result = $engine->sequentialTest(
            experimentId: $this->option('experiment') ?? 'manual',
            peek: $peek,
            maxPeeks: $maxPeeks,
            zScore: $zScore,
        );

        if ($asJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $icon = $result['should_stop'] ? '🛑' : '▶️';
        $this->info("{$icon} Sequential Test — Peek {$result['peek']}/{$result['max_peeks']}");
        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Z-Score', $result['z_score']],
                ['Boundary', $result['boundary']],
                ['Alpha Spent', $result['alpha_spent']],
                ['Alpha Remaining', $result['alpha_remaining']],
                ['Info Fraction', $result['info_fraction']],
                ['Decision', $result['should_stop'] ? '<error>STOP</error>' : '<info>CONTINUE</info>'],
            ],
        );

        $this->newLine();
        $this->line("  {$result['recommendation']}");

        return self::SUCCESS;
    }

    /**
     * Quick significance test.
     */
    private function actionQuick(ExperimentAnalysisEngine $engine, bool $asJson): int
    {
        $cConv = (int) $this->ask('Control conversions', '100');
        $cExp = (int) $this->ask('Control exposures', '2000');
        $tConv = (int) $this->ask('Treatment conversions', '120');
        $tExp = (int) $this->ask('Treatment exposures', '2000');

        $result = $engine->quickSignificance($cConv, $cExp, $tConv, $tExp);

        if ($asJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $icon = $result['significant'] ? '✅' : '⚠️';
        $this->info("{$icon} Quick Significance Test");
        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['P-Value', $result['p_value']],
                ['Significant', $result['significant'] ? '<info>Yes</info>' : '<comment>No</comment>'],
                ['Confidence Level', ($result['confidence_level'] * 100) . '%'],
                ['Relative Uplift', (($result['relative_uplift'] ?? 0) * 100) . '%'],
                ['Test Used', $result['test_used']],
            ],
        );

        $this->newLine();
        $this->line("  {$result['recommendation']}");

        return self::SUCCESS;
    }

    /**
     * Prompt user for variant data.
     *
     * @return array<string, array{exposures: int, conversions: int}>
     */
    private function promptVariants(): array
    {
        $variants = [];
        $count = (int) $this->ask('Number of variants', '2');

        for ($i = 0; $i < $count; $i++) {
            $name = $i === 0 ? 'control' : $this->ask("Variant {$i} name", "variant_{$i}");
            $exposures = (int) $this->ask("{$name} exposures", '1000');
            $conversions = (int) $this->ask("{$name} conversions", '50');

            $variants[$name] = [
                'exposures' => $exposures,
                'conversions' => $conversions,
            ];
        }

        return $variants;
    }

    /**
     * Display a result array, optionally as JSON.
     */
    private function displayResult(array $result, bool $asJson, string $title): void
    {
        if ($asJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return;
        }

        $this->info("📊 {$title}");
        $this->newLine();
        $this->line("  Experiment: {$result['experiment_id']}");
        $this->line("  Winner: " . ($result['winner'] ?? 'None'));
        $this->line("  Recommendation: {$result['recommendation']}");

        if (isset($result['frequentist']) && $result['frequentist'] !== null) {
            $f = $result['frequentist'];
            $this->newLine();
            $this->line('  <comment>Frequentist:</comment>');
            $this->line("    P-Value: " . ($f['p_value'] ?? 'N/A'));
            $this->line("    Significant: " . (($f['significant'] ?? false) ? 'Yes' : 'No'));
            $this->line("    Test: " . ($f['test_used'] ?? 'N/A'));
        }

        if (isset($result['bayesian']) && $result['bayesian'] !== null) {
            $b = $result['bayesian'];
            $this->newLine();
            $this->line('  <comment>Bayesian:</comment>');
            foreach ($b['prob_best'] as $id => $prob) {
                $bar = str_repeat('█', (int) ($prob * 30));
                $this->line("    P(Best) {$id}: {$prob} {$bar}");
            }
        }
    }
}
