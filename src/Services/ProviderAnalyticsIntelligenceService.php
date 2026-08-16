<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Provider Analytics Intelligence Service.
 *
 * Aggregates provider-level analytics intelligence across GA4, Meta Pixel,
 * PostHog, and Plausible. Analyzes mapping quality, coverage gaps,
 * category distribution, and generates provider-specific recommendations.
 *
 * Designed to help SaaS teams optimize multi-provider event tracking
 * by identifying where provider mapping gaps exist and which events
 * would benefit from additional provider coverage.
 *
 * @since 9.6.0
 */
final class ProviderAnalyticsIntelligenceService
{
    /**
     * Supported provider names.
     *
     * @var list<string>
     */
    private const PROVIDERS = ['ga4', 'meta', 'posthog', 'plausible'];

    /**
     * Ideal provider coverage thresholds per category.
     *
     * @var array<string, float>
     */
    private const COVERAGE_TARGETS = [
        'ecommerce' => 0.9,
        'saas' => 0.7,
        'engagement' => 0.6,
    ];

    private ?CacheRepository $cache;

    private int $cacheTtl;

    /**
     * @param  CacheRepository|null  $cache  Optional cache repository
     * @param  int  $cacheTtl  Cache TTL in seconds (default: 300)
     */
    public function __construct(
        ?CacheRepository $cache = null,
        int $cacheTtl = 300,
    ): void {
        $this->cache = $cache;
        $this->cacheTtl = $cacheTtl;
    }

    /**
     * Generate a comprehensive provider intelligence report.
     *
     * @return array{providers: array<string, array{coverage: float, total_events: int, mapped_events: int, category_coverage: array<string, float>, gaps: list<string>, recommendations: list<string>}>, summary: array{best_provider: string, weakest_provider: string, avg_coverage: float, total_events: int}}
     */
    public function report(): array
    {
        $cacheKey = 'zb_analytics_provider_intelligence';
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $allEvents = EventCatalog::all();
        $totalEvents = count($allEvents);
        $providerData = [];
        $bestProvider = '';
        $weakestProvider = '';
        $bestCoverage = -1.0;
        $weakestCoverage = PHP_FLOAT_MAX;

        foreach (self::PROVIDERS as $provider) {
            $mapped = 0;
            $categoryMapped = ['ecommerce' => 0, 'saas' => 0, 'engagement' => 0];
            $categoryTotal = ['ecommerce' => 0, 'saas' => 0, 'engagement' => 0];
            $gaps = [];
            $recommendations = [];

            foreach ($allEvents as $name => $entry) {
                $category = $entry['category'] ?? 'unknown';
                $categoryTotal[$category] = ($categoryTotal[$category] ?? 0) + 1;

                $mappedName = $entry[$provider] ?? null;

                if ($mappedName !== null && $mappedName !== '') {
                    $mapped++;
                    $categoryMapped[$category] = ($categoryMapped[$category] ?? 0) + 1;
                } else {
                    // Track coverage gaps
                    if (in_array($category, ['ecommerce', 'saas'], true)) {
                        $gaps[] = $name;
                    }
                }
            }

            $coverage = $totalEvents > 0 ? round($mapped / $totalEvents, 4) : 0.0;
            $categoryCoverage = [];

            foreach (['ecommerce', 'saas', 'engagement'] as $cat) {
                $catTotal = $categoryTotal[$cat] ?? 0;
                $categoryCoverage[$cat] = $catTotal > 0
                    ? round(($categoryMapped[$cat] ?? 0) / $catTotal, 4)
                    : 0.0;
            }

            // Generate provider-specific recommendations
            $recommendations = $this->generateRecommendations($provider, $coverage, $categoryCoverage, $gaps);

            if ($coverage > $bestCoverage) {
                $bestCoverage = $coverage;
                $bestProvider = $provider;
            }

            if ($coverage < $weakestCoverage) {
                $weakestCoverage = $coverage;
                $weakestProvider = $provider;
            }

            $providerData[$provider] = [
                'coverage' => $coverage,
                'total_events' => $totalEvents,
                'mapped_events' => $mapped,
                'category_coverage' => $categoryCoverage,
                'gaps' => $gaps,
                'recommendations' => $recommendations,
            ];
        }

        $coverages = array_map(fn (array $p): float => $p['coverage'], $providerData);
        $avgCoverage = count($coverages) > 0
            ? round(array_sum($coverages) / count($coverages), 4)
            : 0.0;

        $result = [
            'providers' => $providerData,
            'summary' => [
                'best_provider' => $bestProvider,
                'weakest_provider' => $weakestProvider,
                'avg_coverage' => $avgCoverage,
                'total_events' => $totalEvents,
            ],
        ];

        if ($this->cache !== null) {
            $this->cache->put($cacheKey, $result, $this->cacheTtl);
        }

        return $result;
    }

    /**
     * Get mapping quality analysis for a specific provider.
     *
     * Analyzes the quality of event name mappings for a provider:
     * how many events have meaningful (non-identity) mappings vs. passthrough.
     *
     * @return array{provider: string, coverage: float, meaningful_mappings: int, passthrough_mappings: int, quality_score: float, category_breakdown: array<string, array{mapped: int, total: int, coverage: float}>}
     */
    public function providerQuality(string $provider): array
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            return [
                'provider' => $provider,
                'coverage' => 0.0,
                'meaningful_mappings' => 0,
                'passthrough_mappings' => 0,
                'quality_score' => 0.0,
                'category_breakdown' => [],
            ];
        }

        $allEvents = EventCatalog::all();
        $mapped = 0;
        $meaningful = 0;
        $passthrough = 0;
        $categoryBreakdown = [];

        foreach ($allEvents as $name => $entry) {
            $category = $entry['category'] ?? 'unknown';

            if (! isset($categoryBreakdown[$category])) {
                $categoryBreakdown[$category] = ['mapped' => 0, 'total' => 0];
            }
            $categoryBreakdown[$category]['total']++;

            $mappedName = $entry[$provider] ?? null;

            if ($mappedName !== null && $mappedName !== '') {
                $mapped++;
                $categoryBreakdown[$category]['mapped']++;

                // A meaningful mapping differs from the canonical name
                if ($mappedName !== $name) {
                    $meaningful++;
                } else {
                    $passthrough++;
                }
            }
        }

        $total = count($allEvents);
        $coverage = $total > 0 ? round($mapped / $total, 4) : 0.0;
        $qualityScore = $mapped > 0 ? round($meaningful / $mapped, 4) : 0.0;

        // Compute per-category coverage
        foreach ($categoryBreakdown as $cat => &$data) {
            $data['coverage'] = $data['total'] > 0
                ? round($data['mapped'] / $data['total'], 4)
                : 0.0;
        }
        unset($data);

        return [
            'provider' => $provider,
            'coverage' => $coverage,
            'meaningful_mappings' => $meaningful,
            'passthrough_mappings' => $passthrough,
            'quality_score' => $qualityScore,
            'category_breakdown' => $categoryBreakdown,
        ];
    }

    /**
     * Identify events that would benefit most from being added to a specific provider.
     *
     * @return list<array{event: string, category: string, other_provider_count: int, suggested_name: string|null}>
     */
    public function coverageOpportunities(string $provider, int $limit = 20): array
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            return [];
        }

        $allEvents = EventCatalog::all();
        $opportunities = [];

        foreach ($allEvents as $name => $entry) {
            $mappedName = $entry[$provider] ?? null;

            // Already mapped — skip
            if ($mappedName !== null && $mappedName !== '') {
                continue;
            }

            // Count how many other providers map this event
            $otherCount = 0;
            foreach (self::PROVIDERS as $p) {
                if ($p === $provider) {
                    continue;
                }
                $other = $entry[$p] ?? null;
                if ($other !== null && $other !== '') {
                    $otherCount++;
                }
            }

            // Skip if no other provider maps this event either
            if ($otherCount === 0) {
                continue;
            }

            // Suggest a name from the most common mapping pattern
            $suggestedName = $this->suggestProviderName($name, $entry, $provider);

            $opportunities[] = [
                'event' => $name,
                'category' => $entry['category'] ?? 'unknown',
                'other_provider_count' => $otherCount,
                'suggested_name' => $suggestedName,
            ];
        }

        // Sort by other provider count descending
        usort($opportunities, fn (array $a, array $b): int => $b['other_provider_count'] <=> $a['other_provider_count']);

        return array_slice($opportunities, 0, $limit);
    }

    /**
     * Get a cross-provider mapping matrix for all catalog events.
     *
     * Returns a matrix showing which events are mapped to which providers,
     * useful for dashboard heatmap visualization.
     *
     * @return array{matrix: array<string, array<string, bool|null>>, providers: list<string>, total: int, per_provider_counts: array<string, int>}
     */
    public function mappingMatrix(): array
    {
        $allEvents = EventCatalog::all();
        $matrix = [];
        $perProviderCounts = array_fill_keys(self::PROVIDERS, 0);

        foreach ($allEvents as $name => $entry) {
            $matrix[$name] = [];

            foreach (self::PROVIDERS as $provider) {
                $mapped = $entry[$provider] ?? null;
                $matrix[$name][$provider] = $mapped !== null && $mapped !== '';

                if ($matrix[$name][$provider]) {
                    $perProviderCounts[$provider]++;
                }
            }
        }

        return [
            'matrix' => $matrix,
            'providers' => self::PROVIDERS,
            'total' => count($allEvents),
            'per_provider_counts' => $perProviderCounts,
        ];
    }

    /**
     * Get provider coverage trend recommendations.
     *
     * Analyzes current coverage and generates prioritized recommendations
     * for improving multi-provider tracking coverage.
     *
     * @return array{critical_gaps: list<string>, quick_wins: list<string>, category_priorities: array<string, list<string>>, overall_grade: string}
     */
    public function recommendations(): array
    {
        $report = $this->report();
        $criticalGaps = [];
        $quickWins = [];
        $categoryPriorities = [
            'ecommerce' => [],
            'saas' => [],
            'engagement' => [],
        ];

        // Find events with minimal provider coverage (1 or fewer providers)
        foreach (EventCatalog::all() as $name => $entry) {
            $providerCount = 0;
            foreach (self::PROVIDERS as $p) {
                $mapped = $entry[$p] ?? null;
                if ($mapped !== null && $mapped !== '') {
                    $providerCount++;
                }
            }

            $category = $entry['category'] ?? 'unknown';

            if ($providerCount === 0 && in_array($category, ['ecommerce', 'saas'], true)) {
                $criticalGaps[] = $name;
            }

            if ($providerCount === 1 && in_array($category, ['ecommerce', 'saas'], true)) {
                $quickWins[] = $name;
            }

            if (isset($categoryPriorities[$category]) && $providerCount < 3) {
                $categoryPriorities[$category][] = $name;
            }
        }

        $avgCoverage = $report['summary']['avg_coverage'] ?? 0.0;
        $overallGrade = match (true) {
            $avgCoverage >= 0.90 => 'A+ (Comprehensive)',
            $avgCoverage >= 0.75 => 'A (Strong)',
            $avgCoverage >= 0.60 => 'B+ (Good)',
            $avgCoverage >= 0.45 => 'B (Adequate)',
            $avgCoverage >= 0.30 => 'C+ (Developing)',
            $avgCoverage >= 0.15 => 'C (Basic)',
            default => 'D (Needs Improvement)',
        };

        return [
            'critical_gaps' => $criticalGaps,
            'quick_wins' => $quickWins,
            'category_priorities' => $categoryPriorities,
            'overall_grade' => $overallGrade,
        ];
    }

    /**
     * Clear cached intelligence data.
     */
    public function clearCache(): void
    {
        if ($this->cache !== null) {
            $this->cache->forget('zb_analytics_provider_intelligence');
        }
    }

    /**
     * Generate provider-specific recommendations.
     *
     * @param  array<string, float>  $categoryCoverage
     * @param  list<string>  $gaps
     * @return list<string>
     */
    private function generateRecommendations(string $provider, float $coverage, array $categoryCoverage, array $gaps): array
    {
        $recommendations = [];

        if ($coverage < 0.5) {
            $recommendations[] = "Critical: Only {$this->pct($coverage)} of events mapped — consider expanding {$provider} event coverage.";
        } elseif ($coverage < 0.7) {
            $recommendations[] = "Moderate coverage at {$this->pct($coverage)} — focus on high-value SaaS and ecommerce events.";
        }

        // Category-specific recommendations
        $targetEcom = self::COVERAGE_TARGETS['ecommerce'];
        if (($categoryCoverage['ecommerce'] ?? 0) < $targetEcom) {
            $missing = $this->countCategoryGaps($gaps, 'ecommerce');
            $recommendations[] = "Ecommerce coverage gap: {$this->pct($categoryCoverage['ecommerce'] ?? 0)} vs {$this->pct($targetEcom)} target ({$missing} unmapped).";
        }

        $targetSaas = self::COVERAGE_TARGETS['saas'];
        if (($categoryCoverage['saas'] ?? 0) < $targetSaas) {
            $missing = $this->countCategoryGaps($gaps, 'saas');
            $recommendations[] = "SaaS coverage gap: {$this->pct($categoryCoverage['saas'] ?? 0)} vs {$this->pct($targetSaas)} target ({$missing} unmapped).";
        }

        return $recommendations;
    }

    /**
     * Count unmapped events for a specific category from gaps list.
     *
     * @param  list<string>  $gaps
     */
    private function countCategoryGaps(array $gaps, string $category): int
    {
        $count = 0;

        foreach ($gaps as $eventName) {
            $entry = EventCatalog::get($eventName);
            if ($entry !== null && ($entry['category'] ?? null) === $category) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Suggest a provider event name based on other providers' mappings.
     *
     * @param  array<string, mixed>  $entry
     */
    private function suggestProviderName(string $name, array $entry, string $targetProvider): ?string
    {
        // Try to find a consistent mapping pattern from other providers
        $mappings = [];

        foreach (self::PROVIDERS as $p) {
            if ($p === $targetProvider) {
                continue;
            }
            $mapped = $entry[$p] ?? null;
            if ($mapped !== null && $mapped !== '' && $mapped !== $name) {
                $mappings[] = $mapped;
            }
        }

        // Return the first non-identity mapping as a suggestion
        return $mappings[0] ?? null;
    }

    /**
     * Format a float as a percentage string.
     */
    private function pct(float $value): string
    {
        return round($value * 100, 1) . '%';
    }
}
