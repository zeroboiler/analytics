<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;
use ZeroBoiler\Analytics\Events\Infrastructure\InfrastructureEvents;
use ZeroBoiler\Analytics\Events\EventTags;

/**
 * Phase 29 Production Audit — Provider Coverage Parity, Priority Scoring,
 * Event Mapping Analysis, and Catalog Integrity.
 *
 * @since 100.2.0
 */
test('providerCoverageParity returns correct structure')
    ->expect(fn (): array => EventCatalog::providerCoverageParity())
    ->toHaveKey('total')
    ->toHaveKey('providers');

test('providerCoverageParity total matches catalog count')
    ->expect(fn (): bool => EventCatalog::providerCoverageParity()['total'] === EventCatalog::count())
    ->toBeTrue();

test('providerCoverageParity has all 8 providers')
    ->expect(fn (): array => array_keys(EventCatalog::providerCoverageParity()['providers']))
    ->toBe(['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin']);

test('providerCoverageParity ga4 coverage is 100%')
    ->expect(fn (): float => EventCatalog::providerCoverageParity()['providers']['ga4']['coverage'])
    ->toBe(100.0);

test('providerCoverageParity ga4 gaps is empty')
    ->expect(fn (): array => EventCatalog::providerCoverageParity()['providers']['ga4']['gaps'])
    ->toBeEmpty();

test('providerCoverageParity posthog coverage is 100%')
    ->expect(fn (): float => EventCatalog::providerCoverageParity()['providers']['posthog']['coverage'])
    ->toBe(100.0);

test('providerCoverageParity all providers have mapped and coverage keys')
    ->expect(function (): bool {
        $parity = EventCatalog::providerCoverageParity();

        foreach ($parity['providers'] as $provider => $data) {
            if (! array_key_exists('mapped', $data) || ! array_key_exists('coverage', $data) || ! array_key_exists('gaps', $data)) {
                return false;
            }
        }

        return true;
    })
    ->toBeTrue();

test('providerCoverageParity coverage values are between 0 and 100')
    ->expect(function (): bool {
        $parity = EventCatalog::providerCoverageParity();

        foreach ($parity['providers'] as $data) {
            if ($data['coverage'] < 0.0 || $data['coverage'] > 100.0) {
                return false;
            }
        }

        return true;
    })
    ->toBeTrue();

test('providerCoverageParity sum of mapped + gaps equals total per provider')
    ->expect(function (): bool {
        $parity = EventCatalog::providerCoverageParity();

        foreach ($parity['providers'] as $data) {
            if ($data['mapped'] + count($data['gaps']) !== $parity['total']) {
                return false;
            }
        }

        return true;
    })
    ->toBeTrue();

test('eventProviderMapping returns correct structure for known event')
    ->expect(function (): array {
        $mapping = EventCatalog::eventProviderMapping('purchase');

        return [
            'has_event' => $mapping['event'] === 'purchase',
            'has_providers' => isset($mapping['providers']),
            'has_mapped_count' => isset($mapping['mapped_count']),
            'has_total' => $mapping['total_providers'] === 8,
        ];
    })
    ->toBe([
        'has_event' => true,
        'has_providers' => true,
        'has_mapped_count' => true,
        'has_total' => true,
    ]);

test('eventProviderMapping returns zero mapped for unknown event')
    ->expect(fn (): array => EventCatalog::eventProviderMapping('nonexistent_event_xyz'))
    ->toBe([
        'event' => 'nonexistent_event_xyz',
        'providers' => [
            'ga4' => null,
            'meta' => null,
            'posthog' => null,
            'plausible' => null,
            'mixpanel' => null,
            'amplitude' => null,
            'tiktok' => null,
            'linkedin' => null,
        ],
        'mapped_count' => 0,
        'total_providers' => 8,
    ]);

test('eventProviderMapping purchase has ga4 mapping')
    ->expect(fn (): bool => EventCatalog::eventProviderMapping('purchase')['providers']['ga4'] === 'purchase')
    ->toBeTrue();

test('fullyMappedEvents returns array of EventEntry arrays')
    ->expect(function (): bool {
        $events = EventCatalog::fullyMappedEvents();

        foreach ($events as $event) {
            if (! isset($event['name'], $event['class'], $event['category'])) {
                return false;
            }
        }

        return true;
    })
    ->toBeTrue();

test('fullyMappedEvents returns non-empty array')
    ->expect(fn (): bool => count(EventCatalog::fullyMappedEvents()) > 0)
    ->toBeTrue();

test('fullyMappedEvents all events have 8 provider mappings')
    ->expect(function (): bool {
        $events = EventCatalog::fullyMappedEvents();

        foreach ($events as $event) {
            foreach (['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'] as $provider) {
                $value = $event[$provider] ?? null;
                if ($value === null || $value === '') {
                    return false;
                }
            }
        }

        return true;
    })
    ->toBeTrue();

test('leastMappedEvents returns correct structure')
    ->expect(function (): bool {
        $events = EventCatalog::leastMappedEvents(5);

        return count($events) <= 5
            && array_keys($events[0] ?? []) === ['event', 'category', 'mapped_count', 'gaps'];
    })
    ->toBeTrue();

test('leastMappedEvents is sorted ascending by mapped_count')
    ->expect(function (): bool {
        $events = EventCatalog::leastMappedEvents(10);

        for ($i = 1; $i < count($events); $i++) {
            if ($events[$i]['mapped_count'] < $events[$i - 1]['mapped_count']) {
                return false;
            }
        }

        return true;
    })
    ->toBeTrue();

test('eventPriorityScore returns 0 for unknown event')
    ->expect(fn (): int => EventCatalog::eventPriorityScore('nonexistent_event'))
    ->toBe(0);

test('eventPriorityScore returns int between 0 and 100 for known events')
    ->expect(function (): bool {
        foreach (['purchase', 'sign_up', 'page_view', 'error', 'login'] as $event) {
            $score = EventCatalog::eventPriorityScore($event);

            if (! is_int($score) || $score < 0 || $score > 100) {
                return false;
            }
        }

        return true;
    })
    ->toBeTrue();

test('eventPriorityScore purchase is high priority (revenue tag bonus)')
    ->expect(function (): bool {
        $score = EventCatalog::eventPriorityScore('purchase');

        return $score >= 50;
    })
    ->toBeTrue();

test('eventPriorityScore sign_up is high priority')
    ->expect(function (): bool {
        $score = EventCatalog::eventPriorityScore('sign_up');

        return $score >= 40;
    })
    ->toBeTrue();

test('topPriorityEvents returns correct structure')
    ->expect(function (): bool {
        $events = EventCatalog::topPriorityEvents(10);

        return count($events) <= 10
            && isset($events[0]['event'], $events[0]['category'], $events[0]['priority'], $events[0]['tags']);
    })
    ->toBeTrue();

test('topPriorityEvents is sorted descending by priority')
    ->expect(function (): bool {
        $events = EventCatalog::topPriorityEvents(20);

        for ($i = 1; $i < count($events); $i++) {
            if ($events[$i]['priority'] > $events[$i - 1]['priority']) {
                return false;
            }
        }

        return true;
    })
    ->toBeTrue();

test('topPriorityEvents all priorities are between 0 and 100')
    ->expect(function (): bool {
        $events = EventCatalog::topPriorityEvents(50);

        foreach ($events as $event) {
            if ($event['priority'] < 0 || $event['priority'] > 100) {
                return false;
            }
        }

        return true;
    })
    ->toBeTrue();

test('topPriorityEvents first event is highest scoring')
    ->expect(function (): bool {
        $events = EventCatalog::topPriorityEvents(1);
        $topScore = $events[0]['priority'] ?? 0;
        $all = EventCatalog::names();
        $maxScore = 0;

        foreach ($all as $name) {
            $score = EventCatalog::eventPriorityScore($name);
            if ($score > $maxScore) {
                $maxScore = $score;
            }
        }

        return $topScore === $maxScore;
    })
    ->toBeTrue();

test('recommendedInstrumentationByScore starter returns events with priority >= 60')
    ->expect(function (): bool {
        $recs = EventCatalog::recommendedInstrumentationByScore('starter');

        foreach ($recs as $item) {
            if ($item['priority'] < 60) {
                return false;
            }
        }

        return true;
    })
    ->toBeTrue();

test('recommendedInstrumentationByScore intermediate returns events with priority 40-59')
    ->expect(function (): bool {
        $recs = EventCatalog::recommendedInstrumentationByScore('intermediate');

        foreach ($recs as $item) {
            if ($item['priority'] < 40 || $item['priority'] >= 60) {
                return false;
            }
        }

        return true;
    })
    ->toBeTrue();

test('recommendedInstrumentationByScore advanced returns events with priority < 40')
    ->expect(function (): bool {
        $recs = EventCatalog::recommendedInstrumentationByScore('advanced');

        foreach ($recs as $item) {
            if ($item['priority'] >= 40) {
                return false;
            }
        }

        return true;
    })
    ->toBeTrue();

test('recommendedInstrumentationByScore all returns three tiers')
    ->expect(fn (): array => array_keys(EventCatalog::recommendedInstrumentationByScore('all')))
    ->toBe(['starter', 'intermediate', 'advanced']);

test('recommendedInstrumentationByScore sum of tiers equals catalog total')
    ->expect(function (): bool {
        $tiers = EventCatalog::recommendedInstrumentationByScore('all');

        return count($tiers['starter']) + count($tiers['intermediate']) + count($tiers['advanced'])
            === EventCatalog::count();
    })
    ->toBeTrue();

test('providerCoverageParity plausible has gaps (not all events map to plausible)')
    ->expect(function (): bool {
        $parity = EventCatalog::providerCoverageParity();

        return $parity['providers']['plausible']['coverage'] < 100.0
            && count($parity['providers']['plausible']['gaps']) > 0;
    })
    ->toBeTrue();

test('providerCoverageParity tiktok has gaps')
    ->expect(function (): bool {
        $parity = EventCatalog::providerCoverageParity();

        return $parity['providers']['tiktok']['coverage'] < 100.0
            && count($parity['providers']['tiktok']['gaps']) > 0;
    })
    ->toBeTrue();

test('providerCoverageParity linkedin has gaps')
    ->expect(function (): bool {
        $parity = EventCatalog::providerCoverageParity();

        return $parity['providers']['linkedin']['coverage'] < 100.0
            && count($parity['providers']['linkedin']['gaps']) > 0;
    })
    ->toBeTrue();

test('providerCoverageParity meta coverage includes null mappings')
    ->expect(function (): bool {
        $parity = EventCatalog::providerCoverageParity();

        // Meta has null mappings for many events
        return $parity['providers']['meta']['coverage'] < 100.0;
    })
    ->toBeTrue();

test('eventProviderMapping page_view has all 8 providers mapped')
    ->expect(function (): bool {
        $mapping = EventCatalog::eventProviderMapping('page_view');

        return $mapping['mapped_count'] === 8;
    })
    ->toBeTrue();

test('eventProviderMapping sign_up has meta mapped as CompleteRegistration')
    ->expect(function (): bool {
        $mapping = EventCatalog::eventProviderMapping('sign_up');

        return $mapping['providers']['meta'] === 'CompleteRegistration';
    })
    ->toBeTrue();
