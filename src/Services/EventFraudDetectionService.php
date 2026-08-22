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
 * Event fraud detection service.
 *
 * Detects suspicious analytics event patterns including:
 * - Volume anomalies (burst detection per client ID)
 * - Velocity abuse (event frequency exceeding thresholds)
 * - Duplicate injection (rapid replay of identical events)
 * - Parameter injection (suspicious parameter patterns)
 * - Spoofed identity (multiple client IDs from same fingerprint)
 *
 * Each detection signal contributes to a composite fraud score (0.0–1.0).
 * Events exceeding the quarantine threshold are flagged but not dropped.
 * Events exceeding the block threshold are dropped silently.
 *
 * Inspired by Segment's Sentry integration, PostHog's event spam detection,
 * and Cloudflare bot management patterns.
 *
 * @since 47.0.0
 */
final class EventFraudDetectionService
{
    /** @var array<string, mixed> */
    private array $config;

    private string $cachePrefix;

    private int $metricsTtl;

    private int $velocityWindowSeconds;

    private int $maxEventsPerWindow;

    private float $quarantineThreshold;

    private float $blockThreshold;

    private float $burstMultiplier;

    private int $burstWindowSeconds;

    private int $duplicateWindowSeconds;

    private int $maxDuplicateHashPerWindow;

    /** @var list<string> */
    private array $suspiciousPatterns;

    /** @var list<string> */
    private array $criticalEvents;

    private int $spoofedIdentityWindowSeconds;

    private int $maxFingerprintsPerClient;

    /**
     * @param  CacheRepository  $cache  Cache repository for rate limiting and metrics
     */
    public function __construct(
        private readonly CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->config = $config->get('zeroboiler.analytics.fraud_detection', []);
        $this->cachePrefix = (string) ($this->config['cache_prefix'] ?? 'zb_fraud_');
        $this->metricsTtl = (int) ($this->config['metrics_ttl'] ?? 3600);
        $this->velocityWindowSeconds = (int) ($this->config['velocity_window'] ?? 60);
        $this->maxEventsPerWindow = (int) ($this->config['max_events_per_window'] ?? 200);
        $this->quarantineThreshold = (float) ($this->config['quarantine_threshold'] ?? 0.6);
        $this->blockThreshold = (float) ($this->config['block_threshold'] ?? 0.85);
        $this->burstMultiplier = (float) ($this->config['burst_multiplier'] ?? 5.0);
        $this->burstWindowSeconds = (int) ($this->config['burst_window'] ?? 10);
        $this->duplicateWindowSeconds = (int) ($this->config['duplicate_window'] ?? 5);
        $this->maxDuplicateHashPerWindow = (int) ($this->config['max_duplicate_hash_per_window'] ?? 10);
        $this->suspiciousPatterns = (array) ($this->config['suspicious_patterns'] ?? ['<script', 'javascript:', 'data:', 'onerror=']);
        $this->criticalEvents = (array) ($this->config['critical_events'] ?? ['purchase', 'subscription_created', 'payment_succeeded']);
        $this->spoofedIdentityWindowSeconds = (int) ($this->config['spoofed_identity_window'] ?? 3600);
        $this->maxFingerprintsPerClient = (int) ($this->config['max_fingerprints_per_client'] ?? 5);
    }

    /**
     * Evaluate an event for fraud signals.
     *
     * Returns a fraud analysis with individual signal scores and composite score.
     * The event is NOT blocked here — use shouldBlock() to check the final decision.
     *
     * @param  AnalyticsEvent  $event  The event to evaluate
     * @param  string|null  $clientId  Client identifier (from cookie)
     * @param  string|null  $fingerprint  Browser fingerprint hash (optional)
     * @return array{score: float, signals: array<string, array{name: string, score: float, triggered: bool}>, action: 'pass'|'quarantine'|'block', reason: string|null}
     */
    public function evaluate(AnalyticsEvent $event, ?string $clientId = null, ?string $fingerprint = null): array
    {
        $signals = [];

        $signals['velocity'] = $this->checkVelocity($event, $clientId);
        $signals['burst'] = $this->checkBurst($event, $clientId);
        $signals['duplicate'] = $this->checkDuplicate($event, $clientId);
        $signals['injection'] = $this->checkParameterInjection($event);
        $signals['spoofed_identity'] = $this->checkSpoofedIdentity($clientId, $fingerprint);

        $scores = array_column($signals, 'score');
        $maxScore = max($scores);
        $weightedScore = ($signals['velocity']['score'] * 0.25)
            + ($signals['burst']['score'] * 0.30)
            + ($signals['duplicate']['score'] * 0.20)
            + ($signals['injection']['score'] * 0.15)
            + ($signals['spoofed_identity']['score'] * 0.10);

        // Use weighted score but elevate if any single signal is critical
        $compositeScore = max($weightedScore, $maxScore * 0.8);

        $action = 'pass';
        $reason = null;

        if ($compositeScore >= $this->blockThreshold) {
            $action = 'block';
            $reason = $this->generateBlockReason($signals);
        } elseif ($compositeScore >= $this->quarantineThreshold) {
            $action = 'quarantine';
            $reason = $this->generateQuarantineReason($signals);
        }

        // Critical events: elevate any non-pass action to block
        if ($action === 'quarantine' && in_array($event->name, $this->criticalEvents, true)) {
            $action = 'block';
            $reason = 'Critical event (' . $event->name . ') with elevated fraud score — blocked for safety';
        }

        $this->recordMetrics($event->name, $compositeScore, $action);

        return [
            'score' => round($compositeScore, 4),
            'signals' => $signals,
            'action' => $action,
            'reason' => $reason,
        ];
    }

    /**
     * Check if an event should be blocked based on fraud evaluation.
     *
     * Convenience method for pipeline integration.
     *
     * @param  AnalyticsEvent  $event  The event to check
     * @param  string|null  $clientId  Client identifier
     * @param  string|null  $fingerprint  Browser fingerprint
     * @return bool True if the event should be blocked
     */
    public function shouldBlock(AnalyticsEvent $event, ?string $clientId = null, ?string $fingerprint = null): bool
    {
        $result = $this->evaluate($event, $clientId, $fingerprint);

        return $result['action'] === 'block';
    }

    /**
     * Check velocity — event count from a client within the time window.
     *
     * @param  AnalyticsEvent  $event  The event being checked
     * @param  string|null  $clientId  Client identifier
     * @return array{name: string, score: float, triggered: bool}
     */
    public function checkVelocity(AnalyticsEvent $event, ?string $clientId = null): array
    {
        if ($clientId === null) {
            return ['name' => 'velocity', 'score' => 0.0, 'triggered' => false];
        }

        $cacheKey = $this->cachePrefix . 'vel_' . md5($clientId . ':' . $event->name);
        $current = (int) $this->cache->get($cacheKey, 0);
        $this->cache->put($cacheKey, $current + 1, $this->velocityWindowSeconds);

        if ($current >= $this->maxEventsPerWindow) {
            $score = min(1.0, ($current / $this->maxEventsPerWindow));

            return ['name' => 'velocity', 'score' => $score, 'triggered' => true];
        }

        return ['name' => 'velocity', 'score' => 0.0, 'triggered' => false];
    }

    /**
     * Check burst — sudden spike in event volume within a short window.
     *
     * @param  AnalyticsEvent  $event  The event being checked
     * @param  string|null  $clientId  Client identifier
     * @return array{name: string, score: float, triggered: bool}
     */
    public function checkBurst(AnalyticsEvent $event, ?string $clientId = null): array
    {
        if ($clientId === null) {
            return ['name' => 'burst', 'score' => 0.0, 'triggered' => false];
        }

        $cacheKey = $this->cachePrefix . 'burst_' . md5($clientId . ':' . $event->name);
        $current = (int) $this->cache->get($cacheKey, 0);
        $this->cache->put($cacheKey, $current + 1, $this->burstWindowSeconds);

        $burstThreshold = (int) ceil($this->maxEventsPerWindow * $this->burstMultiplier / ($this->velocityWindowSeconds / $this->burstWindowSeconds));

        if ($burstThreshold > 0 && $current >= $burstThreshold) {
            $score = min(1.0, ($current / $burstThreshold) - 1.0);

            return ['name' => 'burst', 'score' => $score, 'triggered' => true];
        }

        return ['name' => 'burst', 'score' => 0.0, 'triggered' => false];
    }

    /**
     * Check duplicate — identical events replayed within a short window.
     *
     * @param  AnalyticsEvent  $event  The event being checked
     * @param  string|null  $clientId  Client identifier
     * @return array{name: string, score: float, triggered: bool}
     */
    public function checkDuplicate(AnalyticsEvent $event, ?string $clientId = null): array
    {
        $hash = md5($event->name . ':' . json_encode($event->params, JSON_THROW_ON_ERROR));
        $cacheKey = $this->cachePrefix . 'dup_' . $hash;
        $current = (int) $this->cache->get($cacheKey, 0);
        $this->cache->put($cacheKey, $current + 1, $this->duplicateWindowSeconds);

        if ($current >= $this->maxDuplicateHashPerWindow) {
            $score = min(1.0, ($current / $this->maxDuplicateHashPerWindow) - 0.5);

            return ['name' => 'duplicate', 'score' => $score, 'triggered' => true];
        }

        return ['name' => 'duplicate', 'score' => 0.0, 'triggered' => false];
    }

    /**
     * Check parameter injection — suspicious patterns in event parameters.
     *
     * @param  AnalyticsEvent  $event  The event being checked
     * @return array{name: string, score: float, triggered: bool}
     */
    public function checkParameterInjection(AnalyticsEvent $event): array
    {
        $found = 0;

        foreach ($event->params as $key => $value) {
            $stringValue = is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
            foreach ($this->suspiciousPatterns as $pattern) {
                if (stripos($stringValue, $pattern) !== false || stripos((string) $key, $pattern) !== false) {
                    $found++;
                }
            }
        }

        if ($found > 0) {
            $score = min(1.0, $found * 0.5);

            return ['name' => 'injection', 'score' => $score, 'triggered' => true];
        }

        return ['name' => 'injection', 'score' => 0.0, 'triggered' => false];
    }

    /**
     * Check spoofed identity — multiple fingerprints from the same client ID.
     *
     * @param  string|null  $clientId  Client identifier
     * @param  string|null  $fingerprint  Browser fingerprint hash
     * @return array{name: string, score: float, triggered: bool}
     */
    public function checkSpoofedIdentity(?string $clientId, ?string $fingerprint): array
    {
        if ($clientId === null || $fingerprint === null || $fingerprint === '') {
            return ['name' => 'spoofed_identity', 'score' => 0.0, 'triggered' => false];
        }

        $cacheKey = $this->cachePrefix . 'fp_' . md5($clientId);
        /** @var list<string> $fingerprints */
        $fingerprints = $this->cache->get($cacheKey, []);

        if (! in_array($fingerprint, $fingerprints, true)) {
            $fingerprints[] = $fingerprint;
            $this->cache->put($cacheKey, $fingerprints, $this->spoofedIdentityWindowSeconds);
        }

        $count = count($fingerprints);

        if ($count > $this->maxFingerprintsPerClient) {
            $score = min(1.0, ($count / $this->maxFingerprintsPerClient) - 1.0);

            return ['name' => 'spoofed_identity', 'score' => $score, 'triggered' => true];
        }

        return ['name' => 'spoofed_identity', 'score' => 0.0, 'triggered' => false];
    }

    /**
     * Get fraud metrics summary.
     *
     * @return array{total_evaluated: int, passed: int, quarantined: int, blocked: int, top_flagged_events: list<string>, average_score: float}
     */
    public function getMetrics(): array
    {
        $metricsKey = $this->cachePrefix . 'metrics';
        /** @var array{total: int, passed: int, quarantined: int, blocked: int, scores: list<float>, flagged: array<string, int>} $metrics */
        $metrics = $this->cache->get($metricsKey, [
            'total' => 0,
            'passed' => 0,
            'quarantined' => 0,
            'blocked' => 0,
            'scores' => [],
            'flagged' => [],
        ]);

        $avgScore = count($metrics['scores']) > 0
            ? round(array_sum($metrics['scores']) / count($metrics['scores']), 4)
            : 0.0;

        arsort($metrics['flagged']);
        $topFlagged = array_slice(array_keys($metrics['flagged']), 0, 10);

        return [
            'total_evaluated' => $metrics['total'],
            'passed' => $metrics['passed'],
            'quarantined' => $metrics['quarantined'],
            'blocked' => $metrics['blocked'],
            'top_flagged_events' => array_values($topFlagged),
            'average_score' => $avgScore,
        ];
    }

    /**
     * Reset all fraud metrics.
     */
    public function resetMetrics(): void
    {
        $this->cache->forget($this->cachePrefix . 'metrics');
    }

    /**
     * Get the configured quarantine threshold.
     */
    public function getQuarantineThreshold(): float
    {
        return $this->quarantineThreshold;
    }

    /**
     * Get the configured block threshold.
     */
    public function getBlockThreshold(): float
    {
        return $this->blockThreshold;
    }

    /**
     * Record evaluation metrics.
     *
     * @param  string  $eventName  The event name
     * @param  float  $score  Composite fraud score
     * @param  string  $action  Action taken (pass, quarantine, block)
     */
    private function recordMetrics(string $eventName, float $score, string $action): void
    {
        $metricsKey = $this->cachePrefix . 'metrics';
        /** @var array{total: int, passed: int, quarantined: int, blocked: int, scores: list<float>, flagged: array<string, int>} $metrics */
        $metrics = $this->cache->get($metricsKey, [
            'total' => 0,
            'passed' => 0,
            'quarantined' => 0,
            'blocked' => 0,
            'scores' => [],
            'flagged' => [],
        ]);

        $metrics['total']++;
        $metrics[$action === 'pass' ? 'passed' : ($action === 'quarantine' ? 'quarantined' : 'blocked')]++;

        // Keep last 1000 scores for average computation
        $metrics['scores'][] = $score;
        if (count($metrics['scores']) > 1000) {
            $metrics['scores'] = array_slice($metrics['scores'], -500);
        }

        // Track flagged events
        if ($action !== 'pass') {
            $metrics['flagged'][$eventName] = ($metrics['flagged'][$eventName] ?? 0) + 1;
        }

        $this->cache->put($metricsKey, $metrics, $this->metricsTtl);
    }

    /**
     * Generate a human-readable block reason from triggered signals.
     *
     * @param  array<string, array{name: string, score: float, triggered: bool}>  $signals
     */
    private function generateBlockReason(array $signals): string
    {
        $triggered = array_filter($signals, fn (array $s): bool => $s['triggered']);
        $names = array_map(fn (array $s): string => $s['name'], $triggered);

        return 'Blocked: fraud signals triggered (' . implode(', ', $names) . ')';
    }

    /**
     * Generate a human-readable quarantine reason from triggered signals.
     *
     * @param  array<string, array{name: string, score: float, triggered: bool}>  $signals
     */
    private function generateQuarantineReason(array $signals): string
    {
        $triggered = array_filter($signals, fn (array $s): bool => $s['triggered']);
        $names = array_map(fn (array $s): string => $s['name'], $triggered);

        return 'Quarantined: suspicious signals (' . implode(', ', $names) . ')';
    }
}
