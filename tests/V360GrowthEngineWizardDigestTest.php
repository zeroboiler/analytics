<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\OnboardingWizardService;
use ZeroBoiler\Analytics\Services\GrowthMetricsService;
use ZeroBoiler\Analytics\Services\WeeklyDigestService;

beforeEach(function (): void {
    // Minimal config mock for services that require ConfigRepository
    $this->config = mock(Illuminate\Contracts\Config\Repository::class);
    $this->config->shouldReceive('get')->andReturnNull()->byDefault();
});

describe('OnboardingWizardService', function (): void {
    it('can be instantiated with config repository', function (): void {
        $service = new OnboardingWizardService($this->config);

        expect($service)->toBeInstanceOf(OnboardingWizardService::class);
    });

    it('returns 6 wizard steps', function (): void {
        $service = new OnboardingWizardService($this->config);

        $steps = $service->getSteps();

        expect($steps)->toHaveCount(6);
        expect($steps[0]['key'])->toBe('core_setup');
        expect($steps[1]['key'])->toBe('acquisition');
        expect($steps[2]['key'])->toBe('activation');
        expect($steps[3]['key'])->toBe('revenue');
        expect($steps[4]['key'])->toBe('retention');
        expect($steps[5]['key'])->toBe('growth');
    });

    it('each step has required fields', function (): void {
        $service = new OnboardingWizardService($this->config);

        foreach ($service->getSteps() as $step) {
            expect($step)->toHaveKey('key');
            expect($step)->toHaveKey('label');
            expect($step)->toHaveKey('description');
            expect($step)->toHaveKey('events');
            expect($step)->toHaveKey('required');
            expect($step)->toHaveKey('estimated_minutes');
            expect($step['events'])->toBeArray();
            expect($step['estimated_minutes'])->toBeInt();
        }
    });

    it('returns detailed progress with coverage data', function (): void {
        $service = new OnboardingWizardService($this->config);

        $progress = $service->getDetailedProgress();

        expect($progress)->toHaveKey('steps');
        expect($progress)->toHaveKey('total_events');
        expect($progress)->toHaveKey('covered_events');
        expect($progress)->toHaveKey('overall_coverage');
        expect($progress['total_events'])->toBeGreaterThan(0);
        expect($progress['overall_coverage'])->toBeFloat();
        expect($progress['overall_coverage'])->toBeGreaterThanOrEqual(0.0);
        expect($progress['overall_coverage'])->toBeLessThanOrEqual(1.0);
    });

    it('returns wizard state with grade', function (): void {
        $service = new OnboardingWizardService($this->config);

        $state = $service->getState();

        expect($state)->toHaveKey('grade');
        expect($state)->toHaveKey('completion_percentage');
        expect($state)->toHaveKey('total_events_instrumented');
        expect(in_array($state['grade'], ['A', 'B', 'C', 'D', 'F'], true))->toBeTrue();
    });

    it('returns config checklist with items', function (): void {
        $service = new OnboardingWizardService($this->config);

        $checklist = $service->getConfigChecklist();

        expect($checklist)->toHaveKey('items');
        expect($checklist)->toHaveKey('configured_count');
        expect($checklist)->toHaveKey('total_count');
        expect($checklist)->toHaveKey('score');
        expect($checklist['total_count'])->toBeGreaterThan(0);
        expect(count($checklist['items']))->toBe($checklist['total_count']);
    });

    it('config checklist items have required fields', function (): void {
        $service = new OnboardingWizardService($this->config);

        foreach ($service->getConfigChecklist()['items'] as $item) {
            expect($item)->toHaveKey('key');
            expect($item)->toHaveKey('label');
            expect($item)->toHaveKey('status');
            expect($item)->toHaveKey('importance');
            expect(in_array($item['status'], ['configured', 'missing'], true))->toBeTrue();
            expect(in_array($item['importance'], ['critical', 'high', 'medium', 'low'], true))->toBeTrue();
        }
    });

    it('returns readiness grade with breakdown', function (): void {
        $service = new OnboardingWizardService($this->config);

        $grade = $service->getReadinessGrade();

        expect($grade)->toHaveKey('grade');
        expect($grade)->toHaveKey('score');
        expect($grade)->toHaveKey('breakdown');
        expect($grade)->toHaveKey('next_steps');
        expect($grade['breakdown'])->toHaveKey('events');
        expect($grade['breakdown'])->toHaveKey('config');
        expect($grade['breakdown'])->toHaveKey('providers');
        expect(in_array($grade['grade'], ['A', 'B', 'C', 'D', 'F'], true))->toBeTrue();
    });

    it('returns quick-start checklist', function (): void {
        $service = new OnboardingWizardService($this->config);

        $quickStart = $service->getQuickStartChecklist();

        expect($quickStart)->toHaveKey('events');
        expect($quickStart)->toHaveKey('config');
        expect($quickStart)->toHaveKey('estimated_minutes');
        expect($quickStart['events'])->toHaveCount(5);
        expect($quickStart['config'])->toHaveCount(3);
        expect($quickStart['estimated_minutes'])->toBe(15);
    });

    it('quick-start events have provider coverage', function (): void {
        $service = new OnboardingWizardService($this->config);

        $quickStart = $service->getQuickStartChecklist();

        foreach ($quickStart['events'] as $event) {
            expect($event)->toHaveKey('name');
            expect($event)->toHaveKey('why');
            expect($event)->toHaveKey('provider_coverage');
            expect($event['provider_coverage'])->toHaveKey('ga4');
            expect($event['provider_coverage'])->toHaveKey('meta');
            expect($event['provider_coverage'])->toHaveKey('posthog');
        }
    });

    it('current step index returns valid integer', function (): void {
        $service = new OnboardingWizardService($this->config);

        $index = $service->currentStepIndex();

        expect($index)->toBeInt();
        expect($index)->toBeGreaterThanOrEqual(0);
        expect($index)->toBeLessThan(6);
    });

    it('recommendations return limited results', function (): void {
        $service = new OnboardingWizardService($this->config);

        $recommendations = $service->getRecommendations(5);

        expect($recommendations)->toHaveKey('events');
        expect($recommendations)->toHaveKey('estimated_minutes');
        expect(count($recommendations['events']))->toBeLessThanOrEqual(5);
    });
});

describe('GrowthMetricsService', function (): void {
    it('can be instantiated with config repository', function (): void {
        $service = new GrowthMetricsService($this->config);

        expect($service)->toBeInstanceOf(GrowthMetricsService::class);
    });

    it('returns activation metrics with expected keys', function (): void {
        $service = new GrowthMetricsService($this->config);

        $metrics = $service->activationMetrics();

        expect($metrics)->toHaveKey('activation_rate');
        expect($metrics)->toHaveKey('time_to_activate_hours');
        expect($metrics)->toHaveKey('aha_moment_reached');
        expect($metrics)->toHaveKey('total_signups');
        expect($metrics['activation_rate'])->toBeFloat();
        expect($metrics['total_signups'])->toBeInt();
    });

    it('returns stickiness metrics with d30_stickiness', function (): void {
        $service = new GrowthMetricsService($this->config);

        $metrics = $service->stickinessMetrics();

        expect($metrics)->toHaveKey('d30_stickiness');
        expect($metrics)->toHaveKey('feature_stickiness');
        expect($metrics)->toHaveKey('top_sticky_features');
        expect($metrics['d30_stickiness'])->toBeFloat();
    });

    it('returns engagement velocity metrics', function (): void {
        $service = new GrowthMetricsService($this->config);

        $metrics = $service->engagementVelocity();

        expect($metrics)->toHaveKey('events_per_user_per_day');
        expect($metrics)->toHaveKey('engagement_acceleration');
        expect($metrics)->toHaveKey('weekly_active_users');
        expect($metrics)->toHaveKey('monthly_active_users');
        expect($metrics['events_per_user_per_day'])->toBeFloat();
    });

    it('returns cohort health with grade', function (): void {
        $service = new GrowthMetricsService($this->config);

        $metrics = $service->cohortHealth();

        expect($metrics)->toHaveKey('d1_retention');
        expect($metrics)->toHaveKey('d7_retention');
        expect($metrics)->toHaveKey('d30_retention');
        expect($metrics)->toHaveKey('cohort_health_grade');
        expect($metrics)->toHaveKey('churn_risk_users');
        expect(in_array($metrics['cohort_health_grade'], ['A', 'B', 'C', 'D', 'F'], true))->toBeTrue();
    });

    it('returns full dashboard with grade and score', function (): void {
        $service = new GrowthMetricsService($this->config);

        $dashboard = $service->dashboard();

        expect($dashboard)->toHaveKey('activation');
        expect($dashboard)->toHaveKey('stickiness');
        expect($dashboard)->toHaveKey('velocity');
        expect($dashboard)->toHaveKey('cohort');
        expect($dashboard)->toHaveKey('overall_grade');
        expect($dashboard)->toHaveKey('growth_score');
        expect(in_array($dashboard['overall_grade'], ['A', 'B', 'C', 'D', 'F'], true))->toBeTrue();
        expect($dashboard['growth_score'])->toBeFloat();
        expect($dashboard['growth_score'])->toBeGreaterThanOrEqual(0.0);
        expect($dashboard['growth_score'])->toBeLessThanOrEqual(100.0);
    });

    it('returns CLI summary with lines', function (): void {
        $service = new GrowthMetricsService($this->config);

        $summary = $service->cliSummary();

        expect($summary)->toHaveKey('lines');
        expect($summary)->toHaveKey('grade');
        expect($summary)->toHaveKey('score');
        expect($summary['lines'])->toBeArray();
        expect(count($summary['lines']))->toBeGreaterThan(0);
    });
});

describe('WeeklyDigestService', function (): void {
    it('can be instantiated with config repository', function (): void {
        $service = new WeeklyDigestService($this->config);

        expect($service)->toBeInstanceOf(WeeklyDigestService::class);
    });

    it('generates a digest with expected structure', function (): void {
        $service = new WeeklyDigestService($this->config);

        $digest = $service->generate();

        expect($digest)->toHaveKey('period');
        expect($digest)->toHaveKey('generated_at');
        expect($digest)->toHaveKey('version');
        expect($digest)->toHaveKey('sections');
        expect($digest)->toHaveKey('summary');
        expect($digest['period'])->toBeString();
        expect($digest['version'])->toBe(AnalyticsEvent::VERSION);
        expect($digest['sections'])->toBeArray();
    });

    it('digest summary has highlights and alerts', function (): void {
        $service = new WeeklyDigestService($this->config);

        $digest = $service->generate();

        expect($digest['summary'])->toHaveKey('total_events');
        expect($digest['summary'])->toHaveKey('active_providers');
        expect($digest['summary'])->toHaveKey('overall_grade');
        expect($digest['summary'])->toHaveKey('highlights');
        expect($digest['summary'])->toHaveKey('alerts');
        expect($digest['summary']['total_events'])->toBeInt();
    });

    it('returns latest digest', function (): void {
        $service = new WeeklyDigestService($this->config);

        $latest = $service->latest();

        expect($latest)->toHaveKey('period');
        expect($latest)->toHaveKey('sections');
        expect($latest)->toHaveKey('summary');
    });

    it('returns CLI summary with grade and has_alerts flag', function (): void {
        $service = new WeeklyDigestService($this->config);

        $cliSummary = $service->cliSummary();

        expect($cliSummary)->toHaveKey('lines');
        expect($cliSummary)->toHaveKey('grade');
        expect($cliSummary)->toHaveKey('has_alerts');
        expect($cliSummary['lines'])->toBeArray();
        expect($cliSummary['grade'])->toBeString();
        expect(is_bool($cliSummary['has_alerts']))->toBeTrue();
    });

    it('current ISO week returns valid format', function (): void {
        $service = new WeeklyDigestService($this->config);

        $week = $service->currentIsoWeek();

        expect($week)->toMatch('/^\d{4}-W\d{2}$/');
    });

    it('available periods returns array', function (): void {
        $service = new WeeklyDigestService($this->config);

        $periods = $service->availablePeriods();

        expect($periods)->toBeArray();
    });

    it('digest sections have title and data', function (): void {
        $service = new WeeklyDigestService($this->config);

        $digest = $service->generate();

        foreach ($digest['sections'] as $section) {
            expect($section)->toHaveKey('title');
            expect($section)->toHaveKey('data');
            expect($section['title'])->toBeString();
            expect($section['data'])->toBeArray();
        }
    });
});

describe('Version Consistency v3.6.0', function (): void {
    it('AnalyticsEvent::VERSION is 3.6.0', function (): void {
        expect(AnalyticsEvent::VERSION)->toBe('3.6.0');
    });

    it('new services exist and are final classes', function (): void {
        $reflection = new ReflectionClass(OnboardingWizardService::class);
        expect($reflection->isFinal())->toBeTrue();

        $reflection = new ReflectionClass(GrowthMetricsService::class);
        expect($reflection->isFinal())->toBeTrue();

        $reflection = new ReflectionClass(WeeklyDigestService::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    it('new services have declare(strict_types=1)', function (): void {
        foreach (
            [
                OnboardingWizardService::class,
                GrowthMetricsService::class,
                WeeklyDigestService::class,
            ] as $class
        ) {
            $file = (new ReflectionClass($class))->getFileName();
            $contents = file_get_contents((string) $file);
            expect($contents)->toContain('declare(strict_types=1)');
        }
    });

    it('new services have public method return types', function (): void {
        $service = new OnboardingWizardService($this->config);
        $reflection = new ReflectionClass($service);

        $publicMethods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        foreach ($publicMethods as $method) {
            if ($method->getName() === '__construct') {
                continue;
            }
            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull();
        }
    });

    it('new services accept ConfigRepository in constructor', function (): void {
        $constructors = [
            OnboardingWizardService::class,
            GrowthMetricsService::class,
            WeeklyDigestService::class,
        ];

        foreach ($constructors as $class) {
            $reflection = new ReflectionClass($class);
            $constructor = $reflection->getConstructor();
            expect($constructor)->not->toBeNull();

            $params = $constructor->getParameters();
            expect($params)->toHaveCount(1);
            expect($params[0]->getName())->toBe('config');

            $type = $params[0]->getType();
            expect($type)->not->toBeNull();
            expect($type->getName())->toBe('Illuminate\Contracts\Config\Repository');
        }
    });
});
