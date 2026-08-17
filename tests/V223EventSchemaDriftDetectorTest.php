<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Mockery;
use ZeroBoiler\Analytics\DTO\SchemaDriftRecord;
use ZeroBoiler\Analytics\DTO\SchemaMigrationPlan;
use ZeroBoiler\Analytics\Services\EventSchemaDriftDetectorService;

/**
 * Tests for Event Schema Drift Detector & Migration Planner.
 *
 * @covers \ZeroBoiler\Analytics\Services\EventSchemaDriftDetectorService
 * @covers \ZeroBoiler\Analytics\DTO\SchemaDriftRecord
 * @covers \ZeroBoiler\Analytics\DTO\SchemaMigrationPlan
 * @covers \ZeroBoiler\Analytics\DTO\SchemaDriftTrend
 *
 * @since 223.0.0
 */
final class V223EventSchemaDriftDetectorTest extends \PHPUnit\Framework\TestCase
{
    private CacheRepository $cache;

    private ConfigRepository $config;

    /** @var array<string, array<string, mixed>> In-memory cache store */
    private array $cacheStore = [];

    protected function setUp(): void
    {
        $this->cacheStore = [];

        $this->cache = Mockery::mock(CacheRepository::class);
        $this->cache->shouldReceive('put')->andReturnTrue()->byDefault();
        $this->cache->shouldReceive('forget')->andReturnTrue()->byDefault();

        // Cache get returns from in-memory store
        $this->cache->shouldReceive('get')
            ->andReturnUsing(function (string $key) {
                return $this->cacheStore[$key] ?? null;
            });

        // Cache put stores in memory
        $this->cache->shouldReceive('put')
            ->andReturnUsing(function (string $key, mixed $value, ?int $ttl = null): bool {
                $this->cacheStore[$key] = $value;

                return true;
            });

        $this->config = Mockery::mock(ConfigRepository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.schema_drift')
            ->andReturn([
                'max_history_entries' => 20,
                'min_sample_size' => 2,
                'drift_score_threshold' => 0.05,
            ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    // ── Record Observation ─────────────────────────────────────────────

    public function test_record_observation_stores_field_signatures(): void
    {
        $detector = new EventSchemaDriftDetectorService($this->cache, $this->config);

        $detector->recordObservation('purchase', [
            'transaction_id' => 'txn_123',
            'value' => 99.99,
            'currency' => 'USD',
        ], '2026-08-01');

        $snapshot = $this->cacheStore['zb_schema_drift_obs:purchase:2026-08-01'];

        expect($snapshot)->not->toBeNull();
        expect($snapshot['field_count'])->toBe(3);
        expect($snapshot['sample_size'])->toBe(1);
        expect($snapshot['fields']['transaction_id']['type'])->toBe('string');
        expect($snapshot['fields']['value']['type'])->toBe('double');
        expect($snapshot['hash'])->not->toBeEmpty();
    }

    public function test_record_observation_increments_sample_size(): void
    {
        $detector = new EventSchemaDriftDetectorService($this->cache, $this->config);

        $detector->recordObservation('page_view', ['url' => '/home'], '2026-08-01');
        $detector->recordObservation('page_view', ['url' => '/about'], '2026-08-01');

        $snapshot = $this->cacheStore['zb_schema_drift_obs:page_view:2026-08-01'];

        expect($snapshot['sample_size'])->toBe(2);
        expect($snapshot['fields']['url']['example_count'])->toBe(2);
    }

    public function test_record_observation_merges_new_fields(): void
    {
        $detector = new EventSchemaDriftDetectorService($this->cache, $this->config);

        $detector->recordObservation('sign_up', ['method' => 'email'], '2026-08-01');
        $detector->recordObservation('sign_up', ['method' => 'google', 'referral' => 'friend'], '2026-08-01');

        $snapshot = $this->cacheStore['zb_schema_drift_obs:sign_up:2026-08-01'];

        expect($snapshot['field_count'])->toBe(2);
        expect($snapshot['fields']['referral']['type'])->toBe('string');
        expect($snapshot['fields']['method']['example_count'])->toBe(2);
    }

    public function test_record_observation_tracks_nullable(): void
    {
        $detector = new EventSchemaDriftDetectorService($this->cache, $this->config);

        $detector->recordObservation('test_event', ['field_a' => 'value'], '2026-08-01');
        $detector->recordObservation('test_event', ['field_a' => null], '2026-08-01');

        $snapshot = $this->cacheStore['zb_schema_drift_obs:test_event:2026-08-01'];

        expect($snapshot['fields']['field_a']['nullable'])->toBeTrue();
    }

    // ── Record Batch ───────────────────────────────────────────────────

    public function test_record_batch_processes_multiple_events(): void
    {
        $detector = new EventSchemaDriftDetectorService($this->cache, $this->config);

        $events = [
            ['event' => 'purchase', 'params' => ['value' => 10]],
            ['event' => 'page_view', 'params' => ['url' => '/test']],
        ];

        $detector->recordBatch($events, '2026-08-01');

        expect($this->cacheStore['zb_schema_drift_obs:purchase:2026-08-01'])->not->toBeNull();
        expect($this->cacheStore['zb_schema_drift_obs:page_view:2026-08-01'])->not->toBeNull();
    }

    // ── Detect Drift ───────────────────────────────────────────────────

    public function test_detect_drift_no_drift_when_hashes_match(): void
    {
        $detector = new EventSchemaDriftDetectorService($this->cache, $this->config);

        // Same params in both windows = same hash = no drift
        $detector->recordObservation('purchase', ['value' => 10.0, 'currency' => 'USD'], '2026-08-01');
        $detector->recordObservation('purchase', ['value' => 10.0, 'currency' => 'USD'], '2026-08-02');

        $drift = $detector->detectDrift('purchase', '2026-08-01', '2026-08-02');

        expect($drift)->toBeNull();
    }

    public function test_detect_drift_identifies_added_field(): void
    {
        $detector = new EventSchemaDriftDetectorService($this->cache, $this->config);

        $detector->recordObservation('purchase', ['value' => 10.0], '2026-08-01');
        $detector->recordObservation('purchase', ['value' => 10.0], '2026-08-01'); // 2 samples for baseline

        $detector->recordObservation('purchase', ['value' => 10.0, 'coupon' => 'SAVE10'], '2026-08-02');
        $detector->recordObservation('purchase', ['value' => 10.0, 'coupon' => 'SUMMER'], '2026-08-02'); // 2 samples

        $drift = $detector->detectDrift('purchase', '2026-08-01', '2026-08-02');

        expect($drift)->not->toBeNull();
        expect($drift->eventName)->toBe('purchase');
        expect($drift->severity)->toBe('non_breaking');
        expect($drift->changes)->toHaveCount(1);
        expect($drift->changes[0]['type'])->toBe('added');
        expect($drift->changes[0]['field'])->toBe('coupon');
        expect($drift->driftScore)->toBeGreaterThan(0.0);
    }

    public function test_detect_drift_identifies_removed_field(): void
    {
        $detector = new EventSchemaDriftDetectorService($this->cache, $this->config);

        $detector->recordObservation('purchase', ['value' => 10.0, 'shipping' => 5.0], '2026-08-01');
        $detector->recordObservation('purchase', ['value' => 10.0, 'shipping' => 5.0], '2026-08-01');

        $detector->recordObservation('purchase', ['value' => 10.0], '2026-08-02');
        $detector->recordObservation('purchase', ['value' => 10.0], '2026-08-02');

        $drift = $detector->detectDrift('purchase', '2026-08-01', '2026-08-02');

        expect($drift)->not->toBeNull();
        $removed = array_filter($drift->changes, static fn (array $c): bool => $c['type'] === 'removed');
        expect($removed)->not->toBeEmpty();
    }

    public function test_detect_drift_identifies_type_change(): void
    {
        $detector = new EventSchemaDriftDetectorService($this->cache, $this->config);

        $detector->recordObservation('test_event', ['count' => 5], '2026-08-01');
        $detector->recordObservation('test_event', ['count' => 10], '2026-08-01');

        $detector->recordObservation('test_event', ['count' => 'five'], '2026-08-02');
        $detector->recordObservation('test_event', ['count' => 'ten'], '2026-08-02');

        $drift = $detector->detectDrift('test_event', '2026-08-01', '2026-08-02');

        expect($drift)->not->toBeNull();
        $typeChanges = array_filter($drift->changes, static fn (array $c): bool => $c['type'] === 'type_changed');
        expect($typeChanges)->not->toBeEmpty();
    }

    public function test_detect_drift_returns_null_for_insufficient_samples(): void
    {
        $detector = new EventSchemaDriftDetectorService($this->cache, $this->config);

        $detector->recordObservation('purchase', ['value' => 10.0], '2026-08-01');
        // Only 1 sample — below min_sample_size of 2

        $detector->recordObservation('purchase', ['value' => 10.0, 'new_field' => 'x'], '2026-08-02');

        $drift = $detector->detectDrift('purchase', '2026-08-01', '2026-08-02');

        expect($drift)->toBeNull();
    }

    public function test_detect_drift_returns_null_for_missing_windows(): void
    {
        $detector = new EventSchemaDriftDetectorService($this->cache, $this->config);

        $drift = $detector->detectDrift('nonexistent', '2026-08-01', '2026-08-02');

        expect($drift)->toBeNull();
    }

    // ── Detect Drift All ──────────────────────────────────────────────

    public function test_detect_drift_all_scans_all_events(): void
    {
        $detector = new EventSchemaDriftDetectorService($this->cache, $this->config);

        // Seed baseline data for purchase
        $detector->recordObservation('purchase', ['value' => 10.0], '2026-08-01');
        $detector->recordObservation('purchase', ['value' => 10.0], '2026-08-01');

        // Seed drifted data for purchase (added field)
        $detector->recordObservation('purchase', ['value' => 10.0, 'tax' => 1.0], '2026-08-02');
        $detector->recordObservation('purchase', ['value' => 10.0, 'tax' => 2.0], '2026-08-02');

        $drifts = $detector->detectDriftAll('2026-08-01', '2026-08-02');

        expect($drifts)->not->toBeEmpty();
        $purchaseDrifts = array_filter($drifts, static fn (SchemaDriftRecord $d): bool => $d->eventName === 'purchase');
        expect($purchaseDrifts)->not->toBeEmpty();
    }

    // ── Schema Drift Record DTO ───────────────────────────────────────

    public function test_drift_record_to_array(): void
    {
        $record = new SchemaDriftRecord(
            eventName: 'purchase',
            baselineSnapshot: 'abc123',
            currentSnapshot: 'def456',
            changes: [
                ['field' => 'coupon', 'type' => 'added', 'severity' => 'non_breaking', 'details' => [], 'migration_hint' => 'Add default handling'],
            ],
            severity: 'non_breaking',
            driftScore: 0.15,
            totalFieldsBaseline: 3,
            totalFieldsCurrent: 4,
            detectedAt: new \DateTimeImmutable('2026-08-17 12:00:00'),
            sampleSizeBaseline: 10,
            sampleSizeCurrent: 10,
            affectedProviders: ['ga4', 'meta'],
        );

        $arr = $record->toArray();

        expect($arr['event_name'])->toBe('purchase');
        expect($arr['severity'])->toBe('non_breaking');
        expect($arr['drift_score'])->toBe(0.15);
        expect($arr['affected_providers'])->toBe(['ga4', 'meta']);
        expect($arr['changes'])->toHaveCount(1);
    }

    // ── Migration Plan ────────────────────────────────────────────────

    public function test_generate_migration_plan_produces_ordered_steps(): void
    {
        $detector = new EventSchemaDriftDetectorService($this->cache, $this->config);

        $drift = new SchemaDriftRecord(
            eventName: 'purchase',
            baselineSnapshot: 'abc',
            currentSnapshot: 'def',
            changes: [
                [
                    'field' => 'tax',
                    'type' => 'added',
                    'severity' => 'non_breaking',
                    'details' => ['new_type' => 'double'],
                    'migration_hint' => 'Handle new tax field',
                ],
            ],
            severity: 'non_breaking',
            driftScore: 0.2,
            totalFieldsBaseline: 2,
            totalFieldsCurrent: 3,
            detectedAt: new \DateTimeImmutable(),
            sampleSizeBaseline: 10,
            sampleSizeCurrent: 10,
            affectedProviders: ['ga4'],
        );

        $plan = $detector->generateMigrationPlan($drift);

        expect($plan)->toBeInstanceOf(SchemaMigrationPlan::class);
        expect($plan->eventName)->toBe('purchase');
        expect($plan->steps)->not->toBeEmpty();
        expect($plan->riskLevel)->toBe('low');
        expect($plan->rollbackStrategy)->not->toBeNull();
        expect($plan->hasBreakingChanges())->toBeFalse();
    }

    public function test_migration_plan_has_breaking_changes(): void
    {
        $detector = new EventSchemaDriftDetectorService($this->cache, $this->config);

        $drift = new SchemaDriftRecord(
            eventName: 'purchase',
            baselineSnapshot: 'abc',
            currentSnapshot: 'def',
            changes: [
                [
                    'field' => 'transaction_id',
                    'type' => 'removed',
                    'severity' => 'breaking',
                    'details' => ['old_type' => 'string', 'was_nullable' => false],
                    'migration_hint' => 'BREAKING: required field removed',
                ],
            ],
            severity: 'breaking',
            driftScore: 0.8,
            totalFieldsBaseline: 3,
            totalFieldsCurrent: 2,
            detectedAt: new \DateTimeImmutable(),
            sampleSizeBaseline: 50,
            sampleSizeCurrent: 50,
            affectedProviders: ['ga4', 'meta', 'posthog'],
        );

        $plan = $detector->generateMigrationPlan($drift);

        expect($plan->hasBreakingChanges())->toBeTrue();
        expect($plan->riskLevel)->toBe('critical');
        expect($plan->criticalSteps())->not->toBeEmpty();
        expect($plan->prerequisites)->not->toBeEmpty();
    }

    public function test_migration_plan_to_array(): void
    {
        $plan = new SchemaMigrationPlan(
            eventName: 'purchase',
            driftId: 'abc:def',
            steps: [
                ['action' => 'add_default', 'field' => 'tax', 'description' => 'Add tax field', 'code_example' => null, 'urgency' => 'medium', 'affected_consumers' => ['etl']],
            ],
            riskLevel: 'low',
            estimatedImpactConsumers: 2,
            rollbackStrategy: 'Non-breaking — no rollback needed',
            prerequisites: ['Update docs'],
            generatedAt: new \DateTimeImmutable('2026-08-17 12:00:00'),
        );

        $arr = $plan->toArray();

        expect($arr['event_name'])->toBe('purchase');
        expect($arr['has_breaking_changes'])->toBeFalse();
        expect($arr['critical_steps_count'])->toBe(0);
        expect($arr['steps'])->toHaveCount(1);
    }

    // ── Schema Drift Trend ─────────────────────────────────────────────

    public function test_analyze_trend_stable_event(): void
    {
        $detector = new EventSchemaDriftDetectorService($this->cache, $this->config);

        // Same schema across 3 days = stable
        for ($day = 0; $day < 3; $day++) {
            $window = date('Y-m-d', strtotime("-{$day} days"));
            $detector->recordObservation('page_view', ['url' => '/test', 'title' => 'Test'], $window);
            $detector->recordObservation('page_view', ['url' => '/home', 'title' => 'Home'], $window);
        }

        $trend = $detector->analyzeTrend('page_view', 3);

        expect($trend->eventName)->toBe('page_view');
        expect($trend->totalDriftsDetected)->toBe(0);
        expect($trend->stabilityGrade)->toBe('A');
        expect($trend->recommendations)->not->toBeEmpty();
    }

    public function test_analyze_trend_to_array(): void
    {
        $detector = new EventSchemaDriftDetectorService($this->cache, $this->config);

        $trend = $detector->analyzeTrend('nonexistent', 3);

        $arr = $trend->toArray();

        expect($arr['event_name'])->toBe('nonexistent');
        expect($arr['stability_grade'])->toBeString();
        expect($arr['window_history'])->toBeArray();
        expect($arr['recommendations'])->toBeArray();
    }

    // ── Drift Summary ─────────────────────────────────────────────────

    public function test_drift_summary_structure(): void
    {
        $detector = new EventSchemaDriftDetectorService($this->cache, $this->config);

        // Seed some data
        $detector->recordObservation('purchase', ['value' => 10.0], '2026-08-01');
        $detector->recordObservation('purchase', ['value' => 10.0], '2026-08-01');
        $detector->recordObservation('purchase', ['value' => 10.0, 'new' => true], '2026-08-02');
        $detector->recordObservation('purchase', ['value' => 10.0, 'new' => true], '2026-08-02');

        $summary = $detector->driftSummary('2026-08-01', '2026-08-02');

        expect($summary)->toHaveKey('total_events');
        expect($summary)->toHaveKey('drifted_events');
        expect($summary)->toHaveKey('stable_events');
        expect($summary)->toHaveKey('breaking_count');
        expect($summary)->toHaveKey('non_breaking_count');
        expect($summary)->toHaveKey('avg_drift_score');
        expect($summary)->toHaveKey('most_drifted');
        expect($summary)->toHaveKey('drifts');
        expect($summary['total_events'])->toBeGreaterThan(0);
    }

    // ── Schema Drift Trend DTO ────────────────────────────────────────

    public function test_schema_drift_trend_to_array(): void
    {
        $trend = new \ZeroBoiler\Analytics\DTO\SchemaDriftTrend(
            eventName: 'purchase',
            observationWindows: 7,
            totalDriftsDetected: 0,
            driftFrequency: 0.0,
            instabilityScore: 0.0,
            stabilityGrade: 'A',
            windowHistory: [
                ['window' => '2026-08-10', 'snapshot' => 'abc', 'field_count' => 3, 'drift_score' => 0.0],
            ],
            topChangedFields: [],
            recommendations: ['Stable event — no action needed.'],
        );

        $arr = $trend->toArray();

        expect($arr['event_name'])->toBe('purchase');
        expect($arr['stability_grade'])->toBe('A');
        expect($arr['recommendations'])->toContain('Stable event — no action needed.');
    }

    // ── Service Configuration ─────────────────────────────────────────

    public function test_service_config_accessors(): void
    {
        $detector = new EventSchemaDriftDetectorService($this->cache, $this->config);

        expect($detector->getMinSampleSize())->toBe(2);
        expect($detector->getDriftScoreThreshold())->toBe(0.05);
        expect($detector->getProviders())->not->toBeEmpty();
        expect(in_array('ga4', $detector->getProviders(), true))->toBeTrue();
        expect(in_array('posthog', $detector->getProviders(), true))->toBeTrue();
    }

    // ── Edge Cases ────────────────────────────────────────────────────

    public function test_empty_params_produces_no_fields(): void
    {
        $detector = new EventSchemaDriftDetectorService($this->cache, $this->config);

        $detector->recordObservation('empty_event', [], '2026-08-01');
        $detector->recordObservation('empty_event', [], '2026-08-01');

        $snapshot = $this->cacheStore['zb_schema_drift_obs:empty_event:2026-08-01'];

        expect($snapshot['field_count'])->toBe(0);
        expect($snapshot['hash'])->not->toBeEmpty();
    }

    public function test_drift_between_empty_and_nonempty(): void
    {
        $detector = new EventSchemaDriftDetectorService($this->cache, $this->config);

        $detector->recordObservation('evolving', [], '2026-08-01');
        $detector->recordObservation('evolving', [], '2026-08-01');

        $detector->recordObservation('evolving', ['new_field' => 'value'], '2026-08-02');
        $detector->recordObservation('evolving', ['new_field' => 'value'], '2026-08-02');

        $drift = $detector->detectDrift('evolving', '2026-08-01', '2026-08-02');

        expect($drift)->not->toBeNull();
        expect($drift->totalFieldsBaseline)->toBe(0);
        expect($drift->totalFieldsCurrent)->toBe(1);
    }

    public function test_detect_drift_null_baseline_returns_null(): void
    {
        $detector = new EventSchemaDriftDetectorService($this->cache, $this->config);

        $drift = $detector->detectDrift('missing', '2026-08-01', '2026-08-02');

        expect($drift)->toBeNull();
    }

    public function test_type_union_tracking_across_observations(): void
    {
        $detector = new EventSchemaDriftDetectorService($this->cache, $this->config);

        $detector->recordObservation('flexible', ['amount' => 100], '2026-08-01');
        $detector->recordObservation('flexible', ['amount' => 'hundred'], '2026-08-01');

        $snapshot = $this->cacheStore['zb_schema_drift_obs:flexible:2026-08-01'];

        expect($snapshot['fields']['amount']['type'])->toBe('int|string');
    }
}
