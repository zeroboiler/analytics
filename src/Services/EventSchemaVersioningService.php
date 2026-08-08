<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;

/**
 * Event schema versioning service.
 *
 * Injects schema version metadata into dispatched events for backward
 * compatibility tracking. Enables downstream consumers (data warehouses,
 * ETL pipelines, analytics dashboards) to handle format changes gracefully.
 *
 * Schema versions are per-event-name and managed via the EventSchemaRegistry.
 * When a schema changes (new params, renamed fields, type changes), the
 * version should be incremented in the registry.
 *
 * @see \ZeroBoiler\Analytics\Schema\EventSchemaRegistry
 * @see \ZeroBoiler\Analytics\Events\EventCatalog
 */
final class EventSchemaVersioningService
{
    private readonly bool $enabled;

    private readonly string $paramName;

    private readonly string $defaultVersion;

    private readonly bool $includeCatalogVersion;

    private readonly string $catalogVersion;

    /** @var array<string, string> Event name → version mapping (in-memory cache) */
    private array $versionMap = [];

    /**
     * @param  ConfigRepository  $config
     * @param  EventSchemaRegistry|null  $schemaRegistry
     */
    public function __construct(
        ConfigRepository $config,
        private readonly ?EventSchemaRegistry $schemaRegistry = null,
    ) {
        $svConfig = $config->get('zeroboiler.analytics.schema_versioning', []);
        /** @var array{enabled?: bool, param_name?: string, default_version?: string, include_catalog_version?: bool, catalog_version?: string} $svConfig */

        $this->enabled = (bool) ($svConfig['enabled'] ?? true);
        $this->paramName = (string) ($svConfig['param_name'] ?? '_schema_version');
        $this->defaultVersion = (string) ($svConfig['default_version'] ?? '1.0');
        $this->includeCatalogVersion = (bool) ($svConfig['include_catalog_version'] ?? true);
        $this->catalogVersion = (string) ($svConfig['catalog_version'] ?? '2.96.0');
    }

    /**
     * Check if schema versioning is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Inject schema version metadata into an event's params.
     *
     * Adds the `_schema_version` param with the event-specific version.
     * If the event is in the EventCatalog, the catalog version is used.
     * Otherwise, the default version is applied.
     *
     * Does not mutate the original event — returns a new event with versioned params.
     *
     * @param  AnalyticsEvent  $event  The event to version
     * @return AnalyticsEvent  New event with schema version params injected
     */
    public function versionEvent(AnalyticsEvent $event): AnalyticsEvent
    {
        if (! $this->enabled) {
            return $event;
        }

        $params = $event->params;

        // Get version for this event name
        $version = $this->getEventVersion($event->name);

        // Inject schema version param (only if not already set)
        if (! array_key_exists($this->paramName, $params)) {
            $params[$this->paramName] = $version;
        }

        // Inject catalog version (for cross-reference)
        if ($this->includeCatalogVersion && ! array_key_exists('_catalog_version', $params)) {
            $params['_catalog_version'] = $this->catalogVersion;
        }

        return new AnalyticsEvent(
            name: $event->name,
            params: $params,
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
        );
    }

    /**
     * Inject schema version metadata into a raw params array.
     *
     * Useful for versioning events before they are wrapped in AnalyticsEvent.
     *
     * @param  string  $eventName  The event name
     * @param  array<string, mixed>  $params  Event params
     * @return array<string, mixed>  Params with version metadata injected
     */
    public function versionParams(string $eventName, array $params): array
    {
        if (! $this->enabled) {
            return $params;
        }

        if (! array_key_exists($this->paramName, $params)) {
            $params[$this->paramName] = $this->getEventVersion($eventName);
        }

        if ($this->includeCatalogVersion && ! array_key_exists('_catalog_version', $params)) {
            $params['_catalog_version'] = $this->catalogVersion;
        }

        return $params;
    }

    /**
     * Get the schema version for a specific event name.
     *
     * Priority:
     * 1. EventSchemaRegistry (if available and event is registered)
     * 2. Cached version map
     * 3. Default version
     *
     * @param  string  $eventName  The event name
     * @return string  Schema version string (e.g. '1.0', '2.0')
     */
    public function getEventVersion(string $eventName): string
    {
        // Check in-memory cache
        if (isset($this->versionMap[$eventName])) {
            return $this->versionMap[$eventName];
        }

        // Check EventSchemaRegistry
        if ($this->schemaRegistry !== null) {
            try {
                $schema = $this->schemaRegistry->get($eventName);
                if ($schema !== null) {
                    $version = $schema->version();
                    $this->versionMap[$eventName] = $version;

                    return $version;
                }
            } catch (\Throwable) {
                // Fall through to default
            }
        }

        // Check if event exists in catalog
        if (EventCatalog::has($eventName)) {
            // Events in the catalog use the catalog version
            $this->versionMap[$eventName] = $this->catalogVersion;

            return $this->catalogVersion;
        }

        // Default version for unregistered events
        $this->versionMap[$eventName] = $this->defaultVersion;

        return $this->defaultVersion;
    }

    /**
     * Get the catalog version string.
     */
    public function getCatalogVersion(): string
    {
        return $this->catalogVersion;
    }

    /**
     * Get the schema versioning param name.
     */
    public function getParamName(): string
    {
        return $this->paramName;
    }

    /**
     * Get a summary of schema versioning configuration.
     *
     * @return array{enabled: bool, param_name: string, default_version: string, catalog_version: string, include_catalog_version: bool, tracked_events: int}
     */
    public function getSummary(): array
    {
        return [
            'enabled' => $this->enabled,
            'param_name' => $this->paramName,
            'default_version' => $this->defaultVersion,
            'catalog_version' => $this->catalogVersion,
            'include_catalog_version' => $this->includeCatalogVersion,
            'tracked_events' => EventCatalog::count(),
        ];
    }

    /**
     * Extract the schema version from an event's params.
     *
     * Useful for downstream consumers to determine how to process an event.
     *
     * @param  array<string, mixed>  $params  Event params
     * @return string|null  Schema version or null if not versioned
     */
    public function extractVersion(array $params): ?string
    {
        return $params[$this->paramName] ?? null;
    }

    /**
     * Extract the catalog version from an event's params.
     *
     * @param  array<string, mixed>  $params  Event params
     * @return string|null  Catalog version or null if not present
     */
    public function extractCatalogVersion(array $params): ?string
    {
        return $params['_catalog_version'] ?? null;
    }
}
