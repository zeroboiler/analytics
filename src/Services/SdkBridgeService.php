<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Support\EngagementFormatConverter;
use ZeroBoiler\Analytics\Support\SaaSFormatConverter;

/**
 * Analytics SDK Bridge Service — bidirectional event format translation.
 *
 * Enables seamless migration from third-party analytics SDKs (PostHog,
 * Mixpanel, Segment, Amplitude, Plausible) to ZeroBoiler Analytics by
 * providing automatic event name and parameter mapping in both directions.
 *
 * Use cases:
 * - **Migration bridge**: Accept events in third-party format, translate to ZeroBoiler
 * - **Dual-dispatch**: Send ZeroBoiler events to third-party format for parallel tracking
 * - **Compatibility check**: Validate that all tracked events have equivalents in target SDK
 * - **Format inspection**: Inspect how events will be translated before committing
 *
 * @since 166.0.0
 */
final class SdkBridgeService
{
    /** @var array<string, array<string, string>> Third-party SDK → ZeroBoiler event name mappings */
    private array $inboundMaps = [];

    /** @var array<string, array<string, string>> ZeroBoiler → Third-party SDK event name mappings */
    private array $outboundMaps = [];

    /** @var array<string, callable(string, array<string, mixed>): array<string, mixed>> Parameter transformers (inbound) */
    private array $inboundParamTransformers = [];

    /** @var array<string, callable(string, array<string, mixed>): array<string, mixed>> Parameter transformers (outbound) */
    private array $outboundParamTransformers = [];

    /** @var list<string> Supported SDK identifiers */
    private const SUPPORTED_SDKS = [
        'posthog',
        'mixpanel',
        'segment',
        'amplitude',
        'plausible',
        'ga4',
        'meta',
        'tiktok',
        'linkedin',
    ];

    /**
     * Initialize built-in event name mappings for all supported SDKs.
     */
    public function __construct(){
        $this->registerBuiltinMappings();
    }

    /**
     * Get list of supported SDK identifiers.
     *
     * @return list<string>
     */
    public function supportedSdks(): array
    {
        return self::SUPPORTED_SDKS;
    }

    /**
     * Check if a given SDK is supported.
     */
    public function supportsSdk(string $sdk): bool
    {
        return in_array(strtolower($sdk), self::SUPPORTED_SDKS, true);
    }

    /**
     * Translate an inbound event from a third-party SDK to ZeroBoiler format.
     *
     * @param  string  $sdk  Third-party SDK identifier (e.g., 'posthog', 'mixpanel')
     * @param  string  $eventName  Event name in the third-party SDK's format
     * @param  array<string, mixed>  $params  Event parameters in the third-party SDK's format
     * @return AnalyticsEvent Translated ZeroBoiler analytics event
     *
     * @throws \InvalidArgumentException If SDK is not supported
     */
    public function translateInbound(string $sdk, string $eventName, array $params = []): AnalyticsEvent
    {
        $sdk = strtolower($sdk);
        $this->assertSupportedSdk($sdk);

        $zbName = $this->resolveInboundEventName($sdk, $eventName);
        $zbParams = $this->transformInboundParams($sdk, $zbName, $eventName, $params);

        return new AnalyticsEvent(
            name: $zbName,
            params: $zbParams,
            source: 'sdk_bridge',
        );
    }

    /**
     * Translate a ZeroBoiler event to a third-party SDK format.
     *
     * @param  string  $sdk  Target SDK identifier
     * @param  AnalyticsEvent  $event  ZeroBoiler analytics event
     * @return array{event: string, properties: array<string, mixed>} Translated event in target SDK format
     */
    public function translateOutbound(string $sdk, AnalyticsEvent $event): array
    {
        $sdk = strtolower($sdk);
        $this->assertSupportedSdk($sdk);

        $sdkName = $this->resolveOutboundEventName($sdk, $event->name);
        $sdkParams = $this->transformOutboundParams($sdk, $event->name, $sdkName, $event->params);

        return [
            'event' => $sdkName,
            'properties' => $sdkParams,
        ];
    }

    /**
     * Check compatibility between ZeroBoiler events and a target SDK.
     *
     * Returns a report listing events that have mappings, events that lack
     * mappings, and events that may have parameter format differences.
     *
     * @param  string  $sdk  Target SDK to check against
     * @return array{total: int, mapped: int, unmapped: int, unmapped_events: list<string>, mapped_events: list<string>, warnings: list<string>}
     */
    public function compatibilityReport(string $sdk): array
    {
        $sdk = strtolower($sdk);
        $this->assertSupportedSdk($sdk);

        $catalog = EventCatalog::all();
        $mapped = [];
        $unmapped = [];
        $warnings = [];

        foreach ($catalog as $name => $entry) {
            if ($this->hasOutboundMapping($sdk, $name)) {
                $mapped[] = $name;
            } else {
                $unmapped[] = $name;
                // Check if there's a catalog-based fallback
                if (isset($entry['ga4']) || isset($entry['meta'])) {
                    $warnings[] = "Event '{$name}' has no explicit {$sdk} mapping but has provider catalog entries (ga4/meta).";
                }
            }
        }

        return [
            'total' => count($catalog),
            'mapped' => count($mapped),
            'unmapped' => count($unmapped),
            'unmapped_events' => $unmapped,
            'mapped_events' => $mapped,
            'warnings' => $warnings,
        ];
    }

    /**
     * Get mapping coverage statistics for a target SDK.
     *
     * @param  string  $sdk  Target SDK
     * @return array{coverage_percent: float, total: int, mapped: int, by_category: array<string, array{total: int, mapped: int, percent: float}>}
     */
    public function mappingCoverage(string $sdk): array
    {
        $sdk = strtolower($sdk);
        $this->assertSupportedSdk($sdk);

        $catalog = EventCatalog::byCategory();
        $totalMapped = 0;
        $totalAll = 0;
        $byCategory = [];

        foreach ($catalog as $category => $events) {
            $catTotal = count($events);
            $catMapped = 0;
            foreach ($events as $name => $_) {
                if ($this->hasOutboundMapping($sdk, $name)) {
                    $catMapped++;
                    $totalMapped++;
                }
            }
            $totalAll += $catTotal;
            $byCategory[$category] = [
                'total' => $catTotal,
                'mapped' => $catMapped,
                'percent' => $catTotal > 0 ? round(($catMapped / $catTotal) * 100, 1) : 0.0,
            ];
        }

        return [
            'coverage_percent' => $totalAll > 0 ? round(($totalMapped / $totalAll) * 100, 1) : 0.0,
            'total' => $totalAll,
            'mapped' => $totalMapped,
            'by_category' => $byCategory,
        ];
    }

    /**
     * Register a custom inbound event name mapping.
     *
     * @param  string  $sdk  Source SDK identifier
     * @param  string  $sdkEventName  Event name in the source SDK
     * @param  string  $zbEventName  Target ZeroBoiler event name
     */
    public function registerInboundMapping(string $sdk, string $sdkEventName, string $zbEventName): void
    {
        $sdk = strtolower($sdk);
        $this->inboundMaps[$sdk][$sdkEventName] = $zbEventName;
    }

    /**
     * Register a custom outbound event name mapping.
     *
     * @param  string  $sdk  Target SDK identifier
     * @param  string  $zbEventName  ZeroBoiler event name
     * @param  string  $sdkEventName  Event name in the target SDK
     */
    public function registerOutboundMapping(string $sdk, string $zbEventName, string $sdkEventName): void
    {
        $sdk = strtolower($sdk);
        $this->outboundMaps[$sdk][$zbEventName] = $sdkEventName;
    }

    /**
     * Register a custom inbound parameter transformer.
     *
     * @param  string  $sdk  Source SDK identifier
     * @param  callable(string, array<string, mixed>): array<string, mixed>  $transformer
     */
    public function registerInboundParamTransformer(string $sdk, callable $transformer): void
    {
        $this->inboundParamTransformers[strtolower($sdk)] = $transformer;
    }

    /**
     * Register a custom outbound parameter transformer.
     *
     * @param  string  $sdk  Target SDK identifier
     * @param  callable(string, array<string, mixed>): array<string, mixed>  $transformer
     */
    public function registerOutboundParamTransformer(string $sdk, callable $transformer): void
    {
        $this->outboundParamTransformers[strtolower($sdk)] = $transformer;
    }

    /**
     * Get the inbound event name mapping table for a given SDK.
     *
     * @param  string  $sdk
     * @return array<string, string>
     */
    public function getInboundMap(string $sdk): array
    {
        return $this->inboundMaps[strtolower($sdk)] ?? [];
    }

    /**
     * Get the outbound event name mapping table for a given SDK.
     *
     * @param  string  $sdk
     * @return array<string, string>
     */
    public function getOutboundMap(string $sdk): array
    {
        return $this->outboundMaps[strtolower($sdk)] ?? [];
    }

    /**
     * Inspect how a specific event will be translated for a target SDK.
     *
     * @param  string  $sdk  Target SDK
     * @param  string  $eventName  ZeroBoiler event name
     * @param  array<string, mixed>  $params  Event parameters
     * @return array{original: string, translated: string|null, params_original: array<string, mixed>, params_translated: array<string, mixed>, has_mapping: bool, source: string}
     */
    public function inspectTranslation(string $sdk, string $eventName, array $params = []): array
    {
        $sdk = strtolower($sdk);
        $this->assertSupportedSdk($sdk);

        $translatedName = $this->resolveOutboundEventName($sdk, $eventName);
        $translatedParams = $this->transformOutboundParams($sdk, $eventName, $translatedName, $params);

        $hasMapping = $this->hasOutboundMapping($sdk, $eventName);
        $source = $hasMapping ? 'explicit_mapping' : 'catalog_fallback';

        return [
            'original' => $eventName,
            'translated' => $translatedName,
            'params_original' => $params,
            'params_translated' => $translatedParams,
            'has_mapping' => $hasMapping,
            'source' => $source,
        ];
    }

    /**
     * Resolve inbound event name from third-party SDK to ZeroBoiler.
     *
     * Uses explicit mapping first, then tries catalog aliases, then passes through.
     */
    private function resolveInboundEventName(string $sdk, string $sdkEventName): string
    {
        // Explicit mapping
        if (isset($this->inboundMaps[$sdk][$sdkEventName])) {
            return $this->inboundMaps[$sdk][$sdkEventName];
        }

        $resolved = EventCatalog::resolve($sdkEventName);
        if ($resolved !== null) {
            return $resolved;
        }

        // Pass through — treat as custom event
        return $sdkEventName;
    }

    /**
     * Resolve outbound event name from ZeroBoiler to third-party SDK.
     *
     * Uses explicit mapping first, then falls back to catalog provider entries.
     */
    private function resolveOutboundEventName(string $sdk, string $zbEventName): string
    {
        // Explicit mapping
        if (isset($this->outboundMaps[$sdk][$zbEventName])) {
            return $this->outboundMaps[$sdk][$zbEventName];
        }

        // Catalog fallback — use provider-specific name from EventCatalog
        $catalog = EventCatalog::all();
        if (isset($catalog[$zbEventName])) {
            $entry = $catalog[$zbEventName];
            // Map SDK identifier to catalog field name
            $field = $this->sdkToCatalogField($sdk);
            if ($field !== null && isset($entry[$field]) && $entry[$field] !== null) {
                return $entry[$field];
            }
        }

        // Pass through
        return $zbEventName;
    }

    /**
     * Transform inbound parameters from third-party SDK format to ZeroBoiler.
     */
    private function transformInboundParams(string $sdk, string $zbName, string $sdkName, array $params): array
    {
        if (isset($this->inboundParamTransformers[$sdk])) {
            $params = ($this->inboundParamTransformers[$sdk])($sdkName, $params);
        }

        // Strip SDK-specific metadata fields
        $params = $this->stripSdkMetadata($sdk, $params);

        return $params;
    }

    /**
     * Transform outbound parameters from ZeroBoiler to third-party SDK format.
     */
    private function transformOutboundParams(string $sdk, string $zbName, string $sdkName, array $params): array
    {
        if (isset($this->outboundParamTransformers[$sdk])) {
            $params = ($this->outboundParamTransformers[$sdk])($zbName, $params);
        }

        $params = $this->applyFormatConverter($sdk, $zbName, $params);

        return $params;
    }

    /**
     * Apply the appropriate format converter for outbound translation.
     *
     * Checks SaaS events first, then Engagement events. Format converters
     * only apply when they have a mapping for the given event name and
     * return a non-empty result. Otherwise params are passed through.
     */
    private function applyFormatConverter(string $sdk, string $eventName, array $params): array
    {
        // SaaS lifecycle events (sign_up, login, start_trial, etc.)
        if (SaaSFormatConverter::supports($eventName)) {
            $result = SaaSFormatConverter::convertForProvider($eventName, $params, $sdk);
            if ($result !== []) {
                return $result;
            }
        }

        // Engagement events (page_view, click, form_submit, etc.)
        try {
            $result = EngagementFormatConverter::convertForProvider($eventName, $params, $sdk);
            if ($result !== []) {
                return $result;
            }
        } catch (\InvalidArgumentException $e) {
            // Not an engagement event — continue to passthrough
        }

        return $params;
    }

    /**
     * Strip SDK-specific metadata fields that don't belong in ZeroBoiler events.
     *
     * @param  string  $sdk
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function stripSdkMetadata(string $sdk, array $params): array
    {
        // PostHog-specific metadata
        if ($sdk === 'posthog') {
            unset($params['$set'], $params['$set_once'], $params['$unset']);
            unset($params['$current_url'], $params['$referrer'], $params['$referring_domain']);
            unset($params['$device'], $params['$os'], $params['$browser']);
            unset($params['$screen_width'], $params['$screen_height']);
            unset($params['$lib'], $params['$lib_version']);
        }

        // Mixpanel-specific metadata
        if ($sdk === 'mixpanel') {
            unset($params['mp_lib'], $params['mp_device_id']);
        }

        // Segment-specific metadata
        if ($sdk === 'segment') {
            unset($params['context'], $params['integrations']);
            unset($params['messageId'], $params['anonymousId']);
        }

        // Amplitude-specific metadata
        if ($sdk === 'amplitude') {
            unset($params['device_id'], $params['session_id']);
            unset($params['app_version'], $params['os_name'], $params['os_version']);
            unset($params['device_brand'], $params['device_model']);
            unset($params['library']);
        }

        return $params;
    }

    /**
     * Check if an explicit outbound mapping exists for an event.
     */
    private function hasOutboundMapping(string $sdk, string $zbEventName): bool
    {
        if (isset($this->outboundMaps[$sdk][$zbEventName])) {
            return true;
        }

        // Check catalog fallback
        $catalog = EventCatalog::all();
        if (isset($catalog[$zbEventName])) {
            $entry = $catalog[$zbEventName];
            $field = $this->sdkToCatalogField($sdk);
            if ($field !== null && isset($entry[$field]) && $entry[$field] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map SDK identifier to EventCatalog field name.
     *
     * @return string|null Catalog field name or null if no direct mapping
     */
    private function sdkToCatalogField(string $sdk): ?string
    {
        return match ($sdk) {
            'ga4' => 'ga4',
            'meta' => 'meta',
            'posthog' => null, // No dedicated field — uses provider event mapper
            'mixpanel' => null,
            'segment' => null,
            'amplitude' => null,
            'plausible' => null,
            'tiktok' => null,
            'linkedin' => null,
            default => null,
        };
    }

    /**
     * Assert that a given SDK is supported.
     *
     * @throws \InvalidArgumentException
     */
    private function assertSupportedSdk(string $sdk): void
    {
        if (! $this->supportsSdk($sdk)) {
            throw new \InvalidArgumentException(
                "Unsupported SDK: '{$sdk}'. Supported: " . implode(', ', self::SUPPORTED_SDKS),
            );
        }
    }

    /**
     * Register built-in event name mappings for all supported SDKs.
     *
     * Populates inbound and outbound maps with common event name translations
     * between third-party SDKs and ZeroBoiler format.
     */
    private function registerBuiltinMappings(): void
    {
        // ── PostHog Inbound ──────────────────────────────────────────
        $posthogIn = [
            '$pageview' => 'page_view',
            '$screen' => 'screen_view',
            '$identify' => 'identify',
            '$set' => 'user_properties_set',
            '$autocapture' => 'click',
            '$snapshot' => 'session_replay',
            'user_signed_up' => 'sign_up',
            'user_login' => 'login',
            'user_logout' => 'logout',
            'subscription_created' => 'subscription',
            'payment_added' => 'add_payment_info',
        ];
        foreach ($posthogIn as $from => $to) {
            $this->inboundMaps['posthog'][$from] = $to;
        }

        // ── Mixpanel Inbound ────────────────────────────────────────
        $mixpanelIn = [
            '$create_alias' => 'alias',
            'Signup' => 'sign_up',
            'Login' => 'login',
            'Logout' => 'logout',
            'Plan Upgraded' => 'plan_upgrade',
            'Subscription Started' => 'subscription',
            'Trial Started' => 'trial_start',
            'Payment Info Added' => 'add_payment_info',
            'Purchase' => 'purchase',
        ];
        foreach ($mixpanelIn as $from => $to) {
            $this->inboundMaps['mixpanel'][$from] = $to;
        }

        // ── Segment Inbound ──────────────────────────────────────────
        $segmentIn = [
            'page' => 'page_view',
            'screen' => 'screen_view',
            'identify' => 'identify',
            'track' => 'custom_event',
            'group' => 'group_identify',
            'alias' => 'alias',
            'Signed Up' => 'sign_up',
            'Logged In' => 'login',
            'Subscription Started' => 'subscription',
            'Order Completed' => 'purchase',
            'Product Viewed' => 'view_item',
            'Product Added' => 'add_to_cart',
            'Checkout Started' => 'begin_checkout',
            'Payment Info Entered' => 'add_payment_info',
        ];
        foreach ($segmentIn as $from => $to) {
            $this->inboundMaps['segment'][$from] = $to;
        }

        // ── Amplitude Inbound ────────────────────────────────────────
        $amplitudeIn = [
            '[Amplitude] Page Viewed' => 'page_view',
            '[Amplitude] User Signed Up' => 'sign_up',
            '[Amplitude] User Logged In' => 'login',
            '[Amplitude] Subscription Started' => 'subscription',
            '[Amplitude] Trial Started' => 'trial_start',
        ];
        foreach ($amplitudeIn as $from => $to) {
            $this->inboundMaps['amplitude'][$from] = $to;
        }

        // ── ZeroBoiler → PostHog Outbound ───────────────────────────
        $posthogOut = [
            'page_view' => '$pageview',
            'screen_view' => '$screen',
            'sign_up' => 'user_signed_up',
            'login' => 'user_login',
            'logout' => 'user_logout',
            'subscription' => 'subscription_created',
            'add_payment_info' => 'payment_added',
        ];
        foreach ($posthogOut as $from => $to) {
            $this->outboundMaps['posthog'][$from] = $to;
        }

        // ── ZeroBoiler → Mixpanel Outbound ──────────────────────────
        $mixpanelOut = [
            'sign_up' => 'Signup',
            'login' => 'Login',
            'logout' => 'Logout',
            'plan_upgrade' => 'Plan Upgraded',
            'subscription' => 'Subscription Started',
            'trial_start' => 'Trial Started',
            'add_payment_info' => 'Payment Info Added',
            'purchase' => 'Purchase',
        ];
        foreach ($mixpanelOut as $from => $to) {
            $this->outboundMaps['mixpanel'][$from] = $to;
        }

        // ── ZeroBoiler → Segment Outbound ───────────────────────────
        $segmentOut = [
            'page_view' => 'page',
            'screen_view' => 'screen',
            'identify' => 'identify',
            'sign_up' => 'Signed Up',
            'login' => 'Logged In',
            'subscription' => 'Subscription Started',
            'purchase' => 'Order Completed',
            'view_item' => 'Product Viewed',
            'add_to_cart' => 'Product Added',
            'begin_checkout' => 'Checkout Started',
            'add_payment_info' => 'Payment Info Entered',
        ];
        foreach ($segmentOut as $from => $to) {
            $this->outboundMaps['segment'][$from] = $to;
        }

        // ── ZeroBoiler → Amplitude Outbound ─────────────────────────
        $amplitudeOut = [
            'page_view' => '[Amplitude] Page Viewed',
            'sign_up' => '[Amplitude] User Signed Up',
            'login' => '[Amplitude] User Logged In',
            'subscription' => '[Amplitude] Subscription Started',
            'trial_start' => '[Amplitude] Trial Started',
        ];
        foreach ($amplitudeOut as $from => $to) {
            $this->outboundMaps['amplitude'][$from] = $to;
        }

        // ── Register default outbound parameter transformers ────────
        // PostHog uses $set for user properties, $distinct_id for user ID
        $this->outboundParamTransformers['posthog'] = function (string $eventName, array $params): array {
            if (isset($params['user_id'])) {
                $params['distinct_id'] = $params['user_id'];
                unset($params['user_id']);
            }
            if (isset($params['user_properties'])) {
                $params['$set'] = $params['user_properties'];
                unset($params['user_properties']);
            }
            return $params;
        };

        // Mixpanel uses distinct_id, $email, $name
        $this->outboundParamTransformers['mixpanel'] = function (string $eventName, array $params): array {
            if (isset($params['user_id'])) {
                $params['distinct_id'] = $params['user_id'];
                unset($params['user_id']);
            }
            return $params;
        };

        // Segment uses traits for user properties
        $this->outboundParamTransformers['segment'] = function (string $eventName, array $params): array {
            if (isset($params['user_properties'])) {
                $params['traits'] = $params['user_properties'];
                unset($params['user_properties']);
            }
            return $params;
        };
    }
}
