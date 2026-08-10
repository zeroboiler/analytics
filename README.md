# ZeroBoiler Analytics

[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Laravel 13+](https://img.shields.io/badge/Laravel-13%2B-red.svg)](https://laravel.com)
||||[![Latest Version](https://img.shields.io/badge/version-8.4.0-blue)](https://github.com/zeroboiler/analytics)||
|[![PHP 8.5+](https://img.shields.io/badge/PHP-8.5%2B-8892BF.svg)](https://www.php.net)

Industry-standard SaaS analytics for Laravel — production-ready event tracking across **6 providers** (GA4, GTM, Meta Pixel, Plausible, PostHog, and generic HTTP) with a fully-featured JS client, auto-tracking, queue dispatch, identity resolution, cohort analytics, event replay, and GDPR consent.

## Table of Contents

- [Quick Start](#quick-start)
- [What's New in v8.4.0](#whats-new-in-v840)
- [What's New in v8.3.0](#whats-new-in-v830)
- [What's New in v8.2.0](#whats-new-in-v820)
- [What's New in v8.0.0](#whats-new-in-v8000)
- [What's New in v7.9.0](#whats-new-in-v790)
- [What's New in v7.8.0](#whats-new-in-v780)
- [What's New in v7.7.0](#whats-new-in-v770)
- [What's New in v7.6.0](#whats-new-in-v760)
- [What's New in v7.4.0](#whats-new-in-v740)
- [What's New in v7.3.0](#whats-new-in-v730)
- [What's New in v7.1.0](#whats-new-in-v7100)
- [What's New in v7.0.0](#whats-new-in-v7000)
- [What's New in v6.9.0](#whats-new-in-v6900)
- [What's New in v6.8.0](#whats-new-in-v6800)
- [What's New in v6.7.0](#whats-new-in-v6700)
- [What's New in v6.5.0](#whats-new-in-v6500)
- [What's New in v6.2.0](#whats-new-in-v6200)
- [What's New in v6.1.0](#whats-new-in-v6100)
- [What's New in v5.9.0](#whats-new-in-v5900)
- [What's New in v5.8.0](#whats-new-in-v5800)
- [What's New in v5.2.0](#whats-new-in-v5200)
- [What's New in v5.0.0](#whats-new-in-v5000)
- [What's New in v4.5.0](#whats-new-in-v4500)
- [What's New in v4.4.0](#whats-new-in-v4400)
- [What's New in v4.3.0](#whats-new-in-v4300)
- [What's New in v4.2.0](#whats-new-in-v4200)
- [What's New in v4.1.0](#whats-new-in-v4100)
- [What's New in v4.0.0](#whats-new-in-v4000)
- [What's New in v3.9.0](#whats-new-in-v3900)
- [What's New in v3.8.0](#whats-new-in-v3800)
- [What's New in v3.7.0](#whats-new-in-v3700)
- [What's New in v3.6.0](#whats-new-in-v3600)
- [What's New in v3.5.0](#whats-new-in-v3500)
- [What's New in v3.4.0](#whats-new-in-v3400)
- [What's New in v3.3.1](#whats-new-in-v3331)
- [What's New in v3.3.0](#whats-new-in-v3300)
- [What's New in v3.2.0](#whats-new-in-v3200)
- [What's New in v3.1.0](#whats-new-in-v3100)
- [What's New in v3.0.0](#whats-new-in-v3000)
- [What's New in v2.98.0](#whats-new-in-v2980)
- [What's New in v2.97.0](#whats-new-in-v2970)
- [What's New in v2.96.0](#whats-new-in-v2960)
- [What's New in v2.95.0](#whats-new-in-v2950)
- [What's New in v2.94.0](#whats-new-in-v2940)
- [What's New in v2.93.0](#whats-new-in-v2930)
- [What's New in v2.92.0](#whats-new-in-v2920)
- [What's New in v2.91.0](#whats-new-in-v2910)
- [What's New in v2.88.0](#whats-new-in-v2880)
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
- [License](#license)

## What's New in v8.4.0

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

## What's New in v8.3.0

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

## What's New in v8.2.0

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

## What's New in v8.0.0

### Session Analytics & Funnel Aggregation — Industry-Standard SaaS Dashboard Engine

**EventSessionizer** — Session-aware event aggregation for real-time SaaS dashboards. Groups events by client ID + session ID and computes per-session metrics: event counts, unique events, session duration estimation, engagement scoring (0-100), and conversion detection. Uses cache-backed ring buffers with automatic TTL expiry. Supports session indexing, client aggregation stats, and explicit session termination. Inspired by Amplitude Session Explorer and Mixpanel Session Replay.

- **EventSessionizer** — `record(AnalyticsEvent)` for session-context event recording. Returns `session_id`, `event_count`, `unique_events`, `duration_estimate`, `engagement_score`. `getClientSessions()` for per-client session listing. `aggregateStats()` for dashboard-ready summary (total sessions, avg events, conversion rate, avg engagement). `endSession()` for explicit session termination.
- **EventFunnelAggregator** — Automated funnel completion tracking across sessions. Five built-in funnels (signup, activation, purchase, subscription, expansion) with configurable custom funnels. `record()` tracks progress through funnel steps with conversion detection. `getFunnelReport()` returns step-by-step conversion rates, drop-off rates, and cumulative rates. `getAllFunnelReports()` for dashboard overview.
- **EventClassificationEnricher** — Pipeline stage that auto-enriches events with catalog metadata: `_zb_category`, `_zb_provider_map`, `_zb_event_class`, `_zb_priority`. Priority inference for custom events using name pattern heuristics. Supports batch enrichment.
- **AnalyticsReportCommand** — Scheduled report generator (`php artisan analytics:report`) with sections: health, catalog, funnels, sessions, saas. Supports `--format=json` and `--section=` for targeted reporting. Designed for cron-based delivery.
- **API Endpoints** — `GET /api/analytics/sessions/{clientId}`, `GET /sessions/{clientId}/{sessionId}`, `GET /sessions/{clientId}/stats`, `POST /sessions/end/{clientId}/{sessionId}`, `GET /funnels/aggregated/{funnelName}`, `GET /funnels/aggregated`, `GET /funnels/definitions`.
- **Config** — `sessionizer` section (session_ttl, max_sessions_per_client, cache_prefix). `funnel_definitions` for custom funnels. `classification` toggle for auto-enrichment.

## What's New in v7.9.0

### Multi-Touch Attribution & Feature Matrix Benchmark

**AttributionModelService** — Industry-standard multi-touch attribution modeling for marketing analytics. Computes weighted credit across touchpoints using five models: first-touch, last-touch, linear, time-decay, and position-based (U-shaped). Supports channel aggregation, campaign aggregation, and ROAS/CPA efficiency metrics.

- **AttributionModelService** — Five attribution models (first_touch, last_touch, linear, time_decay, position_based). Validates models, computes per-touchpoint credit with percentage breakdowns. `compareModels()` for side-by-side model comparison. `aggregateByChannel()` and `aggregateByCampaign()` for multi-journey aggregation. `channelEfficiency()` for ROAS/CPA calculations.
- **SaaSFeatureMatrixService** — Feature parity benchmarking against industry platforms (Segment, Mixpanel, Amplitude, PostHog, Matomo, Plausible). 70+ feature checks across 13 categories. `buildMatrix()` for full coverage analysis with gap identification. `coverageSummary()` with letter grade. `compareWith()` for per-competitor advantage/disadvantage analysis.
- **Config: `attribution_model` section** — Default model, decay factor, enabled models, cache TTL.
- **Config: `feature_matrix` section** — Enable/disable feature matrix endpoints, cache TTL.
- **10 new API endpoints** — Attribution (models, attribute, compare, by-channel, by-campaign, efficiency) and Feature Matrix (matrix, summary, gaps, compare/{competitor}).
- **Version sweep** — 7.8.0 → 7.9.0 across composer.json, AnalyticsEvent::VERSION, JS client, Svelte composable, TypeScript definitions, README badge.

## What's New in v7.8.0

### Event Plugin Registry & Integrity Check

Third-party Laravel packages can now register their analytics events with the ZeroBoiler event catalog at runtime via the `EventPluginRegistry`. Plugin events merge into the catalog via `EventCatalog::allWithPlugins()` without conflicting with built-in events (built-in wins on name collision).

- **EventPluginRegistry** — Config-driven and runtime plugin registration with validation. Accepts manifests with `package`, `version`, `events` (name, class, ga4, meta, category), and `priority`. Supports `registerPlugin()`, `validate()`, `summary()`, `eventsByPlugin()`, `eventsByCategory()`, `unregisterPlugin()`.
- **EventCatalog::allWithPlugins()** — New static method that merges plugin-registered events into the built-in catalog. Built-in events take precedence on name conflicts.
- **AnalyticsIntegrityCommand** — New `zb:analytics:integrity` artisan command. Validates version consistency across all entry points (composer.json, DTO VERSION, JS/Svelte/d.ts), event catalog completeness (core SaaS lifecycle, ecommerce, engagement events), config integrity (consent, auto-track, queue, providers), and plugin registry health (validation, name conflicts). Supports `--json`, `--verbose`, `--fix` flags. Designed for CI pipelines.
- **Config: `event_plugins` section** — New `zeroboiler.analytics.event_plugins` with `enabled`, `debug`, `plugins` settings.
- **Version sweep** — 7.6.0/7.7.0 → 7.8.0 across composer.json, AnalyticsEvent::VERSION, JS client, Svelte composable, TypeScript definitions, ServiceProvider, README badge.

## What's New in v7.7.0

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

## What's New in v7.6.0

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

## What's New in v7.4.0

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

## What's New in v7.3.0

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

## What's New in v7.1.0

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

## What's New in v7.0.0

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

## What's New in v6.9.0

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

## What's New in v6.4.0

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

## What's New in v6.3.0

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

## What's New in v6.7.0

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

## What's New in v6.5.0

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

## What's New in v6.2.0

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

## What's New in v6.1.0

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

## What's New in v5.9.0

### Industry Standard SaaS Analytics Readiness

Comprehensive version integrity sweep and industry-standard compliance verification across the entire codebase.

- **Version sweep** — 5.7.0 → 5.9.0 across all 102 files (PHP source, JS client, Svelte composables, config, routes, README, CHANGELOG, 100+ test files)
- **New V59 test suite** — 35+ test cases validating: version integrity, event catalog coverage (90+ events across 3 categories), cross-provider format conversion (GA4↔Meta), lifecycle event mapper config-driven mappings, API controller completeness, Inertia middleware SaaS props, Consent Mode v2 GDPR compliance, identity client ID ↔ user ID linking, optional providers (Plausible, PostHog), admin commands, PHP 8.5 `declare(strict_types=1)` enforcement on all source files, config section completeness (22 sections), JS client batch queue implementation, provider health monitor, event routing configuration, and end-to-end SaaS funnel flows
- **Strict types verified** — all 340+ PHP source files confirmed to use `declare(strict_types=1)`

## What's New in v5.8.0

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

## What's New in v5.4.0

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

## What's New in v5.9.0

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

## What's New in v5.2.0

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

## What's New in v6.8.0

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

## What's New in v5.0.0

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

## What's New in v5.0.0

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

## What's New in v4.5.0

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

## What's New in v4.4.0

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

## What's New in v4.3.0

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

## What's New in v4.2.0

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

## What's New in v4.1.0

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

## What's New in v4.0.0

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

## What's New in v3.9.0

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

## What's New in v3.8.0

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

## What's New in v3.7.0

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

## What's New in v3.6.0

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

## What's New in v3.5.0

**SaaS Starter Industry Standard Final Upgrade — Full 12-Point Checklist Closure**

v3.5.0 is the capstone release that validates ZeroBoiler Analytics as a complete, industry-standard SaaS analytics platform. All 12 features from the original SaaS starter upgrade roadmap are fully implemented, tested, and documented. This release adds the final missing README documentation for v3.2–v3.4 and a comprehensive validation test suite.

### Changes

- **README Documentation** — Added complete "What's New" sections for v3.2.0 (Identity Resolution + Event Debounce), v3.3.0 (Svelte 5 Composables + Lifecycle Config Sync), v3.3.1 (Production Readiness Audit), and v3.4.0 (EventCollection DTO + AnalyticsEventDispatcher + Plausible/PostHog Composables). Updated Table of Contents with all version entries
- **Comprehensive Validation Test** (`V98SaaSStarterIndustryStandardFinalTest.php`) — 60+ assertions validating all 12 SaaS starter features: Event Catalog completeness (100+ events across 3 categories), Server-Side Lifecycle Mapper (40+ config-driven mappings), Inertia middleware prop injection (18+ prop groups), API controller coverage (130+ routes), JS client API (trackEvent, trackPageView, identify, consent, ecommerce, batch), Event Queue async dispatch, User Identity Linking (client ↔ user), E-commerce format conversion (GA4 ↔ Meta), Admin commands (overview, test, health, behavioral, dashboard), Config expansion (20+ sections), Optional providers (Plausible + PostHog), and Test coverage (150 test files)
- **Version Bump** — `AnalyticsEvent::VERSION` updated to `3.5.0` across all source files, JS client, and Svelte composables

### Upgrade Notes

- No breaking changes — all existing APIs remain backward compatible
- README now fully documents all releases from v2.88.0 through v3.5.0
- Total: 73.5K+ LOC PHP source, 5.2K+ LOC JS client, 850+ LOC Svelte composables, 150 test files, 130+ API routes, 100+ event classes, 6 provider trackers

## What's New in v3.4.0

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

## What's New in v3.3.1

**Phase 2-3-4 Production Readiness Audit**

v3.3.1 is a maintenance release addressing production readiness across the entire platform.

### Changes

- Fixed syntax error in `AnalyticsInsightAggregator` (malformed array closing bracket)
- Updated 119 stale version string references from `3.0.0` to current version across all source files
- Production readiness audit across Phase 2 (identity, consent, GDPR), Phase 3 (pipeline, queue, replay), and Phase 4 (observability, monitoring)
- All version assertions in tests corrected to match current version

## What's New in v3.3.0

**Svelte 5 Composables, Lifecycle Config Sync, Version Sweep**

v3.3.0 adds a comprehensive Svelte 5 reactive composable layer and lifecycle configuration synchronization.

### New Features

- **Svelte 5 Composables** (`useAnalytics.svelte.js`) — Full reactive analytics layer using Svelte 5 runes (`$state`, `$derived`, `$effect`). `useAnalytics(page)` — auto-initializing composable with Inertia page prop sync. `isReady`, `trackingId`, `userId`, `isAuthenticated`, `debug` derived states. Auto-cleanup on component unmount via `$effect` garbage collection
- **Ecommerce Composable** — `useEcommerce()` — Reactive e-commerce tracking with `trackViewItem()`, `trackAddToCart()`, `trackBeginCheckout()`, `trackPurchase()`. Currency and brand awareness from Inertia props
- **Consent Composable** — `useConsent()` — Reactive consent management with `grant()`, `deny()`, `update()`, `isGranted()`, `purposes()` derived states. Auto-sync with Inertia consent props
- **Lifecycle Config Sync** — Server-side lifecycle event mapper configuration automatically exposed to Inertia props for client-side event name awareness. Version hash for change detection
- **Version Bump to 3.3.0** — All version strings swept across PHP source, JS client, Svelte composables, and tests

## What's New in v3.2.0

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

## What's New in v3.1.0

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

## What's New in v3.0.0

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

## What's New in v2.98.0

- **EventBuilder (Fluent API)** — Type-safe, declarative event construction with catalog-aware validation. Static factories for common events: `EventBuilder::purchase()`, `::signUp()`, `::pageView()`. Chain `->param()`, `->params()`, `->items()`, `->client()`, `->user()`, `->priority()`, and finish with `->build()`, `->dispatch()`, or `->dispatchAsync()`
- **SessionReplayService** — Cache-based session event recording for user journey reconstruction. Ring buffer (configurable max events + TTL), timeline with duration/summary, per-user session indexing, revenue/error event detection. Ideal for support debugging and behavior analysis
- **AdvancedPIIDetector** — Regex-based PII detection engine with 14 built-in patterns (email, phone US/intl, credit card Visa/MC/Amex, SSN, IBAN, JWT, IPv4/IPv6, address, ZIP). Field name heuristics for 30+ PII keys. Configurable confidence threshold and `redact()` with first/last character preservation
- **Config: Session Replay** — `ANALYTICS_SESSION_REPLAY_ENABLED` (default: false), `ANALYTICS_SESSION_REPLAY_MAX_EVENTS` (200), `ANALYTICS_SESSION_REPLAY_TTL` (3600)
- **Config: PII Detection** — `ANALYTICS_PII_DETECTION_ENABLED` (default: false), `ANALYTICS_PII_DETECTION_THRESHOLD` (0.5), `ANALYTICS_PII_DETECTION_CUSTOM_PATTERNS`
- **Version bump to 2.98.0** — 23 files updated across PHP source, tests, JS client, TypeScript definitions, config, and documentation

## What's New in v2.97.0

- **Comprehensive Health Check Service** — `AnalyticsHealthCheckService` runs a full diagnostic across 12 subsystems: providers, catalog, AARRR coverage, identity tracking, queue, GDPR, consent mode, lifecycle mapper, auto-tracking, dedup, API, and pipeline. Returns per-subsystem scores, overall health status, and prioritized recommendations
- **Health Check API Endpoints** — `GET /api/analytics/health-check` for full diagnostic, `GET /api/analytics/ping` for lightweight monitoring (version + provider count + catalog size). Both public endpoints for dashboards and uptime monitoring
- **AnalyticsManager Convenience Methods** — `Analytics::healthCheck()` and `Analytics::ping()` for programmatic access from application code
- **Facade Annotations** — `healthCheck()`, `ping()`, `maturityScore()`, `onboardingChecklist()`, `funnelReadiness()` documented in `@method` annotations for IDE autocomplete
- **Service Registration** — `AnalyticsHealthCheckService` registered as singleton in ServiceProvider for dependency injection
- **Comprehensive Test Suite** — 25 new tests covering all health check subsystems, recommendation sorting, status determination, version consistency, and facade delegation
- **Version bump to 2.97.0** — All version assertions across source, config, JS client, TypeScript definitions, and tests updated

## What's New in v2.96.0

- **SaaS Lifecycle Convenience Methods** — `Analytics::signUp()`, `Analytics::login()` (with auto identity linking), `Analytics::trialStart()`, `Analytics::subscription()`, `Analytics::planUpgrade()`, `Analytics::cancellation()` — the 6 essential SaaS events as one-liners on the AnalyticsManager and Facade
- **SaaS Acquisition Funnel Shortcut** — `Analytics::trackSaaSAcquisition()` fires the full signup → trial → subscribe sequence in a single call, with `skip_trial` and custom params support
- **Inertia Page View Auto-Tracker** — `initInertiaPageViewTracker()` JS function with framework-agnostic Inertia navigation hooks (`inertia:navigate`, `inertia:success`, `popstate`), optional scroll depth, callback support, and proper cleanup. Works with Svelte, Vue, and React adapters
- **Fixed `initSvelteTracker` cleanup** — Event listeners now properly remove on component unmount instead of relying on broken `addEventListener` return value
- **TypeScript `InertiaPageViewTrackerOptions`** — Full type definitions for the new auto-tracker options
- **Facade annotations** — All new SaaS methods documented in `@method` annotations for IDE autocomplete
- **Admin command features** — Overview command updated with SaaS lifecycle convenience methods
- **Version bump to 2.96.0** — All version assertions across source, config, JS client, TypeScript definitions, and tests updated

## What's New in v2.95.0

- **Server-Sent Events (SSE) Controller** — Real-time event streaming via persistent HTTP connections with cursor-based resume, event filtering, category filtering, and configurable heartbeat. Endpoints: `GET /api/analytics/sse`, `/sse/info`, `/sse/health`
- **EventWindowAggregator** — Time-windowed event counting (minute/hour/day) in cache for dashboard sparkline charts. Provides `lastNMinutes()`, `lastNHours()`, `lastNDays()` with configurable TTLs
- **FeatureAdoptionTracker** — Per-user feature adoption tracking with streak detection, adoption funnels, and PLG (product-led growth) analysis. Tracks first/last use timestamps and use counts per feature
- **AnalyticsApiGuard** — Centralized API request validation with payload size limits, event name length checks, batch size limits, and per-client sliding window rate limiting
- **JS Client SSE support** — `connectSSE()` function using native EventSource API with `onEvent`, `onHeartbeat`, `onClose` callbacks. Plus `fetchSSEInfo()` and `fetchSSEHealth()` helpers
- **TypeScript definitions** — Full type coverage for SSE (SSEInfo, SSEEventData, SSEConnection, SSEConnectOptions), Feature Adoption (FeatureAdoptionProfile, FeatureAdoptionFunnelStep)
- **Config expansion** — New `sse`, `windowed_aggregation`, `feature_adoption`, and `api_guard` config sections with env-driven settings
- **EventStreamService enhancements** — Added `getEventsSince()`, `getBufferSize()`, `getCurrentCount()`, `getCurrentCursor()`, `getBufferUtilization()` for SSE controller integration
- **Version bump to 2.95.0** — All version assertions across source, config, JS client, TypeScript definitions, and tests updated

## What's New in v2.94.0

- **SchemaDrivenEventBuilder** — Schema-driven event builder that validates parameters against EventPropertySchema and EventSchemaRegistry for type coercion, default values, and required field enforcement
- **SchemaDiffReporter** — Schema coverage and diff reporter comparing EventCatalog, EventPropertySchema, and EventSchemaRegistry for gap analysis
- **EventPropertySchema::registerBuiltInSchemas()** — Full catalog schema coverage with typed property schemas for all e-commerce, SaaS, engagement, and lifecycle events
- **AnalyticsSchemaExportCommand** — `zb:analytics:schema:export` Artisan command to export event schemas as JSON, TypeScript, or summary with optional coverage report
- **Phase 2-3-4 production readiness audit** — Tests updated for new services and commands (9 console commands, SchemaDrivenEventBuilder, SchemaDiffReporter finality)

## What's New in v2.93.0

- **FunnelProgressTracker** — Cache-persisted funnel progress tracking with completion percentage, step timing, automatic advancement/regression detection, and `funnel_step`/`funnel_completed` event dispatch. Configurable TTL and known funnel names.
- **AnalyticsManager::funnelProgress()** — Convenience method that delegates to FunnelProgressTracker for stateful funnel tracking
- **Funnel progress config** — New `funnel_progress` config section with `ANALYTICS_FUNNEL_PROGRESS_ENABLED` toggle and customizable `known_funnels` list
- **Facade** — `Analytics::funnelProgress()` documented in Facade @method annotations
- **Version bump to 2.93.0** — All 100+ version assertions across source, config, JS client, TypeScript definitions, and tests updated
- **22 new tests** — V93FunnelProgressTrackerTest covering FunnelProgressTracker structure, public method signatures, functional behavior (advancement, regression, completion), config integration, AnalyticsManager delegation, ServiceProvider registration, and version consistency

## What's New in v2.92.0

- **Onboarding Completion Service** — OnboardingCompletionService tracks multi-step user onboarding with configurable required/optional milestones, time-to-completion tracking, completion percentage, and automatic `onboarding_completed` event dispatch
- **EventCatalog::enterpriseComplianceEvents()** — GDPR Article 30, SOC2 CC7, ISO 27001 compliance event set for enterprise audit trails (24 events)
- **EventCatalog::dauMauEvents()** — DAU/MAU stickiness tracking event set (8 core engagement events)
- **EventCatalog::productHealthEvents()** — Product stability, quality, and system health monitoring events
- **Onboarding tracking config** — New `onboarding_tracking` config section with required/optional steps, cache TTL, and prefix
- **Version bump to 2.92.0** — Config, ServiceProvider, schema versioning updated
- **13 new tests** — V92OnboardingComplianceDauMauTest covering new catalog methods, OnboardingCompletionService, version consistency, and file quality

## What's New in v2.91.0

- **Privacy Sandbox & Cart Affinity** — PrivacySandboxService for first-party data strategies, CartStateManager for abandoned cart analytics with item-level tracking
- **Event Affinity Service** — EventAffinityService for cross-event correlation and user behavior pattern detection
- **License header normalization** — FeatureFlagIntegrationService updated to use standard ZeroBoiler license header
- **Version consistency sweep** — All 158 hardcoded version assertions across test files updated to 2.91.0
- **Stale version reference cleanup** — V43 stale version guard updated to check for removed 2.90.0 references

## What's New in v2.88.0

- **Phase 2-3-4 production readiness** — Comprehensive Phase234ProductionTest expanded from 6 to 40+ assertions: strict types, license headers, return types on all public methods (AnalyticsManager, AnalyticsMetrics, EventInterceptorRegistry), final class audit (core classes, DTOs, trackers, enterprise services), #[Override] validation, TrackerInterface compliance, DTO readonly checks, EventPriority enum integrity, version consistency across all sources, config section completeness, Facade @method documentation, ServiceProvider binding audit (80+ singletons, 7 commands)
- **Version consistency fix** — All hardcoded VERSION assertions across 15+ test files updated to match current version
- **CHANGES.md removed** — Single source of truth is CHANGELOG.md

## What's New in v2.87.0

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
- **Pest PHP** — 300+ tests across 110+ test files
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

This validates strict types, `final` modifiers, interface implementations, readonly DTOs, composer metadata, and absence of TODO/FIXME markers across all 224 source files.

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
