<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\Events\SaaS\CustomerSuccessEvents;

/**
 * Customer success analytics service.
 *
 * Aggregates customer success signals (support tickets, NPS scores,
 * health score changes, renewal reminders, churn interviews) into
 * actionable metrics for customer success teams.
 *
 * Provides:
 * - Health score trending per customer
 * - NPS aggregate statistics
 * - Support ticket velocity
 * - Churn risk signal aggregation
 * - Renewal pipeline forecasting
 *
 * @since 135.0.0
 */
final class CustomerSuccessAnalyticsService
{
    /** Cache TTL in seconds (5 minutes) */
    private const CACHE_TTL = 300;

    /** @var CacheRepository */
    private CacheRepository $cache;

    /**
     * @param  CacheRepository  $cache  Cache repository for aggregated metrics
     */
    public function __construct(CacheRepository $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Get the customer success event catalog summary.
     *
     * @return array{count: int, events: list<string>, categories: list<string>}
     */
    public function catalogSummary(): array
    {
        return [
            'count' => CustomerSuccessEvents::count(),
            'events' => CustomerSuccessEvents::names(),
            'categories' => ['support', 'satisfaction', 'health', 'renewal', 'churn', 'advocacy', 'onboarding'],
        ];
    }

    /**
     * Classify an NPS score into a category.
     *
     * @param  int  $score  NPS score (-100 to +100)
     * @return string One of: 'promoter', 'passive', 'detractor'
     */
    public static function classifyNps(int $score): string
    {
        if ($score >= 9) {
            return 'promoter';
        }

        if ($score >= 7) {
            return 'passive';
        }

        return 'detractor';
    }

    /**
     * Calculate the NPS value from a list of individual scores.
     *
     * NPS = % Promoters - % Detractors
     *
     * @param  list<int>  $scores  Individual NPS ratings (0-10)
     * @return int NPS value (-100 to +100)
     */
    public static function calculateNps(array $scores): int
    {
        if ($scores === []) {
            return 0;
        }

        $total = count($scores);
        $promoters = 0;
        $detractors = 0;

        foreach ($scores as $score) {
            if ($score >= 9) {
                $promoters++;
            } elseif ($score <= 6) {
                $detractors++;
            }
        }

        $promoterPct = ($promoters / $total) * 100;
        $detractorPct = ($detractors / $total) * 100;

        return (int) round($promoterPct - $detractorPct);
    }

    /**
     * Compute a health score signal weight from event recency and type.
     *
     * Recent negative signals (low NPS, high ticket volume) decrease health.
     * Recent positive signals (review submitted, onboarding completed) increase health.
     *
     * @param  array<string, int>  $recentEvents  Event name → count in the last 30 days
     * @return float Weighted health signal (-1.0 to +1.0)
     */
    public static function computeHealthSignal(array $recentEvents): float
    {
        if ($recentEvents === []) {
            return 0.0;
        }

        $signal = 0.0;

        // Negative signals
        $signal -= ($recentEvents['support_ticket_created'] ?? 0) * 0.15;
        $signal -= ($recentEvents['churn_interview'] ?? 0) * 0.30;

        // Positive signals
        $signal += ($recentEvents['customer_review'] ?? 0) * 0.20;
        $signal += ($recentEvents['onboarding_call_completed'] ?? 0) * 0.25;
        $signal += ($recentEvents['nps_submitted'] ?? 0) * 0.10;

        // Clamp to [-1.0, +1.0]
        return max(-1.0, min(1.0, $signal));
    }

    /**
     * Determine churn risk level from aggregated signals.
     *
     * @param  float  $healthSignal  Weighted health signal (-1.0 to +1.0)
     * @param  int|null  $npsScore  Latest NPS score (optional)
     * @param  int  $supportTicketCount  Support tickets in the last 30 days
     * @return array{level: string, score: float, factors: list<string>}
     */
    public static function assessChurnRisk(
        float $healthSignal,
        ?int $npsScore = null,
        int $supportTicketCount = 0,
    ): array {
        $score = 50.0 + ($healthSignal * 25.0); // Base: 50, range: 25-75 from signal

        if ($npsScore !== null) {
            $score += ($npsScore - 5) * 3; // NPS 0-10 scale adjustment
        }

        if ($supportTicketCount > 10) {
            $score -= 15;
        } elseif ($supportTicketCount > 5) {
            $score -= 8;
        }

        $score = max(0.0, min(100.0, $score));

        $level = match (true) {
            $score >= 75 => 'low',
            $score >= 50 => 'medium',
            $score >= 30 => 'high',
            default => 'critical',
        };

        $factors = [];
        if ($healthSignal < -0.3) {
            $factors[] = 'negative_health_signal';
        }
        if ($npsScore !== null && $npsScore <= 6) {
            $factors[] = 'low_nps';
        }
        if ($supportTicketCount > 5) {
            $factors[] = 'high_ticket_volume';
        }

        return [
            'level' => $level,
            'score' => round($score, 1),
            'factors' => $factors,
        ];
    }

    /**
     * Get a customer success KPI summary.
     *
     * @param  array{avg_nps?: int, total_tickets_30d?: int, avg_health_score?: float, renewal_rate?: float, churn_rate?: float}  $metrics  Raw KPI values
     * @return array{nps: array{value: int, classification: string}, support_velocity: array{total_30d: int, daily_avg: float}, health: array{avg_score: float, trend: string}, retention: array{renewal_rate: float, churn_rate: float}}
     */
    public static function kpiSummary(array $metrics): array
    {
        $avgNps = (int) ($metrics['avg_nps'] ?? 0);
        $totalTickets = (int) ($metrics['total_tickets_30d'] ?? 0);
        $avgHealth = (float) ($metrics['avg_health_score'] ?? 50.0);
        $renewalRate = (float) ($metrics['renewal_rate'] ?? 0.0);
        $churnRate = (float) ($metrics['churn_rate'] ?? 0.0);

        return [
            'nps' => [
                'value' => $avgNps,
                'classification' => self::classifyNps($avgNps),
            ],
            'support_velocity' => [
                'total_30d' => $totalTickets,
                'daily_avg' => round($totalTickets / 30, 1),
            ],
            'health' => [
                'avg_score' => round($avgHealth, 1),
                'trend' => $avgHealth >= 70 ? 'healthy' : ($avgHealth >= 40 ? 'at_risk' : 'unhealthy'),
            ],
            'retention' => [
                'renewal_rate' => round($renewalRate, 2),
                'churn_rate' => round($churnRate, 2),
            ],
        ];
    }
}
