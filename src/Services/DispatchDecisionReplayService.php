<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Dispatch Decision Replay Service — audit trail analyzer for orchestrator decisions.
 *
 * Reads the dispatch decision ledger written by EventDispatchOrchestrator
 * and provides analysis, filtering, and debugging capabilities. Enables
 * operations teams to:
 *
 * - **Replay decisions**: Re-execute past dispatch decisions with current
 *   configuration to see if outcomes would differ.
 * - **Decision analysis**: Aggregate decisions by action type, provider,
 *   event name, and reasoning to identify patterns.
 * - **Debug dropped events**: Investigate why specific events were dropped,
 *   deferred, or had circuits open.
 * - **Decision trends**: Track how dispatch decisions change over time
 *   (e.g. increasing drop rate signals provider degradation).
 *
 * This service is read-only — it does not modify the decision ledger.
 * The ledger is written by EventDispatchOrchestrator.
 *
 * @see \ZeroBoiler\Analytics\Services\EventDispatchOrchestrator
 * @see \ZeroBoiler\Analytics\Services\EventDispatchLatencyTracker
 *
 * @since 245.0.0
 */
final class DispatchDecisionReplayService
{
    /** @var string Cache key for the orchestrator decision ledger */
    private const LEDGER_KEY = 'zb_orchestrator_decisions';

    /**
     * Decision record from the orchestrator ledger.
     *
     * @phpstan-type DecisionRecord array{id: string, event: string, provider: string, action: string, reasoning: string, latency_estimate?: float|null, priority?: string|null, timestamp: float}
     */
    /**
     * Decision analysis summary.
     *
     * @phpstan-type DecisionAnalysis array{total: int, by_action: array<string, int>, by_provider: array<string, int>, drop_rate: float, defer_rate: float, dispatch_rate: float, circuit_open_count: int, consent_denied_count: int, budget_exceeded_count: int, top_dropped_events: list<array{event: string, count: int}>, top_dropped_providers: list<array{provider: string, count: int}>}
     */

    private CacheRepository $cache;

    private bool $enabled;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->cache = $cache;

        $orchConfig = $config->get('zeroboiler.analytics.dispatch_orchestrator', []);
        /** @var array{enabled?: bool} $orchConfig */

        $this->enabled = (bool) ($orchConfig['enabled'] ?? true);
    }

    // ── Ledger Access ───────────────────────────────────────────────

    /**
     * Get all decisions from the orchestrator ledger.
     *
     * @return list<DecisionRecord>
     */
    public function ledger(): array
    {
        $decisions = $this->cache->get(self::LEDGER_KEY);

        return is_array($decisions) ? $decisions : [];
    }

    /**
     * Get recent decisions, optionally filtered.
     *
     * @param  int  $limit  Maximum decisions to return
     * @param  string|null  $action  Filter by action type (dispatch|defer|drop|...)
     * @param  string|null  $provider  Filter by provider
     * @param  string|null  $event  Filter by event name
     * @return list<DecisionRecord>
     */
    public function recentDecisions(
        int $limit = 50,
        ?string $action = null,
        ?string $provider = null,
        ?string $event = null,
    ): array {
        $decisions = $this->ledger();

        $filtered = array_filter(
            $decisions,
            static function (array $d) use ($action, $provider, $event): bool {
                if ($action !== null && ($d['action'] ?? '') !== $action) {
                    return false;
                }
                if ($provider !== null && ($d['provider'] ?? '') !== $provider) {
                    return false;
                }
                if ($event !== null && ($d['event'] ?? '') !== $event) {
                    return false;
                }

                return true;
            },
        );

        return array_values(array_slice($filtered, -$limit));
    }

    /**
     * Get a single decision by its ID.
     *
     * @param  string  $id  Decision ID
     * @return DecisionRecord|null
     */
    public function getDecision(string $id): ?array
    {
        foreach ($this->ledger() as $decision) {
            if (($decision['id'] ?? '') === $id) {
                return $decision;
            }
        }

        return null;
    }

    // ── Decision Analysis ───────────────────────────────────────────

    /**
     * Analyze dispatch decisions for patterns and insights.
     *
     * Aggregates decisions by action type, provider, event, and
     * reasoning to produce an operational summary.
     *
     * @return DecisionAnalysis
     */
    public function analyze(): array
    {
        $decisions = $this->ledger();
        $total = count($decisions);

        if ($total === 0) {
            return $this->emptyAnalysis();
        }

        $byAction = [];
        $byProvider = [];
        $droppedEvents = [];
        $droppedProviders = [];
        $circuitOpen = 0;
        $consentDenied = 0;
        $budgetExceeded = 0;

        foreach ($decisions as $d) {
            $action = $d['action'] ?? 'unknown';
            $prov = $d['provider'] ?? 'unknown';
            $evt = $d['event'] ?? 'unknown';

            $byAction[$action] = ($byAction[$action] ?? 0) + 1;
            $byProvider[$prov] = ($byProvider[$prov] ?? 0) + 1;

            if ($action === 'drop') {
                $droppedEvents[$evt] = ($droppedEvents[$evt] ?? 0) + 1;
                $droppedProviders[$prov] = ($droppedProviders[$prov] ?? 0) + 1;
            }

            if ($action === 'circuit_open') {
                $circuitOpen++;
            }
            if ($action === 'consent_denied') {
                $consentDenied++;
            }
            if ($action === 'budget_exceeded') {
                $budgetExceeded++;
            }
        }

        arsort($droppedEvents);
        arsort($droppedProviders);

        $topDroppedEvents = [];
        foreach (array_slice($droppedEvents, 0, 10, true) as $event => $count) {
            $topDroppedEvents[] = ['event' => $event, 'count' => $count];
        }

        $topDroppedProviders = [];
        foreach (array_slice($droppedProviders, 0, 10, true) as $provider => $count) {
            $topDroppedProviders[] = ['provider' => $provider, 'count' => $count];
        }

        $dispatchCount = $byAction['dispatch'] ?? 0;
        $dropCount = $byAction['drop'] ?? 0;
        $deferCount = $byAction['defer'] ?? 0;

        return [
            'total' => $total,
            'by_action' => $byAction,
            'by_provider' => $byProvider,
            'drop_rate' => $total > 0 ? round(($dropCount / $total) * 100.0, 2) : 0.0,
            'defer_rate' => $total > 0 ? round(($deferCount / $total) * 100.0, 2) : 0.0,
            'dispatch_rate' => $total > 0 ? round(($dispatchCount / $total) * 100.0, 2) : 0.0,
            'circuit_open_count' => $circuitOpen,
            'consent_denied_count' => $consentDenied,
            'budget_exceeded_count' => $budgetExceeded,
            'top_dropped_events' => $topDroppedEvents,
            'top_dropped_providers' => $topDroppedProviders,
        ];
    }

    /**
     * Analyze decisions for a specific time window.
     *
     * @param  float  $from  Start timestamp (microtime)
     * @param  float  $to  End timestamp (microtime)
     * @return DecisionAnalysis
     */
    public function analyzeWindow(float $from, float $to): array
    {
        $allDecisions = $this->ledger();

        $windowed = array_values(array_filter(
            $allDecisions,
            static fn (array $d): bool => ($d['timestamp'] ?? 0) >= $from && ($d['timestamp'] ?? 0) <= $to,
        ));

        if ($windowed === []) {
            return $this->emptyAnalysis();
        }

        return $this->analyzeDecisions($windowed);
    }

    // ── Decision Debug ──────────────────────────────────────────────

    /**
     * Get decisions that resulted in event drops.
     *
     * Useful for investigating why events were not delivered.
     *
     * @param  int  $limit  Maximum entries
     * @return list<DecisionRecord>
     */
    public function droppedDecisions(int $limit = 50): array
    {
        return $this->recentDecisions($limit, action: 'drop');
    }

    /**
     * Get decisions that hit circuit breaker open state.
     *
     * @param  int  $limit  Maximum entries
     * @return list<DecisionRecord>
     */
    public function circuitOpenDecisions(int $limit = 50): array
    {
        return $this->recentDecisions($limit, action: 'circuit_open');
    }

    /**
     * Get decisions that were blocked by consent.
     *
     * @param  int  $limit  Maximum entries
     * @return list<DecisionRecord>
     */
    public function consentDeniedDecisions(int $limit = 50): array
    {
        return $this->recentDecisions($limit, action: 'consent_denied');
    }

    /**
     * Get decisions that exceeded budget.
     *
     * @param  int  $limit  Maximum entries
     * @return list<DecisionRecord>
     */
    public function budgetExceededDecisions(int $limit = 50): array
    {
        return $this->recentDecisions($limit, action: 'budget_exceeded');
    }

    /**
     * Debug a specific event's dispatch journey across all providers.
     *
     * Returns all decisions for a given event name across all providers.
     *
     * @param  string  $eventName  Event name to debug
     * @return list<DecisionRecord>
     */
    public function debugEvent(string $eventName): array
    {
        return $this->recentDecisions(limit: 100, event: $eventName);
    }

    /**
     * Debug a specific provider's dispatch decisions.
     *
     * @param  string  $provider  Provider identifier
     * @return list<DecisionRecord>
     */
    public function debugProvider(string $provider): array
    {
        return $this->recentDecisions(limit: 100, provider: $provider);
    }

    // ── Decision Trends ─────────────────────────────────────────────

    /**
     * Compute dispatch decision trends by comparing two time windows.
     *
     * Returns before/after comparison for drop rate, defer rate,
     * dispatch rate, and per-action counts.
     *
     * @param  float  $window1From  First window start
     * @param  float  $window1To  First window end
     * @param  float  $window2From  Second window start
     * @param  float  $window2To  Second window end
     * @return array{window1: DecisionAnalysis, window2: DecisionAnalysis, delta: array{drop_rate: float, defer_rate: float, dispatch_rate: float, total: int}}
     */
    public function compareWindows(
        float $window1From,
        float $window1To,
        float $window2From,
        float $window2To,
    ): array {
        $w1 = $this->analyzeWindow($window1From, $window1To);
        $w2 = $this->analyzeWindow($window2From, $window2To);

        return [
            'window1' => $w1,
            'window2' => $w2,
            'delta' => [
                'drop_rate' => round($w2['drop_rate'] - $w1['drop_rate'], 2),
                'defer_rate' => round($w2['defer_rate'] - $w1['defer_rate'], 2),
                'dispatch_rate' => round($w2['dispatch_rate'] - $w1['dispatch_rate'], 2),
                'total' => $w2['total'] - $w1['total'],
            ],
        ];
    }

    // ── Decision Reasoning Breakdown ────────────────────────────────

    /**
     * Get a breakdown of decision reasoning strings.
     *
     * Useful for understanding why events are being dropped or deferred.
     * Groups similar reasoning strings and counts occurrences.
     *
     * @return list<array{reasoning: string, count: int, action: string, example_event: string|null}>
     */
    public function reasoningBreakdown(int $limit = 20): array
    {
        $decisions = $this->ledger();
        $reasoningCounts = [];

        foreach ($decisions as $d) {
            $reasoning = $d['reasoning'] ?? 'no_reasoning';
            $action = $d['action'] ?? 'unknown';
            $event = $d['event'] ?? null;
            $key = $action . ':' . $reasoning;

            if (! isset($reasoningCounts[$key])) {
                $reasoningCounts[$key] = [
                    'reasoning' => $reasoning,
                    'count' => 0,
                    'action' => $action,
                    'example_event' => $event,
                ];
            }

            $reasoningCounts[$key]['count']++;
            if ($reasoningCounts[$key]['example_event'] === null && $event !== null) {
                $reasoningCounts[$key]['example_event'] = $event;
            }
        }

        usort($reasoningCounts, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice(array_values($reasoningCounts), 0, $limit);
    }

    // ── Summary ─────────────────────────────────────────────────────

    /**
     * Get a quick summary for dashboards.
     *
     * @return array{enabled: bool, total_decisions: int, drop_rate: float, dispatch_rate: float, deferred: int, circuit_opens: int, consent_denied: int, budget_exceeded: int, providers_affected: int}
     */
    public function summary(): array
    {
        $analysis = $this->analyze();

        return [
            'enabled' => $this->enabled,
            'total_decisions' => $analysis['total'],
            'drop_rate' => $analysis['drop_rate'],
            'dispatch_rate' => $analysis['dispatch_rate'],
            'deferred' => $analysis['by_action']['defer'] ?? 0,
            'circuit_opens' => $analysis['circuit_open_count'],
            'consent_denied' => $analysis['consent_denied_count'],
            'budget_exceeded' => $analysis['budget_exceeded_count'],
            'providers_affected' => count($analysis['by_provider']),
        ];
    }

    /**
     * Check if the replay service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    // ── Internal Helpers ─────────────────────────────────────────────

    /**
     * @return DecisionAnalysis
     */
    private function emptyAnalysis(): array
    {
        return [
            'total' => 0,
            'by_action' => [],
            'by_provider' => [],
            'drop_rate' => 0.0,
            'defer_rate' => 0.0,
            'dispatch_rate' => 0.0,
            'circuit_open_count' => 0,
            'consent_denied_count' => 0,
            'budget_exceeded_count' => 0,
            'top_dropped_events' => [],
            'top_dropped_providers' => [],
        ];
    }

    /**
     * Analyze a set of pre-filtered decisions.
     *
     * @param  list<DecisionRecord>  $decisions  Pre-filtered decisions
     * @return DecisionAnalysis
     */
    private function analyzeDecisions(array $decisions): array
    {
        $total = count($decisions);

        if ($total === 0) {
            return $this->emptyAnalysis();
        }

        $byAction = [];
        $byProvider = [];
        $droppedEvents = [];
        $droppedProviders = [];
        $circuitOpen = 0;
        $consentDenied = 0;
        $budgetExceeded = 0;

        foreach ($decisions as $d) {
            $action = $d['action'] ?? 'unknown';
            $prov = $d['provider'] ?? 'unknown';
            $evt = $d['event'] ?? 'unknown';

            $byAction[$action] = ($byAction[$action] ?? 0) + 1;
            $byProvider[$prov] = ($byProvider[$prov] ?? 0) + 1;

            if ($action === 'drop') {
                $droppedEvents[$evt] = ($droppedEvents[$evt] ?? 0) + 1;
                $droppedProviders[$prov] = ($droppedProviders[$prov] ?? 0) + 1;
            }

            if ($action === 'circuit_open') {
                $circuitOpen++;
            }
            if ($action === 'consent_denied') {
                $consentDenied++;
            }
            if ($action === 'budget_exceeded') {
                $budgetExceeded++;
            }
        }

        arsort($droppedEvents);
        arsort($droppedProviders);

        $topDroppedEvents = [];
        foreach (array_slice($droppedEvents, 0, 10, true) as $event => $count) {
            $topDroppedEvents[] = ['event' => $event, 'count' => $count];
        }

        $topDroppedProviders = [];
        foreach (array_slice($droppedProviders, 0, 10, true) as $provider => $count) {
            $topDroppedProviders[] = ['provider' => $provider, 'count' => $count];
        }

        $dispatchCount = $byAction['dispatch'] ?? 0;
        $dropCount = $byAction['drop'] ?? 0;
        $deferCount = $byAction['defer'] ?? 0;

        return [
            'total' => $total,
            'by_action' => $byAction,
            'by_provider' => $byProvider,
            'drop_rate' => $total > 0 ? round(($dropCount / $total) * 100.0, 2) : 0.0,
            'defer_rate' => $total > 0 ? round(($deferCount / $total) * 100.0, 2) : 0.0,
            'dispatch_rate' => $total > 0 ? round(($dispatchCount / $total) * 100.0, 2) : 0.0,
            'circuit_open_count' => $circuitOpen,
            'consent_denied_count' => $consentDenied,
            'budget_exceeded_count' => $budgetExceeded,
            'top_dropped_events' => $topDroppedEvents,
            'top_dropped_providers' => $topDroppedProviders,
        ];
    }
}
