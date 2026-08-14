<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Macros;

/**
 * A reusable, parameterized analytics event macro.
 *
 * Macros define named event templates with default parameters, required
 * parameter keys, and optional transforms. When executed, they merge
 * caller-provided params with defaults, apply transforms, and dispatch
 * the event through AnalyticsManager.
 *
 * Useful for DRY event tracking patterns:
 * - "feature_used" macro that auto-attaches user_id, session context, and timestamp
 * - "saas_conversion" macro with revenue parameters and attribution
 * - "engagement" macro with scroll depth, time-on-page thresholds
 *
 * @since 118.0.0
 */
final class AnalyticsMacro
{
    /**
     * Create a new analytics macro.
     *
     * @param  string  $name  Unique macro identifier (e.g. 'feature_used', 'saas_conversion')
     * @param  string  $eventName  The analytics event name to dispatch (e.g. 'feature_used', 'purchase')
     * @param  array<string, mixed>  $defaults  Default parameter values merged into every invocation
     * @param  list<string>  $requiredKeys  Parameter keys that must be provided by the caller
     * @param  list<string>  $tags  Category/organizational tags for macro discovery
     * @param  string|null  $description  Human-readable macro description
     */
    public function __construct(
        private readonly string $name,
        private readonly string $eventName,
        private readonly array $defaults = [],
        private readonly array $requiredKeys = [],
        private readonly array $tags = [],
        private readonly ?string $description = null,
    ): void {}

    /**
     * Get the macro name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Get the underlying analytics event name.
     */
    public function eventName(): string
    {
        return $this->eventName;
    }

    /**
     * Get the default parameters.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return $this->defaults;
    }

    /**
     * Get required parameter keys.
     *
     * @return list<string>
     */
    public function requiredKeys(): array
    {
        return $this->requiredKeys;
    }

    /**
     * Get organizational tags.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return $this->tags;
    }

    /**
     * Get the macro description.
     */
    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * Build the final event parameters by merging caller params with defaults.
     *
     * Caller params take precedence over defaults. Validates that all required
     * keys are present in the final merged params.
     *
     * @param  array<string, mixed>  $params  Caller-provided parameters
     * @return array{params: array<string, mixed>, missing: list<string>}
     *
     * @throws \InvalidArgumentException If required keys are missing
     */
    public function build(array $params = []): array
    {
        $merged = array_merge($this->defaults, $params);
        $missing = [];

        foreach ($this->requiredKeys as $key) {
            if (! array_key_exists($key, $merged) || $merged[$key] === null) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException(
                "Analytics macro '{$this->name}' requires parameters: " . implode(', ', $missing)
            );
        }

        return ['params' => $merged, 'missing' => []];
    }

    /**
     * Convert the macro to an array representation.
     *
     * @return array{name: string, event_name: string, defaults: array<string, mixed>, required_keys: list<string>, tags: list<string>, description: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'event_name' => $this->eventName,
            'defaults' => $this->defaults,
            'required_keys' => $this->requiredKeys,
            'tags' => $this->tags,
            'description' => $this->description,
        ];
    }
}
