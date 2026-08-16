<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\CDP\CdpEventToProfileListener;
use ZeroBoiler\Analytics\CDP\CdpProfileService;
use ZeroBoiler\Analytics\CDP\CdpProfileSnapshot;
use ZeroBoiler\Analytics\CDP\CdpSegmentService;
use ZeroBoiler\Analytics\CDP\CdpTraitComputer;
use ZeroBoiler\Analytics\CDP\CdpTraitDefinition;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use Mockery;

test('CdpTraitDefinition creates static trait', function (): void {
    $def = CdpTraitDefinition::static(
        name: 'plan',
        type: 'string',
        category: 'identity',
        defaultValue: 'free',
        description: 'Current subscription plan',
    );

    expect($def->name)->toBe('plan');
    expect($def->type)->toBe('string');
    expect($def->category)->toBe('identity');
    expect($def->computed)->toBeFalse();
    expect($def->defaultValue)->toBe('free');
    expect($def->description)->toBe('Current subscription plan');
    expect($def->sourceEvent)->toBeNull();
    expect($def->aggregation)->toBeNull();
});

test('CdpTraitDefinition creates computed trait', function (): void {
    $def = CdpTraitDefinition::computed(
        name: 'total_revenue',
        sourceEvent: 'purchase',
        aggregation: 'sum',
        type: 'float',
        sourceField: 'revenue',
        recalculateIntervalSeconds: 300,
        defaultValue: 0.0,
    );

    expect($def->name)->toBe('total_revenue');
    expect($def->type)->toBe('float');
    expect($def->computed)->toBeTrue();
    expect($def->sourceEvent)->toBe('purchase');
    expect($def->aggregation)->toBe('sum');
    expect($def->sourceField)->toBe('revenue');
    expect($def->recalculateIntervalSeconds)->toBe(300);
});

test('CdpTraitDefinition serialization round-trips', function (): void {
    $def = CdpTraitDefinition::computed(
        name: 'session_count',
        sourceEvent: 'session_start',
        aggregation: 'count',
        type: 'int',
    );

    $arr = $def->toArray();
    $restored = CdpTraitDefinition::fromArray($arr);

    expect($restored->name)->toBe('session_count');
    expect($restored->computed)->toBeTrue();
    expect($restored->sourceEvent)->toBe('session_start');
    expect($restored->aggregation)->toBe('count');
});

test('CdpProfileSnapshot creates and accesses traits', function (): void {
    $snapshot = new CdpProfileSnapshot(
        userId: 'user_123',
        email: 'test@example.com',
        traits: ['plan' => 'pro', 'total_revenue' => 199.99],
        segments: ['power_user', 'high_value'],
        createdAt: 1700000000,
        updatedAt: 1700086400,
        totalEvents: 50,
        totalSessions: 10,
    );

    expect($snapshot->userId)->toBe('user_123');
    expect($snapshot->email)->toBe('test@example.com');
    expect($snapshot->getTrait('plan'))->toBe('pro');
    expect($snapshot->getTrait('total_revenue'))->toBe(199.99);
    expect($snapshot->getTrait('nonexistent', 'fallback'))->toBe('fallback');
    expect($snapshot->isInSegment('power_user'))->toBeTrue();
    expect($snapshot->isInSegment('free_tier'))->toBeFalse();
    expect($snapshot->segments)->toBe(['power_user', 'high_value']);
});

test('CdpProfileSnapshot computed properties', function (): void {
    $snapshot = new CdpProfileSnapshot(
        userId: 'user_456',
        createdAt: time() - (5 * 86400), // 5 days ago
        lastEventAt: time() - (2 * 86400), // 2 days ago
        totalEvents: 100,
        totalSessions: 15,
    );

    expect($snapshot->daysSinceCreation())->toBe(5);
    expect($snapshot->daysSinceLastActivity())->toBe(2);
    expect($snapshot->engagementScore())->toBe(20.0); // 100 / 5
});

test('CdpProfileSnapshot toArray and toProviderTraits', function (): void {
    $snapshot = new CdpProfileSnapshot(
        userId: 'user_789',
        traits: ['name' => 'John', 'plan' => 'enterprise'],
        segments: ['high_value'],
        createdAt: 1700000000,
    );

    $arr = $snapshot->toArray();
    expect($arr['user_id'])->toBe('user_789');
    expect($arr['traits'])->toBe(['name' => 'John', 'plan' => 'enterprise']);
    expect($arr['segments'])->toBe(['high_value']);
    expect($arr['total_events'])->toBe(0);
    expect($arr['engagement_score'])->toBeNull();

    expect($snapshot->toProviderTraits())->toBe(['name' => 'John', 'plan' => 'enterprise']);
});

test('CdpProfileSnapshot fromArray round-trips', function (): void {
    $original = new CdpProfileSnapshot(
        userId: 'user_999',
        anonymousId: 'anon_abc',
        email: 'test@test.com',
        traits: ['key' => 'value'],
        segments: ['seg_a'],
        createdAt: 1700000000,
        updatedAt: 1700000100,
        lastEventAt: 1700000050,
        totalEvents: 25,
        totalSessions: 5,
    );

    $restored = CdpProfileSnapshot::fromArray($original->toArray());

    expect($restored->userId)->toBe('user_999');
    expect($restored->anonymousId)->toBe('anon_abc');
    expect($restored->email)->toBe('test@test.com');
    expect($restored->traits)->toBe(['key' => 'value']);
    expect($restored->segments)->toBe(['seg_a']);
    expect($restored->createdAt)->toBe(1700000000);
    expect($restored->totalEvents)->toBe(25);
});

test('CdpProfileSnapshot null safety for computed properties', function (): void {
    $snapshot = new CdpProfileSnapshot(userId: 'no_data');

    expect($snapshot->daysSinceCreation())->toBeNull();
    expect($snapshot->daysSinceLastActivity())->toBeNull();
    expect($snapshot->engagementScore())->toBeNull();
    expect($snapshot->getTrait('anything'))->toBeNull();
});

test('CdpTraitComputer registers and retrieves trait definitions', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->andReturnTrue();

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.cdp', [])->andReturn([]);

    $computer = new CdpTraitComputer($cache, $config);

    // Should have 13 default traits
    $defs = $computer->getTraitDefinitions();
    expect($defs)->toHaveKey('total_revenue');
    expect($defs)->toHaveKey('purchase_count');
    expect($defs)->toHaveKey('session_count');
    expect($defs)->toHaveKey('page_view_count');
    expect($defs)->toHaveKey('error_count');
    expect($defs)->toHaveKey('unique_features_used');
    expect($defs)->toHaveKey('login_count');
    expect($defs)->toHaveKey('avg_order_value');
    expect($defs)->toHaveKey('max_purchase');
    expect($defs)->toHaveKey('search_count');
    expect($defs)->toHaveKey('form_submit_count');
    expect($defs)->toHaveKey('last_plan');
    expect(count($defs))->toBe(13);
});

test('CdpTraitComputer registerTrait adds custom definition', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldNotReceive('get');

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.cdp', [])->andReturn([]);

    $computer = new CdpTraitComputer($cache, $config);

    $custom = CdpTraitDefinition::computed(
        name: 'custom_metric',
        sourceEvent: 'custom_event',
        aggregation: 'count',
        type: 'int',
    );

    $computer->registerTrait($custom);
    $defs = $computer->getTraitDefinitions();

    expect($defs)->toHaveKey('custom_metric');
    expect($defs['custom_metric']->sourceEvent)->toBe('custom_event');
});

test('CdpSegmentService registers and evaluates segments', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->andReturnTrue();

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.cdp', [])->andReturn([]);

    $segmentService = new CdpSegmentService($cache, $config);

    // Should have 8 default segments
    $segments = $segmentService->getSegments();
    expect($segments)->toHaveKey('power_user');
    expect($segments)->toHaveKey('high_value');
    expect($segments)->toHaveKey('at_risk');
    expect($segments)->toHaveKey('new_user');
    expect($segments)->toHaveKey('frequent_searcher');
    expect($segments)->toHaveKey('error_prone');
    expect($segments)->toHaveKey('free_tier');
    expect($segments)->toHaveKey('feature_explorer');
    expect(count($segments))->toBe(8);
});

test('CdpSegmentService evaluates power_user segment', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->andReturnTrue();

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.cdp', [])->andReturn([]);

    $segmentService = new CdpSegmentService($cache, $config);

    // Power user needs page_view_count >= 100 AND session_count >= 20
    $traits = [
        'page_view_count' => 150,
        'session_count' => 25,
    ];

    $matched = $segmentService->evaluateSegments($traits, 'user_1');

    expect($matched)->toContain('power_user');
});

test('CdpSegmentService does not match power_user with low engagement', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->andReturnTrue();

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.cdp', [])->andReturn([]);

    $segmentService = new CdpSegmentService($cache, $config);

    $traits = [
        'page_view_count' => 5,
        'session_count' => 1,
    ];

    $matched = $segmentService->evaluateSegments($traits, 'user_2');

    expect($matched)->not->toContain('power_user');
});

test('CdpSegmentService evaluates high_value segment', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->andReturnTrue();

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.cdp', [])->andReturn([]);

    $segmentService = new CdpSegmentService($cache, $config);

    // High value: total_revenue > 99
    $traits = ['total_revenue' => 250.0];
    $matched = $segmentService->evaluateSegments($traits, 'user_3');

    expect($matched)->toContain('high_value');
    expect($matched)->not->toContain('free_tier');
});

test('CdpSegmentService evaluates free_tier segment', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->andReturnTrue();

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.cdp', [])->andReturn([]);

    $segmentService = new CdpSegmentService($cache, $config);

    $traits = ['total_revenue' => 0.0];
    $matched = $segmentService->evaluateSegments($traits, 'user_4');

    expect($matched)->toContain('free_tier');
    expect($matched)->not->toContain('high_value');
});

test('CdpSegmentService custom segment registration', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->andReturnTrue();
    $cache->shouldReceive('forget')->andReturnTrue();

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.cdp', [])->andReturn([]);

    $segmentService = new CdpSegmentService($cache, $config);

    $segmentService->registerSegment('enterprise', [
        ['trait' => 'plan', 'operator' => 'eq', 'value' => 'enterprise'],
    ], 'Enterprise plan users');

    $traits = ['plan' => 'enterprise'];
    $matched = $segmentService->evaluateSegments($traits, 'user_5');

    expect($matched)->toContain('enterprise');
});

test('CdpSegmentService invalidateCache', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('forget')->with('zb_cdp_segments_user_1')->andReturnTrue();

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.cdp', [])->andReturn([]);

    $segmentService = new CdpSegmentService($cache, $config);

    expect($segmentService->invalidateCache('user_1'))->toBeTrue();
});

test('CdpSegmentService removeSegment', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.cdp', [])->andReturn([]);

    $segmentService = new CdpSegmentService($cache, $config);

    expect($segmentService->removeSegment('power_user'))->toBeTrue();
    expect($segmentService->getSegments())->not->toHaveKey('power_user');
});

test('CdpSegmentService between operator', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->andReturnTrue();

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.cdp', [])->andReturn([]);

    $segmentService = new CdpSegmentService($cache, $config);

    $segmentService->registerSegment('mid_revenue', [
        ['trait' => 'total_revenue', 'operator' => 'between', 'min' => 50.0, 'max' => 200.0],
    ]);

    $matched1 = $segmentService->evaluateSegments(['total_revenue' => 100.0], 'u1');
    expect($matched1)->toContain('mid_revenue');

    $matched2 = $segmentService->evaluateSegments(['total_revenue' => 300.0], 'u2');
    expect($matched2)->not->toContain('mid_revenue');
});

test('CdpSegmentService contains operator', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->andReturnTrue();

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.cdp', [])->andReturn([]);

    $segmentService = new CdpSegmentService($cache, $config);

    $segmentService->registerSegment('gmail_users', [
        ['trait' => 'email', 'operator' => 'contains', 'value' => '@gmail.com'],
    ]);

    $matched = $segmentService->evaluateSegments(['email' => 'user@gmail.com'], 'u1');
    expect($matched)->toContain('gmail_users');
});

test('CdpSegmentService exists and not_exists operators', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->andReturnTrue();

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.cdp', [])->andReturn([]);

    $segmentService = new CdpSegmentService($cache, $config);

    $segmentService->registerSegment('has_company', [
        ['trait' => 'company', 'operator' => 'exists'],
    ]);

    $matched1 = $segmentService->evaluateSegments(['company' => 'Acme'], 'u1');
    expect($matched1)->toContain('has_company');

    $matched2 = $segmentService->evaluateSegments([], 'u2');
    expect($matched2)->not->toContain('has_company');
});

test('CdpProfileService creates and retrieves profile', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('put')->andReturnTrue();
    $cache->shouldReceive('get')->andReturnNull();

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn([]);

    $service = new CdpProfileService($cache, $config);

    $profile = $service->createProfile('user_1', ['name' => 'Test User', 'plan' => 'pro']);

    expect($profile)->toBeInstanceOf(CdpProfileSnapshot::class);
    expect($profile->userId)->toBe('user_1');
    expect($profile->getTrait('name'))->toBe('Test User');
    expect($profile->getTrait('plan'))->toBe('pro');
});

test('CdpProfileService identify sets traits', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('put')->andReturnTrue();
    $cache->shouldReceive('get')
        ->andReturnNull() // getRawProfile null -> create
        ->andReturn(['user_id' => 'user_2', 'traits' => [], 'segments' => [], 'created_at' => 1700000000, 'updated_at' => 1700000000, 'last_event_at' => null, 'total_events' => 0, 'total_sessions' => 0, 'anonymous_id' => null]) // getRawProfile for identify
        ->andReturnNull(); // for computed traits
    $cache->shouldReceive('forget')->andReturnTrue();

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn([]);

    $service = new CdpProfileService($cache, $config);

    $profile = $service->identify('user_2', ['email' => 'test@example.com', 'company' => 'Acme']);

    expect($profile->getTrait('email'))->toBe('test@example.com');
    expect($profile->getTrait('company'))->toBe('Acme');
});

test('CdpProfileService setTrait and incrementTrait', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('put')->andReturnTrue();
    $cache->shouldReceive('get')
        ->andReturnNull() // create
        ->andReturn(['user_id' => 'user_3', 'traits' => ['score' => 10.0], 'segments' => [], 'created_at' => 1700000000, 'updated_at' => 1700000000, 'last_event_at' => null, 'total_events' => 0, 'total_sessions' => 0, 'anonymous_id' => null]) // setTrait
        ->andReturn(['user_id' => 'user_3', 'traits' => ['score' => 10.0, 'level' => 5.0], 'segments' => [], 'created_at' => 1700000000, 'updated_at' => 1700000000, 'last_event_at' => null, 'total_events' => 0, 'total_sessions' => 0, 'anonymous_id' => null]) // incrementTrait
        ->andReturnNull();
    $cache->shouldReceive('forget')->andReturnTrue();

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn([]);

    $service = new CdpProfileService($cache, $config);

    // Create profile first
    $service->createProfile('user_3', ['score' => 10.0]);

    $result = $service->incrementTrait('user_3', 'score', 5);
    expect($result)->toBe(15.0);
});

test('CdpProfileService forgetProfile GDPR erasure', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('forget')->andReturnTrue();
    $cache->shouldReceive('put')->andReturnTrue();
    $cache->shouldReceive('get')
        ->andReturnNull() // getRawProfile null -> create
        ->andReturn(['client_ids' => ['c1']]); // getIndex
    $cache->shouldReceive('get')
        ->andReturn(['client_ids' => []]); // getIndex after removal

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn([]);

    $service = new CdpProfileService($cache, $config);

    expect($service->forgetProfile('user_x'))->toBeTrue();
});

test('CdpProfileService hasProfile returns false for unknown user', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn([]);

    $service = new CdpProfileService($cache, $config);

    expect($service->hasProfile('nonexistent'))->toBeFalse();
});

test('CdpProfileService getSummary', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('get')->andReturnNull();

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn([]);

    $service = new CdpProfileService($cache, $config);

    $summary = $service->getSummary();

    expect($summary)->toHaveKey('total_profiles');
    expect($summary)->toHaveKey('total_segments');
    expect($summary)->toHaveKey('total_trait_definitions');
    expect($summary)->toHaveKey('enabled');
    expect($summary['enabled'])->toBeTrue();
});

test('CdpProfileService getProviderTraits', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('put')->andReturnTrue();
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('forget')->andReturnTrue();

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn([]);

    $service = new CdpProfileService($cache, $config);

    $service->createProfile('user_prov', ['email' => 'prov@test.com', 'plan' => 'enterprise']);
    $traits = $service->getProviderTraits('user_prov');

    expect($traits)->toHaveKey('email');
    expect($traits['email'])->toBe('prov@test.com');
});

test('CdpEventToProfileListener extracts identity and processes event', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('put')->andReturnTrue();
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('forget')->andReturnTrue();

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn([]);

    $profileService = new CdpProfileService($cache, $config);
    $config2 = Mockery::mock(ConfigRepository::class);
    $config2->shouldReceive('get')->andReturn([]);

    $listener = new CdpEventToProfileListener($profileService, $config2);

    $event = new AnalyticsEvent(
        name: 'signup',
        properties: [
            'user_id' => 'new_user',
            'email' => 'new@example.com',
            'name' => 'New User',
            'company' => 'Startup',
        ],
        context: [],
    );

    $result = $listener->handle($event);

    expect($result)->not->BeNull();
    expect($result['processed'])->toBeTrue();
    expect($result['user_id'])->toBe('new_user');
    expect($result['updated_traits'])->toBeArray();
    expect($result['segments'])->toBeArray();
});

test('CdpEventToProfileListener returns null for events without user', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn([]);

    $profileService = Mockery::mock(CdpProfileService::class);
    $listener = new CdpEventToProfileListener($profileService, $config);

    $event = new AnalyticsEvent(
        name: 'page_view',
        properties: ['page' => '/home'],
        context: [],
    );

    expect($listener->handle($event))->toBeNull();
});

test('CdpEventToProfileListener extracts user from context', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('put')->andReturnTrue();
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('forget')->andReturnTrue();

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->andReturn([]);

    $profileService = new CdpProfileService($cache, $config);
    $config2 = Mockery::mock(ConfigRepository::class);
    $config2->shouldReceive('get')->andReturn([]);

    $listener = new CdpEventToProfileListener($profileService, $config2);

    $event = new AnalyticsEvent(
        name: 'purchase',
        properties: ['revenue' => 99.99],
        context: ['user_id' => 'ctx_user'],
    );

    $result = $listener->handle($event);

    expect($result)->not->BeNull();
    expect($result['user_id'])->toBe('ctx_user');
});
