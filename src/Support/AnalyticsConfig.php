<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Type-safe analytics configuration accessor.
 *
 * Provides a single entry point for reading all analytics config values
 * with explicit return types, sensible defaults, and no raw array access.
 * Designed for use in services, middleware, and controllers that need
 * structured access to analytics settings.
 *
 * @since 1.0.0
 */
final class AnalyticsConfig
{
    private const CONFIG_KEY = 'zeroboiler.analytics';

    /**
     * @param  ConfigRepository  $config  Laravel config repository
     */
    public function __construct(
        private readonly ConfigRepository $config,
    ){}

    /**
     * Get a raw config value from the analytics section.
     *
     * @param  string  $key  Dot-notation key relative to 'zeroboiler.analytics'
     * @param  mixed  $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config->get(self::CONFIG_KEY.'.'.$key, $default);
    }

    /**
     * Check if a config key exists.
     */
    public function has(string $key): bool
    {
        return $this->config->has(self::CONFIG_KEY.'.'.$key);
    }

    // ── GA4 ────────────────────────────────────────────────────────────

    public function ga4Enabled(): bool
    {
        return (bool) $this->get('ga4.enabled', false);
    }

    public function ga4MeasurementId(): string
    {
        return (string) $this->get('ga4.measurement_id', '');
    }

    public function ga4ApiSecret(): string
    {
        return (string) $this->get('ga4.api_secret', '');
    }

    // ── GTM ────────────────────────────────────────────────────────────

    public function gtmEnabled(): bool
    {
        return (bool) $this->get('gtm.enabled', false);
    }

    public function gtmContainerId(): string
    {
        return (string) $this->get('gtm.container_id', '');
    }

    // ── Meta Pixel ──────────────────────────────────────────────────────

    public function metaPixelEnabled(): bool
    {
        return (bool) $this->get('meta_pixel.enabled', false);
    }

    public function metaPixelId(): string
    {
        return (string) $this->get('meta_pixel.id', '');
    }

    public function metaPixelAccessToken(): string
    {
        return (string) $this->get('meta_pixel.access_token', '');
    }

    // ── Consent ──────────────────────────────────────────────────────────

    public function consentDefault(): string
    {
        return (string) $this->get('consent.default', 'granted');
    }

    public function consentDefaultDenied(): bool
    {
        return $this->consentDefault() === 'denied';
    }

    /**
     * Get consent purposes configuration.
     *
     * @return array<string, array{label: string, required: bool, default: bool}>
     */
    public function consentPurposes(): array
    {
        $purposes = $this->get('consent.purposes', []);

        return is_array($purposes) ? $purposes : [];
    }

    public function consentLogEnabled(): bool
    {
        return (bool) $this->get('consent.log_enabled', false);
    }

    public function consentLogTtl(): int
    {
        return (int) $this->get('consent.log_ttl', 7776000);
    }

    // ── Queue ────────────────────────────────────────────────────────────

    public function queueEnabled(): bool
    {
        return (bool) $this->get('queue.enabled', true);
    }

    public function queueName(): string
    {
        return (string) $this->get('queue.queue', 'analytics');
    }

    public function queueConnection(): ?string
    {
        $connection = $this->get('queue.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    // ── Identity ─────────────────────────────────────────────────────────

    public function identityCookieName(): string
    {
        return (string) $this->get('identity.cookie_name', 'zb_analytics_id');
    }

    public function identityCookieTtl(): int
    {
        return (int) $this->get('identity.cookie_ttl', 525600);
    }

    public function identityCookieSecure(): bool
    {
        return (bool) $this->get('identity.cookie_secure', true);
    }

    public function identityCookieSameSite(): string
    {
        return (string) $this->get('identity.cookie_samesite', 'Lax');
    }

    public function identityCachePrefix(): string
    {
        return (string) $this->get('identity.cache_prefix', 'zb_identity_');
    }

    public function identityLinkTtl(): int
    {
        return (int) $this->get('identity.link_ttl', 7776000);
    }

    public function identityMaxLinksPerUser(): int
    {
        return (int) $this->get('identity.max_links_per_user', 50);
    }

    public function identityMaxLinksPerClient(): int
    {
        return (int) $this->get('identity.max_links_per_client', 10);
    }

    // ── API ────────────────────────────────────────────────────────────

    public function apiEnabled(): bool
    {
        return (bool) $this->get('api.enabled', true);
    }

    public function apiThrottle(): int
    {
        return (int) $this->get('api.throttle', 60);
    }

    public function apiBaseUrl(): string
    {
        return (string) $this->get('api.base_url', '/api/analytics');
    }

    // ── Auto-Track ──────────────────────────────────────────────────────

    public function autoTrackEnabled(): bool
    {
        return (bool) $this->get('auto_track.enabled', true);
    }

    /**
     * @return array<string, bool>
     */
    public function autoTrackEvents(): array
    {
        $events = $this->get('auto_track.events', []);

        return is_array($events) ? $events : [];
    }

    /**
     * @return array<class-string, list<string>>
     */
    public function autoTrackModels(): array
    {
        $models = $this->get('auto_track.models', []);

        return is_array($models) ? $models : [];
    }

    /**
     * @return array<string, class-string>
     */
    public function autoTrackEventMap(): array
    {
        $map = $this->get('auto_track.event_map', []);

        return is_array($map) ? $map : [];
    }

    // ── E-commerce ──────────────────────────────────────────────────────

    public function ecommerceCurrency(): string
    {
        return (string) $this->get('ecommerce.currency', 'USD');
    }

    public function ecommerceBrand(): string
    {
        return (string) $this->get('ecommerce.brand', '');
    }

    public function ecommerceTaxBehavior(): string
    {
        return (string) $this->get('ecommerce.tax_behavior', 'inclusive');
    }

    public function ecommerceShippingDefault(): float
    {
        return (float) $this->get('ecommerce.shipping_default', 0.0);
    }

    // ── Revenue ─────────────────────────────────────────────────────────

    public function revenueCurrency(): string
    {
        return (string) $this->get('revenue.currency', 'USD');
    }

    public function revenueBillingCycleDefault(): string
    {
        return (string) $this->get('revenue.billing_cycle_default', 'monthly');
    }

    // ── Track Links ──────────────────────────────────────────────────────

    public function trackLinksEnabled(): bool
    {
        return (bool) $this->get('track_links.enabled', false);
    }

    public function trackLinksExternal(): bool
    {
        return (bool) $this->get('track_links.track_external', true);
    }

    public function trackLinksInternal(): bool
    {
        return (bool) $this->get('track_links.track_internal', false);
    }

    public function trackLinksExternalPrefix(): string
    {
        return (string) $this->get('track_links.external_prefix', 'outbound');
    }

    // ── Plausible ────────────────────────────────────────────────────────

    public function plausibleEnabled(): bool
    {
        return (bool) $this->get('plausible.enabled', false);
    }

    public function plausibleDomain(): string
    {
        return (string) $this->get('plausible.domain', '');
    }

    public function plausibleApiKey(): string
    {
        return (string) $this->get('plausible.api_key', '');
    }

    // ── PostHog ─────────────────────────────────────────────────────────

    public function posthogEnabled(): bool
    {
        return (bool) $this->get('posthog.enabled', false);
    }

    public function posthogApiKey(): string
    {
        return (string) $this->get('posthog.api_key', '');
    }

    public function posthogHost(): string
    {
        return (string) $this->get('posthog.host', 'https://eu.posthog.com');
    }

    public function posthogProjectId(): string
    {
        return (string) $this->get('posthog.project_id', '');
    }

    // ── Webhook ─────────────────────────────────────────────────────────

    public function webhookEnabled(): bool
    {
        return (bool) $this->get('webhook.enabled', false);
    }

    public function webhookUrl(): string
    {
        return (string) $this->get('webhook.url', '');
    }

    public function webhookSecret(): string
    {
        return (string) $this->get('webhook.secret', '');
    }

    public function webhookTimeout(): int
    {
        return (int) $this->get('webhook.timeout', 5);
    }

    public function webhookRetries(): int
    {
        return (int) $this->get('webhook.retries', 1);
    }

    public function webhookSign(): bool
    {
        return (bool) $this->get('webhook.sign', false);
    }

    /**
     * @return array<string, string>
     */
    public function webhookHeaders(): array
    {
        $headers = $this->get('webhook.headers', []);

        return is_array($headers) ? $headers : [];
    }

    // ── Debug ───────────────────────────────────────────────────────────

    public function debugEnabled(): bool
    {
        return (bool) $this->get('debug.enabled', false);
    }

    public function debugLogEvents(): bool
    {
        return (bool) $this->get('debug.log_events', false);
    }

    // ── Validation ──────────────────────────────────────────────────────

    public function validationStrict(): bool
    {
        return (bool) $this->get('validation.strict', false);
    }

    /**
     * @return list<string>
     */
    public function validationWhitelist(): array
    {
        $whitelist = $this->get('validation.whitelist', []);

        return is_array($whitelist) ? $whitelist : [];
    }

    public function validationMaxEventNameLength(): int
    {
        return (int) $this->get('validation.max_event_name_length', 100);
    }

    public function validationDeduplicationWindow(): int
    {
        return (int) $this->get('validation.deduplication_window', 10);
    }

    // ── Pipeline ────────────────────────────────────────────────────────

    public function pipelineAutoUtm(): bool
    {
        return (bool) $this->get('pipeline.auto_utm', true);
    }

    public function pipelineAutoTimestamp(): bool
    {
        return (bool) $this->get('pipeline.auto_timestamp', false);
    }

    // ── Sampling ─────────────────────────────────────────────────────────

    public function samplingEnabled(): bool
    {
        return (bool) $this->get('sampling.enabled', false);
    }

    public function samplingRate(): float
    {
        return (float) $this->get('sampling.rate', 1.0);
    }

    public function samplingDeterministic(): bool
    {
        return (bool) $this->get('sampling.deterministic', true);
    }

    // ── PII Sanitization ─────────────────────────────────────────────────

    public function piiEnabled(): bool
    {
        return (bool) $this->get('pii_sanitization.enabled', false);
    }

    public function piiStrategy(): string
    {
        return (string) $this->get('pii_sanitization.strategy', 'hash');
    }

    /**
     * @return list<string>
     */
    public function piiCustomFields(): array
    {
        $fields = $this->get('pii_sanitization.custom_fields', []);

        return is_array($fields) ? $fields : [];
    }

    // ── Replay Queue ────────────────────────────────────────────────────

    public function replayEnabled(): bool
    {
        return (bool) $this->get('replay.enabled', true);
    }

    public function replayMaxAttempts(): int
    {
        return (int) $this->get('replay.max_attempts', 3);
    }

    public function replayBaseDelay(): float
    {
        return (float) $this->get('replay.base_delay', 1.0);
    }

    public function replayMaxDelay(): float
    {
        return (float) $this->get('replay.max_delay', 60.0);
    }

    // ── Blueprints ─────────────────────────────────────────────────────

    public function blueprintsEnabled(): bool
    {
        return (bool) $this->get('blueprints.enabled', true);
    }

    public function blueprintsCacheTtl(): int
    {
        return (int) $this->get('blueprints.cache_ttl', 86400);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function blueprintsLibrary(): array
    {
        $library = $this->get('blueprints.library', []);

        return is_array($library) ? $library : [];
    }

    // ── Segment Export ────────────────────────────────────────────────

    public function segmentExportEnabled(): bool
    {
        return (bool) $this->get('segment_export.enabled', false);
    }

    public function segmentWriteKey(): string
    {
        return (string) $this->get('segment_export.write_key', '');
    }

    public function segmentApiUrl(): string
    {
        return (string) $this->get('segment_export.api_url', 'https://api.segment.io/v1/batch');
    }

    public function segmentBatchSize(): int
    {
        return (int) $this->get('segment_export.batch_size', 100);
    }

    public function segmentTimeout(): int
    {
        return (int) $this->get('segment_export.timeout', 10);
    }

    // ── Lifecycle Hooks ───────────────────────────────────────────────

    public function lifecycleHooksEnabled(): bool
    {
        return (bool) $this->get('lifecycle_hooks.enabled', true);
    }

    public function lifecycleHooksMax(): int
    {
        return (int) $this->get('lifecycle_hooks.max_hooks', 50);
    }

    public function lifecycleHooksTimeout(): int
    {
        return (int) $this->get('lifecycle_hooks.timeout', 5);
    }

    public function replayJitter(): float
    {
        return (float) $this->get('replay.jitter', 0.2);
    }

    // ── Metrics ─────────────────────────────────────────────────────────

    public function metricsEnabled(): bool
    {
        return (bool) $this->get('metrics.enabled', false);
    }

    public function metricsLogOnFlush(): bool
    {
        return (bool) $this->get('metrics.log_on_flush', false);
    }

    // ── Stream ──────────────────────────────────────────────────────────

    public function streamBufferSize(): int
    {
        return (int) $this->get('stream.buffer_size', 1000);
    }

    // ── Client Auto-Track ───────────────────────────────────────────────

    public function clientAutoTrackPageViews(): bool
    {
        return (bool) $this->get('client_auto_track.page_views', true);
    }

    public function clientAutoTrackScrollDepth(): bool
    {
        return (bool) $this->get('client_auto_track.scroll_depth', true);
    }

    public function clientAutoTrackFormTracking(): bool
    {
        return (bool) $this->get('client_auto_track.form_tracking', true);
    }

    public function clientAutoTrackErrorTracking(): bool
    {
        return (bool) $this->get('client_auto_track.error_tracking', true);
    }

    public function clientAutoTrackLinkTracking(): bool
    {
        return (bool) $this->get('client_auto_track.link_tracking', false);
    }

    public function clientAutoTrackSessionTracking(): bool
    {
        return (bool) $this->get('client_auto_track.session_tracking', true);
    }

    public function clientAutoTrackIdleTimeout(): int
    {
        return (int) $this->get('client_auto_track.idle_timeout', 1800);
    }

    /**
     * @return list<string>
     */
    public function clientAutoTrackErrorIgnorePatterns(): array
    {
        $patterns = $this->get('client_auto_track.error_ignore_patterns', []);

        return is_array($patterns) ? $patterns : [];
    }

    // ── Performance ─────────────────────────────────────────────────────

    public function performanceEnabled(): bool
    {
        return (bool) $this->get('performance.enabled', false);
    }

    public function performanceTrackLcp(): bool
    {
        return (bool) $this->get('performance.track_lcp', true);
    }

    public function performanceTrackFid(): bool
    {
        return (bool) $this->get('performance.track_fid', true);
    }

    public function performanceTrackCls(): bool
    {
        return (bool) $this->get('performance.track_cls', true);
    }

    public function performanceTrackInp(): bool
    {
        return (bool) $this->get('performance.track_inp', true);
    }

    public function performanceTrackTtfb(): bool
    {
        return (bool) $this->get('performance.track_ttfb', true);
    }

    public function performanceTrackFcp(): bool
    {
        return (bool) $this->get('performance.track_fcp', false);
    }

    public function performanceSendToServer(): bool
    {
        return (bool) $this->get('performance.send_to_server', true);
    }

    // ── Audit Log ───────────────────────────────────────────────────────

    public function auditLogEnabled(): bool
    {
        return (bool) $this->get('audit_log.enabled', false);
    }

    public function auditLogPriority(): int
    {
        return (int) $this->get('audit_log.priority', 100);
    }

    // ── Tracking Preference ─────────────────────────────────────────────

    public function trackingPreferenceTtl(): int
    {
        return (int) $this->get('tracking_preference.ttl', 604800);
    }

    // ── Dedup ────────────────────────────────────────────────────────────

    public function dedupEnabled(): bool
    {
        return (bool) $this->get('dedup.enabled', true);
    }

    // ── GDPR ──────────────────────────────────────────────────────────────

    public function gdprAnonymizeIp(): bool
    {
        return (bool) $this->get('gdpr.anonymize_ip', false);
    }

    public function gdprIpMaskV4(): int
    {
        return (int) $this->get('gdpr.ip_mask_v4', 2);
    }

    public function gdprIpMaskV6(): int
    {
        return (int) $this->get('gdpr.ip_mask_v6', 48);
    }

    // ── Attribution ────────────────────────────────────────────────────

    public function attributionEnabled(): bool
    {
        return (bool) $this->get('attribution.enabled', true);
    }

    public function attributionModel(): string
    {
        return (string) $this->get('attribution.model', 'last_touch');
    }

    public function attributionSessionWindowDays(): int
    {
        return (int) $this->get('attribution.session_window_days', 30);
    }

    public function attributionCacheTtl(): int
    {
        return (int) $this->get('attribution.cache_ttl', 86400);
    }

    public function attributionFirstTouchTtl(): int
    {
        return (int) $this->get('attribution.first_touch_ttl', 2592000);
    }

    public function attributionTouchHistoryTtl(): int
    {
        return (int) $this->get('attribution.touch_history_ttl', 2592000);
    }

    public function attributionMaxTouchHistory(): int
    {
        return (int) $this->get('attribution.max_touch_history', 20);
    }

    // ── Profile ─────────────────────────────────────────────────────────

    public function profileEnabled(): bool
    {
        return (bool) $this->get('profile.enabled', true);
    }

    public function profileTtl(): int
    {
        return (int) $this->get('profile.ttl', 86400);
    }

    // ── Funnels ─────────────────────────────────────────────────────────

    public function funnelsEnabled(): bool
    {
        return (bool) $this->get('funnels.enabled', true);
    }

    public function funnelsCacheEnabled(): bool
    {
        return (bool) $this->get('funnels.cache_enabled', true);
    }

    public function funnelsCacheTtl(): int
    {
        return (int) $this->get('funnels.cache_ttl', 300);
    }

    // ── Alerts ─────────────────────────────────────────────────────────

    public function alertsEnabled(): bool
    {
        return (bool) $this->get('alerts.enabled', true);
    }

    public function alertsCooldown(): int
    {
        return (int) $this->get('alerts.cooldown', 300);
    }

    public function alertsMaxHistory(): int
    {
        return (int) $this->get('alerts.max_history', 200);
    }

    /**
     * @return array<string, mixed>
     */
    public function alertsRules(): array
    {
        $rules = $this->get('alerts.rules', []);

        return is_array($rules) ? $rules : [];
    }

    // ── Inbound Webhook ────────────────────────────────────────────────

    public function inboundWebhookEnabled(): bool
    {
        return (bool) $this->get('inbound_webhook.enabled', false);
    }

    public function inboundWebhookSecret(): string
    {
        return (string) $this->get('inbound_webhook.secret', '');
    }

    public function inboundWebhookRequireSignature(): bool
    {
        return (bool) $this->get('inbound_webhook.require_signature', true);
    }

    public function inboundWebhookMaxPayloadSize(): int
    {
        return (int) $this->get('inbound_webhook.max_payload_size', 65536);
    }

    public function inboundWebhookMaxEvents(): int
    {
        return (int) $this->get('inbound_webhook.max_events', 50);
    }

    // ── Pipeline (extended) ─────────────────────────────────────────────

    public function pipelineAutoMetadata(): bool
    {
        return (bool) $this->get('pipeline.auto_metadata', true);
    }

    public function pipelineSchemaEnrichment(): bool
    {
        return (bool) $this->get('pipeline.schema_enrichment', false);
    }

    // ── Lifecycle Event Mapping ──────────────────────────────────────

    public function lifecycleEnabled(): bool
    {
        return (bool) $this->get('lifecycle.enabled', true);
    }

    public function lifecycleOverrideDefaults(): bool
    {
        return (bool) $this->get('lifecycle.override_defaults', false);
    }

    /**
     * @return array<string, bool>
     */
    public function lifecycleEvents(): array
    {
        $events = $this->get('lifecycle.events', []);

        return is_array($events) ? $events : [];
    }

    /**
     * @return array<string, array{source: string, target: string, params_extractor?: string, condition?: string, priority?: int}>
     */
    public function lifecycleCustomMappings(): array
    {
        $mappings = $this->get('lifecycle.custom_mappings', []);

        return is_array($mappings) ? $mappings : [];
    }

    // ── Event Correlation ───────────────────────────────────────────

    public function correlationEnabled(): bool
    {
        return (bool) $this->get('correlation.enabled', true);
    }

    public function correlationCacheEnabled(): bool
    {
        return (bool) $this->get('correlation.cache_enabled', true);
    }

    public function correlationCacheTtl(): int
    {
        return (int) $this->get('correlation.cache_ttl', 300);
    }

    public function correlationMaxPatternLength(): int
    {
        return (int) $this->get('correlation.max_pattern_length', 5);
    }

    public function correlationMaxJourneysPerUser(): int
    {
        return (int) $this->get('correlation.max_journeys_per_user', 100);
    }

    // ── Data Retention ──────────────────────────────────────────────

    public function retentionEnabled(): bool
    {
        return (bool) $this->get('retention.enabled', false);
    }

    public function retentionDays(): int
    {
        return (int) $this->get('retention.days', 90);
    }

    public function retentionArchiveAction(): string
    {
        return (string) $this->get('retention.archive_action', 'delete');
    }

    // ── Source Tagging ──────────────────────────────────────────────

    public function sourceTaggingEnabled(): bool
    {
        return (bool) $this->get('source_tagging.enabled', true);
    }

    public function sourceTaggingVersion(): bool
    {
        return (bool) $this->get('source_tagging.tag_version', true);
    }

    // ── Boot Validation ─────────────────────────────────────────────

    public function validationBootEnabled(): bool
    {
        return (bool) $this->get('validation_boot.enabled', false);
    }

    public function validationBootLogLevel(): string
    {
        return (string) $this->get('validation_boot.log_level', 'warning');
    }

    // ── Broadcast ────────────────────────────────────────────────────

    public function broadcastEnabled(): bool
    {
        return (bool) $this->get('broadcast.enabled', false);
    }

    public function broadcastChannelPrefix(): string
    {
        return (string) $this->get('broadcast.channel_prefix', 'analytics');
    }

    public function broadcastPrivateChannels(): bool
    {
        return (bool) $this->get('broadcast.private_channels', true);
    }

    public function broadcastValueThreshold(): ?float
    {
        $threshold = $this->get('broadcast.value_threshold');

        return is_numeric($threshold) ? (float) $threshold : null;
    }

    // ── Tenant Isolation ────────────────────────────────────────────

    public function tenantEnabled(): bool
    {
        return (bool) $this->get('tenant.enabled', false);
    }

    public function tenantResolutionStrategy(): string
    {
        return (string) $this->get('tenant.resolution_strategy', 'user_attribute');
    }

    public function tenantHeader(): string
    {
        return (string) $this->get('tenant.tenant_header', 'X-Tenant-ID');
    }

    public function tenantEventsPerHour(): ?int
    {
        $limit = $this->get('tenant.events_per_hour');

        return is_int($limit) ? $limit : null;
    }

    // ── Retention Policy ─────────────────────────────────────────────

    public function retentionPolicyEnabled(): bool
    {
        return (bool) $this->get('retention.enabled', false);
    }

    public function retentionPolicyAutoExpire(): bool
    {
        return (bool) $this->get('retention.auto_expire', false);
    }

    /**
     * @return list<string>
     */
    public function retentionPolicyPiiCategories(): array
    {
        $categories = $this->get('retention.pii_categories', ['pii']);

        return is_array($categories) ? $categories : ['pii'];
    }

    // ── Analytics Gate ────────────────────────────────────────────────

    public function gateEnabled(): bool
    {
        return (bool) $this->get('gate.enabled', false);
    }

    public function gateDefaultPlan(): string
    {
        return (string) $this->get('gate.default_plan', 'free');
    }

    public function gatePlanAttribute(): string
    {
        return (string) $this->get('gate.plan_attribute', 'plan');
    }

    // ── Referral Tracking ─────────────────────────────────────────

    public function referralEnabled(): bool
    {
        return (bool) $this->get('referral.enabled', false);
    }

    public function referralParamName(): string
    {
        return (string) $this->get('referral.param_name', 'ref');
    }

    public function referralTtl(): int
    {
        return (int) $this->get('referral.ttl', 2592000);
    }

    public function referralTrackConversions(): bool
    {
        return (bool) $this->get('referral.track_conversions', true);
    }

    // ── Broadcast Channels ──────────────────────────────────────────

    public function broadcastAlertChannel(): string
    {
        return (string) $this->get('broadcast.alert_channel', 'analytics.alerts');
    }

    public function broadcastMetricsChannel(): string
    {
        return (string) $this->get('broadcast.metrics_channel', 'analytics.metrics');
    }

    // ── Retention Policy (extended) ──────────────────────────────────

    public function retentionPolicyEngagementDays(): int
    {
        return (int) $this->get('retention_policy.engagement_days', 30);
    }

    public function retentionPolicySaasDays(): int
    {
        return (int) $this->get('retention_policy.saas_days', 90);
    }

    public function retentionPolicyEcommerceDays(): int
    {
        return (int) $this->get('retention_policy.ecommerce_days', 365);
    }

    // ── Dead Letter Queue ────────────────────────────────────────────

    public function deadLetterQueueEnabled(): bool
    {
        return (bool) $this->get('dead_letter_queue.enabled', true);
    }

    public function deadLetterQueueStrategy(): string
    {
        return (string) $this->get('dead_letter_queue.strategy', 'file');
    }

    public function deadLetterQueueStoragePath(): string
    {
        return (string) $this->get('dead_letter_queue.storage_path', '');
    }

    public function deadLetterQueueMaxSize(): int
    {
        return (int) $this->get('dead_letter_queue.max_size', 10000);
    }

    public function deadLetterQueueBufferSize(): int
    {
        return (int) $this->get('dead_letter_queue.buffer_size', 50);
    }

    // ── Real-Time Aggregation ────────────────────────────────────────

    public function realtimeEnabled(): bool
    {
        return (bool) $this->get('realtime.enabled', true);
    }

    public function realtimeWindowSeconds(): int
    {
        return (int) $this->get('realtime.window_seconds', 120);
    }

    public function realtimeTopEventsLimit(): int
    {
        return (int) $this->get('realtime.top_events_limit', 20);
    }

    // ── Analytics Snapshots ──────────────────────────────────────────

    public function snapshotsEnabled(): bool
    {
        return (bool) $this->get('snapshots.enabled', true);
    }

    public function snapshotsDailyTtl(): int
    {
        return (int) $this->get('snapshots.daily_ttl', 7776000);
    }

    public function snapshotsHourlyTtl(): int
    {
        return (int) $this->get('snapshots.hourly_ttl', 604800);
    }

    public function snapshotsMaxDaily(): int
    {
        return (int) $this->get('snapshots.max_daily', 90);
    }

    public function snapshotsMaxHourly(): int
    {
        return (int) $this->get('snapshots.max_hourly', 168);
    }

    // ── SaaS KPI ─────────────────────────────────────────────────────

    public function saasKpiEnabled(): bool
    {
        return (bool) $this->get('saas_kpi.enabled', true);
    }

    public function saasKpiCacheTtl(): int
    {
        return (int) $this->get('saas_kpi.cache_ttl', 2592000);
    }

    // ── UTM Aggregation ───────────────────────────────────────────────

    public function utmAggregationEnabled(): bool
    {
        return (bool) $this->get('utm_aggregation.enabled', true);
    }

    public function utmAggregationCacheTtl(): int
    {
        return (int) $this->get('utm_aggregation.cache_ttl', 2592000);
    }

    public function utmAggregationMaxCombinations(): int
    {
        return (int) $this->get('utm_aggregation.max_combinations', 5000);
    }

    // ── Geolocation ─────────────────────────────────────────────────

    public function geolocationEnabled(): bool
    {
        return (bool) $this->get('geolocation.enabled', false);
    }

    public function geolocationStrategy(): string
    {
        return (string) $this->get('geolocation.strategy', 'header');
    }

    public function geolocationCountryHeader(): string
    {
        return (string) $this->get('geolocation.country_header', 'CF-IPCountry');
    }

    public function geolocationRegionHeader(): string
    {
        return (string) $this->get('geolocation.region_header', '');
    }

    public function geolocationCityHeader(): string
    {
        return (string) $this->get('geolocation.city_header', '');
    }

    // ── Reporting ────────────────────────────────────────────────────

    public function reportingEnabled(): bool
    {
        return (bool) $this->get('reporting.enabled', true);
    }

    public function reportingCacheTtl(): int
    {
        return (int) $this->get('reporting.cache_ttl', 300);
    }

    public function reportingTrendingWindow(): int
    {
        return (int) $this->get('reporting.trending_window', 3600);
    }

    public function reportingTopEventsLimit(): int
    {
        return (int) $this->get('reporting.top_events_limit', 20);
    }

    public function reportingTrendingLimit(): int
    {
        return (int) $this->get('reporting.trending_limit', 10);
    }

    // ── A/B Tests ─────────────────────────────────────────────────────

    public function abTestsEnabled(): bool
    {
        return (bool) $this->get('ab_tests.enabled', true);
    }

    public function abTestsConfidenceThreshold(): float
    {
        return (float) $this->get('ab_tests.confidence_threshold', 0.95);
    }

    public function abTestsCacheTtl(): int
    {
        return (int) $this->get('ab_tests.cache_ttl', 604800);
    }

    // ── Performance Budget ────────────────────────────────────────────

    public function performanceBudgetEnabled(): bool
    {
        return (bool) $this->get('performance_budget.enabled', false);
    }

    public function performanceBudgetMaxPayloadBytes(): int
    {
        return (int) $this->get('performance_budget.max_payload_bytes', 8192);
    }

    public function performanceBudgetMaxParamsCount(): int
    {
        return (int) $this->get('performance_budget.max_params_count', 25);
    }

    public function performanceBudgetMaxEventsPerSession(): int
    {
        return (int) $this->get('performance_budget.max_events_per_session', 100);
    }

    public function performanceBudgetMaxEventsPerUserPerDay(): int
    {
        return (int) $this->get('performance_budget.max_events_per_user_per_day', 500);
    }

    public function performanceBudgetMaxEventsPerPageView(): int
    {
        return (int) $this->get('performance_budget.max_events_per_page_view', 50);
    }

    public function performanceBudgetMaxParamValueLength(): int
    {
        return (int) $this->get('performance_budget.max_param_value_length', 500);
    }

    public function performanceBudgetDropOversized(): bool
    {
        return (bool) $this->get('performance_budget.drop_oversized', true);
    }

    public function performanceBudgetWarnOnly(): bool
    {
        return (bool) $this->get('performance_budget.warn_only', false);
    }

    // ── Event Forwarding ────────────────────────────────────────────

    public function forwardingEnabled(): bool
    {
        return (bool) $this->get('forwarding.enabled', false);
    }

    public function forwardingTimeout(): int
    {
        return (int) $this->get('forwarding.timeout', 5);
    }

    public function forwardingRetries(): int
    {
        return (int) $this->get('forwarding.retries', 1);
    }

    public function forwardingRateLimitPerMinute(): int
    {
        return (int) $this->get('forwarding.rate_limit_per_minute', 1000);
    }

    /**
     * @return array<string, array{enabled: bool, type: string}>
     */
    public function forwardingForwarders(): array
    {
        $forwarders = $this->get('forwarding.forwarders', []);
        assert(is_array($forwarders));

        return $forwarders;
    }

    // ── Convenience Summary ─────────────────────────────────────────────

    /**
     * Get a summary of all config values for diagnostics / admin commands.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'ga4' => [
                'enabled' => $this->ga4Enabled(),
                'measurement_id' => $this->ga4MeasurementId(),
            ],
            'gtm' => [
                'enabled' => $this->gtmEnabled(),
                'container_id' => $this->gtmContainerId(),
            ],
            'meta_pixel' => [
                'enabled' => $this->metaPixelEnabled(),
                'id' => $this->metaPixelId(),
            ],
            'plausible' => [
                'enabled' => $this->plausibleEnabled(),
                'domain' => $this->plausibleDomain(),
            ],
            'posthog' => [
                'enabled' => $this->posthogEnabled(),
                'host' => $this->posthogHost(),
            ],
            'webhook' => [
                'enabled' => $this->webhookEnabled(),
                'url' => $this->webhookUrl(),
            ],
            'mixpanel' => [
                'enabled' => $this->mixpanelEnabled(),
                'token' => $this->mixpanelToken(),
            ],
            'amplitude' => [
                'enabled' => $this->amplitudeEnabled(),
                'api_key' => $this->amplitudeApiKey(),
            ],
            'tiktok' => [
                'enabled' => $this->tiktokEnabled(),
                'pixel_id' => $this->tiktokPixelId(),
            ],
            'linkedin' => [
                'enabled' => $this->linkedinEnabled(),
                'partner_id' => $this->linkedinPartnerId(),
            ],
            'consent' => [
                'default' => $this->consentDefault(),
                'purposes_count' => count($this->consentPurposes()),
                'log_enabled' => $this->consentLogEnabled(),
                'log_ttl' => $this->consentLogTtl(),
            ],
            'queue' => [
                'enabled' => $this->queueEnabled(),
                'queue' => $this->queueName(),
                'connection' => $this->queueConnection(),
            ],
            'identity' => [
                'cookie_name' => $this->identityCookieName(),
                'cookie_ttl' => $this->identityCookieTtl(),
                'cookie_secure' => $this->identityCookieSecure(),
                'cookie_samesite' => $this->identityCookieSameSite(),
            ],
            'api' => [
                'enabled' => $this->apiEnabled(),
                'throttle' => $this->apiThrottle(),
                'base_url' => $this->apiBaseUrl(),
            ],
            'auto_track' => [
                'enabled' => $this->autoTrackEnabled(),
                'events_count' => count($this->autoTrackEvents()),
                'models_count' => count($this->autoTrackModels()),
                'event_map_count' => count($this->autoTrackEventMap()),
            ],
            'ecommerce' => [
                'currency' => $this->ecommerceCurrency(),
                'brand' => $this->ecommerceBrand(),
                'tax_behavior' => $this->ecommerceTaxBehavior(),
            ],
            'track_links' => [
                'enabled' => $this->trackLinksEnabled(),
                'track_external' => $this->trackLinksExternal(),
                'track_internal' => $this->trackLinksInternal(),
            ],
            'debug' => [
                'enabled' => $this->debugEnabled(),
                'log_events' => $this->debugLogEvents(),
            ],
            'validation' => [
                'strict' => $this->validationStrict(),
                'whitelist_count' => count($this->validationWhitelist()),
                'max_event_name_length' => $this->validationMaxEventNameLength(),
                'deduplication_window' => $this->validationDeduplicationWindow(),
            ],
            'sampling' => [
                'enabled' => $this->samplingEnabled(),
                'rate' => $this->samplingRate(),
                'deterministic' => $this->samplingDeterministic(),
            ],
            'pii_sanitization' => [
                'enabled' => $this->piiEnabled(),
                'strategy' => $this->piiStrategy(),
            ],
            'replay' => [
                'enabled' => $this->replayEnabled(),
                'max_attempts' => $this->replayMaxAttempts(),
            ],
            'metrics' => [
                'enabled' => $this->metricsEnabled(),
            ],
            'audit_log' => [
                'enabled' => $this->auditLogEnabled(),
            ],
            'performance' => [
                'enabled' => $this->performanceEnabled(),
            ],
            'client_auto_track' => [
                'page_views' => $this->clientAutoTrackPageViews(),
                'scroll_depth' => $this->clientAutoTrackScrollDepth(),
                'form_tracking' => $this->clientAutoTrackFormTracking(),
                'error_tracking' => $this->clientAutoTrackErrorTracking(),
                'link_tracking' => $this->clientAutoTrackLinkTracking(),
                'session_tracking' => $this->clientAutoTrackSessionTracking(),
            ],
            'tracking_preference' => [
                'ttl' => $this->trackingPreferenceTtl(),
            ],
            'dedup' => [
                'enabled' => $this->dedupEnabled(),
            ],
            'gdpr' => [
                'anonymize_ip' => $this->gdprAnonymizeIp(),
                'ip_mask_v4' => $this->gdprIpMaskV4(),
                'ip_mask_v6' => $this->gdprIpMaskV6(),
            ],
            'attribution' => [
                'enabled' => $this->attributionEnabled(),
                'model' => $this->attributionModel(),
                'session_window_days' => $this->attributionSessionWindowDays(),
                'first_touch_ttl' => $this->attributionFirstTouchTtl(),
                'max_touch_history' => $this->attributionMaxTouchHistory(),
            ],
            'profile' => [
                'enabled' => $this->profileEnabled(),
                'ttl' => $this->profileTtl(),
            ],
            'funnels' => [
                'enabled' => $this->funnelsEnabled(),
                'cache_enabled' => $this->funnelsCacheEnabled(),
            ],
            'alerts' => [
                'enabled' => $this->alertsEnabled(),
                'cooldown' => $this->alertsCooldown(),
                'rules_count' => count($this->alertsRules()),
            ],
            'inbound_webhook' => [
                'enabled' => $this->inboundWebhookEnabled(),
                'require_signature' => $this->inboundWebhookRequireSignature(),
            ],
            'lifecycle' => [
                'enabled' => $this->lifecycleEnabled(),
                'override_defaults' => $this->lifecycleOverrideDefaults(),
                'events_count' => count($this->lifecycleEvents()),
                'custom_mappings_count' => count($this->lifecycleCustomMappings()),
            ],
            'correlation' => [
                'enabled' => $this->correlationEnabled(),
                'cache_enabled' => $this->correlationCacheEnabled(),
                'max_pattern_length' => $this->correlationMaxPatternLength(),
            ],
            'stream' => [
                'buffer_size' => $this->streamBufferSize(),
            ],
            'retention' => [
                'enabled' => $this->retentionEnabled(),
                'days' => $this->retentionDays(),
                'archive_action' => $this->retentionArchiveAction(),
            ],
            'source_tagging' => [
                'enabled' => $this->sourceTaggingEnabled(),
                'tag_version' => $this->sourceTaggingVersion(),
            ],
            'validation_boot' => [
                'enabled' => $this->validationBootEnabled(),
                'log_level' => $this->validationBootLogLevel(),
            ],
            'broadcast' => [
                'enabled' => $this->broadcastEnabled(),
                'channel_prefix' => $this->broadcastChannelPrefix(),
                'private_channels' => $this->broadcastPrivateChannels(),
                'alert_channel' => $this->broadcastAlertChannel(),
                'metrics_channel' => $this->broadcastMetricsChannel(),
            ],
            'tenant' => [
                'enabled' => $this->tenantEnabled(),
                'strategy' => $this->tenantResolutionStrategy(),
                'header' => $this->tenantHeader(),
                'rate_limit' => $this->tenantEventsPerHour(),
            ],
            'retention_policy' => [
                'enabled' => $this->retentionPolicyEnabled(),
                'auto_expire' => $this->retentionPolicyAutoExpire(),
                'pii_categories' => $this->retentionPolicyPiiCategories(),
                'engagement_days' => $this->retentionPolicyEngagementDays(),
                'saas_days' => $this->retentionPolicySaasDays(),
                'ecommerce_days' => $this->retentionPolicyEcommerceDays(),
            ],
            'gate' => [
                'enabled' => $this->gateEnabled(),
                'default_plan' => $this->gateDefaultPlan(),
                'plan_attribute' => $this->gatePlanAttribute(),
            ],
            'referral' => [
                'enabled' => $this->referralEnabled(),
                'param_name' => $this->referralParamName(),
                'ttl' => $this->referralTtl(),
                'track_conversions' => $this->referralTrackConversions(),
            ],
            'dead_letter_queue' => [
                'enabled' => $this->deadLetterQueueEnabled(),
                'strategy' => $this->deadLetterQueueStrategy(),
                'max_size' => $this->deadLetterQueueMaxSize(),
            ],
            'realtime' => [
                'enabled' => $this->realtimeEnabled(),
                'window_seconds' => $this->realtimeWindowSeconds(),
            ],
            'snapshots' => [
                'enabled' => $this->snapshotsEnabled(),
                'max_daily' => $this->snapshotsMaxDaily(),
                'max_hourly' => $this->snapshotsMaxHourly(),
            ],
            'saas_kpi' => [
                'enabled' => $this->saasKpiEnabled(),
            ],
            'utm_aggregation' => [
                'enabled' => $this->utmAggregationEnabled(),
            ],
            'geolocation' => [
                'enabled' => $this->geolocationEnabled(),
                'strategy' => $this->geolocationStrategy(),
            ],
            'reporting' => [
                'enabled' => $this->reportingEnabled(),
            ],
            'ab_tests' => [
                'enabled' => $this->abTestsEnabled(),
            ],
            'performance_budget' => [
                'enabled' => $this->performanceBudgetEnabled(),
                'max_payload_bytes' => $this->performanceBudgetMaxPayloadBytes(),
                'drop_oversized' => $this->performanceBudgetDropOversized(),
                'warn_only' => $this->performanceBudgetWarnOnly(),
            ],
            'forwarding' => [
                'enabled' => $this->forwardingEnabled(),
                'timeout' => $this->forwardingTimeout(),
                'retries' => $this->forwardingRetries(),
                'forwarders_count' => count($this->forwardingForwarders()),
            ],
            'routing' => [
                'enabled' => $this->routingEnabled(),
                'rule_count' => count($this->routingRules()),
            ],
            'aliases' => [
                'count' => count($this->aliases()),
            ],
            'event_cache' => [
                'enabled' => $this->eventCacheEnabled(),
                'memory_max_items' => $this->eventCacheMemoryMaxItems(),
                'memory_ttl' => $this->eventCacheMemoryTtl(),
                'cache_ttl' => $this->eventCacheTtl(),
            ],
            'taxonomy' => [
                'enabled' => $this->taxonomyEnabled(),
                'custom_tags_count' => count($this->taxonomyCustomTags()),
                'disabled_tags_count' => count($this->taxonomyDisabledTags()),
            ],
            'campaign_roi' => [
                'enabled' => $this->campaignRoiEnabled(),
                'cache_ttl' => $this->campaignRoiCacheTtl(),
            ],
            'data_minimization' => [
                'enabled' => $this->dataMinimizationEnabled(),
                'strip_params_count' => count($this->dataMinimizationStripParams()),
                'audit_log' => $this->dataMinimizationAuditLog(),
            ],
            'delivery_confirmation' => [
                'enabled' => $this->deliveryConfirmationEnabled(),
                'critical_events_count' => count($this->deliveryConfirmationCriticalEvents()),
                'token_ttl' => $this->deliveryConfirmationTokenTtl(),
            ],
        ];
    }

    /**
     * Check if event routing is enabled.
     */
    public function routingEnabled(): bool
    {
        return (bool) $this->get('routing.enabled', false);
    }

    /**
     * Get configured routing rules.
     *
     * @return array<string, list<string>>
     */
    public function routingRules(): array
    {
        /** @var array<string, list<string>> $rules */
        $rules = $this->get('routing.rules', []);

        return $rules;
    }

    // ── Event Aliases ──────────────────────────────────────────────────

    /**
     * Get custom event aliases from config.
     *
     * @return array<string, string>
     */
    public function aliases(): array
    {
        /** @var array<string, string> $aliases */
        $aliases = $this->get('aliases', []);

        return $aliases;
    }

    // ── Taxonomy ────────────────────────────────────────────────────

    /**
     * Check if event taxonomy is enabled.
     */
    public function taxonomyEnabled(): bool
    {
        return (bool) $this->get('taxonomy.enabled', true);
    }

    /**
     * Get custom tag overrides from config.
     *
     * @return array<string, list<string>>
     */
    public function taxonomyCustomTags(): array
    {
        /** @var array<string, list<string>> $tags */
        $tags = $this->get('taxonomy.custom_tags', []);

        return is_array($tags) ? $tags : [];
    }

    /**
     * Get disabled taxonomy tags from config.
     *
     * @return list<string>
     */
    public function taxonomyDisabledTags(): array
    {
        /** @var list<string> $tags */
        $tags = $this->get('taxonomy.disabled_tags', []);

        return is_array($tags) ? $tags : [];
    }

    // ── Event Cache ────────────────────────────────────────────────────

    /**
     * Check if event cache is enabled.
     */
    public function eventCacheEnabled(): bool
    {
        return (bool) $this->get('event_cache.enabled', true);
    }

    /**
     * Get max items for L1 memory cache.
     */
    public function eventCacheMemoryMaxItems(): int
    {
        return (int) $this->get('event_cache.memory_max_items', 500);
    }

    /**
     * Get L1 memory cache TTL in seconds.
     */
    public function eventCacheMemoryTtl(): int
    {
        return (int) $this->get('event_cache.memory_ttl', 300);
    }

    /**
     * Get L2 cache store TTL in seconds.
     */
    public function eventCacheTtl(): int
    {
        return (int) $this->get('event_cache.cache_ttl', 3600);
    }

    /**
     * Get event cache key prefix.
     */
    public function eventCachePrefix(): string
    {
        return (string) $this->get('event_cache.prefix', 'zb_analytics_');
    }

    /**
     * Check if provider telemetry is enabled.
     */
    public function telemetryEnabled(): bool
    {
        return (bool) $this->get('telemetry.enabled', false);
    }

    /**
     * Get telemetry cache TTL in seconds.
     */
    public function telemetryCacheTtl(): int
    {
        return (int) $this->get('telemetry.cache_ttl', 300);
    }

    /**
     * Get telemetry cache key prefix.
     */
    public function telemetryCachePrefix(): string
    {
        return (string) $this->get('telemetry.cache_prefix', 'zb_analytics_telemetry');
    }

    /**
     * Check if anonymization is enabled.
     */
    public function anonymizationEnabled(): bool
    {
        return (bool) $this->get('anonymization.enabled', false);
    }

    /**
     * Check if campaign ROI tracking is enabled.
     */
    public function campaignRoiEnabled(): bool
    {
        return (bool) $this->get('campaign_roi.enabled', false);
    }

    /**
     * Get campaign ROI cache TTL in seconds.
     */
    public function campaignRoiCacheTtl(): int
    {
        return (int) $this->get('campaign_roi.cache_ttl', 86400);
    }

    /**
     * Check if data minimization is enabled.
     */
    public function dataMinimizationEnabled(): bool
    {
        return (bool) $this->get('data_minimization.enabled', false);
    }

    /**
     * Get data minimization strip params list.
     *
     * @return list<string>
     */
    public function dataMinimizationStripParams(): array
    {
        /** @var list<string> $params */
        $params = $this->get('data_minimization.strip_params', []);

        return is_array($params) ? $params : [];
    }

    /**
     * Check if data minimization audit logging is enabled.
     */
    public function dataMinimizationAuditLog(): bool
    {
        return (bool) $this->get('data_minimization.audit_log', false);
    }

    /**
     * Check if event delivery confirmation is enabled.
     */
    public function deliveryConfirmationEnabled(): bool
    {
        return (bool) $this->get('delivery_confirmation.enabled', false);
    }

    /**
     * Get delivery confirmation critical events list.
     *
     * @return list<string>
     */
    public function deliveryConfirmationCriticalEvents(): array
    {
        /** @var list<string> $events */
        $events = $this->get('delivery_confirmation.critical_events', []);

        return is_array($events) ? $events : [];
    }

    /**
     * Get delivery confirmation token TTL in seconds.
     */
    public function deliveryConfirmationTokenTtl(): int
    {
        return (int) $this->get('delivery_confirmation.token_ttl', 300);
    }

    /**
     * Get scheduled reports enabled state.
     */
    public function scheduledReportEnabled(): bool
    {
        return (bool) $this->get('scheduled_reports.enabled', false);
    }

    /**
     * Get scheduled reports output path.
     */
    public function scheduledReportOutputPath(): string
    {
        return (string) $this->get('scheduled_reports.output_path', storage_path('analytics/reports'));
    }

    /**
     * Get journey tracking enabled state.
     */
    public function journeysEnabled(): bool
    {
        return (bool) $this->get('journeys.enabled', false);
    }

    /**
     * Get journey tracking cache TTL in seconds.
     */
    public function journeysCacheTtl(): int
    {
        return (int) $this->get('journeys.cache_ttl', 86400);
    }

    // ── Event Priority Gate ──────────────────────────────────────────

    /**
     * Check if event priority gate is enabled.
     */
    public function priorityEnabled(): bool
    {
        return (bool) $this->get('priority.enabled', true);
    }

    /**
     * Get priority rate limit for a given level.
     */
    public function priorityRateLimit(string $level): int
    {
        return (int) $this->get("priority.rate_limits.{$level}", 1000);
    }

    /**
     * Get priority gate cache TTL in seconds.
     */
    public function priorityCacheTtl(): int
    {
        return (int) $this->get('priority.cache_ttl', 60);
    }

    /**
     * Get priority gate cache prefix.
     */
    public function priorityCachePrefix(): string
    {
        return (string) $this->get('priority.cache_prefix', 'zb_priority_');
    }

    /**
     * Check if budget-aware mode is enabled for priority gate.
     */
    public function priorityBudgetAware(): bool
    {
        return (bool) $this->get('priority.budget_aware', true);
    }

    /**
     * Get priority gate budget threshold.
     */
    public function priorityBudgetThreshold(): int
    {
        return (int) $this->get('priority.budget_threshold', 5000);
    }

    /**
     * Get custom priority overrides from config.
     *
     * @return array<string, string>
     */
    public function priorityOverrides(): array
    {
        /** @var array<string, string> $overrides */
        $overrides = $this->get('priority.overrides', []);

        return is_array($overrides) ? $overrides : [];
    }

    // ── Sandbox ──────────────────────────────────────────────────────

    /**
     * Check if analytics sandbox is explicitly enabled.
     */
    public function sandboxEnabled(): ?bool
    {
        $enabled = $this->get('sandbox.enabled');

        return $enabled !== null ? (bool) $enabled : null;
    }

    /**
     * Check if sandbox auto-detection is enabled for local environment.
     */
    public function sandboxAutoLocal(): bool
    {
        return (bool) $this->get('sandbox.auto_local', true);
    }

    /**
     * Check if sandbox auto-detection is enabled for testing environment.
     */
    public function sandboxAutoTesting(): bool
    {
        return (bool) $this->get('sandbox.auto_testing', true);
    }

    /**
     * Check if sandbox staging log-only mode is enabled.
     */
    public function sandboxStagingLogOnly(): bool
    {
        return (bool) $this->get('sandbox.staging_log_only', true);
    }

    /**
     * Get sandbox max events capacity.
     */
    public function sandboxMaxEvents(): int
    {
        return (int) $this->get('sandbox.max_events', 5000);
    }

    /**
     * Get sandbox cache TTL in seconds.
     */
    public function sandboxCacheTtl(): int
    {
        return (int) $this->get('sandbox.cache_ttl', 86400);
    }

    // ── Provider Rate Limits ────────────────────────────────────────

    /**
     * Check if per-provider rate limiting is enabled.
     */
    public function providerRateLimitsEnabled(): bool
    {
        return (bool) $this->get('provider_rate_limits.enabled', false);
    }

    /**
     * Get per-provider rate limit overflow strategy.
     *
     * @return 'drop'|'buffer'|'downsample'
     */
    public function providerRateLimitsOverflow(): string
    {
        return (string) $this->get('provider_rate_limits.overflow_strategy', 'drop');
    }

    /**
     * Get rate limit for a specific provider.
     *
     * @param  string  $provider  Provider name (ga4, meta, plausible, posthog, webhook)
     */
    public function providerRateLimit(string $provider): int
    {
        return (int) $this->get("provider_rate_limits.providers.{$provider}.limit", 0);
    }

    // ── Schema Versioning ────────────────────────────────────────────

    /**
     * Check if event schema versioning is enabled.
     */
    public function schemaVersioningEnabled(): bool
    {
        return (bool) $this->get('schema_versioning.enabled', true);
    }

    /**
     * Get the schema version parameter name.
     */
    public function schemaVersioningParamName(): string
    {
        return (string) $this->get('schema_versioning.param_name', '_schema_version');
    }

    /**
     * Get the default schema version.
     */
    public function schemaVersioningDefault(): string
    {
        return (string) $this->get('schema_versioning.default_version', '1.0');
    }

    // ── Readiness ───────────────────────────────────────────────────

    /**
     * Check if readiness validation is enabled.
     */
    public function readinessEnabled(): bool
    {
        return (bool) $this->get('readiness.enabled', true);
    }

    /**
     * Get the minimum readiness score for production.
     */
    public function readinessMinimumScore(): int
    {
        return (int) $this->get('readiness.minimum_score', 80);
    }

    // ── Mixpanel (v64.0.0) ────────────────────────────────────────────

    public function mixpanelEnabled(): bool
    {
        return (bool) $this->get('mixpanel.enabled', false);
    }

    public function mixpanelToken(): string
    {
        return (string) $this->get('mixpanel.token', '');
    }

    public function mixpanelHost(): string
    {
        return (string) $this->get('mixpanel.host', 'https://api.mixpanel.com');
    }

    // ── Amplitude (v64.0.0) ─────────────────────────────────────────────

    public function amplitudeEnabled(): bool
    {
        return (bool) $this->get('amplitude.enabled', false);
    }

    public function amplitudeApiKey(): string
    {
        return (string) $this->get('amplitude.api_key', '');
    }

    public function amplitudeHost(): string
    {
        return (string) $this->get('amplitude.host', 'https://api2.amplitude.com');
    }

    // ── TikTok (v64.0.0) ──────────────────────────────────────────────

    public function tiktokEnabled(): bool
    {
        return (bool) $this->get('tiktok.enabled', false);
    }

    public function tiktokPixelId(): string
    {
        return (string) $this->get('tiktok.pixel_id', '');
    }

    // ── LinkedIn (v64.0.0) ────────────────────────────────────────────

    public function linkedinEnabled(): bool
    {
        return (bool) $this->get('linkedin.enabled', false);
    }

    public function linkedinPartnerId(): string
    {
        return (string) $this->get('linkedin.partner_id', '');
    }

    // ── Quick Diagnostic (v64.0.0) ──────────────────────────────────────

    /**
     * Get a list of currently enabled provider names.
     *
     * Useful for dashboards, diagnostic commands, and health checks.
     *
     * @return list<string>
     */
    public function enabledProviders(): array
    {
        $providers = [];

        if ($this->ga4Enabled()) {
            $providers[] = 'ga4';
        }

        if ($this->gtmEnabled()) {
            $providers[] = 'gtm';
        }

        if ($this->metaPixelEnabled()) {
            $providers[] = 'meta_pixel';
        }

        if ($this->plausibleEnabled()) {
            $providers[] = 'plausible';
        }

        if ($this->posthogEnabled()) {
            $providers[] = 'posthog';
        }

        if ($this->mixpanelEnabled()) {
            $providers[] = 'mixpanel';
        }

        if ($this->amplitudeEnabled()) {
            $providers[] = 'amplitude';
        }

        if ($this->tiktokEnabled()) {
            $providers[] = 'tiktok';
        }

        if ($this->linkedinEnabled()) {
            $providers[] = 'linkedin';
        }

        if ($this->webhookEnabled()) {
            $providers[] = 'webhook';
        }

        return $providers;
    }

    /**
     * Get a compact, single-level config summary for diagnostics.
     *
     * Unlike summary() which returns nested arrays per section,
     * this returns a flat associative array with the most important
     * values for quick diagnostic display, CLI output, and health checks.
     *
     * @return array<string, mixed>
     */
    public function compactSummary(): array
    {
        return [
            'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
            'providers' => $this->enabledProviders(),
            'provider_count' => count($this->enabledProviders()),
            'consent_default' => $this->consentDefault(),
            'queue_enabled' => $this->queueEnabled(),
            'queue_name' => $this->queueName(),
            'auto_track' => $this->autoTrackEnabled(),
            'api_enabled' => $this->apiEnabled(),
            'api_base_url' => $this->apiBaseUrl(),
            'identity_cookie' => $this->identityCookieName(),
            'ecommerce_currency' => $this->ecommerceCurrency(),
            'sampling_enabled' => $this->samplingEnabled(),
            'sampling_rate' => $this->samplingRate(),
            'pii_enabled' => $this->piiEnabled(),
            'debug_enabled' => $this->debugEnabled(),
            'validation_strict' => $this->validationStrict(),
            'replay_enabled' => $this->replayEnabled(),
            'fingerprint_enabled' => (bool) $this->get('fingerprint.enabled', true),
            'blueprints_enabled' => $this->blueprintsEnabled(),
            'segment_export_enabled' => $this->segmentExportEnabled(),
            'lifecycle_hooks_enabled' => $this->lifecycleHooksEnabled(),
            'event_count' => \ZeroBoiler\Analytics\Events\EventCatalog::count(),
            'event_categories' => count(\ZeroBoiler\Analytics\Events\EventCatalog::byCategory()),
            'rum_enabled' => $this->rumEnabled(),
            'inspector_enabled' => $this->inspectorEnabled(),
        ];
    }

    // ── RUM — Real User Monitoring (v68.0.0) ──────────────────────────

    public function rumEnabled(): bool
    {
        return (bool) $this->get('rum.enabled', false);
    }

    public function rumMaxSamples(): int
    {
        return (int) $this->get('rum.max_samples', 10000);
    }

    public function rumTtl(): int
    {
        return (int) $this->get('rum.ttl', 86400);
    }

    public function rumWindow(): string
    {
        return (string) $this->get('rum.window', '24h');
    }

    public function rumAlertingEnabled(): bool
    {
        return (bool) $this->get('rum.alerting_enabled', true);
    }

    // ── Event Inspector (v68.0.0) ─────────────────────────────────────

    public function inspectorEnabled(): bool
    {
        return (bool) $this->get('inspector.enabled', false);
    }

    public function inspectorMaxTraces(): int
    {
        return (int) $this->get('inspector.max_traces', 500);
    }

    public function inspectorTtl(): int
    {
        return (int) $this->get('inspector.ttl', 300);
    }

    // ── Event Validation Pipeline (v69.0.0) ──────────────────────────

    public function validationPipelineEnabled(): bool
    {
        return (bool) $this->get('validation_pipeline.enabled', true);
    }

    public function validationPipelineFailFast(): bool
    {
        return (bool) $this->get('validation_pipeline.fail_fast', false);
    }

    /** @return array<string, mixed> */
    public function validationPipelineConfig(): array
    {
        return (array) $this->get('validation_pipeline', []);
    }

    public function vpCatalogMembershipEnabled(): bool
    {
        return (bool) $this->get('validation_pipeline.catalog_membership.enforce_membership', true);
    }

    public function vpSchemaValidationEnabled(): bool
    {
        return (bool) $this->get('validation_pipeline.schema_validation.enabled', false);
    }

    public function vpPiiScanningEnabled(): bool
    {
        return (bool) $this->get('validation_pipeline.pii_scanning.enabled', true);
    }

    public function vpDataQualityEnabled(): bool
    {
        return (bool) $this->get('validation_pipeline.data_quality.enabled', true);
    }

    public function vpComplianceEnabled(): bool
    {
        return (bool) $this->get('validation_pipeline.compliance.enabled', true);
    }

    // ── Event Payload Transformation Engine (v70.0.0) ──────────────

    public function transformationEnabled(): bool
    {
        return (bool) $this->get('transformation.enabled', true);
    }

    public function transformationCacheTtl(): int
    {
        return (int) $this->get('transformation.cache_ttl', 3600);
    }

    public function transformationStrict(): bool
    {
        return (bool) $this->get('transformation.strict', false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function transformationMappings(): array
    {
        $mappings = $this->get('transformation.mappings', []);

        return is_array($mappings) ? $mappings : [];
    }

    // ── Intelligence Gateway (v71.0.0) ────────────────────────────────

    public function intelligenceEnabled(): bool
    {
        return (bool) $this->get('intelligence.enabled', true);
    }

    public function intelligenceCacheTtl(): int
    {
        return (int) $this->get('intelligence.cache_ttl', 60);
    }

    public function intelligenceAlertThreshold(): int
    {
        return (int) $this->get('intelligence.alert_threshold', 60);
    }

    /**
     * @return list<string>
     */
    public function intelligenceDisabledSections(): array
    {
        $sections = $this->get('intelligence.disabled_sections', []);

        return is_array($sections) ? $sections : [];
    }

    // ── Geographic Analytics (v73.0.0) ────────────────────────────

    public function geoAnalyticsEnabled(): bool
    {
        return (bool) $this->get('geographic_analytics.enabled', true);
    }

    public function geoAnalyticsCacheTtl(): int
    {
        return (int) $this->get('geographic_analytics.cache_ttl', 300);
    }

    public function geoAnalyticsTopRegionsLimit(): int
    {
        return (int) $this->get('geographic_analytics.top_regions_limit', 20);
    }

    public function geoAnalyticsTopEventsPerRegion(): int
    {
        return (int) $this->get('geographic_analytics.top_events_per_region', 5);
    }

    public function geoAnalyticsAnomalyThreshold(): int
    {
        return (int) $this->get('geographic_analytics.anomaly_threshold_multiplier', 3);
    }

    public function geoAnalyticsEngagementWeightEvents(): float
    {
        return (float) $this->get('geographic_analytics.engagement_weight_events', 0.4);
    }

    public function geoAnalyticsEngagementWeightUsers(): float
    {
        return (float) $this->get('geographic_analytics.engagement_weight_users', 0.4);
    }

    public function geoAnalyticsEngagementWeightSessions(): float
    {
        return (float) $this->get('geographic_analytics.engagement_weight_sessions', 0.2);
    }

    /**
     * Get event health scoring configuration.
     *
     * @return array{enabled: bool, freshness_threshold: int, volume_drop_threshold: float, volume_spike_multiplier: float, min_volume_sample: int}
     */
    public function eventHealthConfig(): array
    {
        return [
            'enabled' => (bool) $this->get('event_health.enabled', true),
            'freshness_threshold' => (int) $this->get('event_health.freshness_threshold', 3600),
            'volume_drop_threshold' => (float) $this->get('event_health.volume_drop_threshold', 0.3),
            'volume_spike_multiplier' => (float) $this->get('event_health.volume_spike_multiplier', 5.0),
            'min_volume_sample' => (int) $this->get('event_health.min_volume_sample', 10),
        ];
    }

    /**
     * Get deploy gate configuration.
     *
     * @return array{block_on_warnings: bool, min_health_score: int, skip_events: list<string>}
     */
    public function deployGateConfig(): array
    {
        return [
            'block_on_warnings' => (bool) $this->get('deploy_gate.block_on_warnings', false),
            'min_health_score' => (int) $this->get('deploy_gate.min_health_score', 40),
            'skip_events' => (array) $this->get('deploy_gate.skip_events', []),
        ];
    }

    public function eventHealthEnabled(): bool
    {
        return (bool) $this->get('event_health.enabled', true);
    }

    public function eventHealthFreshnessThreshold(): int
    {
        return (int) $this->get('event_health.freshness_threshold', 3600);
    }

    public function deployGateBlockOnWarnings(): bool
    {
        return (bool) $this->get('deploy_gate.block_on_warnings', false);
    }

    public function deployGateMinHealthScore(): int
    {
        return (int) $this->get('deploy_gate.min_health_score', 40);
    }
}
