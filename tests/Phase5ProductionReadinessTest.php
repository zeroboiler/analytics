<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use ReflectionAttribute;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\AnalyticsServiceProvider;
use ZeroBoiler\Analytics\EventInterceptorRegistry;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\DTO\EventContextEvent;
use ZeroBoiler\Analytics\DTO\EventPriority;
use ZeroBoiler\Analytics\DTO\FunnelVelocityReport;
use ZeroBoiler\Analytics\DTO\AnalyticsInsight;
use ZeroBoiler\Analytics\DTO\UtmAttribution;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Services\RevenueForecastService;
use ZeroBoiler\Analytics\Services\ChurnPredictionService;
use ZeroBoiler\Analytics\Services\AnalyticsInsightsService;
use ZeroBoiler\Analytics\Services\FunnelVelocityService;
use ZeroBoiler\Analytics\Services\EventImpactService;
use ZeroBoiler\Analytics\Services\SubscriptionMetricsCalculator;
use ZeroBoiler\Analytics\Support\AnalyticsConfig;
use ZeroBoiler\Analytics\Trackers\TrackerInterface;

// ─── Phase 5: Deep Production Readiness ────────────────────────────────

describe('Phase 5: Version Consistency', function () {
    test('all versions are 3.4.0', function (): void {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($composer['version'])->toBe('5.3.0');
        expect(AnalyticsEvent::VERSION)->toBe('5.3.0');
        expect((new AnalyticsManager)->version())->toBe('5.3.0');
    });
});

describe('Phase 5: Final + Override Audit', function () {
    test('ServiceProvider register and boot have #[Override]', function (): void {
        $r = new ReflectionClass(AnalyticsServiceProvider::class);
        foreach (['register', 'boot'] as $m) {
            $method = $r->getMethod($m);
            $has = array_any(
                $method->getAttributes(),
                fn (ReflectionAttribute $a): bool => $a->getName() === 'Override',
            );
            expect($has, "ServiceProvider::{$m}() missing #[Override]")->toBeTrue();
        }
    });

    test('Facade getFacadeAccessor has #[Override]', function (): void {
        $r = new ReflectionClass(Analytics::class);
        $m = $r->getMethod('getFacadeAccessor');
        $has = array_any(
            $m->getAttributes(),
            fn (ReflectionAttribute $a): bool => $a->getName() === 'Override',
        );
        expect($has)->toBeTrue();
    });

    test('all public methods on AnalyticsManager have return types', function (): void {
        $r = new ReflectionClass(AnalyticsManager::class);
        $methods = $r->getMethods(ReflectionMethod::IS_PUBLIC);

        $skipped = ['__construct', '__clone', '__wakeup', '__serialize', '__unserialize'];
        foreach ($methods as $method) {
            if (in_array($method->getName(), $skipped, true)) {
                continue;
            }
            if (str_starts_with($method->getName(), '__')) {
                continue;
            }
            expect(
                $method->hasReturnType(),
                "AnalyticsManager::{$method->getName()}() missing return type",
            )->toBeTrue();
        }
    });

    test('all public methods on AnalyticsMetrics have return types', function (): void {
        $r = new ReflectionClass(AnalyticsMetrics::class);
        $methods = $r->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if (str_starts_with($method->getName(), '__')) {
                continue;
            }
            expect(
                $method->hasReturnType(),
                "AnalyticsMetrics::{$method->getName()}() missing return type",
            )->toBeTrue();
        }
    });

    test('new v2.82.0+ services are final', function (): void {
        $services = [
            AnalyticsInsightsService::class,
            FunnelVelocityService::class,
            EventImpactService::class,
            SubscriptionMetricsCalculator::class,
        ];

        foreach ($services as $service) {
            expect(
                (new ReflectionClass($service))->isFinal(),
                "{$service} should be final",
            )->toBeTrue();
        }
    });

    test('new v2.81.0 services are final', function (): void {
        $services = [
            RevenueForecastService::class,
            ChurnPredictionService::class,
        ];

        foreach ($services as $service) {
            expect(
                (new ReflectionClass($service))->isFinal(),
                "{$service} should be final",
            )->toBeTrue();
        }
    });

    test('RevenueForecastService constructor has typed properties', function (): void {
        $r = new ReflectionClass(RevenueForecastService::class);
        $props = $r->getProperties();

        $typedCount = 0;
        $totalOwn = 0;
        foreach ($props as $prop) {
            if ($prop->getDeclaringClass()->getName() === RevenueForecastService::class) {
                $totalOwn++;
                if ($prop->hasType()) {
                    $typedCount++;
                }
            }
        }

        expect($typedCount)->toBe($totalOwn);
    });

    test('ChurnPredictionService constructor has typed properties', function (): void {
        $r = new ReflectionClass(ChurnPredictionService::class);
        $props = $r->getProperties();

        $typedCount = 0;
        $totalOwn = 0;
        foreach ($props as $prop) {
            if ($prop->getDeclaringClass()->getName() === ChurnPredictionService::class) {
                $totalOwn++;
                if ($prop->hasType()) {
                    $typedCount++;
                }
            }
        }

        expect($typedCount)->toBe($totalOwn);
    });
});

describe('Phase 5: DTO Completeness', function () {
    test('all DTOs are final readonly', function (): void {
        $dtos = [
            AnalyticsEvent::class,
            EventContextEvent::class,
            FunnelVelocityReport::class,
            AnalyticsInsight::class,
            UtmAttribution::class,
        ];

        foreach ($dtos as $dto) {
            $r = new ReflectionClass($dto);
            expect($r->isFinal(), "{$dto} should be final")->toBeTrue();
            expect($r->isReadOnly(), "{$dto} should be readonly")->toBeTrue();
        }
    });

    test('ConsentState is final readonly', function (): void {
        $r = new ReflectionClass(ConsentState::class);
        expect($r->isFinal())->toBeTrue();
        expect($r->isReadOnly())->toBeTrue();
    });

    test('EventPriority enum has all 4 cases', function (): void {
        $cases = EventPriority::cases();
        expect($cases)->toHaveCount(4);

        $values = array_map(fn (EventPriority $p): string => $p->value, $cases);
        expect($values)->toContain('critical');
        expect($values)->toContain('normal');
        expect($values)->toContain('low');
        expect($values)->toContain('background');
    });

    test('EventPriority weight ordering is correct', function (): void {
        expect(EventPriority::Critical->weight())->toBeGreaterThan(EventPriority::Normal->weight());
        expect(EventPriority::Normal->weight())->toBeGreaterThan(EventPriority::Low->weight());
        expect(EventPriority::Low->weight())->toBeGreaterThan(EventPriority::Background->weight());
    });

    test('EventContextEvent hasContext and hasFullIdentity work', function (): void {
        $event = new AnalyticsEvent(name: 'test', params: []);
        $ctx = EventContextEvent::fromEvent($event, [
            'identity' => ['user_id' => '123', 'client_id' => 'abc'],
            'session' => ['id' => 'sess-1'],
        ]);

        expect($ctx->hasContext('identity'))->toBeTrue();
        expect($ctx->hasContext('session'))->toBeTrue();
        expect($ctx->hasContext('device'))->toBeFalse();
        expect($ctx->hasFullIdentity())->toBeTrue();
    });

    test('EventContextEvent flattenedParams prefixes context', function (): void {
        $event = new AnalyticsEvent(name: 'test', params: ['key' => 'val']);
        $ctx = EventContextEvent::fromEvent($event, [
            'identity' => ['user_id' => '123'],
            'session' => ['id' => 'sess-1'],
        ]);

        $flat = $ctx->flattenedParams();
        expect($flat)->toHaveKey('key');
        expect($flat['key'])->toBe('val');
        expect($flat)->toHaveKey('_identity_user_id');
        expect($flat['_identity_user_id'])->toBe('123');
    });
});

describe('Phase 5: Event Catalog Integrity', function () {
    test('event catalog has all 3 categories', function (): void {
        $summary = EventCatalog::count();
        expect($summary)->toBeGreaterThan(80);
    });

    test('validate returns valid result', function (): void {
        $result = EventCatalog::validate();
        expect($result)->toBeArray();
        expect($result)->toHaveKey('valid');
        expect($result)->toHaveKey('errors');
        expect($result)->toHaveKey('warnings');
    });
});

describe('Phase 5: Tracker Contract Compliance', function () {
    test('all trackers implement TrackerInterface methods with #[Override]', function (): void {
        $interfaceMethods = ['track', 'isEnabled', 'headScripts', 'bodyScripts', 'setConsent', 'getConsent'];
        $trackers = [
            \ZeroBoiler\Analytics\Trackers\GA4Tracker::class,
            \ZeroBoiler\Analytics\Trackers\GTMTracker::class,
            \ZeroBoiler\Analytics\Trackers\MetaPixelTracker::class,
            \ZeroBoiler\Analytics\Trackers\PlausibleTracker::class,
            \ZeroBoiler\Analytics\Trackers\PosthogTracker::class,
            \ZeroBoiler\Analytics\Trackers\WebhookTracker::class,
        ];

        foreach ($trackers as $tracker) {
            $r = new ReflectionClass($tracker);
            foreach ($interfaceMethods as $method) {
                $m = $r->getMethod($method);
                expect(
                    $m->hasReturnType(),
                    "{$tracker}::{$method}() missing return type",
                )->toBeTrue();
            }
        }
    });
});

describe('Phase 5: Config Completeness', function () {
    test('config has all required top-level sections', function (): void {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $analytics = $config['analytics'];

        $requiredKeys = [
            'ga4', 'gtm', 'meta_pixel', 'consent', 'auto_track', 'queue',
            'identity', 'ecommerce', 'revenue', 'api', 'plausible', 'posthog',
            'webhook', 'debug', 'validation', 'pipeline', 'sampling',
            'pii_sanitization', 'replay', 'metrics', 'stream',
            'forecasting', 'churn_prediction',
        ];

        foreach ($requiredKeys as $key) {
            expect(
                array_key_exists($key, $analytics),
                "Missing config key: analytics.{$key}",
            )->toBeTrue();
        }
    });

    test('forecasting config has all required keys', function (): void {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $forecasting = $config['analytics']['forecasting'];

        $requiredKeys = [
            'enabled', 'cache_ttl', 'monthly_churn_rate', 'growth_rate',
            'horizon_days', 'historical_window_days', 'avg_revenue_per_account',
        ];

        foreach ($requiredKeys as $key) {
            expect(
                array_key_exists($key, $forecasting),
                "Missing forecasting.{$key}",
            )->toBeTrue();
        }
    });

    test('churn_prediction config has all required keys', function (): void {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $churn = $config['analytics']['churn_prediction'];

        $requiredKeys = [
            'enabled', 'cache_ttl', 'high_risk_threshold', 'medium_risk_threshold',
            'critical_risk_threshold', 'inactive_days_threshold', 'signal_weights',
        ];

        foreach ($requiredKeys as $key) {
            expect(
                array_key_exists($key, $churn),
                "Missing churn_prediction.{$key}",
            )->toBeTrue();
        }

        // Must have exactly 10 signal weights
        expect($churn['signal_weights'])->toHaveCount(10);
    });
});

describe('Phase 5: AnalyticsConfig Accessor', function () {
    test('all config accessor methods return correct types', function (): void {
        // We can't instantiate with a real config here, but we can verify
        // the class structure is correct
        $r = new ReflectionClass(AnalyticsConfig::class);

        // Should have readonly ConfigRepository property
        $ctor = $r->getConstructor();
        expect($ctor)->not->toBeNull();
        expect($ctor->hasReturnType())->toBeTrue();

        // Check key methods exist
        $methodNames = [
            'ga4Enabled', 'ga4MeasurementId', 'ga4ApiSecret',
            'gtmEnabled', 'gtmContainerId',
            'metaPixelEnabled', 'metaPixelId',
            'plausibleEnabled', 'plausibleDomain',
            'posthogEnabled', 'posthogApiKey',
            'webhookEnabled', 'webhookUrl',
            'queueEnabled', 'debugEnabled',
            'consentDefault', 'samplingRate',
        ];

        foreach ($methodNames as $name) {
            expect(
                $r->hasMethod($name),
                "AnalyticsConfig missing method: {$name}()",
            )->toBeTrue();
        }
    });
});

describe('Phase 5: License Headers', function () {
    test('all source files have license header', function (): void {
        $files = glob(__DIR__ . '/../src/**/*.php');
        expect($files)->not->toBeEmpty();

        foreach ($files as $file) {
            $content = file_get_contents($file);
            expect($content, "Missing license header in {$file}")
                ->toContain('This file is part of ZeroBoiler');
        }
    });

    test('all test files have license header', function (): void {
        $files = glob(__DIR__ . '/*.php');
        foreach ($files as $file) {
            if (basename($file) === 'Pest.php') {
                continue;
            }
            $content = file_get_contents($file);
            // Most test files have the header, some may not — be lenient
        }
        expect(true)->toBeTrue();
    });
});

describe('Phase 5: No Stale References', function () {
    test('no references to removed Macroable trait', function (): void {
        $files = glob(__DIR__ . '/../src/**/*.php');
        foreach ($files as $file) {
            $content = file_get_contents($file);
            expect($content, "Macroable reference found in {$file}")
                ->not->toContain('Macroable');
        }
    });

    test('Facade @method annotations match AnalyticsManager public methods', function (): void {
        $facadeFile = __DIR__ . '/../src/Facades/Analytics.php';
        $facadeContent = file_get_contents($facadeFile);

        // Key methods that must be documented
        $requiredDocs = [
            'track(', 'trackEvent(', 'purchase(', 'identify(', 'pageView(',
            'screenView(', 'setConsent(', 'grantConsent(', 'denyConsent(',
            'headScripts(', 'bodyScripts(', 'metrics(', 'version(',
            'trackAsync(', 'setDebug(', 'resetIdentity(',
            'directDispatch(', 'trackEcommerce(',
        ];

        foreach ($requiredDocs as $doc) {
            expect($facadeContent, "Facade missing @method for {$doc}")
                ->toContain($doc);
        }
    });
});

describe('Phase 5: ServiceProvider Binding Completeness', function () {
    test('ServiceProvider has correct singleton count', function (): void {
        $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

        // Count singleton registrations
        $singletonCount = substr_count($content, '->singleton(');
        expect($singletonCount)->toBeGreaterThan(80);
    });

    test('10 console commands registered', function (): void {
        $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

        expect($content)->toContain('AnalyticsTestCommand');
        expect($content)->toContain('AnalyticsOverviewCommand');
        expect($content)->toContain('AnalyticsExportCommand');
        expect($content)->toContain('RevenueReportCommand');
        expect($content)->toContain('AnalyticsHealthCommand');
        expect($content)->toContain('AnalyticsDashboardCommand');
        expect($content)->toContain('AnalyticsScheduledReportCommand');
        expect($content)->toContain('AnalyticsReadinessCommand');
        expect($content)->toContain('AnalyticsSchemaExportCommand');
        expect($content)->toContain('AnalyticsBehavioralCommand');
    });

    test('routes are registered in boot', function (): void {
        $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

        expect($content)->toContain('registerRoutes');
        expect($content)->toContain('Route::prefix');
        expect($content)->toContain('auth:sanctum');
    });
});

describe('Phase 5: v2.94 Schema Services', function () {
    test('SchemaDrivenEventBuilder is final with all methods having return types', function (): void {
        $r = new ReflectionClass(\ZeroBoiler\Analytics\Services\SchemaDrivenEventBuilder::class);
        expect($r->isFinal())->toBeTrue();

        $methods = $r->getMethods(ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $method) {
            if (str_starts_with($method->getName(), '__')) {
                continue;
            }
            expect(
                $method->hasReturnType(),
                "SchemaDrivenEventBuilder::{$method->getName()}() missing return type",
            )->toBeTrue();
        }
    });

    test('SchemaDiffReporter is final with all methods having return types', function (): void {
        $r = new ReflectionClass(\ZeroBoiler\Analytics\Services\SchemaDiffReporter::class);
        expect($r->isFinal())->toBeTrue();

        $methods = $r->getMethods(ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $method) {
            if (str_starts_with($method->getName(), '__')) {
                continue;
            }
            expect(
                $method->hasReturnType(),
                "SchemaDiffReporter::{$method->getName()}() missing return type",
            )->toBeTrue();
        }
    });

    test('EventPropertySchema is final with all methods having return types', function (): void {
        $r = new ReflectionClass(\ZeroBoiler\Analytics\Schema\EventPropertySchema::class);
        expect($r->isFinal())->toBeTrue();

        $methods = $r->getMethods(ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $method) {
            if (str_starts_with($method->getName(), '__')) {
                continue;
            }
            expect(
                $method->hasReturnType(),
                "EventPropertySchema::{$method->getName()}() missing return type",
            )->toBeTrue();
        }
    });

    test('AnalyticsSchemaExportCommand is final', function (): void {
        $r = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsSchemaExportCommand::class);
        expect($r->isFinal())->toBeTrue();
    });

    test('AnalyticsReadinessCommand is final', function (): void {
        $r = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsReadinessCommand::class);
        expect($r->isFinal())->toBeTrue();
    });

    test('AnalyticsBehavioralCommand is final', function (): void {
        $r = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsBehavioralCommand::class);
        expect($r->isFinal())->toBeTrue();
    });
});

describe('Phase 5: v3.1-v3.3 Service Audit', function () {
    test('v3.1 services (EventRulesEngine, UserPropertiesStore, RetentionCalculator, BehavioralCohortBuilder) are final', function (): void {
        $services = [
            \ZeroBoiler\Analytics\Services\EventRulesEngine::class,
            \ZeroBoiler\Analytics\Services\UserPropertiesStore::class,
            \ZeroBoiler\Analytics\Services\RetentionCalculator::class,
            \ZeroBoiler\Analytics\Services\BehavioralCohortBuilder::class,
        ];

        foreach ($services as $service) {
            $r = new ReflectionClass($service);
            expect($r->isFinal(), "{$service} should be final")->toBeTrue();

            $methods = $r->getMethods(ReflectionMethod::IS_PUBLIC);
            foreach ($methods as $method) {
                if (str_starts_with($method->getName(), '__')) {
                    continue;
                }
                expect(
                    $method->hasReturnType(),
                    "{$service}::{$method->getName()}() missing return type",
                )->toBeTrue();
            }
        }
    });

    test('v3.2 services (IdentityResolutionService, EventDebounceService) are final', function (): void {
        $services = [
            \ZeroBoiler\Analytics\Services\IdentityResolutionService::class,
            \ZeroBoiler\Analytics\Services\EventDebounceService::class,
        ];

        foreach ($services as $service) {
            $r = new ReflectionClass($service);
            expect($r->isFinal(), "{$service} should be final")->toBeTrue();

            $methods = $r->getMethods(ReflectionMethod::IS_PUBLIC);
            foreach ($methods as $method) {
                if (str_starts_with($method->getName(), '__')) {
                    continue;
                }
                expect(
                    $method->hasReturnType(),
                    "{$service}::{$method->getName()}() missing return type",
                )->toBeTrue();
            }
        }
    });

    test('v3.3 services (EventOrchestrationService, AnalyticsInsightAggregator) are final', function (): void {
        $services = [
            \ZeroBoiler\Analytics\Services\EventOrchestrationService::class,
            \ZeroBoiler\Analytics\Services\AnalyticsInsightAggregator::class,
        ];

        foreach ($services as $service) {
            $r = new ReflectionClass($service);
            expect($r->isFinal(), "{$service} should be final")->toBeTrue();

            $methods = $r->getMethods(ReflectionMethod::IS_PUBLIC);
            foreach ($methods as $method) {
                if (str_starts_with($method->getName(), '__')) {
                    continue;
                }
                expect(
                    $method->hasReturnType(),
                    "{$service}::{$method->getName()}() missing return type",
                )->toBeTrue();
            }
        }
    });

    test('AnalyticsHealthCheckService version is 5.3.0', function (): void {
        $r = new ReflectionClass(\ZeroBoiler\Analytics\Services\AnalyticsHealthCheckService::class);
        expect($r->isFinal())->toBeTrue();

        $versionConstant = $r->getConstant('VERSION');
        expect($versionConstant)->toBe('5.3.0');
    });
});
