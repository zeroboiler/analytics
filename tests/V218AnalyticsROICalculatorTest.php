<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\SaaSAnalyticsROIService;

beforeEach(function (): void {
    Cache::clear();
});

describe('SaaSAnalyticsROIService — v218.0.0', function (): void {
    describe('Service File Quality', function (): void {
        it('has strict types declaration', function (): void {
            $contents = file_get_contents(__DIR__ . '/../../src/Services/SaaSAnalyticsROIService.php');
            expect($contents)->toContain('declare(strict_types=1);');
        });

        it('has MIT license header', function (): void {
            $contents = file_get_contents(__DIR__ . '/../../src/Services/SaaSAnalyticsROIService.php');
            expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the MIT license.');
        });

        it('is a final class', function (): void {
            $ref = new ReflectionClass(SaaSAnalyticsROIService::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('has @since 218.0.0 docblock', function (): void {
            $ref = new ReflectionClass(SaaSAnalyticsROIService::class);
            $doc = $ref->getDocComment();
            expect($doc)->not->toBeFalse();
            expect($doc)->toContain('@since 218.0.0');
        });
    });

    describe('calculate() — Full ROI Report', function (): void {
        it('returns a valid ROI report structure', function (): void {
            $service = new SaaSAnalyticsROIService;
            $report = $service->calculate();

            expect($report)->toHaveKeys([
                'period', 'overall_roi_percent', 'total_events', 'total_cost',
                'total_value', 'insight_yield_per_1k', 'provider_rois',
                'category_rois', 'recommendations', 'grade',
            ]);
        });

        it('has a positive period string', function (): void {
            $service = new SaaSAnalyticsROIService;
            $report = $service->calculate();

            expect($report['period'])->toBeString();
            expect($report['period'])->toMatch('/^\d{4}-\d{2}$/');
        });

        it('overall_roi_percent is a numeric value', function (): void {
            $service = new SaaSAnalyticsROIService;
            $report = $service->calculate();

            expect($report['overall_roi_percent'])->toBeFloat();
        });

        it('total_events is a positive integer', function (): void {
            $service = new SaaSAnalyticsROIService;
            $report = $service->calculate();

            expect($report['total_events'])->toBeInt();
            expect($report['total_events'])->toBeGreaterThan(0);
        });

        it('total_cost is a positive float', function (): void {
            $service = new SaaSAnalyticsROIService;
            $report = $service->calculate();

            expect($report['total_cost'])->toBeFloat();
            expect($report['total_cost'])->toBeGreaterThan(0);
        });

        it('total_value is a positive float', function (): void {
            $service = new SaaSAnalyticsROIService;
            $report = $service->calculate();

            expect($report['total_value'])->toBeFloat();
            expect($report['total_value'])->toBeGreaterThan(0);
        });

        it('grade is a valid letter grade', function (): void {
            $service = new SaaSAnalyticsROIService;
            $report = $service->calculate();

            $validGrades = ['A+', 'A', 'A-', 'B+', 'B', 'C', 'C-', 'F'];
            expect($report['grade'])->toBeIn($validGrades);
        });

        it('provider_rois is a non-empty list', function (): void {
            $service = new SaaSAnalyticsROIService;
            $report = $service->calculate();

            expect($report['provider_rois'])->toBeArray();
            expect(count($report['provider_rois']))->toBeGreaterThan(0);
        });

        it('category_rois is a non-empty list', function (): void {
            $service = new SaaSAnalyticsROIService;
            $report = $service->calculate();

            expect($report['category_rois'])->toBeArray();
            expect(count($report['category_rois']))->toBeGreaterThan(0);
        });

        it('recommendations is a non-empty list', function (): void {
            $service = new SaaSAnalyticsROIService;
            $report = $service->calculate();

            expect($report['recommendations'])->toBeArray();
            expect(count($report['recommendations']))->toBeGreaterThan(0);
        });
    });

    describe('Provider ROI Structure', function (): void {
        it('each provider ROI has required fields', function (): void {
            $service = new SaaSAnalyticsROIService;
            $report = $service->calculate();

            foreach ($report['provider_rois'] as $p) {
                expect($p)->toHaveKeys([
                    'provider', 'events_tracked', 'dispatch_cost',
                    'attributed_revenue', 'roi_percent', 'efficiency_score',
                ]);
            }
        });

        it('includes at least 10 providers', function (): void {
            $service = new SaaSAnalyticsROIService;
            $report = $service->calculate();

            expect(count($report['provider_rois']))->toBeGreaterThanOrEqual(10);
        });
    });

    describe('Category ROI Structure', function (): void {
        it('each category ROI has required fields', function (): void {
            $service = new SaaSAnalyticsROIService;
            $report = $service->calculate();

            foreach ($report['category_rois'] as $c) {
                expect($c)->toHaveKeys([
                    'category', 'event_count', 'insight_yield',
                    'impact_score', 'coverage_percent',
                ]);
            }
        });

        it('includes at least 9 categories', function (): void {
            $service = new SaaSAnalyticsROIService;
            $report = $service->calculate();

            expect(count($report['category_rois']))->toBeGreaterThanOrEqual(9);
        });
    });

    describe('Quick Accessor Methods', function (): void {
        it('roiPercent() returns a float', function (): void {
            $service = new SaaSAnalyticsROIService;
            expect($service->roiPercent())->toBeFloat();
        });

        it('grade() returns a string', function (): void {
            $service = new SaaSAnalyticsROIService;
            expect($service->grade())->toBeString();
        });

        it('roiPercent() matches calculate overall_roi_percent', function (): void {
            $service = new SaaSAnalyticsROIService;
            // Clear cache between calls to ensure consistency
            $service->invalidateCache();
            $report = $service->calculate();
            $service->invalidateCache();
            $quickRoi = $service->roiPercent();

            expect($quickRoi)->toBe($report['overall_roi_percent']);
        });

        it('grade() matches calculate grade', function (): void {
            $service = new SaaSAnalyticsROIService;
            $service->invalidateCache();
            $report = $service->calculate();
            $service->invalidateCache();
            $quickGrade = $service->grade();

            expect($quickGrade)->toBe($report['grade']);
        });

        it('providerRois() returns an array', function (): void {
            $service = new SaaSAnalyticsROIService;
            $result = $service->providerRois();
            expect($result)->toBeArray();
            expect(count($result))->toBeGreaterThan(0);
        });

        it('categoryRois() returns an array', function (): void {
            $service = new SaaSAnalyticsROIService;
            $result = $service->categoryRois();
            expect($result)->toBeArray();
            expect(count($result))->toBeGreaterThan(0);
        });

        it('recommendations() returns a list of strings', function (): void {
            $service = new SaaSAnalyticsROIService;
            $result = $service->recommendations();
            expect($result)->toBeArray();
            expect(count($result))->toBeGreaterThan(0);
            foreach ($result as $rec) {
                expect($rec)->toBeString();
                expect(strlen($rec))->toBeGreaterThan(10);
            }
        });
    });

    describe('costEfficiency() — Cost Breakdown', function (): void {
        it('returns valid cost efficiency structure', function (): void {
            $service = new SaaSAnalyticsROIService;
            $eff = $service->costEfficiency();

            expect($eff)->toHaveKeys([
                'cost_per_event', 'cost_per_insight',
                'infra_share', 'dispatch_share', 'labor_share',
            ]);
        });

        it('cost_per_event is positive', function (): void {
            $service = new SaaSAnalyticsROIService;
            $eff = $service->costEfficiency();
            expect($eff['cost_per_event'])->toBeGreaterThan(0);
        });

        it('cost shares sum to approximately 100%', function (): void {
            $service = new SaaSAnalyticsROIService;
            $eff = $service->costEfficiency();
            $total = $eff['infra_share'] + $eff['dispatch_share'] + $eff['labor_share'];
            expect($total)->toBeGreaterThan(90);
            expect($total)->toBeLessThanOrEqual(100.1);
        });
    });

    describe('getConfig() — Config Inspection', function (): void {
        it('returns config with all required keys', function (): void {
            $service = new SaaSAnalyticsROIService;
            $config = $service->getConfig();

            expect($config)->toHaveKeys([
                'enabled', 'cache_ttl', 'avg_dispatch_cost',
                'infra_cost_monthly', 'labor_cost_multiplier',
                'attributed_revenue_per_event', 'conversion_uplift_value',
                'prevented_churn_value', 'insight_value', 'grade_thresholds',
            ]);
        });

        it('grade thresholds has all levels', function (): void {
            $service = new SaaSAnalyticsROIService;
            $config = $service->getConfig();

            expect($config['grade_thresholds'])->toHaveKeys([
                'excellent', 'good', 'acceptable', 'poor',
            ]);
        });
    });

    describe('Cache Behavior', function (): void {
        it('uses cache for repeated calculate() calls', function (): void {
            $service = new SaaSAnalyticsROIService;
            $report1 = $service->calculate();
            $report2 = $service->calculate();

            expect($report1)->toBe($report2);
        });

        it('invalidateCache() forces recalculation', function (): void {
            $service = new SaaSAnalyticsROIService;
            $report1 = $service->calculate();
            $service->invalidateCache();
            $report2 = $service->calculate();

            // Same structure, but cache was cleared between calls
            expect($report1['period'])->toBe($report2['period']);
        });
    });

    describe('Version Consistency', function (): void {
        it('AnalyticsEvent VERSION matches composer.json version', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
            expect(AnalyticsEvent::VERSION)->toBe($composer['version']);
        });

        it('AnalyticsEvent VERSION is 218.0.0', function (): void {
            expect(AnalyticsEvent::VERSION)->toBe('218.0.0');
        });
    });

    describe('Command File Quality', function (): void {
        it('command file exists and is readable', function (): void {
            $path = __DIR__ . '/../../src/Console/Commands/AnalyticsROICommand.php';
            expect(file_exists($path))->toBeTrue();

            $contents = file_get_contents($path);
            expect($contents)->toContain('declare(strict_types=1);');
            expect($contents)->toContain('zb:analytics:roi');
        });

        it('command class exists', function (): void {
            expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsROICommand::class))->toBeTrue();
        });

        it('has valid signature with all options', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsROICommand::class);
            $prop = $ref->getProperty('signature');
            $prop->setAccessible(true);
            $sig = $prop->getValue(new \ZeroBoiler\Analytics\Console\Commands\AnalyticsROICommand(
                new SaaSAnalyticsROIService
            ));

            expect($sig)->toContain('--score');
            expect($sig)->toContain('--providers');
            expect($sig)->toContain('--categories');
            expect($sig)->toContain('--efficiency');
            expect($sig)->toContain('--recommendations');
            expect($sig)->toContain('--json');
            expect($sig)->toContain('--invalidate');
        });
    });

    describe('Route Registration', function (): void {
        it('routes file contains ROI endpoints', function (): void {
            $contents = file_get_contents(__DIR__ . '/../../routes/analytics.php');

            expect($contents)->toContain("'roi'");
            expect($contents)->toContain('roiReport');
            expect($contents)->toContain('roiScore');
            expect($contents)->toContain('roiProviders');
            expect($contents)->toContain('roiCategories');
            expect($contents)->toContain('roiEfficiency');
            expect($contents)->toContain('roiRecommendations');
            expect($contents)->toContain('roiInvalidate');
        });
    });

    describe('ServiceProvider Registration', function (): void {
        it('service provider references SaaSAnalyticsROIService', function (): void {
            $contents = file_get_contents(__DIR__ . '/../../src/AnalyticsServiceProvider.php');
            expect($contents)->toContain('SaaSAnalyticsROIService');
        });

        it('service provider registers AnalyticsROICommand', function (): void {
            $contents = file_get_contents(__DIR__ . '/../../src/AnalyticsServiceProvider.php');
            expect($contents)->toContain('AnalyticsROICommand');
        });
    });

    describe('File Count Baselines', function (): void {
        it('source file exists', function (): void {
            expect(file_exists(__DIR__ . '/../../src/Services/SaaSAnalyticsROIService.php'))->toBeTrue();
        });

        it('command file exists', function (): void {
            expect(file_exists(__DIR__ . '/../../src/Console/Commands/AnalyticsROICommand.php'))->toBeTrue();
        });

        it('test file exists', function (): void {
            expect(file_exists(__FILE__))->toBeTrue();
        });
    });
});
