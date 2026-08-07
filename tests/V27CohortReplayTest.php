<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\Queue\EventReplayQueue;
use ZeroBoiler\Analytics\Services\CohortAnalyticsService;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

// ── CohortAnalyticsService Tests ─────────────────────────────────────

describe('CohortAnalyticsService', function () {
    it('can be instantiated', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                    'queue' => ['enabled' => false],
                    'metrics' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new \ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher($manager, $config);

        $service = new CohortAnalyticsService($manager, $queue, false);

        expect($service)->toBeInstanceOf(CohortAnalyticsService::class);
        expect($service->getManager())->toBe($manager);
    });

    it('tracks cohort_assigned event', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                    'queue' => ['enabled' => false],
                    'metrics' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new \ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher($manager, $config);

        $service = new CohortAnalyticsService($manager, $queue, false);
        $service->assignCohort('user-42', '2026-W32', 'signup', ['source' => 'google']);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->not->toBeEmpty();
        expect($layer[0]['event'])->toBe('cohort_assigned');
        expect($layer[0]['cohort_name'])->toBe('2026-W32');
        expect($layer[0]['cohort_type'])->toBe('signup');
        expect($layer[0]['user_id'])->toBe('user-42');
        expect($layer[0]['source'])->toBe('google');
    });

    it('tracks cohort_retention event', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                    'queue' => ['enabled' => false],
                    'metrics' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new \ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher($manager, $config);

        $service = new CohortAnalyticsService($manager, $queue, false);
        $service->trackRetention('user-42', '2026-W32', 7);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->not->toBeEmpty();
        expect($layer[0]['event'])->toBe('cohort_retention');
        expect($layer[0]['retention_day'])->toBe(7);
        expect($layer[0]['retention_period'])->toBe('d7');
    });

    it('tracks cohort_churn event', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                    'queue' => ['enabled' => false],
                    'metrics' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new \ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher($manager, $config);

        $service = new CohortAnalyticsService($manager, $queue, false);
        $service->trackChurn('user-42', '2026-W32', 30, 'too_expensive');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->not->toBeEmpty();
        expect($layer[0]['event'])->toBe('cohort_churn');
        expect($layer[0]['churn_day'])->toBe(30);
        expect($layer[0]['churn_period'])->toBe('d30');
        expect($layer[0]['churn_reason'])->toBe('too_expensive');
    });

    it('tracks cohort_conversion event', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                    'queue' => ['enabled' => false],
                    'metrics' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new \ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher($manager, $config);

        $service = new CohortAnalyticsService($manager, $queue, false);
        $service->trackConversion('user-42', '2026-W32', 'trial_to_paid', ['plan' => 'pro']);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->not->toBeEmpty();
        expect($layer[0]['event'])->toBe('cohort_conversion');
        expect($layer[0]['conversion_type'])->toBe('trial_to_paid');
        expect($layer[0]['plan'])->toBe('pro');
    });

    it('tracks cohort_migration event', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                    'queue' => ['enabled' => false],
                    'metrics' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new \ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher($manager, $config);

        $service = new CohortAnalyticsService($manager, $queue, false);
        $service->trackMigration('user-42', '2026-W32', '2026-W33', ['reason' => 'plan_change']);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->not->toBeEmpty();
        expect($layer[0]['event'])->toBe('cohort_migration');
        expect($layer[0]['from_cohort'])->toBe('2026-W32');
        expect($layer[0]['to_cohort'])->toBe('2026-W33');
    });

    it('tracks cohort_engagement summary', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                    'queue' => ['enabled' => false],
                    'metrics' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new \ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher($manager, $config);

        $service = new CohortAnalyticsService($manager, $queue, false);
        $service->trackEngagementSummary('2026-W32', 85, 120, 'weekly');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->not->toBeEmpty();
        expect($layer[0]['event'])->toBe('cohort_engagement');
        expect($layer[0]['active_users'])->toBe(85);
        expect($layer[0]['total_users'])->toBe(120);
        expect($layer[0]['engagement_rate'])->toBe(70.83);
        expect($layer[0]['period'])->toBe('weekly');
    });

    it('calculates engagement rate correctly for zero total', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                    'queue' => ['enabled' => false],
                    'metrics' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $queue = new \ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher($manager, $config);

        $service = new CohortAnalyticsService($manager, $queue, false);
        $service->trackEngagementSummary('2026-W33', 0, 0, 'daily');

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->not->toBeEmpty();
        expect($layer[0]['engagement_rate'])->toBe(0.0);
    });
});

// ── Cohort Name Generation Tests ──────────────────────────────────────

describe('CohortAnalyticsService::generateCohortName', function () {
    it('generates weekly cohort name', function () {
        $name = CohortAnalyticsService::generateCohortName('weekly', '2026-08-06');
        expect($name)->toBe('2026-W32');
    });

    it('generates monthly cohort name', function () {
        $name = CohortAnalyticsService::generateCohortName('monthly', '2026-08-06');
        expect($name)->toBe('2026-08');
    });

    it('generates daily cohort name', function () {
        $name = CohortAnalyticsService::generateCohortName('daily', '2026-08-06');
        expect($name)->toBe('2026-08-06');
    });

    it('generates quarterly cohort name', function () {
        $name = CohortAnalyticsService::generateCohortName('quarterly', '2026-01-15');
        expect($name)->toBe('Q1-2026');
    });

    it('uses current date when date is null', function () {
        $name = CohortAnalyticsService::generateCohortName('weekly');
        expect($name)->toBeString();
        expect($name)->toMatch('/^\d{4}-W\d{2}$/');
    });

    it('handles invalid date gracefully', function () {
        $name = CohortAnalyticsService::generateCohortName('weekly', 'not-a-date');
        expect($name)->toBeString();
    });
});

// ── Cohort Period Classification Tests ────────────────────────────────

describe('CohortAnalyticsService::classifyPeriod', function () {
    it('classifies day 1', function () {
        expect(CohortAnalyticsService::classifyPeriod(1))->toBe('d1');
    });

    it('classifies day 3', function () {
        expect(CohortAnalyticsService::classifyPeriod(3))->toBe('d7');
    });

    it('classifies day 7', function () {
        expect(CohortAnalyticsService::classifyPeriod(7))->toBe('d7');
    });

    it('classifies day 14', function () {
        expect(CohortAnalyticsService::classifyPeriod(14))->toBe('d14');
    });

    it('classifies day 30', function () {
        expect(CohortAnalyticsService::classifyPeriod(30))->toBe('d30');
    });

    it('classifies day 45', function () {
        expect(CohortAnalyticsService::classifyPeriod(45))->toBe('d60');
    });

    it('classifies day 90', function () {
        expect(CohortAnalyticsService::classifyPeriod(90))->toBe('d90');
    });

    it('classifies day 180', function () {
        expect(CohortAnalyticsService::classifyPeriod(180))->toBe('d180');
    });

    it('classifies day 365', function () {
        expect(CohortAnalyticsService::classifyPeriod(365))->toBe('d365');
    });

    it('classifies day 500 as d365+', function () {
        expect(CohortAnalyticsService::classifyPeriod(500))->toBe('d365+');
    });
});

// ── EventReplayQueue Tests ───────────────────────────────────────────

describe('EventReplayQueue', function () {
    it('can be instantiated', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => false],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                    'replay' => [
                        'enabled' => true,
                        'max_attempts' => 3,
                        'base_delay' => 0.01,
                        'max_delay' => 1.0,
                        'jitter' => 0.0,
                    ],
                    'metrics' => ['enabled' => true],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $metrics = $manager->metrics();

        $replay = new EventReplayQueue($manager, $metrics, $config);

        expect($replay)->toBeInstanceOf(EventReplayQueue::class);
        expect($replay->isEnabled())->toBeTrue();
        expect($replay->pendingCount())->toBe(0);
        expect($replay->failedCount())->toBe(0);
    });

    it('enqueues failed events', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => false],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                    'replay' => [
                        'enabled' => true,
                        'max_attempts' => 3,
                        'base_delay' => 0.01,
                        'max_delay' => 1.0,
                        'jitter' => 0.0,
                    ],
                    'metrics' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        $replay = new EventReplayQueue($manager, $manager->metrics(), $config);

        $event = new AnalyticsEvent(name: 'test_event', params: ['key' => 'value']);
        $replay->enqueue($event, new \RuntimeException('Connection failed'));

        expect($replay->pendingCount())->toBe(1);
    });

    it('does not enqueue when disabled', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => false],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                    'replay' => [
                        'enabled' => false,
                    ],
                    'metrics' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        $replay = new EventReplayQueue($manager, $manager->metrics(), $config);

        $event = new AnalyticsEvent(name: 'test_event', params: []);
        $replay->enqueue($event, new \RuntimeException('Fail'));

        expect($replay->pendingCount())->toBe(0);
    });

    it('returns summary', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => false],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                    'replay' => [
                        'enabled' => true,
                        'max_attempts' => 5,
                        'base_delay' => 2.0,
                        'max_delay' => 120.0,
                        'jitter' => 0.3,
                    ],
                    'metrics' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        $replay = new EventReplayQueue($manager, $manager->metrics(), $config);
        $summary = $replay->summary();

        expect($summary)->toBeArray();
        expect($summary['enabled'])->toBeTrue();
        expect($summary['pending'])->toBe(0);
        expect($summary['failed'])->toBe(0);
        expect($summary['max_attempts'])->toBe(5);
        expect($summary['base_delay'])->toBe(2.0);
        expect($summary['max_delay'])->toBe(120.0);
    });

    it('flush clears all events', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => false],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                    'replay' => ['enabled' => true],
                    'metrics' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        $replay = new EventReplayQueue($manager, $manager->metrics(), $config);

        $event = new AnalyticsEvent(name: 'test', params: []);
        $replay->enqueue($event, new \RuntimeException('fail'));
        expect($replay->pendingCount())->toBe(1);

        $replay->flush();
        expect($replay->pendingCount())->toBe(0);
        expect($replay->failedCount())->toBe(0);
    });

    it('process returns empty result when disabled', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => false],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                    'replay' => ['enabled' => false],
                    'metrics' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        $replay = new EventReplayQueue($manager, $manager->metrics(), $config);
        $result = $replay->process();

        expect($result)->toBe(['retried' => 0, 'succeeded' => 0, 'failed' => 0]);
    });
});

// ── Cohort Event Catalog Integration ──────────────────────────────────

describe('Cohort events in catalog', function () {
    it('cohort_assigned exists in SaaS catalog', function () {
        expect(SaaSEvents::has('cohort_assigned'))->toBeTrue();
    });

    it('cohort_retention exists in SaaS catalog', function () {
        expect(SaaSEvents::has('cohort_retention'))->toBeTrue();
    });

    it('cohort_churn exists in SaaS catalog', function () {
        expect(SaaSEvents::has('cohort_churn'))->toBeTrue();
    });

    it('cohort_conversion exists in SaaS catalog', function () {
        expect(SaaSEvents::has('cohort_conversion'))->toBeTrue();
    });

    it('cohort_migration exists in SaaS catalog', function () {
        expect(SaaSEvents::has('cohort_migration'))->toBeTrue();
    });

    it('cohort_engagement exists in SaaS catalog', function () {
        expect(SaaSEvents::has('cohort_engagement'))->toBeTrue();
    });

    it('all cohort events exist in EventCatalog', function () {
        $cohortEvents = ['cohort_assigned', 'cohort_retention', 'cohort_churn', 'cohort_conversion', 'cohort_migration', 'cohort_engagement'];

        foreach ($cohortEvents as $eventName) {
            expect(EventCatalog::has($eventName))->toBeTrue("Expected '{$eventName}' in EventCatalog");
        }
    });

    it('SaaS catalog count increased to 17', function () {
        expect(SaaSEvents::count())->toBe(17);
    });

    it('total event count increased', function () {
        // Ecommerce: 9, SaaS: 17, Engagement: 13 = 39
        expect(EventCatalog::count())->toBe(39);
    });
});

// ── Cohort Event Schema Tests ────────────────────────────────────────

describe('Cohort event schemas', function () {
    it('has cohort_assigned schema', function () {
        $registry = new EventSchemaRegistry;

        expect($registry->has('cohort_assigned'))->toBeTrue();
        $schema = $registry->get('cohort_assigned');
        expect($schema)->not->toBeNull();
        expect($schema->category)->toBe('saas');
    });

    it('validates cohort_assigned with required params', function () {
        $registry = new EventSchemaRegistry;

        $result = $registry->validate('cohort_assigned', [
            'user_id' => 'user-42',
            'cohort_name' => '2026-W32',
            'cohort_type' => 'signup',
        ]);

        expect($result['valid'])->toBeTrue();
    });

    it('rejects cohort_assigned without required params', function () {
        $registry = new EventSchemaRegistry;

        $result = $registry->validate('cohort_assigned', [
            'cohort_name' => '2026-W32',
        ]);

        expect($result['valid'])->toBeFalse();
    });

    it('has cohort_retention schema with retention_day required', function () {
        $registry = new EventSchemaRegistry;

        $result = $registry->validate('cohort_retention', [
            'user_id' => 'user-42',
            'cohort_name' => '2026-W32',
            'retention_day' => 7,
        ]);

        expect($result['valid'])->toBeTrue();
    });

    it('has cohort_churn schema', function () {
        $registry = new EventSchemaRegistry;

        $result = $registry->validate('cohort_churn', [
            'user_id' => 'user-42',
            'cohort_name' => '2026-W32',
            'churn_reason' => 'too_expensive',
        ]);

        expect($result['valid'])->toBeTrue();
    });

    it('has cohort_engagement schema', function () {
        $registry = new EventSchemaRegistry;

        $result = $registry->validate('cohort_engagement', [
            'cohort_name' => '2026-W32',
            'active_users' => 85,
            'total_users' => 120,
            'period' => 'weekly',
        ]);

        expect($result['valid'])->toBeTrue();
    });

    it('schema count increased with cohort events', function () {
        $registry = new EventSchemaRegistry;
        // v2.6 had ~46 schemas, v2.7 adds 6 cohort schemas
        expect($registry->count())->toBeGreaterThanOrEqual(50);
    });
});

// ── Version Consistency ──────────────────────────────────────────────

describe('Version v2.7.0 consistency', function () {
    it('AnalyticsManager reports v2.7.0', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        expect($manager->version())->toBe('2.41.0');
    });

    it('event catalog summary reflects expanded SaaS', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST1'],
                    'consent' => ['default' => 'granted'],
                    'debug' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'posthog' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $summary = $manager->eventCatalogSummary();

        expect($summary['ecommerce'])->toBe(9);
        expect($summary['saas'])->toBe(17);
        expect($summary['engagement'])->toBe(13);
        expect($summary['total'])->toBe(39);
    });

    it('search finds cohort events', function () {
        $results = EventCatalog::search('cohort');
        expect($results)->not->toBeEmpty();
        expect(count($results))->toBe(6);

        $names = array_map(fn (array $e): string => $e['name'], $results);
        expect($names)->toContain('cohort_assigned');
        expect($names)->toContain('cohort_retention');
        expect($names)->toContain('cohort_churn');
        expect($names)->toContain('cohort_conversion');
        expect($names)->toContain('cohort_migration');
        expect($names)->toContain('cohort_engagement');
    });
});
