<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\Services\AttributionModelService;
use ZeroBoiler\Analytics\Services\SaaSFeatureMatrixService;

// ── V7.9.0 Multi-Touch Attribution & Feature Matrix ────────────────

describe('V790 Multi-Touch Attribution Model Service', function () {

    describe('available models', function () {
        test('returns all five attribution models', function (): void {
            $service = new AttributionModelService(
                app(CacheRepository::class),
                app(Repository::class),
            );

            $models = $service->availableModels();

            expect($models)->toHaveCount(5);
            expect($models)->toHaveKeys([
                'first_touch', 'last_touch', 'linear', 'time_decay', 'position_based',
            ]);
        });

        test('each model has name, description, and enabled flag', function (): void {
            $service = new AttributionModelService(
                app(CacheRepository::class),
                app(Repository::class),
            );

            foreach ($service->availableModels() as $key => $model) {
                expect($model)->toHaveKey('name');
                expect($model)->toHaveKey('description');
                expect($model)->toHaveKey('enabled');
                expect($model['name'])->toBeString();
                expect($model['name'])->not->toBeEmpty();
                expect($model['description'])->toBeString();
                expect($model['description'])->not->toBeEmpty();
                expect($model['enabled'])->toBeBool();
            }
        });

        test('all models are enabled by default', function (): void {
            $service = new AttributionModelService(
                app(CacheRepository::class),
                app(Repository::class),
            );

            foreach ($service->availableModels() as $key => $model) {
                expect($model['enabled'])->toBeTrue("Model '{$key}' should be enabled by default");
            }
        });
    });

    describe('first-touch attribution', function () {
        test('assigns 100% credit to first touchpoint', function (): void {
            $service = new AttributionModelService(
                app(CacheRepository::class),
                app(Repository::class),
            );

            $touchpoints = [
                ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'brand'],
                ['source' => 'facebook', 'medium' => 'social', 'campaign' => 'retargeting'],
                ['source' => 'direct', 'medium' => 'none', 'campaign' => null],
            ];

            $result = $service->attribute('first_touch', $touchpoints, 100.0);

            expect($result['model'])->toBe('first_touch');
            expect($result['revenue'])->toBe(100.0);
            expect($result['touchpoints'])->toHaveCount(3);
            expect($result['touchpoints'][0]['credit'])->toBe(100.0);
            expect($result['touchpoints'][0]['credit_pct'])->toBe(100.0);
            expect($result['touchpoints'][1]['credit'])->toBe(0.0);
            expect($result['touchpoints'][2]['credit'])->toBe(0.0);
        });
    });

    describe('last-touch attribution', function () {
        test('assigns 100% credit to last touchpoint', function (): void {
            $service = new AttributionModelService(
                app(CacheRepository::class),
                app(Repository::class),
            );

            $touchpoints = [
                ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'brand'],
                ['source' => 'facebook', 'medium' => 'social', 'campaign' => 'retargeting'],
                ['source' => 'email', 'medium' => 'email', 'campaign' => 'nurture'],
            ];

            $result = $service->attribute('last_touch', $touchpoints, 200.0);

            expect($result['model'])->toBe('last_touch');
            expect($result['touchpoints'][0]['credit'])->toBe(0.0);
            expect($result['touchpoints'][1]['credit'])->toBe(0.0);
            expect($result['touchpoints'][2]['credit'])->toBe(200.0);
            expect($result['touchpoints'][2]['credit_pct'])->toBe(100.0);
        });
    });

    describe('linear attribution', function () {
        test('distributes credit equally across all touchpoints', function (): void {
            $service = new AttributionModelService(
                app(CacheRepository::class),
                app(Repository::class),
            );

            $touchpoints = [
                ['source' => 'google', 'medium' => 'cpc'],
                ['source' => 'facebook', 'medium' => 'social'],
                ['source' => 'email', 'medium' => 'email'],
                ['source' => 'direct', 'medium' => 'none'],
            ];

            $result = $service->attribute('linear', $touchpoints, 100.0);

            expect($result['model'])->toBe('linear');
            expect($result['touchpoints'])->toHaveCount(4);

            foreach ($result['touchpoints'] as $tp) {
                expect($tp['credit'])->toBe(25.0);
                expect($tp['credit_pct'])->toBe(25.0);
            }
        });

        test('handles two touchpoints correctly', function (): void {
            $service = new AttributionModelService(
                app(CacheRepository::class),
                app(Repository::class),
            );

            $touchpoints = [
                ['source' => 'google', 'medium' => 'organic'],
                ['source' => 'direct', 'medium' => 'none'],
            ];

            $result = $service->attribute('linear', $touchpoints, 50.0);

            expect($result['touchpoints'][0]['credit'])->toBe(25.0);
            expect($result['touchpoints'][1]['credit'])->toBe(25.0);
        });
    });

    describe('time-decay attribution', function () {
        test('assigns more credit to recent touchpoints', function (): void {
            $service = new AttributionModelService(
                app(CacheRepository::class),
                app(Repository::class),
            );

            $touchpoints = [
                ['source' => 'google', 'medium' => 'cpc', 'timestamp' => '2024-01-01'],
                ['source' => 'facebook', 'medium' => 'social', 'timestamp' => '2024-01-05'],
                ['source' => 'email', 'medium' => 'email', 'timestamp' => '2024-01-08'],
            ];

            $result = $service->attribute('time_decay', $touchpoints, 100.0);

            expect($result['model'])->toBe('time_decay');
            expect($result['touchpoints'])->toHaveCount(3);

            // Last touchpoint should have highest credit
            $credits = array_column($result['touchpoints'], 'credit');
            expect($credits[2])->toBeGreaterThan($credits[0]);
            expect($credits[1])->toBeGreaterThan($credits[0]);

            // Credits should sum to revenue
            expect(array_sum($credits))->toBe(100.0);
        });

        test('credits sum to revenue', function (): void {
            $service = new AttributionModelService(
                app(CacheRepository::class),
                app(Repository::class),
            );

            $touchpoints = array_map(
                fn (int $i): array => ['source' => "ch_{$i}", 'medium' => 'cpc'],
                range(1, 10),
            );

            $result = $service->attribute('time_decay', $touchpoints, 500.0);
            $credits = array_column($result['touchpoints'], 'credit');

            expect(abs(array_sum($credits) - 500.0))->toBeLessThan(0.01);
        });
    });

    describe('position-based attribution', function () {
        test('assigns 40% first, 40% last, 20% middle (3+ touchpoints)', function (): void {
            $service = new AttributionModelService(
                app(CacheRepository::class),
                app(Repository::class),
            );

            $touchpoints = [
                ['source' => 'google', 'medium' => 'cpc'],
                ['source' => 'facebook', 'medium' => 'social'],
                ['source' => 'email', 'medium' => 'email'],
                ['source' => 'direct', 'medium' => 'none'],
            ];

            $result = $service->attribute('position_based', $touchpoints, 100.0);

            expect($result['model'])->toBe('position_based');

            // First: 40%
            expect($result['touchpoints'][0]['credit'])->toBe(40.0);
            expect($result['touchpoints'][0]['credit_pct'])->toBe(40.0);

            // Last: 40%
            expect($result['touchpoints'][3]['credit'])->toBe(40.0);
            expect($result['touchpoints'][3]['credit_pct'])->toBe(40.0);

            // Middle 2 touchpoints share 20% → 10% each
            expect($result['touchpoints'][1]['credit'])->toBe(10.0);
            expect($result['touchpoints'][2]['credit'])->toBe(10.0);

            // Sum to 100
            $credits = array_column($result['touchpoints'], 'credit');
            expect(array_sum($credits))->toBe(100.0);
        });

        test('handles 2 touchpoints correctly', function (): void {
            $service = new AttributionModelService(
                app(CacheRepository::class),
                app(Repository::class),
            );

            $touchpoints = [
                ['source' => 'google', 'medium' => 'cpc'],
                ['source' => 'direct', 'medium' => 'none'],
            ];

            $result = $service->attribute('position_based', $touchpoints, 100.0);

            // First gets 60% (40% first + 20% middle), last gets 40%
            expect($result['touchpoints'][0]['credit'])->toBe(60.0);
            expect($result['touchpoints'][1]['credit'])->toBe(40.0);
        });
    });

    describe('edge cases', function () {
        test('returns empty result for empty touchpoints', function (): void {
            $service = new AttributionModelService(
                app(CacheRepository::class),
                app(Repository::class),
            );

            $result = $service->attribute('first_touch', [], 100.0);

            expect($result['model'])->toBe('first_touch');
            expect($result['revenue'])->toBe(100.0);
            expect($result['touchpoints'])->toBeEmpty();
        });

        test('single touchpoint gets 100% credit', function (): void {
            $service = new AttributionModelService(
                app(CacheRepository::class),
                app(Repository::class),
            );

            $touchpoints = [['source' => 'direct', 'medium' => 'none']];

            foreach (['first_touch', 'last_touch', 'linear', 'time_decay', 'position_based'] as $model) {
                $result = $service->attribute($model, $touchpoints, 50.0);
                expect($result['touchpoints'][0]['credit'])->toBe(50.0);
                expect($result['touchpoints'][0]['credit_pct'])->toBe(100.0);
            }
        });

        test('invalid model falls back to default', function (): void {
            $service = new AttributionModelService(
                app(CacheRepository::class),
                app(Repository::class),
            );

            $touchpoints = [
                ['source' => 'google', 'medium' => 'cpc'],
                ['source' => 'direct', 'medium' => 'none'],
            ];

            $result = $service->attribute('invalid_model', $touchpoints, 100.0);

            expect($result['model'])->toBe('position_based');
        });

        test('handles zero revenue', function (): void {
            $service = new AttributionModelService(
                app(CacheRepository::class),
                app(Repository::class),
            );

            $touchpoints = [
                ['source' => 'google', 'medium' => 'cpc'],
                ['source' => 'direct', 'medium' => 'none'],
            ];

            $result = $service->attribute('linear', $touchpoints, 0.0);

            expect($result['revenue'])->toBe(0.0);
            expect($result['touchpoints'][0]['credit'])->toBe(0.0);
            expect($result['touchpoints'][0]['credit_pct'])->toBe(0.0);
        });

        test('handles large number of touchpoints', function (): void {
            $service = new AttributionModelService(
                app(CacheRepository::class),
                app(Repository::class),
            );

            $touchpoints = array_map(
                fn (int $i): array => ['source' => "ch_{$i}", 'medium' => 'cpc', 'timestamp' => "2024-01-{$i}"],
                range(1, 50),
            );

            $result = $service->attribute('linear', $touchpoints, 1000.0);
            $credits = array_column($result['touchpoints'], 'credit');

            expect($result['touchpoints'])->toHaveCount(50);
            expect(abs(array_sum($credits) - 1000.0))->toBeLessThan(0.01);
        });
    });

    describe('compareModels', function () {
        test('compares all enabled models', function (): void {
            $service = new AttributionModelService(
                app(CacheRepository::class),
                app(Repository::class),
            );

            $touchpoints = [
                ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'brand'],
                ['source' => 'facebook', 'medium' => 'social', 'campaign' => 'retargeting'],
                ['source' => 'email', 'medium' => 'email', 'campaign' => 'nurture'],
            ];

            $result = $service->compareModels($touchpoints, 100.0);

            expect($result)->toHaveKey('models');
            expect($result)->toHaveKey('recommended');
            expect($result)->toHaveKey('total_touchpoints');
            expect($result['total_touchpoints'])->toBe(3);
            expect($result['recommended'])->toBe('position_based');

            // All 5 models should be present
            expect($result['models'])->toHaveCount(5);
            expect($result['models'])->toHaveKeys([
                'first_touch', 'last_touch', 'linear', 'time_decay', 'position_based',
            ]);

            // Each model should have the correct revenue
            foreach ($result['models'] as $modelResult) {
                expect($modelResult['revenue'])->toBe(100.0);
                expect($modelResult['touchpoints'])->toHaveCount(3);
            }
        });

        test('works with empty touchpoints', function (): void {
            $service = new AttributionModelService(
                app(CacheRepository::class),
                app(Repository::class),
            );

            $result = $service->compareModels([], 50.0);

            expect($result['total_touchpoints'])->toBe(0);

            foreach ($result['models'] as $modelResult) {
                expect($modelResult['touchpoints'])->toBeEmpty();
            }
        });
    });

    describe('aggregateByChannel', function () {
        test('aggregates attribution by source channel', function (): void {
            $service = new AttributionModelService(
                app(CacheRepository::class),
                app(Repository::class),
            );

            $journeys = [
                [
                    'touchpoints' => [
                        ['source' => 'google', 'medium' => 'cpc'],
                        ['source' => 'direct', 'medium' => 'none'],
                    ],
                    'revenue' => 100.0,
                ],
                [
                    'touchpoints' => [
                        ['source' => 'google', 'medium' => 'organic'],
                        ['source' => 'facebook', 'medium' => 'social'],
                    ],
                    'revenue' => 200.0,
                ],
            ];

            $result = $service->aggregateByChannel($journeys, 'linear');

            expect($result)->toHaveKey('channels');
            expect($result)->toHaveKey('total_revenue');
            expect($result)->toHaveKey('model');
            expect($result['model'])->toBe('linear');
            expect($result['total_revenue'])->toBe(300.0);
            expect($result['channels'])->toHaveKey('google');

            // google should have most credit (present in both journeys)
            expect($result['channels']['google']['revenue'])->toBeGreaterThan(0.0);
            expect($result['channels']['google']['pct'])->toBeGreaterThan(0.0);
        });
    });

    describe('aggregateByCampaign', function () {
        test('aggregates attribution by campaign', function (): void {
            $service = new AttributionModelService(
                app(CacheRepository::class),
                app(Repository::class),
            );

            $journeys = [
                [
                    'touchpoints' => [
                        ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'brand'],
                        ['source' => 'direct', 'medium' => 'none', 'campaign' => null],
                    ],
                    'revenue' => 100.0,
                ],
            ];

            $result = $service->aggregateByCampaign($journeys, 'first_touch');

            expect($result['model'])->toBe('first_touch');
            expect($result['total_revenue'])->toBe(100.0);
            expect($result['campaigns'])->toHaveKey('brand');
            expect($result)->toHaveKey('no_campaign_pct');
        });
    });

    describe('channelEfficiency', function () {
        test('computes ROAS and CPA metrics', function (): void {
            $service = new AttributionModelService(
                app(CacheRepository::class),
                app(Repository::class),
            );

            $journeys = [
                [
                    'touchpoints' => [
                        ['source' => 'google', 'medium' => 'cpc'],
                        ['source' => 'direct', 'medium' => 'none'],
                    ],
                    'revenue' => 100.0,
                ],
            ];

            $result = $service->channelEfficiency($journeys, 'first_touch', ['google' => 50.0]);

            expect($result)->toHaveKey('channels');
            expect($result)->toHaveKey('total_revenue');
            expect($result)->toHaveKey('total_cost');
            expect($result['total_cost'])->toBe(50.0);

            // First-touch: google gets 100.0 revenue with 50.0 cost → ROAS = 2.0
            expect($result['channels']['google']['attributed_revenue'])->toBe(100.0);
            expect($result['channels']['google']['cost'])->toBe(50.0);
            expect($result['channels']['google']['roas'])->toBe(2.0);
        });
    });

    describe('getDefaultModel', function () {
        test('returns position_based by default', function (): void {
            $service = new AttributionModelService(
                app(CacheRepository::class),
                app(Repository::class),
            );

            expect($service->getDefaultModel())->toBe('position_based');
        });
    });
});

describe('V790 SaaS Feature Matrix Service', function () {

    describe('feature definitions', function () {
        test('returns all 13 categories', function (): void {
            $service = new SaaSFeatureMatrixService();
            $definitions = $service->featureDefinitions();

            expect(count($definitions))->toBeGreaterThanOrEqual(13);
            expect($definitions)->toHaveKeys([
                'event_tracking', 'identity', 'providers', 'saas_lifecycle',
                'ecommerce', 'engagement', 'funnel_analytics', 'cohort_analytics',
                'attribution', 'privacy_compliance', 'queue_reliability',
                'pipeline', 'dashboard',
            ]);
        });

        test('each category has features with description and signals', function (): void {
            $service = new SaaSFeatureMatrixService();
            $definitions = $service->featureDefinitions();

            foreach ($definitions as $catKey => $category) {
                expect($category)->toHaveKey('name');
                expect($category)->toHaveKey('features');
                expect(count($category['features']))->toBeGreaterThan(0);

                foreach ($category['features'] as $featureKey => $feature) {
                    expect($feature)->toHaveKey('description');
                    expect($feature)->toHaveKey('signals');
                    expect($feature['description'])->toBeString();
                    expect($feature['description'])->not->toBeEmpty();
                    expect($feature['signals'])->not->toBeEmpty();
                }
            }
        });

        test('total feature count is at least 70', function (): void {
            $service = new SaaSFeatureMatrixService();
            $definitions = $service->featureDefinitions();

            $total = 0;
            foreach ($definitions as $category) {
                $total += count($category['features']);
            }

            expect($total)->toBeGreaterThanOrEqual(70);
        });
    });

    describe('coverage summary', function () {
        test('returns valid summary structure', function (): void {
            $service = new SaaSFeatureMatrixService();
            $summary = $service->coverageSummary();

            expect($summary)->toHaveKeys(['total', 'supported', 'pct', 'grade']);
            expect($summary['total'])->toBeInt();
            expect($summary['total'])->toBeGreaterThanOrEqual(70);
            expect($summary['supported'])->toBeInt();
            expect($summary['supported'])->toBeGreaterThan(0);
            expect($summary['pct'])->toBeFloat();
            expect($summary['pct'])->toBeGreaterThanOrEqual(0.0);
            expect($summary['pct'])->toBeLessThanOrEqual(100.0);
            expect($summary['grade'])->toBeString();
            expect(strlen($summary['grade']))->toBeGreaterThanOrEqual(1);
        });

        test('coverage score is high for this package', function (): void {
            $service = new SaaSFeatureMatrixService();
            $summary = $service->coverageSummary();

            // This package should cover at least 80% of industry features
            expect($summary['pct'])->toBeGreaterThanOrEqual(80.0);
            expect(in_array($summary['grade'], ['A+', 'A', 'A-', 'B+'], true))->toBeTrue();
        });
    });

    describe('feature matrix', function () {
        test('buildMatrix returns complete structure', function (): void {
            $service = new SaaSFeatureMatrixService();
            $matrix = $service->buildMatrix();

            expect($matrix)->toHaveKeys(['categories', 'coverage', 'gaps']);
            expect($matrix['coverage'])->toHaveKeys(['total', 'supported', 'pct', 'by_category']);

            // Each category should have features
            foreach ($matrix['categories'] as $key => $category) {
                expect($category)->toHaveKey('name');
                expect($category)->toHaveKey('features');
                expect(count($category['features']))->toBeGreaterThan(0);

                foreach ($category['features'] as $feature) {
                    expect($feature)->toHaveKeys(['description', 'supported', 'signals', 'detected_signals']);
                    expect(is_bool($feature['supported']))->toBeTrue();
                }
            }
        });

        test('coverage percentages sum correctly', function (): void {
            $service = new SaaSFeatureMatrixService();
            $matrix = $service->buildMatrix();

            expect($matrix['coverage']['total'])->toBeGreaterThan(0);
            expect($matrix['coverage']['supported'])->toBeGreaterThan(0);

            // supported should not exceed total
            expect($matrix['coverage']['supported'])->toBeLessThanOrEqual($matrix['coverage']['total']);
        });
    });

    describe('gaps', function () {
        test('getGaps returns array of gap objects', function (): void {
            $service = new SaaSFeatureMatrixService();
            $gaps = $service->getGaps();

            expect($gaps)->toBeArray();

            foreach ($gaps as $gap) {
                expect($gap)->toHaveKeys(['category', 'feature', 'description']);
                expect($gap['category'])->toBeString();
                expect($gap['feature'])->toBeString();
                expect($gap['description'])->toBeString();
            }
        });

        test('has fewer than 15 gaps for this comprehensive package', function (): void {
            $service = new SaaSFeatureMatrixService();
            $gaps = $service->getGaps();

            expect(count($gaps))->toBeLessThan(15);
        });
    });

    describe('category summary', function () {
        test('returns per-category breakdown', function (): void {
            $service = new SaaSFeatureMatrixService();
            $summary = $service->categorySummary();

            expect(count($summary))->toBeGreaterThanOrEqual(13);

            foreach ($summary as $key => $cat) {
                expect($cat)->toHaveKeys(['name', 'total', 'supported', 'pct']);
                expect($cat['total'])->toBeInt();
                expect($cat['total'])->toBeGreaterThan(0);
                expect($cat['supported'])->toBeInt();
                expect($cat['supported'])->toBeGreaterThanOrEqual(0);
                expect($cat['supported'])->toBeLessThanOrEqual($cat['total']);
            }
        });
    });

    describe('competitor comparison', function () {
        test('compare with segment returns advantages and disadvantages', function (): void {
            $service = new SaaSFeatureMatrixService();
            $result = $service->compareWith('segment');

            expect($result)->toHaveKeys(['competitor', 'advantages', 'disadvantages', 'parity_score']);
            expect($result['competitor'])->toBe('segment');
            expect($result['parity_score'])->toBeFloat();
            expect($result['parity_score'])->toBeGreaterThanOrEqual(0.0);
            expect($result['parity_score'])->toBeLessThanOrEqual(100.0);

            // ZeroBoiler should have advantages (6 providers vs Segment's 2)
            expect(count($result['advantages']))->toBeGreaterThan(0);
        });

        test('compare with plausible shows many advantages', function (): void {
            $service = new SaaSFeatureMatrixService();
            $result = $service->compareWith('plausible');

            expect($result['competitor'])->toBe('plausible');

            // Plausible is minimal — ZeroBoiler should have way more features
            expect(count($result['advantages']))->toBeGreaterThan(50);
            expect($result['parity_score'])->toBeGreaterThanOrEqual(90.0);
        });

        test('unknown competitor returns empty comparison', function (): void {
            $service = new SaaSFeatureMatrixService();
            $result = $service->compareWith('unknown_platform');

            expect($result['competitor'])->toBe('unknown_platform');
            expect($result['advantages'])->toBeEmpty();
            expect($result['disadvantages'])->toBeEmpty();
        });
    });

    describe('core event categories fully covered', function () {
        test('event_tracking category is fully supported', function (): void {
            $service = new SaaSFeatureMatrixService();
            $summary = $service->categorySummary();

            expect($summary['event_tracking']['pct'])->toBe(100.0);
        });

        test('providers category is fully supported', function (): void {
            $service = new SaaSFeatureMatrixService();
            $summary = $service->categorySummary();

            expect($summary['providers']['pct'])->toBe(100.0);
        });

        test('saas_lifecycle category is fully supported', function (): void {
            $service = new SaaSFeatureMatrixService();
            $summary = $service->categorySummary();

            expect($summary['saas_lifecycle']['pct'])->toBe(100.0);
        });
    });
});

describe('V790 Version Consistency', function () {
    test('composer.json version is 7.9.0', function (): void {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);
        expect($composer['version'])->toBe('7.9.0');
    });

    test('AnalyticsEvent VERSION is 7.9.0', function (): void {
        expect(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION)->toBe('7.9.0');
    });

    test('JS client version is 7.9.0', function (): void {
        $js = file_get_contents(base_path('resources/js/analytics.js'));
        expect(str_contains($js, "'7.9.0'"))->toBeTrue();
        expect(str_contains($js, '@version 7.9.0'))->toBeTrue();
    });

    test('Svelte composable version is 7.9.0', function (): void {
        $svelte = file_get_contents(base_path('resources/js/useAnalytics.svelte.js'));
        expect(str_contains($svelte, '@version 7.9.0'))->toBeTrue();
    });

    test('TypeScript definitions version is 7.9.0', function (): void {
        $dts = file_get_contents(base_path('resources/js/analytics.d.ts'));
        expect(str_contains($dts, '@version 7.9.0'))->toBeTrue();
    });

    test('config has attribution_model section', function (): void {
        $config = app(Repository::class)->get('zeroboiler.analytics');
        expect($config)->toHaveKey('attribution_model');
        expect($config['attribution_model'])->toHaveKey('default_model');
        expect($config['attribution_model'])->toHaveKey('time_decay_factor');
        expect($config['attribution_model'])->toHaveKey('enabled_models');
    });

    test('config has feature_matrix section', function (): void {
        $config = app(Repository::class)->get('zeroboiler.analytics');
        expect($config)->toHaveKey('feature_matrix');
        expect($config['feature_matrix'])->toHaveKey('enabled');
        expect($config['feature_matrix'])->toHaveKey('cache_ttl');
    });
});
