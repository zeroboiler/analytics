<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;

/**
 * Cross-provider event coverage analyzer.
 *
 * Scans all event catalogs (Ecommerce, SaaS, Engagement, and the unified
 * EventCatalog) and builds a coverage matrix showing which events have
 * provider-specific mappings (GA4, Meta, PostHog, Plausible, Mixpanel,
 * Amplitude, TikTok, LinkedIn) and which are missing mappings.
 *
 * Produces:
 *   - Per-category coverage summary (% events with all 8 provider mappings)
 *   - Per-provider event count (how many events each provider supports)
 *   - Gap list: events missing mappings for specific providers
 *   - Coverage parity score: 0-100% across all providers
 *
 * @since 181.0.0
 */
final class CrossProviderCoverageAnalyzer
{
    /** Provider fields in catalog entries */
    private const PROVIDER_FIELDS = [
        'ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin',
    ];

    /** @var list<string> Provider names for output */
    private const PROVIDER_NAMES = [
        'ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin',
    ];

    /**
     * Analyze cross-provider coverage for all event categories.
     *
     * @return array{overall_parity: float, categories: array<string, array{total: int, full_coverage: int, coverage_pct: float, provider_counts: array<string, int>, gaps: array<string, list<string>>}>, provider_summary: array<string, array{total_mapped: int, total_events: int, coverage_pct: float, unmapped: list<string>}>}
     */
    public function analyze(): array
    {
        $categories = [
            'ecommerce' => EcommerceEvents::all(),
            'saas' => SaaSEvents::all(),
            'engagement' => EngagementEvents::all(),
        ];

        $categoryResults = [];
        $providerTotals = [];
        $providerMapped = [];

        foreach (self::PROVIDER_NAMES as $provider) {
            $providerTotals[$provider] = 0;
            $providerMapped[$provider] = 0;
        }

        foreach ($categories as $categoryName => $events) {
            $categoryResult = $this->analyzeCategory($categoryName, $events);
            $categoryResults[$categoryName] = $categoryResult;

            // Accumulate provider counts
            foreach (self::PROVIDER_NAMES as $provider) {
                $providerTotals[$provider] += $categoryResult['total'];
                $providerMapped[$provider] += $categoryResult['provider_counts'][$provider];
            }
        }

        // Provider summary
        $providerSummary = [];
        foreach (self::PROVIDER_NAMES as $provider) {
            $total = $providerTotals[$provider];
            $mapped = $providerMapped[$provider];
            $pct = $total > 0 ? round(($mapped / $total) * 100, 1) : 0.0;

            $unmapped = [];
            foreach ($categories as $catName => $catEvents) {
                foreach ($catEvents as $eventName => $entry) {
                    $mapping = $entry[$provider] ?? null;
                    if ($mapping === null || $mapping === '') {
                        $unmapped[] = $catName . ':' . $eventName;
                    }
                }
            }

            $providerSummary[$provider] = [
                'total_mapped' => $mapped,
                'total_events' => $total,
                'coverage_pct' => $pct,
                'unmapped' => array_slice($unmapped, 0, 50), // Limit for performance
            ];
        }

        // Overall parity: average coverage across all providers
        $parities = [];
        foreach ($providerSummary as $provider => $summary) {
            $parities[] = $summary['coverage_pct'];
        }
        $overallParity = count($parities) > 0 ? round(array_sum($parities) / count($parities), 1) : 0.0;

        return [
            'overall_parity' => $overallParity,
            'categories' => $categoryResults,
            'provider_summary' => $providerSummary,
        ];
    }

    /**
     * Analyze a single category's coverage.
     *
     * @param  string  $categoryName
     * @param  array<string, array<string, mixed>>  $events
     * @return array{total: int, full_coverage: int, coverage_pct: float, provider_counts: array<string, int>, gaps: array<string, list<string>>}
     */
    private function analyzeCategory(string $categoryName, array $events): array
    {
        $total = count($events);
        $fullCoverage = 0;
        $providerCounts = [];
        $gaps = [];

        foreach (self::PROVIDER_NAMES as $provider) {
            $providerCounts[$provider] = 0;
        }

        foreach ($events as $eventName => $entry) {
            $eventGaps = [];
            $allMapped = true;

            foreach (self::PROVIDER_NAMES as $provider) {
                $mapping = $entry[$provider] ?? null;
                $hasMapping = $mapping !== null && $mapping !== '';

                if ($hasMapping) {
                    $providerCounts[$provider]++;
                } else {
                    $allMapped = false;
                    $eventGaps[] = $provider;
                }
            }

            if ($allMapped) {
                $fullCoverage++;
            }

            if (count($eventGaps) > 0) {
                $gaps[$eventName] = $eventGaps;
            }
        }

        $coveragePct = $total > 0 ? round(($fullCoverage / $total) * 100, 1) : 0.0;

        return [
            'total' => $total,
            'full_coverage' => $fullCoverage,
            'coverage_pct' => $coveragePct,
            'provider_counts' => $providerCounts,
            'gaps' => $gaps,
        ];
    }

    /**
     * Quick summary: which providers have the best/worst coverage.
     *
     * @return array{best: list<array{provider: string, coverage_pct: float}>, worst: list<array{provider: string, coverage_pct: float}>, average: float}
     */
    public function quickSummary(): array
    {
        $analysis = $this->analyze();
        $providers = $analysis['provider_summary'];

        $sorted = [];
        foreach ($providers as $name => $data) {
            $sorted[] = ['provider' => $name, 'coverage_pct' => $data['coverage_pct']];
        }
        usort($sorted, fn (array $a, array $b): int => $b['coverage_pct'] <=> $a['coverage_pct']);

        return [
            'best' => array_slice($sorted, 0, 3),
            'worst' => array_slice(array_reverse($sorted), 0, 3),
            'average' => $analysis['overall_parity'],
        ];
    }

    /**
     * Get events that are missing mappings for a specific provider.
     *
     * @param  string  $provider  One of: ga4, meta, posthog, plausible, mixpanel, amplitude, tiktok, linkedin
     * @return list<string>
     */
    public function missingForProvider(string $provider): array
    {
        if (! in_array($provider, self::PROVIDER_NAMES, true)) {
            return [];
        }

        $missing = [];

        foreach (EcommerceEvents::all() as $name => $entry) {
            $mapping = $entry[$provider] ?? null;
            if ($mapping === null || $mapping === '') {
                $missing[] = 'ecommerce:' . $name;
            }
        }
        foreach (SaaSEvents::all() as $name => $entry) {
            $mapping = $entry[$provider] ?? null;
            if ($mapping === null || $mapping === '') {
                $missing[] = 'saas:' . $name;
            }
        }
        foreach (EngagementEvents::all() as $name => $entry) {
            $mapping = $entry[$provider] ?? null;
            if ($mapping === null || $mapping === '') {
                $missing[] = 'engagement:' . $name;
            }
        }

        return $missing;
    }

    /**
     * Get the list of supported provider names.
     *
     * @return list<string>
     */
    public static function providerNames(): array
    {
        return self::PROVIDER_NAMES;
    }
}
