<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\CatalogVersionRecommendation;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Release changelog generator for the analytics event catalog.
 *
 * Consumes version recommendations from EventCatalogVersioningEngine and
 * produces structured changelog entries in multiple formats: Markdown,
 * JSON, and compact text. Supports cumulative changelog generation across
 * multiple version ranges.
 *
 * Output formats:
 *
 *   - **markdown**: Full GitHub/CONVENTIONAL_COMMITS style changelog
 *   - **json**: Machine-readable structured changelog
 *   - **compact**: Single-line summary for CI log output
 *   - **conventional**: Conventional Commits format (feat:, fix:, BREAKING CHANGE:)
 *
 * Inspired by: Conventional Commits, Keep a Changelog, semantic-release.
 *
 * @see \ZeroBoiler\Analytics\Services\EventCatalogVersioningEngine
 *
 * @since 216.0.0
 */
final class ReleaseChangelogGeneratorService
{
    /** @var string Cache key prefix for generated changelogs */
    private const CACHE_PREFIX = 'zb_changelog_';

    /** @var int Default cache TTL (7 days) */
    private const DEFAULT_TTL = 604800;

    private CacheRepository $cache;

    private EventCatalogVersioningEngine $versioningEngine;

    private int $ttl;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  EventCatalogVersioningEngine  $versioningEngine  Versioning engine
     * @param  int  $ttl  Cache TTL in seconds
     */
    public function __construct(
        CacheRepository $cache,
        EventCatalogVersioningEngine $versioningEngine,
        int $ttl = self::DEFAULT_TTL,
    ){
        $this->cache = $cache;
        $this->versioningEngine = $versioningEngine;
        $this->ttl = $ttl;
    }

    /**
     * Generate a changelog from a version recommendation.
     *
     * @param  CatalogVersionRecommendation  $recommendation  Version recommendation
     * @param  string  $format  Output format: 'markdown', 'json', 'compact', 'conventional'
     * @return string  Formatted changelog
     */
    public function generate(CatalogVersionRecommendation $recommendation, string $format = 'markdown'): string
    {
        return match ($format) {
            'markdown' => $this->formatMarkdown($recommendation),
            'json' => $this->formatJson($recommendation),
            'compact' => $this->formatCompact($recommendation),
            'conventional' => $this->formatConventional($recommendation),
            default => $this->formatMarkdown($recommendation),
        };
    }

    /**
     * Generate a full changelog entry with metadata.
     *
     * Includes version, date, change summary, and categorized changes.
     *
     * @param  CatalogVersionRecommendation  $recommendation
     * @return array{version: string, date: string, summary: array{major: int, minor: int, patch: int, total: int, has_breaking: bool}, changes: list<array<string, mixed>>, release_notes: string}
     */
    public function generateStructured(CatalogVersionRecommendation $recommendation): array
    {
        $changes = array_map(fn($c) => $c->toArray(), $recommendation->changes);

        return [
            'version' => $recommendation->nextVersion,
            'date' => date('Y-m-d'),
            'summary' => [
                'major' => $recommendation->summary['major'],
                'minor' => $recommendation->summary['minor'],
                'patch' => $recommendation->summary['patch'],
                'total' => array_sum($recommendation->summary),
                'has_breaking' => $recommendation->hasBreaking,
            ],
            'changes' => $changes,
            'release_notes' => $recommendation->releaseNotesOrEmpty(),
        ];
    }

    /**
     * Format changelog as Markdown.
     */
    private function formatMarkdown(CatalogVersionRecommendation $rec): string
    {
        $lines = [];

        $lines[] = "## [{$rec->nextVersion}] - " . date('Y-m-d');
        $lines[] = '';

        $total = array_sum($rec->summary);

        if ($total === 0) {
            $lines[] = 'No catalog changes.';
            $lines[] = '';

            return implode("\n", $lines);
        }

        // Breaking changes section
        $breaking = array_filter($rec->changes, fn($c) => $c->breaking);
        if ($breaking !== []) {
            $lines[] = '### ⚠ BREAKING CHANGES';
            $lines[] = '';
            foreach ($breaking as $change) {
                $lines[] = "- **{$change->eventName}**: {$change->description}";
            }
            $lines[] = '';
        }

        // Added events section
        $added = array_filter($rec->changes, fn($c) => $c->type === 'event_added');
        if ($added !== []) {
            $lines[] = '### Added';
            $lines[] = '';
            foreach ($added as $change) {
                $lines[] = "- `{$change->eventName}` — {$change->description}";
            }
            $lines[] = '';
        }

        // Changed section
        $changed = array_filter($rec->changes, fn($c) => in_array($c->type, ['category_changed', 'provider_mapping_changed'], true));
        if ($changed !== []) {
            $lines[] = '### Changed';
            $lines[] = '';
            foreach ($changed as $change) {
                $lines[] = "- `{$change->eventName}` — {$change->description}";
            }
            $lines[] = '';
        }

        // Provider mapping additions
        $providerAdded = array_filter($rec->changes, fn($c) => $c->type === 'provider_mapping_added');
        if ($providerAdded !== []) {
            $lines[] = '### Provider Mappings Added';
            $lines[] = '';
            foreach ($providerAdded as $change) {
                $lines[] = "- `{$change->eventName}` — {$change->description}";
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Format changelog as JSON.
     */
    private function formatJson(CatalogVersionRecommendation $rec): string
    {
        return json_encode($this->generateStructured($rec), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /**
     * Format changelog as compact single-line text for CI logs.
     */
    private function formatCompact(CatalogVersionRecommendation $rec): string
    {
        $total = array_sum($rec->summary);
        $parts = [];

        if ($rec->summary['major'] > 0) {
            $parts[] = "{$rec->summary['major']} breaking";
        }
        if ($rec->summary['minor'] > 0) {
            $parts[] = "{$rec->summary['minor']} added";
        }
        if ($rec->summary['patch'] > 0) {
            $parts[] = "{$rec->summary['patch']} changed";
        }

        $changeDesc = $parts !== [] ? implode(', ', $parts) : 'no changes';
        $flag = $rec->hasBreaking ? ' ⚠ BREAKING' : '';

        return "analytics-catalog@{$rec->nextVersion}: {$total} changes ({$changeDesc}){$flag}";
    }

    /**
     * Format changelog in Conventional Commits style.
     */
    private function formatConventional(CatalogVersionRecommendation $rec): string
    {
        $lines = [];

        foreach ($rec->changes as $change) {
            $type = match ($change->severity) {
                'major' => 'BREAKING CHANGE',
                'minor' => 'feat',
                'patch' => 'fix',
                default => 'chore',
            };

            $scope = $change->category ?? 'catalog';
            $desc = $change->description;

            $lines[] = "{$type}({$scope}): {$desc}";
        }

        if ($lines === []) {
            $lines[] = 'chore(catalog): no changes detected';
        }

        return implode("\n", $lines);
    }

    /**
     * Generate and cache a changelog for a specific version.
     *
     * @param  string  $version  Version string
     * @param  string  $format  Output format
     * @return string
     */
    public function generateForVersion(string $version, string $format = 'markdown'): string
    {
        $cacheKey = self::CACHE_PREFIX . $version . '.' . $format;

        /** @var string|null */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $recommendation = $this->versioningEngine->getLatestRecommendation();

        if ($recommendation === null) {
            $fallback = CatalogVersionRecommendation::noChange($version);

            $changelog = $this->generate($fallback, $format);
            $this->cache->put($cacheKey, $changelog, $this->ttl);

            return $changelog;
        }

        $changelog = $this->generate($recommendation, $format);
        $this->cache->put($cacheKey, $changelog, $this->ttl);

        return $changelog;
    }

    /**
     * Get catalog statistics for inclusion in changelogs.
     *
     * @return array{version: string, total_events: int, categories: array<string, int>, provider_coverage: array<string, int>}
     */
    public function catalogStats(): array
    {
        $catalog = EventCatalog::all();
        $categories = EventCatalog::categorySummary();
        $providers = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];
        $coverage = [];

        foreach ($providers as $p) {
            $names = match ($p) {
                'ga4' => EventCatalog::allGa4Names(),
                'meta' => EventCatalog::allMetaNames(),
                'posthog' => EventCatalog::allPosthogNames(),
                'plausible' => EventCatalog::allPlausibleNames(),
                'mixpanel' => EventCatalog::allMixpanelNames(),
                'amplitude' => EventCatalog::allAmplitudeNames(),
                'tiktok' => EventCatalog::allTikTokNames(),
                'linkedin' => EventCatalog::allLinkedInNames(),
                default => [],
            };
            $coverage[$p] = count($names);
        }

        return [
            'version' => AnalyticsEvent::VERSION,
            'total_events' => count($catalog),
            'categories' => $categories,
            'provider_coverage' => $coverage,
        ];
    }
}
