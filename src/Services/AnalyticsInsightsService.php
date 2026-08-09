<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\DTO\AnalyticsInsight;

/**
 * Automated analytics insight generation service.
 *
 * Analyzes aggregated event data to produce actionable insights about
 * user behavior patterns, trending events, funnel drop-offs, conversion
 * opportunities, and anomalies. Designed for admin dashboards, scheduled
 * reports, and proactive alerting.
 *
 * Configuration is read from `zeroboiler.analytics.insights`.
 *
 * @phpstan-type InsightConfig array{enabled?: bool, cache_ttl?: int, min_events_for_trend?: int, anomaly_threshold?: float, max_insights?: int, trend_window_hours?: int}
 *
 * @since 1.0.0
 */
final class AnalyticsInsightsService
{
    private readonly bool $enabled;

    private readonly int $cacheTtl;

    private readonly int $minEventsForTrend;

    private readonly float $anomalyThreshold;

    private readonly int $maxInsights;

    private readonly int $trendWindowHours;

    private const CACHE_PREFIX = 'zb_insights_';

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config): void
    {
        $insightsConfig = $config->get('zeroboiler.analytics.insights', []);
        /** @var InsightConfig $insightsConfig */

        $this->enabled = (bool) ($insightsConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($insightsConfig['cache_ttl'] ?? 300); // 5 minutes
        $this->minEventsForTrend = (int) ($insightsConfig['min_events_for_trend'] ?? 10);
        $this->anomalyThreshold = (float) ($insightsConfig['anomaly_threshold'] ?? 3.0);
        $this->maxInsights = (int) ($insightsConfig['max_insights'] ?? 20);
        $this->trendWindowHours = (int) ($insightsConfig['trend_window_hours'] ?? 24);
    }

    /**
     * Generate insights from aggregated event data.
     *
     * Analyzes event counts, trends, and patterns to produce a ranked
     * list of actionable insights.
     *
     * @param  array<string, int>  $currentCounts  Current event name → count mapping
     * @param  array<string, int>  $previousCounts  Previous period event name → count mapping
     * @param  array{total_events?: int, unique_users?: int, avg_session_duration?: float}  $context  Additional context
     * @return list<AnalyticsInsight>
     */
    public function generate(array $currentCounts = [], array $previousCounts = [], array $context = []): array
    {
        if (! $this->enabled) {
            return [];
        }

        $insights = [];

        // Trending events (significant count increase)
        $trending = $this->detectTrendingEvents($currentCounts, $previousCounts);
        foreach ($trending as $insight) {
            $insights[] = $insight;
        }

        // Declining events (significant count decrease)
        $declining = $this->detectDecliningEvents($currentCounts, $previousCounts);
        foreach ($declining as $insight) {
            $insights[] = $insight;
        }

        // Anomaly detection (unusual spikes)
        $anomalies = $this->detectAnomalies($currentCounts, $previousCounts);
        foreach ($anomalies as $insight) {
            $insights[] = $insight;
        }

        // Conversion opportunity insights
        $opportunities = $this->detectOpportunities($currentCounts, $previousCounts, $context);
        foreach ($opportunities as $insight) {
            $insights[] = $insight;
        }

        // Sort by score descending
        usort($insights, fn (AnalyticsInsight $a, AnalyticsInsight $b): int => $b->score <=> $a->score);

        return array_slice($insights, 0, $this->maxInsights);
    }

    /**
     * Detect trending events (significant increase in count).
     *
     * @param  array<string, int>  $current
     * @param  array<string, int>  $previous
     * @return list<AnalyticsInsight>
     */
    public function detectTrendingEvents(array $current, array $previous): array
    {
        $insights = [];
        $allEvents = array_unique(array_merge(array_keys($current), array_keys($previous)));

        foreach ($allEvents as $eventName) {
            $currentCount = $current[$eventName] ?? 0;
            $previousCount = $previous[$eventName] ?? 0;

            if ($currentCount < $this->minEventsForTrend) {
                continue;
            }

            if ($previousCount === 0 && $currentCount > 0) {
                // New event — notable but not necessarily trending
                if ($currentCount >= $this->minEventsForTrend * 2) {
                    $insights[] = new AnalyticsInsight(
                        type: 'trending',
                        title: "New event: {$eventName}",
                        description: "Event '{$eventName}' appeared with {$currentCount} occurrences. Previously untracked.",
                        score: min(0.9, $currentCount / 100),
                        eventName: $eventName,
                        metadata: [
                            'current_count' => $currentCount,
                            'previous_count' => 0,
                            'change_pct' => 100.0,
                        ],
                        recommendation: "Monitor '{$eventName}' to establish a baseline over the next 24-48 hours.",
                    );
                }

                continue;
            }

            $changePercent = $previousCount > 0
                ? (($currentCount - $previousCount) / $previousCount) * 100
                : 0;

            if ($changePercent >= 50.0 && $currentCount - $previousCount >= 5) {
                $score = min(1.0, $changePercent / 200);
                $direction = $changePercent >= 100 ? 'surged' : 'increased';

                $insights[] = new AnalyticsInsight(
                    type: 'trending',
                    title: "Event {$direction}: {$eventName}",
                    description: "'{$eventName}' {$direction} by " . round($changePercent, 1) . "% ({$previousCount} → {$currentCount})",
                    score: $score,
                    eventName: $eventName,
                    metadata: [
                        'current_count' => $currentCount,
                        'previous_count' => $previousCount,
                        'change_pct' => round($changePercent, 1),
                        'change_absolute' => $currentCount - $previousCount,
                    ],
                    recommendation: $changePercent >= 200
                        ? "Investigate possible cause for {$eventName} surge — may indicate a promotion, bug, or viral content."
                        : "Continue monitoring '{$eventName}' trend. Consider if this aligns with expected behavior.",
                );
            }
        }

        return $insights;
    }

    /**
     * Detect declining events (significant decrease in count).
     *
     * @param  array<string, int>  $current
     * @param  array<string, int>  $previous
     * @return list<AnalyticsInsight>
     */
    public function detectDecliningEvents(array $current, array $previous): array
    {
        $insights = [];

        foreach ($previous as $eventName => $previousCount) {
            $currentCount = $current[$eventName] ?? 0;

            if ($previousCount < $this->minEventsForTrend) {
                continue;
            }

            $changePercent = (($currentCount - $previousCount) / $previousCount) * 100;

            if ($changePercent <= -30.0 && $previousCount - $currentCount >= 5) {
                $score = min(1.0, abs($changePercent) / 150);

                $insights[] = new AnalyticsInsight(
                    type: 'warning',
                    title: "Event declining: {$eventName}",
                    description: "'{$eventName}' decreased by " . round(abs($changePercent), 1) . "% ({$previousCount} → {$currentCount})",
                    score: $score,
                    eventName: $eventName,
                    metadata: [
                        'current_count' => $currentCount,
                        'previous_count' => $previousCount,
                        'change_pct' => round($changePercent, 1),
                    ],
                    recommendation: "Investigate potential causes: feature removal, UI change, tracking bug, or seasonal variation.",
                );
            }
        }

        return $insights;
    }

    /**
     * Detect statistical anomalies in event counts.
     *
     * Uses z-score based detection to identify events with unusually
     * high or low counts compared to historical patterns.
     *
     * @param  array<string, int>  $current
     * @param  array<string, int>  $previous
     * @return list<AnalyticsInsight>
     */
    public function detectAnomalies(array $current, array $previous): array
    {
        $insights = [];

        // Calculate mean and std dev from combined data
        $allValues = [];
        foreach ($previous as $count) {
            $allValues[] = $count;
        }
        foreach ($current as $count) {
            $allValues[] = $count;
        }

        if (count($allValues) < 5) {
            return $insights;
        }

        $mean = array_sum($allValues) / count($allValues);
        $variance = array_sum(array_map(
            fn (int $v): float => pow($v - $mean, 2),
            $allValues,
        )) / count($allValues);
        $stdDev = sqrt($variance);

        if ($stdDev <= 0) {
            return $insights;
        }

        foreach ($current as $eventName => $count) {
            $zScore = ($count - $mean) / $stdDev;

            if (abs($zScore) >= $this->anomalyThreshold) {
                $direction = $zScore > 0 ? 'spike' : 'drop';
                $score = min(1.0, abs($zScore) / 5.0);

                $insights[] = new AnalyticsInsight(
                    type: 'anomaly',
                    title: "Anomaly detected: {$eventName} ({$direction})",
                    description: "'{$eventName}' has a z-score of " . round($zScore, 2) . " (count: {$count}, mean: " . round($mean, 1) . ')',
                    score: $score,
                    eventName: $eventName,
                    metadata: [
                        'count' => $count,
                        'mean' => round($mean, 1),
                        'std_dev' => round($stdDev, 1),
                        'z_score' => round($zScore, 2),
                        'direction' => $direction,
                    ],
                    recommendation: abs($zScore) >= 4
                        ? "Critical anomaly in '{$eventName}'. Immediately investigate — may indicate a system issue, promotion, or data quality problem."
                        : "Notable anomaly in '{$eventName}'. Review recent changes that may have affected this metric.",
                );
            }
        }

        return $insights;
    }

    /**
     * Detect conversion opportunities from funnel-like event patterns.
     *
     * Identifies events where significant drop-off occurs between
     * related events (e.g. page_view → form_start → form_submit).
     *
     * @param  array<string, int>  $current
     * @param  array<string, int>  $previous
     * @param  array{total_events?: int, unique_users?: int, avg_session_duration?: float}  $context
     * @return list<AnalyticsInsight>
     */
    public function detectOpportunities(array $current, array $previous, array $context = []): array
    {
        $insights = [];

        // Predefined funnel patterns to check
        $funnelPatterns = [
            ['view_item', 'add_to_cart', 'purchase'],
            ['page_view', 'form_start', 'form_submit'],
            ['sign_up', 'start_trial', 'subscribe'],
            ['begin_checkout', 'add_payment_info', 'purchase'],
            ['sign_up', 'feature_used', 'subscribe'],
        ];

        foreach ($funnelPatterns as $steps) {
            $stepCounts = [];
            foreach ($steps as $step) {
                $stepCounts[$step] = $current[$step] ?? 0;
            }

            // Check for significant drop-off between consecutive steps
            for ($i = 0; $i < count($steps) - 1; $i++) {
                $fromCount = $stepCounts[$steps[$i]];
                $toCount = $stepCounts[$steps[$i + 1]];

                if ($fromCount < $this->minEventsForTrend) {
                    continue;
                }

                $conversionRate = $fromCount > 0 ? $toCount / $fromCount : 0;
                $dropOffRate = 1.0 - $conversionRate;

                if ($dropOffRate >= 0.7 && $fromCount - $toCount >= 10) {
                    $score = min(1.0, $dropOffRate * 0.8);
                    $improvementPotential = $toCount > 0
                        ? (int) round(($fromCount - $toCount) * 0.1)
                        : $fromCount;

                    $insights[] = new AnalyticsInsight(
                        type: 'opportunity',
                        title: "Funnel drop-off: {$steps[$i]} → {$steps[$i + 1]}",
                        description: round($dropOffRate * 100, 1) . '% drop-off between '{$steps[$i]}' ({$fromCount}) and '{$steps[$i + 1]}' ({$toCount}). Est. +{$improvementPotential} conversions if improved by 10%.',
                        score: $score,
                        eventName: $steps[$i],
                        metadata: [
                            'from_event' => $steps[$i],
                            'to_event' => $steps[$i + 1],
                            'from_count' => $fromCount,
                            'to_count' => $toCount,
                            'drop_off_rate' => round($dropOffRate, 4),
                            'improvement_potential' => $improvementPotential,
                            'funnel_steps' => $steps,
                        ],
                        recommendation: "Investigate the '{$steps[$i]} → {$steps[$i + 1]}' transition. Consider UX improvements, reducing friction, or adding social proof.",
                    );
                }
            }
        }

        return $insights;
    }

    /**
     * Generate a summary of insights grouped by type.
     *
     * @param  list<AnalyticsInsight>  $insights
     * @return array{total: int, by_type: array<string, int>, avg_score: float, top_insight: AnalyticsInsight|null, critical_count: int}
     */
    public function summarize(array $insights = []): array
    {
        $byType = [];
        $totalScore = 0.0;
        $criticalCount = 0;
        $topInsight = null;

        foreach ($insights as $insight) {
            $type = $insight->type;
            $byType[$type] = ($byType[$type] ?? 0) + 1;
            $totalScore += $insight->score;

            if ($insight->score >= 0.8) {
                $criticalCount++;
            }

            if ($topInsight === null || $insight->score > $topInsight->score) {
                $topInsight = $insight;
            }
        }

        return [
            'total' => count($insights),
            'by_type' => $byType,
            'avg_score' => count($insights) > 0 ? round($totalScore / count($insights), 4) : 0.0,
            'top_insight' => $topInsight?->toArray(),
            'critical_count' => $criticalCount,
        ];
    }

    /**
     * Clear the insights cache.
     */
    public function clearCache(): void
    {
        try {
            Cache::forget(self::CACHE_PREFIX . 'generated');
        } catch (\Throwable) {
            // Silently fail
        }
    }

    /**
     * Check if the insights service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
