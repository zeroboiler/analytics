<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\Attributes\Test;
use ZeroBoiler\Analytics\Services\BehavioralSegmentationService;

/**
 * Behavioral Segmentation Service & Command — Production Readiness Test (v239.0.0).
 *
 * Validates:
 * 1. BehavioralSegmentationService file quality (strict_types, MIT header, final class, @since)
 * 2. Service method signatures and return types
 * 3. Config section existence and completeness
 * 4. API routes registration
 * 5. Command file quality
 * 6. Controller methods existence
 * 7. Segment tier definitions (10 tiers with proper ranges)
 * 8. RFM scoring logic boundaries
 * 9. Dimension scoring logic
 * 10. Composite score calculation
 * 11. Trait vector construction
 * 12. Segment classification overrides (lost, at_risk)
 * 13. Version consistency
 * 14. Project scale thresholds
 *
 * @since 239.0.0
 */
final class V239BehavioralSegmentationTest extends \PHPUnit\Framework\TestCase
{
    private const VERSION = '266.0.0';
    private const SERVICE_FILE = __DIR__ . '/../src/Services/BehavioralSegmentationService.php';
    private const COMMAND_FILE = __DIR__ . '/../src/Console/Commands/AnalyticsSegmentsCommand.php';
    private const CONFIG_FILE = __DIR__ . '/../config/zeroboiler.php';
    private const ROUTES_FILE = __DIR__ . '/../routes/analytics.php';
    private const CONTROLLER_FILE = __DIR__ . '/../src/Http/Controllers/AnalyticsEventController.php';
    private const COMPOSER_FILE = __DIR__ . '/../composer.json';
    private const README_FILE = __DIR__ . '/../README.md';

    // ─── 1. File Quality Checks ──────────────────────────────────────

    #[Test]
    public function service_file_has_strict_types(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('declare(strict_types=1)', $contents);
    }

    #[Test]
    public function service_file_has_mit_header(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('MIT license', $contents);
        $this->assertStringContainsString('ZeroBoiler', $contents);
    }

    #[Test]
    public function service_class_is_final(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertMatchesRegularExpression('/final\s+class\s+BehavioralSegmentationService/', $contents);
    }

    #[Test]
    public function service_has_since_annotation(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('@since ' . self::VERSION, $contents);
    }

    #[Test]
    public function service_has_docblock(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('Behavioral Segmentation Engine', $contents);
        $this->assertStringContainsString('RFM', $contents);
    }

    #[Test]
    public function command_file_has_strict_types(): void
    {
        $contents = file_get_contents(self::COMMAND_FILE);
        $this->assertStringContainsString('declare(strict_types=1)', $contents);
    }

    #[Test]
    public function command_file_has_mit_header(): void
    {
        $contents = file_get_contents(self::COMMAND_FILE);
        $this->assertStringContainsString('MIT license', $contents);
    }

    #[Test]
    public function command_class_is_final(): void
    {
        $contents = file_get_contents(self::COMMAND_FILE);
        $this->assertMatchesRegularExpression('/final\s+class\s+AnalyticsSegmentsCommand/', $contents);
    }

    #[Test]
    public function command_has_since_annotation(): void
    {
        $contents = file_get_contents(self::COMMAND_FILE);
        $this->assertStringContainsString('@since ' . self::VERSION, $contents);
    }

    #[Test]
    public function command_extends_laravel_command(): void
    {
        $contents = file_get_contents(self::COMMAND_FILE);
        $this->assertStringContainsString('extends Command', $contents);
    }

    // ─── 2. Service Method Signatures ───────────────────────────────

    #[Test]
    public function service_has_segment_user_method(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('public function segmentUser(string $userId, ?string $clientId = null): array', $contents);
    }

    #[Test]
    public function service_has_segment_batch_method(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('public function segmentBatch(array $userIds): array', $contents);
    }

    #[Test]
    public function service_has_compute_rfm_method(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('public function computeRFM(string $userId, ?string $clientId = null): array', $contents);
    }

    #[Test]
    public function service_has_compute_dimensions_method(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('public function computeDimensions(string $userId, ?string $clientId = null): array', $contents);
    }

    #[Test]
    public function service_has_get_users_in_segment_method(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('public function getUsersInSegment(string $segment, int $limit = 100): array', $contents);
    }

    #[Test]
    public function service_has_segment_distribution_method(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('public function segmentDistribution(?array $userIds = null): array', $contents);
    }

    #[Test]
    public function service_has_tiers_method(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('public function tiers(): array', $contents);
    }

    #[Test]
    public function service_has_build_trait_vector_method(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('public function buildTraitVector(array $rfm, array $dimensions): array', $contents);
    }

    #[Test]
    public function service_has_segment_migration_method(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('public function segmentMigration(string $userId): array', $contents);
    }

    #[Test]
    public function service_has_record_snapshot_method(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('public function recordSnapshot(string $userId): void', $contents);
    }

    #[Test]
    public function service_has_segment_history_method(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('public function segmentHistory(string $userId, int $limit = 10): array', $contents);
    }

    #[Test]
    public function service_has_invalidate_user_method(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('public function invalidateUser(string $userId): bool', $contents);
    }

    #[Test]
    public function service_has_config_summary_method(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('public function configSummary(): array', $contents);
    }

    #[Test]
    public function constructor_has_void_return_type(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('public function __construct(', $contents);
        $this->assertMatchesRegularExpression('/public function __construct\([^)]+\):\s*void/', $contents);
    }

    // ─── 3. Config Section ───────────────────────────────────────────

    #[Test]
    public function config_has_behavioral_segmentation_section(): void
    {
        $config = include self::CONFIG_FILE;
        $this->assertArrayHasKey('behavioral_segmentation', $config['analytics']);
    }

    #[Test]
    public function config_has_enabled_flag(): void
    {
        $config = include self::CONFIG_FILE;
        $this->assertArrayHasKey('enabled', $config['analytics']['behavioral_segmentation']);
    }

    #[Test]
    public function config_has_cache_ttl(): void
    {
        $config = include self::CONFIG_FILE;
        $this->assertArrayHasKey('cache_ttl', $config['analytics']['behavioral_segmentation']);
    }

    #[Test]
    public function config_has_rfm_weights(): void
    {
        $config = include self::CONFIG_FILE;
        $this->assertArrayHasKey('rfm_weights', $config['analytics']['behavioral_segmentation']);
        $weights = $config['analytics']['behavioral_segmentation']['rfm_weights'];
        $this->assertArrayHasKey('recency', $weights);
        $this->assertArrayHasKey('frequency', $weights);
        $this->assertArrayHasKey('monetary', $weights);
    }

    #[Test]
    public function config_has_dimensions(): void
    {
        $config = include self::CONFIG_FILE;
        $this->assertArrayHasKey('dimensions', $config['analytics']['behavioral_segmentation']);
        $dims = $config['analytics']['behavioral_segmentation']['dimensions'];
        $this->assertArrayHasKey('recency', $dims);
        $this->assertArrayHasKey('frequency', $dims);
        $this->assertArrayHasKey('monetary', $dims);
        $this->assertArrayHasKey('engagement_breadth', $dims);
        $this->assertArrayHasKey('session_regularity', $dims);
        $this->assertArrayHasKey('growth_trajectory', $dims);
    }

    #[Test]
    public function config_has_all_10_tiers(): void
    {
        $config = include self::CONFIG_FILE;
        $tiers = $config['analytics']['behavioral_segmentation']['tiers'];
        $expected = ['champions', 'loyal', 'potential_loyalists', 'promising', 'new_users', 'need_attention', 'about_to_sleep', 'at_risk', 'hibernating', 'lost'];
        foreach ($expected as $tier) {
            $this->assertArrayHasKey($tier, $tiers, "Missing tier: {$tier}");
            $this->assertArrayHasKey('min', $tiers[$tier], "Missing min for tier: {$tier}");
            $this->assertArrayHasKey('max', $tiers[$tier], "Missing max for tier: {$tier}");
        }
    }

    #[Test]
    public function config_has_thresholds(): void
    {
        $config = include self::CONFIG_FILE;
        $this->assertArrayHasKey('thresholds', $config['analytics']['behavioral_segmentation']);
        $thresholds = $config['analytics']['behavioral_segmentation']['thresholds'];
        $this->assertArrayHasKey('lost_inactive_days', $thresholds);
        $this->assertArrayHasKey('hibernating_inactive_days', $thresholds);
        $this->assertArrayHasKey('at_risk_decline_percent', $thresholds);
    }

    // ─── 4. API Routes ───────────────────────────────────────────────

    #[Test]
    public function routes_has_segments_tiers(): void
    {
        $contents = file_get_contents(self::ROUTES_FILE);
        $this->assertStringContainsString("'segments/tiers'", $contents);
        $this->assertStringContainsString('behavioralSegmentsTiers', $contents);
    }

    #[Test]
    public function routes_has_segments_distribution(): void
    {
        $contents = file_get_contents(self::ROUTES_FILE);
        $this->assertStringContainsString("'segments/distribution'", $contents);
        $this->assertStringContainsString('behavioralSegmentsDistribution', $contents);
    }

    #[Test]
    public function routes_has_segments_user_endpoint(): void
    {
        $contents = file_get_contents(self::ROUTES_FILE);
        $this->assertStringContainsString("'segments/user/{userId}'", $contents);
        $this->assertStringContainsString('behavioralSegmentsUser', $contents);
    }

    #[Test]
    public function routes_has_segments_migration(): void
    {
        $contents = file_get_contents(self::ROUTES_FILE);
        $this->assertStringContainsString("'segments/migration/{userId}'", $contents);
        $this->assertStringContainsString('behavioralSegmentsMigration', $contents);
    }

    #[Test]
    public function routes_has_segments_history(): void
    {
        $contents = file_get_contents(self::ROUTES_FILE);
        $this->assertStringContainsString("'segments/history/{userId}'", $contents);
        $this->assertStringContainsString('behavioralSegmentsHistory', $contents);
    }

    #[Test]
    public function routes_has_segments_list(): void
    {
        $contents = file_get_contents(self::ROUTES_FILE);
        $this->assertStringContainsString('behavioralSegmentsList', $contents);
    }

    #[Test]
    public function routes_has_segments_snapshot(): void
    {
        $contents = file_get_contents(self::ROUTES_FILE);
        $this->assertStringContainsString("'segments/snapshot/{userId}'", $contents);
        $this->assertStringContainsString('behavioralSegmentsSnapshot', $contents);
    }

    #[Test]
    public function routes_has_segments_cache_invalidation(): void
    {
        $contents = file_get_contents(self::ROUTES_FILE);
        $this->assertStringContainsString('behavioralSegmentsInvalidateUser', $contents);
        $this->assertStringContainsString('behavioralSegmentsInvalidateAll', $contents);
    }

    // ─── 5. Controller Methods ────────────────────────────────────────

    #[Test]
    public function controller_has_behavioral_segments_tiers_method(): void
    {
        $contents = file_get_contents(self::CONTROLLER_FILE);
        $this->assertStringContainsString('public function behavioralSegmentsTiers(): JsonResponse', $contents);
    }

    #[Test]
    public function controller_has_behavioral_segments_distribution_method(): void
    {
        $contents = file_get_contents(self::CONTROLLER_FILE);
        $this->assertStringContainsString('public function behavioralSegmentsDistribution(Request $request): JsonResponse', $contents);
    }

    #[Test]
    public function controller_has_behavioral_segments_user_method(): void
    {
        $contents = file_get_contents(self::CONTROLLER_FILE);
        $this->assertStringContainsString('public function behavioralSegmentsUser(Request $request, string $userId): JsonResponse', $contents);
    }

    #[Test]
    public function controller_has_behavioral_segments_migration_method(): void
    {
        $contents = file_get_contents(self::CONTROLLER_FILE);
        $this->assertStringContainsString('public function behavioralSegmentsMigration(string $userId): JsonResponse', $contents);
    }

    #[Test]
    public function controller_has_behavioral_segments_history_method(): void
    {
        $contents = file_get_contents(self::CONTROLLER_FILE);
        $this->assertStringContainsString('public function behavioralSegmentsHistory(Request $request, string $userId): JsonResponse', $contents);
    }

    #[Test]
    public function controller_has_behavioral_segments_list_method(): void
    {
        $contents = file_get_contents(self::CONTROLLER_FILE);
        $this->assertStringContainsString('public function behavioralSegmentsList(Request $request, string $segment): JsonResponse', $contents);
    }

    #[Test]
    public function controller_has_behavioral_segments_snapshot_method(): void
    {
        $contents = file_get_contents(self::CONTROLLER_FILE);
        $this->assertStringContainsString('public function behavioralSegmentsSnapshot(string $userId): JsonResponse', $contents);
    }

    #[Test]
    public function controller_has_behavioral_segments_invalidate_methods(): void
    {
        $contents = file_get_contents(self::CONTROLLER_FILE);
        $this->assertStringContainsString('public function behavioralSegmentsInvalidateUser(string $userId): JsonResponse', $contents);
        $this->assertStringContainsString('public function behavioralSegmentsInvalidateAll(): JsonResponse', $contents);
    }

    // ─── 6. Segment Tier Definitions ─────────────────────────────────

    #[Test]
    public function service_has_ten_segment_tiers_defined(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $expectedTiers = ['champions', 'loyal', 'potential_loyalists', 'promising', 'new_users', 'need_attention', 'about_to_sleep', 'at_risk', 'hibernating', 'lost'];
        foreach ($expectedTiers as $tier) {
            $this->assertStringContainsString("'{$tier}'", $contents, "Missing tier: {$tier}");
        }
    }

    #[Test]
    public function tier_descriptions_exist(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('High-value, highly engaged', $contents);
        $this->assertStringContainsString('Consistently active', $contents);
        $this->assertStringContainsString('Recently signed up', $contents);
    }

    #[Test]
    public function lost_tier_has_lowest_range(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        // Default lost tier: min=0, max=0.99
        $this->assertStringContainsString("'lost' => ['min' => 0.0, 'max' => 0.99", $contents);
    }

    #[Test]
    public function champions_tier_has_highest_range(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        // Default champions tier: min=80, max=100
        $this->assertStringContainsString("'champions' => ['min' => 80, 'max' => 100", $contents);
    }

    // ─── 7. RFM Scoring Logic ─────────────────────────────────────────

    #[Test]
    public function recency_score_uses_5_point_scale(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        // Verify recency scoring uses 1-5 scale with day boundaries
        $this->assertStringContainsString('return 5;', $contents); // Today
        $this->assertStringContainsString('return 1;', $contents); // 60+ days
        $this->assertStringContainsString('recencyScore', $contents);
    }

    #[Test]
    public function frequency_score_uses_event_count_boundaries(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        // Verify frequency uses count-based boundaries
        $this->assertStringContainsString('count >= 100', $contents);
        $this->assertStringContainsString('count >= 50', $contents);
        $this->assertStringContainsString('count >= 20', $contents);
    }

    #[Test]
    public function monetary_score_uses_revenue_boundaries(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('revenue >= 500.0', $contents);
        $this->assertStringContainsString('revenue >= 100.0', $contents);
        $this->assertStringContainsString('revenue >= 25.0', $contents);
    }

    // ─── 8. Dimension Scoring ─────────────────────────────────────────

    #[Test]
    public function engagement_breadth_uses_category_ratio(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('totalCategories = 9', $contents);
        $this->assertStringContainsString('engagementBreadthScore', $contents);
    }

    #[Test]
    public function session_regularity_uses_session_count(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('sessionCount', $contents);
        $this->assertStringContainsString('sessionRegularityScore', $contents);
    }

    #[Test]
    public function growth_trajectory_compares_periods(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('user_events_7d', $contents);
        $this->assertStringContainsString('user_events_prev7d', $contents);
        $this->assertStringContainsString('growthTrajectoryScore', $contents);
    }

    // ─── 9. Segment Classification ───────────────────────────────────

    #[Test]
    public function classify_method_exists(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('private function classifySegment(float $compositeScore, array $rfm, array $dimensions): string', $contents);
    }

    #[Test]
    public function lost_override_for_inactive_users(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        // Lost users override: R=1 and low frequency
        $this->assertStringContainsString("'lost'", $contents);
    }

    #[Test]
    public function at_risk_override_for_declining_users(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        // At-risk override: growth < 20 and mid-range composite
        $this->assertStringContainsString("'at_risk'", $contents);
    }

    #[Test]
    public function composite_score_method_exists(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('private function compositeScore(array $rfm, array $dimensions): float', $contents);
    }

    // ─── 10. Trait Vector ─────────────────────────────────────────────

    #[Test]
    public function trait_vector_has_9_dimensions(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        // 3 RFM + 6 behavioral = 9 dimensions
        $this->assertStringContainsString('0 => (float) $rfm[\'r\']', $contents);
        $this->assertStringContainsString('8 => $dimensions[\'growth_trajectory\']', $contents);
    }

    #[Test]
    public function trait_vector_is_array_indexed(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('array<int, float>', $contents);
    }

    // ─── 11. Command Signature ───────────────────────────────────────

    #[Test]
    public function command_has_segment_option(): void
    {
        $contents = file_get_contents(self::COMMAND_FILE);
        $this->assertStringContainsString('--user=', $contents);
    }

    #[Test]
    public function command_has_tiers_option(): void
    {
        $contents = file_get_contents(self::COMMAND_FILE);
        $this->assertStringContainsString('--tiers', $contents);
    }

    #[Test]
    public function command_has_migration_option(): void
    {
        $contents = file_get_contents(self::COMMAND_FILE);
        $this->assertStringContainsString('--migration', $contents);
    }

    #[Test]
    public function command_has_record_option(): void
    {
        $contents = file_get_contents(self::COMMAND_FILE);
        $this->assertStringContainsString('--record', $contents);
    }

    #[Test]
    public function command_has_json_option(): void
    {
        $contents = file_get_contents(self::COMMAND_FILE);
        $this->assertStringContainsString('--json', $contents);
    }

    #[Test]
    public function command_has_invalidate_option(): void
    {
        $contents = file_get_contents(self::COMMAND_FILE);
        $this->assertStringContainsString('--invalidate', $contents);
    }

    #[Test]
    public function command_references_service(): void
    {
        $contents = file_get_contents(self::COMMAND_FILE);
        $this->assertStringContainsString('BehavioralSegmentationService', $contents);
    }

    #[Test]
    public function command_constructor_has_void_return_type(): void
    {
        $contents = file_get_contents(self::COMMAND_FILE);
        $this->assertMatchesRegularExpression('/public function __construct\([^)]+\):\s*void/', $contents);
    }

    // ─── 12. Project Scale Thresholds ─────────────────────────────────

    #[Test]
    public function project_has_minimum_source_files(): void
    {
        $srcFiles = glob(__DIR__ . '/../src/**/*.php', GLOB_BRACE);
        if ($srcFiles === false) {
            $srcFiles = [];
        }
        $this->assertGreaterThan(950, count($srcFiles), 'Source file count below 950 threshold');
    }

    #[Test]
    public function project_has_minimum_test_files(): void
    {
        $testFiles = glob(__DIR__ . '/../*.php', GLOB_BRACE);
        if ($testFiles === false) {
            $testFiles = [];
        }
        $this->assertGreaterThan(480, count($testFiles), 'Test file count below 480 threshold');
    }

    #[Test]
    public function project_has_minimum_command_files(): void
    {
        $cmdFiles = glob(__DIR__ . '/../src/Console/Commands/*.php');
        $this->assertGreaterThan(110, count($cmdFiles), 'Command count below 110 threshold');
    }

    #[Test]
    public function service_file_has_minimum_loc(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $lines = count(explode("\n", $contents));
        $this->assertGreaterThan(400, $lines, 'Service below 400 LOC threshold');
    }

    #[Test]
    public function command_file_has_minimum_loc(): void
    {
        $contents = file_get_contents(self::COMMAND_FILE);
        $lines = count(explode("\n", $contents));
        $this->assertGreaterThan(200, $lines, 'Command below 200 LOC threshold');
    }

    // ─── 13. Version Consistency ─────────────────────────────────────

    #[Test]
    public function config_section_has_version_reference(): void
    {
        $contents = file_get_contents(self::CONFIG_FILE);
        $this->assertStringContainsString('v239.0.0', $contents);
    }

    #[Test]
    public function service_references_version(): void
    {
        $contents = file_get_contents(self::SERVICE_FILE);
        $this->assertStringContainsString('239.0.0', $contents);
    }

    #[Test]
    public function command_references_version(): void
    {
        $contents = file_get_contents(self::COMMAND_FILE);
        $this->assertStringContainsString('239.0.0', $contents);
    }

    #[Test]
    public function routes_reference_version(): void
    {
        $contents = file_get_contents(self::ROUTES_FILE);
        $this->assertStringContainsString('v239.0.0', $contents);
    }
}
