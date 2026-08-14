<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EventCatalogSchemaSnapshotService;
use ZeroBoiler\Analytics\Services\EventProviderCompatibilityMatrixService;

beforeEach(function (): void {
    $this->cache = app('cache')->store();
    $this->config = app('config');
    $this->snapshotService = new EventCatalogSchemaSnapshotService($this->cache, $this->config);
    $this->matrixService = new EventProviderCompatibilityMatrixService;
});

describe('EventCatalogSchemaSnapshotService', function (): void {
    test('capture creates a snapshot with correct structure', function (): void {
        $result = $this->snapshotService->capture('v142-test');

        expect($result)
            ->toHaveKey('label')
            ->toHaveKey('timestamp')
            ->toHaveKey('version')
            ->toHaveKey('total_events')
            ->toHaveKey('categories')
            ->toHaveKey('snapshot_hash');

        expect($result['label'])->toBe('v142-test');
        expect($result['total_events'])->toBeGreaterThan(0);
        expect($result['version'])->toBe(AnalyticsEvent::VERSION);
        expect($result['snapshot_hash'])->toBeString()->toHaveLength(64);
    });

    test('capture defaults to package version when no label given', function (): void {
        $result = $this->snapshotService->capture();

        expect($result['label'])->toBe(AnalyticsEvent::VERSION);
    });

    test('capture sets baseline automatically if none exists', function (): void {
        expect($this->snapshotService->getBaselineLabel())->toBeNull();

        $this->snapshotService->capture('v142-baseline');

        expect($this->snapshotService->getBaselineLabel())->toBe('v142-baseline');
    });

    test('capture does not overwrite existing baseline', function (): void {
        $this->snapshotService->capture('v142-first');
        $this->snapshotService->capture('v142-second');

        expect($this->snapshotService->getBaselineLabel())->toBe('v142-first');
    });

    test('diffAgainstBaseline returns empty diff when no baseline', function (): void {
        // Clear any cached baseline
        $this->cache->forget('zb_catalog_baseline_label');

        $diff = $this->snapshotService->diffAgainstBaseline();

        expect($diff['breaking'])->toBe([]);
        expect($diff['non_breaking'])->toBeEmpty();
        expect($diff['summary']['breaking_count'])->toBe(0);
    });

    test('diffAgainstBaseline detects no changes when catalog is identical', function (): void {
        $this->snapshotService->capture('v142-same');
        $diff = $this->snapshotService->diffAgainstBaseline('v142-same');

        expect($diff['breaking'])->toBe([]);
        expect($diff['summary']['removed'])->toBe(0);
        expect($diff['summary']['added'])->toBe(0);
        expect($diff['summary']['breaking_count'])->toBe(0);
    });

    test('setBaseline changes the baseline label', function (): void {
        $this->snapshotService->capture('v142-a');
        $this->snapshotService->capture('v142-b');

        expect($this->snapshotService->getBaselineLabel())->toBe('v142-a');

        $this->snapshotService->setBaseline('v142-b');

        expect($this->snapshotService->getBaselineLabel())->toBe('v142-b');
    });

    test('getSnapshotLabels returns known labels', function (): void {
        $this->snapshotService->capture('v142-label-1');

        $labels = $this->snapshotService->getSnapshotLabels();

        expect($labels)->toContain('v142-label-1');
    });

    test('deleteSnapshot removes the snapshot', function (): void {
        $this->snapshotService->capture('v142-delete');

        $json = $this->snapshotService->exportSnapshotJson('v142-delete');
        expect($json)->not->toBeNull();

        $this->snapshotService->deleteSnapshot('v142-delete');

        $json = $this->snapshotService->exportSnapshotJson('v142-delete');
        expect($json)->toBeNull();
    });

    test('deleteSnapshot clears baseline if deleting baseline', function (): void {
        $this->snapshotService->capture('v142-base-del');

        expect($this->snapshotService->getBaselineLabel())->toBe('v142-base-del');

        $this->snapshotService->deleteSnapshot('v142-base-del');

        expect($this->snapshotService->getBaselineLabel())->toBeNull();
    });

    test('exportSnapshotJson returns valid JSON', function (): void {
        $this->snapshotService->capture('v142-export');

        $json = $this->snapshotService->exportSnapshotJson('v142-export');

        expect($json)->not->toBeNull();

        $decoded = json_decode($json, true);
        expect($decoded)->toBeArray();
        expect($decoded)->toHaveKey('events');
        expect($decoded)->toHaveKey('total_events');
        expect($decoded)->toHaveKey('version');
    });

    test('isAutoSnapshotEnabled returns configured value', function (): void {
        expect($this->snapshotService->isAutoSnapshotEnabled())->toBeBool();
    });
});

describe('EventProviderCompatibilityMatrixService', function (): void {
    test('buildMatrix returns correct structure', function (): void {
        $result = $this->matrixService->buildMatrix();

        expect($result)
            ->toHaveKey('matrix')
            ->toHaveKey('summary');

        expect($result['summary'])
            ->toHaveKey('total_events')
            ->toHaveKey('total_gaps')
            ->toHaveKey('coverage_by_provider')
            ->toHaveKey('coverage_by_category')
            ->toHaveKey('critical_gaps')
            ->toHaveKey('overall_coverage');

        expect($result['summary']['total_events'])->toBeGreaterThan(0);
        expect($result['summary']['overall_coverage'])->toBeGreaterThan(0.0);
        expect($result['summary']['overall_coverage'])->toBeLessThanOrEqual(100.0);
    });

    test('buildMatrix has all expected providers in coverage', function (): void {
        $result = $this->matrixService->buildMatrix();
        $providers = array_keys($result['summary']['coverage_by_provider']);

        expect($providers)->toContain('ga4');
        expect($providers)->toContain('meta');
        expect($providers)->toContain('posthog');
        expect($providers)->toContain('plausible');
        expect($providers)->toContain('mixpanel');
        expect($providers)->toContain('amplitude');
        expect($providers)->toContain('tiktok');
        expect($providers)->toContain('linkedin');
    });

    test('buildMatrix has all expected categories in coverage', function (): void {
        $result = $this->matrixService->buildMatrix();
        $categories = array_keys($result['summary']['coverage_by_category']);

        expect($categories)->toContain('ecommerce');
        expect($categories)->toContain('saas');
        expect($categories)->toContain('engagement');
        expect($categories)->toContain('security');
        expect($categories)->toContain('uptime');
        expect($categories)->toContain('infrastructure');
        expect($categories)->toContain('marketing');
        expect($categories)->toContain('customer_success');
    });

    test('buildMatrix matrix entries contain all providers', function (): void {
        $result = $this->matrixService->buildMatrix();
        $firstEvent = array_key_first($result['matrix']);

        expect($result['matrix'][$firstEvent])
            ->toHaveKey('ga4')
            ->toHaveKey('meta')
            ->toHaveKey('posthog')
            ->toHaveKey('plausible')
            ->toHaveKey('mixpanel')
            ->toHaveKey('amplitude')
            ->toHaveKey('tiktok')
            ->toHaveKey('linkedin');
    });

    test('buildMatrix critical gaps are sorted by severity', function (): void {
        $result = $this->matrixService->buildMatrix();
        $gaps = $result['summary']['critical_gaps'];

        if (count($gaps) < 2) {
            $this->markTestSkipped('Not enough gaps to test severity ordering');
        }

        $severityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        $lastOrder = -1;

        foreach ($gaps as $gap) {
            $order = $severityOrder[$gap['severity']] ?? 4;
            expect($order)->toBeGreaterThanOrEqual($lastOrder);
            $lastOrder = $order;
        }
    });

    test('buildMatrix matrix values are bool or null', function (): void {
        $result = $this->matrixService->buildMatrix();

        // Sample first 10 events
        $sample = array_slice($result['matrix'], 0, 10, true);

        foreach ($sample as $eventName => $providers) {
            foreach ($providers as $provider => $value) {
                expect($value)->toBeIn([true, null]);
            }
        }
    });

    test('eventCoverage returns correct structure for known event', function (): void {
        $coverage = $this->matrixService->eventCoverage('page_view');

        expect($coverage)
            ->toHaveKey('event')
            ->toHaveKey('category')
            ->toHaveKey('providers')
            ->toHaveKey('coverage')
            ->toHaveKey('gaps');

        expect($coverage['event'])->toBe('page_view');
        expect($coverage['category'])->toBe('engagement');
        expect($coverage['coverage'])->toBeGreaterThan(0.0);
    });

    test('eventCoverage returns empty result for unknown event', function (): void {
        $coverage = $this->matrixService->eventCoverage('nonexistent_event_xyz');

        expect($coverage['category'])->toBeNull();
        expect($coverage['coverage'])->toBe(0.0);
        expect($coverage['gaps'])->toContain('Event not found in catalog');
    });

    test('eventCoverage page_view has ga4 mapping', function (): void {
        $coverage = $this->matrixService->eventCoverage('page_view');

        expect($coverage['providers']['ga4'])->toBe(true);
    });

    test('eventCoverage purchase has ga4 and meta mappings', function (): void {
        $coverage = $this->matrixService->eventCoverage('purchase');

        expect($coverage['providers']['ga4'])->toBe(true);
        expect($coverage['providers']['meta'])->toBe(true);
    });

    test('providerCoverage returns correct structure', function (): void {
        $coverage = $this->matrixService->providerCoverage('ga4');

        expect($coverage)
            ->toHaveKey('provider')
            ->toHaveKey('total_events')
            ->toHaveKey('mapped')
            ->toHaveKey('missing')
            ->toHaveKey('coverage_pct')
            ->toHaveKey('unmapped_events');

        expect($coverage['provider'])->toBe('ga4');
        expect($coverage['total_events'])->toBeGreaterThan(0);
        expect($coverage['mapped'])->toBeGreaterThan(0);
        expect($coverage['coverage_pct'])->toBeGreaterThan(0.0);
    });

    test('providerCoverage ga4 has highest coverage', function (): void {
        $ga4 = $this->matrixService->providerCoverage('ga4');
        $plausible = $this->matrixService->providerCoverage('plausible');

        expect($ga4['coverage_pct'])->toBeGreaterThanOrEqual($plausible['coverage_pct']);
    });

    test('providerCoverage unmapped_events is a list of strings', function (): void {
        $coverage = $this->matrixService->providerCoverage('tiktok');

        foreach ($coverage['unmapped_events'] as $event) {
            expect($event)->toBeString();
        }
    });

    test('all ecommerce events have ga4 mapping', function (): void {
        $result = $this->matrixService->buildMatrix();
        $catalog = EventCatalog::category('ecommerce');

        foreach (array_keys($catalog) as $eventName) {
            expect($result['matrix'][$eventName]['ga4'])
                ->toBe(true, "Ecommerce event '{$eventName}' should have GA4 mapping");
        }
    });

    test('catalog count matches matrix count', function (): void {
        $result = $this->matrixService->buildMatrix();
        $catalogCount = EventCatalog::count();

        expect($result['summary']['total_events'])->toBe($catalogCount);
    });

    test('version consistency in snapshot and event', function (): void {
        $result = $this->snapshotService->capture('v142-version-test');

        expect($result['version'])->toBe(AnalyticsEvent::VERSION);
    });
});
