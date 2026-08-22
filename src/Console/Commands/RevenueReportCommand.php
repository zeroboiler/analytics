<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Display a revenue analytics report overview.
 *
 * Shows the current configuration for revenue tracking, event catalog
 * revenue-related events, and provides a diagnostic summary for verifying
 * that revenue analytics is properly configured.
 *
 * @since 1.0.0
 */
final class RevenueReportCommand extends Command
{
    protected $signature = 'zb:analytics:revenue-report
        {--dry-run : Simulate a revenue event without dispatching}
        {--event=mrr : Revenue event type to preview (mrr, arr, churn, ltv)}
        {--amount=100.00 : Revenue amount for the dry-run event}';

    protected $description = 'Display revenue analytics configuration and optionally simulate a revenue event';

    private AnalyticsManager $manager;

    public function __construct(AnalyticsManager $manager){
        parent::__construct();
        $this->manager = $manager;
    }

    #[Override]
    public function handle(): int
    {
        $this->info('💰 ZeroBoiler Revenue Analytics Report');
        $this->newLine();

        // Provider Status
        $this->line('<fg=cyan;options=bold>PROVIDER STATUS</>');
        $this->line('─────────────────────────────');

        $summary = $this->manager->providerSummary();
        $enabledCount = 0;

        foreach ($summary as $name => $info) {
            $status = $info['enabled']
                ? '<fg=green>● enabled</>'
                : '<fg=yellow>○ disabled</>';
            $detail = $info['id'] ?? '—';
            $this->line("  {$status}  {$name}: {$detail}");

            if ($info['enabled']) {
                $enabledCount++;
            }
        }

        if ($enabledCount === 0) {
            $this->warn('  ⚠️  No providers enabled — revenue events will not be dispatched.');
            $this->newLine();
            $this->line('  Enable a provider in your .env:');
            $this->line('    ANALYTICS_GA4_ENABLED=true');
            $this->line('    ANALYTICS_GA4_MEASUREMENT_ID=G-XXXXXXXXXX');

            return self::FAILURE;
        }

        // Revenue-Related Events
        $this->newLine();
        $this->line('<fg=cyan;options=bold>REVENUE EVENTS</>');
        $this->line('─────────────────────────────');

        $catalog = EventCatalog::all();
        $revenueEvents = [];

        foreach ($catalog as $name => $entry) {
            if (
                str_contains($name, 'revenue') ||
                str_contains($name, 'purchase') ||
                str_contains($name, 'subscription') ||
                str_contains($name, 'refund')
            ) {
                $revenueEvents[$name] = $entry;
            }
        }

        if (empty($revenueEvents)) {
            $this->line('  (no revenue-related events found)');
        } else {
            foreach ($revenueEvents as $name => $entry) {
                $ga4 = $entry['ga4'] ?? '—';
                $meta = $entry['meta'] ?? '—';
                $category = $entry['category'] ?? '—';
                $this->line("  <fg=green>{$name}</> [{$category}]");
                $this->line("    GA4: {$ga4} | Meta: {$meta}");
            }
        }

        $this->line("  <fg=green;options=bold>".count($revenueEvents).'</> revenue-related events');

        // Configuration
        $this->newLine();
        $this->line('<fg=cyan;options=bold>REVENUE CONFIG</>');
        $this->line('─────────────────────────────');

        /** @var array<string, mixed> $config */
        $config = config('zeroboiler.analytics', []);
        $ecommerce = $config['ecommerce'] ?? [];
        $queue = $config['queue'] ?? [];

        $this->line('  Currency: '.($ecommerce['currency'] ?? 'USD'));
        $this->line('  Brand: '.(($ecommerce['brand'] ?? '') ?: '(none)'));
        $this->line('  Tax Behavior: '.($ecommerce['tax_behavior'] ?? 'not_specified'));
        $this->line('  Queue Enabled: '.($queue['enabled'] ?? true ? '✅' : '🚫'));
        $this->line('  Queue Name: '.($queue['queue'] ?? 'analytics'));

        // Consent State
        $this->newLine();
        $this->line('<fg=cyan;options=bold>CONSENT STATE</>');
        $this->line('─────────────────────────────');

        $consent = $this->manager->getConsent();
        foreach ($consent->toArray() as $signal => $state) {
            $icon = $state === 'granted' ? '✅' : '🚫';
            $this->line("  {$icon} {$signal}: {$state}");
        }

        // Dry-Run Event Preview
        if ($this->option('dry-run')) {
            $this->newLine();
            $this->line('<fg=cyan;options=bold>DRY-RUN EVENT PREVIEW</>');
            $this->line('─────────────────────────────');

            $eventType = (string) $this->option('event');
            $amount = (float) $this->option('amount');

            $eventName = match ($eventType) {
                'mrr' => 'revenue_mrr',
                'arr' => 'revenue_arr',
                'churn' => 'revenue_churn',
                'ltv' => 'revenue_ltv',
                default => 'revenue_custom',
            };

            $event = new AnalyticsEvent(
                name: $eventName,
                params: [
                    'revenue_type' => $eventType,
                    'amount' => $amount,
                    'currency' => $ecommerce['currency'] ?? 'USD',
                    'source' => 'zb:analytics:revenue-report',
                    'dry_run' => true,
                ],
            );

            $this->line("  Event: {$eventName}");
            $this->line("  Amount: \${$amount}");
            $this->line("  Params: ".json_encode($event->params, JSON_PRETTY_PRINT));
            $this->newLine();
            $this->info('  ✅ Dry-run complete — event was NOT dispatched.');
        }

        $this->newLine();
        $this->info('✨ Revenue report complete.');

        return self::SUCCESS;
    }
}
