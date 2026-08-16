<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\EventIntelligenceCopilotService;

/**
 * Analytics Intelligence Copilot command.
 *
 * Generates executive-level analytics intelligence summaries, category analysis,
 * provider health comparisons, volume spike detection, and lifecycle funnel insights.
 *
 * @since 199.0.0
 * @see \ZeroBoiler\Analytics\Services\EventIntelligenceCopilotService
 */
final class AnalyticsCopilotCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:copilot
        {action? : Action to perform (summary|category|spikes|providers|lifecycle|config|clear)}
        {--category= : Category name for category action}
        {--json : Output as JSON}';

    /** @var string */
    protected $description = 'Analytics Intelligence Copilot — executive summary and insights';

    /**
     * Execute the console command.
     */
    public function handle(EventIntelligenceCopilotService $copilot): int
    {
        $action = $this->argument('action') ?? 'summary';
        $asJson = (bool) $this->option('json');

        return match ($action) {
            'summary' => $this->actionSummary($copilot, $asJson),
            'category' => $this->actionCategory($copilot, $asJson),
            'spikes' => $this->actionSpikes($copilot, $asJson),
            'providers' => $this->actionProviders($copilot, $asJson),
            'lifecycle' => $this->actionLifecycle($copilot, $asJson),
            'config' => $this->actionConfig($copilot, $asJson),
            'clear' => $this->actionClear($copilot),
            default => $this->invalidAction($action),
        };
    }

    /**
     * Generate full executive summary.
     */
    private function actionSummary(EventIntelligenceCopilotService $copilot, bool $asJson): int
    {
        $summary = $copilot->generateSummary();

        if ($asJson) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('═══ Analytics Intelligence Copilot ═══');
        $this->newLine();
        $this->line("Generated: {$summary['generated_at']}");
        $this->line("Health Score: {$summary['health_score']} (Grade: {$summary['health_grade']})");
        $this->line("Events Tracked: {$summary['total_events_tracked']}");
        $this->line("Providers: {$summary['total_providers']}");
        $this->line("Categories: {$summary['total_categories']}");
        $this->newLine();

        // Catalog Intelligence
        $cat = $summary['catalog_intelligence'];
        $this->info('── Catalog Intelligence ──');
        $this->line("  Coverage Score: {$cat['coverage_score']}% ({$cat['grade']})");
        $this->line("  Avg Provider Coverage: " . ($cat['avg_provider_coverage'] * 100) . '%');
        $this->line("  Uncategorized Events: {$cat['uncategorized_events']}");
        $this->newLine();

        // Provider Intelligence
        $prov = $summary['provider_intelligence'];
        $this->info('── Provider Intelligence ──');
        $this->line("  Avg Coverage: {$prov['avg_coverage']}%");
        $this->line("  Weakest: " . ($prov['weakest_provider'] ?? 'N/A'));
        $this->line("  Strongest: " . ($prov['strongest_provider'] ?? 'N/A'));
        $this->newLine();

        // Lifecycle Intelligence
        $life = $summary['lifecycle_intelligence'];
        $this->info('── Lifecycle Intelligence ──');
        $this->line("  SaaS Events: {$life['total_saas_events']}");
        $this->line("  Bottleneck: " . ($life['bottleneck'] ?? 'N/A'));
        $this->line("  Healthiest: " . ($life['healthiest_stage'] ?? 'N/A'));
        $this->newLine();

        // Recommendations
        $recs = $summary['recommendations'];
        $this->info('── Recommendations ──');
        if ($recs === []) {
            $this->line('  No recommendations — everything looks great!');
        } else {
            foreach ($recs as $rec) {
                $priority = strtoupper($rec['priority']);
                $this->line("  [{$priority}] {$rec['title']}");
                $this->line("    {$rec['description']}");
                $this->line("    Impact: {$rec['impact']}");
                $this->newLine();
            }
        }

        return self::SUCCESS;
    }

    /**
     * Analyze a specific category.
     */
    private function actionCategory(EventIntelligenceCopilotService $copilot, bool $asJson): int
    {
        $category = $this->option('category');

        if ($category === null) {
            $this->error('Please specify a category with --category=Name');

            return self::FAILURE;
        }

        $result = $copilot->categorySummary($category);

        if ($asJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("═══ Category: {$category} ═══");
        $this->line("  Events: {$result['event_count']}");
        $this->line("  Provider Coverage: " . ($result['provider_coverage'] * 100) . '%');
        $this->line("  Health: {$result['health']}%");
        $this->newLine();

        if ($result['top_events'] !== []) {
            $this->info('  Top Events:');
            foreach ($result['top_events'] as $event) {
                $this->line("    - {$event['name']} ({$event['provider_count']} providers)");
            }
        }

        if ($result['gaps'] !== []) {
            $this->newLine();
            $this->warn('  Gaps:');
            foreach ($result['gaps'] as $gap) {
                $this->line("    ! {$gap}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Detect volume spikes.
     */
    private function actionSpikes(EventIntelligenceCopilotService $copilot, bool $asJson): int
    {
        $result = $copilot->detectVolumeSpikes();

        if ($asJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('═══ Volume Spike Detection ═══');
        $this->line("  Categories Analyzed: {$result['total_categories_analyzed']}");
        $this->newLine();

        if ($result['spikes'] === []) {
            $this->line('  No volume spikes detected.');

            return self::SUCCESS;
        }

        foreach ($result['spikes'] as $spike) {
            $severity = strtoupper($spike['severity']);
            $this->warn("  [{$severity}] {$spike['category']}");
            $this->line("    Current: {$spike['current_volume']}, Expected: {$spike['expected_volume']}, Ratio: {$spike['ratio']}x");
        }

        return self::SUCCESS;
    }

    /**
     * Provider health comparison.
     */
    private function actionProviders(EventIntelligenceCopilotService $copilot, bool $asJson): int
    {
        $result = $copilot->providerHealthComparison();

        if ($asJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('═══ Provider Health Comparison ═══');
        $this->line("  Total Providers: {$result['summary']['total_enabled']}");
        $this->line("  Avg Coverage: {$result['summary']['avg_coverage']}%");
        $this->line("  Weakest: " . ($result['summary']['weakest_provider'] ?? 'N/A'));
        $this->line("  Strongest: " . ($result['summary']['strongest_provider'] ?? 'N/A'));
        $this->newLine();

        $this->info('  Provider Breakdown:');
        foreach ($result['providers'] as $name => $data) {
            $health = $data['health_estimate'];
            $grade = $health >= 90 ? 'A' : ($health >= 70 ? 'B' : ($health >= 50 ? 'C' : 'D'));
            $this->line("    {$name}: {$data['catalog_coverage_pct']}% coverage (Grade: {$grade})");
        }

        return self::SUCCESS;
    }

    /**
     * Lifecycle funnel intelligence.
     */
    private function actionLifecycle(EventIntelligenceCopilotService $copilot, bool $asJson): int
    {
        $result = $copilot->lifecycleFunnelIntelligence();

        if ($asJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('═══ SaaS Lifecycle Funnel Intelligence ═══');
        $this->line("  Total SaaS Events: {$result['total_lifecycle_events']}");
        $this->line("  Bottleneck Stage: " . ($result['bottleneck_stage'] ?? 'N/A'));
        $this->line("  Healthiest Stage: " . ($result['healthiest_stage'] ?? 'N/A'));
        $this->newLine();

        $this->info('  Stage Distribution:');
        foreach ($result['stages'] as $stage) {
            $bar = str_repeat('█', (int) round($stage['percentage'] / 5));
            $this->line("    {$stage['stage']}: {$stage['event_count']} events ({$stage['percentage']}%) {$bar}");
        }

        return self::SUCCESS;
    }

    /**
     * Show config summary.
     */
    private function actionConfig(EventIntelligenceCopilotService $copilot, bool $asJson): int
    {
        $result = $copilot->configSummary();

        if ($asJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('═══ Intelligence Copilot Configuration ═══');
        foreach ($result as $key => $value) {
            $this->line("  {$key}: " . (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value));
        }

        return self::SUCCESS;
    }

    /**
     * Clear copilot cache.
     */
    private function actionClear(EventIntelligenceCopilotService $copilot): int
    {
        $success = $copilot->clearCache();

        if ($success) {
            $this->info('Intelligence Copilot cache cleared successfully.');

            return self::SUCCESS;
        }

        $this->error('Failed to clear cache.');

        return self::FAILURE;
    }

    /**
     * Handle invalid action.
     */
    private function invalidAction(string $action): int
    {
        $this->error("Invalid action: '{$action}'");
        $this->line('Available actions: summary, category, spikes, providers, lifecycle, config, clear');

        return self::FAILURE;
    }
}
