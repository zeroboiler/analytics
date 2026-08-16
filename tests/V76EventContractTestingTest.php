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
final class V76EventContractTestingTest extends TestCase
{
    /**
     * Create a mock config repository with contract_testing settings.
     *
     * @return object{get: callable(string, mixed=): mixed}
     */
    private function mockConfig(): object
    {
        return new class {
            /** @param  array<string, mixed>  $defaults */
            public function get(string $key, mixed $defaults = []): mixed
            {
                if ($key === 'zeroboiler.analytics.contract_testing') {
                    return [
                        'enabled' => true,
                        'severity' => 'warn',
                        'cache_ttl' => 3600,
                    ];
                }

                return $defaults;
            }
        };
    }

    /**
     * Create a mock cache repository.
     *
     * @return object{get: callable(string, mixed=): mixed, put: callable(string, mixed, int): void, has: callable(string): bool, forget: callable(string): bool}
     */
    private function mockCache(): object
    {
        return new class {
            /** @var array<string, mixed> */
            private array $store = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->store[$key] ?? $default;
            }

            public function put(string $key, mixed $value, int $ttl = 3600): void
            {
                $this->store[$key] = $value;
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->store);
            }

            public function forget(string $key): bool
            {
                unset($this->store[$key]);

                return true;
            }
        };
    }

    private function createService(): EventContractTestService
    {
        return new EventContractTestService(
            cache: $this->mockCache(),
            config: $this->mockConfig(),
        );
    }

    /** @test */
    public function service_is_enabled_by_default(): void
    {
        $service = $this->createService();
        $this->assertTrue($service->isEnabled());
    }

    /** @test */
    public function severity_defaults_to_warn(): void
    {
        $service = $this->createService();
        $this->assertSame('warn', $service->getSeverity());
    }

    /** @test */
    public function contract_count_returns_total_registered_contracts(): void
    {
        $service = $this->createService();
        $count = $service->contractCount();

        // GA4: 5, Meta: 6, PostHog: 2, Plausible: 1 = 14 minimum
        $this->assertGreaterThanOrEqual(14, $count);
    }

    /** @test */
    public function get_contracts_returns_all_provider_contracts(): void
    {
        $service = $this->createService();
        $contracts = $service->getContracts();

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
        $service = $this->createService();
        $this->assertTrue($service->hasContract('purchase'));
        $this->assertTrue($service->hasContract('view_item'));
        $this->assertTrue($service->hasContract('add_to_cart'));
    }

    /** @test */
    public function has_contract_returns_false_for_events_without_contracts(): void
    {
        $service = $this->createService();
        $this->assertFalse($service->hasContract('custom_unknown_event'));
    }

    /** @test */
    public function validate_event_passes_for_valid_purchase_event(): void
    {
        $service = $this->createService();
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'txn_test_001',
                'value' => 99.99,
                'currency' => 'USD',
                'items' => [['item_id' => 'p1', 'price' => 99.99, 'quantity' => 1]],
            ],
        );

        $result = $service->validateEvent($event);

        $this->assertArrayHasKey('event', $result);
        $this->assertArrayHasKey('providers', $result);
        $this->assertArrayHasKey('overall_passed', $result);
        $this->assertSame('purchase', $result['event']);
        $this->assertCount(8, $result['providers']);
    }

    /** @test */
    public function validate_event_detects_missing_required_ga4_params(): void
    {
        $service = $this->createService();
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 99.99],  // Missing transaction_id
        );

        $result = $service->validateEvent($event);

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
        $service = $this->createService();
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'txn_001',
                'value' => 99.99,
                'currency' => 'INVALID_CURRENCY',
                'items' => [['item_id' => 'p1', 'price' => 99.99, 'quantity' => 1]],
            ],
        );

        $result = $service->validateEvent($event);

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
        $service = $this->createService();
        $event = new AnalyticsEvent(
            name: 'page_view',
            params: ['$device_id' => 'should_not_set_manually'],
        );

        $violations = $service->validateForProvider($event, 'posthog');

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
        $service = $this->createService();
        $longValue = str_repeat('x', 501);
        $event = new AnalyticsEvent(
            name: 'page_view',
            params: ['long_param' => $longValue],
        );

        $result = $service->validateEvent($event);

        $anyLengthViolation = false;
        foreach ($result['providers'] as $check) {
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
        $service = $this->createService();
        $result = $service->validateCatalog();

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
        $this->assertCount(8, $result['results']);
    }

    /** @test */
    public function provider_coverage_returns_detailed_report(): void
    {
        $service = $this->createService();
        $result = $service->providerCoverage('ga4');

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
    public function coverage_grade_returns_valid_grade(): void
    {
        $service = $this->createService();
        $catalogResult = $service->validateCatalog();

        $this->assertContains(
            $catalogResult['grade'],
            ['A+', 'A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D', 'F'],
        );
    }

    /** @test */
    public function validate_for_provider_page_view_passes_clean_providers(): void
    {
        $service = $this->createService();
        $event = new AnalyticsEvent(
            name: 'page_view',
            params: ['page_title' => 'Test Page'],
        );

        foreach (['ga4', 'meta', 'plausible', 'mixpanel', 'amplitude'] as $provider) {
            $violations = $service->validateForProvider($event, $provider);
            $this->assertEmpty(
                $violations,
                "Expected no violations for page_view on {$provider}, got: " . json_encode($violations),
            );
        }
    }

    /** @test */
    public function validate_for_provider_items_exceeding_max(): void
    {
        $service = $this->createService();
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

        $violations = $service->validateForProvider($event, 'ga4');

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
    public function full_contract_test_suite_structure(): void
    {
        $service = $this->createService();

        $testCases = [
            ['name' => 'purchase', 'params' => ['transaction_id' => 'txn_001', 'value' => 99.99, 'currency' => 'USD', 'items' => [['item_id' => 'p1', 'price' => 99.99, 'quantity' => 1]]]],
            ['name' => 'purchase', 'params' => ['value' => 99.99]],  // Missing required
            ['name' => 'page_view', 'params' => ['page_title' => 'Test']],
            ['name' => 'sign_up', 'params' => ['$distinct_id' => 'user_001']],
        ];

        $results = [];
        foreach ($testCases as $testCase) {
            $event = new AnalyticsEvent(name: $testCase['name'], params: $testCase['params']);
            $results[] = $service->validateEvent($event);
        }

        // All results should have the expected structure
        foreach ($results as $result) {
            $this->assertArrayHasKey('event', $result);
            $this->assertArrayHasKey('providers', $result);
            $this->assertArrayHasKey('overall_passed', $result);
            $this->assertArrayHasKey('severity', $result);
            $this->assertCount(8, $result['providers']);
        }

        // Valid purchase should pass
        $this->assertTrue($results[0]['overall_passed'], 'Valid purchase should pass');
        // Purchase missing transaction_id should fail
        $this->assertFalse($results[1]['overall_passed'], 'Purchase missing transaction_id should fail');
    }

    /** @test */
    public function violation_contains_required_fields(): void
    {
        $service = $this->createService();
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 99.99],  // Missing transaction_id
        );

        $result = $service->validateEvent($event);
        $ga4Violations = $result['providers']['ga4']['violations'];

        $this->assertNotEmpty($ga4Violations);
        $violation = $ga4Violations[0];
        $this->assertArrayHasKey('rule', $violation);
        $this->assertArrayHasKey('message', $violation);
        $this->assertArrayHasKey('param', $violation);
        $this->assertIsString($violation['rule']);
        $this->assertIsString($violation['message']);
    }

    /** @test */
    public function service_constants_are_defined(): void
    {
        $this->assertSame('reject', EventContractTestService::SEVERITY_REJECT);
        $this->assertSame('warn', EventContractTestService::SEVERITY_WARN);
        $this->assertSame('off', EventContractTestService::SEVERITY_OFF);
    }

    /** @test */
    public function meta_contracts_require_value_for_purchase(): void
    {
        $service = $this->createService();
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: [
                'transaction_id' => 'txn_001',
                'currency' => 'USD',
                'items' => [['item_id' => 'p1', 'price' => 99.99, 'quantity' => 1]],
                // Missing 'value'
            ],
        );

        $result = $service->validateEvent($event);
        $metaViolations = $result['providers']['meta']['violations'];

        $hasRequiredValue = false;
        foreach ($metaViolations as $violation) {
            if ($violation['rule'] === 'required_param' && ($violation['param'] ?? '') === 'value') {
                $hasRequiredValue = true;
                break;
            }
        }
        $this->assertTrue($hasRequiredValue, 'Meta Purchase should require value parameter');
    }
}
