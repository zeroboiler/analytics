/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Blueprints;

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
