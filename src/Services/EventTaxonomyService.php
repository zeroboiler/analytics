<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Tag-based event taxonomy service for granular event classification.
 *
 * Extends the category-based classification (ecommerce, saas, engagement)
 * with a flexible tagging system. Events can be tagged by:
 * - Business unit (marketing, product, engineering, sales)
 * - Feature area (billing, auth, onboarding, dashboard)
 * - Product line (core, addon, enterprise)
 * - Custom tags (any string)
 *
 * Tags are config-driven and persisted in cache for fast lookup.
 * Used by the analytics dashboard for filtered views and reporting.
 *
 * @version 5.0.0
 */
final class EventTaxonomyService
{
    /** @var int Default TTL for tag index (seconds) */
    private const DEFAULT_TTL = 3600;

    private const CACHE_PREFIX = 'zb_taxonomy_';

    private CacheRepository $cache;

    /** @var array<string, list<string>> Config-driven tag assignments */
    private array $tagMap;

    /** @var array<string, list<string>> Runtime-added tag assignments */
    private array $dynamicTags = [];

    private int $ttl;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  array<string, list<string>>  $tagMap  Event name → tags mapping from config
     * @param  int  $ttl  TTL for cached tag lookups
     */
    public function __construct(
        CacheRepository $cache,
        array $tagMap = [],
        int $ttl = self::DEFAULT_TTL,
    ): void {
        $this->cache = $cache;
        $this->tagMap = $tagMap;
        $this->ttl = $ttl;
    }

    /**
     * Get all tags for an event.
     *
     * Merges config-driven tags with dynamically added tags.
     *
     * @param  string  $eventName  Event name
     * @return list<string> Tag list
     */
    public function getTags(string $eventName): array
    {
        $configTags = $this->tagMap[$eventName] ?? [];
        $dynamicTags = $this->dynamicTags[$eventName] ?? [];

        return array_values(array_unique(array_merge($configTags, $dynamicTags)));
    }

    /**
     * Check if an event has a specific tag.
     *
     * @param  string  $eventName  Event name
     * @param  string  $tag  Tag to check
     */
    public function hasTag(string $eventName, string $tag): bool
    {
        return in_array($tag, $this->getTags($eventName), true);
    }

    /**
     * Add tags to an event at runtime.
     *
     * @param  string  $eventName  Event name
     * @param  list<string>  $tags  Tags to add
     */
    public function addTags(string $eventName, array $tags): void
    {
        $existing = $this->dynamicTags[$eventName] ?? [];
        $this->dynamicTags[$eventName] = array_values(array_unique(array_merge($existing, $tags)));

        $this->invalidateCache($eventName);
    }

    /**
     * Remove tags from an event at runtime.
     *
     * @param  string  $eventName  Event name
     * @param  list<string>  $tags  Tags to remove
     */
    public function removeTags(string $eventName, array $tags): void
    {
        $existing = $this->dynamicTags[$eventName] ?? [];
        $this->dynamicTags[$eventName] = array_values(array_diff($existing, $tags));

        $this->invalidateCache($eventName);
    }

    /**
     * Get all events with a specific tag.
     *
     * Searches both config-driven and dynamic tags.
     *
     * @param  string  $tag  Tag to search for
     * @return list<string> Event names
     */
    public function getEventsWithTag(string $tag): array
    {
        $events = [];

        // Search config tags
        foreach ($this->tagMap as $eventName => $tags) {
            if (in_array($tag, $tags, true)) {
                $events[] = $eventName;
            }
        }

        // Search dynamic tags
        foreach ($this->dynamicTags as $eventName => $tags) {
            if (in_array($tag, $tags, true) && ! in_array($eventName, $events, true)) {
                $events[] = $eventName;
            }
        }

        return $events;
    }

    /**
     * Get all unique tags across all events.
     *
     * @return list<string>
     */
    public function getAllTags(): array
    {
        $tags = [];

        foreach ($this->tagMap as $eventTags) {
            foreach ($eventTags as $tag) {
                $tags[$tag] = true;
            }
        }

        foreach ($this->dynamicTags as $eventTags) {
            foreach ($eventTags as $tag) {
                $tags[$tag] = true;
            }
        }

        return array_keys($tags);
    }

    /**
     * Get all tag names grouped by tag type/prefix.
     *
     * Tags can use dot notation for namespacing (e.g. 'billing.renewal').
     * This groups tags by their prefix.
     *
     * @return array<string, list<string>>
     */
    public function getTagsByGroup(): array
    {
        $allTags = $this->getAllTags();
        $groups = [];

        foreach ($allTags as $tag) {
            $parts = explode('.', $tag, 2);

            if (count($parts) === 2) {
                $groups[$parts[0]][] = $tag;
            } else {
                $groups['general'][] = $tag;
            }
        }

        return $groups;
    }

    /**
     * Get a tag summary with event counts per tag.
     *
     * @return array<string, array{events: list<string>, count: int}>
     */
    public function getTagSummary(): array
    {
        $allTags = $this->getAllTags();
        $summary = [];

        foreach ($allTags as $tag) {
            $events = $this->getEventsWithTag($tag);
            $summary[$tag] = [
                'events' => $events,
                'count' => count($events),
            ];
        }

        // Sort by count descending
        uasort($summary, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $summary;
    }

    /**
     * Auto-classify all catalog events with taxonomy tags.
     *
     * Uses the event name, category, and provider mappings to
     * generate intelligent default tags for every catalog event.
     *
     * @return array{classified: int, tags_applied: int, events: array<string, list<string>>}
     */
    public function autoClassify(): array
    {
        $all = EventCatalog::all();
        $classified = 0;
        $tagsApplied = 0;
        $result = [];

        foreach ($all as $name => $entry) {
            $tags = $this->inferTags($name, $entry['category'] ?? '');

            if ($tags !== []) {
                $classified++;
                $tagsApplied += count($tags);
                $this->dynamicTags[$name] = $tags;
                $result[$name] = $tags;
            }
        }

        return [
            'classified' => $classified,
            'tags_applied' => $tagsApplied,
            'events' => $result,
        ];
    }

    /**
     * Get events filtered by multiple tags (AND logic).
     *
     * Returns events that have ALL specified tags.
     *
     * @param  list<string>  $tags  Tags to filter by
     * @return list<string> Event names matching all tags
     */
    public function getEventsWithAllTags(array $tags): array
    {
        if ($tags === []) {
            return [];
        }

        $candidates = $this->getEventsWithTag($tags[0]);

        for ($i = 1; $i < count($tags); $i++) {
            $nextCandidates = $this->getEventsWithTag($tags[$i]);
            $candidates = array_values(array_intersect($candidates, $nextCandidates));

            if ($candidates === []) {
                return [];
            }
        }

        return $candidates;
    }

    /**
     * Get events filtered by any of the given tags (OR logic).
     *
     * @param  list<string>  $tags  Tags to filter by
     * @return list<string> Event names matching any tag
     */
    public function getEventsWithAnyTag(array $tags): array
    {
        $events = [];

        foreach ($tags as $tag) {
            $tagged = $this->getEventsWithTag($tag);
            foreach ($tagged as $eventName) {
                $events[$eventName] = true;
            }
        }

        return array_keys($events);
    }

    /**
     * Infer tags for an event based on name and category.
     *
     * @param  string  $eventName  Event name
     * @param  string  $category  Event category
     * @return list<string> Inferred tags
     */
    private function inferTags(string $eventName, string $category): array
    {
        $tags = [$category];

        // Name-based inference
        if (str_contains($eventName, 'payment') || str_contains($eventName, 'billing') || str_contains($eventName, 'invoice')) {
            $tags[] = 'billing';
        }

        if (str_contains($eventName, 'trial')) {
            $tags[] = 'onboarding';
            $tags[] = 'conversion';
        }

        if (str_contains($eventName, 'signup') || str_contains($eventName, 'register')) {
            $tags[] = 'acquisition';
            $tags[] = 'onboarding';
        }

        if (str_contains($eventName, 'login') || str_contains($eventName, 'auth')) {
            $tags[] = 'authentication';
        }

        if (str_contains($eventName, 'plan') || str_contains($eventName, 'subscription')) {
            $tags[] = 'revenue';
            $tags[] = 'billing';
        }

        if (str_contains($eventName, 'purchase') || str_contains($eventName, 'cart') || str_contains($eventName, 'checkout')) {
            $tags[] = 'revenue';
            $tags[] = 'conversion';
        }

        if (str_contains($eventName, 'refund') || str_contains($eventName, 'cancel')) {
            $tags[] = 'churn';
        }

        if (str_contains($eventName, 'error') || str_contains($eventName, 'fail')) {
            $tags[] = 'health';
        }

        if (str_contains($eventName, 'feature') || str_contains($eventName, 'integration')) {
            $tags[] = 'product';
        }

        if (str_contains($eventName, 'team') || str_contains($eventName, 'workspace') || str_contains($eventName, 'invite')) {
            $tags[] = 'collaboration';
        }

        if (str_contains($eventName, 'consent') || str_contains($eventName, 'gdpr') || str_contains($eventName, 'erasure')) {
            $tags[] = 'compliance';
        }

        if (str_contains($eventName, 'page_view') || str_contains($eventName, 'scroll') || str_contains($eventName, 'click')) {
            $tags[] = 'engagement';
        }

        if (str_contains($eventName, 'cohort')) {
            $tags[] = 'segmentation';
        }

        return array_values(array_unique($tags));
    }

    /**
     * Invalidate cached tag data for an event.
     */
    private function invalidateCache(string $eventName): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'tags_' . $eventName);
        $this->cache->forget(self::CACHE_PREFIX . 'summary');
    }
}
