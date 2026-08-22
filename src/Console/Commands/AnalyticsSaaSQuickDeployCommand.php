<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaSStarterEvents;
use ZeroBoiler\Analytics\Services\CustomerHealthScoreService;
use ZeroBoiler\Analytics\Services\EventCatalogSemVerService;

/**
 * SaaS Starter Quick Deploy Command — one-command SaaS analytics readiness check.
 *
 * Validates that all industry-standard SaaS starter instrumentation is in place:
 * - Provider configuration completeness
 * - Event catalog coverage (20 essential events)
 * - Lifecycle event mapping activation
 * - Queue configuration
 * - API endpoint availability
 * - Identity tracking setup
 * - Consent mode configuration
 * - Customer health scoring readiness
 *
 * Outputs a deployment readiness score and actionable checklist.
 * Designed for CI/CD pipeline gates and pre-deployment validation.
 *
 * @since 240.0.0
 */
final class AnalyticsSaaSQuickDeployCommand extends Command
{
    protected $signature = 'zb:analytics:saas:deploy-check
        {--json : Output as JSON for CI/CD}
        {--fix-suggestions : Include fix suggestions for gaps}';

    protected $description = 'SaaS Starter deployment readiness check — validates analytics instrumentation completeness';

    public function handle(): int
    {
        $checks = $this->runAllChecks();
        $summary = $this->buildSummary($checks);

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'ready' => $summary['ready'],
                'score' => $summary['score'],
                'grade' => $summary['grade'],
                'checks' => $checks,
                'gaps' => $summary['gaps'],
                'catalog_version' => (new EventCatalogSemVerService())->currentVersion(),
                'package_version' => AnalyticsEvent::VERSION,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $summary['ready'] ? self::SUCCESS : self::FAILURE;
        }

        $this->renderReport($checks, $summary);

        return $summary['ready'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Run all deployment readiness checks.
     *
     * @return array<string, array{pass: bool, status: string, details: string, critical: bool}>
     */
    private function runAllChecks(): array
    {
        return [
            'provider_configured' => $this->checkProviderConfigured(),
            'catalog_complete' => $this->checkCatalogCoverage(),
            'lifecycle_enabled' => $this->checkLifecycleEnabled(),
            'queue_configured' => $this->checkQueueConfigured(),
            'api_enabled' => $this->checkApiEnabled(),
            'identity_tracking' => $this->checkIdentityTracking(),
            'consent_configured' => $this->checkConsentMode(),
            'saas_kpi_configured' => $this->checkSaasKpiConfigured(),
            'health_score_service' => $this->checkHealthScoreService(),
            'catalog_semver' => $this->checkCatalogSemVer(),
            'auto_track_enabled' => $this->checkAutoTrackEnabled(),
            'ecommerce_defaults' => $this->checkEcommerceDefaults(),
            'inertia_props' => $this->checkInertiaPropsReady(),
            'sdk_client_config' => $this->checkSdkClientConfig(),
        ];
    }

    /**
     * Check that at least one analytics provider is configured.
     */
    private function checkProviderConfigured(): array
    {
        $ga4Enabled = (bool) config('zeroboiler.analytics.ga4.enabled', false);
        $gtmEnabled = (bool) config('zeroboiler.analytics.gtm.enabled', false);
        $metaEnabled = (bool) config('zeroboiler.analytics.meta_pixel.enabled', false);
        $plausibleEnabled = (bool) config('zeroboiler.analytics.plausible.enabled', false);
        $posthogEnabled = (bool) config('zeroboiler.analytics.posthog.enabled', false);

        $enabledCount = 0;
        $enabledList = [];

        if ($ga4Enabled) { $enabledCount++; $enabledList[] = 'GA4'; }
        if ($gtmEnabled) { $enabledCount++; $enabledList[] = 'GTM'; }
        if ($metaEnabled) { $enabledCount++; $enabledList[] = 'Meta Pixel'; }
        if ($plausibleEnabled) { $enabledCount++; $enabledList[] = 'Plausible'; }
        if ($posthogEnabled) { $enabledCount++; $enabledList[] = 'PostHog'; }

        return [
            'pass' => $enabledCount >= 1,
            'status' => $enabledCount >= 1 ? 'PASS' : 'FAIL',
            'details' => $enabledCount >= 1
                ? "{$enabledCount} provider(s) enabled: " . implode(', ', $enabledList)
                : 'No analytics providers enabled. Configure at least one (GA4, GTM, Meta, Plausible, PostHog).',
            'critical' => true,
        ];
    }

    /**
     * Check SaaS Starter event catalog coverage.
     */
    private function checkCatalogCoverage(): array
    {
        $starterEvents = SaaSStarterEvents::all();
        $totalStarter = count($starterEvents);

        return [
            'pass' => $totalStarter >= 20,
            'status' => $totalStarter >= 20 ? 'PASS' : 'WARN',
            'details' => "SaaS Starter catalog contains {$totalStarter} essential events (target: 20+).",
            'critical' => false,
        ];
    }

    /**
     * Check lifecycle event mapping is enabled.
     */
    private function checkLifecycleEnabled(): array
    {
        $enabled = (bool) config('zeroboiler.analytics.lifecycle.enabled', true);
        $customCount = count(config('zeroboiler.analytics.lifecycle.custom_mappings', []));

        return [
            'pass' => $enabled,
            'status' => $enabled ? 'PASS' : 'WARN',
            'details' => $enabled
                ? "Lifecycle tracking enabled ({$customCount} custom mappings)."
                : 'Lifecycle tracking is disabled. Server-side auto-tracking will not work.',
            'critical' => false,
        ];
    }

    /**
     * Check queue configuration for async dispatch.
     */
    private function checkQueueConfigured(): array
    {
        $enabled = (bool) config('zeroboiler.analytics.queue.enabled', true);
        $queue = (string) config('zeroboiler.analytics.queue.queue', 'analytics');

        return [
            'pass' => $enabled,
            'status' => $enabled ? 'PASS' : 'WARN',
            'details' => $enabled
                ? "Async queue enabled (queue: {$queue})."
                : 'Queue disabled — events will dispatch synchronously (blocking).',
            'critical' => false,
        ];
    }

    /**
     * Check API endpoint availability.
     */
    private function checkApiEnabled(): array
    {
        $enabled = (bool) config('zeroboiler.analytics.api.enabled', true);
        $baseUrl = (string) config('zeroboiler.analytics.api.base_url', '/api/analytics');

        return [
            'pass' => $enabled,
            'status' => $enabled ? 'PASS' : 'WARN',
            'details' => $enabled
                ? "API enabled at {$baseUrl}."
                : 'API endpoints disabled — client-side event tracking unavailable.',
            'critical' => false,
        ];
    }

    /**
     * Check identity tracking configuration.
     */
    private function checkIdentityTracking(): array
    {
        $cookieName = (string) config('zeroboiler.analytics.identity.cookie_name', 'zb_analytics_id');
        $autoLink = (bool) config('zeroboiler.analytics.identity.auto_link', true);

        return [
            'pass' => $cookieName !== '' && $autoLink,
            'status' => $cookieName !== '' ? 'PASS' : 'WARN',
            'details' => "Identity cookie: {$cookieName}, auto-link: " . ($autoLink ? 'ON' : 'OFF'),
            'critical' => false,
        ];
    }

    /**
     * Check consent mode configuration.
     */
    private function checkConsentMode(): array
    {
        $default = (string) config('zeroboiler.analytics.consent.default', 'granted');
        $purposes = config('zeroboiler.analytics.consent.purposes', []);
        $purposeCount = is_array($purposes) ? count($purposes) : 0;

        return [
            'pass' => $purposeCount >= 2,
            'status' => $purposeCount >= 2 ? 'PASS' : 'WARN',
            'details' => "Consent default: {$default}, {$purposeCount} purposes defined (GDPR-ready).",
            'critical' => false,
        ];
    }

    /**
     * Check SaaS KPI calculator configuration.
     */
    private function checkSaasKpiConfigured(): array
    {
        $enabled = (bool) config('zeroboiler.analytics.saas_kpi_calc.enabled', true);
        $mrrGoal = (float) config('zeroboiler.analytics.saas_kpi_calc.mrr_goal', 10000);
        $tiers = config('zeroboiler.analytics.revenue.subscription_tiers', []);
        $tierCount = is_array($tiers) ? count($tiers) : 0;

        return [
            'pass' => $enabled,
            'status' => $enabled ? 'PASS' : 'WARN',
            'details' => "SaaS KPI calc: " . ($enabled ? 'ON' : 'OFF') . ", MRR goal: \${$mrrGoal}, {$tierCount} subscription tiers.",
            'critical' => false,
        ];
    }

    /**
     * Check Customer Health Score service availability.
     */
    private function checkHealthScoreService(): array
    {
        $serviceExists = class_exists(CustomerHealthScoreService::class);

        return [
            'pass' => $serviceExists,
            'status' => $serviceExists ? 'PASS' : 'FAIL',
            'details' => $serviceExists
                ? 'CustomerHealthScoreService available for composite health scoring.'
                : 'CustomerHealthScoreService not found.',
            'critical' => false,
        ];
    }

    /**
     * Check Event Catalog SemVer service availability.
     */
    private function checkCatalogSemVer(): array
    {
        $serviceExists = class_exists(EventCatalogSemVerService::class);

        return [
            'pass' => $serviceExists,
            'status' => $serviceExists ? 'PASS' : 'FAIL',
            'details' => $serviceExists
                ? 'EventCatalogSemVerService available for schema versioning.'
                : 'EventCatalogSemVerService not found.',
            'critical' => false,
        ];
    }

    /**
     * Check client-side auto-tracking is configured.
     */
    private function checkAutoTrackEnabled(): array
    {
        $autoTrack = config('zeroboiler.analytics.client_auto_track', []);
        /** @var array{page_views?: bool, scroll_depth?: bool, form_tracking?: bool, error_tracking?: bool} $autoTrack */
        $pageViews = (bool) ($autoTrack['page_views'] ?? true);
        $scrollDepth = (bool) ($autoTrack['scroll_depth'] ?? true);
        $formTracking = (bool) ($autoTrack['form_tracking'] ?? true);
        $errorTracking = (bool) ($autoTrack['error_tracking'] ?? true);

        $activeCount = 0;
        if ($pageViews) { $activeCount++; }
        if ($scrollDepth) { $activeCount++; }
        if ($formTracking) { $activeCount++; }
        if ($errorTracking) { $activeCount++; }

        return [
            'pass' => $activeCount >= 2,
            'status' => $activeCount >= 2 ? 'PASS' : 'WARN',
            'details' => "Client auto-track: {$activeCount}/4 features active (pageViews, scrollDepth, formTracking, errorTracking).",
            'critical' => false,
        ];
    }

    /**
     * Check e-commerce defaults are configured.
     */
    private function checkEcommerceDefaults(): array
    {
        $currency = (string) config('zeroboiler.analytics.ecommerce.currency', 'USD');
        $brand = (string) config('zeroboiler.analytics.ecommerce.brand', '');

        return [
            'pass' => true,
            'status' => 'PASS',
            'details' => "E-commerce defaults: currency={$currency}" . ($brand !== '' ? ", brand={$brand}" : '') . '.',
            'critical' => false,
        ];
    }

    /**
     * Check Inertia props readiness (middleware registration hint).
     */
    private function checkInertiaPropsReady(): array
    {
        $apiBase = (string) config('zeroboiler.analytics.api.base_url', '/api/analytics');
        $apiEnabled = (bool) config('zeroboiler.analytics.api.enabled', true);

        return [
            'pass' => $apiEnabled,
            'status' => $apiEnabled ? 'PASS' : 'WARN',
            'details' => $apiEnabled
                ? "Inertia middleware ready (zbAnalytics props at {$apiBase})."
                : 'Inertia props will not inject zbAnalytics — API disabled.',
            'critical' => false,
        ];
    }

    /**
     * Check SDK client configuration completeness.
     */
    private function checkSdkClientConfig(): array
    {
        $sdkToken = config('zeroboiler.analytics.api.sdk_token');
        $hasToken = is_string($sdkToken) && $sdkToken !== '';

        return [
            'pass' => true, // Not critical — SDK token is optional
            'status' => $hasToken ? 'PASS' : 'INFO',
            'details' => $hasToken
                ? 'SDK token configured for authenticated API access.'
                : 'SDK token not set — public API endpoints only (recommended for client-side).',
            'critical' => false,
        ];
    }

    /**
     * Build a summary of all checks.
     *
     * @param  array<string, array{pass: bool, critical: bool}>  $checks
     * @return array{ready: bool, score: int, grade: string, gaps: list<string>, total: int, passed: int, failed: int}
     */
    private function buildSummary(array $checks): array
    {
        $total = count($checks);
        $passed = 0;
        $gaps = [];

        foreach ($checks as $name => $check) {
            if ($check['pass']) {
                $passed++;
            } else {
                $gaps[] = "{$name}: {$check['details']}";
            }
        }

        $score = $total > 0 ? (int) round($passed / $total * 100) : 0;

        if ($score >= 90) {
            $grade = 'A';
        } elseif ($score >= 80) {
            $grade = 'B';
        } elseif ($score >= 70) {
            $grade = 'C';
        } elseif ($score >= 50) {
            $grade = 'D';
        } else {
            $grade = 'F';
        }

        $ready = true;
        foreach ($checks as $check) {
            if (! $check['pass'] && $check['critical']) {
                $ready = false;
                break;
            }
        }

        return [
            'ready' => $ready,
            'score' => $score,
            'grade' => $grade,
            'gaps' => $gaps,
            'total' => $total,
            'passed' => $passed,
            'failed' => $total - $passed,
        ];
    }

    /**
     * Render the deployment readiness report.
     *
     * @param  array<string, array{pass: bool, status: string, details: string, critical: bool}>  $checks
     * @param  array{ready: bool, score: int, grade: string, gaps: list<string>, total: int, passed: int, failed: int}  $summary
     */
    private function renderReport(array $checks, array $summary): void
    {
        $this->info('🚀 ZeroBoiler Analytics — SaaS Starter Deploy Check');
        $this->line('   Package: v' . AnalyticsEvent::VERSION);
        $this->line('   Catalog: ' . EventCatalog::count() . ' events, ' . count(EventCatalog::byCategory()) . ' categories');

        // Catalog version
        try {
            $semver = new EventCatalogSemVerService();
            $this->line('   Catalog SemVer: <info>' . $semver->currentVersion() . '</info>');
        } catch (\Throwable) {
            $this->line('   Catalog SemVer: <comment>unavailable</comment>');
        }

        $this->newLine();

        // Score badge
        $gradeColor = match ($summary['grade']) {
            'A' => 'green',
            'B' => 'green',
            'C' => 'yellow',
            'D' => 'red',
            default => 'red',
        };

        $readyBadge = $summary['ready'] ? '<fg=green>✅ READY</>' : '<fg=red>❌ NOT READY</>';
        $this->line("   {$readyBadge} — Score: <fg={$gradeColor}>{$summary['score']}/100</> (Grade {$summary['grade']})");
        $this->line("   Checks: <info>{$summary['passed']}</info> passed, <comment>{$summary['failed']}</comment> failed of {$summary['total']} total");

        $this->newLine();

        // Check results table
        $rows = [];
        foreach ($checks as $name => $check) {
            $icon = $check['pass'] ? '✅' : ($check['critical'] ? '❌' : '⚠️');
            $statusColor = $check['pass'] ? 'green' : ($check['critical'] ? 'red' : 'yellow');
            $rows[] = [$icon, $name, "<fg={$statusColor}>{$check['status']}</>", $check['details']];
        }

        $this->table(['', 'Check', 'Status', 'Details'], $rows);

        // Gaps section
        if ($summary['gaps'] !== []) {
            $this->newLine();
            $this->warn('Gaps to address before deployment:');
            foreach ($summary['gaps'] as $gap) {
                $this->line("   ⚠️  {$gap}");
            }
        }

        if ($summary['ready'] && $summary['score'] >= 90) {
            $this->newLine();
            $this->info('🎉 Your analytics instrumentation is industry-standard SaaS ready!');
        }
    }
}
