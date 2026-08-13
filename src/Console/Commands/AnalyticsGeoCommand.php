<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\GeographicAnalyticsService;

/**
 * Analytics Geo Command — Geographic analytics CLI tool.
 *
 * Provides subcommands for:
 *   - summary: High-level geographic analytics overview
 *   - countries: Country-level event breakdown
 *   - regions: Region-level breakdown (filterable by country)
 *   - cities: City-level breakdown
 *   - timezones: Timezone distribution
 *   - engagement: Country engagement heatmap
 *   - funnel: Regional conversion funnel
 *   - top-events: Most tracked events per country
 *   - anomalies: Geo anomaly detection
 *   - continents: Continent-level breakdown
 *   - snapshot-baseline: Snapshot current aggregates as anomaly baseline
 *   - clear-cache: Clear all cached geo analytics data
 *
 * @since v73.0.0
 */
final class AnalyticsGeoCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zb:analytics:geo
                            {action : summary|countries|regions|cities|timezones|engagement|funnel|top-events|anomalies|continents|snapshot-baseline|clear-cache}
                            {--country= : Filter to country code (ISO 3166-1 alpha-2)}
                            {--limit=20 : Max results to display}
                            {--entry=sign_up : Entry event for regional conversion}
                            {--conversion=purchase : Conversion event for regional conversion}
                            {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Geographic analytics — country/region/city/timezone event breakdowns and engagement heatmap';

    /**
     * Execute the console command.
     */
    #[\Override]
    public function handle(GeographicAnalyticsService $service): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'summary' => $this->showSummary($service),
            'countries' => $this->showCountries($service),
            'regions' => $this->showRegions($service),
            'cities' => $this->showCities($service),
            'timezones' => $this->showTimezones($service),
            'engagement' => $this->showEngagement($service),
            'funnel' => $this->showFunnel($service),
            'top-events' => $this->showTopEvents($service),
            'anomalies' => $this->showAnomalies($service),
            'continents' => $this->showContinents($service),
            'snapshot-baseline' => $this->snapshotBaseline($service),
            'clear-cache' => $this->clearCache($service),
            default => $this->invalidAction($action),
        };
    }

    /**
     * Show geographic analytics summary.
     */
    private function showSummary(GeographicAnalyticsService $service): int
    {
        $summary = $service->summary();

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info('Geographic Analytics Summary');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Enabled', $summary['enabled'] ? 'Yes' : 'No'],
                ['Total Events', number_format($summary['total_events'])],
                ['Total Countries', $summary['total_countries']],
                ['Total Timezones', $summary['total_timezones']],
                ['Average Engagement', $summary['average_engagement']],
                ['Anomalies Detected', $summary['anomalies_detected']],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * Show country-level breakdown.
     */
    private function showCountries(GeographicAnalyticsService $service): int
    {
        $data = $service->countryBreakdown();

        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info("Country Breakdown — {$data['total_events']} events across {$data['total_countries']} countries");

        $rows = array_map(
            fn (array $c): array => [
                $c['country'],
                number_format($c['events']),
                number_format($c['users']),
                $c['percentage'] . '%',
            ],
            $data['countries'],
        );

        $this->table(['Country', 'Events', 'Users', 'Percentage'], $rows);

        return self::SUCCESS;
    }

    /**
     * Show region-level breakdown.
     */
    private function showRegions(GeographicAnalyticsService $service): int
    {
        $country = $this->option('country');
        $data = $service->regionBreakdown($country !== '' ? $country : null);

        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $label = $country !== '' ? "Country: {$country}" : 'All Countries';

        $this->components->info("Region Breakdown — {$label} — {$data['total_events']} events across {$data['total_regions']} regions");

        $rows = array_map(
            fn (array $r): array => [
                $r['region'],
                $r['country'],
                number_format($r['events']),
                number_format($r['users']),
                $r['percentage'] . '%',
            ],
            $data['regions'],
        );

        $this->table(['Region', 'Country', 'Events', 'Users', 'Percentage'], $rows);

        return self::SUCCESS;
    }

    /**
     * Show city-level breakdown.
     */
    private function showCities(GeographicAnalyticsService $service): int
    {
        $country = $this->option('country');
        $limit = (int) $this->option('limit');
        $data = $service->cityBreakdown($country !== '' ? $country : null, $limit);

        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info("City Breakdown — {$data['total_events']} events (top {$limit} cities)");

        $rows = array_map(
            fn (array $c): array => [
                $c['city'],
                $c['country'],
                $c['region'],
                number_format($c['events']),
                number_format($c['users']),
            ],
            $data['cities'],
        );

        $this->table(['City', 'Country', 'Region', 'Events', 'Users'], $rows);

        return self::SUCCESS;
    }

    /**
     * Show timezone distribution.
     */
    private function showTimezones(GeographicAnalyticsService $service): int
    {
        $data = $service->timezoneDistribution();

        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info("Timezone Distribution — {$data['total_timezones']} timezones");

        $rows = array_map(
            fn (array $t): array => [
                $t['timezone'],
                number_format($t['events']),
                number_format($t['users']),
                $t['percentage'] . '%',
            ],
            $data['timezones'],
        );

        $this->table(['Timezone', 'Events', 'Users', 'Percentage'], $rows);

        return self::SUCCESS;
    }

    /**
     * Show engagement heatmap.
     */
    private function showEngagement(GeographicAnalyticsService $service): int
    {
        $data = $service->engagementHeatmap();

        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info("Engagement Heatmap — Average Score: {$data['average_score']}");

        $rows = array_map(
            fn (array $c): array => [
                $c['country'],
                $c['score'],
                $c['grade'],
                number_format($c['events']),
                number_format($c['users']),
            ],
            $data['countries'],
        );

        $this->table(['Country', 'Score', 'Grade', 'Events', 'Users'], $rows);

        return self::SUCCESS;
    }

    /**
     * Show regional conversion funnel.
     */
    private function showFunnel(GeographicAnalyticsService $service): int
    {
        $entry = $this->option('entry');
        $conversion = $this->option('conversion');
        $data = $service->regionalConversion($entry, $conversion);

        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info("Regional Conversion: {$entry} → {$conversion} — Global Rate: {$data['global_rate']}%");

        $rows = array_map(
            fn (array $r): array => [
                $r['country'],
                number_format($r['entry_count']),
                number_format($r['conversion_count']),
                $r['rate'] . '%',
            ],
            $data['regions'],
        );

        $this->table(['Country', 'Entries', 'Conversions', 'Rate'], $rows);

        return self::SUCCESS;
    }

    /**
     * Show top events per country.
     */
    private function showTopEvents(GeographicAnalyticsService $service): int
    {
        $data = $service->topEventsPerCountry();

        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info('Top Events Per Country');

        foreach ($data['countries'] as $country => $events) {
            $this->newLine();
            $this->components->twoColumnDetail($country, count($events) . ' top events');

            foreach ($events as $event) {
                $this->components->twoColumnDetail('  ' . $event['event'], number_format($event['count']));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Show geo anomaly detection results.
     */
    private function showAnomalies(GeographicAnalyticsService $service): int
    {
        $data = $service->detectAnomalies();

        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info("Geo Anomaly Detection — Checked {$data['checked']} countries");

        if (empty($data['anomalies'])) {
            $this->info('No anomalies detected.');

            return self::SUCCESS;
        }

        $rows = array_map(
            fn (array $a): array => [
                $a['country'],
                number_format($a['current']),
                number_format($a['baseline']),
                $a['deviation'] . 'x',
                $a['direction'],
            ],
            $data['anomalies'],
        );

        $this->table(['Country', 'Current', 'Baseline', 'Deviation', 'Direction'], $rows);

        return self::SUCCESS;
    }

    /**
     * Show continent-level breakdown.
     */
    private function showContinents(GeographicAnalyticsService $service): int
    {
        $data = $service->continentBreakdown();

        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info("Continent Breakdown — {$data['total_events']} events");

        $rows = array_map(
            fn (array $c): array => [
                $c['continent'],
                number_format($c['events']),
                number_format($c['users']),
                $c['countries'],
                $c['percentage'] . '%',
            ],
            $data['continents'],
        );

        $this->table(['Continent', 'Events', 'Users', 'Countries', 'Percentage'], $rows);

        return self::SUCCESS;
    }

    /**
     * Snapshot current aggregates as anomaly baseline.
     */
    private function snapshotBaseline(GeographicAnalyticsService $service): int
    {
        $service->snapshotBaseline();
        $this->components->info('Geographic baseline snapshot saved.');

        return self::SUCCESS;
    }

    /**
     * Clear all cached geo analytics data.
     */
    private function clearCache(GeographicAnalyticsService $service): int
    {
        $service->clearCache();
        $this->components->info('Geographic analytics cache cleared.');

        return self::SUCCESS;
    }

    /**
     * Handle invalid action.
     */
    private function invalidAction(string $action): int
    {
        $this->error("Invalid action: {$action}");
        $this->line('Valid actions: summary, countries, regions, cities, timezones, engagement, funnel, top-events, anomalies, continents, snapshot-baseline, clear-cache');

        return self::FAILURE;
    }
}
