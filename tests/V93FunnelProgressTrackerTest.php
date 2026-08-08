<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Services\FunnelProgressTracker;
use ZeroBoiler\Analytics\AnalyticsManager;

// ─── FunnelProgressTracker: Basic Structure ────────────────────────────────────

test('FunnelProgressTracker has correct namespace, strict types, and final', function (): void {
    $reflection = new ReflectionClass(FunnelProgressTracker::class);

    expect($reflection->getNamespaceName())->toBe('ZeroBoiler\\Analytics\\Services');
    expect($reflection->isFinal())->toBeTrue();

    $file = file_get_contents($reflection->getFileName());
    expect($file)->toContain('declare(strict_types=1)');
    expect($file)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
});

test('FunnelProgressTracker has version 2.93.0 in docblock', function (): void {
    $reflection = new ReflectionClass(FunnelProgressTracker::class);
    $doc = $reflection->getDocComment();

    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('2.93.0');
});

test('FunnelProgressTracker constructor has typed parameters', function (): void {
    $reflection = new ReflectionClass(FunnelProgressTracker::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->BeNull();

    $params = $constructor->getParameters();
    expect($params)->toHaveCount(3);

    // AnalyticsManager
    expect($params[0]->getName())->toBe('manager');
    expect($params[0]->getType())->not->BeNull();
    expect($params[0]->isPromoted())->toBeTrue();

    // CacheRepository
    expect($params[1]->getName())->toBe('cache');
    expect($params[1]->getType())->not->BeNull();
    expect($params[1]->isPromoted())->toBeTrue();

    // ConfigRepository (not promoted)
    expect($params[2]->getName())->toBe('config');
    expect($params[2]->getType())->not->BeNull();
    expect($params[2]->isPromoted())->toBeFalse();
});

// ─── FunnelProgressTracker: Public Methods ───────────────────────────────────

test('FunnelProgressTracker has all public methods with return types', function (): void {
    $reflection = new ReflectionClass(FunnelProgressTracker::class);
    $publicMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

    $expectedMethods = ['track', 'getProgress', 'isCompleted', 'reset', 'getAllProgress', '__construct'];
    $methodNames = array_map(fn (ReflectionMethod $m): string => $m->getName(), $publicMethods);

    foreach ($expectedMethods as $method) {
        expect($methodNames)->toContain($method);
    }

    // Verify return types
    foreach ($publicMethods as $method) {
        $name = $method->getName();
        if ($name === '__construct') {
            continue;
        }
        $returnType = $method->getReturnType();
        expect($returnType)->not->BeNull();
    }
});

test('track() method has correct parameter types', function (): void {
    $method = new ReflectionMethod(FunnelProgressTracker::class, 'track');
    $params = $method->getParameters();

    expect($params)->toHaveCount(6);
    expect($params[0]->getName())->toBe('funnelName');
    expect((string) $params[0]->getType())->toBe('string');
    expect($params[1]->getName())->toBe('stepName');
    expect((string) $params[1]->getType())->toBe('string');
    expect($params[2]->getName())->toBe('identity');
    expect((string) $params[2]->getType())->toBe('string');
    expect($params[3]->getName())->toBe('stepNumber');
    expect((string) $params[3]->getType())->toBe('int');
    expect($params[4]->getName())->toBe('totalSteps');
    expect((string) $params[4]->getType())->toBe('int');
    expect($params[5]->getName())->toBe('params');
    expect((string) $params[5]->getType())->toBe('array');
    expect($params[5]->isDefaultValueAvailable())->toBeTrue();

    $returnType = $method->getReturnType();
    expect($returnType)->not->BeNull();
    expect((string) $returnType)->toBe('array');
});

test('getProgress() returns nullable array', function (): void {
    $method = new ReflectionMethod(FunnelProgressTracker::class, 'getProgress');

    $returnType = $method->getReturnType();
    expect($returnType)->not->BeNull();
    expect((string) $returnType)->toBe('?array');
});

test('isCompleted() returns bool', function (): void {
    $method = new ReflectionMethod(FunnelProgressTracker::class, 'isCompleted');

    $returnType = $method->getReturnType();
    expect($returnType)->not->BeNull();
    expect((string) $returnType)->toBe('bool');
});

test('reset() returns void', function (): void {
    $method = new ReflectionMethod(FunnelProgressTracker::class, 'reset');

    $returnType = $method->getReturnType();
    expect($returnType)->not->BeNull();
    expect((string) $returnType)->toBe('void');
});

// ─── FunnelProgressTracker: Functional Tests (with mocks) ───────────────────

test('FunnelProgressTracker track() returns correct structure', function (): void {
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
    $config = mock(\Illuminate\Contracts\Config\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.funnel_progress', [])
        ->andReturn([]);

    // No previous state
    $cache->shouldReceive('get')
        ->andReturn(null);

    $cache->shouldReceive('put')->andReturn(true);
    $manager->shouldReceive('track')->andReturnNull();

    $tracker = new FunnelProgressTracker($manager, $cache, $config);

    $result = $tracker->track('signup', 'form_start', 'user-123', 1, 3);

    expect($result)->toBeArray();
    expect($result)->toHaveKeys([
        'funnel_name', 'step_name', 'step_number', 'total_steps',
        'completion_pct', 'is_complete', 'is_advancement', 'is_regression',
        'elapsed_seconds', 'previous_step', 'previous_step_number',
        'first_seen', 'last_updated',
    ]);
    expect($result['funnel_name'])->toBe('signup');
    expect($result['step_name'])->toBe('form_start');
    expect($result['step_number'])->toBe(1);
    expect($result['total_steps'])->toBe(3);
    expect($result['completion_pct'])->toBe(33.3);
    expect($result['is_complete'])->toBeFalse();
    expect($result['is_advancement'])->toBeFalse(); // first step, no previous
    expect($result['is_regression'])->toBeFalse();
    expect($result['previous_step'])->toBeNull();
    expect($result['previous_step_number'])->toBeNull();
});

test('FunnelProgressTracker detects advancement', function (): void {
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
    $config = mock(\Illuminate\Contracts\Config\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.funnel_progress', [])
        ->andReturn([]);

    // Previous state: step 1
    $cache->shouldReceive('get')
        ->andReturn([
            'step_name' => 'form_start',
            'step_number' => 1,
            'step_started_at' => '2026-08-08T10:00:00+00:00',
        ]);

    $cache->shouldReceive('put')->andReturn(true);
    $manager->shouldReceive('track')->andReturnNull();

    $tracker = new FunnelProgressTracker($manager, $cache, $config);

    $result = $tracker->track('signup', 'email_confirm', 'user-123', 2, 3);

    expect($result['is_advancement'])->toBeTrue();
    expect($result['is_regression'])->toBeFalse();
    expect($result['previous_step'])->toBe('form_start');
    expect($result['previous_step_number'])->toBe(1);
    expect($result['elapsed_seconds'])->not->toBeNull();
    expect($result['completion_pct'])->toBe(66.7);
});

test('FunnelProgressTracker detects regression', function (): void {
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
    $config = mock(\Illuminate\Contracts\Config\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.funnel_progress', [])
        ->andReturn([]);

    // Previous state: step 3
    $cache->shouldReceive('get')
        ->andReturn([
            'step_name' => 'payment',
            'step_number' => 3,
            'step_started_at' => '2026-08-08T10:00:00+00:00',
        ]);

    $cache->shouldReceive('put')->andReturn(true);
    $manager->shouldReceive('track')->andReturnNull();

    $tracker = new FunnelProgressTracker($manager, $cache, $config);

    $result = $tracker->track('signup', 'form_start', 'user-123', 1, 3);

    expect($result['is_advancement'])->toBeFalse();
    expect($result['is_regression'])->toBeTrue();
});

test('FunnelProgressTracker detects completion and dispatches funnel_completed', function (): void {
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
    $config = mock(\Illuminate\Contracts\Config\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.funnel_progress', [])
        ->andReturn([]);

    // Previous state: step 2 of 3
    $cache->shouldReceive('get')
        ->andReturn([
            'step_name' => 'email_confirm',
            'step_number' => 2,
            'first_seen' => '2026-08-08T10:00:00+00:00',
            'step_started_at' => '2026-08-08T10:05:00+00:00',
        ]);

    $cache->shouldReceive('put')->andReturn(true);
    $manager->shouldReceive('track')->twice(); // funnel_step + funnel_completed

    $tracker = new FunnelProgressTracker($manager, $cache, $config);

    $result = $tracker->track('signup', 'complete', 'user-123', 3, 3);

    expect($result['is_complete'])->toBeTrue();
    expect($result['completion_pct'])->toBe(100.0);
});

test('FunnelProgressTracker does not re-dispatch completion', function (): void {
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
    $config = mock(\Illuminate\Contracts\Config\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.funnel_progress', [])
        ->andReturn([]);

    // Previous state: already completed
    $cache->shouldReceive('get')
        ->andReturn([
            'step_name' => 'complete',
            'step_number' => 3,
            'completed' => true,
            'completed_at' => '2026-08-08T12:00:00+00:00',
            'first_seen' => '2026-08-08T10:00:00+00:00',
            'step_started_at' => '2026-08-08T11:00:00+00:00',
        ]);

    $cache->shouldReceive('put')->andReturn(true);
    $manager->shouldReceive('track')->once(); // only funnel_step, not funnel_completed

    $tracker = new FunnelProgressTracker($manager, $cache, $config);

    $result = $tracker->track('signup', 'complete', 'user-123', 3, 3);

    expect($result['is_complete'])->toBeTrue();
});

test('FunnelProgressTracker getProgress() returns null when no state', function (): void {
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
    $config = mock(\Illuminate\Contracts\Config\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.funnel_progress', [])
        ->andReturn([]);

    $cache->shouldReceive('get')->andReturn(null);

    $tracker = new FunnelProgressTracker($manager, $cache, $config);

    expect($tracker->getProgress('signup', 'user-123'))->toBeNull();
});

test('FunnelProgressTracker isCompleted() returns false when not completed', function (): void {
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
    $config = mock(\Illuminate\Contracts\Config\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.funnel_progress', [])
        ->andReturn([]);

    $cache->shouldReceive('get')->andReturn(null);

    $tracker = new FunnelProgressTracker($manager, $cache, $config);

    expect($tracker->isCompleted('signup', 'user-123'))->toBeFalse();
});

test('FunnelProgressTracker reset() clears both keys', function (): void {
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
    $config = mock(\Illuminate\Contracts\Config\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.funnel_progress', [])
        ->andReturn([]);

    $cache->shouldReceive('forget')
        ->withArgs(function (string $key): bool {
            return str_starts_with($key, 'zb_funnel_progress_signup_');
        })
        ->twice()
        ->andReturn(true);

    $tracker = new FunnelProgressTracker($manager, $cache, $config);

    $tracker->reset('signup', 'user-123');
});

test('FunnelProgressTracker getAllProgress() scans known funnels', function (): void {
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
    $config = mock(\Illuminate\Contracts\Config\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.funnel_progress', [])
        ->andReturn([]);

    // Only signup has progress
    $cache->shouldReceive('get')
        ->andReturnValues([
            ['step_name' => 'form_start', 'step_number' => 1, 'total_steps' => 3, 'completion_pct' => 33.3, 'first_seen' => '2026-08-08T10:00:00'], // signup
            null, // checkout
            null, // onboarding
            null, // trial
            null, // activation
            false, // _completed for signup
        ]);

    $tracker = new FunnelProgressTracker($manager, $cache, $config);

    $all = $tracker->getAllProgress('user-123');

    expect($all)->toBeArray();
    expect($all)->toHaveKey('signup');
    expect($all['signup']['step_name'])->toBe('form_start');
    expect($all['signup']['completion_pct'])->toBe(33.3);
    expect($all)->not->toHaveKey('checkout');
});

test('FunnelProgressTracker getAllProgress() uses custom funnel names from config', function (): void {
    $manager = mock(AnalyticsManager::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
    $config = mock(\Illuminate\Contracts\Config\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.funnel_progress', [])
        ->andReturn([
            'known_funnels' => ['custom_funnel_a', 'custom_funnel_b'],
        ]);

    $cache->shouldReceive('get')
        ->andReturnValues([
            ['step_name' => 'step1', 'step_number' => 1, 'total_steps' => 2, 'completion_pct' => 50.0, 'first_seen' => '2026-08-08'], // custom_funnel_a
            null, // custom_funnel_b
            false, // _completed for custom_funnel_a
        ]);

    $tracker = new FunnelProgressTracker($manager, $cache, $config);

    $all = $tracker->getAllProgress('user-456');

    expect($all)->toHaveKey('custom_funnel_a');
    expect($all)->not->toHaveKey('custom_funnel_b');
    expect($all)->not->toHaveKey('signup');
});

// ─── AnalyticsManager::funnelProgress() ──────────────────────────────────────

test('AnalyticsManager has funnelProgress() method', function (): void {
    $manager = new ReflectionClass(AnalyticsManager::class);

    expect($manager->hasMethod('funnelProgress'))->toBeTrue();

    $method = $manager->getMethod('funnelProgress');
    expect($method->isPublic())->toBeTrue();

    $params = $method->getParameters();
    expect($params)->toHaveCount(6);
    expect($params[0]->getName())->toBe('funnelName');
    expect($params[2]->getName())->toBe('identity');

    $returnType = $method->getReturnType();
    expect($returnType)->not->BeNull();
    expect((string) $returnType)->toBe('array');
});

// ─── ServiceProvider Registration ────────────────────────────────────────────

test('ServiceProvider registers FunnelProgressTracker as singleton', function (): void {
    $file = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

    expect($file)->toContain('FunnelProgressTracker::class');
    expect($file)->toContain('singleton(FunnelProgressTracker');
    expect($file)->toContain('new FunnelProgressTracker(');
});

test('ServiceProvider imports FunnelProgressTracker', function (): void {
    $file = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

    expect($file)->toContain('use ZeroBoiler\\Analytics\\Services\\FunnelProgressTracker');
});

// ─── Facade ──────────────────────────────────────────────────────────────────

test('Analytics Facade documents funnelProgress method', function (): void {
    $file = file_get_contents(__DIR__ . '/../src/Facades/Analytics.php');

    expect($file)->toContain('@method static array');
    expect($file)->toContain('funnelProgress(');
});

// ─── Config ──────────────────────────────────────────────────────────────────

test('config has funnel_progress section', function (): void {
    $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');

    expect($config)->toContain("'funnel_progress'");
    expect($config)->toContain('ANALYTICS_FUNNEL_PROGRESS_ENABLED');
    expect($config)->toContain('known_funnels');
    expect($config)->toContain("'signup'");
    expect($config)->toContain("'checkout'");
    expect($config)->toContain("'onboarding'");
    expect($config)->toContain("'trial'");
    expect($config)->toContain("'activation'");
});

// ─── Version Consistency ─────────────────────────────────────────────────────

test('config catalog_version is 2.93.0', function (): void {
    $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
    expect($config)->toContain("'catalog_version' => '2.93.0'");
});

test('composer.json version is 2.93.0', function (): void {
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('2.93.0');
});

test('JS client version is 2.93.0', function (): void {
    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($js)->toContain("return '2.93.0'");
});

test('AnalyticsManager::version() is 2.93.0', function (): void {
    $file = file_get_contents(__DIR__ . '/../src/AnalyticsManager.php');
    expect($file)->toContain("return '2.93.0'");
});

test('AnalyticsEvent::VERSION is 2.93.0', function (): void {
    $file = file_get_contents(__DIR__ . '/../src/DTO/AnalyticsEvent.php');
    expect($file)->toContain("'2.93.0'");
});

// ─── EventCatalog Reference ───────────────────────────────────────────────────

test('EventCatalog::funnelTemplates() references FunnelProgressTracker', function (): void {
    $file = file_get_contents(__DIR__ . '/../src/Events/EventCatalog.php');
    expect($file)->toContain('FunnelProgressTracker::track()');
});

test('EventCatalog has funnelTemplates method', function (): void {
    $catalog = new ReflectionClass(\ZeroBoiler\Analytics\Events\EventCatalog::class);

    expect($catalog->hasMethod('funnelTemplates'))->toBeTrue();

    $method = $catalog->getMethod('funnelTemplates');
    expect($method->isPublic())->toBeTrue();
    expect($method->isStatic())->toBeTrue();
});
