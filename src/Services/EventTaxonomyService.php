<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;

/**
 * Tag-based event taxonomy service.
 *
 * Provides semantic classification of analytics events into functional
 * groups (tags) such as revenue, conversion, engagement, error, lifecycle,
 * funnel, and retention. Enables filtering, grouping, and analysis of
 * events by business function rather than just by category.
 *
 * Built-in tag assignments are derived from the event catalog, with
 * support for custom tag overrides from configuration.
 *
 * Configuration is read from `zeroboiler.analytics.taxonomy`.
 *
 * @see \ZeroBoiler\Analytics\Events\EventCatalog
 */
final class EventTaxonomyService
{
    /** @var array<string, list<string>> Built-in event → tag assignments */
    private const TAG_MAP = [
        // Revenue events
        'purchase' => ['revenue', 'conversion', 'ecommerce'],
        'revenue_tracked' => ['revenue'],
        'payment_succeeded' => ['revenue', 'billing'],
        'payment_failed' => ['revenue', 'billing', 'error'],
        'payment_method_added' => ['billing'],
        'invoice_generated' => ['revenue', 'billing'],
        'credit_applied' => ['revenue', 'billing'],
        'refund' => ['revenue', 'ecommerce'],

        // Conversion events
        'sign_up' => ['conversion', 'lifecycle', 'saas'],
        'subscribe' => ['conversion', 'lifecycle', 'saas'],
        'start_trial' => ['conversion', 'lifecycle', 'saas'],
        'add_to_cart' => ['conversion', 'ecommerce'],
        'begin_checkout' => ['conversion', 'funnel', 'ecommerce'],
        'add_payment_info' => ['conversion', 'funnel', 'ecommerce'],
        'form_submit' => ['conversion', 'engagement'],
        'cohort_conversion' => ['conversion', 'cohort'],

        // Engagement events
        'page_view' => ['engagement', 'traffic'],
        'scroll_depth' => ['engagement'],
        'click' => ['engagement'],
        'search' => ['engagement'],
        'share' => ['engagement', 'viral'],
        'outbound_click' => ['engagement', 'traffic'],
        'file_download' => ['engagement'],
        'video_play' => ['engagement', 'content'],
        'time_on_page' => ['engagement'],
        'web_vitals' => ['engagement', 'performance'],
        'timing' => ['engagement', 'performance'],
        'form_start' => ['engagement'],
        'notification' => ['engagement'],
        'campaign_attribution' => ['engagement', 'attribution'],
        'feature_used' => ['engagement', 'saas'],
        'screen_view' => ['engagement', 'traffic'],

        // Error events
        'error' => ['error', 'engagement'],
        'js_error' => ['error', 'performance'],
        'integration_failed' => ['error', 'operational'],

        // Lifecycle events
        'login' => ['lifecycle', 'saas'],
        'logout' => ['lifecycle', 'saas'],
        'trial_end' => ['lifecycle', 'saas'],
        'plan_upgrade' => ['lifecycle', 'saas'],
        'plan_downgrade' => ['lifecycle', 'saas'],
        'cancellation' => ['lifecycle', 'saas', 'churn'],
        'subscription_renewal' => ['lifecycle', 'saas'],
        'account_activated' => ['lifecycle', 'account'],
        'account_deactivated' => ['lifecycle', 'account'],
        'password_changed' => ['lifecycle', 'account'],
        'password_reset' => ['lifecycle', 'account'],
        'profile_updated' => ['lifecycle', 'account'],
        'email_verified' => ['lifecycle', 'account'],

        // B2B / Team events
        'team_created' => ['lifecycle', 'b2b'],
        'team_member_joined' => ['lifecycle', 'b2b'],
        'team_member_removed' => ['lifecycle', 'b2b'],
        'role_changed' => ['lifecycle', 'b2b'],
        'invite_sent' => ['lifecycle', 'b2b'],
        'integration_connected' => ['lifecycle', 'operational'],

        // Funnel events
        'view_item' => ['funnel', 'ecommerce'],
        'view_cart' => ['funnel', 'ecommerce'],
        'remove_from_cart' => ['funnel', 'ecommerce'],
        'select_item' => ['funnel', 'ecommerce'],
        'select_promotion' => ['funnel', 'ecommerce'],
        'view_promotion' => ['funnel', 'ecommerce'],
        'add_to_wishlist' => ['funnel', 'ecommerce'],

        // Cohort events
        'cohort_assigned' => ['cohort', 'lifecycle'],
        'cohort_retention' => ['cohort', 'retention'],
        'cohort_churn' => ['cohort', 'churn', 'retention'],
        'cohort_migration' => ['cohort', 'lifecycle'],
        'cohort_engagement' => ['cohort', 'engagement'],

        // Session events
        'session_start' => ['session', 'lifecycle'],
        'session_end' => ['session', 'lifecycle'],

        // A/B testing
        'ab_test_exposure' => ['experiment'],
    ];

    /** @var array<string, list<string>> All available tag definitions */
    private const TAG_DEFINITIONS = [
        'revenue' => ['label' => 'Revenue', 'description' => 'Events related to monetary transactions and billing'],
        'conversion' => ['label' => 'Conversion', 'description' => 'Events representing key conversion milestones'],
        'engagement' => ['label' => 'Engagement', 'description' => 'User interaction and content engagement events'],
        'error' => ['label' => 'Error', 'description' => 'Error and failure tracking events'],
        'lifecycle' => ['label' => 'Lifecycle', 'description' => 'User account and subscription lifecycle events'],
        'funnel' => ['label' => 'Funnel', 'description' => 'Events that form part of conversion funnels'],
        'cohort' => ['label' => 'Cohort', 'description' => 'Cohort analytics and segmentation events'],
        'session' => ['label' => 'Session', 'description' => 'Session start/end lifecycle events'],
        'billing' => ['label' => 'Billing', 'description' => 'Payment, invoicing, and billing events'],
        'ecommerce' => ['label' => 'E-commerce', 'description' => 'Product and shopping cart related events'],
        'saas' => ['label' => 'SaaS', 'description' => 'SaaS-specific business events'],
        'traffic' => ['label' => 'Traffic', 'description' => 'Page views and navigation events'],
        'performance' => ['label' => 'Performance', 'description' => 'Web vitals and performance monitoring events'],
        'b2b' => ['label' => 'B2B', 'description' => 'Team and organization management events'],
        'account' => ['label' => 'Account', 'description' => 'Account management events'],
        'churn' => ['label' => 'Churn', 'description' => 'Churn prediction and churn-related events'],
        'retention' => ['label' => 'Retention', 'description' => 'Retention analytics events'],
        'operational' => ['label' => 'Operational', 'description' => 'System and integration operational events'],
        'experiment' => ['label' => 'Experiment', 'description' => 'A/B test and experiment events'],
        'viral' => ['label' => 'Viral', 'description' => 'Sharing and viral growth events'],
        'content' => ['label' => 'Content', 'description' => 'Content consumption events'],
        'attribution' => ['label' => 'Attribution', 'description' => 'Campaign and traffic attribution events'],
    ];

    /** @var array<string, list<string>> Computed tag map (built-in + custom overrides) */
    private array $tagMap;

    private bool $enabled;

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config): void
    {
        $taxonomyConfig = $config->get('zeroboiler.analytics.taxonomy', []);
        /** @var array{enabled?: bool, custom_tags?: array<string, list<string>>, disabled_tags?: list<string>} $taxonomyConfig */

        $this->enabled = (bool) ($taxonomyConfig['enabled'] ?? true);

        // Start with built-in tags
        $this->tagMap = self::TAG_MAP;

        // Apply custom tag overrides from config
        $customTags = $taxonomyConfig['custom_tags'] ?? [];
        /** @var array<string, list<string>> $customTags */
        foreach ($customTags as $event => $tags) {
            $this->tagMap[$event] = $tags;
        }

        // Remove events from disabled tags
        $disabledTags = $taxonomyConfig['disabled_tags'] ?? [];
        /** @var list<string> $disabledTags */
        if (! empty($disabledTags)) {
            $disabledSet = array_flip($disabledTags);
            foreach ($this->tagMap as $event => $tags) {
                $this->tagMap[$event] = array_values(array_filter(
                    $tags,
                    fn (string $tag): bool => ! isset($disabledSet[$tag]),
                ));
            }
        }
    }

    /**
     * Get all tags assigned to an event.
     *
     * @return list<string>
     */
    public function tagsFor(string $eventName): array
    {
        return $this->tagMap[$eventName] ?? [];
    }

    /**
     * Check if an event has a specific tag.
     */
    public function hasTag(string $eventName, string $tag): bool
    {
        return in_array($tag, $this->tagsFor($eventName), true);
    }

    /**
     * Get all events that have a specific tag.
     *
     * @return list<string> Event names with the given tag
     */
    public function eventsWithTag(string $tag): array
    {
        $events = [];

        foreach ($this->tagMap as $event => $tags) {
            if (in_array($tag, $tags, true)) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * Get all events matching any of the given tags (OR logic).
     *
     * @param  list<string>  $tags
     * @return list<string> Event names matching at least one tag
     */
    public function eventsWithAnyTag(array $tags): array
    {
        return array_values(array_unique(array_merge(
            ...array_map(
                fn (string $tag): array => $this->eventsWithTag($tag),
                $tags,
            ),
        )));
    }

    /**
     * Get all events matching all of the given tags (AND logic).
     *
     * @param  list<string>  $tags
     * @return list<string> Event names matching all tags
     */
    public function eventsWithAllTags(array $tags): array
    {
        if ($tags === []) {
            return [];
        }

        $sets = array_map(
            fn (string $tag): array => array_flip($this->eventsWithTag($tag)),
            $tags,
        );

        // Intersect all sets
        $intersection = array_shift($sets) ?? [];

        foreach ($sets as $set) {
            $intersection = array_intersect_key($intersection, $set);
        }

        return array_keys($intersection);
    }

    /**
     * Check if the taxonomy service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the full tag map (event → tags).
     *
     * @return array<string, list<string>>
     */
    public function tagMap(): array
    {
        return $this->tagMap;
    }

    /**
     * Get all unique tags across all events.
     *
     * @return list<string>
     */
    public function allTags(): array
    {
        return array_values(array_unique(array_merge(
            ...array_values($this->tagMap),
        )));
    }

    /**
     * Get tag count (number of unique tags in use).
     */
    public function tagCount(): int
    {
        return count($this->allTags());
    }

    /**
     * Get all tag definitions with labels and descriptions.
     *
     * @return array<string, array{label: string, description: string, event_count: int}>
     */
    public function tagDefinitions(): array
    {
        $definitions = [];

        foreach (self::TAG_DEFINITIONS as $tag => $def) {
            $definitions[$tag] = [
                'label' => $def['label'],
                'description' => $def['description'],
                'event_count' => count($this->eventsWithTag($tag)),
            ];
        }

        return $definitions;
    }

    /**
     * Get events grouped by tag.
     *
     * Returns an associative array where each key is a tag name and
     * the value is a list of event names that have that tag.
     *
     * @return array<string, list<string>>
     */
    public function eventsGroupedByTag(): array
    {
        $grouped = [];

        foreach ($this->tagMap as $event => $tags) {
            foreach ($tags as $tag) {
                $grouped[$tag][] = $event;
            }
        }

        // Sort each group alphabetically
        foreach ($grouped as &$events) {
            sort($events);
        }
        unset($events);

        return $grouped;
    }

    /**
     * Get the number of tagged events (events that have at least one tag).
     */
    public function taggedEventCount(): int
    {
        return count(array_filter(
            $this->tagMap,
            fn (array $tags): bool => $tags !== [],
        ));
    }

    /**
     * Get the total number of events in the catalog.
     */
    public function totalEventCount(): int
    {
        return EventCatalog::count();
    }

    /**
     * Get tag coverage ratio (tagged events / total events).
     */
    public function coverageRatio(): float
    {
        $total = $this->totalEventCount();

        if ($total === 0) {
            return 0.0;
        }

        return round($this->taggedEventCount() / $total, 4);
    }

    /**
     * Check if an event is a revenue event.
     */
    public function isRevenueEvent(string $eventName): bool
    {
        return $this->hasTag($eventName, 'revenue');
    }

    /**
     * Check if an event is a conversion event.
     */
    public function isConversionEvent(string $eventName): bool
    {
        return $this->hasTag($eventName, 'conversion');
    }

    /**
     * Check if an event is an error event.
     */
    public function isErrorEvent(string $eventName): bool
    {
        return $this->hasTag($eventName, 'error');
    }

    /**
     * Check if an event is a lifecycle event.
     */
    public function isLifecycleEvent(string $eventName): bool
    {
        return $this->hasTag($eventName, 'lifecycle');
    }

    /**
     * Check if an event is a funnel event.
     */
    public function isFunnelEvent(string $eventName): bool
    {
        return $this->hasTag($eventName, 'funnel');
    }

    /**
     * Check if an event is a churn-related event.
     */
    public function isChurnEvent(string $eventName): bool
    {
        return $this->hasTag($eventName, 'churn');
    }

    /**
     * Get all revenue events.
     *
     * @return list<string>
     */
    public function revenueEvents(): array
    {
        return $this->eventsWithTag('revenue');
    }

    /**
     * Get all conversion events.
     *
     * @return list<string>
     */
    public function conversionEvents(): array
    {
        return $this->eventsWithTag('conversion');
    }

    /**
     * Get all error events.
     *
     * @return list<string>
     */
    public function errorEvents(): array
    {
        return $this->eventsWithTag('error');
    }

    /**
     * Get all funnel events.
     *
     * @return list<string>
     */
    public function funnelEvents(): array
    {
        return $this->eventsWithTag('funnel');
    }

    /**
     * Get a summary of the taxonomy service state.
     *
     * @return array{enabled: bool, total_events: int, tagged_events: int, coverage: float, tag_count: int, tags: list<string>, tag_definitions: array<string, array{label: string, description: string, event_count: int}>}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'total_events' => $this->totalEventCount(),
            'tagged_events' => $this->taggedEventCount(),
            'coverage' => $this->coverageRatio(),
            'tag_count' => $this->tagCount(),
            'tags' => $this->allTags(),
            'tag_definitions' => $this->tagDefinitions(),
        ];
    }

    /**
     * Register additional tags at runtime.
     *
     * @param  string  $eventName  Event to tag
     * @param  list<string>  $tags  Tags to assign
     */
    public function addTags(string $eventName, array $tags): void
    {
        $existing = $this->tagMap[$eventName] ?? [];
        $merged = array_values(array_unique(array_merge($existing, $tags)));
        $this->tagMap[$eventName] = $merged;
    }

    /**
     * Remove tags from an event at runtime.
     *
     * @param  string  $eventName  Event to modify
     * @param  list<string>  $tags  Tags to remove
     */
    public function removeTags(string $eventName, array $tags): void
    {
        if (! isset($this->tagMap[$eventName])) {
            return;
        }

        $removeSet = array_flip($tags);
        $this->tagMap[$eventName] = array_values(array_filter(
            $this->tagMap[$eventName],
            fn (string $tag): bool => ! isset($removeSet[$tag]),
        ));
    }
}
