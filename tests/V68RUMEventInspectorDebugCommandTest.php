<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\Services\WebVitalsAggregatorService;
use ZeroBoiler\Analytics\Services\EventInspectorService;

beforeEach(function (): void {
    $this->cache = mock(CacheRepository::class);
});

describe('WebVitalsAggregatorService', function (): void {
    describe('ingest', function (): void {
        test('stores a valid LCP metric and returns stored=true', function (): void {
            $this->cache->shouldReceive('put')->once()->with(
                mockType: 'string',
                mockType: 'array',
                mockType: 'int',
            );

            $service = new WebVitalsAggregatorService(
                $this->cache,
                enabled: true,
                alertingEnabled: false,
            );

            $result = $service->ingest('LCP', 1200.5, 'good', '/dashboard', 'client-123');

            expect($result['stored'])->toBeTrue();
            expect($result['alert'])->toBeFalse();
        });

        test('rejects invalid metric names', function (): void {
            $service = new WebVitalsAggregatorService(
                $this->cache,
                enabled: true,
            );

            $result = $service->ingest('INVALID_METRIC', 100.0);

            expect($result['stored'])->toBeFalse();
            expect($result['alert'])->toBeFalse();
        });

        test('returns stored=false when disabled', function (): void {
            $service = new WebVitalsAggregatorService(
                $this->cache,
                enabled: false,
            );

            $result = $service->ingest('LCP', 1200.0);

            expect($result['stored'])->toBeFalse();
        });

        test('normalizes metric name case-insensitively', function (): void {
            $this->cache->shouldReceive('put')->once();

            $service = new WebVitalsAggregatorService(
                $this->cache,
                enabled: true,
                alertingEnabled: false,
            );

            $result = $service->ingest('cls', 0.05);

            expect($result['stored'])->toBeTrue();
        });

        test('computes rating when not provided', function (): void {
            $this->cache->shouldReceive('put')->once();

            $service = new WebVitalsAggregatorService(
                $this->cache,
                enabled: true,
                alertingEnabled: false,
            );

            $result = $service->ingest('INP', 50.0);

            expect($result['stored'])->toBeTrue();
        });

        test('detects poor ratings as alerts when alerting enabled', function (): void {
            $this->cache->shouldReceive('put')->once();

            $service = new WebVitalsAggregatorService(
                $this->cache,
                enabled: true,
                alertingEnabled: true,
            );

            $result = $service->ingest('LCP', 5000.0, null, '/slow-page');

            expect($result['stored'])->toBeTrue();
            expect($result['alert'])->toBeTrue();
            expect($result['alert_reason'])->not->toBeNull();
            expect(str_contains($result['alert_reason'], 'LCP'))->toBeTrue();
        });
    });

    describe('ingestBatch', function (): void {
        test('processes multiple metrics and counts stored/alerts', function (): void {
            $this->cache->shouldReceive('put')->times(3);

            $service = new WebVitalsAggregatorService(
                $this->cache,
                enabled: true,
                alertingEnabled: false,
            );

            $result = $service->ingestBatch([
                ['metric' => 'LCP', 'value' => 1200],
                ['metric' => 'CLS', 'value' => 0.05],
                ['metric' => 'INP', 'value' => 100],
            ]);

            expect($result['stored'])->toBe(3);
            expect($result['alerts'])->toBe(0);
            expect(count($result['results']))->toBe(3);
        });

        test('handles empty batch', function (): void {
            $service = new WebVitalsAggregatorService(
                $this->cache,
                enabled: true,
            );

            $result = $service->ingestBatch([]);

            expect($result['stored'])->toBe(0);
            expect($result['alerts'])->toBe(0);
            expect($result['results'])->toBe([]);
        });
    });

    describe('computeRating', function (): void {
        test('classifies LCP good at 2000ms', function (): void {
            $service = new WebVitalsAggregatorService($this->cache);

            expect($service->computeRating('LCP', 2000))->toBe('good');
        });

        test('classifies LCP needs-improvement at 3000ms', function (): void {
            $service = new WebVitalsAggregatorService($this->cache);

            expect($service->computeRating('LCP', 3000))->toBe('needs-improvement');
        });

        test('classifies LCP poor at 5000ms', function (): void {
            $service = new WebVitalsAggregatorService($this->cache);

            expect($service->computeRating('LCP', 5000))->toBe('poor');
        });

        test('classifies CLS good at 0.05', function (): void {
            $service = new WebVitalsAggregatorService($this->cache);

            expect($service->computeRating('CLS', 0.05))->toBe('good');
        });

        test('classifies CLS poor at 0.5', function (): void {
            $service = new WebVitalsAggregatorService($this->cache);

            expect($service->computeRating('CLS', 0.5))->toBe('poor');
        });

        test('returns good for unknown metrics', function (): void {
            $service = new WebVitalsAggregatorService($this->cache);

            expect($service->computeRating('UNKNOWN', 9999))->toBe('good');
        });
    });

    describe('percentileStats', function (): void {
        test('returns empty stats when no data', function (): void {
            $this->cache->shouldReceive('get')->once()->andReturn([]);

            $service = new WebVitalsAggregatorService($this->cache);

            $stats = $service->percentileStats('LCP');

            expect($stats['count'])->toBe(0);
            expect($stats['p75'])->toBeNull();
            expect($stats['metric'])->toBe('LCP');
        });
    });

    describe('dashboardSummary', function (): void {
        test('returns summary with all metrics', function (): void {
            $this->cache->shouldReceive('get')->times(6)->andReturn([]);

            $service = new WebVitalsAggregatorService(
                $this->cache,
                enabled: true,
            );

            $summary = $service->dashboardSummary();

            expect($summary['window'])->toBe('24h');
            expect(isset($summary['metrics']['LCP']))->toBeTrue();
            expect(isset($summary['metrics']['CLS']))->toBeTrue();
            expect(isset($summary['metrics']['INP']))->toBeTrue();
            expect(isset($summary['metrics']['FID']))->toBeTrue();
            expect(isset($summary['metrics']['TTFB']))->toBeTrue();
            expect(isset($summary['metrics']['FCP']))->toBeTrue();
            expect($summary['overall_score'])->toBe(0.0);
        });
    });

    describe('coreWebVitalsAssessment', function (): void {
        test('returns assessment structure with lcp, cls, inp, overall_pass', function (): void {
            $this->cache->shouldReceive('get')->times(3)->andReturn([]);

            $service = new WebVitalsAggregatorService($this->cache);

            $assessment = $service->coreWebVitalsAssessment();

            expect(isset($assessment['lcp']))->toBeTrue();
            expect(isset($assessment['cls']))->toBeTrue();
            expect(isset($assessment['inp']))->toBeTrue();
            expect(isset($assessment['overall_pass']))->toBeTrue();
        });
    });

    describe('constants', function (): void {
        test('has all 6 Core Web Vitals metrics', function (): void {
            expect(WebVitalsAggregatorService::ALL_METRICS)->toHaveCount(6);
            expect(WebVitalsAggregatorService::ALL_METRICS)->toContain('LCP');
            expect(WebVitalsAggregatorService::ALL_METRICS)->toContain('FID');
            expect(WebVitalsAggregatorService::ALL_METRICS)->toContain('CLS');
            expect(WebVitalsAggregatorService::ALL_METRICS)->toContain('INP');
            expect(WebVitalsAggregatorService::ALL_METRICS)->toContain('TTFB');
            expect(WebVitalsAggregatorService::ALL_METRICS)->toContain('FCP');
        });

        test('thresholds contain all metrics with good and poor values', function (): void {
            foreach (WebVitalsAggregatorService::ALL_METRICS as $metric) {
                expect(isset(WebVitalsAggregatorService::THRESHOLDS[$metric]))->toBeTrue();
                expect(isset(WebVitalsAggregatorService::THRESHOLDS[$metric]['good']))->toBeTrue();
                expect(isset(WebVitalsAggregatorService::THRESHOLDS[$metric]['poor']))->toBeTrue();
            }
        });

        test('good threshold is always less than poor threshold', function (): void {
            foreach (WebVitalsAggregatorService::THRESHOLDS as $metric => $thresholds) {
                expect($thresholds['good'])->toBeLessThan($thresholds['poor']);
            }
        });
    });

    describe('isEnabled', function (): void {
        test('reflects enabled state', function (): void {
            $enabled = new WebVitalsAggregatorService($this->cache, enabled: true);
            $disabled = new WebVitalsAggregatorService($this->cache, enabled: false);

            expect($enabled->isEnabled())->toBeTrue();
            expect($disabled->isEnabled())->toBeFalse();
        });
    });
});

describe('EventInspectorService', function (): void {
    describe('recordTrace', function (): void {
        test('returns false when disabled', function (): void {
            $service = new EventInspectorService(
                $this->cache,
                enabled: false,
            );

            $result = $service->recordTrace('evt-1', 'test_event', 'dispatch');

            expect($result)->toBeFalse();
        });

        test('stores trace when enabled', function (): void {
            $this->cache->shouldReceive('get')->once()->andReturn([]);
            $this->cache->shouldReceive('put')->twice();

            $service = new EventInspectorService(
                $this->cache,
                enabled: true,
            );

            $result = $service->recordTrace(
                'evt-abc',
                'page_view',
                'dispatch',
                ['provider' => 'ga4'],
                12.5,
            );

            expect($result)->toBeTrue();
        });
    });

    describe('getTrace', function (): void {
        test('returns trace structure', function (): void {
            $this->cache->shouldReceive('get')->once()->andReturn([
                [
                    'id' => 'evt-1',
                    'event' => 'test',
                    'stage' => 'dispatch',
                    'context' => [],
                    'duration_ms' => 5.0,
                    'timestamp' => '2025-01-01T00:00:00+00:00',
                    'microtime' => 1704067200.0,
                ],
                [
                    'id' => 'evt-1',
                    'event' => 'test',
                    'stage' => 'complete',
                    'context' => [],
                    'duration_ms' => 2.0,
                    'timestamp' => '2025-01-01T00:00:00+00:00',
                    'microtime' => 1704067200.008,
                ],
            ]);

            $service = new EventInspectorService($this->cache);
            $trace = $service->getTrace('evt-1');

            expect($trace['event_id'])->toBe('evt-1');
            expect($trace['stage_count'])->toBe(2);
            expect($trace['has_errors'])->toBeFalse();
            expect($trace['total_duration_ms'])->not->toBeNull();
        });

        test('returns empty trace for unknown event', function (): void {
            $this->cache->shouldReceive('get')->once()->andReturn([]);

            $service = new EventInspectorService($this->cache);
            $trace = $service->getTrace('unknown');

            expect($trace['stage_count'])->toBe(0);
            expect($trace['has_errors'])->toBeFalse();
        });
    });

    describe('recentEvents', function (): void {
        test('returns empty list when no traces', function (): void {
            $this->cache->shouldReceive('get')->once()->andReturn([]);

            $service = new EventInspectorService($this->cache);
            $events = $service->recentEvents();

            expect($events)->toBe([]);
        });
    });

    describe('summary', function (): void {
        test('returns summary structure', function (): void {
            $this->cache->shouldReceive('get')->once()->andReturn([]);

            $service = new EventInspectorService(
                $this->cache,
                enabled: true,
            );

            $summary = $service->summary();

            expect($summary['enabled'])->toBeTrue();
            expect($summary['total_traced'])->toBe(0);
            expect(is_list($summary['stages_available']))->toBeTrue();
            expect($summary['stages_available'])->toContain('dispatch');
            expect($summary['stages_available'])->toContain('complete');
            expect($summary['stages_available'])->toContain('error');
        });
    });

    describe('enable/disable', function (): void {
        test('toggles enabled state', function (): void {
            $service = new EventInspectorService(
                $this->cache,
                enabled: false,
            );

            expect($service->isEnabled())->toBeFalse();

            $service->enable();
            expect($service->isEnabled())->toBeTrue();

            $service->disable();
            expect($service->isEnabled())->toBeFalse();
        });
    });
});

describe('AnalyticsConfig RUM & Inspector accessors', function (): void {
    test('rum accessors return expected defaults', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.rum.enabled', false)
            ->andReturn(true);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.rum.max_samples', 10000)
            ->andReturn(5000);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.rum.ttl', 86400)
            ->andReturn(43200);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.rum.window', '24h')
            ->andReturn('1h');
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.rum.alerting_enabled', true)
            ->andReturn(false);

        $analyticsConfig = new \ZeroBoiler\Analytics\Support\AnalyticsConfig($config);

        expect($analyticsConfig->rumEnabled())->toBeTrue();
        expect($analyticsConfig->rumMaxSamples())->toBe(5000);
        expect($analyticsConfig->rumTtl())->toBe(43200);
        expect($analyticsConfig->rumWindow())->toBe('1h');
        expect($analyticsConfig->rumAlertingEnabled())->toBeFalse();
    });

    test('inspector accessors return expected defaults', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.inspector.enabled', false)
            ->andReturn(true);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.inspector.max_traces', 500)
            ->andReturn(200);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.inspector.ttl', 300)
            ->andReturn(600);

        $analyticsConfig = new \ZeroBoiler\Analytics\Support\AnalyticsConfig($config);

        expect($analyticsConfig->inspectorEnabled())->toBeTrue();
        expect($analyticsConfig->inspectorMaxTraces())->toBe(200);
        expect($analyticsConfig->inspectorTtl())->toBe(600);
    });
});
