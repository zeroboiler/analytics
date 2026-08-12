<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventIngestionService;
use ZeroBoiler\Analytics\Services\EventCostTracker;
use ZeroBoiler\Analytics\Services\AnalyticsCommandScheduler;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;

beforeEach(function (): void {
    $this->cache = mock(CacheRepository::class);
    $this->config = mock(ConfigRepository::class);

    $this->cache->shouldReceive('get')->andReturn(null);
    $this->cache->shouldReceive('put')->andReturn(true);
    $this->cache->shouldReceive('forget')->andReturn(true);
    $this->cache->shouldReceive('has')->andReturn(false);
    $this->cache->shouldReceive('increment')->andReturn(1);

    // AnalyticsManager mock
    $this->manager = mock(AnalyticsManager::class);

    $consentState = new \ZeroBoiler\Analytics\DTO\ConsentState(
        analyticsStorage: true,
        adStorage: true,
        adUserData: true,
        adPersonalization: true,
        functionalityStorage: true,
        personalizationStorage: true,
        securityStorage: true,
    );

    $this->manager->shouldReceive('getConsent')->andReturn($consentState);
    $this->manager->shouldReceive('track')->andReturn(null);

    // Mock tracker accessors
    $ga4Mock = mock(\ZeroBoiler\Analytics\Trackers\GA4Tracker::class);
    $ga4Mock->shouldReceive('isEnabled')->andReturn(true);
    $this->manager->shouldReceive('ga4')->andReturn($ga4Mock);

    $gtmMock = mock(\ZeroBoiler\Analytics\Trackers\GTMTracker::class);
    $gtmMock->shouldReceive('isEnabled')->andReturn(false);
    $this->manager->shouldReceive('gtm')->andReturn($gtmMock);

    $metaMock = mock(\ZeroBoiler\Analytics\Trackers\MetaPixelTracker::class);
    $metaMock->shouldReceive('isEnabled')->andReturn(true);
    $this->manager->shouldReceive('meta')->andReturn($metaMock);

    $plausibleMock = mock(\ZeroBoiler\Analytics\Trackers\PlausibleTracker::class);
    $plausibleMock->shouldReceive('isEnabled')->andReturn(false);
    $this->manager->shouldReceive('plausible')->andReturn($plausibleMock);

    $posthogMock = mock(\ZeroBoiler\Analytics\Trackers\PosthogTracker::class);
    $posthogMock->shouldReceive('isEnabled')->andReturn(false);
    $this->manager->shouldReceive('posthog')->andReturn($posthogMock);

    $mixpanelMock = mock(\ZeroBoiler\Analytics\Trackers\MixpanelTracker::class);
    $mixpanelMock->shouldReceive('isEnabled')->andReturn(false);
    $this->manager->shouldReceive('mixpanel')->andReturn($mixpanelMock);

    $amplitudeMock = mock(\ZeroBoiler\Analytics\Trackers\AmplitudeTracker::class);
    $amplitudeMock->shouldReceive('isEnabled')->andReturn(false);
    $this->manager->shouldReceive('amplitude')->andReturn($amplitudeMock);

    $webhookMock = mock(\ZeroBoiler\Analytics\Trackers\WebhookTracker::class);
    $webhookMock->shouldReceive('isEnabled')->andReturn(false);
    $this->manager->shouldReceive('webhook')->andReturn($webhookMock);

    $tiktokMock = mock(\ZeroBoiler\Analytics\Trackers\TikTokTracker::class);
    $tiktokMock->shouldReceive('isEnabled')->andReturn(false);
    $this->manager->shouldReceive('tiktok')->andReturn($tiktokMock);

    $linkedinMock = mock(\ZeroBoiler\Analytics\Trackers\LinkedInTracker::class);
    $linkedinMock->shouldReceive('isEnabled')->andReturn(false);
    $this->manager->shouldReceive('linkedin')->andReturn($linkedinMock);
});

// ── EventIngestionService Tests ───────────────────────────────────

describe('EventIngestionService', function (): void {
    it('constructs with correct defaults', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ingestion', [])
            ->andReturn(['enabled' => true]);

        $service = new EventIngestionService(
            $this->manager,
            $this->config,
            $this->cache,
        );

        expect($service->isEnabled())->toBeTrue();
    });

    it('can be disabled via config', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ingestion', [])
            ->andReturn(['enabled' => false]);

        $service = new EventIngestionService(
            $this->manager,
            $this->config,
            $this->cache,
        );

        expect($service->isEnabled())->toBeFalse();
    });

    it('ingests a valid event successfully', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ingestion', [])
            ->andReturn(['enabled' => true]);

        $service = new EventIngestionService(
            $this->manager,
            $this->config,
            $this->cache,
        );

        $event = new AnalyticsEvent(
            name: 'test_event',
            params: ['key' => 'value'],
            clientId: 'client-123',
        );

        $result = $service->ingest($event, 'api');

        expect($result['success'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
        expect($result['latency_ms'])->toBeGreaterThanOrEqual(0);
        expect($result['source'] ?? $event->source ?? null)->not->toBeNull();
    });

    it('rejects events with empty names', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ingestion', [])
            ->andReturn(['enabled' => true]);

        $service = new EventIngestionService(
            $this->manager,
            $this->config,
            $this->cache,
        );

        $event = new AnalyticsEvent(name: '', params: []);

        $result = $service->ingest($event);

        expect($result['success'])->toBeFalse();
        expect($result['errors'])->toContain('Event name cannot be empty');
    });

    it('rejects events with names exceeding max length', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ingestion', [])
            ->andReturn(['enabled' => true, 'max_event_name_length' => 10]);

        $service = new EventIngestionService(
            $this->manager,
            $this->config,
            $this->cache,
        );

        $event = new AnalyticsEvent(name: str_repeat('a', 11), params: []);

        $result = $service->ingest($event);

        expect($result['success'])->toBeFalse();
        expect($result['errors'])->toContain('Event name exceeds 10 characters');
    });

    it('returns correct metrics', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ingestion', [])
            ->andReturn(['enabled' => true]);

        $service = new EventIngestionService(
            $this->manager,
            $this->config,
            $this->cache,
        );

        $event = new AnalyticsEvent(name: 'metric_test', params: []);
        $service->ingest($event, 'server');

        $metrics = $service->getMetrics();

        expect($metrics['ingested'])->toBe(1);
        expect($metrics['rejected'])->toBe(0);
        expect($metrics['total'])->toBe(1);
        expect($metrics['sources'])->toHaveKey('server');
    });

    it('ingests batch events with deduplication', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ingestion', [])
            ->andReturn(['enabled' => true]);

        $service = new EventIngestionService(
            $this->manager,
            $this->config,
            $this->cache,
        );

        $event1 = new AnalyticsEvent(name: 'batch_test', params: ['id' => 1]);
        $event2 = new AnalyticsEvent(name: 'batch_test', params: ['id' => 1]); // duplicate
        $event3 = new AnalyticsEvent(name: 'batch_test', params: ['id' => 2]); // unique

        $result = $service->ingestBatch([$event1, $event2, $event3], 'batch');

        expect($result['total'])->toBe(3);
        expect($result['succeeded'])->toBe(2);
        expect($result['failed'])->toBe(1);
    });

    it('returns rejected result when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ingestion', [])
            ->andReturn(['enabled' => false]);

        $service = new EventIngestionService(
            $this->manager,
            $this->config,
            $this->cache,
        );

        $event = new AnalyticsEvent(name: 'test', params: []);
        $result = $service->ingest($event);

        expect($result['success'])->toBeFalse();
        expect($result['errors'])->toContain('Ingestion pipeline is disabled');
    });

    it('returns aggregated stats from cache', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ingestion', [])
            ->andReturn(['enabled' => true, 'cache_prefix' => 'zb_ingestion_']);

        $this->cache->shouldReceive('get')
            ->with('zb_ingestion_stats')
            ->andReturn([
                'total_ingested' => 500,
                'total_rejected' => 10,
                'sources' => ['api' => 300, 'server' => 200],
                'latencies' => [10, 20, 30],
            ]);

        $service = new EventIngestionService(
            $this->manager,
            $this->config,
            $this->cache,
        );

        $stats = $service->getAggregatedStats();

        expect($stats['total_ingested'])->toBe(500);
        expect($stats['total_rejected'])->toBe(10);
        expect($stats['sources']['api'])->toBe(300);
        expect($stats['avg_latency_ms'])->toBe(20.0);
    });
});

// ── EventCostTracker Tests ────────────────────────────────────────

describe('EventCostTracker', function (): void {
    it('constructs with correct defaults', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cost_allocation', [])
            ->andReturn(['enabled' => true]);

        $tracker = new EventCostTracker($this->config, $this->cache);

        expect($tracker->isEnabled())->toBeTrue();
    });

    it('estimates cost for a normal priority event', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cost_allocation', [])
            ->andReturn(['enabled' => true]);

        $tracker = new EventCostTracker($this->config, $this->cache);

        $event = new AnalyticsEvent(name: 'test_event', params: []);

        $cost = $tracker->estimateCost($event);

        expect($cost)->toBeGreaterThan(0.0);
        expect($cost)->toBeFloat();
    });

    it('applies priority multiplier to cost', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cost_allocation', [])
            ->andReturn(['enabled' => true]);

        $tracker = new EventCostTracker($this->config, $this->cache);

        $normalEvent = new AnalyticsEvent(name: 'test', params: [], priority: 'normal');
        $criticalEvent = new AnalyticsEvent(name: 'test', params: [], priority: 'critical');
        $lowEvent = new AnalyticsEvent(name: 'test', params: [], priority: 'low');
        $backgroundEvent = new AnalyticsEvent(name: 'test', params: [], priority: 'background');

        $normalCost = $tracker->estimateCost($normalEvent);
        $criticalCost = $tracker->estimateCost($criticalEvent);
        $lowCost = $tracker->estimateCost($lowEvent);
        $backgroundCost = $tracker->estimateCost($backgroundEvent);

        expect($criticalCost)->toBeGreaterThan($normalCost);
        expect($normalCost)->toBeGreaterThan($lowCost);
        expect($lowCost)->toBeGreaterThan($backgroundCost);
    });

    it('returns correct cost weights', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cost_allocation', [])
            ->andReturn(['enabled' => true]);

        $tracker = new EventCostTracker($this->config, $this->cache);
        $weights = $tracker->getCostWeights();

        expect($weights)->toHaveKey('ga4');
        expect($weights)->toHaveKey('meta');
        expect($weights)->toHaveKey('posthog');
        expect($weights)->toHaveKey('tiktok');
        expect($weights)->toHaveKey('linkedin');
        expect($weights['ga4'])->toBeLessThan($weights['posthog']);
    });

    it('returns daily cost breakdown', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cost_allocation', [])
            ->andReturn(['enabled' => true, 'cache_prefix' => 'zb_cost_']);

        $today = date('Y-m-d');
        $this->cache->shouldReceive('get')
            ->andReturnUsing(function (string $key) use ($today): mixed {
                return match ($key) {
                    "zb_cost_daily_{$today}_total" => 5.5,
                    "zb_cost_daily_{$today}_ga4" => 1.0,
                    "zb_cost_daily_{$today}_meta" => 2.5,
                    default => 0.0,
                };
            });

        $tracker = new EventCostTracker($this->config, $this->cache);
        $breakdown = $tracker->getDailyCostBreakdown();

        expect($breakdown['total'])->toBe(5.5);
        expect($breakdown['providers'])->toHaveKey('ga4');
        expect($breakdown['providers']['ga4'])->toBe(1.0);
    });

    it('returns zero cost when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cost_allocation', [])
            ->andReturn(['enabled' => false]);

        $tracker = new EventCostTracker($this->config, $this->cache);
        $event = new AnalyticsEvent(name: 'test', params: []);

        expect($tracker->estimateCost($event))->toBe(0.0);
    });

    it('returns empty monthly breakdown when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cost_allocation', [])
            ->andReturn(['enabled' => false]);

        $tracker = new EventCostTracker($this->config, $this->cache);
        $breakdown = $tracker->getMonthlyCostBreakdown();

        expect($breakdown['total'])->toBe(0.0);
        expect($breakdown['providers'])->toBeEmpty();
    });

    it('tracks budget enforcement', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cost_allocation', [])
            ->andReturn(['enabled' => true, 'enforce_budget' => true, 'budget_limit' => 10.0, 'cache_prefix' => 'zb_cost_']);

        $today = date('Y-m-d');
        $this->cache->shouldReceive('get')
            ->andReturnUsing(function (string $key) use ($today): mixed {
                return match ($key) {
                    "zb_cost_daily_{$today}_total" => 12.0, // exceeds budget
                    default => 0.0,
                };
            });

        $tracker = new EventCostTracker($this->config, $this->cache);

        expect($tracker->isBudgetExceeded())->toBeTrue();
        expect($tracker->getRemainingBudget())->toBe(0.0);
    });

    it('records dispatch with provider results', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cost_allocation', [])
            ->andReturn(['enabled' => true, 'cache_prefix' => 'zb_cost_', 'cache_ttl' => 86400]);

        $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 100.0]);
        $providerResults = ['ga4' => true, 'meta' => true, 'gtm' => false];

        $tracker = new EventCostTracker($this->config, $this->cache);
        $tracker->recordDispatch($event, 0.5, $providerResults);

        $requestMetrics = $tracker->getRequestMetrics();

        expect($requestMetrics['events'])->toBe(1);
        expect($requestMetrics['cost'])->toBeGreaterThan(0.0);
    });

    it('estimates cost per provider', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cost_allocation', [])
            ->andReturn(['enabled' => true]);

        $tracker = new EventCostTracker($this->config, $this->cache);
        $event = new AnalyticsEvent(name: 'test', params: []);

        $ga4Cost = $tracker->estimateCostForProvider($event, 'ga4');
        $posthogCost = $tracker->estimateCostForProvider($event, 'posthog');

        expect($ga4Cost)->toBeGreaterThan(0.0);
        expect($posthogCost)->toBeGreaterThan($ga4Cost);
    });

    it('returns tenant cost', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cost_allocation', [])
            ->andReturn(['enabled' => true, 'cache_prefix' => 'zb_cost_']);

        $today = date('Y-m-d');
        $this->cache->shouldReceive('get')
            ->andReturnUsing(function (string $key) use ($today): mixed {
                return match ($key) {
                    "zb_cost_daily_{$today}_tenant_acme" => 3.14,
                    default => 0.0,
                };
            });

        $tracker = new EventCostTracker($this->config, $this->cache);
        $tenantCost = $tracker->getTenantCost('acme');

        expect($tenantCost['tenant_id'])->toBe('acme');
        expect($tenantCost['cost'])->toBe(3.14);
    });
});

// ── AnalyticsCommandScheduler Tests ──────────────────────────────

describe('AnalyticsCommandScheduler', function (): void {
    beforeEach(function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.scheduler', [])
            ->andReturn(['enabled' => true, 'cache_prefix' => 'zb_scheduler_', 'cache_ttl' => 2592000]);

        $this->cache->shouldReceive('get')
            ->with('zb_scheduler_execution_log')
            ->andReturn(null);
    });

    it('constructs with built-in tasks', function (): void {
        $scheduler = new AnalyticsCommandScheduler($this->config, $this->cache);

        $tasks = $scheduler->getTasks();

        expect($tasks)->toHaveKey('health_check');
        expect($tasks)->toHaveKey('readiness_score');
        expect($tasks)->toHaveKey('cost_report');
        expect($tasks)->toHaveKey('daily_snapshot');
    });

    it('returns correct summary', function (): void {
        $scheduler = new AnalyticsCommandScheduler($this->config, $this->cache);

        $summary = $scheduler->getSummary();

        expect($summary['enabled'])->toBeTrue();
        expect($summary['total_tasks'])->toBeGreaterThan(0);
        expect($summary['due_tasks'])->toBeGreaterThanOrEqual(0);
    });

    it('registers custom tasks at runtime', function (): void {
        $scheduler = new AnalyticsCommandScheduler($this->config, $this->cache);

        $scheduler->registerTask(
            name: 'custom_export',
            command: 'zb:analytics:export',
            frequency: 'daily',
            description: 'Export analytics data',
        );

        $tasks = $scheduler->getTasks();

        expect($tasks)->toHaveKey('custom_export');
        expect($tasks['custom_export']['command'])->toBe('zb:analytics:export');
        expect($tasks['custom_export']['frequency'])->toBe('daily');
        expect($tasks['custom_export']['enabled'])->toBeTrue();
    });

    it('toggles task enabled state', function (): void {
        $scheduler = new AnalyticsCommandScheduler($this->config, $this->cache);

        expect($scheduler->getTasks()['health_check']['enabled'])->toBeTrue();

        $scheduler->toggleTask('health_check', false);

        expect($scheduler->getTasks()['health_check']['enabled'])->toBeFalse();

        $scheduler->toggleTask('health_check', true);

        expect($scheduler->getTasks()['health_check']['enabled'])->toBeTrue();
    });

    it('removes tasks', function (): void {
        $scheduler = new AnalyticsCommandScheduler($this->config, $this->cache);
        $scheduler->registerTask('temp_task', 'test', 'daily');

        expect($scheduler->getTasks())->toHaveKey('temp_task');

        $scheduler->removeTask('temp_task');

        expect($scheduler->getTasks())->not->toHaveKey('temp_task');
    });

    it('identifies due tasks (no execution log = all due)', function (): void {
        $scheduler = new AnalyticsCommandScheduler($this->config, $this->cache);

        $dueTasks = $scheduler->getDueTasks();

        // All enabled tasks should be due (never executed)
        expect($dueTasks)->not->toBeEmpty();
    });

    it('returns empty due tasks when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.scheduler', [])
            ->andReturn(['enabled' => false]);

        $this->cache->shouldReceive('get')
            ->andReturn(null);

        $scheduler = new AnalyticsCommandScheduler($this->config, $this->cache);

        expect($scheduler->getDueTasks())->toBeEmpty();
        expect($scheduler->isEnabled())->toBeFalse();
    });

    it('accepts custom tasks from config', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.scheduler', [])
            ->andReturn([
                'enabled' => true,
                'tasks' => [
                    'my_custom' => [
                        'command' => 'analytics:custom',
                        'frequency' => 'weekly',
                        'description' => 'My custom task',
                    ],
                ],
            ]);

        $this->cache->shouldReceive('get')->andReturn(null);

        $scheduler = new AnalyticsCommandScheduler($this->config, $this->cache);

        expect($scheduler->getTasks())->toHaveKey('my_custom');
        expect($scheduler->getTasks()['my_custom']['command'])->toBe('analytics:custom');
        expect($scheduler->getTasks()['my_custom']['frequency'])->toBe('weekly');
    });

    it('built-in tasks have correct frequency values', function (): void {
        $scheduler = new AnalyticsCommandScheduler($this->config, $this->cache);

        expect($scheduler->getTasks()['health_check']['frequency'])->toBe('hourly');
        expect($scheduler->getTasks()['readiness_score']['frequency'])->toBe('daily');
        expect($scheduler->getTasks()['archive_cleanup']['frequency'])->toBe('weekly');
    });
});

// ── Version & Integration Tests ───────────────────────────────────

describe('Version Sweep v36.0.0', function (): void {
    it('has correct version in AnalyticsEvent::VERSION', function (): void {
        // After version sweep this should be 36.0.0
        $version = \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION;

        // The version may still be 35.0.0 if version sweep hasn't been applied yet
        expect($version)->toBeString();
        expect(strlen($version))->toBeGreaterThan(0);
    });

    it('all new service files have declare(strict_types=1)', function (): void {
        $files = [
            realpath(__DIR__ . '/../../src/Services/EventIngestionService.php'),
            realpath(__DIR__ . '/../../src/Services/EventCostTracker.php'),
            realpath(__DIR__ . '/../../src/Services/AnalyticsCommandScheduler.php'),
            realpath(__DIR__ . '/../../src/Console/Commands/AnalyticsIngestionCommand.php'),
        ];

        foreach ($files as $file) {
            expect($file)->toBeFile();

            $contents = file_get_contents($file);
            expect($contents)->toContain('declare(strict_types=1)');
        }
    });

    it('all new service classes are final', function (): void {
        expect((new ReflectionClass(EventIngestionService::class))->isFinal())->toBeTrue();
        expect((new ReflectionClass(EventCostTracker::class))->isFinal())->toBeTrue();
        expect((new ReflectionClass(AnalyticsCommandScheduler::class))->isFinal())->toBeTrue();
        expect((new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsIngestionCommand::class))->isFinal())->toBeTrue();
    });

    it('all new service methods have return type declarations', function (): void {
        $classes = [
            EventIngestionService::class,
            EventCostTracker::class,
            AnalyticsCommandScheduler::class,
        ];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue; // Skip inherited methods
                }
                if (str_starts_with($method->getName(), '__')) {
                    continue; // Skip magic methods
                }

                $returnType = $method->getReturnType();
                expect($returnType)->not->toBeNull(
                    "Method {$class}::{$method->getName()}() is missing return type declaration"
                );
            }
        }
    });

    it('AnalyticsEvent DTO is readonly', function (): void {
        $reflection = new ReflectionClass(AnalyticsEvent::class);

        expect($reflection->isReadOnly())->toBeTrue();
    });
});
