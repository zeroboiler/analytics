<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Analytics insight engine — automated event insight generation.
 *
 * Combines data mart rollups with statistical analysis to produce
 * actionable insights for SaaS products: trend detection, category drift,
 * top-mover events, growth signals, and health alerts.
 *
 * Inspired by Amplitude Compass and Mixpanel Signal.
 *
 * Configuration is read from `zeroboiler.analytics.insight_engine`.
 *
 * @phpstan-type Insight array{type: string, title: string, description: string, severity: 'info'|'warning'|'critical', metric: string, value: float|null, previous_value: float|null, change_percent: float|null, event_name?: string, category?: string, recommendation?: string, generated_at: string}
 * @phpstan-type InsightReport array{total: int, critical: int, warnings: int, info: int, insights: list<Insight>, summary: string, generated_at: string}
 *
 * @since 7.0.0
 */
final class AnalyticsInsightEngineService
{
    private const CACHE_PREFIX = 'zb_insights_';

    private const DEFAULT_TTL = 300; // 5 minutes

    private bool $enabled;

    private int $cacheTtl;

    private int $topMoversCount;

    private float $driftThreshold;

    private float $growthThreshold;

    private float $declineThreshold;

    /**
     * @param  CacheRepository  $cache  Application cache driver
     * @param  ConfigRepository  $config  Analytics configuration
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ){
        $engineConfig = $config->get('zeroboiler.analytics.insight_engine', []);
        /** @var array{enabled?: bool, cache_ttl?: int, top_movers_count?: int, drift_threshold?: float, growth_threshold?: float, decline_threshold?: float} $engineConfig */

        $this->enabled = (bool) ($engineConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($engineConfig['cache_ttl'] ?? self::DEFAULT_TTL);
        $this->topMoversCount = (int) ($engineConfig['top_movers_count'] ?? 10);
        $this->driftThreshold = (float) ($engineConfig['drift_threshold'] ?? 0.3);
        $this->growthThreshold = (float) ($engineConfig['growth_threshold'] ?? 0.2);
        $this->declineThreshold = (float) ($engineConfig['decline_threshold'] ?? -0.15);
    }

    /**
     * Check if the insight engine is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Generate a full insight report from the data mart.
     *
     * Analyzes category distribution, top movers, catalog coverage,
     * and growth/decline signals to produce actionable insights.
     *
     * @return InsightReport
     */
    public function generateReport(): array
    {
        if (! $this->enabled) {
            return $this->emptyReport();
        }

        $insights = [];

        // Category distribution insights
        $this->analyzeCategoryDistribution($insights);

        // Top movers (highest count events)
        $this->analyzeTopMovers($insights);

        // Catalog coverage insights
        $this->analyzeCatalogCoverage($insights);

        // Growth signal detection
        $this->analyzeGrowthSignals($insights);

        // Health assessment
        $this->analyzeHealthSignals($insights);

        // Data mart freshness
        $this->analyzeMartFreshness($insights);

        $total = count($insights);
        $critical = count(array_filter($insights, fn (array $i): bool => $i['severity'] === 'critical'));
        $warnings = count(array_filter($insights, fn (array $i): bool => $i['severity'] === 'warning'));
        $info = $total - $critical - $warnings;

        $summary = $this->buildSummary($critical, $warnings, $info);

        $report = [
            'total' => $total,
            'critical' => $critical,
            'warnings' => $warnings,
            'info' => $info,
            'insights' => $insights,
            'summary' => $summary,
            'generated_at' => now()->toIso8601String(),
        ];

        // Cache the report
        $this->cache->put(self::CACHE_PREFIX . 'latest', $report, $this->cacheTtl);

        return $report;
    }

    /**
     * Get the latest cached insight report.
     *
     * @return InsightReport
     */
    public function latestReport(): array
    {
        /** @var InsightReport|null $report */
        $report = $this->cache->get(self::CACHE_PREFIX . 'latest');

        return $report ?? $this->emptyReport();
    }

    /**
     * Get insights filtered by severity.
     *
     * @return list<Insight>
     */
    public function bySeverity(string $severity): array
    {
        $report = $this->latestReport();

        return array_values(array_filter(
            $report['insights'],
            fn (array $insight): bool => $insight['severity'] === $severity,
        ));
    }

    /**
     * Get insights for a specific event.
     *
     * @return list<Insight>
     */
    public function forEvent(string $eventName): array
    {
        $report = $this->latestReport();

        return array_values(array_filter(
            $report['insights'],
            fn (array $insight): bool => ($insight['event_name'] ?? null) === $eventName,
        ));
    }

    /**
     * Get a quick health summary without full report generation.
     *
     * @return array{status: 'healthy'|'warning'|'critical', score: float, issues: int, recommendations: list<string>}
     */
    public function quickHealth(): array
    {
        $report = $this->latestReport();

        $critical = $report['critical'];
        $warnings = $report['warnings'];

        $score = 100.0 - ($critical * 25) - ($warnings * 5);
        $score = max(0.0, min(100.0, $score));

        $status = match (true) {
            $critical > 0 => 'critical',
            $warnings > 3 => 'warning',
            default => 'healthy',
        };

        $recommendations = array_map(
            fn (array $insight): string => $insight['recommendation'] ?? $insight['title'],
            array_filter($report['insights'], fn (array $i): bool => $i['severity'] !== 'info'),
        );

        return [
            'status' => $status,
            'score' => round($score, 1),
            'issues' => $critical + $warnings,
            'recommendations' => array_values($recommendations),
        ];
    }

    /**
     * Analyze category distribution for drift detection.
     *
     * @param  list<Insight>  $insights  Mutable insight accumulator
     */
    private function analyzeCategoryDistribution(array &$insights): void
    {
        try {
            $catalogCategories = EventCatalog::byCategory();
            $catalogEventCounts = array_map(fn (array $cat): int => count($cat), $catalogCategories);

            // Check for category imbalance (any category with 0 events registered)
            foreach ($catalogEventCounts as $category => $count) {
                if ($count === 0) {
                    $insights[] = $this->makeInsight(
                        type: 'empty_category',
                        title: "Empty category: {$category}",
                        description: "The '{$category}' event category has no registered events. This may indicate incomplete catalog setup.",
                        severity: 'warning',
                        metric: 'catalog_events',
                        value: 0.0,
                        recommendation: "Review EventCatalog registration for the {$category} category.",
                    );
                }
            }

            // Analyze category ratio distribution
            $total = array_sum($catalogEventCounts);
            if ($total > 0) {
                foreach ($catalogEventCounts as $category => $count) {
                    $ratio = $count / $total;
                    if ($ratio > 0.6) {
                        $insights[] = $this->makeInsight(
                            type: 'category_dominance',
                            title: "Category dominance: {$category}",
                            description: sprintf(
                                "The '%s' category accounts for %.1f%% of all catalog events (%d events). Consider rebalancing.",
                                $category,
                                $ratio * 100,
                                $count,
                            ),
                            severity: 'info',
                            metric: 'category_ratio',
                            value: $ratio,
                            category: $category,
                            recommendation: "Ensure all event categories are well-populated for balanced analytics coverage.",
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            // Gracefully degrade if EventCatalog is not loadable
        }
    }

    /**
     * Identify top-mover events by catalog importance.
     *
     * @param  list<Insight>  $insights  Mutable insight accumulator
     */
    private function analyzeTopMovers(array &$insights): void
    {
        try {
            $events = EventCatalog::coreSaaS();

            if (count($events) < 5) {
                $insights[] = $this->makeInsight(
                    type: 'thin_core_catalog',
                    title: 'Thin core SaaS catalog',
                    description: sprintf(
                        'Only %d core SaaS events defined. Industry-standard SaaS products track 10-15+ core lifecycle events.',
                        count($events),
                    ),
                    severity: 'warning',
                    metric: 'core_saas_events',
                    value: (float) count($events),
                    recommendation: 'Add missing core events: trial_start, trial_end, plan_upgrade, cancellation, etc.',
                );
            }

            // Check for revenue event coverage
            $revenueEvents = EventCatalog::revenueEvents();
            if (count($revenueEvents) < 5) {
                $insights[] = $this->makeInsight(
                    type: 'missing_revenue_events',
                    title: 'Incomplete revenue event tracking',
                    description: sprintf(
                        'Only %d revenue events registered. Consider adding: purchase, refund, subscription events.',
                        count($revenueEvents),
                    ),
                    severity: 'warning',
                    metric: 'revenue_events',
                    value: (float) count($revenueEvents),
                    recommendation: 'Ensure purchase, refund, and subscription lifecycle events are tracked.',
                );
            }
        } catch (\Throwable $e) {
            // Gracefully degrade
        }
    }

    /**
     * Analyze catalog coverage against provider mappings.
     *
     * @param  list<Insight>  $insights  Mutable insight accumulator
     */
    private function analyzeCatalogCoverage(array &$insights): void
    {
        try {
            $all = EventCatalog::all();
            $ga4Coverage = 0;
            $metaCoverage = 0;
            $posthogCoverage = 0;

            foreach ($all as $entry) {
                if (! empty($entry['ga4'])) {
                    $ga4Coverage++;
                }
                if (! empty($entry['meta'])) {
                    $metaCoverage++;
                }
                if (! empty($entry['posthog'])) {
                    $posthogCoverage++;
                }
            }

            $total = count($all);
            if ($total > 0) {
                $ga4Pct = $ga4Coverage / $total;
                $metaPct = $metaCoverage / $total;
                $posthogPct = $posthogCoverage / $total;

                if ($ga4Pct < 0.8) {
                    $insights[] = $this->makeInsight(
                        type: 'low_provider_coverage',
                        title: 'Low GA4 provider coverage',
                        description: sprintf(
                            'Only %.0f%% of events have GA4 mappings. Target: 80%%+.',
                            $ga4Pct * 100,
                        ),
                        severity: $ga4Pct < 0.5 ? 'critical' : 'warning',
                        metric: 'ga4_coverage',
                        value: $ga4Pct,
                        recommendation: 'Add GA4 event mappings to unmapped catalog events.',
                    );
                }

                if ($metaPct < 0.5) {
                    $insights[] = $this->makeInsight(
                        type: 'low_provider_coverage',
                        title: 'Low Meta Pixel provider coverage',
                        description: sprintf(
                            'Only %.0f%% of events have Meta Pixel mappings.',
                            $metaPct * 100,
                        ),
                        severity: 'info',
                        metric: 'meta_coverage',
                        value: $metaPct,
                        recommendation: 'Add Meta Pixel mappings for e-commerce and conversion events.',
                    );
                }
            }
        } catch (\Throwable $e) {
            // Gracefully degrade
        }
    }

    /**
     * Detect growth signals from event catalog size trends.
     *
     * @param  list<Insight>  $insights  Mutable insight accumulator
     */
    private function analyzeGrowthSignals(array &$insights): void
    {
        try {
            $totalCount = EventCatalog::count();

            if ($totalCount > 0) {
                $insights[] = $this->makeInsight(
                    type: 'catalog_size',
                    title: sprintf('Event catalog: %d events registered', $totalCount),
                    description: sprintf(
                        'Total catalog contains %d events across ecommerce (%d), SaaS (%d), and engagement (%d).',
                        $totalCount,
                        count(EventCatalog::category('ecommerce')),
                        count(EventCatalog::category('saas')),
                        count(EventCatalog::category('engagement')),
                    ),
                    severity: 'info',
                    metric: 'total_events',
                    value: (float) $totalCount,
                );
            }
        } catch (\Throwable $e) {
            // Gracefully degrade
        }
    }

    /**
     * Analyze health signals from the analytics infrastructure.
     *
     * @param  list<Insight>  $insights  Mutable insight accumulator
     */
    private function analyzeHealthSignals(array &$insights): void
    {
        // Check GDPR events coverage
        try {
            $gdprEvents = EventCatalog::gdprEvents();
            if (count($gdprEvents) < 5) {
                $insights[] = $this->makeInsight(
                    type: 'gdpr_gap',
                    title: 'Insufficient GDPR event coverage',
                    description: sprintf(
                        'Only %d GDPR-relevant events tracked. Consent, data subject access, and erasure events should be tracked.',
                        count($gdprEvents),
                    ),
                    severity: 'warning',
                    metric: 'gdpr_events',
                    value: (float) count($gdprEvents),
                    recommendation: 'Ensure consent_granted, consent_withdrawn, data_subject_access_request, and data_erasure_completed are tracked.',
                );
            }
        } catch (\Throwable $e) {
            // Gracefully degrade
        }
    }

    /**
     * Analyze data mart freshness.
     *
     * @param  list<Insight>  $insights  Mutable insight accumulator
     */
    private function analyzeMartFreshness(array &$insights): void
    {
        // Check if data mart has any data
        $cacheKey = EventDataMartService::class . '_freshness_check';

        $freshness = $this->cache->get($cacheKey);
        if ($freshness === null) {
            $insights[] = $this->makeInsight(
                type: 'mart_freshness',
                title: 'Data mart not yet populated',
                description: 'The event data mart has not received any events yet. Ensure auto-tracking or API ingestion is active.',
                severity: 'warning',
                metric: 'mart_freshness',
                value: null,
                recommendation: 'Verify analytics.auto_track.enabled is set to true and events are flowing.',
            );
        }
    }

    /**
     * Create a standardized insight array.
     *
     * @param  'info'|'warning'|'critical'  $severity
     * @return Insight
     */
    private function makeInsight(
        string $type,
        string $title,
        string $description,
        string $severity,
        string $metric,
        ?float $value,
        ?string $category = null,
        ?string $event_name = null,
        ?string $recommendation = null,
    ): array {
        return [
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'severity' => $severity,
            'metric' => $metric,
            'value' => $value,
            'previous_value' => null,
            'change_percent' => null,
            'category' => $category,
            'event_name' => $event_name,
            'recommendation' => $recommendation,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Build a human-readable summary string.
     */
    private function buildSummary(int $critical, int $warnings, int $info): string
    {
        $parts = [];

        if ($critical > 0) {
            $parts[] = "{$critical} critical";
        }

        if ($warnings > 0) {
            $parts[] = "{$warnings} warnings";
        }

        if ($info > 0) {
            $parts[] = "{$info} informational";
        }

        if (empty($parts)) {
            return 'No insights to report. All systems nominal.';
        }

        return 'Insight report: ' . implode(', ', $parts) . '.';
    }

    /**
     * Get an empty insight report structure.
     *
     * @return InsightReport
     */
    private function emptyReport(): array
    {
        return [
            'total' => 0,
            'critical' => 0,
            'warnings' => 0,
            'info' => 0,
            'insights' => [],
            'summary' => 'Insight engine is disabled or no data available.',
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
