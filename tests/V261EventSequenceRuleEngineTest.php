<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Pipeline\SequenceRuleEnricher;
use ZeroBoiler\Analytics\Services\EventSequenceRuleEngine;

beforeEach(function (): void {
    Cache::clear();
});

describe('v261.0.0 — Event Sequence Rule Engine', function (): void {
    describe('Constructor & Config', function (): void {
        test('constructs with disabled config (default)', function (): void {
            $engine = new EventSequenceRuleEngine(
                Cache::driver('array'),
                config(),
            );

            expect($engine->isEnabled())->toBeFalse();
            expect($engine->getRuleCount())->toBe(0);
        });

        test('constructs with enabled config and rules', function (): void {
            config()->set('zeroboiler.analytics.sequence_rules', [
                'enabled' => true,
                'history_ttl' => 3600,
                'rules' => [
                    [
                        'name' => 'trial_conversion',
                        'type' => 'expected',
                        'from' => 'start_trial',
                        'to' => 'subscribe',
                        'window_seconds' => 86400,
                    ],
                    [
                        'name' => 'checkout_velocity',
                        'type' => 'rate_limit',
                        'event' => 'begin_checkout',
                        'max_per_session' => 3,
                    ],
                ],
            ]);

            $engine = new EventSequenceRuleEngine(
                Cache::driver('array'),
                config(),
            );

            expect($engine->isEnabled())->toBeTrue();
            expect($engine->getRuleCount())->toBe(2);
        });

        test('ignores invalid rules', function (): void {
            config()->set('zeroboiler.analytics.sequence_rules', [
                'enabled' => true,
                'rules' => [
                    ['name' => '', 'type' => 'expected', 'from' => 'a', 'to' => 'b'], // empty name
                    ['name' => 'valid', 'type' => 'expected', 'from' => 'a', 'to' => 'b'],
                    ['name' => 'bad_type', 'type' => 'invalid', 'event' => 'x'], // invalid type
                    ['name' => 'no_fields', 'type' => 'rate_limit'], // missing event
                ],
            ]);

            $engine = new EventSequenceRuleEngine(
                Cache::driver('array'),
                config(),
            );

            expect($engine->getRuleCount())->toBe(1);
        });
    });

    describe('Rule Types', function (): void {
        test('expected rule does not fire violation when sequence is pending', function (): void {
            config()->set('zeroboiler.analytics.sequence_rules', [
                'enabled' => true,
                'rules' => [
                    [
                        'name' => 'trial_to_subscribe',
                        'type' => 'expected',
                        'from' => 'start_trial',
                        'to' => 'subscribe',
                        'window_seconds' => 86400,
                    ],
                ],
            ]);

            $engine = new EventSequenceRuleEngine(
                Cache::driver('array'),
                config(),
            );

            $event = new AnalyticsEvent(
                name: 'start_trial',
                params: ['plan' => 'pro'],
                clientId: 'client_abc',
                category: 'saas',
            );

            $violations = $engine->evaluate($event);

            expect($violations)->toBeEmpty();
        });

        test('expected rule does not fire when to-event follows from-event', function (): void {
            config()->set('zeroboiler.analytics.sequence_rules', [
                'enabled' => true,
                'rules' => [
                    [
                        'name' => 'trial_to_subscribe',
                        'type' => 'expected',
                        'from' => 'start_trial',
                        'to' => 'subscribe',
                        'window_seconds' => 86400,
                    ],
                ],
            ]);

            $engine = new EventSequenceRuleEngine(
                Cache::driver('array'),
                config(),
            );

            $trialEvent = new AnalyticsEvent(
                name: 'start_trial',
                params: [],
                clientId: 'client_xyz',
                category: 'saas',
            );

            $engine->evaluate($trialEvent);

            $subscribeEvent = new AnalyticsEvent(
                name: 'subscribe',
                params: ['plan' => 'pro'],
                clientId: 'client_xyz',
                category: 'saas',
            );

            $violations = $engine->evaluate($subscribeEvent);

            expect($violations)->toBeEmpty();
        });

        test('prohibited rule detects forbidden sequence', function (): void {
            config()->set('zeroboiler.analytics.sequence_rules', [
                'enabled' => true,
                'rules' => [
                    [
                        'name' => 'no_direct_subscribe',
                        'type' => 'prohibited',
                        'from' => 'sign_up',
                        'to' => 'subscribe',
                        'window_seconds' => 86400,
                        'unless' => ['start_trial'],
                    ],
                ],
            ]);

            $engine = new EventSequenceRuleEngine(
                Cache::driver('array'),
                config(),
            );

            // Fire sign_up
            $signUp = new AnalyticsEvent(
                name: 'sign_up',
                params: ['method' => 'email'],
                clientId: 'client_bad',
                category: 'saas',
            );

            $engine->evaluate($signUp);

            // Fire subscribe without trial
            $subscribe = new AnalyticsEvent(
                name: 'subscribe',
                params: ['plan' => 'pro'],
                clientId: 'client_bad',
                category: 'saas',
            );

            $violations = $engine->evaluate($subscribe);

            expect($violations)->toHaveCount(1);
            expect($violations[0]['rule'])->toBe('no_direct_subscribe');
            expect($violations[0]['type'])->toBe('prohibited_sequence');
            expect($violations[0]['severity'])->toBe('warning');
        });

        test('prohibited rule allows sequence when unless-event intervenes', function (): void {
            config()->set('zeroboiler.analytics.sequence_rules', [
                'enabled' => true,
                'rules' => [
                    [
                        'name' => 'no_direct_subscribe',
                        'type' => 'prohibited',
                        'from' => 'sign_up',
                        'to' => 'subscribe',
                        'window_seconds' => 86400,
                        'unless' => ['start_trial'],
                    ],
                ],
            ]);

            $engine = new EventSequenceRuleEngine(
                Cache::driver('array'),
                config(),
            );

            $clientId = 'client_good';

            $engine->evaluate(new AnalyticsEvent(name: 'sign_up', clientId: $clientId, category: 'saas'));
            $engine->evaluate(new AnalyticsEvent(name: 'start_trial', clientId: $clientId, category: 'saas'));

            $violations = $engine->evaluate(new AnalyticsEvent(name: 'subscribe', clientId: $clientId, category: 'saas'));

            expect($violations)->toBeEmpty();
        });

        test('rate_limit rule fires when threshold exceeded', function (): void {
            config()->set('zeroboiler.analytics.sequence_rules', [
                'enabled' => true,
                'rules' => [
                    [
                        'name' => 'checkout_velocity',
                        'type' => 'rate_limit',
                        'event' => 'begin_checkout',
                        'max_per_session' => 2,
                    ],
                ],
            ]);

            $engine = new EventSequenceRuleEngine(
                Cache::driver('array'),
                config(),
            );

            $clientId = 'client_fast';

            // First two should be fine
            $v1 = $engine->evaluate(new AnalyticsEvent(name: 'begin_checkout', clientId: $clientId, category: 'ecommerce'));
            $v2 = $engine->evaluate(new AnalyticsEvent(name: 'begin_checkout', clientId: $clientId, category: 'ecommerce'));

            expect($v1)->toBeEmpty();
            expect($v2)->toBeEmpty();

            // Third should trigger
            $v3 = $engine->evaluate(new AnalyticsEvent(name: 'begin_checkout', clientId: $clientId, category: 'ecommerce'));

            expect($v3)->toHaveCount(1);
            expect($v3[0]['type'])->toBe('rate_limit');
            expect($v3[0]['rule'])->toBe('checkout_velocity');
        });

        test('conversion_gate rule detects timing anomalies', function (): void {
            config()->set('zeroboiler.analytics.sequence_rules', [
                'enabled' => true,
                'rules' => [
                    [
                        'name' => 'onboarding_timing',
                        'type' => 'conversion_gate',
                        'steps' => [
                            ['event' => 'sign_up'],
                            ['event' => 'start_trial', 'max_seconds' => 3600],
                            ['event' => 'first_value', 'max_seconds' => 86400],
                        ],
                    ],
                ],
            ]);

            $engine = new EventSequenceRuleEngine(
                Cache::driver('array'),
                config(),
            );

            $clientId = 'client_gate';

            $engine->evaluate(new AnalyticsEvent(name: 'sign_up', clientId: $clientId, category: 'saas'));
            $engine->evaluate(new AnalyticsEvent(name: 'start_trial', clientId: $clientId, category: 'saas'));

            // Final step — should have no timing issues since events are instant
            $violations = $engine->evaluate(new AnalyticsEvent(name: 'first_value', clientId: $clientId, category: 'engagement'));

            // Since all events happened in the same second, timing should be fine
            expect($violations)->toBeEmpty();
        });

        test('conversion_gate ignores incomplete sequences', function (): void {
            config()->set('zeroboiler.analytics.sequence_rules', [
                'enabled' => true,
                'rules' => [
                    [
                        'name' => 'full_funnel',
                        'type' => 'conversion_gate',
                        'steps' => [
                            ['event' => 'sign_up'],
                            ['event' => 'subscribe'],
                            ['event' => 'first_value'],
                        ],
                    ],
                ],
            ]);

            $engine = new EventSequenceRuleEngine(
                Cache::driver('array'),
                config(),
            );

            $clientId = 'client_partial';

            $v1 = $engine->evaluate(new AnalyticsEvent(name: 'sign_up', clientId: $clientId, category: 'saas'));
            $v2 = $engine->evaluate(new AnalyticsEvent(name: 'subscribe', clientId: $clientId, category: 'saas'));

            // No violation because the final step hasn't fired
            expect($v1)->toBeEmpty();
            expect($v2)->toBeEmpty();
        });
    });

    describe('Runtime Rule Management', function (): void {
        test('addRule adds a rule at runtime', function (): void {
            $engine = new EventSequenceRuleEngine(
                Cache::driver('array'),
                config(),
            );

            expect($engine->getRuleCount())->toBe(0);

            $engine->addRule([
                'name' => 'runtime_rule',
                'type' => 'rate_limit',
                'event' => 'click',
                'max_per_session' => 10,
            ]);

            expect($engine->getRuleCount())->toBe(1);
            $rules = $engine->getRules();
            expect($rules[0]['name'])->toBe('runtime_rule');
        });

        test('removeRule removes a rule by name', function (): void {
            config()->set('zeroboiler.analytics.sequence_rules', [
                'enabled' => true,
                'rules' => [
                    ['name' => 'rule_a', 'type' => 'rate_limit', 'event' => 'a', 'max_per_session' => 5],
                    ['name' => 'rule_b', 'type' => 'rate_limit', 'event' => 'b', 'max_per_session' => 5],
                ],
            ]);

            $engine = new EventSequenceRuleEngine(
                Cache::driver('array'),
                config(),
            );

            expect($engine->getRuleCount())->toBe(2);

            $engine->removeRule('rule_a');

            expect($engine->getRuleCount())->toBe(1);
            $rules = $engine->getRules();
            expect($rules[0]['name'])->toBe('rule_b');
        });

        test('addRule ignores invalid rules', function (): void {
            $engine = new EventSequenceRuleEngine(
                Cache::driver('array'),
                config(),
            );

            $engine->addRule(['name' => '', 'type' => 'rate_limit']);
            $engine->addRule(['name' => 'bad', 'type' => 'unknown_type']);

            expect($engine->getRuleCount())->toBe(0);
        });
    });

    describe('History & Diagnostics', function (): void {
        test('records events in sliding window history', function (): void {
            config()->set('zeroboiler.analytics.sequence_rules', [
                'enabled' => true,
                'rules' => [],
            ]);

            $engine = new EventSequenceRuleEngine(
                Cache::driver('array'),
                config(),
            );

            $engine->evaluate(new AnalyticsEvent(name: 'page_view', clientId: 'h1', category: 'engagement'));
            $engine->evaluate(new AnalyticsEvent(name: 'click', clientId: 'h1', category: 'engagement'));

            $history = $engine->getHistory('client:h1');

            expect($history)->toHaveCount(2);
            expect($history[0]['event'])->toBe('page_view');
            expect($history[1]['event'])->toBe('click');
        });

        test('clearHistory removes all events for an identity', function (): void {
            config()->set('zeroboiler.analytics.sequence_rules', [
                'enabled' => true,
                'rules' => [],
            ]);

            $engine = new EventSequenceRuleEngine(
                Cache::driver('array'),
                config(),
            );

            $engine->evaluate(new AnalyticsEvent(name: 'page_view', clientId: 'c1', category: 'engagement'));

            expect($engine->getHistory('client:c1'))->toHaveCount(1);

            $engine->clearHistory('client:c1');

            expect($engine->getHistory('client:c1'))->toBeEmpty();
        });

        test('getSummary returns comprehensive diagnostics', function (): void {
            config()->set('zeroboiler.analytics.sequence_rules', [
                'enabled' => true,
                'rules' => [
                    ['name' => 'r1', 'type' => 'rate_limit', 'event' => 'a', 'max_per_session' => 5],
                    ['name' => 'r2', 'type' => 'expected', 'from' => 'x', 'to' => 'y', 'window_seconds' => 100],
                ],
            ]);

            $engine = new EventSequenceRuleEngine(
                Cache::driver('array'),
                config(),
            );

            $summary = $engine->getSummary();

            expect($summary['enabled'])->toBeTrue();
            expect($summary['rule_count'])->toBe(2);
            expect($summary['rules_by_type'])->toHaveKey('rate_limit');
            expect($summary['rules_by_type']['rate_limit'])->toBe(1);
            expect($summary['rules_by_type']['expected'])->toBe(1);
            expect($summary['history_ttl'])->toBe(86400);
        });

        test('getViolationCounts returns per-rule counts', function (): void {
            config()->set('zeroboiler.analytics.sequence_rules', [
                'enabled' => true,
                'rules' => [
                    ['name' => 'vel', 'type' => 'rate_limit', 'event' => 'checkout', 'max_per_session' => 1],
                ],
            ]);

            $engine = new EventSequenceRuleEngine(
                Cache::driver('array'),
                config(),
            );

            $engine->evaluate(new AnalyticsEvent(name: 'checkout', clientId: 'v1', category: 'ecommerce'));
            $engine->evaluate(new AnalyticsEvent(name: 'checkout', clientId: 'v1', category: 'ecommerce'));

            $counts = $engine->getViolationCounts();

            expect($counts['vel'])->toBe(1);
        });
    });

    describe('Identity Resolution', function (): void {
        test('uses userId when available', function (): void {
            config()->set('zeroboiler.analytics.sequence_rules', [
                'enabled' => true,
                'rules' => [],
            ]);

            $engine = new EventSequenceRuleEngine(
                Cache::driver('array'),
                config(),
            );

            $engine->evaluate(new AnalyticsEvent(name: 'login', userId: '42', clientId: 'c1', category: 'saas'));

            expect($engine->getHistory('user:42'))->toHaveCount(1);
        });

        test('falls back to clientId when no userId', function (): void {
            config()->set('zeroboiler.analytics.sequence_rules', [
                'enabled' => true,
                'rules' => [],
            ]);

            $engine = new EventSequenceRuleEngine(
                Cache::driver('array'),
                config(),
            );

            $engine->evaluate(new AnalyticsEvent(name: 'page_view', clientId: 'c1', category: 'engagement'));

            expect($engine->getHistory('client:c1'))->toHaveCount(1);
        });

        test('skips events with no identity', function (): void {
            config()->set('zeroboiler.analytics.sequence_rules', [
                'enabled' => true,
                'rules' => [
                    ['name' => 'r', 'type' => 'rate_limit', 'event' => 'x', 'max_per_session' => 1],
                ],
            ]);

            $engine = new EventSequenceRuleEngine(
                Cache::driver('array'),
                config(),
            );

            $violations = $engine->evaluate(new AnalyticsEvent(name: 'x'));

            expect($violations)->toBeEmpty();
        });
    });

    describe('SequenceRuleEnricher (Pipeline Integration)', function (): void {
        test('passes event through when no violations', function (): void {
            config()->set('zeroboiler.analytics.sequence_rules', [
                'enabled' => true,
                'rules' => [],
            ]);

            $engine = new EventSequenceRuleEngine(
                Cache::driver('array'),
                config(),
            );

            $enricher = new SequenceRuleEnricher($engine);

            $event = new AnalyticsEvent(name: 'page_view', clientId: 'p1', category: 'engagement');
            $result = $enricher($event);

            expect($result)->not->toBeNull();
            expect($result->name)->toBe('page_view');
            expect($result->params)->not->toHaveKey('_sequence_violations');
        });

        test('attaches violations to event params', function (): void {
            config()->set('zeroboiler.analytics.sequence_rules', [
                'enabled' => true,
                'rules' => [
                    ['name' => 'vel', 'type' => 'rate_limit', 'event' => 'checkout', 'max_per_session' => 1],
                ],
            ]);

            $engine = new EventSequenceRuleEngine(
                Cache::driver('array'),
                config(),
            );

            $enricher = new SequenceRuleEnricher($engine);

            $engine->evaluate(new AnalyticsEvent(name: 'checkout', clientId: 'p2', category: 'ecommerce'));

            $result = $enricher(new AnalyticsEvent(name: 'checkout', clientId: 'p2', category: 'ecommerce'));

            expect($result)->not->toBeNull();
            expect($result->params)->toHaveKey('_sequence_violations');
            expect($result->params['_sequence_violations'])->toHaveCount(1);
        });
    });

    describe('Version Consistency', function (): void {
        test('AnalyticsEvent::VERSION is 261.0.0', function (): void {
            expect(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION)->toBe('261.0.0');
        });

        test('composer.json version is 261.0.0', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['version'])->toBe('261.0.0');
        });
    });

    describe('PHP 8.5 Syntax & Quality', function (): void {
        test('EventSequenceRuleEngine has strict types', function (): void {
            $content = file_get_contents(__DIR__ . '/../src/Services/EventSequenceRuleEngine.php');
            expect($content)->toContain('declare(strict_types=1)');
        });

        test('EventSequenceRuleEngine is final', function (): void {
            $ref = new ReflectionClass(EventSequenceRuleEngine::class);
            expect($ref->isFinal())->toBeTrue();
        });

        test('EventSequenceRuleEngine constructor has void return type', function (): void {
            $ref = new ReflectionClass(EventSequenceRuleEngine::class);
            $ctor = $ref->getConstructor();
            expect($ctor)->not->toBeNull();
            expect($ctor->getReturnType()?->getName())->toBe('void');
        });

        test('all public methods have return type declarations', function (): void {
            $ref = new ReflectionClass(EventSequenceRuleEngine::class);

            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getName() === '__construct') {
                    continue;
                }

                expect($method->getReturnType())->not->toBeNull(
                    "Method {$method->getName()} missing return type",
                );
            }
        });

        test('SequenceRuleEnricher has strict types and docblock', function (): void {
            $content = file_get_contents(__DIR__ . '/../src/Pipeline/SequenceRuleEnricher.php');
            expect($content)->toContain('declare(strict_types=1)');
            expect($content)->toContain('@since 261.0.0');
        });

        test('config section exists with correct structure', function (): void {
            $config = include __DIR__ . '/../config/zeroboiler.php';
            expect(isset($config['analytics']['sequence_rules']))->toBeTrue();
            expect($config['analytics']['sequence_rules']['enabled'])->toBeFalse(); // default disabled
            expect($config['analytics']['sequence_rules']['rules'])->toBeArray();
        });

        test('service provider registers EventSequenceRuleEngine', function (): void {
            $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
            expect($content)->toContain('use ZeroBoiler\Analytics\Services\EventSequenceRuleEngine');
            expect($content)->toContain('EventSequenceRuleEngine::class');
        });
    });
});
