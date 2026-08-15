<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EventTrendForecastService;

/**
 * Analytics trend forecast command.
 *
 * Generates trend forecasts for analytics events using linear regression,
 * exponential smoothing, and seasonal decomposition. Shows projected
 * event volumes, growth rates, and confidence intervals.
 *
 * @see \ZeroBoiler\Analytics\Services\EventTrendForecastService
 *
 * @since 59.0.0
 */
final class AnalyticsTrendForecastCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:trend-forecast
        {--event= : Specific event name to forecast}
        {--category= : Forecast all events in a category (ecommerce, saas, engagement)}
        {--events=* : Multiple event names to forecast and compare}
        {--days=30 : Historical data window in days}
        {--horizon=7 : Forecast horizon in days}
        {--changes : Detect trend acceleration/deceleration changes}
        {--top=10 : Number of top events to show in trend changes mode}
        {--json : Output as JSON}';

    /** @var string */
    protected $description = 'Generate trend forecasts for analytics events using regression and smoothing';

    private EventTrendForecastService $service;

    public function __construct(EventTrendForecastService $service): void
    {
        parent::__construct();
        $this->service = $service;
    }

    #[Override]
    #[Override]
    public function handle(): int
    {
        $asJson = $this->option('json');
        $days = (int) $this->option('days');
        $horizon = (int) $this->option('horizon');
        $eventName = $this->option('event');
        $category = $this->option('category');
        $multiEvents = $this->option('events');
        $detectChanges = $this->option('changes');
        $top = (int) $this->option('top');

        if ($detectChanges) {
            return $this->showTrendChanges($days, $top, $asJson);
        }

        if ($eventName !== null) {
            return $this->showSingleForecast($eventName, $days, $horizon, $asJson);
        }

        if ($category !== null) {
            return $this->showCategoryForecast($category, $days, $horizon, $asJson);
        }

        if ($multiEvents !== []) {
            return $this->showComparativeForecast($multiEvents, $days, $horizon, $asJson);
        }

        return $this->showOverview($days, $horizon, $asJson);
    }

    /**
     * Display forecast for a single event.
     */
    private function showSingleForecast(string $eventName, int $days, int $horizon, bool $asJson): int
    {
        if (! EventCatalog::has($eventName)) {
            $this->error("Event '{$eventName}' not found in catalog.");
            $this->warn('Use --events flag or check available events with: php artisan analytics:overview');

            return self::FAILURE;
        }

        $report = $this->service->forecast($eventName, $days, $horizon);

        if ($asJson) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->displayForecastReport($report);

        return self::SUCCESS;
    }

    /**
     * Display category-level forecast.
     */
    private function showCategoryForecast(string $category, int $days, int $horizon, bool $asJson): int
    {
        $validCategories = ['ecommerce', 'saas', 'engagement', 'security', 'uptime', 'infrastructure', 'marketing'];

        if (! in_array($category, $validCategories, true)) {
            $this->error("Invalid category '{$category}'. Valid: " . implode(', ', $validCategories));

            return self::FAILURE;
        }

        $report = $this->service->forecastCategory($category, $days, $horizon);

        if ($asJson) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->displayForecastReport($report);

        return self::SUCCESS;
    }

    /**
     * Display comparative forecasts for multiple events.
     */
    private function showComparativeForecast(array $eventNames, int $days, int $horizon, bool $asJson): int
    {
        $valid = [];
        foreach ($eventNames as $name) {
            if (EventCatalog::has($name)) {
                $valid[] = $name;
            } else {
                $this->warn("Skipping unknown event: {$name}");
            }
        }

        if ($valid === []) {
            $this->error('No valid events to forecast.');

            return self::FAILURE;
        }

        $report = $this->service->compareForecasts($valid, $days, $horizon);

        if ($asJson) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║           EVENT TREND FORECAST — COMPARATIVE             ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');

        $summary = $report['summary'];
        $this->newLine();
        $this->line("  Period: <info>{$days} days</info> → Forecast: <info>{$horizon} days</info>");
        $this->line("  Total events: <info>{$summary['total']}</info>");
        $this->line("  <fg=green>↑ Upward: {$summary['upward']}</fg=green>  <fg=red>↓ Downward: {$summary['downward']}</fg=red>  <fg=cyan>— Flat: {$summary['flat']}</fg=cyan>  <fg=yellow>~ Volatile: {$summary['volatile']}</fg=yellow>");

        $this->newLine();
        $this->line(sprintf('  %-25s %10s %10s %10s %12s', 'Event', 'Slope', 'R²', 'Growth', 'Direction'));
        $this->line(str_repeat('─', 70));

        foreach ($report['events'] as $evt) {
            $direction = $this->directionIcon($evt['direction']);
            $name = strlen($evt['event_name']) > 25
                ? substr($evt['event_name'], 0, 22) . '...'
                : $evt['event_name'];

            $this->line(sprintf(
                '  %-25s %10s %10s %10s %12s',
                $name,
                $evt['slope'],
                $evt['r_squared'],
                $this->formatPercent($evt['growth_rate']),
                $direction,
            ));
        }

        $this->newLine();
        $this->line("  Computed at: {$report['computed_at']}");

        return self::SUCCESS;
    }

    /**
     * Display trend acceleration/deceleration changes.
     */
    private function showTrendChanges(int $days, int $top, bool $asJson): int
    {
        $changes = $this->service->detectTrendChanges(null, $days);

        if ($asJson) {
            $this->line(json_encode(array_slice($changes, 0, $top), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $display = array_slice($changes, 0, $top);

        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║           TREND ACCELERATION / DECELERATION            ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');

        $this->newLine();
        $this->line(sprintf('  %-25s %10s %10s %10s %16s', 'Event', 'Current', 'Full', 'Change %', 'Acceleration'));
        $this->line(str_repeat('─', 75));

        foreach ($display as $change) {
            $accelLabel = $this->accelerationLabel($change['acceleration']);
            $name = strlen($change['event_name']) > 25
                ? substr($change['event_name'], 0, 22) . '...'
                : $change['event_name'];

            $this->line(sprintf(
                '  %-25s %10s %10s %10s %16s',
                $name,
                $change['current_slope'],
                $change['full_slope'],
                $change['change_pct'] . '%',
                $accelLabel,
            ));
        }

        $this->newLine();
        $this->line("  Showing top {$top} of " . count($changes) . ' events analyzed');

        return self::SUCCESS;
    }

    /**
     * Display overview with top trending events.
     */
    private function showOverview(int $days, int $horizon, bool $asJson): int
    {
        $summary = EventCatalog::summary();
        $config = $this->service->getConfig();

        if ($asJson) {
            $this->line(json_encode([
                'catalog_summary' => $summary,
                'forecast_config' => $config,
                'days' => $days,
                'horizon' => $horizon,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║              EVENT TREND FORECAST ENGINE                  ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');

        $this->newLine();
        $this->line("  Catalog: <info>{$summary['total']} events</info> across 6 categories");
        $this->line("  Forecast horizon: <info>{$horizon} days</info> | History: <info>{$days} days</info>");
        $this->line("  Confidence level: <info>" . ($config['confidence_level'] * 100) . '%</info>");
        $this->line("  Seasonal analysis: <info>' . ($config['seasonal_enabled'] ? 'enabled' : 'disabled') . '</info>');

        $this->newLine();
        $this->line('  Available options:');
        $this->line('    <info>--event=page_view</info>       Forecast a single event');
        $this->line('    <info>--category=saas</info>         Forecast a whole category');
        $this->line('    <info>--events=login sign_up</info>  Compare multiple events');
        $this->line('    <info>--changes</info>               Detect trend accelerations');
        $this->line('    <info>--json</info>                  Output as JSON');
        $this->line('    <info>--days=14</info>               Change history window');
        $this->line('    <info>--horizon=30</info>            Change forecast horizon');

        return self::SUCCESS;
    }

    /**
     * Display a single forecast report.
     */
    private function displayForecastReport(array $report): void
    {
        $direction = $this->directionIcon($report['direction']);

        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║              EVENT TREND FORECAST                        ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');

        $this->newLine();
        $this->line("  Event: <info>{$report['event_name']}</info>");
        $this->line("  Data points: <info>{$report['data_points']}</info> | Period: <info>{$report['period']} days</info>");
        $this->line("  Direction: {$direction}");
        $this->newLine();

        $this->line(sprintf('  %-14s %s', 'Slope:', $report['slope']));
        $this->line(sprintf('  %-14s %s', 'R²:', $report['r_squared']));
        $this->line(sprintf('  %-14s %s', 'Intercept:', $report['intercept']));
        $this->line(sprintf('  %-14s %s', 'Growth Rate:', $this->formatPercent($report['growth_rate'])));
        $this->line(sprintf('  %-14s %s', 'Method:', $report['method']));

        if ($report['seasonal'] !== null) {
            $this->newLine();
            $this->line('  <fg=cyan>Seasonal Indices:</>');
            foreach ($report['seasonal'] as $period => $index) {
                $bar = $this->miniBar($index);
                $this->line("    {$period}: {$index} {$bar}");
            }
        }

        $this->newLine();
        $this->line('  <fg=cyan>Forecast:</>');
        $this->line(sprintf('  %-12s %10s %10s %10s %10s', 'Date', 'Predicted', 'Lower', 'Upper', 'Horizon'));
        $this->line('  ' . str_repeat('─', 55));

        foreach ($report['forecast'] as $point) {
            $this->line(sprintf(
                '  %-12s %10s %10s %10s %10s',
                $point['date'],
                $point['predicted'],
                $point['lower'],
                $point['upper'],
                'Day +' . $point['horizon_day'],
            ));
        }

        $this->newLine();
        $this->line("  Computed at: {$report['computed_at']}");
    }

    /**
     * Get a colored direction icon.
     */
    private function directionIcon(string $direction): string
    {
        return match ($direction) {
            'up' => '<fg=green>↑ Upward</>',
            'down' => '<fg=red>↓ Downward</>',
            'flat' => '<fg=cyan>— Flat</>',
            'volatile' => '<fg=yellow>~ Volatile</>',
            default => $direction,
        };
    }

    /**
     * Get a colored acceleration label.
     */
    private function accelerationLabel(string $acceleration): string
    {
        return match ($acceleration) {
            'accelerating' => '<fg=green>▲ Accelerating</>',
            'decelerating' => '<fg=red>▼ Decelerating</>',
            'stable' => '<fg=cyan>● Stable</>',
            default => $acceleration,
        };
    }

    /**
     * Format a growth rate as a percentage string.
     */
    private function formatPercent(float $rate): string
    {
        $pct = round($rate * 100, 2);

        return ($pct >= 0 ? '+' : '') . $pct . '%';
    }

    /**
     * Generate a mini bar chart from a seasonal index.
     */
    private function miniBar(float $index, int $maxBars = 20): string
    {
        $bars = (int) round(min($maxBars, max(0, $index * 10)));
        $color = $index >= 1.0 ? 'green' : 'red';

        return "<fg={$color}>" . str_repeat('█', $bars) . '</>';
    }
}
