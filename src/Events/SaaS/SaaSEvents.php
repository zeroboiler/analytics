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
            'end_trial' => [
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
