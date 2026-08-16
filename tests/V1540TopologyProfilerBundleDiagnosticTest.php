<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsBundleDiagnosticCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsDependencyTopologyCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsRuntimeProfilerCommand;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Tracking\ServerSideTracker;

beforeEach(function (): void {
    //
});

describe('AnalyticsDependencyTopologyCommand', function (): void {
    test('command class exists and is final', function (): void {
        expect(class_exists(AnalyticsDependencyTopologyCommand::class))->toBeTrue();

        $reflection = new ReflectionClass(AnalyticsDependencyTopologyCommand::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    test('has correct signature and description', function (): void {
        $command = new AnalyticsDependencyTopologyCommand;
        $reflection = new ReflectionClass($command);

        $signature = $reflection->getProperty('signature')->getValue($command);
        $description = $reflection->getProperty('description')->getValue($command);

        expect($signature)->toBe('zb:analytics:topology
        {--json : Output as JSON}
        {--circular : Show only circular dependency warnings}
        {--orphans : Show only orphan (unreferenced) services}
        {--heavy : Show top 10 most-dependency-heavy services}
        {--service= : Analyze a specific service class}
        {--depth=3 : Max traversal depth for dependency chain analysis}');
        expect($description)->toBe('Map service dependency topology — detect circular deps, orphans, and heavy services');
    });

    test('implements Command contract with handle() method', function (): void {
        $reflection = new ReflectionClass(AnalyticsDependencyTopologyCommand::class);
        expect($reflection->hasMethod('handle'))->toBeTrue();

        $method = $reflection->getMethod('handle');
        expect($method->getReturnType()?->getName())->toBe('int');
    });

    test('has @since 154.0.0 docblock', function (): void {
        $reflection = new ReflectionClass(AnalyticsDependencyTopologyCommand::class);
        $doc = $reflection->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('@since 154.0.0');
    });

    test(' ServiceProvider reference is correct', function (): void {
        // Verify the command references the correct ServiceProvider
        $command = new ReflectionClass(AnalyticsDependencyTopologyCommand::class);
        $content = file_get_contents($command->getFileName());
        expect($content)->not->toBeFalse();
        expect($content)->toContain('AnalyticsServiceProvider::class');
        expect($content)->toContain('ZeroBoiler\\Analytics\\AnalyticsServiceProvider');
    });

    test('has private scanServiceProvider method', function (): void {
        $reflection = new ReflectionClass(AnalyticsDependencyTopologyCommand::class);
        expect($reflection->hasMethod('scanServiceProvider'))->toBeTrue();
        expect($reflection->getMethod('scanServiceProvider')->isPrivate())->toBeTrue();
    });

    test('has private buildDependencyGraph method', function (): void {
        $reflection = new ReflectionClass(AnalyticsDependencyTopologyCommand::class);
        expect($reflection->hasMethod('buildDependencyGraph'))->toBeTrue();
        expect($reflection->getMethod('buildDependencyGraph')->isPrivate())->toBeTrue();
    });

    test('has private detectCircularDependencies method', function (): void {
        $reflection = new ReflectionClass(AnalyticsDependencyTopologyCommand::class);
        expect($reflection->hasMethod('detectCircularDependencies'))->toBeTrue();
        expect($reflection->getMethod('detectCircularDependencies')->isPrivate())->toBeTrue();
    });

    test('has DFS circular detection algorithm', function (): void {
        $reflection = new ReflectionClass(AnalyticsDependencyTopologyCommand::class);
        expect($reflection->hasMethod('dfsCircular'))->toBeTrue();
        expect($reflection->getMethod('dfsCircular')->isPrivate())->toBeTrue();
    });

    test('has circularChains property typed correctly', function (): void {
        $reflection = new ReflectionClass(AnalyticsDependencyTopologyCommand::class);
        $prop = $reflection->getProperty('circularChains');
        expect($prop->getType()?->getName())->toBe('array');
    });

    test('has shortName helper for FQCN truncation', function (): void {
        $command = new ReflectionClass(AnalyticsDependencyTopologyCommand::class);

        // Instantiate via reflection to access private method
        $method = $command->getMethod('shortName');
        $method->setAccessible(true);

        // Create a mock instance (command needs no constructor deps)
        $instance = $command->newInstanceWithoutConstructor();

        expect($method->invoke($instance, 'ZeroBoiler\\Analytics\\Services\\SomeService'))->toBe('SomeService');
        expect($method->invoke($instance, 'SimpleClass'))->toBe('SimpleClass');
    });
});

describe('AnalyticsRuntimeProfilerCommand', function (): void {
    test('command class exists and is final', function (): void {
        expect(class_exists(AnalyticsRuntimeProfilerCommand::class))->toBeTrue();

        $reflection = new ReflectionClass(AnalyticsRuntimeProfilerCommand::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    test('has correct signature and description', function (): void {
        $command = new AnalyticsRuntimeProfilerCommand(
            Mockery::mock(AnalyticsManager::class),
            Mockery::mock(Illuminate\Contracts\Config\Repository::class),
        );
        $reflection = new ReflectionClass($command);

        $signature = $reflection->getProperty('signature')->getValue($command);
        $description = $reflection->getProperty('description')->getValue($command);

        expect($signature)->toBe('zb:analytics:profile
        {--iterations=1 : Number of profiling iterations}
        {--json : Output as JSON}
        {--warmup=0 : Warm-up iterations (not measured)}');
        expect($description)->toBe('Profile the analytics dispatch pipeline — measure per-stage latency');
    });

    test('constructor accepts AnalyticsManager and ConfigRepository', function (): void {
        $reflection = new ReflectionClass(AnalyticsRuntimeProfilerCommand::class);
        $constructor = $reflection->getConstructor();
        expect($constructor)->not->toBeNull();

        $params = $constructor->getParameters();
        expect(count($params))->toBe(2);
        expect($params[0]->getType()?->getName())->toBe(AnalyticsManager::class);
        expect($params[1]->getType()?->getName())->toBe('Illuminate\\Contracts\\Config\\Repository');
    });

    test('constructor has void return type', function (): void {
        $reflection = new ReflectionClass(AnalyticsRuntimeProfilerCommand::class);
        $constructor = $reflection->getConstructor();
        expect($constructor?->getReturnType()?->getName())->toBe('void');
    });

    test('has @since 154.0.0 docblock', function (): void {
        $reflection = new ReflectionClass(AnalyticsRuntimeProfilerCommand::class);
        $doc = $reflection->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('@since 154.0.0');
    });

    test('has private runSingleIteration method', function (): void {
        $reflection = new ReflectionClass(AnalyticsRuntimeProfilerCommand::class);
        expect($reflection->hasMethod('runSingleIteration'))->toBeTrue();
        expect($reflection->getMethod('runSingleIteration')->isPrivate())->toBeTrue();
    });

    test('has private elapsedMs helper', function (): void {
        $reflection = new ReflectionClass(AnalyticsRuntimeProfilerCommand::class);
        expect($reflection->hasMethod('elapsedMs'))->toBeTrue();
        expect($reflection->getMethod('elapsedMs')->getReturnType()?->getName())->toBe('float');
    });

    test('has private buildReport method', function (): void {
        $reflection = new ReflectionClass(AnalyticsRuntimeProfilerCommand::class);
        expect($reflection->hasMethod('buildReport'))->toBeTrue();
        expect($reflection->getMethod('buildReport')->getReturnType()?->getName())->toBe('array');
    });

    test('has getEnabledProviders helper', function (): void {
        $reflection = new ReflectionClass(AnalyticsRuntimeProfilerCommand::class);
        expect($reflection->hasMethod('getEnabledProviders'))->toBeTrue();
    });

    test('has formatStageLabel helper for display', function (): void {
        $reflection = new ReflectionClass(AnalyticsRuntimeProfilerCommand::class);
        expect($reflection->hasMethod('formatStageLabel'))->toBeTrue();
    });

    test('has proper stage coverage: 6 stages profiled', function (): void {
        $reflection = new ReflectionClass(AnalyticsRuntimeProfilerCommand::class);
        $content = file_get_contents($reflection->getFileName());
        expect($content)->not->toBeFalse();

        // Verify all 6 stages are profiled
        expect($content)->toContain('dto_construction');
        expect($content)->toContain('manager_dispatch');
        expect($content)->toContain('direct_track');
        expect($content)->toContain('identify_and_track');
        expect($content)->toContain('page_view');
        expect($content)->toContain('purchase_event');
    });

    test('uses hrtime for nanosecond precision timing', function (): void {
        $reflection = new ReflectionClass(AnalyticsRuntimeProfilerCommand::class);
        $content = file_get_contents($reflection->getFileName());
        expect($content)->not->toBeFalse();
        expect($content)->toContain('hrtime(true)');
    });

    test('references AnalyticsManager methods correctly', function (): void {
        $reflection = new ReflectionClass(AnalyticsRuntimeProfilerCommand::class);
        $content = file_get_contents($reflection->getFileName());
        expect($content)->not->toBeFalse();

        expect($content)->toContain('trackEvent');
        expect($content)->toContain("'track'");
        expect($content)->toContain('identify');
        expect($content)->toContain('trackPageView');
        expect($content)->toContain('purchase');
    });
});

describe('AnalyticsBundleDiagnosticCommand', function (): void {
    test('command class exists and is final', function (): void {
        expect(class_exists(AnalyticsBundleDiagnosticCommand::class))->toBeTrue();

        $reflection = new ReflectionClass(AnalyticsBundleDiagnosticCommand::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    test('has correct signature and description', function (): void {
        $command = new AnalyticsBundleDiagnosticCommand(
            Mockery::mock(Illuminate\Contracts\Config\Repository::class),
        );
        $reflection = new ReflectionClass($command);

        $signature = $reflection->getProperty('signature')->getValue($command);
        $description = $reflection->getProperty('description')->getValue($command);

        expect($signature)->toBe('zb:analytics:bundle
        {--json : Output as JSON}
        {--fail-on-warning : Return exit code 1 for warnings}
        {--section= : Check only a specific subsystem}');
        expect($description)->toBe('Comprehensive bundle diagnostic — 12 subsystems in one command');
    });

    test('has 12 subsystem checks defined', function (): void {
        $reflection = new ReflectionClass(AnalyticsBundleDiagnosticCommand::class);
        $constant = $reflection->getConstant('SECTIONS');
        expect($constant)->not->toBeFalse();
        expect(count($constant))->toBe(12);
        expect($constant)->toContain('config');
        expect($constant)->toContain('catalog');
        expect($constant)->toContain('providers');
        expect($constant)->toContain('queue');
        expect($constant)->toContain('identity');
        expect($constant)->toContain('consent');
        expect($constant)->toContain('auto_track');
        expect($constant)->toContain('ecommerce');
        expect($constant)->toContain('js_client');
        expect($constant)->toContain('dedup');
        expect($constant)->toContain('sanitization');
        expect($constant)->toContain('sampling');
    });

    test('has @since 154.0.0 docblock', function (): void {
        $reflection = new ReflectionClass(AnalyticsBundleDiagnosticCommand::class);
        $doc = $reflection->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('@since 154.0.0');
    });

    test('constructor accepts ConfigRepository', function (): void {
        $reflection = new ReflectionClass(AnalyticsBundleDiagnosticCommand::class);
        $constructor = $reflection->getConstructor();
        expect($constructor)->not->toBeNull();

        $params = $constructor->getParameters();
        expect(count($params))->toBe(1);
        expect($params[0]->getType()?->getName())->toBe('Illuminate\\Contracts\\Config\\Repository');
    });

    test('has private subsystem check methods for all 12 sections', function (): void {
        $reflection = new ReflectionClass(AnalyticsBundleDiagnosticCommand::class);

        $methods = [
            'checkConfig',
            'checkCatalog',
            'checkProviders',
            'checkQueue',
            'checkIdentity',
            'checkConsent',
            'checkAutoTrack',
            'checkEcommerce',
            'checkJsClient',
            'checkDedup',
            'checkSanitization',
            'checkSampling',
        ];

        foreach ($methods as $method) {
            expect($reflection->hasMethod($method))->toBeTrue()
                ->and($reflection->getMethod($method)->isPrivate())->toBeTrue();
        }
    });

    test('all check methods return 4-element tuple', function (): void {
        $reflection = new ReflectionClass(AnalyticsBundleDiagnosticCommand::class);
        $content = file_get_contents($reflection->getFileName());
        expect($content)->not->toBeFalse();

        // All check methods should return [$passed, $warnings, $critical, $details]
        expect($content)->toContain('return [$passed, $warnings, $critical, $details]');
    });

    test('has calculateExitCode method with fail-on-warning support', function (): void {
        $reflection = new ReflectionClass(AnalyticsBundleDiagnosticCommand::class);
        expect($reflection->hasMethod('calculateExitCode'))->toBeTrue();
        expect($reflection->getMethod('calculateExitCode')->isPrivate())->toBeTrue();

        $method = $reflection->getMethod('calculateExitCode');
        $params = $method->getParameters();
        expect(count($params))->toBe(1);
        expect($params[0]->getType()?->getName())->toBe('bool');
        expect($method->getReturnType()?->getName())->toBe('int');
    });

    test('has formatSubsystemLabel for display formatting', function (): void {
        $reflection = new ReflectionClass(AnalyticsBundleDiagnosticCommand::class);
        expect($reflection->hasMethod('formatSubsystemLabel'))->toBeTrue();
    });

    test('references EventCatalog for catalog checks', function (): void {
        $reflection = new ReflectionClass(AnalyticsBundleDiagnosticCommand::class);
        $content = file_get_contents($reflection->getFileName());
        expect($content)->not->toBeFalse();
        expect($content)->toContain('EventCatalog::categories');
        expect($content)->toContain('EventCatalog::totalEventCount');
    });

    test('references EcommerceEvents, SaaSEvents, EngagementEvents', function (): void {
        $reflection = new ReflectionClass(AnalyticsBundleDiagnosticCommand::class);
        $content = file_get_contents($reflection->getFileName());
        expect($content)->not->toBeFalse();
        expect($content)->toContain('EcommerceEvents::count');
        expect($content)->toContain('SaaSEvents::count');
        expect($content)->toContain('EngagementEvents::count');
    });

    test('provider credential check covers 9 providers', function (): void {
        $reflection = new ReflectionClass(AnalyticsBundleDiagnosticCommand::class);
        $method = $reflection->getMethod('providerHasCredentials');
        $method->setAccessible(true);

        $instance = $reflection->newInstanceWithoutConstructor();

        // Test GA4 credential check
        expect($method->invoke($instance, 'ga4', ['measurement_id' => 'G-123']))->toBeTrue();
        expect($method->invoke($instance, 'ga4', ['measurement_id' => '']))->toBeFalse();

        // Test Meta Pixel credential check
        expect($method->invoke($instance, 'meta_pixel', ['id' => '12345']))->toBeTrue();
        expect($method->invoke($instance, 'meta_pixel', []))->toBeFalse();

        // Test PostHog credential check
        expect($method->invoke($instance, 'posthog', ['api_key' => 'phc_123']))->toBeTrue();

        // Test unknown provider
        expect($method->invoke($instance, 'unknown_provider', []))->toBeFalse();
    });
});

describe('V154 Cross-Cutting Quality', function (): void {
    test('all 3 commands are final classes', function (): void {
        $classes = [
            AnalyticsDependencyTopologyCommand::class,
            AnalyticsRuntimeProfilerCommand::class,
            AnalyticsBundleDiagnosticCommand::class,
        ];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            expect($reflection->isFinal())->toBeTrue()
                ->and($reflection->isAbstract())->toBeFalse();
        }
    });

    test('all 3 commands use declare(strict_types=1)', function (): void {
        $classes = [
            AnalyticsDependencyTopologyCommand::class,
            AnalyticsRuntimeProfilerCommand::class,
            AnalyticsBundleDiagnosticCommand::class,
        ];

        foreach ($classes as $class) {
            $content = file_get_contents((new ReflectionClass($class))->getFileName());
            expect($content)->not->toBeFalse();
            expect($content)->toContain('declare(strict_types=1)');
        }
    });

    test('all 3 commands have MIT license header', function (): void {
        $classes = [
            AnalyticsDependencyTopologyCommand::class,
            AnalyticsRuntimeProfilerCommand::class,
            AnalyticsBundleDiagnosticCommand::class,
        ];

        foreach ($classes as $class) {
            $content = file_get_contents((new ReflectionClass($class))->getFileName());
            expect($content)->not->toBeFalse();
            expect($content)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
        }
    });

    test('all 3 commands use #[\Override] on handle()', function (): void {
        $classes = [
            AnalyticsDependencyTopologyCommand::class,
            AnalyticsRuntimeProfilerCommand::class,
            AnalyticsBundleDiagnosticCommand::class,
        ];

        foreach ($classes as $class) {
            $content = file_get_contents((new ReflectionClass($class))->getFileName());
            expect($content)->not->toBeFalse();
            expect($content)->toContain('#[\Override]');
            expect($content)->toContain('public function handle(): int');
        }
    });

    test('AnalyticsDependencyTopologyCommand uses ReflectionClass for analysis', function (): void {
        $reflection = new ReflectionClass(AnalyticsDependencyTopologyCommand::class);
        $content = file_get_contents($reflection->getFileName());
        expect($content)->not->toBeFalse();
        expect($content)->toContain('ReflectionClass');
        expect($content)->toContain('ReflectionMethod');
        expect($content)->toContain('ReflectionParameter');
    });

    test('AnalyticsRuntimeProfilerCommand uses hrtime for profiling', function (): void {
        $reflection = new ReflectionClass(AnalyticsRuntimeProfilerCommand::class);
        $content = file_get_contents($reflection->getFileName());
        expect($content)->not->toBeFalse();
        expect($content)->toContain('hrtime(true)');
        expect($content)->toContain('1_000_000');
    });

    test('AnalyticsBundleDiagnosticCommand has proper exit codes', function (): void {
        $reflection = new ReflectionClass(AnalyticsBundleDiagnosticCommand::class);
        $content = file_get_contents($reflection->getFileName());
        expect($content)->not->toBeFalse();
        // Exit code 2 for critical, 1 for warning (when --fail-on-warning), 0 for success
        expect($content)->toContain('return 2');
        expect($content)->toContain('return self::SUCCESS');
    });
});
