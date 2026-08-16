<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Services\EventImpactScoreService;
use ZeroBoiler\Analytics\Services\ProviderAnalyticsIntelligenceService;
use ZeroBoiler\Analytics\Services\EventPriorityCalculator;
use ZeroBoiler\Analytics\Support\EventBuilder;
use ZeroBoiler\Analytics\Events\EventCatalog;

beforeEach(function (): void {
    // Reset any static state in EventCatalog
    EventCatalog::names();
});

describe('EventImpactScoreService', function (): void {
    describe('score()', function (): void {
        test('returns valid structure for a known event', function (): void {
            $service = new EventImpactScoreService();
            $result = $service->score('purchase');

            expect($result)
                ->toHaveKeys(['score', 'grade', 'dimensions', 'category', 'priority'])
                ->and($result['score'])->toBeFloat()
                ->toBeGreaterThan(0.0)
                ->toBeLessThanOrEqual(1.0)
                ->and($result['grade'])->toBeString()
                ->and($result['dimensions'])->toHaveKeys(['revenue', 'funnel', 'frequency', 'provider'])
                ->and($result['category'])->toBe('revenue')
                ->and($result['priority'])->toBe('high');
        });

        test('purchase has high revenue dimension', function (): void {
            $service = new EventImpactScoreService();
            $result = $service->score('purchase');

            expect($result['dimensions']['revenue'])->toBe(1.0);
        });

        test('page_view has high frequency dimension', function (): void {
            $service = new EventImpactScoreService();
            $result = $service->score('page_view');

            expect($result['dimensions']['frequency'])->toBeGreaterThan(0.5);
        });

        test('operational events have low revenue dimension', function (): void {
            $service = new EventImpactScoreService();
            $result = $service->score('error');

            expect($result['dimensions']['revenue'])->toBeLessThan(0.2);
            expect($result['category'])->toBe('operational');
        });

        test('all dimensions are between 0 and 1', function (): void {
            $service = new EventImpactScoreService();

            foreach (['purchase', 'sign_up', 'page_view', 'error', 'share'] as $event) {
                $result = $service->score($event);
                foreach ($result['dimensions'] as $dim => $value) {
                    expect($value)->toBeGreaterThanOrEqual(0.0)
                        ->toBeLessThanOrEqual(1.0)
                        ->and($dim)->toBeIn(['revenue', 'funnel', 'frequency', 'provider']);
                }
            }
        });

        test('events with more provider coverage get higher provider dimension', function (): void {
            $service = new EventImpactScoreService();
            $purchaseResult = $service->score('purchase');
            $errorResult = $service->score('sla_breach');

            expect($purchaseResult['dimensions']['provider'])
                ->toBeGreaterThanOrEqual($errorResult['dimensions']['provider']);
        });
    });

    describe('scoreAll()', function (): void {
        test('returns all events with summary', function (): void {
            $service = new EventImpactScoreService();
            $result = $service->scoreAll();

            expect($result)->toHaveKeys(['events', 'summary'])
                ->and($result['summary'])->toHaveKeys(['total', 'avg_score', 'top_events', 'low_impact'])
                ->and($result['summary']['total'])->toBe(EventCatalog::count())
                ->and($result['summary']['avg_score'])->toBeFloat()
                ->and($result['summary']['top_events'])->toBeArray()
                ->and(count($result['summary']['top_events']))->toBeLessThanOrEqual(10);
        });

        test('respects limit parameter', function (): void {
            $service = new EventImpactScoreService();
            $unlimited = $service->scoreAll();
            $limited = $service->scoreAll(limit: 5);

            expect(count($limited['events']))->toBeLessThanOrEqual(5)
                ->and(count($limited['events']))->toBeLessThanOrEqual(count($unlimited['events']));
        });

        test('every event has valid score', function (): void {
            $service = new EventImpactScoreService();
            $result = $service->scoreAll();

            foreach ($result['events'] as $name => $data) {
                expect($data['score'])->toBeFloat()
                    ->toBeGreaterThanOrEqual(0.0)
                    ->toBeLessThanOrEqual(1.0)
                    ->and($data['grade'])->toBeIn(['A+', 'A', 'B+', 'B', 'C+', 'C', 'D', 'F']);
            }
        });
    });

    describe('topEvents()', function (): void {
        test('returns requested number of events', function (): void {
            $service = new EventImpactScoreService();
            $top5 = $service->topEvents(5);

            expect($top5)->toHaveCount(5);
        });

        test('top events include expected high-impact events', function (): void {
            $service = new EventImpactScoreService();
            $top10 = $service->topEvents(10);
            $names = array_column($top10, 'event');

            // Revenue-critical events should be in top tier
            expect($names)->toContain('purchase');
        });

        test('results are sorted by score descending', function (): void {
            $service = new EventImpactScoreService();
            $top = $service->topEvents(20);

            for ($i = 1; $i < count($top); $i++) {
                expect($top[$i - 1]['score'])->toBeGreaterThanOrEqual($top[$i]['score']);
            }
        });

        test('each entry has required fields', function (): void {
            $service = new EventImpactScoreService();
            $top = $service->topEvents(3);

            foreach ($top as $entry) {
                expect($entry)->toHaveKeys(['event', 'score', 'grade', 'category']);
            }
        });
    });

    describe('lowImpactEvents()', function (): void {
        test('returns events below threshold', function (): void {
            $service = new EventImpactScoreService();
            $low = $service->lowImpactEvents(0.3);

            foreach ($low as $entry) {
                expect($entry['score'])->toBeLessThan(0.3);
            }
        });

        test('each entry has reason field', function (): void {
            $service = new EventImpactScoreService();
            $low = $service->lowImpactEvents();

            foreach ($low as $entry) {
                expect($entry)->toHaveKey('reason')
                    ->and($entry['reason'])->toBeString()
                    ->and($entry['reason'])->not->toBeEmpty();
            }
        });
    });

    describe('compare()', function (): void {
        test('returns comparison structure', function (): void {
            $service = new EventImpactScoreService();
            $result = $service->compare('purchase', 'error');

            expect($result)->toHaveKeys(['event_a', 'event_b', 'delta', 'recommendation'])
                ->and($result['event_a'])->toHaveKeys(['score', 'grade'])
                ->and($result['event_b'])->toHaveKeys(['score', 'grade'])
                ->and($result['delta'])->toBeFloat()
                ->and($result['recommendation'])->toBeString();
        });

        test('purchase scores higher than error', function (): void {
            $service = new EventImpactScoreService();
            $result = $service->compare('purchase', 'error');

            expect($result['delta'])->toBeGreaterThan(0);
        });

        test('same event returns zero delta', function (): void {
            $service = new EventImpactScoreService();
            $result = $service->compare('login', 'login');

            expect($result['delta'])->toBe(0.0);
        });
    });

    describe('distribution()', function (): void {
        test('returns valid distribution', function (): void {
            $service = new EventImpactScoreService();
            $result = $service->distribution();

            expect($result)->toHaveKeys(['distribution', 'grade_breakdown', 'category_averages'])
                ->and($result['distribution'])->toHaveKeys(['critical', 'high', 'medium', 'low', 'minimal']);

            $total = array_sum($result['distribution']);
            expect($total)->toBe(EventCatalog::count());
        });

        test('category averages are present for known categories', function (): void {
            $service = new EventImpactScoreService();
            $result = $service->distribution();

            expect($result['category_averages'])->toHaveKeys(['revenue', 'acquisition', 'activation', 'retention', 'referral', 'operational']);
        });
    });

    describe('categoryAnalysis()', function (): void {
        test('returns analysis for a specific category', function (): void {
            $service = new EventImpactScoreService();
            $result = $service->categoryAnalysis('revenue');

            expect($result)->toHaveKeys(['category', 'avg_score', 'total_events', 'ranked_events'])
                ->and($result['category'])->toBe('revenue')
                ->and($result['avg_score'])->toBeFloat()
                ->and($result['total_events'])->toBeInt()->toBeGreaterThan(0);
        });

        test('ranked events are sorted by score descending', function (): void {
            $service = new EventImpactScoreService();
            $result = $service->categoryAnalysis('revenue');

            $ranked = $result['ranked_events'];
            for ($i = 1; $i < count($ranked); $i++) {
                expect($ranked[$i - 1]['score'])->toBeGreaterThanOrEqual($ranked[$i]['score']);
            }
        });
    });

    describe('clearCache()', function (): void {
        test('does not throw when no cache', function (): void {
            $service = new EventImpactScoreService();
            expect(fn () => $service->clearCache())->not->toThrow(\Throwable::class);
        });
    });
});

describe('ProviderAnalyticsIntelligenceService', function (): void {
    describe('report()', function (): void {
        test('returns provider intelligence report', function (): void {
            $service = new ProviderAnalyticsIntelligenceService();
            $result = $service->report();

            expect($result)->toHaveKeys(['providers', 'summary'])
                ->and($result['providers'])->toHaveKeys(['ga4', 'meta', 'posthog', 'plausible'])
                ->and($result['summary'])->toHaveKeys(['best_provider', 'weakest_provider', 'avg_coverage', 'total_events'])
                ->and($result['summary']['total_events'])->toBe(EventCatalog::count())
                ->and($result['summary']['avg_coverage'])->toBeFloat()
                ->toBeGreaterThanOrEqual(0.0)
                ->toBeLessThanOrEqual(1.0);
        });

        test('each provider has required fields', function (): void {
            $service = new ProviderAnalyticsIntelligenceService();
            $result = $service->report();

            foreach ($result['providers'] as $provider => $data) {
                expect($data)->toHaveKeys(['coverage', 'total_events', 'mapped_events', 'category_coverage', 'gaps', 'recommendations'])
                    ->and($data['coverage'])->toBeFloat()
                    ->toBeGreaterThanOrEqual(0.0)
                    ->toBeLessThanOrEqual(1.0)
                    ->and($data['category_coverage'])->toHaveKeys(['ecommerce', 'saas', 'engagement'])
                    ->and($data['gaps'])->toBeArray()
                    ->and($data['recommendations'])->toBeArray();
            }
        });

        test('GA4 has highest coverage (maps all events)', function (): void {
            $service = new ProviderAnalyticsIntelligenceService();
            $result = $service->report();

            expect($result['summary']['best_provider'])->toBe('ga4');
            expect($result['providers']['ga4']['coverage'])->toBe(1.0);
        });
    });

    describe('providerQuality()', function (): void {
        test('returns quality analysis for GA4', function (): void {
            $service = new ProviderAnalyticsIntelligenceService();
            $result = $service->providerQuality('ga4');

            expect($result)->toHaveKeys(['provider', 'coverage', 'meaningful_mappings', 'passthrough_mappings', 'quality_score', 'category_breakdown'])
                ->and($result['provider'])->toBe('ga4')
                ->and($result['coverage'])->toBe(1.0);
        });

        test('returns empty structure for invalid provider', function (): void {
            $service = new ProviderAnalyticsIntelligenceService();
            $result = $service->providerQuality('invalid_provider');

            expect($result['coverage'])->toBe(0.0)
                ->and($result['provider'])->toBe('invalid_provider');
        });

        test('category breakdown has coverage percentages', function (): void {
            $service = new ProviderAnalyticsIntelligenceService();
            $result = $service->providerQuality('ga4');

            foreach ($result['category_breakdown'] as $cat => $data) {
                expect($data)->toHaveKeys(['mapped', 'total', 'coverage'])
                    ->and($data['coverage'])->toBeFloat();
            }
        });
    });

    describe('coverageOpportunities()', function (): void {
        test('returns opportunities for plausible (most gaps)', function (): void {
            $service = new ProviderAnalyticsIntelligenceService();
            $result = $service->coverageOpportunities('plausible', 10);

            expect($result)->toBeArray();

            foreach ($result as $opportunity) {
                expect($opportunity)->toHaveKeys(['event', 'category', 'other_provider_count', 'suggested_name'])
                    ->and($opportunity['other_provider_count'])->toBeGreaterThan(0);
            }
        });

        test('respects limit parameter', function (): void {
            $service = new ProviderAnalyticsIntelligenceService();
            $result = $service->coverageOpportunities('plausible', 3);

            expect($result)->toHaveCount(3);
        });

        test('returns empty for invalid provider', function (): void {
            $service = new ProviderAnalyticsIntelligenceService();
            $result = $service->coverageOpportunities('nonexistent');

            expect($result)->toBeEmpty();
        });
    });

    describe('mappingMatrix()', function (): void {
        test('returns complete mapping matrix', function (): void {
            $service = new ProviderAnalyticsIntelligenceService();
            $result = $service->mappingMatrix();

            expect($result)->toHaveKeys(['matrix', 'providers', 'total', 'per_provider_counts'])
                ->and($result['providers'])->toBe(['ga4', 'meta', 'posthog', 'plausible'])
                ->and($result['total'])->toBe(EventCatalog::count())
                ->and(count($result['matrix']))->toBe(EventCatalog::count());
        });

        test('every event in matrix has provider flags', function (): void {
            $service = new ProviderAnalyticsIntelligenceService();
            $result = $service->mappingMatrix();

            foreach ($result['matrix'] as $name => $providers) {
                foreach ($result['providers'] as $provider) {
                    expect($providers)->toHaveKey($provider)
                        ->and($providers[$provider])->toBeBool();
                }
            }
        });

        test('GA4 maps all events', function (): void {
            $service = new ProviderAnalyticsIntelligenceService();
            $result = $service->mappingMatrix();

            foreach ($result['matrix'] as $name => $providers) {
                expect($providers['ga4'])->toBeTrue();
            }
        });
    });

    describe('recommendations()', function (): void {
        test('returns structured recommendations', function (): void {
            $service = new ProviderAnalyticsIntelligenceService();
            $result = $service->recommendations();

            expect($result)->toHaveKeys(['critical_gaps', 'quick_wins', 'category_priorities', 'overall_grade'])
                ->and($result['critical_gaps'])->toBeArray()
                ->and($result['quick_wins'])->toBeArray()
                ->and($result['category_priorities'])->toHaveKeys(['ecommerce', 'saas', 'engagement'])
                ->and($result['overall_grade'])->toBeString();
        });
    });

    describe('clearCache()', function (): void {
        test('does not throw when no cache', function (): void {
            $service = new ProviderAnalyticsIntelligenceService();
            expect(fn () => $service->clearCache())->not->toThrow(\Throwable::class);
        });
    });
});

describe('EventBuilder extensions (v9.6.0)', function (): void {
    test('source() sets source on built event', function (): void {
        $event = EventBuilder::make('page_view')
            ->source('api')
            ->validate(false)
            ->build();

        expect($event->source)->toBe('api');
    });

    test('sourceId() embeds _source_id in params', function (): void {
        $event = EventBuilder::make('click')
            ->sourceId('req-123-abc')
            ->validate(false)
            ->build();

        expect($event->params['_source_id'])->toBe('req-123-abc');
    });

    test('sessionId() embeds _session_id in params', function (): void {
        $event = EventBuilder::make('search')
            ->sessionId('sess-xyz-789')
            ->validate(false)
            ->build();

        expect($event->params['_session_id'])->toBe('sess-xyz-789');
    });

    test('group() embeds _group in params', function (): void {
        $event = EventBuilder::make('feature_used')
            ->group('workspace-ws42')
            ->validate(false)
            ->build();

        expect($event->params['_group'])->toBe('workspace-ws42');
    });

    test('all extensions chain together', function (): void {
        $event = EventBuilder::make('purchase')
            ->param('transaction_id', 'txn-999')
            ->source('server')
            ->sourceId('job-456')
            ->sessionId('sess-abc')
            ->group('org-123')
            ->validate(false)
            ->build();

        expect($event->source)->toBe('server')
            ->and($event->params['_source_id'])->toBe('job-456')
            ->and($event->params['_session_id'])->toBe('sess-abc')
            ->and($event->params['_group'])->toBe('org-123')
            ->and($event->params['transaction_id'])->toBe('txn-999');
    });

    test('extensions do not break existing builder API', function (): void {
        $event = EventBuilder::purchase('txn-789', 49.99, 'EUR')
            ->client('cid-abc')
            ->user('uid-123')
            ->priority('critical')
            ->build();

        expect($event->name)->toBe('purchase')
            ->and($event->params['transaction_id'])->toBe('txn-789')
            ->and($event->params['value'])->toBe(49.99)
            ->and($event->params['currency'])->toBe('EUR')
            ->and($event->clientId)->toBe('cid-abc')
            ->and($event->userId)->toBe('uid-123')
            ->and($event->priority)->toBe('critical');
    });

    test('omitted extensions do not add keys to params', function (): void {
        $event = EventBuilder::make('page_view')
            ->validate(false)
            ->build();

        expect($event->params)->not->toHaveKey('_source_id')
            ->and($event->params)->not->toHaveKey('_session_id')
            ->and($event->params)->not->toHaveKey('_group')
            ->and($event->source)->toBeNull();
    });
});
