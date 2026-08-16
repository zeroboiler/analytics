<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing a detected statistical anomaly.
 *
 * Captures anomaly detection results from EventAnomalyProphet including
 * the anomaly type, severity, detected values, and recommended actions.
 *
 * @since 83.0.0
 */
final readonly class AnomalyRecord
{
    /**
     * Anomaly severity levels.
     */
    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    /**
     * Anomaly detection methods.
     */
    public const METHOD_ZSCORE = 'zscore';
    public const METHOD_IQR = 'iqr';
    public const METHOD_THRESHOLD = 'threshold';

    /**
     * @param  string  $metric  The metric name being monitored (e.g., 'page_views', 'signups')
     * @param  string  $method  Detection method used (zscore, iqr, threshold)
     * @param  string  $severity  Anomaly severity (low, medium, high, critical)
     * @param  float  $observedValue  The current observed metric value
     * @param  float  $expectedValue  The expected (baseline) metric value
     * @param  float  $deviationScore  How many standard deviations or IQRs away from expected
     * @param  \DateTimeImmutable  $detectedAt  When the anomaly was detected
     * @param  string|null  $description  Human-readable description of the anomaly
     * @param  list<string>  $recommendedActions  Suggested remediation steps
     * @param  array<string, mixed>  $context  Additional context (baseline stats, thresholds, etc.)
     */
    public function __construct(
        public string $metric,
        public string $method,
        public string $severity,
        public float $observedValue,
        public float $expectedValue,
        public float $deviationScore,
        public \DateTimeImmutable $detectedAt,
        public ?string $description = null,
        public array $recommendedActions = [],
        public array $context = [],
    ): void {}

    /**
     * Get the percentage deviation from expected value.
     */
    public function percentDeviation(): float
    {
        if ($this->expectedValue === 0.0) {
            return $this->observedValue > 0 ? 100.0 : 0.0;
        }

        return round((($this->observedValue - $this->expectedValue) / $this->expectedValue) * 100.0, 2);
    }

    /**
     * Check if this anomaly is negative (below expected).
     */
    public function isNegative(): bool
    {
        return $this->observedValue < $this->expectedValue;
    }

    /**
     * Check if this anomaly is positive (above expected).
     */
    public function isPositive(): bool
    {
        return $this->observedValue > $this->expectedValue;
    }

    /**
     * Get severity rank for comparison (higher = more severe).
     */
    public function severityRank(): int
    {
        return match ($this->severity) {
            self::SEVERITY_LOW => 1,
            self::SEVERITY_MEDIUM => 2,
            self::SEVERITY_HIGH => 3,
            self::SEVERITY_CRITICAL => 4,
            default => 0,
        };
    }

    /**
     * Convert to array representation.
     *
     * @return array{metric: string, method: string, severity: string, observed_value: float, expected_value: float, deviation_score: float, percent_deviation: float, detected_at: string, is_negative: bool, description: string|null, recommended_actions: list<string>, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'metric' => $this->metric,
            'method' => $this->method,
            'severity' => $this->severity,
            'observed_value' => round($this->observedValue, 4),
            'expected_value' => round($this->expectedValue, 4),
            'deviation_score' => round($this->deviationScore, 4),
            'percent_deviation' => $this->percentDeviation(),
            'detected_at' => $this->detectedAt->format('c'),
            'is_negative' => $this->isNegative(),
            'description' => $this->description,
            'recommended_actions' => $this->recommendedActions,
            'context' => $this->context,
        ];
    }
}
