<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Services\SaaSAnalyticsGlossaryService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsGlossaryCommand;

describe('SaaSAnalyticsGlossaryService', function () {
    beforeEach(function () {
        $this->glossary = new SaaSAnalyticsGlossaryService;
    });

    it('has strict_types declaration', function () {
        $content = file_get_contents((new ReflectionClass(SaaSAnalyticsGlossaryService::class))->getFileName());
        expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
    });

    it('has MIT license header', function () {
        $content = file_get_contents((new ReflectionClass(SaaSAnalyticsGlossaryService::class))->getFileName());
        expect(str_contains($content, 'This file is part of ZeroBoiler, licensed under the MIT license.'))->toBeTrue();
    });

    it('is a final class', function () {
        $ref = new ReflectionClass(SaaSAnalyticsGlossaryService::class);
        expect($ref->isFinal())->toBeTrue();
    });

    it('has @since 217.0.0 docblock tag', function () {
        $ref = new ReflectionClass(SaaSAnalyticsGlossaryService::class);
        $doc = $ref->getDocComment();
        expect($doc !== false && str_contains($doc, '@since 217.0.0'))->toBeTrue();
    });

    it('contains at least 28 metrics', function () {
        expect($this->glossary->count())->toBeGreaterThanOrEqual(28);
    });

    it('covers all 6 categories', function () {
        $cats = $this->glossary->categories();
        expect($cats)->toContain('Revenue');
        expect($cats)->toContain('Growth');
        expect($cats)->toContain('Unit Economics');
        expect($cats)->toContain('Engagement');
        expect($cats)->toContain('Retention');
        expect($cats)->toContain('Funnel');
        expect(count($cats))->toBe(6);
    });

    it('has essential SaaS metrics', function () {
        expect($this->glossary->has('mrr'))->toBeTrue();
        expect($this->glossary->has('arr'))->toBeTrue();
        expect($this->glossary->has('nrr'))->toBeTrue();
        expect($this->glossary->has('ltv'))->toBeTrue();
        expect($this->glossary->has('cac'))->toBeTrue();
        expect($this->glossary->has('churn_rate'))->toBeTrue();
        expect($this->glossary->has('stickiness'))->toBeTrue();
        expect($this->glossary->has('dau'))->toBeTrue();
        expect($this->glossary->has('mau'))->toBeTrue();
        expect($this->glossary->has('activation_rate'))->toBeTrue();
        expect($this->glossary->has('conversion_rate'))->toBeTrue();
        expect($this->glossary->has('trial_to_paid'))->toBeTrue();
        expect($this->glossary->has('ltv_cac_ratio'))->toBeTrue();
        expect($this->glossary->has('cac_payback'))->toBeTrue();
        expect($this->glossary->has('quick_ratio'))->toBeTrue();
        expect($this->glossary->has('rule_of_40'))->toBeTrue();
    });

    it('all() returns all metrics with required fields', function () {
        $all = $this->glossary->all();

        foreach ($all as $key => $metric) {
            expect($metric)->toHaveKeys(['name', 'description', 'formula', 'benchmarks', 'source_events', 'required_config', 'category', 'tags']);
            expect($metric['name'])->toBeString();
            expect($metric['name'])->not->toBeEmpty();
            expect($metric['description'])->toBeString();
            expect($metric['description'])->not->toBeEmpty();
            expect($metric['formula'])->toBeString();
            expect($metric['formula'])->not->toBeEmpty();
            expect($metric['benchmarks'])->toBeArray();
            expect($metric['benchmarks'])->toHaveKeys(['good', 'acceptable', 'poor']);
            expect($metric['source_events'])->toBeArray();
            expect($metric['category'])->toBeString();
            expect($metric['tags'])->toBeArray();
        }
    });

    it('get() returns null for unknown metric', function () {
        expect($this->glossary->get('nonexistent_metric_xyz'))->toBeNull();
    });

    it('get() returns valid entry for known metric', function () {
        $mrr = $this->glossary->get('mrr');

        expect($mrr)->not->toBeNull();
        expect($mrr['name'])->toBe('Monthly Recurring Revenue');
        expect($mrr['category'])->toBe('Revenue');
        expect($mrr['tags'])->toContain('revenue');
        expect($mrr['tags'])->toContain('kpi');
        expect($mrr['source_events'])->toContain('subscribe');
        expect($mrr['source_events'])->toContain('plan_upgrade');
        expect($mrr['source_events'])->toContain('cancellation');
    });

    it('groupedByCategory() groups correctly', function () {
        $groups = $this->glossary->groupedByCategory();

        expect($groups)->toHaveKey('Revenue');
        expect($groups['Revenue'])->toHaveKey('mrr');
        expect($groups['Revenue'])->toHaveKey('arr');
        expect($groups['Growth'])->toHaveKey('nrr');
        expect($groups['Engagement'])->toHaveKey('dau');
    });

    it('byTag() returns matching metrics', function () {
        $revenueMetrics = $this->glossary->byTag('revenue');

        expect($revenueMetrics)->not->toBeEmpty();
        expect($revenueMetrics)->toHaveKey('mrr');
        expect($revenueMetrics)->toHaveKey('arr');
    });

    it('byTag() returns empty for non-existent tag', function () {
        expect($this->glossary->byTag('nonexistent_tag_xyz'))->toBeEmpty();
    });

    it('sourceEventsFor() returns events for a metric', function () {
        $events = $this->glossary->sourceEventsFor('mrr');

        expect($events)->toContain('subscribe');
        expect($events)->toContain('plan_upgrade');
        expect($events)->toContain('cancellation');
    });

    it('sourceEventsFor() returns empty for unknown metric', function () {
        expect($this->glossary->sourceEventsFor('nonexistent'))->toBeEmpty();
    });

    it('metricsForEvent() returns metrics that consume an event', function () {
        $metrics = $this->glossary->metricsForEvent('purchase');

        expect($metrics)->not->toBeEmpty();
        // purchase should feed conversion_rate
        $hasConversion = false;
        foreach ($metrics as $key => $name) {
            if ($key === 'conversion_rate') {
                $hasConversion = true;
                break;
            }
        }
        expect($hasConversion)->toBeTrue();
    });

    it('metricsForEvent() returns empty for unknown event', function () {
        expect($this->glossary->metricsForEvent('nonexistent_event_xyz'))->toBeEmpty();
    });

    it('eventToMetricMap() has correct structure', function () {
        $map = $this->glossary->eventToMetricMap();

        expect($map)->toBeArray();

        // subscribe should map to multiple metrics
        expect($map)->toHaveKey('subscribe');
        expect(count($map['subscribe']))->toBeGreaterThanOrEqual(3); // MRR, ARR, NRR, etc.
    });

    it('eventToMetricMap() metrics lists are sorted', function () {
        $map = $this->glossary->eventToMetricMap();

        foreach ($map as $event => $metricKeys) {
            $sorted = $metricKeys;
            sort($sorted);
            expect($metricKeys)->toBe($sorted);
        }
    });

    it('quickReference() returns compact structure', function () {
        $ref = $this->glossary->quickReference();

        expect(count($ref))->toBe($this->glossary->count());
        foreach ($ref as $entry) {
            expect($entry)->toHaveKeys(['key', 'name', 'formula', 'category']);
        }
    });

    it('clientSummary() omits internal details', function () {
        $summary = $this->glossary->clientSummary();

        foreach ($summary as $entry) {
            expect($entry)->toHaveKey('key');
            expect($entry)->toHaveKey('name');
            expect($entry)->toHaveKey('description');
            expect($entry)->toHaveKey('category');
            expect($entry)->toHaveKey('source_events');
            // Should NOT have formula or benchmarks in client summary
            expect($entry)->not->toHaveKey('formula');
            expect($entry)->not->toHaveKey('benchmarks');
            expect($entry)->not->toHaveKey('required_config');
        }
    });

    it('coverageAnalysis() returns valid structure', function () {
        $analysis = $this->glossary->coverageAnalysis();

        expect($analysis)->toHaveKeys(['covered', 'uncovered', 'coverage_percent', 'event_count', 'metric_count']);
        expect($analysis['metric_count'])->toBe($this->glossary->count());
        expect($analysis['coverage_percent'])->toBeGreaterThan(0);
        expect($analysis['coverage_percent'])->toBeLessThanOrEqual(100);
    });

    it('names() returns all metric keys', function () {
        $names = $this->glossary->names();

        expect($names)->toBeArray();
        expect(count($names))->toBe($this->glossary->count());
        expect($names)->toContain('mrr');
        expect($names)->toContain('arr');
        expect($names)->toContain('nrr');
        expect($names)->toContain('churn_rate');
    });

    it('all public methods have return type declarations', function () {
        $ref = new ReflectionClass(SaaSAnalyticsGlossaryService::class);
        $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

        $noReturnType = [];

        foreach ($methods as $method) {
            if ($method->getReturnType() === null) {
                $noReturnType[] = $method->getName();
            }
        }

        expect($noReturnType)->toBeEmpty(
            'Methods missing return types: ' . implode(', ', $noReturnType),
        );
    });
});

describe('AnalyticsGlossaryCommand', function () {
    it('has correct signature', function () {
        $command = new AnalyticsGlossaryCommand;

        expect($command->getName())->toBe('zb:analytics:glossary');
        expect($command->getDescription())->toBeString();
        expect($command->getDescription())->not->toBeEmpty();
    });

    it('has strict_types declaration', function () {
        $content = file_get_contents((new ReflectionClass(AnalyticsGlossaryCommand::class))->getFileName());
        expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
    });

    it('has MIT license header', function () {
        $content = file_get_contents((new ReflectionClass(AnalyticsGlossaryCommand::class))->getFileName());
        expect(str_contains($content, 'This file is part of ZeroBoiler, licensed under the MIT license.'))->toBeTrue();
    });

    it('has @since 217.0.0 docblock tag', function () {
        $ref = new ReflectionClass(AnalyticsGlossaryCommand::class);
        $doc = $ref->getDocComment();
        expect($doc !== false && str_contains($doc, '@since 217.0.0'))->toBeTrue();
    });

    it('accepts --metric option', function () {
        $command = new AnalyticsGlossaryCommand;
        $definition = $command->getDefinition();

        expect($definition->hasOption('metric'))->toBeTrue();
        expect($definition->hasOption('search'))->toBeTrue();
        expect($definition->hasOption('event'))->toBeTrue();
        expect($definition->hasOption('cross-ref'))->toBeTrue();
        expect($definition->hasOption('coverage'))->toBeTrue();
        expect($definition->hasOption('tags'))->toBeTrue();
        expect($definition->hasOption('compact'))->toBeTrue();
        expect($definition->hasOption('json'))->toBeTrue();
    });

    it('handle method has int return type', function () {
        $ref = new ReflectionMethod(AnalyticsGlossaryCommand::class, 'handle');
        expect($ref->getReturnType()?->getName())->toBe('int');
    });
});

describe('v217 Registration', function () {
    it('ServiceProvider registers SaaSAnalyticsGlossaryService', function () {
        $provider = new \ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class);
        $content = file_get_contents($provider->getFileName());

        expect(str_contains($content, 'SaaSAnalyticsGlossaryService'))->toBeTrue();
    });

    it('ServiceProvider registers AnalyticsGlossaryCommand', function () {
        $provider = new \ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class);
        $content = file_get_contents($provider->getFileName());

        expect(str_contains($content, 'AnalyticsGlossaryCommand'))->toBeTrue();
    });

    it('routes file has glossary endpoints', function () {
        $content = file_get_contents(__DIR__ . '/../routes/analytics.php');

        expect(str_contains($content, "'glossary'"))->toBeTrue();
        expect(str_contains($content, 'glossaryMetric'))->toBeTrue();
        expect(str_contains($content, 'glossarySearch'))->toBeTrue();
        expect(str_contains($content, 'glossaryMetricsForEvent'))->toBeTrue();
        expect(str_contains($content, 'glossaryCrossRef'))->toBeTrue();
        expect(str_contains($content, 'glossaryCoverage'))->toBeTrue();
        expect(str_contains($content, 'glossaryTags'))->toBeTrue();
    });

    it('controller has glossary action methods', function () {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class);
        $methods = array_map(fn (ReflectionMethod $m): string => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));

        expect($methods)->toContain('glossary');
        expect($methods)->toContain('glossaryMetric');
        expect($methods)->toContain('glossarySearch');
        expect($methods)->toContain('glossaryMetricsForEvent');
        expect($methods)->toContain('glossaryCrossRef');
        expect($methods)->toContain('glossaryCoverage');
        expect($methods)->toContain('glossaryTags');
    });
});
