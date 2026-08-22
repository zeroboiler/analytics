<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * SaaS Quick Insights Service — automated pattern detection & actionable insights.
 *
 * Analyzes analytics event data to detect notable patterns and generate
 * human-readable insights for SaaS dashboards. Uses statistical heuristics
 * to identify anomalies, trends, and opportunities without requiring ML models.
 *
 * Insight types:
 * - **spike**: Sudden increase in event frequency
 * - **drop**: Sudden decrease in event frequency
 * - **trending_up**: Sustained upward trajectory
 * - **trending_down**: Sustained downward trajectory
 * - **high_volatility**: Unstable metric behavior
 * - **outlier**: Data point far from the norm
 *
 * Each insight includes severity, confidence, affected metric, and
 * a human-readable summary with recommended action.
 *
 * Configuration via `zeroboiler.analytics.quick_insights`.
 *
 * Inspired by Amplitude's Compass, Mixpanel's Signal, and Datadog's Watchdog.
 *
 * @phpstan-type Insight array{id: string, type: string, title: string, description: string, metric: string, severity: 'info'|'warning'|'critical'|'success', confidence: float, period: string|null, value: float|null, delta: float|null, action: string|null, created_at: string}
 * @phpstan-type InsightConfig array{enabled: bool, max_insights: int, spike_threshold: float, drop_threshold: float, trend_periods: int, volatility_threshold: float, cache_ttl: int, ignored_metrics: list<string>}
 *
 * @see \ZeroBoiler\Analytics\Services\RollingWindowAnalyticsEngine
 *
 * @since 177.0.0
 */
final class SaaSQuickInsightsService
{
    /** @var string Current version. */
    public const VERSION = '1.0.0';

    private const CACHE_PREFIX = 'zb_quick_insights_';

    private const DEFAULT_CACHE_TTL = 600;

    private const DEFAULT_SPIKE_THRESHOLD = 2.0;

    private const DEFAULT_DROP_THRESHOLD = 0.5;

    private const DEFAULT_TREND_PERIODS = 5;

    private const DEFAULT_VOLATILITY_THRESHOLD = 0.5;

    private const DEFAULT_MAX_INSIGHTS = 20;

    /** @var array<string, array<int, float>> */
    private array $metricSeries = [];

    /**
     * Create a new SaaSQuickInsightsService.
     *
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ){}

    /**
     * Register a metric time series for analysis.
     *
     * @param  string  $metricName  Metric/event name
     * @param  array<int, float>  $values  Time-ordered values (oldest first)
     */
    public function registerSeries(string $metricName, array $values): void
    {
        $this->metricSeries[$metricName] = $values;
    }

    /**
     * Generate insights from all registered metric series.
     *
     * @return list<Insight>
     */
    public function generateInsights(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'generated';
        $ttl = (int) $this->config->get('zeroboiler.analytics.quick_insights.cache_ttl', self::DEFAULT_CACHE_TTL);

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $insights = [];
        $config = $this->getInsightConfig();
        $ignoredMetrics = $config['ignored_metrics'];

        foreach ($this->metricSeries as $metric => $values) {
            if (in_array($metric, $ignoredMetrics, true)) {
                continue;
            }

            $metricInsights = $this->analyzeMetric($metric, $values, $config);
            array_push($insights, ...$metricInsights);
        }

        $severityOrder = ['critical' => 0, 'warning' => 1, 'success' => 2, 'info' => 3];
        usort($insights, function (array $a, array $b) use ($severityOrder): int {
            $orderA = $severityOrder[$a['severity']] ?? 99;
            $orderB = $severityOrder[$b['severity']] ?? 99;

            return $orderA <=> $orderB;
        });

        $maxInsights = $config['max_insights'];
        $insights = array_slice($insights, 0, $maxInsights);

        $this->cache->put($cacheKey, $insights, $ttl);

        return $insights;
    }

    /**
     * Analyze a single metric for insights.
     *
     * @param  string  $metric  Metric name
     * @param  array<int, float>  $values  Time-ordered values
     * @param  InsightConfig  $config  Insight configuration
     * @return list<Insight>
     */
    private function analyzeMetric(string $metric, array $values, array $config): array
    {
        $insights = [];
        $count = count($values);

        if ($count < 2) {
            return $insights;
        }

        $current = $values[$count - 1];
        $previous = $values[$count - 2];

        // 1. Spike detection
        if ($previous > 0) {
            $ratio = $current / $previous;
            if ($ratio >= $config['spike_threshold']) {
                $insights[] = $this->buildInsight(
                    type: 'spike',
                    title: "Spike in {$metric}",
                    description: "{$metric} increased by " . round(($ratio - 1) * 100, 1) . '% in the latest period.',
                    metric: $metric,
                    severity: 'warning',
                    confidence: min(1.0, ($ratio - 1) / 2),
                    value: $current,
                    delta: round(($ratio - 1) * 100, 1),
                    action: 'Investigate the cause. Check for campaigns, feature launches, or data anomalies.',
                );
            }

            // 2. Drop detection
            if ($ratio <= $config['drop_threshold']) {
                $insights[] = $this->buildInsight(
                    type: 'drop',
                    title: "Drop in {$metric}",
                    description: "{$metric} decreased by " . round((1 - $ratio) * 100, 1) . '% in the latest period.',
                    metric: $metric,
                    severity: $ratio <= 0.1 ? 'critical' : 'warning',
                    confidence: min(1.0, (1 - $ratio) / 2),
                    value: $current,
                    delta: -round((1 - $ratio) * 100, 1),
                    action: 'Investigate potential causes: outages, tracking bugs, or seasonal effects.',
                );
            }
        }

        // 3. Trend detection (need more data points)
        if ($count >= $config['trend_periods']) {
            $recentSlice = array_slice($values, -$config['trend_periods']);
            $olderSlice = array_slice($values, -($config['trend_periods'] * 2), $config['trend_periods']);

            if (count($olderSlice) > 0) {
                $recentMean = array_sum($recentSlice) / count($recentSlice);
                $olderMean = array_sum($olderSlice) / count($olderSlice);

                if ($olderMean > 0) {
                    $trendChange = (($recentMean - $olderMean) / $olderMean) * 100;

                    if ($trendChange > 15) {
                        $insights[] = $this->buildInsight(
                            type: 'trending_up',
                            title: "{$metric} trending upward",
                            description: "{$metric} has been trending up over the last {$config['trend_periods']} periods (+{$trendChange}% vs previous window).",
                            metric: $metric,
                            severity: 'success',
                            confidence: min(1.0, abs($trendChange) / 50),
                            delta: round($trendChange, 1),
                            action: 'Capitalize on this positive trend. Consider doubling down on related initiatives.',
                        );
                    } elseif ($trendChange < -15) {
                        $insights[] = $this->buildInsight(
                            type: 'trending_down',
                            title: "{$metric} trending downward",
                            description: "{$metric} has been declining over the last {$config['trend_periods']} periods ({$trendChange}% vs previous window).",
                            metric: $metric,
                            severity: 'warning',
                            confidence: min(1.0, abs($trendChange) / 50),
                            delta: round($trendChange, 1),
                            action: 'Review recent changes that may have impacted this metric. Consider activation campaigns.',
                        );
                    }
                }
            }
        }

        // 4. Volatility detection
        $volWindow = max(3, min($count, 7));
        $volSlice = array_slice($values, -$volWindow);
        $volMean = array_sum($volSlice) / count($volSlice);

        if ($volMean > 0) {
            $variance = 0.0;
            foreach ($volSlice as $v) {
                $variance += (($v - $volMean) / $volMean) ** 2;
            }
            $volatilityScore = sqrt($variance / count($volSlice));

            if ($volatilityScore > $config['volatility_threshold']) {
                $insights[] = $this->buildInsight(
                    type: 'high_volatility',
                    title: "{$metric} is volatile",
                    description: "{$metric} shows high variability (CV: " . round($volatilityScore, 3) . ') over recent periods.',
                    metric: $metric,
                    severity: 'info',
                    confidence: min(1.0, $volatilityScore),
                    action: 'Consider using smoothed averages for reporting. Investigate root causes of variability.',
                );
            }
        }

        // 5. Outlier detection
        if ($count >= 5) {
            $mean = array_sum($values) / $count;
            $stdDev = $this->calculateStdDev($values);

            if ($stdDev > 0 && abs($current - $mean) > 2 * $stdDev) {
                $zScore = ($current - $mean) / $stdDev;
                $insights[] = $this->buildInsight(
                    type: 'outlier',
                    title: "Outlier detected in {$metric}",
                    description: "Current value ({$current}) is " . round(abs($zScore), 1) . " standard deviations from the mean.",
                    metric: $metric,
                    severity: abs($zScore) > 3 ? 'warning' : 'info',
                    confidence: min(1.0, abs($zScore) / 5),
                    value: $current,
                    action: 'Verify data integrity. This could indicate a tracking issue or a genuine anomaly.',
                );
            }
        }

        return $insights;
    }

    /**
     * Build a standardized insight array.
     *
     * @return Insight
     */
    private function buildInsight(
        string $type,
        string $title,
        string $description,
        string $metric,
        string $severity,
        float $confidence,
        ?float $value = null,
        ?float $delta = null,
        ?string $action = null,
    ): array {
        return [
            'id' => $type . '_' . $metric . '_' . substr((string) time(), -6),
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'metric' => $metric,
            'severity' => $severity,
            'confidence' => round($confidence, 4),
            'period' => date('Y-m-d'),
            'value' => $value,
            'delta' => $delta,
            'action' => $action,
            'created_at' => date('c'),
        ];
    }

    /**
     * Calculate standard deviation.
     *
     * @param  array<int, float>  $values
     */
    private function calculateStdDev(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / $n;
        $variance = 0.0;

        foreach ($values as $v) {
            $variance += ($v - $mean) ** 2;
        }

        return sqrt($variance / $n);
    }

    /**
     * Get insight configuration with defaults.
     *
     * @return InsightConfig
     */
    private function getInsightConfig(): array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = $this->config->get('zeroboiler.analytics.quick_insights', []);

        return [
            'enabled' => (bool) ($cfg['enabled'] ?? true),
            'max_insights' => (int) ($cfg['max_insights'] ?? self::DEFAULT_MAX_INSIGHTS),
            'spike_threshold' => (float) ($cfg['spike_threshold'] ?? self::DEFAULT_SPIKE_THRESHOLD),
            'drop_threshold' => (float) ($cfg['drop_threshold'] ?? self::DEFAULT_DROP_THRESHOLD),
            'trend_periods' => (int) ($cfg['trend_periods'] ?? self::DEFAULT_TREND_PERIODS),
            'volatility_threshold' => (float) ($cfg['volatility_threshold'] ?? self::DEFAULT_VOLATILITY_THRESHOLD),
            'cache_ttl' => (int) ($cfg['cache_ttl'] ?? self::DEFAULT_CACHE_TTL),
            'ignored_metrics' => (array) ($cfg['ignored_metrics'] ?? []),
        ];
    }

    /**
     * Get a summary of insights.
     *
     * @return array{total: int, by_severity: array<string, int>, by_type: array<string, int>, top_critical: list<Insight>}
     */
    public function summary(): array
    {
        $insights = $this->generateInsights();

        $bySeverity = [];
        $byType = [];

        foreach ($insights as $insight) {
            $sev = $insight['severity'];
            $type = $insight['type'];
            $bySeverity[$sev] = ($bySeverity[$sev] ?? 0) + 1;
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }

        $topCritical = array_filter($insights, static fn(array $i): bool => $i['severity'] === 'critical');

        return [
            'total' => count($insights),
            'by_severity' => $bySeverity,
            'by_type' => $byType,
            'top_critical' => array_values($topCritical),
        ];
    }

    /**
     * Invalidate the insights cache.
     */
    public function invalidateCache(): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'generated');
    }
}
