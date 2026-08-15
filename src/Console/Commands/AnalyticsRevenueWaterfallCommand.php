<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\RevenueWaterfallService;
use ZeroBoiler\Analytics\Services\FeatureFlagAnalyticsService;
use ZeroBoiler\Analytics\Services\SaaSGrowthMetricsService;

/**
 * Displays revenue waterfall, growth metrics, and feature flag analytics.
 *
 * Provides a unified command for SaaS operators to monitor:
 * - MRR waterfall (new, expansion, contraction, churn)
 * - Net MRR retention rate
 * - Feature flag variant distribution and conversion rates
 * - Growth metrics (activation, stickiness, virality, retention)
 *
 * @since 78.0.0
 */
final class AnalyticsRevenueWaterfallCommand extends Command
{
    protected $signature = 'zb:analytics:revenue-waterfall
        {--json : Output as JSON}
        {--period=current_month : Period (current_month, 2024-01, etc.)}
        {--trend : Show MRR trend for last 12 months}
        {--growth : Show growth metrics dashboard}
        {--flags : Show feature flag analytics}
        {--retention : Show retention rate}
        {--clear-cache : Clear all waterfall/growth cache}';

    protected $description = 'Display SaaS revenue waterfall, growth metrics, and feature flag analytics';

    private ?RevenueWaterfallService $waterfallService = null;
    private ?SaaSGrowthMetricsService $growthService = null;
    private ?FeatureFlagAnalyticsService $flagService = null;

    public function __construct(
        private readonly RevenueWaterfallService $waterfall,
        private readonly SaaSGrowthMetricsService $growth,
        private readonly FeatureFlagAnalyticsService $flags,
    ): void {
        parent::__construct();
        $this->waterfallService = $waterfall;
        $this->growthService = $growth;
        $this->flagService = $flags;
    }

    #[Override]
    #[Override]
    public function handle(): int
    {
        if ((bool) $this->option('clear-cache')) {
            $this->clearAllCache();

            $this->info('✓ All revenue waterfall, growth metrics, and feature flag cache cleared.');

            return self::SUCCESS;
        }

        $outputJson = (bool) $this->option('json');
        $result = $this->buildOutput();

        if ($outputJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->displayOutput($result);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOutput(): array
    {
        $result = [];

        // Revenue waterfall (always included)
        $period = (string) $this->option('period');
        $result['waterfall'] = $this->waterfallService->waterfall($period);
        $result['movement_summary'] = $this->waterfallService->movementSummary();
        $result['net_mrr_retention'] = $this->waterfallService->netMrrRetentionRate($period);

        if ((bool) $this->option('trend')) {
            $result['mrr_trend'] = $this->waterfallService->mrrTrend(12);
        }

        if ((bool) $this->option('growth')) {
            $result['growth'] = $this->growthService->dashboardSummary();
        }

        if ((bool) $this->option('flags')) {
            $result['feature_flags'] = $this->flagService->allFlags();
            $result['flag_adoption'] = $this->flagService->adoptionSummary();
        }

        if ((bool) $this->option('retention')) {
            $result['retention'] = $this->growthService->retentionCurve();
            $result['stickiness'] = $this->growthService->stickinessRate();
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function displayOutput(array $result): void
    {
        $this->components->info('SaaS Revenue Waterfall');

        // Waterfall table
        $wf = $result['waterfall'];
        $this->table(
            ['Metric', 'Value'],
            [
                ['Starting MRR', number_format($wf['starting_mrr'], 2) . ' ' . $wf['currency']],
                ['New MRR', number_format($wf['new'], 2)],
                ['Expansion', number_format($wf['expansion'], 2)],
                ['Contraction', number_format(abs($wf['contraction']), 2)],
                ['Reactivation', number_format($wf['reactivation'], 2)],
                ['Churn', number_format(abs($wf['churn']), 2)],
                ['Net Change', number_format($wf['net_change'], 2)],
                ['Ending MRR', number_format($wf['ending_mrr'], 2) . ' ' . $wf['currency']],
                ['Growth Rate', $wf['growth_rate'] . '%'],
            ],
        );

        // Movement summary
        $this->components->twoColumnDetail('Movement Summary', '');
        $summary = $result['movement_summary'];
        foreach ($summary as $type => $data) {
            $this->components->twoColumnDetail(
                '  ' . ucfirst($type),
                sprintf('%d movements, %s avg', $data['count'], number_format($data['avg_deal_size'], 2)),
            );
        }

        // Net MRR retention
        $retention = $result['net_mrr_retention'];
        $rateColor = $retention['rate'] >= 100 ? 'green' : ($retention['rate'] >= 80 ? 'yellow' : 'red');
        $this->newLine();
        $this->components->info("Net MRR Retention: <fg={$rateColor}>{$retention['rate']}%</fg={$rateColor}>");

        // Growth dashboard
        if (isset($result['growth'])) {
            $growth = $result['growth'];
            $this->newLine();
            $this->components->info('Growth Metrics');

            $this->components->twoColumnDetail('Activation Rate', $growth['activation']['rate'] . '%');
            $this->components->twoColumnDetail('Stickiness (DAU/MAU)', $growth['stickiness']['rate'] . '%');

            $kFactor = $growth['virality']['k_factor'];
            $kColor = $kFactor >= 1.0 ? 'green' : 'yellow';
            $this->components->twoColumnDetail('Virality (K-factor)', "<fg={$kColor}>{$kFactor}</fg={$kColor}>");

            $ret = $growth['retention'];
            $this->components->twoColumnDetail('D1 Retention', ($ret['day_1'] ?? 'N/A') . '%');
            $this->components->twoColumnDetail('D7 Retention', ($ret['day_7'] ?? 'N/A') . '%');
            $this->components->twoColumnDetail('D30 Retention', ($ret['day_30'] ?? 'N/A') . '%');
        }

        // Feature flags
        if (isset($result['feature_flags'])) {
            $this->newLine();
            $this->components->info('Feature Flag Analytics');

            foreach ($result['feature_flags'] as $flag) {
                $this->components->twoColumnDetail(
                    $flag['flag_key'],
                    sprintf('%d evaluations, %d variants', $flag['total_exposures'], count($flag['variants'])),
                );
            }
        }

        // Retention
        if (isset($result['retention'])) {
            $this->newLine();
            $this->components->info('Retention Curve');

            foreach (['day_1', 'day_3', 'day_7', 'day_14', 'day_30'] as $key) {
                $val = $result['retention'][$key] ?? null;
                $display = $val !== null ? $val . '%' : 'N/A';
                $this->components->twoColumnDetail(ucfirst(str_replace('_', ' ', $key)), $display);
            }
        }
    }

    private function clearAllCache(): void
    {
        $this->waterfallService->clearCache();
        $this->growthService->clearCache();
        $this->flagService->clearCache();
    }
}
