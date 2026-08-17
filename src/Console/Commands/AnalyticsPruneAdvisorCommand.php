<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\EventPruningRecommendation;
use ZeroBoiler\Analytics\Services\EventPruningAdvisorService;

/**
 * Analytics Prune Advisor — recommend event removal, reduction, or consolidation.
 *
 * @since 220.0.0
 *
 * @see \ZeroBoiler\Analytics\Services\EventPruningAdvisorService
 */
final class AnalyticsPruneAdvisorCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'zb:analytics:prune-advisor
        {--action= : Filter by action type (remove, reduce_frequency, merge_with, sample_only)}
        {--high : Show only high-priority recommendations}
        {--consolidate : Show consolidation opportunities only}
        {--protected : List protected events that will never be pruned}
        {--top=20 : Limit output to top N recommendations}
        {--json : Output as JSON}
        {--fresh : Force fresh calculation (bypass cache)}
        {--invalidate : Clear prune advisor cache and exit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recommend event pruning based on signal-to-noise analysis';

    /**
     * Execute the console command.
     */
    public function handle(EventPruningAdvisorService $advisor): int
    {
        if ($this->option('invalidate')) {
            $advisor->invalidateCache();
            $this->info('Prune advisor cache invalidated.');

            return self::SUCCESS;
        }

        $fresh = (bool) $this->option('fresh');

        // Protected events mode
        if ($this->option('protected')) {
            return $this->showProtectedEvents($advisor);
        }

        // Consolidation mode
        if ($this->option('consolidate')) {
            return $this->showConsolidation($advisor);
        }

        // High-priority mode
        if ($this->option('high')) {
            return $this->showHighPriority($advisor);
        }

        // Action filter mode
        if ($this->option('action')) {
            return $this->showByAction($advisor, (string) $this->option('action'));
        }

        // Default: full report
        return $this->showFullReport($advisor, $fresh);
    }

    /**
     * Show protected events.
     */
    private function showProtectedEvents(EventPruningAdvisorService $advisor): int
    {
        $protected = $advisor->protectedEvents();

        if ($this->option('json')) {
            $this->line(json_encode(['protected_events' => $protected], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('🔒 Protected Events (never pruned):');
        $this->table(
            ['#', 'Event Name'],
            array_map(fn (int $i, string $name): array => [$i + 1, $name], array_keys($protected), $protected),
        );

        $this->newLine();
        $this->comment('These events are critical for business metrics and are excluded from pruning recommendations.');

        return self::SUCCESS;
    }

    /**
     * Show consolidation opportunities.
     */
    private function showConsolidation(EventPruningAdvisorService $advisor): int
    {
        $opportunities = $advisor->consolidationOpportunities();

        if ($this->option('json')) {
            $this->line(json_encode($opportunities, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if (empty($opportunities)) {
            $this->info('No consolidation opportunities found. Your event catalog is well-structured!');

            return self::SUCCESS;
        }

        $this->info('🔄 Consolidation Opportunities:');
        $this->table(
            ['Source Event', 'Target Event', 'Combined SNR', 'Est. Savings'],
            array_map(
                fn (array $opp): array => [
                    implode(', ', $opp['events']),
                    $opp['suggested_name'],
                    $opp['combined_snr'],
                    '$' . number_format($opp['estimated_savings'], 4),
                ],
                $opportunities,
            ),
        );

        return self::SUCCESS;
    }

    /**
     * Show high-priority recommendations.
     */
    private function showHighPriority(EventPruningAdvisorService $advisor): int
    {
        $recs = $advisor->highPriorityRecommendations();
        $recs = array_values($recs); // Re-index after filter

        if ($this->option('json')) {
            $this->line(json_encode(
                array_map(fn (EventPruningRecommendation $r): array => $r->toArray(), $recs),
                JSON_PRETTY_PRINT,
            ));

            return self::SUCCESS;
        }

        if (empty($recs)) {
            $this->info('No high-priority pruning recommendations. Your analytics stack is efficient!');

            return self::SUCCESS;
        }

        $this->warn('🔴 High-Priority Pruning Recommendations:');
        $this->displayRecommendations($recs);

        return self::SUCCESS;
    }

    /**
     * Show recommendations filtered by action type.
     */
    private function showByAction(EventPruningAdvisorService $advisor, string $action): int
    {
        $grouped = $advisor->groupedByAction();
        $recs = $grouped[$action] ?? [];

        if ($this->option('json')) {
            $this->line(json_encode(
                array_map(fn (EventPruningRecommendation $r): array => $r->toArray(), $recs),
                JSON_PRETTY_PRINT,
            ));

            return self::SUCCESS;
        }

        if (empty($recs)) {
            $this->info("No '{$action}' recommendations found.");

            return self::SUCCESS;
        }

        $this->info("Pruning Recommendations (action: {$action}):");
        $this->displayRecommendations($recs);

        return self::SUCCESS;
    }

    /**
     * Show full pruning report.
     */
    private function showFullReport(EventPruningAdvisorService $advisor, bool $fresh): int
    {
        $report = $advisor->report($fresh);

        if ($this->option('json')) {
            $jsonReport = $report;
            $jsonReport['recommendations'] = array_map(
                fn (EventPruningRecommendation $r): array => $r->toArray(),
                $report['recommendations'],
            );
            $this->line(json_encode($jsonReport, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        // Summary header
        $this->info('✂️ Event Pruning Advisor Report');
        $this->newLine();
        $this->line("  Total Recommendations: {$report['total_recommendations']}");
        $this->line("  High Priority:    {$report['high_priority']}");
        $this->line("  Medium Priority:  {$report['medium_priority']}");
        $this->line("  Low Priority:     {$report['low_priority']}");
        $this->newLine();
        $this->line("  Est. Monthly Savings: \${$report['estimated_monthly_savings']}");
        $this->line("  Noise Ratio:           {$report['noise_ratio']}%");
        $this->newLine();

        // Action breakdown
        $breakdown = $report['action_breakdown'];
        $this->info('  Action Breakdown:');
        foreach ($breakdown as $action => $count) {
            if ($count > 0) {
                $label = str_replace('_', ' ', $action);
                $this->line("    - {$label}: {$count}");
            }
        }

        $this->newLine();

        // Recommendations table
        if (! empty($report['recommendations'])) {
            $top = array_slice($report['recommendations'], 0, (int) $this->option('top'));
            $this->warn('  Recommendations:');
            $this->displayRecommendations($top);
        } else {
            $this->info('  ✅ No pruning recommendations. Your event catalog is optimized!');
        }

        // Consolidation opportunities
        if (! empty($report['consolidation_opportunities'])) {
            $this->newLine();
            $this->info('  Consolidation Opportunities:');
            $this->table(
                ['Source → Target', 'Combined SNR', 'Est. Savings'],
                array_map(
                    fn (array $opp): array => [
                        implode(' → ', $opp['events']),
                        $opp['combined_snr'],
                        '$' . number_format($opp['estimated_savings'], 4),
                    ],
                    $report['consolidation_opportunities'],
                ),
            );
        }

        $this->newLine();
        $this->comment("  Run 'zb:analytics:snr --noise' to see all noise events.");
        $this->comment("  Computed at: {$report['computed_at']}");

        return self::SUCCESS;
    }

    /**
     * Display recommendations as a table.
     *
     * @param  list<EventPruningRecommendation>  $recs
     */
    private function displayRecommendations(array $recs): void
    {
        $this->table(
            ['Event', 'Action', 'Priority', 'SNR', 'Savings', 'Rationale'],
            array_map(
                fn (EventPruningRecommendation $r): array => [
                    $r->eventName,
                    $r->action,
                    strtoupper($r->priority),
                    $r->snr,
                    '$' . number_format($r->estimatedSavings, 4),
                    strlen($r->rationale) > 60 ? substr($r->rationale, 0, 57) . '...' : $r->rationale,
                ],
                $recs,
            ),
        );
    }
}
