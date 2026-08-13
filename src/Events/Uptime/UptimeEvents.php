<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Uptime;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Static catalog of all uptime/infrastructure analytics events.
 *
 * Provides a central registry for uptime event names, classes, and metadata.
 * Use for validation, lookup, and bulk operations in infrastructure monitoring.
 *
 * @phpstan-type EventEntry array{name: string, class: class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>, ga4: string, meta: string|null, posthog: string, plausible: string|null, mixpanel: string, amplitude: string}
 *
 * @since 9.9.0
 */
final class UptimeEvents
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
            'service_up' => [
                'name' => 'service_up',
                'class' => ServiceUpEvent::class,
                'ga4' => 'service_up',
                'meta' => null,
                'posthog' => 'service_up',
                'plausible' => null,
                'mixpanel' => 'Service Up',
                'amplitude' => 'Service Up',
            ],
            'service_down' => [
                'name' => 'service_down',
                'class' => ServiceDownEvent::class,
                'ga4' => 'service_down',
                'meta' => null,
                'posthog' => 'service_down',
                'plausible' => null,
                'mixpanel' => 'Service Down',
                'amplitude' => 'Service Down',
            ],
            'api_latency' => [
                'name' => 'api_latency',
                'class' => ApiLatencyEvent::class,
                'ga4' => 'api_latency',
                'meta' => null,
                'posthog' => 'api_latency',
                'plausible' => null,
                'mixpanel' => 'API Latency',
                'amplitude' => 'API Latency',
            ],
            'error_spike' => [
                'name' => 'error_spike',
                'class' => ErrorSpikeEvent::class,
                'ga4' => 'error_spike',
                'meta' => null,
                'posthog' => 'error_spike',
                'plausible' => null,
                'mixpanel' => 'Error Spike',
                'amplitude' => 'Error Spike',
            ],
            'deployment' => [
                'name' => 'deployment',
                'class' => DeploymentEvent::class,
                'ga4' => 'deployment',
                'meta' => null,
                'posthog' => 'deployment',
                'plausible' => null,
                'mixpanel' => 'Deployment',
                'amplitude' => 'Deployment',
            ],
        ];

        return self::$catalog;
    }

    /**
     * Get all uptime event names.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::catalog());
    }

    /**
     * Get all uptime event entries.
     *
     * @return array<string, EventEntry>
     */
    public static function all(): array
    {
        return self::catalog();
    }

    /**
     * Get a specific event entry by name.
     *
     * @return EventEntry|null
     */
    public static function get(string $name): ?array
    {
        return self::catalog()[$name] ?? null;
    }

    /**
     * Check if an event name exists in the catalog.
     */
    public static function has(string $name): bool
    {
        return isset(self::catalog()[$name]);
    }

    /**
     * Get the total number of uptime events.
     */
    public static function count(): int
    {
        return count(self::catalog());
    }

    /**
     * Get all GA4 event names in this category.
     *
     * @return list<string>
     */
    public static function ga4Names(): array
    {
        return array_map(
            fn (array $entry): string => $entry['ga4'],
            self::catalog(),
        );
    }

    /**
     * Get all Meta Pixel event names in this category (non-null only).
     *
     * @return list<string>
     */
    public static function metaNames(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $entry): ?string => $entry['meta'],
                self::catalog(),
            ),
            fn (?string $meta): bool => $meta !== null,
        ));
    }

    /**
     * Get the event class for a given event name.
     *
     * @return class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>|null
     */
    public static function classFor(string $name): ?string
    {
        return self::catalog()[$name]['class'] ?? null;
    }

    /**
     * Get the category name for this catalog.
     */
    public static function category(): string
    {
        return 'uptime';
    }

    /**
     * Get all PostHog event names in this category.
     *
     * @return list<string>
     */
    public static function posthogNames(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $entry): ?string => $entry['posthog'] ?? null,
                self::catalog(),
            ),
            fn (?string $name): bool => $name !== null,
        ));
    }

    /**
     * Get all Plausible event names in this category (non-null only).
     *
     * @return list<string>
     */
    public static function plausibleNames(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $entry): ?string => $entry['plausible'] ?? null,
                self::catalog(),
            ),
            fn (?string $name): bool => $name !== null,
        ));
    }

    /**
     * Get all Mixpanel event names in this category.
     *
     * @return list<string>
     */
    public static function mixpanelNames(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $entry): ?string => $entry['mixpanel'] ?? null,
                self::catalog(),
            ),
            fn (?string $name): bool => $name !== null,
        ));
    }

    /**
     * Get all Amplitude event names in this category.
     *
     * @return list<string>
     */
    public static function amplitudeNames(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $entry): ?string => $entry['amplitude'] ?? null,
                self::catalog(),
            ),
            fn (?string $name): bool => $name !== null,
        ));
    }

    /**
     * Get all TikTok event names in this category (non-null only).
     *
     * @return list<string>
     */
    public static function tiktokNames(): array
    {
        return [];
    }

    /**
     * Get all LinkedIn event names in this category (non-null only).
     *
     * @return list<string>
     */
    public static function linkedinNames(): array
    {
        return [];
    }
}
