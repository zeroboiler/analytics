<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\EventTimeSeriesService;

/**
 * Artisan command to display event time-series analytics.
 *
 * Shows aggregated event counts, top events, category breakdown,
 * trend direction, and moving averages across all supported periods.
 *
 * @since 6.0.0
 */
final class AnalyticsTimeSeriesCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'analytics:timeseries
        {--period=1h : Aggregation period (5m, 15m, 1h, 6h, 1d, 7d, 30d)}
        {--event= : Specific event name to analyze}
        {--category : Show category breakdown instead of overall}
        {--compare= : Compare current period against another period}
        {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display event time-series analytics and trends';

    /**
     * Execute the console command.
     */
    public function handle(EventTimeSeriesService $service): int
    {
        $period = (string) $this->option('period');
        $eventName = $this->option('event');
        $showCategory = $this->boolOption('category');
        $comparePeriod = $this->option('compare');
        $asJson = $this->boolOption('json');

        try {
            if ($comparePeriod !== null && is_string($comparePeriod) && $comparePeriod !== '') {
                $result = $service->compare($period, $comparePeriod);
            } elseif ($eventName !== null && is_string($eventName) && $eventName !== '') {
                $result = $service->aggregateEvent($eventName, $period);
            } elseif ($showCategory) {
                $result = $service->aggregateByCategory($period);
            } else {
                $result = $service->aggregate($period);
            }
        } catch (\Throwable $e) {
            $this->error("Time-series computation failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($asJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->displayResult($result, $period);

        return self::SUCCESS;
    }

    /**
     * Display aggregation result as a table.
     *
     * @param  array<string, mixed>  $result
     */
    private function displayResult(array $result, string $period): void
    {
        $this->info("═══ Event Time-Series Analytics [{$period}] ═══");
        $this->newLine();

        if (isset($result['total_events'])) {
            // Overall aggregation
            $trend = $result['trend'] ?? [];
            $arrow = match ($trend['direction'] ?? 'flat') {
                'up' => '↑',
                'down' => '↓',
                default => '→',
            };

            $this->line("Total Events:        {$result['total_events']}");
            $this->line("Unique Identities:   {$result['unique_identities']}");
            $this->line("Trend:               {$arrow} " . ($trend['change_pct'] ?? 0) . '%');
            $this->line("Moving Avg:          " . ($result['moving_avg'] ?? 0));
            $this->newLine();

            // Top events
            $topEvents = $result['top_events'] ?? [];
            if (! empty($topEvents)) {
                $this->info('── Top Events ──');
                $this->table(
                    ['Event', 'Count'],
                    array_map(fn (array $e): array => [$e['event'], $e['count']], $topEvents),
                );
            }

            // Category breakdown
            $categories = $result['category_breakdown'] ?? [];
            if (! empty($categories)) {
                $this->newLine();
                $this->info('── Category Breakdown ──');
                $this->table(
                    ['Category', 'Count'],
                    array_map(fn (string $cat, int $count): array => [$cat, $count], array_keys($categories), array_values($categories)),
                );
            }
        } elseif (isset($result['count'])) {
            // Single event aggregation
            $trend = $result['trend'] ?? [];
            $arrow = match ($trend['direction'] ?? 'flat') {
                'up' => '↑',
                'down' => '↓',
                default => '→',
            };

            $this->line("Event:               " . ($this->option('event') ?? 'N/A'));
            $this->line("Category:            " . ($result['category'] ?? 'N/A'));
            $this->line("Count ({$period}):     {$result['count']}");
            $this->line("% of Total:          " . ($result['pct_of_total'] ?? 0) . '%');
            $this->line("Trend:               {$arrow} " . ($trend['change_pct'] ?? 0) . '%');
        } elseif (isset($result['categories'])) {
            // Category aggregation
            $this->info('── Category Breakdown ──');
            $rows = [];
            foreach ($result['categories'] as $cat => $data) {
                $trend = $data['trend'] ?? [];
                $arrow = match ($trend['direction'] ?? 'flat') {
                    'up' => '↑',
                    'down' => '↓',
                    default => '→',
                };

                $rows[] = [$cat, $data['count'], $data['pct'] . '%', "{$arrow} " . ($trend['change_pct'] ?? 0) . '%'];
            }
            $this->table(['Category', 'Count', '% of Total', 'Trend'], $rows);
        } elseif (isset($result['current']) && isset($result['previous'])) {
            // Comparison
            $current = $result['current'];
            $previous = $result['previous'];
            $delta = $result['delta'] ?? [];

            $this->info('── Period Comparison ──');
            $this->line("Current Period:      {$current['total_events']} events, {$current['unique_identities']} identities");
            $this->line("Previous Period:     {$previous['total_events']} events, {$previous['unique_identities']} identities");
            $this->line("Delta:               " . ($delta['events'] >= 0 ? '+' : '') . $delta['events'] . ' events');
            $this->line("% Change:            " . ($delta['pct_change'] >= 0 ? '+' : '') . $delta['pct_change'] . '%');
        }

        $this->newLine();
        $periods = app(EventTimeSeriesService::class)->supportedPeriods();
        $this->info("Supported periods: " . implode(', ', $periods));
    }

    /**
     * Get a boolean option value, defaulting to false.
     */
    private function boolOption(string $name): bool
    {
        $value = $this->option($name);

        return (bool) $value;
    }
}
