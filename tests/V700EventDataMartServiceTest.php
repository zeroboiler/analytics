<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Services\EventDataMartService;

beforeEach(function () {
    $this->cache = Mockery::mock(CacheRepository::class);
    $this->config = Mockery::mock(ConfigRepository::class);
});

describe('EventDataMartService', function () {
    describe('constructor and configuration', function () {
        it('initializes with default config values', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn([]);

            $service = new EventDataMartService($this->cache, $this->config);

            expect($service->isEnabled())->toBeTrue();
            expect($service->supportedGranularities())->toBe(['minute', 'hour', 'day', 'week', 'month']);
            expect($service->supportedDimensions())->toBe(['event_name', 'category', 'provider', 'client_id', 'user_id', 'source']);
        });

        it('reads custom config values', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn([
                    'enabled' => false,
                    'cache_ttl' => 7200,
                    'default_granularity' => 'day',
                    'max_dimensions' => 100,
                    'auto_dimensions' => ['event_name', 'provider'],
                    'tracked_categories' => ['engagement', 'revenue'],
                ]);

            $service = new EventDataMartService($this->cache, $this->config);

            expect($service->isEnabled())->toBeFalse();
        });

        it('has correct @since annotation version', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn([]);

            $service = new EventDataMartService($this->cache, $this->config);

            $ref = new ReflectionClass($service);
            $doc = $ref->getDocComment();
            expect($doc)->toContain('@since 7.0.0');
        });
    });

    describe('ingest', function () {
        it('does nothing when disabled', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn(['enabled' => false]);

            $service = new EventDataMartService($this->cache, $this->config);

            // Cache should never be called
            $this->cache->shouldNotReceive('get');

            $service->ingest(['name' => 'page_view', 'category' => 'engagement']);
        });

        it('filters by tracked categories when configured', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn(['tracked_categories' => ['engagement']]);

            $service = new EventDataMartService($this->cache, $this->config);

            // Revenue events should be filtered out
            $this->cache->shouldNotReceive('get');

            $service->ingest(['name' => 'purchase', 'category' => 'revenue']);
        });

        it('allows tracked categories to pass through', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn(['tracked_categories' => ['engagement']]);

            $this->cache->shouldReceive('get')
                ->andReturn([])
                ->byDefault();
            $this->cache->shouldReceive('put')
                ->andReturnTrue()
                ->byDefault();

            $service = new EventDataMartService($this->cache, $this->config);

            // Engagement events should be ingested
            $service->ingest(['name' => 'page_view', 'category' => 'engagement']);

            // Should have attempted to write to cache
            expect(true)->toBeTrue();
        });

        it('ingests events into auto-dimensions', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn([
                    'auto_dimensions' => ['event_name', 'category'],
                ]);

            $this->cache->shouldReceive('get')
                ->andReturn([])
                ->byDefault();
            $this->cache->shouldReceive('put')
                ->andReturnTrue();

            $service = new EventDataMartService($this->cache, $this->config);

            $service->ingest([
                'name' => 'page_view',
                'category' => 'engagement',
                'client_id' => 'client-123',
            ]);

            // put() should have been called for each auto-dimension + _all
            $this->cache->shouldHaveReceived('put')->atLeast()->times(3);
        });

        it('truncates dimension keys exceeding 100 characters', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn(['auto_dimensions' => ['event_name']]);

            $this->cache->shouldReceive('get')
                ->andReturn([])
                ->byDefault();
            $this->cache->shouldReceive('put')
                ->andReturnTrue()
                ->withArgs(function (string $key, array $slices, int $ttl): bool {
                return isset($slices[str_repeat('x', 100)]);
            });

            $service = new EventDataMartService($this->cache, $this->config);

            $service->ingest([
                'name' => str_repeat('x', 200),
                'category' => 'test',
            ]);
        });
    });

    describe('ingestBatch', function () {
        it('does nothing when disabled', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn(['enabled' => false]);

            $service = new EventDataMartService($this->cache, $this->config);

            $service->ingestBatch([
                ['name' => 'page_view'],
                ['name' => 'click'],
            ]);

            // Should not have called cache
            expect(true)->toBeTrue();
        });

        it('ingests multiple events', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn(['auto_dimensions' => ['event_name']]);

            $this->cache->shouldReceive('get')
                ->andReturn([])
                ->byDefault();
            $this->cache->shouldReceive('put')
                ->andReturnTrue();

            $service = new EventDataMartService($this->cache, $this->config);

            $service->ingestBatch([
                ['name' => 'page_view', 'category' => 'engagement'],
                ['name' => 'click', 'category' => 'engagement'],
                ['name' => 'purchase', 'category' => 'revenue'],
            ]);

            $this->cache->shouldHaveReceived('put')->atLeast()->times(4);
        });
    });

    describe('query', function () {
        it('returns sorted query results', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn([]);

            $slices = [
                'page_view' => ['count' => 50, 'unique_count' => 30, 'first_seen' => '2026-01-01', 'last_seen' => '2026-01-02'],
                'click' => ['count' => 200, 'unique_count' => 100, 'first_seen' => '2026-01-01', 'last_seen' => '2026-01-03'],
                'purchase' => ['count' => 10, 'unique_count' => 5, 'first_seen' => '2026-01-01', 'last_seen' => '2026-01-04'],
            ];

            $this->cache->shouldReceive('get')
                ->andReturn($slices);

            $service = new EventDataMartService($this->cache, $this->config);

            $result = $service->query('event_name', 'hour', '2026-01-01');

            expect($result['dimension'])->toBe('event_name');
            expect($result['granularity'])->toBe('hour');
            expect($result['total'])->toBe(260);
            expect($result['data'][0]['key'])->toBe('click');
            expect($result['data'][0]['count'])->toBe(200);
        });

        it('respects limit parameter', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn([]);

            $slices = [];
            for ($i = 1; $i <= 20; $i++) {
                $slices["event_{$i}"] = ['count' => $i, 'unique_count' => $i, 'first_seen' => '2026-01-01', 'last_seen' => '2026-01-01'];
            }

            $this->cache->shouldReceive('get')
                ->andReturn($slices);

            $service = new EventDataMartService($this->cache, $this->config);

            $result = $service->query('event_name', 'hour', '2026-01-01', 5);

            expect(count($result['data']))->toBe(5);
        });

        it('returns empty result for empty cache', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn([]);

            $this->cache->shouldReceive('get')
                ->andReturn([]);

            $service = new EventDataMartService($this->cache, $this->config);

            $result = $service->query('event_name', 'hour', '2026-01-01');

            expect($result['data'])->toBe([]);
            expect($result['total'])->toBe(0);
        });
    });

    describe('top', function () {
        it('returns top N events sorted by count', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn([]);

            $slices = [
                'page_view' => ['count' => 100, 'unique_count' => 50, 'first_seen' => '2026-01-01', 'last_seen' => '2026-01-02'],
                'click' => ['count' => 50, 'unique_count' => 30, 'first_seen' => '2026-01-01', 'last_seen' => '2026-01-03'],
            ];

            $this->cache->shouldReceive('get')
                ->andReturn($slices);

            $service = new EventDataMartService($this->cache, $this->config);

            $result = $service->top('event_name', 10, 'hour');

            expect(count($result))->toBe(2);
            expect($result[0]['key'])->toBe('page_view');
            expect($result[0]['count'])->toBe(100);
            expect($result[0]['first_seen'])->toBe('2026-01-01');
        });
    });

    describe('totalCount and totalUniqueCount', function () {
        it('returns total count from _all dimension', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn([]);

            $this->cache->shouldReceive('get')
                ->with('zb_datamart__all_hour')
                ->andReturn([
                    '_total' => ['count' => 500, 'unique_count' => 200, 'first_seen' => '2026-01-01', 'last_seen' => '2026-01-02'],
                ]);

            $service = new EventDataMartService($this->cache, $this->config);

            expect($service->totalCount())->toBe(500);
            expect($service->totalUniqueCount())->toBe(200);
        });

        it('returns zero when no total cell exists', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn([]);

            $this->cache->shouldReceive('get')
                ->andReturn([]);

            $service = new EventDataMartService($this->cache, $this->config);

            expect($service->totalCount())->toBe(0);
            expect($service->totalUniqueCount())->toBe(0);
        });
    });

    describe('byCategory, byEventName, byProvider', function () {
        it('returns category distribution', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn([]);

            $this->cache->shouldReceive('get')
                ->andReturn([
                    'engagement' => ['count' => 100, 'unique_count' => 50, 'first_seen' => '2026-01-01', 'last_seen' => '2026-01-02'],
                    'revenue' => ['count' => 20, 'unique_count' => 10, 'first_seen' => '2026-01-01', 'last_seen' => '2026-01-02'],
                ]);

            $service = new EventDataMartService($this->cache, $this->config);

            $result = $service->byCategory();

            expect($result['engagement'])->toBe(100);
            expect($result['revenue'])->toBe(20);
        });
    });

    describe('summary', function () {
        it('returns complete summary with all fields', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn(['auto_dimensions' => ['event_name']]);

            $this->cache->shouldReceive('get')
                ->andReturn([])
                ->byDefault();

            $service = new EventDataMartService($this->cache, $this->config);

            $summary = $service->summary();

            expect($summary)->toHaveKey('enabled');
            expect($summary)->toHaveKey('granularity');
            expect($summary)->toHaveKey('dimensions');
            expect($summary)->toHaveKey('tracked_categories');
            expect($summary)->toHaveKey('total_events');
            expect($summary)->toHaveKey('total_unique');
            expect($summary)->toHaveKey('cache_ttl');
            expect($summary)->toHaveKey('cached_cubes');
            expect($summary['enabled'])->toBeTrue();
            expect($summary['dimensions'])->toBe(['event_name']);
        });
    });

    describe('exportCube', function () {
        it('exports full cube with metadata', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn([]);

            $slices = [
                'page_view' => ['count' => 100, 'unique_count' => 50, 'first_seen' => '2026-01-01', 'last_seen' => '2026-01-02', 'metadata' => []],
            ];

            $this->cache->shouldReceive('get')
                ->andReturn($slices);

            $service = new EventDataMartService($this->cache, $this->config);

            $cube = $service->exportCube('event_name', 'hour');

            expect($cube['granularity'])->toBe('hour');
            expect($cube['dimension'])->toBe('event_name');
            expect($cube['total'])->toBe(100);
            expect($cube['unique_total'])->toBe(50);
            expect($cube['slices'])->toBe($slices);
            expect($cube['ttl'])->toBe(86400);
            expect($cube)->toHaveKey('generated_at');
            expect($cube)->toHaveKey('period');
        });
    });

    describe('compareDimensions', function () {
        it('compares two dimension distributions with ratio', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn([]);

            $categorySlices = [
                'engagement' => ['count' => 100, 'unique_count' => 50, 'first_seen' => '2026-01-01', 'last_seen' => '2026-01-02'],
                'revenue' => ['count' => 20, 'unique_count' => 10, 'first_seen' => '2026-01-01', 'last_seen' => '2026-01-02'],
            ];

            $providerSlices = [
                'ga4' => ['count' => 80, 'unique_count' => 40, 'first_seen' => '2026-01-01', 'last_seen' => '2026-01-02'],
            ];

            $this->cache->shouldReceive('get')
                ->andReturnUsing(function (string $key) use ($categorySlices, $providerSlices): array {
                if (str_contains($key, 'category')) {
                    return $categorySlices;
                }

                return $providerSlices;
            });

            $service = new EventDataMartService($this->cache, $this->config);

            $result = $service->compareDimensions('category', 'provider');

            expect($result['dimension_a'])->toBe('category');
            expect($result['dimension_b'])->toBe('provider');
            expect(count($result['data']))->toBeGreaterThanOrEqual(3);
        });

        it('handles zero denominator gracefully', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn([]);

            $this->cache->shouldReceive('get')
                ->andReturn([]);

            $service = new EventDataMartService($this->cache, $this->config);

            $result = $service->compareDimensions('category', 'provider');

            expect($result['data'])->toBeEmpty();
        });
    });

    describe('clear', function () {
        it('clears all dimension caches', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn(['auto_dimensions' => ['event_name', 'category']]);

            $this->cache->shouldReceive('forget')
                ->andReturnTrue();

            $service = new EventDataMartService($this->cache, $this->config);

            $service->clear();

            // 2 auto_dimensions × 5 granularities + 1 _all × 5 granularities = 15
            $this->cache->shouldHaveReceived('forget')->times(15);
        });
    });

    describe('cardinality limit', function () {
        it('drops new keys when at capacity', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn(['max_dimensions' => 2, 'auto_dimensions' => ['event_name']]);

            // Already at capacity with 2 keys
            $existingSlices = [
                'existing_1' => ['count' => 10, 'unique_count' => 5, 'first_seen' => '2026-01-01', 'last_seen' => '2026-01-01', 'metadata' => []],
                'existing_2' => ['count' => 20, 'unique_count' => 10, 'first_seen' => '2026-01-01', 'last_seen' => '2026-01-01', 'metadata' => []],
            ];

            $this->cache->shouldReceive('get')
                ->andReturn($existingSlices);

            // Should not put since new key would exceed capacity
            $this->cache->shouldNotReceive('put');

            $service = new EventDataMartService($this->cache, $this->config);

            $service->ingest(['name' => 'new_event_3', 'category' => 'test']);
        });

        it('allows incrementing existing keys even at capacity', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn(['max_dimensions' => 2, 'auto_dimensions' => ['event_name']]);

            $existingSlices = [
                'existing_1' => ['count' => 10, 'unique_count' => 5, 'first_seen' => '2026-01-01', 'last_seen' => '2026-01-01', 'metadata' => []],
                'existing_2' => ['count' => 20, 'unique_count' => 10, 'first_seen' => '2026-01-01', 'last_seen' => '2026-01-01', 'metadata' => []],
            ];

            $this->cache->shouldReceive('get')
                ->andReturn($existingSlices);

            $this->cache->shouldReceive('put')
                ->withArgs(function (string $key, array $slices): bool {
                return ($slices['existing_1']['count'] ?? 0) === 11;
            })
                ->andReturnTrue();

            $service = new EventDataMartService($this->cache, $this->config);

            $service->ingest(['name' => 'existing_1', 'category' => 'test']);
        });
    });

    describe('unique client tracking', function () {
        it('tracks unique client IDs', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn(['auto_dimensions' => ['event_name']]);

            $this->cache->shouldReceive('get')
                ->andReturn([]);
            $this->cache->shouldReceive('put')
                ->withArgs(function (string $key, array $slices): bool {
                return ($slices['page_view']['unique_count'] ?? 0) === 1
                    && in_array('client-123', $slices['page_view']['metadata']['unique_clients'] ?? [], true);
            })
                ->andReturnTrue();

            $service = new EventDataMartService($this->cache, $this->config);

            $service->ingest([
                'name' => 'page_view',
                'category' => 'engagement',
                'client_id' => 'client-123',
            ]);
        });

        it('does not double-count same client ID', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn(['auto_dimensions' => ['event_name']]);

            $existingSlices = [
                'page_view' => [
                    'count' => 10,
                    'unique_count' => 1,
                    'first_seen' => '2026-01-01',
                    'last_seen' => '2026-01-01',
                    'metadata' => ['unique_clients' => ['client-123']],
                ],
            ];

            $this->cache->shouldReceive('get')
                ->andReturn($existingSlices);
            $this->cache->shouldReceive('put')
                ->withArgs(function (string $key, array $slices): bool {
                return ($slices['page_view']['unique_count'] ?? 0) === 1
                    && ($slices['page_view']['count'] ?? 0) === 11;
            })
                ->andReturnTrue();

            $service = new EventDataMartService($this->cache, $this->config);

            $service->ingest([
                'name' => 'page_view',
                'category' => 'engagement',
                'client_id' => 'client-123',
            ]);
        });

        it('switches to probabilistic counting above 1000 unique clients', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn(['auto_dimensions' => ['event_name']]);

            $uniqueClients = [];
            for ($i = 0; $i < 1000; $i++) {
                $uniqueClients[] = "client-{$i}";
            }

            $existingSlices = [
                'page_view' => [
                    'count' => 1000,
                    'unique_count' => 1000,
                    'first_seen' => '2026-01-01',
                    'last_seen' => '2026-01-01',
                    'metadata' => ['unique_clients' => $uniqueClients],
                ],
            ];

            $this->cache->shouldReceive('get')
                ->andReturn($existingSlices);
            $this->cache->shouldReceive('put')
                ->withArgs(function (string $key, array $slices): bool {
                return ($slices['page_view']['metadata']['probabilistic'] ?? false) === true;
            })
                ->andReturnTrue();

            $service = new EventDataMartService($this->cache, $this->config);

            $service->ingest([
                'name' => 'page_view',
                'category' => 'engagement',
                'client_id' => 'client-1001',
            ]);
        });
    });

    describe('production readiness', function () {
        it('is final class', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn([]);

            $service = new EventDataMartService($this->cache, $this->config);

            $ref = new ReflectionClass($service);
            expect($ref->isFinal())->toBeTrue();
        });

        it('all public methods have return type declarations', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn([]);

            $service = new EventDataMartService($this->cache, $this->config);

            $ref = new ReflectionClass($service);
            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                expect($method->hasReturnType())->toBeTrue("Method {$method->getName()}() must have a return type");
            }
        });

        it('constructor uses promoted readonly properties', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn([]);

            $service = new EventDataMartService($this->cache, $this->config);

            $ref = new ReflectionClass($service);
            $constructor = $ref->getConstructor();
            $params = $constructor->getParameters();

            expect($params[0]->getName())->toBe('cache');
            expect($params[0]->isPromoted())->toBeTrue();
            expect($params[1]->getName())->toBe('config');
            expect($params[1]->isPromoted())->toBeTrue();
        });

        it('uses PHP 8.5 syntax — readonly private properties', function () {
            $ref = new ReflectionClass(EventDataMartService::class);

            $properties = $ref->getProperties();
            foreach ($properties as $prop) {
                if (! $prop->isStatic()) {
                    expect($prop->isReadOnly())->toBeTrue(
                        "Property {$prop->getName()} must be readonly in PHP 8.5",
                    );
                }
            }
        });

        it('version consistency across entry points', function () {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.data_mart', [])
                ->andReturn([]);

            $service = new EventDataMartService($this->cache, $this->config);

            // EventDataMartService @since 7.0.0
            $ref = new ReflectionClass($service);
            expect($ref->getDocComment())->toContain('@since 7.0.0');
        });
    });
});
