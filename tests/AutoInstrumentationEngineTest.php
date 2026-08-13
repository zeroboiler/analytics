<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\AutoInstrumentationEngine;

/**
 * Tests for the Auto-Instrumentation Engine (v50.0.0).
 *
 * Verifies config-driven Eloquent model event mapping to analytics events.
 *
 * @covers \ZeroBoiler\Analytics\Services\AutoInstrumentationEngine
 *
 * @since 50.0.0
 */
test('auto-instrumentation engine is disabled by default', function (): void {
    $engine = new AutoInstrumentationEngine(
        config: createConfigRepository([]),
        manager: createMockManager(),
        dispatcher: createEventDispatcher(),
    );

    expect($engine->isEnabled())->toBeFalse();
});

test('auto-instrumentation engine can be enabled via config', function (): void {
    $engine = new AutoInstrumentationEngine(
        config: createConfigRepository(['enabled' => true]),
        manager: createMockManager(),
        dispatcher: createEventDispatcher(),
    );

    expect($engine->isEnabled())->toBeTrue();
});

test('engine extracts user id from model with getAuthIdentifier', function (): void {
    $engine = new AutoInstrumentationEngine(
        config: createConfigRepository([
            'enabled' => true,
            'extract_user_id' => true,
        ]),
        manager: createMockManager(),
        dispatcher: createEventDispatcher(),
    );

    $engine->boot();

    expect($engine->isBooted())->toBeTrue();
});

test('engine extracts params from model attributes with param mapping', function (): void {
    $tracked = [];

    $manager = createMockManager(function (AnalyticsEvent $event) use (&$tracked): void {
        $tracked[] = $event;
    });

    $engine = new AutoInstrumentationEngine(
        config: createConfigRepository([
            'enabled' => true,
            'models' => [
                'Tests\\Models\\TestUser' => [
                    'created' => 'sign_up',
                    'param_map' => [
                        'name' => 'full_name',
                        'email' => 'email',
                    ],
                    'exclude_params' => ['password', 'secret'],
                ],
            ],
            'extract_user_id' => false,
            'include_utm' => false,
        ]),
        manager: $manager,
        dispatcher: createEventDispatcher(),
    );

    $model = new class extends \Illuminate\Database\Eloquent\Model {
        protected $attributes = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'secret123',
            'secret' => 'hidden',
        ];
    };

    $engine->trigger('sign_up', $model);

    expect($tracked)->toHaveCount(1);
    expect($tracked[0]->name)->toBe('sign_up');
    expect($tracked[0]->params['full_name'])->toBe('John Doe');
    expect($tracked[0]->params['email'])->toBe('john@example.com');
    expect($tracked[0]->params)->not->toHaveKey('password');
    expect($tracked[0]->params)->not->toHaveKey('secret');
    expect($tracked[0]->source)->toBe('auto_instrument');
});

test('engine handles custom param extractors', function (): void {
    $tracked = [];

    $manager = createMockManager(function (AnalyticsEvent $event) use (&$tracked): void {
        $tracked[] = $event;
    });

    $engine = new AutoInstrumentationEngine(
        config: createConfigRepository([
            'enabled' => true,
            'extract_user_id' => false,
            'include_utm' => false,
        ]),
        manager: $manager,
        dispatcher: createEventDispatcher(),
    );

    $engine->addParamExtractor(function (\Illuminate\Database\Eloquent\Model $model, string $event): array {
        return ['custom_source' => 'test_extractor'];
    });

    $model = new class extends \Illuminate\Database\Eloquent\Model {
        protected $attributes = ['name' => 'Test'];
    };

    $engine->trigger('test_event', $model);

    expect($tracked)->toHaveCount(1);
    expect($tracked[0]->params['custom_source'])->toBe('test_extractor');
});

test('event transformer can cancel dispatch', function (): void {
    $tracked = [];

    $manager = createMockManager(function (AnalyticsEvent $event) use (&$tracked): void {
        $tracked[] = $event;
    });

    $engine = new AutoInstrumentationEngine(
        config: createConfigRepository([
            'enabled' => true,
            'extract_user_id' => false,
            'include_utm' => false,
        ]),
        manager: $manager,
        dispatcher: createEventDispatcher(),
    );

    $engine->addEventTransformer(function (AnalyticsEvent $event): ?AnalyticsEvent {
        // Cancel all events containing 'skip' in name
        if (str_contains($event->name, 'skip')) {
            return null;
        }

        return $event;
    });

    $model = new class extends \Illuminate\Database\Eloquent\Model {
        protected $attributes = ['name' => 'Test'];
    };

    $engine->trigger('skip_event', $model);
    $engine->trigger('normal_event', $model);

    expect($tracked)->toHaveCount(1);
    expect($tracked[0]->name)->toBe('normal_event');
});

test('engine returns configured models list', function (): void {
    $engine = new AutoInstrumentationEngine(
        config: createConfigRepository([
            'enabled' => true,
            'models' => [
                'App\\Models\\User' => [],
                'App\\Models\\Order' => [],
            ],
        ]),
        manager: createMockManager(),
        dispatcher: createEventDispatcher(),
    );

    $models = $engine->getConfiguredModels();

    expect($models)->toContain('App\\Models\\User');
    expect($models)->toContain('App\\Models\\Order');
});

test('engine returns correct mappings', function (): void {
    $engine = new AutoInstrumentationEngine(
        config: createConfigRepository([
            'enabled' => true,
            'models' => [
                'App\\Models\\User' => [
                    'created' => 'sign_up',
                    'deleted' => 'cancellation',
                ],
            ],
        ]),
        manager: createMockManager(),
        dispatcher: createEventDispatcher(),
    );

    $mappings = $engine->getMappings();

    expect($mappings['App\\Models\\User']['created'])->toBe('sign_up');
    expect($mappings['App\\Models\\User']['deleted'])->toBe('cancellation');
    expect($mappings['App\\Models\\User']['updated'])->toBeNull();
});

test('engine excludes sensitive params by default', function (): void {
    $tracked = [];

    $manager = createMockManager(function (AnalyticsEvent $event) use (&$tracked): void {
        $tracked[] = $event;
    });

    $engine = new AutoInstrumentationEngine(
        config: createConfigRepository([
            'enabled' => true,
            'extract_user_id' => false,
            'include_utm' => false,
        ]),
        manager: $manager,
        dispatcher: createEventDispatcher(),
    );

    $model = new class extends \Illuminate\Database\Eloquent\Model {
        protected $attributes = [
            'name' => 'Test',
            'password' => 'secret',
            'password_hash' => '$2y$10$hash',
            'remember_token' => 'token123',
        ];
    };

    $engine->trigger('test_event', $model);

    expect($tracked)->toHaveCount(1);
    expect($tracked[0]->params)->not->toHaveKey('password');
    expect($tracked[0]->params)->not->toHaveKey('password_hash');
    expect($tracked[0]->params)->not->toHaveKey('remember_token');
    expect($tracked[0]->params)->not->toHaveKey('two_factor_secret');
});

// ── Helper Functions ───────────────────────────────────────────────────

/**
 * Create a mock config repository with the given analytics config.
 *
 * @param  array<string, mixed>  $autoInstrumentConfig
 */
function createConfigRepository(array $autoInstrumentConfig): \Illuminate\Config\Repository
{
    $config = new \Illuminate\Config\Repository;
    $config->set('zeroboiler.analytics.auto_instrument', $autoInstrumentConfig);

    return $config;
}

/**
 * Create a mock AnalyticsManager that captures tracked events.
 *
 * @param  (callable(AnalyticsEvent): void)|null  $callback
 */
function createMockManager(?callable $callback = null): \ZeroBoiler\Analytics\AnalyticsManager
{
    return new class($callback) extends \ZeroBoiler\Analytics\AnalyticsManager {
        /** @var callable(AnalyticsEvent): void */
        private $captureCallback;

        public function __construct(?callable $callback)
        {
            $this->captureCallback = $callback ?? function (): void {};
        }

        public function trackEvent(AnalyticsEvent $event): void
        {
            ($this->captureCallback)($event);
        }
    };
}

/**
 * Create a mock event dispatcher.
 */
function createEventDispatcher(): \Illuminate\Events\Dispatcher
{
    return new \Illuminate\Events\Dispatcher;
}
