<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable definition of an analytics metric in the Semantic Metrics Layer.
 *
 * Defines how a metric is computed from raw analytics events. Supports
 * aggregation types (count, sum, avg, max, min, unique_count, percentile, ratio),
 * dimensional breakdowns, time windowing, and configurable filters.
 *
 * Inspired by dbt Metrics, Cube.js semantic layer, and Looker LookML.
 *
 * @phpstan-type MetricDimension array{name: string, type: 'string'|'number'|'boolean'|'timestamp', description: string, sql?: string|null}
 * @phpstan-type MetricFilter array{field: string, operator: 'eq'|'neq'|'gt'|'gte'|'lt'|'lte'|'in'|'not_in'|'contains'|'not_contains', value: mixed}
 * @phpstan-type MetricTimeWindow array{value: int, unit: 'minutes'|'hours'|'days'|'weeks'|'months'}
 *
 * @since 233.0.0
 */
final readonly class MetricDefinition
{
    /**
     * Aggregation type constants.
     */
    public const TYPE_COUNT = 'count';
    public const TYPE_SUM = 'sum';
    public const TYPE_AVG = 'avg';
    public const TYPE_MAX = 'max';
    public const TYPE_MIN = 'min';
    public const TYPE_UNIQUE_COUNT = 'unique_count';
    public const TYPE_PERCENTILE = 'percentile';
    public const TYPE_RATIO = 'ratio';

    /**
     * Valid aggregation types.
     *
     * @var list<string>
     */
    public const VALID_TYPES = [
        self::TYPE_COUNT,
        self::TYPE_SUM,
        self::TYPE_AVG,
        self::TYPE_MAX,
        self::TYPE_MIN,
        self::TYPE_UNIQUE_COUNT,
        self::TYPE_PERCENTILE,
        self::TYPE_RATIO,
    ];

    /**
     * @param  string  $name  Unique metric identifier (e.g. 'mrr', 'trial_conversion_rate', 'avg_session_duration')
     * @param  string  $label  Human-readable label (e.g. 'Monthly Recurring Revenue')
     * @param  string  $description  Detailed description of what this metric measures
     * @param  string  $type  Aggregation type (count|sum|avg|max|min|unique_count|percentile|ratio)
     * @param  list<string>  $sourceEvents  Event names that feed into this metric (e.g. ['purchase', 'refund'])
     * @param  string|null  $measureField  The event param field to aggregate (required for sum/avg/max/min)
     * @param  list<string>  $uniqueField  Field to count unique values for (for unique_count type)
     * @param  list<MetricDimension>  $dimensions  Dimensional breakdowns available for this metric
     * @param  list<MetricFilter>  $filters  Pre-computation filters to apply to source events
     * @param  MetricTimeWindow|null  $defaultTimeWindow  Default time window for computation
     * @param  string|null  $category  Metric category (revenue|growth|engagement|retention|funnel|unit_economics)
     * @param  array<string, mixed>  $metadata  Additional metadata (tags, owner, team, etc.)
     * @param  string|null  $ratioNumerator  Numerator metric name (for ratio type)
     * @param  string|null  $ratioDenominator  Denominator metric name (for ratio type)
     * @param  float|null  $percentileValue  Percentile value 0-100 (for percentile type)
     * @param  string|null  $unit  Display unit (currency, seconds, percentage, count, etc.)
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public string $type,
        public array $sourceEvents = [],
        public ?string $measureField = null,
        public array $uniqueField = [],
        public array $dimensions = [],
        public array $filters = [],
        public ?array $defaultTimeWindow = null,
        public ?string $category = null,
        public array $metadata = [],
        public ?string $ratioNumerator = null,
        public ?string $ratioDenominator = null,
        public ?float $percentileValue = null,
        public ?string $unit = null,
    ) {}

    /**
     * Create a count metric definition.
     *
     * @param  string  $name
     * @param  string  $label
     * @param  list<string>  $sourceEvents
     * @param  string  $description
     * @return self
     */
    public static function count(
        string $name,
        string $label,
        array $sourceEvents,
        string $description = '',
    ): self {
        return new self(
            name: $name,
            label: $label,
            description: $description,
            type: self::TYPE_COUNT,
            sourceEvents: $sourceEvents,
        );
    }

    /**
     * Create a sum metric definition.
     *
     * @param  string  $name
     * @param  string  $label
     * @param  string  $measureField
     * @param  list<string>  $sourceEvents
     * @param  string  $description
     * @return self
     */
    public static function sum(
        string $name,
        string $label,
        string $measureField,
        array $sourceEvents,
        string $description = '',
    ): self {
        return new self(
            name: $name,
            label: $label,
            description: $description,
            type: self::TYPE_SUM,
            sourceEvents: $sourceEvents,
            measureField: $measureField,
        );
    }

    /**
     * Create a unique count metric definition.
     *
     * @param  string  $name
     * @param  string  $label
     * @param  string  $uniqueField
     * @param  list<string>  $sourceEvents
     * @param  string  $description
     * @return self
     */
    public static function uniqueCount(
        string $name,
        string $label,
        string $uniqueField,
        array $sourceEvents,
        string $description = '',
    ): self {
        return new self(
            name: $name,
            label: $label,
            description: $description,
            type: self::TYPE_UNIQUE_COUNT,
            sourceEvents: $sourceEvents,
            uniqueField: [$uniqueField],
        );
    }

    /**
     * Create a ratio metric definition.
     *
     * @param  string  $name
     * @param  string  $label
     * @param  string  $numerator
     * @param  string  $denominator
     * @param  string  $description
     * @return self
     */
    public static function ratio(
        string $name,
        string $label,
        string $numerator,
        string $denominator,
        string $description = '',
    ): self {
        return new self(
            name: $name,
            label: $label,
            description: $description,
            type: self::TYPE_RATIO,
            ratioNumerator: $numerator,
            ratioDenominator: $denominator,
        );
    }

    /**
     * Check if this is a derived/ratio metric.
     */
    public function isDerived(): bool
    {
        return $this->type === self::TYPE_RATIO
            && $this->ratioNumerator !== null
            && $this->ratioDenominator !== null;
    }

    /**
     * Check if this metric requires a measure field.
     */
    public function requiresMeasureField(): bool
    {
        return in_array($this->type, [
            self::TYPE_SUM,
            self::TYPE_AVG,
            self::TYPE_MAX,
            self::TYPE_MIN,
            self::TYPE_PERCENTILE,
        ], true);
    }

    /**
     * Check if this metric requires unique field(s).
     */
    public function requiresUniqueField(): bool
    {
        return $this->type === self::TYPE_UNIQUE_COUNT;
    }

    /**
     * Check if the type is valid.
     */
    public function isValidType(): bool
    {
        return in_array($this->type, self::VALID_TYPES, true);
    }

    /**
     * Check if this metric definition is structurally valid.
     */
    public function isValid(): bool
    {
        if (empty($this->name) || empty($this->type)) {
            return false;
        }

        if (!$this->isValidType()) {
            return false;
        }

        if ($this->isDerived()) {
            return true;
        }

        if ($this->requiresMeasureField() && $this->measureField === null) {
            return false;
        }

        if ($this->requiresUniqueField() && empty($this->uniqueField)) {
            return false;
        }

        return true;
    }

    /**
     * Get dimension names only.
     *
     * @return list<string>
     */
    public function dimensionNames(): array
    {
        return array_map(
            static fn (array $dim): string => $dim['name'],
            $this->dimensions,
        );
    }

    /**
     * Check if a specific dimension exists.
     */
    public function hasDimension(string $name): bool
    {
        return in_array($name, $this->dimensionNames(), true);
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'description' => $this->description,
            'type' => $this->type,
            'source_events' => $this->sourceEvents,
            'measure_field' => $this->measureField,
            'unique_field' => $this->uniqueField,
            'dimensions' => $this->dimensions,
            'filters' => $this->filters,
            'default_time_window' => $this->defaultTimeWindow,
            'category' => $this->category,
            'metadata' => $this->metadata,
            'ratio_numerator' => $this->ratioNumerator,
            'ratio_denominator' => $this->ratioDenominator,
            'percentile_value' => $this->percentileValue,
            'unit' => $this->unit,
            'is_derived' => $this->isDerived(),
            'is_valid' => $this->isValid(),
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
            name: (string) ($data['name'] ?? ''),
            label: (string) ($data['label'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            type: (string) ($data['type'] ?? 'count'),
            sourceEvents: (array) ($data['source_events'] ?? []),
            measureField: isset($data['measure_field']) ? (string) $data['measure_field'] : null,
            uniqueField: (array) ($data['unique_field'] ?? []),
            dimensions: (array) ($data['dimensions'] ?? []),
            filters: (array) ($data['filters'] ?? []),
            defaultTimeWindow: isset($data['default_time_window']) ? (array) $data['default_time_window'] : null,
            category: isset($data['category']) ? (string) $data['category'] : null,
            metadata: (array) ($data['metadata'] ?? []),
            ratioNumerator: isset($data['ratio_numerator']) ? (string) $data['ratio_numerator'] : null,
            ratioDenominator: isset($data['ratio_denominator']) ? (string) $data['ratio_denominator'] : null,
            percentileValue: isset($data['percentile_value']) ? (float) $data['percentile_value'] : null,
            unit: isset($data['unit']) ? (string) $data['unit'] : null,
        );
    }
}
