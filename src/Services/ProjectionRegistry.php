<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\ProjectionDefinition;

/**
 * Central registry for metric projection definitions.
 *
 * Stores and manages projection definitions (how to compute aggregate metrics
 * from event streams). Supports config-driven loading and programmatic registration.
 *
 * Built-in projections include:
 * - dau: Daily Active Users (unique_count of client_id on page_view, 24h window)
 * - weekly_signups: Weekly Sign-ups (count of sign_up, 7d window)
 * - trial_conversion_rate: Trial → Paid conversion (funnel_rate, start_trial → subscription, 30d window)
 * - avg_session_value: Average Revenue per User (average of value on purchase, 30d window)
 * - signup_to_purchase_ratio: Sign-up to Purchase ratio (ratio, sign_up / purchase, 7d window)
 *
 * @since 129.0.0
 */
final class ProjectionRegistry
{
    /** @var string Cache key prefix for projection definitions */
    private const CACHE_PREFIX = 'zb_projection_def_';

    /** @var string Cache key for the registry summary */
    private const CACHE_SUMMARY_KEY = 'zb_projection_registry_summary';

    /** @var string Cache TTL for registry summary (1 hour) */
    private const CACHE_SUMMARY_TTL = 3600;

    /** @var array<string, ProjectionDefinition> Registered projections keyed by name */
    private array $projections = [];

    /** @var list<string> Registration errors */
    private array $registrationErrors = [];

    private readonly CacheRepository $cache;

    public function __construct(CacheRepository $cache): void
    {
        $this->cache = $cache;
        $this->registerBuiltinProjections();
    }

    /**
     * Register a projection definition.
     *
     * @param  ProjectionDefinition  $definition
     * @return bool  True if registered successfully
     */
    public function register(ProjectionDefinition $definition): bool
    {
        $errors = $definition->validate();

        if (! empty($errors)) {
            $this->registrationErrors[$definition->name] = $errors;

            return false;
        }

        $this->projections[$definition->name] = $definition;

        return true;
    }

    /**
     * Get a projection definition by name.
     *
     * @param  string  $name
     * @return ProjectionDefinition|null
     */
    public function get(string $name): ?ProjectionDefinition
    {
        return $this->projections[$name] ?? null;
    }

    /**
     * Check if a projection is registered.
     *
     * @param  string  $name
     */
    public function has(string $name): bool
    {
        return isset($this->projections[$name]);
    }

    /**
     * Get all registered projection names.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->projections);
    }

    /**
     * Get all registered projections.
     *
     * @return array<string, ProjectionDefinition>
     */
    public function all(): array
    {
        return $this->projections;
    }

    /**
     * Count registered projections.
     */
    public function count(): int
    {
        return count($this->projections);
    }

    /**
     * Get projections by category.
     *
     * @param  string  $category
     * @return array<string, ProjectionDefinition>
     */
    public function byCategory(string $category): array
    {
        return array_filter(
            $this->projections,
            static fn (ProjectionDefinition $p): bool => $p->category === $category,
        );
    }

    /**
     * Get projections by tag.
     *
     * @param  string  $tag
     * @return array<string, ProjectionDefinition>
     */
    public function byTag(string $tag): array
    {
        return array_filter(
            $this->projections,
            static fn (ProjectionDefinition $p): bool => in_array($tag, $p->tags, true),
        );
    }

    /**
     * Get public projections (exposed without auth).
     *
     * @return array<string, ProjectionDefinition>
     */
    public function publicProjections(): array
    {
        return array_filter(
            $this->projections,
            static fn (ProjectionDefinition $p): bool => $p->public,
        );
    }

    /**
     * Get projections grouped by category.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function groupedByCategory(): array
    {
        $groups = [];

        foreach ($this->projections as $name => $definition) {
            $category = $definition->category ?? 'uncategorized';

            if (! isset($groups[$category])) {
                $groups[$category] = [];
            }

            $groups[$category][] = $definition->toArray();
        }

        return $groups;
    }

    /**
     * Remove a projection by name.
     *
     * @param  string  $name
     * @return bool  True if the projection existed and was removed
     */
    public function forget(string $name): bool
    {
        if (! isset($this->projections[$name])) {
            return false;
        }

        unset($this->projections[$name]);

        return true;
    }

    /**
     * Remove all projections.
     */
    public function flush(): void
    {
        $this->projections = [];
        $this->registrationErrors = [];
    }

    /**
     * Get registration errors.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->registrationErrors;
    }

    /**
     * Validate all registered projections.
     *
     * @return array{valid: int, invalid: int, errors: array<string, list<string>>}
     */
    public function validate(): array
    {
        $valid = 0;
        $invalid = 0;
        $errors = [];

        foreach ($this->projections as $name => $definition) {
            $defErrors = $definition->validate();

            if (empty($defErrors)) {
                $valid++;
            } else {
                $invalid++;
                $errors[$name] = $defErrors;
            }
        }

        return [
            'valid' => $valid,
            'invalid' => $invalid,
            'errors' => $errors,
        ];
    }

    /**
     * Get a summary of the registry.
     *
     * @return array{count: int, categories: array<string, int>, types: array<string, int>, public_count: int, names: list<string>}
     */
    public function summary(): array
    {
        $categories = [];
        $types = [];
        $publicCount = 0;

        foreach ($this->projections as $definition) {
            $category = $definition->category ?? 'uncategorized';
            $categories[$category] = ($categories[$category] ?? 0) + 1;
            $types[$definition->type] = ($types[$definition->type] ?? 0) + 1;

            if ($definition->public) {
                $publicCount++;
            }
        }

        return [
            'count' => count($this->projections),
            'categories' => $categories,
            'types' => $types,
            'public_count' => $publicCount,
            'names' => array_keys($this->projections),
        ];
    }

    /**
     * Load projection definitions from config.
     *
     * @param  array<string, array<string, mixed>>  $definitions
     * @return int  Number of projections registered
     */
    public function loadFromConfig(array $definitions): int
    {
        $count = 0;

        foreach ($definitions as $name => $config) {
            $config['name'] = $name;
            $definition = ProjectionDefinition::fromArray($config);

            if ($this->register($definition)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Register built-in SaaS metric projections.
     */
    private function registerBuiltinProjections(): void
    {
        $builtins = [
            'dau' => new ProjectionDefinition(
                name: 'dau',
                label: 'Daily Active Users',
                type: ProjectionDefinition::TYPE_UNIQUE_COUNT,
                event: 'page_view',
                distinctField: 'client_id',
                window: '24h',
                cacheTtl: 300,
                category: 'engagement',
                description: 'Number of unique users active in the last 24 hours',
                tags: ['saas', 'critical', 'engagement'],
                public: true,
            ),

            'weekly_signups' => new ProjectionDefinition(
                name: 'weekly_signups',
                label: 'Weekly Sign-ups',
                type: ProjectionDefinition::TYPE_COUNT,
                event: 'sign_up',
                window: '7d',
                cacheTtl: 600,
                category: 'growth',
                description: 'Number of new sign-ups in the last 7 days',
                tags: ['saas', 'growth'],
                public: true,
            ),

            'trial_conversion_rate' => new ProjectionDefinition(
                name: 'trial_conversion_rate',
                label: 'Trial Conversion Rate',
                type: ProjectionDefinition::TYPE_FUNNEL_RATE,
                event: 'start_trial',
                funnelTarget: 'subscription',
                window: '30d',
                cacheTtl: 1800,
                category: 'growth',
                description: 'Percentage of trial starts that convert to paid subscriptions',
                tags: ['saas', 'revenue', 'critical'],
                public: false,
            ),

            'avg_revenue_per_user' => new ProjectionDefinition(
                name: 'avg_revenue_per_user',
                label: 'Average Revenue per User',
                type: ProjectionDefinition::TYPE_AVERAGE,
                event: 'purchase',
                field: 'value',
                window: '30d',
                cacheTtl: 1800,
                category: 'revenue',
                description: 'Average purchase value per user in the last 30 days',
                tags: ['saas', 'revenue', 'ecommerce'],
                public: false,
            ),

            'signup_to_purchase_ratio' => new ProjectionDefinition(
                name: 'signup_to_purchase_ratio',
                label: 'Sign-up to Purchase Ratio',
                type: ProjectionDefinition::TYPE_RATIO,
                event: 'sign_up',
                ratioDenominator: 'purchase',
                window: '7d',
                cacheTtl: 600,
                category: 'growth',
                description: 'Ratio of sign-ups to purchases in the last 7 days',
                tags: ['saas', 'conversion'],
                public: false,
            ),

            'total_revenue_30d' => new ProjectionDefinition(
                name: 'total_revenue_30d',
                label: 'Total Revenue (30d)',
                type: ProjectionDefinition::TYPE_SUM,
                event: 'purchase',
                field: 'value',
                window: '30d',
                cacheTtl: 600,
                category: 'revenue',
                description: 'Sum of all purchase values in the last 30 days',
                tags: ['saas', 'revenue', 'critical'],
                public: false,
            ),

            'unique_purchasers_30d' => new ProjectionDefinition(
                name: 'unique_purchasers_30d',
                label: 'Unique Purchasers (30d)',
                type: ProjectionDefinition::TYPE_UNIQUE_COUNT,
                event: 'purchase',
                distinctField: 'user_id',
                window: '30d',
                cacheTtl: 600,
                category: 'revenue',
                description: 'Number of unique users who made a purchase in the last 30 days',
                tags: ['saas', 'revenue', 'ecommerce'],
                public: false,
            ),

            'form_completion_rate' => new ProjectionDefinition(
                name: 'form_completion_rate',
                label: 'Form Completion Rate',
                type: ProjectionDefinition::TYPE_FUNNEL_RATE,
                event: 'form_start',
                funnelTarget: 'form_submit',
                window: '7d',
                cacheTtl: 1800,
                category: 'engagement',
                description: 'Percentage of form starts that result in a form submission',
                tags: ['engagement', 'conversion'],
                public: false,
            ),

            'search_to_share_rate' => new ProjectionDefinition(
                name: 'search_to_share_rate',
                label: 'Search to Share Rate',
                type: ProjectionDefinition::TYPE_FUNNEL_RATE,
                event: 'search',
                funnelTarget: 'share',
                window: '7d',
                cacheTtl: 1800,
                category: 'engagement',
                description: 'Percentage of searches that lead to a share action',
                tags: ['engagement'],
                public: false,
            ),

            'cart_abandonment_rate' => new ProjectionDefinition(
                name: 'cart_abandonment_rate',
                label: 'Cart Abandonment Rate',
                type: ProjectionDefinition::TYPE_RATIO,
                event: 'remove_from_cart',
                ratioDenominator: 'add_to_cart',
                window: '7d',
                cacheTtl: 1800,
                category: 'ecommerce',
                description: 'Ratio of cart removals to cart additions (abandonment indicator)',
                tags: ['ecommerce', 'revenue'],
                public: false,
            ),

            'cancellation_rate_30d' => new ProjectionDefinition(
                name: 'cancellation_rate_30d',
                label: 'Cancellation Rate (30d)',
                type: ProjectionDefinition::TYPE_RATIO,
                event: 'cancellation',
                ratioDenominator: 'subscription',
                window: '30d',
                cacheTtl: 3600,
                category: 'retention',
                description: 'Ratio of cancellations to new subscriptions (churn indicator)',
                tags: ['saas', 'retention', 'critical'],
                public: false,
            ),

            'error_rate_24h' => new ProjectionDefinition(
                name: 'error_rate_24h',
                label: 'Error Rate (24h)',
                type: ProjectionDefinition::TYPE_RATIO,
                event: 'error',
                ratioDenominator: 'page_view',
                window: '24h',
                cacheTtl: 300,
                category: 'engagement',
                description: 'Ratio of error events to page views (application health)',
                tags: ['engagement', 'health'],
                public: false,
            ),

            'login_count_7d' => new ProjectionDefinition(
                name: 'login_count_7d',
                label: 'Login Count (7d)',
                type: ProjectionDefinition::TYPE_COUNT,
                event: 'login',
                window: '7d',
                cacheTtl: 600,
                category: 'engagement',
                description: 'Number of login events in the last 7 days',
                tags: ['saas', 'engagement'],
                public: false,
            ),
        ];

        foreach ($builtins as $name => $definition) {
            $this->projections[$name] = $definition;
        }

        Log::debug('ProjectionRegistry: registered built-in projections', [
            'count' => count($builtins),
            'names' => array_keys($builtins),
        ]);
    }
}
