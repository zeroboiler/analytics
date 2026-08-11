<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event transformer for cross-provider format conversion.
 *
 * Centralizes all event name and parameter transformations between
 * GA4, Meta Pixel, PostHog, Plausible, Mixpanel, Amplitude, and generic formats.
 * Provides both individual transforms and bulk conversion helpers.
 *
 * @since 1.0.0
 */
final class EventTransformer
{
    // ── GA4 ↔ Meta Pixel Event Name Mapping ────────────────────────────

    /**
     * Map GA4 e-commerce event names to Meta Pixel equivalents.
     *
     * @return array<string, string|null>
     */
    private static function ga4ToMetaEventMap(): array
    {
        return [
            'view_item' => 'ViewContent',
            'add_to_cart' => 'AddToCart',
            'remove_from_cart' => 'RemoveFromCart',
            'view_cart' => 'ViewCart',
            'begin_checkout' => 'InitiateCheckout',
            'add_payment_info' => 'AddPaymentInfo',
            'purchase' => 'Purchase',
            'refund' => 'Refund',
            'add_to_wishlist' => 'AddToWishlist',
            'select_item' => 'ViewItem',
            'select_promotion' => 'ViewContent',
            'view_promotion' => 'ViewContent',
        ];
    }

    /**
     * Get the Meta Pixel event name for a GA4 event.
     */
    public static function ga4ToMetaEventName(string $ga4Event): ?string
    {
        return self::ga4ToMetaEventMap()[$ga4Event] ?? null;
    }

    /**
     * Check if a GA4 event has a Meta Pixel equivalent.
     */
    public static function hasMetaEquivalent(string $ga4Event): bool
    {
        return self::ga4ToMetaEventName($ga4Event) !== null;
    }

    // ── GA4 ↔ Meta Pixel Parameter Conversion ───────────────────────────

    /**
     * Convert GA4 items array to Meta Pixel contents format.
     *
     * @param  array<int, array<string, mixed>>  $items  GA4-format items
     * @return array{content_ids: list<string>, contents: array<int, array<string, mixed>>, num_items: int}
     */
    public static function ga4ItemsToMetaContents(array $items): array
    {
        $contentIds = [];
        $contents = [];

        foreach ($items as $item) {
            $contentIds[] = (string) ($item['item_id'] ?? '');
            $contents[] = [
                'id' => (string) ($item['item_id'] ?? ''),
                'quantity' => (int) ($item['quantity'] ?? 1),
                'item_price' => (float) ($item['price'] ?? 0),
                'name' => (string) ($item['item_name'] ?? ''),
                'category' => (string) ($item['item_category'] ?? ''),
            ];
        }

        return [
            'content_ids' => $contentIds,
            'contents' => $contents,
            'num_items' => array_sum(array_column($contents, 'quantity')),
        ];
    }

    /**
     * Convert GA4 event data to Meta Pixel parameters.
     *
     * @param  string  $ga4Event  GA4 event name
     * @param  array<string, mixed>  $data  GA4 event parameters
     * @return array<string, mixed>  Meta Pixel formatted parameters
     */
    public static function ga4ToMetaParams(string $ga4Event, array $data): array
    {
        $params = [
            'value' => $data['value'] ?? null,
            'currency' => $data['currency'] ?? 'USD',
        ];

        if (isset($data['items']) && is_array($data['items'])) {
            $metaItems = self::ga4ItemsToMetaContents($data['items']);
            $params['contents'] = $metaItems['contents'];
            $params['content_ids'] = $metaItems['content_ids'];
            $params['num_items'] = $metaItems['num_items'];
        }

        if ($ga4Event === 'purchase' && isset($data['transaction_id'])) {
            $params['content_ids'] ??= array_column($data['items'] ?? [], 'item_id');
        }

        if (isset($data['content_name'])) {
            $params['content_name'] = $data['content_name'];
        }

        if (isset($data['content_type'])) {
            $params['content_type'] = $data['content_type'];
        }

        return array_filter($params, fn (mixed $v): bool => $v !== null);
    }

    // ── SaaS Event → Provider Format Conversion ─────────────────────────

    /**
     * Map SaaS event names to PostHog event names.
     *
     * PostHog uses $prefix for special events.
     *
     * @return array<string, string>
     */
    public static function saasToPosthogEventMap(): array
    {
        return [
            'sign_up' => '$signup',
            'login' => '$identify',
            'logout' => 'logout',
            'start_trial' => 'start_trial',
            'cancellation' => 'cancellation',
            'subscribe' => 'subscription_created',
            'subscription_renewal' => 'subscription_renewed',
            'plan_upgrade' => 'plan_upgraded',
            'plan_downgrade' => 'plan_downgraded',
            'trial_end' => 'trial_ended',
            'feature_used' => 'feature_used',
            'revenue_tracked' => 'revenue_tracked',
            'cohort_assigned' => 'cohort_assigned',
            'cohort_retention' => 'cohort_retention',
            'cohort_churn' => 'cohort_churn',
            'cohort_conversion' => 'cohort_conversion',
            'cohort_migration' => 'cohort_migration',
            'cohort_engagement' => 'cohort_engagement',
            // Engagement (PostHog reserved)
            'page_view' => '$pageview',
            'session_start' => '$session_start',
            'screen_view' => '$screenview',
            'share' => '$share',
            'error' => '$error',
            'form_submit' => 'form_submitted',
            'search' => '$search',
            'js_error' => '$exception',
            // Account lifecycle events
            'account_activated' => 'account_activated',
            'account_deactivated' => 'account_deactivated',
            'password_changed' => 'password_changed',
            'password_reset' => 'password_reset',
            'profile_updated' => 'profile_updated',
            'email_verified' => 'email_verified',
            // B2B / Team events
            'team_created' => 'team_created',
            'team_member_joined' => 'team_member_joined',
            'team_member_removed' => 'team_member_removed',
            'role_changed' => 'role_changed',
            // Billing events
            'payment_failed' => 'payment_failed',
            'payment_succeeded' => 'payment_succeeded',
            'payment_method_added' => 'payment_method_added',
            'invoice_generated' => 'invoice_generated',
            'credit_applied' => 'credit_applied',
            // Additional SaaS events
            'invite_sent' => 'invite_sent',
            'integration_connected' => 'integration_connected',
            'integration_failed' => 'integration_failed',
            'feature_limit_reached' => 'feature_limit_reached',
        ];
    }

    // ── Bulk Transform ───────────────────────────────────────────────────

    /**
     * Map event names to Plausible-compatible custom event names.
     *
     * Plausible uses pageview by default for navigation. Custom events
     * are sent as-is but some events are not supported or need renaming.
     * Null = not supported by Plausible (event will be skipped).
     *
     * @return array<string, string|null>
     */
    public static function toPlausibleEventMap(): array
    {
        return [
            // Navigation
            'page_view' => 'pageview',
            'screen_view' => null,           // Use pageview instead

            // Engagement (not natively tracked by Plausible)
            'scroll_depth' => null,
            'click' => null,
            'outbound_click' => null,
            'session_start' => null,
            'session_end' => null,
            'session_heartbeat' => null,
            'time_on_page' => null,
            'timing' => null,

            // Forms (not natively tracked)
            'form_start' => null,
            'form_submit' => null,

            // Performance (not applicable)
            'web_vitals' => null,
            'js_error' => null,
            'error' => null,

            // SaaS events — supported as custom events in Plausible
            'sign_up' => 'signup',
            'login' => 'login',
            'logout' => 'logout',
            'start_trial' => 'trial_start',
            'trial_end' => 'trial_end',
            'subscribe' => 'subscription',
            'subscription_renewal' => 'subscription_renewal',
            'plan_upgrade' => 'plan_upgrade',
            'plan_downgrade' => 'plan_downgrade',
            'cancellation' => 'cancellation',
            'feature_used' => 'feature_used',
            'revenue_tracked' => 'revenue',

            // E-commerce — all supported as custom events
            'view_item' => null,             // Tracked via pageview
            'add_to_cart' => 'add_to_cart',
            'remove_from_cart' => null,
            'view_cart' => null,
            'begin_checkout' => 'begin_checkout',
            'add_payment_info' => null,
            'purchase' => 'purchase',
            'refund' => 'refund',
            'add_to_wishlist' => null,
            'select_item' => null,
            'select_promotion' => null,
            'view_promotion' => null,

            // Cohort events
            'cohort_assigned' => null,
            'cohort_retention' => null,
            'cohort_churn' => null,
            'cohort_conversion' => null,
            'cohort_migration' => null,
            'cohort_engagement' => null,

            // Content engagement — supported
            'search' => 'search',
            'share' => 'share',
            'file_download' => 'file_download',
            'video_play' => 'video_play',
            'notification' => null,
            'campaign_attribution' => null,
            'ab_test_exposure' => null,
        ];
    }

    /**
     * Transform an event name for Plausible.
     *
     * @return string|null Transformed event name or null if not applicable for Plausible
     */
    public static function toPlausibleEventName(string $eventName): ?string
    {
        return self::toPlausibleEventMap()[$eventName] ?? $eventName;
    }

    /**
     * Transform an event for a specific provider.
     *
     * @param  AnalyticsEvent  $event  Original event
     * @param  'ga4'|'meta'|'posthog'|'plausible'|'mixpanel'|'amplitude'  $provider  Target provider
     * @return AnalyticsEvent  Transformed event
     */
    public static function transformForProvider(AnalyticsEvent $event, string $provider): AnalyticsEvent
    {
        $name = $event->name;
        $params = $event->params;

        return match ($provider) {
            'meta' => self::transformForMeta($name, $params, $event),
            'posthog' => self::transformForPosthog($name, $params, $event),
            'plausible' => self::transformForPlausible($name, $params, $event),
            'mixpanel' => self::transformForMixpanel($name, $params, $event),
            'amplitude' => self::transformForAmplitude($name, $params, $event),
            default => $event, // ga4, webhook use original format
        };
    }

    /**
     * Transform an event for Meta Pixel.
     *
     * @param  string  $name  Original event name
     * @param  array<string, mixed>  $params  Original params
     * @param  AnalyticsEvent  $event  Original event (for clientId, userId)
     * @return AnalyticsEvent  Transformed event
     */
    private static function transformForMeta(
        string $name,
        array $params,
        AnalyticsEvent $event,
    ): AnalyticsEvent {
        // E-commerce events: convert items format
        if (self::hasMetaEquivalent($name)) {
            $metaEventName = self::ga4ToMetaEventName($name);

            return new AnalyticsEvent(
                name: $metaEventName ?? $name,
                params: self::ga4ToMetaParams($name, $params),
                clientId: $event->clientId,
                userId: $event->userId,
            );
        }

        // Non-ecommerce events: pass through unchanged
        return $event;
    }

    /**
     * Transform an event for PostHog.
     *
     * @param  string  $name  Original event name
     * @param  array<string, mixed>  $params  Original params
     * @param  AnalyticsEvent  $event  Original event
     * @return AnalyticsEvent  Transformed event
     */
    private static function transformForPosthog(
        string $name,
        array $params,
        AnalyticsEvent $event,
    ): AnalyticsEvent {
        $posthogMap = self::saasToPosthogEventMap();

        if (isset($posthogMap[$name])) {
            return new AnalyticsEvent(
                name: $posthogMap[$name],
                params: $params,
                clientId: $event->clientId,
                userId: $event->userId,
            );
        }

        return $event;
    }

    /**
     * Transform an event for Plausible.
     *
     * Plausible only supports pageview and custom events.
     * Unsupported events (scroll, click, session, form) return null to skip dispatch.
     *
     * @param  string  $name  Original event name
     * @param  array<string, mixed>  $params  Original params
     * @param  AnalyticsEvent  $event  Original event
     * @return AnalyticsEvent  Transformed event
     */
    private static function transformForPlausible(
        string $name,
        array $params,
        AnalyticsEvent $event,
    ): AnalyticsEvent {
        $plausibleName = self::toPlausibleEventName($name);

        if ($plausibleName === null) {
            // Event not supported by Plausible — return original (tracker will handle)
            return $event;
        }

        if ($plausibleName !== $name) {
            return new AnalyticsEvent(
                name: $plausibleName,
                params: $params,
                clientId: $event->clientId,
                userId: $event->userId,
            );
        }

        return $event;
    }

    /**
     * Transform an event for Mixpanel.
     *
     * Mixpanel uses human-readable title-case event names.
     * Uses the catalog's native `mixpanel` field when available.
     *
     * @param  string  $name  Original event name
     * @param  array<string, mixed>  $params  Original params
     * @param  AnalyticsEvent  $event  Original event
     * @return AnalyticsEvent  Transformed event
     */
    private static function transformForMixpanel(
        string $name,
        array $params,
        AnalyticsEvent $event,
    ): AnalyticsEvent {
        $mixpanelMap = self::saasToMixpanelEventMap();

        if (isset($mixpanelMap[$name])) {
            return new AnalyticsEvent(
                name: $mixpanelMap[$name],
                params: $params,
                clientId: $event->clientId,
                userId: $event->userId,
            );
        }

        return $event;
    }

    /**
     * Transform an event for Amplitude.
     *
     * Amplitude uses past-tense action event names (e.g. "Completed Order").
     * Uses the catalog's native `amplitude` field when available.
     *
     * @param  string  $name  Original event name
     * @param  array<string, mixed>  $params  Original params
     * @param  AnalyticsEvent  $event  Original event
     * @return AnalyticsEvent  Transformed event
     */
    private static function transformForAmplitude(
        string $name,
        array $params,
        AnalyticsEvent $event,
    ): AnalyticsEvent {
        $amplitudeMap = self::saasToAmplitudeEventMap();

        if (isset($amplitudeMap[$name])) {
            return new AnalyticsEvent(
                name: $amplitudeMap[$name],
                params: $params,
                clientId: $event->clientId,
                userId: $event->userId,
            );
        }

        return $event;
    }

    /**
     * Map event names to Mixpanel-compatible event names.
     *
     * Mixpanel uses human-readable title-case event names.
     * Events use their catalog `mixpanel` field when available.
     *
     * @return array<string, string>
     */
    public static function saasToMixpanelEventMap(): array
    {
        return [
            // Authentication
            'sign_up' => 'Sign Up',
            'login' => 'Login',
            'logout' => 'Logout',
            'email_verified' => 'Email Verified',
            'password_changed' => 'Password Changed',
            'password_reset' => 'Password Reset',
            // Subscription lifecycle
            'start_trial' => 'Start Trial',
            'trial_end' => 'Trial Ended',
            'trial_converted' => 'Trial Converted',
            'trial_expired' => 'Trial Expired',
            'subscribe' => 'Subscribe',
            'subscription_created' => 'Subscription Created',
            'subscription_renewal' => 'Subscription Renewed',
            'subscription_resumed' => 'Subscription Resumed',
            'subscription_paused' => 'Subscription Paused',
            'subscription_cancelled' => 'Subscription Cancelled',
            'subscription_value_changed' => 'Subscription Value Changed',
            'cancellation' => 'Cancellation',
            'plan_upgrade' => 'Plan Upgraded',
            'plan_downgrade' => 'Plan Downgraded',
            'plan_changed' => 'Plan Changed',
            // Revenue
            'revenue_tracked' => 'Revenue Tracked',
            'expansion_revenue' => 'Expansion Revenue',
            'payment_succeeded' => 'Payment Succeeded',
            'payment_failed' => 'Payment Failed',
            'payment_method_added' => 'Payment Method Added',
            'payment_method_updated' => 'Payment Method Updated',
            'invoice_generated' => 'Invoice Generated',
            'credit_applied' => 'Credit Applied',
            'billing_retry' => 'Billing Retry',
            // E-commerce
            'view_item' => 'View Item',
            'add_to_cart' => 'Add to Cart',
            'remove_from_cart' => 'Remove from Cart',
            'view_cart' => 'View Cart',
            'begin_checkout' => 'Checkout Started',
            'add_payment_info' => 'Add Payment Info',
            'purchase' => 'Purchase',
            'refund' => 'Refund',
            'add_to_wishlist' => 'Add to Wishlist',
            'select_item' => 'Select Item',
            'select_promotion' => 'Select Promotion',
            'view_promotion' => 'View Promotion',
            // Engagement
            'page_view' => 'Page View',
            'scroll_depth' => 'Scroll Depth',
            'click' => 'Click',
            'form_start' => 'Form Started',
            'form_submit' => 'Form Submitted',
            'search' => 'Search',
            'share' => 'Share',
            'error' => 'Error',
            'session_start' => 'Session Started',
            'session_end' => 'Session Ended',
            'time_on_page' => 'Time on Page',
            'content_engagement' => 'Content Engagement',
            'screen_view' => 'Screen View',
            'outbound_click' => 'Outbound Click',
            // Feature & product
            'feature_used' => 'Feature Used',
            'feature_adopted' => 'Feature Adopted',
            'feature_impression' => 'Feature Impression',
            'feature_limit_reached' => 'Feature Limit Reached',
            'usage_quota_reached' => 'Usage Quota Reached',
            'milestone_reached' => 'Milestone Reached',
            // Account lifecycle
            'account_activated' => 'Account Activated',
            'account_deactivated' => 'Account Deactivated',
            'account_deleted' => 'Account Deleted',
            'profile_updated' => 'Profile Updated',
            // B2B / Team
            'team_created' => 'Team Created',
            'team_member_joined' => 'Team Member Joined',
            'team_member_removed' => 'Team Member Removed',
            'role_changed' => 'Role Changed',
            'workspace_created' => 'Workspace Created',
            'invite_sent' => 'Invite Sent',
            // Integration
            'integration_connected' => 'Integration Connected',
            'integration_failed' => 'Integration Failed',
            // Cohort
            'cohort_assigned' => 'Cohort Assigned',
            'cohort_retention' => 'Cohort Retention',
            'cohort_churn' => 'Cohort Churn',
            'cohort_conversion' => 'Cohort Conversion',
            'cohort_migration' => 'Cohort Migration',
            'cohort_engagement' => 'Cohort Engagement',
            // Operational
            'sla_breach' => 'SLA Breach',
            'export' => 'Export',
            'import' => 'Import',
            // GDPR
            'data_subject_access_request' => 'Data Subject Access Request',
            'data_erasure_completed' => 'Data Erasure Completed',
            // Cart & checkout abandonment
            'abandoned_cart' => 'Abandoned Cart',
            'checkout_abandon' => 'Checkout Abandon',
            // Security
            'login_attempt' => 'Login Attempt',
            'suspicious_activity' => 'Suspicious Activity',
            'data_access_audit' => 'Data Access Audit',
            'rate_limit_exceeded' => 'Rate Limit Exceeded',
            'mfa_challenge' => 'MFA Challenge',
            // Uptime
            'service_up' => 'Service Up',
            'service_down' => 'Service Down',
            'api_latency' => 'API Latency',
            'error_spike' => 'Error Spike',
            'deployment' => 'Deployment',
            // Misc engagement
            'onboarding_step' => 'Onboarding Step',
            'onboarding_completed' => 'Onboarding Completed',
            'goal_conversion' => 'Goal Conversion',
            'feature_request' => 'Feature Request',
            'feedback' => 'Feedback',
            'notification' => 'Notification',
            'file_download' => 'File Download',
            'video_play' => 'Video Play',
            'web_vitals' => 'Web Vitals',
            'js_error' => 'JS Error',
            'timing' => 'Timing',
            'ab_test_exposure' => 'A/B Test Exposure',
            'campaign_attribution' => 'Campaign Attribution',
            'ad_click' => 'Ad Click',
            'checkout_step' => 'Checkout Step',
        ];
    }

    /**
     * Map event names to Amplitude-compatible event names.
     *
     * Amplitude uses past-tense action names (e.g. "Completed Order", "Signed Up").
     * Events use their catalog `amplitude` field when available.
     *
     * @return array<string, string>
     */
    public static function saasToAmplitudeEventMap(): array
    {
        return [
            // Authentication
            'sign_up' => 'Signed Up',
            'login' => 'Logged In',
            'logout' => 'Logged Out',
            'email_verified' => 'Email Verified',
            'password_changed' => 'Password Changed',
            'password_reset' => 'Password Reset',
            // Subscription lifecycle
            'start_trial' => 'Started Trial',
            'trial_end' => 'Trial Ended',
            'trial_converted' => 'Trial Converted',
            'trial_expired' => 'Trial Expired',
            'subscribe' => 'Subscribed',
            'subscription_created' => 'Subscription Created',
            'subscription_renewal' => 'Subscription Renewed',
            'subscription_resumed' => 'Subscription Resumed',
            'subscription_paused' => 'Subscription Paused',
            'subscription_cancelled' => 'Subscription Cancelled',
            'subscription_value_changed' => 'Subscription Value Changed',
            'cancellation' => 'Cancelled',
            'plan_upgrade' => 'Upgraded Plan',
            'plan_downgrade' => 'Downgraded Plan',
            'plan_changed' => 'Changed Plan',
            // Revenue
            'revenue_tracked' => 'Revenue Tracked',
            'expansion_revenue' => 'Expansion Revenue',
            'payment_succeeded' => 'Payment Succeeded',
            'payment_failed' => 'Payment Failed',
            'payment_method_added' => 'Added Payment Method',
            'payment_method_updated' => 'Updated Payment Method',
            'invoice_generated' => 'Invoice Generated',
            'credit_applied' => 'Credit Applied',
            'billing_retry' => 'Billing Retry',
            // E-commerce
            'view_item' => 'Viewed Item',
            'add_to_cart' => 'Added to Cart',
            'remove_from_cart' => 'Removed from Cart',
            'view_cart' => 'Viewed Cart',
            'begin_checkout' => 'Started Checkout',
            'add_payment_info' => 'Added Payment Info',
            'purchase' => 'Completed Order',
            'refund' => 'Refunded Order',
            'add_to_wishlist' => 'Added to Wishlist',
            'select_item' => 'Selected Item',
            'select_promotion' => 'Selected Promotion',
            'view_promotion' => 'Viewed Promotion',
            // Engagement
            'page_view' => 'Page View',
            'scroll_depth' => 'Scroll Depth',
            'click' => 'Clicked',
            'form_start' => 'Started Form',
            'form_submit' => 'Submitted Form',
            'search' => 'Searched',
            'share' => 'Shared',
            'error' => 'Error',
            'session_start' => 'Session Started',
            'session_end' => 'Session Ended',
            'time_on_page' => 'Time on Page',
            'content_engagement' => 'Content Engagement',
            'screen_view' => 'Screen View',
            'outbound_click' => 'Clicked Outbound Link',
            // Feature & product
            'feature_used' => 'Used Feature',
            'feature_adopted' => 'Adopted Feature',
            'feature_impression' => 'Feature Impression',
            'feature_limit_reached' => 'Feature Limit Reached',
            'usage_quota_reached' => 'Usage Quota Reached',
            'milestone_reached' => 'Reached Milestone',
            // Account lifecycle
            'account_activated' => 'Activated Account',
            'account_deactivated' => 'Deactivated Account',
            'account_deleted' => 'Deleted Account',
            'profile_updated' => 'Updated Profile',
            // B2B / Team
            'team_created' => 'Created Team',
            'team_member_joined' => 'Joined Team',
            'team_member_removed' => 'Removed Team Member',
            'role_changed' => 'Changed Role',
            'workspace_created' => 'Created Workspace',
            'invite_sent' => 'Sent Invite',
            // Integration
            'integration_connected' => 'Connected Integration',
            'integration_failed' => 'Integration Failed',
            // Cohort
            'cohort_assigned' => 'Assigned Cohort',
            'cohort_retention' => 'Cohort Retention',
            'cohort_churn' => 'Cohort Churn',
            'cohort_conversion' => 'Cohort Conversion',
            'cohort_migration' => 'Cohort Migration',
            'cohort_engagement' => 'Cohort Engagement',
            // Operational
            'sla_breach' => 'SLA Breach',
            'export' => 'Exported Data',
            'import' => 'Imported Data',
            // GDPR
            'data_subject_access_request' => 'Data Subject Access Request',
            'data_erasure_completed' => 'Data Erasure Completed',
            // Cart & checkout abandonment
            'abandoned_cart' => 'Abandoned Cart',
            'checkout_abandon' => 'Abandoned Checkout',
            // Security
            'login_attempt' => 'Login Attempt',
            'suspicious_activity' => 'Suspicious Activity',
            'data_access_audit' => 'Data Access Audit',
            'rate_limit_exceeded' => 'Rate Limit Exceeded',
            'mfa_challenge' => 'MFA Challenge',
            // Uptime
            'service_up' => 'Service Up',
            'service_down' => 'Service Down',
            'api_latency' => 'API Latency',
            'error_spike' => 'Error Spike',
            'deployment' => 'Deployment',
            // Misc engagement
            'onboarding_step' => 'Onboarding Step',
            'onboarding_completed' => 'Onboarding Completed',
            'goal_conversion' => 'Goal Conversion',
            'feature_request' => 'Feature Request',
            'feedback' => 'Feedback',
            'notification' => 'Notification',
            'file_download' => 'Downloaded File',
            'video_play' => 'Played Video',
            'web_vitals' => 'Web Vitals',
            'js_error' => 'JS Error',
            'timing' => 'Timing',
            'ab_test_exposure' => 'A/B Test Exposure',
            'campaign_attribution' => 'Campaign Attribution',
            'ad_click' => 'Clicked Ad',
            'checkout_step' => 'Checkout Step',
        ];
    }
}
