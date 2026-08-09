<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Services\DataQualityScorer;
use ZeroBoiler\Analytics\Services\EventDeprecationService;
use ZeroBoiler\Analytics\Services\EventGovernanceService;
use ZeroBoiler\Analytics\Services\EventNamingConventionService;

test('EventNamingConventionService validates snake_case event names', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.naming', [])
        ->andReturn([
            'format' => 'snake_case',
            'max_length' => 100,
            'min_length' => 2,
            'disallowed_patterns' => [],
            'custom_prefixes' => [],
            'reserved_prefixes' => ['$', 'zb_', 'amp_'],
            'custom_pattern' => null,
        ]);

    $service = new EventNamingConventionService($config);

    // Valid snake_case names
    expect($service->validate('page_view')['valid'])->toBeTrue();
    expect($service->validate('add_to_cart')['valid'])->toBeTrue();
    expect($service->validate('purchase')['valid'])->toBeTrue();
    expect($service->validate('start_trial')['valid'])->toBeTrue();
});

test('EventNamingConventionService rejects camelCase in snake_case mode', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.naming', [])
        ->andReturn([
            'format' => 'snake_case',
            'max_length' => 100,
            'min_length' => 2,
            'disallowed_patterns' => [],
            'custom_prefixes' => [],
            'reserved_prefixes' => ['$', 'zb_'],
            'custom_pattern' => null,
        ]);

    $service = new EventNamingConventionService($config);

    $result = $service->validate('addToCart');
    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toContain('Event name must be snake_case (e.g., \'add_to_cart\'), got \'addToCart\'');
});

test('EventNamingConventionService rejects reserved prefixes', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.naming', [])
        ->andReturn([
            'format' => 'snake_case',
            'max_length' => 100,
            'min_length' => 2,
            'disallowed_patterns' => [],
            'custom_prefixes' => [],
            'reserved_prefixes' => ['$', 'zb_', 'amp_'],
            'custom_pattern' => null,
        ]);

    $service = new EventNamingConventionService($config);

    expect($service->validate('$pageview')['valid'])->toBeFalse();
    expect($service->validate('zb_custom_event')['valid'])->toBeFalse();
    expect($service->validate('amp_signup')['valid'])->toBeFalse();
});

test('EventNamingConventionService rejects names exceeding max length', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.naming', [])
        ->andReturn([
            'format' => 'snake_case',
            'max_length' => 50,
            'min_length' => 2,
            'disallowed_patterns' => [],
            'custom_prefixes' => [],
            'reserved_prefixes' => [],
            'custom_pattern' => null,
        ]);

    $service = new EventNamingConventionService($config);
    $longName = str_repeat('a', 51);

    $result = $service->validate($longName);
    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toContain("Event name must not exceed 50 characters, got 51");
});

test('EventNamingConventionService normalizes to snake_case', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.naming', [])
        ->andReturn([
            'format' => 'snake_case',
            'max_length' => 100,
            'min_length' => 2,
            'disallowed_patterns' => [],
            'custom_prefixes' => [],
            'reserved_prefixes' => [],
            'custom_pattern' => null,
        ]);

    $service = new EventNamingConventionService($config);

    expect($service->normalize('addToCart'))->toBe('add_to_cart');
    expect($service->normalize('PageView'))->toBe('page_view');
    expect($service->normalize('startTrial'))->toBe('start_trial');
    expect($service->normalize('already_snake'))->toBe('already_snake');
});

test('EventNamingConventionService validates with custom regex pattern', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.naming', [])
        ->andReturn([
            'format' => 'custom',
            'max_length' => 100,
            'min_length' => 2,
            'disallowed_patterns' => [],
            'custom_prefixes' => [],
            'reserved_prefixes' => [],
            'custom_pattern' => '/^[a-z]+\.[a-z]+$/',
        ]);

    $service = new EventNamingConventionService($config);

    expect($service->validate('user.click')['valid'])->toBeTrue();
    expect($service->validate('button_click')['valid'])->toBeFalse();
});

test('EventNamingConventionService returns catalog compliance score', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.naming', [])
        ->andReturn([
            'format' => 'snake_case',
            'max_length' => 100,
            'min_length' => 2,
            'disallowed_patterns' => [],
            'custom_prefixes' => [],
            'reserved_prefixes' => [],
            'custom_pattern' => null,
        ]);

    $service = new EventNamingConventionService($config);

    $score = $service->catalogComplianceScore();
    expect($score)->toBeFloat();
    expect($score)->toBeGreaterThanOrEqual(0.0);
    expect($score)->toBeLessThanOrEqual(100.0);
});

test('EventNamingConventionService returns summary', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.naming', [])
        ->andReturn([
            'format' => 'snake_case',
            'max_length' => 100,
            'min_length' => 2,
            'disallowed_patterns' => [],
            'custom_prefixes' => ['app_'],
            'reserved_prefixes' => ['$', 'zb_'],
            'custom_pattern' => null,
        ]);

    $service = new EventNamingConventionService($config);
    $summary = $service->summary();

    expect($summary)->toHaveKey('format');
    expect($summary)->toHaveKey('max_length');
    expect($summary)->toHaveKey('min_length');
    expect($summary)->toHaveKey('reserved_prefixes');
    expect($summary)->toHaveKey('custom_prefixes');
    expect($summary['format'])->toBe('snake_case');
});

test('EventGovernanceService registers events', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance', [])
        ->andReturn([
            'enabled' => true,
            'enforce_on_dispatch' => false,
            'cache_ttl' => 3600,
            'reserved_prefixes' => ['$', 'zb_'],
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.naming', [])
        ->andReturn([
            'format' => 'snake_case',
            'max_length' => 100,
            'min_length' => 2,
            'disallowed_patterns' => [],
            'custom_prefixes' => [],
            'reserved_prefixes' => ['$', 'zb_'],
            'custom_pattern' => null,
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.deprecation', [])
        ->andReturn(['default_sunset_days' => 30]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.quality', [])
        ->andReturn([
            'cache_ttl' => 3600,
            'min_sample_size' => 10,
            'weights' => [
                'completeness' => 0.35,
                'consistency' => 0.30,
                'timeliness' => 0.15,
                'validity' => 0.20,
            ],
        ]);

    $service = new EventGovernanceService($cache, $config);

    $result = $service->register(
        'custom_button_click',
        'engagement',
        'product-team',
        'User clicked a custom button',
        ['button_id'],
        ['button_label'],
    );

    expect($result['success'])->toBeTrue();
    expect($result['event'])->toBe('custom_button_click');
    expect($result['errors'])->toBeEmpty();

    // Should be in draft status
    $registration = $service->getRegistration('custom_button_click');
    expect($registration)->not->toBeNull();
    expect($registration['status'])->toBe('draft');
    expect($registration['owner'])->toBe('product-team');
});

test('EventGovernanceService rejects duplicate registration', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance', [])
        ->andReturn([
            'enabled' => true,
            'enforce_on_dispatch' => false,
            'cache_ttl' => 3600,
            'reserved_prefixes' => ['$', 'zb_'],
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.naming', [])
        ->andReturn([
            'format' => 'snake_case',
            'max_length' => 100,
            'min_length' => 2,
            'disallowed_patterns' => [],
            'custom_prefixes' => [],
            'reserved_prefixes' => ['$', 'zb_'],
            'custom_pattern' => null,
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.deprecation', [])
        ->andReturn(['default_sunset_days' => 30]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.quality', [])
        ->andReturn([
            'cache_ttl' => 3600,
            'min_sample_size' => 10,
            'weights' => [
                'completeness' => 0.35,
                'consistency' => 0.30,
                'timeliness' => 0.15,
                'validity' => 0.20,
            ],
        ]);

    $service = new EventGovernanceService($cache, $config);

    $service->register('my_event', 'custom', 'team', 'desc');
    $result = $service->register('my_event', 'custom', 'other-team', 'desc2');

    expect($result['success'])->toBeFalse();
    expect($result['errors'])->toContain("Event 'my_event' is already registered");
});

test('EventGovernanceService activates draft events', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance', [])
        ->andReturn([
            'enabled' => true,
            'enforce_on_dispatch' => false,
            'cache_ttl' => 3600,
            'reserved_prefixes' => [],
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.naming', [])
        ->andReturn([
            'format' => 'snake_case',
            'max_length' => 100,
            'min_length' => 2,
            'disallowed_patterns' => [],
            'custom_prefixes' => [],
            'reserved_prefixes' => [],
            'custom_pattern' => null,
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.deprecation', [])
        ->andReturn(['default_sunset_days' => 30]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.quality', [])
        ->andReturn([
            'cache_ttl' => 3600,
            'min_sample_size' => 10,
            'weights' => [
                'completeness' => 0.35,
                'consistency' => 0.30,
                'timeliness' => 0.15,
                'validity' => 0.20,
            ],
        ]);

    $service = new EventGovernanceService($cache, $config);

    $service->register('new_event', 'engagement', 'team', 'A new event');
    $result = $service->activate('new_event');

    expect($result['success'])->toBeTrue();
    expect($service->getRegistration('new_event')['status'])->toBe('active');
});

test('EventGovernanceService deprecates active events', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance', [])
        ->andReturn([
            'enabled' => true,
            'enforce_on_dispatch' => false,
            'cache_ttl' => 3600,
            'reserved_prefixes' => [],
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.naming', [])
        ->andReturn([
            'format' => 'snake_case',
            'max_length' => 100,
            'min_length' => 2,
            'disallowed_patterns' => [],
            'custom_prefixes' => [],
            'reserved_prefixes' => [],
            'custom_pattern' => null,
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.deprecation', [])
        ->andReturn(['default_sunset_days' => 30]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.quality', [])
        ->andReturn([
            'cache_ttl' => 3600,
            'min_sample_size' => 10,
            'weights' => [
                'completeness' => 0.35,
                'consistency' => 0.30,
                'timeliness' => 0.15,
                'validity' => 0.20,
            ],
        ]);

    $service = new EventGovernanceService($cache, $config);

    $service->register('old_event', 'engagement', 'team', 'An old event');
    $service->activate('old_event');
    $result = $service->deprecate('old_event', 'new_event');

    expect($result['success'])->toBeTrue();
    expect($service->getRegistration('old_event')['status'])->toBe('deprecated');
    expect($service->getRegistration('old_event')['deprecated_at'])->not->toBeNull();
});

test('EventGovernanceService retires deprecated events', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance', [])
        ->andReturn([
            'enabled' => true,
            'enforce_on_dispatch' => false,
            'cache_ttl' => 3600,
            'reserved_prefixes' => [],
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.naming', [])
        ->andReturn([
            'format' => 'snake_case',
            'max_length' => 100,
            'min_length' => 2,
            'disallowed_patterns' => [],
            'custom_prefixes' => [],
            'reserved_prefixes' => [],
            'custom_pattern' => null,
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.deprecation', [])
        ->andReturn(['default_sunset_days' => 30]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.quality', [])
        ->andReturn([
            'cache_ttl' => 3600,
            'min_sample_size' => 10,
            'weights' => [
                'completeness' => 0.35,
                'consistency' => 0.30,
                'timeliness' => 0.15,
                'validity' => 0.20,
            ],
        ]);

    $service = new EventGovernanceService($cache, $config);

    $service->register('dead_event', 'engagement', 'team', 'To be retired');
    $service->activate('dead_event');
    $service->deprecate('dead_event');
    $result = $service->retire('dead_event');

    expect($result['success'])->toBeTrue();
    expect($service->getRegistration('dead_event')['status'])->toBe('retired');
});

test('EventGovernanceService validates dispatch against governance', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance', [])
        ->andReturn([
            'enabled' => true,
            'enforce_on_dispatch' => true,
            'cache_ttl' => 3600,
            'reserved_prefixes' => ['$', 'zb_'],
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.naming', [])
        ->andReturn([
            'format' => 'snake_case',
            'max_length' => 100,
            'min_length' => 2,
            'disallowed_patterns' => [],
            'custom_prefixes' => [],
            'reserved_prefixes' => ['$', 'zb_'],
            'custom_pattern' => null,
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.deprecation', [])
        ->andReturn(['default_sunset_days' => 30]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.quality', [])
        ->andReturn([
            'cache_ttl' => 3600,
            'min_sample_size' => 10,
            'weights' => [
                'completeness' => 0.35,
                'consistency' => 0.30,
                'timeliness' => 0.15,
                'validity' => 0.20,
            ],
        ]);

    $service = new EventGovernanceService($cache, $config);

    // Register an event with required params
    $service->register('form_submit', 'engagement', 'team', 'Form submitted', ['form_id']);
    $service->activate('form_submit');

    // Dispatch with missing required param
    $result = $service->validateDispatch('form_submit', ['form_name' => 'contact']);
    expect($result['allowed'])->toBeFalse();
    expect($result['errors'])->toContain("Missing required parameter 'form_id' for event 'form_submit'");

    // Dispatch with all params
    $result2 = $service->validateDispatch('form_submit', ['form_id' => 'contact', 'form_name' => 'Contact']);
    expect($result2['allowed'])->toBeTrue();
});

test('EventGovernanceService blocks retired event dispatch', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance', [])
        ->andReturn([
            'enabled' => true,
            'enforce_on_dispatch' => true,
            'cache_ttl' => 3600,
            'reserved_prefixes' => [],
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.naming', [])
        ->andReturn([
            'format' => 'snake_case',
            'max_length' => 100,
            'min_length' => 2,
            'disallowed_patterns' => [],
            'custom_prefixes' => [],
            'reserved_prefixes' => [],
            'custom_pattern' => null,
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.deprecation', [])
        ->andReturn(['default_sunset_days' => 30]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.quality', [])
        ->andReturn([
            'cache_ttl' => 3600,
            'min_sample_size' => 10,
            'weights' => [
                'completeness' => 0.35,
                'consistency' => 0.30,
                'timeliness' => 0.15,
                'validity' => 0.20,
            ],
        ]);

    $service = new EventGovernanceService($cache, $config);

    $service->register('dead', 'custom', 'team', 'dead event');
    $service->activate('dead');
    $service->deprecate('dead');
    $service->retire('dead');

    $result = $service->validateDispatch('dead', []);
    expect($result['allowed'])->toBeFalse();
    expect($result['errors'])->toContain("Event 'dead' has been retired and cannot be dispatched");
});

test('EventGovernanceService skips validation when disabled', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance', [])
        ->andReturn([
            'enabled' => false,
            'enforce_on_dispatch' => false,
            'cache_ttl' => 3600,
            'reserved_prefixes' => [],
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.naming', [])
        ->andReturn([
            'format' => 'snake_case',
            'max_length' => 100,
            'min_length' => 2,
            'disallowed_patterns' => [],
            'custom_prefixes' => [],
            'reserved_prefixes' => [],
            'custom_pattern' => null,
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.deprecation', [])
        ->andReturn(['default_sunset_days' => 30]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.quality', [])
        ->andReturn([
            'cache_ttl' => 3600,
            'min_sample_size' => 10,
            'weights' => [
                'completeness' => 0.35,
                'consistency' => 0.30,
                'timeliness' => 0.15,
                'validity' => 0.20,
            ],
        ]);

    $service = new EventGovernanceService($cache, $config);

    expect($service->isEnabled())->toBeFalse();

    $result = $service->validateDispatch('any_event', []);
    expect($result['allowed'])->toBeTrue();
    expect($result['governance_status'])->toBeNull();
});

test('EventGovernanceService generates governance report', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance', [])
        ->andReturn([
            'enabled' => true,
            'enforce_on_dispatch' => false,
            'cache_ttl' => 3600,
            'reserved_prefixes' => [],
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.naming', [])
        ->andReturn([
            'format' => 'snake_case',
            'max_length' => 100,
            'min_length' => 2,
            'disallowed_patterns' => [],
            'custom_prefixes' => [],
            'reserved_prefixes' => [],
            'custom_pattern' => null,
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.deprecation', [])
        ->andReturn(['default_sunset_days' => 30]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.quality', [])
        ->andReturn([
            'cache_ttl' => 3600,
            'min_sample_size' => 10,
            'weights' => [
                'completeness' => 0.35,
                'consistency' => 0.30,
                'timeliness' => 0.15,
                'validity' => 0.20,
            ],
        ]);

    $service = new EventGovernanceService($cache, $config);

    $service->register('event_a', 'engagement', 'team', 'Event A');
    $service->activate('event_a');
    $service->register('event_b', 'custom', 'team', 'Event B'); // stays draft

    $report = $service->report();

    expect($report)->toHaveKey('total_events');
    expect($report)->toHaveKey('active');
    expect($report)->toHaveKey('draft');
    expect($report)->toHaveKey('deprecated');
    expect($report)->toHaveKey('retired');
    expect($report)->toHaveKey('catalog_coverage');
    expect($report)->toHaveKey('naming_score');
    expect($report)->toHaveKey('quality_score');
    expect($report)->toHaveKey('duplicate_risk');
    expect($report)->toHaveKey('governance_score');
    expect($report['total_events'])->toBe(2);
    expect($report['active'])->toBe(1);
    expect($report['draft'])->toBe(1);
});

test('EventGovernanceService attention required returns draft and deprecated events', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance', [])
        ->andReturn([
            'enabled' => true,
            'enforce_on_dispatch' => false,
            'cache_ttl' => 3600,
            'reserved_prefixes' => [],
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.naming', [])
        ->andReturn([
            'format' => 'snake_case',
            'max_length' => 100,
            'min_length' => 2,
            'disallowed_patterns' => [],
            'custom_prefixes' => [],
            'reserved_prefixes' => [],
            'custom_pattern' => null,
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.deprecation', [])
        ->andReturn(['default_sunset_days' => 30]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.quality', [])
        ->andReturn([
            'cache_ttl' => 3600,
            'min_sample_size' => 10,
            'weights' => [
                'completeness' => 0.35,
                'consistency' => 0.30,
                'timeliness' => 0.15,
                'validity' => 0.20,
            ],
        ]);

    $service = new EventGovernanceService($cache, $config);

    $service->register('draft_event', 'custom', 'team-a', 'Needs activation');
    $service->register('active_event', 'engagement', 'team-b', 'Active event');
    $service->activate('active_event');
    $service->deprecate('active_event');

    $attention = $service->attentionRequired();

    expect($attention)->toHaveCount(2);

    $draft = array_filter($attention, fn (array $a): bool => $a['status'] === 'draft');
    $deprecated = array_filter($attention, fn (array $a): bool => $a['status'] === 'deprecated');

    expect(count($draft))->toBe(1);
    expect(count($deprecated))->toBe(1);
});

test('EventGovernanceService rejects registration with reserved prefix', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance', [])
        ->andReturn([
            'enabled' => true,
            'enforce_on_dispatch' => false,
            'cache_ttl' => 3600,
            'reserved_prefixes' => ['$', 'zb_', 'amp_'],
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.naming', [])
        ->andReturn([
            'format' => 'snake_case',
            'max_length' => 100,
            'min_length' => 2,
            'disallowed_patterns' => [],
            'custom_prefixes' => [],
            'reserved_prefixes' => ['$', 'zb_', 'amp_'],
            'custom_pattern' => null,
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.deprecation', [])
        ->andReturn(['default_sunset_days' => 30]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.quality', [])
        ->andReturn([
            'cache_ttl' => 3600,
            'min_sample_size' => 10,
            'weights' => [
                'completeness' => 0.35,
                'consistency' => 0.30,
                'timeliness' => 0.15,
                'validity' => 0.20,
            ],
        ]);

    $service = new EventGovernanceService($cache, $config);

    $result = $service->register('$reserved_event', 'engagement', 'team', 'Using reserved prefix');
    expect($result['success'])->toBeFalse();
    expect($result['errors'])->toContain("Event name '\$reserved_event' uses reserved prefix '\$'");
});

test('EventDeprecationService deprecates and tracks dispatches', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.deprecation', [])
        ->andReturn(['default_sunset_days' => 30]);

    $service = new EventDeprecationService($cache, $config);

    $result = $service->deprecate('old_click', 'new_click');
    expect($result['success'])->toBeTrue();

    expect($service->isDeprecated('old_click'))->toBeTrue();
    expect($service->getReplacement('old_click'))->toBe('new_click');
    expect($service->isDeprecated('nonexistent'))->toBeFalse();

    // Track a dispatch
    $track = $service->trackDispatch('old_click');
    expect($track['deprecated'])->toBeTrue();
    expect($track['replacement'])->toBe('new_click');
    expect($track['sunset_expired'])->toBeFalse();
});

test('EventDeprecationService summary returns counts', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.deprecation', [])
        ->andReturn(['default_sunset_days' => 30]);

    $service = new EventDeprecationService($cache, $config);

    $service->deprecate('event_a', 'event_b_new');
    $service->deprecate('event_c'); // no replacement

    $summary = $service->summary();
    expect($summary['total_deprecated'])->toBe(2);
    expect($summary['with_replacement'])->toBe(1);
});

test('DataQualityScorer records events and calculates scores', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.quality', [])
        ->andReturn([
            'cache_ttl' => 3600,
            'min_sample_size' => 2,
            'weights' => [
                'completeness' => 0.35,
                'consistency' => 0.30,
                'timeliness' => 0.15,
                'validity' => 0.20,
            ],
        ]);

    $scorer = new DataQualityScorer($cache, $config);

    // Record valid events
    $scorer->record('page_view', ['url' => '/'], true);
    $scorer->record('page_view', ['url' => '/about'], true);
    $scorer->record('click', ['element' => 'button'], true, ['target_url']);

    $report = $scorer->report();

    expect($report)->toHaveKey('overall_score');
    expect($report)->toHaveKey('dimensions');
    expect($report)->toHaveKey('grade');
    expect($report['total_events_scored'])->toBe(3);
    expect($report['grade'])->toBeString();
});

test('DataQualityScorer returns empty report with insufficient data', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.quality', [])
        ->andReturn([
            'cache_ttl' => 3600,
            'min_sample_size' => 10,
            'weights' => [
                'completeness' => 0.35,
                'consistency' => 0.30,
                'timeliness' => 0.15,
                'validity' => 0.20,
            ],
        ]);

    $scorer = new DataQualityScorer($cache, $config);

    $scorer->record('page_view', ['url' => '/'], true);

    $report = $scorer->report();
    expect($report['overall_score'])->toBe(0.0);
});

test('DataQualityScorer grades correctly', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.quality', [])
        ->andReturn([
            'cache_ttl' => 3600,
            'min_sample_size' => 1,
            'weights' => [
                'completeness' => 0.35,
                'consistency' => 0.30,
                'timeliness' => 0.15,
                'validity' => 0.20,
            ],
        ]);

    $scorer = new DataQualityScorer($cache, $config);

    expect($scorer->grade(100.0))->toBe('A');
    expect($scorer->grade(95.0))->toBe('A');
    expect($scorer->grade(87.0))->toBe('B');
    expect($scorer->grade(72.0))->toBe('C');
    expect($scorer->grade(55.0))->toBe('D');
    expect($scorer->grade(30.0))->toBe('F');
});

test('EventGovernanceService sub-services are accessible', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance', [])
        ->andReturn([
            'enabled' => true,
            'enforce_on_dispatch' => false,
            'cache_ttl' => 3600,
            'reserved_prefixes' => [],
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.naming', [])
        ->andReturn([
            'format' => 'snake_case',
            'max_length' => 100,
            'min_length' => 2,
            'disallowed_patterns' => [],
            'custom_prefixes' => [],
            'reserved_prefixes' => [],
            'custom_pattern' => null,
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.deprecation', [])
        ->andReturn(['default_sunset_days' => 30]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.quality', [])
        ->andReturn([
            'cache_ttl' => 3600,
            'min_sample_size' => 10,
            'weights' => [
                'completeness' => 0.35,
                'consistency' => 0.30,
                'timeliness' => 0.15,
                'validity' => 0.20,
            ],
        ]);

    $service = new EventGovernanceService($cache, $config);

    expect($service->naming())->toBeInstanceOf(EventNamingConventionService::class);
    expect($service->deprecation())->toBeInstanceOf(EventDeprecationService::class);
    expect($service->quality())->toBeInstanceOf(DataQualityScorer::class);
});

test('EventGovernanceService filters registrations by status', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance', [])
        ->andReturn([
            'enabled' => true,
            'enforce_on_dispatch' => false,
            'cache_ttl' => 3600,
            'reserved_prefixes' => [],
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.naming', [])
        ->andReturn([
            'format' => 'snake_case',
            'max_length' => 100,
            'min_length' => 2,
            'disallowed_patterns' => [],
            'custom_prefixes' => [],
            'reserved_prefixes' => [],
            'custom_pattern' => null,
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.deprecation', [])
        ->andReturn(['default_sunset_days' => 30]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.quality', [])
        ->andReturn([
            'cache_ttl' => 3600,
            'min_sample_size' => 10,
            'weights' => [
                'completeness' => 0.35,
                'consistency' => 0.30,
                'timeliness' => 0.15,
                'validity' => 0.20,
            ],
        ]);

    $service = new EventGovernanceService($cache, $config);

    $service->register('ev1', 'engagement', 'team', 'E1');
    $service->activate('ev1');
    $service->register('ev2', 'custom', 'team', 'E2');

    expect(count($service->registrations()))->toBe(2);
    expect(count($service->registrations('active')))->toBe(1);
    expect(count($service->registrations('draft')))->toBe(1);
    expect(count($service->registrations('deprecated')))->toBe(0);
});

test('EventGovernanceService rejects invalid category', function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('put')->andReturn(true);

    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance', [])
        ->andReturn([
            'enabled' => true,
            'enforce_on_dispatch' => false,
            'cache_ttl' => 3600,
            'reserved_prefixes' => [],
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.naming', [])
        ->andReturn([
            'format' => 'snake_case',
            'max_length' => 100,
            'min_length' => 2,
            'disallowed_patterns' => [],
            'custom_prefixes' => [],
            'reserved_prefixes' => [],
            'custom_pattern' => null,
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.deprecation', [])
        ->andReturn(['default_sunset_days' => 30]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.governance.quality', [])
        ->andReturn([
            'cache_ttl' => 3600,
            'min_sample_size' => 10,
            'weights' => [
                'completeness' => 0.35,
                'consistency' => 0.30,
                'timeliness' => 0.15,
                'validity' => 0.20,
            ],
        ]);

    $service = new EventGovernanceService($cache, $config);

    $result = $service->register('my_event', 'invalid_category', 'team', 'desc');
    expect($result['success'])->toBeFalse();
    expect($result['errors'][0])->toContain("Invalid category 'invalid_category'");
});
