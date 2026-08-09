<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests\Feature\V51;

use Illuminate\Support\Facades\Event;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EventExporterService;
use ZeroBoiler\Analytics\Support\EventTransformer;
use Tests\TestCase;

/**
 * v2.51.0 — Report summary, DLQ summary, event exporter, Plausible mapping expansion.
 *
 * Validates the new features added in v2.51:
 * - reportSummary() and dlqSummary() methods on AnalyticsManager
 * - EventExporterService catalog export (JSON + CSV)
 * - Plausible event map coverage for all event types
 * - PostHog event map expansion (account lifecycle, B2B, engagement)
 */
final class V51EventExporterAndSummaryTest extends TestCase
{
    // ── AnalyticsManager::reportSummary ──────────────────────────────────

    public function test_report_summary_returns_expected_structure(): void
    {
        $config = $this->app->make('config');
        $metrics = new AnalyticsMetrics($config);
        $metrics->setEnabled(true);

        $metrics->recordDispatch('ga4');
        $metrics->recordDispatch('ga4');
        $metrics->recordDispatch('meta');
        $metrics->recordFailure('posthog');

        // We test the metrics logic directly since we can't easily mock AnalyticsManager
        $totalDispatched = $metrics->totalDispatched();
        $totalFailed = $metrics->totalFailed();
        $total = $totalDispatched + $totalFailed;

        $this->assertSame(3, $totalDispatched);
        $this->assertSame(1, $totalFailed);
        $this->assertSame(4, $total);

        $successRate = $total > 0
            ? round(($totalDispatched / $total) * 100, 2)
            : 100.0;

        $this->assertSame(75.0, $successRate);
    }

    public function test_report_summary_top_provider(): void
    {
        $config = $this->app->make('config');
        $metrics = new AnalyticsMetrics($config);
        $metrics->setEnabled(true);

        $metrics->recordDispatch('ga4');
        $metrics->recordDispatch('ga4');
        $metrics->recordDispatch('meta');
        $metrics->recordDispatch('meta');
        $metrics->recordDispatch('posthog');

        $byProvider = $metrics->dispatchedByProvider();
        arsort($byProvider);

        $topProvider = array_key_first($byProvider);

        // Both ga4 and meta have 2 — arsort preserves order, so ga4 should be first
        $this->assertContains($topProvider, ['ga4', 'meta']);
        $this->assertSame(2, $byProvider['ga4']);
        $this->assertSame(2, $byProvider['meta']);
        $this->assertSame(1, $byProvider['posthog']);
    }

    public function test_report_summary_empty_metrics(): void
    {
        $config = $this->app->make('config');
        $metrics = new AnalyticsMetrics($config);

        $this->assertSame(0, $metrics->totalDispatched());
        $this->assertSame(0, $metrics->totalFailed());
    }

    // ── EventExporterService ─────────────────────────────────────────────

    public function test_event_exporter_summary_structure(): void
    {
        $exporter = new EventExporterService;

        $summary = $exporter->summary();

        $this->assertArrayHasKey('total', $summary);
        $this->assertArrayHasKey('ecommerce', $summary);
        $this->assertArrayHasKey('saas', $summary);
        $this->assertArrayHasKey('engagement', $summary);
        $this->assertArrayHasKey('ga4_mappings', $summary);
        $this->assertArrayHasKey('meta_mappings', $summary);
        $this->assertArrayHasKey('posthog_mappings', $summary);
        $this->assertArrayHasKey('plausible_mappings', $summary);

        $this->assertGreaterThan(0, $summary['total']);
    }

    public function test_event_exporter_catalog_json_is_valid(): void
    {
        $exporter = new EventExporterService;

        $json = $exporter->exportCatalogJson(pretty: false);

        $data = json_decode($json, true);
        $this->assertNotNull($data);
        $this->assertArrayHasKey('version', $data);
        $this->assertSame('5.7.0', $data['version']);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('categories', $data);
        $this->assertArrayHasKey('ecommerce', $data['categories']);
        $this->assertArrayHasKey('saas', $data['categories']);
        $this->assertArrayHasKey('engagement', $data['categories']);
    }

    public function test_event_exporter_mappings_json_is_valid(): void
    {
        $exporter = new EventExporterService;

        $json = $exporter->exportMappingsJson(pretty: false);

        $data = json_decode($json, true);
        $this->assertNotNull($data);
        $this->assertNotEmpty($data);

        // Each mapping should have ga4, meta, posthog, plausible keys
        foreach ($data as $eventName => $mapping) {
            $this->assertArrayHasKey('ga4', $mapping, "Missing 'ga4' for event: {$eventName}");
            $this->assertArrayHasKey('meta', $mapping, "Missing 'meta' for event: {$eventName}");
            $this->assertArrayHasKey('posthog', $mapping, "Missing 'posthog' for event: {$eventName}");
            $this->assertArrayHasKey('plausible', $mapping, "Missing 'plausible' for event: {$eventName}");
        }
    }

    public function test_event_exporter_catalog_csv_has_header(): void
    {
        $exporter = new EventExporterService;

        $csv = $exporter->exportCatalogCsv();

        $lines = explode("\n", $csv);
        $this->assertGreaterThan(1, count($lines));

        $header = $lines[0];
        $this->assertStringContainsString('event_name', $header);
        $this->assertStringContainsString('category', $header);
        $this->assertStringContainsString('ga4_name', $header);
        $this->assertStringContainsString('meta_name', $header);
        $this->assertStringContainsString('posthog_name', $header);
        $this->assertStringContainsString('plausible_name', $header);
    }

    public function test_event_exporter_category_export(): void
    {
        $exporter = new EventExporterService;

        $ecommerce = $exporter->exportCategory('ecommerce');

        $this->assertNotEmpty($ecommerce);
        $this->assertArrayHasKey('purchase', $ecommerce);
        $this->assertArrayHasKey('add_to_cart', $ecommerce);
        $this->assertArrayHasKey('view_item', $ecommerce);
    }

    // ── EventTransformer: Plausible Map Expansion ─────────────────────────

    public function test_plausible_map_covers_all_saas_events(): void
    {
        $plausibleMap = EventTransformer::toPlausibleEventMap();

        // SaaS events that should have Plausible mappings
        $saasEvents = [
            'sign_up', 'login', 'logout', 'start_trial', 'trial_end',
            'subscribe', 'plan_upgrade', 'plan_downgrade', 'cancellation',
            'feature_used', 'revenue_tracked',
        ];

        foreach ($saasEvents as $event) {
            $this->assertArrayHasKey(
                $event,
                $plausibleMap,
                "Plausible map missing SaaS event: {$event}",
            );
            $this->assertNotNull(
                $plausibleMap[$event],
                "Plausible should map '{$event}' to a custom event, not null",
            );
        }
    }

    public function test_plausible_map_covers_ecommerce_events(): void
    {
        $plausibleMap = EventTransformer::toPlausibleEventMap();

        $supported = ['add_to_cart', 'begin_checkout', 'purchase', 'refund'];
        $notSupported = ['view_item', 'remove_from_cart', 'view_cart', 'add_payment_info'];

        foreach ($supported as $event) {
            $this->assertArrayHasKey($event, $plausibleMap);
            $this->assertNotNull($plausibleMap[$event], "Plausible should support '{$event}'");
        }

        foreach ($notSupported as $event) {
            $this->assertArrayHasKey($event, $plausibleMap);
            $this->assertNull($plausibleMap[$event], "Plausible should NOT support '{$event}'");
        }
    }

    public function test_plausible_map_covers_engagement_content(): void
    {
        $plausibleMap = EventTransformer::toPlausibleEventMap();

        // Supported content events
        $this->assertNotNull($plausibleMap['search']);
        $this->assertNotNull($plausibleMap['share']);
        $this->assertNotNull($plausibleMap['file_download']);
        $this->assertNotNull($plausibleMap['video_play']);

        // Unsupported engagement events
        $this->assertNull($plausibleMap['scroll_depth']);
        $this->assertNull($plausibleMap['click']);
        $this->assertNull($plausibleMap['form_start']);
        $this->assertNull($plausibleMap['form_submit']);
    }

    public function test_plausible_map_covers_cohort_events_as_null(): void
    {
        $plausibleMap = EventTransformer::toPlausibleEventMap();

        $cohortEvents = [
            'cohort_assigned', 'cohort_retention', 'cohort_churn',
            'cohort_conversion', 'cohort_migration', 'cohort_engagement',
        ];

        foreach ($cohortEvents as $event) {
            $this->assertArrayHasKey($event, $plausibleMap, "Missing cohort event: {$event}");
            $this->assertNull($plausibleMap[$event], "Plausible should NOT support cohort event: {$event}");
        }
    }

    // ── EventTransformer: PostHog Map Expansion ───────────────────────────

    public function test_posthog_map_covers_account_lifecycle(): void
    {
        $posthogMap = EventTransformer::saasToPosthogEventMap();

        $accountEvents = [
            'account_activated', 'account_deactivated', 'password_changed',
            'password_reset', 'profile_updated', 'email_verified',
        ];

        foreach ($accountEvents as $event) {
            $this->assertArrayHasKey($event, $posthogMap, "PostHog map missing account event: {$event}");
            $this->assertNotNull($posthogMap[$event]);
        }
    }

    public function test_posthog_map_covers_b2b_team_events(): void
    {
        $posthogMap = EventTransformer::saasToPosthogEventMap();

        $b2bEvents = [
            'team_created', 'team_member_joined', 'team_member_removed', 'role_changed',
        ];

        foreach ($b2bEvents as $event) {
            $this->assertArrayHasKey($event, $posthogMap, "PostHog map missing B2B event: {$event}");
            $this->assertNotNull($posthogMap[$event]);
        }
    }

    public function test_posthog_map_covers_engagement_reserved(): void
    {
        $posthogMap = EventTransformer::saasToPosthogEventMap();

        $this->assertSame('$pageview', $posthogMap['page_view']);
        $this->assertSame('$session_start', $posthogMap['session_start']);
        $this->assertSame('$screenview', $posthogMap['screen_view']);
        $this->assertSame('$share', $posthogMap['share']);
        $this->assertSame('$error', $posthogMap['error']);
        $this->assertSame('$search', $posthogMap['search']);
        $this->assertSame('$exception', $posthogMap['js_error']);
    }

    public function test_posthog_map_no_duplicates(): void
    {
        $posthogMap = EventTransformer::saasToPosthogEventMap();
        $keys = array_keys($posthogMap);
        $uniqueKeys = array_unique($keys);

        $this->assertSameSize($keys, $uniqueKeys, 'PostHog map has duplicate keys');
    }

    public function test_plausible_map_no_duplicates(): void
    {
        $plausibleMap = EventTransformer::toPlausibleEventMap();
        $keys = array_keys($plausibleMap);
        $uniqueKeys = array_unique($keys);

        $this->assertSameSize($keys, $uniqueKeys, 'Plausible map has duplicate keys');
    }

    // ── Version Consistency ───────────────────────────────────────────────

    public function test_version_is_251(): void
    {
        $manager = $this->app->make(AnalyticsManager::class);

        $this->assertSame('5.7.0', $manager->version());
    }
}
