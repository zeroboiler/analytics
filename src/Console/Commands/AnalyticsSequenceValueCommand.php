<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\EventSequencePattern;
use ZeroBoiler\Analytics\Services\EventSequenceValueAttributionService;

/**
 * Event Sequence Value Attribution CLI command.
 *
 * Analyzes and ranks user journey sequences by their business value,
 * identifying the highest-impact funnels and dead-end paths.
 *
 * Usage:
 *   php artisan analytics:sequence-value                    # Show top 10 value sequences
 *   php artisan analytics:sequence-value --top=5            # Show top 5
 *   php artisan analytics:sequence-value --negative        # Show negative-value sequences
 *   php artisan analytics:sequence-value --matrix           # Show full attribution matrix
 *   php artisan analytics:sequence-value --compare=sign_up→trial→purchase,sign_up→trial→cancel  # Compare two paths
 *   php artisan analytics:sequence-value --multipliers     # Show event revenue multipliers
 *   php artisan analytics:sequence-value --demo            # Demo with sample SaaS sequences
 *
 * @since 212.0.0
 */
final class AnalyticsSequenceValueCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:sequence-value
        {--top=10 : Number of top-value sequences to display}
        {--negative : Show only negative-value (churn) sequences}
        {--matrix : Show full attribution matrix with grade distribution}
        {--compare= : Compare two comma-separated sequences (use → as separator)}
        {--multipliers : Show all event revenue multipliers}
        {--demo : Run demo with sample SaaS sequences}
        {--json : Output as JSON}';

    /** @var string */
    protected $description = 'Analyze and rank user journey sequences by business value attribution';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $service = new EventSequenceValueAttributionService();

        if ($this->option('multipliers')) {
            return $this->showMultipliers($service);
        }

        if ($this->option('compare')) {
            return $this->compareSequences($service);
        }

        // Load patterns from demo data (in production, from cache/event store)
        $patterns = $this->loadPatterns();

        if ($this->option('negative')) {
            return $this->showNegativeSequences($service, $patterns);
        }

        if ($this->option('matrix')) {
            return $this->showMatrix($service, $patterns);
        }

        return $this->showTopSequences($service, $patterns, (int) $this->option('top'));
    }

    /**
     * Display top value sequences.
     *
     * @param  list<EventSequencePattern>  $patterns
     */
    private function showTopSequences(EventSequenceValueAttributionService $service, array $patterns, int $top): int
    {
        $attributions = $service->topValueSequences($patterns, $top);

        if (empty($attributions)) {
            $this->info('No sequence patterns found to attribute.');

            return self::SUCCESS;
        }

        $this->info("\n  🏆 Top {$top} Highest-Value User Sequences\n");

        $headers = ['#', 'Sequence', 'Grade', 'Score', 'LTV', 'ROI', 'Conv. Lift', 'Occ.'];

        $rows = [];
        foreach ($attributions as $i => $attr) {
            $rows[] = [
                $i + 1,
                implode(' → ', $attr->sequence),
                $attr->valueGrade,
                number_format($attr->compositeScore, 3),
                '$' . number_format($attr->avgLtv, 0),
                number_format($attr->sequenceRoi, 1) . 'x',
                ($attr->conversionLift >= 0 ? '+' : '') . number_format($attr->conversionLift * 100, 1) . '%',
                number_format($attr->occurrences),
            ];
        }

        $this->table($headers, $rows);

        $this->info("\n  Grade Scale: S (top 5%) → A (top 20%) → B (top 50%) → C (bottom 50%) → D (bottom 10%)\n");

        return self::SUCCESS;
    }

    /**
     * Display negative-value sequences.
     *
     * @param  list<EventSequencePattern>  $patterns
     */
    private function showNegativeSequences(EventSequenceValueAttributionService $service, array $patterns): int
    {
        $negatives = $service->negativeValueSequences($patterns);

        if (empty($negatives)) {
            $this->info('No negative-value sequences detected.');

            return self::SUCCESS;
        }

        $this->warn("\n  ⚠️  Negative-Value Sequences (Churn/Revenue Leak)\n");

        $headers = ['#', 'Sequence', 'Score', 'Grade', 'Warning'];

        $rows = [];
        foreach ($negatives as $i => $neg) {
            $rows[] = [
                $i + 1,
                implode(' → ', $neg['sequence']),
                number_format($neg['composite_score'], 3),
                $neg['value_grade'],
                $neg['warning'],
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * Display full attribution matrix.
     *
     * @param  list<EventSequencePattern>  $patterns
     */
    private function showMatrix(EventSequenceValueAttributionService $service, array $patterns): int
    {
        $matrix = $service->attributeMatrix($patterns);

        $this->info("\n  📊 Event Sequence Value Attribution Matrix\n");

        // Summary
        $summary = $matrix['summary'];
        $this->line("  Total Sequences:  {$summary['total_sequences']}");
        $topPath = $summary['top_path'] ?? 'N/A';
        $highestLtv = $summary['highest_ltv_path'] ?? 'N/A';
        $fastestPath = $summary['fastest_path'] ?? 'N/A';
        $this->line("  Top Path:         {$topPath}");
        $this->line("  Avg Score:        " . number_format($summary['avg_score'], 4));
        $this->line("  Highest LTV Path: {$highestLtv}");
        $this->line("  Fastest Path:     {$fastestPath}");

        // Grade distribution
        $grades = $summary['grade_distribution'];
        $this->newLine();
        $this->line('  Grade Distribution:');

        foreach (['S', 'A', 'B', 'C', 'D'] as $grade) {
            $count = $grades[$grade] ?? 0;
            $bar = str_repeat('█', min(50, $count * 2));
            $this->line("    {$grade}: {$count}  {$bar}");
        }

        // Table
        $this->newLine();
        $headers = ['#', 'Sequence', 'Grade', 'Score', 'LTV', 'ROI', 'Conv.'];

        $rows = [];
        foreach ($matrix['attributions'] as $i => $attr) {
            $rows[] = [
                $i + 1,
                implode(' → ', $attr['sequence']),
                $attr['value_grade'],
                number_format($attr['composite_score'], 3),
                '$' . number_format($attr['avg_ltv'], 0),
                number_format($attr['sequence_roi'], 1) . 'x',
                number_format($attr['conversion_lift'] * 100, 1) . '%',
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * Compare two sequences.
     */
    private function compareSequences(EventSequenceValueAttributionService $service): int
    {
        $raw = $this->option('compare');
        $parts = explode(',', $raw);

        if (count($parts) !== 2) {
            $this->error('Provide exactly 2 sequences separated by comma. Use → as event separator.');
            $this->error('Example: --compare=sign_up→trial→purchase,sign_up→trial→cancel');

            return self::FAILURE;
        }

        $seqA = array_map('trim', explode('→', $parts[0]));
        $seqB = array_map('trim', explode('→', $parts[1]));

        $hashA = hash('sha256', implode('|', $seqA));
        $hashB = hash('sha256', implode('|', $seqB));

        $patternA = new EventSequencePattern(
            id: $hashA,
            sequence: $seqA,
            occurrences: 150,
            uniqueUsers: 120,
            averageDurationSeconds: 86400,
            medianDurationSeconds: 72000,
            conversionRate: 0.45,
        );

        $patternB = new EventSequencePattern(
            id: $hashB,
            sequence: $seqB,
            occurrences: 80,
            uniqueUsers: 65,
            averageDurationSeconds: 432000,
            medianDurationSeconds: 350000,
            conversionRate: 0.20,
        );

        $comparison = $service->compare($patternA, $patternB);

        $this->info("\n  📈 Sequence Value Comparison\n");

        $this->line("  Path A: " . implode(' → ', $seqA));
        $this->line("    Score: " . number_format($comparison['sequence_a']['score'], 4));
        $this->line("    Grade: {$comparison['sequence_a']['grade']}");
        $this->line("    LTV:   \${$comparison['sequence_a']['ltv']}");
        $this->line("    ROI:   {$comparison['sequence_a']['roi']}x");

        $this->newLine();

        $this->line("  Path B: " . implode(' → ', $seqB));
        $this->line("    Score: " . number_format($comparison['sequence_b']['score'], 4));
        $this->line("    Grade: {$comparison['sequence_b']['grade']}");
        $this->line("    LTV:   \${$comparison['sequence_b']['ltv']}");
        $this->line("    ROI:   {$comparison['sequence_b']['roi']}x");

        $this->newLine();
        $this->line("  Delta: " . ($comparison['delta'] >= 0 ? '+' : '') . number_format($comparison['delta'] * 100, 1) . '%');
        $this->newLine();
        $this->line("  💡 {$comparison['recommendation']}\n");

        return self::SUCCESS;
    }

    /**
     * Show event revenue multipliers.
     */
    private function showMultipliers(EventSequenceValueAttributionService $service): int
    {
        $multipliers = $service->getAllRevenueMultipliers();

        $this->info("\n  💰 Event Revenue Multipliers\n");

        $headers = ['Event', 'Multiplier', 'Type'];

        $rows = [];
        foreach ($multipliers as $event => $multiplier) {
            $type = $multiplier > 0 ? 'positive' : 'negative';
            $rows[] = [
                $event,
                number_format($multiplier, 1),
                $type,
            ];
        }

        // Sort by absolute multiplier descending
        usort($rows, fn (array $a, array $b): int => abs((float) $b[1]) <=> abs((float) $a[1]));

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * Load demo sequence patterns.
     *
     * @return list<EventSequencePattern>
     */
    private function loadPatterns(): array
    {
        if (! $this->option('demo')) {
            // Return empty — in production, load from cache/event store
            return [];
        }

        $sequences = [
            ['sign_up', 'start_trial', 'feature_used', 'trial_converted', 'subscription_created'],
            ['sign_up', 'start_trial', 'feature_used', 'plan_upgrade'],
            ['sign_up', 'start_trial', 'trial_expired'],
            ['page_view', 'view_item', 'add_to_cart', 'begin_checkout', 'add_payment_info', 'purchase'],
            ['page_view', 'view_item', 'add_to_cart', 'begin_checkout', 'abandoned_cart'],
            ['sign_up', 'login', 'feature_used', 'feature_adopted', 'share'],
            ['sign_up', 'start_trial', 'subscription_created', 'cancellation'],
            ['sign_up', 'start_trial', 'feature_used', 'subscription_created', 'plan_upgrade', 'team_created'],
            ['login', 'search', 'view_item', 'add_to_cart', 'purchase'],
            ['sign_up', 'start_trial', 'feature_used', 'feature_used', 'form_submit', 'trial_converted'],
        ];

        $occurrences = [500, 300, 200, 800, 400, 350, 100, 80, 600, 250];
        $uniqueUsers = [420, 260, 180, 700, 350, 310, 90, 70, 520, 220];
        $avgDurations = [432000, 259200, 604800, 3600, 86400, 172800, 259200, 604800, 7200, 345600];
        $convRates = [0.45, 0.55, 0.10, 0.65, 0.20, 0.40, 0.08, 0.35, 0.60, 0.50];

        $patterns = [];
        foreach ($sequences as $i => $seq) {
            $hash = hash('sha256', implode('|', $seq));
            $patterns[] = new EventSequencePattern(
                id: $hash,
                sequence: $seq,
                occurrences: $occurrences[$i],
                uniqueUsers: $uniqueUsers[$i],
                averageDurationSeconds: $avgDurations[$i],
                medianDurationSeconds: $avgDurations[$i] * 0.8,
                conversionRate: $convRates[$i],
            );
        }

        $this->comment('  Demo mode: Using 10 sample SaaS sequences.');

        return $patterns;
    }
}
