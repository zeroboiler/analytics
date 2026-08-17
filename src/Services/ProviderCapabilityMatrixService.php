<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\ProviderCapability;
use ZeroBoiler\Analytics\DTO\ProviderCapabilityProfile;

/**
 * Analytics Provider Feature Capability Matrix.
 *
 * Centralized registry of feature support, limits, and protocol details
 * for all 10 analytics providers. Answers questions like:
 *
 * - Does GA4 support user properties in server-side events?
 * - What's the max custom dimensions limit for PostHog?
 * - Which providers support batch API?
 * - Does Meta Pixel support server-side event deduplication?
 * - Which providers have consent mode integration?
 *
 * Used by the event router to make intelligent dispatch decisions,
 * by the validation engine to warn about unsupported features,
 * and by admin dashboards for provider comparison views.
 *
 * Inspired by Segment's Provider Capabilities, mParticle's Kit Matrix,
 * and Tealium's Connector Catalog.
 *
 * Configuration: `zeroboiler.analytics.provider_capabilities`
 *
 * @since 215.0.0
 * @see \ZeroBoiler\Analytics\DTO\ProviderCapability
 * @see \ZeroBoiler\Analytics\DTO\ProviderCapabilityProfile
 */
final class ProviderCapabilityMatrixService
{
    /** @var string Cache prefix */
    private const CACHE_PREFIX = 'zb_provider_cap_';

    /** @var int Default cache TTL (6 hours) */
    private const DEFAULT_CACHE_TTL = 21600;

    /**
     * All capability definitions checked for each provider.
     *
     * @var list<array{name: string, type: 'feature'|'limit'|'format'|'protocol', description: string, check: callable(string): array{supported: bool, value: mixed}}>
     */
    private const CAPABILITY_DEFINITIONS = [
        // ── Core Protocol ────────────────────────────────────────────
        [
            'name' => 'server_side_events',
            'type' => 'feature',
            'description' => 'Supports server-to-server event dispatch (no browser dependency)',
            'check' => null, // defined in $providerChecks
        ],
        [
            'name' => 'batch_api',
            'type' => 'feature',
            'description' => 'Supports batch event submission via API endpoint',
            'check' => null,
        ],
        [
            'name' => 'identify_api',
            'type' => 'feature',
            'description' => 'Supports server-side user identification/aliasing',
            'check' => null,
        ],
        [
            'name' => 'http_post',
            'type' => 'protocol',
            'description' => 'Accepts events via HTTP POST',
            'check' => null,
        ],
        [
            'name' => 'http_get',
            'type' => 'protocol',
            'description' => 'Accepts events via HTTP GET (tracking pixel)',
            'check' => null,
        ],
        [
            'name' => 'json_payload',
            'type' => 'format',
            'description' => 'Accepts JSON event payloads',
            'check' => null,
        ],
        [
            'name' => 'webhook_delivery',
            'type' => 'feature',
            'description' => 'Supports outbound webhook event delivery',
            'check' => null,
        ],
        // ── Identity ────────────────────────────────────────────────
        [
            'name' => 'user_properties',
            'type' => 'feature',
            'description' => 'Supports attaching user properties/traits to events',
            'check' => null,
        ],
        [
            'name' => 'user_aliasing',
            'type' => 'feature',
            'description' => 'Supports merging anonymous ↔ authenticated identity',
            'check' => null,
        ],
        [
            'name' => 'client_id_tracking',
            'type' => 'feature',
            'description' => 'Supports anonymous client/device ID tracking',
            'check' => null,
        ],
        [
            'name' => 'cross_device_identity',
            'type' => 'feature',
            'description' => 'Supports cross-device identity resolution',
            'check' => null,
        ],
        // ── E-commerce ───────────────────────────────────────────────
        [
            'name' => 'ecommerce_items',
            'type' => 'feature',
            'description' => 'Supports structured item/product arrays in events',
            'check' => null,
        ],
        [
            'name' => 'ecommerce_purchase',
            'type' => 'feature',
            'description' => 'Has a dedicated purchase/conversion event type',
            'check' => null,
        ],
        [
            'name' => 'ecommerce_refund',
            'type' => 'feature',
            'description' => 'Supports refund event tracking',
            'check' => null,
        ],
        [
            'name' => 'ecommerce_cart',
            'type' => 'feature',
            'description' => 'Supports add-to-cart / remove-from-cart events',
            'check' => null,
        ],
        [
            'name' => 'currency_conversion',
            'type' => 'feature',
            'description' => 'Supports multi-currency value normalization',
            'check' => null,
        ],
        // ── Consent & Privacy ────────────────────────────────────────
        [
            'name' => 'consent_mode',
            'type' => 'feature',
            'description' => 'Has built-in consent mode / consent-aware tracking',
            'check' => null,
        ],
        [
            'name' => 'data_residency',
            'type' => 'feature',
            'description' => 'Supports regional data residency (EU, US, etc.)',
            'check' => null,
        ],
        [
            'name' => 'pii_scrubbing',
            'type' => 'feature',
            'description' => 'Built-in PII detection and scrubbing',
            'check' => null,
        ],
        // ── Limits ───────────────────────────────────────────────────
        [
            'name' => 'max_custom_dimensions',
            'type' => 'limit',
            'description' => 'Maximum custom event parameters/dimensions per event',
            'check' => null,
        ],
        [
            'name' => 'max_user_properties',
            'type' => 'limit',
            'description' => 'Maximum user properties/traits per user profile',
            'check' => null,
        ],
        [
            'name' => 'max_batch_size',
            'type' => 'limit',
            'description' => 'Maximum events per batch API request',
            'check' => null,
        ],
        [
            'name' => 'max_payload_bytes',
            'type' => 'limit',
            'description' => 'Maximum payload size in bytes per request',
            'check' => null,
        ],
        [
            'name' => 'rate_limit_rpm',
            'type' => 'limit',
            'description' => 'Rate limit in requests per minute',
            'check' => null,
        ],
        // ── Advanced ────────────────────────────────────────────────
        [
            'name' => 'event_deduplication',
            'type' => 'feature',
            'description' => 'Built-in event deduplication support',
            'check' => null,
        ],
        [
            'name' => 'event_validation',
            'type' => 'feature',
            'description' => 'Server-side event schema validation',
            'check' => null,
        ],
        [
            'name' => 'debug_mode',
            'type' => 'feature',
            'description' => 'Supports debug/verbose mode for troubleshooting',
            'check' => null,
        ],
        [
            'name' => 'retry_mechanism',
            'type' => 'feature',
            'description' => 'Built-in retry on transient failures',
            'check' => null,
        ],
        [
            'name' => 'sdk_token_auth',
            'type' => 'feature',
            'description' => 'Supports API key / SDK token authentication',
            'check' => null,
        ],
        [
            'name' => 'realtime_reporting',
            'type' => 'feature',
            'description' => 'Real-time event availability in reporting (seconds)',
            'check' => null,
        ],
        [
            'name' => 'session_tracking',
            'type' => 'feature',
            'description' => 'Automatic session tracking / session reconstruction',
            'check' => null,
        ],
        [
            'name' => 'funnel_analysis',
            'type' => 'feature',
            'description' => 'Built-in funnel analysis in provider dashboard',
            'check' => null,
        ],
        [
            'name' => 'cohort_analysis',
            'type' => 'feature',
            'description' => 'Built-in cohort analysis in provider dashboard',
            'check' => null,
        ],
    ];

    /**
     * Provider capability matrix.
     * Each key is a provider ID. Values are arrays of capability_name → {supported, value}.
     *
     * @var array<string, array<string, array{supported: bool, value: mixed}>>
     */
    private const PROVIDER_CHECKS = [
        'ga4' => [
            'server_side_events' => ['supported' => true, 'value' => null],
            'batch_api' => ['supported' => true, 'value' => null],
            'identify_api' => ['supported' => false, 'value' => null],
            'http_post' => ['supported' => true, 'value' => null],
            'http_get' => ['supported' => true, 'value' => null],
            'json_payload' => ['supported' => true, 'value' => null],
            'webhook_delivery' => ['supported' => false, 'value' => null],
            'user_properties' => ['supported' => true, 'value' => null],
            'user_aliasing' => ['supported' => false, 'value' => null],
            'client_id_tracking' => ['supported' => true, 'value' => null],
            'cross_device_identity' => ['supported' => true, 'value' => null],
            'ecommerce_items' => ['supported' => true, 'value' => null],
            'ecommerce_purchase' => ['supported' => true, 'value' => null],
            'ecommerce_refund' => ['supported' => true, 'value' => null],
            'ecommerce_cart' => ['supported' => true, 'value' => null],
            'currency_conversion' => ['supported' => true, 'value' => null],
            'consent_mode' => ['supported' => true, 'value' => 'v2'],
            'data_residency' => ['supported' => true, 'value' => null],
            'pii_scrubbing' => ['supported' => false, 'value' => null],
            'max_custom_dimensions' => ['supported' => true, 'value' => 50],
            'max_user_properties' => ['supported' => true, 'value' => 25],
            'max_batch_size' => ['supported' => true, 'value' => 25],
            'max_payload_bytes' => ['supported' => true, 'value' => 130000],
            'rate_limit_rpm' => ['supported' => true, 'value' => 720],
            'event_deduplication' => ['supported' => true, 'value' => null],
            'event_validation' => ['supported' => true, 'value' => null],
            'debug_mode' => ['supported' => true, 'value' => null],
            'retry_mechanism' => ['supported' => false, 'value' => null],
            'sdk_token_auth' => ['supported' => true, 'value' => 'api_secret'],
            'realtime_reporting' => ['supported' => true, 'value' => 300],
            'session_tracking' => ['supported' => true, 'value' => null],
            'funnel_analysis' => ['supported' => true, 'value' => null],
            'cohort_analysis' => ['supported' => true, 'value' => null],
        ],
        'gtm' => [
            'server_side_events' => ['supported' => true, 'value' => null],
            'batch_api' => ['supported' => false, 'value' => null],
            'identify_api' => ['supported' => false, 'value' => null],
            'http_post' => ['supported' => true, 'value' => null],
            'http_get' => ['supported' => false, 'value' => null],
            'json_payload' => ['supported' => true, 'value' => null],
            'webhook_delivery' => ['supported' => true, 'value' => null],
            'user_properties' => ['supported' => true, 'value' => null],
            'user_aliasing' => ['supported' => false, 'value' => null],
            'client_id_tracking' => ['supported' => true, 'value' => null],
            'cross_device_identity' => ['supported' => true, 'value' => null],
            'ecommerce_items' => ['supported' => true, 'value' => null],
            'ecommerce_purchase' => ['supported' => true, 'value' => null],
            'ecommerce_refund' => ['supported' => true, 'value' => null],
            'ecommerce_cart' => ['supported' => true, 'value' => null],
            'currency_conversion' => ['supported' => false, 'value' => null],
            'consent_mode' => ['supported' => true, 'value' => 'v2'],
            'data_residency' => ['supported' => false, 'value' => null],
            'pii_scrubbing' => ['supported' => false, 'value' => null],
            'max_custom_dimensions' => ['supported' => true, 'value' => 100],
            'max_user_properties' => ['supported' => true, 'value' => 50],
            'max_batch_size' => ['supported' => false, 'value' => 0],
            'max_payload_bytes' => ['supported' => true, 'value' => 8192],
            'rate_limit_rpm' => ['supported' => false, 'value' => null],
            'event_deduplication' => ['supported' => false, 'value' => null],
            'event_validation' => ['supported' => false, 'value' => null],
            'debug_mode' => ['supported' => true, 'value' => 'preview'],
            'retry_mechanism' => ['supported' => false, 'value' => null],
            'sdk_token_auth' => ['supported' => false, 'value' => null],
            'realtime_reporting' => ['supported' => true, 'value' => 300],
            'session_tracking' => ['supported' => true, 'value' => null],
            'funnel_analysis' => ['supported' => true, 'value' => null],
            'cohort_analysis' => ['supported' => true, 'value' => null],
        ],
        'meta_pixel' => [
            'server_side_events' => ['supported' => true, 'value' => 'CAPI'],
            'batch_api' => ['supported' => true, 'value' => 1000],
            'identify_api' => ['supported' => false, 'value' => null],
            'http_post' => ['supported' => true, 'value' => null],
            'http_get' => ['supported' => true, 'value' => null],
            'json_payload' => ['supported' => true, 'value' => null],
            'webhook_delivery' => ['supported' => false, 'value' => null],
            'user_properties' => ['supported' => true, 'value' => 'userdata'],
            'user_aliasing' => ['supported' => false, 'value' => null],
            'client_id_tracking' => ['supported' => true, 'value' => 'fbc/fbp'],
            'cross_device_identity' => ['supported' => true, 'value' => 'matching'],
            'ecommerce_items' => ['supported' => true, 'value' => 'contents'],
            'ecommerce_purchase' => ['supported' => true, 'value' => 'Purchase'],
            'ecommerce_refund' => ['supported' => false, 'value' => null],
            'ecommerce_cart' => ['supported' => true, 'value' => 'AddToCart'],
            'currency_conversion' => ['supported' => true, 'value' => null],
            'consent_mode' => ['supported' => true, 'value' => 'processing'],
            'data_residency' => ['supported' => true, 'value' => null],
            'pii_scrubbing' => ['supported' => false, 'value' => null],
            'max_custom_dimensions' => ['supported' => true, 'value' => 25],
            'max_user_properties' => ['supported' => true, 'value' => 15],
            'max_batch_size' => ['supported' => true, 'value' => 1000],
            'max_payload_bytes' => ['supported' => true, 'value' => 8192],
            'rate_limit_rpm' => ['supported' => true, 'value' => 200],
            'event_deduplication' => ['supported' => true, 'value' => 'event_id'],
            'event_validation' => ['supported' => true, 'value' => null],
            'debug_mode' => ['supported' => true, 'value' => 'test_events'],
            'retry_mechanism' => ['supported' => true, 'value' => null],
            'sdk_token_auth' => ['supported' => true, 'value' => 'access_token'],
            'realtime_reporting' => ['supported' => false, 'value' => 1800],
            'session_tracking' => ['supported' => false, 'value' => null],
            'funnel_analysis' => ['supported' => true, 'value' => null],
            'cohort_analysis' => ['supported' => false, 'value' => null],
        ],
        'posthog' => [
            'server_side_events' => ['supported' => true, 'value' => null],
            'batch_api' => ['supported' => true, 'value' => 100],
            'identify_api' => ['supported' => true, 'value' => '$identify'],
            'http_post' => ['supported' => true, 'value' => null],
            'http_get' => ['supported' => true, 'value' => null],
            'json_payload' => ['supported' => true, 'value' => null],
            'webhook_delivery' => ['supported' => true, 'value' => null],
            'user_properties' => ['supported' => true, 'value' => '$set'],
            'user_aliasing' => ['supported' => true, 'value' => '$createAlias'],
            'client_id_tracking' => ['supported' => true, 'value' => 'distinct_id'],
            'cross_device_identity' => ['supported' => true, 'value' => null],
            'ecommerce_items' => ['supported' => true, 'value' => '$items'],
            'ecommerce_purchase' => ['supported' => true, 'value' => '$purchase'],
            'ecommerce_refund' => ['supported' => true, 'value' => null],
            'ecommerce_cart' => ['supported' => true, 'value' => null],
            'currency_conversion' => ['supported' => false, 'value' => null],
            'consent_mode' => ['supported' => true, 'value' => 'opt_out'],
            'data_residency' => ['supported' => true, 'value' => 'eu/us'],
            'pii_scrubbing' => ['supported' => false, 'value' => null],
            'max_custom_dimensions' => ['supported' => true, 'value' => 100],
            'max_user_properties' => ['supported' => true, 'value' => 100],
            'max_batch_size' => ['supported' => true, 'value' => 100],
            'max_payload_bytes' => ['supported' => true, 'value' => 1048576],
            'rate_limit_rpm' => ['supported' => true, 'value' => 600],
            'event_deduplication' => ['supported' => true, 'value' => null],
            'event_validation' => ['supported' => false, 'value' => null],
            'debug_mode' => ['supported' => true, 'value' => null],
            'retry_mechanism' => ['supported' => false, 'value' => null],
            'sdk_token_auth' => ['supported' => true, 'value' => 'project_api_key'],
            'realtime_reporting' => ['supported' => true, 'value' => 10],
            'session_tracking' => ['supported' => true, 'value' => null],
            'funnel_analysis' => ['supported' => true, 'value' => null],
            'cohort_analysis' => ['supported' => true, 'value' => null],
        ],
        'plausible' => [
            'server_side_events' => ['supported' => true, 'value' => null],
            'batch_api' => ['supported' => false, 'value' => null],
            'identify_api' => ['supported' => false, 'value' => null],
            'http_post' => ['supported' => true, 'value' => null],
            'http_get' => ['supported' => true, 'value' => null],
            'json_payload' => ['supported' => false, 'value' => null],
            'webhook_delivery' => ['supported' => false, 'value' => null],
            'user_properties' => ['supported' => false, 'value' => null],
            'user_aliasing' => ['supported' => false, 'value' => null],
            'client_id_tracking' => ['supported' => true, 'value' => null],
            'cross_device_identity' => ['supported' => false, 'value' => null],
            'ecommerce_items' => ['supported' => false, 'value' => null],
            'ecommerce_purchase' => ['supported' => false, 'value' => null],
            'ecommerce_refund' => ['supported' => false, 'value' => null],
            'ecommerce_cart' => ['supported' => false, 'value' => null],
            'currency_conversion' => ['supported' => false, 'value' => null],
            'consent_mode' => ['supported' => true, 'value' => 'dnt'],
            'data_residency' => ['supported' => true, 'value' => 'eu'],
            'pii_scrubbing' => ['supported' => true, 'value' => null],
            'max_custom_dimensions' => ['supported' => true, 'value' => 7],
            'max_user_properties' => ['supported' => false, 'value' => 0],
            'max_batch_size' => ['supported' => false, 'value' => 0],
            'max_payload_bytes' => ['supported' => true, 'value' => 2048],
            'rate_limit_rpm' => ['supported' => true, 'value' => 300],
            'event_deduplication' => ['supported' => false, 'value' => null],
            'event_validation' => ['supported' => false, 'value' => null],
            'debug_mode' => ['supported' => false, 'value' => null],
            'retry_mechanism' => ['supported' => false, 'value' => null],
            'sdk_token_auth' => ['supported' => true, 'value' => 'site_domain'],
            'realtime_reporting' => ['supported' => true, 'value' => 300],
            'session_tracking' => ['supported' => true, 'value' => null],
            'funnel_analysis' => ['supported' => false, 'value' => null],
            'cohort_analysis' => ['supported' => false, 'value' => null],
        ],
        'mixpanel' => [
            'server_side_events' => ['supported' => true, 'value' => null],
            'batch_api' => ['supported' => true, 'value' => 50],
            'identify_api' => ['supported' => true, 'value' => 'engage'],
            'http_post' => ['supported' => true, 'value' => null],
            'http_get' => ['supported' => true, 'value' => null],
            'json_payload' => ['supported' => true, 'value' => null],
            'webhook_delivery' => ['supported' => false, 'value' => null],
            'user_properties' => ['supported' => true, 'value' => 'people'],
            'user_aliasing' => ['supported' => true, 'value' => 'merge'],
            'client_id_tracking' => ['supported' => true, 'value' => 'distinct_id'],
            'cross_device_identity' => ['supported' => true, 'value' => null],
            'ecommerce_items' => ['supported' => false, 'value' => null],
            'ecommerce_purchase' => ['supported' => true, 'value' => null],
            'ecommerce_refund' => ['supported' => false, 'value' => null],
            'ecommerce_cart' => ['supported' => false, 'value' => null],
            'currency_conversion' => ['supported' => false, 'value' => null],
            'consent_mode' => ['supported' => true, 'value' => 'opt_out'],
            'data_residency' => ['supported' => true, 'value' => 'eu/us'],
            'pii_scrubbing' => ['supported' => false, 'value' => null],
            'max_custom_dimensions' => ['supported' => true, 'value' => 255],
            'max_user_properties' => ['supported' => true, 'value' => 255],
            'max_batch_size' => ['supported' => true, 'value' => 50],
            'max_payload_bytes' => ['supported' => true, 'value' => 524288],
            'rate_limit_rpm' => ['supported' => true, 'value' => 500],
            'event_deduplication' => ['supported' => false, 'value' => null],
            'event_validation' => ['supported' => false, 'value' => null],
            'debug_mode' => ['supported' => true, 'value' => null],
            'retry_mechanism' => ['supported' => false, 'value' => null],
            'sdk_token_auth' => ['supported' => true, 'value' => 'project_token'],
            'realtime_reporting' => ['supported' => true, 'value' => 60],
            'session_tracking' => ['supported' => false, 'value' => null],
            'funnel_analysis' => ['supported' => true, 'value' => null],
            'cohort_analysis' => ['supported' => true, 'value' => null],
        ],
        'amplitude' => [
            'server_side_events' => ['supported' => true, 'value' => null],
            'batch_api' => ['supported' => true, 'value' => 10],
            'identify_api' => ['supported' => true, 'value' => 'identify'],
            'http_post' => ['supported' => true, 'value' => null],
            'http_get' => ['supported' => true, 'value' => null],
            'json_payload' => ['supported' => true, 'value' => null],
            'webhook_delivery' => ['supported' => false, 'value' => null],
            'user_properties' => ['supported' => true, 'value' => 'user_properties'],
            'user_aliasing' => ['supported' => true, 'value' => 'setUserId'],
            'client_id_tracking' => ['supported' => true, 'value' => 'device_id'],
            'cross_device_identity' => ['supported' => true, 'value' => null],
            'ecommerce_items' => ['supported' => false, 'value' => null],
            'ecommerce_purchase' => ['supported' => true, 'value' => null],
            'ecommerce_refund' => ['supported' => false, 'value' => null],
            'ecommerce_cart' => ['supported' => false, 'value' => null],
            'currency_conversion' => ['supported' => false, 'value' => null],
            'consent_mode' => ['supported' => true, 'value' => 'opt_out'],
            'data_residency' => ['supported' => true, 'value' => 'eu/us'],
            'pii_scrubbing' => ['supported' => false, 'value' => null],
            'max_custom_dimensions' => ['supported' => true, 'value' => 1000],
            'max_user_properties' => ['supported' => true, 'value' => 1000],
            'max_batch_size' => ['supported' => true, 'value' => 10],
            'max_payload_bytes' => ['supported' => true, 'value' => 524288],
            'rate_limit_rpm' => ['supported' => true, 'value' => 600],
            'event_deduplication' => ['supported' => true, 'value' => null],
            'event_validation' => ['supported' => false, 'value' => null],
            'debug_mode' => ['supported' => true, 'value' => null],
            'retry_mechanism' => ['supported' => false, 'value' => null],
            'sdk_token_auth' => ['supported' => true, 'value' => 'api_key'],
            'realtime_reporting' => ['supported' => true, 'value' => 30],
            'session_tracking' => ['supported' => true, 'value' => null],
            'funnel_analysis' => ['supported' => true, 'value' => null],
            'cohort_analysis' => ['supported' => true, 'value' => null],
        ],
        'tiktok' => [
            'server_side_events' => ['supported' => true, 'value' => 'events API'],
            'batch_api' => ['supported' => false, 'value' => null],
            'identify_api' => ['supported' => false, 'value' => null],
            'http_post' => ['supported' => true, 'value' => null],
            'http_get' => ['supported' => true, 'value' => null],
            'json_payload' => ['supported' => true, 'value' => null],
            'webhook_delivery' => ['supported' => false, 'value' => null],
            'user_properties' => ['supported' => false, 'value' => null],
            'user_aliasing' => ['supported' => false, 'value' => null],
            'client_id_tracking' => ['supported' => true, 'value' => 'ttclid'],
            'cross_device_identity' => ['supported' => false, 'value' => null],
            'ecommerce_items' => ['supported' => true, 'value' => 'contents'],
            'ecommerce_purchase' => ['supported' => true, 'value' => 'CompletePayment'],
            'ecommerce_refund' => ['supported' => false, 'value' => null],
            'ecommerce_cart' => ['supported' => true, 'value' => 'AddToCart'],
            'currency_conversion' => ['supported' => false, 'value' => null],
            'consent_mode' => ['supported' => false, 'value' => null],
            'data_residency' => ['supported' => false, 'value' => null],
            'pii_scrubbing' => ['supported' => false, 'value' => null],
            'max_custom_dimensions' => ['supported' => true, 'value' => 10],
            'max_user_properties' => ['supported' => false, 'value' => 0],
            'max_batch_size' => ['supported' => false, 'value' => 0],
            'max_payload_bytes' => ['supported' => true, 'value' => 2048],
            'rate_limit_rpm' => ['supported' => true, 'value' => 100],
            'event_deduplication' => ['supported' => false, 'value' => null],
            'event_validation' => ['supported' => false, 'value' => null],
            'debug_mode' => ['supported' => false, 'value' => null],
            'retry_mechanism' => ['supported' => false, 'value' => null],
            'sdk_token_auth' => ['supported' => true, 'value' => 'access_token'],
            'realtime_reporting' => ['supported' => false, 'value' => 3600],
            'session_tracking' => ['supported' => false, 'value' => null],
            'funnel_analysis' => ['supported' => false, 'value' => null],
            'cohort_analysis' => ['supported' => false, 'value' => null],
        ],
        'linkedin' => [
            'server_side_events' => ['supported' => true, 'value' => 'conversions API'],
            'batch_api' => ['supported' => false, 'value' => null],
            'identify_api' => ['supported' => false, 'value' => null],
            'http_post' => ['supported' => true, 'value' => null],
            'http_get' => ['supported' => true, 'value' => null],
            'json_payload' => ['supported' => true, 'value' => null],
            'webhook_delivery' => ['supported' => false, 'value' => null],
            'user_properties' => ['supported' => false, 'value' => null],
            'user_aliasing' => ['supported' => false, 'value' => null],
            'client_id_tracking' => ['supported' => true, 'value' => null],
            'cross_device_identity' => ['supported' => true, 'value' => 'matched_audiences'],
            'ecommerce_items' => ['supported' => false, 'value' => null],
            'ecommerce_purchase' => ['supported' => true, 'value' => 'purchase'],
            'ecommerce_refund' => ['supported' => false, 'value' => null],
            'ecommerce_cart' => ['supported' => false, 'value' => null],
            'currency_conversion' => ['supported' => false, 'value' => null],
            'consent_mode' => ['supported' => false, 'value' => null],
            'data_residency' => ['supported' => false, 'value' => null],
            'pii_scrubbing' => ['supported' => false, 'value' => null],
            'max_custom_dimensions' => ['supported' => true, 'value' => 10],
            'max_user_properties' => ['supported' => false, 'value' => 0],
            'max_batch_size' => ['supported' => false, 'value' => 0],
            'max_payload_bytes' => ['supported' => true, 'value' => 2048],
            'rate_limit_rpm' => ['supported' => true, 'value' => 200],
            'event_deduplication' => ['supported' => true, 'value' => 'event_id'],
            'event_validation' => ['supported' => false, 'value' => null],
            'debug_mode' => ['supported' => false, 'value' => null],
            'retry_mechanism' => ['supported' => false, 'value' => null],
            'sdk_token_auth' => ['supported' => true, 'value' => 'access_token'],
            'realtime_reporting' => ['supported' => false, 'value' => 86400],
            'session_tracking' => ['supported' => false, 'value' => null],
            'funnel_analysis' => ['supported' => false, 'value' => null],
            'cohort_analysis' => ['supported' => false, 'value' => null],
        ],
        'webhook' => [
            'server_side_events' => ['supported' => true, 'value' => null],
            'batch_api' => ['supported' => true, 'value' => 100],
            'identify_api' => ['supported' => true, 'value' => null],
            'http_post' => ['supported' => true, 'value' => null],
            'http_get' => ['supported' => true, 'value' => null],
            'json_payload' => ['supported' => true, 'value' => null],
            'webhook_delivery' => ['supported' => true, 'value' => null],
            'user_properties' => ['supported' => true, 'value' => null],
            'user_aliasing' => ['supported' => true, 'value' => null],
            'client_id_tracking' => ['supported' => true, 'value' => null],
            'cross_device_identity' => ['supported' => true, 'value' => null],
            'ecommerce_items' => ['supported' => true, 'value' => null],
            'ecommerce_purchase' => ['supported' => true, 'value' => null],
            'ecommerce_refund' => ['supported' => true, 'value' => null],
            'ecommerce_cart' => ['supported' => true, 'value' => null],
            'currency_conversion' => ['supported' => false, 'value' => null],
            'consent_mode' => ['supported' => true, 'value' => null],
            'data_residency' => ['supported' => false, 'value' => null],
            'pii_scrubbing' => ['supported' => false, 'value' => null],
            'max_custom_dimensions' => ['supported' => true, 'value' => 999],
            'max_user_properties' => ['supported' => true, 'value' => 999],
            'max_batch_size' => ['supported' => true, 'value' => 100],
            'max_payload_bytes' => ['supported' => true, 'value' => 1048576],
            'rate_limit_rpm' => ['supported' => false, 'value' => null],
            'event_deduplication' => ['supported' => false, 'value' => null],
            'event_validation' => ['supported' => false, 'value' => null],
            'debug_mode' => ['supported' => true, 'value' => null],
            'retry_mechanism' => ['supported' => false, 'value' => null],
            'sdk_token_auth' => ['supported' => true, 'value' => 'signature'],
            'realtime_reporting' => ['supported' => true, 'value' => 5],
            'session_tracking' => ['supported' => false, 'value' => null],
            'funnel_analysis' => ['supported' => false, 'value' => null],
            'cohort_analysis' => ['supported' => false, 'value' => null],
        ],
    ];

    /** @var array<string, string> Provider ID → human-readable name */
    private const PROVIDER_DISPLAY_NAMES = [
        'ga4' => 'Google Analytics 4',
        'gtm' => 'Google Tag Manager',
        'meta_pixel' => 'Meta Pixel (Conversions API)',
        'posthog' => 'PostHog',
        'plausible' => 'Plausible Analytics',
        'mixpanel' => 'Mixpanel',
        'amplitude' => 'Amplitude',
        'tiktok' => 'TikTok Events API',
        'linkedin' => 'LinkedIn Conversions API',
        'webhook' => 'Generic Webhook',
    ];

    /** @var array<string, string> Provider ID → type classification */
    private const PROVIDER_TYPES = [
        'ga4' => 'hybrid',
        'gtm' => 'hybrid',
        'meta_pixel' => 'hybrid',
        'posthog' => 'hybrid',
        'plausible' => 'hybrid',
        'mixpanel' => 'hybrid',
        'amplitude' => 'hybrid',
        'tiktok' => 'hybrid',
        'linkedin' => 'hybrid',
        'webhook' => 'server',
    ];

    /** @var list<string> All supported provider IDs */
    private const ALL_PROVIDERS = [
        'ga4', 'gtm', 'meta_pixel', 'posthog', 'plausible',
        'mixpanel', 'amplitude', 'tiktok', 'linkedin', 'webhook',
    ];

    private CacheRepository $cache;

    private int $cacheTtl;

    /** @var array<string, ProviderCapabilityProfile>|null Cached profiles */
    private ?array $profileCache = null;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Analytics configuration
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config)
    {
        $this->cache = $cache;
        $capConfig = $config->get('zeroboiler.analytics.provider_capabilities', []);
        /** @var array{cache_ttl?: int} $capConfig */
        $this->cacheTtl = $capConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL;
    }

    /**
     * Get a full capability profile for a single provider.
     *
     * @param  string  $provider  Provider identifier
     * @return ProviderCapabilityProfile|null Profile DTO, or null for unknown provider
     */
    public function getProfile(string $provider): ?ProviderCapabilityProfile
    {
        $provider = strtolower($provider);

        if (! isset(self::PROVIDER_CHECKS[$provider])) {
            return null;
        }

        if (isset($this->profileCache[$provider])) {
            return $this->profileCache[$provider];
        }

        $capabilities = [];
        $missingCapabilities = [];
        $limitations = [];
        $supportedCount = 0;

        foreach (self::CAPABILITY_DEFINITIONS as $def) {
            $capName = $def['name'];
            $check = self::PROVIDER_CHECKS[$provider][$capName] ?? ['supported' => false, 'value' => null];

            $capabilities[$capName] = new ProviderCapability(
                name: $capName,
                type: $def['type'],
                supported: $check['supported'],
                value: $check['value'],
                description: $def['description'],
            );

            if ($check['supported']) {
                $supportedCount++;
                if ($check['value'] !== null && $def['type'] === 'limit') {
                    $limitations[$capName] = $check['value'];
                }
            } else {
                $missingCapabilities[] = $capName;
            }
        }

        $totalCapabilities = count(self::CAPABILITY_DEFINITIONS);
        $coveragePercent = $totalCapabilities > 0
            ? ($supportedCount / $totalCapabilities) * 100
            : 0.0;

        $profile = new ProviderCapabilityProfile(
            provider: $provider,
            displayName: self::PROVIDER_DISPLAY_NAMES[$provider] ?? $provider,
            providerType: self::PROVIDER_TYPES[$provider] ?? 'unknown',
            capabilities: $capabilities,
            supportedCount: $supportedCount,
            totalCapabilities: $totalCapabilities,
            coveragePercent: $coveragePercent,
            missingCapabilities: $missingCapabilities,
            limitations: $limitations,
            computedAt: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        );

        $this->profileCache[$provider] = $profile;

        return $profile;
    }

    /**
     * Quick check: does the provider support a specific capability?
     *
     * @param  string  $provider  Provider identifier
     * @param  string  $capabilityName  Capability name to check
     */
    public function supports(string $provider, string $capabilityName): bool
    {
        $profile = $this->getProfile($provider);

        return $profile?->supports($capabilityName) ?? false;
    }

    /**
     * Get the value of a capability for a provider (e.g. max_batch_size → 50).
     *
     * Returns null if the provider doesn't support the capability.
     *
     * @param  string  $provider  Provider identifier
     * @param  string  $capabilityName  Capability name
     */
    public function getCapabilityValue(string $provider, string $capabilityName): mixed
    {
        $profile = $this->getProfile($provider);

        return $profile?->getCapabilityValue($capabilityName);
    }

    /**
     * Get all provider profiles.
     *
     * @return array<string, ProviderCapabilityProfile>
     */
    public function getAllProfiles(): array
    {
        $profiles = [];
        foreach (self::ALL_PROVIDERS as $provider) {
            $profile = $this->getProfile($provider);
            if ($profile !== null) {
                $profiles[$provider] = $profile;
            }
        }

        return $profiles;
    }

    /**
     * Get all capability definitions (names, types, descriptions).
     *
     * @return list<array{name: string, type: string, description: string}>
     */
    public function getCapabilityDefinitions(): array
    {
        return array_map(
            static fn (array $def): array => [
                'name' => $def['name'],
                'type' => $def['type'],
                'description' => $def['description'],
            ],
            self::CAPABILITY_DEFINITIONS,
        );
    }

    /**
     * Compare two providers' capabilities side by side.
     *
     * Returns a matrix of capability name → [provider_a => bool, provider_b => bool].
     *
     * @return array<string, array{provider_a: bool, provider_b: bool, match: bool}>
     */
    public function compare(string $providerA, string $providerB): array
    {
        $profileA = $this->getProfile($providerA);
        $profileB = $this->getProfile($providerB);

        if ($profileA === null || $profileB === null) {
            return [];
        }

        $comparison = [];
        foreach (self::CAPABILITY_DEFINITIONS as $def) {
            $capName = $def['name'];
            $aSupported = $profileA->supports($capName);
            $bSupported = $profileB->supports($capName);

            $comparison[$capName] = [
                'provider_a' => $aSupported,
                'provider_b' => $bSupported,
                'match' => $aSupported === $bSupported,
            ];
        }

        return $comparison;
    }

    /**
     * Find providers that support ALL given capabilities.
     *
     * @param  list<string>  $requiredCapabilities  List of required capability names
     * @return list<string> Provider IDs that support all capabilities
     */
    public function findProvidersSupporting(array $requiredCapabilities): array
    {
        $matching = [];

        foreach (self::ALL_PROVIDERS as $provider) {
            $allSupported = true;
            foreach ($requiredCapabilities as $cap) {
                if (! $this->supports($provider, $cap)) {
                    $allSupported = false;
                    break;
                }
            }

            if ($allSupported) {
                $matching[] = $provider;
            }
        }

        return $matching;
    }

    /**
     * Find which providers are missing a specific capability.
     *
     * @param  string  $capabilityName  Capability to check
     * @return list<string> Provider IDs that don't support it
     */
    public function findProvidersMissing(string $capabilityName): array
    {
        $missing = [];

        foreach (self::ALL_PROVIDERS as $provider) {
            if (! $this->supports($provider, $capabilityName)) {
                $missing[] = $provider;
            }
        }

        return $missing;
    }

    /**
     * Get a coverage ranking of all providers by capability support.
     *
     * Returns providers sorted by coverage percent (highest first).
     *
     * @return list<array{provider: string, display_name: string, supported: int, total: int, coverage: float, grade: string}>
     */
    public function coverageRanking(): array
    {
        $profiles = $this->getAllProfiles();
        $rankings = [];

        foreach ($profiles as $provider => $profile) {
            $rankings[] = [
                'provider' => $provider,
                'display_name' => $profile->displayName,
                'supported' => $profile->supportedCount,
                'total' => $profile->totalCapabilities,
                'coverage' => round($profile->coveragePercent, 1),
                'grade' => $this->coverageToGrade($profile->coveragePercent),
            ];
        }

        usort($rankings, static fn (array $a, array $b): int => $b['coverage'] <=> $a['coverage']);

        return $rankings;
    }

    /**
     * Get coverage summary statistics.
     *
     * @return array{providers: int, capabilities: int, avg_coverage: float, best_provider: string, worst_provider: string, best_coverage: float, worst_coverage: float}
     */
    public function coverageSummary(): array
    {
        $profiles = $this->getAllProfiles();

        if (empty($profiles)) {
            return [
                'providers' => 0,
                'capabilities' => count(self::CAPABILITY_DEFINITIONS),
                'avg_coverage' => 0.0,
                'best_provider' => '',
                'worst_provider' => '',
                'best_coverage' => 0.0,
                'worst_coverage' => 0.0,
            ];
        }

        $coverages = array_map(
            static fn (ProviderCapabilityProfile $p): float => $p->coveragePercent,
            $profiles,
        );

        $bestIdx = array_search(max($coverages), $coverages, true);
        $worstIdx = array_search(min($coverages), $coverages, true);
        $providerKeys = array_keys($profiles);

        return [
            'providers' => count($profiles),
            'capabilities' => count(self::CAPABILITY_DEFINITIONS),
            'avg_coverage' => round(array_sum($coverages) / count($coverages), 1),
            'best_provider' => $providerKeys[$bestIdx],
            'worst_provider' => $providerKeys[$worstIdx],
            'best_coverage' => round(max($coverages), 1),
            'worst_coverage' => round(min($coverages), 1),
        ];
    }

    /**
     * Get the list of all supported provider IDs.
     *
     * @return list<string>
     */
    public function getProviders(): array
    {
        return self::ALL_PROVIDERS;
    }

    /**
     * Get the total number of tracked capabilities.
     */
    public function getCapabilityCount(): int
    {
        return count(self::CAPABILITY_DEFINITIONS);
    }

    /**
     * Get a matrix view: all providers × all capabilities as a flat table.
     *
     * Useful for CLI table display and CSV export.
     *
     * @return array{headers: list<string>, rows: list<array<string, mixed>>}
     */
    public function matrixTable(): array
    {
        $capabilityNames = array_map(
            static fn (array $def): string => $def['name'],
            self::CAPABILITY_DEFINITIONS,
        );

        $headers = array_merge(['provider', 'display_name', 'type', 'supported', 'coverage'], $capabilityNames);

        $rows = [];
        foreach (self::ALL_PROVIDERS as $provider) {
            $profile = $this->getProfile($provider);
            if ($profile === null) {
                continue;
            }

            $row = [
                'provider' => $provider,
                'display_name' => $profile->displayName,
                'type' => $profile->providerType,
                'supported' => $profile->supportedCount,
                'coverage' => round($profile->coveragePercent, 1) . '%',
            ];

            foreach ($capabilityNames as $capName) {
                $row[$capName] = $profile->supports($capName) ? '✓' : '✗';
            }

            $rows[] = $row;
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Convert coverage percentage to a letter grade.
     */
    private function coverageToGrade(float $percent): string
    {
        return match (true) {
            $percent >= 90.0 => 'A+',
            $percent >= 80.0 => 'A',
            $percent >= 70.0 => 'B+',
            $percent >= 60.0 => 'B',
            $percent >= 50.0 => 'C',
            $percent >= 40.0 => 'D',
            default => 'F',
        };
    }
}
