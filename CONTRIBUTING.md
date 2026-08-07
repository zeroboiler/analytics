# Contributing to ZeroBoiler Analytics

## Code Standards

- **PHP 8.5+** — `declare(strict_types=1)` on every file
- **Final classes** on all service/DTO/event classes
- **Final readonly** on all DTOs (AnalyticsEvent, ConsentState, UtmAttribution, EventContextEvent)
- **`:void` return types** on all constructors
- **Return type declarations** on all public methods
- **Facades are NOT final** — standard Laravel pattern
- **Traits are NOT final** — designed for inheritance
- **Zero TODO/FIXME markers** in production source

## Package Architecture

```
AnalyticsServiceProvider       ← Registers manager, trackers, pipeline, services, routes

Core:
  ├── AnalyticsManager         ← Central dispatcher (GA4, GTM, Meta, Plausible, PostHog, Webhook)
  ├── AnalyticsMetrics         ← Dispatch/failure counters
  ├── EventInterceptorRegistry ← Before/after event interceptor chains
  └── Facades\Analytics        ← Static proxy with full @method annotations

DTOs (final readonly):
  ├── AnalyticsEvent           ← Core event DTO (name, params, clientId, userId, timestamp)
  ├── ConsentState             ← GDPR Consent Mode v2 signals
  ├── UtmAttribution           ← UTM campaign tracking
  └── EventContextEvent        ← Fully-qualified event envelope

Trackers:
  ├── GA4Tracker               ← Google Analytics 4 (Measurement Protocol)
  ├── GTMTracker               ← Google Tag Manager (dataLayer push + scripts)
  ├── MetaPixelTracker         ← Meta Pixel (Conversions API + pixel)
  ├── PlausibleTracker         ← Plausible Analytics (custom events API)
  ├── PosthogTracker           ← PostHog (capture API)
  └── WebhookTracker           ← Generic webhook dispatch with HMAC signing

Events (3 categories, 60+ event classes):
  ├── Engagement/              ← page_view, click, form, scroll, search, video, etc.
  ├── Ecommerce/               ← purchase, cart, checkout, wishlist, refund, etc.
  └── SaaS/                    ← signup, login, trial, plan, revenue, cohort, etc.

Pipeline (decorator chain):
  ├── EventPipeline            ← Orchestrates filter → enricher chain
  ├── Filters:                 ← Consent, Sampling, Debounce, Dedup, TrackingPreference
  └── Enrichers:               ← UTM, Timestamp, UserContext, Geo, Metadata, Schema

Services (50+):
  ├── GoogleAnalyticsService   ← GA4 convenience wrapper
  ├── GoogleTagManagerService  ← GTM convenience wrapper
  ├── MetaPixelService         ← Meta Pixel convenience wrapper
  ├── EcommerceAnalyticsService ← E-commerce tracking shortcuts
  ├── SaaSAnalyticsService     ← SaaS lifecycle event shortcuts
  ├── RevenueAnalyticsService  ← Revenue tracking & MRR
  ├── FunnelAnalyticsService   ← Funnel step tracking
  └── ... (see Services/ directory)

Middleware:
  ├── InjectAnalyticsScripts   ← Auto-inject tracker scripts in responses
  ├── ConsentGateMiddleware    ← Block events without consent
  ├── PiiSanitizationMiddleware ← Strip/mask PII before dispatch
  ├── TimestampMiddleware      ← Add server timestamp to events
  ├── SchemaValidationMiddleware ← Validate against EventSchemaRegistry
  └── AnalyticsMiddlewareStack ← Compose middleware chain

Pipeline Filters & Enrichers:
  ├── ConsentFilter            ← Skip denied-consent events
  ├── SamplingFilter           ← Probabilistic/deterministic sampling
  ├── EventDeduplicationFilter  ← Cache-based fingerprint dedup
  ├── EventDebounceFilter      ← Throttle rapid duplicate events
  ├── UtmEnricher              ← Extract UTM from request
  ├── GeolocationEnricher       ← Geo from headers/IP
  └── UserContextEnricher       ← User/tenant context

Tracking:
  ├── AnonymousIdTracker       ← Persistent client ID generation
  ├── ServerSideTracker        ← Server-to-server GA4/GTM
  ├── SessionTracker           ← Session lifecycle tracking
  └── UserIdentityTracker      ← User ID ↔ client ID linking

## Quality Checks

```bash
composer test              # Run Pest test suite
composer cs-check           # Pint style check
composer cs-fix             # Pint style fix
composer analyse            # PHPStan static analysis
composer rector             # Rector automated refactoring
composer rector-dry         # Rector dry-run
```

## Pull Requests

1. Fork the repository
2. Create a feature branch (`git checkout -b feat/my-feature`)
3. Ensure all quality checks pass
4. Commit with conventional prefix (`feat:`, `fix:`, `refactor:`, `docs:`, `test:`)
5. Push and open a pull request
