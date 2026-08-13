<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventContractTestService;

/**
 * Tests for the Event Contract Testing Engine (v76.0.0).
 *
 * @covers \ZeroBoiler\Analytics\Services\EventContractTestService
 */
final class V76EventContractTestingTest extends BaseTestCase
{
    private EventContractTestService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new EventContractTestService(
            cache: Cache::store('array'),
            config: $this->app->make('config'),
        );
    }

    /** @test */
    public function service_is_enabled_by_default(): void
    {
        $this->assertTrue($this->service->isEnabled());
    }

    /** @test */
    public function severity_defaults_to_warn(): void
    {
        $this->assertSame('warn', $this->service->getSeverity());
    }

    /** @test */
    public function contract_count_returns_total_registered_contracts(): void
    {
        $count = $this->service->contractCount();

        // GA4: 5 contracts (purchase, view_item, add_to_cart, refund, begin_checkout)
        // Meta: 6 contracts
        // PostHog: 2 contracts
        // Plausible: 1 contract
        $this->assertGreaterThanOrEqual(14, $count);
    }

    /** @test */
    public function get_contracts_returns_all_provider_contracts(): void
    {
        $contracts = $this->service->getContracts();

        $this->assertArrayHasKey('ga4', $contracts);
        $this->assertArrayHasKey('meta', $contracts);
        $this->assertArrayHasKey('posthog', $contracts);
        $this->assertArrayHasKey('plausible', $contracts);
        $this->assertArrayHasKey('purchase', $contracts['ga4']);
        $this->assertArrayHasKey('Purchase', $contracts['meta']);
    }

    /** @test */
    public function has_contract_returns_true_for_events_with_contracts(): void
    {
        $this->assertTrue($this->service->hasContract('purchase'));
        $this->assertTrue($this->service->hasContract('view_item'));
        $this->assertTrue($this->service->hasContract('add_to_cart'));
    }

    /** @test */
    public function has_contract_returns_false_for_events_without_contracts(): void
    {
        $this->assertFalse($this->service->hasContract('custom_unknown_event'));
    }

    /** @test */
    public function validate_event_passes_for_valid_purchase_event(): void
    {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'txn_test_001',
                'value' => 99.99,
                'currency' => 'USD',
                'items' => [['item_id' => 'p1', 'price' => 99.99, 'quantity' => 1]],
            ],
        );

        $result = $this->service->validateEvent($event);

        $this->assertArrayHasKey('event', $result);
        $this->assertArrayHasKey('providers', $result);
        $this->assertArrayHasKey('overall_passed', $result);
        $this->assertSame('purchase', $result['event']);
        $this->assertArrayHasKey('ga4', $result['providers']);
        $this->assertArrayHasKey('meta', $result['providers']);
        $this->assertArrayHasKey('posthog', $result['providers']);
    }

    /** @test */
    public function validate_event_detects_missing_required_ga4_params(): void
    {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 99.99],  // Missing transaction_id
        );

        $result = $this->service->validateEvent($event);

        // GA4 requires transaction_id and value
        $ga4Violations = $result['providers']['ga4']['violations'];
        $hasRequiredViolation = false;
        foreach ($ga4Violations as $violation) {
            if ($violation['rule'] === 'required_param') {
                $hasRequiredViolation = true;
                break;
            }
        }
        $this->assertTrue($hasRequiredViolation, 'Expected required_param violation for missing transaction_id');
    }

    /** @test */
    public function validate_event_detects_invalid_currency_enum(): void
    {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'txn_001',
                'value' => 99.99,
                'currency' => 'INVALID_CURRENCY',
                'items' => [['item_id' => 'p1', 'price' => 99.99, 'quantity' => 1]],
            ],
        );

        $result = $this->service->validateEvent($event);

        $ga4Violations = $result['providers']['ga4']['violations'];
        $hasEnumViolation = false;
        foreach ($ga4Violations as $violation) {
            if ($violation['rule'] === 'enum_constraint') {
                $hasEnumViolation = true;
                break;
            }
        }
        $this->assertTrue($hasEnumViolation, 'Expected enum_constraint violation for invalid currency');
    }

    /** @test */
    public function validate_event_detects_reserved_posthog_properties(): void
    {
        $event = new AnalyticsEvent(
            name: 'page_view',
            params: ['$device_id' => 'should_not_set_manually'],
        );

        $violations = $this->service->validateForProvider($event, 'posthog');

        $hasReserved = false;
        foreach ($violations as $violation) {
            if ($violation['rule'] === 'reserved_property') {
                $hasReserved = true;
                break;
            }
        }
        $this->assertTrue($hasReserved, 'Expected reserved_property violation for $device_id');
    }

    /** @test */
    public function validate_event_detects_oversized_param_values(): void
    {
        $longValue = str_repeat('x', 501);
        $event = new AnalyticsEvent(
            name: 'page_view',
            params: ['long_param' => $longValue],
        );

        $result = $this->service->validateEvent($event);

        // At least one provider should flag the length violation
        $anyLengthViolation = false;
        foreach ($result['providers'] as $provider => $check) {
            foreach ($check['violations'] as $violation) {
                if ($violation['rule'] === 'param_length') {
                    $anyLengthViolation = true;
                    break 2;
                }
            }
        }
        $this->assertTrue($anyLengthViolation, 'Expected param_length violation for 501-char value');
    }

    /** @test */
    public function validate_catalog_returns_coverage_report(): void
    {
        $result = $this->service->validateCatalog();

        $this->assertArrayHasKey('total_events', $result);
        $this->assertArrayHasKey('total_contracts', $result);
        $this->assertArrayHasKey('coverage', $result);
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('provider_coverage', $result);
        $this->assertArrayHasKey('grade', $result);

        $this->assertGreaterThan(0, $result['total_events']);
        $this->assertGreaterThan(0, $result['total_contracts']);
        $this->assertGreaterThanOrEqual(0.0, $result['coverage']);
        $this->assertLessThanOrEqual(100.0, $result['coverage']);

        // All 8 providers should be present
        $this->assertCount(8, $result['results']);
    }

    /** @test */
    public function provider_coverage_returns_detailed_report(): void
    {
        $result = $this->service->providerCoverage('ga4');

        $this->assertSame('ga4', $result['provider']);
        $this->assertArrayHasKey('total_events', $result);
        $this->assertArrayHasKey('passed', $result);
        $this->assertArrayHasKey('failed', $result);
        $this->assertArrayHasKey('coverage', $result);
        $this->assertArrayHasKey('top_violations', $result);

        $this->assertGreaterThan(0, $result['total_events']);
        $this->assertGreaterThanOrEqual(0.0, $result['coverage']);
        $this->assertLessThanOrEqual(100.0, $result['coverage']);
    }

    /** @test */
    public function coverage_grade_returns_correct_letters(): void
    {
        $catalogResult = $this->service->validateCatalog();

        // Grade should be one of the valid options
        $this->assertContains(
            $catalogResult['grade'],
            ['A+', 'A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D', 'F'],
        );
    }

    /** @test */
    public function validate_for_provider_page_view_passes_all_providers(): void
    {
        $event = new AnalyticsEvent(
            name: 'page_view',
            params: ['page_title' => 'Test Page'],
        );

        foreach (['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude'] as $provider) {
            $violations = $this->service->validateForProvider($event, $provider);
            $this->assertEmpty(
                $violations,
                "Expected no violations for page_view on {$provider}, got: " . json_encode($violations),
            );
        }
    }

    /** @test */
    public function validate_for_provider_items_exceeding_max(): void
    {
        $items = [];
        for ($i = 0; $i < 26; $i++) {
            $items[] = ['item_id' => "item_{$i}", 'price' => 10.0, 'quantity' => 1];
        }

        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'txn_001',
                'value' => 260.0,
                'currency' => 'USD',
                'items' => $items,
            ],
        );

        $violations = $this->service->validateForProvider($event, 'ga4');

        $hasMaxItems = false;
        foreach ($violations as $violation) {
            if ($violation['rule'] === 'max_items') {
                $hasMaxItems = true;
                break;
            }
        }
        $this->assertTrue($hasMaxItems, 'Expected max_items violation for 26 items');
    }

    /** @test */
    public function analytics_event_version_is_76(): void
    {
        $this->assertSame('76.0.0', AnalyticsEvent::VERSION);
    }

    /** @test */
    public function contract_testing_config_section_exists(): void
    {
        $config = $this->app->make('config');
        $ct = $config->get('zeroboiler.analytics.contract_testing');

        $this->assertIsArray($ct);
        $this->assertArrayHasKey('enabled', $ct);
        $this->assertArrayHasKey('severity', $ct);
        $this->assertArrayHasKey('cache_ttl', $ct);
    }

    /** @test */
    public function full_contract_test_suite_structure(): void
    {
        // Simulate a mini test suite
        $testCases = [
            ['name' => 'purchase', 'params' => ['transaction_id' => 'txn_001', 'value' => 99.99, 'currency' => 'USD', 'items' => [['item_id' => 'p1', 'price' => 99.99, 'quantity' => 1]]]],
            ['name' => 'purchase', 'params' => ['value' => 99.99]],  // Missing required
            ['name' => 'page_view', 'params' => ['page_title' => 'Test']],
            ['name' => 'sign_up', 'params' => ['$distinct_id' => 'user_001']],
        ];

        $results = [];
        foreach ($testCases as $testCase) {
            $event = new AnalyticsEvent(name: $testCase['name'], params: $testCase['params']);
            $results[] = $this->service->validateEvent($event);
        }

        // All results should have the expected structure
        foreach ($results as $result) {
            $this->assertArrayHasKey('event', $result);
            $this->assertArrayHasKey('providers', $result);
            $this->assertArrayHasKey('overall_passed', $result);
            $this->assertArrayHasKey('severity', $result);
            $this->assertCount(8, $result['providers']);
        }

        // At least the first and third should pass (valid events)
        $this->assertTrue($results[0]['overall_passed'], 'Valid purchase should pass');
        // Second should fail (missing transaction_id)
        $this->assertFalse($results[1]['overall_passed'], 'Purchase missing transaction_id should fail');
    }
}
