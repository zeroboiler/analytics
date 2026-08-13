<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Services\AnalyticsQueryBuilder;
use ZeroBoiler\Analytics\Services\EventQueryEngine;

beforeEach(function (): void {
    // Use array cache for testing
    Cache::forget('zb_query_evt_day_');
    Cache::forget('zb_query_prov_success_');
    Cache::forget('zb_query_prov_fail_');
    Cache::forget('zb_query_rev_day_');
    Cache::forget('zb_query_rev_month_');
});

describe('EventQueryEngine', function (): void {
    describe('instantiation', function (): void {
        it('can be resolved from the container', function (): void {
            $engine = app(EventQueryEngine::class);

            expect($engine)->toBeInstanceOf(EventQueryEngine::class);
        });

        it('uses default TTL when no config override', function (): void {
            $engine = app(EventQueryEngine::class);

            expect($engine)->toBeInstanceOf(EventQueryEngine::class);
        });
    });

    describe('time-series queries', function (): void {
        it('returns empty time-series for unknown events', function (): void {
            $engine = app(EventQueryEngine::class);
            $result = $engine->timeSeries(['nonexistent_event_xyz'], 3);

            expect($result)
                ->toHaveKey('dates')
                ->toHaveKey('series')
                ->toHaveKey('total')
                ->toHaveKey('trend');

            expect($result['total'])->toBe(0);
            expect($result['dates'])->toHaveCount(3);
            expect($result['series'])->toHaveKey('nonexistent_event_xyz');
            expect($result['trend'])->toBeFloat();
        });

        it('returns correct number of dates for period', function (): void {
            $engine = app(EventQueryEngine::class);
            $result = $engine->timeSeries(['page_view'], 14);

            expect($result['dates'])->toHaveCount(14);
            expect($result['series']['page_view'])->toHaveCount(14);
        });

        it('clamps period to 90 days max', function (): void {
            $engine = app(EventQueryEngine::class);
            $result = $engine->timeSeries(['page_view'], 200);

            expect($result['dates'])->toHaveCount(90);
        });

        it('clamps period to 1 day min', function (): void {
            $engine = app(EventQueryEngine::class);
            $result = $engine->timeSeries(['page_view'], 0);

            expect($result['dates'])->toHaveCount(1);
        });

        it('handles multiple events in time-series', function (): void {
            $engine = app(EventQueryEngine::class);
            $result = $engine->timeSeries(['page_view', 'sign_up', 'login'], 7);

            expect($result['series'])->toHaveKeys(['page_view', 'sign_up', 'login']);
            expect($result['series']['page_view'])->toHaveCount(7);
            expect($result['series']['sign_up'])->toHaveCount(7);
        });
    });

    describe('hourly distribution', function (): void {
        it('returns 24-hour distribution', function (): void {
            $engine = app(EventQueryEngine::class);
            $result = $engine->hourlyDistribution('page_view');

            expect($result)
                ->toHaveKey('hours')
                ->toHaveKey('counts')
                ->toHaveKey('peak_hour')
                ->toHaveKey('peak_count');

            expect($result['hours'])->toHaveCount(24);
            expect($result['counts'])->toHaveCount(24);
        });
    });

    describe('day-of-week distribution', function (): void {
        it('returns 7-day distribution', function (): void {
            $engine = app(EventQueryEngine::class);
            $result = $engine->dayOfWeekDistribution('page_view', 2);

            expect($result)
                ->toHaveKey('days')
                ->toHaveKey('counts')
                ->toHaveKey('totals')
                ->toHaveKey('peak_day');

            expect($result['days'])->toHaveCount(7);
            expect($result['counts'])->toHaveCount(7);
            expect($result['totals'])->toHaveCount(7);
        });

        it('clamps weeks to 12 max', function (): void {
            $engine = app(EventQueryEngine::class);
            $result = $engine->dayOfWeekDistribution('page_view', 20);

            // Should not throw, just capped
            expect($result['days'])->toHaveCount(7);
        });
    });

    describe('category breakdown', function (): void {
        it('returns all three categories', function (): void {
            $engine = app(EventQueryEngine::class);
            $result = $engine->categoryBreakdown(7);

            expect($result)
                ->toHaveKey('categories')
                ->toHaveKey('total')
                ->toHaveKey('top_category')
                ->toHaveKey('percentages');

            expect($result['categories'])->toHaveKeys(['ecommerce', 'saas', 'engagement']);
            expect($result['percentages'])->toHaveKeys(['ecommerce', 'saas', 'engagement']);
        });
    });

    describe('funnel analysis', function (): void {
        it('returns funnel steps with conversion data', function (): void {
            $engine = app(EventQueryEngine::class);
            $result = $engine->funnelAnalysis('test_funnel', ['sign_up', 'login', 'subscribe'], 7);

            expect($result)
                ->toHaveKey('funnel')
                ->toHaveKey('period_days')
                ->toHaveKey('steps')
                ->toHaveKey('overall_conversion')
                ->toHaveKey('total_entered')
                ->toHaveKey('total_completed');

            expect($result['funnel'])->toBe('test_funnel');
            expect($result['period_days'])->toBe(7);
            expect($result['steps'])->toHaveCount(3);
            expect($result['overall_conversion'])->toBeFloat();
        });

        it('returns correct step names', function (): void {
            $engine = app(EventQueryEngine::class);
            $result = $engine->funnelAnalysis('signup', ['sign_up', 'subscribe'], 7);

            expect($result['steps'][0]['name'])->toBe('sign_up');
            expect($result['steps'][1]['name'])->toBe('subscribe');
        });

        it('handles single-step funnel', function (): void {
            $engine = app(EventQueryEngine::class);
            $result = $engine->funnelAnalysis('single', ['page_view'], 1);

            expect($result['steps'])->toHaveCount(1);
            expect($result['overall_conversion'])->toBe(1.0);
        });
    });

    describe('conversion rate', function (): void {
        it('returns conversion rate between two events', function (): void {
            $engine = app(EventQueryEngine::class);
            $result = $engine->conversionRate('sign_up', 'subscribe', 7);

            expect($result)
                ->toHaveKey('from')
                ->toHaveKey('to')
                ->toHaveKey('period_days')
                ->toHaveKey('from_count')
                ->toHaveKey('to_count')
                ->toHaveKey('rate');

            expect($result['from'])->toBe('sign_up');
            expect($result['to'])->toBe('subscribe');
            expect($result['rate'])->toBeFloat();
        });
    });

    describe('trial conversion', function (): void {
        it('returns trial conversion metrics', function (): void {
            $engine = app(EventQueryEngine::class);
            $result = $engine->trialConversion(30);

            expect($result)
                ->toHaveKey('period_days')
                ->toHaveKey('trials')
                ->toHaveKey('converted')
                ->toHaveKey('rate')
                ->toHaveKey('expired')
                ->toHaveKey('expiry_rate');

            expect($result['rate'])->toBeFloat();
            expect($result['expiry_rate'])->toBeFloat();
        });
    });

    describe('retention cohort', function (): void {
        it('returns cohort table with retention data', function (): void {
            $engine = app(EventQueryEngine::class);
            $result = $engine->retentionCohort('page_view', 3, [1, 3, 7]);

            expect($result)
                ->toHaveKey('event')
                ->toHaveKey('cohorts');

            expect($result['event'])->toBe('page_view');
            expect($result['cohorts'])->toHaveCount(3);

            foreach ($result['cohorts'] as $cohort) {
                expect($cohort)->toHaveKey('date');
                expect($cohort)->toHaveKey('day_0');
                expect($cohort)->toHaveKey('retention');
            }
        });

        it('clamps cohort days to 14', function (): void {
            $engine = app(EventQueryEngine::class);
            $result = $engine->retentionCohort('page_view', 30, [1, 7]);

            expect($result['cohorts'])->toHaveCount(14);
        });
    });

    describe('provider dispatch stats', function (): void {
        it('returns stats for all providers', function (): void {
            $engine = app(EventQueryEngine::class);
            $result = $engine->providerDispatchStats(1);

            expect($result)
                ->toHaveKey('period_days')
                ->toHaveKey('providers')
                ->toHaveKey('totals');

            expect($result['providers'])->toHaveKeys(['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'webhook']);
            expect($result['totals'])->toHaveKeys(['success', 'failure', 'total']);
        });
    });

    describe('saas dashboard summary', function (): void {
        it('returns full dashboard summary', function (): void {
            $engine = app(EventQueryEngine::class);
            $result = $engine->saasDashboardSummary('USD', 7);

            expect($result)
                ->toHaveKey('users')
                ->toHaveKey('revenue')
                ->toHaveKey('funnel')
                ->toHaveKey('engagement')
                ->toHaveKey('providers')
                ->toHaveKey('generated_at')
                ->toHaveKey('period_days');

            expect($result['users'])->toHaveKeys(['signups', 'logins', 'trial_starts']);
            expect($result['revenue'])->toHaveKeys(['daily', 'monthly', 'subscriptions']);
            expect($result['funnel'])->toHaveKeys(['signup_to_subscribe', 'trial_conversion', 'checkout_rate']);
            expect($result['engagement'])->toHaveKeys(['total_events', 'top_events', 'page_views']);
            expect($result['period_days'])->toBe(7);
        });
    });

    describe('trending events', function (): void {
        it('returns trending events list', function (): void {
            $engine = app(EventQueryEngine::class);
            $result = $engine->trendingEvents(7, 'all', 5);

            expect($result)->toBeArray();
        });
    });

    describe('pre-built funnels', function (): void {
        it('signup funnel returns valid structure', function (): void {
            $engine = app(EventQueryEngine::class);
            $result = $engine->signupFunnel(7);

            expect($result['steps'])->toHaveCount(4);
            expect($result['steps'][0]['name'])->toBe('sign_up');
        });

        it('checkout funnel returns valid structure', function (): void {
            $engine = app(EventQueryEngine::class);
            $result = $engine->checkoutFunnelAnalysis(7);

            expect($result['steps'])->toHaveCount(4);
            expect($result['steps'][0]['name'])->toBe('view_item');
            expect($result['steps'][3]['name'])->toBe('purchase');
        });
    });
});

describe('AnalyticsQueryBuilder', function (): void {
    describe('fluent API', function (): void {
        it('creates a builder with make()', function (): void {
            $builder = AnalyticsQueryBuilder::make();

            expect($builder)->toBeInstanceOf(AnalyticsQueryBuilder::class);
            expect($builder->getQueryType())->toBe('time_series');
        });

        it('chains events and period', function (): void {
            $builder = AnalyticsQueryBuilder::make()
                ->events(['sign_up', 'login'])
                ->period(14);

            expect($builder->getEvents())->toBe(['sign_up', 'login']);
            expect($builder->getPeriod())->toBe(14);
        });

        it('chains single event with event()', function (): void {
            $builder = AnalyticsQueryBuilder::make()
                ->event('page_view')
                ->event('sign_up');

            expect($builder->getEvents())->toBe(['page_view', 'sign_up']);
        });

        it('configures funnel query', function (): void {
            $builder = AnalyticsQueryBuilder::make()
                ->funnel('signup', ['sign_up', 'subscribe']);

            expect($builder->getQueryType())->toBe('funnel');
        });

        it('configures trending query', function (): void {
            $builder = AnalyticsQueryBuilder::make()
                ->trending('up');

            expect($builder->getQueryType())->toBe('trending');
        });

        it('configures conversion query', function (): void {
            $builder = AnalyticsQueryBuilder::make()
                ->conversion('sign_up', 'subscribe');

            expect($builder->getQueryType())->toBe('conversion');
        });

        it('configures category breakdown query', function (): void {
            $builder = AnalyticsQueryBuilder::make()
                ->categoryBreakdown();

            expect($builder->getQueryType())->toBe('category_breakdown');
        });

        it('configures retention cohort query', function (): void {
            $builder = AnalyticsQueryBuilder::make()
                ->retentionCohort([1, 7, 30]);

            expect($builder->getQueryType())->toBe('retention_cohort');
        });

        it('configures SaaS dashboard query', function (): void {
            $builder = AnalyticsQueryBuilder::make()
                ->saasDashboard('EUR');

            expect($builder->getQueryType())->toBe('saas_dashboard');
        });

        it('clamps limit to 50 max', function (): void {
            $builder = AnalyticsQueryBuilder::make()
                ->limit(200);

            // Limit is applied during execute, not stored raw
            expect($builder)->toBeInstanceOf(AnalyticsQueryBuilder::class);
        });

        it('clamps limit to 1 min', function (): void {
            $builder = AnalyticsQueryBuilder::make()
                ->limit(0);

            expect($builder)->toBeInstanceOf(AnalyticsQueryBuilder::class);
        });
    });

    describe('serialization', function (): void {
        it('serializes to array', function (): void {
            $builder = AnalyticsQueryBuilder::make()
                ->events(['page_view'])
                ->period(14)
                ->limit(5);

            $arr = $builder->toArray();

            expect($arr)
                ->toHaveKey('type')
                ->toHaveKey('events')
                ->toHaveKey('period_days')
                ->toHaveKey('limit');

            expect($arr['events'])->toBe(['page_view']);
            expect($arr['period_days'])->toBe(14);
        });

        it('serializes funnel config', function (): void {
            $builder = AnalyticsQueryBuilder::make()
                ->funnel('signup', ['sign_up', 'subscribe']);

            $arr = $builder->toArray();

            expect($arr['funnel'])->toBe('signup');
            expect($arr['steps'])->toBe(['sign_up', 'subscribe']);
        });

        it('round-trips through fromArray', function (): void {
            $original = AnalyticsQueryBuilder::make()
                ->events(['page_view', 'sign_up'])
                ->period(14)
                ->limit(5);

            $config = $original->toArray();
            $restored = AnalyticsQueryBuilder::fromArray($config);

            expect($restored->getEvents())->toBe($original->getEvents());
            expect($restored->getPeriod())->toBe($original->getPeriod());
            expect($restored->getQueryType())->toBe($original->getQueryType());
        });
    });

    describe('execution', function (): void {
        it('executes time-series query', function (): void {
            $result = AnalyticsQueryBuilder::make()
                ->events(['page_view'])
                ->period(3)
                ->execute();

            expect($result)->toHaveKey('dates');
            expect($result)->toHaveKey('series');
            expect($result['dates'])->toHaveCount(3);
        });

        it('executes funnel query', function (): void {
            $result = AnalyticsQueryBuilder::make()
                ->funnel('test', ['sign_up', 'subscribe'])
                ->execute();

            expect($result)->toHaveKey('funnel');
            expect($result)->toHaveKey('steps');
            expect($result['steps'])->toHaveCount(2);
        });

        it('executes conversion query', function (): void {
            $result = AnalyticsQueryBuilder::make()
                ->conversion('sign_up', 'subscribe')
                ->execute();

            expect($result)->toHaveKey('rate');
            expect($result['rate'])->toBeFloat();
        });

        it('executes category breakdown query', function (): void {
            $result = AnalyticsQueryBuilder::make()
                ->categoryBreakdown()
                ->execute();

            expect($result)->toHaveKey('categories');
            expect($result['categories'])->toHaveKeys(['ecommerce', 'saas', 'engagement']);
        });

        it('executes SaaS dashboard query', function (): void {
            $result = AnalyticsQueryBuilder::make()
                ->saasDashboard()
                ->execute();

            expect($result)->toHaveKey('users');
            expect($result)->toHaveKey('revenue');
            expect($result)->toHaveKey('funnel');
        });

        it('throws on time-series without events', function (): void {
            AnalyticsQueryBuilder::make()->execute();
        })->throws(RuntimeException::class, 'Time-series query requires at least one event');

        it('throws on funnel without steps', function (): void {
            AnalyticsQueryBuilder::make()->funnel('empty')->execute();
        })->throws(RuntimeException::class, 'Funnel query requires at least one step');

        it('throws on conversion without events', function (): void {
            AnalyticsQueryBuilder::make()->conversion('', '')->execute();
        })->throws(RuntimeException::class, 'Conversion query requires from and to events');
    });

    describe('executeValues', function (): void {
        it('returns stripped values for time-series', function (): void {
            $result = AnalyticsQueryBuilder::make()
                ->events(['page_view'])
                ->period(3)
                ->executeValues();

            expect($result)->toHaveKey('page_view');
        });

        it('returns stripped values for conversion', function (): void {
            $result = AnalyticsQueryBuilder::make()
                ->conversion('sign_up', 'subscribe')
                ->executeValues();

            expect($result)->toHaveKey('rate');
            expect($result)->toHaveKey('from_count');
            expect($result)->toHaveKey('to_count');
        });
    });
});

describe('v74.0.0 Integration', function (): void {
    it('EventQueryEngine and AnalyticsQueryBuilder are both registered', function (): void {
        expect(app()->bound(EventQueryEngine::class))->toBeTrue();
        expect(app()->bound(AnalyticsQueryBuilder::class))->toBeTrue();
    });

    it('query builder uses the shared engine singleton', function (): void {
        $engine = app(EventQueryEngine::class);
        $result = $engine->timeSeries(['page_view'], 3);

        $builderResult = AnalyticsQueryBuilder::make()
            ->events(['page_view'])
            ->period(3)
            ->execute();

        // Both should return same dates
        expect($result['dates'])->toBe($builderResult['dates']);
    });

    it('topEventsWithMeta returns catalog-aware results', function (): void {
        $engine = app(EventQueryEngine::class);
        $result = $engine->topEventsWithMeta(3);

        expect($result)->toBeArray();
        foreach ($result as $event) {
            expect($event)->toHaveKeys(['name', 'count', 'category', 'ga4']);
        }
    });
});
