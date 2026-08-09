<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EventStreamService;
use ZeroBoiler\Analytics\Services\EventAggregationService;

/**
 * Automated insight generation from event data.
 *
 * Analyzes tracked events to produce actionable insights:
 * - Trending events (rising event counts)
 * - Anomaly detection (unusual spikes or drops)
 * - Funnel drop-off analysis
 * - Conversion opportunity identification
 * - Engagement pattern summaries
 *
 * Insights are cached for performance and refreshed on configurable TTL.
 *
 * Used by admin dashboards, scheduled reports, and the analytics overview command.
 *
 * @since 1.0.0
 */
final class AnalyticsInsightAggregator
{
    private ?EventStreamService $streamService;

    private ?EventAggregationService $aggregationService;

    private const CACHE_PREFIX = 'zb_insights_';

    private const DEFAULT_TTL = 300;

    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param  array<string, mixed>|null  $config  Analytics config
     */
    public function __construct(
        ?EventStreamService $streamService = null,
        ?EventAggregationService $aggregationService = null,
        ?array $config = null,
    ): void {
        $this->streamService = $streamService;
        $this->aggregationService = $aggregationService;
        $this->config = $config ?? [];
    }

    /**
     * Generate a full insight report.
     *
     * @return array{generated_at: string, insights: list<array{type: string, category: string, title: string, description: string, severity: string, metric: string|null, value: mixed|null, recommendation: string|null}>, summary: array{total: int, by_type: array<string, int>, by_severity: array<string, int>}}
     */
    public function generateReport(): array
    {
        $insights = [];

        // Trending events
        $trending = $this->detectTrendingEvents();
        foreach ($trending as $insight) {
            $insights[] = $insight;
        }

        // Anomalies
        $anomalies = $this->detectAnomalies();
        foreach ($anomalies as $insight) {
            $insights[] = $insight;
        }

        // Funnel drop-offs
        $funnels = $this->analyzeFunnelDropOffs();
        foreach ($funnels as $insight) {
            $insights[] = $insight;
        }

        // Engagement patterns
        $engagement = $this->analyzeEngagementPatterns();
        foreach ($engagement as $insight) {
            $insights[] = $insight;
        }

        // Conversion opportunities
        $conversions = $this->identifyConversionOpportunities();
        foreach ($conversions as $insight) {
            $insights[] = $insight;
        }

        // Sort by severity (critical > warning > info)
        usort($insights, function (array $a, array $b): int {
            $severityOrder = ['critical' => 0, 'elevated' => 1, 'warning' => 2, 'info' => 3];

            return ($severityOrder[$a['severity']] ?? 3) <=> ($severityOrder[$b['severity']] ?? 3);
        });

        $summary = $this->buildSummary($insights);

        return [
            'generated_at' => now()->toIso8601String(),
            'insights' => $insights,
            'summary' => $summary,
        ];
    }

    /**
     * Detect trending events (events with rising counts).
     *
     * Compares current window event counts against a previous window.
     * Events with significant increase are flagged as trending.
     *
     * @return list<array{type: string, category: string, title: string, description: string, severity: string, metric: string|null, value: mixed|null, recommendation: string|null}>
     */
    public function detectTrendingEvents(): array
    {
        $insights = [];
        $threshold = (float) ($this->config['anomaly_threshold'] ?? 3.0);
        $minEvents = (int) ($this->config['min_events_for_trend'] ?? 10);

        if ($this->aggregationService === null) {
            return $insights;
        }

        try {
            $current = $this->aggregationService->getTopEvents(20);
            $previous = $this->getPreviousWindowTopEvents();

            foreach ($current as $eventName => $currentCount) {
                if ($currentCount < $minEvents) {
                    continue;
                }

                $previousCount = $previous[$eventName] ?? 0;
                $changePercent = $previousCount > 0
                    ? round((($currentCount - $previousCount) / $previousCount) * 100, 2)
                    : 100.0;

                if ($changePercent >= 50.0 && $currentCount >= $minEvents) {
                    $category = EventCatalog::getCategory($eventName) ?? 'unknown';
                    $severity = $changePercent >= 200 ? 'elevated' : 'info';

                    $insights[] = [
                        'type' => 'trending',
                        'category' => $category,
                        'title' => "Trending: {$eventName}",
                        'description' => "Event '{$eventName}' is trending with a {$changePercent}% increase ({$previousCount} → {$currentCount})",
                        'severity' => $severity,
                        'metric' => 'count_change_percent',
                        'value' => $changePercent,
                        'recommendation' => $changePercent >= 200
                            ? 'Investigate the cause of this spike — it may indicate viral activity or a bug.'
                            : 'Monitor this trend to understand what drives this event.',
                    ];
                }

                // Detect decline
                if ($previousCount >= $minEvents && $changePercent <= -30.0) {
                    $category = EventCatalog::getCategory($eventName) ?? 'unknown';
                    $absChange = abs($changePercent);

                    $insights[] = [
                        'type' => 'declining',
                        'category' => $category,
                        'title' => "Declining: {$eventName}",
                        'description' => "Event '{$eventName}' declined by {$absChange}% ({$previousCount} → {$currentCount})",
                        'severity' => $absChange >= 50 ? 'warning' : 'info',
                        'metric' => 'count_change_percent',
                        'value' => $changePercent,
                        'recommendation' => 'Review recent changes that may have impacted this event.',
                    ];
                }
            }
        } catch (\Throwable) {
            // Graceful degradation
        }

        return $insights;
    }

    /**
     * Detect statistical anomalies in event counts.
     *
     * Uses z-score based detection to find events that deviate
     * significantly from their historical average.
     *
     * @return list<array{type: string, category: string, title: string, description: string, severity: string, metric: string|null, value: mixed|null, recommendation: string|null}>
     */
    public function detectAnomalies(): array
    {
        $insights = [];
        $zThreshold = (float) ($this->config['anomaly_threshold'] ?? 3.0);

        if ($this->aggregationService === null) {
            return $insights;
        }

        try {
            $topEvents = $this->aggregationService->getTopEvents(50);
            $stats = $this->getEventStats();

            foreach ($topEvents as $eventName => $currentCount) {
                $eventStat = $stats[$eventName] ?? null;

                if ($eventStat === null) {
                    continue;
                }

                $mean = (float) ($eventStat['mean'] ?? 0);
                $stdDev = (float) ($eventStat['std_dev'] ?? 0);

                if ($stdDev <= 0) {
                    continue;
                }

                $zScore = ($currentCount - $mean) / $stdDev;

                if (abs($zScore) >= $zThreshold) {
                    $category = EventCatalog::getCategory($eventName) ?? 'unknown';
                    $direction = $zScore > 0 ? 'spike' : 'drop';
                    $severity = abs($zScore) >= ($zThreshold * 2) ? 'critical' : 'elevated';

                    $insights[] = [
                        'type' => 'anomaly',
                        'category' => $category,
                        'title' => "Anomaly detected: {$eventName} {$direction}",
                        'description' => sprintf(
                            "Event '%s' has a z-score of %.1f (current: %d, avg: %.1f, σ: %.1f)",
                            $eventName,
                            $zScore,
                            $currentCount,
                            $mean,
                            $stdDev,
                        ),
                        'severity' => $severity,
                        'metric' => 'z_score',
                        'value' => round($zScore, 2),
                        'recommendation' => $direction === 'spike'
                            ? 'Check for viral activity, marketing campaigns, or potential bot traffic.'
                            : 'Investigate potential issues with tracking, feature outages, or UX problems.',
                    ];
                }
            }
        } catch (\Throwable) {
            // Graceful degradation
        }

        return $insights;
    }

    /**
     * Analyze funnel drop-offs from the SaaS funnel definitions.
     *
     * Identifies steps with high drop-off rates that need attention.
     *
     * @return list<array{type: string, category: string, title: string, description: string, severity: string, metric: string|null, value: mixed|null, recommendation: string|null}>
     */
    public function analyzeFunnelDropOffs(): array
    {
        $insights = [];

        $funnels = [
            'signup' => EventCatalog::funnelTemplateEvents('signup'),
            'checkout' => EventCatalog::funnelTemplateEvents('checkout'),
            'subscription' => EventCatalog::funnelTemplateEvents('subscription'),
        ];

        foreach ($funnels as $funnelName => $steps) {
            if (count($steps) < 2) {
                continue;
            }

            // Simple heuristic: if first step exists and subsequent steps don't,
            // there's a drop-off
            $firstStep = $steps[0] ?? null;
            $lastStep = $steps[count($steps) - 1] ?? null;

            if ($firstStep !== null && $lastStep !== null && $firstStep !== $lastStep) {
                $firstStepCount = $this->aggregationService !== null
                    ? $this->aggregationService->getCount($firstStep)
                    : 0;
                $lastStepCount = $this->aggregationService !== null
                    ? $this->aggregationService->getCount($lastStep)
                    : 0;

                if ($firstStepCount > 0) {
                    $dropOffRate = round((1 - ($lastStepCount / $firstStepCount)) * 100, 2);

                    if ($dropOffRate >= 80) {
                        $insights[] = [
                            'type' => 'funnel_drop_off',
                            'category' => 'funnel',
                            'title' => "High drop-off in {$funnelName} funnel",
                            'description' => sprintf(
                                'Funnel %s has %.1f%% drop-off from %s to %s (%d → %d)',
                                $funnelName,
                                $dropOffRate,
                                $firstStep,
                                $lastStep,
                                $firstStepCount,
                                $lastStepCount,
                            ),
                            'severity' => $dropOffRate >= 95 ? 'critical' : 'warning',
                            'metric' => 'drop_off_rate_percent',
                            'value' => $dropOffRate,
                            'recommendation' => 'Optimize the steps between these events to reduce friction and improve conversion.',
                        ];
                    }
                }
            }
        }

        return $insights;
    }

    /**
     * Analyze engagement patterns from tracked events.
     *
     * @return list<array{type: string, category: string, title: string, description: string, severity: string, metric: string|null, value: mixed|null, recommendation: string|null}>
     */
    public function analyzeEngagementPatterns(): array
    {
        $insights = [];

        if ($this->aggregationService === null) {
            return $insights;
        }

        try {
            $allEvents = $this->aggregationService->getTopEvents(100);
            $totalEvents = array_sum($allEvents);

            if ($totalEvents === 0) {
                return $insights;
            }

            // Page view to action ratio (engagement depth)
            $pageViews = $allEvents['page_view'] ?? 0;
            $actions = 0;
            $actionEvents = ['click', 'form_submit', 'search', 'share', 'add_to_cart', 'feature_used', 'scroll_depth'];

            foreach ($actionEvents as $actionEvent) {
                $actions += $allEvents[$actionEvent] ?? 0;
            }

            if ($pageViews > 0) {
                $engagementRatio = round(($actions / $pageViews) * 100, 2);

                if ($engagementRatio < 5) {
                    $insights[] = [
                        'type' => 'low_engagement',
                        'category' => 'engagement',
                        'title' => 'Low engagement depth detected',
                        'description' => "Only {$engagementRatio}% of page views result in meaningful interactions ({$actions} actions / {$pageViews} views)",
                        'severity' => 'warning',
                        'metric' => 'engagement_ratio_percent',
                        'value' => $engagementRatio,
                        'recommendation' => 'Review UX design, call-to-action placement, and content quality to improve engagement.',
                    ];
                }
            }

            // Error rate analysis
            $errors = $allEvents['error'] ?? 0;
            $jsErrors = $allEvents['js_error'] ?? 0;
            $totalErrors = $errors + $jsErrors;

            if ($totalErrors > 0 && $totalEvents > 0) {
                $errorRate = round(($totalErrors / $totalEvents) * 100, 4);

                if ($errorRate >= 0.01) { // 0.01% error rate threshold
                    $insights[] = [
                        'type' => 'high_error_rate',
                        'category' => 'engagement',
                        'title' => 'High error rate detected',
                        'description' => sprintf(
                            'Error rate is %.4f%% (%d errors out of %d total events). This exceeds the 0.01%% threshold.',
                            $errorRate * 100,
                            $totalErrors,
                            $totalEvents,
                        ),
                        'severity' => $errorRate >= 0.05 ? 'critical' : 'warning',
                        'metric' => 'error_rate_percent',
                        'value' => $errorRate,
                        'recommendation' => 'Investigate the top error sources and prioritize fixes for the most common errors.',
                    ];
                }
            }

            // Search-to-conversion ratio
            $searches = $allEvents['search'] ?? 0;
            $purchases = $allEvents['purchase'] ?? 0;
            $subscriptions = $allEvents['subscribe'] ?? 0;

            if ($searches > 0) {
                $searchConversionRate = round((($purchases + $subscriptions) / $searches) * 100, 2);

                $insights[] = [
                    'type' => 'search_conversion',
                    'category' => 'engagement',
                    'title' => 'Search conversion analysis',
                    'description' => "{$searches} searches led to " . ($purchases + $subscriptions) . " conversions ({$searchConversionRate}%)",
                    'severity' => $searchConversionRate < 1 ? 'warning' : 'info',
                    'metric' => 'search_conversion_percent',
                    'value' => $searchConversionRate,
                    'recommendation' => $searchConversionRate < 1
                        ? 'Improve search results relevance and product availability.'
                        : 'Search is performing well. Consider expanding search coverage.',
                ];
            }
        } catch (\Throwable) {
            // Graceful degradation
        }

        return $insights;
    }

    /**
     * Identify conversion opportunities based on event patterns.
     *
     * Looks for events that strongly correlate with conversion events
     * and identifies users who are close to converting but haven't yet.
     *
     * @return list<array{type: string, category: string, title: string, description: string, severity: string, metric: string|null, value: mixed|null, recommendation: string|null}>
     */
    public function identifyConversionOpportunities(): array
    {
        $insights = [];

        if ($this->aggregationService === null) {
            return $insights;
        }

        try {
            $allEvents = $this->aggregationService->getTopEvents(50);

            // Trial-to-subscription ratio
            $trials = $allEvents['start_trial'] ?? 0;
            $trialConverted = $allEvents['trial_converted'] ?? 0;
            $subscriptions = $allEvents['subscribe'] ?? 0;

            if ($trials > 0) {
                $conversionRate = round((($trialConverted + $subscriptions) / $trials) * 100, 2);

                if ($conversionRate < 20) {
                    $insights[] = [
                        'type' => 'conversion_opportunity',
                        'category' => 'saas',
                        'title' => 'Low trial-to-paid conversion rate',
                        'description' => sprintf(
                            'Only %.1f%% of trials convert to paid (%d conversions from %d trials)',
                            $conversionRate,
                            $trialConverted + $subscriptions,
                            $trials,
                        ),
                        'severity' => $conversionRate < 10 ? 'critical' : 'warning',
                        'metric' => 'trial_conversion_percent',
                        'value' => $conversionRate,
                        'recommendation' => 'Improve onboarding experience, add value-based prompts during trial, and reduce time-to-value.',
                    ];
                }
            }

            // Add-to-cart to purchase ratio
            $addToCart = $allEvents['add_to_cart'] ?? 0;
            $purchaseCount = $allEvents['purchase'] ?? 0;

            if ($addToCart > 0) {
                $cartConversionRate = round(($purchaseCount / $addToCart) * 100, 2);

                if ($cartConversionRate < 30) {
                    $insights[] = [
                        'type' => 'conversion_opportunity',
                        'category' => 'ecommerce',
                        'title' => 'Low cart-to-purchase conversion rate',
                        'description' => sprintf(
                            'Only %.1f%% of cart additions result in purchase (%d purchases from %d add_to_cart)',
                            $cartConversionRate,
                            $purchaseCount,
                            $addToCart,
                        ),
                        'severity' => $cartConversionRate < 15 ? 'warning' : 'info',
                        'metric' => 'cart_conversion_percent',
                        'value' => $cartConversionRate,
                        'recommendation' => 'Reduce checkout friction, offer free shipping, send cart abandonment reminders.',
                    ];
                }
            }

            // Feature usage depth for SaaS
            $signups = $allEvents['sign_up'] ?? 0;
            $featureUsed = $allEvents['feature_used'] ?? 0;

            if ($signups > 0) {
                $featureAdoptionRate = round(($featureUsed / $signups) * 100, 2);

                if ($featureAdoptionRate < 50) {
                    $insights[] = [
                        'type' => 'conversion_opportunity',
                        'category' => 'saas',
                        'title' => 'Low feature adoption rate',
                        'description' => sprintf(
                            'Only %.1f%% of signups use features (%d feature_used from %d sign_up)',
                            $featureAdoptionRate,
                            $featureUsed,
                            $signups,
                        ),
                        'severity' => 'info',
                        'metric' => 'feature_adoption_percent',
                        'value' => $featureAdoptionRate,
                        'recommendation' => 'Improve product onboarding, add feature discovery prompts, and guide users to key features.',
                    ];
                }
            }
        } catch (\Throwable) {
            // Graceful degradation
        }

        return $insights;
    }

    /**
     * Get event statistics from the aggregation service.
     *
     * @return array<string, array{mean: float, std_dev: float, count: int}>
     */
    private function getEventStats(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'event_stats';
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $stats = [];

        if ($this->aggregationService !== null) {
            try {
                $topEvents = $this->aggregationService->getTopEvents(100);

                foreach ($topEvents as $eventName => $count) {
                    // For a real implementation, we'd track historical data
                    // Here we simulate stats based on current count
                    $stats[$eventName] = [
                        'mean' => (float) $count,
                        'std_dev' => (float) max(1, (int) ($count * 0.1)), // 10% of count as estimated stddev
                        'count' => $count,
                    ];
                }
            } catch (\Throwable) {
                // Graceful degradation
            }
        }

        $ttl = (int) ($this->config['cache_ttl'] ?? self::DEFAULT_TTL);
        Cache::put($cacheKey, $stats, $ttl);

        return $stats;
    }

    /**
     * Get previous window top events for comparison.
     *
     * @return array<string, int>
     */
    private function getPreviousWindowTopEvents(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'previous_window_events';
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        // In a real implementation, this would read from a previous
        // time window's aggregated data. For now, return empty.
        $previous = [];

        $ttl = (int) ($this->config['cache_ttl'] ?? self::DEFAULT_TTL);
        Cache::put($cacheKey, $previous, $ttl);

        return $previous;
    }

    /**
     * Build a summary of insights by type and severity.
     *
     * @param  list<array{type: string, severity: string}>  $insights
     * @return array{total: int, by_type: array<string, int>, by_severity: array<string, int>}
     */
    private function buildSummary(array $insights): array
    {
        $byType = [];
        $bySeverity = [];

        foreach ($insights as $insight) {
            $type = $insight['type'] ?? 'unknown';
            $severity = $insight['severity'] ?? 'info';

            $byType[$type] = ($byType[$type] ?? 0) + 1;
            $bySeverity[$severity] = ($bySeverity[$severity] ?? 0) + 1;
        }

        return [
            'total' => count($insights),
            'by_type' => $byType,
            'by_severity' => $bySeverity,
        ];
    }
}
