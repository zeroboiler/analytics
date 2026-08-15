<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Serializable queue job for dispatching a batch of analytics events.
 *
 * Processes all events in a single job to reduce queue overhead.
 * Events that fail individually are logged but do not prevent
 * remaining events from being processed.
 *
 * @see \ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher
 *
 * @since 1.0.0
 */
final readonly class TrackAnalyticsEventBatchJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying after an exception.
     */
    public int $backoff = 5;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 120;

    /**
     * @param  list<array{name: string, params: array<string, mixed>, client_id?: string|null, user_id?: string|null, timestamp?: int|null, priority?: string|null}>  $events
     */
    public function __construct(
        public array $events,
    ): void {}

    /**
     * Execute the job — dispatch all events to all enabled trackers.
     */
    #[Override]
    public function handle(AnalyticsManager $manager): void
    {
        $failedCount = 0;

        foreach ($this->events as $eventData) {
            $event = new AnalyticsEvent(
                name: $eventData['name'],
                params: $eventData['params'],
                clientId: $eventData['client_id'] ?? null,
                userId: $eventData['user_id'] ?? null,
                timestamp: isset($eventData['timestamp'])
                    ? \DateTimeImmutable::createFromFormat('U', (string) $eventData['timestamp']) ?: null
                    : null,
                priority: $eventData['priority'] ?? null,
            );

            try {
                $manager->trackEvent($event);
            } catch (\Throwable $e) {
                $failedCount++;
                Log::error('TrackAnalyticsEventBatchJob: failed to track event in batch', [
                    'event' => $eventData['name'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($failedCount > 0) {
            Log::warning('TrackAnalyticsEventBatchJob: completed with failures', [
                'total' => count($this->events),
                'failed' => $failedCount,
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('TrackAnalyticsEventBatchJob: job failed entirely', [
            'event_count' => count($this->events),
            'error' => $exception->getMessage(),
        ]);
    }
}
