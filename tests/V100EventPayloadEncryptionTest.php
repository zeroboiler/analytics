<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsEncryptionCommand;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Middleware\EventPayloadEncryptionMiddleware;
use ZeroBoiler\Analytics\Services\EventPayloadEncryptionService;

describe('EventPayloadEncryptionService', function () {
    beforeEach(function (): void {
        $this->config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.encryption', [])
            ->andReturn([
                'enabled' => true,
                'prefix' => 'enc:v1:',
                'global_fields' => ['email', 'phone', 'ip_address', 'user_*'],
                'event_rules' => [
                    'purchase' => ['credit_card', 'billing_address'],
                    'sign_up' => ['except:ip_address'],
                ],
            ]);
    });

    describe('construction and config', function () {
        it('initializes with correct config values', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            expect($service->isEnabled())->toBeTrue();
            expect($service->getPrefix())->toBe('enc:v1:');
        });

        it('is disabled when config enabled is false', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.encryption', [])
                ->andReturn(['enabled' => false]);

            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            expect($service->isEnabled())->toBeFalse();
        });

        it('uses default prefix when not configured', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.encryption', [])
                ->andReturn([
                    'enabled' => true,
                    'global_fields' => [],
                    'event_rules' => [],
                ]);

            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            expect($service->getPrefix())->toBe('enc:v1:');
        });
    });

    describe('encryptValue', function () {
        it('encrypts a string value', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $result = $service->encryptValue('user@example.com');

            expect($result)->toBeString();
            expect(str_starts_with($result, 'enc:v1:'))->toBeTrue();
            expect($result)->not->toBe('user@example.com');
        });

        it('encrypts an array value', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $result = $service->encryptValue(['street' => '123 Main St', 'city' => 'NYC']);

            expect($result)->toBeString();
            expect(str_starts_with($result, 'enc:v1:'))->toBeTrue();
        });

        it('encrypts a numeric value', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $result = $service->encryptValue(12345);

            expect($result)->toBeString();
            expect(str_starts_with($result, 'enc:v1:'))->toBeTrue();
        });

        it('encrypts a boolean value', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $result = $service->encryptValue(true);

            expect($result)->toBeString();
            expect(str_starts_with($result, 'enc:v1:'))->toBeTrue();
        });

        it('returns null unchanged', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            expect($service->encryptValue(null))->toBeNull();
        });

        it('returns value unchanged when encryption is disabled', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.encryption', [])
                ->andReturn(['enabled' => false]);

            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            expect($service->encryptValue('secret'))->toBe('secret');
        });

        it('hashes oversized values instead of encrypting', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $hugeValue = str_repeat('x', 5000);
            $result = $service->encryptValue($hugeValue);

            // Should be a SHA-256 hash (64 chars), not encrypted
            expect($result)->toBeString();
            expect(strlen($result))->toBe(64);
            expect(str_starts_with($result, 'enc:v1:'))->toBeFalse();
        });

        it('produces deterministic prefixes', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $r1 = $service->encryptValue('test@example.com');
            $r2 = $service->encryptValue('different@example.com');

            // Both should have the prefix but different ciphertexts
            expect(str_starts_with($r1, 'enc:v1:'))->toBeTrue();
            expect(str_starts_with($r2, 'enc:v1:'))->toBeTrue();
            expect($r1)->not->toBe($r2);
        });
    });

    describe('decryptValue', function () {
        it('decrypts an encrypted string back to original', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $original = 'user@example.com';
            $encrypted = $service->encryptValue($original);
            $decrypted = $service->decryptValue($encrypted);

            expect($decrypted)->toBe($original);
        });

        it('round-trips an array value', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $original = ['city' => 'San Francisco', 'zip' => '94102'];
            $encrypted = $service->encryptValue($original);
            $decrypted = $service->decryptValue($encrypted);

            expect($decrypted)->toBe($original);
        });

        it('returns non-encrypted values unchanged', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            expect($service->decryptValue('plain_text'))->toBe('plain_text');
            expect($service->decryptValue(42))->toBe(42);
            expect($service->decryptValue(null))->toBeNull();
            expect($service->decryptValue(['a', 'b']))->toBe(['a', 'b']);
        });

        it('handles corrupted encrypted values gracefully', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $corrupted = 'enc:v1:invalid-base64!!!';
            $result = $service->decryptValue($corrupted);

            // Should return the corrupted value (fail-safe)
            expect($result)->toBe($corrupted);
        });
    });

    describe('encryptParams', function () {
        it('encrypts matching global fields', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $params = [
                'page' => '/pricing',
                'email' => 'user@example.com',
                'phone' => '+15551234567',
            ];

            $result = $service->encryptParams($params, 'page_view');

            expect($result['page'])->toBe('/pricing');
            expect(str_starts_with($result['email'], 'enc:v1:'))->toBeTrue();
            expect(str_starts_with($result['phone'], 'enc:v1:'))->toBeTrue();
        });

        it('encrypts wildcard-matched fields', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $params = [
                'user_email' => 'user@example.com',
                'user_name' => 'John Doe',
                'event' => 'click',
            ];

            $result = $service->encryptParams($params, 'click');

            expect(str_starts_with($result['user_email'], 'enc:v1:'))->toBeTrue();
            expect(str_starts_with($result['user_name'], 'enc:v1:'))->toBeTrue();
            expect($result['event'])->toBe('click');
        });

        it('applies per-event rules', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $params = [
                'amount' => 99.99,
                'credit_card' => '4242424242424242',
                'currency' => 'USD',
            ];

            $result = $service->encryptParams($params, 'purchase');

            expect($result['amount'])->toBe(99.99);
            expect($result['currency'])->toBe('USD');
            expect(str_starts_with($result['credit_card'], 'enc:v1:'))->toBeTrue();
        });

        it('respects except: syntax in event rules', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $params = [
                'email' => 'user@example.com',
                'ip_address' => '192.168.1.1',
                'method' => 'google',
            ];

            $result = $service->encryptParams($params, 'sign_up');

            // email is global → encrypted
            expect(str_starts_with($result['email'], 'enc:v1:'))->toBeTrue();
            // ip_address has except: rule for sign_up → NOT encrypted
            expect($result['ip_address'])->toBe('192.168.1.1');
            // method is not in any rule → not encrypted
            expect($result['method'])->toBe('google');
        });

        it('returns empty params unchanged', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            expect($service->encryptParams([], 'page_view'))->toBe([]);
        });

        it('returns params unchanged when disabled', function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.encryption', [])
                ->andReturn(['enabled' => false]);

            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $params = ['email' => 'user@example.com'];

            expect($service->encryptParams($params, 'page_view'))->toBe($params);
        });

        it('round-trips encrypt and decrypt', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $original = [
                'page' => '/dashboard',
                'email' => 'user@example.com',
                'phone' => '+15551234567',
                'user_agent' => 'Mozilla/5.0',
            ];

            $encrypted = $service->encryptParams($original, 'page_view');
            $decrypted = $service->decryptParams($encrypted);

            expect($decrypted['page'])->toBe('/dashboard');
            expect($decrypted['email'])->toBe('user@example.com');
            expect($decrypted['phone'])->toBe('+15551234567');
            expect($decrypted['user_agent'])->toBe('Mozilla/5.0');
        });
    });

    describe('decryptParams', function () {
        it('decrypts only encrypted values in mixed params', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $encrypted = $service->encryptValue('secret@email.com');

            $params = [
                'page' => '/home',
                'email' => $encrypted,
                'count' => 42,
            ];

            $decrypted = $service->decryptParams($params);

            expect($decrypted['page'])->toBe('/home');
            expect($decrypted['email'])->toBe('secret@email.com');
            expect($decrypted['count'])->toBe(42);
        });
    });

    describe('decryptField', function () {
        it('decrypts a specific field from params', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $encrypted = $service->encryptValue('john@example.com');

            $params = [
                'name' => 'John',
                'email' => $encrypted,
            ];

            expect($service->decryptField($params, 'email'))->toBe('john@example.com');
        });

        it('returns null for missing fields', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            expect($service->decryptField([], 'missing'))->toBeNull();
        });
    });

    describe('isEncryptedValue', function () {
        it('detects encrypted values', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $encrypted = $service->encryptValue('test');

            expect($service->isEncryptedValue($encrypted))->toBeTrue();
            expect($service->isEncryptedValue('plain'))->toBeFalse();
            expect($service->isEncryptedValue(42))->toBeFalse();
            expect($service->isEncryptedValue(null))->toBeFalse();
            expect($service->isEncryptedValue(['a']))->toBeFalse();
        });
    });

    describe('countEncryptedFields', function () {
        it('counts encrypted fields in params', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $e1 = $service->encryptValue('email@test.com');
            $e2 = $service->encryptValue('phone');

            $params = [
                'page' => '/about',
                'email' => $e1,
                'phone' => $e2,
                'count' => 5,
            ];

            expect($service->countEncryptedFields($params))->toBe(2);
        });

        it('returns 0 for empty params', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            expect($service->countEncryptedFields([]))->toBe(0);
        });
    });

    describe('getFieldsForEvent', function () {
        it('returns global fields for events without specific rules', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $fields = $service->getFieldsForEvent('page_view');

            expect($fields)->toContain('email');
            expect($fields)->toContain('phone');
            expect($fields)->toContain('ip_address');
        });

        it('merges event-specific fields', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $fields = $service->getFieldsForEvent('purchase');

            expect($fields)->toContain('email');
            expect($fields)->toContain('credit_card');
            expect($fields)->toContain('billing_address');
        });

        it('applies except: syntax', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $fields = $service->getFieldsForEvent('sign_up');

            expect($fields)->not->toContain('ip_address');
            expect($fields)->toContain('email');
        });
    });

    describe('shouldEncryptFieldForEvent', function () {
        it('returns true for global fields', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            expect($service->shouldEncryptFieldForEvent('email', 'any_event'))->toBeTrue();
        });

        it('returns false for non-matching fields', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            expect($service->shouldEncryptFieldForEvent('page_url', 'page_view'))->toBeFalse();
        });

        it('returns true for wildcard-matched fields', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            expect($service->shouldEncryptFieldForEvent('user_email', 'click'))->toBeTrue();
            expect($service->shouldEncryptFieldForEvent('user_name', 'click'))->toBeTrue();
            expect($service->shouldEncryptFieldForEvent('user_id', 'click'))->toBeTrue();
        });
    });

    describe('rotateEncryption', function () {
        it('re-encrypts encrypted values', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $encrypted = $service->encryptValue('rotate@test.com');

            $params = [
                'plain' => 'unchanged',
                'email' => $encrypted,
            ];

            $rotated = $service->rotateEncryption($params);

            expect($rotated['plain'])->toBe('unchanged');
            expect($service->isEncryptedValue($rotated['email']))->toBeTrue();
        });

        it('returns empty params unchanged', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            expect($service->rotateEncryption([]))->toBe([]);
        });
    });

    describe('healthReport', function () {
        it('returns complete health report', function (): void {
            $encrypter = app(Encrypter::class);
            $service = new EventPayloadEncryptionService($encrypter, $this->config);

            $report = $service->healthReport();

            expect($report)->toHaveKeys([
                'enabled',
                'prefix',
                'global_fields_count',
                'global_fields',
                'event_rules_count',
                'event_rules',
                'cipher',
            ]);
            expect($report['enabled'])->toBeTrue();
            expect($report['prefix'])->toBe('enc:v1:');
            expect($report['cipher'])->toBeString();
            expect($report['global_fields_count'])->toBe(4);
            expect($report['event_rules_count'])->toBe(2);
        });
    });
});

describe('EventPayloadEncryptionMiddleware', function () {
    it('encrypts matching fields in event params', function (): void {
        $config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.encryption', [])
            ->andReturn([
                'enabled' => true,
                'prefix' => 'enc:v1:',
                'global_fields' => ['email', 'phone'],
                'event_rules' => [],
            ]);

        $encrypter = app(Encrypter::class);
        $service = new EventPayloadEncryptionService($encrypter, $config);
        $middleware = new EventPayloadEncryptionMiddleware($service);

        $event = new AnalyticsEvent(
            name: 'page_view',
            params: [
                'page' => '/home',
                'email' => 'user@example.com',
                'phone' => '+15551234567',
            ],
            clientId: 'cli_123',
        );

        $result = $middleware->process($event);

        expect($result)->not->toBeNull();
        expect($result->name)->toBe('page_view');
        expect($result->clientId)->toBe('cli_123');
        expect($result->params['page'])->toBe('/home');
        expect($service->isEncryptedValue($result->params['email']))->toBeTrue();
        expect($service->isEncryptedValue($result->params['phone']))->toBeTrue();
    });

    it('passes event through when encryption is disabled', function (): void {
        $config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.encryption', [])
            ->andReturn(['enabled' => false]);

        $encrypter = app(Encrypter::class);
        $service = new EventPayloadEncryptionService($encrypter, $config);
        $middleware = new EventPayloadEncryptionMiddleware($service);

        $event = new AnalyticsEvent(
            name: 'click',
            params: ['email' => 'test@example.com'],
        );

        $result = $middleware->process($event);

        expect($result)->not->toBeNull();
        expect($result->params['email'])->toBe('test@example.com');
    });

    it('returns event unchanged when params are empty', function (): void {
        $config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.encryption', [])
            ->andReturn([
                'enabled' => true,
                'global_fields' => ['email'],
                'event_rules' => [],
            ]);

        $encrypter = app(Encrypter::class);
        $service = new EventPayloadEncryptionService($encrypter, $config);
        $middleware = new EventPayloadEncryptionMiddleware($service);

        $event = new AnalyticsEvent(name: 'page_view', params: []);

        $result = $middleware->process($event);

        expect($result)->not->toBeNull();
        expect($result->params)->toBe([]);
    });

    it('preserves all event properties after processing', function (): void {
        $config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.encryption', [])
            ->andReturn([
                'enabled' => true,
                'global_fields' => [],
                'event_rules' => [],
            ]);

        $encrypter = app(Encrypter::class);
        $service = new EventPayloadEncryptionService($encrypter, $config);
        $middleware = new EventPayloadEncryptionMiddleware($service);

        $timestamp = new \DateTimeImmutable('2026-08-13T12:00:00+00:00');

        $event = new AnalyticsEvent(
            name: 'sign_up',
            params: ['method' => 'google'],
            clientId: 'cli_abc',
            userId: 'usr_123',
            timestamp: $timestamp,
            priority: 'critical',
            source: 'api',
        );

        $result = $middleware->process($event);

        expect($result->name)->toBe('sign_up');
        expect($result->clientId)->toBe('cli_abc');
        expect($result->userId)->toBe('usr_123');
        expect($result->timestamp)->toEqual($timestamp);
        expect($result->priority)->toBe('critical');
        expect($result->source)->toBe('api');
        expect($result->params['method'])->toBe('google');
    });

    it('reports correct priority and name', function (): void {
        $config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.encryption', [])
            ->andReturn(['enabled' => false]);

        $encrypter = app(Encrypter::class);
        $service = new EventPayloadEncryptionService($encrypter, $config);
        $middleware = new EventPayloadEncryptionMiddleware($service);

        expect($middleware->priority())->toBe(45);
        expect($middleware->name())->toBe('EventPayloadEncryption');
    });
});

describe('AnalyticsEncryptionCommand', function () {
    it('has correct signature and description', function (): void {
        $config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.encryption', [])
            ->andReturn(['enabled' => true]);

        $encrypter = app(Encrypter::class);
        $service = new EventPayloadEncryptionService($encrypter, $config);
        $command = new AnalyticsEncryptionCommand($service);

        expect($command->getDescription())->toContain('53.0.0');
        expect($command->getName())->toBe('zb:analytics:encryption');
    });
});

describe('Version Sweep', function () {
    it('AnalyticsEvent::VERSION matches expected v53.0.0 after sweep', function (): void {
        // This will be updated by the version sweep
        expect(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION)->toBe('53.0.0');
    });
});
