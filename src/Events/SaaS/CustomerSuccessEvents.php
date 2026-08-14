<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\SaaS\SupportTicketCreatedEvent;
use ZeroBoiler\Analytics\Events\SaaS\NpsSubmittedEvent;
use ZeroBoiler\Analytics\Events\SaaS\HealthScoreChangedEvent;
use ZeroBoiler\Analytics\Events\SaaS\RenewalReminderSentEvent;
use ZeroBoiler\Analytics\Events\SaaS\ChurnInterviewEvent;
use ZeroBoiler\Analytics\Events\SaaS\CustomerReviewEvent;
use ZeroBoiler\Analytics\Events\SaaS\OnboardingCallCompletedEvent;

/**
 * Static catalog of customer success analytics events.
 *
 * Customer success events track the health, satisfaction, and retention
 * signals critical for B2B SaaS products. These events feed into health
 * scoring, NPS trending, churn prediction, and expansion revenue models.
 *
 * @phpstan-type EventEntry array{name: string, class: class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>, ga4: string, meta: string|null, posthog: string, plausible: string|null, mixpanel: string, amplitude: string, tiktok: string|null, linkedin: string|null}
 *
 * @since 135.0.0
 */
final class CustomerSuccessEvents
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
     * Get all customer success event names.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::catalog());
    }

    /**
     * Get all customer success event entries.
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
     * Get the total number of customer success events.
     */
    public static function count(): int
    {
        return count(self::catalog());
    }

    /**
     * Get the category name for this catalog.
     */
    public static function category(): string
    {
        return 'customer_success';
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

    /**
     * Get the event class for a given event name.
     *
     * @return class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>|null
     */
    public static function classFor(string $name): ?string
    {
        return self::catalog()[$name]['class'] ?? null;
    }
}
