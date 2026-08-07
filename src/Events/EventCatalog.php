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
     * Get all PostHog-compatible event names across all categories.
     *
     * Maps SaaS events to PostHog conventions ($signup, $identify, etc.)
     * and passes through all other event names as-is.
     *
     * @return list<string>
     */
    public static function allPosthogNames(): array
    {
        $posthogMap = \ZeroBoiler\Analytics\Support\EventTransformer::saasToPosthogEventMap();
        $names = [];

        foreach (self::names() as $name) {
            $names[] = $posthogMap[$name] ?? $name;
        }

        return array_values(array_unique($names));
    }

    /**
     * Get all Plausible-compatible event names across all categories.
     *
     * Filters out events not supported by Plausible (scroll_depth, click,
     * session events, form events) and maps page_view → pageview.
     *
     * @return list<string>
     */
    public static function allPlausibleNames(): array
    {
        $plausibleMap = \ZeroBoiler\Analytics\Support\EventTransformer::toPlausibleEventMap();
        $names = [];

        foreach (self::names() as $name) {
            if (isset($plausibleMap[$name])) {
                if ($plausibleMap[$name] !== null) {
                    $names[] = $plausibleMap[$name];
                }
                // null = not supported, skip
            } else {
                // Not explicitly mapped = supported as custom event
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
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
     * @return array{ga4: list<string>, meta: list<string>, posthog: list<string>, plausible: list<string>}
     */
    public static function byProvider(): array
    {
        $plausibleMap = \ZeroBoiler\Analytics\Support\EventTransformer::toPlausibleEventMap();
        $posthogMap = \ZeroBoiler\Analytics\Support\EventTransformer::saasToPosthogEventMap();

        $plausibleNames = [];
        $posthogNames = [];

        foreach (self::all() as $name => $entry) {
            if (isset($plausibleMap[$name]) && $plausibleMap[$name] !== null) {
                $plausibleNames[] = $plausibleMap[$name];
            } elseif (! isset($plausibleMap[$name])) {
                // Events not explicitly mapped are supported as custom Plausible events
                $plausibleNames[] = $name;
            }

            if (isset($posthogMap[$name])) {
                $posthogNames[] = $posthogMap[$name];
            } else {
                $posthogNames[] = $name;
            }
        }

        return [
            'ga4' => self::allGa4Names(),
            'meta' => self::allMetaNames(),
            'posthog' => array_values(array_unique($posthogNames)),
            'plausible' => array_values(array_unique($plausibleNames)),
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
}
