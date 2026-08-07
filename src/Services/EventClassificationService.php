<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

/**
 * Classifies analytics events by revenue impact tier.
 *
 * Provides a structured way to categorize events into four tiers:
 * - **critical**: Directly tied to revenue (purchase, refund, subscription, payment)
 * - **monetization**: High-value events in the revenue funnel (trial_start, plan_upgrade, checkout)
 * - **engagement**: User interaction events that signal product-market fit (page_view, feature_used, form_submit)
 * - **operational**: Infrastructure/auth events that don't directly indicate engagement (login, logout, session)
 *
 * Classification drives:
 * - Priority gate overrides (critical events always pass through)
 * - Event routing rules (revenue events → GA4 + Meta, not Plausible)
 * - Performance budget allocation (operational events can be dropped first)
 * - Alert thresholds (critical events trigger elevated alerts)
 *
 * @see \ZeroBoiler\Analytics\Services\EventPriorityGate
 * @see \ZeroBoiler\Analytics\Support\EcommerceFormatConverter
 */
final class EventClassificationService
{
    /** Events that directly represent revenue transactions. */
    private const CRITICAL_EVENTS = [
        'purchase', 'refund', 'subscription', 'payment_succeeded', 'payment_failed',
        'revenue_tracked', 'subscription_renewal', 'invoice_generated', 'credit_applied',
    ];

    /** Events in the monetization funnel — high-value conversion signals. */
    private const MONETIZATION_EVENTS = [
        'sign_up', 'trial_start', 'trial_end', 'plan_upgrade', 'plan_downgrade',
        'cancellation', 'begin_checkout', 'add_payment_info', 'add_to_cart',
        'add_to_wishlist', 'view_cart', 'view_item', 'select_item',
        'select_promotion', 'view_promotion', 'feature_limit_reached',
    ];

    /** User engagement events indicating active product usage. */
    private const ENGAGEMENT_EVENTS = [
        'page_view', 'screen_view', 'scroll_depth', 'click', 'outbound_click',
        'form_start', 'form_submit', 'search', 'share', 'file_download',
        'video_play', 'feature_used', 'content_engagement', 'web_vitals',
        'notification', 'campaign_attribution', 'ab_test_exposure',
        'time_on_page', 'timing', 'onboarding_step',
    ];

    /** Operational/auth events — necessary but not engagement signals. */
    private const OPERATIONAL_EVENTS = [
        'login', 'logout', 'session_start', 'session_end', 'session_heartbeat',
        'account_activated', 'account_deactivated', 'password_changed',
        'password_reset', 'profile_updated', 'email_verified', 'role_changed',
        'team_created', 'team_member_joined', 'team_member_removed',
        'invite_sent', 'integration_connected', 'integration_failed',
        'cohort_assigned', 'cohort_retention', 'cohort_churn',
        'cohort_conversion', 'cohort_migration', 'cohort_engagement',
    ];

    /** Tier constants for use in comparisons and config. */
    public const TIER_CRITICAL = 'critical';
    public const TIER_MONETIZATION = 'monetization';
    public const TIER_ENGAGEMENT = 'engagement';
    public const TIER_OPERATIONAL = 'operational';

    /**
     * Built-in classification overrides for event names that don't follow the
     * default category-based rules. Custom overrides are merged on top.
     *
     * @var array<string, string>
     */
    private array $customOverrides;

    /**
     * @param  array<string, string>  $customOverrides  Additional event → tier mappings
     */
    public function __construct(array $customOverrides = []): void
    {
        $this->customOverrides = $customOverrides;
    }

    /**
     * Classify an event by name into a revenue impact tier.
     *
     * @param  string  $eventName  The analytics event name (snake_case)
     * @return self::TIER_*  One of: critical, monetization, engagement, operational
     */
    public function classify(string $eventName): string
    {
        $normalizedName = strtolower(trim($eventName));

        // Custom overrides take precedence
        if (isset($this->customOverrides[$normalizedName])) {
            return $this->customOverrides[$normalizedName];
        }

        if (in_array($normalizedName, self::CRITICAL_EVENTS, true)) {
            return self::TIER_CRITICAL;
        }

        if (in_array($normalizedName, self::MONETIZATION_EVENTS, true)) {
            return self::TIER_MONETIZATION;
        }

        if (in_array($normalizedName, self::ENGAGEMENT_EVENTS, true)) {
            return self::TIER_ENGAGEMENT;
        }

        if (in_array($normalizedName, self::OPERATIONAL_EVENTS, true)) {
            return self::TIER_OPERATIONAL;
        }

        // Revenue-containing events default to monetization
        if (str_contains($normalizedName, 'revenue') || str_contains($normalizedName, 'payment')) {
            return self::TIER_MONETIZATION;
        }

        // Error events default to operational
        if (str_contains($normalizedName, 'error') || str_contains($normalizedName, 'exception')) {
            return self::TIER_OPERATIONAL;
        }

        // Default: engagement for unknown events
        return self::TIER_ENGAGEMENT;
    }

    /**
     * Check if an event is in a specific tier.
     *
     * @param  string  $eventName  Event name
     * @param  string  $tier  One of: critical, monetization, engagement, operational
     */
    public function isTier(string $eventName, string $tier): bool
    {
        return $this->classify($eventName) === $tier;
    }

    /**
     * Check if an event has direct revenue impact (critical or monetization tier).
     *
     * Useful for routing decisions — revenue-impacting events should always
     * be dispatched to GA4 and Meta even when sampling is active.
     */
    public function isRevenueImpacting(string $eventName): bool
    {
        $tier = $this->classify($eventName);

        return $tier === self::TIER_CRITICAL || $tier === self::TIER_MONETIZATION;
    }

    /**
     * Check if an event is droppable under load.
     *
     * Operational and engagement events can be safely dropped during
     * traffic spikes without impacting revenue measurement.
     */
    public function isDroppable(string $eventName): bool
    {
        return ! $this->isRevenueImpacting($eventName);
    }

    /**
     * Classify a batch of events and group them by tier.
     *
     * @param  list<string>  $eventNames  List of event names to classify
     * @return array{critical: list<string>, monetization: list<string>, engagement: list<string>, operational: list<string>}
     */
    public function classifyBatch(array $eventNames): array
    {
        $tiers = [
            self::TIER_CRITICAL => [],
            self::TIER_MONETIZATION => [],
            self::TIER_ENGAGEMENT => [],
            self::TIER_OPERATIONAL => [],
        ];

        foreach ($eventNames as $name) {
            $tier = $this->classify($name);
            $tiers[$tier][] = $name;
        }

        return $tiers;
    }

    /**
     * Get all events classified in a specific tier.
     *
     * Includes built-in events merged with any custom overrides that match.
     *
     * @return list<string>
     */
    public function getEventsInTier(string $tier): array
    {
        $allEvents = array_merge(
            self::CRITICAL_EVENTS,
            self::MONETIZATION_EVENTS,
            self::ENGAGEMENT_EVENTS,
            self::OPERATIONAL_EVENTS,
        );

        $result = [];

        foreach ($allEvents as $event) {
            if ($this->classify($event) === $tier) {
                $result[] = $event;
            }
        }

        // Include custom overrides that match this tier
        foreach ($this->customOverrides as $event => $eventTier) {
            if ($eventTier === $tier && ! in_array($event, $result, true)) {
                $result[] = $event;
            }
        }

        return $result;
    }

    /**
     * Get the tier priority weight (higher = more important).
     *
     * @return int 4=critical, 3=monetization, 2=engagement, 1=operational
     */
    public function tierWeight(string $tier): int
    {
        return match ($tier) {
            self::TIER_CRITICAL => 4,
            self::TIER_MONETIZATION => 3,
            self::TIER_ENGAGEMENT => 2,
            self::TIER_OPERATIONAL => 1,
            default => 0,
        };
    }

    /**
     * Map classification tiers to event priority gate levels.
     *
     * Provides the bridge between event classification and the
     * priority-aware dispatch pipeline.
     *
     * @return array{critical: 'critical', monetization: 'normal', engagement: 'low', operational: 'background'}
     */
    public static function tierToPriorityMap(): array
    {
        return [
            self::TIER_CRITICAL => 'critical',
            self::TIER_MONETIZATION => 'normal',
            self::TIER_ENGAGEMENT => 'low',
            self::TIER_OPERATIONAL => 'background',
        ];
    }

    /**
     * Get the dispatch priority level for an event based on its classification tier.
     */
    public function getDispatchPriority(string $eventName): string
    {
        $tier = $this->classify($eventName);

        return self::tierToPriorityMap()[$tier] ?? 'normal';
    }
}
