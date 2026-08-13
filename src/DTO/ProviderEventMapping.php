<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing a provider-specific event transformation mapping.
 *
 * Groups transformation rules for a single event type targeting a specific
 * provider. Enables per-provider field renaming, dropping, casting, and
 * default value injection.
 *
 * Inspired by Segment Protocols, RudderStack Transformations, and mParticle
 * Data Planning — the industry standard for provider-specific event mapping.
 *
 * @since 70.0.0
 */
final readonly class ProviderEventMapping
{
    /**
     * @param  string  $eventName  Analytics event name (e.g., 'purchase')
     * @param  string  $provider  Target provider identifier (e.g., 'ga4', 'meta', 'posthog')
     * @param  list<EventTransformationRule>  $rules  Ordered list of transformation rules
     * @param  array<string, mixed>  $staticOverrides  Static fields to always merge into the provider payload
     * @param  list<string>  $allowOnly  If non-empty, ONLY these fields are included (whitelist mode)
     * @param  string|null  $eventNameOverride  Override the event name for this provider (e.g., 'Purchase' for Meta)
     */
    public function __construct(
        public string $eventName,
        public string $provider,
        public array $rules = [],
        public array $staticOverrides = [],
        public array $allowOnly = [],
        public ?string $eventNameOverride = null,
    ): void {}

    /**
     * Create from config array.
     *
     * @param  array{event: string, provider: string, rules?: list<array<string, mixed>>, static_overrides?: array<string, mixed>, allow_only?: list<string>, event_name_override?: string|null}  $config
     * @return self
     */
    public static function fromArray(array $config): self
    {
        $rules = [];
        foreach ($config['rules'] ?? [] as $ruleConfig) {
            $rules[] = EventTransformationRule::fromArray($ruleConfig);
        }

        return new self(
            eventName: (string) ($config['event'] ?? ''),
            provider: (string) ($config['provider'] ?? ''),
            rules: $rules,
            staticOverrides: (array) ($config['static_overrides'] ?? []),
            allowOnly: (array) ($config['allow_only'] ?? []),
            eventNameOverride: isset($config['event_name_override']) ? (string) $config['event_name_override'] : null,
        );
    }

    /**
     * Serialize to array for config storage / API responses.
     *
     * @return array{event: string, provider: string, rules: list<array<string, mixed>>, static_overrides: array<string, mixed>, allow_only: list<string>, event_name_override: string|null}
     */
    public function toArray(): array
    {
        return [
            'event' => $this->eventName,
            'provider' => $this->provider,
            'rules' => array_map(fn (EventTransformationRule $r): array => $r->toArray(), $this->rules),
            'static_overrides' => $this->staticOverrides,
            'allow_only' => $this->allowOnly,
            'event_name_override' => $this->eventNameOverride,
        ];
    }

    /**
     * Get the unique key for this mapping (event:provider).
     */
    public function key(): string
    {
        return $this->eventName . ':' . $this->provider;
    }
}
