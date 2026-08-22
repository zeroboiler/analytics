<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Performance score event — fired when an aggregate performance score is calculated.
 *
 * Tracks the overall performance score (0-100) alongside individual Core Web Vitals
 * ratings. Useful for:
 *   - Monitoring performance trends over time
 *   - Correlating performance with conversion rates
 *   - Identifying performance regressions
 *   - A/B testing performance optimizations
 *
 * @since 24.0.0
 */
final readonly class PerformanceScoreEvent extends AnalyticsEvent
{
    /**
     * Create a new performance score event.
     *
     * @param  int  $score  Overall performance score (0-100)
     * @param  string  $rating  Rating: 'good'|'needs-improvement'|'poor'
     * @param  array<string, array{value: float, rating: string}>  $metrics  Individual metric breakdown
     * @param  string|null  $pageUrl  Page URL where metrics were collected
     * @param  string|null  $sessionId  Analytics session ID
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function __construct(
        int $score,
        string $rating,
        array $metrics = [],
        ?string $pageUrl = null,
        ?string $sessionId = null,
        array $extra = [],
    ){
        $params = array_merge($extra, [
            'score' => $score,
            'rating' => $rating,
            'page_url' => $pageUrl,
            'session_id' => $sessionId,
        ]);

        foreach ($metrics as $metricName => $metricData) {
            $params["metric_{$metricName}_value"] = $metricData['value'] ?? null;
            $params["metric_{$metricName}_rating"] = $metricData['rating'] ?? null;
        }

        parent::__construct(
            name: 'performance_score',
            params: $params,
        );
    }
}
