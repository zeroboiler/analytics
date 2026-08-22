<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Request DTO for computing a semantic metric.
 *
 * Encapsulates all parameters needed for metric computation: metric name,
 * time range, granularity, dimensions, filters, and comparison options.
 *
 * @since 233.0.0
 */
final readonly class MetricComputationRequest
{
    /**
     * @param  string  $metricName  Metric to compute
     * @param  \DateTimeImmutable|null  $periodStart  Start of computation period (defaults to 30 days ago)
     * @param  \DateTimeImmutable|null  $periodEnd  End of computation period (defaults to now)
     * @param  string  $granularity  Time granularity: minute|hour|day|week|month
     * @param  list<string>  $dimensions  Dimensional breakdowns to include
     * @param  list<string>  $groupBy  Additional group-by fields
     * @param  bool  $includeComparison  Whether to compute comparison to previous period
     * @param  bool  $includeTimeSeries  Whether to include time series data points
     * @param  array<string, mixed>  $filters  Additional runtime filters
     * @param  string|null  $tenantId  Optional tenant ID for multi-tenant isolation
     * @param  int|null  $limit  Result limit for breakdowns
     * @param  int  $offset  Result offset for breakdowns
     * @param  string|null  $cacheKey  Optional custom cache key override
     */
    public function __construct(
        public string $metricName,
        public ?\DateTimeImmutable $periodStart = null,
        public ?\DateTimeImmutable $periodEnd = null,
        public string $granularity = 'day',
        public array $dimensions = [],
        public array $groupBy = [],
        public bool $includeComparison = false,
        public bool $includeTimeSeries = false,
        public array $filters = [],
        public ?string $tenantId = null,
        public ?int $limit = null,
        public int $offset = 0,
        public ?string $cacheKey = null,
    ){}

    /**
     * Create a simple request for a named metric.
     *
     * @param  string  $metricName
     * @param  string  $granularity
     * @return self
     */
    public static function simple(
        string $metricName,
        string $granularity = 'day',
    ): self {
        return new self(
            metricName: $metricName,
            granularity: $granularity,
        );
    }

    /**
     * Create a request with time range.
     *
     * @param  string  $metricName
     * @param  int  $daysBack
     * @param  string  $granularity
     * @return self
     */
    public static function lastNDays(
        string $metricName,
        int $daysBack = 30,
        string $granularity = 'day',
    ): self {
        return new self(
            metricName: $metricName,
            periodStart: new \DateTimeImmutable("-{$daysBack} days"),
            periodEnd: new \DateTimeImmutable(),
            granularity: $granularity,
        );
    }

    /**
     * Create a request with comparison enabled.
     *
     * @param  string  $metricName
     * @param  int  $daysBack
     * @param  string  $granularity
     * @return self
     */
    public static function withComparison(
        string $metricName,
        int $daysBack = 30,
        string $granularity = 'day',
    ): self {
        return new self(
            metricName: $metricName,
            periodStart: new \DateTimeImmutable("-{$daysBack} days"),
            periodEnd: new \DateTimeImmutable(),
            granularity: $granularity,
            includeComparison: true,
        );
    }

    /**
     * Get the effective period start (defaults to 30 days ago).
     */
    public function effectivePeriodStart(): \DateTimeImmutable
    {
        return $this->periodStart ?? new \DateTimeImmutable('-30 days');
    }

    /**
     * Get the effective period end (defaults to now).
     */
    public function effectivePeriodEnd(): \DateTimeImmutable
    {
        return $this->periodEnd ?? new \DateTimeImmutable();
    }

    /**
     * Generate a cache key from this request.
     */
    public function cacheKey(): string
    {
        if ($this->cacheKey !== null) {
            return $this->cacheKey;
        }

        $parts = [
            'metric',
            $this->metricName,
            $this->effectivePeriodStart()->format('Y-m-d'),
            $this->effectivePeriodEnd()->format('Y-m-d'),
            $this->granularity,
            implode(',', $this->dimensions),
            $this->includeComparison ? 'cmp' : '',
            $this->includeTimeSeries ? 'ts' : '',
            $this->tenantId ?? 'global',
        ];

        return 'zb_sm:' . md5(implode('|', array_filter($parts)));
    }

    /**
     * Valid granularity values.
     *
     * @return list<string>
     */
    public static function validGranularities(): array
    {
        return ['minute', 'hour', 'day', 'week', 'month'];
    }

    /**
     * Check if the granularity is valid.
     */
    public function isValidGranularity(): bool
    {
        return in_array($this->granularity, self::validGranularities(), true);
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
            'period_start' => $this->effectivePeriodStart()->format('c'),
            'period_end' => $this->effectivePeriodEnd()->format('c'),
            'granularity' => $this->granularity,
            'dimensions' => $this->dimensions,
            'group_by' => $this->groupBy,
            'include_comparison' => $this->includeComparison,
            'include_time_series' => $this->includeTimeSeries,
            'filters' => $this->filters,
            'tenant_id' => $this->tenantId,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'cache_key' => $this->cacheKey(),
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
            periodStart: isset($data['period_start']) ? new \DateTimeImmutable($data['period_start']) : null,
            periodEnd: isset($data['period_end']) ? new \DateTimeImmutable($data['period_end']) : null,
            granularity: (string) ($data['granularity'] ?? 'day'),
            dimensions: (array) ($data['dimensions'] ?? []),
            groupBy: (array) ($data['group_by'] ?? []),
            includeComparison: (bool) ($data['include_comparison'] ?? false),
            includeTimeSeries: (bool) ($data['include_time_series'] ?? false),
            filters: (array) ($data['filters'] ?? []),
            tenantId: isset($data['tenant_id']) ? (string) $data['tenant_id'] : null,
            limit: isset($data['limit']) ? (int) $data['limit'] : null,
            offset: (int) ($data['offset'] ?? 0),
            cacheKey: isset($data['cache_key']) ? (string) $data['cache_key'] : null,
        );
    }
}
