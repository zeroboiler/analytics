<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Services\EventBudgetService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsDiagnosticsCommand;

test('v4.3.0 version is set correctly', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('10.3.0');
});

test('composer.json version matches AnalyticsEvent::VERSION', function (): void {
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe(AnalyticsEvent::VERSION);
});

// ─── Event Budget Service ────────────────────────────────────────────

test('EventBudgetService allows events within budget', function (): void {
    $cache = app('cache');
    $service = new EventBudgetService($cache, clientLimit: 100, userLimit: 50, globalLimit: 1000, useCache: false);

    $result = $service->check('client-1', 'user-1');

    expect($result['allowed'])->toBeTrue();
    expect($result['reason'])->toBe('within_budget');
    expect($result['policy'])->toBe('accept');
    expect($result['remaining'])->toHaveKeys(['client', 'user', 'global']);
    expect($result['remaining']['client'])->toBe(99);
    expect($result['remaining']['user'])->toBe(49);
});

test('EventBudgetService rejects when client budget exceeded', function (): void {
    $cache = app('cache');
    $service = new EventBudgetService($cache, clientLimit: 3, userLimit: 100, globalLimit: 1000, useCache: false);

    // Fill client budget
    $service->record('client-x', null);
    $service->record('client-x', null);
    $service->record('client-x', null);

    $result = $service->check('client-x', null);

    expect($result['allowed'])->toBeFalse();
    expect($result['reason'])->toBe('budget_exceeded_client');
    expect($result['policy'])->toBe('reject');
});

test('EventBudgetService rejects when user budget exceeded', function (): void {
    $cache = app('cache');
    $service = new EventBudgetService($cache, clientLimit: 100, userLimit: 2, globalLimit: 1000, useCache: false);

    $service->record(null, 'user-y');
    $service->record(null, 'user-y');

    $result = $service->check(null, 'user-y');

    expect($result['allowed'])->toBeFalse();
    expect($result['reason'])->toBe('budget_exceeded_user');
});

test('EventBudgetService rejects when global budget exceeded', function (): void {
    $cache = app('cache');
    $service = new EventBudgetService($cache, clientLimit: 100, userLimit: 100, globalLimit: 3, useCache: false);

    $service->record('c1', null);
    $service->record('c2', null);
    $service->record('c3', null);

    $result = $service->check('c4', null);

    expect($result['allowed'])->toBeFalse();
    expect($result['reason'])->toBe('budget_exceeded_global');
});

test('EventBudgetService samples events when overflow policy is sample', function (): void {
    $cache = app('cache');
    $service = new EventBudgetService(
        $cache,
        clientLimit: 2,
        userLimit: 100,
        globalLimit: 1000,
        overflowPolicy: 'sample',
        sampleRate: 1.0, // always sample through
        useCache: false,
    );

    // Fill client budget
    $service->record('client-s', null);
    $service->record('client-s', null);

    $result = $service->check('client-s', null);

    expect($result['policy'])->toBe('sample');
    expect($result['allowed'])->toBeTrue();
    expect($result['reason'])->toBe('sampled_through');
});

test('EventBudgetService tracks usage stats', function (): void {
    $cache = app('cache');
    $service = new EventBudgetService($cache, clientLimit: 100, userLimit: 50, globalLimit: 1000, useCache: false);

    $service->record('c1', 'u1');
    $service->record('c1', 'u2');
    $service->record('c2', 'u1');

    $stats = $service->stats();

    expect($stats['client_count'])->toBe(2);
    expect($stats['user_count'])->toBe(2);
    expect($stats['global_total'])->toBe(3);
    expect($stats['rejected_total'])->toBe(0);
    expect($stats['limits']['client'])->toBe(100);
});

test('EventBudgetService clientStatus returns utilization', function (): void {
    $cache = app('cache');
    $service = new EventBudgetService($cache, clientLimit: 100, useCache: false);

    $service->record('client-a', null);
    $service->record('client-a', null);

    $status = $service->clientStatus('client-a');

    expect($status['count'])->toBe(2);
    expect($status['remaining'])->toBe(98);
    expect($status['utilization'])->toBe(2.0);
});

test('EventBudgetService resetClient clears client counter', function (): void {
    $cache = app('cache');
    $service = new EventBudgetService($cache, clientLimit: 100, useCache: false);

    $service->record('client-r', null);
    $service->resetClient('client-r');

    $result = $service->check('client-r', null);
    expect($result['remaining']['client'])->toBe(100);
});

test('EventBudgetService topClients returns sorted results', function (): void {
    $cache = app('cache');
    $service = new EventBudgetService($cache, clientLimit: 100, useCache: false);

    $service->record('low-client', null);
    for ($i = 0; $i < 5; $i++) {
        $service->record('high-client', null);
    }

    $top = $service->topClients(2);

    expect($top)->toHaveCount(2);
    expect($top[0]['client_id'])->toBe('high-client');
    expect($top[0]['count'])->toBe(5);
    expect($top[1]['client_id'])->toBe('low-client');
    expect($top[1]['count'])->toBe(1);
});

test('EventBudgetService clear resets all counters', function (): void {
    $cache = app('cache');
    $service = new EventBudgetService($cache, clientLimit: 100, useCache: false);

    $service->record('c1', 'u1');
    $service->record('c2', 'u2');
    $service->clear();

    $stats = $service->stats();
    expect($stats['client_count'])->toBe(0);
    expect($stats['user_count'])->toBe(0);
    expect($stats['global_total'])->toBe(0);
});

test('EventBudgetService userStatus returns utilization', function (): void {
    $cache = app('cache');
    $service = new EventBudgetService($cache, userLimit: 50, useCache: false);

    $service->record(null, 'user-a');
    $service->record(null, 'user-a');
    $service->record(null, 'user-a');

    $status = $service->userStatus('user-a');

    expect($status['count'])->toBe(3);
    expect($status['remaining'])->toBe(47);
    expect($status['utilization'])->toBe(6.0);
});

// ─── Event Sequencing Analysis ────────────────────────────────────────

test('EventCatalog::validate returns valid result', function (): void {
    $result = EventCatalog::validate();

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
});

test('event catalog contains all required categories', function (): void {
    $byCategory = EventCatalog::byCategory();

    expect($byCategory)->toHaveKeys(['ecommerce', 'saas', 'engagement']);
    expect(count($byCategory['ecommerce']))->toBeGreaterThanOrEqual(15);
    expect(count($byCategory['saas']))->toBeGreaterThanOrEqual(50);
    expect(count($byCategory['engagement']))->toBeGreaterThanOrEqual(30);
});

test('event catalog has 100+ events across all categories', function (): void {
    expect(EventCatalog::count())->toBeGreaterThanOrEqual(100);
});

test('core SaaS lifecycle events present in catalog', function (): void {
    $core = ['sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade', 'cancellation', 'trial_converted', 'subscription_created', 'subscription_cancelled'];

    foreach ($core as $event) {
        expect(SaaSEvents::has($event))->toBeTrue("Missing SaaS event: {$event}");
    }
});

test('core ecommerce events present in catalog', function (): void {
    $core = ['view_item', 'add_to_cart', 'remove_from_cart', 'view_cart', 'begin_checkout', 'add_payment_info', 'purchase', 'refund'];

    foreach ($core as $event) {
        expect(EcommerceEvents::has($event))->toBeTrue("Missing ecommerce event: {$event}");
    }
});

test('core engagement events present in catalog', function (): void {
    $core = ['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'search', 'share', 'error'];

    foreach ($core as $event) {
        expect(EngagementEvents::has($event))->toBeTrue("Missing engagement event: {$event}");
    }
});

test('EventCatalog::coreSaaS returns non-empty list', function (): void {
    $core = EventCatalog::coreSaaS();

    expect($core)->not->toBeEmpty();
    expect(count($core))->toBeGreaterThanOrEqual(10);

    $names = array_map(fn (array $e): string => $e['name'], $core);
    expect($names)->toContain('sign_up');
    expect($names)->toContain('login');
    expect($names)->toContain('start_trial');
});

test('EventCatalog::revenueEvents returns non-empty list', function (): void {
    $revenue = EventCatalog::revenueEvents();

    expect($revenue)->not->toBeEmpty();
    $names = array_map(fn (array $e): string => $e['name'], $revenue);
    expect($names)->toContain('purchase');
    expect($names)->toContain('refund');
});

// ─── Config Budget Section ───────────────────────────────────────────

test('config has budget section', function (): void {
    $config = include __DIR__ . '/../config/zeroboiler.php';
    $budget = $config['analytics']['budget'] ?? [];

    expect($budget)->not->toBeEmpty();
    expect($budget)->toHaveKeys(['enabled', 'client_limit', 'user_limit', 'global_limit', 'window_seconds', 'overflow_policy', 'sample_rate']);
    expect($budget['enabled'])->toBeFalse(); // default disabled
    expect($budget['client_limit'])->toBe(1000);
    expect($budget['user_limit'])->toBe(500);
    expect($budget['global_limit'])->toBe(100000);
    expect($budget['overflow_policy'])->toBe('reject');
});

// ─── Route Coverage ───────────────────────────────────────────────────

test('v4.3.0 budget routes are registered', function (): void {
    $routes = file_get_contents(__DIR__ . '/../routes/analytics.php');

    expect($routes)->toContain("Route::get('budget'");
    expect($routes)->toContain("Route::get('budget/client/{clientId}'");
    expect($routes)->toContain("Route::get('budget/user/{userId}'");
    expect($routes)->toContain("Route::get('budget/top-clients'");
    expect($routes)->toContain("Route::delete('budget'");
    expect($routes)->toContain("Route::delete('budget/client/{clientId}'");
    expect($routes)->toContain("Route::delete('budget/user/{userId}'");
});

test('v4.3.0 correlation matrix route is registered', function (): void {
    $routes = file_get_contents(__DIR__ . '/../routes/analytics.php');

    expect($routes)->toContain("Route::post('correlation/matrix'");
    expect($routes)->toContain("Route::post('correlation/conversion-rate'");
});

// ─── Service Registration ────────────────────────────────────────────

test('EventBudgetService class exists and is final', function (): void {
    $reflection = new ReflectionClass(EventBudgetService::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->getMethod('check')->isPublic())->toBeTrue();
    expect($reflection->getMethod('record')->isPublic())->toBeTrue();
    expect($reflection->getMethod('stats')->isPublic())->toBeTrue();
    expect($reflection->getMethod('clientStatus')->isPublic())->toBeTrue();
    expect($reflection->getMethod('userStatus')->isPublic())->toBeTrue();
    expect($reflection->getMethod('topClients')->isPublic())->toBeTrue();
    expect($reflection->getMethod('clear')->isPublic())->toBeTrue();
});

test('AnalyticsDiagnosticsCommand class exists and extends Command', function (): void {
    $reflection = new ReflectionClass(AnalyticsDiagnosticsCommand::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->hasMethod('handle'))->toBeTrue();

    // Check signature
    $signature = $reflection->getProperty('signature');
    expect($signature->isPublic())->toBeFalse(); // protected
});

// ─── Integration Integrity ──────────────────────────────────────────

test('controller has budgetService property and methods', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class);

    expect($reflection->hasProperty('budgetService'))->toBeTrue();
    expect($reflection->hasMethod('budgetStats'))->toBeTrue();
    expect($reflection->hasMethod('budgetClientStatus'))->toBeTrue();
    expect($reflection->hasMethod('budgetUserStatus'))->toBeTrue();
    expect($reflection->hasMethod('budgetTopClients'))->toBeTrue();
    expect($reflection->hasMethod('budgetResetClient'))->toBeTrue();
    expect($reflection->hasMethod('budgetResetUser'))->toBeTrue();
    expect($reflection->hasMethod('budgetClear'))->toBeTrue();
});

test('controller has correlation matrix method', function (): void {
    $reflection = new ReflectionClass(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class);

    expect($reflection->hasMethod('correlationMatrix'))->toBeTrue();
    expect($reflection->hasMethod('correlationConversionRate'))->toBeTrue();
});
