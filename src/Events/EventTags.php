<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events;

/**
 * Tag registry for event classification.
 *
 * Provides a tag-based classification system for analytics events.
 * Tags allow consumers to query events by semantic purpose rather than
 * just category or name. Multiple tags can be applied to a single event.
 *
 * Available tags:
 *   - revenue:       Events that directly impact revenue tracking
 *   - pii:           Events that may contain personally identifiable information
 *   - critical:      Business-critical events that should never be sampled/dropped
 *   - conversion:    Events representing conversion milestones
 *   - retention:     Events indicating retention health or churn risk
 *   - engagement:    Events measuring user engagement depth
 *   - acquisition:   Events related to user acquisition channels
 *   - authentication: Events related to user auth lifecycle
 *   - billing:       Events related to payment and billing lifecycle
 *   - gdpr:          Events required for GDPR compliance tracking
 *   - privacy_safe:  Events safe to track without collecting any PII
 *   - samplable:     Events safe for probabilistic sampling
 *   - funnel:        Events that form conversion funnels
 *   - b2b:           Events related to B2B/team collaboration
 *   - onboarding:    Events related to user onboarding flow
 *   - ecommerce:     Events related to e-commerce transactions
 *   - plg:           Events measuring product-led growth signals
 *   - enterprise:    Events needed for enterprise compliance tracking
 *   - performance:   Events measuring client-side performance
 *   - session:       Events related to session lifecycle
 *   - consent:       Events related to GDPR consent lifecycle
 *   - cohort:        Events related to cohort analytics
 *
 * @since 98.0.0
 */
final class EventTags
{
    /**
     * Complete event → tags mapping.
     *
     * @var array<string, list<string>>
     */
    private const TAG_MAP = [
        // ── E-commerce ─────────────────────────────────────
        'view_item' => ['ecommerce', 'conversion', 'funnel', 'engagement'],
        'add_to_cart' => ['ecommerce', 'revenue', 'conversion', 'funnel', 'critical'],
        'remove_from_cart' => ['ecommerce', 'revenue', 'funnel'],
        'view_cart' => ['ecommerce', 'revenue', 'funnel'],
        'begin_checkout' => ['ecommerce', 'revenue', 'conversion', 'funnel', 'critical'],
        'add_payment_info' => ['ecommerce', 'revenue', 'pii', 'conversion', 'funnel', 'critical'],
        'purchase' => ['ecommerce', 'revenue', 'conversion', 'critical', 'pii'],
        'refund' => ['ecommerce', 'revenue', 'critical'],
        'add_to_wishlist' => ['ecommerce', 'engagement', 'conversion'],
        'select_item' => ['ecommerce', 'engagement', 'funnel'],
        'select_promotion' => ['ecommerce', 'engagement'],
        'view_promotion' => ['ecommerce', 'engagement'],
        'checkout_step' => ['ecommerce', 'funnel'],
        'abandoned_cart' => ['ecommerce', 'revenue', 'retention', 'funnel'],
        'checkout_abandon' => ['ecommerce', 'revenue', 'retention', 'funnel'],

        // ── SaaS Core ──────────────────────────────────────
        'sign_up' => ['acquisition', 'conversion', 'pii', 'critical', 'gdpr', 'funnel', 'onboarding'],
        'login' => ['authentication', 'pii', 'critical', 'gdpr', 'retention', 'session'],
        'logout' => ['authentication', 'pii', 'gdpr', 'session'],
        'start_trial' => ['conversion', 'funnel', 'onboarding'],
        'trial_end' => ['retention', 'conversion', 'funnel'],
        'subscribe' => ['revenue', 'conversion', 'billing', 'critical', 'funnel'],
        'plan_upgrade' => ['revenue', 'conversion', 'billing', 'critical', 'plg'],
        'plan_downgrade' => ['revenue', 'billing', 'retention'],
        'cancellation' => ['retention', 'revenue', 'billing', 'critical'],
        'feature_used' => ['engagement', 'retention', 'onboarding', 'plg'],
        'revenue_tracked' => ['revenue', 'critical'],

        // ── SaaS Account ──────────────────────────────────
        'account_activated' => ['authentication', 'gdpr', 'onboarding'],
        'account_deactivated' => ['authentication', 'gdpr', 'retention'],
        'password_changed' => ['authentication', 'pii', 'gdpr', 'security'],
        'password_reset' => ['authentication', 'pii', 'gdpr', 'security'],
        'profile_updated' => ['pii', 'gdpr', 'engagement'],
        'email_verified' => ['authentication', 'gdpr', 'onboarding', 'funnel'],

        // ── SaaS Teams ────────────────────────────────────
        'team_created' => ['b2b', 'plg', 'acquisition'],
        'team_member_joined' => ['b2b', 'plg', 'retention'],
        'team_member_removed' => ['b2b', 'gdpr'],
        'role_changed' => ['b2b', 'gdpr', 'enterprise'],
        'invite_sent' => ['b2b', 'acquisition', 'plg'],

        // ── SaaS Billing ──────────────────────────────────
        'payment_succeeded' => ['billing', 'revenue', 'pii', 'critical'],
        'payment_failed' => ['billing', 'revenue', 'retention', 'critical'],
        'payment_method_added' => ['billing', 'pii', 'gdpr'],
        'payment_method_updated' => ['billing', 'pii', 'gdpr'],
        'invoice_generated' => ['billing', 'revenue', 'gdpr'],
        'credit_applied' => ['billing', 'revenue'],
        'subscription_renewal' => ['billing', 'revenue', 'retention'],
        'billing_retry' => ['billing', 'revenue', 'retention', 'critical'],

        // ── SaaS Growth ───────────────────────────────────
        'milestone_reached' => ['engagement', 'retention', 'onboarding', 'plg'],
        'feature_adopted' => ['engagement', 'plg', 'retention'],
        'expansion_revenue' => ['revenue', 'plg', 'critical'],
        'workspace_created' => ['b2b', 'acquisition', 'plg'],
        'integration_connected' => ['engagement', 'retention', 'plg'],
        'integration_failed' => ['retention'],
        'growth_milestone' => ['engagement', 'retention', 'plg'],
        'usage_quota_reached' => ['retention', 'billing'],
        'feature_limit_reached' => ['retention', 'billing'],

        // ── SaaS Compliance ───────────────────────────────
        'data_subject_access_request' => ['gdpr', 'enterprise', 'pii'],
        'data_erasure_completed' => ['gdpr', 'enterprise', 'pii'],

        // ── SaaS Subscription Lifecycle ────────────────────
        'subscription_created' => ['billing', 'revenue', 'conversion', 'funnel'],
        'subscription_cancelled' => ['billing', 'revenue', 'retention', 'critical'],
        'subscription_paused' => ['billing', 'retention'],
        'subscription_resumed' => ['billing', 'revenue', 'retention'],
        'subscription_value_changed' => ['billing', 'revenue', 'critical'],
        'plan_changed' => ['billing', 'revenue'],
        'trial_converted' => ['conversion', 'revenue', 'funnel', 'critical', 'onboarding'],
        'trial_expired' => ['retention', 'funnel'],

        // ── Engagement ─────────────────────────────────────
        'page_view' => ['engagement', 'privacy_safe', 'samplable', 'session', 'funnel'],
        'scroll_depth' => ['engagement', 'privacy_safe', 'samplable'],
        'click' => ['engagement', 'privacy_safe', 'samplable'],
        'form_start' => ['engagement', 'conversion', 'funnel'],
        'form_submit' => ['engagement', 'conversion', 'funnel', 'pii'],
        'search' => ['engagement', 'privacy_safe', 'retention'],
        'share' => ['engagement', 'acquisition', 'plg'],
        'error' => ['performance', 'enterprise'],
        'time_on_page' => ['engagement', 'privacy_safe', 'samplable', 'retention'],
        'session_start' => ['session', 'privacy_safe', 'samplable'],
        'session_end' => ['session', 'privacy_safe', 'samplable'],
        'content_engagement' => ['engagement', 'retention', 'privacy_safe'],
        'notification' => ['engagement', 'privacy_safe'],
        'outbound_click' => ['engagement', 'privacy_safe', 'samplable'],
        'file_download' => ['engagement', 'privacy_safe', 'samplable'],
        'video_play' => ['engagement', 'privacy_safe', 'samplable'],
        'onboarding_step' => ['engagement', 'onboarding', 'funnel'],
        'onboarding_completed' => ['engagement', 'onboarding', 'conversion', 'funnel'],
        'feature_request' => ['engagement', 'acquisition'],
        'feedback' => ['engagement', 'retention'],
        'goal_conversion' => ['conversion', 'funnel', 'engagement'],
        'screen_view' => ['engagement', 'privacy_safe', 'samplable'],
        'ab_test_exposure' => ['engagement', 'samplable'],
        'ad_click' => ['acquisition', 'engagement', 'samplable'],
        'campaign_attribution' => ['acquisition', 'engagement'],
        'web_vitals' => ['performance', 'privacy_safe'],
        'js_error' => ['performance', 'enterprise'],
        'client_error' => ['performance', 'enterprise'],
        'timing' => ['performance', 'privacy_safe'],
        'element_visibility' => ['engagement', 'privacy_safe', 'samplable'],
        'copy_text' => ['engagement', 'privacy_safe', 'samplable'],
        'hover' => ['engagement', 'privacy_safe', 'samplable'],
        'performance_score' => ['performance', 'privacy_safe'],

        // ── Consent ─────────────────────────────────────────
        'consent_granted' => ['consent', 'gdpr', 'pii'],
        'consent_withdrawn' => ['consent', 'gdpr', 'pii'],

        // ── Cohort ─────────────────────────────────────────
        'cohort_assigned' => ['cohort'],
        'cohort_retention' => ['cohort', 'retention'],
        'cohort_churn' => ['cohort', 'retention'],
        'cohort_conversion' => ['cohort', 'conversion'],
        'cohort_migration' => ['cohort'],
        'cohort_engagement' => ['cohort', 'engagement'],
        'retention_cohort' => ['cohort', 'retention'],
        'retention_risk' => ['retention', 'critical'],

        // ── Data Portability ─────────────────────────────
        'export' => ['gdpr', 'enterprise', 'pii'],
        'import' => ['gdpr', 'enterprise', 'pii'],

        // ── Misc ──────────────────────────────────────────
        'export_event' => ['gdpr', 'enterprise'],
        'api_rate_limited' => ['enterprise'],
        'sla_breach' => ['enterprise', 'critical'],
        'webhook_delivered' => ['enterprise'],
    ];

    /**
     * Get all tags for a given event name.
     *
     * @return list<string>
     */
    public static function for(string $eventName): array
    {
        return self::TAG_MAP[$eventName] ?? [];
    }

    /**
     * Check if an event has a specific tag.
     */
    public static function has(string $eventName, string $tag): bool
    {
        return in_array($tag, self::TAG_MAP[$eventName] ?? [], true);
    }

    /**
     * Get all event names that have a specific tag.
     *
     * @return list<string>
     */
    public static function tagged(string $tag): array
    {
        $events = [];

        foreach (self::TAG_MAP as $eventName => $tags) {
            if (in_array($tag, $tags, true)) {
                $events[] = $eventName;
            }
        }

        return $events;
    }

    /**
     * Get all available tags.
     *
     * @return list<string>
     */
    public static function allTags(): array
    {
        $tags = [];

        foreach (self::TAG_MAP as $eventTags) {
            foreach ($eventTags as $tag) {
                $tags[$tag] = true;
            }
        }

        return array_keys($tags);
        // Note: array_keys preserves insertion order, returning unique tags
    }

    /**
     * Get events grouped by tag.
     *
     * Returns a mapping of tag → list of event names.
     *
     * @return array<string, list<string>>
     */
    public static function groupedByTag(): array
    {
        $grouped = [];

        foreach (self::TAG_MAP as $eventName => $tags) {
            foreach ($tags as $tag) {
                $grouped[$tag][] = $eventName;
            }
        }

        // Sort each group alphabetically
        foreach ($grouped as $tag => $events) {
            sort($grouped[$tag]);
        }

        return $grouped;
    }

    /**
     * Get the complete event → tags mapping.
     *
     * @return array<string, list<string>>
     */
    public static function map(): array
    {
        return self::TAG_MAP;
    }

    /**
     * Add a tag to an event at runtime.
     *
     * Note: This does not persist. For permanent tags, update the TAG_MAP constant.
     * This is useful for plugin events or runtime-registered custom events.
     */
    public static function addTag(string $eventName, string $tag): void
    {
        if (! isset(self::TAG_MAP[$eventName])) {
            self::TAG_MAP[$eventName] = [];
        }

        if (! in_array($tag, self::TAG_MAP[$eventName], true)) {
            self::TAG_MAP[$eventName][] = $tag;
        }
    }

    /**
     * Get tag statistics (tag → event count).
     *
     * @return array<string, int>
     */
    public static function stats(): array
    {
        $stats = [];

        foreach (self::TAG_MAP as $tags) {
            foreach ($tags as $tag) {
                $stats[$tag] = ($stats[$tag] ?? 0) + 1;
            }
        }

        return $stats;
    }

    /**
     * Get the number of tags applied to an event.
     */
    public static function tagCount(string $eventName): int
    {
        return count(self::TAG_MAP[$eventName] ?? []);
    }

    /**
     * Get events that match ALL given tags (AND logic).
     *
     * @param  list<string>  $tags
     * @return list<string>
     */
    public static function whereAll(array $tags): array
    {
        if ($tags === []) {
            return [];
        }

        $results = [];

        foreach (self::TAG_MAP as $eventName => $eventTags) {
            $matches = true;
            foreach ($tags as $tag) {
                if (! in_array($tag, $eventTags, true)) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                $results[] = $eventName;
            }
        }

        return $results;
    }

    /**
     * Get events that match ANY given tag (OR logic).
     *
     * @param  list<string>  $tags
     * @return list<string>
     */
    public static function whereAny(array $tags): array
    {
        if ($tags === []) {
            return [];
        }

        $results = [];

        foreach (self::TAG_MAP as $eventName => $eventTags) {
            foreach ($tags as $tag) {
                if (in_array($tag, $eventTags, true)) {
                    $results[] = $eventName;
                    break;
                }
            }
        }

        return array_values(array_unique($results));
    }
}
