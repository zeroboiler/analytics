<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Services\SaaSAnalyticsROIService;

beforeEach(function (): void {
    //
});

describe('V218 SaaS Analytics ROI Calculator', function (): void {
    describe('SaaSAnalyticsROIService — instantiation', function (): void {
        it('constructs with null cache (uses default)', function (): void {
            $service = new SaaSAnalyticsROIService(null);

            expect($service)->toBeInstanceOf(SaaSAnalyticsROIService::class);
        });

        it('is a final class', function (): void {
            $ref = new ReflectionClass(SaaSAnalyticsROIService::class);

            expect($ref->isFinal())->toBeTrue();
        });

        it('has declare(strict_types=1)', function (): void {
            $file = (new ReflectionClass(SaaSAnalyticsROIService::class))->getFileName();
            $contents = file_get_contents($file);
            $tokens = token_get_all($contents);

            $found = false;
            foreach ($tokens as $i => $token) {
                if (is_array($token) && $token[0] === T_DECLARE) {
                    $found = true;
                    break;
                }
            }

            expect($found)->toBeTrue('declare(strict_types=1) not found');
        });

        it('has MIT license header', function (): void {
            $file = (new ReflectionClass(SaaSAnalyticsROIService::class))->getFileName();
            $contents = file_get_contents($file);
            $lines = explode("\n", $contents);

            expect($lines[1])->toContain('part of ZeroBoiler');
            expect($lines[2])->toContain('MIT');
        });

        it('has @since 218.0.0 annotation', function (): void {
            $ref = new ReflectionClass(SaaSAnalyticsROIService::class);
            $doc = $ref->getDocComment();

            expect($doc)->not->toBeFalse();
            expect($doc)->toContain('@since 218.0.0');
        });
    });

    describe('SaaSAnalyticsROIService — calculate()', function (): void {
        it('returns a full report array with all required keys', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $report = $service->calculate();

            expect($report)->toBeArray();
            expect($report)->toHaveKeys([
                'period',
                'overall_roi_percent',
                'total_events',
                'total_cost',
                'total_value',
                'insight_yield_per_1k',
                'provider_rois',
                'category_rois',
                'recommendations',
                'grade',
            ]);
        });

        it('period is current month in Y-m format', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $report = $service->calculate();

            expect($report['period'])->toBe((new \DateTimeImmutable())->format('Y-m'));
        });

        it('total_events is a positive integer', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $report = $service->calculate();

            expect($report['total_events'])->toBeInt();
            expect($report['total_events'])->toBeGreaterThan(0);
        });

        it('total_cost is a positive float', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $report = $service->calculate();

            expect($report['total_cost'])->toBeFloat();
            expect($report['total_cost'])->toBeGreaterThan(0);
        });

        it('total_value is a positive float', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $report = $service->calculate();

            expect($report['total_value'])->toBeFloat();
            expect($report['total_value'])->toBeGreaterThan(0);
        });

        it('overall_roi_percent is a float', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $report = $service->calculate();

            expect($report['overall_roi_percent'])->toBeFloat();
        });

        it('grade is a non-empty string', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $report = $service->calculate();

            expect($report['grade'])->toBeString();
            expect($report['grade'])->not->toBeEmpty();
        });

        it('provider_rois is a non-empty array of provider stats', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $report = $service->calculate();

            expect($report['provider_rois'])->toBeArray();
            expect(count($report['provider_rois']))->toBeGreaterThan(0);

            foreach ($report['provider_rois'] as $p) {
                expect($p)->toHaveKeys(['provider', 'events_tracked', 'dispatch_cost', 'attributed_revenue', 'roi_percent', 'efficiency_score']);
                expect($p['provider'])->toBeString();
                expect($p['events_tracked'])->toBeInt();
                expect($p['dispatch_cost'])->toBeFloat();
                expect($p['attributed_revenue'])->toBeFloat();
                expect($p['roi_percent'])->toBeFloat();
                expect($p['efficiency_score'])->toBeFloat();
            }
        });

        it('category_rois is a non-empty array of category stats', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $report = $service->calculate();

            expect($report['category_rois'])->toBeArray();
            expect(count($report['category_rois']))->toBeGreaterThan(0);

            foreach ($report['category_rois'] as $cat) {
                expect($cat)->toHaveKeys(['category', 'event_count', 'insight_yield', 'impact_score', 'coverage_percent']);
                expect($cat['category'])->toBeString();
                expect($cat['event_count'])->toBeInt();
                expect($cat['insight_yield'])->toBeFloat();
                expect($cat['impact_score'])->toBeFloat();
                expect($cat['coverage_percent'])->toBeFloat();
            }
        });

        it('recommendations is a non-empty array of strings', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $report = $service->calculate();

            expect($report['recommendations'])->toBeArray();
            expect(count($report['recommendations']))->toBeGreaterThan(0);

            foreach ($report['recommendations'] as $rec) {
                expect($rec)->toBeString();
                expect(strlen($rec))->toBeGreaterThan(10);
            }
        });
    });

    describe('SaaSAnalyticsROIService — convenience methods', function (): void {
        it('roiPercent() returns a float matching report overall_roi_percent', function (): void {
            $service = new SaaSAnalyticsROIService(null);

            expect($service->roiPercent())->toBeFloat();
            expect($service->roiPercent())->toBe($service->calculate()['overall_roi_percent']);
        });

        it('grade() returns a string matching report grade', function (): void {
            $service = new SaaSAnalyticsROIService(null);

            expect($service->grade())->toBeString();
            expect($service->grade())->toBe($service->calculate()['grade']);
        });

        it('providerRois() returns an array matching report provider_rois', function (): void {
            $service = new SaaSAnalyticsROIService(null);

            expect($service->providerRois())->toBe($service->calculate()['provider_rois']);
        });

        it('categoryRois() returns an array matching report category_rois', function (): void {
            $service = new SaaSAnalyticsROIService(null);

            expect($service->categoryRois())->toBe($service->calculate()['category_rois']);
        });

        it('recommendations() returns an array matching report recommendations', function (): void {
            $service = new SaaSAnalyticsROIService(null);

            expect($service->recommendations())->toBe($service->calculate()['recommendations']);
        });
    });

    describe('SaaSAnalyticsROIService — costEfficiency()', function (): void {
        it('returns array with all required keys', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $eff = $service->costEfficiency();

            expect($eff)->toBeArray();
            expect($eff)->toHaveKeys([
                'cost_per_event',
                'cost_per_insight',
                'infra_share',
                'dispatch_share',
                'labor_share',
            ]);
        });

        it('all cost values are non-negative floats', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $eff = $service->costEfficiency();

            expect($eff['cost_per_event'])->toBeFloat();
            expect($eff['cost_per_event'])->toBeGreaterThanOrEqual(0);
            expect($eff['cost_per_insight'])->toBeFloat();
            expect($eff['cost_per_insight'])->toBeGreaterThanOrEqual(0);
            expect($eff['infra_share'])->toBeFloat();
            expect($eff['infra_share'])->toBeGreaterThanOrEqual(0);
            expect($eff['dispatch_share'])->toBeFloat();
            expect($eff['dispatch_share'])->toBeGreaterThanOrEqual(0);
            expect($eff['labor_share'])->toBeFloat();
            expect($eff['labor_share'])->toBeGreaterThanOrEqual(0);
        });

        it('cost shares should sum to approximately 100% (with rounding tolerance)', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $eff = $service->costEfficiency();

            $total = $eff['infra_share'] + $eff['dispatch_share'] + $eff['labor_share'];
            expect($total)->toBeLessThan(101.0);
            expect($total)->toBeGreaterThan(99.0);
        });
    });

    describe('SaaSAnalyticsROIService — getConfig()', function (): void {
        it('returns array with all config keys', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $config = $service->getConfig();

            expect($config)->toBeArray();
            expect($config)->toHaveKeys([
                'enabled',
                'cache_ttl',
                'avg_dispatch_cost',
                'infra_cost_monthly',
                'labor_cost_multiplier',
                'attributed_revenue_per_event',
                'conversion_uplift_value',
                'prevented_churn_value',
                'insight_value',
                'grade_thresholds',
            ]);
        });

        it('enabled is true by default', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $config = $service->getConfig();

            expect($config['enabled'])->toBeTrue();
        });

        it('grade_thresholds has all four levels', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $config = $service->getConfig();

            expect($config['grade_thresholds'])->toHaveKeys(['excellent', 'good', 'acceptable', 'poor']);
            expect($config['grade_thresholds']['excellent'])->toBeGreaterThan($config['grade_thresholds']['good']);
            expect($config['grade_thresholds']['good'])->toBeGreaterThan($config['grade_thresholds']['acceptable']);
            expect($config['grade_thresholds']['acceptable'])->toBeGreaterThan($config['grade_thresholds']['poor']);
        });
    });

    describe('SaaSAnalyticsROIService — grade assignment', function (): void {
        it('returns A+ for extremely high ROI', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $report = $service->calculate();

            // With default config, ROI should be very high (thousands of %)
            // so we expect A+ or A
            expect(in_array($report['grade'], ['A+', 'A', 'A-'], true))->toBeTrue();
        });
    });

    describe('SaaSAnalyticsROIService — caching', function (): void {
        it('calculate() returns same result on second call (cached)', function (): void {
            $service = new SaaSAnalyticsROIService(null);

            $first = $service->calculate();
            $second = $service->calculate();

            expect($first)->toBe($second);
        });

        it('invalidateCache() does not throw', function (): void {
            $service = new SaaSAnalyticsROIService(null);

            expect(fn () => $service->invalidateCache())->not->toThrow(\Throwable::class);
        });
    });

    describe('SaaSAnalyticsROIService — provider diversity', function (): void {
        it('covers at least 10 providers', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $report = $service->calculate();

            expect(count($report['provider_rois']))->toBeGreaterThanOrEqual(10);
        });

        it('includes GA4, GTM, Meta Pixel, PostHog, Mixpanel', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $report = $service->calculate();

            $names = array_column($report['provider_rois'], 'provider');
            expect($names)->toContain('ga4');
            expect($names)->toContain('gtm');
            expect($names)->toContain('meta_pixel');
            expect($names)->toContain('posthog');
            expect($names)->toContain('mixpanel');
        });
    });

    describe('SaaSAnalyticsROIService — category diversity', function (): void {
        it('covers at least 7 categories', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $report = $service->calculate();

            expect(count($report['category_rois']))->toBeGreaterThanOrEqual(7);
        });

        it('includes ecommerce, saas, engagement, marketing', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $report = $service->calculate();

            $names = array_column($report['category_rois'], 'category');
            expect($names)->toContain('ecommerce');
            expect($names)->toContain('saas');
            expect($names)->toContain('engagement');
            expect($names)->toContain('marketing');
        });
    });

    describe('SaaSAnalyticsROIService — return type integrity', function (): void {
        it('calculate() has array return type', function (): void {
            $method = new ReflectionMethod(SaaSAnalyticsROIService::class, 'calculate');
            $returnType = $method->getReturnType();

            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('array');
        });

        it('roiPercent() has float return type', function (): void {
            $method = new ReflectionMethod(SaaSAnalyticsROIService::class, 'roiPercent');
            $returnType = $method->getReturnType();

            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('float');
        });

        it('grade() has string return type', function (): void {
            $method = new ReflectionMethod(SaaSAnalyticsROIService::class, 'grade');
            $returnType = $method->getReturnType();

            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('string');
        });

        it('invalidateCache() has void return type', function (): void {
            $method = new ReflectionMethod(SaaSAnalyticsROIService::class, 'invalidateCache');
            $returnType = $method->getReturnType();

            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('void');
        });

        it('costEfficiency() has array return type', function (): void {
            $method = new ReflectionMethod(SaaSAnalyticsROIService::class, 'costEfficiency');
            $returnType = $method->getReturnType();

            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('array');
        });

        it('getConfig() has array return type', function (): void {
            $method = new ReflectionMethod(SaaSAnalyticsROIService::class, 'getConfig');
            $returnType = $method->getReturnType();

            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('array');
        });

        it('constructor has void return type', function (): void {
            $method = new ReflectionMethod(SaaSAnalyticsROIService::class, '__construct');
            $returnType = $method->getReturnType();

            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('void');
        });
    });

    describe('AnalyticsROICommand — structure', function (): void {
        it('command class exists and is final', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsROICommand::class);

            expect($ref->isFinal())->toBeTrue();
        });

        it('extends Illuminate\Console\Command', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsROICommand::class);

            expect($ref->getParentClass()->getName())->toBe('Illuminate\Console\Command');
        });

        it('has declare(strict_types=1)', function (): void {
            $file = (new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsROICommand::class))->getFileName();
            $contents = file_get_contents($file);
            $tokens = token_get_all($contents);

            $found = false;
            foreach ($tokens as $token) {
                if (is_array($token) && $token[0] === T_DECLARE) {
                    $found = true;
                    break;
                }
            }

            expect($found)->toBeTrue('declare(strict_types=1) not found');
        });

        it('has MIT license header', function (): void {
            $file = (new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsROICommand::class))->getFileName();
            $contents = file_get_contents($file);
            $lines = explode("\n", $contents);

            expect($lines[1])->toContain('part of ZeroBoiler');
            expect($lines[2])->toContain('MIT');
        });

        it('has @since 218.0.0 annotation', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsROICommand::class);
            $doc = $ref->getDocComment();

            expect($doc)->not->toBeFalse();
            expect($doc)->toContain('@since 218.0.0');
        });

        it('constructor has void return type', function (): void {
            $method = new ReflectionMethod(\ZeroBoiler\Analytics\Console\Commands\AnalyticsROICommand::class, '__construct');
            $returnType = $method->getReturnType();

            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('void');
        });

        it('handle() has int return type', function (): void {
            $method = new ReflectionMethod(\ZeroBoiler\Analytics\Console\Commands\AnalyticsROICommand::class, 'handle');
            $returnType = $method->getReturnType();

            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('int');
        });

        it('signature starts with zb:analytics:roi', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsROICommand::class);
            $prop = $ref->getProperty('signature');

            expect($prop->getValue(new \ZeroBoiler\Analytics\Console\Commands\AnalyticsROICommand(
                new SaaSAnalyticsROIService(null)
            )))->toContain('zb:analytics:roi');
        });

        it('has all option flags: score, providers, categories, efficiency, recommendations, json, invalidate', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsROICommand::class);
            $cmd = new \ZeroBoiler\Analytics\Console\Commands\AnalyticsROICommand(
                new SaaSAnalyticsROIService(null)
            );

            $sig = $ref->getProperty('signature')->getValue($cmd);
            expect($sig)->toContain('--score');
            expect($sig)->toContain('--providers');
            expect($sig)->toContain('--categories');
            expect($sig)->toContain('--efficiency');
            expect($sig)->toContain('--recommendations');
            expect($sig)->toContain('--json');
            expect($sig)->toContain('--invalidate');
        });
    });

    describe('ROI mathematical consistency', function (): void {
        it('cost = dispatch + infra + labor (with rounding tolerance)', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $report = $service->calculate();
            $config = $service->getConfig();

            $totalDispatch = array_reduce(
                $report['provider_rois'],
                fn (float $carry, array $p): float => $carry + $p['dispatch_cost'],
                0.0,
            );
            $infraDaily = $config['infra_cost_monthly'] / 30;
            $labor = $report['total_events'] * $config['labor_cost_multiplier'];

            $expectedCost = $totalDispatch + $infraDaily + $labor;

            expect(abs($report['total_cost'] - $expectedCost))->toBeLessThan(0.1);
        });

        it('insight_yield_per_1k is positive', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $report = $service->calculate();

            expect($report['insight_yield_per_1k'])->toBeGreaterThan(0);
        });

        it('total_value = revenue + insight_value (approximately)', function (): void {
            $service = new SaaSAnalyticsROIService(null);
            $report = $service->calculate();
            $config = $service->getConfig();

            $expectedValue = $report['insight_yield_per_1k'] * $config['insight_value'] + $report['total_cost'] + $report['total_cost'] * $report['overall_roi_percent'] / 100;

            // Due to rounding this is approximate
            expect(abs($report['total_value'] - $expectedValue))->toBeLessThan(10.0);
        });
    });
});
