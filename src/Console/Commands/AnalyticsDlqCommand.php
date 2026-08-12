<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Services\DeadLetterQueueService;

/**
 * Inspect and manage the analytics Dead Letter Queue (DLQ).
 *
 * Lists failed events, displays details, replays individual or all events,
 * and purges the queue. Essential for recovering from provider outages,
 * configuration errors, and transient failures.
 *
 * @since 23.0.0
 */
final class AnalyticsDlqCommand extends Command
{
    protected $signature = 'zb:analytics:dlq
        {action : Action to perform (list|show|replay|replay-all|purge|stats)}
        {--id=? : Event ID for show/replay actions}
        {--limit=25 : Number of events to list (default: 25)}
        {--json : Output as JSON}';

    protected $description = 'Inspect and manage the analytics Dead Letter Queue';

    private DeadLetterQueueService $dlq;

    /**
     * @param  DeadLetterQueueService  $dlq  Dead letter queue service
     */
    public function __construct(DeadLetterQueueService $dlq)
    {
        parent::__construct();
        $this->dlq = $dlq;
    }

    #[\Override]
    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'list' => $this->listEvents(),
            'show' => $this->showEvent(),
            'replay' => $this->replayEvent(),
            'replay-all' => $this->replayAll(),
            'purge' => $this->purgeQueue(),
            'stats' => $this->showStats(),
            default => $this->invalidAction($action),
        };
    }

    /**
     * List failed events in the DLQ.
     */
    private function listEvents(): int
    {
        $limit = (int) $this->option('limit');
        $events = $this->dlq->list($limit);
        $asJson = (bool) $this->option('json');

        if (empty($events)) {
            if ($asJson) {
                $this->line(json_encode(['events' => [], 'total' => 0], JSON_PRETTY_PRINT));
            } else {
                $this->info('✅ Dead Letter Queue is empty.');
            }

            return self::SUCCESS;
        }

        $stats = $this->dlq->stats();

        if ($asJson) {
            $this->line(json_encode([
                'events' => $events,
                'total' => $stats['count'] ?? 0,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info("📦 Dead Letter Queue (showing {$limit} of {$stats['count']})");
        $this->newLine();

        $headers = ['ID', 'Event', 'Provider', 'Attempts', 'Error', 'Time'];

        $rows = array_map(
            fn (array $event): array => [
                $event['id'] ?? '-',
                $event['event_name'] ?? '-',
                $event['provider'] ?? 'all',
                (string) ($event['attempts'] ?? 0),
                $this->truncate((string) ($event['error'] ?? 'unknown'), 40),
                $event['created_at'] ?? '-',
            ],
            $events,
        );

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * Show details of a specific DLQ event.
     */
    private function showEvent(): int
    {
        $id = $this->option('id');

        if ($id === null || $id === '') {
            $this->error('❌ --id is required for show action.');

            return self::FAILURE;
        }

        $event = $this->dlq->get((string) $id);

        if ($event === null) {
            $this->error("❌ Event '{$id}' not found in DLQ.");

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($event, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info("📋 DLQ Event: {$id}");
        $this->newLine();

        foreach ($event as $key => $value) {
            $displayValue = is_array($value)
                ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : (string) $value;
            $this->line("  <fg=cyan>{$key}:</> {$displayValue}");
        }

        return self::SUCCESS;
    }

    /**
     * Replay a single DLQ event.
     */
    private function replayEvent(): int
    {
        $id = $this->option('id');

        if ($id === null || $id === '') {
            $this->error('❌ --id is required for replay action.');

            return self::FAILURE;
        }

        if (! $this->confirm("Replay event {$id}?")) {
            return self::SUCCESS;
        }

        $result = $this->dlq->replay((string) $id);

        if ($result) {
            $this->info("✅ Event {$id} replayed successfully.");
        } else {
            $this->warn("⚠️  Event {$id} replay failed (still in DLQ).");
        }

        return self::SUCCESS;
    }

    /**
     * Replay all events in the DLQ.
     */
    private function replayAll(): int
    {
        $stats = $this->dlq->stats();
        $count = $stats['count'] ?? 0;

        if ($count === 0) {
            $this->info('✅ Dead Letter Queue is empty — nothing to replay.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Replay all {$count} events in the DLQ?")) {
            return self::SUCCESS;
        }

        $result = $this->dlq->replayAll();
        $replayed = $result['replayed'] ?? 0;
        $failed = $result['failed'] ?? 0;

        $this->info("🔄 Replayed {$replayed} events successfully.");
        if ($failed > 0) {
            $this->warn("⚠️  {$failed} events failed to replay (still in DLQ).");
        }

        return self::SUCCESS;
    }

    /**
     * Purge all events from the DLQ.
     */
    private function purgeQueue(): int
    {
        $stats = $this->dlq->stats();
        $count = $stats['count'] ?? 0;

        if ($count === 0) {
            $this->info('✅ Dead Letter Queue is already empty.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Permanently delete all {$count} events from the DLQ?", false)) {
            return self::SUCCESS;
        }

        $this->dlq->purge();
        $this->info("🗑️  Purged {$count} events from the Dead Letter Queue.");

        return self::SUCCESS;
    }

    /**
     * Show DLQ statistics.
     */
    private function showStats(): int
    {
        $stats = $this->dlq->stats();
        $asJson = (bool) $this->option('json');

        if ($asJson) {
            $this->line(json_encode($stats, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('📊 Dead Letter Queue Statistics');
        $this->newLine();

        foreach ($stats as $key => $value) {
            $display = is_array($value)
                ? json_encode($value, JSON_UNESCAPED_SLASHES)
                : (string) $value;
            $this->line("  <fg=cyan>{$key}:</> {$display}");
        }

        return self::SUCCESS;
    }

    /**
     * Handle invalid action.
     */
    private function invalidAction(string $action): int
    {
        $this->error("❌ Invalid action: '{$action}'.");
        $this->newLine();
        $this->line('Available actions: list, show, replay, replay-all, purge, stats');

        return self::FAILURE;
    }

    /**
     * Truncate a string to a maximum length.
     */
    private function truncate(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength - 3) . '...';
    }
}
