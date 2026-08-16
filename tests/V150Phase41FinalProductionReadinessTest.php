<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Marketing\MarketingEvents;
use ZeroBoiler\Analytics\Events\SaaS\CustomerSuccessEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;
use ZeroBoiler\Analytics\Events\Infrastructure\InfrastructureEvents;
use ZeroBoiler\Analytics\Exceptions\AnalyticsException;
use ZeroBoiler\Analytics\Exceptions\AnalyticsRuntimeException;
use ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Trackers\TrackerInterface;

/**
 * Phase 41 Final Production Readiness Audit — ZeroBoiler Analytics v150.0.0
 *
 * Comprehensive audit covering code quality, version consistency, exception hierarchy,
 * finality enforcement, #[Override] attributes on console commands/jobs/middleware,
 * interface compliance, config structure, license headers, and cross-reference integrity.
 *
 * @since 150.0.0
 */
final class V150Phase41FinalProductionReadinessTest extends TestCase
{
    private const VERSION = '150.0.0';

    private const PKG_ROOT = __DIR__ . '/../..';

    // ── 1. Version Consistency ──────────────────────────────────────────

    public function test_analytics_event_version_constant(): void
    {
        $this->assertSame(self::VERSION, AnalyticsEvent::VERSION);
    }

    public function test_composer_json_version(): void
    {
        $composer = json_decode(file_get_contents(self::PKG_ROOT . '/composer.json'), true);
        $this->assertArrayHasKey('version', $composer);
        $this->assertSame(self::VERSION, $composer['version']);
    }

    public function test_package_json_version(): void
    {
        $pkg = json_decode(file_get_contents(self::PKG_ROOT . '/package.json'), true);
        $this->assertArrayHasKey('version', $pkg);
        $this->assertSame(self::VERSION, $pkg['version']);
    }

    public function test_js_client_version(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/resources/js/analytics.js');
        $this->assertStringContainsString("return '" . self::VERSION . "'", $content);
    }

    public function test_js_svelte_modules_version(): void
    {
        $svelteFiles = glob(self::PKG_ROOT . '/resources/js/*.svelte.js');
        $this->assertNotEmpty($svelteFiles);

        foreach ($svelteFiles as $file) {
            $content = file_get_contents($file);
            $this->assertStringContainsString(
                '@version ' . self::VERSION,
                $content,
                "Version mismatch in " . basename($file),
            );
        }
    }

    public function test_js_constants_version(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/resources/js/analytics.constants.js');
        $this->assertStringContainsString('@version ' . self::VERSION, $content);
    }

    // ── 2. Source File Quality ──────────────────────────────────────────

    public function test_all_source_files_have_strict_types(): void
    {
        $phpFiles = glob(self::PKG_ROOT . '/src/**/*.php');
        $violations = [];

        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            if (!str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = str_replace(self::PKG_ROOT . '/', '', $file);
            }
        }

        $this->assertEmpty($violations, 'Files missing declare(strict_types=1): ' . implode(', ', $violations));
    }

    public function test_all_source_files_have_license_header(): void
    {
        $phpFiles = glob(self::PKG_ROOT . '/src/**/*.php');
        $violations = [];

        foreach ($phpFiles as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!in_array(' * This file is part of ZeroBoiler, licensed under the MIT license.', $lines, true)) {
                $violations[] = str_replace(self::PKG_ROOT . '/', '', $file);
            }
        }

        $this->assertEmpty($violations, 'Files missing license header: ' . implode(', ', $violations));
    }

    public function test_no_todo_or_fixme_markers(): void
    {
        $phpFiles = glob(self::PKG_ROOT . '/src/**/*.php');
        $violations = [];

        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            if (preg_match('/(?:TODO|FIXME|HACK|XXX)\s/', $content)) {
                $violations[] = str_replace(self::PKG_ROOT . '/', '', $file);
            }
        }

        $this->assertEmpty($violations, 'Files with TODO/FIXME/HACK/XXX markers: ' . implode(', ', $violations));
    }

    public function test_all_classes_are_final_or_abstract_or_interface_or_enum(): void
    {
        $phpFiles = glob(self::PKG_ROOT . '/src/**/*.php');
        $violations = [];

        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            // Skip if no class declaration
            if (!preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+\w+/m', $content)) {
                continue;
            }
            // Check for classes that are neither final nor abstract
            if (preg_match('/^\s+class\s+\w+/m', $content)) {
                $violations[] = str_replace(self::PKG_ROOT . '/', '', $file);
            }
        }

        $this->assertEmpty($violations, 'Classes missing final/abstract keyword: ' . implode(', ', $violations));
    }

    // ── 3. Exception Hierarchy ─────────────────────────────────────────

    public function test_exception_hierarchy_structure(): void
    {
        $this->assertTrue(
            (new \ReflectionClass(AnalyticsException::class))->isAbstract(),
            'AnalyticsException must be abstract',
        );
    }

    public function test_exception_base_constructor_has_void_return(): void
    {
        $method = new \ReflectionMethod(AnalyticsException::class, '__construct');
        $this->assertSame('void', (string) $method->getReturnType());
    }

    public function test_exception_leaves_are_final(): void
    {
        $this->assertTrue(
            (new \ReflectionClass(InvalidAnalyticsArgumentException::class))->isFinal(),
            'InvalidAnalyticsArgumentException must be final',
        );
        $this->assertTrue(
            (new \ReflectionClass(AnalyticsRuntimeException::class))->isFinal(),
            'AnalyticsRuntimeException must be final',
        );
    }

    public function test_exception_leaves_extend_base(): void
    {
        $this->assertTrue(
            is_subclass_of(InvalidAnalyticsArgumentException::class, AnalyticsException::class),
        );
        $this->assertTrue(
            is_subclass_of(AnalyticsRuntimeException::class, AnalyticsException::class),
        );
    }

    // ── 4. Facade Quality ──────────────────────────────────────────────

    public function test_facade_is_final(): void
    {
        $this->assertTrue(
            (new \ReflectionClass(Analytics::class))->isFinal(),
            'Analytics facade must be final',
        );
    }

    public function test_facade_has_override_on_get_facade_accessor(): void
    {
        $method = new \ReflectionMethod(Analytics::class, 'getFacadeAccessor');
        $attributes = $method->getAttributes();

        $hasOverride = false;
        foreach ($attributes as $attr) {
            if ($attr->getName() === 'Override') {
                $hasOverride = true;
                break;
            }
        }

        $this->assertTrue($hasOverride, 'getFacadeAccessor must have #[Override]');
    }

    // ── 5. Manager API Surface ─────────────────────────────────────────

    public function test_manager_is_final(): void
    {
        $this->assertTrue(
            (new \ReflectionClass(AnalyticsManager::class))->isFinal(),
        );
    }

    public function test_manager_has_core_public_methods(): void
    {
        $manager = new \ReflectionClass(AnalyticsManager::class);
        $publicMethods = array_filter(
            $manager->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn (\ReflectionMethod $m): bool => !$m->isStatic(),
        );

        $names = array_map(fn (\ReflectionMethod $m): string => $m->getName(), $publicMethods);

        // Core tracking
        $this->assertContains('track', $names);
        $this->assertContains('trackEvent', $names);
        $this->assertContains('purchase', $names);
        $this->assertContains('identify', $names);

        // Tracker accessors
        $this->assertContains('ga4', $names);
        $this->assertContains('gtm', $names);
        $this->assertContains('meta', $names);
        $this->assertContains('plausible', $names);
        $this->assertContains('posthog', $names);
        $this->assertContains('webhook', $names);

        // Consent
        $this->assertContains('setConsent', $names);
        $this->assertContains('grantConsent', $names);
        $this->assertContains('denyConsent', $names);
        $this->assertContains('getConsent', $names);

        // Script generation
        $this->assertContains('headScripts', $names);
        $this->assertContains('bodyScripts', $names);
    }

    // ── 6. Console Commands #[Override] Audit ──────────────────────────

    public function test_all_console_commands_have_final_class(): void
    {
        $commandFiles = glob(self::PKG_ROOT . '/src/Console/Commands/*.php');
        $violations = [];

        foreach ($commandFiles as $file) {
            $content = file_get_contents($file);
            if (!preg_match('/final\s+class\s+\w+Command\b/', $content)) {
                $violations[] = basename($file);
            }
        }

        $this->assertEmpty($violations, 'Commands not final: ' . implode(', ', $violations));
    }

    public function test_all_console_commands_have_override_on_handle(): void
    {
        $commandFiles = glob(self::PKG_ROOT . '/src/Console/Commands/*.php');
        $violations = [];

        foreach ($commandFiles as $file) {
            $content = file_get_contents($file);
            if (str_contains($content, 'public function handle(') && !str_contains($content, '#[Override]')) {
                $violations[] = basename($file);
            }
        }

        $this->assertEmpty($violations, 'Commands missing #[Override] on handle(): ' . implode(', ', $violations));
    }

    public function test_all_console_commands_handle_returns_int(): void
    {
        $commandFiles = glob(self::PKG_ROOT . '/src/Console/Commands/*.php');
        $violations = [];

        foreach ($commandFiles as $file) {
            $content = file_get_contents($file);
            if (preg_match('/public function handle\(/', $content) && !preg_match('/public function handle\([^)]+\):\s*int/', $content)) {
                // Multi-line check
                if (!preg_match('/public function handle\(/', $content)) {
                    continue;
                }
                $violations[] = basename($file);
            }
        }

        $this->assertEmpty($violations, 'Commands handle() not returning int: ' . implode(', ', $violations));
    }

    // ── 7. Jobs #[Override] Audit ──────────────────────────────────────

    public function test_all_jobs_have_override_on_handle(): void
    {
        $jobFiles = glob(self::PKG_ROOT . '/src/Jobs/*.php');
        $violations = [];

        foreach ($jobFiles as $file) {
            $content = file_get_contents($file);
            if (str_contains($content, 'public function handle(') && !str_contains($content, '#[Override]')) {
                $violations[] = basename($file);
            }
        }

        $this->assertEmpty($violations, 'Jobs missing #[Override] on handle(): ' . implode(', ', $violations));
    }

    // ── 8. Middleware #[Override] Audit ─────────────────────────────────

    public function test_all_middleware_have_override_on_handle(): void
    {
        $middlewareFiles = array_merge(
            glob(self::PKG_ROOT . '/src/Http/Middleware/*.php'),
            glob(self::PKG_ROOT . '/src/Middleware/*.php'),
        );
        $violations = [];

        foreach ($middlewareFiles as $file) {
            $content = file_get_contents($file);
            if (str_contains($content, 'public function handle(') && !str_contains($content, '#[Override]')) {
                $violations[] = basename($file);
            }
        }

        $this->assertEmpty($violations, 'Middleware missing #[Override] on handle(): ' . implode(', ', $violations));
    }

    // ── 9. TrackerInterface Compliance ─────────────────────────────────

    public function test_tracker_interface_has_required_methods(): void
    {
        $interface = new \ReflectionClass(TrackerInterface::class);

        $required = ['track', 'isEnabled', 'headScripts', 'bodyScripts', 'setConsent', 'getConsent'];
        foreach ($required as $method) {
            $this->assertTrue(
                $interface->hasMethod($method),
                "TrackerInterface missing method: {$method}",
            );
        }
    }

    // ── 10. Event Catalog Integrity ────────────────────────────────────

    public function test_event_catalog_has_eight_categories(): void
    {
        $byCategory = EventCatalog::byCategory();

        $this->assertArrayHasKey('ecommerce', $byCategory);
        $this->assertArrayHasKey('saas', $byCategory);
        $this->assertArrayHasKey('engagement', $byCategory);
        $this->assertArrayHasKey('security', $byCategory);
        $this->assertArrayHasKey('uptime', $byCategory);
        $this->assertArrayHasKey('infrastructure', $byCategory);
        $this->assertArrayHasKey('marketing', $byCategory);
        $this->assertArrayHasKey('customer_success', $byCategory);
        $this->assertCount(8, $byCategory);
    }

    public function test_event_catalog_minimum_count(): void
    {
        $this->assertGreaterThanOrEqual(210, EventCatalog::count());
    }

    public function test_ecommerce_events_has_core(): void
    {
        foreach (['view_item', 'add_to_cart', 'purchase', 'refund'] as $event) {
            $this->assertTrue(EcommerceEvents::has($event), "Missing ecommerce event: {$event}");
        }
    }

    public function test_saas_events_has_core(): void
    {
        foreach (['sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade', 'cancellation'] as $event) {
            $this->assertTrue(SaaSEvents::has($event), "Missing SaaS event: {$event}");
        }
    }

    public function test_engagement_events_has_core(): void
    {
        foreach (['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'search'] as $event) {
            $this->assertTrue(EngagementEvents::has($event), "Missing engagement event: {$event}");
        }
    }

    public function test_security_events_has_core(): void
    {
        $this->assertGreaterThan(0, SecurityEvents::count());
    }

    public function test_uptime_events_has_core(): void
    {
        $this->assertGreaterThan(0, UptimeEvents::count());
    }

    public function test_infrastructure_events_has_core(): void
    {
        $this->assertGreaterThan(0, InfrastructureEvents::count());
    }

    public function test_marketing_events_has_core(): void
    {
        $this->assertGreaterThan(0, MarketingEvents::count());
    }

    public function test_customer_success_events_has_core(): void
    {
        $this->assertGreaterThan(0, CustomerSuccessEvents::count());
    }

    // ── 11. Config Structure ──────────────────────────────────────────

    public function test_config_file_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/config/zeroboiler.php');
    }

    public function test_config_has_analytics_section(): void
    {
        $config = include self::PKG_ROOT . '/config/zeroboiler.php';
        $this->assertIsArray($config);
        $this->assertArrayHasKey('analytics', $config);
    }

    public function test_config_has_provider_sections(): void
    {
        $config = include self::PKG_ROOT . '/config/zeroboiler.php';
        $analytics = $config['analytics'];

        $this->assertArrayHasKey('ga4', $analytics);
        $this->assertArrayHasKey('gtm', $analytics);
        $this->assertArrayHasKey('meta_pixel', $analytics);
    }

    public function test_config_has_consent_section(): void
    {
        $config = include self::PKG_ROOT . '/config/zeroboiler.php';
        $analytics = $config['analytics'];

        $this->assertArrayHasKey('consent', $analytics);
        $this->assertArrayHasKey('default', $analytics['consent']);
    }

    public function test_config_uses_env_variables(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertGreaterThan(100, substr_count($content, 'env('));
    }

    // ── 12. Composer Metadata ──────────────────────────────────────────

    public function test_composer_requires_php_85(): void
    {
        $composer = json_decode(file_get_contents(self::PKG_ROOT . '/composer.json'), true);
        $this->assertArrayHasKey('php', $composer['require']);
        $this->assertStringContainsString('8.5', $composer['require']['php']);
    }

    public function test_composer_has_illuminate_support(): void
    {
        $composer = json_decode(file_get_contents(self::PKG_ROOT . '/composer.json'), true);
        $this->assertArrayHasKey('illuminate/support', $composer['require']);
    }

    public function test_composer_has_keywords(): void
    {
        $composer = json_decode(file_get_contents(self::PKG_ROOT . '/composer.json'), true);
        $this->assertNotEmpty($composer['keywords']);
        $this->assertContains('analytics', $composer['keywords']);
    }

    // ── 13. Tooling Configuration ──────────────────────────────────────

    public function test_phpstan_level_9(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/phpstan.neon');
        $this->assertStringContainsString('->level(9)', $content);
    }

    public function test_rector_targets_php_85(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/rector.php');
        $this->assertStringContainsString('PHP_85', $content);
    }

    // ── 14. Source Directory Structure ──────────────────────────────────

    public function test_source_has_expected_directories(): void
    {
        $expected = [
            'src/DTO',
            'src/Services',
            'src/Events',
            'src/Trackers',
            'src/Contracts',
            'src/Exceptions',
            'src/Facades',
            'src/Console',
            'src/Http',
            'src/Jobs',
            'src/Pipeline',
            'src/Schema',
            'src/Store',
            'src/Support',
            'src/Models',
        ];

        foreach ($expected as $dir) {
            $this->assertDirectoryExists(
                self::PKG_ROOT . '/' . $dir,
                "Missing directory: {$dir}",
            );
        }
    }

    public function test_source_file_count(): void
    {
        $phpFiles = glob(self::PKG_ROOT . '/src/**/*.php');
        $this->assertGreaterThanOrEqual(780, count($phpFiles));
    }

    public function test_test_file_count(): void
    {
        $testFiles = glob(self::PKG_ROOT . '/tests/**/*.php');
        $this->assertGreaterThanOrEqual(380, count($testFiles));
    }

    public function test_services_directory_has_minimum_count(): void
    {
        $services = glob(self::PKG_ROOT . '/src/Services/*.php');
        $this->assertGreaterThanOrEqual(340, count($services));
    }

    public function test_events_directory_has_minimum_count(): void
    {
        $events = glob(self::PKG_ROOT . '/src/Events/**/*.php');
        $this->assertGreaterThanOrEqual(200, count($events));
    }

    public function test_console_commands_minimum_count(): void
    {
        $commands = glob(self::PKG_ROOT . '/src/Console/Commands/*.php');
        $this->assertGreaterThanOrEqual(75, count($commands));
    }

    // ── 15. Cross-Reference Integrity ──────────────────────────────────

    public function test_provider_trackers_all_exist(): void
    {
        $trackers = [
            'GA4Tracker', 'GTMTracker', 'MetaPixelTracker',
            'PlausibleTracker', 'PostHogTracker', 'WebhookTracker',
            'MixpanelTracker', 'AmplitudeTracker', 'TikTokTracker', 'LinkedInTracker',
        ];

        foreach ($trackers as $tracker) {
            $class = "ZeroBoiler\\Analytics\\Trackers\\{$tracker}";
            $this->assertTrue(
                class_exists($class, false) || file_exists(self::PKG_ROOT . "/src/Trackers/{$tracker}.php"),
                "Missing tracker: {$tracker}",
            );
        }
    }

    public function test_event_subcategories_all_exist(): void
    {
        $categories = [
            'Ecommerce', 'Engagement', 'Security', 'Uptime',
            'Infrastructure', 'Marketing', 'SaaS',
        ];

        foreach ($categories as $cat) {
            $this->assertDirectoryExists(
                self::PKG_ROOT . "/src/Events/{$cat}",
                "Missing event category directory: {$cat}",
            );
        }
    }

    // ── 16. Namespace Consistency ──────────────────────────────────────

    public function test_all_source_files_use_zeroboiler_namespace(): void
    {
        $phpFiles = glob(self::PKG_ROOT . '/src/**/*.php');
        $violations = [];

        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            if (!str_contains($content, 'namespace ZeroBoiler\\Analytics')) {
                $violations[] = str_replace(self::PKG_ROOT . '/', '', $file);
            }
        }

        $this->assertEmpty($violations, 'Files with wrong namespace: ' . implode(', ', $violations));
    }

    // ── 17. ServiceProvider #[Override] Audit ────────────────────────────

    public function test_service_provider_has_override_on_register(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/AnalyticsServiceProvider.php');
        $this->assertMatchesRegularExpression(
            '/Override.*public function register\(\)/s',
            $content,
            'ServiceProvider register() must have #[Override]',
        );
    }

    public function test_service_provider_has_override_on_boot(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/AnalyticsServiceProvider.php');
        $this->assertMatchesRegularExpression(
            '/Override.*public function boot\(\)/s',
            $content,
            'ServiceProvider boot() must have #[Override]',
        );
    }

    public function test_service_provider_has_override_on_provides(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/AnalyticsServiceProvider.php');
        $this->assertMatchesRegularExpression(
            '/Override.*public function provides\(\)/s',
            $content,
            'ServiceProvider provides() must have #[Override]',
        );
    }
}
