<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Blueprints;

/**
 * Immutable DTO representing a reusable event blueprint.
 *
 * Blueprints define reusable event templates with a predefined structure,
 * default parameters, required fields, and parameter type constraints.
 * Inspired by Segment's Event Spec and RudderStack's event templates,
 * blueprints allow teams to standardize event tracking across codebases
 * and enforce consistent parameter naming.
 *
 * @since 66.0.0
 *
 * @example
 *   $blueprint = new EventBlueprint(
 *       name: 'signup_completed',
 *       label: 'Signup Completed',
 *       description: 'Fires when user completes email verification',
 *       baseEvent: 'sign_up',
 *       category: 'saas',
 *       defaultParams: ['signup_method' => 'email'],
 *       requiredParams: ['user_id', 'email_hash'],
 *       paramTypes: ['user_id' => 'string', 'email_hash' => 'string', 'signup_method' => 'string'],
 *       priority: 'critical',
 *       version: '1.0.0',
 *   );
 */
final readonly class EventBlueprint
{
    /**
     * @param  string  $name  Blueprint identifier (dot.case, e.g. 'saas.signup.email')
     * @param  string  $label  Human-readable label
     * @param  string  $description  What triggers this blueprint
     * @param  string  $baseEvent  Catalog event name this blueprint wraps
     * @param  string  $category  Event category (ecommerce, saas, engagement, custom)
     * @param  array<string, mixed>  $defaultParams  Default parameter values
     * @param  list<string>  $requiredParams  Parameter keys that must be provided
     * @param  array<string, string>  $paramTypes  Parameter name → type mapping (string|int|float|bool|array)
     * @param  string|null  $priority  Default priority for events from this blueprint
     * @param  string  $version  Blueprint version (semver)
     * @param  array<string, mixed>  $metadata  Arbitrary metadata (team, owner, deprecated, etc.)
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $description = '',
        public string $baseEvent = '',
        public string $category = 'custom',
        public array $defaultParams = [],
        public array $requiredParams = [],
        public array $paramTypes = [],
        public ?string $priority = null,
        public string $version = '1.0.0',
        public array $metadata = [],
    ) {}

    /**
     * Create from array (for config-based registration).
     *
     * @param  array{name: string, label?: string, description?: string, base_event?: string, category?: string, default_params?: array<string, mixed>, required_params?: list<string>, param_types?: array<string, string>, priority?: string|null, version?: string, metadata?: array<string, mixed>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            label: (string) ($data['label'] ?? $data['name'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            baseEvent: (string) ($data['base_event'] ?? $data['baseEvent'] ?? ''),
            category: (string) ($data['category'] ?? 'custom'),
            defaultParams: (array) ($data['default_params'] ?? $data['defaultParams'] ?? []),
            requiredParams: (array) ($data['required_params'] ?? $data['requiredParams'] ?? []),
            paramTypes: (array) ($data['param_types'] ?? $data['paramTypes'] ?? []),
            priority: isset($data['priority']) ? (string) $data['priority'] : null,
            version: (string) ($data['version'] ?? '1.0.0'),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'description' => $this->description,
            'base_event' => $this->baseEvent,
            'category' => $this->category,
            'default_params' => $this->defaultParams,
            'required_params' => $this->requiredParams,
            'param_types' => $this->paramTypes,
            'priority' => $this->priority,
            'version' => $this->version,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Validate a set of parameters against this blueprint's requirements.
     *
     * Returns an array of validation error messages. Empty array means valid.
     *
     * @param  array<string, mixed>  $params  Parameters to validate
     * @return list<string>
     */
    public function validateParams(array $params): array
    {
        $errors = [];

        foreach ($this->requiredParams as $key) {
            if (! array_key_exists($key, $params)) {
                $errors[] = "Missing required parameter: '{$key}'";
            }
        }

        foreach ($this->paramTypes as $key => $expectedType) {
            if (! array_key_exists($key, $params)) {
                continue;
            }

            $value = $params[$key];
            $actualType = gettype($value);

            $typeMatches = match ($expectedType) {
                'string' => $actualType === 'string' || $value instanceof \Stringable,
                'int', 'integer' => $actualType === 'integer',
                'float', 'double', 'number' => in_array($actualType, ['double', 'integer'], true),
                'bool', 'boolean' => $actualType === 'boolean',
                'array' => $actualType === 'array',
                'mixed' => true,
                default => true, // Unknown types pass through
            };

            if (! $typeMatches) {
                $errors[] = "Parameter '{$key}' expected type '{$expectedType}', got '{$actualType}'";
            }
        }

        return $errors;
    }

    /**
     * Check if this blueprint is deprecated.
     */
    public function isDeprecated(): bool
    {
        return ($this->metadata['deprecated'] ?? false) === true;
    }

    /**
     * Get the deprecation notice, if any.
     */
    public function deprecationNotice(): ?string
    {
        $notice = $this->metadata['deprecation_notice'] ?? null;

        return is_string($notice) && $notice !== '' ? $notice : null;
    }

    /**
     * Get the owner/team for this blueprint.
     */
    public function owner(): ?string
    {
        $owner = $this->metadata['owner'] ?? null;

        return is_string($owner) && $owner !== '' ? $owner : null;
    }

    /**
     * Check if this blueprint is for a specific version.
     */
    public function matchesVersion(string $version): bool
    {
        return $this->version === $version;
    }

    // ── Fluent Builder (v247.0.0) ───────────────────────────────

    /**
     * Start building a new EventBlueprint fluently.
     *
     * @param  string  $name  Blueprint identifier
     * @return EventBlueprintBuilder
     */
    public static function make(string $name, string $label = ''): EventBlueprintBuilder
    {
        return new EventBlueprintBuilder($name, $label);
    }
}

/**
 * Fluent builder for EventBlueprint instances.
 *
 * @since 247.0.0
 */
final class EventBlueprintBuilder
{
    private string $name;
    private string $label;
    private string $description = '';
    private string $baseEvent = '';
    private string $category = 'custom';
    /** @var array<string, mixed> */
    private array $defaultParams = [];
    /** @var list<string> */
    private array $requiredParams = [];
    /** @var array<string, string> */
    private array $paramTypes = [];
    private ?string $priority = null;
    private string $version = '1.0.0';
    /** @var array<string, mixed> */
    private array $metadata = [];

    public function __construct(string $name, string $label)
    {
        $this->name = $name;
        $this->label = $label !== '' ? $label : $name;
    }

    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function baseEvent(string $baseEvent): self
    {
        $this->baseEvent = $baseEvent;
        return $this;
    }

    public function category(string $category): self
    {
        $this->category = $category;
        return $this;
    }

    /**
     * Add a default parameter.
     *
     * @param  string  $key  Parameter name
     * @param  mixed  $value  Default value
     */
    public function default(string $key, mixed $value): self
    {
        $this->defaultParams[$key] = $value;
        return $this;
    }

    /**
     * Define a parameter with its type, required flag, and optional default.
     *
     * @param  string  $name  Parameter name
     * @param  string  $type  Type (string|int|float|bool|array|mixed)
     * @param  bool  $required  Whether this param is required
     * @param  mixed  $default  Default value (only used if not required)
     */
    public function param(string $name, string $type, bool $required = false, mixed $default = null): self
    {
        $this->paramTypes[$name] = $type;

        if ($required) {
            $this->requiredParams[] = $name;
        } elseif ($default !== null) {
            $this->defaultParams[$name] = $default;
        }

        return $this;
    }

    public function priority(string $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    public function version(string $version): self
    {
        $this->version = $version;
        return $this;
    }

    /**
     * Set metadata entries.
     *
     * @param  array<string, mixed>  $metadata  Metadata key-value pairs
     */
    public function metadata(array $metadata): self
    {
        $this->metadata = array_merge($this->metadata, $metadata);
        return $this;
    }

    /**
     * Build the immutable EventBlueprint DTO.
     */
    public function build(): EventBlueprint
    {
        return new EventBlueprint(
            name: $this->name,
            label: $this->label,
            description: $this->description,
            baseEvent: $this->baseEvent,
            category: $this->category,
            defaultParams: $this->defaultParams,
            requiredParams: $this->requiredParams,
            paramTypes: $this->paramTypes,
            priority: $this->priority,
            version: $this->version,
            metadata: $this->metadata,
        );
    }
}
