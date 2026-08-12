<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
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
        {--offset=? : Event offset for show/replay actions (0-based)}
        {--event=? : Filter list by event name}
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
        $asJson = (bool) $this->option('json');
        $eventName = $this->option('event');

        $events = $eventName !== null
            ? $this->dlq->getByEventName((string) $eventName)
            : $this->dlq->all();

        $limit = (int) $this->option('limit');
        $events = array_slice($events, 0, $limit);

        if (empty($events)) {
            if ($asJson) {
                $this->line(json_encode(['events' => [], 'total' => 0], JSON_PRETTY_PRINT));
            } else {
                $this->info('✅ Dead Letter Queue is empty.');
            }

            return self::SUCCESS;
        }

        $total = $this->dlq->count();

        if ($asJson) {
            $this->line(json_encode([
                'events' => $events,
                'total' => $total,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info("📦 Dead Letter Queue (showing " . min($limit, $total) . " of {$total})");
        $this->newLine();

        $headers = ['#', 'Event', 'Provider', 'Error', 'Attempts'];

        $rows = array_map(
            fn (array $event, int $index): array => [
                (string) $index,
                $event['event_name'] ?? $event['name'] ?? '-',
                $event['provider'] ?? 'all',
                $this->truncate((string) ($event['error'] ?? $event['message'] ?? 'unknown'), 50),
                (string) ($event['attempts'] ?? $event['attempt'] ?? 0),
            ],
            $events,
            array_keys($events),
        );

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * Show details of a specific DLQ event by offset.
     */
    private function showEvent(): int
    {
        $offset = $this->option('offset');

        if ($offset === null || $offset === '') {
            $this->error('❌ --offset is required for show action (0-based index).');

            return self::FAILURE;
        }

        $offset = (int) $offset;
        $events = $this->dlq->all();

        if (! isset($events[$offset])) {
            $this->error("❌ No event at offset {$offset} in DLQ (total: " . $this->dlq->count() . ').');

            return self::FAILURE;
        }

        $event = $events[$offset];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($event, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info("📋 DLQ Event [offset {$offset}]");
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
     * Replay a single DLQ event by offset.
     */
    private function replayEvent(): int
    {
        $offset = $this->option('offset');

        if ($offset === null || $offset === '') {
            $this->error('❌ --offset is required for replay action (0-based index).');

            return self::FAILURE;
        }

        $offset = (int) $offset;

        if (! $this->confirm("Replay event at offset {$offset}?")) {
            return self::SUCCESS;
        }

        $result = $this->dlq->replaySingle($offset);

        if ($result !== null) {
            $this->info("✅ Event at offset {$offset} replayed successfully.");
        } else {
            $this->warn("⚠️  Replay failed — event may still be in DLQ.");
        }

        return self::SUCCESS;
    }

    /**
     * Replay all events in the DLQ.
     */
    private function replayAll(): int
    {
        $count = $this->dlq->count();

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
        $count = $this->dlq->count();

        if ($count === 0) {
            $this->info('✅ Dead Letter Queue is already empty.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Permanently delete all {$count} events from the DLQ?", false)) {
            return self::SUCCESS;
        }

        $this->dlq->clear();
        $this->info("🗑️  Purged {$count} events from the Dead Letter Queue.");

        return self::SUCCESS;
    }

    /**
     * Show DLQ statistics.
     */
    private function showStats(): int
    {
        $summary = $this->dlq->summary();
        $asJson = (bool) $this->option('json');

        if ($asJson) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('📊 Dead Letter Queue Statistics');
        $this->newLine();

        foreach ($summary as $key => $value) {
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
