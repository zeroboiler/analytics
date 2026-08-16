<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\CleanRoomAgreement;
use ZeroBoiler\Analytics\DTO\CleanRoomQueryResult;
use ZeroBoiler\Analytics\Services\AnalyticsCleanRoomService;

beforeEach(function (): void {
    $this->cache = mock(CacheRepository::class);
    $this->config = mock(ConfigRepository::class);

    $this->cache->shouldReceive('get')
        ->andReturn(null);

    $this->cache->shouldReceive('put')
        ->andReturn(true);

    $this->cache->shouldReceive('forget')
        ->andReturn(true);

    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.clean_room', [])
        ->andReturn([
            'enabled' => true,
            'k_anonymity' => 5,
            'agreement_ttl' => 604800,
            'result_ttl' => 3600,
            'max_agreements' => 50,
            'query_rate_limit' => 100,
            'max_dimensions' => 10,
            'differential_privacy' => true,
            'privacy_budget' => 1.0,
            'audit_retention' => 7776000,
        ]);

    $this->service = new AnalyticsCleanRoomService($this->cache, $this->config);
});

describe('AnalyticsCleanRoomService — File Quality', function (): void {
    test('file has strict types declaration', function (): void {
        $contents = file_get_contents(__DIR__ . '/../../src/Services/AnalyticsCleanRoomService.php');
        expect($contents)->toContain('declare(strict_types=1)');
    });

    test('file has MIT license header', function (): void {
        $contents = file_get_contents(__DIR__ . '/../../src/Services/AnalyticsCleanRoomService.php');
        expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the MIT license.');
    });

    test('class is final', function (): void {
        $ref = new ReflectionClass(AnalyticsCleanRoomService::class);
        expect($ref->isFinal())->toBeTrue();
    });

    test('service has @since 198.0.0 docblock tag', function (): void {
        $ref = new ReflectionClass(AnalyticsCleanRoomService::class);
        $doc = $ref->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('@since 198.0.0');
    });

    test('constructor has :void return type', function (): void {
        $ref = new ReflectionMethod(AnalyticsCleanRoomService::class, '__construct');
        expect($ref->getReturnType()?->getName())->toBe('void');
    });

    test('all public methods have return type declarations', function (): void {
        $ref = new ReflectionClass(AnalyticsCleanRoomService::class);
        $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if ($method->getDeclaringClass()->getName() !== AnalyticsCleanRoomService::class) {
                continue;
            }
            expect($method->getReturnType())->not->toBeNull(
                "Method {$method->getName()}() missing return type",
            );
        }
    });

    test('service has required public methods', function (): void {
        $ref = new ReflectionClass(AnalyticsCleanRoomService::class);
        $methods = array_map(
            fn (ReflectionMethod $m): string => $m->getName(),
            $ref->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        expect($methods)->toContain('isEnabled');
        expect($methods)->toContain('createAgreement');
        expect($methods)->toContain('getAgreement');
        expect($methods)->toContain('listAgreements');
        expect($methods)->toContain('revokeAgreement');
        expect($methods)->toContain('submitSketch');
        expect($methods)->toContain('executeQuery');
        expect($methods)->toContain('getQueryResults');
        expect($methods)->toContain('getAuditTrail');
        expect($methods)->toContain('stats');
        expect($methods)->toContain('validateConfig');
        expect($methods)->toContain('flush');
        expect($methods)->toContain('enable');
        expect($methods)->toContain('disable');
        expect($methods)->toContain('getKAnonymity');
        expect($methods)->toContain('countActiveAgreements');
    });
});

describe('AnalyticsCleanRoomService — DTOs', function (): void {
    test('CleanRoomAgreement file quality', function (): void {
        $contents = file_get_contents(__DIR__ . '/../../src/DTO/CleanRoomAgreement.php');
        expect($contents)->toContain('declare(strict_types=1)');
        expect($contents)->toContain('MIT license');
        expect($contents)->toContain('@since 198.0.0');

        $ref = new ReflectionClass(CleanRoomAgreement::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->isReadOnly())->toBeTrue();
    });

    test('CleanRoomAgreement immutable properties and methods', function (): void {
        $ref = new ReflectionClass(CleanRoomAgreement::class);
        $methods = array_map(fn (ReflectionMethod $m): string => $m->getName(), $ref->getMethods());

        expect($methods)->toContain('isActive');
        expect($methods)->toContain('isExpired');
        expect($methods)->toContain('isRevoked');
        expect($methods)->toContain('hasParticipant');
        expect($methods)->toContain('allowsAggregation');
        expect($methods)->toContain('allowsDimension');
        expect($methods)->toContain('toArray');
        expect($methods)->toContain('fromArray');
    });

    test('CleanRoomAgreement fromArray and toArray roundtrip', function (): void {
        $original = new CleanRoomAgreement(
            agreementId: 'test-agreement',
            participants: ['party_a', 'party_b'],
            scope: ['event_counts', 'cohorts'],
            dimensions: ['region', 'plan'],
            allowedAggregations: ['count', 'sum'],
            createdAt: '2026-01-01T00:00:00Z',
            expiresAt: '2026-12-31T23:59:59Z',
            status: 'active',
            kAnonymity: 5,
        );

        $restored = CleanRoomAgreement::fromArray($original->toArray());

        expect($restored->agreementId)->toBe($original->agreementId);
        expect($restored->participants)->toBe($original->participants);
        expect($restored->isActive())->toBeTrue();
        expect($restored->isExpired())->toBeFalse();
        expect($restored->isRevoked())->toBeFalse();
        expect($restored->hasParticipant('party_a'))->toBeTrue();
        expect($restored->hasParticipant('party_c'))->toBeFalse();
        expect($restored->allowsAggregation('count'))->toBeTrue();
        expect($restored->allowsAggregation('histogram'))->toBeFalse();
        expect($restored->allowsDimension('region'))->toBeTrue();
        expect($restored->allowsDimension('device'))->toBeFalse();
    });

    test('CleanRoomQueryResult file quality', function (): void {
        $contents = file_get_contents(__DIR__ . '/../../src/DTO/CleanRoomQueryResult.php');
        expect($contents)->toContain('declare(strict_types=1)');
        expect($contents)->toContain('MIT license');
        expect($contents)->toContain('@since 198.0.0');

        $ref = new ReflectionClass(CleanRoomQueryResult::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->isReadOnly())->toBeTrue();
    });

    test('CleanRoomQueryResult hasPrivacyProtection and privacySummary', function (): void {
        $result = new CleanRoomQueryResult(
            agreementId: 'test',
            queryType: 'count',
            result: ['total' => 100],
            kAnonymityApplied: true,
            privacyNoiseApplied: true,
            kAnonymityThreshold: 5,
            privacyEpsilon: 1.0,
            privacyMechanism: 'laplace',
            computedAt: '2026-01-01T00:00:00Z',
            participantCount: 2,
        );

        expect($result->hasPrivacyProtection())->toBeTrue();
        $summary = $result->privacySummary();
        expect($summary['protections'])->toContain('k-anonymity');
        expect($summary['protections'])->toContain('differential_privacy');
        expect($summary['k_anonymity'])->toBe(5);
        expect($summary['epsilon'])->toBe(1.0);

        $array = $result->toArray();
        expect($array['agreement_id'])->toBe('test');
        expect($array['privacy_protection'])->toBeTrue();
    });
});

describe('AnalyticsCleanRoomService — Core Functionality', function (): void {
    test('isEnabled returns configured state', function (): void {
        expect($this->service->isEnabled())->toBeTrue();
        expect($this->service->getKAnonymity())->toBe(5);
    });

    test('createAgreement with insufficient participants throws', function (): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 2 participants');

        $this->service->createAgreement('test', ['only_one'], []);
    });

    test('createAgreement with empty ID throws', function (): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Agreement ID cannot be empty');

        $this->service->createAgreement('', ['a', 'b'], []);
    });

    test('stats returns correct structure', function (): void {
        $stats = $this->service->stats();

        expect($stats)->toHaveKey('enabled');
        expect($stats)->toHaveKey('k_anonymity');
        expect($stats)->toHaveKey('active_agreements');
        expect($stats)->toHaveKey('max_agreements');
        expect($stats)->toHaveKey('query_rate_limit');
        expect($stats)->toHaveKey('differential_privacy');
        expect($stats)->toHaveKey('privacy_budget');
        expect($stats)->toHaveKey('agreement_ttl');
        expect($stats)->toHaveKey('audit_entries');
        expect($stats['enabled'])->toBeTrue();
        expect($stats['k_anonymity'])->toBe(5);
        expect($stats['max_agreements'])->toBe(50);
    });

    test('validateConfig detects valid configuration', function (): void {
        $validation = $this->service->validateConfig();

        expect($validation['valid'])->toBeTrue();
        expect($validation['errors'])->toBeEmpty();
    });
});

describe('AnalyticsCleanRoomCommand — File Quality', function (): void {
    test('file has strict types declaration', function (): void {
        $contents = file_get_contents(__DIR__ . '/../../src/Console/Commands/AnalyticsCleanRoomCommand.php');
        expect($contents)->toContain('declare(strict_types=1)');
    });

    test('file has MIT license header', function (): void {
        $contents = file_get_contents(__DIR__ . '/../../src/Console/Commands/AnalyticsCleanRoomCommand.php');
        expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the MIT license.');
    });

    test('class is final', function (): void {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsCleanRoomCommand::class);
        expect($ref->isFinal())->toBeTrue();
    });

    test('command has @since 198.0.0 docblock tag', function (): void {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsCleanRoomCommand::class);
        $doc = $ref->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('@since 198.0.0');
    });

    test('command has correct signature', function (): void {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsCleanRoomCommand::class);
        $property = $ref->getProperty('signature');
        $property->setAccessible(true);
        $signature = $property->getValue(new \ZeroBoiler\Analytics\Console\Commands\AnalyticsCleanRoomCommand);

        expect($signature)->toContain('zb:analytics:clean-room');
        expect($signature)->toContain('create');
        expect($signature)->toContain('list');
        expect($signature)->toContain('revoke');
        expect($signature)->toContain('submit');
        expect($signature)->toContain('query');
        expect($signature)->toContain('audit');
        expect($signature)->toContain('stats');
        expect($signature)->toContain('validate');
        expect($signature)->toContain('flush');
    });

    test('handle method has int return type', function (): void {
        $ref = new ReflectionMethod(\ZeroBoiler\Analytics\Console\Commands\AnalyticsCleanRoomCommand::class, 'handle');
        expect($ref->getReturnType()?->getName())->toBe('int');
    });
});

describe('V198 Integration — Source Count', function (): void {
    test('source file count has increased', function (): void {
        $srcDir = __DIR__ . '/../../src';
        $files = glob($srcDir . '/**/*.php', GLOB_BRACE);
        expect(count($files))->toBeGreaterThanOrEqual(875);

        // Verify new files exist
        expect(file_exists($srcDir . '/Services/AnalyticsCleanRoomService.php'))->toBeTrue();
        expect(file_exists($srcDir . '/DTO/CleanRoomAgreement.php'))->toBeTrue();
        expect(file_exists($srcDir . '/DTO/CleanRoomQueryResult.php'))->toBeTrue();
        expect(file_exists($srcDir . '/Console/Commands/AnalyticsCleanRoomCommand.php'))->toBeTrue();
    });

    test('test file count has increased', function (): void {
        $testsDir = __DIR__ . '/..';
        $files = glob($testsDir . '/*Test.php');
        expect(count($files))->toBeGreaterThanOrEqual(434);
    });
});

describe('V198 Config — Clean Room Section', function (): void {
    test('config file contains clean_room section', function (): void {
        $contents = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        expect($contents)->toContain("'clean_room' => [");
        expect($contents)->toContain('ANALYTICS_CLEAN_ROOM_ENABLED');
        expect($contents)->toContain('ANALYTICS_CLEAN_ROOM_K_ANONYMITY');
        expect($contents)->toContain('ANALYTICS_CLEAN_ROOM_DIFFERENTIAL_PRIVACY');
        expect($contents)->toContain('ANALYTICS_CLEAN_ROOM_PRIVACY_BUDGET');
        expect($contents)->toContain('ANALYTICS_CLEAN_ROOM_MAX_AGREEMENTS');
        expect($contents)->toContain('ANALYTICS_CLEAN_ROOM_QUERY_RATE_LIMIT');
        expect($contents)->toContain('ANALYTICS_CLEAN_ROOM_AUDIT_RETENTION');
    });
});
