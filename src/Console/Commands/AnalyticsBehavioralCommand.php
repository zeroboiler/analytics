<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\BehavioralCohortBuilder;
use ZeroBoiler\Analytics\Services\EventRulesEngine;
use ZeroBoiler\Analytics\Services\RetentionCalculator;
use ZeroBoiler\Analytics\Services\UserPropertiesStore;

/**
 * Display behavioral analytics insights: retention, stickiness, cohorts, rules.
 *
 * Shows N-Day retention metrics, DAU/WAU/MAU stickiness, behavioral cohort
 * distribution, event rules engine status, and user properties store schema.
 *
 * Useful for monitoring product health and identifying at-risk users.
 *
 * @since 1.0.0
 */
final class AnalyticsBehavioralCommand extends Command
{
    protected $signature = 'zb:analytics:behavioral
        {--retention : Show retention metrics only}
        {--stickiness : Show stickiness metrics only}
        {--cohorts : Show behavioral cohort distribution only}
        {--rules : Show event rules engine status only}
        {--properties : Show user properties store schema only}
        {--date= : Cohort date for retention (YYYY-MM-DD, default: today)}
        {--days=7 : Number of cohort days for comparison}';

    protected $description = 'Display behavioral analytics: retention, stickiness, cohorts, rules';

    #[Override]
    public function handle(): int
    {
        $this->info('🧠 ZeroBoiler Behavioral Analytics');
        $this->newLine();

        $anySection = false;

        if ($this->option('retention') || ! $this->option('stickiness') && ! $this->option('cohorts') && ! $this->option('rules') && ! $this->option('properties')) {
            $this->showRetention();
            $anySection = true;
        }

        if ($this->option('stickiness') || (! $this->option('retention') && ! $this->option('cohorts') && ! $this->option('rules') && ! $this->option('properties') && ! $this->anyOption())) {
            $this->showStickiness();
            $anySection = true;
        }

        if ($this->option('cohorts')) {
            $this->showCohorts();
            $anySection = true;
        }

        if ($this->option('rules')) {
            $this->showRules();
            $anySection = true;
        }

        if ($this->option('properties')) {
            $this->showProperties();
            $anySection = true;
        }

        if (! $anySection) {
            $this->showRetention();
            $this->showStickiness();
            $this->showCohorts();
            $this->showRules();
            $this->showProperties();
        }

        return self::SUCCESS;
    }

    /**
     * Check if any specific section option was passed.
     */
    private function anyOption(): bool
    {
        return $this->option('retention')
            || $this->option('stickiness')
            || $this->option('cohorts')
            || $this->option('rules')
            || $this->option('properties');
    }

    /**
     * Display retention metrics.
     */
    private function showRetention(): void
    {
        $this->line('<fg=cyan;options=bold>RETENTION & COHORT ANALYSIS</>');
        $this->line('─────────────────────────────────');

        /** @var RetentionCalculator $calc */
        $calc = app(RetentionCalculator::class);

        if (! $calc->isEnabled()) {
            $this->warn('    ⚠️  Retention calculator is disabled');
            $this->newLine();

            return;
        }

        $date = $this->option('date') ?? gmdate('Y-m-d');
        $days = (int) $this->option('days');

        // Overall retention
        $retention = $calc->retention();
        $this->line('    <fg=white;options=bold>Overall Retention:</>');
        $this->line('    Day 0 users: ' . $retention['day0_users']);

        foreach ($retention['retention'] as $day => $rate) {
            $rateStr = $rate !== null ? $rate . '%' : 'N/A';
            $bar = $this->retentionBar($rate);
            $this->line("    D{$day}: {$rateStr} {$bar}");
        }

        $this->newLine();

        // Cohort-specific retention
        $cohortRetention = $calc->retention($date);
        $this->line("    <fg=white;options=bold>Cohort ({$date}):</>");
        $this->line('    Day 0 users: ' . $cohortRetention['day0_users']);

        foreach ($cohortRetention['retention'] as $day => $rate) {
            $rateStr = $rate !== null ? $rate . '%' : 'N/A';
            $bar = $this->retentionBar($rate);
            $this->line("    D{$day}: {$rateStr} {$bar}");
        }

        $this->newLine();

        // Cohort comparison
        if ($days > 1) {
            $comparison = $calc->cohortComparison($days);
            $this->line("    <fg=white;options=bold>{$days}-Day Cohort Averages:</>");

            foreach ($comparison['averages'] as $day => $avg) {
                $avgStr = $avg !== null ? $avg . '%' : 'N/A';
                $this->line("    D{$day} avg: {$avgStr}");
            }

            $this->line('    Cohorts analyzed: ' . count($comparison['cohorts']));
            $this->newLine();
        }
    }

    /**
     * Display stickiness metrics.
     */
    private function showStickiness(): void
    {
        $this->line('<fg=cyan;options=bold>STICKINESS (DAU/MAU)</>');
        $this->line('─────────────────────────────────');

        /** @var RetentionCalculator $calc */
        $calc = app(RetentionCalculator::class);

        if (! $calc->isEnabled()) {
            $this->warn('    ⚠️  Retention calculator is disabled');
            $this->newLine();

            return;
        }

        $date = $this->option('date');
        $stickiness = $calc->stickiness(is_string($date) ? $date : null);

        $this->line('    Reference: ' . $stickiness['reference_date']);
        $this->line('    DAU: ' . number_format($stickiness['dau']));
        $this->line('    WAU: ' . number_format($stickiness['wau']));
        $this->line('    MAU: ' . number_format($stickiness['mau']));
        $this->line('    DAU/WAU: ' . $stickiness['dau_wau_ratio'] . '%');
        $this->line('    DAU/MAU: ' . $stickiness['dau_mau_ratio'] . '%');
        $this->line('    WAU/MAU: ' . $stickiness['wau_mau_ratio'] . '%');
        $this->line('    Grade: <fg=green;options=bold>' . $stickiness['grade'] . '</>');
        $this->newLine();
    }

    /**
     * Display behavioral cohort distribution.
     */
    private function showCohorts(): void
    {
        $this->line('<fg=cyan;options=bold>BEHAVIORAL COHORTS</>');
        $this->line('─────────────────────────────────');

        /** @var BehavioralCohortBuilder $builder */
        $builder = app(BehavioralCohortBuilder::class);

        if (! $builder->isEnabled()) {
            $this->warn('    ⚠️  Behavioral cohort builder is disabled');
            $this->newLine();

            return;
        }

        $result = $builder->classify();

        $this->line('    Generated: ' . $result['generated_at']);
        $this->line('    Total users: ' . $result['total_users']);
        $this->newLine();

        foreach ($result['segments'] as $key => $segment) {
            $count = $segment['user_count'];
            $pct = $segment['percentage'];
            $bar = str_repeat('█', (int) ($pct / 2)) . str_repeat('░', 50 - (int) ($pct / 2));
            $this->line("    {$segment['label']}:");
            $this->line("      {$count} users ({$pct}%)");
            $this->line("      <fg=magenta>{$bar}</>");
        }

        $this->newLine();
    }

    /**
     * Display event rules engine status.
     */
    private function showRules(): void
    {
        $this->line('<fg=cyan;options=bold>EVENT RULES ENGINE</>');
        $this->line('─────────────────────────────────');

        /** @var EventRulesEngine $engine */
        $engine = app(EventRulesEngine::class);

        if (! $engine->isEnabled()) {
            $this->warn('    ⚠️  Rules engine is disabled');
            $this->line('    Enable in config: ANALYTICS_RULES_ENABLED=true');
            $this->newLine();

            return;
        }

        $rules = $engine->rules();
        $counts = $engine->triggerCounts();

        $this->line('    Status: <fg=green>✅ Enabled</>');
        $this->line('    Rules loaded: ' . count($rules));

        if ($rules === []) {
            $this->line('    No rules configured. Add to config: zeroboiler.analytics.rules.rules');
        } else {
            $typeCounts = array_count_values(array_map(
                fn (array $r): string => $r['type'],
                $rules,
            ));

            foreach ($typeCounts as $type => $count) {
                $this->line("    {$type}: {$count}");
            }

            $this->newLine();
            $this->line('    <fg=white;options=bold>Trigger Counts:</>');

            if ($counts === []) {
                $this->line('      No triggers yet');
            } else {
                foreach ($counts as $ruleId => $count) {
                    $this->line("      {$ruleId}: {$count}");
                }
            }
        }

        $this->newLine();
    }

    /**
     * Display user properties store schema.
     */
    private function showProperties(): void
    {
        $this->line('<fg=cyan;options=bold>USER PROPERTIES STORE</>');
        $this->line('─────────────────────────────────');

        /** @var UserPropertiesStore $store */
        $store = app(UserPropertiesStore::class);

        if (! $store->isEnabled()) {
            $this->warn('    ⚠️  User properties store is disabled');
            $this->newLine();

            return;
        }

        $this->line('    Status: <fg=green>✅ Enabled</>');

        $schema = $store->schema();

        if ($schema === []) {
            $this->line('    No schema defined (accepts any properties)');
        } else {
            $this->newLine();
            $this->line('    <fg=white;options=bold>Schema:</>');
            foreach ($schema as $key => $def) {
                $type = $def['type'] ?? 'string';
                $aggregation = $def['aggregation'] ?? 'last';
                $this->line("      {$key}: <fg=yellow>{$type}</> ({$aggregation})");
            }
        }

        $this->newLine();
    }

    /**
     * Generate a visual retention bar.
     */
    private function retentionBar(?float $rate): string
    {
        if ($rate === null) {
            return '';
        }

        $filled = (int) ($rate / 2);
        $empty = 50 - $filled;

        if ($rate >= 40) {
            return '<fg=green>' . str_repeat('█', $filled) . '</><fg=dark_gray>' . str_repeat('░', $empty) . '</>';
        }

        if ($rate >= 20) {
            return '<fg=yellow>' . str_repeat('█', $filled) . '</><fg=dark_gray>' . str_repeat('░', $empty) . '</>';
        }

        return '<fg=red>' . str_repeat('█', $filled) . '</><fg=dark_gray>' . str_repeat('░', $empty) . '</>';
    }
}
