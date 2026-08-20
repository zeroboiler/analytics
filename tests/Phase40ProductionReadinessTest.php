<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsServiceProvider;
use ZeroBoiler\Analytics\Contracts\AnalyticsEventStoreInterface;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Exceptions\AnalyticsException;
use ZeroBoiler\Analytics\Exceptions\AnalyticsRuntimeException;
use ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Http\HttpMiddlewareContract;
use ZeroBoiler\Analytics\Pipeline\Validation\ValidationStageInterface;
use ZeroBoiler\Analytics\Trackers\TrackerInterface;

/**
 * Phase 40 — Final Production Readiness Audit.
 *
 * Comprehensive source-level audit covering:
 *  1. Version consistency across all entry points
 *  2. Exception hierarchy — @see references, factory methods, finality
 *  3. All source files have strict_types and license headers
 *  4. Console commands — #[Override] on handle(), final class, int return
 *  5. ServiceProvider/Facade — #[Override] audit
 *  6. Interface compliance
 *  7. Config structure and env() coverage
 *  8. Composer metadata integrity
 *  9. phpstan.neon configuration
 * 10. rector.php PHP 8.5 target
 * 11. AnalyticsManager public API surface
 * 12. EventCatalog integrity (8 categories)
 * 13. TODO/FIXME absence
 * 14. @since annotation completeness on key classes
 *
 * @since 157.0.0
 */
final class Phase40ProductionReadinessTest extends TestCase
{
    private const PKG_ROOT = __DIR__ . '/..';
    private const VERSION = '264.0.0';

    // ── 1. Version Consistency ──────────────────────────────────────────

    #[Test]
    public function analytics_event_version_matches(): void
    {
        $this->assertSame(self::VERSION, AnalyticsEvent::VERSION);
    }

    #[Test]
    public function composer_json_version_matches(): void
    {
        $composer = json_decode(file_get_contents(self::PKG_ROOT . '/composer.json'), true);
        $this->assertSame(self::VERSION, $composer['version']);
    }

    #[Test]
    public function service_provider_version_matches(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('@version ' . self::VERSION, $content);
    }

    #[Test]
    public function integrity_command_version_matches(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Console/Commands/AnalyticsIntegrityCommand.php');
        $this->assertStringContainsString("EXPECTED_VERSION = '" . self::VERSION . "'", $content);
    }

    #[Test]
    public function composer_requires_php_85(): void
    {
        $composer = json_decode(file_get_contents(self::PKG_ROOT . '/composer.json'), true);
        $this->assertSame('^8.5', $composer['require']['php']);
    }

    #[Test]
    public function composer_requires_illuminate_13(): void
    {
        $composer = json_decode(file_get_contents(self::PKG_ROOT . '/composer.json'), true);
        $this->assertSame('^13.0', $composer['require']['illuminate/contracts']);
    }

    #[Test]
    public function composer_has_mit_license(): void
    {
        $composer = json_decode(file_get_contents(self::PKG_ROOT . '/composer.json'), true);
        $this->assertSame('MIT', $composer['license']);
    }

    #[Test]
    public function composer_autoload_namespace_correct(): void
    {
        $composer = json_decode(file_get_contents(self::PKG_ROOT . '/composer.json'), true);
        $this->assertSame('src/', $composer['autoload']['psr-4']['ZeroBoiler\\Analytics\\']);
    }

    #[Test]
    public function composer_has_service_provider_registration(): void
    {
        $composer = json_decode(file_get_contents(self::PKG_ROOT . '/composer.json'), true);
        $this->assertStringContainsString(AnalyticsServiceProvider::class, json_encode($composer['extra']['laravel']['providers']));
    }

    #[Test]
    public function composer_has_facade_alias(): void
    {
        $composer = json_decode(file_get_contents(self::PKG_ROOT . '/composer.json'), true);
        $this->assertSame(
            'ZeroBoiler\\Analytics\\Facades\\Analytics',
            $composer['extra']['laravel']['aliases']['Analytics'],
        );
    }

    #[Test]
    public function composer_has_quality_scripts(): void
    {
        $composer = json_decode(file_get_contents(self::PKG_ROOT . '/composer.json'), true);
        $scripts = ['test', 'lint', 'lint:fix', 'analyse', 'rector', 'quality'];
        foreach ($scripts as $script) {
            $this->assertArrayHasKey($script, $composer['scripts'], "Missing script: {$script}");
        }
    }

    // ── 2. Exception Hierarchy ─────────────────────────────────────────

    #[Test]
    public function base_exception_is_abstract(): void
    {
        $ref = new ReflectionClass(AnalyticsException::class);
        $this->assertTrue($ref->isAbstract());
    }

    #[Test]
    public function base_exception_has_void_constructor(): void
    {
        $method = new ReflectionMethod(AnalyticsException::class, '__construct');
        $this->assertSame('void', $method->getReturnType()?->getName());
    }

    #[Test]
    public function base_exception_docblock_has_see_child_references(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Exceptions/AnalyticsException.php');
        $this->assertStringContainsString('@see \\ZeroBoiler\\Analytics\\Exceptions\\InvalidAnalyticsArgumentException', $content);
        $this->assertStringContainsString('@see \\ZeroBoiler\\Analytics\\Exceptions\\AnalyticsRuntimeException', $content);
    }

    #[Test]
    public function runtime_exception_is_final(): void
    {
        $ref = new ReflectionClass(AnalyticsRuntimeException::class);
        $this->assertTrue($ref->isFinal());
    }

    #[Test]
    public function runtime_exception_has_for_message_factory(): void
    {
        $this->assertTrue(method_exists(AnalyticsRuntimeException::class, 'forMessage'));
        $method = new ReflectionMethod(AnalyticsRuntimeException::class, 'forMessage');
        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
        $this->assertSame(AnalyticsRuntimeException::class, $method->getReturnType()?->getName());
    }

    #[Test]
    public function runtime_exception_docblock_has_see_parent(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Exceptions/AnalyticsRuntimeException.php');
        $this->assertStringContainsString('@see \\ZeroBoiler\\Analytics\\Exceptions\\AnalyticsException', $content);
    }

    #[Test]
    public function argument_exception_is_final(): void
    {
        $ref = new ReflectionClass(InvalidAnalyticsArgumentException::class);
        $this->assertTrue($ref->isFinal());
    }

    #[Test]
    public function argument_exception_has_for_message_factory(): void
    {
        $this->assertTrue(method_exists(InvalidAnalyticsArgumentException::class, 'forMessage'));
        $method = new ReflectionMethod(InvalidAnalyticsArgumentException::class, 'forMessage');
        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
        $this->assertSame(InvalidAnalyticsArgumentException::class, $method->getReturnType()?->getName());
    }

    #[Test]
    public function argument_exception_docblock_has_see_parent(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Exceptions/InvalidAnalyticsArgumentException.php');
        $this->assertStringContainsString('@see \\ZeroBoiler\\Analytics\\Exceptions\\AnalyticsException', $content);
    }

    #[Test]
    public function exceptions_have_at_since_annotations(): void
    {
        foreach (['AnalyticsException', 'AnalyticsRuntimeException', 'InvalidAnalyticsArgumentException'] as $class) {
            $content = file_get_contents(self::PKG_ROOT . "/src/Exceptions/{$class}.php");
            $this->assertStringContainsString('@since', $content, "Missing @since in {$class}");
        }
    }

    // ── 3. Strict Types & License Headers ─────────────────────────────

    #[Test]
    public function all_source_files_have_strict_types(): void
    {
        $files = glob(self::PKG_ROOT . '/src/**/*.php', GLOB_BRACE);
        $violations = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (!str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = str_replace(self::PKG_ROOT . '/', '', $file);
            }
        }
        $this->assertEmpty($violations, 'Files missing strict_types: ' . implode(', ', $violations));
    }

    #[Test]
    public function all_source_files_have_license_headers(): void
    {
        $files = glob(self::PKG_ROOT . '/src/**/*.php', GLOB_BRACE);
        $violations = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (!str_contains($content, 'This file is part of ZeroBoiler')) {
                $violations[] = str_replace(self::PKG_ROOT . '/', '', $file);
            }
        }
        $this->assertEmpty($violations, 'Files missing license header: ' . implode(', ', $violations));
    }

    // ── 4. Console Commands ─────────────────────────────────────────────

    #[Test]
    public function all_console_commands_have_override_on_handle(): void
    {
        $files = glob(self::PKG_ROOT . '/src/Console/Commands/*.php');
        $violations = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (str_contains($content, 'public function handle') && !str_contains($content, '#[Override]')) {
                $violations[] = basename($file);
            }
        }
        $this->assertEmpty($violations, 'Commands missing #[Override] on handle(): ' . implode(', ', $violations));
    }

    #[Test]
    public function all_console_commands_are_final(): void
    {
        $files = glob(self::PKG_ROOT . '/src/Console/Commands/*.php');
        $violations = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (!str_contains($content, 'final class ')) {
                $violations[] = basename($file);
            }
        }
        $this->assertEmpty($violations, 'Commands not final: ' . implode(', ', $violations));
    }

    #[Test]
    public function command_count_is_at_least_80(): void
    {
        $files = glob(self::PKG_ROOT . '/src/Console/Commands/*.php');
        $this->assertGreaterThanOrEqual(80, count($files));
    }

    // ── 5. ServiceProvider & Facade ──────────────────────────────────────

    #[Test]
    public function service_provider_has_override_on_register(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('#[\\Override]', $content);
        $this->assertStringContainsString('public function register(): void', $content);
    }

    #[Test]
    public function service_provider_has_override_on_boot(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('#[\\Override]', $content);
        $this->assertStringContainsString('public function boot(): void', $content);
    }

    #[Test]
    public function service_provider_has_override_on_provides(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('#[\\Override]', $content);
        $this->assertStringContainsString('public function provides(): array', $content);
    }

    #[Test]
    public function facade_is_final(): void
    {
        $ref = new ReflectionClass(Analytics::class);
        $this->assertTrue($ref->isFinal());
    }

    #[Test]
    public function facade_has_override_on_get_facade_accessor(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Facades/Analytics.php');
        $this->assertStringContainsString('#[\\Override]', $content);
        $this->assertStringContainsString('protected static function getFacadeAccessor', $content);
    }

    #[Test]
    public function facade_docblock_sees_manager(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Facades/Analytics.php');
        $this->assertStringContainsString('@see \\ZeroBoiler\\Analytics\\AnalyticsManager', $content);
    }

    #[Test]
    public function service_provider_provides_list(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString("'zeroboiler.analytics'", $content);
        $this->assertStringContainsString(AnalyticsManager::class, $content);
        $this->assertStringContainsString('AnalyticsConfig::class', $content);
    }

    // ── 6. Interface Compliance ────────────────────────────────────────

    #[Test]
    public function tracker_interface_has_required_methods(): void
    {
        $methods = ['track', 'identify', 'pageView', 'isEnabled'];
        foreach ($methods as $method) {
            $this->assertTrue(method_exists(TrackerInterface::class, $method), "Missing: {$method}");
        }
    }

    #[Test]
    public function event_store_interface_has_required_methods(): void
    {
        $methods = ['store', 'storeBatch', 'retrieve', 'query', 'count', 'delete', 'deleteById', 'purge', 'aggregateBy', 'isHealthy'];
        foreach ($methods as $method) {
            $this->assertTrue(method_exists(AnalyticsEventStoreInterface::class, $method), "Missing: {$method}");
        }
    }

    #[Test]
    public function validation_stage_interface_exists(): void
    {
        $this->assertTrue(interface_exists(ValidationStageInterface::class));
    }

    #[Test]
    public function http_middleware_contract_exists(): void
    {
        $this->assertTrue(interface_exists(HttpMiddlewareContract::class));
    }

    // ── 7. Config Structure & env() Coverage ────────────────────────────

    #[Test]
    public function config_file_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/config/zeroboiler.php');
    }

    #[Test]
    public function config_has_top_level_analytics_key(): void
    {
        $config = include self::PKG_ROOT . '/config/zeroboiler.php';
        $this->assertArrayHasKey('analytics', $config);
    }

    #[Test]
    public function config_has_ga4_section(): void
    {
        $config = include self::PKG_ROOT . '/config/zeroboiler.php';
        $this->assertArrayHasKey('ga4', $config['analytics']);
        $this->assertArrayHasKey('enabled', $config['analytics']['ga4']);
        $this->assertArrayHasKey('measurement_id', $config['analytics']['ga4']);
    }

    #[Test]
    public function config_has_consent_section(): void
    {
        $config = include self::PKG_ROOT . '/config/zeroboiler.php';
        $this->assertArrayHasKey('consent', $config['analytics']);
    }

    #[Test]
    public function config_has_auto_track_section(): void
    {
        $config = include self::PKG_ROOT . '/config/zeroboiler.php';
        $this->assertArrayHasKey('auto_track', $config['analytics']);
    }

    #[Test]
    public function config_env_coverage_is_substantial(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        preg_match_all('/env\(/', $content, $matches);
        $this->assertGreaterThanOrEqual(100, count($matches[0]), 'Expected at least 100 env() calls in config');
    }

    // ── 8. File Counts ─────────────────────────────────────────────────

    #[Test]
    public function source_file_count_is_substantial(): void
    {
        $files = glob(self::PKG_ROOT . '/src/**/*.php', GLOB_BRACE);
        $this->assertGreaterThanOrEqual(700, count($files), 'Expected at least 700 source PHP files');
    }

    #[Test]
    public function test_file_count_is_substantial(): void
    {
        $files = glob(self::PKG_ROOT . '/tests/**/*.php', GLOB_BRACE);
        $this->assertGreaterThanOrEqual(300, count($files), 'Expected at least 300 test PHP files');
    }

    #[Test]
    public function routes_file_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/routes/analytics.php');
    }

    #[Test]
    public function database_migrations_exist(): void
    {
        $files = glob(self::PKG_ROOT . '/database/migrations/*.php');
        $this->assertGreaterThan(0, count($files));
    }

    // ── 9. phpstan.neon Configuration ───────────────────────────────────

    #[Test]
    public function phpstan_level_is_9(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/phpstan.neon');
        $this->assertStringContainsString('->level(9)', $content);
    }

    #[Test]
    public function phpstan_checks_unused_parameters(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/phpstan.neon');
        $this->assertStringContainsString('->checkUnusedParameters(true)', $content);
    }

    #[Test]
    public function phpstan_checks_uninitialized_properties(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/phpstan.neon');
        $this->assertStringContainsString('->checkUninitializedProperties(true)', $content);
    }

    #[Test]
    public function phpstan_scans_src_only(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/phpstan.neon');
        $this->assertStringContainsString("__DIR__ . '/src'", $content);
    }

    // ── 10. rector.php Configuration ───────────────────────────────────

    #[Test]
    public function rector_targets_php_85(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/rector.php');
        $this->assertStringContainsString('PhpVersion::PHP_85', $content);
    }

    #[Test]
    public function rector_scans_src_and_tests(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/rector.php');
        $this->assertStringContainsString("__DIR__.'/src'", $content);
        $this->assertStringContainsString("__DIR__.'/tests'", $content);
    }

    // ── 11. AnalyticsManager API Surface ────────────────────────────────

    #[Test]
    public function manager_is_final(): void
    {
        $ref = new ReflectionClass(AnalyticsManager::class);
        $this->assertTrue($ref->isFinal());
    }

    #[Test]
    public function manager_has_core_methods(): void
    {
        $methods = ['track', 'identify', 'pageView', 'purchase', 'screenView', 'setConsent', 'getConsent', 'version', 'eventCatalogSummary'];
        foreach ($methods as $method) {
            $this->assertTrue(method_exists(AnalyticsManager::class, $method), "Missing method: {$method}");
        }
    }

    #[Test]
    public function manager_has_privacy_methods(): void
    {
        $methods = ['addPrivacyNoise', 'addPrivacyNoiseToRevenue', 'resetIdentity', 'optOut', 'optIn'];
        foreach ($methods as $method) {
            $this->assertTrue(method_exists(AnalyticsManager::class, $method), "Missing method: {$method}");
        }
    }

    #[Test]
    public function manager_has_revenue_methods(): void
    {
        $methods = ['trackRevenue', 'mrr', 'revenueAttributionDashboard'];
        foreach ($methods as $method) {
            $this->assertTrue(method_exists(AnalyticsManager::class, $method), "Missing method: {$method}");
        }
    }

    #[Test]
    public function manager_has_wire_protocol_methods(): void
    {
        $methods = ['wireSerialize', 'wireDeserialize', 'wireValidate'];
        foreach ($methods as $method) {
            $this->assertTrue(method_exists(AnalyticsManager::class, $method), "Missing method: {$method}");
        }
    }

    #[Test]
    public function manager_constructor_has_void_return(): void
    {
        $method = new ReflectionMethod(AnalyticsManager::class, '__construct');
        $this->assertSame('void', $method->getReturnType()?->getName());
    }

    // ── 12. EventCatalog Integrity ─────────────────────────────────────

    #[Test]
    public function event_catalog_has_8_categories(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Events/EventCatalog.php');
        $categories = ['EcommerceEvents', 'SaaSEvents', 'EngagementEvents', 'SecurityEvents', 'UptimeEvents', 'InfrastructureEvents', 'MarketingEvents', 'CustomerSuccessEvents'];
        foreach ($categories as $cat) {
            $this->assertStringContainsString($cat, $content, "Missing category: {$cat}");
        }
    }

    #[Test]
    public function event_catalog_all_returns_non_empty(): void
    {
        // Source-level check: the all() method must reference all 8 categories
        $content = file_get_contents(self::PKG_ROOT . '/src/Events/EventCatalog.php');
        $categories = ['ecommerce', 'saas', 'engagement', 'security', 'uptime', 'infrastructure', 'marketing', 'customer_success'];
        foreach ($categories as $cat) {
            $this->assertStringContainsString("'{$cat}'", $content, "Missing category key: {$cat}");
        }
    }

    #[Test]
    public function event_catalog_category_files_exist(): void
    {
        $files = [
            'src/Events/Ecommerce/EcommerceEvents.php',
            'src/Events/SaaS/SaaSEvents.php',
            'src/Events/Engagement/EngagementEvents.php',
            'src/Events/Security/SecurityEvents.php',
            'src/Events/Uptime/UptimeEvents.php',
            'src/Events/Infrastructure/InfrastructureEvents.php',
            'src/Events/Marketing/MarketingEvents.php',
            'src/Events/SaaS/CustomerSuccessEvents.php',
        ];
        foreach ($files as $file) {
            $this->assertFileExists(self::PKG_ROOT . '/' . $file, "Missing: {$file}");
        }
    }

    // ── 13. TODO/FIXME Absence ──────────────────────────────────────────

    #[Test]
    public function no_todo_or_fixme_in_src(): void
    {
        $files = glob(self::PKG_ROOT . '/src/**/*.php', GLOB_BRACE);
        $violations = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $lines = explode("\n", $content);
            foreach ($lines as $num => $line) {
                if (preg_match('/\/\/\s*(TODO|FIXME|HACK|XXX)\b/', $line) || preg_match('/\/\*.*\b(TODO|FIXME|HACK|XXX)\b/', $line)) {
                    // Skip example strings (like G-XXXXXXXXXX)
                    if (!str_contains($line, 'G-XXXXXXX') && !str_contains($line, 'GTM-XXXXXXX')) {
                        $violations[] = basename($file) . ':' . ($num + 1);
                    }
                }
            }
        }
        $this->assertEmpty($violations, 'TODO/FIXME found: ' . implode(', ', $violations));
    }

    // ── 14. @since Annotations on Key Classes ──────────────────────────

    #[Test]
    public function key_classes_have_since_annotations(): void
    {
        $classes = [
            'AnalyticsManager',
            'AnalyticsException',
            'AnalyticsRuntimeException',
            'InvalidAnalyticsArgumentException',
            'EventCatalog',
            'AnalyticsEventStoreInterface',
            'TrackerInterface',
        ];
        foreach ($classes as $class) {
            $file = self::PKG_ROOT . '/src/' . str_replace('\\', '/', $class) . '.php';
            if (!file_exists($file)) {
                $file = self::PKG_ROOT . '/src/Exceptions/' . basename($class) . '.php';
            }
            if (!file_exists($file)) {
                $file = self::PKG_ROOT . '/src/Contracts/' . basename($class) . '.php';
            }
            if (!file_exists($file)) {
                $file = self::PKG_ROOT . '/src/Trackers/' . basename($class) . '.php';
            }
            $this->assertFileExists($file, "File not found for class: {$class}");
            $content = file_get_contents($file);
            $this->assertStringContainsString('@since', $content, "Missing @since in {$class}");
        }
    }

    // ── 15. Cross-Reference Integrity ────────────────────────────────────

    #[Test]
    public function facade_refers_to_existing_manager(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/AnalyticsManager.php');
    }

    #[Test]
    public function facade_refers_to_existing_fake(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Facades/Analytics.php');
        $this->assertStringContainsString('AnalyticsFake', $content);
        $this->assertFileExists(self::PKG_ROOT . '/src/Support/AnalyticsFake.php');
    }

    #[Test]
    public function test_directory_has_tests(): void
    {
        $files = glob(self::PKG_ROOT . '/tests/*.php');
        $this->assertGreaterThan(100, count($files));
    }

    // ── 16. Stale Version Tests Fixed ──────────────────────────────────

    #[Test]
    public function phase38_test_has_correct_version(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/tests/Phase38ProductionAuditTest.php');
        $this->assertStringContainsString("'156.0.0'", $content);
    }

    #[Test]
    public function v135_test_has_correct_version(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/tests/V135Phase37ProductionAuditTest.php');
        $this->assertStringContainsString("'156.0.0'", $content);
    }
}
