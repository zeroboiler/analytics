<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Dead Letter Queue (DLQ) service for permanently failed analytics events.
 *
 * Captures events that have exhausted all retry attempts in the EventReplayQueue
 * and stores them persistently for later inspection, manual replay, or archival.
 *
 * Storage strategy is configurable:
 * - 'file' (default): JSON lines file, one event per line
 * - 'log': Write to Laravel log channel
 * - 'null': Silently discard (not recommended for production)
 *
 * Supports manual replay of individual events or bulk replay of all DLQ events.
 */
final class DeadLetterQueueService
{
    private string $storagePath;

    private string $strategy;

    private bool $enabled;

    private int $maxSize;

    /** @var list<array{event: array{name: string, params: array<string, mixed>, clientId: string|null, userId: string|null}, error: string, attempt: int, timestamp: string}> */
    private array $buffer = [];

    /** @var int Maximum events to buffer before auto-flush */
    private int $bufferSize;

    public function __construct(ConfigRepository $config): void
: void {
        $dlqConfig = $config->get('zeroboiler.analytics.dead_letter_queue', []);
        /** @var array{enabled?: bool, strategy?: string, storage_path?: string, max_size?: int, buffer_size?: int} $dlqConfig */

        $this->enabled = (bool) ($dlqConfig['enabled'] ?? true);
        $this->strategy = $dlqConfig['strategy'] ?? 'file';
        $this->storagePath = $dlqConfig['storage_path'] ?? storage_path('app/analytics/dlq.jsonl');
        $this->maxSize = (int) ($dlqConfig['max_size'] ?? 10000);
        $this->bufferSize = (int) ($dlqConfig['buffer_size'] ?? 50);

        // Ensure directory exists
        $dir = dirname($this->storagePath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /**
     * Enqueue a permanently failed event to the dead letter queue.
     *
     * Called by EventReplayQueue when an event exceeds max retry attempts.
     * The event is buffered and flushed to persistent storage.
     */
    public function enqueue(AnalyticsEvent $event, \Throwable $error, int $attempt): void
    {
        if (! $this->enabled) {
            return;
        }

        // Check size limit
        if ($this->count() >= $this->maxSize) {
            Log::warning('ZeroBoiler Analytics: DLQ is full, dropping event', [
                'event' => $event->name,
                'max_size' => $this->maxSize,
            ]);

            return;
        }

        $this->buffer[] = [
            'event' => [
                'name' => $event->name,
                'params' => $event->params,
                'clientId' => $event->clientId,
                'userId' => $event->userId,
            ],
            'error' => $error->getMessage(),
            'attempt' => $attempt,
            'timestamp' => now()->toIso8601String(),
        ];

        Log::debug('ZeroBoiler Analytics: event moved to DLQ', [
            'event' => $event->name,
            'attempt' => $attempt,
            'error' => $error->getMessage(),
            'buffer_size' => count($this->buffer),
        ]);

        // Auto-flush when buffer is full
        if (count($this->buffer) >= $this->bufferSize) {
            $this->flush();
        }
    }

    /**
     * Flush buffered events to persistent storage.
     */
    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        match ($this->strategy) {
            'file' => $this->flushToFile(),
            'log' => $this->flushToLog(),
            'null' => $this->buffer = [],
            default => $this->flushToFile(),
        };
    }

    /**
     * Get all events from the DLQ.
     *
     * @return list<array{event: array{name: string, params: array<string, mixed>, clientId: string|null, userId: string|null}, error: string, attempt: int, timestamp: string}>
     */
    public function all(): array
    {
        $this->flush(); // Ensure buffer is persisted first

        return match ($this->strategy) {
            'file' => $this->readFromFile(),
            default => [],
        };
    }

    /**
     * Get events from the DLQ, optionally filtered by event name.
     *
     * @return list<array{event: array{name: string, params: array<string, mixed>, clientId: string|null, userId: string|null}, error: string, attempt: int, timestamp: string}>
     */
    public function getByEventName(string $eventName): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (array $entry): bool => $entry['event']['name'] === $eventName,
        ));
    }

    /**
     * Get the number of events in the DLQ (excluding buffer).
     */
    public function count(): int
    {
        return match ($this->strategy) {
            'file' => $this->countFile(),
            default => 0,
        };
    }

    /**
     * Get total size including buffer.
     */
    public function totalSize(): int
    {
        return $this->count() + count($this->buffer);
    }

    /**
     * Clear all events from the DLQ (including buffer).
     */
    public function clear(): void
    {
        $this->buffer = [];

        if ($this->strategy === 'file' && file_exists($this->storagePath)) {
            @unlink($this->storagePath);
        }
    }

    /**
     * Remove a specific event by its line offset.
     *
     * @return bool True if the event was removed
     */
    public function remove(int $offset): bool
    {
        if ($this->strategy !== 'file') {
            return false;
        }

        $events = $this->readFromFile();

        if (! isset($events[$offset])) {
            return false;
        }

        unset($events[$offset]);

        return $this->writeToFile(array_values($events));
    }

    /**
     * Replay all DLQ events — returns them as AnalyticsEvent objects.
     *
     * Does NOT automatically re-dispatch; the caller should handle
     * re-dispatch with appropriate error handling.
     *
     * @return list<AnalyticsEvent>
     */
    public function replayAll(): array
    {
        $entries = $this->all();
        $events = [];

        foreach ($entries as $entry) {
            $events[] = new AnalyticsEvent(
                name: $entry['event']['name'],
                params: $entry['event']['params'],
                clientId: $entry['event']['clientId'],
                userId: $entry['event']['userId'],
            );
        }

        // Clear after replay
        $this->clear();

        return $events;
    }

    /**
     * Get DLQ summary for health checks.
     *
     * @return array{enabled: bool, strategy: string, total: int, buffered: int, max_size: int, storage_path: string, utilization: float}
     */
    public function summary(): array
    {
        $total = $this->totalSize();

        return [
            'enabled' => $this->enabled,
            'strategy' => $this->strategy,
            'total' => $total,
            'buffered' => count($this->buffer),
            'max_size' => $this->maxSize,
            'storage_path' => $this->strategy === 'file' ? $this->storagePath : 'n/a',
            'utilization' => $this->maxSize > 0
                ? round(($total / $this->maxSize) * 100, 2)
                : 0.0,
        ];
    }

    /**
     * Check if the DLQ is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Flush events to file storage (JSON Lines format).
     */
    private function flushToFile(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $handle = @fopen($this->storagePath, 'a');

        if ($handle === false) {
            Log::error('ZeroBoiler Analytics: failed to open DLQ file for writing', [
                'path' => $this->storagePath,
            ]);

            return;
        }

        try {
            foreach ($this->buffer as $entry) {
                $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($line !== false) {
                    @fwrite($handle, $line . "\n");
                }
            }

            @fflush($handle);
        } finally {
            @fclose($handle);
        }

        $this->buffer = [];
    }

    /**
     * Flush events to log.
     */
    private function flushToLog(): void
    {
        foreach ($this->buffer as $entry) {
            Log::warning('ZeroBoiler Analytics: DLQ event', $entry);
        }

        $this->buffer = [];
    }

    /**
     * Read all events from file.
     *
     * @return list<array{event: array{name: string, params: array<string, mixed>, clientId: string|null, userId: string|null}, error: string, attempt: int, timestamp: string}>
     */
    private function readFromFile(): array
    {
        if (! file_exists($this->storagePath)) {
            return [];
        }

        $contents = @file_get_contents($this->storagePath);

        if ($contents === false || $contents === '') {
            return [];
        }

        $events = [];
        $lines = explode("\n", trim($contents));

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            /** @var array{event: array{name: string, params: array<string, mixed>, clientId: string|null, userId: string|null}, error: string, attempt: int, timestamp: string}|null $decoded */
            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return $events;
    }

    /**
     * Count lines in the DLQ file.
     */
    private function countFile(): int
    {
        if (! file_exists($this->storagePath)) {
            return 0;
        }

        $contents = @file_get_contents($this->storagePath);

        if ($contents === false || $contents === '') {
            return 0;
        }

        return substr_count($contents, "\n");
    }

    /**
     * Write events to file (overwrite).
     *
     * @param list<array{event: array{name: string, params: array<string, mixed>, clientId: string|null, userId: string|null}, error: string, attempt: int, timestamp: string}> $events
     */
    private function writeToFile(array $events): bool
    {
        $handle = @fopen($this->storagePath, 'w');

        if ($handle === false) {
            return false;
        }

        try {
            foreach ($events as $entry) {
                $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($line !== false) {
                    @fwrite($handle, $line . "\n");
                }
            }

            @fflush($handle);
        } finally {
            @fclose($handle);
        }

        return true;
    }
}
