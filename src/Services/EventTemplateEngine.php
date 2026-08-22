<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
/**
 * Event Template Engine for rapid analytics event creation.
 *
 * Provides a config-driven template system that allows SaaS products to define
 * reusable event templates with parameter schemas, default values, and
 * validation rules. Templates are loaded from config and can be used to build
 * fully-typed AnalyticsEvent instances with minimal code.
 *
 * Templates are defined in `zeroboiler.analytics.event_templates` config
 * and can be extended at runtime via `registerTemplate()`.
 *
 * @since 140.0.0
 */
final class EventTemplateEngine
{
    /** @var array<string, array{name: string, category: string, params: array<string, array{type: string, required: bool, default?: mixed, description?: string, enum?: list<string>}>}> */
    private array $templates = [];

    private ConfigRepository $config;

    /** @var array<string, list<string>> Runtime-registered templates (not persisted) */
    private array $registeredTemplates = [];

    /**
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(ConfigRepository $config){
        $this->config = $config;
        $this->loadTemplatesFromConfig();
    }

    /**
     * Load event templates from configuration.
     *
     * Merges built-in templates with user-defined templates from config.
     */
    private function loadTemplatesFromConfig(): void
    {
        $this->templates = $this->builtInTemplates();

        $customTemplates = $this->config->get('zeroboiler.analytics.event_templates.definitions', []);
        /** @var array<string, array{name?: string, category?: string, params?: array<string, array{type?: string, required?: bool, default?: mixed, description?: string, enum?: list<string>}>}> $customTemplates */

        foreach ($customTemplates as $key => $definition) {
            $name = $definition['name'] ?? $key;
            $category = $definition['category'] ?? 'custom';
            $params = $definition['params'] ?? [];

            $this->templates[$key] = [
                'name' => $name,
                'category' => $category,
                'params' => $params,
            ];
        }
    }

    /**
     * Get built-in event templates for common SaaS patterns.
     *
     * @return array<string, array{name: string, category: string, params: array<string, array{type: string, required: bool, default?: mixed, description?: string}>}>
     */
    private function builtInTemplates(): array
    {
        return [
            // E-commerce templates
            'ecommerce.purchase' => [
                'name' => 'purchase',
                'category' => 'ecommerce',
                'params' => [
                    'transaction_id' => ['type' => 'string', 'required' => true, 'description' => 'Unique transaction identifier'],
                    'value' => ['type' => 'float', 'required' => true, 'description' => 'Total transaction value'],
                    'currency' => ['type' => 'string', 'required' => false, 'default' => 'USD', 'description' => 'ISO 4217 currency code'],
                    'items' => ['type' => 'array', 'required' => false, 'default' => [], 'description' => 'Array of item objects'],
                    'tax' => ['type' => 'float', 'required' => false, 'default' => 0.0, 'description' => 'Tax amount'],
                    'shipping' => ['type' => 'float', 'required' => false, 'default' => 0.0, 'description' => 'Shipping amount'],
                    'coupon' => ['type' => 'string', 'required' => false, 'description' => 'Coupon code applied'],
                ],
            ],
            'ecommerce.add_to_cart' => [
                'name' => 'add_to_cart',
                'category' => 'ecommerce',
                'params' => [
                    'item_id' => ['type' => 'string', 'required' => true, 'description' => 'Product SKU or ID'],
                    'item_name' => ['type' => 'string', 'required' => true, 'description' => 'Product name'],
                    'price' => ['type' => 'float', 'required' => false, 'default' => 0.0, 'description' => 'Item price'],
                    'quantity' => ['type' => 'int', 'required' => false, 'default' => 1, 'description' => 'Quantity added'],
                    'currency' => ['type' => 'string', 'required' => false, 'default' => 'USD', 'description' => 'ISO 4217 currency code'],
                    'category' => ['type' => 'string', 'required' => false, 'description' => 'Product category'],
                ],
            ],
            'ecommerce.view_item' => [
                'name' => 'view_item',
                'category' => 'ecommerce',
                'params' => [
                    'item_id' => ['type' => 'string', 'required' => true, 'description' => 'Product SKU or ID'],
                    'item_name' => ['type' => 'string', 'required' => true, 'description' => 'Product name'],
                    'price' => ['type' => 'float', 'required' => false, 'default' => 0.0, 'description' => 'Item price'],
                    'currency' => ['type' => 'string', 'required' => false, 'default' => 'USD', 'description' => 'ISO 4217 currency code'],
                    'category' => ['type' => 'string', 'required' => false, 'description' => 'Product category'],
                    'brand' => ['type' => 'string', 'required' => false, 'description' => 'Product brand'],
                ],
            ],
            'ecommerce.refund' => [
                'name' => 'refund',
                'category' => 'ecommerce',
                'params' => [
                    'transaction_id' => ['type' => 'string', 'required' => true, 'description' => 'Original transaction ID'],
                    'value' => ['type' => 'float', 'required' => true, 'description' => 'Refund amount'],
                    'currency' => ['type' => 'string', 'required' => false, 'default' => 'USD', 'description' => 'ISO 4217 currency code'],
                    'reason' => ['type' => 'string', 'required' => false, 'description' => 'Refund reason'],
                ],
            ],

            // SaaS lifecycle templates
            'saas.sign_up' => [
                'name' => 'sign_up',
                'category' => 'saas',
                'params' => [
                    'method' => ['type' => 'string', 'required' => false, 'default' => 'email', 'description' => 'Sign-up method (email, google, github)'],
                    'plan' => ['type' => 'string', 'required' => false, 'description' => 'Selected plan name'],
                    'referrer' => ['type' => 'string', 'required' => false, 'description' => 'Referral source'],
                ],
            ],
            'saas.trial_start' => [
                'name' => 'start_trial',
                'category' => 'saas',
                'params' => [
                    'plan' => ['type' => 'string', 'required' => false, 'default' => 'free', 'description' => 'Trial plan name'],
                    'trial_days' => ['type' => 'int', 'required' => false, 'default' => 14, 'description' => 'Trial duration in days'],
                    'source' => ['type' => 'string', 'required' => false, 'description' => 'Trial start source'],
                ],
            ],
            'saas.subscription' => [
                'name' => 'subscribe',
                'category' => 'saas',
                'params' => [
                    'plan' => ['type' => 'string', 'required' => true, 'description' => 'Subscription plan name'],
                    'value' => ['type' => 'float', 'required' => true, 'description' => 'Subscription amount'],
                    'currency' => ['type' => 'string', 'required' => false, 'default' => 'USD', 'description' => 'ISO 4217 currency code'],
                    'billing_cycle' => ['type' => 'string', 'required' => false, 'default' => 'monthly', 'description' => 'Billing cycle (monthly, yearly)'],
                    'is_trial_conversion' => ['type' => 'bool', 'required' => false, 'default' => false, 'description' => 'Whether converted from trial'],
                ],
            ],
            'saas.plan_upgrade' => [
                'name' => 'plan_upgrade',
                'category' => 'saas',
                'params' => [
                    'from_plan' => ['type' => 'string', 'required' => true, 'description' => 'Previous plan name'],
                    'to_plan' => ['type' => 'string', 'required' => true, 'description' => 'New plan name'],
                    'revenue_diff' => ['type' => 'float', 'required' => false, 'default' => 0.0, 'description' => 'Revenue difference'],
                    'currency' => ['type' => 'string', 'required' => false, 'default' => 'USD', 'description' => 'ISO 4217 currency code'],
                ],
            ],
            'saas.cancellation' => [
                'name' => 'cancellation',
                'category' => 'saas',
                'params' => [
                    'plan' => ['type' => 'string', 'required' => true, 'description' => 'Cancelled plan name'],
                    'reason' => ['type' => 'string', 'required' => false, 'description' => 'Cancellation reason'],
                    'feedback' => ['type' => 'string', 'required' => false, 'description' => 'User feedback text'],
                    'is_churn' => ['type' => 'bool', 'required' => false, 'default' => true, 'description' => 'Whether this counts as churn'],
                ],
            ],

            // Engagement templates
            'engagement.page_view' => [
                'name' => 'page_view',
                'category' => 'engagement',
                'params' => [
                    'title' => ['type' => 'string', 'required' => false, 'description' => 'Page title'],
                    'section' => ['type' => 'string', 'required' => false, 'description' => 'Page section'],
                    'referrer' => ['type' => 'string', 'required' => false, 'description' => 'Referrer URL'],
                ],
            ],
            'engagement.form_submit' => [
                'name' => 'form_submit',
                'category' => 'engagement',
                'params' => [
                    'form_name' => ['type' => 'string', 'required' => true, 'description' => 'Form identifier'],
                    'form_type' => ['type' => 'string', 'required' => false, 'default' => 'contact', 'description' => 'Form type'],
                    'success' => ['type' => 'bool', 'required' => false, 'default' => true, 'description' => 'Whether submission succeeded'],
                ],
            ],
            'engagement.search' => [
                'name' => 'search',
                'category' => 'engagement',
                'params' => [
                    'query' => ['type' => 'string', 'required' => true, 'description' => 'Search query string'],
                    'results_count' => ['type' => 'int', 'required' => false, 'default' => 0, 'description' => 'Number of results returned'],
                    'category' => ['type' => 'string', 'required' => false, 'description' => 'Search category filter'],
                ],
            ],
            'engagement.error' => [
                'name' => 'error',
                'category' => 'engagement',
                'params' => [
                    'message' => ['type' => 'string', 'required' => true, 'description' => 'Error message'],
                    'code' => ['type' => 'string', 'required' => false, 'description' => 'Error code'],
                    'severity' => ['type' => 'string', 'required' => false, 'default' => 'error', 'enum' => ['info', 'warning', 'error', 'critical'], 'description' => 'Error severity level'],
                    'source' => ['type' => 'string', 'required' => false, 'description' => 'Error source (client, server)'],
                ],
            ],

            // Feature adoption template
            'feature.usage' => [
                'name' => 'feature_used',
                'category' => 'saas',
                'params' => [
                    'feature_name' => ['type' => 'string', 'required' => true, 'description' => 'Feature identifier'],
                    'feature_category' => ['type' => 'string', 'required' => false, 'description' => 'Feature category'],
                    'usage_duration_ms' => ['type' => 'int', 'required' => false, 'description' => 'Usage duration in milliseconds'],
                    'success' => ['type' => 'bool', 'required' => false, 'default' => true, 'description' => 'Whether feature usage succeeded'],
                ],
            ],
        ];
    }

    /**
     * Build an AnalyticsEvent from a template key.
     *
     * Merges user-provided params with template defaults, validates
     * required params, and returns a fully-formed AnalyticsEvent.
     *
     * @param  string  $templateKey  Template key (e.g. 'ecommerce.purchase')
     * @param  array<string, mixed>  $params  User-provided parameter values
     * @param  string|null  $clientId  Client ID for identity tracking
     * @param  string|null  $userId  User ID for identity tracking
     * @return AnalyticsEvent
     *
     * @throws \InvalidArgumentException if template not found or required params missing
     */
    public function build(string $templateKey, array $params = [], ?string $clientId = null, ?string $userId = null): AnalyticsEvent
    {
        $template = $this->templates[$templateKey] ?? null;

        if ($template === null) {
            throw new \InvalidArgumentException("Event template '{$templateKey}' not found. Available: " . implode(', ', $this->templateKeys()));
        }

        $resolvedParams = $this->resolveParams($template['params'], $params);

        return new AnalyticsEvent(
            name: $template['name'],
            params: $resolvedParams,
            clientId: $clientId,
            userId: $userId,
        );
    }

    /**
     * Resolve template params by merging defaults with user values.
     *
     * Validates required params and applies type coercion.
     *
     * @param  array<string, array{type: string, required: bool, default?: mixed, enum?: list<string>}>  $schema
     * @param  array<string, mixed>  $userParams
     * @return array<string, mixed>
     */
    private function resolveParams(array $schema, array $userParams): array
    {
        $resolved = [];

        foreach ($schema as $paramName => $definition) {
            $type = $definition['type'] ?? 'string';
            $required = $definition['required'] ?? false;
            $default = $definition['default'] ?? null;
            $enum = $definition['enum'] ?? null;

            if (array_key_exists($paramName, $userParams)) {
                $value = $userParams[$paramName];
                $coerced = $this->coerceType($value, $type);

                if ($enum !== null && ! in_array($coerced, $enum, true)) {
                    throw new \InvalidArgumentException(
                        "Parameter '{$paramName}' value '" . (string) $coerced . "' is not one of: " . implode(', ', $enum)
                    );
                }

                $resolved[$paramName] = $coerced;
            } elseif ($required && $default === null) {
                throw new \InvalidArgumentException("Required parameter '{$paramName}' is missing for template");
            } else {
                $resolved[$paramName] = $default;
            }
        }

        // Pass through any extra user params not in template schema
        foreach ($userParams as $key => $value) {
            if (! array_key_exists($key, $schema)) {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }

    /**
     * Coerce a value to the specified type.
     *
     * @param  mixed  $value  The value to coerce
     * @param  string  $type  Target type (string, int, float, bool, array)
     * @return mixed
     */
    private function coerceType(mixed $value, string $type): mixed
    {
        return match ($type) {
            'string' => is_string($value) ? $value : (string) $value,
            'int', 'integer' => is_int($value) ? $value : (int) $value,
            'float', 'double' => is_float($value) ? $value : (float) $value,
            'bool', 'boolean' => is_bool($value) ? $value : (bool) $value,
            'array' => is_array($value) ? $value : [],
            default => $value,
        };
    }

    /**
     * Register a custom template at runtime.
     *
     * @param  string  $key  Template key
     * @param  array{name: string, category: string, params: array<string, array{type: string, required: bool, default?: mixed, description?: string}>}  $definition
     */
    public function registerTemplate(string $key, array $definition): void
    {
        $this->templates[$key] = $definition;
        $this->registeredTemplates[$key] = [$key, $definition['name'] ?? $key];
    }

    /**
     * Get all template keys.
     *
     * @return list<string>
     */
    public function templateKeys(): array
    {
        return array_keys($this->templates);
    }

    /**
     * Get a template definition by key.
     *
     * @return array{name: string, category: string, params: array<string, array{type: string, required: bool, default?: mixed, description?: string}>}|null
     */
    public function getTemplate(string $key): ?array
    {
        return $this->templates[$key] ?? null;
    }

    /**
     * Check if a template key exists.
     */
    public function hasTemplate(string $key): bool
    {
        return isset($this->templates[$key]);
    }

    /**
     * Get the total number of templates.
     */
    public function count(): int
    {
        return count($this->templates);
    }

    /**
     * Get templates grouped by category.
     *
     * @return array<string, list<string>>
     */
    public function byCategory(): array
    {
        $grouped = [];

        foreach ($this->templates as $key => $template) {
            $category = $template['category'] ?? 'custom';
            $grouped[$category][] = $key;
        }

        return $grouped;
    }

    /**
     * Get runtime-registered templates (not in config).
     *
     * @return list<array{0: string, 1: string}>
     */
    public function registeredTemplates(): array
    {
        return $this->registeredTemplates;
    }

    /**
     * Validate an event name against the catalog.
     *
     * Checks if the event name exists in any event catalog category.
     *
     * @return array{valid: bool, catalog_match: string|null, category: string|null, template_match: string|null}
     */
    public function validateEventName(string $eventName): array
    {
        // Check if it's a known template
        $templateMatch = null;
        foreach ($this->templates as $key => $template) {
            if ($template['name'] === $eventName) {
                $templateMatch = $key;
                break;
            }
        }

        // Check unified catalog
        $catalogMatch = null;
        $category = null;

        if (EventCatalog::has($eventName)) {
            $catalogMatch = $eventName;
            $category = EventCatalog::getCategory($eventName);
        }

        return [
            'valid' => $catalogMatch !== null || $templateMatch !== null,
            'catalog_match' => $catalogMatch,
            'category' => $category,
            'template_match' => $templateMatch,
        ];
    }

    /**
     * Get a summary of the template engine state.
     *
     * @return array{total_templates: int, categories: array<string, int>, built_in_count: int, custom_count: int, registered_count: int, catalog_coverage: int}
     */
    public function summary(): array
    {
        $builtIn = $this->builtInTemplates();
        $customCount = count($this->templates) - count($builtIn);

        return [
            'total_templates' => count($this->templates),
            'categories' => array_map(
                static fn (array $keys): int => count($keys),
                $this->byCategory(),
            ),
            'built_in_count' => count($builtIn),
            'custom_count' => max(0, $customCount),
            'registered_count' => count($this->registeredTemplates),
            'catalog_coverage' => EventCatalog::count(),
        ];
    }

    /**
     * Get the param schema for a template (useful for auto-form generation).
     *
     * @return array<string, array{type: string, required: bool, default?: mixed, description?: string}>
     */
    public function getParamSchema(string $templateKey): array
    {
        $template = $this->templates[$templateKey] ?? null;

        if ($template === null) {
            return [];
        }

        return $template['params'] ?? [];
    }
}
