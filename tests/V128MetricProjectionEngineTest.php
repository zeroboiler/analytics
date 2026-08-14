<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\MetricProjectionResult;
use ZeroBoiler\Analytics\DTO\ProjectionDefinition;
use ZeroBoiler\Analytics\Services\EventMaterializer;
use ZeroBoiler\Analytics\Services\MetricProjectionEngine;
use ZeroBoiler\Analytics\Services\ProjectionRegistry;

beforeEach(function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->andReturn(true);
    $cache->shouldReceive('forget')->andReturn(true);
    $cache->shouldReceive('increment')->andReturn(0);

    $this->cache = $cache;
    $this->registry = new ProjectionRegistry($cache);
});

afterEach(function (): void {
    Mockery::close();
});

describe('ProjectionDefinition', function (): void {
    it('creates a count projection definition', function (): void {
        $def = new ProjectionDefinition(
            name: 'test_count',
            label: 'Test Count',
            type: ProjectionDefinition::TYPE_COUNT,
            event: 'page_view',
            window: '24h',
            category: 'engagement',
        );

        expect($def->name)->toBe('test_count');
        expect($def->type)->toBe('count');
        expect($def->event)->toBe('page_view');
        expect($def->window)->toBe('24h');
        expect($def->category)->toBe('engagement');
    });

    it('validates successfully for a valid count projection', function (): void {
        $def = new ProjectionDefinition(
            name: 'valid_count',
            label: 'Valid Count',
            type: ProjectionDefinition::TYPE_COUNT,
            event: 'page_view',
        );

        expect($def->validate())->toBe([]);
    });

    it('detects invalid type', function (): void {
        $def = new ProjectionDefinition(
            name: 'invalid_type',
            label: 'Invalid Type',
            type: 'nonexistent',
            event: 'page_view',
        );

        $errors = $def->validate();
        expect($errors)->not->toBeEmpty();
        expect($errors[0])->toContain('Invalid type');
    });

    it('detects missing field for sum type', function (): void {
        $def = new ProjectionDefinition(
            name: 'missing_field',
            label: 'Missing Field',
            type: ProjectionDefinition::TYPE_SUM,
            event: 'purchase',
            field: null,
        );

        $errors = $def->validate();
        expect($errors)->not->toBeEmpty();
        expect($errors[0])->toContain('Field is required');
    });

    it('detects missing distinct field for unique_count type', function (): void {
        $def = new ProjectionDefinition(
            name: 'missing_distinct',
            label: 'Missing Distinct',
            type: ProjectionDefinition::TYPE_UNIQUE_COUNT,
            event: 'page_view',
            distinctField: null,
        );

        $errors = $def->validate();
        expect($errors)->not->toBeEmpty();
        expect($errors[0])->toContain('Distinct field is required');
    });

    it('detects missing funnel target for funnel_rate type', function (): void {
        $def = new ProjectionDefinition(
            name: 'missing_funnel',
            label: 'Missing Funnel',
            type: ProjectionDefinition::TYPE_FUNNEL_RATE,
            event: 'start_trial',
            funnelTarget: null,
        );

        $errors = $def->validate();
        expect($errors)->not->toBeEmpty();
        expect($errors[0])->toContain('Funnel target');
    });

    it('detects missing ratio denominator for ratio type', function (): void {
        $def = new ProjectionDefinition(
            name: 'missing_ratio',
            label: 'Missing Ratio',
            type: ProjectionDefinition::TYPE_RATIO,
            event: 'sign_up',
            ratioDenominator: null,
        );

        $errors = $def->validate();
        expect($errors)->not->toBeEmpty();
        expect($errors[0])->toContain('Ratio denominator');
    });

    it('converts to array and back', function (): void {
        $original = new ProjectionDefinition(
            name: 'test_convert',
            label: 'Test Convert',
            type: ProjectionDefinition::TYPE_COUNT,
            event: 'page_view',
            field: null,
            distinctField: null,
            funnelTarget: null,
            ratioDenominator: null,
            window: '7d',
            cacheTtl: 600,
            category: 'growth',
            description: 'Test description',
            filters: ['source' => 'web'],
            tags: ['saas', 'growth'],
            public: true,
        );

        $array = $original->toArray();
        $restored = ProjectionDefinition::fromArray($array);

        expect($restored->name)->toBe($original->name);
        expect($restored->type)->toBe($original->type);
        expect($restored->event)->toBe($original->event);
        expect($restored->window)->toBe($original->window);
        expect($restored->tags)->toBe($original->tags);
        expect($restored->public)->toBe($original->public);
    });
});

describe('ProjectionRegistry', function (): void {
    it('registers built-in projections on construction', function (): void {
        expect($this->registry->count())->toBeGreaterThanOrEqual(13);
        expect($this->registry->has('dau'))->toBeTrue();
        expect($this->registry->has('weekly_signups'))->toBeTrue();
        expect($this->registry->has('trial_conversion_rate'))->toBeTrue();
    });

    it('returns a projection by name', function (): void {
        $dau = $this->registry->get('dau');

        expect($dau)->not->toBeNull();
        expect($dau->type)->toBe(ProjectionDefinition::TYPE_UNIQUE_COUNT);
        expect($dau->event)->toBe('page_view');
        expect($dau->distinctField)->toBe('client_id');
    });

    it('returns null for unknown projection', function (): void {
        expect($this->registry->get('nonexistent'))->toBeNull();
        expect($this->registry->has('nonexistent'))->toBeFalse();
    });

    it('lists all projection names', function (): void {
        $names = $this->registry->names();

        expect($names)->toBeArray();
        expect($names)->toContain('dau');
        expect($names)->toContain('weekly_signups');
        expect($names)->toContain('total_revenue_30d');
    });

    it('filters projections by category', function (): void {
        $growth = $this->registry->byCategory('growth');

        expect($growth)->not->toBeEmpty();
        foreach ($growth as $definition) {
            expect($definition->category)->toBe('growth');
        }
    });

    it('filters projections by tag', function (): void {
        $critical = $this->registry->byTag('critical');

        expect($critical)->not->toBeEmpty();
        foreach ($critical as $definition) {
            expect($definition->tags)->toContain('critical');
        }
    });

    it('returns public projections', function (): void {
        $public = $this->registry->publicProjections();

        expect($public)->not->toBeEmpty();
        foreach ($public as $definition) {
            expect($definition->public)->toBeTrue();
        }
    });

    it('groups projections by category', function (): void {
        $grouped = $this->registry->groupedByCategory();

        expect($grouped)->toBeArray();
        expect(isset($grouped['engagement']))->toBeTrue();
        expect(isset($grouped['revenue']))->toBeTrue();
        expect(isset($grouped['growth']))->toBeTrue();
    });

    it('registers a custom projection', function (): void {
        $custom = new ProjectionDefinition(
            name: 'custom_metric',
            label: 'Custom Metric',
            type: ProjectionDefinition::TYPE_COUNT,
            event: 'custom_event',
            category: 'custom',
        );

        $result = $this->registry->register($custom);

        expect($result)->toBeTrue();
        expect($this->registry->has('custom_metric'))->toBeTrue();
    });

    it('rejects invalid projections', function (): void {
        $invalid = new ProjectionDefinition(
            name: '',
            label: 'Empty Name',
            type: 'invalid_type',
            event: 'page_view',
        );

        $result = $this->registry->register($invalid);

        expect($result)->toBeFalse();
        expect($this->registry->errors())->toHaveKey('');
    });

    it('forgets a projection', function (): void {
        // Register a custom projection first
        $custom = new ProjectionDefinition(
            name: 'to_forget',
            label: 'To Forget',
            type: ProjectionDefinition::TYPE_COUNT,
            event: 'page_view',
        );
        $this->registry->register($custom);

        expect($this->registry->has('to_forget'))->toBeTrue();

        $forgotten = $this->registry->forget('to_forget');

        expect($forgotten)->toBeTrue();
        expect($this->registry->has('to_forget'))->toBeFalse();
    });

    it('forgets non-existent projection returns false', function (): void {
        expect($this->registry->forget('nonexistent'))->toBeFalse();
    });

    it('flushes all projections', function (): void {
        $this->registry->flush();

        expect($this->registry->count())->toBe(0);
        expect($this->registry->names())->toBe([]);
    });

    it('validates all projections', function (): void {
        $validation = $this->registry->validate();

        expect($validation)->toHaveKey('valid');
        expect($validation)->toHaveKey('invalid');
        expect($validation)->toHaveKey('errors');
        expect($validation['invalid'])->toBe(0);
        expect($validation['errors'])->toBe([]);
    });

    it('returns registry summary', function (): void {
        $summary = $this->registry->summary();

        expect($summary)->toHaveKey('count');
        expect($summary)->toHaveKey('categories');
        expect($summary)->toHaveKey('types');
        expect($summary)->toHaveKey('public_count');
        expect($summary)->toHaveKey('names');
        expect($summary['count'])->toBeGreaterThan(0);
        expect($summary['public_count'])->toBeGreaterThan(0);
    });

    it('loads projections from config array', function (): void {
        $this->registry->flush();

        $config = [
            'config_metric' => [
                'type' => 'count',
                'event' => 'page_view',
                'window' => '24h',
                'category' => 'config',
            ],
            'config_revenue' => [
                'type' => 'sum',
                'event' => 'purchase',
                'field' => 'value',
                'category' => 'revenue',
            ],
        ];

        $count = $this->registry->loadFromConfig($config);

        expect($count)->toBe(2);
        expect($this->registry->has('config_metric'))->toBeTrue();
        expect($this->registry->has('config_revenue'))->toBeTrue();
    });
});

describe('MetricProjectionResult', function (): void {
    it('creates a projection result', function (): void {
        $now = new \DateTimeImmutable();
        $staleAt = $now->modify('+5 minutes');

        $result = new MetricProjectionResult(
            name: 'dau',
            value: 1234,
            type: 'unique_count',
            eventCount: 5678,
            window: '24h',
            computedAt: $now,
            staleAt: $staleAt,
            cached: true,
            metadata: ['event' => 'page_view'],
        );

        expect($result->name)->toBe('dau');
        expect($result->value)->toBe(1234);
        expect($result->type)->toBe('unique_count');
        expect($result->eventCount)->toBe(5678);
        expect($result->cached)->toBeTrue();
    });

    it('converts to array and back', function (): void {
        $now = new \DateTimeImmutable();
        $original = new MetricProjectionResult(
            name: 'test',
            value: 42.5,
            type: 'average',
            eventCount: 100,
            window: '7d',
            computedAt: $now,
            cached: false,
            metadata: ['foo' => 'bar'],
        );

        $array = $original->toArray();
        $restored = MetricProjectionResult::fromArray($array);

        expect($restored->name)->toBe($original->name);
        expect($restored->value)->toBe($original->value);
        expect($restored->type)->toBe($original->type);
        expect($restored->cached)->toBeFalse();
    });

    it('detects staleness correctly', function (): void {
        $now = new \DateTimeImmutable();
        $stale = new \DateTimeImmutable('-10 seconds');

        $fresh = new MetricProjectionResult(
            name: 'fresh',
            value: 1,
            type: 'count',
            staleAt: $now->modify('+1 hour'),
        );

        $expired = new MetricProjectionResult(
            name: 'expired',
            value: 2,
            type: 'count',
            staleAt: $stale,
        );

        expect($fresh->isStale($now))->toBeFalse();
        expect($expired->isStale($now))->toBeTrue();
    });

    it('returns null stale when no staleAt is set', function (): void {
        $result = new MetricProjectionResult(
            name: 'no_stale',
            value: 1,
            type: 'count',
        );

        expect($result->isStale())->toBeFalse();
    });

    it('extracts numeric value', function (): void {
        $intResult = new MetricProjectionResult(name: 'int', value: 42, type: 'count');
        $floatResult = new MetricProjectionResult(name: 'float', value: 3.14, type: 'average');
        $stringResult = new MetricProjectionResult(name: 'string', value: '100', type: 'count');
        $nonNumeric = new MetricProjectionResult(name: 'non_numeric', value: 'abc', type: 'count');

        expect($intResult->numericValue())->toBe(42.0);
        expect($floatResult->numericValue())->toBe(3.14);
        expect($stringResult->numericValue())->toBe(100.0);
        expect($nonNumeric->numericValue())->toBeNull();
    });
});

describe('MetricProjectionEngine', function (): void {
    it('returns null for non-existent projection', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.projections', [])->andReturn([]);
        $manager = Mockery::mock(AnalyticsManager::class);
        $registry = new ProjectionRegistry($this->cache);

        $engine = new MetricProjectionEngine($config, $this->cache, $manager, $registry);

        expect($engine->evaluate('nonexistent'))->toBeNull();
    });

    it('evaluates a count projection without error', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.projections', [])->andReturn([]);
        $manager = Mockery::mock(AnalyticsManager::class);
        $registry = new ProjectionRegistry($this->cache);

        $engine = new MetricProjectionEngine($config, $this->cache, $manager, $registry);

        // DAU is a built-in unique_count projection — should not throw
        $result = $engine->evaluate('dau');

        expect($result)->not->toBeNull();
        expect($result->name)->toBe('dau');
        expect($result->type)->toBe('unique_count');
        expect($result->window)->toBe('24h');
    });

    it('evaluates multiple projections', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.projections', [])->andReturn([]);
        $manager = Mockery::mock(AnalyticsManager::class);
        $registry = new ProjectionRegistry($this->cache);

        $engine = new MetricProjectionEngine($config, $this->cache, $manager, $registry);

        $results = $engine->evaluateMultiple(['dau', 'weekly_signups', 'nonexistent']);

        expect($results)->toHaveKey('dau');
        expect($results)->toHaveKey('weekly_signups');
        expect($results)->toHaveKey('nonexistent');
        expect($results['dau'])->not->toBeNull();
        expect($results['weekly_signups'])->not->toBeNull();
        expect($results['nonexistent'])->toBeNull();
    });

    it('evaluates all projections', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.projections', [])->andReturn([]);
        $manager = Mockery::mock(AnalyticsManager::class);
        $registry = new ProjectionRegistry($this->cache);

        $engine = new MetricProjectionEngine($config, $this->cache, $manager, $registry);

        $results = $engine->evaluateAll();

        expect(count($results))->toBe($registry->count());
        foreach ($results as $name => $result) {
            expect($result)->not->toBeNull();
            expect($result->name)->toBe($name);
        }
    });

    it('returns engine status', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.projections', [])->andReturn([]);
        $manager = Mockery::mock(AnalyticsManager::class);
        $registry = new ProjectionRegistry($this->cache);

        $engine = new MetricProjectionEngine($config, $this->cache, $manager, $registry);
        $status = $engine->status();

        expect($status)->toHaveKey('enabled');
        expect($status)->toHaveKey('cache_enabled');
        expect($status)->toHaveKey('cache_ttl');
        expect($status)->toHaveKey('projection_count');
        expect($status['enabled'])->toBeTrue();
        expect($status['projection_count'])->toBeGreaterThan(0);
    });
});

describe('EventMaterializer', function (): void {
    it('gets a materialized projection', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.projections', [])->andReturn([]);
        $manager = Mockery::mock(AnalyticsManager::class);
        $registry = new ProjectionRegistry($this->cache);
        $engine = new MetricProjectionEngine($config, $this->cache, $manager, $registry);
        $materializer = new EventMaterializer($engine, $registry);

        $result = $materializer->get('dau');

        expect($result)->not->toBeNull();
        expect($result->name)->toBe('dau');
    });

    it('gets multiple projections', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.projections', [])->andReturn([]);
        $manager = Mockery::mock(AnalyticsManager::class);
        $registry = new ProjectionRegistry($this->cache);
        $engine = new MetricProjectionEngine($config, $this->cache, $manager, $registry);
        $materializer = new EventMaterializer($engine, $registry);

        $results = $materializer->getMultiple(['dau', 'weekly_signups']);

        expect(count($results))->toBe(2);
        expect($results['dau'])->not->toBeNull();
        expect($results['weekly_signups'])->not->toBeNull();
    });

    it('returns dashboard structure', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.projections', [])->andReturn([]);
        $manager = Mockery::mock(AnalyticsManager::class);
        $registry = new ProjectionRegistry($this->cache);
        $engine = new MetricProjectionEngine($config, $this->cache, $manager, $registry);
        $materializer = new EventMaterializer($engine, $registry);

        $dashboard = $materializer->dashboard();

        expect($dashboard)->toHaveKey('metrics');
        expect($dashboard)->toHaveKey('categories');
        expect($dashboard)->toHaveKey('total');
        expect($dashboard['total'])->toBeGreaterThan(0);
    });

    it('returns dashboard filtered by category', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.projections', [])->andReturn([]);
        $manager = Mockery::mock(AnalyticsManager::class);
        $registry = new ProjectionRegistry($this->cache);
        $engine = new MetricProjectionEngine($config, $this->cache, $manager, $registry);
        $materializer = new EventMaterializer($engine, $registry);

        $dashboard = $materializer->dashboard('revenue');

        foreach ($dashboard['metrics'] as $metric) {
            expect($metric['category'])->toBe('revenue');
        }
    });

    it('refreshes a single projection', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.projections', [])->andReturn([]);
        $manager = Mockery::mock(AnalyticsManager::class);
        $registry = new ProjectionRegistry($this->cache);
        $engine = new MetricProjectionEngine($config, $this->cache, $manager, $registry);
        $materializer = new EventMaterializer($engine, $registry);

        $result = $materializer->refresh('dau');

        expect($result)->not->toBeNull();
        expect($result->cached)->toBeFalse(); // Freshly computed
        expect($materializer->lastRefreshAt('dau'))->not->toBeNull();
    });

    it('refreshes all projections', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.projections', [])->andReturn([]);
        $manager = Mockery::mock(AnalyticsManager::class);
        $registry = new ProjectionRegistry($this->cache);
        $engine = new MetricProjectionEngine($config, $this->cache, $manager, $registry);
        $materializer = new EventMaterializer($engine, $registry);

        $results = $materializer->refreshAll();

        expect(count($results))->toBe($registry->count());
        foreach ($results as $result) {
            expect($result)->not->toBeNull();
            expect($result->cached)->toBeFalse();
        }
    });

    it('detects stale projections', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.projections', [])->andReturn([]);
        $manager = Mockery::mock(AnalyticsManager::class);
        $registry = new ProjectionRegistry($this->cache);
        $engine = new MetricProjectionEngine($config, $this->cache, $manager, $registry);
        $materializer = new EventMaterializer($engine, $registry);

        // All projections are stale before any refresh
        $stale = $materializer->staleProjections(0);

        expect($stale)->toBe($registry->names());
    });

    it('exports all metrics', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.projections', [])->andReturn([]);
        $manager = Mockery::mock(AnalyticsManager::class);
        $registry = new ProjectionRegistry($this->cache);
        $engine = new MetricProjectionEngine($config, $this->cache, $manager, $registry);
        $materializer = new EventMaterializer($engine, $registry);

        $export = $materializer->export();

        expect($export)->toBeArray();
        expect(count($export))->toBeGreaterThan(0);
        expect($export)->toHaveKey('dau');
        expect($export['dau'])->toHaveKey('name');
        expect($export['dau'])->toHaveKey('value');
        expect($export['dau'])->toHaveKey('type');
    });

    it('returns materializer summary', function (): void {
        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.projections', [])->andReturn([]);
        $manager = Mockery::mock(AnalyticsManager::class);
        $registry = new ProjectionRegistry($this->cache);
        $engine = new MetricProjectionEngine($config, $this->cache, $manager, $registry);
        $materializer = new EventMaterializer($engine, $registry);

        $summary = $materializer->summary();

        expect($summary)->toHaveKey('projection_count');
        expect($summary)->toHaveKey('refreshed_count');
        expect($summary)->toHaveKey('stale_count');
        expect($summary)->toHaveKey('categories');
        expect($summary['projection_count'])->toBeGreaterThan(0);
    });
});

describe('Version integrity', function (): void {
    it('has ProjectionDefinition with strict types', function (): void {
        $reflection = new ReflectionClass(ProjectionDefinition::class);
        $file = $reflection->getFileName();

        expect($file)->not->toBeFalse();
        $contents = file_get_contents($file);
        expect($contents)->toContain('declare(strict_types=1)');
    });

    it('has MetricProjectionResult as readonly', function (): void {
        $reflection = new ReflectionClass(MetricProjectionResult::class);

        expect($reflection->isReadOnly())->toBeTrue();
    });

    it('has ProjectionRegistry as final', function (): void {
        $reflection = new ReflectionClass(ProjectionRegistry::class);

        expect($reflection->isFinal())->toBeTrue();
    });

    it('has MetricProjectionEngine as final', function (): void {
        $reflection = new ReflectionClass(MetricProjectionEngine::class);

        expect($reflection->isFinal())->toBeTrue();
    });

    it('has EventMaterializer as final', function (): void {
        $reflection = new ReflectionClass(EventMaterializer::class);

        expect($reflection->isFinal())->toBeTrue();
    });
});
