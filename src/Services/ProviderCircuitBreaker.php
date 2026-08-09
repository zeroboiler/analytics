<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Services\DeadLetterQueueService;

/**
 * Provider Circuit Breaker — prevents cascading failures when analytics providers are down.
 *
 * Implements the circuit breaker pattern with three states:
 * - **Closed** (normal): Events flow to providers normally.
 * - **Open** (failing): Provider has exceeded failure threshold; events are skipped.
 * - **Half-Open** (recovering): A test event is sent to probe provider health.
 *
 * Each provider has independent circuit state. Configurable thresholds for failure
 * count, success threshold (to close from half-open), and cooldown/reset timeouts.
 *
 * Provides dashboard data for monitoring circuit breaker states across all providers.
 *
 * @see https://martinfowler.com/articles/circuitBreaker.html
 *
 * @since 1.0.0
 */
final class ProviderCircuitBreaker
{
    /**
     * Circuit breaker states.
     */
    public const STATE_CLOSED = 'closed';

    public const STATE_OPEN = 'open';

    public const STATE_HALF_OPEN = 'half_open';

    /**
     * Cache key prefix for circuit state.
     */
    private const CACHE_PREFIX = 'zb_circuit_breaker_';

    /**
     * @var array<string, string> Current in-memory state (provider => state)
     */
    private array $state = [];

    /**
     * @var array<string, int> Per-provider failure counters (in-memory)
     */
    private array $failureCount = [];

    /**
     * @var array<string, int> Per-provider success counters (half-open probing)
     */
    private array $successCount = [];

    private bool $enabled;

    private int $failureThreshold;

    private int $successThreshold;

    private int $cooldownSeconds;

    private int $halfOpenMaxProbes;

    private CacheRepository $cache;

    private ConfigRepository $config;

    /**
     * Create a new ProviderCircuitBreaker instance.
     *
     * @param  ConfigRepository  $config  Application config
     * @param  CacheRepository|null  $cache  Cache driver (injected or from container)
     */
    public function __construct(ConfigRepository $config, ?CacheRepository $cache = null): void
    {
        $cbConfig = $config->get('zeroboiler.analytics.circuit_breaker', []);
        /** @var array{enabled?: bool, failure_threshold?: int, success_threshold?: int, cooldown_seconds?: int, half_open_max_probes?: int} $cbConfig */

        $this->enabled = (bool) ($cbConfig['enabled'] ?? false);
        $this->failureThreshold = (int) ($cbConfig['failure_threshold'] ?? 5);
        $this->successThreshold = (int) ($cbConfig['success_threshold'] ?? 2);
        $this->cooldownSeconds = (int) ($cbConfig['cooldown_seconds'] ?? 60);
        $this->halfOpenMaxProbes = (int) ($cbConfig['half_open_max_probes'] ?? 3);
        $this->config = $config;

        // Attempt to resolve cache
        $this->cache = $cache ?? app('cache')->driver();
    }

    /**
     * Determine if events should be sent to a specific provider.
     *
     * Returns false when the circuit is open (provider is considered down).
     * Returns true when closed (normal) or half-open (probing).
     *
     * @param  string  $provider  Provider name (ga4, gtm, meta, plausible, posthog, webhook)
     */
    public function shouldDispatch(string $provider): bool
    {
        if (! $this->enabled) {
            return true;
        }

        $state = $this->getState($provider);

        if ($state === self::STATE_CLOSED) {
            return true;
        }

        if ($state === self::STATE_OPEN) {
            // Check if cooldown has elapsed → transition to half-open
            $lastFailure = $this->getLastFailureTime($provider);

            if ($lastFailure !== null && (time() - $lastFailure) >= $this->cooldownSeconds) {
                $this->transitionTo($provider, self::STATE_HALF_OPEN);

                return true; // Allow one probe event
            }

            return false;
        }

        // Half-open: allow limited probes
        return $this->successCount[$provider] ?? 0 < $this->halfOpenMaxProbes;
    }

    /**
     * Record a successful dispatch to a provider.
     *
     * In half-open state, this counts toward closing the circuit.
     * In closed state, resets the failure counter.
     *
     * @param  string  $provider  Provider name
     */
    public function recordSuccess(string $provider): void
    {
        if (! $this->enabled) {
            return;
        }

        $state = $this->getState($provider);

        // Reset failure count on any success in closed state
        if ($state === self::STATE_CLOSED) {
            $this->failureCount[$provider] = 0;

            return;
        }

        // Count successes in half-open → potentially close circuit
        if ($state === self::STATE_HALF_OPEN) {
            $this->successCount[$provider] = ($this->successCount[$provider] ?? 0) + 1;

            if ($this->successCount[$provider] >= $this->successThreshold) {
                $this->transitionTo($provider, self::STATE_CLOSED);
                $this->failureCount[$provider] = 0;
                $this->successCount[$provider] = 0;
            }
        }
    }

    /**
     * Record a failed dispatch to a provider.
     *
     * Increments the failure counter. If threshold is exceeded,
     * opens the circuit for the provider.
     *
     * @param  string  $provider  Provider name
     * @param  string|null  $error  Optional error message for logging
     */
    public function recordFailure(string $provider, ?string $error = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $state = $this->getState($provider);

        $this->failureCount[$provider] = ($this->failureCount[$provider] ?? 0) + 1;

        // Half-open failure → immediately re-open
        if ($state === self::STATE_HALF_OPEN) {
            $this->transitionTo($provider, self::STATE_OPEN);
            $this->successCount[$provider] = 0;

            return;
        }

        // Closed state → check threshold
        if ($state === self::STATE_CLOSED && $this->failureCount[$provider] >= $this->failureThreshold) {
            $this->transitionTo($provider, self::STATE_OPEN);
        }

        // Persist last failure time
        $this->setLastFailureTime($provider, time());
    }

    /**
     * Get the current circuit state for a provider.
     *
     * @param  string  $provider  Provider name
     * @return string One of: closed, open, half_open
     */
    public function getState(string $provider): string
    {
        // Check in-memory first
        if (isset($this->state[$provider])) {
            return $this->state[$provider];
        }

        // Load from cache
        $cached = $this->cache->get(self::CACHE_PREFIX . $provider);

        if ($cached !== null && is_string($cached)) {
            $this->state[$provider] = $cached;

            return $cached;
        }

        // Default to closed
        $this->state[$provider] = self::STATE_CLOSED;

        return self::STATE_CLOSED;
    }

    /**
     * Manually reset the circuit for a provider to closed state.
     *
     * Useful after manually resolving a provider outage.
     *
     * @param  string  $provider  Provider name
     */
    public function reset(string $provider): void
    {
        $this->transitionTo($provider, self::STATE_CLOSED);
        $this->failureCount[$provider] = 0;
        $this->successCount[$provider] = 0;
        $this->cache->forget(self::CACHE_PREFIX . $provider . '_last_failure');
    }

    /**
     * Manually trip the circuit for a provider (force open).
     *
     * Useful for maintenance windows or planned provider downtime.
     *
     * @param  string  $provider  Provider name
     */
    public function trip(string $provider): void
    {
        $this->transitionTo($provider, self::STATE_OPEN);
        $this->failureCount[$provider] = $this->failureThreshold;
        $this->setLastFailureTime($provider, time());
    }

    /**
     * Get circuit breaker dashboard for all tracked providers.
     *
     * @return array{enabled: bool, providers: array<string, array{state: string, failures: int, successes: int, last_failure: int|null, cooldown_remaining: int|null}>}
     */
    public function getDashboard(): array
    {
        if (! $this->enabled) {
            return ['enabled' => false, 'providers' => []];
        }

        $providers = ['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'webhook'];
        $dashboard = [];

        foreach ($providers as $provider) {
            $state = $this->getState($provider);
            $lastFailure = $this->getLastFailureTime($provider);

            $dashboard[$provider] = [
                'state' => $state,
                'failures' => $this->failureCount[$provider] ?? 0,
                'successes' => $this->successCount[$provider] ?? 0,
                'last_failure' => $lastFailure,
                'cooldown_remaining' => $lastFailure !== null && $state === self::STATE_OPEN
                    ? max(0, $this->cooldownSeconds - (time() - $lastFailure))
                    : null,
            ];
        }

        return [
            'enabled' => $this->enabled,
            'failure_threshold' => $this->failureThreshold,
            'success_threshold' => $this->successThreshold,
            'cooldown_seconds' => $this->cooldownSeconds,
            'providers' => $dashboard,
        ];
    }

    /**
     * Get a summary of the circuit breaker state.
     *
     * @return array{enabled: bool, total_open: int, total_half_open: int, total_closed: int, providers: list<string>}
     */
    public function summary(): array
    {
        if (! $this->enabled) {
            return ['enabled' => false, 'total_open' => 0, 'total_half_open' => 0, 'total_closed' => 0, 'providers' => []];
        }

        $providers = ['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'webhook'];
        $open = [];
        $halfOpen = [];
        $closed = [];

        foreach ($providers as $provider) {
            $state = $this->getState($provider);

            match ($state) {
                self::STATE_OPEN => $open[] = $provider,
                self::STATE_HALF_OPEN => $halfOpen[] = $provider,
                default => $closed[] = $provider,
            };
        }

        return [
            'enabled' => $this->enabled,
            'total_open' => count($open),
            'total_half_open' => count($halfOpen),
            'total_closed' => count($closed),
            'providers' => array_merge($open, $halfOpen, $closed),
        ];
    }

    /**
     * Check if circuit breaker is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the failure threshold.
     */
    public function getFailureThreshold(): int
    {
        return $this->failureThreshold;
    }

    /**
     * Get the success threshold (for half-open → closed transition).
     */
    public function getSuccessThreshold(): int
    {
        return $this->successThreshold;
    }

    /**
     * Get the cooldown period in seconds.
     */
    public function getCooldownSeconds(): int
    {
        return $this->cooldownSeconds;
    }

    /**
     * Transition a provider to a new circuit state.
     *
     * @param  string  $provider  Provider name
     * @param  string  $newState  Target state
     */
    private function transitionTo(string $provider, string $newState): void
    {
        $oldState = $this->state[$provider] ?? self::STATE_CLOSED;
        $this->state[$provider] = $newState;

        // Persist to cache with TTL = 2x cooldown
        $this->cache->put(
            self::CACHE_PREFIX . $provider,
            $newState,
            $this->cooldownSeconds * 2,
        );
    }

    /**
     * Get the last failure timestamp for a provider.
     *
     * @param  string  $provider  Provider name
     * @return int|null Unix timestamp or null
     */
    private function getLastFailureTime(string $provider): ?int
    {
        $cached = $this->cache->get(self::CACHE_PREFIX . $provider . '_last_failure');

        return is_int($cached) ? $cached : null;
    }

    /**
     * Persist the last failure timestamp for a provider.
     *
     * @param  string  $provider  Provider name
     * @param  int  $timestamp  Unix timestamp
     */
    private function setLastFailureTime(string $provider, int $timestamp): void
    {
        $this->cache->put(
            self::CACHE_PREFIX . $provider . '_last_failure',
            $timestamp,
            $this->cooldownSeconds * 2,
        );
    }
}
