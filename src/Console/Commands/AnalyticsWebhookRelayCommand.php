<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\OutboundWebhookRelay;

/**
 * Outbound webhook relay management command.
 *
 * Manage webhook destinations, test connectivity, view delivery
 * statistics, and inspect delivery logs for the outbound relay system.
 *
 * Actions:
 *   (none)    Show relay overview (enabled status, destinations)
 *   list      List all configured destinations with stats
 *   test      Test a specific destination
 *   stats     Show detailed delivery statistics
 *   log       Show delivery log for a destination
 *   clear     Clear delivery log for a destination
 *   reset-rate  Reset rate limit counter for a destination
 *
 * @since 157.0.0
 *
 * @see \ZeroBoiler\Analytics\Services\OutboundWebhookRelay
 */
final class AnalyticsWebhookRelayCommand extends Command
{
    protected $signature = 'zb:analytics:webhook-relay
        {action? : Action to perform (list|test|stats|log|clear|reset-rate)}
        {--destination= : Destination name (required for test/stats/log/clear/reset-rate)}
        {--json : Output as JSON}
        {--limit=50 : Max log entries to show}';

    protected $description = 'Manage outbound webhook relay — test destinations, view delivery stats and logs';

    private OutboundWebhookRelay $relay;

    public function __construct(OutboundWebhookRelay $relay): void
    {
        parent::__construct();
        $this->relay = $relay;
    }

    public function handle(): int
    {
        $action = $this->argument('action');
        $json = (bool) $this->option('json');

        return match ($action) {
            'list' => $this->listDestinations($json),
            'test' => $this->testDestination($json),
            'stats' => $this->showStats($json),
            'log' => $this->showLog($json),
            'clear' => $this->clearLog(),
            'reset-rate' => $this->resetRateLimit(),
            default => $this->showOverview($json),
        };
    }

    /**
     * Show relay overview.
     */
    private function showOverview(bool $json): int
    {
        $stats = $this->relay->stats();

        if ($json) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('╔══════════════════════════════════════════╗');
        $this->info('║  ZeroBoiler Analytics — Webhook Relay    ║');
        $this->info('╚══════════════════════════════════════════╝');
        $this->newLine();

        $status = $stats['enabled'] ? '✅ Enabled' : '❌ Disabled';
        $this->line("  Status: {$status}");
        $this->line("  Rate Limit: {$stats['rate_limit']}/min");
        $this->line(sprintf('  Destinations: %d configured', count($stats['destinations'])));
        $this->newLine();

        $this->table(
            ['Destination', 'Sent', 'Failed', 'Success Rate', 'Last Sent'],
            $this->formatStatsRows($stats['destinations']),
        );

        return self::SUCCESS;
    }

    /**
     * List all destinations.
     */
    private function listDestinations(bool $json): int
    {
        $names = $this->relay->getDestinationNames();

        if ($json) {
            $this->line(json_encode([
                'destinations' => $names,
                'total' => count($names),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if (empty($names)) {
            $this->warn('No outbound webhook destinations configured.');

            return self::SUCCESS;
        }

        $this->info('Configured webhook destinations:');
        foreach ($names as $name) {
            $configured = $this->relay->isDestinationConfigured($name) ? '✅' : '❌';
            $this->line("  {$configured} {$name}");
        }

        return self::SUCCESS;
    }

    /**
     * Test a destination.
     */
    private function testDestination(bool $json): int
    {
        $destination = $this->option('destination');

        if ($destination === null) {
            $this->error('--destination is required for test action.');

            return self::FAILURE;
        }

        $this->info("Testing destination: {$destination}...");

        $result = $this->relay->testDestination((string) $destination);

        if ($json) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $status = $result['success'] ? '✅ Success' : '❌ Failed';
        $statusCode = $result['status_code'] !== null ? (string) $result['status_code'] : 'N/A';
        $error = $result['error'] ?? 'None';

        $this->table(
            ['Metric', 'Value'],
            [
                ['Status', $status],
                ['HTTP Status', $statusCode],
                ['Latency', $result['latency_ms'] . 'ms'],
                ['Error', $error],
            ],
        );

        return $result['success'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Show detailed statistics.
     */
    private function showStats(bool $json): int
    {
        $stats = $this->relay->stats();

        if ($json) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table(
            ['Destination', 'Sent', 'Failed', 'Success Rate', 'Last Sent'],
            $this->formatStatsRows($stats['destinations']),
        );

        return self::SUCCESS;
    }

    /**
     * Show delivery log.
     */
    private function showLog(bool $json): int
    {
        $destination = $this->option('destination');

        if ($destination === null) {
            $this->error('--destination is required for log action.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $log = $this->relay->deliveryLog((string) $destination, $limit);

        if ($json) {
            $this->line(json_encode([
                'destination' => $destination,
                'entries' => $log,
                'total' => count($log),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if (empty($log)) {
            $this->line("  No delivery log entries for: {$destination}");

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($log as $entry) {
            $statusIcon = $entry['status'] === 'delivered' ? '✅' : '❌';
            $rows[] = [
                $entry['event'],
                $statusIcon . ' ' . $entry['status'],
                $entry['latency_ms'] . 'ms',
                $entry['timestamp'],
            ];
        }

        $this->table(
            ['Event', 'Status', 'Latency', 'Timestamp'],
            $rows,
        );

        return self::SUCCESS;
    }

    /**
     * Clear delivery log.
     */
    private function clearLog(): int
    {
        $destination = $this->option('destination');

        if ($destination === null) {
            $this->error('--destination is required for clear action.');

            return self::FAILURE;
        }

        $this->relay->clearDeliveryLog((string) $destination);
        $this->info("Cleared delivery log for: {$destination}");

        return self::SUCCESS;
    }

    /**
     * Reset rate limit counter.
     */
    private function resetRateLimit(): int
    {
        $destination = $this->option('destination');

        if ($destination === null) {
            $this->error('--destination is required for reset-rate action.');

            return self::FAILURE;
        }

        $this->relay->resetRateLimit((string) $destination);
        $this->info("Reset rate limit counter for: {$destination}");

        return self::SUCCESS;
    }

    /**
     * Format stats rows for table display.
     *
     * @param  array<string, array{total_sent: int, total_failed: int, success_rate: float, last_sent: string|null}>  $destinations
     * @return list<array<string, string>>
     */
    private function formatStatsRows(array $destinations): array
    {
        $rows = [];

        foreach ($destinations as $name => $stats) {
            $rows[] = [
                $name,
                (string) $stats['total_sent'],
                (string) $stats['total_failed'],
                $stats['success_rate'] . '%',
                $stats['last_sent'] ?? 'Never',
            ];
        }

        return $rows;
    }
}
