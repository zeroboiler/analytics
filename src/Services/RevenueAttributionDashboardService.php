<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\UtmAttribution;
use ZeroBoiler\Analytics\Store\EventStoreManager;

/**
 * SaaS Revenue Attribution Dashboard service.
 *
 * Combines revenue events with acquisition channels (UTM/referral) to provide
 * a comprehensive ROI view. Calculates LTV by channel, CAC recovery time,
 * payback periods, and revenue concentration metrics essential for SaaS
 * operators.
 *
 * Reads configuration from `zeroboiler.analytics.revenue_attribution`.
 *
 * @see \ZeroBoiler\Analytics\Services\RevenueAttributionService
 * @see \ZeroBoiler\Analytics\Console\Commands\AnalyticsRevenueAttributionCommand
 *
 * @since 148.0.0
 */
final class RevenueAttributionDashboardService
{
    /** @var int Default lookback window in days for revenue aggregation */
    public const DEFAULT_LOOKBACK_DAYS = 30;

    /** @var string Default currency */
    public const DEFAULT_CURRENCY = 'USD';

    private AnalyticsManager $manager;

    private AnalyticsMetrics $metrics;

    private EventStoreManager $store;

    private int $lookbackDays;

    private string $defaultCurrency;

    /** @var array<string, mixed> Revenue attribution configuration */
    private array $config;

    /**
     * @param  AnalyticsManager  $manager  Central analytics manager
     * @param  AnalyticsMetrics  $metrics  Analytics metrics collector
     * @param  EventStoreManager  $store  Event store for querying historical data
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(
        AnalyticsManager $manager,
        AnalyticsMetrics $metrics,
        EventStoreManager $store,
        ConfigRepository $config,
    ): void {
        $this->manager = $manager;
        $this->metrics = $metrics;
        $this->store = $store;

        $attributionConfig = $config->get('zeroboiler.analytics.revenue_attribution', []);
        /** @var array{lookback_days?: int, currency?: string, channels?: list<string>, cac_threshold?: float, ltv_multiplier?: float} $attributionConfig */
        $this->config = $attributionConfig;
        $this->lookbackDays = $attributionConfig['lookback_days'] ?? self::DEFAULT_LOOKBACK_DAYS;
        $this->defaultCurrency = $attributionConfig['currency'] ?? self::DEFAULT_CURRENCY;
    }

    /**
     * Get the full revenue attribution dashboard summary.
     *
     * Returns a consolidated view of revenue by acquisition channel,
     * including LTV, CAC recovery, and payback period calculations.
     *
     * @param  int|null  $lookbackDays  Override default lookback window
     * @return array{generated_at: string, period_days: int, currency: string, total_revenue: float, total_customers: int, channels: array<string, array{revenue: float, customers: int, ltv: float, cac: float|null, payback_months: float|null, revenue_share: float, avg_revenue_per_customer: float}>, top_channel: string|null, revenue_concentration: float, recommendations: list<string>}
     */
    public function dashboard(?int $lookbackDays = null): array
    {
        $days = $lookbackDays ?? $this->lookbackDays;
        $channelData = $this->revenueByChannel($days);
        $totalRevenue = $this->sumChannelRevenue($channelData);
        $totalCustomers = $this->sumChannelCustomers($channelData);

        // Calculate LTV, CAC, payback per channel
        $enrichedChannels = $this->enrichChannelMetrics($channelData, $totalRevenue);

        // Revenue concentration (Herfindahl index)
        $concentration = $this->calculateRevenueConcentration($enrichedChannels, $totalRevenue);

        // Find top channel
        $topChannel = $this->findTopChannel($enrichedChannels);

        // Generate recommendations
        $recommendations = $this->generateRecommendations($enrichedChannels, $totalRevenue, $concentration);

        return [
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'period_days' => $days,
            'currency' => $this->defaultCurrency,
            'total_revenue' => round($totalRevenue, 2),
            'total_customers' => $totalCustomers,
            'channels' => $enrichedChannels,
            'top_channel' => $topChannel,
            'revenue_concentration' => round($concentration, 4),
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Get revenue breakdown by acquisition channel.
     *
     * Aggregates revenue events grouped by their UTM source/medium.
     *
     * @param  int  $days  Lookback period in days
     * @return array<string, array{revenue: float, customers: int, events: int}>
     */
    public function revenueByChannel(int $days): array
    {
        $channels = [];
        $configuredChannels = $this->config['channels'] ?? [
            'organic', 'direct', 'google_cpc', 'google_organic',
            'facebook_ads', 'twitter_ads', 'linkedin_ads',
            'referral', 'email', 'affiliate',
        ];

        // Initialize channels
        foreach ($configuredChannels as $channel) {
            $channels[$channel] = [
                'revenue' => 0.0,
                'customers' => 0,
                'events' => 0,
            ];
        }

        // Aggregate from metrics (in-memory, production uses event store)
        $metricsData = $this->metrics->all();
        $revenueEvents = array_filter(
            $metricsData['events'] ?? [],
            fn (array $e): bool => in_array($e['name'] ?? '', [
                'purchase', 'subscription', 'revenue_tracked', 'mrr_change',
                'ltv_update', 'track_revenue',
            ], true),
        );

        foreach ($revenueEvents as $event) {
            $amount = (float) ($event['params']['revenue_amount'] ?? $event['params']['value'] ?? $event['params']['mrr_delta'] ?? 0);
            $source = $event['params']['utm_source'] ?? $event['params']['acquisition_channel'] ?? 'direct';
            $channel = $this->normalizeChannelName($source);

            if (! isset($channels[$channel])) {
                $channels[$channel] = [
                    'revenue' => 0.0,
                    'customers' => 0,
                    'events' => 0,
                ];
            }

            $channels[$channel]['revenue'] += $amount;
            $channels[$channel]['events']++;
            if (! empty($event['user_id'])) {
                $channels[$channel]['customers'] = max($channels[$channel]['customers'], 1);
            }
        }

        return $channels;
    }

    /**
     * Get LTV (Lifetime Value) breakdown by acquisition channel.
     *
     * @param  int  $days  Lookback period in days
     * @return array<string, float> Channel name → average LTV
     */
    public function ltvByChannel(int $days = 30): array
    {
        $channels = $this->revenueByChannel($days);
        $result = [];

        foreach ($channels as $channel => $data) {
            if ($data['customers'] > 0) {
                $result[$channel] = round($data['revenue'] / $data['customers'], 2);
            }
        }

        arsort($result);

        return $result;
    }

    /**
     * Get CAC (Customer Acquisition Cost) recovery analysis.
     *
     * Compares marketing spend per channel against revenue generated.
     * Configured spend data comes from `zeroboiler.analytics.revenue_attribution.cac_by_channel`.
     *
     * @param  int  $days  Lookback period in days
     * @return array<string, array{spend: float, revenue: float, customers: int, cac: float, cac_recovery_pct: float, payback_months: float|null}>
     */
    public function cacRecoveryByChannel(int $days = 30): array
    {
        $channels = $this->revenueByChannel($days);
        $cacByChannel = $this->config['cac_by_channel'] ?? [];
        $result = [];

        foreach ($channels as $channel => $data) {
            $spend = (float) ($cacByChannel[$channel] ?? 0);
            $cac = $data['customers'] > 0 ? $spend / $data['customers'] : 0;
            $cacRecoveryPct = $spend > 0 ? ($data['revenue'] / $spend) * 100 : 0;
            $monthlyRevenue = $data['revenue'] / max($days, 1) * 30;
            $paybackMonths = $spend > 0 && $data['revenue'] > 0
                ? $spend / max($monthlyRevenue, 0.01)
                : null;

            $result[$channel] = [
                'spend' => round($spend, 2),
                'revenue' => round($data['revenue'], 2),
                'customers' => $data['customers'],
                'cac' => round($cac, 2),
                'cac_recovery_pct' => round($cacRecoveryPct, 1),
                'payback_months' => $paybackMonths !== null ? round($paybackMonths, 1) : null,
            ];
        }

        return $result;
    }

    /**
     * Get revenue concentration analysis.
     *
     * Uses the Herfindahl-Hirschman Index (HHI) to measure how
     * concentrated revenue is across acquisition channels.
     * Values 0-1 where 0 = perfectly diversified, 1 = single channel.
     *
     * @param  int  $days  Lookback period in days
     * @return array{hhi: float, concentration_grade: string, dominant_channel: string|null, risk_level: string}
     */
    public function revenueConcentrationAnalysis(int $days = 30): array
    {
        $channels = $this->revenueByChannel($days);
        $totalRevenue = $this->sumChannelRevenue($channels);
        $hhi = $this->calculateRevenueConcentration($channels, $totalRevenue);

        $grade = $this->concentrationGrade($hhi);
        $dominantChannel = $this->findTopChannel($channels);
        $riskLevel = $this->assessConcentrationRisk($hhi);

        return [
            'hhi' => round($hhi, 4),
            'concentration_grade' => $grade,
            'dominant_channel' => $dominantChannel,
            'risk_level' => $riskLevel,
        ];
    }

    /**
     * Get month-over-month revenue growth by channel.
     *
     * Compares current period revenue vs previous period for each channel.
     *
     * @param  int  $days  Current period length
     * @return array<string, array{current: float, previous: float, growth_pct: float, growth_abs: float}>
     */
    public function channelGrowthTrend(int $days = 30): array
    {
        $currentChannels = $this->revenueByChannel($days);
        $previousChannels = $this->revenueByChannel($days * 2);

        // Subtract current from previous to get only the earlier period
        $earlierChannels = [];
        foreach ($previousChannels as $channel => $data) {
            $currentRevenue = $currentChannels[$channel]['revenue'] ?? 0;
            $earlierChannels[$channel] = [
                'revenue' => $data['revenue'] - $currentRevenue,
                'customers' => max($data['customers'] - ($currentChannels[$channel]['customers'] ?? 0), 0),
            ];
        }

        $result = [];
        foreach ($currentChannels as $channel => $data) {
            $currentRevenue = $data['revenue'];
            $previousRevenue = $earlierChannels[$channel]['revenue'] ?? 0;
            $growthPct = $previousRevenue > 0
                ? (($currentRevenue - $previousRevenue) / $previousRevenue) * 100
                : ($currentRevenue > 0 ? 100.0 : 0.0);

            $result[$channel] = [
                'current' => round($currentRevenue, 2),
                'previous' => round($previousRevenue, 2),
                'growth_pct' => round($growthPct, 1),
                'growth_abs' => round($currentRevenue - $previousRevenue, 2),
            ];
        }

        return $result;
    }

    /**
     * Enrich channel data with LTV, CAC, and payback metrics.
     *
     * @param  array<string, array{revenue: float, customers: int, events: int}>  $channels
     * @param  float  $totalRevenue
     * @return array<string, array{revenue: float, customers: int, ltv: float, cac: float|null, payback_months: float|null, revenue_share: float, avg_revenue_per_customer: float}>
     */
    private function enrichChannelMetrics(array $channels, float $totalRevenue): array
    {
        $cacByChannel = $this->config['cac_by_channel'] ?? [];
        $enriched = [];

        foreach ($channels as $name => $data) {
            $customerCount = max($data['customers'], 1);
            $ltv = $data['revenue'] / $customerCount;
            $spend = (float) ($cacByChannel[$name] ?? 0);
            $cac = $data['customers'] > 0 ? $spend / $customerCount : null;
            $monthlyRevenue = $data['revenue'] / max($this->lookbackDays, 1) * 30;
            $paybackMonths = $cac !== null && $monthlyRevenue > 0
                ? $cac / $monthlyRevenue
                : null;

            $enriched[$name] = [
                'revenue' => round($data['revenue'], 2),
                'customers' => $data['customers'],
                'ltv' => round($ltv, 2),
                'cac' => $cac !== null ? round($cac, 2) : null,
                'payback_months' => $paybackMonths !== null ? round($paybackMonths, 1) : null,
                'revenue_share' => $totalRevenue > 0 ? round(($data['revenue'] / $totalRevenue) * 100, 1) : 0,
                'avg_revenue_per_customer' => round($data['revenue'] / $customerCount, 2),
            ];
        }

        return $enriched;
    }

    /**
     * Calculate revenue concentration using Herfindahl-Hirschman Index.
     *
     * @param  array<string, array{revenue: float}>  $channels
     * @param  float  $totalRevenue
     * @return float 0-1 concentration index
     */
    private function calculateRevenueConcentration(array $channels, float $totalRevenue): float
    {
        if ($totalRevenue <= 0) {
            return 0.0;
        }

        $hhi = 0.0;
        foreach ($channels as $data) {
            $share = $data['revenue'] / $totalRevenue;
            $hhi += $share * $share;
        }

        return $hhi;
    }

    /**
     * Grade the concentration level.
     *
     * @param  float  $hhi  Herfindahl index (0-1)
     * @return string 'excellent'|'good'|'moderate'|'concentrated'|'critical'
     */
    private function concentrationGrade(float $hhi): string
    {
        return match (true) {
            $hhi <= 0.15 => 'excellent',
            $hhi <= 0.25 => 'good',
            $hhi <= 0.40 => 'moderate',
            $hhi <= 0.60 => 'concentrated',
            default => 'critical',
        };
    }

    /**
     * Assess concentration risk level.
     *
     * @param  float  $hhi  Herfindahl index (0-1)
     * @return string 'low'|'medium'|'high'|'critical'
     */
    private function assessConcentrationRisk(float $hhi): string
    {
        return match (true) {
            $hhi <= 0.20 => 'low',
            $hhi <= 0.40 => 'medium',
            $hhi <= 0.60 => 'high',
            default => 'critical',
        };
    }

    /**
     * Find the top revenue channel.
     *
     * @param  array<string, array{revenue: float}>  $channels
     * @return string|null Channel name or null if no revenue
     */
    private function findTopChannel(array $channels): ?string
    {
        $topChannel = null;
        $topRevenue = 0.0;

        foreach ($channels as $name => $data) {
            if ($data['revenue'] > $topRevenue) {
                $topRevenue = $data['revenue'];
                $topChannel = $name;
            }
        }

        return $topChannel;
    }

    /**
     * Sum total revenue across all channels.
     *
     * @param  array<string, array{revenue: float}>  $channels
     * @return float
     */
    private function sumChannelRevenue(array $channels): float
    {
        return array_reduce(
            $channels,
            fn (float $carry, array $data): float => $carry + $data['revenue'],
            0.0,
        );
    }

    /**
     * Sum total customers across all channels.
     *
     * @param  array<string, array{customers: int}>  $channels
     * @return int
     */
    private function sumChannelCustomers(array $channels): int
    {
        return array_reduce(
            $channels,
            fn (int $carry, array $data): int => $carry + $data['customers'],
            0,
        );
    }

    /**
     * Normalize a UTM source into a standard channel name.
     *
     * @param  string  $source  Raw UTM source value
     * @return string Normalized channel name
     */
    private function normalizeChannelName(string $source): string
    {
        $normalized = strtolower(trim($source));

        return match (true) {
            str_contains($normalized, 'google') && str_contains($normalized, 'cpc') => 'google_cpc',
            str_contains($normalized, 'google') && str_contains($normalized, 'organic') => 'google_organic',
            str_contains($normalized, 'google') => 'google_organic',
            str_contains($normalized, 'facebook') || str_contains($normalized, 'meta') => 'facebook_ads',
            str_contains($normalized, 'instagram') => 'facebook_ads',
            str_contains($normalized, 'twitter') || str_contains($normalized, 'x.com') => 'twitter_ads',
            str_contains($normalized, 'linkedin') => 'linkedin_ads',
            str_contains($normalized, 'tiktok') => 'tiktok_ads',
            str_contains($normalized, 'referral') || str_contains($normalized, 'partner') => 'referral',
            str_contains($normalized, 'email') || str_contains($normalized, 'newsletter') => 'email',
            str_contains($normalized, 'affiliate') || str_contains($normalized, 'partner_') => 'affiliate',
            str_contains($normalized, 'organic') => 'organic',
            str_contains($normalized, 'direct') => 'direct',
            default => $normalized,
        };
    }

    /**
     * Generate actionable recommendations based on channel data.
     *
     * @param  array<string, array{revenue: float, customers: int, ltv: float, cac: float|null, payback_months: float|null, revenue_share: float}>  $channels
     * @param  float  $totalRevenue
     * @param  float  $concentration
     * @return list<string>
     */
    private function generateRecommendations(array $channels, float $totalRevenue, float $concentration): array
    {
        $recommendations = [];

        // Concentration risk
        if ($concentration > 0.40) {
            $topChannel = $this->findTopChannel($channels);
            $recommendations[] = "⚠️  HIGH CONCENTRATION: {$topChannel} drives " . round($concentration * 100, 0) . '% of revenue. Diversify acquisition channels.';
        }

        // Channels with high LTV but low volume
        foreach ($channels as $name => $data) {
            if ($data['ltv'] > 100 && $data['customers'] <= 1 && $data['revenue'] > 0) {
                $recommendations[] = "📈 High LTV channel '{$name}' (LTV: \${$data['ltv']}) has low volume. Consider scaling investment.";
            }
        }

        // Channels with negative ROI
        foreach ($channels as $name => $data) {
            if ($data['cac'] !== null && $data['ltv'] < $data['cac'] && $data['revenue'] > 0) {
                $recommendations[] = "📉 Channel '{$name}' has negative unit economics (LTV: \${$data['ltv']} < CAC: \${$data['cac']}). Review or reduce spend.";
            }
        }

        // Channels with long payback
        foreach ($channels as $name => $data) {
            if ($data['payback_months'] !== null && $data['payback_months'] > 18) {
                $recommendations[] = "⏳ Channel '{$name}' has long payback period ({$data['payback_months']} months). Optimize conversion or reduce acquisition cost.";
            }
        }

        // No revenue warning
        if ($totalRevenue <= 0) {
            $recommendations[] = '💡 No revenue events tracked in the current period. Ensure purchase/subscription events are being dispatched.';
        }

        // Good diversification
        if ($concentration <= 0.15 && $totalRevenue > 0) {
            $recommendations[] = '✅ Excellent channel diversification. Revenue is well-distributed across acquisition channels.';
        }

        return $recommendations;
    }
}
