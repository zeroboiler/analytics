<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Anomaly detection service for analytics event monitoring.
 *
 * Monitors event dispatch rates and detects statistically significant
 * deviations from baseline patterns. Supports per-event-type monitoring,
 * configurable thresholds, and alert dispatch.
 *
 * Uses a sliding window approach with mean and standard deviation
 * to detect anomalies without requiring external ML dependencies.
 *
 * @see \ZeroBoiler\Analytics\AnalyticsMetrics
 *
 * @since 1.0.0
 */
final class AnomalyDetectionService
{
    /** @var int Sliding window size in seconds */
    private int $windowSize;

    /** @var float Standard deviation multiplier for anomaly threshold */
    private float $sensitivity;

    /** @var int Minimum data points before anomaly detection activates */
    private int $minDataPoints;

    /** @var array<string, list<int>> Event name → timestamps in current window */
    private array $eventTimeline = [];

    /** @var array<string, list<int>> Event name → historical window counts (for baseline) */
    private array $baselineWindows = [];

    /** @var int Maximum baseline windows to keep */
    private int $maxBaselineWindows;

    /** @var list<array{event: string, rate: float, expected: float, deviation: float, timestamp: string, severity: string}> */
    private array $recentAnomalies = [];

    /** @var int Maximum recent anomalies to keep */
    private int $maxRecentAnomalies;

    private AnalyticsManager $manager;

    private AnalyticsMetrics $metrics;

    private QueuedAnalyticsDispatcher $queue;

    private bool $dispatchAlerts;

    /**
     * @param  AnalyticsManager  $manager
     * @param  AnalyticsMetrics  $metrics
     * @param  QueuedAnalyticsDispatcher  $queue
     * @param  int  $windowSize  Sliding window in seconds (default: 60)
     * @param  float  $sensitivity  Std dev multiplier (default: 2.0 = 95% confidence)
     * @param  int  $minDataPoints  Minimum data points before detection (default: 5)
     * @param  int  $maxBaselineWindows  Max historical windows to keep (default: 60)
     * @param  int  $maxRecentAnomalies  Max recent anomalies stored (default: 100)
     * @param  bool  $dispatchAlerts  Whether to dispatch anomaly events (default: true)
     */
    public function __construct(
        AnalyticsManager $manager,
        AnalyticsMetrics $metrics,
        QueuedAnalyticsDispatcher $queue,
        int $windowSize = 60,
        float $sensitivity = 2.0,
        int $minDataPoints = 5,
        int $maxBaselineWindows = 60,
        int $maxRecentAnomalies = 100,
        bool $dispatchAlerts = true,
    ): void {
        $this->manager = $manager;
        $this->metrics = $metrics;
        $this->queue = $queue;
        $this->windowSize = $windowSize;
        $this->sensitivity = $sensitivity;
        $this->minDataPoints = $minDataPoints;
        $this->maxBaselineWindows = $maxBaselineWindows;
        $this->maxRecentAnomalies = $maxRecentAnomalies;
        $this->dispatchAlerts = $dispatchAlerts;
    }

    /**
     * Record an event occurrence for anomaly detection.
     *
     * Appends the event timestamp to the current window and checks
     * for anomalies after each recording.
     *
     * @param  string  $eventName  Analytics event name
     * @param  int|null  $timestamp  Unix timestamp (defaults to now)
     */
    public function record(string $eventName, ?int $timestamp = null): void
    {
        $ts = $timestamp ?? time();

        if (! isset($this->eventTimeline[$eventName])) {
            $this->eventTimeline[$eventName] = [];
        }

        $this->eventTimeline[$eventName][] = $ts;
    }

    /**
     * Rotate the sliding window and check for anomalies.
     *
     * Should be called periodically (e.g. via a scheduled command
     * or on each window boundary). Stores the current window count
     * as a baseline and checks for deviations.
     *
     * @return list<array{event: string, rate: float, expected: float, deviation: float, timestamp: string, severity: string}>
     */
    public function rotateAndCheck(): array
    {
        $now = time();
        $windowStart = $now - $this->windowSize;
        $detectedAnomalies = [];

        foreach ($this->eventTimeline as $eventName => $timestamps) {
            // Count events in the current window
            $windowCount = 0;
            $remainingTimestamps = [];

            foreach ($timestamps as $ts) {
                if ($ts >= $windowStart) {
                    $windowCount++;
                    $remainingTimestamps[] = $ts;
                }
            }

            // Store current window count as baseline
            $this->baselineWindows[$eventName][] = $windowCount;

            // Trim baseline to max size
            if (count($this->baselineWindows[$eventName]) > $this->maxBaselineWindows) {
                $this->baselineWindows[$eventName] = array_slice(
                    $this->baselineWindows[$eventName],
                    -$this->maxBaselineWindows,
                );
            }

            // Keep only recent timestamps
            $this->eventTimeline[$eventName] = $remainingTimestamps;

            // Check for anomaly
            if (count($this->baselineWindows[$eventName]) >= $this->minDataPoints) {
                $anomaly = $this->detectAnomaly($eventName, $windowCount);

                if ($anomaly !== null) {
                    $detectedAnomalies[] = $anomaly;

                    // Store recent anomaly
                    $this->recentAnomalies[] = $anomaly;
                    if (count($this->recentAnomalies) > $this->maxRecentAnomalies) {
                        array_shift($this->recentAnomalies);
                    }

                    // Dispatch alert event
                    if ($this->dispatchAlerts) {
                        $this->dispatchAlert($anomaly);
                    }
                }
            }
        }

        return $detectedAnomalies;
    }

    /**
     * Manually check an event against historical baseline.
     *
     * Does not rotate the window. Useful for on-demand checks.
     *
     * @param  string  $eventName  Event name to check
     * @param  int|null  $count  Current count (0 = count current window)
     * @return array{event: string, rate: float, expected: float, deviation: float, timestamp: string, severity: string}|null
     */
    public function check(string $eventName, ?int $count = null): ?array
    {
        $actualCount = $count ?? $this->countCurrentWindow($eventName);

        return $this->detectAnomaly($eventName, $actualCount);
    }

    /**
     * Get the current event rate for an event (events per window period).
     *
     * @param  string  $eventName
     * @return float Events per second in the current window
     */
    public function currentRate(string $eventName): float
    {
        $count = $this->countCurrentWindow($eventName);

        return $this->windowSize > 0
            ? round($count / $this->windowSize, 6)
            : 0.0;
    }

    /**
     * Get the expected (mean) rate for an event based on historical baseline.
     *
     * @param  string  $eventName
     * @return float Mean events per window period
     */
    public function expectedRate(string $eventName): float
    {
        $windows = $this->baselineWindows[$eventName] ?? [];

        if (count($windows) === 0) {
            return 0.0;
        }

        return round(array_sum($windows) / count($windows), 2);
    }

    /**
     * Get statistical summary for an event.
     *
     * @param  string  $eventName
     * @return array{mean: float, std_dev: float, min: int, max: int, current: int, baseline_windows: int, is_anomaly: bool, severity: string|null}
     */
    public function stats(string $eventName): array
    {
        $windows = $this->baselineWindows[$eventName] ?? [];
        $current = $this->countCurrentWindow($eventName);

        if (count($windows) === 0) {
            return [
                'mean' => 0.0,
                'std_dev' => 0.0,
                'min' => 0,
                'max' => 0,
                'current' => $current,
                'baseline_windows' => 0,
                'is_anomaly' => false,
                'severity' => null,
            ];
        }

        $mean = array_sum($windows) / count($windows);
        $variance = 0.0;

        foreach ($windows as $w) {
            $variance += ($w - $mean) ** 2;
        }

        $stdDev = sqrt($variance / count($windows));
        $anomaly = $this->detectAnomaly($eventName, $current);

        return [
            'mean' => round($mean, 2),
            'std_dev' => round($stdDev, 2),
            'min' => min($windows),
            'max' => max($windows),
            'current' => $current,
            'baseline_windows' => count($windows),
            'is_anomaly' => $anomaly !== null,
            'severity' => $anomaly !== null ? $anomaly['severity'] : null,
        ];
    }

    /**
     * Get all recent anomalies.
     *
     * @param  int  $limit  Maximum anomalies to return
     * @return list<array{event: string, rate: float, expected: float, deviation: float, timestamp: string, severity: string}>
     */
    public function recentAnomalies(int $limit = 50): array
    {
        return array_slice($this->recentAnomalies, -$limit);
    }

    /**
     * Get a summary of anomaly detection across all tracked events.
     *
     * @return array{tracked_events: int, total_anomalies: int, recent_anomalies: int, most_anomalous: list<string>, window_size: int, sensitivity: float}
     */
    public function summary(): array
    {
        $anomalyCounts = [];

        foreach ($this->recentAnomalies as $anomaly) {
            $event = $anomaly['event'];
            $anomalyCounts[$event] = ($anomalyCounts[$event] ?? 0) + 1;
        }

        arsort($anomalyCounts);

        return [
            'tracked_events' => count($this->eventTimeline),
            'total_anomalies' => count($this->recentAnomalies),
            'recent_anomalies' => count($this->recentAnomalies),
            'most_anomalous' => array_keys(array_slice($anomalyCounts, 0, 10)),
            'window_size' => $this->windowSize,
            'sensitivity' => $this->sensitivity,
        ];
    }

    /**
     * Clear all tracked data (timelines, baselines, anomalies).
     */
    public function flush(): void
    {
        $this->eventTimeline = [];
        $this->baselineWindows = [];
        $this->recentAnomalies = [];
    }

    /**
     * Count events in the current sliding window.
     */
    private function countCurrentWindow(string $eventName): int
    {
        $windowStart = time() - $this->windowSize;
        $count = 0;

        foreach ($this->eventTimeline[$eventName] ?? [] as $ts) {
            if ($ts >= $windowStart) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Detect if a count is anomalous based on historical baseline.
     *
     * Uses Z-score: if |observed - mean| > sensitivity * std_dev,
     * the event is flagged as anomalous.
     *
     * @return array{event: string, rate: float, expected: float, deviation: float, timestamp: string, severity: string}|null
     */
    private function detectAnomaly(string $eventName, int $currentCount): ?array
    {
        $windows = $this->baselineWindows[$eventName] ?? [];

        if (count($windows) < $this->minDataPoints) {
            return null;
        }

        $mean = array_sum($windows) / count($windows);

        if ($mean < 0.01) {
            return null; // Not enough data variance
        }

        $variance = 0.0;
        foreach ($windows as $w) {
            $variance += ($w - $mean) ** 2;
        }

        $stdDev = sqrt($variance / count($windows));

        // If std dev is effectively zero, any deviation > 0 is anomalous
        if ($stdDev < 0.01) {
            if (abs($currentCount - $mean) < 1) {
                return null;
            }

            $deviation = $mean > 0 ? round((($currentCount - $mean) / $mean) * 100, 2) : 100.0;

            return [
                'event' => $eventName,
                'rate' => $this->windowSize > 0 ? round($currentCount / $this->windowSize, 6) : 0.0,
                'expected' => round($mean, 2),
                'deviation' => $deviation,
                'timestamp' => date('c'),
                'severity' => 'critical',
            ];
        }

        $zScore = ($currentCount - $mean) / $stdDev;

        if (abs($zScore) < $this->sensitivity) {
            return null;
        }

        $deviation = $mean > 0 ? round((($currentCount - $mean) / $mean) * 100, 2) : 100.0;
        $severity = $this->classifySeverity(abs($zScore));

        return [
            'event' => $eventName,
            'rate' => $this->windowSize > 0 ? round($currentCount / $this->windowSize, 6) : 0.0,
            'expected' => round($mean, 2),
            'deviation' => $deviation,
            'timestamp' => date('c'),
            'severity' => $severity,
        ];
    }

    /**
     * Classify anomaly severity based on Z-score magnitude.
     *
     * @return string 'warning', 'elevated', or 'critical'
     */
    private function classifySeverity(float $zScore): string
    {
        return match (true) {
            $zScore >= 4.0 => 'critical',
            $zScore >= 3.0 => 'elevated',
            default => 'warning',
        };
    }

    /**
     * Dispatch an anomaly alert as an analytics event.
     *
     * @param  array{event: string, rate: float, expected: float, deviation: float, timestamp: string, severity: string}  $anomaly
     */
    private function dispatchAlert(array $anomaly): void
    {
        try {
            $event = new AnalyticsEvent(
                name: 'analytics_anomaly_detected',
                params: [
                    'anomaly_event' => $anomaly['event'],
                    'anomaly_rate' => $anomaly['rate'],
                    'anomaly_expected' => $anomaly['expected'],
                    'anomaly_deviation' => $anomaly['deviation'],
                    'anomaly_severity' => $anomaly['severity'],
                ],
            );

            $this->queue->dispatch($event);
        } catch (\Throwable $e) {
            try {
                Log::warning('AnomalyDetectionService: failed to dispatch alert', [
                    'error' => $e->getMessage(),
                    'anomaly' => $anomaly,
                ]);
            } catch (\Throwable) {
                // Log may not be available
            }
        }
    }
}
