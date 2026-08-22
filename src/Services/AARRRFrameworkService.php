<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * AARRR (Pirate Metrics) Framework service for SaaS analytics.
 *
 * Provides a unified framework for measuring the five key SaaS growth metrics:
 * - **Acquisition**: How do users find you? (sign_up, page_view, campaign_attribution)
 * - **Activation**: Do they have a great first experience? (onboarding_step, feature_used, email_verified)
 * - **Retention**: Do they come back? (login, session_start, feature_used, content_engagement)
 * - **Revenue**: Do you make money? (purchase, subscribe, plan_upgrade, revenue_tracked)
 * - **Referral**: Do they tell others? (share, invite_sent, team_member_joined)
 *
 * Each pillar provides event tracking, metric calculation, health scoring,
 * and actionable insights. Designed as the single source of truth for
 * SaaS growth metrics across all analytics providers.
 *
 * @see \ZeroBoiler\Analytics\Events\EventCatalog
 * @see \ZeroBoiler\Analytics\Services\SaaSConversionService
 *
 * @since 6.2.0
 */
final class AARRRFrameworkService
{
    /** @var string Cache prefix */
    private const CACHE_PREFIX = 'zb_aarrr_';

    /** Default cache TTL (5 minutes). */
    private const DEFAULT_CACHE_TTL = 300;

    /**
     * AARRR pillar definitions with their event groups and weights.
     *
     * @var array<string, array{label: string, events: list<string>, weight: float, description: string}>
     */
    private const PILLARS = [
        'acquisition' => [
            'label' => 'Acquisition',
            'events' => [
                'sign_up', 'page_view', 'campaign_attribution', 'ad_click',
                'outbound_click', 'search', 'share',
            ],
            'weight' => 0.20,
            'description' => 'How users discover and arrive at your product',
        ],
        'activation' => [
            'label' => 'Activation',
            'events' => [
                'email_verified', 'onboarding_step', 'first_feature_used',
                'feature_used', 'form_submit', 'start_trial',
            ],
            'weight' => 0.25,
            'description' => 'Whether users experience the core value proposition',
        ],
        'retention' => [
            'label' => 'Retention',
            'events' => [
                'login', 'session_start', 'session_end', 'feature_used',
                'content_engagement', 'page_view', 'time_on_page',
            ],
            'weight' => 0.25,
            'description' => 'Whether users continue using the product over time',
        ],
        'revenue' => [
            'label' => 'Revenue',
            'events' => [
                'purchase', 'subscribe', 'plan_upgrade', 'plan_downgrade',
                'revenue_tracked', 'payment_succeeded', 'trial_converted',
                'add_to_cart', 'begin_checkout', 'expansion_revenue',
            ],
            'weight' => 0.20,
            'description' => 'How the product generates income',
        ],
        'referral' => [
            'label' => 'Referral',
            'events' => [
                'share', 'invite_sent', 'team_member_joined', 'team_created',
                'integration_connected',
            ],
            'weight' => 0.10,
            'description' => 'Whether users recommend the product to others',
        ],
    ];

    private AnalyticsManager $manager;

    private CacheRepository $cache;

    private int $cacheTtl;

    /**
     * @param  AnalyticsManager  $manager
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        AnalyticsManager $manager,
        CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->manager = $manager;
        $this->cache = $cache;

        $aarrrConfig = $config->get('zeroboiler.analytics.aarrr', []);
        /** @var array{cache_ttl?: int} $aarrrConfig */
        $this->cacheTtl = (int) ($aarrrConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
    }

    // ── Pillar Definitions ──────────────────────────────────────────

    /**
     * Get all AARRR pillar definitions.
     *
     * @return array<string, array{label: string, events: list<string>, weight: float, description: string, catalog_events: list<array<string, mixed>>}>
     */
    public function pillars(): array
    {
        $result = [];

        foreach (self::PILLARS as $key => $pillar) {
            $catalogEvents = [];
            foreach ($pillar['events'] as $eventName) {
                $entry = EventCatalog::get($eventName);
                if ($entry !== null) {
                    $catalogEvents[] = $entry;
                }
            }

            $result[$key] = [
                'label' => $pillar['label'],
                'events' => $pillar['events'],
                'weight' => $pillar['weight'],
                'description' => $pillar['description'],
                'catalog_events' => $catalogEvents,
                'coverage' => count($catalogEvents),
                'total_events' => count($pillar['events']),
            ];
        }

        return $result;
    }

    /**
     * Get a specific AARRR pillar definition.
     *
     * @param  'acquisition'|'activation'|'retention'|'revenue'|'referral'  $pillar
     * @return array{label: string, events: list<string>, weight: float, description: string, catalog_events: list<array<string, mixed>>, coverage: int, total_events: int}|null
     */
    public function pillar(string $pillar): ?array
    {
        return $this->pillars()[$pillar] ?? null;
    }

    // ── Event Tracking (Convenience) ──────────────────────────────────

    /**
     * Track an acquisition event.
     *
     * @param  array<string, mixed>  $params
     */
    public function trackAcquisition(string $eventName, array $params = []): void
    {
        $this->trackPillarEvent('acquisition', $eventName, $params);
    }

    /**
     * Track an activation event.
     *
     * @param  array<string, mixed>  $params
     */
    public function trackActivation(string $eventName, array $params = []): void
    {
        $this->trackPillarEvent('activation', $eventName, $params);
    }

    /**
     * Track a retention event.
     *
     * @param  array<string, mixed>  $params
     */
    public function trackRetention(string $eventName, array $params = []): void
    {
        $this->trackPillarEvent('retention', $eventName, $params);
    }

    /**
     * Track a revenue event.
     *
     * @param  array<string, mixed>  $params
     */
    public function trackRevenue(string $eventName, array $params = []): void
    {
        $this->trackPillarEvent('revenue', $eventName, $params);
    }

    /**
     * Track a referral event.
     *
     * @param  array<string, mixed>  $params
     */
    public function trackReferral(string $eventName, array $params = []): void
    {
        $this->trackPillarEvent('referral', $eventName, $params);
    }

    /**
     * Track an event tagged with its AARRR pillar.
     *
     * @param  string  $pillar  The AARRR pillar name
     * @param  string  $eventName  The event name
     * @param  array<string, mixed>  $params  Event parameters
     */
    private function trackPillarEvent(string $pillar, string $eventName, array $params = []): void
    {
        $event = new AnalyticsEvent(
            name: $eventName,
            params: array_merge($params, [
                '_aarrr_pillar' => $pillar,
                '_aarrr_timestamp' => now()->toIso8601String(),
            ]),
        );

        $this->manager->trackEvent($event);
    }

    // ── Health Scoring ───────────────────────────────────────────────

    /**
     * Calculate the overall AARRR health score.
     *
     * Returns a weighted score (0-100) based on event catalog coverage
     * across all five pillars. Each pillar's score is based on what
     * percentage of its events are present in the catalog.
     *
     * @return array{score: float, grade: string, pillars: array<string, array{score: float, coverage: int, total: int, grade: string}>, recommendations: list<string>}
     */
    public function healthScore(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'health_score';

        return $this->cache->remember($cacheKey, $this->cacheTtl, function (): array {
            $pillarScores = [];
            $totalWeightedScore = 0.0;
            $recommendations = [];

            foreach (self::PILLARS as $key => $pillar) {
                $coverage = 0;
                foreach ($pillar['events'] as $eventName) {
                    if (EventCatalog::has($eventName)) {
                        $coverage++;
                    }
                }

                $total = count($pillar['events']);
                $score = $total > 0 ? ($coverage / $total) * 100 : 0.0;
                $grade = $this->scoreToGrade($score);

                $pillarScores[$key] = [
                    'score' => round($score, 1),
                    'coverage' => $coverage,
                    'total' => $total,
                    'grade' => $grade,
                ];

                $totalWeightedScore += $score * $pillar['weight'];

                if ($score < 50) {
                    $missing = [];
                    foreach ($pillar['events'] as $eventName) {
                        if (! EventCatalog::has($eventName)) {
                            $missing[] = $eventName;
                        }
                    }
                    $recommendations[] = sprintf(
                        '%s: Only %d/%d events covered. Missing: %s',
                        $pillar['label'],
                        $coverage,
                        $total,
                        implode(', ', array_slice($missing, 0, 5)),
                    );
                }
            }

            $overallScore = round($totalWeightedScore, 1);

            return [
                'score' => $overallScore,
                'grade' => $this->scoreToGrade($overallScore),
                'pillars' => $pillarScores,
                'recommendations' => $recommendations,
            ];
        });
    }

    /**
     * Get the weakest AARRR pillar (lowest health score).
     *
     * @return array{pillar: string, score: float, grade: string, recommendation: string}|null
     */
    public function weakestPillar(): ?array
    {
        $health = $this->healthScore();
        $weakest = null;
        $lowestScore = 101.0;

        foreach ($health['pillars'] as $key => $data) {
            if ($data['score'] < $lowestScore) {
                $lowestScore = $data['score'];
                $weakest = [
                    'pillar' => $key,
                    'score' => $data['score'],
                    'grade' => $data['grade'],
                    'recommendation' => $this->pillarRecommendation($key, $data['coverage'], $data['total']),
                ];
            }
        }

        return $weakest;
    }

    /**
     * Get the strongest AARRR pillar (highest health score).
     *
     * @return array{pillar: string, score: float, grade: string}|null
     */
    public function strongestPillar(): ?array
    {
        $health = $this->healthScore();
        $strongest = null;
        $highestScore = -1.0;

        foreach ($health['pillars'] as $key => $data) {
            if ($data['score'] > $highestScore) {
                $highestScore = $data['score'];
                $strongest = [
                    'pillar' => $key,
                    'score' => $data['score'],
                    'grade' => $data['grade'],
                ];
            }
        }

        return $strongest;
    }

    // ── Coverage Analysis ───────────────────────────────────────────

    /**
     * Get event coverage analysis per AARRR pillar.
     *
     * Returns which events are covered (in catalog) vs. missing
     * for each pillar, useful for instrumentation gap analysis.
     *
     * @return array<string, array{covered: list<string>, missing: list<string>, coverage_pct: float}>
     */
    public function coverageAnalysis(): array
    {
        $result = [];

        foreach (self::PILLARS as $key => $pillar) {
            $covered = [];
            $missing = [];

            foreach ($pillar['events'] as $eventName) {
                if (EventCatalog::has($eventName)) {
                    $covered[] = $eventName;
                } else {
                    $missing[] = $eventName;
                }
            }

            $total = count($pillar['events']);
            $result[$key] = [
                'covered' => $covered,
                'missing' => $missing,
                'coverage_pct' => $total > 0 ? round((count($covered) / $total) * 100, 1) : 0.0,
            ];
        }

        return $result;
    }

    /**
     * Get events not yet mapped to any AARRR pillar.
     *
     * Useful for identifying events that exist in the catalog
     * but are not tracked as part of any growth pillar.
     *
     * @return list<string>
     */
    public function unmappedEvents(): array
    {
        $allPillarEvents = [];
        foreach (self::PILLARS as $pillar) {
            foreach ($pillar['events'] as $event) {
                $allPillarEvents[$event] = true;
            }
        }

        $unmapped = [];
        foreach (EventCatalog::names() as $name) {
            if (! isset($allPillarEvents[$name])) {
                $unmapped[] = $name;
            }
        }

        return $unmapped;
    }

    // ── Dashboard Summary ────────────────────────────────────────────

    /**
     * Get a complete AARRR dashboard summary.
     *
     * Combines pillar definitions, health scores, coverage analysis,
     * weakest/strongest pillars, and recommendations into a single
     * response for dashboard rendering.
     *
     * @return array{health: array{score: float, grade: string}, pillars: array<string, mixed>, weakest: array<string, mixed>|null, strongest: array<string, mixed>|null, coverage: array<string, mixed>, recommendations: list<string>, unmapped_count: int, total_catalog_events: int}
     */
    public function dashboard(): array
    {
        $health = $this->healthScore();
        $coverage = $this->coverageAnalysis();
        $unmapped = $this->unmappedEvents();

        return [
            'health' => [
                'score' => $health['score'],
                'grade' => $health['grade'],
            ],
            'pillars' => $this->pillars(),
            'weakest' => $this->weakestPillar(),
            'strongest' => $this->strongestPillar(),
            'coverage' => $coverage,
            'recommendations' => $health['recommendations'],
            'unmapped_count' => count($unmapped),
            'total_catalog_events' => EventCatalog::count(),
        ];
    }

    /**
     * Invalidate the AARRR health score cache.
     */
    public function invalidateCache(): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'health_score');
    }

    // ── Utilities ──────────────────────────────────────────────────────

    /**
     * Convert a numeric score to a letter grade.
     *
     * @param  float  $score  Score from 0-100
     * @return string  Letter grade (A+, A, B, C, D, F)
     */
    private function scoreToGrade(float $score): string
    {
        return match (true) {
            $score >= 95 => 'A+',
            $score >= 85 => 'A',
            $score >= 70 => 'B',
            $score >= 50 => 'C',
            $score >= 30 => 'D',
            default => 'F',
        };
    }

    /**
     * Generate a recommendation for a pillar based on coverage.
     *
     * @param  string  $pillarKey
     * @param  int  $coverage
     * @param  int  $total
     * @return string
     */
    private function pillarRecommendation(string $pillarKey, int $coverage, int $total): string
    {
        $pillarLabel = self::PILLARS[$pillarKey]['label'] ?? $pillarKey;

        if ($coverage === 0) {
            return sprintf('No %s events are tracked. Start by instrumenting core %s events.', $pillarLabel, strtolower($pillarLabel));
        }

        if ($coverage < $total / 2) {
            return sprintf('%s tracking is incomplete (%d/%d). Focus on the missing events to improve growth visibility.', $pillarLabel, $coverage, $total);
        }

        return sprintf('%s tracking is looking good (%d/%d). Consider adding advanced events for deeper insights.', $pillarLabel, $coverage, $total);
    }
}
