<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EventDeprecationService;
use ZeroBoiler\Analytics\Services\EventVersioningService;

// ── V440 Event Deprecation & Versioning Service Tests ────────────────────

describe('V440 Event Deprecation & Versioning', function () {

    // ── 1. Event Deprecation Service ──────────────────────────────

    describe('EventDeprecationService', function () {
        beforeEach(function (): void {
            Cache::clear();
            Log::clearResolvedChannels();
        });

        test('constructs with empty registry and is enabled by default', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'event_versioning' => [
                            'enabled' => true,
                            'registry' => [],
                        ],
                    ],
                ],
            ]);

            $service = new EventDeprecationService(Cache::driver('array'), $config);

            expect($service->isEnabled())->toBeTrue();
            expect($service->registryCount())->toBe(0);
        });

        test('returns null for non-deprecated events', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'event_versioning' => [
                            'enabled' => true,
                            'registry' => [
                                'sign_up' => ['deprecated' => false, 'stability' => 'stable'],
                            ],
                        ],
                    ],
                ],
            ]);

            $service = new EventDeprecationService(Cache::driver('array'), $config);

            expect($service->getDeprecation('sign_up'))->toBeNull();
            expect($service->getDeprecation('unknown_event'))->toBeNull();
        });

        test('returns deprecation metadata for deprecated events', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'event_versioning' => [
                            'enabled' => true,
                            'registry' => [
                                'old_signup' => [
                                    'since' => '1.0.0',
                                    'deprecated' => true,
                                    'deprecated_in' => '44.0.0',
                                    'replaced_by' => 'sign_up',
                                    'stability' => 'deprecated',
                                    'message' => 'Use sign_up instead.',
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

            $service = new EventDeprecationService(Cache::driver('array'), $config);
            $deprecation = $service->getDeprecation('old_signup');

            expect($deprecation)->not->toBeNull();
            expect($deprecation['deprecated_in'])->toBe('44.0.0');
            expect($deprecation['replaced_by'])->toBe('sign_up');
            expect($deprecation['stability'])->toBe('deprecated');
            expect($deprecation['message'])->toBe('Use sign_up instead.');
        });

        test('checkAndWarn returns true for deprecated events', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'event_versioning' => [
                            'enabled' => true,
                            'registry' => [
                                'old_event' => [
                                    'deprecated' => true,
                                    'deprecated_in' => '44.0.0',
                                    'replaced_by' => 'new_event',
                                    'stability' => 'deprecated',
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

            $service = new EventDeprecationService(Cache::driver('array'), $config);

            expect($service->checkAndWarn('old_event'))->toBeTrue();
            expect($service->checkAndWarn('active_event'))->toBeFalse();
        });

        test('checkAndWarn deduplicates warnings via cache', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'event_versioning' => [
                            'enabled' => true,
                            'warning_ttl' => 3600,
                            'registry' => [
                                'deprecated_event' => [
                                    'deprecated' => true,
                                    'deprecated_in' => '44.0.0',
                                    'stability' => 'deprecated',
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

            $service = new EventDeprecationService(Cache::driver('array'), $config);

            // First call should return true
            expect($service->checkAndWarn('deprecated_event'))->toBeTrue();
            // Second call should return true (still deprecated) but not log again
            expect($service->checkAndWarn('deprecated_event'))->toBeTrue();
        });

        test('resolve returns original when auto_redirect is disabled', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'event_versioning' => [
                            'enabled' => true,
                            'auto_redirect' => false,
                            'registry' => [
                                'old_event' => [
                                    'deprecated' => true,
                                    'deprecated_in' => '44.0.0',
                                    'replaced_by' => 'new_event',
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

            $service = new EventDeprecationService(Cache::driver('array'), $config);

            expect($service->resolve('old_event'))->toBe('old_event');
        });

        test('resolve returns replacement when auto_redirect is enabled', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'event_versioning' => [
                            'enabled' => true,
                            'auto_redirect' => true,
                            'registry' => [
                                'old_event' => [
                                    'deprecated' => true,
                                    'deprecated_in' => '44.0.0',
                                    'replaced_by' => 'new_event',
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

            $service = new EventDeprecationService(Cache::driver('array'), $config);

            expect($service->resolve('old_event'))->toBe('new_event');
            expect($service->resolve('active_event'))->toBe('active_event');
        });

        test('shouldBlock returns false when block_deprecated is disabled', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'event_versioning' => [
                            'enabled' => true,
                            'block_deprecated' => false,
                            'registry' => [
                                'dead_event' => ['deprecated' => true, 'deprecated_in' => '44.0.0'],
                            ],
                        ],
                    ],
                ],
            ]);

            $service = new EventDeprecationService(Cache::driver('array'), $config);

            expect($service->shouldBlock('dead_event'))->toBeFalse();
        });

        test('shouldBlock returns true for deprecated events without replacement', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'event_versioning' => [
                            'enabled' => true,
                            'block_deprecated' => true,
                            'registry' => [
                                'dead_event' => ['deprecated' => true, 'deprecated_in' => '44.0.0'],
                                'redirected_event' => [
                                    'deprecated' => true,
                                    'deprecated_in' => '44.0.0',
                                    'replaced_by' => 'new_event',
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

            $service = new EventDeprecationService(Cache::driver('array'), $config);

            expect($service->shouldBlock('dead_event'))->toBeTrue();
            expect($service->shouldBlock('redirected_event'))->toBeFalse(); // has replacement
            expect($service->shouldBlock('active_event'))->toBeFalse(); // not deprecated
        });

        test('getStability returns correct levels', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'event_versioning' => [
                            'enabled' => true,
                            'registry' => [
                                'beta_event' => ['stability' => 'beta'],
                                'experimental_event' => ['stability' => 'experimental'],
                                'deprecated_event' => ['deprecated' => true, 'stability' => 'deprecated'],
                            ],
                        ],
                    ],
                ],
            ]);

            $service = new EventDeprecationService(Cache::driver('array'), $config);

            expect($service->getStability('beta_event'))->toBe('beta');
            expect($service->getStability('experimental_event'))->toBe('experimental');
            expect($service->getStability('deprecated_event'))->toBe('deprecated');
            expect($service->getStability('unknown_event'))->toBe('stable');
        });

        test('meetsStability enforces minimum levels correctly', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'event_versioning' => [
                            'enabled' => true,
                            'registry' => [
                                'stable_event' => ['stability' => 'stable'],
                                'beta_event' => ['stability' => 'beta'],
                                'experimental_event' => ['stability' => 'experimental'],
                            ],
                        ],
                    ],
                ],
            ]);

            $service = new EventDeprecationService(Cache::driver('array'), $config);

            expect($service->meetsStability('stable_event', 'experimental'))->toBeTrue();
            expect($service->meetsStability('stable_event', 'beta'))->toBeTrue();
            expect($service->meetsStability('stable_event', 'stable'))->toBeTrue();
            expect($service->meetsStability('beta_event', 'stable'))->toBeFalse();
            expect($service->meetsStability('beta_event', 'beta'))->toBeTrue();
            expect($service->meetsStability('experimental_event', 'stable'))->toBeFalse();
            expect($service->meetsStability('experimental_event', 'experimental'))->toBeTrue();
        });

        test('auditReport returns structured report', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'event_versioning' => [
                            'enabled' => true,
                            'registry' => [
                                'old_event' => ['deprecated' => true, 'deprecated_in' => '43.0.0'],
                                'newer_deprecated' => ['deprecated' => true, 'deprecated_in' => '44.0.0', 'replaced_by' => 'sign_up'],
                                'active_event' => ['deprecated' => false, 'stability' => 'stable'],
                            ],
                        ],
                    ],
                ],
            ]);

            $service = new EventDeprecationService(Cache::driver('array'), $config);
            $report = $service->auditReport();

            expect($report['total_deprecated'])->toBe(2);
            expect($report['total_registry'])->toBe(3);
            expect(count($report['events']))->toBe(2);
            // Should be sorted by deprecated_in descending
            expect($report['events'][0]['name'])->toBe('newer_deprecated');
        });

        test('unstableEvents returns beta and experimental events', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'event_versioning' => [
                            'enabled' => true,
                            'registry' => [
                                'stable_event' => ['stability' => 'stable'],
                                'beta_event' => ['stability' => 'beta', 'since' => '42.0.0'],
                                'experimental_event' => ['stability' => 'experimental', 'since' => '43.0.0'],
                            ],
                        ],
                    ],
                ],
            ]);

            $service = new EventDeprecationService(Cache::driver('array'), $config);
            $unstable = $service->unstableEvents();

            expect(count($unstable))->toBe(2);
            expect($unstable[0]['stability'])->toBe('beta');
            expect($unstable[1]['stability'])->toBe('experimental');
        });

        test('register adds metadata to in-memory registry', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'event_versioning' => [
                            'enabled' => true,
                            'registry' => [],
                        ],
                    ],
                ],
            ]);

            $service = new EventDeprecationService(Cache::driver('array'), $config);

            expect($service->registryCount())->toBe(0);

            $service->register('test_event', ['stability' => 'beta', 'since' => '44.0.0']);

            expect($service->registryCount())->toBe(1);
            expect($service->getStability('test_event'))->toBe('beta');
        });

        test('disabled service returns null/defaults', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'event_versioning' => [
                            'enabled' => false,
                            'registry' => [
                                'some_event' => ['deprecated' => true, 'deprecated_in' => '44.0.0'],
                            ],
                        ],
                    ],
                ],
            ]);

            $service = new EventDeprecationService(Cache::driver('array'), $config);

            expect($service->isEnabled())->toBeFalse();
            expect($service->getDeprecation('some_event'))->toBeNull();
            expect($service->getStability('some_event'))->toBe('stable');
            expect($service->checkAndWarn('some_event'))->toBeFalse();
        });
    });

    // ── 2. Event Versioning Service ──────────────────────────────

    describe('EventVersioningService', function () {
        test('getEventVersion returns metadata for catalog events', function (): void {
            $service = new EventVersioningService();
            $version = $service->getEventVersion('sign_up');

            expect($version)->not->toBeNull();
            expect($version['name'])->toBe('sign_up');
            expect($version['category'])->toBe('saas');
            expect($version['stability'])->toBe('stable');
            expect($version['in_catalog'])->toBeTrue();
            expect($version['since'])->toBe('1.0.0');
        });

        test('getEventVersion returns null for unknown events', function (): void {
            $service = new EventVersioningService();

            expect($service->getEventVersion('nonexistent_event_xyz'))->toBeNull();
        });

        test('getAllEventVersions returns data for all catalog events', function (): void {
            $service = new EventVersioningService();
            $versions = $service->getAllEventVersions();

            expect(count($versions))->toBe(EventCatalog::count());
            expect($versions)->toHaveKey('sign_up');
            expect($versions)->toHaveKey('page_view');
            expect($versions)->toHaveKey('purchase');
            expect($versions['sign_up']['category'])->toBe('saas');
            expect($versions['page_view']['category'])->toBe('engagement');
            expect($versions['purchase']['category'])->toBe('ecommerce');
        });

        test('versionSummary returns correct statistics', function (): void {
            $service = new EventVersioningService();
            $summary = $service->versionSummary();

            expect($summary['total_events'])->toBe(EventCatalog::count());
            expect($summary['categories'])->toHaveKeys(['ecommerce', 'saas', 'engagement', 'security', 'uptime']);
            expect($summary['category_versions'])->toHaveKeys(['ecommerce', 'saas', 'engagement', 'security', 'uptime']);
            expect($summary['category_versions']['security'])->toBe('9.9.0');
            expect($summary['category_versions']['uptime'])->toBe('9.9.0');
        });

        test('eventsByVersion groups events by since version', function (): void {
            $service = new EventVersioningService();
            $byVersion = $service->eventsByVersion();

            expect($byVersion)->toHaveKey('1.0.0');
            expect($byVersion)->toHaveKey('9.9.0');
            expect(count($byVersion['1.0.0']))->toBeGreaterThanOrEqual(10);
        });
    });

    // ── 3. Integration: Catalog + Versioning ─────────────────────

    describe('catalog + versioning integration', function () {
        test('all catalog events have valid version metadata', function (): void {
            $service = new EventVersioningService();
            $allEvents = EventCatalog::all();

            foreach ($allEvents as $name => $entry) {
                $version = $service->getEventVersion($name);
                expect($version)->not->toBeNull("Event '{$name}' missing version metadata");
                expect($version['category'])->toBeIn(['ecommerce', 'saas', 'engagement', 'security', 'uptime']);
                expect($version['stability'])->toBe('stable');
                expect($version['since'])->toBeString();
            }
        });

        test('deprecation audit report shows valid replacement references', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'event_versioning' => [
                            'enabled' => true,
                            'registry' => [
                                'old_purchase' => [
                                    'deprecated' => true,
                                    'deprecated_in' => '44.0.0',
                                    'replaced_by' => 'purchase',
                                ],
                                'dead_event' => [
                                    'deprecated' => true,
                                    'deprecated_in' => '44.0.0',
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

            $service = new EventDeprecationService(Cache::driver('array'), $config);
            $report = $service->auditReport();

            $purchaseReplacement = collect($report['events'])
                ->first(fn (array $e): bool => $e['name'] === 'old_purchase');

            expect($purchaseReplacement)->not->toBeNull();
            expect($purchaseReplacement['replacement_in_catalog'])->toBeTrue();

            $deadEvent = collect($report['events'])
                ->first(fn (array $e): bool => $e['name'] === 'dead_event');

            expect($deadEvent)->not->toBeNull();
            expect($deadEvent['replacement_in_catalog'])->toBeFalse();
        });

        test('version constant matches integrity command expectation', function (): void {
            $versionFromConst = \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION;
            $expectedFromIntegrity = '44.0.0';

            expect($versionFromConst)->toBe($expectedFromIntegrity);
        });
    });
});
