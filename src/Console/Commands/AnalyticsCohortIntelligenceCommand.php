<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\CohortBehaviorProfilerService;
use ZeroBoiler\Analytics\Services\EventPredictiveScoringService;

/**
 * Analytics Cohort Intelligence command.
 *
 * Provides cohort profiling, predictive scoring, and behavioral analysis
 * for SaaS user segments. Useful for product teams monitoring user health,
 * identifying at-risk users, and tracking cohort transitions.
 *
 * @since 8.1.0
 */
final class AnalyticsCohortIntelligenceCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:cohort-intelligence
        {action? : Action to perform (profile|predict|distribution|insights|summary)}
        {--json : Output as JSON}
        {--cohort= : Target cohort name for insights}
        {--target= : Target cohort for transition prediction}
        {--threshold=0.6 : Probability threshold for predictions}
        {--limit=10 : Max results for ranked lists}';

    /** @var string */
    protected $description = 'Cohort intelligence — behavioral profiling, predictive scoring, and cohort analysis';

    /**
     * Execute the console command.
     */
    #[Override]
    public function handle(): int
    {
        $action = $this->argument('action') ?? 'summary';

        return match ($action) {
            'profile' => $this->actionProfile(),
            'predict' => $this->actionPredict(),
            'distribution' => $this->actionDistribution(),
            'insights' => $this->actionInsights(),
            'summary' => $this->actionSummary(),
            default => $this->invalidAction($action),
        };
    }

    /**
     * Display cohort profiling overview.
     */
    private function actionProfile(): int
    {
        $profiler = $this->app->make(CohortBehaviorProfilerService::class);
        $definitions = $profiler->getCohortDefinitions();

        $this->info('📊 Behavioral Cohort Definitions');
        $this->newLine();

        $rows = [];
        foreach ($definitions as $name => $def) {
            $thresholds = $def['thresholds'] ?? [];
            $signals = $def['signals'] ?? [];
            $rows[] = [
                $name,
                $def['label'] ?? $name,
                implode(', ', array_slice($signals, 0, 3)) . (count($signals) > 3 ? '...' : ''),
                count($thresholds),
            ];
        }

        $this->table(
            ['Cohort', 'Label', 'Key Signals', 'Thresholds'],
            $rows,
        );

        $this->info(sprintf('Total cohorts: %d', count($definitions)));
        $this->info('Usage: profile users via EventPredictiveScoringService or API endpoints.');

        return self::SUCCESS;
    }

    /**
     * Display predictive scoring configuration.
     */
    private function actionPredict(): int
    {
        $scoring = $this->app->make(EventPredictiveScoringService::class);

        $this->info('🎯 Predictive Scoring Configuration');
        $this->newLine();

        $this->info('Conversion Signals (weights):');
        $this->outputSignalWeights(EventPredictiveScoringService::class, 'CONVERSION_SIGNALS');

        $this->newLine();
        $this->info('Churn Risk Signals (weights):');
        $this->outputSignalWeights(EventPredictiveScoringService::class, 'CHURN_SIGNALS');

        $this->newLine();
        $this->info('Expansion Signals (weights):');
        $this->outputSignalWeights(EventPredictiveScoringService::class, 'EXPANSION_SIGNALS');

        $this->newLine();
        $this->info('Health Grades: A+ (95+), A (85+), B (70+), C (50+), D (30+), F (<30)');

        return self::SUCCESS;
    }

    /**
     * Output signal weights for a constant.
     */
    private function outputSignalWeights(string $class, string $constant): void
    {
        $reflection = new \ReflectionClass($class);
        $signals = $reflection->getConstant($constant);

        if ($signals === false) {
            return;
        }

        $rows = [];
        foreach ($signals as $name => $config) {
            $rows[] = [
                $name,
                number_format($config['weight'] * 100, 1) . '%',
                $config['description'],
            ];
        }

        $this->table(['Signal', 'Weight', 'Description'], $rows);
    }

    /**
     * Display distribution info.
     */
    private function actionDistribution(): int
    {
        $profiler = $this->app->make(CohortBehaviorProfilerService::class);
        $definitions = $profiler->getCohortDefinitions();

        $this->info('📈 Cohort Distribution Analysis');
        $this->newLine();
        $this->info('Pass user events via the distribution() method or API endpoint:');
        $this->info('  POST /api/analytics/cohort-intelligence/distribution');
        $this->newLine();
        $this->info('Available cohorts:');

        foreach ($definitions as $name => $def) {
            $this->line("  • {$name}: {$def['label']}");
        }

        return self::SUCCESS;
    }

    /**
     * Display cohort insights info.
     */
    private function actionInsights(): int
    {
        $cohort = $this->option('cohort');

        $this->info('🔍 Cohort Insights: ' . (cohort ?? 'all'));
        $this->newLine();
        $this->info('Pass user events via the cohortInsights() method or API endpoint:');
        $this->info('  GET /api/analytics/cohort-intelligence/insights/{cohort}');

        if ($cohort) {
            $profiler = $this->app->make(CohortBehaviorProfilerService::class);
            $definitions = $profiler->getCohortDefinitions();

            if (isset($definitions[$cohort])) {
                $def = $definitions[$cohort];
                $this->newLine();
                $this->info("Label: {$def['label']}");
                $this->info("Description: {$def['description']}");
                $this->info("Signals: " . implode(', ', $def['signals'] ?? []));
            } else {
                $this->warn("Unknown cohort: {$cohort}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Display summary overview.
     */
    private function actionSummary(): int
    {
        $this->info('🧠 Cohort Intelligence — Overview');
        $this->newLine();

        $this->info('Services:');
        $this->line('  • CohortBehaviorProfilerService — Behavioral cohort classification');
        $this->line('  • EventPredictiveScoringService — Conversion, churn, expansion scoring');
        $this->newLine();

        $this->info('API Endpoints:');
        $this->line('  POST /api/analytics/cohort-intelligence/profile     — Profile a user');
        $this->line('  POST /api/analytics/cohort-intelligence/profile/batch — Batch profile');
        $this->line('  POST /api/analytics/cohort-intelligence/distribution — Cohort distribution');
        $this->line('  POST /api/analytics/cohort-intelligence/transitions — Transition matrix');
        $this->line('  POST /api/analytics/cohort-intelligence/predict      — Predict transitions');
        $this->line('  POST /api/analytics/cohort-intelligence/score       — Predictive scoring');
        $this->line('  POST /api/analytics/cohort-intelligence/score/batch  — Batch scoring');
        $this->line('  POST /api/analytics/cohort-intelligence/summary     — Scoring summary');
        $this->line('  GET  /api/analytics/cohort-intelligence/churn-top    — Top churn risks');
        $this->line('  GET  /api/analytics/cohort-intelligence/expansion-top — Top expansion candidates');
        $this->line('  GET  /api/analytics/cohort-intelligence/insights/{cohort} — Cohort insights');
        $this->newLine();

        $this->info('Actions: profile, predict, distribution, insights, summary');

        return self::SUCCESS;
    }

    /**
     * Handle invalid action.
     */
    private function invalidAction(string $action): int
    {
        $this->error("Invalid action: {$action}");
        $this->info('Available actions: profile, predict, distribution, insights, summary');

        return self::FAILURE;
    }
}
