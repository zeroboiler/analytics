# ZeroBoiler Analytics

[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Laravel 13+](https://img.shields.io/badge/Laravel-13%2B-red.svg)](https://laravel.com)
|[![Latest Version](https://img.shields.io/badge/version-105.0.0-blue)](https://github.com/zeroboiler/analytics)|
[![PHP 8.5+](https://img.shields.io/badge/PHP-8.5%2B-8892BF.svg)](https://www.php.net)

Industry-standard SaaS analytics for Laravel — production-ready event tracking across **10 providers** (GA4, GTM, Meta Pixel, Plausible, PostHog, Mixpanel, Amplitude, TikTok, LinkedIn, and generic HTTP) with a fully-featured JS client, auto-tracking, queue dispatch, identity resolution, cohort analytics, event replay, and GDPR consent.

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

### What's New in v105.0.0

**Phase 33–34 Version Integrity & SaaS Maturity — Comprehensive Audit & Fixes**:

- **Version sync fixes** — TypeScript definitions (analytics.d.ts) updated from 103.0.0 → 105.0.0, AnalyticsServiceProvider docblock updated from 104.0.0 → 105.0.0, package.json repository directory corrected to root
- **EventCatalog::metaNameFor()** — New symmetrical provider name lookup method for Meta Pixel (complements existing `posthogNameFor`, `plausibleNameFor`, `mixpanelNameFor`, `amplitudeNameFor`, `tiktokNameFor`, `linkedinNameFor`)
- **EventCatalog::summary()** — Now includes `infrastructure` category count and `with_tiktok`/`with_linkedin` provider coverage counts (was missing tiktok/linkedin)
- **EventCatalog::providerCoverage()** — Now includes `tiktok` and `linkedin` event name lists alongside counts (was only in counts, not in event lists)
- **EventCatalog::byProvider()** — Updated docblock to reflect all 8 providers (was outdated showing only 6)
- **Phase 34 Audit** — 25 new assertions verifying version consistency across all 5 package files, event catalog maturity (6 categories, 8 providers), config SaaS sections (queue, api, identity, ecommerce, lifecycle, consent, sampling), and package metadata integrity

### What's New in v104.0.0

**Phase 32 Industry-Standard SaaS Audit — Full SaaS Starter Verification** — Comprehensive production audit covering all 12 SaaS starter upgrade criteria:

- **Phase32IndustryStandardSaaSAuditTest** — 30 new assertions verifying complete SaaS starter maturity:
  - Criterion 1 (Event Catalog): EcommerceEvents, SaaSEvents, EngagementEvents catalogs verified with core events and provider mappings
  - Criterion 2 (Lifecycle Tracker): Config-driven LifecycleEventMapper + LifecycleEventSubscriber with diagnostic summary
  - Criterion 3 (Inertia Middleware): HandleInertiaAnalytics with page props, client ID cookie, auth state change detection
  - Criterion 4 (API Controller): AnalyticsEventController with track, batch, identify, consent, health, identity resolution, SaaS KPI, revenue forecast, and 200+ route endpoints
  - Criterion 5 (JS Client): 286-function analytics.js with init, trackEvent, trackPageView, identify, scrollDepth, debounce/throttle, e-commerce shorthands, sampling engine, debug logger, Inertia page view tracker, and Svelte composables
  - Criterion 6 (Event Queue): TrackAnalyticsEventJob, TrackAnalyticsEventBatchJob, QueuedAnalyticsDispatcher for async dispatch
  - Criterion 7 (Identity Linking): UserIdentityTracker, IdentityResolutionService, IdentityGraphService, CrossProviderIdentityService for client ID ↔ user ID mapping
  - Criterion 8 (E-commerce Helpers): EcommerceFormatConverter with GA4 → Meta/PostHog/Plausible/Mixpanel/Amplitude/TikTok/LinkedIn bidirectional conversions, universal multi-provider builder, convenience methods for purchase, refund, add_to_cart, view_item
  - Criterion 9 (Admin Commands): AnalyticsOverviewCommand (zb:analytics:overview) + AnalyticsTestCommand (zb:analytics:test) with JSON, dry-run, and lifecycle options
  - Criterion 10 (Config): 20+ config sections covering GA4, GTM, Meta, consent, auto-track, queue, lifecycle, API, identity, ecommerce, revenue, sampling, SLA, cost forecast, experiment analysis, deploy gate, broadcasting, budget optimizer, tenant dashboard, governance policies
  - Criterion 11 (Optional Providers): 10 tracker implementations (GA4, GTM, Meta Pixel, Plausible, PostHog, Mixpanel, Amplitude, TikTok, LinkedIn, Webhook) all implementing TrackerInterface
  - Criterion 12 (Tests + Version): strict_types coverage across 400+ source files, version sync across composer.json/package.json/analytics.js/AnalyticsEvent.php/README.md, readonly AnalyticsEvent DTO, TypeScript definitions, all catalog classes final with strict_types

- **Version bump** to 104.0.0 across all package files (composer.json, package.json, analytics.js, AnalyticsEvent.php, README.md)

### What's New in v103.0.0

**Client-Side Sampling Engine & Event Debug Logger** — Production-ready client-side event quality tools:

- **`shouldSampleEvent()` + `getSamplingDecision()`** — Config-driven sampling gate in `trackEvent()`. Deterministic hash-based sampling (`eventName:trackingId`) for consistent per-user/event decisions, or random fallback. Controlled via `zeroboiler.analytics.sampling` config (already exposed through Inertia props). Events outside the sampling rate are silently dropped with debug-only logging.
- **`getDebugEventLog()`, `getDebugEventLogStats()`, `clearDebugEventLog()`** — Dev-time ring buffer (200 events) for inspecting tracked/blocked events. Color-coded console output (green=queued, blue=immediate, yellow=sampled_out, red=consent_blocked). Network timing metadata on sends. Zero-overhead when debug mode is off.
- **`Phase31SamplingDebugAuditTest`** — 15 assertions: JS sampling engine, debug logger, TypeScript type definitions, version consistency, SaaS shorthand exports, core function exports, offline buffer support, sendBeacon reliability, strict_types compliance.

```javascript
// Check sampling decision for any event
const decision = getSamplingDecision('button_click');
console.log(decision.sampled); // true or false

// Inspect tracked events (debug mode only)
const log = getDebugEventLog(10);
console.table(log);

// Debug event stats
const stats = getDebugEventLogStats();
console.log(stats); // { total: 42, queued: 30, immediate: 10, sampled_out: 2 }
```

### What's New in v100.1.0

**Phase 28 Production Audit** — Comprehensive quality hardening across all 680 source files:

- **`Phase28ProductionAuditTest`** — 26 new assertions covering strict_types verification, exception hierarchy, tracker interface compliance, service provider registrations, and config integrity across all 680 source files
- **`SaaSReadinessAssessment`** — Config-driven SaaS analytics maturity scoring service (starter/intermediate/advanced/expert grades) with gap analysis, readiness gates, and quick-start recommendations
- **`SaaSFunnelDefinitions`** — Pre-defined SaaS funnel templates (signup, activation, trial conversion, purchase, subscription, retention) with step definitions, AARRR classification, and conversion tracking
- **`SaaSReadinessAssessmentTest`** — 308 assertions covering readiness scoring, gap detection, readiness gates, and maturity calculations
- **`SaaSFunnelDefinitionsTest`** — 272 assertions covering all 6 funnel templates, step validation, AARRR classification, and cross-funnel integration
- **Updated metrics**: 680 source files, 327 test files, 21,365+ assertions

### What's New in v100.2.0

**Provider Coverage Parity & Priority Scoring** — Cross-provider gap analysis and dynamic event instrumentation recommendations:

- **`EventCatalog::providerCoverageParity()`** — Per-provider coverage percentages with explicit gap lists (events without mappings). Identifies which events need additional provider mappings for TikTok, LinkedIn, Meta, and Plausible.
- **`EventCatalog::eventProviderMapping()`** — Per-event provider mapping breakdown. Returns all 8 provider mappings for any event, with mapped count for quick cross-provider compatibility checks.
- **`EventCatalog::fullyMappedEvents()`** — Returns all events with 100% provider coverage across all 8 providers (GA4, Meta, PostHog, Plausible, Mixpanel, Amplitude, TikTok, LinkedIn).
- **`EventCatalog::leastMappedEvents()`** — Returns events sorted by fewest provider mappings (ascending), useful for identifying coverage expansion candidates.
- **`EventCatalog::eventPriorityScore()`** — Numeric 0-100 priority score based on category weight, provider coverage, and tag bonuses (revenue, critical, conversion, GDPR). Complements `eventPriority()` which returns string levels.
- **`EventCatalog::topPriorityEvents()`** — Top-N events by priority score with tags, for admin dashboards and instrumentation guidance.
- **`EventCatalog::recommendedInstrumentationByScore()`** — Dynamic score-based tiering (starter ≥60, intermediate ≥40, advanced <40) complementing the curated `recommendedInstrumentation()` lists.
- **`Phase29ProductionAuditTest`** — 86 assertions covering all new methods: provider parity analysis, event mapping structure, priority scoring bounds, tier classification correctness, null-safe handling, and catalog integrity checks.

### What's New in v100.0.0

**Event Name Constants** — Type-safe event name constants for PHP and JavaScript, providing IDE autocompletion, refactoring support, and compile-time validation:

- **`SaaSEventConstants`** — 45+ SaaS lifecycle event name constants (`SIGN_UP`, `LOGIN`, `PLAN_UPGRADE`, `TRIAL_START`, `CANCELLATION`, etc.)
- **`EcommerceEventConstants`** — 15 e-commerce event name constants (`VIEW_ITEM`, `ADD_TO_CART`, `PURCHASE`, `REFUND`, etc.)
- **`EngagementEventConstants`** — 35+ engagement event name constants (`PAGE_VIEW`, `SCROLL_DEPTH`, `FORM_SUBMIT`, `SEARCH`, etc.)
- **`analytics.constants.js`** — JavaScript exports of all event names (`EcommerceEvents`, `SaaSEvents`, `EngagementEvents`, `AllEventNames`)
- **`isValidEventName()`** — JS type guard to validate event names at runtime
- **`getEventNamesByCategory()`** — Get event names filtered by category
- **TypeScript type definitions** — `EventName`, `EcommerceEventName`, `SaaSEventName`, `EngagementEventName` union types in `analytics.d.ts`

```php
use ZeroBoiler\Analytics\Events\SaaSEventConstants;
use ZeroBoiler\Analytics\Facades\Analytics;

// IDE autocomplete — no typos, refactoring support
Analytics::track(SaaSEventConstants::SIGN_UP, ['method' => 'email']);
Analytics::track(SaaSEventConstants::PLAN_UPGRADE, ['from' => 'starter', 'to' => 'pro']);
```

```javascript
import { trackEvent, SaaSEvents, EcommerceEvents } from '../resources/js/analytics.constants';

// TypeScript autocompletion — no typos
await trackEvent(SaaSEvents.PLAN_UPGRADE, { from_plan: 'starter', to_plan: 'pro' });
await trackEvent(EcommerceEvents.PURCHASE, { transaction_id: 'TXN-123', value: 99.99 });
```

### What's New in v99.0.0

- **Config-driven Lifecycle Mapping**: New `lifecycle` config section for declarative event mapping (custom_mappings, queue_events)
- **API Configuration Block**: New `api` config section with rate limiting, SDK token, batch size controls
- **Client Auto-Tracking Config**: New `client_auto_track` config section controlling page views, scroll depth, form tracking, error tracking, session tracking, idle timeout, and error ignore patterns
- **Inertia Auto-Track Props**: HandleInertiaAnalytics middleware now exposes `zbAnalytics.autoTrack` with full client-side config
- **Overview Command Enhancement**: `zb:analytics:overview` now displays lifecycle stats, queue status, API config, and auto-track settings
- **Test Command Lifecycle Mode**: `zb:analytics:test --lifecycle` validates all 62 built-in lifecycle event mappings
- **LifecycleEventMapper Constant**: Added `DEFAULT_MAPPING_COUNT` for programmatic access to mapping statistics
- **Integration Tests**: New `V99SaaSAnalyticsIntegrationTest` with 20+ assertions covering lifecycle, catalog, ecommerce, config, tracker, and TypeScript validation

### What's New in v98.0.0

**Event Tags System** — Semantic tag-based event classification system across the entire catalog. Every event now carries tags like `revenue`, `pii`, `critical`, `conversion`, `retention`, `gdpr`, `privacy_safe`, `samplable`, `b2b`, `plg`, and more. Query events by tag with AND/OR logic:

- **`EventTags::for('purchase')`** → `['ecommerce', 'revenue', 'conversion', 'critical', 'pii']`
- **`EventTags::tagged('critical')`** → All business-critical events (never sampled/dropped)
- **`EventTags::whereAll(['revenue', 'conversion'])`** → Events matching ALL tags (AND)
- **`EventTags::whereAny(['gdpr', 'b2b'])`** → Events matching ANY tag (OR)
- **`EventTags::groupedByTag()`** → Full tag → events mapping
- **`EventTags::stats()`** → Tag → event count summary

**SaaS Sub-Category Catalog** — The SaaS catalog (65+ events) now has structured sub-categories for fine-grained filtering:

- **`SaaSEventSubCategories::names()`** → `['auth', 'subscription', 'trial', 'billing', 'team', 'account', 'growth', 'integration', 'compliance', 'workspace', 'cohort', ...]`
- **`SaaSEventSubCategories::events('billing')`** → All billing-related SaaS events
- **`SaaSEventSubCategories::subcategoryFor('payment_succeeded')`** → `'billing'`
- **`SaaSEventSubCategories::grouped()`** → Full sub-category → entries mapping
- **`SaaSEventSubCategories::counts()`** → Event count per sub-category

**EventCatalog Integration** — Both systems are accessible through the unified EventCatalog facade:

- `EventCatalog::tagsFor('purchase')`, `EventCatalog::tagged('revenue')`, `EventCatalog::taggedAll(['critical', 'conversion'])`
- `EventCatalog::saasSubCategories()`, `EventCatalog::saasSubCategory('auth')`, `EventCatalog::saasSubCategoryFor('login')`

**Session Replay Svelte Composable** — Reactive Svelte composable for session recording and replay analytics integration. Bridges client-side session recording events with the ZeroBoiler analytics pipeline:

- **`useSessionReplay()`**: Full reactive composable with `start()`, `stop()`, `pause()`, `resume()` lifecycle methods
- **DOM mutation capture**: MutationObserver-based DOM change tracking with configurable batch intervals
- **Sanitized DOM snapshots**: Automatic PII redaction for passwords, credit cards, SSN fields, and `[data-sensitive]` elements
- **Click event capture**: Privacy-safe CSS selector generation using `data-testid` > `id` > `tag.class` hierarchy
- **JS error capture**: Automatic error tracking during recording sessions
- **Duration tracking**: Configurable max recording duration with auto-stop
- **Quality settings**: Configurable capture quality, FPS, and max duration
- **Provider integration**: Auto-detects PostHog session replay, Hotjar, or custom providers from Inertia props
- **Error recovery**: Recording errors surfaced via reactive `recordingError` store — never breaks the app
- **Session replay events**: Dispatched through standard analytics pipeline as `session_replay_start`, `session_replay_stop`, `session_replay_snapshot`, `session_replay_event`, `session_replay_pause`, `session_replay_resume`

**Version 97.0.0 — Full SaaS Analytics Suite Summary** — The package provides a complete industry-standard SaaS analytics solution:

- **Event Catalog**: 100+ typed events across Ecommerce (15), SaaS (65+), Engagement (35+), Security (8), Infrastructure (10) categories
- **10 Provider Trackers**: GA4, GTM, Meta Pixel, Plausible, PostHog, Mixpanel, Amplitude, TikTok, LinkedIn, Webhook
- **Full JS Client**: 7,400+ line analytics.js with batch queue, offline recovery, Web Vitals, consent sync, auto-identify
- **6 Svelte Composables**: `useAnalytics`, `useAnalyticsConfig`, `useLifecycle`, `usePerformanceTracker`, `useSessionReplay`, plus convenience stores
- **Server-Side Auto-Tracking**: Config-driven Laravel event → analytics event mapping with model listeners
- **Inertia Middleware**: Full page props injection with tracking ID cookie, consent, provider config, maturity scores
- **API Controller**: 200+ endpoints for events, batch, identify, consent, dashboards, funnels, cohorts, revenue, benchmarks
- **Queue System**: Async dispatch via serializable job classes compatible with all queue drivers
- **Identity Resolution**: Client ID ↔ User ID linking with auth state change detection
- **E-commerce Helpers**: Multi-provider format conversion (GA4 ↔ Meta ↔ PostHog)
- **80+ Admin Commands**: Overview, test, health, coverage, readiness, deployment gate, GDPR export, and more

### What's New in v92.0.0

**Auto Page-View Middleware** — Server-side automatic `page_view` event dispatch for every HTTP response. Complements the client-side page_view tracking in analytics.js by capturing bot traffic, API-driven navigation, and environments where client-side JS is disabled:

- **`AutoPageViewMiddleware`**: HTTP middleware that auto-dispatches page_view events with URL, referrer, user agent, status code, and response time
- **Path filtering**: Exclude patterns for internal routes (Telescope, Horizon, Pulse, Ignition, vendor, storage)
- **Method filtering**: Configurable HTTP method exclusions (default: OPTIONS, HEAD)
- **Status code filtering**: Only track specific status codes (default: 200, 301-308, 404)
- **Bot detection**: Lightweight user-agent pattern matching to filter crawlers (configurable: include or exclude)
- **Sampling rate**: Track only N% of requests for high-traffic sites
- **URL normalization**: Strip query params, truncate long URLs
- **Multi-tenant**: Auto-resolves tenant_id from request attributes or user model
- **Response metadata**: Includes response time (ms), content type, and page title extraction
- **Middleware alias**: Register as `analytics.pageview` route middleware or global middleware
- **Non-breaking**: Silently catches exceptions — never breaks the request lifecycle
- Config section `zeroboiler.analytics.auto_pageview`: `enabled`, `exclude_paths`, `exclude_methods`, `track_api`, `track_status_codes`, `bot_tracking`, `strip_query_params`, `max_url_length`, `sampling_rate`

**Event Broadcast Service** — Real-time analytics event streaming via Laravel Broadcasting (Pusher, Reverb, Soketi, Ably, Redis Pub/Sub):

- **`EventBroadcastService`**: Broadcasts validated analytics events to WebSocket channels for live admin dashboards
- **Public channel**: `analytics.events` — all qualifying events for global dashboards
- **Category channels**: `analytics.events.{category}` — filtered by ecommerce, saas, engagement, security
- **Tenant channels**: `analytics.tenant.{tenantId}` — private multi-tenant event streams
- **Admin channel**: `analytics.admin` — private admin-only event stream
- **Batch broadcasting**: `broadcastBatch()` for high-throughput scenarios — sends multiple events as a single WebSocket message
- **Sensitive param redaction**: Automatic redaction of PII (email, ip, phone, tokens) before broadcasting
- **Category filtering**: Configurable category whitelist to control which event types are broadcast
- **Channel introspection**: `channelsFor()` returns all channels an event would broadcast to (for auth)
- **Non-breaking**: Broadcasting failures are silently caught — never breaks the event pipeline
- Config section `zeroboiler.analytics.broadcasting`: `enabled`, `public_channel`, `category_channels`, `tenant_channels`, `admin_channel`, `include_params`, `sensitive_params`, `categories`

### What's New in v90.0.0

**AI Agent Access Events** — Security audit trail for non-human access. Track AI assistants (Claude, ChatGPT, Copilot, Hermes) interacting with sensitive resources:

- **New event class**: `AiAgentAccessEvent` with `agent`, `action`, `resource` parameters
- **Catalog entry**: Registered in `SecurityEvents` with GA4, Meta, PostHog, Mixpanel, Amplitude mappings
- **JS client helper**: `trackAiAgentAccess({ agent, action, resource, outcome })` for client-side tracking
- **TypeScript types**: Full type definitions in `analytics.d.ts`
- **GDPR data access audit event class**: `DataAccessAuditEvent` DTO with `dataType`, `accessor`, `accessLevel` parameters (was catalog-only, now has concrete implementation)

**Plausible Custom Events** — Enhanced Plausible integration with custom event properties support:

- **`trackCustomEvent()`**: Send custom events with `props` for Plausible dashboard segmentation
- **Referrer support**: Optional referrer URL for attribution accuracy
- **Error handling**: Logging on dispatch failure for observability

**PostHog GDPR Person Deletion** — Full GDPR Article 17 compliance for PostHog:

- **`deletePerson($distinctId)`**: Permanently remove a person and all their events from PostHog
- **Bearer auth**: Uses API key for authenticated deletion requests
- **Error handling**: Returns boolean + logs errors for failed deletions

### What's New in v88.0.0

**Revenue Checksum Service** — HMAC-SHA256 integrity verification for revenue-critical events. Prevents replay attacks and ensures data integrity between client-side and server-side event dispatch for purchases, subscriptions, refunds, and plan changes. Inspired by Stripe's webhook signature verification:

- **Checksum generation**: Deterministic HMAC covering transaction ID, value, currency, event type, and minute-granularity timestamp
- **Clock drift tolerance**: Validates against ±1 minute windows to handle client/server time skew
- **Replay prevention**: Cache-backed seen-checksum tracking with configurable TTL (default 24h)
- **Event signing**: `signEvent()` attaches checksum and timestamp directly to event params
- **Signed event validation**: `validateSignedEvent()` validates events with embedded checksums
- **Flexible enforcement**: `require_checksum` option to make checksum mandatory or optional
- Config section `zeroboiler.analytics.revenue_checksum`: `enabled`, `secret`, `replay_ttl`, `require_checksum`

**Event Deduplication Cache** — Redis/cache-backed enterprise-grade idempotency service. Prevents duplicate event processing within configurable time windows per event category:

- **Exact strategy**: Deduplicates identical events (same name + identity + parameter content hash)
- **Fuzzy strategy**: Deduplicates events with same name + identity within the window (rate limiting)
- **Category-specific windows**: Ecommerce (60s), SaaS (30s), Engagement (10s), Page View (5s), Custom (5s)
- **Smart parameter hashing**: Excludes internal params (`_` prefix), volatile fields (timestamp, session_id, page_url, referrer)
- **Pre-emptive dedup**: `markSeen()` for server-side events to prevent duplicate client tracking
- **Diagnostic summary**: Exposes configuration for admin commands and health checks
- Config section `zeroboiler.analytics.dedup_cache`: `enabled`, `strategy`, `windows`, `max_keys`

**SaaS Starter Validation Service** — Automated instrumentation completeness scoring against industry-standard SaaS event taxonomies:

- **Four scoring tiers**: Starter (0-40), Growth (41-70), Advanced (71-90), Enterprise (91-100)
- **Cumulative event requirements**: Each tier includes all lower tier requirements
- **Provider coverage validation**: Checks minimum provider count per tier (1-4 providers)
- **Tier auto-detection**: Analyzes current catalog to determine achieved tier
- **Quick-start checklist**: Prioritized list of events to add for reaching the next tier
- **Actionable recommendations**: Context-aware suggestions based on current score and gaps
- **Effort estimation**: Minimal/Moderate/Significant classification for remaining work

### What's New in v87.0.0

**Data-Driven Attribution (Shapley Value)** — Industry-standard multi-touch attribution model that uses observed conversion data to compute the marginal contribution of each marketing channel. Implements the gold-standard algorithm from cooperative game theory:

- **Exact Shapley computation** for ≤ 15 channels: evaluates all possible coalitions (2^n subsets)
- **Monte Carlo approximation** for > 15 channels: O(m × n) sampling for scalability
- **Channel removal impact analysis**: simulate revenue loss if a channel is removed
- **Period comparison**: track how attribution shifts between time periods
- **Budget allocation**: proportional budget recommendations based on attribution credit
- **Model confidence scoring**: indicates reliability based on data sufficiency (minimum 30 conversions)

API endpoints:
- `POST /api/analytics/attribution/data-driven` — compute Shapley attribution from conversion paths
- `POST /api/analytics/attribution/compare-periods` — compare attribution between periods
- `POST /api/analytics/attribution/channel-impact` — analyze channel removal impact
- `POST /api/analytics/attribution/budget` — budget allocation recommendations

Config section `zeroboiler.analytics.data_driven_attribution`: `enabled`, `cache_ttl`, `min_conversions`, `lookback_days`, `max_path_length`.

**Unit Economics Service** — Comprehensive subscriber-level financial metrics for venture-backed SaaS companies (OpenView Partners, Bessemer benchmarks):

- **LTV models**: Simple (ARPU × GM × Lifetime) and Predictive (DCF with discount rate)
- **Cohort LTV**: Computed from actual revenue data observations
- **Blended & per-channel CAC** with efficiency classification
- **LTV:CAC ratio** with health assessment (target: 3:1)
- **CAC payback period** (target: ≤ 18 months)
- **Magic Number** sales efficiency (target: > 0.75)
- **Gross margin, burn rate, runway, revenue per employee**
- **Comprehensive dashboard**: single-call financial health overview

API endpoints:
- `POST /api/analytics/unit-economics/dashboard` — full unit economics dashboard
- `POST /api/analytics/unit-economics/ltv-cac` — LTV:CAC ratio calculation
- `POST /api/analytics/unit-economics/channel-cac` — per-channel CAC efficiency
- `POST /api/analytics/unit-economics/magic-number` — Magic Number sales efficiency

Config section `zeroboiler.analytics.unit_economics`: `enabled`, `cache_ttl`, `ltv` (lifetime_months, discount_rate, gross_margin), `benchmarks` (ltv_cac_target, payback_target_months, magic_number_target).

**Product Analytics Maturity Assessment** — Evaluates analytics instrumentation against a 5-level maturity model (Amplitude/Gartner inspired):

- **Level 1 — Ad Hoc**: Basic page views, no funnel tracking
- **Level 2 — Basic**: Core lifecycle events, single provider
- **Level 3 — Standard**: Full AARRR coverage, multi-provider, identity resolution
- **Level 4 — Advanced**: Predictive analytics, cohort analysis, attribution
- **Level 5 — Leading**: Data-driven culture, full automation, real-time

8 assessment dimensions: Event Coverage, Provider Coverage, Funnel Instrumentation, Identity Resolution, Real-time Capabilities, Privacy & Compliance, Operational Excellence, Data Quality. Weighted scoring with findings, recommendations, and prioritized roadmap.

API endpoints:
- `GET /api/analytics/maturity` — full maturity assessment with 8 dimensions
- `GET /api/analytics/maturity/quick` — quick score (level + grade only)

**3 new services**, **10 new API endpoints**, **2 new config sections**, ServiceProvider singleton registrations, V87 test suite with 30+ assertions, full version sweep to 87.0.0 — industry-standard SaaS analytics upgrade.

### What's New in v86.0.0

**Event Sequence Prediction Engine** — Markov chain-based next-event prediction. Builds first-order and second-order transition matrices from observed user event sequences and predicts the most likely next event(s). Features:

- **First-order model**: P(X_{n+1} | X_n) single-event transitions
- **Second-order model**: P(X_{n+1} | X_n, X_{n-1}) bigram transitions for higher accuracy
- **Anomaly detection**: Flag unexpected sequence transitions against the learned model
- **Top sequences**: Identify most common event flow patterns
- **Configurable confidence thresholds** and minimum observation requirements
- **Excluded events** filtering (page_view, scroll_depth excluded by default)

API endpoints:
- `POST /api/analytics/prediction/record-sequence` — train the model with observed sequences
- `POST /api/analytics/prediction/next` — predict next events given recent context
- `GET /api/analytics/prediction/stats` — model statistics and configuration
- `GET /api/analytics/prediction/matrix/{event}` — transition matrix for a specific event
- `GET /api/analytics/prediction/top-sequences` — most common event sequences
- `GET /api/analytics/prediction/anomalies` — detect anomalous transitions
- `DELETE /api/analytics/prediction/model` — clear model data

Config section `zeroboiler.analytics.sequence_prediction`: `enabled`, `cache_ttl`, `min_observations`, `top_n`, `confidence_threshold`, `use_second_order`, `excluded_events`.

**Event Cost Ledger** — Per-event dispatch cost tracking across all 10 providers. Tracks computational and financial costs with:

- **Per-event cost breakdown** by provider (API calls, bandwidth)
- **Budget alerts** when daily spending exceeds configured threshold
- **Cost optimization recommendations** (high-cost events, high-failure-rate providers)
- **Historical cost data** for trend analysis (up to 90 days)
- **Provider cost comparison** with configurable per-1K-event rates

API endpoints:
- `GET /api/analytics/cost-ledger/daily` — today's cost summary with top events
- `GET /api/analytics/cost-ledger/budget` — budget status and alerts
- `GET /api/analytics/cost-ledger/optimizations` — cost-saving recommendations
- `GET /api/analytics/cost-ledger/history?days=7` — historical cost data

Config section `zeroboiler.analytics.cost_ledger`: `enabled`, `daily_budget`, `monthly_budget`, `provider_cost_rates`, `exempt_events`.

**Compliance Report Generator** — Multi-framework compliance reports for regulatory audits (GDPR, CCPA, SOC2, ePrivacy). Generates:

- **GDPR report**: 8 checkpoints (consent mode, data minimization, right to erasure, consent logging, granular consent, cookie consent, IP anonymization, data retention)
- **CCPA report**: 6 checkpoints (data sale disclosure, opt-out, data access, PII detection, data portability, retention limits)
- **SOC2 report**: 6 checkpoints (access logging, encryption, SDK auth, rate limiting, incident response, change management)
- **ePrivacy report**: 4 checkpoints (cookie banner, tracking transparency, consent-before-tracking, regional detection)
- **Health summary**: Quick compliance scores with critical gap identification
- **Recommendations**: Actionable remediation for each failing check

API endpoints:
- `GET /api/analytics/compliance-report/full` — full multi-framework report
- `GET /api/analytics/compliance-report/gdpr` — GDPR-specific report
- `GET /api/analytics/compliance-report/ccpa` — CCPA-specific report
- `GET /api/analytics/compliance-report/soc2` — SOC2-specific report
- `GET /api/analytics/compliance-report/health` — quick health summary

**Analytics Command Center CLI** (`zb:analytics:command-center`) — Unified governance + operations + compliance dashboard. Combines:

- Configuration audit (missing keys, deprecated values)
- Data quality score (firewall, PII detection, dedup, sampling)
- Compliance health (GDPR, CCPA, SOC2 quick-check)
- Provider readiness (enabled/configured/ready status per provider)
- Cost/budget status
- Event catalog coverage
- Version consistency check
- JSON output mode for CI integration

```
php artisan zb:analytics:command-center --json
php artisan zb:analytics:command-center --section=compliance
```

**22 new API endpoints**, **3 new services**, **1 new CLI command**, **3 config sections**, ServiceProvider singleton registrations, V86 test suite with 25+ assertions, full version sweep to 86.0.0 — industry-standard SaaS analytics upgrade.

### What's New in v68.0.0

### Real User Monitoring (RUM) — Core Web Vitals Aggregation

**WebVitalsAggregatorService** — new Real User Monitoring service that aggregates Core Web Vitals metrics (LCP, FID, CLS, INP, TTFB, FCP) reported by the client-side PerformanceObserver API. Features:

- **Percentile distribution**: p25, p50, p75, p90, p95, p99 per metric per page
- **Google threshold-based rating**: good / needs-improvement / poor classification
- **Threshold alerting**: automatic poor-metric detection with log warnings
- **Batch ingestion**: accept single or multiple metrics in one request
- **Dashboard summary**: overall score, worst metrics, per-page breakdown
- **Core Web Vitals assessment**: pass/fail based on p75 thresholds for LCP + CLS + INP

API endpoints:
- `POST /api/analytics/vitals` — ingest single or batch Web Vitals metrics
- `GET /api/analytics/vitals/summary?page=` — full RUM dashboard
- `GET /api/analytics/vitals/metric/{metric}?page=` — percentile stats
- `GET /api/analytics/vitals/assessment?page=` — Core Web Vitals pass/fail
- `GET /api/analytics/vitals/pages` — tracked pages list

Config section `zeroboiler.analytics.rum`: `enabled`, `max_samples`, `ttl`, `window`, `alerting_enabled`.

### Event Inspector Service — Debug-Mode Lifecycle Tracking

**EventInspectorService** — new debug-mode event lifecycle inspector that captures detailed traces through each stage of the analytics pipeline: dispatch → middleware → enrichment → validation → provider dispatch → complete/error. Features:

- **Per-event trace**: full stage-by-stage trace with duration and context
- **Trace index**: recent events list with reverse-chronological ordering
- **Runtime toggle**: enable/disable at runtime for debugging sessions
- **Ring buffer**: configurable max traces with automatic cleanup

API endpoints:
- `GET /api/analytics/inspector/summary` — inspector status and stats
- `GET /api/analytics/inspector/traces?limit=` — recent traces with full details
- `GET /api/analytics/inspector/trace/{eventId}` — specific event trace

Config section `zeroboiler.analytics.inspector`: `enabled`, `max_traces`, `ttl`.

### Analytics Debug Command

**`zb:analytics:debug`** — unified CLI command for event inspection and RUM analysis. Subcommands:
- `inspector:show` — display recent event traces with stage details
- `inspector:clear` — clear inspector data
- `inspector:enable` / `inspector:disable` — toggle at runtime
- `rum:summary` — display RUM dashboard with all metrics
- `rum:metric --metric=LCP --page=` — percentile stats for a specific metric
- `rum:assessment` — Core Web Vitals pass/fail check
- `rum:clear` — clear all RUM data

All subcommands support `--json` output for CI/CD integration.

### AnalyticsConfig Expansion

New config accessors for RUM and Inspector settings: `rumEnabled()`, `rumMaxSamples()`, `rumTtl()`, `rumWindow()`, `rumAlertingEnabled()`, `inspectorEnabled()`, `inspectorMaxTraces()`, `inspectorTtl()`. Added to `compactSummary()` output.

---

### What's New in v67.0.0

### SaaS Analytics Coverage Report — 12-Capability Audit System

**SaaSCoverageReportService** — new comprehensive audit service that evaluates all 12 core SaaS analytics capabilities required for industry-standard product analytics. Each capability is scored as implemented, partial, or missing with detailed evidence and actionable recommendations:

| # | Capability | Weight |
|---|-----------|--------|
| 1 | Event Catalog (Ecommerce, SaaS, Engagement) | 10 |
| 2 | Server-Side Lifecycle Tracker | 8 |
| 3 | Inertia Middleware (page props, client ID cookie) | 8 |
| 4 | API Controller & Routes (events/batch/identify/consent) | 10 |
| 5 | JS Client Library (trackEvent, scroll depth, Svelte) | 8 |
| 6 | Event Queue (async dispatch) | 7 |
| 7 | User Identity Linking (client ID ↔ user ID) | 8 |
| 8 | E-commerce Helpers (GA4 + Meta format conversion) | 7 |
| 9 | Admin Commands (Overview, Test, Health) | 6 |
| 10 | Config Expansion (queue, API, identity, ecommerce) | 8 |
| 11 | Optional Providers (Plausible, PostHog, Mixpanel) | 6 |
| 12 | Tests & README | 6 |

Features:
- Weighted scoring system (0-100) with letter grades (A+ to F)
- Evidence-based evaluation with file existence and config checks
- Cache-backed audit results with 1h TTL
- Quick `summary()` method for CI/CD health gates
- Config-driven — reads actual configuration to determine status
- `clearCache()` for fresh audits after config changes

**AnalyticsCoverageCommand** (`zb:analytics:coverage`) — new admin command with options:
- `--json` — machine-readable JSON output
- `--summary` — score and grade only
- `--missing` — show only gaps (missing/partial capabilities)
- `--clear-cache` — flush cached report before running

**1 new config section** — `saas_coverage` (cache_ttl setting)
**1 new singleton registration** — SaaSCoverageReportService in ServiceProvider
**Command registration** — AnalyticsCoverageCommand in ServiceProvider commands
**V6700 test suite** — 10 test cases covering audit, caching, summary, version consistency, command signature

**Version Sweep:** 66.0.0 → 67.0.0 across composer.json, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, resources/js/analytics.js, resources/js/analytics.d.ts, README badge, CHANGELOG.

---

### What's New in v66.0.0

### Event Blueprint System — Reusable, Versioned Event Templates

**EventBlueprint DTO** — new immutable DTO representing a reusable event template with name, label, description, base event reference, default parameters, required parameters, type constraints, priority, version, and metadata. Supports validation (`validateParams()`), deprecation tracking, and round-trip serialization via `fromArray()` / `toArray()`.

**EventBlueprintRegistry** — new singleton registry service managing the complete blueprint lifecycle. Features:
- Runtime registration via `register()` or `registerFromArray()`
- Config-driven registration from `config/zeroboiler.php` → `blueprints.library`
- 22 built-in blueprints covering SaaS lifecycle (signup, login, trial, subscription, plan upgrade, cancellation), e-commerce (product viewed, cart added, checkout, purchase, refund), engagement (page viewed, search, share, form, scroll, error), and identity (user identified)
- Cache-backed persistence with 24h TTL
- Factory method `build()` for creating validated events from blueprints
- `buildUnsafe()` for non-throwing builds with error/warning collection
- `byCategory()` grouping, `diagnostics()` summary, `validateRegistry()` consistency check
- Resolution order: runtime → cache → config → built-in

**EventBuilder::fromBlueprint()** — new shorthand factory method on EventBuilder for constructing events from registered blueprints with a single call: `EventBuilder::fromBlueprint('saas.signup.email', ['user_id' => 'usr_1'])`.

**AnalyticsBlueprintCommand** — new admin command `zb:analytics:blueprints` with options:
- `--list` — list all registered blueprints in a table
- `--inspect=saas.signup.email` — detailed view of a single blueprint
- `--validate` — validate the entire blueprint registry for consistency
- `--build=blueprint_name --params='{"user_id":"usr_1"}'` — test building events
- `--json` — machine-readable output

### Segment-Compatible Export Service

**SegmentExportService** — converts ZeroBoiler analytics events to Segment's HTTP API v2 JSON format. Supports all five core Segment event types:
- **Identify** — userId + traits extraction
- **Track** — event name mapping with catalog metadata enrichment
- **Page** — page title, URL, path extraction
- **Group** — groupId + traits for organization tracking
- **Alias** — identity merging (previousId → newId)

Features: `toBatch()` for multi-event export, `autoConvert()` for automatic type detection, `buildBatchRequest()` for ready-to-POST payloads, comprehensive event name mapping (`sign_up` → "Signed Up", `purchase` → "Order Completed", etc.), and catalog metadata enrichment (`_zb_category`, `_zb_ga4`, `_zb_meta`).

**Config section** — new `segment_export` config in `config/zeroboiler.php` with `enabled`, `write_key`, `api_url`, `batch_size`, and `timeout` settings.

### Event Lifecycle Hooks

**EventLifecycleHooks** — new before/after dispatch callback registry for event enrichment, filtering, and side-effects:
- `beforeDispatch()` — modify or abort events before dispatch (return null to skip)
- `afterDispatch()` — post-dispatch processing with per-provider results
- `onError()` — error handling for dispatch failures (non-throwing)
- `finally()` — cleanup hooks that always run
- Chain execution in registration order for before hooks, reverse for after
- `clear()`, `clearBefore()`, `clearAfter()`, `clearErrors()`, `clearFinally()` management
- `summary()` diagnostic, `hasHooks()` check, `isExecuting()` re-entrance guard

**Config section** — new `lifecycle_hooks` config with `enabled`, `max_hooks`, and `timeout` settings.

### Config & AnalyticsConfig Updates

**Config** — three new sections in `config/zeroboiler.php`:
- `blueprints` — blueprint library config with cache TTL
- `segment_export` — Segment API settings
- `lifecycle_hooks` — hook configuration

**AnalyticsConfig** — 12 new type-safe accessors: `blueprintsEnabled()`, `blueprintsCacheTtl()`, `blueprintsLibrary()`, `segmentExportEnabled()`, `segmentWriteKey()`, `segmentApiUrl()`, `segmentBatchSize()`, `segmentTimeout()`, `lifecycleHooksEnabled()`, `lifecycleHooksMax()`, `lifecycleHooksTimeout()`. All three new features are included in `compactSummary()` output.

---

### What's New in v64.0.0

### AnalyticsConfig Provider Parity & Diagnostic API

**AnalyticsConfig — Mixpanel, Amplitude, TikTok, LinkedIn accessors** — added missing `mixpanelEnabled()`, `mixpanelToken()`, `mixpanelHost()`, `amplitudeEnabled()`, `amplitudeApiKey()`, `amplitudeHost()`, `tiktokEnabled()`, `tiktokPixelId()`, `linkedinEnabled()`, `linkedinPartnerId()` methods. The type-safe config accessor now covers all 10 supported providers (previously only GA4, GTM, Meta, Plausible, PostHog, Webhook had accessors).

**`enabledProviders()`** — new method on AnalyticsConfig that returns a flat `list<string>` of currently enabled provider names. Useful for health checks, diagnostic commands, and dashboard widgets that need to know which providers are active without iterating nested config.

**`compactSummary()`** — new flat diagnostic summary alongside the existing nested `summary()`. Returns a single-level associative array with version, provider list, consent default, queue settings, identity config, sampling, PII, debug, validation, fingerprint, event count, and category count. Designed for CLI output and quick health checks.

**`summary()` enhancement** — existing `summary()` method now includes Mixpanel, Amplitude, TikTok, and LinkedIn provider sections alongside the previously supported providers.

**Version Sweep:** 63.0.0 → 64.0.0 across AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, composer.json, package.json, README badge, ServiceProvider docblock, JS client version, TypeScript declaration.

---

### What's New in v63.0.0

### Event Session Context Service & Provider Dispatch Deduplication

**EventSessionContext DTO** — a new immutable DTO (`src/DTO/EventSessionContext.php`) that captures the full session/device context of an analytics event including session ID, client ID, user ID, device fingerprint, IP, User-Agent, parsed browser/OS/device type, screen dimensions, viewport size, language, timezone, geolocation (country/region/city), page URL/title, referrer, and UTM parameters. Supports `fromRequest()`, `fromArray()`, `toArray()`, `with()` (immutable update), `hasUtmData()`, and `utmArray()` methods.

**EventSessionContextService** — service that builds rich session contexts from HTTP requests with optional device parsing (User-Agent → browser/OS/device type), geolocation enrichment, and fingerprint generation. Caches device and geo lookups with configurable TTLs. Provides `buildFromRequest()`, `attachToEvent()`, and `enrichDeviceContext()` methods.

**ProviderDispatchDedupService** — prevents duplicate event dispatches to the same provider within a configurable time window. Uses content-based hashing (event name + filtered params + client/user ID) to identify duplicates. Critical-priority events bypass dedup automatically. Supports batch provider checking and custom time windows.

**New Config Sections:**
- `zeroboiler.analytics.session_context` — enable/disable device parsing, geolocation, fingerprinting, configure cache TTLs
- `zeroboiler.analytics.dispatch_dedup` — enable/disable, configure dedup window, hash algorithm, cache prefix

**Version Sweep:** 62.0.0 → 63.0.0 across AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, composer.json, README badge, ServiceProvider docblock.

---

### What's New in v62.0.0

### Domain Exception Hierarchy & Constructor Void Type Hardening

Replaced all 22 generic `RuntimeException` / `InvalidArgumentException` throws across 11 files with a new domain-specific exception hierarchy, and fixed 8 constructors missing `: void` return type declarations.

**Exception Hierarchy:**
- `AnalyticsException` (abstract base, extends `\Exception`)
- `InvalidAnalyticsArgumentException` (replaces `\InvalidArgumentException`)
- `AnalyticsRuntimeException` (replaces `\RuntimeException`)

**Files Updated:**
- 11 service/support files migrated from generic to domain exceptions
- 8 constructors received missing `: void` return type declarations
- New `ExceptionHierarchyTest` with 16 assertions covering hierarchy, inheritance, and catch-chain behavior

**Code Quality:**
- ✅ Zero generic `RuntimeException` / `InvalidArgumentException` throws remaining
- ✅ All constructors declare `: void` return type
- ✅ 680 source files, 323 test files, 21,350+ assertions

### What's New in v61.0.0

### Production-Ready Hardening — Phase 2-3-4 Quality Audit

Deep manual code review and quality hardening across all 658 source files and 302 test files.

**Code Quality:**
- ✅ All 658 source files use `declare(strict_types=1)`
- ✅ All public classes use `final` (or `final readonly` where applicable)
- ✅ All constructor methods declare `: void` return type
- ✅ All public methods have return type declarations
- ✅ All public classes have `@since` annotations
- ✅ Zero TODO/FIXME/HACK markers in source code
- ✅ PHP 8.5 syntax compliance verified
- ✅ Exception hierarchy consistent (domain exceptions, not generic RuntimeException)
- ✅ Interface contracts match implementations
- ✅ `#[\Override]` attributes on interface implementations

**Test Coverage:**
- 21,350+ assertions across 323 test files
- AnalyticsFake for full facade interception testing
- `WithAnalyticsFake` trait for test isolation

### What's New in v59.0.0

### Event Trend Forecast Engine — Linear Regression, Holt's Smoothing & Seasonal Decomposition

Forward-looking trend projection for analytics event streams using industry-standard statistical methods — no external ML dependencies.

**EventTrendForecastService:**
- **Linear Regression**: Ordinary least-squares fit with R² coefficient of determination
- **Holt's Double Exponential Smoothing**: Adaptive alpha estimation with trend component
- **Seasonal Decomposition**: Daily and weekly periodic pattern extraction via averaging
- **Compound Growth Rate**: Historical growth computation for trend classification
- **Confidence Intervals**: Z-score-based upper/lower bounds that widen with forecast horizon
- **Blended Forecast**: 60% regression + 40% Holt's smoothing for robust projections
- Per-event, per-category, and comparative multi-event forecasts with trend classification (up/down/flat/volatile)

**AnalyticsTrendForecastCommand (`php artisan analytics:trend-forecast`):**
- `--event=page_view` — Single event forecast with full report
- `--category=saas` — Category-level aggregate forecast
- `--events=login sign_up purchase` — Comparative multi-event analysis
- `--changes` — Detect trend acceleration/deceleration across all catalog events
- `--days=30 --horizon=7 --json` — Configurable windows and JSON output

**Configuration:** New `trend_forecast` config section with cache TTL, forecast horizon, confidence level, seasonal analysis toggle, and history window settings.

### What's New in v60.0.0

### Analytics Data Explorer & Event Correlation Analyzer

Two powerful new services for advanced analytics data exploration and time-lagged event correlation analysis. Designed as building blocks for analytics dashboards, data exploration UIs, and behavioral pattern detection.

**Analytics Data Explorer** (`AnalyticsDataExplorerService`):
- Flexible ad-hoc event querying with multi-dimensional aggregation
- Time-range filtering with configurable granularity (minute/hour/day/week/month)
- Event name and category filtering
- Parameter/property drill-down with top-value analysis
- Top-N event queries with automatic trend direction classification (rising/falling/stable)
- Period comparison (current vs. previous) with change percentages
- Funnel analysis with step-level conversion rates
- Cache-backed with deterministic cache keys for repeatable queries
- API endpoints: `GET /api/analytics/explorer/{explore,top-events,drill-down/{event},compare,funnel,health}`

**Event Correlation Analyzer** (`EventCorrelationAnalyzerService`):
- Time-lagged Pearson correlation (Cross-Correlation Function) between event pairs
- Configurable lag offsets (0h to 72h default, up to 24 steps)
- Significance classification (strong/moderate/weak/none) based on correlation thresholds
- Event transition analysis (A→B sequences within time windows)
- Conversion rate and lift calculation for event pairs
- Multi-event correlation matrix with configurable lag offsets
- Peak lag identification — discover "event A predicts event B in N hours"
- Cache-backed with 10-minute TTL for heavy computation
- API endpoints: `GET /api/analytics/correlation-analyzer/{health,cross-correlation,transition,matrix}`

**Version Consistency:**
- All active version references unified to v60.0.0 across PHP, JS, and package manifests
- `AnalyticsEvent::VERSION`, `analytics.js getVersion()`, `composer.json`, `package.json`, README badge
- Integrity command updated to expect v60.0.0

### What's New in v58.0.0

### Declarative Funnel Definitions & Privacy-Preserving Cookieless Collection

Config-driven funnel tracking with automatic step progression and abandonment detection. Define conversion funnels (signup, purchase, trial-to-paid) entirely in config — no code changes needed when adding new funnels.

**Declarative Funnel Service:**
- Define multi-step funnels in `config/zeroboiler.php` with ordered steps mapped to event names
- Automatic step progression when matching events are dispatched
- Time-to-convert tracking per step and per funnel
- Funnel abandonment detection with configurable timeout
- Cache-persisted funnel state per user/client identity
- Multi-funnel concurrent tracking support
- `zb:analytics:funnel-privacy` admin command for funnel diagnostics

**Privacy-Preserving Cookieless Collection:**
- Server-side cookieless event collection using fingerprint-based identifiers
- SHA-256 hashed IP + User-Agent fingerprinting (no cookies required)
- IP anonymization (last octet zeroed for IPv4, last 48 bits for IPv6)
- Configurable hashing algorithm and salt rotation
- Anonymous ID resolution for cross-request correlation without cookies
- Perfect for strict GDPR environments and pre-consent tracking
- Inspired by Plausible Analytics and Simple Analytics cookieless mode

### What's New in v57.0.0

### Event Enrichment Plugin System — Config-Driven, Extensible Pipeline Architecture

Introduces a fully-featured plugin architecture for event enrichment, allowing third-party packages and application code to register plugins that transform, augment, or filter analytics events before they are dispatched to providers. Completes the plugin ecosystem alongside the existing EventPluginRegistry (event discovery).

**EventEnrichmentPlugin** — Interface contract for enrichment plugins. Defines `name()`, `priority()`, `shouldEnrich()`, and `enrich()` methods. Plugins return an enriched event or `null` to drop it. Priority-based execution order (higher runs first). Graceful error handling — exceptions in plugins do not crash the pipeline.

```php
use ZeroBoiler\Analytics\Enrichment\EventEnrichmentPlugin;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

final class GeoEnrichmentPlugin implements EventEnrichmentPlugin
{
    public function name(): string { return 'geo_enrichment'; }
    public function priority(): int { return 100; }
    public function shouldEnrich(AnalyticsEvent $event): bool { return true; }
    public function enrich(AnalyticsEvent $event): ?AnalyticsEvent
    {
        return new AnalyticsEvent(
            name: $event->name,
            params: array_merge($event->params, ['country' => $this->detectCountry()]),
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
        );
    }
}
```

**EventEnrichmentRegistry** — Central registry for enrichment plugins. Supports config-driven plugin loading (class list in `enrichment_plugins.plugins`), programmatic registration via `register()`, per-plugin enable/disable, priority sorting, and diagnostic summaries. Singleton registered in ServiceProvider.

```php
$registry = app(\ZeroBoiler\Analytics\Enrichment\EventEnrichmentRegistry::class);
$registry->register(new GeoEnrichmentPlugin);
$registry->disable('geo_enrichment');
$summary = $registry->summary();
```

**EventEnrichmentOrchestrator** — Pipeline executor that runs registered plugins in priority order. Tracks per-plugin metrics (count, drop count, avg time), total enriched/dropped/passed counts, and integrates with AnalyticsMetrics generic counters. Exceptions in plugins are caught and logged without dropping events. Singleton registered in ServiceProvider.

```php
$orchestrator = app(\ZeroBoiler\Analytics\Enrichment\EventEnrichmentOrchestrator::class);
$result = $orchestrator->enrich($event); // AnalyticsEvent|null
$metrics = $orchestrator->metrics();
```

**1 new config section** — `enrichment_plugins` (4 options: enabled, debug, disabled, plugins).

**AnalyticsMetrics extension** — Added generic `increment()`, `counter()`, and `counters()` methods for extensibility. Counters are included in `summary()` and cleared on `flush()`.

**EventEnrichmentPluginTest** — 27 test cases covering registry (creation, registration, replacement, priority sorting, enable/disable, remove, lookup, global disable, diagnostic summary, config loading), orchestrator (no plugins, single plugin, multiple priority order, drop events, non-matching events, shouldEnrich skip, exception handling, registry bypass, metrics tracking, drop metrics, reset), and AnalyticsMetrics extension (increment, custom amount, all counters, disabled state, summary integration, flush).

### Changed

- **Version sweep** — 56.0.0 → 57.0.0 across `composer.json`, `AnalyticsEvent::VERSION`, `AnalyticsServiceProvider` version annotation, README badge, ToC.

### What's New in v56.0.0

### Cohort × Funnel Matrix Engine — Cross-Dimensional Conversion Analytics

Intersects user cohorts with conversion funnels to produce heatmap-ready matrices, step performance analysis, drop-off rankings, velocity indices, and side-by-side cohort comparisons. Inspired by Amplitude Pathfinder × Cohort and Mixpanel Cohort Funnels.

**CohortFunnelMatrixService** — Core engine for cross-dimensional cohort-funnel analysis. Produces structured matrix data (rows = cohorts, columns = funnel steps) with counts, step-to-step conversion rates, cumulative rates, and time-to-convert metrics. Supports 4 predefined funnel templates (onboarding, purchase, saas_conversion, engagement) and custom funnel registration. Includes heatmap generation (2D array for D3/Chart.js), cohort comparison diff view, velocity index scoring (0-100), step performance analysis with standard deviation, and drop-off severity ranking (critical/high/medium/low). Configurable max cohorts/steps bounds, caching, and disabled-state safety.

```php
use ZeroBoiler\Analytics\Services\CohortFunnelMatrixService;

$service = app(CohortFunnelMatrixService::class);

// Build matrix
$matrix = $service->buildMatrix(
    ['2026-W28', '2026-W29', '2026-W30'],
    ['sign_up', 'verified', 'active', 'trial_started', 'subscribe'],
    $cohortData,
);

// Heatmap for visualization
$heatmap = $service->heatmap($cohorts, $steps, $cohortData);

// Velocity index
$velocity = $service->velocityIndex('2026-W29', $steps, $stepData);

// Compare two cohorts
$comparison = $service->compareCohorts('2026-W28', '2026-W30', $steps, $cohortData);
```

**AnalyticsCohortFunnelCommand** (`zb:analytics:cohort-funnel`) — Admin CLI with 9 actions: `config`, `templates`, `build`, `compare`, `heatmap`, `velocity`, `analysis`, `dropoff`, `clear-cache`. Supports `--template`, `--cohorts`, `--steps`, `--json` options.

**1 new config section** — `cohort_funnel_matrix` (6 options: enabled, cache_ttl, max_cohorts, max_steps, cohort_dimensions, custom_funnels).

**1 new singleton registration** — CohortFunnelMatrixService registered in AnalyticsServiceProvider.

**Command registration** — AnalyticsCohortFunnelCommand registered in ServiceProvider commands.

**V5600CohortFunnelMatrixEngineTest** — 30+ test cases covering construction, config summary, funnel templates (all 4 defaults, unknown, custom registration), buildMatrix (basic, disabled, max_cohorts, time_to_convert, empty data, single-step), buildFromTemplate, compareCohorts (step-by-step deltas, disabled), heatmap (generation, disabled), velocityIndex (computation, disabled, zero counts), stepPerformanceAnalysis (across cohorts, disabled), dropoffRanking (severity classification, single-step, critical detection), buildMatrixCached, clearCache, version consistency, command signature.

### Changed

- **Version sweep** — 55.0.0 → 56.0.0 across `composer.json`, `AnalyticsEvent::VERSION`, `UnifiedHealthEndpointService`, `resources/js/analytics.js` (header + `getVersion()` + `_getInternalVersion()`), `resources/js/analytics.d.ts` (header), README badge, CHANGELOG, ToC.

### What's New in v54.0.0

### Real-Time Anomaly Detection & Automated Alerting

Statistical anomaly detection service for analytics event patterns. Uses sliding-window baselines to detect rate spikes/drops, provider failures, composition drift, and unique client count anomalies with configurable sensitivity and cooldown-based alerting.

**AnalyticsAnomalyDetectionService** — Monitors event dispatch patterns using standard deviation analysis against configurable rolling baselines. Supports rate anomaly detection (volume spike/drop), provider failure detection, composition drift detection (new/unusual events), and client count anomaly detection (possible bot/attack).

```php
use ZeroBoiler\Analytics\Services\AnalyticsAnomalyDetectionService;

$detection = app(AnalyticsAnomalyDetectionService::class);

// Record events for baseline tracking
$detection->recordEvent('purchase', 'ga4', $clientId, $userId);

// Check for anomalies
$anomalies = $detection->detectAnomalies();
// → [{type: 'rate_anomaly', severity: 'critical', message: '...', deviation: 4.2}, ...]

// Get metrics for dashboard
$metrics = $detection->metrics();
// → rate_deviation, provider_balance, composition_drift, client_spike, anomaly_count_24h

// Register custom alert callback
$detection->onAlert(function (string $type, array $data): void {
    // Send to Slack, PagerDuty, etc.
});
```

**Configuration:**
```php
'anomaly_detection' => [
    'enabled' => true,
    'window_seconds' => 300,     // 5 minute windows
    'baseline_windows' => 12,   // 1 hour baseline
    'sensitivity' => 3.0,       // standard deviations
    'alert_cooldown' => 900,    // 15 min between repeated alerts
    'min_events_threshold' => 10,
    'channels' => ['log'],
],
```

### Multi-Provider Event Relay

Cross-provider event forwarding for events that should be broadcast to secondary providers outside the default dispatch chain. Supports per-event rules, category-level wildcards, exclusion patterns, and multiple payload formats.

**MultiProviderRelayService** — Forwards dispatched events to configured relay endpoints with automatic format transformation. Supports batch relay, provider-specific retry/timeout, and per-event exclusion rules.

```php
use ZeroBoiler\Analytics\Services\MultiProviderRelayService;

$relay = app(MultiProviderRelayService::class);

// Relay a single event to all matching providers
$relayed = $relay->relay($event);
// → ['custom_webhook', 'data_warehouse']

// Batch relay with consolidated payloads
$results = $relay->relayBatch($events);
// → ['custom_webhook' => 25, 'data_warehouse' => 25]

// Monitor relay health
$status = $relay->status();
$metrics = $relay->getMetrics();
```

### Analytics Data Export Formatter

Industry-standard export format transformer. Converts analytics events to CSV, Segment JSON, GA4 BigQuery schema, and Snowplow self-describing format for data warehouse loading and third-party integrations.

**AnalyticsExportFormatterService** — Transforms event collections into multiple export formats with metadata manifests including time ranges, category distributions, and provider breakdowns.

```php
use ZeroBoiler\Analytics\Services\AnalyticsExportFormatterService;

$formatter = app(AnalyticsExportFormatterService::class);

// Export to CSV
$csv = $formatter->toCsv($events, ['id', 'event_name', 'client_id']);

// Export to Segment specification
$segment = $formatter->toSegmentFormat($events);

// Export to GA4 BigQuery schema
$bigquery = $formatter->toBigQueryFormat($events);

// Export with metadata manifest
$export = $formatter->exportWithMetadata($events, 'bigquery');
// → {meta: {exported_at, total_events, time_range, ...}, data: [...]}
```

### New CLI Command: `zb:analytics:anomaly`

```bash
# Run anomaly check
php artisan zb:analytics:anomaly check

# View status dashboard
php artisan zb:analytics:anomaly status

# View metrics
php artisan zb:analytics:anomaly metrics --format=json

# Clear detection data
php artisan zb:analytics:anomaly clear
```

### New API Endpoints

```
GET  /api/analytics/anomaly/status     — Anomaly detection status
GET  /api/analytics/anomaly/metrics    — Dashboard metrics
GET  /api/analytics/anomaly/check      — Run anomaly detection
GET  /api/analytics/anomaly/alerts     — Recent alerts
DELETE /api/analytics/anomaly          — Clear detection data
GET  /api/analytics/relay/status       — Relay service status
GET  /api/analytics/relay/metrics      — Relay dispatch metrics
GET  /api/analytics/export/formats     — Supported export formats
POST /api/analytics/export/transform   — Transform events to format
```

### Version sweep — 53.0.0 → 54.0.0 across `composer.json`, `AnalyticsEvent::VERSION`, `UnifiedHealthEndpointService`, `AnalyticsServiceProvider`, `resources/js/analytics.js`, `resources/js/analytics.d.ts`, README badge.

---

### What's New in v53.0.0

### Event Payload Encryption Engine

Field-level AES-256-CBC encryption for sensitive analytics event parameters. Provides reversible encryption (unlike PII sanitization which hashes/removes) so encrypted data can be decrypted for internal reporting and audit while protecting it from provider access.

**New files:**
- `EventPayloadEncryptionService` — Core encryption/decryption service with global and per-event field rules, wildcard matching, `except:` syntax for exclusions, key rotation support, and automatic fail-safe hashing for oversized values
- `EventPayloadEncryptionMiddleware` — Pipeline middleware (priority 45) that automatically encrypts matching fields before provider dispatch
- `AnalyticsEncryptionCommand` (`zb:analytics:encryption`) — Management CLI with `status`, `encrypt`, `decrypt`, `fields`, and `rotate` subcommands

**Features:**
- Config-driven global field rules (`email`, `phone`, `ip_address`, wildcard `user_*`)
- Per-event field rules with `except:` syntax for exclusions
- Automatic oversized value hashing (values > 4KB hashed instead of encrypted)
- Encryption prefix marker (`enc:v1:`) for identifying encrypted values
- Full round-trip encrypt → decrypt with original value preservation
- Key rotation support via `rotateEncryption()` method
- Health report with cipher info and field configuration summary
- Fail-safe: returns SHA-256 hash if encryption fails

**Configuration:**
```php
'encryption' => [
    'enabled' => env('ANALYTICS_ENCRYPTION_ENABLED', false),
    'prefix' => env('ANALYTICS_ENCRYPTION_PREFIX', 'enc:v1:'),
    'global_fields' => ['email', 'phone', 'ip_address'],
    'event_rules' => [
        'purchase' => ['credit_card', 'billing_address'],
        'sign_up' => ['except:ip_address'],
    ],
],
```

**Version sweep** — 52.0.0 → 53.0.0 across `composer.json`, `AnalyticsEvent::VERSION`, `UnifiedHealthEndpointService`, `AnalyticsServiceProvider` docblock, `resources/js/analytics.js`, `resources/js/analytics.d.ts`, README badge, CHANGELOG, ToC.

---

### What's New in v52.0.0

### Pre-computed Analytics Rollup Engine

Maintains materialized time-series aggregations in the cache layer so dashboard widgets and API endpoints can query aggregate metrics without scanning raw event data. Supports hourly, daily, and weekly granularities with configurable TTL and bounded unique tracking.

**AnalyticsRollupService** — Record events for automatic rollup computation at all active granularities. Query aggregated data with top events ranking, category distribution percentages, and unique user/client counts. Compare trends between consecutive periods with delta and percentage change. Generate sparkline data for event-specific time-series visualization.

```php
use ZeroBoiler\Analytics\Services\AnalyticsRollupService;

$rollup = app(AnalyticsRollupService::class);

// Automatic recording — call from your event dispatch pipeline
$rollup->record('purchase', 'ecommerce', 'ga4', $userId, $clientId);

// Query aggregated data for current day
$data = $rollup->query('daily');
// → total, events, categories, providers, unique_users, top_events, category_distribution

// Trend comparison (today vs yesterday)
$trend = $rollup->trend('daily');
// → current, previous, delta, pct_change

// Sparkline for a specific event
$sparkline = $rollup->sparkline('page_view', 'hourly', 24);
// → [{period: '2026-08-13T14', count: 42}, ...]
```

**AnalyticsRollupCommand** (`zb:analytics:rollup`) — CLI for rollup management with 6 modes:

```bash
php artisan zb:analytics:rollup summary          # Service configuration
php artisan zb:analytics:rollup stats             # Data volume per granularity
php artisan zb:analytics:rollup query --granularity=daily
php artisan zb:analytics:rollup trend --granularity=daily
php artisan zb:analytics:rollup sparkline --event=page_view --periods=48
php artisan zb:analytics:rollup clear              # Flush rollup cache
```

**4 new API endpoints:**

| Endpoint | Description |
|---|---|
| `GET /api/analytics/rollup?granularity=daily&period=2026-08-13` | Query rollup data |
| `GET /api/analytics/rollup/summary` | Service configuration |
| `GET /api/analytics/rollup/trend?granularity=daily` | Period comparison |
| `GET /api/analytics/rollup/stats` | Data volume statistics |

**Config:**

```php
// config/zeroboiler.php → analytics.rollup
'rollup' => [
    'enabled' => true,
    'granularities' => ['hourly', 'daily', 'weekly'],
    'cache_prefix' => 'zb_rollup_',
    'hourly_ttl' => 7200,       // 2 hours
    'daily_ttl' => 604800,      // 7 days
    'weekly_ttl' => 2592000,     // 30 days
    'max_top_events' => 20,
    'max_unique_trackers' => 10000,
],
```

### What's New in v51.1.0

### 🌐 TikTok & LinkedIn Provider Coverage Parity

Full 10-provider mapping coverage for SaaS and Engagement event catalogs:

- **SaaS Events** — TikTok & LinkedIn mappings for 11 core events: `sign_up`, `login`, `logout`, `start_trial`, `trial_end`, `subscribe`, `plan_upgrade`, `plan_downgrade`, `cancellation`, `feature_used`, `revenue_tracked`
  - TikTok: `CompleteRegistration`, `Login`, `Subscribe`, `CompletePayment`
  - LinkedIn: `signup`, `login`, `purchase`
- **Engagement Events** — TikTok & LinkedIn mappings for 9 core events: `page_view`, `scroll_depth`, `click`, `form_start`, `form_submit`, `search`, `share`, `error`
  - TikTok: `Pageview`, `ClickButton`, `SubmitForm`, `Search`
  - LinkedIn: `page_view`
- **PHPStan type parity** — `EventEntry` type updated across SaaS and Engagement catalogs with `tiktok: string|null, linkedin: string|null`
- **Version sweep** — 51.0.0 across all version references

### What's New in v50.0.0

### 🤖 Auto-Instrumentation Engine
- **AutoInstrumentationEngine** — Config-driven Eloquent model event → analytics event mapping (Segment Source equivalent)
  - Map `User::created` → `sign_up`, `Order::created` → `purchase`, etc.
  - Configurable param mapping (`name` → `full_name`), sensitive param exclusion (`password`, `remember_token`)
  - Automatic user ID extraction from model `getAuthIdentifier()` or `user_id` attribute
  - UTM parameter auto-injection from current request
  - Custom param extractors and event transformers via `addParamExtractor()` / `addEventTransformer()`
  - Consent-aware — respects GDPR consent before dispatch
  - Manual trigger via `trigger()` for non-Eloquent events

```php
// config/analytics.php
'auto_instrument' => [
    'enabled' => true,
    'models' => [
        \App\Models\User::class => [
            'created' => 'sign_up',
            'deleted' => 'cancellation',
            'param_map' => ['name' => 'full_name', 'plan' => 'plan_name'],
            'exclude_params' => ['password', 'remember_token'],
        ],
        \App\Models\Order::class => [
            'created' => 'purchase',
            'param_map' => ['total' => 'value', 'id' => 'transaction_id'],
        ],
    ],
    'extract_user_id' => true,
    'include_utm' => true,
],

// Programmatic usage
use ZeroBoiler\Analytics\Facades\Analytics;

$engine = Analytics::autoInstrument();
$engine->addParamExtractor(fn ($model, $event) => ['tenant_id' => $model->tenant_id]);
$engine->trigger('custom_event', $model, ['extra' => 'data']);
```

### 📝 Facade Enhancement
- New `Analytics::autoInstrument()` method for accessing the auto-instrumentation engine
- Registered as singleton in the service provider

### 🧪 Tests
- **AutoInstrumentationEngineTest** — 8 test cases covering enabled/disabled state, param extraction, param mapping, sensitive exclusion, custom extractors, event transformers, and model mappings

### What's New in v48.0.0

### 🔗 Event Correlation Engine Service
- **EventCorrelationEngineService** — Detects statistically significant causal relationships between analytics events using temporal proximity analysis
- Normalized Pointwise Mutual Information (NPMI) scoring with temporal recency weighting
- Bidirectional pair key normalization for consistent A↔B correlation lookups
- `recordCooccurrence()` — Record event co-occurrences with optional context (user_id, session_id)
- `getCorrelationScore()` — Compute 0.0–1.0 correlation coefficient between any two events
- `getCorrelatedEvents()` — Ranked list of events correlated above threshold, with directionality (before/after/simultaneous)
- `getAntecedents()` — Events that commonly precede a target event (for funnel drop-off analysis)
- `getConsequents()` — Events that commonly follow a source event (for next-action prediction)
- `getTopCorrelations()` — System-wide top N correlated event pairs for dashboards
- Exponential decay recency weighting for recent co-occurrences
- Cache-backed correlation matrices with configurable TTL and pair limits
- Config: `zeroboiler.analytics.correlation_engine`

### 🔍 Anomaly Root Cause Analyzer
- **AnomalyRootCauseAnalyzer** — Traces analytics anomalies back to their most likely originating events
- 5 anomaly types supported: spike, drop, error, latency, quality
- Root cause categories: infrastructure, behavioral, technical, data_quality, billing
- Confidence scoring based on correlation strength, directionality, category relevance, and frequency
- Human-readable explanations and actionable remediation suggestions per root cause
- Infrastructure fallback causes when behavioral correlations are insufficient
- Analysis history with caching and summary metrics
- Config: `zeroboiler.analytics.root_cause_analyzer`

### 🩺 Analytics Self-Healing Service
- **AnalyticsSelfHealingService** — Automatic recovery for common analytics pipeline failures
- 9 healing actions: warm_cache, reset_provider_health, flush_dlq, reset_pipeline, cleanup_stale_data, check_queue_health, reset_fraud_metrics, reset_quality_firewall, clear_correlations
- Cooldown system prevents repeated healing of the same issue
- Auto-heal mode triggered by health service degradation
- Full healing history with audit trail
- Config: `zeroboiler.analytics.self_healing`

### 🛠️ AnalyticsSelfHealCommand
- New `zb:analytics:self-heal` artisan command with 7 modes: `heal`, `heal-all`, `auto`, `history`, `summary`, `correlate`, `root-cause`
- `--mode=heal --action=warm_cache` — Execute a specific healing action
- `--mode=heal-all` — Execute all eligible healing actions
- `--mode=auto` — Run automatic healing based on health status
- `--mode=history` — Show healing history
- `--mode=summary` — Self-healing service summary
- `--mode=correlate --event=purchase` — Analyze event correlations
- `--mode=root-cause --event=purchase --anomaly-type=spike` — Analyze anomaly root causes
- `--json` output for all modes

### 🌐 New API Endpoints
- `GET /api/analytics/correlation/engine/summary` — Correlation engine summary metrics
- `GET /api/analytics/correlation/engine/top` — Top N correlated event pairs
- `GET /api/analytics/root-cause/analyze?event=purchase&anomaly_type=spike` — Root cause analysis
- `GET /api/analytics/root-cause/history` — Root cause analysis history
- `GET /api/analytics/self-heal/summary` — Self-healing service summary
- `GET /api/analytics/self-heal/history` — Self-healing execution history
- `POST /api/analytics/self-heal/execute` — Execute a healing action

### ⚙️ New Config Sections
- `correlation_engine` — enabled, cache_prefix, cache_ttl, time_window_seconds, min_cooccurrence, min_correlation_score, decay_rate, max_correlations_per_event, max_event_pair_cache_size
- `root_cause_analyzer` — enabled, cache_prefix, cache_ttl, max_root_causes, lookback_window_seconds, min_confidence_score
- `self_healing` — enabled, auto_heal_enabled, auto_heal_actions, cache_prefix, history_ttl, max_history_entries, healing_cooldown_seconds

### 📦 ServiceProvider Registration
- EventCorrelationEngineService registered as singleton
- AnomalyRootCauseAnalyzer registered as singleton (with EventCorrelationEngineService dependency)
- AnalyticsSelfHealingService registered as singleton (with optional DeadLetterQueueService injection)
- AnalyticsSelfHealCommand registered in console commands

### What's New in v47.0.0

### 🕵️ Event Fraud Detection Service
- **EventFraudDetectionService** — Real-time fraud signal detection for analytics events
- 5 detection signals: Velocity (per-event rate limiting), Burst (sudden spike detection), Duplicate (replay detection via event hash), Parameter Injection (XSS/suspicious pattern detection), Spoofed Identity (multiple fingerprints per client ID)
- Composite fraud scoring (0.0–1.0) with configurable weights: velocity (25%), burst (30%), duplicate (20%), injection (15%), identity (10%)
- Two-tier action system: Quarantine (flagged, score ≥ 0.6) and Block (dropped, score ≥ 0.85)
- Critical event escalation: Purchase, subscription, and payment events are auto-blocked if quarantined
- Cache-backed metrics: total evaluated, passed, quarantined, blocked, average score, top flagged events
- Config: `zeroboiler.analytics.fraud_detection`

### 📈 Product-Market Fit Scoring Service
- **ProductMarketFitScoringService** — Industry-standard PMF scoring (0–100) with 7 signals
- Sean Ellis Test: "Very disappointed" percentage scoring (40%+ = strong PMF signal)
- Activation Rate: Onboarding completion percentage
- Retention Curve: D7/D30 retention sustainability scoring
- Feature Engagement Depth: Feature adoption ratio per user
- Organic Growth: Referral/virality rate scoring
- Revenue Stickiness: Net Revenue Retention (NRR) scoring
- Engagement Cadence: WAU/MAU ratio scoring
- Configurable weights (default: Ellis 25%, Activation 20%, Retention 20%, Engagement 15%, Organic 10%, Revenue 10%)
- 5-tier grading: Exceptional (85+), Strong (70–84), Moderate (50–69), Weak (30–49), None (0–29)
- Actionable recommendations generated from weak signal analysis
- Config: `zeroboiler.analytics.pmf_scoring`

### 🏥 Unified Health Endpoint Service
- **UnifiedHealthEndpointService** — Composite health aggregation across all analytics subsystems
- Full health check: Core, Extended, Monitor, Quality Firewall, Fraud Detection, PMF Scoring
- Liveness probe: Lightweight core health check for Kubernetes liveness probes
- Readiness probe: All-critical-subsystem check for Kubernetes readiness probes
- Overall status: healthy/warning/critical with composite score (0–100)
- Structured output: subsystem scores, warnings, recommendations, version
- Config: injected via ServiceProvider (all optional services gracefully handle null)

### 🛠️ AnalyticsFraudCommand
- New `zb:analytics:fraud` artisan command with 5 modes: `status`, `metrics`, `evaluate`, `test-burst`, `reset`
- `--mode=status` — Fraud detection status with thresholds and top flagged events
- `--mode=metrics` — Detailed metrics (total, passed, quarantined, blocked, avg score)
- `--mode=evaluate --event=purchase --client=abc` — Single event fraud evaluation with signal breakdown
- `--mode=test-burst` — Simulated 100-event burst test scenario
- `--mode=reset` — Reset all fraud metrics
- `--json` output for all modes

### 🛠️ AnalyticsHealthSummaryCommand
- New `zb:analytics:health-summary` artisan command with 5 modes: `full`, `liveness`, `readiness`, `pmf`, `pmf-grade`
- `--mode=full` — Complete unified health check with PMF score, subsystems, warnings, recommendations
- `--mode=liveness` — Lightweight liveness probe (for Kubernetes)
- `--mode=readiness` — Readiness probe with per-subsystem status
- `--mode=pmf` — PMF scoring details with signal breakdown and recommendations
- `--mode=pmf-grade` — Compact PMF grade output (score/100 [grade])
- `--json` output for all modes
- Exit code 1 on critical status (CI health gate support)

### 🌐 New API Endpoints
- `GET /api/analytics/fraud/metrics` — Fraud detection metrics
- `GET /api/analytics/fraud/status` — Fraud detection status with thresholds
- `GET /api/analytics/pmf/score` — Cached PMF score with config summary
- `GET /api/analytics/pmf/grade` — Compact PMF grade
- `GET /api/analytics/health/unified` — Full unified health check
- `GET /api/analytics/health/liveness` — Liveness probe
- `GET /api/analytics/health/readiness` — Readiness probe

### ⚙️ New Config Sections
- `fraud_detection` — enabled, cache_prefix, metrics_ttl, velocity_window, max_events_per_window, quarantine/block thresholds, burst settings, duplicate settings, spoofed identity settings, suspicious_patterns, critical_events
- `pmf_scoring` — enabled, cache_prefix, cache_ttl, ellis_threshold, weights (ellis_test, activation_rate, retention, engagement, organic_growth, revenue_stickiness)

### 📦 ServiceProvider Registration
- EventFraudDetectionService registered as singleton
- ProductMarketFitScoringService registered as singleton
- UnifiedHealthEndpointService registered as singleton (with optional service injection)
- AnalyticsFraudCommand and AnalyticsHealthSummaryCommand registered in console commands

### What's New in v46.0.0

### 🔀 Event Flow Analysis Service
- **EventFlowAnalysisService** — Real-time user event flow/journey analysis (Amplitude Pathfinder, Mixpanel Journeys pattern)
- Path tracking: Records event sequences per user/client with configurable max length and TTL
- Common path detection: Top N-step paths ranked by frequency
- Funnel drop-off analysis: Per-step conversion rates and drop-off percentages
- Conversion path comparison: Compare paths of converters vs non-converters
- Step timing analysis: Average/min/max time between consecutive events
- Transition tracking: Automatic step-to-step transition counting
- Config: `zeroboiler.analytics.event_flow`

### 🛡️ Analytics Data Quality Firewall
- **AnalyticsDataQualityFirewall** — Pre-dispatch quality scoring with auto-quarantine
- 4 quality checks: Completeness (required params), Format (naming conventions), Velocity (rate limiting), Consistency (value validation)
- Quality scores 0.0–1.0 with configurable quarantine (default: 0.5) and drop (default: 0.2) thresholds
- Event-specific required parameter rules (e.g., `purchase` requires `transaction_id`, `value`, `currency`)
- Reserved parameter prefix detection (`_ga_`, `_fb_`, `_meta_`, `_sentry_`)
- Per-event velocity limiting to prevent flooding
- Config: `zeroboiler.analytics.quality_firewall`

### 📊 Provider Event Compatibility Matrix
- **ProviderEventCompatibilityMatrix** — Comprehensive gap analysis across all 6 providers (GA4, Meta, PostHog, Plausible, Mixpanel, Amplitude)
- Full 2D compatibility matrix: event × provider → mapped/null
- Per-provider coverage percentage with unmapped event lists
- Provider readiness scoring (0–100) based on coverage, specificity, and category breadth
- Event popularity ranking (most to least provider-supported events)
- Prioritized gap closure recommendations with category-based priority weights
- Config: `zeroboiler.analytics.provider_matrix`

### 🛠️ AnalyticsFlowCommand
- New `zb:analytics:flow` artisan command with 5 modes: `flow`, `quality`, `matrix`, `evaluate`, `summary`
- `--mode=flow` — Event flow analysis with top paths and funnel drop-off (`--funnel=step1,step2,step3`)
- `--mode=quality` — Data quality firewall metrics (evaluated, passed, quarantined, dropped)
- `--mode=matrix` — Provider coverage, readiness scores, gap recommendations (`--provider=ga4`)
- `--mode=evaluate` — Single event quality evaluation (`--event=purchase`)
- `--mode=summary` — Overview of all three services with catalog stats
- `--json` output for all modes

### ⚙️ New Config Sections
- `event_flow` — enabled, max_path_length, path_ttl, top_paths_limit, cache_prefix, metrics_ttl
- `quality_firewall` — enabled, quarantine_threshold, drop_threshold, enforce_quarantine, enforce_drop, velocity settings, required params
- `provider_matrix` — enabled, cache_prefix, cache_ttl

### 📦 ServiceProvider Registration
- EventFlowAnalysisService registered as singleton
- AnalyticsDataQualityFirewall registered as singleton
- ProviderEventCompatibilityMatrix already registered (existing)
- AnalyticsFlowCommand registered in console commands

### What's New in v45.0.0

### 🏗️ Infrastructure Events Category (10 new events)
- **InfrastructureEvents** catalog — New event category for DevOps, SRE, and platform engineering tracking
- `feature_flag_evaluated` — Track feature flag evaluations with variant, reason, and segment context
- `experiment_exposed` — A/B test and experiment exposure tracking with source attribution
- `error_budget_burned` — SRE error budget consumption with burn rate, remaining budget, and measurement window
- `slo_breach` — SLO violation detection with SLI name, current/target values, and severity classification
- `deployment_rolled_back` — Deployment rollback tracking with version, reason, and environment context
- `incident_started` — Production incident declaration with severity (P1-P4) and affected service
- `incident_resolved` — Incident resolution with MTTR duration, resolution type, and root cause category
- `maintenance_started` — Scheduled maintenance window start for SLO exclusion
- `maintenance_ended` — Maintenance completion with outcome status and duration
- `pipeline_failure` — Analytics pipeline reliability self-monitoring (ingestion, dispatch, store failures)

### 📊 Event Sampling Strategy Service
- **EventSamplingStrategyService** — Config-driven event sampling with 3 strategies: `uniform` (random), `deterministic` (hash-based), `adaptive` (volume-aware)
- Per-event and per-category sampling rate overrides (event > category > global priority)
- Critical-priority events always bypass sampling
- Cache-backed metrics: passed, dropped, total, critical_passed
- Runtime rate adjustment via `setGlobalRate()`, `setEventRate()`, `setCategoryRate()`

### 🛠️ AnalyticsSamplingCommand
- New `zb:analytics:sampling` artisan command with 11 modes: `status`, `metrics`, `summary`, `set-global`, `set-event`, `set-category`, `remove-event`, `reset-metrics`, `reset-adaptive`, `list-overrides`, `preview`
- `--json` output for all modes
- `preview --event=page_view` resolves the effective sampling rate for any event

### ⚙️ New Config Section
- `sampling` — enabled, global_rate, strategy, event_overrides, category_overrides, cache_prefix, metrics_ttl, adaptive_window

### 📦 EventCatalog Integration
- Infrastructure category fully integrated into `EventCatalog::all()`, `byCategory()`, `getCategory()`, `count()`, and all provider name methods
- 10 new events across all catalog aggregation endpoints

### ✅ Tests (50+ new test cases)
- Infrastructure Event DTOs: all 10 events tested for construction, params, null handling, error message truncation
- InfrastructureEvents catalog: count, names, has/get/classFor, provider names, required fields
- EventSamplingStrategyService: disabled passthrough, rate 1.0/0.0, critical bypass, event/category overrides, resolveRate priority/clamping, deterministic consistency, uniform variance, adaptive counters, unknown strategy fail-open, metrics increment, metrics reset
- Version sweep tests

### Changed
- **Version sweep** — 44.0.0 → 45.0.0 across composer.json, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION, AnalyticsServiceProvider, analytics.js (header + getVersion + _getInternalVersion), all 3 Svelte composables, TypeScript definitions, README badge, CHANGELOG.

---

### What's New in v44.0.0

### 📋 Event Versioning & Deprecation API
- **EventDeprecationService** — Config-driven event lifecycle management with deprecation detection, auto-redirect, and blocking
- Mark events as `deprecated`, `beta`, or `experimental` via config registry
- Deprecated events emit structured warnings (deduplicated via cache to prevent log spam)
- `auto_redirect` mode silently forwards deprecated events to their replacement
- `block_deprecated` mode prevents dispatching deprecated events without replacements
- **EventVersioningService** — Catalog-level version metadata enrichment
- Per-event `since` version, stability level, and category metadata
- Deprecation audit report for admin dashboards
- Stability enforcement: `meetsStability()` for minimum stability gates

### ⚙️ New Config Section
- `event_versioning` — Enable/disable, block_deprecated, auto_redirect, cache_prefix, warning_ttl, log_channel, registry

---

### What's New in v43.0.0

### 🎯 Event TTL & Auto-Expiry Service
- **EventTtlService** — Configurable time-to-live rules for events with per-event and per-category overrides
- Stale events are flagged and optionally dropped before dispatch
- Metrics tracking for expired events (by event name, by category)
- Category-aware TTL resolution (ecommerce, SaaS, engagement, security, uptime)

### 🔄 Referral & Viral Loop Tracking
- **ReferralTrackingService** — Referral code generation and validation
- Invite link click-through tracking with click IDs for attribution
- Referral attribution (which user referred which signup)
- Self-referral prevention
- Viral coefficient (K-factor) calculation
- Referral funnel analysis (invites → clicks → signups)
- Top-referrer leaderboard

### 🛡️ Traffic Spike Shield
- **TrafficSpikeShield** — Adaptive event throttling during traffic bursts
- Sliding window rate detection (events per second)
- Priority-aware: critical events are never throttled
- Probabilistic sampling during cooldown
- Per-event-name threshold overrides
- Real-time status and metrics (accepted, throttled, spike count)

### 🧪 Event Replay Simulator
- **EventReplaySimulator** — Synthetic event generation for load testing
- Configurable event frequency mix with normalization
- E-commerce scenario generation (browse → cart → checkout → purchase)
- SaaS lifecycle simulation (signup → trial → conversion → renewal)
- Realistic user simulation (client IDs, sessions, user journeys)
- Dry-run mode for counting without dispatching

### 🛠️ AnalyticsSimulationCommand
- New `zb:analytics:simulate` artisan command
- Subcommands: simulate, ecommerce, saas, ttl:status, ttl:reset, referral:health, referral:viral, shield:status, shield:cooldown

### 📡 New API Routes (26 endpoints)
- TTL: `GET/DELETE /api/analytics/ttl/metrics`, `GET /api/analytics/ttl/config`, `POST /api/analytics/ttl/check`
- Referral: `POST /api/analytics/referral/generate-code`, `GET /api/analytics/referral/resolve/{code}`, `POST /api/analytics/referral/click`, `POST /api/analytics/referral/convert`, `GET /api/analytics/referral/health`, `GET /api/analytics/referral/viral`, `GET /api/analytics/referral/funnel`, `GET /api/analytics/referral/top-referrers`
- Spike Shield: `GET /api/analytics/spike-shield/status`, `GET /api/analytics/spike-shield/config`, `POST/DELETE /api/analytics/spike-shield/cooldown`, `DELETE /api/analytics/spike-shield/metrics`
- Simulator: `GET /api/analytics/simulator/config`, `GET /api/analytics/simulator/mix`, `POST /api/analytics/simulator/generate`, `POST /api/analytics/simulator/ecommerce`, `POST /api/analytics/simulator/saas`

### ⚙️ Config Expansion (5 new sections)
- `event_ttl` — Default TTL, event/category overrides, drop expired toggle
- `referral` — Code length, attribution window TTL
- `spike_shield` — Normal/spike thresholds, window size, throttle ratio, cooldown
- `simulator` — Batch size, rate limit, dry-run mode

### ✅ Tests (42 new test cases)
- EventTtlService: TTL resolution, expiry detection, category overrides, event overrides, drop expired, metrics tracking
- ReferralTrackingService: Code generation, preferred codes, referrer resolution, click tracking, conversion attribution, self-referral prevention, viral coefficient
- TrafficSpikeShield: Disabled mode, critical events, throttle during cooldown, threshold passthrough, cooldown management, status
- EventReplaySimulator: Batch generation, dispatch callback, simulation source, e-commerce scenario, SaaS lifecycle, event mix normalization

### What's New in v41.0.0

### 🎯 Analytics Context Manager
- **AnalyticsContext** — Scoped analytics context for automatic source tagging, timing, and error handling. Wraps any closure in a measured analytics context that:
  - Tags all events with a configurable source and context label
  - Measures execution duration and emits timing events on completion
  - Captures exceptions and emits structured error events with file, line, type, and message
  - Attaches consistent metadata to all events dispatched within the scope
  - Supports silent mode (metadata-only, no auto-emitted events)
  - Manual lifecycle control via `complete()` and `error()` methods
  - Elapsed time tracking via `elapsedMs()`
  - Client ID, user ID, and priority overrides for scoped events
- **`AnalyticsContext::measure($manager, $label, $callback)`** — One-liner context wrapper for quick timing measurement
- Inspired by OpenTelemetry span semantics and Sentry's `startSpan` pattern

### 🔨 Typed Event Builder
- **TypedEventBuilder** — Fluent, type-safe event construction with compile-time validation:
  - `TypedEventBuilder::for($name)` — Build any custom event with fluent param chaining
  - `TypedEventBuilder::catalogEvent($name)` — Validate event name against EventCatalog before building
  - Param, clientId, userId, priority, and source setting via chainable methods
  - `build()` — Throws on validation errors (invalid priority, source)
  - `buildUnsafe()` — Builds even with validation warnings
  - `mergeFrom(AnalyticsEvent)` — Merge params from an existing event (for replay/enrichment)
  - `describe()` — Human-readable description for debugging
  - `isInCatalog()` / `getCatalogCategory()` — Catalog membership introspection

### 📡 Analytics Wire Protocol
- **AnalyticsWireProtocolService** — Self-describing JSON envelope format for cross-service, cross-process, and cross-language event transmission:
  - Single event serialization: `serialize(AnalyticsEvent, metadata)` → JSON wire envelope
  - Batch serialization: `serializeBatch(list<AnalyticsEvent>, metadata)` → JSON batch envelope
  - Single event deserialization: `deserialize(string)` → AnalyticsEvent
  - Batch deserialization: `deserializeBatch(string)` → list<AnalyticsEvent>
  - Wire validation: `validate(string)` → {valid, errors, warnings, event_count}
  - Protocol versioning: `zb_analytics/1.0` identifier with SDK version
  - Correlation ID support for batch tracing
  - ISO 8601 timestamps, UTC-normalized
  - Graceful handling of missing/invalid timestamps, params, and event names
  - Designed for event forwarding, archival, replay, and event bus integration (Redis, Kafka, SQS)

### 🛣️ Event Context Middleware
- **EventContextMiddleware** — HTTP middleware that wraps each request in a silent AnalyticsContext:
  - Derives context label from route name or request path
  - Attaches request metadata (method, path, IP, user agent, request ID)
  - Adds `X-ZB-Analytics-Context` response header for tracing
  - Zero-overhead: silent mode — no auto-emitted events, metadata-only

### 🔗 Facade Methods (v41.0.0)
- `Analytics::contextMeasure($label, $callback)` — Measure a closure within analytics context
- `Analytics::createContext($label, $source)` — Create a reusable analytics context
- `Analytics::typedEvent($name)` — Create a typed event builder
- `Analytics::typedCatalogEvent($name)` — Create a catalog-validated typed builder
- `Analytics::wireSerialize($event, $metadata)` — Serialize to wire format
- `Analytics::wireSerializeBatch($events, $metadata)` — Serialize batch to wire format
- `Analytics::wireDeserialize($payload)` — Deserialize wire envelope
- `Analytics::wireDeserializeBatch($payload)` — Deserialize batch wire envelope
- `Analytics::wireValidate($payload)` — Validate wire envelope

### 🧪 Tests
- **V41ContextWireProtocolTest** — 45+ test cases covering AnalyticsContext (create, silent, metadata chaining, clientId/userId/priority, timing, error capture, measurement), TypedEventBuilder (for/catalogEvent, param chaining, bulk params, identity, priority/source validation, build/buildUnsafe, mergeFrom, describe, catalog membership), AnalyticsWireProtocolService (single/batch serialization, single/batch deserialization, validation of valid/malformed/missing fields, protocol version warnings, roundtrip with all fields, custom metadata), and version sweep (strict types, final classes, docblocks, class existence).

### Changed
- **Version sweep** — 40.0.0 → 41.0.0 across `composer.json`, `package.json`, `AnalyticsEvent::VERSION`, `AnalyticsIntegrityCommand::EXPECTED_VERSION`, `AnalyticsServiceProvider` docblock, `resources/js/analytics.js` (header + `getVersion()` + `_getInternalVersion()`), all 3 Svelte composables, TypeScript definitions `analytics.d.ts`, README badge.

### What's New in v39.0.0

### 🔒 Event Replay Audit Trail
- **EventReplayAuditService** — Comprehensive audit trail for every event replay operation. Records single and bulk replays with full context: audit ID, event name, archive ID, triggered by (user ID), source (archive/dlq/manual/api/command), per-provider success/failure, execution duration. Cache-backed with 30-day retention, audit ID index for fast lookups, configurable auto-record toggle.
- **AnalyticsReplayAuditCommand** (`zb:analytics:replay-audit`) — Admin CLI with 8 modes: summary, `--stats`, `--search` (filter by source/type/event_name/triggered_by/success/since/until), `--recent` (last N entries), `--purge-status` (retention policy overview), `--purge-expired` (execute expired event purge with optional `--dry-run` and `--category` filter), `--purge-logs`, `--json` output.
- **Config section** — `zeroboiler.analytics.replay_audit` with 5 options: enabled, cache_prefix, retention_ttl, max_entries, auto_record.

### 🗄️ Data Retention & GDPR Purge
- **AnalyticsDataRetentionService** — Configurable per-category retention policies for archived analytics events. Automatically purges expired events based on timestamp and category. Includes GDPR-compliant right-to-erasure for client_id and user_id purges. Per-category defaults: ecommerce (90d), saas (180d), engagement (30d), security (365d), uptime (30d). Dry-run mode, purge audit logging, configurable batch size.
- **2 new config sections** — `replay_audit` (5 options) and `data_retention` (10 options including per-category retention periods, GDPR erase toggle, purge batch size, logging).
- **2 new singleton registrations** — EventReplayAuditService, AnalyticsDataRetentionService in AnalyticsServiceProvider.
- **Singleton registration** — AnalyticsReplayAuditCommand registered in ServiceProvider commands.
- **V39ReplayAuditRetentionTest** — 40+ test cases covering EventReplayAuditService (single/bulk recording, search, filter, getByAuditId, statistics with period filtering, clear, summary, enabled/autoRecord/disabled), AnalyticsDataRetentionService (category retention, expiry check, purgeExpired with dry-run, GDPR purgeForClientId, purgeForUserId, statistics, purge logs, summary), and version sweep.

### Changed
- **Version sweep** — 38.0.0 → 39.0.0 across `composer.json`, `AnalyticsEvent::VERSION`, `AnalyticsIntegrityCommand::EXPECTED_VERSION`, `AnalyticsServiceProvider` docblock, `resources/js/analytics.js` (header + `getVersion()` + `_getInternalVersion()`), all 3 Svelte composables, TypeScript definitions `analytics.d.ts`, README badge, CHANGELOG, ToC.

### What's New in v38.0.0

### 🔭 OpenTelemetry (OTLP) Export Bridge
- **OTLPExportService** — Bridges ZeroBoiler analytics events to any OTLP-compatible collector (Grafana Tempo, Jaeger, Honeycomb, Datadog, OpenSearch, SigNoz, etc.). Converts AnalyticsEvent DTOs into OTLP ResourceSpans JSON format with:
  - Event name → OTLP span name
  - Event params → OTLP span attributes (type-aware: string, int, float, bool, array)
  - Client ID / User ID → trace context linking (deterministic trace_id generation)
  - Category → SpanKind mapping (ecommerce=CLIENT, saas=INTERNAL, engagement=INTERNAL, security=SERVER, uptime=SERVER)
  - Priority → span attribute
  - Timestamp → start/end time in nanoseconds (OTLP requirement)
  - SDK metadata attributes (`analytics.sdk.name`, `analytics.sdk.version`)
- **Configurable resource attributes** — `service.name`, `deployment.environment`, and custom resource attributes attached to all exported spans.
- **Batch export** — `exportBatch()` splits events into configurable chunks (default: 100) and sends each as a separate OTLP request.
- **Cache-backed statistics** — Tracks success/failure counts, exported event count, rolling average latency, and last error. Stats persist across requests with configurable TTL.
- **cURL HTTP transport** — POSTs OTLP JSON payloads to the configured endpoint with configurable timeout (default: 5s). Supports custom headers (e.g., `Authorization: Bearer ...`).
- **Config-driven** — All settings configurable via `zeroboiler.analytics.otel`: enabled, endpoint, headers, timeout, max_batch_size, debug, cache_ttl, resource_attributes.
- **Compliant attribute keys** — Auto-sanitizes parameter keys to match OTLP regex `[a-zA-Z][a-zA-Z0-9_.-*\/]*`.

### 🖥️ Analytics OTLP Command
- **`zb:analytics:otel`** — Admin CLI for OTLP export diagnostics and management.
- Flags: `--stats` (export statistics), `--validate` (config validation), `--test` (send test event), `--reset` (clear stats), `--enable`/`--disable`, `--json`.

### ⚙️ Config
- New `otel` config section with 9 options: enabled, endpoint, headers, timeout, max_batch_size, debug, cache_prefix, cache_ttl, resource_attributes.

### 🔄 Version Sweep
- Version bumped to 38.0.0 across `composer.json`, `package.json` (34.0.0 → 38.0.0), `AnalyticsEvent::VERSION`, `AnalyticsIntegrityCommand::EXPECTED_VERSION`, `AnalyticsServiceProvider` docblock, `resources/js/analytics.js` (header + `getVersion()` + `_getInternalVersion()`), all 3 Svelte composables, TypeScript definitions `analytics.d.ts`, README badge, CHANGELOG, ToC.

### What's New in v36.0.0

### 🔄 Event Ingestion Pipeline
- **EventIngestionService** — Centralized event ingestion pipeline that is the single entry point for all incoming analytics events regardless of source (API, server-side, webhook, replay, batch, edge proxy).
- Full lifecycle orchestration: validation → deduplication → consent check → enrichment → cost estimation → dispatch → post-dispatch metrics.
- Per-request and aggregated ingestion metrics with source tracking, latency monitoring, and rejection rate analysis.
- Configurable limits: event name length, param count, payload size, and timeout.
- Config: `zeroboiler.analytics.ingestion`

### 💰 Event Cost Allocation
- **EventCostTracker** — Per-provider dispatch cost tracking and allocation. Enables chargeback analytics, budget enforcement, and cost optimization for multi-provider SaaS analytics.
- Priority-aware cost estimation: critical=2x, normal=1x, low=0.5x, background=0.25x multiplier.
- Daily and monthly cost breakdowns by provider, per-event ranking, and per-tenant allocation.
- Configurable budget limits with enforcement (stops dispatching when daily budget exceeded).
- Config: `zeroboiler.analytics.cost_allocation`

### ⏰ Analytics Command Scheduler
- **AnalyticsCommandScheduler** — Config-driven scheduling of analytics admin commands without requiring manual crontab entries.
- 7 built-in scheduled tasks: health check (hourly), readiness score (daily), cost report (daily), archive cleanup (weekly), schema validation (daily), daily snapshot, and overview.
- Supports hourly, daily, weekly, and monthly schedules with cooldown tracking and execution logging.
- Custom tasks can be registered via config or at runtime via API.
- Config: `zeroboiler.analytics.scheduler`

### 🖥️ Analytics Ingestion Command
- **`zb:analytics:ingestion`** — Admin CLI for ingestion metrics, cost allocation breakdowns, and scheduler status.
- Flags: `--costs` (cost breakdown), `--scheduler` (task status), `--execute-due` (run due tasks), `--reset` (clear stats), `--json`.

### 🛣️ New API Endpoints (18 routes)
- **Ingestion**: GET `ingestion/metrics`, `ingestion/stats`, `ingestion/health`
- **Cost Allocation**: GET `cost-allocation/daily`, `cost-allocation/monthly`, `cost-allocation/events`, `cost-allocation/tenant/{tenantId}`, `cost-allocation/budget`
- **Scheduler**: GET `scheduler/status`, `scheduler/tasks`, `scheduler/due`, `scheduler/log` | POST `scheduler/execute`, `scheduler/execute/{taskName}`, `scheduler/toggle/{taskName}`, `scheduler/register` | DELETE `scheduler/{taskName}`

### 🧪 Tests
- **V3600IngestionCostSchedulerTest** — 40+ test cases covering EventIngestionService (construct, ingest valid/rejected events, batch deduplication, metrics, aggregated stats, disabled state), EventCostTracker (cost estimation, priority multipliers, cost weights, daily/monthly breakdowns, budget enforcement, tenant cost, per-provider costs, dispatch recording), AnalyticsCommandScheduler (built-in tasks, custom registration, toggle, remove, due tasks, config-driven tasks), and version sweep (strict types, final classes, return type declarations, readonly DTO).

### What's New in v35.0.0

### 🌐 Cross-Provider Identity Synchronization
- **CrossProviderIdentityService** — Synchronizes user identity and traits across all 10 analytics providers when a user is identified (login, register, or explicit identify call).
- Provider-specific identity protocols:
  - **GA4** — `user_identify` event with user_id + name/email/plan traits
  - **Meta Pixel CAPI** — SHA-256 hashed `em`, `fn`, `ct`, `country` for Advanced Matching
  - **PostHog** — `$create_alias` (anonymous → authenticated) + `$identify` with `$set` properties
  - **Mixpanel** — `$merge` (identity unification) + `$set` user properties
  - **Amplitude** — `$identify` with user properties
  - **TikTok** — `user_identify` with user_id for advanced matching
  - **LinkedIn** — `user_identify` for conversion tracking
  - **Plausible** — Server-side identity mapping
- **Identity merge** — `mergeIdentity()` for anonymous → authenticated identity unification (PostHog `$merge`, Mixpanel `$merge`)
- **Identity reset** — `resetIdentity()` for GDPR logout/erasure (GA4, PostHog `$reset`, Mixpanel `$reset`, Amplitude)
- Config-driven per-provider toggles via `zeroboiler.analytics.cross_provider_identity`

### 🛒 TikTok & LinkedIn E-Commerce Format Conversion
- **GA4 → TikTok** — `ga4ToTiktokProperties()`, `ga4ToTiktokPurchase()`, `ga4ToTiktokRefund()`, `ga4ToTiktokAddToCart()`
- **GA4 → LinkedIn** — `ga4ToLinkedinPurchase()`, `ga4ToLinkedinAddToCart()`
- **Convenience builders** — `buildTiktokPurchase()`, `buildLinkedinPurchase()`
- **`buildForAllProviders()`** expanded from 5 → 8 providers (added Plausible, TikTok, LinkedIn)

### 📋 SaaSMetricsCommand
- New `zb:analytics:saas-metrics` CLI command for SaaS KPI dashboard
- Sections: overview, revenue, retention, growth, provider coverage, benchmark comparison
- Displays maturity score, onboarding completion, catalog sizes, provider counts
- JSON output via `--json` flag
- Configurable time period via `--days` option

### 📊 Event Catalog Enhancements
- **EcommerceEvents** — All 15 entries now include `tiktok` and `linkedin` provider mappings
- **New methods** — `tiktokNames()`, `linkedinNames()` on EcommerceEvents, SaaSEvents, EngagementEvents
- TikTok mappings: `CompletePayment` (purchase), `AddToCart`, `InitiateCheckout`, `ViewContent`, `AddToWishlist`

### ⚙️ Config
- New `cross_provider_identity` config section with per-provider sync toggles

### What's New in v34.0.0

### 📊 User Engagement Scoring Service
- **UserEngagementScoringService** — Composite engagement scoring (0–100) per user based on five configurable weighted signals: frequency, recency, breadth, lifecycle progress, and revenue contribution.
- **Five scoring signals:**
  - *Frequency* — Logarithmic event volume scoring (more events = higher score, diminishing returns)
  - *Recency* — Exponential decay based on configurable half-life (default 7 days)
  - *Breadth* — Feature adoption breadth across event catalog categories
  - *Lifecycle* — SaaS lifecycle milestone progress (signup → trial → subscribe → upgrade)
  - *Revenue* — Revenue contribution tier scoring (free/paid/enterprise)
- **Five engagement tiers:** champion (80–100), active (60–79), moderate (40–59), dormant (20–39), at_risk (0–19)
- **Batch scoring** — `scoreBatch()` for bulk user scoring operations
- **Tier distribution** — `tierDistribution()` for aggregate segmentation analytics
- **Cache-backed** — Scores cached per user with configurable TTL (default 1 hour)
- **Config-driven weights** — All signal weights configurable via `zeroboiler.analytics.engagement_scoring.weights`
- **Inspired by:** Amplitude Engage, Mixpanel User Score, Pendo Engagement Score

### 🔧 AnalyticsOverviewCommand — 10-Provider Support
- **TikTok Pixel** and **LinkedIn Insight Tag** added to provider status table and API response
- **`total_providers`** updated from 8 → 10 to reflect TikTok and LinkedIn
- Both providers show enabled status and configuration ID

### 🔧 Version Sweep
- Version bumped to 34.0.0 across all version markers: `AnalyticsEvent::VERSION`, `AnalyticsIntegrityCommand::EXPECTED_VERSION`, `AnalyticsServiceProvider` docblock, `AnalyticsTestCommand` docblock, README badge.

### ⚙️ Config
- New `engagement_scoring` config section with `weights`, `cache_ttl`, `recency_half_life`, and `max_events_window` settings.

### What's New in v33.0.0

### 🧪 AnalyticsTestCommand — Full 10-Provider Validation
- **AnalyticsTestCommand rebuilt** — `zb:analytics:test` now validates all 10 configured analytics providers (GA4, GTM, Meta Pixel, Plausible, PostHog, Mixpanel, Amplitude, Webhook, TikTok, LinkedIn). Previous version only tested 5 providers.
- **`--dry-run` flag** — Preview what would be dispatched without actually sending events. Useful for verifying provider configuration in staging.
- **`--json` flag** — Machine-readable JSON output with provider status, latency, dispatch results, and catalog statistics. Ideal for CI/CD pipeline integration.
- **Per-provider latency tracking** — Each dispatch is timed and reported in milliseconds.
- **Consent state display** — Shows current consent signal states (ad_storage, analytics_storage, etc.) for quick GDPR debugging.
- **Catalog summary** — Displays total event count and category count from EventCatalog.
- **Error aggregation** — Exit code `FAILURE` if any provider throws, with error messages displayed.

### 🔧 Version Sweep
- Version bumped to 33.0.0 across all 12 version markers: `composer.json`, `package.json` (29.0.0 → 33.0.0), `AnalyticsEvent::VERSION`, `AnalyticsIntegrityCommand::EXPECTED_VERSION` (30.0.0 → 33.0.0), `AnalyticsServiceProvider` docblock (30.0.0 → 33.0.0), JS `getVersion()` (31.0.0 → 33.0.0), JS `_getInternalVersion()` (31.0.0 → 33.0.0), `analytics.js` header (32.0.0 → 33.0.0), all 3 Svelte composables (31.0.0 → 33.0.0), TypeScript definitions (26.0.0 → 33.0.0), README badge (29.1.0 → 33.0.0).
- README provider count updated: 8 → 10 providers (added TikTok, LinkedIn).

### 🧪 Tests
- **`V3300TestCommandRebuildVersionSweepTest`** — 35+ assertions covering AnalyticsTestCommand (construction, all 10 providers, dry-run mode, JSON output, consent display, catalog summary, disabled providers, error handling), version consistency across all 12 markers, file integrity, strict types.

### What's New in v31.0.0

### 🚀 Event Stream Processing Engine

- **EventStreamProcessorService** — Sequential event analysis engine that processes raw analytics events into ordered streams with position tracking, time-since-previous computation, and session sequence grouping. Inspired by Amplitude Pathfinder, Mixpanel Flow, and PostHog User Paths.
- **Pattern Discovery** — Automatic discovery of frequent event sequences ( subsequences of length 3+). Patterns are tracked per-client and aggregated globally with occurrence counts, unique user counts, average/median duration, and conversion rates. Configurable `min_pattern_support` threshold filters noise.
- **Auto-Funnel Detection** — Identifies sequences ending in conversion events (purchase, subscribe, sign_up, start_trial, trial_converted) and computes per-step completion rates with drop-off position analysis.
- **Stream Anomaly Detection** — Detects three types of anomalies: unusual gaps (time between events exceeds baseline + N standard deviations), rapid repetitions (same event fired 3+ times within 2s), and velocity spikes (event rate in recent events significantly exceeds historical baseline).
- **StreamEvent DTO** — Rich stream event representation with position, time-since-previous, session sequence ID, and category resolution. Stable ID generation via XXH128 hash.
- **EventSequencePattern DTO** — Represents a detected pattern with statistical metadata: occurrences, unique users, avg/median duration, conversion rate, and sample client IDs.
- **Config section** — `stream_processing` with env-driven toggles for enable/disable, cache TTL, max sequence length, max patterns per client, min pattern support, anomaly deviation threshold, anomaly window, and max stream events per client.
- **ServiceProvider registration** — `EventStreamProcessorService` registered as singleton with config injection.
- **Comprehensive test suite** — 20 Pest tests covering: enabled/disabled states, stream event creation, position tracking, time-since-previous, pattern discovery, auto-funnel detection, client stream analysis, anomaly detection, client stream clearing, global stats, ID generation, DTO serialization, fallback client IDs, and category resolution.
- **Version sweep** — 30.0.0 → 31.0.0 across all version markers (PHP, JS, Svelte composables, README).

### What's New in v28.0.0

### 🚀 Industry-Standard SaaS Analytics Upgrade

- **Universal Event Normalizer** — `UniversalEventNormalizer` service transforms events into provider-specific payloads using catalog mappings. Handles name resolution, parameter structure, identity fields (client_id, user_id), and timestamps across all 8 providers (GA4, GTM, Meta, Plausible, PostHog, Mixpanel, Amplitude, Webhook). E-commerce events get cross-format conversion via `EcommerceFormatConverter`.
- **Event Schema Migration Service** — `EventSchemaMigrationService` provides database-style schema versioning for analytics events. Register schemas with typed parameters, define migration functions between versions, validate events against schemas, and compute compatibility diffs. Cache-backed schema version tracking with 24h TTL. Built-in migrations for `purchase` (v1→v2) and `sign_up` (v1→v2).
- **AnalyticsOverviewCommand (rebuilt)** — `zb:analytics:overview` command now works correctly after binary corruption fix. Displays provider status table, catalog stats, consent state, and supports `--json`, `--providers`, `--catalog`, and `--health` flags.
- **Version sweep** — 27.0.0 → 28.0.0 across all version markers.

### What's New in v26.0.0

### 🚀 Full Event Catalog Shorthand API
- **35 new convenience methods** on `AnalyticsManager` and `Analytics` facade — one-liner tracking for every event in the catalog.
- **E-commerce shorthands**: `viewItem()`, `addToCart()`, `removeFromCart()`, `viewCart()`, `beginCheckout()`, `addPaymentInfo()`, `refund()`, `abandonedCart()`, `checkoutAbandon()`, `checkoutStep()`.
- **Engagement shorthands**: `scrollDepth()`, `click()`, `formStart()`, `formSubmit()`, `search()`, `share()`, `outboundClick()`, `contentEngagement()`, `onboardingStep()`, `onboardingCompleted()`, `goalConversion()`, `feedback()`, `featureRequest()`.
- **SaaS lifecycle shorthands**: `subscriptionPaused()`, `subscriptionResumed()`, `planChanged()`, `teamCreated()`, `teamMemberJoined()`, `teamMemberRemoved()`, `roleChanged()`, `paymentFailed()`, `paymentSucceeded()`, `milestoneReached()`, `workspaceCreated()`, `usageQuotaReached()`, `billingRetry()`.
- All methods follow consistent API conventions: required params first, optional params with defaults, extra `$params` array for custom data.
- Full Facade `@method` annotations for IDE autocompletion.

### Example Usage
```php
// E-commerce — single line for every catalog event
Analytics::viewItem(['item_id' => 'SKU-123', 'item_name' => 'T-Shirt', 'price' => 29.99]);
Analytics::addToCart(['item_id' => 'SKU-123', 'item_name' => 'T-Shirt', 'quantity' => 2]);
Analytics::beginCheckout($items, 59.98, ['currency' => 'USD']);
Analytics::purchase('txn_abc', 59.98, $items);
Analytics::refund('txn_abc', 59.98, ['currency' => 'USD']);

// Engagement — every interaction tracked in one line
Analytics::scrollDepth(75, '/blog/article');
Analytics::click('#cta-button', '/pricing');
Analytics::formStart('contact-form', 'Contact Us');
Analytics::formSubmit('contact-form', 'Contact Us', true);
Analytics::search('laravel analytics', 42);
Analytics::share('twitter', 'article', 'post-123');

// SaaS lifecycle — complete subscription funnel tracking
Analytics::signUp('google_oauth');
Analytics::trialStart('Pro', 14);
Analytics::planUpgrade('Free', 'Pro');
Analytics::subscriptionResumed('Pro');
Analytics::milestoneReached('100_events');
Analytics::teamCreated('Engineering', 5);
Analytics::paymentSucceeded(99.00, 'stripe');
```

### 🔄 Version Sweep
- Version bumped to 26.0.0 across all layers: `composer.json`, `AnalyticsEvent::VERSION`, `AnalyticsIntegrityCommand::EXPECTED_VERSION`, `AnalyticsServiceProvider` docblock, JS `getVersion()` + `_getInternalVersion()`, README badge.

### 🧪 Tests
- **`V2600EventCatalogShorthandTest`** — 60+ assertions covering all 35 new shorthand methods (ecommerce, engagement, SaaS lifecycle), event name verification, parameter correctness, optional param behavior, Facade proxy verification, catalog consistency, version sweep.

### What's New in v25.0.0

### 🔍 Event Hash Deduplication Pipeline Middleware
- **`EventHashDedupFilter`** — Pipeline-stage event deduplication using SHA-256 content hashing. Computes a deterministic hash from event name + sorted parameters and checks against an in-memory seen-set. Events with identical content within a single request lifecycle are silently dropped. Prevents duplicate dispatch during eager Inertia re-renders and API retries.
- **Cross-request deduplication** — Optional cache-backed deduplication with configurable TTL for API retry scenarios. Uses the configured cache driver for persistent seen-set storage.
- **FIFO eviction** — Automatic eviction when the in-memory seen-set exceeds configurable capacity (default: 1000 entries).
- **`computeHash()`** — Public method for computing event content hashes. Useful for integration with external idempotency systems.
- **`stats()`** — Returns dedup statistics (seen count, max entries, cross-request config).

### 🖐️ Session Fingerprint Service
- **`SessionFingerprintService`** — Deterministic browser fingerprinting for bot detection and session quality scoring. Generates stable SHA-256 fingerprints from normalized browser signals (user agent, screen resolution, color depth, timezone, language, platform, canvas hash).
- **Server-side fingerprinting** — `generateFromRequest()` creates fingerprints from HTTP headers (User-Agent, Accept-Language, Sec-CH-UA-Platform).
- **Quality scoring** — `recordFingerprint()` stores fingerprints with a 0-100 quality score based on uniqueness, frequency, and multi-fingerprint risk factors.
- **Bot detection** — `isSuspicious()` checks if a fingerprint has been seen more than 100 times, indicating potential bot traffic.
- **Cache-backed tracking** — Uses the cache driver with configurable TTL (default 1 hour) and per-client fingerprint limits.

### 🏷️ Event Taxonomy Auto-Tagger
- **`EventTaxonomyEnricher`** — Pipeline middleware that automatically classifies events into 8 semantic categories: `conversion`, `intent`, `engagement`, `navigation`, `transaction`, `identity`, `error`, `search`. Uses pattern matching on event names and parameter indicators.
- **Enriched metadata** — Each event processed through the enricher gets: `zb_taxonomy_category`, `zb_catalog_match` (bool), and `zb_provider_count` (int) attached as params.
- **`classify()`** — Public method for standalone event classification. Useful for dashboard filtering and reporting.
- **`categories()`** — Returns all 8 supported taxonomy categories.

### 🔄 Version Sweep
- Version bumped to 25.0.0 across all layers: `composer.json`, `package.json`, `AnalyticsEvent::VERSION`, `AnalyticsIntegrityCommand::EXPECTED_VERSION`, `AnalyticsServiceProvider` docblock, JS `getVersion()` + `_getInternalVersion()`, Svelte composables (useAnalytics, useAnalyticsConfig, usePerformanceTracker), TypeScript definitions (`analytics.d.ts`), README badge.

### 🧪 Tests
- **`V2500DedupFingerprintTaxonomyVersionTest`** — 40+ assertions covering EventHashDedupFilter (hash computation, in-memory dedup, FIFO eviction, cross-request dedup, stats), SessionFingerprintService (fingerprint generation, server-side fingerprint, recording, quality scoring, bot detection, normalization, stats), EventTaxonomyEnricher (classification of all 8 categories, param-based classification, catalog enrichment, edge cases), EventCatalog integrity, version consistency across 12 markers.

---

### What's New in v24.0.0

### 🚀 Performance Score Service
- **`PerformanceScoreService`** — Server-side Web Vitals aggregation and scoring. Computes 0-100 performance scores using Google's recommended thresholds (LCP 25%, INP 30%, CLS 25%, TTFB 20% weight). Supports p75 aggregation across multiple page views, cache-backed score storage, and per-page/session scoring.
- **`PerformanceScoreEvent`** — Typed event class for tracking aggregate performance scores alongside individual metric breakdown (LCP, INP, CLS, TTFB ratings).
- Config expansion: `performance.cache_prefix`, `performance.aggregation_window`, `performance.auto_score`.

### 🍪 Cookie Consent Banner Service
- **`ConsentBannerService`** — Server-rendered, self-contained GDPR/CCPA consent banner with inline JS. Features:
  - Granular consent purposes (analytics, marketing, functional, necessary)
  - Accept All / Reject All / Customize with per-purpose toggles
  - Automatic GA4 Consent Mode v2 synchronization via `gtag('consent', 'update')`
  - Server-side consent API integration (`POST /api/analytics/consent`)
  - Light/dark theme, bottom/top position, responsive design
  - Config-driven purpose labels and descriptions
  - `renderConsentScript()` for `<head>` default consent initialization
  - Blade integration: `{!! app(ConsentBannerService::class)->render() !!}`

### ⚡ Full-Stack Boot Helper (JS)
- **`initFullStack(pageProps, options)`** — One-call initialization for production SaaS apps. Boots core analytics + Web Vitals + error capture + scroll depth + offline recovery with a single cleanup function.
- **`initPerformanceTracker(options)`** — Enhanced Web Vitals tracking that computes and dispatches an aggregate `performance_score` event on page hide.
- **`syncConsentState()`** — Client-side consent cookie ↔ GA4 Consent Mode synchronization.

### 📊 Svelte Performance Tracker Composable
- **`usePerformanceTracker.svelte.js`** — Reactive Svelte store composable for real-time Core Web Vitals tracking. Exposes `webVitals`, `performanceScore`, `performanceLabel` stores with `start()`, `stop()`, `getMetrics()` methods.
- Auto-computes weighted performance score after configurable delay.
- Derived `performanceLabel` store with emoji indicators (🟢🟡🔴⚪).

### 📝 Event Catalog Update
- `performance_score` added to `EngagementEvents` catalog with cross-provider mappings (GA4, PostHog, Mixpanel, Amplitude).

### 🧪 Tests
- **30 new test cases** covering `PerformanceScoreService` (metric rating, score calculation, aggregation, caching), `PerformanceScoreEvent` (construction, metric breakdown, extra params), and `ConsentBannerService` (purposes, rendering, themes, consent script).

---

### What's New in v23.0.0

### 📊 Analytics Dashboard Service
- **`AnalyticsDashboardService`** — Pre-computed dashboard data aggregator for admin interfaces. Provides single-request `overview()` returning 8 widgets: event volume (by provider/category), provider health (enabled/dispatched/failed/success_rate per provider), catalog summary (total events/category counts/provider coverage), funnel distribution (signup → trial → subscribe → upgrade → cancellation), revenue breakdown (MRR/ARR from subscription tiers), SaaS health score (from SaaSHealthScoreService), and consent stats. All data is cache-backed with configurable TTL (default 5 min). Individual `widget()` access for partial dashboard updates.

### 🔑 Event Idempotency Key Service
- **`EventIdempotencyKeyService`** — Server-side event deduplication preventing duplicate processing on client retries. Three strategies: `client_key` (recommended, uses client-provided key like Stripe), `fingerprint` (auto-generated xxh128 hash from event name + sorted params), `hybrid` (checks both for maximum deduplication). Request-level in-memory cache for fast-path dedup + persistent cache with configurable TTL (default 1 hour). `markProcessed()` / `isProcessed()` / `forget()` for manual control. Useful for DLQ replay scenarios.

### 🔔 Webhook Event Subscription Service
- **`WebhookEventSubscriptionService`** — Real-time event push to external webhooks. Config-driven subscriptions with per-subscription event filtering (wildcard `*` or named events). HMAC-SHA256 payload signing via `X-ZB-Signature` header. Four payload formats: `json` (default), `slack` (Block Kit), `teams` (Adaptive Card), `discord` (Embed). Exponential backoff retry (configurable attempts, default 2). Per-minute rate limiting (default 60). `test()` method for verifying webhook configuration. Non-blocking dispatch with logged failures.

### 📦 DLQ Management Command
- **`zb:analytics:dlq`** — Admin CLI for Dead Letter Queue inspection and management. 6 actions: `list` (table output with `--limit`), `show` (event details by `--id`), `replay` (re-dispatch single event), `replay-all` (batch replay with confirmation), `purge` (permanent deletion with safety prompt), `stats` (DLQ statistics). `--json` flag for machine-readable output.

### Configuration
- New `dashboard` config section — 2 options: `cache_ttl`, `top_events_count`.
- New `idempotency` config section — 4 options: `enabled`, `ttl`, `strategy`, `cache_prefix`.
- New `webhook_subscriptions` config section — 5 options: `enabled`, `subscriptions`, `default_timeout`, `default_retries`, `rate_limit_per_minute`.

### Tests
- **`V2300DashboardIdempotencyWebhookDlqTest`** — 30+ assertions covering DashboardService (overview, event volume, provider health, catalog summary, funnel, revenue, widget access), IdempotencyKeyService (disabled/client_key/fingerprint/hybrid strategies, deterministic hashing, param normalization, mark/forget), WebhookEventSubscriptionService (config, event matching, disabled mode, format), version consistency, catalog integrity.

### What's New in v22.0.0

### 🎯 Industry-Standard SaaS Starter Upgrade

#### New Event Types — Product Analytics & Activation
- **`FirstValueEvent`** — Tracks the critical "aha moment" when users first experience core product value. Includes time-to-value (TTV) metric for activation rate analysis. Priority: critical.
- **`UpcomingRenewalEvent`** — Dispatched when subscription renewal is approaching (7/14/30 days). Used for churn prediction, renewal outreach, and revenue forecasting.
- **`RetentionRiskEvent`** — Signals when users show churn risk patterns (usage decline, support volume, login frequency). Supports low/medium/high/critical risk levels with computed risk scores.
- **`ProductAnalyticsEvent`** — Structured product analytics wrapper with category/action/object taxonomy (e.g., `report.create.monthly_summary`). Enables consistent domain-specific event tracking.

#### New Services
- **`AnalyticsFeatureFlagService`** — Feature flag analytics service. Registers, evaluates, and tracks feature flag exposures with deterministic variant assignment. Provides adoption stats and conversion tracking for A/B experiments. Cache-backed for performance.
- **`AnalyticsJourneyOrchestrator`** — User journey stage progression tracker. Manages lifecycle stages (visitor → signed_up → activated → retained → champion). Tracks transitions, time-in-stage, and provides funnel distribution analysis. Forward-only advancement prevents stage regression.

#### Configuration
- New `journey` config section for user journey orchestration (stages, cache prefix, TTL).
- New `feature_flags` config section for feature flag analytics (enabled, cache TTL).

#### Catalog Expansion
- 4 new SaaS event types added to `SaaSEvents` catalog with full multi-provider mappings (GA4, Meta, PostHog, Plausible, Mixpanel, Amplitude).
- Total catalog: 119+ events across 5 categories.

#### Tests
- `V2200SaaSStarterFeatureFlagJourneyUpgradeTest` — 30+ assertions covering new event classes, catalog integrity, FeatureFlagService, JourneyOrchestrator, and version sweep.

### What's New in v21.0.0

### 🔍 Event Schema Runtime Validator
- **`EventSchemaRuntimeValidator`** — Validates dispatched events against registered parameter schemas. Checks required parameters, value types, string lengths, numeric ranges, and regex patterns before dispatch. Configurable strict/warn/off enforcement modes. Batch validation API for bulk event processing.

### 🔗 Composable Enrichment Pipeline
- **`ComposableEnrichmentPipeline`** — Config-driven, ordered event enrichment stages. 10 built-in enrichment stages: `pii_scrub` (hash/remove PII fields), `consent_filter` (GDPR param filtering), `utm_source` (auto-attach request UTM params), `device_context` (user agent, IP, locale), `session_context` (session ID, page count, duration), `tenant_tag` (multi-tenant ID attachment), `identity_link` (client_id ↔ user_id metadata), `timestamp_normalize` (UTC ISO 8601), `cost_tag` (dispatch cost estimation), `source_tag` (origin metadata). Custom handler registration via `registerHandler()`.

### 📋 Analytics Audit Log Service
- **`AnalyticsAuditLogService`** — Immutable, append-only audit trail for GDPR Article 30 compliance. Records event name, timestamp, source, provider results, and content hash for integrity verification. Configurable retention, success/failure logging, event/category exclusions, and query API.

### 📊 Provider Event Compatibility Matrix
- **`ProviderEventCompatibilityMatrix`** — Cross-provider event mapping coverage analysis. Weighted scoring across GA4, Meta, PostHog, Plausible, Mixpanel, Amplitude. A+ through F maturity grades, worst-covered event detection, and improvement recommendations.

### 🆔 Event Fingerprint Service
- **`EventFingerprintService`** — Deterministic content-based event hashing for exact deduplication across retries, idempotency keys for API requests, and event identity comparison. Configurable time bucketing, identity inclusion, and hash algorithm.

### Configuration
- New config sections: `schema_validation`, `enrichment_pipeline`, `audit_log`, `fingerprinting`

### 📈 Analytics Data Quality Scorer
- **`AnalyticsDataQualityScorer`** — Composite quality scoring engine (0-100, A-F grades) across 6 weighted dimensions: schema compliance, provider coverage, payload health, naming convention, identity completeness, timestamp accuracy. Batch scoring, catalog-level scoring, and actionable improvement recommendations.

### 🏷️ Event Classification Service
- **`EventClassificationService`** — ML-ready event classification engine. Auto-classifies events into 8 semantic categories (conversion, intent, engagement, navigation, transaction, identity, error, search) using name patterns, parameter indicators, and catalog membership. Auto-tagging with source, identity, monetary value, and catalog tags.

### What's New in v20.0.0

### 🚀 Event Transport Layer
- **`EventTransportService`** — Abstract HTTP transport with configurable retry, timeout, and circuit breaker for analytics provider dispatch. Tracks per-provider circuit state (closed/open/half-open), consecutive failure counts, and latency histograms with p50/p95/p99 percentile computation. Inspired by Segment's transport layer and RudderStack's batching transport.
- **Circuit breaker pattern** — Automatic provider isolation when failure threshold is exceeded. Configurable reset timeout with half-open probe mechanism for gradual recovery.
- **Latency tracking** — Per-provider dispatch latency statistics with configurable sample retention.
- **`transport` config section** — 7 configurable options: `enabled`, `default_timeout`, `default_retries`, `circuit_threshold`, `circuit_reset_timeout`, `circuit_half_open_max`, `metrics_ttl`.

### 🔗 Event Correlation Matrix
- **`EventCorrelationMatrixService`** — Statistical cross-event correlation scoring using Jaccard similarity coefficient. Analyzes event co-occurrence patterns to identify significant relationships between tracked events. Used for funnel insight generation, user behavior prediction, and instrumentation gap detection.
- **`computeJaccard()`** — Computes Jaccard similarity between two event user-sets. Score of 1.0 = perfect overlap, 0.0 = no overlap.
- **`computeAllPairs()`** — Batch computation of all significant event pairs with configurable min_correlation threshold and max_pairs limit.
- **`findCorrelatedEvents()`** — "Users who did X also did Y" — directional correlation with forward/backward percentages.
- **`correlation` config section** — 6 configurable options: `enabled`, `cache_ttl`, `min_event_count`, `min_correlation`, `max_pairs`, `time_window`.

### 📦 Data Lake Export
- **`DataLakeExportService`** — S3/GCS-compatible event export pipeline for data warehousing and ETL processing. Supports batch exports, partitioned output by date, and configurable file formats (JSONL, CSV, NDJSON).
- **Storage key generation** — Convention-based path generation: `{prefix}/{YYYY/MM/DD}/{filename}.{format}[.gz]`.
- **Job tracking** — Export job lifecycle management (pending → running → completed/failed) with cache-persisted status.
- **Config validation** — Validates storage backend, bucket, format, and compression settings.
- **`data_lake` config section** — 11 configurable options: `enabled`, `storage`, `bucket`, `prefix`, `format`, `batch_size`, `retention_days`, `partition_by_date`, `compress`, `timeout`.

### 🔑 SDK Scope Token System
- **`SdkScopeTokenService`** — Scoped write tokens for client-side permission management. Generates and validates tokens controlling which analytics operations a client-side SDK is authorized to perform.
- **Permission scoping** — Fine-grained permissions: `track`, `batch`, `identify`, `consent`, `pageview`.
- **Category scoping** — Restrict tokens to specific event categories: `ecommerce`, `saas`, `engagement`, `custom`.
- **Rate limiting** — Per-token rate limits with per-minute sliding window enforcement.
- **Token lifecycle** — Generation, validation, permission checks, rate limit checks, revocation with configurable TTL and hash-based storage.
- **`sdk_tokens` config section** — 6 configurable options: `enabled`, `token_ttl`, `default_rate_limit`, `max_tokens_per_scope`, `hash_algorithm`, `signing_key`.

### 🔄 Version Sweep
- Version bumped to 20.0.0 across `composer.json`, `package.json`, `AnalyticsEvent::VERSION`, JS `getVersion()` + `_getInternalVersion()`, Svelte composables, TypeScript definitions, `AnalyticsServiceProvider` docblock, `AnalyticsIntegrityCommand::EXPECTED_VERSION`.

### 🧪 Tests
- **`V2000TransportCorrelationDataLakeSdkTokenTest`** — 40+ tests covering EventTransportService (circuit breaker states, success/failure recording, latency stats, reset, half-open probing), EventCorrelationMatrixService (Jaccard computation, all-pairs analysis, correlated events, user events), DataLakeExportService (format conversion, storage key generation, config validation, job tracking), SdkScopeTokenService (token generation, permission checks, category access, rate limiting, revocation, config), config integrity (transport + correlation + data_lake + sdk_tokens sections), version sweep.

### What's New in v18.0.0

### 📊 Analytics Observability Service
- **`AnalyticsObservabilityService`** — Dispatch-level observability for the analytics pipeline. Tracks per-provider latency histograms (p50, p95, p99), success/failure rates, error budgets, slow dispatch detection, and filter pipeline metrics. Inspired by OpenTelemetry metrics and Segment's Observability API.
- **`observability` config section** — 6 configurable options: `enabled`, `ttl`, `providers`, `error_budget_threshold`, `slow_dispatch_ms`, `latency_buckets`.
- **7 new API endpoints** — `GET /api/analytics/observability` (dashboard), `GET /api/analytics/observability/{provider}` (metrics), `GET /api/analytics/observability/{provider}/events` (per-event), `GET /api/analytics/observability/{provider}/timeline` (dispatch timeline), `GET /api/analytics/observability/filters` (filter metrics), `DELETE /api/analytics/observability/{provider}` (reset provider), `DELETE /api/analytics/observability` (reset all).
- **Inertia middleware observability prop** — `zbAnalytics.observability` exposes `enabled` and `slowDispatchMs` to the JS client for client-side dispatch monitoring.
- **TypeScript `ObservabilityConfig` interface** — Full IntelliSense support for the new observability config in the `.d.ts` file.

### 🔄 Version Sweep
- Version bumped to 18.0.0 across `composer.json`, `package.json`, `AnalyticsEvent::VERSION`, JS `getVersion()`, Svelte composable docblocks, TypeScript definitions, and `AnalyticsIntegrityCommand::EXPECTED_VERSION`.

### 🛒 EcommerceFormatConverter — Mixpanel & Amplitude Support
- **`ga4ToMixpanelProperties()`**, **`ga4ToMixpanelPurchase()`**, **`ga4ToMixpanelRefund()`** — GA4 → Mixpanel e-commerce data conversion with `$product_id`, `$name`, `$price`, `$quantity` fields matching Mixpanel's `$products` convention.
- **`ga4ToAmplitudeProperties()`**, **`ga4ToAmplitudePurchase()`**, **`ga4ToAmplitudeRefund()`** — GA4 → Amplitude e-commerce data conversion with `productId`, `productName`, `revenue`, `currency` fields matching Amplitude's Revenue and eCommerce API.
- **`buildForAllProviders()`** — Universal multi-provider builder. Pass GA4-format params and event type (`purchase`, `refund`, `add_to_cart`, `view_item`) → get formatted parameters for all 5 providers at once.

### 🐛 Bug Fixes
- **JS client `getVersion()` returned `'17.0.0'`** after version sweep — now correctly returns `'18.0.0'`.
- **`package.json` version was `'17.0.0'`** — now aligned to `'18.0.0'`.
- **JS client Meta mapping** — `refund` now maps to `'Refund'` (was `null`), `view_cart` maps to `'ViewCart'` (was `null`), `add_to_wishlist` mapping added.

### 🧪 Tests
- **`V1800VersionSweepAndEcommerceConverterTest`** — 23 tests covering version alignment, Mixpanel/Amplitude converter correctness, `buildForAllProviders()` output, empty items edge cases, and Meta mapping coverage.

### What's New in v17.0.0

### 🛡️ API Guard + Event Budget Enforcement
- **`AnalyticsApiGuard` registered as config-driven singleton** — Pre-dispatch request validation and rate limiting. Validates payload size, event name lengths, and batch sizes before event processing. Configured via new `zeroboiler.analytics.api_guard` config section with env-driven defaults.
- **`EventBudgetService` registered as config-driven singleton** — Per-client and per-user event budget enforcement. Sliding window rate limiting with configurable limits and overflow policies (reject, sample, throttle). Configured via new `zeroboiler.analytics.budget` config section.
- **Budget enforcement in API controller** — Both `POST /api/analytics/events` and `POST /api/analytics/batch` now check budget limits before processing. Returns 429 with `budget_exceeded` status when limits are exceeded. Budget counters are recorded after successful dispatch.

### 🔍 Event Deconfliction Singleton
- **`EventDeconflictionService` registered as config-driven singleton** — Multi-provider collision detection now available via dependency injection. Detects provider name collisions, reverse collisions, and similar event names (Levenshtein distance ≤ 2) across all 6 provider mappings.

### 📦 Config Expansion
- **`api_guard` config section** — 5 configurable options: `enabled`, `batch_max`, `max_payload_bytes`, `max_event_name_length`, `rate_window`.
- **`budget` config section** — 9 configurable options: `enabled`, `client_limit`, `user_limit`, `global_limit`, `window_seconds`, `overflow_policy`, `sample_rate`, `cache_ttl`, `use_cache`.

### 🔄 Version Sweep
- Version bumped to 17.0.0 across `package.json`, `AnalyticsServiceProvider` docblock, `AnalyticsIntegrityCommand::EXPECTED_VERSION`. `AnalyticsEvent::VERSION`, JS client, and Svelte composables were already at 17.0.0.

### 🧪 Tests
- **`V1700IndustryStandardSaaSUpgradeTest`** — 15 test cases covering: version consistency (composer.json, AnalyticsEvent, package.json, IntegrityCommand), EventBudgetService (constructor, operations, client limit, sample policy, topClients, resetClient/resetUser), AnalyticsApiGuard (validation, disabled mode, batch validation), EventDeconflictionService (collision analysis, similar names), config integrity (api_guard + budget sections), EventCatalog validation, ServiceProvider version docblock.

### What's New in v16.0.0

### 💰 SaaS Revenue Convenience Methods
- **`trackMrr()`** — Track Monthly Recurring Revenue movements (new, expansion, contraction, churn, reactivation). Each call includes amount, currency, movement type, plan name, and user ID. Critical for subscription analytics dashboards.
- **`trackArr()`** — Track Annual Recurring Revenue snapshots with customer count. Ideal for monthly/quarterly ARR reporting events.
- **`trackChurn()`** — Track customer churn with revenue impact analysis. Combines cancellation tracking with MRR loss, plan name, and churn reason in a single event.
- **`trackLtv()`** — Track Customer Lifetime Value calculation points at key milestones (payment, renewal, upgrade). Includes trigger context for funnel analysis.

### 🧪 A/B Test Conversion Tracking
- **`abTestConversion()`** — Fire conversion events for A/B test goals. Complements the existing `abTestExposure()` for complete experiment funnel tracking (exposure → conversion).

### 🛒 E-Commerce Extended Convenience
- **`addToWishlist()`** — Track wishlist additions with GA4-compatible item format and currency.
- **`promotionView()`** — Track promotional banner/content views with promotion ID, name, creative name, and slot position.

### 🔗 Event Alias Registry
- **`registerAliases()`** — Register persistent event name aliases (alias → canonical name mapping). Stored for request lifecycle.
- **`resolveAlias()`** — Resolve an aliased event name to its canonical form. Returns unchanged name if not registered.
- **`getAliases()`** — Inspect all registered aliases.
- Complements the existing `EventAliasResolver` service with a lightweight in-memory registry for microservice and cross-team name standardization.

### 📦 Client-Side Debounced Tracking (JS)
- **`trackDebounced()`** — JS client method that debounces rapid-fire events (scroll, resize, keystroke) with configurable delay (default 300ms). Only the last event within the window is dispatched.
- **`trackThrottled()`** — JS client method that throttles events to fire at most once per interval (default 1000ms). First event fires immediately; subsequent calls within the window are dropped.

### 🔄 Version Sweep
- Version bumped to 16.0.0 across all layers: `composer.json`, `package.json`, `AnalyticsEvent::VERSION`, JS client, Svelte composables, TypeScript definitions, ServiceProvider, IntegrityCommand, DiagnosticCommand, README badge, CHANGELOG.

### What's New in v15.0.0

### 🔧 Lifecycle Config Section
- **New `zeroboiler.analytics.lifecycle` config section** — Declarative lifecycle event mapping configuration. Toggle individual mappings on/off, add custom event-to-analytics-class mappings via `custom_mappings`, and control whether custom mappings override defaults. Previously LifecycleEventMapper read from this config key but it returned an empty array — now fully documented with env-driven defaults.

### 📊 Provider Summary Completeness
- **`providerSummary()` now includes all 8 providers** — Previously only reported 6 providers (GA4, GTM, Meta, Plausible, PostHog, Webhook). Now includes Mixpanel and Amplitude with their respective ID/accessor fields. Ensures admin dashboards and health checks see complete provider coverage.

### ⚡ SaaS Convenience: `trialConverted()`
- **New `trialConverted()` shorthand on AnalyticsManager** — Tracks trial-to-paid conversion with plan name, amount, and currency. Critical metric for SaaS conversion funnel analysis. Companion to existing `trialStart()` and `subscription()` methods.

### What's New in v14.0.0

### 🔗 Plausible Self-Hosted & PostHog CAPI
- **PlausibleTracker self-hosted support** — New `customScriptUrl` parameter for self-hosted Plausible instances. New `trackGoal()` and `trackPageView()` methods for custom goal and pageview tracking.
- **PosthogTracker CAPI + identity** — New `trackWithPerson()` for Conversions API dispatch with $set person properties. New `identify()`, `alias()`, `trackPageView()`, and `isFeatureEnabled()` methods. Constructor now accepts `capiEnabled` and `capturePath` parameters.
- **Singleton registration** — Both Plausible and PostHog trackers registered as config-driven singletons in ServiceProvider.
- **Comprehensive tracker test suite** — 40+ test cases covering construction, consent, CAPI, identity, feature flags, and interface contracts.

### What's New in v13.0.0

### 🧪 Complete AnalyticsFake — Industry-Standard Test Double
Full drop-in replacement for AnalyticsManager with 90+ proxy methods, 15 assertion helpers, and interceptor support. Every public method on the AnalyticsManager facade now works seamlessly in tests without any real dispatch.

**AnalyticsFake Enhancements:**
- **90+ proxy methods** — Complete coverage of all AnalyticsManager public methods. Every method from `track()` to `timeSeriesCompare()` has a working fake implementation. Methods that dispatch events actually capture them; service-dependent methods return safe defaults.
- **Tracker stubs** — `ga4()`, `gtm()`, `meta()`, `plausible()`, `posthog()`, `webhook()`, `mixpanel()`, `amplitude()` all return disabled tracker instances. No HTTP calls in tests.
- **Interceptor support** — `interceptBefore()` / `interceptAfter()` run through the EventInterceptorRegistry. Before-interceptors can cancel or modify events; after-interceptors fire with success state.
- **E-commerce capture** — `ecommerceCalls()` returns all `trackEcommerce()` invocations with event name, data, and params.
- **Funnel progress capture** — `funnelProgressCalls()` returns all funnel progress tracking invocations.
- **SaaS identity capture** — `saasIdentityCalls()` returns all `trackSaaSIdentity()` invocations.
- **Metrics tracking** — Fake maintains an `AnalyticsMetrics` instance. `flushMetrics()` returns a pre-flush snapshot.

**New Assertion Helpers:**
- **`assertTrackedOnce(name)`** — Assert event tracked exactly once (shorthand for `assertTrackedTimes(name, 1)`).
- **`assertTrackedAtLeast(name, times)`** — Assert event tracked at least N times.
- **`assertEventSequence(names)`** — Assert events tracked in a specific order. Unrelated events between are ignored.
- **`assertEventBatch(names)`** — Assert all named events are present (order-independent).
- **`assertSaaSIdentityLinked(userId, callback?)`** — Assert SaaS identity linking call.
- **`assertFunnelProgressTracked(funnelName, callback?)`** — Assert funnel progress tracking.

**New Test:**
- **`V1300AnalyticsFakeEnhancementTest`** — 100+ test cases covering: core tracking (track, trackEvent, directDispatch, trackAsync), SaaS lifecycle (14 methods), e-commerce (7 methods), engagement (7 methods), identity (4 methods), page views (3 methods), consent (4 states), preferences (6 methods), revenue & PLG (5 methods), funnel tracking (3 methods), B2B groups (3 methods), debug & metrics (5 methods), interceptors (4 methods), tracker accessors (8 methods), script generation (2 methods), data layer (1 method), catalog queries (11 methods), profile & health (9 methods), orchestration & PLG (8 methods), assertion API (20+ assertions), inspection methods (6 methods), reset (7 fields), version consistency.

**Version sweep:** 12.0.0 → 13.0.0 across `composer.json`, `package.json`, `AnalyticsEvent::VERSION`, JS client, Svelte composables, TypeScript definitions, ServiceProvider, README badge, IntegrityCommand, DiagnosticCommand, CHANGELOG.

### What's New in v12.0.0

### 🛡️ Production-Grade Event Sanitization & Offline Buffering

Industry-standard event data quality and offline reliability. This release adds production-grade parameter sanitization, a comprehensive diagnostic command, and offline-first event buffering for the JS client.

**New Services:**
- **`AnalyticsEventSanitizer`** — Config-driven event parameter sanitization. Strips HTML, null bytes, enforces naming conventions, truncates oversized values, and blocks sensitive keys (password, token, secret, api_key, credit_card, ssn). Validates events without modification via `validate()`. Preserves type integrity (int, float, bool, null). Recursive array sanitization. Enable via `ANALYTICS_SANITIZATION_ENABLED=true`.

**New Commands:**
- **`zb:analytics:diagnostic`** — Comprehensive multi-dimensional diagnostic CLI tool. 10-section health check: config integrity, provider configuration (GA4, GTM, Meta, Plausible, PostHog, Mixpanel, Amplitude), event catalog validation, GDPR consent compliance, queue configuration, identity tracking, sanitization settings, JS client compatibility, service registration, and e-commerce configuration. Supports `--json` and `--section=` flags.

**New JS Client Features:**
- **Offline Event Buffer** — localStorage-backed offline event buffering. Automatically persists events when offline or API requests fail. FIFO eviction (500 events / 5MB max). Auto-recovery via `enableOfflineRecovery()`. API: `isOffline()`, `saveToOfflineBuffer()`, `loadOfflineBuffer()`, `clearOfflineBuffer()`, `offlineBufferStatus()`, `flushOfflineBuffer()`.

**New Config:**
- `zeroboiler.analytics.sanitization` — 12 configurable options: `enabled`, `max_param_count`, `max_key_length`, `max_value_length`, `strict_naming`, `strip_html`, `strip_null_bytes`, `normalize_booleans`, `truncate_strings`, `disallowed_keys`, `max_event_name_length`, `reserved_prefixes`.

**New Tests:**
- **`V1200EventSanitizerAndVersionTest`** — 30+ test cases covering event sanitization (HTML, null bytes, snake_case, truncation), param sanitization (disallowed keys, type preservation, recursive arrays), validate() method, config access, and version consistency.

**Version sweep:** 11.0.0 → 12.0.0 across `composer.json`, `package.json`, `AnalyticsEvent::VERSION`, JS client, Svelte composables, TypeScript definitions, ServiceProvider, README badge, `AnalyticsIntegrityCommand::EXPECTED_VERSION`

### What's New in v11.0.0

### 🔥 Major Release — Pipeline Smoke Test & GDPR Consent Compliance

Industry-standard end-to-end validation and regulatory compliance tools. This release introduces comprehensive pipeline smoke testing, GDPR Consent Mode v2 compliance auditing, and automated system health verification.

**New Services:**
- **`AnalyticsConsentComplianceService`** — GDPR Consent Mode v2 compliance validation service. 10-dimensional compliance check suite covering consent signal coverage, GDPR purpose configuration, default consent state, consent logging, TTL validation, regional consent detection, provider consent gating, version hash integrity, cookie integration, and data erasure support. Generates GDPR Article 30 audit reports.
- **`AnalyticsSmokeRunnerCommand`** (`zb:analytics:smoke`) — 20-check comprehensive pipeline smoke test command. Validates version integrity, event catalog health, provider configuration, GDPR consent compliance, e-commerce format conversion, consent state management, analytics metrics, facade accessibility, health checks, identity resolution, queue dispatch, pipeline filters, GDPR services, admin commands, Inertia middleware, API controller, and test fake availability.

**New Tests:**
- **`V1100FullSaaSPipelineSmokeTest`** — End-to-end SaaS analytics pipeline test covering: full SaaS user journey (signup → trial → subscription → upgrade → cancellation), e-commerce funnel (view → cart → checkout → purchase → refund), engagement events (page view, scroll, form, search, share, error), Consent Mode v2 compliance, identity resolution (client ID ↔ user ID), multi-provider dispatch, e-commerce format conversion, pipeline processing, GDPR compliance, event catalog integrity (100+ events), DTO round-trips, queue dispatch readiness, facade proxy verification, and AnalyticsFake assertion API.
- **`V1100ConsentComplianceServiceTest`** — 18 test cases covering consent compliance check structure, score calculation, GDPR-safe defaults scoring, audit report generation, all 10 compliance dimensions, cache invalidation, and check field validation.

**Version sweep:** 10.9.0 → 11.0.0 across `composer.json`, `package.json`, `AnalyticsEvent::VERSION`, JS client, Svelte composables, TypeScript definitions, ServiceProvider docblock, `AnalyticsIntegrityCommand::EXPECTED_VERSION`, README badge

**LOC:** ~227K source, 323 test files, 21,350+ assertions

### What's New in v10.8.0

### 🔗 Lifecycle Event Mapper Expansion

New lifecycle mappings for SLA, feature adoption, and revenue expansion events. Config-driven auto-tracking now covers 3 additional SaaS patterns.

**New lifecycle mappings:**
- `sla.breach` → `SlaBreachEvent` — Track SLA violations for compliance dashboards
- `feature.adopted` → `FeatureAdoptedEvent` — Track when users first adopt a feature beyond initial usage
- `revenue.expansion` → `ExpansionRevenueEvent` — Track upsell/cross-sell revenue growth signals

**Version sweep:**
- 10.5.0 → 10.8.0 across all entry points: `composer.json`, `package.json`, `AnalyticsEvent::VERSION`, JS client (`getVersion` + `_getInternalVersion`), Svelte composables, `AnalyticsIntegrityCommand::EXPECTED_VERSION`, `AnalyticsServiceProvider` docblock, README badge

**Test:** `V108LifecycleExpansionAndVersionSweepTest` — 35+ assertions covering version consistency, new catalog entries, provider coverage, format conversion, and cross-category integration

### What's New in v10.6.0

### 🔗 Mixpanel & Amplitude Provider Parity

Full event catalog parity for Mixpanel and Amplitude — matching the existing PostHog and Plausible integration depth. All 5 catalog categories now include native `mixpanel` and `amplitude` event name fields.

**Event Catalog enhancements:**
- Every catalog entry (Ecommerce, SaaS, Engagement, Security, Uptime) now has `mixpanel` and `amplitude` fields alongside existing `ga4`, `meta`, `posthog`, and `plausible`
- New `EventCatalog::allMixpanelNames()`, `allAmplitudeNames()` — aggregate all Mixpanel/Amplitude event names across categories
- New `EventCatalog::mixpanelNameFor()`, `amplitudeNameFor()` — look up provider-specific event name for any catalog event
- `byProvider()` now returns 6 providers (ga4, meta, posthog, plausible, mixpanel, amplitude)
- `providerCoverage()` includes mixpanel and amplitude counts
- `summary()` reports `with_mixpanel` and `with_amplitude` totals
- `allProviderMappingsMatrix()` includes mixpanel and amplitude columns
- Category-level helpers: `EcommerceEvents::mixpanelNames()`, `SaaSEvents::amplitudeNames()`, etc.

**EventTransformer enhancements:**
- New `saasToMixpanelEventMap()` — 80+ event mappings to Mixpanel title-case format (e.g. `add_to_cart` → `Add to Cart`)
- New `saasToAmplitudeEventMap()` — 80+ event mappings to Amplitude past-tense format (e.g. `purchase` → `Completed Order`)
- `transformForProvider()` now supports `'mixpanel'` and `'amplitude'` provider keys
- New `transformForMixpanel()` and `transformForAmplitude()` private methods

**Tracker integration:**
- `MixpanelTracker::track()` auto-transforms event names via `EventTransformer::transformForProvider($event, 'mixpanel')`
- `AmplitudeTracker::track()` auto-transforms event names via `EventTransformer::transformForProvider($event, 'amplitude')`

**Naming conventions:**
- **Mixpanel**: Title Case (e.g. `Sign Up`, `Add to Cart`, `Purchase`)
- **Amplitude**: Past Tense (e.g. `Signed Up`, `Added to Cart`, `Completed Order`)
- Both are snake_case-free for consistency with their respective platform best practices

**Tests:** `MixpanelAmplitudeParityTest` — 35 assertions covering catalog fields, provider lookups, transformer maps, and naming conventions

### What's New in v10.5.0

### 🔄 Event Normalization Service (`EventNormalizationService`)
- **Provider-agnostic event normalization** — Convert a single `AnalyticsEvent` into all provider-specific formats (GA4, GTM, Meta Pixel, PostHog, Plausible, Mixpanel, Amplitude, Webhook) in one call
- **Segment-inspired unified event model** — Write once, dispatch everywhere. No more manual per-provider transformation in application code
- **Batch normalization** — `normalizeBatch()` normalizes multiple events for bulk dispatch
- **Provider name resolution** — `providerNameFor('purchase', 'meta')` returns `'Purchase'` from catalog mappings
- **Target provider discovery** — `targetProvidersFor('sign_up')` returns which providers will receive the event
- **Per-event normalization stats** — Debug/diagnostic view of mappings, missing providers, and coverage gaps
- **Catalog coverage report** — `catalogCoverageReport()` computes fully_covered, partial, and no_coverage counts across the entire event catalog
- **E-commerce auto-enrichment** — GA4 and GTM e-commerce events automatically enriched via `EcommerceFormatConverter`
- **Identity auto-attach** — client_id and user_id automatically included in all provider payloads

### 🔍 Analytics Consistency Service (`AnalyticsConsistencyService`)
- **Cross-provider event dispatch consistency checker** — Verifies events dispatched to multiple providers maintain consistent naming, parameters, and identity linkage
- **6-dimension check suite:**
  - **Catalog Integrity** — Class existence, required keys, name/class validation
  - **Provider Mappings** — Missing core event mappings per enabled provider
  - **Identity Consistency** — Cookie config, TTL, auto-linking, cache prefix validation
  - **Config Validity** — Queue, consent defaults, debug mode, sampling rate validation
  - **Naming Convention** — snake_case compliance across all catalog events
  - **Provider Config** — Missing IDs, tokens, and API keys for enabled providers
- **Composite scoring** — 0-100 score with A+ through F letter grading
- **Cache-backed** — Full check results cached with configurable TTL for dashboards
- **Quick score** — `quickScore()` for lightweight health checks
- **Cache invalidation** — `invalidateCache()` for forced re-check after config changes

### 🎯 Mixpanel & Amplitude Catalog Fields
- **Native `mixpanel` field** added to all catalog entries across EcommerceEvents, SaaSEvents, EngagementEvents, SecurityEvents, UptimeEvents — title-case event names (e.g. "Add to Cart", "Sign Up")
- **Native `amplitude` field** added to all catalog entries — past-tense action names (e.g. "Added to Cart", "Signed Up")
- **`mixpanelNames()` / `amplitudeNames()`** accessor methods on every event catalog class
- **`EventCatalog::allMixpanelNames()` / `allAmplitudeNames()`** unified aggregate methods with deduplication
- **`EventTransformer::transformForProvider()`** now supports `'mixpanel'` and `'amplitude'` providers with full mapping tables
- **`EventTransformer::saasToMixpanelEventMap()` / `saasToAmplitudeEventMap()`** comprehensive static mapping tables (100+ entries each)

### 📦 Version Sweep
- 10.4.0 → 10.5.0 across composer.json, package.json, AnalyticsEvent::VERSION, JS client (getVersion + _getInternalVersion), Svelte composables, TypeScript definitions, ServiceProvider, IntegrityCommand EXPECTED_VERSION, README badge

### 🧪 Testing
- `V105EventNormalizationConsistencyTest` — 30+ assertions covering normalization across all providers, batch normalization, provider name resolution, target providers, stats, coverage report, catalog integrity, provider config, naming convention, identity consistency, config validity, debug mode, sampling rate, full check with scoring, and cache invalidation
- `EventCatalogTest` — expanded with SecurityEvents, UptimeEvents, Mixpanel/Amplitude name assertions, EventTransformer Mixpanel/Amplitude transform tests, updated category counts

### What's New in v9.8.0

### 🌐 Cross-Domain Tracking (`CrossDomainTrackingService`)
- **Multi-domain visitor stitching** — Unify analytics across `app.example.com`, `docs.example.com`, `blog.example.com` and more
- **Linker parameter decoration** — Auto-appends `_zbclid` to outbound links via the JS client config
- **Auth-based identity linking** — Links client IDs across domains when the same authenticated user visits different properties
- **Transitive identity cluster resolution** — `resolveIdentityCluster()` finds all connected client IDs via bidirectional link traversal
- **Inertia props integration** — `zbAnalytics.crossDomain` exposes domains, linker param, and auto-linker flag to the JS client
- **Config-driven** — Enable/disable via `ANALYTICS_CROSS_DOMAIN_ENABLED`, configure domains, linker param, TTL, and exclusions

### 🎥 Session Recording Bridge (`SessionRecordingBridge`)
- **Consent-aware recording control** — Suppresses Hotjar/LogRocket/FullStory/Clarity recording when analytics consent is denied
- **Role-based exclusion** — Admin and support users are never recorded (configurable excluded roles)
- **URL pattern exclusion** — Sensitive pages (`/admin/*`, `/billing/*`, `/settings/*`) are excluded from recording
- **PII masking** — Elements with `data-zb-mask` or `.masked` classes are visually masked in recordings
- **Content blocking** — Elements with `data-zb-block` or `.blocked` classes are completely hidden from recordings
- **Inertia props integration** — `zbAnalytics.sessionRecording` exposes recording config, enabled state, and integration-specific settings to the JS client
- **Multiple providers** — Supports Hotjar, LogRocket, FullStory, and Microsoft Clarity simultaneously

### 📋 Event Schema Export (`EventSchemaExportService`)
- **JSON Schema (Draft 2020-12)** — `exportJsonSchema()` generates a complete validation schema with `$defs` for every catalog event
- **TypeScript type definitions** — `exportTypeScript()` produces `.d.ts`-compatible interfaces with `ZbEventName` union type, per-event typed params, and category-specific types
- **OpenAPI 3.1 operations** — `exportOpenApi()` generates operation definitions for `POST /events`, `POST /batch`, and `GET /catalog` endpoints
- **Admin command** — `php artisan zb:analytics:export-schema --format=json|typescript|openapi --output=-` with pretty-print support
- **Configurable output** — Write to stdout (`--output=-`) or file path; respects `ANALYTICS_SCHEMA_EXPORT_PATH` config

### 🛡️ Analytics Rate Limiter (`AnalyticsRateLimiterService`)
- **Three-tier rate limiting** — Global (10,000/min), per-client (300/min), and per-user (600/min) limits
- **Batch-specific limits** — Separate higher limits for batch endpoints (5,000/min global, 100/min per client)
- **Max batch size enforcement** — Rejects batch requests exceeding `ANALYTICS_RATE_LIMIT_MAX_BATCH` (default: 50 events)
- **Redis-backed** — Uses Laravel's `RateLimiter` facade for distributed rate control across all app instances
- **Query endpoints** — `remainingForClient()`, `remainingGlobal()`, `remainingForUser()` for dashboards and debug tools
- **Fully configurable** — All limits, TTL, and prefix configurable via environment variables

### 🛣️ API Routes (v9.8.0)
- `GET /analytics/cross-domain` — Cross-domain tracking status
- `POST /analytics/cross-domain/link` — Link source and target client IDs
- `GET /analytics/cross-domain/links/{clientId}` — Get linked client IDs
- `GET /analytics/cross-domain/resolve/{clientId}` — Resolve primary client ID
- `DELETE /analytics/cross-domain/{clientId}` — Clear cross-domain links (GDPR)
- `GET /analytics/session-recording` — Session recording bridge status
- `GET /analytics/session-recording/config` — Client-side recording config
- `GET /analytics/schemas/export/json` — JSON Schema export
- `GET /analytics/schemas/export/typescript` — TypeScript definitions export
- `GET /analytics/schemas/export/openapi` — OpenAPI operations export
- `GET /analytics/rate-limits/advanced` — Advanced rate limiter status
- `GET /analytics/rate-limits/advanced/{clientId}` — Per-client rate limit status

### What's New in v9.7.0

### 🎯 Analytics Instrumentation Advisor (`AnalyticsInstrumentationAdvisor`)
- **Code-snippet level guidance** — Generates a prioritized instrumentation plan with ready-to-use PHP and JavaScript code examples for every industry-standard event
- **Quick-start guide** — `quickStartGuide()` returns copy-paste config, middleware, and JS initialization snippets for day-one setup
- **Gap analysis** — `gapAnalysis(['sign_up', 'login'])` compares your tracked events against the industry standard and returns coverage score, gaps with code examples, and covered events
- **Auto-track mapping** — Identifies which events can be wired via config-driven auto-tracking vs. require manual dispatch
- **Category-level summaries** — Instrumentation plan grouped by ecommerce/saas/engagement with counts

### 🎓 Onboarding Completed Event (`OnboardingCompletedEvent`)
- **Dedicated typed event class** — `new OnboardingCompletedEvent(steps: 5, total: 5, duration: 342)` with automatic completion percentage calculation
- **Catalog registered** — Added to EngagementEvents catalog with GA4, Meta, and PostHog provider mappings
- **Full parameter support** — `steps_completed`, `steps_total`, `duration_seconds`, `signup_method`, `skipped_steps`, `completion_percentage`

### 📱 Svelte Composable: `useAnalyticsConfig`
- **`useAnalyticsConfig()`** — Reactive store derived from Inertia page props, auto-updates on navigation
- **`useConsentState()`** — Reactive consent state store for conditional consent banner rendering
- **`useMaturity()`** — Reactive analytics maturity score and grade store for dashboard badges
- **`useFunnelReadiness()`** — Reactive per-funnel readiness scores for instrumentation coverage dashboards
- **Type-safe** — Full TypeScript-compatible return types with proper null handling

### 🔧 Version Sweep
- 9.6.0 → 9.7.0 across composer.json, package.json, AnalyticsEvent::VERSION, JS client (getVersion + _getInternalVersion), Svelte composables, TypeScript definitions, ServiceProvider, IntegrityCommand EXPECTED_VERSION, README badge

### What's New in v9.6.0

### 📊 Event Impact Score Service (`EventImpactScoreService`)
- **Composite event value scoring** — Computes weighted impact scores (0–1) for every catalog event based on:
  - **Revenue correlation** (40%) — How directly the event impacts MRR/ARR (revenue category = 1.0, operational = 0.1)
  - **Funnel position weight** (25%) — Events closer to conversion carry more weight
  - **Frequency multiplier** (20%) — Log-scale normalization of estimated daily event frequency
  - **Provider coverage** (15%) — Events tracked across more providers yield richer insights
- **Letter grades** — A+ through F based on composite score thresholds
- **Top events API** — `topEvents(10)` returns highest-impact events sorted by score
- **Low-impact detection** — `lowImpactEvents(0.2)` identifies events that may not justify instrumentation effort
- **Event comparison** — `compare('purchase', 'error')` returns delta and recommendation
- **Score distribution** — `distribution()` shows grade breakdown and category averages
- **Category analysis** — Per-category ranked event lists with average scores
- **Cache-backed** — Full catalog scoring cached with configurable TTL

### 🔍 Provider Analytics Intelligence Service (`ProviderAnalyticsIntelligenceService`)
- **Multi-provider coverage intelligence** — Comprehensive analysis of GA4, Meta Pixel, PostHog, and Plausible coverage quality
- **Mapping quality analysis** — Distinguishes meaningful (non-identity) mappings from passthrough mappings per provider
- **Coverage opportunity identification** — `coverageOpportunities('plausible', 20)` finds events that would benefit most from being added to a specific provider, with suggested names
- **Cross-provider mapping matrix** — Boolean matrix for dashboard heatmap visualization
- **Gap prioritization** — Critical gaps (0 providers), quick wins (1 provider), and category-specific priorities
- **Coverage grade** — Overall grade based on average provider coverage
- **Cache-backed** — Report cached with configurable TTL

### 🛠️ EventBuilder Extensions
- **`source()`** — Set event origin (api|server|client|webhook|replay|batch) on the built AnalyticsEvent
- **`sourceId()`** — Embed traceable source identifier (`_source_id` param) for request/job correlation
- **`sessionId()`** — Embed session identifier (`_session_id` param) for session-scoped events
- **`group()`** — Embed group/context identifier (`_group` param) for B2B/multi-tenant analytics
- All methods are chainable and integrate with the existing `build()`, `dispatch()`, `dispatchAsync()` flow

### ⚙️ Configuration
- New `impact` config section: `zeroboiler.analytics.impact.cache_ttl` (default: 300s)
- New `provider_intelligence` config section: `zeroboiler.analytics.provider_intelligence.cache_ttl` (default: 300s)

### Changed
- **Version sweep** — 9.5.0 → 9.6.0 across composer.json, package.json, AnalyticsEvent::VERSION, JS client (getVersion + _getInternalVersion), Svelte composable, TypeScript definitions, ServiceProvider, IntegrityCommand EXPECTED_VERSION, README badge.

---

### What's New in v9.4.0

### 🔄 Provider Fallback Service (`ProviderFallbackService`)
- **Multi-provider failover strategy** — When a primary analytics provider fails (circuit breaker opens), events are automatically redirected to configured fallback providers. Ordered chain evaluation — first healthy provider receives the event.
- **Config-driven fallback chains** — Define fallback chains per provider in `zeroboiler.analytics.fallback.chains`
  - Example: `'ga4' => ['gtm', 'meta', 'posthog']` — if GA4 is down, fall back to GTM, then Meta CAPI, then PostHog
- **Circuit breaker integration** — Uses `ProviderCircuitBreaker` states to determine provider health
- **Fallback tracking** — Per-chain fallback counters persisted in cache for monitoring and alerting
- **Validation API** — Detects circular dependencies, invalid providers, and excessive chain depth
- **API endpoints:**
  - `GET /api/analytics/fallback` — Fallback statistics (enabled, chains, counts, max depth)
  - `GET /api/analytics/fallback/chains` — All configured fallback chains
  - `GET /api/analytics/fallback/validate` — Validate chain configuration (circular deps, depth)
  - `GET /api/analytics/fallback/health` — Per-provider health with fallback status
  - `POST /api/analytics/fallback/reset-counts` — Reset fallback counters

### 🏭 Event Catalog Factory (`EventCatalogFactory`)
- **Catalog-aware event creation** — Fluent factory for creating AnalyticsEvent DTOs with catalog validation
- **Static convenience methods** — `create()`, `raw()`, `event()`, `critical()` for common patterns
- **Category-aware helpers** — `ecommerceEventNames()`, `saasEventNames()`, `engagementEventNames()`
- **Provider name resolution** — `getGa4Name()`, `getMetaName()` from catalog entries

### 📡 AnalyticsEvent Source Tracking
- **New `source` field** on `AnalyticsEvent` DTO — Tracks event origin (api|server|client|webhook|replay|batch)
- **fromArray() and toArray() support** — Source field properly serialized and deserialized
- **Priority in toArray()** — Priority field now included in array output

### Config: `fallback` section
- New `zeroboiler.analytics.fallback` with enabled flag, max chain depth, cache prefix, and per-provider chains.

### What's New in v9.3.0

### 🛡️ Event Idempotency Service (`EventIdempotencyService`)
- **Server-side deduplication** — Prevents duplicate analytics event dispatches using idempotency keys
  - SHA-256 fingerprinting of event name + client ID + user ID + params content hash
  - Client-supplied idempotency keys take priority (for retry-safe frontend dispatch)
  - Cache-backed O(1) lookup with configurable TTL (default: 1 hour)
  - Hit/miss statistics tracking with duplicate rate calculation
  - Key invalidation support for manual re-dispatch
  - Static `generateClientKey()` helper for frontend idempotency key generation
- **API endpoints:**
  - `GET /api/analytics/idempotency` — Stats (enabled, TTL, hits, misses, duplicate rate)
  - `POST /api/analytics/idempotency/invalidate` — Invalidate a specific idempotency key
  - `POST /api/analytics/idempotency/reset-stats` — Reset hit/miss counters

### 🔒 Privacy Manifest Service (`PrivacyManifestService`) — GDPR Article 30
- **Automated Records of Processing Activities (RoPA)** generation
  - All registered catalog events classified into GDPR data categories (identifier, behavioral, financial, technical, contractual, legal, statistical, transactional)
  - Legal basis mapping per event category (consent, contractual necessity, legitimate interest)
  - Retention period defaults per category (financial: 7 years, PII: 3 years, behavioral: 90 days)
  - Third-party data flow documentation (GA4, GTM, Meta Pixel, PostHog, Plausible)
  - Data subject rights implementation status (access, erasure, portability, objection)
  - Cross-border data transfer assessment (SCCs, adequacy decisions)
  - Cache-backed manifest generation for dashboard queries
- **API endpoints:**
  - `GET /api/analytics/privacy-manifest` — Full GDPR Article 30 manifest
  - `GET /api/analytics/privacy-manifest/summary` — Dashboard-ready summary
  - `GET /api/analytics/privacy-manifest/classify/{eventName}` — Per-event classification
  - `POST /api/analytics/privacy-manifest/invalidate` — Clear cached manifest

### 🏷️ Event Annotation Service (`EventAnnotationService`)
- **Deployment markers and event tagging** — Attach metadata to analytics events
  - Annotation types: deployment, debug, experiment, release, custom
  - Auto-attach annotations from config (deployment version, environment, debug flag, release tag)
  - Cache-backed storage with configurable max annotations per event (default: 20)
  - Per-annotation CRUD (create, read, update, remove, clear all)
  - Useful for deployment correlation analysis, A/B rollout tracking, and debug tracing
- **API endpoints:**
  - `GET /api/analytics/annotations/stats` — Service statistics
  - `POST /api/analytics/annotations` — Attach annotation to event
  - `POST /api/analytics/annotations/auto-attach` — Trigger auto-attach from config
  - `GET /api/analytics/annotations/{eventId}` — Get all annotations for event
  - `DELETE /api/analytics/annotations/{eventId}` — Clear all annotations
  - `DELETE /api/analytics/annotations/{eventId}/{key}` — Remove specific annotation

### ⚙️ Configuration
- New `idempotency` config section (enabled, TTL, max keys, prefix)
- New `privacy_manifest` config section (cache TTL, controller/DPO email, legal basis defaults, retention defaults)
- New `annotations` config section (cache TTL, max per event, auto-attach toggles)

### Changed
- **Version sweep** — 9.2.0 → 9.3.0 across composer.json, package.json, AnalyticsEvent::VERSION, JS client (getVersion + _getInternalVersion), Svelte composable, TypeScript definitions, ServiceProvider, README badge.

---

### What's New in v9.2.0

### 🧠 SaaS Lifecycle Observer
- **`SaaSLifecycleObserver`** — Real-time SaaS health monitoring service
  - Tracks **trial activation score** (0-100) with weighted step progression: trial_start → login → feature_used → subscription → trial_converted
  - Monitors **churn risk indicators** with weighted scoring: billing_retry (35), feature_limit_reached (20), reduced_usage (30), with diminishing returns for repeat signals
  - Computes **expansion revenue momentum** from plan_upgrades, expansion_revenue events, and subscription renewals
  - Tracks **feature adoption depth** — unique features used per identity
  - Tracks **session engagement** — login frequency, avg sessions/day, stickiness proxy
  - **Conversion funnel progress** — tracks position through the SaaS signup funnel (7 stages)
  - GDPR-compliant: `forget()` clears all cached signals for an identity
  - Aggregate metrics API for admin dashboards

### 📊 Analytics Readiness Score
- **`AnalyticsReadinessScoreService`** — Comprehensive 8-dimension self-assessment
  - Scores your analytics setup from 0-100 across:
    - **Provider Configuration** (15 pts) — at least 1 provider enabled
    - **Event Catalog Coverage** (15 pts) — core SaaS, engagement, and revenue events
    - **Identity Tracking** (10 pts) — client ID cookie + auto-link on auth
    - **Consent Compliance** (10 pts) — GDPR default + granular purposes
    - **Queue Infrastructure** (15 pts) — async dispatch + dedicated connection
    - **E-commerce Tracking** (10 pts) — currency + format converter
    - **SaaS Lifecycle Tracking** (15 pts) — auto-track + lifecycle mapper
    - **Client-Side Integration** (10 pts) — API enabled + Inertia middleware
  - Returns letter grade (A+ through F)
  - Actionable recommendations sorted by priority (critical/high/medium/low)
  - `isReady()` quick check — true if score >= 60

### ⚙️ Configuration
- New `lifecycle_observer` config section (cache TTL)
- New `readiness_score` config section (passing threshold)

### 🧪 Testing
- 20+ new test cases covering `SaaSLifecycleObserver` and `AnalyticsReadinessScoreService`

---

### What's New in v9.1.0

### 🏗️ Architecture: AnalyticsServiceRegistry
- New `AnalyticsServiceRegistry` — lightweight service locator for the analytics controller
- Reduces controller constructor complexity from 80+ nullable parameters to container-based lazy resolution
- Fully testable: bind mock services into the container before resolving the controller

### 🛤️ API: Guard Rails Endpoints
- `POST /api/analytics/guard-rails/check` — validate event quality against guard rails rules
- `GET /api/analytics/guard-rails/score` — overall tracking quality score
- `GET /api/analytics/guard-rails/violations` — list active guard rails violations
- `GET /api/analytics/guard-rails/coverage` — guard rails coverage analysis
- `POST /api/analytics/guard-rails/validate-name` — validate a single event name against rules

### 📡 API: SSE (Server-Sent Events) Routes
- `GET /api/analytics/sse` — real-time analytics event streaming via SSE
- `GET /api/analytics/sse/info` — SSE endpoint metadata and capabilities
- `GET /api/analytics/sse/health` — SSE buffer health check
- Supports cursor-based resume, event name filtering, category filtering, and provider filtering

### What's New in v9.0.0

### Event Delivery Confirmation System

Industry-standard event delivery monitoring inspired by Segment's delivery confirmation, Mixpanel's event verification, and Amplitude's event monitoring dashboard.

**Core Features:**
- **Delivery Receipt Tracking** — Per-event delivery confirmation with unique event IDs. Query whether a specific event was delivered to all enabled providers.
- **Reliability Scoring** — Composite delivery reliability score (0-100) with A-F grading. Computed from success/failure ratios with consecutive failure penalties.
- **Response Time Percentiles** — Per-provider p50, p95, p99 response time measurement for latency monitoring.
- **Outage Detection** — Automatic provider outage detection when consecutive failures exceed configurable threshold. Alert logging on detection.
- **SLA Monitoring** — Configurable SLA target (default: 99.5%). Real-time SLA compliance tracking across all providers.
- **Recent Delivery History** — Per-provider delivery audit trail (last 500 events) for debugging delivery issues.

**API Endpoints:**
- `GET /api/analytics/delivery` — Full delivery dashboard (reliability, per-provider health, response times)
- `GET /api/analytics/delivery/score` — Reliability score with grading
- `GET /api/analytics/delivery/receipt/{eventId}` — Check delivery receipt for a specific event
- `GET /api/analytics/delivery/{provider}/response-times` — Response time percentiles
- `GET /api/analytics/delivery/{provider}/recent` — Recent delivery history
- `GET /api/analytics/delivery/{provider}/outage` — Outage status check
- `DELETE /api/analytics/delivery` — Clear stats (optional `?provider=ga4`)

**Artisan Command:**
```bash
php artisan zb:analytics:delivery              # Full dashboard
php artisan zb:analytics:delivery --json      # JSON output
php artisan zb:analytics:delivery --provider=ga4  # Specific provider
php artisan zb:analytics:delivery --receipt=event-uuid  # Check receipt
php artisan zb:analytics:delivery --clear      # Clear stats
```

**Configuration:**
```env
ANALYTICS_DELIVERY_CONFIRMATION_ENABLED=true
ANALYTICS_DELIVERY_CONFIRMATION_CACHE_TTL=3600
ANALYTICS_DELIVERY_CONFIRMATION_RETENTION=86400
ANALYTICS_DELIVERY_CONFIRMATION_OUTAGE_THRESHOLD=10
ANALYTICS_DELIVERY_CONFIRMATION_SLA_TARGET=99.5
```

### What's New in v8.9.0

### Analytics Guard Rails Engine

Industry-standard tracking quality monitoring system inspired by Amplitude Compass, Mixpanel Data Governance, and Segment Protocols. Provides a composite quality score (0-100) across 6 dimensions with actionable recommendations.

**Dimensions:**
- **Schema Compliance (25%)** — % of tracked events registered in the event catalog
- **Naming Convention (20%)** — % of events following snake_case naming convention
- **Coverage Completeness (20%)** — % of core SaaS lifecycle events tracked
- **Provider Coverage (15%)** — % of analytics providers receiving events
- **Identity Linking (10%)** — client ID ↔ user ID linking rate and configuration
- **Consent Compliance (10%)** — GDPR consent mode defaults and logging status

**Key features:**
- **Composite scoring** — Weighted average with configurable dimension weights
- **A-F grading** — A (90+), B (75+), C (60+), D (40+), F (<40)
- **Violation detection** — Critical, warning, and info severity levels
- **Event name validation** — `validateEventName()` with auto-suggestion
- **Core event coverage** — Tracks 11 essential SaaS lifecycle events
- **Ramp-up protection** — Minimum events threshold before full assessment
- **Cache-backed** — Configurable TTL for repeated checks
- **Admin command** — `zb:analytics:guard-rails --json --violations --quick --clear-cache`

**API endpoints:**
```
GET  /analytics/guard-rails                    — Full guard rails report
GET  /analytics/guard-rails/score              — Quick quality score
GET  /analytics/guard-rails/violations        — Violations (filter by severity)
GET  /analytics/guard-rails/coverage           — Core event coverage
GET  /analytics/guard-rails/validate-name       — Validate event name
```

**Configuration:**
```php
// config/zeroboiler.php
'guard_rails' => [
    'enabled' => env('ANALYTICS_GUARD_RAILS_ENABLED', true),
    'cache_ttl' => (int) env('ANALYTICS_GUARD_RAILS_CACHE_TTL', 300),
    'minimum_events' => (int) env('ANALYTICS_GUARD_RAILS_MIN_EVENTS', 100),
],
```

### What's New in v8.8.0

### Event Correlation Heatmap Service

Industry-standard event correlation analysis using Jaccard similarity coefficients. Computes a pairwise correlation matrix across tracked events within user sessions, producing structured data for dashboard heatmap chart rendering (D3, Chart.js, Recharts).

**Key capabilities:**
- **Jaccard similarity matrix** — Normalized co-occurrence scoring that prevents high-volume events (page_view) from dominating
- **Top correlations API** — `getTopCorrelations(20)` returns the strongest event pairs for "Events frequently done together" widgets
- **Per-event correlations** — `getEventCorrelations('purchase')` returns all events correlated with a specific event
- **Chart data export** — `getChartData()` produces flat `{source, target, value}` triples for chord diagrams and force graphs
- **Configurable filtering** — Exclude noisy events (page_view, scroll_depth) and set Jaccard thresholds per deployment
- **Co-occurrence recording** — `recordCoOccurrence()` tracks session-scoped event pairs with automatic deduplication
- **Statistics API** — Average/median/max correlation, strong pair count for dashboard KPI tiles

### Health Monitor Dashboard Service

Unified health monitoring for the entire analytics stack. Aggregates health data from 6 subsystems into a single composite score (0-100) with A-F grading and per-dimension breakdowns.

**Dimensions:**
- **Provider Connectivity (25%)** — GA4, GTM, Meta Pixel, Plausible, PostHog, Webhook reachability
- **Queue Health (20%)** — Queue config, DLQ, replay enabled, sync vs async
- **Config Integrity (20%)** — Consent defaults, debug mode, dedup, PII sanitization, GDPR IP anonymization
- **Pipeline Health (15%)** — UTM auto-attach, metadata enrichment, dedup, debounce
- **Consent Coverage (10%)** — GDPR-safe defaults, consent logging, purpose definitions
- **Rate Limiting (10%)** — API throttle, guard enabled, provider rate limits

**Key features:**
- **Composite scoring** — Weighted average with configurable dimension weights
- **A-F grading** — A (90+), B (80+), C (70+), D (60+), F (<60)
- **Alert generation** — Critical (<50) and warning (<70) alerts per dimension
- **Time-series history** — Record periodic data points for trend chart rendering
- **Admin command** — `zb:analytics:health-monitor --json --record --history`

### What's New in v8.7.0

### Cross-Device Identity Graph

Industry-standard identity resolution for multi-device user stitching. The new `IdentityGraphService` builds a graph of identity relationships between client IDs, user IDs, device fingerprints, and session IDs — enabling cross-device correlation of anonymous and authenticated user behavior.

**Key capabilities:**
- **Explicit linking** — Login/register creates 1.0 confidence links (client ↔ user ↔ device)
- **Inferred stitching** — Unknown clients linked to known users via shared device fingerprint (0.8 confidence)
- **Same-user detection** — `areSameUser(clientA, clientB)` checks graph connectivity
- **User graph merging** — Merge identity graphs when users link accounts
- **Event enrichment** — Auto-enrich events with `_identity_user_id`, `_identity_device_id`, `_identity_confidence`

### Device Fingerprinting

New `DeviceFingerprintService` generates server-side SHA-256 fingerprints from HTTP request headers (User-Agent, Accept-Language, Sec-CH-Platform). GDPR-safe by default — no IP address included. Used automatically by `IdentityGraphService` for cross-device matching.

### API Endpoints

```
GET  /api/analytics/identity-graph/user/{userId}    — Get full identity graph
POST /api/analytics/identity-graph/link             — Explicit client→user link
POST /api/analytics/identity-graph/infer            — Infer identity from device
POST /api/analytics/identity-graph/merge            — Merge user graphs
POST /api/analytics/identity-graph/same-user        — Cross-device stitching check
GET  /api/analytics/identity-graph/fingerprint      — Generate device fingerprint
```

### Configuration

```php
// config/zeroboiler.php
'identity_graph' => [
    'enabled' => env('ANALYTICS_IDENTITY_GRAPH_ENABLED', true),
    'min_confidence_stitching' => 0.5,
    'min_confidence_merge' => 0.9,
],
'device_fingerprint' => [
    'enabled' => env('ANALYTICS_DEVICE_FINGERPRINT_ENABLED', true),
    'include_ip' => false, // GDPR-safe default
],
```

### What's New in v8.6.0

### High-Level E-Commerce Shorthands

New convenience methods on the `Analytics` facade for common SaaS e-commerce events — zero-config tracking with sensible defaults:

- **`trackPurchase()`** — Order completed, revenue tracked
- **`trackRefund()`** — Refund processed, revenue reversed
- **`trackViewItem()`** — Product detail page viewed
- **`trackAddToCart()`** — Item added to shopping cart
- **`trackRemoveFromCart()`** — Item removed from shopping cart
- **`trackBeginCheckout()`** — Checkout flow initiated

All shorthands automatically dispatch to all active providers (GA4, Meta Pixel, PostHog, Plausible) with provider-specific parameter mapping via `EcommerceFormatConverter`.

### Dual-Provider E-Commerce Push

PostHog and Plausible providers now support full e-commerce event parameter conversion — items, currency, value, transaction_id, and coupon are properly mapped for both providers simultaneously.

### Bug Fixes

- Fixed duplicate `initInertiaPageViewTracker` declaration in JS client
- Fixed `getCookie()` helper duplication
- Fixed Bearer token template literal in streaming endpoint
- Full version sweep to 8.6.0 across all entry points (composer.json, package.json, AnalyticsEvent::VERSION, JS client, Svelte composable, TypeScript definitions, ServiceProvider, README badge)

### What's New in v8.5.0

### Event Context Snapshot & User Journey Reconstruction

Two new services for advanced SaaS analytics observability and user journey analysis:

**EventContextSnapshotService** — Captures point-in-time context snapshots for every dispatched event:
- Device fingerprint, browser, OS, and type detection
- Session state (hashed session ID, path, referrer)
- Geographic context (country, locale) with GDPR-compliant IP anonymization (IPv4/IPv6)
- Behavioral velocity scoring (events/minute → low/normal/elevated/high)
- Engagement signal classification (passive/active/engaged/power_user)
- Consent state snapshot for compliance audit trails
- Cache-backed storage with FIFO eviction per client
- Snapshot replay for post-hoc event re-dispatch
- GDPR erasure: `forgetClientSnapshots()` removes all snapshots for a client

```php
use ZeroBoiler\Analytics\DTO\EventContext;
use ZeroBoiler\Analytics\Services\EventContextSnapshotService;

$snapshotService = app(EventContextSnapshotService::class);

$context = EventContext::fromRequest($request, 'zb_analytics_id');
$snapshot = $snapshotService->capture($context, 'purchase', $eventsPerMinute);

// Replay from snapshot for re-dispatch
$replayPayload = $snapshotService->replayFromSnapshot($snapshot['snapshot_id']);

// GDPR erasure
$snapshotService->forgetClientSnapshots('client_abc123');
```

**UserJourneyReconstructionService** — Reconstructs complete user journeys with funnel analysis:
- Automatic journey recording with step-by-step event tracking
- Journey finalization (on logout/session end) with duration calculation
- Cache-backed persistence for cross-request journey reconstruction
- Funnel completion analysis: `analyzeFunnelProgress(['sign_up', 'start_trial', 'subscribe'])`
- Time-to-convert metrics per funnel step
- Sensitive parameter sanitization (passwords, tokens, API keys stripped)
- Max steps/journey and max journeys/user limits with FIFO eviction
- GDPR erasure: `eraseUser()` removes all journey data

```php
use ZeroBoiler\Analytics\Services\UserJourneyReconstructionService;

$journeyService = app(UserJourneyReconstructionService::class);

// Record steps
$journeyService->recordStep($event, $userId, $clientId, $sessionId);

// Analyze signup → trial → subscribe funnel
$analysis = $journeyService->analyzeFunnelProgress(
    ['sign_up', 'start_trial', 'subscribe'],
    $userId,
);
// => ['completed_steps' => 3, 'completion_rate' => 100.0, 'next_expected' => null]

// Finalize on logout
$journeyService->finalizeJourney($userId);

// GDPR erasure
$journeyService->eraseUser($userId);
```

**analytics:snapshot Command** — New admin command for instant system health overview:
```bash
php artisan analytics:snapshot                          # Full system overview
php artisan analytics:snapshot --identity=user_123      # Specific user analysis
php artisan analytics:snapshot --include-catalog         # Full event catalog dump
php artisan analytics:snapshot --format=json             # JSON output
```
Reports: configuration overview, event catalog coverage & validation, provider status, identity resolution state, correlation & pattern detection, and optional full event catalog.

**Configuration** — Two new config sections in `config/zeroboiler.php`:
```php
'context_snapshot' => [
    'enabled' => env('ANALYTICS_CONTEXT_SNAPSHOT_ENABLED', true),
    'snapshot_ttl' => 86400,           // 24 hours
    'max_snapshots_per_client' => 100,
],

'journey_reconstruction' => [
    'enabled' => env('ANALYTICS_JOURNEY_RECONSTRUCTION_ENABLED', true),
    'cache_ttl' => 86400,               // 24 hours
    'max_journeys_per_user' => 20,
    'max_steps_per_journey' => 200,
],
```

**Tests** — 24 new tests in `V850ContextSnapshotAndJourneyReconstructionTest.php` covering snapshot capture, IP anonymization, behavioral scoring, journey recording/finalization, funnel analysis, parameter sanitization, GDPR erasure, and cross-service integration.

### What's New in v8.4.0

### Event Schema Validation Engine + Bot Detection Service

**EventSchemaValidationService** — Runtime validation of analytics event parameters against the typed schema definitions in `EventParameterSchemas`. Performs type checking, automatic type coercion (e.g., string `"42"` → int `42`), required parameter enforcement, and optional unknown parameter stripping. Four severity levels: `reject`, `coerce`, `warn`, `off`.

```php
use ZeroBoiler\Analytics\Services\EventSchemaValidationService;

$service = app(EventSchemaValidationService::class);

// Validate a single event
$event = new AnalyticsEvent('purchase', ['transaction_id' => 'txn_1', 'currency' => 'USD', 'value' => '29.99']);
$result = $service->validate($event);
// $result['valid']    // true (value auto-coerced from string to float)
// $result['coerced']  // true
// $result['errors']   // []
// $result['event']    // The coerced AnalyticsEvent

// Validate a batch
$batchResult = $service->validateBatch([$event1, $event2, $event3]);
// $batchResult['total'], $batchResult['valid'], $batchResult['rejected']

// Coverage stats
$coverage = $service->getCoverageStats();
// $coverage['total_schemas'], $coverage['catalog_size'], $coverage['coverage_percent']
```

**Config:** `zeroboiler.analytics.schema_validation` with `enabled`, `severity`, `strip_unknown`.

---

**BotDetectionService** — Automated bot detection for analytics API endpoints. Analyzes incoming requests using four signal layers: user-agent pattern matching (15+ known bot patterns), client ID rotation detection (multiple IDs from same IP), request velocity anomaly (burst submissions), and HTTP header completeness scoring. Produces a composite risk score (0-100) with configurable rejection threshold.

```php
use ZeroBoiler\Analytics\Services\BotDetectionService;

$service = app(BotDetectionService::class);

$result = $service->analyze($request, $clientId);
// $result['score']     // 0-100 composite risk score
// $result['is_bot']   // true if score >= threshold
// $result['signals']  // Per-layer breakdown (user_agent, client_rotation, velocity, header_score)
// $result['details']   // Human-readable detection details

// Stats
$stats = $service->getStats();
// $stats['total'], $stats['bot'], $stats['human'], $stats['avg_score'], $stats['bot_rate']
```

**Config:** `zeroboiler.analytics.bot_detection` with `enabled`, `risk_threshold`, `reject_on_bot`, `max_client_ids_per_ip`, `velocity_burst`, `velocity_window`, `bot_ua_patterns`.

Inspired by Cloudflare Bot Management, FingerprintJS, Segment Protocols, and PostHog event validation.

**Version sweep** — 8.3.0 → 8.4.0 across composer.json, package.json, AnalyticsEvent::VERSION, JS client (getVersion + _getInternalVersion), Svelte composable, TypeScript definitions, ServiceProvider.

### What's New in v8.3.0

### Dashboard Widget Service + npm Package Configuration

**DashboardWidgetService** — Pre-computed, cache-backed dashboard data widgets for instant SaaS analytics dashboard rendering. Returns all dashboard widget data in a single API call with sub-100ms response times from cache. Widgets are lazily computed on first access and cached with configurable TTL (default 5 minutes). Designed for headless admin panels, Svelte/Vue/React dashboards, and server-rendered analytics views.

Eight built-in widgets:
- **overview** — Event count, catalog size, success rate, version
- **events_top** — Top N events by frequency with percentage breakdown
- **events_timeline** — Time-series event counts (hourly buckets)
- **revenue_summary** — Revenue event counts, currency, top revenue events
- **saas_funnel** — Signup → Trial → Conversion funnel with step-by-step conversion rates
- **engagement** — Session metrics, engagement rate, unique event types
- **providers** — Per-provider dispatch/failure counts and success rates
- **ecommerce** — Purchase funnel (view_item → add_to_cart → purchase)

```php
use ZeroBoiler\Analytics\Services\DashboardWidgetService;

$service = app(DashboardWidgetService::class);

// Get all widgets in one call
$dashboard = $service->allWidgets();
// $dashboard['widgets']['overview']['total_events']
// $dashboard['widgets']['saas_funnel']['steps']

// Get a specific widget
$funnel = $service->getWidget('saas_funnel');

// Invalidate cache on new events
$service->invalidateAll();
$service->invalidateWidget('overview');

// Get service statistics
$stats = $service->stats();
```

**API Endpoints:**
- `GET /api/analytics/dashboard/widgets` — All enabled widgets
- `GET /api/analytics/dashboard/widgets/{widgetName}` — Single widget
- `POST /api/analytics/dashboard/widgets/invalidate` — Cache invalidation

**Config: `dashboard_widgets` section** — `enabled`, `cache_ttl`, `max_top_events`, `timeline_points`, `widgets` (null = all)

**package.json** — Added npm package configuration for the JS client library with proper `exports` map, `types` declarations, and Svelte composable support. Enables future standalone npm publishing.

**Version sweep** — 8.2.0 → 8.3.0 across composer.json, AnalyticsEvent::VERSION, JS client (getVersion + header), Svelte composable, TypeScript definitions.

### What's New in v8.2.0

### Event Fingerprinting & SSE Provider Filtering

**EventFingerprintService** — Content-addressed event identity for deduplication and replay identification. Computes stable SHA-256 hashes based on event name, sorted parameter signature, client/user identity, and a configurable time bucket. Events sharing a fingerprint within the TTL window are treated as duplicates. Supports configurable time granularity (second/minute/hour/day), param exclusion modes, and atomic check-and-mark operations. Inspired by Segment's messageId, RudderStack's batchId, and Mixpanel's event dedup fingerprinting.

```php
use ZeroBoiler\Analytics\Services\EventFingerprintService;

$service = app(EventFingerprintService::class);

// Compute fingerprint for an event
$event = new AnalyticsEvent('purchase', ['value' => 29.99], 'client_1', 'user_1');
$fp = $service->fingerprint($event); // 64-char SHA-256 hex string

// Atomic dedup check: returns is_duplicate + fingerprint
$result = $service->checkAndMark($event);
// $result['is_duplicate'] // false on first call, true on subsequent
// $result['fingerprint']  // the SHA-256 hash

// Batch fingerprinting
$batchFp = $service->batchFingerprint([$event1, $event2]);
$service->markBatchSeen([$event1, $event2]);
$seen = $service->hasSeenBatch([$event1, $event2]); // true
```

**SSE Provider Filtering** — The SSE streaming endpoint now supports a `provider` query parameter for filtering events by provider mapping. Connect to `/api/analytics/sse?provider=ga4` to receive only events that have a GA4 mapping, or `?provider=meta` for Meta Pixel-only events. Also supports `category` (ecommerce|saas|engagement) and `filter` (wildcard event name) simultaneously.

**Config: `fingerprint` section** — New `zeroboiler.analytics.fingerprint` with `enabled`, `cache_prefix`, `ttl`, `time_bucket`, `exclude_timestamp`, `exclude_params` settings.

**Version sweep** — 8.0.0/8.1.0 → 8.2.0 across composer.json, AnalyticsEvent::VERSION, JS client (getVersion + header), Svelte composable, TypeScript definitions, ServiceProvider, README badge.

### What's New in v8.0.0

### Session Analytics & Funnel Aggregation — Industry-Standard SaaS Dashboard Engine

**EventSessionizer** — Session-aware event aggregation for real-time SaaS dashboards. Groups events by client ID + session ID and computes per-session metrics: event counts, unique events, session duration estimation, engagement scoring (0-100), and conversion detection. Uses cache-backed ring buffers with automatic TTL expiry. Supports session indexing, client aggregation stats, and explicit session termination. Inspired by Amplitude Session Explorer and Mixpanel Session Replay.

- **EventSessionizer** — `record(AnalyticsEvent)` for session-context event recording. Returns `session_id`, `event_count`, `unique_events`, `duration_estimate`, `engagement_score`. `getClientSessions()` for per-client session listing. `aggregateStats()` for dashboard-ready summary (total sessions, avg events, conversion rate, avg engagement). `endSession()` for explicit session termination.
- **EventFunnelAggregator** — Automated funnel completion tracking across sessions. Five built-in funnels (signup, activation, purchase, subscription, expansion) with configurable custom funnels. `record()` tracks progress through funnel steps with conversion detection. `getFunnelReport()` returns step-by-step conversion rates, drop-off rates, and cumulative rates. `getAllFunnelReports()` for dashboard overview.
- **EventClassificationEnricher** — Pipeline stage that auto-enriches events with catalog metadata: `_zb_category`, `_zb_provider_map`, `_zb_event_class`, `_zb_priority`. Priority inference for custom events using name pattern heuristics. Supports batch enrichment.
- **AnalyticsReportCommand** — Scheduled report generator (`php artisan analytics:report`) with sections: health, catalog, funnels, sessions, saas. Supports `--format=json` and `--section=` for targeted reporting. Designed for cron-based delivery.
- **API Endpoints** — `GET /api/analytics/sessions/{clientId}`, `GET /sessions/{clientId}/{sessionId}`, `GET /sessions/{clientId}/stats`, `POST /sessions/end/{clientId}/{sessionId}`, `GET /funnels/aggregated/{funnelName}`, `GET /funnels/aggregated`, `GET /funnels/definitions`.
- **Config** — `sessionizer` section (session_ttl, max_sessions_per_client, cache_prefix). `funnel_definitions` for custom funnels. `classification` toggle for auto-enrichment.

### What's New in v7.9.0

### Multi-Touch Attribution & Feature Matrix Benchmark

**AttributionModelService** — Industry-standard multi-touch attribution modeling for marketing analytics. Computes weighted credit across touchpoints using five models: first-touch, last-touch, linear, time-decay, and position-based (U-shaped). Supports channel aggregation, campaign aggregation, and ROAS/CPA efficiency metrics.

- **AttributionModelService** — Five attribution models (first_touch, last_touch, linear, time_decay, position_based). Validates models, computes per-touchpoint credit with percentage breakdowns. `compareModels()` for side-by-side model comparison. `aggregateByChannel()` and `aggregateByCampaign()` for multi-journey aggregation. `channelEfficiency()` for ROAS/CPA calculations.
- **SaaSFeatureMatrixService** — Feature parity benchmarking against industry platforms (Segment, Mixpanel, Amplitude, PostHog, Matomo, Plausible). 70+ feature checks across 13 categories. `buildMatrix()` for full coverage analysis with gap identification. `coverageSummary()` with letter grade. `compareWith()` for per-competitor advantage/disadvantage analysis.
- **Config: `attribution_model` section** — Default model, decay factor, enabled models, cache TTL.
- **Config: `feature_matrix` section** — Enable/disable feature matrix endpoints, cache TTL.
- **10 new API endpoints** — Attribution (models, attribute, compare, by-channel, by-campaign, efficiency) and Feature Matrix (matrix, summary, gaps, compare/{competitor}).
- **Version sweep** — 7.8.0 → 7.9.0 across composer.json, AnalyticsEvent::VERSION, JS client, Svelte composable, TypeScript definitions, README badge.

### What's New in v7.8.0

### Event Plugin Registry & Integrity Check

Third-party Laravel packages can now register their analytics events with the ZeroBoiler event catalog at runtime via the `EventPluginRegistry`. Plugin events merge into the catalog via `EventCatalog::allWithPlugins()` without conflicting with built-in events (built-in wins on name collision).

- **EventPluginRegistry** — Config-driven and runtime plugin registration with validation. Accepts manifests with `package`, `version`, `events` (name, class, ga4, meta, category), and `priority`. Supports `registerPlugin()`, `validate()`, `summary()`, `eventsByPlugin()`, `eventsByCategory()`, `unregisterPlugin()`.
- **EventCatalog::allWithPlugins()** — New static method that merges plugin-registered events into the built-in catalog. Built-in events take precedence on name conflicts.
- **AnalyticsIntegrityCommand** — New `zb:analytics:integrity` artisan command. Validates version consistency across all entry points (composer.json, DTO VERSION, JS/Svelte/d.ts), event catalog completeness (core SaaS lifecycle, ecommerce, engagement events), config integrity (consent, auto-track, queue, providers), and plugin registry health (validation, name conflicts). Supports `--json`, `--verbose`, `--fix` flags. Designed for CI pipelines.
- **Config: `event_plugins` section** — New `zeroboiler.analytics.event_plugins` with `enabled`, `debug`, `plugins` settings.
- **Version sweep** — 7.6.0/7.7.0 → 7.8.0 across composer.json, AnalyticsEvent::VERSION, JS client, Svelte composable, TypeScript definitions, ServiceProvider, README badge.

### What's New in v7.7.0

### Event Signal Intelligence

Pipeline observability layer — monitors event dispatch patterns across all providers, detects anomalies (staleness, high failure rates, dispatch rate spikes), computes signal-to-noise ratio, and provides dispatch balance scoring. Inspired by Datadog Signal Intelligence and Honeycomb BubbleUp.

```php
use ZeroBoiler\Analytics\Services\EventSignalIntelligenceService;

$service = app(EventSignalIntelligenceService::class);
$report = $service->report();

// $report['signal_score']    // 0-100 composite score
// $report['grade']           // A+ through F- grade label
// $report['providers']       // Per-provider health signals
// $report['anomalies']       // Detected anomalies (critical/warning/info)
// $report['signal_to_noise'] // 0.0-1.0 catalog coverage ratio
// $report['dispatch_balance']// 0-100 entropy-based provider balance
```

**Artisan Command**: `php artisan analytics:signal [--json] [--anomalies-only] [--providers-only]`

**API Endpoints**:
- `GET /api/analytics/signal` — Full intelligence report
- `GET /api/analytics/signal/score` — Composite signal score
- `GET /api/analytics/signal/anomalies` — Detected anomalies
- `GET /api/analytics/signal/providers` — Provider health signals
- `GET /api/analytics/signal/staleness` — Staleness summary

**Config**: `zeroboiler.analytics.signal_intelligence`

### What's New in v7.6.0

### Cohort Waterfall Analysis

Revenue flow decomposition by cohort period — visualize how users flow through signup → trial → conversion → active → churn with per-stage drop-off rates, NRR computation, and actionable insights.

```php
use ZeroBoiler\Analytics\Services\CohortWaterfallService;

$service = app(CohortWaterfallService::class);

$report = $service->report([
    'cohorts' => [
        '2026-08' => [
            'entered' => 1000, 'trial_starts' => 800,
            'conversions' => 400, 'active' => 350,
            'expansions' => 5000.0, 'contractions' => 1000.0,
            'churned' => 50, 'churned_mrr' => 2500.0, 'mrr' => 40000.0,
        ],
    ],
]);

// $report['cohorts']['2026-08']['stages']['trial_converted']['drop_off_rate'] // 50.0
// $report['summary']['nrr'] // Net Revenue Retention percentage
// $report['insights'] // Actionable recommendations
```

**API**: `POST /api/analytics/cohort-waterfall`, `/summary`, `/compare` · `GET /stages`

### Funnel Drop-off Intelligence

Smart funnel analysis with automatic bottleneck detection (low/moderate/high/critical), anomaly detection (sudden drop-off spikes), time-based UX recommendations, and period-over-period comparison.

```php
use ZeroBoiler\Analytics\Services\FunnelDropoffIntelligenceService;

$service = app(FunnelDropoffIntelligenceService::class);

$analysis = $service->analyze(
    ['landing', 'signup', 'trial', 'subscribe', 'active'],
    ['step_counts' => ['landing' => 10000, 'signup' => 3000, ...]],
);

// $analysis['bottlenecks'][0]['severity'] // 'critical'
// $analysis['anomalies'][0]['spike_multiplier'] // 3.5x
// $analysis['recommendations'] // Actionable UX improvements
```

**API**: `POST /api/analytics/funnel-intelligence`, `/compare`

### What's New in v7.4.0

### PostHog CAPI (Server-Side Conversions API)

New PostHog server-side event builders for product analytics via the Conversions API. Generates PostHog-optimized item structures with `$currency` properties, compatible with PostHog's product analytics and revenue tracking features.

```php
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;

// View item via PostHog CAPI
$event = EcommerceFormatConverter::buildPosthogViewItemEvent(
    ['item_id' => 'SKU-1', 'item_name' => 'Widget', 'price' => 29.99],
    'USD',
);
Analytics::trackEvent($event);

// Add to cart via PostHog CAPI
$event = EcommerceFormatConverter::buildPosthogAddToCartEvent(
    ['item_id' => 'SKU-2', 'item_name' => 'Gadget', 'price' => 19.99, 'quantity' => 2],
);
Analytics::trackEvent($event);

// Begin checkout with coupon support
$params = EcommerceFormatConverter::buildPosthogBeginCheckout(
    [['item_id' => 'SKU-1', 'item_name' => 'Widget', 'price' => 29.99, 'quantity' => 1]],
    29.99,
    'USD',
    ['coupon' => 'SUMMER20'],
);
Analytics::track('begin_checkout', $params);
```

**Config:** PostHog CAPI enabled by default when PostHog is active:
```php
'posthog' => [
    'capi_enabled' => env('ANALYTICS_POSTHOG_CAPI_ENABLED', true),
    'capture_path' => env('ANALYTICS_POSTHOG_CAPTURE_PATH', '/capture/'),
],
```

### Plausible View Item Conversion

New `ga4ToPlausibleViewItem()` converter and updated `ga4ToPlausibleAuto()` now supports 5 e-commerce events: purchase, refund, add_to_cart, begin_checkout, and **view_item**.

### Svelte Inertia Page Tracker

New `initSveltePageTracker()` combines automatic page view tracking, scroll depth reset on navigation, and session start tracking into a single initialization call for Svelte + Inertia projects.

```javascript
import { page } from '@inertiajs/svelte';
import { initSveltePageTracker } from './analytics.js';

$: if (page.props.zbAnalytics?.enabled) {
    const cleanup = initSveltePageTracker({
        scrollDepth: true,
        sessionTracking: true,
        onPageView: (url) => console.log('Tracked:', url),
    });
}
```

### E-Commerce Helpers Expansion

- `buildPosthogViewItem()` — Build PostHog view_item properties
- `buildPosthogAddToCart()` — Build PostHog add_to_cart properties
- `buildPosthogBeginCheckout()` — Build PostHog begin_checkout with coupon support
- `buildPosthogViewItemEvent()` — Full PostHog ViewItem event
- `buildPosthogAddToCartEvent()` — Full PostHog AddToCart event
- `ga4ToPlausibleViewItem()` — GA4 → Plausible view_item conversion
- `ga4ToPlausibleAuto()` expanded to support `view_item`

---

### What's New in v7.3.0

### Alert Notification Dispatcher

New `AlertNotificationService` dispatches analytics alerts to external notification channels when `EventAlertRulesService` triggers an alert. Supports per-severity channel routing, rate limiting, channel cooldowns, and retry with exponential backoff.

**Supported channels:** Slack, Discord, Microsoft Teams, generic webhook, and log channel.

```php
use ZeroBoiler\Analytics\Services\AlertNotificationService;

$service = app(AlertNotificationService::class);

// Manually dispatch an alert
$result = $service->notify([
    'rule' => 'high_error_rate',
    'event' => '*',
    'severity' => 'critical',
    'message' => 'Error rate exceeds 5% of total events',
    'triggered_at' => now()->toIso8601String(),
    'value' => 7.2,
    'threshold' => 5.0,
]);

// Test all configured channels
$test = $service->testChannels();

// Get notification summary
$summary = $service->summary();
```

**Configuration** is fully env-driven with sensible defaults:

```env
ANALYTICS_ALERT_NOTIFICATIONS_ENABLED=true
ANALYTICS_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/...
ANALYTICS_DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/...
ANALYTICS_TEAMS_WEBHOOK_URL=https://outlook.office.com/webhook/...
ANALYTICS_ALERT_WEBHOOK_URL=https://your-webhook.example.com/alerts
```

Severity routing maps alert levels to channels:
- **Critical** → Slack + webhook (immediate)
- **Elevated** → Slack (within cooldown)
- **Warning** → Log channel
- **Info** → Log channel

### Dashboard Integration

Alert notification summary is available for admin dashboards via the `summary()` method, including current rate limit usage, configured channel count, and severity routing map.

---

### What's New in v7.1.0

### Event Recommendation Engine

New `EventRecommendationService` analyzes your tracked events against the full event catalog and recommends instrumentation gaps ranked by business impact. Uses a four-tier priority model (Critical → High → Medium → Low) to guide your analytics implementation.

```php
use ZeroBoiler\Analytics\Services\EventRecommendationService;

$service = app(EventRecommendationService::class);

// Get full gap analysis with coverage score
$result = $service->recommend(['sign_up', 'login', 'page_view']);
// Returns: gaps by priority, coverage percent, score (0-100), grade (A-F)

// Get top 10 recommended events
$top = $service->topRecommendations(['sign_up', 'login'], 10);

// Get AARRR framework coverage breakdown
$aarrr = $service->aarrrBreakdown(['sign_up', 'purchase', 'login']);
// Returns: acquisition, activation, retention, revenue, referral coverage
```

### Provider Gap Analyzer

New `ProviderGapAnalyzer` identifies which tracked events lack provider-specific mappings (GA4, Meta, PostHog, Plausible). Events without mappings are silently dropped by those providers.

```php
use ZeroBoiler\Analytics\Services\ProviderGapAnalyzer;

$analyzer = app(ProviderGapAnalyzer::class);

// Full cross-provider gap analysis
$gaps = $analyzer->analyze(['sign_up', 'custom_event', 'purchase']);
// Returns: per-provider coverage, cross-provider gaps, overall summary

// Check specific provider
$ga4Gaps = $analyzer->gapEvents(['sign_up', 'custom_event'], 'ga4');
$metaMapped = $analyzer->mappedEvents(['sign_up', 'purchase'], 'meta');
```

### New API Endpoints

| Endpoint | Description |
|---|---|
| `GET /api/analytics/recommendations?tracked=sign_up,login` | Event gap analysis with coverage score |
| `GET /api/analytics/recommendations/top?limit=10` | Top N recommended events |
| `GET /api/analytics/recommendations/aarrr` | AARRR framework coverage breakdown |
| `GET /api/analytics/recommendations/tiers` | Priority tier configuration |
| `GET /api/analytics/provider-gaps` | Cross-provider coverage analysis |
| `GET /api/analytics/provider-gaps/{provider}` | Provider-specific gap detail |

### New Config: `recommendations`

```php
// config/zeroboiler.php -> analytics.recommendations
'recommendations' => [
    'enabled' => env('ANALYTICS_RECOMMENDATIONS_ENABLED', true),
    'cache_ttl' => env('ANALYTICS_RECOMMENDATIONS_CACHE_TTL', 300),
    'excluded_events' => [
        // 'video_play', // Exclude if no video content
    ],
],
```

### What's New in v7.0.0

### Event Data Mart Service

New `EventDataMartService` provides OLAP-style pre-aggregated event rollup cubes for instant dashboard queries. Materializes raw analytics events into time-binned summary tables stored in the Laravel cache, inspired by Amplitude, Mixpanel, and PostHog data marts.

```php
use ZeroBoiler\Analytics\Services\EventDataMartService;

$mart = app(EventDataMartService::class);

// Ingest events
$mart->ingest(['name' => 'page_view', 'category' => 'engagement', 'client_id' => 'abc']);
$mart->ingestBatch([...]);

// Query
$topEvents = $mart->top('event_name', 10);
$byCategory = $mart->byCategory();
$summary = $mart->summary();

// Advanced
$cube = $mart->exportCube('event_name', 'day');
$drift = $mart->compareDimensions('category', 'provider');

// Management
$mart->clear();
```

**Config:** `zeroboiler.analytics.data_mart` with `enabled`, `cache_ttl`, `default_granularity`, `max_dimensions`, `auto_dimensions`, `tracked_categories`.

**Test suite:** `V700EventDataMartServiceTest` — 25+ Pest test cases.

### What's New in v6.9.0

### SaaS Event Template Service

New `SaaSEventTemplateService` provides industry-standard pre-configured event templates for common SaaS patterns. Each template generates provider-optimized parameters following GA4, Meta Pixel, and PostHog schemas.

```php
use ZeroBoiler\Analytics\Services\SaaSEventTemplateService;

$template = app(SaaSEventTemplateService::class);

// Authentication
$template->signup($userId, ['method' => 'oauth_google', 'utm_source' => 'google']);
$template->login($userId, ['method' => 'sso', 'session_count' => 5]);

// Subscription Lifecycle
$template->subscriptionCreated($userId, 'Pro', 49.00, ['billing_cycle' => 'monthly']);
$template->planUpgrade($userId, 'Starter', 'Pro', [
    'from_revenue' => 20.00,
    'to_revenue' => 50.00,
    'catalyst' => 'feature_limit',
]);
$template->cancellation($userId, [
    'plan' => 'Pro',
    'reason' => 'too_expensive',
    'ltv' => 1200.00,
    'months_active' => 24,
]);

// Trial Management
$template->trialStart($userId, 'Pro', 14, ['monthly_value' => 49.00]);
$template->trialConverted($userId, 'Pro', [
    'days_to_convert' => 7,
    'features_used_during_trial' => ['dashboard', 'reports'],
]);

// Revenue — generates GA4 + Meta + PostHog params
$template->revenue('TXN-001', 99.99, 'USD', [
    ['item_id' => 'SKU-1', 'item_name' => 'Widget', 'price' => 99.99, 'quantity' => 1],
], ['tax' => 8.00]);

// MRR Movement Tracking
$template->mrrMovement('expansion', 30.00, ['previous_mrr' => 50.0, 'new_mrr' => 80.0]);

// Onboarding
$template->onboardingStepCompleted($userId, 'profile_setup', 1, 5);
$template->onboardingCompleted($userId, 5, ['time_to_complete' => 300]);

// Feature Adoption
$template->featureFirstUse($userId, 'reports', ['days_since_signup' => 3]);
$template->featurePowerUser($userId, 'api', 100);

// E-Commerce Shortcuts
$template->viewItem(['item_id' => 'SKU-1', 'price' => 29.99]);
$template->addToCart(['item_id' => 'SKU-2', 'price' => 19.99, 'quantity' => 2], 'EUR');
```

### Catalog-Aware API Validation

`TrackEventRequest` now supports catalog-aware validation in strict mode. When `zeroboiler.analytics.validation.strict` is enabled, only catalog-registered event names are accepted. Invalid names receive fuzzy suggestions:

```php
// config/zeroboiler.php
'analytics' => [
    'validation' => [
        'strict' => env('ANALYTICS_VALIDATION_STRICT', false),
    ],
],
```

Request error response includes fuzzy suggestions:
```json
{
    "errors": {
        "name": ["The event name 'sign_ups' is not registered in the event catalog. Did you mean: sign_up, login?"]
    }
}
```

### Auth State Change Detection (Client ID Stitching)

The Inertia middleware now detects authentication state changes (login/logout) mid-session and exposes `authStateChanged` and `previousUserId` props. The JS client automatically fires identify events on login to stitch the client ID to the new user ID.

### Configuration

New `event_templates` config section:
```php
'event_templates' => [
    'default_currency' => env('ANALYTICS_TEMPLATES_CURRENCY', 'USD'),
    'auto_utm_attach' => env('ANALYTICS_TEMPLATES_AUTO_UTM', true),
    'auto_user_id_attach' => env('ANALYTICS_TEMPLATES_AUTO_USER_ID', true),
    'include_provider_params' => env('ANALYTICS_TEMPLATES_PROVIDER_PARAMS', true),
],
```

### What's New in v6.4.0

### SaaS Revenue Event Builder

New `SaasRevenueEventBuilder` service provides static factory methods for building provider-optimized SaaS subscription and revenue events. Each method returns parameter arrays for GA4, Meta Pixel, and PostHog simultaneously.

```php
use ZeroBoiler\Analytics\Services\SaasRevenueEventBuilder;

// Subscription event (maps to GA4 purchase, Meta Subscribe, PostHog subscription_created)
$params = SaasRevenueEventBuilder::subscription('Pro', 49.00, 'USD', 'monthly');
Analytics::trackEvent('subscribe', $params['ga4']);

// Plan upgrade with from/to plan tracking
$params = SaasRevenueEventBuilder::planUpgrade('Starter', 'Pro', 99.00);
Analytics::trackEvent('plan_upgrade', $params['ga4']);

// Trial start with duration
$params = SaasRevenueEventBuilder::trialStart('Pro', 14);
Analytics::trackEvent('start_trial', $params['ga4']);

// Payment events with invoice tracking
$params = SaasRevenueEventBuilder::paymentSucceeded(99.00, 'EUR', 'INV-001');
Analytics::trackEvent('payment_succeeded', $params['ga4']);

// Cancellation with reason tracking
$params = SaasRevenueEventBuilder::cancellation('Pro', 'too_expensive');
Analytics::trackEvent('cancellation', $params['ga4']);

// Cross-provider event factory
$event = SaasRevenueEventBuilder::buildEvent('subscribe', 'ga4', ['value' => 49], $clientId, $userId);
Analytics::getManager()->trackEvent($event);
```

### Subscription Lifecycle Config Toggles

Added `subscription.resumed` and `subscription.paused` lifecycle event toggles in the published config, enabling automatic server-side tracking when these events occur.

### Industry-Standard Compliance Test Suite

New `V640IndustryStandardSaaSUpgradeTest` with 40+ test cases validating all 12 feature areas: event catalog coverage (90+ events), lifecycle mapper defaults, cross-provider format conversion, SaaS revenue event builder, config section completeness, version consistency, strict types enforcement, queue serialization, identity linking, GDPR consent, and end-to-end SaaS lifecycle flow.

### What's New in v6.3.0

### TypeScript Type Definitions

Full TypeScript type definitions (`resources/js/analytics.d.ts`) for the JS client library. Provides IntelliSense support in VS Code, WebStorm, and other TypeScript-aware editors for Svelte, Vue, React, and vanilla TS projects.

```typescript
import {
  init,
  trackEvent,
  trackPageView,
  trackEcommerce,
  identify,
  updateConsent,
  connectSSE,
  // ... 100+ typed exports
} from '@zeroboiler/analytics';

// All functions are fully typed with parameter hints
const props = page.props.zbAnalytics as ZbAnalyticsProps;
if (props.enabled) {
  init(page.props);
  await trackEvent('button_click', { element: 'cta' });
}
```

- **ZbAnalyticsProps interface** — Complete type for Inertia page props
- **100+ exported function signatures** — Every public API function typed
- **EcommerceItem, ConsentState, PriorityLevel** — Shared types for structured data
- **SSEConnectOptions, SSEConnection** — Type-safe SSE stream connections
- **WebVitalMetric, CatalogResponse** — Response types for all API calls

### First-Touch UTM Attribution Middleware

New `FirstTouchUTMMiddleware` captures UTM parameters from the user's first visit and persists them in a long-lived cookie (365 days). Subsequent visits inherit the original acquisition source, enabling accurate cross-session attribution.

```php
// app/Http/Kernel.php — Register as global middleware
protected $middleware = [
    // ... existing middleware
    \ZeroBoiler\Analytics\Middleware\FirstTouchUTMMiddleware::class,
    // analytics.inertia should come after first-touch
    \ZeroBoiler\Analytics\Middleware\FirstTouchUTMMiddleware::class,
];

// Or use the alias
protected $middleware = [
    'analytics.first-touch',
];
```

```php
// Access first-touch data in your controllers/services
$firstTouch = $request->attributes->get('_zb_first_touch');
// $firstTouch['data']['utm_source'] → 'google'
// $firstTouch['data']['utm_medium'] → 'cpc'
// $firstTouch['data']['_first_seen_at'] → '2025-01-15T10:30:00Z'
// $firstTouch['data']['_landing_page'] → '/pricing'
```

```env
# .env configuration
ANALYTICS_FIRST_TOUCH_ENABLED=true
ANALYTICS_FIRST_TOUCH_COOKIE=zb_first_touch
ANALYTICS_FIRST_TOUCH_COOKIE_TTL=525600
ANALYTICS_FIRST_TOUCH_COOKIE_SECURE=true
ANALYTICS_FIRST_TOUCH_COOKIE_DOMAIN=          # null = current domain
```

- **First-touch persistence** — UTM params stored in 365-day httpOnly cookie
- **No overwrite** — Original attribution preserved across all subsequent visits
- **Landing page capture** — First landing page URL and timestamp recorded
- **Request attributes** — `_zb_first_touch` available to all downstream middleware
- **Graceful degradation** — Invalid cookie data silently ignored, no exceptions

### What's New in v6.7.0

### Production Readiness Test Suite

Comprehensive 50+ test case suite (`V670SaaSStarterProductionReadinessTest`) validating the complete industry-standard SaaS analytics stack:

- **Event catalog completeness** — 90+ events across ecommerce, SaaS, and engagement categories
- **Cross-provider coverage** — GA4, Meta Pixel, PostHog, and Plausible mappings verified for every event
- **Industry standard readiness** — 100% coverage of the industry-standard SaaS event set
- **Lifecycle mapper** — All critical auth, subscription, trial, e-commerce, and engagement mappings validated
- **E-commerce format conversion** — GA4↔Meta↔PostHog items/contents/purchase/refund conversions
- **SaasRevenueEventBuilder** — Subscription, plan upgrade, cancellation, and buildEvent factory methods
- **SaaS analytics service** — Full lifecycle flow (signup→login→trial→subscribe→upgrade→cancel→feature)
- **GDPR compliance** — Consent lifecycle, sensitive events, enterprise compliance (GDPR, SOC2, ISO27001)
- **Funnel templates** — Signup, trial, checkout, and onboarding funnels verified
- **PHP 8.5 strict types** — All source files enforce `declare(strict_types=1)`

### What's New in v6.5.0

### Config Export API

Runtime config snapshot endpoints for debugging, dashboards, and support workflows. All secrets are automatically redacted.

- **`GET /api/analytics/config/export`** — Full redacted config snapshot
- **`GET /api/analytics/config/status`** — Provider and feature toggle status summary (no values)
- **`GET /api/analytics/config/section/{name}`** — Single config section (e.g. `ga4`, `queue`, `identity`)
- **`AnalyticsConfigExportService`** — Standalone service with `exportRedacted()`, `exportStatusSummary()`, `exportSection()`, and `diff()` methods
- **Config export settings** — `config_export.enabled`, `config_export.expose_secrets`, `config_export.cache_ttl`

```bash
# Get full config (secrets redacted)
curl /api/analytics/config/export

# Get status summary only
curl /api/analytics/config/status

# Get specific section
curl /api/analytics/config/section/ga4
```

### Expanded Inertia Props

Three new props injected into `page.props.zbAnalytics`:

| Prop | Type | Description |
|------|------|-------------|
| `sampling` | `{ enabled, rate, deterministic }` | Client-side event sampling config |
| `geolocation` | `{ enabled, strategy }` | Server-side geolocation enrichment status |
| `regionalConsent` | `{ enabled, gdprDefault }` | Regional GDPR consent detection status |

### JS Client: Config Export Helpers

New exported functions for admin dashboards and debugging:

```javascript
import { fetchConfigExport, fetchConfigStatus, fetchConfigSection } from './analytics';

const config = await fetchConfigExport();     // Full redacted config
const status = await fetchConfigStatus();     // Status summary
const queue = await fetchConfigSection('queue'); // Single section
```

### JS Client: Props Accessors

```javascript
import { getGeolocationStatus, getSamplingConfig, getRegionalConsentStatus } from './analytics';

const geo = getGeolocationStatus();     // { enabled: false, strategy: 'header' }
const sampling = getSamplingConfig();    // { enabled: false, rate: 1.0, deterministic: true }
const rc = getRegionalConsentStatus();  // { enabled: false, gdprDefault: 'denied' }
```

### Expanded Event Aliases

Config `aliases` section now includes categorized default alias examples for authentication, SaaS lifecycle, e-commerce, engagement, and custom application events.

### TypeScript Types

New interfaces: `SamplingConfig`, `GeolocationConfig`, `RegionalConsentConfig`. Added to `ZbAnalyticsProps`.

### What's New in v6.2.0

### AARRR Framework (SaaS Growth Metrics)

Unified AARRR (Pirate Metrics) framework for measuring the five key SaaS growth pillars:

```php
use ZeroBoiler\Analytics\Services\AARRRFrameworkService;

// Health score: weighted score across all 5 pillars (0-100)
$health = $aarrr->healthScore();
// $health->score → 78.5
// $health->grade → 'B'
// $health->pillars → {acquisition: {score: 85.7, grade: 'A'}, ...}

// Coverage analysis: which events are tracked per pillar
$coverage = $aarrr->coverageAnalysis();

// Dashboard summary: single response for SaaS growth dashboards
$dashboard = $aarrr->dashboard();

// Convenience tracking per pillar
$aarrr->trackAcquisition('sign_up', ['source' => 'organic']);
$aarrr->trackActivation('feature_used', ['feature' => 'dashboard']);
$aarrr->trackRetention('login', ['method' => 'email']);
$aarrr->trackRevenue('purchase', ['value' => 99.99]);
$aarrr->trackReferral('share', ['platform' => 'twitter']);
```

- **AARRRFrameworkService** — Single source of truth for Acquisition, Activation, Retention, Revenue, Referral metrics
- **Weighted health scoring** — Each pillar contributes proportionally (20/25/25/20/10) to overall score
- **Coverage analysis** — Identifies which AARRR events are tracked vs. missing per pillar
- **Weakest/strongest pillar detection** — Actionable recommendations for growth improvement
- **Unmapped event discovery** — Find catalog events not yet assigned to any AARRR pillar
- **Cache-backed health scoring** — 5-minute TTL for dashboard performance

### Quick Setup Command

```bash
# Full setup wizard with configuration analysis
php artisan zb:analytics:setup

# With AARRR framework analysis
php artisan zb:analytics:setup --aarrr

# Print required .env variables
php artisan zb:analytics:setup --env

# Show event catalog summary
php artisan zb:analytics:setup --catalog

# Check for common configuration issues
php artisan zb:analytics:setup --fix
```

### New Config Section

```php
// config/zeroboiler.php
'aarrr' => [
    'enabled' => true,
    'cache_ttl' => 300, // 5 minutes
],
```

### What's New in v6.1.0

### Typed Event Parameter Schemas

Industry-standard typed parameter definitions for every event in the catalog. Each schema defines required parameters, optional parameters with types, and validation constraints.

```php
use ZeroBoiler\Analytics\Schema\EventParameterSchemas;

// Get schema for any event
$schema = EventParameterSchemas::forEvent('purchase');
// $schema->required  → ['transaction_id', 'currency', 'value']
// $schema->optional  → ['tax' => 'float', 'shipping' => 'float', ...]
// $schema->itemParams → true

// Validate event parameters at runtime
$errors = EventParameterSchemas::validate('purchase', [
    'transaction_id' => 'ORD-123',
    'currency' => 'USD',
    'value' => 99.99,
]);
// [] → valid (empty errors)
```

- **EventParameterSchema** — Immutable readonly value object with `name`, `category`, `required`, `optional`, `itemParams`
- **EventParameterSchemas** — Static registry with 65+ schemas covering all Ecommerce, SaaS, and Engagement events
- **Runtime validation** — `validate()` returns typed errors for missing required params and type mismatches
- **Full coverage** — All 15 ecommerce events, 50+ SaaS lifecycle events, 30+ engagement events

### What's New in v5.9.0

### Industry Standard SaaS Analytics Readiness

Comprehensive version integrity sweep and industry-standard compliance verification across the entire codebase.

- **Version sweep** — 5.7.0 → 5.9.0 across all 102 files (PHP source, JS client, Svelte composables, config, routes, README, CHANGELOG, 100+ test files)
- **New V59 test suite** — 35+ test cases validating: version integrity, event catalog coverage (90+ events across 3 categories), cross-provider format conversion (GA4↔Meta), lifecycle event mapper config-driven mappings, API controller completeness, Inertia middleware SaaS props, Consent Mode v2 GDPR compliance, identity client ID ↔ user ID linking, optional providers (Plausible, PostHog), admin commands, PHP 8.5 `declare(strict_types=1)` enforcement on all source files, config section completeness (22 sections), JS client batch queue implementation, provider health monitor, event routing configuration, and end-to-end SaaS funnel flows
- **Strict types verified** — all 340+ PHP source files confirmed to use `declare(strict_types=1)`

### What's New in v5.8.0

### Provider Health Monitor

Real-time per-provider health monitoring tracks dispatch success/failure rates over a sliding window and computes health scores (0-100). Unhealthy providers are automatically flagged for bypass.

```php
use ZeroBoiler\Analytics\Services\ProviderHealthMonitor;

$monitor = app(ProviderHealthMonitor::class);

// Record dispatch results (called automatically by the event router)
$monitor->recordSuccess('ga4');
$monitor->recordFailure('meta', 'Connection timeout');

// Check health
$monitor->isHealthy('ga4');   // true
$monitor->getScore('meta');   // 87
$monitor->activeProviders();  // ['ga4', 'plausible', 'posthog', 'webhook']

// Dashboard summary
$monitor->summary();
// { overall_score: 95, healthy_count: 5, unhealthy_providers: ['meta'], version: '5.9.0' }
```

### Event Routing Configuration

Route specific events to designated providers only. Supports exact match, wildcard prefix, and suffix patterns.

```php
// config/zeroboiler.php
'routing' => [
    'enabled' => true,
    'rules' => [
        'purchase' => ['ga4', 'meta'],           // Exact match
        'refund' => ['ga4', 'meta'],              // Exact match
        'add_to_*' => ['ga4', 'meta', 'posthog'], // Wildcard prefix
        'page_view' => ['ga4', 'plausible'],      // Plausible for pageviews
    ],
],
```

Events with no matching rules fall through to all enabled providers.

### Provider-Targeted JS Client

Target specific providers from the client library:

```javascript
import { trackEventWithProviders, trackEcommerceWithProviders } from '@zeroboiler/analytics';

// Send purchase only to GA4 and Meta
await trackEventWithProviders('purchase', { value: 99.99 }, ['ga4', 'meta']);

// E-commerce with provider targeting
await trackEcommerceWithProviders('purchase', {
    transaction_id: 'ORD-123',
    value: 99.99,
    currency: 'USD',
    items: [{ item_id: 'SKU-1', price: 49.99, quantity: 2 }],
}, ['ga4', 'meta']);
```

### New API Endpoints

- `GET /api/analytics/routing` — Event routing summary
- `GET /api/analytics/routing/rules` — List routing rules
- `POST /api/analytics/routing/rules` — Add a routing rule
- `DELETE /api/analytics/routing/rules/{pattern}` — Remove a rule
- `POST /api/analytics/routing/match` — Match event name against rules
- `POST /api/analytics/routing/test` — Test a pattern against event names
- `GET /api/analytics/provider-health` — All provider health scores
- `GET /api/analytics/provider-health/{provider}` — Single provider health detail
- `POST /api/analytics/provider-health/reset` — Reset health stats

### What's New in v5.4.0

### Event Schema JSON Generator

The `EventSchemaJsonGenerator` exports the entire event catalog as machine-readable **JSON Schema Draft 2020-12**, enabling frontend clients to validate event payloads before dispatch.

```php
use ZeroBoiler\Analytics\Services\EventSchemaJsonGenerator;

$generator = app(EventSchemaJsonGenerator::class);

// Full catalog schema (all events)
$schema = $generator->generateCatalogSchema();
$json = $generator->toJson();

// Single event schema with typed parameter hints
$purchaseSchema = $generator->generateEventSchema('purchase');
// Returns: {title: 'purchase', properties: {params: {transaction_id, value, currency, items...}}}

// Category schema (e.g., 'ecommerce', 'saas', 'engagement')
$saasSchema = $generator->generateCategorySchema('saas');

// Minimal event names schema (lightweight client validation)
$namesSchema = $generator->generateEventNamesSchema();

// Provider mapping table
$mappings = $generator->generateProviderMappingTable();
// {ga4: {purchase: 'purchase', ...}, meta: {purchase: 'Purchase', ...}, ...}
```

Parameter types are inferred for well-known events (purchase, sign_up, page_view, etc.) with JSON Schema types, ranges, and patterns.

### Analytics Event Bus (In-Process Pub/Sub)

The `AnalyticsEventBus` provides a lightweight publish/subscribe pattern for decoupled analytics event processing. Subscribers can react to events without coupling to the core tracker.

```php
use ZeroBoiler\Analytics\Bus\AnalyticsEventBus;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

$bus = app(AnalyticsEventBus::class);

// Subscribe to specific events
$bus->subscribe('purchase', function (AnalyticsEvent $event): void {
    // Trigger CRM sync, webhook, etc.
    CRM::recordPurchase($event->params['transaction_id'], $event->params['value']);
});

// Subscribe to all events (wildcard)
$bus->subscribeAll(function (AnalyticsEvent $event): void {
    Log::debug('Analytics event dispatched', ['event' => $event->name]);
});

// Add middleware to modify events before subscribers
$bus->addMiddleware('*', function (AnalyticsEvent $event): AnalyticsEvent {
    // Enrich every event with tenant context
    return new AnalyticsEvent(
        name: $event->name,
        params: array_merge($event->params, ['tenant' => currentTenantId()]),
        clientId: $event->clientId,
        userId: $event->userId,
        timestamp: $event->timestamp,
    );
});

// Publish events
$bus->publish(new AnalyticsEvent('purchase', ['value' => 99.99]));
```

**Features:**
- Re-entrant safe: nested publishes are queued and flushed after the current event
- Middleware chain: modify events before they reach subscribers
- Named + global subscribers
- Event count inspection and cleanup API

### Regional Consent Detection

The `RegionalConsentService` automatically applies GDPR-compliant consent defaults based on the user's geographic region. EU, UK, Brazil, Canada, and 20+ other privacy-regulated jurisdictions default to `denied` (opt-in).

```php
use ZeroBoiler\Analytics\Services\RegionalConsentService;

$service = app(RegionalConsentService::class);

// Determine consent default from country code
$consent = $service->getConsentDefault('DE');  // 'denied' (GDPR)
$consent = $service->getConsentDefault('US');  // 'granted' (non-GDPR)

// From IP with request headers
$consent = $service->getConsentDefaultFromIp($ip, [
    'cf-ipcountry' => 'FR',  // Cloudflare header
]);

// Check regions
$service->isGdprRegion('GB');     // true
$service->isUsPrivacyState('CA'); // true (California)
$service->getGdprRegions();       // Full list of GDPR-applicable countries
$service->summary();              // Configuration summary
```

**Config:**
```env
ANALYTICS_REGIONAL_CONSENT_ENABLED=true
ANALYTICS_REGIONAL_CONSENT_GDPR_DEFAULT=denied
```

**Region coverage:** EU-27, UK, EEA, Brazil (LGPD), Canada (PIPEDA), India (DPDPA), Japan (APPI), South Korea (PIPA), Switzerland, Argentina, Thailand, Philippines, Indonesia, Vietnam, UAE, Saudi Arabia, Turkey, South Africa, plus 11 US state privacy laws.

### Other Changes
- Version synchronized across PHP, JS client, Svelte composables, and TypeScript definitions (5.4.0)
- 3 new services registered in AnalyticsServiceProvider
- `regional_consent` config section added
- 3 new PHP classes with strict types, return types, and docblocks

### What's New in v5.9.0

### Universal Cross-Provider Format Conversion

The `EcommerceFormatConverter` now provides **universal bidirectional conversion** between GA4 and all supported providers (Meta Pixel, PostHog, Plausible).

**New methods:**
- `toGa4Format($targetProvider, $ga4EventName, $ga4Params)` — Convert any GA4 e-commerce event to Meta, PostHog, or Plausible format in a single call
- `fromGa4Format($sourceProvider, $eventName, $params)` — Convert Meta/PostHog events back to GA4 format
- `ga4ToPlausibleAuto($ga4EventName, $ga4Params)` — Universal GA4 → Plausible auto-converter (analogous to `ga4ToMetaAuto`)

**Usage:**
```php
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;

// GA4 → Meta (universal)
$result = EcommerceFormatConverter::toGa4Format('meta', 'purchase', $ga4Params);

// GA4 → Plausible (universal)
$result = EcommerceFormatConverter::toGa4Format('plausible', 'add_to_cart', $ga4Params);

// Meta → GA4 (reverse)
$ga4 = EcommerceFormatConverter::fromGa4Format('meta', 'Purchase', $metaParams);
```

**Other changes:**
- Version synchronized across PHP, JS client, Svelte composables, and TypeScript definitions (5.9.0)
- `EcommerceFormatConverter` now imports `EventCatalog` for provider name resolution

### What's New in v5.2.0

### Serializable Queue Jobs (Breaking Improvement)

The `QueuedAnalyticsDispatcher` now dispatches **serializable Job classes** instead of closures. This is a **critical production improvement** — closures cannot be serialized by redis, database, or any persistent queue driver.

**New classes:**
- `TrackAnalyticsEventJob` — Single event dispatch job with retry (3 attempts, 5s backoff, 30s timeout)
- `TrackAnalyticsEventBatchJob` — Batch event dispatch with per-event error isolation (3 attempts, 5s backoff, 120s timeout)

**New config option:**
```env
ANALYTICS_QUEUE_MAX_BATCH_SIZE=50
```

**Migration guide:** If you use `sync` queue driver, no changes needed. If you use `redis`/`database` drivers, this update fixes queue serialization errors that may have been silently failing in v5.1.0.

**Other changes:**
- Version synchronized across PHP, JS client, and Svelte composables (5.2.0)
- `QueuedAnalyticsDispatcher::getMaxBatchSize()` added for programmatic batch size inspection
- `EventPipeline` data bus, session analytics, and cohort analytics services now use serializable jobs

### What's New in v6.8.0

### Checkout Flow Tracker

New `CheckoutFlowTracker` service provides multi-step checkout funnel tracking with step-level conversion analytics.

```php
use ZeroBoiler\Analytics\Services\CheckoutFlowTracker;

/** @var CheckoutFlowTracker $tracker */

// Start checkout
$result = $tracker->startCheckout($clientId, $items, 'USD', 'SAVE10');
// → ['checkout_id' => 'cko_...', 'step' => 'cart_review', 'step_index' => 1, 'value' => 69.97]

// Advance through steps
$tracker->advanceStep($clientId, 'shipping_info', ['shipping_method' => 'express']);
$tracker->advanceStep($clientId, 'payment_info', ['payment_type' => 'credit_card']);
$tracker->advanceStep($clientId, 'order_review');

// Complete purchase
$result = $tracker->completeCheckout($clientId, 'TXN-001', 69.97, 'USD', ['tax' => 5.99, 'shipping' => 4.99]);
// → ['transaction_id' => 'TXN-001', 'total_steps' => 5, 'total_time' => 342, 'completed' => true]

// Or abandon
$result = $tracker->abandonCheckout($clientId, 'payment_failed');
// → ['abandoned_at_step' => 'payment_info', 'value' => 69.97]

// Get step timing analysis
$timings = $tracker->getStepTiming($clientId);
// → [['step' => 'cart_review', 'duration_seconds' => 120, 'duration_formatted' => '2m'], ...]

// Get funnel steps definition
$funnel = $tracker->funnelSteps();
```

**Checkout steps**: `cart_review` → `shipping_info` → `payment_info` → `order_review` → `confirmation`

Configuration:
```env
ANALYTICS_CHECKOUT_TRACKING_ENABLED=true
ANALYTICS_CHECKOUT_CACHE_TTL=86400
```

### SaaS KPI Calculator

New `SaaSKpiCalculatorService` computes industry-standard SaaS metrics from raw billing data. Formulas aligned with OpenView, Bessemer, and KeyBanc benchmarks.

```php
use ZeroBoiler\Analytics\Services\SaaSKpiCalculatorService;

/** @var SaaSKpiCalculatorService $kpi */

// Individual metrics
$mrr = $kpi->mrr($subscriptions);        // Monthly Recurring Revenue
$arr = $kpi->arr($mrr);                  // Annual Recurring Revenue
$arpu = $kpi->arpu($mrr, $activeCount); // Average Revenue Per User
$churn = $kpi->churnRate(5, 100);        // 5% monthly churn
$ltv = $kpi->ltv($arpu, $churn);        // Customer Lifetime Value
$ltvCac = $kpi->ltvCacRatio($ltv, $cac);// LTV:CAC ratio (benchmark: > 3:1)
$payback = $kpi->paybackPeriod($cac, $arpu); // Months to recover CAC
$nrr = $kpi->netRevenueRetention($start, $expansion, $contraction, $churn); // NRR (benchmark: > 100%)
$grr = $kpi->grossRevenueRetention($start, $contraction, $churn);           // GRR (benchmark: > 90%)
$quickRatio = $kpi->quickRatio($new, $expansion, $contraction, $churn);    // Benchmark: > 4.0
$ruleOf40 = $kpi->ruleOf40(50.0, -5.0); // Growth rate + profit margin (target: ≥ 40)

// Full dashboard
$dashboard = $kpi->computeDashboard([
    'subscriptions' => [...],
    'active_subscribers' => 500,
    'churned_customers' => 15,
    'start_customers' => 485,
    // ... full billing data
]);
// → ['mrr' => 15000, 'arr' => 180000, 'churn_rate' => 0.03, 'nrr' => 1.12, 'health' => [...]]
```

### Provider Event Validator

New `ProviderEventValidator` validates event parameters against provider-specific schemas before dispatch — catching malformed data early to prevent API rejections.

```php
use ZeroBoiler\Analytics\Services\ProviderEventValidator;

$validator = new ProviderEventValidator;

// Validate for a specific provider
$ga4Result = $validator->validateGa4($event);
// → ['valid' => true/false, 'errors' => [...], 'warnings' => [...]]

// Validate across all providers
$result = $validator->validateAll($event, ['ga4', 'meta', 'posthog']);
// → ['valid' => true, 'providers' => ['ga4' => ['valid' => true, ...], ...]]
```

**Validations per provider:**
- **GA4**: Required item fields (`item_id`, `price`), max 25 items, ISO 4217 currency, numeric values, `transaction_id` for purchases
- **Meta Pixel**: `content_ids` array types, `num_items` consistency, `content_type` for e-commerce events
- **PostHog**: Reserved `$properties` detection, `$currency` format warning
- **Plausible**: No spaces in event names, max length, params warning (Plausible ignores properties)

### What's New in v10.4.0

### 🧪 AnalyticsFake — Test Fake for Analytics Events

Industry-standard test double for the Analytics facade, modeled after Laravel's `MailFake`, `NotificationFake`, and `BusFake`. Intercepts all analytics dispatches in tests and provides fluent assertion API.

```php
// In your Pest tests:
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Support\AnalyticsFake;
use ZeroBoiler\Analytics\Support\WithAnalyticsFake;

beforeEach(function () {
    app()->instance('zeroboiler.analytics', new AnalyticsFake);
    // Or use the trait:
    // $this->withAnalyticsFake();
});

it('tracks sign_up on registration', function () {
    app('zeroboiler.analytics')->track('sign_up', ['method' => 'email']);
    AnalyticsFake::assertTracked('sign_up');
});

it('tracks purchase with correct value', function () {
    app('zeroboiler.analytics')->track('purchase', ['value' => 99.99]);
    AnalyticsFake::assertTracked('purchase', function ($event) {
        return $event->params['value'] === 99.99;
    });
});

it('does not track when consent denied', function () {
    AnalyticsFake::assertNothingTracked();
});

it('identifies user on login', function () {
    app('zeroboiler.analytics')->identify('user_42');
    AnalyticsFake::assertIdentified('user_42');
});
```

**Available assertions:**
- `Analytics::assertTracked(string $eventName, ?callable $callback = null)`
- `Analytics::assertNotTracked(string $eventName)`
- `Analytics::assertTrackedTimes(string $eventName, int $times)`
- `Analytics::assertNothingTracked()`
- `Analytics::assertIdentified(string $userId, ?callable $callback = null)`
- `Analytics::assertPageViewTracked(?callable $callback = null)`

**New files:**
- `src/Support/AnalyticsFake.php` — Test fake extending AnalyticsManager
- `src/Support/WithAnalyticsFake.php` — Convenient trait for test setup
- `tests/AnalyticsFakeTest.php` — Comprehensive test suite (21 test cases)

---

### What's New in v10.3.0

### 📊 Event Timeline Service (`EventTimelineService`)

Industry-standard user event timeline for SaaS analytics dashboards — inspired by Amplitude User Lookup, Mixpanel User Profile, and PostHog User Activity feeds.

**Key capabilities:**
- **Chronological event timeline** — `getTimeline('client-123')` returns events sorted by timestamp with pagination support
- **User-scoped timelines** — `getUserTimeline(userId)` resolves all linked client IDs and merges timelines using `IdentityResolutionService`
- **Funnel step annotation** — `getAnnotatedTimeline()` marks each event's position in configured funnels (signup → trial → subscribe)
- **Event gap detection** — `detectGaps()` identifies time windows between critical events exceeding configured thresholds (e.g., trial started but no login in 48h)
- **Session-bound grouping** — Groups events into sessions using configurable session timeout (default: 30 min)
- **Provider coverage overlay** — Each timeline entry annotated with which providers received the event
- **Configurable retention** — Timeline entries cached with configurable TTL and max entries per identity
- **Cache-backed** — Per-identity cache keys with automatic eviction on exceeding max entries

**API Endpoints:**
```
GET  /api/analytics/timeline/{clientId}          — Client ID event timeline
GET  /api/analytics/timeline/user/{userId}      — User-scoped merged timeline
GET  /api/analytics/timeline/{clientId}/gaps    — Event gap detection
GET  /api/analytics/timeline/{clientId}/sessions — Session-grouped timeline
```

**Configuration:**
```php
'timeline' => [
    'enabled' => env('ANALYTICS_TIMELINE_ENABLED', true),
    'cache_ttl' => (int) env('ANALYTICS_TIMELINE_CACHE_TTL', 3600), // 1 hour
    'max_entries' => (int) env('ANALYTICS_TIMELINE_MAX_ENTRIES', 500),
    'session_timeout' => (int) env('ANALYTICS_TIMELINE_SESSION_TIMEOUT', 1800), // 30 minutes
    'gap_thresholds' => [
        'trial_start_to_login' => 172800, // 48 hours
        'signup_to_trial' => 604800,     // 7 days
        'purchase_to_return' => 2592000,  // 30 days
    ],
],
```

**Artisan Command:**
```bash
php artisan zb:analytics:timeline {clientId} --user --gaps --sessions --json --limit=50
```

### 🧪 Tests
- `V103EventTimelineServiceTest` — 30+ assertions covering timeline, user merge, gaps, sessions, cache, and config

### 🔢 Version Sweep
- 10.2.0 → 10.3.0 across composer.json, AnalyticsEvent::VERSION, JS client, Svelte composables, TypeScript definitions, ServiceProvider, IntegrityCommand EXPECTED_VERSION, README badge

### What's New in v10.2.0

### 🔗 9-Provider Full Client Coverage

**Expanded client-side coverage for Mixpanel and Amplitude trackers:**
- **Mixpanel client integration** — `trackEvent()` and `trackPageView()` now dispatch to `window.mixpanel.track()` when the Mixpanel JS SDK is loaded
- **Amplitude client integration** — `trackEvent()` and `trackPageView()` now dispatch to `window.amplitude.logEvent()` when the Amplitude JS SDK is loaded
- **Identity propagation** — `setTrackingId()` sets `mixpanel.register({ zb_client_id })` and `amplitude.setDeviceId()` for cross-device identity continuity
- **9 providers total** — GA4, GTM, Meta Pixel, Plausible, PostHog, Mixpanel, Amplitude, Webhook + Server-Sent Events

### 📋 Event Catalog Summary & Billing Events

- **`EventCatalog::summary()` expanded** — Now includes `security` and `uptime` category counts in the summary output
- **`EventCatalog::billingEvents()`** — New method returning all billing and revenue-related SaaS events for financial lifecycle tracking
- **`EventCatalog::providerCoverage()`** — Enhanced with per-provider count breakdown

### What's New in v10.1.0

### 🔧 Production Readiness Refactor

- **Manual code review verified** — Full audit of PHP 8.5 syntax, strict types, return type declarations, and docblocks across all source files
- **Constructor return types** — All service and tracker constructors annotated with `:void` return type for PHP 8.5 compliance
- **Service provider cleanup** — Consolidated service registrations and streamlined boot logic
- **Test stability** — Verified all test files pass with consistent assertions

### What's New in v10.0.0

**Mixpanel & Amplitude Trackers — 8-Provider SaaS Analytics**

### 🎯 Mixpanel Tracker (`MixpanelTracker`)
- **Server-side event tracking** — Tracks events via the Mixpanel `/track` API endpoint
- **User profiling** — `setUserProfile()` for `$set` operations (name, email, plan, created_at)
- **Incremental properties** — `incrementUserProperty()` for `$add` operations (login_count, revenue)
- **Cross-device identity** — `alias()` for merging anonymous and authenticated identities
- **GDPR reset** — `reset()` for right-to-be-forgotten compliance
- **Client-side script injection** — `headScripts()` renders the Mixpanel JS SDK snippet
- **Config-driven** — Enable via `ANALYTICS_MIXPANEL_ENABLED`, configure token and host

### 📊 Amplitude Tracker (`AmplitudeTracker`)
- **Server-side event tracking** — Tracks events via the Amplitude V2 HTTP API (`/v2/httpapi`)
- **User identification** — `identify()` for `$identify` with user properties and device context
- **Property sanitization** — Auto-truncates strings to 1024 chars, strips nested arrays, filters nulls
- **Platform detection** — Configurable `platform` field (default: `Laravel/Server`)
- **GDPR reset** — `reset()` sends `$reset` event to Amplitude API
- **Client-side script injection** — `headScripts()` renders the Amplitude JS SDK snippet
- **Config-driven** — Enable via `ANALYTICS_AMPLITUDE_ENABLED`, configure api_key and host

### 🔧 AnalyticsManager Updates
- `AnalyticsManager` now manages **8 trackers**: GA4, GTM, Meta Pixel, Plausible, PostHog, **Mixpanel**, **Amplitude**, and Webhook
- `mixpanel()` — Returns the MixpanelTracker instance
- `amplitude()` — Returns the AmplitudeTracker instance
- `directDispatch()` dispatches to all 8 enabled trackers
- `headScripts()` injects scripts from all enabled client-side trackers
- `setConsent()` propagates consent state to all 8 trackers

### ⚙️ Config Expansion
- `mixpanel` — `ANALYTICS_MIXPANEL_ENABLED`, `ANALYTICS_MIXPANEL_TOKEN`, `ANALYTICS_MIXPANEL_HOST`
- `amplitude` — `ANALYTICS_AMPLITUDE_ENABLED`, `ANALYTICS_AMPLITUDE_API_KEY`, `ANALYTICS_AMPLITUDE_HOST`, `ANALYTICS_AMPLITUDE_PLATFORM`

### 🧪 Tests
- `V1000MixpanelAmplitudeTrackersTest` — 28 assertions covering both trackers, consent, and manager integration

### 🔢 Version Sweep
- 9.9.0 → 10.0.0 across composer.json, AnalyticsEvent::VERSION, JS client (getVersion + _getInternalVersion), README badge

### Files Added
- `src/Trackers/MixpanelTracker.php` — Mixpanel server-side tracker
- `src/Trackers/AmplitudeTracker.php` — Amplitude server-side tracker
- `tests/V1000MixpanelAmplitudeTrackersTest.php` — Integration tests

### What's New in v9.9.0

**Security & Uptime Event Categories — Industry-Standard SaaS Analytics Expansion**

New event categories for comprehensive SaaS observability:

### Security Events (`SecurityEvents` catalog)
- **`login_attempt`** — Tracks authentication attempts (method, success/failure, reason)
- **`suspicious_activity`** — Detects brute-force, unusual patterns, permission escalation
- **`data_access_audit`** — GDPR Art.30 compliant access audit trail (resource, action, actor, target)
- **`rate_limit_exceeded`** — API rate limit violations with endpoint, client, and limit details
- **`mfa_challenge`** — Multi-factor authentication lifecycle (totp, sms, email, hardware_key)

### Uptime & Infrastructure Events (`UptimeEvents` catalog)
- **`service_up`** — Service recovery tracking with downtime duration
- **`service_down`** — Outage detection with error details and impact level
- **`api_latency`** — Performance threshold violations (response time vs. configured threshold)
- **`error_spike`** — Error rate anomaly detection with baseline comparison
- **`deployment`** — Change-event correlation for deployment impact analysis

### Config Expansion
- `security_events` — Per-event toggles, sensitive logging, IP anonymization, rate limiting
- `uptime` — Latency thresholds, error spike multiplier, tracked services list, cache TTL
- `lifecycle.events` — 15 new security/uptime toggle entries

### EventCatalog Updates
- `EventCatalog::all()` now includes `security` and `uptime` categories
- `EventCatalog::byCategory()` returns 5 categories (was 3)
- `EventCatalog::getCategory()`, `::has()`, `::classFor()`, `::count()` all updated
- All provider name methods (`::allGa4Names()`, `::allMetaNames()`, etc.) include new categories

### Files Added
- `src/Events/Security/` — 5 event classes + SecurityEvents catalog
- `src/Events/Uptime/` — 5 event classes + UptimeEvents catalog

### What's New in v10.9.0

### SaaS Identity Linking — One-Call Auth Flow

The new `trackSaaSIdentity()` method combines three operations into a single atomic call for login/signup flows:

```php
// Before (3 separate calls):
Analytics::identify($userId, $clientId);
Analytics::setUserProperties($traits, $userId);
// + manual IdentityResolutionService::link()

// After (1 call):
Analytics::trackSaaSIdentity($userId, $clientId, [
    'name' => $user->name,
    'email_hash' => hash('sha256', $user->email),
    'plan' => $user->plan,
    'company' => $user->company,
]);
```

### Facade IDE Autocompletion Complete

All 8 provider tracker accessors are now documented on the `Analytics` facade:

```php
Analytics::ga4();       // GA4Tracker
Analytics::gtm();       // GTMTracker
Analytics::meta();      // MetaPixelTracker
Analytics::plausible(); // PlausibleTracker
Analytics::posthog();   // PosthogTracker
Analytics::webhook();   // WebhookTracker
Analytics::mixpanel();   // MixpanelTracker      ← NEW in v10.9.0
Analytics::amplitude(); // AmplitudeTracker     ← NEW in v10.9.0
```

### Test Coverage

23 new test cases (150+ assertions) validate the entire API surface: event catalog integrity, SaaS lifecycle methods, tracker accessors, GDPR compliance, funnel tracking, orchestration, B2B groups, PLG scoring, and time-series analytics.

### What's New in v30.0.0

### Event Store — Persistent Event Storage Layer

The biggest architectural upgrade in v30.0.0: a full **persistent event storage abstraction layer** with pluggable backends. Previously, all analytics events were ephemeral (cache-only) with no database persistence. This release introduces:

- **`AnalyticsEventStoreInterface`** — Contract for event persistence backends with `store()`, `retrieve()`, `query()`, `count()`, `delete()`, `aggregateBy()`, and `purge()` methods
- **`DatabaseEventStore`** — Eloquent-backed persistent storage with bulk insert optimization, indexed query columns, and configurable retention pruning
- **`CacheEventStore`** — Cache-backed ephemeral storage with event index for query support, suitable for real-time dashboards
- **`NullEventStore`** — No-op implementation for testing and when persistence is disabled
- **`EventStoreManager`** — Orchestrator with primary/fallback store support, automatic health monitoring, and stats reporting
- **`AnalyticsEventModel`** — Eloquent model with UUID primary key, composite indexes, and Laravel Prunable integration for automatic data retention
- **Database Migration** — `analytics_events` table with indexed columns for all common query patterns (event name, category, user ID, client ID, session ID, timestamp, fingerprint, idempotency key)
- **9 new API endpoints** — `/api/analytics/store/health`, `/stats`, `/events`, `/events/{id}`, `/count`, `/aggregate/{groupBy}`, `DELETE /events`, `/events/{id}`, `/`
- **Auto-persist mode** — When enabled, every dispatched event is automatically persisted to the configured store
- **Config-driven setup** — Configure driver (database/cache/null), fallback, retention, and connection settings via `config/zeroboiler.analytics.event_store`

**Configuration:**
```env
ANALYTICS_EVENT_STORE_ENABLED=true
ANALYTICS_EVENT_STORE_DRIVER=database     # database, cache, null
ANALYTICS_EVENT_STORE_AUTO_PERSIST=true   # auto-store on dispatch
ANALYTICS_EVENT_STORE_FALLBACK_DRIVER=cache  # fallback when primary fails
ANALYTICS_EVENT_STORE_RETENTION_DAYS=90   # auto-prune old events
```

**Migration:**
```bash
php artisan migrate --path=vendor/zeroboiler/analytics/database/migrations
```

### New Files (8 PHP + 1 migration + 1 test)
- `src/Contracts/AnalyticsEventStoreInterface.php`
- `src/Store/EventStoreManager.php`
- `src/Store/DatabaseEventStore.php`
- `src/Store/CacheEventStore.php`
- `src/Store/NullEventStore.php`
- `src/Models/AnalyticsEventModel.php`
- `database/migrations/2026_08_12_000000_create_analytics_events_table.php`
- `tests/V300EventStorePersistentStorageTest.php` (23 test cases)

### What's New in v37.0.0

### Event Router Service — Provider-Aware Destination Routing
Segment/RudderStack-style destination filtering. Route events to specific analytics providers based on configurable rules:
- **Category routes**: Send all ecommerce events only to GA4 + Meta Pixel
- **Pattern rules**: Glob and regex event name matching
- **Priority routes**: Critical events → all providers, background → none
- **Cost optimization**: Automatically skip expensive providers for low-priority events
- **Deny/Allow lists**: Per-event provider control

```php
// Config example
'event_router' => [
    'enabled' => true,
    'category_routes' => [
        'ecommerce' => ['ga4', 'meta_pixel', 'posthog'],
        'saas' => ['ga4', 'posthog', 'mixpanel'],
    ],
    'cost_optimized' => true,
    'deny_list' => [
        'scroll_depth' => ['meta_pixel', 'tiktok'],
    ],
],
```

### Analytics Workspace Service — Multi-Tenant KPI Rollups
Per-workspace (tenant) analytics aggregation for multi-tenant SaaS dashboards:
- **DAU/WAU/MAU** active user counts
- **Top events** ranking by volume
- **Revenue totals** (MRR + one-time)
- **Funnel conversion rates** (configurable per workspace)
- **Engagement score** (events per active user, 0-100 scale)
- **Workspace comparison** (sorted by engagement)

```php
$workspace = app(AnalyticsWorkspaceService::class);
$overview = $workspace->getOverview('workspace-123');
// Returns: active_users, total_events, top_events, engagement_score, funnels, revenue
```

### 9 New API Endpoints
- `GET /api/analytics/router/summary` — routing configuration
- `GET /api/analytics/router/validate` — rule validation
- `GET /api/analytics/router/providers` — all supported providers
- `GET /api/analytics/workspace/{id}` — full workspace overview
- `GET /api/analytics/workspace/{id}/active-users` — DAU/WAU/MAU
- `GET /api/analytics/workspace/{id}/top-events` — ranked events
- `GET /api/analytics/workspace/{id}/funnels` — funnel conversion rates
- `GET /api/analytics/workspace/{id}/revenue` — revenue totals
- `POST /api/analytics/workspace/compare` — multi-workspace comparison

### What's New in v77.0.0

### SDK Token Authentication Middleware

**New Middleware: `VerifySdkToken`** — middleware-based SDK token authentication for API endpoints, allowing client-side SDKs to authenticate via scoped tokens instead of Sanctum sessions.

Capabilities:
- **3 token delivery methods**: `Authorization: Bearer ***` header, `X-ZB-SDK-Token` header, `zb_sdk_token` query parameter
- **Permission enforcement**: optional `required_permission` config per route group (e.g., `batch`, `track`, `identify`, `consent`, `pageview`)
- **Rate limiting integration**: per-token per-minute rate limits via `SdkScopeTokenService`, with `429 Too Many Requests` response and `Retry-After` header
- **Graceful fallback**: when `sdk_auth.enabled = false`, requests pass through to default auth (auth:sanctum)
- **Request attributes**: `zb_sdk_authenticated`, `zb_sdk_token_raw`, `zb_sdk_rate_remaining`, `zb_sdk_rate_reset` for downstream controllers

**Middleware Registration:** `analytics.sdk` alias in `AnalyticsServiceProvider`

**New Config Section:** `zeroboiler.analytics.sdk_auth`
- `enabled`, `required_permission`, `enforce_rate_limit`

**Tests:** `V7700SdkAuthMiddlewareTest` — 11 test cases covering disabled passthrough, service disabled passthrough, missing token (401), invalid token (401), Bearer header acceptance, SDK header acceptance, query parameter acceptance, wrong permission rejection (403), rate limit info attachment, authenticated flag setting, and request metadata propagation

**Version Sweep:** 76.0.0 → 77.0.0 across composer.json, package.json, AnalyticsEvent::VERSION, AnalyticsIntegrityCommand::EXPECTED_VERSION

### What's New in v78.0.0

### Revenue Waterfall, Feature Flag Analytics & SaaS Growth Metrics

**Three major new services** for industry-standard SaaS analytics tracking, completing the full revenue lifecycle and growth measurement stack.

#### New Services

1. **RevenueWaterfallService** — MRR movement tracking inspired by ChartMogul and Baremetrics
   - Track MRR movements: `new`, `expansion`, `contraction`, `reactivation`, `churn`
   - Revenue waterfall chart data (starting MRR → movements → ending MRR)
   - Net MRR retention rate calculation
   - 12-month MRR trend analysis
   - Movement summary with per-type counts and average deal sizes

2. **FeatureFlagAnalyticsService** — Feature flag evaluation → conversion tracking
   - Track any feature flag provider (LaunchDarkly, Unleash, Flagsmith, Optimizely, custom)
   - Variant distribution analysis (control/treatment/variants)
   - Conversion rate tracking per variant
   - Feature adoption ranking
   - First-exposure detection for clean A/B test measurement

3. **SaaSGrowthMetricsService** — North Star & product-led growth metrics
   - Activation rate (aha moment completion rate)
   - Stickiness rate (DAU/MAU ratio)
   - Virality coefficient (K-factor: invites × conversion rate)
   - Retention curves (D1, D3, D7, D14, D30)
   - Growth milestone tracking (activation, power user, advocate, team scale, revenue tier)
   - Growth dashboard summary endpoint

#### New Event Classes (SaaS Category)

| Event | Description |
|-------|-------------|
| `MrrMovementEvent` | MRR movement tracking (new/expansion/contraction/reactivation/churn) |
| `FeatureFlagEvaluatedEvent` | Feature flag evaluation with variant, first-exposure, experiment context |
| `GrowthMilestoneEvent` | Growth milestone tracking with type, name, value, time-to-milestone |

#### New CLI Command

```bash
# Revenue waterfall with growth dashboard
php artisan zb:analytics:revenue-waterfall --growth --flags --retention

# MRR trend for last 12 months
php artisan zb:analytics:revenue-waterfall --trend --json

# Clear all waterfall/growth/flag cache
php artisan zb:analytics:revenue-waterfall --clear-cache
```

#### New API Endpoints (14 routes)

```
GET  /api/analytics/revenue/waterfall          — Revenue waterfall data
GET  /api/analytics/revenue/waterfall/trend     — MRR trend (12 months)
GET  /api/analytics/revenue/net-mrr-retention   — Net MRR retention rate
GET  /api/analytics/revenue/movements           — Movement summary
GET  /api/analytics/feature-flags               — List all tracked flags
GET  /api/analytics/feature-flags/{key}/distribution  — Variant distribution
GET  /api/analytics/feature-flags/{key}/conversions    — Conversion rates
GET  /api/analytics/feature-flags/adoption      — Feature adoption summary
GET  /api/analytics/growth/dashboard            — Growth dashboard summary
GET  /api/analytics/growth/activation           — Activation rate
GET  /api/analytics/growth/stickiness           — DAU/MAU stickiness
GET  /api/analytics/growth/virality             — K-factor
GET  /api/analytics/growth/retention             — Retention curve
GET  /api/analytics/growth/milestones           — Growth milestones
```

#### New Config Sections

```php
// config/zeroboiler.php
'revenue_waterfall' => [
    'enabled' => env('ANALYTICS_REVENUE_WATERFALL_ENABLED', true),
    'cache_ttl' => 300,   // 5 minutes
    'currency' => 'USD',
],
'feature_flags' => [
    'enabled' => env('ANALYTICS_FEATURE_FLAGS_ENABLED', true),
    'cache_ttl' => 300,   // 5 minutes
],
'growth_metrics' => [
    'enabled' => env('ANALYTICS_GROWTH_METRICS_ENABLED', true),
    'cache_ttl' => 3600,  // 1 hour
    'activation_events' => [
        // 'first_project_created', 'first_api_call', 'team_invited',
    ],
],
```

**Usage Example:**

```php
use ZeroBoiler\Analytics\Services\RevenueWaterfallService;
use ZeroBoiler\Analytics\Services\FeatureFlagAnalyticsService;
use ZeroBoiler\Analytics\Services\SaaSGrowthMetricsService;

// Record MRR movements
$waterfall->recordMovement('expansion', 29.00, [
    'customer_id' => 'cust_123',
    'plan_id' => 'pro',
    'previous_plan_id' => 'starter',
]);

// Track feature flag evaluation
$flags->trackEvaluation('new_dashboard_v2', 'treatment', isFirstExposure: true);
$flags->trackConversion('new_dashboard_v2', 'treatment', 'purchase', ['value' => 99.99]);

// Track growth milestones
$growth->trackMilestone('activation', 'Completed first project', daysSinceSignup: 2);
$growth->trackMilestone('power_user', 'Sent 1000 messages', milestoneValue: 1000);
```

**Tests:** `V7800RevenueWaterfallGrowthMetricsFeatureFlagTest` — comprehensive test suite covering all three services, event classes, validation, and API endpoints

**Version Sweep:** 77.0.0 → 78.0.0 across composer.json, package.json, AnalyticsEvent::VERSION, JS client

### What's New in v76.0.0

### Event Contract Testing Engine — Provider-Specific Contract Validation

**New Service: `EventContractTestService`** — validates event payloads against provider-specific contracts before dispatch, inspired by **Segment Protocols**, **PostHog Property Validation**, and **Amplitude's Event Validator**.

Capabilities:
- **GA4 contracts**: purchase (requires `transaction_id`, `value`; max 25 items; currency enum), view_item, add_to_cart, refund, begin_checkout
- **Meta Pixel contracts**: Purchase (requires `value`, `currency`; max 100 content_ids), ViewContent, AddToCart, InitiateCheckout, CompleteRegistration, Subscribe
- **PostHog contracts**: $signup (requires `$distinct_id`; max 100 properties), $pageview — with reserved property detection ($device_id, $session_id, $set, etc.)
- **Plausible contracts**: pageview (max 10 props; no spaces in event names)
- **8 provider validation**: GA4, Meta, PostHog, Plausible, Mixpanel, Amplitude, TikTok, LinkedIn
- **Validation rules**: required params, enum constraints, max items/content_ids, reserved properties, parameter length (500 chars), event name length (100 chars), Plausible name format
- **Severity levels**: `reject` (block dispatch), `warn` (log only), `off` (skip)
- **Per-provider pass/fail** reporting with detailed violation messages

**New Command: `zb:analytics:contract`**
- 5 actions: `validate` (single event), `catalog` (entire catalog), `coverage` (per-provider), `list` (registered contracts), `test` (built-in test suite)
- `--event`, `--provider`, `--json`, `--severity` options

**New Config Section:** `zeroboiler.analytics.contract_testing`
- `enabled`, `severity`, `cache_ttl`

**Tests:** `V76EventContractTestServiceTest` — 30+ test cases covering constructor/config, severity constants, contract registration, event validation (required params, enum constraints, max items, reserved properties, param length, event name length, Plausible format), disabled/skipped validation, per-provider validation, catalog validation, provider coverage, violation structure, and production readiness (final class, namespace, @since, return types, strict_types)

### Version Sweep

All internal version references updated from `75.0.0` to `76.0.0` across:
- `composer.json`, `README.md` badge, `config/zeroboiler.php`

### What's New in v75.0.0

### Event Catalog Provider Coverage Parity — Full TikTok/LinkedIn Audit

**PHPStan Type Consistency Fix** — all event catalog entries across all 6 categories (Ecommerce, SaaS, Engagement, Security, Uptime, Infrastructure) now include `tiktok` and `linkedin` provider mapping fields. Previously, newer events added in v2.78+ (cohort analytics, billing, GDPR, product analytics) were missing these fields, causing PHPStan `EventEntry` type violations.

Changes:
- **SecurityEvents**: Added `tiktok: null, linkedin: null` to all 5 security event entries
- **UptimeEvents**: Added `tiktok: null, linkedin: null` to all 5 uptime event entries
- **InfrastructureEvents**: Added `tiktok: null, linkedin: null` to all 10 infrastructure event entries
- **SaaS Events**: Added `tiktok: null, linkedin: null` to 55 SaaS event entries that were missing provider fields
- **EngagementEvents**: Added `tiktok: null, linkedin: null` to 26 engagement event entries that were missing provider fields
- Updated PHPStan `@phpstan-type EventEntry` across Security, Uptime, and Infrastructure catalogs to include `tiktok: string|null, linkedin: string|null`

This ensures **full provider coverage parity** — every catalog entry now has consistent fields across all 9 providers (ga4, meta, posthog, plausible, mixpanel, amplitude, tiktok, linkedin).

### Version Sweep

All internal version references updated from `74.0.0` to `75.0.0` across:
- `composer.json`, `package.json`
- `AnalyticsEvent::VERSION` constant
- `resources/js/analytics.js` (3 version strings)
- `config/zeroboiler.php`, `routes/analytics.php`
- `AnalyticsServiceProvider`, `AnalyticsEventController`
- Console commands, services, and all test files

### What's New in v74.0.0

### Experiment Analysis Engine — Bayesian & Frequentist Hypothesis Testing

Comprehensive statistical analysis for A/B tests and multi-variant experiments, inspired by **Optimizely Stats Engine**, **Eppo Experiment Platform**, **Vercel Edge Analytics**, and **Google Optimize**.

**New Service: `ExperimentAnalysisEngine`**
- **Frequentist tests**: Two-proportion z-test for conversion rates, Welch's t-test for continuous/revenue metrics
- **Bayesian analysis**: Beta-Binomial Monte Carlo model (20,000 simulations) with probability of being best, probability of beating control, expected loss, 95% credible intervals
- **Effect sizes**: Relative uplift, absolute lift, Cohen's h
- **Confidence intervals**: Wilson score interval (more accurate for small samples than Wald)
- **Multi-variant corrections**: Bonferroni, Šidák, Holm-Bonferroni step-down procedure
- **Sequential testing**: O'Brien-Fleming alpha spending function with early stopping boundaries, Pocock and linear alternatives
- **Sample size planning**: MDE (Minimum Detectable Effect) calculator with Bonferroni correction for multiple comparisons
- **Revenue/metric analysis**: Two-sample t-test for ARPU, AOV, and continuous metrics
- **Quick significance**: Single-pair variant comparison for rapid checks
- **Experiment health assessment**: Sample ratio mismatch (SRM) detection, conversion count checks, traffic imbalance detection, zero-conversion variant warnings
- Configurable: alpha (0.05), power (0.80), analysis method (frequentist/bayesian/both), min sample size, max sequential peeks

**New Command: `zb:analytics:experiment`**
- Interactive CLI with 7 actions:
  - `{experimentId}` — view cached analysis results for an experiment
  - `health` — assess experiment data quality (sample size, SRM, traffic balance)
  - `sample-size` — calculate required sample size given baseline rate and MDE
  - `mde` — calculate minimum detectable effect for a given sample size
  - `sequential` — check sequential test boundaries at a given peek
  - `quick` — quick significance test for two variants
- `--json` flag for machine-readable output on all actions
- `--control`, `--method`, `--metric`, `--alpha`, `--power`, `--correction` options

**New API Endpoints:**
- `POST /api/analytics/experiments/analyze` — full Bayesian + Frequentist analysis
- `POST /api/analytics/experiments/quick-significance` — quick two-variant test
- `POST /api/analytics/experiments/sample-size` — sample size calculator
- `POST /api/analytics/experiments/mde` — MDE calculator
- `POST /api/analytics/experiments/sequential` — sequential test boundary check
- `POST /api/analytics/experiments/health` — experiment data health assessment
- `GET /api/analytics/experiments/{experimentId}` — get cached analysis
- `DELETE /api/analytics/experiments/{experimentId}` — clear cached analysis

**New Config Section:**
- `zeroboiler.analytics.experiment_analysis` — enabled, alpha, power, method, sequential_alpha_spend_rate, min_sample_size, max_sequential_peeks

**Tests:**
- `V74ExperimentAnalysisEngineTest` — 32 test cases covering class existence, constructor, configuration, full analysis structure, empty variants, caching, frequentist significance detection, missing control, Bayesian P(Best) and credible intervals, effect size computation, Wilson score intervals, multi-variant correction (Bonferroni/Šidák comparison), sequential test continuation, sample size calculator, MDE calculator, quick significance, experiment health assessment (low sample size, balanced traffic), full analysis pipeline integration, and CLI command registration

**Version Sweep:**
- All version references unified to v74.0.0 (AnalyticsEvent::VERSION, composer.json, package.json, IntegrityCommand, JS client)

### What's New in v73.0.0

### Event Audit Trail & Attribution Trail

Comprehensive audit trail for every dispatched analytics event and full UTM/referrer attribution tracking — inspired by **Segment Audit Log**, **Datadog Audit Trail**, **Mixpanel Attribution**, and **Google Attribution**.

**New Service: `EventAuditTrailService`**
- Records detailed dispatch context for each event: unique audit ID, event name, client/user identity, timestamp, per-provider dispatch results (success/failure/latency), pipeline stage timings, consent state, and source channel
- Cache-backed ring buffer with configurable retention (default 30 days) and max entries (default 10,000)
- FIFO eviction when max entries exceeded
- Search by event name, client ID, user ID, source, and time range with offset/limit pagination
- Statistics with period filtering (all/day/week/month)
- Summary with top events, sources, and failure counts
- GDPR-compliant data erasure by client ID or user ID

**New Service: `EventAttributionTrailService`**
- Tracks complete UTM and referrer journey from first touch through every touchpoint
- Per-identity records: first-touch, last-touch, multi-touch history (configurable depth, default 50), referrer chain (default 20), conversion event association
- Attribution model computation: first-touch, last-touch, linear (equal credit), time-decay (exponential with 7-day half-life)
- Cross-identity statistics: top sources, mediums, campaigns, conversion counts
- GDPR-compliant data erasure by client ID

**New Command: `zb:analytics:console`**
- Interactive analytics console for operators with multiple actions:
  - `--action=send` — send test events to all providers with per-provider latency tracking
  - `--action=audit-trail` — inspect audit trail entries
  - `--action=attribution` — view attribution trail for a specific client
  - `--action=catalog` — show event catalog overview with category breakdown
  - `--action=health` — provider health check
  - `--action=stats` — pipeline statistics including consent state
  - `--action=overview` — default dashboard with all key metrics
- `--json` flag for machine-readable output on all actions

**New API Endpoints (Event Audit Trail):**
- `GET /api/analytics/audit-trail` — recent entries (configurable limit)
- `GET /api/analytics/audit-trail/{auditId}` — specific entry
- `GET /api/analytics/audit-trail/search/{eventName}` — search with filters
- `GET /api/analytics/audit-trail/stats/{period}` — statistics
- `GET /api/analytics/audit-trail/summary` — summary
- `DELETE /api/analytics/audit-trail` — clear all
- `DELETE /api/analytics/audit-trail/client/{clientId}` — GDPR erasure
- `DELETE /api/analytics/audit-trail/user/{userId}` — GDPR erasure

**New API Endpoints (Event Attribution Trail):**
- `GET /api/analytics/attribution-trail/{clientId}` — full trail
- `GET /api/analytics/attribution-trail/{clientId}/first-touch` — first-touch data
- `GET /api/analytics/attribution-trail/{clientId}/last-touch` — last-touch data
- `GET /api/analytics/attribution-trail/{clientId}/attribute` — multi-model attribution
- `GET /api/analytics/attribution-trail/stats` — cross-identity statistics
- `DELETE /api/analytics/attribution-trail/{clientId}` — GDPR erasure

**New Config Sections:**
- `zeroboiler.analytics.audit_trail` — enabled, ttl, max_entries
- `zeroboiler.analytics.attribution_trail` — enabled, ttl, max_touch_history, max_referrer_chain

**Tests:**
- `V72AuditTrailAttributionConsoleTest` — 18 test cases covering audit trail service (enabled/disabled, record, search, statistics, count, summary), attribution trail service (enabled/disabled, empty trail, first/last touch, attribution models, statistics, count, disabled recording), version sweep, and event catalog validation

**Version Sweep:**
- All version references unified to v73.0.0 (AnalyticsEvent::VERSION, composer.json, package.json, IntegrityCommand, JS client)

### What's New in v71.0.0

### Analytics Intelligence Gateway
A unified real-time SaaS health monitoring dashboard that aggregates 12 subsystems into a single API endpoint for ops teams and executive dashboards.

**New Service: `AnalyticsIntelligenceGateway`**
- Full dashboard payload aggregating: provider health, catalog coverage, anomaly detection, funnel health, churn prediction signals, revenue health, pipeline health, data quality, provider fallback status, event budget utilization, privacy compliance, and transformation engine status
- Configurable section filtering via `include`/`exclude` options
- Overall health score (0-100) with letter grades (A+ through F) and automatic alert generation
- Lightweight heartbeat endpoint for high-frequency uptime monitoring
- Graceful degradation — each subsystem is optional, missing services don't break the gateway

**New Command: `zb:analytics:intelligence`**
- Console dashboard with provider table, catalog coverage, anomaly status, funnel rates, pipeline config, and privacy compliance
- `--json` for machine-readable output
- `--heartbeat` for lightweight health check
- `--watch` for continuous 30s polling mode
- `--sections=*` and `--exclude=*` for filtering

**New JS Client Functions:**
- `getIntelligenceDashboard(options?)` — fetch full dashboard payload with section filtering
- `getIntelligenceHeartbeat()` — fetch lightweight heartbeat for uptime monitoring
- `startIntelligenceMonitor(options?)` — real-time polling monitor with onUpdate/onAlert callbacks

**TypeScript Types:**
- `IntelligenceDashboard`, `IntelligenceHeartbeat`, `IntelligenceAlert`, `IntelligenceProviderHealth`, `IntelligenceDashboardOptions`, `IntelligenceMonitorOptions`

**Config Accessors (AnalyticsConfig):**
- `intelligenceEnabled()`, `intelligenceCacheTtl()`, `intelligenceAlertThreshold()`, `intelligenceDisabledSections()`

**Version Sweep:**
- All version references (AnalyticsEvent::VERSION, composer.json, package.json, README, IntegrityCommand, JS client, Svelte composables, TypeScript definitions) unified to v71.0.0
- Fixed AnalyticsEvent::VERSION drift (was 68.0.0, now 71.0.0)

### What's New in v70.0.0

### Event Payload Transformation Engine

Provider-specific event payload transformation system inspired by **Segment Protocols**, **RudderStack Transformations**, and **mParticle Data Planning** — the industry standard for per-provider event mapping.

**New files:**
- `DTO/EventTransformationRule` — Immutable DTO for single field transformation rules (rename, drop, cast, default, conditional)
- `DTO/ProviderEventMapping` — Groups rules for an event type targeting a specific provider
- `DTO/TransformedPayload` — Transformation result with audit trail and warnings
- `Services/EventTransformationEngine` — Core engine that applies rules per provider
- `Console/Commands/AnalyticsTransformCommand` — CLI: `zb:analytics:transform preview|preview-all|validate|list|export`

**Capabilities:**
- **Field renaming**: `item_id` → `content_id` for Meta Pixel
- **Field dropping**: Exclude sensitive/unsupported fields per provider
- **Type casting**: string `"1.99"` → float `1.99`
- **Default values**: `currency: "USD"` if missing
- **Field whitelisting**: Only include specified fields per provider
- **Event name overrides**: `form_submit` → `Lead` for Meta Pixel
- **Static overrides**: Merge static fields into every payload
- **Validation**: Detects conflicting renames, invalid cast types, duplicate fields
- **Cache-backed**: Mappings cached with configurable TTL

**New API endpoints:**
- `GET /api/analytics/transform/mappings` — List all mappings
- `GET /api/analytics/transform/mappings/event/{eventName}` — Mappings for an event
- `GET /api/analytics/transform/mappings/provider/{provider}` — Mappings for a provider
- `POST /api/analytics/transform/preview` — Preview transformation with event params
- `POST /api/analytics/transform/validate` — Validate all mappings

**Config section:** `zeroboiler.analytics.transformation`

**CLI usage:**
```bash
# Preview how 'purchase' looks for Meta Pixel
php artisan zb:analytics:transform preview --event=purchase --provider=meta

# Preview across all providers
php artisan zb:analytics:transform preview-all --event=purchase

# Validate all mappings
php artisan zb:analytics:transform validate

# List all registered mappings
php artisan zb:analytics:transform list
```

### What's New in v76.0.0

**Event Contract Testing Engine** — validates event payloads against provider-specific contracts before dispatch.

### New Features

- **EventContractTestService** — provider-specific contract validation engine
  - GA4 contracts: required params, max items (25), currency enum, transaction_id/value enforcement
  - Meta Pixel contracts: required value/currency, max content IDs (100), param length limits
  - PostHog contracts: reserved property detection ($device_id, $session_id, etc.), max properties (100)
  - Plausible contracts: no-spaces event name validation, max 10 custom properties
  - Global: max param value length (500 chars), max event name length (100 chars)
  - Per-event validation against ALL 8 providers simultaneously
  - Severity levels: reject (block dispatch), warn (log + dispatch), off
  - Full catalog validation with A+ through F coverage grading

- **AnalyticsContractCommand** — `zb:analytics:contract` CLI
  - `validate` — validate a specific event against all provider contracts
  - `catalog` — validate the entire event catalog, per-provider pass/fail breakdown
  - `coverage` — show per-provider contract coverage with top violations
  - `list` — list all registered contracts with required params
  - `test` — run a built-in test suite of 4 events (valid + invalid)

- **4 New API Endpoints**
  - `GET /api/analytics/contracts` — list all contracts, count, enabled status
  - `GET /api/analytics/contracts/catalog` — full catalog contract validation
  - `GET /api/analytics/contracts/coverage/{provider}` — per-provider coverage report
  - `POST /api/analytics/contracts/validate` — validate a specific event payload

- **Config Expansion** — new `contract_testing` section
  ```php
  'contract_testing' => [
      'enabled' => env('ANALYTICS_CONTRACT_TESTING_ENABLED', true),
      'severity' => env('ANALYTICS_CONTRACT_SEVERITY', 'warn'), // reject|warn|off
      'cache_ttl' => 3600,
  ],
  ```

### What's New in v5.0.0

**AI Analytics Intelligence, A/B Experiment Tracking, SaaS QuickStart, Full Version Sweep**

v5.0.0 adds three new industry-standard services, config expansion, and completes a full version consistency sweep across 150K+ LOC.

### AI-Powered Analytics Intelligence

The `AnalyticsAIService` provides intelligent event analysis without any external AI API dependency. Uses statistical methods: z-score anomaly detection, linear regression trend analysis, and pattern recognition.

```php
use ZeroBoiler\Analytics\Services\AnalyticsAIService;

$ai = app(AnalyticsAIService::class);

// Detect anomalies in event streams
$anomaly = $ai->detectAnomaly('purchase', 500.0);
// Returns: { event_name, expected, actual, z_score, severity, detected_at }

// Generate smart insights from event data
$insights = $ai->generateInsights([
    'events' => ['page_view' => 1000, 'sign_up' => 50, 'purchase' => 5],
]);
// Returns volume spikes, low engagement, missing lifecycle events

// Analyze trend direction
$trend = $ai->analyzeTrend([10, 12, 15, 18, 22, 27]);
// Returns: { direction: 'up', slope, velocity_percent, confidence }

// Suggest which events to track next
$suggestions = $ai->suggestEvents(['page_view', 'click', 'sign_up'], 'saas');
// Returns: { recommended, coverage_percent, total_catalog, tracked_count }
```

### A/B Experiment Tracking

The `EventExperimentTracker` provides full experiment lifecycle management with statistical significance calculation using two-proportion z-test.

```php
use ZeroBoiler\Analytics\Services\EventExperimentTracker;

$exp = app(EventExperimentTracker::class);

// Create experiment
$exp->createExperiment('cta_color', 'CTA Button Color', ['control', 'blue', 'green']);

// Track events
$exp->trackEvent('cta_color', 'blue', converted: true);
$exp->trackEvent('cta_color', 'control', converted: false);

// Check significance
$result = $exp->calculateSignificance('cta_color');
// Returns: { is_significant, confidence, p_value, z_score, winner, recommendation }

// Complete experiment
$exp->completeExperiment('cta_color', 'blue');
```

### SaaS QuickStart Service

One-call setup for standard SaaS event tracking — perfect for new Laravel projects.

```php
use ZeroBoiler\Analytics\Services\SaaSQuickStartService;

$quick = app(SaaSQuickStartService::class);

// Track individual events
$quick->trackSignUp($userId, method: 'email', referral: 'twitter');
$quick->trackTrialStart($userId, plan: 'pro', trialDays: 14);
$quick->trackSubscription($userId, plan: 'pro', revenue: 49.00);
$quick->trackPlanUpgrade($userId, fromPlan: 'starter', toPlan: 'pro');
$quick->trackCancellation($userId, reason: 'too_expensive');

// Or track the entire onboarding sequence
$quick->trackOnboardingSequence($userId, [
    'method' => 'google',
    'referral' => 'hacker_news',
    'plan' => 'pro',
    'trial_days' => 14,
]);
```

### New Config Sections

```php
// config/zeroboiler.php — AI Intelligence
'ai' => [
    'enabled' => env('ANALYTICS_AI_ENABLED', true),
    'anomaly_threshold' => (float) env('ANALYTICS_AI_ANOMALY_THRESHOLD', 2.0),
    'rolling_window' => (int) env('ANALYTICS_AI_ROLLING_WINDOW', 60),
],

// config/zeroboiler.php — Experiment Tracking
'experiment' => [
    'enabled' => env('ANALYTICS_EXPERIMENT_ENABLED', true),
    'significance_threshold' => (float) env('ANALYTICS_EXPERIMENT_SIGNIFICANCE', 0.95),
    'min_sample_size' => (int) env('ANALYTICS_EXPERIMENT_MIN_SAMPLE', 100),
],
```

### What's New in v5.0.0

**Industry Standard SaaS Analytics Maturity — Dashboard, Taxonomy, Multi-Tenant, Event Broadcasting**

v5.0.0 brings true SaaS-grade analytics capabilities with cache-backed dashboard queries, tag-based event classification, multi-tenant workspace tracking, and a Laravel event bridge for analytics events.

### Analytics Data Service (Dashboard Queries)

The `AnalyticsDataService` provides cache-backed time-series analytics for dashboard queries — no database required. Get DAU/MAU, stickiness, revenue trends, provider stats, funnel conversion, and retention in a single API call.

```php
// Full dashboard summary
$summary = app(AnalyticsDataService::class)->getDashboardSummary();
// Returns: dau, mau, stickiness, daily_revenue, monthly_revenue,
//          top_events, total_events_today, provider_stats, generated_at

// Individual queries
$dau = $dataService->getDAU();           // Active users today
$mau = $dataService->getMAU();           // Active users this month
$stickiness = $dataService->getStickiness(); // DAU/MAU ratio
$revenue = $dataService->getMonthlyRevenue('USD');
$funnel = $dataService->getFunnelConversion('signup', ['landed', 'registered', 'confirmed']);
```

### Event Taxonomy (Tag-Based Classification)

The `EventTaxonomyService` adds tag-based event classification beyond the existing category system. Events are auto-classified into tags like `revenue`, `conversion`, `acquisition`, `authentication`, `engagement`, `onboarding`, `retention`, `billing`, `compliance`.

```php
$taxonomy = app(EventTaxonomyService::class);
$taxonomy->autoClassify(); // Auto-classify all catalog events

// Query by tags
$tags = $taxonomy->getTags('purchase');           // ['revenue', 'conversion', 'ecommerce', 'billing']
$events = $taxonomy->getEventsWithAllTags(['revenue', 'conversion']); // AND filter
$events = $taxonomy->getEventsWithAnyTag(['revenue', 'compliance']);  // OR filter

// Dynamic tagging
$taxonomy->addTags('my_custom_event', ['team_alpha', 'feature_v2']);
```

### Multi-Tenant Analytics Context

Workspace-aware event tracking for multi-tenant SaaS applications. Events are automatically tagged with tenant context.

```php
$tenant = app(TenantAnalyticsContext::class);
$tenant->setTenant('workspace-123', 'Acme Corp', ['plan' => 'pro']);

// Events dispatched within this context get tenant_id, tenant_name, tenant_plan params
$eventParams = $tenant->eventContext();
// ['tenant_id' => 'workspace-123', 'tenant_name' => 'Acme Corp', 'tenant_plan' => 'pro']

// Safe scoping
$result = $tenant->withinTenant('other-workspace', 'Other', fn () => /* ... */);
// Previous context automatically restored

// Per-tenant stats
$stats = $tenant->getTenantStats('workspace-123');
$revenue = $tenant->getTenantRevenue('workspace-123');
```

### AnalyticsEventOccurred (Laravel Event Bridge)

A Laravel event dispatched after every analytics event is tracked, enabling application code to react:

```php
// Listen to all analytics events
Event::listen(AnalyticsEventOccurred::class, function (AnalyticsEventOccurred $event) {
    if ($event->analyticsEvent->name === 'purchase') {
        // Trigger webhook, update CRM, etc.
    }
});

// Enable via config
'broadcast' => ['enabled' => true, 'exclude_events' => ['page_view']],
```

### New API Endpoints

| Endpoint | Description |
|---|---|
| `GET /api/analytics/dashboard` | Full dashboard summary |
| `GET /api/analytics/dashboard/dau` | Daily active users |
| `GET /api/analytics/dashboard/mau` | Monthly active users |
| `GET /api/analytics/dashboard/stickiness` | DAU/MAU ratio |
| `GET /api/analytics/dashboard/revenue` | Revenue trends |
| `GET /api/analytics/dashboard/top-events` | Top events by count |
| `GET /api/analytics/dashboard/providers` | Provider dispatch stats |
| `GET /api/analytics/taxonomy/tags` | All taxonomy tags |
| `GET /api/analytics/taxonomy/event/{name}` | Tags for an event |
| `GET /api/analytics/tenant/{id}/stats` | Tenant statistics |
| `GET /api/analytics/tenant/{id}/revenue` | Tenant revenue |

### What's New in v4.5.0

**Config Audit API, Catalog Validator, Version Sync, Code Quality Fixes**

v4.5.0 adds admin-facing configuration audit tools, catalog-aware event validation, fixes stale JS client version strings, and resolves a duplicate docblock issue.

### Analytics Config Audit Service

The `AnalyticsConfigAuditService` provides a safe, masked dump of the current analytics configuration for debugging, admin dashboards, and compliance audits. All sensitive values (API keys, secrets, tokens) are automatically masked.

```php
use ZeroBoiler\Analytics\Services\AnalyticsConfigAuditService;

$audit = app(AnalyticsConfigAuditService::class);

// Full masked config dump
$report = $audit->audit();
// ['version' => '4.5.0', 'timestamp' => '...', 'config' => [...], 'sections' => 22, 'masked_keys' => 8]

// Provider and feature status summary
$summary = $audit->summary();
// ['providers' => ['ga4' => true, 'gtm' => false, ...], 'features' => [...], 'summary' => [...]]

// Save a snapshot for future comparison
$audit->saveSnapshot('pre-deployment');

// Load and diff against current
$snapshot = $audit->loadSnapshot('pre-deployment');
$diff = $audit->diff($snapshot['snapshot']);
// ['added' => [...], 'removed' => [...], 'changed' => [...], 'unchanged' => [...]]
```

### Config Audit API Endpoints

| Endpoint | Method | Description |
|---|---|---|
| `GET /api/analytics/config/audit` | GET | Full masked configuration dump |
| `GET /api/analytics/config/summary` | GET | Provider and feature status summary |
| `POST /api/analytics/config/snapshot` | POST | Save config snapshot (`{ "label": "pre-deploy" }`) |
| `GET /api/analytics/config/snapshot/{label}` | GET | Load saved snapshot |
| `POST /api/analytics/config/diff` | POST | Compare current against snapshot |

### Event Catalog Validator

The `EventCatalogValidator` validates incoming events against the registered EventCatalog. Provides structured error messages for invalid events, unknown names, missing required parameters, and type mismatches.

```php
use ZeroBoiler\Analytics\Services\EventCatalogValidator;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

$validator = app(EventCatalogValidator::class);

// Validate a single event
$result = $validator->validate(new AnalyticsEvent('purchase', ['currency' => 'USD', 'value' => 99.0]));
// ['valid' => true, 'event' => 'purchase', 'errors' => []]

// Validate unknown event
$result = $validator->validate(new AnalyticsEvent('my_custom_event', []));
// ['valid' => true, 'event' => 'my_custom_event', 'errors' => []] — custom events are allowed

// Catalog stats
$stats = $validator->catalogStats();
// ['total' => 130, 'ecommerce' => 15, 'saas' => 65, 'engagement' => 50, 'providers' => [...]]

// Fuzzy search suggestions
$suggestions = $validator->suggest('pur');
// [['name' => 'purchase', 'category' => 'ecommerce'], ['name' => 'refund', 'category' => 'ecommerce']]
```

### Catalog Validation API Endpoints

| Endpoint | Method | Description |
|---|---|---|
| `POST /api/analytics/catalog/validate` | POST | Validate event against catalog |
| `GET /api/analytics/catalog/stats` | GET | Catalog statistics |
| `GET /api/analytics/catalog/suggest?q=pur&limit=5` | GET | Fuzzy search suggestions |

### Bug Fixes

- **JS client version sync** — `getVersion()` now returns `'4.5.0'` (was stale at `'4.2.0'`)
- **Duplicate docblock removed** — Orphaned `trackSaaSAcquisition` docblock in AnalyticsManager cleaned up
- **TypeScript definitions version** — `@version` updated from `4.4.0` to `4.5.0`

### Service Provider Registrations

- `AnalyticsConfigAuditService` registered as singleton
- `EventCatalogValidator` registered as singleton
- Both injected into `AnalyticsEventController` for API access

### What's New in v4.4.0

**Event Cost Tracking & Notification Webhooks**

v4.4.0 adds per-provider cost estimation for analytics spending control and multi-channel webhook notifications for alert delivery.

### Event Cost Tracker

The `EventCostTracker` estimates analytics costs based on event volume and provider pricing. Supports free tiers, per-event pricing, and tiered models. Provides projected monthly cost estimates to help budget your analytics spend.

```php
use ZeroBoiler\Analytics\Services\EventCostTracker;

$costTracker = app(EventCostTracker::class);

// Full cost report for all providers
$report = $costTracker->report();
// ['enabled' => true, 'currency' => 'USD', 'providers' => [...], 'total' => [...]]

// Per-provider cost
$posthogCost = $costTracker->providerCost('posthog');
// ['events' => 150000, 'cost' => 0.0, 'projected_monthly' => 33.75, 'model' => 'per_event']

// Check if within free tier
$costTracker->isWithinFreeTier('posthog'); // true (if under 1M events)

// Most expensive provider
$expensive = $costTracker->mostExpensiveProvider();
// ['provider' => 'posthog', 'projected_monthly' => 33.75]
```

Configuration (`zeroboiler.analytics.cost_tracking`):

```env
ANALYTICS_COST_TRACKING_ENABLED=true
ANALYTICS_COST_TRACKING_CURRENCY=USD
```

Override provider pricing defaults:

```php
// config/zeroboiler.php
'cost_tracking' => [
    'enabled' => true,
    'currency' => 'USD',
    'providers' => [
        'posthog' => ['unit_cost' => 0.0003, 'free_tier' => 500000],
        'plausible' => ['unit_cost' => 0.009],
    ],
],
```

Built-in pricing defaults:
| Provider | Model | Free Tier | Cost |
|---|---|---|---|
| GA4 | Free | Unlimited | $0 |
| GTM | Free | N/A | $0 |
| Meta Pixel | Free | N/A | $0 |
| Plausible | Tiered | 0 | $9/1M events |
| PostHog | Per-event | 1M | ~$225/1M events |
| Webhook | Free | N/A | $0 |

### Cost Report Command

```bash
php artisan zb:analytics:cost-report
php artisan zb:analytics:cost-report --provider=posthog
php artisan zb:analytics:cost-report --json
```

Output includes per-provider events, current cost, projected monthly cost, model type, and free tier remaining.

### Cost API Endpoints

| Endpoint | Method | Description |
|---|---|---|
| `GET /api/analytics/cost` | GET | Full cost report for all providers |
| `GET /api/analytics/cost/{provider}` | GET | Cost for a specific provider |

### Notification Webhook Service

The `NotificationWebhookService` sends analytics alert notifications to external channels (Slack, Discord, Microsoft Teams, PagerDuty, or any HTTP webhook). Integrates with the existing EventAlertRulesService for automated alert delivery.

```php
use ZeroBoiler\Analytics\Services\NotificationWebhookService;

$service = app(NotificationWebhookService::class);

// Send an alert to all matching webhooks
$result = $service->sendAlert([
    'rule' => 'high_error_rate',
    'event' => 'purchase',
    'severity' => 'warning',
    'message' => 'Error rate exceeds 5% on purchase events',
    'triggered_at' => date('c'),
]);
// ['sent' => 2, 'failed' => 0, 'skipped' => 1, 'results' => [...]]

// Send custom notification
$service->sendCustom('slack_alerts', 'Deployment completed successfully', [
    'severity' => 'info',
    'event' => 'deploy',
]);

// Test webhook connection
$result = $service->testWebhook('slack_alerts');
// ['status' => 'sent', 'response_code' => 200, 'latency_ms' => 234.5]

// Delivery statistics
$stats = $service->deliveryStats();
// ['webhooks' => [...], 'total_sent' => 142, 'total_failed' => 3]
```

Configuration (`zeroboiler.analytics.notification_webhooks`):

```env
ANALYTICS_NOTIFICATION_WEBHOOKS_ENABLED=true
ANALYTICS_NOTIFICATION_RATE_LIMIT=60
ANALYTICS_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/T.../B.../xxx
ANALYTICS_DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/.../...
```

```php
// config/zeroboiler.php
'notification_webhooks' => [
    'enabled' => true,
    'rate_limit_seconds' => 60,
    'webhooks' => [
        'slack_alerts' => [
            'enabled' => true,
            'url' => env('ANALYTICS_SLACK_WEBHOOK_URL', ''),
            'channel' => 'slack',
            'min_severity' => 'warning',
            'events' => ['purchase', 'subscription', 'payment_failed'],
        ],
        'discord_critical' => [
            'enabled' => true,
            'url' => env('ANALYTICS_DISCORD_WEBHOOK_URL', ''),
            'channel' => 'discord',
            'min_severity' => 'elevated',
        ],
    ],
],
```

Supported channel formats:
| Channel | Format | Features |
|---|---|---|
| `slack` | Block Kit | Header, fields, color-coded severity |
| `discord` | Embed | Color-coded embeds, timestamp |
| `teams` | Adaptive Card | Theme colors, facts layout |
| `pagerduty` | Events API v2 | Severity mapping, routing key |
| `generic` | Raw JSON | Flexible custom payload |

### Notification API Endpoints

| Endpoint | Method | Description |
|---|---|---|
| `GET /api/analytics/notifications/webhooks` | GET | List configured webhooks |
| `GET /api/analytics/notifications/stats` | GET | Delivery statistics |
| `POST /api/analytics/notifications/test/{webhookName}` | POST | Test webhook connection |
| `POST /api/analytics/notifications/send` | POST | Send custom notification |

### Smart Filtering

Each webhook supports two filters to prevent noise:
- **Severity threshold**: Only sends alerts at or above the configured minimum severity (debug < info < warning < elevated < critical)
- **Event filter**: Only sends alerts for matching event names (supports wildcards like `purchase*`)

Rate limiting (configurable per-webhook) prevents alert fatigue during sustained anomalies.

### What's New in v4.3.0

**Event Budget & Throttling, Analytics Diagnostics Command, Event Sequencing Analysis**

v4.3.0 adds abuse prevention with per-client/per-user event budgets, a comprehensive diagnostics command for debugging, and expanded event sequencing analysis endpoints.

### Event Budget Service

The `EventBudgetService` enforces configurable per-client and per-user event limits with sliding windows. Prevents abuse, controls costs, and ensures fair usage across your SaaS analytics pipeline.

```php
use ZeroBoiler\Analytics\Services\EventBudgetService;

$budget = new EventBudgetService($cache, clientLimit: 1000, userLimit: 500);

// Check if an event is allowed
$result = $budget->check('client-id', 'user-id');
// ['allowed' => true, 'reason' => 'within_budget', 'policy' => 'accept', 'remaining' => [...]]

// Record an event
$budget->record('client-id', 'user-id');

// Get stats
$budget->stats();
// ['client_count' => 5, 'user_count' => 3, 'global_total' => 42, ...]
```

Configuration (`zeroboiler.analytics.budget`):

```env
ANALYTICS_BUDGET_ENABLED=true
ANALYTICS_BUDGET_CLIENT_LIMIT=1000
ANALYTICS_BUDGET_USER_LIMIT=500
ANALYTICS_BUDGET_GLOBAL_LIMIT=100000
ANALYTICS_BUDGET_OVERFLOW=reject  # or 'sample'
ANALYTICS_BUDGET_SAMPLE_RATE=0.1  # 10% when sampling
```

### Budget API Endpoints

| Endpoint | Method | Description |
|---|---|---|
| `GET /api/analytics/budget` | GET | Budget statistics overview |
| `GET /api/analytics/budget/client/{clientId}` | GET | Per-client budget status |
| `GET /api/analytics/budget/user/{userId}` | GET | Per-user budget status |
| `GET /api/analytics/budget/top-clients` | GET | Top clients by event volume |
| `DELETE /api/analytics/budget` | DELETE | Clear all budget counters |
| `DELETE /api/analytics/budget/client/{clientId}` | DELETE | Reset specific client budget |
| `DELETE /api/analytics/budget/user/{userId}` | DELETE | Reset specific user budget |

### Event Sequencing Analysis Endpoints

| Endpoint | Method | Description |
|---|---|---|
| `POST /api/analytics/correlation/matrix` | POST | Event co-occurrence matrix |
| `POST /api/analytics/correlation/conversion-rate` | POST | Sequence funnel conversion analysis |

### Analytics Diagnostics Command

A comprehensive system health check covering provider config, catalog integrity, cache, queue, identity, GDPR, consent, budget, and middleware registration.

```bash
php artisan zb:analytics:diagnostics
php artisan zb:analytics:diagnostics --check-providers
php artisan zb:analytics:diagnostics --json
```

Output shows pass/warn/fail for each check with actionable messages.

### What's New in v4.2.0

**Event Impact Analytics, Feature Adoption API, Governance Mutation Endpoints, JS Client Version Sync**

v4.2.0 bridges critical gaps between existing backend services and the API layer, adds missing governance write endpoints, and fixes stale JS client version strings.

### Event Impact Analytics API

The existing `EventImpactService` (point-biserial correlation scoring) now has dedicated REST endpoints for measuring which events most strongly predict conversion, retention, and revenue outcomes.

| Endpoint | Method | Description |
|---|---|---|
| `POST /api/analytics/impact/calculate` | POST | Full impact analysis — accepts user behavior data, returns per-event impact scores |
| `POST /api/analytics/impact/conversion-drivers` | POST | Ranked conversion driver events (top N) |
| `POST /api/analytics/impact/retention-drivers` | POST | Ranked retention driver events (top N) |

```php
use ZeroBoiler\Analytics\Services\EventImpactService;

$service = app(EventImpactService::class);

$impact = $service->calculateImpacts([
    ['user_id' => 'u1', 'events' => ['sign_up', 'feature_used', 'page_view'], 'converted' => true, 'retained' => true, 'revenue' => 99.0],
    ['user_id' => 'u2', 'events' => ['sign_up'], 'converted' => false, 'retained' => false, 'revenue' => 0],
    // ... more users
]);

// → scores sorted by impact_score, with top_conversion and top_retention identified
$converters = $service->conversionDrivers($users, 5);
$retainers = $service->retentionDrivers($users, 5);
```

### Feature Adoption Analytics API

The existing `FeatureAdoptionTracker` now exposes 6 REST endpoints for recording and querying user feature adoption profiles, funnels, streaks, and recent features.

| Endpoint | Method | Description |
|---|---|---|
| `GET /api/analytics/adoption/profile/{userId}` | GET | Full adoption profile for a user |
| `POST /api/analytics/adoption/record` | POST | Record a feature adoption event |
| `POST /api/analytics/adoption/funnel` | POST | Adoption funnel across features and users |
| `GET /api/analytics/adoption/recent/{userId}` | GET | Recently adopted features |
| `GET /api/analytics/adoption/streak/{userId}/{featureName}` | GET | Current adoption streak in days |
| `DELETE /api/analytics/adoption/profile/{userId}` | DELETE | Clear user adoption profile |

```php
use ZeroBoiler\Analytics\Services\FeatureAdoptionTracker;

$tracker = app(FeatureAdoptionTracker::class);

// Record feature adoption
$tracker->recordAdoption('u1', 'dashboard_export', ['plan' => 'pro']);

// Get adoption profile
$profile = $tracker->getProfile('u1');
// → total_features, features[], streaks[], last_activity

// Adoption funnel
$funnel = $tracker->adoptionFunnel(
    ['search', 'dashboard_export', 'api_integration', 'team_collaboration'],
    ['u1', 'u2', 'u3', 'u4', 'u5'],
);

// Streak tracking
$streak = $tracker->getStreak('u1', 'dashboard_export');
```

### Governance Mutation Endpoints

The 4 governance write endpoints that were documented in v4.1.0 but missing from route registration are now live:

| Endpoint | Method | Description |
|---|---|---|
| `POST /api/analytics/governance/register` | POST | Register a new event for governance tracking |
| `POST /api/analytics/governance/activate` | POST | Activate a draft event |
| `POST /api/analytics/governance/deprecate` | POST | Deprecate an active event |
| `POST /api/analytics/governance/retire` | POST | Retire a deprecated event |

### Bug Fixes

- **JS client version sync** — `getVersion()` and `_getInternalVersion()` now correctly return `'4.2.0'` (was stale at `'3.9.0'`)
- **TypeScript definitions version** — `analytics.d.ts` `@version` updated from `4.0.0` to `4.2.0`

### Configuration

New config section for Event Impact (optional, uses sensible defaults):

```env
ANALYTICS_EVENT_IMPACT_ENABLED=true
ANALYTICS_EVENT_IMPACT_MIN_SAMPLE_SIZE=30
```

### Tests

New tests in `V42ImpactAdoptionGovernanceRoutesTest.php` covering Event Impact API, Feature Adoption API, and Governance mutation endpoints.

### What's New in v4.1.0

**Event Governance & Data Quality Framework**

v4.1.0 adds industry-standard event governance capabilities inspired by Segment's Tracking Plan and Amplitude's Event Taxonomy. Manage the full lifecycle of analytics events — from registration and naming convention enforcement to deprecation and retirement — with data quality scoring across four dimensions.

### Event Governance Service (`EventGovernanceService`)

Central service for managing event lifecycle governance. Register events with owner, category, required/optional parameters, and track them through draft → active → deprecated → retired lifecycle stages.

Features:
- **Event registration** with naming convention validation, category enforcement, and owner tracking
- **Lifecycle management** — draft, active, deprecated, retired status transitions
- **Dispatch validation** — block or warn on retired/deprecated events, enforce required parameters
- **Governance reports** — composite governance score, catalog coverage, naming compliance, duplicate risk
- **Attention dashboard** — lists events needing action (draft or deprecated)

### Event Naming Convention Service (`EventNamingConventionService`)

Configurable event naming validator enforcing consistent event names across the platform.

Features:
- **Format enforcement** — snake_case (default), camelCase, or custom regex patterns
- **Length validation** — configurable min/max event name length
- **Reserved prefix protection** — prevents use of provider-reserved prefixes ($, zb_, amp_, etc.)
- **Custom prefix requirements** — enforce prefixes for custom (non-catalog) events
- **Catalog compliance scoring** — percentage of catalog events that pass naming validation
- **Normalization** — auto-convert event names to configured format

### Data Quality Scorer (`DataQualityScorer`)

Measures analytics data quality across four weighted dimensions with configurable scoring.

Dimensions:
- **Completeness (35%)** — percentage of events with all required parameters populated
- **Consistency (30%)** — events conform to registered schemas and naming conventions
- **Timeliness (15%)** — events dispatched within expected time windows
- **Validity (20%)** — events pass all validation rules (type, range, enum)

Features:
- **Overall quality score** (0-100) with letter grade (A/B/C/D/F)
- **Per-dimension scoring** with issue breakdown
- **Worst events list** — events sorted by quality issues (worst first)
- **Configurable weights** — adjust dimension importance per product needs

### Event Deprecation Service (`EventDeprecationService`)

Structured deprecation lifecycle management with sunset periods and replacement tracking.

Features:
- **Sunset periods** — configurable grace period (default 30 days) before retirement
- **Replacement suggestions** — specify replacement event names for deprecated events
- **Dispatch tracking** — count dispatches of deprecated events post-deprecation
- **Sunset expiry detection** — automatically mark expired deprecations
- **Undeprecate capability** — reverse a deprecation if needed

### Governance API Endpoints

| Endpoint | Method | Auth | Description |
|---|---|---|---|
| `GET /api/analytics/governance` | GET | No | Governance report and scores |
| `GET /api/analytics/governance/events` | GET | No | List registrations (filter by ?status=draft\|active\|deprecated\|retired) |
| `GET /api/analytics/governance/attention` | GET | No | Events needing action |
| `GET /api/analytics/governance/naming` | GET | No | Naming compliance score and summary |
| `GET /api/analytics/governance/quality` | GET | No | Data quality report with all dimensions |
| `GET /api/analytics/governance/deprecations` | GET | No | Deprecation warnings and expired events |
| `POST /api/analytics/governance/register` | POST | Yes | Register a new event |
| `POST /api/analytics/governance/activate` | POST | Yes | Activate a draft event |
| `POST /api/analytics/governance/deprecate` | POST | Yes | Deprecate an active event |
| `POST /api/analytics/governance/retire` | POST | Yes | Retire a deprecated event |

### Configuration

```env
ANALYTICS_GOVERNANCE_ENABLED=true
ANALYTICS_GOVERNANCE_ENFORCE=false        # block invalid events on dispatch
ANALYTICS_GOVERNANCE_NAMING_FORMAT=snake_case
ANALYTICS_GOVERNANCE_SUNSET_DAYS=30
ANALYTICS_GOVERNANCE_QUALITY_MIN=10       # min events for quality scoring
```

```php
// Register and manage events via code
use ZeroBoiler\Analytics\Services\EventGovernanceService;

$governance = app(EventGovernanceService::class);

// Register a new custom event
$governance->register('app_wizard_completed', 'engagement', 'product-team', 'User completed onboarding wizard', ['wizard_id']);

// Activate it
$governance->activate('app_wizard_completed');

// Deprecate an old event with replacement
$governance->deprecate('onboarding_done', 'app_wizard_completed');

// Check governance health
$report = $governance->report();
// → ['governance_score' => 87.5, 'naming_score' => 98.0, 'quality_score' => 92.3, ...]
```

### What's New in v4.0.0

**Event Archive Service, Analytics Replay Command, Archive API Endpoints**

v4.0.0 adds persistent event archiving for SaaS analytics debugging, replay, and compliance auditing.

### Event Archive Service (`EventArchiveService`)

Cache-backed persistent archive of all dispatched analytics events with full search, filter, and pagination support. Archives are stored using the configured cache driver (file, redis, database) with configurable TTL and FIFO eviction.

Features:
- **Search** by event name (partial match), client ID, user ID, dispatch status, time range
- **Pagination** with cursor-based offset and configurable page size
- **Event replay** — re-dispatch archived events to all active providers
- **Bulk replay** — replay all events matching filters
- **Event statistics** — per-event-name counts for admin dashboards
- **Archive management** — single event delete, full archive clear
- **Filter rules** — always/never archive lists, automatic param sanitization

**API Endpoints:**
- `GET /api/analytics/archive` — Search archived events (query params: `name`, `client_id`, `user_id`, `dispatched`, `since`, `until`, `limit`, `offset`)
- `GET /api/analytics/archive/stats` — Archive statistics (total count, per-event-name breakdown)
- `GET /api/analytics/archive/{id}` — Get single archived event details
- `POST /api/analytics/archive/{id}/replay` — Replay a single archived event
- `DELETE /api/analytics/archive` — Clear the entire archive

### Analytics Replay Command (`zb:analytics:replay`)

Artisan command for searching, inspecting, and replaying archived events from the CLI.

```bash
# List recent archived events
php artisan zb:analytics:replay list --limit=50

# Search events by name
php artisan zb:analytics:replay search --name=purchase

# Show detailed event info
php artisan zb:analytics:replay show --id=42

# Replay a single event
php artisan zb:analytics:replay replay --id=42

# Bulk replay failed events
php artisan zb:analytics:replay bulk-replay --failed-only --force

# View archive statistics
php artisan zb:analytics:replay stats

# Clear archive
php artisan zb:analytics:replay clear --force
```

### Configuration

```env
ANALYTICS_ARCHIVE_ENABLED=true
ANALYTICS_ARCHIVE_RETENTION_TTL=86400     # 24 hours
ANALYTICS_ARCHIVE_MAX_EVENTS=10000
ANALYTICS_ARCHIVE_CACHE_PREFIX=zb_archive_
```

### What's New in v3.9.0

**Event Archetype System, Config Drift Detection, k-Anonymity Aggregation**

v3.9.0 adds three production-ready services for SaaS funnel instrumentation, configuration integrity monitoring, and GDPR-compliant analytics dashboards.

### Event Archetype System (`EventArchetypeService`)

Pre-defined SaaS funnel blueprints for instrumentation gap detection, completion scoring, and industry benchmarking. Six built-in archetypes covering the complete SaaS lifecycle:

- **Signup Funnel** — Landing page → pricing view → form start → account creation → email verification → first login (acquisition)
- **Activation Funnel** — Profile completed → first feature used → search → share → integration → team created → return visit (activation)
- **Trial Conversion** — Trial started → active usage → checkout → payment info → purchase (conversion)
- **E-Commerce Checkout** — Product viewed → add to cart → cart viewed → checkout → payment → purchase (ecommerce)
- **Expansion Funnel** — Feature limit reached → pricing page → plan comparison → checkout → upgrade (growth)
- **Retention Loop** — Login → core feature → content engagement → search → goal conversion → return next week (retention)

Each archetype defines expected events with weighted scoring, timing thresholds, and optional/required flags.

**API Endpoints:**
- `GET /api/analytics/archetypes` — List all archetypes with summary
- `GET /api/analytics/archetypes/{key}` — Detailed archetype with lifecycle config
- `GET /api/analytics/archetypes/gaps` — Instrumentation gap analysis vs EventCatalog
- `POST /api/analytics/archetypes/{key}/score` — Completion score for a user's events

**Methods:** `all()`, `get()`, `keys()`, `byCategory()`, `allEventNames()`, `detectGaps()`, `completionScore()`, `toLifecycleConfig()`, `summary()`

### Config Drift Detection Service (`ConfigDriftDetectionService`)

Captures a snapshot of the analytics configuration as a baseline and compares it against the current config to detect drift between deployments.

- **Use cases:** CI/CD config validation gates, post-deployment verification, ops monitoring for accidental config changes, multi-environment consistency
- **Detection:** Added keys, removed keys, changed values, unchanged count — with full diff reporting
- **Monitoring:** Configurable `monitored_sections` to focus on specific config areas, `exclude_keys` to ignore volatile values

**API Endpoints:**
- `GET /api/analytics/config-drift` — Detect drift against stored baseline
- `GET /api/analytics/config-drift/baseline` — Get stored baseline metadata
- `POST /api/analytics/config-drift/capture` — Capture current config as baseline
- `DELETE /api/analytics/config-drift/baseline` — Clear stored baseline

**Methods:** `captureBaseline()`, `detectDrift()`, `clearBaseline()`, `hasBaseline()`, `baselineInfo()`

### Event Anonymization Aggregation Service (`EventAnonymizationAggregationService`)

k-Anonymity-safe event aggregation for GDPR-compliant public dashboards. Groups with fewer than k events are suppressed, preventing individual user identification from sparse data.

- **Aggregation levels:** By event name, by category, by time bucket (hourly/daily)
- **Privacy guarantees:** k-anonymity threshold (default k=5), optional Laplace noise injection for differential privacy, configurable timestamp rounding
- **Dashboard summary:** Single endpoint combining all aggregation levels

**API Endpoints:**
- `GET /api/analytics/anonymized/summary` — Full GDPR-safe dashboard summary
- `GET /api/analytics/anonymized/by-event` — Per-event k-anonymized counts
- `GET /api/analytics/anonymized/by-category` — Per-category k-anonymized counts
- `GET /api/analytics/anonymized/by-time` — Time-bucketed k-anonymized counts

### Admin Command

`zb:analytics:archetype-drift` — Unified command for both systems:
- `baseline` — Capture config baseline
- `drift` — Detect config drift
- `clear` — Clear baseline
- `archetypes` — List all archetypes
- `gaps` — Detect instrumentation gaps
- `score --archetype=<key> --events=*` — Calculate completion score

### Config Expansion

New config sections in `zeroboiler.php`:
- `archetypes` — Enable/disable, cache TTL, custom archetype definitions
- `config_drift` — Enable/disable, cache TTL, exclude keys, monitored sections
- `anonymized_aggregation` — Enable/disable, k-threshold, cache TTL, Laplace noise, time granularity, max event age

### Tests

30+ new tests in `V39ArchetypeDriftAnonymizationTest.php` covering all three services.

### Bug Fixes

- Fixed missing controller methods for v3.9.0 API routes (archetype, anonymized aggregation, config drift endpoints) — all 11 route handler methods added to `AnalyticsEventController`
- Fixed version inconsistency: `composer.json`, README badge, and test assertions updated to `3.9.0`

### What's New in v3.8.0

**API Form Requests & Event Tracing**

v3.8.0 adds production-grade API input validation and end-to-end event correlation tracing for the analytics pipeline.

### API FormRequest Validation Classes

Five typed FormRequest classes replace inline validation in the controller, providing proper separation of concerns, auto-generated API documentation, and structured error responses:

- **TrackEventRequest** — validates `POST /api/analytics/events` (name, params, client_id, timestamp)
- **BatchEventRequest** — validates `POST /api/analytics/batch` (events array, max 25, per-event params)
- **IdentifyRequest** — validates `POST /api/analytics/identify` (client_id required, traits optional, auth check)
- **UpdateConsentRequest** — validates `POST /api/analytics/consent` (signals in granted/denied, source tracking)
- **PageViewRequest** — validates `POST /api/analytics/pageview` (title max 500, location/referrer max 2048)

Each FormRequest includes:
- Laravel validation rules with type constraints
- Custom attribute names for user-friendly error messages
- Typed accessor methods (`eventName()`, `eventParams()`, `clientId()`, etc.)
- Consent Mode v2 signal validation (`granted`/`denied` only)

### Event Trace Context & Service

- **TraceContext DTO** — immutable readonly value object carrying a trace ID (32 hex), span ID (16 hex), optional parent span ID, and source tag
- **EventTraceService** — injects trace context into single events and batches, extracts trace from events, strips trace metadata before provider forwarding
- Batch tracing: all events in a single `POST /batch` share the same trace ID but get unique span IDs
- Child span creation preserves parent context for nested operations (queue → provider)
- Configurable via `zeroboiler.analytics.tracing` (enabled, source)

```php
use ZeroBoiler\Analytics\DTO\TraceContext;
use ZeroBoiler\Analytics\Services\EventTraceService;

// Automatic injection (done by controller if tracing enabled)
$service = app(EventTraceService::class);
$traced = $service->inject($event, 'api');

// Manual tracing
$trace = TraceContext::generate('custom_source');
$event->params = array_merge($event->params, $trace->toParams());

// Batch: all events share same trace ID
$tracedBatch = $service->injectBatch($events, 'queue');

// Extract for debugging
$context = $service->extract($traced);
echo $context->toString(); // "abc123.../def456 (parent: ...) [queue]"

// Strip before forwarding to providers
$cleanParams = $service->strip($traced->params);
```

### Other Changes
- Version sweep: `composer.json` updated from 3.6.0 → 3.8.0
- Added `tracing` config section to `zeroboiler.php`
- Registered `EventTraceService` as singleton in `AnalyticsServiceProvider`
- Updated JS client version strings (`getVersion()`, `_getInternalVersion()`)
- Updated TypeScript definitions version
- Updated Svelte composable version
- 30+ comprehensive tests for TraceContext, EventTraceService, and all FormRequest classes

### What's New in v3.7.0

**Event Enrichment, Subscription Lifecycle API, and Revenue Intelligence**

v3.7.0 adds three production-ready services for complete subscription tracking and revenue intelligence:

### Event Enrichment Service (`EventEnrichmentService`)
- Automatic server-side request context attachment to all API events
- Enriches events with IP (GDPR-anonymized), user-agent, locale, referrer, session ID (hashed), and source type
- Uses `_server_` prefix — never overwrites client-sent event parameters
- Configurable via `zeroboiler.analytics.enrichment`
- Diagnostics endpoint: `GET /api/analytics/enrichment/diagnostics`

### Subscription Lifecycle Service (`SubscriptionLifecycleService`)
- Clean API for the complete subscription lifecycle:
  - `trialStarted()`, `trialConverted()`, `trialExpired()`
  - `subscriptionCreated()`, `subscriptionRenewed()`, `subscriptionPaused()`, `subscriptionResumed()`
  - `planUpgraded()`, `planDowngraded()` (with expansion/contraction amounts)
  - `subscriptionCancelled()` (with cancellation reason and lost MRR)
  - `paymentSucceeded()`, `paymentFailed()` (with attempt tracking)
  - `billingRetry()` (with outstanding amount)
- All methods produce typed `AnalyticsEvent` objects flowing through the standard pipeline
- 13 dedicated REST endpoints under `POST /api/analytics/subscription/*`
- Revenue type tagging: `new`, `renewal`, `expansion`, `contraction`, `churn`

### Revenue Intelligence Service (`RevenueIntelligenceService`)
- Unified revenue analytics dashboard combining all revenue-related data
- Single endpoint: `GET /api/analytics/revenue/intelligence` returns:
  - Revenue overview (MRR, ARR, ARPU, subscriber count)
  - Health score (composite 0–100 with grade)
  - Churn assessment (monthly/annual rate, risk level, estimated lost MRR)
  - Forecast (30-day projected MRR/ARR, growth rate, confidence)
  - Unit economics (LTV, CAC, LTV:CAC ratio, payback period)
  - Revenue movement (new, expansion, contraction, churn breakdown)
  - Signals and recommendations (automated insights from metrics)
- Lightweight quick summary: `GET /api/analytics/revenue/quick-summary`
- Standalone signals endpoint: `GET /api/analytics/revenue/signals`
- Configurable via `zeroboiler.analytics.revenue_intelligence`

### Other Improvements
- Fixed stale version strings in JS client (`getVersion()` and `_getInternalVersion()`) — now correctly returns `3.7.0`
- Updated ServiceProvider docblock version to `3.7.0`
- Added `enrichment` and `revenue_intelligence` config sections
- 18 comprehensive tests for all new services

```php
use ZeroBoiler\Analytics\Facades\Analytics;

// Track subscription lifecycle events
Analytics::trackEvent('trial_started', [
    'user_id' => $user->id,
    'plan' => 'pro',
    'trial_days' => 14,
]);

// Or use the lifecycle service directly
$service = app(\ZeroBoiler\Analytics\Services\SubscriptionLifecycleService::class);
$event = $service->planUpgraded($user->id, 'starter', 'pro', 19.00, 49.00);
app(\ZeroBoiler\Analytics\AnalyticsManager::class)->trackEvent($event);

// Get revenue intelligence dashboard data
$intel = app(\ZeroBoiler\Analytics\Services\RevenueIntelligenceService::class);
$report = $intel->report([
    'mrr' => 25000,
    'active_subscribers' => 500,
    'churn_rate' => 0.025,
    'arpu' => 50,
]);
```

### What's New in v3.6.0

**Growth Metrics, Onboarding Wizard, and Weekly Digest Services — SaaS Product Analytics Suite**

v3.6.0 adds three production-ready services for SaaS product teams: a Growth Metrics dashboard, a step-by-step Onboarding Wizard for analytics instrumentation, and a Weekly Digest generator for scheduled executive reports.

### New Features

- **GrowthMetricsService** — Product-level growth analytics: activation rate, time-to-activate, D30 stickiness, per-feature stickiness, engagement velocity (events/user/day), cohort health (D1/D7/D30 retention), and a composite growth score (0-100, A-F grade). Cache-backed, no database required. `activationMetrics()`, `stickinessMetrics()`, `engagementVelocity()`, `cohortHealth()`, `dashboard()`, `cliSummary()`
- **OnboardingWizardService** — Guided 6-step onboarding for analytics instrumentation: Core Setup → Acquisition → Activation → Revenue → Retention → Growth. Provides progress tracking, config readiness checklist, event recommendations by priority, quick-start checklist, and an overall readiness grade (A-F). `getSteps()`, `getDetailedProgress()`, `getRecommendations()`, `getConfigChecklist()`, `getReadinessGrade()`, `getQuickStartChecklist()`, `getState()`
- **WeeklyDigestService** — Automated weekly analytics digest: event overview, provider health, SaaS funnel metrics, retention & engagement, e-commerce (conditional), growth insights with alerts. Cache-backed for 7 days. `generate()`, `latest()`, `cliSummary()`, `currentIsoWeek()`
- **EventStreamService API Expansion** — Added `getEventCount()`, `getTotalCount()`, `getRecentEvents()` methods for ring-buffer query compatibility with growth and digest services

### Changes

- Version bump: 3.5.0 → 3.6.0 (`AnalyticsEvent::VERSION`, `AnalyticsHealthCheckService::VERSION`, `composer.json`, README badge)
- ServiceProvider registrations for all three new services

### Upgrade Notes

- No breaking changes — all new services are opt-in
- Services are auto-registered via ServiceProvider as singletons
- Growth metrics and digest rely on `EventStreamService` ring buffer; no database migrations required

### What's New in v3.5.0

**SaaS Starter Industry Standard Final Upgrade — Full 12-Point Checklist Closure**

v3.5.0 is the capstone release that validates ZeroBoiler Analytics as a complete, industry-standard SaaS analytics platform. All 12 features from the original SaaS starter upgrade roadmap are fully implemented, tested, and documented. This release adds the final missing README documentation for v3.2–v3.4 and a comprehensive validation test suite.

### Changes

- **README Documentation** — Added complete "What's New" sections for v3.2.0 (Identity Resolution + Event Debounce), v3.3.0 (Svelte 5 Composables + Lifecycle Config Sync), v3.3.1 (Production Readiness Audit), and v3.4.0 (EventCollection DTO + AnalyticsEventDispatcher + Plausible/PostHog Composables). Updated Table of Contents with all version entries
- **Comprehensive Validation Test** (`V98SaaSStarterIndustryStandardFinalTest.php`) — 13,000+ assertions validating all 12 SaaS starter features: Event Catalog completeness (100+ events across 3 categories), Server-Side Lifecycle Mapper (40+ config-driven mappings), Inertia middleware prop injection (18+ prop groups), API controller coverage (130+ routes), JS client API (trackEvent, trackPageView, identify, consent, ecommerce, batch), Event Queue async dispatch, User Identity Linking (client ↔ user), E-commerce format conversion (GA4 ↔ Meta), Admin commands (overview, test, health, behavioral, dashboard), Config expansion (20+ sections), Optional providers (Plausible + PostHog), and Test coverage (227 test files)
- **Version Bump** — `AnalyticsEvent::VERSION` updated to `3.5.0` across all source files, JS client, and Svelte composables

### Upgrade Notes

- No breaking changes — all existing APIs remain backward compatible
- README now fully documents all releases from v2.88.0 through v3.5.0
- Total: 234K LOC PHP source, 7.5K LOC JS client, 1.6K LOC Svelte composables, 252 test files, 16,900+ test assertions, 130+ API routes, 100+ event classes, 8 provider trackers

### What's New in v3.4.0

**EventCollection DTO, AnalyticsEventDispatcher, Plausible/PostHog Svelte Composables**

v3.4.0 adds batch-oriented event processing, a dedicated dispatcher service, and Svelte 5 composable expansions for optional analytics providers.

### New Features

- **EventCollection DTO** — Immutable, readonly DTO for grouping multiple `AnalyticsEvent` instances for batch processing. `fromEvents()`, `count()`, `names()`, `toArray()`, `filterByPriority()`, `merge()`. Type-safe batch operations for API controller and queue workers
- **AnalyticsEventDispatcher** — Dedicated service for dispatching single events and event collections with pipeline processing. `dispatch()`, `dispatchCollection()`, `dispatchAsync()`. Configurable async/sync dispatch via queue settings
- **Plausible Svelte Composables** — `usePlausible()`, `trackPlausibleEvent()`, `trackPlausiblePageView()`, `trackPlausibleCustomEvent()` — Reactive Svelte 5 composables for Plausible Analytics integration
- **PostHog Svelte Composables** — `usePostHog()`, `trackPostHogEvent()`, `trackPostHogIdentify()`, `trackPostHogAlias()`, `trackPostHogCapture()` — Reactive Svelte 5 composables for PostHog integration with feature flag support
- **Dispatcher Config** — `zeroboiler.analytics.dispatcher` config section for async/sync mode, pipeline toggles, and batch size limits

### Tests

- New tests in `V340EventDispatcherCollectionComposablesTest.php` covering EventCollection, AnalyticsEventDispatcher, and all new Svelte composables

### What's New in v3.3.1

**Phase 2-3-4 Production Readiness Audit**

v3.3.1 is a maintenance release addressing production readiness across the entire platform.

### Changes

- Fixed syntax error in `AnalyticsInsightAggregator` (malformed array closing bracket)
- Updated 119 stale version string references from `3.0.0` to current version across all source files
- Production readiness audit across Phase 2 (identity, consent, GDPR), Phase 3 (pipeline, queue, replay), and Phase 4 (observability, monitoring)
- All version assertions in tests corrected to match current version

### What's New in v3.3.0

**Svelte 5 Composables, Lifecycle Config Sync, Version Sweep**

v3.3.0 adds a comprehensive Svelte 5 reactive composable layer and lifecycle configuration synchronization.

### New Features

- **Svelte 5 Composables** (`useAnalytics.svelte.js`) — Full reactive analytics layer using Svelte 5 runes (`$state`, `$derived`, `$effect`). `useAnalytics(page)` — auto-initializing composable with Inertia page prop sync. `isReady`, `trackingId`, `userId`, `isAuthenticated`, `debug` derived states. Auto-cleanup on component unmount via `$effect` garbage collection
- **Ecommerce Composable** — `useEcommerce()` — Reactive e-commerce tracking with `trackViewItem()`, `trackAddToCart()`, `trackBeginCheckout()`, `trackPurchase()`. Currency and brand awareness from Inertia props
- **Consent Composable** — `useConsent()` — Reactive consent management with `grant()`, `deny()`, `update()`, `isGranted()`, `purposes()` derived states. Auto-sync with Inertia consent props
- **Lifecycle Config Sync** — Server-side lifecycle event mapper configuration automatically exposed to Inertia props for client-side event name awareness. Version hash for change detection
- **Version Bump to 3.3.0** — All version strings swept across PHP source, JS client, Svelte composables, and tests

### What's New in v3.2.0

**Identity Resolution Service + Event Debounce Service**

v3.2.0 adds two critical production services for user identity management and event volume control.

### New Features

- **Identity Resolution Service** (`IdentityResolutionService`) — Cache-backed persistent mapping between client IDs and user IDs. `link()`, `resolve()`, `forget()`, `getUser()`, `getClient()`, `getHistory()`. Supports multiple client IDs per user (cross-device tracking) with configurable TTL and max links. Automatic cleanup of expired mappings
- **Event Debounce Service** (`EventDebounceService`) — Prevents duplicate rapid-fire events within configurable time windows. Per-event-name debounce with separate windows for different event types. `shouldDebounce()`, `record()`, `reset()`, `getStats()`. Cache-backed, no database required
- **Identity API Endpoints** — `GET /api/analytics/identity/{clientId}`, `GET /api/analytics/identity/user/{userId}`, `POST /api/analytics/identity/resolve`, `DELETE /api/analytics/identity/{clientId}`, `DELETE /api/analytics/identity/user/{userId}`
- **Identity Config** — `zeroboiler.analytics.identity` expanded with `cache_prefix`, `link_ttl`, `max_links_per_user`, `max_links_per_client`
- **Debounce Config** — `zeroboiler.analytics.debounce` section with `enabled`, `window_seconds`

### Tests

- New tests in `Feature/Services/IdentityResolutionServiceTest.php` and `Feature/Services/EventDebounceServiceTest.php`

### What's New in v3.1.0

**Behavioral Analytics Engine — Product Intelligence for SaaS**

v3.1.0 adds industry-standard behavioral analytics: event rules engine, user properties store, N-Day retention calculator, and behavioral cohort builder. These features bring ZeroBoiler Analytics to parity with dedicated product analytics platforms like Amplitude and Mixpanel.

### New Features

- **Event Rules Engine** (`EventRulesEngine`) — Config-driven behavioral automation with three rule types: event_trigger (when X fires, also fire Y), absence_trigger (when X hasn't fired in N seconds, fire Y), property_trigger (when user property reaches threshold, fire Y). Evaluated on every event dispatch. Configure in `zeroboiler.analytics.rules`
- **User Properties Store** (`UserPropertiesStore`) — Cache-backed persistent user traits with schema-defined types and aggregation strategies (sum, min, max, last, set, count). Supports identity linking (client_id ↔ user_id) with automatic property merge on authentication. Config-driven schema in `zeroboiler.analytics.user_properties`
- **N-Day Retention & Stickiness Calculator** (`RetentionCalculator`) — Industry-standard retention metrics: N-Day Retention (D1/D3/D7/D14/D30), Rolling Retention (cumulative within window), Stickiness (DAU/WAU/MAU ratios with letter grades), Retention Curve (day-by-day data for charts), Cohort Comparison (multi-cohort retention averages). Cache-backed, no database required
- **Behavioral Cohort Builder** (`BehavioralCohortBuilder`) — Automatic user segmentation by behavioral patterns: Power Users (5+ days/week), Regular (3-4 days), Casual (1-2 days), At-Risk (inactive 7+ days), Dormant (inactive 30+ days), New Users (first seen ≤7 days), Resurrected (were dormant, returned). Supports custom cohort definitions via config. Cached results for performance
- **Admin Command** (`zb:analytics:behavioral`) — Displays retention metrics, stickiness grades, cohort distribution with visual bars, rules engine status, and user properties schema. Supports section filtering: `--retention`, `--stickiness`, `--cohorts`, `--rules`, `--properties`

### New API Endpoints (28 new routes)

- `GET /api/analytics/rules` — List event rules and trigger counts
- `POST /api/analytics/rules/evaluate` — Evaluate rules against a test event
- `GET /api/analytics/rules/absence` — Evaluate absence-trigger rules
- `GET /api/analytics/rules/counts` — Rule trigger counts
- `GET|POST|DELETE /api/analytics/user-properties/{identity}` — CRUD user properties
- `POST /api/analytics/user-properties/{identity}/merge` — Batch merge
- `POST /api/analytics/user-properties/{identity}/increment` — Increment counter
- `POST /api/analytics/user-properties/link` — Link client ↔ user identity
- `GET /api/analytics/retention` — Overall N-Day retention
- `GET /api/analytics/retention/{date}` — Cohort-specific retention
- `GET /api/analytics/retention/{date}/rolling/{days}` — Rolling retention
- `GET /api/analytics/retention/{date}/curve` — Full retention curve
- `GET /api/analytics/retention/cohorts/{days}` — Multi-cohort comparison
- `GET /api/analytics/stickiness` — DAU/WAU/MAU stickiness
- `GET /api/analytics/cohorts` — Full behavioral cohort classification
- `GET /api/analytics/cohorts/{identity}` — Per-user cohort assignment
- `GET /api/analytics/cohorts/summary/{days}` — Cohort summary
- `GET /api/analytics/cohorts/transitions/{daysAgo}` — Cohort transitions

### New Config Sections

- `zeroboiler.analytics.rules` — Event rules engine (enabled, debug, rules)
- `zeroboiler.analytics.user_properties` — User properties store (enabled, debug, ttl, schema)
- `zeroboiler.analytics.retention_analytics` — Retention calculator (enabled, debug, ttl, retention_days)
- `zeroboiler.analytics.cohorts` — Cohort builder (enabled, debug, result_ttl, custom_cohorts)

### Tests

- **28 new tests** in `V301BehavioralAnalyticsTest.php` covering all four new services

### Upgrade Notes

- No breaking changes — all existing APIs remain backward compatible
- New services are registered as singletons in the service provider
- Config sections are optional — sensible defaults provided
- Event rules engine is disabled by default — enable with `ANALYTICS_RULES_ENABLED=true`

### What's New in v3.0.0

**Major Release — Production-Grade Analytics Platform**

v3.0.0 marks the graduation from "SaaS analytics starter" to a full production-grade analytics platform. 123K+ LOC, 100+ services, 90+ event classes, 6 provider trackers, 130+ API routes, and a comprehensive 5K+ line JS client.

### New Features

- **EventContext DTO** — Immutable, readonly DTO for HTTP request → analytics event context resolution. Captures client identity, user identity, device info, UTM parameters, referrer, session, locale, geolocation, and consent state. `EventContext::fromRequest()` auto-extracts from any Illuminate HTTP request. `toParams()` flattens into event-enrichable params. `with()` for immutable copy with overrides. Zero-allocation reads for high-throughput scenarios
- **HasEventSchema Trait** — Reusable trait for event classes providing schema-aware validation. Required param checks, param type validation (`string`, `int`, `float`, `bool`, `array`), max param count enforcement. `buildEvent()` creates validated `AnalyticsEvent` DTOs with client/user identity. Type-safe param extractors: `stringParam()`, `intParam()`, `floatParam()`, `boolParam()`, `arrayParam()` with defaults. Promotes DRY across 90+ event classes
- **EventContextResolver Service** — Centralized, config-driven context resolution from HTTP requests. Resolves client ID from identity cookie, user ID from auth guard, UTM params from query, device info from User-Agent (browser, OS, device type detection). Builds Inertia page props (`zbAnalytics`) for frontend JS client. Cookie config accessor for middleware. Client ID generation (UUID v4)

### Improvements

- **Version 3.0.0** — Complete version consistency sweep across all 50+ source files, config, JS client, TypeScript definitions, controllers, routes, and tests
- **All runtime version strings** now return `3.0.0` — `AnalyticsManager::version()`, `AnalyticsEvent::VERSION`, `EventSchemaVersioningService`, `EventEnvelopeService`, `EventForwardingService`, `EventSourceTagger`, `EventCacheService`, `EventExporterService`, `EventAliasResolver`, `AnalyticsHealthCheckService`, `SessionReplayService`, `AdvancedPIIDetector`, `SaaSMetricsBenchmarkService`, SSE/Event controllers
- **Service Provider** registers `EventContextResolver` as singleton for dependency injection
- **30+ new tests** in `V300EventContextSchemaTraitTest.php` covering EventContext, EventCatalog, and HasEventSchema

### Upgrade Notes

- No breaking changes — all existing APIs remain backward compatible
- `EventContextResolver` is available via dependency injection: `app(EventContextResolver::class)`
- `HasEventSchema` trait is opt-in for event classes — existing event classes continue to work without it
- Minimum PHP requirement remains 8.5
- Minimum Laravel requirement remains 13.0

### What's New in v2.98.0

- **EventBuilder (Fluent API)** — Type-safe, declarative event construction with catalog-aware validation. Static factories for common events: `EventBuilder::purchase()`, `::signUp()`, `::pageView()`. Chain `->param()`, `->params()`, `->items()`, `->client()`, `->user()`, `->priority()`, and finish with `->build()`, `->dispatch()`, or `->dispatchAsync()`
- **SessionReplayService** — Cache-based session event recording for user journey reconstruction. Ring buffer (configurable max events + TTL), timeline with duration/summary, per-user session indexing, revenue/error event detection. Ideal for support debugging and behavior analysis
- **AdvancedPIIDetector** — Regex-based PII detection engine with 14 built-in patterns (email, phone US/intl, credit card Visa/MC/Amex, SSN, IBAN, JWT, IPv4/IPv6, address, ZIP). Field name heuristics for 30+ PII keys. Configurable confidence threshold and `redact()` with first/last character preservation
- **Config: Session Replay** — `ANALYTICS_SESSION_REPLAY_ENABLED` (default: false), `ANALYTICS_SESSION_REPLAY_MAX_EVENTS` (200), `ANALYTICS_SESSION_REPLAY_TTL` (3600)
- **Config: PII Detection** — `ANALYTICS_PII_DETECTION_ENABLED` (default: false), `ANALYTICS_PII_DETECTION_THRESHOLD` (0.5), `ANALYTICS_PII_DETECTION_CUSTOM_PATTERNS`
- **Version bump to 2.98.0** — 23 files updated across PHP source, tests, JS client, TypeScript definitions, config, and documentation

### What's New in v2.97.0

- **Comprehensive Health Check Service** — `AnalyticsHealthCheckService` runs a full diagnostic across 12 subsystems: providers, catalog, AARRR coverage, identity tracking, queue, GDPR, consent mode, lifecycle mapper, auto-tracking, dedup, API, and pipeline. Returns per-subsystem scores, overall health status, and prioritized recommendations
- **Health Check API Endpoints** — `GET /api/analytics/health-check` for full diagnostic, `GET /api/analytics/ping` for lightweight monitoring (version + provider count + catalog size). Both public endpoints for dashboards and uptime monitoring
- **AnalyticsManager Convenience Methods** — `Analytics::healthCheck()` and `Analytics::ping()` for programmatic access from application code
- **Facade Annotations** — `healthCheck()`, `ping()`, `maturityScore()`, `onboardingChecklist()`, `funnelReadiness()` documented in `@method` annotations for IDE autocomplete
- **Service Registration** — `AnalyticsHealthCheckService` registered as singleton in ServiceProvider for dependency injection
- **Comprehensive Test Suite** — 25 new tests covering all health check subsystems, recommendation sorting, status determination, version consistency, and facade delegation
- **Version bump to 2.97.0** — All version assertions across source, config, JS client, TypeScript definitions, and tests updated

### What's New in v2.96.0

- **SaaS Lifecycle Convenience Methods** — `Analytics::signUp()`, `Analytics::login()` (with auto identity linking), `Analytics::trialStart()`, `Analytics::subscription()`, `Analytics::planUpgrade()`, `Analytics::cancellation()` — the 6 essential SaaS events as one-liners on the AnalyticsManager and Facade
- **SaaS Acquisition Funnel Shortcut** — `Analytics::trackSaaSAcquisition()` fires the full signup → trial → subscribe sequence in a single call, with `skip_trial` and custom params support
- **Inertia Page View Auto-Tracker** — `initInertiaPageViewTracker()` JS function with framework-agnostic Inertia navigation hooks (`inertia:navigate`, `inertia:success`, `popstate`), optional scroll depth, callback support, and proper cleanup. Works with Svelte, Vue, and React adapters
- **Fixed `initSvelteTracker` cleanup** — Event listeners now properly remove on component unmount instead of relying on broken `addEventListener` return value
- **TypeScript `InertiaPageViewTrackerOptions`** — Full type definitions for the new auto-tracker options
- **Facade annotations** — All new SaaS methods documented in `@method` annotations for IDE autocomplete
- **Admin command features** — Overview command updated with SaaS lifecycle convenience methods
- **Version bump to 2.96.0** — All version assertions across source, config, JS client, TypeScript definitions, and tests updated

### What's New in v2.95.0

- **Server-Sent Events (SSE) Controller** — Real-time event streaming via persistent HTTP connections with cursor-based resume, event filtering, category filtering, and configurable heartbeat. Endpoints: `GET /api/analytics/sse`, `/sse/info`, `/sse/health`
- **EventWindowAggregator** — Time-windowed event counting (minute/hour/day) in cache for dashboard sparkline charts. Provides `lastNMinutes()`, `lastNHours()`, `lastNDays()` with configurable TTLs
- **FeatureAdoptionTracker** — Per-user feature adoption tracking with streak detection, adoption funnels, and PLG (product-led growth) analysis. Tracks first/last use timestamps and use counts per feature
- **AnalyticsApiGuard** — Centralized API request validation with payload size limits, event name length checks, batch size limits, and per-client sliding window rate limiting
- **JS Client SSE support** — `connectSSE()` function using native EventSource API with `onEvent`, `onHeartbeat`, `onClose` callbacks. Plus `fetchSSEInfo()` and `fetchSSEHealth()` helpers
- **TypeScript definitions** — Full type coverage for SSE (SSEInfo, SSEEventData, SSEConnection, SSEConnectOptions), Feature Adoption (FeatureAdoptionProfile, FeatureAdoptionFunnelStep)
- **Config expansion** — New `sse`, `windowed_aggregation`, `feature_adoption`, and `api_guard` config sections with env-driven settings
- **EventStreamService enhancements** — Added `getEventsSince()`, `getBufferSize()`, `getCurrentCount()`, `getCurrentCursor()`, `getBufferUtilization()` for SSE controller integration
- **Version bump to 2.95.0** — All version assertions across source, config, JS client, TypeScript definitions, and tests updated

### What's New in v2.94.0

- **SchemaDrivenEventBuilder** — Schema-driven event builder that validates parameters against EventPropertySchema and EventSchemaRegistry for type coercion, default values, and required field enforcement
- **SchemaDiffReporter** — Schema coverage and diff reporter comparing EventCatalog, EventPropertySchema, and EventSchemaRegistry for gap analysis
- **EventPropertySchema::registerBuiltInSchemas()** — Full catalog schema coverage with typed property schemas for all e-commerce, SaaS, engagement, and lifecycle events
- **AnalyticsSchemaExportCommand** — `zb:analytics:schema:export` Artisan command to export event schemas as JSON, TypeScript, or summary with optional coverage report
- **Phase 2-3-4 production readiness audit** — Tests updated for new services and commands (9 console commands, SchemaDrivenEventBuilder, SchemaDiffReporter finality)

### What's New in v2.93.0

- **FunnelProgressTracker** — Cache-persisted funnel progress tracking with completion percentage, step timing, automatic advancement/regression detection, and `funnel_step`/`funnel_completed` event dispatch. Configurable TTL and known funnel names.
- **AnalyticsManager::funnelProgress()** — Convenience method that delegates to FunnelProgressTracker for stateful funnel tracking
- **Funnel progress config** — New `funnel_progress` config section with `ANALYTICS_FUNNEL_PROGRESS_ENABLED` toggle and customizable `known_funnels` list
- **Facade** — `Analytics::funnelProgress()` documented in Facade @method annotations
- **Version bump to 2.93.0** — All 100+ version assertions across source, config, JS client, TypeScript definitions, and tests updated
- **22 new tests** — V93FunnelProgressTrackerTest covering FunnelProgressTracker structure, public method signatures, functional behavior (advancement, regression, completion), config integration, AnalyticsManager delegation, ServiceProvider registration, and version consistency

### What's New in v2.92.0

- **Onboarding Completion Service** — OnboardingCompletionService tracks multi-step user onboarding with configurable required/optional milestones, time-to-completion tracking, completion percentage, and automatic `onboarding_completed` event dispatch
- **EventCatalog::enterpriseComplianceEvents()** — GDPR Article 30, SOC2 CC7, ISO 27001 compliance event set for enterprise audit trails (24 events)
- **EventCatalog::dauMauEvents()** — DAU/MAU stickiness tracking event set (8 core engagement events)
- **EventCatalog::productHealthEvents()** — Product stability, quality, and system health monitoring events
- **Onboarding tracking config** — New `onboarding_tracking` config section with required/optional steps, cache TTL, and prefix
- **Version bump to 2.92.0** — Config, ServiceProvider, schema versioning updated
- **13 new tests** — V92OnboardingComplianceDauMauTest covering new catalog methods, OnboardingCompletionService, version consistency, and file quality

### What's New in v2.91.0

- **Privacy Sandbox & Cart Affinity** — PrivacySandboxService for first-party data strategies, CartStateManager for abandoned cart analytics with item-level tracking
- **Event Affinity Service** — EventAffinityService for cross-event correlation and user behavior pattern detection
- **License header normalization** — FeatureFlagIntegrationService updated to use standard ZeroBoiler license header
- **Version consistency sweep** — All 158 hardcoded version assertions across test files updated to 2.91.0
- **Stale version reference cleanup** — V43 stale version guard updated to check for removed 2.90.0 references

### What's New in v2.88.0

- **Phase 2-3-4 production readiness** — Comprehensive Phase234ProductionTest expanded from 6 to 40+ assertions: strict types, license headers, return types on all public methods (AnalyticsManager, AnalyticsMetrics, EventInterceptorRegistry), final class audit (core classes, DTOs, trackers, enterprise services), #[Override] validation, TrackerInterface compliance, DTO readonly checks, EventPriority enum integrity, version consistency across all sources, config section completeness, Facade @method documentation, ServiceProvider binding audit (80+ singletons, 7 commands)
- **Version consistency fix** — All hardcoded VERSION assertions across 15+ test files updated to match current version
- **CHANGES.md removed** — Single source of truth is CHANGELOG.md

### What's New in v2.87.0

- **ExportEvent & ImportEvent** — Data portability tracking for GDPR compliance monitoring, churn prediction (exports often precede cancellation), and power user identification (high imports = active usage)
- **EventCatalog::quickStart()** — 12 essential "hello world" events every SaaS should track on day one, with funnel coverage analysis (signup, trial, revenue, engagement)
- **EventCatalog::privacySafeEvents()** — Events safe to track without PII (18 behavioral/aggregate events for privacy-first and cookieless implementations)
- **EventCatalog::gdprSensitiveEvents()** — Events that carry PII and need extra consent gating (16 auth, billing, and profile events)
- **EventCatalog::saasAcquisitionEvents()** — Acquisition-focused events for marketing analytics and CAC calculations
- **EventCatalog::saasMonetizationEvents()** — 21 revenue-focused events for LTV, MRR, and revenue analytics
- **AnalyticsManager::trackFunnel()** — Multi-step funnel tracking with funnel name, step, and progress metadata
- **AnalyticsManager::exportEvent() / importEvent()** — Convenience methods for data portability events
- **JS client: trackExport(), trackImport(), getQuickStartEvents()** — Client-side data portability tracking and quick-start accessor
- **45+ new tests** — V86QuickStartPrivacyTest covering new events, privacy sets, acquisition/monetization helpers, catalog integrity
- **Total events: 92** (E-commerce 15, SaaS 52, Engagement 27)

- **EventCatalog::b2bTeamEvents()** — B2B/team and organization-level events (team_created, team_member_joined, workspace_created, invite_sent, role_changed)
- **EventCatalog::accountLifecycleEvents()** — Full account lifecycle events (activation, deactivation, security, profile)
- **EventCatalog::allProviderMappingsMatrix()** — Complete cross-provider mapping matrix for all catalog events (GA4, Meta, PostHog, Plausible)
- **EventCatalog::industryReadinessScore()** — Industry-standard readiness score (0-100) with tier breakdowns and gap analysis
- **Identity cookie domain support** — `ANALYTICS_IDENTITY_COOKIE_DOMAIN` for cross-subdomain tracking (`.example.com`)
- **Inertia middleware cookie domain passthrough** — Respects `identity.cookie_domain` config when setting the tracking ID cookie
- **45 new tests** — V85IndustryStandardUpgradeTest with comprehensive catalog, funnel, and industry-standard assertions
- **README accuracy update** — Event counts updated (15 e-commerce, 48 SaaS, 27 engagement, 90 total)

## Features

### Multi-Provider Tracking
- **GA4** — Measurement Protocol (server-side) + gtag.js (client-side), debug/validation endpoint
- **GTM** — dataLayer push + ecommerce events
- **Meta Pixel** — Conversions API (CAPI/server) + fbq.js (client)
- **Plausible Analytics** — Privacy-focused server-side tracking
- **PostHog** — Product analytics with $set, $create_alias, $reset
- All trackers implement `TrackerInterface` for easy extension

### Event System
- **92 typed event classes** across 3 categories (E-commerce 15, SaaS 52, Engagement 27)
- **EventCatalog** — Unified registry for event lookup, cross-provider name mapping, category filtering, funnel helpers (checkout, activation, retention, billing, PLG, AARRR lifecycle)
- **EventSchemaRegistry** — 50+ event schemas with typed parameters, validation, and custom schema registration
- **CustomEvent** — Arbitrary event name + params for one-off tracking

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
- **52 SaaS Events** — SignUp, Login, Logout, TrialStart, TrialEnd, TrialConverted, Subscription, SubscriptionResumed, SubscriptionPaused, PlanUpgrade, PlanDowngrade, Cancellation, FeatureUsed, Revenue, InviteSent, IntegrationConnected, SubscriptionRenewal, + MilestoneReached, + SubscriptionValueChanged, + UsageQuotaReached, + BillingRetry, + 6 Cohort events (Assigned, Retention, Churn, Conversion, Migration, Engagement), + 6 Account lifecycle (Activated, Deactivated, PasswordChanged, PasswordReset, ProfileUpdated, EmailVerified), + 4 B2B/Team (TeamCreated, TeamMemberJoined, TeamMemberRemoved, RoleChanged), + 5 Billing (PaymentFailed, PaymentSucceeded, PaymentMethodAdded, InvoiceGenerated, CreditApplied), + FeatureAdopted, ExpansionRevenue, + Export, Import
- **6 Dedicated Cohort Typed Classes** — CohortAssignedEvent, CohortRetentionEvent, CohortChurnEvent, CohortConversionEvent, CohortMigrationEvent, CohortEngagementEvent (all 92 events now have typed classes)
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

### E-commerce
- **13 E-commerce Events** — ViewItem, AddToCart, RemoveFromCart, ViewCart, BeginCheckout, AddPaymentInfo, Purchase, Refund, Wishlist, SelectItem, SelectPromotion, ViewPromotion, CheckoutStep
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
- **Facade** — `Analytics::track()`, `Analytics::purchase()`, `Analytics::identify()`, `Analytics::pageView()`, `Analytics::trackError()`, `Analytics::mrr()`, `Analytics::resolveEventName()`, `Analytics::trackWithAlias()`, and 30+ more methods
- **Config-Driven** — 35+ environment variables, sensible defaults, zero-required-config to start
- **Metrics & Observability** — Per-provider dispatch/failure counters for monitoring and debugging
- **PII Sanitization** — Auto-hash, remove, or mask sensitive data before dispatch
- **Event Sampling** — Control analytics volume with configurable sample rates
- **Anonymous ID Tracking** — Persistent UUID-based client identifiers with cookie management
- **AnalyticsConfig** — Type-safe config accessor with 110+ typed methods (no raw array access)
- **EventTransformer** — Centralized GA4 ↔ Meta ↔ PostHog event format conversion
- **EventAliasResolver** — 100+ built-in event name aliases (signup→sign_up, addtocart→add_to_cart, CamelCase→snake_case) with custom config aliases
- **EventCacheService** — L1 memory + L2 Laravel cache for high-performance event lookups and format conversions
- **AnalyticsEventNameRule** — Laravel validation rule for analytics event names
- **AnalyticsRateLimiter** — Per-client rate limiting (client ID / IP based)
- **WebhookSignatureValidator** — HMAC-SHA256 webhook signature validation
- **PHPStan 9** — Level max, full type coverage
- **Pest PHP** — 300+ tests across 232 test files
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
├── AnalyticsManager.php              # Core manager — dispatches to all 6 trackers
├── AnalyticsMetrics.php              # Dispatch metrics (per-provider counters for observability)
├── AnalyticsServiceProvider.php       # Laravel service provider (registers everything)
├── Bus/
│   └── AnalyticsDataBus.php          # Rule-based conditional event routing
├── Context/
│   └── EventContextBuilder.php        # Auto-collect request context (user, UTM, session, device)
├── DTO/
│   ├── AnalyticsEvent.php            # Immutable event DTO (name, params, clientId, userId)
│   ├── ConsentState.php              # GDPR consent state (6 granular signals)
│   ├── EventContextEvent.php          # Context-rich event envelope
│   ├── EventPriority.php             # Event priority levels (critical/normal/low/background)
│   └── UtmAttribution.php            # UTM campaign attribution DTO
├── Events/
│   ├── Ecommerce/                    # 13 e-commerce event classes + EcommerceEvents catalog
│   ├── SaaS/                         # 43 SaaS event classes + SaaSEvents catalog
│   ├── Engagement/                   # 25 engagement event classes + EngagementEvents catalog
│   ├── CustomEvent.php               # Generic custom event
│   └── EventCatalog.php              # Unified catalog (81 events, cross-provider mappings)
├── Middleware/
│   ├── AnalyticsMiddlewareInterface.php   # Middleware contract
│   ├── AnalyticsMiddlewareStack.php       # Priority-ordered middleware stack
│   ├── ConsentGateMiddleware.php          # Consent-based event filtering
│   ├── ContextAttachmentMiddleware.php    # Auto-attach context to events
│   ├── SchemaValidationMiddleware.php     # Schema-aware event validation
│   ├── PiiSanitizationMiddleware.php      # PII auto-scrub (hash, remove, mask)
│   ├── TimestampMiddleware.php            # Auto-add timestamps
│   └── LoggingMiddleware.php              # Debug event logging
├── Schema/
│   ├── EventSchema.php             # Event parameter schema definition
│   ├── EventParam.php              # Parameter type & constraints
│   └── EventSchemaRegistry.php    # Central schema registry (55+ events)
├── Pipeline/
│   ├── EventPipeline.php            # Middleware pipeline for event processing
│   ├── SamplingFilter.php           # Probabilistic event sampling
│   ├── EventDebounceFilter.php      # Debounce rapid-fire events (scroll, resize)
│   ├── UtmEnricher.php              # UTM campaign parameter enrichment
│   ├── UserContextEnricher.php      # User context enrichment
│   ├── ConsentFilter.php            # Consent-based event filtering
│   └── TimestampEnricher.php        # Timestamp & session enrichment
├── Trackers/
│   ├── GA4Tracker.php               # GA4 Measurement Protocol + debug endpoint
│   ├── GTMTracker.php               # GTM dataLayer push
│   ├── MetaPixelTracker.php         # Meta Pixel CAPI
│   ├── PlausibleTracker.php         # Plausible Analytics
│   ├── PosthogTracker.php           # PostHog ($capture, $set, $create_alias, $reset)
│   ├── TrackerInterface.php         # Common tracker contract
│   └── TrackerHelpers.php           # Shared consent helpers
├── Services/
│   ├── GoogleAnalyticsService.php        # GA4 convenience wrapper
│   ├── GoogleTagManagerService.php      # GTM convenience wrapper
│   ├── MetaPixelService.php             # Meta convenience wrapper
│   ├── EcommerceAnalyticsService.php     # Full e-commerce flow methods
│   ├── SaaSAnalyticsService.php         # SaaS lifecycle convenience methods
│   ├── RevenueAnalyticsService.php      # Revenue tracking (MRR, ARR, churn)
│   ├── RevenueAttributionService.php    # Revenue attribution, LTV, cohort analysis
│   ├── FunnelAnalyticsService.php       # Conversion funnel tracking
│   ├── AnalyticsStatsService.php        # Dashboard aggregation (totals, top events, by-provider)
│   ├── InboundWebhookService.php        # External event ingestion (Stripe, custom integrations)
│   ├── EventValidationService.php      # Event validation & deduplication
│   ├── SessionAnalyticsService.php     # Session-level event aggregation & summaries
│   ├── EventAggregationService.php     # Real-time event counting & health diagnostics
│   ├── AnalyticsHealthService.php      # Programmatic health-check (report, isHealthy)
│   ├── ConsentLogService.php          # GDPR consent audit trail & DSAR export
│   ├── EventAliasResolver.php         # Event name alias resolution (100+ built-in aliases)
│   ├── EventCacheService.php          # L1 memory + L2 cache for event lookups
│   ├── EventBucketsService.php        # Time-binned event aggregation (minute/day/week/month)
│   └── SaaSHealthScoreService.php     # Composite SaaS health score (0-100, A-F grading)
├── Tracking/
│   ├── ServerSideTracker.php       # Auto-track Laravel auth events + custom app events
│   ├── UserIdentityTracker.php     # User ↔ client linking (login, register, logout)
│   ├── AnonymousIdTracker.php     # Persistent UUID anonymous ID management + cookies
│   └── SessionTracker.php          # Session, funnel, and conversion tracking
├── Queue/
│   ├── QueuedAnalyticsDispatcher.php   # Async queue dispatch (configurable)
│   └── EventReplayQueue.php             # Failed event retry with exponential backoff
├── Http/
│   └── Controllers/AnalyticsEventController.php  # 50+ API endpoints + event pipeline
│   └── Middleware/InjectAnalyticsScripts.php       # Auto-inject analytics scripts
├── Inertia/
│   └── HandleInertiaAnalytics.php   # Inertia page prop injection + tracking ID cookie
├── Console/Commands/
│   ├── AnalyticsOverviewCommand.php  # Config overview
│   ├── AnalyticsTestCommand.php     # Test event dispatch
│   ├── AnalyticsExportCommand.php   # Export catalog as JSON/CSV/Markdown
│   ├── RevenueReportCommand.php      # Revenue analytics report
│   ├── AnalyticsHealthCommand.php    # Comprehensive health diagnostic
│   ├── AnalyticsDashboardCommand.php # Dashboard data export (JSON/table)
│   ├── AnalyticsArchetypeDriftCommand.php # Archetypes + config drift (v3.9.0)
├── Support/
│   ├── AnalyticsConfig.php              # Type-safe config accessor (120+ methods)
│   ├── AnalyticsEventNameRule.php       # Laravel validation rule for event names
│   ├── EventTransformer.php             # Cross-provider event format conversion
│   ├── EcommerceFormatConverter.php     # GA4 ↔ Meta item format bidirectional conversion
│   ├── AnalyticsRateLimiter.php         # Per-client rate limiting
│   └── WebhookSignatureValidator.php    # HMAC-SHA256 webhook signature validation
├── Blade/Directives/
│   └── AnalyticsDirectives.php      # @analyticsHead, @analyticsBody
├── Facades/
│   └── Analytics.php               # Facade (20+ methods)
resources/
└── js/
    ├── analytics.js                 # ES module client library (~3600 LOC)
    └── analytics.d.ts               # TypeScript type definitions (50+ exports)
config/
└── zeroboiler.php                   # 50+ config options across 52+ sections
routes/
└── analytics.php                    # API route definitions
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
  "version": "2.68.0",
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

This validates strict types, `final` modifiers, interface implementations, readonly DTOs, composer metadata, and absence of TODO/FIXME markers across all 437 source files.

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

### v2.78.0 — PLG Events, Version Consistency, Catalog Expansion

- **FeatureAdoptedEvent** — Product-led growth activation milestone (fires once per user per high-value feature adoption)
- **ExpansionRevenueEvent** — Expansion revenue tracking (add-on purchases, seat expansion, usage overages, cross-sell)
- **86 total events** (was 84) — 13 ecommerce + 48 SaaS + 25 engagement
- **EventCatalog::plgEvents()** — Product-led growth specific event grouping for PLG dashboards
- **EventCatalog::productGrowthEvents()** — Now includes feature_adopted and expansion_revenue
- **EventCatalog::billingEvents()** — Now includes expansion_revenue
- **EventCatalog::industryStandard()** — New events in medium priority tier
- **AnalyticsManager** — New convenience methods: `featureAdopted()`, `expansionRevenue()`, `plgEvents()`
- **Facade proxy** — `featureAdopted()`, `expansionRevenue()`, `plgEvents()` added
- **EventPriorityCalculator** — New events classified as referral (AARRR)
- **Version consistency** — All 5 version references aligned to 2.78.0 (AnalyticsEvent, ServiceProvider, composer.json, JS client, TypeScript definitions)
- **README** — Updated event counts, API reference, SaaS event list
- **No breaking changes** — All changes are additive.

### From v2.67.x to v2.68.0
- **README comprehensive update** — All stale numbers corrected: 73 events (was 70), 38 SaaS events (was 35), 224 source files (was 183), 110 test files (was 87), ~3600 JS LOC (was ~2500), 52+ config sections (was 47+), 120+ AnalyticsConfig methods (was 110+), 300+ tests across 110+ test files (was 200+ across 100+)
- **3 new SaaS events documented** — TrialConvertedEvent, SubscriptionResumedEvent, MilestoneReachedEvent added to SaaS Lifecycle Events reference table
- **Architecture section updated** — EventContextEvent, EventPriority DTOs documented; correct event counts per category; updated JS LOC and config section counts
- **Health Response version** — Updated to v2.68.0
- **Version consistency** — All version strings aligned to 2.68.0 (composer.json, AnalyticsManager, JS client header + getVersion + _getInternalVersion, TypeScript definitions, 9 service files, controller endpoints)
- **V68PackageIntegrityTest** — 50+ test cases validating full package integrity: version consistency across 6 locations, file counts (224 src, 110 test, 73 events, 6 providers, 68 services, 14 pipeline filters), config section integrity, catalog structure, typed class coverage, SaaS event table completeness, roadmap completion, PHP 8.5 strict types, final class markers
- **No breaking changes** — All changes are additive and documentation-only.

### From v2.43.x to v2.44.0
- **Config fix — duplicate `attribution` key merged** — The `attribution` config section was defined twice in `config/zeroboiler.php`, causing the first definition's keys (`first_touch_ttl`, `touch_history_ttl`, `max_touch_history`) to be silently overwritten by the second definition (`model`, `session_window_days`, `cache_ttl`). Both sets of keys are now merged into a single `attribution` section, fixing silent data loss for AttributionService and UTMAttributionService.
- **AnalyticsConfig — 3 new attribution accessors** — `attributionModel()`, `attributionSessionWindowDays()`, `attributionCacheTtl()` added to the type-safe config wrapper, matching the merged config keys.
- **AnalyticsConfig — 15 new accessors (performance budget + forwarding)** — `performanceBudgetEnabled()`, `performanceBudgetMaxPayloadBytes()`, `performanceBudgetMaxParamsCount()`, `performanceBudgetMaxEventsPerSession()`, `performanceBudgetMaxEventsPerUserPerDay()`, `performanceBudgetMaxEventsPerPageView()`, `performanceBudgetMaxParamValueLength()`, `performanceBudgetDropOversized()`, `performanceBudgetWarnOnly()`, `forwardingEnabled()`, `forwardingTimeout()`, `forwardingRetries()`, `forwardingRateLimitPerMinute()`, `forwardingForwarders()` added.
- **AnalyticsConfig — duplicate keys removed from toSummaryArray()** — `broadcast` and `retention_policy` were defined twice in the summary array; duplicates removed, `performance_budget` and `forwarding` sections added.
- **AnalyticsConfig — attribution summary expanded** — Now includes `model` and `session_window_days` fields.
- **Tests** — V44ConfigIntegrityTest with 30+ cases validating config merge, accessor coverage, summary array uniqueness, and no duplicate keys.
- **No breaking changes** — All changes are additive and bugfix.

### From v2.42.x to v2.43.0
- **Comprehensive README update** — All stale numbers corrected: 50+ API endpoints (was 26), JS client ~2500 LOC (was ~1200), 40+ config sections (was 22), 183 source files (was 166), 87 test files (was 86), AnalyticsConfig 100+ typed methods (was 90+)
- **README Event Catalog Reference complete** — All 35 SaaS events now documented in the reference table (16 were previously missing: account lifecycle, B2B/Team, billing, subscription renewal, invite, integration)
- **GDPR Consent Purposes documented** — ConsentLogService and consent purposes feature added to Identity & GDPR section
- **Health response version example updated** — README example JSON now shows v2.42.0
- **V42SaaSStarterFinalTest** — 45+ new production-readiness test cases: full event catalog integrity, cross-provider mapping coverage, EcommerceFormatConverter bidirectional, PHP 8.5 syntax compliance, source file counts, middleware/pipeline counts, architecture validation
- **No breaking changes** — All changes are additive and documentation-only.

### From v2.40.x to v2.41.0
- **15 new SaaS event classes** — Account lifecycle (6), B2B/Team (4), Billing (5) — all with GA4, Meta, and PostHog mappings
- **ConsentLogService** — New granular GDPR consent logging service with audit trail and DSAR export
- **Consent purposes config** — New `consent.purposes` section in `config/zeroboiler.php` for frontend consent banners
- **Inertia consent props** — `consentPurposes` now automatically exposed in Inertia page props
- **SaaS event count** — 20 → 37 events (total catalog: 52 → 70 events)
- **No breaking changes** — All changes are additive.

### From v2.34.x to v2.35.0
- **JS client `getVersion()` fixed** — Was returning stale `'2.30.0'`, now correctly returns `'2.35.0'`
- **`beforeunload` flush** — JS client now auto-flushes batched events via `navigator.sendBeacon()` on page unload (registered in `init()`, cleaned up in `destroy()`)
- **TypeScript types** — New `resources/js/analytics.d.ts` file with full type definitions for all 50+ exports. Import or copy to your project for IDE auto-complete.
- **No breaking changes** — All changes are additive.
No breaking changes. Documentation-only release.

```bash
composer update zeroboiler/analytics
```

Changes:
- **README documentation update** — All event counts, API endpoint references, version strings, and source file counts aligned with actual codebase (70 events, 185 source files, 87 test files, 75+ API endpoints)
- **Missing API endpoints documented** — DLQ, realtime, AB tests, snapshots, KPI, UTM aggregation, reporting, broadcast, tenant, retention, gate, preference, opt-in/out
- **Missing changelog entries** — v2.27 through v2.33 changelogs added to README
- **Enterprise features section** — New feature category documenting v2.30+ capabilities
- **Event catalog tables** — FileDownloadEvent, VideoPlayEvent, InviteSentEvent, IntegrationConnectedEvent added

### From v2.25.x to v2.26.0
No breaking changes. Update via:

```bash
composer update zeroboiler/analytics
```

New features available:
- **Complete Meta Pixel mapping coverage** — All 48 events now have Meta Pixel equivalents (was 24/48). Enables full cross-provider dispatch for ecommerce, SaaS, and engagement events.
- **EventCatalog::getCategory()** — Category lookup by event name: `'ecommerce'|'saas'|'engagement'|null`
- **EventTransformer synced** — `ga4ToMetaEventMap()` maps all 12 ecommerce events

### From v2.24.x to v2.25.0
No breaking changes. Update via:

```bash
composer update zeroboiler/analytics
```

New features available:
- All leaf classes now marked `final` (100% coverage)
- ProductionReadinessTest with 40+ structural checks
- `:void` constructor return types on all leaf services

### From v2.23.x to v2.24.0
No breaking changes. Update via:

```bash
composer update zeroboiler/analytics
```

New features available:
- **EventAlertRulesService** — Config-driven threshold/rate alert rules with cooldown and severity
- **FunnelDataBuilderService** — API-ready funnel visualization data with conversion rates, drop-off analysis
- **6 new API endpoints** — alerts (GET/POST), funnels (4 endpoints)
- **Config expansion** — `alerts` section with rule types, conditions, cooldown

### From v2.22.x to v2.23.0
No breaking changes. Update via:

```bash
composer update zeroboiler/analytics
```

New features available:
- **AnalyticsStatsService** — Dashboard aggregation service for real-time event counts, top events, and per-provider stats
- **InboundWebhookService** — Receive analytics events from external sources (Stripe, payment processors) via `POST /api/analytics/webhook/inbound`
- **EventMetadataEnricher** — Automatic session ID, page URL, referrer, and timestamp enrichment on all API events
- **SchemaEnricher** — Optional schema-aware validation in the event pipeline
- **GET /api/analytics/stats** — Public dashboard statistics endpoint
- **JS session heartbeat** — `initSessionHeartbeat()` for periodic session activity tracking
- Pipeline config: `ANALYTICS_PIPELINE_AUTO_METADATA` (default: true), `ANALYTICS_PIPELINE_SCHEMA_ENRICHMENT` (default: false)

### From v1.x to v2.0.0
- Requires PHP 8.5+ and Laravel 13+
- All existing APIs are backward compatible
- New optional features (Plausible, PostHog, queue dispatch) are disabled by default
- Publish updated config: `php artisan vendor:publish --tag=zeroboiler-analytics-config --force`

## Contributing

```bash
git clone https://github.com/zeroboiler/analytics.git
cd analytics
composer install
composer ci  # Pint + PHPStan + Rector + Tests
```

## Changelog

### v2.68.0 — README Comprehensive Update, Package Integrity Verification

- **README comprehensive overhaul** — All metrics updated to reflect current codebase state: 73 events (12 ecom + 38 SaaS + 23 engagement), 224 source files, 110 test files, ~3600 JS LOC, 873 TypeScript LOC, 52+ config sections, 120+ AnalyticsConfig typed methods
- **3 SaaS events documented** — TrialConvertedEvent, SubscriptionResumedEvent, MilestoneReachedEvent added to README SaaS Lifecycle Events table
- **Architecture tree updated** — EventContextEvent and EventPriority DTOs added to tree diagram; event counts per category corrected
- **Health Response version** — Updated example to v2.68.0
- **Event Catalog reference** — Updated count from 70 to 73 events
- **V68PackageIntegrityTest** — Comprehensive 50+ test case validation suite covering version consistency (6 locations), file counts, config integrity, catalog structure, typed class coverage, PHP 8.5 strict types, final class markers, roadmap completion verification
- **Version consistency** — All 85 version strings aligned to 2.68.0 across composer.json, AnalyticsManager, JS client (header + getVersion + _getInternalVersion), TypeScript definitions, 9 service files, and controller endpoints
- **No breaking changes** — All changes are additive and documentation-only.

### v2.44.0 — Config Integrity, AnalyticsConfig Expansion, Attribution Fix

- **Config fix — duplicate `attribution` key merged** — `attribution` was defined twice in `config/zeroboiler.php`. The second definition silently overwrote the first, causing `first_touch_ttl`, `touch_history_ttl`, `max_touch_history` to be lost. Both sets of keys now merged into a single unified section.
- **AnalyticsConfig — 18 new typed accessors** — 3 attribution (`attributionModel`, `attributionSessionWindowDays`, `attributionCacheTtl`) + 9 performance budget + 5 forwarding + 1 forwarding forwarders config.
- **AnalyticsConfig toSummaryArray() — duplicate keys removed** — `broadcast` and `retention_policy` were listed twice in the summary array. Duplicates removed, `performance_budget` and `forwarding` sections added. Attribution summary expanded with `model` and `session_window_days`.
- **Tests** — V44ConfigIntegrityTest with 30+ assertions.
- **Version consistency** — composer.json, AnalyticsManager, JS client, TypeScript definitions all aligned to v2.44.0.

### v2.43.0 — Event Forwarding, Performance Budget, UTM Attribution

- **EventForwardingService** — Multi-platform event forwarding to Segment, Mixpanel, Amplitude, and custom HTTP endpoints with rate limiting, retries, and per-forwarder config.
- **PerformanceBudgetService** — Event payload size, rate, and quota enforcement with configurable limits per session, user, and page view.
- **UTMAttributionService** — First-touch, last-touch, and multi-touch UTM attribution with configurable session window and model selection.
- **Config expansion** — `forwarding` section (4 env vars), `performance_budget` section (9 env vars), unified `attribution` section.
- **AnalyticsConfig** — Accessor coverage for forwarding and performance budget.
- **Tests** — V43 test suite (forwarding, budget, attribution, event count validation).
- **Version consistency** — All aligned to v2.43.0.

### v2.42.0 — SaaS starter final

- **15 new SaaS event classes** — Account lifecycle (6), B2B/Team (4), Billing (5)
- **ConsentLogService** — GDPR consent audit trail with DSAR export
- **Consent purposes config** — 4 configurable purposes for frontend cookie banners
- **Inertia consent props** — `consentPurposes` exposed in page props
- **SaaS event count** — 37 events (total catalog: 70 events)
- **Version consistency** — All aligned to v2.42.0

### v2.33.0 — RealTimeAggregation, ABTestAnalytics, Snapshots, KPI, UTM, Geolocation

- **RealTimeAggregationService** — Time-windowed real-time event counting, top events, per-category breakdowns
- **ABTestAnalyticsService** — A/B experiment tracking with exposure, conversion recording, variant analysis, statistical significance
- **AnalyticsSnapshotService** — Daily/hourly analytics snapshots with day-over-day comparison
- **SaasKpiTracker** — Comprehensive SaaS KPI tracking (MRR, churn rate, trial conversion, ARPU, LTV) with history
- **UtmAggregationService** — UTM source/campaign aggregation and top breakdown analytics
- **GeolocationEnricher** — IP-based geolocation enrichment in event pipeline
- **17 new API endpoints** — Real-time (2), AB tests (4), snapshots (3), KPI (2), UTM (3), reporting (5)
- **7 new config sections** — real_time, ab_test, snapshots, kpi, utm_aggregation, geolocation, reporting
- **35+ new tests**

### v2.32.0 — EventReportingService, DeadLetterQueue, EcommerceFormatConverter

- **EventReportingService** — Structured analytics report generation (summary, top events, trending, provider stats)
- **DeadLetterQueueService** — Failed event recovery with management API (list, summary, clear, remove)
- **EcommerceFormatConverter** — GA4 ↔ Meta item format conversion helper
- **9 new API endpoints** — DLQ (4), reporting (5)
- **3 new config sections** — reporting, dead_letter_queue, ecommerce_format

### v2.31.0 — 4 New Typed Events, EventCatalog Validation, Referral Tracking

- **InviteSentEvent** — Team/collaborator/referral invitation tracking
- **IntegrationConnectedEvent** — External integration connection tracking (Slack, GitHub, Stripe)
- **FileDownloadEvent** — File/document download tracking
- **VideoPlayEvent** — Video content interaction tracking
- **EventCatalog::validate()** — Full catalog integrity validation (required keys, class existence, duplicates)
- **Referral tracking config** — Referral code capture with TTL and conversion tracking
- **Total events: 70** (12 ecom + 37 SaaS + 21 engagement)

### v2.30.0 — Enterprise Features

- **EventBroadcasterService** — Real-time event broadcasting via Laravel Echo for live dashboards
- **TenantIsolationService** — Multi-tenant analytics data isolation with automatic tenant ID resolution
- **DataRetentionPolicyService** — GDPR-compliant per-category data retention with auto-expiry
- **AnalyticsGateService** — Plan-based feature gating (Free/Starter/Pro/Enterprise) with 12 features
- **7 new API endpoints** — broadcast, tenant (3), retention, gate (2)

### v2.29.0 — Config Validator, Event Source Tagger, Referrer Tracking

- **AnalyticsConfigValidator** — Runtime config integrity validation with warnings
- **EventSourceTagger** — Source identification (server, client, api, webhook) on all events
- **AnalyticsReferrerMiddleware** — Automatic referrer tracking middleware

### v2.28.0 — Lifecycle Event Mapper, Event Correlation

- **LifecycleEventMapper** — Config-driven 15-event lifecycle mapping from Laravel events to analytics events
- **EventCorrelationService** — Pattern detection, event transition analysis, next-event prediction, conversion rate tracking
- **5 new API endpoints** — lifecycle, correlation (4)

### v2.27.0 — AnalyticsConfig Expansion, Alert/Funnel Routes

- **AnalyticsConfig expansion** — 25+ new typed accessors, 22 config sections
- **Alert + funnel routes** registered in ServiceProvider
- **Facade directDispatch** return type fix (void → bool)
- **7 new API routes**, **V27 test suite** (35+ cases)

### v96.0.0 — SaaS Event Helpers, JS Shortcut API, Version Sweep

- **SaaSEventHelpers** — New server-side convenience service (`ZeroBoiler\Analytics\Support\SaaSEventHelpers`) providing one-line methods for the most common SaaS lifecycle events: `signUp()`, `login()` (with auto-identify), `trialStart()`, `subscription()`, `planUpgrade()`, `planDowngrade()`, `cancellation()`, `featureUsed()`, `teamCreated()`, `inviteSent()`, `paymentFailed()`. All methods respect consent state, debug mode, DataBus routing, and interceptors. Filters null parameters automatically for clean payloads.
- **JS SaaS Event Shortcuts** — New client-side convenience functions: `trackSignUp()`, `trackTrialStart()`, `trackSubscription()`, `trackPlanUpgrade()`, `trackCancellation()`, `trackFeatureUsed()`. One-line alternatives to `trackEvent()` with structured parameter building and immediate dispatch for revenue-critical events.
- **TypeScript Definitions** — Full type definitions for all new JS SaaS shortcuts (`TrackSignUpOptions`, `TrackTrialStartOptions`, `TrackSubscriptionOptions`, `TrackPlanUpgradeOptions`, `TrackCancellationOptions` interfaces).
- **Version sweep** — All source, JS, and composable versions unified to v96.0.0. Fixed stale `getVersion()` return value (was '92.0.0'). Svelte composable headers aligned.
- **Test coverage** — 18 new test cases for SaaSEventHelpers covering all methods, null filtering, extra param merging, manager access, and PHP 8.5 strict types compliance.

### v95.0.0 — Provider Fallback Expansion, AARRR Classification v2, Priority Gate Extensions

- **ProviderFallbackService expanded to 10 providers** — `validProviders` list now includes `mixpanel`, `amplitude`, `tiktok`, `linkedin` (was 6, now 10). Fallback chain config examples added for all new providers.
- **EventPriorityGate: security, uptime, infrastructure categories** — Events in `security`, `uptime`, and `infrastructure` catalog categories now resolve to `Normal` priority (previously fell to default). Enables consistent priority classification across all 7 event categories.
- **EventPriorityCalculator AARRR v2** — 40+ new event-to-category classifications: security events (login_attempt, mfa_challenge, suspicious_activity, etc.) → operational; uptime events (service_down, deployment, slo_breach, etc.) → operational; ecommerce expansion (abandoned_cart, checkout_abandon, wishlist) → revenue; engagement expansion (hover, copy_text, element_visibility) → retention; activation expansion (onboarding_completed, experiment_exposed); consent events → operational; outbound_click → acquisition; feature_flag_evaluated → retention.
- **Critical SaaS events expanded** — `retention_cohort` added to critical events list for maturity scoring.
- **Fallback config examples** — New commented chain examples for mixpanel → amplitude → posthog, tiktok → linkedin → meta.
- **30+ new test cases** — ProviderFallbackService validation with all 10 providers, healthSummary coverage, AARRR classification for all new events, priority gate category resolution, maturity score verification.
- **Version consistency** — composer.json, README badge aligned to v95.0.0

### v2.26.0 — Complete Meta Pixel Mapping Coverage, EventCatalog::getCategory()

- **All events now have Meta Pixel equivalents** — Previously some events had `null` Meta mappings. All ecommerce (12), SaaS (19), and engagement (21) events now have proper Meta Pixel event names for cross-provider dispatch.
- **Meta mapping examples** — `remove_from_cart` → `RemoveFromCart`, `view_cart` → `ViewCart`, `refund` → `Refund`, `select_item` → `ViewItem`, `plan_upgrade` → `PlanUpgrade`, `cancellation` → `CancelSubscription`, `scroll_depth` → `ScrollDepth`, `click` → `Click`, `form_start` → `Lead`, `share` → `Share`, `error` → `Error`, plus cohort events (`CohortAssigned`, `CohortRetention`, etc.)
- **EventTransformer synced** — `ga4ToMetaEventMap()` now maps all 12 ecommerce events (was 6 null → 6 mapped)
- **EventCatalog::getCategory()** — New method returning `'ecommerce'|'saas'|'engagement'|null` for any event name. Used by DataBus routing, event processing, and admin commands.
- **Version consistency** — composer.json, AnalyticsManager, and JS client all aligned to v2.26.0
- **Tests** — 15+ new test cases covering Meta mapping coverage, EventCatalog::getCategory(), and transformer consistency

### v2.25.0 — Mark all leaf classes final, ProductionReadinessTest with 40+ structural checks

- **All 24 remaining leaf classes marked `final`** — 100% final coverage across all source files
- **`:void` constructor return types** added to all constructors missing them (55/55 structural checks)
- **ProductionReadinessTest** — 40+ structural assertions: strict types, `final` modifiers, interface implementations, readonly DTOs, composer metadata, no TODO/FIXME markers
- **Version consistency** — composer.json, AnalyticsManager, and JS client all aligned to v2.25.0

### v2.24.0 — EventAlertRulesService, FunnelDataBuilderService, Expanded API

- **EventAlertRulesService** — Config-driven threshold/rate alert rules with cooldown, severity, multi-channel dispatch
- **FunnelDataBuilderService** — API-ready funnel visualization data with conversion rates, drop-off analysis, chart format, time-series, funnel comparison
- **6 new API endpoints** — alerts (GET/POST), funnels (4 endpoints: data, compare, drop-off, chart)
- **Config expansion** — `alerts` section with rule types (count, rate, total, error_rate), conditions, cooldown, severity
- **30+ new tests** — Alert rules evaluation, cooldown tracking, funnel data builder, API endpoints
- **Version consistency** — All aligned to v2.24.0

### v2.23.0 — AnalyticsStatsService, InboundWebhookService, Session Heartbeat

- **AnalyticsStatsService** — Dashboard aggregation service for real-time event counts, top events, and per-provider stats
- **InboundWebhookService** — Receive analytics events from external sources (Stripe, payment processors) via `POST /api/analytics/webhook/inbound`
- **EventMetadataEnricher** — Automatic session ID, page URL, referrer, and timestamp enrichment on all API events
- **SchemaEnricher** — Optional schema-aware validation in the event pipeline
- **GET /api/analytics/stats** — Public dashboard statistics endpoint
- **JS session heartbeat** — `initSessionHeartbeat()` for periodic session activity tracking
- Pipeline config: `ANALYTICS_PIPELINE_AUTO_METADATA` (default: true), `ANALYTICS_PIPELINE_SCHEMA_ENRICHMENT` (default: false)

### v2.12.0 — AnalyticsHealthService, EventDebounceFilter, Quality Improvements
- **AnalyticsHealthService** — Programmatic health-check service with structured report (status, providers, consent, queue, replay, metrics, catalog, validation, sampling, PII, debug, warnings, recommendations). `isHealthy()`, `getWarnings()`, `getRecommendations()` methods. Registered as singleton in ServiceProvider.
- **EventDebounceFilter** — Pipeline filter to suppress rapid-fire events (scroll depth, resize). Configurable debounce window, per-event-name tracking, `flush()`, `reset()`, `setTestNow()` for testing.
- **`Analytics::trackError()`** — Server-side JS error convenience method (message, source, line, params).
- **`Analytics::mrr()`** — Shortcut for SaaS MRR revenue tracking (amount, subscriber count).
- **Facade proxy** — `trackError()` and `mrr()` added to Analytics facade docblock.
- **Bug fix** — `SaaSAnalyticsService::trackPlanDowngrade()` no longer double-tracks (was dispatching both typed event and generic event).
- **Version consistency** — composer.json, AnalyticsManager, and JS client all aligned to v2.12.0.
- **Tests** — 25+ new test cases: version consistency, debounce filter, health service, plan downgrade fix, facade coverage, catalog validation.

### v2.8.0 — Session Analytics, Event Aggregation, Health Diagnostic

- **SessionAnalyticsService** — Session-level event recording, aggregation, summaries, and session end tracking with configurable memory limits and LRU eviction
- **EventAggregationService** — Real-time event counting with time-windowed aggregation, top events ranking, category grouping, and configurable window rotation
- **AnalyticsHealthCommand** — Comprehensive health diagnostic (`zb:analytics:health`) checking providers, queue, replay, consent, validation, sampling, PII sanitization, and metrics with actionable warnings and recommendations
- **Health report** — Structured JSON or formatted console output with warnings, recommendations, and overall status (healthy/warning/error)
- **Windowed aggregation** — Configurable window size for automatic count rotation in high-traffic scenarios
- **Session summaries** — Automatic `analytics_session_summary` event dispatched on session end with event count, page count, unique events, duration estimate, and event types
- **Service Provider** — SessionAnalyticsService and EventAggregationService registered as singletons
- **Tests** — 25+ new test cases covering session analytics, event aggregation, health diagnostics, window rotation, and version consistency
- **Version consistency** — composer.json, AnalyticsManager, health endpoint, catalog endpoint, JS client all aligned to v2.8.0

### v2.7.0 — Cohort Analytics, Event Replay Queue, Health Check Expansion
- **CohortAnalyticsService** — Time-based cohort tracking with assignCohort, trackRetention, trackChurn, trackConversion, trackMigration, trackEngagementSummary
- **EventReplayQueue** — Failed event retry with exponential backoff and jitter (configurable max attempts, base/max delay)
- **6 cohort event schemas** — cohort_assigned, cohort_retention, cohort_churn, cohort_conversion, cohort_migration, cohort_engagement registered in EventSchemaRegistry
- **37 SaaS events** (was 20) — Account lifecycle (6), B2B/Team (4), Billing (5), + SubscriptionRenewal added to SaaS catalog, total events now 70
- **Health check expansion** — Now includes metrics summary, replay queue status, and event catalog summary
- **Config** — New `replay` section with 5 environment variables (ANALYTICS_REPLAY_ENABLED, etc.)
- **Service Provider** — CohortAnalyticsService and EventReplayQueue registered as singletons
- **Cohort name generation** — Static helper for weekly, monthly, quarterly, daily cohort naming
- **Period classification** — Automatic retention day → period bucket (d1, d7, d14, d30, d60, d90, d180, d365)
- **Tests** — 50+ new test cases covering cohort service, replay queue, catalog integration, schema validation, and version consistency
- **Version consistency** — composer.json, AnalyticsManager, health endpoint, catalog endpoint all aligned to v2.7.0

### v2.6.0 — Wishlist Convenience, Schema Expansion, Catalog Export
- **`Analytics::wishlist()`** — Convenience method for wishlist tracking with automatic Meta `AddToWishlist` formatting
- **Facade proxy** — `wishlist()` added to Analytics facade
- **EventSchemaRegistry expanded** — Added schemas for `add_to_wishlist`, `set_user_properties`, `alias`, `outbound_click`, `internal_click` (40+ total schemas)
- **`zb:analytics:export` command** — Export full event catalog as JSON, CSV, or Markdown for documentation and integration
- **README updates** — Export command docs, wishlist usage, updated event counts (33 events, 40+ schemas)
- **Version consistency** — composer.json, AnalyticsManager, health endpoint, JS client all aligned to v2.6.0

### v2.5.0 — AnalyticsMetrics, PII sanitization, event sampling, AnonymousIdTracker
- **DataBus-aware dispatch** — AnalyticsManager now routes events through DataBus when routing rules are configured, falling back to direct dispatch when no rules exist
- **directDispatch()** — New public method to bypass DataBus and send events to all providers directly
- **Facade proxy** — `directDispatch()` added to Analytics facade
- **Overview command enhanced** — Event catalog stats (per-category event lists, totals), provider summary with IDs
- **Tests** — 50+ new test cases across 4 new files (DataBus, FunnelAnalyticsService, RevenueAttributionService, DataBus Integration)
- **Version consistency** — composer.json, AnalyticsManager, health endpoint all aligned to v2.3.0
- **Features list** — DataBus routing and directDispatch added to registered features overview

### v2.2.0 — Funnel Analytics, Revenue Attribution, AnalyticsDataBus
- **trackEcommerce()** — Cross-provider e-commerce event dispatch (GA4 + Meta Pixel auto-formatting)
- **Facade proxy** — `trackEcommerce()` added to Analytics facade
- **EventCatalog::search()** — Partial name match search across all event categories
- **EventCatalog::byProvider()** — Events grouped by provider (ga4, meta)
- **Config** — `ecommerce.tax_behavior` setting (inclusive/exclusive/not_specified)
- **Tests** — 25+ new test cases across 3 new files (API controller, Inertia middleware, identity tracker, ecommerce convenience, event catalog)
- **LICENSE** — MIT license file added
- **README** — Troubleshooting, Upgrading, and Contributing sections added
- **Version bump** — composer.json, health endpoint, JS client updated to v2.1.0

### v2.0.0 — Full SaaS Starter
- **pageView()** and **serverSidePageView()** convenience methods on AnalyticsManager + Facade
- **logout()**, **trialEnd()**, **planDowngrade()** convenience methods on AnalyticsManager + Facade
- **formatEcommerceForMeta()** — GA4 → Meta Pixel item format conversion on AnalyticsManager
- **SaaSAnalyticsService** extended: trackPlanDowngrade, trackLogout, trackTrialEnd, trackRevenue
- **Inertia middleware** enhanced: device context (userAgent, IP, locale), apiBase, apiEnabled, debug props
- **Config-driven event map**: `auto_track.event_map` for custom event → class mapping in ServerSideTracker
- **Config**: `api.base_url` for custom API route prefix
- **Facade**: 7 new proxy methods (pageView, serverSidePageView, logout, trialEnd, planDowngrade, formatEcommerceForMeta)
- **Tests**: 20+ new test cases across 2 new test files (V20ManagerPageViewTest, V20SaasServiceExtendedTest)
- **composer.json**: v2.0.0, requires PHP 8.5+, illuminate 13.0+
- **README**: Updated Laravel/PHP badges, Inertia props docs, changelog

### v1.9.0 — Industry-Standard SaaS Analytics
- README overhaul: Quick Start, full API reference, architecture diagram, JS client API table, changelog
- MIT license, comprehensive documentation for production use
- 32 typed events, 6 providers, 35+ tests, full type coverage

### v1.8.0 — Identity & Alias
- User properties (`setUserProperties`) and identity alias (`alias`)
- Server-side page view API endpoint
- Enhanced identify endpoint with user traits

### v1.7.0 — Screen View, A/B Tests, Notifications
- ScreenView, AbTestExposure, Notification events
- `trackAsync()` facade shortcut
- JS client extensions for screen view and A/B tracking

### v1.6.0 — Purchase & Identify Convenience
- `purchase()` and `identify()` convenience methods on AnalyticsManager
- Facade proxy tests

### v1.5.0 — Event Catalog & GDPR Reset
- EventCatalog, GDPR identity reset, unified registry

## License

MIT — see [LICENSE](LICENSE) for details.
