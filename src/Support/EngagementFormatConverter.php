<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Engagement event format converter for cross-provider data transformation.
 *
 * Provides bidirectional parameter structure conversion for the 8 core
 * engagement events (page_view, scroll_depth, click, form_start, form_submit,
 * search, share, error) across all 8 supported providers: GA4, Meta Pixel,
 * PostHog, Mixpanel, Amplitude, Plausible, TikTok, and LinkedIn.
 *
 * Engagement events are the most frequently tracked events in any SaaS
 * application. Unlike SaaS lifecycle events (which vary per business model),
 * engagement events are universal — every product tracks page views, clicks,
 * form interactions, and errors. Getting provider-specific formats right
 * here has the highest impact on analytics data quality.
 *
 * GA4: Uses recommended event params (page_location, page_title, engagement_time_msec,
 *      scroll_percent, link_url, link_text, form_id, search_term, method, etc.)
 * Meta Pixel: Uses Standard Events (PageView, Lead, Search, Share, etc.) with
 *            content_name, content_type, value, and custom data fields.
 * PostHog: Uses custom event properties with $pageview, $search, etc. autocaptured
 *          event names. Enriches with $current_url, $referrer, $session_duration, etc.
 * Mixpanel: Uses flat event properties for page views, clicks, form events.
 * Amplitude: Uses event properties with optional user_properties for enrichment.
 * Plausible: Uses {event_name, props} simple structure (conversion handled by EventTransformer).
 * TikTok: Uses flat properties with content, value for engagement events.
 * LinkedIn: Uses flat properties for page view, click, and custom events.
 *
 * @since 159.0.0
 */
final class EngagementFormatConverter
{
    // ── page_view → GA4 / Meta / PostHog ─────────────────────────────

    /**
     * Convert page_view event params to GA4 page_view format.
     *
     * GA4 page_view is an automatically collected event, but when sent manually
     * (server-side or SPA), it requires page_location, page_title, and optionally
     * page_referrer. Engagement time can be attached for session quality scoring.
     *
     * @param  array<string, mixed>  $params  Internal page_view params
     * @return array{page_location: string|null, page_title: string|null, page_referrer: string|null, engagement_time_msec: int|null}
     */
    public static function pageViewToGa4(array $params): array
    {
        return [
            'page_location' => $params['url'] ?? $params['page_location'] ?? null,
            'page_title' => $params['title'] ?? $params['page_title'] ?? null,
            'page_referrer' => $params['referrer'] ?? $params['page_referrer'] ?? null,
            'engagement_time_msec' => isset($params['engagement_time_msec'])
                ? (int) $params['engagement_time_msec']
                : null,
        ];
    }

    /**
     * Convert page_view event params to Meta Pixel PageView format.
     *
     * Meta's PageView is a standard event that requires no additional parameters.
     * However, custom properties can be attached for enhanced segmentation.
     *
     * @param  array<string, mixed>  $params  Internal page_view params
     * @return array{content_name: string|null, content_type: string}
     */
    public static function pageViewToMeta(array $params): array
    {
        return [
            'content_name' => $params['title'] ?? $params['page_title'] ?? null,
            'content_type' => (string) ($params['content_type'] ?? 'page'),
        ];
    }

    /**
     * Convert page_view event params to PostHog $pageview properties.
     *
     * PostHog autocaptures $pageview events. When sent server-side or
     * via API, the $current_url and $referrer are the primary properties.
     * Additional context like $screen_width, $screen_height can enhance
     * device-level segmentation.
     *
     * @param  array<string, mixed>  $params  Internal page_view params
     * @return array{$current_url: string|null, $referrer: string|null, $title: string|null, $screen_width: int|null, $screen_height: int|null}
     */
    public static function pageViewToPosthog(array $params): array
    {
        return [
            '$current_url' => $params['url'] ?? $params['page_location'] ?? null,
            '$referrer' => $params['referrer'] ?? $params['page_referrer'] ?? null,
            '$title' => $params['title'] ?? $params['page_title'] ?? null,
            '$screen_width' => isset($params['screen_width']) ? (int) $params['screen_width'] : null,
            '$screen_height' => isset($params['screen_height']) ? (int) $params['screen_height'] : null,
        ];
    }

    // ── scroll_depth → GA4 / Meta / PostHog ──────────────────────────

    /**
     * Convert scroll_depth event params to GA4 scroll event format.
     *
     * GA4's scroll event is an enhanced measurement event. When sent manually,
     * it uses percent_scrolled parameter (90 for 90% scroll).
     *
     * @param  array<string, mixed>  $params  Internal scroll_depth params
     * @return array{percent_scrolled: int|null, engagement_time_msec: int|null, page_location: string|null}
     */
    public static function scrollDepthToGa4(array $params): array
    {
        return [
            'percent_scrolled' => isset($params['percent'])
                ? (int) $params['percent']
                : (isset($params['scroll_percent']) ? (int) $params['scroll_percent'] : null),
            'engagement_time_msec' => isset($params['engagement_time_msec'])
                ? (int) $params['engagement_time_msec']
                : null,
            'page_location' => $params['url'] ?? $params['page_location'] ?? null,
        ];
    }

    /**
     * Convert scroll_depth event params to Meta Pixel custom event format.
     *
     * Meta has no standard Scroll event. Use custom event name with
     * scroll percentage as the primary metric.
     *
     * @param  array<string, mixed>  $params  Internal scroll_depth params
     * @return array{content_name: string, scroll_percent: int|null, content_type: string}
     */
    public static function scrollDepthToMeta(array $params): array
    {
        return [
            'content_name' => (string) ($params['content_name'] ?? 'scroll'),
            'scroll_percent' => isset($params['percent'])
                ? (int) $params['percent']
                : (isset($params['scroll_percent']) ? (int) $params['scroll_percent'] : null),
            'content_type' => 'scroll',
        ];
    }

    /**
     * Convert scroll_depth event params to PostHog properties.
     *
     * @param  array<string, mixed>  $params  Internal scroll_depth params
     * @return array{scroll_depth: int|null, $current_url: string|null, engagement_time: int|null}
     */
    public static function scrollDepthToPosthog(array $params): array
    {
        return [
            'scroll_depth' => isset($params['percent'])
                ? (int) $params['percent']
                : (isset($params['scroll_percent']) ? (int) $params['scroll_percent'] : null),
            '$current_url' => $params['url'] ?? $params['page_location'] ?? null,
            'engagement_time' => isset($params['engagement_time_msec'])
                ? (int) $params['engagement_time_msec']
                : null,
        ];
    }

    // ── click → GA4 / Meta / PostHog ─────────────────────────────────

    /**
     * Convert click event params to GA4 click event format.
     *
     * GA4 click events use link_url, link_text, link_domain, outbound (bool),
     * and optionally file_name, file_extension for download clicks.
     *
     * @param  array<string, mixed>  $params  Internal click params
     * @return array{link_url: string|null, link_text: string|null, link_domain: string|null, outbound: bool, element_class: string|null, element_id: string|null, element_tag: string|null}
     */
    public static function clickToGa4(array $params): array
    {
        $linkUrl = $params['url'] ?? $params['link_url'] ?? null;
        $parsedDomain = null;
        if (is_string($linkUrl) && $linkUrl !== '') {
            $parsed = parse_url($linkUrl, PHP_URL_HOST);
            $parsedDomain = is_string($parsed) ? $parsed : null;
        }

        return [
            'link_url' => $linkUrl,
            'link_text' => $params['text'] ?? $params['link_text'] ?? null,
            'link_domain' => $parsedDomain,
            'outbound' => (bool) ($params['outbound'] ?? false),
            'element_class' => $params['element_class'] ?? null,
            'element_id' => $params['element_id'] ?? null,
            'element_tag' => $params['element_tag'] ?? $params['tag'] ?? null,
        ];
    }

    /**
     * Convert click event params to Meta Pixel format.
     *
     * Meta uses custom events for generic clicks. CTA clicks can map
     * to Lead or custom events with content_name and content_category.
     *
     * @param  array<string, mixed>  $params  Internal click params
     * @return array{content_name: string|null, content_category: string|null, link_url: string|null}
     */
    public static function clickToMeta(array $params): array
    {
        return [
            'content_name' => $params['text'] ?? $params['link_text'] ?? $params['element_id'] ?? null,
            'content_category' => (string) ($params['content_category'] ?? 'click'),
            'link_url' => $params['url'] ?? $params['link_url'] ?? null,
        ];
    }

    /**
     * Convert click event params to PostHog properties.
     *
     * @param  array<string, mixed>  $params  Internal click params
     * @return array{link_url: string|null, link_text: string|null, element_class: string|null, element_id: string|null, element_tag: string|null, outbound: bool, $current_url: string|null}
     */
    public static function clickToPosthog(array $params): array
    {
        return [
            'link_url' => $params['url'] ?? $params['link_url'] ?? null,
            'link_text' => $params['text'] ?? $params['link_text'] ?? null,
            'element_class' => $params['element_class'] ?? null,
            'element_id' => $params['element_id'] ?? null,
            'element_tag' => $params['element_tag'] ?? $params['tag'] ?? null,
            'outbound' => (bool) ($params['outbound'] ?? false),
            '$current_url' => $params['page_location'] ?? null,
        ];
    }

    // ── form_start → GA4 / Meta / PostHog ────────────────────────────

    /**
     * Convert form_start event params to GA4 format.
     *
     * GA4 uses form_start and form_submit as enhanced measurement events.
     * Key params: form_id, form_name, form_destination.
     *
     * @param  array<string, mixed>  $params  Internal form_start params
     * @return array{form_id: string|null, form_name: string|null, form_destination: string|null}
     */
    public static function formStartToGa4(array $params): array
    {
        return [
            'form_id' => $params['form_id'] ?? $params['element_id'] ?? null,
            'form_name' => $params['form_name'] ?? $params['name'] ?? null,
            'form_destination' => $params['form_action'] ?? $params['form_destination'] ?? null,
        ];
    }

    /**
     * Convert form_start event params to Meta Pixel Lead format.
     *
     * Meta maps form interactions to Lead standard event with content_name.
     *
     * @param  array<string, mixed>  $params  Internal form_start params
     * @return array{content_name: string|null, content_category: string, form_id: string|null}
     */
    public static function formStartToMeta(array $params): array
    {
        return [
            'content_name' => $params['form_name'] ?? $params['name'] ?? 'form_start',
            'content_category' => 'form',
            'form_id' => $params['form_id'] ?? $params['element_id'] ?? null,
        ];
    }

    /**
     * Convert form_start event params to PostHog properties.
     *
     * @param  array<string, mixed>  $params  Internal form_start params
     * @return array{form_id: string|null, form_name: string|null, form_action: string|null, $current_url: string|null}
     */
    public static function formStartToPosthog(array $params): array
    {
        return [
            'form_id' => $params['form_id'] ?? $params['element_id'] ?? null,
            'form_name' => $params['form_name'] ?? $params['name'] ?? null,
            'form_action' => $params['form_action'] ?? $params['form_destination'] ?? null,
            '$current_url' => $params['page_location'] ?? null,
        ];
    }

    // ── form_submit → GA4 / Meta / PostHog ───────────────────────────

    /**
     * Convert form_submit event params to GA4 generate_lead format.
     *
     * GA4 uses generate_lead for successful form submissions.
     * Value and currency can be attached for lead value tracking.
     *
     * @param  array<string, mixed>  $params  Internal form_submit params
     * @return array{form_id: string|null, form_name: string|null, form_destination: string|null, value: float|null, currency: string|null}
     */
    public static function formSubmitToGa4(array $params): array
    {
        return [
            'form_id' => $params['form_id'] ?? $params['element_id'] ?? null,
            'form_name' => $params['form_name'] ?? $params['name'] ?? null,
            'form_destination' => $params['form_action'] ?? $params['form_destination'] ?? null,
            'value' => isset($params['value']) ? (float) $params['value'] : null,
            'currency' => $params['currency'] ?? null,
        ];
    }

    /**
     * Convert form_submit event params to Meta Pixel Lead format.
     *
     * Meta maps form submissions to the Lead standard event.
     * Value and currency enable lead value optimization.
     *
     * @param  array<string, mixed>  $params  Internal form_submit params
     * @return array{content_name: string|null, content_category: string, value: float|null, currency: string|null, form_id: string|null}
     */
    public static function formSubmitToMeta(array $params): array
    {
        return [
            'content_name' => $params['form_name'] ?? $params['name'] ?? 'form_submit',
            'content_category' => 'form',
            'value' => isset($params['value']) ? (float) $params['value'] : null,
            'currency' => $params['currency'] ?? 'USD',
            'form_id' => $params['form_id'] ?? $params['element_id'] ?? null,
        ];
    }

    /**
     * Convert form_submit event params to PostHog properties.
     *
     * @param  array<string, mixed>  $params  Internal form_submit params
     * @return array{form_id: string|null, form_name: string|null, form_action: string|null, success: bool, value: float|null, currency: string|null, $current_url: string|null}
     */
    public static function formSubmitToPosthog(array $params): array
    {
        return [
            'form_id' => $params['form_id'] ?? $params['element_id'] ?? null,
            'form_name' => $params['form_name'] ?? $params['name'] ?? null,
            'form_action' => $params['form_action'] ?? $params['form_destination'] ?? null,
            'success' => (bool) ($params['success'] ?? true),
            'value' => isset($params['value']) ? (float) $params['value'] : null,
            'currency' => $params['currency'] ?? null,
            '$current_url' => $params['page_location'] ?? null,
        ];
    }

    // ── search → GA4 / Meta / PostHog ───────────────────────────────

    /**
     * Convert search event params to GA4 search event format.
     *
     * GA4's search event uses search_term as the primary parameter.
     * Optional: number_of_results for search UX analytics.
     *
     * @param  array<string, mixed>  $params  Internal search params
     * @return array{search_term: string|null, number_of_results: int|null, category: string|null}
     */
    public static function searchToGa4(array $params): array
    {
        return [
            'search_term' => $params['query'] ?? $params['search_term'] ?? $params['term'] ?? null,
            'number_of_results' => isset($params['results_count'])
                ? (int) $params['results_count']
                : (isset($params['number_of_results']) ? (int) $params['number_of_results'] : null),
            'category' => $params['category'] ?? null,
        ];
    }

    /**
     * Convert search event params to Meta Pixel Search format.
     *
     * Meta's Search standard event requires search_string parameter.
     *
     * @param  array<string, mixed>  $params  Internal search params
     * @return array{search_string: string|null, content_category: string|null, content_name: string|null}
     */
    public static function searchToMeta(array $params): array
    {
        return [
            'search_string' => $params['query'] ?? $params['search_term'] ?? $params['term'] ?? null,
            'content_category' => $params['category'] ?? null,
            'content_name' => $params['content_name'] ?? null,
        ];
    }

    /**
     * Convert search event params to PostHog $search properties.
     *
     * PostHog's $search is an autocaptured event. When sent via API,
     * uses $search as the primary property with $current_url context.
     *
     * @param  array<string, mixed>  $params  Internal search params
     * @return array{$search: string|null, results: int|null, category: string|null, $current_url: string|null}
     */
    public static function searchToPosthog(array $params): array
    {
        return [
            '$search' => $params['query'] ?? $params['search_term'] ?? $params['term'] ?? null,
            'results' => isset($params['results_count'])
                ? (int) $params['results_count']
                : (isset($params['number_of_results']) ? (int) $params['number_of_results'] : null),
            'category' => $params['category'] ?? null,
            '$current_url' => $params['page_location'] ?? null,
        ];
    }

    // ── share → GA4 / Meta / PostHog ─────────────────────────────────

    /**
     * Convert share event params to GA4 share event format.
     *
     * GA4's share event uses method, content_type, item_id parameters.
     * Method is the sharing mechanism (Twitter, Facebook, Email, Copy, etc.)
     *
     * @param  array<string, mixed>  $params  Internal share params
     * @return array{method: string|null, content_type: string|null, item_id: string|null, item_name: string|null}
     */
    public static function shareToGa4(array $params): array
    {
        return [
            'method' => $params['method'] ?? $params['platform'] ?? $params['channel'] ?? null,
            'content_type' => (string) ($params['content_type'] ?? 'page'),
            'item_id' => $params['item_id'] ?? $params['content_id'] ?? null,
            'item_name' => $params['item_name'] ?? $params['content_name'] ?? null,
        ];
    }

    /**
     * Convert share event params to Meta Pixel Share format.
     *
     * Meta's Share is a standard event with content_type and content_name.
     *
     * @param  array<string, mixed>  $params  Internal share params
     * @return array{content_type: string, content_name: string|null, method: string|null, share_url: string|null}
     */
    public static function shareToMeta(array $params): array
    {
        return [
            'content_type' => (string) ($params['content_type'] ?? 'page'),
            'content_name' => $params['content_name'] ?? $params['item_name'] ?? null,
            'method' => $params['method'] ?? $params['platform'] ?? $params['channel'] ?? null,
            'share_url' => $params['share_url'] ?? $params['url'] ?? null,
        ];
    }

    /**
     * Convert share event params to PostHog properties.
     *
     * @param  array<string, mixed>  $params  Internal share params
     * @return array{$share: string|null, method: string|null, content_type: string|null, item_id: string|null, $current_url: string|null}
     */
    public static function shareToPosthog(array $params): array
    {
        return [
            '$share' => $params['share_url'] ?? $params['url'] ?? null,
            'method' => $params['method'] ?? $params['platform'] ?? $params['channel'] ?? null,
            'content_type' => (string) ($params['content_type'] ?? 'page'),
            'item_id' => $params['item_id'] ?? $params['content_id'] ?? null,
            '$current_url' => $params['page_location'] ?? null,
        ];
    }

    // ── error → GA4 / Meta / PostHog ──────────────────────────────────

    /**
     * Convert error event params to GA4 format.
     *
     * GA4 error events use error_message, error_code, and fatal (bool).
     *
     * @param  array<string, mixed>  $params  Internal error params
     * @return array{error_message: string|null, error_code: int|string|null, fatal: bool|null, page_location: string|null, description: string|null}
     */
    public static function errorToGa4(array $params): array
    {
        return [
            'error_message' => $params['message'] ?? $params['error_message'] ?? null,
            'error_code' => $params['code'] ?? $params['error_code'] ?? null,
            'fatal' => isset($params['fatal']) ? (bool) $params['fatal'] : null,
            'page_location' => $params['url'] ?? $params['page_location'] ?? null,
            'description' => $params['description'] ?? $params['stack'] ?? null,
        ];
    }

    /**
     * Convert error event params to Meta Pixel custom event format.
     *
     * Meta has no standard Error event. Use custom event with
     * error context for debugging and monitoring.
     *
     * @param  array<string, mixed>  $params  Internal error params
     * @return array{content_name: string, error_message: string|null, error_code: string|int|null, fatal: bool|null, error_source: string|null}
     */
    public static function errorToMeta(array $params): array
    {
        return [
            'content_name' => (string) ($params['content_name'] ?? 'error'),
            'error_message' => $params['message'] ?? $params['error_message'] ?? null,
            'error_code' => $params['code'] ?? $params['error_code'] ?? null,
            'fatal' => isset($params['fatal']) ? (bool) $params['fatal'] : null,
            'error_source' => $params['source'] ?? $params['error_source'] ?? null,
        ];
    }

    /**
     * Convert error event params to PostHog $exception properties.
     *
     * PostHog uses $exception for error tracking with $exception_type,
     * $exception_message, $exception_context, $exception_level.
     *
     * @param  array<string, mixed>  $params  Internal error params
     * @return array{$exception_type: string|null, $exception_message: string|null, $exception_context: string|null, $exception_level: string|null, $exception_stack: string|null, $current_url: string|null, $lib: string}
     */
    public static function errorToPosthog(array $params): array
    {
        return [
            '$exception_type' => $params['type'] ?? $params['error_type'] ?? null,
            '$exception_message' => $params['message'] ?? $params['error_message'] ?? null,
            '$exception_context' => $params['context'] ?? $params['error_context'] ?? null,
            '$exception_level' => $params['level'] ?? ($params['fatal'] === true ? 'fatal' : 'error'),
            '$exception_stack' => $params['stack'] ?? $params['stacktrace'] ?? null,
            '$current_url' => $params['url'] ?? $params['page_location'] ?? null,
            '$lib' => 'zeroboiler-analytics-server',
        ];
    }

    // ── page_view → Mixpanel / Amplitude / Plausible / TikTok / LinkedIn ──

    /**
     * Convert page_view event params to Mixpanel page view properties.
     *
     * Mixpanel tracks page views as custom events with page context.
     *
     * @param  array<string, mixed>  $params  Internal page_view params
     * @return array{page: string|null, title: string|null, referrer: string|null, engagement_time: int|null}
     */
    public static function pageViewToMixpanel(array $params): array
    {
        return [
            'page' => $params['url'] ?? $params['page_location'] ?? null,
            'title' => $params['title'] ?? $params['page_title'] ?? null,
            'referrer' => $params['referrer'] ?? $params['page_referrer'] ?? null,
            'engagement_time' => isset($params['engagement_time_msec']) ? (int) $params['engagement_time_msec'] : null,
        ];
    }

    /**
     * Convert page_view event params to Amplitude page view properties.
     *
     * @param  array<string, mixed>  $params  Internal page_view params
     * @return array{page_title: string|null, page_location: string|null, page_referrer: string|null, engagement_time_msec: int|null}
     */
    public static function pageViewToAmplitude(array $params): array
    {
        return [
            'page_title' => $params['title'] ?? $params['page_title'] ?? null,
            'page_location' => $params['url'] ?? $params['page_location'] ?? null,
            'page_referrer' => $params['referrer'] ?? $params['page_referrer'] ?? null,
            'engagement_time_msec' => isset($params['engagement_time_msec']) ? (int) $params['engagement_time_msec'] : null,
        ];
    }

    /**
     * Convert page_view event params to Plausible event properties.
     *
     * Plausible tracks page views natively; these props are for custom dimensions.
     *
     * @param  array<string, mixed>  $params  Internal page_view params
     * @return array{path: string|null, title: string|null, referrer: string|null}
     */
    public static function pageViewToPlausible(array $params): array
    {
        return [
            'path' => $params['url'] ?? $params['page_location'] ?? null,
            'title' => $params['title'] ?? $params['page_title'] ?? null,
            'referrer' => $params['referrer'] ?? $params['page_referrer'] ?? null,
        ];
    }

    /**
     * Convert page_view event params to TikTok page view properties.
     *
     * @param  array<string, mixed>  $params  Internal page_view params
     * @return array{content_name: string|null, content_type: string, page_url: string|null, referrer: string|null}
     */
    public static function pageViewToTiktok(array $params): array
    {
        return [
            'content_name' => $params['title'] ?? $params['page_title'] ?? null,
            'content_type' => 'product',
            'page_url' => $params['url'] ?? $params['page_location'] ?? null,
            'referrer' => $params['referrer'] ?? $params['page_referrer'] ?? null,
        ];
    }

    /**
     * Convert page_view event params to LinkedIn Insight properties.
     *
     * @param  array<string, mixed>  $params  Internal page_view params
     * @return array{value: float, currency: string, page_url: string|null, page_title: string|null}
     */
    public static function pageViewToLinkedin(array $params): array
    {
        return [
            'value' => 0.0,
            'currency' => 'USD',
            'page_url' => $params['url'] ?? $params['page_location'] ?? null,
            'page_title' => $params['title'] ?? $params['page_title'] ?? null,
        ];
    }

    // ── scroll_depth → Mixpanel / Amplitude / Plausible / TikTok / LinkedIn ──

    /**
     * Convert scroll_depth event params to Mixpanel properties.
     *
     * @param  array<string, mixed>  $params  Internal scroll_depth params
     * @return array{scroll_percent: int|null, page_url: string|null, engagement_time: int|null}
     */
    public static function scrollDepthToMixpanel(array $params): array
    {
        return [
            'scroll_percent' => isset($params['percent'])
                ? (int) $params['percent']
                : (isset($params['scroll_percent']) ? (int) $params['scroll_percent'] : null),
            'page_url' => $params['url'] ?? $params['page_location'] ?? null,
            'engagement_time' => isset($params['engagement_time_msec']) ? (int) $params['engagement_time_msec'] : null,
        ];
    }

    /**
     * Convert scroll_depth event params to Amplitude properties.
     *
     * @param  array<string, mixed>  $params  Internal scroll_depth params
     * @return array{scroll_depth: int|null, page_location: string|null, engagement_time_msec: int|null}
     */
    public static function scrollDepthToAmplitude(array $params): array
    {
        return [
            'scroll_depth' => isset($params['percent'])
                ? (int) $params['percent']
                : (isset($params['scroll_percent']) ? (int) $params['scroll_percent'] : null),
            'page_location' => $params['url'] ?? $params['page_location'] ?? null,
            'engagement_time_msec' => isset($params['engagement_time_msec']) ? (int) $params['engagement_time_msec'] : null,
        ];
    }

    /**
     * Convert scroll_depth event params to Plausible properties.
     *
     * @param  array<string, mixed>  $params  Internal scroll_depth params
     * @return array{scroll_depth: string|null}
     */
    public static function scrollDepthToPlausible(array $params): array
    {
        $percent = $params['percent'] ?? $params['scroll_percent'] ?? null;

        return [
            'scroll_depth' => $percent !== null ? (string) $percent . '%' : null,
        ];
    }

    /**
     * Convert scroll_depth event params to TikTok properties.
     *
     * @param  array<string, mixed>  $params  Internal scroll_depth params
     * @return array{content_name: string, scroll_percent: int|null, page_url: string|null}
     */
    public static function scrollDepthToTiktok(array $params): array
    {
        return [
            'content_name' => 'scroll',
            'scroll_percent' => isset($params['percent'])
                ? (int) $params['percent']
                : (isset($params['scroll_percent']) ? (int) $params['scroll_percent'] : null),
            'page_url' => $params['url'] ?? $params['page_location'] ?? null,
        ];
    }

    /**
     * Convert scroll_depth event params to LinkedIn properties.
     *
     * @param  array<string, mixed>  $params  Internal scroll_depth params
     * @return array{scroll_depth: int|null}
     */
    public static function scrollDepthToLinkedin(array $params): array
    {
        return [
            'scroll_depth' => isset($params['percent'])
                ? (int) $params['percent']
                : (isset($params['scroll_percent']) ? (int) $params['scroll_percent'] : null),
        ];
    }

    // ── click → Mixpanel / Amplitude / Plausible / TikTok / LinkedIn ──

    /**
     * Convert click event params to Mixpanel properties.
     *
     * @param  array<string, mixed>  $params  Internal click params
     * @return array{link_url: string|null, link_text: string|null, outbound: bool, element_class: string|null, element_id: string|null}
     */
    public static function clickToMixpanel(array $params): array
    {
        return [
            'link_url' => $params['url'] ?? $params['link_url'] ?? null,
            'link_text' => $params['text'] ?? $params['link_text'] ?? null,
            'outbound' => (bool) ($params['outbound'] ?? false),
            'element_class' => $params['element_class'] ?? null,
            'element_id' => $params['element_id'] ?? null,
        ];
    }

    /**
     * Convert click event params to Amplitude properties.
     *
     * @param  array<string, mixed>  $params  Internal click params
     * @return array{link_url: string|null, link_text: string|null, link_domain: string|null, outbound: bool, element_id: string|null, element_tag: string|null}
     */
    public static function clickToAmplitude(array $params): array
    {
        $linkUrl = $params['url'] ?? $params['link_url'] ?? null;
        $parsedDomain = null;
        if (is_string($linkUrl) && $linkUrl !== '') {
            $parsed = parse_url($linkUrl, PHP_URL_HOST);
            $parsedDomain = is_string($parsed) ? $parsed : null;
        }

        return [
            'link_url' => $linkUrl,
            'link_text' => $params['text'] ?? $params['link_text'] ?? null,
            'link_domain' => $parsedDomain,
            'outbound' => (bool) ($params['outbound'] ?? false),
            'element_id' => $params['element_id'] ?? null,
            'element_tag' => $params['element_tag'] ?? $params['tag'] ?? null,
        ];
    }

    /**
     * Convert click event params to Plausible properties.
     *
     * @param  array<string, mixed>  $params  Internal click params
     * @return array{link_url: string|null, outbound: string|null}
     */
    public static function clickToPlausible(array $params): array
    {
        return [
            'link_url' => $params['url'] ?? $params['link_url'] ?? null,
            'outbound' => ($params['outbound'] ?? false) ? 'true' : 'false',
        ];
    }

    /**
     * Convert click event params to TikTok properties.
     *
     * @param  array<string, mixed>  $params  Internal click params
     * @return array{content_name: string|null, content_type: string, link_url: string|null}
     */
    public static function clickToTiktok(array $params): array
    {
        return [
            'content_name' => $params['text'] ?? $params['link_text'] ?? $params['element_id'] ?? null,
            'content_type' => 'click',
            'link_url' => $params['url'] ?? $params['link_url'] ?? null,
        ];
    }

    /**
     * Convert click event params to LinkedIn properties.
     *
     * @param  array<string, mixed>  $params  Internal click params
     * @return array{link_url: string|null, element_id: string|null}
     */
    public static function clickToLinkedin(array $params): array
    {
        return [
            'link_url' => $params['url'] ?? $params['link_url'] ?? null,
            'element_id' => $params['element_id'] ?? null,
        ];
    }

    // ── form_start → Mixpanel / Amplitude / Plausible / TikTok / LinkedIn ──

    /**
     * Convert form_start event params to Mixpanel properties.
     *
     * @param  array<string, mixed>  $params  Internal form_start params
     * @return array{form_id: string|null, form_name: string|null, form_destination: string|null, page_url: string|null}
     */
    public static function formStartToMixpanel(array $params): array
    {
        return [
            'form_id' => $params['form_id'] ?? $params['element_id'] ?? null,
            'form_name' => $params['form_name'] ?? $params['name'] ?? null,
            'form_destination' => $params['form_action'] ?? $params['form_destination'] ?? null,
            'page_url' => $params['page_location'] ?? null,
        ];
    }

    /**
     * Convert form_start event params to Amplitude properties.
     *
     * @param  array<string, mixed>  $params  Internal form_start params
     * @return array{form_id: string|null, form_name: string|null, form_destination: string|null}
     */
    public static function formStartToAmplitude(array $params): array
    {
        return [
            'form_id' => $params['form_id'] ?? $params['element_id'] ?? null,
            'form_name' => $params['form_name'] ?? $params['name'] ?? null,
            'form_destination' => $params['form_action'] ?? $params['form_destination'] ?? null,
        ];
    }

    /**
     * Convert form_start event params to Plausible properties.
     *
     * @param  array<string, mixed>  $params  Internal form_start params
     * @return array{form_id: string|null, form_name: string|null}
     */
    public static function formStartToPlausible(array $params): array
    {
        return [
            'form_id' => $params['form_id'] ?? $params['element_id'] ?? null,
            'form_name' => $params['form_name'] ?? $params['name'] ?? null,
        ];
    }

    /**
     * Convert form_start event params to TikTok properties.
     *
     * @param  array<string, mixed>  $params  Internal form_start params
     * @return array{content_name: string|null, content_type: string, form_id: string|null}
     */
    public static function formStartToTiktok(array $params): array
    {
        return [
            'content_name' => $params['form_name'] ?? $params['name'] ?? 'form_start',
            'content_type' => 'form',
            'form_id' => $params['form_id'] ?? $params['element_id'] ?? null,
        ];
    }

    /**
     * Convert form_start event params to LinkedIn properties.
     *
     * @param  array<string, mixed>  $params  Internal form_start params
     * @return array{form_id: string|null, form_name: string|null}
     */
    public static function formStartToLinkedin(array $params): array
    {
        return [
            'form_id' => $params['form_id'] ?? $params['element_id'] ?? null,
            'form_name' => $params['form_name'] ?? $params['name'] ?? null,
        ];
    }

    // ── form_submit → Mixpanel / Amplitude / Plausible / TikTok / LinkedIn ──

    /**
     * Convert form_submit event params to Mixpanel properties.
     *
     * @param  array<string, mixed>  $params  Internal form_submit params
     * @return array{form_id: string|null, form_name: string|null, success: bool, value: float|null, currency: string|null}
     */
    public static function formSubmitToMixpanel(array $params): array
    {
        return [
            'form_id' => $params['form_id'] ?? $params['element_id'] ?? null,
            'form_name' => $params['form_name'] ?? $params['name'] ?? null,
            'success' => (bool) ($params['success'] ?? true),
            'value' => isset($params['value']) ? (float) $params['value'] : null,
            'currency' => $params['currency'] ?? null,
        ];
    }

    /**
     * Convert form_submit event params to Amplitude properties.
     *
     * @param  array<string, mixed>  $params  Internal form_submit params
     * @return array{form_id: string|null, form_name: string|null, form_destination: string|null, value: float|null, currency: string|null, success: bool}
     */
    public static function formSubmitToAmplitude(array $params): array
    {
        return [
            'form_id' => $params['form_id'] ?? $params['element_id'] ?? null,
            'form_name' => $params['form_name'] ?? $params['name'] ?? null,
            'form_destination' => $params['form_action'] ?? $params['form_destination'] ?? null,
            'value' => isset($params['value']) ? (float) $params['value'] : null,
            'currency' => $params['currency'] ?? null,
            'success' => (bool) ($params['success'] ?? true),
        ];
    }

    /**
     * Convert form_submit event params to Plausible properties.
     *
     * @param  array<string, mixed>  $params  Internal form_submit params
     * @return array{form_id: string|null, form_name: string|null, success: string|null}
     */
    public static function formSubmitToPlausible(array $params): array
    {
        return [
            'form_id' => $params['form_id'] ?? $params['element_id'] ?? null,
            'form_name' => $params['form_name'] ?? $params['name'] ?? null,
            'success' => ($params['success'] ?? true) ? 'true' : 'false',
        ];
    }

    /**
     * Convert form_submit event params to TikTok properties.
     *
     * @param  array<string, mixed>  $params  Internal form_submit params
     * @return array{content_name: string|null, content_type: string, value: float|null, currency: string|null, form_id: string|null}
     */
    public static function formSubmitToTiktok(array $params): array
    {
        return [
            'content_name' => $params['form_name'] ?? $params['name'] ?? 'form_submit',
            'content_type' => 'form',
            'value' => isset($params['value']) ? (float) $params['value'] : null,
            'currency' => $params['currency'] ?? 'USD',
            'form_id' => $params['form_id'] ?? $params['element_id'] ?? null,
        ];
    }

    /**
     * Convert form_submit event params to LinkedIn properties.
     *
     * @param  array<string, mixed>  $params  Internal form_submit params
     * @return array{value: float|null, currency: string|null, form_id: string|null}
     */
    public static function formSubmitToLinkedin(array $params): array
    {
        return [
            'value' => isset($params['value']) ? (float) $params['value'] : null,
            'currency' => $params['currency'] ?? null,
            'form_id' => $params['form_id'] ?? $params['element_id'] ?? null,
        ];
    }

    // ── search → Mixpanel / Amplitude / Plausible / TikTok / LinkedIn ──

    /**
     * Convert search event params to Mixpanel properties.
     *
     * @param  array<string, mixed>  $params  Internal search params
     * @return array{search_term: string|null, results_count: int|null, category: string|null}
     */
    public static function searchToMixpanel(array $params): array
    {
        return [
            'search_term' => $params['query'] ?? $params['search_term'] ?? $params['term'] ?? null,
            'results_count' => isset($params['results_count'])
                ? (int) $params['results_count']
                : (isset($params['number_of_results']) ? (int) $params['number_of_results'] : null),
            'category' => $params['category'] ?? null,
        ];
    }

    /**
     * Convert search event params to Amplitude properties.
     *
     * @param  array<string, mixed>  $params  Internal search params
     * @return array{search_query: string|null, results: int|null, category: string|null}
     */
    public static function searchToAmplitude(array $params): array
    {
        return [
            'search_query' => $params['query'] ?? $params['search_term'] ?? $params['term'] ?? null,
            'results' => isset($params['results_count'])
                ? (int) $params['results_count']
                : (isset($params['number_of_results']) ? (int) $params['number_of_results'] : null),
            'category' => $params['category'] ?? null,
        ];
    }

    /**
     * Convert search event params to Plausible properties.
     *
     * @param  array<string, mixed>  $params  Internal search params
     * @return array{search_term: string|null}
     */
    public static function searchToPlausible(array $params): array
    {
        return [
            'search_term' => $params['query'] ?? $params['search_term'] ?? $params['term'] ?? null,
        ];
    }

    /**
     * Convert search event params to TikTok properties.
     *
     * @param  array<string, mixed>  $params  Internal search params
     * @return array{content_name: string|null, content_type: string|null, search_string: string|null}
     */
    public static function searchToTiktok(array $params): array
    {
        return [
            'content_name' => $params['content_name'] ?? null,
            'content_type' => $params['category'] ?? null,
            'search_string' => $params['query'] ?? $params['search_term'] ?? $params['term'] ?? null,
        ];
    }

    /**
     * Convert search event params to LinkedIn properties.
     *
     * @param  array<string, mixed>  $params  Internal search params
     * @return array{search_term: string|null, category: string|null}
     */
    public static function searchToLinkedin(array $params): array
    {
        return [
            'search_term' => $params['query'] ?? $params['search_term'] ?? $params['term'] ?? null,
            'category' => $params['category'] ?? null,
        ];
    }

    // ── share → Mixpanel / Amplitude / Plausible / TikTok / LinkedIn ──

    /**
     * Convert share event params to Mixpanel properties.
     *
     * @param  array<string, mixed>  $params  Internal share params
     * @return array{method: string|null, content_type: string|null, item_id: string|null, item_name: string|null, share_url: string|null}
     */
    public static function shareToMixpanel(array $params): array
    {
        return [
            'method' => $params['method'] ?? $params['platform'] ?? $params['channel'] ?? null,
            'content_type' => (string) ($params['content_type'] ?? 'page'),
            'item_id' => $params['item_id'] ?? $params['content_id'] ?? null,
            'item_name' => $params['item_name'] ?? $params['content_name'] ?? null,
            'share_url' => $params['share_url'] ?? $params['url'] ?? null,
        ];
    }

    /**
     * Convert share event params to Amplitude properties.
     *
     * @param  array<string, mixed>  $params  Internal share params
     * @return array{method: string|null, content_type: string|null, item_id: string|null, share_url: string|null}
     */
    public static function shareToAmplitude(array $params): array
    {
        return [
            'method' => $params['method'] ?? $params['platform'] ?? $params['channel'] ?? null,
            'content_type' => (string) ($params['content_type'] ?? 'page'),
            'item_id' => $params['item_id'] ?? $params['content_id'] ?? null,
            'share_url' => $params['share_url'] ?? $params['url'] ?? null,
        ];
    }

    /**
     * Convert share event params to Plausible properties.
     *
     * @param  array<string, mixed>  $params  Internal share params
     * @return array{method: string|null, content_type: string|null}
     */
    public static function shareToPlausible(array $params): array
    {
        return [
            'method' => $params['method'] ?? $params['platform'] ?? $params['channel'] ?? null,
            'content_type' => (string) ($params['content_type'] ?? 'page'),
        ];
    }

    /**
     * Convert share event params to TikTok properties.
     *
     * @param  array<string, mixed>  $params  Internal share params
     * @return array{content_type: string, content_name: string|null, method: string|null, share_url: string|null}
     */
    public static function shareToTiktok(array $params): array
    {
        return [
            'content_type' => (string) ($params['content_type'] ?? 'page'),
            'content_name' => $params['content_name'] ?? $params['item_name'] ?? null,
            'method' => $params['method'] ?? $params['platform'] ?? $params['channel'] ?? null,
            'share_url' => $params['share_url'] ?? $params['url'] ?? null,
        ];
    }

    /**
     * Convert share event params to LinkedIn properties.
     *
     * @param  array<string, mixed>  $params  Internal share params
     * @return array{method: string|null, content_type: string|null, item_id: string|null}
     */
    public static function shareToLinkedin(array $params): array
    {
        return [
            'method' => $params['method'] ?? $params['platform'] ?? $params['channel'] ?? null,
            'content_type' => (string) ($params['content_type'] ?? 'page'),
            'item_id' => $params['item_id'] ?? $params['content_id'] ?? null,
        ];
    }

    // ── error → Mixpanel / Amplitude / Plausible / TikTok / LinkedIn ──

    /**
     * Convert error event params to Mixpanel properties.
     *
     * @param  array<string, mixed>  $params  Internal error params
     * @return array{error_message: string|null, error_code: string|int|null, fatal: bool|null, page_url: string|null, error_source: string|null, error_type: string|null}
     */
    public static function errorToMixpanel(array $params): array
    {
        return [
            'error_message' => $params['message'] ?? $params['error_message'] ?? null,
            'error_code' => $params['code'] ?? $params['error_code'] ?? null,
            'fatal' => isset($params['fatal']) ? (bool) $params['fatal'] : null,
            'page_url' => $params['url'] ?? $params['page_location'] ?? null,
            'error_source' => $params['source'] ?? $params['error_source'] ?? null,
            'error_type' => $params['type'] ?? $params['error_type'] ?? null,
        ];
    }

    /**
     * Convert error event params to Amplitude properties.
     *
     * @param  array<string, mixed>  $params  Internal error params
     * @return array{error_message: string|null, error_type: string|null, error_code: string|int|null, fatal: bool|null, page_location: string|null, stack_trace: string|null}
     */
    public static function errorToAmplitude(array $params): array
    {
        return [
            'error_message' => $params['message'] ?? $params['error_message'] ?? null,
            'error_type' => $params['type'] ?? $params['error_type'] ?? null,
            'error_code' => $params['code'] ?? $params['error_code'] ?? null,
            'fatal' => isset($params['fatal']) ? (bool) $params['fatal'] : null,
            'page_location' => $params['url'] ?? $params['page_location'] ?? null,
            'stack_trace' => $params['stack'] ?? $params['stacktrace'] ?? null,
        ];
    }

    /**
     * Convert error event params to Plausible properties.
     *
     * @param  array<string, mixed>  $params  Internal error params
     * @return array{error_message: string|null, error_code: string|null, fatal: string|null}
     */
    public static function errorToPlausible(array $params): array
    {
        return [
            'error_message' => $params['message'] ?? $params['error_message'] ?? null,
            'error_code' => isset($params['code']) ? (string) $params['code'] : (isset($params['error_code']) ? (string) $params['error_code'] : null),
            'fatal' => isset($params['fatal']) ? ($params['fatal'] ? 'true' : 'false') : null,
        ];
    }

    /**
     * Convert error event params to TikTok properties.
     *
     * @param  array<string, mixed>  $params  Internal error params
     * @return array{content_name: string, error_message: string|null, error_code: string|int|null, fatal: bool|null}
     */
    public static function errorToTiktok(array $params): array
    {
        return [
            'content_name' => 'error',
            'error_message' => $params['message'] ?? $params['error_message'] ?? null,
            'error_code' => $params['code'] ?? $params['error_code'] ?? null,
            'fatal' => isset($params['fatal']) ? (bool) $params['fatal'] : null,
        ];
    }

    /**
     * Convert error event params to LinkedIn properties.
     *
     * @param  array<string, mixed>  $params  Internal error params
     * @return array{error_message: string|null, error_code: string|int|null}
     */
    public static function errorToLinkedin(array $params): array
    {
        return [
            'error_message' => $params['message'] ?? $params['error_message'] ?? null,
            'error_code' => $params['code'] ?? $params['error_code'] ?? null,
        ];
    }

    // ── Generic Engagement Event Converter (8 providers) ───────────────────

    /**
     * Convert any engagement event to a specific provider's format.
     *
     * Central dispatch method that routes to the appropriate converter
     * based on event name and target provider. Supports all 8 providers.
     * Supports the 8 core engagement events plus aliases.
     *
     * @param  string  $eventName  Internal event name
     * @param  array<string, mixed>  $params  Internal event params
     * @param  'ga4'|'meta'|'posthog'|'mixpanel'|'amplitude'|'plausible'|'tiktok'|'linkedin'  $provider  Target provider
     * @return array<string, mixed>  Provider-formatted params
     */
    public static function convertForProvider(string $eventName, array $params, string $provider): array
    {
        return match ($eventName) {
            'page_view' => match ($provider) {
                'ga4' => self::pageViewToGa4($params),
                'meta' => self::pageViewToMeta($params),
                'posthog' => self::pageViewToPosthog($params),
                'mixpanel' => self::pageViewToMixpanel($params),
                'amplitude' => self::pageViewToAmplitude($params),
                'plausible' => self::pageViewToPlausible($params),
                'tiktok' => self::pageViewToTiktok($params),
                'linkedin' => self::pageViewToLinkedin($params),
                default => $params,
            },
            'scroll_depth' => match ($provider) {
                'ga4' => self::scrollDepthToGa4($params),
                'meta' => self::scrollDepthToMeta($params),
                'posthog' => self::scrollDepthToPosthog($params),
                'mixpanel' => self::scrollDepthToMixpanel($params),
                'amplitude' => self::scrollDepthToAmplitude($params),
                'plausible' => self::scrollDepthToPlausible($params),
                'tiktok' => self::scrollDepthToTiktok($params),
                'linkedin' => self::scrollDepthToLinkedin($params),
                default => $params,
            },
            'click' => match ($provider) {
                'ga4' => self::clickToGa4($params),
                'meta' => self::clickToMeta($params),
                'posthog' => self::clickToPosthog($params),
                'mixpanel' => self::clickToMixpanel($params),
                'amplitude' => self::clickToAmplitude($params),
                'plausible' => self::clickToPlausible($params),
                'tiktok' => self::clickToTiktok($params),
                'linkedin' => self::clickToLinkedin($params),
                default => $params,
            },
            'form_start' => match ($provider) {
                'ga4' => self::formStartToGa4($params),
                'meta' => self::formStartToMeta($params),
                'posthog' => self::formStartToPosthog($params),
                'mixpanel' => self::formStartToMixpanel($params),
                'amplitude' => self::formStartToAmplitude($params),
                'plausible' => self::formStartToPlausible($params),
                'tiktok' => self::formStartToTiktok($params),
                'linkedin' => self::formStartToLinkedin($params),
                default => $params,
            },
            'form_submit' => match ($provider) {
                'ga4' => self::formSubmitToGa4($params),
                'meta' => self::formSubmitToMeta($params),
                'posthog' => self::formSubmitToPosthog($params),
                'mixpanel' => self::formSubmitToMixpanel($params),
                'amplitude' => self::formSubmitToAmplitude($params),
                'plausible' => self::formSubmitToPlausible($params),
                'tiktok' => self::formSubmitToTiktok($params),
                'linkedin' => self::formSubmitToLinkedin($params),
                default => $params,
            },
            'search' => match ($provider) {
                'ga4' => self::searchToGa4($params),
                'meta' => self::searchToMeta($params),
                'posthog' => self::searchToPosthog($params),
                'mixpanel' => self::searchToMixpanel($params),
                'amplitude' => self::searchToAmplitude($params),
                'plausible' => self::searchToPlausible($params),
                'tiktok' => self::searchToTiktok($params),
                'linkedin' => self::searchToLinkedin($params),
                default => $params,
            },
            'share' => match ($provider) {
                'ga4' => self::shareToGa4($params),
                'meta' => self::shareToMeta($params),
                'posthog' => self::shareToPosthog($params),
                'mixpanel' => self::shareToMixpanel($params),
                'amplitude' => self::shareToAmplitude($params),
                'plausible' => self::shareToPlausible($params),
                'tiktok' => self::shareToTiktok($params),
                'linkedin' => self::shareToLinkedin($params),
                default => $params,
            },
            'error' => match ($provider) {
                'ga4' => self::errorToGa4($params),
                'meta' => self::errorToMeta($params),
                'posthog' => self::errorToPosthog($params),
                'mixpanel' => self::errorToMixpanel($params),
                'amplitude' => self::errorToAmplitude($params),
                'plausible' => self::errorToPlausible($params),
                'tiktok' => self::errorToTiktok($params),
                'linkedin' => self::errorToLinkedin($params),
                default => $params,
            },
            // Aliases
            'js_error' => match ($provider) {
                'ga4' => self::errorToGa4($params),
                'meta' => self::errorToMeta($params),
                'posthog' => self::errorToPosthog($params),
                'mixpanel' => self::errorToMixpanel($params),
                'amplitude' => self::errorToAmplitude($params),
                'plausible' => self::errorToPlausible($params),
                'tiktok' => self::errorToTiktok($params),
                'linkedin' => self::errorToLinkedin($params),
                default => $params,
            },
            'client_error' => match ($provider) {
                'ga4' => self::errorToGa4($params),
                'meta' => self::errorToMeta($params),
                'posthog' => self::errorToPosthog($params),
                'mixpanel' => self::errorToMixpanel($params),
                'amplitude' => self::errorToAmplitude($params),
                'plausible' => self::errorToPlausible($params),
                'tiktok' => self::errorToTiktok($params),
                'linkedin' => self::errorToLinkedin($params),
                default => $params,
            },
            'outbound_click' => match ($provider) {
                'ga4' => self::clickToGa4(array_merge($params, ['outbound' => true])),
                'meta' => self::clickToMeta($params),
                'posthog' => self::clickToPosthog(array_merge($params, ['outbound' => true])),
                'mixpanel' => self::clickToMixpanel(array_merge($params, ['outbound' => true])),
                'amplitude' => self::clickToAmplitude(array_merge($params, ['outbound' => true])),
                'plausible' => self::clickToPlausible($params),
                'tiktok' => self::clickToTiktok($params),
                'linkedin' => self::clickToLinkedin($params),
                default => $params,
            },
            'file_download' => match ($provider) {
                'ga4' => self::clickToGa4(array_merge($params, ['outbound' => false])),
                'meta' => self::clickToMeta(array_merge($params, ['content_category' => 'download'])),
                'posthog' => self::clickToPosthog($params),
                'mixpanel' => self::clickToMixpanel($params),
                'amplitude' => self::clickToAmplitude($params),
                'plausible' => self::clickToPlausible($params),
                'tiktok' => self::clickToTiktok($params),
                'linkedin' => self::clickToLinkedin($params),
                default => $params,
            },
            default => $params,
        };
    }

    /**
     * Build a provider-optimized AnalyticsEvent from internal engagement params.
     *
     * Convenience method that converts params AND creates a ready-to-dispatch
     * AnalyticsEvent with the correct category tag.
     *
     * @param  string  $eventName  Internal event name
     * @param  array<string, mixed>  $params  Internal event params
     * @param  'ga4'|'meta'|'posthog'|'mixpanel'|'amplitude'|'plausible'|'tiktok'|'linkedin'  $provider  Target provider
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     * @return AnalyticsEvent  Provider-optimized event
     */
    public static function buildProviderEvent(
        string $eventName,
        array $params,
        string $provider,
        ?string $clientId = null,
        ?string $userId = null,
    ): AnalyticsEvent {
        $convertedParams = self::convertForProvider($eventName, $params, $provider);

        return new AnalyticsEvent(
            name: $eventName,
            params: $convertedParams,
            clientId: $clientId,
            userId: $userId,
            category: 'engagement',
        );
    }

    /**
     * Get all supported engagement event names.
     *
     * @return list<string>
     */
    public static function supportedEvents(): array
    {
        return [
            'page_view',
            'scroll_depth',
            'click',
            'form_start',
            'form_submit',
            'search',
            'share',
            'error',
            'js_error',
            'client_error',
            'outbound_click',
            'file_download',
        ];
    }

    /**
     * Check if a given event name is supported by this converter.
     */
    public static function supports(string $eventName): bool
    {
        return in_array($eventName, self::supportedEvents(), true);
    }

    /**
     * Get all supported provider names.
     *
     * @return list<string>
     */
    public static function supportedProviders(): array
    {
        return ['ga4', 'meta', 'posthog', 'mixpanel', 'amplitude', 'plausible', 'tiktok', 'linkedin'];
    }
}
