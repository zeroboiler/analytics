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
 * Computed traits engine for automatic profile enrichment.
 *
 * Evaluates user properties against configurable rules to compute
 * derived traits — similar to Segment Computed Traits, mParticle
 * User Attributes, or PostHog Person Properties.
 *
 * Supported operations:
 *   - **comparison**: value >, >=, <, <=, ==, != threshold
 *   - **contains**: string contains substring or array contains value
 *   - **exists**: property exists / does not exist
 *   - **age**: timestamp-based age calculation
 *   - **count**: count of array-type property
 *   - **sum**: aggregate of numeric property values
 *   - **regex**: regex match against string property
 *
 * Configuration: `zeroboiler.analytics.computed_traits`
 *
 * @see \ZeroBoiler\Analytics\Services\CustomerProfileUnificationService
 *
 * @since 29.0.0
 */
final class ComputedTraitsService
{
    private const CACHE_PREFIX = 'zb_ct_';
    private const DEFAULT_TTL = 3600; // 1 hour
    private const MAX_TRAITS = 50;

    private CacheRepository $cache;

    private UserPropertiesStore $propertiesStore;

    private string $cachePrefix;

    private int $cacheTtl;

    private bool $enabled;

    private bool $debug;

    /** @var array<string, array{property: string, operation: string, value: mixed, output: string, type?: string}> */
    private array $rules = [];

    /** @var array<string, callable(array<string, mixed>): mixed> */
    private array $customComputers = [];

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     * @param  UserPropertiesStore  $propertiesStore
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
        UserPropertiesStore $propertiesStore,
    ): void {
        $this->cache = $cache;
        $this->propertiesStore = $propertiesStore;

        $ctConfig = $config->get('zeroboiler.analytics.computed_traits', []);
        /** @var array{enabled?: bool, debug?: bool, cache_ttl?: int, rules?: array<string, array{property: string, operation: string, value: mixed, output: string, type?: string}>} $ctConfig */

        $this->cachePrefix = (string) ($ctConfig['cache_prefix'] ?? self::CACHE_PREFIX);
        $this->cacheTtl = (int) ($ctConfig['cache_ttl'] ?? self::DEFAULT_TTL);
        $this->enabled = (bool) ($ctConfig['enabled'] ?? true);
        $this->debug = (bool) ($ctConfig['debug'] ?? false);

        $configuredRules = $ctConfig['rules'] ?? [];

        foreach ($configuredRules as $name => $rule) {
            $this->addRule(
                name: $name,
                property: $rule['property'] ?? '',
                operation: $rule['operation'] ?? 'exists',
                value: $rule['value'] ?? null,
                output: $rule['output'] ?? $name,
                type: $rule['type'] ?? 'bool',
            );
        }
    }

    /**
     * Add a computed trait rule.
     *
     * @param  string  $name  Unique rule name
     * @param  string  $property  Source property name to evaluate
     * @param  string  $operation  Operation: comparison, contains, exists, age, count, sum, regex
     * @param  mixed  $value  Comparison value (threshold, substring, pattern)
     * @param  string  $output  Output trait name
     * @param  string  $type  Output type: bool, string, int, float
     */
    public function addRule(
        string $name,
        string $property,
        string $operation,
        mixed $value,
        string $output,
        string $type = 'bool',
    ): void {
        $this->rules[$name] = [
            'property' => $property,
            'operation' => $operation,
            'value' => $value,
            'output' => $output,
            'type' => $type,
        ];
    }

    /**
     * Register a custom computer function.
     *
     * Custom computers receive all user properties and return a computed value.
     * Used for complex logic that can't be expressed with simple rules.
     *
     * @param  string  $name  Unique computer name
     * @param  callable(array<string, mixed>): mixed  $computer  Returns the computed value
     */
    public function registerComputer(string $name, callable $computer): void
    {
        $this->customComputers[$name] = $computer;
    }

    /**
     * Evaluate all rules against an identity's properties.
     *
     * Returns computed traits as key-value pairs that can be merged
     * into the user profile.
     *
     * @param  string  $identity  user_id or client_id
     * @return array<string, mixed> Computed trait key-value pairs
     */
    public function evaluate(string $identity): array
    {
        if (! $this->enabled) {
            return [];
        }

        $traits = $this->propertiesStore->all($identity);
        $computed = [];

        // Evaluate each rule
        foreach ($this->rules as $name => $rule) {
            $result = $this->evaluateRule($rule, $traits);
            $outputName = $rule['output'];
            $type = $rule['type'] ?? 'bool';

            $computed[$outputName] = $this->castResult($result, $type);
        }

        // Run custom computers
        foreach ($this->customComputers as $name => $computer) {
            try {
                $result = $computer($traits);
                $computed[$name] = $result;
            } catch (\Throwable $e) {
                if ($this->debug) {
                    Log::debug('ComputedTraits: custom computer failed', [
                        'computer' => $name,
                        'identity' => $identity,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Cache results
        $this->cache->put(
            $this->cachePrefix . $identity,
            $computed,
            $this->cacheTtl,
        );

        if ($this->debug) {
            Log::debug('ComputedTraits: evaluated', [
                'identity' => $identity,
                'rules_evaluated' => count($this->rules),
                'custom_computers' => count($this->customComputers),
                'computed_count' => count($computed),
            ]);
        }

        return $computed;
    }

    /**
     * Evaluate a single rule against properties.
     *
     * @param  array{property: string, operation: string, value: mixed, output: string, type?: string}  $rule
     * @param  array<string, mixed>  $traits
     * @return mixed
     */
    private function evaluateRule(array $rule, array $traits): mixed
    {
        $property = $rule['property'];
        $operation = $rule['operation'];
        $value = $rule['value'];
        $propertyValue = $traits[$property] ?? null;

        return match ($operation) {
            'exists' => $propertyValue !== null,
            'not_exists' => $propertyValue === null,
            'eq', '==' => $propertyValue == $value,
            'neq', '!=' => $propertyValue != $value,
            'gt', '>' => is_numeric($propertyValue) && is_numeric($value) && (float) $propertyValue > (float) $value,
            'gte', '>=' => is_numeric($propertyValue) && is_numeric($value) && (float) $propertyValue >= (float) $value,
            'lt', '<' => is_numeric($propertyValue) && is_numeric($value) && (float) $propertyValue < (float) $value,
            'lte', '<=' => is_numeric($propertyValue) && is_numeric($value) && (float) $propertyValue <= (float) $value,
            'contains' => is_string($propertyValue) && is_string($value) && str_contains($propertyValue, $value),
            'in' => is_array($value) && in_array($propertyValue, $value, true),
            'not_in' => is_array($value) && ! in_array($propertyValue, $value, true),
            'count' => is_array($propertyValue) ? count($propertyValue) : 0,
            'is_true' => $propertyValue === true || $propertyValue === 1 || $propertyValue === '1',
            'is_false' => $propertyValue === false || $propertyValue === 0 || $propertyValue === '0' || $propertyValue === null,
            'regex' => is_string($propertyValue) && is_string($value)
                ? (bool) preg_match((string) $value, $propertyValue)
                : false,
            'age_days' => is_string($propertyValue) || $propertyValue instanceof \DateTimeInterface
                ? max(0, (int) ((time() - (is_string($propertyValue) ? strtotime($propertyValue) : $propertyValue->getTimestamp())) / 86400))
                : 0,
            default => false,
        };
    }

    /**
     * Cast a computed result to the specified type.
     *
     * @param  mixed  $result
     * @param  string  $type
     * @return mixed
     */
    private function castResult(mixed $result, string $type): mixed
    {
        return match ($type) {
            'bool' => (bool) $result,
            'string' => is_string($result) ? $result : (string) ($result ?? ''),
            'int' => is_numeric($result) ? (int) $result : 0,
            'float' => is_numeric($result) ? (float) $result : 0.0,
            default => $result,
        };
    }

    /**
     * Get the list of registered rules.
     *
     * @return array<string, array{property: string, operation: string, output: string, type: string}>
     */
    public function getRules(): array
    {
        return array_map(
            fn (array $rule): array => [
                'property' => $rule['property'],
                'operation' => $rule['operation'],
                'output' => $rule['output'],
                'type' => $rule['type'] ?? 'bool',
            ],
            $this->rules,
        );
    }

    /**
     * Remove a rule by name.
     */
    public function removeRule(string $name): void
    {
        unset($this->rules[$name]);
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get service statistics.
     *
     * @return array{enabled: bool, rules: int, custom_computers: int, cache_ttl: int}
     */
    public function stats(): array
    {
        return [
            'enabled' => $this->enabled,
            'rules' => count($this->rules),
            'custom_computers' => count($this->customComputers),
            'cache_ttl' => $this->cacheTtl,
        ];
    }
}
