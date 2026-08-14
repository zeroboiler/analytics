<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

/**
 * Engagement analytics event name constants for IDE autocompletion and type safety.
 *
 * Use these constants instead of raw strings to prevent typos and enable
 * IDE "find usages" / refactoring support when tracking engagement events.
 *
 * @since 100.0.0
 *
 * @see \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents
 */
final class EngagementEventConstants
{
    /** @var string Page viewed */
    public const PAGE_VIEW = 'page_view';
    /** @var string Scroll depth milestone reached */
    public const SCROLL_DEPTH = 'scroll_depth';
    /** @var string Element clicked */
    public const CLICK = 'click';
    /** @var string Form started */
    public const FORM_START = 'form_start';
    /** @var string Form submitted */
    public const FORM_SUBMIT = 'form_submit';
    /** @var string Search performed */
    public const SEARCH = 'search';
    /** @var string Content shared */
    public const SHARE = 'share';
    /** @var string Error occurred */
    public const ERROR = 'error';
    /** @var string File downloaded */
    public const FILE_DOWNLOAD = 'file_download';
    /** @var string Video played */
    public const VIDEO_PLAY = 'video_play';
    /** @var string Outbound link clicked */
    public const OUTBOUND_CLICK = 'outbound_click';
    /** @var string Notification event */
    public const NOTIFICATION = 'notification';
    /** @var string Content engagement */
    public const CONTENT_ENGAGEMENT = 'content_engagement';
    /** @var string Onboarding step */
    public const ONBOARDING_STEP = 'onboarding_step';
    /** @var string Onboarding completed */
    public const ONBOARDING_COMPLETED = 'onboarding_completed';
    /** @var string Session started */
    public const SESSION_START = 'session_start';
    /** @var string Session ended */
    public const SESSION_END = 'session_end';
    /** @var string Screen viewed */
    public const SCREEN_VIEW = 'screen_view';
    /** @var string Time on page tracked */
    public const TIME_ON_PAGE = 'time_on_page';
    /** @var string Timing event */
    public const TIMING = 'timing';
    /** @var string A/B test exposure */
    public const AB_TEST_EXPOSURE = 'ab_test_exposure';
    /** @var string Campaign attribution */
    public const CAMPAIGN_ATTRIBUTION = 'campaign_attribution';
    /** @var string Ad click */
    public const AD_CLICK = 'ad_click';
    /** @var string Consent granted */
    public const CONSENT_GRANTED = 'consent_granted';
    /** @var string Consent withdrawn */
    public const CONSENT_WITHDRAWN = 'consent_withdrawn';
    /** @var string Goal conversion */
    public const GOAL_CONVERSION = 'goal_conversion';
    /** @var string Copy text event */
    public const COPY_TEXT = 'copy_text';
    /** @var string Element visibility event */
    public const ELEMENT_VISIBILITY = 'element_visibility';
    /** @var string Hover event */
    public const HOVER = 'hover';
    /** @var string Feature request */
    public const FEATURE_REQUEST = 'feature_request';
    /** @var string Feedback submitted */
    public const FEEDBACK = 'feedback';
    /** @var string Performance score */
    public const PERFORMANCE_SCORE = 'performance_score';
    /** @var string Web vitals event */
    public const WEB_VITALS = 'web_vitals';
    /** @var string Client-side JS error */
    public const CLIENT_ERROR = 'client_error';

    /**
     * Get all engagement event name constants as an associative array.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return (new \ReflectionClass(self::class))->getConstants();
    }

    /**
     * Get all engagement event name constants as a list.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_values(self::all());
    }

    /**
     * Check if a given event name is a valid engagement event constant.
     */
    public static function isValid(string $name): bool
    {
        return in_array($name, self::all(), true);
    }

    /**
     * Get the total number of engagement event constants.
     */
    public static function count(): int
    {
        return count(self::all());
    }
}
