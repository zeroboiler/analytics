<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * AI-powered analytics intelligence service.
 *
 * Provides intelligent event analysis, anomaly detection with ML-inspired
 * heuristics, smart event suggestions, automated insight generation,
 * and predictive recommendations for SaaS products.
 *
 * All analysis is cache-backed and self-contained (no external AI API
 * dependencies). Uses statistical methods: z-score anomaly detection,
 * moving averages, trend analysis, and pattern recognition.
 *
 * Configuration is read from `zeroboiler.analytics.ai`.
 *
 * @phpstan-type AnomalyRecord array{event_name: string, expected: float, actual: float, z_score: float, severity: 'low'|'medium'|'high'|'critical', detected_at: string}
 * @phpstan-type SmartInsight array{type: string, title: string, description: string, confidence: float, action_items: list<string>, affected_events?: list<string>, metric?: string}
 * @phpstan-type TrendPoint array{timestamp: string, value: float, moving_avg: float, lower_bound: float, upper_bound: float}
 *
 * @since 1.0.0
 */
final class AnalyticsAIService
{
    /** @var array<string, float> Rolling event count buffer */
    private array $rollingBuffer = [];

    private int $cacheTtl;

    private string $cachePrefix;

    private int $anomalyWindow;

    private float $anomalyThreshold;

    private int $rollingWindowSize;

    private bool $enabled;

    private const CACHE_PREFIX = 'zb_ai_';

    private const DEFAULT_ANOMALY_THRESHOLD = 2.0;

    private const DEFAULT_ROLLING_WINDOW = 60;

    private const DEFAULT_CACHE_TTL = 300;

    /**
     * @param  ConfigRepository  $config  Analytics configuration
     * @param  CacheRepository  $cache  Application cache
     */
    public function __construct(ConfigRepository $config, CacheRepository $cache){
        $aiConfig = $config->get('zeroboiler.analytics.ai', []);
        /** @var array{enabled?: bool, cache_ttl?: int, anomaly_threshold?: float, anomaly_window?: int, rolling_window?: int} $aiConfig */

        $this->enabled = (bool) ($aiConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($aiConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->cachePrefix = self::CACHE_PREFIX;
        $this->anomalyThreshold = (float) ($aiConfig['anomaly_threshold'] ?? self::DEFAULT_ANOMALY_THRESHOLD);
        $this->anomalyWindow = (int) ($aiConfig['anomaly_window'] ?? 30);
        $this->rollingWindowSize = (int) ($aiConfig['rolling_window'] ?? self::DEFAULT_ROLLING_WINDOW);
        $this->cache = $cache;
    }

    /**
     * Check if the AI service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Analyze a single event value for anomalies using z-score detection.
     *
     * Compares the given value against a rolling window of historical values.
     * Returns null if there isn't enough data for analysis (min 5 data points).
     *
     * @param  string  $eventName  The event name to analyze
     * @param  float  $value  The numeric value to check (count, value, etc.)
     * @return AnomalyRecord|null Anomaly details or null if no anomaly detected
     */
    public function detectAnomaly(string $eventName, float $value): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        $bufferKey = $eventName;
        if (! isset($this->rollingBuffer[$bufferKey])) {
            $this->rollingBuffer[$bufferKey] = [];
        }

        $this->rollingBuffer[$bufferKey][] = $value;

        // Keep rolling window bounded
        if (count($this->rollingBuffer[$bufferKey]) > $this->rollingWindowSize) {
            array_shift($this->rollingBuffer[$bufferKey]);
        }

        $window = $this->rollingBuffer[$bufferKey];
        $count = count($window);

        // Need at least 5 data points for meaningful z-score
        if ($count < 5) {
            return null;
        }

        $mean = array_sum($window) / $count;
        $variance = array_sum(array_map(
            fn (float $v): float => ($v - $mean) ** 2,
            $window,
        )) / $count;
        $stdDev = sqrt($variance);

        // Avoid division by zero
        if ($stdDev < 0.0001) {
            return null;
        }

        $zScore = ($value - $mean) / $stdDev;

        if (abs($zScore) < $this->anomalyThreshold) {
            return null;
        }

        $severity = $this->classifySeverity(abs($zScore));

        return [
            'event_name' => $eventName,
            'expected' => round($mean, 4),
            'actual' => round($value, 4),
            'z_score' => round($zScore, 4),
            'severity' => $severity,
            'detected_at' => date('c'),
        ];
    }

    /**
     * Detect anomalies across multiple event streams.
     *
     * @param  array<string, float>  $eventValues  Map of event name → current value
     * @return list<AnomalyRecord> Detected anomalies
     */
    public function detectBatchAnomalies(array $eventValues): array
    {
        if (! $this->enabled) {
            return [];
        }

        $anomalies = [];

        foreach ($eventValues as $eventName => $value) {
            $anomaly = $this->detectAnomaly($eventName, (float) $value);
            if ($anomaly !== null) {
                $anomalies[] = $anomaly;
            }
        }

        usort($anomalies, function (array $a, array $b): int {
            $severityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

            return ($severityOrder[$a['severity']] ?? 4) <=> ($severityOrder[$b['severity']] ?? 4);
        });

        return $anomalies;
    }

    /**
     * Generate smart insights from event data patterns.
     *
     * Analyzes event counts, trends, and distributions to produce
     * actionable insights with confidence scores and recommendations.
     *
     * @param  array{events: array<string, int>, period?: string}  $data  Event data for analysis
     * @return list<SmartInsight> Generated insights
     */
    public function generateInsights(array $data): array
    {
        if (! $this->enabled) {
            return [];
        }

        $events = $data['events'] ?? [];
        $insights = [];

        if (empty($events)) {
            return $insights;
        }

        // Total event volume analysis
        $totalEvents = array_sum($events);
        $uniqueEvents = count($events);
        $avgEventsPerType = $uniqueEvents > 0 ? $totalEvents / $uniqueEvents : 0;

        // High-volume events (>3x average)
        $highVolume = [];
        $lowVolume = [];
        foreach ($events as $name => $count) {
            if ($count > $avgEventsPerType * 3) {
                $highVolume[] = ['name' => $name, 'count' => $count];
            } elseif ($count < $avgEventsPerType * 0.3 && $avgEventsPerType > 0) {
                $lowVolume[] = ['name' => $name, 'count' => $count];
            }
        }

        if (! empty($highVolume)) {
            usort($highVolume, fn (array $a, array $b): int => $b['count'] <=> $a['count']);
            $insights[] = [
                'type' => 'volume_spike',
                'title' => 'High-Volume Events Detected',
                'description' => sprintf(
                    '%d event type(s) significantly exceed the average volume of %.0f events/type.',
                    count($highVolume),
                    $avgEventsPerType,
                ),
                'confidence' => 0.85,
                'action_items' => [
                    'Consider sampling high-volume events to reduce costs.',
                    'Verify these events are not duplicated by client-side tracking.',
                    'Check if rate limiting is properly configured.',
                ],
                'affected_events' => array_map(fn (array $e): string => $e['name'], array_slice($highVolume, 0, 5)),
                'metric' => 'volume_ratio',
            ];
        }

        if (! empty($lowVolume)) {
            $insights[] = [
                'type' => 'low_engagement',
                'title' => 'Low-Volume Events Detected',
                'description' => sprintf(
                    '%d event type(s) have significantly below-average volume. These may need instrumentation review.',
                    count($lowVolume),
                ),
                'confidence' => 0.70,
                'action_items' => [
                    'Verify tracking code is properly installed for these events.',
                    'Check if these events are gated behind feature flags that are disabled.',
                    'Consider removing if no longer relevant to business goals.',
                ],
                'affected_events' => array_map(fn (array $e): string => $e['name'], array_slice($lowVolume, 0, 5)),
                'metric' => 'volume_ratio',
            ];
        }

        // SaaS-specific insights: check for core lifecycle events
        $saasCore = ['sign_up', 'login', 'start_trial', 'subscribe', 'purchase', 'cancellation'];
        $missingCore = array_diff($saasCore, array_keys($events));

        if (! empty($missingCore)) {
            $insights[] = [
                'type' => 'missing_lifecycle',
                'title' => 'Missing Core SaaS Events',
                'description' => sprintf(
                    'Essential SaaS lifecycle events are not being tracked: %s. These are critical for funnel analysis and revenue attribution.',
                    implode(', ', $missingCore),
                ),
                'confidence' => 0.95,
                'action_items' => [
                    'Implement server-side tracking for missing lifecycle events.',
                    'Use ServerSideTracker with LifecycleEventMapper for automatic mapping.',
                    'Add client-side tracking as a supplement for engagement events.',
                ],
                'affected_events' => array_values($missingCore),
            ];
        }

        return $insights;
    }

    /**
     * Calculate trend direction and velocity for a metric.
     *
     * Uses simple linear regression on the provided data points
     * to determine if a metric is trending up, down, or flat.
     *
     * @param  list<float>  $values  Time-ordered numeric values
     * @return array{direction: 'up'|'down'|'flat', slope: float, velocity_percent: float, confidence: float} Trend analysis
     */
    public function analyzeTrend(array $values): array
    {
        $n = count($values);

        if ($n < 2) {
            return ['direction' => 'flat', 'slope' => 0.0, 'velocity_percent' => 0.0, 'confidence' => 0.0];
        }

        // Simple linear regression: y = slope * x + intercept
        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumX2 = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $x = (float) $i;
            $y = $values[$i];
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }

        $denominator = $n * $sumX2 - $sumX * $sumX;
        $slope = $denominator !== 0.0 ? ($n * $sumXY - $sumX * $sumY) / $denominator : 0.0;

        $mean = $sumY / $n;
        $velocityPercent = $mean > 0 ? ($slope / $mean) * 100 : 0.0;

        // R-squared for confidence
        $intercept = ($sumY - $slope * $sumX) / $n;
        $ssTotal = 0.0;
        $ssResidual = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $predicted = $slope * $i + $intercept;
            $ssTotal += ($values[$i] - $mean) ** 2;
            $ssResidual += ($values[$i] - $predicted) ** 2;
        }

        $rSquared = $ssTotal > 0 ? 1 - ($ssResidual / $ssTotal) : 0.0;

        $direction = abs($slope) < 0.01 ? 'flat' : ($slope > 0 ? 'up' : 'down');

        return [
            'direction' => $direction,
            'slope' => round($slope, 6),
            'velocity_percent' => round($velocityPercent, 2),
            'confidence' => round($rSquared, 4),
        ];
    }

    /**
     * Suggest which events to track based on catalog and current tracking.
     *
     * Compares the full EventCatalog against currently tracked events
     * and recommends high-value additions.
     *
     * @param  list<string>  $trackedEvents  Currently tracked event names
     * @param  string|null  $focus  Optional focus area: 'ecommerce', 'saas', 'engagement'
     * @return array{recommended: list<array{name: string, category: string, reason: string}>, coverage_percent: float, total_catalog: int, tracked_count: int}
     */
    public function suggestEvents(array $trackedEvents, ?string $focus = null): array
    {
        $catalogNames = \ZeroBoiler\Analytics\Events\EventCatalog::names();
        $tracked = array_flip($trackedEvents);
        $totalCatalog = count($catalogNames);

        $missing = [];
        foreach ($catalogNames as $name) {
            if (! isset($tracked[$name])) {
                $category = \ZeroBoiler\Analytics\Events\EventCatalog::getCategory($name) ?? 'unknown';
                $reason = $this->getRecommendationReason($name, $category);
                $missing[] = [
                    'name' => $name,
                    'category' => $category,
                    'reason' => $reason,
                ];
            }
        }

        if ($focus !== null) {
            $missing = array_filter($missing, fn (array $e): bool => $e['category'] === $focus);
        }

        usort($missing, function (array $a, array $b): int {
            $priority = [
                'saas' => 0,
                'ecommerce' => 1,
                'engagement' => 2,
                'unknown' => 3,
            ];

            return ($priority[$a['category']] ?? 4) <=> ($priority[$b['category']] ?? 4);
        });

        $trackedCount = count($trackedEvents);
        $coveragePercent = $totalCatalog > 0 ? ($trackedCount / $totalCatalog) * 100 : 0.0;

        return [
            'recommended' => array_values($missing),
            'coverage_percent' => round($coveragePercent, 1),
            'total_catalog' => $totalCatalog,
            'tracked_count' => $trackedCount,
        ];
    }

    /**
     * Get a summary of the AI service status and configuration.
     *
     * @return array{enabled: bool, anomaly_threshold: float, rolling_window: int, anomaly_window: int, cache_ttl: int, buffer_size: int}
     */
    public function getStatus(): array
    {
        return [
            'enabled' => $this->enabled,
            'anomaly_threshold' => $this->anomalyThreshold,
            'rolling_window' => $this->rollingWindowSize,
            'anomaly_window' => $this->anomalyWindow,
            'cache_ttl' => $this->cacheTtl,
            'buffer_size' => count($this->rollingBuffer),
        ];
    }

    /**
     * Clear the rolling buffer for a specific event or all events.
     */
    public function clearBuffer(?string $eventName = null): void
    {
        if ($eventName !== null) {
            unset($this->rollingBuffer[$eventName]);
        } else {
            $this->rollingBuffer = [];
        }
    }

    /**
     * Classify anomaly severity based on z-score magnitude.
     *
     * @return 'low'|'medium'|'high'|'critical'
     */
    private function classifySeverity(float $absoluteZScore): string
    {
        if ($absoluteZScore >= 4.0) {
            return 'critical';
        }

        if ($absoluteZScore >= 3.0) {
            return 'high';
        }

        if ($absoluteZScore >= $this->anomalyThreshold) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Get a recommendation reason for an untracked event.
     */
    private function getRecommendationReason(string $eventName, string $category): string
    {
        $highPrioritySaas = ['sign_up', 'login', 'start_trial', 'subscribe', 'purchase', 'cancellation', 'plan_upgrade'];
        $highPriorityEcommerce = ['view_item', 'add_to_cart', 'begin_checkout', 'purchase', 'refund'];
        $highPriorityEngagement = ['page_view', 'scroll_depth', 'form_submit', 'error', 'search'];

        $highPriority = match ($category) {
            'saas' => $highPrioritySaas,
            'ecommerce' => $highPriorityEcommerce,
            'engagement' => $highPriorityEngagement,
            default => [],
        };

        if (in_array($eventName, $highPriority, true)) {
            return 'High-priority core event for your business model.';
        }

        return "Useful for comprehensive {$category} analytics coverage.";
    }
}
