<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Attributes;

use Attribute;

/**
 * Attribute for declaring event parameter schemas on DTO classes or methods.
 *
 * Provides type-safe parameter validation at the attribute level.
 * When applied to an event class, the EventSchemaValidationService
 * reads this to validate incoming event parameters.
 *
 * @since 19.0.0
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
final readonly class AnalyticsEventParam
{
    /**
     * @param  string  $name  Parameter name
     * @param  'string'|'int'|'float'|'bool'|'array'  $type  Expected parameter type
     * @param  bool  $required  Whether this parameter is required
     * @param  string|null  $description  Parameter description
     * @param  mixed|null  $default  Default value when not provided
     * @param  string|null  $pattern  Regex pattern for string validation
     * @param  float|null  $min  Minimum value for numeric types
     * @param  float|null  $max  Maximum value for numeric types
     * @param  int|null  $maxLength  Maximum string length
     */
    public function __construct(
        public string $name,
        public string $type = 'string',
        public bool $required = false,
        public ?string $description = null,
        public mixed $default = null,
        public ?string $pattern = null,
        public ?float $min = null,
        public ?float $max = null,
        public ?int $maxLength = null,
    ) {}
}
