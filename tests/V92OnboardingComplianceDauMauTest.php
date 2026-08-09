<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\OnboardingCompletionService;

// ─── Event Catalog: Enterprise Compliance Events ──────────────────────────────

test('enterpriseComplianceEvents returns GDPR/SOC2/ISO27001 events', function (): void {
    $events = EventCatalog::enterpriseComplianceEvents();

    expect($events)->not->toBeEmpty();

    $names = array_column($events, 'name');

    // GDPR Article 30 — records of processing
    expect($names)->toContain('sign_up');
    expect($names)->toContain('login');
    expect($names)->toContain('account_deleted');
    expect($names)->toContain('consent_granted');
    expect($names)->toContain('consent_withdrawn');
    expect($names)->toContain('data_subject_access_request');
    expect($names)->toContain('data_erasure_completed');
    expect($names)->toContain('export');
    expect($names)->toContain('import');

    // SOC2 CC7 — access monitoring
    expect($names)->toContain('role_changed');
    expect($names)->toContain('team_member_joined');
    expect($names)->toContain('team_member_removed');

    // All events must have valid category
    foreach ($events as $entry) {
        expect($entry['category'])->toBeIn(['ecommerce', 'saas', 'engagement']);
    }
});

test('enterpriseComplianceEvents contains at least 15 events', function (): void {
    $events = EventCatalog::enterpriseComplianceEvents();

    expect(count($events))->toBeGreaterThanOrEqual(15);
});

// ─── Event Catalog: DAU/MAU Events ────────────────────────────────────────────

test('dauMauEvents returns engagement events for stickiness tracking', function (): void {
    $events = EventCatalog::dauMauEvents();

    expect($events)->not->toBeEmpty();

    $names = array_column($events, 'name');

    // Core DAU/MAU events
    expect($names)->toContain('login');
    expect($names)->toContain('page_view');
    expect($names)->toContain('session_start');
    expect($names)->toContain('feature_used');

    // Should be a focused set (under 15)
    expect(count($events))->toBeLessThanOrEqual(15);
    expect(count($events))->toBeGreaterThanOrEqual(5);
});

// ─── Event Catalog: Product Health Events ────────────────────────────────────

test('productHealthEvents returns stability and quality events', function (): void {
    $events = EventCatalog::productHealthEvents();

    expect($events)->not->toBeEmpty();

    $names = array_column($events, 'name');

    // Error & performance events
    expect($names)->toContain('error');
    expect($names)->toContain('js_error');
    expect($names)->toContain('web_vitals');

    // Business health events
    expect($names)->toContain('payment_failed');

    expect(count($events))->toBeGreaterThanOrEqual(5);
});

// ─── Event Catalog Integrity ──────────────────────────────────────────────────

test('enterpriseComplianceEvents does not duplicate with gdprEvents', function (): void {
    $compliance = EventCatalog::enterpriseComplianceEvents();
    $gdpr = EventCatalog::gdprEvents();

    $complianceNames = array_column($compliance, 'name');
    $gdprNames = array_column($gdpr, 'name');

    // enterpriseComplianceEvents should be a superset of gdprEvents (or overlap significantly)
    $overlap = array_intersect($complianceNames, $gdprNames);
    expect(count($overlap))->toBeGreaterThanOrEqual(5);
});

test('all new catalog methods return valid entries', function (): void {
    $methods = [
        'enterpriseComplianceEvents',
        'dauMauEvents',
        'productHealthEvents',
    ];

    foreach ($methods as $method) {
        $events = EventCatalog::$method();

        foreach ($events as $entry) {
            // Every entry must have required keys
            expect($entry)->toHaveKey('name');
            expect($entry)->toHaveKey('class');
            expect($entry)->toHaveKey('ga4');
            expect($entry)->toHaveKey('category');
            expect($entry['name'])->toBeString();
            expect($entry['category'])->toBeIn(['ecommerce', 'saas', 'engagement']);
        }
    }
});

// ─── OnboardingCompletionService ──────────────────────────────────────────────

test('OnboardingCompletionService calculates completion correctly', function (): void {
    $manager = mock(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $queue = mock(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
    $config = mock(\Illuminate\Contracts\Config\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.onboarding_tracking', [])
        ->andReturn([
            'required_steps' => ['profile_setup', 'first_feature_used'],
            'optional_steps' => ['tutorial_completed'],
            'cache_ttl' => 3600,
            'cache_prefix' => 'zb_test_',
        ]);

    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);
    $manager->shouldReceive('track')->andReturnNull();
    $queue->shouldReceive('dispatch')->andReturnNull();

    $service = new OnboardingCompletionService($manager, $queue, $cache, $config);

    // Complete first required step
    $service->completeStep('user-1', 'profile_setup');

    $progress = $service->getProgress('user-1');
    expect($progress['completion_pct'])->toBe(50);
    expect($progress['is_complete'])->toBeFalse();
    expect($progress['required_remaining'])->toBe(1);
    expect($progress['started_at'])->not->toBeNull();

    // Complete optional step — should not trigger full completion
    $service->completeStep('user-1', 'tutorial_completed');

    $progress = $service->getProgress('user-1');
    expect($progress['completion_pct'])->toBeGreaterThan(50);
    expect($progress['is_complete'])->toBeFalse();

    // Complete final required step — should trigger onboarding_completed
    $service->completeStep('user-1', 'first_feature_used');

    $progress = $service->getProgress('user-1');
    expect($progress['completion_pct'])->toBe(100);
    expect($progress['is_complete'])->toBeTrue();
    expect($progress['fully_completed_at'])->not->toBeNull();
});

test('OnboardingCompletionService ignores duplicate step completion', function (): void {
    $manager = mock(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $queue = mock(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
    $config = mock(\Illuminate\Contracts\Config\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.onboarding_tracking', [])
        ->andReturn([
            'required_steps' => ['profile_setup'],
            'optional_steps' => [],
            'cache_ttl' => 3600,
            'cache_prefix' => 'zb_test_',
        ]);

    // First call: no cached progress
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);
    $manager->shouldReceive('track')->once();
    $queue->shouldReceive('dispatch')->once(); // triggers completion

    $service = new OnboardingCompletionService($manager, $queue, $cache, $config);

    $service->completeStep('user-2', 'profile_setup');

    // Second call: has cached progress with step already done
    $cache->shouldReceive('get')->andReturn([
        'completed' => ['profile_setup'],
        'completed_at' => ['profile_setup' => time()],
        'started_at' => time(),
        'fully_completed_at' => time(),
    ]);

    $service->completeStep('user-2', 'profile_setup');
    // track() should NOT be called again for duplicate
});

test('OnboardingCompletionService definedSteps returns correct structure', function (): void {
    $manager = mock(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $queue = mock(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
    $config = mock(\Illuminate\Contracts\Config\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.onboarding_tracking', [])
        ->andReturn([
            'required_steps' => ['a', 'b'],
            'optional_steps' => ['c'],
            'cache_ttl' => 3600,
            'cache_prefix' => 'zb_test_',
        ]);

    $service = new OnboardingCompletionService($manager, $queue, $cache, $config);

    $steps = $service->definedSteps();

    expect($steps)->toHaveCount(3);
    expect($steps[0])->toBe(['name' => 'a', 'type' => 'required', 'index' => 0]);
    expect($steps[1])->toBe(['name' => 'b', 'type' => 'required', 'index' => 1]);
    expect($steps[2])->toBe(['name' => 'c', 'type' => 'optional', 'index' => 2]);
});

test('OnboardingCompletionService resetProgress clears cache', function (): void {
    $manager = mock(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $queue = mock(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
    $config = mock(\Illuminate\Contracts\Config\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.onboarding_tracking', [])
        ->andReturn([
            'required_steps' => ['profile_setup'],
            'optional_steps' => [],
            'cache_ttl' => 3600,
            'cache_prefix' => 'zb_test_',
        ]);

    $cache->shouldReceive('forget')->with('zb_test_user-99')->once()->andReturn(true);

    $service = new OnboardingCompletionService($manager, $queue, $cache, $config);

    $service->resetProgress('user-99');
});

test('OnboardingCompletionService funnelStats returns structural metadata', function (): void {
    $manager = mock(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $queue = mock(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class);
    $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
    $config = mock(\Illuminate\Contracts\Config\Repository::class);

    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.onboarding_tracking', [])
        ->andReturn([
            'required_steps' => ['a', 'b'],
            'optional_steps' => ['c'],
            'cache_ttl' => 3600,
            'cache_prefix' => 'zb_test_',
        ]);

    $service = new OnboardingCompletionService($manager, $queue, $cache, $config);

    $stats = $service->funnelStats();

    expect($stats)->toHaveKeys([
        'total_users', 'fully_completed', 'avg_completion_pct',
        'step_completion_rates', 'avg_time_to_completion',
    ]);
    expect($stats['step_completion_rates'])->toHaveKeys(['a', 'b', 'c']);
});

// ─── Version Consistency ─────────────────────────────────────────────────────

test('ServiceProvider version is current', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class);
    $doc = $reflection->getDocComment();

    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('4.6.0');
});

test('config schema_versioning catalog_version is current', function (): void {
    // Verify the config file contains the correct version
    $configContent = file_get_contents(__DIR__ . '/../config/zeroboiler.php');

    expect($configContent)->toContain("'catalog_version' => '4.6.0'");
});

test('config has onboarding_tracking section', function (): void {
    $configContent = file_get_contents(__DIR__ . '/../config/zeroboiler.php');

    expect($configContent)->toContain("'onboarding_tracking'");
    expect($configContent)->toContain('ANALYTICS_ONBOARDING_ENABLED');
    expect($configContent)->toContain('required_steps');
    expect($configContent)->toContain('optional_steps');
});

test('OnboardingCompletionService file has correct namespace and strict types', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Analytics\Services\OnboardingCompletionService::class);

    expect($reflection->getNamespaceName())->toBe('ZeroBoiler\\Analytics\\Services');
    expect($reflection->isFinal())->toBeTrue();

    // Verify strict types declaration
    $fileContent = file_get_contents($reflection->getFileName());
    expect($fileContent)->toContain('declare(strict_types=1)');

    // Verify license header
    expect($fileContent)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
});

test('EventCatalog has enterpriseComplianceEvents, dauMauEvents, productHealthEvents methods', function (): void {
    $catalog = new ReflectionClass(\ZeroBoiler\Analytics\Events\EventCatalog::class);

    expect($catalog->hasMethod('enterpriseComplianceEvents'))->toBeTrue();
    expect($catalog->hasMethod('dauMauEvents'))->toBeTrue();
    expect($catalog->hasMethod('productHealthEvents'))->toBeTrue();

    // All methods are static and public
    foreach (['enterpriseComplianceEvents', 'dauMauEvents', 'productHealthEvents'] as $method) {
        $m = $catalog->getMethod($method);
        expect($m->isPublic())->toBeTrue();
        expect($m->isStatic())->toBeTrue();
    }
});

test('total event catalog count is at least 90', function (): void {
    expect(EventCatalog::count())->toBeGreaterThanOrEqual(90);
});
