<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\SaaS\CohortAssignedEvent;
use ZeroBoiler\Analytics\Events\SaaS\CohortRetentionEvent;
use ZeroBoiler\Analytics\Events\SaaS\CohortChurnEvent;
use ZeroBoiler\Analytics\Events\SaaS\CohortConversionEvent;
use ZeroBoiler\Analytics\Events\SaaS\CohortMigrationEvent;
use ZeroBoiler\Analytics\Events\SaaS\CohortEngagementEvent;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;

describe('V219 Cohort Typed Event Classes', function () {
    describe('CohortAssignedEvent', function () {
        it('constructs with required params', function () {
            $event = new CohortAssignedEvent('2026-W32', 'user-42');

            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('cohort_assigned');
            expect($event->params['cohort_name'])->toBe('2026-W32');
            expect($event->params['user_id'])->toBe('user-42');
        });

        it('constructs with all params', function () {
            $event = new CohortAssignedEvent('2026-08', 'user-42', 'signup', ['plan' => 'pro']);

            expect($event->params['cohort_name'])->toBe('2026-08');
            expect($event->params['user_id'])->toBe('user-42');
            expect($event->params['source'])->toBe('signup');
            expect($event->params['plan'])->toBe('pro');
        });

        it('filters null values', function () {
            $event = new CohortAssignedEvent('2026-W32', 'user-42', null);

            expect($event->params)->toHaveKey('cohort_name');
            expect($event->params)->toHaveKey('user_id');
            expect($event->params)->not->toHaveKey('source');
        });

        it('filters empty string values', function () {
            $event = new CohortAssignedEvent('2026-W32', 'user-42', '');

            expect($event->params)->not->toHaveKey('source');
        });
    });

    describe('CohortRetentionEvent', function () {
        it('constructs with required params', function () {
            $event = new CohortRetentionEvent('2026-W32', 'user-42', 7);

            expect($event->name)->toBe('cohort_retention');
            expect($event->params['cohort_name'])->toBe('2026-W32');
            expect($event->params['user_id'])->toBe('user-42');
            expect($event->params['days_since_start'])->toBe(7);
        });

        it('constructs with period label', function () {
            $event = new CohortRetentionEvent('2026-W32', 'user-42', 30, 'd30');

            expect($event->params['period'])->toBe('d30');
        });

        it('constructs with additional params', function () {
            $event = new CohortRetentionEvent('2026-W32', 'user-42', 7, 'd7', ['active_features' => 5]);

            expect($event->params['active_features'])->toBe(5);
        });
    });

    describe('CohortChurnEvent', function () {
        it('constructs with required params', function () {
            $event = new CohortChurnEvent('2026-W32', 'user-42', 15);

            expect($event->name)->toBe('cohort_churn');
            expect($event->params['cohort_name'])->toBe('2026-W32');
            expect($event->params['user_id'])->toBe('user-42');
            expect($event->params['days_since_start'])->toBe(15);
        });

        it('constructs with reason', function () {
            $event = new CohortChurnEvent('2026-W32', 'user-42', 15, 'too_expensive');

            expect($event->params['reason'])->toBe('too_expensive');
        });

        it('constructs with additional params', function () {
            $event = new CohortChurnEvent('2026-W32', 'user-42', 15, 'inactive', ['last_active_days' => 45]);

            expect($event->params['last_active_days'])->toBe(45);
        });
    });

    describe('CohortConversionEvent', function () {
        it('constructs with required params', function () {
            $event = new CohortConversionEvent('2026-W32', 'user-42', 'trial_to_paid');

            expect($event->name)->toBe('cohort_conversion');
            expect($event->params['cohort_name'])->toBe('2026-W32');
            expect($event->params['user_id'])->toBe('user-42');
            expect($event->params['conversion_type'])->toBe('trial_to_paid');
        });

        it('constructs with additional params', function () {
            $event = new CohortConversionEvent('2026-W32', 'user-42', 'free_to_premium', ['plan' => 'pro', 'value' => 99.99]);

            expect($event->params['plan'])->toBe('pro');
            expect($event->params['value'])->toBe(99.99);
        });
    });

    describe('CohortMigrationEvent', function () {
        it('constructs with required params', function () {
            $event = new CohortMigrationEvent('user-42', '2026-W32', '2026-W33');

            expect($event->name)->toBe('cohort_migration');
            expect($event->params['user_id'])->toBe('user-42');
            expect($event->params['from_cohort'])->toBe('2026-W32');
            expect($event->params['to_cohort'])->toBe('2026-W33');
        });

        it('constructs with reason', function () {
            $event = new CohortMigrationEvent('user-42', '2026-W32', '2026-W33', 'plan_upgrade');

            expect($event->params['reason'])->toBe('plan_upgrade');
        });

        it('constructs with additional params', function () {
            $event = new CohortMigrationEvent('user-42', 'starter', 'pro', null, ['trigger' => 'admin_reassign']);

            expect($event->params['trigger'])->toBe('admin_reassign');
        });
    });

    describe('CohortEngagementEvent', function () {
        it('constructs with required params', function () {
            $event = new CohortEngagementEvent('2026-W32', 85, 120);

            expect($event->name)->toBe('cohort_engagement');
            expect($event->params['cohort_name'])->toBe('2026-W32');
            expect($event->params['active_users'])->toBe(85);
            expect($event->params['total_users'])->toBe(120);
            expect($event->params['engagement_rate'])->toBe(70.83);
        });

        it('calculates engagement rate correctly', function () {
            $event = new CohortEngagementEvent('2026-W32', 50, 100);

            expect($event->params['engagement_rate'])->toBe(50.0);
        });

        it('handles zero total users', function () {
            $event = new CohortEngagementEvent('2026-W32', 0, 0);

            expect($event->params['engagement_rate'])->toBe(0.0);
        });

        it('constructs with period', function () {
            $event = new CohortEngagementEvent('2026-W32', 85, 120, 'weekly');

            expect($event->params['period'])->toBe('weekly');
        });

        it('constructs with additional params', function () {
            $event = new CohortEngagementEvent('2026-W32', 85, 120, 'monthly', ['avg_events_per_user' => 12.5]);

            expect($event->params['avg_events_per_user'])->toBe(12.5);
        });
    });

    describe('Catalog Integration', function () {
        it('all cohort events have typed classes in the catalog', function () {
            $cohortEvents = [
                'cohort_assigned',
                'cohort_retention',
                'cohort_churn',
                'cohort_conversion',
                'cohort_migration',
                'cohort_engagement',
            ];

            foreach ($cohortEvents as $eventName) {
                $entry = SaaSEvents::get($eventName);
                expect($entry)->not->toBeNull();
                expect($entry['class'])->not->toBe(\ZeroBoiler\Analytics\Events\CustomEvent::class);
                expect($entry['name'])->toBe($eventName);
                expect($entry['ga4'])->toBe($eventName);
            }
        });

        it('cohort classes are proper subclasses of AnalyticsEvent', function () {
            $classes = [
                CohortAssignedEvent::class,
                CohortRetentionEvent::class,
                CohortChurnEvent::class,
                CohortConversionEvent::class,
                CohortMigrationEvent::class,
                CohortEngagementEvent::class,
            ];

            foreach ($classes as $class) {
                expect(new ReflectionClass($class))->isSubclassOf(AnalyticsEvent::class)->toBeTrue();
            }
        });

        it('cohort classes are readonly final', function () {
            $classes = [
                CohortAssignedEvent::class,
                CohortRetentionEvent::class,
                CohortChurnEvent::class,
                CohortConversionEvent::class,
                CohortMigrationEvent::class,
                CohortEngagementEvent::class,
            ];

            foreach ($classes as $class) {
                $ref = new ReflectionClass($class);
                expect($ref->isFinal())->toBeTrue();
                expect($ref->isReadOnly())->toBeTrue();
            }
        });

        it('all SaaS events now use typed classes (no CustomEvent)', function () {
            $all = SaaSEvents::all();

            foreach ($all as $name => $entry) {
                expect($entry['class'])
                    ->not->toBe(\ZeroBoiler\Analytics\Events\CustomEvent::class)
                    ->and("Event '{$name}' still uses CustomEvent");
            }
        });

        it('total SaaS event count is still 17', function () {
            expect(SaaSEvents::count())->toBe(17);
        });

        it('total catalog count is still 49', function () {
            $catalog = \ZeroBoiler\Analytics\Events\EventCatalog::all();
            expect(count($catalog))->toBe(49);
        });

        it('cohort events have category saas', function () {
            $catalog = \ZeroBoiler\Analytics\Events\EventCatalog::all();

            $cohortEvents = ['cohort_assigned', 'cohort_retention', 'cohort_churn', 'cohort_conversion', 'cohort_migration', 'cohort_engagement'];

            foreach ($cohortEvents as $eventName) {
                expect($catalog[$eventName]['category'])->toBe('saas');
            }
        });

        it('all cohort events resolve correctly via classFor', function () {
            $expected = [
                'cohort_assigned' => CohortAssignedEvent::class,
                'cohort_retention' => CohortRetentionEvent::class,
                'cohort_churn' => CohortChurnEvent::class,
                'cohort_conversion' => CohortConversionEvent::class,
                'cohort_migration' => CohortMigrationEvent::class,
                'cohort_engagement' => CohortEngagementEvent::class,
            ];

            foreach ($expected as $name => $expectedClass) {
                expect(SaaSEvents::classFor($name))->toBe($expectedClass);
            }
        });

        it('all cohort events exist in the unified catalog', function () {
            $catalog = \ZeroBoiler\Analytics\Events\EventCatalog::all();

            $cohortEvents = ['cohort_assigned', 'cohort_retention', 'cohort_churn', 'cohort_conversion', 'cohort_migration', 'cohort_engagement'];

            foreach ($cohortEvents as $eventName) {
                expect(\ZeroBoiler\Analytics\Events\EventCatalog::has($eventName))->toBeTrue();
                expect($catalog[$eventName]['category'])->toBe('saas');
            }
        });
    });
});
