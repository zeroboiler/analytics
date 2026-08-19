<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Queue;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Service for dispatching analytics events synchronously or asynchronously.
 *
 * Reads the `zeroboiler.analytics.queue` configuration to decide dispatch mode.
 * When queue is enabled, events are pushed to a background worker via
 * DispatchAnalyticsJob / BatchDispatchAnalyticsJob. When disabled,
 * events are dispatched synchronously through AnalyticsManager.
 *
 * Batch dispatch chunks large event lists into multiple jobs according
 * to the configured max_batch_size to prevent oversized payloads.
 *
 * @since 255.0.0
 */
final class AnalyticsQueueService
{
    private bool $queueEnabled;

    private int $maxBatchSize;

    public function __construct(
        private AnalyticsManager $manager,
        ConfigRepository $config,
    ) {
        $queueConfig = $config->get('zeroboiler.analytics.queue', []);
        /** @var array{enabled?: bool, max_batch_size?: int} $queueConfig */
        $this->queueEnabled = (bool) ($queueConfig['enabled'] ?? true);
        $this->maxBatchSize = (int) ($queueConfig['max_batch_size'] ?? 50);
    }

    /**
     * Dispatch a single analytics event (sync or async based on config).
     */
    public function dispatch(AnalyticsEvent $event): void
    {
        if ($this->queueEnabled) {
            DispatchAnalyticsJob::dispatch(
                [$this->serializeEvent($event)],
                $event->clientId,
                $event->userId,
            );

            return;
        }

        $this->manager->trackEvent($event);
    }

    /**
     * Dispatch multiple analytics events, batching as needed.
     *
     * @param  list<AnalyticsEvent>  $events
     */
    public function dispatchBatch(array $events): void
    {
        if ($events === []) {
            return;
        }

        if (! $this->queueEnabled) {
            foreach ($events as $event) {
                $this->manager->trackEvent($event);
            }

            return;
        }

        // Chunk into batch-sized jobs
        $chunks = array_chunk(
            array_map(fn (AnalyticsEvent $e): array => $this->serializeEvent($e), $events),
            $this->maxBatchSize,
        );

        $firstEvent = $events[0];

        foreach ($chunks as $chunk) {
            BatchDispatchAnalyticsJob::dispatch(
                $chunk,
                $firstEvent->clientId,
                $firstEvent->userId,
            );
        }
    }

    /**
     * Check if queue dispatch is enabled.
     */
    public function isQueueEnabled(): bool
    {
        return $this->queueEnabled;
    }

    /**
     * Get the configured max batch size.
     */
    public function getMaxBatchSize(): int
    {
        return $this->maxBatchSize;
    }

    /**
     * Serialize an AnalyticsEvent for queue transport.
     *
     * @return array{name: string, params: array<string, mixed>, category: string|null, client_id: string|null, user_id: string|null, timestamp: string|null}
     */
    private function serializeEvent(AnalyticsEvent $event): array
    {
        return [
            'name' => $event->name,
            'params' => $event->params,
            'category' => $event->category,
            'client_id' => $event->clientId,
            'user_id' => $event->userId,
            'timestamp' => $event->timestamp?->format('c'),
        ];
    }
}
