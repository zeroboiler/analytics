<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Services\EventCostTracker;
use ZeroBoiler\Analytics\Services\NotificationWebhookService;

/**
 * Analytics Cost Report Command.
 *
 * Displays a cost breakdown per analytics provider with current
 * period costs, projected monthly costs, and budget recommendations.
 *
 * Usage:
 *   php artisan zb:analytics:cost-report
 *   php artisan zb:analytics:cost-report --json
 *   php artisan zb:analytics:cost-report --provider=ga4
 *
 * @version 5.0.0
 *
 * @since 1.0.0
 */
final class AnalyticsCostReportCommand extends Command
{
    /**
     * The console command name and signature.
     *
     * @var string
     */
    protected $signature = 'zb:analytics:cost-report
        {--json : Output as JSON}
        {--provider= : Show cost for a specific provider}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display analytics cost breakdown per provider with projections';

    private EventCostTracker $costTracker;

    public function __construct(EventCostTracker $costTracker): void
    {
        parent::__construct();
        $this->costTracker = $costTracker;
    }

    /**
     * Execute the console command.
     */
    #[Override]
    public function handle(): int
    {
        if (! $this->costTracker->isEnabled()) {
            $this->warn('Event cost tracking is disabled.');
            $this->line('Enable it with ANALYTICS_COST_TRACKING_ENABLED=true');

            return self::SUCCESS;
        }

        $provider = $this->option('provider');

        if ($provider !== null) {
            return $this->showProviderCost($provider);
        }

        if ($this->option('json')) {
            $this->line(json_encode($this->costTracker->report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        return $this->showFullReport();
    }

    /**
     * Display full cost report.
     */
    private function showFullReport(): int
    {
        $report = $this->costTracker->report();

        $this->info('📊 ZeroBoiler Analytics — Cost Report');
        $this->newLine();
        $this->line("  Period:       {$report['period']}");
        $this->line("  Generated:    {$report['generated_at']}");
        $this->line("  Currency:     {$report['currency']}");
        $this->newLine();

        // Provider table
        $rows = [];

        foreach ($report['providers'] as $name => $data) {
            $freeRemaining = $data['free_tier_remaining'] > 0
                ? number_format($data['free_tier_remaining']) . ' free'
                : '—';

            $rows[] = [
                $name,
                number_format($data['events']),
                '$' . number_format($data['cost'], 4),
                '$' . number_format($data['projected_monthly'], 2),
                $data['model'],
                $freeRemaining,
            ];
        }

        $this->table(
            ['Provider', 'Events', 'Cost', 'Projected/Mo', 'Model', 'Free Tier'],
            $rows,
        );

        // Totals
        $this->newLine();
        $total = $report['total'];
        $this->info("  Total Events:       " . number_format($total['events']));
        $this->info("  Total Cost:         $" . number_format($total['cost'], 4));
        $this->info("  Projected Monthly:  $" . number_format($total['projected_monthly'], 2));

        // Most expensive
        $mostExpensive = $this->costTracker->mostExpensiveProvider();

        if ($mostExpensive !== null && $mostExpensive['projected_monthly'] > 0) {
            $this->newLine();
            $this->warn("  💰 Highest Cost Provider: {$mostExpensive['provider']} (~\${$mostExpensive['projected_monthly']}/mo projected)");
        }

        // Budget recommendations
        $this->newLine();
        $this->line('  💡 Recommendations:');

        foreach ($report['providers'] as $name => $data) {
            if ($data['model'] === 'free') {
                continue;
            }

            if ($data['free_tier_remaining'] > 0 && $data['free_tier_remaining'] < 10000) {
                $this->line("     ⚠️  {$name}: Only " . number_format($data['free_tier_remaining']) . ' events remaining in free tier');
            }

            if ($data['projected_monthly'] > 100) {
                $this->line("     💸 {$name}: High spend (\${$data['projected_monthly']}/mo) — consider optimizing");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Display cost for a specific provider.
     */
    private function showProviderCost(string $provider): int
    {
        $cost = $this->costTracker->providerCost($provider);

        if ($cost === null) {
            $this->error("Provider '{$provider}' not found or disabled.");

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($cost, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info("📊 {$provider} — Cost Summary");
        $this->newLine();
        $this->line("  Events:           " . number_format($cost['events']));
        $this->line("  Current Cost:     " . $cost['currency'] . ' ' . number_format($cost['cost'], 4));
        $this->line("  Projected/Month:  " . $cost['currency'] . ' ' . number_format($cost['projected_monthly'], 2));
        $this->line("  Model:            {$cost['model']}");

        if ($this->costTracker->isWithinFreeTier($provider)) {
            $this->newLine();
            $this->info('  ✅ Within free tier — no charges');
        } else {
            $this->newLine();
            $this->warn('  ⚠️  Exceeded free tier — charges apply');
        }

        return self::SUCCESS;
    }
}
