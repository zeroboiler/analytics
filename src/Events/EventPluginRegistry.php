<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event Plugin Registry — third-party package event discovery system.
 *
 * Allows other Laravel packages to register their analytics events with
 * the ZeroBoiler analytics catalog at runtime. Registered events are
 * merged into the main EventCatalog and validated against provider schemas.
 *
 * Use cases:
 * - SaaS modules (billing, CRM, helpdesk) registering domain-specific events
 * - Plugin marketplace integrations registering conversion events
 * - Multi-tenant apps registering tenant-specific event categories
 *
 * Events are registered via `registerPlugin()` during ServiceProvider boot,
 * or via config under `zeroboiler.analytics.event_plugins`.
 *
 * @phpstan-type PluginEvent array{name: string, class: class-string<AnalyticsEvent>, ga4: string, meta: string|null, category: string}
 * @phpstan-type PluginManifest array{package: string, version: string, events: list<PluginEvent>, priority: int}
 *
 * @since 7.8.0
 */
final class EventPluginRegistry
{
    /** @var array<string, PluginManifest> Registered plugin manifests keyed by package name */
    private array $plugins = [];

    private bool $debug;

    /** @var list<string> Events added by plugins (for catalog merge) */
    private array $pluginEvents = [];

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config){
        $pluginConfig = $config->get('zeroboiler.analytics.event_plugins', []);
        /** @var array{enabled?: bool, debug?: bool, plugins?: array<string, PluginManifest>} $pluginConfig */
        $this->debug = (bool) ($pluginConfig['debug'] ?? false);

        if ((bool) ($pluginConfig['enabled'] ?? true)) {
            $this->loadFromConfig($pluginConfig['plugins'] ?? []);
        }
    }

    /**
     * Register a plugin manifest with its events.
     *
     * @param  PluginManifest  $manifest
     */
    public function registerPlugin(array $manifest): void
    {
        $package = $manifest['package'] ?? 'unknown';
        $version = $manifest['version'] ?? '0.0.0';
        $events = $manifest['events'] ?? [];
        $priority = (int) ($manifest['priority'] ?? 0);

        if ($events === []) {
            return;
        }

        $validEvents = [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $name = $event['name'] ?? '';
            $class = $event['class'] ?? '';

            if ($name === '' || $class === '') {
                continue;
            }

            $validEvents[] = [
                'name' => $name,
                'class' => $class,
                'ga4' => $event['ga4'] ?? $name,
                'meta' => $event['meta'] ?? null,
                'category' => $event['category'] ?? 'custom',
            ];
        }

        if ($validEvents === []) {
            return;
        }

        $this->plugins[$package] = [
            'package' => $package,
            'version' => $version,
            'events' => $validEvents,
            'priority' => $priority,
            'registered_at' => time(),
        ];

        foreach ($validEvents as $event) {
            $this->pluginEvents[$event['name']] = $event;
        }

        if ($this->debug) {
            Log::debug("[ZeroBoiler] Event plugin registered: {$package}@{$version} ({$this->countPluginEvents($package)} events)");
        }
    }

    /**
     * Get all plugin-registered events merged into catalog format.
     *
     * @return array<string, array{name: string, class: class-string<AnalyticsEvent>, ga4: string, meta: string|null, category: string}>
     */
    public function catalogEvents(): array
    {
        return $this->pluginEvents;
    }

    /**
     * Get all registered plugin manifests.
     *
     * @return array<string, PluginManifest>
     */
    public function plugins(): array
    {
        return $this->plugins;
    }

    /**
     * Get events grouped by source plugin.
     *
     * @return array<string, list<PluginEvent>>
     */
    public function eventsByPlugin(): array
    {
        $grouped = [];
        foreach ($this->plugins as $package => $manifest) {
            $grouped[$package] = $manifest['events'];
        }

        return $grouped;
    }

    /**
     * Check if a plugin is registered.
     */
    public function hasPlugin(string $package): bool
    {
        return isset($this->plugins[$package]);
    }

    /**
     * Check if an event name exists in any registered plugin.
     */
    public function hasEvent(string $eventName): bool
    {
        return isset($this->pluginEvents[$eventName]);
    }

    /**
     * Get event details by name.
     *
     * @return PluginEvent|null
     */
    public function getEvent(string $eventName): ?array
    {
        return $this->pluginEvents[$eventName] ?? null;
    }

    /**
     * Count total plugin-registered events.
     */
    public function totalEventCount(): int
    {
        return count($this->pluginEvents);
    }

    /**
     * Count events for a specific plugin.
     */
    public function countPluginEvents(string $package): int
    {
        return count($this->plugins[$package]['events'] ?? []);
    }

    /**
     * Count total registered plugins.
     */
    public function pluginCount(): int
    {
        return count($this->plugins);
    }

    /**
     * Get events by category across all plugins.
     *
     * @return array<string, list<PluginEvent>>
     */
    public function eventsByCategory(): array
    {
        $grouped = [];
        foreach ($this->pluginEvents as $event) {
            $category = $event['category'] ?? 'custom';
            $grouped[$category][] = $event;
        }

        return $grouped;
    }

    /**
     * Validate that all plugin event classes exist and implement AnalyticsEvent.
     *
     * @return array{valid: int, invalid: int, errors: list<string>}
     */
    public function validate(): array
    {
        $valid = 0;
        $invalid = 0;
        $errors = [];

        foreach ($this->pluginEvents as $eventName => $event) {
            $class = $event['class'];

            if (! class_exists($class)) {
                $invalid++;
                $errors[] = "Event '{$eventName}': class {$class} does not exist";
                continue;
            }

            if (! is_a($class, AnalyticsEvent::class, true)) {
                $invalid++;
                $errors[] = "Event '{$eventName}': class {$class} does not extend AnalyticsEvent";
                continue;
            }

            $valid++;
        }

        return [
            'valid' => $valid,
            'invalid' => $invalid,
            'errors' => $errors,
        ];
    }

    /**
     * Get a summary of all registered plugins.
     *
     * @return array{total_plugins: int, total_events: int, categories: array<string, int>, plugins: list<array{package: string, version: string, events: int, priority: int}>}
     */
    public function summary(): array
    {
        $categories = [];
        foreach ($this->pluginEvents as $event) {
            $cat = $event['category'] ?? 'custom';
            $categories[$cat] = ($categories[$cat] ?? 0) + 1;
        }

        $pluginList = [];
        foreach ($this->plugins as $manifest) {
            $pluginList[] = [
                'package' => $manifest['package'],
                'version' => $manifest['version'],
                'events' => count($manifest['events']),
                'priority' => $manifest['priority'],
            ];
        }

        usort($pluginList, fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        return [
            'total_plugins' => $this->pluginCount(),
            'total_events' => $this->totalEventCount(),
            'categories' => $categories,
            'plugins' => $pluginList,
        ];
    }

    /**
     * Remove a plugin and all its events.
     */
    public function unregisterPlugin(string $package): void
    {
        if (! isset($this->plugins[$package])) {
            return;
        }

        foreach ($this->plugins[$package]['events'] as $event) {
            $name = $event['name'] ?? '';
            if ($name !== '' && isset($this->pluginEvents[$name])) {
                unset($this->pluginEvents[$name]);
            }
        }

        unset($this->plugins[$package]);

        if ($this->debug) {
            Log::debug("[ZeroBoiler] Event plugin unregistered: {$package}");
        }
    }

    /**
     * Clear all registered plugins.
     */
    public function clear(): void
    {
        $this->plugins = [];
        $this->pluginEvents = [];
    }

    /**
     * Load plugins from config.
     *
     * @param  array<string, PluginManifest>  $configPlugins
     */
    private function loadFromConfig(array $configPlugins): void
    {
        foreach ($configPlugins as $package => $manifest) {
            $this->registerPlugin($manifest);
        }
    }
}
