<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Provider gap analyzer — identifies which tracked events lack
 * provider coverage for specific analytics backends.
 *
 * Cross-references the list of tracked events against the event catalog's
 * per-provider mappings (GA4, Meta, PostHog, Plausible) to find events
 * that would be silently dropped when sent to providers without a mapping.
 *
 * Useful for admin dashboards, readiness checks, and provider onboarding.
 *
 * @since 7.1.0
 */
final class ProviderGapAnalyzer
{
    private CacheRepository $cache;

    private ConfigRepository $config;

    private AnalyticsManager $manager;

    /** @var int */
    private int $cacheTtl;

    private const SUPPORTED_PROVIDERS = ['ga4', 'meta', 'posthog', 'plausible'];

    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
        AnalyticsManager $manager,
    ){
        $this->cache = $cache;
        $this->config = $config;
        $this->manager = $manager;

        $recConfig = $config->get('zeroboiler.analytics.recommendations', []);
        /** @var array{cache_ttl?: int} $recConfig */
        $this->cacheTtl = (int) ($recConfig['cache_ttl'] ?? 300);
    }

    /**
     * Analyze provider coverage gaps for a set of tracked events.
     *
     * For each tracked event, checks whether it has a mapping in each
     * provider. Events without a mapping would need transformation or
     * would be dropped silently by that provider.
     *
     * @param  list<string>  $trackedEvents  Event names currently being tracked
     * @return array{providers: array<string, array{enabled: bool, mapped_count: int, gap_count: int, coverage_percent: float, gaps: list<string>}>, cross_provider_gaps: list<string>, summary: array{total_events: int, fully_covered: int, partial_coverage: int, no_coverage: int, overall_coverage_percent: float}}
     */
    public function analyze(array $trackedEvents = []): array
    {
        $cacheKey = 'zb_provider_gaps_' . hash('xxh128', implode(',', $trackedEvents));

        /** @var array|null $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Normalize to lowercase
        $tracked = array_map('strtolower', $trackedEvents);
        $trackedSet = array_flip($tracked);

        $enabledProviders = [
            'ga4' => $this->manager->ga4()->isEnabled(),
            'meta' => $this->manager->meta()->isEnabled(),
            'posthog' => $this->manager->posthog()->isEnabled(),
            'plausible' => $this->manager->plausible()->isEnabled(),
        ];

        $providers = [];
        $eventProviderCount = [];

        // Initialize per-event provider count
        foreach ($tracked as $eventName) {
            $eventProviderCount[$eventName] = 0;
        }

        foreach (self::SUPPORTED_PROVIDERS as $provider) {
            $isEnabled = $enabledProviders[$provider];
            $mapped = [];
            $gaps = [];

            foreach ($tracked as $eventName) {
                $entry = EventCatalog::get($eventName);

                if ($entry === null) {
                    // Event not in catalog — counted as a gap
                    $gaps[] = $eventName;
                    continue;
                }

                $mapping = $entry[$provider] ?? null;

                if ($mapping !== null && $mapping !== '') {
                    $mapped[] = $eventName;
                    $eventProviderCount[$eventName] = ($eventProviderCount[$eventName] ?? 0) + 1;
                } else {
                    $gaps[] = $eventName;
                }
            }

            $totalCount = count($tracked);
            $mappedCount = count($mapped);

            $providers[$provider] = [
                'enabled' => $isEnabled,
                'mapped_count' => $mappedCount,
                'gap_count' => count($gaps),
                'coverage_percent' => $totalCount > 0 ? round(($mappedCount / $totalCount) * 100, 1) : 0.0,
                'gaps' => $gaps,
            ];
        }

        // Find cross-provider gaps (events missing from ALL providers)
        $crossProviderGaps = [];
        foreach ($tracked as $eventName) {
            if (($eventProviderCount[$eventName] ?? 0) === 0) {
                $crossProviderGaps[] = $eventName;
            }
        }

        // Count fully covered (all providers), partial (some), and no coverage
        $fullyCovered = 0;
        $partialCoverage = 0;
        $noCoverage = 0;
        $enabledProviderCount = count(array_filter($enabledProviders));

        foreach ($tracked as $eventName) {
            $count = $eventProviderCount[$eventName] ?? 0;
            if ($count >= $enabledProviderCount && $enabledProviderCount > 0) {
                $fullyCovered++;
            } elseif ($count > 0) {
                $partialCoverage++;
            } else {
                $noCoverage++;
            }
        }

        $totalEvents = count($tracked);
        $overallCoverage = $totalEvents > 0
            ? round((($fullyCovered + ($partialCoverage * 0.5)) / $totalEvents) * 100, 1)
            : 0.0;

        $result = [
            'providers' => $providers,
            'cross_provider_gaps' => $crossProviderGaps,
            'summary' => [
                'total_events' => $totalEvents,
                'fully_covered' => $fullyCovered,
                'partial_coverage' => $partialCoverage,
                'no_coverage' => $noCoverage,
                'overall_coverage_percent' => $overallCoverage,
            ],
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Get events that have mappings for a specific provider.
     *
     * @param  list<string>  $trackedEvents
     * @param  'ga4'|'meta'|'posthog'|'plausible'  $provider
     * @return list<string>
     */
    public function mappedEvents(array $trackedEvents, string $provider): array
    {
        $mapped = [];

        foreach ($trackedEvents as $eventName) {
            $entry = EventCatalog::get($eventName);
            if ($entry === null) {
                continue;
            }

            $mapping = $entry[$provider] ?? null;
            if ($mapping !== null && $mapping !== '') {
                $mapped[] = $eventName;
            }
        }

        return $mapped;
    }

    /**
     * Get events missing mappings for a specific provider.
     *
     * @param  list<string>  $trackedEvents
     * @param  'ga4'|'meta'|'posthog'|'plausible'  $provider
     * @return list<string>
     */
    public function gapEvents(array $trackedEvents, string $provider): array
    {
        $gaps = [];

        foreach ($trackedEvents as $eventName) {
            $entry = EventCatalog::get($eventName);
            if ($entry === null) {
                $gaps[] = $eventName;
                continue;
            }

            $mapping = $entry[$provider] ?? null;
            if ($mapping === null || $mapping === '') {
                $gaps[] = $eventName;
            }
        }

        return $gaps;
    }

    /**
     * Get the list of supported provider names.
     *
     * @return list<string>
     */
    public function supportedProviders(): array
    {
        return self::SUPPORTED_PROVIDERS;
    }
}
