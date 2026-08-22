<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\MetricComputationRequest;
use ZeroBoiler\Analytics\Services\AnalyticsSemanticMetricsService;
use ZeroBoiler\Analytics\Facades\Analytics;

/**
 * Analytics Semantic Metrics — manage and query the Semantic Metrics Layer.
 *
 * Provides CLI access to the metrics definition registry and computation engine.
 * Supports listing, showing details, computing values, validating definitions,
 * and managing custom metrics.
 *
 * @since 233.0.0
 */
final class AnalyticsSemanticMetricsCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:semantic-metrics
        {action? : Action to perform (list|show|compute|summary|validate|categories|types|derived)}
        {--name= : Metric name (for show/compute actions)}
        {--category= : Filter by category}
        {--type= : Filter by type}
        {--json : JSON output}
        {--days=30 : Time period in days for compute}
        {--granularity=day : Time granularity (minute|hour|day|week|month)}
        {--compare : Include period comparison in compute}
        {--timeseries : Include time series in compute}
        {--invalidate : Invalidate cache}
        {--fresh : Force fresh computation (bypass cache)}';

    /** @var string */
    protected $description = 'Query and manage the Semantic Metrics Layer';

    public function __construct(
        private readonly AnalyticsSemanticMetricsService $metricsService,
    ){
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = $this->argument('action') ?? 'list';

        if ($this->option('invalidate')) {
            return $this->invalidateCache();
        }

        return match ($action) {
            'list' => $this->listMetrics(),
            'show' => $this->showMetric(),
            'compute' => $this->computeMetric(),
            'summary' => $this->showSummary(),
            'validate' => $this->validateMetrics(),
            'categories' => $this->showCategories(),
            'types' => $this->showTypes(),
            'derived' => $this->showDerived(),
            default => $this->unknownAction($action),
        };
    }

    /**
     * List all registered metrics.
     */
    private function listMetrics(): int
    {
        $categoryFilter = $this->option('category');
        $typeFilter = $this->option('type');

        if ($categoryFilter !== null) {
            $metrics = $this->metricsService->byCategory();
            $metrics = $metrics[$categoryFilter] ?? [];
        } else {
            $metrics = $this->metricsService->all();
        }

        if ($this->option('json')) {
            $output = [];
            foreach ($metrics as $name => $definition) {
                if ($typeFilter !== null && $definition->type !== $typeFilter) {
                    continue;
                }
                $output[] = $definition->toArray();
            }
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($metrics as $name => $definition) {
            if ($typeFilter !== null && $definition->type !== $typeFilter) {
                continue;
            }
            $rows[] = [
                $name,
                $definition->type,
                $definition->label,
                $definition->category ?? '-',
                implode(', ', $definition->sourceEvents),
                $definition->isValid() ? '✓' : '✗',
            ];
        }

        $this->table(
            ['Name', 'Type', 'Label', 'Category', 'Source Events', 'Valid'],
            $rows,
        );

        $this->info("Total: {$this->metricsService->count()} metrics registered");

        return self::SUCCESS;
    }

    /**
     * Show details for a specific metric.
     */
    private function showMetric(): int
    {
        $name = $this->option('name');

        if ($name === null) {
            $this->error('--name is required for show action');
            return self::FAILURE;
        }

        $definition = $this->metricsService->get($name);

        if ($definition === null) {
            $this->error("Metric '{$name}' not found");
            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($definition->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->info("📊 {$definition->label} ({$definition->name})");
        $this->newLine();
        $this->line("  Type:       {$definition->type}");
        $this->line("  Category:   " . ($definition->category ?? '-'));
        $this->line("  Unit:       " . ($definition->unit ?? '-'));
        $this->line("  Valid:      " . ($definition->isValid() ? '✓' : '✗'));
        $this->line("  Derived:    " . ($definition->isDerived() ? 'Yes' : 'No'));
        $this->line("  Events:     " . implode(', ', $definition->sourceEvents));

        if ($definition->measureField !== null) {
            $this->line("  Measure:    {$definition->measureField}");
        }

        if ($definition->isDerived()) {
            $this->line("  Numerator:  {$definition->ratioNumerator}");
            $this->line("  Denominator: {$definition->ratioDenominator}");
        }

        if (!empty($definition->dimensions)) {
            $this->line("  Dimensions: " . implode(', ', $definition->dimensionNames()));
        }

        $this->newLine();
        $this->line("  {$definition->description}");

        return self::SUCCESS;
    }

    /**
     * Compute a metric value.
     */
    private function computeMetric(): int
    {
        $name = $this->option('name');

        if ($name === null) {
            $this->error('--name is required for compute action');
            return self::FAILURE;
        }

        $days = (int) $this->option('days');
        $granularity = (string) $this->option('granularity');

        $request = new MetricComputationRequest(
            metricName: $name,
            periodStart: new \DateTimeImmutable("-{$days} days"),
            periodEnd: new \DateTimeImmutable(),
            granularity: $granularity,
            includeComparison: (bool) $this->option('compare'),
            includeTimeSeries: (bool) $this->option('timeseries'),
        );

        $result = $this->metricsService->compute($request);

        if ($this->option('json')) {
            $this->line(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->info("📊 {$result->metricName}");
        $this->newLine();
        $this->line("  Value:        {$result->formatted()}");
        $this->line("  Unit:         " . ($result->unit ?? '-'));
        $this->line("  Period:       {$result->periodStart?->format('Y-m-d')} → {$result->periodEnd?->format('Y-m-d')}");
        $this->line("  Granularity:  {$result->granularity}");
        $this->line("  Source Events: {$result->sourceEventCount}");
        $this->line("  Computed At:  {$result->computedAt?->format('Y-m-d H:i:s')}");

        if ($result->hasComparison()) {
            $direction = $result->changeDirection();
            $emoji = match ($direction) {
                'up' => '📈',
                'down' => '📉',
                'new' => '🆕',
                default => '➡️',
            };
            $comparison = $result->comparison;
            $this->line("  {$emoji} vs prev: {$comparison['change_percentage']}%");

            return self::SUCCESS;
        }

        if ($result->hasBreakdowns()) {
            $this->newLine();
            $this->info("  Dimensional Breakdowns:");
            $rows = [];
            foreach ($result->breakdowns as $bd) {
                $rows[] = [
                    $bd['dimension'],
                    $bd['value'],
                    number_format($bd['metric_value'], 2),
                    $bd['percentage'] . '%',
                ];
            }
            $this->table(['Dimension', 'Value', 'Metric Value', '%'], $rows);
        }

        return self::SUCCESS;
    }

    /**
     * Show metrics summary.
     */
    private function showSummary(): int
    {
        $summary = $this->metricsService->summary();

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->info('📊 Semantic Metrics Layer Summary');
        $this->newLine();
        $this->line("  Total Metrics:   {$summary['total']}");
        $this->line("  Derived (Ratio): {$summary['derived']}");
        $this->line("  Raw:            {$summary['raw']}");
        $this->newLine();

        $this->info('  By Category:');
        foreach ($summary['categories'] as $cat => $count) {
            $this->line("    {$cat}: {$count}");
        }

        $this->newLine();
        $this->info('  By Type:');
        foreach ($summary['types'] as $type => $count) {
            $this->line("    {$type}: {$count}");
        }

        return self::SUCCESS;
    }

    /**
     * Validate all metric definitions.
     */
    private function validateMetrics(): int
    {
        $validation = $this->metricsService->validate();

        if ($this->option('json')) {
            $this->line(json_encode($validation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $validCount = count($validation['valid']);
        $invalidCount = count($validation['invalid']);

        $this->info("✅ Valid: {$validCount} metrics");
        $this->info("❌ Invalid: {$invalidCount} metrics");

        if (!empty($validation['invalid'])) {
            $this->newLine();
            $this->warn('Invalid metrics:');
            foreach ($validation['invalid'] as $name => $reason) {
                $this->line("  • {$name}: {$reason}");
            }
        }

        return $invalidCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Show metric categories.
     */
    private function showCategories(): int
    {
        $categories = $this->metricsService->byCategory();

        if ($this->option('json')) {
            $output = [];
            foreach ($categories as $cat => $metrics) {
                $output[] = [
                    'category' => $cat,
                    'count' => count($metrics),
                    'metrics' => array_keys($metrics),
                ];
            }
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($categories as $cat => $metrics) {
            $rows[] = [$cat, count($metrics), implode(', ', array_keys($metrics))];
        }
        $this->table(['Category', 'Count', 'Metrics'], $rows);

        return self::SUCCESS;
    }

    /**
     * Show metrics grouped by type.
     */
    private function showTypes(): int
    {
        $types = [];
        foreach ($this->metricsService->all() as $definition) {
            $types[$definition->type][] = $definition->name;
        }

        if ($this->option('json')) {
            $output = [];
            foreach ($types as $type => $names) {
                $output[] = [
                    'type' => $type,
                    'count' => count($names),
                    'metrics' => $names,
                ];
            }
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($types as $type => $names) {
            $rows[] = [$type, count($names), implode(', ', $names)];
        }
        $this->table(['Type', 'Count', 'Metrics'], $rows);

        return self::SUCCESS;
    }

    /**
     * Show derived metrics.
     */
    private function showDerived(): int
    {
        $derived = $this->metricsService->derivedMetrics();

        if ($this->option('json')) {
            $output = [];
            foreach ($derived as $definition) {
                $output[] = $definition->toArray();
            }
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($derived as $definition) {
            $rows[] = [
                $definition->name,
                $definition->label,
                $definition->ratioNumerator ?? '-',
                $definition->ratioDenominator ?? '-',
            ];
        }
        $this->table(['Name', 'Label', 'Numerator', 'Denominator'], $rows);

        return self::SUCCESS;
    }

    /**
     * Handle unknown action.
     */
    private function unknownAction(string $action): int
    {
        $this->error("Unknown action: {$action}");
        $this->line('Available actions: list, show, compute, summary, validate, categories, types, derived');
        $this->line('Special options: --invalidate, --json');

        return self::FAILURE;
    }

    /**
     * Invalidate metric caches.
     */
    private function invalidateCache(): int
    {
        $name = $this->option('name');

        if ($name !== null) {
            $this->metricsService->invalidateCache($name);
            $this->info("Cache invalidated for metric: {$name}");
        } else {
            $this->metricsService->invalidateAllCache();
            $this->info('All metric caches invalidated');
        }

        return self::SUCCESS;
    }
}
