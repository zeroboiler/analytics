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
            'max_batch_size' => (int) env('ANALYTICS_QUEUE_MAX_BATCH_SIZE', 50),
        ],

        /*
        |--------------------------------------------------------------------------
        | Lifecycle Event Mapping (v99.0.0)
        |--------------------------------------------------------------------------
        |
        | Config-driven mapping of application events to analytics events.
        | Extends the built-in ServerSideTracker with custom event mappings.
        | Used by LifecycleEventMapper to register listeners on the event dispatcher.
        |
        | Each entry maps a source event (Laravel or custom) to a ZeroBoiler analytics event.
        | The `params_extractor` is a method name on the event class that returns
        | an associative array of event parameters.
        |
        */
        'lifecycle' => [
            'enabled' => env('ANALYTICS_LIFECYCLE_ENABLED', true),
            'queue_events' => env('ANALYTICS_LIFECYCLE_QUEUE_EVENTS', false),
            'enrich_attribution' => env('ANALYTICS_LIFECYCLE_ENRICH_ATTRIBUTION', true),
            'custom_mappings' => [
                // Example: Map your custom application events to analytics events
                // 'team.invited' => \App\Analytics\Events\TeamInvitedEvent::class,
                // 'workspace.created' => \App\Analytics\Events\WorkspaceCreatedEvent::class,
                // 'billing.upgraded' => \ZeroBoiler\Analytics\Events\SaaS\PlanUpgradeEvent::class,
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Lifecycle Attribution Enrichment (v152.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Automatic attribution context enrichment for SaaS lifecycle events.
        | When enabled, all lifecycle events (sign_up, login, trial_start,
        | subscription, plan_upgrade, cancellation, etc.) automatically receive:
        |
        | - UTM parameters (utm_source, utm_medium, utm_campaign, etc.)
        | - Referrer URL and host
        | - Session ID and IP address
        | - Device context (platform, browser, locale)
        | - Server timestamp
        | - Page URL and path
        | - Traffic source classification (direct, organic_search, paid_social, etc.)
        |
        | Each enrichment type can be individually toggled. The enrichment
        | is non-destructive: existing params take precedence over enriched values.
        |
        | Inspired by Segment's automatic Context, RudderStack's auto-traits,
        | and PostHog's automatic properties.
        |
        */
        'lifecycle_attribution' => [
            'enabled' => env('ANALYTICS_LIFECYCLE_ATTRIBUTION_ENABLED', true),
            'enrichments' => [
                'utm' => env('ANALYTICS_ATTRIBUTION_UTM', true),
                'referrer' => env('ANALYTICS_ATTRIBUTION_REFERRER', true),
                'session' => env('ANALYTICS_ATTRIBUTION_SESSION', true),
                'device' => env('ANALYTICS_ATTRIBUTION_DEVICE', true),
                'timestamp' => env('ANALYTICS_ATTRIBUTION_TIMESTAMP', true),
                'page' => env('ANALYTICS_ATTRIBUTION_PAGE', false),
                'attribution_summary' => env('ANALYTICS_ATTRIBUTION_SUMMARY', true),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | API Configuration (v99.0.0)
        |--------------------------------------------------------------------------
        |
        | Configuration for the analytics API endpoints exposed at /api/analytics.
        | Controls authentication, rate limiting, SDK token validation, and
        | API availability.
        |
        */
        'api' => [
            'enabled' => env('ANALYTICS_API_ENABLED', true),
            'base_url' => env('ANALYTICS_API_BASE_URL', '/api/analytics'),
            'rate_limit' => (int) env('ANALYTICS_API_RATE_LIMIT', 120),
            'rate_limit_per_minute' => (int) env('ANALYTICS_API_RATE_LIMIT_MINUTE', 60),
            'sdk_token' => env('ANALYTICS_API_SDK_TOKEN'),
            'require_auth' => env('ANALYTICS_API_REQUIRE_AUTH', true),
            'allow_public_health' => env('ANALYTICS_API_PUBLIC_HEALTH', true),
            'batch_max_size' => (int) env('ANALYTICS_API_BATCH_MAX', 25),
            'event_name_max_length' => (int) env('ANALYTICS_API_EVENT_NAME_MAX', 100),
        ],

        /*
        |--------------------------------------------------------------------------
        | Client-Side Auto-Tracking (v99.0.0)
        |--------------------------------------------------------------------------
        |
        | Controls which client-side auto-tracking features are enabled.
        | These settings are exposed via Inertia props (zbAnalytics.autoTrack)
        | and respected by the JS client library's initFullStack() function.
        |
        | All features are enabled by default. Set individual features to false
        | to disable them.
        |
        */
        'client_auto_track' => [
            'page_views' => env('ANALYTICS_CLIENT_PAGE_VIEWS', true),
            'scroll_depth' => env('ANALYTICS_CLIENT_SCROLL_DEPTH', true),
            'form_tracking' => env('ANALYTICS_CLIENT_FORM_TRACKING', true),
            'error_tracking' => env('ANALYTICS_CLIENT_ERROR_TRACKING', true),
            'link_tracking' => env('ANALYTICS_CLIENT_LINK_TRACKING', false),
            'session_tracking' => env('ANALYTICS_CLIENT_SESSION_TRACKING', true),
            'idle_timeout' => (int) env('ANALYTICS_CLIENT_IDLE_TIMEOUT', 1800),
            'error_ignore_patterns' => [
                // Regex patterns to ignore for JS error tracking
                // 'ResizeObserver loop',
                // 'Non-Error promise rejection',
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Revenue Checksum Verification (v88.0.0)
        |--------------------------------------------------------------------------
        |
        | HMAC-SHA256 integrity verification for revenue-critical events.
        | Prevents replay attacks and ensures data integrity for purchases,
        | subscriptions, refunds, and plan changes.
        |
        | Secret defaults to app.key if not explicitly configured.
        |
        */
        'revenue_checksum' => [
            'enabled' => env('ANALYTICS_REVENUE_CHECKSUM_ENABLED', true),
            'secret' => env('ANALYTICS_REVENUE_CHECKSUM_SECRET', ''),
            'replay_ttl' => (int) env('ANALYTICS_REVENUE_CHECKSUM_REPLAY_TTL', 86400), // 24 hours
            'require_checksum' => env('ANALYTICS_REVENUE_CHECKSUM_REQUIRE', false),
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Deduplication Cache (v88.0.0)
        |--------------------------------------------------------------------------
        |
        | Redis/cache-backed event deduplication for enterprise-grade idempotency.
        | Prevents duplicate event processing within configurable time windows.
        |
        | Strategies:
        | - 'exact': deduplicates identical events (same name + params + identity)
        | - 'fuzzy': deduplicates events with same name + identity within window
        |
        */
        'dedup_cache' => [
            'enabled' => env('ANALYTICS_DEDUP_CACHE_ENABLED', true),
            'strategy' => env('ANALYTICS_DEDUP_CACHE_STRATEGY', 'exact'),
            'windows' => [
                'ecommerce' => (int) env('ANALYTICS_DEDUP_ECOMMERCE', 60),
                'saas' => (int) env('ANALYTICS_DEDUP_SAAS', 30),
                'engagement' => (int) env('ANALYTICS_DEDUP_ENGAGEMENT', 10),
                'page_view' => (int) env('ANALYTICS_DEDUP_PAGEVIEW', 5),
                'custom' => (int) env('ANALYTICS_DEDUP_CUSTOM', 5),
            ],
            'max_keys' => (int) env('ANALYTICS_DEDUP_MAX_KEYS', 100000),
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
            'auto_link' => env('ANALYTICS_IDENTITY_AUTO_LINK', true), // Auto-persist client_id ↔ user_id in cache on identify()

            // Identity Resolution Service (v3.2.0)
            // Cache-backed client_id ↔ user_id persistent mapping
            'cache_prefix' => env('ANALYTICS_IDENTITY_CACHE_PREFIX', 'zb_identity_'),
            'link_ttl' => (int) env('ANALYTICS_IDENTITY_LINK_TTL', 7776000), // 90 days (seconds)
            'max_links_per_user' => (int) env('ANALYTICS_IDENTITY_MAX_LINKS_USER', 50),
            'max_links_per_client' => (int) env('ANALYTICS_IDENTITY_MAX_LINKS_CLIENT', 10),
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
        | Checkout Flow Tracking (v6.8.0)
        |-------------------------------------------------------------------------- 
        |
        | Multi-step checkout funnel tracking configuration.
        | Tracks users through cart_review → shipping_info → payment_info →
        | order_review → confirmation with step-level conversion analytics.
        |
        */
        'checkout_tracking' => [
            'enabled' => env('ANALYTICS_CHECKOUT_TRACKING_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_CHECKOUT_CACHE_TTL', 86400), // 24 hours
            'currency' => env('ANALYTICS_CHECKOUT_CURRENCY', 'USD'),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Session Fingerprint (v25.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Deterministic browser fingerprinting for bot detection and quality scoring.
        | Generates stable SHA-256 hashes from normalized browser signals and tracks
        | them in cache for cross-request analysis.
        |
        */
        'session_fingerprint' => [
            'enabled' => env('ANALYTICS_SESSION_FINGERPRINT_ENABLED', false),
            'cache_prefix' => env('ANALYTICS_SESSION_FINGERPRINT_CACHE_PREFIX', 'zb_fp_'),
            'fingerprint_ttl' => (int) env('ANALYTICS_SESSION_FINGERPRINT_TTL', 3600), // 1 hour
            'max_fingerprints_per_client' => (int) env('ANALYTICS_SESSION_FINGERPRINT_MAX_PER_CLIENT', 10),
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
        | SaaS KPI Calculator (v6.8.0)
        |-------------------------------------------------------------------------- 
        |
        | Industry-standard SaaS metrics computation service.
        | Computes MRR, ARR, LTV, NRR, churn rate, Rule of 40, Quick Ratio,
        | trial conversion, and activation rate from raw billing data.
        |
        */
        'saas_kpi_calc' => [
            'enabled' => env('ANALYTICS_SAAS_KPI_CALC_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_SAAS_KPI_CACHE_TTL', 300), // 5 minutes
            'mrr_goal' => (float) env('ANALYTICS_SAAS_KPI_MRR_GOAL', 10000),
            'churn_warning' => (float) env('ANALYTICS_SAAS_KPI_CHURN_WARNING', 0.05),
            'ltv_cac_target' => (float) env('ANALYTICS_SAAS_KPI_LTV_CAC_TARGET', 3.0),
            'quick_ratio_target' => (float) env('ANALYTICS_SAAS_KPI_QUICK_RATIO_TARGET', 4.0),
            'rule_of_40_target' => (float) env('ANALYTICS_SAAS_KPI_RULE_OF_40_TARGET', 40.0),
        ],

        /*
        |--------------------------------------------------------------------------
        | Revenue Waterfall (v78.0.0)
        |--------------------------------------------------------------------------
        |
        | MRR movement tracking: new, expansion, contraction, reactivation, churn.
        | Used by RevenueWaterfallService for revenue waterfall charts and
        | net MRR retention rate calculation.
        |
        */
        'revenue_waterfall' => [
            'enabled' => env('ANALYTICS_REVENUE_WATERFALL_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_REVENUE_WATERFALL_CACHE_TTL', 300), // 5 minutes
            'currency' => env('ANALYTICS_REVENUE_WATERFALL_CURRENCY', 'USD'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Feature Flag Analytics (v78.0.0)
        |--------------------------------------------------------------------------
        |
        | Track feature flag evaluations, variant distribution, and
        | conversion rates for A/B testing and feature adoption analysis.
        |
        */
        'feature_flags' => [
            'enabled' => env('ANALYTICS_FEATURE_FLAGS_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_FEATURE_FLAGS_CACHE_TTL', 300), // 5 minutes
            'track_exposures' => env('ANALYTICS_FEATURE_FLAGS_TRACK_EXPOSURES', true), // Auto-track flag evaluations
            'track_conversions' => env('ANALYTICS_FEATURE_FLAGS_TRACK_CONVERSIONS', true), // Auto-track flag conversions
            'ignored_flags' => [], // Flag names that should NOT be tracked as analytics events
        ],

        /*
        |--------------------------------------------------------------------------
        | SaaS Growth Metrics (v78.0.0)
        |--------------------------------------------------------------------------
        |
        | Industry-standard growth metrics: activation rate, stickiness (DAU/MAU),
        | virality coefficient (K-factor), retention curves, and milestone tracking.
        |
        */
        'growth_metrics' => [
            'enabled' => env('ANALYTICS_GROWTH_METRICS_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_GROWTH_METRICS_CACHE_TTL', 3600), // 1 hour
            'activation_events' => [
                // Events that count as user activation (aha moment).
                // Customize for your product.
                // 'first_project_created', 'first_api_call', 'team_invited',
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Retention Cohort Analysis (v93.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Configuration for retention cohort tracking and analysis.
        | The AnalyticsRetentionService uses this to compute D1/D7/D30/W1/M1
        | retention rates and cohort-based retention curves.
        |
        | Cohort intervals define when retention snapshots are taken relative
        | to the cohort's start date (usually signup or first_value event).
        |
        */
        'retention_cohort' => [
            'enabled' => env('ANALYTICS_RETENTION_COHORT_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_RETENTION_COHORT_CACHE_TTL', 3600), // 1 hour
            'intervals' => ['D1', 'D3', 'D7', 'D14', 'D30', 'W1', 'W2', 'W4', 'M1', 'M3'],
            'default_cohort_event' => 'sign_up', // The event that defines cohort start
            'retention_statuses' => ['retained', 'returning', 'dormant', 'churned'],
            'dormant_threshold_days' => (int) env('ANALYTICS_RETENTION_DORMANT_DAYS', 14),
            'churn_threshold_days' => (int) env('ANALYTICS_RETENTION_CHURN_DAYS', 30),
        ],

        /*
        |--------------------------------------------------------------------------
        | SaaS Event Templates (v6.9.0)
        |--------------------------------------------------------------------------
        |
        | Pre-configured event templates for common SaaS patterns.
        | When SaaSEventTemplateService is used, these settings control
        | default values for currency, UTM tracking, and auto-attachment
        | of attribution context.
        |
        */
        'event_templates' => [
            'default_currency' => env('ANALYTICS_TEMPLATES_CURRENCY', 'USD'),
            'auto_utm_attach' => env('ANALYTICS_TEMPLATES_AUTO_UTM', true),
            'auto_user_id_attach' => env('ANALYTICS_TEMPLATES_AUTO_USER_ID', true),
            'include_provider_params' => env('ANALYTICS_TEMPLATES_PROVIDER_PARAMS', true),
            'definitions' => [
                // Custom event templates can be defined here.
                // Example: 'my.custom_event' => ['name' => 'custom_event', 'category' => 'custom', 'params' => [...]],
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Sanitization (v12.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Production-grade event parameter sanitization. When enabled, all events
        | are validated and cleaned before dispatch. Strips HTML, null bytes,
        | enforces naming conventions, truncates oversized values, and blocks
        | sensitive parameter keys (password, token, secret, etc.).
        |
        | Set to true for production environments.
        |
        */
        'sanitization' => [
            'enabled' => env('ANALYTICS_SANITIZATION_ENABLED', false),
            'max_param_count' => (int) env('ANALYTICS_SANITIZATION_MAX_PARAMS', 100),
            'max_key_length' => (int) env('ANALYTICS_SANITIZATION_MAX_KEY_LENGTH', 100),
            'max_value_length' => (int) env('ANALYTICS_SANITIZATION_MAX_VALUE_LENGTH', 500),
            'strict_naming' => env('ANALYTICS_SANITIZATION_STRICT_NAMING', false),
            'strip_html' => env('ANALYTICS_SANITIZATION_STRIP_HTML', true),
            'strip_null_bytes' => env('ANALYTICS_SANITIZATION_STRIP_NULL_BYTES', true),
            'normalize_booleans' => env('ANALYTICS_SANITIZATION_NORMALIZE_BOOLEANS', true),
            'truncate_strings' => env('ANALYTICS_SANITIZATION_TRUNCATE_STRINGS', true),
            'disallowed_keys' => ['password', 'token', 'secret', 'api_key', 'credit_card', 'ssn'],
            'max_event_name_length' => (int) env('ANALYTICS_SANITIZATION_MAX_NAME_LENGTH', 100),
            'reserved_prefixes' => ['_zb_', '_ga_', '_fb_', '_meta_', '_sentry_'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Buffer (v141.0.0)
        |--------------------------------------------------------------------------
        |
        | Server-side event buffer for debounce and once-only tracking.
        | Used by AnalyticsManager::trackDebounced() and trackOnce() to
        | suppress duplicate events within configurable time windows.
        |
        */
        'event_buffer' => [
            'max_capacity' => (int) env('ANALYTICS_EVENT_BUFFER_MAX_CAPACITY', 100),
            'ttl_seconds' => (int) env('ANALYTICS_EVENT_BUFFER_TTL', 3600),
            'dedup_window_seconds' => (int) env('ANALYTICS_EVENT_BUFFER_DEDUP_WINDOW', 10),
        ],

        /*
        |--------------------------------------------------------------------------
        | Field Validation (v125.0.0)
        |--------------------------------------------------------------------------
        |
        | Config-driven per-event field validation and type coercion.
        | Enforces data quality on incoming events by checking required fields,
        | types, value ranges, enum whitelists, regex patterns, and structural formats.
        |
        | Rules are applied in order: event-specific → wildcard → global.
        | Wildcard patterns support suffix matching (e.g., "saas_*" matches "saas_login").
        |
        | Each rule supports: type, required, nullable, min, max, enum, regex, format, default.
        | Set 'coerce' => false to skip automatic type coercion for a field.
        |
        | Use Analytics::validateEvent('purchase', $params) to validate programmatically.
        |
        */
        'field_validation' => [
            'enabled' => env('ANALYTICS_FIELD_VALIDATION_ENABLED', false),
            'debug' => env('ANALYTICS_FIELD_VALIDATION_DEBUG', false),

            // Per-event field rules
            'rules' => [
                // 'purchase' => [
                //     'transaction_id' => ['type' => 'string', 'required' => true, 'min' => 1, 'max' => 100],
                //     'value' => ['type' => 'float', 'required' => true, 'min' => 0],
                //     'currency' => ['type' => 'string', 'required' => true, 'format' => 'currency_code', 'default' => 'USD'],
                //     'tax' => ['type' => 'float', 'nullable' => true, 'min' => 0],
                //     'shipping' => ['type' => 'float', 'nullable' => true, 'min' => 0],
                //     'items' => ['type' => 'array', 'required' => true, 'min' => 1],
                // ],
                // 'sign_up' => [
                //     'method' => ['type' => 'string', 'enum' => ['email', 'google', 'github', 'sso']],
                // ],
                // 'page_view' => [
                //     'page_location' => ['type' => 'string', 'format' => 'url'],
                // ],
            ],

            // Global rules applied to ALL events
            'global_rules' => [
                // 'session_id' => ['type' => 'string', 'nullable' => true, 'regex' => '/^[a-f0-9]{32}$/i'],
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Data Mart (v7.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Pre-aggregated OLAP-style event rollup cubes for instant dashboard queries.
        | Materializes raw events into time-binned summary cells stored in the cache,
        | enabling fast top-N queries without scanning raw event streams.
        |
        | Inspired by the data mart pattern used by Amplitude, Mixpanel, and PostHog.
        |
        */
        'data_mart' => [
            'enabled' => env('ANALYTICS_DATA_MART_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_DATA_MART_CACHE_TTL', 86400), // 24 hours
            'default_granularity' => env('ANALYTICS_DATA_MART_GRANULARITY', 'hour'), // minute, hour, day, week, month
            'max_dimensions' => (int) env('ANALYTICS_DATA_MART_MAX_DIMENSIONS', 50),
            'auto_dimensions' => ['event_name', 'category', 'provider'],
            'tracked_categories' => [], // empty = all categories
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Insight Engine (v7.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Automated analytics insight generation combining data mart rollups,
        | catalog coverage analysis, and statistical health signals.
        |
        | Inspired by Amplitude Compass and Mixpanel Signal.
        |
        */
        'insight_engine' => [
            'enabled' => env('ANALYTICS_INSIGHT_ENGINE_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_INSIGHT_ENGINE_CACHE_TTL', 300), // 5 minutes
            'top_movers_count' => (int) env('ANALYTICS_INSIGHT_ENGINE_TOP_MOVERS', 10),
            'drift_threshold' => (float) env('ANALYTICS_INSIGHT_ENGINE_DRIFT_THRESHOLD', 0.3),
            'growth_threshold' => (float) env('ANALYTICS_INSIGHT_ENGINE_GROWTH_THRESHOLD', 0.2),
            'decline_threshold' => (float) env('ANALYTICS_INSIGHT_ENGINE_DECLINE_THRESHOLD', -0.15),
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Recommendations & Intelligence (v7.1.0)
        |--------------------------------------------------------------------------
        |
        | Configures the EventRecommendationService and ProviderGapAnalyzer.
        | These services analyze your tracked events against the full catalog
        | and recommend instrumentation gaps ranked by business impact.
        |
        | Use excluded_events to suppress recommendations for events your
        | app intentionally does not track (e.g., video_play if you have
        | no video content).
        |
        */
        'recommendations' => [
            'enabled' => env('ANALYTICS_RECOMMENDATIONS_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_RECOMMENDATIONS_CACHE_TTL', 300), // 5 minutes
            'excluded_events' => [
                // 'video_play', // Uncomment to exclude if no video content
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Cohort Waterfall Analysis (v7.5.0)
        |--------------------------------------------------------------------------
        |
        | Revenue flow decomposition by cohort period. Visualizes how users
        | flow through signup → trial → conversion → active → churn stages.
        | Produces waterfall-style data for dashboard chart rendering.
        |
        | Inspired by ChartMogul, ProfitWell, and Baremetrics revenue waterfall.
        |
        */
        'cohort_waterfall' => [
            'enabled' => env('ANALYTICS_COHORT_WATERFALL_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_COHORT_WATERFALL_CACHE_TTL', 600), // 10 minutes
            'granularity' => env('ANALYTICS_COHORT_WATERFALL_GRANULARITY', 'monthly'), // weekly, monthly
            'currency' => env('ANALYTICS_COHORT_WATERFALL_CURRENCY', 'USD'),
            'projection_months' => (int) env('ANALYTICS_COHORT_WATERFALL_PROJECTION', 6),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Funnel Drop-off Intelligence (v7.5.0)
        |-------------------------------------------------------------------------- 
        |
        | Smart funnel analysis with bottleneck detection, anomaly detection,
        | time-to-convert analysis, and actionable recommendations.
        | Produces structured data for funnel visualization dashboards.
        |
        | Inspired by Mixpanel Funnel Analysis and Amplitude Pathfinder.
        |
        */
        'funnel_intelligence' => [
            'enabled' => env('ANALYTICS_FUNNEL_INTELLIGENCE_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_FUNNEL_INTELLIGENCE_CACHE_TTL', 300), // 5 minutes
            'bottleneck_threshold' => (float) env('ANALYTICS_FUNNEL_BOTTLENECK_THRESHOLD', 50.0), // % drop-off to flag
            'anomaly_threshold' => (float) env('ANALYTICS_FUNNEL_ANOMALY_THRESHOLD', 2.0), // spike multiplier
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Signal Intelligence (v7.7.0)
        |-------------------------------------------------------------------------- 
        |
        | Observability layer for the analytics pipeline. Monitors event dispatch
        | patterns across all providers, detects anomalies, tracks staleness,
        | and computes signal-to-noise ratio and dispatch balance scores.
        |
        | Provides a composite "signal score" (0-100) for dashboards and alerting.
        | Inspired by Datadog Signal Intelligence and Honeycomb BubbleUp.
        |
        */
        'signal_intelligence' => [
            'enabled' => env('ANALYTICS_SIGNAL_INTELLIGENCE_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_SIGNAL_INTELLIGENCE_CACHE_TTL', 300), // 5 minutes
            'staleness_threshold' => (int) env('ANALYTICS_SIGNAL_STALENESS_THRESHOLD', 3600), // 1 hour (seconds)
            'anomaly_window' => (int) env('ANALYTICS_SIGNAL_ANOMALY_WINDOW', 600), // 10 minutes (seconds)
            'anomaly_deviation' => (float) env('ANALYTICS_SIGNAL_ANOMALY_DEVIATION', 2.0), // 2x baseline = anomaly
        ],

        /*
        |--------------------------------------------------------------------------
        | Lifecycle Event Mapping (v15.0.0)
        |--------------------------------------------------------------------------
        |
        | Config-driven mapping of Laravel application events to analytics events.
        | The LifecycleEventMapper reads this section to automatically dispatch
        | analytics events when application events fire (auth.login, subscription.created, etc.).
        |
        | Set 'enabled' to false to disable all server-side lifecycle tracking.
        | Individual events can be toggled on/off in the 'events' sub-array.
        | Use 'custom_mappings' to register your own event → analytics class mappings.
        |
        | Example:
        |   'custom_mappings' => [
        |       'team.invited' => ['source' => 'team.invited', 'target' => '\\App\\Analytics\\Events\\TeamInvitedEvent::class'],
        |   ],
        |
        */
        'lifecycle' => [
            'enabled' => env('ANALYTICS_LIFECYCLE_ENABLED', true),
            'events' => [
                // Toggle individual lifecycle mappings on/off
                // These map to LifecycleEventMapper::DEFAULT_MAPPINGS keys
            ],
            'custom_mappings' => [
                // Add custom event → analytics class mappings here
                // 'app.custom_event' => ['source' => 'app.custom_event', 'target' => '\\App\\Analytics\\Events\\CustomEvent::class'],
            ],
            'override_defaults' => env('ANALYTICS_LIFECYCLE_OVERRIDE_DEFAULTS', false),

            // Queue lifecycle events asynchronously (v79.0.0)
            // When true, LifecycleEventSubscriber dispatches lifecycle events
            // through the configured queue connection instead of synchronous tracking.
            'queue_events' => env('ANALYTICS_LIFECYCLE_QUEUE_EVENTS', false),
        ],

        /*
        |--------------------------------------------------------------------------
        | SaaS Onboarding Funnel (v19.0.0)
        |--------------------------------------------------------------------------
        |
        | Unified onboarding funnel tracking for SaaS products. Tracks the complete
        | user journey from signup through activation with cache-persisted state.
        | Used by SaaSOnboardingFunnelService for progress tracking, drop-off
        | detection, and funnel analytics.
        |
        */
        'onboarding_funnel' => [
            'enabled' => env('ANALYTICS_ONBOARDING_FUNNEL_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_ONBOARDING_FUNNEL_CACHE_TTL', 86400), // 24 hours
            'cache_prefix' => env('ANALYTICS_ONBOARDING_FUNNEL_CACHE_PREFIX', 'zb_onboarding_'),
            'stages' => [
                'sign_up', 'email_verified', 'first_login', 'trial_start',
                'first_feature', 'team_created', 'integration_connected',
                'subscription', 'plan_upgrade', 'activated',
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Track Links (Client-Side)
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
        | Event Blueprints (v66.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Reusable, versioned event templates that enforce consistent parameter
        | naming and structure across codebases. Inspired by Segment's Event Spec
        | and RudderStack's event templates.
        |
        | Define custom blueprints in the 'library' array. Each blueprint has:
        |   - name: Unique identifier (dot.case, e.g. 'saas.signup.email')
        |   - label: Human-readable name
        |   - base_event: Catalog event this blueprint wraps
        |   - required_params: Parameter keys that must be provided
        |   - default_params: Pre-filled parameter values
        |   - param_types: Type validation for parameters
        |   - priority: Default event priority
        |
        | Built-in blueprints are automatically available (saas.*, ecommerce.*,
        | engagement.*, identity.*). Add custom ones below.
        |
        */
        'blueprints' => [
            'enabled' => env('ANALYTICS_BLUEPRINTS_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_BLUEPRINTS_CACHE_TTL', 86400), // 24 hours
            'library' => [
                // Example custom blueprint:
                // 'saas.invitation.sent' => [
                //     'name' => 'saas.invitation.sent',
                //     'label' => 'Team Invitation Sent',
                //     'description' => 'User sent a team invitation',
                //     'base_event' => 'invite_sent',
                //     'category' => 'saas',
                //     'default_params' => [],
                //     'required_params' => ['inviter_id', 'invitee_email'],
                //     'param_types' => ['inviter_id' => 'string', 'invitee_email' => 'string'],
                //     'priority' => 'normal',
                //     'version' => '1.0.0',
                //     'metadata' => ['owner' => 'growth'],
                // ],
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Segment Export (v66.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Segment-compatible event export configuration. When enabled, events can
        | be exported in Segment's HTTP API v2 JSON format for migration, A/B
        | testing between platforms, or dual-dispatch to Segment.
        |
        */
        'segment_export' => [
            'enabled' => env('ANALYTICS_SEGMENT_EXPORT_ENABLED', false),
            'write_key' => env('ANALYTICS_SEGMENT_WRITE_KEY', ''),
            'api_url' => env('ANALYTICS_SEGMENT_API_URL', 'https://api.segment.io/v1/batch'),
            'batch_size' => (int) env('ANALYTICS_SEGMENT_BATCH_SIZE', 100),
            'timeout' => (int) env('ANALYTICS_SEGMENT_TIMEOUT', 10),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Lifecycle Hooks (v66.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Pre/post-dispatch hooks for event enrichment, filtering, and side-effects.
        | When enabled, registered before/after hooks run for every tracked event.
        |
        */
        'lifecycle_hooks' => [
            'enabled' => env('ANALYTICS_LIFECYCLE_HOOKS_ENABLED', true),
            'max_hooks' => (int) env('ANALYTICS_LIFECYCLE_HOOKS_MAX', 50),
            'timeout' => (int) env('ANALYTICS_LIFECYCLE_HOOKS_TIMEOUT', 5),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | SaaS Coverage Report (v67.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Configuration for the SaaS Analytics Coverage Report service that audits
        | all 12 core capabilities required for industry-standard SaaS analytics.
        | The coverage report scores each capability and produces a weighted
        | overall score (0-100) with letter grades (A+ to F).
        |
        */
        'saas_coverage' => [
            'cache_ttl' => (int) env('ANALYTICS_SAAS_COVERAGE_CACHE_TTL', 3600), // 1 hour
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Trend Forecast Engine (v59.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Forward-looking trend projection for analytics event streams using
        | linear regression, Holt's double exponential smoothing, and seasonal
        | decomposition. Produces forecast points with confidence intervals
        | for dashboard rendering, alerting, and capacity planning.
        |
        | Used by EventTrendForecastService and AnalyticsTrendForecastCommand.
        | Complements EventTimeSeriesService (backward-looking) and
        | RevenueForecastService (revenue-specific forecasting).
        |
        */
        'trend_forecast' => [
            'enabled' => env('ANALYTICS_TREND_FORECAST_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_TREND_FORECAST_CACHE_TTL', 600), // 10 minutes
            'forecast_horizon' => (int) env('ANALYTICS_TREND_FORECAST_HORIZON', 7), // days
            'confidence_level' => (float) env('ANALYTICS_TREND_FORECAST_CONFIDENCE', 0.95),
            'min_data_points_ratio' => (float) env('ANALYTICS_TREND_FORECAST_MIN_RATIO', 0.3),
            'seasonal_enabled' => env('ANALYTICS_TREND_FORECAST_SEASONAL', true),
            'seasonal_periods' => ['daily', 'weekly'],
            'max_history_days' => (int) env('ANALYTICS_TREND_FORECAST_HISTORY_DAYS', 30),
        ],

        /*
        |--------------------------------------------------------------------------
        | UTM Parameter Manager (v55.0.0)
        |--------------------------------------------------------------------------
        |
        | Unified UTM parameter management: validation, sanitization, extraction,
        | URL decoration (adding UTM to outbound links), and cleaning (stripping
        | internal tracking params like fbclid, gclid from URLs).
        |
        | Used by UtmParameterManager service and AnalyticsUtmCommand.
        |
        */
        'utm_manager' => [
            'enabled' => env('ANALYTICS_UTM_MANAGER_ENABLED', true),
            'max_value_length' => (int) env('ANALYTICS_UTM_MAX_VALUE_LENGTH', 500),
            'max_key_length' => (int) env('ANALYTICS_UTM_MAX_KEY_LENGTH', 100),
            'lowercase_source_medium' => env('ANALYTICS_UTM_LOWERCASE', true),
            'trim_values' => env('ANALYTICS_UTM_TRIM', true),
            'strip_html' => env('ANALYTICS_UTM_STRIP_HTML', true),
            'aliases' => [
                // Map non-standard param names to canonical UTM names
                // 'source' => 'utm_source',
                // 'medium' => 'utm_medium',
                // 'campaign' => 'utm_campaign',
            ],
            'required_for_completeness' => ['utm_source', 'utm_medium', 'utm_campaign'],
            'internal_params' => [
                // Additional custom internal tracking params to strip
                // 'custom_tracker_id',
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | User Journey Orchestration (v22.0.0)
        |--------------------------------------------------------------------------
        |
        | Configure the user journey stage progression tracking system.
        | The AnalyticsJourneyOrchestrator tracks users through defined stages
        | and provides funnel distribution analysis for activation and retention.
        |
        | Stages are ordered — users can only advance forward.
        | Customize stages to match your product's user lifecycle.
        |
        */
        'journey' => [
            'enabled' => env('ANALYTICS_JOURNEY_ENABLED', true),
            'cache_prefix' => env('ANALYTICS_JOURNEY_CACHE_PREFIX', 'zb_journey_'),
            'cache_ttl' => (int) env('ANALYTICS_JOURNEY_CACHE_TTL', 86400), // 24 hours
            'stages' => [
                'visitor', 'signed_up', 'email_verified', 'activated',
                'engaged', 'converting', 'retained', 'champion',
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Feature Flag Analytics (v22.0.0)
        |--------------------------------------------------------------------------
        |
        | Configuration for the AnalyticsFeatureFlagService which tracks
        | feature flag evaluations, exposures, and conversions.
        |
        */
        'feature_flags' => [
            'enabled' => env('ANALYTICS_FEATURE_FLAGS_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_FEATURE_FLAGS_CACHE_TTL', 86400),
        ],

        /*
        |--------------------------------------------------------------------------
        | Dashboard (v23.0.0)
        |--------------------------------------------------------------------------
        |
        | Configuration for the AnalyticsDashboardService which provides
        | pre-computed dashboard data for admin interfaces. All data is
        | cache-backed for fast rendering.
        |
        */
        'dashboard' => [
            'cache_ttl' => (int) env('ANALYTICS_DASHBOARD_CACHE_TTL', 300), // 5 minutes
            'top_events_count' => (int) env('ANALYTICS_DASHBOARD_TOP_EVENTS', 20),
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Idempotency (v23.0.0)
        |--------------------------------------------------------------------------
        |
        | Server-side event deduplication to prevent duplicate processing
        | when clients retry requests due to network failures.
        |
        | Strategies:
        |   - `client_key`: Use client-provided idempotency key (recommended)
        |   - `fingerprint`: Auto-generate from event name + params hash
        |   - `hybrid`: Check both (most aggressive)
        |
        */
        'idempotency' => [
            'enabled' => env('ANALYTICS_IDEMPOTENCY_ENABLED', false),
            'ttl' => (int) env('ANALYTICS_IDEMPOTENCY_TTL', 3600), // 1 hour
            'strategy' => env('ANALYTICS_IDEMPOTENCY_STRATEGY', 'client_key'),
            'cache_prefix' => env('ANALYTICS_IDEMPOTENCY_CACHE_PREFIX', 'zb_idem_'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Webhook Event Subscriptions (v23.0.0)
        |--------------------------------------------------------------------------
        |
        | Real-time event push to external webhooks (Slack, Teams, Discord, custom).
        | Events matching subscription filters are pushed immediately after dispatch.
        | Supports HMAC signing, retry with exponential backoff, and rate limiting.
        |
        | Example subscription:
        |   'subscriptions' => [
        |       [
        |           'url' => 'https://hooks.slack.com/services/T/B/K',
        |           'secret' => env('SLACK_WEBHOOK_SECRET'),
        |           'events' => ['purchase', 'sign_up', 'trial_converted'],
        |           'format' => 'slack', // json, slack, teams, discord
        |           'timeout' => 5,
        |           'retries' => 2,
        |           'enabled' => true,
        |       ],
        |   ],
        |
        */
        'webhook_subscriptions' => [
            'enabled' => env('ANALYTICS_WEBHOOK_SUBS_ENABLED', false),
            'subscriptions' => [],
            'default_timeout' => (int) env('ANALYTICS_WEBHOOK_SUBS_TIMEOUT', 5),
            'default_retries' => (int) env('ANALYTICS_WEBHOOK_SUBS_RETRIES', 2),
            'rate_limit_per_minute' => (int) env('ANALYTICS_WEBHOOK_SUBS_RATE_LIMIT', 60),
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
        | For self-hosted Plausible instances, set `base_url` to your instance
        | API endpoint and `custom_script_url` to your tracking script path.
        |
        */
        'plausible' => [
            'enabled' => env('ANALYTICS_PLAUSIBLE_ENABLED', false),
            'domain' => env('ANALYTICS_PLAUSIBLE_DOMAIN', ''),
            'api_key' => env('ANALYTICS_PLAUSIBLE_API_KEY', ''),
            'base_url' => env('ANALYTICS_PLAUSIBLE_BASE_URL', 'https://plausible.io/api/event'),
            'custom_script_url' => env('ANALYTICS_PLAUSIBLE_CUSTOM_SCRIPT_URL'), // e.g., 'https://stats.example.com/js/script.js'
            'batch_size' => (int) env('ANALYTICS_PLAUSIBLE_BATCH_SIZE', 20), // Max events per batch API call
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

            /*
            | PostHog Conversions API (Server-Side)
            |
            | When enabled, PostHog events are sent via the Conversions API
            | endpoint (/capture/) with $set person properties for user identity.
            | Provides server-side event reliability and attribution bypassing
            | ad blockers.
            |
            */
            'capi_enabled' => env('ANALYTICS_POSTHOG_CAPI_ENABLED', true),
            'capture_path' => env('ANALYTICS_POSTHOG_CAPTURE_PATH', '/capture/'),
            'batch_size' => (int) env('ANALYTICS_POSTHOG_BATCH_SIZE', 50), // Max events per batch capture call
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
        | Mixpanel Analytics (Optional, v10.0.0)
        |--------------------------------------------------------------------------
        |
        | Product analytics with user profiling, funnel analysis, and cohort tracking.
        | Server-side tracking via Mixpanel /track API endpoint.
        | Enable by setting ANALYTICS_MIXPANEL_ENABLED=true and providing your token.
        |
        */
        'mixpanel' => [
            'enabled' => env('ANALYTICS_MIXPANEL_ENABLED', false),
            'token' => env('ANALYTICS_MIXPANEL_TOKEN', ''),
            'host' => env('ANALYTICS_MIXPANEL_HOST', 'https://api.mixpanel.com'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Amplitude Analytics (Optional, v10.0.0)
        |--------------------------------------------------------------------------
        |
        | Product analytics with behavioral cohorting, revenue analytics, and
        | user journey analysis. Server-side tracking via Amplitude V2 HTTP API.
        | Enable by setting ANALYTICS_AMPLITUDE_ENABLED=true and providing your API key.
        |
        */
        'amplitude' => [
            'enabled' => env('ANALYTICS_AMPLITUDE_ENABLED', false),
            'api_key' => env('ANALYTICS_AMPLITUDE_API_KEY', ''),
            'host' => env('ANALYTICS_AMPLITUDE_HOST', 'https://api2.amplitude.com'),
            'platform' => env('ANALYTICS_AMPLITUDE_PLATFORM', 'Laravel/Server'),
        ],

        /*
        |--------------------------------------------------------------------------
        | B2B Group/Account Analytics (v9.5.0)
        |--------------------------------------------------------------------------
        |
        | Enables account/company-level analytics following the Segment/Mixpanel
        | group specification. Users can be associated with groups (organizations,
        | companies, workspaces) and group-level traits (name, industry, plan,
        | MRR, employee count) are tracked alongside user events.
        |
        | All group data is stored in the Laravel cache with configurable TTL.
        |
        */
        'group' => [
            'enabled' => env('ANALYTICS_GROUP_ENABLED', true),
            'ttl' => (int) env('ANALYTICS_GROUP_TTL', 7776000), // 90 days (seconds)
            'max_members_per_group' => (int) env('ANALYTICS_GROUP_MAX_MEMBERS', 1000),
            'max_groups_per_user' => (int) env('ANALYTICS_GROUP_MAX_GROUPS_USER', 10),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Timeline (v75.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Chronological event timeline for user journey analysis and dashboard
        | rendering. Records events per client ID with session grouping,
        | funnel annotation, and gap detection for churn-risk identification.
        |
        | Inspired by Amplitude User Lookup, Mixpanel User Profile, and
        | PostHog User Activity feeds.
        |
        */
        'timeline' => [
            'enabled' => env('ANALYTICS_TIMELINE_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_TIMELINE_CACHE_TTL', 3600), // 1 hour
            'max_entries' => (int) env('ANALYTICS_TIMELINE_MAX_ENTRIES', 500),
            'session_timeout' => (int) env('ANALYTICS_TIMELINE_SESSION_TIMEOUT', 1800), // 30 minutes (seconds)
            'gap_thresholds' => [
                'trial_start_to_login' => 172800, // 48 hours
                'signup_to_trial' => 604800,     // 7 days
                'purchase_to_return' => 2592000,  // 30 days
            ],
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
        | Event Fingerprinting (v8.2.0)
        |--------------------------------------------------------------------------
        |
        | Content-addressed event fingerprinting for deduplication and replay.
        | Computes stable SHA-256 hashes based on event name, params, client/user
        | identity, and a configurable time bucket. Events sharing a fingerprint
        | within the TTL window are treated as duplicates.
        |
        | time_bucket options: 'second', 'minute' (default), 'hour', 'day'
        |
        */
        'fingerprint' => [
            'enabled' => env('ANALYTICS_FINGERPRINT_ENABLED', true),
            'cache_prefix' => env('ANALYTICS_FINGERPRINT_CACHE_PREFIX', 'zb_fp_'),
            'ttl' => (int) env('ANALYTICS_FINGERPRINT_TTL', 86400), // 24 hours
            'time_bucket' => env('ANALYTICS_FINGERPRINT_TIME_BUCKET', 'minute'),
            'exclude_timestamp' => env('ANALYTICS_FINGERPRINT_EXCLUDE_TIMESTAMP', false),
            'exclude_params' => env('ANALYTICS_FINGERPRINT_EXCLUDE_PARAMS', false),
        ],

        /*
        |--------------------------------------------------------------------------
        | Dashboard Widgets (v8.3.0)
        |--------------------------------------------------------------------------
        |
        | Pre-computed, cache-backed dashboard widget data for instant
        | SaaS analytics dashboard rendering. Widgets are lazily computed
        | on first access and cached until invalidated.
        |
        | Available widgets: overview, events_top, events_timeline,
        | revenue_summary, saas_funnel, engagement, providers, ecommerce
        |
        | Set 'widgets' to a list of widget names to enable only specific ones.
        | Leave empty or null to enable all widgets.
        |
        */
        'dashboard_widgets' => [
            'enabled' => env('ANALYTICS_DASHBOARD_WIDGETS_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_DASHBOARD_WIDGETS_CACHE_TTL', 300), // 5 minutes
            'max_top_events' => (int) env('ANALYTICS_DASHBOARD_WIDGETS_MAX_TOP', 20),
            'timeline_points' => (int) env('ANALYTICS_DASHBOARD_WIDGETS_TIMELINE_POINTS', 24),
            'widgets' => null, // null = all widgets, or list: ['overview', 'events_top', 'providers']
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
        | Event Validation Pipeline (v69.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Unified multi-stage validation pipeline that composes catalog membership,
        | schema validation, PII scanning, data quality, and GDPR compliance into
        | a single validation pass. Each stage produces structured diagnostics with
        | error codes, severity levels, and performance metrics.
        |
        | Configure individual stages or disable the entire pipeline.
        | Used by EventValidationPipeline, zb:analytics:pipeline:validate command,
        | and the validation API endpoints.
        |
        */
        'validation_pipeline' => [
            'enabled' => env('ANALYTICS_VALIDATION_PIPELINE_ENABLED', true),
            'fail_fast' => env('ANALYTICS_VALIDATION_PIPELINE_FAIL_FAST', false),
            'catalog_membership' => [
                'enforce_membership' => env('ANALYTICS_VP_CATALOG_MEMBERSHIP', true),
                'max_name_length' => (int) env('ANALYTICS_VP_MAX_NAME_LENGTH', 100),
                'enforce_snake_case' => env('ANALYTICS_VP_SNAKE_CASE', true),
            ],
            'schema_validation' => [
                'enabled' => env('ANALYTICS_VP_SCHEMA_ENABLED', false),
                'enforce_required' => env('ANALYTICS_VP_SCHEMA_REQUIRED', true),
                'strict_types' => env('ANALYTICS_VP_SCHEMA_STRICT_TYPES', false),
                'max_param_count' => (int) env('ANALYTICS_VP_MAX_PARAMS', 100),
                'max_key_length' => (int) env('ANALYTICS_VP_MAX_KEY_LENGTH', 100),
            ],
            'pii_scanning' => [
                'enabled' => env('ANALYTICS_VP_PII_ENABLED', true),
                'extra_disallowed_keys' => [],
                'skip_patterns' => [],
            ],
            'data_quality' => [
                'enabled' => env('ANALYTICS_VP_QUALITY_ENABLED', true),
                'min_completeness' => (float) env('ANALYTICS_VP_MIN_COMPLETENESS', 0.3),
                'max_empty_params' => (int) env('ANALYTICS_VP_MAX_EMPTY_PARAMS', 10),
            ],
            'compliance' => [
                'enabled' => env('ANALYTICS_VP_COMPLIANCE_ENABLED', true),
                'require_consent_for_pii' => env('ANALYTICS_VP_CONSENT_PII', true),
            ],
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
        | Event Stream Processing (v31.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Sequential event analysis engine that processes events into ordered
        | sequences, discovers frequent event patterns, auto-detects funnels,
        | and identifies stream-level anomalies (velocity spikes, unusual gaps).
        |
        | Inspired by Amplitude Pathfinder, Mixpanel Flow, and PostHog User Paths.
        |
        */
        'stream_processing' => [
            'enabled' => env('ANALYTICS_STREAM_PROCESSING_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_STREAM_PROCESSING_CACHE_TTL', 3600), // 1 hour
            'max_sequence_length' => (int) env('ANALYTICS_STREAM_PROCESSING_MAX_SEQ', 10),
            'max_patterns_per_client' => (int) env('ANALYTICS_STREAM_PROCESSING_MAX_PATTERNS', 50),
            'min_pattern_support' => (int) env('ANALYTICS_STREAM_PROCESSING_MIN_SUPPORT', 2),
            'anomaly_deviation' => (float) env('ANALYTICS_STREAM_PROCESSING_ANOMALY_DEV', 3.0), // standard deviations
            'anomaly_window' => (int) env('ANALYTICS_STREAM_PROCESSING_ANOMALY_WINDOW', 600), // 10 minutes
            'max_stream_events' => (int) env('ANALYTICS_STREAM_PROCESSING_MAX_EVENTS', 500), // per client
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

            /*
            | Performance Score Aggregation (v24.0.0)
            |
            | When enabled, the server-side PerformanceScoreService
            | aggregates collected Web Vitals and computes p75-based
            | overall performance scores per page or session.
            |
            */
            'cache_prefix' => env('ANALYTICS_PERFORMANCE_CACHE_PREFIX', 'zb_perf_'),
            'aggregation_window' => (int) env('ANALYTICS_PERFORMANCE_WINDOW', 900), // 15 minutes (seconds)
            'auto_score' => env('ANALYTICS_PERFORMANCE_AUTO_SCORE', true),
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
        | Event Enrichment (Server-Side Context Attachment)
        |--------------------------------------------------------------------------
        |
        | When enabled, all server-side API events are automatically enriched with
        | request context: IP (GDPR-anonymized), user-agent, locale, referrer,
        | session ID (hashed), and source type (api vs browser).
        |
        | Server context uses the `_server_` prefix and never overwrites
        | client-sent event parameters.
        |
        */
        'enrichment' => [
            'enabled' => env('ANALYTICS_ENRICHMENT_ENABLED', true),
        ],

        /*
        |--------------------------------------------------------------------------
        | Revenue Intelligence (Unified Revenue Dashboard)
        |--------------------------------------------------------------------------
        |
        | Combines revenue forecasting, churn prediction, health scoring,
        | and unit economics into a single API endpoint for SaaS dashboards.
        |
        */
        'revenue_intelligence' => [
            'enabled' => env('ANALYTICS_REVENUE_INTELLIGENCE_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_REVENUE_INTELLIGENCE_CACHE_TTL', 300), // 5 minutes
        ],

        /*
        |--------------------------------------------------------------------------
        | AARRR Framework (SaaS Growth Metrics)
        |--------------------------------------------------------------------------
        |
        | When enabled, AARRRFrameworkService provides a unified framework for
        | measuring the five key SaaS growth pillars: Acquisition, Activation,
        | Retention, Revenue, and Referral. Health scores are cached for
        | dashboard performance.
        |
        */
        'aarrr' => [
            'enabled' => env('ANALYTICS_AARRR_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_AARRR_CACHE_TTL', 300), // 5 minutes
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Tracing (End-to-End Correlation)
        |--------------------------------------------------------------------------
        |
        | When enabled, all API events receive a trace context (trace ID, span ID)
        | for end-to-end correlation. Batch events share the same trace ID with
        | unique span IDs. Trace metadata uses the `_trace_` prefix and is
        | automatically stripped before forwarding to external providers.
        |
        */
        'tracing' => [
            'enabled' => env('ANALYTICS_TRACING_ENABLED', true),
            'source' => env('ANALYTICS_TRACING_SOURCE', 'server'),
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
        | First-Touch UTM Attribution (v6.3.0)
        |-------------------------------------------------------------------------- 
        |
        | Persists UTM parameters from the user's first visit in a long-lived cookie.
        | Enables cross-session attribution by preserving the original acquisition
        | source even when subsequent visits have different or no UTM parameters.
        |
        | The FirstTouchUTMMiddleware reads/writes this cookie and stores the
        | resolved first-touch data on request attributes as `_zb_first_touch`.
        |
        */
        'first_touch' => [
            'enabled' => env('ANALYTICS_FIRST_TOUCH_ENABLED', true),
            'cookie_name' => env('ANALYTICS_FIRST_TOUCH_COOKIE', 'zb_first_touch'),
            'cookie_ttl' => (int) env('ANALYTICS_FIRST_TOUCH_COOKIE_TTL', 525600), // 365 days (minutes)
            'cookie_secure' => env('ANALYTICS_FIRST_TOUCH_COOKIE_SECURE', true),
            'cookie_domain' => env('ANALYTICS_FIRST_TOUCH_COOKIE_DOMAIN'), // null = current domain
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Dispatcher (v3.4.0)
        |-------------------------------------------------------------------------- 
        |
        | Unified event dispatch configuration for AnalyticsEventDispatcher.
        | Controls consent awareness, deduplication, sampling rate, and debug mode
        | at the dispatch layer. This is the recommended entry point for all
        | application code event dispatching.
        |
        */
        'dispatcher' => [
            'consent_aware' => env('ANALYTICS_DISPATCHER_CONSENT_AWARE', true),
            'dedup_enabled' => env('ANALYTICS_DISPATCHER_DEDUP_ENABLED', true),
            'sampling_rate' => (float) env('ANALYTICS_DISPATCHER_SAMPLING_RATE', 1.0),
            'debug' => env('ANALYTICS_DISPATCHER_DEBUG', false),
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Flushing Strategy (v17.0.0)
        |--------------------------------------------------------------------------
        |
        | Controls when analytics events are sent to provider endpoints.
        |
        | Strategies:
        | - 'immediate' (default) — Every event dispatched instantly
        | - 'buffered' — Events buffered until flush() or max_buffer_size reached
        | - 'periodic' — Flush at fixed batch_window intervals
        | - 'batch_window' — Collect for time window then flush as batch
        |
        | Use Analytics::getFlushingService() to access at runtime.
        |
        */
        'flushing' => [
            'strategy' => env('ANALYTICS_FLUSHING_STRATEGY', 'immediate'),
            'max_buffer_size' => (int) env('ANALYTICS_FLUSHING_MAX_BUFFER', 50),
            'batch_window' => (int) env('ANALYTICS_FLUSHING_BATCH_WINDOW', 5),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Tenant Context (v17.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Multi-tenant auto-enrichment for the AnalyticsContextBus.
        | When enabled, the context bus extracts tenant information from the
        | authenticated user model and attaches it to all events.
        |
        */
        'tenant_context' => [
            'enabled' => env('ANALYTICS_TENANT_CONTEXT_ENABLED', false),
            'tenant_id_field' => env('ANALYTICS_TENANT_ID_FIELD', 'tenant_id'),
            'tenant_name_field' => env('ANALYTICS_TENANT_NAME_FIELD', 'tenant_name'),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Feature Flags Context (v17.0.0)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, the AnalyticsContextBus attempts to resolve feature flags
        | from a configured resolver service and attaches them to all events.
        |
        | Set 'resolver' to a fully-qualified class name that implements a method
        | returning an array of flag_name => bool.
        |
        */
        'feature_flags' => [
            'enabled' => env('ANALYTICS_FEATURE_FLAGS_ENABLED', false),
            'resolver' => env('ANALYTICS_FEATURE_FLAGS_RESOLVER'),
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
        | Cross-Platform Attribution (v115.0.0)
        |--------------------------------------------------------------------------
        |
        | Unified attribution across GA4, Meta, Plausible, PostHog, and webhook
        | providers. Supports first-touch, last-touch, linear, time-decay, and
        | position-based (U-shaped) attribution models.
        |
        | Normalizes provider-specific attribution data into a common format
        | for cross-platform reporting and conversion deduplication.
        |
        */
        'cross_platform_attribution' => [
            'enabled' => env('ANALYTICS_CROSS_PLATFORM_ATTRIBUTION_ENABLED', true),
            'model' => env('ANALYTICS_CROSS_PLATFORM_ATTRIBUTION_MODEL', 'last_touch'),
            // Supported: first_touch, last_touch, linear, time_decay, position_based
            'lookback_window_days' => (int) env('ANALYTICS_CROSS_PLATFORM_ATTRIBUTION_LOOKBACK', 90),
            'cache_ttl' => (int) env('ANALYTICS_CROSS_PLATFORM_ATTRIBUTION_CACHE_TTL', 86400),
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
                'subscription.resumed' => true,
                'subscription.paused' => true,

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

                // ── GDPR Consent Lifecycle (v2.93.0) ─────────
                'consent.granted' => true,
                'consent.withdrawn' => true,
                'gdpr.data_subject_access_request' => true,
                'gdpr.data_erasure_completed' => true,

                // ── Plan Management (v2.93.0) ────────────────
                'plan.changed' => true,
                'billing.payment_method_updated' => false,

                // ── Subscription & Trial Expansion (v2.93.0) ─
                'subscription.created_new' => true,
                'subscription.cancelled_new' => true,
                'subscription.resumed' => true,
                'trial.expired' => true,

                // ── Conversion & Growth (v2.76) ─────────────
                'trial.converted' => true,
                'subscription.value_changed' => true,
                'usage.quota_reached' => true,
                'billing.retry' => true,
                'subscription.paused' => true,
                'workspace.created' => true,
                'milestone.reached' => true,
                'team.invite_accepted' => true,
                'subscription.trial_end_reminder' => false,

                // ── Security (v9.9.0) ──────────────────────
                'security.login_attempt' => true,
                'security.suspicious_activity' => true,
                'security.data_access_audit' => true,
                'security.rate_limit_exceeded' => false,
                'security.mfa_challenge' => true,

                // ── Uptime & Infrastructure (v9.9.0) ────────
                'uptime.service_up' => true,
                'uptime.service_down' => true,
                'uptime.api_latency' => true,
                'uptime.error_spike' => true,
                'uptime.deployment' => true,
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
        | Event Context Snapshot (v8.5.0)
        |-------------------------------------------------------------------------- 
        |
        | Captures point-in-time context snapshots for each dispatched event.
        | Snapshots include device fingerprint, session state, geographic hints,
        | behavioral velocity, and consent state. Cached for replay and audit.
        |
        | Used by EventContextSnapshotService for post-hoc event enrichment,
        | compliance reporting, and cross-reference in dashboards.
        |
        */
        'context_snapshot' => [
            'enabled' => env('ANALYTICS_CONTEXT_SNAPSHOT_ENABLED', true),
            'cache_prefix' => env('ANALYTICS_CONTEXT_SNAPSHOT_PREFIX', 'zb_ctx_snapshot_'),
            'snapshot_ttl' => (int) env('ANALYTICS_CONTEXT_SNAPSHOT_TTL', 86400), // 24 hours
            'max_snapshots_per_client' => (int) env('ANALYTICS_CONTEXT_SNAPSHOT_MAX_PER_CLIENT', 100),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | User Journey Reconstruction (v8.5.0)
        |-------------------------------------------------------------------------- 
        |
        | Reconstructs complete user journeys from event correlation data.
        | Provides funnel completion analysis, time-to-convert metrics,
        | drop-off detection, and journey comparison across segments.
        |
        | Journeys are cache-backed and support GDPR erasure.
        |
        */
        'journey_reconstruction' => [
            'enabled' => env('ANALYTICS_JOURNEY_RECONSTRUCTION_ENABLED', true),
            'cache_prefix' => env('ANALYTICS_JOURNEY_RECONSTRUCTION_PREFIX', 'zb_journey_'),
            'cache_ttl' => (int) env('ANALYTICS_JOURNEY_RECONSTRUCTION_TTL', 86400), // 24 hours
            'max_journeys_per_user' => (int) env('ANALYTICS_JOURNEY_RECONSTRUCTION_MAX_PER_USER', 20),
            'max_steps_per_journey' => (int) env('ANALYTICS_JOURNEY_RECONSTRUCTION_MAX_STEPS', 200),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Security Event Tracking (v9.9.0)
        |-------------------------------------------------------------------------- 
        |
        | Config-driven security event tracking for SaaS applications.
        | When enabled, security events (login attempts, suspicious activity,
        | data access audits, rate limit violations, MFA challenges) are
        | dispatched to all configured analytics providers.
        |
        | Toggle individual events on/off. Set 'enabled' to false to disable
        | all security event tracking.
        |
        */
        'security_events' => [
            'enabled' => env('ANALYTICS_SECURITY_EVENTS_ENABLED', true),
            'events' => [
                'login_attempt' => true,
                'suspicious_activity' => true,
                'data_access_audit' => true,
                'rate_limit_exceeded' => false,
                'mfa_challenge' => true,
            ],
            'log_sensitive' => env('ANALYTICS_SECURITY_LOG_SENSITIVE', false), // Log sensitive params to Laravel log
            'anonymize_ip' => env('ANALYTICS_SECURITY_ANONYMIZE_IP', true), // Always anonymize IPs in security events
            'max_events_per_minute' => (int) env('ANALYTICS_SECURITY_MAX_PER_MINUTE', 100), // Rate limit security event dispatch
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Uptime & Infrastructure Monitoring (v9.9.0)
        |-------------------------------------------------------------------------- 
        |
        | Tracks service health, API latency, error spikes, and deployments.
        | Enables correlating infrastructure events with user behavior changes,
        | conversion rate drops, and error rate increases.
        |
        | Used by the health monitoring services and dashboard widgets.
        |
        */
        'uptime' => [
            'enabled' => env('ANALYTICS_UPTIME_ENABLED', true),
            'events' => [
                'service_up' => true,
                'service_down' => true,
                'api_latency' => true,
                'error_spike' => true,
                'deployment' => true,
            ],
            'latency_threshold_ms' => (float) env('ANALYTICS_UPTIME_LATENCY_THRESHOLD', 1000.0),
            'error_spike_multiplier' => (float) env('ANALYTICS_UPTIME_ERROR_SPIKE_MULTIPLIER', 3.0),
            'tracked_services' => ['api', 'database', 'cache', 'queue', 'email', 'storage'],
            'cache_ttl' => (int) env('ANALYTICS_UPTIME_CACHE_TTL', 300), // 5 minutes
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Sessionizer (v8.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Session-aware event aggregation for real-time SaaS dashboards.
        | Groups events by client ID + session ID and computes per-session
        | metrics: event counts, unique events, session duration, engagement.
        |
        | Requires Redis cache driver for production-scale session tracking.
        | Sessions auto-expire based on session_ttl.
        |
        */
        'sessionizer' => [
            'enabled' => env('ANALYTICS_SESSIONIZER_ENABLED', true),
            'session_ttl' => (int) env('ANALYTICS_SESSIONIZER_SESSION_TTL', 1800), // 30 minutes
            'max_sessions_per_client' => (int) env('ANALYTICS_SESSIONIZER_MAX_SESSIONS', 50),
            'cache_prefix' => env('ANALYTICS_SESSIONIZER_CACHE_PREFIX', 'zb_session_'),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Funnel Definitions (v8.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Define custom conversion funnels for your application.
        | Each funnel has a name, ordered steps, and a conversion event.
        | Built-in funnels (signup, activation, purchase, subscription, expansion)
        | are always available. Add custom funnels here.
        |
        | Example:
        |   'onboarding' => [
        |       'steps' => ['sign_up', 'start_trial', 'feature_used', 'subscribe'],
        |       'conversion_event' => 'subscribe',
        |       'time_window' => 604800, // 7 days
        |   ],
        |
        */
        'funnel_definitions' => [],

        /*
        |-------------------------------------------------------------------------- 
        | Event Classification Enrichment (v8.0.0)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, the EventClassificationEnricher automatically attaches
        | catalog metadata to every event: category, provider mappings, priority,
        | and event class name. Uses the `_zb_` prefix to avoid parameter conflicts.
        |
        */
        'classification' => [
            'enabled' => env('ANALYTICS_CLASSIFICATION_ENABLED', true),
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
        | Event Budget & Throttling (v4.3.0)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, enforces per-client and per-user event budgets to prevent
        | abuse and control costs. Supports configurable limits, sliding windows,
        | and overflow policies (reject, sample, throttle).
        |
        | Budgets are tracked in-memory with optional cache persistence.
        |
        */
        'budget' => [
            'enabled' => env('ANALYTICS_BUDGET_ENABLED', false),
            'client_limit' => (int) env('ANALYTICS_BUDGET_CLIENT_LIMIT', 1000),   // per hour
            'user_limit' => (int) env('ANALYTICS_BUDGET_USER_LIMIT', 500),       // per hour
            'global_limit' => (int) env('ANALYTICS_BUDGET_GLOBAL_LIMIT', 100000), // per hour
            'window_seconds' => (int) env('ANALYTICS_BUDGET_WINDOW', 3600),     // 1 hour
            'overflow_policy' => env('ANALYTICS_BUDGET_OVERFLOW', 'reject'),    // reject, sample
            'sample_rate' => (float) env('ANALYTICS_BUDGET_SAMPLE_RATE', 0.1), // 10% when sampling
            'cache_enabled' => env('ANALYTICS_BUDGET_CACHE_ENABLED', true),
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
        | Replay Audit Trail (v39.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Records every event replay operation with full context: who triggered it,
        | which events were replayed, provider-level success/failure, timestamps,
        | and execution duration. Provides search, filtering, and statistics
        | for compliance, debugging, and operational dashboards.
        |
        */
        'replay_audit' => [
            'enabled' => env('ANALYTICS_REPLAY_AUDIT_ENABLED', true),
            'cache_prefix' => env('ANALYTICS_REPLAY_AUDIT_CACHE_PREFIX', 'zb_replay_audit_'),
            'retention_ttl' => (int) env('ANALYTICS_REPLAY_AUDIT_TTL', 2592000), // 30 days
            'max_entries' => (int) env('ANALYTICS_REPLAY_AUDIT_MAX_ENTRIES', 5000),
            'auto_record' => env('ANALYTICS_REPLAY_AUDIT_AUTO_RECORD', true),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Data Retention (v39.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Configurable per-category retention policies for archived analytics events.
        | Automatically purges expired events based on event timestamp and category.
        | Includes GDPR-compliant right-to-erasure for client_id and user_id purges.
        |
        | Retention periods are specified in days. Events are checked against the
        | category-specific retention, falling back to default_days.
        |
        */
        'data_retention' => [
            'enabled' => env('ANALYTICS_DATA_RETENTION_ENABLED', true),
            'default_days' => (int) env('ANALYTICS_DATA_RETENTION_DEFAULT_DAYS', 90),
            'categories' => [
                'ecommerce' => (int) env('ANALYTICS_RETENTION_ECOMMERCE_DAYS', 90),
                'saas' => (int) env('ANALYTICS_RETENTION_SAAS_DAYS', 180),
                'engagement' => (int) env('ANALYTICS_RETENTION_ENGAGEMENT_DAYS', 30),
                'security' => (int) env('ANALYTICS_RETENTION_SECURITY_DAYS', 365),
                'uptime' => (int) env('ANALYTICS_RETENTION_UPTIME_DAYS', 30),
            ],
            'cache_prefix' => env('ANALYTICS_DATA_RETENTION_CACHE_PREFIX', 'zb_retention_'),
            'cache_ttl' => (int) env('ANALYTICS_DATA_RETENTION_CACHE_TTL', 3600),
            'gdpr_erase_enabled' => env('ANALYTICS_DATA_RETENTION_GDPR_ERASE', true),
            'purge_batch_size' => (int) env('ANALYTICS_DATA_RETENTION_PURGE_BATCH', 500),
            'log_purge' => env('ANALYTICS_DATA_RETENTION_LOG_PURGE', true),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Dependency Graph (v40.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Models causal dependencies between analytics events — e.g., sign_up
        | must precede start_trial, add_to_cart must precede purchase. Validates
        | real-time event sequences, detects impossible workflows, and provides
        | funnel guard logic for SaaS products.
        |
        | Built-in graph covers SaaS lifecycle (sign_up → login → start_trial →
        | subscribe → plan_upgrade/cancellation) and e-commerce (view_item →
        | add_to_cart → begin_checkout → purchase → refund).
        |
        | Disable in development to skip dependency validation.
        |
        */
        'dependency_graph' => [
            'enabled' => env('ANALYTICS_DEPENDENCY_GRAPH_ENABLED', true),
            'cache_prefix' => env('ANALYTICS_DEPENDENCY_GRAPH_CACHE_PREFIX', 'zb_edg_'),
            'cache_ttl' => (int) env('ANALYTICS_DEPENDENCY_GRAPH_CACHE_TTL', 86400), // 24 hours
            'violation_ttl' => (int) env('ANALYTICS_DEPENDENCY_GRAPH_VIOLATION_TTL', 3600), // 1 hour
            'max_violations' => (int) env('ANALYTICS_DEPENDENCY_GRAPH_MAX_VIOLATIONS', 100),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Multi-Currency Revenue Normalization (v40.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Converts revenue event values from any currency to a configured base
        | currency using exchange rates. Enables unified revenue analytics across
        | markets and currencies for consistent MRR, ARR, and LTV calculations.
        |
        | Exchange rates default to common pairs (USD base). Override via config
        | or set dynamically using the MultiCurrencyRevenueNormalizer API.
        |
        | When enabled, normalized params are injected with _normalized_* prefix
        | without overwriting original event params.
        |
        */
        'multi_currency' => [
            'enabled' => env('ANALYTICS_MULTI_CURRENCY_ENABLED', false),
            'base_currency' => env('ANALYTICS_MULTI_CURRENCY_BASE', 'USD'),
            'cache_prefix' => env('ANALYTICS_MULTI_CURRENCY_CACHE_PREFIX', 'zb_fx_'),
            'rate_ttl' => (int) env('ANALYTICS_MULTI_CURRENCY_RATE_TTL', 86400), // 24 hours
            'rounding' => env('ANALYTICS_MULTI_CURRENCY_ROUNDING', 'currency'), // currency, none
            'stale_threshold' => (float) env('ANALYTICS_MULTI_CURRENCY_STALE_THRESHOLD', 0.1), // 10% deviation
            'rates' => [], // Override defaults: ['EUR' => 0.92, 'GBP' => 0.79, ...]
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
            // Common event name aliases and abbreviations.
            // The EventAliasResolver uses these plus built-in defaults.
            // ── Authentication Aliases ──
            // 'signup' => 'sign_up',        // Built-in default
            // 'signin' => 'login',         // Built-in default
            // 'signout' => 'logout',       // Built-in default
            // ── SaaS Lifecycle Aliases ──
            // 'sub_created' => 'subscribe', // Built-in default
            // 'plan_change' => 'plan_upgrade',
            // 'trial_start' => 'start_trial',
            // ── E-commerce Aliases ──
            // 'view_product' => 'view_item',
            // 'add_cart' => 'add_to_cart',
            // 'checkout' => 'begin_checkout',
            // ── Engagement Aliases ──
            // 'pv' => 'page_view',
            // 'click_outbound' => 'outbound_click',
            // ── Custom Application Aliases ──
            // 'app:install' => 'feature_used',
            // 'my_custom_event' => 'sign_up',
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
            'catalog_version' => '3.0.0',
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
        | Conversion Path Discovery
        |--------------------------------------------------------------------------
        |
        | Analyzes event sequences to discover the most common multi-step
        | conversion paths. Identifies high-converting patterns, common
        | drop-off points, and optimal journey sequences.
        |
        */
        'conversion_paths' => [
            'enabled' => env('ANALYTICS_CONVERSION_PATHS_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_CONVERSION_PATHS_CACHE_TTL', 86400),
            'max_depth' => (int) env('ANALYTICS_CONVERSION_PATHS_MAX_DEPTH', 10),
            'min_samples' => (int) env('ANALYTICS_CONVERSION_PATHS_MIN_SAMPLES', 3),
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

        /*
        |-------------------------------------------------------------------------- 
        | Session Replay (User Journey Reconstruction)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, records recent analytics events per session in cache
        | for support debugging and user journey reconstruction. Events are
        | stored in a ring buffer with configurable TTL and max events.
        |
        */
        'session_replay' => [
            'enabled' => env('ANALYTICS_SESSION_REPLAY_ENABLED', false),
            'max_events' => (int) env('ANALYTICS_SESSION_REPLAY_MAX_EVENTS', 200),
            'ttl' => (int) env('ANALYTICS_SESSION_REPLAY_TTL', 3600), // 1 hour
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Advanced PII Detection
        |-------------------------------------------------------------------------- 
        |
        | Regex-based detection of personally identifiable information in event
        | parameters. Used by the anonymization service to identify and redact
        | PII fields before dispatch. Supports email, phone, credit card, SSN,
        | IP address, JWT tokens, and custom patterns.
        |
        */
        'pii_detection' => [
            'enabled' => env('ANALYTICS_PII_DETECTION_ENABLED', false),
            'confidence_threshold' => (float) env('ANALYTICS_PII_DETECTION_THRESHOLD', 0.5),
            'custom_patterns' => [],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Orchestration (Multi-Step Lifecycle Pipelines)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, provides multi-step event orchestration for SaaS lifecycle
        | pipelines. Tracks sequential event progress across user journeys with
        | timeout detection, completion events, and rollback support.
        |
        | Built-in pipelines: user_acquisition, trial_conversion, ecommerce_checkout,
        | activation, retention. Custom pipelines can be defined here.
        |
        | Example custom pipeline:
        |   'pipelines' => [
        |       'onboarding' => [
        |           'name' => 'onboarding',
        |           'steps' => [
        |               ['name' => 'welcome', 'event' => 'page_view', 'required' => true, 'timeout_seconds' => 86400],
        |               ['name' => 'profile', 'event' => 'profile_updated', 'required' => true, 'timeout_seconds' => 604800],
        |               ['name' => 'first_action', 'event' => 'feature_used', 'required' => true, 'timeout_seconds' => 604800],
        |           ],
        |           'on_complete_event' => 'onboarding_completed',
        |           'on_timeout_event' => 'onboarding_timeout',
        |           'on_failure_event' => null,
        |       ],
        |   ],
        |
        */
        'orchestration' => [
            'enabled' => env('ANALYTICS_ORCHESTRATION_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_ORCHESTRATION_CACHE_TTL', 86400), // 24 hours
            'scan_limit' => (int) env('ANALYTICS_ORCHESTRATION_SCAN_LIMIT', 100),
            'pipelines' => [],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Rules Engine (Behavioral Automation)
        |-------------------------------------------------------------------------- 
        |
        | Config-driven behavioral rules that trigger automated analytics events.
        | Three rule types:
        |
        |   event_trigger:    When event X fires, also fire event Y
        |   absence_trigger:  When event X has NOT fired for user in N seconds, fire Y
        |   property_trigger: When a user property reaches threshold, fire Y
        |
        | Example rules:
        |   'rules' => [
        |       'trial_conversion_reminder' => [
        |           'type' => 'absence_trigger',
        |           'event' => 'subscribe',
        |           'absent_for' => 604800, // 7 days
        |           'trigger' => 'trial_conversion_reminder',
        |           'params' => ['reminder_type' => 'trial_expiring'],
        |       ],
        |       'auto_enrich_signup' => [
        |           'type' => 'event_trigger',
        |           'on' => 'sign_up',
        |           'then' => 'signup_enriched',
        |           'enrich' => ['signup_method' => 'method'],
        |       ],
        |       'high_value_alert' => [
        |           'type' => 'property_trigger',
        |           'property' => 'total_revenue',
        |           'operator' => 'gte',
        |           'value' => 1000,
        |           'trigger' => 'high_value_customer',
        |       ],
        |   ],
        |
        */
        'rules' => [
            'enabled' => env('ANALYTICS_RULES_ENABLED', false),
            'debug' => env('ANALYTICS_RULES_DEBUG', false),
            'rules' => [],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | User Properties Store
        |-------------------------------------------------------------------------- 
        |
        | Persists and aggregates user traits across events. Used for identity
        | enrichment, cohort building, and property-triggered rules.
        |
        | Schema defines property types and aggregation strategies:
        |   type: string, int, float, bool, array
        |   aggregation: sum, min, max, last, set (unique list), count
        |
        */
        'user_properties' => [
            'enabled' => env('ANALYTICS_USER_PROPS_ENABLED', true),
            'debug' => env('ANALYTICS_USER_PROPS_DEBUG', false),
            'ttl' => (int) env('ANALYTICS_USER_PROPS_TTL', 2592000), // 30 days
            'schema' => [
                'total_revenue' => ['type' => 'float', 'default' => 0.0, 'aggregation' => 'sum'],
                'session_count' => ['type' => 'int', 'default' => 0, 'aggregation' => 'sum'],
                'events_fired' => ['type' => 'int', 'default' => 0, 'aggregation' => 'count'],
                'plan' => ['type' => 'string', 'default' => '', 'aggregation' => 'last'],
                'signup_method' => ['type' => 'string', 'default' => '', 'aggregation' => 'last'],
                'features_used' => ['type' => 'array', 'default' => [], 'aggregation' => 'set'],
                'last_active_at' => ['type' => 'string', 'default' => '', 'aggregation' => 'last'],
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | N-Day Retention & Stickiness Calculator
        |-------------------------------------------------------------------------- 
        |
        | Computes industry-standard retention metrics:
        | - N-Day Retention (D1, D3, D7, D14, D30)
        | - Rolling Retention (cumulative)
        | - Stickiness (DAU/MAU ratio)
        | - Retention Curve (day-by-day for charts)
        |
        | Uses cache-backed event tracking. No database required.
        |
        */
        'retention_analytics' => [
            'enabled' => env('ANALYTICS_RETENTION_CALC_ENABLED', true),
            'debug' => env('ANALYTICS_RETENTION_CALC_DEBUG', false),
            'ttl' => (int) env('ANALYTICS_RETENTION_CALC_TTL', 7776000), // 90 days
            'retention_days' => [1, 3, 7, 14, 30],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Behavioral Cohort Builder
        |-------------------------------------------------------------------------- 
        |
        | Groups users into behavioral segments based on activity patterns.
        | Built-in segments: Power, Regular, Casual, At-Risk, Dormant, New, Resurrected.
        | Supports custom cohort definitions via config.
        |
        | Custom cohort example:
        |   'custom_cohorts' => [
        |       'high_value' => [
        |           'label' => 'High Value',
        |           'rules' => [
        |               ['property' => 'total_revenue', 'operator' => 'gte', 'value' => 100],
        |           ],
        |       ],
        |   ],
        |
        */
        'cohorts' => [
            'enabled' => env('ANALYTICS_COHORTS_ENABLED', true),
            'debug' => env('ANALYTICS_COHORTS_DEBUG', false),
            'result_ttl' => (int) env('ANALYTICS_COHORTS_RESULT_TTL', 3600), // 1 hour
            'custom_cohorts' => [],
        ],

        // Event Debounce (v3.2.0)
        // Prevents duplicate event dispatches within a time window.
        // Useful for scroll depth, input tracking, mouse move events.
        'debounce' => [
            'enabled' => env('ANALYTICS_DEBOUNCE_ENABLED', true),
            'default_ttl' => (int) env('ANALYTICS_DEBOUNCE_DEFAULT_TTL', 5000), // 5 seconds (ms)
            'cache_prefix' => env('ANALYTICS_DEBOUNCE_CACHE_PREFIX', 'zb_debounce_'),

            // Per-event debounce rules (TTL in milliseconds)
            // Override the default TTL for specific events
            'rules' => [
                'scroll_depth' => (int) env('ANALYTICS_DEBOUNCE_SCROLL_DEPTH', 10000),
                'page_view' => (int) env('ANALYTICS_DEBOUNCE_PAGE_VIEW', 5000),
                'input_focus' => (int) env('ANALYTICS_DEBOUNCE_INPUT_FOCUS', 2000),
                'search_query' => (int) env('ANALYTICS_DEBOUNCE_SEARCH_QUERY', 3000),
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Archetypes (SaaS Funnel Blueprints)
        |-------------------------------------------------------------------------- 
        |
        | Pre-defined SaaS funnel blueprints for instrumentation guidance,
        | gap detection, and completion scoring. Built-in archetypes cover:
        | signup_funnel, activation, trial_conversion, ecommerce_checkout,
        | expansion, and retention_loop.
        |
        | Add custom archetypes to match your product's unique funnels.
        | Each archetype defines steps with event names, weights, and
        | expected time windows.
        |
        */
        'archetypes' => [
            'enabled' => env('ANALYTICS_ARCHETYPES_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_ARCHETYPES_CACHE_TTL', 3600),
            'custom' => [
                // 'my_funnel' => [
                //     'name' => 'My Custom Funnel',
                //     'description' => 'Custom conversion funnel',
                //     'category' => 'custom',
                //     'completion_event' => 'my_funnel_completed',
                //     'steps' => [
                //         ['name' => 'step_1', 'event' => 'page_view', 'required' => true, 'weight' => 0.3, 'expected_window_seconds' => 3600],
                //         ['name' => 'step_2', 'event' => 'sign_up', 'required' => true, 'weight' => 0.7, 'expected_window_seconds' => 86400],
                //     ],
                // ],
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Config Drift Detection (Deployment Monitoring)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, captures config baselines and detects drift between
        | deployments. Useful for CI/CD validation gates and ops monitoring.
        |
        | Capture a baseline after each verified deployment:
        |   php artisan zb:analytics:config-baseline
        |
        | Detect drift:
        |   php artisan zb:analytics:config-drift
        |
        */
        'config_drift' => [
            'enabled' => env('ANALYTICS_CONFIG_DRIFT_ENABLED', false),
            'cache_ttl' => (int) env('ANALYTICS_CONFIG_DRIFT_CACHE_TTL', 2592000), // 30 days
            'exclude_keys' => [
                // Keys to exclude from drift comparison (e.g., secrets, tokens)
                // 'ga4.api_secret',
                // 'meta_pixel.access_token',
            ],
            'monitored_sections' => [
                // Empty = monitor all sections. Specify to narrow scope:
                // 'ga4', 'gtm', 'meta_pixel', 'consent', 'queue',
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Anonymized Event Aggregation (k-Anonymity Privacy)
        |-------------------------------------------------------------------------- 
        |
        | Provides k-anonymity-safe event aggregation for GDPR-compliant
        | dashboards and public analytics. Groups with fewer than k events
        | are suppressed to prevent individual user identification.
        |
        | Optional Laplace noise injection provides mathematical differential
        | privacy guarantees for shared/aggregated data.
        |
        */
        'anonymized_aggregation' => [
            'enabled' => env('ANALYTICS_ANON_AGG_ENABLED', true),
            'k_threshold' => (int) env('ANALYTICS_ANON_AGG_K', 5), // minimum 5 events per group
            'cache_ttl' => (int) env('ANALYTICS_ANON_AGG_CACHE_TTL', 3600), // 1 hour
            'laplace_noise' => env('ANALYTICS_ANON_AGG_LAPLACE', false), // differential privacy
            'noise_scale' => (float) env('ANALYTICS_ANON_AGG_NOISE_SCALE', 1.0),
            'time_granularity' => env('ANALYTICS_ANON_AGG_TIME_GRANULARITY', 'hour'), // hour, day
            'max_event_age' => (int) env('ANALYTICS_ANON_AGG_MAX_AGE', 86400), // 24 hours
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Archive (Persistent Dispatch History)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, all dispatched analytics events are stored in a cache-backed
        | archive with search, filter, and pagination support. Enables admin dashboards
        | to inspect recent event activity, debug dispatch issues, and replay events
        | for reprocessing.
        |
        | Archive is accessible via:
        | - API endpoints: GET /api/analytics/archive, GET /api/analytics/archive/{id}
        | - Artisan command: php artisan zb:analytics:replay list|search|show|replay|stats|clear
        |
        | Storage uses the configured cache driver (file, redis, database).
        | Events are evicted (FIFO) when max_events is reached.
        |
        */
        'archive' => [
            'enabled' => env('ANALYTICS_ARCHIVE_ENABLED', false),
            'cache_prefix' => env('ANALYTICS_ARCHIVE_CACHE_PREFIX', 'zb_archive_'),
            'retention_ttl' => (int) env('ANALYTICS_ARCHIVE_RETENTION_TTL', 86400), // 24 hours
            'max_events' => (int) env('ANALYTICS_ARCHIVE_MAX_EVENTS', 10000),
            'always_archive' => [], // Event names to always archive (empty = all)
            'never_archive' => [], // Event names to never archive
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Governance & Data Quality (v4.1.0)
        |-------------------------------------------------------------------------- 
        |
        | Enforces event naming conventions, lifecycle governance (register, activate,
        | deprecate, retire), and data quality scoring across four dimensions:
        | completeness, consistency, timeliness, and validity.
        |
        | Inspired by Segment's Tracking Plan and Amplitude's Event Taxonomy.
        |
        */
        'governance' => [
            'enabled' => env('ANALYTICS_GOVERNANCE_ENABLED', false),
            'enforce_on_dispatch' => env('ANALYTICS_GOVERNANCE_ENFORCE', false), // block invalid events
            'cache_ttl' => (int) env('ANALYTICS_GOVERNANCE_CACHE_TTL', 3600), // 1 hour
            'reserved_prefixes' => ['$', 'zb_', 'amp_', 'firebase_', 'ga_'],

            /*
            | Naming Conventions
            |
            | Configure the expected format for event names.
            | Options: 'snake_case', 'camelCase', or set custom_pattern for regex.
            |
            */
            'naming' => [
                'format' => env('ANALYTICS_GOVERNANCE_NAMING_FORMAT', 'snake_case'),
                'max_length' => (int) env('ANALYTICS_GOVERNANCE_NAMING_MAX', 100),
                'min_length' => (int) env('ANALYTICS_GOVERNANCE_NAMING_MIN', 2),
                'custom_prefixes' => [], // Required prefixes for custom (non-catalog) events
                'reserved_prefixes' => ['$', 'zb_', 'amp_', 'firebase_', 'ga_'],
                'disallowed_patterns' => [
                    // '/__/',
                ],
                'custom_pattern' => null, // Regex override for custom naming
            ],

            /*
            | Deprecation Settings
            |
            | Default sunset period for deprecated events (in days).
            | After the sunset period, the event should be retired.
            |
            */
            'deprecation' => [
                'default_sunset_days' => (int) env('ANALYTICS_GOVERNANCE_SUNSET_DAYS', 30),
            ],

            /*
            | Data Quality Scoring
            |
            | Configure dimension weights for the composite quality score.
            | Weights must sum to 1.0.
            |
            */
            'quality' => [
                'cache_ttl' => (int) env('ANALYTICS_GOVERNANCE_QUALITY_TTL', 3600),
                'min_sample_size' => (int) env('ANALYTICS_GOVERNANCE_QUALITY_MIN', 10),
                'weights' => [
                    'completeness' => 0.35,
                    'consistency' => 0.30,
                    'timeliness' => 0.15,
                    'validity' => 0.20,
                ],
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Cost Tracking (v4.4.0)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, estimates per-provider analytics costs based on event volume
        | and configured unit pricing. Supports free tiers, per-event pricing, and
        | tiered models. Provides projected monthly cost estimates and budget alerts.
        |
        | Provider pricing defaults:
        | - GA4: Free (unlimited)
        | - GTM: Free (client-side only)
        | - Meta Pixel: Free (CAPI is free)
        | - Plausible: $9 per 1M events (tiered)
        | - PostHog: ~$225 per 1M events (1M free on free tier)
        | - Webhook: Free (internal cost only)
        |
        | Override pricing per provider with the 'providers' key.
        |
        */
        'cost_tracking' => [
            'enabled' => env('ANALYTICS_COST_TRACKING_ENABLED', false),
            'currency' => env('ANALYTICS_COST_TRACKING_CURRENCY', 'USD'),
            'providers' => [
                // Override default pricing per provider:
                // 'posthog' => ['unit_cost' => 0.0003, 'free_tier' => 1000000],
                // 'plausible' => ['unit_cost' => 0.01],
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Notification Webhooks (v4.4.0)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, sends analytics alert notifications to external webhook
        | endpoints (Slack, Discord, Microsoft Teams, PagerDuty, or generic HTTP).
        |
        | Each webhook has:
        | - url: Webhook URL (required)
        | - channel: Format type (slack, discord, teams, pagerduty, generic)
        | - enabled: Enable/disable toggle
        | - secret: Bearer token for authenticated webhooks
        | - min_severity: Minimum alert severity to send (debug, info, warning, elevated, critical)
        | - events: Event name filter (empty = all events, supports wildcards)
        |
        | Rate limiting prevents alert fatigue — min seconds between sends per webhook.
        |
        | Example:
        |   'webhooks' => [
        |       'slack_alerts' => [
        |           'enabled' => true,
        |           'url' => env('ANALYTICS_SLACK_WEBHOOK_URL', ''),
        |           'channel' => 'slack',
        |           'min_severity' => 'warning',
        |       ],
        |       'discord_critical' => [
        |           'enabled' => true,
        |           'url' => env('ANALYTICS_DISCORD_WEBHOOK_URL', ''),
        |           'channel' => 'discord',
        |           'min_severity' => 'elevated',
        |       ],
        |   ],
        |
        */
        'notification_webhooks' => [
            'enabled' => env('ANALYTICS_NOTIFICATION_WEBHOOKS_ENABLED', false),
            'rate_limit_seconds' => (int) env('ANALYTICS_NOTIFICATION_RATE_LIMIT', 60),
            'max_delivery_history' => (int) env('ANALYTICS_NOTIFICATION_MAX_HISTORY', 1000),
            'webhooks' => [
                // 'slack_alerts' => [
                //     'enabled' => true,
                //     'url' => env('ANALYTICS_SLACK_WEBHOOK_URL', ''),
                //     'channel' => 'slack',
                //     'secret' => '',
                //     'timeout' => 10,
                //     'retries' => 2,
                //     'min_severity' => 'warning',
                //     'events' => ['purchase', 'subscription', 'payment_failed'],
                // ],
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | AI-Powered Analytics Intelligence (v4.6.0)
        |-------------------------------------------------------------------------- 
        |
        | When enabled, provides intelligent anomaly detection, smart event
        | suggestions, trend analysis, and automated insights. Uses statistical
        | methods (z-score, linear regression) — no external AI API required.
        |
        */
        'ai' => [
            'enabled' => env('ANALYTICS_AI_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_AI_CACHE_TTL', 300), // 5 minutes
            'anomaly_threshold' => (float) env('ANALYTICS_AI_ANOMALY_THRESHOLD', 2.0), // z-score threshold
            'anomaly_window' => (int) env('ANALYTICS_AI_ANOMALY_WINDOW', 30), // data points
            'rolling_window' => (int) env('ANALYTICS_AI_ROLLING_WINDOW', 60), // max buffer size
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Experiment Tracking (A/B Tests) (v4.6.0)
        |-------------------------------------------------------------------------- 
        |
        | Track A/B test experiments with statistical significance calculation.
        | Uses two-proportion z-test for conversion rate comparison.
        | All experiment data is cache-backed — no database required.
        |
        */
        'experiment' => [
            'enabled' => env('ANALYTICS_EXPERIMENT_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_EXPERIMENT_CACHE_TTL', 86400), // 24 hours
            'significance_threshold' => (float) env('ANALYTICS_EXPERIMENT_SIGNIFICANCE', 0.95), // 95% confidence
            'min_sample_size' => (int) env('ANALYTICS_EXPERIMENT_MIN_SAMPLE', 100), // per variant
        ],

        /*
        |--------------------------------------------------------------------------
        | Analytics Data Service (v5.0.0)
        |--------------------------------------------------------------------------
        |
        | Cache-backed time-series analytics data for dashboard queries.
        | Provides DAU/MAU, revenue trends, event counters, provider stats,
        | and conversion funnels without requiring a database.
        |
        */
        'data_service' => [
            'enabled' => env('ANALYTICS_DATA_SERVICE_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_DATA_SERVICE_TTL', 3600), // 1 hour
            'daily_ttl' => (int) env('ANALYTICS_DATA_SERVICE_DAILY_TTL', 86400), // 24 hours
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Query Engine (v5.9.0)
        |--------------------------------------------------------------------------
        |
        | Cache-backed structured query engine for dashboard analytics.
        | Provides time-series, funnel, cohort, and conversion queries
        | without requiring a database. Used by AnalyticsQueryBuilder DSL.
        |
        */
        'query_engine' => [
            'enabled' => env('ANALYTICS_QUERY_ENGINE_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_QUERY_ENGINE_TTL', 300), // 5 minutes
            'default_period_days' => (int) env('ANALYTICS_QUERY_ENGINE_PERIOD', 7),
            'max_period_days' => (int) env('ANALYTICS_QUERY_ENGINE_MAX_PERIOD', 90),
            'max_results' => (int) env('ANALYTICS_QUERY_ENGINE_MAX_RESULTS', 50),
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Taxonomy (v5.0.0)
        |--------------------------------------------------------------------------
        |
        | Tag-based event classification beyond categories. Events can be tagged
        | by business unit, feature area, product line, or custom tags.
        | Used by dashboards for filtered views and reporting.
        |
        */
        'taxonomy' => [
            'enabled' => env('ANALYTICS_TAXONOMY_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_TAXONOMY_CACHE_TTL', 3600), // 1 hour
            'auto_classify' => env('ANALYTICS_TAXONOMY_AUTO_CLASSIFY', true),
            'tags' => [
                // Config-driven event → tags mapping:
                // 'purchase' => ['revenue', 'conversion', 'billing'],
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Multi-Tenant Analytics (v5.0.0)
        |--------------------------------------------------------------------------
        |
        | When enabled, analytics events are automatically tagged with the
        | current tenant context for workspace-aware isolation and reporting.
        | Supports subdomain-based, header-based, or callback-based tenant resolution.
        |
        */
        'tenant' => [
            'enabled' => env('ANALYTICS_TENANT_ENABLED', false),
            'resolver' => env('ANALYTICS_TENANT_RESOLVER', 'manual'), // manual, subdomain, header, callback
            'header' => env('ANALYTICS_TENANT_HEADER', 'X-Tenant-ID'),
            'subdomain_prefix' => env('ANALYTICS_TENANT_SUBDOMAIN', 'app'), // strip this prefix from subdomain
            'cache_ttl' => (int) env('ANALYTICS_TENANT_CACHE_TTL', 3600), // 1 hour
            'auto_tag_events' => env('ANALYTICS_TENANT_AUTO_TAG', true), // auto-tag events with tenant_id
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Cohort × Funnel Matrix Engine (v56.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Cross-dimensional cohort and funnel matrix analytics. Intersects user
        | cohorts with conversion funnels to produce heatmap-ready data, step
        | performance analysis, drop-off rankings, and velocity indices.
        |
        | Inspired by Amplitude Pathfinder × Cohort and Mixpanel Cohort Funnels.
        |
        */
        'cohort_funnel_matrix' => [
            'enabled' => env('ANALYTICS_COHORT_FUNNEL_MATRIX_ENABLED', false),
            'cache_ttl' => (int) env('ANALYTICS_COHORT_FUNNEL_MATRIX_CACHE_TTL', 600), // 10 minutes
            'max_cohorts' => (int) env('ANALYTICS_COHORT_FUNNEL_MATRIX_MAX_COHORTS', 24),
            'max_steps' => (int) env('ANALYTICS_COHORT_FUNNEL_MATRIX_MAX_STEPS', 20),
            'cohort_dimensions' => ['period', 'source', 'plan', 'tier', 'device'],
            'custom_funnels' => [
                // 'custom_checkout' => ['landing', 'signup', 'payment', 'done'],
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Broadcasting (v5.0.0)
        |--------------------------------------------------------------------------
        |
        | When enabled, dispatches a Laravel event (AnalyticsEventOccurred) after
        | every analytics event is tracked. Enables other packages and application
        | code to react to analytics events without modifying core logic.
        |
        */
        'broadcast' => [
            'enabled' => env('ANALYTICS_BROADCAST_ENABLED', false),
            'exclude_events' => [], // Event names to exclude from broadcasting
        ],

        /*
        |--------------------------------------------------------------------------
        | Regional Consent Detection (v5.9.0)
        |--------------------------------------------------------------------------
        |
        | When enabled, automatically applies GDPR-compliant consent defaults
        | based on the user's geographic region. Users in EU/UK/Brazil/CA/etc
        | default to consent='denied' (opt-in). Other regions use the
        | application's configured default.
        |
        | Country code is detected from request headers (CF-IPCountry,
        | X-GeoIP-Country, etc.) or passed explicitly by the application.
        |
        */
        'regional_consent' => [
            'enabled' => env('ANALYTICS_REGIONAL_CONSENT_ENABLED', false),
            'default_consent' => env('ANALYTICS_REGIONAL_CONSENT_DEFAULT', 'granted'),
            'gdpr_default' => env('ANALYTICS_REGIONAL_CONSENT_GDPR_DEFAULT', 'denied'),
            'gdpr_region_default' => env('ANALYTICS_REGIONAL_CONSENT_GDPR_REGION_DEFAULT', 'denied'),
            'additional_regions' => [
                // Additional country codes to treat as GDPR-applicable:
                // 'AU', // Australia
            ],
            'excluded_regions' => [
                // Country codes to exclude from GDPR rules:
                // 'CH', // Exclude Switzerland if not applicable
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Routing (v5.9.0)
        |--------------------------------------------------------------------------
        |
        | When enabled, routes specific events to designated providers only.
        | Supports exact match, wildcard prefix (add_to_*), and suffix (*_click).
        | Events with no matching rules fall through to all enabled providers.
        |
        | Example:
        |   'routing' => [
        |       'enabled' => true,
        |       'rules' => [
        |           'purchase' => ['ga4', 'meta'],
        |           'add_to_*' => ['ga4', 'meta', 'posthog'],
        |           'page_view' => ['ga4', 'plausible'],
        |       ],
        |   ],
        |
        */
        'routing' => [
            'enabled' => env('ANALYTICS_ROUTING_ENABLED', false),
            'rules' => [
                // Example rules (uncomment to enable):
                // 'purchase' => ['ga4', 'meta'],
                // 'refund' => ['ga4', 'meta'],
                // 'page_view' => ['ga4', 'plausible'],
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Provider Health Monitor (v5.9.0)
        |--------------------------------------------------------------------------
        |
        | Monitors per-provider dispatch success/failure rates and computes
        | health scores (0-100). Unhealthy providers can be automatically
        | bypassed during routing. Integrates with the circuit breaker.
        |
        */
        'provider_health' => [
            'enabled' => env('ANALYTICS_PROVIDER_HEALTH_ENABLED', true),
            'window_duration' => (int) env('ANALYTICS_PROVIDER_HEALTH_WINDOW', 300), // 5 minutes sliding window
            'unhealthy_threshold' => (int) env('ANALYTICS_PROVIDER_HEALTH_THRESHOLD', 50), // Score below this = unhealthy
        ],

        /*
        |--------------------------------------------------------------------------
        | PLG Scoring Engine (v6.0.0)
        |--------------------------------------------------------------------------
        |
        | Product-Led Growth scoring engine computes per-identity PLG scores
        | based on activation, engagement, retention, and feature breadth.
        | Scores are cache-backed and used for user segmentation, dashboards,
        | and automated lifecycle triggers.
        |
        | Dimension weights must sum to 1.0. Default: activation 30%,
        | engagement 30%, retention 25%, feature breadth 15%.
        |
        */
        'plg_scoring' => [
            'weights' => [
                'activation' => 0.30,
                'engagement' => 0.30,
                'retention' => 0.25,
                'feature_breadth' => 0.15,
            ],
            'cache_ttl' => (int) env('ANALYTICS_PLG_CACHE_TTL', 3600), // 1 hour
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Time-Series Aggregation (v6.0.0)
        |--------------------------------------------------------------------------
        |
        | Time-series event aggregation engine for dashboard analytics.
        | Computes per-event and per-category aggregated statistics over
        | configurable time windows with trend analysis and moving averages.
        |
        */
        'time_series' => [
            'cache_ttl' => (int) env('ANALYTICS_TS_CACHE_TTL', 300), // 5 minutes
        ],

        /*
        |--------------------------------------------------------------------------
        | Config Export (v6.5.0)
        |--------------------------------------------------------------------------
        |
        | Controls the runtime config export API for debugging, dashboards,
        | and support workflows. When enabled, the /api/analytics/config/export
        | endpoint returns a redacted snapshot of the analytics configuration.
        |
        | Secrets (api_secret, access_token, api_key, etc.) are automatically
        | redacted in all exports. Use 'expose_secrets' to disable redaction
        | (only for trusted admin environments — never in production).
        |
        */
        'config_export' => [
            'enabled' => env('ANALYTICS_CONFIG_EXPORT_ENABLED', true),
            'expose_secrets' => env('ANALYTICS_CONFIG_EXPORT_SECRETS', false),
            'cache_ttl' => (int) env('ANALYTICS_CONFIG_EXPORT_CACHE_TTL', 60), // 1 minute
        ],

        /*
        |--------------------------------------------------------------------------
        | Cohort Revenue Attribution (v6.6.0)
        |--------------------------------------------------------------------------
        |
        | Correlates cohort membership with revenue events to produce LTV-by-cohort
        | analysis, cumulative revenue curves, and payback period estimation.
        | Revenue data is aggregated in cache — no database required.
        |
        | Used by SaaS teams measuring the economic value of user cohorts over time.
        | Supports weekly (YYYY-WXX), monthly (YYYY-MM), and yearly (YYYY) cohorts.
        |
        */
        'cohort_revenue' => [
            'enabled' => env('ANALYTICS_COHORT_REVENUE_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_COHORT_REVENUE_CACHE_TTL', 3600), // 1 hour
            'monthly_churn_rate' => (float) env('ANALYTICS_COHORT_REVENUE_CHURN', 0.05), // 5% monthly churn
            'arpu' => (float) env('ANALYTICS_COHORT_REVENUE_ARPU', 49.0), // Default ARPU for payback calc
            'max_cohorts' => (int) env('ANALYTICS_COHORT_REVENUE_MAX_COHORTS', 24),
            'projection_months' => (int) env('ANALYTICS_COHORT_REVENUE_PROJECTION', 12),
            'currency' => env('ANALYTICS_COHORT_REVENUE_CURRENCY', 'USD'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Data Mart (v7.0.0)
        |--------------------------------------------------------------------------
        |
        | Pre-aggregated OLAP-style event rollup cubes for instant dashboard queries.
        | Materializes raw analytics events into time-binned summary tables stored
        | in the Laravel cache, inspired by Amplitude/Mixpanel/PostHog data marts.
        |
        | Supports multiple granularity levels (minute, hour, day, week, month)
        | and aggregation dimensions (event_name, category, provider, client_id, user_id).
        | Each cell stores count, unique_count, first_seen, last_seen, and metadata.
        |
        */
        'data_mart' => [
            'enabled' => env('ANALYTICS_DATA_MART_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_DATA_MART_CACHE_TTL', 86400), // 24 hours
            'default_granularity' => env('ANALYTICS_DATA_MART_GRANULARITY', 'hour'),
            'max_dimensions' => (int) env('ANALYTICS_DATA_MART_MAX_DIMENSIONS', 50),
            'auto_dimensions' => ['event_name', 'category'],
            'tracked_categories' => [],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Sparkline Service (v7.2.0)
        |-------------------------------------------------------------------------- 
        |
        | Pre-computed mini time-series arrays for dashboard sparkline widgets.
        | Generates compact data arrays (24-100 points) suitable for inline
        | chart rendering without full charting libraries.
        |
        | Inspired by Amplitude and Mixpanel dashboard sparkline widgets.
        |
        */
        'sparkline' => [
            'enabled' => env('ANALYTICS_SPARKLINE_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_SPARKLINE_CACHE_TTL', 300), // 5 minutes
            'default_points' => (int) env('ANALYTICS_SPARKLINE_POINTS', 24),
            'default_period_hours' => (int) env('ANALYTICS_SPARKLINE_PERIOD', 24),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Attribution Modeling (v7.9.0)
        |-------------------------------------------------------------------------- 
        |
        | Multi-touch attribution models for marketing analytics.
        | Computes weighted credit across touchpoints in conversion journeys
        | using first-touch, last-touch, linear, time-decay, and position-based
        | (U-shaped) models.
        |
        | Inspired by Google Analytics Attribution and Segment Personas.
        |
        */
        'attribution_model' => [
            'enabled' => env('ANALYTICS_ATTRIBUTION_MODEL_ENABLED', true),
            'default_model' => env('ANALYTICS_ATTRIBUTION_MODEL_DEFAULT', 'position_based'), // first_touch, last_touch, linear, time_decay, position_based
            'time_decay_factor' => (float) env('ANALYTICS_ATTRIBUTION_DECAY_FACTOR', 0.5),
            'cache_ttl' => (int) env('ANALYTICS_ATTRIBUTION_MODEL_CACHE_TTL', 3600), // 1 hour
            'enabled_models' => [
                'first_touch' => true,
                'last_touch' => true,
                'linear' => true,
                'time_decay' => true,
                'position_based' => true,
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | SaaS Feature Matrix (v7.9.0)
        |-------------------------------------------------------------------------- 
        |
        | Feature parity benchmarking against industry analytics platforms.
        | When enabled, the feature matrix endpoint compares ZeroBoiler against
        | Segment, Mixpanel, Amplitude, PostHog, Matomo, and Plausible.
        |
        */
        'feature_matrix' => [
            'enabled' => env('ANALYTICS_FEATURE_MATRIX_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_FEATURE_MATRIX_CACHE_TTL', 3600), // 1 hour
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Plugin Registry (v7.8.0)
        |--------------------------------------------------------------------------
        |
        | Allows third-party Laravel packages to register their analytics events
        | with the ZeroBoiler event catalog at runtime. Plugin events are merged
        | into the main catalog via EventCatalog::allWithPlugins().
        |
        | Register plugins in config (static) or via EventPluginRegistry::registerPlugin()
        | during ServiceProvider boot (dynamic). Built-in events always take precedence
        | over plugin events with the same name.
        |
        | Example:
        |   'plugins' => [
        |       'acme/billing' => [
        |           'package' => 'acme/billing',
        |           'version' => '2.0.0',
        |           'priority' => 10,
        |           'events' => [
        |               [
        |                   'name' => 'invoice_paid',
        |                   'class' => \Acme\Billing\Analytics\InvoicePaidEvent::class,
        |                   'ga4' => 'invoice_paid',
        |                   'meta' => 'Purchase',
        |                   'category' => 'billing',
        |               ],
        |           ],
        |       ],
        |   ],
        |
        */
        'event_plugins' => [
            'enabled' => env('ANALYTICS_EVENT_PLUGINS_ENABLED', true),
            'debug' => env('ANALYTICS_EVENT_PLUGINS_DEBUG', false),
            'plugins' => [],
        ],

        /*
        |--------------------------------------------------------------------------
        | Enrichment Plugins (v57.0.0)
        |--------------------------------------------------------------------------
        |
        | Config-driven event enrichment plugin system. Allows third-party packages
        | and application code to register enrichment plugins that transform,
        | augment, or filter analytics events before dispatch.
        |
        | Plugins implement EventEnrichmentPlugin and are resolved from the container
        | or instantiated directly. They run in priority order (highest first).
        |
        | Set 'enabled' to false to bypass all enrichment plugins.
        | Use 'disabled' to individually disable plugins by name.
        |
        | Example:
        |   'plugins' => [
        |       \App\Analytics\Enrichment\GeoEnrichmentPlugin::class,
        |       \App\Analytics\Enrichment\RevenueTagPlugin::class,
        |   ],
        |   'disabled' => ['geo_enrichment'],
        |
        */
        'enrichment_plugins' => [
            'enabled' => env('ANALYTICS_ENRICHMENT_PLUGINS_ENABLED', true),
            'debug' => env('ANALYTICS_ENRICHMENT_PLUGINS_DEBUG', false),
            'disabled' => [],
            'plugins' => [],
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Co-occurrence Matrix (v7.2.0)
        |-------------------------------------------------------------------------- 
        |
        | Tracks which events are frequently dispatched together within sessions.
        | Produces a co-occurrence matrix and correlation scores for:
        | - "Events frequently done together" dashboard widget
        | - User journey path analysis
        | - Feature discovery patterns
        |
        | Inspired by Amplitude Pathfinder and Mixpanel Correlation.
        |
        */
        'cooccurrence' => [
            'enabled' => env('ANALYTICS_COOCCURRENCE_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_COOCCURRENCE_CACHE_TTL', 3600), // 1 hour
            'window_seconds' => (int) env('ANALYTICS_COOCCURRENCE_WINDOW', 1800), // 30 minutes
            'max_events' => (int) env('ANALYTICS_COOCCURRENCE_MAX_EVENTS', 50),
        ],

        /*
        |--------------------------------------------------------------------------
        | Alert Notifications (v7.3.0)
        |--------------------------------------------------------------------------
        |
        | Dispatch analytics alerts to external notification channels (Slack,
        | Discord, Microsoft Teams, generic webhook, or log). When
        | EventAlertRulesService triggers an alert, this service routes it
        | to the appropriate channels based on severity.
        |
        | Per-severity routing maps alert severity levels to channel names.
        | Each channel has independent enable/disable toggle and configuration.
        |
        | Rate limiting prevents notification floods. Channel cooldowns
        | prevent duplicate notifications to the same channel.
        |
        | Example channels:
        |   'slack' => [
        |       'type' => 'slack',
        |       'url' => env('ANALYTICS_SLACK_WEBHOOK_URL', ''),
        |       'timeout' => 5,
        |   ],
        |   'discord' => [
        |       'type' => 'discord',
        |       'url' => env('ANALYTICS_DISCORD_WEBHOOK_URL', ''),
        |       'timeout' => 5,
        |   ],
        |   'teams' => [
        |       'type' => 'teams',
        |       'url' => env('ANALYTICS_TEAMS_WEBHOOK_URL', ''),
        |       'timeout' => 5,
        |   ],
        |
        */
        'alert_notifications' => [
            'enabled' => env('ANALYTICS_ALERT_NOTIFICATIONS_ENABLED', false),
            'rate_limit_window' => (int) env('ANALYTICS_ALERT_NOTIF_RATE_WINDOW', 60),
            'rate_limit_max' => (int) env('ANALYTICS_ALERT_NOTIF_RATE_MAX', 20),
            'max_retries' => (int) env('ANALYTICS_ALERT_NOTIF_RETRIES', 2),
            'retry_base_delay' => (float) env('ANALYTICS_ALERT_NOTIF_RETRY_DELAY', 1.0),
            'channel_cooldown' => (int) env('ANALYTICS_ALERT_NOTIF_CHANNEL_COOLDOWN', 30),
            'severity_routing' => [
                'critical' => ['slack', 'webhook'],
                'elevated' => ['slack'],
                'warning' => ['log'],
                'info' => ['log'],
            ],
            'channels' => [
                // 'slack' => [
                //     'type' => 'slack',
                //     'url' => env('ANALYTICS_SLACK_WEBHOOK_URL', ''),
                //     'secret' => env('ANALYTICS_SLACK_WEBHOOK_SECRET', ''),
                //     'timeout' => (int) env('ANALYTICS_SLACK_TIMEOUT', 5),
                // ],
                // 'discord' => [
                //     'type' => 'discord',
                //     'url' => env('ANALYTICS_DISCORD_WEBHOOK_URL', ''),
                //     'timeout' => (int) env('ANALYTICS_DISCORD_TIMEOUT', 5),
                // ],
                // 'teams' => [
                //     'type' => 'teams',
                //     'url' => env('ANALYTICS_TEAMS_WEBHOOK_URL', ''),
                //     'timeout' => (int) env('ANALYTICS_TEAMS_TIMEOUT', 5),
                // ],
                // 'webhook' => [
                //     'type' => 'webhook',
                //     'url' => env('ANALYTICS_ALERT_WEBHOOK_URL', ''),
                //     'secret' => env('ANALYTICS_ALERT_WEBHOOK_SECRET', ''),
                //     'timeout' => (int) env('ANALYTICS_ALERT_WEBHOOK_TIMEOUT', 5),
                // ],
                'log' => [
                    'type' => 'log',
                ],
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Cohort Intelligence (v8.1.0)
        |--------------------------------------------------------------------------
        |
        | Behavioral cohort profiling and predictive scoring engine.
        | Classifies users into behavioral cohorts (power_users, engaged, at_risk,
        | dormant, new, churning, expanding) based on event patterns.
        |
        | Predictive scoring computes conversion probability, churn risk,
        | expansion likelihood, and composite health scores.
        |
        | Inspired by Amplitude Behavioral Cohorts, ProfitWell Retention Engine,
        | and ChartMogel Churn Radar.
        |
        */
        'cohort_intelligence' => [
            'enabled' => env('ANALYTICS_COHORT_INTELLIGENCE_ENABLED', true),
            'profiler_cache_ttl' => (int) env('ANALYTICS_COHORT_PROFILER_CACHE_TTL', 300), // 5 minutes
            'scoring_cache_ttl' => (int) env('ANALYTICS_COHORT_SCORING_CACHE_TTL', 600), // 10 minutes
            'lookback_days' => (int) env('ANALYTICS_COHORT_LOOKBACK_DAYS', 30),
            'min_events_for_profiling' => (int) env('ANALYTICS_COHORT_MIN_EVENTS', 3),
            'decay_factor' => (float) env('ANALYTICS_COHORT_DECAY_FACTOR', 0.95),
        ],

        /*
        |--------------------------------------------------------------------------
        | Schema Validation (v8.4.0)
        |--------------------------------------------------------------------------
        |
        | Runtime validation of event parameters against typed schema definitions.
        | Validates types, coerces values, and enforces required parameters.
        |
        | Severity levels:
        | - 'reject': Block events with validation errors
        | - 'coerce': Auto-fix type mismatches (e.g. string "42" → int 42)
        | - 'warn': Log warnings but pass events through
        | - 'off': Disable schema validation entirely
        |
        | Inspired by Segment Protocols and PostHog event validation.
        |
        */
        'schema_validation' => [
            'enabled' => env('ANALYTICS_SCHEMA_VALIDATION_ENABLED', true),
            'severity' => env('ANALYTICS_SCHEMA_VALIDATION_SEVERITY', 'coerce'),
            'strip_unknown' => env('ANALYTICS_SCHEMA_VALIDATION_STRIP_UNKNOWN', false),
        ],

        /*
        |--------------------------------------------------------------------------
        | Bot Detection (v8.4.0)
        |--------------------------------------------------------------------------
        |
        | Automated bot detection for analytics API endpoints.
        | Analyzes user-agents, client ID rotation patterns, request velocity,
        | and HTTP header completeness to produce a composite risk score (0-100).
        |
        | Requests scoring above the risk threshold are flagged as bots.
        | Use 'reject_on_bot' to automatically block bot traffic.
        |
        | Inspired by Cloudflare Bot Management and FingerprintJS.
        |
        */
        'bot_detection' => [
            'enabled' => env('ANALYTICS_BOT_DETECTION_ENABLED', true),
            'risk_threshold' => (int) env('ANALYTICS_BOT_DETECTION_THRESHOLD', 70),
            'reject_on_bot' => env('ANALYTICS_BOT_DETECTION_REJECT', false),
            'max_client_ids_per_ip' => (int) env('ANALYTICS_BOT_MAX_CLIENT_IDS', 10),
            'velocity_burst' => (int) env('ANALYTICS_BOT_VELOCITY_BURST', 50),
            'velocity_window' => (int) env('ANALYTICS_BOT_VELOCITY_WINDOW', 60), // seconds
            'bot_ua_patterns' => [
                'bot', 'crawl', 'spider', 'scraper', 'curl', 'wget',
                'python-requests', 'python-urllib', 'httpclient', 'java/',
                'go-http', 'node-fetch', 'axios', 'postmanruntime', 'insomnia',
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Identity Graph — Cross-Device Identity Resolution (v8.7.0)
        |-------------------------------------------------------------------------- 
        |
        | Builds a graph of identity relationships between client IDs, user IDs,
        | device fingerprints, and session IDs. Enables cross-device user stitching —
        | correlating anonymous browsing behavior across devices with authenticated
        | user profiles.
        |
        | Confidence scoring:
        |   - Explicit login/register: 1.0 (100%)
        |   - Same device fingerprint + linked client: 0.8
        |   - Same IP + same user agent: 0.5
        |   - Same cookie pair on different sessions: 0.3
        |
        | All graph data is stored in cache (same driver as identity resolution).
        | Set 'enabled' to false to disable cross-device stitching entirely.
        |
        */
        'identity_graph' => [
            'enabled' => env('ANALYTICS_IDENTITY_GRAPH_ENABLED', true),
            'cache_prefix' => env('ANALYTICS_IDENTITY_GRAPH_CACHE_PREFIX', 'zb_ig_'),
            'graph_ttl' => (int) env('ANALYTICS_IDENTITY_GRAPH_TTL', 7776000), // 90 days (seconds)
            'max_clients_per_user' => (int) env('ANALYTICS_IDENTITY_GRAPH_MAX_CLIENTS', 100),
            'max_devices_per_user' => (int) env('ANALYTICS_IDENTITY_GRAPH_MAX_DEVICES', 50),
            'max_edges_per_node' => (int) env('ANALYTICS_IDENTITY_GRAPH_MAX_EDGES', 200),
            'min_confidence_stitching' => (float) env('ANALYTICS_IDENTITY_GRAPH_MIN_CONFIDENCE', 0.5),
            'min_confidence_merge' => (float) env('ANALYTICS_IDENTITY_GRAPH_MERGE_CONFIDENCE', 0.9),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Device Fingerprinting (v8.7.0)
        |-------------------------------------------------------------------------- 
        |
        | Server-side device fingerprint generation from HTTP request headers.
        | Used by IdentityGraphService for cross-device user stitching.
        | Fingerprints are SHA-256 hashes — no raw headers are stored.
        |
        | Components: user_agent, accept_language, sec_ch_platform, sec_ch_mobile,
        | viewport_width, viewport_height (from client hints or JS-reported).
        |
        | Set 'include_ip' to true to include IP address in fingerprint
        | (not recommended for GDPR compliance).
        |
        */
        'device_fingerprint' => [
            'enabled' => env('ANALYTICS_DEVICE_FINGERPRINT_ENABLED', true),
            'hash_algo' => env('ANALYTICS_DEVICE_FINGERPRINT_ALGO', 'sha256'),
            'include_ip' => env('ANALYTICS_DEVICE_FINGERPRINT_INCLUDE_IP', false),
            'components' => [
                'user_agent',
                'accept_language',
                'sec_ch_platform',
                'sec_ch_mobile',
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Correlation Heatmap (v8.8.0)
        |-------------------------------------------------------------------------- 
        |
        | Computes pairwise Jaccard similarity correlation matrix across tracked
        | events within user sessions. Produces heatmap data for dashboard
        | chart rendering. Events are sorted by co-occurrence frequency.
        |
        | Excluded events (page_view, scroll_depth by default) are omitted from
        | the matrix to focus on meaningful behavioral correlations.
        |
        | Inspired by Amplitude Compass and Mixpanel Event Correlation.
        |
        */
        'correlation_heatmap' => [
            'enabled' => env('ANALYTICS_CORRELATION_HEATMAP_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_CORRELATION_HEATMAP_CACHE_TTL', 3600), // 1 hour
            'cache_prefix' => env('ANALYTICS_CORRELATION_HEATMAP_PREFIX', 'zb_heatmap_'),
            'min_co_occurrences' => (int) env('ANALYTICS_CORRELATION_HEATMAP_MIN_CO', 3),
            'max_events' => (int) env('ANALYTICS_CORRELATION_HEATMAP_MAX_EVENTS', 30),
            'jaccard_threshold' => (float) env('ANALYTICS_CORRELATION_HEATMAP_JACCARD', 0.05),
            'exclude_events' => [
                'page_view',
                'scroll_depth',
                'screen_view',
                'session_start',
                'session_end',
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Health Monitor Dashboard (v8.8.0)
        |-------------------------------------------------------------------------- 
        |
        | Unified health monitoring for the entire analytics stack. Aggregates
        | health data from providers, queue, config, pipeline, consent, and
        | rate limiting into a composite score (0-100) with A-F grading.
        |
        | Dimension weights must sum to 1.0. Adjust based on your monitoring
        | priorities. Default: providers 25%, queue 20%, config 20%,
        | pipeline 15%, consent 10%, rate limiting 10%.
        |
        | Use via artisan: zb:analytics:health-monitor --json
        | Or via API: GET /api/analytics/health-monitor
        |
        */
        'health_monitor' => [
            'enabled' => env('ANALYTICS_HEALTH_MONITOR_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_HEALTH_MONITOR_CACHE_TTL', 300), // 5 minutes
            'weights' => [
                'providers' => (float) env('ANALYTICS_HEALTH_MONITOR_W_PROVIDERS', 0.25),
                'queue' => (float) env('ANALYTICS_HEALTH_MONITOR_W_QUEUE', 0.20),
                'config' => (float) env('ANALYTICS_HEALTH_MONITOR_W_CONFIG', 0.20),
                'pipeline' => (float) env('ANALYTICS_HEALTH_MONITOR_W_PIPELINE', 0.15),
                'consent' => (float) env('ANALYTICS_HEALTH_MONITOR_W_CONSENT', 0.10),
                'rate_limiting' => (float) env('ANALYTICS_HEALTH_MONITOR_W_RATE', 0.10),
            ],
            'dimensions' => [
                'providers',
                'queue',
                'config',
                'pipeline',
                'consent',
                'rate_limiting',
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Guard Rails (v8.9.0)
        |--------------------------------------------------------------------------
        |
        | Tracking quality monitoring engine inspired by Amplitude Compass,
        | Mixpanel Data Governance, and Segment Protocols.
        |
        | Computes a composite quality score (0-100) across 6 dimensions:
        | - Schema Compliance (25%)
        | - Naming Convention (20%)
        | - Coverage Completeness (20%)
        | - Provider Coverage (15%)
        | - Identity Linking (10%)
        | - Consent Compliance (10%)
        |
        | Use via artisan: zb:analytics:guard-rails --json
        | Or via API: GET /analytics/guard-rails
        |
        */
        'guard_rails' => [
            'enabled' => env('ANALYTICS_GUARD_RAILS_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_GUARD_RAILS_CACHE_TTL', 300), // 5 minutes
            'minimum_events' => (int) env('ANALYTICS_GUARD_RAILS_MIN_EVENTS', 100), // Minimum events before full assessment
        ],

        /*
        |--------------------------------------------------------------------------
        | Delivery Confirmation (v9.0.0)
        |--------------------------------------------------------------------------
        |
        | Event delivery confirmation and reliability monitoring system.
        | Tracks whether events are successfully delivered to each provider,
        | with response time percentiles, outage detection, and SLA monitoring.
        |
        | Inspired by Segment's delivery confirmation, Mixpanel's event
        | verification, and Amplitude's event monitoring dashboard.
        |
        | Use via artisan: zb:analytics:delivery --json
        | Or via API: GET /analytics/delivery
        |
        */
        'delivery_confirmation' => [
            'enabled' => env('ANALYTICS_DELIVERY_CONFIRMATION_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_DELIVERY_CONFIRMATION_CACHE_TTL', 3600), // 1 hour
            'retention_window' => (int) env('ANALYTICS_DELIVERY_CONFIRMATION_RETENTION', 86400), // 24 hours
            'outage_threshold' => (int) env('ANALYTICS_DELIVERY_CONFIRMATION_OUTAGE_THRESHOLD', 10), // Consecutive failures before outage alert
            'sla_target' => (float) env('ANALYTICS_DELIVERY_CONFIRMATION_SLA_TARGET', 99.5), // SLA target percentage
        ],

        /*
        |--------------------------------------------------------------------------
        | SaaS Lifecycle Observer (v9.2.0)
        |--------------------------------------------------------------------------
        |
        | Real-time SaaS health monitoring via computed lifecycle signals.
        | Tracks trial activation scores, churn risk indicators, expansion
        | revenue momentum, feature adoption depth, and conversion funnel
        | progress for each user identity.
        |
        | Computed signals are cached for dashboard queries and admin commands.
        |
        */
        'lifecycle_observer' => [
            'enabled' => env('ANALYTICS_LIFECYCLE_OBSERVER_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_LIFECYCLE_OBSERVER_CACHE_TTL', 3600), // 1 hour
        ],

        /*
        |--------------------------------------------------------------------------
        | Analytics Readiness Score (v9.2.0)
        |--------------------------------------------------------------------------
        |
        | Comprehensive self-assessment scoring system that evaluates your
        | analytics setup across 8 dimensions:
        | - Provider configuration
        | - Event catalog coverage
        | - Identity tracking
        | - Consent compliance
        | - Queue infrastructure
        | - E-commerce tracking
        | - SaaS lifecycle tracking
        | - Client-side integration
        |
        | Use via artisan: zb:analytics:readiness
        | Or via API: GET /analytics/readiness
        | Or programmatically: AnalyticsReadinessScoreService::compute()
        |
        */
        'readiness_score' => [
            'enabled' => env('ANALYTICS_READINESS_SCORE_ENABLED', true),
            'passing_threshold' => (int) env('ANALYTICS_READINESS_THRESHOLD', 60), // Score >= 60 = "ready"
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Idempotency (v9.3.0)
        |--------------------------------------------------------------------------
        |
        | Prevents duplicate analytics event dispatches using idempotency keys.
        | When enabled, each event is fingerprinted (name + client_id + user_id + params hash)
        | and stored in cache. Duplicate events within the TTL window are silently dropped.
        |
        | Inspired by Stripe's idempotency key pattern.
        |
        */
        'idempotency' => [
            'enabled' => env('ANALYTICS_IDEMPOTENCY_ENABLED', true),
            'ttl' => (int) env('ANALYTICS_IDEMPOTENCY_TTL', 3600), // 1 hour
            'max_keys' => (int) env('ANALYTICS_IDEMPOTENCY_MAX_KEYS', 100000), // Max cached keys
            'prefix' => env('ANALYTICS_IDEMPOTENCY_PREFIX', 'zb_idem_'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Privacy Manifest — GDPR Article 30 (v9.3.0)
        |--------------------------------------------------------------------------
        |
        | Automated Records of Processing Activities (RoPA) generation for all
        | registered analytics events. Produces structured GDPR documentation
        | covering data categories, legal bases, retention periods, data flows,
        | and data subject rights implementation status.
        |
        | Use via API: GET /api/analytics/privacy-manifest
        |
        */
        'privacy_manifest' => [
            'enabled' => env('ANALYTICS_PRIVACY_MANIFEST_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_PRIVACY_MANIFEST_CACHE_TTL', 3600), // 1 hour
            'controller_email' => env('ANALYTICS_PRIVACY_CONTROLLER_EMAIL', 'privacy@example.com'),
            'dpo_email' => env('ANALYTICS_PRIVACY_DPO_EMAIL'), // null = no DPO
            'legal_basis_defaults' => [
                // 'identifier' => 'consent',
                // 'financial' => 'contract',
                // 'behavioral' => 'legitimate_interest',
            ],
            'retention_defaults' => [
                // 'financial' => 2555, // 7 years
                // 'identifier' => 1095, // 3 years
                // 'behavioral' => 90,    // 90 days
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Annotations (v9.3.0)
        |--------------------------------------------------------------------------
        |
        | Allows attaching deployment markers, debug flags, release tags, and
        | custom annotations to analytics events. Useful for deployment
        | correlation analysis and A/B rollout tracking.
        |
        */
        'annotations' => [
            'enabled' => env('ANALYTICS_ANNOTATIONS_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_ANNOTATIONS_CACHE_TTL', 86400), // 24 hours
            'max_annotations_per_event' => (int) env('ANALYTICS_ANNOTATIONS_MAX_PER_EVENT', 20),
            'auto_attach' => [
                'deployment_version' => env('ANALYTICS_ANNOTATIONS_AUTO_DEPLOYMENT_VERSION', false),
                'deployment_version_value' => env('ANALYTICS_ANNOTATIONS_DEPLOYMENT_VERSION_VALUE'),
                'environment' => env('ANALYTICS_ANNOTATIONS_AUTO_ENVIRONMENT', false),
                'debug_in_non_production' => env('ANALYTICS_ANNOTATIONS_DEBUG_IN_NON_PROD', false),
                'release_tag' => env('ANALYTICS_ANNOTATIONS_AUTO_RELEASE_TAG', false),
                'release_tag_value' => env('ANALYTICS_ANNOTATIONS_RELEASE_TAG_VALUE'),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Provider Fallback Strategy (v9.4.0)
        |--------------------------------------------------------------------------
        |
        | When a primary analytics provider fails (circuit breaker opens), events
        | are automatically redirected to configured fallback providers.
        | Chains are ordered lists — the first healthy provider receives the event.
        |
        | Supported providers: ga4, gtm, meta, posthog, plausible, webhook
        |
        | Example: If GA4 goes down, fall back to server-side GTM, then Meta CAPI:
        |   'chains' => [
        |       'ga4' => ['gtm', 'meta', 'posthog'],
        |   ],
        |
        */
        'fallback' => [
            'enabled' => env('ANALYTICS_FALLBACK_ENABLED', true),
            'max_depth' => (int) env('ANALYTICS_FALLBACK_MAX_DEPTH', 3),
            'cache_prefix' => env('ANALYTICS_FALLBACK_CACHE_PREFIX', 'zb_fallback_'),
            'chains' => [
                // 'ga4' => ['gtm', 'meta', 'posthog'],
                // 'meta' => ['ga4', 'posthog'],
                // 'posthog' => ['ga4', 'meta'],
                // 'mixpanel' => ['amplitude', 'posthog'],
                // 'amplitude' => ['mixpanel', 'posthog'],
                // 'tiktok' => ['linkedin', 'meta'],
                // 'linkedin' => ['tiktok', 'meta'],
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Cross-Domain Tracking (v9.8.0)
        |--------------------------------------------------------------------------
        |
        | Enables unified visitor tracking across multiple domains (e.g.,
        | app.example.com + docs.example.com + blog.example.com).
        |
        | Supports linker parameter decoration (auto-appends _zbclid to outbound
        | links) and auth-based client ID linking across domains.
        |
        | Configure your tracked domains and the linker will automatically
        | stitch client IDs together when the same user visits multiple domains.
        |
        */
        'cross_domain' => [
            'enabled' => env('ANALYTICS_CROSS_DOMAIN_ENABLED', false),
            'domains' => [
                // 'app.example.com',
                // 'docs.example.com',
                // 'blog.example.com',
            ],
            'linker_param' => env('ANALYTICS_CROSS_DOMAIN_LINKER_PARAM', '_zbclid'),
            'auto_linker' => env('ANALYTICS_CROSS_DOMAIN_AUTO_LINKER', true),
            'cache_prefix' => env('ANALYTICS_CROSS_DOMAIN_CACHE_PREFIX', 'zb_crossdomain_'),
            'link_ttl' => (int) env('ANALYTICS_CROSS_DOMAIN_LINK_TTL', 900), // 15 minutes
            'excluded_domains' => [
                // 'internal.example.com',
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Session Recording Bridge (v9.8.0)
        |--------------------------------------------------------------------------
        |
        | Consent-aware integration with session recording tools (Hotjar, LogRocket,
        | FullStory, Microsoft Clarity). Recording is suppressed when consent is
        | denied, for excluded roles (admin/support), and on sensitive pages.
        |
        | PII masking is enabled by default — elements with data-zb-mask or .masked
        | classes are visually masked in recordings. Elements with data-zb-block or
        | .blocked classes are completely hidden from recordings.
        |
        */
        'session_recording' => [
            'enabled' => env('ANALYTICS_SESSION_RECORDING_ENABLED', false),
            'integrations' => [
                // 'hotjar' => ['site_id' => env('ANALYTICS_HOTJAR_SITE_ID'), 'version' => 6],
                // 'logrocket' => ['id' => env('ANALYTICS_LOGROCKET_ID')],
                // 'fullstory' => ['org' => env('ANALYTICS_FULLSTORY_ORG')],
                // 'clarity' => ['project' => env('ANALYTICS_CLARITY_PROJECT')],
            ],
            'cache_prefix' => env('ANALYTICS_SESSION_RECORDING_CACHE_PREFIX', 'zb_recording_'),
            'session_ttl' => (int) env('ANALYTICS_SESSION_RECORDING_TTL', 1800), // 30 minutes
            'excluded_patterns' => [
                '/admin/*',
                '/billing/*',
                '/settings/*',
                '/api/*',
            ],
            'excluded_roles' => ['admin', 'super_admin'],
            'consent_aware' => env('ANALYTICS_SESSION_RECORDING_CONSENT_AWARE', true),
            'mask_pii' => env('ANALYTICS_SESSION_RECORDING_MASK_PII', true),
            'mask_selectors' => ['[data-zb-mask]', '.masked'],
            'block_selectors' => ['[data-zb-block]', '.blocked'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Schema Export (v9.8.0)
        |--------------------------------------------------------------------------
        |
        | Auto-generate JSON Schema, TypeScript definitions, and OpenAPI specs
        | from the event catalog. Used by the admin command:
        |   php artisan zb:analytics:export-schema --format=json|typescript|openapi
        |
        | Generated schemas can be consumed by downstream API gateways, SDKs,
        | and documentation tools to ensure type-safe event tracking.
        |
        */
        'schema_export' => [
            'enabled' => env('ANALYTICS_SCHEMA_EXPORT_ENABLED', true),
            'output_path' => env('ANALYTICS_SCHEMA_EXPORT_PATH', resource_path('docs/analytics')),
            'include_provider_mappings' => env('ANALYTICS_SCHEMA_EXPORT_PROVIDERS', true),
        ],

        /*
        |--------------------------------------------------------------------------
        | API Rate Limiting (v9.8.0)
        |--------------------------------------------------------------------------
        |
        | Redis-backed rate limiting for analytics API endpoints.
        | Uses Laravel's RateLimiter facade for distributed rate control.
        |
        | Applies three tiers:
        | - Global: Total events/minute across all clients
        | - Per-client: Events/minute per client ID
        | - Per-user: Events/minute per authenticated user
        |
        | Batch endpoints have separate, higher limits.
        |
        */
        'rate_limit' => [
            'enabled' => env('ANALYTICS_RATE_LIMIT_ENABLED', true),
            'global_limit' => (int) env('ANALYTICS_RATE_LIMIT_GLOBAL', 10000),
            'client_limit' => (int) env('ANALYTICS_RATE_LIMIT_CLIENT', 300),
            'user_limit' => (int) env('ANALYTICS_RATE_LIMIT_USER', 600),
            'batch_global_limit' => (int) env('ANALYTICS_RATE_LIMIT_BATCH_GLOBAL', 5000),
            'batch_client_limit' => (int) env('ANALYTICS_RATE_LIMIT_BATCH_CLIENT', 100),
            'max_batch_size' => (int) env('ANALYTICS_RATE_LIMIT_MAX_BATCH', 50),
            'prefix' => env('ANALYTICS_RATE_LIMIT_PREFIX', 'zb_analytics_'),
            'decay_seconds' => (int) env('ANALYTICS_RATE_LIMIT_DECAY', 60),
        ],

        /*
        |--------------------------------------------------------------------------
        | API Guard (v17.0.0)
        |--------------------------------------------------------------------------
        |
        | Pre-dispatch request validation and rate limiting for analytics API.
        | Validates payload size, event name lengths, and batch sizes before
        | any event processing occurs. Rejects abusive requests early.
        |
        | The AnalyticsApiGuard reads from this config section.
        |
        */
        'api_guard' => [
            'enabled' => env('ANALYTICS_API_GUARD_ENABLED', true),
            'batch_max' => (int) env('ANALYTICS_API_GUARD_BATCH_MAX', 25),
            'max_payload_bytes' => (int) env('ANALYTICS_API_GUARD_MAX_PAYLOAD', 65536), // 64KB
            'max_event_name_length' => (int) env('ANALYTICS_API_GUARD_MAX_NAME_LENGTH', 100),
            'rate_window' => (int) env('ANALYTICS_API_GUARD_RATE_WINDOW', 60), // seconds
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Budget (v17.0.0)
        |--------------------------------------------------------------------------
        |
        | Per-client and per-user event budget enforcement to prevent abuse
        | and control costs. Supports sliding window rate limiting with
        | configurable limits and overflow policies.
        |
        | Policies: 'reject' (drop events), 'sample' (accept fraction), 'throttle'
        |
        */
        'budget' => [
            'enabled' => env('ANALYTICS_BUDGET_ENABLED', true),
            'client_limit' => (int) env('ANALYTICS_BUDGET_CLIENT_LIMIT', 1000), // events per window
            'user_limit' => (int) env('ANALYTICS_BUDGET_USER_LIMIT', 500),
            'global_limit' => (int) env('ANALYTICS_BUDGET_GLOBAL_LIMIT', 100000),
            'window_seconds' => (int) env('ANALYTICS_BUDGET_WINDOW', 3600), // 1 hour
            'overflow_policy' => env('ANALYTICS_BUDGET_OVERFLOW_POLICY', 'reject'), // reject, sample, throttle
            'sample_rate' => (float) env('ANALYTICS_BUDGET_SAMPLE_RATE', 0.1), // when policy is 'sample'
            'cache_ttl' => (int) env('ANALYTICS_BUDGET_CACHE_TTL', 3600),
            'use_cache' => env('ANALYTICS_BUDGET_USE_CACHE', true),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Observability (v18.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Dispatch-level observability for the analytics pipeline. Tracks
        | per-provider latency histograms, success/failure rates, error budgets,
        | and dispatch volume timelines for production monitoring dashboards.
        |
        | Complements EventSignalIntelligenceService (event pattern anomalies)
        | by focusing on the operational health of the dispatch pipeline.
        |
        | Inspired by OpenTelemetry metrics and Segment's Observability API.
        |
        */
        'observability' => [
            'enabled' => env('ANALYTICS_OBSERVABILITY_ENABLED', true),
            'ttl' => (int) env('ANALYTICS_OBSERVABILITY_TTL', 300), // 5 minutes
            'providers' => [], // empty = observe all providers; e.g., ['ga4', 'meta', 'posthog']
            'error_budget_threshold' => (float) env('ANALYTICS_OBSERVABILITY_ERROR_BUDGET', 0.01), // 1% failure rate
            'slow_dispatch_ms' => (float) env('ANALYTICS_OBSERVABILITY_SLOW_MS', 1000.0), // 1 second
            'latency_buckets' => (int) env('ANALYTICS_OBSERVABILITY_LATENCY_BUCKETS', 50),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Transport Layer (v20.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Abstract HTTP transport with configurable retry, timeout, and circuit
        | breaker for analytics provider dispatch. Wraps HTTP client calls with
        | production-grade reliability features. Per-provider circuit state tracking.
        |
        | Inspired by Segment's transport layer, RudderStack's batching transport,
        | and the circuit breaker pattern from Michael Nygard.
        |
        */
        'transport' => [
            'enabled' => env('ANALYTICS_TRANSPORT_ENABLED', true),
            'default_timeout' => (int) env('ANALYTICS_TRANSPORT_TIMEOUT', 5), // seconds
            'default_retries' => (int) env('ANALYTICS_TRANSPORT_RETRIES', 2),
            'circuit_threshold' => (float) env('ANALYTICS_TRANSPORT_CIRCUIT_THRESHOLD', 5.0),
            'circuit_reset_timeout' => (int) env('ANALYTICS_TRANSPORT_CIRCUIT_RESET', 60), // seconds
            'circuit_half_open_max' => (int) env('ANALYTICS_TRANSPORT_HALF_OPEN_MAX', 3),
            'metrics_ttl' => (int) env('ANALYTICS_TRANSPORT_METRICS_TTL', 300), // 5 minutes
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Correlation Matrix (v20.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Statistical cross-event correlation scoring using Jaccard similarity.
        | Analyzes event co-occurrence patterns to identify significant
        | relationships between tracked events for funnel insights.
        |
        | Inspired by PostHog's Event Correlation, Amplitude's Compass,
        | and Mixpanel's Signal feature.
        |
        */
        'correlation' => [
            'enabled' => env('ANALYTICS_CORRELATION_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_CORRELATION_CACHE_TTL', 600), // 10 minutes
            'min_event_count' => (int) env('ANALYTICS_CORRELATION_MIN_COUNT', 5),
            'min_correlation' => (float) env('ANALYTICS_CORRELATION_MIN_SCORE', 0.1),
            'max_pairs' => (int) env('ANALYTICS_CORRELATION_MAX_PAIRS', 100),
            'time_window' => (int) env('ANALYTICS_CORRELATION_TIME_WINDOW', 86400), // 24 hours
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Data Lake Export (v20.0.0)
        |-------------------------------------------------------------------------- 
        |
        | S3/GCS-compatible event export for data warehousing and ETL processing.
        | Supports batch exports, incremental exports, partitioned output,
        | and configurable file formats (JSONL, CSV).
        |
        | Inspired by Segment's Warehouse sync, RudderStack's Object Storage
        | destination, and the analytics data lake pattern.
        |
        */
        'data_lake' => [
            'enabled' => env('ANALYTICS_DATA_LAKE_ENABLED', false),
            'storage' => env('ANALYTICS_DATA_LAKE_STORAGE', 'null'), // s3, gcs, local, null
            'bucket' => env('ANALYTICS_DATA_LAKE_BUCKET', ''),
            'prefix' => env('ANALYTICS_DATA_LAKE_PREFIX', 'analytics/events/'),
            'format' => env('ANALYTICS_DATA_LAKE_FORMAT', 'jsonl'), // jsonl, csv, ndjson
            'batch_size' => (int) env('ANALYTICS_DATA_LAKE_BATCH_SIZE', 10000),
            'retention_days' => (int) env('ANALYTICS_DATA_LAKE_RETENTION', 365),
            'partition_by_date' => env('ANALYTICS_DATA_LAKE_PARTITION', true),
            'compress' => env('ANALYTICS_DATA_LAKE_COMPRESS', true),
            'timeout' => (int) env('ANALYTICS_DATA_LAKE_TIMEOUT', 300), // 5 minutes
        ],

        /*
        |-------------------------------------------------------------------------- 
        | SDK Scope Tokens (v20.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Scoped write tokens for client-side permission management. Generates
        | and validates tokens that control which analytics operations a
        | client-side SDK is authorized to perform. Tokens define allowed
        | event types, rate limits, and data access boundaries.
        |
        | Inspired by Segment's write keys, PostHog project API keys,
        | and Plausible's site-specific API tokens.
        |
        */
        'sdk_tokens' => [
            'enabled' => env('ANALYTICS_SDK_TOKENS_ENABLED', false),
            'token_ttl' => (int) env('ANALYTICS_SDK_TOKENS_TTL', 7776000), // 90 days
            'default_rate_limit' => (int) env('ANALYTICS_SDK_TOKENS_RATE_LIMIT', 100), // per minute
            'max_tokens_per_scope' => (int) env('ANALYTICS_SDK_TOKENS_MAX_PER_SCOPE', 10),
            'hash_algorithm' => env('ANALYTICS_SDK_TOKENS_HASH', 'sha256'),
            'signing_key' => env('ANALYTICS_SDK_TOKENS_SIGNING_KEY', ''),

            // SDK Token Audit Logging (v156.0.0)
            // Tracks all token operations for GDPR Article 30 compliance
            // and security incident investigation.
            'audit' => [
                'enabled' => env('ANALYTICS_SDK_TOKENS_AUDIT_ENABLED', true),
                'ttl' => (int) env('ANALYTICS_SDK_TOKENS_AUDIT_TTL', 604800), // 7 days
                'max_entries' => (int) env('ANALYTICS_SDK_TOKENS_AUDIT_MAX', 1000),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Schema Runtime Validation (v21.0.0)
        |--------------------------------------------------------------------------
        |
        | Validates dispatched events against their registered parameter schemas
        | in the EventSchemaRegistry. Checks required parameters, value types,
        | string lengths, numeric ranges, and regex patterns.
        |
        | Modes:
        | - 'strict': Reject events that fail validation
        | - 'warn':  Log warnings but allow dispatch
        | - 'off':   Skip validation entirely
        |
        */
        'schema_validation' => [
            'enabled' => env('ANALYTICS_SCHEMA_VALIDATION_ENABLED', false),
            'mode' => env('ANALYTICS_SCHEMA_VALIDATION_MODE', 'warn'), // strict, warn, off
            'enforce_catalog_membership' => env('ANALYTICS_SCHEMA_VALIDATION_CATALOG', true),
        ],

        /*
        |--------------------------------------------------------------------------
        | Composable Enrichment Pipeline (v21.0.0)
        |--------------------------------------------------------------------------
        |
        | Config-driven, ordered event enrichment stages that run before dispatch.
        | Each stage can add, transform, or remove event parameters.
        |
        | Built-in stages: utm_source, device_context, session_context,
        | timestamp_normalize, pii_scrub, tenant_tag, identity_link,
        | cost_tag, source_tag, consent_filter
        |
        | Stages are executed in priority order (higher = runs first).
        |
        */
        'enrichment_pipeline' => [
            'enabled' => env('ANALYTICS_ENRICHMENT_PIPELINE_ENABLED', true),
            'stages' => [
                ['stage' => 'pii_scrub', 'enabled' => true, 'priority' => 100, 'config' => []],
                ['stage' => 'consent_filter', 'enabled' => true, 'priority' => 90, 'config' => []],
                ['stage' => 'utm_source', 'enabled' => true, 'priority' => 80, 'config' => []],
                ['stage' => 'device_context', 'enabled' => true, 'priority' => 70, 'config' => []],
                ['stage' => 'session_context', 'enabled' => true, 'priority' => 60, 'config' => []],
                ['stage' => 'tenant_tag', 'enabled' => false, 'priority' => 50, 'config' => []],
                ['stage' => 'identity_link', 'enabled' => true, 'priority' => 40, 'config' => []],
                ['stage' => 'timestamp_normalize', 'enabled' => true, 'priority' => 30, 'config' => []],
                ['stage' => 'cost_tag', 'enabled' => false, 'priority' => 20, 'config' => []],
                ['stage' => 'source_tag', 'enabled' => true, 'priority' => 10, 'config' => []],
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Analytics Audit Log (v21.0.0)
        |--------------------------------------------------------------------------
        |
        | Immutable append-only audit trail for GDPR Article 30 compliance.
        | Records event name, timestamp, source, provider results, and payload
        | hash for integrity verification.
        |
        | Use excluded_events to suppress noise from high-frequency events.
        |
        */
        'audit_log' => [
            'enabled' => env('ANALYTICS_AUDIT_LOG_ENABLED', false),
            'retention_days' => (int) env('ANALYTICS_AUDIT_LOG_RETENTION', 90),
            'max_entries' => (int) env('ANALYTICS_AUDIT_LOG_MAX_ENTRIES', 10000),
            'log_success' => env('ANALYTICS_AUDIT_LOG_SUCCESS', true),
            'log_failures' => env('ANALYTICS_AUDIT_LOG_FAILURES', true),
            'excluded_events' => [
                // 'page_view', // Uncomment to exclude high-frequency events
            ],
            'excluded_categories' => [],
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Fingerprinting (v21.0.0)
        |--------------------------------------------------------------------------
        |
        | Deterministic content-based event hashing for idempotent dispatch.
        | Generates consistent fingerprints across retries enabling exact
        | deduplication and idempotency keys for API requests.
        |
        */
        'fingerprinting' => [
            'time_bucket_seconds' => (int) env('ANALYTICS_FINGERPRINT_TIME_BUCKET', 60),
            'include_client_id' => env('ANALYTICS_FINGERPRINT_CLIENT_ID', true),
            'include_user_id' => env('ANALYTICS_FINGERPRINT_USER_ID', true),
            'ignore_internal_params' => env('ANALYTICS_FINGERPRINT_IGNORE_INTERNAL', true),
            'algorithm' => env('ANALYTICS_FINGERPRINT_ALGORITHM', 'xxh128'), // xxh128, sha256, md5
            'max_cache_size' => (int) env('ANALYTICS_FINGERPRINT_CACHE_SIZE', 1000),
        ],

        /*
        |--------------------------------------------------------------------------
        | CDP Profile Unification (v29.0.0)
        |--------------------------------------------------------------------------
        |
        | Customer Data Platform (CDP) style profile unification. Aggregates identity
        | data, event history, user properties, and attribution context from multiple
        | analytics sources into a single unified customer profile.
        |
        | Inspired by Segment Personas, mParticle Audience, RudderStack Profiles.
        |
        */
        'cdp' => [
            'enabled' => env('ANALYTICS_CDP_ENABLED', true),
            'debug' => env('ANALYTICS_CDP_DEBUG', false),
            'cache_prefix' => env('ANALYTICS_CDP_CACHE_PREFIX', 'zb_cdp_profile_'),
            'profile_ttl' => (int) env('ANALYTICS_CDP_PROFILE_TTL', 2592000), // 30 days
            'max_recent_events' => (int) env('ANALYTICS_CDP_MAX_RECENT_EVENTS', 50),
        ],

        /*
        |--------------------------------------------------------------------------
        | Computed Traits (v29.0.0)
        |--------------------------------------------------------------------------
        |
        | Segment-style computed traits engine. Evaluates user properties against
        | configurable rules to automatically derive profile traits.
        |
        | Operations: exists, eq, gt, gte, lt, lte, contains, in, count,
        |   is_true, is_false, regex, age_days, not_exists, neq, not_in
        |
        | Example rule:
        |   'is_paying' => [
        |       'property' => 'plan',
        |       'operation' => '!=',
        |       'value' => 'free',
        |       'output' => 'is_paying',
        |       'type' => 'bool',
        |   ],
        |
        */
        'computed_traits' => [
            'enabled' => env('ANALYTICS_COMPUTED_TRAITS_ENABLED', true),
            'debug' => env('ANALYTICS_COMPUTED_TRAITS_DEBUG', false),
            'cache_prefix' => env('ANALYTICS_COMPUTED_TRAITS_CACHE_PREFIX', 'zb_ct_'),
            'cache_ttl' => (int) env('ANALYTICS_COMPUTED_TRAITS_CACHE_TTL', 3600), // 1 hour
            'rules' => [
                // 'is_paying' => ['property' => 'plan', 'operation' => '!=', 'value' => 'free', 'output' => 'is_paying', 'type' => 'bool'],
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Privacy Report Generator (v29.0.0)
        |--------------------------------------------------------------------------
        |
        | GDPR Article 30 and CCPA compliance report generation.
        | Generates audit-ready reports for regulatory compliance including
        | Records of Processing Activities (ROPA), data inventory, consent
        | audits, and Data Subject Access Reports (DSAR).
        |
        */
        'privacy_report' => [
            'enabled' => env('ANALYTICS_PRIVACY_REPORT_ENABLED', true),
            'cache_prefix' => env('ANALYTICS_PRIVACY_REPORT_CACHE_PREFIX', 'zb_privacy_report_'),
            'report_ttl' => (int) env('ANALYTICS_PRIVACY_REPORT_TTL', 3600), // 1 hour
            'organization_name' => env('ANALYTICS_PRIVACY_REPORT_ORG', ''),
            'dpo_contact' => env('ANALYTICS_PRIVACY_REPORT_DPO_CONTACT', ''),
            'jurisdiction' => env('ANALYTICS_PRIVACY_REPORT_JURISDICTION', 'GDPR'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Debug Capture (v29.0.0)
        |--------------------------------------------------------------------------
        |
        | Event capture and replay service for debugging. Stores dispatched
        | events with full context for inspection, replay, and simulation.
        |
        | WARNING: Enable only in development/staging. Performance impact in production.
        |
        */
        'debug_capture' => [
            'enabled' => env('ANALYTICS_DEBUG_CAPTURE_ENABLED', false),
            'debug' => env('ANALYTICS_DEBUG_CAPTURE_DEBUG', false),
            'cache_prefix' => env('ANALYTICS_DEBUG_CAPTURE_CACHE_PREFIX', 'zb_debug_'),
            'capture_ttl' => (int) env('ANALYTICS_DEBUG_CAPTURE_TTL', 3600), // 1 hour
            'max_events' => (int) env('ANALYTICS_DEBUG_CAPTURE_MAX_EVENTS', 500),
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Store — Persistent Event Storage (v30.0.0)
        |--------------------------------------------------------------------------
        |
        | Configures the analytics event persistence layer. Events can be stored
        | in a database for historical queries, replay, GDPR compliance, and
        | data warehouse exports, or in cache for ephemeral real-time dashboards.
        |
        | Drivers:
        |   - 'database' (or 'db'): Persistent storage via Eloquent/MySQL
        |   - 'cache': Ephemeral storage via the configured cache driver
        |   - 'null': No persistence (events are dispatched but not stored)
        |
        | Set 'auto_persist' to true to automatically store all dispatched events.
        | When enabled, every event that passes through the dispatch pipeline
        | is also persisted to the configured store.
        |
        | Use 'fallback_driver' to configure a secondary store for high availability.
        | When the primary store fails, events are automatically written to the fallback.
        |
        | The 'retention_days' setting controls automatic pruning of database events
        | via Laravel's Prunable trait. Default: 90 days.
        |
        */
        'event_store' => [
            'enabled' => env('ANALYTICS_EVENT_STORE_ENABLED', false),
            'driver' => env('ANALYTICS_EVENT_STORE_DRIVER', 'cache'), // database, cache, null
            'auto_persist' => env('ANALYTICS_EVENT_STORE_AUTO_PERSIST', false),
            'fallback_driver' => env('ANALYTICS_EVENT_STORE_FALLBACK_DRIVER'), // null = no fallback

            // Database driver settings
            'db_connection' => env('ANALYTICS_EVENT_STORE_DB_CONNECTION', 'mysql'),
            'db_table' => env('ANALYTICS_EVENT_STORE_DB_TABLE', 'analytics_events'),

            // Cache driver settings
            'cache_store' => env('ANALYTICS_EVENT_STORE_CACHE_STORE'), // null = default
            'cache_ttl' => (int) env('ANALYTICS_EVENT_STORE_CACHE_TTL', 86400), // 24 hours

            // Retention policy (database driver only)
            'retention_days' => (int) env('ANALYTICS_EVENT_STORE_RETENTION_DAYS', 90),
        ],

        /*
        |--------------------------------------------------------------------------
        | API Endpoints (v32.0.0)
        |--------------------------------------------------------------------------
        |
        | Configuration for the analytics API controller.
        | Controls authentication, rate limiting, and request validation behavior.
        |
        */
        'api' => [
            'enabled' => env('ANALYTICS_API_ENABLED', true),
            'base_url' => env('ANALYTICS_API_BASE_URL', '/api/analytics'),
            'middleware' => env('ANALYTICS_API_MIDDLE', ''), // additional middleware (e.g., 'throttle:60,1')
            'rate_limit' => [
                'enabled' => env('ANALYTICS_API_RATE_LIMIT_ENABLED', true),
                'max_requests' => (int) env('ANALYTICS_API_RATE_LIMIT_MAX', 120),
                'decay_minutes' => (int) env('ANALYTICS_API_RATE_LIMIT_DECAY', 1),
            ],
            'max_batch_size' => (int) env('ANALYTICS_API_MAX_BATCH', 25),
            'max_event_name_length' => (int) env('ANALYTICS_API_MAX_NAME_LENGTH', 100),
            'max_param_count' => (int) env('ANALYTICS_API_MAX_PARAMS', 100),
        ],

        /*
        |--------------------------------------------------------------------------
        | TikTok Pixel (v32.0.0)
        |--------------------------------------------------------------------------
        |
        | TikTok Pixel and Conversions API (CAPI) configuration.
        | Supports client-side pixel tracking via ttq and server-side
        | event tracking via the TikTok Events API.
        |
        | Required: pixel_id + access_token for server-side CAPI.
        | Client-side pixel rendering only requires pixel_id.
        |
        */
        'tiktok' => [
            'enabled' => env('ANALYTICS_TIKTOK_ENABLED', false),
            'pixel_id' => env('ANALYTICS_TIKTOK_PIXEL_ID', ''),
            'access_token' => env('ANALYTICS_TIKTOK_ACCESS_TOKEN', ''),
            'api_version' => env('ANALYTICS_TIKTOK_API_VERSION', 'v1.3'),
        ],

        /*
        |--------------------------------------------------------------------------
        | LinkedIn Insight Tag (v32.0.0)
        |--------------------------------------------------------------------------
        |
        | LinkedIn Insight Tag and Conversions API configuration.
        | Essential for B2B SaaS analytics — tracks conversions from
        | LinkedIn Ads campaigns and enables remarketing audiences.
        |
        | The Insight Tag provides page-level tracking (client-side).
        | The Conversions API provides server-side event tracking for
        | attributed conversions with higher accuracy.
        |
        */
        'linkedin' => [
            'enabled' => env('ANALYTICS_LINKEDIN_ENABLED', false),
            'partner_id' => env('ANALYTICS_LINKEDIN_PARTNER_ID', ''),
            'conversion_id' => env('ANALYTICS_LINKEDIN_CONVERSION_ID', ''),
            'access_token' => env('ANALYTICS_LINKEDIN_ACCESS_TOKEN', ''),
            'api_version' => env('ANALYTICS_LINKEDIN_API_VERSION', '202401'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Provider Dispatch Telemetry (v32.0.0)
        |--------------------------------------------------------------------------
        |
        | Real-time dispatch monitoring per provider. Tracks success/failure
        | counts, latency, error rates, and top events. Used by dashboards
        | and health monitoring services.
        |
        | Set 'enabled' to false in production to save cache overhead.
        |
        */
        'telemetry' => [
            'enabled' => env('ANALYTICS_TELEMETRY_ENABLED', false),
            'cache_ttl' => (int) env('ANALYTICS_TELEMETRY_CACHE_TTL', 300), // 5 minutes
            'high_volume_threshold' => (int) env('ANALYTICS_TELEMETRY_HIGH_VOLUME', 10000),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | User Engagement Scoring (v34.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Composite user engagement scoring (0–100) based on five weighted signals:
        | frequency, recency, breadth, lifecycle progress, and revenue contribution.
        | Used for PLG segmentation, churn prediction, and activation analysis.
        |
        | Inspired by Amplitude Engage, Mixpanel User Score, Pendo Engagement Score.
        |
        */
        'engagement_scoring' => [
            'enabled' => env('ANALYTICS_ENGAGEMENT_SCORING_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_ENGAGEMENT_SCORING_CACHE_TTL', 3600), // 1 hour
            'recency_half_life' => (int) env('ANALYTICS_ENGAGEMENT_SCORING_RECENCY_HALF_LIFE', 604800), // 7 days
            'max_events_window' => (int) env('ANALYTICS_ENGAGEMENT_SCORING_EVENTS_WINDOW', 90), // 90 days
            'weights' => [
                'frequency' => (float) env('ANALYTICS_ENGAGEMENT_SCORING_WEIGHT_FREQUENCY', 0.30),
                'recency' => (float) env('ANALYTICS_ENGAGEMENT_SCORING_WEIGHT_RECENCY', 0.20),
                'breadth' => (float) env('ANALYTICS_ENGAGEMENT_SCORING_WEIGHT_BREADTH', 0.20),
                'lifecycle' => (float) env('ANALYTICS_ENGAGEMENT_SCORING_WEIGHT_LIFECYCLE', 0.15),
                'revenue' => (float) env('ANALYTICS_ENGAGEMENT_SCORING_WEIGHT_REVENUE', 0.15),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Cross-Provider Identity Sync (v35.0.0)
        |--------------------------------------------------------------------------
        |
        | When a user is identified, syncs their identity and traits across all
        | 10 analytics providers. Supports GA4, Meta CAPI, PostHog, Mixpanel,
        | Amplitude, TikTok, LinkedIn, Plausible, GTM, and Webhook.
        |
        | Set 'cross_provider_enabled' to false to disable cross-provider sync
        | (identity will only be sent to the generic identify event).
        |
        | Use 'provider_sync' to selectively enable/disable per-provider sync.
        |
        */
        'cross_provider_identity' => [
            'enabled' => env('ANALYTICS_CROSS_PROVIDER_IDENTITY_ENABLED', true),
            'provider_sync' => [
                'ga4' => env('ANALYTICS_IDENTITY_SYNC_GA4', true),
                'meta' => env('ANALYTICS_IDENTITY_SYNC_META', true),
                'posthog' => env('ANALYTICS_IDENTITY_SYNC_POSTHOG', true),
                'mixpanel' => env('ANALYTICS_IDENTITY_SYNC_MIXPANEL', true),
                'amplitude' => env('ANALYTICS_IDENTITY_SYNC_AMPLITUDE', true),
                'tiktok' => env('ANALYTICS_IDENTITY_SYNC_TIKTOK', true),
                'linkedin' => env('ANALYTICS_IDENTITY_SYNC_LINKEDIN', true),
                'plausible' => env('ANALYTICS_IDENTITY_SYNC_PLAUSIBLE', true),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Ingestion Pipeline (v36.0.0)
        |--------------------------------------------------------------------------
        |
        | Centralized event ingestion pipeline — the single entry point for all
        | incoming analytics events regardless of source (API, server-side, webhook,
        | replay, batch, edge proxy).
        |
        | Orchestrates validation, deduplication, enrichment, cost estimation,
        | dispatch, and post-dispatch metrics for every event.
        |
        */
        'ingestion' => [
            'enabled' => env('ANALYTICS_INGESTION_ENABLED', true),
            'max_event_name_length' => (int) env('ANALYTICS_INGESTION_MAX_NAME_LENGTH', 100),
            'max_param_count' => (int) env('ANALYTICS_INGESTION_MAX_PARAMS', 100),
            'max_payload_size' => (int) env('ANALYTICS_INGESTION_MAX_PAYLOAD', 65536), // 64 KB
            'timeout_ms' => (int) env('ANALYTICS_INGESTION_TIMEOUT', 5000),
            'track_latency' => env('ANALYTICS_INGESTION_TRACK_LATENCY', true),
            'cache_prefix' => env('ANALYTICS_INGESTION_CACHE_PREFIX', 'zb_ingestion_'),
            'cache_ttl' => (int) env('ANALYTICS_INGESTION_CACHE_TTL', 300), // 5 minutes
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Cost Allocation (v36.0.0)
        |--------------------------------------------------------------------------
        |
        | Per-provider dispatch cost tracking and allocation.
        | Enables chargeback analytics, budget enforcement, and cost optimization.
        |
        | Cost weights are relative units (not monetary). Set 'enforce_budget'
        | to true to stop dispatching when the daily budget limit is reached.
        |
        */
        'cost_allocation' => [
            'enabled' => env('ANALYTICS_COST_ALLOCATION_ENABLED', true),
            'cache_prefix' => env('ANALYTICS_COST_CACHE_PREFIX', 'zb_cost_'),
            'cache_ttl' => (int) env('ANALYTICS_COST_CACHE_TTL', 86400), // 24 hours
            'budget_limit' => (float) env('ANALYTICS_COST_BUDGET_LIMIT', 0.0), // 0 = unlimited
            'enforce_budget' => env('ANALYTICS_COST_ENFORCE_BUDGET', false),
            'cost_weights' => [
                'ga4' => (float) env('ANALYTICS_COST_GA4', 0.2),
                'gtm' => (float) env('ANALYTICS_COST_GTM', 0.1),
                'meta' => (float) env('ANALYTICS_COST_META', 0.3),
                'plausible' => (float) env('ANALYTICS_COST_PLAUSIBLE', 0.15),
                'posthog' => (float) env('ANALYTICS_COST_POSTHOG', 0.5),
                'mixpanel' => (float) env('ANALYTICS_COST_MIXPANEL', 0.45),
                'amplitude' => (float) env('ANALYTICS_COST_AMPLITUDE', 0.5),
                'webhook' => (float) env('ANALYTICS_COST_WEBHOOK', 0.1),
                'tiktok' => (float) env('ANALYTICS_COST_TIKTOK', 0.3),
                'linkedin' => (float) env('ANALYTICS_COST_LINKEDIN', 0.25),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Analytics Command Scheduler (v36.0.0)
        |--------------------------------------------------------------------------
        |
        | Config-driven scheduling of analytics admin commands.
        | Supports hourly, daily, weekly, and monthly schedules with cooldown
        | tracking and execution logging.
        |
        | Set 'enabled' to true and use 'php artisan zb:analytics:ingestion --scheduler'
        | to view status, or '--execute-due' to run all due tasks.
        |
        */
        'scheduler' => [
            'enabled' => env('ANALYTICS_SCHEDULER_ENABLED', false),
            'cache_prefix' => env('ANALYTICS_SCHEDULER_CACHE_PREFIX', 'zb_scheduler_'),
            'cache_ttl' => (int) env('ANALYTICS_SCHEDULER_CACHE_TTL', 2592000), // 30 days
            'tasks' => [
                // 'custom_task' => [
                //     'command' => 'zb:analytics:export',
                //     'frequency' => 'daily', // hourly|daily|weekly|monthly
                //     'description' => 'My custom scheduled task',
                //     'enabled' => true,
                // ],
            ],
            'override_defaults' => env('ANALYTICS_SCHEDULER_OVERRIDE_DEFAULTS', false),
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Router — Provider-Aware Destination Routing (v37.0.0)
        |--------------------------------------------------------------------------
        |
        | Segment/RudderStack-style destination filtering. Determines which
        | analytics providers should receive a given event based on configurable
        | routing rules:
        |
        | - category_routes: Route entire event categories to specific providers
        |   e.g., only send ecommerce events to GA4 + Meta Pixel
        | - pattern_rules: Glob/regex patterns for event name matching
        | - priority_routes: Map priority levels to provider subsets
        | - deny_list: Hard-block specific events from specific providers
        | - allow_list: Restrict specific events to only these providers
        | - cost_optimized: Automatically exclude expensive providers for low-priority events
        | - default_providers: Fallback when no rules match (null = all enabled)
        |
        | The router is consulted before dispatch. Events routed to an empty
        | provider list are silently dropped (by design for cost control).
        |
        */
        'event_router' => [
            'enabled' => env('ANALYTICS_EVENT_ROUTER_ENABLED', false),
            'cache_ttl' => (int) env('ANALYTICS_EVENT_ROUTER_CACHE_TTL', 300), // 5 minutes

            // Category-based routing: category => providers
            'category_routes' => [
                // 'ecommerce' => ['ga4', 'meta_pixel', 'posthog'],
                // 'saas' => ['ga4', 'posthog', 'mixpanel'],
                // 'engagement' => ['ga4', 'posthog'],
            ],

            // Pattern-based routing: glob or regex patterns
            'pattern_rules' => [
                // ['pattern' => 'scroll_*', 'providers' => ['ga4'], 'type' => 'glob'],
                // ['pattern' => '/^ab_/', 'providers' => ['posthog', 'mixpanel'], 'type' => 'regex'],
            ],

            // Priority-based routing: priority => providers
            'priority_routes' => [
                // 'critical' => ['ga4', 'meta_pixel', 'posthog', 'webhook'],
                // 'normal' => ['ga4', 'posthog'],
                // 'low' => ['ga4'],
                // 'background' => [],
            ],

            // Cost optimization: exclude expensive providers for low-priority events
            'cost_optimized' => env('ANALYTICS_EVENT_ROUTER_COST_OPTIMIZED', false),
            'cost_threshold' => (float) env('ANALYTICS_EVENT_ROUTER_COST_THRESHOLD', 0.5),

            // Deny list: event_name => providers to block
            'deny_list' => [
                // 'scroll_depth' => ['meta_pixel', 'tiktok'],
                // 'hover' => ['posthog'],
            ],

            // Allow list: event_name => providers to restrict to
            'allow_list' => [
                // 'purchase' => ['ga4', 'meta_pixel'],
            ],

            // Default providers when no rules match (null = all enabled providers)
            'default_providers' => null,
        ],

        /*
        |--------------------------------------------------------------------------
        | Workspace Analytics — Multi-Tenant KPI Rollups (v37.0.0)
        |--------------------------------------------------------------------------
        |
        | Per-workspace (tenant) analytics aggregation for multi-tenant SaaS
        | dashboards. Computes workspace-scoped metrics:
        | - DAU/WAU/MAU active users
        | - Event volume and top events
        | - Revenue totals (MRR, one-time)
        | - Funnel conversion rates (configurable per workspace)
        | - Engagement score (events per active user)
        |
        | All data is cache-backed. No database required.
        |
        */
        'workspace' => [
            'enabled' => env('ANALYTICS_WORKSPACE_ENABLED', false),
            'cache_prefix' => env('ANALYTICS_WORKSPACE_CACHE_PREFIX', 'zb_workspace_'),
            'cache_ttl' => (int) env('ANALYTICS_WORKSPACE_CACHE_TTL', 3600), // 1 hour
            'max_events_per_summary' => (int) env('ANALYTICS_WORKSPACE_MAX_EVENTS', 1000),

            // Events counted for engagement scoring
            'engagement_events' => [
                'page_view', 'click', 'scroll_depth', 'feature_used',
                'search', 'form_start', 'form_submit', 'share', 'feedback',
            ],

            // Workspace funnel definitions
            'funnels' => [
                'signup_funnel' => [
                    'name' => 'Signup Funnel',
                    'steps' => ['page_view', 'sign_up', 'email_verified', 'start_trial', 'subscribe'],
                    'weights' => [0.2, 0.3, 0.1, 0.2, 0.2],
                ],
                'activation_funnel' => [
                    'name' => 'Activation Funnel',
                    'steps' => ['sign_up', 'feature_used', 'onboarding_completed'],
                    'weights' => [0.3, 0.4, 0.3],
                ],
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | OpenTelemetry (OTLP) Export (v38.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Bridge analytics events to any OTLP-compatible collector 
        | (Grafana Tempo, Jaeger, Honeycomb, Datadog, etc.).
        |
        | When enabled, all dispatched analytics events are automatically
        | converted to OTLP ResourceSpans and POSTed to the configured endpoint.
        | Events are mapped as spans with:
        |   - name → span name
        |   - params → span attributes
        |   - clientId/userId → trace context
        |   - category → span kind
        |
        | Enable this if you use OpenTelemetry for observability and want
        | analytics events to appear alongside application traces.
        |
        */
        'otel' => [
            'enabled' => env('ANALYTICS_OTEL_ENABLED', false),
            'endpoint' => env('ANALYTICS_OTEL_ENDPOINT', 'http://localhost:4318/v1/traces'),
            'headers' => env('ANALYTICS_OTEL_HEADERS', ''), // e.g., "Authorization: Basic xxx, X-Custom: value"
            'timeout' => (int) env('ANALYTICS_OTEL_TIMEOUT', 5), // seconds
            'max_batch_size' => (int) env('ANALYTICS_OTEL_MAX_BATCH_SIZE', 100),
            'debug' => env('ANALYTICS_OTEL_DEBUG', false),
            'cache_prefix' => env('ANALYTICS_OTEL_CACHE_PREFIX', 'zb_otel_'),
            'cache_ttl' => (int) env('ANALYTICS_OTEL_CACHE_TTL', 300), // 5 minutes

            // OpenTelemetry resource attributes attached to all exported spans
            // These identify your application in the OTLP collector
            'resource_attributes' => [
                'service.name' => env('ANALYTICS_OTEL_SERVICE_NAME', 'zeroboiler-analytics'),
                'deployment.environment' => env('ANALYTICS_OTEL_ENVIRONMENT', env('APP_ENV', 'production')),
                // Add custom resource attributes:
                // 'service.namespace' => 'analytics',
                // 'telemetry.sdk.language' => 'php',
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Differential Privacy (v42.0.0)
        |--------------------------------------------------------------------------
        |
        | Privacy-safe analytics aggregation using the Laplace mechanism.
        | Adds calibrated noise to aggregate metrics so individual user
        | contributions cannot be inferred from published results.
        |
        | Follows the Google RAPPOR / Apple differential privacy model.
        | Recommended epsilon values:
        |   ε = 1.0 → Strong privacy (public dashboards)
        |   ε = 0.5 → Very strong privacy (internal only)
        |   ε = 5.0 → Weak privacy (compliance theater)
        |
        */
        'differential_privacy' => [
            'enabled' => env('ANALYTICS_DIFFERENTIAL_PRIVACY_ENABLED', false),
            'epsilon' => (float) env('ANALYTICS_DIFFERENTIAL_PRIVACY_EPSILON', 1.0),
            'default_delta' => (float) env('ANALYTICS_DIFFERENTIAL_PRIVACY_DELTA', 1.0),
            'cache_ttl' => (int) env('ANALYTICS_DIFFERENTIAL_PRIVACY_CACHE_TTL', 300), // 5 minutes
            'cache_prefix' => env('ANALYTICS_DIFFERENTIAL_PRIVACY_CACHE_PREFIX', 'zb_dp_'),
        ],

        /*
        |--------------------------------------------------------------------------
        | Event TTL & Auto-Expiry (v43.0.0)
        |--------------------------------------------------------------------------
        |
        | Manages event lifecycle with configurable time-to-live rules.
        | Events exceeding their TTL are flagged and optionally dropped before
        | dispatch. Configure default TTL and per-event/category overrides.
        |
        | Set 'drop_expired' to true in production to automatically discard
        | stale events (e.g., delayed delivery from queue failures).
        |
        */
        'event_ttl' => [
            'enabled' => env('ANALYTICS_EVENT_TTL_ENABLED', true),
            'default_ttl_seconds' => (int) env('ANALYTICS_EVENT_TTL_DEFAULT', 86400), // 24 hours
            'drop_expired' => env('ANALYTICS_EVENT_TTL_DROP', false),
            'event_overrides' => [
                // 'page_view' => 300,           // 5 minutes
                // 'scroll_depth' => 600,       // 10 minutes
                // 'purchase' => 2592000,       // 30 days (important events)
            ],
            'category_overrides' => [
                // 'ecommerce' => 604800,        // 7 days
                // 'engagement' => 3600,        // 1 hour
                // 'security' => 2592000,        // 30 days
            ],
            'metrics_ttl' => (int) env('ANALYTICS_EVENT_TTL_METRICS_TTL', 3600), // 1 hour
        ],

        /*
        |--------------------------------------------------------------------------
        | Referral & Viral Loop Tracking (v43.0.0)
        |--------------------------------------------------------------------------
        |
        | Tracks referral code usage, invite link clicks, and viral loop metrics.
        | Computes K-factor (viral coefficient), referral conversion rates,
        | and provides top-referrer leaderboards for growth analytics.
        |
        | Configure code length and attribution window TTL.
        |
        */
        'referral' => [
            'enabled' => env('ANALYTICS_REFERRAL_ENABLED', false),
            'code_length' => (int) env('ANALYTICS_REFERRAL_CODE_LENGTH', 8),
            'attribution_ttl' => (int) env('ANALYTICS_REFERRAL_ATTRIBUTION_TTL', 2592000), // 30 days
            'metrics_ttl' => (int) env('ANALYTICS_REFERRAL_METRICS_TTL', 3600), // 1 hour
        ],

        /*
        |--------------------------------------------------------------------------
        | Traffic Spike Shield (v43.0.0)
        |--------------------------------------------------------------------------
        |
        | Adaptive event throttling during traffic bursts. Detects sudden traffic
        | spikes using a sliding window algorithm and applies probabilistic
        | throttling to prevent overwhelming analytics providers and queues.
        |
        | Critical events are never throttled. Set 'enabled' to false to
        | disable automatic throttling.
        |
        */
        'spike_shield' => [
            'enabled' => env('ANALYTICS_SPIKE_SHIELD_ENABLED', false),
            'normal_threshold' => (int) env('ANALYTICS_SPIKE_SHIELD_NORMAL', 1000), // events per window
            'spike_threshold' => (int) env('ANALYTICS_SPIKE_SHIELD_SPIKE', 5000), // events per window
            'window_size' => (int) env('ANALYTICS_SPIKE_SHIELD_WINDOW', 60), // seconds
            'cooldown' => (int) env('ANALYTICS_SPIKE_SHIELD_COOLDOWN', 30), // seconds
            'throttle_ratio' => (float) env('ANALYTICS_SPIKE_SHIELD_RATIO', 0.1), // fraction allowed
            'event_overrides' => [
                // 'page_view' => 10000, // higher threshold for high-volume events
            ],
            'metrics_ttl' => (int) env('ANALYTICS_SPIKE_SHIELD_METRICS_TTL', 3600), // 1 hour
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Replay Simulator (v43.0.0)
        |--------------------------------------------------------------------------
        |
        | Synthetic event generation for load testing and capacity planning.
        | Generates realistic events following configurable frequency distributions.
        | Used by the 'zb:analytics:simulate' artisan command.
        |
        | WARNING: Only enable in development/staging. High-volume simulation
        | can impact production analytics providers.
        |
        */
        'simulator' => [
            'enabled' => env('ANALYTICS_SIMULATOR_ENABLED', false),
            'batch_size' => (int) env('ANALYTICS_SIMULATOR_BATCH', 100),
            'rate_limit' => (int) env('ANALYTICS_SIMULATOR_RATE_LIMIT', 50), // events per second
            'dry_run' => env('ANALYTICS_SIMULATOR_DRY_RUN', true),
            'max_events' => (int) env('ANALYTICS_SIMULATOR_MAX_EVENTS', 100000),
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Versioning & Deprecation (v44.0.0)
        |--------------------------------------------------------------------------
        |
        | Config-driven event lifecycle management. Mark events as deprecated,
        | beta, or experimental. When deprecated events are dispatched, warnings
        | are logged and replacements are suggested.
        |
        | Set 'block_deprecated' to true to prevent dispatching deprecated events
        | that have no replacement. Set 'auto_redirect' to true to silently
        | redirect deprecated events to their replacement.
        |
        | Registry format:
        |   'event_name' => [
        |       'since' => '1.0.0',           // Version when event was introduced
        |       'deprecated' => true,          // Whether event is deprecated
        |       'deprecated_in' => '44.0.0',   // Version when deprecated
        |       'replaced_by' => 'new_event',  // Replacement event name (null = no replacement)
        |       'stability' => 'deprecated',   // stable, beta, experimental, deprecated
        |       'message' => 'Use new_event instead.',
        |   ],
        |
        */
        'event_versioning' => [
            'enabled' => env('ANALYTICS_EVENT_VERSIONING_ENABLED', true),
            'block_deprecated' => env('ANALYTICS_EVENT_VERSIONING_BLOCK_DEPRECATED', false),
            'auto_redirect' => env('ANALYTICS_EVENT_VERSIONING_AUTO_REDIRECT', false),
            'cache_prefix' => env('ANALYTICS_EVENT_VERSIONING_CACHE_PREFIX', 'zb_deprecation_'),
            'warning_ttl' => (int) env('ANALYTICS_EVENT_VERSIONING_WARNING_TTL', 3600), // 1 hour
            'log_channel' => env('ANALYTICS_EVENT_VERSIONING_LOG_CHANNEL', 'daily'),
            'registry' => [
                // Example entries — add your own deprecated events here:
                // 'old_event_name' => [
                //     'since' => '1.0.0',
                //     'deprecated' => true,
                //     'deprecated_in' => '44.0.0',
                //     'replaced_by' => 'new_event_name',
                //     'stability' => 'deprecated',
                //     'message' => 'Use new_event_name instead.',
                // ],
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Sampling Strategy (v45.0.0)
        |--------------------------------------------------------------------------
        |
        | Config-driven event sampling for high-traffic applications. Controls which
        | events are dispatched (sampled in) vs dropped (sampled out) using
        | configurable strategies per event name, category, or as a global default.
        |
        | Three strategies available:
        | - 'uniform': Random sampling (each event independently sampled)
        | - 'deterministic': Hash-based (same event name always gets same decision)
        | - 'adaptive': Volume-aware (auto-reduces rate when throughput is high)
        |
        | Event-specific overrides take precedence over category overrides,
        | which take precedence over the global rate. Critical-priority events
        | are never sampled out regardless of rate.
        |
        */
        'sampling' => [
            'enabled' => env('ANALYTICS_SAMPLING_ENABLED', false),
            'global_rate' => (float) env('ANALYTICS_SAMPLING_GLOBAL_RATE', 1.0),
            'strategy' => env('ANALYTICS_SAMPLING_STRATEGY', 'deterministic'),
            'event_overrides' => [
                // 'scroll_depth' => 0.1,   // Sample 10% of scroll depth events
                // 'page_view' => 0.5,       // Sample 50% of page views
            ],
            'category_overrides' => [
                // 'engagement' => 0.5,       // Sample 50% of all engagement events
                // 'infrastructure' => 1.0,  // Never sample infrastructure events
            ],
            'cache_prefix' => env('ANALYTICS_SAMPLING_CACHE_PREFIX', 'zb_sampling_'),
            'metrics_ttl' => (int) env('ANALYTICS_SAMPLING_METRICS_TTL', 3600),
            'adaptive_window' => (int) env('ANALYTICS_SAMPLING_ADAPTIVE_WINDOW', 60),
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Flow Analysis (v46.0.0)
        |--------------------------------------------------------------------------
        |
        | Real-time user event flow/journey analysis. Tracks event sequences
        | per user, identifies common paths, drop-off points, and conversion
        | funnels. Inspired by Amplitude Pathfinder, Mixpanel Journeys.
        |
        | Enable this to get flow analytics via the `zb:analytics:flow` command
        | and programmatic access via EventFlowAnalysisService.
        |
        */
        'event_flow' => [
            'enabled' => env('ANALYTICS_EVENT_FLOW_ENABLED', false),
            'max_path_length' => (int) env('ANALYTICS_EVENT_FLOW_MAX_PATH', 50),
            'path_ttl' => (int) env('ANALYTICS_EVENT_FLOW_PATH_TTL', 86400), // 24 hours
            'top_paths_limit' => (int) env('ANALYTICS_EVENT_FLOW_TOP_PATHS', 25),
            'cache_prefix' => env('ANALYTICS_EVENT_FLOW_CACHE_PREFIX', 'zb_flow_'),
            'metrics_ttl' => (int) env('ANALYTICS_EVENT_FLOW_METRICS_TTL', 3600), // 1 hour
        ],

        /*
        |--------------------------------------------------------------------------
        | Data Quality Firewall (v46.0.0)
        |--------------------------------------------------------------------------
        |
        | Pre-dispatch data quality scoring and auto-quarantine. Evaluates every
        | event before dispatch using configurable quality rules:
        | - Completeness: required parameters present
        | - Format: naming conventions and type checks
        | - Velocity: per-event rate limiting
        | - Consistency: parameter value validation
        |
        | Events scoring below the quarantine threshold are quarantined.
        | Events scoring below the drop threshold are dropped.
        | Set 'enforce_quarantine' and 'enforce_drop' to true to activate.
        |
        */
        'quality_firewall' => [
            'enabled' => env('ANALYTICS_QUALITY_FIREWALL_ENABLED', false),
            'quarantine_threshold' => (float) env('ANALYTICS_QUALITY_QUARANTINE_THRESHOLD', 0.5),
            'drop_threshold' => (float) env('ANALYTICS_QUALITY_DROP_THRESHOLD', 0.2),
            'enforce_quarantine' => env('ANALYTICS_QUALITY_ENFORCE_QUARANTINE', false),
            'enforce_drop' => env('ANALYTICS_QUALITY_ENFORCE_DROP', false),
            'cache_prefix' => env('ANALYTICS_QUALITY_CACHE_PREFIX', 'zb_qf_'),
            'metrics_ttl' => (int) env('ANALYTICS_QUALITY_METRICS_TTL', 3600),
            'velocity_window' => (int) env('ANALYTICS_QUALITY_VELOCITY_WINDOW', 60),
            'max_events_per_window' => (int) env('ANALYTICS_QUALITY_MAX_EVENTS_WINDOW', 100),
            'required_global_params' => [],
            'event_required_params' => [
                // 'purchase' => ['transaction_id', 'value', 'currency'],
                // 'sign_up' => ['method'],
            ],
            'reserved_prefixes' => ['_ga_', '_fb_', '_meta_', '_sentry_'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Provider Event Compatibility Matrix (v46.0.0)
        |--------------------------------------------------------------------------
        |
        | Comprehensive provider gap analysis. Analyzes which events from the
        | EventCatalog are supported by which providers and identifies gaps.
        | Provides per-provider coverage percentage, readiness scores,
        | and prioritized gap closure recommendations.
        |
        | Used by the `zb:analytics:flow --mode=matrix` command and
        | ProviderEventCompatibilityMatrix service.
        |
        */
        'provider_matrix' => [
            'enabled' => env('ANALYTICS_PROVIDER_MATRIX_ENABLED', true),
            'cache_prefix' => env('ANALYTICS_PROVIDER_MATRIX_CACHE_PREFIX', 'zb_pem_'),
            'cache_ttl' => (int) env('ANALYTICS_PROVIDER_MATRIX_CACHE_TTL', 3600), // 1 hour
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Fraud Detection (v47.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Detects suspicious analytics event patterns including volume anomalies,
        | velocity abuse, duplicate injection, parameter injection (XSS probes),
        | and spoofed identity (multiple fingerprints per client ID).
        |
        | Each detection signal contributes to a composite fraud score (0.0–1.0).
        | Events exceeding quarantine_threshold are flagged but not dropped.
        | Events exceeding block_threshold are dropped silently.
        |
        | Critical events (purchase, subscription_created, payment_succeeded)
        | are always elevated to block if quarantined, for safety.
        |
        | Inspired by Segment's Sentry integration, PostHog's spam detection,
        | and Cloudflare bot management patterns.
        |
        */
        'fraud_detection' => [
            'enabled' => env('ANALYTICS_FRAUD_DETECTION_ENABLED', false),
            'cache_prefix' => env('ANALYTICS_FRAUD_CACHE_PREFIX', 'zb_fraud_'),
            'metrics_ttl' => (int) env('ANALYTICS_FRAUD_METRICS_TTL', 3600), // 1 hour
            'velocity_window' => (int) env('ANALYTICS_FRAUD_VELOCITY_WINDOW', 60), // seconds
            'max_events_per_window' => (int) env('ANALYTICS_FRAUD_MAX_EVENTS_WINDOW', 200),
            'quarantine_threshold' => (float) env('ANALYTICS_FRAUD_QUARANTINE_THRESHOLD', 0.6),
            'block_threshold' => (float) env('ANALYTICS_FRAUD_BLOCK_THRESHOLD', 0.85),
            'burst_multiplier' => (float) env('ANALYTICS_FRAUD_BURST_MULTIPLIER', 5.0),
            'burst_window' => (int) env('ANALYTICS_FRAUD_BURST_WINDOW', 10), // seconds
            'duplicate_window' => (int) env('ANALYTICS_FRAUD_DUPLICATE_WINDOW', 5), // seconds
            'max_duplicate_hash_per_window' => (int) env('ANALYTICS_FRAUD_MAX_DUPLICATES', 10),
            'spoofed_identity_window' => (int) env('ANALYTICS_FRAUD_SPOOFED_WINDOW', 3600), // 1 hour
            'max_fingerprints_per_client' => (int) env('ANALYTICS_FRAUD_MAX_FINGERPRINTS', 5),
            'suspicious_patterns' => ['<script', 'javascript:', 'data:', 'onerror='],
            'critical_events' => ['purchase', 'subscription_created', 'payment_succeeded'],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Product-Market Fit Scoring (v47.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Computes a composite PMF score (0–100) using industry-standard signals:
        | - Sean Ellis Test (40%+ = strong PMF)
        | - Activation Rate (% completing onboarding)
        | - Retention Curve (D7/D30 sustainability)
        | - Feature Engagement Depth (features adopted per user)
        | - Organic Growth Rate (referral/virality)
        | - Revenue Stickiness (Net Revenue Retention)
        |
        | Configurable weights must sum to 1.0. The default weights follow
        | the Sean Ellis framework hierarchy.
        |
        | Grading scale:
        | - Exceptional (85–100): Product-market fit achieved
        | - Strong (70–84): Close to product-market fit
        | - Moderate (50–69): Promising but needs improvement
        | - Weak (30–49): Significant pivot needed
        | - None (0–29): Fundamental reassessment required
        |
        | Inspired by Superhuman's Sean Ellis framework, Amplitude PMF analysis,
        | and OpenView retention-based scoring.
        |
        */
        'pmf_scoring' => [
            'enabled' => env('ANALYTICS_PMF_SCORING_ENABLED', false),
            'cache_prefix' => env('ANALYTICS_PMF_CACHE_PREFIX', 'zb_pmf_'),
            'cache_ttl' => (int) env('ANALYTICS_PMF_CACHE_TTL', 3600), // 1 hour
            'ellis_threshold' => (float) env('ANALYTICS_PMF_ELLIS_THRESHOLD', 0.40),
            'weights' => [
                'ellis_test' => (float) env('ANALYTICS_PMF_W_ELLIS', 0.25),
                'activation_rate' => (float) env('ANALYTICS_PMF_W_ACTIVATION', 0.20),
                'retention' => (float) env('ANALYTICS_PMF_W_RETENTION', 0.20),
                'engagement' => (float) env('ANALYTICS_PMF_W_ENGAGEMENT', 0.15),
                'organic_growth' => (float) env('ANALYTICS_PMF_W_ORGANIC', 0.10),
                'revenue_stickiness' => (float) env('ANALYTICS_PMF_W_REVENUE', 0.10),
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Correlation Engine (v48.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Detects statistically significant causal relationships between events
        | using temporal proximity analysis. Identifies event pairs that frequently
        | co-occur within configurable time windows and computes correlation
        | coefficients for funnel analysis, feature adoption sequencing, and
        | anomaly root cause investigation.
        |
        | Inspired by Amplitude Pathfinder, Mixpanel Journeys, and Datadog.
        |
        */
        'correlation_engine' => [
            'enabled' => env('ANALYTICS_CORRELATION_ENGINE_ENABLED', false),
            'cache_prefix' => env('ANALYTICS_CORRELATION_CACHE_PREFIX', 'zb_corr_'),
            'cache_ttl' => (int) env('ANALYTICS_CORRELATION_CACHE_TTL', 7200), // 2 hours
            'time_window_seconds' => (int) env('ANALYTICS_CORRELATION_TIME_WINDOW', 300), // 5 minutes
            'min_cooccurrence' => (int) env('ANALYTICS_CORRELATION_MIN_COOCCURRENCE', 3),
            'min_correlation_score' => (float) env('ANALYTICS_CORRELATION_MIN_SCORE', 0.3),
            'decay_rate' => (float) env('ANALYTICS_CORRELATION_DECAY_RATE', 0.95),
            'max_correlations_per_event' => (int) env('ANALYTICS_CORRELATION_MAX_PER_EVENT', 20),
            'max_event_pair_cache_size' => (int) env('ANALYTICS_CORRELATION_MAX_PAIRS', 10000),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Anomaly Root Cause Analyzer (v48.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Traces analytics anomalies back to their most likely originating events
        | using correlation engine data. Categorizes root causes as infrastructure,
        | behavioral, technical, or data quality issues, and generates actionable
        | remediation suggestions.
        |
        | Inspired by Datadog AIOps Root Cause Analysis and Honeycomb BubbleUp.
        |
        */
        'root_cause_analyzer' => [
            'enabled' => env('ANALYTICS_ROOT_CAUSE_ENABLED', false),
            'cache_prefix' => env('ANALYTICS_ROOT_CAUSE_CACHE_PREFIX', 'zb_rca_'),
            'cache_ttl' => (int) env('ANALYTICS_ROOT_CAUSE_CACHE_TTL', 1800), // 30 minutes
            'max_root_causes' => (int) env('ANALYTICS_ROOT_CAUSE_MAX', 5),
            'lookback_window_seconds' => (int) env('ANALYTICS_ROOT_CAUSE_LOOKBACK', 3600), // 1 hour
            'min_confidence_score' => (float) env('ANALYTICS_ROOT_CAUSE_MIN_CONFIDENCE', 0.2),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Self-Healing (v48.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Automatic recovery actions for common analytics pipeline failures.
        | Actions include cache warming, provider health reset, DLQ flush,
        | pipeline reset, stale data cleanup, and queue health checks.
        |
        | Inspired by AWS Lambda auto-healing and HashiCorp Consul health checks.
        |
        */
        'self_healing' => [
            'enabled' => env('ANALYTICS_SELF_HEALING_ENABLED', false),
            'auto_heal_enabled' => env('ANALYTICS_SELF_HEALING_AUTO_HEAL_ENABLED', false),
            'auto_heal_actions' => [], // e.g., ['warm_cache', 'reset_provider_health']
            'cache_prefix' => env('ANALYTICS_SELF_HEALING_CACHE_PREFIX', 'zb_heal_'),
            'history_ttl' => (int) env('ANALYTICS_SELF_HEALING_HISTORY_TTL', 86400), // 24 hours
            'max_history_entries' => (int) env('ANALYTICS_SELF_HEALING_MAX_HISTORY', 200),
            'healing_cooldown_seconds' => (int) env('ANALYTICS_SELF_HEALING_COOLDOWN', 300), // 5 minutes
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Lineage Tracker (v49.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Tracks the complete lifecycle path of analytics events through the pipeline:
        | source origin → enrichment stages → provider dispatch → delivery confirmation.
        | Each tracked event gets a lineage ID linking all stages together for
        | end-to-end tracing, debugging, and GDPR compliance reporting.
        |
        | Inspired by OpenTelemetry distributed tracing and Datadog pipeline visibility.
        |
        */
        'event_lineage' => [
            'enabled' => env('ANALYTICS_EVENT_LINEAGE_ENABLED', true),
            'cache_prefix' => env('ANALYTICS_EVENT_LINEAGE_CACHE_PREFIX', 'zb_lineage_'),
            'retention_ttl' => (int) env('ANALYTICS_EVENT_LINEAGE_RETENTION_TTL', 604800), // 7 days
            'max_entries' => (int) env('ANALYTICS_EVENT_LINEAGE_MAX_ENTRIES', 10000),
            'auto_track' => env('ANALYTICS_EVENT_LINEAGE_AUTO_TRACK', false),
            'track_enrichment' => env('ANALYTICS_EVENT_LINEAGE_TRACK_ENRICHMENT', true),
            'track_providers' => env('ANALYTICS_EVENT_LINEAGE_TRACK_PROVIDERS', true),
            'skip_stages' => [], // e.g., ['timestamp'] to skip high-frequency no-op stages
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Pre-computed Analytics Rollups (v52.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Maintains materialized time-series aggregations in the cache layer so
        | dashboard widgets and API endpoints can query aggregate metrics without
        | scanning raw event data. Rollups are computed at configurable granularities
        | (hourly, daily, weekly) and include event counts by name, category, and
        | provider, plus unique user/client tracking with bounded sets.
        |
        | Inspired by Materialized Views in data warehousing, ClickHouse rollup
        | tables, and Mixpanel/Amplitude pre-aggregated dashboard metrics.
        |
        */
        'rollup' => [
            'enabled' => env('ANALYTICS_ROLLUP_ENABLED', true),
            'granularities' => ['hourly', 'daily', 'weekly'], // active rollup granularities
            'cache_prefix' => env('ANALYTICS_ROLLUP_CACHE_PREFIX', 'zb_rollup_'),
            'hourly_ttl' => (int) env('ANALYTICS_ROLLUP_HOURLY_TTL', 7200), // 2 hours
            'daily_ttl' => (int) env('ANALYTICS_ROLLUP_DAILY_TTL', 604800), // 7 days
            'weekly_ttl' => (int) env('ANALYTICS_ROLLUP_WEEKLY_TTL', 2592000), // 30 days
            'max_top_events' => (int) env('ANALYTICS_ROLLUP_MAX_TOP_EVENTS', 20),
            'max_unique_trackers' => (int) env('ANALYTICS_ROLLUP_MAX_UNIQUE_TRACKERS', 10000),
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Payload Encryption (v53.0.0)
        |--------------------------------------------------------------------------
        |
        | Field-level AES-256-CBC encryption for sensitive analytics event parameters.
        | Encrypts individual fields before dispatch to analytics providers while
        | preserving the ability to decrypt for internal reporting and audit.
        |
        | Unlike PII sanitization (which hashes/removes) or anonymization (which
        | HMACs one-way), this service provides reversible encryption with key
        | rotation support.
        |
        | Global fields are encrypted across ALL events. Per-event rules add or
        | exclude fields for specific events. Use 'except:field_name' syntax to
        | exclude a global field from a specific event.
        |
        | Supports wildcard patterns: 'user_*' matches 'user_email', 'user_name', etc.
        |
        | Inspired by Segment's EncryptionMiddleware and mParticle's data encryption.
        |
        */
        'encryption' => [
            'enabled' => env('ANALYTICS_ENCRYPTION_ENABLED', false),
            'prefix' => env('ANALYTICS_ENCRYPTION_PREFIX', 'enc:v1:'),
            'global_fields' => [
                // Fields encrypted across ALL events
                // 'email',
                // 'phone',
                // 'ip_address',
                // 'user_*',
            ],
            'event_rules' => [
                // Per-event field rules
                // 'purchase' => ['credit_card', 'billing_address'],
                // 'sign_up' => ['except:ip_address'], // excludes ip_address from sign_up events
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Anomaly Detection & Automated Alerting (v54.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Real-time statistical anomaly detection for analytics event patterns.
        | Uses sliding-window baselines to detect rate spikes/drops, provider
        | failures, composition drift, and client count anomalies.
        |
        | Alerts fire through configured channels (log, webhook, email) with
        | configurable cooldown to prevent alert fatigue.
        |
        | Inspired by Datadog Monitor, Honeycomb Burn Rate, Amplitude Anomaly Detection.
        |
        */
        'anomaly_detection' => [
            'enabled' => env('ANALYTICS_ANOMALY_ENABLED', false),
            'window_seconds' => (int) env('ANALYTICS_ANOMALY_WINDOW', 300), // 5 minutes
            'baseline_windows' => (int) env('ANALYTICS_ANOMALY_BASELINE', 12), // 12 × 5min = 1 hour
            'sensitivity' => (float) env('ANALYTICS_ANOMALY_SENSITIVITY', 3.0), // standard deviations
            'alert_cooldown' => (int) env('ANALYTICS_ANOMALY_COOLDOWN', 900), // 15 minutes
            'min_events_threshold' => (int) env('ANALYTICS_ANOMALY_MIN_EVENTS', 10),
            'channels' => ['log'], // 'log', 'webhook', 'email'
            'webhook_url' => env('ANALYTICS_ANOMALY_WEBHOOK_URL'),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Multi-Provider Event Relay (v54.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Cross-provider event forwarding for events that should be sent to
        | secondary providers outside the default dispatch chain.
        |
        | Define per-event or category-level relay rules. Events matching the
        | rules are automatically forwarded to configured relay endpoints.
        |
        | Example:
        |   'relay' => [
        |       'enabled' => true,
        |       'providers' => [
        |           'custom_webhook' => [
        |               'enabled' => true,
        |               'url' => 'https://hooks.myapp.com/analytics',
        |               'headers' => ['Authorization' => 'Bearer xyz'],
        |               'format' => 'segment',
        |               'retry' => 2,
        |               'timeout' => 5,
        |           ],
        |       ],
        |       'rules' => [
        |           '*' => ['custom_webhook'],         // Relay ALL events
        |           'purchase' => ['custom_webhook'],   // Relay purchase events only
        |           'ecommerce:*' => ['custom_webhook'], // Relay all ecommerce events
        |       ],
        |       'exclude' => [
        |           'page_view', // Exclude page_view from relay
        |       ],
        |   ],
        |
        */
        'relay' => [
            'enabled' => env('ANALYTICS_RELAY_ENABLED', false),
            'providers' => [
                // Configure relay provider endpoints
            ],
            'rules' => [
                // Per-event and category-level relay rules
                // '*' => ['custom_webhook'],
            ],
            'exclude' => [
                // Event patterns to exclude from relay
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Export Formatting (v54.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Configuration for the AnalyticsExportFormatterService.
        | Controls default export format and column selection for CSV exports.
        |
        */
        'export' => [
            'default_format' => env('ANALYTICS_EXPORT_FORMAT', 'csv'), // csv, segment, bigquery, snowplow
            'csv_columns' => null, // null = all default columns; ['id', 'event_name', 'client_id', ...]
            'include_metadata' => env('ANALYTICS_EXPORT_METADATA', true),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Declarative Funnel Definitions (v58.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Config-driven funnel definitions with automatic step tracking.
        | Each funnel is an ordered list of steps. When the associated event
        | fires, the DeclarativeFunnelService automatically advances the
        | funnel state for the user/client.
        |
        | Steps:
        |   - name: Human-readable step name
        |   - event: Analytics event name that triggers this step
        |   - timeout: Optional per-step timeout in seconds
        |
        | completion_event: Optional event name that marks funnel as complete
        |   (useful when the last step event is shared across funnels).
        |
        | abandonment_timeout: Seconds of inactivity before marking funnel
        |   as abandoned. 0 = no abandonment tracking.
        |
        */
        'funnels' => [
            'enabled' => env('ANALYTICS_FUNNELS_ENABLED', true),
            'cache_prefix' => env('ANALYTICS_FUNNELS_CACHE_PREFIX', 'zb_funnel_'),
            'cache_ttl' => (int) env('ANALYTICS_FUNNELS_CACHE_TTL', 86400), // 24 hours
            'definitions' => [
                // Example: SaaS signup funnel
                // 'signup' => [
                //     'steps' => [
                //         ['name' => 'visit_landing', 'event' => 'page_view', 'timeout' => 0],
                //         ['name' => 'start_registration', 'event' => 'form_start'],
                //         ['name' => 'submit_registration', 'event' => 'sign_up'],
                //         ['name' => 'verify_email', 'event' => 'email_verified'],
                //     ],
                //     'abandonment_timeout' => 3600,
                // ],
                // Example: Purchase funnel
                // 'purchase' => [
                //     'steps' => [
                //         ['name' => 'view_product', 'event' => 'view_item'],
                //         ['name' => 'add_to_cart', 'event' => 'add_to_cart'],
                //         ['name' => 'checkout', 'event' => 'begin_checkout'],
                //         ['name' => 'pay', 'event' => 'purchase'],
                //     ],
                //     'completion_event' => 'purchase',
                // ],
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Privacy-Preserving Cookieless Collection (v58.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Server-side cookieless event collection using fingerprint-based
        | identifiers. Designed for strict GDPR environments where no cookies
        | can be set (e.g., before consent is granted).
        |
        | Uses SHA-256 hashed IP + User-Agent to create stable anonymous
        | identifiers without persistent storage on the client.
        |
        | Inspired by Plausible Analytics and Simple Analytics cookieless mode.
        |
        | Options:
        |   - hash_algorithm: Hash function (sha256, sha384, sha512)
        |   - salt: Optional salt for fingerprint hashing (rotate for privacy)
        |   - ip_anonymization: Zero last octet (IPv4) / last 48 bits (IPv6)
        |   - signals: Server signals used for fingerprint composition
        |
        */
        'privacy_collection' => [
            'enabled' => env('ANALYTICS_PRIVACY_COLLECTION_ENABLED', false),
            'hash_algorithm' => env('ANALYTICS_PRIVACY_HASH_ALGORITHM', 'sha256'),
            'salt' => env('ANALYTICS_PRIVACY_SALT'),
            'cache_ttl' => (int) env('ANALYTICS_PRIVACY_CACHE_TTL', 86400), // 24 hours
            'cache_prefix' => env('ANALYTICS_PRIVACY_CACHE_PREFIX', 'zb_privacy_'),
            'ip_anonymization' => env('ANALYTICS_PRIVACY_IP_ANONYMIZATION', true),
            'signals' => ['ip', 'user_agent', 'accept_language'],
            'max_entries' => (int) env('ANALYTICS_PRIVACY_MAX_ENTRIES', 100000),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | First-Value Detection (v61.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Detects and tracks "aha moment" milestones for each user. When a user
        | performs a key action for the first time (first search, first feature,
        | first integration), a dedicated 'first_value' event is fired.
        |
        | The detection is cache-backed and idempotent — each milestone fires
        | only once per user. Used for activation analytics and PMF scoring.
        |
        */
        'first_value' => [
            'enabled' => env('ANALYTICS_FIRST_VALUE_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_FIRST_VALUE_CACHE_TTL', 7776000), // 90 days
            'milestones' => [
                // Custom milestones override the built-in defaults.
                // Each milestone maps to a tracked event name.
                // 'my_milestone' => [
                //     'event' => 'custom_action',
                //     'label' => 'My Custom Milestone',
                //     'weight' => 2.0,
                //     'category' => 'custom',
                // ],
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Product-Market Fit Scoring (v61.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Computes a PMF score (0-100) based on aggregated analytics signals:
        | activation rate, week-2 retention, feature adoption depth, organic
        | growth rate, and an NPS proxy score.
        |
        | Weights control the relative contribution of each signal.
        | Thresholds define the grade boundaries (D through A+).
        |
        */
        'pmf' => [
            'enabled' => env('ANALYTICS_PMF_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_PMF_CACHE_TTL', 3600), // 1 hour
            'weights' => [
                'activation_rate' => (float) env('ANALYTICS_PMF_WEIGHT_ACTIVATION', 0.25),
                'retention_week2' => (float) env('ANALYTICS_PMF_WEIGHT_RETENTION', 0.25),
                'feature_depth' => (float) env('ANALYTICS_PMF_WEIGHT_FEATURE_DEPTH', 0.20),
                'organic_growth' => (float) env('ANALYTICS_PMF_WEIGHT_ORGANIC', 0.15),
                'nps_proxy' => (float) env('ANALYTICS_PMF_WEIGHT_NPS', 0.15),
            ],
            'thresholds' => [
                'very_early' => (float) env('ANALYTICS_PMF_THRESHOLD_VERY_EARLY', 25.0),
                'early' => (float) env('ANALYTICS_PMF_THRESHOLD_EARLY', 40.0),
                'strong' => (float) env('ANALYTICS_PMF_THRESHOLD_STRONG', 60.0),
                'excellent' => (float) env('ANALYTICS_PMF_THRESHOLD_EXCELLENT', 75.0),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Privacy Impact Assessment (v62.0.0)
        |--------------------------------------------------------------------------
        |
        | GDPR Article 35 Data Protection Impact Assessment (DPIA) service.
        | Automatically evaluates privacy risks for analytics processing activities
        | and generates structured DPIA reports for regulatory compliance.
        |
        | A DPIA is required when processing is likely to result in high risk
        | to the rights and freedoms of natural persons. This service helps
        | SaaS applications continuously monitor for triggering conditions.
        |
        | Options:
        |   - high_risk_categories: Event categories that auto-trigger DPIA requirements
        |   - assessment_frequency_days: How often assessments should be reviewed
        |   - cross_border_transfers: Jurisdictions where data is transferred
        |
        */
        'privacy_impact_assessment' => [
            'enabled' => env('ANALYTICS_PIA_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_PIA_CACHE_TTL', 86400), // 24 hours
            'auto_assess' => env('ANALYTICS_PIA_AUTO_ASSESS', true),
            'required_for_new_events' => env('ANALYTICS_PIA_REQUIRE_NEW_EVENTS', false),
            'reviewer_email' => env('ANALYTICS_PIA_REVIEWER_EMAIL'),
            'dpo_email' => env('ANALYTICS_PIA_DPO_EMAIL'),
            'assessment_frequency_days' => (int) env('ANALYTICS_PIA_FREQUENCY', 365),
            'high_risk_categories' => ['security', 'ecommerce'],
            'processing_purposes' => ['analytics', 'improvement', 'security'],
            'retention_review_days' => (int) env('ANALYTICS_PIA_RETENTION_REVIEW', 90),
            'cross_border_transfers' => ['US', 'EU'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Consent Receipt Registry (v62.0.0)
        |--------------------------------------------------------------------------
        |
        | GDPR-compliant consent receipt recording and audit system.
        | Maintains a cryptographic hash chain of consent receipts that serve
        | as legally defensible proof of consent for regulatory audits.
        |
        | Each receipt records what was consented to, when, by whom, and
        | includes a hash chain for tamper detection (similar to blockchain).
        |
        | Supports consent history queries, integrity verification, and
        | regulatory export in JSON format.
        |
        | Options:
        |   - retention_days: How long receipts are kept (default: 7 years per GDPR)
        |   - include_hash_chain: Enable cryptographic chain for tamper detection
        |   - max_receipts_per_client: Maximum receipts stored per client ID
        |   - auto_record_consent_changes: Automatically record when consent changes
        |
        */
        /*
        |-------------------------------------------------------------------------- 
        | Event Session Context Service (v63.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Builds rich session/device context for analytics events. Extracts
        | browser, OS, device type, screen info, UTM, and geolocation data
        | from HTTP requests and attaches to events as structured params.
        |
        | Features:
        |   - device_parsing: Parse User-Agent for browser, OS, device type
        |   - geolocation: IP-based geo lookup (via GeolocationEnricher)
        |   - fingerprinting: SHA-256 device fingerprint generation
        |   - Configurable cache TTLs for device and geo lookups
        |
        */
        'session_context' => [
            'enabled' => env('ANALYTICS_SESSION_CONTEXT_ENABLED', false),
            'device_parsing' => env('ANALYTICS_SESSION_CONTEXT_DEVICE_PARSING', true),
            'geolocation' => env('ANALYTICS_SESSION_CONTEXT_GEOLOCATION', false),
            'fingerprinting' => env('ANALYTICS_SESSION_CONTEXT_FINGERPRINTING', false),
            'device_cache_ttl' => (int) env('ANALYTICS_SESSION_CONTEXT_DEVICE_CACHE_TTL', 86400), // 24 hours
            'geo_cache_ttl' => (int) env('ANALYTICS_SESSION_CONTEXT_GEO_CACHE_TTL', 604800), // 7 days
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Provider Dispatch Deduplication (v63.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Prevents duplicate event dispatches to the same provider within a
        | configurable time window. Uses content-based hashing (event name +
        | key params + client/user ID) to identify duplicates.
        |
        | Critical-priority events bypass dedup automatically.
        | Configure window_seconds based on your frontend retry/retry behavior.
        |
        */
        'dispatch_dedup' => [
            'enabled' => env('ANALYTICS_DISPATCH_DEDUP_ENABLED', false),
            'window_seconds' => (int) env('ANALYTICS_DISPATCH_DEDUP_WINDOW', 10), // 10 seconds
            'hash_algorithm' => env('ANALYTICS_DISPATCH_DEDUP_HASH', 'xxh128'), // xxh128, sha256, md5
            'cache_prefix' => env('ANALYTICS_DISPATCH_DEDUP_CACHE_PREFIX', 'zb_dedup_'),
        ],

        'consent_receipt' => [
            'enabled' => env('ANALYTICS_CONSENT_RECEIPT_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_CONSENT_RECEIPT_CACHE_TTL', 7776000), // 90 days
            'retention_days' => (int) env('ANALYTICS_CONSENT_RECEIPT_RETENTION', 2555), // 7 years
            'include_hash_chain' => env('ANALYTICS_CONSENT_RECEIPT_HASH_CHAIN', true),
            'include_ip' => env('ANALYTICS_CONSENT_RECEIPT_INCLUDE_IP', true),
            'include_user_agent' => env('ANALYTICS_CONSENT_RECEIPT_INCLUDE_UA', false),
            'require_auth' => env('ANALYTICS_CONSENT_RECEIPT_REQUIRE_AUTH', false),
            'max_receipts_per_client' => (int) env('ANALYTICS_CONSENT_RECEIPT_MAX_PER_CLIENT', 100),
            'auto_record_consent_changes' => env('ANALYTICS_CONSENT_RECEIPT_AUTO_RECORD', true),
            'purposes' => [
                'analytics_storage',
                'ad_storage',
                'ad_user_data',
                'ad_personalization',
                'functionality_storage',
                'personalization_storage',
            ],
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Event Payload Transformation Engine (v70.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Provider-specific event payload transformation rules. Inspired by
        | Segment Protocols, RudderStack Transformations, and mParticle
        | Data Planning — the industry standard for per-provider event mapping.
        |
        | Define field-level transformation rules that are applied before
        | events are dispatched to each provider:
        |
        | - rename: Change field names per provider (e.g., item_id → content_id)
        | - drop: Exclude fields from specific providers
        | - cast: Convert field types (e.g., string "1.99" → float 1.99)
        | - default: Provide fallback values for missing fields
        | - allow_only: Whitelist — only include specified fields
        | - static_overrides: Merge static fields into every payload
        | - event_name_override: Map event names to provider equivalents
        |
        | Rule types per field:
        |   source_field:     (required) The original parameter name
        |   target_field:     (optional) New name for this provider
        |   cast_to:          (optional) 'string'|'int'|'float'|'bool'
        |   default_value:    (optional) Fallback if source is missing
        |   drop_if_missing:  (optional) Omit if source is missing (default: false)
        |   drop_always:      (optional) Always exclude this field (default: false)
        |
        | Example mapping — rename + drop + cast for Meta Pixel purchase:
        |   'mappings' => [
        |       [
        |           'event' => 'purchase',
        |           'provider' => 'meta',
        |           'event_name_override' => 'Purchase',
        |           'rules' => [
        |               ['source_field' => 'transaction_id', 'target_field' => 'order_id'],
        |               ['source_field' => 'value', 'cast_to' => 'float'],
        |               ['source_field' => 'internal_score', 'drop_always' => true],
        |               ['source_field' => 'currency', 'default_value' => 'USD'],
        |           ],
        |           'static_overrides' => ['event_source' => 'server'],
        |       ],
        |   ],
        |
        */
        'transformation' => [
            'enabled' => env('ANALYTICS_TRANSFORMATION_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_TRANSFORMATION_CACHE_TTL', 3600), // 1 hour
            'strict' => env('ANALYTICS_TRANSFORMATION_STRICT', false), // true = drop events with missing required fields
            'mappings' => [
                // Example: Rename purchase fields for Meta Pixel
                // [
                //     'event' => 'purchase',
                //     'provider' => 'meta',
                //     'event_name_override' => 'Purchase',
                //     'rules' => [
                //         ['source_field' => 'transaction_id', 'target_field' => 'order_id'],
                //         ['source_field' => 'value', 'cast_to' => 'float'],
                //         ['source_field' => 'currency', 'default_value' => 'USD'],
                //     ],
                //     'static_overrides' => ['event_source' => 'server'],
                // ],
                //
                // Example: Whitelist fields for Plausible (privacy-focused, fewer fields)
                // [
                //     'event' => 'page_view',
                //     'provider' => 'plausible',
                //     'allow_only' => ['url', 'referrer', 'title'],
                // ],
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Audit Trail (v72.0.0)
        |--------------------------------------------------------------------------
        |
        | Comprehensive audit trail for every dispatched analytics event.
        | Records audit ID, event name, client/user identity, timestamp,
        | per-provider dispatch results (success/failure/latency), pipeline
        | stage timings, consent state, and source channel.
        |
        | Cache-backed with configurable retention and max entries (ring buffer).
        | Provides search, statistics, and GDPR-compliant data erasure.
        |
        */
        'audit_trail' => [
            'enabled' => env('ANALYTICS_AUDIT_TRAIL_ENABLED', true),
            'ttl' => (int) env('ANALYTICS_AUDIT_TRAIL_TTL', 2592000), // 30 days (seconds)
            'max_entries' => (int) env('ANALYTICS_AUDIT_TRAIL_MAX_ENTRIES', 10000),
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Attribution Trail (v72.0.0)
        |--------------------------------------------------------------------------
        |
        | Attribution trail tracking the complete UTM and referrer journey
        | from first touch through every subsequent touchpoint. Maintains
        | per-identity records with first-touch, last-touch, multi-touch
        | history, referrer chain, and conversion event association.
        |
        | Supports multiple attribution models (first-touch, last-touch,
        | linear, time-decay) for comparison.
        |
        */
        'attribution_trail' => [
            'enabled' => env('ANALYTICS_ATTRIBUTION_TRAIL_ENABLED', true),
            'ttl' => (int) env('ANALYTICS_ATTRIBUTION_TRAIL_TTL', 2592000), // 30 days (seconds)
            'max_touch_history' => (int) env('ANALYTICS_ATTRIBUTION_TRAIL_MAX_TOUCH', 50),
            'max_referrer_chain' => (int) env('ANALYTICS_ATTRIBUTION_TRAIL_MAX_REFERRER', 20),
        ],

        /*
        |-------------------------------------------------------------------------- 
        | Geographic Analytics (v73.0.0)
        |-------------------------------------------------------------------------- 
        |
        | Regional event aggregation and geo intelligence service. Aggregates
        | analytics events by country, region, city, timezone, and continent to
        | provide geographic breakdowns, engagement heatmaps, regional conversion
        | funnels, timezone distributions, and geo anomaly detection.
        |
        | Data is sourced from GeolocationEnricher pipeline output (geo_country,
        | geo_region, geo_city, geo_timezone, geo_continent params).
        |
        | Engagement score is a weighted composite of normalized events (0.4),
        | users (0.4), and sessions (0.5). Scores range from 0 to 100 with
        | letter grades A (≥80), B (≥60), C (≥40), D (≥20), F (<20).
        |
        | Inspired by GA4 Geographic reports, Amplitude Geo Analytics,
        | and Mixpanel Geographic Insights.
        |
        */
        'geographic_analytics' => [
            'enabled' => env('ANALYTICS_GEO_ANALYTICS_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_GEO_ANALYTICS_CACHE_TTL', 300), // 5 minutes
            'top_regions_limit' => (int) env('ANALYTICS_GEO_ANALYTICS_TOP_REGIONS', 20),
            'top_events_per_region' => (int) env('ANALYTICS_GEO_ANALYTICS_TOP_EVENTS', 5),
            'anomaly_threshold_multiplier' => (int) env('ANALYTICS_GEO_ANALYTICS_ANOMALY_THRESHOLD', 3), // 3x baseline = anomaly
            'engagement_weight_events' => (float) env('ANALYTICS_GEO_ANALYTICS_WEIGHT_EVENTS', 0.4),
            'engagement_weight_users' => (float) env('ANALYTICS_GEO_ANALYTICS_WEIGHT_USERS', 0.4),
            'engagement_weight_sessions' => (float) env('ANALYTICS_GEO_ANALYTICS_WEIGHT_SESSIONS', 0.2),
        ],

        /*
        |--------------------------------------------------------------------------
        | Experiment Analysis Engine (v75.0.0)
        |--------------------------------------------------------------------------
        |
        | Bayesian and Frequentist hypothesis testing for A/B experiments.
        | Provides comprehensive statistical analysis including:
        | - Two-proportion z-test and Welch's t-test (Frequentist)
        | - Beta-Binomial Monte Carlo analysis (Bayesian)
        | - Wilson score confidence intervals
        | - Cohen's h effect sizes
        | - Bonferroni/Šidák/Holm-Bonferroni multi-variant corrections
        | - O'Brien-Fleming sequential testing with alpha spending
        | - MDE calculator and sample size planning
        |
        | Used by ExperimentAnalysisEngine service and zb:analytics:experiment CLI.
        |
        */
        'experiment_analysis' => [
            'enabled' => env('ANALYTICS_EXPERIMENT_ANALYSIS_ENABLED', true),
            'alpha' => (float) env('ANALYTICS_EXPERIMENT_ANALYSIS_ALPHA', 0.05),
            'power' => (float) env('ANALYTICS_EXPERIMENT_ANALYSIS_POWER', 0.80),
            'method' => env('ANALYTICS_EXPERIMENT_ANALYSIS_METHOD', 'both'), // 'frequentist'|'bayesian'|'both'
            'sequential_alpha_spend_rate' => (float) env('ANALYTICS_EXPERIMENT_ANALYSIS_SEQUENTIAL_RATE', 0.5),
            'min_sample_size' => (int) env('ANALYTICS_EXPERIMENT_ANALYSIS_MIN_SAMPLE', 100),
            'max_sequential_peeks' => (int) env('ANALYTICS_EXPERIMENT_ANALYSIS_MAX_PEEKS', 10),
        ],

        /*
        |--------------------------------------------------------------------------
        | Contract Testing — Provider-specific event contract validation
        |--------------------------------------------------------------------------
        |
        | Validates event payloads against provider-specific contracts before
        | dispatch. Inspired by Segment Protocols, PostHog Property Validation,
        | and Amplitude's Event Validator.
        |
        | Used by EventContractTestService service and zb:analytics:contract CLI.
        |
        */
        'contract_testing' => [
            'enabled' => env('ANALYTICS_CONTRACT_TESTING_ENABLED', true),
            'severity' => env('ANALYTICS_CONTRACT_TESTING_SEVERITY', 'warn'), // 'reject'|'warn'|'off'
            'cache_ttl' => (int) env('ANALYTICS_CONTRACT_TESTING_CACHE_TTL', 3600),
        ],

        /*
        |--------------------------------------------------------------------------
        | SDK Authentication (v77.0.0)
        |--------------------------------------------------------------------------
        |
        | Middleware-based SDK token authentication for API endpoints.
        | When enabled, the `analytics.sdk` middleware validates incoming
        | requests against scoped SDK tokens managed by SdkScopeTokenService.
        |
        | Tokens can be passed via:
        | - Authorization: Bearer <token> header
        | - X-ZB-SDK-Token header
        | - zb_sdk_token query parameter
        |
        | When disabled, routes fall back to default auth (auth:sanctum).
        |
        */
        'sdk_auth' => [
            'enabled' => env('ANALYTICS_SDK_AUTH_ENABLED', false),
            'required_permission' => env('ANALYTICS_SDK_AUTH_PERMISSION', ''), // empty = any valid token
            'enforce_rate_limit' => env('ANALYTICS_SDK_AUTH_RATE_LIMIT', true),
        ],

        /*
        |--------------------------------------------------------------------------
        | Event Health Scoring Engine (v80.0.0)
        |--------------------------------------------------------------------------
        |
        | Per-event health monitoring across five dimensions: freshness, volume,
        | schema compliance, provider delivery, and data quality. Produces
        | composite health scores (0-100) with A+–F grades.
        |
        | Used by EventHealthScoringEngine service and zb:analytics:event-health CLI.
        |
        */
        'event_health' => [
            'enabled' => env('ANALYTICS_EVENT_HEALTH_ENABLED', true),
            'freshness_threshold' => (int) env('ANALYTICS_EVENT_HEALTH_FRESHNESS_THRESHOLD', 3600), // seconds
            'volume_drop_threshold' => (float) env('ANALYTICS_EVENT_HEALTH_VOLUME_DROP_THRESHOLD', 0.3), // 0-1
            'volume_spike_multiplier' => (float) env('ANALYTICS_EVENT_HEALTH_VOLUME_SPIKE_MULTIPLIER', 5.0),
            'min_volume_sample' => (int) env('ANALYTICS_EVENT_HEALTH_MIN_VOLUME_SAMPLE', 10),
        ],

        /*
        |--------------------------------------------------------------------------
        | Deploy Gate (v80.0.0)
        |--------------------------------------------------------------------------
        |
        | Pre-deployment analytics validation for CI/CD pipelines. Blocks deploys
        | that would break analytics instrumentation. Runs catalog integrity,
        | schema coverage, provider compatibility, lifecycle mapping, and
        | breaking change detection checks.
        |
        | Used by AnalyticsDeployGate service and zb:analytics:deploy-gate CLI.
        |
        */
        'deploy_gate' => [
            'block_on_warnings' => env('ANALYTICS_DEPLOY_GATE_BLOCK_ON_WARNINGS', false),
            'min_health_score' => (int) env('ANALYTICS_DEPLOY_GATE_MIN_HEALTH_SCORE', 40),
            'skip_events' => [], // event names to skip during gate checks
        ],

        /*
        |--------------------------------------------------------------------------
        | Funnel Velocity Analyzer (v82.0.0)
        |--------------------------------------------------------------------------
        |
        | Real-time funnel step velocity tracking with per-step timing analytics.
        | Computes median/p95 time-to-advance, dropout rates, bottleneck detection,
        | and completion time prediction for multi-step funnels.
        |
        | Used by FunnelVelocityAnalyzer service.
        |
        */
        'funnel_velocity' => [
            'enabled' => env('ANALYTICS_FUNNEL_VELOCITY_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_FUNNEL_VELOCITY_CACHE_TTL', 86400), // 24 hours
            'window_hours' => (int) env('ANALYTICS_FUNNEL_VELOCITY_WINDOW_HOURS', 72),
            'bottleneck_threshold' => (float) env('ANALYTICS_FUNNEL_VELOCITY_BOTTLENECK_THRESHOLD', 75.0),
        ],

        /*
        |--------------------------------------------------------------------------
        | Privacy-Aware Event Router (v82.0.0)
        |--------------------------------------------------------------------------
        |
        | Routes analytics events based on geographic privacy zone (GDPR, CCPA,
        | LGPD, PIPEDA) with automatic field stripping and consent enforcement.
        | Prevents PII leakage to non-compliant providers.
        |
        | Used by PrivacyAwareEventRouter service.
        |
        */
        'privacy_router' => [
            'enabled' => env('ANALYTICS_PRIVACY_ROUTER_ENABLED', true),
            'default_zone' => env('ANALYTICS_PRIVACY_ROUTER_DEFAULT_ZONE', 'none'),
            'custom_block_fields' => [
                // 'gdpr' => ['custom_pii_field'],
            ],
            'provider_allowlists' => [
                // 'gdpr' => ['ga4', 'plausible'],  // Only GDPR-safe providers
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Revenue Signal Detector (v82.0.0)
        |--------------------------------------------------------------------------
        |
        | SaaS revenue signal detection from user event pattern analysis.
        | Computes churn risk scores, expansion opportunity scores, and
        | recommended actions based on configurable event signal weights.
        |
        | Used by RevenueSignalDetector service.
        |
        */
        'revenue_signals' => [
            'enabled' => env('ANALYTICS_REVENUE_SIGNALS_ENABLED', true),
            'cache_ttl' => (int) env('ANALYTICS_REVENUE_SIGNALS_CACHE_TTL', 3600), // 1 hour
        ],
    ],
    /*
    |--------------------------------------------------------------------------
    | Provider SLA Monitor (v84.0.0)
    |--------------------------------------------------------------------------
    |
    | Monitors each analytics provider against SLA targets: uptime, latency,
    | and error budget. Provides breach detection, compliance percentage
    | tracking, and health comparison matrix across all providers.
    |
    | Inspired by Google Cloud SLI/SLO framework and Datadog SLO monitoring.
    |
    */
    'sla_monitor' => [
        'enabled' => env('ANALYTICS_SLA_MONITOR_ENABLED', true),
        'window_seconds' => (int) env('ANALYTICS_SLA_WINDOW_SECONDS', 3600), // 1 hour
        'retention_windows' => (int) env('ANALYTICS_SLA_RETENTION_WINDOWS', 168), // 7 days hourly
        'default_uptime_target' => (float) env('ANALYTICS_SLA_DEFAULT_UPTIME', 99.9), // %
        'default_latency_target' => (float) env('ANALYTICS_SLA_DEFAULT_LATENCY', 500.0), // ms
        'default_p99_latency_target' => (float) env('ANALYTICS_SLA_DEFAULT_P99_LATENCY', 2000.0), // ms
        'default_error_budget' => (int) env('ANALYTICS_SLA_DEFAULT_ERROR_BUDGET', 10), // per window
        'alert_on_breach' => env('ANALYTICS_SLA_ALERT_ON_BREACH', true),
        'max_breach_history' => (int) env('ANALYTICS_SLA_MAX_BREACH_HISTORY', 1000),
        'monitored_providers' => ['ga4', 'meta_pixel', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'],
        'providers' => [
            // Per-provider SLA overrides (optional)
            // 'ga4' => [
            //     'uptime_target' => 99.95,
            //     'latency_target' => 300.0,
            //     'p99_latency_target' => 1500.0,
            //     'error_budget' => 5,
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Analytics Cost Forecast (v84.0.0)
    |--------------------------------------------------------------------------
    |
    | Predicts future analytics costs per provider using historical event
    | volume data, configured cost-per-event rates, and trend extrapolation.
    | Provides budget alerting and optimization recommendations.
    |
    | Inspired by Segment's billing dashboard and Amplitude's usage analytics.
    |
    */
    'cost_forecast' => [
        'enabled' => env('ANALYTICS_COST_FORECAST_ENABLED', true),
        'currency' => env('ANALYTICS_COST_FORECAST_CURRENCY', 'USD'),
        'history_months' => (int) env('ANALYTICS_COST_FORECAST_HISTORY_MONTHS', 3),
        'projection_months' => (int) env('ANALYTICS_COST_FORECAST_PROJECTION_MONTHS', 3),
        'growth_cap' => (float) env('ANALYTICS_COST_FORECAST_GROWTH_CAP', 50.0), // Max 50% growth assumption
        'alert_on_exceeds_budget' => env('ANALYTICS_COST_FORECAST_ALERT', true),
        'monthly_budget' => (float) env('ANALYTICS_COST_FORECAST_BUDGET', 1000.0),
        'cache_ttl' => (int) env('ANALYTICS_COST_FORECAST_CACHE_TTL', 3600),
        'providers' => [
            // Cost per 1000 events for each provider
            // 'ga4' => 0.0,          // GA4 MP is free
            // 'meta_pixel' => 0.0,   // Meta Pixel is free (server events cost via CAPI)
            // 'posthog' => 0.00625,  // ~$6.25 per 1M events (PostHog pricing)
            // 'plausible' => 0.009,  // ~$9 per 1M events
            // 'mixpanel' => 0.0,     // Free tier available
            // 'amplitude' => 0.0,    // Free tier available
            // 'tiktok' => 0.0,       // Free
            // 'linkedin' => 0.0,      // Free
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Governance Policy Engine (v84.0.0)
    |--------------------------------------------------------------------------
    |
    | Declarative compliance policy rules for analytics events. Evaluates
    | dispatched events against configurable governance policies: parameter
    | limits, PII detection, rate limiting, event whitelists/blacklists,
    | and category restrictions.
    |
    | Inspired by Datadog Governance API and Snowflake Data Governance.
    |
    */
    'governance_policies' => [
        'enabled' => env('ANALYTICS_GOVERNANCE_POLICIES_ENABLED', true),
        'default_action' => env('ANALYTICS_GOVERNANCE_DEFAULT_ACTION', 'warn'), // block, warn, sanitize, transform
        'max_violation_history' => (int) env('ANALYTICS_GOVERNANCE_MAX_HISTORY', 500),
        'cache_ttl' => (int) env('ANALYTICS_GOVERNANCE_CACHE_TTL', 3600),
        'log_violations' => env('ANALYTICS_GOVERNANCE_LOG_VIOLATIONS', true),
        'pii_patterns' => ['email', 'phone', 'ssn', 'credit_card', 'password', 'token', 'secret', 'api_key', 'authorization', 'cookie'],
        'rules' => [
            // Example policies:
            // 'disallow_sensitive_keys' => [
            //     'type' => 'disallowed_params',
            //     'action' => 'sanitize',
            //     'severity' => 'high',
            //     'description' => 'Remove sensitive parameter keys from events',
            //     'config' => ['keys' => ['password', 'token', 'secret', 'api_key', 'credit_card']],
            // ],
            // 'max_event_params' => [
            //     'type' => 'max_params',
            //     'action' => 'warn',
            //     'severity' => 'medium',
            //     'description' => 'Warn when events have too many parameters',
            //     'config' => ['max' => 100],
            // ],
            // 'block_internal_events' => [
            //     'type' => 'blocked_events',
            //     'action' => 'block',
            //     'severity' => 'critical',
            //     'description' => 'Block internal/debug events from production',
            //     'config' => ['events' => ['debug_ping', 'internal_test']],
            // ],
            // 'pii_auto_detect' => [
            //     'type' => 'pii_detection',
            //     'action' => 'sanitize',
            //     'severity' => 'high',
            //     'description' => 'Auto-detect and redact PII from event payloads',
            //     'config' => [],
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SaaS Feature Usage Tracker (v85.0.0)
    |--------------------------------------------------------------------------
    |
    | Tracks daily/weekly/monthly active users per feature, computes usage streaks,
    | identifies power users, and provides feature adoption lifecycle analytics.
    |
    */
    'feature_usage' => [
        'enabled' => env('ANALYTICS_FEATURE_USAGE_ENABLED', true),
        'cache_ttl' => (int) env('ANALYTICS_FEATURE_USAGE_CACHE_TTL', 86400), // 24 hours
        'power_user_threshold' => (int) env('ANALYTICS_FEATURE_USAGE_POWER_THRESHOLD', 7), // 7-day streak
        'known_features' => [
            'dashboard', 'api_export', 'team_invites', 'reporting', 'integrations',
            'search', 'file_download', 'onboarding', 'settings', 'billing',
            'feature_flags', 'webhooks', 'audit_log', 'notifications',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Budget Optimizer (v85.0.0)
    |--------------------------------------------------------------------------
    |
    | Cost-aware intelligent event routing. Analyzes event costs per provider,
    | respects budget limits, and optimizes routing decisions.
    |
    */
    'budget_optimizer' => [
        'enabled' => env('ANALYTICS_BUDGET_OPTIMIZER_ENABLED', false),
        'cache_ttl' => (int) env('ANALYTICS_BUDGET_OPTIMIZER_CACHE_TTL', 86400),
        'cost_per_event' => [
            'ga4' => 0.0001,
            'gtm' => 0.0,
            'meta' => 0.0002,
            'plausible' => 0.00015,
            'posthog' => 0.0001,
            'mixpanel' => 0.0002,
            'amplitude' => 0.00015,
            'tiktok' => 0.0002,
            'linkedin' => 0.0003,
            'webhook' => 0.0,
        ],
        'monthly_budgets' => [
            'ga4' => 50.0,
            'gtm' => 0.0,
            'meta' => 100.0,
            'plausible' => 30.0,
            'posthog' => 75.0,
            'mixpanel' => 100.0,
            'amplitude' => 75.0,
            'tiktok' => 50.0,
            'linkedin' => 25.0,
            'webhook' => 0.0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant Analytics Dashboard (v85.0.0)
    |--------------------------------------------------------------------------
    |
    | Multi-tenant SaaS analytics aggregation. Provides per-tenant dashboards
    | with aggregated metrics, KPI summaries, and cross-tenant benchmarking.
    |
    */
    'tenant_dashboard' => [
        'enabled' => env('ANALYTICS_TENANT_DASHBOARD_ENABLED', false),
        'cache_ttl' => (int) env('ANALYTICS_TENANT_DASHBOARD_CACHE_TTL', 86400),
        'health_score_weights' => [
            'volume' => 30,    // max 30 points
            'engagement' => 35, // max 35 points
            'diversity' => 35,  // max 35 points
        ],
    ],

    /*
    |------------------------------------------------------------------
    | Event Sequence Prediction (v86.0.0)
    |------------------------------------------------------------------
    |
    | Markov chain-based next-event prediction engine. Builds transition
    | matrices from observed user event sequences and predicts the most
    | likely next event(s) in a session.
    |
    | Use cases: proactive instrumentation, funnel optimization,
    | anomaly detection, onboarding guidance.
    |
    */
    'sequence_prediction' => [
        'enabled' => env('ANALYTICS_SEQUENCE_PREDICTION_ENABLED', false),
        'cache_ttl' => (int) env('ANALYTICS_SEQUENCE_PREDICTION_CACHE_TTL', 3600),
        'min_observations' => (int) env('ANALYTICS_SEQUENCE_PREDICTION_MIN_OBS', 10),
        'top_n' => (int) env('ANALYTICS_SEQUENCE_PREDICTION_TOP_N', 5),
        'confidence_threshold' => (float) env('ANALYTICS_SEQUENCE_PREDICTION_CONFIDENCE', 0.05),
        'use_second_order' => (bool) env('ANALYTICS_SEQUENCE_PREDICTION_SECOND_ORDER', true),
        'excluded_events' => ['page_view', 'scroll_depth', 'session_start', 'session_end'],
    ],

    /*
    |------------------------------------------------------------------
    | Event Cost Ledger (v86.0.0)
    |------------------------------------------------------------------
    |
    | Per-event dispatch cost tracking across providers. Tracks
    | computational and financial cost for budgeting and optimization.
    |
    | Provider cost rates are per 1000 events in USD.
    | Set to 0 for free providers or adjust based on your pricing plan.
    |
    */
    'cost_ledger' => [
        'enabled' => env('ANALYTICS_COST_LEDGER_ENABLED', true),
        'cache_ttl' => (int) env('ANALYTICS_COST_LEDGER_CACHE_TTL', 86400),
        'daily_budget' => (float) env('ANALYTICS_COST_LEDGER_DAILY_BUDGET', 100.0),
        'monthly_budget' => (float) env('ANALYTICS_COST_LEDGER_MONTHLY_BUDGET', 3000.0),
        'provider_cost_rates' => [
            'ga4' => 0.001,
            'gtm' => 0.001,
            'meta' => 0.001,
            'plausible' => 0.0005,
            'posthog' => 0.0005,
            'mixpanel' => 0.001,
            'amplitude' => 0.001,
            'tiktok' => 0.001,
            'linkedin' => 0.001,
            'webhook' => 0.0001,
        ],
        'exempt_events' => ['page_view', 'scroll_depth'],
    ],

    /*
    |------------------------------------------------------------------
    | Compliance Report (v86.0.0)
    |------------------------------------------------------------------
    |
    | Multi-framework compliance report generator for GDPR, CCPA, SOC2,
    | and ePrivacy Directive audits. Generates structured reports with
    | evidence checkpoints, compliance scores, and gap analysis.
    |
    | Reports are generated on-demand (no cron required).
    |
    */
    'compliance_report' => [
        'cache_ttl' => (int) env('ANALYTICS_COMPLIANCE_REPORT_CACHE_TTL', 3600),
        'include_recommendations' => true,
        'frameworks' => ['gdpr', 'ccpa', 'soc2', 'eprivacy'],
    ],

    /*
    |------------------------------------------------------------------
    | Data-Driven Attribution (v87.0.0)
    |------------------------------------------------------------------
    |
    | Shapley-value based multi-touch attribution that uses observed
    | conversion data to compute the marginal contribution of each channel.
    | The gold-standard attribution model (used by GA4 enterprise).
    |
    | Requires sufficient conversion data for reliable results.
    |
    */
    'data_driven_attribution' => [
        'enabled' => env('ANALYTICS_DDA_ENABLED', true),
        'cache_ttl' => (int) env('ANALYTICS_DDA_CACHE_TTL', 3600),
        'min_conversions' => (int) env('ANALYTICS_DDA_MIN_CONVERSIONS', 30),
        'lookback_days' => (int) env('ANALYTICS_DDA_LOOKBACK_DAYS', 90),
        'max_path_length' => (int) env('ANALYTICS_DDA_MAX_PATH_LENGTH', 20),
    ],

    /*
    |------------------------------------------------------------------
    | Unit Economics (v87.0.0)
    |------------------------------------------------------------------
    |
    | Subscriber-level unit economics calculator for SaaS financial metrics.
    | Configurable LTV model parameters and industry benchmarks.
    |
    */
    'unit_economics' => [
        'enabled' => env('ANALYTICS_UNIT_ECON_ENABLED', true),
        'cache_ttl' => (int) env('ANALYTICS_UNIT_ECON_CACHE_TTL', 300),
        'ltv' => [
            'lifetime_months' => (int) env('ANALYTICS_LTV_LIFETIME_MONTHS', 120),
            'discount_rate' => (float) env('ANALYTICS_LTV_DISCOUNT_RATE', 0.01),
            'gross_margin' => (float) env('ANALYTICS_LTV_GROSS_MARGIN', 0.75),
        ],
        'benchmarks' => [
            'ltv_cac_target' => (float) env('ANALYTICS_LTV_CAC_TARGET', 3.0),
            'payback_target_months' => (int) env('ANALYTICS_PAYBACK_TARGET', 18),
            'magic_number_target' => (float) env('ANALYTICS_MAGIC_NUMBER_TARGET', 0.75),
        ],
    ],

    /*
    |------------------------------------------------------------------
    | Auto Page-View Tracking (v92.0.0)
    |------------------------------------------------------------------
    |
    | Server-side automatic page_view event dispatch for every HTTP response.
    | Complements client-side page_view tracking by capturing bot traffic,
    | API-driven navigation, and environments where client JS is disabled.
    |
    | Register as route middleware: `analytics.pageview`
    | Register as global middleware for site-wide auto-tracking.
    |
    */
    'auto_pageview' => [
        'enabled' => env('ANALYTICS_AUTO_PAGEVIEW_ENABLED', false),
        'exclude_paths' => [
            '*/_ignition*',
            '*/telescope*',
            '*/horizon*',
            '*/pulse*',
            '*/vendor/*',
            '*/storage/*',
        ],
        'exclude_methods' => ['OPTIONS', 'HEAD'],
        'track_api' => env('ANALYTICS_AUTO_PAGEVIEW_TRACK_API', false),
        'track_status_codes' => [200, 301, 302, 303, 307, 308, 404],
        'bot_tracking' => env('ANALYTICS_AUTO_PAGEVIEW_BOT_TRACKING', false),
        'strip_query_params' => env('ANALYTICS_AUTO_PAGEVIEW_STRIP_QUERY', true),
        'max_url_length' => (int) env('ANALYTICS_AUTO_PAGEVIEW_MAX_URL_LENGTH', 2048),
        'sampling_rate' => (float) env('ANALYTICS_AUTO_PAGEVIEW_SAMPLING_RATE', 1.0),
    ],

    /*
    |------------------------------------------------------------------
    | Event Broadcasting (v92.0.0)
    |------------------------------------------------------------------
    |
    | Real-time analytics event delivery via Laravel Broadcasting.
    | Broadcasts events to WebSocket channels (Pusher, Reverb, Soketi, Ably)
    | for live admin dashboards and real-time activity monitoring.
    |
    | Channels:
    | - `analytics.events` — public channel for all events
    | - `analytics.events.{category}` — category-scoped events
    | - `analytics.tenant.{tenantId}` — private multi-tenant channel
    | - `analytics.admin` — private admin-only event stream
    |
    */
    'broadcasting' => [
        'enabled' => env('ANALYTICS_BROADCASTING_ENABLED', false),
        'public_channel_enabled' => env('ANALYTICS_BROADCASTING_PUBLIC', true),
        'public_channel' => env('ANALYTICS_BROADCASTING_PUBLIC_CHANNEL', 'analytics.events'),
        'category_channels' => env('ANALYTICS_BROADCASTING_CATEGORY_CHANNELS', true),
        'tenant_channels' => env('ANALYTICS_BROADCASTING_TENANT_CHANNELS', false),
        'admin_channel_enabled' => env('ANALYTICS_BROADCASTING_ADMIN_CHANNEL', false),
        'admin_channel' => env('ANALYTICS_BROADCASTING_ADMIN_CHANNEL_NAME', 'analytics.admin'),
        'include_params' => env('ANALYTICS_BROADCASTING_INCLUDE_PARAMS', true),
        'sensitive_params' => ['password', 'token', 'secret', 'api_key', 'credit_card', 'ssn', 'email', 'phone', 'ip'],
        'categories' => ['ecommerce', 'saas', 'engagement', 'security'],
    ],

    /*
    |------------------------------------------------------------------
    | Daily Health Report (v116.0.0)
    |------------------------------------------------------------------
    |
    | Unified daily health aggregation for SaaS operators. Evaluates 7
    | health domains (provider health, pipeline health, catalog integrity,
    | data quality, budget utilization, consent compliance, readiness) and
    | produces a single scored report with grades, issues, and actionable
    | recommendations.
    |
    | Designed for daily cron execution via `zb:analytics:health-report`.
    | Reports are cached for the TTL duration and keyed by date.
    |
    */
    'daily_health_report' => [
        'cache_ttl' => (int) env('ANALYTICS_DAILY_HEALTH_CACHE_TTL', 3600), // 1 hour
        'critical_threshold' => (int) env('ANALYTICS_DAILY_HEALTH_CRITICAL', 30), // Below this = critical
        'warning_threshold' => (int) env('ANALYTICS_DAILY_HEALTH_WARNING', 60), // Below this = degraded
    ],

    /*
    |------------------------------------------------------------------
    | Event Macros (v118.0.0)
    |------------------------------------------------------------------
    |
    | Reusable, parameterized event templates for DRY analytics tracking.
    | Macros define named event patterns with default parameters, required
    | keys, and organizational tags.
    |
    | Each macro maps a name to an analytics event with pre-configured
    | defaults. When executed, caller params are merged with defaults.
    |
    | Example:
    |   'feature_used' => [
    |       'event' => 'feature_used',
    |       'defaults' => ['source' => 'app', 'environment' => 'production'],
    |       'required' => ['feature_name'],
    |       'tags' => ['engagement', 'product', 'adoption'],
    |       'description' => 'Track feature usage with automatic context',
    |   ],
    |
    */
    'macros' => [
        'enabled' => env('ANALYTICS_MACROS_ENABLED', true),
        'definitions' => [
            // Register macros here or programmatically via AnalyticsMacroRegistry::define()
        ],
    ],

    /*
    |------------------------------------------------------------------
    | Replay Audit (v118.0.0)
    |------------------------------------------------------------------
    |
    | Configuration for the analytics event replay audit system.
    | Tracks replay attempts, success/failure rates, and validates
    | replay operations for data integrity.
    |
    */
    'replay_audit' => [
        'enabled' => env('ANALYTICS_REPLAY_AUDIT_ENABLED', true),
        'ttl' => (int) env('ANALYTICS_REPLAY_AUDIT_TTL', 604800), // 7 days
        'max_attempts' => (int) env('ANALYTICS_REPLAY_AUDIT_MAX_ATTEMPTS', 3),
        'replay_ttl' => (int) env('ANALYTICS_REPLAY_AUDIT_REPLAY_TTL', 86400), // 24 hours
    ],

    /*
    |------------------------------------------------------------------
    | Heartbeat Monitor (v120.0.0)
    |------------------------------------------------------------------
    |
    | Production health pulse monitoring for analytics subsystems.
    | Tracks provider circuit states, queue depth, dispatch liveness,
    | and maintains a ring-buffer history for dashboard monitoring.
    |
    | The pulse() method should be called by queue workers after each
    | event dispatch cycle. The current() method returns the latest
    | recorded pulse (or 'stale' if no pulse within threshold).
    |
    */
    'heartbeat' => [
        'enabled' => env('ANALYTICS_HEARTBEAT_ENABLED', true),
        'ttl' => (int) env('ANALYTICS_HEARTBEAT_TTL', 300), // 5 minutes — how long a pulse stays fresh
        'stale_threshold' => (int) env('ANALYTICS_HEARTBEAT_STALE_THRESHOLD', 600), // 10 minutes — after which pulse is considered stale
        'failure_threshold' => (int) env('ANALYTICS_HEARTBEAT_FAILURE_THRESHOLD', 5), // failures before provider circuit opens
    ],

    /*
    |------------------------------------------------------------------
    | Event Bundling (v120.0.0)
    |------------------------------------------------------------------
    |
    | Groups related SaaS lifecycle events into named journey bundles
    | (e.g., signup_funnel, activation_funnel, billing_funnel).
    | Enables funnel analysis, cohort attribution, and journey
    | reconstruction across analytics providers.
    |
    */
    'bundling' => [
        'enabled' => env('ANALYTICS_BUNDLING_ENABLED', true),
        'auto_track_journeys' => env('ANALYTICS_BUNDLING_AUTO_TRACK', true), // Automatically fire journey_start/journey_completed
        'bundle_id_prefix' => env('ANALYTICS_BUNDLING_PREFIX', 'bnd'),
    ],

    /*
    |------------------------------------------------------------------
    | SDK Telemetry Collection (v122.0.0)
    |------------------------------------------------------------------
    |
    | Collects client-side SDK health and performance telemetry
    | (SDK version, platform, page load times, connection type, memory usage,
    | error rates) for operational monitoring and version adoption tracking.
    |
    | Unlike ProviderDispatchTelemetry (server→provider), this tracks
    | client→server signals. Disabled by default to avoid PII concerns.
    |
    */
    'sdk_telemetry' => [
        'enabled' => env('ANALYTICS_SDK_TELEMETRY_ENABLED', false),
        'cache_ttl' => (int) env('ANALYTICS_SDK_TELEMETRY_CACHE_TTL', 86400), // 24 hours
        'aggregation_window' => (int) env('ANALYTICS_SDK_TELEMETRY_AGGREGATION_WINDOW', 3600), // 1 hour
        'collect_page_load' => env('ANALYTICS_SDK_TELEMETRY_PAGE_LOAD', true),
        'collect_connection_type' => env('ANALYTICS_SDK_TELEMETRY_CONNECTION_TYPE', true),
        'collect_memory_usage' => env('ANALYTICS_SDK_TELEMETRY_MEMORY_USAGE', true),
        'collect_battery_status' => env('ANALYTICS_SDK_TELEMETRY_BATTERY_STATUS', false),
        'collect_error_rates' => env('ANALYTICS_SDK_TELEMETRY_ERROR_RATES', true),
    ],

    /*
    |------------------------------------------------------------------
    | Event Compact Serialization (v122.0.0)
    |------------------------------------------------------------------
    |
    | Binary-safe compact serialization for high-throughput event batching.
    | Reduces JSON payload size by ~50-60% using a custom TLV encoding format.
    | Useful for mobile clients, high-frequency event sources, and edge deployments.
    |
    | Clients can serialize events client-side before sending them to
    | POST /api/analytics/deserialize for server-side processing.
    |
    */
    'compact_serialization' => [
        'enabled' => env('ANALYTICS_COMPACT_SERIALIZATION_ENABLED', true),
        'max_batch_size' => (int) env('ANALYTICS_COMPACT_MAX_BATCH', 100),
        'max_payload_bytes' => (int) env('ANALYTICS_COMPACT_MAX_PAYLOAD', 524288), // 512 KB
    ],

    /*
    |------------------------------------------------------------------
    | OpenAPI Specification Export (v127.0.0)
    |------------------------------------------------------------------
    |
    | Generates an OpenAPI 3.0.3 specification from the analytics event catalog
    | and API routes. Export via GET /api/analytics/openapi-spec (JSON) or
    | GET /api/analytics/openapi.yaml (YAML).
    |
    | Customize the spec metadata (title, description, contact, license)
    | to match your application's documentation standards.
    |
    */
    'openapi' => [
        'enabled' => env('ANALYTICS_OPENAPI_ENABLED', true),
        'title' => env('ANALYTICS_OPENAPI_TITLE', 'ZeroBoiler Analytics API'),
        'description' => env('ANALYTICS_OPENAPI_DESCRIPTION', 'Industry-standard SaaS analytics API for Laravel'),
        'version' => env('ANALYTICS_OPENAPI_VERSION'), // null = uses package version
        'contact' => [
            'name' => env('ANALYTICS_OPENAPI_CONTACT_NAME', 'ZeroBoiler'),
            'url' => env('ANALYTICS_OPENAPI_CONTACT_URL', 'https://zeroboiler.dev'),
        ],
        'license' => [
            'name' => 'MIT',
            'url' => 'https://opensource.org/licenses/MIT',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema Snapshot & Diff Detection (v142.0.0)
    |--------------------------------------------------------------------------
    |
    | Captures point-in-time snapshots of the event catalog structure and
    | computes diffs between versions to detect breaking changes before
    | they reach production. Useful for CI/CD integration and release gates.
    |
    | When auto_snapshot is true, a snapshot is automatically captured on
    | each request that triggers catalog access (lazy, first-hit only).
    |
    */
    'schema_snapshot' => [
        'enabled' => env('ANALYTICS_SCHEMA_SNAPSHOT_ENABLED', true),
        'auto_snapshot' => env('ANALYTICS_SCHEMA_SNAPSHOT_AUTO', true),
    ],

    // ────────────────────────────────────────────────────────────────────
    // Metric Projections (v128.0.0)
    // ────────────────────────────────────────────────────────────────────
    //
    // Define reusable metric projections that compute aggregate values
    // from event streams. Projections are evaluated by the MetricProjectionEngine
    // and materialized into cache-backed views by the EventMaterializer.
    //
    // Built-in projections:
    //   dau, weekly_signups, trial_conversion_rate, avg_revenue_per_user,
    //   signup_to_purchase_ratio, total_revenue_30d, unique_purchasers_30d,
    //   form_completion_rate, search_to_share_rate, cart_abandonment_rate,
    //   cancellation_rate_30d, error_rate_24h, login_count_7d
    //
    // CLI:
    //   php artisan zb:analytics:projections --list
    //   php artisan zb:analytics:projections --evaluate=dau
    //   php artisan zb:analytics:projections --dashboard
    //
    // API:
    //   GET /api/analytics/projections
    //   GET /api/analytics/projections/{name}
    //   GET /api/analytics/projections/dashboard
    //
    'projections' => [
        'enabled' => env('ANALYTICS_PROJECTIONS_ENABLED', true),
        'cache_enabled' => env('ANALYTICS_PROJECTIONS_CACHE_ENABLED', true),
        'cache_ttl' => (int) env('ANALYTICS_PROJECTIONS_CACHE_TTL', 0), // 0 = use per-projection TTL
        // Custom projections (loaded from config)
        'custom' => [
            // 'monthly_signups' => [
            //     'type' => 'count',
            //     'event' => 'sign_up',
            //     'window' => '30d',
            //     'category' => 'growth',
            //     'label' => 'Monthly Sign-ups',
            //     'cache_ttl' => 3600,
            //     'tags' => ['growth'],
            //     'public' => true,
            // ],
        ],
    ],

    // ────────────────────────────────────────────────────────────────────
    // Data Residency (v134.0.0)
    // ────────────────────────────────────────────────────────────────────
    //
    // Multi-region data routing and localization for GDPR, CCPA, LGPD, PIPEDA.
    // Controls which analytics providers receive data based on user geography.
    // When enabled, events are filtered through zone rules before dispatch.
    //
    // CLI:
    //   php artisan zb:analytics:data-governance --residency
    //   php artisan zb:analytics:data-governance --audit
    //
    'data_residency' => [
        'enabled' => env('ANALYTICS_DATA_RESIDENCY_ENABLED', false),
        'default_zone' => env('ANALYTICS_DATA_RESIDENCY_DEFAULT_ZONE', 'eu'),
        'audit_ttl' => (int) env('ANALYTICS_DATA_RESIDENCY_AUDIT_TTL', 7776000), // 90 days
        'cache_ttl' => (int) env('ANALYTICS_DATA_RESIDENCY_CACHE_TTL', 3600),
        'strict_categories' => ['saas', 'engagement'],
        'zones' => [
            'eu' => [
                'label' => 'European Union (GDPR)',
                'allowed_providers' => ['ga4', 'gtm', 'plausible', 'posthog', 'mixpanel', 'amplitude'],
                'blocked_fields' => ['ip_address', 'email', 'phone', 'ssn'],
                'requires_consent' => true,
            ],
            'us' => [
                'label' => 'United States (CCPA)',
                'allowed_providers' => ['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'],
                'blocked_fields' => ['ssn'],
                'requires_consent' => false,
            ],
            'us-ca' => [
                'label' => 'California (CCPA Strict)',
                'allowed_providers' => ['ga4', 'gtm', 'plausible', 'posthog', 'mixpanel', 'amplitude'],
                'blocked_fields' => ['email', 'phone', 'ssn', 'ip_address'],
                'requires_consent' => true,
            ],
            'br' => [
                'label' => 'Brazil (LGPD)',
                'allowed_providers' => ['ga4', 'gtm', 'plausible', 'posthog'],
                'blocked_fields' => ['email', 'phone', 'ip_address'],
                'requires_consent' => true,
            ],
            'ca' => [
                'label' => 'Canada (PIPEDA)',
                'allowed_providers' => ['ga4', 'gtm', 'plausible', 'posthog', 'mixpanel', 'amplitude'],
                'blocked_fields' => ['sin', 'ip_address'],
                'requires_consent' => true,
            ],
        ],
    ],

    // ────────────────────────────────────────────────────────────────────
    // Event Consistency (v134.0.0)
    // ────────────────────────────────────────────────────────────────────
    //
    // Cross-provider event delivery consistency validation. Detects:
    // - Provider coverage gaps (events missing mappings for enabled providers)
    // - Schema drift (field name/type mismatches across providers)
    // - Parameter completeness (required fields missing before dispatch)
    // - Consistency scoring (0-100 with letter grades)
    //
    // CLI:
    //   php artisan zb:analytics:data-governance --consistency
    //   php artisan zb:analytics:data-governance --gaps
    //   php artisan zb:analytics:data-governance --clear-cache
    //
    'event_consistency' => [
        'enabled' => env('ANALYTICS_EVENT_CONSISTENCY_ENABLED', true),
        'cache_ttl' => (int) env('ANALYTICS_EVENT_CONSISTENCY_CACHE_TTL', 300),
        'enabled_providers' => ['ga4', 'meta_pixel', 'posthog', 'plausible', 'mixpanel', 'amplitude'],
        'required_global_fields' => ['event_name', 'timestamp'],
        'cache_results' => env('ANALYTICS_EVENT_CONSISTENCY_CACHE', true),
    ],

    // ────────────────────────────────────────────────────────────────────
    // Feature Gating (v135.0.0)
    // ────────────────────────────────────────────────────────────────────
    //
    // Per-plan event tracking eligibility. Controls which analytics events
    // are available based on subscription tier. Higher tiers unlock premium
    // analytics (cohort, churn prediction, revenue forecasting).
    //
    // When enabled, events not in the plan's allowed list are silently dropped.
    // Core tracking events (page_view, click, sign_up, etc.) are always allowed.
    //
    // Usage:
    //   $gating->isEventAllowed('cohort_retention', 'pro');  // check eligibility
    //   $gating->filterAllowedEvents($events, 'free');        // batch filter
    //
    'feature_gating' => [
        'enabled' => env('ANALYTICS_FEATURE_GATING_ENABLED', false),
        'plan_hierarchy' => ['free', 'starter', 'pro', 'enterprise'],
        'premium_categories' => ['cohort', 'retention', 'revenue_intelligence'],
        'plans' => [
            // 'free' => ['page_view', 'click', 'sign_up', 'login'], // explicit allow list
            // 'pro' => ['*'], // wildcard = all events
        ],
    ],

    // ────────────────────────────────────────────────────────────────────
    // Customer Success (v135.0.0)
    // ────────────────────────────────────────────────────────────────────
    //
    // Customer success analytics configuration. Controls NPS survey settings,
    // health score thresholds, renewal reminder timing, and churn interview
    // automation.
    //
    'customer_success' => [
        'enabled' => env('ANALYTICS_CS_ENABLED', true),
        'nps' => [
            'auto_track' => env('ANALYTICS_CS_NPS_AUTO_TRACK', true),
            'score_range_min' => 0,
            'score_range_max' => 10,
        ],
        'health_score' => [
            'min' => 0,
            'max' => 100,
            'warning_threshold' => (float) env('ANALYTICS_CS_HEALTH_WARNING', 40.0),
            'critical_threshold' => (float) env('ANALYTICS_CS_HEALTH_CRITICAL', 25.0),
        ],
        'renewal' => [
            'reminder_days_before' => [30, 14, 7, 3, 1],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pipeline Profiler (v137.0.0)
    |--------------------------------------------------------------------------
    |
    | Analytics dispatch performance profiler. When enabled, tracks per-provider,
    | per-category, and per-event latency metrics. Provides p95/p99 statistics,
    | slow event detection, and degradation alerting.
    |
    | Used by AnalyticsPipelineProfilerService for operational monitoring.
    |
    */
    'pipeline_profiler' => [
        'enabled' => env('ANALYTICS_PIPELINE_PROFILER_ENABLED', false),
        'slow_threshold_ms' => (float) env('ANALYTICS_PIPELINE_PROFILER_SLOW_MS', 500.0),
        'critical_threshold_ms' => (float) env('ANALYTICS_PIPELINE_PROFILER_CRITICAL_MS', 1000.0),
        'cache_ttl' => (int) env('ANALYTICS_PIPELINE_PROFILER_CACHE_TTL', 3600),
        'max_samples' => (int) env('ANALYTICS_PIPELINE_PROFILER_MAX_SAMPLES', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Reliability (v137.0.0)
    |--------------------------------------------------------------------------
    |
    | Event delivery reliability scoring. Tracks success/failure rates per
    | provider and computes composite reliability scores with letter grades.
    | Used by AnalyticsEventReliabilityService.
    |
    */
    'event_reliability' => [
        'enabled' => env('ANALYTICS_EVENT_RELIABILITY_ENABLED', false),
        'warning_threshold' => (float) env('ANALYTICS_EVENT_RELIABILITY_WARNING', 0.90),
        'critical_threshold' => (float) env('ANALYTICS_EVENT_RELIABILITY_CRITICAL', 0.75),
        'window_seconds' => (int) env('ANALYTICS_EVENT_RELIABILITY_WINDOW', 300),
    ],

    /*
    |-------------------------------------------------------------------------- 
    | Event Cost Estimation (v139.0.0)
    |-------------------------------------------------------------------------- 
    |
    | Per-provider event cost estimation for SaaS budget planning.
    | Used by EventCostEstimator to project monthly/yearly analytics spend
    | and detect when estimated costs exceed the budget threshold.
    |
    | Override default industry costs per 1,000 events:
    |   'ga4' => 0.0,  'posthog' => 0.00025,  'plausible' => 0.0001, etc.
    |
    */
    'event_costs' => [
        'cache_ttl' => (int) env('ANALYTICS_EVENT_COSTS_CACHE_TTL', 300),
        'budget' => [
            'monthly_threshold' => (float) env('ANALYTICS_EVENT_COSTS_BUDGET', 100.0),
        ],
        // Per-provider overrides (cost per event in USD, leave empty for defaults)
        // 'ga4' => 0.0,
        // 'posthog' => 0.00025,
        // 'plausible' => 0.0001,
        // 'mixpanel' => 0.0002,
        // 'amplitude' => 0.0003,
        // 'meta_capi' => 0.0002,
    ],

    /*
    |-------------------------------------------------------------------------- 
    | SaaS Onboarding Funnel (v139.0.0)
    |-------------------------------------------------------------------------- 
    |
    | Standard 5-stage onboarding funnel: Signup → Email Verified →
    | First Value → Trial Start → Subscription.
    | Used by SaaSOnboardingFunnelTracker for conversion rate analysis,
    | drop-off detection, and product analytics dashboards.
    |
    */
    'onboarding_funnel' => [
        'cache_ttl' => (int) env('ANALYTICS_ONBOARDING_FUNNEL_CACHE_TTL', 3600),
        // Custom stage overrides (merge with standard 5 stages)
        // 'custom_stages' => [
        //     'activation' => [
        //         'name' => 'Activation',
        //         'event' => 'account_activated',
        //         'description' => 'User activates their account',
        //         'category' => 'saas',
        //         'successor' => 'trial_start',
        //     ],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Auto-Failover (v145.0.0)
    |--------------------------------------------------------------------------
    |
    | Automatic failover orchestration for analytics providers. When a provider
    | becomes unavailable (circuit breaker opens), events are automatically
    | routed to pre-configured fallback providers.
    |
    | Strategies:
    |   - 'priority': Select fallback with lowest priority number first
    |   - 'round_robin': Cycle through fallbacks evenly
    |   - 'health_score': Select fallback with highest composite health score
    |
    | Recovery ramp-up gradually restores traffic to recovered providers,
    | preventing thundering herd effects after outages.
    |
    */
    'failover' => [
        'enabled' => env('ANALYTICS_FAILOVER_ENABLED', false),
        'strategy' => env('ANALYTICS_FAILOVER_STRATEGY', 'priority'),
        'max_cascade_depth' => (int) env('ANALYTICS_FAILOVER_MAX_CASCADE_DEPTH', 3),
        'recovery_ramp_up_percent' => (int) env('ANALYTICS_FAILOVER_RECOVERY_RAMP_UP', 10),
        'audit_log_ttl' => (int) env('ANALYTICS_FAILOVER_AUDIT_LOG_TTL', 86400),
        'priority' => [
            'ga4' => 1,
            'meta_pixel' => 2,
            'posthog' => 3,
            'plausible' => 4,
            'mixpanel' => 5,
            'amplitude' => 6,
            'tiktok' => 7,
            'linkedin' => 8,
            'webhook' => 9,
        ],
        'providers' => [
            'ga4' => ['posthog', 'meta_pixel', 'webhook'],
            'meta_pixel' => ['ga4', 'posthog', 'webhook'],
            'posthog' => ['ga4', 'meta_pixel', 'webhook'],
            'plausible' => ['ga4', 'posthog', 'webhook'],
            'mixpanel' => ['ga4', 'amplitude', 'webhook'],
            'amplitude' => ['mixpanel', 'ga4', 'webhook'],
            'tiktok' => ['meta_pixel', 'ga4', 'webhook'],
            'linkedin' => ['ga4', 'meta_pixel', 'webhook'],
            'webhook' => ['ga4', 'posthog', 'meta_pixel'],
        ],
    ],

    /*
    |-------------------------------------------------------------------------- 
    | Anonymous Event Aggregation (v148.0.0)
    |-------------------------------------------------------------------------- 
    |
    | Privacy-safe aggregate event statistics without PII storage.
    | Counts events by name in configurable time windows (hourly, daily, weekly, monthly).
    | All user identifiers are stripped. Designed for GDPR/CCPA-compliant
    | traffic dashboards, public analytics, and stakeholder reporting.
    |
    | The service accumulates events in memory and flushes to cache on
    | request end (or manual flush()). Aggregates are queryable via
    | the AnonymousEventAggregationService.
    |
    */
    'anonymous_aggregation' => [
        'enabled' => env('ANALYTICS_ANON_AGGREGATION_ENABLED', false),
        'cache_ttl' => (int) env('ANALYTICS_ANON_AGGREGATION_CACHE_TTL', 3600), // 1 hour
        'default_window' => env('ANALYTICS_ANON_AGGREGATION_WINDOW', 'hourly'), // hourly, daily, weekly, monthly
        'max_unique_events' => (int) env('ANALYTICS_ANON_AGGREGATION_MAX_EVENTS', 1000),
    ],

    /*
    |-------------------------------------------------------------------------- 
    | Funnel Leak Detection (v148.0.0)
    |-------------------------------------------------------------------------- 
    |
    | Automated conversion funnel analysis that detects significant
    | drop-off points (leaks) between funnel steps. Provides actionable
    | recommendations for improvement based on industry best practices.
    |
    | Built-in funnels: signup_funnel, purchase_funnel, trial_funnel,
    | activation_funnel, retention_funnel. Add custom funnels via config.
    |
    | CLI: php artisan zb:analytics:funnel-leaks
    |
    */
    'funnel_leak_detection' => [
        'enabled' => env('ANALYTICS_FUNNEL_LEAK_DETECTION_ENABLED', false),
        'cache_ttl' => (int) env('ANALYTICS_FUNNEL_LEAK_CACHE_TTL', 86400), // 24 hours
        'leak_threshold' => (float) env('ANALYTICS_FUNNEL_LEAK_THRESHOLD', 0.40), // 40% drop-off = leak
        'critical_threshold' => (float) env('ANALYTICS_FUNNEL_LEAK_CRITICAL', 0.70), // 70% drop-off = critical
        'custom_funnels' => [
            // 'custom_funnel' => [
            //     'steps' => ['step_1', 'step_2', 'step_3'],
            //     'leak_threshold' => 0.30,
            // ],
        ],
    ],

    /*
    |-------------------------------------------------------------------------- 
    | First-Party Data (v148.0.0)
    |-------------------------------------------------------------------------- 
    |
    | Privacy-first user data capture for the cookieless tracking era.
    | Collects first-party signals (preferences, interests) directly
    | from user interactions. Supports behavioral cohort assignment,
    | GDPR-compliant data export, and readiness scoring.
    |
    | All data stored in cache. For production, implement a persistent
    | FirstPartyDataStoreInterface backed by database/storage.
    |
    */
    'first_party_data' => [
        'enabled' => env('ANALYTICS_FIRST_PARTY_DATA_ENABLED', false),
        'cache_ttl' => (int) env('ANALYTICS_FIRST_PARTY_DATA_CACHE_TTL', 7776000), // 90 days
        'max_preferences_per_user' => (int) env('ANALYTICS_FPD_MAX_PREFS', 50),
        'max_interests_per_user' => (int) env('ANALYTICS_FPD_MAX_INTERESTS', 20),
        'auto_cohort' => env('ANALYTICS_FPD_AUTO_COHORT', true),
    ],

    /*
    |-------------------------------------------------------------------------- 
    | Event Cardinality Limiter (v153.0.0)
    |-------------------------------------------------------------------------- 
    |
    | Prevents high-cardinality dimension explosion in analytics providers.
    | GA4 custom dimensions, PostHog properties, and Mixpanel events have
    | hard limits on unique values per parameter. This service monitors and
    | limits unique values per parameter key to prevent runaway cardinality
    | from user IDs, IPs, session IDs, and other unbounded dimensions.
    |
    | Actions when limit is exceeded:
    | - 'strict': Drop the entire event
    | - 'drop_param': Remove the offending parameter (default)
    | - 'bucket': Replace with a hashed bucket value
    |
    */
    'cardinality' => [
        'enabled' => env('ANALYTICS_CARDINALITY_ENABLED', true),
        'ttl' => (int) env('ANALYTICS_CARDINALITY_TTL', 3600), // 1 hour
        'default_limit' => (int) env('ANALYTICS_CARDINALITY_DEFAULT_LIMIT', 500),
        'param_limits' => [
            // Per-parameter overrides: 'event_name:param_key' => max_unique_values
            // 'purchase:user_id' => 100,
        ],
        'high_cardinality_params' => [
            'user_id', 'client_id', 'session_id', 'ip_address', 'email',
        ],
        'exceeded_action' => env('ANALYTICS_CARDINALITY_ACTION', 'drop_param'), // strict|drop_param|bucket
        'excluded_params' => [
            'event_name', 'category', 'source', 'timestamp', 'version',
        ],
        'excluded_events' => [],
    ],

    /*
    |-------------------------------------------------------------------------- 
    | Structured Event Logging (v153.0.0)
    |-------------------------------------------------------------------------- 
    |
    | Unified structured logging for all analytics event dispatches.
    | Produces consistent structured log entries with standard fields for
    | easy ingestion into observability platforms (Datadog, New Relic, Loki).
    |
    | Log entries use the configured channel ("analytics") and include
    | event metadata, dispatch context, and optional parameter data.
    |
    */
    'structured_logging' => [
        'enabled' => env('ANALYTICS_STRUCTURED_LOGGING_ENABLED', true),
        'channel' => env('ANALYTICS_STRUCTURED_LOGGING_CHANNEL', 'analytics'),
        'dispatch_level' => env('ANALYTICS_STRUCTURED_LOGGING_DISPATCH_LEVEL', 'debug'), // debug|info|warning|error
        'error_level' => env('ANALYTICS_STRUCTURED_LOGGING_ERROR_LEVEL', 'error'),
        'category_levels' => [
            // Per-category log level overrides
            // 'ecommerce' => 'info',
            // 'saas' => 'info',
        ],
        'provider_levels' => [
            // Per-provider log level overrides
            // 'ga4' => 'debug',
        ],
        'include_params' => env('ANALYTICS_STRUCTURED_LOGGING_INCLUDE_PARAMS', false),
        'sensitive_keys' => [
            'email', 'password', 'token', 'api_key', 'secret',
            'ip_address', 'credit_card', 'ssn', 'phone',
        ],
        'max_param_length' => (int) env('ANALYTICS_STRUCTURED_LOGGING_MAX_PARAM_LENGTH', 100),
        'excluded_events' => [],
        'log_rate_limit' => (int) env('ANALYTICS_STRUCTURED_LOGGING_RATE_LIMIT', 1000), // per minute
    ],

    /*
    |-------------------------------------------------------------------------- 
    | Event Delivery SLA Monitor (v153.0.0)
    |-------------------------------------------------------------------------- 
    |
    | Proactive per-provider SLA tracking for event delivery. Monitors
    | availability (success rate), latency percentiles (P50/P95/P99),
    | error rate, and throughput against configurable targets per provider.
    |
    | Status levels: healthy, degraded (within 5% of target), breached, unknown.
    |
    | CLI: php artisan zb:analytics:overview --health (includes SLA status)
    |
    */
    'sla' => [
        'enabled' => env('ANALYTICS_SLA_ENABLED', true),
        'ttl' => (int) env('ANALYTICS_SLA_TTL', 300), // 5 minutes
        'window_seconds' => (int) env('ANALYTICS_SLA_WINDOW', 300), // 5 minute sliding window
        'default_availability' => (float) env('ANALYTICS_SLA_AVAILABILITY', 0.999), // 99.9%
        'default_latency_p95' => (float) env('ANALYTICS_SLA_LATENCY_P95', 500.0), // 500ms
        'default_error_rate_max' => (float) env('ANALYTICS_SLA_ERROR_RATE_MAX', 0.01), // 1%
        'provider_targets' => [
            // Per-provider SLA targets (override defaults)
            // 'ga4' => ['availability' => 0.999, 'latency_p95' => 300, 'error_rate' => 0.005],
            // 'meta' => ['availability' => 0.995, 'latency_p95' => 800, 'error_rate' => 0.02],
        ],
        'monitored_providers' => [
            // Empty array = monitor all providers. Or specify:
            // 'ga4', 'gtm', 'meta', 'plausible', 'posthog',
        ],
    ],

    /*
    |-------------------------------------------------------------------------- 
    | Governance Runtime Validator (v160.0.0)
    |-------------------------------------------------------------------------- 
    |
    | Runtime validation of analytics events against the EventCatalog.
    | Detects unknown events, category mismatches, provider mapping gaps,
    | and deprecated events at dispatch time. Non-blocking — logs warnings.
    |
    | CLI:
    |   php artisan zb:analytics:governance-validate --health
    |   php artisan zb:analytics:governance-validate --sample
    |   php artisan zb:analytics:governance-validate --provider-gaps
    |
    */
    'governance_runtime' => [
        'enabled' => env('ANALYTICS_GOVERNANCE_RUNTIME_ENABLED', false),
        'check_provider_gaps' => env('ANALYTICS_GOVERNANCE_PROVIDER_GAPS', true),
        'auto_resolve' => env('ANALYTICS_GOVERNANCE_AUTO_RESOLVE', true),
        'max_log_size' => (int) env('ANALYTICS_GOVERNANCE_MAX_LOG', 1000),
        'deprecated_events' => [
            // 'old_event_name',
        ],
        'required_global_params' => [
            // 'client_id',
        ],
        'snapshot' => [
            'enabled' => env('ANALYTICS_GOVERNANCE_SNAPSHOT_ENABLED', false),
            'ttl' => (int) env('ANALYTICS_GOVERNANCE_SNAPSHOT_TTL', 86400), // 24 hours
        ],
    ],
];
