<?php
declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests\Unit;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Pipeline\EventHashDedupFilter;
use ZeroBoiler\Analytics\Pipeline\EventTaxonomyEnricher;
use ZeroBoiler\Analytics\Services\SessionFingerprintService;

/**
 * Tests for v25.0.0: EventHashDedupFilter, SessionFingerprintService, EventTaxonomyEnricher.
 *
 * @covers \ZeroBoiler\Analytics\Pipeline\EventHashDedupFilter
 * @covers \ZeroBoiler\Analytics\Pipeline\EventTaxonomyEnricher
 * @covers \ZeroBoiler\Analytics\Services\SessionFingerprintService
 */
#[Group('v2500')]
#[CoversClass(EventHashDedupFilter::class)]
#[CoversClass(EventTaxonomyEnricher::class)]
#[CoversClass(SessionFingerprintService::class)]
final class V2500DedupFingerprintTaxonomyVersionTest extends \PHPUnit\Framework\TestCase
{
    // ─── EventHashDedupFilter Tests ───────────────────────────────────────

    public function test_dedup_filter_passes_unique_events(): void
    {
        $filter = new EventHashDedupFilter();

        $event = new AnalyticsEvent(name: 'page_view', params: ['path' => '/home']);

        $result = $filter($event);

        $this->assertNotNull($result);
        $this->assertSame('page_view', $result->name);
        $this->assertSame(['path' => '/home'], $result->params);
    }

    public function test_dedup_filter_drops_duplicate_events_within_request(): void
    {
        $filter = new EventHashDedupFilter();

        $event = new AnalyticsEvent(name: 'button_click', params: ['button_id' => 'cta']);

        $first = $filter($event);
        $second = $filter($event);

        $this->assertNotNull($first);
        $this->assertNull($second);
    }

    public function test_dedup_filter_treats_same_name_different_params_as_unique(): void
    {
        $filter = new EventHashDedupFilter();

        $eventA = new AnalyticsEvent(name: 'page_view', params: ['path' => '/home']);
        $eventB = new AnalyticsEvent(name: 'page_view', params: ['path' => '/pricing']);

        $resultA = $filter($eventA);
        $resultB = $filter($eventB);

        $this->assertNotNull($resultA);
        $this->assertNotNull($resultB);
    }

    public function test_dedup_filter_treats_reordered_params_as_same_event(): void
    {
        $filter = new EventHashDedupFilter();

        $eventA = new AnalyticsEvent(name: 'purchase', params: ['value' => 100, 'currency' => 'USD']);
        $eventB = new AnalyticsEvent(name: 'purchase', params: ['currency' => 'USD', 'value' => 100]);

        $resultA = $filter($eventA);
        $resultB = $filter($eventB);

        $this->assertNotNull($resultA);
        $this->assertNull($resultB);
    }

    public function test_dedup_compute_hash_is_deterministic(): void
    {
        $filter = new EventHashDedupFilter();

        $event = new AnalyticsEvent(name: 'signup', params: ['plan' => 'pro']);

        $hash1 = $filter->computeHash($event);
        $hash2 = $filter->computeHash($event);

        $this->assertSame($hash1, $hash2);
        $this->assertSame(64, strlen($hash1)); // SHA-256 hex
    }

    public function test_dedup_fifo_eviction_when_capacity_reached(): void
    {
        $filter = new EventHashDedupFilter(['max_memory_entries' => 3]);

        $event1 = new AnalyticsEvent(name: 'evt_1', params: ['i' => 1]);
        $event2 = new AnalyticsEvent(name: 'evt_2', params: ['i' => 2]);
        $event3 = new AnalyticsEvent(name: 'evt_3', params: ['i' => 3]);
        $event4 = new AnalyticsEvent(name: 'evt_4', params: ['i' => 4]);

        $this->assertNotNull($filter($event1));
        $this->assertNotNull($filter($event2));
        $this->assertNotNull($filter($event3));
        $this->assertNotNull($filter($event4)); // triggers eviction

        $this->assertSame(4, $filter->seenCount());
    }

    public function test_dedup_has_seen_hash(): void
    {
        $filter = new EventHashDedupFilter();

        $event = new AnalyticsEvent(name: 'test', params: []);
        $hash = $filter->computeHash($event);

        $this->assertFalse($filter->hasSeenHash($hash));

        $filter($event);

        $this->assertTrue($filter->hasSeenHash($hash));
    }

    public function test_dedup_reset_clears_state(): void
    {
        $filter = new EventHashDedupFilter();

        $event = new AnalyticsEvent(name: 'test', params: []);
        $filter($event);

        $this->assertSame(1, $filter->seenCount());

        $filter->reset();

        $this->assertSame(0, $filter->seenCount());
    }

    public function test_dedup_stats_returns_configured_values(): void
    {
        $filter = new EventHashDedupFilter([
            'max_memory_entries' => 500,
            'cross_request_dedup' => true,
            'cross_request_ttl' => 120,
        ]);

        $stats = $filter->stats();

        $this->assertSame(0, $stats['seen_count']);
        $this->assertSame(500, $stats['max_entries']);
        $this->assertTrue($stats['cross_request_enabled']);
        $this->assertSame(120, $stats['cross_request_ttl']);
    }

    public function test_dedup_cross_request_dedup_gracefully_handles_missing_cache(): void
    {
        // When no Laravel container is available, cross-request dedup should not throw
        $filter = new EventHashDedupFilter(['cross_request_dedup' => true]);

        $event = new AnalyticsEvent(name: 'test', params: ['key' => 'val']);

        // Should not throw even without cache container
        $result = $filter($event);

        $this->assertNotNull($result);
    }

    public function test_dedup_empty_params_event(): void
    {
        $filter = new EventHashDedupFilter();

        $event = new AnalyticsEvent(name: 'heartbeat', params: []);

        $this->assertNotNull($filter($event));
        $this->assertNull($filter($event));
    }

    // ─── SessionFingerprintService Tests ──────────────────────────────────

    public function test_fingerprint_generation_is_deterministic(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $service = new SessionFingerprintService($cache);

        $signals = [
            'user_agent' => 'Mozilla/5.0',
            'screen_width' => 1920,
            'screen_height' => 1080,
        ];

        $fp1 = $service->generateFingerprint($signals);
        $fp2 = $service->generateFingerprint($signals);

        $this->assertSame($fp1, $fp2);
        $this->assertSame(64, strlen($fp1));
    }

    public function test_fingerprint_normalizes_signals(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $service = new SessionFingerprintService($cache);

        $signalsA = ['user_agent' => 'Mozilla/5.0', 'platform' => 'Windows'];
        $signalsB = ['user_agent' => '  mozilla/5.0  ', 'platform' => '  windows  '];

        // After normalization (lowercase + trim), these should produce the same hash
        $fpA = $service->generateFingerprint($signalsA);
        $fpB = $service->generateFingerprint($signalsB);

        $this->assertSame($fpA, $fpB);
    }

    public function test_fingerprint_from_request(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $service = new SessionFingerprintService($cache);

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => 'TestAgent/1.0',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US',
        ]);

        $fp = $service->generateFromRequest($request);

        $this->assertSame(64, strlen($fp));
        $this->assertNotEmpty($fp);
    }

    public function test_record_fingerprint_first_seen_scores_100(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('get')->willReturnMap([
            ['zb_fp_abc123', null],
            ['zb_fp_client_user1', []],
        ]);
        $cache->expects($this->exactly(2))->method('put');

        $service = new SessionFingerprintService($cache);

        $result = $service->recordFingerprint('user1', 'abc123');

        $this->assertSame('abc123', $result['fingerprint']);
        $this->assertSame(100, $result['score']);
        $this->assertFalse($result['is_suspicious']);
        $this->assertSame([], $result['risk_factors']);
    }

    public function test_record_fingerprint_multiple_fingerprints_adds_risk(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('get')->willReturnMap([
            ['zb_fp_abc123', null],
            ['zb_fp_client_user1', ['fp_a' => 100, 'fp_b' => 200, 'fp_c' => 300, 'fp_d' => 400]],
        ]);
        $cache->expects($this->exactly(2))->method('put');

        $service = new SessionFingerprintService($cache);

        $result = $service->recordFingerprint('user1', 'abc123');

        $this->assertSame(80, $result['score']); // -20 for multiple_fingerprints
        $this->assertFalse($result['is_suspicious']); // 80 >= 60
        $this->assertContains('multiple_fingerprints', $result['risk_factors']);
    }

    public function test_record_fingerprint_high_frequency_reduces_score(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('get')->willReturnMap([
            ['zb_fp_abc123', ['seen_count' => 55, 'client_id' => 'user1']],
            ['zb_fp_client_user1', ['fp_old' => 100]],
        ]);
        $cache->expects($this->exactly(2))->method('put');

        $service = new SessionFingerprintService($cache);

        $result = $service->recordFingerprint('user1', 'abc123');

        $this->assertSame(65, $result['score']); // -5 shared, -30 high frequency = 65
        $this->assertContains('shared_fingerprint', $result['risk_factors']);
        $this->assertContains('high_frequency_fingerprint', $result['risk_factors']);
    }

    public function test_fingerprint_is_suspicious_for_high_seen_count(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('get')->willReturn(['seen_count' => 150, 'client_id' => 'user1']);

        $service = new SessionFingerprintService($cache);

        $this->assertTrue($service->isSuspicious('abc123'));
    }

    public function test_fingerprint_is_not_suspicious_when_not_seen(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('get')->willReturn(null);

        $service = new SessionFingerprintService($cache);

        $this->assertFalse($service->isSuspicious('unknown_fp'));
    }

    public function test_fingerprint_is_not_suspicious_for_low_seen_count(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('get')->willReturn(['seen_count' => 50, 'client_id' => 'user1']);

        $service = new SessionFingerprintService($cache);

        $this->assertFalse($service->isSuspicious('abc123')); // 50 <= 100
    }

    public function test_fingerprint_stats_returns_config(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $service = new SessionFingerprintService($cache, [
            'cache_prefix' => 'custom_',
            'fingerprint_ttl' => 7200,
            'max_fingerprints_per_client' => 5,
        ]);

        $stats = $service->stats();

        $this->assertSame('custom_', $stats['cache_prefix']);
        $this->assertSame(7200, $stats['ttl']);
        $this->assertSame(5, $stats['max_per_client']);
    }

    public function test_fingerprint_score_floor_at_zero(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        // Shared fingerprint (-5) + high frequency (-30) + multiple fingerprints (-20) = -55 → clamped to 0
        $cache->method('get')->willReturnMap([
            ['zb_fp_abc123', ['seen_count' => 200, 'client_id' => 'user1']],
            ['zb_fp_client_user1', ['fp_a' => 100, 'fp_b' => 200, 'fp_c' => 300, 'fp_d' => 400]],
        ]);
        $cache->expects($this->exactly(2))->method('put');

        $service = new SessionFingerprintService($cache);

        $result = $service->recordFingerprint('user1', 'abc123');

        $this->assertSame(0, $result['score']);
        $this->assertTrue($result['is_suspicious']);
    }

    // ─── EventTaxonomyEnricher Tests ─────────────────────────────────────

    public function test_taxonomy_classifies_transaction_events(): void
    {
        $enricher = new EventTaxonomyEnricher();

        $transactionEvents = ['purchase', 'refund', 'payment_succeeded', 'checkout_step_1', 'cart_add', 'order_placed', 'revenue_event', 'invoice_generated', 'billing_retry'];
        foreach ($transactionEvents as $name) {
            $this->assertSame('transaction', $enricher->classify($name));
        }
    }

    public function test_taxonomy_classifies_identity_events(): void
    {
        $enricher = new EventTaxonomyEnricher();

        $identityEvents = ['sign_up', 'signup_completed', 'register', 'login', 'logout', 'identify', 'password_changed', 'email_verified', 'account_activated'];
        foreach ($identityEvents as $name) {
            $this->assertSame('identity', $enricher->classify($name));
        }
    }

    public function test_taxonomy_classifies_conversion_events(): void
    {
        $enricher = new EventTaxonomyEnricher();

        $conversionEvents = ['conversion', 'trial_start', 'trial_convert', 'subscription_created', 'plan_upgrade', 'plan_change', 'activation', 'first_value', 'milestone_reached'];
        foreach ($conversionEvents as $name) {
            $this->assertSame('conversion', $enricher->classify($name));
        }
    }

    public function test_taxonomy_classifies_error_events(): void
    {
        $enricher = new EventTaxonomyEnricher();

        $errorEvents = ['error', 'exception_thrown', 'crash', 'js_error', 'rate_limit_exceeded', 'sla_breach', 'service_down'];
        foreach ($errorEvents as $name) {
            $this->assertSame('error', $enricher->classify($name));
        }
    }

    public function test_taxonomy_classifies_search_events(): void
    {
        $enricher = new EventTaxonomyEnricher();

        $searchEvents = ['search', 'query_submitted', 'filter_applied'];
        foreach ($searchEvents as $name) {
            $this->assertSame('search', $enricher->classify($name));
        }
    }

    public function test_taxonomy_classifies_navigation_events(): void
    {
        $enricher = new EventTaxonomyEnricher();

        $navigationEvents = ['page_view', 'screen_view', 'navigate', 'outbound_click', 'route_changed'];
        foreach ($navigationEvents as $name) {
            $this->assertSame('navigation', $enricher->classify($name));
        }
    }

    public function test_taxonomy_classifies_intent_events(): void
    {
        $enricher = new EventTaxonomyEnricher();

        $intentEvents = ['click', 'view_item', 'view_promotion', 'select_item', 'wishlist_add', 'add_to_cart', 'impression', 'feature_used', 'feature_adopted'];
        foreach ($intentEvents as $name) {
            $this->assertSame('intent', $enricher->classify($name));
        }
    }

    public function test_taxonomy_defaults_to_engagement(): void
    {
        $enricher = new EventTaxonomyEnricher();

        $engagementEvents = ['share', 'scroll_depth', 'time_on_page', 'video_play', 'session_start', 'form_start'];
        foreach ($engagementEvents as $name) {
            $this->assertSame('engagement', $enricher->classify($name));
        }
    }

    public function test_taxonomy_categories_returns_all_eight(): void
    {
        $categories = EventTaxonomyEnricher::categories();

        $this->assertCount(8, $categories);
        $this->assertContains('conversion', $categories);
        $this->assertContains('intent', $categories);
        $this->assertContains('engagement', $categories);
        $this->assertContains('navigation', $categories);
        $this->assertContains('transaction', $categories);
        $this->assertContains('identity', $categories);
        $this->assertContains('error', $categories);
        $this->assertContains('search', $categories);
    }

    public function test_taxonomy_enricher_adds_metadata_to_event(): void
    {
        $enricher = new EventTaxonomyEnricher();

        $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 100]);

        $enriched = ($enricher)($event);

        $this->assertSame('purchase', $enriched->name);
        $this->assertArrayHasKey('zb_taxonomy_category', $enriched->params);
        $this->assertArrayHasKey('zb_catalog_match', $enriched->params);
        $this->assertArrayHasKey('zb_provider_count', $enriched->params);
        $this->assertSame('transaction', $enriched->params['zb_taxonomy_category']);
        $this->assertIsBool($enriched->params['zb_catalog_match']);
        $this->assertIsInt($enriched->params['zb_provider_count']);
    }

    public function test_taxonomy_enricher_preserves_original_params(): void
    {
        $enricher = new EventTaxonomyEnricher();

        $event = new AnalyticsEvent(name: 'custom_event', params: ['key' => 'value', 'count' => 42]);

        $enriched = ($enricher)($event);

        $this->assertSame('value', $enriched->params['key']);
        $this->assertSame(42, $enriched->params['count']);
    }

    public function test_taxonomy_classify_with_params_ignores_empty_params(): void
    {
        $enricher = new EventTaxonomyEnricher();

        // Should still classify by name alone
        $this->assertSame('transaction', $enricher->classify('purchase', []));
    }

    // ─── Version Consistency Tests ────────────────────────────────────────

    public function test_version_is_25_across_all_markers(): void
    {
        // PHP markers
        $this->assertSame('25.0.0', \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION);

        // Catalog has entries
        $catalog = EventCatalog::all();
        $this->assertNotEmpty($catalog);

        // EventTaxonomyEnricher has all 8 categories
        $this->assertCount(8, EventTaxonomyEnricher::categories());
    }

    // ─── Edge Cases ────────────────────────────────────────────────────────

    public function test_dedup_handles_large_params(): void
    {
        $filter = new EventHashDedupFilter();

        $largeParams = array_fill(0, 100, 'value_' . random_int(0, 9999));
        $event = new AnalyticsEvent(name: 'bulk_event', params: $largeParams);

        $this->assertNotNull($filter($event));
        $this->assertNull($filter($event));
    }

    public function test_dedup_handles_unicode_event_names(): void
    {
        $filter = new EventHashDedupFilter();

        $event = new AnalyticsEvent(name: 'über_event', params: ['naïve' => 'café']);

        $this->assertNotNull($filter($event));
        $this->assertNull($filter($event));
    }

    public function test_fingerprint_handles_empty_signals(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $service = new SessionFingerprintService($cache);

        $fp = $service->generateFingerprint([]);

        $this->assertSame(64, strlen($fp));
    }

    public function test_fingerprint_defaults_are_applied(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $service = new SessionFingerprintService($cache);

        $stats = $service->stats();

        $this->assertSame('zb_fp_', $stats['cache_prefix']);
        $this->assertSame(3600, $stats['ttl']);
        $this->assertSame(10, $stats['max_per_client']);
    }
}
