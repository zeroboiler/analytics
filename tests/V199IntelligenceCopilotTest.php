<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Tests\Pest;
use ZeroBoiler\Analytics\Services\EventIntelligenceCopilotService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsCopilotCommand;
use ZeroBoiler\Analytics\Events\EventCatalog;

beforeEach(function (): void {
    $this->cache = app('cache');
    $this->config = app('Illuminate\Contracts\Config\Repository');
    $this->service = new EventIntelligenceCopilotService(
        $this->cache,
        $this->config,
    );
});

describe('EventIntelligenceCopilotService', function (): void {
    test('service file has strict types and MIT header', function (): void {
        $content = file_get_contents(__DIR__ . '/../../src/Services/EventIntelligenceCopilotService.php');
        expect($content)->toContain('declare(strict_types=1)');
        expect($content)->toContain('This file is part of ZeroBoiler, licensed under the MIT license.');
    });

    test('service class is final', function (): void {
        $reflection = new ReflectionClass(EventIntelligenceCopilotService::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    test('service has @since annotation', function (): void {
        $reflection = new ReflectionClass(EventIntelligenceCopilotService::class);
        $doc = $reflection->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('@since 199.0.0');
    });

    test('constructor has :void return type', function (): void {
        $reflection = new ReflectionMethod(EventIntelligenceCopilotService::class, '__construct');
        expect($reflection->getReturnType()?->getName())->toBe('void');
    });

    test('all public methods have return type declarations', function (): void {
        $reflection = new ReflectionClass(EventIntelligenceCopilotService::class);
        $publicMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        foreach ($publicMethods as $method) {
            if ($method->getName() === '__construct') {
                continue;
            }
            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull(
                "Method {$method->getName()} must have a return type declaration",
            );
        }
    });

    test('isEnabled returns boolean', function (): void {
        expect($this->service->isEnabled())->toBeBool();
    });

    test('configSummary returns correct structure', function (): void {
        $summary = $this->service->configSummary();
        expect($summary)->toBeArray();
        expect($summary)->toHaveKeys([
            'enabled',
            'cache_ttl',
            'max_recommendations',
            'spike_threshold',
            'anomaly_sensitivity',
            'min_event_volume',
        ]);
    });

    test('categorySummary returns expected structure', function (): void {
        $result = $this->service->categorySummary('Ecommerce');
        expect($result)->toBeArray();
        expect($result)->toHaveKeys([
            'category',
            'event_count',
            'provider_coverage',
            'top_events',
            'gaps',
            'health',
        ]);
        expect($result['category'])->toBe('Ecommerce');
        expect($result['event_count'])->toBeInt();
        expect($result['provider_coverage'])->toBeFloat();
        expect($result['health'])->toBeFloat();
        expect($result['top_events'])->toBeArray();
        expect($result['gaps'])->toBeArray();
    });

    test('detectVolumeSpikes returns expected structure', function (): void {
        $result = $this->service->detectVolumeSpikes();
        expect($result)->toBeArray();
        expect($result)->toHaveKeys(['spikes', 'total_categories_analyzed']);
        expect($result['total_categories_analyzed'])->toBeInt();
        expect($result['spikes'])->toBeArray();
    });

    test('providerHealthComparison returns expected structure', function (): void {
        $result = $this->service->providerHealthComparison();
        expect($result)->toBeArray();
        expect($result)->toHaveKeys(['providers', 'summary']);
        expect($result['providers'])->toBeArray();
        expect($result['summary'])->toHaveKeys([
            'total_enabled',
            'avg_coverage',
            'weakest_provider',
            'strongest_provider',
        ]);
        expect($result['summary']['total_enabled'])->toBeInt();
        expect($result['summary']['avg_coverage'])->toBeFloat();

        // Should have all 10 providers
        expect(count($result['providers']))->toBe(10);
    });

    test('lifecycleFunnelIntelligence returns expected structure', function (): void {
        $result = $this->service->lifecycleFunnelIntelligence();
        expect($result)->toBeArray();
        expect($result)->toHaveKeys([
            'stages',
            'total_lifecycle_events',
            'bottleneck_stage',
            'healthiest_stage',
        ]);
        expect($result['total_lifecycle_events'])->toBeInt();
        expect($result['stages'])->toBeArray();

        // Should have 6 lifecycle stages
        expect(count($result['stages']))->toBe(6);

        // Each stage should have required keys
        foreach ($result['stages'] as $stage) {
            expect($stage)->toHaveKeys(['stage', 'event_count', 'event_names', 'percentage']);
        }
    });

    test('generateSummary returns full intelligence structure', function (): void {
        $result = $this->service->generateSummary();
        expect($result)->toBeArray();

        // Required top-level keys
        expect($result)->toHaveKeys([
            'generated_at',
            'catalog_intelligence',
            'quality_intelligence',
            'volume_intelligence',
            'provider_intelligence',
            'lifecycle_intelligence',
            'recommendations',
            'health_score',
            'health_grade',
            'total_events_tracked',
            'total_providers',
            'total_categories',
        ]);

        // Catalog intelligence structure
        expect($result['catalog_intelligence'])->toHaveKeys([
            'total_events',
            'categories',
            'avg_provider_coverage',
            'coverage_score',
            'grade',
        ]);

        // Health score should be a float between 0-100
        expect($result['health_score'])->toBeFloat();
        expect($result['health_score'])->toBeGreaterThanOrEqual(0.0);
        expect($result['health_score'])->toBeLessThanOrEqual(100.0);

        // Health grade should be a letter
        expect($result['health_grade'])->toBeIn(['A', 'B', 'C', 'D', 'F', 'N/A']);

        // Recommendations should be an array
        expect($result['recommendations'])->toBeArray();

        // Provider and category counts
        expect($result['total_providers'])->toBe(10);
        expect($result['total_categories'])->toBe(9);

        // Generated timestamp should be a valid ISO date
        expect($result['generated_at'])->toBeString();
        expect(strtotime($result['generated_at']))->not()->toBeFalse();
    });

    test('recommendations have correct structure', function (): void {
        $result = $this->service->generateSummary();
        foreach ($result['recommendations'] as $rec) {
            expect($rec)->toBeArray();
            expect($rec)->toHaveKeys(['priority', 'category', 'title', 'description', 'impact']);
            expect($rec['priority'])->toBeIn(['high', 'medium', 'low', 'info']);
        }
    });

    test('clearCache returns boolean', function (): void {
        $result = $this->service->clearCache();
        expect($result)->toBeBool();
    });
});

describe('AnalyticsCopilotCommand', function (): void {
    test('command file has strict types and MIT header', function (): void {
        $content = file_get_contents(__DIR__ . '/../../src/Console/Commands/AnalyticsCopilotCommand.php');
        expect($content)->toContain('declare(strict_types=1)');
        expect($content)->toContain('This file is part of ZeroBoiler, licensed under the MIT license.');
    });

    test('command class is final', function (): void {
        $reflection = new ReflectionClass(AnalyticsCopilotCommand::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    test('command has @since annotation', function (): void {
        $reflection = new ReflectionClass(AnalyticsCopilotCommand::class);
        $doc = $reflection->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('@since 199.0.0');
    });

    test('handle method returns int', function (): void {
        $reflection = new ReflectionMethod(AnalyticsCopilotCommand::class, 'handle');
        expect($reflection->getReturnType()?->getName())->toBe('int');
    });

    test('command has correct signature', function (): void {
        $reflection = new ReflectionClass(AnalyticsCopilotCommand::class);
        $property = $reflection->getProperty('signature');
        $property->setAccessible(true);
        $signature = $property->getValue(new AnalyticsCopilotCommand);
        expect($signature)->toBeString();
        expect($signature)->toContain('analytics:copilot');
        expect($signature)->toContain('summary');
        expect($signature)->toContain('category');
        expect($signature)->toContain('spikes');
        expect($signature)->toContain('providers');
        expect($signature)->toContain('lifecycle');
        expect($signature)->toContain('config');
        expect($signature)->toContain('clear');
        expect($signature)->toContain('--json');
    });
});

describe('v199.0.0 Integration', function (): void {
    test('new source files exist', function (): void {
        expect(file_exists(__DIR__ . '/../../src/Services/EventIntelligenceCopilotService.php'))->toBeTrue();
        expect(file_exists(__DIR__ . '/../../src/Console/Commands/AnalyticsCopilotCommand.php'))->toBeTrue();
    });

    test('config section exists', function (): void {
        $config = include __DIR__ . '/../../config/zeroboiler.php';
        expect($config)->toBeArray();
        expect(isset($config['analytics']['intelligence_copilot']))->toBeTrue();
        expect($config['analytics']['intelligence_copilot'])->toHaveKeys([
            'enabled',
            'cache_ttl',
            'cache_prefix',
            'max_recommendations',
            'min_event_volume',
            'spike_threshold',
            'anomaly_sensitivity',
        ]);
    });

    test('version consistency across entry points', function (): void {
        // composer.json
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        expect($composer['version'])->toBe('199.0.0');

        // package.json
        $pkg = json_decode(file_get_contents(__DIR__ . '/../../package.json'), true);
        expect($pkg['version'])->toBe('199.0.0');

        // AnalyticsEvent::VERSION
        expect(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION)->toBe('199.0.0');
    });

    test('source file count meets minimum', function (): void {
        $finder = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/../../src', RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $phpFiles = 0;
        foreach ($finder as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $phpFiles++;
            }
        }
        expect($phpFiles)->toBeGreaterThanOrEqual(879);
    });

    test('test file count meets minimum', function (): void {
        $finder = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__ . '/..', RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $phpFiles = 0;
        foreach ($finder as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && str_contains($file->getPathname(), '/tests/')) {
                $phpFiles++;
            }
        }
        expect($phpFiles)->toBeGreaterThanOrEqual(447);
    });

    test('service has expected public method count', function (): void {
        $reflection = new ReflectionClass(EventIntelligenceCopilotService::class);
        $publicMethods = array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $m): bool => ! $m->isConstructor(),
        );
        expect(count($publicMethods))->toBeGreaterThanOrEqual(7);
    });
});
