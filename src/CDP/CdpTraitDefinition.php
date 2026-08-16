<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\CDP;

/**
 * Definition of a computed or static CDP user trait.
 *
 * Traits represent user properties that are either:
 * - **Static**: Set directly via `identify()` calls (e.g., name, email, plan)
 * - **Computed**: Calculated from event history (e.g., total_revenue, session_count)
 *
 * Computed traits have a computation source (event type + aggregation method)
 * and a recalculation schedule.
 *
 * @since 196.0.0
 */
final readonly class CdpTraitDefinition
{
    /**
     * Create a new trait definition.
     *
     * @param  string  $name  Trait name (snake_case, e.g. 'total_revenue')
     * @param  string  $type  Value type: 'string', 'int', 'float', 'bool', 'array'
     * @param  string  $category  Trait category: 'identity', 'engagement', 'revenue', 'lifecycle', 'custom'
     * @param  bool  $computed  Whether this trait is computed from events
     * @param  string|null  $sourceEvent  Event name to aggregate (for computed traits)
     * @param  string|null  $aggregation  Aggregation method: 'sum', 'count', 'avg', 'max', 'min', 'latest', 'unique_count'
     * @param  string|null  $sourceField  Field name in event properties to aggregate
     * @param  int  $recalculateIntervalSeconds  How often to recalculate (0 = on every event)
     * @param  mixed|null  $defaultValue  Default value when no data exists
     * @param  string|null  $description  Human-readable description
     */
    public function __construct(
        public string $name,
        public string $type = 'string',
        public string $category = 'custom',
        public bool $computed = false,
        public ?string $sourceEvent = null,
        public ?string $aggregation = null,
        public ?string $sourceField = null,
        public int $recalculateIntervalSeconds = 0,
        public mixed $defaultValue = null,
        public ?string $description = null,
    ) {}

    /**
     * Create a static (manually set) trait definition.
     *
     * @param  string  $name  Trait name
     * @param  string  $type  Value type
     * @param  string  $category  Trait category
     * @param  mixed|null  $defaultValue  Default value
     * @param  string|null  $description  Human-readable description
     * @return self
     */
    public static function static(
        string $name,
        string $type = 'string',
        string $category = 'identity',
        mixed $defaultValue = null,
        ?string $description = null,
    ): self {
        return new self(
            name: $name,
            type: $type,
            category: $category,
            computed: false,
            defaultValue: $defaultValue,
            description: $description,
        );
    }

    /**
     * Create a computed (event-derived) trait definition.
     *
     * @param  string  $name  Trait name
     * @param  string  $sourceEvent  Event name to aggregate
     * @param  string  $aggregation  Aggregation method
     * @param  string  $type  Value type
     * @param  string|null  $sourceField  Field name to aggregate
     * @param  int  $recalculateIntervalSeconds  Recalculation interval
     * @param  mixed|null  $defaultValue  Default value
     * @param  string|null  $description  Human-readable description
     * @return self
     */
    public static function computed(
        string $name,
        string $sourceEvent,
        string $aggregation,
        string $type = 'float',
        ?string $sourceField = null,
        int $recalculateIntervalSeconds = 0,
        mixed $defaultValue = null,
        ?string $description = null,
    ): self {
        return new self(
            name: $name,
            type: $type,
            category: 'engagement',
            computed: true,
            sourceEvent: $sourceEvent,
            aggregation: $aggregation,
            sourceField: $sourceField,
            recalculateIntervalSeconds: $recalculateIntervalSeconds,
            defaultValue: $defaultValue,
            description: $description,
        );
    }

    /**
     * Serialize to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'category' => $this->category,
            'computed' => $this->computed,
            'source_event' => $this->sourceEvent,
            'aggregation' => $this->aggregation,
            'source_field' => $this->sourceField,
            'recalculate_interval' => $this->recalculateIntervalSeconds,
            'default_value' => $this->defaultValue,
            'description' => $this->description,
        ];
    }

    /**
     * Create from array.
     *
     * @param  array<string, mixed>  $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            type: (string) ($data['type'] ?? 'string'),
            category: (string) ($data['category'] ?? 'custom'),
            computed: (bool) ($data['computed'] ?? false),
            sourceEvent: isset($data['source_event']) ? (string) $data['source_event'] : null,
            aggregation: isset($data['aggregation']) ? (string) $data['aggregation'] : null,
            sourceField: isset($data['source_field']) ? (string) $data['source_field'] : null,
            recalculateIntervalSeconds: (int) ($data['recalculate_interval'] ?? 0),
            defaultValue: $data['default_value'] ?? null,
            description: isset($data['description']) ? (string) $data['description'] : null,
        );
    }
}
