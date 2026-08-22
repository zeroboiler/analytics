<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
/**
 * Event-based user intent detection service.
 *
 * Analyzes user event patterns and sequences to classify their current intent.
 * Intent detection helps SaaS products understand *why* users are performing
 * actions, not just *what* they're doing. This enables:
 *
 * - **Buyer intent scoring** — Identify users close to conversion
 * - **Churn intent detection** — Catch users who are likely to leave
 * - **Feature exploration intent** — Users learning the product
 * - **Support-seeking intent** — Users struggling and needing help
 * - **Power-user intent** — Users maximizing product value
 *
 * Intent is determined by analyzing:
 * 1. Event frequency patterns (high/low volume per category)
 * 2. Event sequence analysis (purchase funnel progression)
 * 3. Temporal patterns (rapid activity bursts vs. slow decline)
 * 4. Feature adoption breadth (single-feature vs. multi-feature users)
 *
 * Inspired by Amplitude's "Compass" intent signals and Mixpanel's "Engagement Scoring".
 *
 * Configuration: `zeroboiler.analytics.intent_detection`
 *
 * @phpstan-type IntentSignal array{type: string, strength: float, description: string, events: list<string>}
 * @phpstan-type IntentResult array{primary_intent: string, confidence: float, all_intents: array<string, float>, signals: list<IntentSignal>, risk_level: 'low'|'medium'|'high'|'critical'}
 *
 * @since 169.0.0
 */
final class EventIntentDetectionService
{
    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'zb_intent_';

    /** @var int Default cache TTL (seconds) */
    private const DEFAULT_CACHE_TTL = 1800;

    /** @var int Minimum events required for intent detection */
    private const MIN_EVENTS = 5;

    /** @var int Lookback window in seconds for temporal analysis */
    private const DEFAULT_LOOKBACK = 604800; // 7 days

    /** @var float High confidence threshold */
    private const HIGH_CONFIDENCE = 0.75;

    /** @var float Medium confidence threshold */
    private const MEDIUM_CONFIDENCE = 0.50;

    /** @var array<string, float> Intent classification weights */
    private const INTENT_WEIGHTS = [
        'buying_intent' => [
            'pricing_page_views' => 0.30,
            'trial_events' => 0.20,
            'checkout_events' => 0.25,
            'engagement_breadth' => 0.15,
            'support_events' => -0.10,
        ],
        'churning' => [
            'login_frequency' => 0.25,
            'feature_usage_decline' => 0.30,
            'support_tickets' => 0.20,
            'error_rate' => 0.15,
            'engagement_breadth' => -0.10,
        ],
        'exploring' => [
            'page_view_diversity' => 0.25,
            'feature_discovery_events' => 0.30,
            'session_count' => 0.20,
            'search_queries' => 0.15,
            'repeat_usage' => -0.10,
        ],
        'power_user' => [
            'feature_usage_depth' => 0.25,
            'session_frequency' => 0.20,
            'integration_events' => 0.20,
            'api_usage' => 0.20,
            'error_rate' => -0.15,
        ],
        'support_seeking' => [
            'error_events' => 0.25,
            'search_queries' => 0.20,
            'documentation_views' => 0.20,
            'session_short' => 0.15,
            'success_events' => -0.20,
        ],
    ];

    /** @var array<string, list<string>> Event name patterns per intent signal */
    private const SIGNAL_PATTERNS = [
        'pricing_page_views' => ['/pricing', '/plan', '/upgrade', '/billing'],
        'trial_events' => ['start_trial', 'trial_start', 'trial_started'],
        'checkout_events' => ['begin_checkout', 'add_payment', 'purchase', 'checkout'],
        'support_tickets' => ['support_ticket', 'help_request', 'contact_support'],
        'error_rate' => ['error', 'js_error', 'client_error', 'server_error'],
        'search_queries' => ['search', 'query'],
        'documentation_views' => ['/docs', '/help', '/guide', '/tutorial', '/api'],
        'feature_discovery_events' => ['feature_used', 'feature_flag_evaluated', 'first_value'],
        'integration_events' => ['integration_connected', 'integration_used', 'webhook_delivered'],
        'api_usage' => ['api_request', 'api_call'],
    ];

    private CacheRepository $cache;

    private int $cacheTtl;

    private bool $enabled;

    private int $lookbackWindow;

    /**
     * @param  CacheRepository|null  $cache  Application cache
     * @param  ConfigRepository|null  $config  Analytics configuration
     */
    public function __construct(?CacheRepository $cache = null, ?ConfigRepository $config = null){
        $this->cache = $cache ?? app(CacheRepository::class);
        $configRepo = $config ?? app(ConfigRepository::class);
        $intentConfig = $configRepo->get('zeroboiler.analytics.intent_detection', []);
        /** @var array{enabled?: bool, cache_ttl?: int, lookback_window?: int} $intentConfig */

        $this->enabled = (bool) ($intentConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($intentConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->lookbackWindow = (int) ($intentConfig['lookback_window'] ?? self::DEFAULT_LOOKBACK);
    }

    /**
     * Detect user intent based on their recent event history.
     *
     * @param  string  $userId  User identifier
     * @param  list<array{name: string, timestamp: int|null, properties?: array<string, mixed>, page_url?: string|null}>  $events  User's recent events
     * @return IntentResult|null Intent detection result or null if insufficient data
     */
    public function detectIntent(string $userId, array $events): ?array
    {
        if (!$this->enabled || count($events) < self::MIN_EVENTS) {
            return null;
        }

        $cacheKey = self::CACHE_PREFIX . 'detect_' . md5($userId);
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $now = time();
        $lookbackCutoff = $now - $this->lookbackWindow;

        $recentEvents = array_filter($events, function (array $event) use ($lookbackCutoff): bool {
            return ($event['timestamp'] ?? 0) >= $lookbackCutoff;
        });

        if (count($recentEvents) < self::MIN_EVENTS) {
            return null;
        }

        $signals = $this->extractSignals($recentEvents);
        $intentScores = $this->computeIntentScores($signals);
        $primaryIntent = $this->determinePrimaryIntent($intentScores);
        $confidence = $this->computeIntentConfidence($intentScores);
        $riskLevel = $this->assessRiskLevel($intentScores);

        $result = [
            'primary_intent' => $primaryIntent,
            'confidence' => $confidence,
            'all_intents' => $intentScores,
            'signals' => $signals,
            'risk_level' => $riskLevel,
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Detect intent for a batch of users.
     *
     * @param  array<string, list<array{name: string, timestamp: int|null, properties?: array<string, mixed>, page_url?: string|null}>>  $userEvents  Map of user ID → events
     * @return array<string, IntentResult> Map of user ID → intent result
     */
    public function detectBatchIntents(array $userEvents): array
    {
        $results = [];

        foreach ($userEvents as $userId => $events) {
            $intent = $this->detectIntent($userId, $events);
            if ($intent !== null) {
                $results[$userId] = $intent;
            }
        }

        return $results;
    }

    /**
     * Get users with high buying intent (for sales team follow-up).
     *
     * @param  array<string, IntentResult>  $intentResults  Pre-computed intent results
     * @param  float  $minConfidence  Minimum confidence threshold
     * @return list<array{user_id: string, confidence: float, signals: list<IntentSignal>}> High-intent users
     */
    public function getHighIntentUsers(array $intentResults, float $minConfidence = self::HIGH_CONFIDENCE): array
    {
        $highIntent = [];

        foreach ($intentResults as $userId => $result) {
            if (
                ($result['primary_intent'] === 'buying_intent' || $result['primary_intent'] === 'power_user')
                && $result['confidence'] >= $minConfidence
            ) {
                $highIntent[] = [
                    'user_id' => $userId,
                    'confidence' => $result['confidence'],
                    'signals' => $result['signals'],
                ];
            }
        }

        usort($highIntent, fn(array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        return $highIntent;
    }

    /**
     * Get users showing churn signals (for retention team intervention).
     *
     * @param  array<string, IntentResult>  $intentResults  Pre-computed intent results
     * @return list<array{user_id: string, confidence: float, risk_level: string, signals: list<IntentSignal>}> At-risk users
     */
    public function getAtRiskUsers(array $intentResults): array
    {
        $atRisk = [];

        foreach ($intentResults as $userId => $result) {
            if (
                $result['primary_intent'] === 'churning'
                && in_array($result['risk_level'], ['high', 'critical'], true)
            ) {
                $atRisk[] = [
                    'user_id' => $userId,
                    'confidence' => $result['confidence'],
                    'risk_level' => $result['risk_level'],
                    'signals' => $result['signals'],
                ];
            }
        }

        usort($atRisk, fn(array $a, array $b): int => $a['confidence'] <=> $b['confidence']);

        return $atRisk;
    }

    /**
     * Extract behavioral signals from events.
     *
     * @param  list<array{name: string, timestamp: int|null, properties?: array<string, mixed>, page_url?: string|null}>  $events
     * @return list<IntentSignal>
     */
    private function extractSignals(array $events): array
    {
        $signals = [];
        $totalEvents = count($events);
        $eventNames = array_column($events, 'name');
        $pageUrls = array_filter(array_column($events, 'page_url'));

        $signalCounts = [];
        foreach (self::SIGNAL_PATTERNS as $signalName => $patterns) {
            $count = 0;

            foreach ($events as $event) {
                $name = $event['name'] ?? '';
                $url = $event['page_url'] ?? '';

                foreach ($patterns as $pattern) {
                    if (stripos($name, $pattern) !== false || stripos($url, $pattern) !== false) {
                        $count++;
                        break;
                    }
                }
            }

            $signalCounts[$signalName] = $count;
        }

        foreach ($signalCounts as $signalName => $count) {
            if ($count === 0) {
                continue;
            }

            $strength = min(1.0, $count / max(1, $totalEvents * 0.3));
            $matchedEvents = $this->getMatchedEventNames($signalName, $events);

            $signals[] = [
                'type' => $signalName,
                'strength' => round($strength, 4),
                'description' => $this->describeSignal($signalName, $count, $totalEvents),
                'events' => $matchedEvents,
            ];
        }

        $diversity = count(array_unique($eventNames)) / max(1, $totalEvents);
        $signals[] = [
            'type' => 'engagement_breadth',
            'strength' => round(min(1.0, $diversity * 2), 4),
            'description' => sprintf(
                'User engaged with %d unique event types (%.0f%% diversity).',
                count(array_unique($eventNames)),
                $diversity * 100,
            ),
            'events' => array_values(array_unique($eventNames)),
        ];

        // Login frequency signal
        $loginCount = count(array_filter($eventNames, fn(string $n): bool => str_contains($n, 'login')));
        if ($loginCount > 0) {
            $loginStrength = min(1.0, $loginCount / max(1, $totalEvents * 0.1));
            $signals[] = [
                'type' => 'login_frequency',
                'strength' => round($loginStrength, 4),
                'description' => sprintf('User logged in %d times in lookback window.', $loginCount),
                'events' => ['login'],
            ];
        }

        // Temporal burst signal (rapid activity)
        $timestamps = array_filter(array_map(fn(array $e): ?int => $e['timestamp'] ?? null, $events));
        sort($timestamps);
        $burst = $this->detectActivityBurst($timestamps);
        if ($burst > 0.3) {
            $signals[] = [
                'type' => 'activity_burst',
                'strength' => round($burst, 4),
                'description' => 'Detected rapid activity burst (high engagement session).',
                'events' => [],
            ];
        }

        usort($signals, fn(array $a, array $b): int => $b['strength'] <=> $a['strength']);

        return $signals;
    }

    /**
     * Compute intent scores from extracted signals.
     *
     * @param  list<IntentSignal>  $signals
     * @return array<string, float> Intent name → score (0-1)
     */
    private function computeIntentScores(array $signals): array
    {
        $signalStrengths = [];
        foreach ($signals as $signal) {
            $signalStrengths[$signal['type']] = $signal['strength'];
        }

        $scores = [];

        foreach (self::INTENT_WEIGHTS as $intent => $weights) {
            $score = 0.0;
            $totalWeight = 0.0;

            foreach ($weights as $signalName => $weight) {
                $strength = $signalStrengths[$signalName] ?? 0.0;
                $score += $strength * $weight;
                $totalWeight += abs($weight);
            }

            $scores[$intent] = $totalWeight > 0 ? round(min(1.0, max(0.0, $score / $totalWeight)), 4) : 0.0;
        }

        return $scores;
    }

    /**
     * Determine the primary (dominant) intent from scores.
     *
     * @param  array<string, float>  $scores
     * @return string Intent name
     */
    private function determinePrimaryIntent(array $scores): string
    {
        $bestIntent = 'exploring';
        $bestScore = 0.0;

        foreach ($scores as $intent => $score) {
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIntent = $intent;
            }
        }

        return $bestIntent;
    }

    /**
     * Compute confidence in the intent classification.
     *
     * High confidence = one intent clearly dominates.
     * Low confidence = scores are spread across multiple intents.
     *
     * @param  array<string, float>  $scores
     * @return float Confidence 0.0 to 1.0
     */
    private function computeIntentConfidence(array $scores): float
    {
        if (empty($scores)) {
            return 0.0;
        }

        $total = array_sum($scores);
        if ($total === 0.0) {
            return 0.0;
        }

        $maxScore = max($scores);
        $entropy = 0.0;

        foreach ($scores as $score) {
            if ($score > 0) {
                $p = $score / $total;
                $entropy -= $p * log($p + 1e-10);
            }
        }

        $maxEntropy = log(count($scores));
        $normalizedEntropy = $maxEntropy > 0 ? $entropy / $maxEntropy : 0.0;

        // Confidence = 1 - entropy (less entropy = more confident)
        return round(max(0.0, 1.0 - $normalizedEntropy) * ($maxScore / $total), 4);
    }

    /**
     * Assess overall risk level based on intent scores.
     *
     * @param  array<string, float>  $scores
     * @return 'low'|'medium'|'high'|'critical'
     */
    private function assessRiskLevel(array $scores): string
    {
        $churnScore = $scores['churning'] ?? 0.0;
        $supportScore = $scores['support_seeking'] ?? 0.0;
        $combinedRisk = ($churnScore * 0.7) + ($supportScore * 0.3);

        if ($combinedRisk >= 0.75) {
            return 'critical';
        }

        if ($combinedRisk >= 0.50) {
            return 'high';
        }

        if ($combinedRisk >= 0.30) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Get event names that matched a signal pattern.
     *
     * @param  string  $signalName
     * @param  list<array{name: string, timestamp: int|null, properties?: array<string, mixed>, page_url?: string|null}>  $events
     * @return list<string>
     */
    private function getMatchedEventNames(string $signalName, array $events): array
    {
        $patterns = self::SIGNAL_PATTERNS[$signalName] ?? [];
        $matched = [];

        foreach ($events as $event) {
            $name = $event['name'] ?? '';
            $url = $event['page_url'] ?? '';

            foreach ($patterns as $pattern) {
                if (stripos($name, $pattern) !== false || stripos($url, $pattern) !== false) {
                    $matched[] = $name;
                    break;
                }
            }
        }

        return array_values(array_unique($matched));
    }

    /**
     * Describe a signal in human-readable form.
     *
     * @param  string  $signalName
     * @param  int  $count
     * @param  int  $total
     * @return string
     */
    private function describeSignal(string $signalName, int $count, int $total): string
    {
        $percentage = $total > 0 ? round(($count / $total) * 100) : 0;

        return match ($signalName) {
            'pricing_page_views' => sprintf('Visited pricing/plan pages %d times (%d%% of activity).', $count, $percentage),
            'trial_events' => sprintf('Trial-related events: %d occurrences (%d%%).', $count, $percentage),
            'checkout_events' => sprintf('Checkout/purchase events: %d (%d%%).', $count, $percentage),
            'support_tickets' => sprintf('Support interactions: %d (%d%%).', $count, $percentage),
            'error_rate' => sprintf('Error events: %d (%d%% of activity — potential friction).', $count, $percentage),
            'search_queries' => sprintf('Search queries: %d (%d%% — looking for something specific).', $count, $percentage),
            'documentation_views' => sprintf('Documentation/help page views: %d (%d%%).', $count, $percentage),
            'feature_discovery_events' => sprintf('Feature discovery events: %d (%d%% — exploring product).', $count, $percentage),
            'integration_events' => sprintf('Integration events: %d (%d%% — advanced usage).', $count, $percentage),
            'api_usage' => sprintf('API usage events: %d (%d%% — power user indicator).', $count, $percentage),
            default => sprintf('Signal "%s": %d events (%d%%).', $signalName, $count, $percentage),
        };
    }

    /**
     * Detect activity bursts (many events in a short time window).
     *
     * @param  list<int>  $timestamps  Sorted timestamps
     * @return float Burst intensity 0.0 to 1.0
     */
    private function detectActivityBurst(array $timestamps): float
    {
        if (count($timestamps) < 5) {
            return 0.0;
        }

        $maxPerMinute = 0;
        $currentMinute = 0;
        $countInMinute = 0;

        foreach ($timestamps as $ts) {
            $minute = (int) ($ts / 60);
            if ($minute !== $currentMinute) {
                $maxPerMinute = max($maxPerMinute, $countInMinute);
                $countInMinute = 1;
                $currentMinute = $minute;
            } else {
                $countInMinute++;
            }
        }

        $maxPerMinute = max($maxPerMinute, $countInMinute);

        // Burst: more than 10 events per minute is considered bursty
        return min(1.0, $maxPerMinute / 30);
    }

    /**
     * Get service status summary.
     *
     * @return array{enabled: bool, cache_ttl: int, lookback_window: int, supported_intents: list<string>, signal_patterns: list<string>}
     */
    public function getStatus(): array
    {
        return [
            'enabled' => $this->enabled,
            'cache_ttl' => $this->cacheTtl,
            'lookback_window' => $this->lookbackWindow,
            'supported_intents' => array_keys(self::INTENT_WEIGHTS),
            'signal_patterns' => array_keys(self::SIGNAL_PATTERNS),
        ];
    }
}
