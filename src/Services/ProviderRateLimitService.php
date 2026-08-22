<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * Per-provider rate limiter for analytics event dispatch.
 *
 * Enforces independent rate limits for each analytics provider (GA4, Meta,
 * Plausible, PostHog, Webhook). When a provider exceeds its per-minute limit,
 * the event is not dispatched to that provider (but other providers continue).
 *
 * Overflow strategies:
 * - `drop`: Silently drop events for the rate-limited provider (default)
 * - `buffer`: Return true but log for later replay
 * - `downsample`: Allow through with configurable probability
 *
 * @see \ZeroBoiler\Analytics\Services\ProviderCircuitBreaker
 *
 * @since 1.0.0
 */
final class ProviderRateLimitService
{
    /**
     * @var array<string, int>
     */
    private array $providerLimits = [];

    /**
     * @var array<string, bool>
     */
    private array $providerEnabled = [];

    private int $cacheTtl;

    private string $cachePrefix;

    private string $overflowStrategy;

    private bool $logViolations;

    private bool $enabled;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        ConfigRepository $config,
    ){
        $prlConfig = $config->get('zeroboiler.analytics.provider_rate_limits', []);
        /** @var array{enabled?: bool, cache_ttl?: int, cache_prefix?: string, providers?: array<string, array{limit?: int, enabled?: bool}>, overflow_strategy?: string, log_violations?: bool} $prlConfig */

        $this->enabled = (bool) ($prlConfig['enabled'] ?? false);
        $this->cacheTtl = (int) ($prlConfig['cache_ttl'] ?? 60);
        $this->cachePrefix = (string) ($prlConfig['cache_prefix'] ?? 'zb_prl_');
        $this->overflowStrategy = (string) ($prlConfig['overflow_strategy'] ?? 'drop');
        $this->logViolations = (bool) ($prlConfig['log_violations'] ?? true);

        $providers = $prlConfig['providers'] ?? [];
        foreach ($providers as $name => $settings) {
            /** @var array{limit?: int, enabled?: bool} $settings */
            $this->providerLimits[$name] = (int) ($settings['limit'] ?? 0);
            $this->providerEnabled[$name] = (bool) ($settings['enabled'] ?? true);
        }
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if a specific provider should be rate-limited.
     *
     * Returns true if the provider has exceeded its rate limit and the event
     * should NOT be dispatched to that provider.
     *
     * @param  string  $provider  Provider name (ga4, meta, plausible, posthog, webhook)
     * @param  string|null  $eventName  Optional event name for logging
     * @return bool true = rate limited (block dispatch), false = allow dispatch
     */
    public function shouldThrottle(string $provider, ?string $eventName = null): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $enabled = $this->providerEnabled[$provider] ?? false;
        if (! $enabled) {
            return false;
        }

        $limit = $this->providerLimits[$provider] ?? 0;
        if ($limit <= 0) {
            return false; // No limit configured
        }

        $current = $this->getCurrentCount($provider);

        if ($current >= $limit) {
            if ($this->logViolations) {
                $this->logViolation($provider, $eventName);
            }

            return true;
        }

        return false;
    }

    /**
     * Increment the dispatch counter for a provider.
     *
     * Call this after successfully dispatching to a provider.
     *
     * @param  string  $provider  Provider name
     */
    public function increment(string $provider): void
    {
        if (! $this->enabled) {
            return;
        }

        $key = $this->cacheKey($provider);

        try {
            $current = $this->cache->get($key, 0);
            $this->cache->put($key, (int) $current + 1, $this->cacheTtl);
        } catch (\Throwable $e) {
            // Silently fail — rate limiting should never break dispatch
        }
    }

    /**
     * Get the current dispatch count for a provider in the current window.
     *
     * @param  string  $provider  Provider name
     */
    public function getCurrentCount(string $provider): int
    {
        try {
            return (int) $this->cache->get($this->cacheKey($provider), 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Get the configured rate limit for a provider.
     *
     * @param  string  $provider  Provider name
     */
    public function getLimit(string $provider): int
    {
        return $this->providerLimits[$provider] ?? 0;
    }

    /**
     * Get rate limit status for all providers.
     *
     * @return array<string, array{enabled: bool, limit: int, current: int, remaining: int, throttled: bool}>
     */
    public function getStatus(): array
    {
        $status = [];

        foreach ($this->providerLimits as $provider => $limit) {
            $current = $this->getCurrentCount($provider);
            $enabled = $this->providerEnabled[$provider] ?? false;

            $status[$provider] = [
                'enabled' => $enabled,
                'limit' => $limit,
                'current' => $current,
                'remaining' => max(0, $limit - $current),
                'throttled' => $enabled && $limit > 0 && $current >= $limit,
            ];
        }

        return $status;
    }

    /**
     * Get the overflow strategy.
     *
     * @return 'drop'|'buffer'|'downsample'
     */
    public function getOverflowStrategy(): string
    {
        return match ($this->overflowStrategy) {
            'buffer', 'downsample' => $this->overflowStrategy,
            default => 'drop',
        };
    }

    /**
     * Reset rate limit counters for a specific provider or all providers.
     *
     * @param  string|null  $provider  Provider name (null = all)
     */
    public function reset(?string $provider = null): void
    {
        if ($provider !== null) {
            try {
                $this->cache->forget($this->cacheKey($provider));
            } catch (\Throwable $e) {
                // Silently fail
            }
        } else {
            foreach (array_keys($this->providerLimits) as $p) {
                try {
                    $this->cache->forget($this->cacheKey($p));
                } catch (\Throwable $e) {
                    // Silently fail
                }
            }
        }
    }

    /**
     * Get the cache key for a provider's rate limit counter.
     *
     * @param  string  $provider  Provider name
     */
    private function cacheKey(string $provider): string
    {
        return $this->cachePrefix.$provider.':count';
    }

    /**
     * Log a rate limit violation.
     *
     * @param  string  $provider  Provider name
     * @param  string|null  $eventName  Event name (if available)
     */
    private function logViolation(string $provider, ?string $eventName = null): void
    {
        try {
            $context = [
                'provider' => $provider,
                'limit' => $this->providerLimits[$provider] ?? 0,
                'current' => $this->getCurrentCount($provider),
                'strategy' => $this->overflowStrategy,
            ];

            if ($eventName !== null) {
                $context['event'] = $eventName;
            }

            Log::info('ProviderRateLimit: throttle applied', $context);
        } catch (\Throwable $e) {
            // Silently fail
        }
    }
}
