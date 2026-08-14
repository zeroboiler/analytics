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
 * Provider Auto-Failover Orchestration Service.
 *
 * Orchestrates automatic failover routing when analytics providers become
 * unavailable. Builds on top of ProviderCircuitBreaker by adding:
 *
 * - **Failover routing**: When a provider circuit opens, events are automatically
 *   routed to pre-configured fallback providers based on priority order.
 * - **Disaster recovery**: Sequential failover through a chain of providers,
 *   ensuring at least one provider receives every event.
 * - **Provider health scoring**: Composite health scores combining circuit state,
 *   SLA compliance, latency, and error rate for intelligent routing decisions.
 * - **Failover audit trail**: Every failover action is logged with timestamps,
 *   provider states, and event counts for post-incident analysis.
 * - **Automatic recovery**: When a failed provider recovers (circuit closes),
 *   traffic is gradually shifted back via configurable ramp-up percentage.
 *
 * Failover Strategy:
 *   1. Check primary provider circuit state
 *   2. If open, try first fallback in priority order
 *   3. If all fallbacks exhausted, route to dead-letter queue
 *   4. Log every failover action for audit trail
 *
 * Configuration (zeroboiler.analytics.failover):
 *   - enabled (default: false)
 *   - strategy: 'priority' | 'round-robin' | 'health-score'
 *   - max_cascade_depth (default: 3)
 *   - recovery_ramp_up_percent (default: 10, percentage per minute)
 *   - audit_log_ttl (default: 86400, seconds)
 *   - providers.{provider}.fallbacks: list of fallback provider names
 *   - providers.{provider}.priority: integer priority (lower = higher priority)
 *
 * @see \ZeroBoiler\Analytics\Services\ProviderCircuitBreaker
 * @see \ZeroBoiler\Analytics\Services\ProviderRateLimitService
 *
 * @since 145.0.0
 */
final class ProviderFailoverService
{
    /** @var string Cache key prefix for failover state */
    private const CACHE_PREFIX = 'zb_failover_';

    /** @var string Cache key prefix for failover audit log */
    private const AUDIT_PREFIX = 'zb_failover_audit_';

    /** Failover strategies */
    public const STRATEGY_PRIORITY = 'priority';

    public const STRATEGY_ROUND_ROBIN = 'round_robin';

    public const STRATEGY_HEALTH_SCORE = 'health_score';

    /**
     * @var list<string> All known analytics providers in priority order
     */
    private const ALL_PROVIDERS = [
        'ga4',
        'meta_pixel',
        'posthog',
        'plausible',
        'mixpanel',
        'amplitude',
        'tiktok',
        'linkedin',
        'webhook',
    ];

    private bool $enabled;

    private string $strategy;

    private int $maxCascadeDepth;

    private int $recoveryRampUpPercent;

    private int $auditLogTtl;

    /** @var array<string, list<string>> Provider → fallback provider names */
    private array $providerFallbacks;

    /** @var array<string, int> Provider → priority (lower = higher) */
    private array $providerPriority;

    /** @var array<string, int> Round-robin index per provider */
    private array $roundRobinIndex = [];

    private readonly CacheRepository $cache;

    private readonly ConfigRepository $config;

    /**
     * Create a new ProviderFailoverService.
     *
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;
        $this->config = $config;

        $failoverConfig = $config->get('zeroboiler.analytics.failover', []);

        $this->enabled = (bool) ($failoverConfig['enabled'] ?? false);
        $this->strategy = (string) ($failoverConfig['strategy'] ?? self::STRATEGY_PRIORITY);
        $this->maxCascadeDepth = (int) ($failoverConfig['max_cascade_depth'] ?? 3);
        $this->recoveryRampUpPercent = (int) ($failoverConfig['recovery_ramp_up_percent'] ?? 10);
        $this->auditLogTtl = (int) ($failoverConfig['audit_log_ttl'] ?? 86400);
        $this->providerFallbacks = $failoverConfig['providers'] ?? $this->defaultFallbacks();
        $this->providerPriority = $failoverConfig['priority'] ?? $this->defaultPriorities();
    }

    /**
     * Determine the target providers for an event, applying failover logic.
     *
     * If failover is disabled, returns all enabled providers unchanged.
     * If enabled, replaces any provider with an open circuit with its
     * highest-priority available fallback.
     *
     * @param  list<string>  $enabledProviders  Provider names currently enabled in config
     * @param  array<string, string>  $circuitStates  Provider → circuit state (closed/open/half_open)
     * @return array{targets: list<string>, failovers: list<array{from: string, to: string, reason: string}>}
     */
    public function resolveTargets(array $enabledProviders, array $circuitStates): array
    {
        if (! $this->enabled) {
            return [
                'targets' => $enabledProviders,
                'failovers' => [],
            ];
        }

        $targets = [];
        $failovers = [];

        foreach ($enabledProviders as $provider) {
            $state = $circuitStates[$provider] ?? 'closed';

            if ($state === 'closed') {
                // Provider is healthy — check if it should receive partial traffic
                // during recovery ramp-up
                $rampUp = $this->getRecoveryRampUp($provider);

                if ($rampUp < 100) {
                    // Partial traffic during recovery
                    if ($this->shouldRouteByProbability($rampUp / 100.0)) {
                        $targets[] = $provider;
                    } else {
                        $fallback = $this->selectFallback($provider, $targets, $circuitStates);
                        if ($fallback !== null) {
                            $targets[] = $fallback;
                            $failovers[] = [
                                'from' => $provider,
                                'to' => $fallback,
                                'reason' => "recovery_ramp_up:{$rampUp}%",
                            ];
                            $this->logFailover($provider, $fallback, 'recovery_ramp_up');
                        }
                    }
                } else {
                    $targets[] = $provider;
                }
            } elseif ($state === 'open') {
                // Provider is down — select fallback
                $fallback = $this->selectFallback($provider, $targets, $circuitStates);

                if ($fallback !== null) {
                    $targets[] = $fallback;
                    $failovers[] = [
                        'from' => $provider,
                        'to' => $fallback,
                        'reason' => 'circuit_open',
                    ];
                    $this->logFailover($provider, $fallback, 'circuit_open');
                } else {
                    Log::warning(
                        "ZeroBoiler Analytics: Provider '{$provider}' is down and no fallback available",
                        ['provider' => $provider],
                    );
                }
            } else {
                // half_open — send to provider (circuit breaker will probe)
                $targets[] = $provider;
            }
        }

        return [
            'targets' => array_values(array_unique($targets)),
            'failovers' => $failovers,
        ];
    }

    /**
     * Select the best fallback provider based on the configured strategy.
     *
     * @param  string  $failedProvider  The provider that needs a fallback
     * @param  list<string>  $alreadySelected  Providers already in the target list
     * @param  array<string, string>  $circuitStates  Provider → circuit state
     * @return string|null Best fallback provider, or null if none available
     */
    public function selectFallback(string $failedProvider, array $alreadySelected, array $circuitStates): ?string
    {
        $candidates = $this->getFallbackCandidates($failedProvider, $alreadySelected, $circuitStates);

        if ($candidates === []) {
            return null;
        }

        return match ($this->strategy) {
            self::STRATEGY_PRIORITY => $this->selectByPriority($candidates),
            self::STRATEGY_ROUND_ROBIN => $this->selectByRoundRobin($failedProvider, $candidates),
            self::STRATEGY_HEALTH_SCORE => $this->selectByHealthScore($candidates, $circuitStates),
            default => $candidates[0],
        };
    }

    /**
     * Get available fallback candidates for a failed provider.
     *
     * Filters out providers that are already selected, have open circuits,
     * or exceed the maximum cascade depth.
     *
     * @param  string  $failedProvider
     * @param  list<string>  $alreadySelected
     * @param  array<string, string>  $circuitStates
     * @return list<string> Available fallback providers
     */
    public function getFallbackCandidates(string $failedProvider, array $alreadySelected, array $circuitStates): array
    {
        $fallbacks = $this->providerFallbacks[$failedProvider] ?? [];

        // Count cascade depth (how many providers have already failed over)
        $cascadeCount = count($alreadySelected) - 1;

        $candidates = [];

        foreach ($fallbacks as $fallback) {
            // Skip if already selected
            if (in_array($fallback, $alreadySelected, true)) {
                continue;
            }

            // Skip if circuit is open
            $state = $circuitStates[$fallback] ?? 'closed';
            if ($state === 'open') {
                continue;
            }

            // Skip if cascade depth exceeded
            if ($cascadeCount >= $this->maxCascadeDepth) {
                Log::warning(
                    "ZeroBoiler Analytics: Max failover cascade depth ({$this->maxCascadeDepth}) exceeded",
                    ['failed_provider' => $failedProvider],
                );
                break;
            }

            $candidates[] = $fallback;
        }

        return $candidates;
    }

    /**
     * Get the current recovery ramp-up percentage for a provider.
     *
     * After a provider recovers (circuit closes), traffic is gradually
     * increased by recoveryRampUpPercent per minute until 100%.
     *
     * @return int Ramp-up percentage (0-100). 100 means fully recovered.
     */
    public function getRecoveryRampUp(string $provider): int
    {
        $cacheKey = self::CACHE_PREFIX . 'ramp_' . $provider;
        $rampData = $this->cache->get($cacheKey);

        if ($rampData === null) {
            return 100; // No active ramp-up = fully recovered
        }

        /** @var array{started_at: int, current_percent: int} $rampData */
        $elapsedMinutes = (int) floor((time() - $rampData['started_at']) / 60);
        $newPercent = min(100, $rampData['current_percent'] + ($elapsedMinutes * $this->recoveryRampUpPercent));

        if ($newPercent >= 100) {
            $this->cache->forget($cacheKey);

            return 100;
        }

        // Update cache with new percentage
        $this->cache->put($cacheKey, [
            'started_at' => $rampData['started_at'],
            'current_percent' => $newPercent,
        ], 3600);

        return $newPercent;
    }

    /**
     * Start a recovery ramp-up for a provider.
     *
     * Called when a provider's circuit transitions from open to closed.
     *
     * @param  string  $provider
     * @param  int  $initialPercent  Starting percentage (default: recoveryRampUpPercent)
     */
    public function startRecoveryRampUp(string $provider, int $initialPercent = 0): void
    {
        $cacheKey = self::CACHE_PREFIX . 'ramp_' . $provider;
        $percent = $initialPercent > 0 ? $initialPercent : $this->recoveryRampUpPercent;

        $this->cache->put($cacheKey, [
            'started_at' => time(),
            'current_percent' => $percent,
        ], 3600);

        Log::info(
            "ZeroBoiler Analytics: Recovery ramp-up started for '{$provider}' at {$percent}%",
            ['provider' => $provider, 'initial_percent' => $percent],
        );
    }

    /**
     * Reset the recovery ramp-up for a provider (full traffic restoration).
     *
     * @param  string  $provider
     */
    public function resetRecoveryRampUp(string $provider): void
    {
        $cacheKey = self::CACHE_PREFIX . 'ramp_' . $provider;
        $this->cache->forget($cacheKey);
    }

    /**
     * Log a failover action to the audit trail.
     *
     * Audit entries include timestamp, source provider, target provider,
     * reason, and are stored in cache with configurable TTL.
     *
     * @param  string  $fromProvider
     * @param  string  $toProvider
     * @param  string  $reason
     */
    public function logFailover(string $fromProvider, string $toProvider, string $reason): void
    {
        $auditKey = self::AUDIT_PREFIX . date('Y-m-d');
        $existing = $this->cache->get($auditKey, []);

        $existing[] = [
            'timestamp' => date('c'),
            'from' => $fromProvider,
            'to' => $toProvider,
            'reason' => $reason,
        ];

        // Keep only last 1000 entries per day
        if (count($existing) > 1000) {
            $existing = array_slice($existing, -1000);
        }

        $this->cache->put($auditKey, $existing, $this->auditLogTtl);
    }

    /**
     * Get the failover audit trail for a specific date.
     *
     * @param  string|null  $date  Date in Y-m-d format (default: today)
     * @return list<array{timestamp: string, from: string, to: string, reason: string}>
     */
    public function getAuditTrail(?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        $auditKey = self::AUDIT_PREFIX . $date;

        /** @var list<array{timestamp: string, from: string, to: string, reason: string}> $entries */
        $entries = $this->cache->get($auditKey, []);

        return $entries;
    }

    /**
     * Get a summary of failover activity across all providers.
     *
     * @return array{total_failovers: int, by_provider: array<string, int>, by_reason: array<string, int>, recent: list<array{timestamp: string, from: string, to: string, reason: string}>}
     */
    public function getFailoverSummary(): array
    {
        $todayTrail = $this->getAuditTrail();

        $byProvider = [];
        $byReason = [];

        foreach ($todayTrail as $entry) {
            $from = $entry['from'];
            $reason = $entry['reason'];

            $byProvider[$from] = ($byProvider[$from] ?? 0) + 1;
            $byReason[$reason] = ($byReason[$reason] ?? 0) + 1;
        }

        return [
            'total_failovers' => count($todayTrail),
            'by_provider' => $byProvider,
            'by_reason' => $byReason,
            'recent' => array_slice($todayTrail, -10),
        ];
    }

    /**
     * Get the current failover configuration.
     *
     * @return array{enabled: bool, strategy: string, max_cascade_depth: int, recovery_ramp_up_percent: int, provider_count: int, providers: array<string, list<string>>}
     */
    public function getConfiguration(): array
    {
        return [
            'enabled' => $this->enabled,
            'strategy' => $this->strategy,
            'max_cascade_depth' => $this->maxCascadeDepth,
            'recovery_ramp_up_percent' => $this->recoveryRampUpPercent,
            'provider_count' => count($this->providerFallbacks),
            'providers' => $this->providerFallbacks,
        ];
    }

    /**
     * Check if failover is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the configured failover strategy.
     */
    public function getStrategy(): string
    {
        return $this->strategy;
    }

    /**
     * Compute a composite health score for a provider (0-100).
     *
     * Combines circuit state, SLA data, and error rates into a single score.
     * Used by the 'health_score' failover strategy.
     *
     * @param  string  $provider
     * @param  array<string, string>  $circuitStates
     * @param  array<string, array{success_rate?: float, avg_latency_ms?: float, error_rate?: float}>  $providerMetrics
     * @return int Health score 0-100
     */
    public function computeHealthScore(string $provider, array $circuitStates, array $providerMetrics = []): int
    {
        $state = $circuitStates[$provider] ?? 'closed';

        // Base score from circuit state
        $baseScore = match ($state) {
            'closed' => 80,
            'half_open' => 50,
            'open' => 0,
            default => 60,
        };

        // Adjust by success rate if available
        $metrics = $providerMetrics[$provider] ?? [];
        $successRate = $metrics['success_rate'] ?? 1.0;
        $errorRate = $metrics['error_rate'] ?? 0.0;
        $avgLatency = $metrics['avg_latency_ms'] ?? 0;

        // Success rate bonus/penalty (-20 to +20)
        $successBonus = (int) round(($successRate - 0.95) * 400); // 95% = 0, 100% = +20, 90% = -20
        $successBonus = max(-20, min(20, $successBonus));

        // Error rate penalty (0 to -15)
        $errorPenalty = (int) min(15, round($errorRate * 100));

        // Latency penalty (0 to -5 for >500ms)
        $latencyPenalty = $avgLatency > 500 ? (int) min(5, round(($avgLatency - 500) / 200)) : 0;

        return max(0, min(100, $baseScore + $successBonus - $errorPenalty - $latencyPenalty));
    }

    /**
     * Get the list of all known providers.
     *
     * @return list<string>
     */
    public function allProviders(): array
    {
        return self::ALL_PROVIDERS;
    }

    /**
     * Select a fallback using priority-based strategy.
     *
     * @param  list<string>  $candidates
     * @return string
     */
    private function selectByPriority(array $candidates): string
    {
        $sorted = $candidates;

        usort($sorted, fn (string $a, string $b): int =>
            ($this->providerPriority[$a] ?? 999) <=> ($this->providerPriority[$b] ?? 999)
        );

        return $sorted[0];
    }

    /**
     * Select a fallback using round-robin strategy.
     *
     * @param  string  $sourceProvider
     * @param  list<string>  $candidates
     * @return string
     */
    private function selectByRoundRobin(string $sourceProvider, array $candidates): string
    {
        $key = $sourceProvider . '_rr';
        $index = $this->roundRobinIndex[$key] ?? 0;
        $selected = $candidates[$index % count($candidates)];

        $this->roundRobinIndex[$key] = $index + 1;

        return $selected;
    }

    /**
     * Select a fallback using health score strategy.
     *
     * @param  list<string>  $candidates
     * @param  array<string, string>  $circuitStates
     * @return string
     */
    private function selectByHealthScore(array $candidates, array $circuitStates): string
    {
        $scored = [];

        foreach ($candidates as $candidate) {
            $scored[$candidate] = $this->computeHealthScore($candidate, $circuitStates);
        }

        arsort($scored);

        return array_key_first($scored) ?? $candidates[0];
    }

    /**
     * Decide if an event should be routed based on probability.
     *
     * @param  float  $probability  0.0 to 1.0
     */
    private function shouldRouteByProbability(float $probability): bool
    {
        return mt_rand(0, 100) / 100.0 <= $probability;
    }

    /**
     * Get default fallback mappings when no config is provided.
     *
     * @return array<string, list<string>>
     */
    private function defaultFallbacks(): array
    {
        return [
            'ga4' => ['posthog', 'meta_pixel', 'webhook'],
            'meta_pixel' => ['ga4', 'posthog', 'webhook'],
            'posthog' => ['ga4', 'meta_pixel', 'webhook'],
            'plausible' => ['ga4', 'posthog', 'webhook'],
            'mixpanel' => ['ga4', 'amplitude', 'webhook'],
            'amplitude' => ['mixpanel', 'ga4', 'webhook'],
            'tiktok' => ['meta_pixel', 'ga4', 'webhook'],
            'linkedin' => ['ga4', 'meta_pixel', 'webhook'],
            'webhook' => ['ga4', 'posthog', 'meta_pixel'],
        ];
    }

    /**
     * Get default provider priorities when no config is provided.
     *
     * @return array<string, int>
     */
    private function defaultPriorities(): array
    {
        return [
            'ga4' => 1,
            'meta_pixel' => 2,
            'posthog' => 3,
            'plausible' => 4,
            'mixpanel' => 5,
            'amplitude' => 6,
            'tiktok' => 7,
            'linkedin' => 8,
            'webhook' => 9,
        ];
    }
}
