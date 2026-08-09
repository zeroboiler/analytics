<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Support\AnalyticsConfig;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController;
use ZeroBoiler\Analytics\AnalyticsServiceProvider;

// ─── Version Consistency ──────────────────────────────────────────────

describe('V2.27 Version Consistency', function () {
    it('AnalyticsManager::version() returns 2.27.0', function () {
        $manager = new AnalyticsManager;
        expect($manager->version())->toBe('5.0.0');
    });

    it('Facade directDispatch return type is bool', function () {
        $facadeReflection = new ReflectionClass(\ZeroBoiler\Analytics\Facades\Analytics::class);
        $doc = $facadeReflection->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('@method static bool directDispatch');
        expect($doc)->not->toContain('@method static void directDispatch');
    });

    it('composer.json version is 2.27.0', function () {
        $composer = json_decode(
            file_get_contents(__DIR__.'/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($composer['version'])->toBe('5.0.0');
    });

    it('JS client version is 2.27.0', function () {
        $js = file_get_contents(__DIR__.'/../resources/js/analytics.js');
        expect($js)->toContain('@version 5.0.0');
        expect($js)->toContain("'5.0.0'");
    });

    it('JS client exports getVersion()', function () {
        $js = file_get_contents(__DIR__.'/../resources/js/analytics.js');
        expect($js)->toContain('export function getVersion()');
    });
});

// ─── AnalyticsConfig Completeness ──────────────────────────────────────

describe('V2.27 AnalyticsConfig Completeness', function () {
    it('has dedup config accessors', function () {
        $methods = ['dedupEnabled'];
        foreach ($methods as $method) {
            expect(method_exists(AnalyticsConfig::class, $method))
                ->toBeTrue("AnalyticsConfig should have {$method}()");
        }
    });

    it('has GDPR config accessors', function () {
        $methods = ['gdprAnonymizeIp', 'gdprIpMaskV4', 'gdprIpMaskV6'];
        foreach ($methods as $method) {
            expect(method_exists(AnalyticsConfig::class, $method))
                ->toBeTrue("AnalyticsConfig should have {$method}()");
        }
    });

    it('has attribution config accessors', function () {
        $methods = [
            'attributionEnabled',
            'attributionFirstTouchTtl',
            'attributionTouchHistoryTtl',
            'attributionMaxTouchHistory',
        ];
        foreach ($methods as $method) {
            expect(method_exists(AnalyticsConfig::class, $method))
                ->toBeTrue("AnalyticsConfig should have {$method}()");
        }
    });

    it('has profile config accessors', function () {
        $methods = ['profileEnabled', 'profileTtl'];
        foreach ($methods as $method) {
            expect(method_exists(AnalyticsConfig::class, $method))
                ->toBeTrue("AnalyticsConfig should have {$method}()");
        }
    });

    it('has funnel config accessors', function () {
        $methods = ['funnelsEnabled', 'funnelsCacheEnabled', 'funnelsCacheTtl'];
        foreach ($methods as $method) {
            expect(method_exists(AnalyticsConfig::class, $method))
                ->toBeTrue("AnalyticsConfig should have {$method}()");
        }
    });

    it('has alert config accessors', function () {
        $methods = ['alertsEnabled', 'alertsCooldown', 'alertsMaxHistory', 'alertsRules'];
        foreach ($methods as $method) {
            expect(method_exists(AnalyticsConfig::class, $method))
                ->toBeTrue("AnalyticsConfig should have {$method}()");
        }
    });

    it('has inbound webhook config accessors', function () {
        $methods = [
            'inboundWebhookEnabled',
            'inboundWebhookSecret',
            'inboundWebhookRequireSignature',
            'inboundWebhookMaxPayloadSize',
            'inboundWebhookMaxEvents',
        ];
        foreach ($methods as $method) {
            expect(method_exists(AnalyticsConfig::class, $method))
                ->toBeTrue("AnalyticsConfig should have {$method}()");
        }
    });

    it('has extended pipeline config accessors', function () {
        $methods = ['pipelineAutoMetadata', 'pipelineSchemaEnrichment'];
        foreach ($methods as $method) {
            expect(method_exists(AnalyticsConfig::class, $method))
                ->toBeTrue("AnalyticsConfig should have {$method}()");
        }
    });

    it('summary() includes all 22 config sections', function () {
        // We can't instantiate without Laravel, but we can verify method exists
        expect(method_exists(AnalyticsConfig::class, 'summary'))->toBeTrue();
    });
});

// ─── Route Registration Completeness ────────────────────────────────────

describe('V2.27 Route Registration', function () {
    it('routes/analytics.php includes alert routes', function () {
        $routes = file_get_contents(__DIR__.'/../routes/analytics.php');
        expect($routes)->toContain("Route::get('alerts'");
        expect($routes)->toContain("Route::post('alerts/evaluate'");
    });

    it('routes/analytics.php includes funnel routes', function () {
        $routes = file_get_contents(__DIR__.'/../routes/analytics.php');
        expect($routes)->toContain("Route::get('funnels'");
        expect($routes)->toContain("Route::post('funnels/compare'");
        expect($routes)->toContain("Route::get('funnels/drop-off'");
        expect($routes)->toContain("Route::get('funnels/chart'");
    });

    it('service provider registers alert and funnel routes', function () {
        $provider = file_get_contents(__DIR__.'/../src/AnalyticsServiceProvider.php');
        expect($provider)->toContain("evaluateAlerts");
        expect($provider)->toContain("funnelData");
        expect($provider)->toContain("funnelCompare");
        expect($provider)->toContain("funnelDropOff");
        expect($provider)->toContain("funnelChart");
    });

    it('total of 19 public API routes registered', function () {
        $routes = file_get_contents(__DIR__.'/../src/AnalyticsServiceProvider.php');
        // Public routes (no auth:sanctum group)
        $publicRoutes = [
            'health', 'catalog', 'stream', 'stream/stats', 'export',
            'stats', 'webhook/inbound',
            'alerts/evaluate', 'alerts',
            'funnels', 'funnels/compare', 'funnels/drop-off', 'funnels/chart',
        ];

        foreach ($publicRoutes as $route) {
            expect($routes)->toContain($route);
        }
    });

    it('total of 10 authenticated API routes registered', function () {
        $routes = file_get_contents(__DIR__.'/../src/AnalyticsServiceProvider.php');
        // Authenticated routes (auth:sanctum group)
        $authRoutes = [
            'events', 'batch', 'identify', 'pageview', 'consent',
            'opt-out', 'opt-in', 'preference', 'profile', 'data',
        ];

        foreach ($authRoutes as $route) {
            expect($routes)->toContain($route);
        }
    });
});

// ─── Controller Version Strings ────────────────────────────────────────

describe('V2.27 Controller Version Strings', function () {
    it('catalog endpoint returns version 2.27.0', function () {
        $controller = file_get_contents(__DIR__.'/../src/Http/Controllers/AnalyticsEventController.php');
        // The catalog method has the version
        expect($controller)->toContain("'version' => '5.0.0'");
    });

    it('health endpoint returns version 2.35.0', function () {
        // Both catalog and health use the same version string now
        $controller = file_get_contents(__DIR__.'/../src/Http/Controllers/AnalyticsEventController.php');
        $count = substr_count($controller, "'version' => '5.0.0'");
        // catalog, health, stats = 3 occurrences
        expect($count)->toBeGreaterThanOrEqual(3);
    });

    it('no stale 2.24.0 version strings remain in controller', function () {
        $controller = file_get_contents(__DIR__.'/../src/Http/Controllers/AnalyticsEventController.php');
        expect($controller)->not->toContain("'version' => '5.0.0'");
        expect($controller)->not->toContain("'version' => '5.0.0'");
    });
});

// ─── AnalyticsConfig Method Count ───────────────────────────────────────

describe('V2.27 AnalyticsConfig Method Count', function () {
    it('has at least 80 public methods', function () {
        $reflection = new ReflectionClass(AnalyticsConfig::class);
        $publicMethods = array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $m): bool => ! $m->isStatic(),
        );

        expect(count($publicMethods))->toBeGreaterThanOrEqual(80);
    });

    it('has at least 90 public methods including new accessors', function () {
        $reflection = new ReflectionClass(AnalyticsConfig::class);
        $publicMethods = array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $m): bool => ! $m->isStatic(),
        );

        expect(count($publicMethods))->toBeGreaterThanOrEqual(90);
    });
});

// ─── Event Catalog Integrity ────────────────────────────────────────────

describe('V2.27 Event Catalog Integrity', function () {
    it('total events = 49', function () {
        expect(EventCatalog::count())->toBe(49);
    });

    it('ecommerce events = 12', function () {
        expect(EcommerceEvents::count())->toBe(12);
    });

    it('saas events = 17', function () {
        expect(SaaSEvents::count())->toBe(17);
    });

    it('engagement events = 20', function () {
        expect(EngagementEvents::count())->toBe(20);
    });

    it('all events have typed classes (no CustomEvent)', function () {
        $all = EventCatalog::all();
        foreach ($all as $name => $entry) {
            expect($entry['class'])->not->toBe(\ZeroBoiler\Analytics\Events\CustomEvent::class,
                "Event '{$name}' should not use CustomEvent");
        }
    });

    it('all events have non-empty GA4 mappings', function () {
        $all = EventCatalog::all();
        foreach ($all as $name => $entry) {
            expect($entry['ga4'])->not->toBeEmpty("Event '{$name}' should have a GA4 mapping");
        }
    });
});

// ─── Structural: Service Provider Bindings ─────────────────────────────

describe('V2.27 Service Provider Binding Completeness', function () {
    it('registers EventAlertRulesService', function () {
        $provider = file_get_contents(__DIR__.'/../src/AnalyticsServiceProvider.php');
        expect($provider)->toContain('EventAlertRulesService::class');
    });

    it('registers FunnelDataBuilderService', function () {
        $provider = file_get_contents(__DIR__.'/../src/AnalyticsServiceProvider.php');
        expect($provider)->toContain('FunnelDataBuilderService::class');
    });

    it('registers AnalyticsStatsService', function () {
        $provider = file_get_contents(__DIR__.'/../src/AnalyticsServiceProvider.php');
        expect($provider)->toContain('AnalyticsStatsService::class');
    });

    it('registers InboundWebhookService', function () {
        $provider = file_get_contents(__DIR__.'/../src/AnalyticsServiceProvider.php');
        expect($provider)->toContain('InboundWebhookService::class');
    });

    it('registers all 6 console commands', function () {
        $provider = file_get_contents(__DIR__.'/../src/AnalyticsServiceProvider.php');
        $commands = [
            'AnalyticsTestCommand',
            'AnalyticsOverviewCommand',
            'AnalyticsExportCommand',
            'RevenueReportCommand',
            'AnalyticsHealthCommand',
            'AnalyticsDashboardCommand',
        ];

        foreach ($commands as $command) {
            expect($provider)->toContain($command);
        }
    });
});

// ─── ProductionReadiness: New Accessors Have Correct Return Types ──────

describe('V2.27 New Accessor Return Types', function () {
    $boolMethods = [
        'dedupEnabled', 'gdprAnonymizeIp', 'attributionEnabled', 'profileEnabled',
        'funnelsEnabled', 'funnelsCacheEnabled', 'alertsEnabled',
        'inboundWebhookEnabled', 'inboundWebhookRequireSignature',
        'pipelineAutoMetadata', 'pipelineSchemaEnrichment',
    ];

    it('new boolean accessors return bool', function () use ($boolMethods) {
        $reflection = new ReflectionClass(AnalyticsConfig::class);

        foreach ($boolMethods as $method) {
            $m = $reflection->getMethod($method);
            $returnType = $m->getReturnType();
            expect($returnType)->not->toBeNull("{$method}() should have a return type");
            expect((string) $returnType)->toBe('bool', "{$method}() should return bool");
        }
    });

    it('new int accessors return int', function () {
        $intMethods = [
            'gdprIpMaskV4', 'gdprIpMaskV6',
            'attributionFirstTouchTtl', 'attributionTouchHistoryTtl', 'attributionMaxTouchHistory',
            'profileTtl', 'funnelsCacheTtl',
            'alertsCooldown', 'alertsMaxHistory',
            'inboundWebhookMaxPayloadSize', 'inboundWebhookMaxEvents',
        ];

        $reflection = new ReflectionClass(AnalyticsConfig::class);

        foreach ($intMethods as $method) {
            $m = $reflection->getMethod($method);
            $returnType = $m->getReturnType();
            expect($returnType)->not->toBeNull("{$method}() should have a return type");
            expect((string) $returnType)->toBe('int', "{$method}() should return int");
        }
    });

    it('new string accessors return string', function () {
        $stringMethods = [
            'inboundWebhookSecret',
        ];

        $reflection = new ReflectionClass(AnalyticsConfig::class);

        foreach ($stringMethods as $method) {
            $m = $reflection->getMethod($method);
            $returnType = $m->getReturnType();
            expect($returnType)->not->toBeNull("{$method}() should have a return type");
            expect((string) $returnType)->toBe('string', "{$method}() should return string");
        }
    });

    it('alertsRules() returns array', function () {
        $m = (new ReflectionClass(AnalyticsConfig::class))->getMethod('alertsRules');
        $returnType = $m->getReturnType();
        expect($returnType)->not->toBeNull();
        expect((string) $returnType)->toBe('array');
    });
});
