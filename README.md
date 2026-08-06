# ZeroBoiler Analytics

Industry-standard SaaS analytics for Laravel — complete event tracking across GA4, GTM, Meta Pixel, Plausible, and PostHog.

## Features

- **Multi-Provider Tracking** — GA4 (Measurement Protocol + client), GTM (dataLayer + ecommerce), Meta Pixel (CAPI + client), Plausible, PostHog
- **Event Catalog** — Typed event classes for E-commerce, SaaS lifecycle, Engagement, and Custom events
- **Event Schema Registry** — Centralized schema definitions for 30+ events with typed parameters, validation, and provider-specific name mappings
- **Middleware Stack** — Priority-ordered, composable middleware system (consent gate, context attachment, schema validation, timestamp, logging)
- **Event Context Builder** — Auto-collects user identity, client ID, session, UTM, page, and device context from the request
- **Event Pipeline** — Middleware chain for filtering, enriching, and transforming events before dispatch (UTM, user context, consent, timestamps)
- **Revenue Analytics** — MRR, ARR, one-time, add-on, upgrade, downgrade, and churn revenue tracking service
- **UTM Campaign Attribution** — Auto-capture UTM params (server-side + client-side), attach to all events for marketing attribution
- **Server-Side Auto-Tracking** — Automatically tracks Laravel auth events (login, register, logout) and custom app events (subscriptions, trials, features)
- **Inertia.js Integration** — Middleware injects analytics config into page props for Svelte/Vue/React
- **API Endpoints** — Server-side endpoints for frontend event tracking (track, batch, identify, consent, health)
- **JS Client Library** — ES module for Svelte/Inertia with auto page view, scroll depth, form tracking, error tracking, performance monitoring, and batch queue
- **Async Queue Dispatch** — Queue analytics events to background workers (configurable)
- **User Identity Linking** — Cross-device identification via client ID ↔ user ID association
- **E-commerce Helpers** — High-level service for view item, cart, checkout, purchase, refund with GA4 + Meta format conversion
- **SaaS Analytics Service** — Convenience methods for SaaS lifecycle: sign-up, login, trial, subscription, plan changes, cancellation, feature usage
- **Session Tracker** — Session start/end, page counts, duration tracking, conversion funnel monitoring
- **Event Validation** — Event name validation, parameter sanitization, and deduplication
- **Consent Mode v2** — Full GDPR compliance with granular consent signals
- **Blade Directives** — `@analyticsHead`, `@analyticsBody` for traditional Laravel apps
- **Admin Commands** — `zb:analytics:overview` and `zb:analytics:test`
- **Debug Mode** — Development-friendly logging without dispatching to providers, runtime toggle via `setDebug()`
- **Server-Side Event Validation** — Auto-validation and sanitization on API endpoints, deduplication, strict whitelist mode
- **Event Catalog** — Static catalogs (`EcommerceEvents`, `SaaSEvents`, `EngagementEvents`) for event lookup, validation, and cross-provider name mapping
- **GDPR Identity Reset** — `resetIdentity()` for right-to-be-forgotten across GA4 and PostHog

## Installation

```bash
composer require zeroboiler/analytics
```

Publish the configuration:

```bash
php artisan vendor:publish --tag=zeroboiler-analytics-config
```

## Configuration

All settings are in `config/zeroboiler.php` under the `analytics` key.

### Environment Variables

```env
# GA4
ANALYTICS_GA4_ENABLED=false
ANALYTICS_GA4_MEASUREMENT_ID=G-XXXXXXXXXX
ANALYTICS_GA4_API_SECRET=your_secret

# GTM
ANALYTICS_GTM_ENABLED=false
ANALYTICS_GTM_CONTAINER_ID=GTM-XXXXXXX

# Meta Pixel
ANALYTICS_META_PIXEL_ENABLED=false
ANALYTICS_META_PIXEL_ID=123456789
ANALYTICS_META_PIXEL_ACCESS_TOKEN=your_token

# Plausible (optional)
ANALYTICS_PLAUSIBLE_ENABLED=false
ANALYTICS_PLAUSIBLE_DOMAIN=example.com
ANALYTICS_PLAUSIBLE_API_KEY=your_key

# PostHog (optional)
ANALYTICS_POSTHOG_ENABLED=false
ANALYTICS_POSTHOG_API_KEY=your_key
ANALYTICS_POSTHOG_HOST=https://eu.posthog.com

# Consent
ANALYTICS_CONSENT_DEFAULT=granted

# Auto-Track
ANALYTICS_AUTO_TRACK_ENABLED=true

# Queue
ANALYTICS_QUEUE_ENABLED=true
ANALYTICS_QUEUE=analytics
ANALYTICS_QUEUE_CONNECTION=

# API
ANALYTICS_API_ENABLED=true
ANALYTICS_API_THROTTLE=60

# Identity
ANALYTICS_IDENTITY_COOKIE=zb_analytics_id
ANALYTICS_IDENTITY_COOKIE_TTL=525600

# E-commerce
ANALYTICS_ECOMMERCE_CURRENCY=USD
ANALYTICS_ECOMMERCE_BRAND=

# Debug
ANALYTICS_DEBUG_ENABLED=false
ANALYTICS_DEBUG_LOG_EVENTS=false

# Event Validation
ANALYTICS_VALIDATION_STRICT=false
ANALYTICS_VALIDATION_MAX_NAME_LENGTH=100
ANALYTICS_VALIDATION_DEDUP_WINDOW=10

# Event Pipeline
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
```

### E-commerce Tracking

```php
use ZeroBoiler\Analytics\Facades\Analytics;

// Quick purchase tracking
Analytics::purchase('TXN-12345', 99.99, [
    ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2],
], ['currency' => 'USD', 'coupon' => 'WELCOME20']);

// Full e-commerce flow
use ZeroBoiler\Analytics\Services\EcommerceAnalyticsService;

$ecommerce = app(EcommerceAnalyticsService::class);

$ecommerce->viewItem(['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99]);
$ecommerce->addToCart(['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 1]);
$ecommerce->viewCart([['item_id' => 'SKU-001', 'price' => 49.99]], 49.99);
$ecommerce->beginCheckout([['item_id' => 'SKU-001', 'price' => 49.99]], 49.99, ['coupon' => 'SAVE10']);
$ecommerce->addPaymentInfo('credit_card');
$ecommerce->purchase('TXN-12345', 49.99, [['item_id' => 'SKU-001', 'price' => 49.99, 'quantity' => 1]]);
```

### SaaS Lifecycle Events

```php
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Events\SaaS\{SignUpEvent, TrialStartEvent, SubscriptionEvent};

Analytics::trackEvent(new SignUpEvent(method: 'github'));
Analytics::trackEvent(new TrialStartEvent(plan: 'pro', trialDays: 14));
Analytics::trackEvent(new SubscriptionEvent(plan: 'pro', value: 29.99, currency: 'USD'));
Analytics::trackEvent(new PlanUpgradeEvent(fromPlan: 'starter', toPlan: 'pro'));
Analytics::trackEvent(new CancellationEvent(plan: 'pro', reason: 'too_expensive'));
Analytics::trackEvent(new FeatureUsedEvent(feature: 'export', usageCount: 5));
```

### SaaS Analytics Service (Convenience)

```php
use ZeroBoiler\Analytics\Services\SaaSAnalyticsService;

$saas = app(SaaSAnalyticsService::class);

$saas->trackSignUp('google');
$saas->trackLogin('sanctum');
$saas->trackTrialStart('pro', 14);
$saas->trackSubscription('business', 99.99, 'EUR');
$saas->trackPlanUpgrade('starter', 'pro');
$saas->trackCancellation('pro', 'too_expensive');
$saas->trackFeatureUsed('export', 5);
$saas->trackCustomEvent('onboarding_complete', ['step_count' => 5]);
```

### Revenue Analytics Service

```php
use ZeroBoiler\Analytics\Services\RevenueAnalyticsService;

$revenue = app(RevenueAnalyticsService::class);

// Track MRR / ARR
$revenue->trackMRR(5000.00, 120);             // amount, subscriber count
$revenue->trackARR(60000.00, 120, 'EUR');      // custom currency

// One-time and add-on revenue
$revenue->trackOneTime(49.99, 'setup fee');
$revenue->trackAddon(15.00, 'extra_storage', 'pro');

// Plan changes
$revenue->trackUpgradeRevenue(29.99, 9.99, 'starter', 'pro');
$revenue->trackDowngradeRevenue(9.99, 29.99, 'pro', 'starter');
$revenue->trackChurnRevenue(29.99, 'pro', 'too_expensive');

// Custom revenue events
$revenue->trackCustom(75.00, 'expansion', 'enterprise', ['team_size' => 10]);
```

### Revenue & Campaign Events

```php
use ZeroBoiler\Analytics\Events\SaaS\RevenueEvent;
use ZeroBoiler\Analytics\Events\Engagement\CampaignAttributionEvent;

// Revenue event for billing/metrics tracking
Analytics::trackEvent(new RevenueEvent(
    amount: 29.99,
    currency: 'USD',
    revenueType: 'mrr',
    planName: 'pro',
));

// Campaign attribution from UTM parameters
Analytics::trackEvent(new CampaignAttributionEvent(
    source: 'google',
    medium: 'cpc',
    campaign: 'spring_sale',
    term: 'analytics tool',
    landingPage: 'https://example.com/pricing',
));
```

### Event Pipeline

Process events through a middleware pipeline before dispatch:

```php
use ZeroBoiler\Analytics\Pipeline\EventPipeline;
use ZeroBoiler\Analytics\Pipeline\{ConsentFilter, UtmEnricher, UserContextEnricher, TimestampEnricher};

$pipeline = new EventPipeline;

// Drop events when analytics consent is denied
$pipeline->pipe(new ConsentFilter($consentGranted));

// Auto-attach UTM params from request
$pipeline->pipe(new UtmEnricher($request->query->all()));

// Attach authenticated user context
$pipeline->pipe(new UserContextEnricher(['user_id' => '42', 'user_plan' => 'pro']));

// Add timestamp and session ID
$pipeline->pipe(new TimestampEnricher($sessionId));

// Process event — returns null if filtered out
$processed = $pipeline->process($event);

if ($processed !== null) {
    Analytics::trackEvent($processed);
}
```

Or use the pre-configured pipeline with sensible defaults:

```php
$pipeline = EventPipeline::withDefaults($request->query->all(), true, $sessionId);
$result = $pipeline->process($event);
```

### Session Tracking

```php
use ZeroBoiler\Analytics\Tracking\SessionTracker;

$session = app(SessionTracker::class);

// Start a session
$session->startSession($sessionId, ['source' => 'email_campaign']);

// Track pages within the session
$session->trackSessionPageView($sessionId, ['page' => '/pricing']);

// Track conversion funnels
$session->trackFunnelStep('signup', 'landing', 1);
$session->trackFunnelStep('signup', 'form', 2);
$session->trackFunnelStep('signup', 'confirm', 3);
$session->trackFunnelComplete('signup', 3);

// Track abandonment
$session->trackFunnelAbandon('purchase', 'checkout', 4);

// End session
$session->endSession($sessionId); // Tracks duration + page count
```

### Event Validation

```php
use ZeroBoiler\Analytics\Services\EventValidationService;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

$validator = app(EventValidationService::class);

$result = $validator->validate(new AnalyticsEvent(
    name: 'page_view',
    params: ['key' => 'value'],
));

if ($result['valid']) {
    Analytics::trackEvent($result['event']);
} else {
    foreach ($result['errors'] as $error) {
        Log::warning("Analytics validation: {$error}");
    }
}

// Enable strict mode via config to only allow whitelisted events
```

### Engagement Events

```php
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Events\Engagement\{
    PageViewEvent, ScrollDepthEvent, ClickEvent, FormStartEvent,
    FormSubmitEvent, SearchEvent, ShareEvent, ErrorEvent, TimeOnPageEvent
};

Analytics::trackEvent(new PageViewEvent('Pricing', 'https://example.com/pricing'));
Analytics::trackEvent(new ScrollDepthEvent(percent: 75, page: '/blog/article-1'));
Analytics::trackEvent(new SearchEvent(query: 'analytics laravel', results: 42));
Analytics::trackEvent(new FormSubmitEvent(formName: 'contact', formId: 'form-123'));
Analytics::trackEvent(new ErrorEvent(code: 404, message: 'Page not found', url: '/missing'));
```

### Custom Events

```php
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Events\CustomEvent;

Analytics::trackEvent(new CustomEvent('tutorial_completed', [
    'tutorial_id' => 'getting-started',
    'duration_seconds' => 300,
]));
```

### Event Catalog

Static catalogs provide a central registry for looking up event names, classes, and cross-provider mappings:

```php
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;

// Unified catalog — 29 events across 3 categories
EventCatalog::count();          // 29
EventCatalog::names();          // ['view_item', 'add_to_cart', 'sign_up', ...]
EventCatalog::has('purchase');  // true
EventCatalog::classFor('purchase'); // PurchaseEvent::class

// Grouped by category
$byCategory = EventCatalog::byCategory();
// ['ecommerce' => [...], 'saas' => [...], 'engagement' => [...]]

// Per-category catalogs
EcommerceEvents::names();   // ['view_item', 'add_to_cart', ...]
SaaSEvents::names();        // ['sign_up', 'login', 'subscribe', ...]
EngagementEvents::names();  // ['page_view', 'scroll_depth', 'click', ...]

// Cross-provider mappings
$event = EcommerceEvents::get('purchase');
$event['ga4'];  // 'purchase'
$event['meta']; // 'Purchase'
$event['class']; // PurchaseEvent::class

// All GA4 names (deduplicated)
EventCatalog::allGa4Names();
// All Meta Pixel names
EventCatalog::allMetaNames();
```

### GDPR Identity Reset

```php
use ZeroBoiler\Analytics\Facades\Analytics;

// When a user requests account deletion or data erasure:
Analytics::resetIdentity();

// This sends:
// - GA4: clears cached user ID
// - PostHog: sends $reset event to disassociate future events
```

### Consent Management

```php
use ZeroBoiler\Analytics\Facades\Analytics;

// Apply consent state
Analytics::setConsent(ConsentState::granted());
Analytics::denyConsent();

// Granular consent
$state = Analytics::getConsent()->with([
    'analytics_storage' => 'granted',
    'ad_storage' => 'denied',
]);
Analytics::setConsent($state);
```

### Queue (Async Dispatch)

```php
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;

$queue = app(QueuedAnalyticsDispatcher::class);

// Queue a single event
$queue->dispatch(new AnalyticsEvent('async_event', ['key' => 'value']));

// Queue multiple events as a batch
$queue->dispatchBatch([
    new AnalyticsEvent('event_1', ['key' => 'value1']),
    new AnalyticsEvent('event_2', ['key' => 'value2']),
]);
```

### Server-Side Auto-Tracking

The `ServerSideTracker` automatically listens for Laravel auth events:

```php
// These are tracked automatically:
// - Illuminate\Auth\Events\Login → LoginEvent
// - Illuminate\Auth\Events\Registered → SignUpEvent
// - Illuminate\Auth\Events\Logout → LogoutEvent

// Custom events are configured in config:
// 'auto_track' => [
//     'events' => [
//         'subscription.created' => true,
//         'trial.started' => true,
//     ],
// ],
```

### User Identity

```php
use ZeroBoiler\Analytics\Tracking\UserIdentityTracker;

$identity = app(UserIdentityTracker::class);

// On login
$identity->onLogin($user, $request);

// On register
$identity->onRegister($user, $request);

// Manual identify
$identity->identify($userId, $clientId);
```

## Inertia.js Integration

### Server-Side (Middleware)

Add the `analytics.inertia` middleware to your web routes:

```php
// routes/web.php
Route::middleware(['web', 'analytics.inertia'])->group(function () {
    // Your Inertia routes
});
```

### Client-Side (Svelte)

```javascript
import { page } from '@inertiajs/svelte';
import {
    init,
    destroy,
    trackPageView,
    trackEvent,
    trackEcommerce,
    identify,
    updateConsent,
    initScrollDepth,
    initInertiaPageViewTracker,
    initFormTracking,
    initErrorTracking,
    flushQueue,
} from '../resources/js/analytics';

// Initialize on app boot
$: if (page.props.zbAnalytics) {
    init(page.props);
}

// Setup tracking on mount
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

## JS Client Library

### Event Tracking

```javascript
import { trackEvent, flushQueue } from '../resources/js/analytics';

// Events are batched automatically (flushed every 5 seconds or 25 events)
await trackEvent('button_click', { element: 'buy_now' });

// Force immediate flush
await flushQueue();

// Immediate (non-batched) dispatch
await trackEvent('purchase', { value: 99.99 }, { immediate: true });
```

### E-commerce

```javascript
import { trackEcommerce } from '../resources/js/analytics';

await trackEcommerce('purchase', {
    transaction_id: 'TXN-12345',
    value: 99.99,
    currency: 'USD',
    items: [{ item_id: 'SKU-001', item_name: 'Widget', price: 49.99, quantity: 2 }],
});
```

### Auto Form Tracking

```html
<form data-analytics-form="contact" ...>
```

```javascript
import { initFormTracking } from '../resources/js/analytics';
const cleanup = initFormTracking(); // Tracks form_start + form_submit
```

### Auto Error Tracking

```javascript
import { initErrorTracking } from '../resources/js/analytics';

const cleanup = initErrorTracking({
    trackErrors: true,
    trackRejections: true,
    ignorePatterns: ['ResizeObserver', 'Non-Error promise rejection'],
});
```

### Performance Tracking

```javascript
import { trackPerformance } from '../resources/js/analytics';

// With web-vitals library:
import { onLCP, onCLS, onINP } from 'web-vitals';
onLCP(metric => trackPerformance('LCP', metric.value));
onCLS(metric => trackPerformance('CLS', metric.value));
onINP(metric => trackPerformance('INP', metric.value));
```

## Blade Directives (Traditional Laravel)

```blade
<!DOCTYPE html>
<html>
<head>
    @analyticsHead
</head>
<body>
    @analyticsBody

    <!-- Your content -->

    <script>
        function trackClick(element) {
            fetch('/api/analytics/events', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Analytics-Client-Id': getTrackingCookie(),
                },
                body: JSON.stringify({
                    name: 'click',
                    params: { element: element }
                }),
            });
        }
    </script>
</body>
</html>
```

## API Endpoints

All authenticated endpoints require `auth:sanctum` and are rate-limited (60 req/min).

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/analytics/health` | No | Health check for monitoring |
| POST | `/api/analytics/events` | Yes | Track a single event |
| POST | `/api/analytics/batch` | Yes | Track up to 25 events |
| POST | `/api/analytics/identify` | Yes | Link client ID ↔ user ID |
| POST | `/api/analytics/consent` | Yes | Update consent signals |

### Health Check

```json
GET /api/analytics/health

Response: {
    "status": "ok",
    "version": "1.6.0",
    "providers": {
        "ga4": { "status": "ok", "measurement_id": "G-XXXXX" },
        "meta": { "status": "ok" }
    },
    "consent": { "analytics_storage": "granted", ... },
    "timestamp": "2026-08-05T12:00:00Z"
}
```

### Track Event

```json
POST /api/analytics/events
Headers:
  X-Analytics-Client-Id: <tracking-id>
  Authorization: Bearer <token>

Body: {
    "name": "button_click",
    "params": { "element": "buy_now", "page": "/products" }
}
```

### Batch Events

```json
POST /api/analytics/batch

Body: {
    "events": [
        { "name": "scroll_depth", "params": { "percent": 75 } },
        { "name": "time_on_page", "params": { "seconds": 30 } }
    ]
}
```

## Admin Commands

### Overview

```bash
php artisan zb:analytics:overview
```

Shows: enabled providers, consent state, auto-track config, queue settings, identity config, ecommerce settings.

### Test

```bash
# Send a test event to all enabled providers
php artisan zb:analytics:test

# Use GA4 debug endpoint
php artisan zb:analytics:test --validate

# Custom event name
php artisan zb:analytics:test --event=custom_test
```

## Event Catalog

### E-commerce Events

| Class | GA4 Name | Meta Equivalent |
|-------|----------|----------------|
| `ViewItemEvent` | view_item | ViewContent |
| `AddToCartEvent` | add_to_cart | AddToCart |
| `RemoveFromCartEvent` | remove_from_cart | — |
| `BeginCheckoutEvent` | begin_checkout | InitiateCheckout |
| `AddPaymentInfoEvent` | add_payment_info | AddPaymentInfo |
| `PurchaseEvent` | purchase | Purchase |
| `RefundEvent` | refund | — |
| `ViewCartEvent` | view_cart | — |

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
| `ScrollDepthEvent` | scroll_depth |
| `ClickEvent` | click |
| `FormStartEvent` | form_start |
| `FormSubmitEvent` | form_submit |
| `SearchEvent` | search |
| `ShareEvent` | share |
| `ErrorEvent` | error |
| `TimeOnPageEvent` | time_on_page |
| `CampaignAttributionEvent` | campaign_attribution |

### Generic

| Class | Description |
|-------|-------------|
| `CustomEvent` | Arbitrary event name + params |

### Revenue Analytics

```php
use ZeroBoiler\Analytics\Services\RevenueAnalyticsService;

$revenue = app(RevenueAnalyticsService::class);

// Track MRR
$revenue->trackMRR(5000.0, 42); // $5,000 MRR, 42 subscribers

// Track ARR
$revenue->trackARR(60000.0, 42);

// Track one-time revenue
$revenue->trackOneTime(149.99, 'consulting_fee');

// Track add-on revenue
$revenue->trackAddon(9.99, 'extra_storage', 'pro');

// Track upgrade/downgrade revenue impact
$revenue->trackUpgradeRevenue(99.99, 29.99, 'starter', 'pro');
$revenue->trackDowngradeRevenue(29.99, 99.99, 'pro', 'starter');

// Track churn revenue loss
$revenue->trackChurnRevenue(29.99, 'pro', 'too_expensive');
```

### Event Pipeline

```php
use ZeroBoiler\Analytics\Pipeline\EventPipeline;
use ZeroBoiler\Analytics\Pipeline\UtmEnricher;
use ZeroBoiler\Analytics\Pipeline\ConsentFilter;
use ZeroBoiler\Analytics\Pipeline\TimestampEnricher;

$pipeline = new EventPipeline;

// Add UTM enrichment from request
$pipeline->pipe(new UtmEnricher($request->only([
    'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
])));

// Filter events when consent is denied
$pipeline->pipe(new ConsentFilter(
    $manager->getConsent()->hasAnalyticsConsent()
));

// Add timestamp and session context
$pipeline->pipe(new TimestampEnricher($sessionId));

// Process event through pipeline
$result = $pipeline->process($event);
if ($result !== null) {
    $manager->trackEvent($result);
}

// Or use defaults (UTM + user context):
$pipeline = EventPipeline::withDefaults($context);
```

UTM enrichment is automatically applied to all API events when `ANALYTICS_PIPELINE_AUTO_UTM=true`.

### UTM Campaign Tracking (JS)

```javascript
import { captureUTM, getUTMParams, hasUTMParams, init } from '../resources/js/analytics';

// UTM is auto-captured on init(), but you can also manually capture:
const utm = captureUTM();

if (hasUTMParams()) {
    console.log('Campaign source:', utm.utm_source);
    console.log('Campaign medium:', utm.utm_medium);
}

// UTM params are automatically attached to all tracked events
await trackEvent('signup', { method: 'email' });
// → params automatically include utm_source, utm_medium, etc.
```

## Architecture

```
src/
├── AnalyticsManager.php          # Core manager — dispatches to all trackers
├── AnalyticsServiceProvider.php   # Laravel service provider
├── Context/
│   └── EventContextBuilder.php   # Auto-collect request context (user, UTM, session, device)
├── DTO/
│   ├── AnalyticsEvent.php        # Immutable event DTO
│   └── ConsentState.php          # GDPR consent state
├── Events/
│   ├── Ecommerce/                # 8 e-commerce event classes + EcommerceEvents catalog
│   ├── SaaS/                     # 11 SaaS lifecycle event classes + SaaSEvents catalog
│   ├── Engagement/               # 10 engagement event classes + EngagementEvents catalog
│   ├── CustomEvent.php           # Generic custom event
│   └── EventCatalog.php          # Unified catalog aggregating all categories
├── Middleware/
│   ├── AnalyticsMiddlewareInterface.php  # Middleware contract
│   ├── AnalyticsMiddlewareStack.php     # Priority-ordered middleware stack
│   ├── ConsentGateMiddleware.php        # Consent-based event filtering
│   ├── ContextAttachmentMiddleware.php  # Auto-attach context to events
│   ├── SchemaValidationMiddleware.php   # Schema-aware event validation
│   ├── TimestampMiddleware.php          # Auto-add timestamps
│   └── LoggingMiddleware.php             # Debug event logging
├── Schema/
│   ├── EventSchema.php          # Event parameter schema definition
│   ├── EventParam.php           # Parameter type & constraints
│   └── EventSchemaRegistry.php  # Central schema registry (30+ events)
├── Pipeline/
│   ├── EventPipeline.php         # Middleware pipeline for event processing
│   ├── UtmEnricher.php           # UTM campaign parameter enrichment
│   ├── UserContextEnricher.php   # User context enrichment
│   ├── ConsentFilter.php         # Consent-based event filtering
│   └── TimestampEnricher.php     # Timestamp & session enrichment
├── Trackers/
│   ├── GA4Tracker.php            # GA4 Measurement Protocol
│   ├── GTMTracker.php            # GTM dataLayer
│   ├── MetaPixelTracker.php      # Meta Pixel CAPI
│   ├── PlausibleTracker.php      # Plausible Analytics
│   ├── PosthogTracker.php        # PostHog Analytics
│   ├── TrackerInterface.php      # Common tracker contract
│   └── TrackerHelpers.php        # Shared consent helpers
├── Services/
│   ├── GoogleAnalyticsService.php
│   ├── GoogleTagManagerService.php
│   ├── MetaPixelService.php
│   ├── EcommerceAnalyticsService.php  # E-commerce convenience methods
│   ├── SaaSAnalyticsService.php      # SaaS lifecycle convenience methods
│   ├── RevenueAnalyticsService.php    # Revenue tracking (MRR, ARR, churn)
│   └── EventValidationService.php    # Event validation & deduplication
├── Tracking/
│   ├── ServerSideTracker.php     # Auto-track Laravel events
│   ├── UserIdentityTracker.php   # User ↔ client linking
│   └── SessionTracker.php        # Session & funnel tracking
├── Queue/
│   └── QueuedAnalyticsDispatcher.php  # Async queue dispatch
├── Http/
│   ├── Controllers/AnalyticsEventController.php  # API endpoints + pipeline
│   └── Middleware/InjectAnalyticsScripts.php
├── Inertia/
│   └── HandleInertiaAnalytics.php  # Inertia prop injection
├── Console/Commands/
│   ├── AnalyticsOverviewCommand.php
│   └── AnalyticsTestCommand.php
├── Blade/Directives/
│   └── AnalyticsDirectives.php
├── Facades/
│   └── Analytics.php
resources/
└── js/
    └── analytics.js              # ES module client library (UTM, batch, form, error, perf)
config/
└── zeroboiler.php                # Configuration file
routes/
└── analytics.php                 # API route definitions
```

### Event Schema Registry

The `EventSchemaRegistry` provides a single source of truth for event definitions:

```php
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;

$registry = app(EventSchemaRegistry::class);

// Validate event params against schema
$result = $registry->validate('purchase', [
    'transaction_id' => 'TXN-123',
    'value' => 99.99,
]);

if ($result['valid']) {
    Analytics::trackEvent(new AnalyticsEvent('purchase', $result['sanitized']));
}

// Register custom schemas
$registry->register(new EventSchema(
    name: 'onboarding_complete',
    category: 'custom',
    description: 'User completed onboarding flow',
    requiredParams: [
        'steps_completed' => new EventParam(type: 'int', min: 1),
    ],
    optionalParams: [
        'duration_seconds' => new EventParam(type: 'int'),
    ],
));

// Get events by category
$ecommerceEvents = $registry->getEventsByCategory('ecommerce');
$allSchemas = $registry->getSchemasByCategory();
```

### Middleware Stack

The `AnalyticsMiddlewareStack` provides a composable, priority-ordered middleware system:

```php
use ZeroBoiler\Analytics\Middleware\AnalyticsMiddlewareStack;
use ZeroBoiler\Analytics\Middleware\{ConsentGateMiddleware, ContextAttachmentMiddleware, SchemaValidationMiddleware};

$stack = new AnalyticsMiddlewareStack;

// Add middleware (executed in priority order — lower number = first)
$stack->add(new ConsentGateMiddleware(Analytics::getConsent()->hasAnalyticsConsent()));
$stack->add(new ContextAttachmentMiddleware(['app_version' => '1.4.0']));
$stack->add(new SchemaValidationMiddleware(app(EventSchemaRegistry::class)));

// Process an event through the stack
$processed = $stack->process($event);
if ($processed !== null) {
    Analytics::trackEvent($processed);
}

// Or use the pre-configured default stack
$stack = AnalyticsMiddlewareStack::createDefault(
    analyticsGranted: true,
    context: ['source' => 'web'],
);
```

### Event Context Builder

The `EventContextBuilder` automatically collects server-side context:

```php
use ZeroBoiler\Analytics\Context\EventContextBuilder;

$context = (new EventContextBuilder($request))
    ->withUserIdentity()       // user_id, user_email_hash, user_plan
    ->withClientId()           // from cookie or header
    ->withSession()            // session_id
    ->withUTM()                // utm_source, utm_medium, etc.
    ->withPage()               // page_url, page_path
    ->withDevice()             // ip, user_agent, locale
    ->withCustom(['app_version' => '1.4.0'])
    ->build();

// Merge context into an event
$enrichedEvent = new AnalyticsEvent(
    name: 'page_view',
    params: array_merge($event->params, $context),
    clientId: $context['client_id'] ?? null,
    userId: $context['user_id'] ?? null,
);
```

### Debug Mode

```php
use ZeroBoiler\Analytics\Facades\Analytics;

// Check if debug mode is active
if (Analytics::isDebug()) {
    // Events are logged but not dispatched
}

// Toggle debug mode at runtime
Analytics::setDebug(true);

// Check if event logging is enabled
Analytics::shouldLogEvents(); // bool
```

When `ANALYTICS_DEBUG_ENABLED=true`, all events are intercepted and optionally logged
instead of being dispatched to providers. Useful for development and staging environments.

### Server-Side Event Validation

Events received via the API endpoints are automatically validated and sanitized
when `EventValidationService` is available (registered by the service provider):

```php
// Events are sanitized before dispatch:
// - Control characters stripped from keys/values
// - Event names validated against allowed patterns
// - Duplicate detection within configurable time window
// - Strict mode rejects non-whitelisted events
```

## Testing

```bash
# Run all tests
composer test

# Run with coverage
composer test:ci

# Full CI suite (Pint + PHPStan + Rector + Tests)
composer ci
```

## License

Proprietary — ZeroBoiler
