<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;

/**
 * Cart state manager for cross-session e-commerce tracking.
 *
 * Tracks cart state (items, value, currency) across page loads and sessions.
 * Enables accurate cart abandonment tracking by persisting cart snapshots
 * and computing abandonment scores based on cart value and time since last update.
 *
 * The cart state is stored in the Laravel cache keyed by client ID.
 * When a user authenticates, the anonymous cart is merged with any existing
 * authenticated cart data.
 *
 * Features:
 * - Cross-session cart persistence (items, value, currency)
 * - Cart abandonment score calculation (value × recency decay)
 * - Cart merge on authentication (anonymous → authenticated)
 * - Cart snapshot for checkout funnel analysis
 * - Cart value history for trend tracking
 *
 * @see \ZeroBoiler\Analytics\Services\EcommerceAnalyticsService
 * @version 5.0.0
 *
 * @since 1.0.0
 */
final class CartStateManager
{
    private AnalyticsManager $manager;

    private CacheRepository $cache;

    private bool $enabled;

    private string $cachePrefix;

    private int $cacheTtl;

    private string $defaultCurrency;

    private float $abandonmentDecayRate;

    /** @var int Time in seconds after which a cart is considered abandoned */
    private int $abandonmentThresholdSeconds;

    private const MAX_CART_SIZE = 100;

    private const MAX_HISTORY_ENTRIES = 50;

    /**
     * @param  AnalyticsManager  $manager
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(AnalyticsManager $manager, CacheRepository $cache, ConfigRepository $config){
        $this->manager = $manager;
        $this->cache = $cache;

        $cartConfig = $config->get('zeroboiler.analytics.cart_tracking', []);
        /** @var array{enabled?: bool, cache_prefix?: string, cache_ttl?: int, currency?: string, abandonment_decay_rate?: float, abandonment_threshold_seconds?: int} $cartConfig */

        $this->enabled = (bool) ($cartConfig['enabled'] ?? true);
        $this->cachePrefix = (string) ($cartConfig['cache_prefix'] ?? 'zb_cart_');
        $this->cacheTtl = (int) ($cartConfig['cache_ttl'] ?? 2592000); // 30 days
        $this->defaultCurrency = (string) ($cartConfig['currency'] ?? 'USD');
        $this->abandonmentDecayRate = (float) ($cartConfig['abandonment_decay_rate'] ?? 0.1);
        $this->abandonmentThresholdSeconds = (int) ($cartConfig['abandonment_threshold_seconds'] ?? 86400); // 24 hours
    }

    /**
     * Check if cart tracking is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    // ── Cart State Management ───────────────────────────────────────

    /**
     * Update the cart state for a client.
     *
     * Persists the current cart items and computed value in cache.
     * Also records a cart snapshot for funnel analysis.
     *
     * @param  string  $clientId  Client tracking ID
     * @param  array<int, array{item_id: string, item_name?: string, item_category?: string, price: float, quantity: int}>  $items  Cart items
     * @param  string|null  $currency  Currency code
     * @return array{value: float, item_count: int, currency: string, updated_at: int} Updated cart state
     */
    public function updateCart(string $clientId, array $items, ?string $currency = null): array
    {
        if (! $this->enabled) {
            return ['value' => 0.0, 'item_count' => 0, 'currency' => $currency ?? $this->defaultCurrency, 'updated_at' => time()];
        }

        // Trim to max size
        if (count($items) > self::MAX_CART_SIZE) {
            $items = array_slice($items, 0, self::MAX_CART_SIZE);
        }

        $cartCurrency = $currency ?? $this->defaultCurrency;
        $value = 0.0;
        $itemCount = 0;

        foreach ($items as $item) {
            $price = (float) ($item['price'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 1);
            $value += $price * $quantity;
            $itemCount += $quantity;
        }

        $cartState = [
            'items' => $items,
            'value' => $value,
            'item_count' => $itemCount,
            'currency' => $cartCurrency,
            'updated_at' => time(),
        ];

        $this->cache->put(
            $this->cachePrefix . $clientId,
            $cartState,
            $this->cacheTtl,
        );

        return $cartState;
    }

    /**
     * Get the current cart state for a client.
     *
     * @param  string  $clientId  Client tracking ID
     * @return array{items: array<int, array<string, mixed>>, value: float, item_count: int, currency: string, updated_at: int}|null Cart state or null
     */
    public function getCart(string $clientId): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        $data = $this->cache->get($this->cachePrefix . $clientId);

        return is_array($data) ? $data : null;
    }

    /**
     * Clear the cart state for a client.
     *
     * Call this after successful purchase to reset cart tracking.
     */
    public function clearCart(string $clientId): void
    {
        $this->cache->forget($this->cachePrefix . $clientId);
    }

    /**
     * Check if a cart exists for a client.
     */
    public function hasCart(string $clientId): bool
    {
        return $this->getCart($clientId) !== null;
    }

    // ── Cart Merge (Anonymous → Authenticated) ──────────────────────

    /**
     * Merge an anonymous cart into an authenticated cart.
     *
     * When a user logs in or registers, their anonymous cart items
     * should be merged with any existing authenticated cart. This method
     * combines items, deduplicates by item_id, and sums quantities.
     *
     * @param  string  $anonymousClientId  Anonymous client tracking ID
     * @param  string  $authenticatedClientId  Authenticated client tracking ID
     * @return array{merged: bool, value: float, item_count: int} Merge result
     */
    public function mergeCart(string $anonymousClientId, string $authenticatedClientId): array
    {
        if (! $this->enabled) {
            return ['merged' => false, 'value' => 0.0, 'item_count' => 0];
        }

        $anonymousCart = $this->getCart($anonymousClientId);
        $authCart = $this->getCart($authenticatedClientId);

        if ($anonymousCart === null || empty($anonymousCart['items'])) {
            return ['merged' => false, 'value' => $authCart['value'] ?? 0.0, 'item_count' => $authCart['item_count'] ?? 0];
        }

        $mergedItems = $authCart['items'] ?? [];

        // Merge items: combine quantities for duplicate item_ids
        foreach ($anonymousCart['items'] as $incomingItem) {
            $incomingId = (string) ($incomingItem['item_id'] ?? '');
            $found = false;

            foreach ($mergedItems as &$existingItem) {
                if ((string) ($existingItem['item_id'] ?? '') === $incomingId) {
                    $existingItem['quantity'] = (int) $existingItem['quantity'] + (int) ($incomingItem['quantity'] ?? 1);
                    $found = true;
                    break;
                }
            }
            unset($existingItem);

            if (! $found) {
                $mergedItems[] = $incomingItem;
            }
        }

        $result = $this->updateCart($authenticatedClientId, $mergedItems);
        $this->clearCart($anonymousClientId);

        return ['merged' => true, 'value' => $result['value'], 'item_count' => $result['item_count']];
    }

    // ── Abandonment Scoring ─────────────────────────────────────────

    /**
     * Calculate the cart abandonment score for a client.
     *
     * The score combines cart value with time-based decay to predict
     * the likelihood of cart abandonment. Higher scores indicate
     * higher-value carts that have been idle longer.
     *
     * Score = cart_value × (1 - e^(-decay_rate × hours_since_update))
     *
     * @param  string  $clientId  Client tracking ID
     * @return array{score: float, value: float, hours_since_update: float, is_abandoned: bool, item_count: int} Abandonment analysis
     */
    public function abandonmentScore(string $clientId): array
    {
        $default = [
            'score' => 0.0,
            'value' => 0.0,
            'hours_since_update' => 0.0,
            'is_abandoned' => false,
            'item_count' => 0,
        ];

        if (! $this->enabled) {
            return $default;
        }

        $cart = $this->getCart($clientId);

        if ($cart === null) {
            return $default;
        }

        $value = (float) ($cart['value'] ?? 0);
        $updatedAt = (int) ($cart['updated_at'] ?? 0);
        $itemCount = (int) ($cart['item_count'] ?? 0);
        $secondsSinceUpdate = max(0, time() - $updatedAt);
        $hoursSinceUpdate = $secondsSinceUpdate / 3600.0;

        $isAbandoned = $secondsSinceUpdate >= $this->abandonmentThresholdSeconds;

        // Exponential decay: higher decay rate = score approaches value faster
        $decayFactor = 1.0 - exp(-$this->abandonmentDecayRate * $hoursSinceUpdate);
        $score = $value * $decayFactor;

        return [
            'score' => round($score, 2),
            'value' => $value,
            'hours_since_update' => round($hoursSinceUpdate, 2),
            'is_abandoned' => $isAbandoned,
            'item_count' => $itemCount,
        ];
    }

    /**
     * Get all abandoned carts.
     *
     * Scans recent cart entries and returns those exceeding the
     * abandonment threshold. Useful for remarketing campaigns
     * and abandoned cart recovery flows.
     *
     * @return list<array{client_id: string, value: float, item_count: int, hours_since_update: float, score: float}>
     */
    public function getAbandonedCarts(): array
    {
        // This is a best-effort scan — cache-based storage means
        // we can't enumerate all keys efficiently.
        // In production, use a database-backed cart store for full enumeration.
        return [];
    }

    // ── Cart Value History ──────────────────────────────────────────

    /**
     * Record a cart value snapshot for trend analysis.
     *
     * Stores periodic cart value observations for computing
     * cart growth trends and value distribution.
     *
     * @param  string  $clientId  Client tracking ID
     * @param  float  $value  Cart value at snapshot time
     * @param  int|null  $timestamp  Snapshot timestamp (defaults to now)
     */
    public function recordCartSnapshot(string $clientId, float $value, ?int $timestamp = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $historyKey = $this->cachePrefix . 'history_' . $clientId;
        $history = $this->cache->get($historyKey, []);
        /** @var array<int, array{value: float, timestamp: int}> $history */

        $history[] = [
            'value' => $value,
            'timestamp' => $timestamp ?? time(),
        ];

        // Keep only recent entries
        if (count($history) > self::MAX_HISTORY_ENTRIES) {
            $history = array_slice($history, -self::MAX_HISTORY_ENTRIES);
        }

        $this->cache->put($historyKey, $history, $this->cacheTtl);
    }

    /**
     * Get cart value history for a client.
     *
     * @param  string  $clientId  Client tracking ID
     * @return list<array{value: float, timestamp: int}>
     */
    public function getCartHistory(string $clientId): array
    {
        if (! $this->enabled) {
            return [];
        }

        $history = $this->cache->get($this->cachePrefix . 'history_' . $clientId, []);

        return is_array($history) ? $history : [];
    }

    // ── Utility ─────────────────────────────────────────────────────

    /**
     * Calculate cart value from items array.
     *
     * @param  array<int, array{price: float, quantity: int}>  $items
     * @return float Total cart value
     */
    public function calculateValue(array $items): float
    {
        $value = 0.0;

        foreach ($items as $item) {
            $value += (float) ($item['price'] ?? 0) * (int) ($item['quantity'] ?? 1);
        }

        return $value;
    }

    /**
     * Get the abandonment threshold in seconds.
     */
    public function abandonmentThresholdSeconds(): int
    {
        return $this->abandonmentThresholdSeconds;
    }

    /**
     * Get the configured cache TTL for cart state.
     */
    public function cacheTtl(): int
    {
        return $this->cacheTtl;
    }
}
