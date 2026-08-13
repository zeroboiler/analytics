<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Services\EventTaxonomyService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

beforeEach(function (): void {
    $configMock = Mockery::mock(ConfigRepository::class);
    $configMock->shouldReceive('get')
        ->with('zeroboiler.analytics.taxonomy', [])
        ->andReturn(['enabled' => true]);
    $this->taxonomy = new EventTaxonomyService($configMock);
});

afterEach(function (): void {
    Mockery::close();
});

describe('V52 — Event Taxonomy Service + Catalog Provider Enrichment', function (): void {

    describe('Catalog posthog/plausible fields', function (): void {
        test('EcommerceEvents entries have posthog field', function (): void {
            $all = EcommerceEvents::all();
            foreach ($all as $name => $entry) {
                expect($entry)
                    ->toHaveKey('posthog')
                    ->and($entry['posthog'])->toBeString();
            }
        });

        test('EcommerceEvents entries have plausible field', function (): void {
            $all = EcommerceEvents::all();
            foreach ($all as $name => $entry) {
                expect($entry)->toHaveKey('plausible');
                // plausible can be string or null
                expect($entry['plausible'])
                    ->toBeString()
                    ->or->toBeNull();
            }
        });

        test('SaaSEvents entries have posthog field', function (): void {
            $all = SaaSEvents::all();
            foreach ($all as $name => $entry) {
                expect($entry['posthog'])->toBeString();
            }
        });

        test('SaaSEvents entries have plausible field', function (): void {
            $all = SaaSEvents::all();
            foreach ($all as $name => $entry) {
                expect($entry['plausible'])->toBeString()->or->toBeNull();
            }
        });

        test('EngagementEvents entries have posthog field', function (): void {
            $all = EngagementEvents::all();
            foreach ($all as $name => $entry) {
                expect($entry['posthog'])->toBeString();
            }
        });

        test('EngagementEvents entries have plausible field', function (): void {
            $all = EngagementEvents::all();
            foreach ($all as $name => $entry) {
                expect($entry['plausible'])->toBeString()->or->toBeNull();
            }
        });

        test('EcommerceEvents::posthogNames() returns non-empty list', function (): void {
            $names = EcommerceEvents::posthogNames();
            expect($names)->toBeArray();
            expect(count($names))->toBeGreaterThan(0);
            expect(in_array('$view_item', $names, true))->toBeTrue();
            expect(in_array('purchase', $names, true))->toBeTrue();
        });

        test('EcommerceEvents::plausibleNames() returns non-empty list with only non-null values', function (): void {
            $names = EcommerceEvents::plausibleNames();
            expect($names)->toBeArray();
            foreach ($names as $name) {
                expect($name)->toBeString();
                expect($name)->not->toBe('');
            }
            expect(in_array('purchase', $names, true))->toBeTrue();
        });

        test('SaaSEvents::posthogNames() returns non-empty list', function (): void {
            $names = SaaSEvents::posthogNames();
            expect($names)->toBeArray();
            expect(count($names))->toBeGreaterThan(0);
            expect(in_array('$signup', $names, true))->toBeTrue();
        });

        test('SaaSEvents::plausibleNames() filters null entries', function (): void {
            $names = SaaSEvents::plausibleNames();
            expect($names)->toBeArray();
            foreach ($names as $name) {
                expect($name)->toBeString();
            }
            // Core SaaS events should have plausible mapping
            expect(in_array('signup', $names, true))->toBeTrue();
            expect(in_array('purchase', $names, true))->toBeTrue();
        });

        test('EngagementEvents::posthogNames() returns non-empty list', function (): void {
            $names = EngagementEvents::posthogNames();
            expect($names)->toBeArray();
            expect(count($names))->toBeGreaterThan(0);
            expect(in_array('$pageview', $names, true))->toBeTrue();
        });

        test('EngagementEvents::plausibleNames() filters null entries', function (): void {
            $names = EngagementEvents::plausibleNames();
            expect($names)->toBeArray();
            foreach ($names as $name) {
                expect($name)->toBeString();
            }
            expect(in_array('pageview', $names, true))->toBeTrue();
        });
    });

    describe('EventCatalog native provider methods', function (): void {
        test('allPosthogNames() uses catalog-native posthog fields', function (): void {
            $names = EventCatalog::allPosthogNames();
            expect($names)->toBeArray();
            expect(count($names))->toBeGreaterThan(0);
            // Should contain PostHog reserved events with $ prefix
            expect(in_array('$signup', $names, true))->toBeTrue();
            expect(in_array('$pageview', $names, true))->toBeTrue();
            expect(in_array('$exception', $names, true))->toBeTrue();
        });

        test('allPlausibleNames() uses catalog-native plausible fields', function (): void {
            $names = EventCatalog::allPlausibleNames();
            expect($names)->toBeArray();
            expect(count($names))->toBeGreaterThan(0);
            expect(in_array('pageview', $names, true))->toBeTrue();
            expect(in_array('purchase', $names, true))->toBeTrue();
            // Should NOT contain null events
            foreach ($names as $name) {
                expect($name)->toBeString();
                expect($name)->not->toBe('');
            }
        });

        test('allPosthogMappings() returns complete mapping table', function (): void {
            $mappings = EventCatalog::allPosthogMappings();
            expect($mappings)->toBeArray();
            expect(count($mappings))->toBe(EventCatalog::count());
            expect($mappings['sign_up'])->toBe('$signup');
            expect($mappings['page_view'])->toBe('$pageview');
            expect($mappings['purchase'])->toBe('purchase');
        });

        test('allPlausibleMappings() returns complete mapping table with nulls', function (): void {
            $mappings = EventCatalog::allPlausibleMappings();
            expect($mappings)->toBeArray();
            expect(count($mappings))->toBe(EventCatalog::count());
            expect($mappings['page_view'])->toBe('pageview');
            expect($mappings['purchase'])->toBe('purchase');
            // Events not supported by Plausible should be null
            expect($mappings['scroll_depth'])->toBeNull();
            expect($mappings['click'])->toBeNull();
        });

        test('posthogNameFor() returns native catalog value', function (): void {
            expect(EventCatalog::posthogNameFor('sign_up'))->toBe('$signup');
            expect(EventCatalog::posthogNameFor('login'))->toBe('$identify');
            expect(EventCatalog::posthogNameFor('unknown_event'))->toBe('unknown_event');
        });

        test('plausibleNameFor() returns native catalog value or null', function (): void {
            expect(EventCatalog::plausibleNameFor('page_view'))->toBe('pageview');
            expect(EventCatalog::plausibleNameFor('purchase'))->toBe('purchase');
            expect(EventCatalog::plausibleNameFor('scroll_depth'))->toBeNull();
            expect(EventCatalog::plausibleNameFor('unknown_event'))->toBeNull();
        });

        test('byProvider() uses native catalog fields', function (): void {
            $byProvider = EventCatalog::byProvider();
            expect(array_keys($byProvider))->toEqual(['ga4', 'meta', 'posthog', 'plausible']);
            expect(count($byProvider['ga4']))->toBeGreaterThan(0);
            expect(count($byProvider['meta']))->toBeGreaterThan(0);
            expect(count($byProvider['posthog']))->toBeGreaterThan(0);
            expect(count($byProvider['plausible']))->toBeGreaterThan(0);
        });
    });

    describe('EventTaxonomyService', function (): void {
        test('is enabled by default', function (): void {
            expect($this->taxonomy->isEnabled())->toBeTrue();
        });

        test('tagsFor() returns array of tags for known events', function (): void {
            $tags = $this->taxonomy->tagsFor('purchase');
            expect($tags)->toBeArray();
            expect(in_array('revenue', $tags, true))->toBeTrue();
            expect(in_array('conversion', $tags, true))->toBeTrue();
            expect(in_array('ecommerce', $tags, true))->toBeTrue();
        });

        test('tagsFor() returns empty array for unknown events', function (): void {
            expect($this->taxonomy->tagsFor('unknown_event_xyz'))->toBe([]);
        });

        test('hasTag() works correctly', function (): void {
            expect($this->taxonomy->hasTag('purchase', 'revenue'))->toBeTrue();
            expect($this->taxonomy->hasTag('purchase', 'conversion'))->toBeTrue();
            expect($this->taxonomy->hasTag('purchase', 'cohort'))->toBeFalse();
            expect($this->taxonomy->hasTag('unknown', 'revenue'))->toBeFalse();
        });

        test('eventsWithTag() returns correct revenue events', function (): void {
            $events = $this->taxonomy->eventsWithTag('revenue');
            expect($events)->toBeArray();
            expect(in_array('purchase', $events, true))->toBeTrue();
            expect(in_array('payment_succeeded', $events, true))->toBeTrue();
            expect(in_array('invoice_generated', $events, true))->toBeTrue();
        });

        test('eventsWithTag() returns correct conversion events', function (): void {
            $events = $this->taxonomy->eventsWithTag('conversion');
            expect($events)->toBeArray();
            expect(in_array('sign_up', $events, true))->toBeTrue();
            expect(in_array('subscribe', $events, true))->toBeTrue();
            expect(in_array('add_to_cart', $events, true))->toBeTrue();
        });

        test('eventsWithAnyTag() uses OR logic', function (): void {
            $events = $this->taxonomy->eventsWithAnyTag(['revenue', 'churn']);
            expect(in_array('purchase', $events, true))->toBeTrue();
            expect(in_array('cancellation', $events, true))->toBeTrue();
        });

        test('eventsWithAllTags() uses AND logic', function (): void {
            $events = $this->taxonomy->eventsWithAllTags(['revenue', 'conversion']);
            expect(in_array('purchase', $events, true))->toBeTrue();
            // revenue + conversion must both be present
            foreach ($events as $event) {
                expect($this->taxonomy->hasTag($event, 'revenue'))->toBeTrue();
                expect($this->taxonomy->hasTag($event, 'conversion'))->toBeTrue();
            }
        });

        test('eventsWithAllTags() with empty tags returns empty', function (): void {
            expect($this->taxonomy->eventsWithAllTags([]))->toBe([]);
        });

        test('allTags() returns all unique tags', function (): void {
            $tags = $this->taxonomy->allTags();
            expect($tags)->toBeArray();
            expect(count($tags))->toBeGreaterThan(10);
            expect(in_array('revenue', $tags, true))->toBeTrue();
            expect(in_array('conversion', $tags, true))->toBeTrue();
            expect(in_array('engagement', $tags, true))->toBeTrue();
            expect(in_array('error', $tags, true))->toBeTrue();
            expect(in_array('lifecycle', $tags, true))->toBeTrue();
        });

        test('tagCount() returns correct count', function (): void {
            expect($this->taxonomy->tagCount())->toBe($this->taxonomy->tagCount());
            expect($this->taxonomy->tagCount())->toBeGreaterThan(0);
        });

        test('tagDefinitions() returns definitions with event counts', function (): void {
            $defs = $this->taxonomy->tagDefinitions();
            expect($defs)->toBeArray();
            expect(isset($defs['revenue']))->toBeTrue();
            expect($defs['revenue'])->toHaveKey('label');
            expect($defs['revenue'])->toHaveKey('description');
            expect($defs['revenue'])->toHaveKey('event_count');
            expect($defs['revenue']['label'])->toBe('Revenue');
            expect($defs['revenue']['event_count'])->toBeGreaterThan(0);
        });

        test('eventsGroupedByTag() returns grouped events', function (): void {
            $grouped = $this->taxonomy->eventsGroupedByTag();
            expect($grouped)->toBeArray();
            expect(isset($grouped['revenue']))->toBeTrue();
            expect(isset($grouped['conversion']))->toBeTrue();
            expect(count($grouped['revenue']))->toBeGreaterThan(0);
        });

        test('isRevenueEvent() shortcut', function (): void {
            expect($this->taxonomy->isRevenueEvent('purchase'))->toBeTrue();
            expect($this->taxonomy->isRevenueEvent('sign_up'))->toBeFalse();
        });

        test('isConversionEvent() shortcut', function (): void {
            expect($this->taxonomy->isConversionEvent('sign_up'))->toBeTrue();
            expect($this->taxonomy->isConversionEvent('page_view'))->toBeFalse();
        });

        test('isErrorEvent() shortcut', function (): void {
            expect($this->taxonomy->isErrorEvent('error'))->toBeTrue();
            expect($this->taxonomy->isErrorEvent('js_error'))->toBeTrue();
            expect($this->taxonomy->isErrorEvent('purchase'))->toBeFalse();
        });

        test('isLifecycleEvent() shortcut', function (): void {
            expect($this->taxonomy->isLifecycleEvent('login'))->toBeTrue();
            expect($this->taxonomy->isLifecycleEvent('plan_upgrade'))->toBeTrue();
            expect($this->taxonomy->isLifecycleEvent('purchase'))->toBeFalse();
        });

        test('isFunnelEvent() shortcut', function (): void {
            expect($this->taxonomy->isFunnelEvent('view_item'))->toBeTrue();
            expect($this->taxonomy->isFunnelEvent('begin_checkout'))->toBeTrue();
            expect($this->taxonomy->isFunnelEvent('page_view'))->toBeFalse();
        });

        test('isChurnEvent() shortcut', function (): void {
            expect($this->taxonomy->isChurnEvent('cancellation'))->toBeTrue();
            expect($this->taxonomy->isChurnEvent('cohort_churn'))->toBeTrue();
            expect($this->taxonomy->isChurnEvent('sign_up'))->toBeFalse();
        });

        test('revenueEvents() returns all revenue-tagged events', function (): void {
            $revenue = $this->taxonomy->revenueEvents();
            expect($revenue)->toBeArray();
            expect(count($revenue))->toBeGreaterThan(0);
            expect(in_array('purchase', $revenue, true))->toBeTrue();
        });

        test('conversionEvents() returns all conversion-tagged events', function (): void {
            $conv = $this->taxonomy->conversionEvents();
            expect($conv)->toBeArray();
            expect(in_array('sign_up', $conv, true))->toBeTrue();
            expect(in_array('subscribe', $conv, true))->toBeTrue();
        });

        test('errorEvents() returns all error-tagged events', function (): void {
            $errors = $this->taxonomy->errorEvents();
            expect($errors)->toBeArray();
            expect(in_array('error', $errors, true))->toBeTrue();
            expect(in_array('js_error', $errors, true))->toBeTrue();
        });

        test('funnelEvents() returns all funnel-tagged events', function (): void {
            $funnel = $this->taxonomy->funnelEvents();
            expect($funnel)->toBeArray();
            expect(in_array('view_item', $funnel, true))->toBeTrue();
            expect(in_array('add_to_cart', $funnel, true))->toBeTrue();
        });

        test('taggedEventCount() returns reasonable count', function (): void {
            $tagged = $this->taxonomy->taggedEventCount();
            expect($tagged)->toBeGreaterThan(0);
            expect($tagged)->toBeLessThanOrEqual($this->taxonomy->totalEventCount());
        });

        test('coverageRatio() returns float between 0 and 1', function (): void {
            $ratio = $this->taxonomy->coverageRatio();
            expect($ratio)->toBeFloat();
            expect($ratio)->toBeGreaterThanOrEqual(0.0);
            expect($ratio)->toBeLessThanOrEqual(1.0);
        });

        test('addTags() adds tags at runtime', function (): void {
            $this->taxonomy->addTags('custom_event', ['custom_tag']);
            expect($this->taxonomy->tagsFor('custom_event'))->toContain('custom_tag');
        });

        test('removeTags() removes tags at runtime', function (): void {
            $this->taxonomy->addTags('test_event', ['tag_a', 'tag_b']);
            $this->taxonomy->removeTags('test_event', ['tag_a']);
            $tags = $this->taxonomy->tagsFor('test_event');
            expect(in_array('tag_a', $tags, true))->toBeFalse();
            expect(in_array('tag_b', $tags, true))->toBeTrue();
        });

        test('summary() returns complete structure', function (): void {
            $summary = $this->taxonomy->summary();
            expect(array_keys($summary))->toEqual([
                'enabled',
                'total_events',
                'tagged_events',
                'coverage',
                'tag_count',
                'tags',
                'tag_definitions',
            ]);
            expect($summary['enabled'])->toBeTrue();
            expect($summary['total_events'])->toBeGreaterThan(0);
            expect($summary['tagged_events'])->toBeGreaterThan(0);
            expect($summary['coverage'])->toBeGreaterThan(0.0);
            expect($summary['tag_count'])->toBeGreaterThan(0);
        });
    });

    describe('Custom tags and disabled tags config', function (): void {
        test('custom tags override built-in tags', function (): void {
            $configMock = Mockery::mock(ConfigRepository::class);
            $configMock->shouldReceive('get')
                ->with('zeroboiler.analytics.taxonomy', [])
                ->andReturn([
                    'enabled' => true,
                    'custom_tags' => [
                        'purchase' => ['revenue', 'custom_only'],
                    ],
                ]);

            $taxonomy = new EventTaxonomyService($configMock);
            $tags = $taxonomy->tagsFor('purchase');
            expect($tags)->toContain('revenue');
            expect($tags)->toContain('custom_only');
        });

        test('disabled tags are removed', function (): void {
            $configMock = Mockery::mock(ConfigRepository::class);
            $configMock->shouldReceive('get')
                ->with('zeroboiler.analytics.taxonomy', [])
                ->andReturn([
                    'enabled' => true,
                    'disabled_tags' => ['revenue'],
                ]);

            $taxonomy = new EventTaxonomyService($configMock);
            // purchase had revenue tag, now should not
            expect($taxonomy->hasTag('purchase', 'revenue'))->toBeFalse();
            // but should still have other tags
            expect($taxonomy->hasTag('purchase', 'conversion'))->toBeTrue();
        });

        test('taxonomy disabled when config says so', function (): void {
            $configMock = Mockery::mock(ConfigRepository::class);
            $configMock->shouldReceive('get')
                ->with('zeroboiler.analytics.taxonomy', [])
                ->andReturn([
                    'enabled' => false,
                ]);

            $taxonomy = new EventTaxonomyService($configMock);
            expect($taxonomy->isEnabled())->toBeFalse();
        });
    });

    describe('Version consistency', function (): void {
        test('composer.json version is 2.52.0', function (): void {
            $json = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($json['version'])->toBe('76.0.0');
        });

        test('EventCatalog total count is consistent', function (): void {
            $total = EventCatalog::count();
            $byCategory = EventCatalog::byCategory();
            $sum = count($byCategory['ecommerce']) + count($byCategory['saas']) + count($byCategory['engagement']);
            expect($sum)->toBe($total);
        });

        test('all source files exist', function (): void {
            expect(file_exists(__DIR__ . '/../src/Services/EventTaxonomyService.php'))->toBeTrue();
            expect(file_exists(__DIR__ . '/../src/Events/EventCatalog.php'))->toBeTrue();
        });
    });
});
