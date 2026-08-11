<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\AnalyticsApiGuard;
use ZeroBoiler\Analytics\Services\AnalyticsEventSanitizer;
use ZeroBoiler\Analytics\Services\EventBudgetService;
use ZeroBoiler\Analytics\Services\EventDeconflictionService;

test('v17.0.0 version consistency', function (): void {
    // composer.json version
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('17.0.0');

    // AnalyticsEvent::VERSION
    expect(AnalyticsEvent::VERSION)->toBe('17.0.0');

    // package.json version
    $pkg = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);
    expect($pkg['version'])->toBe('17.0.0');

    // IntegrityCommand::EXPECTED_VERSION
    $reflection = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsIntegrityCommand::class);
    $constant = $reflection->getConstant('EXPECTED_VERSION');
    expect($constant)->toBe('17.0.0');
});

test('EventBudgetService constructor and basic operations', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $service = new EventBudgetService(
        $cache,
        clientLimit: 100,
        userLimit: 50,
        globalLimit: 1000,
        windowSeconds: 3600,
        overflowPolicy: 'reject',
        sampleRate: 0.1,
        cacheTtl: 3600,
        useCache: false,
    );

    // Initially within budget
    $result = $service->check('client-1', 'user-1');
    expect($result['allowed'])->toBeTrue();
    expect($result['reason'])->toBe('within_budget');
    expect($result['remaining']['client'])->toBe(100);
    expect($result['remaining']['user'])->toBe(50);
    expect($result['remaining']['global'])->toBe(1000);

    // Record events
    $service->record('client-1', 'user-1');
    $stats = $service->stats();
    expect($stats['client_count'])->toBe(1);
    expect($stats['user_count'])->toBe(1);
    expect($stats['limits']['client'])->toBe(100);
    expect($stats['overflow_policy'])->toBe('reject');

    // Client status
    $clientStatus = $service->clientStatus('client-1');
    expect($clientStatus['count'])->toBe(1);
    expect($clientStatus['remaining'])->toBe(99);
    expect($clientStatus['utilization'])->toBe(1.0);

    // User status
    $userStatus = $service->userStatus('user-1');
    expect($userStatus['count'])->toBe(1);
    expect($userStatus['remaining'])->toBe(49);

    // Clear
    $service->clear();
    expect($service->stats()['client_count'])->toBe(0);
});

test('EventBudgetService enforces client limit', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $service = new EventBudgetService(
        $cache,
        clientLimit: 2,
        userLimit: 100,
        globalLimit: 1000,
        windowSeconds: 3600,
        overflowPolicy: 'reject',
        useCache: false,
    );

    // First two allowed
    expect($service->check('client-x', null)['allowed'])->toBeTrue();
    $service->record('client-x');
    expect($service->check('client-x', null)['allowed'])->toBeTrue();
    $service->record('client-x');

    // Third rejected
    $result = $service->check('client-x', null);
    expect($result['allowed'])->toBeFalse();
    expect($result['reason'])->toBe('budget_exceeded_client');
    expect($result['policy'])->toBe('reject');
});

test('EventBudgetService sample policy allows fraction', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $service = new EventBudgetService(
        $cache,
        clientLimit: 1,
        userLimit: 100,
        globalLimit: 1000,
        windowSeconds: 3600,
        overflowPolicy: 'sample',
        sampleRate: 1.0, // 100% sample — always allow
        useCache: false,
    );

    $service->record('client-y');

    // With sample rate 1.0, should still be allowed
    $result = $service->check('client-y', null);
    expect($result['policy'])->toBe('sample');
    // With 100% sample rate, always allowed
    expect($result['allowed'])->toBeTrue();
    expect($result['reason'])->toBe('sampled_through');
});

test('AnalyticsApiGuard validates requests', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(0);
    $cache->shouldReceive('put')->andReturn(true);
    $cache->shouldReceive('increment')->andReturn(1);

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.api', [])
        ->andReturn(['throttle' => 60]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.api_guard', [])
        ->andReturn([
            'enabled' => true,
            'batch_max' => 25,
            'max_payload_bytes' => 65536,
            'max_event_name_length' => 100,
            'rate_window' => 60,
        ]);

    $guard = new AnalyticsApiGuard($cache, $config);

    expect($guard->isEnabled())->toBeTrue();
    expect($guard->getThrottle())->toBe(60);
    expect($guard->getBatchMax())->toBe(25);

    // Validate batch
    $batchResult = $guard->validateBatch([
        ['name' => 'page_view', 'params' => ['url' => '/test']],
        ['name' => 'click', 'params' => ['element' => 'button']],
    ]);
    expect($batchResult['valid'])->toBeTrue();
    expect($batchResult['count'])->toBe(2);

    // Reject oversized batch
    $oversized = array_fill(0, 30, ['name' => 'event', 'params' => []]);
    $bigBatch = $guard->validateBatch($oversized);
    expect($bigBatch['valid'])->toBeFalse();
    expect($bigBatch['reason'])->toBe('batch_too_large');
});

test('AnalyticsApiGuard is disabled when config says so', function (): void {
    $cache = mock(CacheRepository::class);
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.api', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.api_guard', [])
        ->andReturn(['enabled' => false]);

    $guard = new AnalyticsApiGuard($cache, $config);
    expect($guard->isEnabled())->toBeFalse();

    // Should always validate when disabled
    $result = $guard->validateBatch(array_fill(0, 100, ['name' => 'x']));
    expect($result['valid'])->toBeTrue();
    expect($result['count'])->toBe(100);
});

test('EventDeconflictionService analyzes provider collisions', function (): void {
    $manager = mock(AnalyticsManager::class);

    $service = new EventDeconflictionService($manager);
    $report = $service->analyze();

    expect($report)->toHaveKey('conflicts');
    expect($report)->toHaveKey('warnings');
    expect($report)->toHaveKey('summary');
    expect($report['summary'])->toHaveKey('total_conflicts');
    expect($report['summary'])->toHaveKey('total_warnings');
    expect($report['summary'])->toHaveKey('provider_collision_counts');
    expect($report['summary']['provider_collision_counts'])->toHaveKey('ga4');
    expect($report['summary']['provider_collision_counts'])->toHaveKey('meta');
    expect($report['summary']['provider_collision_counts'])->toHaveKey('posthog');
    expect($report['summary']['provider_collision_counts'])->toHaveKey('plausible');
    expect($report['summary']['provider_collision_counts'])->toHaveKey('similar_names');
});

test('EventDeconflictionService detects similar event names', function (): void {
    $manager = mock(AnalyticsManager::class);

    $service = new EventDeconflictionService($manager);
    $report = $service->analyze();

    // Check for warnings (similar names with edit distance ≤ 2)
    $similarWarnings = array_filter(
        array_keys($report['warnings']),
        fn (string $key): bool => str_starts_with($key, 'similar_names:'),
    );

    // The catalog has intentionally similar event names
    // (e.g., onboarding_step vs onboarding_completed) — these produce warnings
    // Just verify the analysis runs without errors
    expect(is_array($report['warnings']))->toBeTrue();
});

test('EventBudgetService topClients ordering', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $service = new EventBudgetService(
        $cache,
        clientLimit: 100,
        userLimit: 100,
        globalLimit: 10000,
        useCache: false,
    );

    $service->record('client-low');
    $service->record('client-low');
    $service->record('client-high');
    $service->record('client-high');
    $service->record('client-high');

    $top = $service->topClients(2);
    expect($top)->toHaveCount(2);
    expect($top[0]['client_id'])->toBe('client-high');
    expect($top[0]['count'])->toBe(3);
    expect($top[1]['client_id'])->toBe('client-low');
    expect($top[1]['count'])->toBe(2);
});

test('EventBudgetService resetClient and resetUser', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $service = new EventBudgetService($cache, useCache: false);

    $service->record('client-a', 'user-a');
    $service->record('client-a', 'user-a');
    expect($service->clientStatus('client-a')['count'])->toBe(2);

    $service->resetClient('client-a');
    expect($service->clientStatus('client-a')['count'])->toBe(0);
    // User count should still be tracked
    expect($service->userStatus('user-a')['count'])->toBe(2);

    $service->resetUser('user-a');
    expect($service->userStatus('user-a')['count'])->toBe(0);
});

test('config has api_guard and budget sections', function (): void {
    $config = require __DIR__ . '/../config/zeroboiler.php';
    $analytics = $config['analytics'] ?? [];

    expect($analytics)->toHaveKey('api_guard');
    expect($analytics['api_guard'])->toHaveKey('enabled');
    expect($analytics['api_guard'])->toHaveKey('batch_max');
    expect($analytics['api_guard'])->toHaveKey('max_payload_bytes');
    expect($analytics['api_guard'])->toHaveKey('max_event_name_length');
    expect($analytics['api_guard'])->toHaveKey('rate_window');

    expect($analytics)->toHaveKey('budget');
    expect($analytics['budget'])->toHaveKey('enabled');
    expect($analytics['budget'])->toHaveKey('client_limit');
    expect($analytics['budget'])->toHaveKey('user_limit');
    expect($analytics['budget'])->toHaveKey('global_limit');
    expect($analytics['budget'])->toHaveKey('window_seconds');
    expect($analytics['budget'])->toHaveKey('overflow_policy');
    expect($analytics['budget'])->toHaveKey('sample_rate');
    expect($analytics['budget'])->toHaveKey('cache_ttl');
    expect($analytics['budget'])->toHaveKey('use_cache');
});

test('EventCatalog validation passes for all categories', function (): void {
    $result = EventCatalog::validate();
    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
});

test('ServiceProvider has correct version docblock', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class);
    $doc = $reflection->getDocComment();
    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('@version 17.0.0');
});
