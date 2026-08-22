<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Schema;

/**
 * Immutable property definition within an event schema.
 *
 * Represents a single parameter definition in an EventSchemaBuilder,
 * including type, required/optional status, default value, constraints,
 * and description. Returned by builder methods for chainable configuration.
 *
 * @since 118.0.0
 *
 * @see \ZeroBoiler\Analytics\Schema\EventSchemaBuilder
 */
final class PropertyDefinition
{
    /** @var string Property name */
    public readonly string $name;

    /** @var string Property type (string, int, float, bool, array, enum, timestamp, email, url, numeric) */
    public readonly string $type;

    /** @var list<string> Allowed values for enum type */
    public array $enumValues = [];

    /** @var bool Whether this property is required */
    public bool $isRequired = false;

    /** @var mixed Default value when not provided */
    public mixed $defaultValue = null;

    /** @var bool Whether a default value is set */
    public bool $hasDefault = false;

    /** @var string|null Property description */
    public ?string $description = null;

    /** @var int|null Maximum string length */
    public int $maxLength = 500;

    /** @var int Minimum numeric value */
    public int|float $minValue = 0;

    /** @var int|float Maximum numeric value */
    public int|float $maxValue = 999999999;

    /** @var int Maximum array length */
    public int $maxArrayLength = 100;

    /** @var string|null Regex pattern for string validation */
    public ?string $pattern = null;

    /** @var list<string> Example values for documentation */
    public array $examples = [];

    /**
     * @param  string  $name  Property name
     * @param  string  $type  Property type
     * @param  list<string>  $enumValues  Allowed enum values (empty if not enum)
     */
    public function __construct(string $name, string $type, array $enumValues = [])
    {
        $this->name = $name;
        $this->type = $type;
        $this->enumValues = $enumValues;
    }

    /**
     * Mark this property as required.
     *
     * @return self
     */
    public function required(): self
    {
        $this->isRequired = true;

        return $this;
    }

    /**
     * Mark this property as optional (default).
     *
     * @return self
     */
    public function optional(): self
    {
        $this->isRequired = false;

        return $this;
    }

    /**
     * Set the default value for this property.
     *
     * @param  mixed  $value  Default value
     * @return self
     */
    public function default(mixed $value): self
    {
        $this->defaultValue = $value;
        $this->hasDefault = true;

        return $this;
    }

    /**
     * Set the property description.
     *
     * @param  string  $description  Description text
     * @return self
     */
    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Set the maximum string length.
     *
     * @param  int  $length  Maximum length
     * @return self
     */
    public function maxLength(int $length): self
    {
        $this->maxLength = $length;

        return $this;
    }

    /**
     * Set the minimum numeric value.
     *
     * @param  int|float  $min  Minimum value
     * @return self
     */
    public function min(int|float $min): self
    {
        $this->minValue = $min;

        return $this;
    }

    /**
     * Set the maximum numeric value.
     *
     * @param  int|float  $max  Maximum value
     * @return self
     */
    public function max(int|float $max): self
    {
        $this->maxValue = $max;

        return $this;
    }

    /**
     * Set the maximum array length.
     *
     * @param  int  $length  Maximum array length
     * @return self
     */
    public function maxArrayLength(int $length): self
    {
        $this->maxArrayLength = $length;

        return $this;
    }

    /**
     * Set a regex validation pattern.
     *
     * @param  string  $pattern  Regex pattern
     * @return self
     */
    public function pattern(string $pattern): self
    {
        $this->pattern = $pattern;

        return $this;
    }

    /**
     * Add example values for documentation.
     *
     * @param  mixed  ...$values  Example values
     * @return self
     */
    public function example(mixed ...$values): self
    {
        foreach ($values as $value) {
            $this->examples[] = $value;
        }

        return $this;
    }

    /**
     * Convert to array representation.
     *
     * @return array{name: string, type: string, required: bool, default: mixed|null, has_default: bool, description: string|null, enum_values: list<string>, max_length: int, min: int|float, max: int|float, pattern: string|null, examples: list<mixed>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'required' => $this->isRequired,
            'default' => $this->defaultValue,
            'has_default' => $this->hasDefault,
            'description' => $this->description,
            'enum_values' => $this->enumValues,
            'max_length' => $this->maxLength,
            'min' => $this->minValue,
            'max' => $this->maxValue,
            'pattern' => $this->pattern,
            'examples' => $this->examples,
        ];
    }
}
