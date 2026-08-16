<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Sub-category registry for the SaaS event catalog.
 *
 * The SaaS catalog contains 65+ events spanning authentication, billing,
 * teams, onboarding, and growth. This class provides sub-category filtering
 * so consumers can query specific SaaS event groups without loading the
 * entire catalog.
 *
 * Sub-categories:
 *   - auth:        sign_up, login, logout, email_verified, password_*
 *   - subscription: subscribe, plan_upgrade/downgrade, cancellation, *_renewal, *_paused/resumed
 *   - trial:       start_trial, trial_end, trial_converted, trial_expired
 *   - billing:     payment_*, invoice_generated, credit_applied, billing_retry, *_value_changed
 *   - team:        team_created, team_member_*, role_changed
 *   - account:     account_activated/deactivated/deleted, profile_updated
 *   - growth:      milestone_reached, feature_adopted, expansion_revenue, feature_used
 *   - integration: integration_connected/failed, integration_used
 *   - compliance:  data_subject_access_request, data_erasure_completed
 *   - workspace:   workspace_created
 *   - cohort:      cohort_*
 *   - invitation:  invite_sent
 *   - notification: webhook_delivered, sla_breach
 *   - retention:   retention_risk, retention_cohort
 *
 * @since 98.0.0
 */
final class SaaSEventSubCategories
{
    /**
     * All SaaS event sub-categories with their event name lists.
     *
     * @var array<string, list<string>>
     */
    private const SUBCATEGORIES = [
        'auth' => [
            'sign_up', 'login', 'logout', 'email_verified',
            'password_changed', 'password_reset', 'password_reset_requested',
        ],
        'subscription' => [
            'subscribe', 'plan_upgrade', 'plan_downgrade', 'cancellation',
            'subscription_renewal', 'subscription_paused', 'subscription_resumed',
            'subscription_created', 'subscription_cancelled',
            'subscription_value_changed', 'plan_changed',
        ],
        'trial' => [
            'start_trial', 'trial_end', 'trial_converted', 'trial_expired',
        ],
        'billing' => [
            'payment_succeeded', 'payment_failed', 'payment_method_added',
            'payment_method_updated', 'payment_method_removed',
            'invoice_generated', 'credit_applied', 'billing_retry', 'revenue_tracked',
        ],
        'team' => [
            'team_created', 'team_member_joined', 'team_member_removed',
            'role_changed',
        ],
        'account' => [
            'account_activated', 'account_deactivated', 'account_deleted',
            'profile_updated', 'activation',
        ],
        'growth' => [
            'milestone_reached', 'feature_adopted', 'expansion_revenue',
            'feature_used', 'feature_limit_reached', 'usage_quota_reached',
            'growth_milestone',
        ],
        'integration' => [
            'integration_connected', 'integration_failed', 'integration_used',
        ],
        'compliance' => [
            'data_subject_access_request', 'data_erasure_completed',
        ],
        'workspace' => [
            'workspace_created',
        ],
        'cohort' => [
            'cohort_assigned', 'cohort_retention', 'cohort_churn',
            'cohort_conversion', 'cohort_migration', 'cohort_engagement',
            'retention_cohort',
        ],
        'invitation' => [
            'invite_sent',
        ],
        'notification' => [
            'webhook_delivered', 'sla_breach',
        ],
        'retention' => [
            'retention_risk',
        ],
        'export' => [
            'export', 'import',
        ],
        'customer_success' => [
            'support_ticket_created', 'nps_submitted', 'health_score_changed',
            'renewal_reminder_sent', 'churn_interview', 'customer_review',
            'onboarding_call_completed',
        ],
    ];

    /**
     * Get all sub-category names.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::SUBCATEGORIES);
    }

    /**
     * Get event names for a specific sub-category.
     *
     * @return list<string>
     */
    public static function events(string $subcategory): array
    {
        return self::SUBCATEGORIES[$subcategory] ?? [];
    }

    /**
     * Get all sub-categories with their event lists.
     *
     * @return array<string, list<string>>
     */
    public static function all(): array
    {
        return self::SUBCATEGORIES;
    }

    /**
     * Get the sub-category for a given event name.
     *
     * Returns null if the event doesn't belong to any sub-category.
     */
    public static function subcategoryFor(string $eventName): ?string
    {
        foreach (self::SUBCATEGORIES as $subcategory => $events) {
            if (in_array($eventName, $events, true)) {
                return $subcategory;
            }
        }

        return null;
    }

    /**
     * Check if an event belongs to a specific sub-category.
     */
    public static function belongsTo(string $eventName, string $subcategory): bool
    {
        return in_array($eventName, self::SUBCATEGORIES[$subcategory] ?? [], true);
    }

    /**
     * Get all events across all sub-categories as a flat list.
     *
     * @return list<string>
     */
    public static function allEventNames(): array
    {
        $all = [];

        foreach (self::SUBCATEGORIES as $events) {
            foreach ($events as $event) {
                $all[] = $event;
            }
        }

        return array_values(array_unique($all));
    }

    /**
     * Get event count per sub-category.
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        $counts = [];

        foreach (self::SUBCATEGORIES as $subcategory => $events) {
            $counts[$subcategory] = count($events);
        }

        return $counts;
    }

    /**
     * Get the full catalog entries for a sub-category.
     *
     * Returns SaaS catalog entries filtered by sub-category.
     *
     * @return list<array{name: string, class: class-string<AnalyticsEvent>, ga4: string, meta: string|null, category: string}>
     */
    public static function catalogEntries(string $subcategory): array
    {
        $eventNames = self::events($subcategory);

        if ($eventNames === []) {
            return [];
        }

        $catalog = SaaSEvents::all();
        $results = [];

        foreach ($eventNames as $name) {
            if (isset($catalog[$name])) {
                $entry = $catalog[$name];
                $entry['subcategory'] = $subcategory;
                $results[] = $entry;
            }
        }

        return $results;
    }

    /**
     * Get SaaS events grouped by sub-category with full entries.
     *
     * @return array<string, list<array{name: string, class: class-string<AnalyticsEvent>, ga4: string, meta: string|null, subcategory: string}>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::SUBCATEGORIES as $subcategory => $eventNames) {
            $grouped[$subcategory] = self::catalogEntries($subcategory);
        }

        return $grouped;
    }
}
