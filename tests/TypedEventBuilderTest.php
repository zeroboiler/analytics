<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Support\AnalyticsFake;
use ZeroBoiler\Analytics\Support\TypedEventBuilder;

describe('TypedEventBuilder', function (): void {
    describe('Construction & Catalog Awareness', function (): void {
        test('creates builder with event name', function (): void {
            $builder = new TypedEventBuilder('purchase');

            expect($builder->name())->toBe('purchase');
        });

        test('infers category from catalog for known events', function (): void {
            $builder = new TypedEventBuilder('purchase');

            expect($builder->getCategory())->toBe('ecommerce');
        });

        test('infers saas category from catalog', function (): void {
            $builder = new TypedEventBuilder('sign_up');

            expect($builder->getCategory())->toBe('saas');
        });

        test('infers engagement category from catalog', function (): void {
            $builder = new TypedEventBuilder('page_view');

            expect($builder->getCategory())->toBe('engagement');
        });

        test('returns null category for unknown events', function (): void {
            $builder = new TypedEventBuilder('custom_unknown_event');

            expect($builder->getCategory())->toBeNull();
        });

        test('reports catalog presence correctly', function (): void {
            $catalogEvent = new TypedEventBuilder('sign_up');
            $unknownEvent = new TypedEventBuilder('totally_made_up');

            expect($catalogEvent->isInCatalog())->toBeTrue();
            expect($unknownEvent->isInCatalog())->toBeFalse();
        });

        test('returns catalog entry for known events', function (): void {
            $builder = new TypedEventBuilder('purchase');
            $entry = $builder->catalogEntry();

            expect($entry)->not->toBeNull();
            expect($entry['name'])->toBe('purchase');
            expect($entry['ga4'])->toBe('purchase');
            expect($entry['category'])->toBe('ecommerce');
        });

        test('returns null catalog entry for unknown events', function (): void {
            $builder = new TypedEventBuilder('nonexistent_event');

            expect($builder->catalogEntry())->toBeNull();
        });

        test('warns on unknown events in strict mode', function (): void {
            $builder = new TypedEventBuilder('nonexistent_event', catalogStrict: true);

            expect($builder->getWarnings())->toContain("Event 'nonexistent_event' is not in the event catalog.");
        });

        test('does not warn on known events in strict mode', function (): void {
            $builder = new TypedEventBuilder('sign_up', catalogStrict: true);

            expect($builder->getWarnings())->toBe([]);
        });

        test('does not warn in non-strict mode', function (): void {
            $builder = new TypedEventBuilder('nonexistent_event', catalogStrict: false);

            expect($builder->getWarnings())->toBe([]);
        });
    });

    describe('Fluent Parameter API', function (): void {
        test('param() sets single parameter with fluent return', function (): void {
            $builder = new TypedEventBuilder('search');
            $result = $builder->param('search_term', 'laravel analytics');

            expect($result)->toBe($builder);
            expect($builder->getParams()['search_term'])->toBe('laravel analytics');
        });

        test('params() sets multiple parameters', function (): void {
            $builder = new TypedEventBuilder('sign_up')
                ->params([
                    'method' => 'github',
                    'plan' => 'pro',
                ]);

            expect($builder->getParams()['method'])->toBe('github');
            expect($builder->getParams()['plan'])->toBe('pro');
        });

        test('skips empty string values', function (): void {
            $builder = new TypedEventBuilder('sign_up')
                ->param('method', 'email')
                ->param('empty_field', '');

            expect($builder->getParams())->toHaveKey('method');
            expect($builder->getParams())->not->toHaveKey('empty_field');
        });

        test('later param() overwrites earlier value', function (): void {
            $builder = new TypedEventBuilder('search')
                ->param('search_term', 'initial')
                ->param('search_term', 'updated');

            expect($builder->getParams()['search_term'])->toBe('updated');
        });
    });

    describe('Type Coercion', function (): void {
        test('coerces transaction_id to string', function (): void {
            $builder = new TypedEventBuilder('purchase')
                ->param('transaction_id', 12345);

            expect($builder->getParams()['transaction_id'])->toBe('12345');
        });

        test('coerces value to float', function (): void {
            $builder = new TypedEventBuilder('purchase')
                ->param('value', '99.99');

            expect($builder->getParams()['value'])->toBe(99.99);
            expect($builder->getParams()['value'])->toBeFloat();
        });

        test('coerces quantity to int', function (): void {
            $builder = new TypedEventBuilder('add_to_cart')
                ->param('quantity', '3');

            expect($builder->getParams()['quantity'])->toBe(3);
            expect($builder->getParams()['quantity'])->toBeInt();
        });

        test('coerces boolean string to bool', function (): void {
            $builder = new TypedEventBuilder('form_submit')
                ->param('success', 'true');

            expect($builder->getParams()['success'])->toBeTrue();
        });

        test('coerces false string to bool', function (): void {
            $builder = new TypedEventBuilder('form_submit')
                ->param('success', 'false');

            expect($builder->getParams()['success'])->toBeFalse();
        });

        test('passes through null values unchanged', function (): void {
            $builder = new TypedEventBuilder('sign_up')
                ->param('plan', null);

            expect($builder->getParams()['plan'])->toBeNull();
        });

        test('passes through arrays unchanged', function (): void {
            $items = [['item_id' => 'SKU-1', 'price' => 10.0]];
            $builder = new TypedEventBuilder('purchase')
                ->param('items', $items);

            expect($builder->getParams()['items'])->toBe($items);
        });
    });

    describe('Identity Binding', function (): void {
        test('client() sets client ID', function (): void {
            $event = new TypedEventBuilder('page_view')
                ->client('abc-123')
                ->build();

            expect($event->clientId)->toBe('abc-123');
        });

        test('user() sets user ID from string', function (): void {
            $event = new TypedEventBuilder('login')
                ->user('42')
                ->build();

            expect($event->userId)->toBe('42');
        });

        test('user() sets user ID from int', function (): void {
            $event = new TypedEventBuilder('login')
                ->user(42)
                ->build();

            expect($event->userId)->toBe('42');
        });

        test('user() accepts null to clear', function (): void {
            $event = new TypedEventBuilder('page_view')
                ->user(null)
                ->build();

            expect($event->userId)->toBeNull();
        });

        test('session() sets session ID', function (): void {
            $event = new TypedEventBuilder('page_view')
                ->session('sess-xyz')
                ->build();

            expect($event->sessionId)->toBe('sess-xyz');
        });
    });

    describe('Metadata', function (): void {
        test('priority() clamps to 0-100 range', function (): void {
            $event = new TypedEventBuilder('click')
                ->priority(150)
                ->build();

            expect($event->priority)->toBe(100);
        });

        test('priority() clamps negative values to 0', function (): void {
            $event = new TypedEventBuilder('click')
                ->priority(-10)
                ->build();

            expect($event->priority)->toBe(0);
        });

        test('source() sets event source', function (): void {
            $event = new TypedEventBuilder('error')
                ->source('client')
                ->build();

            expect($event->source)->toBe('client');
        });

        test('default source is server', function (): void {
            $event = new TypedEventBuilder('click')->build();

            expect($event->source)->toBe('server');
        });

        test('category() overrides inferred category', function (): void {
            $builder = new TypedEventBuilder('purchase')
                ->category('custom_category');

            expect($builder->getCategory())->toBe('custom_category');
        });
    });

    describe('Build', function (): void {
        test('build() returns AnalyticsEvent with correct name', function (): void {
            $event = new TypedEventBuilder('sign_up')
                ->param('method', 'email')
                ->build();

            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('sign_up');
            expect($event->params['method'])->toBe('email');
        });

        test('build() includes all set params', function (): void {
            $event = new TypedEventBuilder('purchase')
                ->param('transaction_id', 'TXN-1')
                ->param('value', 49.99)
                ->param('currency', 'USD')
                ->client('cid-1')
                ->user('user-1')
                ->session('sess-1')
                ->priority(80)
                ->source('cron')
                ->build();

            expect($event->name)->toBe('purchase');
            expect($event->params['transaction_id'])->toBe('TXN-1');
            expect($event->params['value'])->toBe(49.99);
            expect($event->params['currency'])->toBe('USD');
            expect($event->clientId)->toBe('cid-1');
            expect($event->userId)->toBe('user-1');
            expect($event->sessionId)->toBe('sess-1');
            expect($event->priority)->toBe(80);
            expect($event->source)->toBe('cron');
        });

        test('build() infers category from catalog', function (): void {
            $event = new TypedEventBuilder('sign_up')->build();

            expect($event->category)->toBe('saas');
        });

        test('build() uses overridden category', function (): void {
            $event = (new TypedEventBuilder('sign_up'))->category('custom')->build();

            expect($event->category)->toBe('custom');
        });

        test('build() sets default priority to 50', function (): void {
            $event = new TypedEventBuilder('click')->build();

            expect($event->priority)->toBe(50);
        });
    });

    describe('Integration with AnalyticsFake', function (): void {
        test('typedEvent() returns builder via fake', function (): void {
            $fake = new AnalyticsFake;
            $builder = $fake->typedEvent('sign_up');

            expect($builder)->toBeInstanceOf(TypedEventBuilder::class);
            expect($builder->name())->toBe('sign_up');
        });

        test('typedCatalogEvent() returns builder with strict mode via fake', function (): void {
            $fake = new AnalyticsFake;
            $builder = $fake->typedCatalogEvent('sign_up');

            expect($builder)->toBeInstanceOf(TypedEventBuilder::class);
            expect($builder->name())->toBe('sign_up');
            expect($builder->getWarnings())->toBe([]);
        });

        test('typedCatalogEvent() warns on unknown events via fake', function (): void {
            $fake = new AnalyticsFake;
            $builder = $fake->typedCatalogEvent('unknown_xyz');

            expect($builder->getWarnings())->not->toBeEmpty();
        });
    });

    describe('Edge Cases', function (): void {
        test('builder with no params produces event with empty params', function (): void {
            $event = new TypedEventBuilder('page_view')->build();

            expect($event->params)->toBe([]);
        });

        test('param value of 0 is preserved for numeric keys', function (): void {
            $builder = new TypedEventBuilder('scroll_depth')
                ->param('value', 0);

            // 'value' is a float key, so 0 becomes 0.0
            expect($builder->getParams()['value'])->toBe(0.0);
        });

        test('param value of false is preserved for boolean keys', function (): void {
            $builder = new TypedEventBuilder('form_submit')
                ->param('success', false);

            expect($builder->getParams()['success'])->toBeFalse();
        });

        test('multiple builders are independent', function (): void {
            $a = new TypedEventBuilder('sign_up')->param('method', 'email');
            $b = new TypedEventBuilder('login')->param('method', 'github');

            expect($a->name())->toBe('sign_up');
            expect($a->getParams()['method'])->toBe('email');
            expect($b->name())->toBe('login');
            expect($b->getParams()['method'])->toBe('github');
        });
    });
});
