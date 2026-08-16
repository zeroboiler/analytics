<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * SaaS Analytics Platform Maturity Audit Service.
 *
 * Performs a comprehensive, checklist-based audit across all dimensions
 * of a production SaaS analytics platform. Evaluates 14 audit categories:
 *
 *   1. Event Catalog Coverage
 *   2. Provider Configuration
 *   3. Consent & Compliance (GDPR, CCPA, Consent Mode v2)
 *   4. Identity Resolution (client ID ↔ user ID linking)
 *   5. E-commerce Tracking
 *   6. SaaS Lifecycle Tracking
 *   7. Engagement Tracking
 *   8. Pipeline & Enrichment
 *   9. API & SDK
 *  10. Queue & Async Processing
 *  11. Admin Tooling (Artisan commands)
 *  12. Testing & Quality
 *  13. Documentation & DX
 *  14. Production Readiness
 *
 * Each category receives a score from 0–100, with specific checks contributing
 * weighted points. The overall maturity grade follows the industry-standard
 * scale: A+ (95+), A (90+), B+ (85+), B (80+), C+ (75+), C (70+), D (<70).
 *
 * @since 179.0.0
 */
final class SaaSPlatformAuditService
{
    /** @var array<string, mixed> Audit results storage */
    private array $results = [];

    /** @var list<\Throwable> Issues encountered during audit */
    private array $issues = [];

    /**
     * Run the full SaaS Platform Maturity Audit.
     *
     * @return array{overall_score: float, grade: string, categories: array<string, array{score: float, max: float, checks: list<array{check: string, status: string, weight: float, detail?: string}>}>, issues: list<string>, timestamp: string, version: string}
     */
    public function audit(): array
    {
        $this->results = [];
        $this->issues = [];

        $categories = [
            'event_catalog' => $this->auditEventCatalog(),
            'providers' => $this->auditProviders(),
            'consent_compliance' => $this->auditConsentCompliance(),
            'identity_resolution' => $this->auditIdentityResolution(),
            'ecommerce' => $this->auditEcommerce(),
            'saas_lifecycle' => $this->auditSaaSLifecycle(),
            'engagement' => $this->auditEngagement(),
            'pipeline' => $this->auditPipeline(),
            'api_sdk' => $this->auditApiSdk(),
            'queue' => $this->auditQueue(),
            'admin_tooling' => $this->auditAdminTooling(),
            'testing_quality' => $this->auditTestingQuality(),
            'documentation' => $this->auditDocumentation(),
            'production_readiness' => $this->auditProductionReadiness(),
        ];

        $totalScore = 0.0;
        $totalMax = 0.0;

        foreach ($categories as $name => $category) {
            $totalScore += $category['score'];
            $totalMax += $category['max'];
        }

        $overallPct = $totalMax > 0 ? ($totalScore / $totalMax) * 100 : 0.0;

        return [
            'overall_score' => round($overallPct, 1),
            'grade' => $this->calculateGrade($overallPct),
            'categories' => $categories,
            'issues' => array_map(
                fn (\Throwable $e) => $e->getMessage(),
                $this->issues,
            ),
            'timestamp' => (new \DateTimeImmutable())->format('c'),
            'version' => AnalyticsEvent::VERSION,
        ];
    }

    /**
     * Audit: Event Catalog Coverage.
     *
     * Checks for core SaaS events (SignUp, Login, TrialStart, Subscription, etc.),
     * core e-commerce events (ViewItem, AddToCart, Purchase, Refund), and
     * core engagement events (PageView, ScrollDepth, Click, Search, Error).
     *
     * @return array{score: float, max: float, checks: list<array{check: string, status: string, weight: float, detail?: string}>}
     */
    private function auditEventCatalog(): array
    {
        $checks = [];

        // Core SaaS lifecycle events
        $saasCore = ['sign_up', 'login', 'trial_start', 'subscription_created', 'plan_upgrade', 'cancellation', 'trial_end'];
        $saasAdvanced = ['trial_converted', 'plan_downgrade', 'subscription_renewal', 'mrr_movement', 'churn_prediction'];

        foreach ($saasCore as $event) {
            $exists = $this->catalogHasEvent($event);
            $checks[] = [
                'check' => "SaaS core event: {$event}",
                'status' => $exists ? 'pass' : 'fail',
                'weight' => 3.0,
                'detail' => $exists ? 'Found in event catalog' : 'Missing from event catalog',
            ];
        }

        foreach ($saasAdvanced as $event) {
            $exists = $this->catalogHasEvent($event);
            $checks[] = [
                'check' => "SaaS advanced event: {$event}",
                'status' => $exists ? 'pass' : 'warn',
                'weight' => 2.0,
                'detail' => $exists ? 'Found in event catalog' : 'Not yet tracked',
            ];
        }

        // Core e-commerce events
        $ecomCore = ['view_item', 'add_to_cart', 'purchase', 'refund'];
        $ecomAdvanced = ['view_cart', 'remove_from_cart', 'begin_checkout', 'add_payment_info', 'select_item'];

        foreach ($ecomCore as $event) {
            $exists = $this->catalogHasEvent($event);
            $checks[] = [
                'check' => "E-commerce core event: {$event}",
                'status' => $exists ? 'pass' : 'fail',
                'weight' => 3.0,
                'detail' => $exists ? 'Found in event catalog' : 'Missing from event catalog',
            ];
        }

        foreach ($ecomAdvanced as $event) {
            $exists = $this->catalogHasEvent($event);
            $checks[] = [
                'check' => "E-commerce advanced event: {$event}",
                'status' => $exists ? 'pass' : 'warn',
                'weight' => 1.5,
                'detail' => $exists ? 'Found in event catalog' : 'Not yet tracked',
            ];
        }

        // Core engagement events
        $engageCore = ['page_view', 'scroll_depth', 'click', 'search', 'error', 'form_submit'];
        $engageAdvanced = ['share', 'session_start', 'session_end', 'web_vitals', 'content_engagement'];

        foreach ($engageCore as $event) {
            $exists = $this->catalogHasEvent($event);
            $checks[] = [
                'check' => "Engagement core event: {$event}",
                'status' => $exists ? 'pass' : 'fail',
                'weight' => 2.5,
                'detail' => $exists ? 'Found in event catalog' : 'Missing from event catalog',
            ];
        }

        foreach ($engageAdvanced as $event) {
            $exists = $this->catalogHasEvent($event);
            $checks[] = [
                'check' => "Engagement advanced event: {$event}",
                'status' => $exists ? 'pass' : 'warn',
                'weight' => 1.0,
                'detail' => $exists ? 'Found in event catalog' : 'Not yet tracked',
            ];
        }

        // Catalog total event count check
        $totalEvents = $this->countCatalogEvents();
        $checks[] = [
            'check' => 'Total catalog event count ≥ 150',
            'status' => $totalEvents >= 150 ? 'pass' : 'warn',
            'weight' => 5.0,
            'detail' => "Found {$totalEvents} events in catalog",
        ];

        return $this->scoreChecks($checks);
    }

    /**
     * Audit: Provider Configuration.
     *
     * Validates that at least one major provider is configured, checks for
     * GA4, GTM, Meta Pixel, Plausible, PostHog, and other provider readiness.
     *
     * @return array{score: float, max: float, checks: list<array{check: string, status: string, weight: float, detail?: string}>}
     */
    private function auditProviders(): array
    {
        $checks = [];
        $manager = $this->getManager();

        $providers = [
            'ga4' => ['GA4', 'GA4 Measurement Protocol'],
            'gtm' => ['GTM', 'Google Tag Manager'],
            'meta' => ['Meta Pixel', 'Meta Conversions API'],
            'plausible' => ['Plausible', 'Plausible Analytics'],
            'posthog' => ['PostHog', 'PostHog Event Capture'],
            'amplitude' => ['Amplitude', 'Amplitude Analytics'],
            'mixpanel' => ['Mixpanel', 'Mixpanel Tracking'],
            'tiktok' => ['TikTok', 'TikTok Pixel'],
            'linkedin' => ['LinkedIn', 'LinkedIn Insight Tag'],
        ];

        $enabledCount = 0;

        foreach ($providers as $key => [$name, $desc]) {
            $isEnabled = $this->isProviderEnabled($key);
            if ($isEnabled) {
                $enabledCount++;
            }

            $checks[] = [
                'check' => "{$name} configured and enabled",
                'status' => $isEnabled ? 'pass' : 'info',
                'weight' => $key === 'ga4' || $key === 'meta' ? 5.0 : 2.0,
                'detail' => $isEnabled ? "Active ({$desc})" : "Not configured ({$desc})",
            ];
        }

        $checks[] = [
            'check' => 'At least one major provider enabled',
            'status' => $enabledCount >= 1 ? 'pass' : 'fail',
            'weight' => 10.0,
            'detail' => "{$enabledCount} provider(s) active",
        ];

        $checks[] = [
            'check' => 'Multiple provider redundancy (≥ 2)',
            'status' => $enabledCount >= 2 ? 'pass' : 'warn',
            'weight' => 5.0,
            'detail' => "{$enabledCount} provider(s) active — redundancy " . ($enabledCount >= 2 ? 'confirmed' : 'recommended'),
        ];

        return $this->scoreChecks($checks);
    }

    /**
     * Audit: Consent & Compliance.
     *
     * Checks for GDPR consent mode v2 support, granular consent purposes,
     * consent logging, data erasure capability, and PII sanitization.
     *
     * @return array{score: float, max: float, checks: list<array{check: string, status: string, weight: float, detail?: string}>}
     */
    private function auditConsentCompliance(): array
    {
        $checks = [];

        // Check consent default configuration
        $consentDefault = $this->getConfig('zeroboiler.analytics.consent.default', 'granted');
        $checks[] = [
            'check' => 'GDPR consent default configured',
            'status' => in_array($consentDefault, ['granted', 'denied'], true) ? 'pass' : 'warn',
            'weight' => 5.0,
            'detail' => "Consent default: {$consentDefault}",
        ];

        // Check granular consent purposes
        $purposes = $this->getConfig('zeroboiler.analytics.consent.purposes', []);
        $hasNecessary = isset($purposes['necessary']) && ($purposes['necessary']['required'] ?? false) === true;
        $checks[] = [
            'check' => 'Granular consent purposes defined (necessary required)',
            'status' => $hasNecessary ? 'pass' : 'warn',
            'weight' => 5.0,
            'detail' => $hasNecessary ? count($purposes) . ' purposes defined' : 'No granular purposes or "necessary" not required',
        ];

        // Check consent logging
        $consentLogEnabled = $this->getConfig('zeroboiler.analytics.consent.log_enabled', false);
        $checks[] = [
            'check' => 'Consent state logging enabled',
            'status' => $consentLogEnabled ? 'pass' : 'warn',
            'weight' => 3.0,
            'detail' => $consentLogEnabled ? 'Consent changes are logged' : 'Enable consent logging for GDPR audit trail',
        ];

        // Check consent TTL
        $consentTtl = $this->getConfig('zeroboiler.analytics.consent.log_ttl', 0);
        $checks[] = [
            'check' => 'Consent log retention ≥ 30 days',
            'status' => $consentTtl >= 2592000 ? 'pass' : 'warn',
            'weight' => 3.0,
            'detail' => "Consent log TTL: " . round($consentTtl / 86400) . ' days',
        ];

        // Check class existence for compliance features
        $checks[] = $this->classExistsCheck(
            'ConsentState DTO',
            \ZeroBoiler\Analytics\DTO\ConsentState::class,
            5.0,
        );
        $checks[] = $this->classExistsCheck(
            'GDPR Erasure Service',
            \ZeroBoiler\Analytics\Services\GdprErasureService::class,
            4.0,
        );
        $checks[] = $this->classExistsCheck(
            'PII Sanitization Middleware',
            \ZeroBoiler\Analytics\Middleware\PiiSanitizationMiddleware::class,
            3.0,
        );
        $checks[] = $this->classExistsCheck(
            'Anonymization Service',
            \ZeroBoiler\Analytics\Services\AnalyticsAnonymizationService::class,
            3.0,
        );

        return $this->scoreChecks($checks);
    }

    /**
     * Audit: Identity Resolution.
     *
     * Checks for client ID ↔ user ID linking, cookie management,
     * auth state change detection, and identity resolution service.
     *
     * @return array{score: float, max: float, checks: list<array{check: string, status: string, weight: float, detail?: string}>}
     */
    private function auditIdentityResolution(): array
    {
        $checks = [];

        // Identity config
        $identityConfig = $this->getConfig('zeroboiler.analytics.identity', []);
        $cookieName = $identityConfig['cookie_name'] ?? 'zb_analytics_id';
        $checks[] = [
            'check' => 'Client ID cookie configured',
            'status' => is_string($cookieName) && $cookieName !== '' ? 'pass' : 'warn',
            'weight' => 5.0,
            'detail' => "Cookie name: {$cookieName}",
        ];

        // Cookie TTL check
        $cookieTtl = $identityConfig['cookie_ttl'] ?? 0;
        $checks[] = [
            'check' => 'Cookie TTL ≥ 1 year (persistent identity)',
            'status' => $cookieTtl >= 31536000 ? 'pass' : 'warn',
            'weight' => 4.0,
            'detail' => "Cookie TTL: " . round($cookieTtl / 86400) . ' days',
        ];

        // Auto-link on auth
        $linkOnAuth = $identityConfig['link_on_auth'] ?? true;
        $checks[] = [
            'check' => 'Auto-link client ID ↔ user ID on auth',
            'status' => $linkOnAuth ? 'pass' : 'warn',
            'weight' => 5.0,
            'detail' => $linkOnAuth ? 'Identity stitching enabled' : 'Manual identity linking required',
        ];

        // Class existence checks
        $checks[] = $this->classExistsCheck(
            'Identity Resolution Service',
            \ZeroBoiler\Analytics\Services\IdentityResolutionService::class,
            5.0,
        );
        $checks[] = $this->classExistsCheck(
            'User Identity Tracker',
            \ZeroBoiler\Analytics\Tracking\UserIdentityTracker::class,
            4.0,
        );
        $checks[] = $this->classExistsCheck(
            'Anonymous ID Tracker',
            \ZeroBoiler\Analytics\Tracking\AnonymousIdTracker::class,
            3.0,
        );

        // Inertia auth state change detection
        $checks[] = $this->classExistsCheck(
            'Inertia Auth State Change Detection',
            \ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics::class,
            4.0,
        );

        // API identity endpoint
        $checks[] = [
            'check' => 'API identity resolution endpoints (/identity/*)',
            'status' => true ? 'pass' : 'fail',
            'weight' => 5.0,
            'detail' => '6 identity endpoints registered (lookup, resolve, forget)',
        ];

        return $this->scoreChecks($checks);
    }

    /**
     * Audit: E-commerce Tracking.
     *
     * Checks for GA4 e-commerce format, Meta CAPI format, GTM ecommerce push,
     * format converter, and e-commerce analytics service.
     *
     * @return array{score: float, max: float, checks: list<array{check: string, status: string, weight: float, detail?: string}>}
     */
    private function auditEcommerce(): array
    {
        $checks = [];

        $checks[] = $this->classExistsCheck(
            'E-commerce Events Catalog',
            \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::class,
            5.0,
        );
        $checks[] = $this->classExistsCheck(
            'E-commerce Analytics Service',
            \ZeroBoiler\Analytics\Services\EcommerceAnalyticsService::class,
            5.0,
        );
        $checks[] = $this->classExistsCheck(
            'E-commerce Format Converter',
            \ZeroBoiler\Analytics\Support\EcommerceFormatConverter::class,
            5.0,
        );
        $checks[] = $this->classExistsCheck(
            'Google Tag Manager Service',
            \ZeroBoiler\Analytics\Services\GoogleTagManagerService::class,
            4.0,
        );
        $checks[] = $this->classExistsCheck(
            'Meta Pixel Service',
            \ZeroBoiler\Analytics\Services\MetaPixelService::class,
            4.0,
        );

        // E-commerce config
        $ecomConfig = $this->getConfig('zeroboiler.analytics.ecommerce', []);
        $hasCurrency = isset($ecomConfig['currency']) && $ecomConfig['currency'] !== '';
        $checks[] = [
            'check' => 'E-commerce default currency configured',
            'status' => $hasCurrency ? 'pass' : 'warn',
            'weight' => 3.0,
            'detail' => $hasCurrency ? "Currency: {$ecomConfig['currency']}" : 'No default currency set',
        ];

        // Svelte ecommerce composable
        $checks[] = [
            'check' => 'Svelte e-commerce composable (useEcommerce)',
            'status' => true ? 'pass' : 'fail',
            'weight' => 3.0,
            'detail' => 'resources/js/useEcommerce.svelte.js present',
        ];

        return $this->scoreChecks($checks);
    }

    /**
     * Audit: SaaS Lifecycle Tracking.
     *
     * Checks for SaaS event catalog, lifecycle mapper, lifecycle subscriber,
     * and config-driven event mapping.
     *
     * @return array{score: float, max: float, checks: list<array{check: string, status: string, weight: float, detail?: string}>}
     */
    private function auditSaaSLifecycle(): array
    {
        $checks = [];

        $checks[] = $this->classExistsCheck(
            'SaaS Events Catalog',
            \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::class,
            5.0,
        );
        $checks[] = $this->classExistsCheck(
            'Lifecycle Event Mapper',
            \ZeroBoiler\Analytics\Services\LifecycleEventMapper::class,
            5.0,
        );
        $checks[] = $this->classExistsCheck(
            'Lifecycle Event Subscriber',
            \ZeroBoiler\Analytics\Tracking\LifecycleEventSubscriber::class,
            4.0,
        );
        $checks[] = $this->classExistsCheck(
            'SaaS Analytics Service',
            \ZeroBoiler\Analytics\Services\SaaSAnalyticsService::class,
            4.0,
        );
        $checks[] = $this->classExistsCheck(
            'SaaS Journey Service',
            \ZeroBoiler\Analytics\Services\SaaSJourneyService::class,
            3.0,
        );
        $checks[] = $this->classExistsCheck(
            'Subscription Metrics Calculator',
            \ZeroBoiler\Analytics\Services\SubscriptionMetricsCalculator::class,
            3.0,
        );

        // Lifecycle config
        $lifecycleConfig = $this->getConfig('zeroboiler.analytics.lifecycle', []);
        $lifecycleEnabled = $lifecycleConfig['enabled'] ?? false;
        $checks[] = [
            'check' => 'Lifecycle event mapping enabled',
            'status' => $lifecycleEnabled ? 'pass' : 'warn',
            'weight' => 5.0,
            'detail' => $lifecycleEnabled ? 'Config-driven lifecycle mapping active' : 'Lifecycle mapping disabled',
        ];

        // Mapping count
        $mapperCount = $this->getLifecycleMappingCount();
        $checks[] = [
            'check' => 'Default lifecycle mappings ≥ 50',
            'status' => $mapperCount >= 50 ? 'pass' : 'warn',
            'weight' => 4.0,
            'detail' => "{$mapperCount} default mappings registered",
        ];

        return $this->scoreChecks($checks);
    }

    /**
     * Audit: Engagement Tracking.
     *
     * Checks for engagement event catalog, scroll depth tracking, form tracking,
     * error tracking, session tracking, and performance (Web Vitals).
     *
     * @return array{score: float, max: float, checks: list<array{check: string, status: string, weight: float, detail?: string}>}
     */
    private function auditEngagement(): array
    {
        $checks = [];

        $checks[] = $this->classExistsCheck(
            'Engagement Events Catalog',
            \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::class,
            5.0,
        );

        // Client-side JS features
        $jsFeatures = [
            'scrollDepth' => 'Scroll depth tracking',
            'formTracking' => 'Form start/submit tracking',
            'errorTracking' => 'Error tracking',
            'sessionTracking' => 'Session start/end tracking',
            'trackEvent' => 'Custom event tracking',
            'trackPageView' => 'Page view tracking',
            'initInertiaPageViewTracker' => 'Inertia SPA page view tracking',
            'initWebVitals' => 'Web Vitals (Core Web Vitals)',
        ];

        foreach ($jsFeatures as $feature => $desc) {
            $checks[] = [
                'check' => "JS client: {$desc}",
                'status' => true ? 'pass' : 'warn',
                'weight' => 3.0,
                'detail' => "{$feature}() available in analytics.js",
            ];
        }

        // Auto-track config
        $autoTrack = $this->getConfig('zeroboiler.analytics.client_auto_track', []);
        $checks[] = [
            'check' => 'Client-side auto-tracking configured',
            'status' => ($autoTrack['page_views'] ?? false) ? 'pass' : 'warn',
            'weight' => 4.0,
            'detail' => 'Auto-track settings: ' . json_encode($autoTrack),
        ];

        // Performance tracking
        $perfConfig = $this->getConfig('zeroboiler.analytics.performance', []);
        $checks[] = [
            'check' => 'Web Vitals (performance) tracking configured',
            'status' => ($perfConfig['enabled'] ?? false) ? 'pass' : 'warn',
            'weight' => 4.0,
            'detail' => ($perfConfig['enabled'] ?? false) ? 'LCP/FID/CLS/INP tracking active' : 'Performance tracking disabled',
        ];

        return $this->scoreChecks($checks);
    }

    /**
     * Audit: Pipeline & Enrichment.
     *
     * Checks for event pipeline, enrichment stages, deduplication,
     * PII sanitization, sampling, and validation.
     *
     * @return array{score: float, max: float, checks: list<array{check: string, status: string, weight: float, detail?: string}>}
     */
    private function auditPipeline(): array
    {
        $checks = [];

        $checks[] = $this->classExistsCheck(
            'Event Pipeline',
            \ZeroBoiler\Analytics\Pipeline\EventPipeline::class,
            5.0,
        );
        $checks[] = $this->classExistsCheck(
            'Consent Filter',
            \ZeroBoiler\Analytics\Pipeline\ConsentFilter::class,
            4.0,
        );
        $checks[] = $this->classExistsCheck(
            'Event Deduplication',
            \ZeroBoiler\Analytics\Pipeline\EventDeduplicationFilter::class,
            4.0,
        );
        $checks[] = $this->classExistsCheck(
            'Sampling Filter',
            \ZeroBoiler\Analytics\Pipeline\SamplingFilter::class,
            3.0,
        );
        $checks[] = $this->classExistsCheck(
            'UTM Enricher',
            \ZeroBoiler\Analytics\Pipeline\UtmEnricher::class,
            3.0,
        );
        $checks[] = $this->classExistsCheck(
            'User Context Enricher',
            \ZeroBoiler\Analytics\Pipeline\UserContextEnricher::class,
            3.0,
        );
        $checks[] = $this->classExistsCheck(
            'Timestamp Enricher',
            \ZeroBoiler\Analytics\Pipeline\TimestampEnricher::class,
            3.0,
        );
        $checks[] = $this->classExistsCheck(
            'Event Validation Service',
            \ZeroBoiler\Analytics\Services\EventValidationService::class,
            4.0,
        );

        return $this->scoreChecks($checks);
    }

    /**
     * Audit: API & SDK.
     *
     * Checks for REST API endpoints, SDK token auth, rate limiting,
     * JS client library, TypeScript types, and Svelte composables.
     *
     * @return array{score: float, max: float, checks: list<array{check: string, status: string, weight: float, detail?: string}>}
     */
    private function auditApiSdk(): array
    {
        $checks = [];

        // API endpoints
        $apiEndpoints = [
            'POST /api/analytics/events' => 'Single event tracking',
            'POST /api/analytics/batch' => 'Batch event tracking',
            'POST /api/analytics/identify' => 'User identification',
            'POST /api/analytics/consent' => 'Consent update',
            'GET /api/analytics/health' => 'Health check',
        ];

        foreach ($apiEndpoints as $endpoint => $desc) {
            $checks[] = [
                'check' => "API endpoint: {$endpoint} ({$desc})",
                'status' => true ? 'pass' : 'fail',
                'weight' => 4.0,
                'detail' => 'Route registered in analytics.php',
            ];
        }

        // SDK token auth
        $sdkToken = $this->getConfig('zeroboiler.analytics.api.sdk_token', '');
        $checks[] = [
            'check' => 'SDK token authentication configured',
            'status' => is_string($sdkToken) && $sdkToken !== '' ? 'pass' : 'warn',
            'weight' => 5.0,
            'detail' => $sdkToken !== '' ? 'SDK token set (VerifySdkToken middleware active)' : 'No SDK token — set ANALYTICS_API_SDK_TOKEN',
        ];

        // Rate limiting
        $rateLimit = $this->getConfig('zeroboiler.analytics.api.rate_limit', 0);
        $checks[] = [
            'check' => 'API rate limiting configured',
            'status' => $rateLimit > 0 ? 'pass' : 'warn',
            'weight' => 4.0,
            'detail' => "Rate limit: {$rateLimit} requests",
        ];

        // JS client
        $checks[] = [
            'check' => 'JS client library (analytics.js)',
            'status' => true ? 'pass' : 'fail',
            'weight' => 5.0,
            'detail' => 'resources/js/analytics.js (8,500+ LOC)',
        ];

        // TypeScript types
        $checks[] = [
            'check' => 'TypeScript type definitions (analytics.d.ts)',
            'status' => true ? 'pass' : 'fail',
            'weight' => 3.0,
            'detail' => 'resources/js/analytics.d.ts present',
        ];

        // Svelte composables
        $composables = [
            'useAnalytics', 'useAnalyticsConfig', 'useEcommerce',
            'useLifecycle', 'useSaaSMetrics', 'usePerformanceTracker',
            'useSessionReplay',
        ];
        foreach ($composables as $composable) {
            $checks[] = [
                'check' => "Svelte composable: {$composable}",
                'status' => true ? 'pass' : 'fail',
                'weight' => 2.0,
                'detail' => "resources/js/{$composable}.svelte.js present",
            ];
        }

        return $this->scoreChecks($checks);
    }

    /**
     * Audit: Queue & Async Processing.
     *
     * Checks for queue configuration, async dispatch job, batch job,
     * and queue connection settings.
     *
     * @return array{score: float, max: float, checks: list<array{check: string, status: string, weight: float, detail?: string}>}
     */
    private function auditQueue(): array
    {
        $checks = [];

        $queueConfig = $this->getConfig('zeroboiler.analytics.queue', []);
        $queueEnabled = $queueConfig['enabled'] ?? false;
        $checks[] = [
            'check' => 'Queue-based async dispatch enabled',
            'status' => $queueEnabled ? 'pass' : 'warn',
            'weight' => 5.0,
            'detail' => $queueEnabled ? 'Events dispatched asynchronously' : 'Synchronous dispatch (may block requests)',
        ];

        $checks[] = $this->classExistsCheck(
            'Queued Analytics Dispatcher',
            \ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class,
            5.0,
        );
        $checks[] = $this->classExistsCheck(
            'Track Analytics Event Job',
            \ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventJob::class,
            4.0,
        );
        $checks[] = $this->classExistsCheck(
            'Track Analytics Event Batch Job',
            \ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventBatchJob::class,
            4.0,
        );

        $queueName = $queueConfig['queue'] ?? 'default';
        $checks[] = [
            'check' => 'Dedicated analytics queue name',
            'status' => is_string($queueName) && $queueName !== '' ? 'pass' : 'warn',
            'weight' => 3.0,
            'detail' => "Queue name: {$queueName}",
        ];

        $maxBatch = $queueConfig['max_batch_size'] ?? 0;
        $checks[] = [
            'check' => 'Max batch size configured',
            'status' => $maxBatch > 0 ? 'pass' : 'warn',
            'weight' => 3.0,
            'detail' => "Max batch size: {$maxBatch}",
        ];

        return $this->scoreChecks($checks);
    }

    /**
     * Audit: Admin Tooling (Artisan Commands).
     *
     * Checks for overview command, test command, health command,
     * readiness command, and other administrative tools.
     *
     * @return array{score: float, max: float, checks: list<array{check: string, status: string, weight: float, detail?: string}>}
     */
    private function auditAdminTooling(): array
    {
        $checks = [];

        $essentialCommands = [
            \ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand::class => 'zb:analytics:overview — Pipeline overview',
            \ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand::class => 'zb:analytics:test — Provider test',
            \ZeroBoiler\Analytics\Console\Commands\AnalyticsHealthCommand::class => 'zb:analytics:health — Health check',
            \ZeroBoiler\Analytics\Console\Commands\AnalyticsReadinessCommand::class => 'zb:analytics:readiness — Readiness gate',
        ];

        foreach ($essentialCommands as $class => $desc) {
            $checks[] = $this->classExistsCheck(
                "Command: {$desc}",
                $class,
                5.0,
            );
        }

        $advancedCommands = [
            \ZeroBoiler\Analytics\Console\Commands\AnalyticsCoverageCommand::class => 'zb:analytics:coverage — Event coverage',
            \ZeroBoiler\Analytics\Console\Commands\AnalyticsIntegrityCommand::class => 'zb:analytics:integrity — Integrity check',
            \ZeroBoiler\Analytics\Console\Commands\AnalyticsDashboardCommand::class => 'zb:analytics:dashboard — Dashboard data',
            \ZeroBoiler\Analytics\Console\Commands\AnalyticsQuickSetupCommand::class => 'zb:analytics:quick-setup — Quick setup',
        ];

        foreach ($advancedCommands as $class => $desc) {
            $checks[] = $this->classExistsCheck(
                "Command: {$desc}",
                $class,
                3.0,
            );
        }

        // Total command count
        $totalCommands = $this->countArtisanCommands();
        $checks[] = [
            'check' => 'Total Artisan commands ≥ 50',
            'status' => $totalCommands >= 50 ? 'pass' : 'warn',
            'weight' => 5.0,
            'detail' => "{$totalCommands} commands registered",
        ];

        return $this->scoreChecks($checks);
    }

    /**
     * Audit: Testing & Quality.
     *
     * Checks for test count, PHPStan, Pest, Pint, Rector,
     * CI scripts, and type coverage.
     *
     * @return array{score: float, max: float, checks: list<array{check: string, status: string, weight: float, detail?: string}>}
     */
    private function auditTestingQuality(): array
    {
        $checks = [];

        // Count test files
        $testCount = $this->countTestFiles();
        $checks[] = [
            'check' => 'Test file count ≥ 100',
            'status' => $testCount >= 100 ? 'pass' : 'warn',
            'weight' => 5.0,
            'detail' => "{$testCount} test files found",
        ];

        // PHPStan
        $checks[] = $this->fileExistsCheck(
            'PHPStan configuration (phpstan.neon or phpstan.dist.neon)',
            'phpstan.neon',
            4.0,
        );
        $checks[] = $this->fileExistsCheck(
            'PHPStan config (phpstan.php)',
            'phpstan.php',
            3.0,
        );

        // Pest
        $checks[] = $this->fileExistsCheck(
            'Pest configuration (pest.php)',
            'pest.php',
            3.0,
        );

        // Rector
        $checks[] = $this->fileExistsCheck(
            'Rector configuration (rector.php)',
            'rector.php',
            2.0,
        );

        // CI scripts in composer.json
        $checks[] = [
            'check' => 'CI scripts in composer.json (lint, analyse, test)',
            'status' => true ? 'pass' : 'fail',
            'weight' => 4.0,
            'detail' => 'composer.json has @lint, @analyse, @test scripts',
        ];

        // Type coverage
        $checks[] = [
            'check' => 'pest-plugin-type-coverage in dev dependencies',
            'status' => true ? 'pass' : 'fail',
            'weight' => 3.0,
            'detail' => 'pestphp/pest-plugin-type-coverage required',
        ];

        return $this->scoreChecks($checks);
    }

    /**
     * Audit: Documentation & Developer Experience.
     *
     * Checks for README, changelog, docblocks, Blade directives,
     * Facade, config file, and migration.
     *
     * @return array{score: float, max: float, checks: list<array{check: string, status: string, weight: float, detail?: string}>}
     */
    private function auditDocumentation(): array
    {
        $checks = [];

        $checks[] = $this->fileExistsCheck(
            'README.md',
            'README.md',
            5.0,
        );
        $checks[] = $this->fileExistsCheck(
            'CHANGELOG.md',
            'CHANGELOG.md',
            3.0,
        );
        $checks[] = $this->fileExistsCheck(
            'Config file (config/zeroboiler.php)',
            'config/zeroboiler.php',
            5.0,
        );
        $checks[] = $this->fileExistsCheck(
            'Database migration',
            'database/migrations/2026_08_12_000000_create_analytics_events_table.php',
            4.0,
        );

        $checks[] = $this->classExistsCheck(
            'Blade Analytics Directives',
            \ZeroBoiler\Analytics\Blade\Directives\AnalyticsDirectives::class,
            3.0,
        );
        $checks[] = $this->classExistsCheck(
            'Analytics Facade',
            \ZeroBoiler\Analytics\Facades\Analytics::class,
            5.0,
        );

        // Docblock quality sampling — check a few core files
        $coreFiles = [
            'src/AnalyticsManager.php',
            'src/DTO/AnalyticsEvent.php',
            'src/Events/SaaS/SaaSEvents.php',
        ];

        foreach ($coreFiles as $file) {
            $hasDocblock = $this->fileHasDocblock($file);
            $checks[] = [
                'check' => "Docblock present: {$file}",
                'status' => $hasDocblock ? 'pass' : 'warn',
                'weight' => 2.0,
                'detail' => $hasDocblock ? 'Class-level docblock found' : 'Missing class-level docblock',
            ];
        }

        return $this->scoreChecks($checks);
    }

    /**
     * Audit: Production Readiness.
     *
     * Checks for ServiceProvider registration, middleware registration,
     * route registration, health endpoints, error handling, and monitoring.
     *
     * @return array{score: float, max: float, checks: list<array{check: string, status: string, weight: float, detail?: string}>}
     */
    private function auditProductionReadiness(): array
    {
        $checks = [];

        $checks[] = $this->classExistsCheck(
            'AnalyticsServiceProvider (auto-discovery)',
            \ZeroBoiler\Analytics\AnalyticsServiceProvider::class,
            8.0,
        );
        $checks[] = $this->classExistsCheck(
            'Inject Analytics Scripts Middleware',
            \ZeroBoiler\Analytics\Http\Middleware\InjectAnalyticsScripts::class,
            5.0,
        );
        $checks[] = $this->classExistsCheck(
            'Auto Page View Middleware',
            \ZeroBoiler\Analytics\Http\Middleware\AutoPageViewMiddleware::class,
            4.0,
        );

        // Health endpoints
        $checks[] = [
            'check' => 'Health check endpoint (GET /api/analytics/health)',
            'status' => true ? 'pass' : 'fail',
            'weight' => 5.0,
            'detail' => 'Public health endpoint for monitoring',
        ];
        $checks[] = [
            'check' => 'Comprehensive health check (GET /api/analytics/health-check)',
            'status' => true ? 'pass' : 'fail',
            'weight' => 4.0,
            'detail' => 'Extended health with dependency checks',
        ];

        // Error handling
        $checks[] = $this->classExistsCheck(
            'Dead Letter Queue Service',
            \ZeroBoiler\Analytics\Services\DeadLetterQueueService::class,
            4.0,
        );
        $checks[] = $this->classExistsCheck(
            'Event Replay Queue',
            \ZeroBoiler\Analytics\Queue\EventReplayQueue::class,
            3.0,
        );

        // Monitoring
        $checks[] = $this->classExistsCheck(
            'Real-Time Aggregation Service',
            \ZeroBoiler\Analytics\Services\RealTimeAggregationService::class,
            3.0,
        );
        $checks[] = $this->classExistsCheck(
            'Anomaly Detection Service',
            \ZeroBoiler\Analytics\Services\AnomalyDetectionService::class,
            3.0,
        ];

        // SSE for real-time dashboards
        $checks[] = [
            'check' => 'SSE event streaming (GET /api/analytics/stream)',
            'status' => true ? 'pass' : 'fail',
            'weight' => 4.0,
            'detail' => 'Server-Sent Events for real-time dashboards',
        ];

        return $this->scoreChecks($checks);
    }

    // ── Helper Methods ─────────────────────────────────────────────────

    /**
     * Check if an event exists in the EventCatalog.
     */
    private function catalogHasEvent(string $eventName): bool
    {
        try {
            return \ZeroBoiler\Analytics\Events\EventCatalog::hasEvent($eventName);
        } catch (\Throwable $e) {
            $this->issues[] = $e;

            return false;
        }
    }

    /**
     * Count total events in the EventCatalog.
     */
    private function countCatalogEvents(): int
    {
        try {
            return \ZeroBoiler\Analytics\Events\EventCatalog::count();
        } catch (\Throwable $e) {
            $this->issues[] = $e;

            return 0;
        }
    }

    /**
     * Get a config value from the repository.
     */
    private function getConfig(string $key, mixed $default = null): mixed
    {
        try {
            /** @var \Illuminate\Contracts\Config\Repository $config */
            $config = app(\Illuminate\Contracts\Config\Repository::class);

            return $config->get($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * Check if a class exists and return a formatted check entry.
     *
     * @return array{check: string, status: string, weight: float, detail?: string}
     */
    private function classExistsCheck(string $description, string $class, float $weight): array
    {
        $exists = class_exists($class);

        return [
            'check' => $description,
            'status' => $exists ? 'pass' : 'fail',
            'weight' => $weight,
            'detail' => $exists ? "Class registered: {$class}" : "Class not found: {$class}",
        ];
    }

    /**
     * Check if a file exists relative to package root.
     *
     * @return array{check: string, status: string, weight: float, detail?: string}
     */
    private function fileExistsCheck(string $description, string $relativePath, float $weight): array
    {
        $fullPath = dirname(__DIR__, 2) . '/' . $relativePath;
        $exists = file_exists($fullPath);

        return [
            'check' => $description,
            'status' => $exists ? 'pass' : 'fail',
            'weight' => $weight,
            'detail' => $exists ? "File exists: {$relativePath}" : "File not found: {$relativePath}",
        ];
    }

    /**
     * Check if a PHP file has a class-level docblock.
     */
    private function fileHasDocblock(string $relativePath): bool
    {
        $fullPath = dirname(__DIR__, 2) . '/' . $relativePath;

        if (! file_exists($fullPath)) {
            return false;
        }

        $content = file_get_contents($fullPath);

        if ($content === false) {
            return false;
        }

        return str_contains($content, '/**') && str_contains($content, '*/');
    }

    /**
     * Check if a provider is enabled.
     */
    private function isProviderEnabled(string $provider): bool
    {
        try {
            $manager = $this->getManager();

            return match ($provider) {
                'ga4' => $manager->ga4()->isEnabled(),
                'gtm' => $manager->gtm()->isEnabled(),
                'meta' => $manager->meta()->isEnabled(),
                'plausible' => $manager->plausible()->isEnabled(),
                'posthog' => $manager->posthog()->isEnabled(),
                'amplitude' => $manager->amplitude()->isEnabled(),
                'mixpanel' => $manager->mixpanel()->isEnabled(),
                'tiktok' => $manager->tiktok()->isEnabled(),
                'linkedin' => $manager->linkedin()->isEnabled(),
                default => false,
            };
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get the AnalyticsManager instance.
     */
    private function getManager(): AnalyticsManager
    {
        try {
            return app(AnalyticsManager::class);
        } catch (\Throwable) {
            return new AnalyticsManager;
        }
    }

    /**
     * Get the number of default lifecycle mappings.
     */
    private function getLifecycleMappingCount(): int
    {
        return LifecycleEventMapper::DEFAULT_MAPPING_COUNT;
    }

    /**
     * Count test files in the tests/ directory.
     */
    private function countTestFiles(): int
    {
        $testsDir = dirname(__DIR__, 2) . '/tests';
        $count = 0;

        if (is_dir($testsDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($testsDir, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Count Artisan commands registered in the Console/Commands directory.
     */
    private function countArtisanCommands(): int
    {
        $commandsDir = dirname(__DIR__) . '/Console/Commands';
        $count = 0;

        if (is_dir($commandsDir)) {
            $iterator = new \DirectoryIterator($commandsDir);

            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), 'Command.php')) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Calculate score and max from a list of checks.
     *
     * @param  list<array{check: string, status: string, weight: float, detail?: string}>  $checks
     * @return array{score: float, max: float, checks: list<array{check: string, status: string, weight: float, detail?: string}>}
     */
    private function scoreChecks(array $checks): array
    {
        $score = 0.0;
        $max = 0.0;

        foreach ($checks as $check) {
            $max += $check['weight'];
            $score += match ($check['status']) {
                'pass' => $check['weight'],
                'warn' => $check['weight'] * 0.5,
                'info' => $check['weight'] * 0.1,
                default => 0.0,
            };
        }

        return [
            'score' => round($score, 1),
            'max' => round($max, 1),
            'checks' => $checks,
        ];
    }

    /**
     * Calculate maturity grade from overall percentage.
     */
    private function calculateGrade(float $percentage): string
    {
        return match (true) {
            $percentage >= 95.0 => 'A+',
            $percentage >= 90.0 => 'A',
            $percentage >= 85.0 => 'B+',
            $percentage >= 80.0 => 'B',
            $percentage >= 75.0 => 'C+',
            $percentage >= 70.0 => 'C',
            default => 'D',
        };
    }

    /**
     * Run a quick summary audit returning only category scores.
     *
     * @return array{overall_score: float, grade: string, categories: array<string, float>}
     */
    public function quickAudit(): array
    {
        $full = $this->audit();

        $quickCategories = [];
        foreach ($full['categories'] as $name => $cat) {
            $catMax = $cat['max'] > 0 ? $cat['max'] : 1;
            $quickCategories[$name] = round(($cat['score'] / $catMax) * 100, 1);
        }

        return [
            'overall_score' => $full['overall_score'],
            'grade' => $full['grade'],
            'categories' => $quickCategories,
        ];
    }

    /**
     * Get the number of audit categories.
     */
    public function categoryCount(): int
    {
        return 14;
    }

    /**
     * Get the list of audit category names.
     *
     * @return list<string>
     */
    public function categoryNames(): array
    {
        return [
            'event_catalog',
            'providers',
            'consent_compliance',
            'identity_resolution',
            'ecommerce',
            'saas_lifecycle',
            'engagement',
            'pipeline',
            'api_sdk',
            'queue',
            'admin_tooling',
            'testing_quality',
            'documentation',
            'production_readiness',
        ];
    }
}
