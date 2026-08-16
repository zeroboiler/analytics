<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Mockery;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\PipelineOrchestratorService;
use ZeroBoiler\Analytics\Services\PipelineStep;
use ZeroBoiler\Analytics\Services\PipelineResult;

/**
 * Tests for PipelineOrchestratorService — DAG-based event pipeline orchestration.
 *
 * @covers \ZeroBoiler\Analytics\Services\PipelineOrchestratorService
 * @covers \ZeroBoiler\Analytics\Services\PipelineStep
 * @covers \ZeroBoiler\Analytics\Services\PipelineResult
 *
 * @since 187.0.0
 */

test('PipelineOrchestratorService constructs with defaults', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.pipeline_orchestrator', [])
        ->andReturn([]);

    $service = new PipelineOrchestratorService($cache, $config);

    expect($service->isEnabled())->toBeTrue();
    expect($service->pipelineNames())->toBe([]);
    expect($service->stepCount('nonexistent'))->toBe(0);
});

test('PipelineOrchestratorService disabled state returns skipped result', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.pipeline_orchestrator', [])
        ->andReturn(['enabled' => false]);

    $service = new PipelineOrchestratorService($cache, $config);

    expect($service->isEnabled())->toBeFalse();
    expect($service->quickSummary())->toBe([
        'enabled' => false,
        'pipelines' => 0,
        'status' => 'disabled',
    ]);

    $result = $service->execute('test', new AnalyticsEvent(name: 'test', category: 'test'));
    expect($result->success)->toBeTrue();
    expect($result->pipeline)->toBe('test');
});

test('PipelineOrchestratorService registers simple pipeline', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.pipeline_orchestrator', [])
        ->andReturn([]);

    $service = new PipelineOrchestratorService($cache, $config);
    $service->registerPipeline('test', [
        'step1' => new PipelineStep(
            handler: fn (AnalyticsEvent $e, array $ctx): array => ['processed' => true],
        ),
    ]);

    expect($service->pipelineNames())->toBe(['test']);
    expect($service->stepCount('test'))->toBe(1);
});

test('PipelineOrchestratorService executes pipeline with dependency order', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.pipeline_orchestrator', [])
        ->andReturn([]);

    $order = [];
    $service = new PipelineOrchestratorService($cache, $config);
    $service->registerPipeline('ordered', [
        'fetch' => new PipelineStep(
            handler: function (AnalyticsEvent $e, array $ctx) use (&$order): void {
                $order[] = 'fetch';
            },
        ),
        'validate' => new PipelineStep(
            handler: function (AnalyticsEvent $e, array $ctx) use (&$order): void {
                $order[] = 'validate';
            },
            dependencies: ['fetch'],
        ),
        'enrich' => new PipelineStep(
            handler: function (AnalyticsEvent $e, array $ctx) use (&$order): void {
                $order[] = 'enrich';
            },
            dependencies: ['validate'],
        ),
        'transform' => new PipelineStep(
            handler: function (AnalyticsEvent $e, array $ctx) use (&$order): void {
                $order[] = 'transform';
            },
            dependencies: ['enrich'],
        ),
    ]);

    $event = new AnalyticsEvent(name: 'test', category: 'test');
    $result = $service->execute('ordered', $event);

    expect($result->success)->toBeTrue();
    expect($order)->toBe(['fetch', 'validate', 'enrich', 'transform']);
    expect($result->executedSteps)->toBe(4);
    expect($result->skippedSteps)->toBe(0);
});

test('PipelineOrchestratorService rejects pipeline exceeding max steps', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.pipeline_orchestrator', [])
        ->andReturn(['max_steps' => 5]);

    $service = new PipelineOrchestratorService($cache, $config);

    $steps = [];
    for ($i = 0; $i < 6; $i++) {
        $steps["step_{$i}"] = new PipelineStep(
            handler: fn (AnalyticsEvent $e, array $ctx): array => [],
        );
    }

    $service->registerPipeline('too_many', $steps);
})->throws(\InvalidArgumentException::class, 'exceeding max of 5');

test('PipelineOrchestratorService rejects pipeline with unknown dependency', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.pipeline_orchestrator', [])
        ->andReturn([]);

    $service = new PipelineOrchestratorService($cache, $config);
    $service->registerPipeline('bad_dep', [
        'step1' => new PipelineStep(
            handler: fn (AnalyticsEvent $e, array $ctx): array => [],
            dependencies: ['nonexistent_step'],
        ),
    ]);
})->throws(\InvalidArgumentException::class, 'unknown dependencies');

test('PipelineOrchestratorService detects dependency cycles', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.pipeline_orchestrator', [])
        ->andReturn([]);

    $service = new PipelineOrchestratorService($cache, $config);
    $service->registerPipeline('cyclic', [
        'a' => new PipelineStep(handler: fn (): array => [], dependencies: ['c']),
        'b' => new PipelineStep(handler: fn (): array => [], dependencies: ['a']),
        'c' => new PipelineStep(handler: fn (): array => [], dependencies: ['b']),
    ]);
})->throws(\RuntimeException::class, 'dependency cycle');

test('PipelineOrchestratorService throws on executing nonexistent pipeline', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.pipeline_orchestrator', [])
        ->andReturn([]);

    $service = new PipelineOrchestratorService($cache, $config);
    $service->execute('ghost', new AnalyticsEvent(name: 'test', category: 'test'));
})->throws(\InvalidArgumentException::class, 'not registered');

test('PipelineOrchestratorService respects bypass predicate', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.pipeline_orchestrator', [])
        ->andReturn([]);

    $executed = false;
    $service = new PipelineOrchestratorService($cache, $config);
    $service->registerPipeline('bypass_test', [
        'step1' => new PipelineStep(
            handler: function (AnalyticsEvent $e, array $ctx) use (&$executed): void {
                $executed = true;
            },
            bypass: fn (AnalyticsEvent $e, array $ctx): bool => true,
        ),
    ]);

    $result = $service->execute('bypass_test', new AnalyticsEvent(name: 'test', category: 'test'));

    expect($result->success)->toBeTrue();
    expect($executed)->toBeFalse();
    expect($result->skippedSteps)->toBe(1);
    expect($result->skipped)->toBe(['step1' => 'bypassed']);
});

test('PipelineOrchestratorService skips dependent step when parent fails', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.pipeline_orchestrator', [])
        ->andReturn(['max_retries' => 1]);

    $results = [];
    $service = new PipelineOrchestratorService($cache, $config);
    $service->registerPipeline('dep_fail', [
        'parent' => new PipelineStep(
            handler: function (AnalyticsEvent $e, array $ctx) use (&$results): void {
                $results[] = 'parent';
                throw new \RuntimeException('parent failed');
            },
        ),
        'child' => new PipelineStep(
            handler: function (AnalyticsEvent $e, array $ctx) use (&$results): void {
                $results[] = 'child';
            },
            dependencies: ['parent'],
        ),
    ]);

    $result = $service->execute('dep_fail', new AnalyticsEvent(name: 'test', category: 'test'));

    expect($result->success)->toBeFalse();
    expect($results)->toBe(['parent']);
    expect($result->errors)->toHaveKey('parent');
    expect($result->skipped)->toBe(['child' => 'dependency_failed']);
});

test('PipelineOrchestratorService records execution history', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.pipeline_orchestrator', [])
        ->andReturn([]);

    $service = new PipelineOrchestratorService($cache, $config);
    $service->registerPipeline('history_test', [
        'step1' => new PipelineStep(handler: fn (): array => []),
    ]);

    $service->execute('history_test', new AnalyticsEvent(name: 'test', category: 'test'));

    $history = $service->history('history_test');
    expect($history)->toHaveCount(1);
    expect($history[0]['success'])->toBeTrue();
    expect($history[0]['pipeline'])->toBe('history_test');
});

test('PipelineOrchestratorService validates valid pipeline', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.pipeline_orchestrator', [])
        ->andReturn([]);

    $service = new PipelineOrchestratorService($cache, $config);
    $service->registerPipeline('valid_test', [
        'a' => new PipelineStep(handler: fn (): array => []),
        'b' => new PipelineStep(handler: fn (): array => [], dependencies: ['a']),
    ]);

    $validation = $service->validatePipeline('valid_test');

    expect($validation['valid'])->toBeTrue();
    expect($validation['steps'])->toBe(2);
    expect($validation['cycles'])->toBeFalse();
    expect($validation['execution_order'])->toBe(['a', 'b']);
});

test('PipelineOrchestratorService validates nonexistent pipeline', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.pipeline_orchestrator', [])
        ->andReturn([]);

    $service = new PipelineOrchestratorService($cache, $config);
    $validation = $service->validatePipeline('ghost');

    expect($validation['valid'])->toBeFalse();
    expect($validation['steps'])->toBe(0);
});

test('PipelineOrchestratorService health summary empty', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.pipeline_orchestrator', [])
        ->andReturn([]);

    $service = new PipelineOrchestratorService($cache, $config);
    $summary = $service->healthSummary();

    expect($summary['pipelines'])->toBe(0);
    expect($summary['total_executions'])->toBe(0);
    expect($summary['success_rate'])->toBe(100.0);
    expect($summary['avg_duration_ms'])->toBe(0.0);
});

test('PipelineOrchestratorService parallel steps executed', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.pipeline_orchestrator', [])
        ->andReturn([]);

    $order = [];
    $service = new PipelineOrchestratorService($cache, $config);
    $service->registerPipeline('parallel_test', [
        'independent1' => new PipelineStep(
            handler: function (AnalyticsEvent $e, array $ctx) use (&$order): void {
                $order[] = 'independent1';
            },
            parallel: true,
        ),
        'independent2' => new PipelineStep(
            handler: function (AnalyticsEvent $e, array $ctx) use (&$order): void {
                $order[] = 'independent2';
            },
            parallel: true,
        ),
    ]);

    $result = $service->execute('parallel_test', new AnalyticsEvent(name: 'test', category: 'test'));

    expect($result->success)->toBeTrue();
    expect($result->executedSteps)->toBe(2);
    expect($order)->toContain('independent1');
    expect($order)->toContain('independent2');
});

test('PipelineOrchestratorService step retry with eventual success', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.pipeline_orchestrator', [])
        ->andReturn([
            'max_retries' => 3,
            'backoff_multiplier' => 1.0,
        ]);

    $attempts = 0;
    $service = new PipelineOrchestratorService($cache, $config);
    $service->registerPipeline('retry_test', [
        'flaky' => new PipelineStep(
            handler: function (AnalyticsEvent $e, array $ctx) use (&$attempts): void {
                $attempts++;
                if ($attempts < 3) {
                    throw new \RuntimeException('transient failure');
                }
            },
            baseDelayMs: 1,
        ),
    ]);

    $result = $service->execute('retry_test', new AnalyticsEvent(name: 'test', category: 'test'));

    expect($result->success)->toBeTrue();
    expect($attempts)->toBe(3);
    expect($result->stepResults['flaky']['attempts'])->toBe(3);
});

test('PipelineResult skipped factory', function (): void {
    $result = PipelineResult::skipped('my_pipeline');

    expect($result->pipeline)->toBe('my_pipeline');
    expect($result->success)->toBeTrue();
    expect($result->hasSkipped())->toBeFalse();
    expect($result->hasErrors())->toBeFalse();
    expect($result->successRate())->toBe(100.0);
});

test('PipelineResult with errors reports correctly', function (): void {
    $result = new PipelineResult(
        pipeline: 'test',
        success: false,
        errors: ['step1' => 'timeout'],
        totalSteps: 3,
        executedSteps: 1,
        skippedSteps: 1,
    );

    expect($result->success)->toBeFalse();
    expect($result->hasErrors())->toBeTrue();
    expect($result->successRate())->toBe(round(1 / 3 * 100, 2));
});

test('PipelineOrchestratorService clears pipeline', function (): void {
    $cache = new CacheRepository(new ArrayStore());
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.pipeline_orchestrator', [])
        ->andReturn([]);

    $service = new PipelineOrchestratorService($cache, $config);
    $service->registerPipeline('to_clear', [
        's1' => new PipelineStep(handler: fn (): array => []),
    ]);

    $service->execute('to_clear', new AnalyticsEvent(name: 'test', category: 'test'));
    $service->clearPipeline('to_clear');

    expect($service->pipelineNames())->not->toContain('to_clear');
    expect($service->history('to_clear'))->toBe([]);
});

test('PipelineOrchestratorService service is final with strict types', function (): void {
    $reflection = new \ReflectionClass(PipelineOrchestratorService::class);
    expect($reflection->isFinal())->toBeTrue();

    $file = $reflection->getFileName();
    $contents = (string) file_get_contents((string) $file);
    expect($contents)->toContain('declare(strict_types=1)');
    expect($contents)->toContain('MIT');
});

test('PipelineOrchestratorService public methods have return types', function (): void {
    $reflection = new \ReflectionClass(PipelineOrchestratorService::class);

    foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
        $returnType = $method->getReturnType();
        expect($returnType)->not->toBeNull("Method {$method->getName()} is missing return type declaration");
    }
});
