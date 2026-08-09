<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Schema;

/**
 * Immutable value object representing a single event's parameter schema.
 *
 * Defines required parameters, optional parameters with expected types,
 * and whether the event supports e-commerce item arrays.
 *
 * @since 6.1.0
 */
final readonly class EventParameterSchema
{
    /**
     * @param  string  $name  Event name (e.g. 'purchase')
     * @param  string  $category  Event category ('ecommerce', 'saas', 'engagement')
     * @param  list<string>  $required  Required parameter names
     * @param  array<string, string>  $optional  Optional parameter names mapped to expected types ('string', 'integer', 'float', 'boolean', 'array')
     * @param  bool  $itemParams  Whether this event supports e-commerce item arrays
     */
    public function __construct(
        public string $name,
        public string $category,
        public array $required,
        public array $optional,
        public bool $itemParams,
    ) {}

    /**
     * Convert to array representation for API responses.
     *
     * @return array{name: string, category: string, required: list<string>, optional: array<string, string>, item_params: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'category' => $this->category,
            'required' => $this->required,
            'optional' => $this->optional,
            'item_params' => $this->itemParams,
        ];
    }
}
