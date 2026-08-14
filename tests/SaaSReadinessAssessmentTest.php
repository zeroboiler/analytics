<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Services\SaaSReadinessAssessment;

describe('SaaSReadinessAssessment', function () {
    describe('assess()', function () {
        it('returns a complete assessment report', function () {
            $assessment = new SaaSReadinessAssessment(
                trackedEvents: ['sign_up', 'login', 'page_view'],
                enabledProviders: ['ga4' => true, 'meta' => true],
                configFlags: ['identity' => true, 'queue' => true, 'consent' => true],
            );

            $report = $assessment->assess();

            expect($report)->toHaveKey('overall_score');
            expect($report)->toHaveKey('overall_grade');
            expect($report)->toHaveKey('dimensions');
            expect($report)->toHaveKey('tracked_events');
            expect($report)->toHaveKey('total_catalog_events');
            expect($report)->toHaveKey('tracked_count');
            expect($report)->toHaveKey('coverage_percent');
            expect($report)->toHaveKey('generated_at');
            expect($report)->toHaveKey('version');

            expect($report['overall_score'])->toBeFloat();
            expect($report['overall_grade'])->toBeString();
            expect($report['tracked_count'])->toBe(3);
            expect($report['tracked_events'])->toBe(['sign_up', 'login', 'page_view']);
            expect($report['version'])->toBe('101.0.0');
        });

        it('includes all 7 assessment dimensions', function () {
            $assessment = new SaaSReadinessAssessment();
            $report = $assessment->assess();

            $dimensions = array_keys($report['dimensions']);

            expect($dimensions)->toBe([
                'event_coverage',
                'provider_coverage',
                'funnel_readiness',
                'aarrr_coverage',
                'identity_tracking',
                'ecommerce_readiness',
                'configuration_quality',
            ]);
        });

        it('each dimension has required fields', function () {
            $assessment = new SaaSReadinessAssessment();
            $report = $assessment->assess();

            foreach ($report['dimensions'] as $key => $dimension) {
                expect($dimension)
                    ->toHaveKey('name')->and()
                    ->toHaveKey('score')->and()
                    ->toHaveKey('max')->and()
                    ->toHaveKey('percent')->and()
                    ->toHaveKey('status')->and()
                    ->toHaveKey('findings')->and()
                    ->toHaveKey('recommendations');

                expect($dimension['name'])->toBeString();
                expect($dimension['percent'])->toBeFloat()->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(100.0);
                expect($dimension['status'])->toBeIn(['excellent', 'good', 'fair', 'poor', 'missing']);
                expect($dimension['findings'])->toBeArray();
                expect($dimension['recommendations'])->toBeArray();
            }
        });

        it('calculates grade correctly', function () {
            // With no tracking, should get F
            $empty = new SaaSReadinessAssessment();
            $report = $empty->assess();

            expect($report['overall_grade'])->toBe('F');
            expect($report['overall_score'])->toBeLessThan(40.0);
        });

        it('score improves with more tracked events', function () {
            $minimal = new SaaSReadinessAssessment(['sign_up']);
            $report = $minimal->assess();

            $comprehensive = new SaaSReadinessAssessment([
                'sign_up', 'login', 'logout', 'page_view', 'start_trial',
                'trial_converted', 'subscribe', 'plan_upgrade', 'purchase',
                'payment_succeeded', 'feature_used', 'onboarding_step',
                'email_verified', 'share', 'invite_sent', 'team_member_joined',
            ], ['ga4' => true, 'meta' => true, 'posthog' => true], [
                'identity' => true, 'queue' => true, 'auto_track' => true,
                'consent' => true, 'ecommerce' => true, 'api' => true,
                'lifecycle' => true,
            ]);
            $comprehensiveReport = $comprehensive->assess();

            expect($comprehensiveReport['overall_score'])->toBeGreaterThan($report['overall_score']);
        });
    });

    describe('quickSummary()', function () {
        it('returns summary fields', function () {
            $assessment = new SaaSReadinessAssessment(['sign_up', 'login']);
            $summary = $assessment->quickSummary();

            expect($summary)->toHaveKey('score');
            expect($summary)->toHaveKey('grade');
            expect($summary)->toHaveKey('tracked');
            expect($summary)->toHaveKey('total');
            expect($summary)->toHaveKey('percent');
            expect($summary)->toHaveKey('funnel_coverage');
            expect($summary)->toHaveKey('aarrr_coverage');

            expect($summary['tracked'])->toBe(2);
        });
    });

    describe('topRecommendations()', function () {
        it('returns actionable recommendations', function () {
            $assessment = new SaaSReadinessAssessment([]);
            $recs = $assessment->topRecommendations(3);

            expect($recs)->toBeArray();
            expect(count($recs))->toBeLessThanOrEqual(3);

            foreach ($recs as $rec) {
                expect($rec)->toHaveKey('action');
                expect($rec)->toHaveKey('impact');
                expect($rec)->toHaveKey('dimension');
                expect($rec['action'])->toBeString()->not->toBeEmpty();
                expect($rec['impact'])->toBeIn(['high', 'medium', 'low']);
            }
        });

        it('sorts by impact (high first)', function () {
            $assessment = new SaaSReadinessAssessment([]);
            $recs = $assessment->topRecommendations(10);

            $highCount = 0;
            $mediumCount = 0;
            $lowCount = 0;

            foreach ($recs as $rec) {
                if ($rec['impact'] === 'high') {
                    $highCount++;
                } elseif ($rec['impact'] === 'medium') {
                    $mediumCount++;
                } else {
                    $lowCount++;
                }
            }

            // High should come first
            $foundLow = false;
            foreach ($recs as $rec) {
                if ($rec['impact'] === 'low') {
                    $foundLow = true;
                }
                if ($foundLow && $rec['impact'] === 'high') {
                    $this->fail('High impact recommendation found after low impact');
                }
            }
            expect(true)->toBeTrue(); // Assert we didn't fail above
        });

        it('limits output', function () {
            $assessment = new SaaSReadinessAssessment([]);
            $recs = $assessment->topRecommendations(2);

            expect(count($recs))->toBeLessThanOrEqual(2);
        });
    });

    describe('event_coverage dimension', function () {
        it('scores higher with critical events tracked', function () {
            $noCritical = new SaaSReadinessAssessment(['page_view']);
            $withCritical = new SaaSReadinessAssessment([
                'sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade',
                'cancellation', 'purchase', 'payment_succeeded', 'trial_converted',
            ]);

            $noCriticalReport = $noCritical->assess();
            $withCriticalReport = $withCritical->assess();

            expect($withCriticalReport['dimensions']['event_coverage']['percent'])
                ->toBeGreaterThan($noCriticalReport['dimensions']['event_coverage']['percent']);
        });
    });

    describe('identity_tracking dimension', function () {
        it('scores 0 when nothing is configured', function () {
            $assessment = new SaaSReadinessAssessment([], [], []);
            $report = $assessment->assess();

            expect($report['dimensions']['identity_tracking']['percent'])->toBe(0.0);
            expect($report['dimensions']['identity_tracking']['status'])->toBe('missing');
        });

        it('scores 100 when all identity features are enabled', function () {
            $assessment = new SaaSReadinessAssessment([], [], [
                'identity' => true, 'api' => true, 'lifecycle' => true,
            ]);
            $report = $assessment->assess();

            expect($report['dimensions']['identity_tracking']['percent'])->toBe(100.0);
            expect($report['dimensions']['identity_tracking']['status'])->toBe('excellent');
        });

        it('scores 40 with identity alone', function () {
            $assessment = new SaaSReadinessAssessment([], [], ['identity' => true]);
            $report = $assessment->assess();

            expect($report['dimensions']['identity_tracking']['percent'])->toBe(40.0);
        });
    });

    describe('configuration_quality dimension', function () {
        it('scores 0 with no config flags', function () {
            $assessment = new SaaSReadinessAssessment([], [], []);
            $report = $assessment->assess();

            expect($report['dimensions']['configuration_quality']['percent'])->toBe(0.0);
        });

        it('scores 100 with all config flags', function () {
            $assessment = new SaaSReadinessAssessment([], [], [
                'queue' => true, 'auto_track' => true, 'consent' => true,
                'ecommerce' => true, 'api' => true, 'lifecycle' => true,
            ]);
            $report = $assessment->assess();

            expect($report['dimensions']['configuration_quality']['percent'])->toBe(100.0);
        });
    });

    describe('funnel_readiness dimension', function () {
        it('detects fully instrumented funnels', function () {
            // signup_funnel requires: page_view, sign_up, email_verified, login
            $assessment = new SaaSReadinessAssessment([
                'page_view', 'sign_up', 'email_verified', 'login',
            ]);
            $report = $assessment->assess();

            $funnelFindings = $report['dimensions']['funnel_readiness']['findings'];
            $fullyInstrumented = array_filter($funnelFindings, fn (string $f): bool => str_contains($f, 'fully instrumented'));
            expect(count($fullyInstrumented))->toBeGreaterThanOrEqual(1);
        });
    });

    describe('aarrr_coverage dimension', function () {
        it('detects weak pillar coverage', function () {
            $assessment = new SaaSReadinessAssessment(['sign_up']);
            $report = $assessment->assess();

            $recs = $report['dimensions']['aarrr_coverage']['recommendations'];
            expect($recs)->not->toBeEmpty();
        });

        it('improves with events across all pillars', function () {
            $onePillar = new SaaSReadinessAssessment(['sign_up']);
            $allPillars = new SaaSReadinessAssessment([
                'sign_up', 'page_view', 'email_verified', 'feature_used',
                'login', 'content_engagement', 'purchase', 'subscribe',
                'plan_upgrade', 'payment_succeeded', 'share', 'invite_sent',
            ]);

            $oneReport = $onePillar->assess();
            $allReport = $allPillars->assess();

            expect($allReport['dimensions']['aarrr_coverage']['percent'])
                ->toBeGreaterThan($oneReport['dimensions']['aarrr_coverage']['percent']);
        });
    });

    describe('ecommerce_readiness dimension', function () {
        it('detects missing core e-commerce events', function () {
            $assessment = new SaaSReadinessAssessment(['view_item']);
            $report = $assessment->assess();

            $recs = $report['dimensions']['ecommerce_readiness']['recommendations'];
            expect($recs)->not->toBeEmpty();
            expect(implode(' ', $recs))->toContain('add_to_cart');
        });

        it('scores higher with all core e-commerce events', function () {
            $partial = new SaaSReadinessAssessment(['view_item']);
            $core = new SaaSReadinessAssessment([
                'view_item', 'add_to_cart', 'begin_checkout', 'purchase',
                'remove_from_cart', 'view_cart', 'add_to_wishlist',
                'select_item', 'add_payment_info', 'refund',
                'select_promotion', 'view_promotion', 'checkout_step',
                'abandoned_cart', 'checkout_abandon',
            ]);

            $partialReport = $partial->assess();
            $coreReport = $core->assess();

            expect($coreReport['dimensions']['ecommerce_readiness']['percent'])
                ->toBeGreaterThan($partialReport['dimensions']['ecommerce_readiness']['percent']);
        });
    });
});
