<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Console\Commands\AnalyticsSdkTokenCommand;
use ZeroBoiler\Analytics\Services\SdkScopeTokenService;
use ZeroBoiler\Analytics\Services\SdkTokenAuditLogger;

beforeEach(function (): void {
    //
});

describe('SdkTokenAuditLogger', function (): void {
    test('class exists and is final', function (): void {
        expect(class_exists(SdkTokenAuditLogger::class))->toBeTrue();

        $reflection = new ReflectionClass(SdkTokenAuditLogger::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    test('has strict types declaration', function (): void {
        $contents = file_get_contents((new ReflectionClass(SdkTokenAuditLogger::class))->getFileName());
        expect($contents)->not->toBeFalse();
        expect($contents)->toContain('declare(strict_types=1)');
    });

    test('constructor returns void', function (): void {
        $reflection = new ReflectionClass(SdkTokenAuditLogger::class);
        $constructor = $reflection->getMethod('__construct');
        expect($constructor->getReturnType()?->getName())->toBe('void');
    });

    test('has isEnabled method returning bool', function (): void {
        $reflection = new ReflectionClass(SdkTokenAuditLogger::class);
        expect($reflection->hasMethod('isEnabled'))->toBeTrue();

        $method = $reflection->getMethod('isEnabled');
        expect($method->getReturnType()?->getName())->toBe('bool');
    });

    test('has log method returning void', function (): void {
        $reflection = new ReflectionClass(SdkTokenAuditLogger::class);
        expect($reflection->hasMethod('log'))->toBeTrue();

        $method = $reflection->getMethod('log');
        expect($method->getReturnType()?->getName())->toBe('void');
    });

    test('has getEntries method returning array', function (): void {
        $reflection = new ReflectionClass(SdkTokenAuditLogger::class);
        expect($reflection->hasMethod('getEntries'))->toBeTrue();

        $method = $reflection->getMethod('getEntries');
        expect($method->getReturnType()?->getName())->toBe('array');
    });

    test('has getStats method returning array', function (): void {
        $reflection = new ReflectionClass(SdkTokenAuditLogger::class);
        expect($reflection->hasMethod('getStats'))->toBeTrue();

        $method = $reflection->getMethod('getStats');
        expect($method->getReturnType()?->getName())->toBe('array');
    });

    test('has getSecurityEvents method returning array', function (): void {
        $reflection = new ReflectionClass(SdkTokenAuditLogger::class);
        expect($reflection->hasMethod('getSecurityEvents'))->toBeTrue();

        $method = $reflection->getMethod('getSecurityEvents');
        expect($method->getReturnType()?->getName())->toBe('array');
    });

    test('has clear method returning void', function (): void {
        $reflection = new ReflectionClass(SdkTokenAuditLogger::class);
        expect($reflection->hasMethod('clear'))->toBeTrue();

        $method = $reflection->getMethod('clear');
        expect($method->getReturnType()?->getName())->toBe('void');
    });

    test('has count method returning int', function (): void {
        $reflection = new ReflectionClass(SdkTokenAuditLogger::class);
        expect($reflection->hasMethod('count'))->toBeTrue();

        $method = $reflection->getMethod('count');
        expect($method->getReturnType()?->getName())->toBe('int');
    });

    test('has allOperations static method returning list of strings', function (): void {
        $reflection = new ReflectionClass(SdkTokenAuditLogger::class);
        expect($reflection->hasMethod('allOperations'))->toBeTrue();

        $method = $reflection->getMethod('allOperations');
        expect($method->getReturnType()?->getName())->toBe('array');
    });

    test('operation constants are defined correctly', function (): void {
        expect(SdkTokenAuditLogger::OP_GENERATE)->toBe('generate');
        expect(SdkTokenAuditLogger::OP_VALIDATE)->toBe('validate');
        expect(SdkTokenAuditLogger::OP_VALIDATE_FAIL)->toBe('validate_fail');
        expect(SdkTokenAuditLogger::OP_REVOKE)->toBe('revoke');
        expect(SdkTokenAuditLogger::OP_RATE_LIMITED)->toBe('rate_limited');
        expect(SdkTokenAuditLogger::OP_ORIGIN_BLOCKED)->toBe('origin_blocked');
        expect(SdkTokenAuditLogger::OP_ENVIRONMENT_BLOCKED)->toBe('environment_blocked');
        expect(SdkTokenAuditLogger::OP_PERMISSION_DENIED)->toBe('permission_denied');
        expect(SdkTokenAuditLogger::OP_ROTATE)->toBe('rotate');
    });

    test('outcome constants are defined correctly', function (): void {
        expect(SdkTokenAuditLogger::OUTCOME_SUCCESS)->toBe('success');
        expect(SdkTokenAuditLogger::OUTCOME_FAILURE)->toBe('failure');
        expect(SdkTokenAuditLogger::OUTCOME_BLOCKED)->toBe('blocked');
    });

    test('allOperations returns all defined operations', function (): void {
        $operations = SdkTokenAuditLogger::allOperations();
        expect($operations)->toBeArray();
        expect($operations)->toContain('generate');
        expect($operations)->toContain('validate');
        expect($operations)->toContain('revoke');
        expect($operations)->toContain('rate_limited');
        expect($operations)->toContain('origin_blocked');
        expect(count($operations))->toBeGreaterThanOrEqual(9);
    });

    test('has @since 156.0.0 docblock', function (): void {
        $reflection = new ReflectionClass(SdkTokenAuditLogger::class);
        $doc = $reflection->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('@since 156.0.0');
    });

    test('log entries have required fields structure', function (): void {
        // Verify the PHPDoc type hint for getEntries return type includes required fields
        $reflection = new ReflectionClass(SdkTokenAuditLogger::class);
        $method = $reflection->getMethod('getEntries');
        $doc = $method->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('id:');
        expect($doc)->toContain('operation:');
        expect($doc)->toContain('scope:');
        expect($doc)->toContain('ip:');
        expect($doc)->toContain('timestamp:');
    });
});

describe('AnalyticsSdkTokenCommand', function (): void {
    test('command class exists and is final', function (): void {
        expect(class_exists(AnalyticsSdkTokenCommand::class))->toBeTrue();

        $reflection = new ReflectionClass(AnalyticsSdkTokenCommand::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    test('has strict types declaration', function (): void {
        $contents = file_get_contents((new ReflectionClass(AnalyticsSdkTokenCommand::class))->getFileName());
        expect($contents)->not->toBeFalse();
        expect($contents)->toContain('declare(strict_types=1)');
    });

    test('constructor returns void', function (): void {
        $reflection = new ReflectionClass(AnalyticsSdkTokenCommand::class);
        $constructor = $reflection->getMethod('__construct');
        expect($constructor->getReturnType()?->getName())->toBe('void');
    });

    test('extends Illuminate\Console\Command', function (): void {
        $reflection = new ReflectionClass(AnalyticsSdkTokenCommand::class);
        expect($reflection->getParentClass()?->getName())->toBe('Illuminate\Console\Command');
    });

    test('has correct signature and description', function (): void {
        $command = new AnalyticsSdkTokenCommand(
            app('cache'),
            app('config'),
        );
        $reflection = new ReflectionClass($command);

        $signature = $reflection->getProperty('signature')->getValue($command);
        $description = $reflection->getProperty('description')->getValue($command);

        expect($signature)->toContain('zb:analytics:sdk-tokens');
        expect($signature)->toContain('{action?');
        expect($signature)->toContain('--scope=');
        expect($signature)->toContain('--permissions=');
        expect($signature)->toContain('--categories=');
        expect($signature)->toContain('--rate-limit=');
        expect($signature)->toContain('--environment=');
        expect($signature)->toContain('--token=');
        expect($signature)->toContain('--json');

        expect($description)->toBe('Manage SDK scoped tokens — generate, list, revoke, rotate, and audit');
    });

    test('has handle method returning int', function (): void {
        $reflection = new ReflectionClass(AnalyticsSdkTokenCommand::class);
        expect($reflection->hasMethod('handle'))->toBeTrue();

        $method = $reflection->getMethod('handle');
        expect($method->getReturnType()?->getName())->toBe('int');
    });

    test('has @since 156.0.0 docblock', function (): void {
        $reflection = new ReflectionClass(AnalyticsSdkTokenCommand::class);
        $doc = $reflection->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('@since 156.0.0');
    });

    test('has private action methods for each action', function (): void {
        $reflection = new ReflectionClass(AnalyticsSdkTokenCommand::class);
        expect($reflection->hasMethod('generateToken'))->toBeTrue();
        expect($reflection->hasMethod('listTokens'))->toBeTrue();
        expect($reflection->hasMethod('revokeToken'))->toBeTrue();
        expect($reflection->hasMethod('rotateToken'))->toBeTrue();
        expect($reflection->hasMethod('showAudit'))->toBeTrue();
        expect($reflection->hasMethod('clearAudit'))->toBeTrue();
        expect($reflection->hasMethod('showStats'))->toBeTrue();
        expect($reflection->hasMethod('displayStatus'))->toBeTrue();

        // Verify they are private
        expect($reflection->getMethod('generateToken')->isPrivate())->toBeTrue();
        expect($reflection->getMethod('listTokens')->isPrivate())->toBeTrue();
        expect($reflection->getMethod('revokeToken')->isPrivate())->toBeTrue();
        expect($reflection->getMethod('rotateToken')->isPrivate())->toBeTrue();
    });

    test('action methods have correct return types', function (): void {
        $reflection = new ReflectionClass(AnalyticsSdkTokenCommand::class);
        foreach (['generateToken', 'listTokens', 'revokeToken', 'rotateToken', 'showAudit', 'clearAudit', 'showStats', 'displayStatus', 'invalidAction'] as $method) {
            $m = $reflection->getMethod($method);
            expect($m->getReturnType()?->getName())->toBe('int');
        }
    });
});

describe('SDK Token Gateway — Routes & Config Integration', function (): void {
    test('routes file contains SDK token endpoints', function (): void {
        $routesContent = file_get_contents(base_path('routes/analytics.php'));
        expect($routesContent)->not->toBeFalse();
        expect($routesContent)->toContain('sdk-tokens/audit');
        expect($routesContent)->toContain('sdk-tokens/audit/security');
        expect($routesContent)->toContain('sdk-tokens/audit/stats');
        expect($routesContent)->toContain('sdk-tokens/status');
        expect($routesContent)->toContain('sdk-tokens/permissions');
        expect($routesContent)->toContain('sdkTokenAuditLog');
        expect($routesContent)->toContain('sdkTokenAuditSecurity');
        expect($routesContent)->toContain('sdkTokenAuditStats');
        expect($routesContent)->toContain('sdkTokenStatus');
        expect($routesContent)->toContain('sdkTokenPermissions');
    });

    test('routes file has v156.0.0 comment', function (): void {
        $routesContent = file_get_contents(base_path('routes/analytics.php'));
        expect($routesContent)->not->toBeFalse();
        expect($routesContent)->toContain('v156.0.0');
    });

    test('config has audit section under sdk_tokens', function (): void {
        $config = config('zeroboiler.analytics.sdk_tokens');
        expect($config)->not->toBeNull();
        expect($config)->toBeArray();
        expect(isset($config['audit']))->toBeTrue();
        expect($config['audit'])->toBeArray();
        expect(isset($config['audit']['enabled']))->toBeTrue();
        expect(isset($config['audit']['ttl']))->toBeTrue();
        expect(isset($config['audit']['max_entries']))->toBeTrue();
    });

    test('ServiceProvider registers SdkTokenAuditLogger', function (): void {
        $providerContent = file_get_contents(
            (new ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class))->getFileName()
        );
        expect($providerContent)->not->toBeFalse();
        expect($providerContent)->toContain('SdkTokenAuditLogger');
        expect($providerContent)->toContain('AnalyticsSdkTokenCommand');
    });

    test('controller has SDK token gateway methods', function (): void {
        $controllerContent = file_get_contents(
            (new ReflectionClass(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class))->getFileName()
        );
        expect($controllerContent)->not->toBeFalse();
        expect($controllerContent)->toContain('sdkTokenAuditLog');
        expect($controllerContent)->toContain('sdkTokenAuditSecurity');
        expect($controllerContent)->toContain('sdkTokenAuditStats');
        expect($controllerContent)->toContain('sdkTokenAuditClear');
        expect($controllerContent)->toContain('sdkTokenStatus');
        expect($controllerContent)->toContain('sdkTokenPermissions');
    });

    test('version sweep — all entry points are 160.0.0', function (): void {
        // composer.json
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);
        expect($composer['version'])->toBe('160.0.0');

        // package.json
        $package = json_decode(file_get_contents(base_path('package.json')), true);
        expect($package['version'])->toBe('160.0.0');

        // AnalyticsEvent::VERSION
        expect(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION)->toBe('160.0.0');

        // Integrity command
        $integrityReflection = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsIntegrityCommand::class);
        $expectedVersion = $integrityReflection->getConstant('EXPECTED_VERSION');
        expect($expectedVersion)->toBe('160.0.0');
    });
});
