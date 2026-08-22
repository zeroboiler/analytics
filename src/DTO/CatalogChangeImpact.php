<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing the semantic version impact of a catalog change.
 *
 * Classifies catalog changes into major (breaking), minor (feature), or patch
 * (fix/non-breaking) severity levels for automated versioning decisions.
 *
 * @since 216.0.0
 */
final readonly class CatalogChangeImpact
{
    /**
     * @param  string  $type  Change type: 'event_added', 'event_removed', 'event_renamed', 'category_changed', 'provider_mapping_added', 'provider_mapping_removed', 'provider_mapping_changed', 'class_changed'
     * @param  string  $eventName  Affected event name (snake_case canonical)
     * @param  string  $severity  Impact severity: 'major', 'minor', 'patch'
     * @param  string  $description  Human-readable description of the change
     * @param  string|null  $oldValue  Previous value (for changes)
     * @param  string|null  $newValue  New value (for changes)
     * @param  string|null  $category  Event category
     * @param  bool  $breaking  Whether this change is considered breaking
     * @param  array<string, mixed>  $metadata  Additional context
     */
    public function __construct(
        public string $type,
        public string $eventName,
        public string $severity,
        public string $description,
        public ?string $oldValue = null,
        public ?string $newValue = null,
        public ?string $category = null,
        public bool $breaking = false,
        public array $metadata = [],
    ) {}

    /**
     * Create a major (breaking) change impact.
     *
     * @param  string  $type
     * @param  string  $eventName
     * @param  string  $description
     * @param  array<string, mixed>  $metadata
     * @return self
     */
    public static function major(string $type, string $eventName, string $description, array $metadata = []): self
    {
        return new self(
            type: $type,
            eventName: $eventName,
            severity: 'major',
            description: $description,
            breaking: true,
            metadata: $metadata,
        );
    }

    /**
     * Create a minor (feature) change impact.
     *
     * @param  string  $type
     * @param  string  $eventName
     * @param  string  $description
     * @param  array<string, mixed>  $metadata
     * @return self
     */
    public static function minor(string $type, string $eventName, string $description, array $metadata = []): self
    {
        return new self(
            type: $type,
            eventName: $eventName,
            severity: 'minor',
            description: $description,
            metadata: $metadata,
        );
    }

    /**
     * Create a patch (non-breaking) change impact.
     *
     * @param  string  $type
     * @param  string  $eventName
     * @param  string  $description
     * @param  array<string, mixed>  $metadata
     * @return self
     */
    public static function patch(string $type, string $eventName, string $description, array $metadata = []): self
    {
        return new self(
            type: $type,
            eventName: $eventName,
            severity: 'patch',
            description: $description,
            metadata: $metadata,
        );
    }

    /**
     * Serialize to array for JSON/Cache storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'event_name' => $this->eventName,
            'severity' => $this->severity,
            'description' => $this->description,
            'old_value' => $this->oldValue,
            'new_value' => $this->newValue,
            'category' => $this->category,
            'breaking' => $this->breaking,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Deserialize from array.
     *
     * @param  array<string, mixed>  $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) ($data['type'] ?? ''),
            eventName: (string) ($data['event_name'] ?? ''),
            severity: (string) ($data['severity'] ?? 'patch'),
            description: (string) ($data['description'] ?? ''),
            oldValue: isset($data['old_value']) ? (string) $data['old_value'] : null,
            newValue: isset($data['new_value']) ? (string) $data['new_value'] : null,
            category: isset($data['category']) ? (string) $data['category'] : null,
            breaking: (bool) ($data['breaking'] ?? false),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }
}
