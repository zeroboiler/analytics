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
        | Event Timeline (v10.3.0)
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
    ],
];
