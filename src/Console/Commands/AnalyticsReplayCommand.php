<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\EventArchiveService;

/**
 * Search and replay archived analytics events.
 *
 * Provides an artisan interface for admins to:
 * - List recent archived events
 * - Search events by name, client ID, user ID
 * - Replay individual or bulk events to active providers
 * - View event count statistics
 * - Clear the event archive
 *
 * @version 5.0.0
 *
 * @since 1.0.0
 */
final class AnalyticsReplayCommand extends Command
{
    protected $signature = 'zb:analytics:replay
        {action : Action to perform (list, search, show, replay, bulk-replay, stats, clear)}
        {--name= : Filter by event name (partial match)}
        {--client_id= : Filter by client ID (exact match)}
        {--user_id= : Filter by user ID (exact match)}
        {--id= : Single event ID for show/replay}
        {--failed-only : Only show failed dispatches}
        {--limit=25 : Number of results (default: 25)}
        {--dispatched : Only show successfully dispatched events}
        {--force : Skip confirmation for bulk actions}';

    protected $description = 'Search, inspect, and replay archived analytics events';

    private EventArchiveService $archive;

    /**
     * Create a new command instance.
     */
    public function __construct(EventArchiveService $archive)
    {
        parent::__construct();
        $this->archive = $archive;
    }

    /**
     * Execute the console command.
     */
    #[\Override]
    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'list' => $this->listRecent(),
            'search' => $this->search(),
            'show' => $this->show(),
            'replay' => $this->replay(),
            'bulk-replay' => $this->bulkReplay(),
            'stats' => $this->stats(),
            'clear' => $this->clear(),
            default => $this->invalidAction($action),
        };
    }

    /**
     * List most recently archived events.
     */
    private function listRecent(): int
    {
        $limit = (int) $this->option('limit');
        $results = $this->archive->search([], $limit, 0);

        if ($results['events'] === []) {
            $this->info('📦 Event archive is empty.');

            return self::SUCCESS;
        }

        $this->info("📦 Recent Archived Events ({$results['total']} total)");
        $this->line('──────────────────────────────────────────────────────────────────');

        $this->table(
            ['ID', 'Event', 'Client', 'User', 'Status', 'Providers', 'Time'],
            array_map(fn (array $e): array => [
                $e['id'],
                $e['name'],
                $this->truncate($e['client_id'] ?? '—', 12),
                $this->truncate($e['user_id'] ?? '—', 12),
                $e['dispatched'] ? '<fg=green>✓ sent</>' : '<fg=red>✗ failed</>',
                implode(', ', $e['providers'] ?? []),
                $e['timestamp'],
            ], $results['events']),
        );

        return self::SUCCESS;
    }

    /**
     * Search archived events with filters.
     */
    private function search(): int
    {
        $filters = $this->buildFilters();
        $limit = (int) $this->option('limit');
        $results = $this->archive->search($filters, $limit, 0);

        if ($results['events'] === []) {
            $this->warn('No archived events match the given filters.');

            return self::SUCCESS;
        }

        $this->info("🔍 Search Results ({$results['total']} matching events)");
        $this->line('──────────────────────────────────────────────────────────────────');

        $this->table(
            ['ID', 'Event', 'Client', 'User', 'Status', 'Time'],
            array_map(fn (array $e): array => [
                $e['id'],
                $e['name'],
                $this->truncate($e['client_id'] ?? '—', 12),
                $this->truncate($e['user_id'] ?? '—', 12),
                $e['dispatched'] ? '<fg=green>✓</>' : '<fg=red>✗</>',
                $e['timestamp'],
            ], $results['events']),
        );

        return self::SUCCESS;
    }

    /**
     * Show detailed information about a single archived event.
     */
    private function show(): int
    {
        $id = (int) $this->option('id');

        if ($id <= 0) {
            $this->error('Please provide an event ID with --id=N');

            return self::FAILURE;
        }

        $event = $this->archive->get($id);

        if ($event === null) {
            $this->error("Event #{$id} not found in archive.");

            return self::FAILURE;
        }

        $this->info("📋 Archived Event #{$event['id']}");
        $this->line('──────────────────────────────────────────────────────────────────');
        $this->line("  Event:     <fg=cyan>{$event['name']}</>");
        $this->line('  Client ID: ' . ($event['client_id'] ?? '—'));
        $this->line('  User ID:   ' . ($event['user_id'] ?? '—'));
        $this->line("  Status:    " . ($event['dispatched'] ? '<fg=green>Dispatched</>' : '<fg=red>Failed</>'));
        $this->line("  Providers: " . implode(', ', $event['providers'] ?? []));
        $this->line("  Timestamp: {$event['timestamp']}");
        $this->line("  Archived:  {$event['archived_at']}");

        if (! empty($event['params'])) {
            $this->newLine();
            $this->line('  <fg=cyan;options=bold>Parameters:</>');
            foreach ($event['params'] as $key => $value) {
                $display = is_array($value) ? json_encode($value) : (string) $value;
                $this->line("    {$key}: {$this->truncate($display, 80)}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Replay a single archived event.
     */
    private function replay(): int
    {
        $id = (int) $this->option('id');

        if ($id <= 0) {
            $this->error('Please provide an event ID with --id=N');

            return self::FAILURE;
        }

        $event = $this->archive->get($id);

        if ($event === null) {
            $this->error("Event #{$id} not found in archive.");

            return self::FAILURE;
        }

        $this->line("Replaying event #{$id}: <fg=cyan>{$event['name']}</>");

        $success = $this->archive->replay($id);

        if ($success) {
            $this->info("✅ Event #{$id} replayed successfully.");

            return self::SUCCESS;
        }

        $this->error("❌ Failed to replay event #{$id}.");

        return self::FAILURE;
    }

    /**
     * Bulk replay events matching filters.
     */
    private function bulkReplay(): int
    {
        $filters = $this->buildFilters();

        if (! $this->option('force')) {
            $this->warn('This will re-dispatch all matching events to active providers.');
            $this->line('Filters: ' . json_encode($filters));

            if (! $this->confirm('Proceed with bulk replay?')) {
                return self::SUCCESS;
            }
        }

        $result = $this->archive->replayBulk($filters);

        $this->info("📤 Bulk Replay Complete");
        $this->line('──────────────────────────────────────────────────────────────────');
        $this->line("  Total:    {$result['total']}");
        $this->line("  Replayed: <fg=green>{$result['replayed']}</>");
        $this->line("  Failed:   <fg=red>{$result['failed']}</>");

        return self::SUCCESS;
    }

    /**
     * Show event count statistics.
     */
    private function stats(): int
    {
        $total = $this->archive->totalArchived();
        $counts = $this->archive->eventCounts(20);

        $this->info("📊 Event Archive Statistics ({$total} total events)");
        $this->line('──────────────────────────────────────────────────────────────────');

        if ($counts === []) {
            $this->line('  No events archived yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['Event Name', 'Count', 'Bar'],
            array_map(fn (array $c): array => [
                $c['name'],
                $c['count'],
                str_repeat('█', min((int) ($c['count'] / max(array_column($counts, 'count')) * 30), 30)),
            ], $counts),
        );

        return self::SUCCESS;
    }

    /**
     * Clear the entire event archive.
     */
    private function clear(): int
    {
        $total = $this->archive->totalArchived();

        if ($total === 0) {
            $this->info('Archive is already empty.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->warn("This will permanently delete all {$total} archived events.");

            if (! $this->confirm('Proceed?')) {
                return self::SUCCESS;
            }
        }

        $cleared = $this->archive->clear();

        $this->info("🗑️  Cleared {$cleared} archived events.");

        return self::SUCCESS;
    }

    /**
     * Build filter array from command options.
     *
     * @return array{name?: string, client_id?: string, user_id?: string, dispatched?: bool|null}
     */
    private function buildFilters(): array
    {
        $filters = [];

        if ($this->option('name')) {
            $filters['name'] = (string) $this->option('name');
        }

        if ($this->option('client_id')) {
            $filters['client_id'] = (string) $this->option('client_id');
        }

        if ($this->option('user_id')) {
            $filters['user_id'] = (string) $this->option('user_id');
        }

        if ($this->option('failed-only')) {
            $filters['dispatched'] = false;
        } elseif ($this->option('dispatched')) {
            $filters['dispatched'] = true;
        }

        return $filters;
    }

    /**
     * Handle invalid action.
     */
    private function invalidAction(string $action): int
    {
        $this->error("Invalid action: {$action}");
        $this->line('Valid actions: list, search, show, replay, bulk-replay, stats, clear');

        return self::FAILURE;
    }

    /**
     * Truncate a string for display.
     */
    private function truncate(string $value, int $length = 20): string
    {
        return mb_strlen($value) > $length
            ? mb_substr($value, 0, $length) . '…'
            : $value;
    }
}
