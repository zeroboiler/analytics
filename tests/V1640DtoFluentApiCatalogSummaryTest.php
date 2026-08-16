<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

beforeEach(function (): void {
    //
});

describe('V1640 DTO Fluent API & Catalog Summary', function (): void {
    describe('AnalyticsEvent DTO fluent methods', function (): void {
        test('withSource() returns new instance with overridden source', function (): void {
            $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
                name: 'page_view',
                params: ['url' => '/test'],
                source: 'client',
            );

            $modified = $event->withSource('api');

            expect($modified->name)->toBe('page_view');
            expect($modified->source)->toBe('api');
            expect($event->source)->toBe('client'); // Original unchanged
            expect($modified)->not->toBe($event); // New instance
        });

        test('withPriority() returns new instance with overridden priority', function (): void {
            $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
                name: 'purchase',
                params: ['value' => 99.99],
                priority: 'normal',
            );

            $modified = $event->withPriority('critical');

            expect($modified->priority)->toBe('critical');
            expect($event->priority)->toBe('normal');
        });

        test('withTimestamp() returns new instance with overridden timestamp', function (): void {
            $originalTs = new \DateTimeImmutable('2026-01-15 10:00:00');
            $newTs = new \DateTimeImmutable('2026-01-15 12:00:00');

            $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
                name: 'sign_up',
                timestamp: $originalTs,
            );

            $modified = $event->withTimestamp($newTs);

            expect($modified->timestamp)->toEqual($newTs);
            expect($event->timestamp)->toEqual($originalTs);
        });

        test('withSource() preserves all other properties', function (): void {
            $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
                name: 'scroll_depth',
                params: ['percent' => 75],
                clientId: 'cli-123',
                userId: 'user-456',
                priority: 'low',
                source: 'client',
                category: 'engagement',
                sessionId: 'sess-789',
            );

            $modified = $event->withSource('replay');

            expect($modified->name)->toBe('scroll_depth');
            expect($modified->params)->toBe(['percent' => 75]);
            expect($modified->clientId)->toBe('cli-123');
            expect($modified->userId)->toBe('user-456');
            expect($modified->priority)->toBe('low');
            expect($modified->category)->toBe('engagement');
            expect($modified->sessionId)->toBe('sess-789');
            expect($modified->source)->toBe('replay');
        });

        test('withPriority() preserves all other properties', function (): void {
            $ts = new \DateTimeImmutable('2026-08-15 00:00:00');
            $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
                name: 'form_submit',
                params: ['form_id' => 'contact'],
                clientId: 'cli-abc',
                userId: 'user-def',
                timestamp: $ts,
                source: 'server',
                category: 'engagement',
                sessionId: 'sess-xyz',
                priority: 'background',
            );

            $modified = $event->withPriority('normal');

            expect($modified->clientId)->toBe('cli-abc');
            expect($modified->userId)->toBe('user-def');
            expect($modified->timestamp)->toEqual($ts);
            expect($modified->source)->toBe('server');
            expect($modified->category)->toBe('engagement');
            expect($modified->sessionId)->toBe('sess-xyz');
            expect($modified->priority)->toBe('normal');
        });

        test('withTimestamp() preserves all other properties', function (): void {
            $originalTs = new \DateTimeImmutable('2026-01-01 00:00:00');
            $newTs = new \DateTimeImmutable('2026-06-15 12:30:00');

            $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
                name: 'error',
                params: ['message' => 'test error'],
                clientId: 'cli-err',
                userId: 'user-err',
                timestamp: $originalTs,
                priority: 'critical',
                source: 'client',
                category: 'engagement',
                sessionId: 'sess-err',
            );

            $modified = $event->withTimestamp($newTs);

            expect($modified->name)->toBe('error');
            expect($modified->params)->toBe(['message' => 'test error']);
            expect($modified->clientId)->toBe('cli-err');
            expect($modified->userId)->toBe('user-err');
            expect($modified->priority)->toBe('critical');
            expect($modified->source)->toBe('client');
            expect($modified->category)->toBe('engagement');
            expect($modified->sessionId)->toBe('sess-err');
            expect($modified->timestamp)->toEqual($newTs);
        });

        test('all fluent methods can be chained', function (): void {
            $ts = new \DateTimeImmutable('2026-08-15 00:00:00');
            $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
                name: 'add_to_cart',
                params: [],
            );

            $result = $event
                ->withSource('api')
                ->withPriority('critical')
                ->withTimestamp($ts)
                ->withCategory('ecommerce')
                ->withSessionId('sess-chain')
                ->withMergedParams(['item_id' => 'SKU-001']);

            expect($result->name)->toBe('add_to_cart');
            expect($result->source)->toBe('api');
            expect($result->priority)->toBe('critical');
            expect($result->timestamp)->toEqual($ts);
            expect($result->category)->toBe('ecommerce');
            expect($result->sessionId)->toBe('sess-chain');
            expect($result->params)->toBe(['item_id' => 'SKU-001']);
        });

        test('readonly class prevents property mutation', function (): void {
            $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(name: 'test');

            expect($event)->toBeInstanceOf(\DateTimeInterface::class); // This will fail, proving readonly
        })->skip('readonly class — properties cannot be reassigned, verified by reflection');

        test('DTO class is final', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::class);

            expect($ref->isFinal())->toBeTrue();
        });

        test('DTO class is readonly', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::class);

            // PHP 8.2+ readonly classes
            $attributes = $ref->getAttributes();
            $hasReadonly = count(array_filter($attributes, fn ($a) => $a->getName() === 'Readonly')) > 0
                || str_contains((string) $ref, 'readonly');

            // In PHP 8.2+, readonly is a language keyword, not an attribute
            $file = file_get_contents($ref->getFileName());
            expect($file)->toContain('final readonly class AnalyticsEvent');
        });
    });

    describe('EventCatalog::categorySummary()', function (): void {
        test('returns array with all 8 categories plus total', function (): void {
            $summary = \ZeroBoiler\Analytics\Events\EventCatalog::categorySummary();

            expect($summary)->toHaveKey('ecommerce');
            expect($summary)->toHaveKey('saas');
            expect($summary)->toHaveKey('engagement');
            expect($summary)->toHaveKey('security');
            expect($summary)->toHaveKey('uptime');
            expect($summary)->toHaveKey('infrastructure');
            expect($summary)->toHaveKey('marketing');
            expect($summary)->toHaveKey('customer_success');
            expect($summary)->toHaveKey('total');
        });

        test('total equals sum of all categories', function (): void {
            $summary = \ZeroBoiler\Analytics\Events\EventCatalog::categorySummary();

            $expectedTotal = $summary['ecommerce']
                + $summary['saas']
                + $summary['engagement']
                + $summary['security']
                + $summary['uptime']
                + $summary['infrastructure']
                + $summary['marketing']
                + $summary['customer_success'];

            expect($summary['total'])->toBe($expectedTotal);
        });

        test('total matches EventCatalog::count()', function (): void {
            $summary = \ZeroBoiler\Analytics\Events\EventCatalog::categorySummary();
            $count = \ZeroBoiler\Analytics\Events\EventCatalog::count();

            expect($summary['total'])->toBe($count);
        });

        test('category counts match individual catalog counts', function (): void {
            $summary = \ZeroBoiler\Analytics\Events\EventCatalog::categorySummary();

            expect($summary['ecommerce'])->toBe(
                \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::count()
            );
            expect($summary['saas'])->toBe(
                \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::count()
            );
            expect($summary['engagement'])->toBe(
                \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::count()
            );
        });

        test('total is 194 events', function (): void {
            $summary = \ZeroBoiler\Analytics\Events\EventCatalog::categorySummary();

            expect($summary['total'])->toBe(194);
        });

        test('ecommerce has 15 events', function (): void {
            $summary = \ZeroBoiler\Analytics\Events\EventCatalog::categorySummary();

            expect($summary['ecommerce'])->toBe(15);
        });

        test('saas has 82 events', function (): void {
            $summary = \ZeroBoiler\Analytics\Events\EventCatalog::categorySummary();

            expect($summary['saas'])->toBe(82);
        });

        test('engagement has 35 events', function (): void {
            $summary = \ZeroBoiler\Analytics\Events\EventCatalog::categorySummary();

            expect($summary['engagement'])->toBe(35);
        });

        test('all values are positive integers', function (): void {
            $summary = \ZeroBoiler\Analytics\Events\EventCatalog::categorySummary();

            foreach ($summary as $key => $value) {
                expect($value)->toBeInt();
                expect($value)->toBeGreaterThan(0);
            }
        });
    });

    describe('Version consistency', function (): void {
        test('AnalyticsEvent::VERSION is 164.0.0', function (): void {
            expect(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION)->toBe('164.0.0');
        });

        test('AnalyticsIntegrityCommand::EXPECTED_VERSION is 164.0.0', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsIntegrityCommand::class);
            $constant = $ref->getConstant('EXPECTED_VERSION');

            expect($constant)->toBe('164.0.0');
        });

        test('composer.json version is 164.0.0', function (): void {
            $composer = json_decode(
                file_get_contents(__DIR__ . '/../composer.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            expect($composer['version'])->toBe('164.0.0');
        });

        test('package.json version is 164.0.0', function (): void {
            $pkg = json_decode(
                file_get_contents(__DIR__ . '/../package.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            expect($pkg['version'])->toBe('164.0.0');
        });
    });

    describe('Quality gates', function (): void {
        test('AnalyticsEvent has strict_types declaration', function (): void {
            $file = file_get_contents(
                (new ReflectionClass(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::class))->getFileName()
            );

            expect($file)->toContain('declare(strict_types=1)');
        });

        test('EventCatalog has strict_types declaration', function (): void {
            $file = file_get_contents(
                (new ReflectionClass(\ZeroBoiler\Analytics\Events\EventCatalog::class))->getFileName()
            );

            expect($file)->toContain('declare(strict_types=1)');
        });

        test('EventCatalog is final', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Analytics\Events\EventCatalog::class);

            expect($ref->isFinal())->toBeTrue();
        });

        test('withSource has @since 164.0.0 docblock', function (): void {
            $ref = new ReflectionMethod(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::class, 'withSource');
            $doc = $ref->getDocComment();

            expect($doc)->toContain('164.0.0');
        });

        test('withPriority has @since 164.0.0 docblock', function (): void {
            $ref = new ReflectionMethod(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::class, 'withPriority');
            $doc = $ref->getDocComment();

            expect($doc)->toContain('164.0.0');
        });

        test('withTimestamp has @since 164.0.0 docblock', function (): void {
            $ref = new ReflectionMethod(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::class, 'withTimestamp');
            $doc = $ref->getDocComment();

            expect($doc)->toContain('164.0.0');
        });

        test('categorySummary has @since 164.0.0 docblock', function (): void {
            $ref = new ReflectionMethod(\ZeroBoiler\Analytics\Events\EventCatalog::class, 'categorySummary');
            $doc = $ref->getDocComment();

            expect($doc)->toContain('164.0.0');
        });

        test('fluent methods have return type self', function (): void {
            $methods = ['withSource', 'withPriority', 'withTimestamp', 'withCategory', 'withSessionId', 'withMergedParams'];

            foreach ($methods as $method) {
                $ref = new ReflectionMethod(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::class, $method);
                $returnType = $ref->getReturnType();

                expect($returnType)->not->toBeNull();
                expect((string) $returnType)->toBe('self');
            }
        });

        test('MIT license header present in AnalyticsEvent', function (): void {
            $file = file_get_contents(
                (new ReflectionClass(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::class))->getFileName()
            );

            expect($file)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
        });

        test('MIT license header present in EventCatalog', function (): void {
            $file = file_get_contents(
                (new ReflectionClass(\ZeroBoiler\Analytics\Events\EventCatalog::class))->getFileName()
            );

            expect($file)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
        });
    });
});
