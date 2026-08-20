<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events;

/**
 * Curated SaaS Starter Events — the 20 essential events every SaaS must track.
 *
 * Provides a focused subset of the full EventCatalog that represents the
 * minimum viable analytics instrumentation for a SaaS product. Events are
 * organized into 4 groups: SaaS Lifecycle, E-commerce, Engagement, and Growth.
 *
 * Use this as your instrumentation checklist — when all 20 events are being
 * tracked, you have industry-standard SaaS analytics coverage.
 *
 * Inspired by Segment's recommended events, PostHog's event taxonomy,
 * and Mixpanel's SaaS retention playbook.
 *
 * @since 210.0.0
 *
 * @see \ZeroBoiler\Analytics\Events\EventCatalog
 */
final class SaaSStarterEvents
{
    /**
     * The 20 essential SaaS events, grouped by category.
     *
     * Each entry contains the canonical catalog name, a human-readable label,
     * the category for quick filtering, and a brief instrumentation hint.
     *
     * @return array<string, array{name: string, label: string, category: 'saas'|'ecommerce'|'engagement', hint: string}>
     */
    public static function all(): array
    {
        return [
            // ── SaaS Lifecycle (7 events) ──────────────────────────
            'sign_up' => [
                'name' => 'sign_up',
                'label' => 'Sign Up',
                'category' => 'saas',
                'hint' => 'Fire on Illuminate\Auth\Events\Registered or custom registration',
            ],
            'login' => [
                'name' => 'login',
                'label' => 'Login',
                'category' => 'saas',
                'hint' => 'Auto-tracked via auth.login lifecycle mapping',
            ],
            'start_trial' => [
                'name' => 'start_trial',
                'label' => 'Trial Start',
                'category' => 'saas',
                'hint' => 'Fire when user activates a trial plan',
            ],
            'trial_converted' => [
                'name' => 'trial_converted',
                'label' => 'Trial Converted',
                'category' => 'saas',
                'hint' => 'Fire when trial user subscribes to a paid plan',
            ],
            'subscribe' => [
                'name' => 'subscribe',
                'label' => 'Subscription Created',
                'category' => 'saas',
                'hint' => 'Fire on subscription.created lifecycle event',
            ],
            'plan_upgrade' => [
                'name' => 'plan_upgrade',
                'label' => 'Plan Upgrade',
                'category' => 'saas',
                'hint' => 'Fire when user moves to a higher tier',
            ],
            'cancellation' => [
                'name' => 'cancellation',
                'label' => 'Cancellation',
                'category' => 'saas',
                'hint' => 'Fire on subscription.cancelled lifecycle event',
            ],

            // ── E-commerce (4 events) ────────────────────────────
            'view_item' => [
                'name' => 'view_item',
                'label' => 'View Item',
                'category' => 'ecommerce',
                'hint' => 'Fire on product/pricing page view',
            ],
            'add_to_cart' => [
                'name' => 'add_to_cart',
                'label' => 'Add to Cart',
                'category' => 'ecommerce',
                'hint' => 'Fire when user selects a plan or adds item',
            ],
            'purchase' => [
                'name' => 'purchase',
                'label' => 'Purchase',
                'category' => 'ecommerce',
                'hint' => 'Fire on successful payment completion',
            ],
            'refund' => [
                'name' => 'refund',
                'label' => 'Refund',
                'category' => 'ecommerce',
                'hint' => 'Fire on payment refund or credit issuance',
            ],

            // ── Engagement (6 events) ───────────────────────────
            'page_view' => [
                'name' => 'page_view',
                'label' => 'Page View',
                'category' => 'engagement',
                'hint' => 'Auto-tracked via client autoTrack.pageViews',
            ],
            'scroll_depth' => [
                'name' => 'scroll_depth',
                'label' => 'Scroll Depth',
                'category' => 'engagement',
                'hint' => 'Auto-tracked via client autoTrack.scrollDepth',
            ],
            'click' => [
                'name' => 'click',
                'label' => 'Click',
                'category' => 'engagement',
                'hint' => 'Fire on CTA, nav, or conversion-relevant clicks',
            ],
            'form_start' => [
                'name' => 'form_start',
                'label' => 'Form Start',
                'category' => 'engagement',
                'hint' => 'Fire on first interaction with a form',
            ],
            'form_submit' => [
                'name' => 'form_submit',
                'label' => 'Form Submit',
                'category' => 'engagement',
                'hint' => 'Fire on successful form submission',
            ],
            'search' => [
                'name' => 'search',
                'label' => 'Search',
                'category' => 'engagement',
                'hint' => 'Fire on in-app search queries',
            ],

            // ── Growth (3 events) ───────────────────────────────
            'share' => [
                'name' => 'share',
                'label' => 'Share',
                'category' => 'engagement',
                'hint' => 'Fire on referral link sharing or social share',
            ],
            'error' => [
                'name' => 'error',
                'label' => 'Error',
                'category' => 'engagement',
                'hint' => 'Auto-captured via client autoTrack.errorTracking',
            ],
            'feature_used' => [
                'name' => 'feature_used',
                'label' => 'Feature Used',
                'category' => 'saas',
                'hint' => 'Fire on first use of key product features (aha moments)',
            ],
        ];
    }

    /**
     * Get all 20 starter event names.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /**
     * Get the total number of starter events.
     */
    public static function count(): int
    {
        return count(self::all());
    }

    /**
     * Get events grouped by category.
     *
     * @return array{saas: list<string>, ecommerce: list<string>, engagement: list<string>}
     */
    public static function byCategory(): array
    {
        $groups = ['saas' => [], 'ecommerce' => [], 'engagement' => []];

        foreach (self::all() as $name => $entry) {
            $cat = $entry['category'];
            $groups[$cat][] = $name;
        }

        return $groups;
    }

    /**
     * Check if a given event is in the starter set.
     */
    public static function isStarterEvent(string $name): bool
    {
        return isset(self::all()[$name]);
    }

    /**
     * Check which starter events exist in the full EventCatalog.
     *
     * Returns a map of event name → boolean indicating catalog presence.
     *
     * @return array<string, bool>
     */
    public static function catalogPresence(): array
    {
        $presence = [];

        foreach (self::names() as $name) {
            $presence[$name] = EventCatalog::has($name);
        }

        return $presence;
    }

    /**
     * Get starter events that are NOT yet in the catalog.
     *
     * Useful for instrumentation gap analysis.
     *
     * @return list<string>
     */
    public static function missingFromCatalog(): array
    {
        return array_keys(array_filter(
            self::catalogPresence(),
            fn (bool $present): bool => ! $present,
        ));
    }

    /**
     * Calculate instrumentation coverage as a percentage.
     *
     * Returns what fraction of the 20 starter events are present
     * in the full EventCatalog (should be 100% for a complete package).
     */
    public static function coveragePercent(): float
    {
        $present = count(array_filter(self::catalogPresence()));
        $total = self::count();

        if ($total === 0) {
            return 0.0;
        }

        return round(($present / $total) * 100.0, 1);
    }

    /**
     * Get the recommended instrumentation priority order.
     *
     * Events are ordered by their impact on SaaS analytics maturity:
     * identity → activation → revenue → retention.
     *
     * @return list<string>
     */
    public static function priorityOrder(): array
    {
        return [
            // Identity (must-track first)
            'sign_up',
            'login',
            // Activation
            'start_trial',
            'feature_used',
            'page_view',
            // Revenue
            'subscribe',
            'plan_upgrade',
            'purchase',
            'view_item',
            'add_to_cart',
            'cancellation',
            'refund',
            'trial_converted',
            // Engagement & Retention
            'form_start',
            'form_submit',
            'click',
            'search',
            'scroll_depth',
            'share',
            // Error tracking
            'error',
        ];
    }

    /**
     * Build an instrumentation payload for the Inertia page props.
     *
     * Returns the client summary plus gap analysis and priority ordering,
     * designed to be injected into zbAnalytics.recommendedEvents by the
     * HandleInertiaAnalytics middleware.
     *
     * @return array{total: int, coverage: float, categories: array{saas: int, ecommerce: int, engagement: int}, events: list<array{name: string, label: string, category: string, hint: string, priority_index: int, is_gap: bool}>, gaps: list<string>, gapCount: int, priorityOrder: list<string>}
     */
    public static function instrumentationPayload(): array
    {
        $summary = self::clientSummary();
        $missing = self::missingFromCatalog();

        // Enrich events with priority order index and gap status
        $priorityIndexed = [];
        $priorityOrder = self::priorityOrder();
        foreach ($summary['events'] as $event) {
            $priorityIndex = array_search($event['name'], $priorityOrder, true);
            $priorityIndexed[] = [
                ...$event,
                'priority_index' => $priorityIndex !== false ? $priorityIndex : 999,
                'is_gap' => in_array($event['name'], $missing, true),
            ];
        }

        return [
            'total' => $summary['total'],
            'coverage' => $summary['coverage'],
            'categories' => $summary['categories'],
            'events' => $priorityIndexed,
            'gaps' => $missing,
            'gapCount' => count($missing),
            'priorityOrder' => $priorityOrder,
        ];
    }

    /**
     * Get provider coverage for each starter event.
     *
     * Returns a per-event breakdown showing which of the 8 providers
     * (ga4, meta, posthog, plausible, mixpanel, amplitude, tiktok, linkedin)
     * have a mapping for that event. Events with 100% coverage (all 8 providers)
     * are flagged as `fully_covered`.
     *
     * @return array<string, array{event: string, label: string, category: string, providers: array<string, string|null>, covered_count: int, total_providers: int, coverage_pct: float, fully_covered: bool}>
     *
     * @since 264.0.0
     */
    public static function providerCoverage(): array
    {
        $providers = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];
        $totalProviders = count($providers);
        $result = [];

        foreach (self::all() as $name => $entry) {
            $catalogEntry = EventCatalog::get($name);

            // If event is not in the unified catalog, report zero coverage
            if ($catalogEntry === null) {
                $result[$name] = [
                    'event' => $name,
                    'label' => $entry['label'],
                    'category' => $entry['category'],
                    'providers' => array_fill_keys($providers, null),
                    'covered_count' => 0,
                    'total_providers' => $totalProviders,
                    'coverage_pct' => 0.0,
                    'fully_covered' => false,
                ];

                continue;
            }

            $providerMap = [];
            $coveredCount = 0;

            foreach ($providers as $provider) {
                $mapped = $catalogEntry[$provider] ?? null;
                $providerMap[$provider] = $mapped;

                if ($mapped !== null && $mapped !== '') {
                    $coveredCount++;
                }
            }

            $result[$name] = [
                'event' => $name,
                'label' => $entry['label'],
                'category' => $entry['category'],
                'providers' => $providerMap,
                'covered_count' => $coveredCount,
                'total_providers' => $totalProviders,
                'coverage_pct' => round(($coveredCount / $totalProviders) * 100.0, 1),
                'fully_covered' => $coveredCount === $totalProviders,
            ];
        }

        return $result;
    }

    /**
     * Get a summary of provider coverage across all starter events.
     *
     * Returns per-provider counts, percentages, and lists of uncovered events.
     * Useful for admin dashboards and instrumentation gap analysis.
     *
     * @return array{providers: array<string, array{covered: int, total: int, pct: float, uncovered_events: list<string>}>, overall_pct: float, fully_covered_events: int, total_events: int}
     *
     * @since 264.0.0
     */
    public static function providerCoverageSummary(): array
    {
        $coverage = self::providerCoverage();
        $providers = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];
        $totalEvents = count($coverage);
        $providerSummary = [];
        $fullyCoveredCount = 0;

        foreach ($providers as $provider) {
            $covered = 0;
            $uncovered = [];

            foreach ($coverage as $name => $eventData) {
                $mapped = $eventData['providers'][$provider] ?? null;

                if ($mapped !== null && $mapped !== '') {
                    $covered++;
                } else {
                    $uncovered[] = $name;
                }
            }

            $providerSummary[$provider] = [
                'covered' => $covered,
                'total' => $totalEvents,
                'pct' => $totalEvents > 0 ? round(($covered / $totalEvents) * 100.0, 1) : 0.0,
                'uncovered_events' => $uncovered,
            ];
        }

        // Count fully covered events (all 8 providers)
        foreach ($coverage as $eventData) {
            if ($eventData['fully_covered']) {
                $fullyCoveredCount++;
            }
        }

        // Overall average coverage
        $allPcts = array_column($providerSummary, 'pct');
        $overallPct = count($allPcts) > 0 ? round(array_sum($allPcts) / count($allPcts), 1) : 0.0;

        return [
            'providers' => $providerSummary,
            'overall_pct' => $overallPct,
            'fully_covered_events' => $fullyCoveredCount,
            'total_events' => $totalEvents,
        ];
    }

    /**
     * Get a client-safe summary for instrumentation guidance.
     *
     * Returns a compact structure suitable for Inertia props or API response.
     *
     * @return array{total: int, coverage: float, categories: array{saas: int, ecommerce: int, engagement: int}, events: list<array{name: string, label: string, category: string, hint: string}>}
     */
    public static function clientSummary(): array
    {
        $events = [];
        $categoryCounts = ['saas' => 0, 'ecommerce' => 0, 'engagement' => 0];

        foreach (self::all() as $entry) {
            $events[] = [
                'name' => $entry['name'],
                'label' => $entry['label'],
                'category' => $entry['category'],
                'hint' => $entry['hint'],
            ];
            $categoryCounts[$entry['category']]++;
        }

        return [
            'total' => self::count(),
            'coverage' => self::coveragePercent(),
            'categories' => $categoryCounts,
            'events' => $events,
        ];
    }
}
