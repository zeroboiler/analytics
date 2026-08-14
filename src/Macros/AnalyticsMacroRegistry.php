<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Macros;

use ZeroBoiler\Analytics\AnalyticsManager;

/**
 * Registry for reusable analytics event macros.
 *
 * Provides a central registry for defining and executing parameterized
 * event templates. Macros reduce boilerplate in event tracking by
 * pre-configuring default parameters, required keys, and tags.
 *
 * Macros can be registered programmatically or via config:
 *
 *   // Via config (zeroboiler.analytics.macros.definitions)
 *   'feature_used' => [
 *       'event' => 'feature_used',
 *       'defaults' => ['source' => 'app'],
 *       'required' => ['feature_name'],
 *       'tags' => ['engagement', 'product'],
 *   ],
 *
 *   // Programmatically
 *   AnalyticsMacroRegistry::define('feature_used', 'feature_used')
 *       ->defaults(['source' => 'app'])
 *       ->required(['feature_name'])
 *       ->tag('engagement', 'product')
 *       ->description('Track feature usage with context')
 *       ->register();
 *
 * @since 118.0.0
 */
final class AnalyticsMacroRegistry
{
    /** @var array<string, AnalyticsMacro> Registered macros keyed by name */
    private static array $macros = [];

    /** @var bool Whether config-based macros have been loaded */
    private static bool $configLoaded = false;

    /**
     * Define a new macro using the fluent builder API.
     *
     * @param  string  $name  Unique macro identifier
     * @param  string  $eventName  The analytics event name to dispatch
     */
    public static function define(string $name, string $eventName): AnalyticsMacroBuilder
    {
        return new AnalyticsMacroBuilder($name, $eventName);
    }

    /**
     * Register a pre-built macro instance.
     */
    public static function register(AnalyticsMacro $macro): void
    {
        self::$macros[$macro->name()] = $macro;
    }

    /**
     * Register macros from configuration.
     *
     * Loads macro definitions from zeroboiler.analytics.macros.definitions.
     * Each definition is an array with keys: event, defaults, required, tags, description.
     *
     * @param  array<string, mixed>  $config  The macros config section
     */
    public static function loadFromConfig(array $config): void
    {
        if (self::$configLoaded) {
            return;
        }

        $definitions = $config['definitions'] ?? [];

        foreach ($definitions as $name => $definition) {
            if (isset(self::$macros[$name])) {
                continue; // Programmatic registration takes precedence
            }

            /** @var array{event?: string, defaults?: array<string, mixed>, required?: list<string>, tags?: list<string>, description?: string} $definition */
            self::$macros[$name] = new AnalyticsMacro(
                name: $name,
                eventName: (string) ($definition['event'] ?? $name),
                defaults: (array) ($definition['defaults'] ?? []),
                requiredKeys: (array) ($definition['required'] ?? []),
                tags: (array) ($definition['tags'] ?? []),
                description: $definition['description'] ?? null,
            );
        }

        self::$configLoaded = true;
    }

    /**
     * Execute a registered macro with the given parameters.
     *
     * Merges caller params with macro defaults, validates required keys,
     * and dispatches the event through the AnalyticsManager.
     *
     * @param  AnalyticsManager  $manager  The analytics manager instance
     * @param  string  $name  Registered macro name
     * @param  array<string, mixed>  $params  Caller-provided parameters
     * @throws \InvalidArgumentException If the macro is not registered or required keys are missing
     */
    public static function execute(AnalyticsManager $manager, string $name, array $params = []): void
    {
        $macro = self::get($name);

        if ($macro === null) {
            throw new \InvalidArgumentException("Analytics macro '{$name}' is not registered. " .
                'Available: ' . implode(', ', self::names()));
        }

        $result = $macro->build($params);
        $manager->trackEvent($macro->eventName(), $result['params']);
    }

    /**
     * Get a registered macro by name.
     */
    public static function get(string $name): ?AnalyticsMacro
    {
        return self::$macros[$name] ?? null;
    }

    /**
     * Check if a macro is registered.
     */
    public static function has(string $name): bool
    {
        return isset(self::$macros[$name]);
    }

    /**
     * Get all registered macro names.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::$macros);
    }

    /**
     * Get all registered macros.
     *
     * @return array<string, AnalyticsMacro>
     */
    public static function all(): array
    {
        return self::$macros;
    }

    /**
     * Get macros grouped by tag.
     *
     * @return array<string, list<string>>
     */
    public static function byTag(): array
    {
        $grouped = [];

        foreach (self::$macros as $macro) {
            foreach ($macro->tags() as $tag) {
                $grouped[$tag][] = $macro->name();
            }
        }

        return $grouped;
    }

    /**
     * Get the total number of registered macros.
     */
    public static function count(): int
    {
        return count(self::$macros);
    }

    /**
     * Remove a registered macro.
     */
    public static function forget(string $name): void
    {
        unset(self::$macros[$name]);
    }

    /**
     * Remove all registered macros and reset config loaded state.
     * Primarily used in testing.
     */
    public static function flush(): void
    {
        self::$macros = [];
        self::$configLoaded = false;
    }

    /**
     * Validate all registered macros for integrity.
     *
     * Checks that all macros have non-empty event names, that required keys
     * don't conflict with defaults, and that names follow naming conventions.
     *
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public static function validate(): array
    {
        $errors = [];
        $warnings = [];

        foreach (self::$macros as $name => $macro) {
            // Check event name is not empty
            if ($macro->eventName() === '') {
                $errors[] = "Macro '{$name}' has an empty event name.";
            }

            // Check name follows snake_case convention
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                $warnings[] = "Macro '{$name}' does not follow snake_case naming convention.";
            }

            // Check required keys don't have defaults (redundant)
            foreach ($macro->requiredKeys() as $key) {
                if (array_key_exists($key, $macro->defaults()) && $macro->defaults()[$key] !== null) {
                    $warnings[] = "Macro '{$name}': required key '{$key}' has a default value — required is redundant.";
                }
            }

            // Check for description
            if ($macro->description() === null) {
                $warnings[] = "Macro '{$name}' has no description.";
            }

            // Check for tags
            if ($macro->tags() === []) {
                $warnings[] = "Macro '{$name}' has no tags for discoverability.";
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Get a summary of all registered macros.
     *
     * @return array{count: int, by_tag: array<string, int>, names: list<string>}
     */
    public static function summary(): array
    {
        $byTag = [];

        foreach (self::$macros as $macro) {
            foreach ($macro->tags() as $tag) {
                $byTag[$tag] = ($byTag[$tag] ?? 0) + 1;
            }
        }

        return [
            'count' => self::count(),
            'by_tag' => $byTag,
            'names' => self::names(),
        ];
    }
}
