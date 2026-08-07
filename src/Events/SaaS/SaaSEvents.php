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
 * @phpstan-type EventEntry array{name: string, class: class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>, ga4: string, meta: string|null}
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
            ],
            'login' => [
                'name' => 'login',
                'class' => LoginEvent::class,
                'ga4' => 'login',
                'meta' => 'Login',
            ],
            'logout' => [
                'name' => 'logout',
                'class' => LogoutEvent::class,
                'ga4' => 'logout',
                'meta' => 'Logout',
            ],
            'start_trial' => [
                'name' => 'start_trial',
                'class' => TrialStartEvent::class,
                'ga4' => 'start_trial',
                'meta' => 'StartTrial',
            ],
            'end_trial' => [
                'name' => 'trial_end',
                'class' => TrialEndEvent::class,
                'ga4' => 'trial_end',
                'meta' => 'TrialEnded',
            ],
            'subscribe' => [
                'name' => 'subscribe',
                'class' => SubscriptionEvent::class,
                'ga4' => 'purchase',
                'meta' => 'Subscribe',
            ],
            'plan_upgrade' => [
                'name' => 'plan_upgrade',
                'class' => PlanUpgradeEvent::class,
                'ga4' => 'plan_upgrade',
                'meta' => 'PlanUpgrade',
            ],
            'plan_downgrade' => [
                'name' => 'plan_downgrade',
                'class' => PlanDowngradeEvent::class,
                'ga4' => 'plan_downgrade',
                'meta' => 'PlanDowngrade',
            ],
            'cancellation' => [
                'name' => 'cancellation',
                'class' => CancellationEvent::class,
                'ga4' => 'cancellation',
                'meta' => 'CancelSubscription',
            ],
            'feature_used' => [
                'name' => 'feature_used',
                'class' => FeatureUsedEvent::class,
                'ga4' => 'feature_used',
                'meta' => 'FeatureUsed',
            ],
            'revenue_tracked' => [
                'name' => 'revenue_tracked',
                'class' => RevenueEvent::class,
                'ga4' => 'revenue_tracked',
                'meta' => 'Purchase',
            ],
            // Cohort analytics events (typed classes)
            'cohort_assigned' => [
                'name' => 'cohort_assigned',
                'class' => CohortAssignedEvent::class,
                'ga4' => 'cohort_assigned',
                'meta' => 'CohortAssigned',
            ],
            'cohort_retention' => [
                'name' => 'cohort_retention',
                'class' => CohortRetentionEvent::class,
                'ga4' => 'cohort_retention',
                'meta' => 'CohortRetention',
            ],
            'cohort_churn' => [
                'name' => 'cohort_churn',
                'class' => CohortChurnEvent::class,
                'ga4' => 'cohort_churn',
                'meta' => 'CohortChurn',
            ],
            'cohort_conversion' => [
                'name' => 'cohort_conversion',
                'class' => CohortConversionEvent::class,
                'ga4' => 'cohort_conversion',
                'meta' => 'CohortConversion',
            ],
            'cohort_migration' => [
                'name' => 'cohort_migration',
                'class' => CohortMigrationEvent::class,
                'ga4' => 'cohort_migration',
                'meta' => 'CohortMigration',
            ],
            'cohort_engagement' => [
                'name' => 'cohort_engagement',
                'class' => CohortEngagementEvent::class,
                'ga4' => 'cohort_engagement',
                'meta' => 'CohortEngagement',
            ],
            'invite_sent' => [
                'name' => 'invite_sent',
                'class' => InviteSentEvent::class,
                'ga4' => 'invite_sent',
                'meta' => 'InviteSent',
            ],
            'integration_connected' => [
                'name' => 'integration_connected',
                'class' => IntegrationConnectedEvent::class,
                'ga4' => 'integration_connected',
                'meta' => 'IntegrationConnected',
            ],
            'subscription_renewal' => [
                'name' => 'subscription_renewal',
                'class' => SubscriptionRenewalEvent::class,
                'ga4' => 'subscription_renewal',
                'meta' => 'SubscriptionRenewal',
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
}
