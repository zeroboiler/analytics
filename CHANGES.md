# Changelog

All notable changes to the `zeroboiler/analytics` package will be documented in this file.

## [Unreleased]

## [2.87.0] - 2026-08-08

### Added
- **SaaSMetricsBenchmarkService** — Industry-standard benchmarking engine for 24 key SaaS metrics across 5 categories (revenue, conversion, retention, engagement, funnel). Percentile-based scoring (p25/p50/p75/p90), batch comparison, report cards with prioritized recommendations, and quick-start targets. Based on OpenView, KeyBanc, ProfitWell, Tomasz Tunguz, and SaaS industry research data.
- **Benchmark config** — `zeroboiler.analytics.benchmarks` section with `enabled`, `cache_ttl`, `industry`, and `company_stage` settings.
- **Benchmark API endpoints** — `GET /api/analytics/benchmarks` (list all), `GET /api/analytics/benchmarks/{metric}` (single), `GET /api/analytics/benchmarks/compare` (batch comparison), `GET /api/analytics/benchmarks/report-card` (grades + recommendations), `GET /api/analytics/benchmarks/quick-start` (8 most impactful targets).
- **JS client: fetchBenchmarks()** — Fetch all benchmarks with optional category filter.
- **JS client: fetchBenchmark(metric)** — Fetch single metric thresholds.
- **JS client: compareBenchmarks(metrics)** — Compare metric values against industry benchmarks.
- **JS client: fetchBenchmarkReportCard(metrics)** — Full report card with grades and priorities.
- **JS client: fetchBenchmarkQuickStart()** — 8 essential benchmark targets for onboarding.
- **TypeScript: BenchmarkMetric, BenchmarkComparison, BenchmarkBatchResult, BenchmarkReportCard, BenchmarkQuickStartMetric** interfaces and all 5 function signatures.
- **Test suite: V87SaaSBenchmarkTest** — 25+ tests covering benchmark data integrity, percentile scoring (higher_better + lower_better), batch comparison, report cards, quick-start metrics, category filtering, boundary conditions, gap calculations, disabled service behavior, and consistency checks.

### Changed
- Version bump to 2.87.0 across all version references (AnalyticsEvent, composer.json, JS client ×3, TypeScript, AnalyticsManager).

## [2.86.0] - 2026-08-08

### Added
- **ExportEvent** — Data export tracking event for GDPR compliance monitoring and churn prediction. Captures format (csv, json, pdf, xlsx), resource type, and record count. GA4: `file_download`, Meta: `ExportData`.
- **ImportEvent** — Data import tracking event for onboarding optimization and power user identification. Captures format, resource type, record count, and success status. GA4: `file_upload`, Meta: `ImportData`.
- **EventCatalog::quickStart()** — Returns the 12 essential events every SaaS should track on day one, with category breakdown and funnel coverage (signup, trial, revenue, engagement). The "hello world" set for immediate actionable analytics.
- **EventCatalog::privacySafeEvents()** — Returns events safe to track without collecting any PII (page_view, scroll_depth, click, search, web_vitals, etc.). Ideal for privacy-first and cookieless implementations.
- **EventCatalog::gdprSensitiveEvents()** — Returns events that typically contain or imply PII and need extra consent gating (sign_up, login, payment events, profile events, etc.).
- **EventCatalog::saasAcquisitionEvents()** — Returns acquisition-focused events for marketing analytics and CAC calculations.
- **EventCatalog::saasMonetizationEvents()** — Returns 21 revenue-focused events for LTV, MRR, and revenue analytics.
- **AnalyticsManager::exportEvent()** — Convenience method for tracking data exports with format, resource, and record count.
- **AnalyticsManager::importEvent()** — Convenience method for tracking data imports with format, resource, record count, and success status.
- **AnalyticsManager::trackFunnel()** — Convenience method for tracking multi-step funnel progression with funnel name, step name, step number, and total steps metadata.
- **AnalyticsManager::quickStartEvents()** — Returns the quick-start event set for onboarding guidance.
- **JS client: trackExport()** — Client-side data export tracking with format and optional resource/recordCount.
- **JS client: trackImport()** — Client-side data import tracking with format, optional resource/recordCount, and success status.
- **JS client: getQuickStartEvents()** — Fetches the 12-event quick-start set from the server for onboarding checklists.
- **AARRR classification** — `export` → operational, `import` → activation.
- **Test suite: V86QuickStartPrivacyTest** — 45+ tests covering new events, quick-start set, privacy-safe events, GDPR-sensitive events, acquisition/monetization sets, catalog integrity, and AARRR classification.

### Changed
- Version bump to 2.86.0 across all version references (AnalyticsEvent, composer.json, JS client ×3, TypeScript, AnalyticsManager, ServiceProvider, controller ×115+).
- Total catalog events: 90 → 92 (added export, import).
- SaaS event count: 50 → 52 (added export, import).
- Export/import events added to productGrowthEvents, allLifecycleEvents, saasEssential, industryStandard (medium tier), and recommendedInstrumentation (enterprise).

## [2.85.0] - 2026-08-08

### Added
- **EventFingerprintService** — Content-aware event fingerprinting with cache-based deduplication. Generates deterministic fingerprints from event name, client ID, user ID, and sorted params (nulls filtered, floats rounded, booleans normalized). Configurable dedup window (`dedup.window_seconds`), max fingerprints, and cache prefix. 6 new PHP tests.
- **Inertia middleware: Funnel readiness props** — Exposes `funnelReadiness` in zbAnalytics: signup, purchase, subscription, and overall funnel conversion readiness scores. Enables client-side instrumentation guidance.
- **Inertia middleware: Recommended events** — Exposes `recommendedEvents` in zbAnalytics: up to 10 untracked events from the starter instrumentation set with category and priority. Computed from onboarding gap analysis.
- **Inertia middleware: Dedup config** — Exposes `dedup` in zbAnalytics: enabled flag and windowSeconds for client-side debounce tuning.
- **JS client: getFunnelReadinessFromProps()** — Synchronous funnel readiness accessor from Inertia props.
- **JS client: getRecommendedEvents()** — Returns untracked recommended events with name, category, and priority.
- **JS client: getDedupConfig()** — Returns client-side dedup configuration for event debouncing.
- **TypeScript: FunnelReadiness, RecommendedEvent, DedupConfig interfaces** — New type definitions for v2.84.0 Inertia props.
- **Config expansion: dedup.window_seconds, dedup.max_fingerprints, dedup.cache_prefix** — New env-driven config keys for fine-grained dedup control.
- **Test suite: V84FingerprintInertiaPropsTest** — 32 tests covering fingerprint determinism, param normalization (order, nulls, floats, booleans, nested arrays), client/user ID inclusion, provider coverage, industry standard tiers, funnel events, PLG events, critical vs samplable separation.

### Changed
- Version bump to 2.84.0 across all version references (AnalyticsEvent, composer.json, JS client ×2, TypeScript, AnalyticsManager, AnalyticsEventController ×100+).

## [2.81.0] - 2026-08-08

### Added
- **RevenueForecastService** — SaaS revenue forecasting engine with MRR trend projection, LTV calculation, LTV:CAC ratio, CAC payback period, runway estimation, cohort retention curves, and MRR movement breakdown. Config-driven via `zeroboiler.analytics.forecasting`. 9 API endpoints.
- **ChurnPredictionService** — Weighted scoring model for user churn risk prediction. 10 configurable signals (days inactive, usage decline, support tickets, failed payments, feature adoption, contract expiration, billing disputes, login frequency, engagement score, plan downgrades). Classifies users as low/medium/high/critical risk. 5 API endpoints.
- **Config sections**: `forecasting` (enabled, cache_ttl, monthly_churn_rate, growth_rate, horizon_days, historical_window_days, avg_revenue_per_account), `churn_prediction` (enabled, cache_ttl, high/medium/critical risk thresholds, inactive_days_threshold, 10 signal weights).
- **JS client**: Revenue forecasting API helpers (fetchRevenueForecast, fetchRevenueForecastSummary, fetchRevenueProject, fetchLtv, fetchLtvCacRatio, fetchPaybackPeriod, fetchRunway, fetchCohortRetention, fetchMrrMovement), churn prediction helpers (fetchChurnScore, fetchChurnScoreBatch, fetchChurnCohortSummary, fetchChurnWeights, fetchChurnThresholds).
- **TypeScript**: ForecastPoint, ForecastSummary, LtvResult, LtvCacResult, PaybackResult, RunwayResult, MrrMovement, ChurnRiskProfile, ChurnSignal, ChurnThresholds interfaces.

### Changed
- Version bump to 2.81.0 across all version references (AnalyticsEvent, composer.json, JS client, TypeScript, AnalyticsManager, ServiceProvider, controller).
- AnalyticsEventController: Fixed missing `$this->config` property (ConfigRepository) required by revenue forecasting and churn prediction endpoints.

## [2.79.0] - 2026-08-08

### Added
- **SlaBreachEvent** — Enterprise SLA breach monitoring event. Tracks uptime, response time, and resolution time threshold violations with severity classification (minor/major/critical). Fields: sla_type, threshold, actual, unit, severity, customer_id. Registered in SaaSEvents catalog with GA4/PostHog mappings.
- **PaymentMethodUpdatedEvent** — Payment method change tracking event. Captures method type (credit_card, bank_transfer, paypal), change type (added, updated, removed, set_default), and processor (stripe, paypal, braintree). Important for RevOps analytics and payment optimization. Registered in SaaSEvents catalog.
- **FeedbackEvent** — User satisfaction signal event for NPS, CSAT, CES, feature requests, and bug reports. Tracks score, rating (promoter/passive/detractor), category (ui, performance, feature, support), and source. Critical for product-market fit analysis. Registered in EngagementEvents catalog with GA4/Meta/PostHog mappings.
- **GoalConversionEvent** — Custom goal conversion tracking beyond standard e-commerce/SaaS events. Supports goal name, category (onboarding, activation, revenue, retention), goal value for ROI calculation, and funnel step tracking. Plausible-compatible with `goal` event name. Registered in EngagementEvents catalog.
- **EventPriorityCalculator expansion** — 11 previously unclassified events added to AARRR category map: integration_failed, sla_breach, payment_method_updated, cohort_assigned/retention/churn/conversion/migration/engagement, checkout_step → operational; feedback → retention; goal_conversion → revenue. Ensures 100% catalog coverage in AARRR classification.
- **EventCatalog::industryStandard()** — feedback and goal_conversion added to medium priority tier.

### Changed
- Version bump to 2.79.0 across AnalyticsEvent, composer.json, JS client (header + getVersion + _getInternalVersion), TypeScript definitions, AnalyticsManager, AnalyticsServiceProvider, controller endpoint version string.
- SaaS event count: 48 → 50 (added sla_breach, payment_method_updated).
- Engagement event count: 25 → 27 (added feedback, goal_conversion).
- Total catalog events: 86 → 90.
- All AARRR categories now have 100% classification coverage across the full 90-event catalog.

## [2.78.0] - 2026-08-08

### Added
- **EventCatalog::billingEvents()** — Get all billing and revenue-related SaaS events (payments, invoices, credits, billing retries, subscription value changes). Ideal for revenue dashboards and billing health monitors.
- **EventCatalog::productGrowthEvents()** — Get product-led growth and expansion events covering acquisition, activation, retention, revenue, and referral (AARRR framework). Useful for PLG dashboards.
- **EventCatalog::allLifecycleEvents()** — Get all events involved in the complete user lifecycle (AARRR/Pirate Metrics). Combines activation, checkout, SaaS funnel, retention, billing, and growth events.
- **LifecycleEventMapper** — 7 new conversion & expansion lifecycle mappings: `trial.converted`, `subscription.value_changed`, `usage.quota_reached`, `billing.retry`, `subscription.paused`, `workspace.created`, `milestone.reached` (38 total built-in mappings).
- **ServerSideTracker** — Added `trial.converted` custom event mapping.
- **Inertia Middleware** — Exposes `subscriptionTiers` (revenue config) and `identityAutoLink` (identity linking flag) as page props for client-side usage.
- **Config** — Added `identity.link_on_auth` for controlling auto-identify on authentication. Added `api.prefix`, `api.middleware`, `api.auth_middleware` for flexible route registration.
- **JS Client** — Auto-identify on init: when `identityAutoLink` is true and user is authenticated, silently sends identify event via sendBeacon for zero-latency client ID ↔ user ID linking.
- **TypeScript** — Added `SubscriptionTier` interface and `subscriptionTiers`/`identityAutoLink` props to `ZbAnalyticsConfig`.

### Changed
- README updated: 84 total events (E-commerce 13, SaaS 46, Engagement 25), LifecycleEventMapper documentation.
- Version bumped to 2.76.0 (composer.json, JS client, TypeScript definitions).

## [2.75.0] - 2026-08-08

### Added
- **SubscriptionValueChangedEvent** — MRR/ARR movement tracker. Captures previous/new subscription values, computed delta, currency, billing cycle, and reason (upgrade, downgrade, add_on, removal, discount, promotional). Registered in SaaSEvents catalog (46 total) with GA4/Meta/PostHog mappings. Auto-tracked via `subscription.value_changed` ServerSide event.
- **UsageQuotaReachedEvent** — Expansion/upsell signal event. Tracks feature/resource that hit quota limit, current usage, limit, unit, and auto-calculated usage percentage. Signals for upsell dashboards, churn prediction, and feature gating analytics. Auto-tracked via `usage.quota_reached` ServerSide event.
- **BillingRetryEvent** — Dunning lifecycle tracker. Captures retry status (attempted, succeeded, failed, exhausted), attempt number, plan, amount, currency, and failure reason (card_declined, insufficient_funds, expired_card). Critical for revenue recovery rate dashboards. Auto-tracked via `billing.retry` ServerSide event.
- **EventCatalog::criticalEvents()** — Business-critical events that should never be sampled or dropped. Revenue, authentication, subscription lifecycle, and e-commerce funnel events (21 events). Use with PriorityAwareFilter to guarantee dispatch.
- **EventCatalog::samplableEvents()** — Events safe for probabilistic sampling during traffic spikes. Engagement and low-criticality events (15 events) that can be dropped without impacting business metrics.
- **EventCatalog::checkoutFunnel()** — Complete e-commerce checkout funnel in order (10 events): view_item → select_item → add_to_cart → remove_from_cart → view_cart → add_to_wishlist → begin_checkout → add_payment_info → purchase → refund.
- **EventCatalog::activationFunnel()** — Product-led activation funnel events (14 events): sign_up → email_verified → login → onboarding_step → feature_used → engagement → milestone → trial → subscribe.
- **EventCatalog::retentionSignals()** — Retention health and churn risk signals (19 events): churn signals (cancellation, account_deactivated, plan_downgrade, payment_failed, billing_retry, feature_limit_reached, usage_quota_reached) and positive retention signals (login, feature_used, milestone_reached, plan_upgrade, subscription_value_changed, team_member_joined, integration_connected, payment_method_added).
- **ServerSideTracker: 3 new custom event mappings** — `subscription.value_changed`, `usage.quota_reached`, `billing.retry` registered in the default customEventMap for config-driven auto-tracking.

### Changed
- Version bump to 2.75.0 across AnalyticsEvent, composer.json, JS client (header + getVersion + _getInternalVersion), TypeScript definitions, AnalyticsManager, and ServiceProvider docblock.
- SaaS event count: 43 → 46 (added subscription_value_changed, usage_quota_reached, billing_retry).
- Total catalog events: 81 → 84.

## [2.74.0] - 2026-08-08

### Added
- **SubscriptionPausedEvent** — Typed event class for subscription pause lifecycle. Tracks pause patterns for retention analysis and revenue forecasting. Fields: plan, reason, pause_duration_days, user_id, client_id, + custom params. Registered in SaaSEvents catalog with GA4/Meta/PostHog mappings.
- **FeatureRequestEvent** — Typed engagement event for user feature requests and demand signals. Tracks feature description, category, source (in_app_modal, feedback_widget, support_ticket), vote count, request ID, and page URL. Registered in EngagementEvents catalog with GA4/Meta/PostHog mappings.
- **EventContextBuilder::withReferrer()** — Full referrer context enrichment with parsed components (host, path, search terms, search engine detection). Supports Google, Bing, Yahoo, DuckDuckGo, Baidu, Yandex, and generic `q`/`search`/`query`/`keyword` params.
- **EventContextBuilder::withTenancy()** — Multi-tenant context extraction for B2B SaaS. Auto-detects tenant_id from user attributes (tenant_id, team_id, organization_id, workspace_id, account_id) or X-Tenant-Id header. Supports tenant_name extraction.
- **EventContextBuilder::extractSearchTerm()** / **detectSearchEngine()** — Private helpers for search engine detection and search term extraction from referrer URLs.
- **EventCatalog::engagementEvents()** — Quick-access helper returning core engagement events for SaaS product usage funnels (17 events including page_view, scroll_depth, click, forms, search, feature_request, onboarding_step).
- **EventCatalog::saasFunnelEvents()** — Quick-access helper returning essential SaaS conversion funnel events (12 events: sign_up → trial_start → subscribe → plan_upgrade → cancellation → subscription_paused → milestone_reached).
- **Inertia middleware: consentVersion prop** — XXH128 hash of consent configuration (purposes + default) injected into zbAnalytics props. Enables client-side cache invalidation when consent config changes server-side.
- **Config: revenue.subscription_tiers** — Configurable subscription tier definitions for tier-level analytics. Each tier supports name, price, billing_cycle, and feature list. Used by SaaSAnalyticsService for plan-specific event enrichment.
- **ServerSideTracker: subscription.paused mapping** — Auto-track `subscription.paused` application events as SubscriptionPausedEvent.
- **TypeScript: EcommerceConfig interface** — Full IntelliSense support for e-commerce defaults (currency, brand, taxBehavior, shippingDefault).
- **TypeScript: ZbAnalyticsConfig extended** — Added consentVersion, ecommerce, consentLogEnabled, and version optional fields.

### Changed
- Version bump to 2.74.0 across AnalyticsEvent, composer.json, JS client (header + getVersion + _getInternalVersion), TypeScript definitions, AnalyticsManager, and ServiceProvider docblock.
- SaaS event count: 42 → 43 (added subscription_paused).
- Engagement event count: 24 → 25 (added feature_request).
- Total catalog events: 79 → 81.

## [2.70.0] - 2026-08-08

### Added
- **ProviderCircuitBreaker** — Circuit breaker pattern for analytics provider outage protection. Three states (closed/open/half_open) with configurable failure threshold, success threshold, cooldown, and half-open probe limits. Per-provider independent circuits. Methods: `shouldDispatch()`, `recordSuccess()`, `recordFailure()`, `reset()`, `trip()`, `getState()`, `getDashboard()`, `summary()`, `isEnabled()`.
- **EventComplianceService** — GDPR/SOC2/privacy compliance audit engine. Generates comprehensive reports covering PII exposure, consent coverage, retention policies, data minimization, and processing transparency. Produces 0-100 overall score with actionable recommendations. Methods: `generateReport()`, `analyzePiiExposure()`, `analyzeConsentCoverage()`, `analyzeRetention()`, `analyzeDataMinimization()`, `analyzeProcessingTransparency()`, `getScore()`, `invalidateCache()`.
- **AnalyticsRecoveryService** — Advanced DLQ recovery with retry budget tracking, batch recovery, health assessment, and 24h recovery history. Wraps DeadLetterQueueService with enterprise-grade management. Methods: `getBudget()`, `hasBudgetRemaining()`, `batchRecover()`, `getHistory()`, `recordHistory()`, `assessHealth()`, `summary()`.
- **Config sections**: `circuit_breaker` (failure_threshold, success_threshold, cooldown_seconds, half_open_max_probes), `compliance` (enabled, cache_ttl), `recovery` (enabled, max_recoveries_per_hour, batch_size).
- **API endpoints**: `GET /api/analytics/circuit-breaker`, `GET /api/analytics/circuit-breaker/summary`, `POST /api/analytics/circuit-breaker/{provider}/reset`, `POST /api/analytics/circuit-breaker/{provider}/trip`, `GET /api/analytics/compliance`, `GET /api/analytics/compliance/score`, `POST /api/analytics/compliance/invalidate`, `GET /api/analytics/recovery/budget`, `GET /api/analytics/recovery/health`, `GET /api/analytics/recovery/history`, `POST /api/analytics/recovery/batch`.
- **JS client functions**: `fetchCircuitBreakerDashboard()`, `fetchCircuitBreakerSummary()`, `fetchComplianceReport()`, `fetchComplianceScore()`, `fetchRecoveryBudget()`, `fetchRecoveryHealth()`, `fetchRecoveryHistory()`.
- **TypeScript definitions**: `CircuitBreakerDashboard`, `CircuitBreakerProviderState`, `ComplianceReport`, `RecoveryBudget`, `RecoveryHealth`, `RecoveryHistory` interfaces with full IntelliSense support.
- **V70CircuitBreakerComplianceRecoveryTest** — 45 test cases covering ProviderCircuitBreaker (12 tests), EventComplianceService (14 tests), AnalyticsRecoveryService (8 tests), and version consistency (11 tests).

### Changed
- Version bump to 2.70.0 across AnalyticsManager, composer.json, JS client (header + getVersion + _getInternalVersion), TypeScript definitions, 9 service files, all controller endpoint version strings, and ServiceProvider docblock.

## [2.69.0] - 2026-08-08

### Added
- **EventDeconflictionService** — Multi-provider event name collision detection & resolution. Analyzes all providers for duplicate event names, similar-name warnings, and reverse mapping conflicts. Methods: `analyze()`, `summary()`.
- **EventSchemaInferenceService** — Automatic schema inference from event class constructor signatures. Scans all event classes and generates typed parameter schemas. Methods: `inferAll()`, `inferForClass()`, `summary()`.
- **HeatmapAggregationService** — Click heatmap data collection and aggregation with grid-based bucketing for GDPR data minimization. Methods: `recordClick()`, `getHeatmapData()`, `getTrackedUrls()`, `getSummary()`, `clearUrl()`, `isEnabled()`.
- **AnalyticsRateLimitDashboardService** — Per-client, per-event-type rate limiting dashboard with cache-backed counters. Methods: `getDashboard()`, `getClientStatus()`, `resetClient()`, `checkLimit()`, `summary()`.
- **FeatureFlagIntegrationService** — Feature flag ↔ analytics bridge for event gating, annotation, and A/B test correlation. Supports external resolvers (LaunchDarkly, Unleash, Pennant) or config-driven static flags. Methods: `evaluate()`, `shouldDispatchEvent()`, `annotateEvent()`, `trackExposure()`, `trackUsage()`, `getCorrelationPayload()`, `isInRollout()`.
- **JS: Click heatmap tracking** — `recordHeatmapClick()`, `initHeatmapTracking()`, `fetchHeatmapData()` client-side functions for automatic click data collection with ignore selectors support.
- **JS: Event deconfliction helper** — `fetchDeconflictionReport()` to retrieve collision analysis from the server.
- **JS: Schema inference helper** — `fetchInferredSchemas()` to retrieve auto-generated event schemas.
- **JS: Rate limit dashboard** — `fetchRateLimitDashboard()` to view rate limit overview.
- **API endpoints** — `GET /api/analytics/deconfliction`, `GET /api/analytics/schemas/infer`, `POST /api/analytics/heatmap/click`, `GET /api/analytics/heatmap/data`, `GET /api/analytics/heatmap/urls`, `DELETE /api/analytics/heatmap/data`, `GET /api/analytics/rate-limits`, `GET /api/analytics/rate-limits/{clientId}`, `DELETE /api/analytics/rate-limits/{clientId}`.
- **V69HeatmapDeconflictionFeatureFlagTest** — 17 test cases covering HeatmapAggregationService, EventDeconflictionService, EventSchemaInferenceService, AnalyticsRateLimitDashboardService, and FeatureFlagIntegrationService.

### Changed
- Version bump to 2.69.0 across all source files, controller endpoints, services, JS client, and composer.json (85+ version references).

## [2.68.0] - 2026-08-08

### Added
- **V68PackageIntegrityTest** — Comprehensive 50+ test case validation suite covering version consistency (6 locations), file counts (224 src, 110 test, 73 events, 6 providers, 68 services, 14 pipeline filters, 8 commands), config section integrity (52+ sections), catalog structure (3 categories, typed classes, cross-provider mappings), SaaS event table completeness (38 events documented), PHP 8.5 strict types compliance across all source files, final class markers on all leaf classes, AnalyticsEvent readonly enforcement, roadmap completion verification (all 12 items implemented).
- **README comprehensive overhaul** — All stale metrics corrected: 73 events (was 70), 38 SaaS events (was 35), 224 source files (was 183), 110 test files (was 87), ~3600 JS LOC (was ~2500), 52+ config sections (was 47+), 120+ AnalyticsConfig typed methods (was 110+), 300+ tests (was 200+), 6 admin commands documented.
- **3 new SaaS events documented in README** — TrialConvertedEvent (trial_converted, TrialConverted), SubscriptionResumedEvent (subscription_resumed, SubscriptionResumed), MilestoneReachedEvent (milestone_reached, MilestoneReached) added to SaaS Lifecycle Events reference table.
- **Architecture tree updated** — EventContextEvent and EventPriority DTOs added; event counts per category corrected; JS LOC and config section counts updated.
- **Health Response version** — Updated example JSON to v2.68.0.
- **Upgrade guide** — New v2.68.0 section documenting all changes.

### Changed
- Version bump to 2.68.0 across AnalyticsManager, composer.json, JS client (header + getVersion + _getInternalVersion), TypeScript definitions, 9 service files (AnalyticsEventRouter, EventAliasResolver, EventCacheService, EventEnvelopeService, EventExporterService, EventForwardingService, EventSourceTagger), and all controller endpoint version strings (85 total version references).
- README Event Catalog reference updated from 70 to 73 events.
- README SaaS Lifecycle Events section header updated from 35 to 38 events.
- README ProductionReadinessTest file count updated from 189 to 224 source files.
- README API Reference catalog endpoint updated to 73 events.
- Total changelog entries: 40+ versions documented.

## [2.67.0] - 2026-08-08

### Added
- **DataWarehouseExportService** — ETL export engine for data warehouses (Snowflake, BigQuery, Redshift, Databricks). Supports NDJSON and CSV formats with configurable field selection, category/event filtering, null value handling, and file/toString export. Methods: `addEvent()`, `addEvents()`, `filterByCategory()`, `filterByEvent()`, `exportToString()`, `exportToFile()`, `clear()`, `count()`, `summary()`. Static helpers: `supportedFormats()`.
- **AnalyticsDashboardDataProvider** — Dashboard data aggregation service. Methods: `overview()` (full admin view with version, providers, catalog, metrics), `publicOverview()` (sanitized public view), `providerStatus()` (enabled/total counts), `kpiSection()` (optional KPI data), `healthSection()` (optional health data), `realtimeSection()` (optional realtime data).
- **EventPropertySchema** — Comprehensive event property schema validation system. Supports type checking (string, int, float, bool, array), format validation (email, url, currency, uuid, iso_date), range constraints (min, max), enum constraints, and required fields. Built-in schemas for purchase, sign_up, plan_upgrade, cancellation, error events. Methods: `validate()`, `hasSchema()`, `schemaCount()`, `getSchema()`, `defineProperty()`, `defineGlobalRule()`, `supportedTypes()`, `supportedFormats()`, `registerBuiltInSchemas()`.
- **Unified Dashboard command** — `zb:analytics:dashboard` exports dashboard data as structured JSON or table with optional metrics, health, and KPI sections.
- **AnalyticsScheduledReportCommand** — `analytics:report:schedule` for periodic analytics reports with configurable period (hourly/daily/weekly/monthly), format (json/table), and file output.
- **First-Touch UTM Cookie** — Auto-persists first-touch UTM parameters in a cookie for cross-session attribution. Set by Inertia middleware, used by all client events.
- **`property_schema` config section** — Controls event property schema validation (enabled, reject_invalid, log_violations, register_builtins).
- **`data_warehouse` config section** — Controls ETL export format, output path, field selection, headers, and null values.
- **`delivery_confirmation` config section** — Client feedback loop for critical events (purchase, sign_up, subscription, payment_succeeded).
- **V67DataWarehouseSchemaDashboardTest** — 55 test cases covering DataWarehouseExportService (11 tests), EventPropertySchema (14 tests), AnalyticsDashboardDataProvider (6 tests), version consistency, file counts, catalog integrity, config sections.

### Changed
- Version bump to 2.67.0 across AnalyticsManager, composer.json, JS client (header + getVersion + _getInternalVersion), TypeScript definitions, and all controller endpoint version strings.
- SaaS event catalog expanded from 70 → 73 events (trial_converted, subscription_resumed, milestone_reached).
- Config file expanded with 3 new sections (property_schema, data_warehouse, delivery_confirmation).
- Total config sections: 52+ (was 49+).

## [2.66.0] - 2026-08-07

### Added
- **SaaSConversionService** — Trial-to-paid conversion tracking, activation scoring (weighted milestones, 0-100 score), time-to-conversion analysis with distribution buckets, subscription win-back rate, conversion funnel analysis (5 steps: trial_started → first_feature_used → profile_completed → checkout_started → converted_to_paid), per-plan conversion breakdown, and comprehensive summary endpoint. Config-driven via `conversion_analytics` section with 8 default activation milestones.
- **3 New SaaS Event Classes** — `TrialConvertedEvent` (plan, trial_plan, trial_duration_days, conversion_source), `SubscriptionResumedEvent` (plan, previous_plan, days_since_cancellation, reactivation_source), `MilestoneReachedEvent` (milestone, category, value). All readonly final DTOs extending AnalyticsEvent.
- **EcommerceFormatConverter GA4→Meta Expansion** — 7 new methods: `ga4ToMetaView()`, `ga4ToMetaAddToCart()`, `ga4ToMetaBeginCheckout()`, `ga4ToMetaAddPaymentInfo()`, `ga4ToMetaAuto()` universal converter supporting all 7 e-commerce events (view_item, add_to_cart, begin_checkout, add_payment_info, purchase, refund, add_to_wishlist). Automatic Meta event name selection and parameter formatting.
- **4 Conversion API Endpoints** — `GET /api/analytics/conversion/summary` (full conversion analytics), `GET /api/analytics/conversion/funnel` (5-step funnel), `GET /api/analytics/conversion/activation/{userId}` (per-user activation score), `GET /api/analytics/conversion/time-to-convert` (TTC distribution with 7 time buckets).
- **Config: `conversion_analytics`** — New config section with `enabled`, `cache_ttl`, and configurable `activation_milestones` (8 defaults with weight/category).
- **5 JS Client Conversion Functions** — `trackTrialConversion()`, `trackSubscriptionResumed()`, `trackMilestone()`, `fetchConversionSummary()`, `fetchConversionFunnel()`. Full JSDoc with examples.
- **V66SaaSConversionEcommerceTest** — 42 test cases covering 3 new event classes (structure, immutability, inheritance), SaaSEvents catalog entries, EventCatalog registration/validation, EcommerceFormatConverter new methods (7 individual + ga4ToMetaAuto for all 7 events + null for unknown), SaaSConversionService existence/methods/constructor, config section, routes, controller endpoints, ServiceProvider binding, version consistency, JS client functions, provider mapping coverage.

### Changed
- Version bump to 2.66.0 across AnalyticsManager, composer.json, JS client (header + getVersion + _getInternalVersion), TypeScript definitions, ServiceProvider, 7 service files, and all controller endpoint version strings.
- SaaS event catalog expanded from 42 → 45 events (trial_converted, subscription_resumed, milestone_reached).
- Total event catalog expanded from ~70 → ~73 events.

## [2.63.0] - 2026-08-07

### Added
- **DeadLetterQueueService::replaySingle()** — Replay a single DLQ event by offset. Returns an `AnalyticsEvent` DTO and automatically removes the event from the DLQ. Useful for targeted event recovery without replaying the entire queue.
- **GdprErasureService::exportUser()** — GDPR DSAR data portability export. Collects analytics profile, attribution summary, tracking preferences, and event counts into a single structured array for compliance data exports.
- **POST /api/analytics/dlq/replay** — Replay all DLQ events through the analytics manager. Returns dispatched/failed counts and per-event error details.
- **POST /api/analytics/dlq/replay/{offset}** — Replay a single DLQ event by offset through the analytics manager. Returns 404 if offset is out of bounds, 500 if dispatch fails.
- **GET /api/analytics/gdpr/export** — Export all analytics data for the authenticated user (GDPR DSAR data portability). Returns profile, attribution, preferences, and event counts. Requires authentication.
- **V63DlqReplayGdprExportTest** — 24 test cases covering DeadLetterQueueService replay methods (existence, signatures, return types), GdprErasureService exportUser method (existence, signature, docblock), controller endpoints (dlqReplayAll, dlqReplaySingle, gdprExport), version consistency (6 files), route registration, ServiceProvider bindings, class structure integrity, stale version cleanup, strict types, and file count metrics.

### Changed
- Version bump to 2.63.0 across AnalyticsManager, composer.json, JS client (header + _getInternalVersion), TypeScript definitions, and 65+ controller endpoint version strings.
- All service summary/info methods updated from 2.61.0 → 2.63.0 (EventSourceTagger, EventForwardingService, EventAliasResolver, EventEnvelopeService, EventExporterService, EventCacheService, AnalyticsEventRouter).
- Routes file updated with 3 new endpoints (DLQ replay × 2, GDPR export).
- AnalyticsServiceProvider registers DLQ replay and GDPR export routes in both the route file and boot-time dynamic registration.

## [2.62.0] - 2026-08-07

### Added
- **CampaignRoiService** — Marketing campaign ROI tracking with spend registration, conversion recording, ROI/ROAS/CPA computation, per-campaign and aggregate metrics, top campaign ranking, channel grouping, and campaign removal. Config-driven via `campaign_roi.enabled` and `campaign_roi.cache_ttl`. Singleton registration in ServiceProvider.
- **DataMinimizationService** — Privacy-first data minimization engine (GDPR Article 5(1)(c) compliance). Enforces parameter stripping based on global allowlists, per-event allowlists, per-category allowlists, and mandatory strip lists. Internal params (prefixed with `_`) are always preserved. Includes `minimize()`, `previewStripped()`, `getGlobalAllowlist()`, `getStripParams()`, `getEventAllowlists()`, `getCategoryAllowlists()`, `summary()`, and audit logging toggle.
- **Config: `campaign_roi`** — New config section with `enabled` and `cache_ttl` settings (default: 24h).
- **Config: `data_minimization`** — New config section with `enabled`, `global_allowlist`, `event_allowlists`, `category_allowlists`, `strip_params` (defaults: user_agent, ip_address, raw_query, full_page_url), and `audit_log`.
- **Config: `delivery_confirmation`** — New config section with `enabled`, `critical_events` (defaults: purchase, sign_up, subscription, payment_succeeded), and `token_ttl` (default: 300s).
- **10 API endpoints** — Provider telemetry (GET/POST telemetry, POST telemetry/probe), Campaign ROI (GET campaigns/roi, GET campaigns/{id}/roi, POST campaigns/spend), Data Minimization (GET privacy/minimization, POST privacy/minimization/preview), SaaS Journey milestones (POST journeys/milestones, GET journeys/milestones/{journey}, GET journeys/list, DELETE journeys/{journey}).
- **AnalyticsConfig accessors** — `campaignRoiEnabled()`, `campaignRoiCacheTtl()`, `dataMinimizationEnabled()`, `dataMinimizationStripParams()`, `dataMinimizationAuditLog()`, `deliveryConfirmationEnabled()`, `deliveryConfirmationCriticalEvents()`, `deliveryConfirmationTokenTtl()`.
- **V62CampaignRoiDataMinimizationTest** — 30+ test cases covering CampaignRoiService (construction, spend registration, ROI computation, aggregation, sorting, channel grouping, removal), DataMinimizationService (construction, param stripping, internal param preservation, per-event allowlists, preview, summary, disabled passthrough), config accessors, version consistency, filesystem integrity, config file integrity, route registration, and ServiceProvider bindings.

### Changed
- Version bump to 2.62.0 across AnalyticsManager, composer.json, JS client (header + _getInternalVersion), TypeScript definitions.
- Routes file updated with 10 new endpoints for telemetry, campaign ROI, data minimization, and journey milestones.
- AnalyticsServiceProvider registers CampaignRoiService (singleton) and DataMinimizationService (bind).
- AnalyticsConfig::summary() now includes campaign_roi, data_minimization, and delivery_confirmation sections.

## [2.58.0] - 2026-08-07

### Added
- **JS Consent Purposes API** — Four new client-side functions for GDPR consent banner integration: `getConsentPurposes()`, `getConsentPurposeKeys()`, `getOptionalConsentPurposes()`, and `buildConsentSignals()`. These read the `consentPurposes` Inertia prop (injected by `HandleInertiaAnalytics`) and provide a purpose → Consent Mode v2 signal mapper with automatic `necessary` grant enforcement.
- **TypeScript ConsentPurpose type** — New `ConsentPurpose` interface with `label`, `required`, `default` fields. Added to `ZbAnalyticsConfig.consentPurposes` and all 4 new function declarations.
- **V58VersionConsistencyConsentPurposesTest** — 35+ test cases.

### Changed
- **Version unification to 2.58.0** — All 75+ version strings (AnalyticsManager, AnalyticsEventController 65 instances, 6 service files, JS client, TypeScript, composer.json) unified. Eliminated stale 2.52.0, 2.54.0, 2.57.0 references.
- Total test files: 100 (was 99)

## [2.57.0] - 2026-08-07

### Added
- **Event Schema Validation API** — 4 new REST endpoints for schema registry access: `GET /api/analytics/schemas` (all schemas with optional ?category filter and ?compact mode), `GET /api/analytics/schemas/summary` (category counts, parameter type distribution, avg params/event), `GET /api/analytics/schemas/{eventName}` (single schema with parameter type/maxLength details), `POST /api/analytics/schemas/validate` (validate event payload against schema with optional ?sanitize=1). All endpoints expose the existing EventSchemaRegistry via JSON API for client-side validation, documentation generation, and admin dashboards.
- **AnalyticsScheduledReportCommand** — Artisan command (`analytics:report:schedule`) for generating periodic analytics reports via Laravel Scheduler. Supports `--period` (hourly/daily/weekly/monthly), `--format` (json/table), `--output` (write to disk), and `--all` (all periods). Table format displays top events, provider breakdown, and key metrics. Designed for cron-based or scheduled delivery.
- **Config: `scheduled_reports`** — New config section with `enabled`, `output_path`, `auto_archive`, and `archive_days` settings. Example scheduler integration documented in config comments.
- **4 new routes** — Schema Validation API routes registered in both `routes/analytics.php` and `AnalyticsServiceProvider`.
- **V57SchemaApiScheduledReportTest** — 30+ test cases covering EventSchemaRegistry (catalog coverage, category grouping, validation, type checking, missing params, parameter listing), AnalyticsScheduledReportCommand (class structure, signature, method signatures, return types), controller schema methods (existence, return types, parameter signatures), config integrity, version consistency (6 files), route registration, filesystem integrity, and source file counts.

### Changed
- Version bump to 2.57.0
- AnalyticsManager::version() returns '2.57.0' (was '2.56.0')
- Composer version updated to 2.57.0
- JS client version string updated to 2.57.0 (was 2.56.0)
- TypeScript definitions version updated to 2.57.0 (was 2.56.0)
- EventSourceTagger::_version updated to 2.57.0 (was 2.56.0)
- EventEnvelopeService::summary() version updated to 2.57.0 (was 2.56.0)
- All 21 controller endpoint version strings updated to 2.57.0
- Total source files: 195 (was 194)
- Total test files: 96 (was 95)
- Total config sections: 50+ (was 48+)

## [2.56.0] - 2026-08-07

### Added
- **EventContextEvent DTO** — Fully-qualified analytics event with rich context envelope. Wraps a base AnalyticsEvent with session, device, geolocation, identity, UTM attribution, referrer, and consent context. Supports `fromEvent()` shorthand, `toArray()` serialization, `flattenedParams()` for provider dispatch (underscore-prefixed keys), `hasContext()` and `hasFullIdentity()` checks.
- **EventEnvelopeService** — Builds context-rich event envelopes from HTTP requests. Auto-enriches events with session, device (User-Agent parsing), geolocation (header strategy), identity (user_id/client_id from auth + cookie), UTM params, referrer, consent state, and metadata. Config-driven section toggles via `zeroboiler.analytics.envelope`. Supports `build()` (from request) and `buildFromEvent()` (server-side/queue) methods.
- **ConsentAwareFilter** — Granular consent-aware pipeline filter. Evaluates each event against consent purposes (analytics, functional, marketing, necessary) before dispatch. Events mapped to purposes via configurable rules — page_view→analytics, purchase→analytics+functional, error→necessary. Supports per-user granular consent via ConsentLogService, global ConsentState fallback, purpose→signal mapping (purposeToSignalMap), `isPermitted()` check for client-side gating, and custom purpose mapping overrides.
- **JS client consent-aware pre-queue** — Buffers events fired before consent resolution. `consentGranted()` replays queued events, `consentDenied()` discards them. Max 50 events buffered with LRU eviction. New exports: `consentGranted()`, `consentDenied()`, `getConsentState()`, `getConsentPreQueueCount()`, `resetConsentState()`.
- **3 new API endpoints** — `GET /api/analytics/consent/purposes` (purpose definitions + event→purpose mapping), `GET /api/analytics/consent/envelope-info` (envelope service status + active sections), `GET /api/analytics/consent/history` (authenticated, per-user consent change audit trail).
- **2 new config sections** — `envelope` (9 toggles for context section enrichment), `consent_purposes` (enabled + strict mode for granular consent filtering).
- **2 new service bindings** in AnalyticsServiceProvider: EventEnvelopeService (with 7 optional service dependencies), ConsentAwareFilter (with ConsentLogService).
- **V56EventEnvelopeConsentPreQueueTest** — 45+ test cases covering EventContextEvent DTO (7 tests: construction, fromEvent, toArray, flattenedParams, hasContext, hasFullIdentity), EventEnvelopeService (5 tests: build, disabled, activeSections, summary), ConsentAwareFilter (15 tests: disabled, necessary, identify, denied/granted analytics, denied functional, granted ecommerce, denied marketing, fail-open, purposeToSignalMap, getRequiredPurposes, isPermitted, setPurposeMapping, getPurposeMap, per-user consent lookup), config integrity (2 tests), version consistency (5 tests), route registration, filesystem integrity (strict types, docblocks, final classes), JS client consent exports, TypeScript definitions.

### Changed
- Version bump to 2.56.0
- AnalyticsManager::version() returns '2.56.0' (was '2.55.0')
- Composer version updated to 2.56.0
- JS client version string updated to 2.56.0 (was 2.54.0)
- TypeScript definitions version updated to 2.56.0 (was 2.54.0)
- EventSourceTagger::_version updated to 2.56.0 (was 2.52.0)
- All 13 controller endpoint version strings updated to 2.56.0
- Total source files: 194 (was 191)
- Total test files: 95 (was 93)
- Total config sections: 48+ (was 46+)

## [2.50.0] - 2026-08-07

### Added
- **PostHog e-commerce format conversion** — `EcommerceFormatConverter::ga4ToPosthogProperties()`, `ga4ToPosthogPurchase()`, `ga4ToPosthogRefund()`, `buildPosthogPurchase()` for full GA4→PostHog item/transaction parameter transformation
- **3-provider purchase builder** — `EcommerceFormatConverter::buildPurchaseEvent()` now supports `posthog` provider alongside `ga4` and `meta`
- **PostHog catalog mapping table** — `EventCatalog::allPosthogMappings()` returns complete event→PostHog name mapping, `posthogNameFor()` for single lookups
- **V50SaaSStarterIndustryStandardTest** — Comprehensive 10-section test suite validating event catalog completeness, cross-provider mappings, e-commerce format conversion, config structure, filesystem integrity, JS client exports, and source file metrics

### Changed
- Version bump to 2.50.0
- `EventCatalog::allPosthogNames()` refactored to use shared `posthogNameFor()` method
- `EcommerceFormatConverter::buildPurchaseEvent()` type union expanded to `'ga4'|'meta'|'posthog'`
- JS client version string updated to 2.50.0
- TypeScript definitions version updated to 2.50.0
- All version consistency tests updated to 2.50.0

## [2.49.0] - 2026-08-07

### Added
- **EventAliasResolver** — Maps 100+ common event name aliases/abbreviations to canonical catalog names. Supports CamelCase→snake_case (AddToCart→add_to_cart), abbreviations (signup→sign_up, addtocart→add_to_cart, pageview→page_view), PostHog convention stripping ($signup→sign_up), Plausible variants, custom config aliases. Runtime add/remove alias management, batch resolution, reverse lookup (getAliasesFor). Config-driven via `zeroboiler.analytics.aliases`.
- **EventCacheService** — High-performance L1 (in-memory) + L2 (Laravel cache) layered event lookup cache. Caches catalog entries, event names, total counts, GA4↔Meta format conversions. LRU eviction policy, configurable TTL, warmUp() for pre-loading, flush/flushMemory, detailed stats (hits, misses, hit_rate). Config-driven via `zeroboiler.analytics.event_cache`.
- **AnalyticsManager::resolveEventName()** — Convenience method for alias resolution via the Facade
- **AnalyticsManager::trackWithAlias()** — Track events using potentially aliased names (resolves then dispatches)
- **Facade proxy methods** — `resolveEventName()`, `trackWithAlias()` added to Analytics facade
- **6 new AnalyticsConfig accessors** — aliases(), eventCacheEnabled(), eventCacheMemoryMaxItems(), eventCacheMemoryTtl(), eventCacheTtl(), eventCachePrefix()
- **2 new config sections** — `aliases` (custom event name mappings), `event_cache` (L1/L2 cache configuration with env vars)
- **V49EventAliasResolverCacheTest** — 50+ test cases covering EventAliasResolver (resolution, alias management, batch, config, PostHog, CamelCase), EventCacheService (memory cache, L2, warmUp, flush, stats, ecommerce caching, resolve-and-get), version consistency, config integrity, architecture validation

### Changed
- Version bump to 2.49.0
- AnalyticsManager::version() returns '2.49.0'
- Composer version updated to 2.49.0
- JS client version string updated to 2.49.0
- TypeScript definitions version updated to 2.49.0
- All 38+ controller endpoint version strings updated to 2.49.0
- EventSourceTagger::_version updated to 2.49.0
- EventForwardingService version strings updated to 2.49.0
- AnalyticsEventRouter version string updated to 2.49.0
- All version consistency tests updated to 2.49.0
- Total source files: 191 (was 189)
- Total test files: 93 (was 92)
- Total config sections: 44+ (was 42+)

## [2.42.0] - 2026-08-07

### Added
- **V42SaaSStarterFinalTest** — 45+ production-readiness test cases covering full event catalog integrity (68 events, typed classes, no CustomEvent, no duplicates, GA4/Meta mapping coverage), cross-provider mapping completeness (PostHog, Plausible, GA4→Meta), EcommerceFormatConverter bidirectional (GA4↔Meta item format), ConsentLogService GDPR purposes (4 purposes, necessary always granted), source file counts (183 src, 87 tests, 2400+ JS LOC), config section coverage (40+ sections), ServiceProvider binding count (50+), middleware/pipeline class counts, architecture validation (AnalyticsManager final, DTOs readonly, all trackers implement TrackerInterface), PHP 8.5 declare(strict_types=1) compliance across all source files, README documentation completeness, JS client feature parity
- **Comprehensive README update** — All stale numbers corrected: API endpoints 50+ (was 26), JS client ~2500 LOC (was ~1200), config sections 40+ (was 22), source files 183 (was 166), test files 87 (was 86), AnalyticsConfig typed methods 100+ (was 90+)
- **Event Catalog Reference complete** — All 35 SaaS events now documented in README reference table (16 were previously missing: AccountActivatedEvent, AccountDeactivatedEvent, PasswordChangedEvent, PasswordResetEvent, ProfileUpdatedEvent, EmailVerifiedEvent, TeamCreatedEvent, TeamMemberJoinedEvent, TeamMemberRemovedEvent, RoleChangedEvent, PaymentFailedEvent, PaymentSucceededEvent, PaymentMethodAddedEvent, InvoiceGeneratedEvent, CreditAppliedEvent, InviteSentEvent, IntegrationConnectedEvent, SubscriptionRenewalEvent)
- **GDPR features documented** — ConsentLogService and consent purposes feature added to Identity & GDPR section, Inertia props consent purposes documented
- **Health response version updated** — README example JSON now shows v2.42.0

### Changed
- Version bump to 2.42.0
- AnalyticsManager::version() returns '2.42.0' (was '2.41.0')
- Composer version updated to 2.42.0
- JS client version string updated to 2.42.0
- TypeScript definitions version updated to 2.42.0
- EventSourceTagger::_version updated to 2.42.0
- Controller version strings updated to 2.42.0 on all endpoints (26 occurrences)
- README architecture section updated (50+ API endpoints, 183 source files, 87 test files)
- README SaaS Lifecycle Events table header updated (35 events)
- README upgrade guide: v2.42.0 section added

## [2.41.0] - 2026-08-07

### Fixed
- Removed deprecated `setAccessible(true)` calls in test files (PHP 8.5 compliance)

### Added
- **TypeScript type definitions** — `resources/js/analytics.d.ts` with full IntelliSense/auto-complete support for all 50+ exported functions. Covers ZbAnalyticsConfig, ConsentSignals, TrackLinksConfig, DeviceContext, AutoTrackConfig, PerformanceConfig, AnalyticsEvent, EventCatalog, Ga4Item, EcommerceData, PromotionData, ScreenViewOptions, SessionState, and all exported function signatures. Extends `@inertiajs/core` PageProps interface with `zbAnalytics` property.
- **`flushPendingOnUnload()`** — Uses `navigator.sendBeacon()` to reliably flush batched events on page unload/navigate-away. Prevents data loss when users close tabs or navigate during the 5-second flush interval. Automatically registered in `init()` and cleaned up in `destroy()`.
- **AnalyticsOverviewCommand feature list updated** — Now includes all 40+ features including TypeScript types, sendBeacon unload flush, and event replay queue.
- **V35TypeScriptClientUpgradeTest** — 30+ new test cases covering TypeScript type definitions file existence and content, JS client version consistency (2.35.0), sendBeacon unload flush implementation, version string alignment (manager, composer, JS, source tagger), event catalog integrity (52 events), all config sections, all PHP source files, test file count, and no stale version references.

### Changed
- Version bump to 2.35.0
- AnalyticsManager::version() returns '2.35.0' (was '2.34.0')
- Composer version updated to 2.35.0
- JS client version string updated to 2.35.0 (was 2.30.0 — fixed stale version)
- Controller version strings updated to 2.35.0 on all endpoints (29 occurrences)
- EventSourceTagger::_version updated to 2.35.0
- All test version assertions updated to 2.35.0 (27 occurrences across 15 test files)

## [2.34.0] - 2026-08-07

### Added
- Total event count: 52 (was 49) — 12 e-commerce + 19 SaaS + 21 engagement
- Total config sections: 36 (was 31)
- AnalyticsConfig summary() now includes referral, broadcast (extended), retention_policy (extended) sections
- **TenantIsolationService** — Multi-tenant analytics data isolation for B2B SaaS. Automatic tenant ID resolution from authenticated user attribute, request header (X-Tenant-ID), subdomain, or session. Per-tenant config overrides (disabled events, analytics enabled toggle), per-tenant rate limiting (events per hour), tenant context propagation to all events. Config-driven via `zeroboiler.analytics.tenant`.
- **DataRetentionPolicyService** — GDPR-compliant data retention management. Per-category retention periods (engagement: 30d, SaaS: 90d, ecommerce: 365d), PII category tracking, automatic event expiry checking, configurable auto-expire, retention clamping (1 day min, 10 year max), retention recording and summary reporting. Config-driven via `zeroboiler.analytics.retention`.
- **AnalyticsGateService** — Feature-flag-style analytics access control for tiered SaaS plans. 12 features (events, pageviews, ecommerce, cohorts, funnels, predictions, export, broadcast, alerts, profile, attribution, multi_tenant) with dependency enforcement. 4 plan tiers (Free, Starter, Pro, Enterprise) with per-feature enablement. Per-user and per-tenant overrides with cache-backed resolution. Plan auto-detection from user model attribute. Config-driven via `zeroboiler.analytics.gate`.
- **7 new API endpoints** — `GET /api/analytics/broadcast` (broadcast channel info), `GET /api/analytics/tenant` (tenant isolation status + rate limit), `POST /api/analytics/tenant/config` (per-tenant config update, auth), `GET /api/analytics/retention` (retention policy summary), `GET /api/analytics/gate` (feature gate status for current user), `GET /api/analytics/gate/definitions` (feature + plan tier definitions for client-side).
- **15 new AnalyticsConfig accessors** — broadcastEnabled(), broadcastChannelPrefix(), broadcastPrivateChannels(), broadcastValueThreshold(), tenantEnabled(), tenantResolutionStrategy(), tenantHeader(), tenantEventsPerHour(), retentionPolicyEnabled(), retentionPolicyAutoExpire(), retentionPolicyPiiCategories(), gateEnabled(), gateDefaultPlan(), gatePlanAttribute(). Summary expanded to 31 sections (was 27).
- **4 new service bindings** in AnalyticsServiceProvider: EventBroadcasterService, TenantIsolationService, DataRetentionPolicyService, AnalyticsGateService.
- **V30EnterpriseFeaturesTest** — 50+ new test cases covering EventBroadcasterService (6 tests: disabled default, enabled config, no broadcast when disabled, value threshold filter, critical events always broadcast, channel naming), TenantIsolationService (7 tests: disabled default, enabled config, event enrichment passthrough, set/get config, reset config, shouldTrack with disabled events, blocks disabled events), DataRetentionPolicyService (8 tests: disabled default, allows all when disabled, enabled with defaults, expiry date, period clamping, PII category, summary, record event), AnalyticsGateService (13 tests: disabled default, enabled with default plan, global override, pro plan features, enterprise plan features, unknown feature, event-by-category, available features, feature definitions, plan tiers, user override, summary), integration checks (5 tests: all services final, strict types, files exist, provider bindings, routes registered, config accessors).

### Changed
- Version bump to 2.30.0
- AnalyticsManager::version() returns '2.30.0' (was '2.29.0')
- Controller version strings updated to 2.30.0 on all endpoints
- Composer version updated to 2.30.0
- JS client version string updated to 2.30.0
- Total service count: 42+ services registered in ServiceProvider (was 38+)
- Total API endpoints: 37 public + 11 authenticated (was 31 + 10)
- Total config sections: 31 (was 27)
- Total PHP source files: 153 (was 149)

## [2.28.0] - 2026-08-06

### Added
- **LifecycleEventMapper** — Config-driven lifecycle event mapping service that automatically maps Laravel auth events, subscription lifecycle events, trial events, feature usage, e-commerce, and engagement events to typed ZeroBoiler analytics events. Supports 15 built-in mappings across 6 lifecycle categories (auth, subscription, trial, feature, ecommerce, engagement) with priority ordering, conditional filtering, custom param extractors, and config-driven enable/disable toggles. Custom mappings can be merged with or replace defaults.
- **EventCorrelationService** — Event pattern detection and predictive analytics service. Detects frequent event sequences (n-gram patterns), calculates transition probabilities, predicts next events, computes conversion rates for arbitrary event sequences, builds co-occurrence matrices, and tracks user journeys. All analysis is performed in-memory with optional cache persistence. Useful for identifying common user flows, optimizing conversion paths, and proactive analytics.
- **5 new API endpoints** — `GET /api/analytics/lifecycle` (lifecycle mapping configuration), `GET /api/analytics/correlation/patterns` (frequent event patterns), `GET /api/analytics/correlation/transitions` (top event transitions), `GET /api/analytics/correlation/predict` (next-event prediction), `GET /api/analytics/correlation/summary` (correlation analysis summary).
- **2 new config sections** — `lifecycle` (enabled, override_defaults, events toggles, custom_mappings) and `correlation` (enabled, cache settings, max_pattern_length, max_journeys_per_user).
- **11 new AnalyticsConfig accessors** — lifecycleEnabled(), lifecycleOverrideDefaults(), lifecycleEvents(), lifecycleCustomMappings(), correlationEnabled(), correlationCacheEnabled(), correlationCacheTtl(), correlationMaxPatternLength(), correlationMaxJourneysPerUser(). summary() now includes 24 sections (was 22).
- **2 new service bindings** in AnalyticsServiceProvider: LifecycleEventMapper, EventCorrelationService.
- **V28LifecycleCorrelationTest** — 30+ new test cases covering LifecycleEventMapper (construction, config, toggles, custom mappings, override_defaults, summary, registration), EventCorrelationService (recording, journeys, transitions, pattern detection, conversion rates, prediction, correlation matrix, clear, cache), and version/config consistency.

### Changed
- Version bump to 2.28.0
- AnalyticsManager::version() returns '2.28.0' (was '2.27.0')
- Controller version strings updated to 2.28.0 on lifecycle and correlation endpoints
- Total service count: 35+ services registered in ServiceProvider
- Total API endpoints: 28 public + 10 authenticated
- Total config sections: 24 (was 22)

## [2.27.0] - 2026-08-06

### Added
- **AnalyticsConfig expansion** — 25+ new typed config accessors: dedup, GDPR, attribution, profile, funnels, alerts, inbound webhook, pipeline (auto_metadata, schema_enrichment). Summary expanded to 22 config sections (from 14).
- **Alert + Funnel API routes** — `POST /api/analytics/alerts/evaluate`, `GET /api/analytics/alerts`, `GET /api/analytics/funnels`, `POST /api/analytics/funnels/compare`, `GET /api/analytics/funnels/drop-off`, `GET /api/analytics/funnels/chart` now registered in ServiceProvider (previously only in routes file).
- **JS client `getVersion()`** — Programmatic version export for diagnostics and API compatibility checks.
- **V27ConfigRoutesVersionConsistencyTest** — 35+ new test cases covering version string consistency (manager, facade, composer, JS), config accessor completeness (all new sections), route registration completeness (23 public + authenticated routes), controller version strings (3 endpoints), accessor return types (bool/int/string/array), event catalog integrity, and service provider binding verification.

### Fixed
- **Facade `directDispatch` return type** — Corrected from `void` to `bool` matching AnalyticsManager::directDispatch() actual return type.
- **Controller version strings** — `catalog()`, `health()`, and `stats()` endpoints updated from stale `2.24.0` to `2.27.0`.
- **JS client JSDoc version** — Updated from `2.26.0` to `2.27.0`.

### Changed
- Version bump to 2.27.0
- AnalyticsConfig summary() now returns 22 sections (was 14) — includes dedup, gdpr, attribution, profile, funnels, alerts, inbound_webhook, stream

## [2.23.0] - 2026-08-06

### Added
- **AnalyticsStatsService** — Dashboard aggregation service combining real-time event counts from EventAggregationService with dispatch/failure counters from AnalyticsMetrics. Provides `summary()`, `byCategory()`, and `byProvider()` (with success rates) for admin dashboards and monitoring.
- **InboundWebhookService** — Receive analytics events from external sources (Stripe webhooks, payment processors, partner integrations) via `POST /api/analytics/webhook/inbound`. Supports single events and batch payloads (up to 50 events). HMAC-SHA256 signature verification with configurable enforcement. Payload size limits (64KB default). All inbound events are tagged with `_source: webhook_inbound` for tracing.
- **EventMetadataEnricher** — New pipeline filter that auto-attaches session ID, page URL, referrer, and ISO-8601 timestamp to all events passing through the API pipeline. Metadata keys are prefixed with `_` to avoid collision with user params. Existing params are never overwritten.
- **SchemaEnricher** — New pipeline filter that validates events against the EventSchemaRegistry. Attaches `_schema_valid` flag and `_schema_errors` to event params. Non-blocking by default (attaches warning flags); supports strict mode that drops invalid events.
- **GET /api/analytics/stats** — Public endpoint returning aggregated analytics statistics (total events, top events, per-category breakdowns, per-provider metrics, replay queue status). Powers admin dashboards without authentication.
- **POST /api/analytics/webhook/inbound** — Public endpoint for receiving external analytics events with HMAC-SHA256 signature verification. Returns 200 (ok), 207 (partial), 400 (error), or 503 (disabled).
- **Pipeline config expansion** — New `auto_metadata` (default: true) and `schema_enrichment` (default: false) pipeline settings for configuring the enhanced API pipeline.
- **Inbound webhook config section** — `inbound_webhook` with 5 environment variables: `ANALYTICS_INBOUND_WEBHOOK_ENABLED`, `ANALYTICS_INBOUND_WEBHOOK_SECRET`, `ANALYTICS_INBOUND_WEBHOOK_REQUIRE_SIGNATURE`, `ANALYTICS_INBOUND_WEBHOOK_MAX_PAYLOAD`, `ANALYTICS_INBOUND_WEBHOOK_MAX_EVENTS`.
- **JS session heartbeat** — `initSessionHeartbeat(intervalSeconds)`, `stopSessionHeartbeat()`, `isHeartbeatActive()` exports. Periodically tracks `session_heartbeat` events with session duration, event count, and page path. Configurable interval (10–300 seconds, default: 60).
- **2 new service bindings** in AnalyticsServiceProvider: AnalyticsStatsService, InboundWebhookService.
- **V23StatsInboundMetadataTest** — 40+ new test cases covering EventMetadataEnricher (5 tests), SchemaEnricher (5 tests), AnalyticsStatsService (4 tests), InboundWebhookService (13 tests), pipeline integration (4 tests), version consistency (3 tests), and config expansion (3 tests).

### Changed
- Version bump to 2.23.0
- API controller constructor now accepts AnalyticsStatsService, InboundWebhookService, and EventSchemaRegistry as optional dependencies
- `buildPipeline()` enhanced to chain SchemaEnricher → UtmEnricher → EventMetadataEnricher in the correct order
- Health, catalog, and stats endpoint version strings updated to 2.23.0
- JS client version string updated to 2.23.0
- Inertia middleware version consistency maintained

## [2.22.0] - 2026-08-06

### Added
- **AnalyticsProfileService** — Per-user analytics profile aggregation stored in cache. Tracks event counts, lifetime value (LTV), first/last seen timestamps, funnel completion, engagement score (0–100), current plan, and user traits. Engagement score is calculated from event frequency (log scale), diversity, revenue activity, and funnel steps.
- **AttributionService** — First-touch and multi-touch UTM attribution tracking. Captures UTM parameters from requests, persists first-touch attribution (immutable after first visit), and maintains a rolling touch history (max 20 touchpoints). Provides attribution summary with source/medium/campaign aggregation and chronological journey.
- **EventInterceptorRegistry** — Global before/after event hooks. Register interceptors to modify, filter, or observe events before/after dispatch. Before-interceptors can cancel dispatch by returning null. After-interceptors receive a success flag and are exception-safe.
- **GdprErasureService** — GDPR "right to be forgotten" orchestration. Deletes analytics profile, attribution data, and tracking preferences in a single call. Designed to complement AnalyticsManager::resetIdentity() for complete provider-side erasure.
- **AnalyticsManager enhancements** — `interceptBefore()`, `interceptAfter()`, `interceptors()`, `getProfile()`, `getProfileSummary()` convenience methods. `directDispatch()` now returns `bool` (true if at least one provider succeeded).
- **New API endpoints** — `GET /api/analytics/profile` (authenticated, returns user profile summary), `DELETE /api/analytics/data` (authenticated, erases all user analytics data for GDPR compliance).
- **New config sections** — `attribution` (first-touch TTL, touch history TTL, max history, enabled toggle), `profile` (TTL, enabled toggle).
- **4 new service bindings** in AnalyticsServiceProvider: AnalyticsProfileService, AttributionService, GdprErasureService.
- **Facade proxy methods** — `interceptBefore()`, `interceptAfter()`, `interceptors()`, `getProfile()`, `getProfileSummary()`.
- **V22ProfileAttributionInterceptorTest** — 50+ new test cases covering EventInterceptorRegistry (7 tests), AnalyticsProfileService (13 tests), AttributionService (11 tests), GdprErasureService (6 tests), manager integration (7 tests), UtmAttribution (5 tests), config expansion (4 tests), service provider registration (2 tests).

### Changed
- Version bump to 2.22.0
- `directDispatch()` return type changed from `void` to `bool` — tracks whether at least one provider accepted the event
- Facade `@method` annotations updated with new return types for `directDispatch` and new methods
- Health/catalog endpoint version strings updated to 2.22.0
- JS client version string updated to 2.22.0

## [2.20.0] - 2026-08-06

### Added
- **EventDeduplicationService** — Cache-based event deduplication using SHA-256 fingerprints (name + client ID + user ID + params hash). Configurable dedup window (default: 10s) and max recent events (default: 500). Can be disabled globally via `ANALYTICS_DEDUP_ENABLED`.
- **DeviceContextService** — Zero-dependency User-Agent parser with browser, browser version, OS, OS version, device type (mobile/tablet/desktop/bot/unknown), and device brand detection. Supports Chrome, Firefox, Safari, Edge, Opera, Brave, Vivaldi, Arc, Samsung Browser, UCBrowser, Lynx, and IE.
- **IpAnonymizationService** — GDPR-compliant IP masking for IPv4 (configurable octet preservation) and IPv6 (configurable bit preservation). Supports `::ffff:` mapped addresses. Controlled via `zeroboiler.analytics.gdpr` config section.
- **SaasFunnelService** — Complete SaaS lifecycle funnel tracking across 5 funnels: Signup (5 steps), Trial (4 steps), Conversion (4 steps), Retention (4 steps), Expansion (4 steps). Each step produces a `funnel_{name}_{step}` event with funnel metadata for downstream funnel analytics.
- **New config sections** — `dedup` (event deduplication toggle), `gdpr` (IP anonymization settings), `funnels` (funnel tracking toggle)
- **4 new service bindings** in AnalyticsServiceProvider: EventDeduplicationService, DeviceContextService, IpAnonymizationService, SaasFunnelService
- **30+ new test cases** in V20DedupDeviceIpFunnelTest covering all new services

### Changed
- Version bump to 2.20.0
- Facade proxy methods: added isTrackingAllowed(), optOut(), optIn(), suppressClient(), transferClientToUser()
- Health/catalog endpoint version strings updated to 2.20.0
- JS client version string updated to 2.20.0

## [2.19.0] - 2026-08-06

### Added
- **6 Dedicated Cohort Typed Event Classes** — CohortAssignedEvent, CohortRetentionEvent, CohortChurnEvent, CohortConversionEvent, CohortMigrationEvent, CohortEngagementEvent with typed constructors replacing generic CustomEvent references
- **All 49 events now have typed classes** — Previously 6 cohort events used CustomEvent; now every event in the catalog has a dedicated typed class with enforced parameters
- **V219CohortTypedClassesTest** — 30+ new test cases covering all 6 cohort event constructors, parameter validation, engagement rate calculation, null/empty filtering, catalog integration (typed class verification, no CustomEvent, readonly final, subclass check), and unified catalog cross-validation

### Changed
- **SaaSEvents catalog** — All 6 cohort event entries now reference dedicated typed classes instead of CustomEvent
- **JS client** — Removed duplicate `initAll()` export at EOF; consolidated to single canonical implementation with full session tracking, config-driven Inertia settings, and proper cleanup
- **README** — Added "6 Dedicated Cohort Typed Classes" to SaaS Analytics features, expanded API Reference table to all 13 endpoints, added `zb:analytics:dashboard` command documentation, added v2.19.0 upgrade guide, updated Health Response version to 2.19.0
- Version bump to 2.19.0 — complete typed event coverage across the entire 49-event feature matrix

## [2.18.0] - 2026-08-06

### Added
- **Comprehensive SaaS starter test suite** — 60+ new test cases across 4 test files covering the complete 49-event feature matrix
- **V218SaasStarterComprehensiveTest** — Event catalog completeness, category structure, cross-provider mappings (GA4/Meta/PostHog/Plausible), typed class resolution, search, and required-key validation
- **V218EngagementEventClassesTest** — All 20 typed engagement event class constructors and DTO conversion (ClickEvent, ScrollDepthEvent, FormStartEvent, FormSubmitEvent, SearchEvent, ShareEvent, ErrorEvent, PageViewEvent, SessionStartEvent, SessionEndEvent, WebVitalsEvent, JSErrorEvent, OutboundClickEvent, TimingEvent, ScreenViewEvent, AbTestExposureEvent, NotificationEvent, CampaignAttributionEvent, TimeOnPageEvent)
- **V218EcommerceEventClassesTest** — All 12 typed e-commerce event class constructors, monetary param typing, and cross-provider param validation (ViewItemEvent through ViewPromotionEvent)
- **V218SaasEventClassesTest** — All 17 typed SaaS event class constructors including 6 cohort events (CohortAssignedEvent through CohortEngagementEvent), monetary param validation
- **`@dataProvider` data provider** for event entry key validation (name, class, ga4, meta, category)
- Total test files: 61, total test cases: 150+

### Changed
- Version bump to 2.18.0 — industry-standard SaaS analytics package
- All 12 roadmap items fully implemented and production-tested
- Full feature matrix: 49 events, 6 providers, 12 config sections, 57+ JS client exports, 6 admin commands

## [2.17.0] - 2026-08-06

### Added
- **`initAll()` — One-Call Setup** — Initialize analytics AND all auto-trackers (page views, scroll depth, form tracking, error tracking, link tracking, session tracking, Web Vitals) with a single function call. Returns a combined cleanup function for Svelte `onMount` compatibility. Recommended for Svelte/Inertia apps.
- **Dynamic API base URL** — JS client now reads `apiBase` from Inertia `zbAnalytics` props. All fetch URLs use the configured base URL instead of hardcoded `/api/analytics`. New `getApiBaseUrl()` export.
- **EventSchemaRegistry expansion** — Added schemas for `select_item`, `select_promotion`, `view_promotion` e-commerce events with GA4 provider mappings and typed parameter definitions.
- **`AnalyticsConfig::trackingPreferenceTtl()`** — Type-safe accessor for tracking preference cache TTL configuration. Added to `summary()` output.
- **20 typed engagement event classes** — All engagement events now have dedicated typed classes (JSErrorEvent, OutboundClickEvent, TimingEvent, SessionStartEvent, SessionEndEvent, WebVitalsEvent) instead of CustomEvent references.
- **Event catalog count** — Total events now 49 (12 e-commerce + 17 SaaS + 20 engagement)
- **`V33EngagementExpansionTest.php`** — 30+ new test cases covering all 20 engagement event classes, catalog completeness verification (total counts, category counts, typed class validation, GA4 mappings), and manager dispatch integration.

### Changed
- **JS client** — All API fetch URLs now use dynamic `${apiBaseUrl}` variable (was hardcoded `/api/analytics`). Configurable via `ANALYTICS_API_BASE_URL` → Inertia props → JS client.
- **README** — Updated event catalog reference with full engagement events table (20 entries including WebVitals, JSError, Timing, Session, OutboundClick). Updated feature list to 49 events and 50+ schemas. Added `initAll()` to quick start and JS API reference. Updated Svelte example to recommend `initAll()` as primary setup method.

## [2.16.0] - 2026-08-06

### Added
- **TrackingPreferenceService** — Per-user GDPR opt-out/opt-in with persistent cache storage (7-day TTL)
- **AnalyticsManager::optOut()** / **optIn()** — Persist user tracking preferences (beyond consent)
- **AnalyticsManager::isTrackingAllowed()** — Combined preference + consent check for tracking decisions
- **AnalyticsManager::suppressClient()** — Suppress anonymous client tracking before authentication
- **AnalyticsManager::transferClientToUser()** — Transfer client suppression to authenticated user on login
- **POST /api/analytics/opt-out** — API endpoint for per-user tracking opt-out
- **POST /api/analytics/opt-in** — API endpoint for per-user tracking opt-in
- **GET /api/analytics/preference** — Check tracking preference status (combined consent + opt-out)
- **EventCatalog::allPosthogNames()** — PostHog-compatible event names ($signup, $identify, etc.)
- **EventCatalog::allPlausibleNames()** — Plausible-compatible event names (filtered, pageview mapping)
- **EventCatalog::byProvider()** expanded — Now includes `posthog` and `plausible` provider keys
- **Config: `tracking_preference`** section — Cache TTL configuration for tracking preferences
- **JS client: `optOutTracking()`** — Client-side opt-out with immediate tracking stop
- **JS client: `optInTracking()`** — Client-side opt-in to restore tracking
- **JS client: `getTrackingPreference()`** — Fetch current preference state from server
- Fixed broken template literals in JS client (`***` → backticks)
- 20+ new tests covering TrackingPreferenceService and EventCatalog provider expansion

## [2.15.0] - 2026-08-06

### Added
- **SelectItemEvent** — GA4 `select_item` event for product list selection (e-commerce funnel)
- **SelectPromotionEvent** — GA4 `select_promotion` event for promotion click tracking
- **ViewPromotionEvent** — GA4 `view_promotion` event for promotion impression tracking
- **EcommerceEvents catalog** expanded to 12 events (from 9)
- **AnalyticsManager** convenience methods: `selectItem()`, `selectPromotion()`, `viewPromotion()`
- **EcommerceAnalyticsService** methods: `selectItem()`, `selectPromotion()`, `viewPromotion()`
- **EventTransformer** Plausible event mapping (`toPlausibleEventName()`, `toPlausibleEventMap()`, `transformForPlausible()`)
- **JS client** promotion tracking: `trackSelectItem()`, `trackPromotionView()`, `trackPromotionClick()`
- 3 new test suites covering events, catalog expansion, and Plausible transformer

## [2.14.0] - 2026-08-06

### Fixed
- **minimum-stability** changed from `dev` to `stable` for production readiness
- **Repository paths** fixed from `/tmp/*` to relative `../response`, `../dto`, `../value-objects`
- Added `sort-packages: true` to composer config
- Added `keywords` to composer.json for better discoverability
- Version bump to 2.14.0

## [2.13.0] - 2025-08-06

### Added
- **AnalyticsConfig** — Type-safe, single-entry-point config accessor with 60+ typed methods (no raw array access)
- **AnalyticsEventNameRule** — Laravel validation rule for analytics event names (format, catalog, strict whitelist modes)
- **EventTransformer** — Centralized cross-provider event format conversion (GA4 ↔ Meta, SaaS → PostHog)
- **AnalyticsRateLimiter** — Per-client rate limiting using Laravel's RateLimiter (client ID/IP based)
- **WebhookSignatureValidator** — HMAC-SHA256 webhook signature validation (X-ZB-Signature + X-Hub-Signature-256)
- **AnalyticsDashboardCommand** (`zb:analytics:dashboard`) — Export dashboard data as structured JSON/table
- 70+ new tests covering all Support layer classes

## [2.12.0] - 2025-08-06

### Added
- GA4 event tracking with typed parameters
- GTM container integration
- Meta Pixel event dispatch
- `AnalyticsHealthService` for provider health monitoring
- `EventDebounceFilter` for deduplication and throttling
- Client-side auto-tracking helpers (pageview, timing, performance)
- Session lifecycle tracking (start, end, heartbeat)
- Config-driven provider setup and feature flags
- MIT license

## [2.11.0] - 2025-07-01

### Added
- Client auto-tracking, performance events, session lifecycle
- MIT license
