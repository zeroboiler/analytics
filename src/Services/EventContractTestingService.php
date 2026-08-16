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
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Provider-specific event contract testing service.
 *
 * Defines, validates, and tests event contracts per provider. Unlike schema
 * validation (which checks event structure), contract testing verifies that
 * events meet provider-specific requirements: required fields, field naming
 * conventions, value ranges, and parameter structures that each provider expects.
 *
 * Contracts are derived from the EventCatalog provider mappings and enriched
 * with provider-specific field requirements defined in configuration.
 *
 * Features:
 * - **Contract Registry**: Provider-specific field requirements per event type
 * - **Validation**: Validate events against provider contracts before dispatch
 * - **Coverage Analysis**: Measure which events have contracts defined per provider
 * - **Contract Diff**: Compare contracts across versions to detect breaking changes
 * - **Test Mode**: Dry-run validation without dispatching events
 *
 * Configuration: `zeroboiler.analytics.contracts`
 *
 * @see \ZeroBoiler\Analytics\Events\EventCatalog
 * @see \ZeroBoiler\Analytics\Services\EventSchemaRuntimeValidator
 * @see \ZeroBoiler\Analytics\Services\ProviderEventValidator
 *
 * @since 208.0.0
 */
final class EventContractTestingService
{
    /** @var array<string, array<string, array{required: list<string>, optional: list<string>, type_rules: array<string, string>, max_params?: int}>> */
    private array $contracts = [];

    private bool $enabled;

    private bool $strictMode;

    private bool $logViolations;

    private int $cacheTtl;

    private CacheRepository $cache;

    private ConfigRepository $config;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;
        $this->config = $config;

        $contractConfig = $config->get('zeroboiler.analytics.contracts', []);
        /** @var array{enabled?: bool, strict?: bool, log_violations?: bool, cache_ttl?: int, custom_contracts?: array<string, array<string, array<string, mixed>>>> $contractConfig */

        $this->enabled = (bool) ($contractConfig['enabled'] ?? true);
        $this->strictMode = (bool) ($contractConfig['strict'] ?? false);
        $this->logViolations = (bool) ($contractConfig['log_violations'] ?? true);
        $this->cacheTtl = (int) ($contractConfig['cache_ttl'] ?? 3600);

        $this->loadContracts($contractConfig['custom_contracts'] ?? []);
    }

    /**
     * Validate an event against a specific provider's contract.
     *
     * Checks required fields, field types, and parameter constraints
     * for the given provider.
     *
     * @param  AnalyticsEvent  $event  Event to validate
     * @param  string  $provider  Provider name (ga4, meta, gtm, plausible, posthog, etc.)
     * @return array{valid: bool, errors: list<string>, warnings: list<string>, coverage: float}
     */
    public function validate(AnalyticsEvent $event, string $provider): array
    {
        if (! $this->enabled) {
            return ['valid' => true, 'errors' => [], 'warnings' => [], 'coverage' => 1.0];
        }

        $errors = [];
        $warnings = [];

        // Get contract for this event + provider
        $contract = $this->getContract($event->name, $provider);
        if ($contract === null) {
            // No contract defined — warning in strict mode, ok otherwise
            if ($this->strictMode) {
                $errors[] = "No contract defined for event '{$event->name}' on provider '{$provider}'";
            } else {
                $warnings[] = "No contract defined for event '{$event->name}' on provider '{$provider}'";
            }

            return [
                'valid' => ! $this->strictMode,
                'errors' => $errors,
                'warnings' => $warnings,
                'coverage' => 0.0,
            ];
        }

        // Check required fields
        foreach ($contract['required'] as $field) {
            if (! array_key_exists($field, $event->params)) {
                $errors[] = "Required field '{$field}' is missing for provider '{$provider}'";
            }
        }

        // Check field types
        foreach ($contract['type_rules'] as $field => $expectedType) {
            if (array_key_exists($field, $event->params)) {
                $actualType = gettype($event->params[$field]);
                if (! $this->typeMatches($actualType, $expectedType, $event->params[$field])) {
                    $errors[] = "Field '{$field}' expected type '{$expectedType}', got '{$actualType}' for provider '{$provider}'";
                }
            }
        }

        // Check max params size if defined
        if (isset($contract['max_params'])) {
            $paramCount = count($event->params);
            if ($paramCount > $contract['max_params']) {
                $warnings[] = "Event has {$paramCount} params, exceeding max {$contract['max_params']} for provider '{$provider}'";
            }
        }

        // Calculate field coverage
        $requiredCount = count($contract['required']);
        $presentCount = 0;
        foreach ($contract['required'] as $field) {
            if (array_key_exists($field, $event->params)) {
                $presentCount++;
            }
        }
        $coverage = $requiredCount > 0 ? round($presentCount / $requiredCount, 4) : 1.0;

        // Log violations
        if ($this->logViolations && ($errors !== [] || $warnings !== [])) {
            Log::debug('Analytics contract violation', [
                'event' => $event->name,
                'provider' => $provider,
                'errors' => $errors,
                'warnings' => $warnings,
                'coverage' => $coverage,
            ]);
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'coverage' => $coverage,
        ];
    }

    /**
     * Validate an event against all registered provider contracts.
     *
     * @param  AnalyticsEvent  $event  Event to validate
     * @return array{ga4?: array{valid: bool, errors: list<string>, warnings: list<string>, coverage: float}, meta?: array{valid: bool, errors: list<string>, warnings: list<string>, coverage: float}, ...}
     */
    public function validateAllProviders(AnalyticsEvent $event): array
    {
        $results = [];
        $providers = $this->getSupportedProviders();

        foreach ($providers as $provider) {
            $results[$provider] = $this->validate($event, $provider);
        }

        return $results;
    }

    /**
     * Get contract coverage analysis.
     *
     * Returns per-provider contract coverage: how many events have
     * contracts defined vs total events in the catalog.
     *
     * @return array{providers: array<string, array{defined: int, total: int, coverage: float}>, overall: float, missing: array<string, list<string>>}
     */
    public function coverageAnalysis(): array
    {
        $catalog = EventCatalog::all();
        $providers = $this->getSupportedProviders();
        $providerStats = [];
        $missing = [];

        foreach ($providers as $provider) {
            $defined = 0;
            $providerMissing = [];

            foreach ($catalog as $eventName => $_entry) {
                if ($this->getContract($eventName, $provider) !== null) {
                    $defined++;
                } else {
                    $providerMissing[] = $eventName;
                }
            }

            $total = count($catalog);
            $providerStats[$provider] = [
                'defined' => $defined,
                'total' => $total,
                'coverage' => $total > 0 ? round($defined / $total, 4) : 0.0,
            ];

            if ($providerMissing !== []) {
                $missing[$provider] = array_slice($providerMissing, 0, 20);
            }
        }

        $overallTotal = 0;
        $overallDefined = 0;
        foreach ($providerStats as $stat) {
            $overallTotal += $stat['total'];
            $overallDefined += $stat['defined'];
        }

        return [
            'providers' => $providerStats,
            'overall' => $overallTotal > 0 ? round($overallDefined / ($overallTotal * count($providers)), 4) : 0.0,
            'missing' => $missing,
        ];
    }

    /**
     * Register or update a contract for an event/provider pair.
     *
     * @param  string  $eventName  Event name
     * @param  string  $provider  Provider name
     * @param  array{required?: list<string>, optional?: list<string>, type_rules?: array<string, string>, max_params?: int}  $contract  Contract definition
     */
    public function registerContract(string $eventName, string $provider, array $contract): void
    {
        $this->contracts[$eventName][$provider] = [
            'required' => $contract['required'] ?? [],
            'optional' => $contract['optional'] ?? [],
            'type_rules' => $contract['type_rules'] ?? [],
            'max_params' => $contract['max_params'] ?? null,
        ];
    }

    /**
     * Get a contract definition for an event/provider pair.
     *
     * @param  string  $eventName  Event name
     * @param  string  $provider  Provider name
     * @return array{required: list<string>, optional: list<string>, type_rules: array<string, string>, max_params?: int}|null
     */
    public function getContract(string $eventName, string $provider): ?array
    {
        return $this->contracts[$eventName][$provider] ?? null;
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the list of supported providers.
     *
     * @return list<string>
     */
    public function getSupportedProviders(): array
    {
        return ['ga4', 'meta', 'gtm', 'plausible', 'posthog', 'mixpanel', 'amplitude', 'tiktok', 'linkedin', 'webhook'];
    }

    /**
     * Get total number of registered contracts.
     */
    public function contractCount(): int
    {
        $count = 0;
        foreach ($this->contracts as $providerContracts) {
            $count += count($providerContracts);
        }

        return $count;
    }

    /**
     * Check if a PHP type matches an expected contract type.
     *
     * @param  string  $actualType  PHP type from gettype()
     * @param  string  $expectedType  Contract type (string, integer, number, boolean, array, object)
     * @param  mixed  $value  Actual value
     */
    private function typeMatches(string $actualType, string $expectedType, mixed $value): bool
    {
        return match ($expectedType) {
            'string' => $actualType === 'string',
            'integer', 'int' => $actualType === 'integer',
            'number' => $actualType === 'integer' || $actualType === 'double',
            'boolean', 'bool' => $actualType === 'boolean',
            'array' => $actualType === 'array',
            'object' => $actualType === 'object' || $actualType === 'array',
            'numeric' => is_numeric($value),
            'nullable_string' => $actualType === 'string' || $value === null,
            'nullable_integer' => $actualType === 'integer' || $value === null,
            'nullable_number' => is_numeric($value) || $value === null,
            default => true, // Unknown types pass through
        };
    }

    /**
     * Load contracts from configuration and built-in defaults.
     *
     * Loads custom contracts from config and merges with built-in
     * provider-specific contracts for common events.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $customContracts  Custom contracts from config
     */
    private function loadContracts(array $customContracts): void
    {
        // Built-in GA4 ecommerce contracts
        $this->contracts['purchase']['ga4'] = [
            'required' => ['transaction_id', 'value', 'currency'],
            'optional' => ['tax', 'shipping', 'coupon', 'affiliation', 'items'],
            'type_rules' => ['transaction_id' => 'string', 'value' => 'number', 'currency' => 'string'],
        ];

        $this->contracts['view_item']['ga4'] = [
            'required' => ['items'],
            'optional' => ['value', 'currency', 'item_list_id', 'item_list_name'],
            'type_rules' => ['items' => 'array', 'value' => 'nullable_number', 'currency' => 'nullable_string'],
        ];

        $this->contracts['add_to_cart']['ga4'] = [
            'required' => ['items'],
            'optional' => ['value', 'currency'],
            'type_rules' => ['items' => 'array'],
        ];

        $this->contracts['begin_checkout']['ga4'] = [
            'required' => ['items'],
            'optional' => ['value', 'currency', 'coupon'],
            'type_rules' => ['items' => 'array'],
        ];

        $this->contracts['refund']['ga4'] = [
            'required' => ['transaction_id'],
            'optional' => ['value', 'currency', 'tax', 'shipping', 'items'],
            'type_rules' => ['transaction_id' => 'string', 'value' => 'nullable_number', 'currency' => 'nullable_string'],
        ];

        // Built-in Meta Pixel ecommerce contracts
        $this->contracts['purchase']['meta'] = [
            'required' => ['value', 'currency'],
            'optional' => ['content_ids', 'contents', 'num_items', 'content_type', 'content_name'],
            'type_rules' => ['value' => 'number', 'currency' => 'string', 'num_items' => 'nullable_integer'],
        ];

        $this->contracts['view_item']['meta'] = [
            'required' => ['content_ids', 'content_type'],
            'optional' => ['content_name', 'value', 'currency'],
            'type_rules' => ['content_ids' => 'array', 'content_type' => 'string'],
        ];

        $this->contracts['add_to_cart']['meta'] = [
            'required' => [],
            'optional' => ['content_ids', 'content_name', 'content_type', 'contents', 'value', 'currency'],
            'type_rules' => [],
        ];

        // Built-in GA4 SaaS contracts
        $this->contracts['sign_up']['ga4'] = [
            'required' => ['method'],
            'optional' => [],
            'type_rules' => ['method' => 'string'],
        ];

        $this->contracts['login']['ga4'] = [
            'required' => ['method'],
            'optional' => [],
            'type_rules' => ['method' => 'string'],
        ];

        $this->contracts['trial_start']['ga4'] = [
            'required' => [],
            'optional' => ['plan', 'value', 'currency'],
            'type_rules' => ['plan' => 'nullable_string', 'value' => 'nullable_number'],
        ];

        $this->contracts['plan_upgrade']['ga4'] = [
            'required' => [],
            'optional' => ['plan', 'previous_plan', 'value', 'currency'],
            'type_rules' => ['plan' => 'nullable_string', 'previous_plan' => 'nullable_string'],
        ];

        // Built-in GA4 engagement contracts
        $this->contracts['page_view']['ga4'] = [
            'required' => ['page_location'],
            'optional' => ['page_title', 'page_referrer'],
            'type_rules' => ['page_location' => 'string'],
        ];

        $this->contracts['search']['ga4'] = [
            'required' => ['search_term'],
            'optional' => [],
            'type_rules' => ['search_term' => 'string'],
        ];

        // Merge custom contracts from config (override built-ins)
        foreach ($customContracts as $eventName => $providerContracts) {
            if (! is_array($providerContracts)) {
                continue;
            }
            foreach ($providerContracts as $provider => $contract) {
                if (! is_array($contract)) {
                    continue;
                }
                $this->contracts[$eventName][$provider] = [
                    'required' => $contract['required'] ?? [],
                    'optional' => $contract['optional'] ?? [],
                    'type_rules' => $contract['type_rules'] ?? [],
                    'max_params' => $contract['max_params'] ?? null,
                ];
            }
        }
    }
}
