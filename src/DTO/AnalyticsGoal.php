<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing an analytics goal with target tracking.
 *
 * Goals define quantitative targets that can be tracked against actual
 * event/metric data. Each goal has a name, target value, time window,
 * and optional threshold for alerting. Used by AnalyticsGoalTracker.
 *
 * @since 177.0.0
 */
final readonly class AnalyticsGoal
{
    /**
     * @param  string  $key  Unique goal identifier (e.g. 'daily_signups', 'monthly_mrr')
     * @param  string  $name  Human-readable goal name
     * @param  string  $description  Goal description
     * @param  float  $target  Target value to achieve
     * @param  string  $metric  Underlying metric/event name to track
     * @param  string  $aggregation  How to aggregate: 'count', 'sum', 'avg', 'unique'
     * @param  string  $window  Time window: 'daily', 'weekly', 'monthly', 'quarterly', 'yearly'
     * @param  float|null  $warningThreshold  Percentage (0-100) at which to trigger warning
     * @param  float|null  $criticalThreshold  Percentage (0-100) at which to trigger critical alert
     * @param  string|null  $category  Goal category: 'growth', 'retention', 'revenue', 'engagement', 'activation'
     * @param  string|null  $owner  Team/person responsible for this goal
     * @param  bool  $active  Whether this goal is currently active
     * @param  array<string, mixed>  $meta  Additional metadata
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description = '',
        public float $target = 0.0,
        public string $metric = '',
        public string $aggregation = 'count',
        public string $window = 'daily',
        public ?float $warningThreshold = null,
        public ?float $criticalThreshold = null,
        public ?string $category = null,
        public ?string $owner = null,
        public bool $active = true,
        public array $meta = [],
    ) {}

    /**
     * Create from config array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) ($data['key'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            target: (float) ($data['target'] ?? 0.0),
            metric: (string) ($data['metric'] ?? ''),
            aggregation: (string) ($data['aggregation'] ?? 'count'),
            window: (string) ($data['window'] ?? 'daily'),
            warningThreshold: isset($data['warning_threshold']) ? (float) $data['warning_threshold'] : null,
            criticalThreshold: isset($data['critical_threshold']) ? (float) $data['critical_threshold'] : null,
            category: isset($data['category']) ? (string) $data['category'] : null,
            owner: isset($data['owner']) ? (string) $data['owner'] : null,
            active: (bool) ($data['active'] ?? true),
            meta: (array) ($data['meta'] ?? []),
        );
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'target' => $this->target,
            'metric' => $this->metric,
            'aggregation' => $this->aggregation,
            'window' => $this->window,
            'warning_threshold' => $this->warningThreshold,
            'critical_threshold' => $this->criticalThreshold,
            'category' => $this->category,
            'owner' => $this->owner,
            'active' => $this->active,
            'meta' => $this->meta,
        ];
    }
}
