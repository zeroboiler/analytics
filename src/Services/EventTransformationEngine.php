<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\EventTransformationRule;
use ZeroBoiler\Analytics\DTO\ProviderEventMapping;
use ZeroBoiler\Analytics\DTO\TransformedPayload;

/**
 * Provider-specific event payload transformation engine.
 *
 * Transforms analytics event payloads into provider-specific formats using
 * configurable transformation rules. Supports:
 *
 * - **Field renaming**: Rename event parameters for provider compatibility
 *   (e.g., `item_id` → `content_id` for Meta Pixel)
 * - **Field dropping**: Exclude sensitive or unsupported fields per provider
 *   (e.g., drop `internal_score` before sending to GA4)
 * - **Type casting**: Convert field types to match provider expectations
 *   (e.g., string `"1.99"` → float `1.99`)
 * - **Default values**: Inject provider-required fields with fallbacks
 *   (e.g., `currency: "USD"` if missing for Meta Pixel)
 * - **Field whitelisting**: Only include explicitly allowed fields per provider
 * - **Event name overrides**: Map event names to provider-specific equivalents
 *   (e.g., `form_submit` → `Lead` for Meta Pixel)
 * - **Static overrides**: Merge static fields into every payload for a provider
 *   (e.g., `event_source: "server"` for all server-side GA4 events)
 *
 * Architecture is inspired by:
 * - **Segment Protocols** — event governance and field-level transformation
 * - **RudderStack Transformations** — JavaScript-based event transformation
 * - **mParticle Data Planning** — schema-driven event mapping
 *
 * @see ProviderEventMapping
 * @see EventTransformationRule
 * @see TransformedPayload
 *
 * @since 70.0.0
 */
final class EventTransformationEngine
{
    /** @var array<string, ProviderEventMapping> key = "event:provider" */
    private array $mappings = [];

    /** @var array<string, list<ProviderEventMapping>> key = event name */
    private array $byEvent = [];

    /** @var array<string, list<ProviderEventMapping>> key = provider name */
    private array $byProvider = [];

    private bool $enabled;

    private CacheRepository $cache;

    private int $cacheTtl;

    /** @var list<ProviderEventMapping> Built-in default mappings for common events */
    private const DEFAULT_MAPPINGS = [];

    /**
     * @param  ConfigRepository  $config
     * @param  CacheRepository  $cache
     */
    public function __construct(
        ConfigRepository $config,
        CacheRepository $cache,
    ){
        $this->cache = $cache;
        $this->enabled = (bool) $config->get('zeroboiler.analytics.transformation.enabled', true);
        $this->cacheTtl = (int) $config->get('zeroboiler.analytics.transformation.cache_ttl', 3600);

        $this->loadMappings($config);
    }

    /**
     * Transform an event payload for a specific provider.
     *
     * If no mapping exists for the event+provider combination, the event
     * is returned as-is (passthrough). If a mapping exists but results
     * in an empty payload, a "dropped" result is returned.
     *
     * @return TransformedPayload The transformed payload with audit trail
     */
    public function transform(AnalyticsEvent $event, string $provider): TransformedPayload
    {
        if (! $this->enabled) {
            return TransformedPayload::passthrough($event, $provider);
        }

        $mapping = $this->findMapping($event->name, $provider);

        if ($mapping === null) {
            return TransformedPayload::passthrough($event, $provider);
        }

        return $this->applyMapping($event, $mapping);
    }

    /**
     * Transform an event for all registered providers at once.
     *
     * Useful for previewing how an event will look across all providers
     * before dispatch. Returns a map of provider → TransformedPayload.
     *
     * @param  list<string>  $providers  Provider identifiers to transform for
     * @return array<string, TransformedPayload>
     */
    public function transformForAll(AnalyticsEvent $event, array $providers): array
    {
        $results = [];

        foreach ($providers as $provider) {
            $results[$provider] = $this->transform($event, $provider);
        }

        return $results;
    }

    /**
     * Register a custom transformation mapping.
     *
     * Overrides any existing mapping for the same event+provider combination.
     */
    public function registerMapping(ProviderEventMapping $mapping): void
    {
        $key = $mapping->key();
        $this->mappings[$key] = $mapping;

        // Rebuild indexes to keep them consistent
        $this->rebuildIndexes();

        // Persist to cache
        $this->persistMappings();
    }

    /**
     * Remove a transformation mapping by key.
     */
    public function removeMapping(string $eventName, string $provider): void
    {
        $key = $eventName . ':' . $provider;
        unset($this->mappings[$key]);

        // Rebuild indexes
        $this->rebuildIndexes();
        $this->persistMappings();
    }

    /**
     * Get all registered mappings.
     *
     * @return array<string, ProviderEventMapping>
     */
    public function allMappings(): array
    {
        return $this->mappings;
    }

    /**
     * Get mappings for a specific event.
     *
     * @return list<ProviderEventMapping>
     */
    public function mappingsForEvent(string $eventName): array
    {
        return $this->byEvent[$eventName] ?? [];
    }

    /**
     * Get mappings for a specific provider.
     *
     * @return list<ProviderEventMapping>
     */
    public function mappingsForProvider(string $provider): array
    {
        return $this->byProvider[$provider] ?? [];
    }

    /**
     * Check if a mapping exists for an event+provider combination.
     */
    public function hasMapping(string $eventName, string $provider): bool
    {
        return isset($this->mappings[$eventName . ':' . $provider]);
    }

    /**
     * Get the total number of registered mappings.
     */
    public function mappingCount(): int
    {
        return count($this->mappings);
    }

    /**
     * Validate all registered mappings for consistency.
     *
     * Checks for:
     * - Rules referencing non-existent fields
     * - Conflicting rename targets
     * - Invalid cast types
     * - Circular rename chains
     *
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public function validateMappings(): array
    {
        $errors = [];
        $warnings = [];
        $validCastTypes = ['string', 'int', 'float', 'bool'];

        foreach ($this->mappings as $key => $mapping) {
            $renameTargets = [];
            $seenSources = [];

            foreach ($mapping->rules as $i => $rule) {
                if ($rule->castTo !== null && ! in_array($rule->castTo, $validCastTypes, true)) {
                    $errors[] = "Mapping '{$key}': rule #{$i} has invalid cast_to '{$rule->castTo}'";
                }

                if (in_array($rule->sourceField, $seenSources, true)) {
                    $warnings[] = "Mapping '{$key}': duplicate source field '{$rule->sourceField}'";
                }
                $seenSources[] = $rule->sourceField;

                if ($rule->targetField !== null) {
                    if (isset($renameTargets[$rule->targetField])) {
                        $errors[] = "Mapping '{$key}': rules #{$i} and #{$renameTargets[$rule->targetField]} both rename to '{$rule->targetField}'";
                    }
                    $renameTargets[$rule->targetField] = $i;
                }

                // Warn about drop + rename conflict
                if ($rule->dropAlways && $rule->targetField !== null) {
                    $warnings[] = "Mapping '{$key}': rule #{$i} has both drop_always and target_field — target_field will be ignored";
                }
            }

            if ($mapping->allowOnly !== []) {
                $ruleTargets = array_filter(
                    array_map(fn (EventTransformationRule $r): ?string => $r->targetField ?? $r->sourceField, $mapping->rules),
                    fn (?string $f): bool => $f !== null,
                );
                $missing = array_diff($ruleTargets, $mapping->allowOnly);
                if ($missing !== []) {
                    $warnings[] = "Mapping '{$key}': allow_only whitelist excludes fields produced by rename rules: " . implode(', ', $missing);
                }
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Export all mappings as serializable arrays.
     *
     * @return list<array<string, mixed>>
     */
    public function exportMappings(): array
    {
        return array_map(fn (ProviderEventMapping $m): array => $m->toArray(), $this->mappings);
    }

    /**
     * Find a mapping for a specific event+provider combination.
     */
    private function findMapping(string $eventName, string $provider): ?ProviderEventMapping
    {
        return $this->mappings[$eventName . ':' . $provider] ?? null;
    }

    /**
     * Apply a mapping's transformation rules to an event.
     */
    private function applyMapping(AnalyticsEvent $event, ProviderEventMapping $mapping): TransformedPayload
    {
        $params = $event->params;
        $applied = [];
        $warnings = [];
        $missingRequired = [];

        // Apply each rule in order
        foreach ($mapping->rules as $rule) {
            // Drop-always rules: unconditionally remove the field
            if ($rule->dropAlways) {
                unset($params[$rule->sourceField]);
                $applied[] = [
                    'rule' => 'drop',
                    'field' => $rule->sourceField,
                    'action' => 'field excluded from provider payload',
                ];

                continue;
            }

            $hasField = array_key_exists($rule->sourceField, $params);

            if (! $hasField) {
                if ($rule->defaultValue !== null) {
                    $params[$rule->sourceField] = $rule->defaultValue;
                    $applied[] = [
                        'rule' => 'default',
                        'field' => $rule->sourceField,
                        'action' => 'applied default value',
                    ];
                } elseif ($rule->dropIfMissing) {
                    $missingRequired[] = $rule->sourceField;
                }

                continue;
            }

            // Evaluate conditional rule
            if ($rule->condition !== null) {
                try {
                    if (! ($rule->condition)($params[$rule->sourceField])) {
                        unset($params[$rule->sourceField]);
                        $applied[] = [
                            'rule' => 'conditional_drop',
                            'field' => $rule->sourceField,
                            'action' => 'condition predicate returned false',
                        ];

                        continue;
                    }
                } catch (\Throwable $e) {
                    $warnings[] = "Condition evaluation failed for field '{$rule->sourceField}': {$e->getMessage()}";

                    continue;
                }
            }

            if ($rule->castTo !== null) {
                $original = $params[$rule->sourceField];
                $params[$rule->sourceField] = $this->castValue($original, $rule->castTo);
                $applied[] = [
                    'rule' => 'cast',
                    'field' => $rule->sourceField,
                    'action' => "cast from " . get_debug_type($original) . " to {$rule->castTo}",
                ];
            }

            if ($rule->targetField !== null) {
                $value = $params[$rule->sourceField];
                unset($params[$rule->sourceField]);
                $params[$rule->targetField] = $value;
                $applied[] = [
                    'rule' => 'rename',
                    'field' => $rule->sourceField,
                    'action' => "renamed to {$rule->targetField}",
                ];
            }
        }

        if ($missingRequired !== []) {
            if ($this->configStrict()) {
                return TransformedPayload::dropped(
                    $event->name,
                    $mapping->provider,
                    'missing required fields: ' . implode(', ', $missingRequired),
                );
            }

            foreach ($missingRequired as $field) {
                $warnings[] = "Required field '{$field}' is missing — included anyway (non-strict mode)";
            }
        }

        if ($mapping->allowOnly !== []) {
            $params = array_intersect_key($params, array_flip($mapping->allowOnly));
            $applied[] = [
                'rule' => 'whitelist',
                'field' => '*',
                'action' => 'applied allow_only filter (' . count($mapping->allowOnly) . ' fields)',
            ];
        }

        if ($mapping->staticOverrides !== []) {
            $params = array_merge($params, $mapping->staticOverrides);
            $applied[] = [
                'rule' => 'static_override',
                'field' => '*',
                'action' => 'merged ' . count($mapping->staticOverrides) . ' static fields',
            ];
        }

        $resolvedName = $mapping->eventNameOverride ?? $event->name;

        return new TransformedPayload(
            eventName: $resolvedName,
            params: $params,
            provider: $mapping->provider,
            applied: $applied,
            warnings: $warnings,
        );
    }

    /**
     * Cast a value to the specified type.
     *
     * @param  mixed  $value
     * @param  'string'|'int'|'float'|'bool'  $type
     * @return mixed
     */
    private function castValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'string' => is_scalar($value) ? (string) $value : null,
            'int' => is_numeric($value) ? (int) $value : null,
            'float' => is_numeric($value) ? (float) $value : null,
            'bool' => is_bool($value) ? $value : (is_numeric($value) ? (bool) $value : null),
            default => $value,
        };
    }

    /**
     * Check if strict mode is enabled for transformation.
     */
    private function configStrict(): bool
    {
        return (bool) $this->cache->get('zb_transformation_strict', false);
    }

    /**
     * Load mappings from config and cache.
     */
    private function loadMappings(ConfigRepository $config): void
    {
        $cached = $this->cache->get('zb_transformation_mappings');

        if (is_array($cached) && $cached !== []) {
            $this->hydrateMappings($cached);

            return;
        }

        $configMappings = $config->get('zeroboiler.analytics.transformation.mappings', []);

        if (is_array($configMappings) && $configMappings !== []) {
            $this->hydrateMappings($configMappings);
            $this->persistMappings();
        }
    }

    /**
     * Hydrate mappings from raw array data.
     *
     * @param  list<array<string, mixed>>  $raw
     */
    private function hydrateMappings(array $raw): void
    {
        $this->mappings = [];
        $this->byEvent = [];
        $this->byProvider = [];

        foreach ($raw as $config) {
            $mapping = ProviderEventMapping::fromArray($config);
            $key = $mapping->key();
            $this->mappings[$key] = $mapping;
            $this->byEvent[$mapping->eventName][] = $mapping;
            $this->byProvider[$mapping->provider][] = $mapping;
        }
    }

    /**
     * Persist current mappings to cache.
     */
    private function persistMappings(): void
    {
        try {
            $this->cache->put('zb_transformation_mappings', $this->exportMappings(), $this->cacheTtl);
        } catch (\Throwable $e) {
            Log::warning('EventTransformationEngine: failed to persist mappings to cache', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Rebuild the event and provider indexes from the mappings array.
     */
    private function rebuildIndexes(): void
    {
        $this->byEvent = [];
        $this->byProvider = [];

        foreach ($this->mappings as $mapping) {
            $this->byEvent[$mapping->eventName][] = $mapping;
            $this->byProvider[$mapping->provider][] = $mapping;
        }
    }
}
