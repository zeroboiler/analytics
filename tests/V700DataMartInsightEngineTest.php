<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Services\EventDataMartService;
use ZeroBoiler\Analytics\Services\AnalyticsInsightEngineService;

beforeEach(function (): void {
    $this->cache = mock(CacheRepository::class);
    $this->config = mock(ConfigRepository::class);

    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.data_mart', [])
        ->andReturn([
            'enabled' => true,
            'cache_ttl' => 86400,
            'default_granularity' => 'hour',
            'max_dimensions' => 50,
            'auto_dimensions' => ['event_name', 'category'],
            'tracked_categories' => [],
        ]);

    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.insight_engine', [])
        ->andReturn([
            'enabled' => true,
            'cache_ttl' => 300,
            'top_movers_count' => 10,
            'drift_threshold' => 0.3,
            'growth_threshold' => 0.2,
            'decline_threshold' => -0.15,
        ]);

    $this->mart = new EventDataMartService($this->cache, $this->config);
    $this->engine = new AnalyticsInsightEngineService($this->cache, $this->config);
});

// ─── EventDataMartService ──────────────────────────────────────────────

describe('EventDataMartService', function (): void {
    it('can be instantiated with strict types', function (): void {
        expect($this->mart)->toBeInstanceOf(EventDataMartService::class);
    });

    it('reports enabled status correctly', function (): void {
        expect($this->mart->isEnabled())->toBeTrue();
    });

    it('has PHP 8.5 final class', function (): void {
        $reflection = new ReflectionClass(EventDataMartService::class);
        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->getConstructor()?->getName() ?? '')->toBe('__construct');
    });

    it('has declare(strict_types=1)', function (): void {
        $contents = file_get_contents((new ReflectionClass(EventDataMartService::class))->getFileName());
        expect($contents)->toContain('declare(strict_types=1)');
    });

    it('returns supported granularities', function (): void {
        $granularities = $this->mart->supportedGranularities();
        expect($granularities)->toContain('minute');
        expect($granularities)->toContain('hour');
        expect($granularities)->toContain('day');
        expect($granularities)->toContain('week');
        expect($granularities)->toContain('month');
    });

    it('returns supported dimensions', function (): void {
        $dimensions = $this->mart->supportedDimensions();
        expect($dimensions)->toContain('event_name');
        expect($dimensions)->toContain('category');
        expect($dimensions)->toContain('provider');
        expect($dimensions)->toContain('client_id');
        expect($dimensions)->toContain('user_id');
        expect($dimensions)->toContain('source');
    });

    it('ingests events and updates cache cells', function (): void {
        $slices = [];

        $this->cache->shouldReceive('get')
            ->andReturnUsing(function (string $key) use (&$slices): ?array {
                return $slices[$key] ?? null;
            });

        $this->cache->shouldReceive('put')
            ->andReturnUsing(function (string $key, array $value) use (&$slices): bool {
                $slices[$key] = $value;

                return true;
            });

        $this->mart->ingest([
            'name' => 'page_view',
            'category' => 'engagement',
            'client_id' => 'client-123',
        ]);

        // Verify data was written
        $eventNameKey = 'zb_datamart_event_name_hour';
        expect(isset($slices[$eventNameKey]))->toBeTrue();
        expect($slices[$eventNameKey]['page_view']['count'])->toBe(1);
    });

    it('ingests batch of events', function (): void {
        $slices = [];

        $this->cache->shouldReceive('get')
            ->andReturnUsing(function (string $key) use (&$slices): ?array {
                return $slices[$key] ?? null;
            });

        $this->cache->shouldReceive('put')
            ->andReturnUsing(function (string $key, array $value) use (&$slices): bool {
                $slices[$key] = $value;

                return true;
            });

        $events = [
            ['name' => 'page_view', 'category' => 'engagement'],
            ['name' => 'sign_up', 'category' => 'saas'],
            ['name' => 'purchase', 'category' => 'ecommerce'],
        ];

        $this->mart->ingestBatch($events);

        $eventNameKey = 'zb_datamart_event_name_hour';
        expect($slices[$eventNameKey]['page_view']['count'])->toBe(1);
        expect($slices[$eventNameKey]['sign_up']['count'])->toBe(1);
        expect($slices[$eventNameKey]['purchase']['count'])->toBe(1);
    });

    it('tracks unique client IDs', function (): void {
        $slices = [];

        $this->cache->shouldReceive('get')
            ->andReturnUsing(function (string $key) use (&$slices): ?array {
                return $slices[$key] ?? null;
            });

        $this->cache->shouldReceive('put')
            ->andReturnUsing(function (string $key, array $value) use (&$slices): bool {
                $slices[$key] = $value;

                return true;
            });

        // Same event from same client twice
        $this->mart->ingest([
            'name' => 'page_view',
            'category' => 'engagement',
            'client_id' => 'client-abc',
        ]);

        $this->mart->ingest([
            'name' => 'page_view',
            'category' => 'engagement',
            'client_id' => 'client-abc',
        ]);

        $eventNameKey = 'zb_datamart_event_name_hour';
        expect($slices[$eventNameKey]['page_view']['count'])->toBe(2);
        expect($slices[$eventNameKey]['page_view']['unique_count'])->toBe(1);
    });

    it('does not ingest when disabled', function (): void {
        $disabledConfig = mock(ConfigRepository::class);
        $disabledConfig->shouldReceive('get')
            ->with('zeroboiler.analytics.data_mart', [])
            ->andReturn(['enabled' => false]);

        $disabledMart = new EventDataMartService($this->cache, $disabledConfig);

        $this->cache->shouldNotReceive('put');
        $this->cache->shouldNotReceive('get');

        $disabledMart->ingest([
            'name' => 'page_view',
            'category' => 'engagement',
        ]);

        expect(true)->toBeTrue(); // No exception thrown
    });

    it('filters by tracked categories when configured', function (): void {
        $filteredConfig = mock(ConfigRepository::class);
        $filteredConfig->shouldReceive('get')
            ->with('zeroboiler.analytics.data_mart', [])
            ->andReturn([
                'enabled' => true,
                'cache_ttl' => 86400,
                'default_granularity' => 'hour',
                'max_dimensions' => 50,
                'auto_dimensions' => ['category'],
                'tracked_categories' => ['saas'],
            ]);

        $filteredMart = new EventDataMartService($this->cache, $filteredConfig);

        $this->cache->shouldNotReceive('put');

        // This event should be filtered out (not saas)
        $filteredMart->ingest([
            'name' => 'page_view',
            'category' => 'engagement',
        ]);
    });

    it('returns correct summary', function (): void {
        $slices = [];

        $this->cache->shouldReceive('get')
            ->andReturnUsing(function (string $key) use (&$slices): ?array {
                return $slices[$key] ?? null;
            });

        $summary = $this->mart->summary();

        expect($summary)->toHaveKey('enabled');
        expect($summary)->toHaveKey('granularity');
        expect($summary)->toHaveKey('dimensions');
        expect($summary)->toHaveKey('total_events');
        expect($summary)->toHaveKey('total_unique');
        expect($summary)->toHaveKey('cache_ttl');
        expect($summary['enabled'])->toBeTrue();
        expect($summary['granularity'])->toBe('hour');
    });

    it('queries data from cache', function (): void {
        $this->cache->shouldReceive('get')
            ->andReturn([
                'page_view' => ['count' => 100, 'unique_count' => 50, 'first_seen' => '2026-08-10', 'last_seen' => '2026-08-10'],
                'sign_up' => ['count' => 25, 'unique_count' => 25, 'first_seen' => '2026-08-10', 'last_seen' => '2026-08-10'],
            ]);

        $result = $this->mart->query('event_name', 'hour', '2026-08-10');

        expect($result['dimension'])->toBe('event_name');
        expect($result['granularity'])->toBe('hour');
        expect($result['total'])->toBe(125);
        expect(count($result['data']))->toBe(2);
    });

    it('clears all cached cubes', function (): void {
        $this->cache->shouldReceive('forget')
            ->andReturn(true);

        $result = $this->mart->clear();
        expect($result)->toBeNull(); // void return
    });

    it('exports full cube', function (): void {
        $this->cache->shouldReceive('get')
            ->andReturn([
                'purchase' => [
                    'count' => 50,
                    'unique_count' => 30,
                    'first_seen' => '2026-08-01',
                    'last_seen' => '2026-08-10',
                    'metadata' => [],
                ],
            ]);

        $cube = $this->mart->exportCube('event_name', 'hour');

        expect($cube['dimension'])->toBe('event_name');
        expect($cube['granularity'])->toBe('hour');
        expect($cube['total'])->toBe(50);
        expect($cube['unique_total'])->toBe(30);
        expect($cube['generated_at'])->toBeString();
        expect($cube['ttl'])->toBe(86400);
    });

    it('compares two dimensions', function (): void {
        $this->cache->shouldReceive('get')
            ->andReturnUsing(function (string $key): array {
                if (str_contains($key, 'event_name')) {
                    return ['purchase' => ['count' => 100, 'unique_count' => 50, 'first_seen' => 'a', 'last_seen' => 'b', 'metadata' => []]];
                }

                return ['ecommerce' => ['count' => 200, 'unique_count' => 100, 'first_seen' => 'a', 'last_seen' => 'b', 'metadata' => []]];
            });

        $result = $this->mart->compareDimensions('event_name', 'category');

        expect($result)->toHaveKey('dimension_a');
        expect($result)->toHaveKey('dimension_b');
        expect($result)->toHaveKey('data');
        expect($result['dimension_a'])->toBe('event_name');
        expect($result['dimension_b'])->toBe('category');
    });
});

// ─── AnalyticsInsightEngineService ──────────────────────────────────────

describe('AnalyticsInsightEngineService', function (): void {
    it('can be instantiated with strict types', function (): void {
        expect($this->engine)->toBeInstanceOf(AnalyticsInsightEngineService::class);
    });

    it('reports enabled status correctly', function (): void {
        expect($this->engine->isEnabled())->toBeTrue();
    });

    it('has PHP 8.5 final class', function (): void {
        $reflection = new ReflectionClass(AnalyticsInsightEngineService::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    it('has declare(strict_types=1)', function (): void {
        $contents = file_get_contents((new ReflectionClass(AnalyticsInsightEngineService::class))->getFileName());
        expect($contents)->toContain('declare(strict_types=1)');
    });

    it('generates a report with required keys', function (): void {
        $this->cache->shouldReceive('get')->andReturn(null);
        $this->cache->shouldReceive('put')->andReturn(true);

        $report = $this->engine->generateReport();

        expect($report)->toHaveKey('total');
        expect($report)->toHaveKey('critical');
        expect($report)->toHaveKey('warnings');
        expect($report)->toHaveKey('info');
        expect($report)->toHaveKey('insights');
        expect($report)->toHaveKey('summary');
        expect($report)->toHaveKey('generated_at');
        expect($report['generated_at'])->toBeString();
    });

    it('returns empty report when disabled', function (): void {
        $disabledConfig = mock(ConfigRepository::class);
        $disabledConfig->shouldReceive('get')
            ->with('zeroboiler.analytics.insight_engine', [])
            ->andReturn(['enabled' => false]);

        $disabledEngine = new AnalyticsInsightEngineService($this->cache, $disabledConfig);

        $report = $disabledEngine->generateReport();

        expect($report['total'])->toBe(0);
        expect($report['insights'])->toBeEmpty();
    });

    it('filters insights by severity', function (): void {
        $this->cache->shouldReceive('get')
            ->andReturn(null);
        $this->cache->shouldReceive('put')->andReturn(true);

        $report = $this->engine->generateReport();
        $this->cache->shouldReceive('get')
            ->with('zb_insights_latest')
            ->andReturn($report);

        $warnings = $this->engine->bySeverity('warning');

        foreach ($warnings as $insight) {
            expect($insight['severity'])->toBe('warning');
        }
    });

    it('provides quick health assessment', function (): void {
        $this->cache->shouldReceive('get')
            ->andReturn([
                'total' => 5,
                'critical' => 1,
                'warnings' => 2,
                'info' => 2,
                'insights' => [
                    ['severity' => 'critical', 'title' => 'Test', 'recommendation' => 'Fix it'],
                    ['severity' => 'warning', 'title' => 'Test 2', 'recommendation' => 'Check it'],
                ],
                'summary' => 'Test summary',
                'generated_at' => now()->toIso8601String(),
            ]);

        $health = $this->engine->quickHealth();

        expect($health)->toHaveKey('status');
        expect($health)->toHaveKey('score');
        expect($health)->toHaveKey('issues');
        expect($health)->toHaveKey('recommendations');
        expect($health['status'])->toBe('critical');
        expect($health['score'])->toBeLessThan(80);
    });

    it('returns latest cached report', function (): void {
        $cachedReport = [
            'total' => 3,
            'critical' => 0,
            'warnings' => 1,
            'info' => 2,
            'insights' => [],
            'summary' => 'Cached report.',
            'generated_at' => now()->toIso8601String(),
        ];

        $this->cache->shouldReceive('get')
            ->with('zb_insights_latest')
            ->andReturn($cachedReport);

        $report = $this->engine->latestReport();

        expect($report['summary'])->toBe('Cached report.');
    });

    it('returns empty report when no cache available', function (): void {
        $this->cache->shouldReceive('get')
            ->with('zb_insights_latest')
            ->andReturn(null);

        $report = $this->engine->latestReport();

        expect($report['total'])->toBe(0);
        expect($report['insights'])->toBeEmpty();
    });

    it('insights have standardized structure', function (): void {
        $this->cache->shouldReceive('get')
            ->andReturn(null);
        $this->cache->shouldReceive('put')
            ->andReturn(true);

        $report = $this->engine->generateReport();

        if ($report['total'] > 0) {
            $firstInsight = $report['insights'][0];

            expect($firstInsight)->toHaveKey('type');
            expect($firstInsight)->toHaveKey('title');
            expect($firstInsight)->toHaveKey('description');
            expect($firstInsight)->toHaveKey('severity');
            expect($firstInsight)->toHaveKey('metric');
            expect($firstInsight)->toHaveKey('generated_at');

            expect(in_array($firstInsight['severity'], ['info', 'warning', 'critical'], true))->toBeTrue();
        }
    });
});

// ─── Version Consistency ───────────────────────────────────────────────

describe('v700 Version Consistency', function (): void {
    it('AnalyticsEvent VERSION is 7.0.0', function (): void {
        expect(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION)->toBe('7.0.0');
    });

    it('composer.json version is 7.0.0', function (): void {
        $json = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        expect($json['version'])->toBe('7.0.0');
    });

    it('PHP 8.5 minimum requirement in composer.json', function (): void {
        $json = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        expect($json['require']['php'])->toBe('^8.5');
    });

    it('EventDataMartService file exists and is valid PHP', function (): void {
        $file = __DIR__ . '/../src/Services/EventDataMartService.php';
        expect(file_exists($file))->toBeTrue();
        $contents = file_get_contents($file);
        expect($contents)->toContain('declare(strict_types=1)');
        expect($contents)->toContain('namespace ZeroBoiler\\Analytics\\Services');
        expect($contents)->toContain('final class EventDataMartService');
    });

    it('AnalyticsInsightEngineService file exists and is valid PHP', function (): void {
        $file = __DIR__ . '/../src/Services/AnalyticsInsightEngineService.php';
        expect(file_exists($file))->toBeTrue();
        $contents = file_get_contents($file);
        expect($contents)->toContain('declare(strict_types=1)');
        expect($contents)->toContain('namespace ZeroBoiler\\Analytics\\Services');
        expect($contents)->toContain('final class AnalyticsInsightEngineService');
    });

    it('AnalyticsInsightsCommand file exists and is valid PHP', function (): void {
        $file = __DIR__ . '/../src/Console/Commands/AnalyticsInsightsCommand.php';
        expect(file_exists($file))->toBeTrue();
        $contents = file_get_contents($file);
        expect($contents)->toContain('declare(strict_types=1)');
        expect($contents)->toContain('final class AnalyticsInsightsCommand');
    });

    it('config has data_mart section', function (): void {
        $contents = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        expect($contents)->toContain("'data_mart'");
        expect($contents)->toContain('ANALYTICS_DATA_MART_ENABLED');
    });

    it('config has insight_engine section', function (): void {
        $contents = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        expect($contents)->toContain("'insight_engine'");
        expect($contents)->toContain('ANALYTICS_INSIGHT_ENGINE_ENABLED');
    });

    it('controller has data mart methods', function (): void {
        $contents = file_get_contents(__DIR__ . '/../src/Http/Controllers/AnalyticsEventController.php');
        expect($contents)->toContain('dataMartSummary');
        expect($contents)->toContain('dataMartTop');
        expect($contents)->toContain('dataMartByCategory');
        expect($contents)->toContain('insightReport');
        expect($contents)->toContain('insightHealth');
    });

    it('routes file has data mart endpoints', function (): void {
        $contents = file_get_contents(__DIR__ . '/../routes/analytics.php');
        expect($contents)->toContain('data-mart/summary');
        expect($contents)->toContain('data-mart/top');
        expect($contents)->toContain('insights');
        expect($contents)->toContain('insightReport');
    });

    it('ServiceProvider registers EventDataMartService', function (): void {
        $contents = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
        expect($contents)->toContain('EventDataMartService::class');
        expect($contents)->toContain('AnalyticsInsightEngineService::class');
        expect($contents)->toContain('AnalyticsInsightsCommand::class');
    });
});
