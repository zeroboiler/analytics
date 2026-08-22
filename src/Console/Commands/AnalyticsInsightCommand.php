<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\AnalyticsAIService;
use ZeroBoiler\Analytics\Services\GeospatialAnalyticsService;
use ZeroBoiler\Analytics\Services\NaturalLanguageQueryEngine;

/**
 * Unified analytics insight command — AI-powered natural language queries,
 * geospatial analytics, anomaly detection, and smart recommendations.
 *
 * Provides a single CLI entry point for:
 * - Natural language queries ("ask" mode)
 * - Geospatial breakdowns ("geo" mode)
 * - Anomaly detection ("anomalies" mode)
 * - Smart insights and recommendations ("insights" mode)
 * - Geographic funnel analysis ("geo-funnel" mode)
 *
 * Examples:
 *   php artisan zb:analytics:insight ask "How many page views last week?"
 *   php artisan zb:analytics:insight geo country --event=purchase
 *   php artisan zb:analytics:insight anomalies --threshold=2.5
 *   php artisan zb:analytics:insight insights
 *   php artisan zb:analytics:insight geo-funnel "sign_up,subscribe,plan_upgrade"
 *   php artisan zb:analytics:insight heatmap --event=page_view --json
 *
 * @since 237.0.0
 */
final class AnalyticsInsightCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:insight
        {mode : Action mode: ask, geo, anomalies, insights, geo-funnel, heatmap, summary}
        {question? : Natural language question (for "ask" mode)}
        {--event= : Event name filter}
        {--category= : Category filter}
        {--country= : Country filter for geo queries}
        {--limit=20 : Result limit}
        {--threshold=2.0 : Anomaly z-score threshold}
        {--dimension=country : Geographic dimension (country, region, city, timezone, continent)}
        {--json : Output as JSON}
        {--fresh : Skip cache}
        {--compare : Include comparison data}
        {--group-by= : Group results by dimension}';

    /** @var string */
    protected $description = 'AI-powered analytics insights, geospatial queries, and anomaly detection';

    public function __construct(
        private readonly NaturalLanguageQueryEngine $nlEngine,
        private readonly GeospatialAnalyticsService $geoService,
        private readonly AnalyticsAIService $aiService,
    ){
        parent::__construct();
    }

    /**
     * Execute the insight command.
     */
    public function handle(): int
    {
        $mode = $this->argument('mode');
        $outputJson = $this->option('json');

        return match ($mode) {
            'ask' => $this->handleAsk($outputJson),
            'geo' => $this->handleGeo($outputJson),
            'anomalies' => $this->handleAnomalies($outputJson),
            'insights' => $this->handleInsights($outputJson),
            'geo-funnel' => $this->handleGeoFunnel($outputJson),
            'heatmap' => $this->handleHeatmap($outputJson),
            'summary' => $this->handleSummary($outputJson),
            default => $this->invalidMode($mode),
        };
    }

    // ── Mode Handlers ─────────────────────────────────────────────────

    /**
     * Handle natural language query mode.
     */
    private function handleAsk(bool $json): int
    {
        $question = $this->argument('question');

        if (! is_string($question) || $question === '') {
            $question = $this->ask('Enter your analytics question:', 'How many page views last week?');
        }

        $this->info("🔍 Querying: {$question}");
        $this->newLine();

        $result = $this->nlEngine->ask($question);

        if ($json) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        // Display parsed query
        $parsed = $result['query'];
        $this->info('📋 Parsed Query:');
        $this->table(
            ['Field', 'Value'],
            [
                ['Event', $parsed['event'] ?? '(auto-detected from category/metric)'],
                ['Category', $parsed['category'] ?? '(all)'],
                ['Time Range', $parsed['time_range']['label']],
                ['Aggregation', $parsed['aggregation']],
                ['Limit', (string) $parsed['limit']],
                ['Sort', $parsed['sort']],
                ['Confidence', ($parsed['confidence'] * 100) . '%'],
                ['Comparison', $parsed['comparison'] === true ? 'Yes' : 'No'],
                ['Group By', $parsed['group_by'] ?? '(none)'],
            ],
        );

        // Display summary
        $this->newLine();
        $this->comment("💬 {$result['summary']}");

        // Display data summary
        if ($result['data'] !== null) {
            $this->newLine();
            $this->info('📊 Data Source: ' . ($result['data']['source'] ?? 'unknown'));
            $this->info('   Type: ' . ($result['data']['type'] ?? 'unknown'));
        }

        // Display execution stats
        $this->newLine();
        $this->info("⏱️  Executed in {$result['execution_ms']}ms" . ($result['cached'] ? ' (cached)' : ''));

        // Display suggestions
        if ($result['suggestions'] !== []) {
            $this->newLine();
            $this->info('💡 Suggested follow-up queries:');
            foreach ($result['suggestions'] as $i => $suggestion) {
                $this->line("   " . ($i + 1) . ". {$suggestion}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Handle geospatial breakdown mode.
     */
    private function handleGeo(bool $json): int
    {
        $dimension = $this->option('dimension');
        $event = $this->option('event');
        $category = $this->option('category');
        $country = $this->option('country');
        $limit = (int) $this->option('limit');

        $this->info("🌍 Geographic breakdown by {$dimension}");
        if ($event !== null) {
            $this->info("   Event filter: {$event}");
        }
        if ($category !== null) {
            $this->info("   Category filter: {$category}");
        }
        if ($country !== null) {
            $this->info("   Country filter: {$country}");
        }
        $this->newLine();

        $result = match ($dimension) {
            'country' => $this->geoService->byCountry($event, $category),
            'region' => $this->geoService->byRegion($event, $category, $country),
            'city' => $this->geoService->byCity($event, $category, $country),
            'continent' => $this->geoService->byContinent($event),
            'timezone' => $this->geoService->byTimezone($event),
            default => $this->geoService->byCountry($event, $category),
        };

        if ($json) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        // Display table
        $rows = array_map(
            fn (array $item): array => [
                $item['location'],
                (string) $item['count'],
                $item['percentage'] . '%',
            ],
            array_slice($result['top_n'], 0, $limit),
        );

        $this->table(
            [ucfirst($dimension), 'Events', 'Share'],
            $rows,
        );

        $this->newLine();
        $this->info("Total: {$result['total']} events across " . count($result['values']) . " {$dimension}s");

        return self::SUCCESS;
    }

    /**
     * Handle anomaly detection mode.
     */
    private function handleAnomalies(bool $json): int
    {
        $event = $this->option('event');
        $threshold = (float) $this->option('threshold');

        $this->info("🔍 Detecting geographic anomalies (z-score > {$threshold})");
        if ($event !== null) {
            $this->info("   Event filter: {$event}");
        }
        $this->newLine();

        $anomalies = $this->geoService->detectAnomalies($event, $threshold);

        if ($json) {
            $this->line(json_encode($anomalies, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($anomalies === []) {
            $this->info('✅ No geographic anomalies detected.');
            $this->comment('   All locations are within normal traffic distribution.');

            return self::SUCCESS;
        }

        $this->warn('⚠️  Detected ' . count($anomalies) . ' geographic anomalies:');

        $rows = array_map(
            fn (array $a): array => [
                $a['location'],
                $a['dimension'],
                ($a['actual_share'] * 100) . '%',
                ($a['expected_share'] * 100) . '%',
                (string) $a['z_score'],
                strtoupper($a['severity']),
            ],
            $anomalies,
        );

        $this->table(
            ['Location', 'Dimension', 'Actual Share', 'Expected Share', 'Z-Score', 'Severity'],
            $rows,
        );

        return self::SUCCESS;
    }

    /**
     * Handle smart insights mode.
     */
    private function handleInsights(bool $json): int
    {
        $this->info('🧠 Generating smart analytics insights...');
        $this->newLine();

        $catalogCount = EventCatalog::count();
        $categorySummary = EventCatalog::categorySummary();

        // Generate insights based on catalog analysis
        $insights = $this->generateCatalogInsights($catalogCount, $categorySummary);

        if ($json) {
            $this->line(json_encode($insights, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('📊 Catalog Insights:');
        $this->newLine();

        foreach ($insights as $i => $insight) {
            $type = $insight['type'];
            $title = $insight['title'];

            $icon = match ($type) {
                'coverage' => '📈',
                'recommendation' => '💡',
                'metric' => '📊',
                'warning' => '⚠️',
                'strength' => '✅',
                default => 'ℹ️',
            };

            $this->line("  {$icon} {$title}");
            $this->line("     {$insight['description']}");

            if (! empty($insight['action_items'])) {
                foreach ($insight['action_items'] as $item) {
                    $this->line("     → {$item}");
                }
            }
            $this->newLine();
        }

        // Geographic coverage
        $this->info('🌍 Geographic Coverage:');
        $geoCoverage = $this->geoService->coverage();
        $this->line("   Countries: {$geoCoverage['countries']} tracked");
        $this->line("   Regions: {$geoCoverage['regions']} tracked");
        $this->line("   Cities: {$geoCoverage['cities']} tracked");
        $this->line("   Coverage score: " . round($geoCoverage['coverage_score'] * 100, 1) . '%');

        // NL engine status
        $this->newLine();
        $this->info('🤖 Natural Language Query Engine:');
        $nlSummary = $this->nlEngine->summary();
        $status = $nlSummary['enabled'] ? 'enabled' : 'disabled';
        $this->line("   Status: {$status}");
        $this->line("   Templates: {$nlSummary['templates']} question patterns");
        $this->line("   Custom parsers: {$nlSummary['custom_parsers']}");

        // Sample questions
        $this->newLine();
        $this->comment('Try asking:');
        $templates = $this->nlEngine->questionTemplates();
        $sampled = array_slice($templates, 0, 3);
        foreach ($sampled as $template) {
            $example = str_replace('{event}', 'page_view', str_replace('{n}', '10', str_replace('{time_range}', 'last week', $template['template'])));
            $this->line("   php artisan zb:analytics:insight ask \"{$example}\"");
        }

        return self::SUCCESS;
    }

    /**
     * Handle geographic funnel mode.
     */
    private function handleGeoFunnel(bool $json): int
    {
        $question = $this->argument('question');

        if (! is_string($question) || $question === '') {
            $question = 'sign_up,start_trial,subscribe,plan_upgrade';
        }

        $stages = explode(',', $question);
        $stages = array_map('trim', $stages);

        if (count($stages) < 2) {
            $this->error('Funnel requires at least 2 stages (comma-separated event names).');

            return self::FAILURE;
        }

        $dimension = $this->option('dimension');

        $this->info("🗺️  Geographic funnel: " . implode(' → ', $stages));
        $this->info("   Dimension: {$dimension}");
        $this->newLine();

        $result = $this->geoService->geographicFunnel($stages, $dimension);

        if ($json) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Overall conversion: " . round($result['overall_conversion'] * 100, 2) . '%');
        $this->info("Locations analyzed: " . count($result['locations']));
        $this->newLine();

        // Show funnel for first 10 locations
        $limit = min(10, count($result['locations']));

        for ($i = 0; $i < $limit; $i++) {
            $location = $result['locations'][$i];
            $locationName = $location[0]['location'] ?? 'Unknown';

            $this->info("📍 {$locationName}:");

            foreach ($location as $step) {
                $bar = $this->buildProgressBar($step['conversion_rate'], 20);
                $this->line("   {$step['stage']}: {$step['count']} events ({$bar} " . round($step['conversion_rate'] * 100, 1) . '%)');
            }
            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * Handle heatmap mode.
     */
    private function handleHeatmap(bool $json): int
    {
        $event = $this->option('event');
        $category = $this->option('category');

        $this->info('🗺️  Generating heatmap data...');
        if ($event !== null) {
            $this->info("   Event: {$event}");
        }
        $this->newLine();

        $points = $this->geoService->heatmapData($event, $category);

        if ($json) {
            $this->line(json_encode([
                'type' => 'heatmap',
                'points' => $points,
                'total_points' => count($points),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($points === []) {
            $this->comment('No heatmap data available (no events with geolocation).');

            return self::SUCCESS;
        }

        $this->table(
            ['Location', 'Country', 'Lat', 'Lng', 'Intensity'],
            array_map(
                fn (array $p): array => [
                    $p['location'],
                    $p['country_code'] ?? '—',
                    (string) round($p['lat'], 4),
                    (string) round($p['lng'], 4),
                    (string) $p['intensity'],
                ],
                array_slice($points, 0, 20),
            ),
        );

        $this->newLine();
        $this->info('Total points: ' . count($points));

        // GeoJSON export tip
        $this->comment('💡 Use --json with heatmap mode or query the /api/analytics/geo/geojson endpoint for GeoJSON export.');

        return self::SUCCESS;
    }

    /**
     * Handle summary mode.
     */
    private function handleSummary(bool $json): int
    {
        $this->info('📊 Analytics Insight Engine Summary');
        $this->newLine();

        $summary = [
            'nl_query' => $this->nlEngine->summary(),
            'geospatial' => $this->geoService->summary(),
            'event_catalog' => [
                'total_events' => EventCatalog::count(),
                'categories' => count(EventCatalog::byCategory()),
            ],
        ];

        if ($json) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        // NL Query Engine
        $nl = $summary['nl_query'];
        $nlStatus = $nl['enabled'] ? '<info>enabled</info>' : '<comment>disabled</comment>';
        $this->line("🤖 Natural Language Query: {$nlStatus}");
        $this->line("   Cache TTL: {$nl['cache_ttl']}s");
        $this->line("   LLM Fallback: " . ($nl['llm_fallback'] ? 'enabled (' . $nl['llm_provider'] . ')' : 'disabled'));
        $this->line("   Templates: {$nl['templates']}");
        $this->line("   Custom Parsers: {$nl['custom_parsers']}");

        // Geospatial
        $this->newLine();
        $geo = $summary['geospatial'];
        $geoStatus = $geo['enabled'] ? '<info>enabled</info>' : '<comment>disabled</comment>';
        $this->line("🌍 Geospatial Analytics: {$geoStatus}");
        $this->line("   Cache TTL: {$geo['cache_ttl']}s");
        $this->line("   Top Locations Limit: {$geo['top_limit']}");
        $this->line("   Heatmap Bucket Size: {$geo['heatmap_bucket']}");
        $this->line("   Known Countries: {$geo['country_count']}");

        // Catalog
        $this->newLine();
        $cat = $summary['event_catalog'];
        $this->line("📋 Event Catalog: {$cat['total_events']} events across {$cat['categories']} categories");

        return self::SUCCESS;
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Handle invalid mode.
     */
    private function invalidMode(string $mode): int
    {
        $this->error("Invalid mode: {$mode}");
        $this->newLine();
        $this->info('Available modes:');
        $this->line('  ask         — Natural language query');
        $this->line('  geo         — Geographic breakdown');
        $this->line('  anomalies   — Geographic anomaly detection');
        $this->line('  insights    — Smart insights & recommendations');
        $this->line('  geo-funnel  — Geographic funnel analysis');
        $this->line('  heatmap     — Heatmap data generation');
        $this->line('  summary     — Service status summary');

        return self::FAILURE;
    }

    /**
     * Generate catalog-based insights.
     *
     * @return list<array{type: string, title: string, description: string, action_items: list<string>}>
     */
    private function generateCatalogInsights(int $catalogCount, array $categorySummary): array
    {
        $insights = [];

        // Coverage insight
        $insights[] = [
            'type' => 'coverage',
            'title' => "{$catalogCount} events across " . count($categorySummary) . ' categories',
            'description' => 'Your event catalog provides comprehensive coverage for SaaS analytics.',
            'action_items' => [],
        ];

        // Category balance insight
        $maxCategory = '';
        $maxCount = 0;
        $minCategory = '';
        $minCount = PHP_INT_MAX;

        foreach ($categorySummary as $name => $count) {
            if ($name === 'total') {
                continue;
            }

            if ($count > $maxCount) {
                $maxCount = $count;
                $maxCategory = $name;
            }

            if ($count < $minCount) {
                $minCount = $count;
                $minCategory = $name;
            }
        }

        $insights[] = [
            'type' => 'recommendation',
            'title' => "Largest category: {$maxCategory} ({$maxCount} events)",
            'description' => "Consider whether {$minCategory} ({$minCount} events) needs additional event definitions.",
            'action_items' => [
                "Review {$minCategory} events for gaps",
                "Add custom events to underrepresented categories",
            ],
        ];

        // Ecommerce strength
        if (isset($categorySummary['ecommerce']) && $categorySummary['ecommerce'] >= 10) {
            $insights[] = [
                'type' => 'strength',
                'title' => 'Strong e-commerce event coverage',
                'description' => $categorySummary['ecommerce'] . ' e-commerce events with full purchase funnel tracking.',
                'action_items' => [],
            ];
        }

        // SaaS lifecycle
        if (isset($categorySummary['saas']) && $categorySummary['saas'] >= 15) {
            $insights[] = [
                'type' => 'strength',
                'title' => 'Comprehensive SaaS lifecycle tracking',
                'description' => $categorySummary['saas'] . ' SaaS events covering signup, trial, subscription, and billing.',
                'action_items' => [],
            ];
        }

        // Multi-provider insight
        $insights[] = [
            'type' => 'recommendation',
            'title' => 'Multi-provider event mapping available',
            'description' => 'All events have GA4, Meta, PostHog, Plausible, Mixpanel, Amplitude mappings.',
            'action_items' => [
                'Use the EcommerceFormatConverter for cross-provider format conversion',
                'Enable multiple providers in config for redundancy',
            ],
        ];

        return $insights;
    }

    /**
     * Build a visual progress bar for funnel conversion rates.
     */
    private function buildProgressBar(float $rate, int $width): int
    {
        $filled = (int) round($rate * $width);

        $bar = str_repeat('█', $filled) . str_repeat('░', $width - $filled);

        $this->line("   [{$bar}]");

        return $filled;
    }
}
