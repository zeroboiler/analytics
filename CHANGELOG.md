# Changelog

All notable changes to the `zeroboiler/analytics` package will be documented in this file.

## [Unreleased]

### Changed
- Removed unused `dto` and `value-objects` path repositories from `composer.json`

## [2.51.0] - 2026-08-07

### Added
- **AnalyticsEventRouter** — Config-driven event routing service that filters which providers receive specific events. Supports exact match, prefix wildcard (`add_to_*`), suffix wildcard (`*_click`), and catch-all (`*`) patterns. Events matching a rule are dispatched only to the listed providers. Unmatched events fall through to all enabled providers.
- **Config section `routing`** — New `zeroboiler.analytics.routing` config with `enabled` toggle and `rules` map. Supported provider names: `ga4`, `gtm`, `meta`, `plausible`, `posthog`, `webhook`.
- **Facade proxy methods** — Added `selectItem()`, `selectPromotion()`, `viewPromotion()`, `subscriptionRenewal()` to `@method` annotations for full IDE auto-complete coverage
- **AnalyticsConfig accessors** — `routingEnabled()`, `routingRules()` for type-safe routing config access
- **V47EventRouterFacadeVersionTest** — 30+ test cases covering AnalyticsEventRouter (pattern matching, wildcard rules, runtime rule management, summary, fall-through dispatch), Facade proxy completeness, version consistency across all 9 files, config section coverage, source file counts, and class architecture validation

### Changed
- Version bump to 2.47.0
- AnalyticsManager::version() returns '2.47.0'
- Composer version updated to 2.47.0
- JS client version string updated to 2.47.0 (5 occurrences)
- TypeScript definitions version updated to 2.47.0
- EventSourceTagger::_version updated to 2.47.0
- Controller version strings updated to 2.47.0 (38 occurrences)
- EventForwardingService version strings updated to 2.47.0 (3 occurrences)
- All 51 version references now consistently 2.47.0 (previously 2.45.0 in manager/source tagger/forwarding/controller and 2.46.0 in composer)

## [2.46.0] - 2026-08-07

### Added
- **LifecycleEventMapper expansion**: 20 new event mappings across 4 new categories (account lifecycle, B2B/team, billing, integrations), bringing total from 15 to 35 default mappings
- Account lifecycle events: `account.activated`, `account.deactivated`, `account.email_verified`, `account.password_changed`, `account.password_reset`, `account.profile_updated`
- B2B / Team lifecycle events: `team.created`, `team.member_joined`, `team.member_removed`, `team.role_changed`, `team.invite_sent`
- Billing lifecycle events: `billing.payment_succeeded`, `billing.payment_failed`, `billing.payment_method_added`, `billing.invoice_generated`, `billing.credit_applied`
- Integration lifecycle events: `integration.connected`, `integration.failed`
- Subscription renewal mapping: `subscription.renewal`
- Feature limit reached mapping: `feature.limit_reached`
- Param extractors for all new events with correct constructor argument mapping (team events, payment events, integration events, role changes, invites)
- `V46ComprehensiveLifecycleTest` — 15 tests validating all 35 mappings, 12 category coverage, config toggles per category, registration volume, and target class validity
- Lifecycle config toggles for all 20 new event keys in `config/zeroboiler.php`

### Changed
- Lifecycle config `events` array reorganized with category comments for discoverability
- Param extractors now use match expressions for per-class constructor mapping instead of reflection-only fallbacks

## [2.45.0] - 2026-08-07

### Fixed
- **Version consistency**: Updated all 41 controller endpoints, EventSourceTagger, EventForwardingService (Segment payload), JS client, TypeScript definitions, AnalyticsManager, and composer.json from 2.43.0/2.44.0 → 2.45.0
- Eliminated stale 2.43.0 version references that persisted through v2.44.0

### Added
- `V45ConfigCoverageVersionIntegrityTest` — comprehensive test suite validating all 60+ AnalyticsConfig accessors, summary() completeness (55+ sections), version consistency across 6 file types, CHANGELOG integrity, PHP 8.5 return type compliance, and class immutability
- Full accessor coverage tests for GA4, GTM, Meta Pixel, Plausible, PostHog, Webhook, Pipeline, Lifecycle, GDPR, Identity, API, Auto-Track, E-commerce, Revenue, Track Links, Queue config sections

### Changed
- Version consistency bump to 2.45.0 across all PHP source, JS client, TS definitions, and test files
- Updated V43 and V44 test version assertions to match 2.45.0

## [2.44.0] - 2026-08-07

### Added
- `AnalyticsConfig` expansion with 8 new attribute accessors: `attributionModel()`, `attributionSessionWindowDays()`, `attributionCacheTtl()`, `attributionFirstTouchTtl()`, `attributionTouchHistoryTtl()`, `attributionMaxTouchHistory()`, `referralEnabled()`, `referralParamName()`, `referralTtl()`, `referralTrackConversions()`
- Attribution config section with first-touch/multi-touch model, session window, and touch history TTL
- Referral tracking config section with configurable param name and conversion tracking
- `V44ConfigIntegrityTest` — config integrity, AnalyticsConfig expansion, attribution fix validation

### Fixed
- Attribution model and referral config accessors now have proper type-safe return declarations

## [2.43.0] - 2026-08-07

### Added
- `EventForwardingService` — forward analytics events to external platforms (Segment, Mixpanel, Amplitude, custom webhooks) with configurable timeout, retries, and rate limiting
- `PerformanceBudgetService` — enforce limits on event payload size, rate per session, and daily quotas with configurable max payload bytes, max params count, and per-user/per-day caps
- `AnalyticsConfig` expanded with forwarding and performance budget accessors
- `V43ForwardingBudgetAttributionTest` — comprehensive tests for forwarding, budget, and attribution features

## [2.42.0] - 2026-08-07

### Added
- Event forwarding config section (`forwarding`) with per-forwarder enable/disable, timeout, retries, and rate limiting
- Performance budget config section (`performance_budget`) with payload size, param count, session, and daily limits
- UTM attribution service and config expansion
- 68 events comprehensive README update with GDPR consent purposes documentation
- `V42SaaSStarterFinalTest` — comprehensive production readiness test suite

### Changed
- Version consistency bump to 2.42.0 across all controller endpoints and service files

## [2.41.0] - 2026-08-07

### Added
- **Account Lifecycle Events**: `AccountActivatedEvent`, `AccountDeactivatedEvent`, `PasswordChangedEvent`, `PasswordResetEvent`, `ProfileUpdatedEvent`, `EmailVerifiedEvent` — 6 new typed event classes for SaaS account management tracking
- **B2B / Team Events**: `TeamCreatedEvent`, `TeamMemberJoinedEvent`, `TeamMemberRemovedEvent`, `RoleChangedEvent` — 4 new typed event classes for multi-tenant SaaS collaboration tracking
- **Billing Events**: `PaymentFailedEvent`, `PaymentSucceededEvent`, `PaymentMethodAddedEvent`, `InvoiceGeneratedEvent`, `CreditAppliedEvent` — 5 new typed event classes for payment and billing lifecycle tracking
- `ConsentLogService` — granular GDPR consent tracking with audit trail, per-purpose consent management, DSAR export, and cache-backed history
- `consent.purposes` config section (necessary, analytics, marketing, functional) with required/default flags for consent banners
- `consent.log_enabled` and `consent.log_ttl` config options for consent audit logging
- `AnalyticsConfig::consentPurposes()`, `consentLogEnabled()`, `consentLogTtl()` accessors
- Inertia middleware `consentPurposes` prop exposure for frontend consent banner integration
- Full PostHog event name mappings for all 15 new events
- SaaS event catalog expanded from 20 → 35 events

### Changed
- Version consistency bump to 2.41.0 across AnalyticsManager, 26 controller endpoints, JS client, TS definitions, EventSourceTagger, and 18 test files

## [2.40.0] - 2026-08-07

### Added
- `subscription.renewal` auto-track mapping in `ServerSideTracker`
- `ecommerce.shipping_default` config option for default shipping value in e-commerce events
- `revenue` config section (`currency`, `billing_cycle_default`) for SaaS revenue tracking defaults
- `AnalyticsConfig::ecommerceShippingDefault()`, `revenueCurrency()`, `revenueBillingCycleDefault()` accessors

### Changed
- Version consistency bump to 2.40.0 across all 26 controller endpoints, AnalyticsManager, JS client, TS definitions, EventSourceTagger, and 17 test files

## [2.39.0] - 2026-08-07

### Fixed
- Added missing `:void` return type to `SubscriptionRenewalEvent` constructor (PHP 8.5 compliance)

## [2.38.0] - 2026-08-07

### Added
- JS client SaaS starter completeness (8 new convenience trackers, full TS parity)
- Version consistency across all 26 controller endpoints + tests

## [2.37.0] - 2026-08-07

### Added
- `subscription_renewal` event with full PostHog mapping
- `AnalyticsConfig` expanded accessors (8 new sections)

## [2.36.0] - 2026-08-07

### Fixed
- Removed deprecated `setAccessible(true)` calls in tests (PHP 8.5 compliance)

## [2.35.0] - 2026-08-07

### Added
- TypeScript type definitions, `sendBeacon` unload flush

## [1.0.0] - 2026-08-01

### Added
- Multi-provider analytics tracking (GA4, GTM, Meta Pixel, Plausible, PostHog)
- Event pipeline with middleware (PII sanitization, consent gating, schema validation, deduplication)
- SaaS event tracking (subscription, revenue, cohort, feature usage, invite, trial)
- Ecommerce event tracking (purchase, add to cart, checkout, refund, wishlist, item views)
- Engagement event tracking (page view, click, scroll, form, session, web vitals, errors)
- Real-time aggregation, anomaly detection, funnel analytics
- GDPR erasure, data retention policies, tenant isolation
- Queue-based event replay and dead letter queue
- Inertia.js integration, UTM attribution, revenue attribution
- CLI commands for analytics testing, export, and revenue reporting
- Config-driven architecture with `AnalyticsConfig`
- PHP 8.5 attributes, readonly DTOs, final service classes
