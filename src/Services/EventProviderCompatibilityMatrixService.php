<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\Events\EventCatalog;
/**
 * Provider compatibility matrix service — validates event-to-provider mapping coverage.
 *
 * Analyzes the event catalog to identify gaps where events lack provider
 * mappings that would be expected for their category. For example, all
 * ecommerce events should have GA4 and Meta mappings; all SaaS events
 * should have GA4, PostHog, and Mixpanel mappings.
 *
 * Generates a per-event, per-provider coverage matrix with gap detection,
 * coverage scores, and actionable recommendations for improving provider
 * parity across the analytics pipeline.
 *
 * This is critical for SaaS teams that use multi-provider analytics
 * (GA4 + PostHog + Mixpanel) and want to ensure consistent event
 * coverage across all platforms.
 *
 * @since 142.0.0
 */
final class EventProviderCompatibilityMatrixService
{
    /**
     * Expected provider coverage rules per category.
     *
     * Defines which providers should have non-null mappings for events
     * in each category. Providers not listed are considered optional.
     *
     * @var array<string, list<string>>
     */
    private const EXPECTED_PROVIDERS = [
        'ecommerce' => ['ga4', 'meta', 'posthog', 'mixpanel', 'amplitude'],
        'saas' => ['ga4', 'posthog', 'mixpanel', 'amplitude'],
        'engagement' => ['ga4', 'posthog', 'mixpanel', 'amplitude'],
        'security' => ['ga4', 'posthog', 'mixpanel', 'amplitude'],
        'uptime' => ['ga4', 'posthog', 'mixpanel', 'amplitude'],
        'infrastructure' => ['ga4', 'posthog', 'mixpanel', 'amplitude'],
        'marketing' => ['ga4', 'meta', 'posthog', 'mixpanel', 'amplitude'],
        'customer_success' => ['ga4', 'posthog', 'mixpanel', 'amplitude'],
    ];

    /**
     * All tracked provider keys.
     *
     * @var list<string>
     */
    private const ALL_PROVIDERS = [
        'ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin',
    ];

    /**
     * Build the full provider compatibility matrix.
     *
     * Returns a per-event, per-provider coverage table showing which events
     * have mappings for each provider, with gap detection.
     *
     * @return array{matrix: array<string, array<string, bool|null>>, summary: array{total_events: int, total_gaps: int, coverage_by_provider: array<string, array{mapped: int, missing: int, coverage_pct: float}>, coverage_by_category: array<string, array{mapped: int, missing: int, coverage_pct: float}>, critical_gaps: list<array{event: string, category: string, provider: string, severity: string}>}, overall_coverage: float}
     */
    public function buildMatrix(): array
    {
        $allEvents = EventCatalog::all();
        $matrix = [];
        $gaps = [];

        $providerStats = [];
        $categoryStats = [];

        foreach (self::ALL_PROVIDERS as $provider) {
            $providerStats[$provider] = ['mapped' => 0, 'missing' => 0];
        }

        foreach (self::EXPECTED_PROVIDERS as $category => $expectedProviders) {
            $categoryStats[$category] = ['mapped' => 0, 'missing' => 0];
        }

        foreach ($allEvents as $name => $entry) {
            $category = $entry['category'] ?? 'unknown';
            $matrix[$name] = [];

            foreach (self::ALL_PROVIDERS as $provider) {
                $mapping = $entry[$provider] ?? null;
                $hasMapping = $mapping !== null;
                $matrix[$name][$provider] = $hasMapping ? true : null;

                if ($hasMapping) {
                    $providerStats[$provider]['mapped']++;
                } else {
                    $providerStats[$provider]['missing']++;
                }
            }

            $expected = self::EXPECTED_PROVIDERS[$category] ?? [];

            foreach ($expected as $provider) {
                $mapping = $entry[$provider] ?? null;

                if ($mapping !== null) {
                    $categoryStats[$category]['mapped']++;
                } else {
                    $categoryStats[$category]['missing']++;
                    $gaps[] = [
                        'event' => $name,
                        'category' => $category,
                        'provider' => $provider,
                        'severity' => $this->gapSeverity($category, $provider),
                    ];
                }
            }
        }

        $totalEvents = count($allEvents);

        foreach ($providerStats as $provider => &$stats) {
            $total = $stats['mapped'] + $stats['missing'];
            $stats['coverage_pct'] = $total > 0
                ? round(($stats['mapped'] / $total) * 100, 1)
                : 0.0;
        }
        unset($stats);

        foreach ($categoryStats as $category => &$stats) {
            $total = $stats['mapped'] + $stats['missing'];
            $stats['coverage_pct'] = $total > 0
                ? round(($stats['mapped'] / $total) * 100, 1)
                : 0.0;
        }
        unset($stats);

        usort($gaps, function (array $a, array $b): int {
            $severityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
            $aSeverity = $severityOrder[$a['severity']] ?? 4;
            $bSeverity = $severityOrder[$b['severity']] ?? 4;

            return $aSeverity <=> $bSeverity;
        });

        // Overall coverage: expected provider mappings that exist / total expected
        $totalExpectedMapped = 0;
        $totalExpectedTotal = 0;
        foreach ($categoryStats as $stats) {
            $totalExpectedMapped += $stats['mapped'];
            $totalExpectedTotal += $stats['mapped'] + $stats['missing'];
        }
        $overallCoverage = $totalExpectedTotal > 0
            ? round(($totalExpectedMapped / $totalExpectedTotal) * 100, 1)
            : 0.0;

        return [
            'matrix' => $matrix,
            'summary' => [
                'total_events' => $totalEvents,
                'total_gaps' => count($gaps),
                'coverage_by_provider' => $providerStats,
                'coverage_by_category' => $categoryStats,
                'critical_gaps' => $gaps,
                'overall_coverage' => $overallCoverage,
            ],
        ];
    }

    /**
     * Get coverage score for a specific event.
     *
     * @param  string  $eventName
     * @return array{event: string, category: string|null, providers: array<string, bool|null>, coverage: float, gaps: list<string>}
     */
    public function eventCoverage(string $eventName): array
    {
        $entry = EventCatalog::get($eventName);

        if ($entry === null) {
            return [
                'event' => $eventName,
                'category' => null,
                'providers' => [],
                'coverage' => 0.0,
                'gaps' => ['Event not found in catalog'],
            ];
        }

        $category = $entry['category'] ?? 'unknown';
        $expected = self::EXPECTED_PROVIDERS[$category] ?? [];
        $providers = [];
        $gaps = [];
        $mappedCount = 0;

        foreach (self::ALL_PROVIDERS as $provider) {
            $mapping = $entry[$provider] ?? null;
            $hasMapping = $mapping !== null;
            $providers[$provider] = $hasMapping;

            if ($hasMapping) {
                $mappedCount++;
            }

            if (in_array($provider, $expected, true) && ! $hasMapping) {
                $gaps[] = $provider;
            }
        }

        return [
            'event' => $eventName,
            'category' => $category,
            'providers' => $providers,
            'coverage' => count(self::ALL_PROVIDERS) > 0
                ? round(($mappedCount / count(self::ALL_PROVIDERS)) * 100, 1)
                : 0.0,
            'gaps' => $gaps,
        ];
    }

    /**
     * Get a summary of provider coverage for a specific provider.
     *
     * @param  string  $provider
     * @return array{provider: string, total_events: int, mapped: int, missing: int, coverage_pct: float, unmapped_events: list<string>}
     */
    public function providerCoverage(string $provider): array
    {
        $allEvents = EventCatalog::all();
        $mapped = 0;
        $missing = 0;
        $unmapped = [];

        foreach ($allEvents as $name => $entry) {
            $mapping = $entry[$provider] ?? null;

            if ($mapping !== null) {
                $mapped++;
            } else {
                $missing++;
                $unmapped[] = $name;
            }
        }

        $total = $mapped + $missing;

        return [
            'provider' => $provider,
            'total_events' => $total,
            'mapped' => $mapped,
            'missing' => $missing,
            'coverage_pct' => $total > 0 ? round(($mapped / $total) * 100, 1) : 0.0,
            'unmapped_events' => $unmapped,
        ];
    }

    /**
     * Determine the severity of a provider mapping gap.
     *
     * @param  string  $category
     * @param  string  $provider
     * @return 'critical'|'high'|'medium'|'low'
     */
    private function gapSeverity(string $category, string $provider): string
    {
        // GA4 is always critical (primary analytics provider)
        if ($provider === 'ga4') {
            return 'critical';
        }

        // Ecommerce events need Meta for conversion tracking
        if ($category === 'ecommerce' && $provider === 'meta') {
            return 'critical';
        }

        // PostHog is expected for product analytics
        if ($provider === 'posthog' && in_array($category, ['saas', 'engagement'], true)) {
            return 'high';
        }

        // Mixpanel/Amplitude for SaaS cohort analysis
        if (in_array($provider, ['mixpanel', 'amplitude'], true) && $category === 'saas') {
            return 'medium';
        }

        return 'low';
    }
}
