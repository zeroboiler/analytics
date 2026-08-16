<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\SaaSFunnelDefinitions;

describe('SaaSFunnelDefinitions', function () {
    describe('all()', function () {
        it('returns all 8 built-in funnel definitions', function () {
            $funnels = SaaSFunnelDefinitions::all();

            expect($funnels)->toHaveCount(8);
            expect(array_keys($funnels))->toBe([
                'signup_funnel',
                'trial_conversion_funnel',
                'expansion_revenue_funnel',
                'activation_funnel',
                'checkout_funnel',
                'retention_funnel',
                'referral_funnel',
                'cancellation_flow_funnel',
            ]);
        });

        it('each funnel has required fields', function () {
            foreach (SaaSFunnelDefinitions::all() as $key => $funnel) {
                expect($funnel)
                    ->toHaveKey('key')->and()
                    ->toHaveKey('name')->and()
                    ->toHaveKey('description')->and()
                    ->toHaveKey('category')->and()
                    ->toHaveKey('aarrr_pillar')->and()
                    ->toHaveKey('steps');

                expect($funnel['key'])->toBe($key);
                expect($funnel['name'])->toBeString()->not->toBeEmpty();
                expect($funnel['steps'])->toBeArray()->not->toBeEmpty();
                expect(count($funnel['steps']))->toBeGreaterThanOrEqual(2);
            }
        });

        it('each step has required fields', function () {
            foreach (SaaSFunnelDefinitions::all() as $key => $funnel) {
                foreach ($funnel['steps'] as $i => $step) {
                    expect($step)
                        ->toHaveKey('name')->and()
                        ->toHaveKey('label')->and()
                        ->toHaveKey('event_name')->and()
                        ->toHaveKey('expected_window_days')->and()
                        ->toHaveKey('weight');

                    expect($step['event_name'])->toBeString()->not->toBeEmpty();
                    expect($step['expected_window_days'])->toBeInt()->toBeGreaterThanOrEqual(0);
                    expect($step['weight'])->toBeFloat()->toBeGreaterThan(0);
                }
            }
        });
    });

    describe('get()', function () {
        it('returns a funnel definition by key', function () {
            $funnel = SaaSFunnelDefinitions::get('signup_funnel');

            expect($funnel)->not->toBeNull();
            expect($funnel['name'])->toBe('Signup Funnel');
            expect($funnel['aarrr_pillar'])->toBe('acquisition');
        });

        it('returns null for unknown key', function () {
            expect(SaaSFunnelDefinitions::get('nonexistent'))->toBeNull();
        });
    });

    describe('keys()', function () {
        it('returns all funnel keys', function () {
            $keys = SaaSFunnelDefinitions::keys();

            expect($keys)->toContain('signup_funnel');
            expect($keys)->toContain('trial_conversion_funnel');
            expect($keys)->toContain('checkout_funnel');
            expect($keys)->toHaveCount(8);
        });
    });

    describe('count()', function () {
        it('returns 8', function () {
            expect(SaaSFunnelDefinitions::count())->toBe(8);
        });
    });

    describe('byAarrrPillar()', function () {
        it('groups funnels by AARRR pillar', function () {
            $grouped = SaaSFunnelDefinitions::byAarrrPillar();

            expect($grouped)->toHaveKey('acquisition');
            expect($grouped)->toHaveKey('activation');
            expect($grouped)->toHaveKey('retention');
            expect($grouped)->toHaveKey('revenue');
            expect($grouped)->toHaveKey('referral');

            // Acquisition should have signup funnel
            $acquisition = $grouped['acquisition'];
            expect($acquisition)->not->toBeEmpty();
            expect(array_column($acquisition, 'key'))->toContain('signup_funnel');

            // Revenue should have trial conversion and checkout
            $revenue = $grouped['revenue'];
            $revenueKeys = array_column($revenue, 'key');
            expect($revenueKeys)->toContain('trial_conversion_funnel');
            expect($revenueKeys)->toContain('checkout_funnel');
            expect($revenueKeys)->toContain('expansion_revenue_funnel');
        });
    });

    describe('byCategory()', function () {
        it('filters by saas category', function () {
            $saas = SaaSFunnelDefinitions::byCategory('saas');

            expect($saas)->not->toBeEmpty();
            foreach ($saas as $funnel) {
                expect($funnel['category'])->toBe('saas');
            }
        });

        it('filters by ecommerce category', function () {
            $ecommerce = SaaSFunnelDefinitions::byCategory('ecommerce');

            expect($ecommerce)->toHaveCount(1);
            expect($ecommerce[0]['key'])->toBe('checkout_funnel');
        });
    });

    describe('requiredEvents()', function () {
        it('returns event names for signup funnel', function () {
            $events = SaaSFunnelDefinitions::requiredEvents('signup_funnel');

            expect($events)->toContain('page_view');
            expect($events)->toContain('sign_up');
            expect($events)->toContain('email_verified');
            expect($events)->toContain('login');
        });

        it('returns empty array for unknown funnel', function () {
            expect(SaaSFunnelDefinitions::requiredEvents('unknown'))->toBe([]);
        });
    });

    describe('fullyInstrumented()', function () {
        it('identifies fully instrumented funnels', function () {
            $tracked = ['page_view', 'sign_up', 'email_verified', 'login'];
            $instrumented = SaaSFunnelDefinitions::fullyInstrumented($tracked);

            $keys = array_column($instrumented, 'key');
            expect($keys)->toContain('signup_funnel');
        });

        it('excludes partially instrumented funnels', function () {
            $tracked = ['sign_up', 'login']; // missing page_view, email_verified
            $instrumented = SaaSFunnelDefinitions::fullyInstrumented($tracked);

            $keys = array_column($instrumented, 'key');
            expect($keys)->not->toContain('signup_funnel');
        });

        it('returns empty when nothing is tracked', function () {
            expect(SaaSFunnelDefinitions::fullyInstrumented([]))->toBeEmpty();
        });
    });

    describe('coverageReport()', function () {
        it('returns coverage for all funnels', function () {
            $tracked = ['sign_up', 'email_verified', 'login', 'start_trial', 'feature_used', 'purchase'];
            $report = SaaSFunnelDefinitions::coverageReport($tracked);

            expect($report)->toHaveCount(8);
            expect($report)->toHaveKey('signup_funnel');
            expect($report)->toHaveKey('trial_conversion_funnel');

            // Signup funnel should be fully covered
            expect($report['signup_funnel']['coverage_percent'])->toBe(100.0);
            expect($report['signup_funnel']['status'])->toBe('complete');

            // Trial conversion should be partial
            expect($report['trial_conversion_funnel']['coverage_percent'])->toBeLessThan(100.0);
        });

        it('reports zero coverage for no tracked events', function () {
            $report = SaaSFunnelDefinitions::coverageReport([]);

            foreach ($report as $funnelReport) {
                expect($funnelReport['coverage_percent'])->toBe(0.0);
                expect($funnelReport['status'])->toBe('minimal');
            }
        });

        it('reports missing events correctly', function () {
            $report = SaaSFunnelDefinitions::coverageReport([]);

            $signupMissing = $report['signup_funnel']['missing_events'];
            expect($signupMissing)->toContain('page_view');
            expect($signupMissing)->toContain('sign_up');
            expect($signupMissing)->toContain('email_verified');
            expect($signupMissing)->toContain('login');
        });
    });

    describe('forDeclarativeService()', function () {
        it('returns funnel definitions in DeclarativeFunnelService format', function () {
            $defs = SaaSFunnelDefinitions::forDeclarativeService();

            expect($defs)->toHaveKey('signup_funnel');
            expect($defs['signup_funnel'])->toHaveKey('steps');
            expect($defs['signup_funnel'])->toHaveKey('name');
            expect($defs['signup_funnel']['name'])->toBe('Signup Funnel');
            expect($defs['signup_funnel']['steps'])->toBeArray();
        });
    });

    describe('validate()', function () {
        it('passes validation with valid definitions', function () {
            $result = SaaSFunnelDefinitions::validate();

            expect($result['valid'])->toBeTrue();
            expect($result['errors'])->toBeEmpty();
        });
    });

    describe('specific funnel structures', function () {
        it('signup_funnel has correct step order', function () {
            $funnel = SaaSFunnelDefinitions::get('signup_funnel');
            $stepNames = array_map(fn (array $s): string => $s['name'], $funnel['steps']);

            expect($stepNames)->toBe(['landing', 'signup', 'verify', 'first_login']);
        });

        it('trial_conversion_funnel has 6 steps ending with payment', function () {
            $funnel = SaaSFunnelDefinitions::get('trial_conversion_funnel');

            expect(count($funnel['steps']))->toBe(6);
            $lastStep = $funnel['steps'][5];
            expect($lastStep['event_name'])->toBe('payment_succeeded');
            expect($lastStep['weight'])->toBe(2.0);
        });

        it('checkout_funnel follows standard e-commerce flow', function () {
            $funnel = SaaSFunnelDefinitions::get('checkout_funnel');
            $events = array_map(fn (array $s): string => $s['event_name'], $funnel['steps']);

            expect($events)->toEqual([
                'view_item',
                'add_to_cart',
                'view_cart',
                'begin_checkout',
                'add_payment_info',
                'purchase',
            ]);
        });

        it('cancellation_flow_funnel ends with cancellation event', function () {
            $funnel = SaaSFunnelDefinitions::get('cancellation_flow_funnel');
            $lastStep = end($funnel['steps']);

            expect($lastStep['event_name'])->toBe('cancellation');
            expect($lastStep['weight'])->toBe(3.0);
        });
    });
});
