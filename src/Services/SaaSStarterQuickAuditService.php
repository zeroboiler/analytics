<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\SaaSStarterEvents;

/**
 * SaaS Starter Quick Audit — single-call production readiness scorer.
 *
 * Validates all 12 industry-standard SaaS analytics features against the
 * current installation and returns an A+ through F grade with per-feature
 * scores, actionable gap analysis, and a prioritized remediation list.
 *
 * Designed for `zb:analytics:saas-audit` and CI/CD readiness gates.
 *
 * Scoring model:
 *   Each of the 12 features is scored 0–10 based on implementation
 *   completeness. Weighted into a composite 0–100 score:
 *
 *   1. Event Catalog (weight 0.12) — typed events + multi-provider mappings
 *   2. Server-Side Lifecycle Tracker (weight 0.10) — config-driven mapping
 *   3. Inertia Middleware (weight 0.10) — page props + client ID cookie
 *   4. API Controller + Routes (weight 0.08) — track/batch/identify/consent
 *   5. JS Client Library (weight 0.10) — trackEvent, scroll, consent, batch
 *   6. Event Queue (weight 0.07) — async dispatch jobs
 *   7. User Identity Linking (weight 0.08) — client ID ↔ user ID
 *   8. E-commerce Helpers (weight 0.08) — GA4 ↔ Meta format conversion
 *   9. Admin Commands (weight 0.07) — overview + test commands
 *  10. Config Expansion (weight 0.07) — 12+ config sections
 *  11. Optional Providers (weight 0.06) — Plausible, PostHog, etc.
  12. Tests + Documentation (weight 0.07) — test files + README
 *
 * Grade mapping:
 *   95–100 = A+  |  90–94 = A  |  85–89 = A-
 *   80–84  = B+  |  75–79 = B  |  70–74 = B-
 *   65–69  = C+  |  60–64 = C  |  55–59 = C-
 *   50–54  = D+  |  45–49 = D  |  40–44 = D-
 *    0–39  = F
 *
 * @since 252.0.0
 *
 * @see \ZeroBoiler\Analytics\Events\SaaSStarterEvents
 * @see \ZeroBoiler\Analytics\Events\EventCatalog
 */
final class SaaSStarterQuickAuditService
{
    /**
     * Feature definitions with weights and max scores.
     *
     * @var array<string, array{label: string, weight: float, max: int}>
     */
    private const FEATURES = [
        'event_catalog'        => ['label' => 'Event Catalog',               'weight' => 0.12, 'max' => 10],
        'lifecycle_tracker'    => ['label' => 'Server-Side Lifecycle',        'weight' => 0.10, 'max' => 10],
        'inertia_middleware'   => ['label' => 'Inertia Middleware',           'weight' => 0.10, 'max' => 10],
        'api_controller'       => ['label' => 'API Controller + Routes',     'weight' => 0.08, 'max' => 10],
        'js_client'            => ['label' => 'JS Client Library',            'weight' => 0.10, 'max' => 10],
        'event_queue'          => ['label' => 'Event Queue',                  'weight' => 0.07, 'max' => 10],
        'identity_linking'     => ['label' => 'User Identity Linking',       'weight' => 0.08, 'max' => 10],
        'ecommerce_helpers'    => ['label' => 'E-commerce Helpers',           'weight' => 0.08, 'max' => 10],
        'admin_commands'       => ['label' => 'Admin Commands',               'weight' => 0.07, 'max' => 10],
        'config_expansion'     => ['label' => 'Config Expansion',             'weight' => 0.07, 'max' => 10],
        'optional_providers'   => ['label' => 'Optional Providers',           'weight' => 0.06, 'max' => 10],
        'tests_documentation'  => ['label' => 'Tests + Documentation',        'weight' => 0.07, 'max' => 10],
    ];

    private ConfigRepository $config;

    public function __construct(ConfigRepository $config): void
    {
        $this->config = $config;
    }

    /**
     * Run the full SaaS starter audit.
     *
     * @return array{score: float, grade: string, features: array<string, array{label: string, score: int, max: int, weight: float, checks: list<array{pass: bool, description: string}>}>, gaps: list<array{feature: string, severity: string, finding: string, remediation: string}>, summary: array{total_checks: int, passed: int, failed: int, warnings: int, feature_count: int, catalog_events: int, starter_coverage: float}}
     */
    public function audit(): array
    {
        $features = $this->auditAllFeatures();
        $gaps = $this->extractGaps($features);

        $compositeScore = $this->computeCompositeScore($features);
        $grade = $this->computeGrade($compositeScore);

        // Summary stats
        $totalChecks = 0;
        $passedChecks = 0;
        foreach ($features as $f) {
            foreach ($f['checks'] as $check) {
                $totalChecks++;
                if ($check['pass']) {
                    $passedChecks++;
                }
            }
        }

        return [
            'score'   => round($compositeScore, 1),
            'grade'   => $grade,
            'features' => $features,
            'gaps'    => $gaps,
            'summary' => [
                'total_checks'     => $totalChecks,
                'passed'           => $passedChecks,
                'failed'           => $totalChecks - $passedChecks,
                'warnings'         => 0,
                'feature_count'    => 12,
                'catalog_events'   => EventCatalog::count(),
                'starter_coverage' => SaaSStarterEvents::coveragePercent(),
            ],
        ];
    }

    /**
     * Run the audit and return only the score and grade.
     *
     * @return array{score: float, grade: string}
     */
    public function quickScore(): array
    {
        $features = $this->auditAllFeatures();
        $compositeScore = $this->computeCompositeScore($features);

        return [
            'score' => round($compositeScore, 1),
            'grade' => $this->computeGrade($compositeScore),
        ];
    }

    /**
     * Check if the SaaS starter implementation is production-ready.
     *
     * Production-ready means: score >= 80.0 (grade B+ or above),
     * all critical features score >= 5/10, and starter coverage = 100%.
     */
    public function isProductionReady(): bool
    {
        $features = $this->auditAllFeatures();
        $compositeScore = $this->computeCompositeScore($features);

        if ($compositeScore < 80.0) {
            return false;
        }

        // All features must have at least 5/10
        foreach ($features as $f) {
            if ($f['score'] < 5) {
                return false;
            }
        }

        // Starter event catalog coverage must be 100%
        if (SaaSStarterEvents::coveragePercent() < 100.0) {
            return false;
        }

        return true;
    }

    /**
     * Audit all 12 features and return per-feature results.
     *
     * @return array<string, array{label: string, score: int, max: int, weight: float, checks: list<array{pass: bool, description: string}>}>
     */
    private function auditAllFeatures(): array
    {
        return [
            'event_catalog'       => $this->auditEventCatalog(),
            'lifecycle_tracker'   => $this->auditLifecycleTracker(),
            'inertia_middleware'  => $this->auditInertiaMiddleware(),
            'api_controller'      => $this->auditApiController(),
            'js_client'           => $this->auditJsClient(),
            'event_queue'         => $this->auditEventQueue(),
            'identity_linking'    => $this->auditIdentityLinking(),
            'ecommerce_helpers'   => $this->auditEcommerceHelpers(),
            'admin_commands'      => $this->auditAdminCommands(),
            'config_expansion'    => $this->auditConfigExpansion(),
            'optional_providers'  => $this->auditOptionalProviders(),
            'tests_documentation' => $this->auditTestsDocumentation(),
        ];
    }

    // ── Individual Feature Audits ──────────────────────────────────

    /**
     * @return array{label: string, score: int, max: int, weight: float, checks: list<array{pass: bool, description: string}>}
     */
    private function auditEventCatalog(): array
    {
        $checks = [];
        $catalogCount = EventCatalog::count();

        // Check 1: At least 150 events in catalog
        $checks[] = [
            'pass'        => $catalogCount >= 150,
            'description' => "Event catalog has {$catalogCount} events (expected >= 150)",
        ];

        // Check 2: Three core catalogs exist
        $ecomCount = EcommerceEvents::count();
        $saasCount = SaaSEvents::count();
        $engCount = EngagementEvents::count();
        $checks[] = [
            'pass'        => $ecomCount >= 10 && $saasCount >= 20 && $engCount >= 15,
            'description' => "Three catalogs: Ecommerce({$ecomCount}), SaaS({$saasCount}), Engagement({$engCount})",
        ];

        // Check 3: All 20 starter events in catalog
        $starterCoverage = SaaSStarterEvents::coveragePercent();
        $checks[] = [
            'pass'        => $starterCoverage >= 100.0,
            'description' => "SaaS Starter coverage: {$starterCoverage}%",
        ];

        // Check 4: Multi-provider mappings (GA4, Meta, PostHog)
        $hasGa4 = EcommerceEvents::ga4Names() !== [];
        $hasMeta = EcommerceEvents::metaNames() !== [];
        $checks[] = [
            'pass'        => $hasGa4 && $hasMeta,
            'description' => 'Multi-provider mappings: GA4 + Meta',
        ];

        // Check 5: Typed factory methods exist
        $checks[] = [
            'pass'        => method_exists(EcommerceEvents::class, 'purchase') && method_exists(SaaSEvents::class, 'signUp'),
            'description' => 'Typed factory methods on catalogs',
        ];

        return $this->buildFeatureResult('event_catalog', $checks);
    }

    /**
     * @return array{label: string, score: int, max: int, weight: float, checks: list<array{pass: bool, description: string}>}
     */
    private function auditLifecycleTracker(): array
    {
        $checks = [];
        $lifecycle = $this->config->get('zeroboiler.analytics.lifecycle', []);
        $autoTrack = $this->config->get('zeroboiler.analytics.auto_track', []);

        // Check 1: Lifecycle config enabled
        $checks[] = [
            'pass'        => (bool) ($lifecycle['enabled'] ?? false),
            'description' => 'Lifecycle tracking config enabled',
        ];

        // Check 2: Auto-track enabled
        $checks[] = [
            'pass'        => (bool) ($autoTrack['enabled'] ?? false),
            'description' => 'Auto-track config enabled',
        ];

        // Check 3: At least 5 auto-track events configured
        $eventToggles = $autoTrack['events'] ?? [];
        $enabledCount = count(array_filter($eventToggles));
        $checks[] = [
            'pass'        => $enabledCount >= 5,
            'description' => "{$enabledCount} auto-track events configured (expected >= 5)",
        ];

        // Check 4: Config-driven event map key exists
        $checks[] = [
            'pass'        => array_key_exists('event_map', $autoTrack),
            'description' => 'Config-driven event_map key present',
        ];

        // Check 5: Custom mappings key exists in lifecycle config
        $checks[] = [
            'pass'        => array_key_exists('custom_mappings', $lifecycle),
            'description' => 'Lifecycle custom_mappings key present',
        ];

        return $this->buildFeatureResult('lifecycle_tracker', $checks);
    }

    /**
     * @return array{label: string, score: int, max: int, weight: float, checks: list<array{pass: bool, description: string}>}
     */
    private function auditInertiaMiddleware(): array
    {
        $checks = [];
        $identity = $this->config->get('zeroboiler.analytics.identity', []);
        $consent = $this->config->get('zeroboiler.analytics.consent', []);
        $clientAuto = $this->config->get('zeroboiler.analytics.client_auto_track', []);

        // Check 1: Identity cookie name configured
        $checks[] = [
            'pass'        => ! empty($identity['cookie_name']),
            'description' => 'Client ID cookie name configured: ' . ($identity['cookie_name'] ?? 'missing'),
        ];

        // Check 2: Identity cookie TTL set
        $checks[] = [
            'pass'        => ($identity['cookie_ttl'] ?? 0) > 0,
            'description' => 'Client ID cookie TTL set',
        ];

        // Check 3: Consent purposes defined
        $purposes = $consent['purposes'] ?? [];
        $checks[] = [
            'pass'        => count($purposes) >= 3,
            'description' => count($purposes) . ' consent purposes defined',
        ];

        // Check 4: Client auto-track has page_views
        $checks[] = [
            'pass'        => (bool) ($clientAuto['page_views'] ?? false),
            'description' => 'Client auto-track.pageViews enabled',
        ];

        // Check 5: Client auto-track has scroll_depth
        $checks[] = [
            'pass'        => (bool) ($clientAuto['scroll_depth'] ?? false),
            'description' => 'Client auto-track.scrollDepth enabled',
        ];

        return $this->buildFeatureResult('inertia_middleware', $checks);
    }

    /**
     * @return array{label: string, score: int, max: int, weight: float, checks: list<array{pass: bool, description: string}>}
     */
    private function auditApiController(): array
    {
        $checks = [];
        $api = $this->config->get('zeroboiler.analytics.api', []);

        // Check 1: API enabled
        $checks[] = [
            'pass'        => (bool) ($api['enabled'] ?? false),
            'description' => 'API endpoint enabled',
        ];

        // Check 2: Rate limit configured
        $checks[] = [
            'pass'        => ($api['rate_limit'] ?? 0) > 0,
            'description' => 'API rate limit configured: ' . ($api['rate_limit'] ?? 0),
        ];

        // Check 3: Batch max size set
        $checks[] = [
            'pass'        => ($api['batch_max_size'] ?? 0) > 0,
            'description' => 'API batch max size configured',
        ];

        // Check 4: Auth required toggle present
        $checks[] = [
            'pass'        => array_key_exists('require_auth', $api),
            'description' => 'API require_auth toggle present',
        ];

        // Check 5: Routes file exists
        $routesFile = file_exists(__DIR__ . '/../../routes/analytics.php');
        $checks[] = [
            'pass'        => $routesFile,
            'description' => 'routes/analytics.php exists',
        ];

        return $this->buildFeatureResult('api_controller', $checks);
    }

    /**
     * @return array{label: string, score: int, max: int, weight: float, checks: list<array{pass: bool, description: string}>}
     */
    private function auditJsClient(): array
    {
        $checks = [];
        $jsPath = __DIR__ . '/../../resources/js/analytics.js';
        $svelteFiles = glob(__DIR__ . '/../../resources/js/use*.svelte.js');
        $tsPath = __DIR__ . '/../../resources/js/analytics.d.ts';

        // Check 1: Main JS client exists and is substantial (>1000 lines)
        $jsExists = file_exists($jsPath);
        $jsLines = $jsExists ? count(file($jsPath)) : 0;
        $checks[] = [
            'pass'        => $jsLines > 1000,
            'description' => "JS client library: {$jsLines} LOC (expected > 1,000)",
        ];

        // Check 2: TypeScript definitions exist
        $checks[] = [
            'pass'        => file_exists($tsPath),
            'description' => 'TypeScript definitions (analytics.d.ts) exist',
        ];

        // Check 3: At least 10 Svelte composables
        $composableCount = is_array($svelteFiles) ? count($svelteFiles) : 0;
        $checks[] = [
            'pass'        => $composableCount >= 10,
            'description' => "{$composableCount} Svelte composables (expected >= 10)",
        ];

        // Check 4: JS exports key functions
        $jsContent = $jsExists ? file_get_contents($jsPath) : '';
        $hasTrackEvent = str_contains($jsContent, 'export function trackEvent');
        $hasTrackPageView = str_contains($jsContent, 'export function trackPageView');
        $checks[] = [
            'pass'        => $hasTrackEvent && $hasTrackPageView,
            'description' => 'JS exports: trackEvent + trackPageView',
        ];

        // Check 5: Consent mode support in JS
        $hasConsent = str_contains($jsContent, 'consent') || str_contains($jsContent, 'Consent');
        $checks[] = [
            'pass'        => $hasConsent,
            'description' => 'JS client has consent mode support',
        ];

        return $this->buildFeatureResult('js_client', $checks);
    }

    /**
     * @return array{label: string, score: int, max: int, weight: float, checks: list<array{pass: bool, description: string}>}
     */
    private function auditEventQueue(): array
    {
        $checks = [];
        $queue = $this->config->get('zeroboiler.analytics.queue', []);

        // Check 1: Queue enabled
        $checks[] = [
            'pass'        => (bool) ($queue['enabled'] ?? false),
            'description' => 'Queue dispatch enabled',
        ];

        // Check 2: Queue name configured
        $checks[] = [
            'pass'        => ! empty($queue['queue']),
            'description' => 'Queue name configured: ' . ($queue['queue'] ?? 'missing'),
        ];

        // Check 3: Max batch size set
        $checks[] = [
            'pass'        => ($queue['max_batch_size'] ?? 0) > 0,
            'description' => 'Queue max batch size configured',
        ];

        // Check 4: QueuedAnalyticsDispatcher job class exists
        $dispatcherExists = class_exists(\ZeroBoiler\Analytics\Bus\QueuedAnalyticsDispatcher::class);
        $checks[] = [
            'pass'        => $dispatcherExists,
            'description' => 'QueuedAnalyticsDispatcher class exists',
        ];

        // Check 5: Queue job exists
        $jobExists = class_exists(\ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventJob::class);
        $checks[] = [
            'pass'        => $jobExists,
            'description' => 'TrackAnalyticsEventJob class exists',
        ];

        return $this->buildFeatureResult('event_queue', $checks);
    }

    /**
     * @return array{label: string, score: int, max: int, weight: float, checks: list<array{pass: bool, description: string}>}
     */
    private function auditIdentityLinking(): array
    {
        $checks = [];
        $identity = $this->config->get('zeroboiler.analytics.identity', []);

        // Check 1: Cookie name configured
        $checks[] = [
            'pass'        => ! empty($identity['cookie_name']),
            'description' => 'Identity cookie name configured',
        ];

        // Check 2: Link-on-auth enabled
        $checks[] = [
            'pass'        => (bool) ($identity['link_on_auth'] ?? false),
            'description' => 'Identity link_on_auth enabled',
        ];

        // Check 3: Auto-link enabled
        $checks[] = [
            'pass'        => (bool) ($identity['auto_link'] ?? false),
            'description' => 'Identity auto_link enabled',
        ];

        // Check 4: Cache prefix configured
        $checks[] = [
            'pass'        => ! empty($identity['cache_prefix']),
            'description' => 'Identity cache prefix configured',
        ];

        // Check 5: Link TTL set
        $checks[] = [
            'pass'        => ($identity['link_ttl'] ?? 0) > 0,
            'description' => 'Identity link TTL configured',
        ];

        return $this->buildFeatureResult('identity_linking', $checks);
    }

    /**
     * @return array{label: string, score: int, max: int, weight: float, checks: list<array{pass: bool, description: string}>}
     */
    private function auditEcommerceHelpers(): array
    {
        $checks = [];
        $ecommerce = $this->config->get('zeroboiler.analytics.ecommerce', []);

        // Check 1: Currency configured
        $checks[] = [
            'pass'        => ! empty($ecommerce['currency']),
            'description' => 'E-commerce currency configured: ' . ($ecommerce['currency'] ?? 'missing'),
        ];

        // Check 2: Tax behavior configured
        $checks[] = [
            'pass'        => ! empty($ecommerce['tax_behavior']),
            'description' => 'E-commerce tax behavior configured',
        ];

        // Check 3: EcommerceAnalyticsService exists
        $serviceExists = class_exists(\ZeroBoiler\Analytics\Services\EcommerceAnalyticsService::class);
        $checks[] = [
            'pass'        => $serviceExists,
            'description' => 'EcommerceAnalyticsService class exists',
        ];

        // Check 4: EcommerceFormatConverter exists
        $converterExists = class_exists(\ZeroBoiler\Analytics\Services\EcommerceFormatConverter::class);
        $checks[] = [
            'pass'        => $converterExists,
            'description' => 'EcommerceFormatConverter class exists (GA4 ↔ Meta)',
        ];

        // Check 5: Checkout tracking config exists
        $checkout = $this->config->get('zeroboiler.analytics.checkout_tracking', []);
        $checks[] = [
            'pass'        => array_key_exists('enabled', $checkout),
            'description' => 'Checkout tracking config section present',
        ];

        return $this->buildFeatureResult('ecommerce_helpers', $checks);
    }

    /**
     * @return array{label: string, score: int, max: int, weight: float, checks: list<array{pass: bool, description: string}>}
     */
    private function auditAdminCommands(): array
    {
        $checks = [];

        // Check 1: AnalyticsOverviewCommand exists
        $checks[] = [
            'pass'        => class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand::class),
            'description' => 'AnalyticsOverviewCommand exists',
        ];

        // Check 2: AnalyticsTestCommand exists
        $checks[] = [
            'pass'        => class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand::class),
            'description' => 'AnalyticsTestCommand exists',
        ];

        // Check 3: Quick setup command exists
        $checks[] = [
            'pass'        => class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsQuickSetupCommand::class),
            'description' => 'AnalyticsQuickSetupCommand exists',
        ];

        // Check 4: Health command exists
        $checks[] = [
            'pass'        => class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsHealthCommand::class),
            'description' => 'AnalyticsHealthCommand exists',
        ];

        // Check 5: Integrity command exists
        $checks[] = [
            'pass'        => class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsIntegrityCommand::class),
            'description' => 'AnalyticsIntegrityCommand exists',
        ];

        return $this->buildFeatureResult('admin_commands', $checks);
    }

    /**
     * @return array{label: string, score: int, max: int, weight: float, checks: list<array{pass: bool, description: string}>}
     */
    private function auditConfigExpansion(): array
    {
        $checks = [];
        $analytics = $this->config->get('zeroboiler.analytics', []);

        // Required config sections for SaaS starter
        $requiredSections = [
            'ga4', 'gtm', 'meta_pixel', 'consent', 'auto_track',
            'queue', 'lifecycle', 'api', 'identity', 'ecommerce',
            'revenue', 'client_auto_track',
        ];

        // Check 1: At least 12 config sections present
        $presentCount = 0;
        foreach ($requiredSections as $section) {
            if (array_key_exists($section, $analytics)) {
                $presentCount++;
            }
        }
        $checks[] = [
            'pass'        => $presentCount >= 12,
            'description' => "{$presentCount}/13 required config sections present",
        ];

        // Check 2: Queue config has connection option
        $queue = $analytics['queue'] ?? [];
        $checks[] = [
            'pass'        => array_key_exists('connection', $queue),
            'description' => 'Queue connection option configurable',
        ];

        // Check 3: API config has SDK token
        $api = $analytics['api'] ?? [];
        $checks[] = [
            'pass'        => array_key_exists('sdk_token', $api),
            'description' => 'API SDK token option present',
        ];

        // Check 4: Dedup cache config exists
        $checks[] = [
            'pass'        => array_key_exists('dedup_cache', $analytics),
            'description' => 'Deduplication cache config section present',
        ];

        // Check 5: Revenue config exists
        $checks[] = [
            'pass'        => array_key_exists('revenue', $analytics),
            'description' => 'Revenue config section present',
        ];

        return $this->buildFeatureResult('config_expansion', $checks);
    }

    /**
     * @return array{label: string, score: int, max: int, weight: float, checks: list<array{pass: bool, description: string}>}
     */
    private function auditOptionalProviders(): array
    {
        $checks = [];
        $analytics = $this->config->get('zeroboiler.analytics', []);

        // Check 1: Plausible config section
        $checks[] = [
            'pass'        => array_key_exists('plausible', $analytics),
            'description' => 'Plausible config section present',
        ];

        // Check 2: PostHog config section
        $checks[] = [
            'pass'        => array_key_exists('posthog', $analytics),
            'description' => 'PostHog config section present',
        ];

        // Check 3: PlausibleTracker class exists
        $checks[] = [
            'pass'        => class_exists(\ZeroBoiler\Analytics\Trackers\PlausibleTracker::class),
            'description' => 'PlausibleTracker class exists',
        ];

        // Check 4: PosthogTracker class exists
        $checks[] = [
            'pass'        => class_exists(\ZeroBoiler\Analytics\Trackers\PosthogTracker::class),
            'description' => 'PosthogTracker class exists',
        ];

        // Check 5: At least one optional provider is enabled
        $plausibleEnabled = (bool) ($analytics['plausible']['enabled'] ?? false);
        $posthogEnabled = (bool) ($analytics['posthog']['enabled'] ?? false);
        $checks[] = [
            'pass'        => $plausibleEnabled || $posthogEnabled,
            'description' => 'At least one optional provider enabled',
        ];

        return $this->buildFeatureResult('optional_providers', $checks);
    }

    /**
     * @return array{label: string, score: int, max: int, weight: float, checks: list<array{pass: bool, description: string}>}
     */
    private function auditTestsDocumentation(): array
    {
        $checks = [];
        $testsDir = __DIR__ . '/../../tests/';
        $readmePath = __DIR__ . '/../../README.md';
        $changelogPath = __DIR__ . '/../../CHANGELOG.md';

        // Check 1: Tests directory exists and has files
        $testFiles = is_dir($testsDir) ? glob($testsDir . '*Test.php') : [];
        $testCount = is_array($testFiles) ? count($testFiles) : 0;
        $checks[] = [
            'pass'        => $testCount >= 100,
            'description' => "{$testCount} test files (expected >= 100)",
        ];

        // Check 2: README exists and is substantial
        $readmeExists = file_exists($readmePath);
        $readmeLines = $readmeExists ? count(file($readmePath)) : 0;
        $checks[] = [
            'pass'        => $readmeLines > 200,
            'description' => "README: {$readmeLines} lines (expected > 200)",
        ];

        // Check 3: CHANGELOG exists
        $checks[] = [
            'pass'        => file_exists($changelogPath),
            'description' => 'CHANGELOG.md exists',
        ];

        // Check 4: phpstan.neon.dist exists
        $checks[] = [
            'pass'        => file_exists(__DIR__ . '/../../phpstan.neon.dist'),
            'description' => 'phpstan.neon.dist exists',
        ];

        // Check 5: CI workflow exists
        $checks[] = [
            'pass'        => file_exists(__DIR__ . '/../../.github/workflows/ci.yml'),
            'description' => 'GitHub Actions CI workflow exists',
        ];

        return $this->buildFeatureResult('tests_documentation', $checks);
    }

    // ── Scoring Helpers ──────────────────────────────────────────

    /**
     * Build a feature result with computed score.
     *
     * @param string $featureKey
     * @param list<array{pass: bool, description: string}> $checks
     * @return array{label: string, score: int, max: int, weight: float, checks: list<array{pass: bool, description: string}>}
     */
    private function buildFeatureResult(string $featureKey, array $checks): array
    {
        $def = self::FEATURES[$featureKey];
        $passed = count(array_filter($checks, fn (array $c): bool => $c['pass']));
        $score = (int) round(($passed / max(count($checks), 1)) * $def['max']);

        return [
            'label'  => $def['label'],
            'score'  => $score,
            'max'    => $def['max'],
            'weight' => $def['weight'],
            'checks' => $checks,
        ];
    }

    /**
     * Compute the weighted composite score (0–100).
     *
     * @param array<string, array{score: int, max: int, weight: float}> $features
     */
    private function computeCompositeScore(array $features): float
    {
        $total = 0.0;

        foreach ($features as $key => $feature) {
            $max = self::FEATURES[$key]['max'];
            $normalized = $max > 0 ? $feature['score'] / $max : 0;
            $total += $normalized * $feature['weight'];
        }

        return $total * 100.0;
    }

    /**
     * Convert a numeric score to a letter grade.
     */
    private function computeGrade(float $score): string
    {
        return match (true) {
            $score >= 97.0 => 'A+',
            $score >= 93.0 => 'A',
            $score >= 90.0 => 'A-',
            $score >= 87.0 => 'B+',
            $score >= 83.0 => 'B',
            $score >= 80.0 => 'B-',
            $score >= 77.0 => 'C+',
            $score >= 73.0 => 'C',
            $score >= 70.0 => 'C-',
            $score >= 67.0 => 'D+',
            $score >= 63.0 => 'D',
            $score >= 60.0 => 'D-',
            default      => 'F',
        };
    }

    /**
     * Extract actionable gaps from feature audit results.
     *
     * @param array<string, array{label: string, score: int, checks: list<array{pass: bool, description: string}>}> $features
     * @return list<array{feature: string, severity: string, finding: string, remediation: string}>
     */
    private function extractGaps(array $features): array
    {
        $gaps = [];

        foreach ($features as $key => $feature) {
            foreach ($feature['checks'] as $check) {
                if ($check['pass']) {
                    continue;
                }

                $severity = $feature['score'] < 5 ? 'critical' : ($feature['score'] < 8 ? 'warning' : 'info');
                $gaps[] = [
                    'feature'     => $feature['label'],
                    'severity'    => $severity,
                    'finding'     => $check['description'],
                    'remediation' => $this->suggestRemediation($key, $check['description']),
                ];
            }
        }

        return $gaps;
    }

    /**
     * Suggest a remediation for a given feature and finding.
     */
    private function suggestRemediation(string $feature, string $finding): string
    {
        $remediations = [
            'event_catalog' => 'Ensure all 20 SaaS starter events are registered in EventCatalog with multi-provider mappings.',
            'lifecycle_tracker' => 'Enable zeroboiler.analytics.lifecycle.enabled and configure auto_track.events in config.',
            'inertia_middleware' => 'Configure identity.cookie_name and consent.purposes in config. Add analytics.inertia middleware to web routes.',
            'api_controller' => 'Enable zeroboiler.analytics.api.enabled and set rate limits. Ensure routes/analytics.php is published.',
            'js_client' => 'Verify resources/js/analytics.js has trackEvent/trackPageView exports. Ensure Svelte composables exist.',
            'event_queue' => 'Enable zeroboiler.analytics.queue.enabled and configure queue name and batch size.',
            'identity_linking' => 'Configure identity.cookie_name, identity.link_on_auth, and identity.cache_prefix.',
            'ecommerce_helpers' => 'Configure ecommerce.currency and ensure EcommerceFormatConverter is available for GA4 ↔ Meta conversion.',
            'admin_commands' => 'Run php artisan zb:analytics:overview to verify commands are registered.',
            'config_expansion' => 'Publish the full config: php artisan vendor:publish --tag=zeroboiler-analytics-config.',
            'optional_providers' => 'Add plausible and posthog config sections. Enable at least one optional provider.',
            'tests_documentation' => 'Add test files to tests/ directory. Ensure README and CHANGELOG are up to date.',
        ];

        return $remediations[$feature] ?? 'Review the feature implementation against the SaaS starter checklist.';
    }

    /**
     * Get the feature definitions (weights and labels).
     *
     * @return array<string, array{label: string, weight: float, max: int}>
     */
    public static function featureDefinitions(): array
    {
        return self::FEATURES;
    }

    /**
     * Get the weight sum for validation.
     */
    public static function totalWeight(): float
    {
        $total = 0.0;

        foreach (self::FEATURES as $def) {
            $total += $def['weight'];
        }

        return $total;
    }
}
