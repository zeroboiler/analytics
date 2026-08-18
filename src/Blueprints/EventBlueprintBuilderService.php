<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Blueprints;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;
use ZeroBoiler\Analytics\Support\EngagementFormatConverter;
use ZeroBoiler\Analytics\Support\SaaSFormatConverter;

/**
 * Fluent event builder service powered by blueprint definitions.
 *
 * Complements EventBlueprintRegistry with advanced capabilities:
 * - Automatic parameter type coercion (string → int/float/bool)
 * - Computed/derived parameters (e.g. value = price × quantity)
 * - Provider-ready payload generation (GA4, Meta, PostHog formats)
 * - Batch building from param variations
 * - Detailed validation reports (dry-run mode)
 * - PII field auto-redaction from payloads
 *
 * @since 246.0.0
 *
 * @example
 *   $event = $builder->from('ecommerce.purchase.completed')
 *       ->with(['transaction_id' => 'TXN-1', 'value' => '99.99', 'currency' => 'USD'])
 *       ->compute(['value' => 'price * quantity'])
 *       ->coerce()
 *       ->build();
 *
 *   $payloads = $builder->from('saas.signup.email')
 *       ->with(['user_id' => '123'])
 *       ->toProviderPayloads();
 */
final class EventBlueprintBuilderService
{
    /** @var array<string, mixed> Coercion rules: type → callable */
    private const COERCION_RULES = [
        'int' => 'self::coerceToInt',
        'integer' => 'self::coerceToInt',
        'float' => 'self::coerceToFloat',
        'double' => 'self::coerceToFloat',
        'number' => 'self::coerceToFloat',
        'bool' => 'self::coerceToBool',
        'boolean' => 'self::coerceToBool',
        'string' => 'self::coerceToString',
        'array' => 'self::coerceToArray',
    ];

    /** @var list<string> Default PII field patterns */
    private const DEFAULT_PII_PATTERNS = [
        'email', 'password', 'credit_card', 'ssn', 'phone',
        'ip_address', 'user_agent', 'address', 'zip_code',
    ];

    private EventBlueprintRegistry $registry;

    private ConfigRepository $config;

    /** @var string|null Blueprint name being built */
    private ?string $blueprintName = null;

    /** @var array<string, mixed> Accumulated parameters */
    private array $params = [];

    /** @var array<string, string> Computed param expressions (param → expression) */
    private array $computedParams = [];

    /** @var string|null Client ID override */
    private ?string $clientId = null;

    /** @var string|null User ID override */
    private ?string $userId = null;

    /** @var string|null Priority override */
    private ?string $priority = null;

    /** @var string|null Source override */
    private ?string $source = null;

    /** @var string|null Session ID override */
    private ?string $sessionId = null;

    /** @var list<string> PII fields to redact from payloads */
    private array $piiFields = [];

    /** @var bool Whether auto-coercion is enabled */
    private bool $autoCoerce = true;

    /** @var list<string> Validation errors from last build */
    private array $lastErrors = [];

    /** @var list<string> Validation warnings from last build */
    private array $lastWarnings = [];

    /** @var list<string> Coercion log from last build */
    private array $coercionLog = [];

    public function __construct(EventBlueprintRegistry $registry, ConfigRepository $config): void
    {
        $this->registry = $registry;
        $this->config = $config;

        $piiConfig = $config->get('zeroboiler.analytics.blueprint_builder.pii_fields', []);
        /** @var list<string> $piiConfig */
        $this->piiFields = $piiConfig !== [] ? $piiConfig : self::DEFAULT_PII_PATTERNS;
    }

    // ── Fluent API ──────────────────────────────────────────────────

    /**
     * Select a blueprint to build from.
     *
     * @param  string  $name  Blueprint name (e.g. 'saas.signup.email')
     * @return self
     */
    public function from(string $name): self
    {
        $this->blueprintName = $name;
        $this->params = [];
        $this->computedParams = [];
        $this->clientId = null;
        $this->userId = null;
        $this->priority = null;
        $this->source = null;
        $this->sessionId = null;
        $this->lastErrors = [];
        $this->lastWarnings = [];
        $this->coercionLog = [];

        return $this;
    }

    /**
     * Set parameters for the event.
     *
     * @param  array<string, mixed>  $params  Event parameters
     * @return self
     */
    public function with(array $params): self
    {
        $this->params = array_merge($this->params, $params);

        return $this;
    }

    /**
     * Set a single parameter.
     *
     * @param  string  $key  Parameter name
     * @param  mixed  $value  Parameter value
     * @return self
     */
    public function set(string $key, mixed $value): self
    {
        $this->params[$key] = $value;

        return $this;
    }

    /**
     * Declare computed parameters using simple expression syntax.
     *
     * Supports: `price * quantity`, `value + tax`, `count(items)`, `upper(method)`
     *
     * @param  array<string, string>  $expressions  param_name → expression
     * @return self
     */
    public function compute(array $expressions): self
    {
        $this->computedParams = array_merge($this->computedParams, $expressions);

        return $this;
    }

    /**
     * Set the client ID for the event.
     *
     * @return self
     */
    public function clientId(string $id): self
    {
        $this->clientId = $id;

        return $this;
    }

    /**
     * Set the user ID for the event.
     *
     * @return self
     */
    public function userId(string $id): self
    {
        $this->userId = $id;

        return $this;
    }

    /**
     * Set the priority for the event.
     *
     * @param  'critical'|'high'|'normal'|'low'|'background'  $priority
     * @return self
     */
    public function priority(string $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * Set the event source.
     *
     * @return self
     */
    public function source(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    /**
     * Set the session ID.
     *
     * @return self
     */
    public function sessionId(string $id): self
    {
        $this->sessionId = $id;

        return $this;
    }

    /**
     * Set PII fields to redact from provider payloads.
     *
     * @param  list<string>  $fields  Field names/patterns to redact
     * @return self
     */
    public function redactFields(array $fields): self
    {
        $this->piiFields = $fields;

        return $this;
    }

    /**
     * Enable or disable auto-coercion.
     *
     * @return self
     */
    public function autoCoerce(bool $enabled): self
    {
        $this->autoCoerce = $enabled;

        return $this;
    }

    // ── Build Methods ──────────────────────────────────────────────

    /**
     * Build the final AnalyticsEvent DTO.
     *
     * Resolves the blueprint, merges defaults, coerces types,
     * evaluates computed params, validates required fields,
     * and returns the event. Throws on validation failure.
     *
     * @return AnalyticsEvent
     * @throws \InvalidArgumentException If blueprint not found or validation fails
     */
    public function build(): AnalyticsEvent
    {
        $this->guardBlueprintSelected();

        $blueprint = $this->registry->find($this->blueprintName);

        if ($blueprint === null) {
            throw new \InvalidArgumentException(
                "Blueprint '{$this->blueprintName}' not found in registry.",
            );
        }

        // Merge blueprint defaults → user params
        $merged = array_merge($blueprint->defaultParams, $this->params);

        // Auto-coerce parameter types
        if ($this->autoCoerce) {
            $merged = $this->coerceParams($merged, $blueprint->paramTypes);
        }

        // Evaluate computed params
        $merged = $this->evaluateComputedParams($merged);

        // Validate required params
        $errors = $blueprint->validateParams($merged);

        if ($blueprint->isDeprecated()) {
            $this->lastWarnings[] = $blueprint->deprecationNotice() ?? "Blueprint '{$this->blueprintName}' is deprecated.";
        }

        if ($errors !== []) {
            $this->lastErrors = $errors;

            throw new \InvalidArgumentException(
                "Blueprint '{$this->blueprintName}' validation failed: " . implode('; ', $errors),
            );
        }

        $eventName = $blueprint->baseEvent !== '' ? $blueprint->baseEvent : $blueprint->name;

        return new AnalyticsEvent(
            name: $eventName,
            params: $merged,
            clientId: $this->clientId,
            userId: $this->userId,
            priority: $this->priority ?? $blueprint->priority,
            source: $this->source,
            category: $blueprint->category !== 'custom' ? $blueprint->category : null,
            sessionId: $this->sessionId,
        );
    }

    /**
     * Build an event without throwing on validation errors.
     *
     * Returns a detailed report with the event, errors, warnings, and coercion log.
     *
     * @return array{event: AnalyticsEvent, errors: list<string>, warnings: list<string>, coerced: list<string>, blueprint: string|null, params: array<string, mixed>}
     */
    public function buildReport(): array
    {
        $this->lastErrors = [];
        $this->lastWarnings = [];
        $this->coercionLog = [];

        $blueprint = $this->blueprintName !== null
            ? $this->registry->find($this->blueprintName)
            : null;

        if ($blueprint === null) {
            $name = $this->blueprintName ?? '(none)';

            return [
                'event' => new AnalyticsEvent(name: $name, params: $this->params),
                'errors' => ["Blueprint '{$name}' not found"],
                'warnings' => [],
                'coerced' => [],
                'blueprint' => $this->blueprintName,
                'params' => $this->params,
            ];
        }

        // Deprecation warning
        if ($blueprint->isDeprecated()) {
            $this->lastWarnings[] = $blueprint->deprecationNotice() ?? "Deprecated: {$blueprint->name}";
        }

        // Merge & coerce
        $merged = array_merge($blueprint->defaultParams, $this->params);

        if ($this->autoCoerce) {
            $merged = $this->coerceParams($merged, $blueprint->paramTypes);
        }

        // Computed params
        $merged = $this->evaluateComputedParams($merged);

        // Validate
        $errors = $blueprint->validateParams($merged);
        $this->lastErrors = $errors;

        // Type warnings for params without declared types
        foreach ($merged as $key => $value) {
            if (! isset($blueprint->paramTypes[$key]) && is_array($value)) {
                $this->lastWarnings[] = "Parameter '{$key}' has no declared type but contains an array value.";
            }
        }

        $eventName = $blueprint->baseEvent !== '' ? $blueprint->baseEvent : $blueprint->name;

        $event = new AnalyticsEvent(
            name: $eventName,
            params: $merged,
            clientId: $this->clientId,
            userId: $this->userId,
            priority: $this->priority ?? $blueprint->priority,
            source: $this->source,
            category: $blueprint->category !== 'custom' ? $blueprint->category : null,
            sessionId: $this->sessionId,
        );

        return [
            'event' => $event,
            'errors' => $this->lastErrors,
            'warnings' => $this->lastWarnings,
            'coerced' => $this->coercionLog,
            'blueprint' => $this->blueprintName,
            'params' => $merged,
        ];
    }

    // ── Provider Payload Generation ─────────────────────────────────

    /**
     * Generate provider-ready payloads for the current blueprint build.
     *
     * Returns payloads formatted for GA4, Meta Pixel, and PostHog.
     * PII fields are automatically redacted.
     *
     * @return array{ga4: array<string, mixed>, meta: array<string, mixed>|null, posthog: array<string, mixed>, raw: array<string, mixed>}
     */
    public function toProviderPayloads(): array
    {
        $report = $this->buildReport();
        $event = $report['event'];
        $eventName = $event->name;
        $params = $this->redactPii($event->params);

        // Look up catalog entry for provider names
        $catalogEntry = EventCatalog::get($eventName);

        // GA4 payload
        $ga4EventName = $catalogEntry['ga4'] ?? $eventName;
        $ga4Payload = array_merge(['event_name' => $ga4EventName], $params);
        if ($event->clientId !== null) {
            $ga4Payload['client_id'] = $event->clientId;
        }
        if ($event->userId !== null) {
            $ga4Payload['user_id'] = $event->userId;
        }

        // Meta Pixel payload
        $metaPayload = null;
        $metaEventName = $catalogEntry['meta'] ?? null;
        if ($metaEventName !== null) {
            $metaPayload = array_merge(['event_name' => $metaEventName], $params);
            if (isset($params['value']) && isset($params['currency'])) {
                $metaPayload['value'] = (float) $params['value'];
                $metaPayload['currency'] = (string) $params['currency'];
            }
        }

        // PostHog payload
        $posthogEventName = $catalogEntry['posthog'] ?? $eventName;
        $posthogPayload = array_merge(['event' => $posthogEventName, 'properties' => $params]);
        if ($event->clientId !== null) {
            $posthogPayload['distinct_id'] = $event->clientId;
        }

        return [
            'ga4' => $ga4Payload,
            'meta' => $metaPayload,
            'posthog' => $posthogPayload,
            'raw' => $params,
        ];
    }

    // ── Batch Building ──────────────────────────────────────────────

    /**
     * Build multiple events from param variations.
     *
     * Each variation is merged with the base params and built independently.
     * Useful for generating test data, replay simulations, or multi-item events.
     *
     * @param  list<array<string, mixed>>  $variations  List of param overrides
     * @return list<array{event: AnalyticsEvent, errors: list<string>, warnings: list<string>}>
     *
     * @example
     *   $results = $builder->from('ecommerce.cart.added')
 *       ->with(['currency' => 'USD'])
 *       ->buildBatch([
 *           ['item_id' => 'SKU-1', 'price' => 29.99, 'quantity' => 2],
 *           ['item_id' => 'SKU-2', 'price' => 49.99, 'quantity' => 1],
 *       ]);
     */
    public function buildBatch(array $variations): array
    {
        $baseParams = $this->params;
        $results = [];

        foreach ($variations as $variation) {
            $this->params = array_merge($baseParams, $variation);
            $report = $this->buildReport();
            $results[] = [
                'event' => $report['event'],
                'errors' => $report['errors'],
                'warnings' => $report['warnings'],
            ];
        }

        // Restore base params
        $this->params = $baseParams;

        return $results;
    }

    // ── Schema Introspection ────────────────────────────────────────

    /**
     * Get the parameter schema for the current (or named) blueprint.
     *
     * @param  string|null  $blueprintName  Blueprint name (uses selected if null)
     * @return array{name: string, label: string, category: string, base_event: string, required: list<string>, optional: list<string>, types: array<string, string>, defaults: array<string, mixed>}|null
     */
    public function schema(?string $blueprintName = null): ?array
    {
        $name = $blueprintName ?? $this->blueprintName;

        if ($name === null) {
            return null;
        }

        $blueprint = $this->registry->find($name);

        if ($blueprint === null) {
            return null;
        }

        $required = $blueprint->requiredParams;
        $optional = array_keys(array_diff_key($blueprint->paramTypes, array_flip($required)));

        return [
            'name' => $blueprint->name,
            'label' => $blueprint->label,
            'category' => $blueprint->category,
            'base_event' => $blueprint->baseEvent,
            'required' => $required,
            'optional' => $optional,
            'types' => $blueprint->paramTypes,
            'defaults' => $blueprint->defaultParams,
        ];
    }

    /**
     * Get all available blueprint schemas.
     *
     * @return list<array{name: string, label: string, category: string, base_event: string, param_count: int, required_count: int}>
     */
    public function allSchemas(): array
    {
        $schemas = [];

        foreach ($this->registry->names() as $name) {
            $s = $this->schema($name);

            if ($s !== null) {
                $schemas[] = [
                    'name' => $s['name'],
                    'label' => $s['label'],
                    'category' => $s['category'],
                    'base_event' => $s['base_event'],
                    'param_count' => count($s['types']),
                    'required_count' => count($s['required']),
                ];
            }
        }

        return $schemas;
    }

    // ── Coercion ────────────────────────────────────────────────────

    /**
     * Coerce parameter values to their declared types.
     *
     * @param  array<string, mixed>  $params  Parameters to coerce
     * @param  array<string, string>  $typeMap  Parameter name → expected type
     * @return array<string, mixed>
     */
    private function coerceParams(array $params, array $typeMap): array
    {
        foreach ($typeMap as $key => $expectedType) {
            if (! array_key_exists($key, $params)) {
                continue;
            }

            $value = $params[$key];
            $rule = self::COERCION_RULES[$expectedType] ?? null;

            if ($rule === null) {
                continue;
            }

            $coerced = self::$rule($value);

            if ($coerced !== $value) {
                $params[$key] = $coerced;
                $this->coercionLog[] = "{$key}: " . get_debug_type($value) . ' → ' . get_debug_type($coerced);
            }
        }

        return $params;
    }

    /**
     * Evaluate computed parameter expressions.
     *
     * Supports simple arithmetic expressions referencing other params.
     * Syntax: `param_name` references other params, `count(array_param)` counts array items.
     *
     * @param  array<string, mixed>  $params  Current params (including already-set values)
     * @return array<string, mixed>
     */
    private function evaluateComputedParams(array $params): array
    {
        foreach ($this->computedParams as $target => $expression) {
            $value = $this->evaluateExpression($expression, $params);

            if ($value !== null) {
                $params[$target] = $value;
            }
        }

        return $params;
    }

    /**
     * Evaluate a single computed expression.
     *
     * @param  string  $expression  Expression string
     * @param  array<string, mixed>  $params  Available params for variable substitution
     * @return int|float|string|null
     */
    private function evaluateExpression(string $expression, array $params): int|float|string|null
    {
        $expr = trim($expression);

        // count(array_param) function
        if (preg_match('/^count\((\w+)\)$/', $expr, $matches)) {
            $arrayVal = $params[$matches[1]] ?? null;

            return is_array($arrayVal) ? count($arrayVal) : 0;
        }

        // upper(param) / lower(param) functions
        if (preg_match('/^(upper|lower)\((\w+)\)$/', $expr, $matches)) {
            $strVal = $params[$matches[2]] ?? null;
            $str = is_string($strVal) || $strVal instanceof \Stringable ? (string) $strVal : null;

            if ($str === null) {
                return null;
            }

            return $matches[1] === 'upper' ? strtoupper($str) : strtolower($str);
        }

        // Simple arithmetic: param * param, param + param, param - param, param / param
        if (preg_match('/^(\w+)\s*([+\-*/])\s*(\w+)$/', $expr, $matches)) {
            $left = $params[$matches[1]] ?? $matches[1];
            $right = $params[$matches[3]] ?? $matches[3];
            $operator = $matches[2];

            if (! is_numeric($left) || ! is_numeric($right)) {
                return null;
            }

            $leftFloat = (float) $left;
            $rightFloat = (float) $right;

            return match ($operator) {
                '+' => $leftFloat + $rightFloat,
                '-' => $leftFloat - $rightFloat,
                '*' => $leftFloat * $rightFloat,
                '/' => $rightFloat !== 0.0 ? $leftFloat / $rightFloat : null,
                default => null,
            };
        }

        // Plain variable reference
        if (array_key_exists($expr, $params)) {
            return $params[$expr];
        }

        return null;
    }

    // ── PII Redaction ───────────────────────────────────────────────

    /**
     * Redact PII fields from a parameter array.
     *
     * @param  array<string, mixed>  $params  Parameters to redact
     * @return array<string, mixed>
     */
    private function redactPii(array $params): array
    {
        foreach ($params as $key => $value) {
            foreach ($this->piiFields as $piiPattern) {
                if (stripos($key, $piiPattern) !== false) {
                    $params[$key] = '[REDACTED]';

                    break;
                }
            }
        }

        return $params;
    }

    // ── Static Coercion Helpers ─────────────────────────────────────

    /**
     * Coerce a value to integer.
     */
    private static function coerceToInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return 0;
    }

    /**
     * Coerce a value to float.
     */
    private static function coerceToFloat(mixed $value): float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    /**
     * Coerce a value to boolean.
     */
    private static function coerceToBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * Coerce a value to string.
     */
    private static function coerceToString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Coerce a value to array.
     */
    private static function coerceToArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null) {
            return [];
        }

        return [$value];
    }

    // ── Diagnostics ─────────────────────────────────────────────────

    /**
     * Get validation errors from the last build attempt.
     *
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->lastErrors;
    }

    /**
     * Get validation warnings from the last build attempt.
     *
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->lastWarnings;
    }

    /**
     * Get the coercion log from the last build attempt.
     *
     * @return list<string>
     */
    public function getCoercionLog(): array
    {
        return $this->coercionLog;
    }

    /**
     * Get service configuration summary.
     *
     * @return array{auto_coerce: bool, pii_fields: list<string>, registry_total: int}
     */
    public function getConfig(): array
    {
        return [
            'auto_coerce' => $this->autoCoerce,
            'pii_fields' => $this->piiFields,
            'registry_total' => $this->registry->count(),
        ];
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) $this->config->get('zeroboiler.analytics.blueprint_builder.enabled', true);
    }

    /**
     * Guard that a blueprint has been selected.
     *
     * @throws \LogicException If no blueprint is selected
     */
    private function guardBlueprintSelected(): void
    {
        if ($this->blueprintName === null || $this->blueprintName === '') {
            throw new \LogicException('No blueprint selected. Call from() before build().');
        }
    }
}
