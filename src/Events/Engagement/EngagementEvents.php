<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

/**
 * Static catalog of all engagement analytics events.
 *
 * Provides a central registry for event names, classes, and metadata.
 * Use for validation, lookup, and bulk operations.
 *
 * @phpstan-type EventEntry array{name: string, class: class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>, ga4: string, meta: string|null}
 */
final class EngagementEvents
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
            'page_view' => [
                'name' => 'page_view',
                'class' => PageViewEvent::class,
                'ga4' => 'page_view',
                'meta' => 'PageView',
            ],
            'scroll_depth' => [
                'name' => 'scroll_depth',
                'class' => ScrollDepthEvent::class,
                'ga4' => 'scroll',
                'meta' => 'ScrollDepth',
            ],
            'click' => [
                'name' => 'click',
                'class' => ClickEvent::class,
                'ga4' => 'click',
                'meta' => 'Click',
            ],
            'form_start' => [
                'name' => 'form_start',
                'class' => FormStartEvent::class,
                'ga4' => 'form_start',
                'meta' => 'Lead',
            ],
            'form_submit' => [
                'name' => 'form_submit',
                'class' => FormSubmitEvent::class,
                'ga4' => 'generate_lead',
                'meta' => 'Lead',
            ],
            'search' => [
                'name' => 'search',
                'class' => SearchEvent::class,
                'ga4' => 'search',
                'meta' => 'Search',
            ],
            'share' => [
                'name' => 'share',
                'class' => ShareEvent::class,
                'ga4' => 'share',
                'meta' => 'Share',
            ],
            'error' => [
                'name' => 'error',
                'class' => ErrorEvent::class,
                'ga4' => 'error',
                'meta' => 'Error',
            ],
            'time_on_page' => [
                'name' => 'time_on_page',
                'class' => TimeOnPageEvent::class,
                'ga4' => 'time_on_page',
                'meta' => 'TimeOnPage',
            ],
            'campaign_attribution' => [
                'name' => 'campaign_attribution',
                'class' => CampaignAttributionEvent::class,
                'ga4' => 'campaign_attribution',
                'meta' => 'CampaignAttribution',
            ],
            'screen_view' => [
                'name' => 'screen_view',
                'class' => ScreenViewEvent::class,
                'ga4' => 'screen_view',
                'meta' => 'ViewContent',
            ],
            'ab_test_exposure' => [
                'name' => 'ab_test_exposure',
                'class' => AbTestExposureEvent::class,
                'ga4' => 'ab_test_exposure',
                'meta' => 'ABTestExposure',
            ],
            'notification' => [
                'name' => 'notification',
                'class' => NotificationEvent::class,
                'ga4' => 'notification',
                'meta' => 'Notification',
            ],
            // Performance & client-side events
            'web_vitals' => [
                'name' => 'web_vitals',
                'class' => WebVitalsEvent::class,
                'ga4' => 'web_vitals',
                'meta' => 'WebVitals',
            ],
            'js_error' => [
                'name' => 'js_error',
                'class' => JSErrorEvent::class,
                'ga4' => 'js_error',
                'meta' => 'Error',
            ],
            'timing' => [
                'name' => 'timing',
                'class' => TimingEvent::class,
                'ga4' => 'timing',
                'meta' => 'Timing',
            ],
            // Session lifecycle events
            'session_start' => [
                'name' => 'session_start',
                'class' => SessionStartEvent::class,
                'ga4' => 'session_start',
                'meta' => 'SessionStart',
            ],
            'session_end' => [
                'name' => 'session_end',
                'class' => SessionEndEvent::class,
                'ga4' => 'session_end',
                'meta' => 'SessionEnd',
            ],
            // Link click events
            'outbound_click' => [
                'name' => 'outbound_click',
                'class' => OutboundClickEvent::class,
                'ga4' => 'outbound_click',
                'meta' => 'OutboundClick',
            ],
            // Content engagement events
            'file_download' => [
                'name' => 'file_download',
                'class' => FileDownloadEvent::class,
                'ga4' => 'file_download',
                'meta' => 'FileDownload',
            ],
            'video_play' => [
                'name' => 'video_play',
                'class' => VideoPlayEvent::class,
                'ga4' => 'video_play',
                'meta' => 'VideoPlay',
            ],
        ];

        return self::$catalog;
    }

    /**
     * Get all engagement event names.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::catalog());
    }

    /**
     * Get all engagement event entries.
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
     * Get the total number of engagement events.
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
        return 'engagement';
    }
}
