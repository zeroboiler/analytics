<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\SaaS\CustomerSuccessEvents;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventTags;
use ZeroBoiler\Analytics\Events\Infrastructure\InfrastructureEvents;
use ZeroBoiler\Analytics\Events\Marketing\MarketingEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;
use ZeroBoiler\Analytics\Events\Webhook\WebhookEvents;

/**
 * Unified event catalog aggregating all event categories.
 *
 * Provides a single entry point for looking up event names, classes,
 * and provider mappings across Ecommerce, SaaS (including cohort), Engagement,
 * Security, Uptime, Infrastructure, Marketing, and CustomerSuccess.
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
            self::withCategory(InfrastructureEvents::all(), 'infrastructure'),
            self::withCategory(MarketingEvents::all(), 'marketing'),
            self::withCategory(CustomerSuccessEvents::all(), 'customer_success'),
            self::withCategory(WebhookEvents::all(), 'webhook'),
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
     * @return array{ecommerce: array<string, EventEntry>, saas: array<string, EventEntry>, engagement: array<string, EventEntry>, security: array<string, EventEntry>, uptime: array<string, EventEntry>, infrastructure: array<string, EventEntry>, marketing: array<string, EventEntry>, customer_success: array<string, EventEntry>}
     */
    public static function byCategory(): array
    {
        return [
            'ecommerce' => self::withCategory(EcommerceEvents::all(), 'ecommerce'),
            'saas' => self::withCategory(SaaSEvents::all(), 'saas'),
            'engagement' => self::withCategory(EngagementEvents::all(), 'engagement'),
            'security' => self::withCategory(SecurityEvents::all(), 'security'),
            'uptime' => self::withCategory(UptimeEvents::all(), 'uptime'),
            'infrastructure' => self::withCategory(InfrastructureEvents::all(), 'infrastructure'),
            'marketing' => self::withCategory(MarketingEvents::all(), 'marketing'),
            'customer_success' => self::withCategory(CustomerSuccessEvents::all(), 'customer_success'),
            'webhook' => self::withCategory(WebhookEvents::all(), 'webhook'),
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
            || UptimeEvents::has($name)
            || InfrastructureEvents::has($name)
            || MarketingEvents::has($name)
            || CustomerSuccessEvents::has($name)
            || WebhookEvents::has($name);
    }

    /**
     * Get the category name for a given event name.
     *
     * @return 'ecommerce'|'saas'|'engagement'|'security'|'uptime'|'infrastructure'|'marketing'|'customer_success'|null
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

        if (InfrastructureEvents::has($name)) {
            return 'infrastructure';
        }

        if (MarketingEvents::has($name)) {
            return 'marketing';
        }

        if (CustomerSuccessEvents::has($name)) {
            return 'customer_success';
        }

        if (WebhookEvents::has($name)) {
            return 'webhook';
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
            + UptimeEvents::count()
            + InfrastructureEvents::count()
            + MarketingEvents::count()
            + CustomerSuccessEvents::count();
    }

    /**
     * Get a summary of event counts per category.
     *
     * Returns an associative array with category names as keys and
     * event counts as values, plus a 'total' key for the grand total.
     *
     * @return array{ecommerce: int, saas: int, engagement: int, security: int, uptime: int, infrastructure: int, marketing: int, customer_success: int, total: int}
     *
     * @since 165.0.0
     */
    public static function categorySummary(): array
    {
        $counts = [
            'ecommerce' => EcommerceEvents::count(),
            'saas' => SaaSEvents::count(),
            'engagement' => EngagementEvents::count(),
            'security' => SecurityEvents::count(),
            'uptime' => UptimeEvents::count(),
            'infrastructure' => InfrastructureEvents::count(),
            'marketing' => MarketingEvents::count(),
            'customer_success' => CustomerSuccessEvents::count(),
        ];

        $counts['total'] = array_sum($counts);

        return $counts;
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
            InfrastructureEvents::ga4Names(),
            MarketingEvents::ga4Names(),
            CustomerSuccessEvents::ga4Names(),
        ))));
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
            InfrastructureEvents::metaNames(),
            MarketingEvents::metaNames(),
            CustomerSuccessEvents::metaNames(),
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
            InfrastructureEvents::posthogNames(),
            MarketingEvents::posthogNames(),
            CustomerSuccessEvents::posthogNames(),
        ))));
    }

    /**
     * Get all unique Plausible event names across all categories.
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
            InfrastructureEvents::plausibleNames(),
            MarketingEvents::plausibleNames(),
            CustomerSuccessEvents::plausibleNames(),
        ))));
    }

    /**
     * Get all unique Mixpanel event names across all categories.
     *
     * Uses the native `mixpanel` field in each catalog entry.
     *
     * @return list<string>
     */
    public static function allMixpanelNames(): array
    {
        return array_values(array_unique(array_filter(array_merge(
            EcommerceEvents::mixpanelNames(),
            SaaSEvents::mixpanelNames(),
            EngagementEvents::mixpanelNames(),
            SecurityEvents::mixpanelNames(),
            UptimeEvents::mixpanelNames(),
            InfrastructureEvents::mixpanelNames(),
            MarketingEvents::mixpanelNames(),
            CustomerSuccessEvents::mixpanelNames(),
        ))));
    }

    /**
     * Get all unique Amplitude event names across all categories.
     *
     * Uses the native `amplitude` field in each catalog entry.
     *
     * @return list<string>
     */
    public static function allAmplitudeNames(): array
    {
        return array_values(array_unique(array_filter(array_merge(
            EcommerceEvents::amplitudeNames(),
            SaaSEvents::amplitudeNames(),
            EngagementEvents::amplitudeNames(),
            SecurityEvents::amplitudeNames(),
            UptimeEvents::amplitudeNames(),
            InfrastructureEvents::amplitudeNames(),
            MarketingEvents::amplitudeNames(),
            CustomerSuccessEvents::amplitudeNames(),
        ))));
    }

    /**
     * Get all unique TikTok event names across all categories.
     *
     * Uses the native `tiktok` field in each catalog entry.
     * Events with null tiktok mapping are filtered out.
     *
     * @return list<string>
     */
    public static function allTikTokNames(): array
    {
        return array_values(array_unique(array_filter(array_merge(
            EcommerceEvents::tiktokNames(),
            SaaSEvents::tiktokNames(),
            EngagementEvents::tiktokNames(),
            SecurityEvents::tiktokNames(),
            UptimeEvents::tiktokNames(),
            InfrastructureEvents::tiktokNames(),
            MarketingEvents::tiktokNames(),
            CustomerSuccessEvents::tiktokNames(),
        ))));
    }

    /**
     * Get all unique LinkedIn event names across all categories.
     *
     * Uses the native `linkedin` field in each catalog entry.
     * Events with null linkedin mapping are filtered out.
     *
     * @return list<string>
     */
    public static function allLinkedInNames(): array
    {
        return array_values(array_unique(array_filter(array_merge(
            EcommerceEvents::linkedinNames(),
            SaaSEvents::linkedinNames(),
            EngagementEvents::linkedinNames(),
            SecurityEvents::linkedinNames(),
            UptimeEvents::linkedinNames(),
            InfrastructureEvents::linkedinNames(),
            MarketingEvents::linkedinNames(),
            CustomerSuccessEvents::linkedinNames(),
        ))));
    }

    /**
     * Resolve an event name from any common format to its canonical catalog name.
     *
     * Accepts snake_case, camelCase, PascalCase, kebab-case, or spaced formats
     * and normalizes to the catalog's snake_case convention. Performs exact match
     * first, then falls back to normalized lookup.
     *
     * @param  string  $name  Event name in any format (e.g. 'ViewItem', 'view-item', 'Add To Cart')
     * @return string|null  Canonical snake_case catalog name, or null if not found
     *
     * @since 109.0.0
     */
    public static function resolve(string $name): ?string
    {
        // 1. Exact match (fast path)
        if (self::has($name)) {
            return $name;
        }

        // 2. Normalize: lowercase, replace common separators with underscores, collapse
        $normalized = strtolower(
            preg_replace('/[\s\-]+/', '_', trim($name)) ?? $name
        );

        if (self::has($normalized)) {
            return $normalized;
        }

        // 3. CamelCase/PascalCase → snake_case (e.g. ViewItem → view_item, AddToCart → add_to_cart)
        $snake = strtolower(
            preg_replace('/(?<!^)([A-Z])/', '_$1', $name) ?? $name
        );

        if (self::has($snake)) {
            return $snake;
        }

        // 4. Double-underscore collapse (e.g. some__event → some_event)
        $collapsed = preg_replace('/_+/', '_', $snake) ?? $snake;

        if (self::has($collapsed)) {
            return $collapsed;
        }

        return null;
    }

    /**
     * Resolve an event name and return its full catalog entry.
     *
     * Convenience wrapper for resolve() → get().
     *
     * @return array|null  Full catalog entry array, or null if not found
     *
     * @since 109.0.0
     */
    public static function resolveAndGet(string $name): ?array
    {
        $resolved = self::resolve($name);

        if ($resolved === null) {
            return null;
        }

        return self::get($resolved);
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
     * Search events by name pattern within a specific category.
     *
     * Returns matching event entries only from the specified category.
     * Useful for finding events like "cart" within ecommerce or
     * "trial" within SaaS lifecycle.
     *
     * @param  string  $pattern  Search pattern (partial match, case-insensitive)
     * @param  'ecommerce'|'saas'|'engagement'|'security'|'uptime'|'infrastructure'|'marketing'|'customer_success'  $category
     * @return list<EventEntry>
     *
     * @since 130.0.0
     */
    public static function searchByCategory(string $pattern, string $category): array
    {
        $events = self::category($category);
        $results = [];

        foreach ($events as $name => $entry) {
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
     * Includes all supported providers: ga4, meta, posthog, plausible,
     * mixpanel, amplitude, tiktok, linkedin.
     *
     * Uses native catalog fields rather than EventTransformer maps.
     *
     * @return array{ga4: list<string>, meta: list<string>, posthog: list<string>, plausible: list<string>, mixpanel: list<string>, amplitude: list<string>, tiktok: list<string>, linkedin: list<string>}
     */
    public static function byProvider(): array
    {
        return [
            'ga4' => self::allGa4Names(),
            'meta' => self::allMetaNames(),
            'posthog' => self::allPosthogNames(),
            'plausible' => self::allPlausibleNames(),
            'mixpanel' => self::allMixpanelNames(),
            'amplitude' => self::allAmplitudeNames(),
            'tiktok' => self::allTikTokNames(),
            'linkedin' => self::allLinkedInNames(),
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
            ?? UptimeEvents::classFor($name)
            ?? InfrastructureEvents::classFor($name)
            ?? MarketingEvents::classFor($name)
            ?? CustomerSuccessEvents::classFor($name);
    }

    /**
     * Get all events in a specific category.
     *
     * @param  'ecommerce'|'saas'|'engagement'|'security'|'uptime'|'infrastructure'|'marketing'|'customer_success'  $category
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
            'infrastructure' => self::withCategory(InfrastructureEvents::all(), 'infrastructure'),
            'marketing' => self::withCategory(MarketingEvents::all(), 'marketing'),
            'customer_success' => self::withCategory(CustomerSuccessEvents::all(), 'customer_success'),
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
     * Get the Meta Pixel event name for a given catalog event name.
     *
     * @return string|null Meta Pixel event name or null if not supported
     *
     * @since 105.0.0
     */
    public static function metaNameFor(string $name): ?string
    {
        $entry = self::get($name);

        return $entry['meta'] ?? null;
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
     * Get the Mixpanel event name for a given catalog event name.
     *
     * @return string|null Mixpanel event name or null if not supported
     */
    public static function mixpanelNameFor(string $name): ?string
    {
        $entry = self::get($name);

        return $entry['mixpanel'] ?? null;
    }

    /**
     * Get the Amplitude event name for a given catalog event name.
     *
     * @return string|null Amplitude event name or null if not supported
     */
    public static function amplitudeNameFor(string $name): ?string
    {
        $entry = self::get($name);

        return $entry['amplitude'] ?? null;
    }

    /**
     * Get the TikTok event name for a given catalog event name.
     *
     * @return string|null TikTok event name or null if not supported
     */
    public static function tiktokNameFor(string $name): ?string
    {
        $entry = self::get($name);

        return $entry['tiktok'] ?? null;
    }

    /**
     * Get the LinkedIn event name for a given catalog event name.
     *
     * @return string|null LinkedIn event name or null if not supported
     */
    public static function linkedinNameFor(string $name): ?string
    {
        $entry = self::get($name);

        return $entry['linkedin'] ?? null;
    }

    /**
     * Get the complete event → Meta Pixel mapping table from catalog entries.
     *
     * @return array<string, string|null>
     *
     * @since 105.0.0
     */
    public static function allMetaMappings(): array
    {
        $mappings = [];

        foreach (self::all() as $name => $entry) {
            $mappings[$name] = $entry['meta'] ?? null;
        }

        return $mappings;
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
     * @return array{total: int, ecommerce: int, saas: int, engagement: int, security: int, uptime: int, infrastructure: int, with_ga4: int, with_meta: int, with_posthog: int, with_plausible: int, with_mixpanel: int, with_amplitude: int, with_tiktok: int, with_linkedin: int}
     */
    public static function summary(): array
    {
        $all = self::all();

        $withGa4 = 0;
        $withMeta = 0;
        $withPosthog = 0;
        $withPlausible = 0;
        $withMixpanel = 0;
        $withAmplitude = 0;
        $withTiktok = 0;
        $withLinkedin = 0;

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
            if (isset($entry['mixpanel']) && $entry['mixpanel'] !== null) {
                $withMixpanel++;
            }
            if (isset($entry['amplitude']) && $entry['amplitude'] !== null) {
                $withAmplitude++;
            }
            if (isset($entry['tiktok']) && $entry['tiktok'] !== null) {
                $withTiktok++;
            }
            if (isset($entry['linkedin']) && $entry['linkedin'] !== null) {
                $withLinkedin++;
            }
        }

        return [
            'total' => count($all),
            'ecommerce' => EcommerceEvents::count(),
            'saas' => SaaSEvents::count(),
            'engagement' => EngagementEvents::count(),
            'security' => SecurityEvents::count(),
            'uptime' => UptimeEvents::count(),
            'infrastructure' => InfrastructureEvents::count(),
            'with_ga4' => $withGa4,
            'with_meta' => $withMeta,
            'with_posthog' => $withPosthog,
            'with_plausible' => $withPlausible,
            'with_mixpanel' => $withMixpanel,
            'with_amplitude' => $withAmplitude,
            'with_tiktok' => $withTiktok,
            'with_linkedin' => $withLinkedin,
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
     * Get SaaS acquisition & activation funnel events in sequence order.
     *
     * Returns the industry-standard SaaS signup funnel events ordered from
     * first touch to conversion: sign_up → login → start_trial → trial_end →
     * subscribe → plan_upgrade → plan_downgrade → cancellation.
     *
     * Useful for funnel visualization, conversion rate calculation, and
     * drop-off analysis dashboards.
     *
     * @return list<array{step: int, event: string, entry: EventEntry|null}>
     *
     * @since 112.0.0
     */
    public static function saasFunnelEvents(): array
    {
        $steps = [
            'sign_up',
            'login',
            'start_trial',
            'trial_converted',
            'subscribe',
            'subscription_renewal',
            'plan_upgrade',
            'plan_downgrade',
            'cancellation',
        ];

        $result = [];

        foreach ($steps as $index => $name) {
            $result[] = [
                'step' => $index + 1,
                'event' => $name,
                'entry' => self::get($name),
            ];
        }

        return $result;
    }

    /**
     * Get e-commerce purchase funnel events in sequence order.
     *
     * Returns the standard e-commerce funnel: view_item → add_to_cart →
     * view_cart → begin_checkout → add_payment_info → purchase → refund.
     *
     * @return list<array{step: int, event: string, entry: EventEntry|null}>
     *
     * @since 112.0.0
     */
    public static function ecommerceFunnelEvents(): array
    {
        $steps = [
            'view_item',
            'select_item',
            'add_to_cart',
            'remove_from_cart',
            'view_cart',
            'begin_checkout',
            'add_payment_info',
            'purchase',
            'refund',
        ];

        $result = [];

        foreach ($steps as $index => $name) {
            $result[] = [
                'step' => $index + 1,
                'event' => $name,
                'entry' => self::get($name),
            ];
        }

        return $result;
    }

    /**
     * Get engagement funnel events for product usage tracking.
     *
     * Returns the standard engagement funnel: page_view → scroll_depth →
     * click → form_start → form_submit → search → share → error.
     *
     * @return list<array{step: int, event: string, entry: EventEntry|null}>
     *
     * @since 112.0.0
     */
    public static function engagementFunnelEvents(): array
    {
        $steps = [
            'page_view',
            'scroll_depth',
            'click',
            'form_start',
            'form_submit',
            'search',
            'share',
            'error',
        ];

        $result = [];

        foreach ($steps as $index => $name) {
            $result[] = [
                'step' => $index + 1,
                'event' => $name,
                'entry' => self::get($name),
            ];
        }

        return $result;
    }

    /**
     * Compute funnel conversion rates from an array of event counts.
     *
     * Given an associative array of event_name → count, computes the
     * step-by-step conversion rates for a given funnel definition.
     *
     * @param  array<string, int>  $eventCounts  Event name → occurrence count
     * @param  'saas'|'ecommerce'|'engagement'  $funnelType  Which funnel to analyze
     * @return array{steps: list<array{step: int, event: string, count: int, conversion_rate: float|null}>, overall_conversion: float|null}
     *
     * @since 112.0.0
     */
    public static function funnelConversionRates(array $eventCounts, string $funnelType): array
    {
        $funnelEvents = match ($funnelType) {
            'saas' => self::saasFunnelEvents(),
            'ecommerce' => self::ecommerceFunnelEvents(),
            'engagement' => self::engagementFunnelEvents(),
            default => [],
        };

        $steps = [];
        $firstStepCount = null;

        foreach ($funnelEvents as $item) {
            $count = $eventCounts[$item['event']] ?? 0;

            if ($firstStepCount === null) {
                $firstStepCount = $count;
            }

            $steps[] = [
                'step' => $item['step'],
                'event' => $item['event'],
                'count' => $count,
                'conversion_rate' => $firstStepCount > 0
                    ? round(($count / $firstStepCount) * 100, 2)
                    : null,
            ];
        }

        $overallConversion = null;

        if ($firstStepCount > 0 && count($steps) > 0) {
            $lastStepCount = $steps[array_key_last($steps)]['count'];
            $overallConversion = round(($lastStepCount / $firstStepCount) * 100, 2);
        }

        return [
            'steps' => $steps,
            'overall_conversion' => $overallConversion,
        ];
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
     * @return array{ga4: list<string>, meta: list<string>, posthog: list<string>, plausible: list<string>, mixpanel: list<string>, amplitude: list<string>, tiktok: list<string>, linkedin: list<string>, counts: array{ga4: int, meta: int, posthog: int, plausible: int, mixpanel: int, amplitude: int, tiktok: int, linkedin: int}}
     */
    public static function providerCoverage(): array
    {
        return [
            'ga4' => self::allGa4Names(),
            'meta' => self::allMetaNames(),
            'posthog' => self::allPosthogNames(),
            'plausible' => self::allPlausibleNames(),
            'mixpanel' => self::allMixpanelNames(),
            'amplitude' => self::allAmplitudeNames(),
            'tiktok' => self::allTikTokNames(),
            'linkedin' => self::allLinkedInNames(),
            'counts' => [
                'ga4' => self::providerCount('ga4'),
                'meta' => self::providerCount('meta'),
                'posthog' => self::providerCount('posthog'),
                'plausible' => self::providerCount('plausible'),
                'mixpanel' => self::providerCount('mixpanel'),
                'amplitude' => self::providerCount('amplitude'),
                'tiktok' => self::providerCount('tiktok'),
                'linkedin' => self::providerCount('linkedin'),
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
     * Returns every event's name mapped to its GA4, Meta, PostHog, Plausible,
     * Mixpanel, and Amplitude equivalents in a single lookup structure.
     * Ideal for admin dashboards and provider configuration UIs.
     *
     * @return array<string, array{ga4: string, meta: string|null, posthog: string, plausible: string|null, mixpanel: string|null, amplitude: string|null, category: string}>
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
                'mixpanel' => $entry['mixpanel'] ?? null,
                'amplitude' => $entry['amplitude'] ?? null,
                'tiktok' => $entry['tiktok'] ?? null,
                'linkedin' => $entry['linkedin'] ?? null,
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

    // ── Tag-Based Queries (v98.0.0) ──────────────────────────────

    /**
     * Get tags for a given event name.
     *
     * Delegates to EventTags::for().
     *
     * @return list<string>
     */
    public static function tagsFor(string $eventName): array
    {
        return EventTags::for($eventName);
    }

    /**
     * Get all event names that have a specific tag.
     *
     * Delegates to EventTags::tagged().
     *
     * @return list<EventEntry>
     */
    public static function tagged(string $tag): array
    {
        $names = EventTags::tagged($tag);

        return array_values(array_filter(
            array_map(fn (string $name): ?array => self::get($name), $names),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get events that match ALL given tags (AND logic).
     *
     * @param  list<string>  $tags
     * @return list<EventEntry>
     */
    public static function taggedAll(array $tags): array
    {
        $names = EventTags::whereAll($tags);

        return array_values(array_filter(
            array_map(fn (string $name): ?array => self::get($name), $names),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Get events that match ANY given tag (OR logic).
     *
     * @param  list<string>  $tags
     * @return list<EventEntry>
     */
    public static function taggedAny(array $tags): array
    {
        $names = EventTags::whereAny($tags);

        return array_values(array_filter(
            array_map(fn (string $name): ?array => self::get($name), $names),
            fn (?array $entry): bool => $entry !== null,
        ));
    }

    /**
     * Check if an event has a specific tag.
     */
    public static function hasTag(string $eventName, string $tag): bool
    {
        return EventTags::has($eventName, $tag);
    }

    /**
     * Get all available tag names.
     *
     * @return list<string>
     */
    public static function allTags(): array
    {
        return EventTags::allTags();
    }

    /**
     * Get events grouped by tag.
     *
     * @return array<string, list<EventEntry>>
     */
    public static function groupedByTag(): array
    {
        $tagged = EventTags::groupedByTag();
        $result = [];

        foreach ($tagged as $tag => $names) {
            $result[$tag] = array_values(array_filter(
                array_map(fn (string $name): ?array => self::get($name), $names),
                fn (?array $entry): bool => $entry !== null,
            ));
        }

        return $result;
    }

    // ── SaaS Sub-Category Queries (v98.0.0) ──────────────────────

    /**
     * Get SaaS sub-category names.
     *
     * @return list<string>
     */
    public static function saasSubCategories(): array
    {
        return \ZeroBoiler\Analytics\Events\SaaS\SaaSEventSubCategories::names();
    }

    /**
     * Get SaaS events in a specific sub-category.
     *
     * @return list<EventEntry>
     */
    public static function saasSubCategory(string $subcategory): array
    {
        return \ZeroBoiler\Analytics\Events\SaaS\SaaSEventSubCategories::catalogEntries($subcategory);
    }

    /**
     * Get SaaS events grouped by sub-category.
     *
     * @return array<string, list<EventEntry>>
     */
    public static function saasGrouped(): array
    {
        return \ZeroBoiler\Analytics\Events\SaaS\SaaSEventSubCategories::grouped();
    }

    /**
     * Get the sub-category for a SaaS event.
     */
    public static function saasSubCategoryFor(string $eventName): ?string
    {
        return \ZeroBoiler\Analytics\Events\SaaS\SaaSEventSubCategories::subcategoryFor($eventName);
    }

    // ── Provider Coverage Parity (v100.2.0) ──────────────────────

    /**
     * Get provider coverage parity analysis with gap detection.
     *
     * Extends providerCoverage() with per-provider coverage percentages
     * and explicit gap lists (events without mappings). Useful for gap
     * analysis when onboarding new analytics providers.
     *
     * @return array{total: int, providers: array<string, array{mapped: int, coverage: float, gaps: list<string>}>}
     *
     * @since 100.2.0
     */
    public static function providerCoverageParity(): array
    {
        $all = self::all();
        $total = count($all);
        $providers = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];
        $result = [];

        foreach ($providers as $provider) {
            $mapped = 0;
            $gaps = [];

            foreach ($all as $name => $entry) {
                $value = $entry[$provider] ?? null;

                if ($value !== null && $value !== '') {
                    $mapped++;
                } else {
                    $gaps[] = $name;
                }
            }

            $result[$provider] = [
                'mapped' => $mapped,
                'coverage' => $total > 0 ? round(($mapped / $total) * 100, 1) : 0.0,
                'gaps' => $gaps,
            ];
        }

        return [
            'total' => $total,
            'providers' => $result,
        ];
    }

    /**
     * Get provider mapping breakdown for a specific event.
     *
     * Returns which providers have mappings for a given event name.
     * Useful for checking cross-provider compatibility before dispatch.
     *
     * @return array{event: string, providers: array<string, string|null>, mapped_count: int, total_providers: int}
     *
     * @since 100.2.0
     */
    public static function eventProviderMapping(string $eventName): array
    {
        $entry = self::get($eventName);

        if ($entry === null) {
            return [
                'event' => $eventName,
                'providers' => [],
                'mapped_count' => 0,
                'total_providers' => 8,
            ];
        }

        $providers = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];
        $mappedCount = 0;
        $mappings = [];

        foreach ($providers as $provider) {
            $value = $entry[$provider] ?? null;
            $mappings[$provider] = $value;

            if ($value !== null && $value !== '') {
                $mappedCount++;
            }
        }

        return [
            'event' => $eventName,
            'providers' => $mappings,
            'mapped_count' => $mappedCount,
            'total_providers' => count($providers),
        ];
    }

    /**
     * Get events that are fully mapped across all 8 providers (100% coverage).
     *
     * These events can be dispatched to any provider without transformation.
     *
     * @return list<EventEntry>
     *
     * @since 100.2.0
     */
    public static function fullyMappedEvents(): array
    {
        $all = self::all();
        $providers = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];
        $result = [];

        foreach ($all as $entry) {
            $allMapped = true;

            foreach ($providers as $provider) {
                $value = $entry[$provider] ?? null;

                if ($value === null || $value === '') {
                    $allMapped = false;
                    break;
                }
            }

            if ($allMapped) {
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * Get events with the fewest provider mappings (candidates for expansion).
     *
     * Returns events sorted by mapped provider count (ascending).
     * Useful for identifying which events need additional provider coverage.
     *
     * @param  int  $limit  Maximum number of events to return
     * @return list<array{event: string, category: string, mapped_count: int, gaps: list<string>}>
     *
     * @since 100.2.0
     */
    public static function leastMappedEvents(int $limit = 10): array
    {
        $all = self::all();
        $providers = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];
        $scored = [];

        foreach ($all as $name => $entry) {
            $mappedCount = 0;
            $gaps = [];

            foreach ($providers as $provider) {
                $value = $entry[$provider] ?? null;

                if ($value !== null && $value !== '') {
                    $mappedCount++;
                } else {
                    $gaps[] = $provider;
                }
            }

            $scored[] = [
                'event' => $name,
                'category' => $entry['category'] ?? 'unknown',
                'mapped_count' => $mappedCount,
                'gaps' => $gaps,
            ];
        }

        usort($scored, fn (array $a, array $b): int => $a['mapped_count'] <=> $b['mapped_count']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Get events filtered by provider coverage requirements.
     *
     * Returns events that have mappings for ALL specified providers.
     * Useful for finding events that can be dispatched to a specific
     * combination of providers without transformation gaps.
     *
     * @param  list<string>  $providers  Provider names (e.g. ['ga4', 'meta', 'posthog'])
     * @return list<EventEntry>
     *
     * @since 112.0.0
     */
    public static function filterByProviders(array $providers): array
    {
        if ($providers === []) {
            return array_values(self::all());
        }

        $all = self::all();
        $result = [];

        foreach ($all as $entry) {
            $allMapped = true;

            foreach ($providers as $provider) {
                $value = $entry[$provider] ?? null;

                if ($value === null || $value === '') {
                    $allMapped = false;
                    break;
                }
            }

            if ($allMapped) {
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * Get events organized by the AARRR (Pirate Metrics) framework.
     *
     * Returns a structured breakdown of all catalog events by their
     * AARRR stage: Acquisition, Activation, Retention, Revenue, Referral.
     * Each stage includes the event entries, count, and provider coverage stats.
     * Events that don't fit any AARRR stage are grouped under 'operational'.
     *
     * Uses EventTags for classification rather than hardcoded lists,
     * ensuring consistency with the tag-based query system.
     *
     * @return array{acquisition: array{events: list<EventEntry>, count: int}, activation: array{events: list<EventEntry>, count: int}, retention: array{events: list<EventEntry>, count: int}, revenue: array{events: list<EventEntry>, count: int}, referral: array{events: list<EventEntry>, count: int}, operational: array{events: list<EventEntry>, count: int}, total: int, coverage: array<string, float>}
     *
     * @since 112.0.0
     */
    public static function aarrrBreakdown(): array
    {
        $stages = ['acquisition', 'activation', 'retention', 'revenue', 'referral'];
        $breakdown = [];
        $allEvents = self::all();
        $assigned = [];

        foreach ($stages as $stage) {
            $taggedNames = EventTags::tagged($stage);
            $events = [];

            foreach ($taggedNames as $name) {
                $entry = self::get($name);

                if ($entry !== null) {
                    $events[] = $entry;
                    $assigned[$name] = true;
                }
            }

            $breakdown[$stage] = [
                'events' => $events,
                'count' => count($events),
            ];
        }

        // Operational = events not assigned to any AARRR stage
        $operational = [];
        foreach ($allEvents as $name => $entry) {
            if (! isset($assigned[$name])) {
                $operational[] = $entry;
            }
        }

        $breakdown['operational'] = [
            'events' => $operational,
            'count' => count($operational),
        ];

        $total = 0;
        foreach ($stages as $stage) {
            $total += $breakdown[$stage]['count'];
        }

        $breakdown['total'] = $total;

        // Coverage: what percentage of all catalog events are covered by AARRR stages
        $totalCatalog = count($allEvents);
        $breakdown['coverage'] = [
            'aarrr' => $totalCatalog > 0 ? round(($total / $totalCatalog) * 100, 1) : 0.0,
            'total_catalog' => $totalCatalog,
        ];

        return $breakdown;
    }

    /**
     * Analyze cross-funnel event correlation.
     *
     * Identifies events that appear in multiple funnels and returns
     * a correlation matrix showing which events bridge different
     * funnel types. Essential for understanding event overlap and
     * designing non-redundant instrumentation.
     *
     * @return array{overlap_events: list<array{event: string, funnels: list<string>, funnel_count: int, categories: list<string>, tags: list<string>}>, funnel_sizes: array<string, int>, intersection_matrix: array<string, array<string, int>>}
     *
     * @since 113.0.0
     */
    public static function crossFunnelCorrelation(): array
    {
        $saas = self::saasFunnelEvents();
        $ecommerce = self::ecommerceFunnelEvents();
        $engagement = self::engagementFunnelEvents();
        $activation = self::activationFunnel();
        $checkout = self::checkoutFunnel();

        $funnelGroups = [
            'saas' => array_column($saas, 'event'),
            'ecommerce' => array_map(fn (array $e): string => $e['name'], $ecommerce),
            'engagement' => array_column($engagement, 'event'),
            'activation' => array_map(fn (array $e): string => $e['name'], $activation),
            'checkout' => array_map(fn (array $e): string => $e['name'], $checkout),
        ];

        $funnelSizes = [];
        foreach ($funnelGroups as $name => $events) {
            $funnelSizes[$name] = count($events);
        }

        // Intersection matrix: funnel x funnel event count overlap
        $funnelNames = array_keys($funnelGroups);
        $intersectionMatrix = [];

        foreach ($funnelNames as $a) {
            $intersectionMatrix[$a] = [];

            foreach ($funnelNames as $b) {
                $intersectionMatrix[$a][$b] = count(array_intersect($funnelGroups[$a], $funnelGroups[$b]));
            }
        }

        // Find events present in multiple funnels
        $eventFunnelMap = [];
        foreach ($funnelGroups as $funnelName => $events) {
            foreach ($events as $eventName) {
                if (! isset($eventFunnelMap[$eventName])) {
                    $eventFunnelMap[$eventName] = [];
                }

                if (! in_array($funnelName, $eventFunnelMap[$eventName], true)) {
                    $eventFunnelMap[$eventName][] = $funnelName;
                }
            }
        }

        $overlapEvents = [];

        foreach ($eventFunnelMap as $eventName => $funnels) {
            if (count($funnels) > 1) {
                $entry = self::get($eventName);
                $overlapEvents[] = [
                    'event' => $eventName,
                    'funnels' => $funnels,
                    'funnel_count' => count($funnels),
                    'categories' => $entry !== null ? [$entry['category']] : [],
                    'tags' => EventTags::for($eventName),
                ];
            }
        }

        usort($overlapEvents, fn (array $a, array $b): int => $b['funnel_count'] <=> $a['funnel_count']);

        return [
            'overlap_events' => $overlapEvents,
            'funnel_sizes' => $funnelSizes,
            'intersection_matrix' => $intersectionMatrix,
        ];
    }

    /**
     * Get the AARRR stage attribution for each funnel step.
     *
     * Maps each funnel type's steps to their AARRR classification
     * using EventTags, providing a complete funnel → AARRR view.
     * Useful for funnel dashboards that need stage-level color coding.
     *
     * @return array{saas: list<array{step: int, event: string, aarrr_stage: string, tags: list<string>}>, ecommerce: list<array{step: int, event: string, aarrr_stage: string, tags: list<string>}>, engagement: list<array{step: int, event: string, aarrr_stage: string, tags: list<string>}>}
     *
     * @since 113.0.0
     */
    public static function funnelStepAttribution(): array
    {
        $result = [];

        foreach (['saas', 'ecommerce', 'engagement'] as $funnelType) {
            $funnelEvents = match ($funnelType) {
                'saas' => self::saasFunnelEvents(),
                'ecommerce' => self::ecommerceFunnelEvents(),
                'engagement' => self::engagementFunnelEvents(),
            };

            $steps = [];

            foreach ($funnelEvents as $item) {
                $tags = EventTags::for($item['event']);

                // Determine primary AARRR stage from tags
                $aarrrStages = ['acquisition', 'activation', 'retention', 'revenue', 'referral'];
                $stage = 'operational';

                foreach ($aarrrStages as $s) {
                    if (in_array($s, $tags, true)) {
                        $stage = $s;
                        break;
                    }
                }

                $steps[] = [
                    'step' => $item['step'],
                    'event' => $item['event'],
                    'aarrr_stage' => $stage,
                    'tags' => $tags,
                ];
            }

            $result[$funnelType] = $steps;
        }

        return $result;
    }

    /**
     * Build an event → funnel impact matrix.
     *
     * Returns a matrix showing which events participate in which funnels,
     * with their AARRR stage, priority score, and provider coverage.
     * Essential for understanding the full impact of each tracked event.
     *
     * @param  'saas'|'ecommerce'|'engagement'|null  $funnelType  Filter by funnel type, or null for all
     * @return list<array{event: string, funnels: list<string>, aarrr_stage: string, priority_score: int, provider_count: int, tags: list<string>}>
     *
     * @since 113.0.0
     */
    public static function eventImpactMatrix(?string $funnelType = null): array
    {
        $allFunnelEvents = [];

        $funnelMap = [
            'saas' => self::saasFunnelEvents(),
            'ecommerce' => self::ecommerceFunnelEvents(),
            'engagement' => self::engagementFunnelEvents(),
        ];

        $funnels = $funnelType !== null && isset($funnelMap[$funnelType])
            ? [$funnelType => $funnelMap[$funnelType]]
            : $funnelMap;

        $providers = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];

        foreach ($funnels as $fname => $fEvents) {
            foreach ($fEvents as $item) {
                $allFunnelEvents[$item['event']] ??= [
                    'event' => $item['event'],
                    'funnels' => [],
                ];

                if (! in_array($fname, $allFunnelEvents[$item['event']]['funnels'], true)) {
                    $allFunnelEvents[$item['event']]['funnels'][] = $fname;
                }
            }
        }

        $matrix = [];
        $aarrrStages = ['acquisition', 'activation', 'retention', 'revenue', 'referral'];

        foreach ($allFunnelEvents as $eventName => $data) {
            $entry = self::get($eventName);
            $tags = EventTags::for($eventName);

            $aarrrStage = 'operational';
            foreach ($aarrrStages as $s) {
                if (in_array($s, $tags, true)) {
                    $aarrrStage = $s;
                    break;
                }
            }

            $providerCount = 0;
            if ($entry !== null) {
                foreach ($providers as $p) {
                    $v = $entry[$p] ?? null;
                    if ($v !== null && $v !== '') {
                        $providerCount++;
                    }
                }
            }

            $matrix[] = [
                'event' => $eventName,
                'funnels' => $data['funnels'],
                'aarrr_stage' => $aarrrStage,
                'priority_score' => self::eventPriorityScore($eventName),
                'provider_count' => $providerCount,
                'tags' => $tags,
            ];
        }

        usort($matrix, fn (array $a, array $b): int => $b['priority_score'] <=> $a['priority_score']);

        return $matrix;
    }

    /**
     * Compute step-to-step drop-off analysis for a funnel.
     *
     * Given event counts for a funnel type, calculates the drop-off rate
     * between consecutive steps. Returns drop-off percentages and
     * classifies each transition as 'healthy' (< 30%), 'warning' (30-60%),
     * or 'critical' (> 60%).
     *
     * @param  array<string, int>  $eventCounts  Event name → count
     * @param  'saas'|'ecommerce'|'engagement'  $funnelType
     * @return array{transitions: list<array{from: string, to: string, from_count: int, to_count: int, drop_off_rate: float|null, severity: 'healthy'|'warning'|'critical'|null}>, worst_dropoff: array{from: string, to: string, rate: float|null}|null}
     *
     * @since 113.0.0
     */
    public static function funnelDropoffAnalysis(array $eventCounts, string $funnelType): array
    {
        $funnelEvents = match ($funnelType) {
            'saas' => self::saasFunnelEvents(),
            'ecommerce' => self::ecommerceFunnelEvents(),
            'engagement' => self::engagementFunnelEvents(),
            default => [],
        };

        $transitions = [];
        $worstDropoff = null;

        for ($i = 0; $i < count($funnelEvents) - 1; $i++) {
            $fromEvent = $funnelEvents[$i]['event'];
            $toEvent = $funnelEvents[$i + 1]['event'];
            $fromCount = $eventCounts[$fromEvent] ?? 0;
            $toCount = $eventCounts[$toEvent] ?? 0;

            $dropoffRate = null;
            $severity = null;

            if ($fromCount > 0) {
                $dropoffRate = round((1 - ($toCount / $fromCount)) * 100, 2);

                $severity = match (true) {
                    $dropoffRate < 30 => 'healthy',
                    $dropoffRate < 60 => 'warning',
                    default => 'critical',
                };

                if ($worstDropoff === null || $dropoffRate > ($worstDropoff['rate'] ?? 0)) {
                    $worstDropoff = [
                        'from' => $fromEvent,
                        'to' => $toEvent,
                        'rate' => $dropoffRate,
                    ];
                }
            }

            $transitions[] = [
                'from' => $fromEvent,
                'to' => $toEvent,
                'from_count' => $fromCount,
                'to_count' => $toCount,
                'drop_off_rate' => $dropoffRate,
                'severity' => $severity,
            ];
        }

        return [
            'transitions' => $transitions,
            'worst_dropoff' => $worstDropoff,
        ];
    }

    /**
     * Get the numeric priority score for an event name (0-100).
     *
     * Computes a score based on:
     * - Category weight (ecommerce=30, saas=25, engagement=20, security=15, uptime=5, infrastructure=5)
     * - Provider coverage bonus (0-30, proportional to mapped providers)
     * - Tag bonuses (revenue=+10, critical=+10, conversion=+5, gdpr=+5)
     *
     * Complements eventPriority() which returns a string level.
     *
     * @return int Priority score 0-100
     *
     * @since 100.2.0
     */
    public static function eventPriorityScore(string $eventName): int
    {
        $entry = self::get($eventName);

        if ($entry === null) {
            return 0;
        }

        $categoryWeight = match ($entry['category'] ?? '') {
            'ecommerce' => 30,
            'saas' => 25,
            'engagement' => 20,
            'security' => 15,
            'uptime' => 5,
            'infrastructure' => 5,
            default => 0,
        };

        $providers = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];
        $mappedCount = 0;

        foreach ($providers as $provider) {
            $value = $entry[$provider] ?? null;

            if ($value !== null && $value !== '') {
                $mappedCount++;
            }
        }

        $providerBonus = (int) round(($mappedCount / count($providers)) * 30);

        $tags = EventTags::for($eventName);
        $tagBonus = 0;

        if (in_array('revenue', $tags, true)) {
            $tagBonus += 10;
        }
        if (in_array('critical', $tags, true)) {
            $tagBonus += 10;
        }
        if (in_array('conversion', $tags, true)) {
            $tagBonus += 5;
        }
        if (in_array('gdpr', $tags, true)) {
            $tagBonus += 5;
        }

        return min(100, $categoryWeight + $providerBonus + $tagBonus);
    }

    /**
     * Get the top-N highest priority events by numeric score.
     *
     * @param  int  $limit  Maximum events to return
     * @return list<array{event: string, category: string, priority: int, tags: list<string>}>
     *
     * @since 100.2.0
     */
    public static function topPriorityEvents(int $limit = 20): array
    {
        $all = self::all();
        $scored = [];

        foreach ($all as $name => $entry) {
            $scored[] = [
                'event' => $name,
                'category' => $entry['category'] ?? 'unknown',
                'priority' => self::eventPriorityScore($name),
                'tags' => EventTags::for($name),
            ];
        }

        usort($scored, fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Get instrumentation recommendations grouped by priority-score tier.
     *
     * Complements recommendedInstrumentation() (which uses curated lists)
     * with a dynamic score-based grouping.
     *
     * @return array{starter: list<array{event: string, priority: int}>, intermediate: list<array{event: string, priority: int}>, advanced: list<array{event: string, priority: int}>}
     *
     * @since 100.2.0
     */
    public static function recommendedInstrumentationByScore(string $tier = 'starter'): array
    {
        $all = self::all();
        $scored = [];

        foreach ($all as $name => $entry) {
            $scored[] = [
                'event' => $name,
                'category' => $entry['category'] ?? 'unknown',
                'priority' => self::eventPriorityScore($name),
            ];
        }

        usort($scored, fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        $tiers = [
            'starter' => [],
            'intermediate' => [],
            'advanced' => [],
        ];

        foreach ($scored as $item) {
            $p = $item['priority'];

            if ($p >= 60) {
                $tiers['starter'][] = $item;
            } elseif ($p >= 40) {
                $tiers['intermediate'][] = $item;
            } else {
                $tiers['advanced'][] = $item;
            }
        }

        if ($tier === 'all') {
            return $tiers;
        }

        return $tiers[$tier] ?? $tiers['starter'];
    }

    // ── Phase 42: Event Dependency Graph & Causal Path Analysis ──────

    /**
     * Define causal dependency edges between events based on funnel ordering.
     *
     * Each edge represents a "typically precedes" relationship:
     * source → target means source usually fires before target in the same session.
     * Built from the canonical funnel event sequences.
     *
     * @return array<string, list<string>>  source event → list of target events
     *
     * @since 114.0.0
     */
    public static function causalEdges(): array
    {
        return [
            // SaaS acquisition funnel
            'sign_up' => ['login', 'start_trial', 'email_verified', 'account_activated', 'team_created'],
            'login' => ['start_trial', 'feature_used', 'plan_upgrade', 'plan_downgrade', 'cancellation'],
            'start_trial' => ['trial_converted', 'trial_expired', 'trial_end', 'feature_used'],
            'trial_converted' => ['subscribe', 'subscription_created'],
            'subscribe' => ['subscription_renewal', 'plan_upgrade', 'plan_downgrade', 'cancellation'],
            'plan_upgrade' => ['subscription_renewal', 'cancellation', 'plan_downgrade'],
            'subscription_renewal' => ['subscription_renewal', 'cancellation', 'plan_downgrade'],
            // E-commerce funnel
            'view_item' => ['select_item', 'add_to_cart', 'add_to_wishlist'],
            'select_item' => ['add_to_cart', 'view_cart'],
            'add_to_cart' => ['remove_from_cart', 'view_cart', 'begin_checkout'],
            'remove_from_cart' => ['add_to_cart', 'view_cart', 'begin_checkout', 'abandoned_cart'],
            'view_cart' => ['begin_checkout', 'abandoned_cart'],
            'begin_checkout' => ['add_payment_info', 'checkout_step', 'checkout_abandon'],
            'add_payment_info' => ['purchase'],
            'purchase' => ['refund'],
            // Engagement funnel
            'page_view' => ['scroll_depth', 'click', 'form_start', 'search', 'share', 'outbound_click'],
            'scroll_depth' => ['click', 'form_start'],
            'click' => ['form_start', 'share', 'outbound_click'],
            'form_start' => ['form_submit'],
            'form_submit' => ['sign_up', 'goal_conversion'],
            // Account lifecycle
            'email_verified' => ['account_activated', 'onboarding_step', 'onboarding_completed'],
            'account_activated' => ['onboarding_step', 'feature_used', 'start_trial'],
            'onboarding_step' => ['onboarding_completed', 'feature_used', 'milestone_reached'],
            // Team/B2B
            'team_created' => ['team_member_joined', 'invite_sent', 'integration_connected'],
            'invite_sent' => ['team_member_joined'],
            'team_member_joined' => ['feature_used', 'role_changed'],
            // Billing
            'payment_method_added' => ['add_payment_info', 'purchase', 'subscribe'],
            'payment_failed' => ['billing_retry', 'cancellation'],
            'payment_succeeded' => ['subscription_renewal', 'plan_upgrade'],
            'billing_retry' => ['payment_succeeded', 'payment_failed', 'cancellation'],
            // Workspace
            'workspace_created' => ['team_created', 'integration_connected', 'feature_used'],
            'integration_connected' => ['feature_used', 'integration_failed'],
            // Performance
            'web_vitals' => ['performance_score'],
            'js_error' => ['error', 'client_error'],
            'client_error' => ['error'],
        ];
    }

    /**
     * Build a directed adjacency graph from causal edges.
     *
     * Returns both forward (outgoing) and reverse (incoming) adjacency lists
     * for efficient path traversal and ancestor/descendant queries.
     *
     * @return array{forward: array<string, list<string>>, reverse: array<string, list<string>>, edge_count: int, node_count: int}
     *
     * @since 114.0.0
     */
    public static function eventDependencyGraph(): array
    {
        $edges = self::causalEdges();
        $forward = [];
        $reverse = [];

        foreach ($edges as $source => $targets) {
            if (! isset($forward[$source])) {
                $forward[$source] = [];
            }

            foreach ($targets as $target) {
                $forward[$source][] = $target;

                if (! isset($reverse[$target])) {
                    $reverse[$target] = [];
                }
                $reverse[$target][] = $source;
            }
        }

        // Include nodes that appear only as targets (no outgoing edges)
        foreach ($reverse as $node => $ancestors) {
            if (! isset($forward[$node])) {
                $forward[$node] = [];
            }
        }

        // Include nodes that appear only as sources (no incoming edges)
        foreach ($forward as $node => $descendants) {
            if (! isset($reverse[$node])) {
                $reverse[$node] = [];
            }
        }

        $allNodes = array_unique(array_merge(array_keys($forward), array_keys($reverse)));
        $edgeCount = 0;

        foreach ($forward as $targets) {
            $edgeCount += count($targets);
        }

        return [
            'forward' => $forward,
            'reverse' => $reverse,
            'edge_count' => $edgeCount,
            'node_count' => count($allNodes),
            'nodes' => array_values($allNodes),
        ];
    }

    /**
     * Find all causal paths from one event to another using BFS.
     *
     * Returns all simple paths (no cycles) from $from to $to through the
     * causal dependency graph. Paths are returned sorted by length (shortest first).
     *
     * @return list<list<string>>  List of paths, each path is a list of event names from → to
     *
     * @since 114.0.0
     */
    public static function causalPaths(string $from, string $to, int $maxDepth = 8): array
    {
        $graph = self::eventDependencyGraph();
        $forward = $graph['forward'];

        $paths = [];

        // BFS with path tracking
        $queue = [[$from]];

        while ($queue !== []) {
            $path = array_shift($queue);
            $current = $path[array_key_last($path)];

            if ($current === $to && count($path) > 1) {
                $paths[] = $path;
                continue;
            }

            if (count($path) - 1 >= $maxDepth) {
                continue;
            }

            $neighbors = $forward[$current] ?? [];

            foreach ($neighbors as $neighbor) {
                // Prevent cycles
                if (in_array($neighbor, $path, true)) {
                    continue;
                }

                $queue[] = [...$path, $neighbor];
            }
        }

        // Sort by path length (shortest first)
        usort($paths, fn (array $a, array $b): int => count($a) <=> count($b));

        return $paths;
    }

    /**
     * Get all direct ancestors (events that typically precede) for a given event.
     *
     * Uses the reverse adjacency list from the dependency graph.
     *
     * @return list<string>
     *
     * @since 114.0.0
     */
    public static function causalAncestors(string $event, int $depth = 1): array
    {
        $graph = self::eventDependencyGraph();
        $reverse = $graph['reverse'];

        if ($depth <= 1) {
            return $reverse[$event] ?? [];
        }

        // Multi-depth: BFS on reverse graph
        $ancestors = [];
        $visited = [$event => true];
        $currentLevel = [$event];

        for ($d = 0; $d < $depth; $d++) {
            $nextLevel = [];

            foreach ($currentLevel as $node) {
                foreach ($reverse[$node] ?? [] as $parent) {
                    if (! isset($visited[$parent])) {
                        $visited[$parent] = true;
                        $ancestors[] = $parent;
                        $nextLevel[] = $parent;
                    }
                }
            }

            $currentLevel = $nextLevel;
        }

        return $ancestors;
    }

    /**
     * Get all direct descendants (events that typically follow) for a given event.
     *
     * Uses the forward adjacency list from the dependency graph.
     *
     * @return list<string>
     *
     * @since 114.0.0
     */
    public static function causalDescendants(string $event, int $depth = 1): array
    {
        $graph = self::eventDependencyGraph();
        $forward = $graph['forward'];

        if ($depth <= 1) {
            return $forward[$event] ?? [];
        }

        // Multi-depth: BFS on forward graph
        $descendants = [];
        $visited = [$event => true];
        $currentLevel = [$event];

        for ($d = 0; $d < $depth; $d++) {
            $nextLevel = [];

            foreach ($currentLevel as $node) {
                foreach ($forward[$node] ?? [] as $child) {
                    if (! isset($visited[$child])) {
                        $visited[$child] = true;
                        $descendants[] = $child;
                        $nextLevel[] = $child;
                    }
                }
            }

            $currentLevel = $nextLevel;
        }

        return $descendants;
    }

    /**
     * Get critical path events for a given funnel type.
     *
     * Critical paths are the shortest causal chains from funnel entry to exit.
     * Returns all shortest paths through the funnel dependency graph.
     *
     * @param  'saas'|'ecommerce'|'engagement'  $funnelType
     * @return array{entry: string, exit: string, critical_paths: list<list<string>>, max_depth: int}
     *
     * @since 114.0.0
     */
    public static function funnelCriticalPaths(string $funnelType): array
    {
        $entryExit = match ($funnelType) {
            'saas' => ['sign_up', 'cancellation'],
            'ecommerce' => ['view_item', 'purchase'],
            'engagement' => ['page_view', 'error'],
            default => ['', ''],
        };

        if ($entryExit[0] === '') {
            return ['entry' => '', 'exit' => '', 'critical_paths' => [], 'max_depth' => 0];
        }

        $paths = self::causalPaths($entryExit[0], $entryExit[1], 10);

        // Find shortest path length
        $minLength = PHP_INT_MAX;
        foreach ($paths as $path) {
            if (count($path) < $minLength) {
                $minLength = count($path);
            }
        }

        // Filter to only shortest paths
        $criticalPaths = array_filter(
            $paths,
            fn (array $path): bool => count($path) === $minLength
        );

        return [
            'entry' => $entryExit[0],
            'exit' => $entryExit[1],
            'critical_paths' => array_values($criticalPaths),
            'max_depth' => $minLength > 0 ? $minLength - 1 : 0,
        ];
    }

    /**
     * Statistical funnel bottleneck analysis using z-score anomaly detection.
     *
     * Identifies statistically significant drop-off points in a funnel
     * by comparing actual transition rates against the mean and standard
     * deviation of all transitions. Bottlenecks are classified by z-score:
     *   - |z| < 1.0: normal
     *   - 1.0 ≤ |z| < 2.0: elevated
     *   - |z| ≥ 2.0: critical bottleneck
     *
     * @param  array<string, int>  $eventCounts  Event name → count
     * @param  'saas'|'ecommerce'|'engagement'  $funnelType
     * @return array{transitions: list<array{from: string, to: string, from_count: int, to_count: int, rate: float|null, z_score: float|null, severity: string|null}>, mean_rate: float, std_dev: float, critical_count: int, elevated_count: int}
     *
     * @since 114.0.0
     */
    public static function funnelBottleneckAnalysis(array $eventCounts, string $funnelType): array
    {
        // Get funnel events
        $funnelEvents = match ($funnelType) {
            'saas' => self::saasFunnelEvents(),
            'ecommerce' => self::ecommerceFunnelEvents(),
            'engagement' => self::engagementFunnelEvents(),
            default => [],
        };

        if ($funnelEvents === []) {
            return [
                'transitions' => [],
                'mean_rate' => 0.0,
                'std_dev' => 0.0,
                'critical_count' => 0,
                'elevated_count' => 0,
            ];
        }

        // Build transitions
        $transitions = [];
        $rates = [];

        for ($i = 0; $i < count($funnelEvents) - 1; $i++) {
            $fromName = $funnelEvents[$i]['event'];
            $toName = $funnelEvents[$i + 1]['event'];
            $fromCount = $eventCounts[$fromName] ?? 0;
            $toCount = $eventCounts[$toName] ?? 0;

            $rate = $fromCount > 0 ? round((1 - ($toCount / $fromCount)) * 100, 2) : null;

            if ($rate !== null) {
                $rates[] = $rate;
            }

            $transitions[] = [
                'from' => $fromName,
                'to' => $toName,
                'from_count' => $fromCount,
                'to_count' => $toCount,
                'rate' => $rate,
                'z_score' => null,
                'severity' => null,
            ];
        }

        // Compute mean and std dev of drop-off rates
        $n = count($rates);
        $mean = $n > 0 ? array_sum($rates) / $n : 0.0;
        $variance = 0.0;

        foreach ($rates as $r) {
            $variance += ($r - $mean) ** 2;
        }

        $stdDev = $n > 1 ? sqrt($variance / ($n - 1)) : 0.0;

        // Compute z-scores and severity
        $criticalCount = 0;
        $elevatedCount = 0;

        foreach ($transitions as &$transition) {
            if ($transition['rate'] !== null && $stdDev > 0) {
                $zScore = ($transition['rate'] - $mean) / $stdDev;
                $transition['z_score'] = round($zScore, 2);

                $absZ = abs($zScore);

                if ($absZ >= 2.0) {
                    $transition['severity'] = 'critical';
                    $criticalCount++;
                } elseif ($absZ >= 1.0) {
                    $transition['severity'] = 'elevated';
                    $elevatedCount++;
                } else {
                    $transition['severity'] = 'normal';
                }
            }
        }

        return [
            'transitions' => $transitions,
            'mean_rate' => round($mean, 2),
            'std_dev' => round($stdDev, 2),
            'critical_count' => $criticalCount,
            'elevated_count' => $elevatedCount,
        ];
    }

    /**
     * Get event sequence correlation matrix for a funnel type.
     *
     * Returns a matrix showing which event pairs tend to co-occur in
     * sequence within the same funnel. Each cell contains the correlation
     * strength (0.0 to 1.0) based on the causal edge structure.
     *
     * @param  'saas'|'ecommerce'|'engagement'  $funnelType
     * @return array{funnel: string, events: list<string>, matrix: array<string, array<string, float>>, direct_edges: int, possible_edges: int, density: float}
     *
     * @since 114.0.0
     */
    public static function eventSequenceCorrelationMatrix(string $funnelType): array
    {
        $funnelEvents = match ($funnelType) {
            'saas' => self::saasFunnelEvents(),
            'ecommerce' => self::ecommerceFunnelEvents(),
            'engagement' => self::engagementFunnelEvents(),
            default => [],
        };

        if ($funnelEvents === []) {
            return [
                'funnel' => $funnelType,
                'events' => [],
                'matrix' => [],
                'direct_edges' => 0,
                'possible_edges' => 0,
                'density' => 0.0,
            ];
        }

        $eventNames = array_column($funnelEvents, 'event');
        $edges = self::causalEdges();
        $matrix = [];

        foreach ($eventNames as $row) {
            $matrix[$row] = [];

            foreach ($eventNames as $col) {
                // Direct causal edge → 1.0
                if (in_array($col, $edges[$row] ?? [], true)) {
                    $matrix[$row][$col] = 1.0;
                } elseif ($row === $col) {
                    // Self → 1.0 (identity)
                    $matrix[$row][$col] = 1.0;
                } else {
                    // Indirect: check if there's a 2-hop path
                    $hasIndirect = false;

                    foreach ($edges[$row] ?? [] as $intermediate) {
                        if (in_array($col, $edges[$intermediate] ?? [], true)) {
                            $hasIndirect = true;
                            break;
                        }
                    }

                    $matrix[$row][$col] = $hasIndirect ? 0.5 : 0.0;
                }
            }
        }

        $n = count($eventNames);
        $possibleEdges = $n * ($n - 1); // Exclude diagonal
        $directEdges = 0;

        foreach ($eventNames as $row) {
            foreach ($eventNames as $col) {
                if ($row !== $col && ($matrix[$row][$col] ?? 0.0) === 1.0) {
                    $directEdges++;
                }
            }
        }

        return [
            'funnel' => $funnelType,
            'events' => $eventNames,
            'matrix' => $matrix,
            'direct_edges' => $directEdges,
            'possible_edges' => $possibleEdges,
            'density' => $possibleEdges > 0 ? round($directEdges / $possibleEdges, 4) : 0.0,
        ];
    }

    /**
     * Provider coverage summary across the entire event catalog.
     *
     * Returns a comprehensive analysis of which providers have mappings for
     * how many events, event coverage percentages, and gap lists for each
     * provider. Useful for audit readiness and provider onboarding decisions.
     *
     * @return array{total_events: int, providers: array<string, array{mapped: int, coverage: float, gaps: list<string>, top_categories: array<string, int>}>, best_covered: list<string>, least_covered: list<string>}
     *
     * @since 115.0.0
     */
    public static function providerCoverageSummary(): array
    {
        $catalog = self::all();
        $totalEvents = count($catalog);

        /** @var array<string, list<string>> $providerMap */
        $providerMap = [
            'ga4' => [],
            'gtm' => [],
            'meta' => [],
            'posthog' => [],
            'plausible' => [],
            'mixpanel' => [],
            'amplitude' => [],
            'tiktok' => [],
            'linkedin' => [],
            'webhook' => [],
        ];

        foreach ($catalog as $name => $entry) {
            foreach ($providerMap as $provider => &$mapped) {
                $value = $entry[$provider] ?? null;

                if ($value !== null && $value !== '') {
                    $mapped[] = $name;
                }
            }
        }

        // Build per-provider summaries
        $summaries = [];
        $coverages = [];

        foreach ($providerMap as $provider => $mapped) {
            $mappedCount = count($mapped);
            $coverage = $totalEvents > 0 ? round(($mappedCount / $totalEvents) * 100, 1) : 0.0;

            // Count by category
            $categoryCounts = [];
            foreach ($mapped as $eventName) {
                $cat = $catalog[$eventName]['category'] ?? 'unknown';
                $categoryCounts[$cat] = ($categoryCounts[$cat] ?? 0) + 1;
            }

            // Sort categories by count descending, take top 3
            arsort($categoryCounts);
            $topCategories = array_slice($categoryCounts, 0, 3);

            $summaries[$provider] = [
                'mapped' => $mappedCount,
                'coverage' => $coverage,
                'gaps' => array_values(array_diff(array_keys($catalog), $mapped)),
                'top_categories' => $topCategories,
            ];

            $coverages[$provider] = $coverage;
        }

        // Sort providers by coverage descending
        arsort($coverages);
        $bestCovered = array_keys(array_filter($coverages, fn (float $c): bool => $c >= 80.0));
        $leastCovered = array_keys(array_filter($coverages, fn (float $c): bool => $c < 30.0));

        return [
            'total_events' => $totalEvents,
            'providers' => $summaries,
            'best_covered' => $bestCovered,
            'least_covered' => $leastCovered,
        ];
    }

    /**
     * Get events that are mapped to ALL specified providers.
     *
     * Unlike filterByProviders() which returns just names, this returns
     * full event details with provider-specific mapping values.
     *
     * @param  list<string>  $providers  Provider names (e.g. ['ga4', 'meta', 'posthog'])
     * @return list<array{name: string, category: string, entries: array<string, string>}>
     *
     * @since 115.0.0
     */
    public static function providerIntersectionEvents(array $providers): array
    {
        $catalog = self::all();
        $result = [];

        foreach ($catalog as $name => $entry) {
            $allPresent = true;

            foreach ($providers as $provider) {
                $value = $entry[$provider] ?? null;

                if ($value === null || $value === '') {
                    $allPresent = false;
                    break;
                }
            }

            if ($allPresent) {
                $providerEntries = [];
                foreach ($providers as $provider) {
                    $providerEntries[$provider] = $entry[$provider] ?? '';
                }

                $result[] = [
                    'name' => $name,
                    'category' => $entry['category'] ?? 'unknown',
                    'entries' => $providerEntries,
                ];
            }
        }

        return $result;
    }
}
