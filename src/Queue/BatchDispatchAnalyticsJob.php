<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Queued job for batch analytics event dispatch.
 *
 * Processes a batch of events in a single job to reduce queue overhead.
 * Batches are chunked according to the configured max_batch_size.
 * Each chunk is processed sequentially within the job.
 *
 * Use AnalyticsQueueService::dispatchBatch() instead of dispatching directly.
 *
 * @see \ZeroBoiler\Analytics\Queue\AnalyticsQueueService
 *
 * @since 255.0.0
 */
final class BatchDispatchAnalyticsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var int */
    public int $tries = 2;

    /** @var int */
    public int $timeout = 60;

    /** @var array<int, array{name: string, params: array<string, mixed>, category?: string, client_id?: string|null, user_id?: string|null}> */
    private array $events;

    /**
     * @param  list<array{name: string, params: array<string, mixed>, category?: string, client_id?: string|null, user_id?: string|null}>  $events
     */
    public function __construct(
        array $events,
        private ?string $clientId = null,
        private ?string $userId = null,
    ) {
        $this->events = $events;
        $queueConfig = config('zeroboiler.analytics.queue', []);
        /** @var array{queue?: string, connection?: string|null} $queueConfig */
        $this->queue = (string) ($queueConfig['queue'] ?? 'analytics');
        $this->connection = $queueConfig['connection'] ?? null;
    }

    /**
     * Execute the job — dispatch all batched events.
     */
    public function handle(AnalyticsManager $manager): void
    {
        foreach ($this->events as $eventData) {
            $event = new AnalyticsEvent(
                name: $eventData['name'],
                params: $eventData['params'] ?? [],
                clientId: $eventData['client_id'] ?? $this->clientId,
                userId: $eventData['user_id'] ?? $this->userId,
                category: $eventData['category'] ?? null,
            );

            if (isset($eventData['timestamp']) && is_string($eventData['timestamp'])) {
                $event = $event->withTimestamp($eventData['timestamp']);
            }

            $manager->trackEvent($event);
        }
    }

    /**
 * Handle a job failure.
 */
    public function failed(\Throwable $exception): void
    {
        logger()->warning('ZeroBoiler Analytics: batch dispatch failed', [
            'batch_size' => count($this->events),
            'client_id' => $this->clientId,
            'error' => $exception->getMessage(),
        ]);
    }

    public function displayName(): string
    {
        return 'zb:analytics:batch-dispatch';
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return [
            'zb:analytics',
            'batch',
            'client:' . ($this->clientId ?? 'anonymous'),
        ];
    }
}
