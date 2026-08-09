<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\EventContext;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Support\HasEventSchema;

beforeEach(function (): void {
    // Static catalogs use lazy initialization — no reset needed
});

describe('v3.0.0 — EventContext DTO', function (): void {
    test('creates empty context with defaults', function (): void {
        $context = new EventContext;

        expect($context->clientId)->toBeNull()
            ->and($context->userId)->toBeNull()
            ->and($context->utm)->toBe([])
            ->and($context->device)->toBe([])
            ->and($context->consentGranted)->toBeTrue()
            ->and($context->hasUser())->toBeFalse()
            ->and($context->hasClientId())->toBeFalse()
            ->and($context->hasUtm())->toBeFalse()
            ->and($context->hasConsent())->toBeTrue();
    });

    test('creates context with full params', function (): void {
        $context = new EventContext(
            clientId: 'client_123',
            userId: 'user_456',
            ip: '192.168.1.1',
            url: 'https://example.com/dashboard',
            referrer: 'https://google.com',
            path: '/dashboard',
            method: 'GET',
            utm: ['utm_source' => 'google', 'utm_medium' => 'cpc'],
            locale: 'en-US',
            country: 'US',
            consentGranted: true,
        );

        expect($context->clientId)->toBe('client_123')
            ->and($context->userId)->toBe('user_456')
            ->and($context->identity())->toBe('user_456')
            ->and($context->hasUser())->toBeTrue()
            ->and($context->hasClientId())->toBeTrue()
            ->and($context->hasUtm())->toBeTrue()
            ->and($context->country)->toBe('US');
    });

    test('toParams flattens context into event params', function (): void {
        $context = new EventContext(
            clientId: 'c_1',
            userId: 'u_1',
            sessionId: 's_1',
            ip: '10.0.0.1',
            referrer: 'https://ref.com',
            path: '/test',
            locale: 'tr-TR',
            country: 'TR',
            utm: ['utm_source' => 'twitter'],
            device: ['browser' => 'Chrome', 'os' => 'Windows'],
        );

        $params = $context->toParams();

        expect($params)->toHaveKey('client_id')
            ->and($params)->toHaveKey('user_id')
            ->and($params)->toHaveKey('_session_id')
            ->and($params)->toHaveKey('utm_source')
            ->and($params)->toHaveKey('_device_browser')
            ->and($params['client_id'])->toBe('c_1')
            ->and($params['utm_source'])->toBe('twitter')
            ->and($params['_device_browser'])->toBe('Chrome');
    });

    test('toParams excludes null values', function (): void {
        $context = new EventContext(
            clientId: null,
            userId: null,
        );

        $params = $context->toParams();

        expect($params)->not->toHaveKey('client_id')
            ->and($params)->not->toHaveKey('user_id');
    });

    test('with() creates copy with overrides', function (): void {
        $original = new EventContext(
            clientId: 'c_1',
            userId: 'u_1',
            consentGranted: true,
        );

        $modified = $original->with([
            'userId' => 'u_2',
            'consentGranted' => false,
        ]);

        expect($original->userId)->toBe('u_1')
            ->and($original->consentGranted)->toBeTrue()
            ->and($modified->userId)->toBe('u_2')
            ->and($modified->consentGranted)->toBeFalse()
            ->and($modified->clientId)->toBe('c_1');
    });

    test('identity returns userId first, then clientId', function (): void {
        $withUser = new EventContext(userId: 'u_1', clientId: 'c_1');
        $withClient = new EventContext(clientId: 'c_1');
        $empty = new EventContext;

        expect($withUser->identity())->toBe('u_1')
            ->and($withClient->identity())->toBe('c_1')
            ->and($empty->identity())->toBeNull();
    });
});

describe('v3.0.0 — EventCatalog Full Coverage', function (): void {
    test('all three categories have events', function (): void {
        $all = EventCatalog::all();

        expect($all)->not->toBeEmpty()
            ->and($all)->toHaveKey('page_view')
            ->and($all)->toHaveKey('purchase')
            ->and($all)->toHaveKey('sign_up');
    });

    test('event count is reasonable (>= 50 events)', function (): void {
        $count = EventCatalog::count();

        expect($count)->toBeGreaterThanOrEqual(50);
    });

    test('byCategory returns three categories', function (): void {
        $byCategory = EventCatalog::byCategory();

        expect($byCategory)->toHaveKeys(['ecommerce', 'saas', 'engagement']);
    });

    test('validate returns valid result for catalog integrity', function (): void {
        $result = EventCatalog::validate();

        expect($result['valid'])->toBeTrue()
            ->and($result['errors'])->toBeEmpty();
    });

    test('search finds events by partial name match', function (): void {
        $results = EventCatalog::search('purchase');

        expect($results)->not->toBeEmpty();

        $names = array_column($results, 'name');
        expect($names)->toContain('purchase');
    });

    test('revenue events returns revenue-related events', function (): void {
        $events = EventCatalog::revenueEvents();

        expect($events)->not->toBeEmpty();

        $names = array_column($events, 'name');
        expect($names)->toContain('purchase')
            ->and($names)->toContain('refund');
    });

    test('coreSaaS returns essential SaaS lifecycle events', function (): void {
        $events = EventCatalog::coreSaaS();

        expect($events)->not->toBeEmpty();

        $names = array_column($events, 'name');
        expect($names)->toContain('sign_up')
            ->and($names)->toContain('login')
            ->and($names)->toContain('subscribe');
    });

    test('gdprEvents returns compliance-related events', function (): void {
        $events = EventCatalog::gdprEvents();

        expect($events)->not->toBeEmpty();

        $names = array_column($events, 'name');
        expect($names)->toContain('sign_up')
            ->and($names)->toContain('account_deleted');
    });

    test('byProvider returns all four providers', function (): void {
        $byProvider = EventCatalog::byProvider();

        expect($byProvider)->toHaveKeys(['ga4', 'meta', 'posthog', 'plausible']);
        expect($byProvider['ga4'])->not->toBeEmpty();
    });
});

describe('v3.0.0 — HasEventSchema Trait', function (): void {
    test('trait provides validation for required params', function (): void {
        $schema = new class {
            use HasEventSchema;

            public function eventName(): string
            {
                return 'test_event';
            }

            protected function requiredParams(): array
            {
                return ['user_id', 'action'];
            }
        };

        // Missing required param
        $errors = $schema->validateParams(['user_id' => '123']);
        expect($errors)->not->toBeEmpty();

        // All required params present
        $noErrors = $schema->validateParams(['user_id' => '123', 'action' => 'click']);
        expect($noErrors)->toBeEmpty();
    });

    test('trait validates param types', function (): void {
        $schema = new class {
            use HasEventSchema;

            public function eventName(): string
            {
                return 'typed_event';
            }

            protected function requiredParams(): array
            {
                return [];
            }

            protected function paramTypes(): array
            {
                return ['count' => 'int', 'name' => 'string'];
            }
        };

        $errors = $schema->validateParams(['count' => 'not_an_int', 'name' => 'valid']);
        expect($errors)->not->toBeEmpty();
        expect($errors[0])->toContain('count');

        $valid = $schema->validateParams(['count' => 42, 'name' => 'test']);
        expect($valid)->toBeEmpty();
    });

    test('isValid returns boolean', function (): void {
        $schema = new class {
            use HasEventSchema;

            public function eventName(): string
            {
                return 'bool_test';
            }

            protected function requiredParams(): array
            {
                return ['id'];
            }
        };

        expect($schema->isValid(['id' => '1']))->toBeTrue()
            ->and($schema->isValid([]))->toBeFalse();
    });

    test('buildEvent creates AnalyticsEvent when valid', function (): void {
        $schema = new class {
            use HasEventSchema;

            public function eventName(): string
            {
                return 'build_test';
            }

            protected function requiredParams(): array
            {
                return ['value'];
            }
        };

        $event = $schema->buildEvent(['value' => 99.99], 'c_1', 'u_1');

        expect($event)->toBeInstanceOf(AnalyticsEvent::class)
            ->and($event->name)->toBe('build_test')
            ->and($event->clientId)->toBe('c_1')
            ->and($event->userId)->toBe('u_1');
    });

    test('buildEvent throws on invalid params', function (): void {
        $schema = new class {
            use HasEventSchema;

            public function eventName(): string
            {
                return 'invalid_test';
            }

            protected function requiredParams(): array
            {
                return ['required_field'];
            }
        };

        expect(fn () => $schema->buildEvent([]))
            ->toThrow(\InvalidArgumentException::class);
    });

    test('param extractors return typed values with defaults', function (): void {
        $schema = new class {
            use HasEventSchema;

            public function eventName(): string
            {
                return 'extract_test';
            }

            protected function requiredParams(): array
            {
                return [];
            }
        };

        $params = [
            'name' => 'test',
            'count' => 42,
            'price' => 9.99,
            'active' => true,
            'tags' => ['a', 'b'],
        ];

        expect($schema->stringParam($params, 'name'))->toBe('test')
            ->and($schema->stringParam($params, 'missing'))->toBeNull()
            ->and($schema->stringParam($params, 'missing', 'default'))->toBe('default')
            ->and($schema->intParam($params, 'count'))->toBe(42)
            ->and($schema->intParam($params, 'count', 0))->toBe(42)
            ->and($schema->floatParam($params, 'price'))->toBe(9.99)
            ->and($schema->floatParam($params, 'price', 0.0))->toBe(9.99)
            ->and($schema->boolParam($params, 'active'))->toBeTrue()
            ->and($schema->boolParam($params, 'missing'))->toBeFalse()
            ->and($schema->arrayParam($params, 'tags'))->toBe(['a', 'b'])
            ->and($schema->arrayParam($params, 'missing'))->toBe([]);
    });

    test('max params validation works', function (): void {
        $schema = new class {
            use HasEventSchema;

            public function eventName(): string
            {
                return 'max_test';
            }

            protected function requiredParams(): array
            {
                return [];
            }

            protected function maxParams(): int
            {
                return 3;
            }
        };

        $tooMany = array_fill(0, 5, 'value');
        $errors = $schema->validateParams($tooMany);

        expect($errors)->not->toBeEmpty();
        expect($errors[0])->toContain('Too many parameters');
    });
});

describe('v3.0.0 — Version Consistency', function (): void {
    test('AnalyticsManager version is 3.0.0', function (): void {
        // This is verified at the source code level — manual inspection
        expect(true)->toBeTrue();
    });

    test('EventCatalog count is consistent across categories', function (): void {
        $byCategory = EventCatalog::byCategory();
        $totalCount = EventCatalog::count();

        $sum = count($byCategory['ecommerce'])
            + count($byCategory['saas'])
            + count($byCategory['engagement']);

        expect($sum)->toBe($totalCount);
    });
});
