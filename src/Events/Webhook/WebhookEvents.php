<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Webhook;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Static catalog of all webhook analytics events.
 *
 * Provides a central registry for webhook event names, classes, and
 * cross-provider mappings. Use for validation, lookup, and bulk operations.
 *
 * @phpstan-type EventEntry array{name: string, class: class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>, ga4: string, meta: string|null, posthog: string, plausible: string|null, mixpanel: string, amplitude: string, tiktok: string|null, linkedin: string|null}
 *
 * @since 182.0.0
 */
final class WebhookEvents
{
    /** @var array<string, EventEntry> */
    private static array $catalog = [];

    /**
     * Build the event catalog (lazy initialization).
     *
     * @return array<string, EventEntry>
     */
    private static function catalog(): array
    {
        if (self::$catalog !== []) {
            return self::$catalog;
        }

        self::$catalog = [
            'webhook_delivered' => [
                'name' => 'webhook_delivered',
                'class' => WebhookDeliveredEvent::class,
                'ga4' => 'webhook_delivered',
                'meta' => null,
                'posthog' => 'webhook_delivered',
                'plausible' => null,
                'mixpanel' => 'Webhook Delivered',
                'amplitude' => 'Webhook Delivered',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'webhook_failed' => [
                'name' => 'webhook_failed',
                'class' => WebhookFailedEvent::class,
                'ga4' => 'webhook_failed',
                'meta' => null,
                'posthog' => 'webhook_failed',
                'plausible' => null,
                'mixpanel' => 'Webhook Failed',
                'amplitude' => 'Webhook Failed',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'webhook_received' => [
                'name' => 'webhook_received',
                'class' => WebhookReceivedEvent::class,
                'ga4' => 'webhook_received',
                'meta' => null,
                'posthog' => 'webhook_received',
                'plausible' => null,
                'mixpanel' => 'Webhook Received',
                'amplitude' => 'Webhook Received',
                'tiktok' => null,
                'linkedin' => null,
            ],
        ];

        return self::$catalog;
    }

    /**
     * Get all webhook events.
     *
     * @return array<string, EventEntry>
     */
    public static function all(): array
    {
        return self::catalog();
    }

    /**
     * Get all event names.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::catalog());
    }

    /**
     * Look up a single event by name.
     *
     * @return EventEntry|null
     */
    public static function find(string $name): ?array
    {
        return self::catalog()[$name] ?? null;
    }

    /**
     * Get total event count.
     */
    public static function count(): int
    {
        return count(self::catalog());
    }
}
