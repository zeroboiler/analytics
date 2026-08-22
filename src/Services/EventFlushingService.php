<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Configurable event flushing strategy service.
 *
 * Controls when and how analytics events are sent to provider endpoints.
 * Supports multiple flushing strategies for different use cases:
 *
 * - **immediate** — Every event is dispatched instantly (default)
 * - **buffered** — Events are buffered until flush() is called or buffer size is reached
 * - **periodic** — Events are flushed at fixed time intervals
 * - **batch_window** — Events are collected for a time window, then flushed as a batch
 *
 * This service acts as a middleware between the AnalyticsManager and
 * the actual provider dispatch. It accumulates events according to the
 * configured strategy and dispatches them optimally.
 *
 * @since 17.0.0
 */
final class EventFlushingService
{
    /** Strategy: dispatch every event immediately */
    public const STRATEGY_IMMEDIATE = 'immediate';

    /** Strategy: buffer events until flush() or max size */
    public const STRATEGY_BUFFERED = 'buffered';

    /** Strategy: flush at fixed intervals */
    public const STRATEGY_PERIODIC = 'periodic';

    /** Strategy: collect for a time window then flush */
    public const STRATEGY_BATCH_WINDOW = 'batch_window';

    /** @var list<AnalyticsEvent> Event buffer */
    private array $buffer = [];

    /** @var int Current buffer size */
    private int $bufferSize = 0;

    /** @var string Active flushing strategy */
    private string $strategy;

    /** @var int Maximum buffer size before auto-flush */
    private int $maxBufferSize;

    /** @var int Batch window duration in seconds (for batch_window strategy) */
    private int $batchWindowSeconds;

    /** @var float Last flush timestamp */
    private float $lastFlushTime = 0.0;

    /** @var int Total events flushed (lifetime) */
    private int $totalFlushed = 0;

    /** @var int Total events dispatched (immediate) */
    private int $totalImmediate = 0;

    private AnalyticsManager $manager;

    /**
     * @param  AnalyticsManager  $manager
     * @param  ConfigRepository  $config
     */
    public function __construct(AnalyticsManager $manager, ConfigRepository $config){
        $this->manager = $manager;

        $flushConfig = $config->get('zeroboiler.analytics.flushing', []);
        /** @var array{strategy?: string, max_buffer_size?: int, batch_window?: int} $flushConfig */

        $this->strategy = $flushConfig['strategy'] ?? self::STRATEGY_IMMEDIATE;
        $this->maxBufferSize = (int) ($flushConfig['max_buffer_size'] ?? 50);
        $this->batchWindowSeconds = (int) ($flushConfig['batch_window'] ?? 5);
        $this->lastFlushTime = microtime(true);
    }

    /**
     * Process an event through the flushing strategy.
     *
     * Depending on the strategy, the event is either dispatched immediately
     * or buffered for later dispatch.
     *
     * @param  AnalyticsEvent  $event
     * @return void
     */
    public function process(AnalyticsEvent $event): void
    {
        match ($this->strategy) {
            self::STRATEGY_IMMEDIATE => $this->dispatchImmediate($event),
            self::STRATEGY_BUFFERED => $this->bufferEvent($event),
            self::STRATEGY_PERIODIC => $this->periodicProcess($event),
            self::STRATEGY_BATCH_WINDOW => $this->batchWindowProcess($event),
            default => $this->dispatchImmediate($event),
        };
    }

    /**
     * Flush all buffered events.
     *
     * Dispatches all events currently in the buffer and clears it.
     *
     * @return int Number of events flushed
     */
    public function flush(): int
    {
        $count = count($this->buffer);

        if ($count === 0) {
            return 0;
        }

        foreach ($this->buffer as $event) {
            $this->manager->trackEvent($event);
        }

        $this->buffer = [];
        $this->bufferSize = 0;
        $this->totalFlushed += $count;
        $this->lastFlushTime = microtime(true);

        return $count;
    }

    /**
     * Get the current buffer contents (for inspection/testing).
     *
     * @return list<AnalyticsEvent>
     */
    public function getBuffer(): array
    {
        return $this->buffer;
    }

    /**
     * Get the current buffer size.
     */
    public function getBufferSize(): int
    {
        return $this->bufferSize;
    }

    /**
     * Check if the buffer has events pending.
     */
    public function hasPendingEvents(): bool
    {
        return $this->bufferSize > 0;
    }

    /**
     * Get the active flushing strategy.
     */
    public function getStrategy(): string
    {
        return $this->strategy;
    }

    /**
     * Change the flushing strategy at runtime.
     *
     * Changing strategy triggers an automatic flush of any buffered events
     * before switching to the new strategy.
     *
     * @param  string  $strategy  One of the STRATEGY_* constants
     * @return void
     */
    public function setStrategy(string $strategy): void
    {
        // Flush any pending events before switching
        $this->flush();

        $validStrategies = [
            self::STRATEGY_IMMEDIATE,
            self::STRATEGY_BUFFERED,
            self::STRATEGY_PERIODIC,
            self::STRATEGY_BATCH_WINDOW,
        ];

        if (in_array($strategy, $validStrategies, true)) {
            $this->strategy = $strategy;
        }
    }

    /**
     * Get flushing statistics.
     *
     * @return array{strategy: string, buffer_size: int, max_buffer_size: int, batch_window: int, total_flushed: int, total_immediate: int, last_flush_time: string|null, has_pending: bool}
     */
    public function stats(): array
    {
        return [
            'strategy' => $this->strategy,
            'buffer_size' => $this->bufferSize,
            'max_buffer_size' => $this->maxBufferSize,
            'batch_window' => $this->batchWindowSeconds,
            'total_flushed' => $this->totalFlushed,
            'total_immediate' => $this->totalImmediate,
            'last_flush_time' => $this->lastFlushTime > 0
                ? date('Y-m-d\TH:i:s.vP', (int) $this->lastFlushTime)
                : null,
            'has_pending' => $this->hasPendingEvents(),
        ];
    }

    /**
     * Reset all statistics and clear the buffer.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->buffer = [];
        $this->bufferSize = 0;
        $this->totalFlushed = 0;
        $this->totalImmediate = 0;
        $this->lastFlushTime = microtime(true);
    }

    /**
     * Dispatch an event immediately (bypass buffer).
     *
     * @param  AnalyticsEvent  $event
     * @return void
     */
    private function dispatchImmediate(AnalyticsEvent $event): void
    {
        $this->manager->trackEvent($event);
        $this->totalImmediate++;
    }

    /**
     * Buffer an event. Auto-flushes when max buffer size is reached.
     *
     * @param  AnalyticsEvent  $event
     * @return void
     */
    private function bufferEvent(AnalyticsEvent $event): void
    {
        $this->buffer[] = $event;
        $this->bufferSize++;

        if ($this->bufferSize >= $this->maxBufferSize) {
            $this->flush();
        }
    }

    /**
     * Process event with periodic flushing.
     *
     * Buffers events and auto-flushes if the periodic interval has elapsed.
     *
     * @param  AnalyticsEvent  $event
     * @return void
     */
    private function periodicProcess(AnalyticsEvent $event): void
    {
        $this->bufferEvent($event);

        $now = microtime(true);
        $elapsed = $now - $this->lastFlushTime;

        if ($elapsed >= $this->batchWindowSeconds) {
            $this->flush();
        }
    }

    /**
     * Process event with batch window strategy.
     *
     * Similar to periodic but only flushes when the window elapses
     * AND there are events in the buffer.
     *
     * @param  AnalyticsEvent  $event
     * @return void
     */
    private function batchWindowProcess(AnalyticsEvent $event): void
    {
        $this->bufferEvent($event);

        $now = microtime(true);
        $elapsed = $now - $this->lastFlushTime;

        if ($elapsed >= $this->batchWindowSeconds && $this->hasPendingEvents()) {
            $this->flush();
        }
    }
}
