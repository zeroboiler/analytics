<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event Archetype System — Pre-defined SaaS funnel blueprints.
 *
 * Provides reusable event sequence templates for common SaaS patterns:
 * signup funnel, activation, trial conversion, expansion, retention.
 * Each archetype defines expected events, their order, timing thresholds,
 * and completion scoring.
 *
 * Use for:
 * - Instrumentation gap detection (which events in a funnel are missing)
 * - Completion scoring (what % of users complete each step)
 * - Funnel benchmarking (compare your funnel against industry blueprints)
 * - Auto-generating LifecycleEventMapper config from archetypes
 *
 * Configuration: `zeroboiler.analytics.archetypes`
 *
 * @see \ZeroBoiler\Analytics\Services\EventCorrelationService
 * @see \ZeroBoiler\Analytics\Services\FunnelAnalyticsService
 */
final class EventArchetypeService
{
    private const CACHE_PREFIX = 'zb_archetype_';

    /** @var array<string, array{name: string, description: string, steps: list<array{name: string, event: string, required: bool, weight: float, expected_window_seconds: int}>, completion_event: string|null, category: string}> */
    private static array $builtInArchetypes = [];

    private bool $enabled;

    private CacheRepository $cache;

    private int $cacheTtl;

    /** @var array<string, array{name: string, description: string, steps: list<array{name: string, event: string, required: bool, weight: float, expected_window_seconds: int}>, completion_event: string|null, category: string}> */
    private array $customArchetypes = [];

    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $archetypeConfig = $config->get('zeroboiler.analytics.archetypes', []);
        /** @var array{enabled?: bool, cache_ttl?: int, custom?: array<string, array{name?: string, description?: string, steps?: list<array{name?: string, event?: string, required?: bool, weight?: float, expected_window_seconds?: int}>, completion_event?: string|null, category?: string}>} $archetypeConfig */
        $this->enabled = (bool) ($archetypeConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($archetypeConfig['cache_ttl'] ?? 3600);

        // Load custom archetypes from config
        $customDefs = $archetypeConfig['custom'] ?? [];
        foreach ($customDefs as $key => $def) {
            $this->customArchetypes[$key] = [
                'name' => (string) ($def['name'] ?? $key),
                'description' => (string) ($def['description'] ?? ''),
                'steps' => array_map(
                    fn (array $step): array => [
                        'name' => (string) ($step['name'] ?? ''),
                        'event' => (string) ($step['event'] ?? ''),
                        'required' => (bool) ($step['required'] ?? true),
                        'weight' => (float) ($step['weight'] ?? 1.0),
                        'expected_window_seconds' => (int) ($step['expected_window_seconds'] ?? 86400),
                    ],
                    (array) ($def['steps'] ?? []),
                ),
                'completion_event' => $def['completion_event'] ?? null,
                'category' => (string) ($def['category'] ?? 'custom'),
            ];
        }
    }

    /**
     * Get all built-in archetype definitions (lazy init).
     *
     * @return array<string, array{name: string, description: string, steps: list<array{name: string, event: string, required: bool, weight: float, expected_window_seconds: int}>, completion_event: string|null, category: string}>
     */
    private static function builtInArchetypes(): array
    {
        if (self::$builtInArchetypes !== []) {
            return self::$builtInArchetypes;
        }

        self::$builtInArchetypes = [
            // ── Signup Funnel ──────────────────────────────────────────
            'signup_funnel' => [
                'name' => 'Signup Funnel',
                'description' => 'Landing page visit through account creation and email verification.',
                'steps' => [
                    ['name' => 'landing_view', 'event' => 'page_view', 'required' => true, 'weight' => 0.10, 'expected_window_seconds' => 3600],
                    ['name' => 'pricing_view', 'event' => 'page_view', 'required' => false, 'weight' => 0.05, 'expected_window_seconds' => 3600],
                    ['name' => 'signup_initiated', 'event' => 'form_start', 'required' => true, 'weight' => 0.15, 'expected_window_seconds' => 1800],
                    ['name' => 'account_created', 'event' => 'sign_up', 'required' => true, 'weight' => 0.35, 'expected_window_seconds' => 600],
                    ['name' => 'email_verified', 'event' => 'email_verified', 'required' => true, 'weight' => 0.20, 'expected_window_seconds' => 86400],
                    ['name' => 'first_login', 'event' => 'login', 'required' => true, 'weight' => 0.15, 'expected_window_seconds' => 172800],
                ],
                'completion_event' => 'signup_funnel_completed',
                'category' => 'acquisition',
            ],

            // ── Activation Funnel ─────────────────────────────────────
            'activation' => [
                'name' => 'Activation Funnel',
                'description' => 'Post-signup activation milestones that predict long-term retention.',
                'steps' => [
                    ['name' => 'profile_completed', 'event' => 'profile_updated', 'required' => true, 'weight' => 0.15, 'expected_window_seconds' => 604800],
                    ['name' => 'first_feature_used', 'event' => 'feature_used', 'required' => true, 'weight' => 0.30, 'expected_window_seconds' => 86400],
                    ['name' => 'first_search', 'event' => 'search', 'required' => false, 'weight' => 0.05, 'expected_window_seconds' => 172800],
                    ['name' => 'first_share', 'event' => 'share', 'required' => false, 'weight' => 0.05, 'expected_window_seconds' => 604800],
                    ['name' => 'integration_connected', 'event' => 'integration_connected', 'required' => false, 'weight' => 0.10, 'expected_window_seconds' => 604800],
                    ['name' => 'team_created', 'event' => 'team_created', 'required' => false, 'weight' => 0.10, 'expected_window_seconds' => 604800],
                    ['name' => 'return_visit', 'event' => 'page_view', 'required' => true, 'weight' => 0.25, 'expected_window_seconds' => 172800],
                ],
                'completion_event' => 'user_activated',
                'category' => 'activation',
            ],

            // ── Trial Conversion Funnel ───────────────────────────────
            'trial_conversion' => [
                'name' => 'Trial Conversion',
                'description' => 'Free trial through paid subscription conversion.',
                'steps' => [
                    ['name' => 'trial_started', 'event' => 'start_trial', 'required' => true, 'weight' => 0.20, 'expected_window_seconds' => 0],
                    ['name' => 'trial_active_usage', 'event' => 'feature_used', 'required' => true, 'weight' => 0.20, 'expected_window_seconds' => 604800],
                    ['name' => 'checkout_started', 'event' => 'begin_checkout', 'required' => true, 'weight' => 0.25, 'expected_window_seconds' => 86400],
                    ['name' => 'payment_info_added', 'event' => 'add_payment_info', 'required' => true, 'weight' => 0.15, 'expected_window_seconds' => 3600],
                    ['name' => 'purchase_completed', 'event' => 'purchase', 'required' => true, 'weight' => 0.20, 'expected_window_seconds' => 1800],
                ],
                'completion_event' => 'trial_converted',
                'category' => 'conversion',
            ],

            // ── E-Commerce Checkout Funnel ───────────────────────────
            'ecommerce_checkout' => [
                'name' => 'E-Commerce Checkout',
                'description' => 'Product discovery through purchase completion.',
                'steps' => [
                    ['name' => 'product_viewed', 'event' => 'view_item', 'required' => true, 'weight' => 0.10, 'expected_window_seconds' => 3600],
                    ['name' => 'added_to_cart', 'event' => 'add_to_cart', 'required' => true, 'weight' => 0.20, 'expected_window_seconds' => 3600],
                    ['name' => 'cart_viewed', 'event' => 'view_cart', 'required' => false, 'weight' => 0.05, 'expected_window_seconds' => 1800],
                    ['name' => 'checkout_started', 'event' => 'begin_checkout', 'required' => true, 'weight' => 0.25, 'expected_window_seconds' => 1800],
                    ['name' => 'payment_info', 'event' => 'add_payment_info', 'required' => true, 'weight' => 0.15, 'expected_window_seconds' => 600],
                    ['name' => 'purchase', 'event' => 'purchase', 'required' => true, 'weight' => 0.25, 'expected_window_seconds' => 600],
                ],
                'completion_event' => 'checkout_completed',
                'category' => 'ecommerce',
            ],

            // ── Expansion / Upsell Funnel ────────────────────────────
            'expansion' => [
                'name' => 'Expansion Funnel',
                'description' => 'Existing user upgrade path from lower to higher plans.',
                'steps' => [
                    ['name' => 'feature_limit_reached', 'event' => 'feature_limit_reached', 'required' => false, 'weight' => 0.15, 'expected_window_seconds' => 2592000],
                    ['name' => 'pricing_page_view', 'event' => 'page_view', 'required' => true, 'weight' => 0.20, 'expected_window_seconds' => 86400],
                    ['name' => 'plan_comparison', 'event' => 'page_view', 'required' => false, 'weight' => 0.05, 'expected_window_seconds' => 3600],
                    ['name' => 'checkout_started', 'event' => 'begin_checkout', 'required' => true, 'weight' => 0.25, 'expected_window_seconds' => 3600],
                    ['name' => 'upgrade_completed', 'event' => 'plan_upgrade', 'required' => true, 'weight' => 0.35, 'expected_window_seconds' => 1800],
                ],
                'completion_event' => 'expansion_completed',
                'category' => 'growth',
            ],

            // ── Retention Loop ───────────────────────────────────────
            'retention_loop' => [
                'name' => 'Retention Loop',
                'description' => 'Weekly engagement pattern that predicts long-term retention.',
                'steps' => [
                    ['name' => 'login', 'event' => 'login', 'required' => true, 'weight' => 0.20, 'expected_window_seconds' => 604800],
                    ['name' => 'core_feature_used', 'event' => 'feature_used', 'required' => true, 'weight' => 0.30, 'expected_window_seconds' => 604800],
                    ['name' => 'content_engaged', 'event' => 'content_engagement', 'required' => false, 'weight' => 0.10, 'expected_window_seconds' => 604800],
                    ['name' => 'search_performed', 'event' => 'search', 'required' => false, 'weight' => 0.10, 'expected_window_seconds' => 604800],
                    ['name' => 'value_delivered', 'event' => 'goal_conversion', 'required' => false, 'weight' => 0.15, 'expected_window_seconds' => 604800],
                    ['name' => 'return_next_week', 'event' => 'login', 'required' => true, 'weight' => 0.15, 'expected_window_seconds' => 1209600],
                ],
                'completion_event' => 'retention_loop_completed',
                'category' => 'retention',
            ],
        ];

        return self::$builtInArchetypes;
    }

    /**
     * Get all archetypes (built-in + custom).
     *
     * @return array<string, array{name: string, description: string, steps: list<array{name: string, event: string, required: bool, weight: float, expected_window_seconds: int}>, completion_event: string|null, category: string}>
     */
    public function all(): array
    {
        return array_merge(self::builtInArchetypes(), $this->customArchetypes);
    }

    /**
     * Get a specific archetype by key.
     *
     * @return array{name: string, description: string, steps: list<array{name: string, event: string, required: bool, weight: float, expected_window_seconds: int}>, completion_event: string|null, category: string}|null
     */
    public function get(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Get all archetype keys.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * Get archetypes grouped by category.
     *
     * @return array<string, list<string>>
     */
    public function byCategory(): array
    {
        $groups = [];
        foreach ($this->all() as $key => $archetype) {
            $category = $archetype['category'];
            $groups[$category] ??= [];
            $groups[$category][] = $key;
        }

        return $groups;
    }

    /**
     * Get all event names referenced across all archetypes.
     *
     * @return list<string>
     */
    public function allEventNames(): array
    {
        $events = [];
        foreach ($this->all() as $archetype) {
            foreach ($archetype['steps'] as $step) {
                $events[] = $step['event'];
            }
        }

        return array_values(array_unique($events));
    }

    /**
     * Detect instrumentation gaps: which archetype events are NOT in the EventCatalog.
     *
     * @return array{gaps: list<array{archetype: string, step: string, event: string}>, coverage_pct: float, total_steps: int, missing_steps: int}
     */
    public function detectGaps(): array
    {
        $catalogNames = array_flip(EventCatalog::names());
        $gaps = [];
        $totalSteps = 0;

        foreach ($this->all() as $archetypeKey => $archetype) {
            foreach ($archetype['steps'] as $step) {
                $totalSteps++;
                if (! isset($catalogNames[$step['event']])) {
                    $gaps[] = [
                        'archetype' => $archetypeKey,
                        'step' => $step['name'],
                        'event' => $step['event'],
                    ];
                }
            }
        }

        $missingSteps = count($gaps);
        $coveragePct = $totalSteps > 0 ? round((($totalSteps - $missingSteps) / $totalSteps) * 100, 1) : 100.0;

        return [
            'gaps' => $gaps,
            'coverage_pct' => $coveragePct,
            'total_steps' => $totalSteps,
            'missing_steps' => $missingSteps,
        ];
    }

    /**
     * Calculate the completion score for a given set of completed event names.
     *
     * @param  string  $archetypeKey  Archetype identifier
     * @param  list<string>  $completedEvents  List of event names the user has completed
     * @return array{score: float, max_score: float, completed_steps: list<string>, missing_steps: list<string>, pct: float}
     */
    public function completionScore(string $archetypeKey, array $completedEvents): array
    {
        $archetype = $this->get($archetypeKey);
        if ($archetype === null) {
            return ['score' => 0.0, 'max_score' => 0.0, 'completed_steps' => [], 'missing_steps' => [], 'pct' => 0.0];
        }

        $completedSet = array_flip($completedEvents);
        $score = 0.0;
        $maxScore = 0.0;
        $completedSteps = [];
        $missingSteps = [];

        foreach ($archetype['steps'] as $step) {
            $maxScore += $step['weight'];
            if (isset($completedSet[$step['event']])) {
                $score += $step['weight'];
                $completedSteps[] = $step['name'];
            } else {
                $missingSteps[] = $step['name'];
            }
        }

        $pct = $maxScore > 0.0 ? round(($score / $maxScore) * 100, 1) : 0.0;

        return [
            'score' => round($score, 4),
            'max_score' => round($maxScore, 4),
            'completed_steps' => $completedSteps,
            'missing_steps' => $missingSteps,
            'pct' => $pct,
        ];
    }

    /**
     * Generate a LifecycleEventMapper config snippet from an archetype.
     *
     * Useful for bootstrapping auto_track config from a chosen funnel blueprint.
     *
     * @return array<string, bool>
     */
    public function toLifecycleConfig(string $archetypeKey): array
    {
        $archetype = $this->get($archetypeKey);
        if ($archetype === null) {
            return [];
        }

        $config = [];
        foreach ($archetype['steps'] as $step) {
            $key = $step['event'];
            $config[$key] = true;
        }

        return $config;
    }

    /**
     * Get archetype summary for API responses.
     *
     * @return list<array{key: string, name: string, description: string, category: string, steps: int, required_steps: int, event_names: list<string>}>
     */
    public function summary(): array
    {
        $result = [];
        foreach ($this->all() as $key => $archetype) {
            $eventNames = array_values(array_unique(array_map(
                fn (array $step): string => $step['event'],
                $archetype['steps'],
            )));
            $requiredCount = count(array_filter(
                $archetype['steps'],
                fn (array $step): bool => $step['required'],
            ));

            $result[] = [
                'key' => $key,
                'name' => $archetype['name'],
                'description' => $archetype['description'],
                'category' => $archetype['category'],
                'steps' => count($archetype['steps']),
                'required_steps' => $requiredCount,
                'event_names' => $eventNames,
            ];
        }

        return $result;
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
