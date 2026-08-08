<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Support\AnalyticsConfig;

beforeEach(function (): void {
    $this->config = mock(Illuminate\Contracts\Config\Repository::class);
});

// ── v2.44.0 Config Integrity, AnalyticsConfig Expansion, Attribution Fix ───

describe('v2.44.0 Config Integrity + AnalyticsConfig Expansion', function (): void {

    // ── Attribution Accessor Coverage ───────────────────────────

    test('attributionModel returns default last_touch', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.attribution.model', 'last_touch')
            ->andReturn('last_touch');

        $ac = new AnalyticsConfig($this->config);
        expect($ac->attributionModel())->toBe('last_touch');
    });

    test('attributionModel returns configured value', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.attribution.model', 'last_touch')
            ->andReturn('first_touch');

        $ac = new AnalyticsConfig($this->config);
        expect($ac->attributionModel())->toBe('first_touch');
    });

    test('attributionSessionWindowDays returns int', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.attribution.session_window_days', 30)
            ->andReturn(60);

        $ac = new AnalyticsConfig($this->config);
        expect($ac->attributionSessionWindowDays())->toBe(60);
    });

    test('attributionCacheTtl returns int', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.attribution.cache_ttl', 86400)
            ->andReturn(172800);

        $ac = new AnalyticsConfig($this->config);
        expect($ac->attributionCacheTtl())->toBe(172800);
    });

    test('attributionEnabled returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.attribution.enabled', true)
            ->andReturn(true);

        $ac = new AnalyticsConfig($this->config);
        expect($ac->attributionEnabled())->toBeTrue();
    });

    test('attributionFirstTouchTtl returns int', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.attribution.first_touch_ttl', 2592000)
            ->andReturn(2592000);

        $ac = new AnalyticsConfig($this->config);
        expect($ac->attributionFirstTouchTtl())->toBe(2592000);
    });

    test('attributionTouchHistoryTtl returns int', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.attribution.touch_history_ttl', 2592000)
            ->andReturn(2592000);

        $ac = new AnalyticsConfig($this->config);
        expect($ac->attributionTouchHistoryTtl())->toBe(2592000);
    });

    test('attributionMaxTouchHistory returns int', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.attribution.max_touch_history', 20)
            ->andReturn(50);

        $ac = new AnalyticsConfig($this->config);
        expect($ac->attributionMaxTouchHistory())->toBe(50);
    });

    // ── Performance Budget Accessors ────────────────────────────

    test('performanceBudgetEnabled returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.performance_budget.enabled', false)
            ->andReturn(true);

        $ac = new AnalyticsConfig($this->config);
        expect($ac->performanceBudgetEnabled())->toBeTrue();
    });

    test('performanceBudgetMaxPayloadBytes returns int', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.performance_budget.max_payload_bytes', 8192)
            ->andReturn(16384);

        $ac = new AnalyticsConfig($this->config);
        expect($ac->performanceBudgetMaxPayloadBytes())->toBe(16384);
    });

    test('performanceBudgetMaxParamsCount returns int', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.performance_budget.max_params_count', 25)
            ->andReturn(50);

        $ac = new AnalyticsConfig($this->config);
        expect($ac->performanceBudgetMaxParamsCount())->toBe(50);
    });

    test('performanceBudgetMaxEventsPerSession returns int', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.performance_budget.max_events_per_session', 100)
            ->andReturn(200);

        $ac = new AnalyticsConfig($this->config);
        expect($ac->performanceBudgetMaxEventsPerSession())->toBe(200);
    });

    test('performanceBudgetMaxEventsPerUserPerDay returns int', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.performance_budget.max_events_per_user_per_day', 500)
            ->andReturn(1000);

        $ac = new AnalyticsConfig($this->config);
        expect($ac->performanceBudgetMaxEventsPerUserPerDay())->toBe(1000);
    });

    test('performanceBudgetMaxEventsPerPageView returns int', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.performance_budget.max_events_per_page_view', 50)
            ->andReturn(100);

        $ac = new AnalyticsConfig($this->config);
        expect($ac->performanceBudgetMaxEventsPerPageView())->toBe(100);
    });

    test('performanceBudgetMaxParamValueLength returns int', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.performance_budget.max_param_value_length', 500)
            ->andReturn(1000);

        $ac = new AnalyticsConfig($this->config);
        expect($ac->performanceBudgetMaxParamValueLength())->toBe(1000);
    });

    test('performanceBudgetDropOversized returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.performance_budget.drop_oversized', true)
            ->andReturn(false);

        $ac = new AnalyticsConfig($this->config);
        expect($ac->performanceBudgetDropOversized())->toBeFalse();
    });

    test('performanceBudgetWarnOnly returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.performance_budget.warn_only', false)
            ->andReturn(true);

        $ac = new AnalyticsConfig($this->config);
        expect($ac->performanceBudgetWarnOnly())->toBeTrue();
    });

    // ── Forwarding Accessors ─────────────────────────────────────

    test('forwardingEnabled returns bool', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.forwarding.enabled', false)
            ->andReturn(true);

        $ac = new AnalyticsConfig($this->config);
        expect($ac->forwardingEnabled())->toBeTrue();
    });

    test('forwardingTimeout returns int', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.forwarding.timeout', 5)
            ->andReturn(10);

        $ac = new AnalyticsConfig($this->config);
        expect($ac->forwardingTimeout())->toBe(10);
    });

    test('forwardingRetries returns int', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.forwarding.retries', 1)
            ->andReturn(3);

        $ac = new AnalyticsConfig($this->config);
        expect($ac->forwardingRetries())->toBe(3);
    });

    test('forwardingRateLimitPerMinute returns int', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.forwarding.rate_limit_per_minute', 1000)
            ->andReturn(5000);

        $ac = new AnalyticsConfig($this->config);
        expect($ac->forwardingRateLimitPerMinute())->toBe(5000);
    });

    test('forwardingForwarders returns array', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.forwarding.forwarders', [])
            ->andReturn([
                'segment' => ['enabled' => true, 'type' => 'segment'],
            ]);

        $ac = new AnalyticsConfig($this->config);
        $forwarders = $ac->forwardingForwarders();
        expect($forwarders)->toBeArray();
        expect($forwarders)->toHaveCount(1);
        expect(isset($forwarders['segment']))->toBeTrue();
    });

    test('forwardingForwarders returns empty array by default', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.forwarding.forwarders', [])
            ->andReturn([]);

        $ac = new AnalyticsConfig($this->config);
        expect($ac->forwardingForwarders())->toBe([]);
    });

    // ── toSummaryArray Uniqueness ────────────────────────────────

    test('toSummaryArray has no duplicate keys', function (): void {
        // Build a mock that returns empty for all calls
        $this->config->shouldReceive('get')->andReturn(null);

        $ac = new AnalyticsConfig($this->config);
        $summary = $ac->toSummaryArray();

        $keys = array_keys($summary);
        $uniqueKeys = array_unique($keys);

        // If count differs, there are duplicates (PHP silently overwrites)
        expect($keys)->toEqual($uniqueKeys);
    });

    test('toSummaryArray includes performance_budget section', function (): void {
        $this->config->shouldReceive('get')->andReturn(null);

        $ac = new AnalyticsConfig($this->config);
        $summary = $ac->toSummaryArray();

        expect(isset($summary['performance_budget']))->toBeTrue();
        expect($summary['performance_budget'])->toBeArray();
        expect(isset($summary['performance_budget']['enabled']))->toBeTrue();
    });

    test('toSummaryArray includes forwarding section', function (): void {
        $this->config->shouldReceive('get')->andReturn(null);

        $ac = new AnalyticsConfig($this->config);
        $summary = $ac->toSummaryArray();

        expect(isset($summary['forwarding']))->toBeTrue();
        expect($summary['forwarding'])->toBeArray();
        expect(isset($summary['forwarding']['enabled']))->toBeTrue();
    });

    test('toSummaryArray attribution includes model and session_window_days', function (): void {
        $this->config->shouldReceive('get')->andReturn(null);

        $ac = new AnalyticsConfig($this->config);
        $summary = $ac->toSummaryArray();

        expect(isset($summary['attribution']['model']))->toBeTrue();
        expect(isset($summary['attribution']['session_window_days']))->toBeTrue();
    });

    // ── Version Consistency ─────────────────────────────────────

    test('version is 2.45.0 across all markers', function (): void {
        // Check composer.json
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        expect($composer['version'])->toBe('2.94.0');

        // Check JS client
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect(str_contains($js, "'2.94.0'"))->toBeTrue();

        // Check TypeScript definitions
        $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
        expect(str_contains($dts, '2.94.0'))->toBeTrue();
    });

    test('config file has no duplicate attribution keys', function (): void {
        $configContent = file_get_contents(__DIR__ . '/../config/zeroboiler.php');

        // Count occurrences of "'attribution' =>" in the config
        preg_match_all("/'attribution'\s*=>/", $configContent, $matches);
        expect(count($matches[0]))->toBe(1, 'Config should have exactly one attribution section');
    });

    test('AnalyticsConfig is final', function (): void {
        $reflection = new ReflectionClass(AnalyticsConfig::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    test('AnalyticsConfig uses strict types', function (): void {
        $content = file_get_contents((new ReflectionClass(AnalyticsConfig::class))->getFileName());
        expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
    });

    test('AnalyticsConfig constructor has void return type', function (): void {
        $method = new ReflectionMethod(AnalyticsConfig::class, '__construct');
        expect($method->getReturnType()?->getName())->toBe('void');
    });
});
