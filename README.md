# ZeroBoiler Analytics

[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Laravel 13+](https://img.shields.io/badge/Laravel-13%2B-red.svg)](https://laravel.com)
[![PHP 8.5+](https://img.shields.io/badge/PHP-8.5%2B-8892BF.svg)](https://www.php.net)

Industry-standard SaaS analytics for Laravel — production-ready event tracking across **6 providers** (GA4, GTM, Meta Pixel, Plausible, PostHog, and generic HTTP) with a fully-featured JS client, auto-tracking, queue dispatch, identity resolution, and GDPR consent.

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

## Features

### Multi-Provider Tracking
- **GA4** — Measurement Protocol (server-side) + gtag.js (client-side), debug/validation endpoint
- **GTM** — dataLayer push + ecommerce events
- **Meta Pixel** — Conversions API (CAPI/server) + fbq.js (client)
- **Plausible Analytics** — Privacy-focused server-side tracking
- **PostHog** — Product analytics with $set, $create_alias, $reset
- All trackers implement `TrackerInterface` for easy extension

### Event System
- **32 typed event classes** across 3 categories (E-commerce, SaaS, Engagement)
- **EventCatalog** — Unified registry for event lookup, cross-provider name mapping, and category filtering
- **EventSchemaRegistry** — 30+ event schemas with typed parameters, validation, and custom schema registration
- **CustomEvent** — Arbitrary event name + params for one-off tracking

### Event Processing
- **Middleware Stack** — Priority-ordered, composable middleware (consent gate, context attachment, schema validation, timestamp, logging)
- **Event Pipeline** — Lightweight pipe chain for UTM enrichment, user context, consent filtering, and timestamp enrichment
- **Event Context Builder** — Auto-collects user identity, client ID, session, UTM, page, and device context from the request
- **Event Validation** — Name validation, parameter sanitization, deduplication, and strict whitelist mode

### SaaS Analytics
- **SaaS Lifecycle Events** — SignUp, Login, Logout, TrialStart, TrialEnd, Subscription, PlanUpgrade, PlanDowngrade, Cancellation, FeatureUsed, Revenue
- **SaaSAnalyticsService** — Convenience methods for all lifecycle events + custom events
- **RevenueAnalyticsService** — MRR, ARR, one-time, add-on, upgrade, downgrade, churn revenue tracking
- **RevenueAttributionService** — Revenue tracking with UTM attribution, MRR changes, LTV estimates, cohort revenue, and revenue breakdown by channel
- **FunnelAnalyticsService** — Multi-step conversion funnel tracking with step tracking, abandonment detection, completion rate, and retry tracking
- **Server-Side Auto-Tracking** — Config-driven mapping of Laravel auth events and custom app events to analytics events
- **Session & Funnel Tracker** — Session start/end, page counts, duration, and conversion funnel step tracking
- **AnalyticsDataBus** — Rule-based event routing to selectively dispatch events to specific providers by name, category, param, or PII detection

### E-commerce
- **8 E-commerce Events** — ViewItem, AddToCart, RemoveFromCart, ViewCart, BeginCheckout, AddPaymentInfo, Purchase, Refund
- **EcommerceAnalyticsService** — Full e-commerce flow convenience methods
- **GA4 ↔ Meta Format Conversion** — Automatic cross-provider event name and parameter mapping (JS + PHP)

### Identity & GDPR
- **User Identity Linking** — Client ID ↔ User ID association for cross-device identification
- **Identity Alias** — Merge anonymous → authenticated profiles (PostHog $create_alias compatible)
- **User Properties** — Set user traits across all providers (PostHog $set, GA4 user properties)
- **GDPR Identity Reset** — `resetIdentity()` for right-to-be-forgotten compliance
- **Consent Mode v2** — Full granular consent (analytics_storage, ad_storage, ad_user_data, ad_personalization, functionality_storage, security_storage)

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
- `GET /api/analytics/health` — Health check (public, no auth)

### JS Client Library (`resources/js/analytics.js`)
- **Init** — Reads config from Inertia `zbAnalytics` prop, auto-initializes all enabled providers
- **Event Tracking** — `trackEvent()` with auto-batch queue (5s / 25 events) + immediate mode
- **Page View** — Client-side GA4/Meta/Plausible/PostHog push + server-side dispatch
- **E-commerce** — `trackEcommerce()` with automatic GA4 ↔ Meta format conversion
- **Screen View & A/B Test** — SPA navigation and experiment exposure tracking
- **Scroll Depth** — Fires at 25%, 50%, 75%, 90% thresholds (once per page view)
- **Form Tracking** — Auto-captures `form_start` and `form_submit` via event delegation
- **Error Tracking** — Captures `window.error` + `unhandledrejection` with configurable ignore patterns
- **Performance Tracking** — Web Vitals integration (LCP, CLS, INP) + Performance API timing
- **Link Tracking** — Auto-track outbound/internal link clicks with custom prefix
- **UTM Capture** — Auto-captures UTM params on init, persists across navigations, enriches all events
- **Identity** — `identify()`, `alias()`, `identifyWithTraits()`, `setUserProperties()`, `trackServerPageView()`
- **Cleanup** — All auto-trackers return cleanup functions for Svelte `onMount` compatibility

### Async Queue Dispatch
- Configurable queue connection and queue name
- `QueuedAnalyticsDispatcher` for background event processing
- `trackAsync()` facade shortcut with automatic sync fallback on failure

### Admin Commands
- `zb:analytics:overview` — Shows enabled providers, consent state, auto-track config, queue settings, identity config, ecommerce settings
- `zb:analytics:test` — Sends test event to all providers, supports `--validate` (GA4 debug) and `--event=` (custom name)

### Blade Integration
- `@analyticsHead` — GA4/GTM/Meta/Plausible/PostHog head script tags
- `@analyticsBody` — GTM noscript + Meta body script tags
- `InjectAnalyticsScripts` middleware for auto-injection

### Developer Experience
- **Debug Mode** — `ANALYTICS_DEBUG_ENABLED=true` logs events without dispatching, runtime toggle via `setDebug()`
- **Facade** — `Analytics::track()`, `Analytics::purchase()`, `Analytics::identify()`, `Analytics::pageView()`, `Analytics::logout()`, and 25+ more methods
- **Config-Driven** — 30+ environment variables, sensible defaults, zero-required-config to start
- **PHPStan 9** — Level max, full type coverage
- **Pest PHP** — 50+ tests
- **Pint** — Laravel coding style
- **Rector** — Automated code quality

## Architecture

```
src/
├── AnalyticsManager.php              # Core manager — dispatches to all 6 trackers
├── AnalyticsServiceProvider.php       # Laravel service provider (registers everything)
├── Bus/
│   └── AnalyticsDataBus.php          # Rule-based conditional event routing
├── Context/
│   └── EventContextBuilder.php        # Auto-collect request context (user, UTM, session, device)
├── DTO/
│   ├── AnalyticsEvent.php            # Immutable event DTO (name, params, clientId, userId)
│   ├── ConsentState.php              # GDPR consent state (6 granular signals)
│   └── UtmAttribution.php            # UTM campaign attribution DTO
├── Events/
│   ├── Ecommerce/                    # 8 e-commerce event classes + EcommerceEvents catalog
│   ├── SaaS/                         # 11 SaaS lifecycle event classes + SaaSEvents catalog
│   ├── Engagement/                   # 13 engagement event classes + EngagementEvents catalog
│   ├── CustomEvent.php               # Generic custom event
│   └── EventCatalog.php              # Unified catalog (32 events, cross-provider mappings)
├── Middleware/
│   ├── AnalyticsMiddlewareInterface.php   # Middleware contract
│   ├── AnalyticsMiddlewareStack.php       # Priority-ordered middleware stack
│   ├── ConsentGateMiddleware.php          # Consent-based event filtering
│   ├── ContextAttachmentMiddleware.php    # Auto-attach context to events
│   ├── SchemaValidationMiddleware.php     # Schema-aware event validation
│   ├── TimestampMiddleware.php            # Auto-add timestamps
│   └── LoggingMiddleware.php              # Debug event logging
├── Schema/
│   ├── EventSchema.php             # Event parameter schema definition
│   ├── EventParam.php              # Parameter type & constraints
│   └── EventSchemaRegistry.php    # Central schema registry (30+ events)
├── Pipeline/
│   ├── EventPipeline.php            # Middleware pipeline for event processing
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
│   └── EventValidationService.php      # Event validation & deduplication
├── Tracking/
│   ├── ServerSideTracker.php       # Auto-track Laravel auth events + custom app events
│   ├── UserIdentityTracker.php     # User ↔ client linking (login, register, logout)
│   └── SessionTracker.php          # Session, funnel, and conversion tracking
├── Queue/
│   └── QueuedAnalyticsDispatcher.php   # Async queue dispatch (configurable)
├── Http/
│   ├── Controllers/AnalyticsEventController.php  # 6 API endpoints + event pipeline
│   └── Middleware/InjectAnalyticsScripts.php       # Auto-inject analytics scripts
├── Inertia/
│   └── HandleInertiaAnalytics.php   # Inertia page prop injection + tracking ID cookie
├── Console/Commands/
│   ├── AnalyticsOverviewCommand.php  # Config overview
│   └── AnalyticsTestCommand.php     # Test event dispatch
├── Blade/Directives/
│   └── AnalyticsDirectives.php      # @analyticsHead, @analyticsBody
├── Facades/
│   └── Analytics.php               # Facade (20+ methods)
resources/
└── js/
    └── analytics.js                 # ES module client library (~1200 LOC)
config/
└── zeroboiler.php                   # 30+ config options across 12 sections
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
], ['currency' => 'USD', 'coupon' => 'WELCOME20']);

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

### Engagement Events

```php
use ZeroBoiler\Analytics\Events\Engagement\{
    PageViewEvent, ScrollDepthEvent, ClickEvent, FormStartEvent,
    FormSubmitEvent, SearchEvent, ShareEvent, ErrorEvent, TimeOnPageEvent,
    ScreenViewEvent, AbTestExposureEvent, NotificationEvent, CampaignAttributionEvent,
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

// Unified catalog — 32 events across 3 categories
EventCatalog::count();          // 32
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
    init, destroy,
    trackEvent, trackPageView, trackScreenView,
    trackEcommerce, trackAbTestExposure,
    identify, alias, identifyWithTraits, setUserProperties,
    updateConsent, trackServerPageView,
    initScrollDepth, initInertiaPageViewTracker,
    initFormTracking, initErrorTracking,
    initLinkTracking, trackPerformance,
    flushQueue, getTrackingId, isInitialized,
    captureUTM, getUTMParams, clearUTMParams,
    pushToDataLayer, trackTiming,
} from '../resources/js/analytics';

// Initialize
$: if (page.props.zbAnalytics) {
    init(page.props);
}

// Setup tracking
onMount(() => {
    const cleanupScroll = initScrollDepth();
    const cleanupInertia = initInertiaPageViewTracker();
    const cleanupForm = initFormTracking();
    const cleanupErrors = initErrorTracking({
        ignorePatterns: ['ResizeObserver', 'Non-Error promise rejection'],
    });

    return () => {
        cleanupScroll();
        cleanupInertia();
        cleanupForm();
        cleanupErrors();
        destroy();
    };
});
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

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| `GET` | `/api/analytics/health` | No | Health check (providers, consent, version) |
| `POST` | `/api/analytics/events` | Yes | Track a single event |
| `POST` | `/api/analytics/batch` | Yes | Track up to 25 events |
| `POST` | `/api/analytics/identify` | Yes | Link client ID ↔ user ID + traits |
| `POST` | `/api/analytics/pageview` | Yes | Server-side page view |
| `POST` | `/api/analytics/consent` | Yes | Update consent signals |

All authenticated endpoints use `auth:sanctum` + throttle middleware (60 req/min).

### Health Response

```json
{
  "status": "ok",
  "version": "2.3.0",
  "providers": {
    "ga4": { "status": "ok", "measurement_id": "G-XXXXX" },
    "meta": { "status": "ok" }
  },
  "consent": { "analytics_storage": "granted", "ad_storage": "granted", "..." },
  "timestamp": "2026-08-06T12:00:00Z"
}
```

## Event Catalog Reference

### E-commerce Events

| Class | GA4 Name | Meta Equivalent |
|-------|----------|----------------|
| `ViewItemEvent` | view_item | ViewContent |
| `AddToCartEvent` | add_to_cart | AddToCart |
| `RemoveFromCartEvent` | remove_from_cart | — |
| `ViewCartEvent` | view_cart | — |
| `BeginCheckoutEvent` | begin_checkout | InitiateCheckout |
| `AddPaymentInfoEvent` | add_payment_info | AddPaymentInfo |
| `PurchaseEvent` | purchase | Purchase |
| `RefundEvent` | refund | — |

### SaaS Lifecycle Events

| Class | Event Name | Meta Equivalent |
|-------|-----------|-----------------|
| `SignUpEvent` | sign_up | CompleteRegistration |
| `LoginEvent` | login | — |
| `LogoutEvent` | logout | — |
| `TrialStartEvent` | start_trial | StartTrial |
| `TrialEndEvent` | end_trial | — |
| `SubscriptionEvent` | subscribe | Subscribe |
| `PlanUpgradeEvent` | plan_upgrade | — |
| `PlanDowngradeEvent` | plan_downgrade | — |
| `CancellationEvent` | cancellation | — |
| `FeatureUsedEvent` | feature_used | — |
| `RevenueEvent` | revenue_tracked | Purchase (mapped) |

### Engagement Events

| Class | Event Name |
|-------|-----------|
| `PageViewEvent` | page_view |
| `ScreenViewEvent` | screen_view |
| `ScrollDepthEvent` | scroll_depth |
| `ClickEvent` | click |
| `FormStartEvent` | form_start |
| `FormSubmitEvent` | form_submit |
| `SearchEvent` | search |
| `ShareEvent` | share |
| `ErrorEvent` | error |
| `TimeOnPageEvent` | time_on_page |
| `CampaignAttributionEvent` | campaign_attribution |
| `AbTestExposureEvent` | ab_test_exposure |
| `NotificationEvent` | notification |

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
| `destroy()` | Cleanup listeners, timers, state |
| `isInitialized()` | Check if analytics is active |
| `getTrackingId()` | Get server-generated tracking UUID |
| `trackEvent(name, params, options?)` | Track event (auto-batched, `{immediate: true}` to bypass) |
| `flushQueue()` | Flush batch queue immediately |
| `trackPageView(title?, location?, referrer?)` | Client + server page view |
| `trackScreenView(name, options?)` | SPA screen view |

### E-commerce

| Function | Description |
|----------|-------------|
| `trackEcommerce(name, data)` | Track with GA4 ↔ Meta conversion |

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
| `initScrollDepth()` | Scroll depth at 25/50/75/90% |
| `initInertiaPageViewTracker()` | Auto page view on navigation |
| `initFormTracking(options?)` | form_start + form_submit |
| `initErrorTracking(options?)` | JS errors + unhandled rejections |
| `initLinkTracking(options?)` | Outbound/internal link clicks |

### UTM

| Function | Description |
|----------|-------------|
| `captureUTM()` | Capture UTM from URL |
| `getUTMParams()` | Get current UTM params |
| `hasUTMParams()` | Check if UTM captured |
| `clearUTMParams()` | Clear UTM state |

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
```

## Testing

```bash
composer test          # Run all Pest tests
composer test:ci      # Run with coverage
composer ci           # Full CI (Pint + PHPStan 9 + Rector + Tests)
```

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

### From v2.0.x to v2.1.0
No breaking changes. Update via:

```bash
composer update zeroboiler/analytics
```

New features available:
- `Analytics::trackEcommerce('purchase', $data)` — auto-formats for GA4 + Meta
- `EventCatalog::search('cart')` — find events by partial name
- `EventCatalog::byProvider()` — group events by provider
- New config option: `ANALYTICS_ECOMMERCE_TAX_BEHAVIOR`

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

### v2.3.0 — DataBus Integration, Test Suite Expansion, v2.3.0 hardening
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
