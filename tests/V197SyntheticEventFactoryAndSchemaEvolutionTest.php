<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Services\EventSchemaEvolutionTracker;
use ZeroBoiler\Analytics\Services\SyntheticEventFactory;

/**
 * Tests for v197.0.0 — Synthetic Event Factory + Event Schema Evolution Tracker.
 *
 * @covers \ZeroBoiler\Analytics\Services\SyntheticEventFactory
 * @covers \ZeroBoiler\Analytics\Services\EventSchemaEvolutionTracker
 * @covers \ZeroBoiler\Analytics\Console\Commands\AnalyticsSyntheticCommand
 *
 * @since 197.0.0
 */
final class V197SyntheticEventFactoryAndSchemaEvolutionTest extends TestCase
{
    // ── SyntheticEventFactory Tests ────────────────────────────────

    public function testFactoryCanBeInstantiatedWithDefaults(): void
    {
        $factory = new SyntheticEventFactory;

        $this->assertNotNull($factory);
        $this->assertGreaterThan(0, $factory->poolSize());
    }

    public function testFactoryPoolContainsAllCategories(): void
    {
        $factory = new SyntheticEventFactory;
        $stats   = $factory->poolStats();

        $this->assertArrayHasKey('ecommerce', $stats);
        $this->assertArrayHasKey('saas', $stats);
        $this->assertArrayHasKey('engagement', $stats);
        $this->assertArrayHasKey('total', $stats);

        $this->assertGreaterThan(0, $stats['ecommerce']);
        $this->assertGreaterThan(0, $stats['saas']);
        $this->assertGreaterThan(0, $stats['engagement']);
        $this->assertEquals(
            $stats['ecommerce'] + $stats['saas'] + $stats['engagement'] + $stats['total'] % $stats['total'],
            $stats['total'],
        );
        $this->assertEquals(
            $stats['ecommerce'] + $stats['saas'] + $stats['engagement'],
            $stats['total'],
        );
    }

    public function testGenerateEventReturnsAnalyticsEventDto(): void
    {
        $factory = new SyntheticEventFactory;
        $event   = $factory->generateEvent();

        $this->assertNotNull($event);
        $this->assertNotEmpty($event->name);
        $this->assertNotEmpty($event->clientId);
        $this->assertIsArray($event->params);
    }

    public function testGenerateEventRestrictsToCategory(): void
    {
        $factory = new SyntheticEventFactory;

        $ecommerceEvent = $factory->generateEvent('ecommerce');
        $this->assertTrue(EcommerceEvents::has($ecommerceEvent->name));

        $saasEvent = $factory->generateEvent('saas');
        $this->assertTrue(SaaSEvents::has($saasEvent->name));

        $engagementEvent = $factory->generateEvent('engagement');
        $this->assertTrue(EngagementEvents::has($engagementEvent->name));
    }

    public function testGenerateEventWithCustomIdentity(): void
    {
        $factory = new SyntheticEventFactory;
        $event   = $factory->generateEvent(null, 'custom_client_123', 'user_456');

        $this->assertSame('custom_client_123', $event->clientId);
        $this->assertSame('user_456', $event->userId);
    }

    public function testGenerateSessionReturnsCorrectCount(): void
    {
        $factory  = new SyntheticEventFactory;
        $session  = $factory->generateSession(5);

        $this->assertCount(5, $session);

        // All events in a session should share the same client ID
        $clientIds = array_unique(array_map(fn ($e) => $e->clientId, $session));
        $this->assertCount(1, $clientIds, 'All session events should share a single client ID');
    }

    public function testGenerateSessionEventsAreAnalyticsEventInstances(): void
    {
        $factory = new SyntheticEventFactory;
        $session = $factory->generateSession(3);

        foreach ($session as $event) {
            $this->assertNotEmpty($event->name);
            $this->assertNotEmpty($event->clientId);
            $this->assertNotEmpty($event->userId);
        }
    }

    public function testGenerateConversionFunnelFollowsExpectedSequence(): void
    {
        $factory = new SyntheticEventFactory;
        $funnel  = $factory->generateConversionFunnel();

        $saasNames = array_map(fn ($e) => $e->name, $funnel);

        // Must contain all funnel steps (interleaved with engagement events)
        $this->assertContains('sign_up', $saasNames);
        $this->assertContains('start_trial', $saasNames);
        $this->assertContains('subscribe', $saasNames);
        $this->assertContains('plan_upgrade', $saasNames);

        // sign_up should appear before subscribe
        $signUpIdx    = array_search('sign_up', $saasNames, true);
        $subscribeIdx = array_search('subscribe', $saasNames, true);
        $this->assertLessThan($subscribeIdx, $signUpIdx);
    }

    public function testGenerateConversionFunnelHasSharedIdentity(): void
    {
        $factory = new SyntheticEventFactory;
        $funnel  = $factory->generateConversionFunnel();

        $clientIds = array_unique(array_map(fn ($e) => $e->clientId, $funnel));
        $userIds   = array_unique(array_map(fn ($e) => $e->userId, $funnel));

        $this->assertCount(1, $clientIds);
        $this->assertCount(1, $userIds);
    }

    public function testGenerateBatchReturnsCorrectCount(): void
    {
        $factory = new SyntheticEventFactory;
        $batch   = $factory->generateBatch(20);

        $this->assertCount(20, $batch);
    }

    public function testGenerateBatchRestrictsToCategory(): void
    {
        $factory = new SyntheticEventFactory;
        $batch   = $factory->generateBatch(10, 'ecommerce');

        foreach ($batch as $event) {
            $this->assertTrue(
                EcommerceEvents::has($event->name),
                "Event '{$event->name}' should be in the ecommerce catalog",
            );
        }
    }

    public function testGenerateMultipleSessionsCreatesCorrectCount(): void
    {
        $factory  = new SyntheticEventFactory;
        $sessions = $factory->generateMultipleSessions(3, 5);

        $this->assertCount(3, $sessions);
        foreach ($sessions as $session) {
            $this->assertCount(5, $session);
        }
    }

    public function testGenerateMultipleSessionsUsesDifferentClientIds(): void
    {
        $factory  = new SyntheticEventFactory;
        $sessions = $factory->generateMultipleSessions(3, 3);

        $allClientIds = [];
        foreach ($sessions as $session) {
            foreach ($session as $event) {
                $allClientIds[] = $event->clientId;
            }
        }

        // Each session should have a unique client ID
        $uniqueClientIds = array_unique($allClientIds);
        $this->assertCount(3, $uniqueClientIds);
    }

    public function testGenerateEcommerceJourneyFollowsPurchaseFunnel(): void
    {
        $factory = new SyntheticEventFactory;
        $journey = $factory->generateEcommerceJourney();

        $names = array_map(fn ($e) => $e->name, $journey);

        $this->assertContains('view_item', $names);
        $this->assertContains('add_to_cart', $names);
        $this->assertContains('begin_checkout', $names);
        $this->assertContains('purchase', $names);

        // Correct ordering: view_item before purchase
        $viewIdx     = array_search('view_item', $names, true);
        $purchaseIdx = array_search('purchase', $names, true);
        $this->assertLessThan($purchaseIdx, $viewIdx);
    }

    public function testEcommercePurchaseHasTransactionId(): void
    {
        $factory = new SyntheticEventFactory;
        $journey = $factory->generateEcommerceJourney();
        $purchase = array_filter($journey, fn ($e) => $e->name === 'purchase');

        foreach ($purchase as $event) {
            $this->assertArrayHasKey('transaction_id', $event->params);
            $this->assertStringStartsWith('TXN-SYN-', $event->params['transaction_id']);
            $this->assertArrayHasKey('value', $event->params);
            $this->assertArrayHasKey('currency', $event->params);
        }
    }

    public function testEcommerceRefundHasReason(): void
    {
        $factory = new SyntheticEventFactory;
        $events  = [];
        for ($i = 0; $i < 100; $i++) {
            $event = $factory->generateEvent('ecommerce');
            if ($event->name === 'refund') {
                $events[] = $event;
                break;
            }
        }

        if (!empty($events)) {
            $this->assertArrayHasKey('reason', $events[0]->params);
        }
    }

    public function testSaaSParamsHavePlan(): void
    {
        $factory = new SyntheticEventFactory;
        $found   = false;

        for ($i = 0; $i < 100; $i++) {
            $event = $factory->generateEvent('saas');
            if (in_array($event->name, ['subscribe', 'plan_upgrade', 'cancellation'], true)) {
                $this->assertArrayHasKey('plan', $event->params);
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Should find at least one plan-bearing SaaS event in 100 attempts');
    }

    public function testEngagementPageViewHasPage(): void
    {
        $factory = new SyntheticEventFactory;
        $events  = [];

        for ($i = 0; $i < 100; $i++) {
            $event = $factory->generateEvent('engagement');
            if ($event->name === 'page_view') {
                $events[] = $event;
                break;
            }
        }

        if (!empty($events)) {
            $this->assertArrayHasKey('page', $events[0]->params);
            $this->assertStringStartsWith('/', $events[0]->params['page']);
        }
    }

    public function testConfigurationSummary(): void
    {
        $factory  = new SyntheticEventFactory;
        $summary  = $factory->configurationSummary();

        $this->assertArrayHasKey('category_weights', $summary);
        $this->assertArrayHasKey('session_depth', $summary);
        $this->assertArrayHasKey('pools', $summary);
        $this->assertArrayHasKey('funnel_steps', $summary);

        $this->assertEquals(4, count($summary['funnel_steps']));
        $this->assertEquals('sign_up', $summary['funnel_steps'][0]);
        $this->assertEquals('plan_upgrade', $summary['funnel_steps'][3]);
    }

    public function testFactoryWithCustomWeights(): void
    {
        $factory = new SyntheticEventFactory([
            'ecommerce'  => 0.5,
            'saas'       => 0.3,
            'engagement' => 0.2,
        ]);

        $this->assertGreaterThan(0, $factory->poolSize());

        $stats = $factory->poolStats();
        $this->assertGreaterThan(0, $stats['ecommerce']);
        $this->assertGreaterThan(0, $stats['saas']);
        $this->assertGreaterThan(0, $stats['engagement']);
    }

    // ── EventSchemaEvolutionTracker Tests ──────────────────────────

    public function testTrackerCanBeInstantiated(): void
    {
        $tracker = new EventSchemaEvolutionTracker;

        $this->assertNotNull($tracker);
        $this->assertSame([], $tracker->getChanges());
        $this->assertSame([], $tracker->snapshotVersions());
    }

    public function testRegisterSnapshotAndRetrieve(): void
    {
        $tracker = new EventSchemaEvolutionTracker;

        $tracker->registerSnapshot('1.0.0', [
            'ecommerce'  => ['view_item', 'purchase'],
            'saas'       => ['sign_up'],
            'engagement' => ['page_view'],
        ]);

        $snap = $tracker->getSnapshot('1.0.0');

        $this->assertNotNull($snap);
        $this->assertSame('1.0.0', $snap->version);
        $this->assertSame(3, $snap->totalEvents());
        $this->assertSame(3, $snap->categoryCount());
        $this->assertSame('ecommerce', $snap->categoryFor('view_item'));
        $this->assertSame('saas', $snap->categoryFor('sign_up'));
    }

    public function testSnapshotFromCatalogs(): void
    {
        $tracker = new EventSchemaEvolutionTracker;
        $tracker->snapshotFromCatalogs('196.0.0');

        $snap = $tracker->getSnapshot('196.0.0');

        $this->assertNotNull($snap);
        $this->assertSame('196.0.0', $snap->version);
        $this->assertGreaterThan(0, $snap->totalEvents());
    }

    public function testDiffDetectsAddedEvents(): void
    {
        $tracker = new EventSchemaEvolutionTracker;

        $tracker->registerSnapshot('1.0.0', [
            'ecommerce'  => ['view_item'],
            'saas'       => ['sign_up'],
            'engagement' => ['page_view'],
        ]);

        $tracker->registerSnapshot('2.0.0', [
            'ecommerce'  => ['view_item', 'purchase'],
            'saas'       => ['sign_up', 'login'],
            'engagement' => ['page_view'],
        ]);

        $changes = $tracker->diff('1.0.0', '2.0.0');

        $added = array_filter($changes, fn ($c) => $c->type === 'added');
        $this->assertCount(2, $added);

        $addedNames = array_map(fn ($c) => $c->eventName, $added);
        $this->assertContains('purchase', $addedNames);
        $this->assertContains('login', $addedNames);
    }

    public function testDiffDetectsRemovedEvents(): void
    {
        $tracker = new EventSchemaEvolutionTracker;

        $tracker->registerSnapshot('1.0.0', [
            'ecommerce'  => ['view_item', 'purchase', 'refund'],
            'saas'       => ['sign_up', 'login'],
            'engagement' => ['page_view'],
        ]);

        $tracker->registerSnapshot('2.0.0', [
            'ecommerce'  => ['view_item'],
            'saas'       => ['sign_up'],
            'engagement' => ['page_view'],
        ]);

        $changes = $tracker->diff('1.0.0', '2.0.0');
        $removed = array_filter($changes, fn ($c) => $c->type === 'removed');

        $this->assertCount(2, $removed);
    }

    public function testDiffDetectsCategoryAdded(): void
    {
        $tracker = new EventSchemaEvolutionTracker;

        $tracker->registerSnapshot('1.0.0', [
            'ecommerce'  => ['view_item'],
        ]);

        $tracker->registerSnapshot('2.0.0', [
            'ecommerce'  => ['view_item'],
            'saas'       => ['sign_up'],
        ]);

        $changes = $tracker->diff('1.0.0', '2.0.0');
        $catAdded = array_filter($changes, fn ($c) => $c->type === 'category_added');

        $this->assertCount(1, $catAdded);
    }

    public function testDiffDetectsCategoryRemoved(): void
    {
        $tracker = new EventSchemaEvolutionTracker;

        $tracker->registerSnapshot('1.0.0', [
            'ecommerce'  => ['view_item'],
            'saas'       => ['sign_up'],
        ]);

        $tracker->registerSnapshot('2.0.0', [
            'ecommerce'  => ['view_item'],
        ]);

        $changes = $tracker->diff('1.0.0', '2.0.0');
        $catRemoved = array_filter($changes, fn ($c) => $c->type === 'category_removed');

        $this->assertCount(1, $catRemoved);
    }

    public function testDiffNoChangesBetweenIdenticalSnapshots(): void
    {
        $tracker = new EventSchemaEvolutionTracker;

        $tracker->registerSnapshot('1.0.0', [
            'ecommerce'  => ['view_item'],
            'saas'       => ['sign_up'],
        ]);

        $tracker->registerSnapshot('1.0.0-copy', [
            'ecommerce'  => ['view_item'],
            'saas'       => ['sign_up'],
        ]);

        $changes = $tracker->diff('1.0.0', '1.0.0-copy');
        $this->assertSame([], $changes);
    }

    public function testAnalyzeReportIsBreakingForRemovedEvents(): void
    {
        $tracker = new EventSchemaEvolutionTracker;

        $tracker->registerSnapshot('1.0.0', [
            'ecommerce'  => ['view_item', 'purchase'],
            'saas'       => ['sign_up'],
            'engagement' => ['page_view'],
        ]);

        $tracker->registerSnapshot('2.0.0', [
            'ecommerce'  => ['view_item'],
            'saas'       => ['sign_up'],
            'engagement' => ['page_view'],
        ]);

        $report = $tracker->analyze('1.0.0', '2.0.0');

        $this->assertSame('1.0.0', $report->fromVersion);
        $this->assertSame('2.0.0', $report->toVersion);
        $this->assertTrue($report->isBreaking);
        $this->assertCount(1, $report->breaking);
        $this->assertCount(0, $report->nonBreaking);
    }

    public function testAnalyzeReportNotBreakingForAdditionsOnly(): void
    {
        $tracker = new EventSchemaEvolutionTracker;

        $tracker->registerSnapshot('1.0.0', [
            'ecommerce'  => ['view_item'],
        ]);

        $tracker->registerSnapshot('2.0.0', [
            'ecommerce'  => ['view_item', 'purchase'],
        ]);

        $report = $tracker->analyze('1.0.0', '2.0.0');

        $this->assertFalse($report->isBreaking);
        $this->assertCount(0, $report->breaking);
        $this->assertCount(1, $report->nonBreaking);
    }

    public function testAnalyzeReportSummaryIsReadable(): void
    {
        $tracker = new EventSchemaEvolutionTracker;

        $tracker->registerSnapshot('1.0.0', [
            'ecommerce'  => ['view_item'],
        ]);

        $tracker->registerSnapshot('2.0.0', [
            'ecommerce'  => ['view_item', 'purchase'],
        ]);

        $report = $tracker->analyze('1.0.0', '2.0.0');
        $summary = $report->summary();

        $this->assertStringContainsString('1.0.0', $summary);
        $this->assertStringContainsString('2.0.0', $summary);
        $this->assertStringContainsString('NO', $summary);
    }

    public function testBreakingChangePolicyForRemoval(): void
    {
        $tracker = new EventSchemaEvolutionTracker;

        $change = new \ZeroBoiler\Analytics\Services\EventChange(
            type:        'removed',
            eventName:   'purchase',
            category:    'ecommerce',
            fromVersion: '1.0.0',
            toVersion:   '2.0.0',
        );

        $this->assertTrue($tracker->isBreaking($change));
    }

    public function testNonBreakingChangePolicyForAddition(): void
    {
        $tracker = new EventSchemaEvolutionTracker;

        $change = new \ZeroBoiler\Analytics\Services\EventChange(
            type:        'added',
            eventName:   'new_event',
            category:    'engagement',
            fromVersion: '1.0.0',
            toVersion:   '2.0.0',
        );

        $this->assertFalse($tracker->isBreaking($change));
    }

    public function testEventChangeIsAdditive(): void
    {
        $added = new \ZeroBoiler\Analytics\Services\EventChange(
            type:        'added',
            eventName:   'test',
            category:    'saas',
            fromVersion: '1.0.0',
            toVersion:   '2.0.0',
        );

        $this->assertTrue($added->isAdditive());
        $this->assertFalse($added->isDestructive());
    }

    public function testEventChangeIsDestructive(): void
    {
        $removed = new \ZeroBoiler\Analytics\Services\EventChange(
            type:        'removed',
            eventName:   'test',
            category:    'saas',
            fromVersion: '1.0.0',
            toVersion:   '2.0.0',
        );

        $this->assertFalse($removed->isAdditive());
        $this->assertTrue($removed->isDestructive());
    }

    public function testCatalogSnapshotEventsNotIn(): void
    {
        $snapA = new \ZeroBoiler\Analytics\Services\CatalogSnapshot(
            version: '1.0.0',
            eventsByCategory: ['ecommerce' => ['a', 'b'], 'saas' => ['c']],
            eventDetails: [],
        );

        $snapB = new \ZeroBoiler\Analytics\Services\CatalogSnapshot(
            version: '2.0.0',
            eventsByCategory: ['ecommerce' => ['a'], 'saas' => ['c', 'd']],
            eventDetails: [],
        );

        $onlyInA = $snapA->eventsNotIn($snapB);
        $onlyInB = $snapB->eventsNotIn($snapA);

        $this->assertCount(1, $onlyInA);
        $this->assertSame('b', $onlyInA[0]->name);
        $this->assertSame('ecommerce', $onlyInA[0]->category);

        $this->assertCount(1, $onlyInB);
        $this->assertSame('d', $onlyInB[0]->name);
    }

    // ── Integration: Factory + Tracker ────────────────────────────

    public function testFactoryAndTrackerCrossValidate(): void
    {
        $factory = new SyntheticEventFactory;
        $tracker = new EventSchemaEvolutionTracker;

        // Snapshot from current catalogs
        $tracker->snapshotFromCatalogs('197.0.0');

        $snap = $tracker->latestSnapshot();
        $this->assertNotNull($snap);

        // All factory-generated events should be resolvable in the snapshot
        for ($i = 0; $i < 50; $i++) {
            $event = $factory->generateEvent();
            $cat   = $snap->categoryFor($event->name);

            $this->assertNotNull(
                $cat,
                "Generated event '{$event->name}' should exist in the current catalog snapshot",
            );
        }
    }

    public function testVersionIntegrity(): void
    {
        $tracker = new EventSchemaEvolutionTracker;
        $tracker->snapshotFromCatalogs('197.0.0');

        $snap = $tracker->latestSnapshot();
        $this->assertSame('197.0.0', $snap->version);
        $this->assertContains('197.0.0', $tracker->snapshotVersions());
    }
}
