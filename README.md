# ZeroBoiler Analytics

Industry-standard SaaS analytics for Laravel — complete event tracking across GA4, GTM, Meta Pixel, Plausible, and PostHog.

## Features

- **Multi-Provider Tracking** — GA4 (Measurement Protocol + client), GTM (dataLayer + ecommerce), Meta Pixel (CAPI + client), Plausible, PostHog
- **Event Catalog** — Typed event classes for E-commerce, SaaS lifecycle, Engagement, and Custom events
- **Server-Side Auto-Tracking** — Automatically tracks Laravel auth events (login, register, logout) and custom app events (subscriptions, trials, features)
- **Inertia.js Integration** — Middleware injects analytics config into page props for Svelte/Vue/React
- **API Endpoints** — Server-side endpoints for frontend event tracking (track, batch, identify, consent)
- **JS Client Library** — ES module for Svelte/Inertia with auto page view, scroll depth, consent management
- **Async Queue Dispatch** — Queue analytics events to background workers (configurable)
- **User Identity Linking** — Cross-device identification via client ID ↔ user ID association
- **E-commerce Helpers** — High-level service for view item, cart, checkout, purchase, refund
- **Consent Mode v2** — Full GDPR compliance with granular consent signals
- **Blade Directives** — `@analyticsHead`, `@analyticsBody` for traditional Laravel apps
- **Admin Commands** — `zb:analytics:overview` and `zb:analytics:test`

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
    trackPageView,
    trackEvent,
    trackEcommerce,
    identify,
    updateConsent,
    initScrollDepth,
    initInertiaPageViewTracker,
} from '../resources/js/analytics';

// Initialize on app boot
$: if (page.props.zbAnalytics) {
    init(page.props);
}

// Auto-track page views
onMount(() => {
    const cleanupScroll = initScrollDepth();
    const cleanupInertia = initInertiaPageViewTracker();

    return () => {
        cleanupScroll();
        cleanupInertia();
    };
});
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
        // Track client-side events
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

All endpoints require authentication (`auth:sanctum`) and are rate-limited (60 req/min):

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/analytics/events` | Track a single event |
| POST | `/api/analytics/batch` | Track up to 25 events |
| POST | `/api/analytics/identify` | Link client ID ↔ user ID |
| POST | `/api/analytics/consent` | Update consent signals |

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

### Generic

| Class | Description |
|-------|-------------|
| `CustomEvent` | Arbitrary event name + params |

## Architecture

```
src/
├── AnalyticsManager.php          # Core manager — dispatches to all trackers
├── AnalyticsServiceProvider.php   # Laravel service provider
├── DTO/
│   ├── AnalyticsEvent.php        # Immutable event DTO
│   └── ConsentState.php          # GDPR consent state
├── Events/
│   ├── Ecommerce/                # 8 e-commerce event classes
│   ├── SaaS/                     # 10 SaaS lifecycle event classes
│   ├── Engagement/               # 9 engagement event classes
│   └── CustomEvent.php           # Generic custom event
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
│   └── EcommerceAnalyticsService.php  # E-commerce convenience methods
├── Tracking/
│   ├── ServerSideTracker.php     # Auto-track Laravel events
│   └── UserIdentityTracker.php   # User ↔ client linking
├── Queue/
│   └── QueuedAnalyticsDispatcher.php  # Async queue dispatch
├── Http/
│   ├── Controllers/AnalyticsEventController.php  # API endpoints
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
    └── analytics.js              # ES module client library
config/
└── zeroboiler.php                # Configuration file
routes/
└── analytics.php                 # API route definitions
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
