<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * User properties store for analytics identity enrichment.
 *
 * Persists and aggregates user traits across events — plan, signup date,
 * total revenue, session count, last active timestamp, feature usage counts.
 * Properties are stored in the cache (configurable TTL) and can be:
 *
 * - **Set** explicitly via `set()` or `merge()` (from identify/traits)
 * - **Incremented** via `increment()` (e.g., session_count, events_fired)
 * - **Aggregated** via `aggregate()` with min/max/sum strategies
 * - **Read** via `get()` or `all()` for enrichment in event pipelines
 * - **Exported** via `toArray()` for API responses and provider user properties
 *
 * Properties are keyed by user_id (or client_id for anonymous users).
 * When a client_id is linked to a user_id, properties are merged.
 *
 * @phpstan-type PropertyDefinition array{type: 'string'|'int'|'float'|'bool'|'array', default: mixed, aggregation?: 'sum'|'min'|'max'|'last'|'set'|'count', ttl?: int}
 *
 * @since 1.0.0
 */
final class UserPropertiesStore
{
    private const KEY_PREFIX = 'zb_user_props_';
    private const LINK_KEY = 'zb_user_link_';
    private const DEFAULT_TTL = 2592000; // 30 days (seconds)

    private CacheRepository $cache;

    private ConfigRepository $config;

    private bool $enabled;

    private bool $debug;

    private int $defaultTtl;

    /** @var array<string, PropertyDefinition> */
    private array $schema = [];

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;
        $this->config = $config;

        $storeConfig = $config->get('zeroboiler.analytics.user_properties', []);
        /** @var array{enabled?: bool, debug?: bool, ttl?: int, schema?: array<string, PropertyDefinition>} $storeConfig */
        $this->enabled = (bool) ($storeConfig['enabled'] ?? true);
        $this->debug = (bool) ($storeConfig['debug'] ?? false);
        $this->defaultTtl = (int) ($storeConfig['ttl'] ?? self::DEFAULT_TTL);

        if (isset($storeConfig['schema']) && is_array($storeConfig['schema'])) {
            $this->schema = $storeConfig['schema'];
        }
    }

    /**
     * Set a user property.
     *
     * If a schema definition exists for this key, the value is cast to the
     * defined type. If an aggregation strategy is defined, the value is
     * aggregated instead of replaced.
     *
     * @param  string  $identity  user_id or client_id
     * @param  string  $key  Property key
     * @param  mixed  $value  Property value
     */
    public function set(string $identity, string $key, mixed $value): void
    {
        if (! $this->enabled) {
            return;
        }

        // Check for schema-defined aggregation
        $definition = $this->schema[$key] ?? null;

        if ($definition !== null && isset($definition['aggregation'])) {
            $this->aggregate($identity, $key, $value, $definition['aggregation'], $definition['ttl'] ?? null);

            return;
        }

        $properties = $this->loadProperties($identity);
        $properties[$key] = $this->castValue($value, $definition);
        $this->saveProperties($identity, $properties);

        if ($this->debug) {
            Log::debug('UserPropertiesStore: set', [
                'identity' => $identity,
                'key' => $key,
                'value' => $value,
            ]);
        }
    }

    /**
     * Set multiple user properties at once (merge with existing).
     *
     * @param  string  $identity  user_id or client_id
     * @param  array<string, mixed>  $properties  Key-value pairs to set
     */
    public function merge(string $identity, array $properties): void
    {
        if (! $this->enabled || empty($properties)) {
            return;
        }

        $existing = $this->loadProperties($identity);

        foreach ($properties as $key => $value) {
            $definition = $this->schema[$key] ?? null;

            if ($definition !== null && isset($definition['aggregation'])) {
                $existing[$key] = $this->applyAggregation(
                    $existing[$key] ?? $definition['default'] ?? null,
                    $value,
                    $definition['aggregation'],
                );
            } else {
                $existing[$key] = $this->castValue($value, $definition);
            }
        }

        $this->saveProperties($identity, $existing);

        if ($this->debug) {
            Log::debug('UserPropertiesStore: merge', [
                'identity' => $identity,
                'keys' => array_keys($properties),
            ]);
        }
    }

    /**
     * Increment a numeric property.
     *
     * @param  string  $identity  user_id or client_id
     * @param  string  $key  Property key
     * @param  int|float  $by  Amount to increment (default: 1)
     */
    public function increment(string $identity, string $key, int|float $by = 1): void
    {
        if (! $this->enabled) {
            return;
        }

        $properties = $this->loadProperties($identity);
        $current = $properties[$key] ?? 0;
        $properties[$key] = is_int($by) && is_int($current)
            ? $current + $by
            : (float) $current + (float) $by;
        $this->saveProperties($identity, $properties);

        if ($this->debug) {
            Log::debug('UserPropertiesStore: increment', [
                'identity' => $identity,
                'key' => $key,
                'by' => $by,
                'result' => $properties[$key],
            ]);
        }
    }

    /**
     * Aggregate a property value using a strategy.
     *
     * @param  string  $identity  user_id or client_id
     * @param  string  $key  Property key
     * @param  mixed  $value  New value
     * @param  string  $strategy  Aggregation strategy (sum, min, max, last, set, count)
     * @param  int|null  $ttl  Optional per-property TTL override
     */
    public function aggregate(string $identity, string $key, mixed $value, string $strategy, ?int $ttl = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $properties = $this->loadProperties($identity);
        $properties[$key] = $this->applyAggregation($properties[$key] ?? null, $value, $strategy);
        $this->saveProperties($identity, $properties, $ttl);

        if ($this->debug) {
            Log::debug('UserPropertiesStore: aggregate', [
                'identity' => $identity,
                'key' => $key,
                'strategy' => $strategy,
                'value' => $value,
                'result' => $properties[$key],
            ]);
        }
    }

    /**
     * Get a single user property.
     *
     * @param  string  $identity  user_id or client_id
     * @param  string  $key  Property key
     * @param  mixed  $default  Default value if not set
     * @return mixed
     */
    public function get(string $identity, string $key, mixed $default = null): mixed
    {
        $properties = $this->loadProperties($identity);

        return $properties[$key] ?? $default;
    }

    /**
     * Get all user properties.
     *
     * @param  string  $identity  user_id or client_id
     * @return array<string, mixed>
     */
    public function all(string $identity): array
    {
        return $this->loadProperties($identity);
    }

    /**
     * Get all user properties as an associative array (for API export).
     *
     * @param  string  $identity  user_id or client_id
     * @return array<string, mixed>
     */
    public function toArray(string $identity): array
    {
        return $this->loadProperties($identity);
    }

    /**
     * Link a client ID to a user ID, merging their properties.
     *
     * When an anonymous user authenticates, their client-side properties
     * are merged into their authenticated user properties. The client ID
     * link is stored so future lookups resolve to the user ID.
     *
     * @param  string  $clientId  Anonymous client tracking ID
     * @param  string  $userId  Authenticated user ID
     */
    public function linkIdentity(string $clientId, string $userId): void
    {
        if (! $this->enabled) {
            return;
        }

        // Store link mapping
        $this->cache->put(
            self::LINK_KEY . $clientId,
            $userId,
            $this->defaultTtl,
        );

        // Merge client properties into user properties
        $clientProps = $this->loadProperties($clientId);
        $userProps = $this->loadProperties($userId);
        $merged = array_merge($clientProps, $userProps);

        // Mark identity link
        $merged['_linked_client_id'] = $clientId;
        $merged['_linked_at'] = time();

        $this->saveProperties($userId, $merged);

        // Clear client properties to prevent stale data
        $this->cache->forget(self::KEY_PREFIX . $clientId);

        if ($this->debug) {
            Log::debug('UserPropertiesStore: identity linked', [
                'client_id' => $clientId,
                'user_id' => $userId,
                'merged_keys' => array_keys($merged),
            ]);
        }
    }

    /**
     * Resolve the canonical identity for a user or client.
     *
     * If a client ID is linked to a user ID, returns the user ID.
     * Otherwise returns the provided identity.
     *
     * @param  string  $identity  user_id or client_id
     * @return string  Resolved identity (user_id if linked, else original)
     */
    public function resolveIdentity(string $identity): string
    {
        // Check if this is a client_id linked to a user_id
        $linkedUserId = $this->cache->get(self::LINK_KEY . $identity);

        if (is_string($linkedUserId) && $linkedUserId !== '') {
            return $linkedUserId;
        }

        return $identity;
    }

    /**
     * Delete all properties for an identity (GDPR right to erasure).
     *
     * @param  string  $identity  user_id or client_id
     */
    public function delete(string $identity): void
    {
        $this->cache->forget(self::KEY_PREFIX . $identity);

        if ($this->debug) {
            Log::debug('UserPropertiesStore: deleted', [
                'identity' => $identity,
            ]);
        }
    }

    /**
     * Check if the user properties store is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the property schema definitions.
     *
     * @return array<string, PropertyDefinition>
     */
    public function schema(): array
    {
        return $this->schema;
    }

    /**
     * Load properties from cache.
     *
     * @param  string  $identity  user_id or client_id
     * @return array<string, mixed>
     */
    private function loadProperties(string $identity): array
    {
        $cached = $this->cache->get(self::KEY_PREFIX . $identity);

        if (is_array($cached)) {
            return $cached;
        }

        return [];
    }

    /**
     * Save properties to cache.
     *
     * @param  string  $identity  user_id or client_id
     * @param  array<string, mixed>  $properties
     * @param  int|null  $ttl  Optional TTL override
     */
    private function saveProperties(string $identity, array $properties, ?int $ttl = null): void
    {
        $this->cache->put(
            self::KEY_PREFIX . $identity,
            $properties,
            $ttl ?? $this->defaultTtl,
        );
    }

    /**
     * Apply an aggregation strategy to merge old and new values.
     *
     * @param  mixed  $current  Current stored value
     * @param  mixed  $incoming  New value to aggregate
     * @param  string  $strategy  Aggregation strategy
     * @return mixed
     */
    private function applyAggregation(mixed $current, mixed $incoming, string $strategy): mixed
    {
        return match ($strategy) {
            'sum' => (float) ($current ?? 0) + (float) $incoming,
            'min' => $current === null
                ? $incoming
                : min((float) $current, (float) $incoming),
            'max' => $current === null
                ? $incoming
                : max((float) $current, (float) $incoming),
            'last' => $incoming,
            'set' => $this->addToSet($current, $incoming),
            'count' => (int) ($current ?? 0) + 1,
            default => $incoming,
        };
    }

    /**
     * Add a value to a set (unique collection).
     *
     * @param  mixed  $current  Current set or null
     * @param  mixed  $item  Item to add
     * @return list<mixed>
     */
    private function addToSet(mixed $current, mixed $item): array
    {
        $set = is_array($current) ? $current : [];

        if (! in_array($item, $set, true)) {
            $set[] = $item;
        }

        return $set;
    }

    /**
     * Cast a value according to schema definition.
     *
     * @param  mixed  $value
     * @param  PropertyDefinition|null  $definition
     * @return mixed
     */
    private function castValue(mixed $value, ?array $definition): mixed
    {
        if ($definition === null) {
            return $value;
        }

        return match ($definition['type'] ?? 'string') {
            'int' => is_numeric($value) ? (int) $value : 0,
            'float' => is_numeric($value) ? (float) $value : 0.0,
            'bool' => (bool) $value,
            'array' => is_array($value) ? $value : [],
            'string' => is_string($value) ? $value : (string) ($value ?? ''),
            default => $value,
        };
    }
}
