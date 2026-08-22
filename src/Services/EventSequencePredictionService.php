<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Event Sequence Prediction Service — Markov chain-based next-event prediction.
 *
 * Builds first-order and second-order Markov transition matrices from observed
 * user event sequences and predicts the most likely next event(s) in a session.
 *
 * Use cases:
 * - **Proactive instrumentation**: Anticipate which events users will trigger next
 * - **Funnel optimization**: Identify where users diverge from expected sequences
 * - **Smart suggestions**: Surface relevant features before users ask
 * - **Anomaly detection**: Flag unexpected sequence transitions
 * - **Onboarding guidance**: Predict and suggest next onboarding steps
 *
 * Markov chain model:
 * - P(X_{n+1} | X_n) for first-order transitions
 * - P(X_{n+1} | X_n, X_{n-1}) for second-order (bigram) transitions
 * - Transition probabilities estimated from observed event sequences in cache
 * - Minimum observation threshold before predictions are considered reliable
 *
 * Configuration: `zeroboiler.analytics.sequence_prediction`
 *
 * @since 86.0.0
 */
final class EventSequencePredictionService
{
    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'zb_seq_pred_';

    /** @var int Default minimum observations for reliable predictions */
    private const DEFAULT_MIN_OBSERVATIONS = 10;

    /** @var int Default number of predictions to return */
    private const DEFAULT_TOP_N = 5;

    /** @var float Default confidence threshold for returning predictions */
    private const DEFAULT_CONFIDENCE_THRESHOLD = 0.05;

    private CacheRepository $cache;

    private int $cacheTtl;

    private int $minObservations;

    private int $topN;

    private float $confidenceThreshold;

    private bool $enabled;

    private bool $useSecondOrder;

    /** @var list<string> Events to exclude from prediction context */
    private array $excludedEvents;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $predConfig = $config->get('zeroboiler.analytics.sequence_prediction', []);
        /** @var array{enabled?: bool, cache_ttl?: int, min_observations?: int, top_n?: int, confidence_threshold?: float, use_second_order?: bool, excluded_events?: list<string>} $predConfig */

        $this->enabled = (bool) ($predConfig['enabled'] ?? false);
        $this->cacheTtl = (int) ($predConfig['cache_ttl'] ?? 3600);
        $this->minObservations = (int) ($predConfig['min_observations'] ?? self::DEFAULT_MIN_OBSERVATIONS);
        $this->topN = (int) ($predConfig['top_n'] ?? self::DEFAULT_TOP_N);
        $this->confidenceThreshold = (float) ($predConfig['confidence_threshold'] ?? self::DEFAULT_CONFIDENCE_THRESHOLD);
        $this->useSecondOrder = (bool) ($predConfig['use_second_order'] ?? true);
        $this->excludedEvents = (array) ($predConfig['excluded_events'] ?? ['page_view', 'scroll_depth', 'session_start']);
    }

    /**
     * Record an observed event sequence for model training.
     *
     * Updates first-order and second-order transition matrices in cache.
     *
     * @param  string  $clientId  Client tracking ID
     * @param  list<string>  $sequence  Ordered event names observed in session
     * @return array{recorded: bool, transitions_updated: int, sequence_length: int}
     */
    public function recordSequence(string $clientId, array $sequence): array
    {
        if (!$this->enabled || count($sequence) < 2) {
            return ['recorded' => false, 'transitions_updated' => 0, 'sequence_length' => count($sequence)];
        }

        $filtered = array_values(array_filter($sequence, fn (string $e): bool => !in_array($e, $this->excludedEvents, true)));
        $transitionsUpdated = 0;

        // Update first-order transitions: X_n → X_{n+1}
        for ($i = 0; $i < count($filtered) - 1; $i++) {
            $from = $filtered[$i];
            $to = $filtered[$i + 1];
            $this->incrementTransition($from, $to);
            $transitionsUpdated++;
        }

        // Update second-order transitions: (X_{n-1}, X_n) → X_{n+1}
        if ($this->useSecondOrder) {
            for ($i = 0; $i < count($filtered) - 2; $i++) {
                $from1 = $filtered[$i];
                $from2 = $filtered[$i + 1];
                $to = $filtered[$i + 2];
                $this->incrementSecondOrderTransition($from1, $from2, $to);
                $transitionsUpdated++;
            }
        }

        // Track total observations per source event
        $this->incrementSourceCount($filtered[0]);

        return [
            'recorded' => true,
            'transitions_updated' => $transitionsUpdated,
            'sequence_length' => count($sequence),
        ];
    }

    /**
     * Predict the most likely next event(s) given a recent sequence.
     *
     * Uses second-order Markov model if available and enabled,
     * falling back to first-order for predictions.
     *
     * @param  list<string>  $recentEvents  Recent event names (last N events in session)
     * @return list<array{event: string, probability: float, confidence: string, model: string}>
     */
    public function predictNext(array $recentEvents): array
    {
        if (!$this->enabled || empty($recentEvents)) {
            return [];
        }

        $filtered = array_values(array_filter($recentEvents, fn (string $e): bool => !in_array($e, $this->excludedEvents, true)));

        if (empty($filtered)) {
            return [];
        }

        // Try second-order first (if enabled and we have 2+ events)
        if ($this->useSecondOrder && count($filtered) >= 2) {
            $n = count($filtered);
            $from1 = $filtered[$n - 2];
            $from2 = $filtered[$n - 1];
            $secondOrderPreds = $this->getSecondOrderPredictions($from1, $from2);

            if (!empty($secondOrderPreds)) {
                return array_slice($secondOrderPreds, 0, $this->topN);
            }
        }

        // Fall back to first-order
        $lastEvent = $filtered[count($filtered) - 1];
        $firstOrderPreds = $this->getFirstOrderPredictions($lastEvent);

        return array_slice($firstOrderPreds, 0, $this->topN);
    }

    /**
     * Get the full transition matrix for a source event.
     *
     * @param  string  $fromEvent  Source event name
     * @return array{from: string, total_transitions: int, transitions: array<string, array{to: string, count: int, probability: float}>}
     */
    public function getTransitionMatrix(string $fromEvent): array
    {
        $cacheKey = self::CACHE_PREFIX . 'fo_' . md5($fromEvent);
        /** @var array<string, int>|null $matrix */
        $matrix = $this->cache->get($cacheKey);

        if ($matrix === null) {
            return ['from' => $fromEvent, 'total_transitions' => 0, 'transitions' => []];
        }

        $total = array_sum($matrix);
        $transitions = [];

        foreach ($matrix as $toEvent => $count) {
            $transitions[$toEvent] = [
                'to' => $toEvent,
                'count' => $count,
                'probability' => $total > 0 ? round($count / $total, 6) : 0.0,
            ];
        }

        // Sort by probability descending
        uasort($transitions, fn (array $a, array $b): int => $b['probability'] <=> $a['probability']);

        return ['from' => $fromEvent, 'total_transitions' => $total, 'transitions' => $transitions];
    }

    /**
     * Get prediction model statistics.
     *
     * @return array{enabled: bool, model: string, min_observations: int, confidence_threshold: float, first_order_events: int, second_order_events: int, total_sequences_recorded: int}
     */
    public function getStats(): array
    {
        $foKey = self::CACHE_PREFIX . 'fo_index';
        $soKey = self::CACHE_PREFIX . 'so_index';
        $seqKey = self::CACHE_PREFIX . 'sequences_recorded';

        /** @var list<string>|null $foIndex */
        $foIndex = $this->cache->get($foKey);
        /** @var list<string>|null $soIndex */
        $soIndex = $this->cache->get($soKey);

        return [
            'enabled' => $this->enabled,
            'model' => $this->useSecondOrder ? 'mixed (first+second order)' : 'first-order Markov',
            'min_observations' => $this->minObservations,
            'confidence_threshold' => $this->confidenceThreshold,
            'first_order_events' => count($foIndex ?? []),
            'second_order_events' => count($soIndex ?? []),
            'total_sequences_recorded' => (int) ($this->cache->get($seqKey) ?? 0),
        ];
    }

    /**
     * Clear all prediction model data from cache.
     *
     * @return array{cleared: bool}
     */
    public function clearModel(): array
    {
        $foKey = self::CACHE_PREFIX . 'fo_index';
        $soKey = self::CACHE_PREFIX . 'so_index';
        $seqKey = self::CACHE_PREFIX . 'sequences_recorded';

        /** @var list<string>|null $foIndex */
        $foIndex = $this->cache->get($foKey);
        /** @var list<string>|null $soIndex */
        $soIndex = $this->cache->get($soKey);

        $keysToDelete = [$foKey, $soKey, $seqKey];

        foreach ($foIndex ?? [] as $event) {
            $keysToDelete[] = self::CACHE_PREFIX . 'fo_' . md5($event);
        }

        foreach ($soIndex ?? [] as $key) {
            $keysToDelete[] = self::CACHE_PREFIX . 'so_' . md5($key);
        }

        foreach ($keysToDelete as $key) {
            $this->cache->delete($key);
        }

        return ['cleared' => true];
    }

    /**
     * Get the most common event sequences (top N).
     *
     * @param  int  $limit  Number of sequences to return
     * @return list<array{sequence: list<string>, count: int}>
     */
    public function getTopSequences(int $limit = 10): array
    {
        $foKey = self::CACHE_PREFIX . 'fo_index';
        /** @var list<string>|null $foIndex */
        $foIndex = $this->cache->get($foKey);

        if ($foIndex === null || empty($foIndex)) {
            return [];
        }

        $topTransitions = [];
        foreach (array_slice($foIndex, 0, 20) as $fromEvent) {
            $matrix = $this->getTransitionMatrix($fromEvent);
            foreach ($matrix['transitions'] as $toEvent => $data) {
                $topTransitions[] = [
                    'sequence' => [$fromEvent, $toEvent],
                    'count' => $data['count'],
                ];
            }
        }

        usort($topTransitions, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($topTransitions, 0, $limit);
    }

    /**
     * Detect anomalous event transitions in a session.
     *
     * Compares actual transitions against the Markov model and flags
     * transitions with unexpectedly low probability.
     *
     * @param  list<string>  $sequence  Full event sequence to analyze
     * @param  float  $anomalyThreshold  Probability below which a transition is considered anomalous (default: 0.01)
     * @return list<array{from: string, to: string, expected_probability: float|null, is_anomaly: bool, reason: string}>
     */
    public function detectAnomalies(array $sequence, float $anomalyThreshold = 0.01): array
    {
        if (!$this->enabled || count($sequence) < 2) {
            return [];
        }

        $filtered = array_values(array_filter($sequence, fn (string $e): bool => !in_array($e, $this->excludedEvents, true)));
        $anomalies = [];

        for ($i = 0; $i < count($filtered) - 1; $i++) {
            $from = $filtered[$i];
            $to = $filtered[$i + 1];
            $matrix = $this->getTransitionMatrix($from);

            $transition = $matrix['transitions'][$to] ?? null;
            $probability = $transition['probability'] ?? null;

            if ($probability === null) {
                // Never seen this transition before
                if ($matrix['total_transitions'] >= $this->minObservations) {
                    $anomalies[] = [
                        'from' => $from,
                        'to' => $to,
                        'expected_probability' => null,
                        'is_anomaly' => true,
                        'reason' => 'unseen_transition',
                    ];
                }
            } elseif ($probability < $anomalyThreshold && $matrix['total_transitions'] >= $this->minObservations) {
                $anomalies[] = [
                    'from' => $from,
                    'to' => $to,
                    'expected_probability' => $probability,
                    'is_anomaly' => true,
                    'reason' => 'low_probability',
                ];
            }
        }

        return $anomalies;
    }

    // ── Private Helpers ────────────────────────────────────────────────

    /**
     * Increment a first-order transition count.
     */
    private function incrementTransition(string $from, string $to): void
    {
        $cacheKey = self::CACHE_PREFIX . 'fo_' . md5($from);
        /** @var array<string, int> $matrix */
        $matrix = $this->cache->get($cacheKey) ?? [];

        $matrix[$to] = ($matrix[$to] ?? 0) + 1;

        $this->cache->set($cacheKey, $matrix, $this->cacheTtl);

        // Update index
        $this->addToIndex('fo_index', $from);
    }

    /**
     * Increment a second-order transition count.
     */
    private function incrementSecondOrderTransition(string $from1, string $from2, string $to): void
    {
        $key = $from1 . '|' . $from2;
        $cacheKey = self::CACHE_PREFIX . 'so_' . md5($key);
        /** @var array<string, int> $matrix */
        $matrix = $this->cache->get($cacheKey) ?? [];

        $matrix[$to] = ($matrix[$to] ?? 0) + 1;

        $this->cache->set($cacheKey, $matrix, $this->cacheTtl);

        // Update index
        $this->addToIndex('so_index', $key);
    }

    /**
     * Track total source event observations.
     */
    private function incrementSourceCount(string $event): void
    {
        $seqKey = self::CACHE_PREFIX . 'sequences_recorded';
        $this->cache->increment($seqKey);
        $this->cache->set($seqKey, (int) ($this->cache->get($seqKey) ?? 0) + 1, $this->cacheTtl * 24);
    }

    /**
     * Add an event to the tracking index.
     */
    private function addToIndex(string $indexKey, string $event): void
    {
        $cacheKey = self::CACHE_PREFIX . $indexKey;
        /** @var list<string> $index */
        $index = $this->cache->get($cacheKey) ?? [];

        if (!in_array($event, $index, true)) {
            $index[] = $event;
            $this->cache->set($cacheKey, $index, $this->cacheTtl * 24);
        }
    }

    /**
     * Get first-order predictions for a given event.
     *
     * @return list<array{event: string, probability: float, confidence: string, model: string}>
     */
    private function getFirstOrderPredictions(string $fromEvent): array
    {
        $matrix = $this->getTransitionMatrix($fromEvent);

        if ($matrix['total_transitions'] < $this->minObservations) {
            return [];
        }

        $predictions = [];
        foreach ($matrix['transitions'] as $toEvent => $data) {
            if ($data['probability'] >= $this->confidenceThreshold) {
                $predictions[] = [
                    'event' => $toEvent,
                    'probability' => $data['probability'],
                    'confidence' => $this->classifyConfidence($data['probability'], $matrix['total_transitions']),
                    'model' => 'first-order',
                ];
            }
        }

        usort($predictions, fn (array $a, array $b): int => $b['probability'] <=> $a['probability']);

        return $predictions;
    }

    /**
     * Get second-order predictions for a given event pair.
     *
     * @return list<array{event: string, probability: float, confidence: string, model: string}>
     */
    private function getSecondOrderPredictions(string $from1, string $from2): array
    {
        $key = $from1 . '|' . $from2;
        $cacheKey = self::CACHE_PREFIX . 'so_' . md5($key);
        /** @var array<string, int>|null $matrix */
        $matrix = $this->cache->get($cacheKey);

        if ($matrix === null) {
            return [];
        }

        $total = array_sum($matrix);

        if ($total < $this->minObservations) {
            return [];
        }

        $predictions = [];
        foreach ($matrix as $toEvent => $count) {
            $probability = round($count / $total, 6);
            if ($probability >= $this->confidenceThreshold) {
                $predictions[] = [
                    'event' => $toEvent,
                    'probability' => $probability,
                    'confidence' => $this->classifyConfidence($probability, $total),
                    'model' => 'second-order',
                ];
            }
        }

        usort($predictions, fn (array $a, array $b): int => $b['probability'] <=> $a['probability']);

        return $predictions;
    }

    /**
     * Classify prediction confidence based on probability and sample size.
     *
     * @return string 'high'|'medium'|'low'
     */
    private function classifyConfidence(float $probability, int $totalSamples): string
    {
        if ($probability >= 0.5 && $totalSamples >= 100) {
            return 'high';
        }

        if ($probability >= 0.2 && $totalSamples >= 50) {
            return 'medium';
        }

        return 'low';
    }
}
