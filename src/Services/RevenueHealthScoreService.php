<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Revenue Health Score Service.
 *
 * Computes a composite health score for revenue analytics by analyzing
 * event patterns across the SaaS and e-commerce event catalogs.
 *
 * The health score (0-100) is derived from:
 * - Revenue event coverage: Are all revenue-critical events being tracked?
 * - Subscription lifecycle coverage: Sign-up → trial → subscribe → upgrade flow
 * - E-commerce funnel coverage: View → cart → checkout → purchase
 * - Billing health signals: Payment success/failure ratios
 * - Provider mapping coverage: Are revenue events mapped to all providers?
 *
 * Used by admin dashboards and readiness checks to identify gaps in
 * revenue analytics instrumentation.
 *
 * @since 133.0.0
 */
final class RevenueHealthScoreService
{
    private const CACHE_KEY = 'zb_analytics_revenue_health_score';
    private const CACHE_TTL = 3600; // 1 hour

    private CacheRepository $cache;

    /** @var array<string, int> Weight of each health dimension (must sum to 100) */
    private const WEIGHTS = [
        'revenue_events' => 25,
        'subscription_lifecycle' => 25,
        'ecommerce_funnel' => 20,
        'billing_signals' => 15,
        'provider_coverage' => 15,
    ];

    /**
     * @param  CacheRepository  $cache  Laravel cache repository
     */
    public function __construct(CacheRepository $cache): void
    {
        $this->cache = $cache;
    }

    /**
     * Compute the revenue health score with caching.
     *
     * @return array{score: int, grade: string, dimensions: array<string, array{score: float, max: int, weight: int, status: string, details: string}>, gaps: list<string>, recommendations: list<string>, computed_at: string}
     */
    public function compute(): array
    {
        return $this->cache->remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            return $this->computeFresh();
        });
    }

    /**
     * Compute the revenue health score without caching.
     *
     * @return array{score: int, grade: string, dimensions: array<string, array{score: float, max: int, weight: int, status: string, details: string}>, gaps: list<string>, recommendations: list<string>, computed_at: string}
     */
    public function computeFresh(): array
    {
        $dimensions = [
            'revenue_events' => $this->assessRevenueEvents(),
            'subscription_lifecycle' => $this->assessSubscriptionLifecycle(),
            'ecommerce_funnel' => $this->assessEcommerceFunnel(),
            'billing_signals' => $this->assessBillingSignals(),
            'provider_coverage' => $this->assessProviderCoverage(),
        ];

        // Weighted score
        $totalScore = 0.0;
        foreach ($dimensions as $key => $dim) {
            $weight = self::WEIGHTS[$key] ?? 0;
            $totalScore += ($dim['score'] / 100) * $weight;
        }

        $score = (int) round($totalScore);
        $grade = $this->computeGrade($score);
        $gaps = $this->identifyGaps($dimensions);
        $recommendations = $this->generateRecommendations($dimensions, $gaps);

        return [
            'score' => $score,
            'grade' => $grade,
            'dimensions' => $dimensions,
            'gaps' => $gaps,
            'recommendations' => $recommendations,
            'computed_at' => date('c'),
        ];
    }

    /**
     * Assess revenue event tracking coverage.
     *
     * @return array{score: float, max: int, weight: int, status: string, details: string}
     */
    private function assessRevenueEvents(): array
    {
        $revenueEvents = [
            'purchase', 'refund', 'subscribe', 'revenue_tracked',
            'payment_succeeded', 'payment_failed', 'invoice_generated',
            'credit_applied', 'plan_upgrade', 'plan_downgrade',
            'cancellation', 'subscription_renewal',
        ];

        $catalog = \ZeroBoiler\Analytics\Events\EventCatalog::class;
        $tracked = 0;

        foreach ($revenueEvents as $event) {
            if ($catalog::has($event)) {
                $tracked++;
            }
        }

        $total = count($revenueEvents);
        $score = $total > 0 ? round(($tracked / $total) * 100, 1) : 0.0;

        return [
            'score' => $score,
            'max' => 100,
            'weight' => self::WEIGHTS['revenue_events'],
            'status' => $score >= 80 ? 'healthy' : ($score >= 50 ? 'warning' : 'critical'),
            'details' => "{$tracked}/{$total} revenue events tracked in catalog",
        ];
    }

    /**
     * Assess subscription lifecycle coverage.
     *
     * @return array{score: float, max: int, weight: int, status: string, details: string}
     */
    private function assessSubscriptionLifecycle(): array
    {
        $lifecycleEvents = [
            'sign_up', 'login', 'start_trial', 'subscribe',
            'plan_upgrade', 'plan_downgrade', 'cancellation',
            'trial_converted', 'subscription_renewal',
        ];

        $catalog = \ZeroBoiler\Analytics\Events\EventCatalog::class;
        $tracked = 0;

        foreach ($lifecycleEvents as $event) {
            if ($catalog::has($event)) {
                $tracked++;
            }
        }

        $total = count($lifecycleEvents);
        $score = $total > 0 ? round(($tracked / $total) * 100, 1) : 0.0;

        return [
            'score' => $score,
            'max' => 100,
            'weight' => self::WEIGHTS['subscription_lifecycle'],
            'status' => $score >= 80 ? 'healthy' : ($score >= 50 ? 'warning' : 'critical'),
            'details' => "{$tracked}/{$total} subscription lifecycle events tracked",
        ];
    }

    /**
     * Assess e-commerce funnel coverage.
     *
     * @return array{score: float, max: int, weight: int, status: string, details: string}
     */
    private function assessEcommerceFunnel(): array
    {
        $funnelEvents = [
            'view_item', 'add_to_cart', 'view_cart', 'begin_checkout',
            'add_payment_info', 'purchase', 'refund',
        ];

        $catalog = \ZeroBoiler\Analytics\Events\EventCatalog::class;
        $tracked = 0;

        foreach ($funnelEvents as $event) {
            if ($catalog::has($event)) {
                $tracked++;
            }
        }

        $total = count($funnelEvents);
        $score = $total > 0 ? round(($tracked / $total) * 100, 1) : 0.0;

        return [
            'score' => $score,
            'max' => 100,
            'weight' => self::WEIGHTS['ecommerce_funnel'],
            'status' => $score >= 80 ? 'healthy' : ($score >= 50 ? 'warning' : 'critical'),
            'details' => "{$tracked}/{$total} e-commerce funnel events tracked",
        ];
    }

    /**
     * Assess billing signal coverage.
     *
     * @return array{score: float, max: int, weight: int, status: string, details: string}
     */
    private function assessBillingSignals(): array
    {
        $billingEvents = [
            'payment_succeeded', 'payment_failed', 'payment_method_added',
            'invoice_generated', 'billing_retry', 'credit_applied',
        ];

        $catalog = \ZeroBoiler\Analytics\Events\EventCatalog::class;
        $tracked = 0;

        foreach ($billingEvents as $event) {
            if ($catalog::has($event)) {
                $tracked++;
            }
        }

        $total = count($billingEvents);
        $score = $total > 0 ? round(($tracked / $total) * 100, 1) : 0.0;

        return [
            'score' => $score,
            'max' => 100,
            'weight' => self::WEIGHTS['billing_signals'],
            'status' => $score >= 80 ? 'healthy' : ($score >= 50 ? 'warning' : 'critical'),
            'details' => "{$tracked}/{$total} billing signal events tracked",
        ];
    }

    /**
     * Assess provider mapping coverage for revenue events.
     *
     * @return array{score: float, max: int, weight: int, status: string, details: string}
     */
    private function assessProviderCoverage(): array
    {
        $revenueEventNames = [
            'purchase', 'subscribe', 'refund', 'revenue_tracked',
            'payment_succeeded', 'plan_upgrade', 'cancellation',
        ];

        $catalog = \ZeroBoiler\Analytics\Events\EventCatalog::class;
        $providers = ['ga4', 'meta', 'posthog'];
        $totalChecks = count($revenueEventNames) * count($providers);
        $mappedCount = 0;

        foreach ($revenueEventNames as $eventName) {
            $entry = $catalog::get($eventName);

            if ($entry === null) {
                continue;
            }

            foreach ($providers as $provider) {
                if (isset($entry[$provider]) && $entry[$provider] !== null) {
                    $mappedCount++;
                }
            }
        }

        $score = $totalChecks > 0 ? round(($mappedCount / $totalChecks) * 100, 1) : 0.0;

        return [
            'score' => $score,
            'max' => 100,
            'weight' => self::WEIGHTS['provider_coverage'],
            'status' => $score >= 80 ? 'healthy' : ($score >= 50 ? 'warning' : 'critical'),
            'details' => "{$mappedCount}/{$totalChecks} revenue→provider mappings (ga4, meta, posthog)",
        ];
    }

    /**
     * Compute a letter grade from a numeric score.
     */
    private function computeGrade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            $score >= 50 => 'E',
            default => 'F',
        };
    }

    /**
     * Identify gaps from health dimensions.
     *
     * @param  array<string, array{score: float, status: string}>  $dimensions
     * @return list<string>
     */
    private function identifyGaps(array $dimensions): array
    {
        $gaps = [];

        foreach ($dimensions as $key => $dim) {
            if ($dim['status'] === 'critical') {
                $label = str_replace('_', ' ', $key);
                $gaps[] = "Critical: {$label} coverage is below 50%";
            } elseif ($dim['status'] === 'warning') {
                $label = str_replace('_', ' ', $key);
                $gaps[] = "Warning: {$label} coverage is below 80%";
            }
        }

        return $gaps;
    }

    /**
     * Generate actionable recommendations based on health score.
     *
     * @param  array<string, array{score: float, status: string}>  $dimensions
     * @param  list<string>  $gaps
     * @return list<string>
     */
    private function generateRecommendations(array $dimensions, array $gaps): array
    {
        if (empty($gaps)) {
            return ['Revenue analytics instrumentation is excellent. Consider adding expansion_revenue and contraction events for ARR tracking.'];
        }

        $recommendations = [];

        if (($dimensions['revenue_events']['score'] ?? 0) < 80) {
            $recommendations[] = 'Track all revenue-critical events: purchase, refund, subscribe, revenue_tracked, and billing events.';
        }

        if (($dimensions['subscription_lifecycle']['score'] ?? 0) < 80) {
            $recommendations[] = 'Complete the subscription lifecycle: sign_up → trial → subscribe → upgrade/downgrade → cancellation.';
        }

        if (($dimensions['ecommerce_funnel']['score'] ?? 0) < 80) {
            $recommendations[] = 'Instrument the full e-commerce funnel: view_item → add_to_cart → checkout → purchase.';
        }

        if (($dimensions['provider_coverage']['score'] ?? 0) < 80) {
            $recommendations[] = 'Ensure revenue events have GA4, Meta Pixel, and PostHog mappings for cross-provider consistency.';
        }

        return $recommendations;
    }

    /**
     * Invalidate the cached health score.
     */
    public function invalidateCache(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}
