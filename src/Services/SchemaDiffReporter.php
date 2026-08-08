<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Schema\EventPropertySchema;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;

/**
 * Schema coverage and diff reporter.
 *
 * Compares the three schema sources — EventCatalog, EventPropertySchema,
 * and EventSchemaRegistry — to identify gaps, mismatches, and coverage
 * statistics. Useful for ensuring schema consistency across the package
 * and for CI quality gates.
 *
 * Report categories:
 * - catalog_only: Events in the catalog but missing from property schema or registry
 * - property_only: Events in property schema but not in the catalog
 * - registry_only: Events in registry but not in the catalog
 * - full_coverage: Events covered by all three sources
 * - partial_coverage: Events covered by catalog + one validator
 *
 * @version 2.96.0
 */
final class SchemaDiffReporter
{
    /**
     * Generate a comprehensive coverage report.
     *
     * @return array{total_catalog: int, total_property: int, total_registry: int, full_coverage: int, partial_coverage: int, catalog_only: list<string>, property_only: list<string>, registry_only: list<string>, full_coverage_events: list<string>, coverage_pct: float}
     */
    public function report(
        ?EventPropertySchema $propertySchema = null,
        ?EventSchemaRegistry $schemaRegistry = null,
    ): array {
        $catalogNames = EventCatalog::names();

        // Get property schema event names
        $propertyNames = [];
        if ($propertySchema !== null) {
            $schemas = $propertySchema->getSchemas();
            $propertyNames = array_keys($schemas);
        }

        // Get registry event names
        $registryNames = [];
        if ($schemaRegistry !== null) {
            $registryNames = $schemaRegistry->getEventNames();
        }

        $catalogSet = array_flip($catalogNames);
        $propertySet = array_flip($propertyNames);
        $registrySet = array_flip($registryNames);

        // Classify events
        $fullCoverage = [];
        $partialCoverage = [];
        $catalogOnly = [];

        foreach ($catalogNames as $name) {
            $inProperty = isset($propertySet[$name]);
            $inRegistry = isset($registrySet[$name]);
            $covered = (int) $inProperty + (int) $inRegistry;

            if ($inProperty && $inRegistry) {
                $fullCoverage[] = $name;
            } elseif ($covered > 0) {
                $partialCoverage[] = $name;
            } else {
                $catalogOnly[] = $name;
            }
        }

        // Events only in property schema (not in catalog)
        $propertyOnly = [];
        foreach ($propertyNames as $name) {
            if (! isset($catalogSet[$name])) {
                $propertyOnly[] = $name;
            }
        }

        // Events only in registry (not in catalog)
        $registryOnly = [];
        foreach ($registryNames as $name) {
            if (! isset($catalogSet[$name])) {
                $registryOnly[] = $name;
            }
        }

        $totalCatalog = count($catalogNames);
        $coveragePct = $totalCatalog > 0
            ? round((count($fullCoverage) + count($partialCoverage)) / $totalCatalog * 100, 1)
            : 100.0;

        return [
            'total_catalog' => $totalCatalog,
            'total_property' => count($propertyNames),
            'total_registry' => count($registryNames),
            'full_coverage' => count($fullCoverage),
            'partial_coverage' => count($partialCoverage),
            'catalog_only' => $catalogOnly,
            'property_only' => $propertyOnly,
            'registry_only' => $registryOnly,
            'full_coverage_events' => $fullCoverage,
            'coverage_pct' => $coveragePct,
        ];
    }

    /**
     * Generate a human-readable coverage summary string.
     *
     * @return string Multi-line coverage summary
     */
    public function summary(
        ?EventPropertySchema $propertySchema = null,
        ?EventSchemaRegistry $schemaRegistry = null,
    ): string {
        $report = $this->report($propertySchema, $schemaRegistry);

        $lines = [];
        $lines[] = 'ZeroBoiler Analytics — Schema Coverage Report';
        $lines[] = str_repeat('─', 50);
        $lines[] = "Catalog events:  {$report['total_catalog']}";
        $lines[] = "Property schemas: {$report['total_property']}";
        $lines[] = "Registry schemas: {$report['total_registry']}";
        $lines[] = '';
        $lines[] = "Full coverage (all 3):    {$report['full_coverage']} events";
        $lines[] = "Partial coverage (2/3):   {$report['partial_coverage']} events";
        $lines[] = "Catalog only (no schema): {$report['coverage_pct']}% covered";
        $lines[] = '';

        if (! empty($report['catalog_only'])) {
            $lines[] = 'Events missing from property schema & registry:';
            foreach (array_slice($report['catalog_only'], 0, 20) as $name) {
                $lines[] = "  - {$name}";
            }
            if (count($report['catalog_only']) > 20) {
                $lines[] = "  ... and " . (count($report['catalog_only']) - 20) . ' more';
            }
            $lines[] = '';
        }

        if (! empty($report['property_only'])) {
            $lines[] = 'Events in property schema but not in catalog:';
            foreach ($report['property_only'] as $name) {
                $lines[] = "  - {$name}";
            }
            $lines[] = '';
        }

        if (! empty($report['registry_only'])) {
            $lines[] = 'Events in registry but not in catalog:';
            foreach ($report['registry_only'] as $name) {
                $lines[] = "  - {$name}";
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Check if minimum coverage threshold is met.
     *
     * @param  float  $threshold  Minimum coverage percentage (0-100)
     */
    public function meetsThreshold(
        float $threshold,
        ?EventPropertySchema $propertySchema = null,
        ?EventSchemaRegistry $schemaRegistry = null,
    ): bool {
        $report = $this->report($propertySchema, $schemaRegistry);

        return $report['coverage_pct'] >= $threshold;
    }

    /**
     * Get coverage report by category.
     *
     * @return array<string, array{total: int, full: int, partial: int, missing: list<string>, coverage_pct: float}>
     */
    public function reportByCategory(
        ?EventPropertySchema $propertySchema = null,
        ?EventSchemaRegistry $schemaRegistry = null,
    ): array {
        $categories = ['ecommerce', 'saas', 'engagement'];
        $result = [];

        foreach ($categories as $category) {
            $catalogEvents = EventCatalog::category($category);
            $catalogNames = array_keys($catalogEvents);

            $full = 0;
            $partial = 0;
            $missing = [];

            foreach ($catalogNames as $name) {
                $inProperty = $propertySchema !== null && $propertySchema->hasSchema($name);
                $inRegistry = $schemaRegistry !== null && $schemaRegistry->has($name);
                $covered = (int) $inProperty + (int) $inRegistry;

                if ($covered === 2) {
                    $full++;
                } elseif ($covered === 1) {
                    $partial++;
                } else {
                    $missing[] = $name;
                }
            }

            $total = count($catalogNames);
            $coveragePct = $total > 0
                ? round(($full + $partial) / $total * 100, 1)
                : 100.0;

            $result[$category] = [
                'total' => $total,
                'full' => $full,
                'partial' => $partial,
                'missing' => $missing,
                'coverage_pct' => $coveragePct,
            ];
        }

        return $result;
    }
}
