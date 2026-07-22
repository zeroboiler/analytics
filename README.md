# ZeroBoiler Analytics

Google Analytics 4, Google Tag Manager and Meta Pixel (Facebook Pixel) integration for Laravel applications.

## Installation

```bash
composer require zeroboiler/analytics
```

Publish the config:

```bash
php artisan vendor:publish --tag="zeroboiler-analytics-config"
```

## Configuration

Add the following environment variables to your `.env`:

```env
# Google Analytics 4
ANALYTICS_GA4_ENABLED=true
ANALYTICS_GA4_MEASUREMENT_ID=G-XXXXXXXXXX
ANALYTICS_GA4_API_SECRET=your_api_secret

# Google Tag Manager
ANALYTICS_GTM_ENABLED=true
ANALYTICS_GTM_CONTAINER_ID=GTM-XXXXXXX

# Meta Pixel (Facebook Pixel)
ANALYTICS_META_PIXEL_ENABLED=true
ANALYTICS_META_PIXEL_ID=123456789012345
ANALYTICS_META_PIXEL_ACCESS_TOKEN=your_access_token
```

## Usage

### Auto-Inject Scripts via Middleware

Register the middleware in your `bootstrap/app.php` or `app/Http/Kernel.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \ZeroBoiler\Analytics\Http\Middleware\InjectAnalyticsScripts::class,
    ]);
})
```

This automatically injects:
- GA4 gtag.js → `<head>`
- GTM script → `<head>`
- GTM noscript → `<body>`
- Meta Pixel → `<head>`
- Meta Pixel noscript → `<body>`

### Manual Script Rendering (Blade Directives)

If you prefer manual placement:

```blade
@analyticsHead
</head>
<body>
@analyticsBody
```

### Track Events

```php
use ZeroBoiler\Analytics\Facades\Analytics;

// Track across all configured providers
Analytics::track('purchase', [
    'value' => 99.99,
    'currency' => 'USD',
    'transaction_id' => 'ORDER-123',
]);

// Using the AnalyticsEvent DTO
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

Analytics::trackEvent(new AnalyticsEvent(
    name: 'sign_up',
    params: ['method' => 'email'],
    userId: auth()->id(),
));
```

### GTM dataLayer

```php
Analytics::push([
    'event' => 'ecommerce_purchase',
    'transaction_id' => 'ORDER-123',
    'value' => 99.99,
]);
```

Or via Blade:

```blade
@dataLayerPush(['event' => 'page_view', 'page' => '/home'])
```

### Individual Trackers

```php
// GA4
Analytics::ga4()->track(new AnalyticsEvent(name: 'page_view'));

// GTM
Analytics::gtm()->push(['event' => 'conversion']);

// Meta Pixel
Analytics::meta()->track(new AnalyticsEvent(name: 'Lead'));
```

### Service Classes

```php
use ZeroBoiler\Analytics\Services\GoogleAnalyticsService;
use ZeroBoiler\Analytics\Services\GoogleTagManagerService;
use ZeroBoiler\Analytics\Services\MetaPixelService;

// Google Analytics 4
app(GoogleAnalyticsService::class)->trackPageView(url: '/home', title: 'Home');
app(GoogleAnalyticsService::class)->trackPurchase(transactionData: [...]);

// Google Tag Manager
app(GoogleTagManagerService::class)->pushConversion('PURCHASE_LABEL');
app(GoogleTagManagerService::class)->pushEcommerceEvent('purchase', [...]);

// Meta Pixel
app(MetaPixelService::class)->trackViewContent(['content_name' => 'Product A']);
app(MetaPixelService::class)->trackLead(['content_category' => 'newsletter']);
```

## Features

| Feature | GA4 | GTM | Meta Pixel |
|---------|-----|-----|------------|
| Client-side tracking | ✅ gtag.js | ✅ Container | ✅ fbevents.js |
| Server-side tracking | ✅ Measurement Protocol | ✅ dataLayer | ✅ Conversions API |
| Event tracking | ✅ | ✅ | ✅ |
| Ecommerce | ✅ | ✅ | ✅ |
| Noscript fallback | — | ✅ | ✅ |
| Middleware injection | ✅ | ✅ | ✅ |
| Blade directives | ✅ | ✅ | ✅ |

## Testing

```bash
composer test
```

## License

Proprietary © ZeroBoiler
