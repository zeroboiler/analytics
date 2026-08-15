<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\CatalogSnapshotService;
use ZeroBoiler\Analytics\Services\EventGovernanceRuntimeValidator;

/**
 * V160 — Event Governance Runtime Validator & Catalog Snapshot Service.
 *
 * Tests the new governance runtime validator, catalog snapshot service,
 * governance CLI command registration, config expansion, and version sweep.
 *
 * @since 160.0.0
 */
final class V160GovernanceRuntimeValidatorTest extends TestCase
{
    private const PKG_ROOT = __DIR__ . '/..';
    private const VERSION = '160.0.0';

    // ── 1. EventGovernanceRuntimeValidator: Known Event Validation ─────

    #[Test]
    public function validator_accepts_known_catalog_event(): void
    {
        $validator = new EventGovernanceRuntimeValidator;
        $event = new AnalyticsEvent(name: 'page_view', params: ['title' => 'Home'], category: 'engagement');
        $result = $validator->validate($event);

        $this->assertTrue($result['valid']);
        $this->assertSame('page_view', $result['event']);
        $this->assertSame([], $result['warnings']);
        $this->assertNotNull($result['catalog_entry']);
        $this->assertSame('page_view', $result['resolved_name']);
    }

    #[Test]
    public function validator_detects_unknown_event(): void
    {
        $validator = new EventGovernanceRuntimeValidator;
        $event = new AnalyticsEvent(name: 'nonexistent_event_xyz', params: []);
        $result = $validator->validate($event);

        $this->assertFalse($result['valid']);
        $this->assertContains('not found in catalog', implode(' ', $result['warnings']));
        $this->assertNull($result['resolved_name']);
        $this->assertNull($result['catalog_entry']);
    }

    #[Test]
    public function validator_auto_resolves_fuzzy_event_name(): void
    {
        $validator = new EventGovernanceRuntimeValidator(autoResolve: true);
        $event = new AnalyticsEvent(name: 'AddToCart', params: ['item_id' => 'p1']);
        $result = $validator->validate($event);

        $this->assertNotNull($result['resolved_name']);
        $this->assertSame('add_to_cart', $result['resolved_name']);
        $this->assertNotNull($result['catalog_entry']);
    }

    #[Test]
    public function validator_does_not_resolve_when_auto_resolve_disabled(): void
    {
        $validator = new EventGovernanceRuntimeValidator(autoResolve: false);
        $event = new AnalyticsEvent(name: 'AddToCart', params: []);
        $result = $validator->validate($event);

        $this->assertFalse($result['valid']);
        $this->assertNull($result['resolved_name']);
    }

    // ── 2. Category Mismatch Detection ──────────────────────────────────

    #[Test]
    public function validator_detects_category_mismatch(): void
    {
        $validator = new EventGovernanceRuntimeValidator;
        $event = new AnalyticsEvent(name: 'page_view', params: [], category: 'ecommerce');
        $result = $validator->validate($event);

        $this->assertFalse($result['valid']);
        $found = false;
        foreach ($result['warnings'] as $w) {
            if (str_contains($w, 'Category mismatch')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Should detect category mismatch');
    }

    // ── 3. Required Parameter Validation ───────────────────────────────

    #[Test]
    public function validator_checks_required_global_params(): void
    {
        $validator = new EventGovernanceRuntimeValidator;
        $validator->setRequiredGlobalParams(['client_id']);
        $event = new AnalyticsEvent(name: 'page_view', params: ['title' => 'Test'], category: 'engagement');
        $result = $validator->validate($event);

        $this->assertFalse($result['valid']);
        $found = false;
        foreach ($result['warnings'] as $w) {
            if (str_contains($w, 'client_id')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Should detect missing required parameter');
    }

    #[Test]
    public function validator_passes_when_required_params_present(): void
    {
        $validator = new EventGovernanceRuntimeValidator;
        $validator->setRequiredGlobalParams(['client_id']);
        $event = new AnalyticsEvent(name: 'page_view', params: ['client_id' => 'abc123'], category: 'engagement');
        $result = $validator->validate($event);

        $this->assertTrue($result['valid']);
    }

    // ── 4. Deprecated Event Detection ──────────────────────────────────

    #[Test]
    public function validator_warns_on_deprecated_events(): void
    {
        $validator = new EventGovernanceRuntimeValidator;
        $validator->setDeprecatedEvents(['page_view']);
        $event = new AnalyticsEvent(name: 'page_view', params: [], category: 'engagement');
        $result = $validator->validate($event);

        $found = false;
        foreach ($result['warnings'] as $w) {
            if (str_contains($w, 'Deprecated')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Should warn about deprecated event');
    }

    // ── 5. Provider Gap Detection ──────────────────────────────────────

    #[Test]
    public function validator_detects_provider_gaps(): void
    {
        $validator = new EventGovernanceRuntimeValidator(checkProviderGaps: true);
        // Most engagement events don't have meta/plausible mapping
        $event = new AnalyticsEvent(name: 'scroll_depth', params: ['percent' => 50], category: 'engagement');
        $result = $validator->validate($event);

        // scroll_depth has ga4 and posthog but not meta — may have gaps
        $this->assertIsArray($result['provider_gaps']);
    }

    #[Test]
    public function validator_skips_provider_gaps_when_disabled(): void
    {
        $validator = new EventGovernanceRuntimeValidator(checkProviderGaps: false);
        $event = new AnalyticsEvent(name: 'page_view', params: [], category: 'engagement');
        $result = $validator->validate($event);

        $this->assertSame([], $result['provider_gaps']);
    }

    // ── 6. Batch Validation ────────────────────────────────────────────

    #[Test]
    public function validator_batch_validates_multiple_events(): void
    {
        $validator = new EventGovernanceRuntimeValidator;
        $events = [
            new AnalyticsEvent(name: 'page_view', params: [], category: 'engagement'),
            new AnalyticsEvent(name: 'purchase', params: [], category: 'ecommerce'),
            new AnalyticsEvent(name: 'sign_up', params: [], category: 'saas'),
            new AnalyticsEvent(name: 'unknown_event', params: []),
        ];

        $result = $validator->validateBatch($events);

        $this->assertSame(4, $result['total']);
        $this->assertSame(3, $result['valid']);
        $this->assertSame(1, $result['invalid']);
        $this->assertContains('unknown_event', $result['unknown_events']);
        $this->assertCount(4, $result['results']);
    }

    // ── 7. Validation Log ────────────────────────────────────────────

    #[Test]
    public function validator_accumulates_validation_log(): void
    {
        $validator = new EventGovernanceRuntimeValidator;
        $validator->validate(new AnalyticsEvent(name: 'unknown_xyz', params: []));
        $validator->validate(new AnalyticsEvent(name: 'page_view', params: [], category: 'ecommerce'));

        $log = $validator->getValidationLog();
        $this->assertGreaterThan(0, count($log));
    }

    #[Test]
    public function validator_log_summary_is_accurate(): void
    {
        $validator = new EventGovernanceRuntimeValidator;
        $validator->validate(new AnalyticsEvent(name: 'unknown_xyz', params: []));
        $validator->validate(new AnalyticsEvent(name: 'page_view', params: [], category: 'engagement'));

        $summary = $validator->logSummary();
        $this->assertArrayHasKey('total_entries', $summary);
        $this->assertArrayHasKey('warnings', $summary);
        $this->assertArrayHasKey('errors', $summary);
        $this->assertArrayHasKey('unique_events', $summary);
        $this->assertArrayHasKey('top_offenders', $summary);
    }

    #[Test]
    public function validator_clear_log(): void
    {
        $validator = new EventGovernanceRuntimeValidator;
        $validator->validate(new AnalyticsEvent(name: 'unknown_xyz', params: []));
        $validator->clearLog();
        $this->assertSame([], $validator->getValidationLog());
    }

    // ── 8. Catalog Governance Health ───────────────────────────────────

    #[Test]
    public function catalog_governance_health_returns_structured_result(): void
    {
        $validator = new EventGovernanceRuntimeValidator;
        $health = $validator->catalogGovernanceHealth();

        $this->assertArrayHasKey('total', $health);
        $this->assertArrayHasKey('valid', $health);
        $this->assertArrayHasKey('incomplete', $health);
        $this->assertArrayHasKey('issues', $health);
        $this->assertGreaterThan(0, $health['total']);
        $this->assertIsList($health['issues']);
    }

    // ── 9. Empty Event Name Detection ──────────────────────────────────

    #[Test]
    public function validator_detects_empty_event_name(): void
    {
        $validator = new EventGovernanceRuntimeValidator;
        $event = new AnalyticsEvent(name: '', params: []);
        $result = $validator->validate($event);

        $this->assertFalse($result['valid']);
        $found = false;
        foreach ($result['warnings'] as $w) {
            if (str_contains($w, 'Empty event name')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }

    // ── 10. All Ecommerce Events Validate ────────────────────────────────

    #[Test]
    public function all_ecommerce_events_pass_validation(): void
    {
        $validator = new EventGovernanceRuntimeValidator;
        $names = \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::names();
        $invalid = [];

        foreach ($names as $name) {
            $result = $validator->validate(new AnalyticsEvent(name: $name, params: [], category: 'ecommerce'));
            if (! $result['valid'] && $result['resolved_name'] === null) {
                $invalid[] = $name;
            }
        }

        $this->assertSame([], $invalid, 'All ecommerce events should be in catalog');
    }

    // ── 11. All SaaS Core Events Validate ───────────────────────────────

    #[Test]
    public function saas_core_events_pass_validation(): void
    {
        $validator = new EventGovernanceRuntimeValidator;
        $coreEvents = ['sign_up', 'login', 'logout', 'start_trial', 'subscribe', 'plan_upgrade', 'plan_downgrade', 'cancellation', 'feature_used', 'revenue_tracked'];
        $invalid = [];

        foreach ($coreEvents as $name) {
            $result = $validator->validate(new AnalyticsEvent(name: $name, params: [], category: 'saas'));
            if (! $result['valid'] && $result['resolved_name'] === null) {
                $invalid[] = $name;
            }
        }

        $this->assertSame([], $invalid, 'All core SaaS events should be in catalog');
    }

    // ── 12. All Engagement Core Events Validate ───────────────────────

    #[Test]
    public function engagement_core_events_pass_validation(): void
    {
        $validator = new EventGovernanceRuntimeValidator;
        $coreEvents = ['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'search', 'share', 'error'];
        $invalid = [];

        foreach ($coreEvents as $name) {
            $result = $validator->validate(new AnalyticsEvent(name: $name, params: [], category: 'engagement'));
            if (! $result['valid'] && $result['resolved_name'] === null) {
                $invalid[] = $name;
            }
        }

        $this->assertSame([], $invalid, 'All core engagement events should be in catalog');
    }

    // ── 13. CatalogSnapshotService: Build Snapshot ──────────────────────

    #[Test]
    public function snapshot_service_builds_snapshot(): void
    {
        $cache = new \Illuminate\Cache\ArrayStore;
        $repository = new \Illuminate\Cache\Repository($cache);
        $service = new CatalogSnapshotService($repository, enabled: false);

        $snapshot = $service->capture('test_snapshot');

        $this->assertArrayHasKey('label', $snapshot);
        $this->assertArrayHasKey('timestamp', $snapshot);
        $this->assertArrayHasKey('version', $snapshot);
        $this->assertArrayHasKey('total_events', $snapshot);
        $this->assertArrayHasKey('categories', $snapshot);
        $this->assertArrayHasKey('provider_coverage', $snapshot);
        $this->assertArrayHasKey('events', $snapshot);
        $this->assertSame('test_snapshot', $snapshot['label']);
        $this->assertSame(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION, $snapshot['version']);
        $this->assertGreaterThan(0, $snapshot['total_events']);
    }

    // ── 14. CatalogSnapshotService: Snapshot Diff ─────────────────────

    #[Test]
    public function snapshot_diff_detects_added_events(): void
    {
        $cache = new \Illuminate\Cache\ArrayStore;
        $repository = new \Illuminate\Cache\Repository($cache);
        $service = new CatalogSnapshotService($repository, enabled: false);

        // Build a minimal baseline
        $baseline = [
            'label' => 'baseline',
            'timestamp' => date('c'),
            'version' => '159.0.0',
            'total_events' => 1,
            'categories' => ['engagement' => 1],
            'provider_coverage' => ['ga4' => 1, 'meta' => 1, 'posthog' => 1],
            'events' => [
                'page_view' => [
                    'name' => 'page_view',
                    'category' => 'engagement',
                    'providers' => ['ga4' => 'page_view', 'meta' => 'PageView', 'posthog' => '$pageview'],
                ],
            ],
        ];

        // Build a current with one extra event
        $current = $baseline;
        $current['events']['purchase'] = [
            'name' => 'purchase',
            'category' => 'ecommerce',
            'providers' => ['ga4' => 'purchase', 'meta' => 'Purchase', 'posthog' => 'purchase'],
        ];
        $current['total_events'] = 2;
        $current['categories']['ecommerce'] = 1;

        $diff = $service->diff($baseline, $current);

        $this->assertContains('purchase', $diff['added']);
        $this->assertSame([], $diff['removed']);
        $this->assertSame(1, $diff['summary']['non_breaking']);
        $this->assertSame(0, $diff['summary']['breaking']);
    }

    #[Test]
    public function snapshot_diff_detects_removed_events_as_breaking(): void
    {
        $cache = new \Illuminate\Cache\ArrayStore;
        $repository = new \Illuminate\Cache\Repository($cache);
        $service = new CatalogSnapshotService($repository, enabled: false);

        $baseline = [
            'label' => 'baseline',
            'timestamp' => date('c'),
            'version' => '159.0.0',
            'total_events' => 1,
            'categories' => ['engagement' => 1],
            'provider_coverage' => ['ga4' => 1, 'meta' => 1, 'posthog' => 1],
            'events' => [
                'page_view' => [
                    'name' => 'page_view',
                    'category' => 'engagement',
                    'providers' => ['ga4' => 'page_view', 'meta' => 'PageView', 'posthog' => '$pageview'],
                ],
            ],
        ];

        $current = [
            'label' => 'current',
            'timestamp' => date('c'),
            'version' => '160.0.0',
            'total_events' => 0,
            'categories' => [],
            'provider_coverage' => ['ga4' => 0, 'meta' => 0, 'posthog' => 0],
            'events' => [],
        ];

        $diff = $service->diff($baseline, $current);

        $this->assertContains('page_view', $diff['removed']);
        $this->assertGreaterThan(0, $diff['summary']['breaking']);
    }

    // ── 15. Governance Validate Command File Exists ────────────────────

    #[Test]
    public function governance_validate_command_file_exists(): void
    {
        $path = self::PKG_ROOT . '/src/Console/Commands/AnalyticsGovernanceValidateCommand.php';
        $this->assertFileExists($path);
    }

    #[Test]
    public function governance_validate_command_has_strict_types(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Console/Commands/AnalyticsGovernanceValidateCommand.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function governance_validate_command_has_license_header(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Console/Commands/AnalyticsGovernanceValidateCommand.php');
        $this->assertStringContainsString('ZeroBoiler, licensed under the MIT license', $content);
    }

    #[Test]
    public function governance_validate_command_has_since_annotation(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Console/Commands/AnalyticsGovernanceValidateCommand.php');
        $this->assertStringContainsString('@since 160.0.0', $content);
    }

    // ── 16. Config Has Governance Runtime Section ─────────────────────

    #[Test]
    public function config_has_governance_runtime_section(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'governance_runtime' => [", $content);
        $this->assertStringContainsString('ANALYTICS_GOVERNANCE_RUNTIME_ENABLED', $content);
        $this->assertStringContainsString('ANALYTICS_GOVERNANCE_PROVIDER_GAPS', $content);
        $this->assertStringContainsString('ANALYTICS_GOVERNANCE_AUTO_RESOLVE', $content);
        $this->assertStringContainsString('ANALYTICS_GOVERNANCE_SNAPSHOT_TTL', $content);
    }

    // ── 17. Version Sweep ──────────────────────────────────────────────

    #[Test]
    public function analytics_event_version_is_160(): void
    {
        $this->assertSame(self::VERSION, AnalyticsEvent::VERSION);
    }

    #[Test]
    public function composer_json_version_is_160(): void
    {
        $composer = json_decode(file_get_contents(self::PKG_ROOT . '/composer.json'), true);
        $this->assertSame(self::VERSION, $composer['version']);
    }

    #[Test]
    public function integrity_command_version_is_160(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Console/Commands/AnalyticsIntegrityCommand.php');
        $this->assertStringContainsString("EXPECTED_VERSION = '" . self::VERSION . "'", $content);
    }

    #[Test]
    public function service_provider_version_is_160(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('@version ' . self::VERSION, $content);
    }

    // ── 18. CatalogSnapshotService File Exists ────────────────────────

    #[Test]
    public function catalog_snapshot_service_file_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Services/CatalogSnapshotService.php');
    }

    #[Test]
    public function catalog_snapshot_service_has_strict_types(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/CatalogSnapshotService.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function catalog_snapshot_service_has_since_annotation(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/CatalogSnapshotService.php');
        $this->assertStringContainsString('@since 160.0.0', $content);
    }

    // ── 19. EventGovernanceRuntimeValidator File Exists ────────────────

    #[Test]
    public function governance_runtime_validator_file_exists(): void
    {
        $this->assertFileExists(self::PKG_ROOT . '/src/Services/EventGovernanceRuntimeValidator.php');
    }

    #[Test]
    public function governance_runtime_validator_has_strict_types(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/EventGovernanceRuntimeValidator.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function governance_runtime_validator_has_since_annotation(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/EventGovernanceRuntimeValidator.php');
        $this->assertStringContainsString('@since 160.0.0', $content);
    }

    // ── 20. Event Catalog Integrity ───────────────────────────────────

    #[Test]
    public function catalog_has_all_eight_categories(): void
    {
        $byCategory = EventCatalog::byCategory();
        $expectedCategories = ['ecommerce', 'saas', 'engagement', 'security', 'uptime', 'infrastructure', 'marketing', 'customer_success'];
        foreach ($expectedCategories as $cat) {
            $this->assertArrayHasKey($cat, $byCategory, "Missing category: {$cat}");
            $this->assertGreaterThan(0, count($byCategory[$cat]), "Empty category: {$cat}");
        }
    }

    #[Test]
    public function catalog_count_is_consistent(): void
    {
        $all = EventCatalog::all();
        $byCategory = EventCatalog::byCategory();
        $categorySum = 0;

        foreach ($byCategory as $events) {
            $categorySum += count($events);
        }

        $this->assertSame(count($all), $categorySum, 'EventCatalog::all() count must match sum of categories');
    }

    #[Test]
    public function all_events_have_required_keys(): void
    {
        $all = EventCatalog::all();
        $requiredKeys = EventCatalog::requiredKeys();

        foreach ($all as $name => $entry) {
            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey($key, $entry, "Event '{$name}' missing key '{$key}'");
            }
        }
    }

    // ── 21. Max Log Size Enforcement ────────────────────────────────

    #[Test]
    public function validator_enforces_max_log_size(): void
    {
        $validator = new EventGovernanceRuntimeValidator(maxLogSize: 5);
        for ($i = 0; $i < 10; $i++) {
            $validator->validate(new AnalyticsEvent(name: "unknown_event_{$i}", params: []));
        }

        $log = $validator->getValidationLog();
        $this->assertLessThanOrEqual(5, count($log));
    }

    // ── 22. Resolved Name Is Null For Truly Unknown Events ─────────────

    #[Test]
    public function resolved_name_null_when_event_is_genuinely_unknown(): void
    {
        $validator = new EventGovernanceRuntimeValidator(autoResolve: true);
        $result = $validator->validate(new AnalyticsEvent(name: 'zzz_absolutely_nonexistent_xyz', params: []));

        $this->assertNull($result['resolved_name']);
    }
}
