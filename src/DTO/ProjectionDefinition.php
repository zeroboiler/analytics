<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO defining a metric projection.
 *
 * A projection is a reusable metric definition that specifies how to
 * compute an aggregate value from a stream of analytics events.
 *
 * Supported types:
 * - **count**: Count of events matching the filter criteria
 * - **sum**: Sum of a numeric field across matching events
 * - **average**: Average of a numeric field across matching events
 * - **unique_count**: Count of distinct values for a field (e.g. unique users)
 * - **funnel_rate**: Conversion rate between two events (e.g. trial → subscription)
 * - **ratio**: Ratio of two event counts (e.g. signups / page_views)
 *
 * @since 128.0.0
 */
final readonly class ProjectionDefinition
{
    /**
     * Available aggregation types.
     */
    public const TYPE_COUNT = 'count';
    public const TYPE_SUM = 'sum';
    public const TYPE_AVERAGE = 'average';
    public const TYPE_UNIQUE_COUNT = 'unique_count';
    public const TYPE_FUNNEL_RATE = 'funnel_rate';
    public const TYPE_RATIO = 'ratio';

    /** @var list<string> All valid aggregation types */
    public const VALID_TYPES = [
        self::TYPE_COUNT,
        self::TYPE_SUM,
        self::TYPE_AVERAGE,
        self::TYPE_UNIQUE_COUNT,
        self::TYPE_FUNNEL_RATE,
        self::TYPE_RATIO,
    ];

    /** @var list<string> All valid time windows */
    public const VALID_WINDOWS = ['1h', '6h', '12h', '24h', '7d', '14d', '30d', '90d'];

    /**
     * @param  string  $name  Unique projection name (e.g. 'dau', 'trial_conversion_rate')
     * @param  string  $label  Human-readable label (e.g. 'Daily Active Users')
     * @param  string  $type  Aggregation type (one of VALID_TYPES)
     * @param  string  $event  Primary event name to aggregate (e.g. 'page_view', 'sign_up')
     * @param  string|null  $field  Field to aggregate for sum/average (e.g. 'value', 'duration')
     * @param  string|null  $distinctField  Field for unique_count distinct (e.g. 'client_id', 'user_id')
     * @param  string|null  $funnelTarget  Target event for funnel_rate (e.g. 'subscription' when event is 'start_trial')
     * @param  string|null  $ratioDenominator  Denominator event for ratio type (e.g. 'page_view' when event is 'sign_up')
     * @param  string|null  $window  Default time window (e.g. '7d', '30d')
     * @param  int|null  $cacheTtl  Cache TTL in seconds (null = use global default)
     * @param  string|null  $category  Metric category (e.g. 'growth', 'engagement', 'revenue', 'retention')
     * @param  string|null  $description  Human-readable description of what this metric measures
     * @param  array<string, mixed>  $filters  Default filter criteria (event params to match)
     * @param  list<string>  $tags  Organizational tags for grouping (e.g. ['saas', 'critical'])
     * @param  bool  $public  Whether this metric is exposed via the public API (no auth required)
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $type,
        public string $event,
        public ?string $field = null,
        public ?string $distinctField = null,
        public ?string $funnelTarget = null,
        public ?string $ratioDenominator = null,
        public ?string $window = null,
        public ?int $cacheTtl = null,
        public ?string $category = null,
        public ?string $description = null,
        public array $filters = [],
        public array $tags = [],
        public bool $public = false,
    ) {}

    /**
     * Validate the projection definition.
     *
     * @return list<string> List of validation errors (empty = valid)
     */
    public function validate(): array
    {
        $errors = [];

        if ($this->name === '') {
            $errors[] = 'Projection name must not be empty';
        }

        if (! in_array($this->type, self::VALID_TYPES, true)) {
            $errors[] = "Invalid type '{$this->type}'. Must be one of: " . implode(', ', self::VALID_TYPES);
        }

        if ($this->event === '') {
            $errors[] = 'Primary event name must not be empty';
        }

        // Type-specific validations
        match ($this->type) {
            self::TYPE_SUM, self::TYPE_AVERAGE => $this->validateFieldRequired($errors),
            self::TYPE_UNIQUE_COUNT => $this->validateDistinctFieldRequired($errors),
            self::TYPE_FUNNEL_RATE => $this->validateFunnelTargetRequired($errors),
            self::TYPE_RATIO => $this->validateRatioDenominatorRequired($errors),
            default => null,
        };

        if ($this->window !== null && ! in_array($this->window, self::VALID_WINDOWS, true)) {
            $errors[] = "Invalid window '{$this->window}'. Must be one of: " . implode(', ', self::VALID_WINDOWS);
        }

        return $errors;
    }

    /**
     * Check if this projection requires a specific field.
     */
    private function validateFieldRequired(array &$errors): void
    {
        if ($this->field === null || $this->field === '') {
            $errors[] = "Field is required for type '{$this->type}'";
        }
    }

    /**
     * Check if this projection requires a distinct field.
     */
    private function validateDistinctFieldRequired(array &$errors): void
    {
        if ($this->distinctField === null || $this->distinctField === '') {
            $errors[] = "Distinct field is required for type 'unique_count'";
        }
    }

    /**
     * Check if this projection requires a funnel target.
     */
    private function validateFunnelTargetRequired(array &$errors): void
    {
        if ($this->funnelTarget === null || $this->funnelTarget === '') {
            $errors[] = "Funnel target event is required for type 'funnel_rate'";
        }
    }

    /**
     * Check if this projection requires a ratio denominator.
     */
    private function validateRatioDenominatorRequired(array &$errors): void
    {
        if ($this->ratioDenominator === null || $this->ratioDenominator === '') {
            $errors[] = "Ratio denominator event is required for type 'ratio'";
        }
    }

    /**
     * Convert to array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'event' => $this->event,
            'window' => $this->window,
            'cache_ttl' => $this->cacheTtl,
            'category' => $this->category,
            'description' => $this->description,
            'filters' => $this->filters,
            'tags' => $this->tags,
            'public' => $this->public,
        ];

        if ($this->field !== null) {
            $result['field'] = $this->field;
        }

        if ($this->distinctField !== null) {
            $result['distinct_field'] = $this->distinctField;
        }

        if ($this->funnelTarget !== null) {
            $result['funnel_target'] = $this->funnelTarget;
        }

        if ($this->ratioDenominator !== null) {
            $result['ratio_denominator'] = $this->ratioDenominator;
        }

        return $result;
    }

    /**
     * Create a projection definition from an array (config-driven).
     *
     * @param  array<string, mixed>  $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            label: (string) ($data['label'] ?? $data['name'] ?? ''),
            type: (string) ($data['type'] ?? 'count'),
            event: (string) ($data['event'] ?? ''),
            field: isset($data['field']) ? (string) $data['field'] : null,
            distinctField: isset($data['distinct_field']) ? (string) $data['distinct_field'] : null,
            funnelTarget: isset($data['funnel_target']) ? (string) $data['funnel_target'] : null,
            ratioDenominator: isset($data['ratio_denominator']) ? (string) $data['ratio_denominator'] : null,
            window: isset($data['window']) ? (string) $data['window'] : null,
            cacheTtl: isset($data['cache_ttl']) ? (int) $data['cache_ttl'] : null,
            category: isset($data['category']) ? (string) $data['category'] : null,
            description: isset($data['description']) ? (string) $data['description'] : null,
            filters: is_array($data['filters'] ?? null) ? $data['filters'] : [],
            tags: is_array($data['tags'] ?? null) ? $data['tags'] : [],
            public: (bool) ($data['public'] ?? false),
        );
    }
}
