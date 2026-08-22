<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Schema;

/**
 * Immutable value object representing a single event field definition.
 *
 * Defines the type, required status, description, allowed values,
 * and default value for an event parameter. Used by EventFieldRegistry
 * to build per-event schemas for validation and documentation.
 *
 * @since 133.0.0
 */
final class FieldDefinition
{
    /**
     * @param  string  $type  Field type: string, int, float, bool, array, numeric
     * @param  bool  $required  Whether the field is required
     * @param  string  $description  Human-readable field description
     * @param  list<string>  $allowedValues  Enum-like allowed values (empty = no constraint)
     * @param  mixed  $defaultValue  Default value when field is optional and not provided
     */
    public function __construct(
        public readonly string $type,
        public readonly bool $required,
        public readonly string $description,
        public array $allowedValues = [],
        public mixed $defaultValue = null,
    ){}

    /**
     * Convert to array representation for serialization/documentation.
     *
     * @return array{type: string, required: bool, description: string, allowed_values: list<string>, default: mixed}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'required' => $this->required,
            'description' => $this->description,
            'allowed_values' => $this->allowedValues,
            'default' => $this->defaultValue,
        ];
    }
}
