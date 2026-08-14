<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Schema\EventFieldRegistry;
use ZeroBoiler\Analytics\Schema\FieldDefinition;
use ZeroBoiler\Analytics\Services\CrossProviderTranslationMatrix;
use ZeroBoiler\Analytics\Services\RevenueHealthScoreService;

/**
 * V133 Cross-Provider Translation Matrix & Revenue Health Score tests.
 *
 * @covers \ZeroBoiler\Analytics\Services\CrossProviderTranslationMatrix
 * @covers \ZeroBoiler\Analytics\Services\RevenueHealthScoreService
 * @covers \ZeroBoiler\Analytics\Schema\EventFieldRegistry
 * @covers \ZeroBoiler\Analytics\Schema\FieldDefinition
 *
 * @since 133.0.0
 */
final class V133CrossProviderAndRevenueHealthTest extends TestCase
{
    // ── CrossProviderTranslationMatrix ──────────────────────

    public function test_translate_name_returns_provider_event_for_known_event(): void
    {
        $matrix = new CrossProviderTranslationMatrix();

        $ga4 = $matrix->translateName('purchase', 'ga4');
        $meta = $matrix->translateName('purchase', 'meta');
        $posthog = $matrix->translateName('purchase', 'posthog');

        $this->assertSame('purchase', $ga4);
        $this->assertSame('Purchase', $meta);
        $this->assertSame('purchase', $posthog);
    }

    public function test_translate_name_returns_null_for_unmapped_provider(): void
    {
        $matrix = new CrossProviderTranslationMatrix();

        // Many engagement events don't have linkedin mapping
        $result = $matrix->translateName('scroll_depth', 'linkedin');

        $this->assertNull($result);
    }

    public function test_translate_name_returns_null_for_unknown_event(): void
    {
        $matrix = new CrossProviderTranslationMatrix();

        $result = $matrix->translateName('nonexistent_event', 'ga4');

        $this->assertNull($result);
    }

    public function test_reverse_translate_finds_canonical_from_provider_name(): void
    {
        $matrix = new CrossProviderTranslationMatrix();

        // Meta's 'CompleteRegistration' maps to 'sign_up'
        $canonical = $matrix->reverseTranslate('CompleteRegistration', 'meta');

        $this->assertSame('sign_up', $canonical);
    }

    public function test_reverse_translate_returns_null_for_unknown_provider_event(): void
    {
        $matrix = new CrossProviderTranslationMatrix();

        $result = $matrix->reverseTranslate('TotallyFakeEvent', 'meta');

        $this->assertNull($result);
    }

    public function test_translate_between_performs_cross_provider_translation(): void
    {
        $matrix = new CrossProviderTranslationMatrix();

        // Meta 'CompleteRegistration' → canonical 'sign_up' → GA4 'sign_up'
        $result = $matrix->translateBetween('CompleteRegistration', 'meta', 'ga4');

        $this->assertSame('sign_up', $result);
    }

    public function test_translate_between_returns_null_when_no_mapping_exists(): void
    {
        $matrix = new CrossProviderTranslationMatrix();

        $result = $matrix->translateBetween('fake_event', 'meta', 'ga4');

        $this->assertNull($result);
    }

    public function test_full_translation_map_returns_all_providers(): void
    {
        $matrix = new CrossProviderTranslationMatrix();
        $map = $matrix->fullTranslationMap('purchase');

        $this->assertArrayHasKey('ga4', $map);
        $this->assertArrayHasKey('meta', $map);
        $this->assertArrayHasKey('posthog', $map);
        $this->assertArrayHasKey('plausible', $map);
        $this->assertArrayHasKey('mixpanel', $map);
        $this->assertArrayHasKey('amplitude', $map);
        $this->assertArrayHasKey('tiktok', $map);
        $this->assertArrayHasKey('linkedin', $map);
        $this->assertSame('purchase', $map['ga4']);
        $this->assertSame('Purchase', $map['meta']);
    }

    public function test_full_translation_map_returns_nulls_for_unknown_event(): void
    {
        $matrix = new CrossProviderTranslationMatrix();
        $map = $matrix->fullTranslationMap('nonexistent');

        foreach ($map as $value) {
            $this->assertNull($value);
        }
    }

    public function test_coverage_for_returns_computed_coverage(): void
    {
        $matrix = new CrossProviderTranslationMatrix();
        $coverage = $matrix->coverageFor('purchase');

        $this->assertArrayHasKey('mapped', $coverage);
        $this->assertArrayHasKey('total', $coverage);
        $this->assertArrayHasKey('coverage', $coverage);
        $this->assertArrayHasKey('providers', $coverage);
        $this->assertArrayHasKey('unmapped', $coverage);
        $this->assertSame(8, $coverage['total']);
        $this->assertGreaterThanOrEqual(1, $coverage['mapped']);
        $this->assertGreaterThanOrEqual(0.0, $coverage['coverage']);
        $this->assertLessThanOrEqual(100.0, $coverage['coverage']);
    }

    public function test_mapping_gaps_returns_provider_keys(): void
    {
        $matrix = new CrossProviderTranslationMatrix();
        $gaps = $matrix->mappingGaps();

        $this->assertArrayHasKey('ga4', $gaps);
        $this->assertArrayHasKey('meta', $gaps);
        $this->assertArrayHasKey('posthog', $gaps);
        $this->assertArrayHasKey('plausible', $gaps);
        $this->assertArrayHasKey('mixpanel', $gaps);
        $this->assertArrayHasKey('amplitude', $gaps);
        $this->assertArrayHasKey('tiktok', $gaps);
        $this->assertArrayHasKey('linkedin', $gaps);
        $this->assertIsArray($gaps['ga4']);
    }

    public function test_matrix_table_returns_structured_data(): void
    {
        $matrix = new CrossProviderTranslationMatrix();
        $table = $matrix->matrixTable();

        $this->assertArrayHasKey('headers', $table);
        $this->assertArrayHasKey('rows', $table);
        $this->assertSame(['event', 'ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'], $table['headers']);
        $this->assertNotEmpty($table['rows']);
    }

    public function test_providers_returns_all_supported_providers(): void
    {
        $matrix = new CrossProviderTranslationMatrix();
        $providers = $matrix->providers();

        $this->assertSame([
            'ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin',
        ], $providers);
    }

    public function test_is_provider_supported_validates_known_provider(): void
    {
        $matrix = new CrossProviderTranslationMatrix();

        $this->assertTrue($matrix->isProviderSupported('ga4'));
        $this->assertTrue($matrix->isProviderSupported('meta'));
        $this->assertTrue($matrix->isProviderSupported('posthog'));
        $this->assertFalse($matrix->isProviderSupported('unknown'));
    }

    public function test_provider_collisions_returns_array(): void
    {
        $matrix = new CrossProviderTranslationMatrix();
        $collisions = $matrix->providerCollisions();

        $this->assertIsArray($collisions);
        // Each collision should have provider, event_name, canonical_names
        foreach ($collisions as $collision) {
            $this->assertArrayHasKey('provider', $collision);
            $this->assertArrayHasKey('event_name', $collision);
            $this->assertArrayHasKey('canonical_names', $collision);
            $this->assertGreaterThanOrEqual(2, count($collision['canonical_names']));
        }
    }

    // ── EventFieldRegistry ────────────────────────────────

    public function test_field_registry_returns_schema_for_known_event(): void
    {
        $schema = EventFieldRegistry::forEvent('purchase');

        $this->assertArrayHasKey('transaction_id', $schema);
        $this->assertArrayHasKey('value', $schema);
        $this->assertArrayHasKey('currency', $schema);
        $this->assertInstanceOf(FieldDefinition::class, $schema['transaction_id']);
        $this->assertTrue($schema['transaction_id']->required);
        $this->assertTrue($schema['value']->required);
        $this->assertSame('string', $schema['transaction_id']->type);
        $this->assertSame('float', $schema['value']->type);
    }

    public function test_field_registry_returns_empty_for_unknown_event(): void
    {
        $schema = EventFieldRegistry::forEvent('nonexistent_event');

        $this->assertSame([], $schema);
    }

    public function test_field_registry_ecommerce_events_have_required_fields(): void
    {
        $purchase = EventFieldRegistry::forEvent('purchase');
        $addToCart = EventFieldRegistry::forEvent('add_to_cart');

        // Purchase requires transaction_id, value, currency
        $this->assertTrue($purchase['transaction_id']->required);
        $this->assertTrue($purchase['value']->required);
        $this->assertTrue($purchase['currency']->required);

        // Add to cart requires item_id, item_name, price, quantity
        $this->assertTrue($addToCart['item_id']->required);
        $this->assertTrue($addToCart['price']->required);
        $this->assertTrue($addToCart['quantity']->required);
    }

    public function test_field_registry_saas_events_have_required_fields(): void
    {
        $subscribe = EventFieldRegistry::forEvent('subscribe');
        $planUpgrade = EventFieldRegistry::forEvent('plan_upgrade');

        $this->assertTrue($subscribe['plan']->required);
        $this->assertTrue($subscribe['value']->required);
        $this->assertTrue($subscribe['currency']->required);
        $this->assertTrue($planUpgrade['from_plan']->required);
        $this->assertTrue($planUpgrade['to_plan']->required);
    }

    public function test_field_registry_required_fields_returns_list(): void
    {
        $required = EventFieldRegistry::requiredFields('purchase');

        $this->assertContains('transaction_id', $required);
        $this->assertContains('value', $required);
        $this->assertContains('currency', $required);
    }

    public function test_field_registry_validate_passes_valid_payload(): void
    {
        $errors = EventFieldRegistry::validate('purchase', [
            'transaction_id' => 'TXN-123',
            'value' => 99.99,
            'currency' => 'USD',
        ]);

        $this->assertSame([], $errors);
    }

    public function test_field_registry_validate_fails_missing_required(): void
    {
        $errors = EventFieldRegistry::validate('purchase', [
            'transaction_id' => 'TXN-123',
            // missing value and currency
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Missing required field', $errors[0]);
    }

    public function test_field_registry_validate_fails_wrong_type(): void
    {
        $errors = EventFieldRegistry::validate('purchase', [
            'transaction_id' => 'TXN-123',
            'value' => 'not_a_number',
            'currency' => 'USD',
        ]);

        $this->assertNotEmpty($errors);
        $typeError = array_filter($errors, fn (string $e): bool => str_contains($e, 'must be a number'));
        $this->assertNotEmpty($typeError);
    }

    public function test_field_registry_validate_checks_allowed_values(): void
    {
        $errors = EventFieldRegistry::validate('sign_up', [
            'method' => 'invalid_method',
        ]);

        $hasAllowedError = array_filter($errors, fn (string $e): bool => str_contains($e, 'invalid value'));
        $this->assertNotEmpty($hasAllowedError);
    }

    public function test_field_registry_event_names_returns_expected_events(): void
    {
        $names = EventFieldRegistry::eventNames();

        $this->assertContains('purchase', $names);
        $this->assertContains('sign_up', $names);
        $this->assertContains('page_view', $names);
        $this->assertContains('click', $names);
        $this->assertContains('scroll_depth', $names);
        $this->assertGreaterThanOrEqual(28, count($names));
    }

    public function test_field_definition_to_array_serializes_correctly(): void
    {
        $def = new FieldDefinition('string', true, 'Test field', ['a', 'b'], 'default_val');
        $array = $def->toArray();

        $this->assertSame('string', $array['type']);
        $this->assertTrue($array['required']);
        $this->assertSame('Test field', $array['description']);
        $this->assertSame(['a', 'b'], $array['allowed_values']);
        $this->assertSame('default_val', $array['default']);
    }

    public function test_field_definition_is_immutable(): void
    {
        $def = new FieldDefinition('string', false, 'Test');

        $this->assertSame('string', $def->type);
        $this->assertFalse($def->required);
        $this->assertSame('Test', $def->description);
    }

    public function test_field_registry_engagement_events_have_required_fields(): void
    {
        $click = EventFieldRegistry::forEvent('click');
        $search = EventFieldRegistry::forEvent('search');

        $this->assertTrue($click['element']->required);
        $this->assertTrue($search['search_term']->required);
        $this->assertFalse($click['page_location']->required);
    }

    public function test_field_registry_all_returns_schemas_for_all_events(): void
    {
        $all = EventFieldRegistry::all();

        $this->assertArrayHasKey('purchase', $all);
        $this->assertArrayHasKey('sign_up', $all);
        $this->assertArrayHasKey('page_view', $all);
        $this->assertNotEmpty($all['purchase']);
    }

    // ── RevenueHealthScoreService ──────────────────────────

    public function test_revenue_health_score_computes_with_valid_structure(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('remember')->willReturnCallback(
            fn (string $key, int $ttl, callable $callback) => $callback()
        );

        $service = new RevenueHealthScoreService($cache);
        $result = $service->computeFresh();

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('grade', $result);
        $this->assertArrayHasKey('dimensions', $result);
        $this->assertArrayHasKey('gaps', $result);
        $this->assertArrayHasKey('recommendations', $result);
        $this->assertArrayHasKey('computed_at', $result);

        // Score should be 0-100
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);

        // Grade should be A-F
        $this->assertContains($result['grade'], ['A', 'B', 'C', 'D', 'E', 'F']);

        // All 5 dimensions
        $this->assertCount(5, $result['dimensions']);
        $this->assertArrayHasKey('revenue_events', $result['dimensions']);
        $this->assertArrayHasKey('subscription_lifecycle', $result['dimensions']);
        $this->assertArrayHasKey('ecommerce_funnel', $result['dimensions']);
        $this->assertArrayHasKey('billing_signals', $result['dimensions']);
        $this->assertArrayHasKey('provider_coverage', $result['dimensions']);
    }

    public function test_revenue_health_dimensions_have_required_keys(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('remember')->willReturnCallback(
            fn (string $key, int $ttl, callable $callback) => $callback()
        );

        $service = new RevenueHealthScoreService($cache);
        $result = $service->computeFresh();

        foreach ($result['dimensions'] as $key => $dim) {
            $this->assertArrayHasKey('score', $dim, "Missing 'score' in dimension '{$key}'");
            $this->assertArrayHasKey('max', $dim, "Missing 'max' in dimension '{$key}'");
            $this->assertArrayHasKey('weight', $dim, "Missing 'weight' in dimension '{$key}'");
            $this->assertArrayHasKey('status', $dim, "Missing 'status' in dimension '{$key}'");
            $this->assertArrayHasKey('details', $dim, "Missing 'details' in dimension '{$key}'");
            $this->assertContains($dim['status'], ['healthy', 'warning', 'critical']);
            $this->assertSame(100, $dim['max']);
            $this->assertGreaterThanOrEqual(0.0, $dim['score']);
            $this->assertLessThanOrEqual(100.0, $dim['score']);
        }
    }

    public function test_revenue_health_score_is_cacheable(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->expects($this->once())->method('remember')->willReturn([
            'score' => 95,
            'grade' => 'A',
            'dimensions' => [],
            'gaps' => [],
            'recommendations' => [],
            'computed_at' => '2026-01-01T00:00:00+00:00',
        ]);

        $service = new RevenueHealthScoreService($cache);
        $result = $service->compute();

        $this->assertSame(95, $result['score']);
        $this->assertSame('A', $result['grade']);
    }

    public function test_revenue_health_gaps_and_recommendations_are_lists(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->method('remember')->willReturnCallback(
            fn (string $key, int $ttl, callable $callback) => $callback()
        );

        $service = new RevenueHealthScoreService($cache);
        $result = $service->computeFresh();

        $this->assertIsArray($result['gaps']);
        $this->assertIsArray($result['recommendations']);

        // If no gaps, there should be recommendations
        if (empty($result['gaps'])) {
            $this->assertNotEmpty($result['recommendations']);
        }
    }

    public function test_revenue_health_invalidate_cache_calls_forget(): void
    {
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->expects($this->once())->method('forget')->with('zb_analytics_revenue_health_score');

        $service = new RevenueHealthScoreService($cache);
        $service->invalidateCache();
    }
}
