<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

return [
    'analytics' => [
        'ga4' => [
            'enabled' => env('ANALYTICS_GA4_ENABLED', false),
            'measurement_id' => env('ANALYTICS_GA4_MEASUREMENT_ID', ''),
            'api_secret' => env('ANALYTICS_GA4_API_SECRET', ''),
        ],
        'gtm' => [
            'enabled' => env('ANALYTICS_GTM_ENABLED', false),
            'container_id' => env('ANALYTICS_GTM_CONTAINER_ID', ''),
        ],
        'meta_pixel' => [
            'enabled' => env('ANALYTICS_META_PIXEL_ENABLED', false),
            'id' => env('ANALYTICS_META_PIXEL_ID', ''),
            'access_token' => env('ANALYTICS_META_PIXEL_ACCESS_TOKEN', ''),
        ],

        /*
        |--------------------------------------------------------------------------
        | Consent Mode (GDPR)
        |--------------------------------------------------------------------------
        |
        | Default consent state applied to all trackers on initialization.
        | Set to 'denied' for GDPR-safe defaults (users must explicitly opt-in).
        | Options: 'granted', 'denied'
        |
        */
        'consent' => [
            'default' => env('ANALYTICS_CONSENT_DEFAULT', 'granted'),

            /*
            | Granular Consent Purposes (GDPR)
            |
            | Define consent purposes exposed to the frontend via Inertia props.
            | 'necessary' is always granted and cannot be denied.
            | Other purposes follow the 'default' setting above.
            |
            */
            'purposes' => [
                'necessary' => ['label' => 'Necessary', 'required' => true, 'default' => true],
                'analytics' => ['label' => 'Analytics', 'required' => false, 'default' => true],
                'marketing' => ['label' => 'Marketing', 'required' => false, 'default' => false],
                'functional' => ['label' => 'Functional', 'required' => false, 'default' => true],
            ],
            'log_enabled' => env('ANALYTICS_CONSENT_LOG_ENABLED', false),
            'log_ttl' => (int) env('ANALYTICS_CONSENT_LOG_TTL', 7776000), // 90 days
        ],

        /*
        |--------------------------------------------------------------------------
        | Auto-Track (Server-Side Event Tracking)
        |--------------------------------------------------------------------------
        |
        | Automatically track Laravel framework events as analytics events.
        | Toggle individual events on/off. Set 'enabled' to false to disable
        | all server-side auto-tracking.
        |
        */
        'auto_track' => [
            'enabled' => env('ANALYTICS_AUTO_TRACK_ENABLED', true),
            'events' => [
                'auth.login' => true,
                'auth.register' => true,
                'auth.logout' => false,
                'subscription.created' => true,
                'subscription.upgraded' => true,
                'subscription.cancelled' => true,
                'trial.started' => true,
                'trial.ended' => false,
                'feature.used' => false,
            ],
            'models' => [
                // Track Eloquent model events as analytics events
                // Example: App\\Models\\Habit::class => ['created', 'deleted'],
            ],
            /*
            |------------------------------------------------------------------
            | Custom Event Map (Config-Driven)
            |------------------------------------------------------------------
            |
            | Map custom application event names to analytics event classes.
            | These are merged with the built-in event map in ServerSideTracker.
            | Classes must extend ZeroBoiler\Analytics\DTO\AnalyticsEvent.
            |
            | Example:
            |   'event_map' => [
            |       'team.invited' => \App\Analytics\Events\TeamInvitedEvent::class,
            |       'workspace.created' => \App\Analytics\Events\WorkspaceCreatedEvent::class,
            |   ],
            |
            */
            'event_map' => [],
        ],

        /*
        |--------------------------------------------------------------------------
        | Queue (Async Event Dispatch)
        |--------------------------------------------------------------------------
        |
        | When enabled, analytics events dispatched via QueuedAnalyticsDispatcher
        | are sent asynchronously through a background queue worker.
        | Set to false for synchronous (blocking) dispatch.
        |
        */
        'queue' => [
            'enabled' => env('ANALYTICS_QUEUE_ENABLED', true),
            'queue' => env('ANALYTICS_QUEUE', 'analytics'),
            'connection' => env('ANALYTICS_QUEUE_CONNECTION'),
        ],
        /*
        |--------------------------------------------------------------------------
        | Identity Tracking
        |--------------------------------------------------------------------------
        |
        | Server-generated tracking ID stored in a cookie for client/server
        | matching. Used by the Inertia middleware and API endpoints.
        |
        */
        'identity' => [
            'cookie_name' => env('ANALYTICS_IDENTITY_COOKIE', 'zb_analytics_id'),
            'cookie_ttl' => env('ANALYTICS_IDENTITY_COOKIE_TTL', 525600), // 365 days (minutes)
            'cookie_secure' => env('ANALYTICS_IDENTITY_COOKIE_SECURE', true),
            'cookie_samesite' => env('ANALYTICS_IDENTITY_COOKIE_SAMESITE', 'Lax'),
        ],

        /*
        |--------------------------------------------------------------------------
        | E-commerce
        |--------------------------------------------------------------------------
        |
        | Default settings for e-commerce event tracking.
        | Used by EcommerceAnalyticsService when no override is provided.
        |
        */
        'ecommerce' => [
            'currency' => env('ANALYTICS_ECOMMERCE_CURRENCY', 'USD'),
            'brand' => env('ANALYTICS_ECOMMERCE_BRAND', ''),
            'tax_behavior' => env('ANALYTICS_ECOMMERCE_TAX_BEHAVIOR', 'inclusive'), // inclusive, exclusive, not_specified
            'shipping_default' => (float) env('ANALYTICS_ECOMMERCE_SHIPPING_DEFAULT', 0.0),
        ],

        /*
        |--------------------------------------------------------------------------
        | SaaS Revenue Defaults
        |--------------------------------------------------------------------------
        |
        | Default settings for SaaS revenue tracking events.
        | Used by RevenueAnalyticsService when no override is provided.
        |
        */
        'revenue' => [
            'currency' => env('ANALYTICS_REVENUE_CURRENCY', 'USD'),
            'billing_cycle_default' => env('ANALYTICS_REVENUE_BILLING_CYCLE', 'monthly'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Auto-Track Links (Client-Side)
        |--------------------------------------------------------------------------
        |
        | Configure automatic link click tracking in the JS client.
        | When enabled, the Inertia middleware exposes these settings to the frontend.
        |
        */
        'track_links' => [
            'enabled' => env('ANALYTICS_TRACK_LINKS_ENABLED', false),
            'track_external' => env('ANALYTICS_TRACK_LINKS_EXTERNAL', true),
            'track_internal' => env('ANALYTICS_TRACK_LINKS_INTERNAL', false),
            'external_prefix' => env('ANALYTICS_TRACK_LINKS_PREFIX', 'outbound'),
        ],

        /*
        |--------------------------------------------------------------------------
        | API (Frontend Event Tracking)
        |--------------------------------------------------------------------------
        |
        | Configuration for the server-side API endpoints that accept events
        | from the JS client library.
        |
        */
        'api' => [
            'enabled' => env('ANALYTICS_API_ENABLED', true),
            'throttle' => env('ANALYTICS_API_THROTTLE', 60),
            'base_url' => env('ANALYTICS_API_BASE_URL', '/api/analytics'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Plausible Analytics (Optional)
        |--------------------------------------------------------------------------
        |
        | Privacy-focused analytics via server-side API. No cookies required.
        | Enable by setting ANALYTICS_PLAUSIBLE_ENABLED=true and providing
        | your site domain and API key.
        |
        */
        'plausible' => [
            'enabled' => env('ANALYTICS_PLAUSIBLE_ENABLED', false),
            'domain' => env('ANALYTICS_PLAUSIBLE_DOMAIN', ''),
            'api_key' => env('ANALYTICS_PLAUSIBLE_API_KEY', ''),
            'base_url' => env('ANALYTICS_PLAUSIBLE_BASE_URL', 'https://plausible.io/api/event'),
        ],

        /*
        |--------------------------------------------------------------------------
        | PostHog (Optional)
        |--------------------------------------------------------------------------
        |
        | Product analytics with feature flags via server-side capture endpoint.
        | Enable by setting ANALYTICS_POSTHOG_ENABLED=true and providing
        | your API key.
        |
        */
        'posthog' => [
            'enabled' => env('ANALYTICS_POSTHOG_ENABLED', false),
            'api_key' => env('ANALYTICS_POSTHOG_API_KEY', ''),
            'host' => env('ANALYTICS_POSTHOG_HOST', 'https://eu.posthog.com'),
            'project_id' => env('ANALYTICS_POSTHOG_PROJECT_ID', ''),
        ],

        /*
        |--------------------------------------------------------------------------
        | Webhook Tracker (Custom HTTP Backend)
        |--------------------------------------------------------------------------
        |
        | Send analytics events to a custom HTTP endpoint via POST requests.
        | Supports HMAC-SHA256 payload signing for verification.
        | Ideal for forwarding events to internal data warehouses or custom dashboards.
        |
        */
        'webhook' => [
            'enabled' => env('ANALYTICS_WEBHOOK_ENABLED', false),
            'url' => env('ANALYTICS_WEBHOOK_URL', ''),
            'secret' => env('ANALYTICS_WEBHOOK_SECRET', ''),
            'timeout' => (int) env('ANALYTICS_WEBHOOK_TIMEOUT', 5),
            'retries' => (int) env('ANALYTICS_WEBHOOK_RETRIES', 1),
            'sign' => env('ANALYTICS_WEBHOOK_SIGN', false),
            'headers' => [],
        ],

        /*
        |--------------------------------------------------------------------------
        | Audit Logging
        |--------------------------------------------------------------------------
        |
        | When enabled, every dispatched event is logged to the 'daily' log channel
        | with structured context (event name, client_id, user_id, params, timestamp).
        | Useful for compliance, debugging, and building custom analytics dashboards.
        |
        */
        'audit_log' => [
            'enabled' => env('ANALYTICS_AUDIT_LOG_ENABLED', false),
            'priority' => (int) env('ANALYTICS_AUDIT_LOG_PRIORITY', 100),
        ],

        /*
        |--------------------------------------------------------------------------
        | Debug Mode
        |--------------------------------------------------------------------------
        |
        | When enabled, events are logged but not sent to providers.
        | Useful for development and debugging.
        |
        */
        'debug' => [
            'enabled' => env('ANALYTICS_DEBUG_ENABLED', false),
            'log_events' => env('ANALYTICS_DEBUG_LOG_EVENTS', false),
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Validation
        |--------------------------------------------------------------------------
        |
        | Configure event name validation and deduplication.
        | In strict mode, only whitelisted event names are accepted.
        | Deduplication prevents the same event from being sent multiple times
        | within a configurable time window.
        |
        */
        'validation' => [
            'strict' => env('ANALYTICS_VALIDATION_STRICT', false),
            'whitelist' => [], // Add allowed event names, e.g. ['page_view', 'purchase']
            'max_event_name_length' => (int) env('ANALYTICS_VALIDATION_MAX_NAME_LENGTH', 100),
            'max_param_key_length' => (int) env('ANALYTICS_VALIDATION_MAX_PARAM_KEY_LENGTH', 100),
            'deduplication_window' => (int) env('ANALYTICS_VALIDATION_DEDUP_WINDOW', 10),
            'max_recent_events' => (int) env('ANALYTICS_VALIDATION_MAX_RECENT', 500),
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Pipeline
        |--------------------------------------------------------------------------
        |
        | Configure the event processing pipeline.
        | When auto_utm is enabled, UTM parameters from requests are automatically
        | attached to all events.
        |
        */
        'pipeline' => [
            'auto_utm' => env('ANALYTICS_PIPELINE_AUTO_UTM', true),
            'auto_timestamp' => env('ANALYTICS_PIPELINE_AUTO_TIMESTAMP', false),
            'auto_metadata' => env('ANALYTICS_PIPELINE_AUTO_METADATA', true),
            'schema_enrichment' => env('ANALYTICS_PIPELINE_SCHEMA_ENRICHMENT', false),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Sampling (High-Traffic Control)
        |-------------------------------------------------------------------------- 
        |
        | Drop events probabilistically to control volume during traffic spikes.
        | Rate 1.0 = no sampling (all events), 0.1 = ~10% of events processed.
        | Deterministic mode ensures the same event names are consistently sampled.
        |
        */
        'sampling' => [
            'enabled' => env('ANALYTICS_SAMPLING_ENABLED', false),
            'rate' => (float) env('ANALYTICS_SAMPLING_RATE', 1.0),
            'deterministic' => env('ANALYTICS_SAMPLING_DETERMINISTIC', true),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | PII Sanitization
        |-------------------------------------------------------------------------- 
        |
        | Automatically sanitize Personally Identifiable Information from event
        | parameters before dispatch. Strategies: hash (SHA-256), remove, mask.
        |
        */
        'pii_sanitization' => [
            'enabled' => env('ANALYTICS_PII_ENABLED', false),
            'strategy' => env('ANALYTICS_PII_STRATEGY', 'hash'), // hash, remove, mask
            'custom_fields' => [],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Replay Queue (Failed Event Retry)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, events that fail to dispatch are automatically retried
        | with exponential backoff and jitter. Prevents data loss during
        | transient provider outages.
        |
        */
        'replay' => [
            'enabled' => env('ANALYTICS_REPLAY_ENABLED', true),
            'max_attempts' => (int) env('ANALYTICS_REPLAY_MAX_ATTEMPTS', 3),
            'base_delay' => (float) env('ANALYTICS_REPLAY_BASE_DELAY', 1.0),
            'max_delay' => (float) env('ANALYTICS_REPLAY_MAX_DELAY', 60.0),
            'jitter' => (float) env('ANALYTICS_REPLAY_JITTER', 0.2),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Metrics & Observability
        |-------------------------------------------------------------------------- 
        |
        | Track event dispatch counts, success/failure rates, and per-provider
        | statistics for monitoring and debugging. Disabled by default for
        | zero-overhead in production.
        |
        */
        'metrics' => [
            'enabled' => env('ANALYTICS_METRICS_ENABLED', false),
            'log_on_flush' => env('ANALYTICS_METRICS_LOG_ON_FLUSH', false),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Stream (Real-Time Dashboard)
        |-------------------------------------------------------------------------- 
        |
        | In-memory ring buffer for real-time event streaming to dashboards.
        | The buffer stores recent events that can be polled via the
        | GET /api/analytics/stream endpoint. Configure the buffer size
        | based on your dashboard's polling frequency and data requirements.
        |
        */
        'stream' => [
            'buffer_size' => (int) env('ANALYTICS_STREAM_BUFFER_SIZE', 1000),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Client-Side Auto-Tracking
        |-------------------------------------------------------------------------- 
        |
        | Config-driven settings exposed to the JS client via Inertia props.
        | When initAll() is called on the client, these settings determine
        | which auto-trackers are enabled. Individual trackers can be
        | toggled on/off without code changes.
        |
        */
        'client_auto_track' => [
            'page_views' => env('ANALYTICS_CLIENT_PAGE_VIEWS', true),
            'scroll_depth' => env('ANALYTICS_CLIENT_SCROLL_DEPTH', true),
            'form_tracking' => env('ANALYTICS_CLIENT_FORM_TRACKING', true),
            'error_tracking' => env('ANALYTICS_CLIENT_ERROR_TRACKING', true),
            'link_tracking' => env('ANALYTICS_CLIENT_LINK_TRACKING', false),
            'session_tracking' => env('ANALYTICS_CLIENT_SESSION_TRACKING', true),
            'idle_timeout' => (int) env('ANALYTICS_CLIENT_IDLE_TIMEOUT', 1800), // 30 minutes
            'error_ignore_patterns' => [
                'ResizeObserver',
                'Non-Error promise rejection',
                'Script error',
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Performance Tracking (Core Web Vitals)
        |-------------------------------------------------------------------------- 
        |
        | Settings for client-side performance monitoring.
        | When enabled, the Inertia middleware exposes these settings
        | so the JS client can initialize Web Vitals tracking.
        |
        */
        'performance' => [
            'enabled' => env('ANALYTICS_PERFORMANCE_ENABLED', false),
            'track_lcp' => env('ANALYTICS_PERFORMANCE_LCP', true),
            'track_fid' => env('ANALYTICS_PERFORMANCE_FID', true),
            'track_cls' => env('ANALYTICS_PERFORMANCE_CLS', true),
            'track_inp' => env('ANALYTICS_PERFORMANCE_INP', true),
            'track_ttfb' => env('ANALYTICS_PERFORMANCE_TTFB', true),
            'track_fcp' => env('ANALYTICS_PERFORMANCE_FCP', false),
            'send_to_server' => env('ANALYTICS_PERFORMANCE_SERVER', true),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Tracking Preferences (Per-User Opt-Out)
        |-------------------------------------------------------------------------- 
        |
        | Per-user tracking preferences persisted in cache. Unlike consent signals
        | (which control cookie/storage permissions), tracking preferences suppress
        | all event dispatch, even when consent is granted.
        |
        | Use Analytics::optOut($userId) to persist user preferences.
        |
        */
        'tracking_preference' => [
            'ttl' => (int) env('ANALYTICS_TRACKING_PREF_TTL', 604800), // 7 days (seconds)
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Deduplication
        |-------------------------------------------------------------------------- 
        |
        | When enabled, events with the same fingerprint (name + client ID + user ID +
        | params hash) are deduplicated within a configurable time window. Uses the
        | application cache driver to track recent event fingerprints.
        |
        */
        'dedup' => [
            'enabled' => env('ANALYTICS_DEDUP_ENABLED', true),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | GDPR Compliance
        |-------------------------------------------------------------------------- 
        |
        | IP anonymization for analytics events. When enabled, the last octets of IPv4
        | and the last bits of IPv6 addresses are masked before inclusion in events.
        | ip_mask_v4: number of octets to preserve (2 = keep first 2, e.g. 192.168.0.0)
        | ip_mask_v6: number of bits to preserve (48 = keep first 3 groups)
        |
        */
        'gdpr' => [
            'anonymize_ip' => env('ANALYTICS_GDPR_ANONYMIZE_IP', false),
            'ip_mask_v4' => (int) env('ANALYTICS_GDPR_IP_MASK_V4', 2),
            'ip_mask_v6' => (int) env('ANALYTICS_GDPR_IP_MASK_V6', 48),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Attribution Tracking (First-Touch / Multi-Touch)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, UTM parameters from incoming requests are captured and
        | persisted as first-touch and multi-touch attribution data.
        | First-touch attribution is stored for 30 days by default.
        | Touch history keeps the most recent 20 touchpoints.
        |
        */
        'attribution' => [
            'enabled' => env('ANALYTICS_ATTRIBUTION_ENABLED', true),
            'model' => env('ANALYTICS_ATTRIBUTION_MODEL', 'last_touch'), // first_touch, last_touch, multi_touch
            'session_window_days' => (int) env('ANALYTICS_ATTRIBUTION_WINDOW', 30),
            'cache_ttl' => (int) env('ANALYTICS_ATTRIBUTION_CACHE_TTL', 86400),
            'first_touch_ttl' => (int) env('ANALYTICS_ATTRIBUTION_FIRST_TOUCH_TTL', 2592000), // 30 days
            'touch_history_ttl' => (int) env('ANALYTICS_ATTRIBUTION_TOUCH_HISTORY_TTL', 2592000), // 30 days
            'max_touch_history' => (int) env('ANALYTICS_ATTRIBUTION_MAX_HISTORY', 20),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Profile Aggregation
        |-------------------------------------------------------------------------- 
        |
        | When enabled, per-user analytics profiles are built from tracked events.
        | Profiles include lifetime value, event counts, engagement scores,
        | funnel completion, and user traits.
        |
        */
        'profile' => [
            'enabled' => env('ANALYTICS_PROFILE_ENABLED', true),
            'ttl' => (int) env('ANALYTICS_PROFILE_TTL', 86400), // 24 hours
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Inbound Webhook (External Event Ingestion)
        |-------------------------------------------------------------------------- 
        |
        | Receive analytics events from external sources via a webhook endpoint.
        | Supports HMAC-SHA256 signature verification for secure ingestion.
        | Typical sources: Stripe webhooks, payment processors, partner integrations.
        |
        */
        'inbound_webhook' => [
            'enabled' => env('ANALYTICS_INBOUND_WEBHOOK_ENABLED', false),
            'secret' => env('ANALYTICS_INBOUND_WEBHOOK_SECRET', ''),
            'require_signature' => env('ANALYTICS_INBOUND_WEBHOOK_REQUIRE_SIGNATURE', true),
            'max_payload_size' => (int) env('ANALYTICS_INBOUND_WEBHOOK_MAX_PAYLOAD', 65536), // 64KB
            'max_events' => (int) env('ANALYTICS_INBOUND_WEBHOOK_MAX_EVENTS', 50),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Funnel Tracking
        |-------------------------------------------------------------------------- 
        |
        | When enabled, SaasFunnelService tracks complete user lifecycle funnels
        | (signup, trial, conversion, retention, expansion) with funnel-named events.
        |
        */
        'funnels' => [
            'enabled' => env('ANALYTICS_FUNNELS_ENABLED', true),
            'cache_enabled' => env('ANALYTICS_FUNNELS_CACHE_ENABLED', true),
            'cache_ttl' => env('ANALYTICS_FUNNELS_CACHE_TTL', 300), // 5 minutes
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Alert Rules
        |-------------------------------------------------------------------------- 
        |
        | Config-driven alert rules that trigger when event metrics exceed
        | configured thresholds. Supports rate-based, count-based, and error-rate
        | alerts with cooldown periods to prevent alert fatigue.
        |
        | Rule types: 'count' (total event count), 'rate' (events/minute),
        |             'total' (total dispatched), 'error_rate' (errors/total %)
        | Conditions: 'gt' (>), 'gte' (>=), 'lt' (<), 'lte' (<=), 'eq' (==)
        |
        | Example rule:
        |   'high_purchase_rate' => [
        |       'type' => 'rate',
        |       'event' => 'purchase',
        |       'condition' => 'gt',
        |       'threshold' => 100,
        |       'window' => 60,
        |       'cooldown' => 600,
        |       'severity' => 'warning',
        |       'message' => 'Purchase rate exceeds 100/min',
        |       'dispatch' => true,
        |   ],
        |
        */
        'alerts' => [
            'enabled' => env('ANALYTICS_ALERTS_ENABLED', true),
            'cooldown' => env('ANALYTICS_ALERTS_COOLDOWN', 300), // 5 minutes
            'max_history' => (int) env('ANALYTICS_ALERTS_MAX_HISTORY', 200),
            'rules' => [
                'high_error_rate' => [
                    'type' => 'error_rate',
                    'condition' => 'gt',
                    'threshold' => 5.0, // 5% error rate
                    'cooldown' => 600, // 10 minutes
                    'severity' => 'elevated',
                    'message' => 'Error rate exceeds 5% of total events',
                    'dispatch' => true,
                ],
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Lifecycle Event Mapping
        |--------------------------------------------------------------------------
        |
        | Config-driven mapping of application events to analytics events.
        | LifecycleEventMapper automatically maps Laravel auth events, subscription
        | lifecycle events, trial events, feature usage, e-commerce, and engagement
        | events to typed ZeroBoiler analytics events.
        |
        | Toggle individual events on/off. Set 'enabled' to false to disable
        | the entire lifecycle mapper.
        |
        | Add custom mappings in 'custom_mappings' to extend beyond defaults.
        | Set 'override_defaults' to true to replace all built-in mappings.
        |
        */
        'lifecycle' => [
            'enabled' => env('ANALYTICS_LIFECYCLE_ENABLED', true),
            'override_defaults' => env('ANALYTICS_LIFECYCLE_OVERRIDE_DEFAULTS', false),
            'events' => [
                // ── Authentication ───────────────────────────────
                'auth.login' => true,
                'auth.register' => true,
                'auth.logout' => false,

                // ── Subscription ──────────────────────────────
                'subscription.created' => true,
                'subscription.upgraded' => true,
                'subscription.downgraded' => true,
                'subscription.cancelled' => true,
                'subscription.renewal' => true,

                // ── Trial ───────────────────────────────────────
                'trial.started' => true,
                'trial.ended' => false,

                // ── Feature Usage ───────────────────────────────
                'feature.used' => false,
                'feature.limit_reached' => true,

                // ── E-commerce ──────────────────────────────────
                'order.completed' => true,
                'order.refunded' => true,

                // ── Engagement ──────────────────────────────────
                'form.submitted' => false,
                'search.performed' => false,
                'error.occurred' => false,

                // ── Account Lifecycle ───────────────────────────
                'account.activated' => true,
                'account.deactivated' => true,
                'account.email_verified' => true,
                'account.password_changed' => false,
                'account.password_reset' => true,
                'account.profile_updated' => false,

                // ── B2B / Team ──────────────────────────────────
                'team.created' => true,
                'team.member_joined' => true,
                'team.member_removed' => true,
                'team.role_changed' => true,
                'team.invite_sent' => true,

                // ── Billing ─────────────────────────────────────
                'billing.payment_succeeded' => true,
                'billing.payment_failed' => true,
                'billing.payment_method_added' => false,
                'billing.invoice_generated' => false,
                'billing.credit_applied' => false,

                // ── Integrations ─────────────────────────────────
                'integration.connected' => true,
                'integration.failed' => true,
            ],
            /*
            | Custom event mappings (merged with or override defaults).
            | Each mapping: source event name → target analytics event class.
            |
            | Example:
            |   'custom_mappings' => [
            |       'team.invited' => [
            |           'source' => 'team.invited',
            |           'target' => \App\Analytics\Events\TeamInvitedEvent::class,
            |           'params_extractor' => 'extractTeamParams',
            |           'priority' => 90,
            |       ],
            |   ],
            */
            'custom_mappings' => [],
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Correlation & Pattern Detection
        |--------------------------------------------------------------------------
        |
        | Analyzes user journeys to detect frequent event patterns, calculate
        | transition probabilities, and predict next events. Useful for
        | understanding user behavior and optimizing conversion paths.
        |
        | Pattern analysis is cached for performance. Adjust cache_ttl and
        | max_pattern_length based on your traffic volume.
        |
        */
        'correlation' => [
            'enabled' => env('ANALYTICS_CORRELATION_ENABLED', true),
            'cache_enabled' => env('ANALYTICS_CORRELATION_CACHE_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_CORRELATION_CACHE_TTL', 300), // 5 minutes
            'max_pattern_length' => (int) env('ANALYTICS_CORRELATION_MAX_PATTERN_LENGTH', 5),
            'max_journeys_per_user' => (int) env('ANALYTICS_CORRELATION_MAX_JOURNEYS', 100),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Data Retention
        |-------------------------------------------------------------------------- 
        |
        | Configure event data retention policies. When enabled, the package
        | exposes retention metadata so downstream storage layers (database,
        | data warehouse) can automatically purge old analytics events.
        |
        | The retention_days value controls how long event data is considered
        | "active". Events older than this should be archived or deleted.
        |
        */
        'retention' => [
            'enabled' => env('ANALYTICS_RETENTION_ENABLED', false),
            'days' => (int) env('ANALYTICS_RETENTION_DAYS', 90), // 90 days default
            'archive_action' => env('ANALYTICS_RETENTION_ARCHIVE', 'delete'), // delete, archive, anonymize
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Source Tagging
        |-------------------------------------------------------------------------- 
        |
        | When enabled, all dispatched events are automatically tagged with
        | metadata about their origin (_source, _timestamp, _version).
        | Source tagging is lightweight and recommended for all SaaS deployments.
        |
        */
        'source_tagging' => [
            'enabled' => env('ANALYTICS_SOURCE_TAGGING_ENABLED', true),
            'tag_version' => env('ANALYTICS_SOURCE_TAGGING_VERSION', true),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Config Validation
        |-------------------------------------------------------------------------- 
        |
        | When enabled, the AnalyticsConfigValidator runs on every boot and
        | logs warnings/errors for misconfigured providers. Recommended for
        | development and staging environments.
        |
        */
        'validation_boot' => [
            'enabled' => env('ANALYTICS_VALIDATION_BOOT_ENABLED', false),
            'log_level' => env('ANALYTICS_VALIDATION_BOOT_LOG_LEVEL', 'warning'), // error, warning, info
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Referral Tracking
        |-------------------------------------------------------------------------- 
        |
        | When enabled, tracks referral codes and affiliate links in URLs.
        | Automatically captures the 'ref' parameter from incoming requests
        | and persists it for conversion attribution.
        |
        */
        'referral' => [
            'enabled' => env('ANALYTICS_REFERRAL_ENABLED', false),
            'param_name' => env('ANALYTICS_REFERRAL_PARAM', 'ref'),
            'ttl' => (int) env('ANALYTICS_REFERRAL_TTL', 2592000), // 30 days
            'track_conversions' => env('ANALYTICS_REFERRAL_TRACK_CONVERSIONS', true),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Broadcast (Real-Time Event Broadcasting)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, analytics events are broadcast via Laravel Echo/Broadcasting
        | for real-time dashboard updates. Supports selective broadcasting by
        | event name, category, value threshold, and severity.
        |
        */
        'broadcast' => [
            'enabled' => env('ANALYTICS_BROADCAST_ENABLED', false),
            'channel_prefix' => env('ANALYTICS_BROADCAST_PREFIX', 'analytics'),
            'private_channels' => env('ANALYTICS_BROADCAST_PRIVATE', true),
            'value_threshold' => (float) env('ANALYTICS_BROADCAST_VALUE_THRESHOLD', 0.0),
            'alert_channel' => env('ANALYTICS_BROADCAST_ALERT_CHANNEL', 'analytics.alerts'),
            'metrics_channel' => env('ANALYTICS_BROADCAST_METRICS_CHANNEL', 'analytics.metrics'),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Tenant Isolation (Multi-Tenant SaaS)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, isolates analytics data per tenant. Supports resolution
        | from user attribute, request header, subdomain, or session.
        |
        */
        'tenant' => [
            'enabled' => env('ANALYTICS_TENANT_ENABLED', false),
            'resolution_strategy' => env('ANALYTICS_TENANT_STRATEGY', 'user_attribute'),
            'tenant_header' => env('ANALYTICS_TENANT_HEADER', 'X-Tenant-ID'),
            'events_per_hour' => (int) env('ANALYTICS_TENANT_EVENTS_PER_HOUR', 10000),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Retention Policy (GDPR Data Retention)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, exposes retention metadata for downstream storage layers
        | to automatically purge or anonymize old analytics events.
        |
        */
        'retention_policy' => [
            'enabled' => env('ANALYTICS_RETENTION_POLICY_ENABLED', false),
            'auto_expire' => env('ANALYTICS_RETENTION_POLICY_AUTO_EXPIRE', false),
            'pii_categories' => ['pii'],
            'engagement_days' => (int) env('ANALYTICS_RETENTION_ENGAGEMENT_DAYS', 30),
            'saas_days' => (int) env('ANALYTICS_RETENTION_SAAS_DAYS', 90),
            'ecommerce_days' => (int) env('ANALYTICS_RETENTION_ECOMMERCE_DAYS', 365),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Feature Gate (Plan-Based Access Control)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, restricts analytics features based on the user's plan tier.
        | Supports Free, Starter, Pro, and Enterprise tiers with 12 features.
        |
        */
        'gate' => [
            'enabled' => env('ANALYTICS_GATE_ENABLED', false),
            'default_plan' => env('ANALYTICS_GATE_DEFAULT_PLAN', 'free'),
            'plan_attribute' => env('ANALYTICS_GATE_PLAN_ATTRIBUTE', 'plan'),
            'global_overrides' => [],
            'user_overrides' => [],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Reporting (Periodic Analytics Summaries)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, generates daily/weekly/monthly analytics reports with
        | event counts, category breakdowns, trending events, and provider stats.
        | Reports are cached for configurable TTL.
        |
        */
        'reporting' => [
            'enabled' => env('ANALYTICS_REPORTING_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_REPORTING_CACHE_TTL', 300),
            'trending_window' => (int) env('ANALYTICS_REPORTING_TRENDING_WINDOW', 3600),
            'top_events_limit' => (int) env('ANALYTICS_REPORTING_TOP_EVENTS_LIMIT', 20),
            'trending_limit' => (int) env('ANALYTICS_REPORTING_TRENDING_LIMIT', 10),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Dead Letter Queue (Failed Event Persistence)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, permanently failed events (exhausted all retries) are
        | stored persistently for inspection, manual replay, or archival.
        | Supports file, log, or null storage strategies.
        |
        */
        'dead_letter_queue' => [
            'enabled' => env('ANALYTICS_DLQ_ENABLED', true),
            'strategy' => env('ANALYTICS_DLQ_STRATEGY', 'file'),
            'storage_path' => env('ANALYTICS_DLQ_STORAGE_PATH', storage_path('app/analytics/dlq.jsonl')),
            'max_size' => (int) env('ANALYTICS_DLQ_MAX_SIZE', 10000),
            'buffer_size' => (int) env('ANALYTICS_DLQ_BUFFER_SIZE', 50),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Real-Time Aggregation (Live Dashboard Data)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, maintains rolling event counters for live dashboards.
        | Uses the cache driver for cross-process state sharing.
        | Requires Redis or Memcached for multi-server deployments.
        |
        */
        'realtime' => [
            'enabled' => env('ANALYTICS_REALTIME_ENABLED', true),
            'window_seconds' => (int) env('ANALYTICS_REALTIME_WINDOW', 120),
            'top_events_limit' => (int) env('ANALYTICS_REALTIME_TOP_EVENTS', 20),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | A/B Test Analytics
        |-------------------------------------------------------------------------- 
        |
        | Tracks A/B test exposures and conversions per variant.
        | Computes statistical significance using two-proportion z-test.
        | Experiments are stored in the Laravel cache.
        |
        */
        'ab_tests' => [
            'enabled' => env('ANALYTICS_AB_TESTS_ENABLED', true),
            'confidence_threshold' => (float) env('ANALYTICS_AB_TESTS_CONFIDENCE', 0.95),
            'cache_ttl' => (int) env('ANALYTICS_AB_TESTS_CACHE_TTL', 604800),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Analytics Snapshots (Trend Comparisons)
        |-------------------------------------------------------------------------- 
        |
        | Periodic point-in-time captures of analytics metrics for trend analysis.
        | Supports daily and hourly snapshots with configurable retention.
        |
        */
        'snapshots' => [
            'enabled' => env('ANALYTICS_SNAPSHOTS_ENABLED', true),
            'daily_ttl' => (int) env('ANALYTICS_SNAPSHOTS_DAILY_TTL', 7776000),
            'hourly_ttl' => (int) env('ANALYTICS_SNAPSHOTS_HOURLY_TTL', 604800),
            'max_daily' => (int) env('ANALYTICS_SNAPSHOTS_MAX_DAILY', 90),
            'max_hourly' => (int) env('ANALYTICS_SNAPSHOTS_MAX_HOURLY', 168),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | SaaS KPI Tracking
        |-------------------------------------------------------------------------- 
        |
        | Tracks and aggregates SaaS-specific business metrics:
        | MRR, ARR, churn rate, trial conversion, CLV, ARPU.
        | Data is stored in the Laravel cache.
        |
        */
        'saas_kpi' => [
            'enabled' => env('ANALYTICS_SAAS_KPI_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_SAAS_KPI_CACHE_TTL', 2592000),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | UTM Aggregation (Marketing Attribution)
        |-------------------------------------------------------------------------- 
        |
        | Aggregates events by UTM source, medium, and campaign.
        | Provides marketing attribution insights with conversion tracking.
        |
        */
        'utm_aggregation' => [
            'enabled' => env('ANALYTICS_UTM_AGG_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_UTM_AGG_CACHE_TTL', 2592000),
            'max_combinations' => (int) env('ANALYTICS_UTM_AGG_MAX_COMBOS', 5000),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Geolocation Enrichment
        |-------------------------------------------------------------------------- 
        |
        | Enriches events with country/region/city based on client IP.
        | Supports three strategies: header, ip2country, maxmind.
        |
        */
        'geolocation' => [
            'enabled' => env('ANALYTICS_GEO_ENABLED', false),
            'strategy' => env('ANALYTICS_GEO_STRATEGY', 'header'),
            'country_header' => env('ANALYTICS_GEO_COUNTRY_HEADER', 'CF-IPCountry'),
            'region_header' => env('ANALYTICS_GEO_REGION_HEADER', ''),
            'city_header' => env('ANALYTICS_GEO_CITY_HEADER', ''),
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Forwarding (External Platforms)
        |--------------------------------------------------------------------------
        |
        | Forward analytics events to external platforms like Segment, Mixpanel,
        | Amplitude, or custom webhooks. Each forwarder has independent config,
        | enable/disable toggle, and retry strategy.
        |
        */
        'forwarding' => [
            'enabled' => env('ANALYTICS_FORWARDING_ENABLED', false),
            'timeout' => (int) env('ANALYTICS_FORWARDING_TIMEOUT', 5),
            'retries' => (int) env('ANALYTICS_FORWARDING_RETRIES', 1),
            'rate_limit_per_minute' => (int) env('ANALYTICS_FORWARDING_RATE_LIMIT', 1000),
            'forwarders' => [
                // 'segment' => [
                //     'enabled' => true,
                //     'type' => 'segment',
                //     'write_key' => env('ANALYTICS_SEGMENT_WRITE_KEY', ''),
                // ],
                // 'mixpanel' => [
                //     'enabled' => true,
                //     'type' => 'mixpanel',
                //     'token' => env('ANALYTICS_MIXPANEL_TOKEN', ''),
                // ],
                // 'amplitude' => [
                //     'enabled' => true,
                //     'type' => 'amplitude',
                //     'api_key' => env('ANALYTICS_AMPLITUDE_API_KEY', ''),
                // ],
                // 'data_warehouse' => [
                //     'enabled' => true,
                //     'type' => 'custom',
                //     'url' => env('ANALYTICS_CUSTOM_FORWARDER_URL', ''),
                //     'headers' => ['Authorization' => 'Bearer ' . env('ANALYTICS_CUSTOM_FORWARDER_TOKEN', '')],
                // ],
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Performance Budget
        |--------------------------------------------------------------------------
        |
        | Enforce limits on event payload size, rate per session, and daily quotas.
        | Helps prevent analytics from impacting application performance or
        | incurring excessive costs with external providers.
        |
        */
        'performance_budget' => [
            'enabled' => env('ANALYTICS_PERF_BUDGET_ENABLED', false),
            'max_payload_bytes' => (int) env('ANALYTICS_PERF_MAX_PAYLOAD', 8192),
            'max_params_count' => (int) env('ANALYTICS_PERF_MAX_PARAMS', 25),
            'max_events_per_session' => (int) env('ANALYTICS_PERF_MAX_SESSION', 100),
            'max_events_per_user_per_day' => (int) env('ANALYTICS_PERF_MAX_USER_DAY', 500),
            'max_events_per_page_view' => (int) env('ANALYTICS_PERF_MAX_PAGE_VIEW', 50),
            'max_param_value_length' => (int) env('ANALYTICS_PERF_MAX_VALUE_LEN', 500),
            'drop_oversized' => env('ANALYTICS_PERF_DROP_OVERSIZED', true),
            'warn_only' => env('ANALYTICS_PERF_WARN_ONLY', false),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Routing (Provider-Specific Event Filtering)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, routes specific events to specific providers based on
        | pattern matching rules. Events matching a pattern are dispatched only
        | to the listed providers instead of all enabled providers.
        |
        | Patterns support exact match ("purchase"), prefix wildcard ("add_to_*"),
        | suffix wildcard ("*_click"), and wildcard-only ("*").
        |
        | Supported provider names: ga4, gtm, meta, plausible, posthog, webhook
        |
        | Example:
        |   'rules' => [
        |       'purchase' => ['ga4', 'meta'],
        |       'refund' => ['ga4', 'meta'],
        |       'add_to_*' => ['ga4', 'meta', 'posthog'],
        |       'page_view' => ['ga4', 'plausible', 'posthog'],
        |   ],
        |
        */
        'routing' => [
            'enabled' => env('ANALYTICS_ROUTING_ENABLED', false),
            'rules' => [
                // 'purchase' => ['ga4', 'meta'],
                // 'page_view' => ['ga4', 'plausible', 'posthog'],
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Aliases
        |--------------------------------------------------------------------------
        |
        | Map common event name aliases and abbreviations to canonical event names.
        | The EventAliasResolver uses these plus built-in defaults to normalize
        | event names from different sources (JS client, webhooks, integrations).
        |
        | Example:
        |   'aliases' => [
        |       'my_custom_event' => 'sign_up',
        |       'app:install' => 'feature_used',
        |   ],
        |
        */
        'aliases' => [
            // 'signup' => 'sign_up',    // Already included in defaults
            // 'my_alias' => 'page_view', // Custom alias
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Cache
        |--------------------------------------------------------------------------
        |
        | High-performance event lookup caching. Uses in-memory L1 cache
        | (per-request) with optional L2 Laravel cache store (cross-request).
        | Eliminates repeated catalog lookups in batch processing and event replay.
        |
        */
        'event_cache' => [
            'enabled' => env('ANALYTICS_EVENT_CACHE_ENABLED', true),
            'memory_max_items' => (int) env('ANALYTICS_EVENT_CACHE_MEMORY_MAX', 500),
            'memory_ttl' => (int) env('ANALYTICS_EVENT_CACHE_MEMORY_TTL', 300),
            'cache_ttl' => (int) env('ANALYTICS_EVENT_CACHE_TTL', 3600),
            'prefix' => env('ANALYTICS_EVENT_CACHE_PREFIX', 'zb_analytics_'),
        ],

    ],
];
