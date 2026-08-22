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
 * Queued job for async analytics event dispatch.
 *
 * Offloads event dispatch from the request lifecycle to a background worker.
 * The serialized event DTO is dispatched through AnalyticsManager with all
 * configured providers when the job is processed.
 *
 * Supports:
 *  - Configurable queue name and connection
 *  - Retry with exponential backoff (max 3 attempts)
 *  - Dead letter queue for permanently failed events
 *  - Batch mode: dispatch multiple events in a single job
 *
 * @since 255.0.0
 */
final class DispatchAnalyticsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying (exponential backoff).
     */
    public int|array $backoff = [5, 15, 60];

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 30;

    /**
     * @param  list<array{name: string, params: array<string, mixed>, category?: string, client_id?: string|null, user_id?: string|null, timestamp?: string|null}>  $events
     */
    public function __construct(
        private array $events,
        private ?string $clientId = null,
        private ?string $userId = null,
    ) {
        $queueConfig = config('zeroboiler.analytics.queue', []);
        /** @var array{queue?: string, connection?: string|null} $queueConfig */
        $this->queue = (string) ($queueConfig['queue'] ?? 'analytics');
        $this->connection = $queueConfig['connection'] ?? null;
    }

    /**
     * Execute the job — dispatch all events through the analytics pipeline.
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

            // Preserve original timestamp if provided
            if (isset($eventData['timestamp']) && is_string($eventData['timestamp'])) {
                $event = $event->withTimestamp($eventData['timestamp']);
            }

            $manager->trackEvent($event);
        }
    }

    /**
     * Handle a job failure.
     *
     * Logs the failure context for debugging. Events that exceed retries
     * are automatically routed to the dead letter queue by Laravel.
     */
    public function failed(\Throwable $exception): void
    {
        logger()->warning('ZeroBoiler Analytics: queued dispatch failed', [
            'events' => count($this->events),
            'client_id' => $this->clientId,
            'user_id' => $this->userId,
            'attempt' => $this->attempts(),
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Get the display name of the job.
     */
    public function displayName(): string
    {
        return 'zb:analytics:dispatch';
    }

    /**
     * Get the tags for the job.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return [
            'zb:analytics',
            'client:' . ($this->clientId ?? 'anonymous'),
        ];
    }
}
