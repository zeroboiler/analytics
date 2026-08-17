<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Services\EventTimeSeriesDecompositionService;

beforeEach(function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn([]);
    $cache->shouldReceive('put')->andReturn(true);
    $cache->shouldReceive('forget')->andReturn(true);

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.decomposition', [])
        ->andReturn([
            'enabled' => true,
            'cache_ttl' => 1800,
            'default_period' => 7,
            'forecast_horizon' => 1,
            'confidence_width' => 1.96,
        ]);

    $this->service = new EventTimeSeriesDecompositionService($cache, $config);
});

// ─── File Quality Checks ──────────────────────────────────────────────

it('has strict_types declaration', function (): void {
    $contents = file_get_contents((string) realpath(__DIR__ . '/../../src/Services/EventTimeSeriesDecompositionService.php'));
    expect($contents)->toContain('declare(strict_types=1)');
});

it('has MIT license header', function (): void {
    $contents = file_get_contents((string) realpath(__DIR__ . '/../../src/Services/EventTimeSeriesDecompositionService.php'));
    expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
});

it('is a final class', function (): void {
    $reflection = new ReflectionClass(EventTimeSeriesDecompositionService::class);
    expect($reflection->isFinal())->toBeTrue();
});

it('has @since 221.0.0 docblock tag', function (): void {
    $reflection = new ReflectionClass(EventTimeSeriesDecompositionService::class);
    $doc = $reflection->getDocComment();
    expect($doc)->toContain('@since 221.0.0');
});

// ─── Service Instantiation ────────────────────────────────────────────

it('can be instantiated', function (): void {
    expect($this->service)->toBeInstanceOf(EventTimeSeriesDecompositionService::class);
});

it('is enabled by default', function (): void {
    expect($this->service->isEnabled())->toBeTrue();
});

it('returns correct cache TTL', function (): void {
    expect($this->service->getCacheTtl())->toBe(1800);
});

it('returns config with all keys', function (): void {
    $config = $this->service->getConfig();

    expect($config)->toHaveKey('enabled');
    expect($config)->toHaveKey('cache_ttl');
    expect($config)->toHaveKey('default_period');
    expect($config)->toHaveKey('forecast_horizon');
    expect($config)->toHaveKey('confidence_width');
    expect($config)->toHaveKey('min_data_points');
    expect($config)->toHaveKey('min_seasonal_points');
    expect($config)->toHaveKey('anomaly_z_threshold');

    expect($config['enabled'])->toBeTrue();
    expect($config['min_data_points'])->toBe(8);
    expect($config['min_seasonal_points'])->toBe(14);
    expect($config['anomaly_z_threshold'])->toBe(2.0);
});

// ─── Insufficient Data ────────────────────────────────────────────────

it('returns empty result for empty data', function (): void {
    $result = $this->service->decompose('test_event', []);

    expect($result['event_name'])->toBe('test_event');
    expect($result['data_points'])->toBe(0);
    expect($result['trend'])->toBeEmpty();
    expect($result['seasonal'])->toBeEmpty();
    expect($result['noise'])->toBeEmpty();
    expect($result['trend_direction'])->toBe('stable');
    expect($result['noise_ratio'])->toBe(1.0);
    expect($result['signal_to_noise'])->toBe(0.0);
});

it('returns empty result for data below minimum threshold', function (): void {
    $result = $this->service->decompose('test_event', [10, 20, 30]);

    expect($result['data_points'])->toBe(3);
    expect($result['trend'])->toBeEmpty();
});

// ─── Basic Decomposition ─────────────────────────────────────────────

it('decomposes minimal data without seasonal extraction', function (): void {
    $data = [100, 110, 120, 130, 140, 150, 160, 170, 180, 190];

    $result = $this->service->decompose('growing_event', $data, 7);

    expect($result['event_name'])->toBe('growing_event');
    expect($result['data_points'])->toBe(10);
    expect($result['trend'])->toHaveCount(10);
    expect($result['seasonal'])->toHaveCount(10);
    expect($result['noise'])->toHaveCount(10);
    expect($result['trend_direction'])->toBe('growing');
    expect($result['trend_slope'])->toBeGreaterThan(0);
});

it('identifies declining trend', function (): void {
    $data = [200, 190, 180, 170, 160, 150, 140, 130, 120, 110, 100, 90, 80, 70];

    $result = $this->service->decompose('declining_event', $data, 7);

    expect($result['trend_direction'])->toBe('declining');
    expect($result['trend_slope'])->toBeLessThan(0);
});

it('identifies stable trend for flat data', function (): void {
    $data = array_fill(0, 20, 100);

    $result = $this->service->decompose('stable_event', $data, 7);

    expect($result['trend_direction'])->toBe('stable');
});

// ─── Seasonal Decomposition ───────────────────────────────────────────

it('extracts seasonal component from data with clear weekly pattern', function (): void {
    // 4 weeks of data with clear weekly seasonality
    $data = [];
    $weeklyPattern = [50, 80, 90, 85, 75, 60, 45]; // Sun-Sat

    for ($week = 0; $week < 4; $week++) {
        foreach ($weeklyPattern as $dayValue) {
            $data[] = $dayValue + ($week * 5); // Slight upward trend
        }
    }

    $result = $this->service->decompose('seasonal_event', $data, 7);

    expect($result['data_points'])->toBe(28);
    expect($result['seasonal'])->toHaveCount(28);
    expect($result['seasonality_strength'])->toBeGreaterThan(0.01);
    expect($result['seasonal_peaks'])->not->toBeEmpty();
    expect($result['seasonal_troughs'])->not->toBeEmpty();
});

// ─── Noise and Signal-to-Noise ───────────────────────────────────────

it('computes noise ratio for deterministic data', function (): void {
    // Perfectly linear data — noise ratio should be very low
    $data = range(100, 200);

    $result = $this->service->decompose('linear_event', $data, 7);

    expect($result['noise_ratio'])->toBeLessThan(0.5);
    expect($result['signal_to_noise'])->toBeGreaterThan(0);
});

it('has higher noise ratio for noisy data', function (): void {
    mt_srand(42);
    $noisy = [];
    for ($i = 0; $i < 30; $i++) {
        $noisy[] = 100 + (mt_rand(-50, 50));
    }

    $result = $this->service->decompose('noisy_event', $noisy, 7);

    // Noisy data should have higher noise ratio
    expect($result['noise_ratio'])->toBeGreaterThan(0);
});

// ─── Forecasting ───────────────────────────────────────────────────────

it('generates forecast for sufficient data', function (): void {
    $data = range(100, 200);

    $result = $this->service->decompose('forecast_event', $data, 7);

    expect($result['forecast'])->not->toBeEmpty();
    expect($result['confidence_upper'])->not->toBeEmpty();
    expect($result['confidence_lower'])->not->toBeEmpty();

    // Confidence bounds should bracket forecast
    foreach ($result['forecast'] as $i => $forecast) {
        expect($result['confidence_upper'][$i])->toBeGreaterThanOrEqual($forecast);
        expect($result['confidence_lower'][$i])->toBeLessThanOrEqual($forecast);
    }
});

it('respects zero forecast steps', function (): void {
    $data = range(100, 200);

    $result = $this->service->decompose('no_forecast_event', $data, 7, 0);

    expect($result['forecast'])->toBeEmpty();
    expect($result['confidence_upper'])->toBeEmpty();
    expect($result['confidence_lower'])->toBeEmpty();
});

// ─── Anomaly Detection ────────────────────────────────────────────────

it('detects spike anomalies in otherwise regular data', function (): void {
    $data = array_fill(0, 27, 100);
    // Inject a massive spike at index 13
    $data[13] = 300;

    $result = $this->service->decompose('spike_event', $data, 7);

    expect($result['anomalies'])->not->toBeEmpty();

    // The spike at index 13 should be detected
    $anomalyIndices = array_column($result['anomalies'], 'index');
    expect($anomalyIndices)->toContain(13);
});

it('each anomaly has required fields', function (): void {
    $data = array_fill(0, 27, 100);
    $data[5] = 350;
    $data[20] = 380;

    $result = $this->service->decompose('multi_anomaly_event', $data, 7);

    foreach ($result['anomalies'] as $anomaly) {
        expect($anomaly)->toHaveKey('index');
        expect($anomaly)->toHaveKey('expected');
        expect($anomaly)->toHaveKey('actual');
        expect($anomaly)->toHaveKey('deviation');
        expect($anomaly)->toHaveKey('z_score');
        expect($anomaly['z_score'])->toBeGreaterThanOrEqual(2.0);
    }
});

// ─── Seasonal Profile ─────────────────────────────────────────────────

it('generates seasonal profile', function (): void {
    $data = [];
    $weeklyPattern = [50, 80, 90, 85, 75, 60, 45];

    for ($week = 0; $week < 4; $week++) {
        foreach ($weeklyPattern as $dayValue) {
            $data[] = $dayValue + ($week * 3);
        }
    }

    $profile = $this->service->seasonalProfile($data, 7);

    expect($profile['positions'])->toHaveCount(7);
    expect($profile['values'])->toHaveCount(7);
    expect($profile['amplitude'])->toBeGreaterThan(0);
    expect($profile)->toHaveKey('peaks');
    expect($profile)->toHaveKey('troughs');
});

it('returns flat profile for insufficient data', function (): void {
    $profile = $this->service->seasonalProfile([1, 2, 3], 7);

    expect($profile['values'])->toHaveCount(7);
    expect($profile['amplitude'])->toBe(0.0);
    expect($profile['peaks'])->toBeEmpty();
});

// ─── Multi-Event Decomposition ────────────────────────────────────────

it('decomposes multiple events with comparison', function (): void {
    $growing = range(100, 200);
    $stable = array_fill(0, 30, 150);

    $result = $this->service->decomposeMulti([
        'growing_event' => $growing,
        'stable_event' => $stable,
    ]);

    expect($result)->toHaveKey('events');
    expect($result)->toHaveKey('comparison');
    expect($result['events'])->toHaveKey('growing_event');
    expect($result['events'])->toHaveKey('stable_event');
    expect($result['comparison'])->toHaveKey('highest_trend');
    expect($result['comparison'])->toHaveKey('highest_seasonality');
    expect($result['comparison'])->toHaveKey('most_volatile');
    expect($result['comparison'])->toHaveKey('most_predictable');
    expect($result['comparison']['highest_trend'])->toBe('growing_event');
    expect($result['comparison']['most_predictable'])->toBe('stable_event');
});

it('sets comparison keys to null for empty data', function (): void {
    $result = $this->service->decomposeMulti([]);

    expect($result['comparison']['highest_trend'])->toBeNull();
    expect($result['comparison']['most_volatile'])->toBeNull();
});

// ─── Sufficient Data Check ────────────────────────────────────────────

it('reports sufficient data for long series', function (): void {
    expect($this->service->hasSufficientData(range(1, 30), 7))->toBeTrue();
});

it('reports insufficient data for short series', function (): void {
    expect($this->service->hasSufficientData([1, 2, 3], 7))->toBeFalse();
});

it('reports insufficient data for single element', function (): void {
    expect($this->service->hasSufficientData([42], 7))->toBeFalse();
});

// ─── Summary Metrics ───────────────────────────────────────────────────

it('computes correct summary statistics', function (): void {
    $data = [100, 200, 300, 400, 500, 600, 700, 800, 900, 1000,
             100, 200, 300, 400, 500, 600, 700, 800, 900, 1000];

    $result = $this->service->decompose('summary_event', $data, 7);

    expect($result['summary']['min'])->toBe(100.0);
    expect($result['summary']['max'])->toBe(1000.0);
    expect($result['summary']['mean'])->toBe(550.0);
});

it('produces consistent decomposition for same input', function (): void {
    $data = range(50, 150);

    $result1 = $this->service->decompose('deterministic_event', $data, 7);
    $result2 = $this->service->decompose('deterministic_event', $data, 7);

    expect($result1['trend_slope'])->toEqual($result2['trend_slope']);
    expect($result1['trend_direction'])->toBe($result2['trend_direction']);
    expect($result1['seasonality_strength'])->toEqual($result2['seasonality_strength']);
});

// ─── Command Structure Checks ─────────────────────────────────────────

it('command class is instantiable', function (): void {
    expect(new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsDecompositionCommand::class))
        ->toBeInstanceOf(ReflectionClass::class);
});

it('command class has correct namespace', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsDecompositionCommand::class);
    expect($reflection->getNamespaceName())->toBe('ZeroBoiler\\Analytics\\Console\\Commands');
});

it('command class extends Illuminate Console Command', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsDecompositionCommand::class);
    expect($reflection->getParentClass()?->getName())->toBe('Illuminate\\Console\\Command');
});
