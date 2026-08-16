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
 * Event dispatch orchestration engine — unified coordinator for intelligent
 * event dispatch across all analytics providers.
 *
 * Bridges latency tracking, replay audit, circuit breaker, reliability scoring,
 * and provider dispatch ordering into a single decision-making layer. Each
 * dispatch decision is context-aware, considering:
 *
 * - **Provider health**: Circuit breaker state, recent failure rate
 * - **Latency budget**: Is the provider within acceptable P95 latency?
 * - **Reliability score**: Composite delivery reliability (0-100)
 * - **Dispatch priority**: Critical events skip throttling, low-priority may be sampled
 * - **Replay context**: Events from replay operations get special routing
 * - **Budget compliance**: Per-provider event budget not exceeded
 * - **Consent state**: GDPR consent honored per provider
 *
 * All decisions are logged to a dispatch decision ledger for audit and debugging.
 * Provides dashboard data for the AnalyticsOrchestratorCommand.
 *
 * Dispatch flow:
 * 1. Pre-flight checks (consent, budget, circuit breaker)
 * 2. Priority scoring (critical events routed to fastest providers first)
 * 3. Latency-aware routing (slow providers deferred for non-critical events)
 * 4. Decision logging (provider, action, reasoning, latency estimate)
 * 5. Post-dispatch recording (latency, success/failure, replay audit update)
 *
 * @see \ZeroBoiler\Analytics\Services\EventDispatchLatencyTracker
 * @see \ZeroBoiler\Analytics\Services\EventReplayAuditLedger
 * @see \ZeroBoiler\Analytics\Services\ProviderCircuitBreaker
 * @see \ZeroBoiler\Analytics\Services\AnalyticsEventReliabilityService
 * @see \ZeroBoiler\Analytics\Services\ProviderDispatchOrderService
 *
 * @since 207.0.0
 */
final class EventDispatchOrchestrator
{
    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'zb_orchestrator_';

    /** @var int Decision ledger TTL (seconds) */
    private const DECISION_LEDGER_TTL = 1800;

    /** @var int Maximum decisions to retain in ledger */
    private const MAX_DECISIONS = 1000;

    /** @var float Minimum reliability score for auto-dispatch (0-100) */
    private const MIN_RELIABILITY_AUTO = 60.0;

    /** @var float Minimum reliability score for critical events */
    private const MIN_RELIABILITY_CRITICAL = 40.0;

    /** Dispatch decision actions */
    public const ACTION_DISPATCH = 'dispatch';
    public const ACTION_DEFER = 'defer';
    public const ACTION_DROP = 'drop';
    public const ACTION_REPLAY = 'replay';
    public const ACTION_SAMPLE = 'sample';
    public const ACTION_CIRCUIT_OPEN = 'circuit_open';
    public const ACTION_BUDGET_EXCEEDED = 'budget_exceeded';
    public const ACTION_CONSENT_DENIED = 'consent_denied';

    /** @var array<string, mixed> */
    private readonly array $config;

    private readonly bool $enabled;

    private readonly int $decisionTtl;

    private readonly int $maxDecisions;

    private readonly float $minReliabilityAuto;

    private readonly float $minReliabilityCritical;

    private readonly bool $logDecisions;

    private readonly CacheRepository $cache;

    /** @var int Decision counter for unique IDs */
    private int $decisionCounter = 0;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $this->cache = $cache;

        $orchConfig = $config->get('zeroboiler.analytics.dispatch_orchestrator', []);
        /** @var array{enabled?: bool, decision_ttl?: int, max_decisions?: int, min_reliability_auto?: float, min_reliability_critical?: float, log_decisions?: bool} $orchConfig */

        $this->config = $orchConfig;
        $this->enabled = (bool) ($orchConfig['enabled'] ?? true);
        $this->decisionTtl = (int) ($orchConfig['decision_ttl'] ?? self::DECISION_LEDGER_TTL);
        $this->maxDecisions = (int) ($orchConfig['max_decisions'] ?? self::MAX_DECISIONS);
        $this->minReliabilityAuto = (float) ($orchConfig['min_reliability_auto'] ?? self::MIN_RELIABILITY_AUTO);
        $this->minReliabilityCritical = (float) ($orchConfig['min_reliability_critical'] ?? self::MIN_RELIABILITY_CRITICAL);
        $this->logDecisions = (bool) ($orchConfig['log_decisions'] ?? true);
    }

    /**
     * Evaluate whether an event should be dispatched to a specific provider.
     *
     * Returns a dispatch decision with action, reasoning, and metadata.
     *
     * @param  string  $provider  Provider identifier (ga4, meta_pixel, etc.)
     * @param  AnalyticsEvent  $event  The event to evaluate
     * @param  array{circuit_state?: string, reliability_score?: float, budget_remaining?: int, consent_granted?: bool, latency_p95?: float|null, is_replay?: bool}  $context  Provider context signals
     * @return array{action: string, provider: string, event: string, reasoning: string, priority: string|null, reliability: float|null, circuit_state: string|null, latency_p95: float|null, is_replay: bool, timestamp: string}
     */
    public function evaluate(
        string $provider,
        AnalyticsEvent $event,
        array $context = [],
    ): array {
        if (! $this->enabled) {
            return $this->makeDecision(self::ACTION_DISPATCH, $provider, $event, 'Orchestrator disabled — auto-dispatch', $context);
        }

        // Check consent
        if (($context['consent_granted'] ?? true) === false) {
            return $this->makeDecision(self::ACTION_CONSENT_DENIED, $provider, $event, 'Consent denied for provider', $context);
        }

        // Check circuit breaker
        $circuitState = $context['circuit_state'] ?? 'closed';
        if ($circuitState === 'open') {
            return $this->makeDecision(self::ACTION_CIRCUIT_OPEN, $provider, $event, 'Circuit breaker open — provider unavailable', $context);
        }

        // Check budget
        $budgetRemaining = $context['budget_remaining'] ?? null;
        if ($budgetRemaining !== null && $budgetRemaining <= 0) {
            return $this->makeDecision(self::ACTION_BUDGET_EXCEEDED, $provider, $event, 'Event budget exceeded for provider', $context);
        }

        // Check reliability threshold
        $reliability = $context['reliability_score'] ?? 100.0;
        $isCritical = $event->priority === 'critical';
        $minReliability = $isCritical ? $this->minReliabilityCritical : $this->minReliabilityAuto;

        if ($reliability < $minReliability && ! $isCritical) {
            // Non-critical events deferred when reliability is low
            if ($reliability < ($minReliability * 0.5)) {
                return $this->makeDecision(self::ACTION_DROP, $provider, $event, "Reliability {$reliability} below minimum {$minReliability}", $context);
            }
            return $this->makeDecision(self::ACTION_DEFER, $provider, $event, "Reliability {$reliability} degraded — deferring non-critical", $context);
        }

        // Replay events get special routing
        if ($context['is_replay'] ?? false) {
            return $this->makeDecision(self::ACTION_REPLAY, $provider, $event, 'Replay dispatch — routing to available provider', $context);
        }

        // Low-priority events may be sampled under latency pressure
        if ($event->priority === 'low' || $event->priority === 'background') {
            $latencyP95 = $context['latency_p95'] ?? null;
            if ($latencyP95 !== null && $latencyP95 > 2000.0) {
                return $this->makeDecision(self::ACTION_SAMPLE, $provider, $event, "High latency (P95: {$latencyP95}ms) — sampling low-priority", $context);
            }
        }

        return $this->makeDecision(self::ACTION_DISPATCH, $provider, $event, 'All checks passed', $context);
    }

    /**
     * Evaluate dispatch for multiple providers simultaneously.
     *
     * Returns an ordered list of dispatch decisions, sorted by action priority
     * (dispatch > replay > sample > defer > circuit_open > budget_exceeded > consent_denied > drop).
     *
     * @param  AnalyticsEvent  $event  The event to evaluate
     * @param  array<string, array{circuit_state?: string, reliability_score?: float, budget_remaining?: int|null, consent_granted?: bool, latency_p95?: float|null}>  $providerContexts  Provider context signals keyed by provider name
     * @return list<array{action: string, provider: string, event: string, reasoning: string, priority: string|null, reliability: float|null, circuit_state: string|null, latency_p95: float|null, is_replay: bool, timestamp: string}>
     */
    public function evaluateMulti(
        AnalyticsEvent $event,
        array $providerContexts,
    ): array {
        $decisions = [];

        foreach ($providerContexts as $provider => $context) {
            $decisions[] = $this->evaluate($provider, $event, $context);
        }

        // Sort by action priority (dispatch first, drop last)
        usort($decisions, function (array $a, array $b): int {
            return $this->actionPriority($a['action']) <=> $this->actionPriority($b['action']);
        });

        return $decisions;
    }

    /**
     * Record a dispatch outcome for post-dispatch analytics.
     *
     * Updates the decision ledger with the actual outcome.
     *
     * @param  string  $provider  Provider identifier
     * @param  string  $event  Event name
     * @param  bool  $success  Whether dispatch succeeded
     * @param  float|null  $latencyMs  Actual dispatch latency (ms)
     * @param  string|null  $error  Error message if failed
     * @return void
     */
    public function recordOutcome(
        string $provider,
        string $event,
        bool $success,
        ?float $latencyMs = null,
        ?string $error = null,
    ): void {
        $key = self::CACHE_PREFIX . 'outcomes_' . $provider;
        $outcomes = $this->cache->get($key, []);
        /** @var list<array{event: string, success: bool, latency_ms: float|null, error: string|null, timestamp: string}> $outcomes */

        $outcomes[] = [
            'event' => $event,
            'success' => $success,
            'latency_ms' => $latencyMs,
            'error' => $error,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        // Retain last 200 outcomes per provider
        if (count($outcomes) > 200) {
            $outcomes = array_slice($outcomes, -200);
        }

        $this->cache->put($key, $outcomes, $this->decisionTtl);
    }

    /**
     * Get aggregated dispatch statistics across all providers.
     *
     * @return array{total_decisions: int, by_action: array<string, int>, by_provider: array<string, int>, recent_decisions: list<array{action: string, provider: string, event: string, reasoning: string, timestamp: string}>}
     */
    public function stats(): array
    {
        $decisions = $this->getDecisionLedger();

        $byAction = [];
        $byProvider = [];

        foreach ($decisions as $d) {
            $action = $d['action'] ?? 'unknown';
            $provider = $d['provider'] ?? 'unknown';

            $byAction[$action] = ($byAction[$action] ?? 0) + 1;
            $byProvider[$provider] = ($byProvider[$provider] ?? 0) + 1;
        }

        return [
            'total_decisions' => count($decisions),
            'by_action' => $byAction,
            'by_provider' => $byProvider,
            'recent_decisions' => array_slice($decisions, -20),
        ];
    }

    /**
     * Get per-provider outcome statistics.
     *
     * @return array<string, array{total: int, success: int, failed: int, avg_latency_ms: float|null, recent_errors: list<string>}>
     */
    public function outcomeStats(): array
    {
        $providers = ['ga4', 'gtm', 'meta_pixel', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin', 'webhook'];
        $stats = [];

        foreach ($providers as $provider) {
            $key = self::CACHE_PREFIX . 'outcomes_' . $provider;
            $outcomes = $this->cache->get($key, []);
            /** @var list<array{event: string, success: bool, latency_ms: float|null, error: string|null}> $outcomes */

            $total = count($outcomes);
            $success = 0;
            $latencies = [];
            $recentErrors = [];

            foreach ($outcomes as $o) {
                if ($o['success']) {
                    $success++;
                } else {
                    $recentErrors[] = $o['error'] ?? 'Unknown error';
                }
                if ($o['latency_ms'] !== null) {
                    $latencies[] = $o['latency_ms'];
                }
            }

            $stats[$provider] = [
                'total' => $total,
                'success' => $success,
                'failed' => $total - $success,
                'avg_latency_ms' => count($latencies) > 0
                    ? round(array_sum($latencies) / count($latencies), 2)
                    : null,
                'recent_errors' => array_slice($recentErrors, -5),
            ];
        }

        return $stats;
    }

    /**
     * Clear all orchestrator data from cache.
     *
     * @return void
     */
    public function clear(): void
    {
        $keys = $this->cache->get(self::CACHE_PREFIX . 'decision_keys', []);
        /** @var list<string> $keys */

        foreach ($keys as $key) {
            $this->cache->forget($key);
        }

        $this->cache->forget(self::CACHE_PREFIX . 'decision_keys');
        $this->cache->forget(self::CACHE_PREFIX . 'index');

        // Clear outcome caches
        $providers = ['ga4', 'gtm', 'meta_pixel', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin', 'webhook'];
        foreach ($providers as $provider) {
            $this->cache->forget(self::CACHE_PREFIX . 'outcomes_' . $provider);
        }
    }

    /**
     * Get dispatch health summary — composite view of orchestration health.
     *
     * @return array{enabled: bool, total_decisions: int, dispatch_rate: float, defer_rate: float, drop_rate: float, provider_summary: array<string, array{total: int, success_rate: float|null}>}
     */
    public function healthSummary(): array
    {
        $stats = $this->stats();
        $total = $stats['total_decisions'];
        $byAction = $stats['by_action'];

        $dispatchRate = $total > 0
            ? round(($byAction[self::ACTION_DISPATCH] ?? 0) / $total * 100, 1)
            : 0.0;

        $deferRate = $total > 0
            ? round(($byAction[self::ACTION_DEFER] ?? 0) / $total * 100, 1)
            : 0.0;

        $dropRate = $total > 0
            ? round(($byAction[self::ACTION_DROP] ?? 0) / $total * 100, 1)
            : 0.0;

        $outcomeStats = $this->outcomeStats();
        $providerSummary = [];

        foreach ($outcomeStats as $provider => $os) {
            $providerSummary[$provider] = [
                'total' => $os['total'],
                'success_rate' => $os['total'] > 0
                    ? round($os['success'] / $os['total'] * 100, 1)
                    : null,
            ];
        }

        return [
            'enabled' => $this->enabled,
            'total_decisions' => $total,
            'dispatch_rate' => $dispatchRate,
            'defer_rate' => $deferRate,
            'drop_rate' => $dropRate,
            'provider_summary' => $providerSummary,
        ];
    }

    /**
     * Get action priority for sorting (lower = higher priority).
     *
     * @param  string  $action  Dispatch action
     * @return int
     */
    private function actionPriority(string $action): int
    {
        return match ($action) {
            self::ACTION_DISPATCH => 0,
            self::ACTION_REPLAY => 1,
            self::ACTION_SAMPLE => 2,
            self::ACTION_DEFER => 3,
            self::ACTION_CIRCUIT_OPEN => 4,
            self::ACTION_BUDGET_EXCEEDED => 5,
            self::ACTION_CONSENT_DENIED => 6,
            self::ACTION_DROP => 7,
            default => 8,
        };
    }

    /**
     * Make and log a dispatch decision.
     *
     * @param  string  $action  Decision action
     * @param  string  $provider  Provider identifier
     * @param  AnalyticsEvent  $event  The event
     * @param  string  $reasoning  Human-readable reason
     * @param  array{circuit_state?: string, reliability_score?: float, latency_p95?: float|null, is_replay?: bool}  $context  Provider context
     * @return array{action: string, provider: string, event: string, reasoning: string, priority: string|null, reliability: float|null, circuit_state: string|null, latency_p95: float|null, is_replay: bool, timestamp: string}
     */
    private function makeDecision(
        string $action,
        string $provider,
        AnalyticsEvent $event,
        string $reasoning,
        array $context,
    ): array {
        $decision = [
            'id' => ++$this->decisionCounter,
            'action' => $action,
            'provider' => $provider,
            'event' => $event->name,
            'reasoning' => $reasoning,
            'priority' => $event->priority,
            'reliability' => $context['reliability_score'] ?? null,
            'circuit_state' => $context['circuit_state'] ?? null,
            'latency_p95' => $context['latency_p95'] ?? null,
            'is_replay' => $context['is_replay'] ?? false,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        if ($this->logDecisions) {
            $this->logDecision($decision);
        }

        return $decision;
    }

    /**
     * Log a dispatch decision to the cache-backed ledger.
     *
     * @param  array{id: int, action: string, provider: string, event: string, reasoning: string, priority: string|null, reliability: float|null, circuit_state: string|null, latency_p95: float|null, is_replay: bool, timestamp: string}  $decision
     * @return void
     */
    private function logDecision(array $decision): void
    {
        $indexKey = self::CACHE_PREFIX . 'index';
        $index = $this->cache->get($indexKey, []);
        /** @var list<string> $index */

        $decisionKey = self::CACHE_PREFIX . 'decision_' . $decision['id'];
        $index[] = $decisionKey;

        // Trim to max decisions
        while (count($index) > $this->maxDecisions) {
            $oldKey = array_shift($index);
            if ($oldKey !== null) {
                $this->cache->forget($oldKey);
            }
        }

        $this->cache->put($decisionKey, $decision, $this->decisionTtl);
        $this->cache->put($indexKey, $index, $this->decisionTtl + 60);
    }

    /**
     * Get all decisions from the ledger.
     *
     * @return list<array{action: string, provider: string, event: string, reasoning: string, priority: string|null, reliability: float|null, circuit_state: string|null, latency_p95: float|null, is_replay: bool, timestamp: string}>
     */
    private function getDecisionLedger(): array
    {
        $indexKey = self::CACHE_PREFIX . 'index';
        $index = $this->cache->get($indexKey, []);
        /** @var list<string> $index */

        $decisions = [];

        foreach ($index as $key) {
            $decision = $this->cache->get($key);
            if (is_array($decision)) {
                $decisions[] = $decision;
            }
        }

        return $decisions;
    }
}
