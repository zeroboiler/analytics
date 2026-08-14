<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Schema;

/**
 * Immutable event schema definition DTO.
 *
 * Represents a fully-defined analytics event schema produced by EventSchemaBuilder.
 * Contains the event name, category, description, provider mappings, tags,
 * and all property definitions with their types, constraints, and defaults.
 *
 * Used for runtime validation, FormRequest rule generation, provider mapping,
 * and documentation generation.
 *
 * @phpstan-import-type PropertyDefinitionArray from PropertyDefinition
 *
 * @since 118.0.0
 *
 * @see \ZeroBoiler\Analytics\Schema\EventSchemaBuilder
 */
final readonly class EventSchemaDefinition
{
    /**
     * @param  string  $name  Canonical event name (snake_case)
     * @param  string  $category  Event category (ecommerce, saas, engagement, custom)
     * @param  string  $description  Human-readable description
     * @param  list<string>  $tags  Taxonomy tags
     * @param  array<string, PropertyDefinition>  $properties  Property definitions
     * @param  string|null  $ga4  GA4 event name
     * @param  string|null  $meta  Meta Pixel event name
     * @param  string|null  $posthog  PostHog event name
     * @param  string|null  $plausible  Plausible event name
     * @param  string|null  $mixpanel  Mixpanel event name
     * @param  string|null  $amplitude  Amplitude event name
     * @param  string|null  $tiktok  TikTok event name
     * @param  string|null  $linkedin  LinkedIn event name
     */
    public function __construct(
        public string $name,
        public string $category,
        public string $description,
        public array $tags,
        public array $properties,
        public ?string $ga4,
        public ?string $meta,
        public ?string $posthog,
        public ?string $plausible,
        public ?string $mixpanel,
        public ?string $amplitude,
        public ?string $tiktok,
        public ?string $linkedin,
    ): void {}

    /**
     * Get the list of required property names.
     *
     * @return list<string>
     */
    public function requiredProperties(): array
    {
        $required = [];

        foreach ($this->properties as $name => $def) {
            if ($def->isRequired) {
                $required[] = $name;
            }
        }

        return $required;
    }

    /**
     * Get the list of optional property names.
     *
     * @return list<string>
     */
    public function optionalProperties(): array
    {
        $optional = [];

        foreach ($this->properties as $name => $def) {
            if (! $def->isRequired) {
                $optional[] = $name;
            }
        }

        return $optional;
    }

    /**
     * Get provider mappings as an array.
     *
     * @return array{ga4: string|null, meta: string|null, posthog: string|null, plausible: string|null, mixpanel: string|null, amplitude: string|null, tiktok: string|null, linkedin: string|null}
     */
    public function providerMappings(): array
    {
        return [
            'ga4' => $this->ga4,
            'meta' => $this->meta,
            'posthog' => $this->posthog,
            'plausible' => $this->plausible,
            'mixpanel' => $this->mixpanel,
            'amplitude' => $this->amplitude,
            'tiktok' => $this->tiktok,
            'linkedin' => $this->linkedin,
        ];
    }

    /**
     * Count providers that have a mapping (non-null).
     *
     * @return int
     */
    public function providerCoverageCount(): int
    {
        $mappings = $this->providerMappings();

        return count(array_filter($mappings, static fn (?string $v): bool => $v !== null));
    }

    /**
     * Convert to array representation.
     *
     * @return array{name: string, category: string, description: string, tags: list<string>, properties: array<string, array<string, mixed>>, required: list<string>, optional: list<string>, providers: array<string, string|null>, provider_count: int}
     */
    public function toArray(): array
    {
        $props = [];

        foreach ($this->properties as $name => $def) {
            $props[$name] = $def->toArray();
        }

        return [
            'name' => $this->name,
            'category' => $this->category,
            'description' => $this->description,
            'tags' => $this->tags,
            'properties' => $props,
            'required' => $this->requiredProperties(),
            'optional' => $this->optionalProperties(),
            'providers' => $this->providerMappings(),
            'provider_count' => $this->providerCoverageCount(),
        ];
    }

    /**
     * Convert to JSON string.
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }
}
