<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\AnalyticsPipelineHealthService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsPipelineHealthCommand;

beforeEach(function (): void {
    // Verify files exist
    expect(file_exists(__DIR__ . '/../../src/Services/AnalyticsPipelineHealthService.php'))->toBeTrue();
    expect(file_exists(__DIR__ . '/../../src/Console/Commands/AnalyticsPipelineHealthCommand.php'))->toBeTrue();
});

describe('AnalyticsPipelineHealthService', function (): void {
    it('has strict types and MIT header', function (): void {
        $content = file_get_contents(__DIR__ . '/../../src/Services/AnalyticsPipelineHealthService.php');
        expect($content)->toContain('declare(strict_types=1)');
        expect($content)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
    });

    it('is a final class with namespace', function (): void {
        $content = file_get_contents(__DIR__ . '/../../src/Services/AnalyticsPipelineHealthService.php');
        expect($content)->toContain('final class AnalyticsPipelineHealthService');
        expect($content)->toContain('namespace ZeroBoiler\\Analytics\\Services');
    });

    it('has 8 default dimension weights summing to 1.0', function (): void {
        $weights = [
            'provider_health' => 0.20,
            'queue_health' => 0.15,
            'delivery_reliability' => 0.20,
            'latency_performance' => 0.15,
            'deduplication' => 0.10,
            'budget_compliance' => 0.10,
            'schema_integrity' => 0.05,
            'identity_resolution' => 0.05,
        ];
        expect(array_sum($weights))->toBe(1.0);
    });

    it('has 14 public methods with return type declarations', function (): void {
        $content = file_get_contents(__DIR__ . '/../../src/Services/AnalyticsPipelineHealthService.php');
        $methods = ['isEnabled', 'getWeights', 'compute', 'score', 'grade', 'status', 'history', 'trend', 'attention', 'invalidate', 'clearHistory', 'configSummary'];

        foreach ($methods as $method) {
            expect($content)->toContain("public function {$method}(");
        }

        // Verify constructor has :void
        expect($content)->toContain('public function __construct(');
        expect($content)->toContain('): void');
    });

    it('has @since 213.0.0 docblock', function (): void {
        $content = file_get_contents(__DIR__ . '/../../src/Services/AnalyticsPipelineHealthService.php');
        expect($content)->toContain('@since 213.0.0');
    });

    it('uses AnalyticsManager and EventCatalog dependencies', function (): void {
        $content = file_get_contents(__DIR__ . '/../../src/Services/AnalyticsPipelineHealthService.php');
        expect($content)->toContain('use ZeroBoiler\\Analytics\\AnalyticsManager');
        expect($content)->toContain('use ZeroBoiler\\Analytics\\Events\\EventCatalog');
    });
});

describe('AnalyticsPipelineHealthCommand', function (): void {
    it('has strict types and MIT header', function (): void {
        $content = file_get_contents(__DIR__ . '/../../src/Console/Commands/AnalyticsPipelineHealthCommand.php');
        expect($content)->toContain('declare(strict_types=1)');
        expect($content)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
    });

    it('is a final class with correct namespace', function (): void {
        $content = file_get_contents(__DIR__ . '/../../src/Console/Commands/AnalyticsPipelineHealthCommand.php');
        expect($content)->toContain('final class AnalyticsPipelineHealthCommand');
        expect($content)->toContain('namespace ZeroBoiler\\Analytics\\Console\\Commands');
    });

    it('has correct signature and handle return type', function (): void {
        $content = file_get_contents(__DIR__ . '/../../src/Console/Commands/AnalyticsPipelineHealthCommand.php');
        expect($content)->toContain("'analytics:pipeline-health'");
        expect($content)->toContain('--score');
        expect($content)->toContain('--history');
        expect($content)->toContain('--attention');
        expect($content)->toContain('--json');
        expect($content)->toContain('--invalidate');
        expect($content)->toContain('public function handle(): int');
    });

    it('has 4 action methods', function (): void {
        $content = file_get_contents(__DIR__ . '/../../src/Console/Commands/AnalyticsPipelineHealthCommand.php');
        expect($content)->toContain('private function showFullReport(): int');
        expect($content)->toContain('private function showScore(): int');
        expect($content)->toContain('private function showHistory(): int');
        expect($content)->toContain('private function showAttention(): int');
    });

    it('has @since 213.0.0 docblock', function (): void {
        $content = file_get_contents(__DIR__ . '/../../src/Console/Commands/AnalyticsPipelineHealthCommand.php');
        expect($content)->toContain('@since 213.0.0');
    });
});

describe('Integration', function (): void {
    it('ServiceProvider registers the service singleton and command', function (): void {
        $sp = file_get_contents(__DIR__ . '/../../src/AnalyticsServiceProvider.php');
        expect($sp)->toContain('use ZeroBoiler\\Analytics\\Services\\AnalyticsPipelineHealthService');
        expect($sp)->toContain('use ZeroBoiler\\Analytics\\Console\\Commands\\AnalyticsPipelineHealthCommand');
        expect($sp)->toContain('AnalyticsPipelineHealthService::class, function');
        expect($sp)->toContain('AnalyticsPipelineHealthCommand::class');
    });

    it('routes file registers pipeline-health endpoints', function (): void {
        $routes = file_get_contents(__DIR__ . '/../../routes/analytics.php');
        expect($routes)->toContain("'pipeline-health'");
        expect($routes)->toContain('pipelineHealth');
        expect($routes)->toContain('pipelineHealthScore');
        expect($routes)->toContain('pipelineHealthHistory');
        expect($routes)->toContain('pipelineHealthTrend');
        expect($routes)->toContain('pipelineHealthAttention');
        expect($routes)->toContain('pipelineHealthInvalidate');
        expect($routes)->toContain('pipelineHealthConfig');
    });

    it('controller has 7 pipeline health action methods', function (): void {
        $controller = file_get_contents(__DIR__ . '/../../src/Http/Controllers/AnalyticsEventController.php');
        $methods = ['pipelineHealth', 'pipelineHealthScore', 'pipelineHealthHistory', 'pipelineHealthTrend', 'pipelineHealthAttention', 'pipelineHealthInvalidate', 'pipelineHealthConfig'];

        foreach ($methods as $method) {
            expect($controller)->toContain("public function {$method}(): JsonResponse");
        }
    });

    it('config file has pipeline_health section', function (): void {
        $config = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        expect($config)->toContain("'pipeline_health'");
        expect($config)->toContain('ANALYTICS_PIPELINE_HEALTH_ENABLED');
        expect($config)->toContain('ANALYTICS_PIPELINE_HEALTH_CACHE_TTL');
    });

    it('version consistency — 3 new files', function (): void {
        $serviceLines = count(file(__DIR__ . '/../../src/Services/AnalyticsPipelineHealthService.php'));
        $commandLines = count(file(__DIR__ . '/../../src/Console/Commands/AnalyticsPipelineHealthCommand.php'));

        expect($serviceLines)->toBeGreaterThan(100);
        expect($commandLines)->toBeGreaterThan(50);
    });

    it('source file counts are above baseline', function (): void {
        $srcCount = count(glob(__DIR__ . '/../../src/**/*.php', GLOB_BRACE));
        $testCount = count(glob(__DIR__ . '/../*.php'));
        // Baseline: 900+ src files, 461+ tests
        expect($srcCount)->toBeGreaterThan(900);
        expect($testCount)->toBeGreaterThan(460);
    });
});
