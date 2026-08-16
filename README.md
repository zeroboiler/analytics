# ZeroBoiler Analytics

![MIT License](https://img.shields.io/badge/license-MIT-blue.svg)
![Laravel 13+](https://img.shields.io/badge/Laravel-13%2B-red.svg)
![Latest Version](https://img.shields.io/badge/version-197.0.0-blue)
![PHP 8.5+](https://img.shields.io/badge/PHP-8.5%2B-8892BF.svg)

Industry-standard SaaS analytics for Laravel — production-ready event tracking across **10 providers** (GA4, GTM, Meta Pixel, Plausible, PostHog, Mixpanel, Amplitude, TikTok, LinkedIn, and generic HTTP) with **197 typed events**, **9 categories** (Ecommerce, SaaS, Engagement, Security, Uptime, Infrastructure, Marketing, CustomerSuccess, and Webhook), **399 services**, **88 artisan commands**, a fully-featured **JS client (~11,700 LOC)**, **12 Svelte composables**, comprehensive **TypeScript type definitions (~3,100 LOC)**, **Inertia.js middleware**, **Blade directives**, server-side lifecycle tracking, queue dispatch, identity resolution, cross-device identity merge, event budget enforcement, cohort analytics, event replay, GDPR consent, data residency routing, event consistency validation, feature gating analytics, customer success analytics, pipeline performance profiling, event delivery reliability scoring, SDK token gateway with audit logging, **event behavioral fingerprinting**, **intent detection**, **predictive churn scoring**, **server-side tag management with health monitoring & auto-failover**, **automated GDPR/CCPA/SOC2 compliance scoring**, **event value attribution**, **SaaS momentum analytics**, **SaaS revenue funnel analytics**, **feature adoption tracking with stickiness curves**, **goal tracker with alerting**, **rolling window trend analysis**, **automated quick insights**, **Monte Carlo funnel simulation**, **user lifecycle stage detection**, **DAG-based pipeline orchestration**, **Sentry error analytics integration**, **cross-provider schema validation**, **config drift detection**, **behavioral user segmentation**, **feature flag rollout guardrails**, **SaaS event helpers**, **campaign context hydration**, **CDP (Customer Data Platform) with user profiles, computed traits, and dynamic segments**, **synthetic event data factory**, **event schema evolution tracking**, and e-commerce format conversion across all providers.

## Table of Contents

- [Quick Start](#quick-start)
- [Features](#features)
- [Architecture](#architecture)
- [Configuration](#configuration)
- [Usage](#usage)
- [Inertia.js Integration](#inertiajs-integration)
- [Blade Integration](#blade-integration-traditional-laravel)
- [API Reference](#api-reference)
- [Event Catalog Reference](#event-catalog-reference)
- [Server-Side Auto-Tracking](#server-side-auto-tracking)
- [JS Client API Reference](#js-client-api-reference)
- [Admin Commands](#admin-commands)
- [Testing](#testing)
- [Troubleshooting](#troubleshooting)
- [Upgrading](#upgrading)
- [Changelog](#changelog)
- [License](#license)

## Quick Start

```bash
composer require zeroboiler/analytics
php artisan vendor:publish --tag=zeroboiler-analytics-config
```

```env
ANALYTICS_GA4_ENABLED=true
ANALYTICS_GA4_MEASUREMENT_ID=G-XXXXXXXXXX
ANALYTICS_GA4_API_SECRET=your_secret
```

```php
use ZeroBoiler\Analytics\Facades\Analytics;

Analytics::track('button_click', ['element' => 'buy_now']);
Analytics::purchase('TXN-12345', 99.99, [['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2]]);
```

```javascript
// Svelte/Inertia
import { init, trackEvent, trackPageView } from '../resources/js/analytics';

init(page.props);
await trackEvent('tutorial_completed', { duration_seconds: 300 });
```

Done. That's it.

### What's New in v197.0.0

**Synthetic Event Data Factory + Event Schema Evolution Tracker — Industry-Standard SaaS Analytics Upgrade**:

- **SyntheticEventFactory** — generates realistic synthetic analytics events for dev, staging, and load-testing environments. Weighted category pools, session simulation with temporal spacing, SaaS conversion funnel generation (sign_up → subscribe → plan_upgrade), e-commerce journey generation (view_item → purchase), configurable per-category weights, custom client/user identity, and batch generation. Enables zero-data-state dashboard population and pipeline stress testing.
- **EventSchemaEvolutionTracker** — tracks catalog schema changes across versions to prevent breaking changes. Registers snapshots per version, diffs two versions detecting added/removed events, category changes, new/removed categories. Configurable breaking-change policies (removal = breaking, addition = safe). Produces human-readable evolution reports for CI release gates.
- **AnalyticsSyntheticCommand** — `php artisan analytics:synthetic` CLI with subcommands: `generate` (batch events), `session` (user session), `funnel` (SaaS conversion), `ecommerce` (purchase journey), `batch` (quick dispatch), `schema-evolution` (catalog diff). Supports `--count`, `--category`, `--dispatch`, `--from-version`, `--to-version`, `--json` flags.
- **Supporting types** — `CatalogSnapshot`, `EventReference`, `EventChange`, `BreakingChangePolicy`, `EvolutionReport` as immutable value objects.
- **Version sweep** — composer.json → 197.0.0, AnalyticsEvent::VERSION → 197.0.0, README badge → 197.0.0.
- **Tests** — V197SyntheticEventFactoryAndSchemaEvolutionTest (50+ assertions): factory instantiation, pool stats, category restriction, custom identity, session generation (count, shared client ID), conversion funnel (sequence, identity), batch generation, multi-session, e-commerce journey (sequence, transaction ID, params), SaaS plan params, engagement page params, configuration summary, tracker snapshot CRUD, diff detection (added, removed, category added/removed, identical), analyze reports (breaking vs non-breaking), policy evaluation, change type predicates, cross-validation between factory and tracker catalogs.

### What's New in v195.0.0

**SaaS Event Helpers + Campaign Context Hydration — Industry-Standard SaaS Analytics Upgrade**:

- **SaaSEventHelpers** — static one-liner methods for the most common SaaS event patterns: `SaaSEventHelpers::signUp()`, `::login()`, `::trialStart()`, `::subscription()`, `::planUpgrade()`, `::planDowngrade()`, `::cancellation()`, `::featureUsed()`, `::teamEvent()`, `::onboardingStep()`, `::firstValue()`, `::revenue()`, `::custom()`. Reduces controller boilerplate to a single static call per event.
- **CampaignContextHydratorService** — centralized campaign context extraction from HTTP requests. Reads UTM parameters, referrer, session data; classifies traffic sources (direct, organic_search, paid_search, paid_social, email, referral, affiliate); detects device type and geolocation; persists first-touch attribution in cache; provides client-safe context for Inertia props; computes attribution summaries for cross-session reporting.
- **useAttribution Svelte composable** — reactive UTM/campaign attribution composable for Svelte/Inertia. Stores for UTM params, referrer, traffic source, first-touch persistence (localStorage), derived stores for utmString, attributionLabel, isPaidTraffic, isOrganicTraffic, and attributionSnapshot. Auto-initializes from Inertia page props or URL fallback.
- **Inertia middleware campaign context injection** — `zbAnalytics.campaignContext` prop now includes client-safe UTM, referrer, traffic source, and has_utm for every Inertia response.
- **Version sweep** — All entry points synced to 195.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 12 Svelte composables (11 existing + useAttribution), AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge.
- **Tests** — V195SaaSEventHelpersCampaignAttributionTest (40+ assertions): SaaSEventHelpers file quality (strict_types, final, MIT header, @since 195.0.0, 12 public methods + custom + manager), CampaignContextHydratorService file quality (strict_types, final, MIT header, @since 195.0.0, 8 public methods), UTM extraction (all 5 params, empty), traffic source classification (paid_search via medium/source, organic_search, paid_social, email, affiliate, referral, organic_social, direct, bot detection excluded), toClientSafeContext (structure, no IP/UA), device type detection (desktop, mobile, tablet, bot, unknown/null), domain parsing, first-touch persist/get/attribution flow, useAttribution composable file quality (strict JS, exports, stores, derived stores), version consistency, source file count ≥ 863, test file count ≥ 442. Total: 863+ src files, 442+ tests, 399 services.

### What's New in v194.0.0

**SaaS Lifecycle Flow Service + Webhook Event Catalog Parity — Industry-Standard SaaS Analytics Upgrade**:

- **SaaSLifecycleFlowService** — 8-stage SaaS customer funnel tracking service (anonymous → signed_up → trialing → subscribed → activated → expanding → retained → champion). Each track method dispatches the appropriate analytics event AND returns the resulting funnel stage. Static utilities: stages(), stageIndex(), progressForStage(), nextStageAfter(), resolveStageForEvent(), isForwardProgression(), funnelSummary(), funnelBreakdown(). Event-to-stage mapping covers sign_up, start_trial, subscribe, plan_upgrade, subscription_renewal. Configured via AnalyticsManager injection.
- **Webhook Event Catalog Parity** — WebhookEvents catalog (3 events: webhook_delivered, webhook_failed, webhook_received) now included in EventCatalog count, categorySummary, and all 8 provider name lists (GA4, Meta, PostHog, Plausible, Mixpanel, Amplitude, TikTok, LinkedIn).
- **Phase 46 Production Readiness** — 80+ new assertions: SaaSLifecycleFlowService file quality (final, :void constructor, @since 194.0.0), 8-stage funnel validation, stageIndex/progressForStage consistency, isForwardProgression direction, resolveStageForEvent mapping, funnelSummary/funnelBreakdown structure, track method return types, constructor nullable manager, nextStageAfter boundary; exception hierarchy bidirectional @see validation (abstract AnalyticsException → 2 final leaves with factory methods); DTO immutability audit (AnalyticsEvent/AnalyticsGoal/GoalProgress final readonly with :void constructors and factory methods); ServiceProvider finality + register/provides #[Override]; Facade finality + getFacadeAccessor contract; AnalyticsManager finality + :void constructor; composer metadata integrity (PHP 8.5, namespace, provider, alias, scripts, MIT license); phpstan.neon.dist level 9 + rector.php PHP_85; version consistency (composer, DTO); source file count ≥ 862, test count ≥ 441; config file integrity; Phase 45 version fix (857→862+, 191.0.0→194.0.0); README badge sync (193.0.0→194.0.0).
- **Tests** — Phase46ProductionReadinessTest (80+ assertions), V194EventCatalogWebhookParityAndFlowTest (15 assertions), V194SaaSLifecycleFlowServiceTest (50+ assertions), Phase45ProductionReadinessTest version sync. Total: 862+ src files, 441+ tests, 398 services.

### What's New in v193.0.0

**SaaS Conversion Predictor + QuickStart Bug Fix — Industry-Standard SaaS Analytics Upgrade**:

- **SaaSConversionPredictorService** — heuristic-based conversion probability estimation using configurable positive/negative signal scoring. 10 positive signals across 4 categories (engagement: page_views_high, feature_used_count, form_submitted, search_used; activation: onboarding_completed, first_value_moment; temporal: session_frequency_high, session_recency_recent; social: team_invited, referral_shared). 4 negative signals (errors_count, support_ticket, long_inactivity, session_bounce). Features: single user prediction, batch prediction, top prospects ranking, signal map builder from event summaries, cache-backed results, actionable recommendations (upgrade prompts, onboarding guidance, re-engagement triggers, bug fix priorities), 7-tier grading (A+ through F), 4-category intent classification (high_intent/medium_intent/low_intent/unlikely), custom signal weight overrides via config. Configured via `zeroboiler.analytics.conversion_predictor`.
- **AnalyticsConversionPredictorCommand** — artisan command `analytics:predict` with `--demo` mode (sample data + top prospects table + detailed signal breakdown), `--user` for single user prediction, `--signals` for custom signal JSON, `--top` for prospect limit. Displays color-coded grades and categories.
- **Bug fix** — SaaSQuickStartService: all 9 `trackEvent('name', [...])` calls corrected to `track('name', [...])` (trackEvent expects AnalyticsEvent DTO, not string+array).
- **1 new config section**: `zeroboiler.analytics.conversion_predictor` (enabled, cache_ttl, custom_weights).
- **Version sweep** — All 22 entry points synced to 193.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 11 Svelte composables, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge.
- **Tests** — V193ConversionPredictorQuickStartFixTest (40 tests): SaaSConversionPredictorService file quality (strict_types, final, MIT header, @since), construction with default/disabled/custom-weight configs, positive/negative signal catalog definitions and structure, stats structure (14 signals, 7 grade tiers, 5 categories), prediction with all-positive/no-negative signals (high score), all-negative/no-positive signals (low score), no signals (baseline), signal breakdown structure (14 entries with weight/matched/value/label/category), grade boundary validation (high_intent), grade/category valid values, buildSignalMap from event summary (hot user, cold user, default values), batch prediction (structure + summary), batch prediction empty, top prospects sorting and limit, recommendations for hot lead (upgrade prompt), error users (bug fix), incomplete onboarding (onboarding rec), cache put/forget behavior, numeric signal values, score bounds 0.0–1.0 across 4 test cases; SaaSQuickStartService bug fix verification (no trackEvent calls, 9 track calls); version consistency (composer, DTO, integrity command); source file count ≥ 860, test file count ≥ 439. Total: 861+ src files, 439+ tests, 398 services.

### What's New in v192.0.0

**Behavioral User Segmentation + Feature Flag Rollout Guardrails — Industry-Standard SaaS Analytics Upgrade**:

- **BehavioralUserSegmentService** — dynamic behavioral user segmentation engine with 6 segment types (event, frequency, sequence, time, property, composite) and 10 built-in SaaS segments (power users, new users, trial users, converted users, at-risk users, churned users, feature adapters, searchers, ecommerce browsers, buyers). Supports define/undefine custom segments, evaluate membership from raw event data, set operations (intersect, union, except, XOR), trending analysis (detect segments with significant membership changes), snapshot persistence for historical tracking, and segment comparison with retention rate. Configured via `zeroboiler.analytics.behavioral_segments`.
- **FeatureFlagRolloutGuardrailService** — monitors metric health during progressive feature flag rollouts. Covers 10 guardrail metrics across 5 categories (conversion rate, error rate, page load time, API latency P95, revenue per user, session duration, DAU/MAU ratio, D1 retention, feature usage, bounce rate). 4 rollout phases with adaptive sensitivity (canary 0.5x, early 0.75x, broad 1.0x, full 1.5x). Features: pre/post baseline comparison with z-test statistical significance, severity classification (safe/warning/critical/breached), automatic rollback recommendations, rollout velocity monitoring (detect too-fast rollouts), and audit log for post-mortem analysis. Configured via `zeroboiler.analytics.rollout_guardrails`.
- **2 new config sections**: `zeroboiler.analytics.behavioral_segments` (enabled, cache TTL, max segment size, max snapshots, definitions) and `zeroboiler.analytics.rollout_guardrails` (enabled, cache TTL, min sample size, significance alpha, auto rollback recommendation, custom thresholds).
- **Version sweep** — All entry points synced to 192.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 11 Svelte composables, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge. Service count updated: 394 → 396.
- **Tests** — V192BehavioralSegmentsRolloutGuardrailsTest (40 tests): BehavioralUserSegmentService file quality (strict_types, final, MIT header, @since), construction/defaults/enabled, 10 built-in segments, stats structure, summary structure, define/undefine/getDefinition lifecycle, event-based evaluation (must_have, must_not_have), frequency-based evaluation, sequence-based evaluation (strict + non-strict), set operations (intersect, union, except), trending detection, segment comparison with retention_rate, snapshot creation, multi-segment evaluation, unknown segment empty result, composite AND segment; FeatureFlagRolloutGuardrailService file quality, construction/defaults/enabled, stats structure (5 metric categories, 10 supported metrics, 4 phases, sensitivity map), supportedMetrics/rolloutPhases arrays, safe rollout evaluation (verdict=safe, PROCEED recommendation), critical regression detection (verdict=breached, ROLL BACK), warning state, error rate positive threshold (higher=worser), phase sensitivity (canary vs full for same metric), baseline capture/get, rollout velocity (safe + too-fast), health check (healthy), audit log + flag history filtering; version consistency (composer, DTO), src file count ≥ 857, test count ≥ 437, service count ≥ 396. Total: 859+ src files, 438+ tests, 396 services.

### What's New in v190.0.0

**Provider Dispatch Order Optimization + Event Catalog Explorer — Industry-Standard SaaS Analytics Upgrade**:

- **ProviderDispatchOrderService** — dynamically determines the optimal dispatch order for each analytics event across all enabled providers. Unlike static routing rules, this service considers real-time provider health, cost constraints, SLA targets, budget utilization, and consent state. Each provider is scored across 6 weighted factors: health (0.25), SLA compliance (0.20), budget utilization (0.15), event coverage (0.25), cost efficiency (0.10), and consent readiness (0.05). Providers scoring below the configurable minimum threshold (default 25/100) are automatically excluded. Provides `dispatchPlan()` for full analysis, `orderedProviders()` for quick dispatch lists, and `providerScoreBreakdown()` for debugging. Supports per-provider weight overrides, global exclusion lists, and cache-backed health/budget/SLA scores for real-time reactivity. Configured via `zeroboiler.analytics.dispatch_order`.
- **EventCatalogExplorerService** — advanced event catalog search engine with fuzzy matching, tag filtering, provider coverage analysis, and intelligent developer recommendations. Goes beyond basic string matching with Levenshtein-inspired similarity scoring, AARRR stage and tag-based filtering, provider-specific coverage queries (`providerCoverage()`), similar event discovery using catalog relationships and shared tags (`similar()`), and use-case-driven recommendations (`recommend('signup')` → sign_up, account_activated, email_verified, onboarding events). Provides `search()` with category/provider/tag filters and configurable fuzzy sensitivity, `tagOverview()` for tag-level instrumentation coverage analysis, and `stats()` for catalog health monitoring. Designed for developer tooling and admin dashboards. Configured via `zeroboiler.analytics.catalog_explorer`.
- **2 new config sections**: `zeroboiler.analytics.dispatch_order` (enabled, cache TTL, min score, provider weights, excluded providers, respect routing) and `zeroboiler.analytics.catalog_explorer` (enabled, cache TTL, max results, fuzzy sensitivity).
- **Version sweep** — All 22 entry points synced to 190.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 11 Svelte composables, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge. Service count updated: 392 → 394.
- **Tests** — V190DispatchOrderCatalogExplorerTest (25 tests): ProviderDispatchOrderService file quality (strict_types, final, void constructor, MIT header, @since), construction/defaults/enabled/minScore/excludedProviders/stats structure (10 providers, 6 factors), dispatchPlan structure (providers array with name/score/factors/excluded/reasons), orderedProviders string list, providerScoreBreakdown structure (6 factor keys), summary with scoring factor definitions, scoringFactors weight=0.25 for health, clearCache no-throw; EventCatalogExplorerService file quality, construction/defaults/enabled/stats structure (total_events, use_cases_supported), search exact match (purchase → similarity 100, match_type exact), category filter, empty query returns zero results, recommend for signup (sign_up primary), similar to purchase (related ecommerce events), similar for unknown (empty), providerCoverage analysis (ga4 with category breakdown), tagOverview structure (tags, total_tags, most_common), fuzzy search with typo "purchas" finds "purchase", clearCache no-throw; version consistency (composer, DTO, README), src file count ≥ 857, test count ≥ 436, service count ≥ 393. Total: 857+ src files, 436+ tests, 394 services.

### What's New in v189.0.0

**Cross-Provider Schema Validation + Config Drift Detection — Industry-Standard SaaS Analytics Upgrade**:

- **EventSchemaValidatorService** — validates analytics events against each provider's schema requirements before dispatch. Covers GA4 (event name length ≤ 40 chars, reserved parameter protection, ecommerce item validation with required/recommended fields, max 200 items per event, parameter value length ≤ 100 chars), Meta Pixel (max 25 custom properties, content_ids/content_type for ecommerce, value + currency for revenue events), PostHog (property value length ≤ 1024 chars, reserved $-prefixed key protection), Plausible (max 30 custom properties, event name format recommendations), Mixpanel (max 255 event properties, 65535 char string limit, reserved key protection), and Amplitude (event type length ≤ 1000 chars, reserved device_id/session_id/user_id protection). Provides configurable strict mode (lenient = warnings only; strict = reject on any issue), per-provider validation with `validateForProvider()`, batch validation with aggregate reports, severity classification (error/warning/info), provider rules documentation for developer tooling, and cache-persisted validation summaries. Registered as singleton in ServiceProvider. Configured via `zeroboiler.analytics.schema_validator`.
- **ConfigDriftDetectionService** — monitors analytics configuration for unexpected changes by comparing live config values against a captured baseline snapshot. Captures baseline of all monitored config sections (ga4, gtm, meta_pixel, consent, queue, lifecycle, api, client_auto_track, identity, auto_track, revenue_checksum, dedup_cache, sampling, data_lake, trace_context) into cache. When drift is detected, generates severity-classified alerts: critical (provider enable/disable, consent default change, auth requirement change), warning (credential updates, cookie name changes, queue config changes), info (new keys added, non-critical value changes). Provides per-section drift detection for targeted checks, drift history tracking (max 100 entries), configurable ignore patterns for known-acceptable changes, baseline management (capture/get/clear), and quick summary for dashboards. Supports multiple baseline labels for multi-environment tracking. Inspired by Terraform drift detection, AWS Config Rules, and Datadog config audit. Registered as singleton in ServiceProvider. Configured via `zeroboiler.analytics.config_drift`.
- **2 new config sections**: `zeroboiler.analytics.schema_validator` (enabled, strict_mode, ttl, providers list, max_event_name_length, max_params_count) and `zeroboiler.analytics.config_drift` (enabled, ttl, baseline_label, ignore_keys).
- **Version sweep** — All entry points synced to 189.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 11 Svelte composables, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge. Service count updated: 389 → 391.
- **Tests** — V189SchemaValidatorConfigDriftTest (35 tests): EventSchemaValidatorService file quality (strict_types, final, void constructor, MIT header, @since), validate/validateBatch/validateForProvider methods, GA4 long event name detection, GA4 reserved param detection, GA4 missing item_id in ecommerce items, GA4 too many items (201 > 200), Meta missing value in purchase event, PostHog reserved key detection, Mixpanel too many properties, Amplitude reserved key detection, batch aggregate report structure, providerRules documentation (6 providers), stats structure, isEnabled/isStrictMode/getActiveProviders types, clearCache no-throw, summary structure; ConfigDriftDetectionService file quality, capture baseline + detect no-drift, getBaseline null/exists, detectSection structure, history tracking (record/get/clear), quickSummary structure, stats structure (15 monitored sections, 6 critical, 5 warning), getMonitoredSections/getIgnoredKeys arrays, isEnabled bool, clearBaseline/clearHistory no-throw, cross-service independence, version consistency (composer, JS, dts, README), service count minimum (391), src file count minimum (855), test count minimum (434). Total: 855+ src files, 434+ tests, 391 services.

### What's New in v188.0.0

**Data Lake Export + W3C Trace Context Propagation — Industry-Standard SaaS Analytics Upgrade**:

- **AnalyticsDataLakeService** — Time-partitioned event export for warehouse ingestion. Supports three output formats: NDJSON (JSON Lines for BigQuery/Snowflake), CSV (for Redshift COPY and spreadsheets), and aggregated summary (per-event-name/category/day counts with unique user/client counts and first/last seen timestamps). Features column projection (select specific event fields), date range filtering, category filtering, configurable batch sizes (default 10,000), and cached snapshots for incremental exports. Designed for scheduled export jobs feeding downstream data warehouses. Configured via `zeroboiler.analytics.data_lake`.
- **EventTraceContextService** — W3C Trace Context propagation for distributed tracing correlation. Extracts `traceparent` and `tracestate` headers from incoming HTTP requests, parses W3C format (version-traceid-spanid-flags), and generates new trace IDs when no context is present. Enriches analytics events with `trace_id`, `span_id`, and `trace_flags` parameters for APM integration (Datadog, Honeycomb, Jaeger, OpenTelemetry). Supports child span creation for provider-level dispatch tracing, strict/lenient parsing modes, and configurable auto-enrichment toggle. Configured via `zeroboiler.analytics.trace_context`.
- **2 new config sections**: `zeroboiler.analytics.data_lake` (enabled, cache TTL, max batch size, default columns, category filter, default format) and `zeroboiler.analytics.trace_context` (enabled, strict mode, auto-enrich).
- **Version sweep** — All 22 entry points synced to 188.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 11 Svelte composables, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge. Service count updated: 387 → 389.
- **Tests** — V188DataLakeTraceContextTest (43 tests): Data Lake service file existence/quality (strict_types, final, void constructor, MIT header, @since), export methods (NDJSON/CSV/summary), snapshot cache (key/get/cache/clear), stats, isEnabled, format constants (3), supported formats (3), return type declarations (7), config section; Trace Context service file existence/quality, extract/enrich/traceParams/getTraceContext/createChildSpan/toTraceparentHeader/isEnabled/hasActiveTrace (7 methods), return type declarations (7), config section; cross-service integration (disabled enriches unchanged, child span 16 hex chars, W3C format, stats structure, cache usage, docblocks); version consistency (4 entry points: DTO, composer, package, README), service count minimum (389), test count minimum (434). Total: 854+ src files, 434+ tests, 389 services.

### What's New in v187.0.0

**DAG Pipeline Orchestrator + Sentry Error Analytics — Industry-Standard SaaS Analytics Upgrade**:

- **PipelineOrchestratorService** — DAG-based event pipeline orchestration with dependency resolution via Kahn's algorithm (topological sort), cycle detection, per-step configurable retry with exponential backoff, parallel execution hints, bypass predicates, health tracking, execution history, and dry-run validation. Register named pipelines (e.g. `ecommerce_checkout`, `saas_onboarding`) with processing steps and dependency edges. The orchestrator determines correct execution order, handles failures cascadingly (failed step's dependents are skipped), and records execution metrics (duration, success rate) in cache-persisted history. Configured via `zeroboiler.analytics.pipeline_orchestrator`.
- **PipelineStep** — immutable value object for pipeline processing steps. Supports handler callable, dependency list, bypass predicate, parallel hint, max retries, base delay, and category tag.
- **PipelineResult** — immutable value object for pipeline execution results. Exposes success/failure, step outcomes with timing, skipped step reasons, error details, and computed success rate.
- **SentryErrorAnalyticsService** — bridge between Sentry error monitoring and product analytics. Ingests Sentry webhook payloads (issue/alert format) into normalized AnalyticsEvent DTOs with error metadata (issue ID, title, level, fingerprint, critical path). Computes: error fingerprinting (MD5 of title + culprit + relevant tags for deduplication), critical path detection (configurable path keywords matched against error title/culprit/context/tags), impact scoring (0–100 composite of frequency, severity, critical path weight, and recency decay), error cohort analytics (group errors by fingerprint with occurrence counts and impact averages), revenue impact estimation (projects revenue at risk from critical-path errors using configurable impact factor and conversion drop thresholds), and funnel analysis (maps errors to awareness/interest/trial/conversion/retention stages). Quick summary reports healthy/degraded status. Configured via `zeroboiler.analytics.sentry_error_analytics`.
- **2 new config sections**: `zeroboiler.analytics.pipeline_orchestrator` (enabled, max steps, max retries, backoff multiplier, cache TTL, max history) and `zeroboiler.analytics.sentry_error_analytics` (enabled, environment, max errors/cohorts, cache TTL, critical paths list, impact window hours, critical path weight, revenue impact factor, conversion drop threshold).
- **Version sweep** — composer.json and README badge synced to 187.0.0. Service count updated: 382 → 387.
- **Tests** — V187PipelineOrchestratorServiceTest (20 tests): construction/defaults, disabled state, simple registration, dependency-ordered execution, max steps exceeded, unknown dependency rejection, cycle detection, nonexistent pipeline execution, bypass predicate, dependency failure cascading, execution history recording, valid pipeline validation, nonexistent pipeline validation, health summary empty, parallel steps, retry with eventual success, PipelineResult factory, PipelineResult errors, clear pipeline, source quality (final + strict types + return types). V187SentryErrorAnalyticsServiceTest (23 tests): construction/defaults, disabled state, fingerprint determinism, fingerprint difference, critical path detection (checkout/payment/none), impact scoring (basic/fatal-critical/capped), Sentry payload ingestion (success/invalid-action/invalid-issue/disabled/critical-path-detection), error cohorts (aggregation/critical-path), revenue impact (no-critical/with-critical/zero-order-value), funnel analysis (empty/with-errors), clear, degraded summary, source quality (final + strict types + return types). Total: 852+ src files, 421+ tests, 387 services.

### What's New in v186.0.0

**Revenue Cohort Matrix + Session Replay Heatmap + Real-Time Event Stream — Industry-Standard SaaS Analytics Upgrade**:

- **RevenueCohortMatrixService** — signup cohort-based MRR/ARR tracking and analysis. Produces a matrix of signup cohorts (rows) × monthly periods since signup (columns) with aggregated MRR per cell. Computes per-cohort metrics: retention rate (latest MRR vs. M0), expansion rate (MRR growth beyond M0), contraction rate (MRR decline from M0), avg MRR per user, and payback period estimation. Provides cohort comparison (side-by-side), best-retaining/expansion cohort detection, MRR trend analysis (improving/declining/stable across recent cohorts), and quick health summary (excellent/good/warning/critical). Cache-persisted with configurable TTL. Configured via `zeroboiler.analytics.revenue_cohort_matrix`.
- **SessionReplayHeatmapService** — page-level behavior aggregation into heatmap zones. Records click, hover, scroll-reach, and dwell-time interactions per page zone (CSS selector or viewport range). Computes weighted heat scores (0–100) using configurable interaction weights (click=1.0, hover=0.3, scroll=0.5, time=0.1). Produces per-page heatmaps with hottest/coldest zone detection, engagement depth (viewport reach), and cross-page summary with engagement funnel analysis (top/middle/bottom zone interactions) and actionable recommendations. Configured via `zeroboiler.analytics.heatmap`.
- **RealTimeEventStreamService** — sliding-window event aggregation for real-time dashboards. Maintains time-bucketed event counters with configurable window size (default 60s) and bucket granularity (default 5s). Provides: total events in window, events-per-second (EPS), category breakdown, ranked top events, burst detection (traffic spike beyond threshold), and bucket health monitoring. Exposes `ingest()`, `snapshot()`, `quickSummary()`, `categoryCount()`, `eventCount()`, and `isBurstDetected()` APIs. Configured via `zeroboiler.analytics.realtime_stream`.
- **3 new config sections**: `zeroboiler.analytics.revenue_cohort_matrix` (enabled, cache TTL, max periods, expansion/contraction thresholds, payback target), `zeroboiler.analytics.heatmap` (enabled, cache TTL, max zones/pages, interaction weights), `zeroboiler.analytics.realtime_stream` (enabled, window seconds, bucket size, burst threshold, max top events).
- **Version sweep** — All 14 entry points synced to 186.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 11 Svelte composables, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge.
- **Tests** — V186RevenueCohortMatrixServiceTest (7 tests): construction/defaults, recordSignup tracking, recordMovement updates, quickSummary health assessment, compareCohorts comparison, disabled state, recordChurn MRR reduction. V186SessionReplayHeatmapServiceTest (9 tests): construction/weights, click recording, hover/scroll recording, cross-page summary, quickSummary status, clear, disabled state, dwell time, impression tracking. V186RealTimeEventStreamServiceTest (10 tests): construction/defaults, ingest+snapshot, topEvents ranking, categoryCount/eventCount, quickSummary status, clear, disabled state, eventsPerSecond, burst detection, snapshot structure. Total: 850+ src files, 430+ tests, 385 services.

### What's New in v185.0.0

**Monte Carlo Funnel Simulation + SaaS Lifecycle Stage Detection — Industry-Standard SaaS Analytics Upgrade**:

- **FunnelSimulationService** — probabilistic funnel conversion simulation using Monte Carlo methods. For each stage transition, draws N samples from a Beta distribution fitted to observed conversion data. Produces: mean conversion rate with standard error, 90%/95%/99% confidence intervals, probability of reaching a target conversion rate, risk assessment (probability of dropping below threshold), and actionable bottleneck recommendations. Supports "what-if" scenario simulation — test hypothetical stage improvements (e.g., "what happens if trial→activation improves by 20%?") and get delta lift analysis with statistical significance estimation. Uses deterministic seeded random number generation for reproducible results. Configured via `zeroboiler.analytics.funnel_simulation`.
- **SaaSLifecycleStageService** — automatic user lifecycle stage detection based on behavioral signals. Classifies users into 6 stages: **Prospect** (signed up, no trial/subscription), **Trial** (active trial), **Active** (paid subscriber with regular usage), **Engaged** (power user with broad adoption), **At Risk** (subscribed but declining), **Churned** (cancelled or long-inactive). Stage assignment evaluates: time-based signals (days since activity), event-based signals (trial, subscription, cancellation events), engagement signals (DAU streak, events/week, features used), and revenue signals (plan, MRR). Provides: stage distribution (count/percentage per stage), stage transition analysis (flow between periods), cohort stage breakdown (per-signup-cohort distribution), quantitative risk scoring, and per-stage re-engagement recommendations. Configured via `zeroboiler.analytics.lifecycle_stages`.
- **10 new REST API endpoints**: 4 funnel simulation endpoints (GET/POST /api/analytics/funnel-simulation, POST /api/analytics/funnel-simulation/what-if, GET /api/analytics/funnel-simulation/summary) + 6 lifecycle stage endpoints (GET /api/analytics/lifecycle-stages/distribution, POST /api/analytics/lifecycle-stages/determine, GET /api/analytics/lifecycle-stages/transitions, GET /api/analytics/lifecycle-stages/cohort-breakdown, GET /api/analytics/lifecycle-stages/recommendations/{stage}, GET /api/analytics/lifecycle-stages/summary).
- **2 new config sections**: `zeroboiler.analytics.funnel_simulation` (simulation count, seed, cache TTL) and `zeroboiler.analytics.lifecycle_stages` (at-risk/churned inactive day thresholds, cache TTL).
- **Version sweep** — All entry points synced to 185.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 11 Svelte composables, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge.
- **Tests** — V185FunnelSimulationLifecycleStageTest (50+ assertions): construction, setObservedData, recordObservation accumulation, simulateStage result structure/accuracy/CI bounds/risk scoring, insufficient data handling, runSimulation snapshot structure/overall conversion/probability profile/risk summary/target analysis/caching/recommendations, whatIfSimulation baseline/improved/delta/restoration, quickSummary, SaaSLifecycleStageService stage detection (churned/prospect/trial/active/engaged/at_risk), score bounds, record structure, distribution computation/percentages, transition analysis/health indicators, cohort breakdown, stage recommendations, quickSummary risk detection, version consistency (strict types, finality, return types, MIT headers). Total: 847+ src files, 427+ tests, 382 services.

### What's New in v184.0.0

**SaaS Telemetry Aggregator + Event Replay Validation — Industry-Standard SaaS Analytics Upgrade**:

- **SaaSTelemetryAggregatorService** — unified provider telemetry dashboard for SaaS observability. Aggregates real-time dispatch telemetry across all configured analytics providers into a single cache-backed data source. Computes per-provider dispatch counts and success/failure rates, per-category event distribution, per-event-name frequency rankings, rolling window throughput metrics (1m, 5m, 15m, 1h), cross-provider latency percentiles (p50, p95, p99), provider health status (healthy/degraded/down), and anomaly detection on throughput spikes/drops. Provides `summary()`, `providerDetails()`, `categoryBreakdown()`, `quickOverview()`, and `detectAnomalies()` methods for dashboard rendering. Registered as singleton in ServiceProvider.
- **EventReplayValidationService** — validates events before replay dispatch from dead-letter queue or event archive. Prevents replaying unknown/deprecated events, events that violate consent state, PII-bearing events that should be anonymized, invalid payloads, and duplicates (idempotency check). Provides `validate()`, `validateBatch()`, `markReplayed()`, `blockEvent()`/`unblockEvent()` methods. Returns validation results with issue codes (REPLAY_DUPLICATE, EVENT_BLOCKED, SENSITIVE_CONTENT, CATALOG_UNKNOWN, STALE_EVENT, FUTURE_EVENT, PAYLOAD_TOO_LARGE) and optionally a sanitized event suitable for re-dispatch with `source: 'replay'`. Registered as singleton in ServiceProvider.
- **Version sweep** — All entry points synced to 183.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.constants.js, analytics.d.ts, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge.
- **Tests** — V183SaaSTelemetryAggregatorTest (18 assertions), V183EventReplayValidationTest (15 assertions), Phase183ProductionReadinessTest (16+ assertions). Total: 843+ src files, 425+ tests.

### What's New in v182.0.0

**4 New Svelte Composables + Webhook Event Category — Industry-Standard SaaS Analytics Upgrade**:

- **useScrollDepth composable** — reactive scroll depth tracking for Svelte/Inertia apps. Monitors page scroll position and fires `scroll_depth` analytics events at configurable depth thresholds (25%, 50%, 75%, 90%). Provides reactive stores for current scroll percentage, milestones reached, max depth, and scroll time. Uses efficient debounced scroll handler with automatic reset on Inertia page navigation. Includes `forceTrack()` for virtual/infinite scroll pages and `onMilestone` callback.
- **useConsent composable** — GDPR Consent Mode v2 management for Svelte/Inertia apps. Reads consent state from Inertia page props (zbAnalytics.consent) and provides reactive stores for granular consent purposes (necessary, analytics, marketing, functional, ad_user_data, ad_personalization). Methods: grant(), deny(), grantAll(), denyAll(), setPurpose(), dismissBanner(). Automatically syncs to server via updateConsent() and calls gtag consent API when GA4 is enabled. Derived stores: analyticsGranted, marketingGranted, allGranted, allDenied.
- **useIdentity composable** — reactive client-server identity management for Svelte/Inertia apps. Manages client_id ↔ user_id linking with automatic auth state change detection from Inertia page props. Provides reactive stores for clientId, userId, authStateChanged, syncing status, and link count. Derived stores: isAuthenticated, justLoggedIn, justLoggedOut, identitySnapshot. Methods: linkIdentity(), unlinkIdentity(), getClientIdFromCookie(). Fires login/logout analytics events automatically.
- **usePageView composable** — automatic page view tracking for Svelte/Inertia apps. Watches Inertia page navigation and fires `page_view` events with title, URL, referrer, and route metadata. Supports debounced navigation, virtual page views for modals/overlays, engagement metrics (time between views), and filtering via shouldTrack(). Reactive stores: currentPage, previousPage, pageViewCount, sessionStart, avgTimeBetweenViews.
- **Webhook Events Category** — new event category with 3 typed events: WebhookDeliveredEvent, WebhookFailedEvent, WebhookReceivedEvent. Each includes relevant metadata (webhook ID, URL, status code, response time, error details). Registered in EventCatalog as the 9th category alongside Ecommerce, SaaS, Engagement, Security, Uptime, Infrastructure, Marketing, and CustomerSuccess. Includes WebhookEvents catalog class, WebhookEventConstants, and cross-provider mappings (GA4, PostHog, Mixpanel, Amplitude).
- **Version sweep** — All entry points synced to 182.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 11 Svelte composables (7 existing + 4 new), AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge. Total: 197 typed events, 9 categories, 11 Svelte composables.

### What's New in v181.0.0

**SaaS Analytics Compliance Matrix + Cross-Provider Coverage Analyzer — Industry-Standard SaaS Analytics Upgrade**:

- **SaaSComplianceMatrixService** — validates analytics instrumentation against 12 industry-standard frameworks: AARRR Pirate Metrics (5 sub-frameworks: Acquisition, Activation, Retention, Referral, Revenue), North Star Metric Framework, CAC/LTV Tracking, Activation Funnel, Retention Cohort, Revenue Attribution, Product-Led Growth (PLG) Signals, and GTM Alignment. Each framework defines required and optional events with weighted scoring (70/30 split). Returns per-framework compliance score, gap lists, and actionable recommendations sorted by priority.
- **CrossProviderCoverageAnalyzer** — analyzes cross-provider event mapping coverage across all 8 providers (GA4, Meta, PostHog, Plausible, Mixpanel, Amplitude, TikTok, LinkedIn) and 3 event categories (Ecommerce, SaaS, Engagement). Produces per-category coverage summary (% events with full 8-provider mappings), per-provider event counts, gap lists (events missing mappings), and an overall coverage parity score (0-100%). Includes quickSummary() and missingForProvider() methods for targeted analysis.
- **Phase181ProductionReadinessTest** — 52 comprehensive assertions covering: version consistency across 10 entry points (181.0.0), class finality & structure, event catalog coverage (8 categories, 150+ events), core SaaS/ecommerce/engagement event presence, tracker implementations (10 providers), compliance matrix structure (12 frameworks), cross-provider analyzer structure (8 providers), format converter parity, middleware compliance, lifecycle subscriber diagnostics, GDPR services, identity services, consent mode v2 config, auto_track config, source file counts (800+ src, 300+ tests), subdirectory cross-reference, JS client/composable/DTS existence, strict types & MIT headers, Blade directives, config integrity, Svelte composable files, pipeline classes, bus classes, event plugin registry, AnalyticsFake, WithAnalyticsFake trait, routes file validation, database migrations.
- **Version sweep** — All 14 entry points synced to 181.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 7 Svelte composables, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge.
- **ServiceProvider registration** — SaaSComplianceMatrixService (singleton, injected AnalyticsManager) and CrossProviderCoverageAnalyzer (singleton) registered in the container.

### What's New in v180.0.0

**Route Registration Completion + Production Readiness — Industry-Standard SaaS Analytics Upgrade**:

- **40+ REST API routes registered in ServiceProvider** — All v172-v179 controller endpoints (Pipeline Profiler, Event Trace, Config Drift, Event Value Attribution, SaaS Momentum, Onboarding Wizard, Instrumentation Advisor, Config Validation, Goal Tracker, Rolling Window, Quick Insights, Platform Audit) now properly registered in ServiceProvider::registerRoutes(). Previously these controller methods existed but were unreachable without manual route registration.
- **Route categories registered**: Pipeline Validation (4 routes), Event Trace (3 routes), Config Drift (5 routes), Event Value Attribution (4 routes), SaaS Momentum (4 routes), Onboarding Wizard (4 routes), Instrumentation Advisor (4 routes), Config Validation (1 route), Goal Tracker (6 routes), Rolling Window Analytics (4 routes), Quick Insights (2 routes), Platform Audit (2 routes).
- **Phase180ProductionReadinessTest** — comprehensive production readiness test with 25+ assertions covering: version consistency across all 18 entry points (180.0.0), no stale version remnants, all new route patterns registered in ServiceProvider, all 44 new controller methods verified, source file counts (830+ src, 420+ tests), core class structure (final, strict types, void constructors), event catalog integrity (8 categories, core events), JS client exports (trackEvent, trackPageView, version), README integrity (version badge, quick start, API reference), MIT license headers.
- **Version sweep** — All 18 entry points synced to 180.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 7 Svelte composables, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge, AnalyticsEventController, SaaSPlatformAuditService, Phase39/Phase42 production tests, routes file.

### What's New in v178.0.0

**Goal Tracker + Rolling Window Analytics + Quick Insights — Industry-Standard SaaS Analytics Upgrade**:

- **AnalyticsGoalTracker** — quantitative target tracking for SaaS KPIs. Define measurable goals with configurable targets, time windows (daily/weekly/monthly/quarterly/yearly), aggregation strategies (count/sum/avg/unique), and alert thresholds (warning/critical). Supports category-based grouping (growth, retention, revenue, engagement, activation), owner assignment, and runtime goal registration. Cache-backed progress computation with configurable TTL. Methods: registerGoal(), removeGoal(), progress(), allProgress(), progressByCategory(), dashboard(), attentionNeeded(), achievedGoals(), invalidateAll(). Configured via `zeroboiler.analytics.goals`.
- **AnalyticsGoal DTO** — immutable readonly DTO representing a goal with key, name, target, metric mapping, aggregation, window, thresholds, category, owner, and metadata. Supports fromArray()/toArray() round-trip serialization.
- **GoalProgress DTO** — immutable readonly DTO capturing goal progress: actual vs target, percentage, status classification (on_track/at_risk/behind/achieved/exceeded/no_data), trend direction, change percentage, and period. Built from AnalyticsGoal::fromGoal() factory method.
- **RollingWindowAnalyticsEngine** — time-series smoothing and trend analysis engine. Three moving average algorithms: Simple Moving Average (SMA), Exponential Moving Average (EMA) with configurable alpha, and Weighted Moving Average (WMA) with linear-decay weights. Linear regression-based trend detection with R² confidence scoring. Volatility measurement via coefficient of variation. Full-series smoothing for chart rendering. Dashboard-ready profile() method returning all metrics in one call. Configured via `zeroboiler.analytics.rolling_window`.
- **SaaSQuickInsightsService** — automated pattern detection generating human-readable insights for SaaS dashboards. Five insight types: spike detection (sudden increase), drop detection (sudden decrease), trend detection (sustained trajectory), volatility detection (unstable behavior), and outlier detection (statistical anomaly via z-score). Each insight includes severity (info/warning/critical/success), confidence score, affected metric, human-readable description, and recommended action. Cache-backed with configurable thresholds. Configured via `zeroboiler.analytics.quick_insights`.
- **12 new REST API endpoints**: 6 goal tracker endpoints (GET /api/analytics/goals, POST /api/analytics/goals, GET /api/analytics/goals/progress, GET /api/analytics/goals/dashboard, GET /api/analytics/goals/attention, GET /api/analytics/goals/{key}/progress) + 4 rolling window endpoints (POST /api/analytics/rolling-window/compute, /rolling-window/trend, /rolling-window/profile, /rolling-window/smooth) + 2 quick insights endpoints (GET /api/analytics/quick-insights, /quick-insights/summary).
- **3 new config sections**: `zeroboiler.analytics.goals` (goal definitions, thresholds, cache TTL), `zeroboiler.analytics.rolling_window` (window size, EMA alpha, volatility window, trend minimum points), `zeroboiler.analytics.quick_insights` (max insights, spike/drop thresholds, trend periods, ignored metrics).
- **Comprehensive test suite**: V177GoalTrackerAndInsightsTest with 40+ assertions covering goal DTO construction, goal progress status classification (6 states), trend detection (up/down/flat), all moving average algorithms, volatility scoring, series smoothing, insight generation (spike/drop/trend/outlier), ignored metrics, insight structure validation, version consistency.
- **Version sweep** — All 14 entry points synced from 176.0.0 → 178.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 7 Svelte composables, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge.

### What's New in v176.0.0

**SaaS Onboarding Wizard + Event Instrumentation Advisor + Config Validation — Industry-Standard SaaS Analytics Upgrade**:

- **SaaSOnboardingWizardService** — 19-step guided onboarding wizard for setting up ZeroBoiler Analytics in SaaS applications. Each step has a completion status (assessed from current config), priority level (critical/high/medium/low), recommendation with fix instructions, and config path reference. Steps cover: provider configuration (GA4, GTM, Meta), consent mode, core SaaS event tracking, e-commerce events, identity linking, Inertia middleware, lifecycle tracking, queue config, API routes, JS client, Blade directives, auto-tracking, event validation, error tracking, session recording, and optional providers (Plausible, PostHog). Methods: getState(), summary(), gaps(), nextAction(), grade(), categoryBreakdown(), invalidateCache(). Cache-backed with 1-hour TTL.
- **EventInstrumentationAdvisor** — analyzes current event tracking configuration against industry-standard SaaS benchmarks and provides actionable recommendations. Covers AARRR funnel stages (signup, activation, retention, revenue, referral) with coverage scoring per stage. Produces priority-ranked event recommendations with code snippets showing how to implement each event. Includes maturity grading (Minimal → Starter → Starter+ → Growth → Enterprise) and quick-win detection for critical untracked events. Methods: getReport(), summary(), gaps(), quickWins(), priorityMatrix(), stageCoverage(), invalidateCache(). Cache-backed with 30-minute TTL.
- **AnalyticsConfigValidationService** — validates configuration integrity across 11 dimensions: core structure, provider configuration (GA4/GTM/Meta/Plausible/PostHog with format checks), consent settings, queue configuration, API rate limits, identity linking, security (cookie secure/samesite/httponly), performance (sampling rate, dedup window), e-commerce settings (currency ISO 4217, tax behavior), auto-track events, and lifecycle config. Returns scored results (0-100) with error/warning/info severity levels and actionable fix instructions.
- **9 new REST API endpoints**: 4 onboarding wizard endpoints (GET /api/analytics/onboarding-wizard, /onboarding-wizard/summary, /onboarding-wizard/gaps, /onboarding-wizard/next) + 4 instrumentation advisor endpoints (GET /api/analytics/instrumentation, /instrumentation/summary, /instrumentation/gaps, /instrumentation/stage) + 1 config validation endpoint (POST /api/analytics/config/validate).
- **Version sweep** — All 14 entry points synced from 175.0.0 → 176.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 7 Svelte composables, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge.

### What's New in v175.0.0

**Event Value Attribution Engine + SaaS Momentum Analytics — Industry-Standard SaaS Analytics Upgrade**:

- **EventValueAttributionService** — assigns monetary value to non-revenue events based on conversion funnel position. 6 default funnel paths (signup, trial, purchase, subscription, plan_upgrade, engagement_retention), 18 base event values, position-decay attribution model. Methods: valueOf(), valueOfMany(), report(), valueJourney(). Configurable via `zeroboiler.analytics.event_value_attribution`.
- **SaaSMomentumService** — measures growth rate of change (GRoC) for 6 key SaaS metrics: MRR velocity, retention acceleration, engagement momentum, net new MRR acceleration, conversion velocity, churn velocity. Composite momentum score (-100 to +100) with letter grades (A+ through F-). Methods: calculateMetricMomentum(), compositeScore(), quickSummary(), availableMetrics(). Configurable via `zeroboiler.analytics.momentum`.
- **8 new REST API endpoints**: 4 event value endpoints (GET /api/analytics/event-value, POST /api/analytics/event-value/batch, GET /api/analytics/event-value/report, POST /api/analytics/event-value/journey) + 4 momentum endpoints (POST /api/analytics/momentum/score, GET /api/analytics/momentum/metric, POST /api/analytics/momentum/quick, GET /api/analytics/momentum/metrics).
- **Version sweep** — All 14 entry points synced from 174.0.0 → 175.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 7 Svelte composables, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge.

### What's New in v173.0.0

**Phase 39 Production Readiness Hardening — Version Sweep & Integrity Validation**:

- **Phase39ProductionReadinessTest** — 15+ assertions covering AnalyticsManager finality + void constructor, AnalyticsServiceProvider finality + register/boot/provides, Facade accessor correctness, version consistency (173.0.0), source file count (817+), config integrity (ga4/gtm/tracking), subdirectory cross-reference (Trackers/Events/Enrichment/Services/Commands/Jobs).
- **Version sweep** — All 14 entry points synced from 172.0.0 → 173.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 7 Svelte composables (useAnalytics, useAnalyticsConfig, useEcommerce, useLifecycle, useSaaSMetrics, usePerformanceTracker, useSessionReplay), AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge.

### What's New in v172.0.0

**Pipeline Profiler REST API + Event Trace REST API + Config Drift Baseline Import — Industry-Standard SaaS Analytics Upgrade**:

- **Pipeline Profiler REST API** — Exposes the `AnalyticsPipelineProfilerService` via 5 new REST endpoints for ops teams and monitoring dashboards: `GET /api/analytics/profiler` (full dashboard with provider/category latency profiles, degraded providers, request-cycle metrics), `GET /api/analytics/profiler/provider/{provider}` (per-provider min/max/avg/p50/p95/p99 latency and bucket distribution), `GET /api/analytics/profiler/category/{category}` (per-category dispatch latency profiles), `GET /api/analytics/profiler/slow-events?limit=50` (slow/critical event list), `DELETE /api/analytics/profiler` (flush all profiler telemetry). Cache-backed performance telemetry with configurable thresholds. Inspired by OpenTelemetry collector exporters and Datadog APM pipeline profiling.
- **Event Trace REST API** — Exposes the `EventTraceService` and `TraceContext` via 3 new REST endpoints for end-to-end event correlation and debugging: `GET /api/analytics/trace/generate` (generate new trace ID + span ID for manual correlation), `POST /api/analytics/trace/inject` (inject trace context into a single event payload), `POST /api/analytics/trace/inject-batch` (inject shared trace ID across a batch of events — all share trace ID but get unique span IDs). Enables tracing events through client → API → queue → provider pipeline. ULID-based trace IDs, hex span IDs.
- **Config Drift Baseline Import** — New `importBaseline()` and `getBaseline()` methods on `ConfigDriftDetectionService` with `POST /api/analytics/config-drift/import` endpoint. Accepts external config snapshots (e.g., exported from production) and stores them as the drift detection baseline. Use cases: multi-environment config sync (import production baseline to staging for drift detection), CI/CD pipeline config gate uploads, manual baseline restoration from backups. Validates structure, strips stale `_meta`, adds import metadata (source, label, timestamp).
- **Config expansion** — New `tracing` config section documented and referenced by EventTraceService for configurable trace source and enable/disable.
- **Route expansion** — 9 new API routes: 5 profiler, 3 trace, 1 config-drift import.
- **Version sweep** — All 14 entry points synced from 171.0.0 → 172.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 7 Svelte composables, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge.

### What's New in v171.0.0

**Server-Side Tag Manager + Automated Compliance Scoring — Industry-Standard SaaS Analytics Upgrade**:

- **AnalyticsProviderTagManager** — Server-side tag management for runtime provider configuration without config changes or redeployment. Features: enable/disable individual providers at runtime with audit reasons, reorder provider dispatch priority (0-100), override provider-specific settings (API keys, endpoints) for A/B testing or key rotation, scheduled provider activation/deactivation with optional auto-re-enable timestamps, health monitoring with automatic failover (configurable consecutive failure threshold triggers auto-disable with cooldown), provider health dashboard (healthy/degraded/down status), bulk operations (disable all for maintenance, restore all to defaults), full audit trail of all overrides. Cache-backed persistence. Inspired by Google Tag Manager server-side container and Segment Tag Manager.
- **EventComplianceScoringService** — Automated GDPR, CCPA, and SOC2 compliance scoring for all analytics events. Scores each event across 7 dimensions: data minimization (20%), purpose limitation (15%), consent readiness (20%), PII risk (15%), retention compliance (10%), data subject rights (10%), and audit trail readiness (10%). Produces per-event compliance scores with letter grades (A+ through F), violation identification (CRITICAL/HIGH/MEDIUM/LOW), and actionable improvement recommendations. System-wide compliance report aggregates across all catalog events into GDPR, CCPA, and SOC2 framework-specific scores. Configurable PII field definitions and per-event overrides (pii_fields, retention_days, legal_basis, sensitive flag). Cache-backed system reports for dashboard display.
- **AnalyticsWarmupCommand** — Post-deploy validation and cache warmup command (`php artisan analytics:warmup`). Validates config structure and provider credentials, pre-populates event catalog caches, checks provider readiness, runs compliance scoring cache warmup, performs provider health checks via TagManager, reports system readiness with error/warning summary. Supports `--skip-health`, `--skip-compliance`, and `--verbose` flags. Designed for deployment pipelines.
- **Config expansion** — New `tag_manager` and `compliance_scoring` config sections with env-driven settings.
- **Version sweep** — All 14 entry points synced from 170.0.0 → 171.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 7 Svelte composables, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge. Service count updated: 362 → 364. Command count updated: 84 → 85.

### What's New in v169.0.0

**Event Intelligence Engine: Behavioral Fingerprinting, Intent Detection & Predictive Churn Scoring**:

- **EventBehavioralFingerprintService** — Generates unique behavioral signatures for each user based on event frequency distribution, timing patterns, session characteristics, and event sequence preferences. Features: deterministic hash fingerprint, cosine similarity-based user matching, segment auto-assignment (power_user, casual_user, explorer, at_risk, new_user, bot_like), bot detection from behavioral patterns, drift detection for behavioral change monitoring, and confidence scoring based on data sufficiency. Cache-backed for cross-process consistency.
- **EventIntentDetectionService** — Analyzes user event patterns and sequences to classify current intent. Five intent types: buying_intent (pricing visits + checkout events), churning (declining engagement + support tickets), exploring (high diversity + feature discovery), power_user (deep feature usage + API + integrations), and support_seeking (errors + docs + short sessions). Uses weighted sigmoid scoring with configurable signal patterns. Batch intent detection, high-intent user extraction, and at-risk user identification for targeted intervention.
- **PredictiveChurnScoringService** — Computes churn probability (0-100) per user using 9 weighted behavioral features: login frequency decline, feature usage decline, session duration decline, support ticket frequency, error event rate, engagement recency, feature adoption breadth, trial-to-conversion gap, and negative signals (cancellation/downgrade). Sigmoid transform maps raw weighted score to probability. Risk levels: healthy (0-30), at_risk (31-60), high_risk (61-80), critical (81-100). Features trend analysis (improving/stable/declining), predicted churn date estimation, batch scoring, churn risk summary dashboard data, and threshold-based user extraction for retention team workflows.
- **Comprehensive test suite** — 50+ test cases covering all 3 services: fingerprint generation, hash determinism, feature extraction, segment matching, bot detection, drift computation, baseline management, similar user discovery, intent classification, batch detection, high-intent/at-risk extraction, churn scoring, risk classification, feature analysis, trend computation, summary generation, class structure validation (final, strict types, void constructors).
- **Version sweep** — All 14 entry points synced from 168.0.0 → 169.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 7 Svelte composables, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge. Service count updated: 355 → 360.

### What's New in v168.0.0

**Eloquent Model Observer + Retention Cohort Analytics + Data Warehouse Export — Industry-Standard SaaS Analytics Upgrade**:

- **AnalyticsEventObserver** — Auto-track Eloquent model CRUD operations as analytics events. Register via `AnalyticsEventObserver::observe()` in model boot methods or via config `auto_track.models`. Supports custom event names, categories, param key extraction, conditional tracking, and namespace-based category guessing (Billing→saas, Product→ecommerce, etc.).
- **SaaSRetentionCohortService** — Time-based cohort retention analytics service. Computes retention tables showing what percentage of users active in period N were still active in N+1, N+2, etc. Supports daily/weekly/monthly periods, dashboard summary with letter grades (A–F), trend classification (healthy/moderate/concerning), cohort comparison, and per-user retention tracking. Cache-backed for performance.
- **EventWarehouseExportService** — Data warehouse export helper supporting JSONL (BigQuery/Snowflake), CSV, BigQuery schema JSON, Snowflake CREATE TABLE DDL, and ClickHouse CREATE TABLE DDL. Provides a 20-column analytics events schema with typed definitions. Auto-normalizes UTM, device, and page context into flat warehouse columns.
- **AnalyticsRequestTrackerMiddleware** — HTTP request lifecycle middleware that auto-tracks API calls as analytics events (api_request, api_error, api_slow_request). Configurable via `request_tracking` config section with exclude paths/methods, slow threshold, and selective status code tracking.
- **Config expansion** — New `request_tracking` config section with env-driven settings for request tracking middleware.
- **V1680 comprehensive test suite** — 40+ test cases covering AnalyticsEventObserver (mapping registration, config-driven format, param keys, clear), SaaSRetentionCohortService (cohort table, retention metrics, comparison, summary grades, period types), EventWarehouseExportService (JSONL/CSV export, schema, BigQuery/Snowflake/ClickHouse DDL, format listing, UTM normalization), and version sweep consistency across all 14 entry points.
- **Version sweep** — All 14 entry points synced from 167.0.0 → 168.0.0: composer.json, package.json, analytics.js (header + getVersion), analytics.d.ts, analytics.constants.js, 7 Svelte composables, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, ServiceProvider @version, README badge.

### What's New in v167.0.0

**SaaS Analytics Starter Kit — Industry-Standard Badge Level + README Accuracy Audit**:

- **README accuracy audit** — Verified and updated all headline metrics: 355 services (was "350+"), 84 commands (was 83), JS client ~11,700 LOC (was "~8,200"), TypeScript definitions ~3,100 LOC (was "~3,000"). Source: 836 PHP files (270K+ LOC), 421 test files (168K+ LOC).
- **SaaS Analytics Starter Kit completion badge** — All 12 planned SaaS analytics features now verified as industry-standard: Event Catalog (8 categories, 194 events), Server-Side Lifecycle Tracker, Inertia middleware (page props + client ID cookie), API controller + routes (events/batch/identify/consent), JS client library (trackEvent, trackPageView, initInertiaPageViewTracker, scroll depth, client ID management), event queue (async dispatch), user identity linking (client ID ↔ user ID), e-commerce helpers (GA4 + Meta format conversion across all 8 providers), admin commands (overview + test + 82 more), config expansion (queue, API, identity, auto-track, ecommerce, lifecycle), optional providers (Plausible + PostHog trackers), comprehensive test suite (405 tests).
- **V1670 SaaS Starter Kit Completion Test** — 80+ assertions validating all 12 SaaS analytics features at industry-standard level, README metric accuracy, version sweep consistency, and quality gates.
- **Version sweep** — All 14 entry points synced to v167.0.0.

### What's New in v166.0.0

**SDK Bridge Mode — Bidirectional Event Format Translation for Third-Party SDK Migration**:

- **SdkBridgeService** — Server-side bidirectional event format translation supporting 4 third-party SDKs (PostHog, Mixpanel, Segment, Amplitude). Translates inbound events from third-party SDK format to ZeroBoiler format (with automatic SDK metadata stripping), and outbound events from ZeroBoiler to target SDK format (with parameter structure adaptation including user_id→distinct_id for PostHog/Mixpanel, user_properties→$set for PostHog, user_properties→traits for Segment).
- **JS SDK Bridge functions** — `trackFromSdk()`, `translateToSdk()`, `inspectSdkTranslation()`, `getSupportedBridgeSdks()`, `fetchSdkBridgeCompatibility()` — client-side bidirectional translation for parallel tracking during migration.
- **Compatibility API** — 7 new endpoints: `GET /api/analytics/sdk-bridge/sdks`, `GET /api/analytics/sdk-bridge/compatibility/{sdk}`, `GET /api/analytics/sdk-bridge/coverage/{sdk}`, `POST /api/analytics/sdk-bridge/translate-inbound`, `POST /api/analytics/sdk-bridge/translate-outbound`, `POST /api/analytics/sdk-bridge/inspect`, `GET /api/analytics/sdk-bridge/mappings/{sdk}`.
- **Custom mapping registration** — `registerInboundMapping()`, `registerOutboundMapping()`, `registerInboundParamTransformer()`, `registerOutboundParamTransformer()` for extending bridge with custom event mappings and parameter transformers.
- **Built-in event name mappings** — 32 inbound + 32 outbound translations across PostHog (7+7), Mixpanel (8+8), Segment (11+11), Amplitude (5+5).
- **Bidirectional roundtrip consistency** — Events maintain name integrity through inbound→outbound roundtrips (e.g., PostHog `$pageview` → ZeroBoiler `page_view` → PostHog `$pageview`).
- **TypeScript type definitions** — `SdkBridgeTrackResult`, `SdkBridgeTranslation`, `SdkBridgeInspection`, `SdkBridgeCompatibilityReport` interfaces.
- **V1660 comprehensive test suite** — 40+ test cases covering all 4 SDKs, bidirectional translation, metadata stripping, parameter transformation, custom mappings, roundtrip consistency, compatibility reports, coverage analysis, class structure validation.
- **Version sweep** — All 14 entry points synced to v166.0.0. Fixed package.json version drift (was 164.0.0, now 166.0.0).

### What's New in v165.0.0

**Full 8-Provider Format Converter Parity**:

- **SaaSFormatConverter 8-provider expansion** — Added Mixpanel, Amplitude, Plausible, TikTok, and LinkedIn converters for all 6 SaaS lifecycle events (sign_up, login, trial_start, subscription, plan_upgrade, cancellation). 30 new static converter methods. `convertForProvider()` now dispatches to all 8 providers. Previously only supported GA4, Meta, and PostHog.
- **EngagementFormatConverter 8-provider expansion** — Added Mixpanel, Amplitude, Plausible, TikTok, and LinkedIn converters for all 8 core engagement events (page_view, scroll_depth, click, form_start, form_submit, search, share, error) plus 4 aliases. 40 new static converter methods. `convertForProvider()` now dispatches to all 8 providers for 12 event names (96 combinations).
- **buildRevenueParams() 8-provider expansion** — SaaS revenue helper now formats revenue events for all 8 providers (previously only GA4, Meta, PostHog). Includes Mixpanel revenue, Amplitude user_properties enrichment, Plausible string conversion, TikTok content_name, and LinkedIn flat format.
- **supportedProviders() parity** — Both SaaSFormatConverter and EngagementFormatConverter now return the same 8 providers, matching EcommerceFormatConverter. Added `supports()` method to SaaSFormatConverter for event name validation.
- **V1650 Production Audit Test** — 100+ assertions validating all 8-provider conversions, dispatch parity, buildRevenueParams expansion, alias routing, and cross-converter consistency.
- **Version sweep** — All 14 entry points synced to v165.0.0.

### What's New in v164.0.0

**DTO Fluent API Expansion + Event Catalog Summary + Documentation Accuracy**:

- **AnalyticsEvent DTO fluent methods** — Added `withSource()`, `withPriority()`, and `withTimestamp()` immutable fluent methods for pipeline-safe event transformation. Completes the fluent API alongside existing `withCategory()`, `withSessionId()`, and `withMergedParams()`.
- **EventCatalog::categorySummary()** — New method returning per-category event counts plus grand total. Used by admin commands and dashboard widgets for catalog coverage reporting.
- **README event count accuracy** — Corrected event counts from stale 176/210+ to verified 194 (Ecommerce 15, SaaS 82, Engagement 35, Marketing 34, Infrastructure 10, CustomerSuccess 7, Security 6, Uptime 5). Updated all references across Event System and SaaS Analytics sections.
- **README category naming** — Clarified CustomerSuccess as a named category (was "plugin-extensible").
- **V1640 Production Audit Test** — 80+ assertions validating new DTO methods, EventCatalog::categorySummary(), event count accuracy, version consistency, and quality gates.
- **Version sweep** — All 14 entry points synced to v164.0.0.

> 📖 See [CHANGELOG.md](CHANGELOG.md) for complete release history.

## Features

### Multi-Provider Tracking (10 Providers)
- **GA4** — Measurement Protocol (server-side) + gtag.js (client-side), debug/validation endpoint
- **GTM** — dataLayer push + ecommerce events
- **Meta Pixel** — Conversions API (CAPI/server) + fbq.js (client)
- **Plausible Analytics** — Privacy-focused server-side tracking
- **PostHog** — Product analytics with $set, $create_alias, $reset
- **Mixpanel** — Product analytics with event tracking and user profiles
- **Amplitude** — Product analytics with event streaming and user identity
- **TikTok** — TikTok Pixel server-side event tracking
- **LinkedIn** — LinkedIn Insight Tag server-side event tracking
- **Webhook** — Generic HTTP webhook forwarding for custom integrations
- All trackers implement `TrackerInterface` for easy extension

### Event System
- **194 typed event classes** across 8 categories (E-commerce 15, SaaS 82, Engagement 35, Marketing 34, Infrastructure 10, CustomerSuccess 7, Security 6, Uptime 5)
- **EventCatalog** — Unified registry for event lookup, cross-provider name mapping (8 providers), semantic alias resolution, category filtering, category summary statistics, funnel helpers (checkout, activation, retention, billing, PLG, AARRR lifecycle), provider coverage analysis, dependency graph, causal path analysis, funnel bottleneck analysis, AARRR framework breakdown
- **EventSchemaRegistry** — 50+ event schemas with typed parameters, validation, and custom schema registration
- **EventSchemaBuilder** — Fluent DSL for defining event schemas with chainable constraints (type, required, enum, regex, range)
- **CustomEvent** — Arbitrary event name + params for one-off tracking
- **EventPluginRegistry** — Plugin-based event category extension for third-party integrations

### Event Processing
- **Middleware Stack** — Priority-ordered, composable middleware (consent gate, context attachment, schema validation, timestamp, logging, PII sanitization)
- **Event Pipeline** — Lightweight pipe chain for UTM enrichment, user context, consent filtering, and timestamp enrichment
- **Event Context Builder** — Auto-collects user identity, client ID, session, UTM, page, and device context from the request
- **Event Validation** — Name validation, parameter sanitization, deduplication, and strict whitelist mode
- **Event Deduplication** — Cache-based SHA-256 fingerprint deduplication with configurable window (EventDeduplicationService)
- **Device Context** — Zero-dependency User-Agent parsing for browser, OS, device type, and brand detection (DeviceContextService)
- **Event Priority Gate** — Four-level priority system (critical/normal/low/background) with per-priority rate limits, budget thresholds, and 20+ built-in event-level overrides (EventPriorityGate, PriorityAwareFilter)
- **IP Anonymization** — GDPR-compliant IPv4/IPv6 masking with configurable granularity (IpAnonymizationService)
- **PII Sanitization** — Auto-scrub Personally Identifiable Information from events (hash, remove, or mask strategies)
- **Event Sampling** — Probabilistic rate limiting for high-traffic apps (deterministic or random)
- **Event Debounce** — Suppress rapid-fire events (scroll, resize) with configurable windows
- **Session Analytics** — Session-level event recording, aggregation, summaries, and end-of-session dispatch
- **Event Aggregation** — Real-time event counting with time-windowed rotation, top events ranking, and category grouping
- **Event Metadata Enrichment** — Auto-attach session ID, page URL, referrer, and server timestamp to all API events (EventMetadataEnricher)
- **Schema Pipeline Validation** — Optional schema-aware validation in the API pipeline with non-blocking warning flags (SchemaEnricher)

### SaaS Funnel Tracking
- **5 Lifecycle Funnels** — Signup (5 steps), Trial (4 steps), Conversion (4 steps), Retention (4 steps), Expansion (4 steps)
- **SaasFunnelService** — High-level API for tracking complete user funnels with funnel metadata on every event
- **21 funnel step methods** — signupLandingPage(), signupView(), signupFormStart(), signupFormSubmit(), signupComplete(), trialStart(), trialActive(), trialConverted(), trialExpired(), pricingView(), planSelect(), checkoutStart(), checkoutComplete(), featureUsed(), renewalEligible(), renewalStart(), renewalComplete(), upgradeEligible(), upgradeView(), upgradeSelect(), upgradeComplete()

### SaaS Analytics
- **82 SaaS Events** — SignUp, Login, Logout, TrialStart, TrialEnd, TrialConverted, Subscription, SubscriptionResumed, SubscriptionPaused, PlanUpgrade, PlanDowngrade, Cancellation, FeatureUsed, Revenue, InviteSent, IntegrationConnected, SubscriptionRenewal, + MilestoneReached, SubscriptionValueChanged, UsageQuotaReached, BillingRetry, + 6 Cohort events (Assigned, Retention, Churn, Conversion, Migration, Engagement), + 6 Account lifecycle (Activated, Deactivated, PasswordChanged, PasswordReset, ProfileUpdated, EmailVerified), + 4 B2B/Team (TeamCreated, TeamMemberJoined, TeamMemberRemoved, RoleChanged), + 5 Billing (PaymentFailed, PaymentSucceeded, PaymentMethodAdded, InvoiceGenerated, CreditApplied), + FeatureAdopted, ExpansionRevenue, + Export, Import, + 34 Marketing events (email lifecycle, ad attribution, referral, social, affiliate, webinar, lead scoring, blog, push notification, SMS)
- **194 Typed Event Classes** — All catalog events have dedicated typed event classes with GA4/Meta/PostHog/Plausible/Mixpanel/Amplitude/TikTok/LinkedIn mappings
- **SaaSAnalyticsService** — Convenience methods for all lifecycle events + custom events
- **CohortAnalyticsService** — Time-based cohort tracking with retention, churn, conversion, migration, and engagement summary analytics
- **RevenueAnalyticsService** — MRR, ARR, one-time, add-on, upgrade, downgrade, churn revenue tracking
- **RevenueAttributionService** — Revenue tracking with UTM attribution, MRR changes, LTV estimates, cohort revenue, and revenue breakdown by channel
- **FunnelAnalyticsService** — Multi-step conversion funnel tracking with step tracking, abandonment detection, completion rate, and retry tracking
- **Server-Side Auto-Tracking** — Config-driven mapping of Laravel auth events and custom app events to analytics events
- **LifecycleEventMapper** — 38 built-in lifecycle mappings (auth, subscription, trial, feature, e-commerce, engagement, account, B2B/team, billing, integration, conversion & expansion)
- **AnalyticsHealthService** — Programmatic health-check with structured report, warnings, and recommendations
- **Session & Funnel Tracker** — Session start/end, page counts, duration, and conversion funnel step tracking
- **AnalyticsDataBus** — Rule-based event routing to selectively dispatch events to specific providers by name, category, param, or PII detection
- **Heartbeat Monitor** — Production health pulse with per-provider circuit breaker, queue depth tracking, ring-buffer history (24h), aggregate statistics, and staleness detection
- **Event Bundling** — Groups related SaaS events into named journey bundles (signup_funnel, activation_funnel, etc.) with step tracking, completion/abandonment, and funnel analysis
- **Feature Flag Observer** — Auto-tracks A/B test exposures and goal conversions as analytics events with deduplication, configurable tracking, and ignore lists

### SDK Bridge Mode (Third-Party SDK Migration)
- **4 Supported SDKs** — PostHog, Mixpanel, Segment, Amplitude bidirectional event format translation
- **Inbound Translation** — Accept events in third-party SDK format, auto-translate to ZeroBoiler format with SDK metadata stripping
- **Outbound Translation** — Dual-dispatch ZeroBoiler events to third-party format for parallel tracking during migration
- **Compatibility Reports** — Per-SDK mapping coverage analysis showing which events have explicit translations
- **Custom Mappings** — Extensible via registerInboundMapping(), registerOutboundMapping(), and param transformers
- **JS Client Bridge** — `trackFromSdk()`, `translateToSdk()`, `inspectSdkTranslation()` for client-side migration support
- **7 API Endpoints** — SDK bridge sdks, compatibility, coverage, translate-inbound/outbound, inspect, mappings

### E-commerce
- **15 E-commerce Events** — ViewItem, AddToCart, RemoveFromCart, ViewCart, BeginCheckout, AddPaymentInfo, Purchase, Refund, Wishlist, SelectItem, SelectPromotion, ViewPromotion, CheckoutStep, AbandonedCart, CheckoutAbandon
- **EcommerceAnalyticsService** — Full e-commerce flow convenience methods
- **GA4 ↔ Meta Format Conversion** — Automatic cross-provider event name and parameter mapping for all 81 events (JS + PHP)
- **`Analytics::wishlist()`** — Convenience method with auto Meta `AddToWishlist` formatting

### Identity & GDPR
- **User Identity Linking** — Client ID ↔ User ID association for cross-device identification
- **Identity Alias** — Merge anonymous → authenticated profiles (PostHog $create_alias compatible)
- **User Properties** — Set user traits across all providers (PostHog $set, GA4 user properties)
- **GDPR Identity Reset** — `resetIdentity()` for right-to-be-forgotten compliance
- **Consent Mode v2** — Full granular consent (analytics_storage, ad_storage, ad_user_data, ad_personalization, functionality_storage, security_storage)
- **GDPR Consent Purposes** — 4 configurable purposes (necessary, analytics, marketing, functional) with required/default flags, exposed in Inertia props for cookie banners
- **ConsentLogService** — Audit trail for consent changes with DSAR export, configurable TTL (90-day default), purpose-level logging

### Inertia.js Integration
- **HandleInertiaAnalytics Middleware** — Injects analytics config + server-generated tracking ID into page props
- **Client ID Cookie** — Auto-generated UUID stored in httpOnly cookie for server/client matching
- **Auto-Track Links** — Configurable outbound/internal link click tracking exposed via Inertia props

### API Endpoints
- `POST /api/analytics/events` — Track single event (auth:sanctum, rate-limited)
- `POST /api/analytics/batch` — Track up to 25 events in one request
- `POST /api/analytics/identify` — Link client ID ↔ user ID + optional traits
- `POST /api/analytics/pageview` — Server-side page view (ad-blocker resistant)
- `POST /api/analytics/consent` — Update consent signals
- `POST /api/analytics/opt-out` — Per-user tracking opt-out (GDPR)
- `POST /api/analytics/opt-in` — Override previous opt-out preference
- `GET /api/analytics/preference` — Check tracking preference status
- `GET /api/analytics/health` — Health check (public, no auth)
- `GET /api/analytics/catalog` — Full event catalog (public, no auth)
- `GET /api/analytics/stream` — Real-time event stream for dashboards
- `GET /api/analytics/stream/stats` — Event stream statistics
- `GET /api/analytics/export` — Export events (JSON, CSV, metrics, compliance)
- `GET /api/analytics/stats` — Aggregated dashboard statistics (public, no auth)
- `POST /api/analytics/webhook/inbound` — Receive events from external sources (HMAC-SHA256 verified)
- `GET /api/analytics/alerts` — Alert rules summary and recent alert history
- `POST /api/analytics/alerts/evaluate` — Evaluate all alert rules against current metrics
- `GET /api/analytics/funnels` — Funnel visualization data (per-step conversion rates, drop-off)
- `POST /api/analytics/funnels/compare` — Side-by-side funnel comparison
- `GET /api/analytics/funnels/drop-off` — Step-by-step drop-off analysis
- `GET /api/analytics/funnels/chart` — Chart.js/Recharts-compatible funnel data
- `GET /api/analytics/lifecycle` — Lifecycle event mapping configuration
- `GET /api/analytics/correlation/patterns` — Frequent event patterns
- `GET /api/analytics/correlation/transitions` — Top event transitions
- `GET /api/analytics/correlation/predict` — Next-event prediction
- `GET /api/analytics/growth/dashboard` — Full growth metrics dashboard (activation, stickiness, velocity, cohort health, grade)
- `GET /api/analytics/growth/activation` — Activation rate and time-to-activate metrics
- `GET /api/analytics/growth/stickiness` — Feature stickiness and D30 stickiness
- `GET /api/analytics/growth/velocity` — Engagement velocity (events/user/day)
- `GET /api/analytics/growth/cohort-health` — Cohort health (D1/D7/D30 retention, churn risk)
- `GET /api/analytics/onboarding/wizard` — Onboarding wizard state and grade
- `GET /api/analytics/onboarding/wizard/steps` — 6-step guided onboarding steps
- `GET /api/analytics/onboarding/wizard/progress` — Detailed per-step progress with coverage
- `GET /api/analytics/onboarding/wizard/recommendations` — Next events to instrument
- `GET /api/analytics/onboarding/wizard/config-checklist` — Configuration readiness checklist
- `GET /api/analytics/onboarding/wizard/readiness` — Overall readiness grade (A-F)
- `GET /api/analytics/onboarding/wizard/quick-start` — Day-one quick-start checklist
- `GET /api/analytics/digest` — Weekly digest for a specific period
- `GET /api/analytics/digest/latest` — Latest weekly digest
- `GET /api/analytics/correlation/summary` — Correlation analysis summary
- `GET /api/analytics/buckets` — List all event bucket series
- `GET /api/analytics/buckets/{series}` — Get time-binned event aggregation (granularity=minute|hour|day|week|month)
- `GET /api/analytics/buckets/{series}/summary` — Bucket series summary with top events
- `GET /api/analytics/buckets/{seriesA}/compare/{seriesB}` — Compare two bucket series side-by-side
- `GET /api/analytics/health-score` — Get current SaaS health score (0–100, A–F grade)
- `POST /api/analytics/health-score/calculate` — Force-recalculate health score
- `GET /api/analytics/health-score/history` — Health score trend history
- `GET /api/analytics/journeys/stats` — Journey statistics across all tracked journeys
- `GET /api/analytics/journeys/patterns` — Most common journey patterns
- `GET /api/analytics/journeys/drop-offs` — Drop-off points analysis
- `GET /api/analytics/journeys/search?pattern=...` — Find journeys matching a pattern
- `POST /api/analytics/journeys/funnel` — Funnel conversion within journeys
- `GET /api/analytics/journeys/{id}` — Full journey timeline with page flow
- `GET /api/analytics/archetypes` — List event archetypes with summary (v3.9.0)
- `GET /api/analytics/archetypes/{key}` — Detailed archetype with lifecycle config (v3.9.0)
- `GET /api/analytics/archetypes/gaps` — Instrumentation gap analysis vs EventCatalog (v3.9.0)
- `POST /api/analytics/archetypes/{key}/score` — Archetype completion score (v3.9.0)
- `GET /api/analytics/anonymized/summary` — GDPR-safe k-anonymized dashboard summary (v3.9.0)
- `GET /api/analytics/anonymized/by-event` — Per-event k-anonymized counts (v3.9.0)
- `GET /api/analytics/anonymized/by-category` — Per-category k-anonymized counts (v3.9.0)
- `GET /api/analytics/anonymized/by-time` — Time-bucketed k-anonymized counts (v3.9.0)
- `GET /api/analytics/config-drift` — Detect config drift against baseline (v3.9.0)
- `GET /api/analytics/config-drift/baseline` — Baseline metadata (v3.9.0)
- `POST /api/analytics/config-drift/capture` — Capture config baseline (v3.9.0)
- `DELETE /api/analytics/config-drift/baseline` — Clear config baseline (v3.9.0)

### JS Client Library (`resources/js/analytics.js`)
- **Init** — Reads config from Inertia `zbAnalytics` prop, auto-initializes all enabled providers
- **Event Tracking** — `trackEvent()` with auto-batch queue (5s / 25 events) + immediate mode
- **Page View** — Client-side GA4/Meta/Plausible/PostHog push + server-side dispatch
- **E-commerce** — `trackEcommerce()` with automatic GA4 ↔ Meta format conversion
- **Screen View & A/B Test** — SPA navigation and experiment exposure tracking
- **Scroll Depth** — Fires at 25%, 50%, 75%, 90% thresholds (once per page view)
- **Form Tracking** — Auto-captures `form_start` and `form_submit` via event delegation
- **Error Tracking** — Captures `window.error` + `unhandledrejection` with configurable ignore patterns
- **Performance Tracking** — Web Vitals integration (LCP, CLS, INP, TTFB, FCP) + Performance API timing
- **Link Tracking** — Auto-track outbound/internal link clicks with custom prefix
- **Session Tracking** — Session start/end with idle timeout, visibility change, beforeUnload
- **Session Heartbeat** — Periodic session activity ping (10–300s configurable)
- **UTM Capture** — Auto-captures UTM params on init, persists across navigations, enriches all events
- **Identity** — `identify()`, `alias()`, `identifyWithTraits()`, `setUserProperties()`, `trackServerPageView()`
- **Event Catalog** — `fetchEventCatalog()` to fetch server-side event catalog (cached)
- **GDPR Preferences** — `optOutTracking()`, `optInTracking()`, `getTrackingPreference()`
- **GTM DataLayer** — `pushToDataLayer()` for custom GTM data pushes
- **Unload Flush** — `navigator.sendBeacon()` auto-flush on page unload prevents data loss
- **Cleanup** — All auto-trackers return cleanup functions for Svelte `onMount` compatibility

### TypeScript Type Definitions (`resources/js/analytics.d.ts`)
- **Full IntelliSense** — All 50+ exported functions with typed parameters and return types
- **Interface Definitions** — ZbAnalyticsConfig, ConsentSignals, AutoTrackConfig, PerformanceConfig, Ga4Item, EventCatalog, SessionState, and more
- **Inertia Extension** — Extends `@inertiajs/core` PageProps with optional `zbAnalytics` property

### Async Queue Dispatch
- Configurable queue connection and queue name
- `QueuedAnalyticsDispatcher` for background event processing
- `trackAsync()` facade shortcut with automatic sync fallback on failure

### Event Replay Queue
- Failed events automatically retried with exponential backoff + jitter
- Configurable max attempts, base/max delay, and jitter percentage
- `EventReplayQueue::summary()` for health checks and monitoring
- Prevents analytics data loss during transient provider outages

### Admin Commands
- `zb:analytics:overview` — Shows enabled providers, consent state, auto-track config, queue settings, identity config, ecommerce settings
- `zb:analytics:test` — Sends test event to all providers, supports `--validate` (GA4 debug) and `--event=` (custom name)
- `zb:analytics:export` — Export event catalog as JSON, CSV, or Markdown for documentation
- `zb:analytics:revenue-report` — Revenue analytics configuration overview with dry-run preview
- `zb:analytics:health` — Comprehensive health diagnostic with warnings, recommendations, JSON output (`--json`)
- `zb:analytics:dashboard` — Export dashboard data as structured JSON/table (`--include-metrics`, `--include-health`, `--pretty`)
- `zb:analytics:archetype-drift` — Config drift detection + event archetypes: `baseline`, `drift`, `clear`, `archetypes`, `gaps`, `score`

### Blade Integration
- `@analyticsHead` — GA4/GTM/Meta/Plausible/PostHog head script tags
- `@analyticsBody` — GTM noscript + Meta body script tags
- `InjectAnalyticsScripts` middleware for auto-injection

### Developer Experience
- **Debug Mode** — `ANALYTICS_DEBUG_ENABLED=true` logs events without dispatching, runtime toggle via `setDebug()`
- **Facade** — `Analytics::track()`, `Analytics::purchase()`, `Analytics::identify()`, `Analytics::pageView()`, `Analytics::trackError()`, `Analytics::mrr()`, `Analytics::resolveEventName()`, `Analytics::trackWithAlias()`, and 50+ more methods
- **Config-Driven** — 60+ environment variables, sensible defaults, zero-required-config to start
- **Metrics & Observability** — Per-provider dispatch/failure counters for monitoring and debugging
- **PII Sanitization** — Auto-hash, remove, or mask sensitive data before dispatch
- **Event Sampling** — Control analytics volume with configurable sample rates
- **Anonymous ID Tracking** — Persistent UUID-based client identifiers with cookie management
- **AnalyticsConfig** — Type-safe config accessor with 316 typed methods (no raw array access)
- **EventTransformer** — Centralized GA4 ↔ Meta ↔ PostHog ↔ Plausible ↔ Mixpanel ↔ Amplitude ↔ TikTok ↔ LinkedIn event format conversion
- **EventAliasResolver** — 100+ built-in event name aliases (signup→sign_up, addtocart→add_to_cart, CamelCase→snake_case) with custom config aliases
- **EventCacheService** — L1 memory + L2 Laravel cache for high-performance event lookups and format conversions
- **AnalyticsEventNameRule** — Laravel validation rule for analytics event names
- **AnalyticsRateLimiter** — Per-client rate limiting (client ID / IP based)
- **WebhookSignatureValidator** — HMAC-SHA256 webhook signature validation
- **PHPStan 2** — Level max, full type coverage
- **Pest PHP 3** — 3000+ tests across 421 test files
- **Pint** — Laravel coding style
- **Rector** — Automated code quality

### Enterprise Features (v2.30+)
- **Multi-Tenant Isolation** — Per-tenant analytics data with automatic tenant ID resolution (user attribute, header, subdomain, session), per-tenant config overrides, per-tenant rate limiting
- **Event Broadcasting** — Real-time event broadcast via Laravel Echo/Pusher for live dashboards
- **Data Retention Policy** — GDPR-compliant per-category retention (engagement 30d, SaaS 90d, ecommerce 365d) with auto-expiry
- **Feature Gate** — Plan-based analytics access control (Free/Starter/Pro/Enterprise tiers) with 12 gated features and per-user overrides
- **Geolocation Enrichment** — IP-based geolocation enrichment in event pipeline
- **Referral Tracking** — Referral code tracking with TTL and conversion tracking
- **Event Reporting** — Structured report generation for compliance and audit (JSON, CSV, metrics)
- **Dead Letter Queue** — Failed event recovery with DLQ management API
- **Real-Time Aggregation** — Time-windowed real-time event counting and top events
- **A/B Test Analytics** — Experiment tracking with exposure, conversion, and result analysis
- **Analytics Snapshots** — Daily/hourly snapshots with day-over-day comparison
- **SaaS KPI Tracker** — MRR, churn rate, trial conversion, ARPU, and LTV tracking
- **Event Buckets** — Time-binned aggregation (minute/hour/day/week/month) for chart rendering and dashboard widgets, per-event breakdowns, unique user counting, cross-series comparison
- **SaaS Health Score** — Composite 0–100 score with A–F grading across 4 dimensions (engagement, revenue, conversion, retention), sub-score breakdowns, historical trend tracking
- **UTM Aggregation** — Source/campaign breakdown and top UTM analytics
- **SaasKpiTracker** — Comprehensive SaaS metrics tracking with KPI history
- **Event Correlation** — Pattern detection, transition analysis, and next-event prediction
- **Config Validator** — AnalyticsConfigValidator for runtime config integrity checks
- **Event Source Tagger** — Source identification (server, client, api, webhook) on events
- **Event Archetypes** — 6 built-in SaaS funnel blueprints (signup, activation, trial, ecommerce, expansion, retention) with gap detection and completion scoring (v3.9.0)
- **Config Drift Detection** — Baseline capture and drift detection for CI/CD validation gates (v3.9.0)
- **k-Anonymity Aggregation** — GDPR-safe event aggregation with Laplace noise injection and k-threshold suppression (v3.9.0)

## Architecture

```
src/
├── AnalyticsManager.php              # Core manager — dispatches to all 10 trackers
├── AnalyticsMetrics.php              # Dispatch metrics (per-provider counters for observability)
├── AnalyticsServiceProvider.php       # Laravel service provider (registers everything)
├── Bus/
│   ├── AnalyticsDataBus.php          # Rule-based conditional event routing
│   ├── AnalyticsEventBus.php         # Internal event bus for decoupled dispatch
│   └── AnalyticsEventDispatcher.php   # Queued event dispatcher
├── Context/
│   ├── EventContextBuilder.php        # Auto-collect request context (user, UTM, session, device)
│   └── AnalyticsContextBus.php        # Context propagation across event pipeline
├── DTO/                              # 23 immutable DTOs
│   ├── AnalyticsEvent.php            # Immutable event DTO (name, params, clientId, userId)
│   ├── ConsentState.php              # GDPR consent state (6 granular signals)
│   ├── EventContextEvent.php          # Context-rich event envelope
│   ├── EventPriority.php             # Event priority levels (critical/normal/low/background)
│   └── ...                            # (20+ more DTOs)
├── Events/
│   ├── Ecommerce/                    # 15 e-commerce event classes + EcommerceEvents catalog
│   ├── SaaS/                         # 71 SaaS event classes + SaaSEvents catalog
│   ├── Engagement/                   # 35 engagement event classes + EngagementEvents catalog
│   ├── Marketing/                    # 34 marketing event classes + MarketingEvents catalog
│   ├── Security/                     # 6 security event classes + SecurityEvents catalog
│   ├── Uptime/                       # 5 uptime event classes + UptimeEvents catalog
│   ├── Infrastructure/               # 10 infrastructure event classes + InfrastructureEvents catalog
│   ├── CustomEvent.php               # Generic custom event
│   └── EventCatalog.php              # Unified catalog (176 events, 8-provider mappings)
├── Middleware/                       # 13 middleware
│   ├── AnalyticsMiddlewareInterface.php   # Middleware contract
│   ├── AnalyticsMiddlewareStack.php       # Priority-ordered middleware stack
│   ├── ConsentGateMiddleware.php          # Consent-based event filtering
│   ├── ContextAttachmentMiddleware.php    # Auto-attach context to events
│   ├── SchemaValidationMiddleware.php     # Schema-aware event validation
│   ├── PiiSanitizationMiddleware.php      # PII auto-scrub (hash, remove, mask)
│   ├── TimestampMiddleware.php            # Auto-add timestamps
│   └── ...                            # (7+ more middleware)
├── Schema/
│   ├── EventSchema.php             # Event parameter schema definition
│   ├── EventSchemaBuilder.php      # Fluent schema DSL builder
│   ├── EventParam.php              # Parameter type & constraints
│   └── EventSchemaRegistry.php    # Central schema registry (55+ events)
├── Pipeline/                        # 24 pipeline filters + validation pipeline
│   ├── EventPipeline.php            # Middleware pipeline for event processing
│   ├── SamplingFilter.php           # Probabilistic event sampling
│   ├── EventDebounceFilter.php      # Debounce rapid-fire events (scroll, resize)
│   ├── UtmEnricher.php              # UTM campaign parameter enrichment
│   ├── UserContextEnricher.php      # User context enrichment
│   ├── ConsentFilter.php            # Consent-based event filtering
│   └── ...                          # (18+ more pipeline filters)
├── Trackers/                        # 10 provider trackers
│   ├── GA4Tracker.php               # GA4 Measurement Protocol + debug endpoint
│   ├── GTMTracker.php               # GTM dataLayer push
│   ├── MetaPixelTracker.php         # Meta Pixel CAPI
│   ├── PlausibleTracker.php         # Plausible Analytics
│   ├── PosthogTracker.php           # PostHog ($capture, $set, $create_alias, $reset)
│   ├── MixpanelTracker.php          # Mixpanel event tracking
│   ├── AmplitudeTracker.php         # Amplitude event tracking
│   ├── TikTokTracker.php            # TikTok Pixel events
│   ├── LinkedInTracker.php          # LinkedIn Insight Tag events
│   ├── WebhookTracker.php           # Generic HTTP webhook forwarding
│   ├── TrackerInterface.php         # Common tracker contract
│   └── TrackerHelpers.php           # Shared consent helpers
├── Services/                        # 318 services
│   ├── GoogleAnalyticsService.php        # GA4 convenience wrapper
│   ├── GoogleTagManagerService.php      # GTM convenience wrapper
│   ├── MetaPixelService.php             # Meta convenience wrapper
│   ├── EcommerceAnalyticsService.php     # Full e-commerce flow methods
│   ├── SaaSAnalyticsService.php         # SaaS lifecycle convenience methods
│   ├── RevenueAnalyticsService.php      # Revenue tracking (MRR, ARR, churn)
│   ├── RevenueAttributionService.php    # Revenue attribution, LTV, cohort analysis
│   ├── FunnelAnalyticsService.php       # Conversion funnel tracking
│   ├── AnalyticsStatsService.php        # Dashboard aggregation (totals, top events, by-provider)
│   └── ...                            # (310+ more services)
├── Tracking/
│   ├── ServerSideTracker.php       # Auto-track Laravel auth events + custom app events
│   ├── UserIdentityTracker.php     # User ↔ client linking (login, register, logout)
│   ├── AnonymousIdTracker.php     # Persistent UUID anonymous ID management + cookies
│   ├── SessionTracker.php          # Session, funnel, and conversion tracking
│   ├── TenantAnalyticsContext.php  # Multi-tenant analytics context
│   └── LifecycleEventSubscriber.php # Config-driven lifecycle event auto-tracking
├── Queue/
│   ├── QueuedAnalyticsDispatcher.php   # Async queue dispatch (configurable)
│   ├── EventReplayQueue.php             # Failed event retry with exponential backoff
│   └── TrackAnalyticsEventJob.php       # Queued job for async event dispatch
├── Http/
│   ├── Controllers/AnalyticsEventController.php  # 200+ API endpoints + event pipeline
│   ├── Controllers/AnalyticsSSEController.php     # Server-Sent Events for live dashboards
│   └── Middleware/InjectAnalyticsScripts.php       # Auto-inject analytics scripts
├── Inertia/
│   └── HandleInertiaAnalytics.php   # Inertia page prop injection + tracking ID cookie
├── Console/Commands/                # 71 artisan commands
│   ├── AnalyticsOverviewCommand.php  # Config overview
│   ├── AnalyticsTestCommand.php     # Test event dispatch
│   ├── AnalyticsExportCommand.php   # Export catalog as JSON/CSV/Markdown
│   ├── AnalyticsHealthCommand.php    # Comprehensive health diagnostic
│   ├── AnalyticsDashboardCommand.php # Dashboard data export (JSON/table)
│   └── ...                           # (66+ more commands)
├── Support/
│   ├── AnalyticsConfig.php              # Type-safe config accessor (316 methods)
│   ├── AnalyticsEventNameRule.php       # Laravel validation rule for event names
│   ├── EventTransformer.php             # Cross-provider event format conversion
│   ├── EcommerceFormatConverter.php     # GA4 ↔ Meta item format bidirectional conversion
│   ├── SaaSEventHelpers.php             # SaaS lifecycle event convenience helpers (26 methods)
│   ├── EventBuilder.php                 # Fluent event builder (35+ factories)
│   ├── AnalyticsRateLimiter.php         # Per-client rate limiting
│   └── WebhookSignatureValidator.php    # HMAC-SHA256 webhook signature validation
├── Blueprints/                       # Event blueprint system
│   ├── EventBlueprint.php
│   └── EventBlueprintRegistry.php
├── Macros/                           # Event macro system
│   ├── AnalyticsMacro.php
│   ├── AnalyticsMacroBuilder.php
│   └── AnalyticsMacroRegistry.php
├── Attributes/                       # PHP attribute-driven event mapping
│   ├── AnalyticsEventAttribute.php
│   ├── AnalyticsEventParam.php
│   └── AnalyticsLifecycleMapping.php
├── Blade/Directives/
│   └── AnalyticsDirectives.php      # @analyticsHead, @analyticsBody
├── Facades/
│   └── Analytics.php               # Facade (50+ methods)
resources/
└── js/
    ├── analytics.js                 # ES module client library (~8000 LOC)
    ├── analytics.d.ts               # TypeScript type definitions (50+ exports)
    ├── analytics.constants.js        # Event name constants (176 events, 7 categories)
    └── *.svelte.js                   # 5 Svelte composables (useAnalytics, useLifecycle, usePerformanceTracker, useSessionReplay, useAnalyticsConfig)
config/
└── zeroboiler.php                   # 60+ config options across 60+ sections
routes/
└── analytics.php                    # 200+ API route definitions
```

## Configuration

All settings are in `config/zeroboiler.php` under the `analytics` key.

### Environment Variables

```env
# ── Providers ──────────────────────────────────────────────────────
ANALYTICS_GA4_ENABLED=false
ANALYTICS_GA4_MEASUREMENT_ID=G-XXXXXXXXXX
ANALYTICS_GA4_API_SECRET=your_secret

ANALYTICS_GTM_ENABLED=false
ANALYTICS_GTM_CONTAINER_ID=GTM-XXXXXXX

ANALYTICS_META_PIXEL_ENABLED=false
ANALYTICS_META_PIXEL_ID=123456789
ANALYTICS_META_PIXEL_ACCESS_TOKEN=your_token

ANALYTICS_PLAUSIBLE_ENABLED=false
ANALYTICS_PLAUSIBLE_DOMAIN=example.com
ANALYTICS_PLAUSIBLE_API_KEY=your_key

ANALYTICS_POSTHOG_ENABLED=false
ANALYTICS_POSTHOG_API_KEY=your_key
ANALYTICS_POSTHOG_HOST=https://eu.posthog.com
ANALYTICS_POSTHOG_PROJECT_ID=

# ── Consent (GDPR) ─────────────────────────────────────────────
ANALYTICS_CONSENT_DEFAULT=granted
ANALYTICS_CONSENT_LOG_ENABLED=false
ANALYTICS_CONSENT_LOG_TTL=7776000

# ── Server-Side Auto-Tracking ────────────────────────────────────
ANALYTICS_AUTO_TRACK_ENABLED=true

# ── Queue (Async Dispatch) ─────────────────────────────────────────
ANALYTICS_QUEUE_ENABLED=true
ANALYTICS_QUEUE=analytics
ANALYTICS_QUEUE_CONNECTION=

# ── API Endpoints ─────────────────────────────────────────────────
ANALYTICS_API_ENABLED=true
ANALYTICS_API_THROTTLE=60
ANALYTICS_API_BASE_URL=/api/analytics

# ── Identity Tracking ────────────────────────────────────────────
ANALYTICS_IDENTITY_COOKIE=zb_analytics_id
ANALYTICS_IDENTITY_COOKIE_TTL=525600
ANALYTICS_IDENTITY_COOKIE_SECURE=true
ANALYTICS_IDENTITY_COOKIE_SAMESITE=Lax

# ── E-commerce ────────────────────────────────────────────────────
ANALYTICS_ECOMMERCE_CURRENCY=USD
ANALYTICS_ECOMMERCE_BRAND=

# ── Auto-Track Links ──────────────────────────────────────────────
ANALYTICS_TRACK_LINKS_ENABLED=false
ANALYTICS_TRACK_LINKS_EXTERNAL=true
ANALYTICS_TRACK_LINKS_INTERNAL=false
ANALYTICS_TRACK_LINKS_PREFIX=outbound

# ── Debug Mode ───────────────────────────────────────────────────
ANALYTICS_DEBUG_ENABLED=false
ANALYTICS_DEBUG_LOG_EVENTS=false

# ── Event Validation ──────────────────────────────────────────────
ANALYTICS_VALIDATION_STRICT=false
ANALYTICS_VALIDATION_MAX_NAME_LENGTH=100
ANALYTICS_VALIDATION_DEDUP_WINDOW=10

# ── Event Pipeline ────────────────────────────────────────────────
ANALYTICS_PIPELINE_AUTO_UTM=true
ANALYTICS_PIPELINE_AUTO_TIMESTAMP=false

# ── Event Sampling (High-Traffic) ────────────────────────────────
ANALYTICS_SAMPLING_ENABLED=false
ANALYTICS_SAMPLING_RATE=1.0
ANALYTICS_SAMPLING_DETERMINISTIC=true

# ── PII Sanitization ────────────────────────────────────────────
ANALYTICS_PII_ENABLED=false
ANALYTICS_PII_STRATEGY=hash

# ── Event Replay Queue (Failed Event Retry) ───────────────────────
ANALYTICS_REPLAY_ENABLED=true
ANALYTICS_REPLAY_MAX_ATTEMPTS=3
ANALYTICS_REPLAY_BASE_DELAY=1.0
ANALYTICS_REPLAY_MAX_DELAY=60.0
ANALYTICS_REPLAY_JITTER=0.2

# ── Metrics & Observability ──────────────────────────
ANALYTICS_METRICS_ENABLED=false
ANALYTICS_METRICS_LOG_ON_FLUSH=false

# ── Event Pipeline ───────────────────────────────────
ANALYTICS_PIPELINE_AUTO_METADATA=true
ANALYTICS_PIPELINE_SCHEMA_ENRICHMENT=false

# ── Inbound Webhook ──────────────────────────────────
ANALYTICS_INBOUND_WEBHOOK_ENABLED=false
ANALYTICS_INBOUND_WEBHOOK_SECRET=
ANALYTICS_INBOUND_WEBHOOK_REQUIRE_SIGNATURE=true
ANALYTICS_INBOUND_WEBHOOK_MAX_PAYLOAD=65536
ANALYTICS_INBOUND_WEBHOOK_MAX_EVENTS=50
```

## Usage

### Basic Event Tracking

```php
use ZeroBoiler\Analytics\Facades\Analytics;

// Track a simple event
Analytics::track('button_click', ['element' => 'buy_now', 'page' => '/products']);

// Track using typed event
use ZeroBoiler\Analytics\Events\Engagement\ClickEvent;
Analytics::trackEvent(new ClickEvent(element: 'cta_button', page: '/pricing'));

// Quick purchase tracking (convenience)
Analytics::purchase('TXN-12345', 99.99, [
    ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2],
]);

// Wishlist (GA4 + Meta AddToWishlist auto-formatted)
Analytics::wishlist(['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99]);

// Identify a user (cross-device linking)
Analytics::identify('42', 'client-uuid', ['email_hash' => hash('sha256', 'user@example.com'), 'plan' => 'pro']);

// Screen view (SPA navigation)
Analytics::screenView('Dashboard', 'main');

// Page view (server-side, middleware-based)
Analytics::pageView('Pricing', 'https://example.com/pricing', 'https://google.com');
Analytics::serverSidePageView('Home', '/home', '', 'client-uuid', 'user-42');

// Logout / trial end / plan downgrade
Analytics::logout('sanctum');
Analytics::trialEnd('converted', 'pro');
Analytics::planDowngrade('pro', 'starter');

// A/B test exposure
Analytics::abTestExposure('pricing_redesign_v2', 'variant_a');

// Notification tracking
Analytics::notification('email', 'sent', 'welcome');
Analytics::notification('push', 'opened', 'weekly_digest');

// Async event dispatch (queued)
Analytics::trackAsync('background_job', ['job_id' => '12345']);

// Server-side error tracking
Analytics::trackError('Undefined variable: user', '/app/Http/Controllers/UserController.php', 42);

// MRR tracking (SaaS shortcut)
Analytics::mrr(5000.00, 120, ['plan' => 'business']);
```

### E-commerce Tracking

```php
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Services\EcommerceAnalyticsService;

// Quick purchase
Analytics::purchase('TXN-12345', 99.99, [
    ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2],
]);

// Cross-provider e-commerce (auto-formats for GA4 + Meta)
Analytics::trackEcommerce('purchase', [
    'transaction_id' => 'TXN-12345',
    'value' => 99.99,
    'currency' => 'USD',
    'items' => [['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2]],
]);

// Full e-commerce flow
$ecommerce = app(EcommerceAnalyticsService::class);
$ecommerce->viewItem(['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99]);
$ecommerce->addToCart(['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 1]);
$ecommerce->viewCart([['item_id' => 'SKU-001', 'price' => 49.99]], 49.99);
$ecommerce->beginCheckout([['item_id' => 'SKU-001', 'price' => 49.99]], 49.99, ['coupon' => 'SAVE10']);
$ecommerce->addPaymentInfo('credit_card');
$ecommerce->purchase('TXN-12345', 49.99, [['item_id' => 'SKU-001', 'price' => 49.99, 'quantity' => 1]]);
$ecommerce->refund('TXN-12345', 49.99);

// Cross-provider format conversion
$metaFormat = $ecommerce->formatMetaItem(['item_id' => 'SKU-001', 'price' => 49.99, 'quantity' => 1]);
$metaCart = Analytics::formatEcommerceForMeta([['item_id' => 'SKU-001', 'price' => 49.99, 'quantity' => 1]]);
```

### SaaS Lifecycle Events

```php
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Services\SaaSAnalyticsService;

$saas = app(SaaSAnalyticsService::class);

$saas->trackSignUp('google');
$saas->trackLogin('sanctum');
$saas->trackTrialStart('pro', 14);
$saas->trackSubscription('business', 99.99, 'EUR');
$saas->trackPlanUpgrade('starter', 'pro');
$saas->trackCancellation('pro', 'too_expensive');
$saas->trackPlanDowngrade('pro', 'starter');
$saas->trackLogout('sanctum');
$saas->trackTrialEnd('converted', 'pro');
$saas->trackRevenue(5000.00, 'mrr', 'business');
$saas->trackFeatureUsed('export', 5);
$saas->trackCustomEvent('onboarding_complete', ['step_count' => 5]);
```

### v2.90 — New SaaS Lifecycle Events (GDPR + Subscription)

```php
use ZeroBoiler\Analytics\Events\SaaS\{
    AccountDeletedEvent, SubscriptionCreatedEvent, SubscriptionCancelledEvent,
    TrialExpiredEvent, PlanChangedEvent,
};

use ZeroBoiler\Analytics\Facades\Analytics;

// GDPR account deletion tracking
Analytics::trackEvent(new AccountDeletedEvent(
    reason: 'gdpr_request',
    method: 'self_service',
    accountAgeDays: 365,
    lastPlan: 'pro',
));

// Subscription creation
Analytics::trackEvent(new SubscriptionCreatedEvent(
    plan: 'business',
    value: 99.99,
    currency: 'EUR',
    billingCycle: 'monthly',
    source: 'trial_conversion',
));

// Subscription cancellation with retention context
Analytics::trackEvent(new SubscriptionCancelledEvent(
    plan: 'pro',
    reason: 'too_expensive',
    flow: 'self_service',
    effectiveDate: '2026-09-01',
    retentionOfferAccepted: false,
));

// Trial expired (lapsed without action)
Analytics::trackEvent(new TrialExpiredEvent(
    plan: 'pro',
    trialLengthDays: 14,
    featuresUsedCount: 3,
    lastActivity: '2026-08-01T10:00:00Z',
));

// General plan change
Analytics::trackEvent(new PlanChangedEvent(
    fromPlan: 'starter',
    toPlan: 'enterprise',
    direction: 'upgrade',
    reason: 'user_initiated',
    priceDifference: 50.00,
    currency: 'USD',
));

// GDPR & billing event helpers
EventCatalog::gdprEvents();    // Compliance-relevant events
EventCatalog::billingEvents(); // Financial lifecycle events
```

### Revenue Analytics

```php
use ZeroBoiler\Analytics\Services\RevenueAnalyticsService;

$revenue = app(RevenueAnalyticsService::class);

$revenue->trackMRR(5000.00, 120);              // $5,000 MRR, 120 subscribers
$revenue->trackARR(60000.00, 120, 'EUR');      // custom currency
$revenue->trackOneTime(49.99, 'setup fee');
$revenue->trackAddon(15.00, 'extra_storage', 'pro');
$revenue->trackUpgradeRevenue(29.99, 9.99, 'starter', 'pro');
$revenue->trackDowngradeRevenue(9.99, 29.99, 'pro', 'starter');
$revenue->trackChurnRevenue(29.99, 'pro', 'too_expensive');
$revenue->trackCustom(75.00, 'expansion', 'enterprise', ['team_size' => 10]);
```

### Revenue Attribution & LTV

```php
use ZeroBoiler\Analytics\Services\RevenueAttributionService;
use ZeroBoiler\Analytics\DTO\UtmAttribution;

$attribution = app(RevenueAttributionService::class);

// Track revenue with UTM attribution
$utm = UtmAttribution::fromRequest(
    $request->only(['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content']),
    firstTouch: true,
    referrer: $request->headers->get('referer'),
);
$attribution->trackRevenue('rev-001', 99.99, ['plan' => 'pro'], $utm, (string) $user->id);

// MRR change tracking
$attribution->trackMrrChange((string) $user->id, 49.99, 29.99, 'pro', 'upgrade');

// LTV estimation
$attribution->trackLtv((string) $user->id, 599.99, 299.99, 180);

// Cohort revenue
$attribution->trackCohortRevenue('2026-01', 15000.00, 250);

// Revenue breakdown by channel
$attribution->trackRevenueBreakdown('stripe', 'organic', 5000.00, 50);
```

### Revenue Forecasting

```php
use ZeroBoiler\Analytics\Services\RevenueForecastService;

$forecast = app(RevenueForecastService::class);

// Full 90-day forecast with daily MRR projections
$projection = $forecast->forecast([
    'mrr' => 10000.0,
    'active_subscribers' => 120,
    'churned_mrr_last_month' => 500.0,
    'new_mrr_last_month' => 2000.0,
    'expansion_mrr_last_month' => 800.0,
    'churned_subscribers_last_month' => 4,
]);

// Quick summary
$summary = $forecast->summary(['mrr' => 10000.0, 'active_subscribers' => 120]);

// LTV calculation
$ltv = $forecast->calculateLtv(arpu: 99.0, monthlyChurnRate: 0.03, grossMargin: 0.75);

// LTV:CAC ratio analysis
$ratio = $forecast->ltvCACRatio(ltv: 3000.0, cac: 1000.0);

// CAC payback period
$payback = $forecast->paybackPeriod(cac: 500.0, monthlyArpu: 99.0, grossMargin: 0.75);

// Runway estimation
$runway = $forecast->runway(currentMrr: 5000.0, monthlyExpenses: 15000.0);

// Cohort retention curve
$curve = $forecast->cohortRetentionCurve(months: 12, monthlyChurnRate: 0.03);

// MRR movement breakdown
$movement = $forecast->mrrMovementBreakdown([
    'new_mrr' => 5000.0, 'expansion_mrr' => 2000.0,
    'contraction_mrr' => 500.0, 'churned_mrr' => 1000.0,
    'previous_mrr' => 20000.0,
]);
```

### Churn Prediction

```php
use ZeroBoiler\Analytics\Services\ChurnPredictionService;

$churn = app(ChurnPredictionService::class);

// Score a single user
$profile = $churn->scoreUser('user-123', [
    'days_inactive' => 30,
    'usage_decline_pct' => 45,
    'support_tickets_30d' => 2,
    'failed_payments_90d' => 1,
]);
// Returns: user_id, overall_score (0-100), risk_level (low/medium/high/critical),
//          signals (10 evaluated), recommendation, probability_percent

// Batch score multiple users (ranked by risk)
$batch = $churn->scoreBatch([
    ['user_id' => 'u1', 'days_inactive' => 0],
    ['user_id' => 'u2', 'days_inactive' => 45, 'usage_decline_pct' => 80],
]);

// Cohort risk summary (aggregate stats)
$summary = $churn->cohortRiskSummary($users);

// Get configured signal weights and thresholds
$weights = $churn->getSignalWeights();
$thresholds = $churn->getThresholds();
```

### Conversion Funnels

```php
use ZeroBoiler\Analytics\Services\FunnelAnalyticsService;

$funnel = app(FunnelAnalyticsService::class);

// Signup funnel
$funnel->startFunnel('signup', ['source' => 'landing_page']);
$funnel->trackStep('signup', 'form_start', 1);
$funnel->trackStep('signup', 'email_confirmed', 2);
$funnel->trackStep('signup', 'profile_setup', 3);
$funnel->complete('signup', 3, ['plan' => 'pro']);

// Abandonment tracking
$funnel->startFunnel('checkout');
$funnel->trackStep('checkout', 'shipping_info', 1);
$funnel->abandon('checkout', 'shipping_info', 4, ['reason' => 'form_complexity']);

// Retry tracking
$funnel->retry('signup', 2);

// State inspection
$funnel->isActive('signup');              // bool
$funnel->getCurrentStep('checkout');      // ?string
$funnel->getActiveFunnels();              // list<string>
```

### Cohort Analytics

```php
use ZeroBoiler\Analytics\Services\CohortAnalyticsService;

$cohorts = app(CohortAnalyticsService::class);

// Assign user to a cohort (typically on signup)
$cohortName = CohortAnalyticsService::generateCohortName('weekly');
$cohorts->assignCohort((string) $user->id, $cohortName, 'signup', ['source' => 'google']);

// Track retention (user returns after N days)
$cohorts->trackRetention((string) $user->id, $cohortName, 7);  // d7 retention
$cohorts->trackRetention((string) $user->id, $cohortName, 30); // d30 retention

// Track churn (user cancelled/deactivated)
$cohorts->trackChurn((string) $user->id, $cohortName, 15, 'too_expensive');

// Track conversion (trial → paid)
$cohorts->trackConversion((string) $user->id, $cohortName, 'trial_to_paid', ['plan' => 'pro']);

// Track migration (user moved between cohorts)
$cohorts->trackMigration((string) $user->id, '2026-W32', '2026-W33');

// Engagement summary (periodic aggregation)
$cohorts->trackEngagementSummary('2026-W32', 85, 120, 'weekly');
// engagement_rate auto-calculated: 70.83%

// Period classification helper
CohortAnalyticsService::classifyPeriod(7);   // 'd7'
CohortAnalyticsService::classifyPeriod(30);  // 'd30'
CohortAnalyticsService::classifyPeriod(500); // 'd365+'

// Generate cohort names
CohortAnalyticsService::generateCohortName('weekly', '2026-08-06'); // '2026-W32'
CohortAnalyticsService::generateCohortName('monthly', '2026-08-06'); // '2026-08'
CohortAnalyticsService::generateCohortName('quarterly', '2026-01-15'); // 'Q1-2026'
```

### Event Routing (Data Bus)

```php
use ZeroBoiler\Analytics\Bus\AnalyticsDataBus;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

$bus = app(AnalyticsDataBus::class);

// Route ecommerce events only to GA4
$bus->routeByCategory('ecommerce', ['ga4']);

// Route PII events to privacy-safe providers only
$bus->routePiiOnly(['ga4', 'posthog']);

// Route by event name pattern
$bus->routeByPattern('purchase*', ['ga4', 'meta']);

// Route by parameter value
$bus->routeByParam('method', 'github', ['ga4', 'gtm']);

// One-off routing
$bus->routeTo($event, ['ga4']);
$bus->routeExcept($event, ['meta', 'posthog']);

// Custom rule
$bus->addRule(
    fn (AnalyticsEvent $e) => ($e->params['value'] ?? 0) > 100,
    ['ga4', 'meta'],
);

// Clear all rules
$bus->clearRules();
```

### Event Routing (Provider Filtering)

```php
use ZeroBoiler\Analytics\Services\AnalyticsEventRouter;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

$router = app(AnalyticsEventRouter::class);

// Route purchase events only to GA4 and Meta
$router->route(new AnalyticsEvent(name: 'purchase', params: [
    'transaction_id' => 'TXN-001',
    'value' => 99.99,
]));
// → dispatched only to GA4 + Meta (not Plausible, PostHog, etc.)

// Check which providers would receive an event
$providers = $router->matchProviders('add_to_cart');
// → ['ga4', 'meta', 'posthog'] (if 'add_to_*' rule is configured)

// Add runtime routing rule
$router->addRule('page_view', ['ga4', 'plausible', 'posthog']);
```

Config-driven rules in `zeroboiler.php`:
```php
'routing' => [
    'enabled' => true,
    'rules' => [
        'purchase' => ['ga4', 'meta'],
        'refund' => ['ga4', 'meta'],
        'add_to_*' => ['ga4', 'meta', 'posthog'],
        'page_view' => ['ga4', 'plausible', 'posthog'],
    ],
],
```

### Engagement Events

```php
use ZeroBoiler\Analytics\Events\Engagement\{
    PageViewEvent, ScrollDepthEvent, ClickEvent, FormStartEvent,
    FormSubmitEvent, SearchEvent, ShareEvent, ErrorEvent, TimeOnPageEvent,
    ScreenViewEvent, AbTestExposureEvent, NotificationEvent, CampaignAttributionEvent,
    JSErrorEvent, TimingEvent, SessionStartEvent, SessionEndEvent, OutboundClickEvent,
    WebVitalsEvent,
};

Analytics::trackEvent(new PageViewEvent('Pricing', 'https://example.com/pricing'));
Analytics::trackEvent(new ScrollDepthEvent(percent: 75, page: '/blog/article-1'));
Analytics::trackEvent(new SearchEvent(query: 'analytics laravel', results: 42));
Analytics::trackEvent(new FormSubmitEvent(formName: 'contact', formId: 'form-123'));
Analytics::trackEvent(new ErrorEvent(code: 404, message: 'Page not found', url: '/missing'));
Analytics::trackEvent(new ScreenViewEvent('Dashboard'));
Analytics::trackEvent(new AbTestExposureEvent('cta_color_test', 'control'));
Analytics::trackEvent(new NotificationEvent('email', 'sent', 'welcome_email'));
Analytics::trackEvent(new CampaignAttributionEvent(source: 'google', medium: 'cpc', campaign: 'spring_sale'));
```

### Event Catalog

```php
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;

// Unified catalog — 86 events across 3 categories
EventCatalog::count();          // 86
EventCatalog::names();          // ['view_item', 'add_to_cart', 'sign_up', ...]
EventCatalog::has('purchase');  // true
EventCatalog::classFor('purchase'); // PurchaseEvent::class

// Grouped by category
$byCategory = EventCatalog::byCategory();

// Cross-provider mappings
$event = EcommerceEvents::get('purchase');
$event['ga4'];   // 'purchase'
$event['meta'];  // 'Purchase'
$event['class']; // PurchaseEvent::class

// All GA4 / Meta names (deduplicated)
EventCatalog::allGa4Names();
EventCatalog::allMetaNames();
```

### Event Pipeline

```php
use ZeroBoiler\Analytics\Pipeline\EventPipeline;
use ZeroBoiler\Analytics\Pipeline\{ConsentFilter, UtmEnricher, TimestampEnricher};

$pipeline = new EventPipeline;
$pipeline->pipe(new ConsentFilter($consentGranted));
$pipeline->pipe(new UtmEnricher($request->query->all()));
$pipeline->pipe(new TimestampEnricher($sessionId));

$processed = $pipeline->process($event);
if ($processed !== null) {
    Analytics::trackEvent($processed);
}

// Or use pre-configured defaults:
$pipeline = EventPipeline::withDefaults($context);
```

### Event Debounce (High-Frequency Events)

```php
use ZeroBoiler\Analytics\Pipeline\EventDebounceFilter;

$debounce = new EventDebounceFilter(1000); // 1 second window

// Suppress rapid-fire scroll events
$result = $debounce->process($event);
if ($result !== null) {
    Analytics::trackEvent($result);
}

// Flush held events on page navigation
$flushed = $debounce->flush();
foreach ($flushed as $event) {
    Analytics::trackEvent($event);
}
```

### Event Context Builder

```php
use ZeroBoiler\Analytics\Context\EventContextBuilder;

$context = (new EventContextBuilder($request))
    ->withUserIdentity()
    ->withClientId()
    ->withSession()
    ->withUTM()
    ->withPage()
    ->withDevice()
    ->withCustom(['app_version' => '1.0.0'])
    ->build();
```

### Session Tracking

```php
use ZeroBoiler\Analytics\Tracking\SessionTracker;

$session = app(SessionTracker::class);

$session->startSession($sessionId, ['source' => 'email_campaign']);
$session->trackSessionPageView($sessionId, ['page' => '/pricing']);
$session->trackFunnelStep('signup', 'landing', 1);
$session->trackFunnelStep('signup', 'form', 2);
$session->trackFunnelStep('signup', 'confirm', 3);
$session->trackFunnelComplete('signup', 3);
$session->trackFunnelAbandon('purchase', 'checkout', 4);
$session->endSession($sessionId);
```

### User Identity & GDPR

```php
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Tracking\UserIdentityTracker;

// Identity linking
$identity = app(UserIdentityTracker::class);
$identity->onLogin($user, $request);
$identity->onRegister($user, $request);
$identity->identify($userId, $clientId);

// User properties
Analytics::setUserProperties(['name' => 'Jane', 'plan' => 'pro'], '42');

// Identity alias (anonymous → authenticated)
Analytics::alias('anonymous-client-uuid', 'user-42');

// GDPR right to be forgotten
Analytics::resetIdentity();
```

### Consent Management

```php
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\DTO\ConsentState;

// Granular consent
$state = Analytics::getConsent()->with([
    'analytics_storage' => 'granted',
    'ad_storage' => 'denied',
]);
Analytics::setConsent($state);

// Shortcuts
Analytics::grantConsent();
Analytics::denyConsent();
```

### Event Validation

```php
use ZeroBoiler\Analytics\Services\EventValidationService;

$validator = app(EventValidationService::class);
$result = $validator->validate(new AnalyticsEvent(name: 'page_view', params: ['key' => 'value']));

if ($result['valid']) {
    Analytics::trackEvent($result['event']);
}
```

### Event Schema Registry

```php
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;
use ZeroBoiler\Analytics\Schema\EventSchema;
use ZeroBoiler\Analytics\Schema\EventParam;

$registry = app(EventSchemaRegistry::class);

// Validate against schema
$result = $registry->validate('purchase', ['transaction_id' => 'TXN-123', 'value' => 99.99]);

// Register custom schemas
$registry->register(new EventSchema(
    name: 'onboarding_complete',
    category: 'custom',
    description: 'User completed onboarding flow',
    requiredParams: ['steps_completed' => new EventParam(type: 'int', min: 1)],
    optionalParams: ['duration_seconds' => new EventParam(type: 'int')],
));
```

### Middleware Stack

```php
use ZeroBoiler\Analytics\Middleware\AnalyticsMiddlewareStack;
use ZeroBoiler\Analytics\Middleware\{ConsentGateMiddleware, ContextAttachmentMiddleware, SchemaValidationMiddleware};

$stack = new AnalyticsMiddlewareStack;
$stack->add(new ConsentGateMiddleware($consentGranted));
$stack->add(new ContextAttachmentMiddleware(['app_version' => '1.0.0']));
$stack->add(new SchemaValidationMiddleware(app(EventSchemaRegistry::class)));

$processed = $stack->process($event);
if ($processed !== null) {
    Analytics::trackEvent($processed);
}

// Pre-configured default stack
$stack = AnalyticsMiddlewareStack::createDefault(analyticsGranted: true, context: ['source' => 'web']);
```

### Debug Mode

```php
use ZeroBoiler\Analytics\Facades\Analytics;

Analytics::setDebug(true);
Analytics::isDebug();           // true
Analytics::shouldLogEvents();   // true if ANALYTICS_DEBUG_LOG_EVENTS=true
```

### Health Check (Programmatic)

```php
use ZeroBoiler\Analytics\Services\AnalyticsHealthService;

$health = app(AnalyticsHealthService::class);

$report = $health->report();
// $report['status'] — 'healthy', 'warning', or 'error'
// $report['providers'], $report['warnings'], $report['recommendations']

$health->isHealthy();            // bool
$health->getWarnings();          // list<string>
$health->getRecommendations();  // list<string>
```

## Inertia.js Integration

### Server-Side

```php
// routes/web.php
Route::middleware(['web', 'analytics.inertia'])->group(function () {
    // Inertia routes
});
```

The middleware injects `zbAnalytics` into every Inertia response:

```json
{
  "enabled": true,
  "trackingId": "uuid-from-cookie",
  "userId": "42",
  "consent": { "analytics_storage": "granted", "..." },
  "ga4MeasurementId": "G-XXXXX",
  "metaPixelId": "123456789",
  "trackLinks": { "enabled": false, "trackExternal": true, "trackInternal": false },
  "device": { "userAgent": "...", "ip": "...", "locale": "en" },
  "apiBase": "/api/analytics",
  "apiEnabled": true,
  "debug": false
}
```

### Client-Side (Svelte)

```javascript
import { page } from '@inertiajs/svelte';
import {
    init, destroy, initAll,
    trackEvent, trackPageView, trackScreenView,
    trackEcommerce, trackAbTestExposure,
    identify, alias, identifyWithTraits, setUserProperties,
    updateConsent, trackServerPageView,
    initScrollDepth, initInertiaPageViewTracker,
    initFormTracking, initErrorTracking,
    initLinkTracking, trackPerformance,
    flushQueue, getTrackingId, getApiBaseUrl, isInitialized,
    captureUTM, getUTMParams, clearUTMParams,
    pushToDataLayer, trackTiming,
} from '../resources/js/analytics';

// Initialize everything in one call (recommended for Svelte/Inertia)
$: if (page.props.zbAnalytics) {
    cleanup = initAll(page.props);
}

// Or fine-grained setup:
// $: if (page.props.zbAnalytics) {
//     init(page.props);
// }
//
// onMount(() => {
//     const cleanupScroll = initScrollDepth();
//     const cleanupInertia = initInertiaPageViewTracker();
//     const cleanupForm = initFormTracking();
//     const cleanupErrors = initErrorTracking({
//         ignorePatterns: ['ResizeObserver', 'Non-Error promise rejection'],
//     });
//
//     return () => {
//         cleanupScroll();
//         cleanupInertia();
//         cleanupForm();
//         cleanupErrors();
//         destroy();
//     };
// });
```

## Blade Integration (Traditional Laravel)

```blade
<!DOCTYPE html>
<html>
<head>
    @analyticsHead
</head>
<body>
    @analyticsBody
    <!-- Your content -->
</body>
</html>
```

Or auto-inject with middleware:

```php
Route::middleware(['analytics.scripts'])->group(function () {
    // Routes
});
```

## API Reference

### Public Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/analytics/health` | Health check (providers, consent, queue, metrics, replay, version) |
| `GET` | `/api/analytics/catalog` | Full event catalog (86 events, categories, provider mappings) |
| `GET` | `/api/analytics/stats` | Aggregated dashboard statistics (totals, top events, by-provider) |
| `GET` | `/api/analytics/stream` | Real-time event stream (cursor-based polling) |
| `GET` | `/api/analytics/stream/stats` | Event stream statistics |
| `GET` | `/api/analytics/export` | Export events (JSON, CSV, metrics, compliance) |
| `POST` | `/api/analytics/webhook/inbound` | Receive external events (HMAC-SHA256 verified) |
| `GET` | `/api/analytics/alerts` | Alert rules summary and recent alert history |
| `POST` | `/api/analytics/alerts/evaluate` | Evaluate all alert rules against current metrics |
| `GET` | `/api/analytics/funnels` | Funnel visualization data (conversion rates) |
| `POST` | `/api/analytics/funnels/compare` | Side-by-side funnel comparison |
| `GET` | `/api/analytics/funnels/drop-off` | Step-by-step drop-off analysis |
| `GET` | `/api/analytics/funnels/chart` | Chart.js/Recharts-compatible funnel data |
| `GET` | `/api/analytics/lifecycle` | Lifecycle event mapping configuration |
| `GET` | `/api/analytics/correlation/patterns` | Frequent event patterns |
| `GET` | `/api/analytics/correlation/transitions` | Top event transitions |
| `GET` | `/api/analytics/correlation/predict` | Next-event prediction |
| `GET` | `/api/analytics/correlation/summary` | Correlation analysis summary |
| `GET` | `/api/analytics/broadcast` | Broadcast channel configuration info |
| `GET` | `/api/analytics/tenant` | Multi-tenant isolation status + rate limits |
| `GET` | `/api/analytics/retention` | Data retention policy summary |
| `GET` | `/api/analytics/gate` | Feature gate status for current user |
| `GET` | `/api/analytics/gate/definitions` | Feature + plan tier definitions (for client-side) |
| `GET` | `/api/analytics/dlq` | Dead letter queue (failed events list) |
| `GET` | `/api/analytics/dlq/summary` | Dead letter queue summary |
| `DELETE` | `/api/analytics/dlq` | Clear all dead letter queue entries |
| `GET` | `/api/analytics/realtime` | Real-time event aggregation snapshot |
| `GET` | `/api/analytics/realtime/top-events` | Real-time top events ranking |
| `GET` | `/api/analytics/ab-tests/{experimentId}` | A/B test results for an experiment |
| `GET` | `/api/analytics/snapshots/daily` | Daily analytics snapshot |
| `GET` | `/api/analytics/snapshots/hourly` | Hourly analytics snapshot |
| `GET` | `/api/analytics/snapshots/comparison` | Day-over-day comparison |
| `GET` | `/api/analytics/kpi` | SaaS KPI summary (MRR, churn, trial conversion) |
| `GET` | `/api/analytics/kpi/mrr-history` | MRR history over time |
| `GET` | `/api/analytics/utm/sources` | Top UTM sources |
| `GET` | `/api/analytics/utm/campaigns` | Top UTM campaigns |
| `GET` | `/api/analytics/utm/breakdown` | UTM parameter breakdown |
| `GET` | `/api/analytics/report` | Full analytics report |
| `GET` | `/api/analytics/report/summary` | Report summary |
| `GET` | `/api/analytics/report/top-events` | Report top events |
| `GET` | `/api/analytics/report/trending` | Trending events |
| `GET` | `/api/analytics/report/provider-stats` | Provider-specific stats |

### Authenticated Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/analytics/events` | Track a single event |
| `POST` | `/api/analytics/batch` | Track up to 25 events |
| `POST` | `/api/analytics/identify` | Link client ID ↔ user ID + traits |
| `POST` | `/api/analytics/pageview` | Server-side page view (ad-blocker resistant) |
| `POST` | `/api/analytics/consent` | Update consent signals |
| `POST` | `/api/analytics/opt-out` | Per-user tracking opt-out (GDPR) |
| `POST` | `/api/analytics/opt-in` | Override previous opt-out preference |
| `GET` | `/api/analytics/preference` | Check tracking preference status |
| `POST` | `/api/analytics/tenant/config` | Update per-tenant analytics config |
| `POST` | `/api/analytics/ab-tests/{experimentId}/exposure` | Record A/B test exposure |
| `POST` | `/api/analytics/ab-tests/{experimentId}/conversion` | Record A/B test conversion |
| `DELETE` | `/api/analytics/ab-tests/{experimentId}` | Delete A/B test data |

All authenticated endpoints use `auth:sanctum` + throttle middleware (60 req/min).

### Health Response

```json
{
  "status": "ok",
  "version": "163.0.0",
  "providers": {
    "ga4": { "status": "ok", "measurement_id": "G-XXXXX" },
    "gtm": { "status": "ok", "container_id": "GTM-XXXXXXX" },
    "meta": { "status": "ok" },
    "plausible": { "status": "ok" },
    "posthog": { "status": "ok" }
  },
  "consent": { "analytics_storage": "granted", "ad_storage": "granted", "..." },
  "queue": { "enabled": true, "queue": "analytics" },
  "metrics": { "total_dispatched": 1234, "total_failed": 2 },
  "replay": { "enabled": true, "pending": 0 },
  "timestamp": "2026-08-07T12:00:00Z"
}
```

## Event Catalog Reference

### E-commerce Events

| Class | GA4 Name | Meta Equivalent |
|-------|----------|----------------|
| `ViewItemEvent` | view_item | ViewContent |
| `AddToCartEvent` | add_to_cart | AddToCart |
| `RemoveFromCartEvent` | remove_from_cart | RemoveFromCart |
| `ViewCartEvent` | view_cart | ViewCart |
| `BeginCheckoutEvent` | begin_checkout | InitiateCheckout |
| `AddPaymentInfoEvent` | add_payment_info | AddPaymentInfo |
| `PurchaseEvent` | purchase | Purchase |
| `RefundEvent` | refund | Refund |
| `WishlistEvent` | add_to_wishlist | AddToWishlist |
| `SelectItemEvent` | select_item | ViewItem |
| `SelectPromotionEvent` | select_promotion | ViewContent |
| `ViewPromotionEvent` | view_promotion | ViewContent |

### SaaS Lifecycle Events (38 events)

| Class | Event Name | Meta Equivalent |
|-------|-----------|-----------------|
| `SignUpEvent` | sign_up | CompleteRegistration |
| `LoginEvent` | login | Login |
| `LogoutEvent` | logout | Logout |
| `TrialStartEvent` | start_trial | StartTrial |
| `TrialEndEvent` | end_trial | TrialEnded |
| `TrialConvertedEvent` | trial_converted | TrialConverted |
| `SubscriptionEvent` | subscribe | Subscribe |
| `SubscriptionResumedEvent` | subscription_resumed | SubscriptionResumed |
| `PlanUpgradeEvent` | plan_upgrade | PlanUpgrade |
| `PlanDowngradeEvent` | plan_downgrade | PlanDowngrade |
| `CancellationEvent` | cancellation | CancelSubscription |
| `FeatureUsedEvent` | feature_used | FeatureUsed |
| `RevenueEvent` | revenue_tracked | Purchase (mapped) |
| `MilestoneReachedEvent` | milestone_reached | MilestoneReached |
| `InviteSentEvent` | invite_sent | InviteSent |
| `IntegrationConnectedEvent` | integration_connected | IntegrationConnected |
| `CohortAssignedEvent` | cohort_assigned | CohortAssigned |
| `CohortRetentionEvent` | cohort_retention | CohortRetention |
| `CohortChurnEvent` | cohort_churn | CohortChurn |
| `CohortConversionEvent` | cohort_conversion | CohortConversion |
| `CohortMigrationEvent` | cohort_migration | CohortMigration |
| `CohortEngagementEvent` | cohort_engagement | CohortEngagement |
| `InviteSentEvent` | invite_sent | InviteSent |
| `IntegrationConnectedEvent` | integration_connected | IntegrationConnected |
| `SubscriptionRenewalEvent` | subscription_renewal | SubscriptionRenewal |
| `AccountActivatedEvent` | account_activated | AccountActivated |
| `AccountDeactivatedEvent` | account_deactivated | AccountDeactivated |
| `PasswordChangedEvent` | password_changed | PasswordChanged |
| `PasswordResetEvent` | password_reset | PasswordReset |
| `ProfileUpdatedEvent` | profile_updated | ProfileUpdated |
| `EmailVerifiedEvent` | email_verified | EmailVerified |
| `TeamCreatedEvent` | team_created | TeamCreated |
| `TeamMemberJoinedEvent` | team_member_joined | TeamMemberJoined |
| `TeamMemberRemovedEvent` | team_member_removed | TeamMemberRemoved |
| `RoleChangedEvent` | role_changed | RoleChanged |
| `PaymentFailedEvent` | payment_failed | PaymentFailed |
| `PaymentSucceededEvent` | payment_succeeded | PaymentSucceeded |
| `PaymentMethodAddedEvent` | payment_method_added | PaymentMethodAdded |
| `InvoiceGeneratedEvent` | invoice_generated | InvoiceGenerated |
| `CreditAppliedEvent` | credit_applied | CreditApplied |

### Engagement Events

| Class | Event Name | Meta Equivalent |
|-------|-----------|----------------|
| `PageViewEvent` | page_view | PageView |
| `ScrollDepthEvent` | scroll_depth | ScrollDepth |
| `ClickEvent` | click | Click |
| `FormStartEvent` | form_start | Lead |
| `FormSubmitEvent` | form_submit | Lead |
| `SearchEvent` | search | Search |
| `ShareEvent` | share | Share |
| `ErrorEvent` | error | Error |
| `TimeOnPageEvent` | time_on_page | TimeOnPage |
| `CampaignAttributionEvent` | campaign_attribution | CampaignAttribution |
| `ScreenViewEvent` | screen_view | ViewContent |
| `AbTestExposureEvent` | ab_test_exposure | ABTestExposure |
| `NotificationEvent` | notification | Notification |
| `WebVitalsEvent` | web_vitals | WebVitals |
| `JSErrorEvent` | js_error | Error |
| `TimingEvent` | timing | Timing |
| `SessionStartEvent` | session_start | SessionStart |
| `SessionEndEvent` | session_end | SessionEnd |
| `OutboundClickEvent` | outbound_click | OutboundClick |
| `FileDownloadEvent` | file_download | FileDownload |
| `VideoPlayEvent` | video_play | VideoPlay |

### Generic

| Class | Description |
|-------|-------------|
| `CustomEvent` | Arbitrary event name + params |

## Server-Side Auto-Tracking

The `ServerSideTracker` automatically maps Laravel framework events to analytics events:

```
Illuminate\Auth\Events\Login       → LoginEvent       (auth.login)
Illuminate\Auth\Events\Registered   → SignUpEvent      (auth.register)
Illuminate\Auth\Events\Logout      → LogoutEvent      (auth.logout)

subscription.created    → SubscriptionEvent  (subscription.created)
subscription.upgraded   → PlanUpgradeEvent    (subscription.upgraded)
subscription.downgraded → PlanDowngradeEvent  (subscription.downgraded)
subscription.cancelled  → CancellationEvent   (subscription.cancelled)
trial.started           → TrialStartEvent     (trial.started)
trial.ended             → TrialEndEvent       (trial.ended)
feature.used            → FeatureUsedEvent    (feature.used)
```

Configurable per-event in `config/zeroboiler.php` under `auto_track.events`. Supports Eloquent model event listeners too.

## JS Client API Reference

### Core

| Function | Description |
|----------|-------------|
| `init(pageProps)` | Initialize from Inertia props |
| `initAll(pageProps, options?)` | Initialize + all auto-trackers (one-call setup) |
| `destroy()` | Cleanup listeners, timers, state |
| `destroyAll()` | Cleanup all auto-initialized trackers + destroy |
| `isInitialized()` | Check if analytics is active |
| `getVersion()` | Get library version string (e.g. '2.44.0') |
| `getTrackingId()` | Get server-generated tracking UUID |
| `getApiBaseUrl()` | Get configured API base URL |
| `trackEvent(name, params, options?)` | Track event (auto-batched, `{immediate: true}` to bypass) |
| `flushQueue()` | Flush batch queue immediately |
| `trackPageView(title?, location?, referrer?)` | Client + server page view |
| `trackScreenView(name, options?)` | SPA screen view |

### E-commerce

| Function | Description |
|----------|-------------|
| `trackEcommerce(name, data)` | Track with GA4 ↔ Meta conversion |
| `trackWishlist(item)` | Wishlist add with GA4 + Meta |
| `trackSelectItem(items, listId?, listName?)` | Product selection from list |
| `trackPromotionView(promotion)` | Promotion impression |
| `trackPromotionClick(promotion)` | Promotion click |

### Identity

| Function | Description |
|----------|-------------|
| `identify(userId?)` | Link tracking ID with user |
| `alias(previousId, newId)` | Merge identities |
| `identifyWithTraits(traits)` | Identify + set traits |
| `setUserProperties(properties, userId?)` | Set user traits |
| `updateConsent(signals)` | Update GDPR consent |
| `trackServerPageView(options?)` | Server-side page view (ad-block resistant) |

### Auto-Trackers (all return cleanup functions)

| Function | Description |
|----------|-------------|
| `initSessionTracking(options?)` | Session start/end tracking with idle timeout |
| `initInertiaPageViewTracker()` | Auto page view on navigation |
| `initScrollDepth()` | Scroll depth at 25/50/75/90% |
| `initFormTracking(options?)` | form_start + form_submit |
| `initErrorTracking(options?)` | JS errors + unhandled rejections |
| `initLinkTracking(options?)` | Outbound/internal link clicks |
| `initWebVitals(options?)` | Core Web Vitals (LCP, INP, CLS, TTFB, FCP) |
| `initSessionHeartbeat(seconds?)` | Periodic session heartbeat (10–300s, tracks `session_heartbeat` events) |
| `stopSessionHeartbeat()` | Stop the session heartbeat timer |
| `isHeartbeatActive()` | Check if heartbeat is running |

### Session Helpers

| Function | Description |
|----------|-------------|
| `recordSessionEvent()` | Increment session event counter |
| `recordSessionPageView()` | Increment session page view counter |
| `getSessionState()` | Get current session state (debug) |

### UTM

| Function | Description |
|----------|-------------|
| `captureUTM()` | Capture UTM from URL |
| `getUTMParams()` | Get current UTM params |
| `hasUTMParams()` | Check if UTM captured |
| `clearUTMParams()` | Clear UTM state |

### Event Catalog

| Function | Description |
|----------|-------------|
| `fetchEventCatalog(options?)` | Fetch event catalog from server (cached) |
| `getCachedCatalog()` | Get cached catalog without fetching |
| `clearCatalogCache()` | Clear the cached catalog |

### GDPR Tracking Preferences

| Function | Description |
|----------|-------------|
| `optOutTracking()` | Per-user tracking opt-out (disables all tracking) |
| `optInTracking()` | Override previous opt-out preference |
| `getTrackingPreference()` | Check current tracking preference |

### Performance

| Function | Description |
|----------|-------------|
| `trackPerformance(metric, value, params?)` | Web Vitals (LCP, CLS, INP) |
| `trackTiming(name)` | Performance API timing |
| `pushToDataLayer(data)` | GTM dataLayer push |

## Admin Commands

```bash
# Overview of all analytics configuration
php artisan zb:analytics:overview

# Send test event to all providers
php artisan zb:analytics:test

# Use GA4 debug/validate endpoint
php artisan zb:analytics:test --validate

# Custom event name
php artisan zb:analytics:test --event=custom_test

# Export event catalog as JSON (default)
php artisan zb:analytics:export

# Export as CSV
php artisan zb:analytics:export --format=csv

# Export as Markdown table
php artisan zb:analytics:export --format=markdown

# Export to file
php artisan zb:analytics:export --output=docs/event-catalog.json

# Filter by category
php artisan zb:analytics:export --category=ecommerce --format=markdown

# Revenue analytics report
php artisan zb:analytics:revenue-report

# Dry-run a revenue event
php artisan zb:analytics:revenue-report --dry-run --event=mrr --amount=5000

# Comprehensive health diagnostic
php artisan zb:analytics:health

# Health diagnostic as JSON (for CI/monitoring)
php artisan zb:analytics:health --json

# Health diagnostic without recommendations
php artisan zb:analytics:health --no-recommendations

# Dashboard data export (structured JSON)
php artisan zb:analytics:dashboard

# Dashboard with metrics and health included
php artisan zb:analytics:dashboard --include-metrics --include-health

# Pretty-printed dashboard output
php artisan zb:analytics:dashboard --pretty
```

## Testing

```bash
composer test          # Run all Pest tests
composer test:ci      # Run with coverage
composer ci           # Full CI (Pint + PHPStan 9 + Rector + Tests)
```

### Production Readiness

All source files use `declare(strict_types=1)`. Every leaf class is marked `final` (AnalyticsManager, trackers, services, middleware, pipeline, commands, etc.). The `AnalyticsEvent` DTO is `readonly`. All constructors on leaf classes declare `:void` return types. The package requires **PHP 8.5+** with `minimum-stability: stable`.

Run the structural verification suite:

```bash
composer test -- --filter=ProductionReadinessTest
```

This validates strict types, `final` modifiers, interface implementations, readonly DTOs, composer metadata, and absence of TODO/FIXME markers across all 817 source files.

## Troubleshooting

### Events not appearing in GA4
- Verify `ANALYTICS_GA4_ENABLED=true` and `ANALYTICS_GA4_MEASUREMENT_ID` is set
- Check `ANALYTICS_GA4_API_SECRET` for server-side Measurement Protocol
- Enable debug mode (`ANALYTICS_DEBUG_ENABLED=true`, `ANALYTICS_DEBUG_LOG_EVENTS=true`) to inspect dispatched events
- Use `php artisan zb:analytics:test --validate` to test against GA4's debug endpoint

### Consent blocking events
- Default consent is `granted` — set `ANALYTICS_CONSENT_DEFAULT=denied` for GDPR-safe defaults
- If using a cookie banner, call `Analytics::updateConsent()` from the JS client after user consent
- Check browser console for gtag consent update errors

### Inertia props not showing zbAnalytics
- Ensure the `analytics.inertia` middleware is registered on your Inertia routes
- Verify at least one provider is enabled (check with `php artisan zb:analytics:overview`)
- If using a custom middleware group, make sure it runs after `HandleInertiaMiddleware`

### Queue jobs failing
- Run `php artisan queue:work --queue=analytics` to process the analytics queue
- Set `ANALYTICS_QUEUE_ENABLED=false` for synchronous dispatch during debugging
- Check `ANALYTICS_QUEUE_CONNECTION` if using a non-default queue connection

### Identity not linking (anonymous → authenticated)
- Ensure the `zb_analytics_id` cookie is set by the Inertia middleware (check browser cookies)
- The JS client sends `X-Analytics-Client-Id` header on API requests
- Call `identify()` after login to explicitly link identities
- Check `UserIdentityTracker::onLogin()` is being called (use ServerSideTracker auto-track)


## Upgrading

```bash
composer update zeroboiler/analytics
```

See [CHANGELOG.md](CHANGELOG.md) for all version notes and breaking changes.

## Contributing

```bash
git clone https://github.com/zeroboiler/analytics.git
cd analytics
composer install
composer ci  # Pint + PHPStan + Rector + Tests
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full release history.

## License

MIT — see [LICENSE](LICENSE) for details.
