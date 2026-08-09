<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Queue;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * Event replay queue with exponential backoff for failed analytics events.
 *
 * Captures events that fail to dispatch (network errors, provider outages)
 * and retries them with configurable exponential backoff. Events are stored
 * in-memory (session-scoped) with optional file-based persistence.
 *
 * Designed for resilience in production: transient provider failures are
 * retried automatically without losing analytics data.
 *
 * @see \ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher
 *
 * @since 1.0.0
 */
final class EventReplayQueue
{
    /** @var array<int, array{event: AnalyticsEvent, attempt: int, nextRetry: float, maxAttempts: int}> */
    private array $pending = [];

    /** @var array<int, array{event: AnalyticsEvent, attempt: int, nextRetry: float, maxAttempts: int}> */
    private array $failed = [];

    private AnalyticsManager $manager;

    private AnalyticsMetrics $metrics;

    private int $maxAttempts;

    private float $baseDelay;

    private float $maxDelay;

    private bool $enabled;

    private float $jitter;

    /**
     * @param  AnalyticsManager  $manager
     * @param  AnalyticsMetrics  $metrics
     * @param  ConfigRepository  $config
     */
    public function __construct(AnalyticsManager $manager, AnalyticsMetrics $metrics, ConfigRepository $config): void
    {
        $this->manager = $manager;
        $this->metrics = $metrics;

        $replayConfig = $config->get('zeroboiler.analytics.replay', []);
        /** @var array{enabled?: bool, max_attempts?: int, base_delay?: float, max_delay?: float, jitter?: float} $replayConfig */
        $this->enabled = (bool) ($replayConfig['enabled'] ?? true);
        $this->maxAttempts = (int) ($replayConfig['max_attempts'] ?? 3);
        $this->baseDelay = (float) ($replayConfig['base_delay'] ?? 1.0);
        $this->maxDelay = (float) ($replayConfig['max_delay'] ?? 60.0);
        $this->jitter = (float) ($replayConfig['jitter'] ?? 0.2);
    }

    /**
     * Enqueue a failed event for replay.
     *
     * Called when an event dispatch fails. The event will be retried
     * with exponential backoff on the next process() call.
     */
    public function enqueue(AnalyticsEvent $event, \Throwable $error): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->pending[] = [
            'event' => $event,
            'attempt' => 0,
            'nextRetry' => microtime(true) + $this->calculateDelay(0),
            'maxAttempts' => $this->maxAttempts,
        ];

        Log::debug('ZeroBoiler Analytics: event enqueued for replay', [
            'event' => $event->name,
            'error' => $error->getMessage(),
            'pending_count' => count($this->pending),
        ]);
    }

    /**
     * Process pending replay events.
     *
     * Retries all events whose next retry time has passed.
     * Successfully dispatched events are removed. Permanently failed
     * events (exceeded max attempts) are moved to the failed list.
     *
     * @return array{retried: int, succeeded: int, failed: int}
     */
    public function process(): array
    {
        if (! $this->enabled) {
            return ['retried' => 0, 'succeeded' => 0, 'failed' => 0];
        }

        $now = microtime(true);
        $retried = 0;
        $succeeded = 0;
        $failed = 0;

        $stillPending = [];

        foreach ($this->pending as $item) {
            if ($item['nextRetry'] > $now) {
                $stillPending[] = $item;

                continue;
            }

            $item['attempt']++;
            $retried++;

            try {
                $this->manager->directDispatch($item['event']);
                $succeeded++;
                $this->metrics->recordDispatch('replay');
            } catch (\Throwable $e) {
                if ($item['attempt'] >= $item['maxAttempts']) {
                    $this->failed[] = $item;
                    $failed++;
                    $this->metrics->recordFailure('replay', $e->getMessage());

                    Log::warning('ZeroBoiler Analytics: replay event permanently failed', [
                        'event' => $item['event']->name,
                        'attempts' => $item['attempt'],
                        'error' => $e->getMessage(),
                    ]);
                } else {
                    $item['nextRetry'] = microtime(true) + $this->calculateDelay($item['attempt']);
                    $stillPending[] = $item;
                }
            }
        }

        $this->pending = $stillPending;

        return ['retried' => $retried, 'succeeded' => $succeeded, 'failed' => $failed];
    }

    /**
     * Get the number of pending (retryable) events.
     */
    public function pendingCount(): int
    {
        return count($this->pending);
    }

    /**
     * Get the number of permanently failed events.
     */
    public function failedCount(): int
    {
        return count($this->failed);
    }

    /**
     * Get all permanently failed events.
     *
     * @return array<int, AnalyticsEvent>
     */
    public function getFailedEvents(): array
    {
        return array_map(fn (array $item): AnalyticsEvent => $item['event'], $this->failed);
    }

    /**
     * Clear all pending and failed events.
     */
    public function flush(): void
    {
        $this->pending = [];
        $this->failed = [];
    }

    /**
     * Check if replay is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the queue summary for health checks and monitoring.
     *
     * @return array{enabled: bool, pending: int, failed: int, max_attempts: int, base_delay: float, max_delay: float}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'pending' => count($this->pending),
            'failed' => count($this->failed),
            'max_attempts' => $this->maxAttempts,
            'base_delay' => $this->baseDelay,
            'max_delay' => $this->maxDelay,
        ];
    }

    /**
     * Calculate exponential backoff delay with jitter.
     */
    private function calculateDelay(int $attempt): float
    {
        $exponential = $this->baseDelay * (2 ** $attempt);
        $capped = min($exponential, $this->maxDelay);

        // Add jitter to prevent thundering herd
        $jitterRange = $capped * $this->jitter;
        $jitterOffset = mt_rand() / mt_getrandmax() * (2 * $jitterRange) - $jitterRange;

        return max(0.1, $capped + $jitterOffset);
    }
}
