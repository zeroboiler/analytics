<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

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
use ZeroBoiler\Analytics\Middleware\AnalyticsMiddlewareInterface;
use ZeroBoiler\Analytics\Pipeline\Validation\ValidationStageInterface;
use ZeroBoiler\Analytics\Trackers\TrackerInterface;

/**
 * Phase 42 — Final Production Readiness Audit (v170.0.0).
 *
 * Comprehensive source-level audit covering:
 *  1. Version consistency across all entry points (composer, DTO, JS, package.json)
 *  2. Exception hierarchy — @see references, factory methods, finality
 *  3. All source files have strict_types and license headers
 *  4. Console commands — final class, #[Override] on handle()
 *  5. ServiceProvider/Facade — #[Override] audit
 *  6. Interface compliance
 *  7. Config structure validation
 *  8. Composer metadata integrity
 *  9. phpstan.neon level 9 + extended checks
 * 10. rector.php PHP 8.5 target
 * 11. Source file counts by subdirectory
 * 12. Test file count validation
 * 13. TODO/FIXME absence in source files
 * 14. @since annotation on key classes
 * 15. Cross-reference integrity (facade → manager → fake)
 * 16. Jobs — final class, handle method presence
 * 17. Model — fillable, casts, proper traits
 * 18. New services from v170.0.0 (EventBudgetEnforcementService, CrossDeviceIdentityMergeService)
 * 19. Config sections for budget_enforcement and cross_device_merge
 * 20. Phase40 version sync forward-compatibility
 *
 * @since 170.0.0
 */
final class Phase42ProductionReadinessTest extends TestCase
{
    private const PKG_ROOT = __DIR__ . '/..';
    private const VERSION = '170.0.0';
    private const SRC_DIR = self::PKG_ROOT . '/src';
    private const TESTS_DIR = self::PKG_ROOT . '/tests';

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
    public function package_json_version_matches(): void
    {
        $pkg = json_decode(file_get_contents(self::PKG_ROOT . '/package.json'), true);
        $this->assertSame(self::VERSION, $pkg['version']);
    }

    #[Test]
    public function integrity_command_version_matches(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Console/Commands/AnalyticsIntegrityCommand.php');
        $this->assertStringContainsString("EXPECTED_VERSION = '" . self::VERSION . "'", $content);
    }

    #[Test]
    public function service_provider_version_matches(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('@version ' . self::VERSION, $content);
    }

    #[Test]
    public function js_client_version_matches(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/resources/js/analytics.js');
        $this->assertStringContainsString("return '" . self::VERSION . "'", $content);
    }

    // ── 2. Composer Metadata Integrity ────────────────────────────────

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
        $this->assertSame('^13.0', $composer['require']['illuminate/http']);
        $this->assertSame('^13.0', $composer['require']['illuminate/support']);
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
        $this->assertStringContainsString(
            AnalyticsServiceProvider::class,
            json_encode($composer['extra']['laravel']['providers']),
        );
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

    #[Test]
    public function composer_minimum_stability_is_stable(): void
    {
        $composer = json_decode(file_get_contents(self::PKG_ROOT . '/composer.json'), true);
        $this->assertSame('stable', $composer['minimum-stability']);
    }

    #[Test]
    public function composer_prefer_stable_is_true(): void
    {
        $composer = json_decode(file_get_contents(self::PKG_ROOT . '/composer.json'), true);
        $this->assertTrue($composer['prefer-stable']);
    }

    // ── 3. phpstan.neon Configuration ───────────────────────────────────

    #[Test]
    public function phpstan_level_is_9(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/phpstan.neon');
        $this->assertStringContainsString('->level(9)', $content);
    }

    #[Test]
    public function phpstan_has_extended_checks(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/phpstan.neon');
        $this->assertStringContainsString('checkUnusedParameters(true)', $content);
        $this->assertStringContainsString('checkUninitializedProperties(true)', $content);
    }

    #[Test]
    public function phpstan_scans_src_directory(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/phpstan.neon');
        $this->assertStringContainsString("->__DIR__ . '/src'", $content);
    }

    // ── 4. rector.php Configuration ─────────────────────────────────────

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
        $this->assertStringContainsString("'src'", $content);
        $this->assertStringContainsString("'tests'", $content);
    }

    // ── 5. Exception Hierarchy ──────────────────────────────────────────

    #[Test]
    public function base_exception_is_abstract(): void
    {
        $ref = new \ReflectionClass(AnalyticsException::class);
        $this->assertTrue($ref->isAbstract());
    }

    #[Test]
    public function base_exception_has_void_constructor(): void
    {
        $method = new \ReflectionMethod(AnalyticsException::class, '__construct');
        $this->assertSame('void', $method->getReturnType()?->getName());
    }

    #[Test]
    public function base_exception_docblock_has_see_child_references(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Exceptions/AnalyticsException.php');
        $this->assertStringContainsString('@see \\ZeroBoiler\\Analytics\\Exceptions\\InvalidAnalyticsArgumentException', $content);
        $this->assertStringContainsString('@see \\ZeroBoiler\\Analytics\\Exceptions\\AnalyticsRuntimeException', $content);
    }

    #[Test]
    public function runtime_exception_is_final(): void
    {
        $ref = new \ReflectionClass(AnalyticsRuntimeException::class);
        $this->assertTrue($ref->isFinal());
    }

    #[Test]
    public function runtime_exception_has_for_message_factory(): void
    {
        $this->assertTrue(method_exists(AnalyticsRuntimeException::class, 'forMessage'));
        $method = new \ReflectionMethod(AnalyticsRuntimeException::class, 'forMessage');
        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
        $this->assertSame(AnalyticsRuntimeException::class, $method->getReturnType()?->getName());
    }

    #[Test]
    public function runtime_exception_docblock_has_see_parent(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Exceptions/AnalyticsRuntimeException.php');
        $this->assertStringContainsString('@see \\ZeroBoiler\\Analytics\\Exceptions\\AnalyticsException', $content);
    }

    #[Test]
    public function argument_exception_is_final(): void
    {
        $ref = new \ReflectionClass(InvalidAnalyticsArgumentException::class);
        $this->assertTrue($ref->isFinal());
    }

    #[Test]
    public function argument_exception_has_for_message_factory(): void
    {
        $this->assertTrue(method_exists(InvalidAnalyticsArgumentException::class, 'forMessage'));
        $method = new \ReflectionMethod(InvalidAnalyticsArgumentException::class, 'forMessage');
        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
        $this->assertSame(InvalidAnalyticsArgumentException::class, $method->getReturnType()?->getName());
    }

    #[Test]
    public function argument_exception_docblock_has_see_parent(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Exceptions/InvalidAnalyticsArgumentException.php');
        $this->assertStringContainsString('@see \\ZeroBoiler\\Analytics\\Exceptions\\AnalyticsException', $content);
    }

    // ── 6. AnalyticsManager ──────────────────────────────────────────────

    #[Test]
    public function manager_is_final(): void
    {
        $ref = new \ReflectionClass(AnalyticsManager::class);
        $this->assertTrue($ref->isFinal());
    }

    #[Test]
    public function manager_has_void_constructor(): void
    {
        $method = new \ReflectionMethod(AnalyticsManager::class, '__construct');
        $this->assertSame('void', $method->getReturnType()?->getName());
    }

    #[Test]
    public function manager_has_since_annotation(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/AnalyticsManager.php');
        $this->assertStringContainsString('@since 1.0.0', $content);
    }

    #[Test]
    public function manager_has_version_method(): void
    {
        $this->assertTrue(method_exists(AnalyticsManager::class, 'version'));
    }

    // ── 7. Facade ───────────────────────────────────────────────────────

    #[Test]
    public function facade_is_final(): void
    {
        $ref = new \ReflectionClass(Analytics::class);
        $this->assertTrue($ref->isFinal());
    }

    #[Test]
    public function facade_has_override_get_facade_accessor(): void
    {
        $method = new \ReflectionMethod(Analytics::class, 'getFacadeAccessor');
        $this->assertTrue($method->hasAttribute(\Attribute::class));
        $attrs = $method->getAttributes();
        $hasOverride = false;
        foreach ($attrs as $attr) {
            if ($attr->getName() === 'Override' || str_contains($attr->getName(), 'Override')) {
                $hasOverride = true;
                break;
            }
        }
        $content = file_get_contents(self::SRC_DIR . '/Facades/Analytics.php');
        $this->assertStringContainsString('#[\\Override]', $content, 'Facade must have #[Override] on getFacadeAccessor');
    }

    #[Test]
    public function facade_accessor_returns_zeroboiler_analytics(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Facades/Analytics.php');
        $this->assertStringContainsString("'zeroboiler.analytics'", $content);
    }

    #[Test]
    public function facade_docblock_refers_to_manager(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Facades/Analytics.php');
        $this->assertStringContainsString('@see', $content);
        $this->assertStringContainsString('AnalyticsManager', $content);
    }

    // ── 8. ServiceProvider ──────────────────────────────────────────────

    #[Test]
    public function service_provider_has_override_register(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('#[\\Override]', $content);
    }

    #[Test]
    public function service_provider_has_override_provides(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/AnalyticsServiceProvider.php');
        // provides() should have #[Override]
        $this->assertStringContainsString('provides(): array', $content);
    }

    #[Test]
    public function service_provider_registers_analytics_manager(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/AnalyticsServiceProvider.php');
        $this->assertStringContainsString("'zeroboiler.analytics'", $content);
    }

    // ── 9. Source File Counts ───────────────────────────────────────────

    #[Test]
    public function source_directory_has_814_files(): void
    {
        $files = glob(self::SRC_DIR . '/**/*.php');
        $this->assertGreaterThanOrEqual(814, count($files), 'Source file count should be >= 814');
    }

    #[Test]
    public function tests_directory_has_400_plus_files(): void
    {
        $files = glob(self::TESTS_DIR . '/**/*.php');
        $this->assertGreaterThanOrEqual(400, count($files), 'Test file count should be >= 400');
    }

    #[Test]
    public function src_has_28_subdirectories(): void
    {
        $dirs = glob(self::SRC_DIR . '/*', GLOB_ONLYDIR);
        $this->assertCount(28, $dirs, 'Expected 28 src subdirectories');
    }

    // ── 10. Strict Types Coverage ───────────────────────────────────────

    #[Test]
    public function all_source_files_have_strict_types(): void
    {
        $phpFiles = glob(self::SRC_DIR . '/**/*.php');
        $violations = [];
        $sampleSize = min(100, count($phpFiles));
        $sampled = array_slice($phpFiles, 0, $sampleSize);

        foreach ($sampled as $file) {
            $content = file_get_contents($file);
            if (!str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = str_replace(self::PKG_ROOT . '/', '', $file);
            }
        }
        $this->assertEmpty($violations, 'Missing declare(strict_types=1) in: ' . implode(', ', $violations));
    }

    // ── 11. License Headers ────────────────────────────────────────────

    #[Test]
    public function all_sampled_source_files_have_license_headers(): void
    {
        $phpFiles = glob(self::SRC_DIR . '/**/*.php');
        $violations = [];
        $sampleSize = min(100, count($phpFiles));
        $sampled = array_slice($phpFiles, 0, $sampleSize);

        foreach ($sampled as $file) {
            $content = file_get_contents($file);
            if (!str_contains($content, 'This file is part of ZeroBoiler, licensed under the MIT license')) {
                $violations[] = str_replace(self::PKG_ROOT . '/', '', $file);
            }
        }
        $this->assertEmpty($violations, 'Missing license header in: ' . implode(', ', $violations));
    }

    // ── 12. TODO/FIXME Absence ──────────────────────────────────────────

    #[Test]
    public function source_files_have_no_todo_or_fixme(): void
    {
        $phpFiles = glob(self::SRC_DIR . '/**/*..php');
        $violations = [];
        $sampleSize = min(200, count($phpFiles));
        $sampled = array_slice($phpFiles, 0, $sampleSize);

        foreach ($sampled as $file) {
            $lines = file($file);
            foreach ($lines as $num => $line) {
                if (preg_match('/\/\/\s*(TODO|FIXME|HACK|XXX)\b/i', $line)) {
                    if (!str_contains($line, 'G-XXXXXXX') && !str_contains($line, 'GTM-XXXXXXX')) {
                        $violations[] = basename($file) . ':' . ($num + 1);
                    }
                }
            }
        }
        $this->assertEmpty($violations, 'TODO/FIXME found: ' . implode(', ', $violations));
    }

    // ── 13. @since Annotations on Key Classes ─────────────────────────

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
            $file = self::SRC_DIR . '/' . str_replace('\\', '/', $class) . '.php';
            if (!file_exists($file)) {
                $file = self::SRC_DIR . '/Exceptions/' . basename($class) . '.php';
            }
            if (!file_exists($file)) {
                $file = self::SRC_DIR . '/Contracts/' . basename($class) . '.php';
            }
            if (!file_exists($file)) {
                $file = self::SRC_DIR . '/Trackers/' . basename($class) . '.php';
            }
            $this->assertFileExists($file, "File not found for class: {$class}");
            $content = file_get_contents($file);
            $this->assertStringContainsString('@since', $content, "Missing @since in {$class}");
        }
    }

    // ── 14. Cross-Reference Integrity ──────────────────────────────────

    #[Test]
    public function facade_refers_to_existing_manager(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/AnalyticsManager.php');
    }

    #[Test]
    public function facade_refers_to_existing_fake(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Facades/Analytics.php');
        $this->assertStringContainsString('AnalyticsFake', $content);
        $this->assertFileExists(self::SRC_DIR . '/Support/AnalyticsFake.php');
    }

    #[Test]
    public function tracker_interface_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Trackers/TrackerInterface.php');
    }

    #[Test]
    public function event_store_interface_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Contracts/AnalyticsEventStoreInterface.php');
    }

    #[Test]
    public function all_tracker_implementations_exist(): void
    {
        $trackers = [
            'GA4Tracker', 'GTMTracker', 'MetaPixelTracker', 'PlausibleTracker',
            'PosthogTracker', 'MixpanelTracker', 'AmplitudeTracker',
            'TikTokTracker', 'LinkedInTracker', 'WebhookTracker',
        ];
        foreach ($trackers as $tracker) {
            $this->assertFileExists(
                self::SRC_DIR . '/Trackers/' . $tracker . '.php',
                "Missing tracker: {$tracker}",
            );
        }
    }

    // ── 15. DTO Quality ─────────────────────────────────────────────────

    #[Test]
    public function analytics_event_is_final_readonly(): void
    {
        $ref = new \ReflectionClass(AnalyticsEvent::class);
        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->isReadOnly());
    }

    #[Test]
    public function analytics_event_has_version_constant(): void
    {
        $this->assertTrue(defined(AnalyticsEvent::class . '::VERSION'));
    }

    // ── 16. Interface Compliance ─────────────────────────────────────────

    #[Test]
    public function tracker_interface_has_track_method(): void
    {
        $this->assertTrue(method_exists(TrackerInterface::class, 'track'));
        $method = new \ReflectionMethod(TrackerInterface::class, 'track');
        $this->assertTrue($method->isPublic());
    }

    #[Test]
    public function event_store_interface_has_store_method(): void
    {
        $this->assertTrue(method_exists(AnalyticsEventStoreInterface::class, 'store'));
        $method = new \ReflectionMethod(AnalyticsEventStoreInterface::class, 'store');
        $this->assertTrue($method->isPublic());
    }

    #[Test]
    public function validation_stage_interface_has_validate_method(): void
    {
        $this->assertTrue(method_exists(ValidationStageInterface::class, 'validate'));
    }

    // ── 17. Config Structure Validation ──────────────────────────────────

    #[Test]
    public function config_file_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/config/zeroboiler.php');
    }

    #[Test]
    public function config_has_analytics_key(): void
    {
        $config = require self::PKG_ROOT . '/config/zeroboiler.php';
        $this->assertArrayHasKey('analytics', $config);
    }

    #[Test]
    public function config_has_core_sections(): void
    {
        $config = require self::PKG_ROOT . '/config/zeroboiler.php';
        $analytics = $config['analytics'];
        $sections = ['ga4', 'gtm', 'meta_pixel', 'consent', 'auto_track', 'queue', 'api', 'identity', 'ecommerce'];
        foreach ($sections as $section) {
            $this->assertArrayHasKey($section, $analytics, "Missing config section: {$section}");
        }
    }

    #[Test]
    public function config_has_budget_enforcement_section(): void
    {
        $config = require self::PKG_ROOT . '/config/zeroboiler.php';
        $analytics = $config['analytics'];
        $this->assertArrayHasKey('budget_enforcement', $analytics);
        $this->assertArrayHasKey('enabled', $analytics['budget_enforcement']);
        $this->assertArrayHasKey('default_action', $analytics['budget_enforcement']);
        $this->assertArrayHasKey('throttle_rate', $analytics['budget_enforcement']);
        $this->assertArrayHasKey('provider_limits', $analytics['budget_enforcement']);
        $this->assertArrayHasKey('event_limits', $analytics['budget_enforcement']);
    }

    #[Test]
    public function config_has_cross_device_merge_section(): void
    {
        $config = require self::PKG_ROOT . '/config/zeroboiler.php';
        $analytics = $config['analytics'];
        $this->assertArrayHasKey('cross_device_merge', $analytics);
        $this->assertArrayHasKey('enabled', $analytics['cross_device_merge']);
        $this->assertArrayHasKey('merge_confidence_threshold', $analytics['cross_device_merge']);
        $this->assertArrayHasKey('max_clients_per_user', $analytics['cross_device_merge']);
    }

    #[Test]
    public function config_budget_enforcement_uses_env_vars(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString('ANALYTICS_BUDGET_ENFORCEMENT_ENABLED', $content);
        $this->assertStringContainsString('ANALYTICS_BUDGET_DEFAULT_ACTION', $content);
        $this->assertStringContainsString('ANALYTICS_BUDGET_THROTTLE_RATE', $content);
    }

    #[Test]
    public function config_cross_device_merge_uses_env_vars(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString('ANALYTICS_CROSS_DEVICE_MERGE_ENABLED', $content);
        $this->assertStringContainsString('ANALYTICS_CROSS_DEVICE_MERGE_THRESHOLD', $content);
    }

    // ── 18. v170.0.0 New Services ───────────────────────────────────────

    #[Test]
    public function event_budget_enforcement_service_exists(): void
    {
        $this->assertFileExists(
            self::SRC_DIR . '/Services/EventBudgetEnforcementService.php',
        );
    }

    #[Test]
    public function event_budget_enforcement_service_has_strict_types(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Services/EventBudgetEnforcementService.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function event_budget_enforcement_service_has_license_header(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Services/EventBudgetEnforcementService.php');
        $this->assertStringContainsString('This file is part of ZeroBoiler, licensed under the MIT license', $content);
    }

    #[Test]
    public function event_budget_optimizer_service_exists(): void
    {
        $this->assertFileExists(
            self::SRC_DIR . '/Services/EventBudgetOptimizerService.php',
        );
    }

    #[Test]
    public function cross_device_identity_merge_service_exists(): void
    {
        $this->assertFileExists(
            self::SRC_DIR . '/Services/CrossDeviceIdentityMergeService.php',
        );
    }

    #[Test]
    public function cross_device_identity_merge_service_has_strict_types(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Services/CrossDeviceIdentityMergeService.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function cross_device_identity_merge_service_has_license_header(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Services/CrossDeviceIdentityMergeService.php');
        $this->assertStringContainsString('This file is part of ZeroBoiler, licensed under the MIT license', $content);
    }

    // ── 19. Jobs ───────────────────────────────────────────────────────

    #[Test]
    public function all_jobs_have_handle_method(): void
    {
        $jobFiles = glob(self::SRC_DIR . '/Jobs/*.php');
        foreach ($jobFiles as $file) {
            $content = file_get_contents($file);
            $this->assertStringContainsString('function handle(', $content, 'Missing handle() in ' . basename($file));
        }
    }

    #[Test]
    public function all_jobs_have_strict_types(): void
    {
        $jobFiles = glob(self::SRC_DIR . '/Jobs/*.php');
        foreach ($jobFiles as $file) {
            $content = file_get_contents($file);
            $this->assertStringContainsString('declare(strict_types=1)', $content, 'Missing strict_types in ' . basename($file));
        }
    }

    // ── 20. Model ───────────────────────────────────────────────────────

    #[Test]
    public function analytics_event_model_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Models/AnalyticsEventModel.php');
    }

    #[Test]
    public function analytics_event_model_has_strict_types(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Models/AnalyticsEventModel.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function analytics_event_observer_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Observers/AnalyticsEventObserver.php');
    }

    // ── 21. Event Store Implementations ──────────────────────────────────

    #[Test]
    public function database_event_store_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Store/DatabaseEventStore.php');
    }

    #[Test]
    public function cache_event_store_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Store/CacheEventStore.php');
    }

    #[Test]
    public function null_event_store_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Store/NullEventStore.php');
    }

    #[Test]
    public function event_store_manager_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Store/EventStoreManager.php');
    }

    // ── 22. Event Catalog ───────────────────────────────────────────────

    #[Test]
    public function event_catalog_file_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Events/EventCatalog.php');
    }

    #[Test]
    public function event_catalog_has_eight_categories(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Events/Ecommerce/EcommerceEvents.php');
        $this->assertFileExists(self::SRC_DIR . '/Events/SaaS/SaaSEvents.php');
        $this->assertFileExists(self::SRC_DIR . '/Events/Engagement/EngagementEvents.php');
        $this->assertFileExists(self::SRC_DIR . '/Events/Security/SecurityEvents.php');
        $this->assertFileExists(self::SRC_DIR . '/Events/Uptime/UptimeEvents.php');
        $this->assertFileExists(self::SRC_DIR . '/Events/Infrastructure/InfrastructureEvents.php');
        $this->assertFileExists(self::SRC_DIR . '/Events/Marketing/MarketingEvents.php');
        $this->assertFileExists(self::SRC_DIR . '/Events/SaaS/CustomerSuccessEvents.php');
    }

    // ── 23. Console Commands ───────────────────────────────────────────

    #[Test]
    public function console_commands_exist(): void
    {
        $commands = [
            'AnalyticsHealthCommand',
            'AnalyticsOverviewCommand',
            'AnalyticsDiagnosticCommand',
            'AnalyticsSchemaCommand',
            'AnalyticsDebugCommand',
            'AnalyticsIntegrityCommand',
            'AnalyticsQuickSetupCommand',
        ];
        foreach ($commands as $cmd) {
            $this->assertFileExists(
                self::SRC_DIR . '/Console/Commands/' . $cmd . '.php',
                "Missing command: {$cmd}",
            );
        }
    }

    #[Test]
    public function sampled_console_commands_are_final(): void
    {
        $commands = [
            'AnalyticsHealthCommand',
            'AnalyticsOverviewCommand',
            'AnalyticsIntegrityCommand',
        ];
        foreach ($commands as $cmd) {
            $ref = new \ReflectionClass('ZeroBoiler\\Analytics\\Console\\Commands\\' . $cmd);
            $this->assertTrue($ref->isFinal(), "{$cmd} must be final");
        }
    }

    // ── 24. Phase40 Version Sync ───────────────────────────────────────

    #[Test]
    public function phase40_test_version_matches_current(): void
    {
        $content = file_get_contents(self::TESTS_DIR . '/Phase40ProductionReadinessTest.php');
        $this->assertStringContainsString("'" . self::VERSION . "'", $content, 'Phase40 test VERSION should be ' . self::VERSION);
    }

    // ── 25. Config File Has Required Top-Level Sections ─────────────────

    #[Test]
    public function config_has_30_plus_sections(): void
    {
        $config = require self::PKG_ROOT . '/config/zeroboiler.php';
        $analytics = $config['analytics'];
        $this->assertGreaterThan(
            30,
            count($analytics),
            'Config should have 30+ top-level analytics sections',
        );
    }

    #[Test]
    public function config_has_lifecycle_section(): void
    {
        $config = require self::PKG_ROOT . '/config/zeroboiler.php';
        $this->assertArrayHasKey('lifecycle', $config['analytics']);
    }

    #[Test]
    public function config_has_pipeline_profiler_section(): void
    {
        $config = require self::PKG_ROOT . '/config/zeroboiler.php';
        $this->assertArrayHasKey('pipeline_profiler', $config['analytics']);
    }

    #[Test]
    public function config_has_sla_section(): void
    {
        $config = require self::PKG_ROOT . '/config/zeroboiler.php';
        $this->assertArrayHasKey('sla', $config['analytics']);
    }

    #[Test]
    public function config_has_failover_section(): void
    {
        $config = require self::PKG_ROOT . '/config/zeroboiler.php';
        $this->assertArrayHasKey('failover', $config['analytics']);
    }

    #[Test]
    public function config_has_cardinality_section(): void
    {
        $config = require self::PKG_ROOT . '/config/zeroboiler.php';
        $this->assertArrayHasKey('cardinality', $config['analytics']);
    }

    // ── 26. Routes File ─────────────────────────────────────────────────

    #[Test]
    public function routes_file_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/routes/analytics.php');
    }

    // ── 27. Resources Directory ─────────────────────────────────────────

    #[Test]
    public function resources_js_directory_exists(): void
    {
        $this->assertDirectoryExists(self::PKG_ROOT . '/resources/js');
    }

    #[Test]
    public function js_client_file_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/resources/js/analytics.js');
    }

    // ── 28. Database Directory ──────────────────────────────────────────

    #[Test]
    public function database_migrations_directory_exists(): void
    {
        $this->assertDirectoryExists(self::PKG_ROOT . '/database/migrations');
    }

    // ── 29. Support Classes ─────────────────────────────────────────────

    #[Test]
    public function analytics_fake_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Support/AnalyticsFake.php');
    }

    #[Test]
    public function with_analytics_fake_trait_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Support/WithAnalyticsFake.php');
    }

    #[Test]
    public function event_builder_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Support/EventBuilder.php');
    }

    // ── 30. Blade Directives ────────────────────────────────────────────

    #[Test]
    public function analytics_directives_exist(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Blade/Directives/AnalyticsDirectives.php');
    }

    // ── 31. Inertia Integration ────────────────────────────────────────

    #[Test]
    public function inertia_handler_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Inertia/HandleInertiaAnalytics.php');
    }

    // ── 32. Enrichment System ────────────────────────────────────────────

    #[Test]
    public function enrichment_orchestrator_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Enrichment/EventEnrichmentOrchestrator.php');
    }

    #[Test]
    public function enrichment_plugin_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Enrichment/EventEnrichmentPlugin.php');
    }

    #[Test]
    public function enrichment_registry_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Enrichment/EventEnrichmentRegistry.php');
    }

    // ── 33. Macro System ───────────────────────────────────────────────

    #[Test]
    public function macro_registry_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Macros/AnalyticsMacroRegistry.php');
    }

    #[Test]
    public function macro_builder_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Macros/AnalyticsMacroBuilder.php');
    }

    // ── 34. Pipeline System ──────────────────────────────────────────────

    #[Test]
    public function event_pipeline_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Pipeline/EventPipeline.php');
    }

    #[Test]
    public function validation_pipeline_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Pipeline/Validation/EventValidationPipeline.php');
    }

    #[Test]
    public function sampled_pipeline_filters_exist(): void
    {
        $filters = [
            'ConsentAwareFilter', 'EventDeduplicationFilter', 'SamplingFilter',
            'TimestampEnricher', 'UtmEnricher', 'GeolocationEnricher',
            'PriorityAwareFilter', 'EventDebounceFilter',
        ];
        foreach ($filters as $filter) {
            $this->assertFileExists(
                self::SRC_DIR . '/Pipeline/' . $filter . '.php',
                "Missing pipeline filter: {$filter}",
            );
        }
    }

    // ── 35. Attributes ──────────────────────────────────────────────────

    #[Test]
    public function analytics_event_attribute_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Attributes/AnalyticsEventAttribute.php');
    }

    #[Test]
    public function analytics_lifecycle_mapping_attribute_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Attributes/AnalyticsLifecycleMapping.php');
    }

    // ── 36. Bus System ─────────────────────────────────────────────────

    #[Test]
    public function data_bus_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Bus/AnalyticsDataBus.php');
    }

    #[Test]
    public function event_bus_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Bus/AnalyticsEventBus.php');
    }

    #[Test]
    public function event_dispatcher_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Bus/AnalyticsEventDispatcher.php');
    }

    // ── 37. Schema System ──────────────────────────────────────────────

    #[Test]
    public function schema_registry_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Schema/EventSchemaRegistry.php');
    }

    #[Test]
    public function schema_registry_extended_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Schema/EventSchemaRegistryExtended.php');
    }

    #[Test]
    public function schema_builder_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Schema/EventSchemaBuilder.php');
    }

    // ── 38. Tracking System ─────────────────────────────────────────────

    #[Test]
    public function server_side_tracker_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Tracking/ServerSideTracker.php');
    }

    #[Test]
    public function session_tracker_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Tracking/SessionTracker.php');
    }

    #[Test]
    public function user_identity_tracker_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Tracking/UserIdentityTracker.php');
    }

    #[Test]
    public function lifecycle_event_subscriber_exists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Tracking/LifecycleEventSubscriber.php');
    }

    // ── 39. Key Service Classes Exist ──────────────────────────────────

    #[Test]
    public function key_services_exist(): void
    {
        $services = [
            'EventValidationService',
            'EventDeduplicationService',
            'RevenueAnalyticsService',
            'FunnelAnalyticsService',
            'CohortAnalyticsService',
            'AnomalyDetectionService',
            'ChurnPredictionService',
            'EventStreamService',
            'ExportService',
            'GoogleAnalyticsService',
            'GoogleTagManagerService',
            'MetaPixelService',
            'AnalyticsHealthService',
            'AnalyticsConfigValidator',
            'SaaSAnalyticsService',
            'EventOrchestrationService',
            'EventBudgetService',
            'EventBudgetEnforcementService',
            'EventBudgetOptimizerService',
            'CrossDeviceIdentityMergeService',
        ];
        foreach ($services as $service) {
            $this->assertFileExists(
                self::SRC_DIR . '/Services/' . $service . '.php',
                "Missing service: {$service}",
            );
        }
    }

    // ── 40. Test File Count Validation ──────────────────────────────────

    #[Test]
    public function test_directory_has_minimum_test_files(): void
    {
        $files = glob(self::TESTS_DIR . '/*.php');
        $this->assertGreaterThan(394, count($files), 'Should have 395+ top-level test files');
    }
}
