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
            'cookie_ttl' => (int) env('ANALYTICS_IDENTITY_COOKIE_TTL', 525600), // 365 days (minutes),
            'cookie_secure' => env('ANALYTICS_IDENTITY_COOKIE_SECURE', true),
            'cookie_samesite' => env('ANALYTICS_IDENTITY_COOKIE_SAMESITE', 'Lax'),
            'cookie_domain' => env('ANALYTICS_IDENTITY_COOKIE_DOMAIN'), // null = current domain; '.example.com' for cross-subdomain
            'link_on_auth' => env('ANALYTICS_IDENTITY_LINK_ON_AUTH', true),
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

            /*
            | Subscription Tiers
            |
            | Define your product's subscription tiers for tier-level analytics.
            | Used by SaaSAnalyticsService for plan-specific event enrichment
            | and by the admin dashboard for tier breakdowns.
            |
            | Each tier defines: display name, price (in configured currency),
            | and an optional list of features for feature-gating analytics.
            |
            */
            'subscription_tiers' => [
                // 'free' => [
                //     'name' => 'Free',
                //     'price' => 0,
                //     'features' => ['basic_dashboard', 'limited_exports'],
                // ],
                // 'starter' => [
                //     'name' => 'Starter',
                //     'price' => 19,
                //     'billing_cycle' => 'monthly',
                //     'features' => ['advanced_dashboard', 'api_access', 'email_support'],
                // ],
                // 'pro' => [
                //     'name' => 'Pro',
                //     'price' => 49,
                //     'billing_cycle' => 'monthly',
                //     'features' => ['team_collaboration', 'custom_reports', 'priority_support'],
                // ],
                // 'enterprise' => [
                //     'name' => 'Enterprise',
                //     'price' => 199,
                //     'billing_cycle' => 'monthly',
                //     'features' => ['sso', 'audit_log', 'dedicated_support', 'sla'],
                // ],
            ],
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
            'prefix' => env('ANALYTICS_API_PREFIX', 'analytics'),
            'middleware' => env('ANALYTICS_API_MIDDLEWARE', ''),
            'auth_middleware' => env('ANALYTICS_API_AUTH_MIDDLEWARE', 'auth:sanctum'),
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
            'window_seconds' => (int) env('ANALYTICS_DEDUP_WINDOW_SECONDS', 10),
            'max_fingerprints' => (int) env('ANALYTICS_DEDUP_MAX_FINGERPRINTS', 10000),
            'cache_prefix' => env('ANALYTICS_DEDUP_CACHE_PREFIX', 'zb_fp_'),
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

                // ── GDPR & Account Deletion (v2.90.0) ────────
                'account.deleted' => true,

                // ── Subscription & Trial (v2.90.0) ────────────
                'subscription.created' => true,
                'subscription.cancelled' => true,
                'trial.expired' => true,
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

        /*
        |-------------------------------------------------------------------------- 
        | Event Buckets (Time-Binned Aggregation)
        |-------------------------------------------------------------------------- 
        |
        | Aggregates events into configurable time buckets (minute, hour, day, week,
        | month) for chart rendering, trend analysis, and dashboard widgets.
        | Each bucket tracks event counts, unique users/clients, values, and per-event
        | breakdowns. Supports multiple concurrent series and cross-series comparison.
        |
        */
        'event_buckets' => [
            'enabled' => env('ANALYTICS_EVENT_BUCKETS_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_EVENT_BUCKETS_CACHE_TTL', 86400),
            'max_series' => (int) env('ANALYTICS_EVENT_BUCKETS_MAX_SERIES', 50),
            'max_buckets_per_series' => (int) env('ANALYTICS_EVENT_BUCKETS_MAX_PER_SERIES', 1000),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | SaaS Health Score
        |-------------------------------------------------------------------------- 
        |
        | Computes a composite health score (0-100) from multiple SaaS KPIs:
        | engagement, revenue, conversion, and retention. Scores are graded A-F
        | and tracked over time for trend visualization.
        |
        */
        'health_score' => [
            'enabled' => env('ANALYTICS_HEALTH_SCORE_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_HEALTH_SCORE_CACHE_TTL', 86400),
            'weights' => [
                'engagement' => 0.25,
                'revenue' => 0.30,
                'conversion' => 0.25,
                'retention' => 0.20,
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Envelope (Context-Rich Event Building)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, EventEnvelopeService automatically enriches dispatched
        | events with session, device, geolocation, identity, UTM, referrer,
        | consent, and metadata context from the current HTTP request.
        |
        | Toggle individual context sections on/off to control overhead.
        | Produces EventContextEvent DTOs with flattened params for providers.
        |
        */
        'envelope' => [
            'enabled' => env('ANALYTICS_ENVELOPE_ENABLED', true),
            'session' => env('ANALYTICS_ENVELOPE_SESSION', true),
            'device' => env('ANALYTICS_ENVELOPE_DEVICE', true),
            'geo' => env('ANALYTICS_ENVELOPE_GEO', false),
            'identity' => env('ANALYTICS_ENVELOPE_IDENTITY', true),
            'utm' => env('ANALYTICS_ENVELOPE_UTM', true),
            'referrer' => env('ANALYTICS_ENVELOPE_REFERRER', true),
            'consent' => env('ANALYTICS_ENVELOPE_CONSENT', true),
            'metadata' => env('ANALYTICS_ENVELOPE_METADATA', true),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Consent-Aware Pipeline (Granular Purpose Filtering)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, the ConsentAwareFilter evaluates each event against
        | granular consent purposes before dispatch. Events are mapped to
        | required purposes (analytics, functional, marketing, necessary).
        |
        | Only events whose required purposes are all granted are dispatched.
        | Events requiring only 'necessary' are always dispatched.
        |
        | 'strict' mode drops events when consent state is unknown.
        | 'fail_open' mode (default) allows events when consent is unresolved.
        |
        */
        'consent_purposes' => [
            'enabled' => env('ANALYTICS_CONSENT_PURPOSES_ENABLED', false),
            'strict' => env('ANALYTICS_CONSENT_PURPOSES_STRICT', false),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Scheduled Reports (Periodic Analytics Report Generation)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, the `analytics:report:schedule` command generates periodic
        | reports for dashboard delivery, email notifications, or archival.
        | Configure output path, periods, and auto-archive behavior.
        |
        | Laravel Scheduler integration example:
        |   $schedule->command('analytics:report:schedule --period=daily')->dailyAt('09:00');
        |   $schedule->command('analytics:report:schedule --period=weekly --all')->weekly();
        |
        */
        'scheduled_reports' => [
            'enabled' => env('ANALYTICS_SCHEDULED_REPORTS_ENABLED', false),
            'output_path' => env('ANALYTICS_SCHEDULED_REPORTS_PATH', storage_path('app/analytics/reports')),
            'auto_archive' => env('ANALYTICS_SCHEDULED_REPORTS_ARCHIVE', false),
            'archive_days' => (int) env('ANALYTICS_SCHEDULED_REPORTS_ARCHIVE_DAYS', 90),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | SaaS Journey Milestones
        |-------------------------------------------------------------------------- 
        |
        | Track user progression through configurable multi-step journeys.
        | Each journey has named milestones. When all are completed, a
        | `journey_completed` event is dispatched with timing metadata.
        |
        | Add custom journeys in 'definitions'. Built-in journeys:
        | - acquisition (landing → signup → confirm)
        | - trial (trial_start → trial_active → checkout)
        | - expansion (upgrade_eligible → upgrade_select → checkout)
        | - activation (signup → first_feature → first_return → profile)
        |
        */
        'journeys' => [
            'enabled' => env('ANALYTICS_JOURNEYS_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_JOURNEYS_CACHE_TTL', 2592000), // 30 days
            'definitions' => [
                // Custom journeys override/extend the built-in defaults:
                // 'onboarding' => [
                //     'label' => 'Onboarding Flow',
                //     'milestones' => ['welcome_view', 'profile_setup', 'team_invite', 'first_action'],
                //     'completed_event' => 'journey_onboarding_completed',
                // ],
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Data Anonymization (GDPR PII Masking)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, event parameters matching configured field patterns
        | are anonymized before dispatch. Uses HMAC-SHA256 for deterministic
        | ID anonymization and prefix masking for general values.
        |
        | Strategies:
        | - Email: mask local part, preserve domain (joh***@example.com)
        | - Phone: mask middle digits (123****89)
        | - IP: zero last octet (192.168.1.0)
        | - UUID: deterministic HMAC replacement
        | - General: prefix + asterisk masking
        |
        */
        'anonymization' => [
            'enabled' => env('ANALYTICS_ANONYMIZATION_ENABLED', false),
            'salt' => env('ANALYTICS_ANONYMIZATION_SALT', 'zb_anon_salt'),
            'global_fields' => [
                'email',
                'phone',
                'ip_address',
                'user_agent',
                'full_name',
                'first_name',
                'last_name',
                'address',
                'credit_card',
            ],
            'event_rules' => [
                // Per-event field rules (merged with global):
                // 'sign_up' => ['email', 'name'],
                // 'login' => ['ip_address'],
            ],
            'category_rules' => [
                // Per-category field rules (merged with global):
                // 'saas' => ['email', 'name'],
                // 'ecommerce' => ['credit_card', 'billing_address'],
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Provider Telemetry (Self-Monitoring)
        |-------------------------------------------------------------------------- 
        |
        | Periodically verify provider connectivity and record probe results
        | for dashboard exposure. Designed to be called by a scheduled command
        | or health-check middleware. Results are cached to avoid hammering
        | providers on every request.
        |
        */
        'telemetry' => [
            'enabled' => env('ANALYTICS_TELEMETRY_ENABLED', false),
            'cache_ttl' => (int) env('ANALYTICS_TELEMETRY_CACHE_TTL', 300), // 5 minutes
            'cache_prefix' => env('ANALYTICS_TELEMETRY_CACHE_PREFIX', 'zb_analytics_telemetry'),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Campaign ROI (Marketing Attribution)
        |-------------------------------------------------------------------------- 
        |
        | Track marketing campaign spend and correlate with conversion events
        | for ROI, ROAS, and CPA computation. Register spend data via the API
        | or service, then query ROI metrics per-campaign or in aggregate.
        |
        | Integration: Pair with UTM tracking and RevenueAttributionService
        | for end-to-end marketing attribution.
        |
        */
        'campaign_roi' => [
            'enabled' => env('ANALYTICS_CAMPAIGN_ROI_ENABLED', false),
            'cache_ttl' => (int) env('ANALYTICS_CAMPAIGN_ROI_CACHE_TTL', 86400), // 24 hours
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Data Minimization (Privacy-First / GDPR Article 5(1)(c))
        |-------------------------------------------------------------------------- 
        |
        | Enforces data minimization by stripping unnecessary parameters from
        | events before dispatch. Unlike PII sanitization (which masks values),
        | data minimization removes optional parameters entirely based on
        | allowlists.
        |
        | Strategies:
        | - Global allowlist: Only retain listed params for ALL events
        | - Per-event allowlist: Override per specific event name
        | - Per-category allowlist: Override per event category
        | - Strip params: Always remove these regardless of allowlists
        |
        | When empty, no parameters are removed (allowlist = allow all).
        | Internal params (prefixed with _) are always preserved.
        |
        */
        'data_minimization' => [
            'enabled' => env('ANALYTICS_DATA_MINIMIZATION_ENABLED', false),
            'global_allowlist' => [],
            'event_allowlists' => [],
            'category_allowlists' => [],
            'strip_params' => ['user_agent', 'ip_address', 'raw_query', 'full_page_url'],
            'audit_log' => env('ANALYTICS_DATA_MINIMIZATION_AUDIT', false),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | SaaS Conversion Analytics
        |-------------------------------------------------------------------------- 
        |
        | Tracks trial-to-paid conversion, activation milestones, time-to-conversion,
        | and subscription win-back rates. Used by SaaSConversionService for funnel
        | analysis, activation scoring, and cohort conversion metrics.
        |
        | Activation milestones are configurable — each milestone has a weight (0-1)
        | that contributes to the user's 0-100 activation score. Customize based
        | on your product's key activation moments.
        |
        */
        'conversion_analytics' => [
            'enabled' => env('ANALYTICS_CONVERSION_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_CONVERSION_CACHE_TTL', 86400), // 24 hours
            'activation_milestones' => [
                'first_login' => ['weight' => 0.10, 'category' => 'activation'],
                'profile_completed' => ['weight' => 0.15, 'category' => 'activation'],
                'first_feature_used' => ['weight' => 0.25, 'category' => 'activation'],
                'team_created' => ['weight' => 0.10, 'category' => 'growth'],
                'integration_connected' => ['weight' => 0.15, 'category' => 'activation'],
                'invite_sent' => ['weight' => 0.10, 'category' => 'growth'],
                'search_performed' => ['weight' => 0.05, 'category' => 'engagement'],
                'three_day_retention' => ['weight' => 0.10, 'category' => 'retention'],
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Delivery Confirmation (Client Feedback Loop)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, the API includes a delivery confirmation token with
        | each event response. The JS client can use this to verify reliable
        | delivery and retry failed events. This enables an acknowledgment-
        | based delivery guarantee for critical events (purchases, signups).
        |
        */
        'delivery_confirmation' => [
            'enabled' => env('ANALYTICS_DELIVERY_CONFIRMATION_ENABLED', false),
            'critical_events' => ['purchase', 'sign_up', 'subscription', 'payment_succeeded'],
            'token_ttl' => (int) env('ANALYTICS_DELIVERY_CONFIRMATION_TTL', 300), // 5 minutes
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Priority Gate
        |-------------------------------------------------------------------------- 
        |
        | When enabled, events are assigned priority levels (critical, normal, low,
        | background) that determine dispatch behavior. Critical events (purchase,
        | sign_up, subscription) always pass through; low-priority events
        | (scroll_depth, outbound_click) are subject to rate limits and budget checks.
        |
        | Built-in priority overrides map known event names to levels. Custom
        | overrides can be added via the 'overrides' key.
        |
        | Rate limits are per-priority (events per minute window).
        | Budget threshold is a global cap on total dispatched events.
        |
        */
        'priority' => [
            'enabled' => env('ANALYTICS_PRIORITY_ENABLED', true),
            'rate_limits' => [
                'critical' => (int) env('ANALYTICS_PRIORITY_RATE_CRITICAL', 10000),
                'normal' => (int) env('ANALYTICS_PRIORITY_RATE_NORMAL', 1000),
                'low' => (int) env('ANALYTICS_PRIORITY_RATE_LOW', 200),
                'background' => (int) env('ANALYTICS_PRIORITY_RATE_BACKGROUND', 50),
            ],
            'overrides' => [
                // Custom event priority overrides:
                // 'my_custom_event' => 'critical',
                // 'debug_ping' => 'background',
            ],
            'cache_ttl' => (int) env('ANALYTICS_PRIORITY_CACHE_TTL', 60), // 1 minute window
            'cache_prefix' => env('ANALYTICS_PRIORITY_CACHE_PREFIX', 'zb_priority_'),
            'budget_aware' => env('ANALYTICS_PRIORITY_BUDGET_AWARE', true),
            'budget_threshold' => (int) env('ANALYTICS_PRIORITY_BUDGET_THRESHOLD', 5000),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Data Warehouse Export (ETL)
        |-------------------------------------------------------------------------- 
        |
        | Export analytics events to NDJSON or CSV format for ingestion by
        | data warehouses (Snowflake, BigQuery, Redshift, Databricks).
        | Supports configurable field selection, category/event filtering,
        | and date range filtering.
        |
        | NDJSON (default): Newline-delimited JSON, one event per line.
        | Compatible with BigQuery load jobs, Snowflake COPY INTO, and AWS Athena.
        |
        | CSV: Comma-separated with optional headers.
        | Compatible with Redshift COPY, Snowflake, and traditional ETL tools.
        |
        */
        'data_warehouse' => [
            'enabled' => env('ANALYTICS_DATA_WAREHOUSE_ENABLED', false),
            'format' => env('ANALYTICS_DATA_WAREHOUSE_FORMAT', 'ndjson'), // ndjson, csv
            'output_path' => env('ANALYTICS_DATA_WAREHOUSE_PATH', storage_path('app/analytics/exports')),
            'include_fields' => [], // Empty = include all fields
            'include_headers' => env('ANALYTICS_DATA_WAREHOUSE_HEADERS', true),
            'null_value' => env('ANALYTICS_DATA_WAREHOUSE_NULL_VALUE', ''),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Property Schema Validation
        |-------------------------------------------------------------------------- 
        |
        | When enabled, events dispatched through the API are validated against
        | registered property schemas. Validates type, required, enum, format,
        | and range constraints. Invalid events are rejected with descriptive errors.
        |
        | Built-in schemas cover core e-commerce, SaaS, and engagement events.
        | Custom schemas can be added via EventPropertySchema::defineEventSchema().
        |
        */
        'property_schema' => [
            'enabled' => env('ANALYTICS_PROPERTY_SCHEMA_ENABLED', false),
            'reject_invalid' => env('ANALYTICS_PROPERTY_SCHEMA_REJECT', true),
            'log_violations' => env('ANALYTICS_PROPERTY_SCHEMA_LOG', true),
            'register_builtins' => env('ANALYTICS_PROPERTY_SCHEMA_BUILTINS', true),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Circuit Breaker (Provider Outage Protection)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, monitors provider dispatch failures and opens a circuit
        | (stops sending events) when the failure threshold is exceeded. After
        | a cooldown period, the circuit transitions to half-open and probes
        | with limited events. Successful probes close the circuit.
        |
        | States: closed (normal), open (failing), half_open (recovering)
        |
        */
        'circuit_breaker' => [
            'enabled' => env('ANALYTICS_CIRCUIT_BREAKER_ENABLED', false),
            'failure_threshold' => (int) env('ANALYTICS_CIRCUIT_BREAKER_FAILURE_THRESHOLD', 5),
            'success_threshold' => (int) env('ANALYTICS_CIRCUIT_BREAKER_SUCCESS_THRESHOLD', 2),
            'cooldown_seconds' => (int) env('ANALYTICS_CIRCUIT_BREAKER_COOLDOWN', 60),
            'half_open_max_probes' => (int) env('ANALYTICS_CIRCUIT_BREAKER_PROBES', 3),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Compliance Audit (GDPR / SOC2 / Privacy)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, the EventComplianceService generates comprehensive
        | compliance reports covering PII exposure, consent coverage, retention
        | policies, data minimization, and processing transparency.
        |
        | Reports are cached for the configured TTL and invalidated on config changes.
        |
        */
        'compliance' => [
            'enabled' => env('ANALYTICS_COMPLIANCE_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_COMPLIANCE_CACHE_TTL', 3600), // 1 hour
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Recovery Service (DLQ Batch Recovery)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, provides advanced DLQ recovery with retry budget tracking,
        | batch recovery, health assessment, and recovery history.
        |
        | The max_recoveries_per_hour limit prevents runaway recovery loops
        | from consuming excessive resources during extended outages.
        |
        */
        'recovery' => [
            'enabled' => env('ANALYTICS_RECOVERY_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_RECOVERY_CACHE_TTL', 300), // 5 minutes
            'max_recoveries_per_hour' => (int) env('ANALYTICS_RECOVERY_MAX_PER_HOUR', 100),
            'batch_size' => (int) env('ANALYTICS_RECOVERY_BATCH_SIZE', 10),
        ],

        /*
        |--------------------------------------------------------------------------
        | Analytics Sandbox (Non-Production Event Capture)
        |--------------------------------------------------------------------------
        |
        | When enabled, captures events in local storage without sending to any
        | external provider. Events are stored in the configured cache store
        | and can be inspected, replayed, or exported for development/testing.
        |
        | Automatically activates when APP_ENV is 'local' or 'testing' unless
        | explicitly disabled. In 'staging' it logs but does not capture by default.
        |
        | Supports event inspection via API and artisan commands. Events can be
        | replayed against live providers when debugging integration issues.
        |
        */
        'sandbox' => [
            'enabled' => env('ANALYTICS_SANDBOX_ENABLED', null), // null = auto-detect from APP_ENV
            'auto_local' => env('ANALYTICS_SANDBOX_AUTO_LOCAL', true),
            'auto_testing' => env('ANALYTICS_SANDBOX_AUTO_TESTING', true),
            'staging_log_only' => env('ANALYTICS_SANDBOX_STAGING_LOG', true),
            'max_events' => (int) env('ANALYTICS_SANDBOX_MAX_EVENTS', 5000),
            'cache_ttl' => (int) env('ANALYTICS_SANDBOX_CACHE_TTL', 86400), // 24 hours
            'cache_prefix' => env('ANALYTICS_SANDBOX_CACHE_PREFIX', 'zb_sandbox_'),
            'include_context' => env('ANALYTICS_SANDBOX_CONTEXT', true),
            'allow_replay' => env('ANALYTICS_SANDBOX_REPLAY', true),
        ],

        /*
        |--------------------------------------------------------------------------
        | Per-Provider Rate Limits
        |--------------------------------------------------------------------------
        |
        | Independent rate limits per analytics provider. When a provider exceeds
        | its per-minute rate limit, events for that provider are buffered in the
        | event stream until the window resets. Other providers continue normally.
        |
        | Set to 0 or null to disable rate limiting for a specific provider.
        | Global rate limiter (api.throttle) applies to incoming API requests
        | and is separate from per-provider dispatch limits.
        |
        | Recommended limits based on provider quotas:
        | - GA4 MP: 30 requests/sec (1800/min)
        | - Meta CAPI: 2000 events/min
        | - Plausible: 1000 events/min
        | - PostHog: 1000 events/min
        |
        */
        'provider_rate_limits' => [
            'enabled' => env('ANALYTICS_PROVIDER_RATE_LIMITS_ENABLED', false),
            'cache_ttl' => (int) env('ANALYTICS_PROVIDER_RATE_LIMITS_TTL', 60), // 1 minute window
            'cache_prefix' => env('ANALYTICS_PROVIDER_RATE_LIMITS_PREFIX', 'zb_prl_'),
            'providers' => [
                'ga4' => [
                    'limit' => (int) env('ANALYTICS_PRL_GA4', 1800),
                    'enabled' => env('ANALYTICS_PRL_GA4_ENABLED', true),
                ],
                'meta' => [
                    'limit' => (int) env('ANALYTICS_PRL_META', 2000),
                    'enabled' => env('ANALYTICS_PRL_META_ENABLED', true),
                ],
                'gtm' => [
                    'limit' => 0, // No limit for GTM (client-side only)
                    'enabled' => false,
                ],
                'plausible' => [
                    'limit' => (int) env('ANALYTICS_PRL_PLAUSIBLE', 1000),
                    'enabled' => env('ANALYTICS_PRL_PLAUSIBLE_ENABLED', true),
                ],
                'posthog' => [
                    'limit' => (int) env('ANALYTICS_PRL_POSTHOG', 1000),
                    'enabled' => env('ANALYTICS_PRL_POSTHOG_ENABLED', true),
                ],
                'webhook' => [
                    'limit' => (int) env('ANALYTICS_PRL_WEBHOOK', 500),
                    'enabled' => env('ANALYTICS_PRL_WEBHOOK_ENABLED', true),
                ],
            ],
            'overflow_strategy' => env('ANALYTICS_PRL_OVERFLOW', 'drop'), // drop, buffer, downsample
            'log_violations' => env('ANALYTICS_PRL_LOG', true),
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Schema Versioning
        |--------------------------------------------------------------------------
        |
        | Tracks schema version metadata on events for backward compatibility.
        | When enabled, each dispatched event includes a `_schema_version` param
        | indicating the event schema format version used at dispatch time.
        |
        | Consumers (data warehouses, downstream processors) can use this to
        | handle format changes gracefully without breaking existing pipelines.
        |
        | Schema versions are per-event-name. When a schema changes, increment
        | the version in the schema registry. The package auto-injects the current
        | version into dispatched event params.
        |
        */
        'schema_versioning' => [
            'enabled' => env('ANALYTICS_SCHEMA_VERSIONING_ENABLED', true),
            'param_name' => env('ANALYTICS_SCHEMA_VERSION_PARAM', '_schema_version'),
            'default_version' => env('ANALYTICS_SCHEMA_VERSION_DEFAULT', '1.0'),
            'include_catalog_version' => env('ANALYTICS_SCHEMA_VERSION_CATALOG', true),
            'catalog_version' => '2.97.0',
        ],

        /*
        |--------------------------------------------------------------------------
        | SaaS Starter Readiness (Production Checklist)
        |--------------------------------------------------------------------------
        |
        | When enabled, provides a programmatic readiness check for production
        | deployments. Validates critical configuration, provider setup, consent
        | defaults, queue connectivity, and recommended settings.
        |
        | Use via artisan: `zb:analytics:readiness`
        | Or via API: `GET /api/analytics/readiness`
        |
        | Checklist items are scored (pass/warn/fail) with an overall readiness
        | percentage (0-100%). A minimum of 80% is recommended for production.
        |
        */
        'readiness' => [
            'enabled' => env('ANALYTICS_READINESS_ENABLED', true),
            'minimum_score' => (int) env('ANALYTICS_READINESS_MIN_SCORE', 80),
            'cache_ttl' => (int) env('ANALYTICS_READINESS_CACHE_TTL', 300), // 5 minutes
            'required_checks' => [
                'providers_configured',      // At least one provider is enabled and configured
                'consent_default_set',       // Consent default is explicitly configured
                'queue_configured',          // Queue driver is available
                'identity_cookie_set',       // Identity cookie is configured
                'event_validation_active',   // Event validation is enabled
                'debug_disabled',            // Debug mode is OFF in production
                'replay_enabled',            // Event replay is enabled for reliability
                'dedup_active',              // Event deduplication is active
            ],
            'recommended_checks' => [
                'pii_sanitization',          // PII sanitization recommended
                'consent_logging',           // Consent audit log recommended for GDPR
                'gdpr_ip_anonymization',     // IP anonymization recommended
                'attribution_tracking',      // UTM attribution recommended
                'health_score_enabled',      // SaaS health score tracking
                'error_tracking_client',      // JS error tracking enabled
                'performance_budget',        // Performance budget configured
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Revenue Forecasting (SaaS Predictive Analytics)
        |-------------------------------------------------------------------------- 
        |
        | Configurable revenue forecasting engine that projects MRR/ARR growth,
        | calculates LTV, estimates runway, and models churn impact.
        | Results are cached for performance.
        |
        */
        'forecasting' => [
            'enabled' => env('ANALYTICS_FORECASTING_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_FORECASTING_CACHE_TTL', 300), // 5 minutes
            'monthly_churn_rate' => (float) env('ANALYTICS_FORECASTING_CHURN_RATE', 0.03), // 3% default
            'growth_rate' => (float) env('ANALYTICS_FORECASTING_GROWTH_RATE', 0.05), // 5% default
            'horizon_days' => (int) env('ANALYTICS_FORECASTING_HORIZON', 90), // 90-day forecast
            'historical_window_days' => (int) env('ANALYTICS_FORECASTING_HISTORICAL_WINDOW', 90),
            'avg_revenue_per_account' => (float) env('ANALYTICS_FORECASTING_ARPU', 99.0),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Churn Prediction (Risk Scoring)
        |-------------------------------------------------------------------------- 
        |
        | Weighted scoring model that evaluates user behavior signals to predict
        | churn risk. Supports configurable signal weights and risk thresholds.
        | Users are classified as low/medium/high/critical risk.
        |
        */
        'churn_prediction' => [
            'enabled' => env('ANALYTICS_CHURN_PREDICTION_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_CHURN_PREDICTION_CACHE_TTL', 600), // 10 minutes
            'high_risk_threshold' => (int) env('ANALYTICS_CHURN_HIGH_RISK', 60),
            'medium_risk_threshold' => (int) env('ANALYTICS_CHURN_MEDIUM_RISK', 30),
            'critical_risk_threshold' => (int) env('ANALYTICS_CHURN_CRITICAL_RISK', 80),
            'inactive_days_threshold' => (int) env('ANALYTICS_CHURN_INACTIVE_DAYS', 14),
            'signal_weights' => [
                'days_inactive' => (float) env('ANALYTICS_CHURN_WEIGHT_INACTIVE', 25.0),
                'usage_decline_pct' => (float) env('ANALYTICS_CHURN_WEIGHT_USAGE', 20.0),
                'support_tickets_30d' => (float) env('ANALYTICS_CHURN_WEIGHT_SUPPORT', 15.0),
                'failed_payments_90d' => (float) env('ANALYTICS_CHURN_WEIGHT_PAYMENTS', 20.0),
                'feature_adoption_low' => (float) env('ANALYTICS_CHURN_WEIGHT_ADOPTION', 10.0),
                'contract_expiring_30d' => (float) env('ANALYTICS_CHURN_WEIGHT_CONTRACT', 15.0),
                'billing_disputes' => (float) env('ANALYTICS_CHURN_WEIGHT_DISPUTES', 20.0),
                'login_frequency_decline' => (float) env('ANALYTICS_CHURN_WEIGHT_LOGIN', 15.0),
                'engagement_score_low' => (float) env('ANALYTICS_CHURN_WEIGHT_ENGAGEMENT', 10.0),
                'plan_downgrade_recent' => (float) env('ANALYTICS_CHURN_WEIGHT_DOWNGRADE', 25.0),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Analytics Insights (Automated Event Intelligence)
        |--------------------------------------------------------------------------
        |
        | When enabled, generates automated insights from event data:
        | trending events, anomalies, funnel drop-offs, and conversion opportunities.
        | Used by admin dashboards and scheduled reports.
        |
        */
        'insights' => [
            'enabled' => env('ANALYTICS_INSIGHTS_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_INSIGHTS_CACHE_TTL', 300), // 5 minutes
            'min_events_for_trend' => (int) env('ANALYTICS_INSIGHTS_MIN_EVENTS', 10),
            'anomaly_threshold' => (float) env('ANALYTICS_INSIGHTS_ANOMALY_THRESHOLD', 3.0), // z-score threshold
            'max_insights' => (int) env('ANALYTICS_INSIGHTS_MAX', 20),
            'trend_window_hours' => (int) env('ANALYTICS_INSIGHTS_TREND_WINDOW', 24),
        ],

        /*
        |--------------------------------------------------------------------------
        | Funnel Velocity Analysis (Time-Based Funnel Metrics)
        |--------------------------------------------------------------------------
        |
        | When enabled, provides time-based funnel analysis measuring how long
        | users spend at each step. Identifies bottlenecks by median/percentile
        | transition times and drop-off rates.
        |
        */
        'funnel_velocity' => [
            'enabled' => env('ANALYTICS_FUNNEL_VELOCITY_ENABLED', true),
            'percentile_window' => (int) env('ANALYTICS_FUNNEL_VELOCITY_PERCENTILE_WINDOW', 100),
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Impact Scoring (Conversion & Retention Correlation)
        |--------------------------------------------------------------------------
        |
        | When enabled, calculates which events most strongly correlate with
        | conversion, retention, and revenue outcomes. Uses point-biserial
        | correlation for statistical rigor without ML infrastructure.
        |
        */
        'event_impact' => [
            'enabled' => env('ANALYTICS_EVENT_IMPACT_ENABLED', true),
            'min_sample_size' => (int) env('ANALYTICS_EVENT_IMPACT_MIN_SAMPLE', 30),
            'conversion_events' => [
                'subscribe', 'purchase', 'trial_converted', 'plan_upgrade',
            ],
            'retention_events' => [
                'feature_used', 'login', 'form_submit', 'page_view',
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | SaaS Metrics Benchmarks (Industry Standards)
        |--------------------------------------------------------------------------
        |
        | When enabled, provides industry-standard benchmark comparisons for
        | 24 key SaaS metrics across 5 categories (revenue, conversion, retention,
        | engagement, funnel). Based on data from OpenView, KeyBanc, ProfitWell,
        | and published SaaS industry research.
        |
        | Compare your metrics against percentile thresholds (p25/p50/p75/p90)
        | to identify areas needing improvement.
        |
        */
        'benchmarks' => [
            'enabled' => env('ANALYTICS_BENCHMARKS_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_BENCHMARKS_CACHE_TTL', 43200), // 12 hours
            'industry' => env('ANALYTICS_BENCHMARKS_INDUSTRY', 'saas'), // saas, b2b, b2c, marketplace
            'company_stage' => (int) env('ANALYTICS_BENCHMARKS_COMPANY_STAGE', 0), // 0=early, 1=growth, 2=mature
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Privacy Sandbox (Cookieless Tracking Future)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, bridges ZeroBoiler analytics events with Chrome's Privacy
        | Sandbox APIs: Topics API, Attribution Reporting API, and Private
        | Aggregation API. Prepares your analytics for the cookieless future.
        |
        | Topics API: Maps events to contextual interest signals
        | Attribution Reporting: Conversion measurement without cross-site IDs
        | Private Aggregation: Aggregate reporting without individual data
        |
        */
        'privacy_sandbox' => [
            'enabled' => env('ANALYTICS_PRIVACY_SANDBOX_ENABLED', false),
            'topics_cache_prefix' => env('ANALYTICS_PRIVACY_SANDBOX_TOPICS_PREFIX', 'zb_topics_'),
            'topics_cache_ttl' => (int) env('ANALYTICS_PRIVACY_SANDBOX_TOPICS_TTL', 604800), // 7 days
            'attribution_window_days' => (int) env('ANALYTICS_PRIVACY_SANDBOX_ATTRIBUTION_WINDOW', 30),
            'aggregation_cache_prefix' => env('ANALYTICS_PRIVACY_SANDBOX_AGG_PREFIX', 'zb_agg_'),
            'aggregation_cache_ttl' => (int) env('ANALYTICS_PRIVACY_SANDBOX_AGG_TTL', 86400), // 24 hours
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Cart State Tracking (E-Commerce)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, tracks cart state (items, value, currency) across sessions.
        | Enables cart abandonment scoring and cross-session cart merge on auth.
        | Used by CartStateManager for checkout funnel analysis.
        |
        */
        'cart_tracking' => [
            'enabled' => env('ANALYTICS_CART_TRACKING_ENABLED', true),
            'cache_prefix' => env('ANALYTICS_CART_CACHE_PREFIX', 'zb_cart_'),
            'cache_ttl' => (int) env('ANALYTICS_CART_CACHE_TTL', 2592000), // 30 days
            'currency' => env('ANALYTICS_CART_CURRENCY', 'USD'),
            'abandonment_decay_rate' => (float) env('ANALYTICS_CART_ABANDONMENT_DECAY', 0.1),
            'abandonment_threshold_seconds' => (int) env('ANALYTICS_CART_ABANDONMENT_THRESHOLD', 86400), // 24 hours
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Affinity Analysis
        |-------------------------------------------------------------------------- 
        |
        | When enabled, computes pairwise affinity scores between event types,
        | measuring how often events co-occur within user sessions.
        | High affinity reveals user behavior patterns for optimization.
        |
        */
        'affinity' => [
            'enabled' => env('ANALYTICS_AFFINITY_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_AFFINITY_CACHE_TTL', 3600), // 1 hour
            'min_co_occurrences' => (int) env('ANALYTICS_AFFINITY_MIN_CO', 5),
            'min_lift_threshold' => (float) env('ANALYTICS_AFFINITY_MIN_LIFT', 1.2),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Onboarding Completion Tracking
        |-------------------------------------------------------------------------- 
        |
        | Tracks multi-step user onboarding progress with configurable milestones.
        | When all required steps are completed, an `onboarding_completed` event
        | is dispatched with timing metadata. Supports required and optional
        | milestones for flexible onboarding funnel analysis.
        |
        */
        'onboarding_tracking' => [
            'enabled' => env('ANALYTICS_ONBOARDING_ENABLED', true),
            'required_steps' => [
                'profile_setup',
                'first_feature_used',
                'team_invited_or_skipped',
            ],
            'optional_steps' => [
                'billing_connected',
                'integration_added',
                'tutorial_completed',
            ],
            'cache_ttl' => (int) env('ANALYTICS_ONBOARDING_CACHE_TTL', 2592000), // 30 days
            'cache_prefix' => env('ANALYTICS_ONBOARDING_CACHE_PREFIX', 'zb_onboarding_'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Funnel Progress Tracking
        |--------------------------------------------------------------------------
        |
        | Tracks user progression through multi-step funnels (signup, checkout,
        | onboarding, trial, activation) with cache-persisted state. Uses
        | FunnelProgressTracker for completion percentage, step timing, and
        | automatic advancement/regression detection.
        |
        | The `known_funnels` list defines which funnel names are scanned
        | when calling `getAllProgress()`. Customize to match your app's
        | actual funnel identifiers.
        |
        */
        'funnel_progress' => [
            'enabled' => env('ANALYTICS_FUNNEL_PROGRESS_ENABLED', true),
            'known_funnels' => [
                'signup',
                'checkout',
                'onboarding',
                'trial',
                'activation',
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Server-Sent Events (SSE) Streaming
        |--------------------------------------------------------------------------
        |
        | Real-time event streaming via SSE for dashboard widgets.
        | When enabled, the GET /api/analytics/sse endpoint is available
        | for persistent HTTP connections that push events as they occur.
        |
        | Configure max connection lifetime, heartbeat interval, and
        | auto-register the SSE routes via the service provider.
        |
        */
        'sse' => [
            'enabled' => env('ANALYTICS_SSE_ENABLED', true),
            'max_connection_seconds' => (int) env('ANALYTICS_SSE_MAX_CONNECTION', 300), // 5 minutes
            'heartbeat_seconds' => (int) env('ANALYTICS_SSE_HEARTBEAT', 30),
            'poll_interval_ms' => (int) env('ANALYTICS_SSE_POLL_INTERVAL', 500),
        ],

        /*
        |--------------------------------------------------------------------------
        | Windowed Event Aggregation (Sparklines)
        |--------------------------------------------------------------------------
        |
        | Time-windowed event counting for dashboard sparkline charts.
        | Counts events per minute/hour/day in the cache for efficient querying.
        | Used by the /api/analytics/sparkline endpoints.
        |
        */
        'windowed_aggregation' => [
            'enabled' => env('ANALYTICS_WINDOWED_AGGREGATION_ENABLED', true),
            'minute_ttl' => (int) env('ANALYTICS_WINDOWED_MINUTE_TTL', 7200), // 2 hours
            'hour_ttl' => (int) env('ANALYTICS_WINDOWED_HOUR_TTL', 86400), // 24 hours
            'day_ttl' => (int) env('ANALYTICS_WINDOWED_DAY_TTL', 2592000), // 30 days
        ],

        /*
        |--------------------------------------------------------------------------
        | Feature Adoption Tracking (PLG)
        |--------------------------------------------------------------------------
        |
        | Tracks which features each user has adopted for product-led growth
        | analysis, activation scoring, and adoption funnels.
        | Data stored in cache with configurable TTL.
        |
        */
        'feature_adoption' => [
            'enabled' => env('ANALYTICS_FEATURE_ADOPTION_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_FEATURE_ADOPTION_TTL', 2592000), // 30 days
            'streak_window_days' => (int) env('ANALYTICS_FEATURE_ADOPTION_STREAK_WINDOW', 7),
        ],

        /*
        |--------------------------------------------------------------------------
        | API Guard (Request Validation & Rate Limiting)
        |--------------------------------------------------------------------------
        |
        | Centralized request validation for incoming analytics API requests.
        | Validates payload size, event name length, batch size, and applies
        | per-client rate limiting before event processing.
        |
        */
        'api_guard' => [
            'enabled' => env('ANALYTICS_API_GUARD_ENABLED', true),
            'batch_max' => (int) env('ANALYTICS_API_GUARD_BATCH_MAX', 25),
            'max_payload_bytes' => (int) env('ANALYTICS_API_GUARD_MAX_PAYLOAD', 65536), // 64KB
            'max_event_name_length' => (int) env('ANALYTICS_API_GUARD_MAX_NAME', 100),
            'rate_window' => (int) env('ANALYTICS_API_GUARD_RATE_WINDOW', 60), // seconds
        ],

    ],
];
