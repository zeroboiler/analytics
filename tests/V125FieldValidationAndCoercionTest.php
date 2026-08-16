<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventFieldCoercer;
use ZeroBoiler\Analytics\Services\EventFieldValidator;
use ZeroBoiler\Analytics\Pipeline\Validation\FieldValidationStage;

describe('Phase 125 — Event Field Validation & Coercion', function (): void {

    // ── EventFieldCoercer ────────────────────────────────────────────

    describe('EventFieldCoercer', function (): void {
        it('coerces string to int', function (): void {
            $coercer = new EventFieldCoercer;
            $result = $coercer->coerce('42', 'int');

            expect($result)->toBe(42);
            expect($coercer->coercionCount())->toBe(1);
        });

        it('coerces string to float', function (): void {
            $coercer = new EventFieldCoercer;
            $result = $coercer->coerce('3.14', 'float');

            expect($result)->toBe(3.14);
        });

        it('coerces string to bool (true variants)', function (): void {
            $coercer = new EventFieldCoercer;

            expect($coercer->coerce('true', 'bool'))->toBeTrue();
            expect($coercer->coerce('1', 'bool'))->toBeTrue();
            expect($coercer->coerce('yes', 'bool'))->toBeTrue();
            expect($coercer->coerce('on', 'bool'))->toBeTrue();
        });

        it('coerces string to bool (false variants)', function (): void {
            $coercer = new EventFieldCoercer;

            expect($coercer->coerce('false', 'bool'))->toBeFalse();
            expect($coercer->coerce('0', 'bool'))->toBeFalse();
            expect($coercer->coerce('no', 'bool'))->toBeFalse();
            expect($coercer->coerce('off', 'bool'))->toBeFalse();
            expect($coercer->coerce('', 'bool'))->toBeFalse();
        });

        it('coerces JSON string to array', function (): void {
            $coercer = new EventFieldCoercer;
            $result = $coercer->coerce('["a","b","c"]', 'array');

            expect($result)->toBe(['a', 'b', 'c']);
        });

        it('coerces comma-separated string to array', function (): void {
            $coercer = new EventFieldCoercer;
            $result = $coercer->coerce('foo,bar,baz', 'array');

            expect($result)->toBe(['foo', 'bar', 'baz']);
        });

        it('coerces string to numeric (int vs float)', function (): void {
            $coercer = new EventFieldCoercer;

            expect($coercer->coerce('42', 'numeric'))->toBe(42);
            expect($coercer->coerce('42.5', 'numeric'))->toBe(42.5);
        });

        it('preserves correct types without coercion', function (): void {
            $coercer = new EventFieldCoercer;

            expect($coercer->coerce(42, 'int'))->toBe(42);
            expect($coercer->coerce(3.14, 'float'))->toBe(3.14);
            expect($coercer->coerce(true, 'bool'))->toBeTrue();
            expect($coercer->coercionCount())->toBe(0);
        });

        it('coerces int to string', function (): void {
            $coercer = new EventFieldCoercer;
            $result = $coercer->coerce(42, 'string');

            expect($result)->toBe('42');
        });

        it('coerces bool to string', function (): void {
            $coercer = new EventFieldCoercer;

            expect($coercer->coerce(true, 'string'))->toBe('true');
            expect($coercer->coerce(false, 'string'))->toBe('false');
        });

        it('throws on invalid coercion in strict mode', function (): void {
            $coercer = new EventFieldCoercer(strict: true);

            expect(fn () => $coercer->coerce('not_a_number', 'int'))
                ->toThrow(InvalidArgumentException::class);
        });

        it('preserves original on invalid coercion in non-strict mode', function (): void {
            $coercer = new EventFieldCoercer(strict: false);
            $result = $coercer->coerce('not_a_number', 'int');

            expect($result)->toBe('not_a_number');
        });

        it('coerces params with field rules', function (): void {
            $coercer = new EventFieldCoercer;
            $params = [
                'price' => '29.99',
                'quantity' => '3',
                'active' => 'yes',
            ];
            $rules = [
                'price' => ['type' => 'float'],
                'quantity' => ['type' => 'int'],
                'active' => ['type' => 'bool'],
            ];

            $result = $coercer->coerceParams($params, $rules);

            expect($result['params']['price'])->toBe(29.99);
            expect($result['params']['quantity'])->toBe(3);
            expect($result['params']['active'])->toBeTrue();
            expect($result['coercions'])->toHaveCount(3);
        });

        it('respects coerce:false in rules', function (): void {
            $coercer = new EventFieldCoercer;
            $params = ['price' => '29.99'];
            $rules = ['price' => ['type' => 'float', 'coerce' => false]];

            $result = $coercer->coerceParams($params, $rules);

            expect($result['params']['price'])->toBe('29.99');
            expect($result['coercions'])->toHaveCount(0);
        });

        it('provides diagnostic summary', function (): void {
            $coercer = new EventFieldCoercer;
            $coercer->coerce('42', 'int');
            $coercer->coerce('true', 'bool');

            $summary = $coercer->summary();

            expect($summary['total_coercions'])->toBe(2);
            expect($summary['strict'])->toBeFalse();
            expect($summary['types'])->toHaveKey('string→int');
            expect($summary['types'])->toHaveKey('string→bool');
        });

        it('resets coercion log', function (): void {
            $coercer = new EventFieldCoercer;
            $coercer->coerce('42', 'int');
            expect($coercer->coercionCount())->toBe(1);

            $coercer->reset();
            expect($coercer->coercionCount())->toBe(0);
        });
    });

    // ── EventFieldValidator ──────────────────────────────────────────

    describe('EventFieldValidator', function (): void {
        it('validates required fields', function (): void {
            $validator = new EventFieldValidator(rules: [
                'purchase' => [
                    'transaction_id' => ['type' => 'string', 'required' => true],
                    'value' => ['type' => 'float', 'required' => true],
                ],
            ]);

            $result = $validator->validateRaw('purchase', ['transaction_id' => 'TXN-1']);

            expect($result['valid'])->toBeFalse();
            expect($result['errors'])->toHaveCount(1);
            expect($result['errors'][0]['field'])->toBe('value');
            expect($result['errors'][0]['rule'])->toBe('required');
        });

        it('passes when all required fields are present', function (): void {
            $validator = new EventFieldValidator(rules: [
                'purchase' => [
                    'transaction_id' => ['type' => 'string', 'required' => true],
                    'value' => ['type' => 'float', 'required' => true],
                ],
            ]);

            $result = $validator->validateRaw('purchase', [
                'transaction_id' => 'TXN-1',
                'value' => 99.99,
            ]);

            expect($result['valid'])->toBeTrue();
            expect($result['errors'])->toBeEmpty();
        });

        it('validates type constraints', function (): void {
            $validator = new EventFieldValidator(rules: [
                'purchase' => [
                    'value' => ['type' => 'float'],
                ],
            ]);

            $result = $validator->validateRaw('purchase', ['value' => 'not_a_number']);

            expect($result['valid'])->toBeFalse();
            expect($result['errors'][0]['rule'])->toBe('type');
        });

        it('validates min/max constraints', function (): void {
            $validator = new EventFieldValidator(rules: [
                'search' => [
                    'results_count' => ['type' => 'int', 'min' => 0, 'max' => 10000],
                ],
            ]);

            $tooHigh = $validator->validateRaw('search', ['results_count' => 50000]);
            expect($tooHigh['valid'])->toBeFalse();
            expect($tooHigh['errors'][0]['rule'])->toBe('max');

            $tooLow = $validator->validateRaw('search', ['results_count' => -1]);
            expect($tooLow['valid'])->toBeFalse();
            expect($tooLow['errors'][0]['rule'])->toBe('min');
        });

        it('validates enum constraints', function (): void {
            $validator = new EventFieldValidator(rules: [
                'sign_up' => [
                    'method' => ['type' => 'string', 'enum' => ['email', 'google', 'github']],
                ],
            ]);

            $valid = $validator->validateRaw('sign_up', ['method' => 'google']);
            expect($valid['valid'])->toBeTrue();

            $invalid = $validator->validateRaw('sign_up', ['method' => 'twitter']);
            expect($invalid['valid'])->toBeFalse();
            expect($invalid['errors'][0]['rule'])->toBe('enum');
        });

        it('validates format constraints', function (): void {
            $validator = new EventFieldValidator(rules: [
                'page_view' => [
                    'page_location' => ['type' => 'string', 'format' => 'url'],
                    'currency' => ['type' => 'string', 'format' => 'currency_code'],
                ],
            ]);

            $result = $validator->validateRaw('page_view', [
                'page_location' => 'not-a-url',
                'currency' => 'DOLLARS',
            ]);

            expect($result['valid'])->toBeFalse();
            expect($result['errors'])->toHaveCount(2);
        });

        it('accepts valid format values', function (): void {
            $validator = new EventFieldValidator(rules: [
                'purchase' => [
                    'currency' => ['type' => 'string', 'format' => 'currency_code'],
                ],
            ]);

            $result = $validator->validateRaw('purchase', ['currency' => 'USD']);
            expect($result['valid'])->toBeTrue();
        });

        it('validates uuid format', function (): void {
            $validator = new EventFieldValidator(rules: [
                'user_event' => [
                    'session_id' => ['type' => 'string', 'format' => 'uuid'],
                ],
            ]);

            $valid = $validator->validateRaw('user_event', ['session_id' => '550e8400-e29b-41d4-a716-446655440000']);
            expect($valid['valid'])->toBeTrue();

            $invalid = $validator->validateRaw('user_event', ['session_id' => 'not-a-uuid']);
            expect($invalid['valid'])->toBeFalse();
        });

        it('respects nullable fields', function (): void {
            $validator = new EventFieldValidator(rules: [
                'purchase' => [
                    'coupon' => ['type' => 'string', 'nullable' => true],
                ],
            ]);

            $result = $validator->validateRaw('purchase', ['coupon' => null]);
            expect($result['valid'])->toBeTrue();
        });

        it('applies default values for missing fields', function (): void {
            $validator = new EventFieldValidator(rules: [
                'purchase' => [
                    'currency' => ['type' => 'string', 'default' => 'USD'],
                ],
            ]);

            $result = $validator->validateRaw('purchase', []);
            expect($result['valid'])->toBeTrue();
            expect($result['coerced_params']['currency'])->toBe('USD');
        });

        it('coerces values before validation', function (): void {
            $validator = new EventFieldValidator(rules: [
                'purchase' => [
                    'value' => ['type' => 'float', 'required' => true, 'min' => 0],
                ],
            ]);

            $result = $validator->validateRaw('purchase', ['value' => '99.99']);

            expect($result['valid'])->toBeTrue();
            expect($result['coerced_params']['value'])->toBe(99.99);
            expect($result['coercions'])->toBeGreaterThan(0);
        });

        it('applies global rules to all events', function (): void {
            $validator = new EventFieldValidator(
                rules: [],
                globalRules: [
                    'session_id' => ['type' => 'string', 'format' => 'uuid'],
                ],
            );

            $result = $validator->validateRaw('any_event', ['session_id' => 'bad-uuid']);
            expect($result['valid'])->toBeFalse();
        });

        it('event-specific rules override global rules', function (): void {
            $validator = new EventFieldValidator(
                rules: [
                    'my_event' => [
                        'session_id' => ['type' => 'string'], // Override: no format check
                    ],
                ],
                globalRules: [
                    'session_id' => ['type' => 'string', 'format' => 'uuid'],
                ],
            );

            $result = $validator->validateRaw('my_event', ['session_id' => 'any-string']);
            expect($result['valid'])->toBeTrue();
        });

        it('supports wildcard patterns', function (): void {
            $validator = new EventFieldValidator(rules: [
                'saas_*' => [
                    'plan' => ['type' => 'string', 'required' => true],
                ],
            ]);

            $result = $validator->validateRaw('saas_login', []);
            expect($result['valid'])->toBeFalse();
            expect($result['errors'][0]['field'])->toBe('plan');

            $result2 = $validator->validateRaw('other_event', []);
            expect($result2['valid'])->toBeTrue();
        });

        it('returns disabled result when disabled', function (): void {
            $validator = new EventFieldValidator(enabled: false);
            $result = $validator->validateRaw('purchase', []);

            expect($result['valid'])->toBeTrue();
            expect($result['errors'])->toBeEmpty();
        });

        it('validates AnalyticsEvent objects', function (): void {
            $validator = new EventFieldValidator(rules: [
                'page_view' => [
                    'page_title' => ['type' => 'string', 'max' => 100],
                ],
            ]);

            $event = new AnalyticsEvent('page_view', ['page_title' => str_repeat('x', 200)]);
            $result = $validator->validate($event);

            expect($result['valid'])->toBeFalse();
            expect($result['errors'][0]['rule'])->toBe('max');
        });

        it('validates regex patterns', function (): void {
            $validator = new EventFieldValidator(rules: [
                'user_event' => [
                    'username' => ['type' => 'string', 'regex' => '/^[a-zA-Z0-9_]{3,20}$/'],
                ],
            ]);

            $valid = $validator->validateRaw('user_event', ['username' => 'john_doe']);
            expect($valid['valid'])->toBeTrue();

            $invalid = $validator->validateRaw('user_event', ['username' => 'ab']); // too short
            expect($invalid['valid'])->toBeFalse();
        });

        it('provides diagnostic summary', function (): void {
            $validator = new EventFieldValidator(rules: [
                'purchase' => [
                    'value' => ['type' => 'float', 'required' => true],
                ],
                'sign_up' => [
                    'method' => ['type' => 'string'],
                ],
            ]);

            $summary = $validator->diagnosticSummary();
            expect($summary['enabled'])->toBeTrue();
            expect($summary['event_count'])->toBe(2);
            expect($summary['events'])->toContain('purchase');
            expect($summary['events'])->toContain('sign_up');
        });

        it('saasPresetRules returns comprehensive rules', function (): void {
            $rules = EventFieldValidator::saasPresetRules();

            expect($rules)->toHaveKey('purchase');
            expect($rules)->toHaveKey('sign_up');
            expect($rules)->toHaveKey('page_view');
            expect($rules)->toHaveKey('add_to_cart');
            expect($rules)->toHaveKey('search');
            expect($rules)->toHaveKey('share');
            expect($rules)->toHaveKey('plan_upgrade');
            expect($rules)->toHaveKey('start_trial');

            // Purchase requires transaction_id, value, items
            $purchaseRules = $rules['purchase'];
            expect($purchaseRules['transaction_id']['required'])->toBeTrue();
            expect($purchaseRules['value']['required'])->toBeTrue();
            expect($purchaseRules['items']['required'])->toBeTrue();
            expect($purchaseRules['currency']['default'])->toBe('USD');
        });

        it('validates iso_date format', function (): void {
            $validator = new EventFieldValidator(rules: [
                'report' => [
                    'date' => ['type' => 'string', 'format' => 'iso_date'],
                ],
            ]);

            $valid = $validator->validateRaw('report', ['date' => '2026-08-14']);
            expect($valid['valid'])->toBeTrue();

            $invalid = $validator->validateRaw('report', ['date' => '14/08/2026']);
            expect($invalid['valid'])->toBeFalse();
        });

        it('validates iso_datetime format', function (): void {
            $validator = new EventFieldValidator(rules: [
                'event' => [
                    'occurred_at' => ['type' => 'string', 'format' => 'iso_datetime'],
                ],
            ]);

            $valid = $validator->validateRaw('event', ['occurred_at' => '2026-08-14T10:30:00']);
            expect($valid['valid'])->toBeTrue();

            $valid2 = $validator->validateRaw('event', ['occurred_at' => '2026-08-14 10:30:00']);
            expect($valid2['valid'])->toBeTrue();
        });
    });

    // ── FieldValidationStage ─────────────────────────────────────────

    describe('FieldValidationStage', function (): void {
        it('integrates with ValidationStageInterface', function (): void {
            $validator = new EventFieldValidator(rules: [
                'purchase' => [
                    'value' => ['type' => 'float', 'required' => true],
                ],
            ]);
            $stage = new FieldValidationStage($validator);

            expect($stage->name())->toBe('field_validation');
            expect($stage->priority())->toBe(15);
            expect($stage->enabled())->toBeTrue();
            expect($stage->description())->toBeString();
            expect($stage->validator())->toBe($validator);
        });

        it('passes valid events', function (): void {
            $validator = new EventFieldValidator(rules: [
                'purchase' => [
                    'value' => ['type' => 'float', 'required' => true],
                ],
            ]);
            $stage = new FieldValidationStage($validator);
            $event = new AnalyticsEvent('purchase', ['value' => 99.99]);

            $result = $stage->validate($event);

            expect($result['passed'])->toBeTrue();
            expect($result['errors'])->toBeEmpty();
            expect($result['metrics']['checked'])->toBe(1);
            expect($result['metrics']['failed'])->toBe(0);
        });

        it('fails invalid events with structured errors', function (): void {
            $validator = new EventFieldValidator(rules: [
                'purchase' => [
                    'value' => ['type' => 'float', 'required' => true],
                ],
            ]);
            $stage = new FieldValidationStage($validator);
            $event = new AnalyticsEvent('purchase', []);

            $result = $stage->validate($event);

            expect($result['passed'])->toBeFalse();
            expect($result['errors'])->toHaveCount(1);
            expect($result['errors'][0]['code'])->toBe('field.required');
            expect($result['errors'][0]['severity'])->toBe('error');
        });

        it('reports coercion count in metrics', function (): void {
            $validator = new EventFieldValidator(rules: [
                'purchase' => [
                    'value' => ['type' => 'float'],
                ],
            ]);
            $stage = new FieldValidationStage($validator);
            $event = new AnalyticsEvent('purchase', ['value' => '99.99']);

            $result = $stage->validate($event);

            expect($result['passed'])->toBeTrue();
            expect($result['metrics']['coerced'])->toBeGreaterThan(0);
            expect($result['metrics'])->toHaveKey('coerced_params');
        });

        it('skips when validator is disabled', function (): void {
            $validator = new EventFieldValidator(enabled: false);
            $stage = new FieldValidationStage($validator);
            $event = new AnalyticsEvent('purchase', []);

            $result = $stage->validate($event);

            expect($result['passed'])->toBeTrue();
            expect($result['metrics']['skipped'])->toBe(1);
        });
    });

    // ── Config-driven validation ────────────────────────────────────

    describe('fromConfig factory', function (): void {
        it('builds from config array', function (): void {
            $config = [
                'enabled' => true,
                'debug' => true,
                'rules' => [
                    'purchase' => [
                        'value' => ['type' => 'float', 'required' => true],
                    ],
                ],
                'global_rules' => [
                    'session_id' => ['type' => 'string', 'nullable' => true],
                ],
            ];

            $validator = EventFieldValidator::fromConfig($config);

            expect($validator->isEnabled())->toBeTrue();
            expect($validator->getEventRules('purchase'))->toHaveKey('value');
            expect($validator->getEventRules('any_event'))->toHaveKey('session_id');
        });
    });
});
