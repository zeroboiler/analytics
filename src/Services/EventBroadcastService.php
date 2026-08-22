<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Analytics event broadcasting service for real-time WebSocket delivery.
 *
 * Broadcasts analytics events through Laravel's broadcasting system
 * (Pusher, Soketi, Reverb, Ably, Redis Pub/Sub) to enable real-time
 * admin dashboards, live user activity monitors, and instant alerting.
 *
 * Supports both public and private channels:
 * - `analytics.events` — public channel for all events (global dashboard)
 * - `analytics.events.{category}` — category-scoped (e.g. analytics.events.ecommerce)
 * - `analytics.tenant.{tenantId}` — private channel for multi-tenant dashboards
 * - `analytics.admin` — private channel for admin-only event stream
 *
 * Events are broadcast after being dispatched through the analytics pipeline,
 * ensuring only validated, enriched events reach WebSocket clients.
 *
 * Configuration:
 * - `zeroboiler.analytics.broadcasting.enabled` — master toggle
 * - `zeroboiler.analytics.broadcasting.channels` — channel configuration
 * - `zeroboiler.analytics.broadcasting.public_channel` — public channel name
 * - `zeroboiler.analytics.broadcasting.admin_channel` — admin channel name
 * - `zeroboiler.analytics.broadcasting.include_params` — include full event params
 * - `zeroboiler.analytics.broadcasting.sensitive_params` — params to redact
 * - `zeroboiler.analytics.broadcasting.batch_size` — batch multiple events
 * - `zeroboiler.analytics.broadcasting.categories` — categories to broadcast
 *
 * @since 92.0.0
 */
final class EventBroadcastService
{
    /** @var list<string> Default sensitive parameters to redact from broadcasts */
    private const DEFAULT_SENSITIVE_PARAMS = [
        'password', 'token', 'secret', 'api_key', 'credit_card',
        'ssn', 'email', 'phone', 'ip',
    ];

    /** @var list<string> Default categories to broadcast */
    private const DEFAULT_CATEGORIES = [
        'ecommerce', 'saas', 'engagement', 'security',
        'uptime', 'infrastructure', 'marketing',
    ];

    /**
     * @param  ConfigRepository  $config  Configuration repository
     * @param  Broadcaster|null  $broadcaster  Laravel broadcaster (null if broadcasting not configured)
     */
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ?Broadcaster $broadcaster,
    ){}

    /**
     * Check if event broadcasting is enabled.
     *
     * Requires both the analytics config flag and a configured broadcaster.
     */
    public function isEnabled(): bool
    {
        $broadcastConfig = $this->getBroadcastConfig();

        return (bool) ($broadcastConfig['enabled'] ?? false)
            && $this->broadcaster !== null;
    }

    /**
     * Broadcast an analytics event to configured channels.
     *
     * Dispatches the event to all applicable channels based on
     * configuration: public, category-scoped, tenant-scoped, and admin.
     *
     * Sensitive parameters are redacted before broadcasting.
     * Silently catches exceptions to never break the event pipeline.
     *
     * @param  AnalyticsEvent  $event  The analytics event to broadcast
     * @param  array<string, mixed>  $metadata  Additional metadata (source, timestamp, etc.)
     */
    public function broadcast(AnalyticsEvent $event, array $metadata = []): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $broadcastConfig = $this->getBroadcastConfig();
        $categories = (array) ($broadcastConfig['categories'] ?? self::DEFAULT_CATEGORIES);

        // Check category filter
        $category = $metadata['category'] ?? null;
        if ($category !== null && ! in_array($category, $categories, true)) {
            return;
        }

        $payload = $this->buildPayload($event, $metadata, $broadcastConfig);

        try {
            // Broadcast to public channel
            if ((bool) ($broadcastConfig['public_channel_enabled'] ?? true)) {
                $publicChannel = (string) ($broadcastConfig['public_channel'] ?? 'analytics.events');
                $this->broadcaster->broadcast(
                    [$this->makeChannel($publicChannel)],
                    'analytics.event',
                    $payload,
                );
            }

            // Broadcast to category-scoped channel
            if ($category !== null && (bool) ($broadcastConfig['category_channels'] ?? true)) {
                $categoryChannel = "analytics.events.{$category}";
                $this->broadcaster->broadcast(
                    [$this->makeChannel($categoryChannel)],
                    'analytics.event',
                    $payload,
                );
            }

            // Broadcast to tenant-scoped private channel
            $tenantId = $metadata['tenant_id'] ?? null;
            if ($tenantId !== null && (bool) ($broadcastConfig['tenant_channels'] ?? false)) {
                $tenantChannel = "analytics.tenant.{$tenantId}";
                $this->broadcaster->broadcast(
                    [new PrivateChannel($tenantChannel)],
                    'analytics.event',
                    $payload,
                );
            }

            // Broadcast to admin channel (all events for admin dashboard)
            if ((bool) ($broadcastConfig['admin_channel_enabled'] ?? false)) {
                $adminChannel = (string) ($broadcastConfig['admin_channel'] ?? 'analytics.admin');
                $this->broadcaster->broadcast(
                    [new PrivateChannel($adminChannel)],
                    'analytics.event',
                    $payload,
                );
            }
        } catch (\Throwable $e) {
            // Broadcasting must never break the event pipeline
        }
    }

    /**
     * Broadcast a batch of events as a single message.
     *
     * Useful for high-throughput scenarios where individual broadcasts
     * would flood WebSocket connections. Batches are sent as a single
     * 'analytics.batch' event with an array of event payloads.
     *
     * @param  list<array{event: AnalyticsEvent, metadata: array<string, mixed>}>  $events  Events to broadcast
     */
    public function broadcastBatch(array $events): void
    {
        if (! $this->isEnabled() || $events === []) {
            return;
        }

        $broadcastConfig = $this->getBroadcastConfig();
        $categories = (array) ($broadcastConfig['categories'] ?? self::DEFAULT_CATEGORIES);

        $payloads = [];
        foreach ($events as $item) {
            $event = $item['event'];
            $metadata = $item['metadata'] ?? [];

            $category = $metadata['category'] ?? null;
            if ($category !== null && ! in_array($category, $categories, true)) {
                continue;
            }

            $payloads[] = $this->buildPayload($event, $metadata, $broadcastConfig);
        }

        if ($payloads === []) {
            return;
        }

        try {
            $publicChannel = (string) ($broadcastConfig['public_channel'] ?? 'analytics.events');

            $this->broadcaster->broadcast(
                [$this->makeChannel($publicChannel)],
                'analytics.batch',
                ['events' => $payloads, 'count' => count($payloads)],
            );
        } catch (\Throwable $e) {
            // Broadcasting must never break the event pipeline
        }
    }

    /**
     * Get all channels this service would broadcast to for a given event.
     *
     * Useful for authorization checks and channel registration.
     *
     * @param  AnalyticsEvent  $event
     * @param  array<string, mixed>  $metadata
     * @return list<Channel|PrivateChannel>
     */
    public function channelsFor(AnalyticsEvent $event, array $metadata = []): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $broadcastConfig = $this->getBroadcastConfig();
        $channels = [];

        if ((bool) ($broadcastConfig['public_channel_enabled'] ?? true)) {
            $channels[] = $this->makeChannel(
                (string) ($broadcastConfig['public_channel'] ?? 'analytics.events'),
            );
        }

        $category = $metadata['category'] ?? null;
        if ($category !== null && (bool) ($broadcastConfig['category_channels'] ?? true)) {
            $channels[] = $this->makeChannel("analytics.events.{$category}");
        }

        $tenantId = $metadata['tenant_id'] ?? null;
        if ($tenantId !== null && (bool) ($broadcastConfig['tenant_channels'] ?? false)) {
            $channels[] = new PrivateChannel("analytics.tenant.{$tenantId}");
        }

        if ((bool) ($broadcastConfig['admin_channel_enabled'] ?? false)) {
            $channels[] = new PrivateChannel(
                (string) ($broadcastConfig['admin_channel'] ?? 'analytics.admin'),
            );
        }

        return $channels;
    }

    /**
     * Build the broadcast payload from an analytics event.
     *
     * Redacts sensitive parameters and includes metadata.
     * The payload is kept lightweight for WebSocket transmission.
     *
     * @param  AnalyticsEvent  $event
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $broadcastConfig
     * @return array<string, mixed>
     */
    private function buildPayload(AnalyticsEvent $event, array $metadata, array $broadcastConfig): array
    {
        $params = $event->params;

        // Redact sensitive parameters if configured
        $includeParams = (bool) ($broadcastConfig['include_params'] ?? true);
        $sensitiveParams = (array) ($broadcastConfig['sensitive_params'] ?? self::DEFAULT_SENSITIVE_PARAMS);

        if (! $includeParams) {
            $params = [];
        } elseif ($sensitiveParams !== []) {
            $params = $this->redactParams($params, $sensitiveParams);
        }

        return [
            'name' => $event->name,
            'category' => $metadata['category'] ?? null,
            'params' => $params,
            'timestamp' => $metadata['timestamp'] ?? $event->timestamp?->format(\DateTimeInterface::ATOM) ?? null,
            'source' => $metadata['source'] ?? $event->source ?? 'server',
            'user_id' => $metadata['user_id'] ?? $event->userId,
            'tenant_id' => $metadata['tenant_id'] ?? null,
            'session_id' => $metadata['session_id'] ?? null,
        ];
    }

    /**
     * Redact sensitive parameters from event params.
     *
     * Replaces values of matching parameter keys with '[REDACTED]'.
     * Performs recursive redaction for nested arrays.
     *
     * @param  array<string, mixed>  $params  Event parameters
     * @param  list<string>  $sensitiveKeys  Keys to redact
     * @return array<string, mixed> Redacted parameters
     */
    private function redactParams(array $params, array $sensitiveKeys): array
    {
        $redacted = [];

        foreach ($params as $key => $value) {
            if (in_array($key, $sensitiveKeys, true)) {
                $redacted[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redactParams($value, $sensitiveKeys);
            } else {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }

    /**
     * Create a Channel instance from a channel name string.
     *
     * Returns a regular Channel for non-private names and a
     * PrivateChannel for names prefixed with 'private-'.
     *
     * @param  string  $name  Channel name
     * @return Channel|PrivateChannel
     */
    private function makeChannel(string $name): Channel|PrivateChannel
    {
        if (str_starts_with($name, 'private-')) {
            return new PrivateChannel(substr($name, 9));
        }

        return new Channel($name);
    }

    /**
     * Get the broadcasting configuration section.
     *
     * @return array<string, mixed>
     */
    private function getBroadcastConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = $this->config->get('zeroboiler.analytics.broadcasting', []);

        return $config;
    }
}
