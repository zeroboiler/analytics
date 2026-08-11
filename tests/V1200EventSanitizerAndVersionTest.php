<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\AnalyticsEventSanitizer;

beforeEach(function (): void {
    // Config mock with sanitization enabled
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.sanitization', [])
        ->andReturn([
            'enabled' => true,
            'max_param_count' => 50,
            'max_key_length' => 80,
            'max_value_length' => 200,
            'strict_naming' => true,
            'strip_html' => true,
            'strip_null_bytes' => true,
            'normalize_booleans' => true,
            'truncate_strings' => true,
            'disallowed_keys' => ['password', 'token', 'secret', 'api_key'],
            'max_event_name_length' => 100,
            'reserved_prefixes' => ['_zb_', '_ga_'],
        ]);

    $this->sanitizer = new AnalyticsEventSanitizer($config);
});

describe('AnalyticsEventSanitizer', function (): void {
    test('sanitizer is enabled when config says so', function (): void {
        expect($this->sanitizer->isEnabled())->toBeTrue();
    });

    test('sanitizeEventName strips HTML', function (): void {
        $result = $this->sanitizer->sanitizeEventName('<b>purchase</b>');
        expect($result)->toBe('purchase');
    });

    test('sanitizeEventName strips null bytes', function (): void {
        $result = $this->sanitizer->sanitizeEventName("page\0_view");
        expect($result)->toBe('page_view');
    });

    test('sanitizeEventName converts to snake_case in strict mode', function (): void {
        $result = $this->sanitizer->sanitizeEventName('Purchase Completed');
        expect($result)->toBe('purchase_completed');
    });

    test('sanitizeEventName truncates long names', function (): void {
        $longName = str_repeat('a', 150);
        $result = $this->sanitizer->sanitizeEventName($longName);
        expect(strlen($result))->toBe(100);
    });

    test('sanitizeParam blocks disallowed keys', function (): void {
        $result = $this->sanitizer->sanitizeParam('user_password', 'secret123');
        expect($result['allowed'])->toBeFalse();
    });

    test('sanitizeParam blocks api_key variant', function (): void {
        $result = $this->sanitizer->sanitizeParam('my_api_key', 'abc123');
        expect($result['allowed'])->toBeFalse();
    });

    test('sanitizeParam allows safe keys', function (): void {
        $result = $this->sanitizer->sanitizeParam('page_title', 'Home');
        expect($result['allowed'])->toBeTrue();
        expect($result['value'])->toBe('Home');
    });

    test('sanitizeParam strips HTML from values', function (): void {
        $result = $this->sanitizer->sanitizeParam('title', '<script>alert(1)</script>Hello');
        expect($result['value'])->toBe('alert(1)Hello');
    });

    test('sanitizeParam truncates long string values', function (): void {
        $longValue = str_repeat('x', 300);
        $result = $this->sanitizer->sanitizeParam('description', $longValue);
        expect(strlen($result['value']))->toBe(200);
    });

    test('sanitizeParam preserves integers', function (): void {
        $result = $this->sanitizer->sanitizeParam('count', 42);
        expect($result['value'])->toBe(42);
    });

    test('sanitizeParam preserves floats', function (): void {
        $result = $this->sanitizer->sanitizeParam('price', 29.99);
        expect($result['value'])->toBe(29.99);
    });

    test('sanitizeParam preserves booleans', function (): void {
        $result = $this->sanitizer->sanitizeParam('is_active', true);
        expect($result['value'])->toBeTrue();
    });

    test('sanitizeParam recursively sanitizes arrays', function (): void {
        $nested = [
            'safe_key' => 'value',
            'user_token' => 'should_be_removed',
        ];
        $result = $this->sanitizer->sanitizeValue($nested);

        expect($result)->toBeArray();
        expect(isset($result['safe_key']))->toBeTrue();
        expect(isset($result['user_token']))->toBeFalse();
    });

    test('sanitizeParam sanitizes keys', function (): void {
        $result = $this->sanitizer->sanitizeKey('<b>bold</b> key');
        expect($result)->not->toContain('<');
    });

    test('sanitize preserves all event properties', function (): void {
        $event = new AnalyticsEvent(
            name: 'test_event',
            params: ['key' => 'value'],
            clientId: 'cli-123',
            userId: 'user-456',
            priority: 'critical',
            source: 'api',
        );

        $sanitized = $this->sanitizer->sanitize($event);

        expect($sanitized->name)->toBe('test_event');
        expect($sanitized->clientId)->toBe('cli-123');
        expect($sanitized->userId)->toBe('user-456');
        expect($sanitized->priority)->toBe('critical');
        expect($sanitized->source)->toBe('api');
    });

    test('sanitize removes disallowed params', function (): void {
        $event = new AnalyticsEvent(
            name: 'form_submit',
            params: [
                'form_name' => 'contact',
                'user_password' => 'secret123',
                'email' => 'test@example.com',
            ],
        );

        $sanitized = $this->sanitizer->sanitize($event);

        expect(isset($sanitized->params['form_name']))->toBeTrue();
        expect(isset($sanitized->params['email']))->toBeTrue();
        expect(isset($sanitized->params['user_password']))->toBeFalse();
        expect($this->sanitizer->hasErrors())->toBeTrue();
    });

    test('validate returns valid for clean events', function (): void {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 29.99, 'currency' => 'USD'],
        );

        $result = $this->sanitizer->validate($event);

        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
    });

    test('validate detects empty event name', function (): void {
        $event = new AnalyticsEvent(name: '', params: []);

        $result = $this->sanitizer->validate($event);

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->toContain('Event name is empty');
    });

    test('validate warns about non-snake-case names in strict mode', function (): void {
        $event = new AnalyticsEvent(name: 'My Custom Event', params: []);

        $result = $this->sanitizer->validate($event);

        expect($result['valid'])->toBeTrue();
        expect($result['warnings'])->toContain("Event name 'My Custom Event' is not snake_case");
    });

    test('validate detects disallowed params', function (): void {
        $event = new AnalyticsEvent(
            name: 'login',
            params: ['password' => 'secret'],
        );

        $result = $this->sanitizer->validate($event);

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->toHaveCount(1);
    });

    test('validate warns about reserved prefixes', function (): void {
        $event = new AnalyticsEvent(
            name: 'custom',
            params: ['_zb_internal' => 'value'],
        );

        $result = $this->sanitizer->validate($event);

        expect($result['valid'])->toBeTrue();
        expect($result['warnings'])->not->toBeEmpty();
    });

    test('getConfig returns full configuration', function (): void {
        $config = $this->sanitizer->getConfig();

        expect($config)->toHaveKey('enabled');
        expect($config)->toHaveKey('max_param_count');
        expect($config)->toHaveKey('max_key_length');
        expect($config)->toHaveKey('max_value_length');
        expect($config)->toHaveKey('strict_naming');
        expect($config)->toHaveKey('strip_html');
        expect($config)->toHaveKey('strip_null_bytes');
        expect($config)->toHaveKey('normalize_booleans');
        expect($config)->toHaveKey('truncate_strings');
        expect($config)->toHaveKey('disallowed_keys');
        expect($config)->toHaveKey('max_event_name_length');
        expect($config)->toHaveKey('reserved_prefixes');
    });

    test('getErrors returns empty when no sanitization occurred', function (): void {
        expect($this->sanitizer->getErrors())->toBeEmpty();
        expect($this->sanitizer->hasErrors())->toBeFalse();
    });

    test('sanitize handles null values correctly', function (): void {
        $result = $this->sanitizer->sanitizeValue(null);
        expect($result)->toBeNull();
    });

    test('sanitize converts unknown types to string', function (): void {
        $obj = new class {
            public function __toString(): string
            {
                return 'custom_object';
            }
        };

        $result = $this->sanitizer->sanitizeValue($obj);
        expect($result)->toBe('custom_object');
    });
});

describe('v12.0.0 Version Consistency', function (): void {
    test('PHP version is 12.0.0', function (): void {
        expect(AnalyticsEvent::VERSION)->toBe('12.0.0');
    });

    test('event catalog is valid', function (): void {
        $result = EventCatalog::validate();
        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
    });

    test('event catalog has all 5 categories', function (): void {
        $byCategory = EventCatalog::byCategory();
        expect(array_keys($byCategory))->toHaveCount(5);
        expect($byCategory)->toHaveKey('ecommerce');
        expect($byCategory)->toHaveKey('saas');
        expect($byCategory)->toHaveKey('engagement');
        expect($byCategory)->toHaveKey('security');
        expect($byCategory)->toHaveKey('uptime');
    });

    test('critical SaaS events exist', function (): void {
        $critical = ['sign_up', 'login', 'start_trial', 'subscription', 'cancellation', 'page_view', 'purchase'];
        foreach ($critical as $name) {
            expect(EventCatalog::has($name))->toBeTrue("Missing critical event: {$name}");
        }
    });

    test('catalog has 100+ events', function (): void {
        expect(EventCatalog::count())->toBeGreaterThan(100);
    });
});
