<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Jobs;

use Illuminate\Bus\Queueable as QueueableTrait;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Serializable queue job for dispatching a single analytics event.
 *
 * Replaces the closure-based dispatch in QueuedAnalyticsDispatcher with
 * a proper serializable job class. This is required for redis/database
 * queue drivers where closures cannot be serialized.
 *
 * Preserves full event metadata across async boundaries: source, category,
 * and session_id are serialized alongside core fields.
 *
 * @see \ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher
 *
 * @since 1.0.0
 */
final class TrackAnalyticsEventJob implements ShouldQueue
{
    use InteractsWithQueue;
    use QueueableTrait;

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
    public int $timeout = 30;

    /**
     * Create a new analytics event tracking job.
     *
     * @param  string  $name  Event name
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     * @param  int|null  $timestamp  Event timestamp (Unix epoch)
     * @param  string|null  $priority  Event priority level
     * @param  string|null  $source  Event origin (api|server|client|webhook|replay|batch)
     * @param  string|null  $category  Event category (ecommerce|saas|engagement|security|uptime|infrastructure|marketing|customer_success|webhook)
     * @param  string|null  $sessionId  Session identifier for event grouping
     */
    public function __construct(
        public string $name,
        public array $params,
        public ?string $clientId = null,
        public ?string $userId = null,
        public ?int $timestamp = null,
        public ?string $priority = null,
        public ?string $source = null,
        public ?string $category = null,
        public ?string $sessionId = null,
    ){}

    /**
     * Execute the job — dispatch the event to all enabled trackers.
     */
    #[Override]
    public function handle(AnalyticsManager $manager): void
    {
        $event = new AnalyticsEvent(
            name: $this->name,
            params: $this->params,
            clientId: $this->clientId,
            userId: $this->userId,
            timestamp: $this->timestamp !== null
                ? \DateTimeImmutable::createFromFormat('U', (string) $this->timestamp) ?: null
                : null,
            priority: $this->priority,
            source: $this->source,
            category: $this->category,
            sessionId: $this->sessionId,
        );

        $manager->trackEvent($event);
    }

    /**
     * Handle a job failure — log and mark as failed.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('TrackAnalyticsEventJob: failed to track event', [
            'event' => $this->name,
            'client_id' => $this->clientId,
            'user_id' => $this->userId,
            'source' => $this->source,
            'category' => $this->category,
            'session_id' => $this->sessionId,
            'attempt' => $this->attempts(),
            'error' => $exception->getMessage(),
        ]);
    }
}
