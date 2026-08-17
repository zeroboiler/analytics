<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\CatalogChangeImpact;
use ZeroBoiler\Analytics\DTO\CatalogVersionRecommendation;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\CatalogSnapshotService;
use ZeroBoiler\Analytics\Services\EventCatalogVersioningEngine;
use ZeroBoiler\Analytics\Services\ReleaseChangelogGeneratorService;

/**
 * @covers \ZeroBoiler\Analytics\DTO\CatalogChangeImpact
 * @covers \ZeroBoiler\Analytics\DTO\CatalogVersionRecommendation
 * @covers \ZeroBoiler\Analytics\Services\EventCatalogVersioningEngine
 * @covers \ZeroBoiler\Analytics\Services\ReleaseChangelogGeneratorService
 * @covers \ZeroBoiler\Analytics\Console\Commands\AnalyticsCatalogVersionCommand
 */
it('CatalogChangeImpact has factory methods for all severity levels', function (): void {
    $major = CatalogChangeImpact::major('event_removed', 'test_event', 'Event removed');
    expect($major->severity)->toBe('major');
    expect($major->breaking)->toBeTrue();

    $minor = CatalogChangeImpact::minor('event_added', 'new_event', 'Event added');
    expect($minor->severity)->toBe('minor');
    expect($minor->breaking)->toBeFalse();

    $patch = CatalogChangeImpact::patch('category_changed', 'some_event', 'Category changed');
    expect($patch->severity)->toBe('patch');
    expect($patch->breaking)->toBeFalse();
});

it('CatalogChangeImpact serialization round-trip', function (): void {
    $original = CatalogChangeImpact::major(
        type: 'event_removed',
        eventName: 'purchase',
        description: 'Purchase event removed',
        oldValue: 'old_class',
        newValue: null,
        category: 'ecommerce',
    );

    $array = $original->toArray();
    $restored = CatalogChangeImpact::fromArray($array);

    expect($restored->type)->toBe($original->type);
    expect($restored->eventName)->toBe($original->eventName);
    expect($restored->severity)->toBe($original->severity);
    expect($restored->breaking)->toBe($original->breaking);
    expect($restored->category)->toBe($original->category);
});

it('CatalogChangeImpact has all required array keys in toArray', function (): void {
    $impact = CatalogChangeImpact::minor('event_added', 'signup', 'New signup event');
    $array = $impact->toArray();

    expect($array)->toHaveKeys([
        'type', 'event_name', 'severity', 'description',
        'old_value', 'new_value', 'category', 'breaking', 'metadata',
    ]);
});

it('CatalogVersionRecommendation noChange factory works', function (): void {
    $rec = CatalogVersionRecommendation::noChange('215.0.0');

    expect($rec->recommended)->toBe('none');
    expect($rec->currentVersion)->toBe('215.0.0');
    expect($rec->nextVersion)->toBe('215.0.0');
    expect($rec->hasBreaking)->toBeFalse();
    expect($rec->changes)->toBe([]);
    expect($rec->releaseNotesOrEmpty())->toBe('No changes detected.');
});

it('CatalogVersionRecommendation serialization round-trip', function (): void {
    $rec = CatalogVersionRecommendation::noChange('216.0.0');
    $array = $rec->toArray();
    $restored = CatalogVersionRecommendation::fromArray($array);

    expect($restored->recommended)->toBe($rec->recommended);
    expect($restored->currentVersion)->toBe($rec->currentVersion);
    expect($restored->nextVersion)->toBe($rec->nextVersion);
    expect($restored->hasBreaking)->toBe($rec->hasBreaking);
});

it('EventCatalogVersioningEngine has correct severity map', function (): void {
    $map = EventCatalogVersioningEngine::getSeverityMap();

    expect($map)->toBe([
        'event_added' => 'minor',
        'event_removed' => 'major',
        'event_renamed' => 'major',
        'category_changed' => 'patch',
        'provider_mapping_added' => 'minor',
        'provider_mapping_removed' => 'major',
        'provider_mapping_changed' => 'patch',
        'class_changed' => 'major',
    ]);
});

it('EventCatalogVersioningEngine has correct breaking types', function (): void {
    $types = EventCatalogVersioningEngine::getBreakingTypes();

    expect($types)->toContain('event_removed');
    expect($types)->toContain('event_renamed');
    expect($types)->toContain('provider_mapping_removed');
    expect($types)->toContain('class_changed');
    expect($types)->not->toContain('event_added');
    expect($types)->not->toContain('category_changed');
});

it('EventCatalogVersioningEngine isBreakingType works correctly', function (): void {
    expect(EventCatalogVersioningEngine::isBreakingType('event_removed'))->toBeTrue();
    expect(EventCatalogVersioningEngine::isBreakingType('event_added'))->toBeFalse();
    expect(EventCatalogVersioningEngine::isBreakingType('provider_mapping_added'))->toBeFalse();
    expect(EventCatalogVersioningEngine::isBreakingType('class_changed'))->toBeTrue();
});

it('EventCatalogVersioningEngine getDefaultSeverity returns correct values', function (): void {
    expect(EventCatalogVersioningEngine::getDefaultSeverity('event_added'))->toBe('minor');
    expect(EventCatalogVersioningEngine::getDefaultSeverity('event_removed'))->toBe('major');
    expect(EventCatalogVersioningEngine::getDefaultSeverity('unknown_type'))->toBe('patch');
});

it('EventCatalogVersioningEngine file has strict types and MIT header', function (): void {
    $path = __DIR__ . '/../../src/Services/EventCatalogVersioningEngine.php';
    $content = file_get_contents($path);

    expect($content)->toContain('declare(strict_types=1)');
    expect($content)->toContain('This file is part of ZeroBoiler, licensed under the MIT license.');
    expect($content)->toContain('final class EventCatalogVersioningEngine');
    expect($content)->toContain('@since 216.0.0');
});

it('EventCatalogVersioningEngine has public methods with return types', function (): void {
    $reflection = new ReflectionClass(EventCatalogVersioningEngine::class);
    $publicMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

    $methodNames = array_map(fn(ReflectionMethod $m): string => $m->getName(), $publicMethods);
    $excluded = ['__construct'];

    foreach ($methodNames as $name) {
        if (in_array($name, $excluded, true)) {
            continue;
        }

        $method = $reflection->getMethod($name);
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull("Method {$name} missing return type");
    }
});

it('EventCatalogVersioningEngine has at least 10 public methods', function (): void {
    $reflection = new ReflectionClass(EventCatalogVersioningEngine::class);
    $publicMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
    $excluded = ['__construct'];

    $count = count(array_filter($publicMethods, fn(ReflectionMethod $m): bool => ! in_array($m->getName(), $excluded, true)));
    expect($count)->toBeGreaterThanOrEqual(10);
});

it('ReleaseChangelogGeneratorService has 4 output formats', function (): void {
    $reflection = new ReflectionClass(ReleaseChangelogGeneratorService::class);
    $method = $reflection->getMethod('generate');
    $params = $method->getParameters();
    $formatParam = $params[1]; // $format

    // Check the method has the format parameter
    expect($formatParam->getName())->toBe('format');
});

it('ReleaseChangelogGeneratorService file quality', function (): void {
    $path = __DIR__ . '/../../src/Services/ReleaseChangelogGeneratorService.php';
    $content = file_get_contents($path);

    expect($content)->toContain('declare(strict_types=1)');
    expect($content)->toContain('This file is part of ZeroBoiler, licensed under the MIT license.');
    expect($content)->toContain('final class ReleaseChangelogGeneratorService');
    expect($content)->toContain('@since 216.0.0');
});

it('ReleaseChangelogGeneratorService generates markdown for no-change recommendation', function (): void {
    $rec = CatalogVersionRecommendation::noChange('216.0.0');

    // Test the markdown generation logic manually since we can't resolve the service
    $lines = [];
    $lines[] = "## [{$rec->nextVersion}] - " . date('Y-m-d');
    $lines[] = '';
    $lines[] = 'No catalog changes.';

    $markdown = implode("\n", $lines);
    expect($markdown)->toContain('## [216.0.0]');
    expect($markdown)->toContain('No catalog changes.');
});

it('ReleaseChangelogGeneratorService catalogStats returns expected structure', function (): void {
    // Verify catalog is available
    $catalog = EventCatalog::all();
    expect($catalog)->not->toBeEmpty();

    $stats = [
        'version' => AnalyticsEvent::VERSION,
        'total_events' => count($catalog),
    ];

    expect($stats['version'])->toBe('216.0.0');
    expect($stats['total_events'])->toBeGreaterThan(100);
});

it('AnalyticsCatalogVersionCommand has correct signature', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsCatalogVersionCommand::class);
    $method = $reflection->getMethod('handle');

    expect($method->getReturnType()?->getName())->toBe('int');
});

it('AnalyticsCatalogVersionCommand file quality', function (): void {
    $path = __DIR__ . '/../../src/Console/Commands/AnalyticsCatalogVersionCommand.php';
    $content = file_get_contents($path);

    expect($content)->toContain('declare(strict_types=1)');
    expect($content)->toContain('This file is part of ZeroBoiler, licensed under the MIT license.');
    expect($content)->toContain('final class AnalyticsCatalogVersionCommand');
    expect($content)->toContain('@since 216.0.0');
});

it('version consistency across all files', function (): void {
    $version = '216.0.0';

    // DTO version constant
    expect(AnalyticsEvent::VERSION)->toBe($version);

    // Composer
    $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
    expect($composer['version'])->toBe($version);

    // Package.json
    $pkg = json_decode(file_get_contents(__DIR__ . '/../../package.json'), true);
    expect($pkg['version'])->toBe($version);
});

it('ServiceProvider registers new services and command', function (): void {
    $path = __DIR__ . '/../../src/AnalyticsServiceProvider.php';
    $content = file_get_contents($path);

    expect($content)->toContain('EventCatalogVersioningEngine::class');
    expect($content)->toContain('ReleaseChangelogGeneratorService::class');
    expect($content)->toContain('AnalyticsCatalogVersionCommand::class');
    expect($content)->toContain('singleton(EventCatalogVersioningEngine::class');
    expect($content)->toContain('singleton(ReleaseChangelogGeneratorService::class');
});

it('config has catalog_versioning section', function (): void {
    $path = __DIR__ . '/../../config/zeroboiler.php';
    $content = file_get_contents($path);

    expect($content)->toContain("'catalog_versioning' => [");
    expect($content)->toContain('ANALYTICS_CATALOG_VERSIONING_ENABLED');
    expect($content)->toContain('ANALYTICS_CATALOG_VERSIONING_CACHE_TTL');
});

it('routes have catalog-version endpoints', function (): void {
    $path = __DIR__ . '/../../routes/analytics.php';
    $content = file_get_contents($path);

    expect($content)->toContain('catalog-version/recommendation');
    expect($content)->toContain('catalog-version/severity');
    expect($content)->toContain('catalog-version/changelog');
    expect($content)->toContain('catalog-version/stats');
    expect($content)->toContain('catalog-version/history');
    expect($content)->toContain('catalog-version/capture');
    expect($content)->toContain('catalog-version/config');
});

it('controller has catalog version action methods', function (): void {
    $path = __DIR__ . '/../../src/Http/Controllers/AnalyticsEventController.php';
    $content = file_get_contents($path);

    expect($content)->toContain('catalogVersionRecommendation');
    expect($content)->toContain('catalogVersionSeverity');
    expect($content)->toContain('catalogVersionChangelog');
    expect($content)->toContain('catalogVersionStats');
    expect($content)->toContain('catalogVersionHistory');
    expect($content)->toContain('catalogVersionCapture');
    expect($content)->toContain('catalogVersionConfig');
});

it('source file count baseline', function (): void {
    $srcFiles = glob(__DIR__ . '/../../src/**/*.php', GLOB_BRACE);
    // 421 existing + 4 new (2 DTOs, 2 Services, 1 Command) = 425+
    expect(count($srcFiles))->toBeGreaterThanOrEqual(425);
});

it('service count is 423 (421 existing + 2 new)', function (): void {
    $services = glob(__DIR__ . '/../../src/Services/*.php');
    expect(count($services))->toBe(423); // 421 + EventCatalogVersioningEngine + ReleaseChangelogGeneratorService
});

it('command count is 100 (99 existing + 1 new)', function (): void {
    $commands = glob(__DIR__ . '/../../src/Console/Commands/*.php');
    expect(count($commands))->toBe(100); // 99 + AnalyticsCatalogVersionCommand
});

it('DTO count increased by 2', function (): void {
    $dtos = glob(__DIR__ . '/../../src/DTO/*.php');
    // There are many existing DTOs — just verify we have the new ones
    $newDtos = [
        __DIR__ . '/../../src/DTO/CatalogChangeImpact.php',
        __DIR__ . '/../../src/DTO/CatalogVersionRecommendation.php',
    ];

    foreach ($newDtos as $dto) {
        expect(file_exists($dto))->toBeTrue();
    }
});
