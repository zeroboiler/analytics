<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsServiceProvider;
use ZeroBoiler\Analytics\Contracts\AnalyticsEventStoreInterface;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Http\HttpMiddlewareContract;
use ZeroBoiler\Analytics\Pipeline\Validation\ValidationStageInterface;
use ZeroBoiler\Analytics\Trackers\TrackerInterface;

beforeEach(function (): void {
    $this->version = '150.0.0';
    $this->srcDir = __DIR__ . '/../../src';
    $this->testDir = __DIR__ . '/../../tests';
});

// ─── Version & Metadata Consistency ───────────────────────────────────────

describe('Phase 38 — Version Consistency', function (): void {
    test('AnalyticsEvent::VERSION matches package version', function (): void {
        expect(AnalyticsEvent::VERSION)->toBe($this->version);
    });

    test('composer.json version matches package version', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        expect($composer['version'])->toBe($this->version);
    });

    test('composer.json requires PHP ^8.5', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        expect($composer['require']['php'])->toBe('^8.5');
    });

    test('composer.json requires illuminate/contracts ^13.0', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
    });

    test('composer.json has MIT license', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        expect($composer['license'])->toBe('MIT');
    });

    test('composer.json autoload namespace is correct', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        expect($composer['autoload']['psr-4']['ZeroBoiler\\Analytics\\'])->toBe('src/');
    });

    test('composer.json has ServiceProvider registration', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        expect($composer['extra']['laravel']['providers'])->toContain(AnalyticsServiceProvider::class);
    });

    test('composer.json has Facade alias', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        expect($composer['extra']['laravel']['aliases']['Analytics'])->toBe('ZeroBoiler\\Analytics\\Facades\\Analytics');
    });

    test('composer.json has quality scripts', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        expect($composer['scripts'])->toHaveKey('test');
        expect($composer['scripts'])->toHaveKey('lint');
        expect($composer['scripts'])->toHaveKey('lint:fix');
        expect($composer['scripts'])->toHaveKey('analyse');
        expect($composer['scripts'])->toHaveKey('rector');
        expect($composer['scripts'])->toHaveKey('quality');
    });
});

// ─── File Counts & Structure ─────────────────────────────────────────────

describe('Phase 38 — Source & Test File Integrity', function (): void {
    test('source directory exists and has files', function (): void {
        $files = glob($this->srcDir . '/*.php');
        expect(count($files))->toBeGreaterThan(0);
        $allFiles = glob($this->srcDir . '/**/*.php', GLOB_BRACE);
        expect(count($allFiles))->toBeGreaterThan(700);
    });

    test('test directory exists and has files', function (): void {
        $allFiles = glob($this->testDir . '/**/*.php', GLOB_BRACE);
        expect(count($allFiles))->toBeGreaterThan(300);
    });

    test('config file exists', function (): void {
        expect(file_exists(__DIR__ . '/../../config/zeroboiler.php'))->toBeTrue();
    });

    test('routes file exists', function (): void {
        expect(file_exists(__DIR__ . '/../../routes/analytics.php'))->toBeTrue();
    });

    test('rector.php exists and targets PHP 8.5', function (): void {
        $content = file_get_contents(__DIR__ . '/../../rector.php');
        expect($content)->toContain('PHP_85');
    });

    test('database migrations directory exists', function (): void {
        expect(is_dir(__DIR__ . '/../../database/migrations'))->toBeTrue();
    });
});

// ─── Strict Types Coverage ───────────────────────────────────────────────

describe('Phase 38 — Strict Types Coverage', function (): void {
    test('all source files have declare(strict_types=1)', function (): void {
        $files = glob($this->srcDir . '/**/*.php', GLOB_BRACE);
        $violations = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (!str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = str_replace($this->srcDir . '/', '', $file);
            }
        }
        expect($violations)->toBeEmpty('Files missing strict_types: ' . implode(', ', $violations));
    });

    test('all test files have declare(strict_types=1)', function (): void {
        $files = glob($this->testDir . '/**/*.php', GLOB_BRACE);
        $violations = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (!str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = str_replace($this->testDir . '/', '', $file);
            }
        }
        expect($violations)->toBeEmpty('Test files missing strict_types: ' . implode(', ', $violations));
    });

    test('config file has strict_types', function (): void {
        expect(file_get_contents(__DIR__ . '/../../config/zeroboiler.php'))->toContain('declare(strict_types=1)');
    });

    test('migration files have strict_types', function (): void {
        $files = glob(__DIR__ . '/../../database/migrations/*.php');
        $violations = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (!str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = basename($file);
            }
        }
        expect($violations)->toBeEmpty('Migrations missing strict_types: ' . implode(', ', $violations));
    });
});

// ─── Final Class Enforcement ──────────────────────────────────────────────

describe('Phase 38 — Final Class Enforcement', function (): void {
    test('AnalyticsManager is final', function (): void {
        expect((new ReflectionClass(AnalyticsManager::class))->isFinal())->toBeTrue();
    });

    test('Analytics facade is final', function (): void {
        expect((new ReflectionClass(Analytics::class))->isFinal())->toBeTrue();
    });

    test('AnalyticsEvent DTO is final readonly', function (): void {
        $ref = new ReflectionClass(AnalyticsEvent::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->isReadOnly())->toBeTrue();
    });

    test('AnalyticsServiceProvider is final', function (): void {
        expect((new ReflectionClass(AnalyticsServiceProvider::class))->isFinal())->toBeTrue();
    });

    test('all source classes are final', function (): void {
        $files = glob($this->srcDir . '/**/*.php', GLOB_BRACE);
        $nonFinal = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (preg_match('/^class\s+(\w+)/m', $content, $m)) {
                if (!preg_match('/^final\s+class\s+' . preg_quote($m[1], '/') . '/m', $content)) {
                    $nonFinal[] = str_replace($this->srcDir . '/', '', $file);
                }
            }
        }
        expect($nonFinal)->toBeEmpty('Non-final classes: ' . implode(', ', array_slice($nonFinal, 0, 10)));
    });
});

// ─── Constructor :void Return Types ──────────────────────────────────────

describe('Phase 38 — Constructor :void Return Types', function (): void {
    test('AnalyticsManager constructor returns void', function (): void {
        $method = new ReflectionMethod(AnalyticsManager::class, '__construct');
        expect($method->getReturnType()?->getName())->toBe('void');
    });

    test('AnalyticsServiceProvider constructor returns void', function (): void {
        $method = new ReflectionMethod(AnalyticsServiceProvider::class, '__construct');
        expect($method->getReturnType()?->getName())->toBe('void');
    });

    test('sample service constructors return void', function (): void {
        $classes = [
            'ZeroBoiler\\Analytics\\Services\\GoogleAnalyticsService',
            'ZeroBoiler\\Analytics\\Services\\MetaPixelService',
            'ZeroBoiler\\Analytics\\Services\\GoogleTagManagerService',
            'ZeroBoiler\\Analytics\\Services\\EcommerceAnalyticsService',
            'ZeroBoiler\\Analytics\\Services\\RevenueAnalyticsService',
        ];
        foreach ($classes as $class) {
            $ref = new ReflectionClass($class);
            $constructor = $ref->getConstructor();
            expect($constructor?->getReturnType()?->getName())->toBe('void', "{$class} constructor must return void");
        }
    });
});

// ─── Interface Compliance ─────────────────────────────────────────────────

describe('Phase 38 — Interface Compliance', function (): void {
    test('TrackerInterface has 6 required methods', function (): void {
        $methods = ['track', 'isEnabled', 'headScripts', 'bodyScripts', 'setConsent', 'getConsent'];
        $ref = new ReflectionClass(TrackerInterface::class);
        foreach ($methods as $method) {
            expect($ref->hasMethod($method))->toBeTrue("TrackerInterface missing method: {$method}");
        }
    });

    test('AnalyticsEventStoreInterface has 9 required methods', function (): void {
        $methods = ['store', 'storeBatch', 'retrieve', 'query', 'count', 'delete', 'deleteById', 'purge', 'aggregateBy', 'isHealthy'];
        $ref = new ReflectionClass(AnalyticsEventStoreInterface::class);
        foreach ($methods as $method) {
            expect($ref->hasMethod($method))->toBeTrue("AnalyticsEventStoreInterface missing: {$method}");
        }
    });

    test('HttpMiddlewareContract has handle method', function (): void {
        $ref = new ReflectionClass(HttpMiddlewareContract::class);
        expect($ref->hasMethod('handle'))->toBeTrue();
    });

    test('ValidationStageInterface has validate method', function (): void {
        $ref = new ReflectionClass(ValidationStageInterface::class);
        expect($ref->hasMethod('validate'))->toBeTrue();
    });

    test('all 10 tracker implementations implement TrackerInterface', function (): void {
        $trackers = [
            'ZeroBoiler\\Analytics\\Trackers\\GA4Tracker',
            'ZeroBoiler\\Analytics\\Trackers\\GTMTracker',
            'ZeroBoiler\\Analytics\\Trackers\\MetaPixelTracker',
            'ZeroBoiler\\Analytics\\Trackers\\PlausibleTracker',
            'ZeroBoiler\\Analytics\\Trackers\\PosthogTracker',
            'ZeroBoiler\\Analytics\\Trackers\\MixpanelTracker',
            'ZeroBoiler\\Analytics\\Trackers\\AmplitudeTracker',
            'ZeroBoiler\\Analytics\\Trackers\\TikTokTracker',
            'ZeroBoiler\\Analytics\\Trackers\\LinkedInTracker',
            'ZeroBoiler\\Analytics\\Trackers\\WebhookTracker',
        ];
        foreach ($trackers as $tracker) {
            expect((new ReflectionClass($tracker))->implementsInterface(TrackerInterface::class))
                ->toBeTrue("{$tracker} must implement TrackerInterface");
        }
    });

    test('event store implementations implement AnalyticsEventStoreInterface', function (): void {
        $stores = [
            'ZeroBoiler\\Analytics\\Store\\DatabaseEventStore',
            'ZeroBoiler\\Analytics\\Store\\CacheEventStore',
            'ZeroBoiler\\Analytics\\Store\\NullEventStore',
        ];
        foreach ($stores as $store) {
            expect((new ReflectionClass($store))->implementsInterface(AnalyticsEventStoreInterface::class))
                ->toBeTrue("{$store} must implement AnalyticsEventStoreInterface");
        }
    });
});

// ─── ServiceProvider #[Override] Audit ────────────────────────────────────

describe('Phase 38 — ServiceProvider #[Override] Audit', function (): void {
    test('register() has #[Override] attribute', function (): void {
        $content = file_get_contents($this->srcDir . '/AnalyticsServiceProvider.php');
        // Check that #[\Override] appears before public function register
        expect(preg_match('/#\[\\\\Override\]\s+public function register/', $content))->toBe(1);
    });

    test('boot() has #[Override] attribute', function (): void {
        $content = file_get_contents($this->srcDir . '/AnalyticsServiceProvider.php');
        expect(preg_match('/#\[\\\\Override\]\s+public function boot/', $content))->toBe(1);
    });

    test('provides() has #[Override] attribute', function (): void {
        $content = file_get_contents($this->srcDir . '/AnalyticsServiceProvider.php');
        expect(preg_match('/#\[\\\\Override\]\s+public function provides/', $content))->toBe(1);
    });

    test('register returns void', function (): void {
        $method = new ReflectionMethod(AnalyticsServiceProvider::class, 'register');
        expect($method->getReturnType()?->getName())->toBe('void');
    });

    test('boot returns void', function (): void {
        $method = new ReflectionMethod(AnalyticsServiceProvider::class, 'boot');
        expect($method->getReturnType()?->getName())->toBe('void');
    });

    test('provides returns array', function (): void {
        $method = new ReflectionMethod(AnalyticsServiceProvider::class, 'provides');
        expect($method->getReturnType()?->getName())->toBe('array');
    });
});

// ─── Facade Audit ────────────────────────────────────────────────────────

describe('Phase 38 — Facade Audit', function (): void {
    test('Analytics facade getFacadeAccessor returns correct key', function (): void {
        $method = new ReflectionMethod(Analytics::class, 'getFacadeAccessor');
        $method->setAccessible(true);
        expect($method->invoke(null))->toBe('zeroboiler.analytics');
    });

    test('Analytics facade getFacadeAccessor has #[Override]', function (): void {
        $content = file_get_contents($this->srcDir . '/Facades/Analytics.php');
        expect($content)->toContain('#[\\Override]');
    });

    test('Analytics facade has @see AnalyticsManager reference', function (): void {
        $content = file_get_contents($this->srcDir . '/Facades/Analytics.php');
        expect($content)->toContain('@see');
    });

    test('Analytics facade has @since annotation', function (): void {
        $content = file_get_contents($this->srcDir . '/Facades/Analytics.php');
        expect($content)->toContain('@since');
    });

    test('Analytics facade has identifyAndTrack @method annotation', function (): void {
        $content = file_get_contents($this->srcDir . '/Facades/Analytics.php');
        expect($content)->toContain('identifyAndTrack');
    });
});

// ─── Config Structure Validation ─────────────────────────────────────────

describe('Phase 38 — Config Structure Validation', function (): void {
    test('config has analytics top-level key', function (): void {
        $config = include __DIR__ . '/../../config/zeroboiler.php';
        expect($config)->toHaveKey('analytics');
    });

    test('config has all 10 provider sections', function (): void {
        $config = include __DIR__ . '/../../config/zeroboiler.php';
        $providers = ['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog', 'mixpanel', 'amplitude', 'tiktok', 'linkedin', 'webhook'];
        foreach ($providers as $p) {
            expect($config['analytics'])->toHaveKey($p, "Missing provider config: {$p}");
        }
    });

    test('config has consent section', function (): void {
        $config = include __DIR__ . '/../../config/zeroboiler.php';
        expect($config['analytics'])->toHaveKey('consent');
        expect($config['analytics']['consent'])->toHaveKeys(['default', 'purposes', 'log_enabled', 'log_ttl']);
    });

    test('config has queue section', function (): void {
        $config = include __DIR__ . '/../../config/zeroboiler.php';
        expect($config['analytics'])->toHaveKey('queue');
    });

    test('config has identity section', function (): void {
        $config = include __DIR__ . '/../../config/zeroboiler.php';
        expect($config['analytics'])->toHaveKey('identity');
    });

    test('config has data_residency section with zones', function (): void {
        $config = include __DIR__ . '/../../config/zeroboiler.php';
        expect($config['analytics'])->toHaveKey('data_residency');
        expect($config['analytics']['data_residency'])->toHaveKey('zones');
    });

    test('config has event_consistency section', function (): void {
        $config = include __DIR__ . '/../../config/zeroboiler.php';
        expect($config['analytics'])->toHaveKey('event_consistency');
    });

    test('config has feature_gating section', function (): void {
        $config = include __DIR__ . '/../../config/zeroboiler.php';
        expect($config['analytics'])->toHaveKey('feature_gating');
        expect($config['analytics']['feature_gating'])->toHaveKeys(['enabled', 'plan_hierarchy', 'premium_categories', 'plans']);
    });

    test('config has customer_success section', function (): void {
        $config = include __DIR__ . '/../../config/zeroboiler.php';
        expect($config['analytics'])->toHaveKey('customer_success');
    });

    test('config has pipeline_profiler section', function (): void {
        $config = include __DIR__ . '/../../config/zeroboiler.php';
        expect($config['analytics'])->toHaveKey('pipeline_profiler');
    });

    test('config has event_reliability section', function (): void {
        $config = include __DIR__ . '/../../config/zeroboiler.php';
        expect($config['analytics'])->toHaveKey('event_reliability');
    });

    test('config has event_costs section', function (): void {
        $config = include __DIR__ . '/../../config/zeroboiler.php';
        expect($config['analytics'])->toHaveKey('event_costs');
    });
});

// ─── env() Coverage ─────────────────────────────────────────────────────

describe('Phase 38 — env() Coverage', function (): void {
    test('config uses env() for all 10 provider enable toggles', function (): void {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        $envVars = [
            'ANALYTICS_GA4_ENABLED',
            'ANALYTICS_GTM_ENABLED',
            'ANALYTICS_META_PIXEL_ENABLED',
            'ANALYTICS_PLAUSIBLE_ENABLED',
            'ANALYTICS_POSTHOG_ENABLED',
            'ANALYTICS_MIXPANEL_ENABLED',
            'ANALYTICS_AMPLITUDE_ENABLED',
            'ANALYTICS_TIKTOK_ENABLED',
            'ANALYTICS_LINKEDIN_ENABLED',
            'ANALYTICS_WEBHOOK_ENABLED',
        ];
        foreach ($envVars as $var) {
            expect($content)->toContain($var, "Missing env() for {$var}");
        }
    });

    test('config has extensive env() coverage (1400+ env() calls)', function (): void {
        $content = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        $count = substr_count($content, 'env(');
        expect($count)->toBeGreaterThan(1400);
    });
});

// ─── License Headers ─────────────────────────────────────────────────────

describe('Phase 38 — License Header Coverage', function (): void {
    test('all source files have MIT license header', function (): void {
        $files = glob($this->srcDir . '/**/*.php', GLOB_BRACE);
        $violations = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (!str_contains($content, 'licensed under the MIT license')) {
                $violations[] = str_replace($this->srcDir . '/', '', $file);
            }
        }
        expect($violations)->toBeEmpty('Files missing MIT license: ' . implode(', ', array_slice($violations, 0, 5)));
    });
});

// ─── TODO/FIXME Absence ──────────────────────────────────────────────────

describe('Phase 38 — TODO/FIXME Absence', function (): void {
    test('no TODO markers in source files', function (): void {
        $files = glob($this->srcDir . '/**/*.php', GLOB_BRACE);
        $violations = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (str_contains($content, 'TODO')) {
                $violations[] = str_replace($this->srcDir . '/', '', $file);
            }
        }
        expect($violations)->toBeEmpty('Files with TODO: ' . implode(', ', array_slice($violations, 0, 5)));
    });

    test('no FIXME markers in source files', function (): void {
        $files = glob($this->srcDir . '/**/*.php', GLOB_BRACE);
        $violations = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (str_contains($content, 'FIXME')) {
                $violations[] = str_replace($this->srcDir . '/', '', $file);
            }
        }
        expect($violations)->toBeEmpty('Files with FIXME: ' . implode(', ', array_slice($violations, 0, 5)));
    });
});

// ─── @since Annotation Completeness ──────────────────────────────────────

describe('Phase 38 — @since Annotation Completeness', function (): void {
    test('all public classes have @since annotation', function (): void {
        $files = glob($this->srcDir . '/**/*.php', GLOB_BRACE);
        $violations = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (preg_match('/^(final\s+)?(readonly\s+)?(abstract\s+)?(class|interface|trait|enum)\s+(\w+)/m', $content, $m)) {
                if (!str_contains($content, '@since')) {
                    $violations[] = str_replace($this->srcDir . '/', '', $file) . " ({$m[5]})";
                }
            }
        }
        expect($violations)->toBeEmpty('Classes missing @since: ' . implode(', ', array_slice($violations, 0, 5)));
    });
});

// ─── Event Catalog Integrity ──────────────────────────────────────────────

describe('Phase 38 — Event Catalog Integrity', function (): void {
    test('EventCatalog has 210+ events', function (): void {
        expect(EventCatalog::count())->toBeGreaterThanOrEqual(210);
    });

    test('EventCatalog has all 8 categories', function (): void {
        $categories = ['ecommerce', 'saas', 'engagement', 'security', 'uptime', 'infrastructure', 'marketing', 'customer_success'];
        $summary = EventCatalog::categorySummary();
        foreach ($categories as $cat) {
            expect($summary)->toHaveKey($cat, "Missing category: {$cat}");
        }
    });

    test('EventCatalog::all returns consistent structure', function (): void {
        $catalog = EventCatalog::all();
        foreach ($catalog as $name => $entry) {
            expect($entry)->toHaveKey('name');
            expect($entry)->toHaveKey('class');
            expect($entry)->toHaveKey('category');
            expect($entry['name'])->toBe($name);
        }
    });
});

// ─── Cross-Reference Integrity ──────────────────────────────────────────

describe('Phase 38 — Cross-Reference Integrity', function (): void {
    test('ServiceProvider registers core services as singletons', function (): void {
        $content = file_get_contents($this->srcDir . '/AnalyticsServiceProvider.php');
        expect($content)->toContain("singleton('zeroboiler.analytics'");
        expect($content)->toContain('singleton(GoogleAnalyticsService::class');
        expect($content)->toContain('singleton(MetaPixelService::class');
        expect($content)->toContain('singleton(GoogleTagManagerService::class');
        expect($content)->toContain('singleton(EcommerceAnalyticsService::class');
    });

    test('ServiceProvider registers event store manager', function (): void {
        $content = file_get_contents($this->srcDir . '/AnalyticsServiceProvider.php');
        expect($content)->toContain('EventStoreManager');
    });

    test('ServiceProvider publishes config', function (): void {
        $content = file_get_contents($this->srcDir . '/AnalyticsServiceProvider.php');
        expect($content)->toContain('zeroboiler-analytics-config');
    });
});

// ─── Project Structure Files ─────────────────────────────────────────────

describe('Phase 38 — Project Structure Files', function (): void {
    test('LICENSE file exists', function (): void {
        expect(file_exists(__DIR__ . '/../../LICENSE'))->toBeTrue();
    });

    test('README.md exists', function (): void {
        expect(file_exists(__DIR__ . '/../../README.md'))->toBeTrue();
    });

    test('.gitignore exists', function (): void {
        expect(file_exists(__DIR__ . '/../../.gitignore'))->toBeTrue();
    });
});
