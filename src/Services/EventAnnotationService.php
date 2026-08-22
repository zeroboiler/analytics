<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * Event annotation service for marking and tagging analytics events.
 *
 * Provides the ability to attach deployment markers, debug flags, release
 * tags, and custom annotations to analytics events. Annotations are stored
 * server-side and can be queried for filtering, debugging, and deployment
 * correlation analysis.
 *
 * Use cases:
 * - Mark events during deployments for A/B rollout tracking
 * - Tag events with deployment version for regression analysis
 * - Add debug annotations in staging/development environments
 * - Attach experiment/cohort markers for analysis segmentation
 *
 * Configuration: `zeroboiler.analytics.annotations`
 *
 * @since 9.3.0
 */
final class EventAnnotationService
{
    /** @var array{enabled: bool, cache_ttl: int, max_annotations_per_event: int, auto_attach: array<string, mixed>} */
    private array $config;

    private bool $enabled;

    private int $cacheTtl;

    private int $maxAnnotationsPerEvent;

    /** @var array<string, mixed> */
    private array $autoAttach;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        ConfigRepository $config,
    ){
        $annotationConfig = $config->get('zeroboiler.analytics.annotations', []);
        /** @var array{enabled?: bool, cache_ttl?: int, max_annotations_per_event?: int, auto_attach?: array<string, mixed>} $annotationConfig */

        $this->config = $annotationConfig;
        $this->enabled = (bool) ($annotationConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($annotationConfig['cache_ttl'] ?? 86400); // 24 hours
        $this->maxAnnotationsPerEvent = (int) ($annotationConfig['max_annotations_per_event'] ?? 20);
        $this->autoAttach = (array) ($annotationConfig['auto_attach'] ?? []);
    }

    /**
     * Attach an annotation to an event.
     *
     * Annotations are stored in a cache-backed store keyed by event ID.
     * Each annotation has a key, value, type, and timestamp.
     *
     * @param  string  $eventId  Unique event identifier
     * @param  string  $key  Annotation key (e.g., 'deployment', 'debug', 'experiment')
     * @param  string|bool|int|float  $value  Annotation value
     * @param  string  $type  Annotation type: 'deployment'|'debug'|'experiment'|'release'|'custom'
     * @return bool True if annotation was stored
     */
    public function annotate(
        string $eventId,
        string $key,
        string|bool|int|float $value,
        string $type = 'custom',
    ): bool {
        if (! $this->enabled) {
            return false;
        }

        $validTypes = ['deployment', 'debug', 'experiment', 'release', 'custom'];
        if (! in_array($type, $validTypes, true)) {
            $type = 'custom';
        }

        $cacheKey = $this->annotationCacheKey($eventId);
        $annotations = $this->getAnnotations($eventId);

        // Enforce max annotations limit
        if (count($annotations) >= $this->maxAnnotationsPerEvent) {
            Log::warning('EventAnnotationService: max annotations reached', [
                'event_id' => $this->maskId($eventId),
                'max' => $this->maxAnnotationsPerEvent,
            ]);

            return false;
        }

        // Update existing annotation if key matches
        $updated = false;
        foreach ($annotations as $i => $annotation) {
            if ($annotation['key'] === $key && $annotation['type'] === $type) {
                $annotations[$i]['value'] = $value;
                $annotations[$i]['updated_at'] = $this->now();
                $updated = true;
                break;
            }
        }

        if (! $updated) {
            $annotations[] = [
                'key' => $key,
                'value' => $value,
                'type' => $type,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ];
        }

        try {
            $this->cache->put($cacheKey, $annotations, $this->cacheTtl);

            return true;
        } catch (\Throwable $e) {
            Log::warning('EventAnnotationService: failed to store annotation', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get all annotations for an event.
     *
     * @param  string  $eventId
     * @return list<array{key: string, value: string|bool|int|float, type: string, created_at: string, updated_at: string}>
     */
    public function getAnnotations(string $eventId): array
    {
        if (! $this->enabled) {
            return [];
        }

        $cached = $this->cache->get($this->annotationCacheKey($eventId));

        return is_array($cached) ? $cached : [];
    }

    /**
     * Get a specific annotation value.
     *
     * @param  string  $eventId
     * @param  string  $key
     * @return string|bool|int|float|null
     */
    public function getAnnotation(string $eventId, string $key): string|bool|int|float|null
    {
        $annotations = $this->getAnnotations($eventId);

        foreach ($annotations as $annotation) {
            if ($annotation['key'] === $key) {
                return $annotation['value'];
            }
        }

        return null;
    }

    /**
     * Remove an annotation from an event.
     *
     * @param  string  $eventId
     * @param  string  $key
     * @return bool True if annotation was removed
     */
    public function removeAnnotation(string $eventId, string $key): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $annotations = $this->getAnnotations($eventId);
        $filtered = array_filter(
            $annotations,
            fn (array $a): bool => $a['key'] !== $key,
        );

        if (count($filtered) === count($annotations)) {
            return false; // Nothing removed
        }

        try {
            $this->cache->put(
                $this->annotationCacheKey($eventId),
                array_values($filtered),
                $this->cacheTtl,
            );

            return true;
        } catch (\Throwable $e) {
            Log::warning('EventAnnotationService: failed to remove annotation', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Clear all annotations for an event.
     *
     * @param  string  $eventId
     */
    public function clearAnnotations(string $eventId): void
    {
        $this->cache->forget($this->annotationCacheKey($eventId));
    }

    /**
     * Add auto-attach annotations from config.
     *
     * Called during event processing to attach configured automatic
     * annotations (deployment version, environment, etc.).
     *
     * @param  string  $eventId
     * @return list<array{key: string, value: string|bool|int|float, type: string}>
     */
    public function autoAttachAnnotations(string $eventId): array
    {
        if (! $this->enabled || $this->autoAttach === []) {
            return [];
        }

        $attached = [];

        // Deployment version
        if (($this->autoAttach['deployment_version'] ?? false)) {
            $version = $this->autoAttach['deployment_version_value']
                ?? config('app.version', \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION);
            $this->annotate($eventId, 'deployment_version', $version, 'deployment');
            $attached[] = ['key' => 'deployment_version', 'value' => $version, 'type' => 'deployment'];
        }

        // Environment
        if (($this->autoAttach['environment'] ?? false)) {
            $env = app()->environment();
            $this->annotate($eventId, 'environment', $env, 'debug');
            $attached[] = ['key' => 'environment', 'value' => $env, 'type' => 'debug'];
        }

        // Debug flag (auto in non-production)
        if (($this->autoAttach['debug_in_non_production'] ?? false) && ! app()->environment('production')) {
            $this->annotate($eventId, 'debug', true, 'debug');
            $attached[] = ['key' => 'debug', 'value' => true, 'type' => 'debug'];
        }

        // Release tag
        if (($this->autoAttach['release_tag'] ?? false) && ($this->autoAttach['release_tag_value'] ?? null)) {
            $release = (string) $this->autoAttach['release_tag_value'];
            $this->annotate($eventId, 'release', $release, 'release');
            $attached[] = ['key' => 'release', 'value' => $release, 'type' => 'release'];
        }

        return $attached;
    }

    /**
     * Get annotation statistics.
     *
     * @return array{enabled: bool, cache_ttl: int, max_per_event: int, auto_attach_keys: list<string>}
     */
    public function getStats(): array
    {
        return [
            'enabled' => $this->enabled,
            'cache_ttl' => $this->cacheTtl,
            'max_per_event' => $this->maxAnnotationsPerEvent,
            'auto_attach_keys' => array_keys($this->autoAttach),
        ];
    }

    /**
     * Check if the annotation service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Search annotations by key across events.
     *
     * Note: This is a best-effort scan and may not find all annotations
     * in a distributed cache environment.
     *
     * @param  string  $key
     * @param  int  $limit
     * @return list<array{event_id: string, value: string|bool|int|float, type: string}>
     */
    public function searchByKey(string $key, int $limit = 50): array
    {
        // This is a placeholder — full implementation would require
        // a persistent store (database) for cross-event search.
        // Cache-based search is not practical for production use.
        return [];
    }

    /**
     * Generate the cache key for an event's annotations.
     *
     * @param  string  $eventId
     * @return string
     */
    private function annotationCacheKey(string $eventId): string
    {
        return 'zb_annotation_' . hash('xxh128', $eventId);
    }

    /**
     * Get the current timestamp as ISO 8601.
     *
     * @return string
     */
    private function now(): string
    {
        return (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
    }

    /**
     * Mask an event ID for safe logging.
     *
     * @param  string  $eventId
     * @return string
     */
    private function maskId(string $eventId): string
    {
        $len = strlen($eventId);

        if ($len <= 8) {
            return substr($eventId, 0, 3) . '***';
        }

        return substr($eventId, 0, 6) . '...' . substr($eventId, -4);
    }
}
