<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\EventSequencePattern;
use ZeroBoiler\Analytics\DTO\SequenceValueAttribution;
use ZeroBoiler\Analytics\Services\EventSequenceValueAttributionService;

/**
 * Event Sequence Value Attribution Matrix — comprehensive test suite.
 *
 * @since 212.0.0
 *
 * @covers \ZeroBoiler\Analytics\Services\EventSequenceValueAttributionService
 * @covers \ZeroBoiler\Analytics\DTO\SequenceValueAttribution
 */
final class V212EventSequenceValueAttributionTest extends TestCase
{
    private EventSequenceValueAttributionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EventSequenceValueAttributionService();
    }

    // ── SequenceValueAttribution DTO Tests ───────────────────────

    public function test_dto_is_readonly_and_immutable(): void
    {
        $dto = new SequenceValueAttribution(
            sequenceId: 'abc123',
            sequence: ['sign_up', 'trial'],
            occurrences: 100,
            uniqueUsers: 80,
            avgLtv: 250.0,
            totalRevenue: 5000.0,
            conversionRate: 0.45,
            conversionLift: 0.15,
            d7Retention: 0.65,
            d30Retention: 0.40,
            timeToValueSeconds: 86400.0,
            sequenceRoi: 10.0,
            valueGrade: 'A',
            compositeScore: 0.78,
            metadata: ['test' => true],
        );

        $this->assertInstanceOf(SequenceValueAttribution::class, $dto);
        $this->assertSame('abc123', $dto->sequenceId);
        $this->assertSame(['sign_up', 'trial'], $dto->sequence);
        $this->assertSame(100, $dto->occurrences);
        $this->assertSame(80, $dto->uniqueUsers);
        $this->assertSame(250.0, $dto->avgLtv);
        $this->assertSame(5000.0, $dto->totalRevenue);
        $this->assertSame(0.45, $dto->conversionRate);
        $this->assertSame(0.15, $dto->conversionLift);
        $this->assertSame(0.65, $dto->d7Retention);
        $this->assertSame(0.40, $dto->d30Retention);
        $this->assertSame(86400.0, $dto->timeToValueSeconds);
        $this->assertSame(10.0, $dto->sequenceRoi);
        $this->assertSame('A', $dto->valueGrade);
        $this->assertSame(0.78, $dto->compositeScore);
        $this->assertSame(['test' => true], $dto->metadata);
    }

    public function test_dto_to_array_serialization(): void
    {
        $dto = new SequenceValueAttribution(
            sequenceId: 'sha256hash',
            sequence: ['sign_up', 'start_trial', 'purchase'],
            occurrences: 200,
            uniqueUsers: 180,
            avgLtv: 350.50,
            totalRevenue: 12500.75,
            conversionRate: 0.5523,
            conversionLift: 0.2341,
            d7Retention: 0.7123,
            d30Retention: 0.4567,
            timeToValueSeconds: 123456.789,
            sequenceRoi: 15.5,
            valueGrade: 'S',
            compositeScore: 0.9123,
            metadata: ['key' => 'value'],
        );

        $arr = $dto->toArray();

        $this->assertSame('sha256hash', $arr['sequence_id']);
        $this->assertSame(['sign_up', 'start_trial', 'purchase'], $arr['sequence']);
        $this->assertSame(200, $arr['occurrences']);
        $this->assertSame(180, $arr['unique_users']);
        $this->assertSame(350.5, $arr['avg_ltv']); // rounded to 2 decimals
        $this->assertSame(12500.75, $arr['total_revenue']); // rounded to 2 decimals
        $this->assertSame(0.5523, $arr['conversion_rate']); // rounded to 4 decimals
        $this->assertSame(0.2341, $arr['conversion_lift']); // rounded to 4 decimals
        $this->assertSame(0.7123, $arr['d7_retention']); // rounded to 4 decimals
        $this->assertSame(0.4567, $arr['d30_retention']); // rounded to 4 decimals
        $this->assertSame(123456.8, $arr['time_to_value']); // rounded to 1 decimal
        $this->assertSame(15.5, $arr['sequence_roi']); // rounded to 2 decimals
        $this->assertSame('S', $arr['value_grade']);
        $this->assertSame(0.9123, $arr['composite_score']); // rounded to 4 decimals
        $this->assertSame(['key' => 'value'], $arr['metadata']);
    }

    public function test_dto_from_array_round_trip(): void
    {
        $original = new SequenceValueAttribution(
            sequenceId: 'test_id',
            sequence: ['a', 'b', 'c'],
            occurrences: 50,
            uniqueUsers: 40,
            avgLtv: 100.0,
            totalRevenue: 2000.0,
            conversionRate: 0.30,
            conversionLift: 0.10,
            d7Retention: 0.50,
            d30Retention: 0.25,
            timeToValueSeconds: 3600.0,
            sequenceRoi: 8.0,
            valueGrade: 'B',
            compositeScore: 0.55,
            metadata: ['foo' => 'bar'],
        );

        $arr = $original->toArray();
        $restored = SequenceValueAttribution::fromArray($arr);

        $this->assertSame($original->sequenceId, $restored->sequenceId);
        $this->assertSame($original->sequence, $restored->sequence);
        $this->assertSame($original->occurrences, $restored->occurrences);
        $this->assertSame($original->uniqueUsers, $restored->uniqueUsers);
        $this->assertSame($original->valueGrade, $restored->valueGrade);
        $this->assertSame($original->metadata, $restored->metadata);
    }

    public function test_dto_from_array_with_missing_keys(): void
    {
        $dto = SequenceValueAttribution::fromArray([]);

        $this->assertSame('', $dto->sequenceId);
        $this->assertSame([], $dto->sequence);
        $this->assertSame(0, $dto->occurrences);
        $this->assertSame(0, $dto->uniqueUsers);
        $this->assertSame(0.0, $dto->avgLtv);
        $this->assertSame(0.0, $dto->totalRevenue);
        $this->assertSame(0.0, $dto->conversionRate);
        $this->assertSame('C', $dto->valueGrade);
        $this->assertSame([], $dto->metadata);
    }

    public function test_dto_from_array_with_string_sequence(): void
    {
        $dto = SequenceValueAttribution::fromArray([
            'sequence' => 'not_an_array',
        ]);

        $this->assertSame([], $dto->sequence);
    }

    // ── Service: Single Attribution Tests ────────────────────────

    public function test_attribute_high_value_conversion_sequence(): void
    {
        $pattern = new EventSequencePattern(
            id: hash('sha256', 'sign_up|start_trial|feature_used|trial_converted|subscription_created'),
            sequence: ['sign_up', 'start_trial', 'feature_used', 'trial_converted', 'subscription_created'],
            occurrences: 500,
            uniqueUsers: 420,
            averageDurationSeconds: 432000,
            medianDurationSeconds: 345600,
            conversionRate: 0.45,
        );

        $result = $this->service->attribute($pattern);

        $this->assertInstanceOf(SequenceValueAttribution::class, $result);
        $this->assertGreaterThan(0, $result->compositeScore);
        $this->assertGreaterThanOrEqual(0, $result->compositeScore);
        $this->assertLessThanOrEqual(1, $result->compositeScore);
        $this->assertContains($result->valueGrade, ['S', 'A', 'B', 'C', 'D']);
        $this->assertGreaterThan(0, $result->avgLtv);
        $this->assertGreaterThanOrEqual(0, $result->totalRevenue);
        $this->assertSame(0.45, $result->conversionRate);
        $this->assertGreaterThan(0, $result->d7Retention);
        $this->assertGreaterThanOrEqual(0, $result->sequenceRoi);
        $this->assertSame(500, $result->occurrences);
        $this->assertSame(420, $result->uniqueUsers);
        $this->assertCount(5, $result->sequence);
    }

    public function test_attribute_churn_sequence_has_lower_score(): void
    {
        $churnPattern = new EventSequencePattern(
            id: hash('sha256', 'sign_up|start_trial|subscription_created|cancellation'),
            sequence: ['sign_up', 'start_trial', 'subscription_created', 'cancellation'],
            occurrences: 100,
            uniqueUsers: 90,
            averageDurationSeconds: 259200,
            medianDurationSeconds: 200000,
            conversionRate: 0.08,
        );

        $growthPattern = new EventSequencePattern(
            id: hash('sha256', 'sign_up|start_trial|feature_used|trial_converted|plan_upgrade'),
            sequence: ['sign_up', 'start_trial', 'feature_used', 'trial_converted', 'plan_upgrade'],
            occurrences: 300,
            uniqueUsers: 250,
            averageDurationSeconds: 432000,
            medianDurationSeconds: 350000,
            conversionRate: 0.55,
        );

        $churnResult = $this->service->attribute($churnPattern);
        $growthResult = $this->service->attribute($growthPattern);

        // Growth path should score higher than churn path
        $this->assertGreaterThan($churnResult->compositeScore, $growthResult->compositeScore);
    }

    public function test_attribute_with_custom_baselines(): void
    {
        $pattern = new EventSequencePattern(
            id: hash('sha256', 'page_view|view_item|add_to_cart|purchase'),
            sequence: ['page_view', 'view_item', 'add_to_cart', 'purchase'],
            occurrences: 800,
            uniqueUsers: 700,
            averageDurationSeconds: 3600,
            medianDurationSeconds: 2400,
            conversionRate: 0.65,
        );

        $baselines = [
            'baseline_conversion' => 0.10,
            'baseline_ltv' => 200.0,
            'baseline_d7' => 0.50,
            'baseline_d30' => 0.30,
            'avg_acquisition_cost' => 50.0,
        ];

        $result = $this->service->attribute($pattern, $baselines);

        $this->assertInstanceOf(SequenceValueAttribution::class, $result);
        // With 65% conversion and baseline of 10%, lift should be positive
        $this->assertGreaterThan(0, $result->conversionLift);
        // With higher LTV baseline, estimated LTV should be higher
        $this->assertGreaterThan(0, $result->avgLtv);
    }

    public function test_attribute_empty_sequence(): void
    {
        $pattern = new EventSequencePattern(
            id: hash('sha256', ''),
            sequence: [],
            occurrences: 0,
            uniqueUsers: 0,
            averageDurationSeconds: 0.0,
            medianDurationSeconds: 0.0,
            conversionRate: 0.0,
        );

        $result = $this->service->attribute($pattern);

        $this->assertInstanceOf(SequenceValueAttribution::class, $result);
        $this->assertSame([], $result->sequence);
        $this->assertSame(0.0, $result->conversionRate);
    }

    public function test_attribute_single_event_sequence(): void
    {
        $pattern = new EventSequencePattern(
            id: hash('sha256', 'purchase'),
            sequence: ['purchase'],
            occurrences: 200,
            uniqueUsers: 200,
            averageDurationSeconds: 10.0,
            medianDurationSeconds: 8.0,
            conversionRate: 1.0,
        );

        $result = $this->service->attribute($pattern);

        $this->assertGreaterThan(0, $result->compositeScore);
        $this->assertSame(1.0, $result->conversionRate);
        $this->assertSame(['purchase'], $result->sequence);
    }

    // ── Service: Matrix Tests ────────────────────────────────────

    public function test_attribute_matrix_empty_patterns(): void
    {
        $result = $this->service->attributeMatrix([]);

        $this->assertSame([], $result['attributions']);
        $this->assertSame(0, $result['summary']['total_sequences']);
        $this->assertSame(0.0, $result['summary']['avg_score']);
        $this->assertNull($result['summary']['top_path']);
        $this->assertNull($result['summary']['highest_ltv_path']);
        $this->assertNull($result['summary']['fastest_path']);
    }

    public function test_attribute_matrix_ranks_by_composite_score(): void
    {
        $patterns = $this->createSamplePatterns();

        $result = $this->service->attributeMatrix($patterns);

        $this->assertSame(count($patterns), $result['summary']['total_sequences']);
        $this->assertGreaterThan(0, $result['summary']['avg_score']);
        $this->assertNotNull($result['summary']['top_path']);

        // Attributions should be sorted by score descending
        $scores = array_column($result['attributions'], 'composite_score');
        for ($i = 1; $i < count($scores); $i++) {
            $this->assertGreaterThanOrEqual($scores[$i], $scores[$i - 1]);
        }

        // Grade distribution should sum to total
        $grades = $result['summary']['grade_distribution'];
        $this->assertSame(count($patterns), array_sum($grades));
    }

    public function test_attribute_matrix_grade_distribution(): void
    {
        $patterns = $this->createSamplePatterns();

        $result = $this->service->attributeMatrix($patterns);
        $grades = $result['summary']['grade_distribution'];

        // All grades should be present (S, A, B, C, D)
        $this->assertArrayHasKey('S', $grades);
        $this->assertArrayHasKey('A', $grades);
        $this->assertArrayHasKey('B', $grades);
        $this->assertArrayHasKey('C', $grades);
        $this->assertArrayHasKey('D', $grades);

        // Each should be a non-negative integer
        foreach ($grades as $grade => $count) {
            $this->assertIsInt($count);
            $this->assertGreaterThanOrEqual(0, $count);
        }
    }

    public function test_attribute_matrix_identifies_highest_ltv_path(): void
    {
        $patterns = $this->createSamplePatterns();

        $result = $this->service->attributeMatrix($patterns);

        $this->assertNotNull($result['summary']['highest_ltv_path']);
        $this->assertIsString($result['summary']['highest_ltv_path']);
    }

    public function test_attribute_matrix_identifies_fastest_path(): void
    {
        $patterns = $this->createSamplePatterns();

        $result = $this->service->attributeMatrix($patterns);

        $this->assertNotNull($result['summary']['fastest_path']);
        $this->assertIsString($result['summary']['fastest_path']);
    }

    // ── Service: Top Sequences Tests ─────────────────────────────

    public function test_top_value_sequences_returns_correct_count(): void
    {
        $patterns = $this->createSamplePatterns();

        $top5 = $this->service->topValueSequences($patterns, 5);
        $this->assertCount(5, $top5);

        $top3 = $this->service->topValueSequences($patterns, 3);
        $this->assertCount(3, $top3);

        // Top sequences should be in descending order
        for ($i = 1; $i < count($top5); $i++) {
            $this->assertGreaterThanOrEqual($top5[$i]->compositeScore, $top5[$i - 1]->compositeScore);
        }
    }

    public function test_top_value_sequences_all_are_sequence_value_attribution(): void
    {
        $patterns = $this->createSamplePatterns();

        $top = $this->service->topValueSequences($patterns, 3);

        foreach ($top as $attr) {
            $this->assertInstanceOf(SequenceValueAttribution::class, $attr);
        }
    }

    // ── Service: Negative Value Sequences Tests ─────────────────

    public function test_negative_value_sequences_detects_churn_paths(): void
    {
        $patterns = [
            new EventSequencePattern(
                id: hash('sha256', 'subscription_created|cancellation'),
                sequence: ['subscription_created', 'cancellation'],
                occurrences: 100,
                uniqueUsers: 90,
                averageDurationSeconds: 259200,
                medianDurationSeconds: 200000,
                conversionRate: 0.08,
            ),
        ];

        $negatives = $this->service->negativeValueSequences($patterns);

        $this->assertCount(1, $negatives);
        $this->assertSame(['subscription_created', 'cancellation'], $negatives[0]['sequence']);
        $this->assertArrayHasKey('warning', $negatives[0]);
        $this->assertStringContainsString('churn', $negatives[0]['warning']);
    }

    public function test_negative_value_sequences_empty_for_positive_paths(): void
    {
        $patterns = [
            new EventSequencePattern(
                id: hash('sha256', 'sign_up|feature_used'),
                sequence: ['sign_up', 'feature_used'],
                occurrences: 500,
                uniqueUsers: 400,
                averageDurationSeconds: 86400,
                medianDurationSeconds: 72000,
                conversionRate: 0.50,
            ),
        ];

        $negatives = $this->service->negativeValueSequences($patterns);

        $this->assertCount(0, $negatives);
    }

    // ── Service: Comparison Tests ────────────────────────────────

    public function test_compare_high_vs_low_value_sequences(): void
    {
        $patternA = new EventSequencePattern(
            id: hash('sha256', 'sign_up|trial|purchase|plan_upgrade'),
            sequence: ['sign_up', 'start_trial', 'purchase', 'plan_upgrade'],
            occurrences: 500,
            uniqueUsers: 450,
            averageDurationSeconds: 432000,
            medianDurationSeconds: 360000,
            conversionRate: 0.60,
        );

        $patternB = new EventSequencePattern(
            id: hash('sha256', 'sign_up|trial_expired'),
            sequence: ['sign_up', 'trial_expired'],
            occurrences: 200,
            uniqueUsers: 180,
            averageDurationSeconds: 604800,
            medianDurationSeconds: 500000,
            conversionRate: 0.10,
        );

        $comparison = $this->service->compare($patternA, $patternB);

        $this->assertArrayHasKey('sequence_a', $comparison);
        $this->assertArrayHasKey('sequence_b', $comparison);
        $this->assertArrayHasKey('delta', $comparison);
        $this->assertArrayHasKey('recommendation', $comparison);

        $this->assertGreaterThan($comparison['sequence_b']['score'], $comparison['sequence_a']['score']);
        $this->assertGreaterThan(0, $comparison['delta']);
        $this->assertStringContainsString('outperforms', $comparison['recommendation']);
    }

    public function test_compare_similar_sequences(): void
    {
        $patternA = new EventSequencePattern(
            id: hash('sha256', 'a|b|c'),
            sequence: ['sign_up', 'login', 'feature_used'],
            occurrences: 300,
            uniqueUsers: 250,
            averageDurationSeconds: 86400,
            medianDurationSeconds: 72000,
            conversionRate: 0.40,
        );

        $patternB = new EventSequencePattern(
            id: hash('sha256', 'd|e|f'),
            sequence: ['sign_up', 'login', 'search'],
            occurrences: 280,
            uniqueUsers: 230,
            averageDurationSeconds: 90000,
            medianDurationSeconds: 75000,
            conversionRate: 0.38,
        );

        $comparison = $this->service->compare($patternA, $patternB);

        $this->assertArrayHasKey('recommendation', $comparison);
        // With similar scores, delta should be small
        $this->assertLessThan(0.5, abs($comparison['delta']));
    }

    // ── Service: Revenue Multiplier Tests ────────────────────────

    public function test_get_event_revenue_multiplier_known_event(): void
    {
        $this->assertSame(10.0, $this->service->getEventRevenueMultiplier('purchase'));
        $this->assertSame(15.0, $this->service->getEventRevenueMultiplier('subscription_created'));
        $this->assertSame(18.0, $this->service->getEventRevenueMultiplier('plan_upgrade'));
        $this->assertSame(-5.0, $this->service->getEventRevenueMultiplier('cancellation'));
        $this->assertSame(-8.0, $this->service->getEventRevenueMultiplier('subscription_cancelled'));
    }

    public function test_get_event_revenue_multiplier_unknown_event(): void
    {
        $this->assertSame(0.0, $this->service->getEventRevenueMultiplier('nonexistent_event'));
    }

    public function test_get_all_revenue_multipliers(): void
    {
        $multipliers = $this->service->getAllRevenueMultipliers();

        $this->assertIsArray($multipliers);
        $this->assertGreaterThan(30, count($multipliers)); // 33 defined
        $this->assertArrayHasKey('purchase', $multipliers);
        $this->assertArrayHasKey('cancellation', $multipliers);

        // All values should be floats
        foreach ($multipliers as $event => $value) {
            $this->assertIsString($event);
            $this->assertIsFloat($value);
        }
    }

    // ── Service: Weight Configuration Tests ─────────────────────

    public function test_default_weights(): void
    {
        $weights = $this->service->getWeights();

        $this->assertSame(0.30, $weights['ltv']);
        $this->assertSame(0.25, $weights['conversion']);
        $this->assertSame(0.20, $weights['retention']);
        $this->assertSame(0.15, $weights['revenue']);
        $this->assertSame(0.10, $weights['velocity']);
    }

    public function test_custom_weights(): void
    {
        $service = new EventSequenceValueAttributionService(
            cache: null,
            config: null,
            cacheTtl: 600,
            weights: [
                'ltv' => 0.50,
                'conversion' => 0.20,
                'retention' => 0.15,
                'revenue' => 0.10,
                'velocity' => 0.05,
            ],
        );

        $weights = $service->getWeights();

        $this->assertSame(0.50, $weights['ltv']);
        $this->assertSame(0.20, $weights['conversion']);
        $this->assertSame(0.15, $weights['retention']);
        $this->assertSame(0.10, $weights['revenue']);
        $this->assertSame(0.05, $weights['velocity']);
    }

    // ── Service: Value Grade Tests ────────────────────────────────

    public function test_value_grades_for_known_sequences(): void
    {
        // High-value SaaS lifecycle
        $saasPattern = new EventSequencePattern(
            id: hash('sha256', 'sign_up|trial|convert|subscribe|upgrade'),
            sequence: ['sign_up', 'start_trial', 'feature_used', 'trial_converted', 'subscription_created', 'plan_upgrade'],
            occurrences: 300,
            uniqueUsers: 250,
            averageDurationSeconds: 432000,
            medianDurationSeconds: 360000,
            conversionRate: 0.60,
        );

        $result = $this->service->attribute($saasPattern);
        $this->assertContains($result->valueGrade, ['S', 'A', 'B']);

        // Pure churn path
        $churnPattern = new EventSequencePattern(
            id: hash('sha256', 'subscribe|cancel'),
            sequence: ['subscription_created', 'cancellation'],
            occurrences: 50,
            uniqueUsers: 45,
            averageDurationSeconds: 86400,
            medianDurationSeconds: 60000,
            conversionRate: 0.05,
        );

        $churnResult = $this->service->attribute($churnPattern);
        $this->assertContains($churnResult->valueGrade, ['C', 'D']);
    }

    // ── Integration: Full Pipeline Test ──────────────────────────

    public function test_full_pipeline_pattern_to_attribution_to_array(): void
    {
        $pattern = new EventSequencePattern(
            id: hash('sha256', 'sign_up|start_trial|purchase'),
            sequence: ['sign_up', 'start_trial', 'purchase'],
            occurrences: 400,
            uniqueUsers: 350,
            averageDurationSeconds: 172800,
            medianDurationSeconds: 144000,
            conversionRate: 0.50,
        );

        // Attribute
        $attribution = $this->service->attribute($pattern);
        $this->assertInstanceOf(SequenceValueAttribution::class, $attribution);

        // Serialize
        $arr = $attribution->toArray();
        $this->assertIsArray($arr);
        $this->assertArrayHasKey('sequence_id', $arr);
        $this->assertArrayHasKey('composite_score', $arr);
        $this->assertArrayHasKey('value_grade', $arr);
        $this->assertArrayHasKey('avg_ltv', $arr);
        $this->assertArrayHasKey('sequence_roi', $arr);

        // Round-trip
        $restored = SequenceValueAttribution::fromArray($arr);
        $this->assertSame($attribution->sequenceId, $restored->sequenceId);
        $this->assertSame($attribution->valueGrade, $restored->valueGrade);
    }

    public function test_full_matrix_pipeline_with_mixed_sequences(): void
    {
        $patterns = [
            // SaaS growth path
            new EventSequencePattern(
                id: hash('sha256', 's1'),
                sequence: ['sign_up', 'start_trial', 'feature_used', 'trial_converted', 'subscription_created', 'plan_upgrade', 'team_created'],
                occurrences: 80,
                uniqueUsers: 70,
                averageDurationSeconds: 604800,
                medianDurationSeconds: 500000,
                conversionRate: 0.35,
            ),
            // E-commerce full funnel
            new EventSequencePattern(
                id: hash('sha256', 's2'),
                sequence: ['page_view', 'view_item', 'add_to_cart', 'begin_checkout', 'add_payment_info', 'purchase'],
                occurrences: 800,
                uniqueUsers: 700,
                averageDurationSeconds: 3600,
                medianDurationSeconds: 2400,
                conversionRate: 0.65,
            ),
            // Quick churn
            new EventSequencePattern(
                id: hash('sha256', 's3'),
                sequence: ['sign_up', 'start_trial', 'cancellation'],
                occurrences: 150,
                uniqueUsers: 140,
                averageDurationSeconds: 86400,
                medianDurationSeconds: 72000,
                conversionRate: 0.08,
            ),
            // Engagement loop
            new EventSequencePattern(
                id: hash('sha256', 's4'),
                sequence: ['login', 'feature_used', 'feature_used', 'search', 'form_submit'],
                occurrences: 1000,
                uniqueUsers: 500,
                averageDurationSeconds: 1800,
                medianDurationSeconds: 1200,
                conversionRate: 0.80,
            ),
            // Trial expiry
            new EventSequencePattern(
                id: hash('sha256', 's5'),
                sequence: ['sign_up', 'start_trial', 'trial_expired'],
                occurrences: 200,
                uniqueUsers: 190,
                averageDurationSeconds: 604800,
                medianDurationSeconds: 518400,
                conversionRate: 0.10,
            ),
        ];

        $matrix = $this->service->attributeMatrix($patterns);

        // Verify matrix structure
        $this->assertSame(5, $matrix['summary']['total_sequences']);
        $this->assertCount(5, $matrix['attributions']);

        // E-commerce full funnel should rank high (high conversion, revenue events)
        $ecommerceScore = 0;
        $churnScore = 0;
        foreach ($matrix['attributions'] as $attr) {
            if (in_array('purchase', $attr['sequence'], true)) {
                $ecommerceScore = $attr['composite_score'];
            }
            if (in_array('cancellation', $attr['sequence'], true)) {
                $churnScore = $attr['composite_score'];
            }
        }

        $this->assertGreaterThan(0, $ecommerceScore);
        $this->assertGreaterThan($churnScore, $ecommerceScore);

        // Negative sequences should include churn path
        $negatives = $this->service->negativeValueSequences($patterns);
        $this->assertGreaterThan(0, count($negatives));
    }

    // ── PHP 8.5 Syntax & Type Safety Tests ────────────────────────

    public function test_service_declares_strict_types(): void
    {
        $reflection = new \ReflectionClass(EventSequenceValueAttributionService::class);
        $file = $reflection->getFileName();
        $contents = (string) file_get_contents($file);

        $this->assertStringContainsString('declare(strict_types=1)', $contents);
    }

    public function test_dto_declares_strict_types(): void
    {
        $reflection = new \ReflectionClass(SequenceValueAttribution::class);
        $file = $reflection->getFileName();
        $contents = (string) file_get_contents($file);

        $this->assertStringContainsString('declare(strict_types=1)', $contents);
    }

    public function test_service_is_final(): void
    {
        $reflection = new \ReflectionClass(EventSequenceValueAttributionService::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function test_dto_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(SequenceValueAttribution::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_service_constructor_has_return_type_void(): void
    {
        $method = new \ReflectionMethod(EventSequenceValueAttributionService::class, '__construct');
        $this->assertSame('void', $method->getReturnType()?->getName());
    }

    public function test_dto_constructor_has_return_type_void(): void
    {
        $method = new \ReflectionMethod(SequenceValueAttribution::class, '__construct');
        $this->assertSame('void', $method->getReturnType()?->getName());
    }

    public function test_attribute_method_has_return_type(): void
    {
        $method = new \ReflectionMethod(EventSequenceValueAttributionService::class, 'attribute');
        $returnType = $method->getReturnType()?->getName();
        $this->assertSame(SequenceValueAttribution::class, $returnType);
    }

    public function test_attribute_matrix_method_has_return_type(): void
    {
        $method = new \ReflectionMethod(EventSequenceValueAttributionService::class, 'attributeMatrix');
        $returnType = $method->getReturnType()?->getName();
        $this->assertSame('array', $returnType);
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Create sample SaaS event sequence patterns for testing.
     *
     * @return list<EventSequencePattern>
     */
    private function createSamplePatterns(): array
    {
        $sequences = [
            ['sign_up', 'start_trial', 'feature_used', 'trial_converted', 'subscription_created'],
            ['sign_up', 'start_trial', 'feature_used', 'plan_upgrade'],
            ['sign_up', 'start_trial', 'trial_expired'],
            ['page_view', 'view_item', 'add_to_cart', 'begin_checkout', 'add_payment_info', 'purchase'],
            ['page_view', 'view_item', 'add_to_cart', 'begin_checkout', 'abandoned_cart'],
            ['sign_up', 'login', 'feature_used', 'feature_adopted', 'share'],
            ['sign_up', 'start_trial', 'subscription_created', 'cancellation'],
            ['sign_up', 'start_trial', 'feature_used', 'subscription_created', 'plan_upgrade', 'team_created'],
            ['login', 'search', 'view_item', 'add_to_cart', 'purchase'],
            ['sign_up', 'start_trial', 'feature_used', 'feature_used', 'form_submit', 'trial_converted'],
        ];

        $occurrences = [500, 300, 200, 800, 400, 350, 100, 80, 600, 250];
        $uniqueUsers = [420, 260, 180, 700, 350, 310, 90, 70, 520, 220];
        $avgDurations = [432000, 259200, 604800, 3600, 86400, 172800, 259200, 604800, 7200, 345600];
        $convRates = [0.45, 0.55, 0.10, 0.65, 0.20, 0.40, 0.08, 0.35, 0.60, 0.50];

        $patterns = [];

        foreach ($sequences as $i => $seq) {
            $hash = hash('sha256', implode('|', $seq));
            $patterns[] = new EventSequencePattern(
                id: $hash,
                sequence: $seq,
                occurrences: $occurrences[$i],
                uniqueUsers: $uniqueUsers[$i],
                averageDurationSeconds: $avgDurations[$i],
                medianDurationSeconds: $avgDurations[$i] * 0.8,
                conversionRate: $convRates[$i],
            );
        }

        return $patterns;
    }
}
