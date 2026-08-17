<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Provider resilience service with circuit breaker pattern.
 *
 * Provides automatic failover between analytics providers when failures
 * are detected. Uses a circuit breaker pattern with three states:
 *
 * - **Closed** (healthy): Events dispatch to all enabled providers normally.
 *   Failure counts are tracked in a sliding window.
 *
 * - **Open** (unhealthy): After `failure_threshold` consecutive failures,
 *   the circuit opens. Events are routed to fallback providers only.
 *   A cooldown period begins before attempting recovery.
 *
 * - **Half-Open** (recovering): After the cooldown, a single test event
 *   is sent. If successful, the circuit closes again. If it fails,
 *   the circuit reopens with an extended cooldown.
 *
 * Each provider has its own independent circuit breaker. Configurable
 * per-provider thresholds and cooldowns. Supports ordered fallback
 * chains: if GA4 is down, try PostHog → Plausible → Webhook.
 *
 * Inspired by Netflix Hystrix, Polly (C#), and Resilience4j.
 *
 * @since 214.0.0
 */
final class ProviderResilienceService
{
    /** @var string Cache key prefix for circuit breaker state */
    private const CACHE_PREFIX = 'zb_resilience_';

    /**
     * Known provider identifiers with their display names.
     *
     * @var array<string, string>
     */
    private const PROVIDERS = [
        'ga4' => 'Google Analytics 4',
        'gtm' => 'Google Tag Manager',
        'meta_pixel' => 'Meta Pixel',
        'plausible' => 'Plausible',
        'posthog' => 'PostHog',
        'mixpanel' => 'Mixpanel',
        'amplitude' => 'Amplitude',
        'webhook' => 'Webhook',
        'tiktok' => 'TikTok Pixel',
        'linkedin' => 'LinkedIn Insight Tag',
    ];

    /**
     * Default fallback chains per provider.
     *
     * When a primary provider fails, events are re-routed to the
     * first available provider in its fallback chain.
     *
     * @var array<string, list<string>>
     */
    private const DEFAULT_FALLBACK_CHAINS = [
        'ga4' => ['posthog', 'plausible', 'webhook'],
        'gtm' => ['ga4', 'webhook'],
        'meta_pixel' => ['ga4', 'posthog', 'webhook'],
        'plausible' => ['posthog', 'ga4', 'webhook'],
        'posthog' => ['plausible', 'ga4', 'webhook'],
        'mixpanel' => ['amplitude', 'posthog', 'webhook'],
        'amplitude' => ['mixpanel', 'posthog', 'webhook'],
        'webhook' => [],
        'tiktok' => ['meta_pixel', 'ga4', 'webhook'],
        'linkedin' => ['meta_pixel', 'ga4', 'webhook'],
    ];

    private CacheRepository $cache;

    private AnalyticsManager $manager;

    private bool $enabled;

    /** @var int Failures before opening circuit (default: 3) */
    private int $failureThreshold;

    /** @var int Base cooldown in seconds before half-open (default: 60) */
    private int $baseCooldown;

    /** @var float Cooldown multiplier on repeated failures (default: 2.0) */
    private float $cooldownMultiplier;

    /** @var int Max cooldown in seconds (default: 3600) */
    private int $maxCooldown;

    /** @var int Sliding window duration for failure counting (default: 300 seconds) */
    private int $failureWindow;

    /** @var list<array{provider: string, fallbacks: list<string>}> Custom fallback chains */
    private array $fallbackChains;

    private bool $logFailures;

    private int $cacheTtl;

    public function __construct(CacheRepository $cache, ConfigRepository $config, AnalyticsManager $manager): void
    {
        $this->cache = $cache;
        $this->manager = $manager;

        $resilienceConfig = $config->get('zeroboiler.analytics.provider_resilience', []);
        /** @var array{enabled?: bool, failure_threshold?: int, base_cooldown?: int, cooldown_multiplier?: float, max_cooldown?: int, failure_window?: int, fallback_chains?: array<string, list<string>>, log_failures?: bool, cache_ttl?: int} $resilienceConfig */

        $this->enabled = (bool) ($resilienceConfig['enabled'] ?? true);
        $this->failureThreshold = (int) ($resilienceConfig['failure_threshold'] ?? 3);
        $this->baseCooldown = (int) ($resilienceConfig['base_cooldown'] ?? 60);
        $this->cooldownMultiplier = (float) ($resilienceConfig['cooldown_multiplier'] ?? 2.0);
        $this->maxCooldown = (int) ($resilienceConfig['max_cooldown'] ?? 3600);
        $this->failureWindow = (int) ($resilienceConfig['failure_window'] ?? 300);
        $this->logFailures = (bool) ($resilienceConfig['log_failures'] ?? true);
        $this->cacheTtl = (int) ($resilienceConfig['cache_ttl'] ?? 7200);

        // Merge custom fallback chains with defaults
        $customChains = $resilienceConfig['fallback_chains'] ?? [];
        $this->fallbackChains = [];
        foreach (self::DEFAULT_FALLBACK_CHAINS as $provider => $fallbacks) {
            $this->fallbackChains[] = [
                'provider' => $provider,
                'fallbacks' => $customChains[$provider] ?? $fallbacks,
            ];
        }
    }

    /**
     * Record a successful dispatch for a provider.
     *
     * Resets the failure counter and closes the circuit if it was open.
     *
     * @param  string  $provider  Provider identifier (e.g. 'ga4', 'meta_pixel')
     */
    public function recordSuccess(string $provider): void
    {
        if (! $this->enabled) {
            return;
        }

        $state = $this->getState($provider);

        // If half-open and success → close the circuit
        if ($state->status === 'half_open') {
            $this->saveState($provider, new CircuitBreakerState(
                status: 'closed',
                failureCount: 0,
                lastFailureAt: null,
                openedAt: null,
                cooldownEnd: null,
                cooldownLevel: 0,
                totalFailures: $state->totalFailures,
                totalSuccesses: $state->totalSuccesses + 1,
                lastSuccessAt: new \DateTimeImmutable(),
            ));

            Log::info("[Resilience] Circuit closed for '{$provider}' after successful recovery test.", [
                'provider' => $provider,
            ]);

            return;
        }

        // Normal operation: reset failure count, increment success
        $this->saveState($provider, new CircuitBreakerState(
            status: 'closed',
            failureCount: 0,
            lastFailureAt: $state->lastFailureAt,
            openedAt: null,
            cooldownEnd: null,
            cooldownLevel: 0,
            totalFailures: $state->totalFailures,
            totalSuccesses: $state->totalSuccesses + 1,
            lastSuccessAt: new \DateTimeImmutable(),
        ));
    }

    /**
     * Record a failed dispatch for a provider.
     *
     * Increments the failure counter. If the threshold is reached,
     * opens the circuit breaker.
     *
     * @param  string  $provider  Provider identifier
     * @param  string  $error  Error message (for logging)
     */
    public function recordFailure(string $provider, string $error = ''): void
    {
        if (! $this->enabled) {
            return;
        }

        $state = $this->getState($provider);
        $now = new \DateTimeImmutable();

        // Count recent failures within the sliding window
        $recentFailures = $this->countRecentFailures($state);

        $newFailureCount = $recentFailures + 1;

        // Check if threshold is reached
        if ($newFailureCount >= $this->failureThreshold && $state->status !== 'open') {
            $cooldownLevel = $state->cooldownLevel + 1;
            $cooldownSeconds = (int) min(
                $this->baseCooldown * pow($this->cooldownMultiplier, $cooldownLevel - 1),
                $this->maxCooldown,
            );
            $cooldownEnd = $now->getTimestamp() + $cooldownSeconds;

            $this->saveState($provider, new CircuitBreakerState(
                status: 'open',
                failureCount: $newFailureCount,
                lastFailureAt: $now,
                openedAt: $now,
                cooldownEnd: $cooldownEnd,
                cooldownLevel: $cooldownLevel,
                totalFailures: $state->totalFailures + 1,
                totalSuccesses: $state->totalSuccesses,
                lastSuccessAt: $state->lastSuccessAt,
            ));

            if ($this->logFailures) {
                Log::warning("[Resilience] Circuit OPENED for '{$provider}' after {$newFailureCount} failures. Cooldown: {$cooldownSeconds}s (level {$cooldownLevel}).", [
                    'provider' => $provider,
                    'error' => $error,
                    'failure_count' => $newFailureCount,
                    'cooldown_seconds' => $cooldownSeconds,
                ]);
            }
        } elseif ($state->status === 'half_open') {
            // Half-open and failure → re-open with longer cooldown
            $cooldownLevel = $state->cooldownLevel + 1;
            $cooldownSeconds = (int) min(
                $this->baseCooldown * pow($this->cooldownMultiplier, $cooldownLevel - 1),
                $this->maxCooldown,
            );

            $this->saveState($provider, new CircuitBreakerState(
                status: 'open',
                failureCount: $newFailureCount,
                lastFailureAt: $now,
                openedAt: $now,
                cooldownEnd: $now->getTimestamp() + $cooldownSeconds,
                cooldownLevel: $cooldownLevel,
                totalFailures: $state->totalFailures + 1,
                totalSuccesses: $state->totalSuccesses,
                lastSuccessAt: $state->lastSuccessAt,
            ));

            if ($this->logFailures) {
                Log::warning("[Resilience] Circuit RE-OPENED for '{$provider}' during recovery. Cooldown extended: {$cooldownSeconds}s.", [
                    'provider' => $provider,
                    'error' => $error,
                ]);
            }
        } else {
            // Accumulating failures but threshold not yet reached
            $this->saveState($provider, new CircuitBreakerState(
                status: 'closed',
                failureCount: $newFailureCount,
                lastFailureAt: $now,
                openedAt: $state->openedAt,
                cooldownEnd: $state->cooldownEnd,
                cooldownLevel: $state->cooldownLevel,
                totalFailures: $state->totalFailures + 1,
                totalSuccesses: $state->totalSuccesses,
                lastSuccessAt: $state->lastSuccessAt,
            ));
        }
    }

    /**
     * Check if a provider is available for dispatch.
     *
     * A provider is available if its circuit is closed, or if the cooldown
     * has elapsed (transitioning to half-open).
     *
     * @param  string  $provider  Provider identifier
     * @return bool
     */
    public function isAvailable(string $provider): bool
    {
        if (! $this->enabled) {
            return true;
        }

        $state = $this->getState($provider);

        if ($state->status === 'closed') {
            return true;
        }

        if ($state->status === 'half_open') {
            return true; // Allow one test event through
        }

        // Open: check if cooldown has elapsed
        if ($state->cooldownEnd !== null && time() >= $state->cooldownEnd) {
            // Transition to half-open
            $this->saveState($provider, new CircuitBreakerState(
                status: 'half_open',
                failureCount: $state->failureCount,
                lastFailureAt: $state->lastFailureAt,
                openedAt: $state->openedAt,
                cooldownEnd: null,
                cooldownLevel: $state->cooldownLevel,
                totalFailures: $state->totalFailures,
                totalSuccesses: $state->totalSuccesses,
                lastSuccessAt: $state->lastSuccessAt,
            ));

            return true;
        }

        return false;
    }

    /**
     * Get the fallback providers for a given provider.
     *
     * Returns only providers that are currently available (circuit closed
     * or half-open). Ordered by priority (first in chain = first to try).
     *
     * @param  string  $provider  Provider identifier
     * @return list<string>  Available fallback provider identifiers
     */
    public function getAvailableFallbacks(string $provider): array
    {
        $chain = $this->getFallbackChain($provider);
        $available = [];

        foreach ($chain as $fallbackProvider) {
            if ($this->isAvailable($fallbackProvider)) {
                $available[] = $fallbackProvider;
            }
        }

        return $available;
    }

    /**
     * Get the circuit breaker state for a provider.
     *
     * @param  string  $provider  Provider identifier
     * @return CircuitBreakerState
     */
    public function getState(string $provider): CircuitBreakerState
    {
        $key = self::CACHE_PREFIX . $provider;

        /** @var array{status: string, failure_count: int, last_failure_at: string|null, opened_at: string|null, cooldown_end: int|null, cooldown_level: int, total_failures: int, total_successes: int, last_success_at: string|null}|null $cached */
        $cached = $this->cache->get($key);

        if ($cached === null) {
            return new CircuitBreakerState(
                status: 'closed',
                failureCount: 0,
                lastFailureAt: null,
                openedAt: null,
                cooldownEnd: null,
                cooldownLevel: 0,
                totalFailures: 0,
                totalSuccesses: 0,
                lastSuccessAt: null,
            );
        }

        return new CircuitBreakerState(
            status: $cached['status'] ?? 'closed',
            failureCount: (int) ($cached['failure_count'] ?? 0),
            lastFailureAt: isset($cached['last_failure_at']) ? new \DateTimeImmutable($cached['last_failure_at']) : null,
            openedAt: isset($cached['opened_at']) ? new \DateTimeImmutable($cached['opened_at']) : null,
            cooldownEnd: isset($cached['cooldown_end']) ? (int) $cached['cooldown_end'] : null,
            cooldownLevel: (int) ($cached['cooldown_level'] ?? 0),
            totalFailures: (int) ($cached['total_failures'] ?? 0),
            totalSuccesses: (int) ($cached['total_successes'] ?? 0),
            lastSuccessAt: isset($cached['last_success_at']) ? new \DateTimeImmutable($cached['last_success_at']) : null,
        );
    }

    /**
     * Get circuit breaker states for all providers.
     *
     * @return array<string, CircuitBreakerState>
     */
    public function getAllStates(): array
    {
        $states = [];
        foreach (array_keys(self::PROVIDERS) as $provider) {
            $states[$provider] = $this->getState($provider);
        }

        return $states;
    }

    /**
     * Get a summary of provider resilience status.
     *
     * @return array{enabled: bool, providers: array<string, array{display_name: string, status: string, available: bool, failure_count: int, total_failures: int, total_successes: float, cooldown_remaining?: int}>, healthy_count: int, degraded_count: int, down_count: int}
     */
    public function getStatusSummary(): array
    {
        $providers = [];
        $healthy = 0;
        $degraded = 0;
        $down = 0;

        foreach (self::PROVIDERS as $id => $displayName) {
            $state = $this->getState($id);
            $available = $this->isAvailable($id);

            $entry = [
                'display_name' => $displayName,
                'status' => $state->status,
                'available' => $available,
                'failure_count' => $state->failureCount,
                'total_failures' => $state->totalFailures,
                'total_successes' => $state->totalSuccesses,
            ];

            if ($state->status === 'open' && $state->cooldownEnd !== null) {
                $remaining = max(0, $state->cooldownEnd - time());
                $entry['cooldown_remaining'] = $remaining;
            }

            $providers[$id] = $entry;

            if ($state->status === 'open') {
                $down++;
            } elseif ($state->status === 'half_open' || $state->failureCount > 0) {
                $degraded++;
            } else {
                $healthy++;
            }
        }

        return [
            'enabled' => $this->enabled,
            'providers' => $providers,
            'healthy_count' => $healthy,
            'degraded_count' => $degraded,
            'down_count' => $down,
        ];
    }

    /**
     * Manually reset a provider's circuit breaker to closed state.
     *
     * @param  string  $provider  Provider identifier
     */
    public function resetProvider(string $provider): void
    {
        $this->cache->forget(self::CACHE_PREFIX . $provider);

        Log::info("[Resilience] Circuit manually reset for '{$provider}'.");
    }

    /**
     * Reset all provider circuit breakers.
     */
    public function resetAll(): void
    {
        foreach (array_keys(self::PROVIDERS) as $provider) {
            $this->cache->forget(self::CACHE_PREFIX . $provider);
        }

        Log::info('[Resilience] All circuit breakers manually reset.');
    }

    /**
     * Check if provider resilience is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get configuration summary.
     *
     * @return array{enabled: bool, failure_threshold: int, base_cooldown: int, cooldown_multiplier: float, max_cooldown: int, failure_window: int, log_failures: bool}
     */
    public function getConfigSummary(): array
    {
        return [
            'enabled' => $this->enabled,
            'failure_threshold' => $this->failureThreshold,
            'base_cooldown' => $this->baseCooldown,
            'cooldown_multiplier' => $this->cooldownMultiplier,
            'max_cooldown' => $this->maxCooldown,
            'failure_window' => $this->failureWindow,
            'log_failures' => $this->logFailures,
        ];
    }

    // ── Internal Methods ────────────────────────────────────────────────

    /**
     * Save the circuit breaker state for a provider.
     *
     * @param  string  $provider  Provider identifier
     * @param  CircuitBreakerState  $state  New state
     */
    private function saveState(string $provider, CircuitBreakerState $state): void
    {
        $this->cache->put(
            self::CACHE_PREFIX . $provider,
            [
                'status' => $state->status,
                'failure_count' => $state->failureCount,
                'last_failure_at' => $state->lastFailureAt?->format(\DateTimeInterface::ATOM),
                'opened_at' => $state->openedAt?->format(\DateTimeInterface::ATOM),
                'cooldown_end' => $state->cooldownEnd,
                'cooldown_level' => $state->cooldownLevel,
                'total_failures' => $state->totalFailures,
                'total_successes' => $state->totalSuccesses,
                'last_success_at' => $state->lastSuccessAt?->format(\DateTimeInterface::ATOM),
            ],
            $this->cacheTtl,
        );
    }

    /**
     * Count failures within the sliding window.
     *
     * @param  CircuitBreakerState  $state  Current state
     * @return int  Number of recent failures
     */
    private function countRecentFailures(CircuitBreakerState $state): int
    {
        if ($state->lastFailureAt === null) {
            return 0;
        }

        $windowStart = time() - $this->failureWindow;

        if ($state->lastFailureAt->getTimestamp() < $windowStart) {
            return 0;
        }

        // In a production system, this would track individual failure timestamps
        // in a list. For the cache-backed approach, we use the state's failure count
        // as an approximation that resets on success.
        return $state->failureCount;
    }

    /**
     * Get the fallback chain for a provider.
     *
     * @param  string  $provider  Provider identifier
     * @return list<string>  Ordered list of fallback provider identifiers
     */
    private function getFallbackChain(string $provider): array
    {
        foreach ($this->fallbackChains as $chain) {
            if ($chain['provider'] === $provider) {
                return $chain['fallbacks'];
            }
        }

        return [];
    }
}

/**
 * Immutable circuit breaker state for a provider.
 *
 * @since 214.0.0
 */
final readonly class CircuitBreakerState
{
    /**
     * @param  'closed'|'open'|'half_open'  $status  Circuit breaker status
     * @param  int  $failureCount  Consecutive failures in the current window
     * @param  \DateTimeImmutable|null  $lastFailureAt  Timestamp of the last failure
     * @param  \DateTimeImmutable|null  $openedAt  When the circuit was opened
     * @param  int|null  $cooldownEnd  Unix timestamp when cooldown expires
     * @param  int  $cooldownLevel  Number of times the circuit has re-opened (for exponential backoff)
     * @param  int  $totalFailures  Cumulative failure count
     * @param  int  $totalSuccesses  Cumulative success count
     * @param  \DateTimeImmutable|null  $lastSuccessAt  Timestamp of the last success
     */
    public function __construct(
        public string $status,
        public int $failureCount,
        public ?\DateTimeImmutable $lastFailureAt,
        public ?\DateTimeImmutable $openedAt,
        public ?int $cooldownEnd,
        public int $cooldownLevel,
        public int $totalFailures,
        public int $totalSuccesses,
        public ?\DateTimeImmutable $lastSuccessAt,
    ): void {}

    /**
     * Convert to array representation.
     *
     * @return array{status: string, failure_count: int, last_failure_at: string|null, opened_at: string|null, cooldown_end: int|null, cooldown_level: int, total_failures: int, total_successes: int, last_success_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'failure_count' => $this->failureCount,
            'last_failure_at' => $this->lastFailureAt?->format(\DateTimeInterface::ATOM),
            'opened_at' => $this->openedAt?->format(\DateTimeInterface::ATOM),
            'cooldown_end' => $this->cooldownEnd,
            'cooldown_level' => $this->cooldownLevel,
            'total_failures' => $this->totalFailures,
            'total_successes' => $this->totalSuccesses,
            'last_success_at' => $this->lastSuccessAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
