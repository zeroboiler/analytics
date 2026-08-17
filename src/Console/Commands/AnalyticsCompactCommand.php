<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\CompactionResult;
use ZeroBoiler\Analytics\Services\EventArchiveCompactionService;

/**
 * Analytics Archive Compaction Command — reduce storage cost of archived analytics events.
 *
 * Provides multiple action modes for archive compaction operations:
 *
 * - Default (no flags): Run full compaction across all strategies
 * - `--strategy=<name>`: Run only a specific strategy (aggregate, truncate, sample, expire)
 * - `--event=<name>`: Compact a single event
 * - `--estimate`: Show estimated savings without performing compaction
 * - `--config`: Display compaction configuration
 * - `--history`: Show compaction history
 * - `--max-age=<days>`: Override max age threshold for this run
 * - `--json`: Output as JSON
 * - `--dry-run`: Show what would be compacted without making changes
 *
 * @since 224.0.0
 */
final class AnalyticsCompactCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:compact
        {--strategy= : Compaction strategy (aggregate, truncate, sample, expire)}
        {--event= : Compact a specific event}
        {--estimate : Show estimated savings without compacting}
        {--config : Display compaction configuration}
        {--history : Show compaction history}
        {--max-age= : Override max age in days}
        {--json : Output as JSON}
        {--dry-run : Show what would happen without changes}';

    /** @var string */
    protected $description = 'Compact archived analytics events to reduce storage cost';

    /**
     * Execute the compaction command.
     */
    public function handle(): int
    {
        $service = $this->getCompactionService();

        if (! $service->isEnabled()) {
            $this->warn('Archive compaction is disabled in configuration.');

            return 0;
        }

        // Config mode
        if ($this->option('config')) {
            return $this->showConfig($service);
        }

        // History mode
        if ($this->option('history')) {
            return $this->showHistory($service);
        }

        // Estimate mode
        if ($this->option('estimate')) {
            return $this->showEstimate($service);
        }

        // Single event mode
        if ($this->option('event') !== null) {
            return $this->compactSingleEvent($service);
        }

        // Single strategy mode
        if ($this->option('strategy') !== null) {
            return $this->compactByStrategy($service);
        }

        // Full compaction mode (default)
        return $this->runFullCompaction($service);
    }

    /**
     * Run full compaction across all strategies.
     *
     * @param  EventArchiveCompactionService  $service
     * @return int
     */
    private function runFullCompaction(EventArchiveCompactionService $service): int
    {
        $maxAge = $this->option('max-age') !== null ? (int) $this->option('max-age') : null;

        if ($this->option('dry-run')) {
            $this->info('Dry-run mode — no changes will be made.');
            $this->newLine();

            $estimate = $service->estimateSavings($maxAge);
            $this->displayEstimate($estimate);

            return 0;
        }

        $this->info('Running full archive compaction...');
        $this->newLine();

        $report = $service->compact($maxAge);

        if ($this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->displayReport($report);

        return self::SUCCESS;
    }

    /**
     * Compact a single event.
     *
     * @param  EventArchiveCompactionService  $service
     * @return int
     */
    private function compactSingleEvent(EventArchiveCompactionService $service): int
    {
        $eventName = (string) $this->option('event');
        $strategy = $this->option('strategy') !== null ? (string) $this->option('strategy') : null;
        $maxAge = $this->option('max-age') !== null ? (int) $this->option('max-age') : null;

        $this->info("Compacting event: {$eventName}");

        $result = $service->compactEvent($eventName, $strategy, $maxAge);

        if ($this->option('json')) {
            $this->line(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->displayResult($result);

        return self::SUCCESS;
    }

    /**
     * Compact by strategy.
     *
     * @param  EventArchiveCompactionService  $service
     * @return int
     */
    private function compactByStrategy(EventArchiveCompactionService $service): int
    {
        $strategy = (string) $this->option('strategy');
        $maxAge = $this->option('max-age') !== null ? (int) $this->option('max-age') : null;

        if (! in_array($strategy, EventArchiveCompactionService::ALL_STRATEGIES, true)) {
            $this->error("Invalid strategy: {$strategy}. Valid: " . implode(', ', EventArchiveCompactionService::ALL_STRATEGIES));

            return 1;
        }

        $this->info("Compacting strategy: {$strategy}");

        $result = $service->compactByStrategy($strategy, $maxAge);

        if ($this->option('json')) {
            $this->line(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->displayResult($result);

        return self::SUCCESS;
    }

    /**
     * Show estimated savings.
     *
     * @param  EventArchiveCompactionService  $service
     * @return int
     */
    private function showEstimate(EventArchiveCompactionService $service): int
    {
        $maxAge = $this->option('max-age') !== null ? (int) $this->option('max-age') : null;
        $estimate = $service->estimateSavings($maxAge);

        if ($this->option('json')) {
            $this->line(json_encode($estimate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->displayEstimate($estimate);

        return self::SUCCESS;
    }

    /**
     * Display configuration.
     *
     * @param  EventArchiveCompactionService  $service
     * @return int
     */
    private function showConfig(EventArchiveCompactionService $service): int
    {
        $stats = $service->stats();

        if ($this->option('json')) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->info('Archive Compaction Configuration');
        $this->newLine();
        $this->table(
            ['Setting', 'Value'],
            [
                ['Enabled', $stats['enabled'] ? 'Yes' : 'No'],
                ['Max Age (days)', $stats['max_age_days']],
                ['Sample Rate', number_format($stats['sample_rate'] * 100, 1) . '%'],
                ['Bytes per Event', $stats['bytes_per_event']],
                ['Aggregate Bucket', $stats['aggregate_bucket_seconds'] . 's'],
                ['Strategies', implode(', ', $stats['all_strategies'])],
            ],
        );

        $this->newLine();
        $this->info('Strategy → Event Mapping');

        $rows = [];
        foreach ($stats['strategies'] as $strategy => $data) {
            $rows[] = [
                $strategy,
                $data['event_count'],
                implode(', ', $data['events']) ?: '(none)',
            ];
        }

        $this->table(['Strategy', 'Event Count', 'Events'], $rows);

        return self::SUCCESS;
    }

    /**
     * Display compaction history.
     *
     * @param  EventArchiveCompactionService  $service
     * @return int
     */
    private function showHistory(EventArchiveCompactionService $service): int
    {
        $history = $service->getHistory();

        if ($this->option('json')) {
            $this->line(json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if ($history === []) {
            $this->info('No compaction history found.');

            return 0;
        }

        $this->info('Compaction History (last ' . count($history) . ' runs)');
        $this->newLine();

        $rows = array_map(fn (array $entry): array => [
            $entry['strategy'] ?? '-',
            $entry['scope'] ?? '-',
            $entry['events_before'] ?? 0,
            $entry['events_after'] ?? 0,
            round($entry['compression_ratio'] ?? 0, 4),
            round($entry['bytes_saved'] ?? 0, 2) . ' KB',
            $entry['success'] ? '✓' : '✗',
        ], $history);

        $this->table(
            ['Strategy', 'Scope', 'Before', 'After', 'Ratio', 'Saved', 'OK'],
            $rows,
        );

        return self::SUCCESS;
    }

    /**
     * Display a full compaction report.
     *
     * @param  CompactionReport  $report
     * @return void
     */
    private function displayReport(CompactionReport $report): void
    {
        $this->info('Compaction Report — ' . $report->dateRange);
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Health Grade', $report->healthGrade],
                ['Events Before', number_format($report->totalEventsBefore)],
                ['Events After', number_format($report->totalEventsAfter)],
                ['Events Compacted', number_format($report->totalEventsCompacted)],
                ['Storage Saved', round($report->totalBytesSaved, 2) . ' KB'],
                ['Compression Ratio', round($report->overallCompressionRatio, 4)],
                ['Successful Scopes', $report->successfulScopes],
                ['Failed Scopes', $report->failedScopes],
                ['Duration', round($report->durationMs, 1) . ' ms'],
            ],
        );

        if ($report->results !== []) {
            $this->newLine();
            $this->info('Per-Strategy Results');

            $rows = array_map(fn (CompactionResult $r): array => [
                $r->strategy,
                $r->scope,
                number_format($r->eventsBefore),
                number_format($r->eventsAfter),
                round($r->compressionRatio, 4),
                round($r->bytesSaved, 2) . ' KB',
                $r->success ? '✓' : '✗',
            ], $report->results);

            $this->table(
                ['Strategy', 'Scope', 'Before', 'After', 'Ratio', 'Saved', 'OK'],
                $rows,
            );
        }

        if ($report->recommendations !== []) {
            $this->newLine();
            $this->info('Recommendations');

            foreach ($report->recommendations as $i => $rec) {
                $this->line("  " . ($i + 1) . ". {$rec}");
            }
        }
    }

    /**
     * Display a single compaction result.
     *
     * @param  CompactionResult  $result
     * @return void
     */
    private function displayResult(CompactionResult $result): void
    {
        if ($result->success) {
            $this->info("✓ Compaction successful");
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Strategy', $result->strategy],
                    ['Scope', $result->scope],
                    ['Events Before', number_format($result->eventsBefore)],
                    ['Events After', number_format($result->eventsAfter)],
                    ['Compression Ratio', round($result->compressionRatio, 4)],
                    ['Storage Saved', round($result->bytesSaved, 2) . ' KB'],
                    ['Duration', round($result->durationMs, 1) . ' ms'],
                ],
            );
        } else {
            $this->error("✗ Compaction failed: {$result->error}");
        }
    }

    /**
     * Display estimate results.
     *
     * @param  array{strategies: array<string, array{events: int, estimated_savings_kb: float, compression_ratio: float}>, total_savings_kb: float, total_compactable: int}  $estimate
     * @return void
     */
    private function displayEstimate(array $estimate): void
    {
        $this->info('Estimated Compaction Savings');
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Compactable Events', $estimate['total_compactable']],
                ['Total Estimated Savings', round($estimate['total_savings_kb'], 2) . ' KB'],
            ],
        );

        $this->newLine();
        $this->info('Per-Strategy Estimates');

        $rows = [];
        foreach ($estimate['strategies'] as $strategy => $data) {
            $rows[] = [
                $strategy,
                $data['events'],
                round($data['estimated_savings_kb'], 2) . ' KB',
                round($data['compression_ratio'] * 100, 1) . '%',
            ];
        }

        $this->table(['Strategy', 'Events', 'Est. Savings', 'Compression'], $rows);
    }

    /**
     * Get the compaction service from the container.
     *
     * @return EventArchiveCompactionService
     */
    private function getCompactionService(): EventArchiveCompactionService
    {
        return $this->laravel->make(EventArchiveCompactionService::class);
    }
}
