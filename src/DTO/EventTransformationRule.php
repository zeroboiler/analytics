<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing a single field transformation rule.
 *
 * Defines how a specific event parameter should be transformed before
 * being sent to a particular provider. Supports field renaming, type
 * casting, default values, and conditional filtering.
 *
 * @since 70.0.0
 */
final readonly class EventTransformationRule
{
    /**
     * @param  string  $sourceField  Original parameter name in the event
     * @param  string|null  $targetField  Renamed field for the target provider (null = keep original)
     * @param  'string'|'int'|'float'|'bool'|null  $castTo  Type cast to apply (null = no cast)
     * @param  mixed  $defaultValue  Default value if source field is missing
     * @param  bool  $dropIfMissing  Whether to omit the field entirely if source is missing
     * @param  bool  $dropAlways  Whether to always exclude this field from the provider payload
     * @param  callable(mixed): bool|null  $condition  Optional predicate; field is only included when callable returns true
     */
    public function __construct(
        public string $sourceField,
        public ?string $targetField = null,
        public ?string $castTo = null,
        public mixed $defaultValue = null,
        public bool $dropIfMissing = false,
        public bool $dropAlways = false,
        public ?\Closure $condition = null,
    ){}

    /**
     * Create a "rename" rule — changes field name for the target provider.
     */
    public static function rename(string $source, string $target): self
    {
        return new self(sourceField: $source, targetField: $target);
    }

    /**
     * Create a "drop" rule — excludes the field from the target provider payload.
     */
    public static function drop(string $field): self
    {
        return new self(sourceField: $field, dropAlways: true);
    }

    /**
     * Create a "cast" rule — converts the field value type.
     *
     * @param  'string'|'int'|'float'|'bool'  $type
     */
    public static function cast(string $field, string $type): self
    {
        return new self(sourceField: $field, castTo: $type);
    }

    /**
     * Create a "default" rule — provides a fallback value if missing.
     */
    public static function default(string $field, mixed $value): self
    {
        return new self(sourceField: $field, defaultValue: $value, dropIfMissing: false);
    }

    /**
     * Create a "conditional" rule — only includes field when predicate passes.
     *
     * @param  callable(mixed): bool  $predicate
     */
    public static function conditional(string $field, callable $predicate): self
    {
        return new self(sourceField: $field, condition: $predicate);
    }

    /**
     * Create a "required" rule — drops the event if this field is missing.
     */
    public static function required(string $field): self
    {
        return new self(sourceField: $field, dropIfMissing: true);
    }

    /**
     * Serialize rule to array (for config storage / API responses).
     *
     * @return array{source_field: string, target_field: string|null, cast_to: string|null, default_value: mixed, drop_if_missing: bool, drop_always: bool}
     */
    public function toArray(): array
    {
        return [
            'source_field' => $this->sourceField,
            'target_field' => $this->targetField,
            'cast_to' => $this->castTo,
            'default_value' => $this->defaultValue,
            'drop_if_missing' => $this->dropIfMissing,
            'drop_always' => $this->dropAlways,
        ];
    }

    /**
     * Create from config array.
     *
     * @param  array{source_field: string, target_field?: string, cast_to?: string, default_value?: mixed, drop_if_missing?: bool, drop_always?: bool}  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            sourceField: (string) ($config['source_field'] ?? ''),
            targetField: isset($config['target_field']) ? (string) $config['target_field'] : null,
            castTo: isset($config['cast_to']) ? (string) $config['cast_to'] : null,
            defaultValue: $config['default_value'] ?? null,
            dropIfMissing: (bool) ($config['drop_if_missing'] ?? false),
            dropAlways: (bool) ($config['drop_always'] ?? false),
        );
    }
}
