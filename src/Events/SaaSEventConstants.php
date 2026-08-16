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
 * @since 100.0.0
 *
 * @see \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents
 */
final class SaaSEventConstants
{
    // ── Authentication ────────────────────────────────────────────
    /** @var string User signed up / registered */
    public const SIGN_UP = 'sign_up';
    /** @var string User logged in */
    public const LOGIN = 'login';
    /** @var string User logged out */
    public const LOGOUT = 'logout';
    /** @var string User's email was verified */
    public const EMAIL_VERIFIED = 'email_verified';

    // ── Subscription Lifecycle ──────────────────────────────────
    /** @var string Subscription created / activated */
    public const SUBSCRIPTION_CREATED = 'subscription_created';
    /** @var string Subscription renewed */
    public const SUBSCRIPTION_RENEWAL = 'subscription_renewal';
    /** @var string Subscription cancelled */
    public const CANCELLATION = 'cancellation';
    /** @var string Subscription explicitly cancelled (v2.93) */
    public const SUBSCRIPTION_CANCELLED = 'subscription_cancelled';
    /** @var string Subscription paused */
    public const SUBSCRIPTION_PAUSED = 'subscription_paused';
    /** @var string Subscription resumed */
    public const SUBSCRIPTION_RESUMED = 'subscription_resumed';
    /** @var string Subscription value changed (expansion/contraction) */
    public const SUBSCRIPTION_VALUE_CHANGED = 'subscription_value_changed';

    // ── Plan Changes ─────────────────────────────────────────────
    /** @var string Plan upgraded */
    public const PLAN_UPGRADE = 'plan_upgrade';
    /** @var string Plan downgraded */
    public const PLAN_DOWNGRADE = 'plan_downgrade';
    /** @var string Generic plan change (with from/to) */
    public const PLAN_CHANGED = 'plan_changed';

    // ── Trial Lifecycle ──────────────────────────────────────────
    /** @var string Trial started */
    public const TRIAL_START = 'start_trial';
    /** @var string Trial ended */
    public const TRIAL_END = 'trial_end';
    /** @var string Trial converted to paid */
    public const TRIAL_CONVERTED = 'trial_converted';
    /** @var string Trial expired without conversion */
    public const TRIAL_EXPIRED = 'trial_expired';

    // ── Billing & Payments ────────────────────────────────────────
    /** @var string Payment succeeded */
    public const PAYMENT_SUCCEEDED = 'payment_succeeded';
    /** @var string Payment failed */
    public const PAYMENT_FAILED = 'payment_failed';
    /** @var string Payment method added */
    public const PAYMENT_METHOD_ADDED = 'payment_method_added';
    /** @var string Payment method updated */
    public const PAYMENT_METHOD_UPDATED = 'payment_method_updated';
    /** @var string Invoice generated */
    public const INVOICE_GENERATED = 'invoice_generated';
    /** @var string Billing retry attempted */
    public const BILLING_RETRY = 'billing_retry';
    /** @var string Credit applied to account */
    public const CREDIT_APPLIED = 'credit_applied';

    // ── Account Lifecycle ─────────────────────────────────────────
    /** @var string Account activated */
    public const ACCOUNT_ACTIVATED = 'account_activated';
    /** @var string Account deactivated */
    public const ACCOUNT_DEACTIVATED = 'account_deactivated';
    /** @var string Account deleted (GDPR) */
    public const ACCOUNT_DELETED = 'account_deleted';
    /** @var string Password changed */
    public const PASSWORD_CHANGED = 'password_changed';
    /** @var string Password reset requested */
    public const PASSWORD_RESET = 'password_reset';
    /** @var string Profile updated */
    public const PROFILE_UPDATED = 'profile_updated';

    // ── Feature Usage ────────────────────────────────────────────
    /** @var string Feature used / invoked */
    public const FEATURE_USED = 'feature_used';
    /** @var string Feature limit reached */
    public const FEATURE_LIMIT_REACHED = 'feature_limit_reached';
    /** @var string Feature adopted (first meaningful use) */
    public const FEATURE_ADOPTED = 'feature_adopted';

    // ── Team / B2B ────────────────────────────────────────────────
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
    /** @var string Workspace created */
    public const WORKSPACE_CREATED = 'workspace_created';

    // ── Growth & Milestones ───────────────────────────────────────
    /** @var string Growth milestone reached */
    public const MILESTONE_REACHED = 'milestone_reached';
    /** @var string Expansion revenue event */
    public const EXPANSION_REVENUE = 'expansion_revenue';
    /** @var string First value realized (aha moment) */
    public const FIRST_VALUE = 'first_value';
    /** @var string Usage quota reached */
    public const USAGE_QUOTA_REACHED = 'usage_quota_reached';

    // ── Integrations ──────────────────────────────────────────────
    /** @var string Integration connected */
    public const INTEGRATION_CONNECTED = 'integration_connected';
    /** @var string Integration failed */
    public const INTEGRATION_FAILED = 'integration_failed';
    /** @var string Integration used */
    public const INTEGRATION_USED = 'integration_used';

    // ── GDPR & Privacy ────────────────────────────────────────────
    /** @var string Data subject access request */
    public const DATA_SUBJECT_ACCESS_REQUEST = 'data_subject_access_request';
    /** @var string Data erasure completed */
    public const DATA_ERASURE_COMPLETED = 'data_erasure_completed';

    // ── Utility ───────────────────────────────────────────────────

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
