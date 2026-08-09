<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

/**
 * Static catalog of all SaaS lifecycle analytics events.
 *
 * Provides a central registry for event names, classes, and metadata.
 * Use for validation, lookup, and bulk operations.
 *
 * @phpstan-type EventEntry array{name: string, class: class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>, ga4: string, meta: string|null, posthog: string, plausible: string|null}
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
            ],
            'login' => [
                'name' => 'login',
                'class' => LoginEvent::class,
                'ga4' => 'login',
                'meta' => 'Login',
                'posthog' => '$identify',
                'plausible' => 'login',
            ],
            'logout' => [
                'name' => 'logout',
                'class' => LogoutEvent::class,
                'ga4' => 'logout',
                'meta' => 'Logout',
                'posthog' => 'logout',
                'plausible' => 'logout',
            ],
            'start_trial' => [
                'name' => 'start_trial',
                'class' => TrialStartEvent::class,
                'ga4' => 'start_trial',
                'meta' => 'StartTrial',
                'posthog' => 'start_trial',
                'plausible' => 'trial_start',
            ],
            'trial_end' => [
                'name' => 'trial_end',
                'class' => TrialEndEvent::class,
                'ga4' => 'trial_end',
                'meta' => 'TrialEnded',
                'posthog' => 'trial_ended',
                'plausible' => 'trial_end',
            ],
            'subscribe' => [
                'name' => 'subscribe',
                'class' => SubscriptionEvent::class,
                'ga4' => 'purchase',
                'meta' => 'Subscribe',
                'posthog' => 'subscription_created',
                'plausible' => 'subscription',
            ],
            'plan_upgrade' => [
                'name' => 'plan_upgrade',
                'class' => PlanUpgradeEvent::class,
                'ga4' => 'plan_upgrade',
                'meta' => 'PlanUpgrade',
                'posthog' => 'plan_upgraded',
                'plausible' => 'plan_upgrade',
            ],
            'plan_downgrade' => [
                'name' => 'plan_downgrade',
                'class' => PlanDowngradeEvent::class,
                'ga4' => 'plan_downgrade',
                'meta' => 'PlanDowngrade',
                'posthog' => 'plan_downgraded',
                'plausible' => 'plan_downgrade',
            ],
            'cancellation' => [
                'name' => 'cancellation',
                'class' => CancellationEvent::class,
                'ga4' => 'cancellation',
                'meta' => 'CancelSubscription',
                'posthog' => 'cancellation',
                'plausible' => 'cancellation',
            ],
            'feature_used' => [
                'name' => 'feature_used',
                'class' => FeatureUsedEvent::class,
                'ga4' => 'feature_used',
                'meta' => 'FeatureUsed',
                'posthog' => 'feature_used',
                'plausible' => 'feature_used',
            ],
            'revenue_tracked' => [
                'name' => 'revenue_tracked',
                'class' => RevenueEvent::class,
                'ga4' => 'revenue_tracked',
                'meta' => 'Purchase',
                'posthog' => 'revenue_tracked',
                'plausible' => 'revenue',
            ],
            // Cohort analytics events (typed classes)
            'cohort_assigned' => [
                'name' => 'cohort_assigned',
                'class' => CohortAssignedEvent::class,
                'ga4' => 'cohort_assigned',
                'meta' => 'CohortAssigned',
                'posthog' => 'cohort_assigned',
                'plausible' => null,
            ],
            'cohort_retention' => [
                'name' => 'cohort_retention',
                'class' => CohortRetentionEvent::class,
                'ga4' => 'cohort_retention',
                'meta' => 'CohortRetention',
                'posthog' => 'cohort_retention',
                'plausible' => null,
            ],
            'cohort_churn' => [
                'name' => 'cohort_churn',
                'class' => CohortChurnEvent::class,
                'ga4' => 'cohort_churn',
                'meta' => 'CohortChurn',
                'posthog' => 'cohort_churn',
                'plausible' => null,
            ],
            'cohort_conversion' => [
                'name' => 'cohort_conversion',
                'class' => CohortConversionEvent::class,
                'ga4' => 'cohort_conversion',
                'meta' => 'CohortConversion',
                'posthog' => 'cohort_conversion',
                'plausible' => null,
            ],
            'cohort_migration' => [
                'name' => 'cohort_migration',
                'class' => CohortMigrationEvent::class,
                'ga4' => 'cohort_migration',
                'meta' => 'CohortMigration',
                'posthog' => 'cohort_migration',
                'plausible' => null,
            ],
            'cohort_engagement' => [
                'name' => 'cohort_engagement',
                'class' => CohortEngagementEvent::class,
                'ga4' => 'cohort_engagement',
                'meta' => 'CohortEngagement',
                'posthog' => 'cohort_engagement',
                'plausible' => null,
            ],
            'invite_sent' => [
                'name' => 'invite_sent',
                'class' => InviteSentEvent::class,
                'ga4' => 'invite_sent',
                'meta' => 'InviteSent',
                'posthog' => 'invite_sent',
                'plausible' => null,
            ],
            'integration_connected' => [
                'name' => 'integration_connected',
                'class' => IntegrationConnectedEvent::class,
                'ga4' => 'integration_connected',
                'meta' => 'IntegrationConnected',
                'posthog' => 'integration_connected',
                'plausible' => null,
            ],
            'subscription_renewal' => [
                'name' => 'subscription_renewal',
                'class' => SubscriptionRenewalEvent::class,
                'ga4' => 'subscription_renewal',
                'meta' => 'SubscriptionRenewal',
                'posthog' => 'subscription_renewed',
                'plausible' => 'subscription_renewal',
            ],
            // Account lifecycle events
            'account_activated' => [
                'name' => 'account_activated',
                'class' => AccountActivatedEvent::class,
                'ga4' => 'account_activated',
                'meta' => 'AccountActivated',
                'posthog' => 'account_activated',
                'plausible' => null,
            ],
            'account_deactivated' => [
                'name' => 'account_deactivated',
                'class' => AccountDeactivatedEvent::class,
                'ga4' => 'account_deactivated',
                'meta' => 'AccountDeactivated',
                'posthog' => 'account_deactivated',
                'plausible' => null,
            ],
            'password_changed' => [
                'name' => 'password_changed',
                'class' => PasswordChangedEvent::class,
                'ga4' => 'password_changed',
                'meta' => 'PasswordChanged',
                'posthog' => 'password_changed',
                'plausible' => null,
            ],
            'password_reset' => [
                'name' => 'password_reset',
                'class' => PasswordResetEvent::class,
                'ga4' => 'password_reset',
                'meta' => 'PasswordReset',
                'posthog' => 'password_reset',
                'plausible' => null,
            ],
            'profile_updated' => [
                'name' => 'profile_updated',
                'class' => ProfileUpdatedEvent::class,
                'ga4' => 'profile_updated',
                'meta' => 'ProfileUpdated',
                'posthog' => 'profile_updated',
                'plausible' => null,
            ],
            'email_verified' => [
                'name' => 'email_verified',
                'class' => EmailVerifiedEvent::class,
                'ga4' => 'email_verified',
                'meta' => 'EmailVerified',
                'posthog' => 'email_verified',
                'plausible' => null,
            ],
            // B2B / Team events
            'team_created' => [
                'name' => 'team_created',
                'class' => TeamCreatedEvent::class,
                'ga4' => 'team_created',
                'meta' => 'TeamCreated',
                'posthog' => 'team_created',
                'plausible' => null,
            ],
            'team_member_joined' => [
                'name' => 'team_member_joined',
                'class' => TeamMemberJoinedEvent::class,
                'ga4' => 'team_member_joined',
                'meta' => 'TeamMemberJoined',
                'posthog' => 'team_member_joined',
                'plausible' => null,
            ],
            'team_member_removed' => [
                'name' => 'team_member_removed',
                'class' => TeamMemberRemovedEvent::class,
                'ga4' => 'team_member_removed',
                'meta' => 'TeamMemberRemoved',
                'posthog' => 'team_member_removed',
                'plausible' => null,
            ],
            'role_changed' => [
                'name' => 'role_changed',
                'class' => RoleChangedEvent::class,
                'ga4' => 'role_changed',
                'meta' => 'RoleChanged',
                'posthog' => 'role_changed',
                'plausible' => null,
            ],
            // Billing events
            'payment_failed' => [
                'name' => 'payment_failed',
                'class' => PaymentFailedEvent::class,
                'ga4' => 'payment_failed',
                'meta' => 'PaymentFailed',
                'posthog' => 'payment_failed',
                'plausible' => null,
            ],
            'payment_succeeded' => [
                'name' => 'payment_succeeded',
                'class' => PaymentSucceededEvent::class,
                'ga4' => 'payment_succeeded',
                'meta' => 'PaymentSucceeded',
                'posthog' => 'payment_succeeded',
                'plausible' => null,
            ],
            'payment_method_added' => [
                'name' => 'payment_method_added',
                'class' => PaymentMethodAddedEvent::class,
                'ga4' => 'payment_method_added',
                'meta' => 'PaymentMethodAdded',
                'posthog' => 'payment_method_added',
                'plausible' => null,
            ],
            'invoice_generated' => [
                'name' => 'invoice_generated',
                'class' => InvoiceGeneratedEvent::class,
                'ga4' => 'invoice_generated',
                'meta' => 'InvoiceGenerated',
                'posthog' => 'invoice_generated',
                'plausible' => null,
            ],
            'credit_applied' => [
                'name' => 'credit_applied',
                'class' => CreditAppliedEvent::class,
                'ga4' => 'credit_applied',
                'meta' => 'CreditApplied',
                'posthog' => 'credit_applied',
                'plausible' => null,
            ],
            // Operational events
            'feature_limit_reached' => [
                'name' => 'feature_limit_reached',
                'class' => FeatureLimitReachedEvent::class,
                'ga4' => 'feature_limit_reached',
                'meta' => 'FeatureLimitReached',
                'posthog' => 'feature_limit_reached',
                'plausible' => null,
            ],
            'integration_failed' => [
                'name' => 'integration_failed',
                'class' => IntegrationFailedEvent::class,
                'ga4' => 'integration_failed',
                'meta' => 'IntegrationFailed',
                'posthog' => 'integration_failed',
                'plausible' => null,
            ],
            // Feature discovery & exposure
            'feature_impression' => [
                'name' => 'feature_impression',
                'class' => ImpressionEvent::class,
                'ga4' => 'feature_impression',
                'meta' => 'FeatureImpression',
                'posthog' => 'feature_impression',
                'plausible' => null,
            ],
            // Multi-tenant workspace creation
            'workspace_created' => [
                'name' => 'workspace_created',
                'class' => WorkspaceCreatedEvent::class,
                'ga4' => 'workspace_created',
                'meta' => 'WorkspaceCreated',
                'posthog' => 'workspace_created',
                'plausible' => null,
            ],
            // Conversion & growth events (v2.66.0)
            'trial_converted' => [
                'name' => 'trial_converted',
                'class' => TrialConvertedEvent::class,
                'ga4' => 'trial_converted',
                'meta' => 'Subscribe',
                'posthog' => 'trial_converted',
                'plausible' => 'conversion',
            ],
            'subscription_resumed' => [
                'name' => 'subscription_resumed',
                'class' => SubscriptionResumedEvent::class,
                'ga4' => 'subscription_resumed',
                'meta' => 'Subscribe',
                'posthog' => 'subscription_resumed',
                'plausible' => null,
            ],
            'milestone_reached' => [
                'name' => 'milestone_reached',
                'class' => MilestoneReachedEvent::class,
                'ga4' => 'milestone_reached',
                'meta' => 'MilestoneReached',
                'posthog' => 'milestone_reached',
                'plausible' => null,
            ],
            // Subscription lifecycle — pause/resume
            'subscription_paused' => [
                'name' => 'subscription_paused',
                'class' => SubscriptionPausedEvent::class,
                'ga4' => 'subscription_paused',
                'meta' => 'SubscriptionPaused',
                'posthog' => 'subscription_paused',
                'plausible' => null,
            ],
            // Revenue movement events
            'subscription_value_changed' => [
                'name' => 'subscription_value_changed',
                'class' => SubscriptionValueChangedEvent::class,
                'ga4' => 'subscription_value_changed',
                'meta' => 'SubscriptionValueChanged',
                'posthog' => 'subscription_value_changed',
                'plausible' => null,
            ],
            // Expansion & limit signals
            'usage_quota_reached' => [
                'name' => 'usage_quota_reached',
                'class' => UsageQuotaReachedEvent::class,
                'ga4' => 'usage_quota_reached',
                'meta' => 'UsageQuotaReached',
                'posthog' => 'usage_quota_reached',
                'plausible' => null,
            ],
            // Dunning / billing retry
            'billing_retry' => [
                'name' => 'billing_retry',
                'class' => BillingRetryEvent::class,
                'ga4' => 'billing_retry',
                'meta' => 'BillingRetry',
                'posthog' => 'billing_retry',
                'plausible' => null,
            ],
            // SLA compliance event
            'sla_breach' => [
                'name' => 'sla_breach',
                'class' => SlaBreachEvent::class,
                'ga4' => 'sla_breach',
                'meta' => 'CustomEvent',
                'posthog' => 'sla_breach',
                'plausible' => null,
            ],
            // Payment method update event
            'payment_method_updated' => [
                'name' => 'payment_method_updated',
                'class' => PaymentMethodUpdatedEvent::class,
                'ga4' => 'payment_method_updated',
                'meta' => 'CustomEvent',
                'posthog' => 'payment_method_updated',
                'plausible' => null,
            ],
            // Product-Led Growth events (v2.78.0)
            'feature_adopted' => [
                'name' => 'feature_adopted',
                'class' => FeatureAdoptedEvent::class,
                'ga4' => 'feature_adopted',
                'meta' => 'FeatureAdopted',
                'posthog' => 'feature_adopted',
                'plausible' => null,
            ],
            'expansion_revenue' => [
                'name' => 'expansion_revenue',
                'class' => ExpansionRevenueEvent::class,
                'ga4' => 'expansion_revenue',
                'meta' => 'Purchase',
                'posthog' => 'expansion_revenue',
                'plausible' => null,
            ],
            // Data portability events (v2.86.0)
            'export' => [
                'name' => 'export',
                'class' => ExportEvent::class,
                'ga4' => 'file_download',
                'meta' => 'ExportData',
                'posthog' => 'export',
                'plausible' => null,
            ],
            'import' => [
                'name' => 'import',
                'class' => ImportEvent::class,
                'ga4' => 'file_upload',
                'meta' => 'ImportData',
                'posthog' => 'import',
                'plausible' => null,
            ],
            // GDPR compliance & account lifecycle (v2.90.0)
            'account_deleted' => [
                'name' => 'account_deleted',
                'class' => AccountDeletedEvent::class,
                'ga4' => 'account_deleted',
                'meta' => 'CustomEvent',
                'posthog' => 'account_deleted',
                'plausible' => null,
            ],
            // Subscription lifecycle (v2.90.0)
            'subscription_created' => [
                'name' => 'subscription_created',
                'class' => SubscriptionCreatedEvent::class,
                'ga4' => 'subscription_created',
                'meta' => 'Subscribe',
                'posthog' => 'subscription_created',
                'plausible' => 'subscription',
            ],
            'subscription_cancelled' => [
                'name' => 'subscription_cancelled',
                'class' => SubscriptionCancelledEvent::class,
                'ga4' => 'subscription_cancelled',
                'meta' => 'CancelSubscription',
                'posthog' => 'subscription_cancelled',
                'plausible' => 'cancellation',
            ],
            // Trial lifecycle (v2.90.0)
            'trial_expired' => [
                'name' => 'trial_expired',
                'class' => TrialExpiredEvent::class,
                'ga4' => 'trial_expired',
                'meta' => 'CustomEvent',
                'posthog' => 'trial_expired',
                'plausible' => null,
            ],
            // Plan management (v2.90.0)
            'plan_changed' => [
                'name' => 'plan_changed',
                'class' => PlanChangedEvent::class,
                'ga4' => 'plan_changed',
                'meta' => 'CustomEvent',
                'posthog' => 'plan_changed',
                'plausible' => null,
            ],
            // GDPR compliance events (v2.93.0)
            'data_subject_access_request' => [
                'name' => 'data_subject_access_request',
                'class' => DataSubjectAccessRequestEvent::class,
                'ga4' => 'data_subject_access_request',
                'meta' => 'CustomEvent',
                'posthog' => 'data_subject_access_request',
                'plausible' => null,
            ],
            'data_erasure_completed' => [
                'name' => 'data_erasure_completed',
                'class' => DataErasureCompletedEvent::class,
                'ga4' => 'data_erasure_completed',
                'meta' => 'CustomEvent',
                'posthog' => 'data_erasure_completed',
                'plausible' => null,
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
}
