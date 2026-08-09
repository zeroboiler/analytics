<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-client analytics rate limiter.
 *
 * Provides rate limiting for analytics API endpoints based on
 * client ID or IP address. Uses Laravel's built-in RateLimiter
 * with configurable keys, limits, and decay windows.
 *
 * @since 1.0.0
 */
final class AnalyticsRateLimiter
{
    /**
     * @param  int  $maxAttempts  Maximum events per decay window
     * @param  int  $decaySeconds  Decay window in seconds
     */
    public function __construct(
        private readonly int $maxAttempts = 120,
        private readonly int $decaySeconds = 60,
    ): void {}

    /**
     * Attempt to process an event. Returns true if within rate limit.
     */
    public function attempt(string $key): bool
    {
        return RateLimiter::attempt(
            $this->resolveKey($key),
            $this->maxAttempts,
            function (): void {},
            $this->decaySeconds,
        );
    }

    /**
     * Check if the given key is currently rate-limited (without incrementing).
     */
    public function tooManyAttempts(string $key): bool
    {
        return RateLimiter::tooManyAttempts($this->resolveKey($key), $this->maxAttempts);
    }

    /**
     * Get the number of remaining attempts for the key.
     */
    public function remaining(string $key): int
    {
        return RateLimiter::remaining($this->resolveKey($key), $this->maxAttempts);
    }

    /**
     * Get the number of seconds until the rate limit resets.
     */
    public function availableIn(string $key): int
    {
        return RateLimiter::availableIn($this->resolveKey($key));
    }

    /**
     * Clear the rate limit for a given key.
     */
    public function clear(string $key): void
    {
        RateLimiter::clear($this->resolveKey($key));
    }

    /**
     * Resolve the rate limiter key from a raw key string.
     *
     * Prefixes with 'analytics:' to avoid collisions with other rate limiters.
     */
    private function resolveKey(string $key): string
    {
        return 'analytics:'.$key;
    }

    /**
     * Extract the rate limiter key from an HTTP request.
     *
     * Uses the X-Analytics-Client-Id header when available,
     * falls back to the IP address.
     */
    public static function keyFromRequest(Request $request, string $cookieName = 'zb_analytics_id'): string
    {
        $clientId = $request->header('X-Analytics-Client-Id');

        if (is_string($clientId) && $clientId !== '') {
            return $clientId;
        }

        $cookie = $request->cookie($cookieName);

        if (is_string($cookie) && $cookie !== '') {
            return $cookie;
        }

        return (string) $request->ip();
    }
}
