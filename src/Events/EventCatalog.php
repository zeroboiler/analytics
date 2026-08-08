<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events;

use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;

/**
 * Unified event catalog aggregating all event categories.
 *
 * Provides a single entry point for looking up event names, classes,
 * and provider mappings across Ecommerce, SaaS (including cohort), and Engagement categories.
 *
 * @phpstan-type EventEntry array{name: string, class: class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>, ga4: string, meta: string|null, category: string}
 */
final class EventCatalog
{
    /**
     * Get all events from all categories.
     *
     * @return array<string, EventEntry>
     */
    public static function all(): array
    {
        return array_merge(
            self::withCategory(EcommerceEvents::all(), 'ecommerce'),
            self::withCategory(SaaSEvents::all(), 'saas'),
            self::withCategory(EngagementEvents::all(), 'engagement'),
        );
    }

    /**
     * Get all event names across all categories.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /**
     * Get events grouped by category.
     *
     * @return array{ecommerce: array<string, EventEntry>, saas: array<string, EventEntry>, engagement: array<string, EventEntry>}
     */
    public static function byCategory(): array
    {
        return [
            'ecommerce' => self::withCategory(EcommerceEvents::all(), 'ecommerce'),
            'saas' => self::withCategory(SaaSEvents::all(), 'saas'),
            'engagement' => self::withCategory(EngagementEvents::all(), 'engagement'),
        ];
    }

    /**
     * Get a specific event entry by name.
     *
     * @return EventEntry|null
     */
    public static function get(string $name): ?array
    {
        return self::all()[$name] ?? null;
    }

    /**
     * Check if an event name exists in any catalog.
     */
    public static function has(string $name): bool
    {
        return EcommerceEvents::has($name)
            || SaaSEvents::has($name)
            || EngagementEvents::has($name);
    }

    /**
     * Get the category name for a given event name.
     *
     * @return 'ecommerce'|'saas'|'engagement'|null
     */
    public static function getCategory(string $name): ?string
    {
        if (EcommerceEvents::has($name)) {
            return 'ecommerce';
        }

        if (SaaSEvents::has($name)) {
            return 'saas';
        }

        if (EngagementEvents::has($name)) {
            return 'engagement';
        }

        return null;
    }

    /**
     * Get the total number of events across all categories.
     */
    public static function count(): int
    {
        return EcommerceEvents::count()
            + SaaSEvents::count()
            + EngagementEvents::count();
    }

    /**
     * Get all unique GA4 event names across all categories.
     *
     * @return list<string>
     */
    public static function allGa4Names(): array
    {
        return array_values(array_unique(array_merge(
            EcommerceEvents::ga4Names(),
            SaaSEvents::ga4Names(),
            EngagementEvents::ga4Names(),
        )));
    }

    /**
     * Get all unique Meta Pixel event names across all categories.
     *
     * @return list<string>
     */
    public static function allMetaNames(): array
    {
        return array_values(array_unique(array_filter(array_merge(
            EcommerceEvents::metaNames(),
            SaaSEvents::metaNames(),
            EngagementEvents::metaNames(),
        ))));
    }

    /**
     * Get all PostHog event names across all categories from catalog entries.
     *
     * Uses the native `posthog` field in each catalog entry rather than
     * relying on EventTransformer maps.
     *
     * @return list<string>
     */
    public static function allPosthogNames(): array
    {
        return array_values(array_unique(array_filter(array_merge(
            EcommerceEvents::posthogNames(),
            SaaSEvents::posthogNames(),
            EngagementEvents::posthogNames(),
        ))));
    }

    /**
     * Get all Plausible event names across all categories from catalog entries.
     *
     * Uses the native `plausible` field in each catalog entry.
     * Events with null plausible mapping are filtered out.
     *
     * @return list<string>
     */
    public static function allPlausibleNames(): array
    {
        return array_values(array_unique(array_filter(array_merge(
            EcommerceEvents::plausibleNames(),
            SaaSEvents::plausibleNames(),
            EngagementEvents::plausibleNames(),
        ))));
    }

    /**
     * Search events by name pattern (partial match).
     *
     * @return list<EventEntry>
     */
    public static function search(string $pattern): array
    {
        $results = [];

        foreach (self::all() as $name => $entry) {
            if (str_contains($name, strtolower($pattern))) {
                $results[] = $entry;
            }
        }

        return $results;
    }

    /**
     * Get events grouped by provider name.
     *
     * Returns arrays keyed by provider name with deduplicated event names.
     * Includes all supported providers: ga4, meta, posthog, plausible.
     *
     * Now uses native catalog fields (posthog, plausible) rather than
     * EventTransformer maps.
     *
     * @return array{ga4: list<string>, meta: list<string>, posthog: list<string>, plausible: list<string>}
     */
    public static function byProvider(): array
    {
        return [
            'ga4' => self::allGa4Names(),
            'meta' => self::allMetaNames(),
            'posthog' => self::allPosthogNames(),
            'plausible' => self::allPlausibleNames(),
        ];
    }

    /**
     * Get the event class for a given event name.
     *
     * @return class-string<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>|null
     */
    public static function classFor(string $name): ?string
    {
        return EcommerceEvents::classFor($name)
            ?? SaaSEvents::classFor($name)
            ?? EngagementEvents::classFor($name);
    }

    /**
     * Get all events in a specific category.
     *
     * @param  'ecommerce'|'saas'|'engagement'  $category
     * @return array<string, EventEntry>
     */
    public static function category(string $category): array
    {
        return match ($category) {
            'ecommerce' => self::withCategory(EcommerceEvents::all(), 'ecommerce'),
            'saas' => self::withCategory(SaaSEvents::all(), 'saas'),
            'engagement' => self::withCategory(EngagementEvents::all(), 'engagement'),
            default => [],
        };
    }

    /**
     * Get the required keys that every event entry must have.
     *
     * @return list<string>
     */
    public static function requiredKeys(): array
    {
        return ['name', 'class', 'ga4', 'category'];
    }

    /**
     * Validate the integrity of the entire event catalog.
     *
     * Checks that every event entry has the required keys, that all
     * event classes exist and extend AnalyticsEvent, and that there
     * are no duplicate names across categories.
     *
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public static function validate(): array
    {
        $errors = [];
        $warnings = [];
        $required = self::requiredKeys();
        $allEvents = self::all();
        $seenNames = [];

        foreach ($allEvents as $name => $entry) {
            // Check required keys
            foreach ($required as $key) {
                if (! array_key_exists($key, $entry)) {
                    $errors[] = "Event '{$name}' is missing required key '{$key}'";
                }
            }

            // Check class exists
            $className = $entry['class'] ?? null;
            if ($className !== null && ! class_exists($className)) {
                $errors[] = "Event '{$name}' references non-existent class '{$className}'";
            } elseif ($className !== null) {
                $baseClass = \ZeroBoiler\Analytics\DTO\AnalyticsEvent::class;
                if (! is_a($className, $baseClass, true)) {
                    $errors[] = "Event '{$name}' class '{$className}' does not extend AnalyticsEvent";
                }
            }

            // Check for duplicates
            if (in_array($name, $seenNames, true)) {
                $errors[] = "Duplicate event name detected: '{$name}'";
            }
            $seenNames[] = $name;

            // Check name matches key
            if (($entry['name'] ?? null) !== $name) {
                $warnings[] = "Event '{$name}' has mismatched name field: '{$entry['name']}'";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Annotate event entries with their category.
     *
     * @param  array<string, array{name: string, class: class-string, ga4: string, meta: string|null}>  $events
     * @return array<string, EventEntry>
     */
    private static function withCategory(array $events, string $category): array
    {
        return array_map(
            fn (array $entry): array => array_merge($entry, ['category' => $category]),
            $events,
        );
    }

    /**
     * Get the complete event → PostHog mapping table from catalog entries.
     *
     * Returns an associative array mapping every catalog event name to its
     * PostHog equivalent as defined in the native `posthog` field.
     *
     * @return array<string, string>
     */
    public static function allPosthogMappings(): array
    {
        $mappings = [];

        foreach (self::all() as $name => $entry) {
            $mappings[$name] = $entry['posthog'] ?? $name;
        }

        return $mappings;
    }

    /**
     * Get the PostHog event name for a given catalog event name.
     *
     * @return string PostHog event name (may include $ prefix for reserved events)
     */
    public static function posthogNameFor(string $name): string
    {
        $entry = self::get($name);

        return $entry['posthog'] ?? $name;
    }

    /**
     * Get the Plausible event name for a given catalog event name.
     *
     * @return string|null Plausible event name or null if not supported
     */
    public static function plausibleNameFor(string $name): ?string
    {
        $entry = self::get($name);

        return $entry['plausible'] ?? null;
    }

    /**
     * Get the complete event → Plausible mapping table from catalog entries.
     *
     * @return array<string, string|null>
     */
    public static function allPlausibleMappings(): array
    {
        $mappings = [];

        foreach (self::all() as $name => $entry) {
            $mappings[$name] = $entry['plausible'] ?? null;
        }

        return $mappings;
    }

    /**
     * Search events by provider event name (reverse lookup).
     *
     * Given a GA4/Meta/PostHog/Plausible event name, find all catalog
     * events that map to it. Useful for incoming webhook normalization.
     *
     * @param  string  $providerName  'ga4'|'meta'|'posthog'|'plausible'
     * @param  string  $providerEventName  The provider-specific event name
     * @return list<EventEntry>
     */
    public static function searchByProvider(string $providerName, string $providerEventName): array
    {
        $results = [];

        foreach (self::all() as $entry) {
            $mapped = $entry[$providerName] ?? null;

            if ($mapped !== null && $mapped === $providerEventName) {
                $results[] = $entry;
            }
        }

        return $results;
    }

    /**
     * Get revenue-related events across all categories.
     *
     * Returns events that directly impact revenue tracking: purchases, subscriptions,
     * refunds, trial conversions, billing events, and revenue tracking.
     * Useful for revenue dashboards and financial reporting.
     *
     * @return list<EventEntry>
     */
    public static function revenueEvents(): array
    {
        $revenueKeys = [
            'purchase', 'refund', 'subscribe', 'revenue_tracked',
            'payment_succeeded', 'payment_failed', 'invoice_generated',
            'credit_applied', 'trial_converted', 'plan_upgrade', 'plan_downgrade',
            'cancellation', 'subscription_renewal', 'subscription_resumed',
            'add_to_cart', 'remove_from_cart', 'begin_checkout', 'add_payment_info',
            'view_cart', 'add_to_wishlist',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $revenueKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get core SaaS lifecycle events for startup onboarding.
     *
     * Returns the essential subset of SaaS events every new deployment should
     * track: authentication, subscription lifecycle, trial, and key business events.
     *
     * @return list<EventEntry>
     */
    public static function coreSaaS(): array
    {
        $coreKeys = [
            'sign_up', 'login', 'logout', 'start_trial', 'trial_end',
            'subscribe', 'plan_upgrade', 'plan_downgrade', 'cancellation',
            'trial_converted', 'subscription_resumed',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $coreKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get a summary of the event catalog with counts and breakdown.
     *
     * @return array{total: int, ecommerce: int, saas: int, engagement: int, with_ga4: int, with_meta: int, with_posthog: int, with_plausible: int}
     */
    public static function summary(): array
    {
        $all = self::all();

        $withGa4 = 0;
        $withMeta = 0;
        $withPosthog = 0;
        $withPlausible = 0;

        foreach ($all as $entry) {
            if (isset($entry['ga4'])) {
                $withGa4++;
            }
            if (isset($entry['meta']) && $entry['meta'] !== null) {
                $withMeta++;
            }
            if (isset($entry['posthog']) && $entry['posthog'] !== null) {
                $withPosthog++;
            }
            if (isset($entry['plausible']) && $entry['plausible'] !== null) {
                $withPlausible++;
            }
        }

        return [
            'total' => count($all),
            'ecommerce' => EcommerceEvents::count(),
            'saas' => SaaSEvents::count(),
            'engagement' => EngagementEvents::count(),
            'with_ga4' => $withGa4,
            'with_meta' => $withMeta,
            'with_posthog' => $withPosthog,
            'with_plausible' => $withPlausible,
        ];
    }
}
