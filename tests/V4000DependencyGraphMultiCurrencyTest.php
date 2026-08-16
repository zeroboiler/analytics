<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventDependencyGraphService;
use ZeroBoiler\Analytics\Services\MultiCurrencyRevenueNormalizer;

/**
 * Tests for EventDependencyGraphService and MultiCurrencyRevenueNormalizer.
 *
 * Covers dependency graph validation, topological sort, cycle detection,
 * path validation, funnel probability, and multi-currency conversion with
 * rate management, batch normalization, and edge cases.
 *
 * @version 40.0.0
 *
 * @since 40.0.0
 */
final class V4000DependencyGraphMultiCurrencyTest extends TestCase
{
    // ──────────────────────────────────────────────────────
    // EventDependencyGraphService Tests
    // ──────────────────────────────────────────────────────

    public function testDependencyGraphIsEnabledWithDefaultConfig(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.dependency_graph.enabled' => true,
        ]);

        $service = new EventDependencyGraphService($cache, $config);

        $this->assertTrue($service->isEnabled());
    }

    public function testDependencyGraphIsDisabledWhenConfigured(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.dependency_graph.enabled' => false,
        ]);

        $service = new EventDependencyGraphService($cache, $config);

        $this->assertFalse($service->isEnabled());
    }

    public function testValidateEventWithNoPrerequisitesPasses(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $service = new EventDependencyGraphService($cache, $config);

        $event = new AnalyticsEvent(name: 'sign_up', params: []);
        $result = $service->validate($event, 'client_123');

        $this->assertTrue($result['valid']);
        $this->assertFalse($result['violated']);
        $this->assertSame('sign_up', $result['event']);
        $this->assertEmpty($result['missing_prerequisites']);
    }

    public function testValidateEventWithMetPrerequisitesPasses(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $service = new EventDependencyGraphService($cache, $config);

        // First, record sign_up
        $service->validate(new AnalyticsEvent(name: 'sign_up'), 'client_456');

        // Then validate start_trial (requires sign_up)
        $result = $service->validate(
            new AnalyticsEvent(name: 'start_trial'),
            'client_456',
        );

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['missing_prerequisites']);
    }

    public function testValidateEventWithMissingPrerequisitesFails(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $service = new EventDependencyGraphService($cache, $config);

        // Try start_trial without sign_up
        $result = $service->validate(
            new AnalyticsEvent(name: 'start_trial'),
            'client_new',
        );

        $this->assertFalse($result['valid']);
        $this->assertTrue($result['violated']);
        $this->assertContains('sign_up', $result['missing_prerequisites']);
    }

    public function testValidatePurchaseWithoutBeginCheckoutFails(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $service = new EventDependencyGraphService($cache, $config);

        $result = $service->validate(
            new AnalyticsEvent(name: 'purchase'),
            'client_cart_skip',
        );

        $this->assertFalse($result['valid']);
        $this->assertContains('begin_checkout', $result['missing_prerequisites']);
    }

    public function testValidateBatchCalculatesPassRate(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $service = new EventDependencyGraphService($cache, $config);

        $events = [
            new AnalyticsEvent(name: 'sign_up'),
            new AnalyticsEvent(name: 'start_trial'),
            new AnalyticsEvent(name: 'subscribe'),
        ];

        $result = $service->validateBatch($events);

        $this->assertSame(3, $result['total']);
        $this->assertSame(3, $result['passed']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(1.0, $result['pass_rate']);
    }

    public function testValidateBatchDetectsMixedResults(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $service = new EventDependencyGraphService($cache, $config);

        // Record sign_up for this client
        $service->validate(new AnalyticsEvent(name: 'sign_up'), 'client_mix');

        $events = [
            new AnalyticsEvent(name: 'start_trial'),  // valid (sign_up met)
            new AnalyticsEvent(name: 'purchase'),       // invalid (no begin_checkout)
        ];

        $result = $service->validateBatch($events);

        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['passed']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(0.5, $result['pass_rate']);
    }

    public function testGetPrerequisitesReturnsEmptyForRootEvents(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $service = new EventDependencyGraphService($cache, $config);

        $prereqs = $service->getPrerequisites('sign_up');

        $this->assertEmpty($prereqs);
    }

    public function testGetPrerequisitesReturnsCorrectForNestedEvents(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $service = new EventDependencyGraphService($cache, $config);

        $prereqs = $service->getPrerequisites('subscribe');

        $this->assertContains('sign_up', $prereqs);
    }

    public function testGetSuccessorsReturnsCorrectForRootEvents(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $service = new EventDependencyGraphService($cache, $config);

        $successors = $service->getSuccessors('sign_up');

        $this->assertContains('login', $successors);
        $this->assertContains('start_trial', $successors);
        $this->assertContains('subscribe', $successors);
    }

    public function testGetGraphContainsAllBuiltinNodes(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $service = new EventDependencyGraphService($cache, $config);

        $graph = $service->getGraph();

        $this->assertArrayHasKey('sign_up', $graph);
        $this->assertArrayHasKey('login', $graph);
        $this->assertArrayHasKey('start_trial', $graph);
        $this->assertArrayHasKey('subscribe', $graph);
        $this->assertArrayHasKey('purchase', $graph);
        $this->assertArrayHasKey('view_item', $graph);
    }

    public function testTopologicalSortReturnsAllNodes(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $service = new EventDependencyGraphService($cache, $config);

        $sorted = $service->topologicalSort();

        // All builtin nodes should be in the sort
        $graph = $service->getGraph();
        foreach (array_keys($graph) as $node) {
            $this->assertContains($node, $sorted);
        }

        // sign_up should come before subscribe in topological order
        $signUpIdx = array_search('sign_up', $sorted, true);
        $subscribeIdx = array_search('subscribe', $sorted, true);

        $this->assertNotFalse($signUpIdx);
        $this->assertNotFalse($subscribeIdx);
        $this->assertLessThan($subscribeIdx, $signUpIdx);
    }

    public function testDetectCyclesReturnsEmptyForAcyclicGraph(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $service = new EventDependencyGraphService($cache, $config);

        $cycles = $service->detectCycles();

        $this->assertEmpty($cycles);
    }

    public function testValidatePathReturnsValidForConnectedPath(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $service = new EventDependencyGraphService($cache, $config);

        $result = $service->validatePath(['sign_up', 'start_trial', 'subscribe']);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['violations']);
    }

    public function testValidatePathReturnsViolationsForDisconnectedPath(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $service = new EventDependencyGraphService($cache, $config);

        $result = $service->validatePath(['sign_up', 'purchase']);

        // sign_up → purchase has no direct edge
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['violations']);
    }

    public function testFunnelCompletionProbabilityCalculatesCorrectly(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $service = new EventDependencyGraphService($cache, $config);

        // Full valid path
        $result = $service->funnelCompletionProbability([
            'sign_up', 'start_trial', 'subscribe',
        ]);

        $this->assertSame(1.0, $result['probability']);
        $this->assertSame(2, $result['steps_completed']);
        $this->assertSame(2, $result['total_steps']);
        $this->assertEmpty($result['missing_edges']);
    }

    public function testFunnelCompletionProbabilityDetectsMissingEdges(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $service = new EventDependencyGraphService($cache, $config);

        $result = $service->funnelCompletionProbability([
            'sign_up', 'purchase',  // no direct edge
        ]);

        $this->assertLessThan(1.0, $result['probability']);
        $this->assertNotEmpty($result['missing_edges']);
    }

    public function testStatisticsReturnsExpectedStructure(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $service = new EventDependencyGraphService($cache, $config);

        $stats = $service->statistics();

        $this->assertArrayHasKey('nodes', $stats);
        $this->assertArrayHasKey('edges', $stats);
        $this->assertArrayHasKey('required_edges', $stats);
        $this->assertArrayHasKey('expected_edges', $stats);
        $this->assertArrayHasKey('exclusive_edges', $stats);
        $this->assertArrayHasKey('cycles', $stats);
        $this->assertArrayHasKey('has_custom', $stats);
        $this->assertGreaterThan(0, $stats['nodes']);
        $this->assertGreaterThan(0, $stats['edges']);
    }

    public function testSummaryReturnsExpectedStructure(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $service = new EventDependencyGraphService($cache, $config);

        $summary = $service->summary();

        $this->assertArrayHasKey('enabled', $summary);
        $this->assertArrayHasKey('statistics', $summary);
        $this->assertArrayHasKey('critical_paths', $summary);
    }

    public function testViolationsAreRecordedAndRetrievable(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $service = new EventDependencyGraphService($cache, $config);

        // Trigger a violation
        $service->validate(
            new AnalyticsEvent(name: 'purchase'),
            'client_violation',
        );

        $violations = $service->getViolations('client_violation');

        $this->assertNotEmpty($violations);
        $this->assertSame('purchase', $violations[0]['event']);
        $this->assertNotEmpty($violations[0]['missing']);
    }

    public function testClearViolationsRemovesRecords(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $service = new EventDependencyGraphService($cache, $config);

        $service->validate(
            new AnalyticsEvent(name: 'purchase'),
            'client_clear',
        );

        $this->assertNotEmpty($service->getViolations('client_clear'));

        $service->clearViolations('client_clear');

        $this->assertEmpty($service->getViolations('client_clear'));
    }

    public function testDisabledServicePassesAllValidation(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.dependency_graph.enabled' => false,
        ]);
        $service = new EventDependencyGraphService($cache, $config);

        $result = $service->validate(
            new AnalyticsEvent(name: 'purchase'),
            'client_disabled',
        );

        $this->assertTrue($result['valid']);
        $this->assertTrue($service->validateBatch([
            new AnalyticsEvent(name: 'purchase'),
        ])['pass_rate'] === 1.0);
    }

    public function testNullClientIdSkipsValidation(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $service = new EventDependencyGraphService($cache, $config);

        $result = $service->validate(
            new AnalyticsEvent(name: 'purchase'),
            null,
        );

        $this->assertTrue($result['valid']);
    }

    public function testGetCriticalPathsReturnsNonEmptyList(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();
        $service = new EventDependencyGraphService($cache, $config);

        $paths = $service->getCriticalPaths();

        $this->assertNotEmpty($paths);
        $this->assertIsString($paths[0]);
    }

    // ──────────────────────────────────────────────────────
    // MultiCurrencyRevenueNormalizer Tests
    // ──────────────────────────────────────────────────────

    public function testMultiCurrencyIsDisabledByDefault(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.multi_currency.enabled' => false,
        ]);

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        $this->assertFalse($service->isEnabled());
    }

    public function testMultiCurrencyIsEnabledWhenConfigured(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.multi_currency.enabled' => true,
        ]);

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        $this->assertTrue($service->isEnabled());
    }

    public function testGetBaseCurrencyReturnsConfiguredValue(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.multi_currency.base_currency' => 'EUR',
        ]);

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        $this->assertSame('EUR', $service->getBaseCurrency());
    }

    public function testGetRateReturnsStaticRate(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.multi_currency.base_currency' => 'USD',
        ]);

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        // EUR rate from defaults is 0.92
        $rate = $service->getRate('EUR');

        $this->assertNotNull($rate);
        $this->assertSame(0.92, $rate);
    }

    public function testGetRateReturnsOneForBaseCurrency(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.multi_currency.base_currency' => 'USD',
        ]);

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        $this->assertSame(1.0, $service->getRate('USD'));
    }

    public function testGetRateReturnsNullForUnknownCurrency(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.multi_currency.base_currency' => 'USD',
        ]);

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        $this->assertNull($service->getRate('XYZ'));
    }

    public function testConvertValueBetweenCurrencies(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.multi_currency.base_currency' => 'USD',
        ]);

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        // 100 EUR → USD (rate: EUR=0.92, USD=1.0, cross: 1.0/0.92 ≈ 1.087)
        $result = $service->convertValue(100.0, 'EUR', 'USD');

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(108.70, $result, 0.01);
    }

    public function testConvertValueSameCurrencyReturnsSameAmount(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.multi_currency.base_currency' => 'USD',
        ]);

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        $result = $service->convertValue(100.0, 'USD', 'USD');

        $this->assertNotNull($result);
        $this->assertSame(100.0, $result);
    }

    public function testConvertValueUnknownCurrencyReturnsNull(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.multi_currency.base_currency' => 'USD',
        ]);

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        $this->assertNull($service->convertValue(100.0, 'EUR', 'XYZ'));
        $this->assertNull($service->convertValue(100.0, 'XYZ', 'USD'));
    }

    public function testSetRateStoresDynamicRate(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.multi_currency.base_currency' => 'USD',
        ]);

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        $result = $service->setRate('EUR', 0.95);
        $this->assertTrue($result);

        // New rate should be returned
        $rate = $service->getRate('EUR');
        $this->assertSame(0.95, $rate);
    }

    public function testSetRateRejectsNonPositive(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        $this->assertFalse($service->setRate('EUR', 0.0));
        $this->assertFalse($service->setRate('EUR', -1.0));
    }

    public function testSetRatesBulkUpdate(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        $result = $service->setRates([
            'EUR' => 0.90,
            'GBP' => 0.80,
        ]);

        $this->assertSame(2, $result['updated']);
        $this->assertSame(0, $result['failed']);
    }

    public function testDetectCurrencyFromEventParams(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        $this->assertSame('EUR', $service->detectCurrency(['currency' => 'EUR']));
        $this->assertSame('GBP', $service->detectCurrency(['Currency' => 'GBP']));
        $this->assertSame('JPY', $service->detectCurrency(['_currency' => 'JPY']));
        $this->assertNull($service->detectCurrency(['other' => 'value']));
        $this->assertNull($service->detectCurrency([]));
    }

    public function testDetectValueFromEventParams(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        $this->assertSame(99.99, $service->detectValue(['value' => 99.99]));
        $this->assertSame(50.0, $service->detectValue(['revenue' => 50.0]));
        $this->assertSame(25.0, $service->detectValue(['price' => 25.0]));
        $this->assertSame(10.0, $service->detectValue(['amount' => '10.0']));
        $this->assertNull($service->detectValue(['other' => 'not_a_number']));
        $this->assertNull($service->detectValue([]));
    }

    public function testNormalizeEventConvertsCorrectly(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.multi_currency.enabled' => true,
            'zeroboiler.analytics.multi_currency.base_currency' => 'USD',
        ]);

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['currency' => 'EUR', 'value' => 100.0],
        );

        $result = $service->normalizeEvent($event);

        $this->assertTrue($result['converted']);
        $this->assertSame('EUR', $result['from_currency']);
        $this->assertSame('USD', $result['to_currency']);
        $this->assertSame(100.0, $result['original_value']);
        $this->assertNotNull($result['converted_value']);
        $this->assertGreaterThan(100.0, $result['converted_value']); // EUR < USD
        $this->assertNotNull($result['rate']);

        // Check normalized params are injected
        $normalizedParams = $result['event']->params;
        $this->assertArrayHasKey('_normalized_currency', $normalizedParams);
        $this->assertSame('USD', $normalizedParams['_normalized_currency']);
        $this->assertArrayHasKey('_original_currency', $normalizedParams);
        $this->assertArrayHasKey('_exchange_rate', $normalizedParams);
        $this->assertTrue($normalizedParams['_currency_converted']);
    }

    public function testNormalizeEventSkipsWhenDisabled(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.multi_currency.enabled' => false,
            'zeroboiler.analytics.multi_currency.base_currency' => 'USD',
        ]);

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['currency' => 'EUR', 'value' => 100.0],
        );

        $result = $service->normalizeEvent($event);

        $this->assertFalse($result['converted']);
        $this->assertNull($result['from_currency']);
    }

    public function testNormalizeEventSkipsBaseCurrency(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.multi_currency.enabled' => true,
            'zeroboiler.analytics.multi_currency.base_currency' => 'USD',
        ]);

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['currency' => 'USD', 'value' => 100.0],
        );

        $result = $service->normalizeEvent($event);

        $this->assertFalse($result['converted']);
    }

    public function testNormalizeEventSkipsMissingCurrency(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.multi_currency.enabled' => true,
        ]);

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 100.0],
        );

        $result = $service->normalizeEvent($event);

        $this->assertFalse($result['converted']);
    }

    public function testNormalizeBatchCalculatesTotals(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.multi_currency.enabled' => true,
            'zeroboiler.analytics.multi_currency.base_currency' => 'USD',
        ]);

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        $events = [
            new AnalyticsEvent(name: 'purchase', params: ['currency' => 'EUR', 'value' => 100.0]),
            new AnalyticsEvent(name: 'purchase', params: ['currency' => 'GBP', 'value' => 50.0]),
            new AnalyticsEvent(name: 'purchase', params: ['value' => 25.0]), // no currency, skip
        ];

        $result = $service->normalizeBatch($events);

        $this->assertSame(3, $result['total_events']);
        $this->assertSame(2, $result['converted_count']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertContains('EUR', $result['currencies_detected']);
        $this->assertContains('GBP', $result['currencies_detected']);
        $this->assertArrayHasKey('EUR', $result['total_original']);
        $this->assertArrayHasKey('GBP', $result['total_original']);
        $this->assertSame(100.0, $result['total_original']['EUR']);
        $this->assertSame(50.0, $result['total_original']['GBP']);
        $this->assertGreaterThan(0.0, $result['total_normalized']);
    }

    public function testGetAllRatesReturnsMergedRates(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository();

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        $rates = $service->getAllRates();

        $this->assertArrayHasKey('USD', $rates);
        $this->assertArrayHasKey('EUR', $rates);
        $this->assertArrayHasKey('GBP', $rates);
        $this->assertSame(1.0, $rates['USD']);
    }

    public function testStatisticsReturnsExpectedStructure(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.multi_currency.enabled' => true,
        ]);

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        $stats = $service->statistics();

        $this->assertArrayHasKey('enabled', $stats);
        $this->assertArrayHasKey('base_currency', $stats);
        $this->assertArrayHasKey('available_currencies', $stats);
        $this->assertArrayHasKey('rates_source', $stats);
        $this->assertArrayHasKey('stale_rates', $stats);
        $this->assertArrayHasKey('stale_count', $stats);
        $this->assertTrue($stats['enabled']);
        $this->assertGreaterThan(0, $stats['available_currencies']);
    }

    public function testSummaryReturnsExpectedStructure(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.multi_currency.enabled' => true,
        ]);

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        $summary = $service->summary();

        $this->assertArrayHasKey('enabled', $summary);
        $this->assertArrayHasKey('base_currency', $summary);
        $this->assertArrayHasKey('statistics', $summary);
    }

    public function testJpyRoundingUsesZeroDecimals(): void
    {
        $cache = $this->createCacheRepository();
        $config = $this->createConfigRepository([
            'zeroboiler.analytics.multi_currency.enabled' => true,
            'zeroboiler.analytics.multi_currency.base_currency' => 'JPY',
        ]);

        $service = new MultiCurrencyRevenueNormalizer($cache, $config);

        $result = $service->convertValue(100.0, 'USD', 'JPY');

        $this->assertNotNull($result);
        // Should be rounded to integer
        $this->assertSame((float) round($result), $result);
    }

    // ──────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────

    /**
     * Create a simple in-memory cache repository mock.
     */
    private function createCacheRepository(): \Illuminate\Contracts\Cache\Repository
    {
        $store = new class implements \Illuminate\Contracts\Cache\Store
        {
            /** @var array<string, mixed> */
            private array $data = [];

            public function get($key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function many(array $keys): array
            {
                $results = [];
                foreach ($keys as $key) {
                    $results[$key] = $this->data[$key] ?? null;
                }

                return $results;
            }

            public function put($key, $value, $seconds): bool
            {
                $this->data[$key] = $value;

                return true;
            }

            public function putMany(array $values, $seconds): bool
            {
                foreach ($values as $key => $value) {
                    $this->data[$key] = $value;
                }

                return true;
            }

            public function increment($key, $value = 1): int|bool
            {
                $current = $this->data[$key] ?? 0;
                $this->data[$key] = $current + $value;

                return $this->data[$key];
            }

            public function decrement($key, $value = 1): int|bool
            {
                $current = $this->data[$key] ?? 0;
                $this->data[$key] = $current - $value;

                return $this->data[$key];
            }

            public function forget($key): bool
            {
                unset($this->data[$key]);

                return true;
            }

            public function flush(): bool
            {
                $this->data = [];

                return true;
            }

            public function getPrefix(): string
            {
                return '';
            }
        };

        return new \Illuminate\Cache\Repository($store);
    }

    /**
     * Create a config repository mock with optional overrides.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createConfigRepository(array $overrides = []): \Illuminate\Contracts\Config\Repository
    {
        $defaults = [
            'zeroboiler.analytics.dependency_graph.enabled' => true,
            'zeroboiler.analytics.dependency_graph.cache_prefix' => 'zb_edg_',
            'zeroboiler.analytics.dependency_graph.cache_ttl' => 86400,
            'zeroboiler.analytics.dependency_graph.violation_ttl' => 3600,
            'zeroboiler.analytics.dependency_graph.max_violations' => 100,
            'zeroboiler.analytics.multi_currency.enabled' => false,
            'zeroboiler.analytics.multi_currency.base_currency' => 'USD',
            'zeroboiler.analytics.multi_currency.cache_prefix' => 'zb_fx_',
            'zeroboiler.analytics.multi_currency.rate_ttl' => 86400,
            'zeroboiler.analytics.multi_currency.rounding' => 'currency',
            'zeroboiler.analytics.multi_currency.stale_threshold' => 0.1,
            'zeroboiler.analytics.multi_currency.rates' => [],
        ];

        $values = array_merge($defaults, $overrides);

        return new class($values) implements \Illuminate\Contracts\Config\Repository
        {
            /** @param  array<string, mixed>  $values */
            public function __construct(
                private readonly array $values,
            ) {}

            public function has($key): bool
            {
                return array_key_exists($key, $this->values);
            }

            public function get($key, $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }

            public function all(): array
            {
                return $this->values;
            }

            public function set($key, $value = null): void
            {
                // No-op for test
            }

            public function prepend($key, $value): void
            {
                // No-op for test
            }

            public function push($key, $value): void
            {
                // No-op for test
            }
        };
    }
}
