<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Infrastructure\DeploymentRolledBackEvent;
use ZeroBoiler\Analytics\Events\Infrastructure\ErrorBudgetBurnedEvent;
use ZeroBoiler\Analytics\Events\Infrastructure\ExperimentExposedEvent;
use ZeroBoiler\Analytics\Events\Infrastructure\FeatureFlagEvaluatedEvent;
use ZeroBoiler\Analytics\Events\Infrastructure\IncidentResolvedEvent;
use ZeroBoiler\Analytics\Events\Infrastructure\IncidentStartedEvent;
use ZeroBoiler\Analytics\Events\Infrastructure\InfrastructureEvents;
use ZeroBoiler\Analytics\Events\Infrastructure\MaintenanceEndedEvent;
use ZeroBoiler\Analytics\Events\Infrastructure\MaintenanceStartedEvent;
use ZeroBoiler\Analytics\Events\Infrastructure\PipelineFailureEvent;
use ZeroBoiler\Analytics\Events\Infrastructure\SLOBreachEvent;
use ZeroBoiler\Analytics\Services\EventSamplingStrategyService;

// ── V450 Infrastructure Events & Event Sampling Strategy Tests ──────────

describe('V450 Infrastructure Events & Sampling Strategy', function () {

    // ── 1. Infrastructure Event DTOs ───────────────────────────────

    describe('FeatureFlagEvaluatedEvent', function () {
        test('constructs with required params', function (): void {
            $event = new FeatureFlagEvaluatedEvent('dark_mode', true);

            expect($event->name)->toBe('feature_flag_evaluated')
                ->and($event->params['flag_name'])->toBe('dark_mode')
                ->and($event->params['enabled'])->toBeTrue()
                ->and($event->params['variant'])->toBeNull()
                ->and($event->params['reason'])->toBeNull();
        });

        test('constructs with all params', function (): void {
            $event = new FeatureFlagEvaluatedEvent('new_checkout', true, 'treatment', 'segment');

            expect($event->name)->toBe('feature_flag_evaluated')
                ->and($event->params['flag_name'])->toBe('new_checkout')
                ->and($event->params['enabled'])->toBeTrue()
                ->and($event->params['variant'])->toBe('treatment')
                ->and($event->params['reason'])->toBe('segment');
        });

        test('merges extra params', function (): void {
            $event = new FeatureFlagEvaluatedEvent('banner_v2', false, null, null, ['segment' => 'beta_users']);

            expect($event->params['segment'])->toBe('beta_users');
        });
    });

    describe('ExperimentExposedEvent', function () {
        test('constructs with required params', function (): void {
            $event = new ExperimentExposedEvent('exp_123', 'control');

            expect($event->name)->toBe('experiment_exposed')
                ->and($event->params['experiment_id'])->toBe('exp_123')
                ->and($event->params['variation'])->toBe('control');
        });

        test('constructs with source', function (): void {
            $event = new ExperimentExposedEvent('exp_456', 'treatment', 'optimizely');

            expect($event->params['source'])->toBe('optimizely');
        });
    });

    describe('ErrorBudgetBurnedEvent', function () {
        test('constructs with required params', function (): void {
            $event = new ErrorBudgetBurnedEvent('api_availability', 2.5, 45.0);

            expect($event->name)->toBe('error_budget_burned')
                ->and($event->params['slo_name'])->toBe('api_availability')
                ->and($event->params['burn_rate'])->toBe(2.5)
                ->and($event->params['remaining'])->toBe(45.0);
        });

        test('constructs with all params', function (): void {
            $event = new ErrorBudgetBurnedEvent('p99_latency', 5.0, 10.0, '1h');

            expect($event->params['window'])->toBe('1h');
        });
    });

    describe('SLOBreachEvent', function () {
        test('constructs with required params', function (): void {
            $event = new SLOBreachEvent('api_availability_99.9', 99.2, 99.9);

            expect($event->name)->toBe('slo_breach')
                ->and($event->params['current_value'])->toBe(99.2)
                ->and($event->params['target'])->toBe(99.9);
        });

        test('constructs with all params', function (): void {
            $event = new SLOBreachEvent('api_availability', 98.5, 99.9, 'availability', 'critical');

            expect($event->params['sli_name'])->toBe('availability')
                ->and($event->params['severity'])->toBe('critical');
        });
    });

    describe('DeploymentRolledBackEvent', function () {
        test('constructs with required params', function (): void {
            $event = new DeploymentRolledBackEvent('v2.5.0', 'v2.4.1');

            expect($event->name)->toBe('deployment_rolled_back')
                ->and($event->params['version'])->toBe('v2.5.0')
                ->and($event->params['rollback_to'])->toBe('v2.4.1');
        });

        test('constructs with all params', function (): void {
            $event = new DeploymentRolledBackEvent('v3.0.0', 'v2.9.0', 'errors', 'production');

            expect($event->params['reason'])->toBe('errors')
                ->and($event->params['environment'])->toBe('production');
        });
    });

    describe('IncidentStartedEvent', function () {
        test('constructs with required params', function (): void {
            $event = new IncidentStartedEvent('INC-001', 'P1');

            expect($event->name)->toBe('incident_started')
                ->and($event->params['incident_id'])->toBe('INC-001')
                ->and($event->params['severity'])->toBe('P1');
        });

        test('constructs with all params', function (): void {
            $event = new IncidentStartedEvent('INC-002', 'P2', 'API returning 500s', 'payment-service');

            expect($event->params['title'])->toBe('API returning 500s')
                ->and($event->params['affected_service'])->toBe('payment-service');
        });
    });

    describe('IncidentResolvedEvent', function () {
        test('constructs with required params', function (): void {
            $event = new IncidentResolvedEvent('INC-001');

            expect($event->name)->toBe('incident_resolved')
                ->and($event->params['incident_id'])->toBe('INC-001');
        });

        test('constructs with all params', function (): void {
            $event = new IncidentResolvedEvent('INC-001', 45, 'fixed', 'code');

            expect($event->params['duration_minutes'])->toBe(45)
                ->and($event->params['resolution'])->toBe('fixed')
                ->and($event->params['root_cause'])->toBe('code');
        });
    });

    describe('MaintenanceStartedEvent', function () {
        test('constructs with required params', function (): void {
            $event = new MaintenanceStartedEvent('MAINT-001');

            expect($event->name)->toBe('maintenance_started')
                ->and($event->params['maintenance_id'])->toBe('MAINT-001');
        });

        test('constructs with all params', function (): void {
            $event = new MaintenanceStartedEvent('MAINT-002', 'api-gateway', 'database_upgrade');

            expect($event->params['affected_service'])->toBe('api-gateway')
                ->and($event->params['reason'])->toBe('database_upgrade');
        });
    });

    describe('MaintenanceEndedEvent', function () {
        test('constructs with required params', function (): void {
            $event = new MaintenanceEndedEvent('MAINT-001');

            expect($event->name)->toBe('maintenance_ended')
                ->and($event->params['maintenance_id'])->toBe('MAINT-001');
        });

        test('constructs with all params', function (): void {
            $event = new MaintenanceEndedEvent('MAINT-001', 'completed', 30);

            expect($event->params['status'])->toBe('completed')
                ->and($event->params['duration_minutes'])->toBe(30);
        });
    });

    describe('PipelineFailureEvent', function () {
        test('constructs with required params', function (): void {
            $event = new PipelineFailureEvent('dispatch');

            expect($event->name)->toBe('pipeline_failure')
                ->and($event->params['stage'])->toBe('dispatch');
        });

        test('constructs with all params', function (): void {
            $event = new PipelineFailureEvent('ingestion', 'ga4', 'rate_limit', '429 Too Many Requests');

            expect($event->params['provider'])->toBe('ga4')
                ->and($event->params['error_type'])->toBe('rate_limit')
                ->and($event->params['error_message'])->toBe('429 Too Many Requests');
        });

        test('truncates long error messages', function (): void {
            $longMessage = str_repeat('x', 600);
            $event = new PipelineFailureEvent('dispatch', null, 'validation', $longMessage);

            expect(strlen($event->params['error_message']))->toBe(500);
        });
    });

    // ── 2. InfrastructureEvents Catalog ────────────────────────────────

    describe('InfrastructureEvents catalog', function () {
        test('has correct number of events', function (): void {
            expect(InfrastructureEvents::count())->toBe(10);
        });

        test('contains all expected event names', function (): void {
            $names = InfrastructureEvents::names();

            expect($names)->toContain('feature_flag_evaluated')
                ->toContain('experiment_exposed')
                ->toContain('error_budget_burned')
                ->toContain('slo_breach')
                ->toContain('deployment_rolled_back')
                ->toContain('incident_started')
                ->toContain('incident_resolved')
                ->toContain('maintenance_started')
                ->toContain('maintenance_ended')
                ->toContain('pipeline_failure');
        });

        test('has returns true for known events', function (): void {
            expect(InfrastructureEvents::has('feature_flag_evaluated'))->toBeTrue()
                ->and(InfrastructureEvents::has('incident_started'))->toBeTrue()
                ->and(InfrastructureEvents::has('pipeline_failure'))->toBeTrue();
        });

        test('has returns false for unknown events', function (): void {
            expect(InfrastructureEvents::has('nonexistent'))->toBeFalse()
                ->and(InfrastructureEvents::has('page_view'))->toBeFalse();
        });

        test('get returns entry for known event', function (): void {
            $entry = InfrastructureEvents::get('feature_flag_evaluated');

            expect($entry)->not->toBeNull()
                ->and($entry['name'])->toBe('feature_flag_evaluated')
                ->and($entry['class'])->toBe(FeatureFlagEvaluatedEvent::class)
                ->and($entry['ga4'])->toBe('feature_flag_evaluated');
        });

        test('get returns null for unknown event', function (): void {
            expect(InfrastructureEvents::get('nonexistent'))->toBeNull();
        });

        test('classFor returns correct class', function (): void {
            expect(InfrastructureEvents::classFor('incident_started'))->toBe(IncidentStartedEvent::class)
                ->and(InfrastructureEvents::classFor('slo_breach'))->toBe(SLOBreachEvent::class);
        });

        test('classFor returns null for unknown event', function (): void {
            expect(InfrastructureEvents::classFor('nonexistent'))->toBeNull();
        });

        test('ga4Names returns correct names', function (): void {
            $ga4Names = InfrastructureEvents::ga4Names();

            expect($ga4Names)->toHaveCount(10)
                ->and($ga4Names)->toContain('feature_flag_evaluated')
                ->and($ga4Names)->toContain('pipeline_failure');
        });

        test('posthogNames returns correct names', function (): void {
            $posthogNames = InfrastructureEvents::posthogNames();

            expect($posthogNames)->toContain('feature_flag_evaluated')
                ->and($posthogNames)->toContain('$experiment_exposed');
        });

        test('metaNames returns empty array (no Meta Pixel support)', function (): void {
            expect(InfrastructureEvents::metaNames())->toBe([]);
        });

        test('plausibleNames returns empty array (no Plausible support)', function (): void {
            expect(InfrastructureEvents::plausibleNames())->toBe([]);
        });

        test('all entries have required fields', function (): void {
            foreach (InfrastructureEvents::all() as $name => $entry) {
                expect($entry)->toHaveKey('name')
                    ->and($entry)->toHaveKey('class')
                    ->and($entry)->toHaveKey('ga4')
                    ->and($entry)->toHaveKey('posthog')
                    ->and($entry)->toHaveKey('mixpanel')
                    ->and($entry)->toHaveKey('amplitude')
                    ->and($entry['name'])->toBe($name);
            }
        });
    });

    // ── 3. EventCatalog Integration ───────────────────────────────

    describe('EventCatalog with Infrastructure category', function () {
        test('total count includes infrastructure events', function (): void {
            $baseCount = EventCatalog::count();

            // After adding InfrastructureEvents, catalog should include them
            expect($baseCount)->toBeGreaterThanOrEqual(10);
        });

        test('getCategory returns infrastructure for infrastructure events', function (): void {
            expect(EventCatalog::getCategory('feature_flag_evaluated'))->toBe('infrastructure')
                ->and(EventCatalog::getCategory('incident_started'))->toBe('infrastructure')
                ->and(EventCatalog::getCategory('pipeline_failure'))->toBe('infrastructure');
        });

        test('byCategory includes infrastructure', function (): void {
            $byCategory = EventCatalog::byCategory();

            expect($byCategory)->toHaveKey('infrastructure');
        });
    });

    // ── 4. EventSamplingStrategyService ───────────────────────────

    describe('EventSamplingStrategyService', function () {
        beforeEach(function (): void {
            Cache::clear();
        });

        test('disabled sampling passes all events', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => [
                            'enabled' => false,
                            'global_rate' => 0.5,
                        ],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);
            $event = new AnalyticsEvent(name: 'page_view');

            // When disabled, shouldSample always returns true regardless of rate
            expect($service->shouldSample($event))->toBeTrue();
        });

        test('enabled with rate 1.0 passes all events', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => [
                            'enabled' => true,
                            'global_rate' => 1.0,
                        ],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);
            $event = new AnalyticsEvent(name: 'page_view');

            expect($service->shouldSample($event))->toBeTrue();
        });

        test('enabled with rate 0.0 drops all events', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => [
                            'enabled' => true,
                            'global_rate' => 0.0,
                        ],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);
            $event = new AnalyticsEvent(name: 'page_view');

            expect($service->shouldSample($event))->toBeFalse();
        });

        test('critical priority events always pass through', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => [
                            'enabled' => true,
                            'global_rate' => 0.0,
                        ],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);
            $criticalEvent = new AnalyticsEvent(name: 'incident_started', priority: 'critical');

            expect($service->shouldSample($criticalEvent))->toBeTrue();
        });

        test('event-specific override takes precedence over global rate', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => [
                            'enabled' => true,
                            'global_rate' => 1.0, // Would pass everything
                            'event_overrides' => [
                                'scroll_depth' => 0.0, // But this event is always dropped
                            ],
                        ],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);
            $normalEvent = new AnalyticsEvent(name: 'page_view');
            $overriddenEvent = new AnalyticsEvent(name: 'scroll_depth');

            expect($service->shouldSample($normalEvent))->toBeTrue();
            expect($service->shouldSample($overriddenEvent))->toBeFalse();
        });

        test('category override takes precedence over global rate', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => [
                            'enabled' => true,
                            'global_rate' => 1.0,
                            'category_overrides' => [
                                'engagement' => 0.0,
                            ],
                        ],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);
            $saasEvent = new AnalyticsEvent(name: 'sign_up');
            $engagementEvent = new AnalyticsEvent(name: 'click');

            // sign_up is in 'saas' category — no override
            expect($service->shouldSample($saasEvent))->toBeTrue();
            // click is in 'engagement' category — overridden to 0.0
            expect($service->shouldSample($engagementEvent))->toBeFalse();
        });

        test('resolveRate returns event override when set', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => [
                            'enabled' => true,
                            'global_rate' => 0.5,
                            'event_overrides' => [
                                'purchase' => 1.0,
                            ],
                        ],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);
            $purchaseEvent = new AnalyticsEvent(name: 'purchase');
            $otherEvent = new AnalyticsEvent(name: 'page_view');

            expect($service->resolveRate($purchaseEvent))->toBe(1.0);
            expect($service->resolveRate($otherEvent))->toBe(0.5);
        });

        test('resolveRate returns global rate when no overrides', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => [
                            'enabled' => true,
                            'global_rate' => 0.75,
                        ],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);
            $event = new AnalyticsEvent(name: 'page_view');

            expect($service->resolveRate($event))->toBe(0.75);
        });

        test('resolveRate clamps to 0.0-1.0 range', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => [
                            'enabled' => true,
                            'global_rate' => 5.0,
                        ],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);
            $event = new AnalyticsEvent(name: 'test');

            expect($service->resolveRate($event))->toBe(1.0);
        });

        test('deterministic strategy produces consistent results', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => [
                            'enabled' => true,
                            'global_rate' => 0.5,
                            'strategy' => 'deterministic',
                        ],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);
            $event = new AnalyticsEvent(name: 'page_view');

            // Deterministic: same event name always gets same result
            $results = [];
            for ($i = 0; $i < 100; $i++) {
                $results[] = $service->shouldSample($event);
            }

            // All results should be identical
            $uniqueResults = array_unique($results);
            expect(count($uniqueResults))->toBe(1);
        });

        test('uniform strategy produces varied results', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => [
                            'enabled' => true,
                            'global_rate' => 0.5,
                            'strategy' => 'uniform',
                        ],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);
            $event = new AnalyticsEvent(name: 'page_view');

            $results = [];
            for ($i = 0; $i < 100; $i++) {
                $results[] = $service->shouldSample($event);
            }

            $uniqueResults = array_unique($results);
            // With 100 trials at 50% rate, should get both true and false
            expect(count($uniqueResults))->toBe(2);
        });

        test('getStrategy returns configured strategy', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => [
                            'enabled' => true,
                            'strategy' => 'adaptive',
                        ],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);

            expect($service->getStrategy())->toBe('adaptive');
        });

        test('isEnabled returns configured state', function (): void {
            $configDisabled = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => ['enabled' => false],
                    ],
                ],
            ]);

            $configEnabled = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => ['enabled' => true],
                    ],
                ],
            ]);

            expect(new EventSamplingStrategyService(Cache::driver('array'), $configDisabled)->isEnabled())->toBeFalse();
            expect(new EventSamplingStrategyService(Cache::driver('array'), $configEnabled)->isEnabled())->toBeTrue();
        });

        test('getGlobalRate returns configured rate', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => [
                            'enabled' => true,
                            'global_rate' => 0.25,
                        ],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);

            expect($service->getGlobalRate())->toBe(0.25);
        });

        test('setGlobalRate updates runtime rate', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => [
                            'enabled' => true,
                            'global_rate' => 1.0,
                        ],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);
            $service->setGlobalRate(0.1);

            expect($service->getGlobalRate())->toBe(0.1);
        });

        test('setGlobalRate clamps to valid range', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => [
                            'enabled' => true,
                            'global_rate' => 0.5,
                        ],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);
            $service->setGlobalRate(5.0);

            expect($service->getGlobalRate())->toBe(1.0);
        });

        test('setEventRate adds override', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => ['enabled' => true],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);
            $service->setEventRate('page_view', 0.3);

            expect($service->getEventOverrides())->toHaveKey('page_view')
                ->and($service->getEventOverrides()['page_view'])->toBe(0.3);
        });

        test('removeEventRate removes override', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => [
                            'enabled' => true,
                            'event_overrides' => ['page_view' => 0.5],
                        ],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);
            expect($service->getEventOverrides())->toHaveKey('page_view');

            $service->removeEventRate('page_view');
            expect($service->getEventOverrides())->not->toHaveKey('page_view');
        });

        test('setCategoryRate adds override', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => ['enabled' => true],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);
            $service->setCategoryRate('engagement', 0.2);

            expect($service->getCategoryOverrides())->toHaveKey('engagement')
                ->and($service->getCategoryOverrides()['engagement'])->toBe(0.2);
        });

        test('getMetrics returns correct structure', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => ['enabled' => true],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);
            $metrics = $service->getMetrics();

            expect($metrics)->toHaveKeys(['passed', 'dropped', 'total', 'critical_passed', 'rate', 'strategy']);
        });

        test('summary returns correct structure', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => [
                            'enabled' => true,
                            'strategy' => 'deterministic',
                            'global_rate' => 0.5,
                            'event_overrides' => ['page_view' => 0.1],
                            'category_overrides' => ['engagement' => 0.2],
                        ],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);
            $summary = $service->summary();

            expect($summary)->toHaveKeys(['enabled', 'strategy', 'global_rate', 'event_overrides_count', 'category_overrides_count', 'effective_rates'])
                ->and($summary['enabled'])->toBeTrue()
                ->and($summary['strategy'])->toBe('deterministic')
                ->and($summary['global_rate'])->toBe(0.5)
                ->and($summary['event_overrides_count'])->toBe(1)
                ->and($summary['category_overrides_count'])->toBe(1);
        });

        test('adaptive counters can be reset', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => [
                            'enabled' => true,
                            'strategy' => 'adaptive',
                            'global_rate' => 1.0,
                        ],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);

            // Run some events through to populate counters
            $event = new AnalyticsEvent(name: 'page_view');
            $service->shouldSample($event);
            $service->shouldSample($event);

            expect($service->getAdaptiveCounters())->not->toBeEmpty();

            $service->resetAdaptiveCounters();
            expect($service->getAdaptiveCounters())->toBeEmpty();
        });

        test('unknown strategy passes all events through', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => [
                            'enabled' => true,
                            'global_rate' => 0.0,
                            'strategy' => 'unknown_strategy',
                        ],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);
            $event = new AnalyticsEvent(name: 'page_view');

            // Unknown strategy should pass through (fail-open)
            expect($service->shouldSample($event))->toBeTrue();
        });

        test('metrics counters increment correctly', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => [
                            'enabled' => true,
                            'global_rate' => 0.0, // All dropped
                        ],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);
            $event = new AnalyticsEvent(name: 'page_view');

            $service->shouldSample($event);
            $service->shouldSample($event);
            $service->shouldSample($event);

            $metrics = $service->getMetrics();
            expect($metrics['dropped'])->toBe(3);
            expect($metrics['passed'])->toBe(0);
            expect($metrics['total'])->toBe(3);
        });

        test('resetMetrics clears all counters', function (): void {
            $config = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => [
                            'enabled' => true,
                            'global_rate' => 0.5,
                        ],
                    ],
                ],
            ]);

            $service = new EventSamplingStrategyService(Cache::driver('array'), $config);
            $event = new AnalyticsEvent(name: 'page_view');

            $service->shouldSample($event);
            $service->resetMetrics();

            $metrics = $service->getMetrics();
            expect($metrics['passed'])->toBe(0)
                ->and($metrics['dropped'])->toBe(0)
                ->and($metrics['total'])->toBe(0);
        });
    });

    // ── 5. Version Sweep ──────────────────────────────────────────

    describe('Version sweep 44 → 45', function () {
        test('AnalyticsEvent VERSION is 45.0.0', function (): void {
            expect(AnalyticsEvent::VERSION)->toBe('45.0.0');
        });
    });
});
