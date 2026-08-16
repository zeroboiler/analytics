<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Schema;

/**
 * Typed event parameter schema definitions for industry-standard SaaS analytics.
 *
 * Provides declarative, typed parameter specifications for every event in the
 * catalog. Each schema defines required parameters, optional parameters with
 * types and defaults, and validation constraints (max length, enums, ranges).
 *
 * Schemas are consumed by EventValidationService and EventSchemaRegistry for
 * runtime validation, and exposed via the API for client-side IntelliSense.
 *
 * @since 6.1.0
 */
final class EventParameterSchemas
{
    /**
     * Get the parameter schema for a given event name.
     *
     * Returns null for unknown events (custom events have no predefined schema).
     *
     * @return EventParameterSchema|null
     */
    public static function forEvent(string $eventName): ?EventParameterSchema
    {
        return self::all()[$eventName] ?? null;
    }

    /**
     * Get all parameter schemas keyed by event name.
     *
     * @return array<string, EventParameterSchema>
     */
    public static function all(): array
    {
        return [
            // ── E-Commerce Events ────────────────────────────────────
            'view_item' => new EventParameterSchema(
                name: 'view_item',
                category: 'ecommerce',
                required: ['currency', 'value', 'items'],
                optional: [
                    'item_list_name' => 'string',
                    'item_list_id' => 'string',
                ],
                itemParams: true,
            ),
            'add_to_cart' => new EventParameterSchema(
                name: 'add_to_cart',
                category: 'ecommerce',
                required: ['currency', 'value', 'items'],
                optional: [
                    'item_list_name' => 'string',
                    'item_list_id' => 'string',
                ],
                itemParams: true,
            ),
            'remove_from_cart' => new EventParameterSchema(
                name: 'remove_from_cart',
                category: 'ecommerce',
                required: ['currency', 'value', 'items'],
                optional: [],
                itemParams: true,
            ),
            'view_cart' => new EventParameterSchema(
                name: 'view_cart',
                category: 'ecommerce',
                required: ['currency', 'value', 'items'],
                optional: [],
                itemParams: true,
            ),
            'begin_checkout' => new EventParameterSchema(
                name: 'begin_checkout',
                category: 'ecommerce',
                required: ['currency', 'value', 'items'],
                optional: [
                    'coupon' => 'string',
                    'checkout_step' => 'integer',
                    'checkout_option' => 'string',
                ],
                itemParams: true,
            ),
            'add_payment_info' => new EventParameterSchema(
                name: 'add_payment_info',
                category: 'ecommerce',
                required: ['currency', 'value', 'items'],
                optional: [
                    'coupon' => 'string',
                    'payment_type' => 'string',
                ],
                itemParams: true,
            ),
            'purchase' => new EventParameterSchema(
                name: 'purchase',
                category: 'ecommerce',
                required: ['transaction_id', 'currency', 'value'],
                optional: [
                    'tax' => 'float',
                    'shipping' => 'float',
                    'coupon' => 'string',
                    'affiliation' => 'string',
                    'shipping_tier' => 'string',
                    'payment_type' => 'string',
                    'items' => 'array',
                ],
                itemParams: true,
            ),
            'refund' => new EventParameterSchema(
                name: 'refund',
                category: 'ecommerce',
                required: ['transaction_id'],
                optional: [
                    'currency' => 'string',
                    'value' => 'float',
                    'shipping' => 'float',
                    'tax' => 'float',
                    'coupon' => 'string',
                    'affiliation' => 'string',
                    'items' => 'array',
                ],
                itemParams: true,
            ),
            'add_to_wishlist' => new EventParameterSchema(
                name: 'add_to_wishlist',
                category: 'ecommerce',
                required: ['items'],
                optional: [
                    'currency' => 'string',
                    'value' => 'float',
                    'item_list_name' => 'string',
                    'item_list_id' => 'string',
                ],
                itemParams: true,
            ),
            'select_item' => new EventParameterSchema(
                name: 'select_item',
                category: 'ecommerce',
                required: ['items'],
                optional: [
                    'item_list_name' => 'string',
                    'item_list_id' => 'string',
                ],
                itemParams: true,
            ),
            'select_promotion' => new EventParameterSchema(
                name: 'select_promotion',
                category: 'ecommerce',
                required: ['items'],
                optional: [
                    'promotion_id' => 'string',
                    'promotion_name' => 'string',
                    'creative_name' => 'string',
                    'creative_slot' => 'string',
                    'location_id' => 'string',
                ],
                itemParams: true,
            ),
            'view_promotion' => new EventParameterSchema(
                name: 'view_promotion',
                category: 'ecommerce',
                required: ['items'],
                optional: [
                    'promotion_id' => 'string',
                    'promotion_name' => 'string',
                    'creative_name' => 'string',
                    'creative_slot' => 'string',
                    'location_id' => 'string',
                ],
                itemParams: true,
            ),
            'checkout_step' => new EventParameterSchema(
                name: 'checkout_step',
                category: 'ecommerce',
                required: ['checkout_step'],
                optional: [
                    'checkout_option' => 'string',
                    'currency' => 'string',
                    'value' => 'float',
                    'items' => 'array',
                ],
                itemParams: false,
            ),
            'abandoned_cart' => new EventParameterSchema(
                name: 'abandoned_cart',
                category: 'ecommerce',
                required: [],
                optional: [
                    'currency' => 'string',
                    'value' => 'float',
                    'items' => 'array',
                    'cart_item_count' => 'integer',
                    'time_since_add' => 'float',
                ],
                itemParams: false,
            ),
            'checkout_abandon' => new EventParameterSchema(
                name: 'checkout_abandon',
                category: 'ecommerce',
                required: [],
                optional: [
                    'checkout_step' => 'integer',
                    'currency' => 'string',
                    'value' => 'float',
                ],
                itemParams: false,
            ),

            // ── SaaS Lifecycle Events ─────────────────────────────────
            'sign_up' => new EventParameterSchema(
                name: 'sign_up',
                category: 'saas',
                required: [],
                optional: [
                    'method' => 'string',
                    'source' => 'string',
                    'plan_name' => 'string',
                    'trial_days' => 'integer',
                ],
                itemParams: false,
            ),
            'login' => new EventParameterSchema(
                name: 'login',
                category: 'saas',
                required: [],
                optional: [
                    'method' => 'string',
                ],
                itemParams: false,
            ),
            'logout' => new EventParameterSchema(
                name: 'logout',
                category: 'saas',
                required: [],
                optional: [],
                itemParams: false,
            ),
            'start_trial' => new EventParameterSchema(
                name: 'start_trial',
                category: 'saas',
                required: [],
                optional: [
                    'plan_name' => 'string',
                    'trial_days' => 'integer',
                    'trial_end_date' => 'string',
                ],
                itemParams: false,
            ),
            'trial_end' => new EventParameterSchema(
                name: 'trial_end',
                category: 'saas',
                required: [],
                optional: [
                    'plan_name' => 'string',
                    'reason' => 'string',
                    'converted' => 'boolean',
                ],
                itemParams: false,
            ),
            'trial_expired' => new EventParameterSchema(
                name: 'trial_expired',
                category: 'saas',
                required: [],
                optional: [
                    'plan_name' => 'string',
                    'trial_days' => 'integer',
                ],
                itemParams: false,
            ),
            'trial_converted' => new EventParameterSchema(
                name: 'trial_converted',
                category: 'saas',
                required: [],
                optional: [
                    'plan_name' => 'string',
                    'trial_days' => 'integer',
                    'days_to_convert' => 'integer',
                    'value' => 'float',
                    'currency' => 'string',
                ],
                itemParams: false,
            ),
            'subscribe' => new EventParameterSchema(
                name: 'subscribe',
                category: 'saas',
                required: [],
                optional: [
                    'plan_name' => 'string',
                    'value' => 'float',
                    'currency' => 'string',
                    'billing_cycle' => 'string',
                    'trial' => 'boolean',
                ],
                itemParams: false,
            ),
            'plan_upgrade' => new EventParameterSchema(
                name: 'plan_upgrade',
                category: 'saas',
                required: [],
                optional: [
                    'from_plan' => 'string',
                    'to_plan' => 'string',
                    'price_difference' => 'float',
                    'currency' => 'string',
                    'reason' => 'string',
                ],
                itemParams: false,
            ),
            'plan_downgrade' => new EventParameterSchema(
                name: 'plan_downgrade',
                category: 'saas',
                required: [],
                optional: [
                    'from_plan' => 'string',
                    'to_plan' => 'string',
                    'price_difference' => 'float',
                    'currency' => 'string',
                    'reason' => 'string',
                ],
                itemParams: false,
            ),
            'plan_changed' => new EventParameterSchema(
                name: 'plan_changed',
                category: 'saas',
                required: [],
                optional: [
                    'from_plan' => 'string',
                    'to_plan' => 'string',
                    'from_price' => 'float',
                    'to_price' => 'float',
                    'currency' => 'string',
                    'reason' => 'string',
                ],
                itemParams: false,
            ),
            'cancellation' => new EventParameterSchema(
                name: 'cancellation',
                category: 'saas',
                required: [],
                optional: [
                    'plan_name' => 'string',
                    'reason' => 'string',
                    'feedback' => 'string',
                    'active_subscription_months' => 'integer',
                    'lifetime_value' => 'float',
                    'currency' => 'string',
                ],
                itemParams: false,
            ),
            'subscription_created' => new EventParameterSchema(
                name: 'subscription_created',
                category: 'saas',
                required: [],
                optional: [
                    'plan_name' => 'string',
                    'value' => 'float',
                    'currency' => 'string',
                    'billing_cycle' => 'string',
                    'trial' => 'boolean',
                ],
                itemParams: false,
            ),
            'subscription_cancelled' => new EventParameterSchema(
                name: 'subscription_cancelled',
                category: 'saas',
                required: [],
                optional: [
                    'plan_name' => 'string',
                    'reason' => 'string',
                    'effective_date' => 'string',
                ],
                itemParams: false,
            ),
            'subscription_renewal' => new EventParameterSchema(
                name: 'subscription_renewal',
                category: 'saas',
                required: [],
                optional: [
                    'plan_name' => 'string',
                    'value' => 'float',
                    'currency' => 'string',
                    'billing_cycle' => 'integer',
                    'renewal_count' => 'integer',
                ],
                itemParams: false,
            ),
            'subscription_resumed' => new EventParameterSchema(
                name: 'subscription_resumed',
                category: 'saas',
                required: [],
                optional: [
                    'plan_name' => 'string',
                    'reason' => 'string',
                ],
                itemParams: false,
            ),
            'subscription_paused' => new EventParameterSchema(
                name: 'subscription_paused',
                category: 'saas',
                required: [],
                optional: [
                    'plan_name' => 'string',
                    'reason' => 'string',
                    'resume_date' => 'string',
                ],
                itemParams: false,
            ),
            'subscription_value_changed' => new EventParameterSchema(
                name: 'subscription_value_changed',
                category: 'saas',
                required: [],
                optional: [
                    'plan_name' => 'string',
                    'old_value' => 'float',
                    'new_value' => 'float',
                    'currency' => 'string',
                    'reason' => 'string',
                ],
                itemParams: false,
            ),
            'feature_used' => new EventParameterSchema(
                name: 'feature_used',
                category: 'saas',
                required: [],
                optional: [
                    'feature_name' => 'string',
                    'category' => 'string',
                    'count' => 'integer',
                ],
                itemParams: false,
            ),
            'feature_adopted' => new EventParameterSchema(
                name: 'feature_adopted',
                category: 'saas',
                required: [],
                optional: [
                    'feature_name' => 'string',
                    'first_use_date' => 'string',
                    'days_since_signup' => 'integer',
                ],
                itemParams: false,
            ),
            'feature_limit_reached' => new EventParameterSchema(
                name: 'feature_limit_reached',
                category: 'saas',
                required: [],
                optional: [
                    'feature_name' => 'string',
                    'limit' => 'integer',
                    'current_usage' => 'integer',
                    'plan_name' => 'string',
                ],
                itemParams: false,
            ),
            'revenue_tracked' => new EventParameterSchema(
                name: 'revenue_tracked',
                category: 'saas',
                required: ['value'],
                optional: [
                    'currency' => 'string',
                    'plan_name' => 'string',
                    'source' => 'string',
                    'recurring' => 'boolean',
                ],
                itemParams: false,
            ),
            'expansion_revenue' => new EventParameterSchema(
                name: 'expansion_revenue',
                category: 'saas',
                required: ['value'],
                optional: [
                    'currency' => 'string',
                    'plan_name' => 'string',
                    'source' => 'string',
                ],
                itemParams: false,
            ),
            'payment_failed' => new EventParameterSchema(
                name: 'payment_failed',
                category: 'saas',
                required: [],
                optional: [
                    'plan_name' => 'string',
                    'amount' => 'float',
                    'currency' => 'string',
                    'reason' => 'string',
                    'retry_count' => 'integer',
                ],
                itemParams: false,
            ),
            'payment_succeeded' => new EventParameterSchema(
                name: 'payment_succeeded',
                category: 'saas',
                required: [],
                optional: [
                    'plan_name' => 'string',
                    'amount' => 'float',
                    'currency' => 'string',
                    'billing_cycle' => 'string',
                ],
                itemParams: false,
            ),
            'billing_retry' => new EventParameterSchema(
                name: 'billing_retry',
                category: 'saas',
                required: [],
                optional: [
                    'plan_name' => 'string',
                    'amount' => 'float',
                    'currency' => 'string',
                    'attempt' => 'integer',
                    'reason' => 'string',
                ],
                itemParams: false,
            ),
            'team_created' => new EventParameterSchema(
                name: 'team_created',
                category: 'saas',
                required: [],
                optional: [
                    'team_name' => 'string',
                    'member_count' => 'integer',
                    'plan_name' => 'string',
                ],
                itemParams: false,
            ),
            'team_member_joined' => new EventParameterSchema(
                name: 'team_member_joined',
                category: 'saas',
                required: [],
                optional: [
                    'team_name' => 'string',
                    'role' => 'string',
                    'invited_by' => 'string',
                ],
                itemParams: false,
            ),
            'team_member_removed' => new EventParameterSchema(
                name: 'team_member_removed',
                category: 'saas',
                required: [],
                optional: [
                    'team_name' => 'string',
                    'role' => 'string',
                    'reason' => 'string',
                ],
                itemParams: false,
            ),
            'role_changed' => new EventParameterSchema(
                name: 'role_changed',
                category: 'saas',
                required: [],
                optional: [
                    'old_role' => 'string',
                    'new_role' => 'string',
                    'changed_by' => 'string',
                ],
                itemParams: false,
            ),
            'invite_sent' => new EventParameterSchema(
                name: 'invite_sent',
                category: 'saas',
                required: [],
                optional: [
                    'team_name' => 'string',
                    'role' => 'string',
                    'email' => 'string',
                ],
                itemParams: false,
            ),
            'integration_connected' => new EventParameterSchema(
                name: 'integration_connected',
                category: 'saas',
                required: [],
                optional: [
                    'integration_name' => 'string',
                    'category' => 'string',
                ],
                itemParams: false,
            ),
            'integration_failed' => new EventParameterSchema(
                name: 'integration_failed',
                category: 'saas',
                required: [],
                optional: [
                    'integration_name' => 'string',
                    'error_type' => 'string',
                    'error_message' => 'string',
                ],
                itemParams: false,
            ),
            'account_activated' => new EventParameterSchema(
                name: 'account_activated',
                category: 'saas',
                required: [],
                optional: [
                    'activation_method' => 'string',
                ],
                itemParams: false,
            ),
            'account_deactivated' => new EventParameterSchema(
                name: 'account_deactivated',
                category: 'saas',
                required: [],
                optional: [
                    'reason' => 'string',
                ],
                itemParams: false,
            ),
            'account_deleted' => new EventParameterSchema(
                name: 'account_deleted',
                category: 'saas',
                required: [],
                optional: [
                    'reason' => 'string',
                    'lifetime_days' => 'integer',
                ],
                itemParams: false,
            ),
            'password_changed' => new EventParameterSchema(
                name: 'password_changed',
                category: 'saas',
                required: [],
                optional: [],
                itemParams: false,
            ),
            'password_reset' => new EventParameterSchema(
                name: 'password_reset',
                category: 'saas',
                required: [],
                optional: [
                    'method' => 'string',
                ],
                itemParams: false,
            ),
            'email_verified' => new EventParameterSchema(
                name: 'email_verified',
                category: 'saas',
                required: [],
                optional: [],
                itemParams: false,
            ),
            'profile_updated' => new EventParameterSchema(
                name: 'profile_updated',
                category: 'saas',
                required: [],
                optional: [
                    'fields_updated' => 'string',
                ],
                itemParams: false,
            ),
            'workspace_created' => new EventParameterSchema(
                name: 'workspace_created',
                category: 'saas',
                required: [],
                optional: [
                    'workspace_name' => 'string',
                    'plan_name' => 'string',
                ],
                itemParams: false,
            ),
            'milestone_reached' => new EventParameterSchema(
                name: 'milestone_reached',
                category: 'saas',
                required: [],
                optional: [
                    'milestone_name' => 'string',
                    'milestone_type' => 'string',
                    'value' => 'float',
                ],
                itemParams: false,
            ),
            'usage_quota_reached' => new EventParameterSchema(
                name: 'usage_quota_reached',
                category: 'saas',
                required: [],
                optional: [
                    'feature_name' => 'string',
                    'quota_limit' => 'integer',
                    'current_usage' => 'integer',
                    'plan_name' => 'string',
                ],
                itemParams: false,
            ),
            'sla_breach' => new EventParameterSchema(
                name: 'sla_breach',
                category: 'saas',
                required: [],
                optional: [
                    'sla_type' => 'string',
                    'breach_duration' => 'float',
                    'threshold' => 'float',
                ],
                itemParams: false,
            ),
            'consent_granted' => new EventParameterSchema(
                name: 'consent_granted',
                category: 'saas',
                required: [],
                optional: [
                    'purposes' => 'array',
                    'source' => 'string',
                ],
                itemParams: false,
            ),
            'consent_withdrawn' => new EventParameterSchema(
                name: 'consent_withdrawn',
                category: 'saas',
                required: [],
                optional: [
                    'purposes' => 'array',
                    'source' => 'string',
                ],
                itemParams: false,
            ),

            // ── Engagement Events ──────────────────────────────────────
            'page_view' => new EventParameterSchema(
                name: 'page_view',
                category: 'engagement',
                required: [],
                optional: [
                    'page_title' => 'string',
                    'page_location' => 'string',
                    'page_referrer' => 'string',
                ],
                itemParams: false,
            ),
            'scroll_depth' => new EventParameterSchema(
                name: 'scroll_depth',
                category: 'engagement',
                required: [],
                optional: [
                    'percent' => 'integer',
                    'page_location' => 'string',
                    'page_title' => 'string',
                    'time_on_page' => 'float',
                ],
                itemParams: false,
            ),
            'click' => new EventParameterSchema(
                name: 'click',
                category: 'engagement',
                required: [],
                optional: [
                    'element' => 'string',
                    'element_id' => 'string',
                    'element_class' => 'string',
                    'element_text' => 'string',
                    'url' => 'string',
                    'section' => 'string',
                ],
                itemParams: false,
            ),
            'outbound_click' => new EventParameterSchema(
                name: 'outbound_click',
                category: 'engagement',
                required: ['url'],
                optional: [
                    'link_text' => 'string',
                    'link_id' => 'string',
                    'section' => 'string',
                ],
                itemParams: false,
            ),
            'form_start' => new EventParameterSchema(
                name: 'form_start',
                category: 'engagement',
                required: [],
                optional: [
                    'form_id' => 'string',
                    'form_name' => 'string',
                    'form_type' => 'string',
                    'page_location' => 'string',
                ],
                itemParams: false,
            ),
            'form_submit' => new EventParameterSchema(
                name: 'form_submit',
                category: 'engagement',
                required: [],
                optional: [
                    'form_id' => 'string',
                    'form_name' => 'string',
                    'form_type' => 'string',
                    'success' => 'boolean',
                    'page_location' => 'string',
                ],
                itemParams: false,
            ),
            'search' => new EventParameterSchema(
                name: 'search',
                category: 'engagement',
                required: ['search_term'],
                optional: [
                    'results_count' => 'integer',
                    'category' => 'string',
                    'source' => 'string',
                ],
                itemParams: false,
            ),
            'share' => new EventParameterSchema(
                name: 'share',
                category: 'engagement',
                required: ['method'],
                optional: [
                    'content_type' => 'string',
                    'content_id' => 'string',
                    'content_name' => 'string',
                    'item_id' => 'string',
                ],
                itemParams: false,
            ),
            'error' => new EventParameterSchema(
                name: 'error',
                category: 'engagement',
                required: [],
                optional: [
                    'error_type' => 'string',
                    'error_message' => 'string',
                    'error_code' => 'string',
                    'fatal' => 'boolean',
                    'page_location' => 'string',
                    'stack_trace' => 'string',
                ],
                itemParams: false,
            ),
            'js_error' => new EventParameterSchema(
                name: 'js_error',
                category: 'engagement',
                required: [],
                optional: [
                    'error_message' => 'string',
                    'error_source' => 'string',
                    'line_number' => 'integer',
                    'column_number' => 'integer',
                    'page_location' => 'string',
                ],
                itemParams: false,
            ),
            'session_start' => new EventParameterSchema(
                name: 'session_start',
                category: 'engagement',
                required: [],
                optional: [
                    'session_id' => 'string',
                    'source' => 'string',
                    'medium' => 'string',
                ],
                itemParams: false,
            ),
            'session_end' => new EventParameterSchema(
                name: 'session_end',
                category: 'engagement',
                required: [],
                optional: [
                    'session_id' => 'string',
                    'duration_seconds' => 'float',
                    'event_count' => 'integer',
                    'page_view_count' => 'integer',
                ],
                itemParams: false,
            ),
            'time_on_page' => new EventParameterSchema(
                name: 'time_on_page',
                category: 'engagement',
                required: [],
                optional: [
                    'page_location' => 'string',
                    'page_title' => 'string',
                    'duration_seconds' => 'float',
                    'engagement_percent' => 'float',
                ],
                itemParams: false,
            ),
            'screen_view' => new EventParameterSchema(
                name: 'screen_view',
                category: 'engagement',
                required: ['screen_name'],
                optional: [
                    'screen_class' => 'string',
                ],
                itemParams: false,
            ),
            'file_download' => new EventParameterSchema(
                name: 'file_download',
                category: 'engagement',
                required: ['file_name'],
                optional: [
                    'file_extension' => 'string',
                    'file_url' => 'string',
                    'file_size' => 'integer',
                    'link_text' => 'string',
                ],
                itemParams: false,
            ),
            'video_play' => new EventParameterSchema(
                name: 'video_play',
                category: 'engagement',
                required: ['video_title'],
                optional: [
                    'video_url' => 'string',
                    'video_provider' => 'string',
                    'video_duration' => 'float',
                    'video_percent' => 'integer',
                ],
                itemParams: false,
            ),
            'web_vitals' => new EventParameterSchema(
                name: 'web_vitals',
                category: 'engagement',
                required: [],
                optional: [
                    'metric_name' => 'string',
                    'metric_value' => 'float',
                    'rating' => 'string',
                    'page_location' => 'string',
                    'navigation_type' => 'string',
                ],
                itemParams: false,
            ),
            'timing' => new EventParameterSchema(
                name: 'timing',
                category: 'engagement',
                required: ['name', 'duration'],
                optional: [
                    'category' => 'string',
                    'label' => 'string',
                    'page_location' => 'string',
                ],
                itemParams: false,
            ),
            'notification' => new EventParameterSchema(
                name: 'notification',
                category: 'engagement',
                required: [],
                optional: [
                    'type' => 'string',
                    'action' => 'string',
                    'notification_id' => 'string',
                    'title' => 'string',
                ],
                itemParams: false,
            ),
            'ad_click' => new EventParameterSchema(
                name: 'ad_click',
                category: 'engagement',
                required: [],
                optional: [
                    'platform' => 'string',
                    'campaign_id' => 'string',
                    'ad_group_id' => 'string',
                    'creative_id' => 'string',
                    'placement' => 'string',
                    'keyword' => 'string',
                    'cost' => 'float',
                ],
                itemParams: false,
            ),
            'content_engagement' => new EventParameterSchema(
                name: 'content_engagement',
                category: 'engagement',
                required: [],
                optional: [
                    'content_type' => 'string',
                    'content_id' => 'string',
                    'title' => 'string',
                    'author' => 'string',
                    'category' => 'string',
                    'engagement_percent' => 'float',
                    'time_spent_seconds' => 'float',
                    'completed' => 'boolean',
                ],
                itemParams: false,
            ),
            'onboarding_step' => new EventParameterSchema(
                name: 'onboarding_step',
                category: 'engagement',
                required: [],
                optional: [
                    'step_name' => 'string',
                    'step_index' => 'integer',
                    'total_steps' => 'integer',
                    'method' => 'string',
                    'completed' => 'boolean',
                    'duration_seconds' => 'float',
                ],
                itemParams: false,
            ),
            'feature_request' => new EventParameterSchema(
                name: 'feature_request',
                category: 'engagement',
                required: [],
                optional: [
                    'feature_name' => 'string',
                    'description' => 'string',
                    'category' => 'string',
                ],
                itemParams: false,
            ),
            'feedback' => new EventParameterSchema(
                name: 'feedback',
                category: 'engagement',
                required: [],
                optional: [
                    'score' => 'integer',
                    'comment' => 'string',
                    'source' => 'string',
                    'category' => 'string',
                ],
                itemParams: false,
            ),
            'goal_conversion' => new EventParameterSchema(
                name: 'goal_conversion',
                category: 'engagement',
                required: ['goal_name'],
                optional: [
                    'goal_value' => 'float',
                    'currency' => 'string',
                    'category' => 'string',
                ],
                itemParams: false,
            ),
            'ab_test_exposure' => new EventParameterSchema(
                name: 'ab_test_exposure',
                category: 'engagement',
                required: ['experiment_id', 'variant_id'],
                optional: [
                    'experiment_name' => 'string',
                    'category' => 'string',
                ],
                itemParams: false,
            ),
            'campaign_attribution' => new EventParameterSchema(
                name: 'campaign_attribution',
                category: 'engagement',
                required: [],
                optional: [
                    'utm_source' => 'string',
                    'utm_medium' => 'string',
                    'utm_campaign' => 'string',
                    'utm_term' => 'string',
                    'utm_content' => 'string',
                ],
                itemParams: false,
            ],

            // ── Cohort Events ─────────────────────────────────────────
            'cohort_assigned' => new EventParameterSchema(
                name: 'cohort_assigned',
                category: 'saas',
                required: ['cohort_name'],
                optional: [
                    'cohort_type' => 'string',
                    'criteria' => 'string',
                ],
                itemParams: false,
            ),
            'cohort_retention' => new EventParameterSchema(
                name: 'cohort_retention',
                category: 'saas',
                required: ['cohort_name'],
                optional: [
                    'period_days' => 'integer',
                    'retention_rate' => 'float',
                    'active_users' => 'integer',
                    'total_users' => 'integer',
                ],
                itemParams: false,
            ),
            'cohort_churn' => new EventParameterSchema(
                name: 'cohort_churn',
                category: 'saas',
                required: ['cohort_name'],
                optional: [
                    'period_days' => 'integer',
                    'churn_rate' => 'float',
                ],
                itemParams: false,
            ),
            'cohort_conversion' => new EventParameterSchema(
                name: 'cohort_conversion',
                category: 'saas',
                required: ['cohort_name'],
                optional: [
                    'conversion_rate' => 'float',
                    'period_days' => 'integer',
                ],
                itemParams: false,
            ),
            'cohort_migration' => new EventParameterSchema(
                name: 'cohort_migration',
                category: 'saas',
                required: ['from_cohort', 'to_cohort'],
                optional: [
                    'reason' => 'string',
                ],
                itemParams: false,
            ),
            'cohort_engagement' => new EventParameterSchema(
                name: 'cohort_engagement',
                category: 'saas',
                required: ['cohort_name'],
                optional: [
                    'engagement_score' => 'float',
                    'event_count' => 'integer',
                    'period_days' => 'integer',
                ],
                itemParams: false,
            ),
        ];
    }

    /**
     * Get all schema event names.
     *
     * @return list<string>
     */
    public static function schemaEventNames(): array
    {
        return array_keys(self::all());
    }

    /**
     * Get the total number of events with parameter schemas.
     */
    public static function count(): int
    {
        return count(self::all());
    }

    /**
     * Check if an event has a parameter schema defined.
     */
    public static function hasSchema(string $eventName): bool
    {
        return isset(self::all()[$eventName]);
    }

    /**
     * Validate event parameters against its schema.
     *
     * Returns an array of validation errors. Empty array means valid.
     *
     * @param  string  $eventName
     * @param  array<string, mixed>  $params
     * @return list<string>
     */
    public static function validate(string $eventName, array $params): array
    {
        $schema = self::forEvent($eventName);

        if ($schema === null) {
            return []; // No schema = no validation (custom events)
        }

        $errors = [];

        // Check required parameters
        foreach ($schema->required as $param) {
            if (! array_key_exists($param, $params)) {
                $errors[] = "Missing required parameter: '{$param}'";
            }
        }

        // Type-check known optional parameters
        foreach ($schema->optional as $param => $expectedType) {
            if (! array_key_exists($param, $params)) {
                continue;
            }

            $value = $params[$param];

            if ($value === null) {
                continue; // null is acceptable for optional params
            }

            $valid = match ($expectedType) {
                'string' => is_string($value),
                'integer', 'int' => is_int($value),
                'float', 'double' => is_float($value) || is_int($value),
                'boolean', 'bool' => is_bool($value),
                'array' => is_array($value),
                default => true,
            };

            if (! $valid) {
                $actualType = get_debug_type($value);
                $errors[] = "Parameter '{$param}' expected type '{$expectedType}', got '{$actualType}'";
            }
        }

        return $errors;
    }

    /**
     * Get schemas grouped by category.
     *
     * @return array{ecommerce: array<string, EventParameterSchema>, saas: array<string, EventParameterSchema>, engagement: array<string, EventParameterSchema>}
     */
    public static function byCategory(): array
    {
        $result = [
            'ecommerce' => [],
            'saas' => [],
            'engagement' => [],
        ];

        foreach (self::all() as $name => $schema) {
            $result[$schema->category][$name] = $schema;
        }

        return $result;
    }
}
