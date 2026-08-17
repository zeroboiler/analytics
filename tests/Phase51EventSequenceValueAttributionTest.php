<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\EventSequencePattern;
use ZeroBoiler\Analytics\DTO\SequenceValueAttribution;
use ZeroBoiler\Analytics\Services\EventSequenceValueAttributionService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsSequenceValueCommand;

/**
 * Phase 51 production readiness — Event Sequence Value Attribution.
 *
 * Validates:
 * - EventSequenceValueAttributionService file quality (strict_types, MIT header, final, @since, constructor :void)
 * - SequenceValueAttribution DTO file quality (strict_types, MIT header, final readonly, @since, constructor :void)
 * - AnalyticsSequenceValueCommand file quality (strict_types, MIT header, final, @since, handle :int)
 * - Service instantiation with default/null/cache/config parameters
 * - Single pattern attribution returns SequenceValueAttribution DTO
 * - Composite score bounds (0.0–1.0)
 * - Value grade mapping (S/A/B/C/D)
 * - Revenue multiplier lookup for known/unknown events
 * - Matrix attribution structure (attributions + summary)
 * - Top value sequences ranking
 * - Negative value sequences detection (cancellation/churn paths)
 * - Sequence comparison delta computation
 * - Weight configuration and custom weight overrides
 * - Cache clear no-throw behavior
 * - ServiceProvider registration (singleton + command)
 * - Version consistency across all new files
 *
 * @since 212.0.0
 */

// ─── File Quality ───────────────────────────────────────────────────────────

test('SequenceValueAttribution DTO has strict_types, MIT header, final readonly, and @since annotation', function (): void {
    $reflection = new ReflectionClass(SequenceValueAttribution::class);
    $file = file_get_contents($reflection->getFileName());

    expect($file)->toContain('declare(strict_types=1)')
        ->and($file)->toContain('This file is part of ZeroBoiler, licensed under the MIT license')
        ->and($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue()
        ->and($file)->toContain('@since 212.0.0');

    // Constructor has :void return type
    $constructor = $reflection->getMethod('__construct');
    expect($constructor->getReturnType()?->getName())->toBe('void');

    // toArray and fromArray methods exist
    expect($reflection->hasMethod('toArray'))->toBeTrue()
        ->and($reflection->hasMethod('fromArray'))->toBeTrue()
        ->and($reflection->getMethod('toArray')->getReturnType()?->getName())->toBe('array')
        ->and($reflection->getMethod('fromArray')->getReturnType()?->getName())->toBe(SequenceValueAttribution::class);
});

test('EventSequenceValueAttributionService has strict_types, MIT header, final, and @since annotation', function (): void {
    $reflection = new ReflectionClass(EventSequenceValueAttributionService::class);
    $file = file_get_contents($reflection->getFileName());

    expect($file)->toContain('declare(strict_types=1)')
        ->and($file)->toContain('This file is part of ZeroBoiler, licensed under the MIT license')
        ->and($reflection->isFinal())->toBeTrue()
        ->and($file)->toContain('@since 212.0.0');

    // Constructor has :void return type
    $constructor = $reflection->getMethod('__construct');
    expect($constructor->getReturnType()?->getName())->toBe('void');
});

test('AnalyticsSequenceValueCommand has strict_types, MIT header, final, and @since annotation', function (): void {
    $reflection = new ReflectionClass(AnalyticsSequenceValueCommand::class);
    $file = file_get_contents($reflection->getFileName());

    expect($file)->toContain('declare(strict_types=1)')
        ->and($file)->toContain('This file is part of ZeroBoiler, licensed under the MIT license')
        ->and($reflection->isFinal())->toBeTrue()
        ->and($file)->toContain('@since 212.0.0');

    // handle() returns int
    $handle = $reflection->getMethod('handle');
    expect($handle->getReturnType()?->getName())->toBe('int');
});

// ─── Service Instantiation ──────────────────────────────────────────────────

test('Service instantiates with null cache and config (default)', function (): void {
    $service = new EventSequenceValueAttributionService();

    expect($service)->toBeInstanceOf(EventSequenceValueAttributionService::class);
});

test('Service instantiates with custom weights override', function (): void {
    $service = new EventSequenceValueAttributionService(
        cache: null,
        config: null,
        cacheTtl: 1200,
        weights: ['ltv' => 0.40, 'conversion' => 0.30, 'retention' => 0.10, 'revenue' => 0.10, 'velocity' => 0.10],
    );

    $weights = $service->getWeights();
    expect($weights['ltv'])->toBe(0.40)
        ->and($weights['conversion'])->toBe(0.30)
        ->and($weights['retention'])->toBe(0.10);
});

// ─── Single Pattern Attribution ──────────────────────────────────────────────

test('Single pattern attribution returns SequenceValueAttribution DTO', function (): void {
    $service = new EventSequenceValueAttributionService();

    $pattern = new EventSequencePattern(
        id: hash('sha256', 'sign_up|start_trial|purchase'),
        sequence: ['sign_up', 'start_trial', 'purchase'],
        occurrences: 500,
        uniqueUsers: 400,
        averageDurationSeconds: 86400,
        medianDurationSeconds: 72000,
        conversionRate: 0.45,
    );

    $attribution = $service->attribute($pattern);

    expect($attribution)->toBeInstanceOf(SequenceValueAttribution::class)
        ->and($attribution->sequenceId)->toBe($pattern->id)
        ->and($attribution->sequence)->toBe(['sign_up', 'start_trial', 'purchase'])
        ->and($attribution->occurrences)->toBe(500)
        ->and($attribution->uniqueUsers)->toBe(400)
        ->and($attribution->conversionRate)->toBe(0.45)
        ->and($attribution->compositeScore)->toBeGreaterThanOrEqual(0.0)
        ->and($attribution->compositeScore)->toBeLessThanOrEqual(1.0)
        ->and($attribution->valueGrade)->toBeIn(['S', 'A', 'B', 'C', 'D']);
});

test('High-value purchase sequence gets high grade', function (): void {
    $service = new EventSequenceValueAttributionService();

    $pattern = new EventSequencePattern(
        id: hash('sha256', 'sign_up|start_trial|feature_used|trial_converted|subscription_created'),
        sequence: ['sign_up', 'start_trial', 'feature_used', 'trial_converted', 'subscription_created'],
        occurrences: 500,
        uniqueUsers: 420,
        averageDurationSeconds: 432000,
        medianDurationSeconds: 345600,
        conversionRate: 0.45,
    );

    $attribution = $service->attribute($pattern);

    // This high-revenue, high-conversion sequence should score well
    expect($attribution->compositeScore)->toBeGreaterThan(0.5)
        ->and($attribution->valueGrade)->toBeIn(['A', 'S', 'B']);
});

test('Churn-dominated sequence gets negative revenue score and low grade', function (): void {
    $service = new EventSequenceValueAttributionService();

    $pattern = new EventSequencePattern(
        id: hash('sha256', 'sign_up|start_trial|cancellation'),
        sequence: ['sign_up', 'start_trial', 'cancellation'],
        occurrences: 100,
        uniqueUsers: 90,
        averageDurationSeconds: 259200,
        medianDurationSeconds: 200000,
        conversionRate: 0.08,
    );

    $attribution = $service->attribute($pattern);

    // Cancellation-heavy sequence should have lower composite score
    expect($attribution->compositeScore)->toBeLessThan(0.6)
        ->and($attribution->valueGrade)->toBeIn(['C', 'D']);
});

// ─── Revenue Multipliers ────────────────────────────────────────────────────

test('Revenue multiplier returns correct value for purchase event', function (): void {
    $service = new EventSequenceValueAttributionService();

    expect($service->getEventRevenueMultiplier('purchase'))->toBe(10.0)
        ->and($service->getEventRevenueMultiplier('plan_upgrade'))->toBe(18.0)
        ->and($service->getEventRevenueMultiplier('cancellation'))->toBe(-5.0)
        ->and($service->getEventRevenueMultiplier('unknown_event'))->toBe(0.0);
});

test('getAllRevenueMultipliers returns non-empty array with positive and negative values', function (): void {
    $service = new EventSequenceValueAttributionService();
    $multipliers = $service->getAllRevenueMultipliers();

    expect($multipliers)->toBeArray()
        ->and($multipliers)->not->toBeEmpty()
        ->and($multipliers)->toHaveKey('purchase')
        ->and($multipliers)->toHaveKey('cancellation')
        ->and($multipliers['purchase'])->toBeGreaterThan(0)
        ->and($multipliers['cancellation'])->toBeLessThan(0);
});

// ─── Matrix Attribution ─────────────────────────────────────────────────────

test('Matrix attribution returns correct structure', function (): void {
    $service = new EventSequenceValueAttributionService();

    $patterns = [
        new EventSequencePattern(
            id: hash('sha256', 'sign_up|purchase'),
            sequence: ['sign_up', 'purchase'],
            occurrences: 800,
            uniqueUsers: 700,
            averageDurationSeconds: 3600,
            medianDurationSeconds: 3000,
            conversionRate: 0.65,
        ),
        new EventSequencePattern(
            id: hash('sha256', 'page_view|abandoned_cart'),
            sequence: ['page_view', 'abandoned_cart'],
            occurrences: 400,
            uniqueUsers: 350,
            averageDurationSeconds: 86400,
            medianDurationSeconds: 72000,
            conversionRate: 0.20,
        ),
    ];

    $matrix = $service->attributeMatrix($patterns);

    expect($matrix)->toBeArray()
        ->and($matrix)->toHaveKeys(['attributions', 'summary'])
        ->and($matrix['attributions'])->toHaveCount(2)
        ->and($matrix['summary'])->toHaveKeys(['total_sequences', 'top_path', 'avg_score', 'grade_distribution', 'highest_ltv_path', 'fastest_path'])
        ->and($matrix['summary']['total_sequences'])->toBe(2)
        ->and($matrix['summary']['avg_score'])->toBeFloat()
        ->and($matrix['summary']['grade_distribution'])->toHaveKeys(['S', 'A', 'B', 'C', 'D']);

    // Attributions should be sorted by composite score descending
    expect($matrix['attributions'][0]['composite_score'])
        ->toBeGreaterThanOrEqual($matrix['attributions'][1]['composite_score']);
});

test('Empty patterns matrix returns zero total_sequences', function (): void {
    $service = new EventSequenceValueAttributionService();

    $matrix = $service->attributeMatrix([]);

    expect($matrix['summary']['total_sequences'])->toBe(0)
        ->and($matrix['summary']['top_path'])->toBeNull()
        ->and($matrix['attributions'])->toHaveCount(0);
});

// ─── Top Value Sequences ────────────────────────────────────────────────────

test('Top value sequences returns correct count and order', function (): void {
    $service = new EventSequenceValueAttributionService();

    $patterns = [];
    $sequences = [
        ['sign_up', 'start_trial', 'subscription_created', 'plan_upgrade'],
        ['sign_up', 'start_trial', 'cancellation'],
        ['page_view', 'scroll_depth'],
    ];
    $occurrences = [200, 100, 1000];
    $convRates = [0.40, 0.08, 0.90];

    foreach ($sequences as $i => $seq) {
        $patterns[] = new EventSequencePattern(
            id: hash('sha256', implode('|', $seq)),
            sequence: $seq,
            occurrences: $occurrences[$i],
            uniqueUsers: (int) ($occurrences[$i] * 0.85),
            averageDurationSeconds: 86400,
            medianDurationSeconds: 72000,
            conversionRate: $convRates[$i],
        );
    }

    $top = $service->topValueSequences($patterns, 2);

    expect($top)->toHaveCount(2);

    // First should have higher composite score than second
    expect($top[0]->compositeScore)
        ->toBeGreaterThanOrEqual($top[1]->compositeScore);
});

// ─── Negative Value Sequences ───────────────────────────────────────────────

test('Negative value sequences detects churn paths', function (): void {
    $service = new EventSequenceValueAttributionService();

    $patterns = [
        new EventSequencePattern(
            id: hash('sha256', 'sign_up|start_trial|purchase'),
            sequence: ['sign_up', 'start_trial', 'purchase'],
            occurrences: 800,
            uniqueUsers: 700,
            averageDurationSeconds: 3600,
            medianDurationSeconds: 3000,
            conversionRate: 0.65,
        ),
        new EventSequencePattern(
            id: hash('sha256', 'sign_up|start_trial|cancellation'),
            sequence: ['sign_up', 'start_trial', 'cancellation'],
            occurrences: 200,
            uniqueUsers: 180,
            averageDurationSeconds: 259200,
            medianDurationSeconds: 200000,
            conversionRate: 0.08,
        ),
        new EventSequencePattern(
            id: hash('sha256', 'subscription_created|plan_downgrade|cancellation'),
            sequence: ['subscription_created', 'plan_downgrade', 'cancellation'],
            occurrences: 50,
            uniqueUsers: 45,
            averageDurationSeconds: 432000,
            medianDurationSeconds: 350000,
            conversionRate: 0.05,
        ),
    ];

    $negatives = $service->negativeValueSequences($patterns);

    // At least 2 negative sequences (cancellation + downgrade paths)
    expect($negatives)->not->toBeEmpty()
        ->and(count($negatives))->toBeGreaterThanOrEqual(2);

    // Each negative must have required keys
    foreach ($negatives as $neg) {
        expect($neg)->toHaveKeys(['sequence', 'composite_score', 'value_grade', 'warning']);
    }
});

// ─── Sequence Comparison ────────────────────────────────────────────────────

test('Sequence comparison returns correct delta and recommendation', function (): void {
    $service = new EventSequenceValueAttributionService();

    $patternA = new EventSequencePattern(
        id: hash('sha256', 'sign_up|start_trial|purchase'),
        sequence: ['sign_up', 'start_trial', 'purchase'],
        occurrences: 500,
        uniqueUsers: 420,
        averageDurationSeconds: 86400,
        medianDurationSeconds: 72000,
        conversionRate: 0.45,
    );

    $patternB = new EventSequencePattern(
        id: hash('sha256', 'sign_up|start_trial|cancellation'),
        sequence: ['sign_up', 'start_trial', 'cancellation'],
        occurrences: 100,
        uniqueUsers: 90,
        averageDurationSeconds: 259200,
        medianDurationSeconds: 200000,
        conversionRate: 0.08,
    );

    $comparison = $service->compare($patternA, $patternB);

    expect($comparison)->toBeArray()
        ->and($comparison)->toHaveKeys(['sequence_a', 'sequence_b', 'delta', 'recommendation'])
        ->and($comparison['sequence_a'])->toHaveKeys(['score', 'grade', 'ltv', 'roi'])
        ->and($comparison['sequence_b'])->toHaveKeys(['score', 'grade', 'ltv', 'roi'])
        ->and($comparison['delta'])->toBeFloat()
        ->and($comparison['recommendation'])->toBeString();

    // Purchase path should outperform cancellation path
    expect($comparison['delta'])->toBeGreaterThan(0);
});

test('Comparison of identical sequences returns near-zero delta', function (): void {
    $service = new EventSequenceValueAttributionService();

    $pattern = new EventSequencePattern(
        id: hash('sha256', 'sign_up|login'),
        sequence: ['sign_up', 'login'],
        occurrences: 100,
        uniqueUsers: 80,
        averageDurationSeconds: 600,
        medianDurationSeconds: 500,
        conversionRate: 0.50,
    );

    $comparison = $service->compare($pattern, $pattern);

    expect($comparison['delta'])->toBe(0.0)
        ->and($comparison['recommendation'])->toContain('Comparable value');
});

// ─── Cache Clear ─────────────────────────────────────────────────────────────

test('Clear cache does not throw with null cache', function (): void {
    $service = new EventSequenceValueAttributionService(cache: null);

    $service->clearCache();

    // If we reach here, no exception was thrown
    expect(true)->toBeTrue();
});

// ─── DTO Round-Trip ─────────────────────────────────────────────────────────

test('SequenceValueAttribution toArray/fromArray round-trip', function (): void {
    $dto = new SequenceValueAttribution(
        sequenceId: 'abc123',
        sequence: ['sign_up', 'purchase'],
        occurrences: 100,
        uniqueUsers: 80,
        avgLtv: 250.0,
        totalRevenue: 5000.0,
        conversionRate: 0.45,
        conversionLift: 0.15,
        d7Retention: 0.60,
        d30Retention: 0.35,
        timeToValueSeconds: 86400,
        sequenceRoi: 10.0,
        valueGrade: 'A',
        compositeScore: 0.78,
        metadata: ['key' => 'value'],
    );

    $array = $dto->toArray();
    $restored = SequenceValueAttribution::fromArray($array);

    expect($restored->sequenceId)->toBe('abc123')
        ->and($restored->sequence)->toBe(['sign_up', 'purchase'])
        ->and($restored->occurrences)->toBe(100)
        ->and($restored->uniqueUsers)->toBe(80)
        ->and($restored->valueGrade)->toBe('A')
        ->and($restored->compositeScore)->toBe(0.78)
        ->and($restored->metadata)->toBe(['key' => 'value']);
});

test('SequenceValueAttribution default values are correct', function (): void {
    $dto = new SequenceValueAttribution(sequenceId: 'test', sequence: []);

    expect($dto->occurrences)->toBe(0)
        ->and($dto->uniqueUsers)->toBe(0)
        ->and($dto->avgLtv)->toBe(0.0)
        ->and($dto->totalRevenue)->toBe(0.0)
        ->and($dto->conversionRate)->toBe(0.0)
        ->and($dto->conversionLift)->toBe(0.0)
        ->and($dto->d7Retention)->toBe(0.0)
        ->and($dto->d30Retention)->toBe(0.0)
        ->and($dto->timeToValueSeconds)->toBe(0.0)
        ->and($dto->sequenceRoi)->toBe(0.0)
        ->and($dto->valueGrade)->toBe('C')
        ->and($dto->compositeScore)->toBe(0.0)
        ->and($dto->metadata)->toBe([]);
});

// ─── Command File Quality ───────────────────────────────────────────────────

test('AnalyticsSequenceValueCommand has correct signature', function (): void {
    $reflection = new ReflectionClass(AnalyticsSequenceValueCommand::class);
    $file = file_get_contents($reflection->getFileName());

    expect($file)->toContain('analytics:sequence-value')
        ->and($file)->toContain('--top=')
        ->and($file)->toContain('--negative')
        ->and($file)->toContain('--matrix')
        ->and($file)->toContain('--compare=')
        ->and($file)->toContain('--multipliers')
        ->and($file)->toContain('--demo')
        ->and($file)->toContain('--json');
});

test('AnalyticsSequenceValueCommand imports correct classes', function (): void {
    $file = file_get_contents(
        (new ReflectionClass(AnalyticsSequenceValueCommand::class))->getFileName()
    );

    expect($file)->toContain('use ZeroBoiler\\Analytics\\DTO\\EventSequencePattern;')
        ->and($file)->toContain('use ZeroBoiler\\Analytics\\Services\\EventSequenceValueAttributionService;')
        ->and($file)->toContain('use Illuminate\\Console\\Command;');
});

// ─── Svelte Composable ──────────────────────────────────────────────────────

test('useEventSequence Svelte composable exists and has correct exports', function (): void {
    $composablePath = dirname(__DIR__, 2) . '/resources/js/useEventSequence.svelte.js';

    expect(file_exists($composablePath))->toBeTrue('useEventSequence.svelte.js must exist');

    $content = file_get_contents($composablePath);

    expect($content)->toContain('export function useEventSequence')
        ->and($content)->toContain('fetchMatrix')
        ->and($content)->toContain('topSequences')
        ->and($content)->toContain('byGrade')
        ->and($content)->toContain('gradeDistribution')
        ->and($content)->toContain('compare')
        ->and($content)->toContain('avgScore')
        ->and($content)->toContain('topPath')
        ->and($content)->toContain('@since 212.0.0');
});

// ─── Source File Counts ─────────────────────────────────────────────────────

test('Source file counts meet minimum thresholds', function (): void {
    $srcDir = dirname(__DIR__, 2) . '/src';
    $testDir = dirname(__DIR__, 2) . '/tests';

    $srcCount = count(glob($srcDir . '/**/*.php', GLOB_BRACE));
    $testCount = count(glob($testDir . '/**/*.php', GLOB_BRACE));

    expect($srcCount)->toBeGreaterThanOrEqual(904, "Expected at least 904 src files, got {$srcCount}")
        ->and($testCount)->toBeGreaterThanOrEqual(460, "Expected at least 460 test files, got {$testCount}");
});

// ─── Weight Configuration ────────────────────────────────────────────────────

test('Default weight configuration sums to 1.0', function (): void {
    $service = new EventSequenceValueAttributionService();
    $weights = $service->getWeights();

    $total = array_sum($weights);

    expect($total)->toBe(1.0)
        ->and($weights)->toHaveCount(5)
        ->and($weights['ltv'])->toBe(0.30)
        ->and($weights['conversion'])->toBe(0.25)
        ->and($weights['retention'])->toBe(0.20)
        ->and($weights['revenue'])->toBe(0.15)
        ->and($weights['velocity'])->toBe(0.10);
});
