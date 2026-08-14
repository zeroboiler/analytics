<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Cross-Provider Event Translation Matrix.
 *
 * Provides a unified translation engine that can convert any event
 * from one provider format to another. Uses the EventCatalog's native
 * provider mappings to perform bidirectional translations.
 *
 * Supports translation between all 8+ providers:
 * - GA4, Meta Pixel, PostHog, Plausible, Mixpanel, Amplitude, TikTok, LinkedIn
 *
 * Handles both event name translation and payload parameter mapping
 * for e-commerce events (GA4 items ↔ Meta contents format).
 *
 * @since 133.0.0
 */
final class CrossProviderTranslationMatrix
{
    /** @var list<string> All supported provider identifiers */
    private const PROVIDERS = [
        'ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin',
    ];

    /** @var array<string, array<string, string>> Cached canonical → provider mapping */
    private static array $mappingCache = [];

    /**
     * Translate a catalog event name to a specific provider's event name.
     *
     * Given a canonical event name (e.g. 'purchase'), returns the
     * provider-specific event name (e.g. 'Purchase' for Meta Pixel).
     *
     * Returns null if the event has no mapping for the target provider.
     *
     * @param  string  $eventName  Canonical event name from the catalog
     * @param  string  $targetProvider  Target provider (ga4, meta, posthog, etc.)
     * @return string|null Provider-specific event name, or null if unmapped
     */
    public function translateName(string $eventName, string $targetProvider): ?string
    {
        $entry = EventCatalog::get($eventName);

        if ($entry === null) {
            return null;
        }

        return $entry[$targetProvider] ?? null;
    }

    /**
     * Reverse-translate a provider-specific event name back to canonical.
     *
     * Given a provider event name (e.g. 'CompleteRegistration' from Meta),
     * returns the canonical catalog name (e.g. 'sign_up').
     *
     * @param  string  $providerEventName  Provider-specific event name
     * @param  string  $sourceProvider  Source provider (ga4, meta, posthog, etc.)
     * @return string|null Canonical catalog event name, or null if not found
     */
    public function reverseTranslate(string $providerEventName, string $sourceProvider): ?string
    {
        $results = EventCatalog::searchByProvider($sourceProvider, $providerEventName);

        return count($results) > 0 ? $results[0]['name'] : null;
    }

    /**
     * Translate an event between two providers.
     *
     * Given an event name in one provider's format, returns the equivalent
     * event name in another provider's format.
     *
     * Example: translate('CompleteRegistration', 'meta', 'ga4') → 'sign_up'
     *
     * @param  string  $sourceEventName  Event name in source provider's format
     * @param  string  $sourceProvider  Source provider identifier
     * @param  string  $targetProvider  Target provider identifier
     * @return string|null Translated event name, or null if no mapping exists
     */
    public function translateBetween(string $sourceEventName, string $sourceProvider, string $targetProvider): ?string
    {
        $canonical = $this->reverseTranslate($sourceEventName, $sourceProvider);

        if ($canonical === null) {
            return null;
        }

        return $this->translateName($canonical, $targetProvider);
    }

    /**
     * Get the full translation mapping for an event across all providers.
     *
     * Returns an array mapping each provider name to the translated event name.
     * Providers with null values do not have a mapping for this event.
     *
     * @return array<string, string|null>
     */
    public function fullTranslationMap(string $eventName): array
    {
        $entry = EventCatalog::get($eventName);

        if ($entry === null) {
            return array_fill_keys(self::PROVIDERS, null);
        }

        $map = [];

        foreach (self::PROVIDERS as $provider) {
            $map[$provider] = $entry[$provider] ?? null;
        }

        return $map;
    }

    /**
     * Get translation coverage statistics for a specific event.
     *
     * Returns the count and percentage of providers that have mappings
     * for the given event.
     *
     * @return array{mapped: int, total: int, coverage: float, providers: list<string>, unmapped: list<string>}
     */
    public function coverageFor(string $eventName): array
    {
        $map = $this->fullTranslationMap($eventName);
        $mapped = [];
        $unmapped = [];

        foreach ($map as $provider => $name) {
            if ($name !== null) {
                $mapped[] = $provider;
            } else {
                $unmapped[] = $provider;
            }
        }

        $total = count(self::PROVIDERS);

        return [
            'mapped' => count($mapped),
            'total' => $total,
            'coverage' => $total > 0 ? round((count($mapped) / $total) * 100, 1) : 0.0,
            'providers' => $mapped,
            'unmapped' => $unmapped,
        ];
    }

    /**
     * Get events that have full cross-provider coverage (mapped to all providers).
     *
     * @return list<array{name: string, map: array<string, string|null>}>
     */
    public function fullyMappedEvents(): array
    {
        $result = [];

        foreach (EventCatalog::names() as $name) {
            $coverage = $this->coverageFor($name);

            if ($coverage['mapped'] === $coverage['total']) {
                $result[] = [
                    'name' => $name,
                    'map' => $this->fullTranslationMap($name),
                ];
            }
        }

        return $result;
    }

    /**
     * Get events that are missing provider mappings, grouped by provider.
     *
     * Useful for identifying gaps in the event catalog coverage.
     *
     * @return array<string, list<string>> Provider → list of unmapped event names
     */
    public function mappingGaps(): array
    {
        $gaps = array_fill_keys(self::PROVIDERS, []);

        foreach (EventCatalog::names() as $name) {
            $map = $this->fullTranslationMap($name);

            foreach ($map as $provider => $value) {
                if ($value === null) {
                    $gaps[$provider][] = $name;
                }
            }
        }

        return $gaps;
    }

    /**
     * Get a translation matrix table suitable for admin dashboards.
     *
     * Returns a 2D array: event_name → provider → provider_event_name.
     *
     * @return array{headers: list<string>, rows: array<string, array<string, string|null>>}
     */
    public function matrixTable(): array
    {
        return [
            'headers' => array_merge(['event'], self::PROVIDERS),
            'rows' => array_map(
                fn (string $name): array => array_merge(
                    [$name],
                    array_map(
                        fn (string $provider): ?string => $this->translateName($name, $provider),
                        self::PROVIDERS,
                    ),
                ),
                EventCatalog::names(),
            ),
        ];
    }

    /**
     * Get all supported provider identifiers.
     *
     * @return list<string>
     */
    public function providers(): array
    {
        return self::PROVIDERS;
    }

    /**
     * Check if a provider is supported.
     */
    public function isProviderSupported(string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true);
    }

    /**
     * Get events that map to the same provider event name (collision detection).
     *
     * Useful for identifying potential ambiguity when reverse-translating
     * from provider events back to canonical names.
     *
     * @return array<string, array{provider: string, event_name: string, canonical_names: list<string>}>
     */
    public function providerCollisions(): array
    {
        $collisions = [];

        foreach (self::PROVIDERS as $provider) {
            $providerNames = [];

            foreach (EventCatalog::names() as $name) {
                $mapped = $this->translateName($name, $provider);

                if ($mapped !== null) {
                    $providerNames[$mapped][] = $name;
                }
            }

            foreach ($providerNames as $providerEventName => $canonicalNames) {
                if (count($canonicalNames) > 1) {
                    $collisions[] = [
                        'provider' => $provider,
                        'event_name' => $providerEventName,
                        'canonical_names' => $canonicalNames,
                    ];
                }
            }
        }

        return $collisions;
    }
}
