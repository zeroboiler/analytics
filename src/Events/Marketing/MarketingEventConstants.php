<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Marketing;

/**
 * Marketing analytics event name constants for IDE autocompletion and type safety.
 *
 * Use these constants instead of raw strings to prevent typos and enable
 * IDE "find usages" / refactoring support when tracking marketing events.
 *
 * @since 121.0.0
 *
 * @see \ZeroBoiler\Analytics\Events\Marketing\MarketingEvents
 */
final class MarketingEventConstants
{
    // ── Email Marketing ──────────────────────────────────────────────
    /** @var string Marketing email sent to recipient */
    public const EMAIL_SENT = 'email_sent';
    /** @var string Marketing email delivered to inbox */
    public const EMAIL_DELIVERED = 'email_delivered';
    /** @var string Marketing email opened by recipient */
    public const EMAIL_OPENED = 'email_opened';
    /** @var string Recipient clicked link in email */
    public const EMAIL_CLICKED = 'email_clicked';
    /** @var string Marketing email bounced */
    public const EMAIL_BOUNCED = 'email_bounced';
    /** @var string Recipient unsubscribed from emails */
    public const EMAIL_UNSUBSCRIBED = 'email_unsubscribed';
    /** @var string Recipient marked email as spam */
    public const EMAIL_MARKED_SPAM = 'email_marked_spam';

    // ── Lead Generation ──────────────────────────────────────────────
    /** @var string New lead captured */
    public const LEAD_CAPTURED = 'lead_captured';
    /** @var string Lead qualified (sales-accepted) */
    public const LEAD_QUALIFIED = 'lead_qualified';
    /** @var string Lead score changed */
    public const LEAD_SCORE_CHANGED = 'lead_score_changed';

    // ── Content Marketing ──────────────────────────────────────────
    /** @var string Blog article viewed */
    public const BLOG_VIEW = 'blog_view';
    /** @var string Content asset downloaded */
    public const CONTENT_DOWNLOADED = 'content_downloaded';
    /** @var string Newsletter subscription */
    public const NEWSLETTER_SUBSCRIBED = 'newsletter_subscribed';

    // ── Social Media ────────────────────────────────────────────────
    /** @var string Content shared on social media */
    public const SOCIAL_SHARE = 'social_share';
    /** @var string User followed brand account */
    public const SOCIAL_FOLLOW = 'social_follow';
    /** @var string User commented on social post */
    public const SOCIAL_COMMENT = 'social_comment';
    /** @var string Brand mentioned on social */
    public const SOCIAL_MENTION = 'social_mention';

    // ── Paid Advertising ────────────────────────────────────────────
    /** @var string Ad impression served */
    public const AD_IMPRESSION = 'ad_impression';
    /** @var string Ad clicked */
    public const AD_CLICK = 'ad_click';
    /** @var string Ad conversion completed */
    public const AD_CONVERSION = 'ad_conversion';

    // ── Webinars & Events ──────────────────────────────────────────
    /** @var string User registered for webinar */
    public const WEBINAR_REGISTERED = 'webinar_registered';
    /** @var string User attended webinar */
    public const WEBINAR_ATTENDED = 'webinar_attended';
    /** @var string User engaged with webinar content */
    public const WEBINAR_ENGAGEMENT = 'webinar_engagement';

    // ── SMS & Push ───────────────────────────────────────────────────
    /** @var string SMS sent to recipient */
    public const SMS_SENT = 'sms_sent';
    /** @var string SMS delivered to handset */
    public const SMS_DELIVERED = 'sms_delivered';
    /** @var string Recipient clicked SMS link */
    public const SMS_CLICKED = 'sms_clicked';
    /** @var string Push notification sent */
    public const PUSH_NOTIFICATION_SENT = 'push_notification_sent';
    /** @var string Push notification opened */
    public const PUSH_NOTIFICATION_OPENED = 'push_notification_opened';

    // ── Affiliate & Referral ────────────────────────────────────────
    /** @var string Referral link shared */
    public const REFERRAL_LINK_SHARED = 'referral_link_shared';
    /** @var string Referral converted to user */
    public const REFERRAL_CONVERSION = 'referral_conversion';
    /** @var string New affiliate signed up */
    public const AFFILIATE_SIGNUP = 'affiliate_signup';
    /** @var string Affiliate commission earned */
    public const AFFILIATE_COMMISSION = 'affiliate_commission';

    // ── Attribution ──────────────────────────────────────────────────
    /** @var string Attribution touchpoint recorded */
    public const ATTRIBUTION_TOUCHPOINT = 'attribution_touchpoint';
    /** @var string Campaign response received */
    public const CAMPAIGN_RESPONSE = 'campaign_response';

    /**
     * Get all marketing event name constants as an associative array.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return (new \ReflectionClass(self::class))->getConstants();
    }

    /**
     * Get all marketing event name constants as a list.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_values(self::all());
    }

    /**
     * Check if a given event name is a valid marketing event constant.
     */
    public static function isValid(string $name): bool
    {
        return in_array($name, self::all(), true);
    }

    /**
     * Get the total number of marketing event constants.
     */
    public static function count(): int
    {
        return count(self::all());
    }
}
