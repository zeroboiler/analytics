<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a Core Web Vitals performance metric.
 *
 * Captures LCP, FID, CLS, INP, TTFB, and other Web Vitals
 * metrics reported by the client-side performance observer.
 *
 * GA4: web_vitals (custom)
 * Meta: (custom)
 *
 * @since 1.0.0
 */
final readonly class WebVitalsEvent extends AnalyticsEvent
{
    /**
     * @param  string  $metricName  Metric name (LCP, FID, CLS, INP, TTFB, FCP)
     * @param  float  $value  Metric value (round-trip time in ms, score, etc.)
     * @param  string|null  $rating  Good, needs-improvement, or poor
     * @param  string|null  $pagePath  Page where metric was measured
     * @param  string|null  $navigationType  Navigation type (navigate, reload, back_forward, prerender)
     */
    public function __construct(
        string $metricName,
        float $value,
        ?string $rating = null,
        ?string $pagePath = null,
        ?string $navigationType = null,
    ): void {
        parent::__construct('web_vitals', array_filter([
            'metric_name' => $metricName,
            'metric_value' => round($value, 2),
            'rating' => $rating,
            'page_path' => $pagePath,
            'navigation_type' => $navigationType,
        ], fn (mixed $v): bool => $v !== null));
    }
}
