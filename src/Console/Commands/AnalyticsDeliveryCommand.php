<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\EventDeliveryConfirmationService;

/**
 * Analytics Delivery Confirmation command.
 *
 * Displays event delivery reliability scores, per-provider health,
 * response time percentiles, outage detection, and SLA status.
 *
 * Inspired by Segment's delivery monitoring, Amplitude's event verification,
 * and PostHog's ingestion monitoring dashboard.
 *
 * @since 9.0.0
 */
final class AnalyticsDeliveryCommand extends Command
{
    protected $signature = 'zb:analytics:delivery
        {--json : Output as JSON}
        {--provider= : Show details for a specific provider}
        {--clear : Clear all delivery stats}
        {--receipt= : Check delivery receipt for a specific event ID}';

    protected $description = 'Event delivery reliability monitoring & confirmation receipts';

    private EventDeliveryConfirmationService $service;

    public function __construct(EventDeliveryConfirmationService $service): void
    {
        parent::__construct();
        $this->service = $service;
    }

    /**
     * Execute the console command.
     */
    #[Override]
    public function handle(): int
    {
        // Handle --clear
        if ($this->option('clear')) {
            $this->service->clearStats($this->option('provider') ?? null);
            $provider = $this->option('provider') ?? 'all';
            $this->info("✅ Delivery stats cleared for: {$provider}");

            return self::SUCCESS;
        }

        // Handle --receipt
        $receiptId = $this->option('receipt');
        if ($receiptId !== null && is_string($receiptId) && $receiptId !== '') {
            return $this->showReceipt($receiptId);
        }

        // Default: show delivery dashboard
        return $this->showDashboard();
    }

    /**
     * Show the delivery receipt for a specific event.
     *
     * @param  string  $eventId  Event ID to check
     */
    private function showReceipt(string $eventId): int
    {
        $receipt = $this->service->checkReceipt($eventId);

        if ($this->option('json')) {
            $this->line(json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("📋 Delivery Receipt: {$eventId}");
        $this->newLine();

        $status = $receipt['delivered'] ? '✅ Delivered' : '⚠️  Pending/Partial';
        $this->line("  Status: {$status}");
        $this->newLine();

        foreach ($receipt['providers'] as $provider => $data) {
            $icon = $data['success'] ? '✅' : '❌';
            $time = $data['response_time_ms'] !== null ? "{$data['response_time_ms']}ms" : 'N/A';
            $error = $data['error'] ?? '';
            $line = "  {$icon} {$provider}: {$time}";
            if ($error !== null && $error !== '') {
                $line .= " ({$error})";
            }
            $this->line($line);
        }

        return self::SUCCESS;
    }

    /**
     * Show the delivery reliability dashboard.
     */
    private function showDashboard(): int
    {
        $dashboard = $this->service->getDeliveryDashboard();

        if ($this->option('json')) {
            $this->line(json_encode($dashboard, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $reliability = $dashboard['reliability'];

        $this->info('📦 ZeroBoiler Analytics — Event Delivery Monitor');
        $this->newLine();

        // Overall reliability
        $grade = $reliability['grade'];
        $score = $reliability['score'];
        $rate = $reliability['actual_rate'];
        $slaTarget = $reliability['sla_target'];
        $slaMet = $reliability['sla_met'] ? '✅' : '❌';

        $this->line("  Overall Reliability: {$score}/100 (Grade: {$grade})");
        $this->line("  Delivery Rate: {$rate}% (SLA: {$slaTarget}% {$slaMet})");
        $this->line("  Events Tracked: {$dashboard['events_tracked']}");

        // Progress bar for score
        $this->newLine();
        $this->output->write('  Reliability: [');
        $filled = (int) round($score / 2);
        $empty = 50 - $filled;
        $this->output->write(str_repeat('=', $filled));
        $this->output->write(str_repeat(' ', $empty));
        $this->output->writeln("] {$score}%");
        $this->newLine();

        // Per-provider details
        if (empty($dashboard['providers'])) {
            $this->warn('  No enabled providers found.');

            return self::SUCCESS;
        }

        $specificProvider = $this->option('provider');

        foreach ($dashboard['providers'] as $provider => $data) {
            if ($specificProvider !== null && $provider !== $specificProvider) {
                continue;
            }

            $outageIcon = $data['in_outage'] ? '🔴 OUTAGE' : '🟢';
            $pScore = $data['score'];
            $pRate = round($data['rate'] * 100, 1);
            $success = $data['success_count'];
            $failure = $data['failure_count'];
            $p50 = $data['response_times']['p50'];
            $p95 = $data['response_times']['p95'];
            $p99 = $data['response_times']['p99'];
            $avg = $data['response_times']['avg'];
            $samples = $data['response_times']['samples'];

            $this->line("  ┌─────────────────────────────────────────────────");
            $this->line("  │ {$outageIcon} Provider: {$provider}");
            $this->line("  │   Score: {$pScore}/100  |  Rate: {$pRate}%");
            $this->line("  │   Success: {$success}  |  Failures: {$failure}  |  Recent failures: {$data['recent_failures']}");

            if ($samples > 0) {
                $this->line("  │   Response Times ({$samples} samples):");
                $this->line("  │     p50: {$p50}ms  |  p95: {$p95}ms  |  p99: {$p99}ms  |  avg: {$avg}ms");
            } else {
                $this->line('  │   Response Times: no data yet');
            }

            $this->line("  └─────────────────────────────────────────────────");
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
