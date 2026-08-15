<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Engagement\CopyTextEvent;
use ZeroBoiler\Analytics\Events\Engagement\ElementVisibilityEvent;
use ZeroBoiler\Analytics\Events\Engagement\HoverEvent;
use ZeroBoiler\Analytics\Events\Engagement\OnboardingCompletedEvent;

/**
 * Static catalog of all engagement analytics events.
 *
 * Provides a central registry for event names, classes, and metadata.
 * Use for validation, lookup, and bulk operations.
 *
 * @phpstan-type EventEntry array{name: string, class: class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>, ga4: string, meta: string|null, posthog: string, plausible: string|null, mixpanel: string, amplitude: string, tiktok: string|null, linkedin: string|null}
 *
 * @since 1.0.0
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
                'mixpanel' => 'Page View',
                'amplitude' => 'Page View',
                'tiktok' => 'Pageview',
                'linkedin' => 'page_view',
            ],
            'scroll_depth' => [
                'name' => 'scroll_depth',
                'class' => ScrollDepthEvent::class,
                'ga4' => 'scroll',
                'meta' => 'ScrollDepth',
                'posthog' => 'scroll_depth',
                'plausible' => null,
                'mixpanel' => 'Scroll Depth',
                'amplitude' => 'Scroll Depth',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'click' => [
                'name' => 'click',
                'class' => ClickEvent::class,
                'ga4' => 'click',
                'meta' => 'Click',
                'posthog' => 'click',
                'plausible' => null,
                'mixpanel' => 'Click',
                'amplitude' => 'Click',
                'tiktok' => 'ClickButton',
                'linkedin' => null,
            ],
            'form_start' => [
                'name' => 'form_start',
                'class' => FormStartEvent::class,
                'ga4' => 'form_start',
                'meta' => 'Lead',
                'posthog' => 'form_started',
                'plausible' => null,
                'mixpanel' => 'Form Start',
                'amplitude' => 'Form Start',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'form_submit' => [
                'name' => 'form_submit',
                'class' => FormSubmitEvent::class,
                'ga4' => 'generate_lead',
                'meta' => 'Lead',
                'posthog' => 'form_submitted',
                'plausible' => null,
                'mixpanel' => 'Form Submit',
                'amplitude' => 'Form Submit',
                'tiktok' => 'SubmitForm',
                'linkedin' => null,
            ],
            'search' => [
                'name' => 'search',
                'class' => SearchEvent::class,
                'ga4' => 'search',
                'meta' => 'Search',
                'posthog' => '$search',
                'plausible' => 'search',
                'mixpanel' => 'Search',
                'amplitude' => 'Search',
                'tiktok' => 'Search',
                'linkedin' => null,
            ],
            'share' => [
                'name' => 'share',
                'class' => ShareEvent::class,
                'ga4' => 'share',
                'meta' => 'Share',
                'posthog' => '$share',
                'plausible' => 'share',
                'mixpanel' => 'Share',
                'amplitude' => 'Share',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'error' => [
                'name' => 'error',
                'class' => ErrorEvent::class,
                'ga4' => 'error',
                'meta' => 'Error',
                'posthog' => '$error',
                'plausible' => null,
                'mixpanel' => 'Error',
                'amplitude' => 'Error',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'time_on_page' => [
                'name' => 'time_on_page',
                'class' => TimeOnPageEvent::class,
                'ga4' => 'time_on_page',
                'meta' => 'TimeOnPage',
                'posthog' => 'time_on_page',
                'plausible' => null,
                'mixpanel' => 'Time On Page',
                'amplitude' => 'Time On Page',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'campaign_attribution' => [
                'name' => 'campaign_attribution',
                'class' => CampaignAttributionEvent::class,
                'ga4' => 'campaign_attribution',
                'meta' => 'CampaignAttribution',
                'posthog' => 'campaign_attribution',
                'plausible' => null,
                'mixpanel' => 'Campaign Attribution',
                'amplitude' => 'Campaign Attribution',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'screen_view' => [
                'name' => 'screen_view',
                'class' => ScreenViewEvent::class,
                'ga4' => 'screen_view',
                'meta' => 'ViewContent',
                'posthog' => '$screenview',
                'plausible' => null,
                'mixpanel' => 'Screen View',
                'amplitude' => 'Screen View',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'ab_test_exposure' => [
                'name' => 'ab_test_exposure',
                'class' => AbTestExposureEvent::class,
                'ga4' => 'ab_test_exposure',
                'meta' => 'ABTestExposure',
                'posthog' => '$experiment_exposure',
                'plausible' => null,
                'mixpanel' => 'Ab Test Exposure',
                'amplitude' => 'Ab Test Exposure',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'notification' => [
                'name' => 'notification',
                'class' => NotificationEvent::class,
                'ga4' => 'notification',
                'meta' => 'Notification',
                'posthog' => 'notification',
                'plausible' => null,
                'mixpanel' => 'Notification',
                'amplitude' => 'Notification',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Performance & client-side events
            'web_vitals' => [
                'name' => 'web_vitals',
                'class' => WebVitalsEvent::class,
                'ga4' => 'web_vitals',
                'meta' => 'WebVitals',
                'posthog' => 'web_vitals',
                'plausible' => null,
                'mixpanel' => 'Web Vitals',
                'amplitude' => 'Web Vitals',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'js_error' => [
                'name' => 'js_error',
                'class' => JSErrorEvent::class,
                'ga4' => 'js_error',
                'meta' => 'Error',
                'posthog' => '$exception',
                'plausible' => null,
                'mixpanel' => 'Js Error',
                'amplitude' => 'Js Error',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'timing' => [
                'name' => 'timing',
                'class' => TimingEvent::class,
                'ga4' => 'timing',
                'meta' => 'Timing',
                'posthog' => 'timing',
                'plausible' => null,
                'mixpanel' => 'Timing',
                'amplitude' => 'Timing',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Session lifecycle events
            'session_start' => [
                'name' => 'session_start',
                'class' => SessionStartEvent::class,
                'ga4' => 'session_start',
                'meta' => 'SessionStart',
                'posthog' => '$session_start',
                'plausible' => null,
                'mixpanel' => 'Session Start',
                'amplitude' => 'Session Start',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'session_end' => [
                'name' => 'session_end',
                'class' => SessionEndEvent::class,
                'ga4' => 'session_end',
                'meta' => 'SessionEnd',
                'posthog' => 'session_end',
                'plausible' => null,
                'mixpanel' => 'Session End',
                'amplitude' => 'Session End',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Link click events
            'outbound_click' => [
                'name' => 'outbound_click',
                'class' => OutboundClickEvent::class,
                'ga4' => 'outbound_click',
                'meta' => 'OutboundClick',
                'posthog' => 'outbound_click',
                'plausible' => null,
                'mixpanel' => 'Outbound Click',
                'amplitude' => 'Outbound Click',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Content engagement events
            'file_download' => [
                'name' => 'file_download',
                'class' => FileDownloadEvent::class,
                'ga4' => 'file_download',
                'meta' => 'FileDownload',
                'posthog' => 'file_download',
                'plausible' => 'file_download',
                'mixpanel' => 'File Download',
                'amplitude' => 'File Download',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'video_play' => [
                'name' => 'video_play',
                'class' => VideoPlayEvent::class,
                'ga4' => 'video_play',
                'meta' => 'VideoPlay',
                'posthog' => 'video_play',
                'plausible' => 'video_play',
                'mixpanel' => 'Video Play',
                'amplitude' => 'Video Play',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Paid advertising events
            'ad_click' => [
                'name' => 'ad_click',
                'class' => AdClickEvent::class,
                'ga4' => 'ad_click',
                'meta' => 'AdClick',
                'posthog' => 'ad_click',
                'plausible' => null,
                'mixpanel' => 'Ad Click',
                'amplitude' => 'Ad Click',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Content consumption events
            'content_engagement' => [
                'name' => 'content_engagement',
                'class' => ContentEngagementEvent::class,
                'ga4' => 'content_engagement',
                'meta' => 'ContentEngagement',
                'posthog' => 'content_engagement',
                'plausible' => null,
                'mixpanel' => 'Content Engagement',
                'amplitude' => 'Content Engagement',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // SaaS onboarding funnel events
            'onboarding_step' => [
                'name' => 'onboarding_step',
                'class' => OnboardingStepEvent::class,
                'ga4' => 'onboarding_step',
                'meta' => 'OnboardingStep',
                'posthog' => 'onboarding_step',
                'plausible' => null,
                'mixpanel' => 'Onboarding Step',
                'amplitude' => 'Onboarding Step',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Product demand signals
            'feature_request' => [
                'name' => 'feature_request',
                'class' => FeatureRequestEvent::class,
                'ga4' => 'feature_request',
                'meta' => 'FeatureRequest',
                'posthog' => 'feature_request',
                'plausible' => null,
                'mixpanel' => 'Feature Request',
                'amplitude' => 'Feature Request',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // User satisfaction & feedback (v2.79.0)
            'feedback' => [
                'name' => 'feedback',
                'class' => FeedbackEvent::class,
                'ga4' => 'feedback',
                'meta' => 'Feedback',
                'posthog' => 'feedback',
                'plausible' => null,
                'mixpanel' => 'Feedback',
                'amplitude' => 'Feedback',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Custom goal conversion tracking (v2.79.0)
            'goal_conversion' => [
                'name' => 'goal_conversion',
                'class' => GoalConversionEvent::class,
                'ga4' => 'goal_conversion',
                'meta' => 'Conversion',
                'posthog' => 'goal_conversion',
                'plausible' => 'goal',
                'mixpanel' => 'Goal Conversion',
                'amplitude' => 'Goal Conversion',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // GDPR consent lifecycle events (v2.93.0)
            'consent_granted' => [
                'name' => 'consent_granted',
                'class' => ConsentGrantedEvent::class,
                'ga4' => 'consent_update',
                'meta' => 'ConsentGranted',
                'posthog' => 'consent_granted',
                'plausible' => null,
                'mixpanel' => 'Consent Granted',
                'amplitude' => 'Consent Granted',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'consent_withdrawn' => [
                'name' => 'consent_withdrawn',
                'class' => ConsentWithdrawnEvent::class,
                'ga4' => 'consent_update',
                'meta' => 'ConsentWithdrawn',
                'posthog' => 'consent_withdrawn',
                'plausible' => null,
                'mixpanel' => 'Consent Withdrawn',
                'amplitude' => 'Consent Withdrawn',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Onboarding completion (v9.7.0)
            'onboarding_completed' => [
                'name' => 'onboarding_completed',
                'class' => OnboardingCompletedEvent::class,
                'ga4' => 'onboarding_completed',
                'meta' => 'CompleteRegistration',
                'posthog' => 'onboarding_completed',
                'plausible' => null,
                'mixpanel' => 'Onboarding Completed',
                'amplitude' => 'Onboarding Completed',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Performance analytics (v24.0.0)
            'performance_score' => [
                'name' => 'performance_score',
                'class' => PerformanceScoreEvent::class,
                'ga4' => 'performance_score',
                'meta' => null,
                'posthog' => 'performance_score',
                'plausible' => null,
                'mixpanel' => 'Performance Score',
                'amplitude' => 'Performance Score',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Element visibility via IntersectionObserver (v27.0.0)
            'element_visibility' => [
                'name' => 'element_visibility',
                'class' => ElementVisibilityEvent::class,
                'ga4' => 'element_visibility',
                'meta' => null,
                'posthog' => 'element_visibility',
                'plausible' => null,
                'mixpanel' => 'Element Visibility',
                'amplitude' => 'Element Visibility',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Text copy/cut tracking (v27.0.0)
            'copy_text' => [
                'name' => 'copy_text',
                'class' => CopyTextEvent::class,
                'ga4' => 'copy_text',
                'meta' => null,
                'posthog' => 'copy_text',
                'plausible' => null,
                'mixpanel' => 'Copy Text',
                'amplitude' => 'Copy Text',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Element hover/focus tracking (v27.0.0)
            'hover' => [
                'name' => 'hover',
                'class' => HoverEvent::class,
                'ga4' => 'hover',
                'meta' => null,
                'posthog' => 'hover',
                'plausible' => null,
                'mixpanel' => 'Hover',
                'amplitude' => 'Hover',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Client-side error with structured context (v93.0.0)
            'client_error' => [
                'name' => 'client_error',
                'class' => ClientErrorEvent::class,
                'ga4' => 'client_error',
                'meta' => null,
                'posthog' => '$exception',
                'plausible' => null,
                'mixpanel' => 'Client Error',
                'amplitude' => 'Client Error',
                'tiktok' => null,
                'linkedin' => null,
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
     *
     * @since 35.0.0
     */
    public static function tiktokNames(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $entry): ?string => $entry['tiktok'] ?? null,
                self::catalog(),
            ),
            fn (?string $name): bool => $name !== null,
        ));
    }

    /**
     * Get all LinkedIn event names in this category (non-null only).
     *
     * @return list<string>
     *
     * @since 35.0.0
     */
    public static function linkedinNames(): array
    {
        return array_values(array_filter(
            array_map(
                fn (array $entry): ?string => $entry['linkedin'] ?? null,
                self::catalog(),
            ),
            fn (?string $name): bool => $name !== null,
        ));
    }

    // ── Typed Shorthand Factory Methods (v151.0.0) ──────────────────

    /**
     * Build a typed page_view event.
     *
     * @param  array{title?: string, location?: string, referrer?: string}  $params
     * @return AnalyticsEvent
     */
    public static function pageView(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'page_view', params: $params, category: 'engagement');
    }

    /**
     * Build a typed scroll_depth event.
     *
     * @param  int  $percent
     * @param  array<string, mixed>  $extra
     * @return AnalyticsEvent
     */
    public static function scrollDepth(int $percent, array $extra = []): AnalyticsEvent
    {
        return new AnalyticsEvent(
            name: 'scroll_depth',
            params: array_merge(['percent' => $percent], $extra),
            category: 'engagement',
        );
    }

    /**
     * Build a typed click event.
     *
     * @param  string  $element
     * @param  array<string, mixed>  $extra
     * @return AnalyticsEvent
     */
    public static function click(string $element, array $extra = []): AnalyticsEvent
    {
        return new AnalyticsEvent(
            name: 'click',
            params: array_merge(['element' => $element], $extra),
            category: 'engagement',
        );
    }

    /**
     * Build a typed form_start event.
     *
     * @param  string  $formName
     * @param  array<string, mixed>  $extra
     * @return AnalyticsEvent
     */
    public static function formStart(string $formName, array $extra = []): AnalyticsEvent
    {
        return new AnalyticsEvent(
            name: 'form_start',
            params: array_merge(['form_name' => $formName], $extra),
            category: 'engagement',
        );
    }

    /**
     * Build a typed form_submit event.
     *
     * @param  string  $formName
     * @param  array<string, mixed>  $extra
     * @return AnalyticsEvent
     */
    public static function formSubmit(string $formName, array $extra = []): AnalyticsEvent
    {
        return new AnalyticsEvent(
            name: 'form_submit',
            params: array_merge(['form_name' => $formName], $extra),
            category: 'engagement',
        );
    }

    /**
     * Build a typed search event.
     *
     * @param  string  $query
     * @param  array<string, mixed>  $extra
     * @return AnalyticsEvent
     */
    public static function search(string $query, array $extra = []): AnalyticsEvent
    {
        return new AnalyticsEvent(
            name: 'search',
            params: array_merge(['search_term' => $query], $extra),
            category: 'engagement',
        );
    }

    /**
     * Build a typed share event.
     *
     * @param  string  $method
     * @param  string|null  $contentType
     * @param  array<string, mixed>  $extra
     * @return AnalyticsEvent
     */
    public static function share(string $method, ?string $contentType = null, array $extra = []): AnalyticsEvent
    {
        return new AnalyticsEvent(
            name: 'share',
            params: array_merge(array_filter(['method' => $method, 'content_type' => $contentType]), $extra),
            category: 'engagement',
        );
    }

    /**
     * Build a typed error event.
     *
     * @param  string  $message
     * @param  array<string, mixed>  $extra
     * @return AnalyticsEvent
     */
    public static function error(string $message, array $extra = []): AnalyticsEvent
    {
        return new AnalyticsEvent(
            name: 'error',
            params: array_merge(['error_message' => $message], $extra),
            category: 'engagement',
        );
    }

    /**
     * Build a typed js_error event.
     *
     * @param  string  $errorMessage
     * @param  string|null  $source
     * @param  array<string, mixed>  $extra
     * @return AnalyticsEvent
     */
    public static function jsError(string $errorMessage, ?string $source = null, array $extra = []): AnalyticsEvent
    {
        return new AnalyticsEvent(
            name: 'js_error',
            params: array_merge(array_filter(['error_message' => $errorMessage, 'source' => $source]), $extra),
            category: 'engagement',
        );
    }

    /**
     * Build a typed session_start event.
     *
     * @param  array<string, mixed>  $params
     * @return AnalyticsEvent
     */
    public static function sessionStart(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'session_start', params: $params, category: 'engagement');
    }

    /**
     * Build a typed session_end event.
     *
     * @param  array{duration_seconds?: int, page_count?: int}  $params
     * @return AnalyticsEvent
     */
    public static function sessionEnd(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'session_end', params: $params, category: 'engagement');
    }

    /**
     * Build a typed feedback event.
     *
     * @param  array{score?: int, comment?: string}  $params
     * @return AnalyticsEvent
     */
    public static function feedback(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'feedback', params: $params, category: 'engagement');
    }

    /**
     * Build a typed consent_granted event.
     *
     * @param  array{purposes?: list<string>}  $params
     * @return AnalyticsEvent
     */
    public static function consentGranted(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'consent_granted', params: $params, category: 'engagement');
    }

    /**
     * Build a typed consent_withdrawn event.
     *
     * @param  array{purposes?: list<string>}  $params
     * @return AnalyticsEvent
     */
    public static function consentWithdrawn(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'consent_withdrawn', params: $params, category: 'engagement');
    }

    /**
     * Build a typed onboarding_completed event.
     *
     * @param  array{duration_seconds?: int, steps_completed?: int}  $params
     * @return AnalyticsEvent
     */
    public static function onboardingCompleted(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'onboarding_completed', params: $params, category: 'engagement');
    }

    /**
     * Build a typed AnalyticsEvent from any catalog entry by name.
     *
     * Generic factory — validates the event name against the catalog.
     *
     * @param  string  $name  Event name (must exist in this catalog)
     * @param  array<string, mixed>  $params
     * @return AnalyticsEvent
     * @throws \InvalidArgumentException if event name is not in this catalog
     */
    public static function build(string $name, array $params = []): AnalyticsEvent
    {
        if (! self::has($name)) {
            throw new \InvalidArgumentException("Unknown engagement event: {$name}");
        }

        return new AnalyticsEvent(name: $name, params: $params, category: 'engagement');
    }
}
