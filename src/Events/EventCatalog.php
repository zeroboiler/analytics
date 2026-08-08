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

    /**
     * Get events filtered by a specific category that have a PostHog mapping.
     *
     * Useful for identifying events that can be sent to PostHog from a
     * specific category without additional transformation.
     *
     * @param  'ecommerce'|'saas'|'engagement'  $category
     * @return list<EventEntry>
     */
    public static function withPosthogMapping(string $category): array
    {
        $events = self::category($category);

        return array_values(array_filter(
            $events,
            fn (array $entry): bool => ($entry['posthog'] ?? null) !== null,
        ));
    }

    /**
     * Get events filtered by a specific category that have a Plausible mapping.
     *
     * Useful for identifying events that can be sent to Plausible from a
     * specific category without additional transformation.
     *
     * @param  'ecommerce'|'saas'|'engagement'  $category
     * @return list<EventEntry>
     */
    public static function withPlausibleMapping(string $category): array
    {
        $events = self::category($category);

        return array_values(array_filter(
            $events,
            fn (array $entry): bool => ($entry['plausible'] ?? null) !== null,
        ));
    }

    /**
     * Get all SaaS engagement/funnel events for startup onboarding.
     *
     * Returns engagement events that form the core SaaS product usage funnel:
     * page views, scroll depth, clicks, forms, search, sharing, feature requests,
     * onboarding steps, and errors.
     *
     * @return list<EventEntry>
     */
    public static function engagementEvents(): array
    {
        $engagementKeys = [
            'page_view', 'scroll_depth', 'click', 'form_start', 'form_submit',
            'search', 'share', 'error', 'time_on_page', 'session_start',
            'session_end', 'outbound_click', 'content_engagement',
            'onboarding_step', 'feature_request', 'web_vitals', 'js_error',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $engagementKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get SaaS funnel events for conversion tracking.
     *
     * Returns the essential events that form a SaaS conversion funnel:
     * sign_up → trial_start → subscribe → plan_upgrade (expansion).
     * Useful for funnel visualization and drop-off analysis.
     *
     * @return list<EventEntry>
     */
    public static function saasFunnelEvents(): array
    {
        $funnelKeys = [
            'sign_up', 'login', 'start_trial', 'trial_converted', 'subscribe',
            'plan_upgrade', 'plan_downgrade', 'cancellation', 'subscription_resumed',
            'subscription_paused', 'milestone_reached',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $funnelKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get the count of events that have a specific provider mapping.
     *
     * @param  'ga4'|'meta'|'posthog'|'plausible'  $provider
     */
    public static function providerCount(string $provider): int
    {
        $all = self::all();
        $count = 0;

        foreach ($all as $entry) {
            $value = $entry[$provider] ?? null;
            if ($value !== null && $value !== '') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get all event names grouped by provider with counts.
     *
     * Returns a comprehensive breakdown of event coverage per provider,
     * useful for admin dashboards and readiness checks.
     *
     * @return array{ga4: list<string>, meta: list<string>, posthog: list<string>, plausible: list<string>, counts: array{ga4: int, meta: int, posthog: int, plausible: int}}
     */
    public static function providerCoverage(): array
    {
        return [
            'ga4' => self::allGa4Names(),
            'meta' => self::allMetaNames(),
            'posthog' => self::allPosthogNames(),
            'plausible' => self::allPlausibleNames(),
            'counts' => [
                'ga4' => self::providerCount('ga4'),
                'meta' => self::providerCount('meta'),
                'posthog' => self::providerCount('posthog'),
                'plausible' => self::providerCount('plausible'),
            ],
        ];
    }

    /**
     * Get business-critical events that should never be sampled or dropped.
     *
     * Revenue, authentication, and subscription events that directly impact
     * business metrics. Use with PriorityAwareFilter to ensure these events
     * are always dispatched regardless of sampling or deduplication settings.
     *
     * @return list<EventEntry>
     */
    public static function criticalEvents(): array
    {
        $criticalKeys = [
            // Revenue-impacting
            'purchase', 'refund', 'subscribe', 'revenue_tracked',
            'payment_succeeded', 'payment_failed', 'credit_applied',
            'invoice_generated', 'trial_converted', 'subscription_renewal',
            'subscription_value_changed', 'billing_retry',
            // Authentication
            'sign_up', 'login', 'logout',
            // Subscription lifecycle
            'plan_upgrade', 'plan_downgrade', 'cancellation',
            'subscription_paused', 'subscription_resumed',
            // E-commerce funnel
            'add_to_cart', 'begin_checkout', 'add_payment_info',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $criticalKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get events safe for probabilistic sampling in high-traffic scenarios.
     *
     * Returns engagement and low-criticality events that can be safely
     * sampled to reduce volume during traffic spikes without impacting
     * business metrics.
     *
     * @return list<EventEntry>
     */
    public static function samplableEvents(): array
    {
        $samplableKeys = [
            'page_view', 'scroll_depth', 'click', 'time_on_page',
            'content_engagement', 'screen_view', 'session_start',
            'session_end', 'outbound_click', 'notification',
            'timing', 'web_vitals', 'feature_impression',
            'campaign_attribution',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $samplableKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get the complete e-commerce checkout funnel events in order.
     *
     * Returns events representing a typical checkout flow:
     * view_item → add_to_cart → view_cart → begin_checkout →
     * add_payment_info → purchase → refund (post-purchase).
     * Useful for funnel visualization and drop-off analysis.
     *
     * @return list<EventEntry>
     */
    public static function checkoutFunnel(): array
    {
        $funnelKeys = [
            'view_item', 'select_item', 'add_to_cart', 'remove_from_cart',
            'view_cart', 'add_to_wishlist', 'begin_checkout',
            'add_payment_info', 'purchase', 'refund',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $funnelKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get the product-led activation funnel events.
     *
     * Returns events representing a user's journey from sign-up to
     * first meaningful value realization. Covers onboarding steps,
     * feature usage, and key engagement signals.
     *
     * @return list<EventEntry>
     */
    public static function activationFunnel(): array
    {
        $activationKeys = [
            'sign_up', 'email_verified', 'login', 'onboarding_step',
            'feature_used', 'page_view', 'search', 'form_submit',
            'share', 'content_engagement', 'milestone_reached',
            'start_trial', 'trial_converted', 'subscribe',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $activationKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get events that signal retention health or churn risk.
     *
     * Returns events indicating user engagement levels and churn signals:
     * account deactivation, cancellation, feature limit reached, usage quota
     * warnings, payment failures, and plan downgrades.
     *
     * @return list<EventEntry>
     */
    public static function retentionSignals(): array
    {
        $retentionKeys = [
            // Churn signals
            'cancellation', 'account_deactivated', 'plan_downgrade',
            'payment_failed', 'billing_retry', 'subscription_paused',
            'feature_limit_reached', 'usage_quota_reached',
            // Retention positive signals
            'login', 'feature_used', 'content_engagement',
            'milestone_reached', 'plan_upgrade', 'subscription_value_changed',
            'team_member_joined', 'integration_connected',
            'payment_method_added',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $retentionKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get all billing and revenue-related SaaS events.
     *
     * Returns events for financial lifecycle tracking: payments, invoices,
     * credits, billing retries, and subscription value changes. Ideal for
     * revenue dashboards, billing health monitors, and dunning analytics.
     *
     * @return list<EventEntry>
     */
    public static function billingEvents(): array
    {
        $billingKeys = [
            'payment_succeeded', 'payment_failed', 'payment_method_added',
            'invoice_generated', 'credit_applied', 'billing_retry',
            'subscription_value_changed', 'subscribe', 'subscription_renewal',
            'revenue_tracked', 'purchase',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $billingKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get product-led growth and expansion events.
     *
     * Returns events that indicate organic growth, product adoption, and
     * expansion revenue signals. Useful for PLG dashboards and growth analytics.
     *
     * @return list<EventEntry>
     */
    public static function productGrowthEvents(): array
    {
        $growthKeys = [
            // Acquisition
            'sign_up', 'start_trial', 'trial_converted', 'email_verified',
            // Engagement depth
            'feature_used', 'onboarding_step', 'content_engagement',
            'search', 'share', 'form_submit', 'milestone_reached',
            // Expansion
            'plan_upgrade', 'team_member_joined', 'team_created',
            'integration_connected', 'workspace_created', 'invite_sent',
            // E-commerce signals
            'add_to_cart', 'begin_checkout', 'purchase', 'add_to_wishlist',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $growthKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get all events involved in the complete user lifecycle.
     *
     * Combines acquisition, activation, retention, revenue, and referral
     * events for a unified lifecycle view. This is the AARRR (Pirate Metrics)
     * framework representation in the event catalog.
     *
     * @return list<EventEntry>
     */
    public static function allLifecycleEvents(): array
    {
        return array_values(array_unique(array_merge(
            self::activationFunnel(),
            self::checkoutFunnel(),
            self::saasFunnelEvents(),
            self::retentionSignals(),
            self::billingEvents(),
            self::productGrowthEvents(),
        ), SORT_REGULAR));
    }
}
