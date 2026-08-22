<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Multi-provider fallback strategy with circuit breaker integration.
 *
 * When a primary analytics provider fails (circuit breaker open), this service
 * automatically redirects events to configured fallback providers. Supports
 * configurable fallback chains per provider, priority-based routing, and
 * automatic recovery when the primary provider comes back online.
 *
 * Fallback chains are configured in `zeroboiler.analytics.fallback`.
 *
 * Inspired by Segment's source-sending redundancy and Amplitude's
 * multi-region event delivery.
 *
 * @since 9.4.0
 *
 * @see ProviderCircuitBreaker
 */
final class ProviderFallbackService
{
    /** @var array<string, string> Provider name → circuit state from CircuitBreaker */
    private array $providerStates = [];

    /** @var array<string, int> Per-provider fallback attempt counters (in-memory) */
    private array $fallbackCounts = [];

    private bool $enabled;

    /** @var array<string, list<string>> Fallback chains per provider */
    private array $fallbackChains;

    private int $maxFallbackDepth;

    private string $cachePrefix;

    private CacheRepository $cache;

    private ConfigRepository $config;

    /**
     * Create a new ProviderFallbackService instance.
     *
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;
        $this->config = $config;

        $fallbackConfig = $config->get('zeroboiler.analytics.fallback', []);
        /** @var array{enabled?: bool, chains?: array<string, list<string>>, max_depth?: int, cache_prefix?: string} $fallbackConfig */

        $this->enabled = (bool) ($fallbackConfig['enabled'] ?? true);
        $this->fallbackChains = (array) ($fallbackConfig['chains'] ?? []);
        $this->maxFallbackDepth = (int) ($fallbackConfig['max_depth'] ?? 3);
        $this->cachePrefix = (string) ($fallbackConfig['cache_prefix'] ?? 'zb_fallback_');
    }

    /**
     * Get the fallback chain for a given provider.
     *
     * Returns an ordered list of provider names to try when the given
     * provider is unavailable. If no chain is configured, returns an empty array.
     *
     * @param  string  $providerName  The provider that failed (e.g., 'ga4', 'meta', 'posthog', 'plausible', 'webhook')
     * @return list<string> Ordered list of fallback provider names
     */
    public function getFallbackChain(string $providerName): array
    {
        return $this->fallbackChains[$providerName] ?? [];
    }

    /**
     * Resolve the best available provider for dispatching an event.
     *
     * Checks the circuit breaker state for each provider in the chain
     * and returns the first provider that is not in OPEN state.
     * If the primary provider is healthy, returns it immediately.
     *
     * @param  string  $primaryProvider  The intended primary provider name
     * @param  list<string>  $circuitStates  Provider => circuit state ('closed', 'open', 'half_open')
     * @return string The resolved provider name (may be the primary or a fallback)
     */
    public function resolveProvider(string $primaryProvider, array $circuitStates = []): string
    {
        if (! $this->enabled) {
            return $primaryProvider;
        }

        $this->providerStates = $circuitStates;

        // If primary is healthy (closed or half_open), use it
        $primaryState = $circuitStates[$primaryProvider] ?? 'closed';
        if ($primaryState !== 'open') {
            return $primaryProvider;
        }

        // Primary is down — walk the fallback chain
        $chain = $this->getFallbackChain($primaryProvider);

        foreach ($chain as $fallbackProvider) {
            $fallbackState = $circuitStates[$fallbackProvider] ?? 'closed';
            if ($fallbackState !== 'open') {
                $this->recordFallback($primaryProvider, $fallbackProvider);

                return $fallbackProvider;
            }
        }

        // All providers in chain are down — return primary anyway (will likely fail)
        return $primaryProvider;
    }

    /**
     * Record a fallback event for monitoring and alerting.
     *
     * Increments counters and stores the event in cache for dashboard display.
     *
     * @param  string  $fromProvider  The provider that failed
     * @param  string  $toProvider  The fallback provider that received the event
     */
    public function recordFallback(string $fromProvider, string $toProvider): void
    {
        $key = "{$fromProvider}:{$toProvider}";

        if (! isset($this->fallbackCounts[$key])) {
            $this->fallbackCounts[$key] = 0;
        }

        $this->fallbackCounts[$key]++;

        // Persist to cache for cross-process visibility
        $cacheKey = "{$this->cachePrefix}count_{$key}";
        $current = (int) $this->cache->get($cacheKey, 0);
        $this->cache->put($cacheKey, $current + 1, 86400); // 24 hours
    }

    /**
     * Get the total number of fallback events for a specific chain.
     *
     * @param  string  $fromProvider
     * @param  string|null  $toProvider  If null, returns total for all fallbacks from this provider
     * @return int
     */
    public function getFallbackCount(string $fromProvider, ?string $toProvider = null): int
    {
        if ($toProvider !== null) {
            return $this->fallbackCounts["{$fromProvider}:{$toProvider}"] ?? 0;
        }

        $total = 0;
        foreach ($this->fallbackCounts as $key => $count) {
            if (str_starts_with($key, "{$fromProvider}:")) {
                $total += $count;
            }
        }

        return $total;
    }

    /**
     * Get all fallback chains configuration.
     *
     * @return array<string, list<string>>
     */
    public function getAllChains(): array
    {
        return $this->fallbackChains;
    }

    /**
     * Check if fallback is globally enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get fallback service statistics for dashboards.
     *
     * @return array{enabled: bool, chains: array<string, list<string>>, fallback_counts: array<string, int>, max_depth: int, chain_count: int}
     */
    public function stats(): array
    {
        return [
            'enabled' => $this->enabled,
            'chains' => $this->fallbackChains,
            'fallback_counts' => $this->fallbackCounts,
            'max_depth' => $this->maxFallbackDepth,
            'chain_count' => count($this->fallbackChains),
        ];
    }

    /**
     * Get the maximum fallback depth allowed.
     */
    public function getMaxFallbackDepth(): int
    {
        return $this->maxFallbackDepth;
    }

    /**
     * Check if a provider has any configured fallback chain.
     */
    public function hasFallbackChain(string $providerName): bool
    {
        return isset($this->fallbackChains[$providerName]) && count($this->fallbackChains[$providerName]) > 0;
    }

    /**
     * Validate the fallback chain configuration.
     *
     * Checks for circular dependencies, invalid provider names,
     * and excessive chain depth.
     *
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public function validate(): array
    {
        $errors = [];
        $warnings = [];
        $validProviders = ['ga4', 'gtm', 'meta', 'posthog', 'plausible', 'webhook', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];

        foreach ($this->fallbackChains as $provider => $chain) {
            if (! in_array($provider, $validProviders, true)) {
                $errors[] = "Invalid primary provider '{$provider}' in fallback chain";
                continue;
            }

            if (count($chain) > $this->maxFallbackDepth) {
                $errors[] = "Fallback chain for '{$provider}' exceeds max depth ({$this->maxFallbackDepth})";
            }

            foreach ($chain as $i => $fallback) {
                if (! in_array($fallback, $validProviders, true)) {
                    $errors[] = "Invalid fallback provider '{$fallback}' in chain for '{$provider}' at index {$i}";
                }

                if ($fallback === $provider) {
                    $errors[] = "Circular dependency: '{$provider}' cannot fallback to itself";
                }
            }

            // Check for circular chains (A → B → A)
            foreach ($chain as $fallback) {
                $fallbackChain = $this->fallbackChains[$fallback] ?? [];
                if (in_array($provider, $fallbackChain, true)) {
                    $warnings[] = "Potential circular chain detected: '{$provider}' → '{$fallback}' → '{$provider}'";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Clear all in-memory fallback counters.
     */
    public function resetCounters(): void
    {
        $this->fallbackCounts = [];
    }

    /**
     * Get cached fallback counts (persisted across processes).
     *
     * @return array<string, int>
     */
    public function getCachedCounts(): array
    {
        $counts = [];

        foreach ($this->fallbackChains as $provider => $chain) {
            foreach ($chain as $fallback) {
                $cacheKey = "{$this->cachePrefix}count_{$provider}:{$fallback}";
                $counts["{$provider}:{$fallback}"] = (int) $this->cache->get($cacheKey, 0);
            }
        }

        return $counts;
    }

    /**
     * Clear cached fallback counts.
     */
    public function clearCachedCounts(): void
    {
        foreach ($this->fallbackChains as $provider => $chain) {
            foreach ($chain as $fallback) {
                $cacheKey = "{$this->cachePrefix}count_{$provider}:{$fallback}";
                $this->cache->forget($cacheKey);
            }
        }
    }

    /**
     * Get the provider health summary with fallback status.
     *
     * @param  array<string, string>  $circuitStates  Provider => circuit state
     * @return array{providers: array<string, array{state: string, has_fallback: bool, fallback_chain: list<string>, fallback_counts: int}>}
     */
    public function healthSummary(array $circuitStates = []): array
    {
        $this->providerStates = $circuitStates;
        $providers = [];

        $allProviders = ['ga4', 'gtm', 'meta', 'posthog', 'plausible', 'webhook', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];

        foreach ($allProviders as $provider) {
            $state = $circuitStates[$provider] ?? 'closed';
            $chain = $this->getFallbackChain($provider);

            $providers[$provider] = [
                'state' => $state,
                'has_fallback' => count($chain) > 0,
                'fallback_chain' => $chain,
                'fallback_counts' => $this->getFallbackCount($provider),
            ];
        }

        return ['providers' => $providers];
    }
}
