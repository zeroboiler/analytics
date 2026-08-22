<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\RevenueWaterfallService;
use ZeroBoiler\Analytics\Services\RetentionCalculator;
use ZeroBoiler\Analytics\Services\RevenueForecastService;
use ZeroBoiler\Analytics\Services\SaaSRevenueFunnelService;
use ZeroBoiler\Analytics\Services\SaaSMomentumService;
/**
 * SaaS Revenue Intelligence — unified revenue metrics dashboard.
 *
 * Aggregates all SaaS revenue-critical KPIs into a single command:
 *
 * - MRR / ARR / MRR movement (new, expansion, contraction, churn, reactivation)
 * - Churn rate (gross, net, logo churn)
 * - LTV / CAC ratio and payback period
 * - Net Revenue Retention (NRR)
 * - Quick Ratio
 * - Rule of 40 (growth rate + profit margin)
 * - Revenue forecast (linear projection)
 * - Cohort retention table
 * - Revenue funnel metrics
 * - SaaS momentum score
 *
 * Inspired by ChartMogul, ProfitWell, Baremetrics, and Lattice.
 *
 * @since 226.0.0
 */
final class SaaSRevenueIntelligenceCommand extends Command
{
    protected $signature = 'zb:analytics:revenue:intelligence
        {--period=30 : Analysis period in days (7, 14, 30, 90, 365)}
        {--json : Output as JSON}
        {--mrr : Show MRR breakdown only}
        {--waterfall : Show MRR waterfall only}
        {--retention : Show cohort retention only}
        {--forecast : Show revenue forecast only}
        {--funnel : Show SaaS revenue funnel only}
        {--churn : Show churn analysis only}
        {--momentum : Show SaaS momentum score only}
        {--kpi : Show KPI summary only}
        {--benchmarks : Compare against industry benchmarks}';

    protected $description = 'SaaS Revenue Intelligence — unified revenue metrics dashboard';

    private AnalyticsManager $manager;

    private ConfigRepository $config;

    public function __construct(AnalyticsManager $manager, ConfigRepository $config){
        parent::__construct();
        $this->manager = $manager;
        $this->config = $config;
    }

    public function handle(): int
    {
        $outputJson = (bool) $this->option('json');
        $period = (int) $this->option('period');

        if ($outputJson) {
            $this->line(json_encode($this->buildFullReport($period), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->renderHeader();

        // Single-focus modes
        if ((bool) $this->option('mrr')) {
            $this->renderMrrBreakdown($period);

            return self::SUCCESS;
        }

        if ((bool) $this->option('waterfall')) {
            $this->renderWaterfall($period);

            return self::SUCCESS;
        }

        if ((bool) $this->option('retention')) {
            $this->renderRetention($period);

            return self::SUCCESS;
        }

        if ((bool) $this->option('forecast')) {
            $this->renderForecast($period);

            return self::SUCCESS;
        }

        if ((bool) $this->option('funnel')) {
            $this->renderFunnel($period);

            return self::SUCCESS;
        }

        if ((bool) $this->option('churn')) {
            $this->renderChurnAnalysis($period);

            return self::SUCCESS;
        }

        if ((bool) $this->option('momentum')) {
            $this->renderMomentum($period);

            return self::SUCCESS;
        }

        if ((bool) $this->option('kpi')) {
            $this->renderKpiSummary($period);

            return self::SUCCESS;
        }

        // Full dashboard
        $this->renderKpiSummary($period);
        $this->newLine();
        $this->renderMrrBreakdown($period);
        $this->newLine();
        $this->renderWaterfall($period);
        $this->newLine();
        $this->renderChurnAnalysis($period);
        $this->newLine();
        $this->renderMomentum($period);

        if ((bool) $this->option('benchmarks')) {
            $this->newLine();
            $this->renderBenchmarks($period);
        }

        $this->newLine();
        $this->renderFooter();

        return self::SUCCESS;
    }

    /**
     * Build the full report data structure.
     *
     * @return array<string, mixed>
     */
    private function buildFullReport(int $period): array
    {
        return [
            'version' => AnalyticsEvent::VERSION,
            'period_days' => $period,
            'generated_at' => now()->toIso8601String(),
            'kpi' => $this->getKpiData($period),
            'mrr' => $this->getMrrData($period),
            'waterfall' => $this->getWaterfallData($period),
            'churn' => $this->getChurnData($period),
            'retention' => $this->getRetentionData($period),
            'forecast' => $this->getForecastData($period),
            'funnel' => $this->getFunnelData($period),
            'momentum' => $this->getMomentumData($period),
            'benchmarks' => $this->getBenchmarksData($period),
        ];
    }

    /**
     * Get KPI summary data.
     *
     * @return array<string, mixed>
     */
    private function getKpiData(int $period): array
    {
        try {
            $calculator = new SaasKpiCalculatorService($this->config);
            $kpi = $calculator->calculate($period);

            return $kpi;
        } catch (\Throwable $e) {
            return $this->emptyKpiResponse($period);
        }
    }

    /**
     * Get MRR breakdown data.
     *
     * @return array<string, mixed>
     */
    private function getMrrData(int $period): array
    {
        try {
            $service = new RevenueWaterfallService($this->config);
            $mrr = $service->mrrMovement($period);

            return $mrr;
        } catch (\Throwable $e) {
            return ['mrr' => 0, 'arr' => 0, 'new_mrr' => 0, 'expansion_mrr' => 0, 'contraction_mrr' => 0, 'churned_mrr' => 0, 'reactivation_mrr' => 0, 'net_mrr_change' => 0, 'nrr' => 0, 'currency' => $this->getCurrency()];
        }
    }

    /**
     * Get MRR waterfall data.
     *
     * @return array<string, mixed>
     */
    private function getWaterfallData(int $period): array
    {
        try {
            $service = new RevenueWaterfallService($this->config);

            return $service->waterfall($period);
        } catch (\Throwable $e) {
            return ['movements' => [], 'period' => $period, 'currency' => $this->getCurrency()];
        }
    }

    /**
     * Get churn analysis data.
     *
     * @return array<string, mixed>
     */
    private function getChurnData(int $period): array
    {
        try {
            $calculator = new SaasKpiCalculatorService($this->config);
            $kpi = $calculator->calculate($period);

            return [
                'gross_churn_rate' => $kpi['gross_churn_rate'] ?? 0,
                'net_churn_rate' => $kpi['net_churn_rate'] ?? 0,
                'logo_churn_rate' => $kpi['logo_churn_rate'] ?? 0,
                'churned_mrr' => $kpi['churned_mrr'] ?? 0,
                'churned_customers' => $kpi['churned_customers'] ?? 0,
                'avg_revenue_churned' => $kpi['avg_revenue_churned'] ?? 0,
                'churn_prediction_enabled' => $this->config->get('zeroboiler.analytics.churn_prediction.enabled', false),
            ];
        } catch (\Throwable $e) {
            return ['gross_churn_rate' => 0, 'net_churn_rate' => 0, 'logo_churn_rate' => 0, 'churned_mrr' => 0, 'churned_customers' => 0, 'avg_revenue_churned' => 0, 'churn_prediction_enabled' => false];
        }
    }

    /**
     * Get retention data.
     *
     * @return array<string, mixed>
     */
    private function getRetentionData(int $period): array
    {
        try {
            $service = new RetentionCalculator($this->config);

            return $service->retentionCurve($period);
        } catch (\Throwable $e) {
            return ['intervals' => [], 'period' => $period];
        }
    }

    /**
     * Get revenue forecast data.
     *
     * @return array<string, mixed>
     */
    private function getForecastData(int $period): array
    {
        try {
            $service = new RevenueForecastService($this->config);

            return $service->forecast($period, 3);
        } catch (\Throwable $e) {
            return ['projections' => [], 'current_mrr' => 0, 'growth_rate' => 0];
        }
    }

    /**
     * Get SaaS revenue funnel data.
     *
     * @return array<string, mixed>
     */
    private function getFunnelData(int $period): array
    {
        try {
            $service = new SaaSRevenueFunnelService($this->config);

            return $service->metrics($period);
        } catch (\Throwable $e) {
            return ['stages' => [], 'conversion_rate' => 0, 'avg_time_to_convert' => 0];
        }
    }

    /**
     * Get SaaS momentum data.
     *
     * @return array<string, mixed>
     */
    private function getMomentumData(int $period): array
    {
        try {
            $service = new SaaSMomentumService($this->config);

            return $service->score($period);
        } catch (\Throwable $e) {
            return ['score' => 0, 'grade' => 'N/A', 'components' => []];
        }
    }

    /**
     * Get industry benchmark data.
     *
     * @return array<string, mixed>
     */
    private function getBenchmarksData(int $period): array
    {
        try {
            $service = new \ZeroBoiler\Analytics\Services\SaasMetricsBenchmarkService($this->config);

            return $service->compare($period);
        } catch (\Throwable $e) {
            return ['benchmarks' => [], 'period' => $period];
        }
    }

    /**
     * Render the command header.
     */
    private function renderHeader(): void
    {
        $period = (int) $this->option('period');

        $this->info('╔══════════════════════════════════════════════════════════════════╗');
        $this->info('║         📊 SaaS Revenue Intelligence Dashboard                ║');
        $this->info('╠══════════════════════════════════════════════════════════════════╣');
        $this->line(sprintf('║  Version: %-55s ║', AnalyticsEvent::VERSION));
        $this->line(sprintf('║  Period:  %-54s ║', $period . ' days'));
        $this->line(sprintf('║  Currency: %-53s ║', $this->getCurrency()));
        $this->info('╠══════════════════════════════════════════════════════════════════╣');
    }

    /**
     * Render the footer.
     */
    private function renderFooter(): void
    {
        $this->info('╚══════════════════════════════════════════════════════════════════╝');
        $this->comment('  ZeroBoiler Analytics v' . AnalyticsEvent::VERSION);
        $this->comment('  Use --json, --mrr, --waterfall, --retention, --forecast, --funnel, --churn, --momentum, --kpi for focused views.');
    }

    /**
     * Render KPI summary.
     */
    private function renderKpiSummary(int $period): void
    {
        $kpi = $this->getKpiData($period);
        $currency = $this->getCurrency();

        $this->info('  📈 KPI Summary');
        $this->line('  ──────────────────────────────────────────────────');

        $mrr = $kpi['mrr'] ?? 0;
        $arr = $mrr * 12;
        $nrr = $kpi['net_revenue_retention'] ?? 0;
        $ltv = $kpi['ltv'] ?? 0;
        $cac = $kpi['cac'] ?? 0;
        $ltvCac = $cac > 0 ? round($ltv / $cac, 2) : 0;
        $grossChurn = $kpi['gross_churn_rate'] ?? 0;
        $quickRatio = $kpi['quick_ratio'] ?? 0;
        $ruleOf40 = $kpi['rule_of_40'] ?? 0;
        $growthRate = $kpi['growth_rate'] ?? 0;
        $trialConversion = $kpi['trial_conversion_rate'] ?? 0;
        $activationRate = $kpi['activation_rate'] ?? 0;

        $rows = [
            ['MRR', $this->formatCurrency($mrr, $currency)],
            ['ARR', $this->formatCurrency($arr, $currency)],
            ['NRR', $this->formatPercent($nrr)],
            ['Growth Rate', $this->formatPercent($growthRate)],
            ['Gross Churn', $this->formatPercent($grossChurn)],
            ['LTV', $this->formatCurrency($ltv, $currency)],
            ['CAC', $this->formatCurrency($cac, $currency)],
            ['LTV/CAC', $ltvCac > 0 ? (string) $ltvCac . 'x' : 'N/A'],
            ['Quick Ratio', $quickRatio > 0 ? (string) round($quickRatio, 2) : 'N/A'],
            ['Rule of 40', $ruleOf40 !== 0 ? (string) round($ruleOf40, 1) . '%' : 'N/A'],
            ['Trial Conversion', $this->formatPercent($trialConversion)],
            ['Activation Rate', $this->formatPercent($activationRate)],
        ];

        $this->table(
            ['Metric', 'Value'],
            $rows,
        );
    }

    /**
     * Render MRR breakdown.
     */
    private function renderMrrBreakdown(int $period): void
    {
        $mrr = $this->getMrrData($period);
        $currency = $this->getCurrency();

        $this->info('  💰 MRR Breakdown');
        $this->line('  ──────────────────────────────────────────────────');

        $currentMrr = $mrr['mrr'] ?? 0;
        $arr = $currentMrr * 12;
        $newMrr = $mrr['new_mrr'] ?? 0;
        $expansionMrr = $mrr['expansion_mrr'] ?? 0;
        $contractionMrr = $mrr['contraction_mrr'] ?? 0;
        $churnedMrr = $mrr['churned_mrr'] ?? 0;
        $reactivationMrr = $mrr['reactivation_mrr'] ?? 0;
        $netChange = $mrr['net_mrr_change'] ?? 0;
        $nrr = $mrr['nrr'] ?? 0;

        $rows = [
            ['Current MRR', $this->formatCurrency($currentMrr, $currency), $this->mrrBar($currentMrr, $currentMrr)],
            ['Annual Run Rate', $this->formatCurrency($arr, $currency), ''],
            ['New MRR', $this->formatCurrency($newMrr, $currency), $this->mrrBar($newMrr, max($currentMrr, 1), '+')],
            ['Expansion', $this->formatCurrency($expansionMrr, $currency), $this->mrrBar($expansionMrr, max($currentMrr, 1), '+')],
            ['Contraction', $this->formatCurrency($contractionMrr, $currency), $this->mrrBar($contractionMrr, max($currentMrr, 1), '-')],
            ['Churned', $this->formatCurrency($churnedMrr, $currency), $this->mrrBar($churnedMrr, max($currentMrr, 1), '-')],
            ['Reactivation', $this->formatCurrency($reactivationMrr, $currency), $this->mrrBar($reactivationMrr, max($currentMrr, 1), '+')],
            ['Net Change', $this->formatCurrency($netChange, $currency), $netChange >= 0 ? '🟢' : '🔴'],
            ['NRR', $this->formatPercent($nrr), $nrr >= 100 ? '🟢 Healthy' : '🔴 At Risk'],
        ];

        $this->table(
            ['Metric', 'Value', 'Indicator'],
            $rows,
        );
    }

    /**
     * Render MRR waterfall.
     */
    private function renderWaterfall(int $period): void
    {
        $waterfall = $this->getWaterfallData($period);
        $currency = $this->getCurrency();

        $this->info('  🌊 MRR Waterfall');
        $this->line('  ──────────────────────────────────────────────────');

        $movements = $waterfall['movements'] ?? [];

        if ($movements === []) {
            $this->warn('  No MRR movement data available for the selected period.');

            return;
        }

        $rows = [];
        $startingMrr = $waterfall['starting_mrr'] ?? 0;
        $rows[] = ['Starting MRR', $this->formatCurrency($startingMrr, $currency), ''];

        foreach ($movements as $movement) {
            $type = $movement['type'] ?? 'unknown';
            $amount = $movement['amount'] ?? 0;
            $count = $movement['count'] ?? 0;
            $label = ucfirst((string) str_replace('_', ' ', $type));
            $indicator = $amount >= 0 ? '🟢' : '🔴';

            $rows[] = [
                $label,
                $this->formatCurrency($amount, $currency),
                sprintf('%s (%d customers)', $indicator, $count),
            ];
        }

        $endingMrr = $waterfall['ending_mrr'] ?? $startingMrr;
        $rows[] = ['Ending MRR', $this->formatCurrency($endingMrr, $currency), ''];

        $this->table(
            ['Movement', 'Amount', 'Details'],
            $rows,
        );
    }

    /**
     * Render churn analysis.
     */
    private function renderChurnAnalysis(int $period): void
    {
        $churn = $this->getChurnData($period);
        $currency = $this->getCurrency();

        $this->info('  ⚠️  Churn Analysis');
        $this->line('  ──────────────────────────────────────────────────');

        $grossChurn = $churn['gross_churn_rate'] ?? 0;
        $netChurn = $churn['net_churn_rate'] ?? 0;
        $logoChurn = $churn['logo_churn_rate'] ?? 0;
        $churnedMrr = $churn['churned_mrr'] ?? 0;
        $churnedCustomers = $churn['churned_customers'] ?? 0;
        $avgRevenue = $churn['avg_revenue_churned'] ?? 0;

        $healthGrade = $grossChurn <= 0.03 ? '🟢 Excellent' : ($grossChurn <= 0.05 ? '🟡 Good' : ($grossChurn <= 0.08 ? '🟠 Fair' : '🔴 Critical'));

        $rows = [
            ['Gross Churn Rate', $this->formatPercent($grossChurn), $healthGrade],
            ['Net Churn Rate', $this->formatPercent($netChurn), $netChurn <= 0 ? '🟢 Growing' : '🔴 Shrinking'],
            ['Logo Churn Rate', $this->formatPercent($logoChurn), ''],
            ['Churned MRR', $this->formatCurrency($churnedMrr, $currency), ''],
            ['Churned Customers', (string) $churnedCustomers, ''],
            ['Avg Revenue Churned', $this->formatCurrency($avgRevenue, $currency), ''],
        ];

        $this->table(
            ['Metric', 'Value', 'Status'],
            $rows,
        );
    }

    /**
     * Render cohort retention.
     */
    private function renderRetention(int $period): void
    {
        $retention = $this->getRetentionData($period);

        $this->info('  📊 Cohort Retention');
        $this->line('  ──────────────────────────────────────────────────');

        $intervals = $retention['intervals'] ?? [];

        if ($intervals === []) {
            $this->warn('  No retention data available for the selected period.');

            return;
        }

        $rows = [];
        foreach ($intervals as $interval) {
            $label = $interval['interval'] ?? 'unknown';
            $rate = $interval['rate'] ?? 0;
            $count = $interval['retained'] ?? 0;
            $total = $interval['cohort_size'] ?? 0;
            $indicator = $rate >= 0.4 ? '🟢' : ($rate >= 0.2 ? '🟡' : '🔴');

            $rows[] = [
                $label,
                $this->formatPercent($rate),
                sprintf('%s (%d/%d)', $indicator, $count, $total),
            ];
        }

        $this->table(
            ['Interval', 'Retention Rate', 'Users'],
            $rows,
        );
    }

    /**
     * Render revenue forecast.
     */
    private function renderForecast(int $period): void
    {
        $forecast = $this->getForecastData($period);
        $currency = $this->getCurrency();

        $this->info('  🔮 Revenue Forecast');
        $this->line('  ──────────────────────────────────────────────────');

        $currentMrr = $forecast['current_mrr'] ?? 0;
        $growthRate = $forecast['growth_rate'] ?? 0;
        $projections = $forecast['projections'] ?? [];

        $this->line(sprintf('  Current MRR:  %s', $this->formatCurrency($currentMrr, $currency)));
        $this->line(sprintf('  Growth Rate:  %s', $this->formatPercent($growthRate)));
        $this->newLine();

        if ($projections === []) {
            $this->warn('  Insufficient data for projection.');

            return;
        }

        $rows = [];
        foreach ($projections as $projection) {
            $month = $projection['month'] ?? 0;
            $projectedMrr = $projection['mrr'] ?? 0;
            $projectedArr = $projectedMrr * 12;
            $lower = $projection['lower_bound'] ?? $projectedMrr;
            $upper = $projection['upper_bound'] ?? $projectedMrr;

            $rows[] = [
                sprintf('Month +%d', $month),
                $this->formatCurrency($projectedMrr, $currency),
                $this->formatCurrency($projectedArr, $currency),
                sprintf('%s — %s', $this->formatCurrency($lower, $currency), $this->formatCurrency($upper, $currency)),
            ];
        }

        $this->table(
            ['Period', 'Projected MRR', 'Projected ARR', '95% CI'],
            $rows,
        );
    }

    /**
     * Render SaaS revenue funnel.
     */
    private function renderFunnel(int $period): void
    {
        $funnel = $this->getFunnelData($period);

        $this->info('  🔁 SaaS Revenue Funnel');
        $this->line('  ──────────────────────────────────────────────────');

        $stages = $funnel['stages'] ?? [];

        if ($stages === []) {
            $this->warn('  No funnel data available for the selected period.');

            return;
        }

        $rows = [];
        $prevCount = null;
        foreach ($stages as $stage) {
            $name = $stage['name'] ?? 'unknown';
            $count = $stage['count'] ?? 0;
            $rate = $stage['conversion_rate'] ?? 0;
            $dropoff = $stage['dropoff'] ?? 0;

            $stepRate = $prevCount !== null && $prevCount > 0 ? round(($count / $prevCount) * 100, 1) : 100;
            $indicator = $stepRate >= 70 ? '🟢' : ($stepRate >= 40 ? '🟡' : '🔴');

            $rows[] = [
                $name,
                (string) $count,
                $this->formatPercent($rate),
                sprintf('%s %.1f%% step rate', $indicator, $stepRate),
            ];

            $prevCount = $count;
        }

        $overallConversion = $funnel['conversion_rate'] ?? 0;
        $avgTimeToConvert = $funnel['avg_time_to_convert'] ?? 0;

        $this->table(
            ['Stage', 'Users', 'Cumulative', 'Step Rate'],
            $rows,
        );

        $this->newLine();
        $this->line(sprintf('  Overall Conversion: %s', $this->formatPercent($overallConversion)));
        $this->line(sprintf('  Avg Time to Convert: %s', $avgTimeToConvert > 0 ? $avgTimeToConvert . ' days' : 'N/A'));
    }

    /**
     * Render SaaS momentum.
     */
    private function renderMomentum(int $period): void
    {
        $momentum = $this->getMomentumData($period);

        $this->info('  🚀 SaaS Momentum');
        $this->line('  ──────────────────────────────────────────────────');

        $score = $momentum['score'] ?? 0;
        $grade = $momentum['grade'] ?? 'N/A';
        $components = $momentum['components'] ?? [];

        $gradeEmoji = match (true) {
            $score >= 80 => '🟢',
            $score >= 60 => '🟡',
            $score >= 40 => '🟠',
            default => '🔴',
        };

        $this->line(sprintf('  Momentum Score: %s %d/100 (%s)', $gradeEmoji, $score, $grade));
        $this->newLine();

        if ($components !== []) {
            $rows = [];
            foreach ($components as $key => $value) {
                $label = ucfirst((string) str_replace('_', ' ', $key));
                $val = is_numeric($value) ? round((float) $value, 1) : (string) $value;
                $indicator = (is_float($value) && $value >= 70) ? '🟢' : ((is_float($value) && $value >= 40) ? '🟡' : '🔴');

                $rows[] = [$label, (string) $val, $indicator];
            }

            $this->table(
                ['Component', 'Score', 'Status'],
                $rows,
            );
        }
    }

    /**
     * Render industry benchmarks.
     */
    private function renderBenchmarks(int $period): void
    {
        $benchmarks = $this->getBenchmarksData($period);

        $this->info('  📋 Industry Benchmarks');
        $this->line('  ──────────────────────────────────────────────────');

        $data = $benchmarks['benchmarks'] ?? [];

        if ($data === []) {
            $this->warn('  No benchmark data available.');

            return;
        }

        $rows = [];
        foreach ($data as $metric => $benchmark) {
            $current = $benchmark['current'] ?? 0;
            $target = $benchmark['target'] ?? 0;
            $percentile = $benchmark['percentile'] ?? 0;
            $median = $benchmark['median'] ?? 0;
            $indicator = $percentile >= 75 ? '🟢 Top Quartile' : ($percentile >= 50 ? '🟡 Above Median' : ($percentile >= 25 ? '🟠 Below Median' : '🔴 Bottom Quartile'));

            $rows[] = [
                ucfirst((string) str_replace('_', ' ', $metric)),
                (string) round((float) $current, 2),
                (string) round((float) $median, 2),
                (string) round((float) $target, 2),
                sprintf('%d%% — %s', $percentile, $indicator),
            ];
        }

        $this->table(
            ['Metric', 'Current', 'Median', 'Target', 'Percentile'],
            $rows,
        );
    }

    /**
     * Generate an empty KPI response structure.
     *
     * @return array<string, mixed>
     */
    private function emptyKpiResponse(int $period): array
    {
        return [
            'period_days' => $period,
            'mrr' => 0,
            'arr' => 0,
            'net_revenue_retention' => 0,
            'gross_churn_rate' => 0,
            'net_churn_rate' => 0,
            'logo_churn_rate' => 0,
            'ltv' => 0,
            'cac' => 0,
            'ltv_cac_ratio' => 0,
            'quick_ratio' => 0,
            'rule_of_40' => 0,
            'growth_rate' => 0,
            'trial_conversion_rate' => 0,
            'activation_rate' => 0,
            'payback_period_months' => 0,
            'currency' => $this->getCurrency(),
        ];
    }

    /**
     * Format a currency value.
     */
    private function formatCurrency(float $value, string $currency): string
    {
        return $currency . number_format($value, 2);
    }

    /**
     * Format a percentage value.
     */
    private function formatPercent(float $value): string
    {
        return number_format($value * 100, 1) . '%';
    }

    /**
     * Generate a simple ASCII bar for MRR movement.
     */
    private function mrrBar(float $value, float $max, string $direction): string
    {
        if ($max <= 0) {
            return '';
        }

        $ratio = abs($value) / $max;
        $filled = (int) round($ratio * 20);
        $bar = str_repeat('█', min($filled, 20)) . str_repeat('░', max(20 - $filled, 0));

        return $direction . ' ' . $bar;
    }

    /**
     * Get the configured currency.
     */
    private function getCurrency(): string
    {
        return (string) ($this->config->get('zeroboiler.analytics.revenue.currency', 'USD') ?? 'USD');
    }
}
