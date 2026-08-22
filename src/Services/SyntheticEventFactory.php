<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;

/**
 * Generates realistic synthetic analytics events for development, staging,
 * and load-testing environments.  Produces statistically plausible events
 * with configurable distributions, temporal patterns, and per-category
 * weightings so the analytics pipeline can be exercised at any scale.
 *
 * @since 197.0.0
 */
final class SyntheticEventFactory
{
    /** @var array<string, float> Category → relative weight */
    private array $categoryWeights;

    /** @var int Minimum seconds between consecutive events in a session */
    private int $minEventInterval;

    /** @var int Maximum seconds between consecutive events in a session */
    private int $maxEventInterval;

    /** @var positive-int Number of events per generated session (approx) */
    private int $sessionDepth;

    /** @var list<string> Catalog pools that may appear in output */
    private array $pools;

    /** @var list<string> All event names that may be generated */
    private array $eventPool;

    /** @var list<string> Only ecommerce event names */
    private array $ecommercePool;

    /** @var list<string> Only SaaS event names */
    private array $saasPool;

    /** @var list<string> Only engagement event names */
    private array $engagementPool;

    /** @var list<string> Revenue-bearing event names */
    private array $revenuePool;

    /** @var list<string> Conversion funnel event names (sign_up → trial_start → subscribe) */
    private const CONVERSION_FUNNEL = ['sign_up', 'start_trial', 'subscribe', 'plan_upgrade'];

    /** @var array<string, string> Seed user IDs for deterministic generation */
    private const SEED_USERS = [
        'user_synthetic_001', 'user_synthetic_002', 'user_synthetic_003',
        'user_synthetic_004', 'user_synthetic_005', 'user_synthetic_006',
        'user_synthetic_007', 'user_synthetic_008', 'user_synthetic_009',
        'user_synthetic_010',
    ];

    /** @var list<string> Sample item IDs for e-commerce events */
    private const SEED_ITEMS = [
        'SKU-WIDGET-A', 'SKU-GIZMO-B', 'SKU-SUBSCRIPTION-PRO',
        'SKU-PLAN-ENTERPRISE', 'SKU-ADDON-API', 'SKU-CREDIT-100',
    ];

    /** @var list<string> Sample page paths for engagement events */
    private const SEED_PAGES = [
        '/', '/pricing', '/features', '/docs/getting-started',
        '/dashboard', '/settings/billing', '/api/reference',
        '/blog/product-update', '/integrations', '/changelog',
    ];

    /** @var list<string> Sample search terms */
    private const SEED_SEARCHES = [
        'analytics', 'pricing', 'api', 'integration', 'gdpr',
        'export', 'real-time', 'dashboard', 'segmentation', 'funnel',
    ];

    /**
     * @param  array<string, float>|null  $categoryWeights  Override per-category generation weights
     * @param  int|null  $sessionDepth  Approximate events per generated session (default 8)
     */
    public function __construct(
        ?array $categoryWeights = null,
        ?int $sessionDepth = null,
    ){
        $this->categoryWeights = $categoryWeights ?? [
            'ecommerce'  => 0.25,
            'saas'       => 0.35,
            'engagement' => 0.40,
        ];
        $this->sessionDepth = $sessionDepth ?? 8;
        $this->minEventInterval = 2;
        $this->maxEventInterval = 120;

        // Build event pools from catalogs
        $this->ecommercePool  = EcommerceEvents::names();
        $this->saasPool       = SaaSEvents::names();
        $this->engagementPool = EngagementEvents::names();
        $this->revenuePool    = array_intersect(
            EcommerceEvents::names(),
            ['purchase', 'refund', 'begin_checkout'],
        );

        // Full pool respects weights
        $this->eventPool = $this->buildWeightedPool();
        $this->pools     = ['ecommerce', 'saas', 'engagement'];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Public API
    // ──────────────────────────────────────────────────────────────────

    /**
     * Generate a single random synthetic event.
     *
     * @param  string|null  $category  Restrict to 'ecommerce', 'saas', or 'engagement' (null = any)
     * @param  string|null  $clientId  Override client ID
     * @param  string|null  $userId    Override user ID
     */
    public function generateEvent(
        ?string $category = null,
        ?string $clientId = null,
        ?string $userId = null,
    ): AnalyticsEvent {
        $name   = $category === null
            ? $this->pickRandom($this->eventPool)
            : $this->pickFromCategory($category);
        $params = $this->buildEventParams($name, $category ?? $this->resolveCategory($name));

        return new AnalyticsEvent(
            name:     $name,
            params:   $params,
            clientId: $clientId ?? $this->generateClientId(),
            userId:   $userId ?? $this->pickRandom(self::SEED_USERS),
        );
    }

    /**
     * Generate a batch of N synthetic events simulating a single user session.
     *
     * Events are ordered to mimic a realistic browsing journey with
     * temporal spacing and funnel-like progression where applicable.
     *
     * @return list<AnalyticsEvent>
     */
    public function generateSession(int $count, ?string $userId = null): array
    {
        $clientId = $this->generateClientId();
        $userId   = $userId ?? $this->pickRandom(self::SEED_USERS);
        $events   = [];
        $elapsed  = 0;

        // Always start with a page_view
        $events[] = $this->generateEvent('engagement', $clientId, $userId);

        for ($i = 1; $i < $count; $i++) {
            $elapsed += $this->minEventInterval + (int) mt_rand(0, $this->maxEventInterval - $this->minEventInterval);

            // Weighted: 60 % engagement, 20 % saas, 20 % ecommerce
            $roll = mt_rand(0, 99);
            $cat = $roll < 60 ? 'engagement' : ($roll < 80 ? 'saas' : 'ecommerce');

            $event = $this->generateEvent($cat, $clientId, $userId);
            $events[] = $event;
        }

        return $events;
    }

    /**
     * Generate a realistic SaaS conversion funnel sequence.
     *
     * Produces a complete journey: sign_up → login → start_trial →
     * feature_used → subscribe → plan_upgrade with engagement sprinkled in.
     *
     * @return list<AnalyticsEvent>
     */
    public function generateConversionFunnel(?string $userId = null): array
    {
        $clientId = $this->generateClientId();
        $userId   = $userId ?? $this->pickRandom(self::SEED_USERS);
        $events   = [];

        foreach (self::CONVERSION_FUNNEL as $eventName) {
            $events[] = new AnalyticsEvent(
                name:     $eventName,
                params:   $this->buildEventParams($eventName, 'saas'),
                clientId: $clientId,
                userId:   $userId,
            );

            // Sprinkle engagement events between funnel steps
            if ($eventName !== 'plan_upgrade') {
                $events[] = $this->generateEvent('engagement', $clientId, $userId);
            }
        }

        return $events;
    }

    /**
     * Generate a batch of N independent events (no session correlation).
     *
     * @return list<AnalyticsEvent>
     */
    public function generateBatch(int $count, ?string $category = null): array
    {
        $events = [];

        for ($i = 0; $i < $count; $i++) {
            $events[] = $this->generateEvent($category);
        }

        return $events;
    }

    /**
     * Generate N user sessions with M events each.
     *
     * @return list<list<AnalyticsEvent>>
     */
    public function generateMultipleSessions(int $userCount, int $eventsPerSession = 8): array
    {
        $sessions = [];

        for ($i = 0; $i < $userCount; $i++) {
            $userId  = self::SEED_USERS[$i % count(self::SEED_USERS)];
            $sessions[] = $this->generateSession($eventsPerSession, $userId);
        }

        return $sessions;
    }

    /**
     * Generate a revenue-weighted batch simulating e-commerce activity.
     *
     * Produces view_item → add_to_cart → begin_checkout → purchase
     * sequences with realistic item/price data.
     *
     * @return list<AnalyticsEvent>
     */
    public function generateEcommerceJourney(?string $clientId = null): array
    {
        $clientId = $clientId ?? $this->generateClientId();
        $userId   = $this->pickRandom(self::SEED_USERS);
        $events   = [];

        $steps = ['view_item', 'add_to_cart', 'begin_checkout', 'purchase'];

        foreach ($steps as $step) {
            $events[] = new AnalyticsEvent(
                name:     $step,
                params:   $this->buildEcommerceParams($step),
                clientId: $clientId,
                userId:   $userId,
            );
        }

        return $events;
    }

    /**
     * Get the current event pool size.
     */
    public function poolSize(): int
    {
        return count($this->eventPool);
    }

    /**
     * Get the per-category event counts.
     *
     * @return array{ecommerce: int, saas: int, engagement: int, total: int}
     */
    public function poolStats(): array
    {
        return [
            'ecommerce'  => count($this->ecommercePool),
            'saas'       => count($this->saasPool),
            'engagement' => count($this->engagementPool),
            'total'      => count($this->eventPool),
        ];
    }

    /**
     * Get a summary of available pools and configuration.
     *
     * @return array{category_weights: array<string, float>, session_depth: int, pools: array<string, int>, funnel_steps: list<string>}
     */
    public function configurationSummary(): array
    {
        return [
            'category_weights' => $this->categoryWeights,
            'session_depth'    => $this->sessionDepth,
            'pools'            => [
                'ecommerce'  => count($this->ecommercePool),
                'saas'       => count($this->saasPool),
                'engagement' => count($this->engagementPool),
            ],
            'funnel_steps'     => self::CONVERSION_FUNNEL,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Internal Helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Build a weighted event pool from all catalogs.
     *
     * @return list<string>
     */
    private function buildWeightedPool(): array
    {
        $pool = [];

        foreach (['ecommerce', 'saas', 'engagement'] as $cat) {
            $weight    = $this->categoryWeights[$cat] ?? 0.0;
            $repeats   = (int) max(1, round($weight * 100));
            $catalog   = $cat === 'ecommerce'
                ? $this->ecommercePool
                : ($cat === 'saas' ? $this->saasPool : $this->engagementPool);

            for ($i = 0; $i < $repeats; $i++) {
                foreach ($catalog as $name) {
                    $pool[] = $name;
                }
            }
        }

        return $pool;
    }

    /**
     * Pick a random event name from a specific category.
     *
     * @return string
     */
    private function pickFromCategory(string $category): string
    {
        return match ($category) {
            'ecommerce'  => $this->pickRandom($this->ecommercePool),
            'saas'       => $this->pickRandom($this->saasPool),
            'engagement' => $this->pickRandom($this->engagementPool),
            default      => $this->pickRandom($this->eventPool),
        };
    }

    /**
     * Resolve which catalog an event belongs to.
     */
    private function resolveCategory(string $eventName): string
    {
        if (EcommerceEvents::has($eventName)) {
            return 'ecommerce';
        }

        if (SaaSEvents::has($eventName)) {
            return 'saas';
        }

        return 'engagement';
    }

    /**
     * Build realistic event parameters based on event name and category.
     *
     * @return array<string, mixed>
     */
    private function buildEventParams(string $name, string $category): array
    {
        if ($category === 'ecommerce') {
            return $this->buildEcommerceParams($name);
        }

        if ($category === 'saas') {
            return $this->buildSaaSParams($name);
        }

        return $this->buildEngagementParams($name);
    }

    /**
     * Build e-commerce event parameters.
     *
     * @return array<string, mixed>
     */
    private function buildEcommerceParams(string $name): array
    {
        $itemId   = $this->pickRandom(self::SEED_ITEMS);
        $price    = round(mt_rand(500, 29900) / 100, 2);
        $quantity = (string) mt_rand(1, 5);
        $currency = 'USD';

        $base = [
            'item_id'    => $itemId,
            'item_name'  => str_replace('SKU-', '', $itemId),
            'currency'   => $currency,
            'value'      => $price,
        ];

        return match ($name) {
            'view_item' => $base,
            'add_to_cart' => array_merge($base, ['quantity' => (int) $quantity]),
            'begin_checkout' => array_merge($base, [
                'quantity'    => (int) $quantity,
                'items'       => [['item_id' => $itemId, 'price' => $price, 'quantity' => (int) $quantity]],
            ]),
            'purchase' => [
                'transaction_id' => 'TXN-SYN-' . bin2hex(random_bytes(4)),
                'value'          => $price * (int) $quantity,
                'currency'       => $currency,
                'tax'            => round($price * (int) $quantity * 0.08, 2),
                'shipping'       => (float) mt_rand(0, 1500) / 100,
                'items'          => [['item_id' => $itemId, 'price' => $price, 'quantity' => (int) $quantity]],
                'coupon'         => mt_rand(0, 1) === 1 ? 'SAVE10' : null,
            ],
            'refund' => [
                'transaction_id' => 'TXN-SYN-' . bin2hex(random_bytes(4)),
                'value'          => $price,
                'currency'       => $currency,
                'reason'         => $this->pickRandom(['customer_request', 'defective', 'wrong_item']),
            ],
            default => $base,
        };
    }

    /**
     * Build SaaS event parameters.
     *
     * @return array<string, mixed>
     */
    private function buildSaaSParams(string $name): array
    {
        $plan   = $this->pickRandom(['free', 'starter', 'pro', 'enterprise']);
        $source = $this->pickRandom(['direct', 'organic', 'paid_social', 'referral', 'email']);

        return match ($name) {
            'sign_up' => [
                'method'     => $this->pickRandom(['email', 'google', 'github']),
                'plan'       => 'free',
                'source'     => $source,
                'referrer'   => $this->pickRandom(self::SEED_PAGES),
            ],
            'login' => [
                'method' => $this->pickRandom(['email', 'sso', 'magic_link']),
            ],
            'start_trial' => [
                'plan'          => $this->pickRandom(['starter', 'pro']),
                'trial_days'    => (string) $this->pickRandom([7, 14, 30]),
                'source'        => $source,
            ],
            'subscribe' => [
                'plan'         => $plan,
                'value'        => round($this->pickRandom([0.0, 9.0, 29.0, 99.0, 299.0]), 2),
                'currency'     => 'USD',
                'billing_cycle' => $this->pickRandom(['monthly', 'annual']),
            ],
            'plan_upgrade' => [
                'from_plan' => $this->pickRandom(['free', 'starter', 'pro']),
                'to_plan'   => $plan,
                'value'     => round($this->pickRandom([9.0, 29.0, 99.0, 299.0]), 2),
                'currency'  => 'USD',
            ],
            'cancellation' => [
                'plan'   => $plan,
                'reason' => $this->pickRandom(['too_expensive', 'missing_features', 'switched_competitor', 'no_longer_needed']),
            ],
            'feature_used' => [
                'feature' => $this->pickRandom(['dashboard', 'api', 'export', 'reports', 'integrations', 'team_mgmt']),
                'count'   => mt_rand(1, 20),
            ],
            default => [
                'plan'   => $plan,
                'source' => $source,
            ],
        };
    }

    /**
     * Build engagement event parameters.
     *
     * @return array<string, mixed>
     */
    private function buildEngagementParams(string $name): array
    {
        $page = $this->pickRandom(self::SEED_PAGES);

        return match ($name) {
            'page_view' => [
                'page'     => $page,
                'title'    => 'Page: ' . $page,
                'referrer' => $this->pickRandom(array_merge(self::SEED_PAGES, ['https://google.com', 'https://twitter.com'])),
            ],
            'scroll_depth' => [
                'page'       => $page,
                'percentage' => $this->pickRandom([25, 50, 75, 90, 100]),
                'time_ms'    => mt_rand(1000, 30000),
            ],
            'click' => [
                'element'     => $this->pickRandom(['cta_button', 'nav_link', 'pricing_card', 'download_btn']),
                'page'        => $page,
                'target_url'  => $this->pickRandom(self::SEED_PAGES),
            ],
            'form_start' => [
                'form_name' => $this->pickRandom(['contact', 'signup', 'trial_request', 'demo']),
                'page'      => $page,
            ],
            'form_submit' => [
                'form_name' => $this->pickRandom(['contact', 'signup', 'trial_request', 'demo']),
                'page'      => $page,
                'success'   => true,
            ],
            'search' => [
                'term'      => $this->pickRandom(self::SEED_SEARCHES),
                'results'   => mt_rand(0, 50),
                'page'      => $page,
            ],
            'share' => [
                'method'    => $this->pickRandom(['twitter', 'linkedin', 'email', 'copy_link']),
                'page'      => $page,
                'url'       => $page,
            ],
            'error' => [
                'message' => $this->pickRandom(['TypeError: undefined', 'NetworkError', 'Timeout exceeded']),
                'severity' => $this->pickRandom(['warning', 'error', 'critical']),
                'page'     => $page,
            ],
            default => [
                'page' => $page,
            ],
        };
    }

    /**
     * Pick a random element from an array.
     *
     * @template T
     * @param  list<T>  $items
     * @return T
     */
    private function pickRandom(array $items): mixed
    {
        return $items[array_rand($items)];
    }

    /**
     * Generate a synthetic client ID.
     */
    private function generateClientId(): string
    {
        return 'syn_' . bin2hex(random_bytes(8));
    }
}
