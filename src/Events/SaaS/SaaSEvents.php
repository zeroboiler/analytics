<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Static catalog of all SaaS lifecycle analytics events.
 *
 * Provides a central registry for event names, classes, and metadata.
 * Use for validation, lookup, and bulk operations.
 *
 * @phpstan-type EventEntry array{name: string, class: class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>, ga4: string, meta: string|null, posthog: string, plausible: string|null, mixpanel: string, amplitude: string, tiktok: string|null, linkedin: string|null}
 *
 * @since 1.0.0
 */
final class SaaSEvents
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
            'sign_up' => [
                'name' => 'sign_up',
                'class' => SignUpEvent::class,
                'ga4' => 'sign_up',
                'meta' => 'CompleteRegistration',
                'posthog' => '$signup',
                'plausible' => 'signup',
                'mixpanel' => 'Sign Up',
                'amplitude' => 'Sign Up',
                'tiktok' => 'CompleteRegistration',
                'linkedin' => 'signup',
            ],
            'login' => [
                'name' => 'login',
                'class' => LoginEvent::class,
                'ga4' => 'login',
                'meta' => 'Login',
                'posthog' => '$identify',
                'plausible' => 'login',
                'mixpanel' => 'Login',
                'amplitude' => 'Login',
                'tiktok' => 'Login',
                'linkedin' => 'login',
            ],
            'logout' => [
                'name' => 'logout',
                'class' => LogoutEvent::class,
                'ga4' => 'logout',
                'meta' => 'Logout',
                'posthog' => 'logout',
                'plausible' => 'logout',
                'mixpanel' => 'Logout',
                'amplitude' => 'Logout',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'start_trial' => [
                'name' => 'start_trial',
                'class' => TrialStartEvent::class,
                'ga4' => 'start_trial',
                'meta' => 'StartTrial',
                'posthog' => 'start_trial',
                'plausible' => 'trial_start',
                'mixpanel' => 'Start Trial',
                'amplitude' => 'Start Trial',
                'tiktok' => 'Subscribe',
                'linkedin' => 'signup',
            ],
            'trial_end' => [
                'name' => 'trial_end',
                'class' => TrialEndEvent::class,
                'ga4' => 'trial_end',
                'meta' => 'TrialEnded',
                'posthog' => 'trial_ended',
                'plausible' => 'trial_end',
                'mixpanel' => 'Trial End',
                'amplitude' => 'Trial End',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'subscribe' => [
                'name' => 'subscribe',
                'class' => SubscriptionEvent::class,
                'ga4' => 'purchase',
                'meta' => 'Subscribe',
                'posthog' => 'subscription_created',
                'plausible' => 'subscription',
                'mixpanel' => 'Subscribe',
                'amplitude' => 'Subscribe',
                'tiktok' => 'Subscribe',
                'linkedin' => 'purchase',
            ],
            'plan_upgrade' => [
                'name' => 'plan_upgrade',
                'class' => PlanUpgradeEvent::class,
                'ga4' => 'plan_upgrade',
                'meta' => 'PlanUpgrade',
                'posthog' => 'plan_upgraded',
                'plausible' => 'plan_upgrade',
                'mixpanel' => 'Plan Upgrade',
                'amplitude' => 'Plan Upgrade',
                'tiktok' => 'Subscribe',
                'linkedin' => 'purchase',
            ],
            'plan_downgrade' => [
                'name' => 'plan_downgrade',
                'class' => PlanDowngradeEvent::class,
                'ga4' => 'plan_downgrade',
                'meta' => 'PlanDowngrade',
                'posthog' => 'plan_downgraded',
                'plausible' => 'plan_downgrade',
                'mixpanel' => 'Plan Downgrade',
                'amplitude' => 'Plan Downgrade',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'cancellation' => [
                'name' => 'cancellation',
                'class' => CancellationEvent::class,
                'ga4' => 'cancellation',
                'meta' => 'CancelSubscription',
                'posthog' => 'cancellation',
                'plausible' => 'cancellation',
                'mixpanel' => 'Cancellation',
                'amplitude' => 'Cancellation',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'feature_used' => [
                'name' => 'feature_used',
                'class' => FeatureUsedEvent::class,
                'ga4' => 'feature_used',
                'meta' => 'FeatureUsed',
                'posthog' => 'feature_used',
                'plausible' => 'feature_used',
                'mixpanel' => 'Feature Used',
                'amplitude' => 'Feature Used',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'revenue_tracked' => [
                'name' => 'revenue_tracked',
                'class' => RevenueEvent::class,
                'ga4' => 'revenue_tracked',
                'meta' => 'Purchase',
                'posthog' => 'revenue_tracked',
                'plausible' => 'revenue',
                'mixpanel' => 'Revenue Tracked',
                'amplitude' => 'Revenue Tracked',
                'tiktok' => 'CompletePayment',
                'linkedin' => 'purchase',
            ],
            // Cohort analytics events (typed classes)
            'cohort_assigned' => [
                'name' => 'cohort_assigned',
                'class' => CohortAssignedEvent::class,
                'ga4' => 'cohort_assigned',
                'meta' => 'CohortAssigned',
                'posthog' => 'cohort_assigned',
                'plausible' => null,
                'mixpanel' => 'Cohort Assigned',
                'amplitude' => 'Cohort Assigned',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'cohort_retention' => [
                'name' => 'cohort_retention',
                'class' => CohortRetentionEvent::class,
                'ga4' => 'cohort_retention',
                'meta' => 'CohortRetention',
                'posthog' => 'cohort_retention',
                'plausible' => null,
                'mixpanel' => 'Cohort Retention',
                'amplitude' => 'Cohort Retention',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'cohort_churn' => [
                'name' => 'cohort_churn',
                'class' => CohortChurnEvent::class,
                'ga4' => 'cohort_churn',
                'meta' => 'CohortChurn',
                'posthog' => 'cohort_churn',
                'plausible' => null,
                'mixpanel' => 'Cohort Churn',
                'amplitude' => 'Cohort Churn',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'cohort_conversion' => [
                'name' => 'cohort_conversion',
                'class' => CohortConversionEvent::class,
                'ga4' => 'cohort_conversion',
                'meta' => 'CohortConversion',
                'posthog' => 'cohort_conversion',
                'plausible' => null,
                'mixpanel' => 'Cohort Conversion',
                'amplitude' => 'Cohort Conversion',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'cohort_migration' => [
                'name' => 'cohort_migration',
                'class' => CohortMigrationEvent::class,
                'ga4' => 'cohort_migration',
                'meta' => 'CohortMigration',
                'posthog' => 'cohort_migration',
                'plausible' => null,
                'mixpanel' => 'Cohort Migration',
                'amplitude' => 'Cohort Migration',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'cohort_engagement' => [
                'name' => 'cohort_engagement',
                'class' => CohortEngagementEvent::class,
                'ga4' => 'cohort_engagement',
                'meta' => 'CohortEngagement',
                'posthog' => 'cohort_engagement',
                'plausible' => null,
                'mixpanel' => 'Cohort Engagement',
                'amplitude' => 'Cohort Engagement',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'invite_sent' => [
                'name' => 'invite_sent',
                'class' => InviteSentEvent::class,
                'ga4' => 'invite_sent',
                'meta' => 'InviteSent',
                'posthog' => 'invite_sent',
                'plausible' => null,
                'mixpanel' => 'Invite Sent',
                'amplitude' => 'Invite Sent',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'integration_connected' => [
                'name' => 'integration_connected',
                'class' => IntegrationConnectedEvent::class,
                'ga4' => 'integration_connected',
                'meta' => 'IntegrationConnected',
                'posthog' => 'integration_connected',
                'plausible' => null,
                'mixpanel' => 'Integration Connected',
                'amplitude' => 'Integration Connected',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'subscription_renewal' => [
                'name' => 'subscription_renewal',
                'class' => SubscriptionRenewalEvent::class,
                'ga4' => 'subscription_renewal',
                'meta' => 'SubscriptionRenewal',
                'posthog' => 'subscription_renewed',
                'plausible' => 'subscription_renewal',
                'mixpanel' => 'Subscription Renewal',
                'amplitude' => 'Subscription Renewal',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Account lifecycle events
            'account_activated' => [
                'name' => 'account_activated',
                'class' => AccountActivatedEvent::class,
                'ga4' => 'account_activated',
                'meta' => 'AccountActivated',
                'posthog' => 'account_activated',
                'plausible' => null,
                'mixpanel' => 'Account Activated',
                'amplitude' => 'Account Activated',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'account_deactivated' => [
                'name' => 'account_deactivated',
                'class' => AccountDeactivatedEvent::class,
                'ga4' => 'account_deactivated',
                'meta' => 'AccountDeactivated',
                'posthog' => 'account_deactivated',
                'plausible' => null,
                'mixpanel' => 'Account Deactivated',
                'amplitude' => 'Account Deactivated',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'password_changed' => [
                'name' => 'password_changed',
                'class' => PasswordChangedEvent::class,
                'ga4' => 'password_changed',
                'meta' => 'PasswordChanged',
                'posthog' => 'password_changed',
                'plausible' => null,
                'mixpanel' => 'Password Changed',
                'amplitude' => 'Password Changed',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'password_reset' => [
                'name' => 'password_reset',
                'class' => PasswordResetEvent::class,
                'ga4' => 'password_reset',
                'meta' => 'PasswordReset',
                'posthog' => 'password_reset',
                'plausible' => null,
                'mixpanel' => 'Password Reset',
                'amplitude' => 'Password Reset',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'profile_updated' => [
                'name' => 'profile_updated',
                'class' => ProfileUpdatedEvent::class,
                'ga4' => 'profile_updated',
                'meta' => 'ProfileUpdated',
                'posthog' => 'profile_updated',
                'plausible' => null,
                'mixpanel' => 'Profile Updated',
                'amplitude' => 'Profile Updated',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'email_verified' => [
                'name' => 'email_verified',
                'class' => EmailVerifiedEvent::class,
                'ga4' => 'email_verified',
                'meta' => 'EmailVerified',
                'posthog' => 'email_verified',
                'plausible' => null,
                'mixpanel' => 'Email Verified',
                'amplitude' => 'Email Verified',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // B2B / Team events
            'team_created' => [
                'name' => 'team_created',
                'class' => TeamCreatedEvent::class,
                'ga4' => 'team_created',
                'meta' => 'TeamCreated',
                'posthog' => 'team_created',
                'plausible' => null,
                'mixpanel' => 'Team Created',
                'amplitude' => 'Team Created',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'team_member_joined' => [
                'name' => 'team_member_joined',
                'class' => TeamMemberJoinedEvent::class,
                'ga4' => 'team_member_joined',
                'meta' => 'TeamMemberJoined',
                'posthog' => 'team_member_joined',
                'plausible' => null,
                'mixpanel' => 'Team Member Joined',
                'amplitude' => 'Team Member Joined',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'team_member_removed' => [
                'name' => 'team_member_removed',
                'class' => TeamMemberRemovedEvent::class,
                'ga4' => 'team_member_removed',
                'meta' => 'TeamMemberRemoved',
                'posthog' => 'team_member_removed',
                'plausible' => null,
                'mixpanel' => 'Team Member Removed',
                'amplitude' => 'Team Member Removed',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'role_changed' => [
                'name' => 'role_changed',
                'class' => RoleChangedEvent::class,
                'ga4' => 'role_changed',
                'meta' => 'RoleChanged',
                'posthog' => 'role_changed',
                'plausible' => null,
                'mixpanel' => 'Role Changed',
                'amplitude' => 'Role Changed',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Billing events
            'payment_failed' => [
                'name' => 'payment_failed',
                'class' => PaymentFailedEvent::class,
                'ga4' => 'payment_failed',
                'meta' => 'PaymentFailed',
                'posthog' => 'payment_failed',
                'plausible' => null,
                'mixpanel' => 'Payment Failed',
                'amplitude' => 'Payment Failed',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'payment_succeeded' => [
                'name' => 'payment_succeeded',
                'class' => PaymentSucceededEvent::class,
                'ga4' => 'payment_succeeded',
                'meta' => 'PaymentSucceeded',
                'posthog' => 'payment_succeeded',
                'plausible' => null,
                'mixpanel' => 'Payment Succeeded',
                'amplitude' => 'Payment Succeeded',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'payment_method_added' => [
                'name' => 'payment_method_added',
                'class' => PaymentMethodAddedEvent::class,
                'ga4' => 'payment_method_added',
                'meta' => 'PaymentMethodAdded',
                'posthog' => 'payment_method_added',
                'plausible' => null,
                'mixpanel' => 'Payment Method Added',
                'amplitude' => 'Payment Method Added',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'invoice_generated' => [
                'name' => 'invoice_generated',
                'class' => InvoiceGeneratedEvent::class,
                'ga4' => 'invoice_generated',
                'meta' => 'InvoiceGenerated',
                'posthog' => 'invoice_generated',
                'plausible' => null,
                'mixpanel' => 'Invoice Generated',
                'amplitude' => 'Invoice Generated',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'credit_applied' => [
                'name' => 'credit_applied',
                'class' => CreditAppliedEvent::class,
                'ga4' => 'credit_applied',
                'meta' => 'CreditApplied',
                'posthog' => 'credit_applied',
                'plausible' => null,
                'mixpanel' => 'Credit Applied',
                'amplitude' => 'Credit Applied',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Operational events
            'feature_limit_reached' => [
                'name' => 'feature_limit_reached',
                'class' => FeatureLimitReachedEvent::class,
                'ga4' => 'feature_limit_reached',
                'meta' => 'FeatureLimitReached',
                'posthog' => 'feature_limit_reached',
                'plausible' => null,
                'mixpanel' => 'Feature Limit Reached',
                'amplitude' => 'Feature Limit Reached',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'integration_failed' => [
                'name' => 'integration_failed',
                'class' => IntegrationFailedEvent::class,
                'ga4' => 'integration_failed',
                'meta' => 'IntegrationFailed',
                'posthog' => 'integration_failed',
                'plausible' => null,
                'mixpanel' => 'Integration Failed',
                'amplitude' => 'Integration Failed',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Feature discovery & exposure
            'feature_impression' => [
                'name' => 'feature_impression',
                'class' => ImpressionEvent::class,
                'ga4' => 'feature_impression',
                'meta' => 'FeatureImpression',
                'posthog' => 'feature_impression',
                'plausible' => null,
                'mixpanel' => 'Feature Impression',
                'amplitude' => 'Feature Impression',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Multi-tenant workspace creation
            'workspace_created' => [
                'name' => 'workspace_created',
                'class' => WorkspaceCreatedEvent::class,
                'ga4' => 'workspace_created',
                'meta' => 'WorkspaceCreated',
                'posthog' => 'workspace_created',
                'plausible' => null,
                'mixpanel' => 'Workspace Created',
                'amplitude' => 'Workspace Created',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Conversion & growth events (v2.66.0)
            'trial_converted' => [
                'name' => 'trial_converted',
                'class' => TrialConvertedEvent::class,
                'ga4' => 'trial_converted',
                'meta' => 'Subscribe',
                'posthog' => 'trial_converted',
                'plausible' => 'conversion',
                'mixpanel' => 'Trial Converted',
                'amplitude' => 'Trial Converted',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'subscription_resumed' => [
                'name' => 'subscription_resumed',
                'class' => SubscriptionResumedEvent::class,
                'ga4' => 'subscription_resumed',
                'meta' => 'Subscribe',
                'posthog' => 'subscription_resumed',
                'plausible' => null,
                'mixpanel' => 'Subscription Resumed',
                'amplitude' => 'Subscription Resumed',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'milestone_reached' => [
                'name' => 'milestone_reached',
                'class' => MilestoneReachedEvent::class,
                'ga4' => 'milestone_reached',
                'meta' => 'MilestoneReached',
                'posthog' => 'milestone_reached',
                'plausible' => null,
                'mixpanel' => 'Milestone Reached',
                'amplitude' => 'Milestone Reached',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Subscription lifecycle — pause/resume
            'subscription_paused' => [
                'name' => 'subscription_paused',
                'class' => SubscriptionPausedEvent::class,
                'ga4' => 'subscription_paused',
                'meta' => 'SubscriptionPaused',
                'posthog' => 'subscription_paused',
                'plausible' => null,
                'mixpanel' => 'Subscription Paused',
                'amplitude' => 'Subscription Paused',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Revenue movement events
            'subscription_value_changed' => [
                'name' => 'subscription_value_changed',
                'class' => SubscriptionValueChangedEvent::class,
                'ga4' => 'subscription_value_changed',
                'meta' => 'SubscriptionValueChanged',
                'posthog' => 'subscription_value_changed',
                'plausible' => null,
                'mixpanel' => 'Subscription Value Changed',
                'amplitude' => 'Subscription Value Changed',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Expansion & limit signals
            'usage_quota_reached' => [
                'name' => 'usage_quota_reached',
                'class' => UsageQuotaReachedEvent::class,
                'ga4' => 'usage_quota_reached',
                'meta' => 'UsageQuotaReached',
                'posthog' => 'usage_quota_reached',
                'plausible' => null,
                'mixpanel' => 'Usage Quota Reached',
                'amplitude' => 'Usage Quota Reached',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Dunning / billing retry
            'billing_retry' => [
                'name' => 'billing_retry',
                'class' => BillingRetryEvent::class,
                'ga4' => 'billing_retry',
                'meta' => 'BillingRetry',
                'posthog' => 'billing_retry',
                'plausible' => null,
                'mixpanel' => 'Billing Retry',
                'amplitude' => 'Billing Retry',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // SLA compliance event
            'sla_breach' => [
                'name' => 'sla_breach',
                'class' => SlaBreachEvent::class,
                'ga4' => 'sla_breach',
                'meta' => 'CustomEvent',
                'posthog' => 'sla_breach',
                'plausible' => null,
                'mixpanel' => 'Sla Breach',
                'amplitude' => 'Sla Breach',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Payment method update event
            'payment_method_updated' => [
                'name' => 'payment_method_updated',
                'class' => PaymentMethodUpdatedEvent::class,
                'ga4' => 'payment_method_updated',
                'meta' => 'CustomEvent',
                'posthog' => 'payment_method_updated',
                'plausible' => null,
                'mixpanel' => 'Payment Method Updated',
                'amplitude' => 'Payment Method Updated',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Product-Led Growth events (v2.78.0)
            'feature_adopted' => [
                'name' => 'feature_adopted',
                'class' => FeatureAdoptedEvent::class,
                'ga4' => 'feature_adopted',
                'meta' => 'FeatureAdopted',
                'posthog' => 'feature_adopted',
                'plausible' => null,
                'mixpanel' => 'Feature Adopted',
                'amplitude' => 'Feature Adopted',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'expansion_revenue' => [
                'name' => 'expansion_revenue',
                'class' => ExpansionRevenueEvent::class,
                'ga4' => 'expansion_revenue',
                'meta' => 'Purchase',
                'posthog' => 'expansion_revenue',
                'plausible' => null,
                'mixpanel' => 'Expansion Revenue',
                'amplitude' => 'Expansion Revenue',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Data portability events (v2.86.0)
            'export' => [
                'name' => 'export',
                'class' => ExportEvent::class,
                'ga4' => 'file_download',
                'meta' => 'ExportData',
                'posthog' => 'export',
                'plausible' => null,
                'mixpanel' => 'Export',
                'amplitude' => 'Export',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'import' => [
                'name' => 'import',
                'class' => ImportEvent::class,
                'ga4' => 'file_upload',
                'meta' => 'ImportData',
                'posthog' => 'import',
                'plausible' => null,
                'mixpanel' => 'Import',
                'amplitude' => 'Import',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // GDPR compliance & account lifecycle (v2.90.0)
            'account_deleted' => [
                'name' => 'account_deleted',
                'class' => AccountDeletedEvent::class,
                'ga4' => 'account_deleted',
                'meta' => 'CustomEvent',
                'posthog' => 'account_deleted',
                'plausible' => null,
                'mixpanel' => 'Account Deleted',
                'amplitude' => 'Account Deleted',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Subscription lifecycle (v2.90.0)
            'subscription_created' => [
                'name' => 'subscription_created',
                'class' => SubscriptionCreatedEvent::class,
                'ga4' => 'subscription_created',
                'meta' => 'Subscribe',
                'posthog' => 'subscription_created',
                'plausible' => 'subscription',
                'mixpanel' => 'Subscription Created',
                'amplitude' => 'Subscription Created',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'subscription_cancelled' => [
                'name' => 'subscription_cancelled',
                'class' => SubscriptionCancelledEvent::class,
                'ga4' => 'subscription_cancelled',
                'meta' => 'CancelSubscription',
                'posthog' => 'subscription_cancelled',
                'plausible' => 'cancellation',
                'mixpanel' => 'Subscription Cancelled',
                'amplitude' => 'Subscription Cancelled',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Trial lifecycle (v2.90.0)
            'trial_expired' => [
                'name' => 'trial_expired',
                'class' => TrialExpiredEvent::class,
                'ga4' => 'trial_expired',
                'meta' => 'CustomEvent',
                'posthog' => 'trial_expired',
                'plausible' => null,
                'mixpanel' => 'Trial Expired',
                'amplitude' => 'Trial Expired',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Plan management (v2.90.0)
            'plan_changed' => [
                'name' => 'plan_changed',
                'class' => PlanChangedEvent::class,
                'ga4' => 'plan_changed',
                'meta' => 'CustomEvent',
                'posthog' => 'plan_changed',
                'plausible' => null,
                'mixpanel' => 'Plan Changed',
                'amplitude' => 'Plan Changed',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // GDPR compliance events (v2.93.0)
            'data_subject_access_request' => [
                'name' => 'data_subject_access_request',
                'class' => DataSubjectAccessRequestEvent::class,
                'ga4' => 'data_subject_access_request',
                'meta' => 'CustomEvent',
                'posthog' => 'data_subject_access_request',
                'plausible' => null,
                'mixpanel' => 'Data Subject Access Request',
                'amplitude' => 'Data Subject Access Request',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'data_erasure_completed' => [
                'name' => 'data_erasure_completed',
                'class' => DataErasureCompletedEvent::class,
                'ga4' => 'data_erasure_completed',
                'meta' => 'CustomEvent',
                'posthog' => 'data_erasure_completed',
                'plausible' => null,
                'mixpanel' => 'Data Erasure Completed',
                'amplitude' => 'Data Erasure Completed',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Product analytics & activation signals (v22.0.0)
            'first_value' => [
                'name' => 'first_value',
                'class' => FirstValueEvent::class,
                'ga4' => 'first_value',
                'meta' => 'CompleteRegistration',
                'posthog' => '$set',
                'plausible' => 'activation',
                'mixpanel' => 'First Value',
                'amplitude' => 'First Value',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'upcoming_renewal' => [
                'name' => 'upcoming_renewal',
                'class' => UpcomingRenewalEvent::class,
                'ga4' => 'upcoming_renewal',
                'meta' => 'CustomEvent',
                'posthog' => 'upcoming_renewal',
                'plausible' => null,
                'mixpanel' => 'Upcoming Renewal',
                'amplitude' => 'Upcoming Renewal',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'retention_risk' => [
                'name' => 'retention_risk',
                'class' => RetentionRiskEvent::class,
                'ga4' => 'retention_risk',
                'meta' => 'CustomEvent',
                'posthog' => 'retention_risk',
                'plausible' => null,
                'mixpanel' => 'Retention Risk',
                'amplitude' => 'Retention Risk',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'product_analytics' => [
                'name' => 'product_analytics',
                'class' => ProductAnalyticsEvent::class,
                'ga4' => 'product_analytics',
                'meta' => 'CustomEvent',
                'posthog' => 'product_analytics',
                'plausible' => null,
                'mixpanel' => 'Product Analytics',
                'amplitude' => 'Product Analytics',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // API telemetry (v27.0.0)
            'api_rate_limited' => [
                'name' => 'api_rate_limited',
                'class' => ApiRateLimitedEvent::class,
                'ga4' => 'api_rate_limited',
                'meta' => null,
                'posthog' => 'api_rate_limited',
                'plausible' => null,
                'mixpanel' => 'API Rate Limited',
                'amplitude' => 'API Rate Limited',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Webhook delivery monitoring (v27.0.0)
            'webhook_delivered' => [
                'name' => 'webhook_delivered',
                'class' => WebhookDeliveredEvent::class,
                'ga4' => 'webhook_delivered',
                'meta' => null,
                'posthog' => 'webhook_delivered',
                'plausible' => null,
                'mixpanel' => 'Webhook Delivered',
                'amplitude' => 'Webhook Delivered',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Integration engagement tracking (v27.0.0)
            'integration_used' => [
                'name' => 'integration_used',
                'class' => IntegrationUsedEvent::class,
                'ga4' => 'integration_used',
                'meta' => null,
                'posthog' => 'integration_used',
                'plausible' => null,
                'mixpanel' => 'Integration Used',
                'amplitude' => 'Integration Used',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Revenue waterfall tracking (v78.0.0)
            'mrr_movement' => [
                'name' => 'mrr_movement',
                'class' => MrrMovementEvent::class,
                'ga4' => 'mrr_movement',
                'meta' => null,
                'posthog' => 'mrr_movement',
                'plausible' => null,
                'mixpanel' => 'MRR Movement',
                'amplitude' => 'MRR Movement',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Feature flag analytics (v78.0.0)
            'feature_flag_evaluated' => [
                'name' => 'feature_flag_evaluated',
                'class' => FeatureFlagEvaluatedEvent::class,
                'ga4' => 'feature_flag_evaluated',
                'meta' => null,
                'posthog' => '$feature_flag',
                'plausible' => null,
                'mixpanel' => 'Feature Flag Evaluated',
                'amplitude' => 'Feature Flag Evaluated',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Growth milestone tracking (v78.0.0)
            'growth_milestone' => [
                'name' => 'growth_milestone',
                'class' => GrowthMilestoneEvent::class,
                'ga4' => 'growth_milestone',
                'meta' => null,
                'posthog' => 'growth_milestone',
                'plausible' => null,
                'mixpanel' => 'Growth Milestone',
                'amplitude' => 'Growth Milestone',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Onboarding funnel entry (v131.0.0)
            'onboarding_started' => [
                'name' => 'onboarding_started',
                'class' => OnboardingStartedEvent::class,
                'ga4' => 'onboarding_started',
                'meta' => 'CompleteRegistration',
                'posthog' => 'onboarding_started',
                'plausible' => 'activation',
                'mixpanel' => 'Onboarding Started',
                'amplitude' => 'Onboarding Started',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // B2B invitation conversion (v131.0.0)
            'invite_accepted' => [
                'name' => 'invite_accepted',
                'class' => InviteAcceptedEvent::class,
                'ga4' => 'invite_accepted',
                'meta' => 'Lead',
                'posthog' => 'invite_accepted',
                'plausible' => null,
                'mixpanel' => 'Invite Accepted',
                'amplitude' => 'Invite Accepted',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Security & account health signals (v131.0.0)
            'password_reset_requested' => [
                'name' => 'password_reset_requested',
                'class' => PasswordResetRequestedEvent::class,
                'ga4' => 'password_reset_requested',
                'meta' => 'CustomEvent',
                'posthog' => 'password_reset_requested',
                'plausible' => null,
                'mixpanel' => 'Password Reset Requested',
                'amplitude' => 'Password Reset Requested',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Payment method lifecycle (v131.0.0)
            'payment_method_removed' => [
                'name' => 'payment_method_removed',
                'class' => PaymentMethodRemovedEvent::class,
                'ga4' => 'payment_method_removed',
                'meta' => 'CustomEvent',
                'posthog' => 'payment_method_removed',
                'plausible' => null,
                'mixpanel' => 'Payment Method Removed',
                'amplitude' => 'Payment Method Removed',
                'tiktok' => null,
                'linkedin' => null,
            ],
            // Retention & activation signals (v93.0.0)
            'retention_cohort' => [
                'name' => 'retention_cohort',
                'class' => RetentionCohortEvent::class,
                'ga4' => 'retention_cohort',
                'meta' => 'CustomEvent',
                'posthog' => 'retention_cohort',
                'plausible' => null,
                'mixpanel' => 'Retention Cohort',
                'amplitude' => 'Retention Cohort',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'activation' => [
                'name' => 'activation',
                'class' => ActivationEvent::class,
                'ga4' => 'activation',
                'meta' => 'CompleteRegistration',
                'posthog' => 'activation',
                'plausible' => 'activation',
                'mixpanel' => 'Activation',
                'amplitude' => 'Activation',
                'tiktok' => 'CompleteRegistration',
                'linkedin' => null,
            ],
            // Customer success events (v135.0.0)
            'support_ticket_created' => [
                'name' => 'support_ticket_created',
                'class' => SupportTicketCreatedEvent::class,
                'ga4' => 'support_ticket_created',
                'meta' => 'CustomEvent',
                'posthog' => 'support_ticket_created',
                'plausible' => null,
                'mixpanel' => 'Support Ticket Created',
                'amplitude' => 'Support Ticket Created',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'nps_submitted' => [
                'name' => 'nps_submitted',
                'class' => NpsSubmittedEvent::class,
                'ga4' => 'nps_submitted',
                'meta' => 'CustomEvent',
                'posthog' => 'nps_submitted',
                'plausible' => null,
                'mixpanel' => 'NPS Submitted',
                'amplitude' => 'NPS Submitted',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'health_score_changed' => [
                'name' => 'health_score_changed',
                'class' => HealthScoreChangedEvent::class,
                'ga4' => 'health_score_changed',
                'meta' => 'CustomEvent',
                'posthog' => 'health_score_changed',
                'plausible' => null,
                'mixpanel' => 'Health Score Changed',
                'amplitude' => 'Health Score Changed',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'renewal_reminder_sent' => [
                'name' => 'renewal_reminder_sent',
                'class' => RenewalReminderSentEvent::class,
                'ga4' => 'renewal_reminder_sent',
                'meta' => 'CustomEvent',
                'posthog' => 'renewal_reminder_sent',
                'plausible' => null,
                'mixpanel' => 'Renewal Reminder Sent',
                'amplitude' => 'Renewal Reminder Sent',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'churn_interview' => [
                'name' => 'churn_interview',
                'class' => ChurnInterviewEvent::class,
                'ga4' => 'churn_interview',
                'meta' => 'CustomEvent',
                'posthog' => 'churn_interview',
                'plausible' => null,
                'mixpanel' => 'Churn Interview',
                'amplitude' => 'Churn Interview',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'customer_review' => [
                'name' => 'customer_review',
                'class' => CustomerReviewEvent::class,
                'ga4' => 'customer_review',
                'meta' => 'CustomEvent',
                'posthog' => 'customer_review',
                'plausible' => null,
                'mixpanel' => 'Customer Review',
                'amplitude' => 'Customer Review',
                'tiktok' => null,
                'linkedin' => null,
            ],
            'onboarding_call_completed' => [
                'name' => 'onboarding_call_completed',
                'class' => OnboardingCallCompletedEvent::class,
                'ga4' => 'onboarding_call_completed',
                'meta' => 'CustomEvent',
                'posthog' => 'onboarding_call_completed',
                'plausible' => null,
                'mixpanel' => 'Onboarding Call Completed',
                'amplitude' => 'Onboarding Call Completed',
                'tiktok' => null,
                'linkedin' => null,
            ],
        ];

        return self::$catalog;
    }

    /**
     * Get all SaaS event names.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::catalog());
    }

    /**
     * Get all SaaS event entries.
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
     * Get the total number of SaaS events.
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
        return 'saas';
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
     *
     * @since 35.0.0
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
     *
     * @since 35.0.0
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

    // ── Typed Shorthand Factory Methods (v151.0.0) ──────────────────

    /**
     * Build a typed sign_up event.
     *
     * @param  array{method?: string, user_id?: string}  $params
     * @return AnalyticsEvent
     */
    public static function signUp(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'sign_up', params: $params, category: 'saas');
    }

    /**
     * Build a typed login event.
     *
     * @param  array{method?: string, user_id?: string}  $params
     * @return AnalyticsEvent
     */
    public static function login(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'login', params: $params, category: 'saas');
    }

    /**
     * Build a typed logout event.
     *
     * @param  array<string, mixed>  $params
     * @return AnalyticsEvent
     */
    public static function logout(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'logout', params: $params, category: 'saas');
    }

    /**
     * Build a typed start_trial event.
     *
     * @param  array{plan_name?: string, trial_days?: int}  $params
     * @return AnalyticsEvent
     */
    public static function startTrial(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'start_trial', params: $params, category: 'saas');
    }

    /**
     * Build a typed subscribe (subscription created) event.
     *
     * @param  array{plan_name?: string, amount?: float, currency?: string, billing_cycle?: string}  $params
     * @return AnalyticsEvent
     */
    public static function subscribe(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'subscribe', params: $params, category: 'saas');
    }

    /**
     * Build a typed plan_upgrade event.
     *
     * @param  string  $fromPlan
     * @param  string  $toPlan
     * @param  array<string, mixed>  $extra
     * @return AnalyticsEvent
     */
    public static function planUpgrade(string $fromPlan, string $toPlan, array $extra = []): AnalyticsEvent
    {
        return new AnalyticsEvent(
            name: 'plan_upgrade',
            params: array_merge(['from_plan' => $fromPlan, 'to_plan' => $toPlan], $extra),
            category: 'saas',
        );
    }

    /**
     * Build a typed plan_downgrade event.
     *
     * @param  string  $fromPlan
     * @param  string  $toPlan
     * @param  array<string, mixed>  $extra
     * @return AnalyticsEvent
     */
    public static function planDowngrade(string $fromPlan, string $toPlan, array $extra = []): AnalyticsEvent
    {
        return new AnalyticsEvent(
            name: 'plan_downgrade',
            params: array_merge(['from_plan' => $fromPlan, 'to_plan' => $toPlan], $extra),
            category: 'saas',
        );
    }

    /**
     * Build a typed cancellation event.
     *
     * @param  array{plan_name?: string, reason?: string, feedback?: string}  $params
     * @return AnalyticsEvent
     */
    public static function cancellation(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'cancellation', params: $params, category: 'saas');
    }

    /**
     * Build a typed feature_used event.
     *
     * @param  string  $featureName
     * @param  array<string, mixed>  $extra
     * @return AnalyticsEvent
     */
    public static function featureUsed(string $featureName, array $extra = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'feature_used', params: array_merge(['feature_name' => $featureName], $extra), category: 'saas');
    }

    /**
     * Build a typed revenue_tracked event.
     *
     * @param  float  $amount
     * @param  string  $currency
     * @param  array<string, mixed>  $extra
     * @return AnalyticsEvent
     */
    public static function revenueTracked(float $amount, string $currency = 'USD', array $extra = []): AnalyticsEvent
    {
        return new AnalyticsEvent(
            name: 'revenue_tracked',
            params: array_merge(['amount' => $amount, 'currency' => $currency], $extra),
            category: 'saas',
        );
    }

    /**
     * Build a typed subscription_created event.
     *
     * @param  array{plan_name?: string, amount?: float, currency?: string}  $params
     * @return AnalyticsEvent
     */
    public static function subscriptionCreated(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'subscription_created', params: $params, category: 'saas');
    }

    /**
     * Build a typed subscription_cancelled event.
     *
     * @param  array{plan_name?: string, reason?: string}  $params
     * @return AnalyticsEvent
     */
    public static function subscriptionCancelled(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'subscription_cancelled', params: $params, category: 'saas');
    }

    /**
     * Build a typed trial_converted event.
     *
     * @param  array{plan_name?: string, amount?: float}  $params
     * @return AnalyticsEvent
     */
    public static function trialConverted(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'trial_converted', params: $params, category: 'saas');
    }

    /**
     * Build a typed trial_expired event.
     *
     * @param  array{plan_name?: string, outcome?: string}  $params
     * @return AnalyticsEvent
     */
    public static function trialExpired(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'trial_expired', params: $params, category: 'saas');
    }

    /**
     * Build a typed invite_accepted event.
     *
     * @param  array{inviter_id?: string, role?: string}  $params
     * @return AnalyticsEvent
     */
    public static function inviteAccepted(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'invite_accepted', params: $params, category: 'saas');
    }

    /**
     * Build a typed workspace_created event.
     *
     * @param  array{workspace_name?: string, members_count?: int}  $params
     * @return AnalyticsEvent
     */
    public static function workspaceCreated(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'workspace_created', params: $params, category: 'saas');
    }

    /**
     * Build a typed first_value event (activation signal).
     *
     * @param  array{event_name?: string, description?: string}  $params
     * @return AnalyticsEvent
     */
    public static function firstValue(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'first_value', params: $params, category: 'saas');
    }

    /**
     * Build a typed activation event.
     *
     * @param  array{trigger?: string, description?: string}  $params
     * @return AnalyticsEvent
     */
    public static function activation(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'activation', params: $params, category: 'saas');
    }

    /**
     * Build a typed payment_failed event.
     *
     * @param  array{amount?: float, currency?: string, reason?: string}  $params
     * @return AnalyticsEvent
     */
    public static function paymentFailed(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'payment_failed', params: $params, category: 'saas');
    }

    /**
     * Build a typed payment_succeeded event.
     *
     * @param  array{amount?: float, currency?: string, plan_name?: string}  $params
     * @return AnalyticsEvent
     */
    public static function paymentSucceeded(array $params = []): AnalyticsEvent
    {
        return new AnalyticsEvent(name: 'payment_succeeded', params: $params, category: 'saas');
    }

    /**
     * Build a typed AnalyticsEvent from any catalog entry by name.
     *
     * Generic factory — validates the event name against the catalog.
     *
     * @param  string  $name  Event name (must exist in this catalog)
     * @param  array<string, mixed>  $params
     * @return AnalyticsEvent
     * @throws \InvalidArgumentException if event name is not in this catalog
     */
    public static function build(string $name, array $params = []): AnalyticsEvent
    {
        if (! self::has($name)) {
            throw new \InvalidArgumentException("Unknown SaaS event: {$name}");
        }

        return new AnalyticsEvent(name: $name, params: $params, category: 'saas');
    }
}
