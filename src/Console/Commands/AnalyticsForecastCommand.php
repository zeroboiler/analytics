<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Services\ChurnPredictionService;
use ZeroBoiler\Analytics\Services\RevenueForecastService;

/**
 * CLI command for revenue forecasting and churn prediction.
 *
 * Provides interactive and scriptable access to MRR forecasting,
 * LTV calculation, churn risk scoring, cohort retention analysis,
 * and runway estimation.
 *
 * @since 81.0.0
 */
final class AnalyticsForecastCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:forecast
        {action : Forecast action (mrr|ltv|runway|churn-score|churn-cohort|churn-weights|churn-thresholds|mrr-movement|cohort-retention)}
        {--user= : User ID for churn scoring}
        {--users=* : Multiple user IDs for batch churn scoring}
        {--arpu= : Average Revenue Per User (for LTV)}
        {--churn-rate= : Monthly churn rate (for LTV)}
        {--margin= : Gross margin 0-1 (for LTV)}
        {--months=6 : Forecast horizon in months}
        {--current-mrr= : Current MRR for forecasting}
        {--growth-rate= : Monthly growth rate for MRR forecast}
        {--json : Output as JSON}
        {--days-back=90 : Days of historical data to consider}
        {--threshold= : Custom churn risk threshold (0-100)}';

    /** @var string */
    protected $description = 'Revenue forecasting, churn prediction, and SaaS financial metrics';

    /**
     * Execute the console command.
     */
    public function handle(
        ConfigRepository $config,
        CacheRepository $cache,
    ): int {
        $action = $this->argument('action');

        try {
            $result = match ($action) {
                'mrr' => $this->forecastMrr($config, $cache),
                'ltv' => $this->calculateLtv($config),
                'runway' => $this->estimateRunway($config),
                'churn-score' => $this->scoreChurn($config, $cache),
                'churn-cohort' => $this->churnCohortSummary($config, $cache),
                'churn-weights' => $this->churnWeights($config),
                'churn-thresholds' => $this->churnThresholds($config),
                'mrr-movement' => $this->mrrMovement($config, $cache),
                'cohort-retention' => $this->cohortRetention($config, $cache),
                default => throw new \InvalidArgumentException("Unknown action: {$action}. Valid actions: mrr, ltv, runway, churn-score, churn-cohort, churn-weights, churn-thresholds, mrr-movement, cohort-retention"),
            };

            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return;
            }

            $this->displayResult($action, $result);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        } catch (\Throwable $e) {
            $this->error("Forecast error: {$e->getMessage()}");

            return 1;
        }
    }

    /**
     * Forecast MRR over a given horizon.
     *
     * @return array<string, mixed>
     */
    private function forecastMrr(ConfigRepository $config, CacheRepository $cache): array
    {
        $currentMrr = (float) ($this->option('current-mrr') ?? $this->ask('Current MRR ($)', '10000'));
        $growthRate = (float) ($this->option('growth-rate') ?? $this->ask('Monthly growth rate (decimal, e.g. 0.05)', '0.05'));
        $months = (int) $this->option('months');

        $service = new RevenueForecastService($config);

        return $service->forecastMrr(
            currentMrr: $currentMrr,
            monthlyGrowthRate: $growthRate,
            months: $months,
        );
    }

    /**
     * Calculate LTV from ARPU, churn rate, and margin.
     *
     * @return array<string, mixed>
     */
    private function calculateLtv(ConfigRepository $config): array
    {
        $arpu = (float) ($this->option('arpu') ?? $this->ask('ARPU ($)', '99'));
        $churnRate = (float) ($this->option('churn-rate') ?? $this->ask('Monthly churn rate (decimal, e.g. 0.03)', '0.03'));
        $margin = (float) ($this->option('margin') ?? $this->ask('Gross margin (decimal, e.g. 0.75)', '0.75'));

        $service = new RevenueForecastService($config);

        return $service->calculateLtv(
            arpu: $arpu,
            monthlyChurnRate: $churnRate,
            grossMargin: $margin,
        );
    }

    /**
     * Estimate runway based on current burn and revenue.
     *
     * @return array<string, mixed>
     */
    private function estimateRunway(ConfigRepository $config): array
    {
        $service = new RevenueForecastService($config);

        $currentMrr = (float) ($this->option('current-mrr') ?? $this->ask('Current MRR ($)', '10000'));
        $monthlyBurn = (float) $this->ask('Monthly burn rate ($)', '50000');
        $growthRate = (float) ($this->option('growth-rate') ?? $this->ask('Monthly MRR growth rate', '0.05'));

        return $service->estimateRunway(
            currentMrr: $currentMrr,
            monthlyBurn: $monthlyBurn,
            monthlyGrowthRate: $growthRate,
        );
    }

    /**
     * Score a user's churn risk.
     *
     * @return array<string, mixed>
     */
    private function scoreChurn(ConfigRepository $config, CacheRepository $cache): array
    {
        $userId = $this->option('user');

        if ($userId === null) {
            $userId = $this->ask('User ID');
        }

        $service = new ChurnPredictionService($config);

        return $service->scoreUser($userId);
    }

    /**
     * Show churn cohort summary.
     *
     * @return array<string, mixed>
     */
    private function churnCohortSummary(ConfigRepository $config, CacheRepository $cache): array
    {
        $service = new ChurnPredictionService($config);
        $daysBack = (int) $this->option('days-back');

        return $service->cohortSummary($daysBack);
    }

    /**
     * Show churn signal weights.
     *
     * @return array<string, mixed>
     */
    private function churnWeights(ConfigRepository $config): array
    {
        $service = new ChurnPredictionService($config);

        return $service->getSignalWeights();
    }

    /**
     * Show churn thresholds.
     *
     * @return array<string, mixed>
     */
    private function churnThresholds(ConfigRepository $config): array
    {
        $forecastConfig = $config->get('zeroboiler.analytics.churn_prediction', []);
        $churnConfig = $config->get('zeroboiler.analytics.forecasting', []);

        return [
            'high_risk' => (int) ($forecastConfig['high_risk_threshold'] ?? 60),
            'medium_risk' => (int) ($forecastConfig['medium_risk_threshold'] ?? 30),
            'warning_threshold' => (float) ($churnConfig['churn_warning'] ?? 0.05),
        ];
    }

    /**
     * Show MRR movement breakdown.
     *
     * @return array<string, mixed>
     */
    private function mrrMovement(ConfigRepository $config, CacheRepository $cache): array
    {
        $service = new RevenueForecastService($config);

        return $service->mrrMovementBreakdown();
    }

    /**
     * Show cohort retention curve.
     *
     * @return array<string, mixed>
     */
    private function cohortRetention(ConfigRepository $config, CacheRepository $cache): array
    {
        $service = new RevenueForecastService($config);
        $daysBack = (int) $this->option('days-back');

        return $service->cohortRetentionCurve($daysBack);
    }

    /**
     * Display a result table or key-value output.
     *
     * @param  array<string, mixed>  $result
     */
    private function displayResult(string $action, array $result): void
    {
        match ($action) {
            'mrr' => $this->displayMrrForecast($result),
            'ltv' => $this->displayLtv($result),
            'runway' => $this->displayRunway($result),
            'churn-score' => $this->displayChurnScore($result),
            'churn-cohort' => $this->displayCohortSummary($result),
            'churn-weights' => $this->displayWeights($result),
            'churn-thresholds' => $this->displayThresholds($result),
            'mrr-movement' => $this->displayMovement($result),
            'cohort-retention' => $this->displayRetentionCurve($result),
        };
    }

    /**
     * Display MRR forecast as table.
     *
     * @param  array<string, mixed>  $result
     */
    private function displayMrrForecast(array $result): void
    {
        $this->info('MRR Forecast');
        $this->table(
            ['Month', 'MRR ($)', 'Growth ($)'],
            array_map(fn (array $m): array => [
                $m['month'],
                number_format($m['mrr'], 2),
                number_format($m['growth'], 2),
            ], $result['projections'] ?? []),
        );

        $this->newLine();
        $this->info("Final MRR: \${$result['final_mrr']}");
        $this->info("Total Growth: \${$result['total_growth']}");
    }

    /**
     * Display LTV calculation.
     *
     * @param  array<string, mixed>  $result
     */
    private function displayLtv(array $result): void
    {
        $this->info('Lifetime Value (LTV) Calculation');
        $this->table(
            ['Metric', 'Value'],
            [
                ['ARPU (Monthly)', '$' . number_format($result['arpu'], 2)],
                ['LTV', '$' . number_format($result['ltv'], 2)],
                ['LTV (Months)', $result['ltv_months']],
                ['Annual ARPU', '$' . number_format($result['arpu_annual'], 2)],
                ['Churn Multiplier', $result['churn_multiplier']],
            ],
        );
    }

    /**
     * Display runway estimate.
     *
     * @param  array<string, mixed>  $result
     */
    private function displayRunway(array $result): void
    {
        $this->info('Runway Estimate');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Current MRR', '$' . number_format($result['current_mrr'], 2)],
                ['Monthly Burn', '$' . number_format($result['monthly_burn'], 2)],
                ['Net Burn', '$' . number_format($result['net_burn'], 2)],
                ['Runway (Months)', $result['runway_months']],
                ['Break-even MRR', '$' . number_format($result['break_even_mrr'], 2)],
            ],
        );
    }

    /**
     * Display churn score.
     *
     * @param  array<string, mixed>  $result
     */
    private function displayChurnScore(array $result): void
    {
        $score = $result['risk_score'] ?? 0;
        $level = $result['risk_level'] ?? 'unknown';
        $color = match ($level) {
            'low' => 'info',
            'medium' => 'comment',
            'high' => 'warn',
            'critical' => 'error',
            default => 'line',
        };

        $this->{$color}("Churn Risk: {$level} (score: {$score}/100)");

        if (! empty($result['signals'])) {
            $this->table(
                ['Signal', 'Value', 'Contribution'],
                array_map(fn (array $s): array => [
                    $s['signal'],
                    $s['value'] ?? 'N/A',
                    ($s['contribution'] ?? 0) . '%',
                ], $result['signals']),
            );
        }
    }

    /**
     * Display cohort churn summary.
     *
     * @param  array<string, mixed>  $result
     */
    private function displayCohortSummary(array $result): void
    {
        $this->info('Churn Cohort Summary');
        $this->table(
            ['Cohort', 'Total Users', 'Churned', 'Churn Rate'],
            array_map(fn (array $c): array => [
                $c['cohort'],
                $c['total'],
                $c['churned'],
                number_format($c['churn_rate'] * 100, 1) . '%',
            ], $result['cohorts'] ?? []),
        );
    }

    /**
     * Display signal weights.
     *
     * @param  array<string, mixed>  $result
     */
    private function displayWeights(array $result): void
    {
        $this->info('Churn Signal Weights');
        $this->table(
            ['Signal', 'Weight'],
            array_map(fn (string $signal, float $weight): array => [$signal, $weight], array_keys($result), array_values($result)),
        );
    }

    /**
     * Display thresholds.
     *
     * @param  array<string, mixed>  $result
     */
    private function displayThresholds(array $result): void
    {
        $this->info('Churn Thresholds');
        $this->table(
            ['Level', 'Threshold'],
            [
                ['High Risk', $result['high_risk'] . '/100'],
                ['Medium Risk', $result['medium_risk'] . '/100'],
                ['Warning Churn Rate', number_format($result['warning_threshold'] * 100, 1) . '%'],
            ],
        );
    }

    /**
     * Display MRR movement breakdown.
     *
     * @param  array<string, mixed>  $result
     */
    private function displayMovement(array $result): void
    {
        $this->info('MRR Movement Breakdown');
        $this->table(
            ['Category', 'Amount ($)'],
            array_map(fn (string $k, float $v): array => [$k, number_format($v, 2)], array_keys($result), array_values($result)),
        );
    }

    /**
     * Display cohort retention curve.
     *
     * @param  array<string, mixed>  $result
     */
    private function displayRetentionCurve(array $result): void
    {
        $this->info('Cohort Retention Curve');
        $this->table(
            ['Cohort', 'Period 1', 'Period 2', 'Period 3', 'Period 4', 'Period 5'],
            array_map(fn (array $c): array => [
                $c['cohort'],
                ...array_map(fn (float $r): string => number_format($r * 100, 1) . '%', $c['retention'] ?? []),
            ], $result['cohorts'] ?? []),
        );
    }
}
