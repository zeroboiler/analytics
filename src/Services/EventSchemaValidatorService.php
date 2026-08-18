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
/**
 * Cross-Provider Event Schema Validation Service.
 *
 * Validates analytics events against each provider's schema requirements
 * before dispatch. Ensures events conform to GA4's parameter naming rules,
 * Meta Pixel's content object structure, PostHog's property limits, and
 * other provider-specific constraints.
 *
 * Provides:
 * - Per-provider validation (GA4, Meta, PostHog, Plausible, etc.)
 * - Batch validation with aggregate report
 * - Event-specific schema rules (ecommerce items, SaaS lifecycle, engagement)
 * - Warnings vs. hard errors (configurable strictness)
 * - Cache-persisted validation summaries
 *
 * Inspired by Segment's Schema Validator, RudderStack's Event Schema Check,
 * and mParticle's Data Planning.
 *
 * Configuration: `zeroboiler.analytics.schema_validator`
 *
 * @see \ZeroBoiler\Analytics\Events\EventCatalog
 * @see \ZeroBoiler\Analytics\Schema\EventSchemaRegistry
 *
 * @since 189.0.0
 */
final class EventSchemaValidatorService
{
    /** @var string Cache key prefix for validation summaries */
    private const CACHE_PREFIX = 'zb_schema_validator_';

    /** @var int Default TTL for cached summaries (30 minutes) */
    private const DEFAULT_TTL = 1800;

    /** @var int GA4 max parameter value length (100 chars recommended) */
    private const GA4_MAX_PARAM_LENGTH = 100;

    /** @var int GA4 max items per ecommerce event */
    private const GA4_MAX_ITEMS = 200;

    /** @var int Meta Pixel max custom properties */
    private const META_MAX_PROPERTIES = 25;

    /** @var int PostHog max property value length (1024 chars) */
    private const POSTHOG_MAX_VALUE_LENGTH = 1024;

    /** @var int Plausible max custom props */
    private const PLAUSIBLE_MAX_PROPS = 30;

    /** @var int Mixpanel max event properties */
    private const MIXPANEL_MAX_PROPS = 255;

    /** @var string Warning severity */
    public const SEVERITY_WARNING = 'warning';

    /** @var string Error severity */
    public const SEVERITY_ERROR = 'error';

    /** @var string Info severity */
    public const SEVERITY_INFO = 'info';

    /** @var list<string> Required GA4 ecommerce item fields */
    private const GA4_REQUIRED_ITEM_FIELDS = ['item_id'];

    /** @var list<string> Recommended GA4 ecommerce item fields */
    private const GA4_RECOMMENDED_ITEM_FIELDS = ['item_name', 'price', 'currency'];

    /** @var list<string> GA4 reserved parameter names that cannot be overridden */
    private const GA4_RESERVED_PARAMS = [
        'page_location', 'page_referrer', 'page_title',
        'campaign_source', 'campaign_medium', 'campaign_name',
        'session_id', 'session_number', 'engagement_time_msec',
    ];

    private CacheRepository $cache;

    private bool $enabled;

    private int $ttl;

    private bool $strictMode;

    /** @var list<string> Providers to validate against */
    private array $activeProviders;

    /** @var int Max event name length */
    private int $maxEventNameLength;

    /** @var int Max params count per event */
    private int $maxParamsCount;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $svConfig = $config->get('zeroboiler.analytics.schema_validator', []);
        /** @var array{enabled?: bool, ttl?: int, strict_mode?: bool, providers?: list<string>, max_event_name_length?: int, max_params_count?: int} $svConfig */
        $this->enabled = (bool) ($svConfig['enabled'] ?? true);
        $this->ttl = (int) ($svConfig['ttl'] ?? self::DEFAULT_TTL);
        $this->strictMode = (bool) ($svConfig['strict_mode'] ?? false);
        $this->activeProviders = (array) ($svConfig['providers'] ?? ['ga4', 'meta', 'posthog']);
        $this->maxEventNameLength = (int) ($svConfig['max_event_name_length'] ?? 100);
        $this->maxParamsCount = (int) ($svConfig['max_params_count'] ?? 100);
    }

    /**
     * Validate an event against all active providers.
     *
     * @param  AnalyticsEvent  $event  The event to validate
     * @return array{valid: bool, issues: list<array{provider: string, severity: string, code: string, message: string, field?: string|null}>}
     */
    public function validate(AnalyticsEvent $event): array
    {
        if (! $this->enabled) {
            return ['valid' => true, 'issues' => []];
        }

        $issues = [];

        foreach ($this->activeProviders as $provider) {
            $providerIssues = $this->validateForProvider($event, $provider);
            array_push($issues, ...$providerIssues);
        }

        return [
            'valid' => $this->isStrictPass($issues),
            'issues' => $issues,
        ];
    }

    /**
     * Validate a batch of events and return an aggregate report.
     *
     * @param  list<AnalyticsEvent>  $events
     * @return array{total: int, valid: int, invalid: int, warnings: int, errors: int, by_provider: array<string, int>, issues: list<array{event_name: string, provider: string, severity: string, code: string, message: string}>}
     */
    public function validateBatch(array $events): array
    {
        $allIssues = [];
        $validCount = 0;
        $errorCount = 0;
        $warningCount = 0;
        $providerCounts = array_fill_keys($this->activeProviders, 0);

        foreach ($events as $event) {
            $result = $this->validate($event);

            if ($result['valid']) {
                $validCount++;
            } else {
                $errorCount++;
            }

            foreach ($result['issues'] as $issue) {
                $allIssues[] = array_merge($issue, ['event_name' => $event->name]);

                if (! isset($providerCounts[$issue['provider']])) {
                    $providerCounts[$issue['provider']] = 0;
                }
                $providerCounts[$issue['provider']]++;

                if ($issue['severity'] === self::SEVERITY_ERROR) {
                    $errorCount++;
                } elseif ($issue['severity'] === self::SEVERITY_WARNING) {
                    $warningCount++;
                }
            }
        }

        return [
            'total' => count($events),
            'valid' => $validCount,
            'invalid' => count($events) - $validCount,
            'warnings' => $warningCount,
            'errors' => $errorCount,
            'by_provider' => $providerCounts,
            'issues' => $allIssues,
        ];
    }

    /**
     * Validate an event for a specific provider.
     *
     * @param  AnalyticsEvent  $event
     * @param  string  $provider  Provider identifier (ga4, meta, posthog, plausible, mixpanel)
     * @return list<array{provider: string, severity: string, code: string, message: string, field?: string|null}>
     */
    public function validateForProvider(AnalyticsEvent $event, string $provider): array
    {
        if (! $this->enabled) {
            return [];
        }

        return match ($provider) {
            'ga4' => $this->validateForGA4($event),
            'meta' => $this->validateForMeta($event),
            'posthog' => $this->validateForPostHog($event),
            'plausible' => $this->validateForPlausible($event),
            'mixpanel' => $this->validateForMixpanel($event),
            'amplitude' => $this->validateForAmplitude($event),
            default => [],
        };
    }

    /**
     * Check if the schema validator is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if strict mode is enabled.
     */
    public function isStrictMode(): bool
    {
        return $this->strictMode;
    }

    /**
     * Get active provider list.
     *
     * @return list<string>
     */
    public function getActiveProviders(): array
    {
        return $this->activeProviders;
    }

    /**
     * Get provider-specific schema rules documentation.
     *
     * Useful for admin dashboards and developer tooling.
     *
     * @return array<string, array{max_params: int, max_event_name_length: int, reserved_params: list<string>, required_ecommerce_fields: list<string>, notes: string}>
     */
    public function providerRules(): array
    {
        return [
            'ga4' => [
                'max_params' => 50,
                'max_event_name_length' => 40,
                'reserved_params' => self::GA4_RESERVED_PARAMS,
                'required_ecommerce_fields' => self::GA4_REQUIRED_ITEM_FIELDS,
                'notes' => 'Event names must be 40 chars max. Item arrays limited to 200 items.',
            ],
            'meta' => [
                'max_params' => self::META_MAX_PROPERTIES,
                'max_event_name_length' => 100,
                'reserved_params' => [],
                'required_ecommerce_fields' => ['content_ids', 'content_type', 'value', 'currency'],
                'notes' => 'Standard events enforce content_type and value. Max 25 custom properties.',
            ],
            'posthog' => [
                'max_params' => 100,
                'max_event_name_length' => 100,
                'reserved_params' => ['$distinct_id', '$set', '$set_once'],
                'required_ecommerce_fields' => [],
                'notes' => 'Property values limited to 1024 chars. Reserved $-prefixed keys.',
            ],
            'plausible' => [
                'max_params' => self::PLAUSIBLE_MAX_PROPS,
                'max_event_name_length' => 100,
                'reserved_params' => [],
                'required_ecommerce_fields' => [],
                'notes' => 'Custom props limited to 30. URL and referrer auto-attached.',
            ],
            'mixpanel' => [
                'max_params' => self::MIXPANEL_MAX_PROPS,
                'max_event_name_length' => 255,
                'reserved_params' => ['distinct_id', 'time', 'token'],
                'required_ecommerce_fields' => [],
                'notes' => 'Max 255 properties. String values must not exceed 65535 chars.',
            ],
            'amplitude' => [
                'max_params' => 100,
                'max_event_name_length' => 1000,
                'reserved_params' => ['device_id', 'user_id', 'session_id', 'time'],
                'required_ecommerce_fields' => [],
                'notes' => 'Event type (name) up to 1000 chars. Revenue tracked via revenue_type.',
            ],
        ];
    }

    /**
     * Get a quick summary of recent validation results from cache.
     *
     * @return array{total_validated: int, error_rate: float, warning_rate: float, top_issues: list<array{code: string, count: int}>, last_run: string|null}
     */
    public function summary(): array
    {
        $cached = $this->cache->get(self::CACHE_PREFIX . 'summary');

        if (is_array($cached)) {
            return $cached;
        }

        return [
            'total_validated' => 0,
            'error_rate' => 0.0,
            'warning_rate' => 0.0,
            'top_issues' => [],
            'last_run' => null,
        ];
    }

    /**
     * Clear cached validation summaries.
     */
    public function clearCache(): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'summary');
    }

    /**
     * Get validation statistics.
     *
     * @return array{enabled: bool, strict_mode: bool, providers: list<string>, max_event_name_length: int, max_params_count: int, provider_rules_count: int}
     */
    public function stats(): array
    {
        return [
            'enabled' => $this->enabled,
            'strict_mode' => $this->strictMode,
            'providers' => $this->activeProviders,
            'max_event_name_length' => $this->maxEventNameLength,
            'max_params_count' => $this->maxParamsCount,
            'provider_rules_count' => count($this->providerRules()),
        ];
    }

    /**
     * Validate an event against GA4 schema rules.
     *
     * Checks: event name length, reserved parameter usage, ecommerce item structure,
     * parameter value length, max params count.
     *
     * @param  AnalyticsEvent  $event
     * @return list<array{provider: string, severity: string, code: string, message: string, field?: string|null}>
     */
    private function validateForGA4(AnalyticsEvent $event): array
    {
        $issues = [];
        $severity = $this->strictMode ? self::SEVERITY_ERROR : self::SEVERITY_WARNING;

        // Event name length (GA4 recommends 40 chars max)
        if (strlen($event->name) > 40) {
            $issues[] = [
                'provider' => 'ga4',
                'severity' => $severity,
                'code' => 'GA4_EVENT_NAME_TOO_LONG',
                'message' => sprintf('GA4 event name "%s" exceeds 40 character limit (%d chars)', $event->name, strlen($event->name)),
                'field' => 'name',
            ];
        }

        // Reserved parameter usage
        foreach (self::GA4_RESERVED_PARAMS as $reserved) {
            if (array_key_exists($reserved, $event->params)) {
                $issues[] = [
                    'provider' => 'ga4',
                    'severity' => self::SEVERITY_WARNING,
                    'code' => 'GA4_RESERVED_PARAM',
                    'message' => sprintf('GA4 reserved parameter "%s" should not be set manually', $reserved),
                    'field' => $reserved,
                ];
            }
        }

        // Parameter value length
        foreach ($event->params as $key => $value) {
            if (is_string($value) && strlen($value) > self::GA4_MAX_PARAM_LENGTH) {
                $issues[] = [
                    'provider' => 'ga4',
                    'severity' => self::SEVERITY_WARNING,
                    'code' => 'GA4_PARAM_VALUE_TOO_LONG',
                    'message' => sprintf('Parameter "%s" value exceeds %d characters (GA4 recommended max)', $key, self::GA4_MAX_PARAM_LENGTH),
                    'field' => $key,
                ];
            }
        }

        // Ecommerce item validation
        if ($this->isEcommerceEvent($event) && isset($event->params['items']) && is_array($event->params['items'])) {
            $itemsCount = count($event->params['items']);

            if ($itemsCount > self::GA4_MAX_ITEMS) {
                $issues[] = [
                    'provider' => 'ga4',
                    'severity' => self::SEVERITY_ERROR,
                    'code' => 'GA4_TOO_MANY_ITEMS',
                    'message' => sprintf('GA4 ecommerce events support max %d items, got %d', self::GA4_MAX_ITEMS, $itemsCount),
                    'field' => 'items',
                ];
            }

            foreach ($event->params['items'] as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                // Required fields
                foreach (self::GA4_REQUIRED_ITEM_FIELDS as $requiredField) {
                    if (! isset($item[$requiredField])) {
                        $issues[] = [
                            'provider' => 'ga4',
                            'severity' => $severity,
                            'code' => 'GA4_MISSING_ITEM_FIELD',
                            'message' => sprintf('GA4 item[%d] missing required field "%s"', $index, $requiredField),
                            'field' => "items.{$index}.{$requiredField}",
                        ];
                    }
                }

                // Recommended fields
                foreach (self::GA4_RECOMMENDED_ITEM_FIELDS as $recField) {
                    if (! isset($item[$recField])) {
                        $issues[] = [
                            'provider' => 'ga4',
                            'severity' => self::SEVERITY_INFO,
                            'code' => 'GA4_MISSING_RECOMMENDED_FIELD',
                            'message' => sprintf('GA4 item[%d] missing recommended field "%s" for better reporting', $index, $recField),
                            'field' => "items.{$index}.{$recField}",
                        ];
                    }
                }
            }
        }

        // Max params count
        if (count($event->params) > $this->maxParamsCount) {
            $issues[] = [
                'provider' => 'ga4',
                'severity' => $severity,
                'code' => 'GA4_TOO_MANY_PARAMS',
                'message' => sprintf('Event has %d params, exceeds max of %d', count($event->params), $this->maxParamsCount),
            ];
        }

        return $issues;
    }

    /**
     * Validate an event against Meta Pixel schema rules.
     *
     * Checks: property count, content object structure, value/currency for standard events.
     *
     * @param  AnalyticsEvent  $event
     * @return list<array{provider: string, severity: string, code: string, message: string, field?: string|null}>
     */
    private function validateForMeta(AnalyticsEvent $event): array
    {
        $issues = [];
        $severity = $this->strictMode ? self::SEVERITY_ERROR : self::SEVERITY_WARNING;

        // Property count limit
        if (count($event->params) > self::META_MAX_PROPERTIES) {
            $issues[] = [
                'provider' => 'meta',
                'severity' => $severity,
                'code' => 'META_TOO_MANY_PROPERTIES',
                'message' => sprintf('Meta Pixel supports max %d custom properties, got %d', self::META_MAX_PROPERTIES, count($event->params)),
            ];
        }

        // Ecommerce events: content_type and content_ids recommended
        if ($this->isEcommerceEvent($event)) {
            if (! isset($event->params['content_ids']) && ! isset($event->params['content_name'])) {
                $issues[] = [
                    'provider' => 'meta',
                    'severity' => self::SEVERITY_WARNING,
                    'code' => 'META_MISSING_CONTENT_IDS',
                    'message' => 'Meta Pixel ecommerce events should include content_ids or content_name',
                    'field' => 'content_ids',
                ];
            }

            if (! isset($event->params['content_type'])) {
                $issues[] = [
                    'provider' => 'meta',
                    'severity' => self::SEVERITY_INFO,
                    'code' => 'META_MISSING_CONTENT_TYPE',
                    'message' => 'Meta Pixel ecommerce events benefit from content_type (product, product_group)',
                    'field' => 'content_type',
                ];
            }
        }

        // Purchase/subscription events should have value and currency
        if (in_array($event->name, ['purchase', 'subscription_created', 'subscribe', 'payment_succeeded'], true)) {
            if (! isset($event->params['value'])) {
                $issues[] = [
                    'provider' => 'meta',
                    'severity' => $severity,
                    'code' => 'META_MISSING_VALUE',
                    'message' => 'Meta Pixel revenue events require "value" parameter',
                    'field' => 'value',
                ];
            }

            if (! isset($event->params['currency'])) {
                $issues[] = [
                    'provider' => 'meta',
                    'severity' => self::SEVERITY_WARNING,
                    'code' => 'META_MISSING_CURRENCY',
                    'message' => 'Meta Pixel revenue events should include "currency" (e.g. USD)',
                    'field' => 'currency',
                ];
            }
        }

        return $issues;
    }

    /**
     * Validate an event against PostHog schema rules.
     *
     * Checks: property value length, reserved $-prefixed keys, event name format.
     *
     * @param  AnalyticsEvent  $event
     * @return list<array{provider: string, severity: string, code: string, message: string, field?: string|null}>
     */
    private function validateForPostHog(AnalyticsEvent $event): array
    {
        $issues = [];
        $severity = $this->strictMode ? self::SEVERITY_ERROR : self::SEVERITY_WARNING;

        // Property value length
        foreach ($event->params as $key => $value) {
            if (is_string($value) && strlen($value) > self::POSTHOG_MAX_VALUE_LENGTH) {
                $issues[] = [
                    'provider' => 'posthog',
                    'severity' => $severity,
                    'code' => 'POSTHOG_VALUE_TOO_LONG',
                    'message' => sprintf('Property "%s" value exceeds PostHog %d char limit', $key, self::POSTHOG_MAX_VALUE_LENGTH),
                    'field' => $key,
                ];
            }
        }

        // Reserved $-prefixed keys
        $reserved = ['$distinct_id', '$set', '$set_once', '$current_url', '$pathname'];
        foreach ($reserved as $key) {
            if (array_key_exists($key, $event->params)) {
                $issues[] = [
                    'provider' => 'posthog',
                    'severity' => self::SEVERITY_WARNING,
                    'code' => 'POSTHOG_RESERVED_KEY',
                    'message' => sprintf('PostHog reserved key "%s" should not be included in event properties', $key),
                    'field' => $key,
                ];
            }
        }

        // Event name with leading $ should be PostHog's own
        if (str_starts_with($event->name, '$') && ! in_array($event->name, ['$pageview', '$identify', '$autocapture'], true)) {
            $issues[] = [
                'provider' => 'posthog',
                'severity' => self::SEVERITY_WARNING,
                'code' => 'POSTHOG_RESERVED_EVENT_NAME',
                'message' => sprintf('Event name "%s" starts with $, which is reserved for PostHog system events', $event->name),
                'field' => 'name',
            ];
        }

        return $issues;
    }

    /**
     * Validate an event against Plausible schema rules.
     *
     * Plausible is minimal: mainly checks custom prop count and event name format.
     *
     * @param  AnalyticsEvent  $event
     * @return list<array{provider: string, severity: string, code: string, message: string, field?: string|null}>
     */
    private function validateForPlausible(AnalyticsEvent $event): array
    {
        $issues = [];

        // Custom prop count
        if (count($event->params) > self::PLAUSIBLE_MAX_PROPS) {
            $issues[] = [
                'provider' => 'plausible',
                'severity' => self::SEVERITY_WARNING,
                'code' => 'PLAUSIBLE_TOO_MANY_PROPS',
                'message' => sprintf('Plausible supports max %d custom properties', self::PLAUSIBLE_MAX_PROPS),
            ];
        }

        // Event name should be snake_case or lowercase
        if ($event->name !== strtolower($event->name) && ! str_contains($event->name, ' ')) {
            $issues[] = [
                'provider' => 'plausible',
                'severity' => self::SEVERITY_INFO,
                'code' => 'PLAUSIBLE_EVENT_NAME_FORMAT',
                'message' => 'Plausible recommends lowercase event names for consistency',
                'field' => 'name',
            ];
        }

        return $issues;
    }

    /**
     * Validate an event against Mixpanel schema rules.
     *
     * Checks: property count, string value limits, reserved keys.
     *
     * @param  AnalyticsEvent  $event
     * @return list<array{provider: string, severity: string, code: string, message: string, field?: string|null}>
     */
    private function validateForMixpanel(AnalyticsEvent $event): array
    {
        $issues = [];

        // Property count
        if (count($event->params) > self::MIXPANEL_MAX_PROPS) {
            $issues[] = [
                'provider' => 'mixpanel',
                'severity' => self::SEVERITY_ERROR,
                'code' => 'MIXPANEL_TOO_MANY_PROPS',
                'message' => sprintf('Mixpanel supports max %d event properties', self::MIXPANEL_MAX_PROPS),
            ];
        }

        // String value length (65535 chars)
        foreach ($event->params as $key => $value) {
            if (is_string($value) && strlen($value) > 65535) {
                $issues[] = [
                    'provider' => 'mixpanel',
                    'severity' => self::SEVERITY_ERROR,
                    'code' => 'MIXPANEL_VALUE_TOO_LONG',
                    'message' => sprintf('Property "%s" string value exceeds Mixpanel 65535 char limit', $key),
                    'field' => $key,
                ];
            }
        }

        // Reserved keys
        $reserved = ['distinct_id', 'time', 'token', 'mp_lib', '$device_id'];
        foreach ($reserved as $key) {
            if (array_key_exists($key, $event->params)) {
                $issues[] = [
                    'provider' => 'mixpanel',
                    'severity' => self::SEVERITY_WARNING,
                    'code' => 'MIXPANEL_RESERVED_KEY',
                    'message' => sprintf('Mixpanel reserved key "%s" should not be set manually', $key),
                    'field' => $key,
                ];
            }
        }

        return $issues;
    }

    /**
     * Validate an event against Amplitude schema rules.
     *
     * Checks: event type length, reserved user/session properties.
     *
     * @param  AnalyticsEvent  $event
     * @return list<array{provider: string, severity: string, code: string, message: string, field?: string|null}>
     */
    private function validateForAmplitude(AnalyticsEvent $event): array
    {
        $issues = [];

        // Event type length (1000 chars)
        if (strlen($event->name) > 1000) {
            $issues[] = [
                'provider' => 'amplitude',
                'severity' => self::SEVERITY_ERROR,
                'code' => 'AMPLITUDE_EVENT_NAME_TOO_LONG',
                'message' => sprintf('Amplitude event type exceeds 1000 character limit', $event->name),
                'field' => 'name',
            ];
        }

        // Reserved properties
        $reserved = ['device_id', 'user_id', 'session_id', 'time', 'event_type', 'platform'];
        foreach ($reserved as $key) {
            if (array_key_exists($key, $event->params)) {
                $issues[] = [
                    'provider' => 'amplitude',
                    'severity' => self::SEVERITY_WARNING,
                    'code' => 'AMPLITUDE_RESERVED_KEY',
                    'message' => sprintf('Amplitude reserved key "%s" should not be in event properties', $key),
                    'field' => $key,
                ];
            }
        }

        return $issues;
    }

    /**
     * Check if an event is an ecommerce event.
     *
     * @param  AnalyticsEvent  $event
     */
    private function isEcommerceEvent(AnalyticsEvent $event): bool
    {
        return $event->category === 'ecommerce' || EcommerceEvents::has($event->name);
    }

    /**
     * Determine if the event passes validation based on strict mode.
     *
     * In strict mode, any issue (including warnings) causes failure.
     * In lenient mode, only ERROR-severity issues cause failure.
     *
     * @param  list<array{severity: string}>  $issues
     */
    private function isStrictPass(array $issues): bool
    {
        if ($this->strictMode) {
            return $issues === [];
        }

        foreach ($issues as $issue) {
            if ($issue['severity'] === self::SEVERITY_ERROR) {
                return false;
            }
        }

        return true;
    }
}
