<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Schema;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event Schema Registry — centralized registry for EventSchemaDefinition instances.
 *
 * Provides a central store for registering, retrieving, and querying
 * event schemas built with EventSchemaBuilder. Supports config-driven
 * and programmatic registration with optional cache persistence.
 *
 * Integration points:
 * - EventSchemaBuilder::build() → register()
 * - AnalyticsConfigValidator → validate against registered schemas
 * - AnalyticsDeployGate → check schema coverage
 * - EventSchemaExportService → export registered schemas as JSON/YAML
 *
 * Usage:
 *   $registry = app(EventSchemaRegistryExtended::class);
 *
 *   // Programmatic registration
 *   $registry->register(EventSchemaBuilder::define('my_event')
 *       ->category('custom')
 *       ->string('user_id')->required()
 *       ->build());
 *
 *   // Lookup
 *   $schema = $registry->get('my_event');
 *   $rules = $registry->validationRules('my_event');
 *
 * @since 118.0.0
 *
 * @see \ZeroBoiler\Analytics\Schema\EventSchemaBuilder
 * @see \ZeroBoiler\Analytics\Schema\EventSchemaDefinition
 */
final class EventSchemaRegistryExtended
{
    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'zb_schema_registry_ext_';

    /** @var int Default cache TTL (24 hours) */
    private const DEFAULT_TTL = 86400;

    /** @var array<string, EventSchemaDefinition> Registered schemas */
    private array $schemas = [];

    /** @var CacheRepository Cache repository */
    private CacheRepository $cache;

    /** @var int Cache TTL in seconds */
    private int $cacheTtl;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  int  $cacheTtl  Cache TTL in seconds
     */
    public function __construct(CacheRepository $cache, int $cacheTtl = self::DEFAULT_TTL){
        $this->cache = $cache;
        $this->cacheTtl = $cacheTtl;
        $this->loadBuiltInSchemas();
    }

    /**
     * Register a schema definition.
     *
     * If a schema with the same name already exists, it is replaced
     * (last-write-wins, matching Laravel's config merge behavior).
     *
     * @param  EventSchemaDefinition  $schema  Schema to register
     * @return void
     */
    public function register(EventSchemaDefinition $schema): void
    {
        $this->schemas[$schema->name] = $schema;
    }

    /**
     * Register multiple schemas at once.
     *
     * @param  list<EventSchemaDefinition>  $schemas  List of schemas to register
     * @return void
     */
    public function registerMany(array $schemas): void
    {
        foreach ($schemas as $schema) {
            $this->register($schema);
        }
    }

    /**
     * Get a registered schema by name.
     *
     * @param  string  $name  Event name
     * @return EventSchemaDefinition|null  Schema definition or null
     */
    public function get(string $name): ?EventSchemaDefinition
    {
        return $this->schemas[$name] ?? null;
    }

    /**
     * Check if a schema is registered.
     *
     * @param  string  $name  Event name
     * @return bool
     */
    public function has(string $name): bool
    {
        return isset($this->schemas[$name]);
    }

    /**
     * Get all registered schema names.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->schemas);
    }

    /**
     * Get all registered schemas.
     *
     * @return array<string, EventSchemaDefinition>
     */
    public function all(): array
    {
        return $this->schemas;
    }

    /**
     * Get the total number of registered schemas.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->schemas);
    }

    /**
     * Get schemas grouped by category.
     *
     * @return array<string, list<EventSchemaDefinition>>
     */
    public function byCategory(): array
    {
        $grouped = [];

        foreach ($this->schemas as $schema) {
            $grouped[$schema->category][] = $schema;
        }

        return $grouped;
    }

    /**
     * Get Laravel validation rules for a registered schema.
     *
     * Uses EventSchemaBuilder's rule generation for the schema's properties.
     * Returns an empty array if the schema is not registered.
     *
     * @param  string  $name  Event name
     * @return array<string, string>  Property name → validation rule string
     */
    public function validationRules(string $name): array
    {
        $schema = $this->get($name);

        if ($schema === null) {
            return [];
        }

        return $this->schemaToRules($schema);
    }

    /**
     * Validate params against a registered schema.
     *
     * Checks:
     * 1. All required properties are present
     * 2. Property types match (string, int, float, bool, array)
     * 3. Enum values are within allowed set
     * 4. String lengths within max
     * 5. Numeric values within min/max
     *
     * @param  string  $name  Event name
     * @param  array<string, mixed>  $params  Event parameters to validate
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public function validate(string $name, array $params): array
    {
        $schema = $this->get($name);

        if ($schema === null) {
            return [
                'valid' => false,
                'errors' => ["Schema '{$name}' is not registered in the schema registry."],
                'warnings' => [],
            ];
        }

        $errors = [];
        $warnings = [];

        foreach ($schema->requiredProperties() as $requiredProp) {
            if (! array_key_exists($requiredProp, $params)) {
                $def = $schema->properties[$requiredProp] ?? null;
                if ($def === null || ! $def->hasDefault) {
                    $errors[] = "Required property '{$requiredProp}' is missing.";
                }
            }
        }

        foreach ($params as $key => $value) {
            $def = $schema->properties[$key] ?? null;

            if ($def === null) {
                $warnings[] = "Unknown property '{$key}' — not defined in schema.";
                continue;
            }

            // Type check
            $typeError = $this->validateType($key, $value, $def);
            if ($typeError !== null) {
                $errors[] = $typeError;
            }
        }

        return [
            'valid' => count($errors) === 0,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Remove a registered schema.
     *
     * @param  string  $name  Event name
     * @return bool  True if removed, false if not found
     */
    public function forget(string $name): bool
    {
        if (isset($this->schemas[$name])) {
            unset($this->schemas[$name]);

            return true;
        }

        return false;
    }

    /**
     * Clear all registered schemas.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->schemas = [];
    }

    /**
     * Export all schemas as an array.
     *
     * @return array<string, array<string, mixed>>
     */
    public function export(): array
    {
        $exported = [];

        foreach ($this->schemas as $name => $schema) {
            $exported[$name] = $schema->toArray();
        }

        return $exported;
    }

    /**
     * Get summary statistics about registered schemas.
     *
     * @return array{total: int, categories: array<string, int>, provider_coverage: array<string, int>, total_properties: int, required_properties: int}
     */
    public function summary(): array
    {
        $categories = [];
        $providerCounts = array_fill_keys(['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'], 0);
        $totalProperties = 0;
        $totalRequired = 0;

        foreach ($this->schemas as $schema) {
            $categories[$schema->category] = ($categories[$schema->category] ?? 0) + 1;

            $mappings = $schema->providerMappings();
            foreach ($mappings as $provider => $name) {
                if ($name !== null) {
                    $providerCounts[$provider] = ($providerCounts[$provider] ?? 0) + 1;
                }
            }

            $totalProperties += count($schema->properties);
            $totalRequired += count($schema->requiredProperties());
        }

        return [
            'total' => count($this->schemas),
            'categories' => $categories,
            'provider_coverage' => $providerCounts,
            'total_properties' => $totalProperties,
            'required_properties' => $totalRequired,
        ];
    }

    /**
     * Check which registered schemas have catalog entries.
     *
     * @return array{in_catalog: list<string>, missing_from_catalog: list<string>}
     */
    public function catalogCoverage(): array
    {
        $inCatalog = [];
        $missing = [];

        foreach ($this->schemas as $name => $schema) {
            if (EventCatalog::has($name)) {
                $inCatalog[] = $name;
            } else {
                $missing[] = $name;
            }
        }

        return [
            'in_catalog' => $inCatalog,
            'missing_from_catalog' => $missing,
        ];
    }

    /**
     * Load built-in schemas that map to catalog events.
     *
     * Pre-registers schemas for core catalog events (sign_up, login,
     * purchase, page_view) to provide out-of-the-box validation.
     */
    private function loadBuiltInSchemas(): void
    {
        // SaaS core events
        $this->register(
            EventSchemaBuilder::define('sign_up')
                ->category('saas')
                ->description('User registration completed')
                ->tag('acquisition', 'acquisition')
                ->string('user_id')->required()
                ->enum('method', ['email', 'oauth_google', 'oauth_github', 'sso', 'magic_link'])->default('email')
                ->string('referral')
                ->ga4('sign_up')
                ->meta('CompleteRegistration')
                ->posthog('$signup')
                ->plausible('signup')
                ->build()
        );

        $this->register(
            EventSchemaBuilder::define('login')
                ->category('saas')
                ->description('User authenticated')
                ->tag('engagement', 'retention')
                ->string('user_id')->required()
                ->enum('method', ['email', 'oauth', 'sso', 'magic_link', 'password'])
                ->boolean('remember_me')->default(false)
                ->ga4('login')
                ->meta('Login')
                ->posthog('$identify')
                ->plausible('login')
                ->build()
        );

        $this->register(
            EventSchemaBuilder::define('start_trial')
                ->category('saas')
                ->description('Free trial started')
                ->tag('acquisition', 'revenue', 'acquisition')
                ->string('user_id')->required()
                ->string('plan')->required()
                ->integer('trial_days')->min(1)->max(90)->default(14)
                ->ga4('start_trial')
                ->meta('StartTrial')
                ->posthog('start_trial')
                ->build()
        );

        $this->register(
            EventSchemaBuilder::define('purchase')
                ->category('ecommerce')
                ->description('Purchase completed')
                ->tag('revenue', 'conversion', 'acquisition')
                ->string('transaction_id')->required()
                ->float('value')->required()->min(0)
                ->string('currency')->default('USD')
                ->array_('items')
                ->string('coupon')
                ->float('tax')
                ->float('shipping')
                ->ga4('purchase')
                ->meta('Purchase')
                ->posthog('purchase')
                ->build()
        );

        $this->register(
            EventSchemaBuilder::define('page_view')
                ->category('engagement')
                ->description('Page viewed')
                ->tag('engagement')
                ->string('page_title')
                ->url('page_url')
                ->string('referrer')
                ->ga4('page_view')
                ->meta('PageView')
                ->posthog('$pageview')
                ->plausible('pageview')
                ->build()
        );

        $this->register(
            EventSchemaBuilder::define('cancellation')
                ->category('saas')
                ->description('Subscription cancelled')
                ->tag('churn', 'revenue')
                ->string('user_id')->required()
                ->string('plan')
                ->string('reason')
                ->string('feedback')
                ->ga4('cancellation')
                ->meta('CancelSubscription')
                ->posthog('cancellation')
                ->build()
        );
    }

    /**
     * Generate Laravel validation rules from a schema definition.
     *
     * @param  EventSchemaDefinition  $schema  Schema definition
     * @return array<string, string>  Property name → validation rule string
     */
    private function schemaToRules(EventSchemaDefinition $schema): array
    {
        $rules = [];

        foreach ($schema->properties as $name => $def) {
            $typeRule = $this->propertyToRule($def);
            $prefix = $def->isRequired ? 'required|' : 'nullable|';
            $rules[$name] = $prefix . $typeRule;
        }

        return $rules;
    }

    /**
     * Convert a property definition to a Laravel validation rule.
     *
     * @param  PropertyDefinition  $def  Property definition
     * @return string  Laravel validation rule
     */
    private function propertyToRule(PropertyDefinition $def): string
    {
        return match ($def->type) {
            'string' => 'string|max:' . $def->maxLength,
            'int' => 'integer|min:' . $def->minValue . '|max:' . $def->maxValue,
            'float', 'numeric' => 'numeric|min:' . $def->minValue . '|max:' . $def->maxValue,
            'bool' => 'boolean',
            'array' => 'array|max:' . $def->maxArrayLength,
            'enum' => 'string|in:' . implode(',', $def->enumValues),
            'timestamp' => 'date',
            'email' => 'email|max:255',
            'url' => 'url|max:2048',
            default => 'string',
        };
    }

    /**
     * Validate a single property value against its definition.
     *
     * @param  string  $key  Property name
     * @param  mixed  $value  Property value
     * @param  PropertyDefinition  $def  Property definition
     * @return string|null  Error message or null if valid
     */
    private function validateType(string $key, mixed $value, PropertyDefinition $def): ?string
    {
        // Null is OK for optional fields
        if ($value === null) {
            return $def->isRequired ? "Property '{$key}' is required but received null." : null;
        }

        $typeValid = match ($def->type) {
            'string', 'email', 'url', 'timestamp' => is_string($value),
            'int' => is_int($value),
            'float', 'numeric' => is_int($value) || is_float($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            'enum' => is_string($value) && in_array($value, $def->enumValues, true),
            default => true,
        };

        if (! $typeValid) {
            $expected = $def->type;
            $actual = get_debug_type($value);

            return "Property '{$key}' expected type '{$expected}', got '{$actual}'.";
        }

        // String length check
        if (is_string($value) && mb_strlen($value) > $def->maxLength) {
            return "Property '{$key}' exceeds max length ({$def->maxLength}).";
        }

        // Numeric range check
        if ((is_int($value) || is_float($value)) && ($value < $def->minValue || $value > $def->maxValue)) {
            return "Property '{$key}' value {$value} is outside range [{$def->minValue}, {$def->maxValue}].";
        }

        // Array length check
        if (is_array($value) && count($value) > $def->maxArrayLength) {
            return "Property '{$key}' array length exceeds maximum ({$def->maxArrayLength}).";
        }

        // Pattern check
        if ($def->pattern !== null && is_string($value)) {
            if (preg_match($def->pattern, $value) !== 1) {
                return "Property '{$key}' does not match required pattern.";
            }
        }

        return null;
    }
}
