<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing a provider's capability profile.
 *
 * Contains all capabilities for a single provider along with metadata
 * (display name, type, supported version range) and derived statistics.
 * Produced by ProviderCapabilityMatrixService::getProfile().
 *
 * @since 215.0.0
 * @see \ZeroBoiler\Analytics\Services\ProviderCapabilityMatrixService
 */
final readonly class ProviderCapabilityProfile
{
    /**
     * @param  string  $provider  Provider identifier (ga4, meta, posthog, etc.)
     * @param  string  $displayName  Human-readable provider name
     * @param  string  $providerType  Provider type: 'client', 'server', 'hybrid'
     * @param  array<string, ProviderCapability>  $capabilities  Capability name → Capability DTO
     * @param  int  $supportedCount  Number of supported capabilities
     * @param  int  $totalCapabilities  Total capabilities checked
     * @param  float  $coveragePercent  Percentage of total capabilities supported
     * @param  list<string>  $missingCapabilities  List of unsupported capability names
     * @param  list<string>  $limitations  List of capabilities with limits (e.g. max_custom_dimensions: 50)
     * @param  string  $computedAt  ISO-8601 timestamp
     */
    public function __construct(
        public string $provider,
        public string $displayName,
        public string $providerType,
        public array $capabilities,
        public int $supportedCount,
        public int $totalCapabilities,
        public float $coveragePercent,
        public array $missingCapabilities,
        public array $limitations,
        public string $computedAt,
    ) {}

    /**
     * Check if a specific capability is supported.
     */
    public function supports(string $capabilityName): bool
    {
        return isset($this->capabilities[$capabilityName])
            && $this->capabilities[$capabilityName]->supported;
    }

    /**
     * Get a specific capability's value, or null if unsupported.
     */
    public function getCapabilityValue(string $capabilityName): mixed
    {
        if (! $this->supports($capabilityName)) {
            return null;
        }

        return $this->capabilities[$capabilityName]->value;
    }

    /**
     * Get all capabilities as array.
     *
     * @return list<array{name: string, type: string, supported: bool, value: mixed, description: string}>
     */
    public function capabilitiesList(): array
    {
        return array_map(
            static fn (ProviderCapability $cap): array => $cap->toArray(),
            array_values($this->capabilities),
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
            'provider' => $this->provider,
            'display_name' => $this->displayName,
            'provider_type' => $this->providerType,
            'capabilities' => $this->capabilitiesList(),
            'supported_count' => $this->supportedCount,
            'total_capabilities' => $this->totalCapabilities,
            'coverage_percent' => round($this->coveragePercent, 1),
            'missing_capabilities' => $this->missingCapabilities,
            'limitations' => $this->limitations,
            'computed_at' => $this->computedAt,
        ];
    }
}
