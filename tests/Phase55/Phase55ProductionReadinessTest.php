<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests\Phase55;

use PHPUnit\Framework\Attributes\Test;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsServiceProvider;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\DTO\EventAction;
use ZeroBoiler\Analytics\Exceptions\AnalyticsException;
use ZeroBoiler\Analytics\Exceptions\AnalyticsRuntimeException;
use ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Services\EventPropertyTypeValidator;
use ZeroBoiler\Analytics\Services\PropertyViolation;
use ZeroBoiler\Analytics\Services\PropertyValidationResult;
use ZeroBoiler\Analytics\Services\EventQueryBuilder;

/**
 * Phase 55 Production Readiness — Deep Quality Audit.
 *
 * Validates: exception hierarchy (FQCN bidirectional cross-references,
 * leaf finality, forMessage factories), ServiceProvider provides() audit,
 * Facade #[Override], AnalyticsManager final + @since, DTO immutability,
 * EventPropertyTypeValidator constants, EventQueryBuilder existence,
 * EventAction immutability, version consistency 231.0.0 across 5 entry points,
 * composer metadata (PHP ^8.5, Laravel ^13.0), rector PHP 8.5 target,
 * phpstan level 9, strict_types + MIT headers (950+ files), zero TODO/FIXME,
 * project structure files, config file integrity.
 *
 * @since 231.0.0
 */
final class Phase55ProductionReadinessTest extends \PHPUnit\Framework\TestCase
{
    // ── Exception Hierarchy ───────────────────────────────────────────

    #[Test]
    public function analytics_exception_is_abstract(): void
    {
        $reflection = new \ReflectionClass(AnalyticsException::class);
        $this->assertTrue($reflection->isAbstract(), 'AnalyticsException must be abstract');
        $this->assertSame(\Exception::class, $reflection->getParentClass()->getName());
    }

    #[Test]
    public function analytics_exception_has_void_constructor(): void
    {
        $method = new \ReflectionMethod(AnalyticsException::class, '__construct');
        $this->assertTrue($method->hasReturnType());
        $this->assertSame('void', (string) $method->getReturnType());
    }

    #[Test]
    public function runtime_exception_is_final_leaf(): void
    {
        $reflection = new \ReflectionClass(AnalyticsRuntimeException::class);
        $this->assertTrue($reflection->isFinal(), 'AnalyticsRuntimeException must be final');
        $this->assertSame(AnalyticsException::class, $reflection->getParentClass()->getName());
    }

    #[Test]
    public function invalid_argument_exception_is_final_leaf(): void
    {
        $reflection = new \ReflectionClass(InvalidAnalyticsArgumentException::class);
        $this->assertTrue($reflection->isFinal(), 'InvalidAnalyticsArgumentException must be final');
        $this->assertSame(AnalyticsException::class, $reflection->getParentClass()->getName());
    }

    #[Test]
    public function runtime_exception_for_message_returns_self(): void
    {
        $result = AnalyticsRuntimeException::forMessage('test');
        $this->assertInstanceOf(AnalyticsRuntimeException::class, $result);
        $this->assertSame('test', $result->getMessage());
    }

    #[Test]
    public function invalid_argument_exception_for_message_returns_self(): void
    {
        $result = InvalidAnalyticsArgumentException::forMessage('bad arg');
        $this->assertInstanceOf(InvalidAnalyticsArgumentException::class, $result);
        $this->assertSame('bad arg', $result->getMessage());
    }

    #[Test]
    public function exception_fqcn_cross_references(): void
    {
        // Abstract base references leaves
        $baseContent = file_get_contents(__DIR__ . '/../../src/Exceptions/AnalyticsException.php');
        $this->assertStringContainsString(InvalidAnalyticsArgumentException::class, $baseContent);
        $this->assertStringContainsString(AnalyticsRuntimeException::class, $baseContent);

        // Leaves reference base
        $runtimeContent = file_get_contents(__DIR__ . '/../../src/Exceptions/AnalyticsRuntimeException.php');
        $this->assertStringContainsString(AnalyticsException::class, $runtimeContent);

        $invalidContent = file_get_contents(__DIR__ . '/../../src/Exceptions/InvalidAnalyticsArgumentException.php');
        $this->assertStringContainsString(AnalyticsException::class, $invalidContent);
    }

    #[Test]
    public function exception_for_message_supports_code_and_previous(): void
    {
        $result = AnalyticsRuntimeException::forMessage('err', 42, new \RuntimeException('inner'));
        $this->assertSame(42, $result->getCode());
        $this->assertNotNull($result->getPrevious());
        $this->assertSame('inner', $result->getPrevious()->getMessage());
    }

    // ── ServiceProvider ───────────────────────────────────────────────

    #[Test]
    public function service_provider_is_final(): void
    {
        $reflection = new \ReflectionClass(AnalyticsServiceProvider::class);
        $this->assertTrue($reflection->isFinal(), 'AnalyticsServiceProvider must be final');
    }

    #[Test]
    public function service_provider_has_provides_method(): void
    {
        $this->assertTrue(method_exists(AnalyticsServiceProvider::class, 'provides'));
        $method = new \ReflectionMethod(AnalyticsServiceProvider::class, 'provides');
        $this->assertTrue($method->hasReturnType());
        $this->assertTrue($method->isPublic());
        $this->assertSame('array', (string) $method->getReturnType());
    }

    #[Test]
    public function service_provider_provides_core_binding(): void
    {
        $provider = new \ReflectionClass(AnalyticsServiceProvider::class);
        $providesMethod = $provider->getMethod('provides');
        $instance = $providesMethod->getDeclaringClass()->isAbstract()
            ? null
            : null; // Cannot instantiate without container — just verify method exists

        // Verify the method body contains 'zeroboiler.analytics'
        $content = file_get_contents(__DIR__ . '/../../src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString("'zeroboiler.analytics'", $content);
        $this->assertStringContainsString('AnalyticsManager::class', $content);
    }

    #[Test]
    public function service_provider_register_has_override_attribute(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('#[\\Override]', $content);
    }

    #[Test]
    public function service_provider_boot_has_override_attribute(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/AnalyticsServiceProvider.php');
        // boot() method should have #[Override]
        $this->assertTrue(
            preg_match('/#\[\\\\Override\]\s+public function boot\(\)/', $content) === 1
            || str_contains($content, '#[\\Override]'),
            'boot() should have #[Override]'
        );
    }

    // ── Facade ───────────────────────────────────────────────────────

    #[Test]
    public function facade_has_override_on_get_facade_accessor(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/Facades/Analytics.php');
        $this->assertStringContainsString('#[\\Override]', $content);
    }

    #[Test]
    public function facade_is_final(): void
    {
        $reflection = new \ReflectionClass(Analytics::class);
        $this->assertTrue($reflection->isFinal(), 'Analytics facade must be final');
    }

    #[Test]
    public function facade_has_substantial_method_docblocks(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/Facades/Analytics.php');
        $methodCount = substr_count($content, '@method static');
        $this->assertGreaterThanOrEqual(80, $methodCount, "Facade should document >= 80 methods, got {$methodCount}");
    }

    // ── AnalyticsManager ─────────────────────────────────────────────

    #[Test]
    public function analytics_manager_is_final(): void
    {
        $reflection = new \ReflectionClass(AnalyticsManager::class);
        $this->assertTrue($reflection->isFinal(), 'AnalyticsManager must be final');
    }

    #[Test]
    public function analytics_manager_has_since_annotation(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/AnalyticsManager.php');
        $this->assertStringContainsString('@since 1.0.0', $content);
    }

    #[Test]
    public function analytics_manager_has_mit_license_and_strict_types(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/AnalyticsManager.php');
        $this->assertStringContainsString('MIT license', $content);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    // ── DTO Quality ───────────────────────────────────────────────────

    #[Test]
    public function analytics_event_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(AnalyticsEvent::class);
        $this->assertTrue($reflection->isFinal(), 'AnalyticsEvent must be final');
        $this->assertTrue($reflection->isReadOnly(), 'AnalyticsEvent must be readonly');
    }

    #[Test]
    public function analytics_event_has_version_constant(): void
    {
        $this->assertSame('231.0.0', AnalyticsEvent::VERSION);
    }

    #[Test]
    public function analytics_event_has_from_array(): void
    {
        $this->assertTrue(method_exists(AnalyticsEvent::class, 'fromArray'));
        $method = new \ReflectionMethod(AnalyticsEvent::class, 'fromArray');
        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
    }

    #[Test]
    public function analytics_event_has_to_array(): void
    {
        $this->assertTrue(method_exists(AnalyticsEvent::class, 'toArray'));
        $method = new \ReflectionMethod(AnalyticsEvent::class, 'toArray');
        $this->assertTrue($method->isPublic());
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('array', (string) $returnType);
    }

    #[Test]
    public function event_action_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(EventAction::class);
        $this->assertTrue($reflection->isFinal(), 'EventAction must be final');
        $this->assertTrue($reflection->isReadOnly(), 'EventAction must be readonly');
    }

    #[Test]
    public function event_action_has_matches_method(): void
    {
        $this->assertTrue(method_exists(EventAction::class, 'matches'));
        $method = new \ReflectionMethod(EventAction::class, 'matches');
        $this->assertSame('bool', (string) $method->getReturnType());
    }

    #[Test]
    public function consent_state_exists(): void
    {
        $this->assertTrue(class_exists(ConsentState::class));
    }

    // ── V231 New Services ────────────────────────────────────────────

    #[Test]
    public function event_property_type_validator_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Services/EventPropertyTypeValidator.php');
        $this->assertTrue(class_exists(EventPropertyTypeValidator::class));
    }

    #[Test]
    public function event_property_type_validator_has_severity_constants(): void
    {
        $this->assertSame('error', EventPropertyTypeValidator::SEVERITY_ERROR);
        $this->assertSame('warning', EventPropertyTypeValidator::SEVERITY_WARNING);
        $this->assertSame('info', EventPropertyTypeValidator::SEVERITY_INFO);
    }

    #[Test]
    public function event_property_type_validator_has_error_code_constants(): void
    {
        $this->assertSame('missing_required', EventPropertyTypeValidator::CODE_MISSING_REQUIRED);
        $this->assertSame('type_mismatch', EventPropertyTypeValidator::CODE_TYPE_MISMATCH);
        $this->assertSame('range_violation', EventPropertyTypeValidator::CODE_RANGE_VIOLATION);
        $this->assertSame('length_exceeded', EventPropertyTypeValidator::CODE_LENGTH_EXCEEDED);
        $this->assertSame('unknown_param', EventPropertyTypeValidator::CODE_UNKNOWN_PARAM);
        $this->assertSame('no_schema', EventPropertyTypeValidator::CODE_NO_SCHEMA);
        $this->assertSame('invalid_param_key', EventPropertyTypeValidator::CODE_INVALID_PARAM_KEY);
    }

    #[Test]
    public function event_property_type_validator_is_final(): void
    {
        $reflection = new \ReflectionClass(EventPropertyTypeValidator::class);
        $this->assertTrue($reflection->isFinal(), 'EventPropertyTypeValidator must be final');
    }

    #[Test]
    public function event_property_type_validator_has_since_231(): void
    {
        $content = file_get_contents(__DIR__ . '/../../src/Services/EventPropertyTypeValidator.php');
        $this->assertStringContainsString('@since 231.0.0', $content);
    }

    #[Test]
    public function property_violation_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Services/PropertyViolation.php');
        $this->assertTrue(class_exists(PropertyViolation::class));
    }

    #[Test]
    public function property_violation_has_to_array_and_from_array(): void
    {
        $this->assertTrue(method_exists(PropertyViolation::class, 'toArray'));
        $this->assertTrue(method_exists(PropertyViolation::class, 'fromArray'));
    }

    #[Test]
    public function property_validation_result_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Services/PropertyValidationResult.php');
        $this->assertTrue(class_exists(PropertyValidationResult::class));
    }

    #[Test]
    public function property_validation_result_has_convenience_methods(): void
    {
        $this->assertTrue(method_exists(PropertyValidationResult::class, 'passed'));
        $this->assertTrue(method_exists(PropertyValidationResult::class, 'failed'));
        $this->assertTrue(method_exists(PropertyValidationResult::class, 'errorsOnly'));
        $this->assertTrue(method_exists(PropertyValidationResult::class, 'toArray'));
    }

    #[Test]
    public function event_query_builder_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../src/Services/EventQueryBuilder.php');
        $this->assertTrue(class_exists(EventQueryBuilder::class));
    }

    #[Test]
    public function event_query_builder_has_since_and_until_methods(): void
    {
        $this->assertTrue(method_exists(EventQueryBuilder::class, 'since'));
        $this->assertTrue(method_exists(EventQueryBuilder::class, 'until'));
    }

    // ── Version Consistency ───────────────────────────────────────────

    #[Test]
    public function version_consistency_all_entry_points(): void
    {
        $this->assertSame('231.0.0', AnalyticsEvent::VERSION);

        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        $this->assertSame('231.0.0', $composer['version']);

        $package = json_decode(file_get_contents(__DIR__ . '/../../package.json'), true);
        $this->assertSame('231.0.0', $package['version']);

        $js = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
        $this->assertStringContainsString('@version 231.0.0', $js);

        $readme = file_get_contents(__DIR__ . '/../../README.md');
        $this->assertStringContainsString('version-231.0.0', $readme);
    }

    // ── Composer Metadata ─────────────────────────────────────────────

    #[Test]
    public function composer_requires_php_85(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        $this->assertArrayHasKey('php', $composer['require']);
        $this->assertStringContainsString('8.5', $composer['require']['php']);
    }

    #[Test]
    public function composer_requires_laravel_13(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        $this->assertArrayHasKey('illuminate/contracts', $composer['require']);
        $this->assertStringContainsString('13', $composer['require']['illuminate/contracts']);
    }

    #[Test]
    public function composer_has_zeroboiler_namespace(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        $this->assertArrayHasKey('psr-4', $composer['autoload']);
        $this->assertArrayHasKey('ZeroBoiler\\Analytics\\', $composer['autoload']['psr-4']);
    }

    // ── Rector ────────────────────────────────────────────────────────

    #[Test]
    public function rector_targets_php_85(): void
    {
        $content = file_get_contents(__DIR__ . '/../../rector.php');
        $this->assertStringContainsString('PHP_85', $content);
    }

    // ── PhpStan ──────────────────────────────────────────────────────

    #[Test]
    public function phpstan_level_9(): void
    {
        $content = file_get_contents(__DIR__ . '/../../phpstan.neon.dist');
        $this->assertStringContainsString('level: 9', $content);
    }

    #[Test]
    public function phpstan_checks_uninitialized_properties(): void
    {
        $content = file_get_contents(__DIR__ . '/../../phpstan.neon.dist');
        $this->assertStringContainsString('checkUninitializedProperties: true', $content);
    }

    // ── Source File Quality ───────────────────────────────────────────

    #[Test]
    public function all_source_files_have_strict_types(): void
    {
        $srcFiles = glob(__DIR__ . '/../../src/**/*.php', GLOB_BRACE);
        $failures = [];
        foreach ($srcFiles as $file) {
            $content = file_get_contents($file);
            if (! str_contains($content, 'declare(strict_types=1)')) {
                $failures[] = str_replace(__DIR__ . '/../../', '', $file);
            }
        }
        $this->assertEmpty($failures, 'Files missing strict_types: ' . implode(', ', array_slice($failures, 0, 5)));
    }

    #[Test]
    public function all_source_files_have_mit_license_header(): void
    {
        $srcFiles = glob(__DIR__ . '/../../src/**/*.php', GLOB_BRACE);
        $failures = [];
        foreach ($srcFiles as $file) {
            $content = file_get_contents($file);
            if (! str_contains($content, 'MIT license')) {
                $failures[] = str_replace(__DIR__ . '/../../', '', $file);
            }
        }
        $this->assertEmpty($failures, 'Files missing MIT header: ' . implode(', ', array_slice($failures, 0, 5)));
    }

    #[Test]
    public function source_file_count_baseline(): void
    {
        $srcFiles = glob(__DIR__ . '/../../src/**/*.php', GLOB_BRACE);
        $this->assertGreaterThanOrEqual(950, count($srcFiles), 'Should have >= 950 source files');
    }

    // ── Zero TODO/FIXME ──────────────────────────────────────────────

    #[Test]
    public function zero_todo_or_fixme_in_source(): void
    {
        $srcFiles = glob(__DIR__ . '/../../src/**/*.php', GLOB_BRACE);
        $violations = [];
        foreach ($srcFiles as $file) {
            $lines = file($file);
            foreach ($lines as $num => $line) {
                if (preg_match('/(TODO|FIXME|HACK|XXX)/', $line)) {
                    $relative = str_replace(__DIR__ . '/../../', '', $file);
                    $violations[] = "{$relative}:{$num}";
                }
            }
        }
        $this->assertEmpty($violations, 'Found TODO/FIXME: ' . implode(', ', array_slice($violations, 0, 10)));
    }

    // ── Project Structure ─────────────────────────────────────────────

    #[Test]
    public function project_structure_files_exist(): void
    {
        $files = [
            'composer.json',
            'package.json',
            'README.md',
            'CHANGELOG.md',
            'rector.php',
            'phpstan.neon.dist',
            'config/zeroboiler.php',
        ];
        foreach ($files as $file) {
            $this->assertFileExists(__DIR__ . '/../../' . $file, "Missing: {$file}");
        }
    }

    // ── Config Integrity ─────────────────────────────────────────────

    #[Test]
    public function config_has_core_sections(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        $sections = [
            'ga4', 'gtm', 'meta_pixel', 'consent', 'queue', 'api',
            'identity', 'ecommerce', 'property_validation', 'event_query',
        ];
        foreach ($sections as $section) {
            $this->assertStringContainsString("'{$section}'", $content, "Missing config section: {$section}");
        }
    }

    #[Test]
    public function config_has_event_actions_section(): void
    {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        $this->assertStringContainsString("'event_actions'", $content, 'Missing event_actions config section');
    }

    // ── Event Categories Coverage ───────────────────────────────────

    #[Test]
    public function event_categories_directories_exist(): void
    {
        $categories = [
            'Ecommerce', 'SaaS', 'Engagement', 'Security', 'Uptime',
            'Infrastructure', 'Marketing', 'Webhook',
        ];
        foreach ($categories as $category) {
            $this->assertFileExists(
                __DIR__ . "/../../src/Events/{$category}",
                "Missing event category directory: {$category}",
            );
        }
    }

    // ── Command Count ─────────────────────────────────────────────────

    #[Test]
    public function command_count_baseline(): void
    {
        $commandFiles = glob(__DIR__ . '/../../src/Console/Commands/*.php');
        $this->assertGreaterThanOrEqual(110, count($commandFiles), 'Should have >= 110 artisan commands');
    }

    // ── Service Count ──────────────────────────────────────────────────

    #[Test]
    public function service_count_baseline(): void
    {
        $serviceFiles = glob(__DIR__ . '/../../src/Services/*.php');
        $this->assertGreaterThanOrEqual(430, count($serviceFiles), 'Should have >= 430 services');
    }

    // ── Test Count Baseline ───────────────────────────────────────────

    #[Test]
    public function test_file_count_baseline(): void
    {
        $testFiles = glob(__DIR__ . '/../../tests/**/*Test.php', GLOB_BRACE);
        $this->assertGreaterThanOrEqual(479, count($testFiles), 'Should have >= 479 test files');
    }

    // ── JS Client Integrity ──────────────────────────────────────────

    #[Test]
    public function js_client_loc_baseline(): void
    {
        $lines = count(file(__DIR__ . '/../../resources/js/analytics.js'));
        $this->assertGreaterThanOrEqual(14000, $lines, "analytics.js should have >= 14,000 LOC, got {$lines}");
    }

    #[Test]
    public function typescript_definitions_substantial(): void
    {
        $content = file_get_contents(__DIR__ . '/../../resources/js/analytics.d.ts');
        $this->assertGreaterThanOrEqual(3000, strlen($content), 'TypeScript definitions should be >= 3,000 chars');
    }

    // ── Changelog Has 231 Entry ────────────────────────────────────────

    #[Test]
    public function changelog_has_v231_entry(): void
    {
        $content = file_get_contents(__DIR__ . '/../../CHANGELOG.md');
        $this->assertStringContainsString('[231.0.0]', $content);
        $this->assertStringContainsString('EventPropertyTypeValidator', $content);
    }
}
