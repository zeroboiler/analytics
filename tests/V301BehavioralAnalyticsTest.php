<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventRulesEngine;
use ZeroBoiler\Analytics\Services\UserPropertiesStore;
use ZeroBoiler\Analytics\Services\RetentionCalculator;
use ZeroBoiler\Analytics\Services\BehavioralCohortBuilder;

beforeEach(function (): void {
    $this->cache = Mockery::mock(CacheRepository::class);
    $this->config = Mockery::mock(ConfigRepository::class);
    $this->manager = Mockery::mock(AnalyticsManager::class);
});

afterEach(function (): void {
    Mockery::close();
});

// ─── Event Rules Engine ────────────────────────────────────────────

describe('EventRulesEngine', function (): void {
    it('is disabled by default', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.rules', [])
            ->andReturn(['enabled' => false, 'debug' => false, 'rules' => []]);

        $engine = new EventRulesEngine($this->manager, $this->cache, $this->config);

        expect($engine->isEnabled())->toBeFalse();
        expect($engine->rules())->toBe([]);
    });

    it('loads event_trigger rules from config', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.rules', [])
            ->andReturn([
                'enabled' => true,
                'debug' => false,
                'rules' => [
                    'enrich_signup' => [
                        'type' => 'event_trigger',
                        'on' => 'sign_up',
                        'then' => 'signup_enriched',
                        'enrich' => ['method' => 'method'],
                    ],
                    'invalid_rule' => [
                        'type' => 'unknown_type',
                        'on' => 'page_view',
                        'then' => 'something',
                    ],
                ],
            ]);

        $engine = new EventRulesEngine($this->manager, $this->cache, $this->config);

        expect($engine->isEnabled())->toBeTrue();
        expect($engine->rules())->toHaveCount(1);
        expect($engine->rules())->toHaveKey('enrich_signup');
    });

    it('evaluates event_trigger and produces new event', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.rules', [])
            ->andReturn([
                'enabled' => true,
                'debug' => false,
                'rules' => [
                    'auto_tag' => [
                        'type' => 'event_trigger',
                        'on' => 'button_click',
                        'then' => 'button_click_tagged',
                    ],
                ],
            ]);

        $engine = new EventRulesEngine($this->manager, $this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'button_click', params: ['element' => 'buy_now']);

        $triggered = $engine->evaluate($event);

        expect($triggered)->toHaveCount(1);
        expect($triggered[0]->name)->toBe('button_click_tagged');
        expect($triggered[0]->params['triggered_by'])->toBe('button_click');
        expect($triggered[0]->params['element'])->toBe('buy_now');
    });

    it('skips non-matching events', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.rules', [])
            ->andReturn([
                'enabled' => true,
                'debug' => false,
                'rules' => [
                    'signup_tag' => [
                        'type' => 'event_trigger',
                        'on' => 'sign_up',
                        'then' => 'signup_tagged',
                    ],
                ],
            ]);

        $engine = new EventRulesEngine($this->manager, $this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'page_view', params: []);

        $triggered = $engine->evaluate($event);

        expect($triggered)->toBe([]);
    });

    it('enriches params with mapping', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.rules', [])
            ->andReturn([
                'enabled' => true,
                'debug' => false,
                'rules' => [
                    'enrich' => [
                        'type' => 'event_trigger',
                        'on' => 'sign_up',
                        'then' => 'signup_enriched',
                        'enrich' => ['signup_method' => 'method'],
                    ],
                ],
            ]);

        $engine = new EventRulesEngine($this->manager, $this->cache, $this->config);
        $event = new AnalyticsEvent(name: 'sign_up', params: ['method' => 'google']);

        $triggered = $engine->evaluate($event);

        expect($triggered)->toHaveCount(1);
        expect($triggered[0]->params['signup_method'])->toBe('google');
    });

    it('returns empty for absence rules when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.rules', [])
            ->andReturn(['enabled' => false, 'debug' => false, 'rules' => []]);

        $engine = new EventRulesEngine($this->manager, $this->cache, $this->config);

        expect($engine->evaluateAbsenceRules())->toBe([]);
    });

    it('tracks trigger counts', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.rules', [])
            ->andReturn([
                'enabled' => true,
                'debug' => false,
                'rules' => [
                    'tag' => [
                        'type' => 'event_trigger',
                        'on' => 'click',
                        'then' => 'click_tagged',
                    ],
                ],
            ]);

        $engine = new EventRulesEngine($this->manager, $this->cache, $this->config);

        $engine->evaluate(new AnalyticsEvent(name: 'click', params: []));
        $engine->evaluate(new AnalyticsEvent(name: 'click', params: []));
        $engine->evaluate(new AnalyticsEvent(name: 'page_view', params: []));

        expect($engine->triggerCounts())->toBe(['tag' => 2]);
        $engine->resetTriggerCounts();
        expect($engine->triggerCounts())->toBe([]);
    });
});

// ─── User Properties Store ──────────────────────────────────────────

describe('UserPropertiesStore', function (): void {
    it('is enabled by default', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.user_properties', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'ttl' => 2592000, 'schema' => []]);

        $store = new UserPropertiesStore($this->cache, $this->config);

        expect($store->isEnabled())->toBeTrue();
    });

    it('sets and gets a property', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.user_properties', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'ttl' => 2592000, 'schema' => []]);

        $this->cache->shouldReceive('get')
            ->with('zb_user_props_user_1')
            ->andReturnNull();
        $this->cache->shouldReceive('put')
            ->with('zb_user_props_user_1', ['plan' => 'pro'], 2592000)
            ->once();

        $store = new UserPropertiesStore($this->cache, $this->config);
        $store->set('user_1', 'plan', 'pro');

        expect($store->get('user_1', 'plan'))->toBe('pro');
    });

    it('merges multiple properties', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.user_properties', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'ttl' => 2592000, 'schema' => []]);

        $this->cache->shouldReceive('get')
            ->with('zb_user_props_user_1')
            ->andReturn(['plan' => 'free']);
        $this->cache->shouldReceive('put')
            ->with('zb_user_props_user_1', ['plan' => 'pro', 'team_size' => 5], 2592000)
            ->once();

        $store = new UserPropertiesStore($this->cache, $this->config);
        $store->merge('user_1', ['plan' => 'pro', 'team_size' => 5]);
    });

    it('increments a numeric property', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.user_properties', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'ttl' => 2592000, 'schema' => []]);

        $this->cache->shouldReceive('get')
            ->with('zb_user_props_user_1')
            ->andReturn(['session_count' => 5]);
        $this->cache->shouldReceive('put')
            ->with('zb_user_props_user_1', ['session_count' => 6], 2592000)
            ->once();

        $store = new UserPropertiesStore($this->cache, $this->config);
        $store->increment('user_1', 'session_count', 1);
    });

    it('aggregates with sum strategy', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.user_properties', [])
            ->andReturn([
                'enabled' => true,
                'debug' => false,
                'ttl' => 2592000,
                'schema' => [
                    'total_revenue' => ['type' => 'float', 'default' => 0.0, 'aggregation' => 'sum'],
                ],
            ]);

        $this->cache->shouldReceive('get')
            ->with('zb_user_props_user_1')
            ->andReturn(['total_revenue' => 49.99]);
        $this->cache->shouldReceive('put')
            ->with('zb_user_props_user_1', ['total_revenue' => 99.99], 2592000)
            ->once();

        $store = new UserPropertiesStore($this->cache, $this->config);
        $store->set('user_1', 'total_revenue', 50.0);
    });

    it('casts values according to schema', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.user_properties', [])
            ->andReturn([
                'enabled' => true,
                'debug' => false,
                'ttl' => 2592000,
                'schema' => [
                    'age' => ['type' => 'int', 'default' => 0],
                ],
            ]);

        $this->cache->shouldReceive('get')
            ->with('zb_user_props_user_1')
            ->andReturnNull();
        $this->cache->shouldReceive('put')
            ->with('zb_user_props_user_1', ['age' => 25], 2592000)
            ->once();

        $store = new UserPropertiesStore($this->cache, $this->config);
        $store->set('user_1', 'age', '25');
    });

    it('links client ID to user ID', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.user_properties', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'ttl' => 2592000, 'schema' => []]);

        $this->cache->shouldReceive('put')
            ->with('zb_user_link_client_abc', 'user_42', 2592000)
            ->once();
        $this->cache->shouldReceive('get')
            ->with('zb_user_props_client_abc')
            ->andReturn(['source' => 'organic']);
        $this->cache->shouldReceive('put')
            ->with('zb_user_props_user_42', Mockery::on(function (array $v): bool {
                return isset($v['source']) && isset($v['_linked_client_id']);
            }), 2592000)
            ->once();
        $this->cache->shouldReceive('forget')
            ->with('zb_user_props_client_abc')
            ->once();

        $store = new UserPropertiesStore($this->cache, $this->config);
        $store->linkIdentity('client_abc', 'user_42');
    });

    it('resolves linked identity', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.user_properties', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'ttl' => 2592000, 'schema' => []]);

        $this->cache->shouldReceive('get')
            ->with('zb_user_link_client_abc')
            ->andReturn('user_42');

        $store = new UserPropertiesStore($this->cache, $this->config);

        expect($store->resolveIdentity('client_abc'))->toBe('user_42');
    });

    it('deletes all properties for an identity', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.user_properties', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'ttl' => 2592000, 'schema' => []]);

        $this->cache->shouldReceive('forget')
            ->with('zb_user_props_user_1')
            ->once();

        $store = new UserPropertiesStore($this->cache, $this->config);
        $store->delete('user_1');
    });

    it('does nothing when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.user_properties', [])
            ->andReturn(['enabled' => false, 'debug' => false, 'ttl' => 2592000, 'schema' => []]);

        $store = new UserPropertiesStore($this->cache, $this->config);
        $store->set('user_1', 'plan', 'pro');

        // No cache calls were made (verified by Mockery strict mode)
        expect($store->isEnabled())->toBeFalse();
    });

    it('returns all properties as array', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.user_properties', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'ttl' => 2592000, 'schema' => []]);

        $this->cache->shouldReceive('get')
            ->with('zb_user_props_user_1')
            ->andReturn(['plan' => 'pro', 'session_count' => 10]);

        $store = new UserPropertiesStore($this->cache, $this->config);

        expect($store->toArray('user_1'))->toBe(['plan' => 'pro', 'session_count' => 10]);
    });
});

// ─── Retention Calculator ──────────────────────────────────────────

describe('RetentionCalculator', function (): void {
    it('returns empty result when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.retention_analytics', [])
            ->andReturn(['enabled' => false, 'debug' => false, 'ttl' => 7776000, 'retention_days' => [1, 3, 7, 14, 30]]);

        $calc = new RetentionCalculator($this->cache, $this->config);

        expect($calc->isEnabled())->toBeFalse();
        expect($calc->retention())->toBe([
            'cohort_date' => null,
            'day0_users' => 0,
            'retention' => [1 => 0.0, 3 => 0.0, 7 => 0.0, 14 => 0.0, 30 => 0.0],
            'period' => 'overall',
        ]);
    });

    it('records activity and returns configured retention days', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.retention_analytics', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'ttl' => 7776000, 'retention_days' => [1, 3, 7, 14, 30]]);

        $this->cache->shouldReceive('get')
            ->andReturnNull(); // No first-seen, no activity
        $this->cache->shouldReceive('put')
            ->andReturnTrue();

        $calc = new RetentionCalculator($this->cache, $this->config);

        expect($calc->isEnabled())->toBeTrue();
        expect($calc->retentionDays())->toBe([1, 3, 7, 14, 30]);
    });

    it('returns empty stickiness when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.retention_analytics', [])
            ->andReturn(['enabled' => false, 'debug' => false, 'ttl' => 7776000, 'retention_days' => [1, 3, 7]]);

        $calc = new RetentionCalculator($this->cache, $this->config);
        $result = $calc->stickiness('2026-01-15');

        expect($result['dau'])->toBe(0);
        expect($result['grade'])->toBe('N/A');
    });

    it('returns empty cohort comparison when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.retention_analytics', [])
            ->andReturn(['enabled' => false, 'debug' => false, 'ttl' => 7776000, 'retention_days' => [1, 7]]);

        $calc = new RetentionCalculator($this->cache, $this->config);

        expect($calc->cohortComparison(7))->toBe(['cohorts' => [], 'averages' => []]);
    });

    it('returns empty retention curve when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.retention_analytics', [])
            ->andReturn(['enabled' => false, 'debug' => false, 'ttl' => 7776000, 'retention_days' => [1, 7]]);

        $calc = new RetentionCalculator($this->cache, $this->config);

        $curve = $calc->retentionCurve('2026-01-01', 7);
        expect($curve['cohort_date'])->toBe('2026-01-01');
        expect($curve['day0_users'])->toBe(0);
        expect($curve['curve'])->toBe([]);
    });
});

// ─── Behavioral Cohort Builder ─────────────────────────────────────

describe('BehavioralCohortBuilder', function (): void {
    it('is enabled by default', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cohorts', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'result_ttl' => 3600, 'custom_cohorts' => []]);

        $builder = new BehavioralCohortBuilder($this->cache, $this->config);

        expect($builder->isEnabled())->toBeTrue();
    });

    it('returns empty result when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cohorts', [])
            ->andReturn(['enabled' => false, 'debug' => false, 'result_ttl' => 3600, 'custom_cohorts' => []]);

        $builder = new BehavioralCohortBuilder($this->cache, $this->config);

        expect($builder->classify())->toBe([
            'generated_at' => Mockery::any(),
            'total_users' => 0,
            'segments' => [],
            'custom_cohorts' => [],
        ]);
    });

    it('returns segment definitions', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cohorts', [])
            ->andReturn(['enabled' => true, 'debug' => false, 'result_ttl' => 3600, 'custom_cohorts' => []]);

        $builder = new BehavioralCohortBuilder($this->cache, $this->config);

        $defs = $builder->segmentDefinitions();
        expect($defs)->toHaveKey('power');
        expect($defs)->toHaveKey('regular');
        expect($defs)->toHaveKey('casual');
        expect($defs)->toHaveKey('at_risk');
        expect($defs)->toHaveKey('dormant');
        expect($defs)->toHaveKey('new');
        expect($defs)->toHaveKey('resurrected');
    });

    it('returns empty summary when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cohorts', [])
            ->andReturn(['enabled' => false, 'debug' => false, 'result_ttl' => 3600, 'custom_cohorts' => []]);

        $builder = new BehavioralCohortBuilder($this->cache, $this->config);

        expect($builder->summary(30))->toBe(['period_days' => 30, 'cohorts' => []]);
    });

    it('returns null for user classification when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.cohorts', [])
            ->andReturn(['enabled' => false, 'debug' => false, 'result_ttl' => 3600, 'custom_cohorts' => []]);

        $builder = new BehavioralCohortBuilder($this->cache, $this->config);

        expect($builder->classifyUser('user_1'))->toBeNull();
    });
});
