<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Schema;

/**
 * Fluent analytics event schema builder — Laravel Schema Builder for analytics events.
 *
 * Provides a declarative, fluent API for defining analytics event schemas
 * programmatically. Inspired by Laravel's database Schema Builder, this
 * enables developers to define event structures with type constraints,
 * required fields, default values, enum validation, and provider-specific
 * mappings in a readable, chainable format.
 *
 * Built schemas integrate with EventSchemaRegistry for runtime validation,
 * EventPropertySchema for type coercion, and EventCatalog for provider mapping.
 *
 * Usage:
 *   $schema = EventSchemaBuilder::define('subscription_upgraded')
 *       ->category('saas')
 *       ->description('Fires when a user upgrades their subscription plan')
 *       ->string('user_id')->required()
 *       ->string('plan_from')
 *       ->string('plan_to')->required()
 *       ->float('price_change')
 *       ->string('currency')->default('USD')
 *       ->enum('billing_cycle', ['monthly', 'yearly', 'lifetime'])
 *       ->ga4('subscription_upgraded')
 *       ->meta('Subscribe')
 *       ->posthog('plan_upgraded')
 *       ->tag('billing', 'revenue', 'acquisition')
 *       ->build();
 *
 * The build() method returns an EventSchemaDefinition DTO that can be
 * registered with EventSchemaRegistryExtended or used standalone.
 *
 * @since 118.0.0
 *
 * @see \ZeroBoiler\Analytics\Schema\EventSchemaRegistryExtended
 * @see \ZeroBoiler\Analytics\Schema\EventSchemaDefinition
 */
final class EventSchemaBuilder
{
    /** @var string Event name (canonical snake_case) */
    private string $name;

    /** @var string Event category (ecommerce, saas, engagement, security, uptime, infrastructure, custom) */
    private string $category = 'custom';

    /** @var string Human-readable description */
    private string $description = '';

    /** @var list<string> Event tags for taxonomy classification */
    private array $tags = [];

    /** @var string|null GA4 event name */
    private ?string $ga4Name = null;

    /** @var string|null Meta Pixel event name */
    private ?string $metaName = null;

    /** @var string|null PostHog event name */
    private ?string $posthogName = null;

    /** @var string|null Plausible event name */
    private ?string $plausibleName = null;

    /** @var string|null Mixpanel event name */
    private ?string $mixpanelName = null;

    /** @var string|null Amplitude event name */
    private ?string $amplitudeName = null;

    /** @var string|null TikTok event name */
    private ?string $tiktokName = null;

    /** @var string|null LinkedIn event name */
    private ?string $linkedinName = null;

    /** @var array<string, PropertyDefinition> Defined properties */
    private array $properties = [];

    /** @var string|null Name of the last defined property (for fluent forwarding) */
    private ?string $lastName = null;

    /**
     * Create a new schema builder for the given event name.
     *
     * @param  string  $name  Event name (canonical snake_case)
     * @return self
     */
    public static function define(string $name): self
    {
        return new self($name);
    }

    /**
     * @param  string  $name  Event name
     */
    private function __construct(string $name): void
    {
        $this->name = $name;
    }

    /**
     * Set the event category.
     *
     * @param  string  $category  One of: ecommerce, saas, engagement, security, uptime, infrastructure, custom
     * @return self
     */
    public function category(string $category): self
    {
        $this->category = $category;

        return $this;
    }

    /**
     * Set the event description.
     *
     * @param  string  $description  Human-readable description of the event
     * @return self
     */
    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Add tags for taxonomy classification.
     *
     * @param  string  ...$tags  Tags (e.g. 'billing', 'revenue', 'acquisition')
     * @return self
     */
    public function tag(string ...$tags): self
    {
        foreach ($tags as $tag) {
            if (! in_array($tag, $this->tags, true)) {
                $this->tags[] = $tag;
            }
        }

        return $this;
    }

    // ─── Provider Mappings ──────────────────────────────────────

    /**
     * Set the GA4 event name mapping.
     *
     * @param  string  $name  GA4 event name (e.g. 'purchase', 'sign_up')
     * @return self
     */
    public function ga4(string $name): self
    {
        $this->ga4Name = $name;

        return $this;
    }

    /**
     * Set the Meta Pixel event name mapping.
     *
     * @param  string|null  $name  Meta Pixel event name (e.g. 'Purchase', 'CompleteRegistration')
     * @return self
     */
    public function meta(?string $name): self
    {
        $this->metaName = $name;

        return $this;
    }

    /**
     * Set the PostHog event name mapping.
     *
     * @param  string  $name  PostHog event name (e.g. '$signup', 'purchase')
     * @return self
     */
    public function posthog(string $name): self
    {
        $this->posthogName = $name;

        return $this;
    }

    /**
     * Set the Plausible event name mapping.
     *
     * @param  string|null  $name  Plausible custom event name
     * @return self
     */
    public function plausible(?string $name): self
    {
        $this->plausibleName = $name;

        return $this;
    }

    /**
     * Set the Mixpanel event name mapping.
     *
     * @param  string|null  $name  Mixpanel event name
     * @return self
     */
    public function mixpanel(?string $name): self
    {
        $this->mixpanelName = $name;

        return $this;
    }

    /**
     * Set the Amplitude event name mapping.
     *
     * @param  string|null  $name  Amplitude event name
     * @return self
     */
    public function amplitude(?string $name): self
    {
        $this->amplitudeName = $name;

        return $this;
    }

    /**
     * Set the TikTok Pixel event name mapping.
     *
     * @param  string|null  $name  TikTok event name
     * @return self
     */
    public function tiktok(?string $name): self
    {
        $this->tiktokName = $name;

        return $this;
    }

    /**
     * Set the LinkedIn Insight Tag event name mapping.
     *
     * @param  string|null  $name  LinkedIn event name
     * @return self
     */
    public function linkedin(?string $name): self
    {
        $this->linkedinName = $name;

        return $this;
    }

    // ─── Property Definitions ───────────────────────────────────

    /**
     * Define a string property.
     *
     * @param  string  $name  Property name
     * @return self
     */
    public function string(string $name): self
    {
        $this->properties[$name] = new PropertyDefinition($name, 'string');
        $this->lastName = $name;

        return $this;
    }

    /**
     * Define an integer property.
     *
     * @param  string  $name  Property name
     * @return self
     */
    public function integer(string $name): self
    {
        $this->properties[$name] = new PropertyDefinition($name, 'int');
        $this->lastName = $name;

        return $this;
    }

    /**
     * Define a float property.
     *
     * @param  string  $name  Property name
     * @return self
     */
    public function float(string $name): self
    {
        $this->properties[$name] = new PropertyDefinition($name, 'float');
        $this->lastName = $name;

        return $this;
    }

    /**
     * Define a boolean property.
     *
     * @param  string  $name  Property name
     * @return self
     */
    public function boolean(string $name): self
    {
        $this->properties[$name] = new PropertyDefinition($name, 'bool');
        $this->lastName = $name;

        return $this;
    }

    /**
     * Define an array property.
     *
     * @param  string  $name  Property name
     * @return self
     */
    public function array_(string $name): self
    {
        $this->properties[$name] = new PropertyDefinition($name, 'array');
        $this->lastName = $name;

        return $this;
    }

    /**
     * Define a numeric property (int or float).
     *
     * @param  string  $name  Property name
     * @return self
     */
    public function numeric(string $name): self
    {
        $this->properties[$name] = new PropertyDefinition($name, 'numeric');
        $this->lastName = $name;

        return $this;
    }

    /**
     * Define an enum property with allowed values.
     *
     * @param  string  $name  Property name
     * @param  list<string>  $allowedValues  Allowed values
     * @return self
     */
    public function enum(string $name, array $allowedValues): self
    {
        $this->properties[$name] = new PropertyDefinition($name, 'enum', $allowedValues);
        $this->lastName = $name;

        return $this;
    }

    /**
     * Define a timestamp property (ISO 8601 string or Unix timestamp).
     *
     * @param  string  $name  Property name
     * @return self
     */
    public function timestamp(string $name): self
    {
        $this->properties[$name] = new PropertyDefinition($name, 'timestamp');
        $this->lastName = $name;

        return $this;
    }

    /**
     * Define an email property (string with email format validation).
     *
     * @param  string  $name  Property name
     * @return self
     */
    public function email(string $name): self
    {
        $this->properties[$name] = new PropertyDefinition($name, 'email');
        $this->lastName = $name;

        return $this;
    }

    /**
     * Define a URL property (string with URL format validation).
     *
     * @param  string  $name  Property name
     * @return self
     */
    public function url(string $name): self
    {
        $this->properties[$name] = new PropertyDefinition($name, 'url');
        $this->lastName = $name;

        return $this;
    }

    // ─── Property Modifiers (forwarded to last defined property) ─

    /**
     * Mark the last defined property as required.
     *
     * @return self
     */
    public function required(): self
    {
        $this->requireLastProperty();

        return $this;
    }

    /**
     * Mark the last defined property as optional (default).
     *
     * @return self
     */
    public function optional(): self
    {
        $this->modifyLastProperty(fn (PropertyDefinition $def): PropertyDefinition => $def->optional());

        return $this;
    }

    /**
     * Set the default value for the last defined property.
     *
     * @param  mixed  $value  Default value
     * @return self
     */
    public function default(mixed $value): self
    {
        $this->modifyLastProperty(fn (PropertyDefinition $def): PropertyDefinition => $def->default($value));

        return $this;
    }

    /**
     * Set the description for the last defined property.
     *
     * @param  string  $description  Description text
     * @return self
     */
    public function propDescription(string $description): self
    {
        $this->modifyLastProperty(fn (PropertyDefinition $def): PropertyDefinition => $def->description($description));

        return $this;
    }

    /**
     * Set the maximum string length for the last defined property.
     *
     * @param  int  $length  Maximum length
     * @return self
     */
    public function maxLength(int $length): self
    {
        $this->modifyLastProperty(fn (PropertyDefinition $def): PropertyDefinition => $def->maxLength($length));

        return $this;
    }

    /**
     * Set the minimum numeric value for the last defined property.
     *
     * @param  int|float  $min  Minimum value
     * @return self
     */
    public function min(int|float $min): self
    {
        $this->modifyLastProperty(fn (PropertyDefinition $def): PropertyDefinition => $def->min($min));

        return $this;
    }

    /**
     * Set the maximum numeric value for the last defined property.
     *
     * @param  int|float  $max  Maximum value
     * @return self
     */
    public function max(int|float $max): self
    {
        $this->modifyLastProperty(fn (PropertyDefinition $def): PropertyDefinition => $def->max($max));

        return $this;
    }

    /**
     * Set the maximum array length for the last defined property.
     *
     * @param  int  $length  Maximum array length
     * @return self
     */
    public function maxArrayLength(int $length): self
    {
        $this->modifyLastProperty(fn (PropertyDefinition $def): PropertyDefinition => $def->maxArrayLength($length));

        return $this;
    }

    /**
     * Set a regex pattern for the last defined property.
     *
     * @param  string  $pattern  Regex pattern
     * @return self
     */
    public function pattern(string $pattern): self
    {
        $this->modifyLastProperty(fn (PropertyDefinition $def): PropertyDefinition => $def->pattern($pattern));

        return $this;
    }

    /**
     * Add example values for the last defined property.
     *
     * @param  mixed  ...$values  Example values
     * @return self
     */
    public function example(mixed ...$values): self
    {
        $this->modifyLastProperty(fn (PropertyDefinition $def): PropertyDefinition => $def->example(...$values));

        return $this;
    }

    // ─── Build ───────────────────────────────────────────────────

    /**
     * Build the schema definition DTO.
     *
     * @return EventSchemaDefinition
     */
    public function build(): EventSchemaDefinition
    {
        return new EventSchemaDefinition(
            name: $this->name,
            category: $this->category,
            description: $this->description,
            tags: $this->tags,
            properties: $this->properties,
            ga4: $this->ga4Name,
            meta: $this->metaName,
            posthog: $this->posthogName,
            plausible: $this->plausibleName,
            mixpanel: $this->mixpanelName,
            amplitude: $this->amplitudeName,
            tiktok: $this->tiktokName,
            linkedin: $this->linkedinName,
        );
    }

    /**
     * Build and return the property validation rules array.
     *
     * Useful for Laravel FormRequest validation rules.
     *
     * @return array<string, string> Property name → validation rule string
     */
    public function buildValidationRules(): array
    {
        $rules = [];

        foreach ($this->properties as $name => $def) {
            $rule = $this->typeToRule($def);

            if ($def->isRequired) {
                $rule = 'required|' . $rule;
            } else {
                $rule = 'nullable|' . $rule;
            }

            $rules[$name] = $rule;
        }

        return $rules;
    }

    /**
     * Convert a property definition to a Laravel validation rule string.
     *
     * @param  PropertyDefinition  $def  Property definition
     * @return string  Laravel validation rule
     */
    private function typeToRule(PropertyDefinition $def): string
    {
        return match ($def->type) {
            'string' => 'string|max:' . $def->maxLength,
            'int' => 'integer|min:' . $def->minValue . '|max:' . $def->maxValue,
            'float', 'numeric' => 'numeric|min:' . $def->minValue . '|max:' . $def->maxValue,
            'bool' => 'boolean',
            'array' => 'array|max:' . $def->maxArrayLength,
            'enum' => 'in:' . implode(',', $def->enumValues),
            'timestamp' => 'date',
            'email' => 'email|max:255',
            'url' => 'url|max:2048',
            default => 'string',
        };
    }

    /**
     * Require the last defined property.
     *
     * @return void
     *
     * @throws \LogicException if no property has been defined yet
     */
    private function requireLastProperty(): void
    {
        if ($this->lastName === null) {
            throw new \LogicException('No property has been defined yet. Call string(), integer(), etc. before required().');
        }

        $this->properties[$this->lastName]->required();
    }

    /**
     * Apply a modifier callback to the last defined property.
     *
     * @param  callable(PropertyDefinition): PropertyDefinition  $modifier
     * @return void
     *
     * @throws \LogicException if no property has been defined yet
     */
    private function modifyLastProperty(callable $modifier): void
    {
        if ($this->lastName === null) {
            throw new \LogicException('No property has been defined yet. Call string(), integer(), etc. before modifier methods.');
        }

        $modifier($this->properties[$this->lastName]);
    }
}
