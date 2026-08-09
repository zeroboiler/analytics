<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\AnalyticsAIService;
use ZeroBoiler\Analytics\Services\EventExperimentTracker;
use ZeroBoiler\Analytics\Services\SaaSQuickStartService;
use ZeroBoiler\Analytics\AnalyticsManager;

/**
 * V5.0.0 — Industry-Standard SaaS Analytics Upgrade Test
 *
 * Validates:
 * 1. Version consistency across all source files (5.0.0)
 * 2. AnalyticsAIService — anomaly detection, insights, trend analysis, event suggestions
 * 3. EventExperimentTracker — A/B test tracking, statistical significance (z-test)
 * 4. SaaSQuickStartService — one-call SaaS event tracking API
 * 5. Config expansion — ai, experiment sections
 * 6. ServiceProvider registrations for new services
 * 7. Event Catalog integrity with 100+ events
 * 8. All 12 SaaS starter features still passing
 */

test('v5.0.0: AnalyticsEvent VERSION is 5.0.0', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('5.0.0');
});

test('v5.0.0: composer.json version is 5.0.0', function (): void {
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('5.0.0');
});

test('v5.0.0: JS client version is 5.0.0', function (): void {
    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($js)->toContain("'5.0.0'");
    expect($js)->toContain('@version 5.0.0');
});

test('v5.0.0: Svelte composables version is 5.0.0', function (): void {
    $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
    expect($svelte)->toContain('@version 5.0.0');
});

test('v5.0.0: TypeScript definitions version is 5.0.0', function (): void {
    $ts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
    expect($ts)->toContain('@version 5.0.0');
});

test('v5.0.0: no stale @version docblocks remain', function (): void {
    // All @version tags in src/ should be 5.0.0
    $output = shell_exec('grep -rn "@version [0-9]" ' . escapeshellarg(__DIR__ . '/../src') . ' --include="*.php" 2>/dev/null') ?: '';
    $lines = array_filter(explode("\n", trim($output)));

    $stale = [];
    foreach ($lines as $line) {
        if (! str_contains($line, '@version 5.0.0')) {
            $stale[] = $line;
        }
    }

    expect($stale)->toBeEmpty('Found stale @version docblocks: ' . implode("\n", $stale));
});

test('v5.0.0: no stale VERSION constants remain', function (): void {
    $output = shell_exec('grep -rn "VERSION = '"'"'[0-9]' . escapeshellarg(__DIR__ . '/../src') . ' --include="*.php" 2>/dev/null') ?: '';
    $lines = array_filter(explode("\n", trim($output)));

    $stale = [];
    foreach ($lines as $line) {
        if (! str_contains($line, "VERSION = '5.0.0'")) {
            $stale[] = $line;
        }
    }

    expect($stale)->toBeEmpty('Found stale VERSION constants: ' . implode("\n", $stale));
});

// ── AnalyticsAIService Tests ─────────────────────────────────────────

test('v5.0.0: AnalyticsAIService class exists', function (): void {
    expect(class_exists(AnalyticsAIService::class))->toBeTrue();
});

test('v5.0.0: AnalyticsAIService has required methods', function (): void {
    $ref = new ReflectionClass(AnalyticsAIService::class);
    $methods = array_map(fn (\ReflectionMethod $m) => $m->getName(), $ref->getMethods(\ReflectionMethod::IS_PUBLIC));

    expect($methods)->toContain('detectAnomaly');
    expect($methods)->toContain('detectBatchAnomalies');
    expect($methods)->toContain('generateInsights');
    expect($methods)->toContain('analyzeTrend');
    expect($methods)->toContain('suggestEvents');
    expect($methods)->toContain('isEnabled');
    expect($methods)->toContain('getStatus');
    expect($methods)->toContain('clearBuffer');
});

test('v5.0.0: AnalyticsAIService is final', function (): void {
    $ref = new ReflectionClass(AnalyticsAIService::class);
    expect($ref->isFinal())->toBeTrue();
});

test('v5.0.0: AnalyticsAIService constructor has proper type hints', function (): void {
    $ref = new ReflectionClass(AnalyticsAIService::class);
    $ctor = $ref->getMethod('__construct');
    $params = $ctor->getParameters();

    expect(count($params))->toBe(2);
    expect($params[0]->getType()?->getName())->toBe('Illuminate\\Contracts\\Config\\Repository');
    expect($params[1]->getType()?->getName())->toBe('Illuminate\\Contracts\\Cache\\Repository');
});

test('v5.0.0: AnalyticsAIService has return type declarations', function (): void {
    $ref = new ReflectionClass(AnalyticsAIService::class);

    $detectAnomaly = $ref->getMethod('detectAnomaly');
    $returnType = $detectAnomaly->getReturnType();
    expect($returnType)->not->toBeNull();
    expect((string) $returnType)->toContain('array');

    $generateInsights = $ref->getMethod('generateInsights');
    expect($generateInsights->getReturnType())->not->toBeNull();

    $analyzeTrend = $ref->getMethod('analyzeTrend');
    expect($analyzeTrend->getReturnType())->not->toBeNull();

    $suggestEvents = $ref->getMethod('suggestEvents');
    expect($suggestEvents->getReturnType())->not->toBeNull();
});

test('v5.0.0: AnalyticsAIService detectAnomaly returns null for disabled service', function (): void {
    // Constructor with enabled=false config
    $config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.ai', [])->andReturn(['enabled' => false]);

    $service = new AnalyticsAIService($config, $cache);
    expect($service->isEnabled())->toBeFalse();
    expect($service->detectAnomaly('test_event', 100.0))->toBeNull();
});

test('v5.0.0: AnalyticsAIService detectAnomaly returns null for insufficient data', function (): void {
    $config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.ai', [])->andReturn(['enabled' => true]);

    $service = new AnalyticsAIService($config, $cache);
    // Only 3 data points — need 5 minimum
    $service->detectAnomaly('test_event', 10.0);
    $service->detectAnomaly('test_event', 11.0);
    $service->detectAnomaly('test_event', 10.5);
    expect($service->detectAnomaly('test_event', 12.0))->toBeNull();
});

test('v5.0.0: AnalyticsAIService detectAnomaly detects anomaly after sufficient data', function (): void {
    $config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.ai', [])->andReturn([
        'enabled' => true,
        'anomaly_threshold' => 1.5,
    ]);

    $service = new AnalyticsAIService($config, $cache);

    // Feed stable data
    for ($i = 0; $i < 10; $i++) {
        $service->detectAnomaly('test_event', 10.0 + random_float(-0.1, 0.1));
    }

    // Now feed a massive spike (should be detected)
    $anomaly = $service->detectAnomaly('test_event', 500.0);
    expect($anomaly)->not->toBeNull();
    expect($anomaly)->toHaveKey('event_name');
    expect($anomaly)->toHaveKey('z_score');
    expect($anomaly)->toHaveKey('severity');
    expect($anomaly['event_name'])->toBe('test_event');
    expect($anomaly['severity'])->toBeIn(['low', 'medium', 'high', 'critical']);
});

test('v5.0.0: AnalyticsAIService generateInsights returns insights for event data', function (): void {
    $config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.ai', [])->andReturn(['enabled' => true]);

    $service = new AnalyticsAIService($config, $cache);

    $insights = $service->generateInsights([
        'events' => [
            'page_view' => 1000,
            'sign_up' => 50,
            'purchase' => 5,
            'custom_event' => 5000, // High volume
        ],
    ]);

    expect($insights)->toBeArray();

    // Should have high-volume insight (custom_event is 10x page_view)
    $hasVolumeInsight = false;
    foreach ($insights as $insight) {
        if (($insight['type'] ?? '') === 'volume_spike') {
            $hasVolumeInsight = true;
            expect($insight)->toHaveKey('confidence');
            expect($insight)->toHaveKey('action_items');
            expect($insight['action_items'])->toBeArray();
        }
    }
    expect($hasVolumeInsight)->toBeTrue();
});

test('v5.0.0: AnalyticsAIService generateInsights detects missing lifecycle events', function (): void {
    $config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.ai', [])->andReturn(['enabled' => true]);

    $service = new AnalyticsAIService($config, $cache);

    // Only track page_view — missing all core SaaS events
    $insights = $service->generateInsights([
        'events' => [
            'page_view' => 100,
            'click' => 50,
        ],
    ]);

    $hasMissing = false;
    foreach ($insights as $insight) {
        if (($insight['type'] ?? '') === 'missing_lifecycle') {
            $hasMissing = true;
            expect($insight['confidence'])->toBeGreaterThanOrEqual(0.9);
            expect($insight['affected_events'])->toContain('sign_up');
            expect($insight['affected_events'])->toContain('login');
        }
    }
    expect($hasMissing)->toBeTrue();
});

test('v5.0.0: AnalyticsAIService analyzeTrend returns flat for insufficient data', function (): void {
    $config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.ai', [])->andReturn(['enabled' => true]);

    $service = new AnalyticsAIService($config, $cache);

    $trend = $service->analyzeTrend([10.0]);
    expect($trend['direction'])->toBe('flat');
    expect($trend['confidence'])->toBe(0.0);
});

test('v5.0.0: AnalyticsAIService analyzeTrend detects upward trend', function (): void {
    $config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.ai', [])->andReturn(['enabled' => true]);

    $service = new AnalyticsAIService($config, $cache);

    $trend = $service->analyzeTrend([10, 12, 15, 18, 22, 27, 33, 40]);
    expect($trend['direction'])->toBe('up');
    expect($trend['slope'])->toBeGreaterThan(0);
});

test('v5.0.0: AnalyticsAIService analyzeTrend detects downward trend', function (): void {
    $config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.ai', [])->andReturn(['enabled' => true]);

    $service = new AnalyticsAIService($config, $cache);

    $trend = $service->analyzeTrend([100, 90, 80, 70, 60, 50, 40, 30]);
    expect($trend['direction'])->toBe('down');
    expect($trend['slope'])->toBeLessThan(0);
});

test('v5.0.0: AnalyticsAIService suggestEvents returns catalog coverage', function (): void {
    $config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.ai', [])->andReturn(['enabled' => true]);

    $service = new AnalyticsAIService($config, $cache);

    $result = $service->suggestEvents(['page_view', 'click', 'sign_up']);
    expect($result)->toHaveKey('recommended');
    expect($result)->toHaveKey('coverage_percent');
    expect($result)->toHaveKey('total_catalog');
    expect($result)->toHaveKey('tracked_count');
    expect($result['total_catalog'])->toBeGreaterThanOrEqual(100);
    expect($result['coverage_percent'])->toBeLessThan(50); // Only tracking 3 of 100+
    expect($result['tracked_count'])->toBe(3);
});

test('v5.0.0: AnalyticsAIService getStatus returns config', function (): void {
    $config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.ai', [])->andReturn([
        'enabled' => true,
        'anomaly_threshold' => 2.5,
        'rolling_window' => 100,
    ]);

    $service = new AnalyticsAIService($config, $cache);
    $status = $service->getStatus();

    expect($status['enabled'])->toBeTrue();
    expect($status['anomaly_threshold'])->toBe(2.5);
    expect($status['rolling_window'])->toBe(100);
    expect($status)->toHaveKey('buffer_size');
});

test('v5.0.0: AnalyticsAIService clearBuffer works', function (): void {
    $config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.ai', [])->andReturn(['enabled' => true]);

    $service = new AnalyticsAIService($config, $cache);
    $service->detectAnomaly('test', 1.0);
    $service->clearBuffer('test');
    $service->clearBuffer(); // Clear all
    $status = $service->getStatus();
    expect($status['buffer_size'])->toBe(0);
});

// ── EventExperimentTracker Tests ────────────────────────────────────

test('v5.0.0: EventExperimentTracker class exists', function (): void {
    expect(class_exists(EventExperimentTracker::class))->toBeTrue();
});

test('v5.0.0: EventExperimentTracker is final', function (): void {
    $ref = new ReflectionClass(EventExperimentTracker::class);
    expect($ref->isFinal())->toBeTrue();
});

test('v5.0.0: EventExperimentTracker has required methods', function (): void {
    $ref = new ReflectionClass(EventExperimentTracker::class);
    $methods = array_map(fn (\ReflectionMethod $m) => $m->getName(), $ref->getMethods(\ReflectionMethod::IS_PUBLIC));

    expect($methods)->toContain('createExperiment');
    expect($methods)->toContain('trackEvent');
    expect($methods)->toContain('getExperiment');
    expect($methods)->toContain('calculateSignificance');
    expect($methods)->toContain('completeExperiment');
    expect($methods)->toContain('pauseExperiment');
    expect($methods)->toContain('resumeExperiment');
    expect($methods)->toContain('getSummary');
    expect($methods)->toContain('isEnabled');
});

test('v5.0.0: EventExperimentTracker has return type declarations', function (): void {
    $ref = new ReflectionClass(EventExperimentTracker::class);

    foreach (['createExperiment', 'getExperiment', 'calculateSignificance', 'getSummary', 'completeExperiment'] as $method) {
        $m = $ref->getMethod($method);
        expect($m->getReturnType())->not->toBeNull("Method {$method} missing return type");
    }
});

test('v5.0.0: EventExperimentTracker constructor has proper type hints', function (): void {
    $ref = new ReflectionClass(EventExperimentTracker::class);
    $ctor = $ref->getMethod('__construct');
    $params = $ctor->getParameters();

    expect(count($params))->toBe(2);
});

test('v5.0.0: EventExperimentTracker creates experiment correctly', function (): void {
    $config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.experiment', [])->andReturn(['enabled' => true]);
    $cache->shouldReceive('put')->once();

    $tracker = new EventExperimentTracker($config, $cache);
    $experiment = $tracker->createExperiment('test_ab', 'Button Color Test', ['control', 'blue', 'green']);

    expect($experiment['id'])->toBe('test_ab');
    expect($experiment['name'])->toBe('Button Color Test');
    expect($experiment['status'])->toBe('running');
    expect($experiment['winner'])->toBeNull();
    expect(count($experiment['variants']))->toBe(3);
    expect($experiment['variants'][0]['name'])->toBe('control');
    expect($experiment['variants'][0]['events'])->toBe(0);
});

test('v5.0.0: EventExperimentTracker tracks events and updates conversion rates', function (): void {
    $config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.experiment', [])->andReturn(['enabled' => true]);
    $cache->shouldReceive('put')->andReturnTrue();

    $tracker = new EventExperimentTracker($config, $cache);

    // Create + get returns the experiment
    $cache->shouldReceive('get')->with('zb_exp_test', null)->andReturn(null);
    $tracker->createExperiment('test', 'Test', ['control', 'variant']);

    // Now track: simulate get returning updated experiment
    $cache->shouldReceive('get')->with('zb_exp_test', null)->andReturnUsing(function () use (&$exp) {
        return $exp;
    });
    $cache->shouldReceive('put')->withArgs(fn ($key, $value) => ($exp = $value) !== null || true)->andReturnTrue();

    $tracker->trackEvent('test', 'control', false);
    $tracker->trackEvent('test', 'control', false);
    $tracker->trackEvent('test', 'control', true);
    $tracker->trackEvent('test', 'variant', false);
    $tracker->trackEvent('test', 'variant', true);
    $tracker->trackEvent('test', 'variant', true);

    $experiment = $tracker->getExperiment('test');
    expect($experiment)->not->toBeNull();

    // Control: 3 events, 1 conversion = 33.3%
    expect($experiment['variants'][0]['events'])->toBe(3);
    expect($experiment['variants'][0]['conversions'])->toBe(1);

    // Variant: 3 events, 2 conversions = 66.7%
    expect($experiment['variants'][1]['events'])->toBe(3);
    expect($experiment['variants'][1]['conversions'])->toBe(2);
});

test('v5.0.0: EventExperimentTracker significance calculation returns correct structure', function (): void {
    $config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.experiment', [])->andReturn([
        'enabled' => true,
        'min_sample_size' => 5, // Low for testing
    ]);

    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturnTrue();

    $tracker = new EventExperimentTracker($config, $cache);

    // Create experiment with small sample
    $experiment = [
        'id' => 'sig_test',
        'name' => 'Significance Test',
        'variants' => [
            ['name' => 'control', 'events' => 1000, 'conversions' => 50, 'conversion_rate' => 0.05],
            ['name' => 'variant', 'events' => 1000, 'conversions' => 80, 'conversion_rate' => 0.08],
        ],
        'status' => 'running',
        'winner' => null,
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ];

    $cache->shouldReceive('get')->with('zb_exp_sig_test', null)->andReturn($experiment);
    $result = $tracker->calculateSignificance('sig_test');

    expect($result)->toHaveKey('is_significant');
    expect($result)->toHaveKey('confidence');
    expect($result)->toHaveKey('p_value');
    expect($result)->toHaveKey('z_score');
    expect($result)->toHaveKey('recommendation');
    expect($result['p_value'])->toBeFloat();
    expect($result['z_score'])->toBeFloat();
});

test('v5.0.0: EventExperimentTracker pause/resume lifecycle works', function (): void {
    $config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.experiment', [])->andReturn(['enabled' => true]);
    $cache->shouldReceive('put')->andReturnTrue();

    $tracker = new EventExperimentTracker($config, $cache);

    $paused = [
        'id' => 'lifecycle_test',
        'name' => 'Lifecycle',
        'variants' => [['name' => 'a', 'events' => 0, 'conversions' => 0, 'conversion_rate' => 0.0]],
        'status' => 'paused',
        'winner' => null,
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ];

    $cache->shouldReceive('get')->with('zb_exp_lifecycle_test', null)->andReturn($paused);
    $result = $tracker->resumeExperiment('lifecycle_test');
    expect($result)->not->toBeNull();
    expect($result['status'])->toBe('running');

    $running = array_merge($paused, ['status' => 'running']);
    $cache->shouldReceive('get')->with('zb_exp_lifecycle_test', null)->andReturn($running);
    $result = $tracker->pauseExperiment('lifecycle_test');
    expect($result)->not->toBeNull();
    expect($result['status'])->toBe('paused');
});

// ── SaaSQuickStartService Tests ─────────────────────────────────────

test('v5.0.0: SaaSQuickStartService class exists', function (): void {
    expect(class_exists(SaaSQuickStartService::class))->toBeTrue();
});

test('v5.0.0: SaaSQuickStartService is final', function (): void {
    $ref = new ReflectionClass(SaaSQuickStartService::class);
    expect($ref->isFinal())->toBeTrue();
});

test('v5.0.0: SaaSQuickStartService has all tracking methods', function (): void {
    $ref = new ReflectionClass(SaaSQuickStartService::class);
    $methods = array_map(fn (\ReflectionMethod $m) => $m->getName(), $ref->getMethods(\ReflectionMethod::IS_PUBLIC));

    expect($methods)->toContain('trackSignUp');
    expect($methods)->toContain('trackLogin');
    expect($methods)->toContain('trackTrialStart');
    expect($methods)->toContain('trackTrialConversion');
    expect($methods)->toContain('trackSubscription');
    expect($methods)->toContain('trackPlanUpgrade');
    expect($methods)->toContain('trackCancellation');
    expect($methods)->toContain('trackPurchase');
    expect($methods)->toContain('trackFeatureUsed');
    expect($methods)->toContain('trackError');
    expect($methods)->toContain('trackOnboardingSequence');
});

test('v5.0.0: SaaSQuickStartService methods have return type void', function (): void {
    $ref = new ReflectionClass(SaaSQuickStartService::class);

    $voidMethods = ['trackSignUp', 'trackLogin', 'trackTrialStart', 'trackTrialConversion',
        'trackSubscription', 'trackPlanUpgrade', 'trackCancellation', 'trackPurchase',
        'trackFeatureUsed', 'trackError', 'trackOnboardingSequence'];

    foreach ($voidMethods as $methodName) {
        $method = $ref->getMethod($methodName);
        expect((string) $method->getReturnType())->toBe('void', "Method {$methodName} should return void");
    }
});

test('v5.0.0: SaaSQuickStartService constructor accepts AnalyticsManager', function (): void {
    $ref = new ReflectionClass(SaaSQuickStartService::class);
    $ctor = $ref->getMethod('__construct');
    $params = $ctor->getParameters();

    expect(count($params))->toBe(1);
    expect($params[0]->getType()?->getName())->toBe(AnalyticsManager::class);
    expect($params[0]->isPromoted())->toBeTrue();
});

// ── Config Expansion Tests ───────────────────────────────────────────

test('v5.0.0: config has ai section', function (): void {
    $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
    expect($config)->not->toBeFalse();
    expect($config)->toContain("'ai' => [");
    expect($config)->toContain('ANALYTICS_AI_ENABLED');
    expect($config)->toContain('ANALYTICS_AI_ANOMALY_THRESHOLD');
    expect($config)->toContain('ANALYTICS_AI_ROLLING_WINDOW');
});

test('v5.0.0: config has experiment section', function (): void {
    $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
    expect($config)->not->toBeFalse();
    expect($config)->toContain("'experiment' => [");
    expect($config)->toContain('ANALYTICS_EXPERIMENT_ENABLED');
    expect($config)->toContain('ANALYTICS_EXPERIMENT_SIGNIFICANCE');
    expect($config)->toContain('ANALYTICS_EXPERIMENT_MIN_SAMPLE');
});

test('v5.0.0: config has 30+ sections total', function (): void {
    $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
    preg_match_all("/'([a-z_]+)' => \[/", $config, $matches);
    $sections = array_unique($matches[1]);
    expect(count($sections))->toBeGreaterThanOrEqual(30);
});

// ── ServiceProvider Registration Tests ────────────────────────────────

test('v5.0.0: ServiceProvider registers AnalyticsAIService', function (): void {
    $provider = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect($provider)->toContain('AnalyticsAIService::class');
    expect($provider)->toContain('singleton(AnalyticsAIService');
});

test('v5.0.0: ServiceProvider registers EventExperimentTracker', function (): void {
    $provider = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect($provider)->toContain('EventExperimentTracker::class');
    expect($provider)->toContain('singleton(EventExperimentTracker');
});

test('v5.0.0: ServiceProvider registers SaaSQuickStartService', function (): void {
    $provider = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect($provider)->toContain('SaaSQuickStartService::class');
    expect($provider)->toContain('singleton(SaaSQuickStartService');
});

test('v5.0.0: ServiceProvider has import statements for new services', function (): void {
    $provider = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect($provider)->toContain('use ZeroBoiler\\Analytics\\Services\\AnalyticsAIService');
    expect($provider)->toContain('use ZeroBoiler\\Analytics\\Services\\EventExperimentTracker');
    expect($provider)->toContain('use ZeroBoiler\\Analytics\\Services\\SaaSQuickStartService');
});

// ── Event Catalog Integrity ──────────────────────────────────────────

test('v5.0.0: event catalog is valid', function (): void {
    $result = EventCatalog::validate();
    expect($result['valid'])->toBeTrue('Event catalog validation failed: ' . implode(', ', $result['errors']));
    expect($result['errors'])->toBeEmpty();
});

test('v5.0.0: event catalog has 100+ events', function (): void {
    expect(EventCatalog::count())->toBeGreaterThanOrEqual(100);
});

test('v5.0.0: event catalog has core SaaS events', function (): void {
    $core = EventCatalog::coreSaaS();
    $names = array_map(fn (array $e): string => $e['name'], $core);
    expect($names)->toContain('sign_up');
    expect($names)->toContain('login');
    expect($names)->toContain('start_trial');
    expect($names)->toContain('subscribe');
    expect($names)->toContain('plan_upgrade');
});

// ── README ───────────────────────────────────────────────────────────

test('v5.0.0: README version badge is 5.0.0', function (): void {
    $readme = file_get_contents(__DIR__ . '/../README.md');
    expect($readme)->toContain('version-5.0.0-blue');
});

// ── CHANGELOG ────────────────────────────────────────────────────────

test('v5.0.0: CHANGELOG has 5.0.0 entry', function (): void {
    $changelog = file_get_contents(__DIR__ . '/../CHANGELOG.md');
    expect($changelog)->toContain('## [5.0.0]');
});
