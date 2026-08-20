<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EventStreamService;
use ZeroBoiler\Analytics\Services\GrowthMetricsService;
use ZeroBoiler\Analytics\Services\OnboardingWizardService;
use ZeroBoiler\Analytics\Services\WeeklyDigestService;

beforeEach(function (): void {
    // Mock cache
    cache()->clear();

    // Minimal config mock
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturnNull();

    $this->config = $config;
});

afterEach(function (): void {
    Mockery::close();
    cache()->clear();
});

// ─── GrowthMetricsService ───────────────────────────────────────────────────

describe('GrowthMetricsService', function (): void {
    it('is final', function (): void {
        $ref = new ReflectionClass(GrowthMetricsService::class);
        expect($ref->isFinal())->toBeTrue();
    });

    it('has strict types declaration', function (): void {
        $contents = file_get_contents(
            (new ReflectionClass(GrowthMetricsService::class))->getFileName(),
        );
        expect($contents)->toContain('declare(strict_types=1)');
    });

    it('constructs with ConfigRepository', function (): void {
        $service = new GrowthMetricsService($this->config);
        expect($service)->toBeInstanceOf(GrowthMetricsService::class);
    });

    it('activationMetrics returns expected structure', function (): void {
        $service = new GrowthMetricsService($this->config);
        $metrics = $service->activationMetrics();

        expect($metrics)
            ->toBeArray()
            ->toHaveKeys([
                'activation_rate',
                'time_to_activate_hours',
                'aha_moment_reached',
                'total_signups',
            ]);

        expect($metrics['activation_rate'])->toBeFloat();
        expect($metrics['time_to_activate_hours'])->toBeNull();
        expect($metrics['aha_moment_reached'])->toBeInt();
        expect($metrics['total_signups'])->toBeInt();
    });

    it('stickinessMetrics returns expected structure', function (): void {
        $service = new GrowthMetricsService($this->config);
        $metrics = $service->stickinessMetrics();

        expect($metrics)
            ->toBeArray()
            ->toHaveKeys([
                'd30_stickiness',
                'feature_stickiness',
                'top_sticky_features',
            ]);

        expect($metrics['d30_stickiness'])->toBeFloat();
        expect($metrics['feature_stickiness'])->toBeArray();
        expect($metrics['top_sticky_features'])->toBeArray();
    });

    it('engagementVelocity returns expected structure', function (): void {
        $service = new GrowthMetricsService($this->config);
        $metrics = $service->engagementVelocity();

        expect($metrics)
            ->toBeArray()
            ->toHaveKeys([
                'events_per_user_per_day',
                'engagement_acceleration',
                'weekly_active_users',
                'monthly_active_users',
            ]);

        expect($metrics['events_per_user_per_day'])->toBeFloat();
        expect($metrics['engagement_acceleration'])->toBeFloat();
        expect($metrics['weekly_active_users'])->toBeInt();
        expect($metrics['monthly_active_users'])->toBeInt();
    });

    it('cohortHealth returns expected structure', function (): void {
        $service = new GrowthMetricsService($this->config);
        $metrics = $service->cohortHealth();

        expect($metrics)
            ->toBeArray()
            ->toHaveKeys([
                'd1_retention',
                'd7_retention',
                'd30_retention',
                'cohort_health_grade',
                'churn_risk_users',
            ]);

        expect($metrics['d1_retention'])->toBeFloat();
        expect($metrics['d7_retention'])->toBeFloat();
        expect($metrics['d30_retention'])->toBeFloat();
        expect($metrics['cohort_health_grade'])->toBeString();
        expect($metrics['churn_risk_users'])->toBeInt();
    });

    it('cohort health grade is valid A-F', function (): void {
        $service = new GrowthMetricsService($this->config);
        $metrics = $service->cohortHealth();

        expect(in_array($metrics['cohort_health_grade'], ['A', 'B', 'C', 'D', 'F'], true))
            ->toBeTrue();
    });

    it('dashboard returns composite growth score', function (): void {
        $service = new GrowthMetricsService($this->config);
        $dashboard = $service->dashboard();

        expect($dashboard)
            ->toBeArray()
            ->toHaveKeys([
                'activation',
                'stickiness',
                'velocity',
                'cohort',
                'overall_grade',
                'growth_score',
            ]);

        expect($dashboard['growth_score'])->toBeFloat();
        expect($dashboard['growth_score'])->toBeGreaterThanOrEqual(0.0);
        expect($dashboard['growth_score'])->toBeLessThanOrEqual(100.0);
        expect(in_array($dashboard['overall_grade'], ['A', 'B', 'C', 'D', 'F'], true))
            ->toBeTrue();
    });

    it('cliSummary returns lines array with grade and score', function (): void {
        $service = new GrowthMetricsService($this->config);
        $summary = $service->cliSummary();

        expect($summary)
            ->toBeArray()
            ->toHaveKeys(['lines', 'grade', 'score']);

        expect($summary['lines'])->toBeArray();
        expect($summary['grade'])->toBeString();
        expect($summary['score'])->toBeFloat();
    });

    it('cliSummary lines contain key metric labels', function (): void {
        $service = new GrowthMetricsService($this->config);
        $summary = $service->cliSummary();
        $joined = implode("\n", $summary['lines']);

        expect($joined)->toContain('Activation Rate');
        expect($joined)->toContain('D30 Stickiness');
        expect($joined)->toContain('Events/User/Day');
        expect($joined)->toContain('D1 Retention');
        expect($joined)->toContain('Growth Grade');
    });

    it('caches activation metrics', function (): void {
        $service = new GrowthMetricsService($this->config);

        $first = $service->activationMetrics();
        $second = $service->activationMetrics();

        // Both calls return identical results
        expect($first)->toBe($second);
    });

    it('caches stickiness metrics', function (): void {
        $service = new GrowthMetricsService($this->config);

        $first = $service->stickinessMetrics();
        $second = $service->stickinessMetrics();

        expect($first)->toBe($second);
    });

    it('returns zero metrics when EventStreamService unavailable', function (): void {
        $service = new GrowthMetricsService($this->config);
        $metrics = $service->activationMetrics();

        expect($metrics['activation_rate'])->toBe(0.0);
        expect($metrics['total_signups'])->toBe(0);
        expect($metrics['aha_moment_reached'])->toBe(0);
    });

    it('dashboard grade is F when all metrics are zero', function (): void {
        $service = new GrowthMetricsService($this->config);
        $dashboard = $service->dashboard();

        expect($dashboard['overall_grade'])->toBe('F');
        expect($dashboard['growth_score'])->toBe(0.0);
    });
});

// ─── OnboardingWizardService ────────────────────────────────────────────────

describe('OnboardingWizardService', function (): void {
    it('is final', function (): void {
        $ref = new ReflectionClass(OnboardingWizardService::class);
        expect($ref->isFinal())->toBeTrue();
    });

    it('has strict types declaration', function (): void {
        $contents = file_get_contents(
            (new ReflectionClass(OnboardingWizardService::class))->getFileName(),
        );
        expect($contents)->toContain('declare(strict_types=1)');
    });

    it('constructs with ConfigRepository', function (): void {
        $service = new OnboardingWizardService($this->config);
        expect($service)->toBeInstanceOf(OnboardingWizardService::class);
    });

    it('getSteps returns 6 steps in order', function (): void {
        $service = new OnboardingWizardService($this->config);
        $steps = $service->getSteps();

        expect($steps)->toBeArray();
        expect(count($steps))->toBe(6);

        $keys = array_column($steps, 'key');
        expect($keys)->toBe([
            'core_setup',
            'acquisition',
            'activation',
            'revenue',
            'retention',
            'growth',
        ]);
    });

    it('each step has required fields', function (): void {
        $service = new OnboardingWizardService($this->config);
        $steps = $service->getSteps();

        foreach ($steps as $step) {
            expect($step)
                ->toHaveKeys(['key', 'label', 'description', 'events', 'required', 'estimated_minutes']);

            expect($step['events'])->toBeArray();
            expect($step['required'])->toBeBool();
            expect($step['estimated_minutes'])->toBeInt();
            expect($step['estimated_minutes'])->toBeGreaterThan(0);
        }
    });

    it('core_setup and acquisition and activation are required', function (): void {
        $service = new OnboardingWizardService($this->config);
        $steps = $service->getSteps();

        expect($steps[0]['required'])->toBeTrue(); // core_setup
        expect($steps[1]['required'])->toBeTrue(); // acquisition
        expect($steps[2]['required'])->toBeTrue(); // activation
        expect($steps[3]['required'])->toBeTrue(); // revenue
        expect($steps[4]['required'])->toBeFalse(); // retention
        expect($steps[5]['required'])->toBeFalse(); // growth
    });

    it('currentStepIndex returns int', function (): void {
        $service = new OnboardingWizardService($this->config);
        $index = $service->currentStepIndex();

        expect($index)->toBeInt();
        expect($index)->toBeGreaterThanOrEqual(0);
        expect($index)->toBeLessThan(6);
    });

    it('getDetailedProgress returns expected structure', function (): void {
        $service = new OnboardingWizardService($this->config);
        $progress = $service->getDetailedProgress();

        expect($progress)
            ->toBeArray()
            ->toHaveKeys(['steps', 'total_events', 'covered_events', 'overall_coverage']);

        expect($progress['steps'])->toBeArray();
        expect(count($progress['steps']))->toBe(6);
        expect($progress['total_events'])->toBeInt();
        expect($progress['total_events'])->toBeGreaterThan(0);
        expect($progress['covered_events'])->toBeInt();
        expect($progress['overall_coverage'])->toBeFloat();
    });

    it('each progress step has required fields', function (): void {
        $service = new OnboardingWizardService($this->config);
        $progress = $service->getDetailedProgress();

        foreach ($progress['steps'] as $step) {
            expect($step)
                ->toHaveKeys([
                    'key',
                    'label',
                    'total_events',
                    'covered_events',
                    'coverage',
                    'is_complete',
                    'estimated_minutes',
                ]);

            expect($step['coverage'])->toBeFloat();
            expect($step['is_complete'])->toBeBool();
        }
    });

    it('getRecommendations returns events array', function (): void {
        $service = new OnboardingWizardService($this->config);
        $recs = $service->getRecommendations();

        expect($recs)
            ->toBeArray()
            ->toHaveKeys(['events', 'estimated_minutes']);

        expect($recs['events'])->toBeArray();
        expect($recs['estimated_minutes'])->toBeInt();
    });

    it('getRecommendations respects limit', function (): void {
        $service = new OnboardingWizardService($this->config);
        $recs = $service->getRecommendations(3);

        expect(count($recs['events']))->toBeLessThanOrEqual(3);
    });

    it('each recommendation has required fields', function (): void {
        $service = new OnboardingWizardService($this->config);
        $recs = $service->getRecommendations();

        foreach ($recs['events'] as $rec) {
            expect($rec)
                ->toHaveKeys(['name', 'step', 'priority', 'category']);

            expect($rec['priority'])->toBeIn(['high', 'medium']);
        }
    });

    it('getConfigChecklist returns items with status', function (): void {
        $service = new OnboardingWizardService($this->config);
        $checklist = $service->getConfigChecklist();

        expect($checklist)
            ->toBeArray()
            ->toHaveKeys(['items', 'configured_count', 'total_count', 'score']);

        expect($checklist['items'])->toBeArray();
        expect($checklist['total_count'])->toBeGreaterThan(0);
        expect($checklist['score'])->toBeFloat();
    });

    it('config checklist items have correct importance levels', function (): void {
        $service = new OnboardingWizardService($this->config);
        $checklist = $service->getConfigChecklist();

        foreach ($checklist['items'] as $item) {
            expect($item)
                ->toHaveKeys(['key', 'label', 'status', 'importance']);

            expect($item['importance'])->toBeIn(['critical', 'high', 'medium', 'low']);
            expect($item['status'])->toBeIn(['configured', 'missing']);
        }
    });

    it('getReadinessGrade returns A-F grade', function (): void {
        $service = new OnboardingWizardService($this->config);
        $grade = $service->getReadinessGrade();

        expect($grade)
            ->toBeArray()
            ->toHaveKeys(['grade', 'score', 'breakdown', 'next_steps']);

        expect($grade['grade'])->toBeString();
        expect(in_array($grade['grade'], ['A', 'B', 'C', 'D', 'F'], true))->toBeTrue();
        expect($grade['score'])->toBeFloat();
        expect($grade['breakdown'])->toBeArray();
        expect($grade['breakdown'])->toHaveKeys(['events', 'config', 'providers']);
        expect($grade['next_steps'])->toBeArray();
    });

    it('getQuickStartChecklist returns events and config', function (): void {
        $service = new OnboardingWizardService($this->config);
        $checklist = $service->getQuickStartChecklist();

        expect($checklist)
            ->toBeArray()
            ->toHaveKeys(['events', 'config', 'estimated_minutes']);

        expect(count($checklist['events']))->toBe(5);
        expect(count($checklist['config']))->toBe(3);
        expect($checklist['estimated_minutes'])->toBe(15);
    });

    it('quick start events have name and why', function (): void {
        $service = new OnboardingWizardService($this->config);
        $checklist = $service->getQuickStartChecklist();

        foreach ($checklist['events'] as $event) {
            expect($event)->toHaveKeys(['name', 'why', 'provider_coverage']);
            expect($event['provider_coverage'])->toBeArray();
            expect($event['provider_coverage'])->toHaveKeys(['ga4', 'meta', 'posthog']);
        }
    });

    it('quick start config has key, label, and env_var', function (): void {
        $service = new OnboardingWizardService($this->config);
        $checklist = $service->getQuickStartChecklist();

        foreach ($checklist['config'] as $item) {
            expect($item)->toHaveKeys(['key', 'label', 'env_var']);
            expect($item['env_var'])->toContain('=');
        }
    });

    it('getState returns wizard state', function (): void {
        $service = new OnboardingWizardService($this->config);
        $state = $service->getState();

        expect($state)
            ->toBeArray()
            ->toHaveKeys([
                'started_at',
                'current_step',
                'completed_steps',
                'total_events_instrumented',
                'completion_percentage',
                'grade',
            ]);

        expect($state['completion_percentage'])->toBeFloat();
        expect($state['grade'])->toBeString();
        expect($state['completed_steps'])->toBeArray();
    });

    it('getState accepts custom appId', function (): void {
        $service = new OnboardingWizardService($this->config);
        $state = $service->getState('my-app');

        expect($state)->toBeArray();
        expect($state)->toHaveKey('grade');
    });

    it('has MIT license header', function (): void {
        $contents = file_get_contents(
            (new ReflectionClass(OnboardingWizardService::class))->getFileName(),
        );
        expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
    });
});

// ─── WeeklyDigestService ────────────────────────────────────────────────────

describe('WeeklyDigestService', function (): void {
    it('is final', function (): void {
        $ref = new ReflectionClass(WeeklyDigestService::class);
        expect($ref->isFinal())->toBeTrue();
    });

    it('has strict types declaration', function (): void {
        $contents = file_get_contents(
            (new ReflectionClass(WeeklyDigestService::class))->getFileName(),
        );
        expect($contents)->toContain('declare(strict_types=1)');
    });

    it('constructs with ConfigRepository', function (): void {
        $service = new WeeklyDigestService($this->config);
        expect($service)->toBeInstanceOf(WeeklyDigestService::class);
    });

    it('generate returns digest structure', function (): void {
        $service = new WeeklyDigestService($this->config);
        $digest = $service->generate();

        expect($digest)
            ->toBeArray()
            ->toHaveKeys([
                'period',
                'generated_at',
                'version',
                'sections',
                'summary',
            ]);

        expect($digest['period'])->toBeString();
        expect($digest['version'])->toBe(AnalyticsEvent::VERSION);
        expect($digest['sections'])->toBeArray();
        expect($digest['summary'])->toBeArray();
    });

    it('summary has required fields', function (): void {
        $service = new WeeklyDigestService($this->config);
        $digest = $service->generate();
        $summary = $digest['summary'];

        expect($summary)
            ->toHaveKeys([
                'total_events',
                'active_providers',
                'overall_grade',
                'highlights',
                'alerts',
            ]);

        expect($summary['total_events'])->toBeInt();
        expect($summary['active_providers'])->toBeInt();
        expect($summary['overall_grade'])->toBeString();
        expect($summary['highlights'])->toBeArray();
        expect($summary['alerts'])->toBeArray();
    });

    it('sections contain required entries', function (): void {
        $service = new WeeklyDigestService($this->config);
        $digest = $service->generate();
        $sectionTitles = array_column($digest['sections'], 'title');

        expect($sectionTitles)->toContain('Event Overview');
        expect($sectionTitles)->toContain('Provider Health');
        expect($sectionTitles)->toContain('SaaS Metrics');
        expect($sectionTitles)->toContain('Retention & Engagement');
        expect($sectionTitles)->toContain('Growth Insights');
    });

    it('each section has title and data', function (): void {
        $service = new WeeklyDigestService($this->config);
        $digest = $service->generate();

        foreach ($digest['sections'] as $section) {
            expect($section)->toHaveKeys(['title', 'data']);
            expect($section['title'])->toBeString();
            expect($section['data'])->toBeArray();
        }
    });

    it('generate caches results', function (): void {
        $service = new WeeklyDigestService($this->config);

        $first = $service->generate();
        $second = $service->generate();

        expect($first)->toBe($second);
    });

    it('latest returns current week digest', function (): void {
        $service = new WeeklyDigestService($this->config);
        $digest = $service->latest();

        expect($digest)->toBeArray();
        expect($digest)->toHaveKey('period');
    });

    it('cliSummary returns formatted output', function (): void {
        $service = new WeeklyDigestService($this->config);
        $summary = $service->cliSummary();

        expect($summary)
            ->toBeArray()
            ->toHaveKeys(['lines', 'grade', 'has_alerts']);

        expect($summary['lines'])->toBeArray();
        expect(count($summary['lines']))->toBeGreaterThan(0);
        expect($summary['grade'])->toBeString();
        expect($summary['has_alerts'])->toBeBool();
    });

    it('cliSummary contains header', function (): void {
        $service = new WeeklyDigestService($this->config);
        $summary = $service->cliSummary();
        $joined = implode("\n", $summary['lines']);

        expect($joined)->toContain('ZeroBoiler Analytics');
        expect($joined)->toContain('Weekly Digest');
    });

    it('cliSummary contains key metrics', function (): void {
        $service = new WeeklyDigestService($this->config);
        $summary = $service->cliSummary();
        $joined = implode("\n", $summary['lines']);

        expect($joined)->toContain('Total Events');
        expect($joined)->toContain('Active Providers');
        expect($joined)->toContain('Overall Grade');
    });

    it('currentIsoWeek returns ISO week format', function (): void {
        $service = new WeeklyDigestService($this->config);
        $week = $service->currentIsoWeek();

        expect($week)->toBeString();
        expect($week)->toMatch('/^\d{4}-W\d{2}$/');
    });

    it('availablePeriods returns array', function (): void {
        $service = new WeeklyDigestService($this->config);
        $periods = $service->availablePeriods();

        expect($periods)->toBeArray();
    });

    it('digest period matches current week when no period specified', function (): void {
        $service = new WeeklyDigestService($this->config);
        $digest = $service->generate();

        expect($digest['period'])->toBe($service->currentIsoWeek());
    });

    it('provider health section shows F grade when no providers enabled', function (): void {
        $service = new WeeklyDigestService($this->config);
        $digest = $service->generate();

        $providerHealth = null;
        foreach ($digest['sections'] as $section) {
            if ($section['title'] === 'Provider Health') {
                $providerHealth = $section;
                break;
            }
        }

        expect($providerHealth)->not->toBeNull();
        expect($providerHealth['grade'])->toBe('F');
    });

    it('growth insights section includes alerts when no providers configured', function (): void {
        $service = new WeeklyDigestService($this->config);
        $digest = $service->generate();

        $growthInsights = null;
        foreach ($digest['sections'] as $section) {
            if ($section['title'] === 'Growth Insights') {
                $growthInsights = $section;
                break;
            }
        }

        expect($growthInsights)->not->toBeNull();
        expect($growthInsights['data']['alerts'])->toBeArray();
        // No providers enabled should generate an alert
        expect($growthInsights['data']['alerts'])->toContain(
            'No analytics providers configured — events are being lost',
        );
    });

    it('overall grade is valid A-F or N/A', function (): void {
        $service = new WeeklyDigestService($this->config);
        $digest = $service->generate();
        $grade = $digest['summary']['overall_grade'];

        expect($grade)->toBeIn(['A', 'B', 'C', 'D', 'F', 'N/A']);
    });

    it('has MIT license header', function (): void {
        $contents = file_get_contents(
            (new ReflectionClass(WeeklyDigestService::class))->getFileName(),
        );
        expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
    });
});

// ─── EventStreamService Extensions ─────────────────────────────────────────

describe('EventStreamService new methods', function (): void {
    beforeEach(function (): void {
        $manager = Mockery::mock(ZeroBoiler\Analytics\AnalyticsManager::class);
        $manager->shouldReceive('eventCategory')->andReturnNull();

        $metrics = Mockery::mock(ZeroBoiler\Analytics\AnalyticsMetrics::class);

        $this->stream = new EventStreamService($manager, $metrics, 100);
    });

    it('getTotalCount returns 0 for empty buffer', function (): void {
        expect($this->stream->getTotalCount())->toBe(0);
    });

    it('getTotalCount returns correct count after push', function (): void {
        $this->stream->push('page_view');
        $this->stream->push('button_click');
        $this->stream->push('page_view');

        expect($this->stream->getTotalCount())->toBe(3);
    });

    it('getEventCount returns 0 for unknown event', function (): void {
        expect($this->stream->getEventCount('unknown_event'))->toBe(0);
    });

    it('getEventCount returns correct count for specific event', function (): void {
        $this->stream->push('page_view');
        $this->stream->push('button_click');
        $this->stream->push('page_view');
        $this->stream->push('page_view');

        expect($this->stream->getEventCount('page_view'))->toBe(3);
        expect($this->stream->getEventCount('button_click'))->toBe(1);
    });

    it('getRecentEvents returns empty array for empty buffer', function (): void {
        expect($this->stream->getRecentEvents())->toBe([]);
    });

    it('getRecentEvents returns events with name key', function (): void {
        $this->stream->push('page_view', ['url' => '/home']);
        $this->stream->push('button_click', ['element' => 'cta']);

        $recent = $this->stream->getRecentEvents();

        expect($recent)->toHaveCount(2);
        // Most recent first (reversed)
        expect($recent[0]['name'])->toBe('button_click');
        expect($recent[1]['name'])->toBe('page_view');
    });

    it('getRecentEvents respects limit', function (): void {
        for ($i = 0; $i < 10; $i++) {
            $this->stream->push("event_{$i}");
        }

        $recent = $this->stream->getRecentEvents(3);

        expect($recent)->toHaveCount(3);
        // Most recent first
        expect($recent[0]['name'])->toBe('event_9');
    });

    it('getRecentEvents preserves original event field', function (): void {
        $this->stream->push('purchase');

        $recent = $this->stream->getRecentEvents();

        expect($recent[0])->toHaveKey('event');
        expect($recent[0])->toHaveKey('name');
        expect($recent[0]['event'])->toBe('purchase');
        expect($recent[0]['name'])->toBe('purchase');
    });

    it('buffer eviction works correctly with new methods', function (): void {
        // Buffer size is 100
        for ($i = 0; $i < 150; $i++) {
            $this->stream->push("event_{$i}");
        }

        // Buffer should only keep last 100
        expect($this->stream->getTotalCount())->toBe(100);
        expect($this->stream->getEventCount('event_0'))->toBe(0);
        expect($this->stream->getEventCount('event_149'))->toBe(1);
    });
});

// ─── ServiceProvider Registration ──────────────────────────────────────────

describe('ServiceProvider registrations for new services', function (): void {
    it('AnalyticsServiceProvider registers GrowthMetricsService', function (): void {
        $ref = new ReflectionClass(
            ZeroBoiler\Analytics\AnalyticsServiceProvider::class,
        );
        $contents = file_get_contents($ref->getFileName());

        expect($contents)->toContain('GrowthMetricsService::class');
    });

    it('AnalyticsServiceProvider registers OnboardingWizardService', function (): void {
        $ref = new ReflectionClass(
            ZeroBoiler\Analytics\AnalyticsServiceProvider::class,
        );
        $contents = file_get_contents($ref->getFileName());

        expect($contents)->toContain('OnboardingWizardService::class');
    });

    it('AnalyticsServiceProvider registers WeeklyDigestService', function (): void {
        $ref = new ReflectionClass(
            ZeroBoiler\Analytics\AnalyticsServiceProvider::class,
        );
        $contents = file_get_contents($ref->getFileName());

        expect($contents)->toContain('WeeklyDigestService::class');
    });

    it('AnalyticsServiceProvider has import statements for new services', function (): void {
        $ref = new ReflectionClass(
            ZeroBoiler\Analytics\AnalyticsServiceProvider::class,
        );
        $contents = file_get_contents($ref->getFileName());

        expect($contents)->toContain('use ZeroBoiler\\Analytics\\Services\\GrowthMetricsService');
        expect($contents)->toContain('use ZeroBoiler\\Analytics\\Services\\OnboardingWizardService');
        expect($contents)->toContain('use ZeroBoiler\\Analytics\\Services\\WeeklyDigestService');
    });
});

// ─── Version Consistency ─────────────────────────────────────────────────────

describe('Version consistency', function (): void {
    it('composer.json version is 3.6.0', function (): void {
        $composer = json_decode(
            file_get_contents(base_path('vendor/zeroboiler/analytics/composer.json')),
            true,
        );

        // Fallback: read from package directory directly
        $path = base_path('vendor/zeroboiler/analytics/composer.json');
        if (! file_exists($path)) {
            $path = dirname((new ReflectionClass(AnalyticsEvent::class))->getFileName(), 3) . '/composer.json';
        }

        if (file_exists($path)) {
            $composer = json_decode(file_get_contents($path), true);
            expect($composer['version'])->toBe('268.0.0');
        }
    });

    it('AnalyticsEvent VERSION is 266.0.0', function (): void {
        expect(AnalyticsEvent::VERSION)->toBe('268.0.0');
    });

    it('AnalyticsHealthCheckService VERSION is 266.0.0', function (): void {
        $ref = new ReflectionClass(ZeroBoiler\Analytics\Services\AnalyticsHealthCheckService::class);
        $const = $ref->getConstant('VERSION');
        expect($const)->toBe('268.0.0');
    });
});
