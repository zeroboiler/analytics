<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\EventPruningRecommendation;
use ZeroBoiler\Analytics\DTO\EventSNRResult;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Services\EventPruningAdvisorService;
use ZeroBoiler\Analytics\Services\EventSNRCalculatorService;

describe('V220 Event SNR Calculator + Pruning Advisor', function () {
    describe('EventSNRResult DTO', function () {
        it('constructs with all fields', function () {
            $result = new EventSNRResult(
                eventName: 'purchase',
                category: 'ecommerce',
                dispatchCount: 5000,
                dispatchShare: 15.0,
                actionabilityScore: 98.0,
                correlationScore: 98.0,
                uniquenessScore: 95.0,
                costPerDispatch: 0.0001,
                totalCost: 0.5,
                snr: 97.0,
                grade: 'A+',
                verdict: 'signal',
            );

            expect($result->eventName)->toBe('purchase');
            expect($result->category)->toBe('ecommerce');
            expect($result->dispatchCount)->toBe(5000);
            expect($result->dispatchShare)->toBe(15.0);
            expect($result->snr)->toBe(97.0);
            expect($result->grade)->toBe('A+');
            expect($result->verdict)->toBe('signal');
        });

        it('serializes to array correctly', function () {
            $result = new EventSNRResult(
                eventName: 'login',
                category: 'saas',
                dispatchCount: 10000,
                dispatchShare: 25.0,
                actionabilityScore: 45.0,
                correlationScore: 25.0,
                uniquenessScore: 15.0,
                costPerDispatch: 0.0001,
                totalCost: 1.0,
                snr: 30.5,
                grade: 'D',
                verdict: 'noise_candidate',
            );

            $arr = $result->toArray();

            expect($arr)->toHaveKey('event_name');
            expect($arr['event_name'])->toBe('login');
            expect($arr['snr'])->toBe(30.5);
            expect($arr['grade'])->toBe('D');
            expect($arr['verdict'])->toBe('noise_candidate');
            expect($arr['dispatch_share'])->toBe(25.0);
        });

        it('round-trips through fromArray', function () {
            $original = new EventSNRResult(
                eventName: 'page_view',
                category: 'engagement',
                dispatchCount: 50000,
                dispatchShare: 55.0,
                actionabilityScore: 35.0,
                correlationScore: 40.0,
                uniquenessScore: 20.0,
                costPerDispatch: 0.0001,
                totalCost: 5.0,
                snr: 33.5,
                grade: 'D',
                verdict: 'noise_candidate',
            );

            $restored = EventSNRResult::fromArray($original->toArray());

            expect($restored->eventName)->toBe($original->eventName);
            expect($restored->category)->toBe($original->category);
            expect($restored->snr)->toBe($original->snr);
            expect($restored->grade)->toBe($original->grade);
            expect($restored->verdict)->toBe($original->verdict);
        });

        it('identifies noise events correctly', function () {
            $noise = new EventSNRResult(
                eventName: 'test',
                category: 'custom',
                dispatchCount: 100,
                dispatchShare: 0.1,
                actionabilityScore: 5.0,
                correlationScore: 3.0,
                uniquenessScore: 2.0,
                costPerDispatch: 0.0001,
                totalCost: 0.01,
                snr: 4.0,
                grade: 'F',
                verdict: 'noise',
            );

            expect($noise->isNoise())->toBeTrue();
            expect($noise->isNoiseCandidate())->toBeFalse();
            expect($noise->isSignal())->toBeFalse();
        });

        it('identifies noise candidates correctly', function () {
            $candidate = new EventSNRResult(
                eventName: 'test',
                category: 'custom',
                dispatchCount: 500,
                dispatchShare: 0.5,
                actionabilityScore: 30.0,
                correlationScore: 25.0,
                uniquenessScore: 20.0,
                costPerDispatch: 0.0001,
                totalCost: 0.05,
                snr: 25.5,
                grade: 'D',
                verdict: 'noise_candidate',
            );

            expect($candidate->isNoise())->toBeFalse();
            expect($candidate->isNoiseCandidate())->toBeTrue();
            expect($candidate->isSignal())->toBeFalse();
        });

        it('identifies signal events correctly', function () {
            $signal = new EventSNRResult(
                eventName: 'test',
                category: 'custom',
                dispatchCount: 1000,
                dispatchShare: 1.0,
                actionabilityScore: 95.0,
                correlationScore: 90.0,
                uniquenessScore: 88.0,
                costPerDispatch: 0.0001,
                totalCost: 0.1,
                snr: 91.5,
                grade: 'A+',
                verdict: 'signal',
            );

            expect($signal->isNoise())->toBeFalse();
            expect($signal->isNoiseCandidate())->toBeFalse();
            expect($signal->isSignal())->toBeTrue();
        });

        it('computes cost efficiency correctly', function () {
            $result = new EventSNRResult(
                eventName: 'test',
                category: 'custom',
                dispatchCount: 1000,
                dispatchShare: 1.0,
                actionabilityScore: 50.0,
                correlationScore: 50.0,
                uniquenessScore: 50.0,
                costPerDispatch: 0.0001,
                totalCost: 0.1,
                snr: 50.0,
                grade: 'C',
                verdict: 'moderate',
            );

            // costEfficiency = SNR / totalCost = 50.0 / 0.1 = 500.0
            expect($result->costEfficiency())->toBe(500.0);
        });

        it('handles zero total cost in cost efficiency', function () {
            $result = new EventSNRResult(
                eventName: 'test',
                category: 'custom',
                dispatchCount: 0,
                dispatchShare: 0.0,
                actionabilityScore: 50.0,
                correlationScore: 50.0,
                uniquenessScore: 50.0,
                costPerDispatch: 0.0001,
                totalCost: 0.0,
                snr: 50.0,
                grade: 'C',
                verdict: 'moderate',
            );

            expect($result->costEfficiency())->toBe(0.0);
        });
    });

    describe('EventPruningRecommendation DTO', function () {
        it('constructs with all fields', function () {
            $rec = new EventPruningRecommendation(
                eventName: 'logout',
                category: 'saas',
                action: 'merge_with',
                rationale: 'Low SNR, can merge with login.',
                currentCost: 0.05,
                estimatedSavings: 0.04,
                snr: 12.5,
                mergeTarget: 'login',
                priority: 'medium',
                alternatives: ['login'],
            );

            expect($rec->eventName)->toBe('logout');
            expect($rec->action)->toBe('merge_with');
            expect($rec->mergeTarget)->toBe('login');
            expect($rec->priority)->toBe('medium');
            expect($rec->estimatedSavings)->toBe(0.04);
        });

        it('constructs with sampling action', function () {
            $rec = new EventPruningRecommendation(
                eventName: 'time_on_page',
                category: 'engagement',
                action: 'sample_only',
                rationale: 'High volume, low signal.',
                currentCost: 2.0,
                estimatedSavings: 1.8,
                snr: 18.0,
                suggestedSampleRate: 5,
                priority: 'high',
            );

            expect($rec->suggestedSampleRate)->toBe(5);
            expect($rec->isHighPriority())->toBeTrue();
        });

        it('serializes to array correctly', function () {
            $rec = new EventPruningRecommendation(
                eventName: 'test',
                category: 'custom',
                action: 'remove',
                rationale: 'Noise event.',
                currentCost: 0.5,
                estimatedSavings: 0.5,
                snr: 8.0,
                priority: 'low',
            );

            $arr = $rec->toArray();

            expect($arr['event_name'])->toBe('test');
            expect($arr['action'])->toBe('remove');
            expect($arr['snr'])->toBe(8.0);
        });

        it('round-trips through fromArray', function () {
            $original = new EventPruningRecommendation(
                eventName: 'view_cart',
                category: 'ecommerce',
                action: 'merge_with',
                rationale: 'Merge with add_to_cart.',
                currentCost: 0.3,
                estimatedSavings: 0.24,
                snr: 15.0,
                mergeTarget: 'add_to_cart',
                priority: 'medium',
                alternatives: ['add_to_cart', 'view_item'],
            );

            $restored = EventPruningRecommendation::fromArray($original->toArray());

            expect($restored->eventName)->toBe($original->eventName);
            expect($restored->action)->toBe($original->action);
            expect($restored->mergeTarget)->toBe($original->mergeTarget);
            expect($restored->alternatives)->toBe($original->alternatives);
        });

        it('computes savings percentage correctly', function () {
            $rec = new EventPruningRecommendation(
                eventName: 'test',
                category: 'custom',
                action: 'remove',
                rationale: 'Test.',
                currentCost: 10.0,
                estimatedSavings: 8.5,
                snr: 5.0,
            );

            // 8.5 / 10.0 * 100 = 85%
            expect($rec->savingsPercentage())->toBe(85.0);
        });

        it('handles zero current cost in savings percentage', function () {
            $rec = new EventPruningRecommendation(
                eventName: 'test',
                category: 'custom',
                action: 'remove',
                rationale: 'Test.',
                currentCost: 0.0,
                estimatedSavings: 0.0,
                snr: 5.0,
            );

            expect($rec->savingsPercentage())->toBe(0.0);
        });
    });

    describe('EventSNRCalculatorService', function () {
        it('has strict types declaration', function () {
            $contents = file_get_contents(__DIR__ . '/../../src/Services/EventSNRCalculatorService.php');
            expect($contents)->toContain('declare(strict_types=1)');
        });

        it('has MIT license header', function () {
            $contents = file_get_contents(__DIR__ . '/../../src/Services/EventSNRCalculatorService.php');
            expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
        });

        it('is a final class', function () {
            $ref = new ReflectionClass(EventSNRCalculatorService::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('has @since 220.0.0 annotation', function () {
            $contents = file_get_contents(__DIR__ . '/../../src/Services/EventSNRCalculatorService.php');
            expect($contents)->toContain('@since 220.0.0');
        });

        it('declares return types on public methods', function () {
            $ref = new ReflectionClass(EventSNRCalculatorService::class);
            $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($publicMethods as $method) {
                if ($method->getName() === '__construct') {
                    continue;
                }
                expect($method->hasReturnType())->toBeTrue()->and(
                    "Method {$method->getName()} missing return type"
                );
            }
        });

        it('purchase has high SNR (grade A+)', function () {
            $metrics = mock(ZeroBoiler\Analytics\AnalyticsMetrics::class);
            $metrics->shouldReceive('totalEvents')->andReturn(100000);

            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturnNull();
            $cache->shouldReceive('put')->andReturnTrue();

            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.event_snr', [])->andReturn([
                'enabled' => true,
                'cache_ttl' => 3600,
                'cost_per_dispatch' => 0.0001,
            ]);

            $service = new EventSNRCalculatorService($cache, $config, $metrics);
            $result = $service->calculate('purchase');

            expect($result->eventName)->toBe('purchase');
            expect($result->category)->toBe('ecommerce');
            expect($result->snr)->toBeGreaterThanOrEqual(85.0);
            expect($result->grade)->toBe('A+');
            expect($result->verdict)->toBe('signal');
            expect($result)->toBeInstanceOf(EventSNRResult::class);
        });

        it('logout has very low SNR (grade F or D)', function () {
            $metrics = mock(ZeroBoiler\Analytics\AnalyticsMetrics::class);
            $metrics->shouldReceive('totalEvents')->andReturn(100000);

            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.event_snr', [])->andReturn([]);

            $service = new EventSNRCalculatorService($cache, $config, $metrics);
            $result = $service->calculate('logout');

            expect($result->snr)->toBeLessThan(40.0);
            expect($result->verdict)->toBe('noise' );
        });

        it('sign_up has high SNR', function () {
            $metrics = mock(ZeroBoiler\Analytics\AnalyticsMetrics::class);
            $metrics->shouldReceive('totalEvents')->andReturn(100000);

            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.event_snr', [])->andReturn([]);

            $service = new EventSNRCalculatorService($cache, $config, $metrics);
            $result = $service->calculate('sign_up');

            expect($result->snr)->toBeGreaterThanOrEqual(70.0);
            expect($result->verdict)->toBe('signal');
        });

        it('accepts overrides for testing', function () {
            $metrics = mock(ZeroBoiler\Analytics\AnalyticsMetrics::class);
            $metrics->shouldReceive('totalEvents')->andReturn(1000);

            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.event_snr', [])->andReturn([]);

            $service = new EventSNRCalculatorService($cache, $config, $metrics);

            $result = $service->calculate('custom_event', [
                'actionability' => 10.0,
                'correlation' => 5.0,
                'uniqueness' => 3.0,
                'dispatch_count' => 50000,
                'dispatch_share' => 50.0,
                'cost_per_dispatch' => 0.001,
            ]);

            expect($result->snr)->toBeLessThan(20.0);
            expect($result->verdict)->toBe('noise');
            expect($result->totalCost)->toBe(50.0); // 50000 * 0.001
        });

        it('report returns valid structure', function () {
            $metrics = mock(ZeroBoiler\Analytics\AnalyticsMetrics::class);
            $metrics->shouldReceive('totalEvents')->andReturn(50000);

            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturnNull();
            $cache->shouldReceive('put')->andReturnTrue();

            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.event_snr', [])->andReturn([]);

            $service = new EventSNRCalculatorService($cache, $config, $metrics);
            $report = $service->report(true);

            expect($report)->toHaveKey('total_events');
            expect($report)->toHaveKey('signal_count');
            expect($report)->toHaveKey('moderate_count');
            expect($report)->toHaveKey('noise_candidate_count');
            expect($report)->toHaveKey('noise_count');
            expect($report)->toHaveKey('average_snr');
            expect($report)->toHaveKey('median_snr');
            expect($report)->toHaveKey('weighted_snr');
            expect($report)->toHaveKey('total_monthly_cost');
            expect($report)->toHaveKey('top_signal_events');
            expect($report)->toHaveKey('top_noise_events');
            expect($report)->toHaveKey('events');
            expect($report)->toHaveKey('grades');
            expect($report)->toHaveKey('category_summary');
            expect($report)->toHaveKey('computed_at');

            expect($report['total_events'])->toBeGreaterThan(0);
            expect($report['average_snr'])->toBeGreaterThan(0.0);
            expect($report['computed_at'])->toBeString();
        });

        it('grades are all present in report', function () {
            $metrics = mock(ZeroBoiler\Analytics\AnalyticsMetrics::class);
            $metrics->shouldReceive('totalEvents')->andReturn(50000);

            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturnNull();
            $cache->shouldReceive('put')->andReturnTrue();

            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.event_snr', [])->andReturn([]);

            $service = new EventSNRCalculatorService($cache, $config, $metrics);
            $report = $service->report(true);

            $grades = $report['grades'];
            expect($grades)->toHaveKey('A+');
            expect($grades)->toHaveKey('A');
            expect($grades)->toHaveKey('B');
            expect($grades)->toHaveKey('C');
            expect($grades)->toHaveKey('D');
            expect($grades)->toHaveKey('F');
        });

        it('category summary has valid structure', function () {
            $metrics = mock(ZeroBoiler\Analytics\AnalyticsMetrics::class);
            $metrics->shouldReceive('totalEvents')->andReturn(50000);

            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturnNull();
            $cache->shouldReceive('put')->andReturnTrue();

            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.event_snr', [])->andReturn([]);

            $service = new EventSNRCalculatorService($cache, $config, $metrics);
            $summary = $service->categorySummary();

            expect($summary)->toBeArray();
            // At minimum ecommerce, saas, engagement should exist
            expect($summary)->toHaveKey('ecommerce');
            expect($summary['ecommerce'])->toHaveKey('count');
            expect($summary['ecommerce'])->toHaveKey('avg_snr');
            expect($summary['ecommerce'])->toHaveKey('total_cost');
            expect($summary['ecommerce'])->toHaveKey('signal_count');
            expect($summary['ecommerce'])->toHaveKey('noise_count');
        });

        it('getConfig returns valid structure', function () {
            $metrics = mock(ZeroBoiler\Analytics\AnalyticsMetrics::class);
            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.event_snr', [])->andReturn([
                'enabled' => true,
                'cache_ttl' => 7200,
                'cost_per_dispatch' => 0.0002,
            ]);

            $service = new EventSNRCalculatorService($cache, $config, $metrics);
            $cfg = $service->getConfig();

            expect($cfg)->toHaveKey('enabled');
            expect($cfg)->toHaveKey('cache_ttl');
            expect($cfg)->toHaveKey('cost_per_dispatch');
            expect($cfg)->toHaveKey('weights');
            expect($cfg)->toHaveKey('thresholds');
            expect($cfg['weights'])->toHaveKey('actionability');
            expect($cfg['weights'])->toHaveKey('correlation');
            expect($cfg['weights'])->toHaveKey('uniqueness');
            expect($cfg['weights'])->toHaveKey('cost_efficiency');
            expect($cfg['thresholds'])->toHaveKey('signal');
            expect($cfg['thresholds'])->toHaveKey('moderate');
            expect($cfg['thresholds'])->toHaveKey('noise_candidate');
        });

        it('isEnabled returns correct value', function () {
            $metrics = mock(ZeroBoiler\Analytics\AnalyticsMetrics::class);
            $cache = mock(Illuminate\Contracts\Cache\Repository::class);

            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->andReturn(['enabled' => true]);
            $service1 = new EventSNRCalculatorService($cache, $config, $metrics);
            expect($service1->isEnabled())->toBeTrue();

            $config2 = mock(Illuminate\Contracts\Config\Repository::class);
            $config2->shouldReceive('get')->andReturn(['enabled' => false]);
            $service2 = new EventSNRCalculatorService($cache, $config2, $metrics);
            expect($service2->isEnabled())->toBeFalse();
        });
    });

    describe('EventPruningAdvisorService', function () {
        it('has strict types declaration', function () {
            $contents = file_get_contents(__DIR__ . '/../../src/Services/EventPruningAdvisorService.php');
            expect($contents)->toContain('declare(strict_types=1)');
        });

        it('has MIT license header', function () {
            $contents = file_get_contents(__DIR__ . '/../../src/Services/EventPruningAdvisorService.php');
            expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
        });

        it('is a final class', function () {
            $ref = new ReflectionClass(EventPruningAdvisorService::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('has @since 220.0.0 annotation', function () {
            $contents = file_get_contents(__DIR__ . '/../../src/Services/EventPruningAdvisorService.php');
            expect($contents)->toContain('@since 220.0.0');
        });

        it('protected events list contains critical events', function () {
            $metrics = mock(ZeroBoiler\Analytics\AnalyticsMetrics::class);
            $metrics->shouldReceive('totalEvents')->andReturn(50000);

            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturnNull();
            $cache->shouldReceive('put')->andReturnTrue();

            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.event_snr', [])->andReturn([]);

            $snrService = new EventSNRCalculatorService($cache, $config, $metrics);
            $advisor = new EventPruningAdvisorService($snrService, $cache, $config);

            $protected = $advisor->protectedEvents();

            expect($protected)->toContain('purchase');
            expect($protected)->toContain('sign_up');
            expect($protected)->toContain('subscribe');
            expect($protected)->toContain('cancellation');
            expect($protected)->toContain('start_trial');
            expect($protected)->toContain('trial_converted');
            expect($protected)->toContain('plan_upgrade');
        });

        it('isProtected returns true for critical events', function () {
            $metrics = mock(ZeroBoiler\Analytics\AnalyticsMetrics::class);
            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.event_snr', [])->andReturn([]);

            $snrService = new EventSNRCalculatorService($cache, $config, $metrics);
            $advisor = new EventPruningAdvisorService($snrService, $cache, $config);

            expect($advisor->isProtected('purchase'))->toBeTrue();
            expect($advisor->isProtected('sign_up'))->toBeTrue();
            expect($advisor->isProtected('random_event'))->toBeFalse();
        });

        it('report returns valid structure', function () {
            $metrics = mock(ZeroBoiler\Analytics\AnalyticsMetrics::class);
            $metrics->shouldReceive('totalEvents')->andReturn(50000);

            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturnNull();
            $cache->shouldReceive('put')->andReturnTrue();

            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.event_snr', [])->andReturn([]);

            $snrService = new EventSNRCalculatorService($cache, $config, $metrics);
            $advisor = new EventPruningAdvisorService($snrService, $cache, $config);

            $report = $advisor->report(true);

            expect($report)->toHaveKey('total_recommendations');
            expect($report)->toHaveKey('high_priority');
            expect($report)->toHaveKey('medium_priority');
            expect($report)->toHaveKey('low_priority');
            expect($report)->toHaveKey('estimated_monthly_savings');
            expect($report)->toHaveKey('action_breakdown');
            expect($report)->toHaveKey('recommendations');
            expect($report)->toHaveKey('consolidation_opportunities');
            expect($report)->toHaveKey('noise_ratio');
            expect($report)->toHaveKey('computed_at');

            expect($report['estimated_monthly_savings'])->toBeGreaterThanOrEqual(0.0);
            expect($report['noise_ratio'])->toBeGreaterThanOrEqual(0.0);
        });

        it('action_breakdown has all action types', function () {
            $metrics = mock(ZeroBoiler\Analytics\AnalyticsMetrics::class);
            $metrics->shouldReceive('totalEvents')->andReturn(50000);

            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturnNull();
            $cache->shouldReceive('put')->andReturnTrue();

            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.event_snr', [])->andReturn([]);

            $snrService = new EventSNRCalculatorService($cache, $config, $metrics);
            $advisor = new EventPruningAdvisorService($snrService, $cache, $config);

            $report = $advisor->report(true);
            $breakdown = $report['action_breakdown'];

            expect($breakdown)->toHaveKey('remove');
            expect($breakdown)->toHaveKey('reduce_frequency');
            expect($breakdown)->toHaveKey('merge_with');
            expect($breakdown)->toHaveKey('sample_only');
        });

        it('recommendations do not include protected events', function () {
            $metrics = mock(ZeroBoiler\Analytics\AnalyticsMetrics::class);
            $metrics->shouldReceive('totalEvents')->andReturn(50000);

            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturnNull();
            $cache->shouldReceive('put')->andReturnTrue();

            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.event_snr', [])->andReturn([]);

            $snrService = new EventSNRCalculatorService($cache, $config, $metrics);
            $advisor = new EventPruningAdvisorService($snrService, $cache, $config);

            $report = $advisor->report(true);

            $protectedEvents = $advisor->protectedEvents();
            foreach ($report['recommendations'] as $rec) {
                expect(in_array($rec->eventName, $protectedEvents, true))->toBeFalse()->and(
                    "Protected event '{$rec->eventName}' should not be in recommendations"
                );
            }
        });

        it('declares return types on public methods', function () {
            $ref = new ReflectionClass(EventPruningAdvisorService::class);
            $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($publicMethods as $method) {
                if ($method->getName() === '__construct') {
                    continue;
                }
                expect($method->hasReturnType())->toBeTrue()->and(
                    "Method {$method->getName()} missing return type"
                );
            }
        });

        it('consolidation opportunities have valid structure', function () {
            $metrics = mock(ZeroBoiler\Analytics\AnalyticsMetrics::class);
            $metrics->shouldReceive('totalEvents')->andReturn(50000);

            $cache = mock(Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturnNull();
            $cache->shouldReceive('put')->andReturnTrue();

            $config = mock(Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.event_snr', [])->andReturn([]);

            $snrService = new EventSNRCalculatorService($cache, $config, $metrics);
            $advisor = new EventPruningAdvisorService($snrService, $cache, $config);

            $opportunities = $advisor->consolidationOpportunities();

            expect($opportunities)->toBeArray();
            foreach ($opportunities as $opp) {
                expect($opp)->toHaveKey('events');
                expect($opp)->toHaveKey('suggested_name');
                expect($opp)->toHaveKey('combined_snr');
                expect($opp)->toHaveKey('estimated_savings');
                expect($opp['events'])->toBeArray();
                expect(count($opp['events']))->toBeGreaterThanOrEqual(2);
            }
        });
    });

    describe('Commands', function () {
        it('AnalyticsSnrCommand has correct signature', function () {
            $ref = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsSnrCommand::class);
            expect($ref->isFinal())->toBeTrue();

            // Check signature
            $prop = $ref->getProperty('signature');
            $signature = $prop->getValue(new \ZeroBoiler\Analytics\Console\Commands\AnalyticsSnrCommand);
            expect($signature)->toContain('zb:analytics:snr');
        });

        it('AnalyticsPruneAdvisorCommand has correct signature', function () {
            $ref = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsPruneAdvisorCommand::class);
            expect($ref->isFinal())->toBeTrue();

            $prop = $ref->getProperty('signature');
            $signature = $prop->getValue(new \ZeroBoiler\Analytics\Console\Commands\AnalyticsPruneAdvisorCommand);
            expect($signature)->toContain('zb:analytics:prune-advisor');
        });
    });

    describe('File Quality', function () {
        it('DTO files have strict types', function () {
            foreach (['EventSNRResult', 'EventPruningRecommendation'] as $class) {
                $file = (new ReflectionClass("ZeroBoiler\\Analytics\\DTO\\{$class}"))->getFileName();
                $contents = file_get_contents($file);
                expect($contents)->toContain('declare(strict_types=1)')->and("{$class} missing strict_types");
            }
        });

        it('DTO files are readonly', function () {
            foreach (['EventSNRResult', 'EventPruningRecommendation'] as $class) {
                $ref = new ReflectionClass("ZeroBoiler\\Analytics\\DTO\\{$class}");
                expect($ref->isReadOnly())->toBeTrue()->and("{$class} is not readonly");
            }
        });

        it('DTO files are final', function () {
            foreach (['EventSNRResult', 'EventPruningRecommendation'] as $class) {
                $ref = new ReflectionClass("ZeroBoiler\\Analytics\\DTO\\{$class}");
                expect($ref->isFinal())->toBeTrue()->and("{$class} is not final");
            }
        });

        it('DTO files have @since 220.0.0', function () {
            foreach (['EventSNRResult', 'EventPruningRecommendation'] as $class) {
                $file = (new ReflectionClass("ZeroBoiler\\Analytics\\DTO\\{$class}"))->getFileName();
                $contents = file_get_contents($file);
                expect($contents)->toContain('@since 220.0.0')->and("{$class} missing @since");
            }
        });

        it('service files have MIT header', function () {
            foreach (['EventSNRCalculatorService', 'EventPruningAdvisorService'] as $class) {
                $file = (new ReflectionClass("ZeroBoiler\\Analytics\\Services\\{$class}"))->getFileName();
                $contents = file_get_contents($file);
                expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
            }
        });

        it('command files have MIT header', function () {
            foreach (['AnalyticsSnrCommand', 'AnalyticsPruneAdvisorCommand'] as $class) {
                $file = (new ReflectionClass("ZeroBoiler\\Analytics\\Console\\Commands\\{$class}"))->getFileName();
                $contents = file_get_contents($file);
                expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
            }
        });

        it('command files have @since 220.0.0', function () {
            foreach (['AnalyticsSnrCommand', 'AnalyticsPruneAdvisorCommand'] as $class) {
                $file = (new ReflectionClass("ZeroBoiler\\Analytics\\Console\\Commands\\{$class}"))->getFileName();
                $contents = file_get_contents($file);
                expect($contents)->toContain('@since 220.0.0');
            }
        });
    });
});
