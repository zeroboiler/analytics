<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Queue;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Dispatches analytics events asynchronously via Laravel queues.
 *
 * When queue is enabled, events are queued and processed in a background
 * worker instead of blocking the HTTP request. Uses the 'analytics' queue
 * connection by default.
 */
class QueuedAnalyticsDispatcher
{
    private AnalyticsManager $manager;

    private bool $enabled;

    private string $queueName;

    private ?string $connection;

    public function __construct(AnalyticsManager $manager, ConfigRepository $config)
    {
        $this->manager = $manager;

        $queueConfig = $config->get('zeroboiler.analytics.queue', []);
        /** @var array{enabled?: bool, queue?: string, connection?: string} $queueConfig */
        $this->enabled = (bool) ($queueConfig['enabled'] ?? true);
        $this->queueName = $queueConfig['queue'] ?? 'analytics';
        $this->connection = $queueConfig['connection'] ?? null;
    }

    /**
     * Dispatch a single analytics event to the queue.
     */
    public function dispatch(AnalyticsEvent $event): void
    {
        if (! $this->enabled) {
            // Queue disabled — dispatch synchronously
            $this->manager->trackEvent($event);

            return;
        }

        $pendingJob = dispatch(function () use ($event): void {
            $this->safeTrack($event);
        })
            ->onQueue($this->queueName)
            ->afterCommit();

        if ($this->connection !== null) {
            $pendingJob->onConnection($this->connection);
        }
    }

    /**
     * Dispatch multiple analytics events as a batch job.
     *
     * All events are processed in a single queue job to reduce overhead.
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

        $pendingJob = dispatch(function () use ($events): void {
            foreach ($events as $event) {
                $this->safeTrack($event);
            }
        })
            ->onQueue($this->queueName)
            ->afterCommit();

        if ($this->connection !== null) {
            $pendingJob->onConnection($this->connection);
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
     * Track an event with error handling to prevent queue job failures.
     */
    private function safeTrack(AnalyticsEvent $event): void
    {
        try {
            $this->manager->trackEvent($event);
        } catch (\Throwable $e) {
            Log::error('QueuedAnalyticsDispatcher: failed to track event', [
                'event' => $event->name,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
