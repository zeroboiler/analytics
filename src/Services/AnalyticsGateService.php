<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Feature-flag-style analytics access gate service.
 *
 * Controls which analytics features are available per user, per plan,
 * per tenant, or globally. Enables SaaS products to tier analytics
 * features (e.g. basic events on Free, cohorts/funnels on Pro, predictions on Enterprise).
 *
 * Configuration is read from `zeroboiler.analytics.gate`.
 *
 * Supports:
 * - Per-feature enable/disable (events, pageviews, ecommerce, cohorts, funnels, predictions, export, broadcast)
 * - Plan-based tier resolution (free, pro, enterprise)
 * - Per-tenant overrides
 * - Per-user overrides
 * - Feature dependency enforcement
 * - Runtime gate checking with caching
 *
 * @since 1.0.0
 */
final class AnalyticsGateService
{
    /**
     * All analytics features and their default availability.
     *
     * @var array<string, array{default: bool, depends_on?: list<string>, description: string}>
     */
    private const FEATURES = [
        'events' => [
            'default' => true,
            'description' => 'Track custom analytics events',
        ],
        'pageviews' => [
            'default' => true,
            'description' => 'Track page view events',
        ],
        'ecommerce' => [
            'default' => false,
            'depends_on' => ['events'],
            'description' => 'Track e-commerce events (purchase, cart, etc.)',
        ],
        'cohorts' => [
            'default' => false,
            'depends_on' => ['events'],
            'description' => 'Cohort analytics and retention reports',
        ],
        'funnels' => [
            'default' => false,
            'depends_on' => ['events'],
            'description' => 'Funnel analysis and drop-off reports',
        ],
        'predictions' => [
            'default' => false,
            'depends_on' => ['cohorts', 'funnels'],
            'description' => 'Predictive analytics and pattern detection',
        ],
        'export' => [
            'default' => false,
            'depends_on' => ['events'],
            'description' => 'Export analytics data (JSON, CSV)',
        ],
        'broadcast' => [
            'default' => false,
            'depends_on' => ['events'],
            'description' => 'Real-time event broadcasting',
        ],
        'alerts' => [
            'default' => false,
            'depends_on' => ['events'],
            'description' => 'Threshold-based alert rules',
        ],
        'profile' => [
            'default' => false,
            'depends_on' => ['events'],
            'description' => 'User analytics profile and LTV tracking',
        ],
        'attribution' => [
            'default' => false,
            'depends_on' => ['events'],
            'description' => 'UTM and first-touch/multi-touch attribution',
        ],
        'multi_tenant' => [
            'default' => false,
            'description' => 'Multi-tenant analytics isolation',
        ],
    ];

    /**
     * Plan tiers and their feature sets.
     *
     * @var array<string, array{label: string, features: array<string, bool>}>
     */
    private const PLAN_TIERS = [
        'free' => [
            'label' => 'Free',
            'features' => [
                'events' => true,
                'pageviews' => true,
            ],
        ],
        'starter' => [
            'label' => 'Starter',
            'features' => [
                'events' => true,
                'pageviews' => true,
                'ecommerce' => true,
            ],
        ],
        'pro' => [
            'label' => 'Pro',
            'features' => [
                'events' => true,
                'pageviews' => true,
                'ecommerce' => true,
                'cohorts' => true,
                'funnels' => true,
                'export' => true,
                'alerts' => true,
                'profile' => true,
                'attribution' => true,
            ],
        ],
        'enterprise' => [
            'label' => 'Enterprise',
            'features' => [
                'events' => true,
                'pageviews' => true,
                'ecommerce' => true,
                'cohorts' => true,
                'funnels' => true,
                'predictions' => true,
                'export' => true,
                'broadcast' => true,
                'alerts' => true,
                'profile' => true,
                'attribution' => true,
                'multi_tenant' => true,
            ],
        ],
    ];

    private bool $enabled;

    /** @var string The default plan for users without an explicit plan */
    private string $defaultPlan;

    /** @var string The attribute to read from the user model for plan resolution */
    private string $planAttribute;

    /** @var array<string, bool> Global feature overrides (from config) */
    private array $globalOverrides;

    /** @var array<string, array<string, bool>> Per-tenant feature overrides */
    private array $tenantOverrides;

    /** @var array<string, array<string, bool>> Per-user feature overrides (cache-keyed) */
    private array $userOverrides;

    /** @var string Cache prefix */
    private string $cachePrefix;

    /** @var int Cache TTL */
    private int $cacheTtl;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ){
        $gateConfig = $config->get('zeroboiler.analytics.gate', []);
        /** @var array{enabled?: bool, default_plan?: string, plan_attribute?: string, features?: array<string, bool>, tenants?: array<string, array<string, bool>>, cache_prefix?: string, cache_ttl?: int} $gateConfig */

        $this->enabled = (bool) ($gateConfig['enabled'] ?? false);
        $this->defaultPlan = (string) ($gateConfig['default_plan'] ?? 'free');
        $this->planAttribute = (string) ($gateConfig['plan_attribute'] ?? 'plan');
        $this->globalOverrides = $gateConfig['features'] ?? [];
        $this->tenantOverrides = $gateConfig['tenants'] ?? [];
        $this->userOverrides = [];
        $this->cachePrefix = (string) ($gateConfig['cache_prefix'] ?? 'zb_gate_');
        $this->cacheTtl = (int) ($gateConfig['cache_ttl'] ?? 1800);
    }

    /**
     * Check if a specific feature is allowed.
     *
     * Resolution order:
     * 1. Global override (from config)
     * 2. User override (from cache/config)
     * 3. Tenant override (from config)
     * 4. Plan tier features
     * 5. Feature default
     */
    public function allows(string $feature, ?string $userId = null, ?string $tenantId = null): bool
    {
        if (! $this->enabled) {
            return true; // Gate disabled = all features available
        }

        if (! isset(self::FEATURES[$feature])) {
            return false;
        }

        // 1. Global override takes highest priority
        if (isset($this->globalOverrides[$feature])) {
            return $this->globalOverrides[$feature];
        }

        // 2. User override
        if ($userId !== null) {
            $userOverride = $this->getUserOverrides($userId);
            if (isset($userOverride[$feature])) {
                if ($userOverride[$feature] && ! $this->checkDependencies($feature)) {
                    return false;
                }

                return $userOverride[$feature];
            }
        }

        // 3. Tenant override
        if ($tenantId !== null && isset($this->tenantOverrides[$tenantId][$feature])) {
            if ($this->tenantOverrides[$tenantId][$feature] && ! $this->checkDependencies($feature)) {
                return false;
            }

            return $this->tenantOverrides[$tenantId][$feature];
        }

        // 4. Resolve from plan
        $plan = $this->resolvePlan($userId);
        $planFeatures = self::PLAN_TIERS[$plan]['features'] ?? [];

        if (isset($planFeatures[$feature])) {
            if ($planFeatures[$feature] && ! $this->checkDependencies($feature)) {
                return false;
            }

            return $planFeatures[$feature];
        }

        // 5. Feature default
        if (self::FEATURES[$feature]['default'] && ! $this->checkDependencies($feature)) {
            return false;
        }

        return self::FEATURES[$feature]['default'];
    }

    /**
     * Check if an event type is allowed based on its category.
     *
     * Maps event categories to gate features:
     * - ecommerce events → 'ecommerce' feature
     * - saas cohort events → 'cohorts' feature
     * - saas lifecycle events → 'events' feature
     * - engagement events → 'pageviews' or 'events' feature
     */
    public function allowsEvent(AnalyticsEvent $event, ?string $userId = null, ?string $tenantId = null): bool
    {
        if (! $this->enabled) {
            return true;
        }

        $feature = match ($event->name) {
            'page_view', 'screen_view' => 'pageviews',
            default => 'events',
        };

        $category = \ZeroBoiler\Analytics\Events\EventCatalog::getCategory($event->name);

        if ($category === 'ecommerce') {
            $feature = 'ecommerce';
        } elseif ($category === 'saas' && str_starts_with($event->name, 'cohort_')) {
            $feature = 'cohorts';
        }

        return $this->allows($feature, $userId, $tenantId);
    }

    /**
     * Get all features with their current availability for a user/tenant.
     *
     * @param  string|null  $userId
     * @param  string|null  $tenantId
     * @return array<string, bool>
     */
    public function getAvailableFeatures(?string $userId = null, ?string $tenantId = null): array
    {
        $features = [];

        foreach (array_keys(self::FEATURES) as $feature) {
            $features[$feature] = $this->allows($feature, $userId, $tenantId);
        }

        return $features;
    }

    /**
     * Get the resolved plan for a user.
     */
    public function resolvePlan(?string $userId = null): string
    {
        if ($userId === null) {
            $user = request()->user();
        } else {
            $user = null;
            try {
                $model = $this->config->get('auth.providers.users.model');
                if ($model !== null && is_string($model) && class_exists($model)) {
                    $user = (new $model)->newQuery()->find($userId);
                }
            } catch (\Throwable $e) {
                return $this->defaultPlan;
            }
        }

        if ($user === null) {
            return $this->defaultPlan;
        }

        foreach ([$this->planAttribute, 'subscription_plan', 'billing_plan', 'role'] as $attr) {
            if (method_exists($user, 'getAttribute')) {
                $value = $user->getAttribute($attr);
            } elseif (property_exists($user, $attr)) {
                $value = $user->{$attr};
            } else {
                continue;
            }

            if (is_string($value) && isset(self::PLAN_TIERS[$value])) {
                return $value;
            }
        }

        return $this->defaultPlan;
    }

    /**
     * Set a user-level feature override.
     *
     * @param  string  $userId
     * @param  string  $feature
     * @param  bool  $allowed
     */
    public function setUserFeature(string $userId, string $feature, bool $allowed): void
    {
        $overrides = $this->getUserOverrides($userId);
        $overrides[$feature] = $allowed;

        $cacheKey = $this->cachePrefix . 'user_' . $userId;
        $this->cache->put($cacheKey, $overrides, $this->cacheTtl);
        $this->userOverrides[$userId] = $overrides;
    }

    /**
     * Get all feature definitions.
     *
     * @return array<string, array{default: bool, depends_on?: list<string>, description: string}>
     */
    public static function getFeatureDefinitions(): array
    {
        return self::FEATURES;
    }

    /**
     * Get all plan tier definitions.
     *
     * @return array<string, array{label: string, features: array<string, bool>}>
     */
    public static function getPlanTiers(): array
    {
        return self::PLAN_TIERS;
    }

    /**
     * Check if the gate is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get a comprehensive gate summary.
     *
     * @return array{enabled: bool, default_plan: string, features_count: int, plan_attribute: string, available_features: array<string, bool>}
     */
    public function summary(?string $userId = null, ?string $tenantId = null): array
    {
        return [
            'enabled' => $this->enabled,
            'default_plan' => $this->defaultPlan,
            'features_count' => count(self::FEATURES),
            'plan_attribute' => $this->planAttribute,
            'available_features' => $this->getAvailableFeatures($userId, $tenantId),
        ];
    }

    /**
     * Check if all dependencies for a feature are satisfied.
     */
    private function checkDependencies(string $feature): bool
    {
        $dependencies = self::FEATURES[$feature]['depends_on'] ?? [];

        foreach ($dependencies as $dep) {
            if (! $this->allows($dep)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get user overrides from cache or in-memory.
     *
     * @param  string  $userId
     * @return array<string, bool>
     */
    private function getUserOverrides(string $userId): array
    {
        if (isset($this->userOverrides[$userId])) {
            return $this->userOverrides[$userId];
        }

        $cacheKey = $this->cachePrefix . 'user_' . $userId;
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            $this->userOverrides[$userId] = $cached;

            return $cached;
        }

        return [];
    }
}
