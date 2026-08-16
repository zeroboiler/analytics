<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Macros;

use ZeroBoiler\Analytics\AnalyticsManager;

/**
 * Fluent builder for AnalyticsMacro instances.
 *
 * Provides a declarative API for defining analytics event macros
 * with method chaining for defaults, required keys, tags, and description.
 *
 * @since 118.0.0
 *
 * @example
 *   AnalyticsMacroRegistry::define('feature_used', 'feature_used')
 *       ->defaults(['source' => 'app', 'environment' => app()->environment()])
 *       ->required(['feature_name'])
 *       ->tag('engagement', 'product', 'adoption')
 *       ->description('Track feature usage with automatic context')
 *       ->register();
 */
final class AnalyticsMacroBuilder
{
    /** @var array<string, mixed> Default parameter values */
    private array $defaults = [];

    /** @var list<string> Required parameter keys */
    private array $requiredKeys = [];

    /** @var list<string> Organizational tags */
    private array $tags = [];

    /** @var string|null Macro description */
    private ?string $description = null;

    /**
     * @param  string  $name  Unique macro identifier
     * @param  string  $eventName  The analytics event name to dispatch
     */
    public function __construct(
        private readonly string $name,
        private readonly string $eventName,
    ): void {}

    /**
     * Set default parameter values merged into every macro execution.
     *
     * @param  array<string, mixed>  $defaults  Key-value pairs of default parameters
     * @return static
     */
    public function defaults(array $defaults): static
    {
        $this->defaults = $defaults;

        return $this;
    }

    /**
     * Set a single default parameter value.
     *
     * @return static
     */
    public function default(string $key, mixed $value): static
    {
        $this->defaults[$key] = $value;

        return $this;
    }

    /**
     * Set required parameter keys that must be provided by the caller.
     *
     * @param  list<string>  $keys  Parameter key names
     * @return static
     */
    public function required(array $keys): static
    {
        $this->requiredKeys = $keys;

        return $this;
    }

    /**
     * Add a single required parameter key.
     *
     * @return static
     */
    public function requireKey(string $key): static
    {
        $this->requiredKeys[] = $key;

        return $this;
    }

    /**
     * Set organizational tags for macro discovery and grouping.
     *
     * @param  string  ...$tags  One or more tag strings
     * @return static
     */
    public function tag(string ...$tags): static
    {
        foreach ($tags as $tag) {
            if (! in_array($tag, $this->tags, true)) {
                $this->tags[] = $tag;
            }
        }

        return $this;
    }

    /**
     * Set the macro description.
     *
     * @return static
     */
    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Build the macro instance without registering it.
     */
    public function build(): AnalyticsMacro
    {
        return new AnalyticsMacro(
            name: $this->name,
            eventName: $this->eventName,
            defaults: $this->defaults,
            requiredKeys: $this->requiredKeys,
            tags: $this->tags,
            description: $this->description,
        );
    }

    /**
     * Build and register the macro in the global registry.
     */
    public function register(): AnalyticsMacro
    {
        $macro = $this->build();
        AnalyticsMacroRegistry::register($macro);

        return $macro;
    }

    /**
     * Build and execute the macro immediately.
     *
     * @param  AnalyticsManager  $manager
     * @param  array<string, mixed>  $params
     */
    public function dispatch(AnalyticsManager $manager, array $params = []): void
    {
        $macro = $this->build();
        $result = $macro->build($params);
        $manager->track($macro->eventName(), $result['params']);
    }
}
