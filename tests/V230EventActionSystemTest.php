<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\EventAction;
use ZeroBoiler\Analytics\EventActionRegistry;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsEventActionsCommand;

beforeEach(function (): void {
    $this->registry = new EventActionRegistry(
        config: new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'event_actions' => [
                        'enabled' => true,
                        'debug' => false,
                        'actions' => [],
                    ],
                ],
            ],
        ]),
        cache: null,
    );
});

describe('EventAction DTO', function (): void {
    it('creates an action with required fields', function (): void {
        $action = new EventAction(
            id: 'test_action',
            on: 'purchase',
            handler: fn (AnalyticsEvent $e): void => null,
        );

        expect($action->id)->toBe('test_action');
        expect($action->on)->toBe('purchase');
        expect($action->priority)->toBe(100);
        expect($action->cooldownSeconds)->toBeNull();
        expect($action->condition)->toBeNull();
        expect($action->metadata)->toBe([]);
    });

    it('creates an action with all fields', function (): void {
        $action = new EventAction(
            id: 'full_action',
            on: 'saas.*',
            handler: fn (AnalyticsEvent $e): void => null,
            priority: 10,
            cooldownSeconds: 300,
            condition: 'param.value > 100',
            metadata: ['team' => 'growth'],
        );

        expect($action->id)->toBe('full_action');
        expect($action->on)->toBe('saas.*');
        expect($action->priority)->toBe(10);
        expect($action->cooldownSeconds)->toBe(300);
        expect($action->condition)->toBe('param.value > 100');
        expect($action->metadata)->toBe(['team' => 'growth']);
    });

    it('matches exact event names', function (): void {
        $action = new EventAction(
            id: 'exact',
            on: 'purchase',
            handler: fn (AnalyticsEvent $e): void => null,
        );

        expect($action->matches('purchase'))->toBeTrue();
        expect($action->matches('sign_up'))->toBeFalse();
        expect($action->matches('refund'))->toBeFalse();
    });

    it('matches glob patterns', function (): void {
        $action = new EventAction(
            id: 'glob',
            on: 'saas.*',
            handler: fn (AnalyticsEvent $e): void => null,
        );

        expect($action->matches('saas.sign_up'))->toBeTrue();
        expect($action->matches('saas.login'))->toBeTrue();
        expect($action->matches('saas.plan_upgrade'))->toBeTrue();
        expect($action->matches('purchase'))->toBeFalse();
        expect($action->matches('ecommerce.view_item'))->toBeFalse();
    });

    it('matches category prefix patterns', function (): void {
        $action = new EventAction(
            id: 'category',
            on: 'category:ecommerce',
            handler: fn (AnalyticsEvent $e): void => null,
        );

        expect($action->matches('view_item'))->toBeTrue();
        expect($action->matches('add_to_cart'))->toBeTrue();
        expect($action->matches('purchase'))->toBeTrue();
        expect($action->matches('sign_up'))->toBeFalse();
    });

    it('evaluates condition with numeric comparison', function (): void {
        $action = new EventAction(
            id: 'conditional',
            on: 'purchase',
            handler: fn (AnalyticsEvent $e): void => null,
            condition: 'param.value > 100',
        );

        $highValue = new AnalyticsEvent(name: 'purchase', params: ['value' => 200]);
        $lowValue = new AnalyticsEvent(name: 'purchase', params: ['value' => 50]);
        $noValue = new AnalyticsEvent(name: 'purchase', params: []);

        expect($action->conditionSatisfied($highValue))->toBeTrue();
        expect($action->conditionSatisfied($lowValue))->toBeFalse();
        expect($action->conditionSatisfied($noValue))->toBeFalse();
    });

    it('evaluates condition with equality operator', function (): void {
        $action = new EventAction(
            id: 'eq_check',
            on: 'subscription',
            handler: fn (AnalyticsEvent $e): void => null,
            condition: 'param.plan == "pro"',
        );

        $pro = new AnalyticsEvent(name: 'subscription', params: ['plan' => 'pro']);
        $free = new AnalyticsEvent(name: 'subscription', params: ['plan' => 'free']);

        expect($action->conditionSatisfied($pro))->toBeTrue();
        expect($action->conditionSatisfied($free))->toBeFalse();
    });

    it('evaluates AND conditions', function (): void {
        $action = new EventAction(
            id: 'and_check',
            on: 'purchase',
            handler: fn (AnalyticsEvent $e): void => null,
            condition: 'param.value > 50 && param.currency == "USD"',
        );

        $valid = new AnalyticsEvent(name: 'purchase', params: ['value' => 100, 'currency' => 'USD']);
        $wrongCurrency = new AnalyticsEvent(name: 'purchase', params: ['value' => 100, 'currency' => 'EUR']);
        $tooLow = new AnalyticsEvent(name: 'purchase', params: ['value' => 20, 'currency' => 'USD']);

        expect($action->conditionSatisfied($valid))->toBeTrue();
        expect($action->conditionSatisfied($wrongCurrency))->toBeFalse();
        expect($action->conditionSatisfied($tooLow))->toBeFalse();
    });

    it('returns true when no condition is set', function (): void {
        $action = new EventAction(
            id: 'unconditional',
            on: 'purchase',
            handler: fn (AnalyticsEvent $e): void => null,
        );

        $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 0]);
        expect($action->conditionSatisfied($event))->toBeTrue();
    });

    it('serializes to array without handler', function (): void {
        $action = new EventAction(
            id: 'serializable',
            on: 'purchase',
            handler: fn (AnalyticsEvent $e): void => null,
            priority: 5,
            cooldownSeconds: 60,
            condition: 'param.value > 100',
            metadata: ['team' => 'growth'],
        );

        $array = $action->toArray();

        expect($array)->toHaveKey('id');
        expect($array)->toHaveKey('on');
        expect($array)->toHaveKey('priority');
        expect($array)->toHaveKey('cooldown_seconds');
        expect($array)->toHaveKey('condition');
        expect($array)->toHaveKey('metadata');
        expect($array['id'])->toBe('serializable');
        expect($array['priority'])->toBe(5);
        expect($array['cooldown_seconds'])->toBe(60);
        expect($array)->not->toHaveKey('handler');
    });
});

describe('EventActionRegistry', function (): void {
    it('registers an action programmatically', function (): void {
        $this->registry->register(new EventAction(
            id: 'test_1',
            on: 'purchase',
            handler: fn (AnalyticsEvent $e): void => null,
        ));

        expect($this->registry->count())->toBe(1);
        expect($this->registry->has('test_1'))->toBeTrue();
    });

    it('unregisters an action by ID', function (): void {
        $this->registry->register(new EventAction(
            id: 'removable',
            on: 'purchase',
            handler: fn (AnalyticsEvent $e): void => null,
        ));

        expect($this->registry->count())->toBe(1);

        $this->registry->unregister('removable');

        expect($this->registry->count())->toBe(0);
        expect($this->registry->has('removable'))->toBeFalse();
    });

    it('finds matching actions for an event', function (): void {
        $this->registry->register(new EventAction(
            id: 'purchase_action',
            on: 'purchase',
            handler: fn (AnalyticsEvent $e): void => null,
        ));

        $this->registry->register(new EventAction(
            id: 'signup_action',
            on: 'sign_up',
            handler: fn (AnalyticsEvent $e): void => null,
        ));

        $event = new AnalyticsEvent(name: 'purchase', params: []);
        $matching = $this->registry->findMatchingActions($event);

        expect($matching)->toHaveCount(1);
        expect($matching[0]->id)->toBe('purchase_action');
    });

    it('dispatches matching actions on an event', function (): void {
        $executed = [];

        $this->registry->register(new EventAction(
            id: 'track_purchase',
            on: 'purchase',
            handler: function (AnalyticsEvent $e) use (&$executed): void {
                $executed[] = 'track_purchase';
            },
        ));

        $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99]);
        $result = $this->registry->dispatch($event);

        expect($result['executed'])->toContain('track_purchase');
        expect($result['skipped'])->toBeEmpty();
        expect($result['errors'])->toBeEmpty();
        expect($executed)->toContain('track_purchase');
    });

    it('skips actions that fail condition', function (): void {
        $this->registry->register(new EventAction(
            id: 'high_value_only',
            on: 'purchase',
            handler: fn (AnalyticsEvent $e): void => fail('Should not execute'),
            condition: 'param.value > 100',
        ));

        $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 50]);
        $result = $this->registry->dispatch($event);

        expect($result['executed'])->toBeEmpty();
        expect($result['skipped'])->toContain('high_value_only');
    });

    it('catches handler errors and continues', function (): void {
        $this->registry->register(new EventAction(
            id: 'failing_action',
            on: 'purchase',
            handler: function (AnalyticsEvent $e): void {
                throw new \RuntimeException('Intentional failure');
            },
            priority: 10,
        ));

        $executed = false;

        $this->registry->register(new EventAction(
            id: 'ok_action',
            on: 'purchase',
            handler: function (AnalyticsEvent $e) use (&$executed): void {
                $executed = true;
            },
            priority: 20,
        ));

        $event = new AnalyticsEvent(name: 'purchase', params: []);
        $result = $this->registry->dispatch($event);

        expect($result['executed'])->toContain('ok_action');
        expect($result['errors'])->not->toBeEmpty();
        expect($executed)->toBeTrue();
    });

    it('sorts actions by priority before dispatch', function (): void {
        $order = [];

        $this->registry->register(new EventAction(
            id: 'low_priority',
            on: 'purchase',
            handler: function (AnalyticsEvent $e) use (&$order): void {
                $order[] = 'low_priority';
            },
            priority: 50,
        ));

        $this->registry->register(new EventAction(
            id: 'high_priority',
            on: 'purchase',
            handler: function (AnalyticsEvent $e) use (&$order): void {
                $order[] = 'high_priority';
            },
            priority: 5,
        ));

        $event = new AnalyticsEvent(name: 'purchase', params: []);
        $this->registry->dispatch($event);

        expect($order)->toBe(['high_priority', 'low_priority']);
    });

    it('returns empty result when disabled', function (): void {
        $disabledRegistry = new EventActionRegistry(
            config: new \Illuminate\Config\Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'event_actions' => [
                            'enabled' => false,
                            'actions' => [],
                        ],
                    ],
                ],
            ]),
            cache: null,
        );

        $disabledRegistry->register(new EventAction(
            id: 'ignored',
            on: 'purchase',
            handler: fn (AnalyticsEvent $e): void => fail('Should not execute'),
        ));

        $event = new AnalyticsEvent(name: 'purchase', params: []);
        $result = $disabledRegistry->dispatch($event);

        expect($result['executed'])->toBeEmpty();
    });

    it('groups actions by pattern', function (): void {
        $this->registry->register(new EventAction(
            id: 'purchase_1',
            on: 'purchase',
            handler: fn (AnalyticsEvent $e): void => null,
        ));

        $this->registry->register(new EventAction(
            id: 'purchase_2',
            on: 'purchase',
            handler: fn (AnalyticsEvent $e): void => null,
        ));

        $this->registry->register(new EventAction(
            id: 'signup_1',
            on: 'sign_up',
            handler: fn (AnalyticsEvent $e): void => null,
        ));

        $grouped = $this->registry->groupedByPattern();

        expect($grouped)->toHaveKey('purchase');
        expect($grouped['purchase'])->toHaveCount(2);
        expect($grouped)->toHaveKey('sign_up');
        expect($grouped['sign_up'])->toHaveCount(1);
    });

    it('produces a valid summary', function (): void {
        $this->registry->register(new EventAction(
            id: 'a1',
            on: 'purchase',
            handler: fn (AnalyticsEvent $e): void => null,
            priority: 10,
        ));

        $this->registry->register(new EventAction(
            id: 'a2',
            on: 'sign_up',
            handler: fn (AnalyticsEvent $e): void => null,
            priority: 50,
        ));

        $summary = $this->registry->summary();

        expect($summary['enabled'])->toBeTrue();
        expect($summary['total_actions'])->toBe(2);
        expect($summary['patterns'])->toBe(2);
        expect($summary['total_executions'])->toBe(0);
        expect($summary['actions'])->toHaveCount(2);
    });

    it('tracks execution counts', function (): void {
        $this->registry->register(new EventAction(
            id: 'counter',
            on: 'page_view',
            handler: fn (AnalyticsEvent $e): void => null,
        ));

        expect($this->registry->executionCount('counter'))->toBe(0);
        expect($this->registry->totalExecutions())->toBe(0);

        $this->registry->dispatch(new AnalyticsEvent(name: 'page_view', params: []));
        $this->registry->dispatch(new AnalyticsEvent(name: 'page_view', params: []));

        expect($this->registry->executionCount('counter'))->toBe(2);
        expect($this->registry->totalExecutions())->toBe(2);
    });

    it('flushes all actions and counts', function (): void {
        $this->registry->register(new EventAction(
            id: 'to_flush',
            on: 'purchase',
            handler: fn (AnalyticsEvent $e): void => null,
        ));

        $this->registry->dispatch(new AnalyticsEvent(name: 'purchase', params: []));

        $this->registry->flush();

        expect($this->registry->count())->toBe(0);
        expect($this->registry->totalExecutions())->toBe(0);
    });
});

describe('File Quality', function (): void {
    it('EventAction has strict types', function (): void {
        $contents = file_get_contents(__DIR__ . '/../../src/DTO/EventAction.php');
        expect($contents)->toContain('declare(strict_types=1)');
    });

    it('EventAction has MIT header', function (): void {
        $contents = file_get_contents(__DIR__ . '/../../src/DTO/EventAction.php');
        expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
    });

    it('EventAction is final readonly', function (): void {
        $reflection = new ReflectionClass(EventAction::class);
        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->isReadOnly())->toBeTrue();
    });

    it('EventActionRegistry has strict types', function (): void {
        $contents = file_get_contents(__DIR__ . '/../../src/EventActionRegistry.php');
        expect($contents)->toContain('declare(strict_types=1)');
    });

    it('EventActionRegistry has MIT header', function (): void {
        $contents = file_get_contents(__DIR__ . '/../../src/EventActionRegistry.php');
        expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
    });

    it('EventActionRegistry is final', function (): void {
        $reflection = new ReflectionClass(EventActionRegistry::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    it('EventActionRegistry constructor has void return type', function (): void {
        $method = new ReflectionMethod(EventActionRegistry::class, '__construct');
        expect($method->hasReturnType())->toBeTrue();
        expect((string) $method->getReturnType())->toBe('void');
    });

    it('AnalyticsEventActionsCommand has strict types', function (): void {
        $contents = file_get_contents(__DIR__ . '/../../src/Console/Commands/AnalyticsEventActionsCommand.php');
        expect($contents)->toContain('declare(strict_types=1)');
    });

    it('AnalyticsEventActionsCommand has MIT header', function (): void {
        $contents = file_get_contents(__DIR__ . '/../../src/Console/Commands/AnalyticsEventActionsCommand.php');
        expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
    });

    it('AnalyticsEventActionsCommand is final', function (): void {
        $reflection = new ReflectionClass(AnalyticsEventActionsCommand::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    it('AnalyticsEventActionsCommand has @since 230.0.0', function (): void {
        $contents = file_get_contents(__DIR__ . '/../../src/Console/Commands/AnalyticsEventActionsCommand.php');
        expect($contents)->toContain('@since 230.0.0');
    });

    it('EventAction has @since 230.0.0', function (): void {
        $contents = file_get_contents(__DIR__ . '/../../src/DTO/EventAction.php');
        expect($contents)->toContain('@since 230.0.0');
    });

    it('EventActionRegistry has @since 230.0.0', function (): void {
        $contents = file_get_contents(__DIR__ . '/../../src/EventActionRegistry.php');
        expect($contents)->toContain('@since 230.0.0');
    });

    it('all public methods have return type declarations', function (): void {
        $reflection = new ReflectionClass(EventActionRegistry::class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if ($method->getName() === '__construct') {
                expect($method->hasReturnType())->toBeTrue();

                continue;
            }

            expect($method->hasReturnType())
                ->toBeTrue("Method {$method->getName()}() is missing return type");
        }
    });

    it('AnalyticsEvent VERSION is 230.0.0', function (): void {
        expect(AnalyticsEvent::VERSION)->toBe('230.0.0');
    });

    it('composer.json version is 230.0.0', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);

        expect($composer['version'])->toBe('230.0.0');
    });
});
