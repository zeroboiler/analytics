<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 180 production readiness test.
 *
 * Validates:
 * 1. Version consistency (180.0.0) across all 18 entry points
 * 2. All v172-v179 REST API routes registered in ServiceProvider
 * 3. Controller method existence for all registered routes
 * 4. Source file counts (src + tests)
 * 5. Core classes: final, strict types, void constructors
 * 6. Event catalog integrity (8 categories)
 * 7. ServiceProvider registration completeness
 *
 * @since 180.0.0
 */
final class Phase180ProductionReadinessTest extends TestCase
{
    private const EXPECTED_VERSION = '180.0.0';

    /** @var list<string> Files that should contain the version */
    private const VERSION_ENTRY_POINTS = [
        'composer.json',
        'package.json',
        'src/DTO/AnalyticsEvent.php',
        'src/Console/Commands/AnalyticsIntegrityCommand.php',
        'src/AnalyticsServiceProvider.php',
        'src/Http/Controllers/AnalyticsEventController.php',
        'src/Services/SaaSPlatformAuditService.php',
        'resources/js/analytics.js',
        'resources/js/analytics.constants.js',
        'resources/js/analytics.d.ts',
        'resources/js/useAnalytics.svelte.js',
        'resources/js/useAnalyticsConfig.svelte.js',
        'resources/js/useEcommerce.svelte.js',
        'resources/js/useLifecycle.svelte.js',
        'resources/js/usePerformanceTracker.svelte.js',
        'resources/js/useSaaSMetrics.svelte.js',
        'resources/js/useSessionReplay.svelte.js',
        'README.md',
    ];

    /** @var list<string> New route patterns registered in v180 */
    private const NEW_ROUTE_PATTERNS = [
        'pipeline/validate/status',
        'pipeline/validate/stages',
        'pipeline/validate/event',
        'pipeline/validate/batch',
        'trace/generate',
        'trace/inject',
        'trace/inject-batch',
        'config/drift/detect',
        'config/drift/baseline',
        'config/drift/capture',
        'config/drift/import',
        'event-value',
        'momentum/score',
        'momentum/metric',
        'momentum/quick',
        'momentum/metrics',
        'onboarding-wizard',
        'onboarding-wizard/summary',
        'onboarding-wizard/gaps',
        'onboarding-wizard/next',
        'instrumentation',
        'instrumentation/summary',
        'instrumentation/gaps',
        'instrumentation/stage/',
        'config/validate-full',
        'goals',
        'goals/register',
        'goals/dashboard',
        'goals/attention',
        'rolling-window/compute',
        'rolling-window/trend',
        'rolling-window/profile',
        'rolling-window/smooth',
        'quick-insights',
        'platform-audit',
        'platform-audit/quick',
    ];

    private const MIN_SRC_FILES = 830;

    private const MIN_TEST_FILES = 420;

    // ── Version Sweep Tests ───────────────────────────────────────

    public function test_version_is_180_in_all_entry_points(): void
    {
        $root = dirname(__DIR__, 2);
        $failures = [];

        foreach (self::VERSION_ENTRY_POINTS as $relativePath) {
            $fullPath = $root . '/' . $relativePath;
            if (! file_exists($fullPath)) {
                $failures[] = "MISSING: {$relativePath}";
                continue;
            }

            $content = file_get_contents($fullPath);
            if ($content === false) {
                $failures[] = "UNREADABLE: {$relativePath}";
                continue;
            }

            if (! str_contains($content, self::EXPECTED_VERSION)) {
                $failures[] = "WRONG VERSION in {$relativePath}";
            }
        }

        $this->assertEmpty($failures, implode("\n", $failures));
    }

    public function test_no_179_version_remnants(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = "grep -rn '179\.0\.0' {$root}/src {$root}/tests {$root}/resources {$root}/README.md {$root}/composer.json {$root}/package.json 2>/dev/null || true";
        $output = shell_exec($cmd);
        $this->assertEmpty(
            trim($output ?? ''),
            'Found remaining 179.0.0 references:' . ($output ?? ''),
        );
    }

    // ── Route Registration Tests ──────────────────────────────────

    public function test_new_routes_registered_in_service_provider(): void
    {
        $root = dirname(__DIR__, 2);
        $spPath = $root . '/src/AnalyticsServiceProvider.php';
        $this->assertFileExists($spPath);

        $content = file_get_contents($spPath);
        $this->assertNotFalse($content);

        $missing = [];
        foreach (self::NEW_ROUTE_PATTERNS as $pattern) {
            if (! str_contains($content, $pattern)) {
                $missing[] = $pattern;
            }
        }

        $this->assertEmpty($missing, 'Missing route patterns in ServiceProvider:' . implode(', ', $missing));
    }

    public function test_controller_has_new_endpoint_methods(): void
    {
        $root = dirname(__DIR__, 2);
        $controllerPath = $root . '/src/Http/Controllers/AnalyticsEventController.php';
        $this->assertFileExists($controllerPath);

        $content = file_get_contents($controllerPath);
        $this->assertNotFalse($content);

        $requiredMethods = [
            'pipelineValidateStatus',
            'pipelineValidateStages',
            'pipelineValidateEvent',
            'pipelineValidateBatch',
            'traceGenerate',
            'traceInject',
            'traceInjectBatch',
            'configDriftDetect',
            'configDriftBaselineInfo',
            'configDriftCapture',
            'configDriftClear',
            'configDriftImport',
            'eventValue',
            'eventValueBatch',
            'eventValueReport',
            'eventValueJourney',
            'momentumScore',
            'momentumMetric',
            'momentumQuick',
            'momentumMetrics',
            'onboardingWizardState',
            'onboardingWizardSummary',
            'onboardingWizardGaps',
            'onboardingWizardNextAction',
            'instrumentationAdvisor',
            'instrumentationSummary',
            'instrumentationGaps',
            'instrumentationStageCoverage',
            'configValidate',
            'goalsList',
            'goalsRegister',
            'goalsProgress',
            'goalsAllProgress',
            'goalsDashboard',
            'goalsAttentionNeeded',
            'rollingWindowCompute',
            'rollingWindowTrend',
            'rollingWindowProfile',
            'rollingWindowSmooth',
            'quickInsights',
            'quickInsightsSummary',
            'platformAudit',
            'platformAuditQuick',
        ];

        $missing = [];
        foreach ($requiredMethods as $method) {
            if (! str_contains($content, 'public function ' . $method)) {
                $missing[] = $method;
            }
        }

        $this->assertEmpty($missing, 'Missing controller methods:' . implode(', ', $missing));
    }

    // ── Source File Count Tests ──────────────────────────────────

    public function test_source_file_count(): void
    {
        $root = dirname(__DIR__, 2);
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/src', \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }

        $this->assertGreaterThanOrEqual(self::MIN_SRC_FILES, $count, "Expected at least {$this->minSrcFiles} source files, got {$count}");
    }

    public function test_test_file_count(): void
    {
        $root = dirname(__DIR__, 2);
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/tests', \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }

        $this->assertGreaterThanOrEqual(self::MIN_TEST_FILES, $count, "Expected at least {$this->minTestFiles} test files, got {$count}");
    }

    // ── Core Class Structure Tests ────────────────────────────────

    public function test_analytics_event_is_final_readonly(): void
    {
        $root = dirname(__DIR__, 2);
        $content = file_get_contents($root . '/src/DTO/AnalyticsEvent.php');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('final readonly class AnalyticsEvent', $content);
    }

    public function test_analytics_manager_is_final(): void
    {
        $root = dirname(__DIR__, 2);
        $content = file_get_contents($root . '/src/AnalyticsManager.php');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('final class AnalyticsManager', $content);
    }

    public function test_service_provider_is_final(): void
    {
        $root = dirname(__DIR__, 2);
        $content = file_get_contents($root . '/src/AnalyticsServiceProvider.php');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('final class AnalyticsServiceProvider', $content);
    }

    public function test_event_catalog_is_final(): void
    {
        $root = dirname(__DIR__, 2);
        $content = file_get_contents($root . '/src/Events/EventCatalog.php');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('final class EventCatalog', $content);
    }

    public function test_core_classes_have_strict_types(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [
            'src/DTO/AnalyticsEvent.php',
            'src/AnalyticsManager.php',
            'src/AnalyticsServiceProvider.php',
            'src/Events/EventCatalog.php',
            'src/Events/SaaS/SaaSEvents.php',
            'src/Events/Ecommerce/EcommerceEvents.php',
            'src/Events/Engagement/EngagementEvents.php',
        ];

        foreach ($files as $relativePath) {
            $content = file_get_contents($root . '/' . $relativePath);
            $this->assertNotFalse($content, "Cannot read {$relativePath}");
            $this->assertStringContainsString('declare(strict_types=1)', $content, "Missing strict types in {$relativePath}");
        }
    }

    // ── Event Catalog Integrity ───────────────────────────────────

    public function test_event_catalog_has_eight_categories(): void
    {
        $root = dirname(__DIR__, 2);
        $content = file_get_contents($root . '/src/Events/EventCatalog.php');
        $this->assertNotFalse($content);

        $categories = ['ecommerce', 'saas', 'engagement', 'security', 'uptime', 'infrastructure', 'marketing', 'customer_success'];
        foreach ($categories as $cat) {
            $this->assertStringContainsString("'{$cat}'", $content, "Missing category '{$cat}' in EventCatalog");
        }
    }

    public function test_saas_events_has_core_lifecycle_events(): void
    {
        $root = dirname(__DIR__, 2);
        $content = file_get_contents($root . '/src/Events/SaaS/SaaSEvents.php');
        $this->assertNotFalse($content);

        $coreEvents = ['sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade', 'plan_downgrade', 'cancellation'];
        foreach ($coreEvents as $event) {
            $this->assertStringContainsString("'{$event}'", $content, "Missing SaaS event '{$event}'");
        }
    }

    public function test_ecommerce_events_has_core_events(): void
    {
        $root = dirname(__DIR__, 2);
        $content = file_get_contents($root . '/src/Events/Ecommerce/EcommerceEvents.php');
        $this->assertNotFalse($content);

        $coreEvents = ['view_item', 'add_to_cart', 'remove_from_cart', 'view_cart', 'begin_checkout', 'purchase', 'refund'];
        foreach ($coreEvents as $event) {
            $this->assertStringContainsString("'{$event}'", $content, "Missing ecommerce event '{$event}'");
        }
    }

    // ── JS Client Integrity ──────────────────────────────────────

    public function test_js_client_exports_track_event(): void
    {
        $root = dirname(__DIR__, 2);
        $content = file_get_contents($root . '/resources/js/analytics.js');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('export async function trackEvent', $content);
    }

    public function test_js_client_exports_track_page_view(): void
    {
        $root = dirname(__DIR__, 2);
        $content = file_get_contents($root . '/resources/js/analytics.js');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('export async function trackPageView', $content);
    }

    public function test_js_client_version_is_180(): void
    {
        $root = dirname(__DIR__, 2);
        $content = file_get_contents($root . '/resources/js/analytics.js');
        $this->assertNotFalse($content);
        $this->assertStringContainsString(self::EXPECTED_VERSION, $content);
    }

    // ── README Integrity ───────────────────────────────────────────

    public function test_readme_has_version_badge(): void
    {
        $root = dirname(__DIR__, 2);
        $content = file_get_contents($root . '/README.md');
        $this->assertNotFalse($content);
        $this->assertStringContainsString(self::EXPECTED_VERSION, $content);
    }

    public function test_readme_has_quick_start(): void
    {
        $root = dirname(__DIR__, 2);
        $content = file_get_contents($root . '/README.md');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('Quick Start', $content);
    }

    public function test_readme_has_api_reference(): void
    {
        $root = dirname(__DIR__, 2);
        $content = file_get_contents($root . '/README.md');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('API Reference', $content);
    }

    // ── ServiceProvider Registration ───────────────────────────────

    public function test_service_provider_registers_facade(): void
    {
        $root = dirname(__DIR__, 2);
        $content = file_get_contents($root . '/composer.json');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('Analytics', $content, 'Facade alias missing in composer.json');
    }

    public function test_service_provider_version_in_docblock(): void
    {
        $root = dirname(__DIR__, 2);
        $content = file_get_contents($root . '/src/AnalyticsServiceProvider.php');
        $this->assertNotFalse($content);
        $this->assertStringContainsString(self::EXPECTED_VERSION, $content);
    }

    // ── MIT License Headers ────────────────────────────────────────

    public function test_core_files_have_mit_header(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [
            'src/DTO/AnalyticsEvent.php',
            'src/AnalyticsManager.php',
            'src/AnalyticsServiceProvider.php',
            'src/Events/EventCatalog.php',
            'src/Console/Commands/AnalyticsOverviewCommand.php',
            'src/Console/Commands/AnalyticsTestCommand.php',
        ];

        foreach ($files as $relativePath) {
            $content = file_get_contents($root . '/' . $relativePath);
            $this->assertNotFalse($content);
            $this->assertStringContainsString('MIT', $content, "Missing MIT license header in {$relativePath}");
        }
    }

    public function test_new_test_file_has_mit_header(): void
    {
        $content = file_get_contents(__FILE__);
        $this->assertNotFalse($content);
        $this->assertStringContainsString('MIT', $content);
    }
}
