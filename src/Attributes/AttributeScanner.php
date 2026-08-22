<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Attributes;

use ReflectionClass;

/**
 * Scans classes for #[AnalyticsEventAttribute] and #[AnalyticsLifecycleMapping] attributes.
 *
 * Provides a registry of attribute-declared events and lifecycle mappings
 * that complement the static event catalogs and config-driven lifecycle mapper.
 *
 * @since 19.0.0
 */
final class AttributeScanner
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $eventCache = null;

    /** @var array<string, array{source: string, target: string, params_extractor: string|null, priority: int}>|null */
    private static ?array $lifecycleCache = null;

    /** @var list<string> */
    private static array $scannedClasses = [];

    /**
     * Scan a class for the #[AnalyticsEventAttribute] attribute.
     *
     * @param  class-string  $className  Fully qualified class name
     * @return array<string, mixed>|null  Catalog entry or null if no attribute found
     */
    public static function scanEvent(string $className): ?array
    {
        try {
            $reflection = new ReflectionClass($className);
        } catch (\ReflectionException $e) {
            return null;
        }

        $attributes = $reflection->getAttributes(AnalyticsEventAttribute::class);

        if ($attributes === []) {
            return null;
        }

        $attribute = $attributes[0]->newInstance();

        return $attribute->toCatalogEntry($className);
    }

    /**
     * Scan a class for all #[AnalyticsLifecycleMapping] attributes (on methods).
     *
     * @param  class-string  $className  Fully qualified class name
     * @return array<string, array{source: string, target: string, params_extractor: string|null, priority: int}>
     */
    public static function scanLifecycleMappings(string $className): array
    {
        $mappings = [];

        try {
            $reflection = new ReflectionClass($className);
        } catch (\ReflectionException $e) {
            return $mappings;
        }

        foreach ($reflection->getMethods() as $method) {
            $attributes = $method->getAttributes(AnalyticsLifecycleMapping::class);

            foreach ($attributes as $attr) {
                $instance = $attr->newInstance();
                $mappings[$instance->source] = [
                    'source' => $instance->source,
                    'target' => $instance->target,
                    'params_extractor' => $instance->paramsExtractor,
                    'priority' => $instance->priority,
                ];
            }
        }

        return $mappings;
    }

    /**
     * Get all attribute-declared events from scanned classes.
     *
     * @param  list<class-string>  $classes  Classes to scan (scans all if empty)
     * @return array<string, array<string, mixed>>
     */
    public static function allEvents(array $classes = []): array
    {
        if (self::$eventCache !== null && $classes === []) {
            return self::$eventCache;
        }

        $events = [];
        $classNames = $classes;

        foreach ($classNames as $className) {
            $entry = self::scanEvent($className);

            if ($entry !== null) {
                $events[$entry['name']] = $entry;
            }

            if (! in_array($className, self::$scannedClasses, true)) {
                self::$scannedClasses[] = $className;
            }
        }

        if ($classes === []) {
            self::$eventCache = $events;
        }

        return $events;
    }

    /**
     * Get all attribute-declared lifecycle mappings from scanned classes.
     *
     * @param  list<class-string>  $classes  Classes to scan
     * @return array<string, array{source: string, target: string, params_extractor: string|null, priority: int}>
     */
    public static function allLifecycleMappings(array $classes = []): array
    {
        if (self::$lifecycleCache !== null && $classes === []) {
            return self::$lifecycleCache;
        }

        $mappings = [];

        foreach ($classes as $className) {
            $classMappings = self::scanLifecycleMappings($className);
            $mappings = array_merge($mappings, $classMappings);
        }

        if ($classes === []) {
            self::$lifecycleCache = $mappings;
        }

        return $mappings;
    }

    /**
     * Clear the internal scan caches.
     *
     * Useful in testing or when new attribute classes are registered at runtime.
     */
    public static function clearCache(): void
    {
        self::$eventCache = null;
        self::$lifecycleCache = null;
        self::$scannedClasses = [];
    }

    /**
     * Check if a class has been scanned.
     *
     * @param  class-string  $className
     */
    public static function hasScanned(string $className): bool
    {
        return in_array($className, self::$scannedClasses, true);
    }
}
