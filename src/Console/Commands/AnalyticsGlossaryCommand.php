<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\SaaSAnalyticsGlossaryService;

/**
 * SaaS Analytics Glossary command — displays industry-standard metric definitions,
 * formulas, benchmarks, and event-to-metric cross-references.
 *
 * Usage:
 *   php artisan zb:analytics:glossary              Full glossary (grouped by category)
 *   php artisan zb:analytics:glossary --metric=mrr Detail for a single metric
 *   php artisan zb:analytics:glossary --search=revenue Find metrics by keyword
 *   php artisan zb:analytics:glossary --event=purchase Find which metrics use an event
 *   php artisan zb:analytics:glossary --cross-ref  Event-to-metric cross-reference map
 *   php artisan zb:analytics:glossary --coverage  Coverage analysis
 *   php artisan zb:analytics:glossary --tags      List all tags
 *   php artisan zb:analytics:glossary --compact   Quick reference (key, name, formula)
 *   php artisan zb:analytics:glossary --json      JSON output
 *
 * @since 217.0.0
 */
final class AnalyticsGlossaryCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'zb:analytics:glossary
        {--metric= : Show detail for a specific metric key (e.g. mrr, churn_rate)}
        {--search= : Search metrics by keyword in name, description, or tags}
        {--event= : Find which metrics consume a given event name}
        {--cross-ref : Show event-to-metric cross-reference map}
        {--coverage : Show coverage analysis (which metrics have source events)}
        {--tags : List all unique tags}
        {--compact : Quick reference mode (key, name, formula only)}
        {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display SaaS analytics glossary — metric definitions, formulas, benchmarks, and event cross-references';

    /**
     * Execute the console command.
     */
    public function handle(SaaSAnalyticsGlossaryService $glossary): int
    {
        // Single metric detail
        if ($this->option('metric') !== null) {
            return $this->showMetric($glossary, (string) $this->option('metric'));
        }

        // Search by keyword
        if ($this->option('search') !== null) {
            return $this->searchMetrics($glossary, (string) $this->option('search'));
        }

        // Event-to-metric lookup
        if ($this->option('event') !== null) {
            return $this->showMetricsForEvent($glossary, (string) $this->option('event'));
        }

        // Cross-reference map
        if ($this->option('cross-ref')) {
            return $this->showCrossRef($glossary);
        }

        // Coverage analysis
        if ($this->option('coverage')) {
            return $this->showCoverage($glossary);
        }

        // Tags listing
        if ($this->option('tags')) {
            return $this->showTags($glossary);
        }

        // Compact quick reference
        if ($this->option('compact')) {
            return $this->showCompact($glossary);
        }

        // Default: full glossary grouped by category
        return $this->showFullGlossary($glossary);
    }

    /**
     * Display full glossary grouped by category.
     */
    private function showFullGlossary(SaaSAnalyticsGlossaryService $glossary): int
    {
        $groups = $glossary->groupedByCategory();

        if ($this->option('json')) {
            $this->line(json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('📚 SaaS Analytics Glossary — ' . $glossary->count() . ' metrics across ' . count($groups) . ' categories');
        $this->newLine();

        foreach ($groups as $category => $metrics) {
            $this->components->twoColumnDetail("{$this->emoji($category)} {$category}", count($metrics) . ' metrics');

            foreach ($metrics as $key => $metric) {
                $this->line("  <comment>{$key}</comment> — {$metric['name']}");
                $this->line("    {$metric['description']}");
                $this->line("    <info>Formula:</info> {$metric['formula']}");
                $this->line("    <info>Benchmarks:</info> ✓ {$metric['benchmarks']['good']} | ⚠ {$metric['benchmarks']['acceptable']} | ✗ {$metric['benchmarks']['poor']}");
                $this->line("    <info>Source events:</info> " . ($metric['source_events'] !== [] ? implode(', ', $metric['source_events']) : '<fg=yellow>none defined</>'));
                $this->newLine();
            }
        }

        return self::SUCCESS;
    }

    /**
     * Display detail for a single metric.
     */
    private function showMetric(SaaSAnalyticsGlossaryService $glossary, string $key): int
    {
        $metric = $glossary->get($key);

        if ($metric === null) {
            $this->error("Metric '{$key}' not found. Available: " . implode(', ', $glossary->names()));

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode(['key' => $key] + $metric, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("📊 {$metric['name']} ({$key})");
        $this->newLine();
        $this->line($metric['description']);
        $this->newLine();
        $this->components->twoColumnDetail('Formula', $metric['formula']);
        $this->newLine();
        $this->line('  <info>Benchmarks:</info>');
        $this->components->twoColumnDetail('  ✓ Good', $metric['benchmarks']['good']);
        $this->components->twoColumnDetail('  ⚠ Acceptable', $metric['benchmarks']['acceptable']);
        $this->components->twoColumnDetail('  ✗ Poor', $metric['benchmarks']['poor']);
        $this->newLine();
        $this->line('  <info>Source Events:</info> ' . ($metric['source_events'] !== [] ? implode(', ', $metric['source_events']) : 'none'));
        $this->line('  <info>Required Config:</info> ' . ($metric['required_config'] !== [] ? implode(', ', $metric['required_config']) : 'none'));
        $this->line('  <info>Category:</info> ' . $metric['category']);
        $this->line('  <info>Tags:</info> ' . implode(', ', $metric['tags']));

        return self::SUCCESS;
    }

    /**
     * Search metrics by keyword.
     */
    private function searchMetrics(SaaSAnalyticsGlossaryService $glossary, string $query): int
    {
        $results = [];
        $queryLower = strtolower($query);

        foreach ($glossary->all() as $key => $metric) {
            $searchable = strtolower(implode(' ', [
                $key,
                $metric['name'],
                $metric['description'],
                $metric['category'],
                implode(' ', $metric['tags']),
            ]));

            if (str_contains($searchable, $queryLower)) {
                $results[$key] = $metric;
            }
        }

        if ($results === []) {
            $this->warn("No metrics found matching '{$query}'.");

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("🔍 Found " . count($results) . " metric(s) matching '{$query}':");
        $this->newLine();

        foreach ($results as $key => $metric) {
            $this->components->twoColumnDetail("<comment>{$key}</comment> — {$metric['name']}", '[' . $metric['category'] . ']');
            $this->line("  {$metric['formula']}");
        }

        return self::SUCCESS;
    }

    /**
     * Show which metrics consume a given event.
     */
    private function showMetricsForEvent(SaaSAnalyticsGlossaryService $glossary, string $event): int
    {
        $metrics = $glossary->metricsForEvent($event);

        if ($metrics === []) {
            $this->warn("No metrics found that use event '{$event}'.");

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line(json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("🔗 Metrics using event '{$event}':");
        $this->newLine();

        foreach ($metrics as $key => $name) {
            $this->components->twoColumnDetail($key, $name);
        }

        return self::SUCCESS;
    }

    /**
     * Show event-to-metric cross-reference map.
     */
    private function showCrossRef(SaaSAnalyticsGlossaryService $glossary): int
    {
        $map = $glossary->eventToMetricMap();

        if ($this->option('json')) {
            $this->line(json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('🔗 Event-to-Metric Cross-Reference (' . count($map) . ' events → ' . $glossary->count() . ' metrics):');
        $this->newLine();

        foreach ($map as $event => $metricKeys) {
            $this->components->twoColumnDetail("<comment>{$event}</comment>", implode(', ', $metricKeys));
        }

        return self::SUCCESS;
    }

    /**
     * Show coverage analysis.
     */
    private function showCoverage(SaaSAnalyticsGlossaryService $glossary): int
    {
        $analysis = $glossary->coverageAnalysis();

        if ($this->option('json')) {
            $this->line(json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('📈 Glossary Coverage Analysis');
        $this->newLine();
        $this->components->twoColumnDetail('Total Metrics', (string) $analysis['metric_count']);
        $this->components->twoColumnDetail('Covered (with source events)', count($analysis['covered']) . ' (' . $analysis['coverage_percent'] . '%)');
        $this->components->twoColumnDetail('Uncovered', count($analysis['uncovered']));
        $this->components->twoColumnDetail('Unique Events Used', (string) $analysis['event_count']);

        if ($analysis['uncovered'] !== []) {
            $this->newLine();
            $this->warn('Uncovered metrics (no source events defined):');
            foreach ($analysis['uncovered'] as $key) {
                $this->line("  • {$key}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * List all unique tags.
     */
    private function showTags(SaaSAnalyticsGlossaryService $glossary): int
    {
        $tags = [];

        foreach ($glossary->all() as $metric) {
            foreach ($metric['tags'] as $tag) {
                $tags[$tag] = ($tags[$tag] ?? 0) + 1;
            }
        }

        arsort($tags);

        if ($this->option('json')) {
            $this->line(json_encode($tags, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('🏷️  Glossary Tags (' . count($tags) . ' unique):');
        $this->newLine();

        foreach ($tags as $tag => $count) {
            $this->components->twoColumnDetail($tag, $count . ' metrics');
        }

        return self::SUCCESS;
    }

    /**
     * Show compact quick reference.
     */
    private function showCompact(SaaSAnalyticsGlossaryService $glossary): int
    {
        $ref = $glossary->quickReference();

        if ($this->option('json')) {
            $this->line(json_encode($ref, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('📋 Quick Reference — ' . count($ref) . ' metrics');
        $this->newLine();

        $headers = ['Key', 'Name', 'Category', 'Formula'];
        $rows = array_map(fn (array $r): array => [
            $r['key'],
            $r['name'],
            $r['category'],
            $r['formula'],
        ], $ref);

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * Get a category emoji for display.
     */
    private function emoji(string $category): string
    {
        return match ($category) {
            'Revenue' => '💰',
            'Growth' => '📈',
            'Unit Economics' => '📊',
            'Engagement' => '🎯',
            'Retention' => '🔁',
            'Funnel' => '🔻',
            default => '📌',
        };
    }
}
