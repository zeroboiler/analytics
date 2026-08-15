<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\Services\RevenueAttributionDashboardService;
use ZeroBoiler\Analytics\Store\EventStoreManager;

/**
 * Displays a revenue attribution dashboard for SaaS operators.
 *
 * Shows revenue breakdown by acquisition channel, LTV by channel,
 * CAC recovery analysis, revenue concentration risk, and
 * actionable recommendations for optimizing marketing spend.
 *
 * @see \ZeroBoiler\Analytics\Services\RevenueAttributionDashboardService
 *
 * @since 148.0.0
 */
final class AnalyticsRevenueAttributionCommand extends Command
{
    protected $signature = 'zb:analytics:revenue-attribution
        {--days=30 : Lookback period in days}
        {--json : Output as JSON}
        {--ltv : Show LTV breakdown by channel}
        {--cac : Show CAC recovery analysis}
        {--concentration : Show revenue concentration analysis}
        {--growth : Show channel growth trends}
        {--all : Show all sections}';

    protected $description = 'Display revenue attribution dashboard by acquisition channel';

    private RevenueAttributionDashboardService $service;

    /**
     * @param  AnalyticsManager  $manager  Central analytics manager
     * @param  AnalyticsMetrics  $metrics  Analytics metrics collector
     * @param  EventStoreManager  $store  Event store for historical queries
     */
    public function __construct(
        private readonly AnalyticsManager $manager,
        private readonly AnalyticsMetrics $metrics,
        private readonly EventStoreManager $store,
    ): void {
        parent::__construct();
        $this->service = new RevenueAttributionDashboardService($manager, $metrics, $store, app('config'));
    }

    #[Override]
    #[Override]
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $outputJson = (bool) $this->option('json');
        $showAll = (bool) $this->option('all');

        if ($outputJson) {
            $result = $this->buildFullJsonOutput($days);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($showAll) {
            $this->renderDashboard($days);
            $this->line('');
            $this->renderLtv($days);
            $this->line('');
            $this->renderCac($days);
            $this->line('');
            $this->renderConcentration($days);
            $this->line('');
            $this->renderGrowth($days);

            return self::SUCCESS;
        }

        // Default: render dashboard
        $this->renderDashboard($days);

        if ($this->option('ltv')) {
            $this->line('');
            $this->renderLtv($days);
        }

        if ($this->option('cac')) {
            $this->line('');
            $this->renderCac($days);
        }

        if ($this->option('concentration')) {
            $this->line('');
            $this->renderConcentration($days);
        }

        if ($this->option('growth')) {
            $this->line('');
            $this->renderGrowth($days);
        }

        return self::SUCCESS;
    }

    /**
     * Build full JSON output for all sections.
     *
     * @param  int  $days  Lookback period
     * @return array{dashboard: mixed, ltv: mixed, cac: mixed, concentration: mixed, growth: mixed}
     */
    private function buildFullJsonOutput(int $days): array
    {
        return [
            'dashboard' => $this->service->dashboard($days),
            'ltv' => $this->service->ltvByChannel($days),
            'cac' => $this->service->cacRecoveryByChannel($days),
            'concentration' => $this->service->revenueConcentrationAnalysis($days),
            'growth' => $this->service->channelGrowthTrend($days),
        ];
    }

    /**
     * Render the main revenue attribution dashboard.
     *
     * @param  int  $days  Lookback period
     */
    private function renderDashboard(int $days): void
    {
        $data = $this->service->dashboard($days);

        $this->info('═══ Revenue Attribution Dashboard ═══');
        $this->line("  Period: {$data['period_days']} days | Currency: {$data['currency']}");
        $this->line("  Generated: {$data['generated_at']}");
        $this->line('');
        $this->info("  Total Revenue: \${$data['total_revenue']}");
        $this->info("  Total Customers: {$data['total_customers']}");
        $this->info("  Top Channel: " . ($data['top_channel'] ?? 'N/A'));
        $this->info("  Revenue Concentration (HHI): " . ($data['revenue_concentration'] * 100) . '%');
        $this->line('');
        $this->info('  ─── Revenue by Channel ───');

        $this->table(
            ['Channel', 'Revenue', 'Customers', 'LTV', 'CAC', 'Payback', 'Share %'],
            collect($data['channels'])
                ->sortByDesc('revenue')
                ->map(fn (array $ch): array => [
                    $ch['revenue'],
                    '$' . number_format($ch['revenue'], 2),
                    (string) $ch['customers'],
                    '$' . number_format($ch['ltv'], 2),
                    $ch['cac'] !== null ? '$' . number_format($ch['cac'], 2) : 'N/A',
                    $ch['payback_months'] !== null ? $ch['payback_months'] . 'mo' : 'N/A',
                    $ch['revenue_share'] . '%',
                ])
                ->values()
                ->map(fn (array $row): array => [
                    $row[0] ?? 'N/A',
                    $row[1] ?? '$0.00',
                    $row[2] ?? '0',
                    $row[3] ?? '$0.00',
                    $row[4] ?? 'N/A',
                    $row[5] ?? 'N/A',
                    $row[6] ?? '0%',
                ])
                ->toArray(),
        );

        if (! empty($data['recommendations'])) {
            $this->line('');
            $this->warn('  ─── Recommendations ───');
            foreach ($data['recommendations'] as $rec) {
                $this->line("  {$rec}");
            }
        }
    }

    /**
     * Render LTV breakdown by channel.
     *
     * @param  int  $days  Lookback period
     */
    private function renderLtv(int $days): void
    {
        $ltv = $this->service->ltvByChannel($days);

        $this->info('═══ LTV by Acquisition Channel ═══');
        $this->table(
            ['Channel', 'Average LTV'],
            collect($ltv)->map(fn (float $value, string $channel): array => [
                $channel,
                '$' . number_format($value, 2),
            ])->values()->toArray(),
        );
    }

    /**
     * Render CAC recovery analysis.
     *
     * @param  int  $days  Lookback period
     */
    private function renderCac(int $days): void
    {
        $cac = $this->service->cacRecoveryByChannel($days);

        $this->info('═══ CAC Recovery Analysis ═══');
        $this->table(
            ['Channel', 'Spend', 'Revenue', 'Customers', 'CAC', 'Recovery %', 'Payback'],
            collect($cac)->map(fn (array $data, string $channel): array => [
                $channel,
                '$' . number_format($data['spend'], 2),
                '$' . number_format($data['revenue'], 2),
                (string) $data['customers'],
                '$' . number_format($data['cac'], 2),
                $data['cac_recovery_pct'] . '%',
                $data['payback_months'] !== null ? $data['payback_months'] . 'mo' : 'N/A',
            ])->values()->toArray(),
        );
    }

    /**
     * Render revenue concentration analysis.
     *
     * @param  int  $days  Lookback period
     */
    private function renderConcentration(int $days): void
    {
        $analysis = $this->service->revenueConcentrationAnalysis($days);

        $this->info('═══ Revenue Concentration Analysis ═══');
        $this->line("  HHI Index: " . ($analysis['hhi'] * 100) . '%');
        $this->line("  Grade: {$analysis['concentration_grade']}");
        $this->line("  Dominant Channel: " . ($analysis['dominant_channel'] ?? 'N/A'));
        $this->line("  Risk Level: {$analysis['risk_level']}");
    }

    /**
     * Render channel growth trends.
     *
     * @param  int  $days  Lookback period
     */
    private function renderGrowth(int $days): void
    {
        $growth = $this->service->channelGrowthTrend($days);

        $this->info('═══ Channel Growth Trends (MoM) ═══');
        $this->table(
            ['Channel', 'Current', 'Previous', 'Growth %', 'Change'],
            collect($growth)->map(fn (array $data, string $channel): array => [
                $channel,
                '$' . number_format($data['current'], 2),
                '$' . number_format($data['previous'], 2),
                ($data['growth_pct'] >= 0 ? '+' : '') . $data['growth_pct'] . '%',
                ($data['growth_abs'] >= 0 ? '+$' : '-$') . number_format(abs($data['growth_abs']), 2),
            ])->values()->toArray(),
        );
    }
}
