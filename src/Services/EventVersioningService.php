<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;

/**
 * Event Versioning Service — catalog-level version metadata enrichment.
 *
 * Provides since-version, stability, and category metadata for every event
 * in the catalog. Used by admin dashboards, API responses, and the
 * deprecation service to enforce version-aware event policies.
 *
 * @since 44.0.0
 */
final class EventVersioningService
{
    /**
     * Category introduction versions.
     *
     * @var array<string, string>
     */
    private const CATEGORY_SINCE = [
        'ecommerce' => '1.0.0',
        'saas' => '1.0.0',
        'engagement' => '1.0.0',
        'security' => '9.9.0',
        'uptime' => '9.9.0',
    ];

    /**
     * Get version metadata for a single event.
     *
     * @return array{name: string, since: string, category: string, stability: string, in_catalog: bool}|null
     */
    public function getEventVersion(string $eventName): ?array
    {
        $entry = EventCatalog::get($eventName);

        if ($entry === null) {
            return null;
        }

        $category = $entry['category'] ?? 'unknown';

        return [
            'name' => $eventName,
            'since' => self::CATEGORY_SINCE[$category] ?? '1.0.0',
            'category' => $category,
            'stability' => 'stable',
            'in_catalog' => true,
        ];
    }

    /**
     * Get version metadata for all events in the catalog.
     *
     * @return array<string, array{name: string, since: string, category: string, stability: string}>
     */
    public function getAllEventVersions(): array
    {
        $versions = [];
        $all = EventCatalog::all();

        foreach ($all as $name => $entry) {
            $category = $entry['category'] ?? 'unknown';
            $versions[$name] = [
                'name' => $name,
                'since' => self::CATEGORY_SINCE[$category] ?? '1.0.0',
                'category' => $category,
                'stability' => 'stable',
            ];
        }

        return $versions;
    }

    /**
     * Get version summary statistics.
     *
     * @return array{total_events: int, categories: array<string, int>, category_versions: array<string, string>}
     */
    public function versionSummary(): array
    {
        $byCategory = EventCatalog::byCategory();
        $categories = [];

        foreach ($byCategory as $cat => $events) {
            $categories[$cat] = count($events);
        }

        return [
            'total_events' => EventCatalog::count(),
            'categories' => $categories,
            'category_versions' => self::CATEGORY_SINCE,
        ];
    }

    /**
     * Get events by their since-version.
     *
     * @return array<string, list<string>>
     */
    public function eventsByVersion(): array
    {
        $versions = [];
        $all = EventCatalog::all();

        foreach ($all as $name => $entry) {
            $category = $entry['category'] ?? 'unknown';
            $version = self::CATEGORY_SINCE[$category] ?? '1.0.0';
            $versions[$version][] = $name;
        }

        return $versions;
    }
}
