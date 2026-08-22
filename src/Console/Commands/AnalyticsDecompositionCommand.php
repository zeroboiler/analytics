<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\EventTimeSeriesDecompositionService;

/**
 * Analytics Time-Series Decomposition command.
 *
 * Decomposes event volume time-series into trend, seasonality, and noise
 * components to identify genuine growth patterns vs. seasonal fluctuations.
 *
 * @see \ZeroBoiler\Analytics\Services\EventTimeSeriesDecompositionService
 *
 * @since 221.0.0
 */
final class AnalyticsDecompositionCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:decomposition
        {--event= : Specific event name to decompose}
        {--period=7 : Seasonality period (e.g. 7 for weekly, 24 for hourly)}
        {--json : Output as JSON}
        {--profile : Show seasonal profile instead of full decomposition}
        {--no-forecast : Skip forecast generation}
        {--compare : Compare multiple events (requires event names as arguments)}';

    /** @var string */
    protected $description = 'Decompose event time-series into trend, seasonality, and noise components';

    private EventTimeSeriesDecompositionService $service;

    public function __construct(EventTimeSeriesDecompositionService $service){
        parent::__construct();
        $this->service = $service;
    }

    public function handle(): int
    {
        if (! $this->service->isEnabled()) {
            $this->warn('Event time-series decomposition is disabled in config.');

            return self::SUCCESS;
        }

        $asJson = (bool) $this->option('json');
        $eventName = (string) $this->option('event');
        $period = (int) $this->option('period');

        // Seasonal profile (check before --event so --event --profile works together)
        if ($this->option('profile')) {
            if ($eventName === '') {
                $this->error('Please specify an event name with --event=<name>');

                return self::FAILURE;
            }

            return $this->showSeasonalProfile($eventName, $period, $asJson);
        }

        // Single event decomposition
        if ($eventName !== '') {
            return $this->decomposeSingleEvent($eventName, $period, $asJson);
        }

        // Config overview (default when no event specified)
        return $this->showConfigOverview($asJson);
    }

    /**
     * Show configuration overview and diagnostic info.
     */
    private function showConfigOverview(bool $asJson): int
    {
        $config = $this->service->getConfig();

        if ($asJson) {
            $this->output->write(json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('⚙️  Time-Series Decomposition Engine');
        $this->newLine();
        $this->table(
            ['Setting', 'Value'],
            [
                ['Enabled', $config['enabled'] ? 'Yes' : 'No'],
                ['Cache TTL', $config['cache_ttl'] . ' seconds'],
                ['Default Period', $config['default_period']],
                ['Forecast Horizon', $config['forecast_horizon'] . '× period'],
                ['Confidence Width', $config['confidence_width'] . 'σ'],
                ['Min Data Points', $config['min_data_points']],
                ['Min Seasonal Points', $config['min_seasonal_points']],
                ['Anomaly Z-Threshold', $config['anomaly_z_threshold']],
            ],
        );

        $this->newLine();
        $this->info('Usage examples:');
        $this->line('  php artisan zb:analytics:decomposition --event=page_view');
        $this->line('  php artisan zb:analytics:decomposition --event=page_view --period=7');
        $this->line('  php artisan zb:analytics:decomposition --event=page_view --json');
        $this->line('  php artisan zb:analytics:decomposition --event=page_view --profile');
        $this->line('  php artisan zb:analytics:decomposition --event=page_view --no-forecast');

        return self::SUCCESS;
    }

    /**
     * Decompose a single event.
     */
    private function decomposeSingleEvent(string $eventName, int $period, bool $asJson): int
    {
        if ($eventName === '') {
            $this->error('Please specify an event name with --event=<name>');

            return self::FAILURE;
        }

        if ($asJson) {
            // Generate sample data for demonstration
            $data = $this->generateSampleData($eventName);

            if ($data === []) {
                $this->error("No data available for event: {$eventName}");
                $this->line('In production, data comes from AnalyticsEventModel.');

                return self::FAILURE;
            }

            $result = $this->service->decompose($eventName, $data, $period);
            $this->output->write(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $data = $this->generateSampleData($eventName);

        if ($data === []) {
            $this->warn("No data available for event: {$eventName}");
            $this->line('Showing engine info:');

            return $this->showConfigOverview(false);
        }

        $this->info("📊 Decomposing: {$eventName}");
        $this->line('   Data points: ' . count($data));
        $this->line('   Period: ' . $period);
        $this->newLine();

        $result = $this->service->decompose(
            $eventName,
            $data,
            $period,
            (bool) $this->option('no-forecast') ? 0 : null,
        );

        // Summary
        $summary = $result['summary'];
        $this->table(
            ['Metric', 'Value'],
            [
                ['Mean Volume', number_format($summary['mean'], 2)],
                ['Std Deviation', number_format($summary['std_dev'], 2)],
                ['Min', number_format($summary['min'], 2)],
                ['Max', number_format($summary['max'], 2)],
                ['Trend Direction', $result['trend_direction']],
                ['Trend Slope', number_format($result['trend_slope'], 6)],
                ['Trend % Change', $summary['trend_pct_change'] . '%'],
                ['Seasonality Strength', number_format($result['seasonality_strength'], 4)],
                ['Seasonal Amplitude', number_format($summary['seasonal_amplitude'], 4)],
                ['Noise Ratio', number_format($result['noise_ratio'], 4)],
                ['Signal/Noise', number_format($result['signal_to_noise'], 4)],
            ],
        );

        // Anomalies
        if (count($result['anomalies']) > 0) {
            $this->newLine();
            $this->warn('⚠️  Anomalies Detected:');
            $this->table(
                ['Index', 'Expected', 'Actual', 'Deviation', 'Z-Score'],
                array_map(fn (array $a): array => [
                    $a['index'],
                    number_format($a['expected'], 2),
                    number_format($a['actual'], 2),
                    number_format($a['deviation'], 2),
                    number_format($a['z_score'], 2),
                ], $result['anomalies']),
            );
        } else {
            $this->newLine();
            $this->info('✅ No anomalies detected.');
        }

        // Forecast preview
        if (! $this->option('no-forecast') && count($result['forecast']) > 0) {
            $this->newLine();
            $this->info('📈 Forecast (next ' . count($result['forecast']) . ' periods):');
            $forecastTable = [];
            foreach ($result['forecast'] as $i => $val) {
                $forecastTable[] = [
                    $i + 1,
                    number_format($val, 2),
                    number_format($result['confidence_lower'][$i] ?? 0, 2),
                    number_format($result['confidence_upper'][$i] ?? 0, 2),
                ];
            }
            $this->table(
                ['Step', 'Forecast', 'Lower CI', 'Upper CI'],
                $forecastTable,
            );
        }

        return self::SUCCESS;
    }

    /**
     * Show seasonal profile.
     */
    private function showSeasonalProfile(string $eventName, int $period, bool $asJson): int
    {
        if ($eventName === '') {
            $this->error('Please specify an event name with --event=<name>');

            return self::FAILURE;
        }

        $data = $this->generateSampleData($eventName);

        if ($data === []) {
            $this->error("No data available for event: {$eventName}");

            return self::FAILURE;
        }

        $profile = $this->service->seasonalProfile($data, $period);

        if ($asJson) {
            $this->output->write(json_encode($profile, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("🔄 Seasonal Profile: {$eventName} (period={$period})");
        $this->newLine();

        $rows = [];
        foreach ($profile['positions'] as $i => $pos) {
            $rows[] = [
                $pos,
                number_format($profile['values'][$i], 4),
                in_array($pos, $profile['peaks'], true) ? '🔺 Peak' : (in_array($pos, $profile['troughs'], true) ? '🔻 Trough' : ''),
            ];
        }

        $this->table(['Position', 'Seasonal Value', 'Type'], $rows);
        $this->line('Amplitude: ' . number_format($profile['amplitude'], 4));

        return self::SUCCESS;
    }

    /**
     * Generate sample data for CLI demonstration.
     *
     * In production, this would query AnalyticsEventModel for actual event volumes.
     *
     * @return list<int>
     */
    private function generateSampleData(string $eventName): array
    {
        // Generate 28 days of sample data with a clear trend + weekly seasonality
        mt_srand(crc32($eventName));
        $data = [];

        for ($i = 0; $i < 28; $i++) {
            // Base trend: growing from 100 to ~150
            $trend = 100 + ($i * 1.8);

            // Weekly seasonality: higher on weekdays
            $dayOfWeek = $i % 7;
            $seasonal = match ($dayOfWeek) {
                0 => -15.0, // Sunday low
                1 => 10.0,  // Monday high
                2 => 15.0,  // Tuesday peak
                3 => 12.0,
                4 => 8.0,
                5 => 5.0,
                6 => -10.0, // Saturday low
                default => 0.0,
            };

            // Random noise
            $noise = (mt_rand(-20, 20)) * 1.0;

            // Occasional anomaly
            if ($i === 13) {
                $noise += 60; // Spike
            }

            $data[] = (int) max(0, $trend + $seasonal + $noise);
        }

        return $data;
    }
}
