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
 * @phpstan-type EventEntry array{name: string, class: class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>, ga4: string, meta: string|null, posthog: string, plausible: string|null}
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
                'posthog' => '$pageview',
                'plausible' => 'pageview',
            ],
            'scroll_depth' => [
                'name' => 'scroll_depth',
                'class' => ScrollDepthEvent::class,
                'ga4' => 'scroll',
                'meta' => 'ScrollDepth',
                'posthog' => 'scroll_depth',
                'plausible' => null,
            ],
            'click' => [
                'name' => 'click',
                'class' => ClickEvent::class,
                'ga4' => 'click',
                'meta' => 'Click',
                'posthog' => 'click',
                'plausible' => null,
            ],
            'form_start' => [
                'name' => 'form_start',
                'class' => FormStartEvent::class,
                'ga4' => 'form_start',
                'meta' => 'Lead',
                'posthog' => 'form_started',
                'plausible' => null,
            ],
            'form_submit' => [
                'name' => 'form_submit',
                'class' => FormSubmitEvent::class,
                'ga4' => 'generate_lead',
                'meta' => 'Lead',
                'posthog' => 'form_submitted',
                'plausible' => null,
            ],
            'search' => [
                'name' => 'search',
                'class' => SearchEvent::class,
                'ga4' => 'search',
                'meta' => 'Search',
                'posthog' => '$search',
                'plausible' => 'search',
            ],
            'share' => [
                'name' => 'share',
                'class' => ShareEvent::class,
                'ga4' => 'share',
                'meta' => 'Share',
                'posthog' => '$share',
                'plausible' => 'share',
            ],
            'error' => [
                'name' => 'error',
                'class' => ErrorEvent::class,
                'ga4' => 'error',
                'meta' => 'Error',
                'posthog' => '$error',
                'plausible' => null,
            ],
            'time_on_page' => [
                'name' => 'time_on_page',
                'class' => TimeOnPageEvent::class,
                'ga4' => 'time_on_page',
                'meta' => 'TimeOnPage',
                'posthog' => 'time_on_page',
                'plausible' => null,
            ],
            'campaign_attribution' => [
                'name' => 'campaign_attribution',
                'class' => CampaignAttributionEvent::class,
                'ga4' => 'campaign_attribution',
                'meta' => 'CampaignAttribution',
                'posthog' => 'campaign_attribution',
                'plausible' => null,
            ],
            'screen_view' => [
                'name' => 'screen_view',
                'class' => ScreenViewEvent::class,
                'ga4' => 'screen_view',
                'meta' => 'ViewContent',
                'posthog' => '$screenview',
                'plausible' => null,
            ],
            'ab_test_exposure' => [
                'name' => 'ab_test_exposure',
                'class' => AbTestExposureEvent::class,
                'ga4' => 'ab_test_exposure',
                'meta' => 'ABTestExposure',
                'posthog' => '$experiment_exposure',
                'plausible' => null,
            ],
            'notification' => [
                'name' => 'notification',
                'class' => NotificationEvent::class,
                'ga4' => 'notification',
                'meta' => 'Notification',
                'posthog' => 'notification',
                'plausible' => null,
            ],
            // Performance & client-side events
            'web_vitals' => [
                'name' => 'web_vitals',
                'class' => WebVitalsEvent::class,
                'ga4' => 'web_vitals',
                'meta' => 'WebVitals',
                'posthog' => 'web_vitals',
                'plausible' => null,
            ],
            'js_error' => [
                'name' => 'js_error',
                'class' => JSErrorEvent::class,
                'ga4' => 'js_error',
                'meta' => 'Error',
                'posthog' => '$exception',
                'plausible' => null,
            ],
            'timing' => [
                'name' => 'timing',
                'class' => TimingEvent::class,
                'ga4' => 'timing',
                'meta' => 'Timing',
                'posthog' => 'timing',
                'plausible' => null,
            ],
            // Session lifecycle events
            'session_start' => [
                'name' => 'session_start',
                'class' => SessionStartEvent::class,
                'ga4' => 'session_start',
                'meta' => 'SessionStart',
                'posthog' => '$session_start',
                'plausible' => null,
            ],
            'session_end' => [
                'name' => 'session_end',
                'class' => SessionEndEvent::class,
                'ga4' => 'session_end',
                'meta' => 'SessionEnd',
                'posthog' => 'session_end',
                'plausible' => null,
            ],
            // Link click events
            'outbound_click' => [
                'name' => 'outbound_click',
                'class' => OutboundClickEvent::class,
                'ga4' => 'outbound_click',
                'meta' => 'OutboundClick',
                'posthog' => 'outbound_click',
                'plausible' => null,
            ],
            // Content engagement events
            'file_download' => [
                'name' => 'file_download',
                'class' => FileDownloadEvent::class,
                'ga4' => 'file_download',
                'meta' => 'FileDownload',
                'posthog' => 'file_download',
                'plausible' => 'file_download',
            ],
            'video_play' => [
                'name' => 'video_play',
                'class' => VideoPlayEvent::class,
                'ga4' => 'video_play',
                'meta' => 'VideoPlay',
                'posthog' => 'video_play',
                'plausible' => 'video_play',
            ],
            // Paid advertising events
            'ad_click' => [
                'name' => 'ad_click',
                'class' => AdClickEvent::class,
                'ga4' => 'ad_click',
                'meta' => 'AdClick',
                'posthog' => 'ad_click',
                'plausible' => null,
            ],
            // Content consumption events
            'content_engagement' => [
                'name' => 'content_engagement',
                'class' => ContentEngagementEvent::class,
                'ga4' => 'content_engagement',
                'meta' => 'ContentEngagement',
                'posthog' => 'content_engagement',
                'plausible' => null,
            ],
            // SaaS onboarding funnel events
            'onboarding_step' => [
                'name' => 'onboarding_step',
                'class' => OnboardingStepEvent::class,
                'ga4' => 'onboarding_step',
                'meta' => 'OnboardingStep',
                'posthog' => 'onboarding_step',
                'plausible' => null,
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
}
