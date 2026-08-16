<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Blueprints\EventBlueprint;
use ZeroBoiler\Analytics\Blueprints\EventBlueprintRegistry;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventLifecycleHooks;
use ZeroBoiler\Analytics\Services\SegmentExportService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

beforeEach(function (): void {
    $cache = mock(CacheRepository::class);
    $cache->shouldReceive('put')->andReturn(true);
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('forget')->andReturn(true);
    $this->cache = $cache;
});

// ── EventBlueprint DTO ──────────────────────────────────────────────

test('EventBlueprint creates from constructor with all fields', function (): void {
    $blueprint = new EventBlueprint(
        name: 'saas.signup.email',
        label: 'Email Signup',
        description: 'User registered via email',
        baseEvent: 'sign_up',
        category: 'saas',
        defaultParams: ['signup_method' => 'email'],
        requiredParams: ['user_id'],
        paramTypes: ['user_id' => 'string', 'signup_method' => 'string'],
        priority: 'critical',
        version: '1.0.0',
        metadata: ['owner' => 'growth'],
    );

    expect($blueprint->name)->toBe('saas.signup.email');
    expect($blueprint->label)->toBe('Email Signup');
    expect($blueprint->baseEvent)->toBe('sign_up');
    expect($blueprint->category)->toBe('saas');
    expect($blueprint->defaultParams)->toBe(['signup_method' => 'email']);
    expect($blueprint->requiredParams)->toBe(['user_id']);
    expect($blueprint->priority)->toBe('critical');
    expect($blueprint->version)->toBe('1.0.0');
    expect($blueprint->owner())->toBe('growth');
});

test('EventBlueprint::fromArray parses config correctly', function (): void {
    $blueprint = EventBlueprint::fromArray([
        'name' => 'ecommerce.purchase.completed',
        'label' => 'Purchase Completed',
        'description' => 'Order completed',
        'base_event' => 'purchase',
        'category' => 'ecommerce',
        'default_params' => ['currency' => 'USD'],
        'required_params' => ['transaction_id', 'value'],
        'param_types' => ['transaction_id' => 'string', 'value' => 'float'],
        'priority' => 'critical',
        'version' => '2.0.0',
        'metadata' => ['owner' => 'revenue'],
    ]);

    expect($blueprint->name)->toBe('ecommerce.purchase.completed');
    expect($blueprint->baseEvent)->toBe('purchase');
    expect($blueprint->requiredParams)->toBe(['transaction_id', 'value']);
    expect($blueprint->owner())->toBe('revenue');
});

test('EventBlueprint::fromArray handles both camelCase and snake_case keys', function (): void {
    $blueprint = EventBlueprint::fromArray([
        'name' => 'test.blueprint',
        'baseEvent' => 'sign_up',
        'defaultParams' => ['a' => 'b'],
        'requiredParams' => ['x'],
    ]);

    expect($blueprint->baseEvent)->toBe('sign_up');
    expect($blueprint->defaultParams)->toBe(['a' => 'b']);
});

test('EventBlueprint::toArray round-trips correctly', function (): void {
    $original = new EventBlueprint(
        name: 'test.event',
        label: 'Test Event',
        baseEvent: 'page_view',
        category: 'engagement',
        defaultParams: ['key' => 'val'],
        requiredParams: ['required'],
        priority: 'normal',
        version: '1.2.3',
        metadata: ['deprecated' => true, 'deprecation_notice' => 'Use new.event instead'],
    );

    $restored = EventBlueprint::fromArray($original->toArray());

    expect($restored->name)->toBe($original->name);
    expect($restored->baseEvent)->toBe($original->baseEvent);
    expect($restored->defaultParams)->toBe($original->defaultParams);
    expect($restored->requiredParams)->toBe($original->requiredParams);
    expect($restored->isDeprecated())->toBeTrue();
    expect($restored->deprecationNotice())->toBe('Use new.event instead');
});

test('EventBlueprint::validateParams passes with all required fields', function (): void {
    $blueprint = new EventBlueprint(
        name: 'test.event',
        label: 'Test',
        requiredParams: ['user_id', 'email'],
        paramTypes: ['user_id' => 'string', 'email' => 'string', 'age' => 'int'],
    );

    expect($blueprint->validateParams(['user_id' => 'usr_1', 'email' => 'test@example.com']))->toBe([]);
});

test('EventBlueprint::validateParams catches missing required params', function (): void {
    $blueprint = new EventBlueprint(
        name: 'test.event',
        label: 'Test',
        requiredParams: ['user_id'],
    );

    $errors = $blueprint->validateParams(['other_param' => 'val']);

    expect($errors)->not()->toBeEmpty();
    expect($errors[0])->toContain('Missing required parameter');
    expect($errors[0])->toContain('user_id');
});

test('EventBlueprint::validateParams catches type mismatches', function (): void {
    $blueprint = new EventBlueprint(
        name: 'test.event',
        label: 'Test',
        requiredParams: [],
        paramTypes: ['count' => 'int', 'price' => 'float', 'active' => 'bool', 'tags' => 'array'],
    );

    $errors = $blueprint->validateParams([
        'count' => 'not_an_int',
        'price' => 'not_a_float',
        'active' => 'not_a_bool',
        'tags' => 'not_an_array',
    ]);

    expect(count($errors))->toBe(4);
    expect($errors[0])->toContain('count');
    expect($errors[0])->toContain('string');
});

test('EventBlueprint::validateParams accepts valid number types', function (): void {
    $blueprint = new EventBlueprint(
        name: 'test.event',
        label: 'Test',
        paramTypes: ['count' => 'int', 'price' => 'float', 'name' => 'string'],
    );

    $errors = $blueprint->validateParams([
        'count' => 42,
        'price' => 19.99,
        'name' => 'Test',
    ]);

    expect($errors)->toBe([]);
});

test('EventBlueprint isDeprecated and deprecationNotice', function (): void {
    $active = new EventBlueprint(name: 'active', label: 'Active');
    $deprecated = new EventBlueprint(
        name: 'deprecated',
        label: 'Deprecated',
        metadata: ['deprecated' => true, 'deprecation_notice' => 'Use replacement instead'],
    );

    expect($active->isDeprecated())->toBeFalse();
    expect($active->deprecationNotice())->toBeNull();

    expect($deprecated->isDeprecated())->toBeTrue();
    expect($deprecated->deprecationNotice())->toBe('Use replacement instead');
});

test('EventBlueprint matchesVersion', function (): void {
    $blueprint = new EventBlueprint(name: 'test', label: 'Test', version: '2.0.0');

    expect($blueprint->matchesVersion('2.0.0'))->toBeTrue();
    expect($blueprint->matchesVersion('1.0.0'))->toBeFalse();
});

// ── EventBlueprintRegistry ──────────────────────────────────────────

test('Registry registers and finds blueprints', function (): void {
    $registry = new EventBlueprintRegistry($this->cache);

    $blueprint = new EventBlueprint(
        name: 'custom.test',
        label: 'Custom Test',
        baseEvent: 'page_view',
    );

    $registry->register($blueprint);

    $found = $registry->find('custom.test');
    expect($found)->not()->toBeNull();
    expect($found->name)->toBe('custom.test');
    expect($found->baseEvent)->toBe('page_view');
});

test('Registry returns null for unknown blueprints', function (): void {
    $registry = new EventBlueprintRegistry($this->cache);

    expect($registry->find('nonexistent.blueprint'))->toBeNull();
});

test('Registry has() returns correct boolean', function (): void {
    $registry = new EventBlueprintRegistry($this->cache);
    $registry->register(new EventBlueprint(name: 'exists', label: 'Exists'));

    expect($registry->has('exists'))->toBeTrue();
    expect($registry->has('nope'))->toBeFalse();
});

test('Registry registerFromArray parses and registers', function (): void {
    $registry = new EventBlueprintRegistry($this->cache);

    $registry->registerFromArray([
        'name' => 'config.blueprint',
        'label' => 'From Config',
        'base_event' => 'sign_up',
    ]);

    expect($registry->has('config.blueprint'))->toBeTrue();
    expect($registry->find('config.blueprint')->label)->toBe('From Config');
});

test('Registry registerFromArray throws on empty name', function (): void {
    $registry = new EventBlueprintRegistry($this->cache);

    expect(fn (): mixed => $registry->registerFromArray(['name' => '', 'label' => '']))
        ->toThrow(ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException::class);
});

test('Registry unregister removes blueprint', function (): void {
    $registry = new EventBlueprintRegistry($this->cache);
    $registry->register(new EventBlueprint(name: 'temp', label: 'Temp'));

    expect($registry->has('temp'))->toBeTrue();

    $registry->unregister('temp');

    expect($registry->has('temp'))->toBeFalse();
});

test('Registry clear removes all runtime blueprints', function (): void {
    $registry = new EventBlueprintRegistry($this->cache);
    $registry->register(new EventBlueprint(name: 'a', label: 'A'));
    $registry->register(new EventBlueprint(name: 'b', label: 'B'));

    $registry->clear();

    expect($registry->find('a'))->toBeNull();
    expect($registry->find('b'))->toBeNull();
});

test('Registry has built-in blueprints', function (): void {
    $registry = new EventBlueprintRegistry($this->cache);

    expect($registry->has('saas.signup.email'))->toBeTrue();
    expect($registry->has('saas.signup.google'))->toBeTrue();
    expect($registry->has('saas.signup.github'))->toBeTrue();
    expect($registry->has('saas.login.standard'))->toBeTrue();
    expect($registry->has('saas.login.sso'))->toBeTrue();
    expect($registry->has('saas.trial.started'))->toBeTrue();
    expect($registry->has('saas.trial.converted'))->toBeTrue();
    expect($registry->has('saas.subscription.created'))->toBeTrue();
    expect($registry->has('saas.plan.upgraded'))->toBeTrue();
    expect($registry->has('saas.subscription.cancelled'))->toBeTrue();
    expect($registry->has('ecommerce.product.viewed'))->toBeTrue();
    expect($registry->has('ecommerce.cart.added'))->toBeTrue();
    expect($registry->has('ecommerce.checkout.started'))->toBeTrue();
    expect($registry->has('ecommerce.purchase.completed'))->toBeTrue();
    expect($registry->has('ecommerce.refund.issued'))->toBeTrue();
    expect($registry->has('engagement.page.viewed'))->toBeTrue();
    expect($registry->has('engagement.search.performed'))->toBeTrue();
    expect($registry->has('engagement.content.shared'))->toBeTrue();
    expect($registry->has('engagement.form.started'))->toBeTrue();
    expect($registry->has('engagement.form.submitted'))->toBeTrue();
    expect($registry->has('engagement.scroll.depth'))->toBeTrue();
    expect($registry->has('engagement.error.occurred'))->toBeTrue();
    expect($registry->has('identity.user.identified'))->toBeTrue();
});

test('Registry build creates validated event', function (): void {
    $registry = new EventBlueprintRegistry($this->cache);

    $event = $registry->build(
        'saas.signup.email',
        ['user_id' => 'usr_123', 'email_hash' => 'abc123'],
        clientId: 'cli_456',
    );

    expect($event)->toBeInstanceOf(AnalyticsEvent::class);
    expect($event->name)->toBe('sign_up');
    expect($event->params['signup_method'])->toBe('email');
    expect($event->params['user_id'])->toBe('usr_123');
    expect($event->clientId)->toBe('cli_456');
    expect($event->priority)->toBe('critical');
});

test('Registry build throws on missing required params', function (): void {
    $registry = new EventBlueprintRegistry($this->cache);

    expect(fn (): mixed => $registry->build('saas.signup.email', []))
        ->toThrow(ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException::class);
});

test('Registry build throws on unknown blueprint', function (): void {
    $registry = new EventBlueprintRegistry($this->cache);

    expect(fn (): mixed => $registry->build('nonexistent.blueprint'))
        ->toThrow(ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException::class);
});

test('Registry buildUnsafe returns event with errors', function (): void {
    $registry = new EventBlueprintRegistry($this->cache);

    $result = $registry->buildUnsafe('saas.signup.email', []);

    expect($result['event'])->toBeInstanceOf(AnalyticsEvent::class);
    expect($result['errors'])->not()->toBeEmpty();
    expect($result['errors'][0])->toContain('user_id');
});

test('Registry byCategory groups blueprints correctly', function (): void {
    $registry = new EventBlueprintRegistry($this->cache);

    $byCategory = $registry->byCategory();

    expect($byCategory)->toHaveKey('saas');
    expect($byCategory)->toHaveKey('ecommerce');
    expect($byCategory)->toHaveKey('engagement');
    expect($byCategory)->toHaveKey('custom');
    expect(count($byCategory['saas']))->toBeGreaterThanOrEqual(10);
    expect(count($byCategory['ecommerce']))->toBeGreaterThanOrEqual(5);
});

test('Registry diagnostics returns correct counts', function (): void {
    $registry = new EventBlueprintRegistry($this->cache);
    $registry->register(new EventBlueprint(name: 'runtime.custom', label: 'Runtime'));

    $diagnostics = $registry->diagnostics();

    expect($diagnostics['total'])->toBeGreaterThanOrEqual(20);
    expect($diagnostics['built_in'])->toBeGreaterThanOrEqual(20);
    expect($diagnostics['runtime'])->toBe(1);
});

test('Registry validateRegistry reports valid state', function (): void {
    $registry = new EventBlueprintRegistry($this->cache);

    $result = $registry->validateRegistry();

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBe([]);
});

test('Registry names returns all blueprint identifiers', function (): void {
    $registry = new EventBlueprintRegistry($this->cache);

    $names = $registry->names();

    expect($names)->toContain('saas.signup.email');
    expect($names)->toContain('ecommerce.purchase.completed');
    expect($names)->toContain('engagement.page.viewed');
    expect($names)->toContain('identity.user.identified');
});

test('Registry build with ecommerce blueprint merges defaults', function (): void {
    $registry = new EventBlueprintRegistry($this->cache);

    $event = $registry->build('ecommerce.purchase.completed', [
        'transaction_id' => 'TXN-001',
        'value' => 99.99,
        'currency' => 'EUR',
    ]);

    expect($event->name)->toBe('purchase');
    expect($event->params['transaction_id'])->toBe('TXN-001');
    expect($event->params['value'])->toBe(99.99);
    expect($event->params['currency'])->toBe('EUR');
    expect($event->priority)->toBe('critical');
});

// ── SegmentExportService ─────────────────────────────────────────────

test('Segment export toTrack converts correctly', function (): void {
    $service = new SegmentExportService('test_write_key');

    $event = new AnalyticsEvent(
        name: 'purchase',
        params: ['transaction_id' => 'TXN-1', 'value' => 99.99, 'currency' => 'USD'],
        clientId: 'cli_abc',
        userId: 'usr_123',
    );

    $segment = $service->toTrack($event);

    expect($segment['type'])->toBe('track');
    expect($segment['event'])->toBe('Order Completed');
    expect($segment['properties']['transaction_id'])->toBe('TXN-1');
    expect($segment['userId'])->toBe('usr_123');
    expect($segment['anonymousId'])->toBe('cli_abc');
    expect($segment['context']['library']['name'])->toBe('zeroboiler-analytics');
    expect($segment['context']['library']['version'])->toBe(AnalyticsEvent::VERSION);
});

test('Segment export toIdentify converts correctly', function (): void {
    $service = new SegmentExportService();

    $event = new AnalyticsEvent(
        name: 'identify',
        params: ['email_hash' => 'abc', 'plan' => 'pro'],
        userId: 'usr_123',
    );

    $segment = $service->toIdentify($event);

    expect($segment['type'])->toBe('identify');
    expect($segment['userId'])->toBe('usr_123');
    expect($segment['traits']['plan'])->toBe('pro');
});

test('Segment export toPage converts correctly', function (): void {
    $service = new SegmentExportService();

    $event = new AnalyticsEvent(
        name: 'page_view',
        params: ['page_title' => 'Home', 'page_location' => 'https://example.com/home', 'page_referrer' => 'https://google.com'],
    );

    $segment = $service->toPage($event);

    expect($segment['type'])->toBe('page');
    expect($segment['name'])->toBe('Home');
    expect($segment['properties']['url'])->toBe('https://example.com/home');
    expect($segment['properties']['path'])->toBe('/home');
    expect($segment['properties']['referrer'])->toBe('https://google.com');
});

test('Segment export toGroup converts correctly', function (): void {
    $service = new SegmentExportService();

    $event = new AnalyticsEvent(
        name: 'team_created',
        params: ['name' => 'Engineering', 'plan' => 'enterprise'],
        userId: 'usr_123',
    );

    $segment = $service->toGroup('grp_456', $event);

    expect($segment['type'])->toBe('group');
    expect($segment['groupId'])->toBe('grp_456');
    expect($segment['traits']['name'])->toBe('Engineering');
    expect($segment['userId'])->toBe('usr_123');
});

test('Segment export toAlias converts correctly', function (): void {
    $service = new SegmentExportService();

    $event = new AnalyticsEvent(name: 'identify', userId: 'usr_123');

    $segment = $service->toAlias('anonymous_abc', $event);

    expect($segment['type'])->toBe('alias');
    expect($segment['previousId'])->toBe('anonymous_abc');
    expect($segment['userId'])->toBe('usr_123');
});

test('Segment export toBatch converts multiple events', function (): void {
    $service = new SegmentExportService();

    $events = [
        new AnalyticsEvent(name: 'page_view', params: ['page_title' => 'Home']),
        new AnalyticsEvent(name: 'purchase', params: ['value' => 49.99]),
        new AnalyticsEvent(name: 'sign_up', params: ['signup_method' => 'email']),
    ];

    $batch = $service->toBatch($events);

    expect($batch['batch'])->toHaveCount(3);
    expect($batch['batch'][0]['type'])->toBe('page');
    expect($batch['batch'][1]['type'])->toBe('track');
    expect($batch['batch'][1]['event'])->toBe('Order Completed');
    expect($batch['batch'][2]['type'])->toBe('track');
    expect($batch['batch'][2]['event'])->toBe('Signed Up');
    expect($batch['sentAt'])->not()->toBeEmpty();
});

test('Segment export autoConvert dispatches correct type', function (): void {
    $service = new SegmentExportService();

    $identify = $service->autoConvert(new AnalyticsEvent(name: 'identify', userId: 'usr_1'));
    expect($identify['type'])->toBe('identify');

    $page = $service->autoConvert(new AnalyticsEvent(name: 'page_view', params: ['page_title' => 'Test']));
    expect($page['type'])->toBe('page');

    $track = $service->autoConvert(new AnalyticsEvent(name: 'custom_event'));
    expect($track['type'])->toBe('track');
});

test('Segment export event name mapping', function (): void {
    $service = new SegmentExportService();

    $mapped = [
        ['sign_up', 'Signed Up'],
        ['login', 'Logged In'],
        ['purchase', 'Order Completed'],
        ['refund', 'Order Refunded'],
        ['search', 'Products Searched'],
        ['share', 'Product Shared'],
        ['form_start', 'Form Started'],
        ['form_submit', 'Form Submitted'],
        ['scroll_depth', 'Page Scrolled'],
        ['error', 'Error Occurred'],
    ];

    foreach ($mapped as [$zbName, $segmentName]) {
        $event = new AnalyticsEvent(name: $zbName);
        $segment = $service->toTrack($event);
        expect($segment['event'])->toBe($segmentName, "Failed mapping for {$zbName}");
    }
});

test('Segment export buildBatchRequest includes write key', function (): void {
    $service = new SegmentExportService('my_write_key');

    $batch = $service->buildBatchRequest([new AnalyticsEvent(name: 'page_view')]);

    expect($batch['writeKey'])->toBe('my_write_key');
    expect($batch['batch'])->toHaveCount(1);
});

test('Segment export toTrack enriches with catalog metadata', function (): void {
    $service = new SegmentExportService();

    $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 100]);

    $segment = $service->toTrack($event);

    // purchase exists in catalog
    expect($segment['properties'])->toHaveKey('_zb_category');
    expect($segment['properties'])->toHaveKey('_zb_ga4');
    expect($segment['properties']['_zb_ga4'])->toBe('purchase');
});

// ── EventLifecycleHooks ─────────────────────────────────────────────

test('Lifecycle hooks beforeDispatch modifies event', function (): void {
    $hooks = new EventLifecycleHooks();

    $hooks->beforeDispatch(function (AnalyticsEvent $event): AnalyticsEvent {
        return new AnalyticsEvent(
            name: $event->name,
            params: array_merge($event->params, ['enriched' => true]),
            clientId: $event->clientId,
        );
    });

    $original = new AnalyticsEvent(name: 'page_view', params: ['page_title' => 'Home']);
    $result = $hooks->runBeforeHooks($original);

    expect($result)->not()->toBeNull();
    expect($result->params['enriched'])->toBeTrue();
    expect($result->params['page_title'])->toBe('Home');
});

test('Lifecycle hooks beforeDispatch aborts on null return', function (): void {
    $hooks = new EventLifecycleHooks();

    $hooks->beforeDispatch(function (AnalyticsEvent $event): ?AnalyticsEvent {
        if ($event->name === 'skip_me') {
            return null; // Abort signal
        }

        return $event;
    });

    $skip = new AnalyticsEvent(name: 'skip_me');
    $keep = new AnalyticsEvent(name: 'keep_me');

    expect($hooks->runBeforeHooks($skip))->toBeNull();
    expect($hooks->runBeforeHooks($keep))->not()->toBeNull();
    expect($hooks->runBeforeHooks($keep)->name)->toBe('keep_me');
});

test('Lifecycle hooks afterDispatch receives results', function (): void {
    $hooks = new EventLifecycleHooks();

    $receivedResults = null;

    $hooks->afterDispatch(function (AnalyticsEvent $event, array $results) use (&$receivedResults): void {
        $receivedResults = $results;
    });

    $event = new AnalyticsEvent(name: 'purchase');
    $hooks->runAfterHooks($event, ['ga4' => 'ok', 'meta' => 'ok']);

    expect($receivedResults)->toBe(['ga4' => 'ok', 'meta' => 'ok']);
});

test('Lifecycle hooks onError receives exception', function (): void {
    $hooks = new EventLifecycleHooks();

    $caughtException = null;

    $hooks->onError(function (AnalyticsEvent $event, \Throwable $e) use (&$caughtException): void {
        $caughtException = $e;
    });

    $event = new AnalyticsEvent(name: 'error');
    $exception = new \RuntimeException('Dispatch failed');

    $hooks->runErrorHooks($event, $exception);

    expect($caughtException)->toBe($exception);
});

test('Lifecycle hooks finally always runs', function (): void {
    $hooks = new EventLifecycleHooks();

    $finallyCalled = false;

    $hooks->finally(function (AnalyticsEvent $event) use (&$finallyCalled): void {
        $finallyCalled = true;
    });

    $hooks->runFinallyHooks(new AnalyticsEvent(name: 'any'));

    expect($finallyCalled)->toBeTrue();
});

test('Lifecycle hooks clear removes all hooks', function (): void {
    $hooks = new EventLifecycleHooks();

    $hooks->beforeDispatch(fn (AnalyticsEvent $e): AnalyticsEvent => $e);
    $hooks->afterDispatch(fn (AnalyticsEvent $e, array $r): void => null);
    $hooks->onError(fn (AnalyticsEvent $e, \Throwable $t): void => null);
    $hooks->finally(fn (AnalyticsEvent $e): void => null);

    expect($hooks->hasHooks())->toBeTrue();

    $hooks->clear();

    expect($hooks->hasHooks())->toBeFalse();
    expect($hooks->summary())->toBe([
        'before' => 0,
        'after' => 0,
        'error' => 0,
        'finally' => 0,
        'total' => 0,
    ]);
});

test('Lifecycle hooks summary reports correct counts', function (): void {
    $hooks = new EventLifecycleHooks();

    $hooks->beforeDispatch(fn (AnalyticsEvent $e): AnalyticsEvent => $e);
    $hooks->beforeDispatch(fn (AnalyticsEvent $e): AnalyticsEvent => $e);
    $hooks->afterDispatch(fn (AnalyticsEvent $e, array $r): void => null);
    $hooks->onError(fn (AnalyticsEvent $e, \Throwable $t): void => null);
    $hooks->finally(fn (AnalyticsEvent $e): void => null);

    $summary = $hooks->summary();

    expect($summary['before'])->toBe(2);
    expect($summary['after'])->toBe(1);
    expect($summary['error'])->toBe(1);
    expect($summary['finally'])->toBe(1);
    expect($summary['total'])->toBe(5);
});

test('Lifecycle hooks clearBefore/clearAfter/clearErrors/clearFinally', function (): void {
    $hooks = new EventLifecycleHooks();

    $hooks->beforeDispatch(fn (AnalyticsEvent $e): AnalyticsEvent => $e);
    $hooks->afterDispatch(fn (AnalyticsEvent $e, array $r): void => null);
    $hooks->onError(fn (AnalyticsEvent $e, \Throwable $t): void => null);
    $hooks->finally(fn (AnalyticsEvent $e): void => null);

    $hooks->clearBefore();

    $summary = $hooks->summary();
    expect($summary['before'])->toBe(0);
    expect($summary['total'])->toBe(3);

    $hooks->clearAfter();
    $hooks->clearErrors();
    $hooks->clearFinally();

    expect($hooks->hasHooks())->toBeFalse();
});

test('Lifecycle hooks chain multiple before hooks in order', function (): void {
    $hooks = new EventLifecycleHooks();

    $hooks->beforeDispatch(function (AnalyticsEvent $event): AnalyticsEvent {
        return new AnalyticsEvent(
            name: $event->name,
            params: array_merge($event->params, ['hook1' => true]),
        );
    });

    $hooks->beforeDispatch(function (AnalyticsEvent $event): AnalyticsEvent {
        return new AnalyticsEvent(
            name: $event->name,
            params: array_merge($event->params, ['hook2' => true]),
        );
    });

    $result = $hooks->runBeforeHooks(new AnalyticsEvent(name: 'test', params: []));

    expect($result->params['hook1'])->toBeTrue();
    expect($result->params['hook2'])->toBeTrue();
});

test('Lifecycle hooks error hooks swallow exceptions', function (): void {
    $hooks = new EventLifecycleHooks();

    $hooks->onError(function (AnalyticsEvent $event, \Throwable $e): void {
        throw new \RuntimeException('Error hook threw');
    });

    // Should not throw
    $hooks->runErrorHooks(
        new AnalyticsEvent(name: 'test'),
        new \RuntimeException('Original error'),
    );

    expect(true)->toBeTrue(); // Reached without exception
});

// ── Version Consistency ────────────────────────────────────────────

test('AnalyticsEvent::VERSION is 68.0.0', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('68.0.0');
});

test('EventBuilder has fromBlueprint static method', function (): void {
    expect(method_exists(\ZeroBoiler\Analytics\Support\EventBuilder::class, 'fromBlueprint'))->toBeTrue();
});

test('SegmentExportService exists and is instantiable', function (): void {
    expect(new SegmentExportService())->toBeInstanceOf(SegmentExportService::class);
});

test('EventLifecycleHooks exists and is instantiable', function (): void {
    expect(new EventLifecycleHooks())->toBeInstanceOf(EventLifecycleHooks::class);
});

test('EventBlueprintRegistry exists and is instantiable', function (): void {
    expect(new EventBlueprintRegistry($this->cache))->toBeInstanceOf(EventBlueprintRegistry::class);
});

test('AnalyticsConfig has new accessors', function (): void {
    $config = new \ZeroBoiler\Analytics\Support\AnalyticsConfig(
        new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'blueprints' => ['enabled' => true, 'cache_ttl' => 7200],
                    'segment_export' => ['enabled' => true, 'write_key' => 'key_123'],
                    'lifecycle_hooks' => ['enabled' => false, 'max_hooks' => 25],
                ],
            ],
        ]),
    );

    expect($config->blueprintsEnabled())->toBeTrue();
    expect($config->blueprintsCacheTtl())->toBe(7200);
    expect($config->segmentExportEnabled())->toBeTrue();
    expect($config->segmentWriteKey())->toBe('key_123');
    expect($config->segmentApiUrl())->toBe('https://api.segment.io/v1/batch');
    expect($config->segmentBatchSize())->toBe(100);
    expect($config->segmentTimeout())->toBe(10);
    expect($config->lifecycleHooksEnabled())->toBeFalse();
    expect($config->lifecycleHooksMax())->toBe(25);
    expect($config->lifecycleHooksTimeout())->toBe(5);
});

test('AnalyticsConfig compactSummary includes new fields', function (): void {
    $config = new \ZeroBoiler\Analytics\Support\AnalyticsConfig(
        new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'blueprints' => ['enabled' => true],
                    'segment_export' => ['enabled' => false],
                    'lifecycle_hooks' => ['enabled' => true],
                ],
            ],
        ]),
    );

    $summary = $config->compactSummary();

    expect($summary)->toHaveKey('blueprints_enabled');
    expect($summary)->toHaveKey('segment_export_enabled');
    expect($summary)->toHaveKey('lifecycle_hooks_enabled');
    expect($summary['blueprints_enabled'])->toBeTrue();
    expect($summary['segment_export_enabled'])->toBeFalse();
    expect($summary['lifecycle_hooks_enabled'])->toBeTrue();
});
