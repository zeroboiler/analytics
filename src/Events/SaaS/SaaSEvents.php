<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
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
                'meta' => null,
            ],
            'logout' => [
                'name' => 'logout',
                'class' => LogoutEvent::class,
                'ga4' => 'logout',
                'meta' => null,
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
                'meta' => null,
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
                'meta' => null,
            ],
            'plan_downgrade' => [
                'name' => 'plan_downgrade',
                'class' => PlanDowngradeEvent::class,
                'ga4' => 'plan_downgrade',
                'meta' => null,
            ],
            'cancellation' => [
                'name' => 'cancellation',
                'class' => CancellationEvent::class,
                'ga4' => 'cancellation',
                'meta' => null,
            ],
            'feature_used' => [
                'name' => 'feature_used',
                'class' => FeatureUsedEvent::class,
                'ga4' => 'feature_used',
                'meta' => null,
            ],
            'revenue_tracked' => [
                'name' => 'revenue_tracked',
                'class' => RevenueEvent::class,
                'ga4' => 'revenue_tracked',
                'meta' => 'Purchase',
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
