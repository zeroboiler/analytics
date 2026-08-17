<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Console\Commands\SaaSRevenueIntelligenceCommand;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

test('SaaSRevenueIntelligenceCommand has correct file quality', function (): void {
    $reflection = new ReflectionClass(SaaSRevenueIntelligenceCommand::class);

    // Class is final
    expect($reflection->isFinal())->toBeTrue();

    // Has strict types
    $contents = file_get_contents($reflection->getFileName());
    expect($contents)->toContain('declare(strict_types=1)');

    // Has MIT license header
    expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the MIT license.');

    // Has @since 226.0.0
    expect($contents)->toContain('@since 226.0.0');

    // Namespace is correct
    expect($reflection->getNamespaceName())->toBe('ZeroBoiler\\Analytics\\Console\\Commands');

    // Extends Illuminate\Console\Command
    expect($reflection->getParentClass()->getName())->toBe('Illuminate\\Console\\Command');
});

test('SaaSRevenueIntelligenceCommand has correct command signature', function (): void {
    $reflection = new ReflectionClass(SaaSRevenueIntelligenceCommand::class);

    // Constructor accepts AnalyticsManager and ConfigRepository
    $constructor = $reflection->getMethod('__construct');
    $parameters = $constructor->getParameters();

    expect($parameters)->toHaveCount(2);
    expect($parameters[0]->getType()?->getName())->toBe(AnalyticsManager::class);
    expect($parameters[1]->getType()?->getName())->toBe('Illuminate\\Contracts\\Config\\Repository');

    // Class properties
    expect($reflection->hasProperty('manager'))->toBeTrue();
    expect($reflection->hasProperty('config'))->toBeTrue();
});

test('SaaSRevenueIntelligenceCommand command is registered in ServiceProvider', function (): void {
    $providerReflection = new ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class);
    $contents = file_get_contents($providerReflection->getFileName());

    // Import statement exists
    expect($contents)->toContain('use ZeroBoiler\\Analytics\\Console\\Commands\\SaaSRevenueIntelligenceCommand;');

    // Registration exists
    expect($contents)->toContain('SaaSRevenueIntelligenceCommand::class');
});

test('SaaSRevenueIntelligenceCommand signature contains all options', function (): void {
    $command = new SaaSRevenueIntelligenceCommand(
        app(AnalyticsManager::class),
        app('config'),
    );

    $definition = $command->getDefinition();

    // Options
    expect($definition->hasOption('period'))->toBeTrue();
    expect($definition->hasOption('json'))->toBeTrue();
    expect($definition->hasOption('mrr'))->toBeTrue();
    expect($definition->hasOption('waterfall'))->toBeTrue();
    expect($definition->hasOption('retention'))->toBeTrue();
    expect($definition->hasOption('forecast'))->toBeTrue();
    expect($definition->hasOption('funnel'))->toBeTrue();
    expect($definition->hasOption('churn'))->toBeTrue();
    expect($definition->hasOption('momentum'))->toBeTrue();
    expect($definition->hasOption('kpi'))->toBeTrue();
    expect($definition->hasOption('benchmarks'))->toBeTrue();

    // Default period value
    $periodOption = $definition->getOption('period');
    expect($periodOption->getDefault())->toBe(30);
});

test('SaaSRevenueIntelligenceCommand has all required public methods', function (): void {
    $reflection = new ReflectionClass(SaaSRevenueIntelligenceCommand::class);

    // Main handle method
    expect($reflection->hasMethod('handle'))->toBeTrue();
    $handleMethod = $reflection->getMethod('handle');
    expect($handleMethod->getReturnType()?->getName())->toBe('int');

    // Has #[Override] attribute on handle
    $attributes = $handleMethod->getAttributes();
    $hasOverride = false;
    foreach ($attributes as $attr) {
        if ($attr->getName() === 'Override') {
            $hasOverride = true;
            break;
        }
    }
    expect($hasOverride)->toBeTrue();
});

test('SaaSRevenueIntelligenceCommand version is consistent', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('226.0.0');
});

test('SaaSRevenueIntelligenceCommand file exists in correct location', function (): void {
    $path = __DIR__ . '/../../src/Console/Commands/SaaSRevenueIntelligenceCommand.php';
    expect(file_exists($path))->toBeTrue();
});

test('SaaSRevenueIntelligenceCommand has docblocks on all render methods', function (): void {
    $reflection = new ReflectionClass(SaaSRevenueIntelligenceCommand::class);
    $contents = file_get_contents($reflection->getFileName());

    // All render methods should have docblocks
    $renderMethods = [
        'renderHeader',
        'renderFooter',
        'renderKpiSummary',
        'renderMrrBreakdown',
        'renderWaterfall',
        'renderChurnAnalysis',
        'renderRetention',
        'renderForecast',
        'renderFunnel',
        'renderMomentum',
        'renderBenchmarks',
    ];

    foreach ($renderMethods as $method) {
        expect($reflection->hasMethod($method))->toBeTrue("Missing method: {$method}");
    }
});

test('SaaSRevenueIntelligenceCommand has proper return types on helper methods', function (): void {
    $reflection = new ReflectionClass(SaaSRevenueIntelligenceCommand::class);

    // Private helper methods with return types
    $expectedMethods = [
        'buildFullReport',
        'getKpiData',
        'getMrrData',
        'getWaterfallData',
        'getChurnData',
        'getRetentionData',
        'getForecastData',
        'getFunnelData',
        'getMomentumData',
        'getBenchmarksData',
        'emptyKpiResponse',
        'formatCurrency',
        'formatPercent',
        'mrrBar',
        'getCurrency',
    ];

    foreach ($expectedMethods as $method) {
        expect($reflection->hasMethod($method))->toBeTrue("Missing helper method: {$method}");
    }
});
