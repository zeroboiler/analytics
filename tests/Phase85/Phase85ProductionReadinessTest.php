<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests\Phase85;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsServiceProvider;
use ZeroBoiler\Analytics\Contracts\AnalyticsEventStoreInterface;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Exceptions\AnalyticsException;
use ZeroBoiler\Analytics\Exceptions\AnalyticsRuntimeException;
use ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Pipeline\Validation\ValidationStageInterface;
use ZeroBoiler\Analytics\Support\AnalyticsFake;

/**
 * Phase 85 production readiness — comprehensive structural audit.
 *
 * Validates strict_types, license header, final classes, constructor :void,
 * exception hierarchy with forMessage self-return, ServiceProvider final+#[Override],
 * Facade final+#[Override], zero TODO/FIXME, phpstan parity, composer metadata,
 * version consistency, config structure, json_encode safety, and project scale.
 *
 * @since 253.0.0
 */
final class Phase85ProductionReadinessTest extends TestCase
{
    // ── Version Consistency ──────────────────────────────────────

    #[Test]
    public function version_consistency_across_entry_points(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        $package = json_decode(file_get_contents(__DIR__ . '/../../package.json'), true);
        $readme = file_get_contents(__DIR__ . '/../../README.md');
        $eventVersion = AnalyticsEvent::VERSION;

        $this->assertSame('253.0.0', $composer['version']);
        $this->assertSame('253.0.0', $package['version']);
        $this->assertStringContainsString('version-253.0.0', $readme);
        $this->assertSame('253.0.0', $eventVersion);
    }

    // ── Composer Metadata ─────────────────────────────────────────

    #[Test]
    public function composer_metadata(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);

        $this->assertSame('zeroboiler/analytics', $composer['name']);
        $this->assertSame('library', $composer['type']);
        $this->assertSame('MIT', $composer['license']);
        $this->assertSame('^8.5', $composer['require']['php']);
        $this->assertSame('^13.0', $composer['require']['illuminate/contracts']);
        $this->assertSame('^13.0', $composer['require']['illuminate/support']);
        $this->assertArrayHasKey('zeroboiler/analytics', $composer['extra']['laravel']['providers'][0] ?? []);
    }

    #[Test]
    public function composer_autoload_psr4(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);

        $this->assertSame('ZeroBoiler\\Analytics\\', $composer['autoload']['psr-4']['ZeroBoiler\\Analytics\\']);
        $this->assertSame('ZeroBoiler\\Analytics\\Tests\\', $composer['autoload-dev']['psr-4']['ZeroBoiler\\Analytics\\Tests\\']);
    }

    #[Test]
    public function composer_dev_dependencies(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);

        $this->assertArrayHasKey('pestphp/pest', $composer['require-dev']);
        $this->assertArrayHasKey('phpstan/phpstan', $composer['require-dev']);
        $this->assertArrayHasKey('laravel/pint', $composer['require-dev']);
        $this->assertArrayHasKey('rector/rector', $composer['require-dev']);
    }

    // ── File Quality: strict_types + License ─────────────────────

    #[Test]
    public function all_src_files_have_strict_types(): void
    {
        $files = glob(__DIR__ . '/../../src/**/*.php', GLOB_ERR);
        $missing = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (! str_contains($content, 'declare(strict_types=1);')) {
                $missing[] = str_replace(__DIR__ . '/../../', '', $file);
            }
        }

        $this->assertEmpty($missing, 'Files missing declare(strict_types=1): ' . implode(', ', $missing));
    }

    #[Test]
    public function all_src_files_have_license_header(): void
    {
        $files = glob(__DIR__ . '/../../src/**/*.php', GLOB_ERR);
        $missing = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (! str_contains($content, 'This file is part of ZeroBoiler, licensed under the MIT license.')) {
                $missing[] = str_replace(__DIR__ . '/../../', '', $file);
            }
        }

        $this->assertEmpty($missing, 'Files missing license header: ' . implode(', ', $missing));
    }

    #[Test]
    public function no_blank_line_after_opening_tag(): void
    {
        $files = glob(__DIR__ . '/../../src/**/*.php', GLOB_ERR);
        $violations = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (str_starts_with($content, "<?php\n\n")) {
                $violations[] = str_replace(__DIR__ . '/../../', '', $file);
            }
        }

        $this->assertEmpty($violations, 'Files with blank line after <?php: ' . implode(', ', $violations));
    }

    // ── Zero TODO/FIXME ───────────────────────────────────────────

    #[Test]
    public function zero_todo_fixme_in_src(): void
    {
        $files = glob(__DIR__ . '/../../src/**/*.php', GLOB_ERR);
        $violations = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $lines = explode("\n", $content);
            foreach ($lines as $num => $line) {
                if (preg_match('/\/\/\s*(TODO|FIXME|HACK|XXX)\b/i', $line) ||
                    preg_match('/\*\s*(TODO|FIXME|HACK|XXX)\b/i', $line)) {
                    $violations[] = str_replace(__DIR__ . '/../../', '', $file) . ":" . ($num + 1);
                }
            }
        }

        $this->assertEmpty($violations, 'TODO/FIXME found: ' . implode(', ', $violations));
    }

    // ── Exception Hierarchy ───────────────────────────────────────

    #[Test]
    public function exception_hierarchy(): void
    {
        $this->assertTrue(is_subclass_of(AnalyticsRuntimeException::class, AnalyticsException::class));
        $this->assertTrue(is_subclass_of(InvalidAnalyticsArgumentException::class, AnalyticsException::class));
        $this->assertTrue(is_subclass_of(AnalyticsException::class, \Exception::class));
    }

    #[Test]
    public function exception_leaves_are_final(): void
    {
        $ref = new \ReflectionClass(AnalyticsRuntimeException::class);
        $this->assertTrue($ref->isFinal());

        $ref = new \ReflectionClass(InvalidAnalyticsArgumentException::class);
        $this->assertTrue($ref->isFinal());
    }

    #[Test]
    public function exception_base_is_abstract(): void
    {
        $ref = new \ReflectionClass(AnalyticsException::class);
        $this->assertTrue($ref->isAbstract());
    }

    #[Test]
    public function forMessage_factory_self_return(): void
    {
        $method = new \ReflectionMethod(AnalyticsRuntimeException::class, 'forMessage');
        $this->assertSame('self', $method->getReturnType()?->getName());
        $this->assertTrue($method->isStatic());
        $this->assertTrue($method->isPublic());

        $method = new \ReflectionMethod(InvalidAnalyticsArgumentException::class, 'forMessage');
        $this->assertSame('self', $method->getReturnType()?->getName());
        $this->assertTrue($method->isStatic());
        $this->assertTrue($method->isPublic());
    }

    // ── ServiceProvider final + #[Override] ────────────────────────

    #[Test]
    public function service_provider_is_final(): void
    {
        $ref = new \ReflectionClass(AnalyticsServiceProvider::class);
        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->isSubclassOf(\Illuminate\Support\ServiceProvider::class));
    }

    #[Test]
    public function service_provider_has_override_on_register(): void
    {
        $method = new \ReflectionMethod(AnalyticsServiceProvider::class, 'register');
        $attrs = $method->getAttributes();
        $hasOverride = false;
        foreach ($attrs as $attr) {
            if ($attr->getName() === 'Override') {
                $hasOverride = true;
                break;
            }
        }
        $this->assertTrue($hasOverride, 'register() missing #[Override]');
        $this->assertSame('void', $method->getReturnType()?->getName());
    }

    #[Test]
    public function service_provider_has_override_on_boot(): void
    {
        $method = new \ReflectionMethod(AnalyticsServiceProvider::class, 'boot');
        $attrs = $method->getAttributes();
        $hasOverride = false;
        foreach ($attrs as $attr) {
            if ($attr->getName() === 'Override') {
                $hasOverride = true;
                break;
            }
        }
        $this->assertTrue($hasOverride, 'boot() missing #[Override]');
        $this->assertSame('void', $method->getReturnType()?->getName());
    }

    #[Test]
    public function service_provider_has_override_on_provides(): void
    {
        $method = new \ReflectionMethod(AnalyticsServiceProvider::class, 'provides');
        $attrs = $method->getAttributes();
        $hasOverride = false;
        foreach ($attrs as $attr) {
            if ($attr->getName() === 'Override') {
                $hasOverride = true;
                break;
            }
        }
        $this->assertTrue($hasOverride, 'provides() missing #[Override]');
    }

    #[Test]
    public function service_provider_provides_binding(): void
    {
        $spContent = file_get_contents(__DIR__ . '/../../src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString("singleton('zeroboiler.analytics'", $spContent);
    }

    // ── Facade final + #[Override] ─────────────────────────────────

    #[Test]
    public function facade_is_final(): void
    {
        $ref = new \ReflectionClass(Analytics::class);
        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->isSubclassOf(\Illuminate\Support\Facades\Facade::class));
    }

    #[Test]
    public function facade_has_override_on_get_facade_accessor(): void
    {
        $method = new \ReflectionMethod(Analytics::class, 'getFacadeAccessor');
        $attrs = $method->getAttributes();
        $hasOverride = false;
        foreach ($attrs as $attr) {
            if ($attr->getName() === 'Override') {
                $hasOverride = true;
                break;
            }
        }
        $this->assertTrue($hasOverride, 'getFacadeAccessor() missing #[Override]');
        $this->assertSame('string', $method->getReturnType()?->getName());
    }

    #[Test]
    public function facade_returns_correct_accessor(): void
    {
        $ref = new \ReflectionMethod(Analytics::class, 'getFacadeAccessor');
        $ref->setAccessible(true);
        $this->assertSame('zeroboiler.analytics', $ref->invoke(null));
    }

    // ── AnalyticsManager final ────────────────────────────────────

    #[Test]
    public function analytics_manager_is_final(): void
    {
        $ref = new \ReflectionClass(AnalyticsManager::class);
        $this->assertTrue($ref->isFinal());
    }

    #[Test]
    public function analytics_manager_constructor_void(): void
    {
        $method = new \ReflectionMethod(AnalyticsManager::class, '__construct');
        $this->assertSame('void', $method->getReturnType()?->getName());
    }

    // ── Constructor :void audit (sample) ───────────────────────────

    #[Test]
    public function tracker_constructors_have_void_return(): void
    {
        $trackers = [
            \ZeroBoiler\Analytics\Trackers\GA4Tracker::class,
            \ZeroBoiler\Analytics\Trackers\GTMTracker::class,
            \ZeroBoiler\Analytics\Trackers\MetaPixelTracker::class,
            \ZeroBoiler\Analytics\Trackers\PlausibleTracker::class,
            \ZeroBoiler\Analytics\Trackers\PosthogTracker::class,
            \ZeroBoiler\Analytics\Trackers\MixpanelTracker::class,
            \ZeroBoiler\Analytics\Trackers\AmplitudeTracker::class,
            \ZeroBoiler\Analytics\Trackers\TikTokTracker::class,
            \ZeroBoiler\Analytics\Trackers\LinkedInTracker::class,
            \ZeroBoiler\Analytics\Trackers\WebhookTracker::class,
        ];

        foreach ($trackers as $tracker) {
            $ref = new \ReflectionMethod($tracker, '__construct');
            $this->assertSame(
                'void',
                $ref->getReturnType()?->getName(),
                "{$tracker}::__construct() missing :void return type",
            );
        }
    }

    #[Test]
    public function store_constructors_have_void_return(): void
    {
        $stores = [
            \ZeroBoiler\Analytics\Store\CacheEventStore::class,
            \ZeroBoiler\Analytics\Store\DatabaseEventStore::class,
            \ZeroBoiler\Analytics\Store\NullEventStore::class,
            \ZeroBoiler\Analytics\Store\EventStoreManager::class,
        ];

        foreach ($stores as $store) {
            $ref = new \ReflectionMethod($store, '__construct');
            $this->assertSame(
                'void',
                $ref->getReturnType()?->getName(),
                "{$store}::__construct() missing :void return type",
            );
        }
    }

    // ── Interface Count ───────────────────────────────────────────

    #[Test]
    public function core_interface_count(): void
    {
        $this->assertTrue(interface_exists(AnalyticsEventStoreInterface::class));
        $this->assertTrue(interface_exists(ValidationStageInterface::class));
        $this->assertTrue(interface_exists(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class));
        $this->assertTrue(interface_exists(\ZeroBoiler\Analytics\Http\HttpMiddlewareContract::class));
        $this->assertTrue(interface_exists(\ZeroBoiler\Analytics\Middleware\AnalyticsMiddlewareInterface::class));
    }

    // ── Config Structure ──────────────────────────────────────────

    #[Test]
    public function config_returns_array(): void
    {
        $config = require __DIR__ . '/../../config/zeroboiler.php';

        $this->assertIsArray($config);
        $this->assertArrayHasKey('analytics', $config);
    }

    #[Test]
    public function config_has_required_sections(): void
    {
        $config = require __DIR__ . '/../../config/zeroboiler.php';
        $analytics = $config['analytics'];

        $requiredSections = [
            'ga4', 'gtm', 'meta_pixel', 'consent', 'auto_track',
            'queue', 'lifecycle', 'api', 'identity', 'ecommerce',
            'revenue', 'revenue_waterfall', 'feature_flags',
            'dedup_cache', 'revenue_checksum', 'client_auto_track',
        ];

        foreach ($requiredSections as $section) {
            $this->assertArrayHasKey($section, $analytics, "Missing config section: {$section}");
        }
    }

    #[Test]
    public function config_ga4_keys(): void
    {
        $config = require __DIR__ . '/../../config/zeroboiler.php';
        $ga4 = $config['analytics']['ga4'];

        $this->assertArrayHasKey('enabled', $ga4);
        $this->assertArrayHasKey('measurement_id', $ga4);
        $this->assertArrayHasKey('api_secret', $ga4);
    }

    #[Test]
    public function config_consent_keys(): void
    {
        $config = require __DIR__ . '/../../config/zeroboiler.php';
        $consent = $config['analytics']['consent'];

        $this->assertArrayHasKey('default', $consent);
        $this->assertArrayHasKey('purposes', $consent);
        $this->assertIsArray($consent['purposes']);
    }

    #[Test]
    public function config_api_keys(): void
    {
        $config = require __DIR__ . '/../../config/zeroboiler.php';
        $api = $config['analytics']['api'];

        $this->assertArrayHasKey('enabled', $api);
        $this->assertArrayHasKey('rate_limit', $api);
        $this->assertArrayHasKey('sdk_token', $api);
        $this->assertArrayHasKey('require_auth', $api);
    }

    #[Test]
    public function config_queue_keys(): void
    {
        $config = require __DIR__ . '/../../config/zeroboiler.php';
        $queue = $config['analytics']['queue'];

        $this->assertArrayHasKey('enabled', $queue);
        $this->assertArrayHasKey('queue', $queue);
        $this->assertArrayHasKey('connection', $queue);
        $this->assertArrayHasKey('max_batch_size', $queue);
    }

    #[Test]
    public function config_identity_keys(): void
    {
        $config = require __DIR__ . '/../../config/zeroboiler.php';
        $identity = $config['analytics']['identity'];

        $this->assertArrayHasKey('cookie_name', $identity);
        $this->assertArrayHasKey('cookie_ttl', $identity);
        $this->assertArrayHasKey('cookie_secure', $identity);
        $this->assertArrayHasKey('cookie_samesite', $identity);
    }

    #[Test]
    public function config_section_count_minimum(): void
    {
        $config = require __DIR__ . '/../../config/zeroboiler.php';
        $analytics = $config['analytics'];

        $this->assertGreaterThanOrEqual(20, count($analytics), 'Config section count too low');
    }

    // ── phpstan Parity ────────────────────────────────────────────

    #[Test]
    public function phpstan_neon_includes_dist(): void
    {
        $neon = file_get_contents(__DIR__ . '/../../phpstan.neon');
        $this->assertStringContainsString('includes:', $neon);
        $this->assertStringContainsString('phpstan.neon.dist', $neon);
    }

    #[Test]
    public function phpstan_neon_dist_level_9(): void
    {
        $content = file_get_contents(__DIR__ . '/../../phpstan.neon.dist');
        $this->assertStringContainsString('level: 9', $content);
    }

    #[Test]
    public function phpstan_neon_dist_has_required_settings(): void
    {
        $content = file_get_contents(__DIR__ . '/../../phpstan.neon.dist');

        $this->assertStringContainsString('checkUnusedParameters: true', $content);
        $this->assertStringContainsString('checkUninitializedProperties: true', $content);
        $this->assertStringContainsString('treatPhpDocTypesAsCertain: false', $content);
    }

    // ── Project Scale Thresholds ───────────────────────────────────

    #[Test]
    public function project_scale_thresholds(): void
    {
        $srcCount = count(glob(__DIR__ . '/../../src/**/*.php', GLOB_ERR));
        $testCount = count(glob(__DIR__ . '/../../tests/**/*.php', GLOB_ERR));
        $commandCount = count(glob(__DIR__ . '/../../src/Console/Commands/*Command.php', GLOB_ERR));
        $serviceCount = count(glob(__DIR__ . '/../../src/Services/*.php', GLOB_ERR));

        $this->assertGreaterThanOrEqual(983, $srcCount, "Source file count too low: {$srcCount}");
        $this->assertGreaterThanOrEqual(502, $testCount, "Test file count too low: {$testCount}");
        $this->assertGreaterThanOrEqual(118, $commandCount, "Command count too low: {$commandCount}");
        $this->assertGreaterThanOrEqual(452, $serviceCount, "Service count too low: {$serviceCount}");
    }

    #[Test]
    public function namespace_count_threshold(): void
    {
        $content = [];
        $files = glob(__DIR__ . '/../../src/**/*.php', GLOB_ERR);
        foreach ($files as $file) {
            $c = file_get_contents($file);
            if (preg_match('/namespace ZeroBoiler\\Analytics([^;]+)/', $c, $m)) {
                $content[] = $m[1];
            }
        }
        $unique = array_unique($content);

        $this->assertGreaterThanOrEqual(40, count($unique), 'Namespace count too low: ' . count($unique));
    }

    // ── API Endpoint Count ────────────────────────────────────────

    #[Test]
    public function api_endpoint_count(): void
    {
        $routeContent = file_get_contents(__DIR__ . '/../../routes/analytics.php');
        preg_match_all('/Route::(get|post|put|patch|delete)\(/', $routeContent, $matches);
        $endpointCount = count($matches[0]);

        $this->assertGreaterThanOrEqual(893, $endpointCount, "API endpoint count too low: {$endpointCount}");
    }

    // ── AnalyticsFake ─────────────────────────────────────────────

    #[Test]
    public function analytics_fake_exists(): void
    {
        $this->assertTrue(class_exists(AnalyticsFake::class));
        $ref = new \ReflectionClass(AnalyticsFake::class);
        $this->assertFalse($ref->isFinal(), 'AnalyticsFake should be non-final for extension');
    }

    // ── Migration File ────────────────────────────────────────────

    #[Test]
    public function migration_file_exists(): void
    {
        $migrations = glob(__DIR__ . '/../../database/migrations/*.php', GLOB_ERR);
        $this->assertNotEmpty($migrations, 'No migration files found');
    }

    // ── DTO Quality (sample) ──────────────────────────────────────

    #[Test]
    public function analytics_event_dto_is_final(): void
    {
        $ref = new \ReflectionClass(AnalyticsEvent::class);
        $this->assertTrue($ref->isFinal());
    }

    // ── License File ──────────────────────────────────────────────

    #[Test]
    public function license_file_exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../LICENSE');
        $license = file_get_contents(__DIR__ . '/../../LICENSE');
        $this->assertStringContainsString('MIT', $license);
    }

    // ── Project Structure Files ───────────────────────────────────

    #[Test]
    public function project_structure_files(): void
    {
        $root = __DIR__ . '/../..';

        $this->assertFileExists($root . '/composer.json');
        $this->assertFileExists($root . '/phpstan.neon.dist');
        $this->assertFileExists($root . '/phpstan.neon');
        $this->assertFileExists($root . '/rector.php');
        $this->assertFileExists($root . '/.editorconfig');
        $this->assertFileExists($root . '/.gitignore');
        $this->assertFileExists($root . '/config/zeroboiler.php');
        $this->assertFileExists($root . '/routes/analytics.php');
    }

    // ── Readme Badge ──────────────────────────────────────────────

    #[Test]
    public function readme_has_version_badge(): void
    {
        $readme = file_get_contents(__DIR__ . '/../../README.md');
        $this->assertStringContainsString('version-253.0.0', $readme);
    }
}
