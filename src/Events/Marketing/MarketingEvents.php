<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Marketing;

/**
 * Static catalog of all marketing analytics events.
 *
 * Covers email campaigns, lead generation, content marketing,
 * social media, paid advertising, webinars, SMS, push notifications,
 * affiliate/referral tracking, and marketing attribution.
 *
 * @phpstan-type EventEntry array{name: string, class: class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>, ga4: string, meta: string|null, posthog: string, plausible: string|null, mixpanel: string, amplitude: string, tiktok: string|null, linkedin: string|null}
 *
 * @since 121.0.0
 */
final class MarketingEvents
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
            // ── Email Marketing ───────────────────────────────────────
            'email_sent' => [
                'name' => 'email_sent',
                'class' => EmailSentEvent::class,
                'ga4' => 'email_sent',
                'meta' => null,
                'posthog' => 'email_sent',
                'plausible' => null,
                'mixpanel' => 'Email Sent',
                'amplitude' => 'Email Sent',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'email_delivered' => [
                'name' => 'email_delivered',
                'class' => EmailDeliveredEvent::class,
                'ga4' => 'email_delivered',
                'meta' => null,
                'posthog' => 'email_delivered',
                'plausible' => null,
                'mixpanel' => 'Email Delivered',
                'amplitude' => 'Email Delivered',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'email_opened' => [
                'name' => 'email_opened',
                'class' => EmailOpenedEvent::class,
                'ga4' => 'email_opened',
                'meta' => 'ViewContent',
                'posthog' => 'email_opened',
                'plausible' => null,
                'mixpanel' => 'Email Opened',
                'amplitude' => 'Email Opened',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'email_clicked' => [
                'name' => 'email_clicked',
                'class' => EmailClickedEvent::class,
                'ga4' => 'email_clicked',
                'meta' => 'ViewContent',
                'posthog' => 'email_clicked',
                'plausible' => null,
                'mixpanel' => 'Email Clicked',
                'amplitude' => 'Email Clicked',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'email_bounced' => [
                'name' => 'email_bounced',
                'class' => EmailBouncedEvent::class,
                'ga4' => 'email_bounced',
                'meta' => null,
                'posthog' => 'email_bounced',
                'plausible' => null,
                'mixpanel' => 'Email Bounced',
                'amplitude' => 'Email Bounced',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'email_unsubscribed' => [
                'name' => 'email_unsubscribed',
                'class' => EmailUnsubscribedEvent::class,
                'ga4' => 'email_unsubscribed',
                'meta' => null,
                'posthog' => 'email_unsubscribed',
                'plausible' => null,
                'mixpanel' => 'Email Unsubscribed',
                'amplitude' => 'Email Unsubscribed',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'email_marked_spam' => [
                'name' => 'email_marked_spam',
                'class' => EmailMarkedSpamEvent::class,
                'ga4' => 'email_marked_spam',
                'meta' => null,
                'posthog' => 'email_marked_spam',
                'plausible' => null,
                'mixpanel' => 'Email Marked Spam',
                'amplitude' => 'Email Marked Spam',
                'tiktok' => null,
                'linkedin' => null,
            ],

            // ── Lead Generation ────────────────────────────────────────
            'lead_captured' => [
                'name' => 'lead_captured',
                'class' => LeadCapturedEvent::class,
                'ga4' => 'generate_lead',
                'meta' => 'Lead',
                'posthog' => 'lead_captured',
                'plausible' => null,
                'mixpanel' => 'Lead Captured',
                'amplitude' => 'Lead Captured',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'lead_qualified' => [
                'name' => 'lead_qualified',
                'class' => LeadQualifiedEvent::class,
                'ga4' => 'lead_qualified',
                'meta' => 'Lead',
                'posthog' => 'lead_qualified',
                'plausible' => null,
                'mixpanel' => 'Lead Qualified',
                'amplitude' => 'Lead Qualified',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'lead_score_changed' => [
                'name' => 'lead_score_changed',
                'class' => LeadScoreChangedEvent::class,
                'ga4' => 'lead_score_changed',
                'meta' => null,
                'posthog' => 'lead_score_changed',
                'plausible' => null,
                'mixpanel' => 'Lead Score Changed',
                'amplitude' => 'Lead Score Changed',
                'tiktok' => null,
                'linkedin' => null,
            ],

            // ── Content Marketing ─────────────────────────────────────
            'blog_view' => [
                'name' => 'blog_view',
                'class' => BlogViewEvent::class,
                'ga4' => 'page_view',
                'meta' => 'ViewContent',
                'posthog' => 'blog_view',
                'plausible' => 'pageview',
                'mixpanel' => 'Blog View',
                'amplitude' => 'Blog View',
                'tiktok' => 'ViewContent',
                'linkedin' => null,
            ],
            'content_downloaded' => [
                'name' => 'content_downloaded',
                'class' => ContentDownloadedEvent::class,
                'ga4' => 'file_download',
                'meta' => 'ViewContent',
                'posthog' => 'content_downloaded',
                'plausible' => 'file_download',
                'mixpanel' => 'Content Downloaded',
                'amplitude' => 'Content Downloaded',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'newsletter_subscribed' => [
                'name' => 'newsletter_subscribed',
                'class' => NewsletterSubscribedEvent::class,
                'ga4' => 'newsletter_subscribed',
                'meta' => 'Subscribe',
                'posthog' => 'newsletter_subscribed',
                'plausible' => null,
                'mixpanel' => 'Newsletter Subscribed',
                'amplitude' => 'Newsletter Subscribed',
                'tiktok' => null,
                'linkedin' => null,
            ],

            // ── Social Media ───────────────────────────────────────────
            'social_share' => [
                'name' => 'social_share',
                'class' => SocialShareEvent::class,
                'ga4' => 'share',
                'meta' => 'Share',
                'posthog' => '$share',
                'plausible' => 'share',
                'mixpanel' => 'Social Share',
                'amplitude' => 'Social Share',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'social_follow' => [
                'name' => 'social_follow',
                'class' => SocialFollowEvent::class,
                'ga4' => 'social_follow',
                'meta' => null,
                'posthog' => 'social_follow',
                'plausible' => null,
                'mixpanel' => 'Social Follow',
                'amplitude' => 'Social Follow',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'social_comment' => [
                'name' => 'social_comment',
                'class' => SocialCommentEvent::class,
                'ga4' => 'social_comment',
                'meta' => null,
                'posthog' => 'social_comment',
                'plausible' => null,
                'mixpanel' => 'Social Comment',
                'amplitude' => 'Social Comment',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'social_mention' => [
                'name' => 'social_mention',
                'class' => SocialMentionEvent::class,
                'ga4' => 'social_mention',
                'meta' => null,
                'posthog' => 'social_mention',
                'plausible' => null,
                'mixpanel' => 'Social Mention',
                'amplitude' => 'Social Mention',
                'tiktok' => null,
                'linkedin' => null,
            ],

            // ── Paid Advertising ──────────────────────────────────────
            'ad_impression' => [
                'name' => 'ad_impression',
                'class' => AdImpressionEvent::class,
                'ga4' => 'ad_impression',
                'meta' => 'AdImpression',
                'posthog' => 'ad_impression',
                'plausible' => null,
                'mixpanel' => 'Ad Impression',
                'amplitude' => 'Ad Impression',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'ad_click' => [
                'name' => 'ad_click',
                'class' => AdClickMarketingEvent::class,
                'ga4' => 'ad_click',
                'meta' => 'AdClick',
                'posthog' => 'ad_click',
                'plausible' => null,
                'mixpanel' => 'Ad Click',
                'amplitude' => 'Ad Click',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'ad_conversion' => [
                'name' => 'ad_conversion',
                'class' => AdConversionEvent::class,
                'ga4' => 'conversion',
                'meta' => 'CompleteRegistration',
                'posthog' => 'ad_conversion',
                'plausible' => null,
                'mixpanel' => 'Ad Conversion',
                'amplitude' => 'Ad Conversion',
                'tiktok' => null,
                'linkedin' => null,
            ],

            // ── Webinars & Events ──────────────────────────────────────
            'webinar_registered' => [
                'name' => 'webinar_registered',
                'class' => WebinarRegisteredEvent::class,
                'ga4' => 'webinar_registered',
                'meta' => 'Lead',
                'posthog' => 'webinar_registered',
                'plausible' => null,
                'mixpanel' => 'Webinar Registered',
                'amplitude' => 'Webinar Registered',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'webinar_attended' => [
                'name' => 'webinar_attended',
                'class' => WebinarAttendedEvent::class,
                'ga4' => 'webinar_attended',
                'meta' => null,
                'posthog' => 'webinar_attended',
                'plausible' => null,
                'mixpanel' => 'Webinar Attended',
                'amplitude' => 'Webinar Attended',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'webinar_engagement' => [
                'name' => 'webinar_engagement',
                'class' => WebinarEngagementEvent::class,
                'ga4' => 'webinar_engagement',
                'meta' => null,
                'posthog' => 'webinar_engagement',
                'plausible' => null,
                'mixpanel' => 'Webinar Engagement',
                'amplitude' => 'Webinar Engagement',
                'tiktok' => null,
                'linkedin' => null,
            ],

            // ── SMS & Push ──────────────────────────────────────────────
            'sms_sent' => [
                'name' => 'sms_sent',
                'class' => SmsSentEvent::class,
                'ga4' => 'sms_sent',
                'meta' => null,
                'posthog' => 'sms_sent',
                'plausible' => null,
                'mixpanel' => 'SMS Sent',
                'amplitude' => 'SMS Sent',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'sms_delivered' => [
                'name' => 'sms_delivered',
                'class' => SmsDeliveredEvent::class,
                'ga4' => 'sms_delivered',
                'meta' => null,
                'posthog' => 'sms_delivered',
                'plausible' => null,
                'mixpanel' => 'SMS Delivered',
                'amplitude' => 'SMS Delivered',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'sms_clicked' => [
                'name' => 'sms_clicked',
                'class' => SmsClickedEvent::class,
                'ga4' => 'sms_clicked',
                'meta' => null,
                'posthog' => 'sms_clicked',
                'plausible' => null,
                'mixpanel' => 'SMS Clicked',
                'amplitude' => 'SMS Clicked',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'push_notification_sent' => [
                'name' => 'push_notification_sent',
                'class' => PushNotificationSentEvent::class,
                'ga4' => 'push_sent',
                'meta' => null,
                'posthog' => 'push_notification_sent',
                'plausible' => null,
                'mixpanel' => 'Push Sent',
                'amplitude' => 'Push Sent',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'push_notification_opened' => [
                'name' => 'push_notification_opened',
                'class' => PushNotificationOpenedEvent::class,
                'ga4' => 'push_opened',
                'meta' => null,
                'posthog' => 'push_notification_opened',
                'plausible' => null,
                'mixpanel' => 'Push Opened',
                'amplitude' => 'Push Opened',
                'tiktok' => null,
                'linkedin' => null,
            ],

            // ── Affiliate & Referral ──────────────────────────────────
            'referral_link_shared' => [
                'name' => 'referral_link_shared',
                'class' => ReferralLinkSharedEvent::class,
                'ga4' => 'share',
                'meta' => 'Share',
                'posthog' => 'referral_link_shared',
                'plausible' => null,
                'mixpanel' => 'Referral Shared',
                'amplitude' => 'Referral Shared',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'referral_conversion' => [
                'name' => 'referral_conversion',
                'class' => ReferralConversionEvent::class,
                'ga4' => 'referral_conversion',
                'meta' => 'CompleteRegistration',
                'posthog' => 'referral_conversion',
                'plausible' => null,
                'mixpanel' => 'Referral Conversion',
                'amplitude' => 'Referral Conversion',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'affiliate_signup' => [
                'name' => 'affiliate_signup',
                'class' => AffiliateSignupEvent::class,
                'ga4' => 'affiliate_signup',
                'meta' => 'Lead',
                'posthog' => 'affiliate_signup',
                'plausible' => null,
                'mixpanel' => 'Affiliate Signup',
                'amplitude' => 'Affiliate Signup',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'affiliate_commission' => [
                'name' => 'affiliate_commission',
                'class' => AffiliateCommissionEvent::class,
                'ga4' => 'affiliate_commission',
                'meta' => 'Purchase',
                'posthog' => 'affiliate_commission',
                'plausible' => null,
                'mixpanel' => 'Affiliate Commission',
                'amplitude' => 'Affiliate Commission',
                'tiktok' => null,
                'linkedin' => null,
            ],

            // ── Marketing Attribution ──────────────────────────────────
            'attribution_touchpoint' => [
                'name' => 'attribution_touchpoint',
                'class' => AttributionTouchpointEvent::class,
                'ga4' => 'attribution_touchpoint',
                'meta' => null,
                'posthog' => 'attribution_touchpoint',
                'plausible' => null,
                'mixpanel' => 'Attribution Touchpoint',
                'amplitude' => 'Attribution Touchpoint',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'campaign_response' => [
                'name' => 'campaign_response',
                'class' => CampaignResponseEvent::class,
                'ga4' => 'campaign_response',
                'meta' => 'Lead',
                'posthog' => 'campaign_response',
                'plausible' => null,
                'mixpanel' => 'Campaign Response',
                'amplitude' => 'Campaign Response',
                'tiktok' => null,
                'linkedin' => null,
            ],
        ];

        return self::$catalog;
    }

    /**
     * Get all marketing event names.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::catalog());
    }

    /**
     * Get all marketing event entries.
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
     * Get the total number of marketing events.
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
        return 'marketing';
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
}
