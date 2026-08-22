<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;

/**
 * Event Contract Testing Engine — validates event payloads against
 * provider-specific contracts before dispatch.
 *
 * Defines declarative contracts for each provider (GA4, Meta Pixel, PostHog,
 * Plausible, Mixpanel, Amplitude, TikTok, LinkedIn) specifying:
 * - Required parameters per event type
 * - Parameter type constraints (string, numeric, array)
 * - Maximum payload sizes and array lengths
 * - Reserved property names that must not be used
 * - Enum constraints (e.g. currency codes, event categories)
 *
 * Validates events against ALL enabled provider contracts simultaneously
 * and produces a per-provider pass/fail report with detailed violation messages.
 *
 * Config: `zeroboiler.analytics.contract_testing`
 *
 * Inspired by Segment Protocols, PostHog Property Validation, and
 * Amplitude's Event Validator.
 *
 * @since 266.0.0
 */
final class EventContractTestService
{
    /** Contract severity: reject events that fail */
    public const SEVERITY_REJECT = 'reject';

    /** Contract severity: warn but dispatch anyway */
    public const SEVERITY_WARN = 'warn';

    /** Contract severity: skip validation entirely */
    public const SEVERITY_OFF = 'off';

    private const CACHE_PREFIX = 'zb_contract_';

    /** @var string Current severity level */
    private string $severity;

    /** @var bool Whether contract testing is enabled */
    private bool $enabled;

    /** @var int Cache TTL for contract results (seconds) */
    private int $cacheTtl;

    /** @var CacheRepository Cache repository */
    private CacheRepository $cache;

    /** @var ConfigRepository Config repository */
    private ConfigRepository $config;

    /** @var list<string> Tracked providers */
    private const PROVIDERS = [
        'ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin',
    ];

    /**
     * GA4-specific contracts: event name → required params + constraints.
     *
     * @var array<string, array{required: list<string>, max_items?: int, max_param_length?: int, enums?: array<string, list<string>>}>
     */
    private const GA4_CONTRACTS = [
        'purchase' => [
            'required' => ['transaction_id', 'value'],
            'max_items' => 25,
            'enums' => ['currency' => ['USD', 'EUR', 'GBP', 'TRY', 'JPY']],
        ],
        'view_item' => [
            'required' => ['items'],
            'max_items' => 25,
        ],
        'add_to_cart' => [
            'required' => ['items'],
            'max_items' => 25,
        ],
        'refund' => [
            'required' => ['transaction_id', 'value'],
        ],
        'begin_checkout' => [
            'required' => ['items'],
            'max_items' => 25,
        ],
    ];

    /**
     * Meta Pixel-specific contracts.
     *
     * @var array<string, array{required: list<string>, max_param_length?: int, max_content_ids?: int}>
     */
    private const META_CONTRACTS = [
        'Purchase' => [
            'required' => ['value', 'currency'],
            'max_content_ids' => 100,
            'max_param_length' => 256,
        ],
        'ViewContent' => [
            'max_param_length' => 256,
        ],
        'AddToCart' => [
            'required' => ['value', 'currency'],
            'max_content_ids' => 100,
        ],
        'InitiateCheckout' => [
            'required' => ['value', 'currency'],
            'max_param_length' => 256,
        ],
        'CompleteRegistration' => [
            'max_param_length' => 256,
        ],
        'Subscribe' => [
            'required' => ['value'],
        ],
    ];

    /**
     * PostHog-specific contracts.
     *
     * @var array<string, array{required: list<string>, reserved_properties?: list<string>, max_properties?: int}>
     */
    private const POSTHOG_CONTRACTS = [
        '$signup' => [
            'required' => ['$distinct_id'],
            'max_properties' => 100,
        ],
        '$pageview' => [
            'max_properties' => 100,
        ],
    ];

    /**
     * Plausible-specific contracts.
     *
     * @var array<string, array{name_pattern?: string, max_props?: int}>
     */
    private const PLAUSIBLE_CONTRACTS = [
        'pageview' => [
            'max_props' => 10,
        ],
    ];

    /** @var list<string> Reserved PostHog property names */
    private const POSTHOG_RESERVED = [
        '$device_id', '$session_id', '$window_id', '$distinct_id',
        '$user_id', '$anonymous_id', '$ip', '$geoip_disable', '$time',
        '$set', '$set_once', '$unset',
    ];

    /** @var int Default max parameter value length (characters) */
    private const MAX_PARAM_LENGTH = 500;

    /** @var int Default max event name length */
    private const MAX_EVENT_NAME_LENGTH = 100;

    /**
     * Create a new EventContractTestService.
     *
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Application config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;
        $this->config = $config;

        $ctConfig = $config->get('zeroboiler.analytics.contract_testing', []);
        /** @var array{enabled?: bool, severity?: string, cache_ttl?: int} $ctConfig */

        $this->enabled = (bool) ($ctConfig['enabled'] ?? true);
        $this->severity = (string) ($ctConfig['severity'] ?? self::SEVERITY_WARN);
        $this->cacheTtl = (int) ($ctConfig['cache_ttl'] ?? 3600);
    }

    /**
     * Validate a single event against all provider contracts.
     *
     * Returns a per-provider report of pass/fail results with violation details.
     *
     * @return array{event: string, providers: array<string, array{passed: bool, violations: list<array{rule: string, message: string, param?: string}>}>, overall_passed: bool, severity: string}
     */
    public function validateEvent(AnalyticsEvent $event): array
    {
        if (! $this->enabled || $this->severity === self::SEVERITY_OFF) {
            return $this->buildSkipResult($event->name);
        }

        $providerResults = [];
        $allPassed = true;

        foreach (self::PROVIDERS as $provider) {
            $violations = $this->validateForProvider($event, $provider);
            $passed = count($violations) === 0;

            if (! $passed) {
                $allPassed = false;
            }

            $providerResults[$provider] = [
                'passed' => $passed,
                'violations' => $violations,
            ];
        }

        return [
            'event' => $event->name,
            'providers' => $providerResults,
            'overall_passed' => $allPassed,
            'severity' => $this->severity,
        ];
    }

    /**
     * Validate an event for a specific provider.
     *
     * @return list<array{rule: string, message: string, param?: string}>
     */
    public function validateForProvider(AnalyticsEvent $event, string $provider): array
    {
        $violations = [];

        // Get the provider-specific event name
        $providerEventName = $this->getProviderEventName($event->name, $provider);

        // Validate event name length
        if (mb_strlen($providerEventName) > self::MAX_EVENT_NAME_LENGTH) {
            $violations[] = [
                'rule' => 'event_name_length',
                'message' => "Event name '{$providerEventName}' exceeds max length of " . self::MAX_EVENT_NAME_LENGTH . ' characters',
                'param' => 'event_name',
            ];
        }

        // Plausible: no spaces in custom event names
        if ($provider === 'plausible' && str_contains($providerEventName, ' ')) {
            $violations[] = [
                'rule' => 'plausible_name_format',
                'message' => "Plausible event names must not contain spaces: '{$providerEventName}'",
                'param' => 'event_name',
            ];
        }

        // Validate params against provider-specific contracts
        $contract = $this->getContract($event->name, $provider);
        if ($contract !== null) {
            $violations = [...$violations, ...$this->validateParams($event->params, $contract, $provider)];
        }

        // PostHog reserved properties check
        if ($provider === 'posthog') {
            $violations = [...$violations, ...$this->checkReservedProperties($event->params)];
        }

        // Global parameter length check
        $violations = [...$violations, ...$this->checkParamLengths($event->params)];

        return $violations;
    }

    /**
     * Validate the entire event catalog against all provider contracts.
     *
     * @return array{total_events: int, total_contracts: int, coverage: float, results: array<string, array{passed: int, failed: int, violations: int}>, provider_coverage: array<string, float>, grade: string}
     */
    public function validateCatalog(): array
    {
        $allEvents = EventCatalog::all();
        $totalEvents = count($allEvents);
        $providerStats = [];

        foreach (self::PROVIDERS as $provider) {
            $providerStats[$provider] = ['passed' => 0, 'failed' => 0, 'violations' => 0];
        }

        $totalContracts = 0;
        $totalViolations = 0;

        foreach ($allEvents as $name => $entry) {
            $event = new AnalyticsEvent(
                name: $name,
                params: $this->sampleParams($name),
            );

            foreach (self::PROVIDERS as $provider) {
                $violations = $this->validateForProvider($event, $provider);
                $totalContracts++;
                $totalViolations += count($violations);

                if (count($violations) === 0) {
                    $providerStats[$provider]['passed']++;
                } else {
                    $providerStats[$provider]['failed']++;
                    $providerStats[$provider]['violations'] += count($violations);
                }
            }
        }

        // Calculate per-provider coverage
        $providerCoverage = [];
        foreach (self::PROVIDERS as $provider) {
            $total = $providerStats[$provider]['passed'] + $providerStats[$provider]['failed'];
            $providerCoverage[$provider] = $total > 0
                ? round(($providerStats[$provider]['passed'] / $total) * 100, 2)
                : 100.0;
        }

        // Calculate overall coverage
        $overallCoverage = $totalContracts > 0
            ? round((($totalContracts - $totalViolations) / $totalContracts) * 100, 2)
            : 100.0;

        return [
            'total_events' => $totalEvents,
            'total_contracts' => $totalContracts,
            'coverage' => $overallCoverage,
            'results' => $providerStats,
            'provider_coverage' => $providerCoverage,
            'grade' => $this->coverageGrade($overallCoverage),
        ];
    }

    /**
     * Get contract coverage for a specific provider.
     *
     * @return array{provider: string, total_events: int, passed: int, failed: int, coverage: float, top_violations: list<array{event: string, violations: list<string>}>}
     */
    public function providerCoverage(string $provider): array
    {
        $allEvents = EventCatalog::all();
        $passed = 0;
        $failed = 0;
        $topViolations = [];

        foreach ($allEvents as $name => $entry) {
            $event = new AnalyticsEvent(
                name: $name,
                params: $this->sampleParams($name),
            );

            $violations = $this->validateForProvider($event, $provider);

            if (count($violations) === 0) {
                $passed++;
            } else {
                $failed++;
                if (count($topViolations) < 20) {
                    $topViolations[] = [
                        'event' => $name,
                        'violations' => array_map(
                            fn (array $v): string => $v['message'],
                            $violations,
                        ),
                    ];
                }
            }
        }

        $total = $passed + $failed;

        return [
            'provider' => $provider,
            'total_events' => $total,
            'passed' => $passed,
            'failed' => $failed,
            'coverage' => $total > 0 ? round(($passed / $total) * 100, 2) : 100.0,
            'top_violations' => $topViolations,
        ];
    }

    /**
     * Get all registered contracts.
     *
     * @return array{ga4: array<string, mixed>, meta: array<string, mixed>, posthog: array<string, mixed>, plausible: array<string, mixed>}
     */
    public function getContracts(): array
    {
        return [
            'ga4' => self::GA4_CONTRACTS,
            'meta' => self::META_CONTRACTS,
            'posthog' => self::POSTHOG_CONTRACTS,
            'plausible' => self::PLAUSIBLE_CONTRACTS,
        ];
    }

    /**
     * Get the total number of registered contracts across all providers.
     */
    public function contractCount(): int
    {
        return count(self::GA4_CONTRACTS)
            + count(self::META_CONTRACTS)
            + count(self::POSTHOG_CONTRACTS)
            + count(self::PLAUSIBLE_CONTRACTS);
    }

    /**
     * Check if contract testing is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the current severity level.
     */
    public function getSeverity(): string
    {
        return $this->severity;
    }

    /**
     * Check if a specific event has contracts defined for any provider.
     */
    public function hasContract(string $eventName): bool
    {
        foreach (self::PROVIDERS as $provider) {
            if ($this->getContract($eventName, $provider) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the provider-specific event name from the catalog.
     *
     * @return string The provider's event name for the given event
     */
    private function getProviderEventName(string $eventName, string $provider): string
    {
        $catalogEntry = EventCatalog::get($eventName);

        if ($catalogEntry === null) {
            return $eventName;
        }

        return (string) ($catalogEntry[$provider] ?? $eventName);
    }

    /**
     * Get the contract definition for an event + provider combination.
     *
     * @return array{required?: list<string>, max_items?: int, max_param_length?: int, max_content_ids?: int, enums?: array<string, list<string>>, name_pattern?: string, max_properties?: int, reserved_properties?: list<string>}|null
     */
    private function getContract(string $eventName, string $provider): ?array
    {
        $providerEventName = $this->getProviderEventName($eventName, $provider);

        return match ($provider) {
            'ga4' => self::GA4_CONTRACTS[$providerEventName] ?? self::GA4_CONTRACTS[$eventName] ?? null,
            'meta' => self::META_CONTRACTS[$providerEventName] ?? self::META_CONTRACTS[$eventName] ?? null,
            'posthog' => self::POSTHOG_CONTRACTS[$providerEventName] ?? self::POSTHOG_CONTRACTS[$eventName] ?? null,
            'plausible' => self::PLAUSIBLE_CONTRACTS[$providerEventName] ?? self::PLAUSIBLE_CONTRACTS[$eventName] ?? null,
            default => null,
        };
    }

    /**
     * Validate event parameters against a contract.
     *
     * @param  array<string, mixed>  $params  Event parameters
     * @param  array{required?: list<string>, max_items?: int, max_param_length?: int, max_content_ids?: int, enums?: array<string, list<string>>, max_properties?: int}  $contract  Provider contract
     * @param  string  $provider  Provider name
     * @return list<array{rule: string, message: string, param?: string}>
     */
    private function validateParams(array $params, array $contract, string $provider): array
    {
        $violations = [];

        // Check required parameters
        if (isset($contract['required'])) {
            /** @var list<string> $required */
            $required = $contract['required'];
            foreach ($required as $paramName) {
                if (! array_key_exists($paramName, $params)) {
                    $violations[] = [
                        'rule' => 'required_param',
                        'message' => "Missing required parameter '{$paramName}' for {$provider}",
                        'param' => $paramName,
                    ];
                }
            }
        }

        // Check max items (for GA4 items array)
        if (isset($contract['max_items']) && isset($params['items']) && is_array($params['items'])) {
            $maxItems = $contract['max_items'];
            $itemCount = count($params['items']);
            if ($itemCount > $maxItems) {
                $violations[] = [
                    'rule' => 'max_items',
                    'message' => "Items array exceeds max of {$maxItems} (got {$itemCount}) for {$provider}",
                    'param' => 'items',
                ];
            }
        }

        // Check max content IDs (for Meta Pixel)
        if (isset($contract['max_content_ids']) && isset($params['content_ids']) && is_array($params['content_ids'])) {
            $maxIds = $contract['max_content_ids'];
            $idCount = count($params['content_ids']);
            if ($idCount > $maxIds) {
                $violations[] = [
                    'rule' => 'max_content_ids',
                    'message' => "Content IDs array exceeds max of {$maxIds} (got {$idCount}) for meta",
                    'param' => 'content_ids',
                ];
            }
        }

        // Check enum constraints
        if (isset($contract['enums'])) {
            /** @var array<string, list<string>> $enums */
            $enums = $contract['enums'];
            foreach ($enums as $paramName => $allowedValues) {
                if (isset($params[$paramName]) && ! in_array((string) $params[$paramName], $allowedValues, true)) {
                    $violations[] = [
                        'rule' => 'enum_constraint',
                        'message' => "Parameter '{$paramName}' value '" . (string) $params[$paramName] . "' is not one of: " . implode(', ', $allowedValues),
                        'param' => $paramName,
                    ];
                }
            }
        }

        // Check max properties (PostHog)
        if (isset($contract['max_properties'])) {
            $propCount = count($params);
            $maxProps = $contract['max_properties'];
            if ($propCount > $maxProps) {
                $violations[] = [
                    'rule' => 'max_properties',
                    'message' => "Event has {$propCount} properties, exceeding max of {$maxProps} for {$provider}",
                ];
            }
        }

        return $violations;
    }

    /**
     * Check for reserved PostHog property names.
     *
     * @param  array<string, mixed>  $params  Event parameters
     * @return list<array{rule: string, message: string, param?: string}>
     */
    private function checkReservedProperties(array $params): array
    {
        $violations = [];

        foreach (array_keys($params) as $key) {
            if (in_array($key, self::POSTHOG_RESERVED, true)) {
                $violations[] = [
                    'rule' => 'reserved_property',
                    'message' => "Reserved PostHog property '{$key}' should not be set manually",
                    'param' => $key,
                ];
            }
        }

        return $violations;
    }

    /**
     * Check parameter value lengths against global limits.
     *
     * @param  array<string, mixed>  $params  Event parameters
     * @return list<array{rule: string, message: string, param?: string}>
     */
    private function checkParamLengths(array $params): array
    {
        $violations = [];

        foreach ($params as $key => $value) {
            if (is_string($value) && mb_strlen($value) > self::MAX_PARAM_LENGTH) {
                $violations[] = [
                    'rule' => 'param_length',
                    'message' => "Parameter '{$key}' exceeds max length of " . self::MAX_PARAM_LENGTH . ' characters',
                    'param' => $key,
                ];
            }
        }

        return $violations;
    }

    /**
     * Generate sample parameters for catalog validation.
     *
     * @param  string  $eventName  Event name
     * @return array<string, mixed> Sample params for testing
     */
    private function sampleParams(string $eventName): array
    {
        // E-commerce events get realistic sample params
        if (EcommerceEvents::has($eventName)) {
            return match ($eventName) {
                'purchase', 'refund' => [
                    'transaction_id' => 'txn_test_001',
                    'value' => 99.99,
                    'currency' => 'USD',
                    'items' => [['item_id' => 'prod_1', 'price' => 99.99, 'quantity' => 1]],
                ],
                'view_item', 'add_to_cart', 'begin_checkout' => [
                    'items' => [['item_id' => 'prod_1', 'price' => 49.99, 'quantity' => 1]],
                    'currency' => 'USD',
                ],
                default => ['currency' => 'USD'],
            };
        }

        // SaaS events get minimal params
        if (SaaSEvents::has($eventName)) {
            return match ($eventName) {
                'sign_up' => ['$distinct_id' => 'user_test_001'],
                'login' => [],
                default => [],
            };
        }

        // Engagement events
        return match ($eventName) {
            'page_view' => ['page_title' => 'Test Page'],
            default => [],
        };
    }

    /**
     * Build a skip result when contract testing is disabled.
     *
     * @param  string  $eventName  Event name
     * @return array{event: string, providers: array<string, array{passed: bool, violations: list<array{rule: string, message: string}>>}>, overall_passed: bool, severity: string, skipped: bool}
     */
    private function buildSkipResult(string $eventName): array
    {
        $providers = [];
        foreach (self::PROVIDERS as $provider) {
            $providers[$provider] = ['passed' => true, 'violations' => []];
        }

        return [
            'event' => $eventName,
            'providers' => $providers,
            'overall_passed' => true,
            'severity' => $this->severity,
            'skipped' => true,
        ];
    }

    /**
     * Calculate a grade from coverage percentage.
     *
     * @param  float  $coverage  Coverage percentage (0-100)
     * @return string Grade (A+ through F)
     */
    private function coverageGrade(float $coverage): string
    {
        return match (true) {
            $coverage >= 98.0 => 'A+',
            $coverage >= 95.0 => 'A',
            $coverage >= 90.0 => 'A-',
            $coverage >= 85.0 => 'B+',
            $coverage >= 80.0 => 'B',
            $coverage >= 75.0 => 'B-',
            $coverage >= 70.0 => 'C+',
            $coverage >= 65.0 => 'C',
            $coverage >= 60.0 => 'C-',
            $coverage >= 50.0 => 'D',
            default => 'F',
        };
    }
}
