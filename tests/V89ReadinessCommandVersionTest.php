<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

test('AnalyticsReadinessCommand class exists and is final', function (): void {
    $class = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsReadinessCommand::class);

    expect($class->isFinal())->toBeTrue();
});

test('AnalyticsReadinessCommand has correct signature', function (): void {
    $class = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsReadinessCommand::class);
    $property = $class->getProperty('signature');

    expect($property->getValue())->toBe('zb:analytics:readiness
        {--json : Output as JSON}
        {--no-cache : Force fresh assessment (ignore cache)}');
});

test('AnalyticsReadinessCommand is registered in ServiceProvider', function (): void {
    $provider = new \ZeroBoiler\Analytics\AnalyticsServiceProvider(app());
    $bootRef = new ReflectionMethod($provider, 'boot');

    expect($bootRef)->not->toBeFalse();

    $source = file_get_contents((string) $bootRef->getFileName());
    expect($source)->toContain('AnalyticsReadinessCommand::class');
});

test('AnalyticsReadinessCommand has strict types', function (): void {
    $source = file_get_contents(
        dirname(__DIR__, 2).'/src/Console/Commands/AnalyticsReadinessCommand.php'
    );

    expect($source)->toContain('declare(strict_types=1)');
});

test('AnalyticsReadinessCommand has license header', function (): void {
    $source = file_get_contents(
        dirname(__DIR__, 2).'/src/Console/Commands/AnalyticsReadinessCommand.php'
    );

    expect(str_contains($source, 'This file is part of ZeroBoiler, licensed under the MIT license'))->toBeTrue();
});

test('AnalyticsReadinessCommand has Override attribute on handle', function (): void {
    $class = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsReadinessCommand::class);
    $method = $class->getMethod('handle');
    $attrs = $method->getAttributes(\Override::class);

    expect(count($attrs))->toBeGreaterThanOrEqual(1);
});

test('AnalyticsReadinessCommand injects AnalyticsReadinessService', function (): void {
    $class = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsReadinessCommand::class);
    $constructor = $class->getMethod('__construct');
    $params = $constructor->getParameters();

    expect(count($params))->toBe(2);
    expect($params[0]->getName())->toBe('service');
    expect($params[0]->getType()->getName())->toBe(
        \ZeroBoiler\Analytics\Services\AnalyticsReadinessService::class
    );
});

test('AnalyticsReadinessCommand handle returns int', function (): void {
    $class = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsReadinessCommand::class);
    $method = $class->getMethod('handle');
    $returnType = $method->getReturnType();

    expect($returnType)->not->toBeNull();
    expect($returnType->getName())->toBe('int');
});

test('version consistency across all sources is 2.90.0', function (): void {
    // Composer
    $composer = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);
    expect($composer['version'])->toBe('268.0.0');

    // AnalyticsEvent DTO
    expect(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION)->toBe('268.0.0');

    // Manager
    $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
    expect($manager->version())->toBe('268.0.0');

    // JS Client
    $js = file_get_contents(dirname(__DIR__, 2).'/resources/js/analytics.js');
    expect(str_contains($js, "'268.0.0'"))->toBeTrue();

    // TypeScript
    $dts = file_get_contents(dirname(__DIR__, 2).'/resources/js/analytics.d.ts');
    expect(str_contains($dts, '268.0.0'))->toBeTrue();

    // Config
    $config = include dirname(__DIR__, 2).'/config/zeroboiler.php';
    expect($config['analytics']['schema_versioning']['catalog_version'])->toBe('268.0.0');

    // Event Schema Versioning Service
    $source = file_get_contents(
        dirname(__DIR__, 2).'/src/Services/EventSchemaVersioningService.php'
    );
    expect(str_contains($source, "'268.0.0'"))->toBeTrue();

    // AnalyticsEventRouter
    $router = file_get_contents(
        dirname(__DIR__, 2).'/src/Services/AnalyticsEventRouter.php'
    );
    expect(str_contains($router, "'268.0.0'"))->toBeTrue();

    // EventCacheService
    $cache = file_get_contents(
        dirname(__DIR__, 2).'/src/Services/EventCacheService.php'
    );
    expect(str_contains($cache, "'268.0.0'"))->toBeTrue();

    // EventExporterService
    $exporter = file_get_contents(
        dirname(__DIR__, 2).'/src/Services/EventExporterService.php'
    );
    expect(str_contains($exporter, "'268.0.0'"))->toBeTrue();

    // EventSourceTagger
    $tagger = file_get_contents(
        dirname(__DIR__, 2).'/src/Services/EventSourceTagger.php'
    );
    expect(str_contains($tagger, "'268.0.0'"))->toBeTrue();

    // EventForwardingService
    $forwarding = file_get_contents(
        dirname(__DIR__, 2).'/src/Services/EventForwardingService.php'
    );
    expect(str_contains($forwarding, "'268.0.0'"))->toBeTrue();

    // EventAliasResolver
    $resolver = file_get_contents(
        dirname(__DIR__, 2).'/src/Services/EventAliasResolver.php'
    );
    expect(str_contains($resolver, "'268.0.0'"))->toBeTrue();

    // EventEnvelopeService
    $envelope = file_get_contents(
        dirname(__DIR__, 2).'/src/Services/EventEnvelopeService.php'
    );
    expect(str_contains($envelope, "'268.0.0'"))->toBeTrue();

    // SaaSMetricsBenchmarkService
    $benchmark = file_get_contents(
        dirname(__DIR__, 2).'/src/Services/SaaSMetricsBenchmarkService.php'
    );
    expect(str_contains($benchmark, "'268.0.0'"))->toBeTrue();

    // ServiceProvider
    $provider = file_get_contents(
        dirname(__DIR__, 2).'/src/AnalyticsServiceProvider.php'
    );
    expect(str_contains($provider, '268.0.0'))->toBeTrue();
});

test('no stale 2.88.0 version references remain in src', function (): void {
    $srcFiles = glob(dirname(__DIR__, 2).'/src/**/*.php');

    foreach ($srcFiles as $file) {
        $contents = file_get_contents($file);
        expect(str_contains($contents, '2.88.0'))
            ->toBeFalse("{$file} still contains stale 2.88.0 version reference");
    }
});

test('no stale 2.88.0 version references remain in resources', function (): void {
    $resourceFiles = array_merge(
        glob(dirname(__DIR__, 2).'/resources/js/*.js'),
        glob(dirname(__DIR__, 2).'/resources/js/*.d.ts'),
    );

    foreach ($resourceFiles as $file) {
        $contents = file_get_contents($file);
        expect(str_contains($contents, '2.88.0'))
            ->toBeFalse("{$file} still contains stale 2.88.0 version reference");
    }
});

test('no stale 2.88.0 version references remain in config', function (): void {
    $configFile = dirname(__DIR__, 2).'/config/zeroboiler.php';
    $contents = file_get_contents($configFile);

    expect(str_contains($contents, '2.88.0'))->toBeFalse();
});

test('AnalyticsReadinessCommand source file count is consistent', function (): void {
    $phpFiles = glob(dirname(__DIR__, 2).'/src/**/*.php');
    expect(count($phpFiles))->toBeGreaterThan(390);
});
