<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Queue;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventBatchJob;
use ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventJob;

/**
 * Dispatches analytics events asynchronously via Laravel queues.
 *
 * When queue is enabled, events are dispatched as serializable Job classes
 * (TrackAnalyticsEventJob / TrackAnalyticsEventBatchJob) instead of
 * closures. This ensures compatibility with redis, database, and all
 * queue drivers that require serializable jobs.
 *
 * Uses the 'analytics' queue connection by default.
 *
 * @see \ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventJob
 * @see \ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventBatchJob
 *
 * @since 1.0.0
 */
final class QueuedAnalyticsDispatcher
{
    private AnalyticsManager $manager;

    private bool $enabled;

    private string $queueName;

    private ?string $connection;

    /** @var int Max events per batch job */
    private int $maxBatchSize;

    public function __construct(AnalyticsManager $manager, ConfigRepository $config): void
    {
        $this->manager = $manager;

        $queueConfig = $config->get('zeroboiler.analytics.queue', []);
        /** @var array{enabled?: bool, queue?: string, connection?: string, max_batch_size?: int} $queueConfig */
        $this->enabled = (bool) ($queueConfig['enabled'] ?? true);
        $this->queueName = $queueConfig['queue'] ?? 'analytics';
        $this->connection = $queueConfig['connection'] ?? null;
        $this->maxBatchSize = (int) ($queueConfig['max_batch_size'] ?? 50);
    }

    /**
     * Dispatch a single analytics event to the queue.
     *
     * Uses a serializable job class (TrackAnalyticsEventJob) for
     * compatibility with all queue drivers.
     */
    public function dispatch(AnalyticsEvent $event): void
    {
        if (! $this->enabled) {
            // Queue disabled — dispatch synchronously
            $this->manager->trackEvent($event);

            return;
        }

        $job = new TrackAnalyticsEventJob(
            name: $event->name,
            params: $event->params,
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp?->getTimestamp(),
            priority: $event->priority,
        );

        $pendingJob = dispatch($job)
            ->onQueue($this->queueName)
            ->afterCommit();

        if ($this->connection !== null) {
            $pendingJob->onConnection($this->connection);
        }
    }

    /**
     * Dispatch multiple analytics events as batched jobs.
     *
     * Events are chunked into batches of maxBatchSize to keep
     * individual jobs manageable. All events in a batch are processed
     * in a single queue job to reduce overhead.
     *
     * @param  array<AnalyticsEvent>  $events
     */
    public function dispatchBatch(array $events): void
    {
        if (empty($events)) {
            return;
        }

        if (! $this->enabled) {
            foreach ($events as $event) {
                $this->manager->trackEvent($event);
            }

            return;
        }

        // Chunk events into batches to avoid oversized jobs
        $chunks = array_chunk($events, $this->maxBatchSize);

        foreach ($chunks as $chunk) {
            $eventData = array_map(
                fn (AnalyticsEvent $event): array => [
                    'name' => $event->name,
                    'params' => $event->params,
                    'client_id' => $event->clientId,
                    'user_id' => $event->userId,
                    'timestamp' => $event->timestamp?->getTimestamp(),
                    'priority' => $event->priority,
                ],
                $chunk,
            );

            $job = new TrackAnalyticsEventBatchJob(events: $eventData);

            $pendingJob = dispatch($job)
                ->onQueue($this->queueName)
                ->afterCommit();

            if ($this->connection !== null) {
                $pendingJob->onConnection($this->connection);
            }
        }
    }

    /**
     * Set a specific queue connection for dispatched jobs.
     */
    public function onConnection(string $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * Set a specific queue name for dispatched jobs.
     */
    public function onQueue(string $queue): self
    {
        $this->queueName = $queue;

        return $this;
    }

    /**
     * Enable or disable queued dispatch.
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    /**
     * Check if queued dispatch is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the configured max batch size.
     */
    public function getMaxBatchSize(): int
    {
        return $this->maxBatchSize;
    }
}
