<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

/**
 * SaaS analytics event name constants for IDE autocompletion and type safety.
 *
 * Use these constants instead of raw strings to prevent typos and enable
 * IDE "find usages" / refactoring support when tracking SaaS lifecycle events.
 *
 * @since 238.0.0
 *
 * @see \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents
 */
final class SaaSEventConstants
{
    // ── Identity & Auth ───────────────────────────────────────
    /** @var string User registered */
    public const SIGN_UP = 'sign_up';
    /** @var string User logged in */
    public const LOGIN = 'login';
    /** @var string User logged out */
    public const LOGOUT = 'logout';
    /** @var string Email verified */
    public const EMAIL_VERIFIED = 'email_verified';
    /** @var string Password changed */
    public const PASSWORD_CHANGED = 'password_changed';
    /** @var string Password reset */
    public const PASSWORD_RESET = 'password_reset';
    /** @var string Profile updated */
    public const PROFILE_UPDATED = 'profile_updated';

    // ── Subscription Lifecycle ─────────────────────────────────
    /** @var string Trial started */
    public const START_TRIAL = 'start_trial';
    /** @var string Trial ended */
    public const TRIAL_END = 'trial_end';
    /** @var string Trial converted to paid */
    public const TRIAL_CONVERTED = 'trial_converted';
    /** @var string Trial expired without conversion */
    public const TRIAL_EXPIRED = 'trial_expired';
    /** @var string Subscription created (alias) */
    public const SUBSCRIBE = 'subscribe';
    /** @var string Subscription formally created */
    public const SUBSCRIPTION_CREATED = 'subscription_created';
    /** @var string Subscription renewed */
    public const SUBSCRIPTION_RENEWAL = 'subscription_renewal';
    /** @var string Subscription paused */
    public const SUBSCRIPTION_PAUSED = 'subscription_paused';
    /** @var string Subscription resumed */
    public const SUBSCRIPTION_RESUMED = 'subscription_resumed';
    /** @var string Subscription cancelled */
    public const SUBSCRIPTION_CANCELLED = 'subscription_cancelled';
    /** @var string Subscription value changed */
    public const SUBSCRIPTION_VALUE_CHANGED = 'subscription_value_changed';
    /** @var string Plan upgraded */
    public const PLAN_UPGRADE = 'plan_upgrade';
    /** @var string Plan downgraded */
    public const PLAN_DOWNGRADE = 'plan_downgrade';
    /** @var string Plan changed (generic) */
    public const PLAN_CHANGED = 'plan_changed';
    /** @var string Cancellation */
    public const CANCELLATION = 'cancellation';

    // ── Billing & Revenue ──────────────────────────────────────
    /** @var string Payment failed */
    public const PAYMENT_FAILED = 'payment_failed';
    /** @var string Payment succeeded */
    public const PAYMENT_SUCCEEDED = 'payment_succeeded';
    /** @var string Payment method added */
    public const PAYMENT_METHOD_ADDED = 'payment_method_added';
    /** @var string Payment method updated */
    public const PAYMENT_METHOD_UPDATED = 'payment_method_updated';
    /** @var string Payment method removed */
    public const PAYMENT_METHOD_REMOVED = 'payment_method_removed';
    /** @var string Invoice generated */
    public const INVOICE_GENERATED = 'invoice_generated';
    /** @var string Credit applied */
    public const CREDIT_APPLIED = 'credit_applied';
    /** @var string Billing retry */
    public const BILLING_RETRY = 'billing_retry';
    /** @var string Revenue tracked */
    public const REVENUE_TRACKED = 'revenue_tracked';
    /** @var string Expansion revenue */
    public const EXPANSION_REVENUE = 'expansion_revenue';
    /** @var string Contraction revenue */
    public const CONTRACTION_REVENUE = 'contraction_revenue';

    // ── Feature & Product ──────────────────────────────────────
    /** @var string Feature used */
    public const FEATURE_USED = 'feature_used';
    /** @var string Feature adopted (PLG) */
    public const FEATURE_ADOPTED = 'feature_adopted';
    /** @var string Feature limit reached */
    public const FEATURE_LIMIT_REACHED = 'feature_limit_reached';
    /** @var string Feature impression */
    public const FEATURE_IMPRESSION = 'feature_impression';
    /** @var string Feature flag evaluated */
    public const FEATURE_FLAG_EVALUATED = 'feature_flag_evaluated';

    // ── Team & Collaboration ───────────────────────────────────
    /** @var string Team created */
    public const TEAM_CREATED = 'team_created';
    /** @var string Team member joined */
    public const TEAM_MEMBER_JOINED = 'team_member_joined';
    /** @var string Team member removed */
    public const TEAM_MEMBER_REMOVED = 'team_member_removed';
    /** @var string Role changed */
    public const ROLE_CHANGED = 'role_changed';
    /** @var string Invite sent */
    public const INVITE_SENT = 'invite_sent';
    /** @var string Invite accepted */
    public const INVITE_ACCEPTED = 'invite_accepted';
    /** @var string Workspace created */
    public const WORKSPACE_CREATED = 'workspace_created';

    // ── Cohort Analytics ───────────────────────────────────────
    /** @var string Cohort assigned */
    public const COHORT_ASSIGNED = 'cohort_assigned';
    /** @var string Cohort retention */
    public const COHORT_RETENTION = 'cohort_retention';
    /** @var string Cohort churn */
    public const COHORT_CHURN = 'cohort_churn';
    /** @var string Cohort conversion */
    public const COHORT_CONVERSION = 'cohort_conversion';
    /** @var string Cohort migration */
    public const COHORT_MIGRATION = 'cohort_migration';
    /** @var string Cohort engagement */
    public const COHORT_ENGAGEMENT = 'cohort_engagement';

    // ── Integration ───────────────────────────────────────────
    /** @var string Integration connected */
    public const INTEGRATION_CONNECTED = 'integration_connected';
    /** @var string Integration failed */
    public const INTEGRATION_FAILED = 'integration_failed';
    /** @var string Integration used */
    public const INTEGRATION_USED = 'integration_used';

    // ── Data & Privacy ─────────────────────────────────────────
    /** @var string Export */
    public const EXPORT = 'export';
    /** @var string Import */
    public const IMPORT = 'import';
    /** @var string Account activated */
    public const ACCOUNT_ACTIVATED = 'account_activated';
    /** @var string Account deactivated */
    public const ACCOUNT_DEACTIVATED = 'account_deactivated';
    /** @var string Account deleted */
    public const ACCOUNT_DELETED = 'account_deleted';
    /** @var string Data erasure completed */
    public const DATA_ERASURE_COMPLETED = 'data_erasure_completed';
    /** @var string Data subject access request */
    public const DATA_SUBJECT_ACCESS_REQUEST = 'data_subject_access_request';

    // ── Growth & Milestones ───────────────────────────────────
    /** @var string Milestone reached */
    public const MILESTONE_REACHED = 'milestone_reached';
    /** @var string Growth milestone */
    public const GROWTH_MILESTONE = 'growth_milestone';
    /** @var string ARR milestone */
    public const ARR_MILESTONE = 'arr_milestone';
    /** @var string First value (activation) */
    public const FIRST_VALUE = 'first_value';
    /** @var string Activation */
    public const ACTIVATION = 'activation';
    /** @var string Usage quota reached */
    public const USAGE_QUOTA_REACHED = 'usage_quota_reached';

    // ── Operational & SLA ─────────────────────────────────────
    /** @var string SLA breach */
    public const SLA_BREACH = 'sla_breach';
    /** @var string Api rate limited */
    public const API_RATE_LIMITED = 'api_rate_limited';
    /** @var string Webhook delivered */
    public const WEBHOOK_DELIVERED = 'webhook_delivered';

    // ── Health & Retention ────────────────────────────────────
    /** @var string Health score changed */
    public const HEALTH_SCORE_CHANGED = 'health_score_changed';
    /** @var string Retention risk */
    public const RETENTION_RISK = 'retention_risk';
    /** @var string Retention cohort */
    public const RETENTION_COHORT = 'retention_cohort';
    /** @var string NPS submitted */
    public const NPS_SUBMITTED = 'nps_submitted';
    /** @var string Churn interview */
    public const CHURN_INTERVIEW = 'churn_interview';
    /** @var string Customer review */
    public const CUSTOMER_REVIEW = 'customer_review';
    /** @var string Onboarding started */
    public const ONBOARDING_STARTED = 'onboarding_started';
    /** @var string Onboarding call completed */
    public const ONBOARDING_CALL_COMPLETED = 'onboarding_call_completed';
    /** @var string Support ticket created */
    public const SUPPORT_TICKET_CREATED = 'support_ticket_created';
    /** @var string Renewal reminder sent */
    public const RENEWAL_REMINDER_SENT = 'renewal_reminder_sent';
    /** @var string Upcoming renewal */
    public const UPCOMING_RENEWAL = 'upcoming_renewal';
    /** @var string Product analytics event */
    public const PRODUCT_ANALYTICS = 'product_analytics_event';
    /** @var string Net revenue retention */
    public const NET_REVENUE_RETENTION = 'net_revenue_retention';
    /** @var string MRR movement */
    public const MRR_MOVEMENT = 'mrr_movement';
    /** @var string Payback period */
    public const PAYBACK_PERIOD = 'payback_period';
    /** @var string Burn multiple */
    public const BURN_MULTIPLE = 'burn_multiple';

    /**
     * Get all SaaS event name constants as an associative array.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return (new \ReflectionClass(self::class))->getConstants();
    }

    /**
     * Get all SaaS event name constants as a list.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_values(self::all());
    }

    /**
     * Check if a given event name is a valid SaaS event constant.
     */
    public static function isValid(string $name): bool
    {
        return in_array($name, self::all(), true);
    }

    /**
     * Get the total number of SaaS event constants.
     */
    public static function count(): int
    {
        return count(self::all());
    }
}
