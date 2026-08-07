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
 */
final class AnalyticsConfig
{
    private const CONFIG_KEY = 'zeroboiler.analytics';

    /**
     * @param  ConfigRepository  $config  Laravel config repository
     */
    public function __construct(
        private readonly ConfigRepository $config,
    ): void {}

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
            'consent' => [
                'default' => $this->consentDefault(),
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
            'broadcast' => [
                'enabled' => $this->broadcastEnabled(),
                'channel_prefix' => $this->broadcastChannelPrefix(),
                'private_channels' => $this->broadcastPrivateChannels(),
                'alert_channel' => $this->broadcastAlertChannel(),
                'metrics_channel' => $this->broadcastMetricsChannel(),
            ],
            'retention_policy' => [
                'enabled' => $this->retentionPolicyEnabled(),
                'auto_expire' => $this->retentionPolicyAutoExpire(),
                'pii_categories' => $this->retentionPolicyPiiCategories(),
                'engagement_days' => $this->retentionPolicyEngagementDays(),
                'saas_days' => $this->retentionPolicySaasDays(),
                'ecommerce_days' => $this->retentionPolicyEcommerceDays(),
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
        ];
    }
}
