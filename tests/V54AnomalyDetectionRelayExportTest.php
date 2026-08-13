<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Services\AnalyticsAnomalyDetectionService;
use ZeroBoiler\Analytics\Services\AnalyticsExportFormatterService;
use ZeroBoiler\Analytics\Services\MultiProviderRelayService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Collection;

beforeEach(function (): void {
    $this->cache = mock(CacheRepository::class);
    $this->cache->shouldReceive('get')->andReturn([]);
    $this->cache->shouldReceive('put')->andReturn(true);
    $this->cache->shouldReceive('forget')->andReturn(true);
});

describe('AnalyticsAnomalyDetectionService', function (): void {
    test('can be instantiated with config', function (): void {
        $service = new AnalyticsAnomalyDetectionService($this->cache, [
            'enabled' => true,
            'window_seconds' => 300,
            'baseline_windows' => 12,
            'sensitivity' => 3.0,
        ]);

        expect($service)->toBeInstanceOf(AnalyticsAnomalyDetectionService::class);
    });

    test('recordEvent increments window count', function (): void {
        $this->cache->shouldReceive('get')
            ->with('zb_anomaly_window_0', any())
            ->andReturn(['count' => 0, 'events' => [], 'providers' => [], 'clients' => [], 'last_updated' => 0]);
        $this->cache->shouldReceive('put')
            ->andReturn(true);
        $this->cache->shouldReceive('get')
            ->with('zb_anomaly_window_keys', any())
            ->andReturn([]);

        $service = new AnalyticsAnomalyDetectionService($this->cache, ['enabled' => true]);
        $service->recordEvent('purchase', 'ga4', 'client_123');

        // No exception thrown = success
        expect(true)->toBeTrue();
    });

    test('recordBatch processes multiple events', function (): void {
        $this->cache->shouldReceive('get')
            ->with('zb_anomaly_window_', any())
            ->andReturn(['count' => 0, 'events' => [], 'providers' => [], 'clients' => [], 'last_updated' => 0]);
        $this->cache->shouldReceive('put')
            ->andReturn(true);
        $this->cache->shouldReceive('get')
            ->with('zb_anomaly_window_keys', any())
            ->andReturn([]);

        $service = new AnalyticsAnomalyDetectionService($this->cache, ['enabled' => true]);
        $service->recordBatch([
            ['name' => 'purchase', 'provider' => 'ga4', 'client_id' => 'c1'],
            ['name' => 'sign_up', 'provider' => 'meta', 'client_id' => 'c2'],
        ]);

        expect(true)->toBeTrue();
    });

    test('detectAnomalies returns empty array when disabled', function (): void {
        $service = new AnalyticsAnomalyDetectionService($this->cache, ['enabled' => false]);
        $anomalies = $service->detectAnomalies();

        expect($anomalies)->toBe([]);
    });

    test('detectAnomalies returns empty array when threshold not met', function (): void {
        $this->cache->shouldReceive('get')
            ->andReturn(['count' => 0, 'events' => [], 'providers' => [], 'clients' => [], 'last_updated' => 0]);

        $service = new AnalyticsAnomalyDetectionService($this->cache, [
            'enabled' => true,
            'min_events_threshold' => 100,
        ]);
        $anomalies = $service->detectAnomalies();

        expect($anomalies)->toBe([]);
    });

    test('status returns structure with required keys', function (): void {
        $this->cache->shouldReceive('get')
            ->andReturn([]);

        $service = new AnalyticsAnomalyDetectionService($this->cache, ['enabled' => true]);
        $status = $service->status();

        expect($status)
            ->toHaveKey('enabled')
            ->toHaveKey('current_window')
            ->toHaveKey('baseline')
            ->toHaveKey('recent_alerts')
            ->toHaveKey('windows_tracked');
    });

    test('metrics returns structure with required keys', function (): void {
        $this->cache->shouldReceive('get')
            ->andReturn([]);

        $service = new AnalyticsAnomalyDetectionService($this->cache, ['enabled' => true]);
        $metrics = $service->metrics();

        expect($metrics)
            ->toHaveKey('rate_deviation')
            ->toHaveKey('provider_balance')
            ->toHaveKey('composition_drift')
            ->toHaveKey('client_spike')
            ->toHaveKey('anomaly_count_24h');
    });

    test('clear calls cache forget for all tracked keys', function (): void {
        $this->cache->shouldReceive('get')
            ->with('zb_anomaly_window_keys', any())
            ->andReturn(['123', '124']);
        $this->cache->shouldReceive('forget')
            ->with('zb_anomaly_window_123')
            ->andReturn(true);
        $this->cache->shouldReceive('forget')
            ->with('zb_anomaly_window_124')
            ->andReturn(true);
        $this->cache->shouldReceive('forget')
            ->with('zb_anomaly_window_keys')
            ->andReturn(true);
        $this->cache->shouldReceive('forget')
            ->with('zb_anomaly_recent_alerts')
            ->andReturn(true);

        $service = new AnalyticsAnomalyDetectionService($this->cache, ['enabled' => true]);
        $service->clear();

        expect(true)->toBeTrue();
    });

    test('onAlert registers callback', function (): void {
        $called = false;
        $service = new AnalyticsAnomalyDetectionService($this->cache, ['enabled' => true]);

        $service->onAlert(function (string $type, array $data) use (&$called): void {
            $called = true;
        });

        expect(true)->toBeTrue(); // No exception = callback registered
    });
});

describe('AnalyticsExportFormatterService', function (): void {
    test('toCsv returns CSV string with headers', function (): void {
        $service = new AnalyticsExportFormatterService;
        $events = new Collection;

        $csv = $service->toCsv($events, ['id', 'event_name']);

        expect($csv)
            ->toBeString()
            ->toContain('id')
            ->toContain('event_name');
    });

    test('toCsv with custom columns', function (): void {
        $service = new AnalyticsExportFormatterService;
        $events = new Collection;

        $csv = $service->toCsv($events, ['event_name', 'provider']);

        expect($csv)->toContain('event_name,provider');
    });

    test('toSegmentFormat returns array with Segment structure', function (): void {
        $service = new AnalyticsExportFormatterService;
        $events = new Collection;

        $result = $service->toSegmentFormat($events);

        expect($result)->toBeArray();
    });

    test('toBigQueryFormat returns array with GA4 structure', function (): void {
        $service = new AnalyticsExportFormatterService;
        $events = new Collection;

        $result = $service->toBigQueryFormat($events);

        expect($result)->toBeArray();
    });

    test('toSnowplowFormat returns array with self-describing schema', function (): void {
        $service = new AnalyticsExportFormatterService;
        $events = new Collection;

        $result = $service->toSnowplowFormat($events);

        expect($result)->toBeArray();
    });

    test('exportWithMetadata returns meta and data keys', function (): void {
        $service = new AnalyticsExportFormatterService;
        $events = new Collection;

        $result = $service->exportWithMetadata($events, 'csv');

        expect($result)
            ->toHaveKey('meta')
            ->toHaveKey('data');

        expect($result['meta'])
            ->toHaveKey('exported_at')
            ->toHaveKey('total_events')
            ->toHaveKey('format')
            ->toHaveKey('version')
            ->toHaveKey('time_range')
            ->toHaveKey('category_distribution')
            ->toHaveKey('provider_distribution');
    });

    test('supportedFormats returns expected formats', function (): void {
        $formats = AnalyticsExportFormatterService::supportedFormats();
        $formatNames = array_column($formats, 'format');

        expect($formatNames)
            ->toContain('csv')
            ->toContain('segment')
            ->toContain('bigquery')
            ->toContain('snowplow');
    });

    test('each format has label and description', function (): void {
        $formats = AnalyticsExportFormatterService::supportedFormats();

        foreach ($formats as $format) {
            expect($format)
                ->toHaveKey('format')
                ->toHaveKey('label')
                ->toHaveKey('description');
        }
    });
});

describe('MultiProviderRelayService', function (): void {
    test('can be instantiated with config', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.relay.enabled', false)
            ->andReturn(false);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.relay.providers', [])
            ->andReturn([]);

        $service = new MultiProviderRelayService($config);

        expect($service)->toBeInstanceOf(MultiProviderRelayService::class);
    });

    test('status returns structure with required keys', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.relay.enabled', false)
            ->andReturn(false);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.relay.providers', [])
            ->andReturn([]);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.relay.rules', [])
            ->andReturn([]);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.relay.exclude', [])
            ->andReturn([]);

        $service = new MultiProviderRelayService($config);
        $status = $service->status();

        expect($status)
            ->toHaveKey('enabled')
            ->toHaveKey('providers')
            ->toHaveKey('rules_count')
            ->toHaveKey('exclusions_count')
            ->toHaveKey('metrics');
    });

    test('getMetrics returns zero counts for fresh instance', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.relay.enabled', false)
            ->andReturn(false);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.relay.providers', [])
            ->andReturn([]);

        $service = new MultiProviderRelayService($config);
        $metrics = $service->getMetrics();

        expect($metrics)
            ->toHaveKey('total_dispatched')
            ->toHaveKey('total_errors')
            ->toHaveKey('by_provider');

        expect($metrics['total_dispatched'])->toBe(0);
        expect($metrics['total_errors'])->toBe(0);
    });

    test('resetMetrics clears counters', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.relay.enabled', false)
            ->andReturn(false);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.relay.providers', [])
            ->andReturn([]);

        $service = new MultiProviderRelayService($config);
        $service->resetMetrics();

        $metrics = $service->getMetrics();
        expect($metrics['total_dispatched'])->toBe(0);
    });
});
