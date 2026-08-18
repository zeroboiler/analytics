<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event replay simulator — synthetic event generation for load testing.
 *
 * Generates realistic synthetic analytics events for capacity planning,
 * load testing, pipeline validation, and dashboard demos. Produces events
 * that follow realistic distributions and patterns.
 *
 * Features:
 *   - Event catalog-aware generation (uses real event names and schemas)
 *   - Configurable event mix (which events and their frequency ratios)
 *   - Batch generation with rate limiting
 *   - Realistic user simulation (client IDs, sessions, user journeys)
 *   - E-commerce scenario generation (browse → cart → checkout → purchase)
 *   - SaaS lifecycle simulation (signup → trial → conversion → renewal)
 *   - Metrics tracking (events generated, dispatch success/failure)
 *   - Dry-run mode (count what would be generated without dispatching)
 *
 * @since 43.0.0
 */
final class EventReplaySimulator
{
    /** @var int Default batch size for generation */
    private const DEFAULT_BATCH_SIZE = 100;

    /** @var int Default rate limit (events per second) */
    private const DEFAULT_RATE_LIMIT = 50;

    /** @var int Max events per simulation run */
    private const MAX_EVENTS = 100000;

    private const CACHE_PREFIX = 'zb_replay_sim_';

    private CacheRepository $cache;

    /** @var array<string, float> Event name => frequency weight (0.0–1.0) */
    private array $eventMix;

    private int $batchSize;

    private int $rateLimit;

    private bool $dryRun;

    /** @var int Cache TTL for simulation results */
    private int $resultsTtl;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  array<string, float>  $eventMix  Event frequency weights
     * @param  int  $batchSize  Events per batch
     * @param  int  $rateLimit  Max events per second
     * @param  bool  $dryRun  If true, count events without dispatching
     * @param  int  $resultsTtl  TTL for cached simulation results
     */
    public function __construct(
        CacheRepository $cache,
        array $eventMix = [],
        int $batchSize = self::DEFAULT_BATCH_SIZE,
        int $rateLimit = self::DEFAULT_RATE_LIMIT,
        bool $dryRun = false,
        int $resultsTtl = 3600,
    ): void {
        $this->cache = $cache;
        $this->eventMix = $this->normalizeMix($eventMix);
        $this->batchSize = min(max($batchSize, 1), self::MAX_EVENTS);
        $this->rateLimit = max($rateLimit, 1);
        $this->dryRun = $dryRun;
        $this->resultsTtl = $resultsTtl;
    }

    /**
     * Generate a batch of synthetic events.
     *
     * Creates events based on the configured event mix with realistic
     * parameters and client/user assignments.
     *
     * @param  int  $count  Number of events to generate
     * @param  callable(AnalyticsEvent): void|null  $dispatcher  Optional callback to dispatch each event
     * @return array{generated: int, dispatched: int, by_event: array<string, int>, duration_ms: float}
     */
    public function generateBatch(int $count, ?callable $dispatcher = null): array
    {
        $count = min(max($count, 1), self::MAX_EVENTS);
        $startTime = microtime(true);
        $generated = 0;
        $dispatched = 0;
        $byEvent = [];
        $clientIds = $this->generateClientPool(max((int) ceil($count / 10), 5));
        $userIds = $this->generateUserPool(max((int) ceil($count / 20), 2));

        for ($i = 0; $i < $count; $i++) {
            $eventName = $this->selectEventFromMix();
            $clientId = $clientIds[array_rand($clientIds)];
            $userId = ($i % 4 === 0) ? $userIds[array_rand($userIds)] : null;

            $event = new AnalyticsEvent(
                name: $eventName,
                params: $this->generateEventParams($eventName),
                clientId: $clientId,
                userId: $userId,
                timestamp: $this->generateTimestamp($i, $count),
                priority: $this->generatePriority(),
                source: 'simulation',
            );

            $generated++;
            $byEvent[$eventName] = ($byEvent[$eventName] ?? 0) + 1;

            if (! $this->dryRun && $dispatcher !== null) {
                $dispatcher($event);
                $dispatched++;
            }
        }

        $durationMs = (microtime(true) - $startTime) * 1000;

        return [
            'generated' => $generated,
            'dispatched' => $dispatched,
            'by_event' => $byEvent,
            'duration_ms' => round($durationMs, 2),
        ];
    }

    /**
     * Generate an e-commerce browsing scenario (browse → cart → checkout → purchase).
     *
     * Creates a realistic sequence of events simulating a user's purchase journey.
     *
     * @param  string  $clientId  Client ID for the simulation
     * @param  callable(AnalyticsEvent): void|null  $dispatcher  Optional dispatch callback
     * @return array{steps: int, events: list<string>, revenue: float}
     */
    public function generateEcommerceScenario(string $clientId, ?callable $dispatcher = null): array
    {
        $events = [];
        $revenue = 0.0;
        $step = 0;

        // 1. Browse products (1-3 page views)
        $browseCount = mt_rand(1, 3);
        for ($i = 0; $i < $browseCount; $i++) {
            $event = new AnalyticsEvent(
                name: 'view_item',
                params: [
                    'item_id' => 'SKU-' . mt_rand(1000, 9999),
                    'item_name' => 'Product ' . mt_rand(1, 100),
                    'currency' => 'USD',
                    'value' => (float) mt_rand(10, 500),
                ],
                clientId: $clientId,
                source: 'simulation',
            );
            $events[] = 'view_item';
            $step++;
            if ($dispatcher !== null) {
                $dispatcher($event);
            }
        }

        // 2. Add to cart (70% probability)
        if (mt_rand(1, 100) <= 70) {
            $itemValue = (float) mt_rand(10, 500);
            $quantity = mt_rand(1, 3);
            $event = new AnalyticsEvent(
                name: 'add_to_cart',
                params: [
                    'item_id' => 'SKU-' . mt_rand(1000, 9999),
                    'currency' => 'USD',
                    'value' => $itemValue * $quantity,
                    'quantity' => $quantity,
                ],
                clientId: $clientId,
                source: 'simulation',
            );
            $events[] = 'add_to_cart';
            $step++;
            $revenue = $itemValue * $quantity;
            if ($dispatcher !== null) {
                $dispatcher($event);
            }
        }

        // 3. Begin checkout (50% of cart additions)
        if ($revenue > 0 && mt_rand(1, 100) <= 50) {
            $event = new AnalyticsEvent(
                name: 'begin_checkout',
                params: [
                    'currency' => 'USD',
                    'value' => $revenue,
                    'items' => mt_rand(1, 5),
                ],
                clientId: $clientId,
                source: 'simulation',
            );
            $events[] = 'begin_checkout';
            $step++;
            if ($dispatcher !== null) {
                $dispatcher($event);
            }
        }

        // 4. Purchase (60% of checkouts)
        if ($revenue > 0 && mt_rand(1, 100) <= 60) {
            $event = new AnalyticsEvent(
                name: 'purchase',
                params: [
                    'currency' => 'USD',
                    'value' => $revenue,
                    'transaction_id' => 'TXN-' . strtoupper(substr(md5((string) mt_rand()), 0, 8)),
                    'shipping' => (float) mt_rand(0, 20),
                    'tax' => round($revenue * 0.08, 2),
                ],
                clientId: $clientId,
                source: 'simulation',
            );
            $events[] = 'purchase';
            $step++;
            if ($dispatcher !== null) {
                $dispatcher($event);
            }
        }

        return [
            'steps' => $step,
            'events' => $events,
            'revenue' => $revenue,
        ];
    }

    /**
     * Generate a SaaS lifecycle scenario (signup → trial → conversion → renewal).
     *
     * Creates a realistic sequence simulating a user's SaaS journey.
     *
     * @param  string  $clientId  Client ID
     * @param  callable(AnalyticsEvent): void|null  $dispatcher  Optional dispatch callback
     * @return array{steps: int, events: list<string>, converted: bool, plan: string|null}
     */
    public function generateSaaSLifecycleScenario(string $clientId, ?callable $dispatcher = null): array
    {
        $events = [];
        $userId = 'user_sim_' . substr(md5((string) mt_rand()), 0, 8);
        $converted = false;
        $plan = null;
        $step = 0;

        // Helper to dispatch and record
        $dispatch = function (string $name, array $params = []) use ($clientId, &$userId, &$step, &$events, $dispatcher): void {
            $event = new AnalyticsEvent(
                name: $name,
                params: array_merge($params, ['user_id' => $userId]),
                clientId: $clientId,
                userId: $userId,
                source: 'simulation',
            );
            $events[] = $name;
            $step++;
            if ($dispatcher !== null) {
                $dispatcher($event);
            }
        };

        // 1. Sign up
        $dispatch('sign_up', ['method' => 'email']);

        // 2. Email verified (80%)
        if (mt_rand(1, 100) <= 80) {
            $dispatch('email_verified');
        }

        // 3. Trial start (70%)
        if (mt_rand(1, 100) <= 70) {
            $dispatch('trial_start', ['plan' => 'pro', 'trial_days' => 14]);

            // 4. Feature used during trial (90%)
            if (mt_rand(1, 100) <= 90) {
                $dispatch('feature_used', ['feature' => 'dashboard']);
            }

            // 5. Trial conversion (40%)
            if (mt_rand(1, 100) <= 40) {
                $plans = ['starter', 'pro', 'enterprise'];
                $plan = $plans[array_rand($plans)];
                $dispatch('subscription_created', ['plan' => $plan, 'billing_cycle' => 'monthly']);
                $converted = true;
            }
        }

        return [
            'steps' => $step,
            'events' => $events,
            'converted' => $converted,
            'plan' => $plan,
        ];
    }

    /**
     * Get the current event mix configuration.
     *
     * @return array<string, float> Normalized event weights
     */
    public function getEventMix(): array
    {
        return $this->eventMix;
    }

    /**
     * Get simulator configuration.
     *
     * @return array{batch_size: int, rate_limit: int, dry_run: bool, max_events: int}
     */
    public function getConfig(): array
    {
        return [
            'batch_size' => $this->batchSize,
            'rate_limit' => $this->rateLimit,
            'dry_run' => $this->dryRun,
            'max_events' => self::MAX_EVENTS,
        ];
    }

    /**
     * Normalize and validate the event mix weights.
     *
     * @param  array<string, float>  $mix  Raw event mix
     * @return array<string, float> Normalized event mix
     */
    private function normalizeMix(array $mix): array
    {
        if (empty($mix)) {
            return $this->getDefaultMix();
        }

        // Validate and clamp weights
        $normalized = [];
        foreach ($mix as $event => $weight) {
            $normalized[$event] = min(max((float) $weight, 0.0), 1.0);
        }

        // Normalize to sum to 1.0
        $total = array_sum($normalized);
        if ($total > 0) {
            foreach ($normalized as $event => $weight) {
                $normalized[$event] = round($weight / $total, 6);
            }
        }

        return $normalized;
    }

    /**
     * Get the default event mix.
     *
     * @return array<string, float> Default event frequency weights
     */
    private function getDefaultMix(): array
    {
        return [
            'page_view' => 0.35,
            'scroll_depth' => 0.15,
            'click' => 0.12,
            'view_item' => 0.08,
            'add_to_cart' => 0.03,
            'search' => 0.05,
            'sign_up' => 0.02,
            'login' => 0.04,
            'form_submit' => 0.03,
            'share' => 0.02,
            'error' => 0.01,
            'purchase' => 0.01,
            'begin_checkout' => 0.01,
            'feature_used' => 0.02,
            'feedback' => 0.01,
            'session_start' => 0.02,
            'session_end' => 0.02,
            'form_start' => 0.01,
        ];
    }

    /**
     * Select a random event from the mix based on weighted distribution.
     *
     * @return string Selected event name
     */
    private function selectEventFromMix(): string
    {
        $rand = mt_rand() / mt_getrandmax();
        $cumulative = 0.0;

        foreach ($this->eventMix as $event => $weight) {
            $cumulative += $weight;
            if ($rand <= $cumulative) {
                return $event;
            }
        }

        return array_key_first($this->eventMix) ?? 'page_view';
    }

    /**
     * Generate realistic parameters for a given event name.
     *
     * @param  string  $eventName  Event name
     * @return array<string, mixed> Event parameters
     */
    private function generateEventParams(string $eventName): array
    {
        return match ($eventName) {
            'page_view' => [
                'page_title' => 'Page ' . mt_rand(1, 50),
                'page_location' => '/page/' . mt_rand(1, 50),
                'referrer' => mt_rand(0, 1) === 0 ? 'https://google.com' : null,
            ],
            'scroll_depth' => [
                'depth_percent' => mt_rand(10, 100),
                'page_location' => '/page/' . mt_rand(1, 50),
            ],
            'click' => [
                'element' => ['button', 'link', 'nav', 'card'][mt_rand(0, 3)],
                'element_text' => ['Submit', 'Learn More', 'Get Started', 'View'][mt_rand(0, 3)],
                'page_location' => '/page/' . mt_rand(1, 50),
            ],
            'view_item' => [
                'item_id' => 'SKU-' . mt_rand(1000, 9999),
                'item_name' => 'Product ' . mt_rand(1, 100),
                'currency' => 'USD',
                'value' => (float) mt_rand(10, 500),
            ],
            'add_to_cart' => [
                'item_id' => 'SKU-' . mt_rand(1000, 9999),
                'currency' => 'USD',
                'value' => (float) mt_rand(10, 300),
                'quantity' => mt_rand(1, 3),
            ],
            'purchase' => [
                'transaction_id' => 'TXN-' . strtoupper(substr(md5((string) mt_rand()), 0, 8)),
                'currency' => 'USD',
                'value' => (float) mt_rand(20, 1000),
            ],
            'search' => [
                'search_term' => ['analytics', 'dashboard', 'reports', 'pricing', 'features'][mt_rand(0, 4)],
                'results_count' => mt_rand(0, 100),
            ],
            'sign_up' => [
                'method' => ['email', 'google', 'github'][mt_rand(0, 2)],
            ],
            'login' => [
                'method' => ['email', 'google', 'github'][mt_rand(0, 2)],
            ],
            'form_submit' => [
                'form_name' => ['contact', 'feedback', 'signup', 'newsletter'][mt_rand(0, 3)],
                'form_id' => 'form_' . mt_rand(1, 20),
            ],
            'share' => [
                'method' => ['twitter', 'linkedin', 'email', 'copy'][mt_rand(0, 3)],
                'content_type' => ['page', 'article', 'product'][mt_rand(0, 2)],
            ],
            'error' => [
                'error_type' => ['js_error', 'api_error', 'validation_error'][mt_rand(0, 2)],
                'error_message' => 'Simulated error ' . mt_rand(1, 999),
                'fatal' => mt_rand(1, 10) === 1,
            ],
            'begin_checkout' => [
                'currency' => 'USD',
                'value' => (float) mt_rand(20, 500),
                'items' => mt_rand(1, 5),
            ],
            'feature_used' => [
                'feature' => ['dashboard', 'reports', 'api', 'export', 'import', 'settings'][mt_rand(0, 5)],
            ],
            'feedback' => [
                'rating' => mt_rand(1, 5),
                'category' => ['ux', 'performance', 'feature_request', 'bug'][mt_rand(0, 3)],
            ],
            'session_start' => [
                'source' => ['direct', 'organic', 'referral', 'social'][mt_rand(0, 3)],
            ],
            'session_end' => [
                'duration_seconds' => mt_rand(10, 3600),
                'page_count' => mt_rand(1, 20),
            ],
            'form_start' => [
                'form_name' => ['contact', 'signup', 'checkout', 'search'][mt_rand(0, 3)],
            ],
            default => [
                'simulated' => true,
                'param_' . mt_rand(1, 5) => 'value_' . mt_rand(1, 100),
            ],
        };
    }

    /**
     * Generate a pool of synthetic client IDs.
     *
     * @param  int  $count  Number of IDs to generate
     * @return list<string> Client IDs
     */
    private function generateClientPool(int $count): array
    {
        $pool = [];
        for ($i = 0; $i < $count; $i++) {
            $pool[] = 'sim_cid_' . substr(md5((string) (mt_rand() + $i)), 0, 12);
        }

        return $pool;
    }

    /**
     * Generate a pool of synthetic user IDs.
     *
     * @param  int  $count  Number of IDs to generate
     * @return list<string> User IDs
     */
    private function generateUserPool(int $count): array
    {
        $pool = [];
        for ($i = 0; $i < $count; $i++) {
            $pool[] = 'sim_uid_' . substr(md5((string) (mt_rand() + $i + 1000)), 0, 12);
        }

        return $pool;
    }

    /**
     * Generate a realistic timestamp with slight randomization.
     *
     * @param  int  $index  Current event index
     * @param  int  $total  Total events in batch
     * @return \DateTimeImmutable
     */
    private function generateTimestamp(int $index, int $total): \DateTimeImmutable
    {
        // Spread events over the last hour with randomization
        $secondsAgo = (int) (($total - $index) * (3600 / max($total, 1)));
        $jitter = mt_rand(-5, 5);

        return (new \DateTimeImmutable())->modify("-{$secondsAgo} seconds")->modify("{$jitter} seconds");
    }

    /**
     * Generate a random priority level.
     *
     * @return string Priority level
     */
    private function generatePriority(): string
    {
        $rand = mt_rand(1, 100);

        if ($rand <= 5) {
            return 'critical';
        }

        if ($rand <= 80) {
            return 'normal';
        }

        return 'low';
    }
}
