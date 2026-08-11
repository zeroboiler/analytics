<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;

/**
 * Unified event catalog aggregating all event categories.
 *
 * Provides a single entry point for looking up event names, classes,
 * and provider mappings across Ecommerce, SaaS (including cohort), Engagement,
 * Security, Uptime, and registered plugin categories.
 *
 * @phpstan-type EventEntry array{name: string, class: class-string<AnalyticsEvent>, ga4: string, meta: string|null, category: string}
 *
 * @since 1.0.0
 */
final class EventCatalog
{
    /**
     * Get all events from all categories (built-in only, no plugins).
     *
     * @return array<string, EventEntry>
     */
    public static function all(): array
    {
        return array_merge(
            self::withCategory(EcommerceEvents::all(), 'ecommerce'),
            self::withCategory(SaaSEvents::all(), 'saas'),
            self::withCategory(EngagementEvents::all(), 'engagement'),
            self::withCategory(SecurityEvents::all(), 'security'),
            self::withCategory(UptimeEvents::all(), 'uptime'),
        );
    }

    /**
     * Get all events including those registered via EventPluginRegistry.
     *
     * Accepts an optional array of plugin events to merge into the catalog.
     * Plugin events with names matching built-in events are skipped (built-in wins).
     *
     * @param  array<string, EventEntry>  $pluginEvents  Events from EventPluginRegistry::catalogEvents()
     * @return array<string, EventEntry>
     *
     * @since 7.8.0
     */
    public static function allWithPlugins(array $pluginEvents = []): array
    {
        $builtin = self::all();

        if ($pluginEvents === []) {
            return $builtin;
        }

        // Plugin events that don't conflict with built-in names
        $merged = $builtin;
        foreach ($pluginEvents as $name => $entry) {
            if (! isset($builtin[$name])) {
                $merged[$name] = $entry;
            }
        }

        return $merged;
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
            'security' => self::withCategory(SecurityEvents::all(), 'security'),
            'uptime' => self::withCategory(UptimeEvents::all(), 'uptime'),
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
            || EngagementEvents::has($name)
            || SecurityEvents::has($name)
            || UptimeEvents::has($name);
    }

    /**
     * Get the category name for a given event name.
     *
     * @return 'ecommerce'|'saas'|'engagement'|'security'|'uptime'|null
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

        if (SecurityEvents::has($name)) {
            return 'security';
        }

        if (UptimeEvents::has($name)) {
            return 'uptime';
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
            + EngagementEvents::count()
            + SecurityEvents::count()
            + UptimeEvents::count();
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
            SecurityEvents::ga4Names(),
            UptimeEvents::ga4Names(),
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
            SecurityEvents::metaNames(),
            UptimeEvents::metaNames(),
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
            SecurityEvents::posthogNames(),
            UptimeEvents::posthogNames(),
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
            SecurityEvents::plausibleNames(),
            UptimeEvents::plausibleNames(),
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
            ?? EngagementEvents::classFor($name)
            ?? SecurityEvents::classFor($name)
            ?? UptimeEvents::classFor($name);
    }

    /**
     * Get all events in a specific category.
     *
     * @param  'ecommerce'|'saas'|'engagement'|'security'|'uptime'  $category
     * @return array<string, EventEntry>
     */
    public static function category(string $category): array
    {
        return match ($category) {
            'ecommerce' => self::withCategory(EcommerceEvents::all(), 'ecommerce'),
            'saas' => self::withCategory(SaaSEvents::all(), 'saas'),
            'engagement' => self::withCategory(EngagementEvents::all(), 'engagement'),
            'security' => self::withCategory(SecurityEvents::all(), 'security'),
            'uptime' => self::withCategory(UptimeEvents::all(), 'uptime'),
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
     * Get GDPR-related events for compliance tracking.
     *
     * Returns events that carry PII, involve consent, or represent
     * data subject rights (account deletion, consent changes, erasure).
     * Essential for GDPR Article 30 records of processing activities.
     *
     * @return list<EventEntry>
     */
    public static function gdprEvents(): array
    {
        $gdprKeys = [
            'sign_up', 'login', 'account_activated', 'account_deactivated',
            'account_deleted', 'password_changed', 'password_reset',
            'email_verified', 'profile_updated', 'export', 'import',
            'cancellation', 'subscription_cancelled',
            // GDPR consent lifecycle (v2.93.0)
            'consent_granted', 'consent_withdrawn',
            // Data subject rights (v2.93.0)
            'data_subject_access_request', 'data_erasure_completed',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $gdprKeys),
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
            'security' => SecurityEvents::count(),
            'uptime' => UptimeEvents::count(),
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
            'payment_method_updated', 'invoice_generated', 'credit_applied',
            'billing_retry', 'subscription_value_changed', 'subscribe',
            'subscription_created', 'subscription_cancelled', 'subscription_renewal',
            'subscription_resumed', 'subscription_paused',
            'revenue_tracked', 'purchase', 'expansion_revenue', 'plan_changed',
            'plan_upgrade', 'plan_downgrade', 'cancellation',
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
            // PLG-specific (v2.78.0)
            'feature_adopted', 'expansion_revenue',
            // Data portability (v2.86.0)
            'export', 'import',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $growthKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get product-led growth (PLG) specific events.
     *
     * Returns events that directly measure PLG motion: feature adoption,
     * expansion revenue, trial conversion, team expansion, and organic
     * sharing signals. These events form the PLG analytics framework.
     *
     * @return list<EventEntry>
     */
    public static function plgEvents(): array
    {
        $plgKeys = [
            'feature_adopted', 'expansion_revenue',
            'sign_up', 'start_trial', 'trial_converted',
            'plan_upgrade', 'team_member_joined', 'team_created',
            'invite_sent', 'share', 'milestone_reached',
            'integration_connected', 'feature_used',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $plgKeys),
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
            // Data portability
            array_filter(
                array_map(fn (string $key): ?array => self::get($key), ['export', 'import']),
                fn (?array $entry): bool => $entry !== null,
            ),
        ), SORT_REGULAR));
    }

    /**
     * Get events classified as industry-standard SaaS instrumentation.
     *
     * Returns the minimum set of events that an industry-standard SaaS
     * product should instrument. Covers the full AARRR framework with
     * priority ordering (critical first).
     *
     * Critical events are listed first, followed by high-priority,
     * medium-priority, and low-priority events within each AARRR category.
     *
     * @return array{critical: list<EventEntry>, high: list<EventEntry>, medium: list<EventEntry>, low: list<EventEntry>, all: list<EventEntry>, count: int}
     */
    public static function industryStandard(): array
    {
        $criticalKeys = [
            'sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade',
            'cancellation', 'page_view', 'purchase', 'payment_succeeded',
            'payment_failed', 'trial_converted',
        ];

        $highKeys = [
            'email_verified', 'onboarding_step', 'feature_used', 'add_to_cart',
            'begin_checkout', 'add_payment_info', 'refund', 'plan_downgrade',
            'subscription_renewal', 'subscription_resumed', 'trial_end',
            'subscription_paused', 'view_item', 'search', 'form_submit',
            'share', 'revenue_tracked', 'invoice_generated', 'billing_retry',
            'subscription_value_changed',
        ];

        $mediumKeys = [
            'scroll_depth', 'time_on_page', 'session_start', 'session_end',
            'content_engagement', 'form_start', 'notification', 'milestone_reached',
            'team_created', 'team_member_joined', 'integration_connected',
            'invite_sent', 'profile_updated', 'view_cart', 'remove_from_cart',
            'add_to_wishlist', 'select_item', 'credit_applied', 'payment_method_added',
            'workspace_created', 'feature_adopted', 'expansion_revenue',
            'feedback', 'goal_conversion',
            // Data portability (v2.86.0)
            'export', 'import',
        ];

        $lowKeys = [
            'click', 'outbound_click', 'file_download', 'video_play',
            'screen_view', 'web_vitals', 'timing', 'js_error', 'error',
            'ab_test_exposure', 'ad_click', 'feature_impression',
            'select_promotion', 'view_promotion', 'role_changed',
            'team_member_removed', 'logout', 'feature_limit_reached',
            'usage_quota_reached', 'password_changed', 'password_reset',
            'account_activated', 'account_deactivated', 'campaign_attribution',
        ];

        $toEntries = static fn (array $keys): array => array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $keys),
            fn (?array $entry): bool => $entry !== null,
        ));

        $critical = $toEntries($criticalKeys);
        $high = $toEntries($highKeys);
        $medium = $toEntries($mediumKeys);
        $low = $toEntries($lowKeys);
        $all = array_values(array_unique(array_merge($critical, $high, $medium, $low), SORT_REGULAR));

        return [
            'critical' => $critical,
            'high' => $high,
            'medium' => $medium,
            'low' => $low,
            'all' => $all,
            'count' => count($all),
        ];
    }

    /**
     * Get events related to conversion optimization.
     *
     * Returns events that represent key conversion milestones across
     * signup, trial, subscription, and e-commerce funnels. Useful for
     * conversion rate optimization (CRO) dashboards.
     *
     * @return list<EventEntry>
     */
    public static function conversionEvents(): array
    {
        $conversionKeys = [
            // SaaS conversion funnel
            'sign_up', 'email_verified', 'start_trial', 'trial_converted',
            'subscribe', 'plan_upgrade', 'feature_adopted',
            // E-commerce conversion funnel
            'view_item', 'add_to_cart', 'view_cart', 'begin_checkout',
            'add_payment_info', 'purchase',
            // Engagement-driven conversion signals
            'form_submit', 'goal_conversion', 'milestone_reached',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $conversionKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get events related to cart and checkout abandonment.
     *
     * Returns events that signal user intent but not completion — the
     * gap between "interested" and "converted". Critical for recovery
     * campaigns and funnel optimization.
     *
     * @return list<EventEntry>
     */
    public static function abandonedEvents(): array
    {
        $abandonedKeys = [
            'abandoned_cart', 'checkout_abandon',
            'remove_from_cart', 'view_cart',
            // Partial engagement signals
            'form_start', 'begin_checkout', 'add_payment_info',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $abandonedKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get the AARRR category classification for a given event name.
     *
     * Uses the EventPriorityCalculator's classification to determine
     * which stage of the AARRR framework an event belongs to.
     *
     * @return 'acquisition'|'activation'|'retention'|'revenue'|'referral'|'operational'
     */
    public static function eventCategory(string $eventName): string
    {
        $calculator = new \ZeroBoiler\Analytics\Services\EventPriorityCalculator;

        return $calculator->classify($eventName);
    }

    /**
     * Get the priority level of a given event name.
     *
     * @return 'critical'|'high'|'medium'|'low'
     */
    public static function eventPriority(string $eventName): string
    {
        $calculator = new \ZeroBoiler\Analytics\Services\EventPriorityCalculator;

        return $calculator->getEventPriority($eventName);
    }

    /**
     * Get the recommended essential SaaS instrumentation set.
     *
     * Returns a comprehensive but focused set of events that every SaaS
     * product should instrument for production-grade analytics. This is
     * the recommended starting point for new SaaS deployments — broader
     * than coreSaaS() which only covers the bare minimum.
     *
     * Covers authentication, subscription lifecycle, trial, billing,
     * feature usage, engagement, and key revenue events.
     *
     * @return array{events: list<EventEntry>, categories: array<string, int>, count: int, ga4_coverage: int}
     */
    public static function saasEssential(): array
    {
        $essentialKeys = [
            // ── Authentication (must-have) ─────────────────
            'sign_up', 'login', 'logout', 'email_verified',
            // ── Subscription Lifecycle ──────────────────────
            'subscribe', 'plan_upgrade', 'plan_downgrade', 'cancellation',
            'subscription_renewal', 'subscription_paused', 'subscription_resumed',
            // ── Trial ──────────────────────────────────────
            'start_trial', 'trial_end', 'trial_converted',
            // ── Revenue ────────────────────────────────────
            'payment_succeeded', 'payment_failed', 'invoice_generated',
            'revenue_tracked', 'credit_applied', 'subscription_value_changed',
            'billing_retry',
            // ── Feature & Engagement ────────────────────────
            'feature_used', 'onboarding_step', 'milestone_reached',
            'page_view', 'search', 'form_submit', 'share',
            // ── Account & Team ──────────────────────────────
            'account_activated', 'account_deactivated', 'profile_updated',
            'team_created', 'team_member_joined', 'invite_sent',
            // ── Integration ─────────────────────────────────
            'integration_connected', 'integration_failed',
            // ── E-commerce (if applicable) ────────────────
            'view_item', 'add_to_cart', 'begin_checkout', 'purchase',
            'refund', 'add_payment_info',
            // ── Data Portability ────────────────────────
            'export', 'import',
        ];

        $events = array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $essentialKeys),
            fn (?array $entry): bool => $entry !== null,
        ));

        $categories = ['ecommerce' => 0, 'saas' => 0, 'engagement' => 0];
        $ga4Count = 0;

        foreach ($events as $entry) {
            $cat = $entry['category'] ?? 'engagement';
            if (isset($categories[$cat])) {
                $categories[$cat]++;
            }
            if (isset($entry['ga4']) && $entry['ga4'] !== '') {
                $ga4Count++;
            }
        }

        return [
            'events' => $events,
            'categories' => $categories,
            'count' => count($events),
            'ga4_coverage' => $ga4Count,
        ];
    }

    /**
     * Get the recommended instrumentation for a given SaaS maturity target.
     *
     * Returns a graded recommendation of events to instrument based on
     * the desired maturity level. Each level includes all events from
     * the level below plus additional events.
     *
     * Levels: 'starter' (essential 25 events), 'growth' (40 events),
     * 'enterprise' (60+ events), 'complete' (all catalog events).
     *
     * @param  'starter'|'growth'|'enterprise'|'complete'  $level
     * @return array{level: string, events: list<EventEntry>, count: int, next_level: string|null}
     */
    public static function recommendedInstrumentation(string $level = 'starter'): array
    {
        $starterKeys = [
            'sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade',
            'cancellation', 'page_view', 'search', 'form_submit',
            'payment_succeeded', 'payment_failed', 'purchase',
            'trial_converted', 'feature_used', 'milestone_reached',
            'onboarding_step', 'email_verified', 'revenue_tracked',
            'error', 'subscription_renewal',
        ];

        $growthKeys = [
            'logout', 'plan_downgrade', 'subscription_paused', 'subscription_resumed',
            'trial_end', 'invoice_generated', 'credit_applied', 'billing_retry',
            'subscription_value_changed', 'add_to_cart', 'begin_checkout',
            'add_payment_info', 'refund', 'view_item', 'share', 'content_engagement',
            'team_created', 'team_member_joined', 'invite_sent',
            'integration_connected', 'account_activated', 'account_deactivated',
            'scroll_depth', 'time_on_page', 'session_start', 'session_end',
            'payment_method_added', 'profile_updated',
        ];

        $enterpriseKeys = [
            'form_start', 'notification', 'outbound_click', 'file_download',
            'video_play', 'ab_test_exposure', 'campaign_attribution',
            'role_changed', 'team_member_removed',
            'password_changed', 'password_reset', 'feature_limit_reached',
            'usage_quota_reached', 'feature_request', 'goal_conversion',
            'view_cart', 'remove_from_cart', 'add_to_wishlist', 'select_item',
            'select_promotion', 'view_promotion', 'web_vitals', 'timing',
            'feedback', 'sla_breach', 'payment_method_updated',
            'workspace_created', 'expansion_revenue', 'feature_adopted',
            'integration_failed', 'abandoned_cart', 'checkout_abandon',
            // Data portability (v2.86.0)
            'export', 'import',
        ];

        $levels = [
            'starter' => $starterKeys,
            'growth' => [...$starterKeys, ...$growthKeys],
            'enterprise' => [...$starterKeys, ...$growthKeys, ...$enterpriseKeys],
            'complete' => self::names(),
        ];

        $validLevels = ['starter', 'growth', 'enterprise', 'complete'];
        $safeLevel = in_array($level, $validLevels, true) ? $level : 'starter';

        $keys = $levels[$safeLevel];
        $events = array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $keys),
            fn (?array $entry): bool => $entry !== null,
        ));

        $nextMap = [
            'starter' => 'growth',
            'growth' => 'enterprise',
            'enterprise' => 'complete',
            'complete' => null,
        ];

        return [
            'level' => $safeLevel,
            'events' => $events,
            'count' => count($events),
            'next_level' => $nextMap[$safeLevel],
        ];
    }

    /**
     * Get B2B/team and organization-level events.
     *
     * Returns events related to multi-tenant and team collaboration:
     * team creation, member management, role changes, workspace events,
     * and invite tracking. Ideal for B2B SaaS products.
     *
     * @return list<EventEntry>
     */
    public static function b2bTeamEvents(): array
    {
        $b2bKeys = [
            'team_created', 'team_member_joined', 'team_member_removed',
            'role_changed', 'workspace_created', 'invite_sent',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $b2bKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get account lifecycle events (activation, deactivation, security, profile).
     *
     * Returns events tracking the full account lifecycle from activation
     * through security events, profile changes, and deactivation.
     * Useful for account health dashboards and security monitoring.
     *
     * @return list<EventEntry>
     */
    public static function accountLifecycleEvents(): array
    {
        $accountKeys = [
            'account_activated', 'account_deactivated', 'email_verified',
            'password_changed', 'password_reset', 'profile_updated',
            'login', 'logout', 'sign_up',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $accountKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Build a complete cross-provider mapping matrix for all catalog events.
     *
     * Returns every event's name mapped to its GA4, Meta, PostHog, and Plausible
     * equivalents in a single lookup structure. Ideal for admin dashboards
     * and provider configuration UIs.
     *
     * @return array<string, array{ga4: string, meta: string|null, posthog: string, plausible: string|null, category: string}>
     */
    public static function allProviderMappingsMatrix(): array
    {
        $matrix = [];

        foreach (self::all() as $name => $entry) {
            $matrix[$name] = [
                'ga4' => $entry['ga4'] ?? $name,
                'meta' => $entry['meta'] ?? null,
                'posthog' => $entry['posthog'] ?? $name,
                'plausible' => $entry['plausible'] ?? null,
                'category' => $entry['category'] ?? 'unknown',
            ];
        }

        return $matrix;
    }

    /**
     * Get the absolute minimum quick-start event set for day-one SaaS instrumentation.
     *
     * Returns just 12 essential events that cover the critical SaaS funnel:
     * acquisition, activation, revenue, and retention. This is the "hello world"
     * set — instrument these on day one and you have actionable analytics immediately.
     *
     * @return array{events: list<EventEntry>, count: int, categories: array<string, int>, funnel_coverage: array{signup: bool, trial: bool, revenue: bool, engagement: bool}}
     */
    public static function quickStart(): array
    {
        $quickStartKeys = [
            'sign_up', 'login', 'start_trial', 'subscribe', 'purchase',
            'cancellation', 'page_view', 'search', 'form_submit',
            'feature_used', 'error', 'revenue_tracked',
        ];

        $events = array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $quickStartKeys),
            fn (?array $entry): bool => $entry !== null,
        ));

        $categories = ['ecommerce' => 0, 'saas' => 0, 'engagement' => 0];
        foreach ($events as $entry) {
            $cat = $entry['category'] ?? 'engagement';
            if (isset($categories[$cat])) {
                $categories[$cat]++;
            }
        }

        $names = array_column($events, 'name');

        return [
            'events' => $events,
            'count' => count($events),
            'categories' => $categories,
            'funnel_coverage' => [
                'signup' => in_array('sign_up', $names, true),
                'trial' => in_array('start_trial', $names, true),
                'revenue' => in_array('subscribe', $names, true) || in_array('purchase', $names, true),
                'engagement' => in_array('feature_used', $names, true),
            ],
        ];
    }

    /**
     * Get events that are safe to track without collecting any PII.
     *
     * Returns events that typically contain only behavioral/aggregate data
     * with no personal identifiers. Ideal for privacy-first implementations
     * and cookieless tracking scenarios.
     *
     * @return list<EventEntry>
     */
    public static function privacySafeEvents(): array
    {
        $safeKeys = [
            'page_view', 'scroll_depth', 'click', 'search', 'share',
            'screen_view', 'outbound_click', 'file_download', 'video_play',
            'content_engagement', 'web_vitals', 'timing', 'ab_test_exposure',
            'session_start', 'session_end', 'time_on_page',
            'feature_impression', 'notification',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $safeKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get events that typically contain or imply PII and need extra consent gating.
     *
     * Returns events that may carry personal information: authentication,
     * profile, billing, and identity-related events. These should be
     * gated behind explicit user consent beyond the default analytics consent.
     *
     * @return list<EventEntry>
     */
    public static function gdprSensitiveEvents(): array
    {
        $sensitiveKeys = [
            'sign_up', 'login', 'logout', 'email_verified', 'password_changed',
            'password_reset', 'profile_updated', 'account_activated',
            'account_deactivated', 'payment_failed', 'payment_succeeded',
            'payment_method_added', 'payment_method_updated', 'invoice_generated',
            'credit_applied', 'identify',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $sensitiveKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get events specifically related to user acquisition channels and sources.
     *
     * Returns events that measure how users discover and enter the product:
     * sign-ups from different channels, trial starts, referral conversions,
     * and campaign-driven registrations. Useful for marketing analytics and
     * CAC (Customer Acquisition Cost) calculations.
     *
     * @return list<EventEntry>
     */
    public static function saasAcquisitionEvents(): array
    {
        $acquisitionKeys = [
            'sign_up', 'email_verified', 'start_trial', 'invite_sent',
            'share', 'campaign_attribution', 'ad_click', 'outbound_click',
            'goal_conversion', 'feature_request', 'integration_connected',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $acquisitionKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get events specifically related to monetization and revenue generation.
     *
     * Returns events that directly impact revenue: purchases, subscriptions,
     * upgrades, billing, expansion revenue, and payment lifecycle. Useful for
     * revenue analytics, LTV calculations, and MRR monitoring.
     *
     * @return list<EventEntry>
     */
    public static function saasMonetizationEvents(): array
    {
        $monetizationKeys = [
            'purchase', 'subscribe', 'plan_upgrade', 'plan_downgrade',
            'cancellation', 'trial_converted', 'subscription_renewal',
            'subscription_resumed', 'subscription_paused', 'revenue_tracked',
            'payment_succeeded', 'payment_failed', 'invoice_generated',
            'credit_applied', 'billing_retry', 'subscription_value_changed',
            'expansion_revenue', 'refund', 'add_to_cart', 'begin_checkout',
            'add_payment_info',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $monetizationKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get events required for enterprise compliance tracking.
     *
     * Returns events that map to GDPR Article 30 (records of processing),
     * SOC2 CC7 (logical access monitoring), and ISO 27001 (audit trail)
     * compliance requirements. These events form the minimum audit trail
     * for enterprise customers with compliance obligations.
     *
     * @return list<EventEntry>
     */
    public static function enterpriseComplianceEvents(): array
    {
        $complianceKeys = [
            // GDPR Article 30 — Records of processing
            'sign_up', 'login', 'email_verified', 'password_changed', 'password_reset',
            'profile_updated', 'account_activated', 'account_deactivated', 'account_deleted',
            'consent_granted', 'consent_withdrawn',
            'data_subject_access_request', 'data_erasure_completed',
            // SOC2 CC7 — Access monitoring
            'logout', 'role_changed', 'team_member_joined', 'team_member_removed',
            // Audit trail
            'integration_connected', 'integration_failed',
            'payment_method_added', 'payment_method_updated',
            'export', 'import',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $complianceKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get events needed for DAU/MAU ratio tracking.
     *
     * Returns the minimum set of events required to calculate Daily Active
     * Users (DAU), Monthly Active Users (MAU), and the DAU/MAU stickiness
     * ratio. These are the core engagement health metrics for any SaaS product.
     *
     * @return list<EventEntry>
     */
    public static function dauMauEvents(): array
    {
        $dauMauKeys = [
            'login', 'page_view', 'session_start', 'feature_used',
            'search', 'form_submit', 'click', 'content_engagement',
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $dauMauKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get product health monitoring events.
     *
     * Returns events that indicate product stability, user satisfaction,
     * and system health. Essential for product teams monitoring quality
     * metrics and error rates.
     *
     * @return list<EventEntry>
     */
    public static function productHealthEvents(): array
    {
        $healthKeys = [
            'error', 'js_error', 'web_vitals', 'timing',
            'payment_failed', 'billing_retry', 'sla_breach',
            'feature_limit_reached', 'usage_quota_reached',
            'feedback', 'support_tickets_placeholder', // mapped to feedback
        ];

        return array_values(array_filter(
            array_map(fn (string $key): ?array => self::get($key), $healthKeys),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get the industry-standard SaaS readiness score (0-100).
     *
     * Calculates how well-instrumented the current event catalog is
     * compared to the industry-standard set. Returns a score from 0-100
     * with category-level breakdowns.
     *
     * @return array{score: int, breakdown: array<string, int>, gaps: list<string>, total_standard: int, total_covered: int}
     */
    public static function industryReadinessScore(): array
    {
        $standard = self::industryStandard();
        $allEvents = self::names();
        $eventSet = array_flip($allEvents);

        $breakdown = [
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
        ];

        $gaps = [];
        $covered = 0;
        $totalStandard = $standard['count'];

        foreach (['critical', 'high', 'medium', 'low'] as $tier) {
            foreach ($standard[$tier] as $entry) {
                $name = $entry['name'];
                if (isset($eventSet[$name])) {
                    $breakdown[$tier]++;
                    $covered++;
                } else {
                    $gaps[] = $name;
                }
            }
        }

        $score = $totalStandard > 0 ? (int) round(($covered / $totalStandard) * 100) : 0;

        return [
            'score' => $score,
            'breakdown' => $breakdown,
            'gaps' => $gaps,
            'total_standard' => $totalStandard,
            'total_covered' => $covered,
        ];
    }

    /**
     * Get pre-built funnel templates for common SaaS funnels.
     *
     * Returns named funnel definitions with ordered steps, each step
     * having a canonical event name and display label. Use with
     * FunnelProgressTracker::track() for automated funnel progression.
     *
     * @return array<string, array{name: string, description: string, steps: list<array{event: string, label: string}>, total_steps: int}>
     */
    public static function funnelTemplates(): array
    {
        return [
            'signup' => [
                'name' => 'signup',
                'description' => 'User registration funnel — from landing page to account creation',
                'steps' => [
                    ['event' => 'page_view', 'label' => 'Landing Page'],
                    ['event' => 'form_start', 'label' => 'Registration Form Started'],
                    ['event' => 'form_submit', 'label' => 'Registration Form Submitted'],
                    ['event' => 'sign_up', 'label' => 'Account Created'],
                    ['event' => 'email_verified', 'label' => 'Email Verified'],
                ],
                'total_steps' => 5,
            ],
            'trial' => [
                'name' => 'trial',
                'description' => 'Trial activation funnel — from signup to trial conversion',
                'steps' => [
                    ['event' => 'start_trial', 'label' => 'Trial Started'],
                    ['event' => 'feature_used', 'label' => 'First Feature Used'],
                    ['event' => 'onboarding_step', 'label' => 'Onboarding Completed'],
                    ['event' => 'trial_converted', 'label' => 'Trial Converted'],
                ],
                'total_steps' => 4,
            ],
            'checkout' => [
                'name' => 'checkout',
                'description' => 'E-commerce checkout funnel — from product view to purchase',
                'steps' => [
                    ['event' => 'view_item', 'label' => 'Product Viewed'],
                    ['event' => 'add_to_cart', 'label' => 'Added to Cart'],
                    ['event' => 'begin_checkout', 'label' => 'Checkout Started'],
                    ['event' => 'add_payment_info', 'label' => 'Payment Info Entered'],
                    ['event' => 'purchase', 'label' => 'Purchase Completed'],
                ],
                'total_steps' => 5,
            ],
            'onboarding' => [
                'name' => 'onboarding',
                'description' => 'Product onboarding funnel — from signup to activation',
                'steps' => [
                    ['event' => 'sign_up', 'label' => 'Signed Up'],
                    ['event' => 'email_verified', 'label' => 'Email Verified'],
                    ['event' => 'profile_updated', 'label' => 'Profile Completed'],
                    ['event' => 'feature_used', 'label' => 'First Feature Used'],
                    ['event' => 'milestone_reached', 'label' => 'Activation Milestone'],
                ],
                'total_steps' => 5,
            ],
            'activation' => [
                'name' => 'activation',
                'description' => 'Product activation funnel — key value-realization moments',
                'steps' => [
                    ['event' => 'sign_up', 'label' => 'Signed Up'],
                    ['event' => 'feature_used', 'label' => 'Feature Used'],
                    ['event' => 'search', 'label' => 'Search Performed'],
                    ['event' => 'content_engagement', 'label' => 'Content Engaged'],
                    ['event' => 'integration_connected', 'label' => 'Integration Added'],
                    ['event' => 'team_member_joined', 'label' => 'Team Expanded'],
                ],
                'total_steps' => 6,
            ],
            'billing' => [
                'name' => 'billing',
                'description' => 'Subscription billing funnel — from plan selection to payment',
                'steps' => [
                    ['event' => 'plan_upgrade', 'label' => 'Plan Selected'],
                    ['event' => 'begin_checkout', 'label' => 'Checkout Initiated'],
                    ['event' => 'add_payment_info', 'label' => 'Payment Method Added'],
                    ['event' => 'subscribe', 'label' => 'Subscription Created'],
                    ['event' => 'payment_succeeded', 'label' => 'Payment Succeeded'],
                ],
                'total_steps' => 5,
            ],
        ];
    }

    /**
     * Get a specific funnel template by name.
     *
     * @return array{name: string, description: string, steps: list<array{event: string, label: string}>, total_steps: int}|null
     */
    public static function funnelTemplate(string $name): ?array
    {
        return self::funnelTemplates()[$name] ?? null;
    }

    /**
     * Get all funnel template names.
     *
     * @return list<string>
     */
    public static function funnelTemplateNames(): array
    {
        return array_keys(self::funnelTemplates());
    }

    /**
     * Get the event names from a funnel template as a flat list.
     *
     * @param  string  $name  Funnel template name
     * @return list<string> Event names in funnel order
     */
    public static function funnelTemplateEvents(string $name): array
    {
        $template = self::funnelTemplate($name);

        if ($template === null) {
            return [];
        }

        return array_column($template['steps'], 'event');
    }
}
