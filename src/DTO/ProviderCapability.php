<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing a single analytics provider capability.
 *
 * Each capability has a name, type, boolean status, optional limit/value,
 * and a human-readable description. Used by ProviderCapabilityMatrix
 * to describe what each provider supports.
 *
 * @since 215.0.0
 * @see \ZeroBoiler\Analytics\Services\ProviderCapabilityMatrixService
 */
final readonly class ProviderCapability
{
    /**
     * Create a new ProviderCapability instance.
     *
     * @param  string  $name  Capability identifier (e.g. 'user_properties', 'batch_api')
     * @param  string  $type  Capability type: 'feature', 'limit', 'format', 'protocol'
     * @param  bool  $supported  Whether the provider supports this capability
     * @param  mixed  $value  Optional limit value (e.g. 25 for max custom dimensions)
     * @param  string  $description  Human-readable description
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $supported,
        public mixed $value = null,
        public string $description = '',
    )  {}

    /**
     * Serialize to array.
     *
     * @return array{name: string, type: string, supported: bool, value: mixed, description: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'supported' => $this->supported,
            'value' => $this->value,
            'description' => $this->description,
        ];
    }

    /**
     * Create from array (round-trip support).
     *
     * @param  array{name: string, type: string, supported: bool, value?: mixed, description?: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            type: $data['type'],
            supported: $data['supported'],
            value: $data['value'] ?? null,
            description: $data['description'] ?? '',
        );
    }
}
