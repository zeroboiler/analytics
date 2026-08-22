<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Enrichment;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
/**
 * Registry for analytics event enrichment plugins.
 *
 * Manages registration, ordering, enable/disable state, and lookup
 * of EventEnrichmentPlugin instances. Plugins are sorted by priority
 * (higher runs first) and can be enabled/disabled via config.
 *
 * Configuration is read from `zeroboiler.analytics.enrichment_plugins`.
 *
 * Config structure:
 * ```php
 * 'enrichment_plugins' => [
 *     'enabled' => true,
 *     'disabled' => ['some_plugin_name'],
 *     'plugins' => [
 *         \App\Analytics\GeoEnrichmentPlugin::class,
 *         \App\Analytics\RevenueTagPlugin::class,
 *     ],
 * ],
 * ```
 *
 * Plugins can also be registered programmatically via `register()`.
 *
 * @see EventEnrichmentPlugin
 * @see EventEnrichmentOrchestrator
 *
 * @since 57.0.0
 */
final class EventEnrichmentRegistry
{
    /** @var array<string, EventEnrichmentPlugin> Registered plugins keyed by name */
    private array $plugins = [];

    /** @var list<string> Names of disabled plugins */
    private array $disabled = [];

    /** @var bool Whether the plugin system is enabled */
    private bool $enabled;

    private bool $debug;

    /** @var list<string> Plugin class names loaded from config (for lazy resolution) */
    private array $pendingClasses = [];

    /**
     * Create a new enrichment plugin registry.
     *
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config){
        $pluginConfig = $config->get('zeroboiler.analytics.enrichment_plugins', []);
        /** @var array{enabled?: bool, disabled?: list<string>, plugins?: list<class-string<EventEnrichmentPlugin>>, debug?: bool} $pluginConfig */

        $this->enabled = (bool) ($pluginConfig['enabled'] ?? true);
        $this->disabled = (array) ($pluginConfig['disabled'] ?? []);
        $this->debug = (bool) ($pluginConfig['debug'] ?? false);

        $classes = $pluginConfig['plugins'] ?? [];
        foreach ($classes as $class) {
            if (is_string($class) && class_exists($class)) {
                $this->pendingClasses[] = $class;
            } elseif ($this->debug) {
                Log::debug("[ZeroBoiler] Enrichment plugin class not found: {$class}");
            }
        }

        $this->resolvePending();
    }

    /**
     * Register a plugin instance programmatically.
     *
     * If a plugin with the same name is already registered, it is replaced.
     *
     * @param  EventEnrichmentPlugin  $plugin
     */
    public function register(EventEnrichmentPlugin $plugin): void
    {
        $name = $plugin->name();

        if (isset($this->plugins[$name]) && $this->debug) {
            Log::debug("[ZeroBoiler] Enrichment plugin '{$name}' overwritten by new registration.");
        }

        $this->plugins[$name] = $plugin;
    }

    /**
     * Get all registered plugins sorted by priority (highest first).
     *
     * @return list<EventEnrichmentPlugin>
     */
    public function all(): array
    {
        $active = array_filter(
            $this->plugins,
            fn (EventEnrichmentPlugin $plugin): bool => ! in_array($plugin->name(), $this->disabled, true),
        );

        $sorted = $active;
        usort($sorted, fn (EventEnrichmentPlugin $a, EventEnrichmentPlugin $b): int => $b->priority() <=> $a->priority());

        return array_values($sorted);
    }

    /**
     * Get a plugin by name.
     *
     * @return EventEnrichmentPlugin|null The plugin, or null if not registered
     */
    public function get(string $name): ?EventEnrichmentPlugin
    {
        return $this->plugins[$name] ?? null;
    }

    /**
     * Check if a plugin is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->plugins[$name]);
    }

    /**
     * Check if a plugin is enabled (registered and not in disabled list).
     */
    public function isEnabled(string $name): bool
    {
        return $this->has($name) && ! in_array($name, $this->disabled, true);
    }

    /**
     * Disable a plugin by name.
     *
     * Does not unregister the plugin — just prevents it from running
     * in the enrichment pipeline.
     */
    public function disable(string $name): void
    {
        if (! in_array($name, $this->disabled, true)) {
            $this->disabled[] = $name;
        }
    }

    /**
     * Enable a previously disabled plugin.
     */
    public function enable(string $name): void
    {
        $this->disabled = array_values(array_filter(
            $this->disabled,
            fn (string $disabled): bool => $disabled !== $name,
        ));
    }

    /**
     * Remove a plugin from the registry entirely.
     */
    public function remove(string $name): void
    {
        unset($this->plugins[$name]);
    }

    /**
     * Get the count of registered plugins.
     */
    public function count(): int
    {
        return count($this->plugins);
    }

    /**
     * Get the count of active (enabled) plugins.
     */
    public function activeCount(): int
    {
        return count(array_filter(
            $this->plugins,
            fn (EventEnrichmentPlugin $plugin): bool => $this->isEnabled($plugin->name()),
        ));
    }

    /**
     * Check if the enrichment plugin system is enabled.
     */
    public function isPluginSystemEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get names of all registered plugins.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->plugins);
    }

    /**
     * Get names of disabled plugins.
     *
     * @return list<string>
     */
    public function disabledNames(): array
    {
        return $this->disabled;
    }

    /**
     * Get registry summary for diagnostics.
     *
     * @return array{enabled: bool, total: int, active: int, disabled: list<string>, plugins: list<array{name: string, priority: int, enabled: bool}>}
     */
    public function summary(): array
    {
        $pluginList = [];
        foreach ($this->plugins as $plugin) {
            $pluginList[] = [
                'name' => $plugin->name(),
                'priority' => $plugin->priority(),
                'enabled' => $this->isEnabled($plugin->name()),
            ];
        }

        return [
            'enabled' => $this->enabled,
            'total' => $this->count(),
            'active' => $this->activeCount(),
            'disabled' => $this->disabled,
            'plugins' => $pluginList,
        ];
    }

    /**
     * Resolve pending plugin class names from config into instances.
     */
    private function resolvePending(): void
    {
        foreach ($this->pendingClasses as $class) {
            try {
                $instance = new $class;

                if (! $instance instanceof EventEnrichmentPlugin) {
                    if ($this->debug) {
                        Log::debug("[ZeroBoiler] Enrichment plugin {$class} does not implement EventEnrichmentPlugin.");
                    }

                    continue;
                }

                $this->plugins[$instance->name()] = $instance;

                if ($this->debug) {
                    Log::debug("[ZeroBoiler] Enrichment plugin '{$instance->name()}' registered (priority: {$instance->priority()}).");
                }
            } catch (\Throwable $e) {
                if ($this->debug) {
                    Log::debug("[ZeroBoiler] Failed to instantiate enrichment plugin {$class}: {$e->getMessage()}");
                }
            }
        }

        // Clear pending after resolution
        $this->pendingClasses = [];
    }
}
