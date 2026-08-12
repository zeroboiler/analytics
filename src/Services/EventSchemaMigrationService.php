<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event schema migration service for safe event evolution.
 *
 * Manages versioned event schemas and provides migration paths for
 * transitioning events between schema versions without breaking
 * existing analytics pipelines.
 *
 * Tracks schema versions in cache, validates compatibility between
 * versions, and provides migration functions for parameter renaming,
 * type changes, and field deprecation.
 *
 * Inspired by database migration patterns adapted for event schemas.
 *
 * @since 28.0.0
 */
final class EventSchemaMigrationService
{
    private const CACHE_PREFIX = 'zb_schema_migration_';
    private const CACHE_TTL = 86400; // 24 hours

    /** @var array<string, array<int, callable>> Registered migration functions keyed by event name */
    private array $migrations = [];

    /** @var array<string, array<string, mixed>> Current schema definitions */
    private array $schemas = [];

    public function __construct(
        private readonly CacheRepository $cache,
    ): void {
        $this->registerBuiltinMigrations();
    }

    /**
     * Register a migration for a specific event.
     *
     * Migrations are callables that transform event parameters from
     * one schema version to another.
     *
     * @param  string  $eventName  Event name (e.g. 'purchase', 'sign_up')
     * @param  int  $fromVersion  Source schema version
     * @param  int  $toVersion  Target schema version
     * @param  callable(array<string, mixed>): array<string, mixed>  $migration  Transformation function
     */
    public function registerMigration(string $eventName, int $fromVersion, int $toVersion, callable $migration): void
    {
        if (! isset($this->migrations[$eventName])) {
            $this->migrations[$eventName] = [];
        }

        $this->migrations[$eventName][$fromVersion] = $migration;
    }

    /**
     * Register a schema definition for an event.
     *
     * Schema definitions describe the expected parameter structure
     * for a given event at a specific version.
     *
     * @param  string  $eventName
     * @param  array{version: int, params: array<string, array{type: string, required: bool, deprecated?: bool, renamed_from?: string, default?: mixed}>}  $definition
     */
    public function registerSchema(string $eventName, array $definition): void
    {
        $this->schemas[$eventName] = $definition;
    }

    /**
     * Migrate event parameters to the latest schema version.
     *
     * Applies all registered migrations sequentially from the
     * event's current version to the latest version.
     *
     * @param  AnalyticsEvent  $event
     * @return AnalyticsEvent  New event with migrated parameters
     */
    public function migrateEvent(AnalyticsEvent $event): AnalyticsEvent
    {
        $eventName = $event->name;
        $params = $event->params;

        $currentVersion = $this->getEventSchemaVersion($eventName);
        $latestVersion = $this->getLatestSchemaVersion($eventName);

        if ($currentVersion >= $latestVersion) {
            return $event;
        }

        // Apply migrations sequentially
        for ($v = $currentVersion; $v < $latestVersion; $v++) {
            $params = $this->applyMigration($eventName, $v, $v + 1, $params);
        }

        // Update the event's schema version marker
        $params['_schema_version'] = $latestVersion;

        return new AnalyticsEvent(
            name: $event->name,
            params: $params,
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $event->source,
        );
    }

    /**
     * Validate event parameters against the current schema definition.
     *
     * Checks for required parameters, deprecated fields, and type mismatches.
     *
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public function validateEvent(AnalyticsEvent $event): array
    {
        $eventName = $event->name;
        $schema = $this->schemas[$eventName] ?? null;

        if ($schema === null) {
            return ['valid' => true, 'errors' => [], 'warnings' => ['No schema definition found for event']];
        }

        $errors = [];
        $warnings = [];
        $params = $event->params;

        foreach ($schema['params'] as $paramName => $paramDef) {
            $isRequired = (bool) ($paramDef['required'] ?? false);
            $isDeprecated = (bool) ($paramDef['deprecated'] ?? false);

            // Check required
            if ($isRequired && ! array_key_exists($paramName, $params)) {
                $renamedFrom = $paramDef['renamed_from'] ?? null;
                if ($renamedFrom !== null && array_key_exists($renamedFrom, $params)) {
                    $warnings[] = "Parameter '{$paramName}' is required but found deprecated '{$renamedFrom}'";
                } else {
                    $errors[] = "Missing required parameter: '{$paramName}'";
                }
            }

            // Check deprecated
            if ($isDeprecated && array_key_exists($paramName, $params)) {
                $renamedTo = $paramDef['renamed_to'] ?? null;
                if ($renamedTo !== null) {
                    $warnings[] = "Parameter '{$paramName}' is deprecated, use '{$renamedTo}' instead";
                } else {
                    $warnings[] = "Parameter '{$paramName}' is deprecated";
                }
            }

            // Type check
            if (array_key_exists($paramName, $params)) {
                $expectedType = $paramDef['type'] ?? 'string';
                $actualType = gettype($params[$paramName]);

                if (! $this->isTypeCompatible($actualType, $expectedType)) {
                    $errors[] = "Parameter '{$paramName}' expected type '{$expectedType}', got '{$actualType}'";
                }
            }
        }

        // Check for unknown parameters
        foreach (array_keys($params) as $key) {
            if (! isset($schema['params'][$key]) && ! str_starts_with($key, '_')) {
                $warnings[] = "Unknown parameter: '{$key}'";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Get the schema version for an event.
     *
     * Reads from cache or defaults to the latest registered version.
     */
    public function getEventSchemaVersion(string $eventName): int
    {
        return (int) $this->cache->get(
            self::CACHE_PREFIX.$eventName.'_version',
            fn (): int => $this->getLatestSchemaVersion($eventName),
        );
    }

    /**
     * Set the schema version for an event (persists to cache).
     */
    public function setEventSchemaVersion(string $eventName, int $version): void
    {
        $this->cache->put(
            self::CACHE_PREFIX.$eventName.'_version',
            $version,
            self::CACHE_TTL,
        );
    }

    /**
     * Get the latest registered schema version for an event.
     */
    public function getLatestSchemaVersion(string $eventName): int
    {
        return $this->schemas[$eventName]['version'] ?? 1;
    }

    /**
     * Get all registered schema definitions.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllSchemas(): array
    {
        return $this->schemas;
    }

    /**
     * Get all registered migrations.
     *
     * @return array<string, array<int, callable>>
     */
    public function getAllMigrations(): array
    {
        return $this->migrations;
    }

    /**
     * Generate a schema diff report between two events.
     *
     * @return array{added: list<string>, removed: list<string>, changed: list<string>, compatible: bool}
     */
    public function diffSchemas(string $eventA, string $eventB): array
    {
        $schemaA = $this->schemas[$eventA] ?? [];
        $schemaB = $this->schemas[$eventB] ?? [];

        $paramsA = $schemaA['params'] ?? [];
        $paramsB = $schemaB['params'] ?? [];

        $added = array_values(array_diff(array_keys($paramsB), array_keys($paramsA)));
        $removed = array_values(array_diff(array_keys($paramsA), array_keys($paramsB)));
        $changed = [];

        foreach (array_intersect(array_keys($paramsA), array_keys($paramsB)) as $key) {
            if ($paramsA[$key]['type'] !== $paramsB[$key]['type']) {
                $changed[] = $key;
            }
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'changed' => $changed,
            'compatible' => empty($removed) && empty($changed),
        ];
    }

    /**
     * Apply a single migration step.
     *
     * @param  string  $eventName
     * @param  int  $fromVersion
     * @param  int  $toVersion
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function applyMigration(string $eventName, int $fromVersion, int $toVersion, array $params): array
    {
        $migration = $this->migrations[$eventName][$fromVersion] ?? null;

        if ($migration === null) {
            return $params;
        }

        $result = $migration($params);

        if (! is_array($result)) {
            return $params;
        }

        return $result;
    }

    /**
     * Check if an actual PHP type is compatible with a schema type string.
     */
    private function isTypeCompatible(string $actualType, string $expectedType): bool
    {
        return match ($expectedType) {
            'string' => in_array($actualType, ['string', 'NULL'], true),
            'int', 'integer' => in_array($actualType, ['integer', 'double'], true),
            'float', 'double' => in_array($actualType, ['double', 'integer'], true),
            'bool', 'boolean' => $actualType === 'boolean',
            'array' => $actualType === 'array',
            'mixed' => true,
            default => true, // Unknown types are permissive
        };
    }

    /**
     * Register built-in schema migrations for common event evolutions.
     */
    private function registerBuiltinMigrations(): void
    {
        // Purchase event: v1 → v2 migration (currency field added)
        $this->registerMigration('purchase', 1, 2, function (array $params): array {
            if (! isset($params['currency']) && isset($params['value'])) {
                $params['currency'] = 'USD';
            }

            return $params;
        });

        // Sign up event: v1 → v2 migration (method field normalized)
        $this->registerMigration('sign_up', 1, 2, function (array $params): array {
            if (isset($params['auth_method'])) {
                $params['method'] = $params['auth_method'];
                unset($params['auth_method']);
            }

            return $params;
        });

        // Register built-in schemas
        $this->registerSchema('purchase', [
            'version' => 2,
            'params' => [
                'transaction_id' => ['type' => 'string', 'required' => true],
                'value' => ['type' => 'float', 'required' => true],
                'currency' => ['type' => 'string', 'required' => false],
                'items' => ['type' => 'array', 'required' => false],
                'tax' => ['type' => 'float', 'required' => false],
                'shipping' => ['type' => 'float', 'required' => false],
                'coupon' => ['type' => 'string', 'required' => false],
            ],
        ]);

        $this->registerSchema('sign_up', [
            'version' => 2,
            'params' => [
                'method' => ['type' => 'string', 'required' => false],
                'user_id' => ['type' => 'string', 'required' => false],
                'plan' => ['type' => 'string', 'required' => false],
            ],
        ]);

        $this->registerSchema('page_view', [
            'version' => 1,
            'params' => [
                'page_title' => ['type' => 'string', 'required' => false],
                'page_location' => ['type' => 'string', 'required' => false],
                'page_referrer' => ['type' => 'string', 'required' => false],
            ],
        ]);

        $this->registerSchema('login', [
            'version' => 1,
            'params' => [
                'method' => ['type' => 'string', 'required' => false],
                'user_id' => ['type' => 'string', 'required' => false],
            ],
        ]);
    }
}
