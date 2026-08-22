<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing the result of a metric projection evaluation.
 *
 * Contains the projected metric value, metadata about the computation,
 * and staleness information for cache-aware consumers.
 *
 * @since 129.0.0
 */
final readonly class MetricProjectionResult
{
    /**
     * @param  string  $name  Projection name (e.g. 'dau', 'trial_conversion_rate')
     * @param  mixed  $value  Computed metric value (int|float|string|array)
     * @param  string  $type  Metric type: count, sum, average, unique_count, funnel_rate, ratio
     * @param  int  $eventCount  Number of raw events that contributed to this metric
     * @param  string|null  $window  Time window used (e.g. '7d', '30d', '24h')
     * @param  \DateTimeImmutable|null  $computedAt  When this result was computed
     * @param  \DateTimeImmutable|null  $staleAt  When this result becomes stale
     * @param  bool  $cached  Whether this result was served from cache
     * @param  array<string, mixed>  $metadata  Additional metadata (filters, breakdowns, etc.)
     */
    public function __construct(
        public string $name,
        public mixed $value,
        public string $type,
        public int $eventCount = 0,
        public ?string $window = null,
        public ?\DateTimeImmutable $computedAt = null,
        public ?\DateTimeImmutable $staleAt = null,
        public bool $cached = false,
        public array $metadata = [],
    ){}

    /**
     * Create a projection result from an array.
     *
     * @param  array<string, mixed>  $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            value: $data['value'] ?? null,
            type: (string) ($data['type'] ?? 'count'),
            eventCount: (int) ($data['event_count'] ?? 0),
            window: isset($data['window']) ? (string) $data['window'] : null,
            computedAt: isset($data['computed_at']) && is_string($data['computed_at'])
                ? new \DateTimeImmutable($data['computed_at'])
                : null,
            staleAt: isset($data['stale_at']) && is_string($data['stale_at'])
                ? new \DateTimeImmutable($data['stale_at'])
                : null,
            cached: (bool) ($data['cached'] ?? false),
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }

    /**
     * Convert to array for API responses and cache storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'value' => $this->value,
            'type' => $this->type,
            'event_count' => $this->eventCount,
            'window' => $this->window,
            'computed_at' => $this->computedAt?->format('c'),
            'stale_at' => $this->staleAt?->format('c'),
            'cached' => $this->cached,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Check if this result is stale relative to the given time.
     *
     * @param  \DateTimeImmutable|null  $now  Current time (null = now)
     */
    public function isStale(?\DateTimeImmutable $now = null): bool
    {
        if ($this->staleAt === null) {
            return false;
        }

        return ($now ?? new \DateTimeImmutable()) >= $this->staleAt;
    }

    /**
     * Get the numeric value if the result is numeric.
     *
     * @return float|null
     */
    public function numericValue(): ?float
    {
        if (is_int($this->value) || is_float($this->value)) {
            return (float) $this->value;
        }

        if (is_string($this->value) && is_numeric($this->value)) {
            return (float) $this->value;
        }

        return null;
    }
}
