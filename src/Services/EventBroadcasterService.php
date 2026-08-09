<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Real-time analytics event broadcasting service.
 *
 * Broadcasts analytics events to frontend clients via Laravel Echo/Broadcasting
 * channels. Enables live dashboards, real-time admin panels, and in-app
 * notifications for high-value events (purchases, signups, errors).
 *
 * Configuration is read from `zeroboiler.analytics.broadcast`.
 *
 * Supports:
 * - Selective event broadcasting (only specific events)
 * - Threshold-based broadcasting (only above value threshold)
 * - Channel-based routing (per-tenant, per-category, global)
 * - Event enrichment with metrics data
 * - Private and public channel support
 *
 * @since 1.0.0
 */
final class EventBroadcasterService
{
    /** @var list<string> Events always broadcast regardless of filter rules */
    private const ALWAYS_BROADCAST = [
        'purchase',
        'subscription',
        'sign_up',
        'cancellation',
        'error',
        'js_error',
    ];

    private bool $enabled;

    /** @var list<string> Event name patterns to broadcast (empty = all) */
    private array $filterEvents;

    /** @var list<string> Event categories to broadcast (empty = all) */
    private array $filterCategories;

    /** @var string Broadcast channel prefix */
    private string $channelPrefix;

    /** @var bool Whether to use private channels */
    private bool $privateChannels;

    /** @var float|null Minimum event value to broadcast (for revenue events) */
    private ?float $valueThreshold;

    /** @var bool Whether to include full event params in broadcast */
    private bool $includeParams;

    /** @var int Maximum event name length for broadcast payload */
    private int $maxPayloadSize;

    /**
     * @param  ConfigRepository  $config
     * @param  Broadcaster|null  $broadcaster  Optional broadcaster (null = auto-resolve)
     */
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ?Broadcaster $broadcaster = null,
    ): void {
        $broadcastConfig = $config->get('zeroboiler.analytics.broadcast', []);
        /** @var array{enabled?: bool, filter_events?: list<string>, filter_categories?: list<string>, channel_prefix?: string, private_channels?: bool, value_threshold?: float|null, include_params?: bool, max_payload_size?: int} $broadcastConfig */

        $this->enabled = (bool) ($broadcastConfig['enabled'] ?? false);
        $this->filterEvents = $broadcastConfig['filter_events'] ?? [];
        $this->filterCategories = $broadcastConfig['filter_categories'] ?? [];
        $this->channelPrefix = (string) ($broadcastConfig['channel_prefix'] ?? 'analytics');
        $this->privateChannels = (bool) ($broadcastConfig['private_channels'] ?? true);
        $this->valueThreshold = $broadcastConfig['value_threshold'] ?? null;
        $this->includeParams = (bool) ($broadcastConfig['include_params'] ?? true);
        $this->maxPayloadSize = (int) ($broadcastConfig['max_payload_size'] ?? 1024);
    }

    /**
     * Broadcast an analytics event to configured channels.
     *
     * Routes the event to:
     * 1. The global analytics channel: `{prefix}.events`
     * 2. The category channel: `{prefix}.{category}`
     * 3. The specific event channel: `{prefix}.events.{name}`
     *
     * @return bool Whether the event was broadcast
     */
    public function broadcast(AnalyticsEvent $event): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if (! $this->shouldBroadcast($event)) {
            return false;
        }

        $broadcaster = $this->resolveBroadcaster();
        if ($broadcaster === null) {
            return false;
        }

        $payload = $this->buildPayload($event);

        try {
            // Broadcast to global analytics channel
            $globalChannel = $this->channelPrefix . '.events';
            $this->sendToChannel($broadcaster, $globalChannel, 'analytics.event', $payload);

            // Broadcast to category channel
            $category = $this->resolveCategory($event);
            if ($category !== null) {
                $categoryChannel = $this->channelPrefix . '.' . $category;
                $this->sendToChannel($broadcaster, $categoryChannel, 'analytics.event', $payload);
            }

            // Broadcast to specific event channel
            $eventChannel = $this->channelPrefix . '.events.' . $event->name;
            $this->sendToChannel($broadcaster, $eventChannel, 'analytics.event', $payload);

            return true;
        } catch (\Throwable $e) {
            try {
                Log::debug('EventBroadcasterService: broadcast failed', [
                    'event' => $event->name,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
                // Log facade unavailable
            }

            return false;
        }
    }

    /**
     * Broadcast a high-priority alert event.
     *
     * Uses a dedicated `.alerts` channel for events that require immediate
     * attention (errors, cancellations, large transactions).
     */
    public function broadcastAlert(AnalyticsEvent $event, string $severity = 'info'): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $broadcaster = $this->resolveBroadcaster();
        if ($broadcaster === null) {
            return false;
        }

        $payload = $this->buildPayload($event);
        $payload['severity'] = $severity;
        $payload['alert'] = true;

        try {
            $alertChannel = $this->channelPrefix . '.alerts';
            $this->sendToChannel($broadcaster, $alertChannel, 'analytics.alert', $payload);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Broadcast a metrics snapshot to the dashboard channel.
     *
     * @param  array<string, mixed>  $metrics
     */
    public function broadcastMetrics(array $metrics): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $broadcaster = $this->resolveBroadcaster();
        if ($broadcaster === null) {
            return false;
        }

        try {
            $metricsChannel = $this->channelPrefix . '.metrics';
            $this->sendToChannel($broadcaster, $metricsChannel, 'analytics.metrics', $metrics);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if broadcasting is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the channel prefix.
     */
    public function getChannelPrefix(): string
    {
        return $this->channelPrefix;
    }

    /**
     * Get the full channel name for a given suffix.
     */
    public function channelName(string $suffix = ''): string
    {
        $suffix = $suffix !== '' ? '.' . $suffix : '';

        return $this->channelPrefix . $suffix;
    }

    /**
     * Determine if an event should be broadcast based on filter rules.
     */
    private function shouldBroadcast(AnalyticsEvent $event): bool
    {
        // Always broadcast critical events
        if (in_array($event->name, self::ALWAYS_BROADCAST, true)) {
            return true;
        }

        // Category filter
        if ($this->filterCategories !== []) {
            $category = $this->resolveCategory($event);
            if ($category !== null && ! in_array($category, $this->filterCategories, true)) {
                return false;
            }
        }

        // Event name filter
        if ($this->filterEvents !== []) {
            if (! in_array($event->name, $this->filterEvents, true)) {
                return false;
            }
        }

        // Value threshold filter
        if ($this->valueThreshold !== null) {
            $value = (float) ($event->params['value'] ?? $event->params['price'] ?? 0);
            if ($value < $this->valueThreshold) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build the broadcast payload from an analytics event.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(AnalyticsEvent $event): array
    {
        $payload = [
            'event' => $event->name,
            'timestamp' => now()->toIso8601String(),
            'client_id' => $event->clientId,
            'user_id' => $event->userId,
            'category' => $this->resolveCategory($event),
        ];

        if ($this->includeParams) {
            $payload['params'] = $event->params;
        }

        // Truncate payload if needed
        $serialized = json_encode($payload) ?: '{}';
        if (strlen($serialized) > $this->maxPayloadSize) {
            $payload['params'] = ['_truncated' => true];
        }

        return $payload;
    }

    /**
     * Resolve the event category from the event name.
     */
    private function resolveCategory(AnalyticsEvent $event): ?string
    {
        return \ZeroBoiler\Analytics\Events\EventCatalog::getCategory($event->name);
    }

    /**
     * Resolve or get the broadcaster instance.
     */
    private function resolveBroadcaster(): ?Broadcaster
    {
        if ($this->broadcaster !== null) {
            return $this->broadcaster;
        }

        try {
            return app(Broadcaster::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Send a payload to a specific channel.
     *
     * @param  Broadcaster  $broadcaster
     * @param  string  $channel
     * @param  string  $event
     * @param  array<string, mixed>  $payload
     */
    private function sendToChannel(Broadcaster $broadcaster, string $channel, string $event, array $payload): void
    {
        $fullChannel = $this->privateChannels
            ? 'private-' . $channel
            : $channel;

        $broadcaster->broadcast(
            [$fullChannel],
            $event,
            $payload,
        );
    }
}
