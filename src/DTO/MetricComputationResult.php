<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Result of computing a semantic metric.
 *
 * Contains the computed value, time range, dimensional breakdowns,
 * comparison to previous period, and metadata about the computation.
 *
 * @phpstan-type DimensionBreakdown array{dimension: string, value: string, metric_value: float, percentage: float}
 * @phpstan-type ComparisonData array{previous_value: float|null, change: float|null, change_percentage: float|null, direction: 'up'|'down'|'stable'|'new'}
 *
 * @since 233.0.0
 */
final readonly class MetricComputationResult
{
    /**
     * @param  string  $metricName  The metric that was computed
     * @param  float  $value  The computed metric value
     * @param  string|null  $formattedValue  Human-readable formatted value (e.g. "$1,234.56")
     * @param  string|null  $unit  Display unit
     * @param  \DateTimeImmutable  $computedAt  When this computation was performed
     * @param  \DateTimeImmutable  $periodStart  Start of the computation period
     * @param  \DateTimeImmutable  $periodEnd  End of the computation period
     * @param  int  $sourceEventCount  Number of raw events that fed into this computation
     * @param  list<DimensionBreakdown>  $breakdowns  Dimensional breakdowns (if requested)
     * @param  ComparisonData|null  $comparison  Comparison to previous period (if requested)
     * @param  string|null  $granularity  Time granularity used (minute|hour|day|week|month)
     * @param  array<string, mixed>  $metadata  Additional computation metadata (cache_hit, compute_time_ms, etc.)
     * @param  list<array{timestamp: string, value: float}>  $timeSeries  Time series data points (if requested)
     */
    public function __construct(
        public string $metricName,
        public float $value,
        public ?string $formattedValue = null,
        public ?string $unit = null,
        public ?\DateTimeImmutable $computedAt = null,
        public ?\DateTimeImmutable $periodStart = null,
        public ?\DateTimeImmutable $periodEnd = null,
        public int $sourceEventCount = 0,
        public array $breakdowns = [],
        public ?array $comparison = null,
        public ?string $granularity = null,
        public array $metadata = [],
        public array $timeSeries = [],
    ): void {}

    /**
     * Create a result with zero value.
     *
     * @param  string  $metricName
     * @return self
     */
    public static function zero(string $metricName): self
    {
        return new self(
            metricName: $metricName,
            value: 0.0,
            computedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Create from computed value.
     *
     * @param  string  $metricName
     * @param  float  $value
     * @param  string|null  $unit
     * @param  int  $eventCount
     * @return self
     */
    public static function make(
        string $metricName,
        float $value,
        ?string $unit = null,
        int $eventCount = 0,
    ): self {
        return new self(
            metricName: $metricName,
            value: $value,
            unit: $unit,
            computedAt: new \DateTimeImmutable(),
            sourceEventCount: $eventCount,
        );
    }

    /**
     * Format the value with the given precision.
     */
    public function formatted(int $decimals = 2): string
    {
        if ($this->formattedValue !== null) {
            return $this->formattedValue;
        }

        return number_format($this->value, $decimals);
    }

    /**
     * Check if this result has dimensional breakdowns.
     */
    public function hasBreakdowns(): bool
    {
        return count($this->breakdowns) > 0;
    }

    /**
     * Check if this result has comparison data.
     */
    public function hasComparison(): bool
    {
        return $this->comparison !== null;
    }

    /**
     * Check if this result has time series data.
     */
    public function hasTimeSeries(): bool
    {
        return count($this->timeSeries) > 0;
    }

    /**
     * Get the change direction.
     */
    public function changeDirection(): string
    {
        if ($this->comparison === null) {
            return 'stable';
        }

        $change = $this->comparison['change'] ?? 0.0;

        if ($change > 0.001) {
            return 'up';
        }

        if ($change < -0.001) {
            return 'down';
        }

        return 'stable';
    }

    /**
     * Create a copy with comparison data attached.
     *
     * @param  float|null  $previousValue
     * @return self
     */
    public function withComparison(float|null $previousValue): self
    {
        $change = $previousValue !== null ? $this->value - $previousValue : null;
        $changePct = ($previousValue !== null && $previousValue !== 0.0)
            ? (($this->value - $previousValue) / abs($previousValue)) * 100.0
            : null;

        return new self(
            metricName: $this->metricName,
            value: $this->value,
            formattedValue: $this->formattedValue,
            unit: $this->unit,
            computedAt: $this->computedAt,
            periodStart: $this->periodStart,
            periodEnd: $this->periodEnd,
            sourceEventCount: $this->sourceEventCount,
            breakdowns: $this->breakdowns,
            comparison: [
                'previous_value' => $previousValue,
                'change' => $change,
                'change_percentage' => $changePct,
                'direction' => $previousValue === null
                    ? 'new'
                    : ($change > 0.001 ? 'up' : ($change < -0.001 ? 'down' : 'stable')),
            ],
            granularity: $this->granularity,
            metadata: $this->metadata,
            timeSeries: $this->timeSeries,
        );
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'metric_name' => $this->metricName,
            'value' => $this->value,
            'formatted_value' => $this->formattedValue,
            'unit' => $this->unit,
            'computed_at' => $this->computedAt?->format('c'),
            'period_start' => $this->periodStart?->format('c'),
            'period_end' => $this->periodEnd?->format('c'),
            'source_event_count' => $this->sourceEventCount,
            'breakdowns' => $this->breakdowns,
            'comparison' => $this->comparison,
            'granularity' => $this->granularity,
            'metadata' => $this->metadata,
            'time_series' => $this->timeSeries,
            'has_breakdowns' => $this->hasBreakdowns(),
            'has_comparison' => $this->hasComparison(),
            'change_direction' => $this->changeDirection(),
        ];
    }

    /**
     * Create from array representation.
     *
     * @param  array<string, mixed>  $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            metricName: (string) ($data['metric_name'] ?? ''),
            value: (float) ($data['value'] ?? 0.0),
            formattedValue: isset($data['formatted_value']) ? (string) $data['formatted_value'] : null,
            unit: isset($data['unit']) ? (string) $data['unit'] : null,
            computedAt: isset($data['computed_at']) ? new \DateTimeImmutable($data['computed_at']) : null,
            periodStart: isset($data['period_start']) ? new \DateTimeImmutable($data['period_start']) : null,
            periodEnd: isset($data['period_end']) ? new \DateTimeImmutable($data['period_end']) : null,
            sourceEventCount: (int) ($data['source_event_count'] ?? 0),
            breakdowns: (array) ($data['breakdowns'] ?? []),
            comparison: isset($data['comparison']) ? (array) $data['comparison'] : null,
            granularity: isset($data['granularity']) ? (string) $data['granularity'] : null,
            metadata: (array) ($data['metadata'] ?? []),
            timeSeries: (array) ($data['time_series'] ?? []),
        );
    }
}
