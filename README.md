# ZeroBoiler Analytics

Industry-standard SaaS analytics for Laravel. Full lifecycle event tracking with
GA4, GTM, Meta Pixel, Plausible, and PostHog — server-side and client-side.

## Installation

```bash
composer require zeroboiler/analytics
```

Publish the config:

```bash
php artisan vendor:publish --tag="zeroboiler-analytics-config"
```

## Quick Start

### 1. Configure Providers

```env
# Google Analytics 4 (server-side + client-side)
ANALYTICS_GA4_ENABLED=true
ANALYTICS_GA4_MEASUREMENT_ID=G-XXXXXXXXXX
ANALYTICS_GA4_API_SECRET=your_api_secret

# Google Tag Manager (dataLayer push + container)
ANALYTICS_GTM_ENABLED=true
ANALYTICS_GTM_CONTAINER_ID=GTM-XXXXXXX

# Meta Pixel (Conversions API + client-side)
ANALYTICS_META_PIXEL_ENABLED=true
ANALYTICS_META_PIXEL_ID=123456789012345
ANALYTICS_META_PIXEL_ACCESS_TOKEN=your_access_token

# Optional: Plausible Analytics (privacy-focused)
ANALYTICS_PLAUSIBLE_ENABLED=false
ANALYTICS_PLAUSIBLE_DOMAIN=yourdomain.com
ANALYTICS_PLAUSIBLE_API_KEY=your_api_key

# Optional: PostHog (product analytics)
ANALYTICS_POSTHOG_ENABLED=false
ANALYTICS_POSTHOG_API_KEY=phc_your_key
ANALYTICS_POSTHOG_PROJECT_ID=123
```

### 2. Add Middleware

```php
// bootstrap/app.php (Laravel 11+)
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \ZeroBoiler\Analytics\Http\Middleware\InjectAnalyticsScripts::class,
    ]);
})
```

### 3. Start Tracking

```php
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

// Simple tracking
Analytics::track('purchase', ['value' => 99.99, 'currency' => 'USD']);

// Using typed events
Analytics::trackEvent(new \ZeroBoiler\Analytics\Events\SaaS\SignUpEvent(method: 'github'));
```

## Features Overview

### 🏪 Event Catalog — 30+ Typed Event Classes

#### E-commerce Events (GA4 + Meta standard)
```php
use ZeroBoiler\Analytics\Events\Ecommerce\PurchaseEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\AddToCartEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\ViewItemEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\BeginCheckoutEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\RefundEvent;

Analytics::trackEvent(new PurchaseEvent(
    transactionId: 'TX-001',
    value: 99.99,
    currency: 'USD',
    items: [['item_id' => 'SKU-1', 'item_name' => 'Widget', 'price' => 99.99, 'quantity' => 1]],
));
```

#### SaaS Lifecycle Events
```php
use ZeroBoiler\Analytics\Events\SaaS\SignUpEvent;
use ZeroBoiler\Analytics\Events\SaaS\TrialStartEvent;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionEvent;
use ZeroBoiler\Analytics\Events\SaaS\PlanUpgradeEvent;
use ZeroBoiler\Analytics\Events\SaaS\CancellationEvent;
use ZeroBoiler\Analytics\Events\SaaS\FeatureUsedEvent;

Analytics::trackEvent(new TrialStartEvent(planName: 'Pro', trialDays: 14));
Analytics::trackEvent(new PlanUpgradeEvent(fromPlan: 'Free', toPlan: 'Pro'));
Analytics::trackEvent(new FeatureUsedEvent(featureName: 'export_csv', category: 'data'));
```

#### Engagement Events
```php
use ZeroBoiler\Analytics\Events\Engagement\PageViewEvent;
use ZeroBoiler\Analytics\Events\Engagement\ScrollDepthEvent;
use ZeroBoiler\Analytics\Events\Engagement\ClickEvent;
use ZeroBoiler\Analytics\Events\Engagement\FormSubmitEvent;
use ZeroBoiler\Analytics\Events\Engagement\SearchEvent;
use ZeroBoiler\Analytics\Events\Engagement\ErrorEvent;

Analytics::trackEvent(new ScrollDepthEvent(percent: 75, pagePath: '/blog/article'));
Analytics::trackEvent(new ErrorEvent(errorType: '500', message: 'Server Error', fatal: true));
Analytics::trackEvent(new SearchEvent(searchTerm: 'laravel analytics', resultsCount: 42));
```

#### Custom Events
```php
use ZeroBoiler\Analytics\Events\CustomEvent;

Analytics::trackEvent(new CustomEvent('video_completed', [
    'video_id' => 'vid-123',
    'duration' => 120,
]));
```

### 🔄 Server-Side Lifecycle Tracker

Automatically tracks Laravel framework events as analytics events:

```php
// Config: zeroboiler.analytics.auto_track
'auto_track' => [
    'enabled' => true,
    'events' => [
        'auth.login' => true,          // LoginEvent
        'auth.register' => true,       // SignUpEvent
        'auth.logout' => false,        // disabled (noisy)
        'subscription.created' => true, // SubscriptionEvent
        'trial.started' => true,        // TrialStartEvent
    ],
    'models' => [
        // App\Models\Habit::class => ['created', 'deleted'],
    ],
],
```

### 🎯 Inertia / SSR Integration

Inject analytics config into Inertia page props:

```php
// Register the middleware
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \ZeroBoiler\Analytics\Http\Middleware\InjectAnalyticsScripts::class,
        \ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics::class,
    ]);
})
```

In your Svelte app, access `page.props.zbAnalytics`:
```js
// { enabled, consent, trackingId, ga4MeasurementId, gtmContainerId, metaPixelId, userId }
```

### 📡 API Endpoints

Server-side endpoints for frontend event tracking (auth:sanctum protected):

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/analytics/events` | Track single event |
| POST | `/api/analytics/batch` | Batch up to 25 events |
| POST | `/api/analytics/identify` | Link client_id ↔ user |
| POST | `/api/analytics/consent` | Update GDPR consent |

### 📦 Svelte/JS Client Library

```js
import { init, trackEvent, trackPageView, initScrollDepth } from '@/analytics';

// Initialize with Inertia page props
init(page.props);

// Track events
await trackEvent('button_click', { element: 'buy_now' });
await trackPageView();

// Ecommerce
await trackEcommerce('purchase', {
    transaction_id: 'TX-001',
    value: 99.99,
    items: [{ item_id: 'SKU-1', price: 99.99, quantity: 1 }],
});

// Identity
await identify('user-123');

// Consent
updateConsent({ analytics_storage: 'granted', ad_storage: 'denied' });

// Scroll depth (auto-fires at 25%, 50%, 75%, 90%)
initScrollDepth();
```

### ⚡ Async Queue Dispatch

```php
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;

// Events are dispatched asynchronously via the 'analytics' queue
app(QueuedAnalyticsDispatcher::class)->dispatch($event);

// Batch dispatch
app(QueuedAnalyticsDispatcher::class)->dispatchBatch([$event1, $event2, $event3]);
```

```env
ANALYTICS_QUEUE_ENABLED=true
ANALYTICS_QUEUE=analytics
ANALYTICS_QUEUE_CONNECTION=redis
```

### 🔗 User Identity Tracking

```php
use ZeroBoiler\Analytics\Tracking\UserIdentityTracker;

// Automatically called by ServerSideTracker on login/register
// Or manually:
app(UserIdentityTracker::class)->onLogin($user, $request);
app(UserIdentityTracker::class)->onRegister($user, $request);
```

### 🛒 E-commerce Service

```php
use ZeroBoiler\Analytics\Services\EcommerceAnalyticsService;

$ecom = app(EcommerceAnalyticsService::class);

$ecom->viewItem(['item_id' => 'SKU-1', 'item_name' => 'Widget', 'price' => 49.99]);
$ecom->addToCart(['item_id' => 'SKU-1', 'quantity' => 2, 'price' => 49.99]);
$ecom->beginCheckout($items, 99.99, ['coupon' => 'SAVE10']);
$ecom->purchase('TX-001', 99.99, $items, ['coupon' => 'SAVE10']);
$ecom->refund('TX-001', 99.99);
```

### 🖥️ Admin Commands

```bash
# Test all configured providers
php artisan zb:analytics:test

# Test with GA4 debug endpoint
php artisan zb:analytics:test --validate

# View full configuration overview
php artisan zb:analytics:overview
```

### 🔒 Consent Mode v2 (GDPR)

```php
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\DTO\ConsentState;

// Grant all consent
Analytics::grantConsent();

// Deny all (GDPR-safe default)
Analytics::denyConsent();

// Granular control
Analytics::setConsent(ConsentState::granted()->with([
    'analytics_storage' => 'granted',
    'ad_storage' => 'denied',
]));

// Get current state
$consent = Analytics::getConsent();
$consent->hasAnalyticsConsent(); // bool
$consent->hasAdConsent();         // bool
```

### 🔧 Additional Providers

#### Plausible Analytics
```php
Analytics::plausible()->track(new AnalyticsEvent(name: 'pageview', params: [
    'url' => 'https://example.com/pricing',
]));
```

#### PostHog
```php
Analytics::posthog()->track(new AnalyticsEvent(name: 'user_signed_up', params: [
    'plan' => 'Pro',
]));
```

## Blade Directives

```blade
{{-- Auto-inject all scripts --}}
@analyticsHead
</head>
<body>
@analyticsBody

{{-- GTM dataLayer push --}}
@dataLayerPush(['event' => 'page_view', 'page' => '/home'])
```

## Script Injection Middleware

Automatically injects all enabled provider scripts:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \ZeroBoiler\Analytics\Http\Middleware\InjectAnalyticsScripts::class,
    ]);
})
```

## Complete Feature Matrix

| Feature | GA4 | GTM | Meta | Plausible | PostHog |
|---------|-----|-----|------|-----------|---------|
| Client-side tracking | ✅ gtag.js | ✅ Container | ✅ fbevents.js | ✅ script.js | ✅ posthog.js |
| Server-side tracking | ✅ MP | ✅ dataLayer | ✅ CAPI | ✅ API | ✅ capture |
| Event tracking | ✅ | ✅ | ✅ | ✅ | ✅ |
| Ecommerce events | ✅ | ✅ | ✅ | — | — |
| Consent Mode v2 | ✅ | ✅ | ✅ | ✅ | ✅ |
| Noscript fallback | — | ✅ | ✅ | — | — |
| Middleware injection | ✅ | ✅ | ✅ | ✅ | ✅ |
| Blade directives | ✅ | ✅ | ✅ | — | — |
| Inertia props | ✅ | ✅ | ✅ | ✅ | ✅ |
| API endpoints | ✅ | ✅ | ✅ | ✅ | ✅ |
| Queue dispatch | ✅ | ✅ | ✅ | ✅ | ✅ |

## Configuration Reference

```env
# Core Providers
ANALYTICS_GA4_ENABLED=false
ANALYTICS_GA4_MEASUREMENT_ID=
ANALYTICS_GA4_API_SECRET=
ANALYTICS_GTM_ENABLED=false
ANALYTICS_GTM_CONTAINER_ID=
ANALYTICS_META_PIXEL_ENABLED=false
ANALYTICS_META_PIXEL_ID=
ANALYTICS_META_PIXEL_ACCESS_TOKEN=

# Consent
ANALYTICS_CONSENT_DEFAULT=granted

# Auto-Track
ANALYTICS_AUTO_TRACK_ENABLED=true

# Queue
ANALYTICS_QUEUE_ENABLED=true
ANALYTICS_QUEUE=analytics
ANALYTICS_QUEUE_CONNECTION=

# Identity
ANALYTICS_IDENTITY_COOKIE=zb_analytics_id
ANALYTICS_IDENTITY_COOKIE_TTL=525600
ANALYTICS_IDENTITY_COOKIE_SECURE=true
ANALYTICS_IDENTITY_COOKIE_SAMESITE=Lax

# E-commerce
ANALYTICS_ECOMMERCE_CURRENCY=USD
ANALYTICS_ECOMMERCE_BRAND=

# API
ANALYTICS_API_ENABLED=true
ANALYTICS_API_THROTTLE=60

# Plausible (optional)
ANALYTICS_PLAUSIBLE_ENABLED=false
ANALYTICS_PLAUSIBLE_DOMAIN=
ANALYTICS_PLAUSIBLE_API_KEY=

# PostHog (optional)
ANALYTICS_POSTHOG_ENABLED=false
ANALYTICS_POSTHOG_API_KEY=
ANALYTICS_POSTHOG_HOST=https://eu.posthog.com
ANALYTICS_POSTHOG_PROJECT_ID=
```

## Testing

```bash
composer test
```

## License

Proprietary © ZeroBoiler
