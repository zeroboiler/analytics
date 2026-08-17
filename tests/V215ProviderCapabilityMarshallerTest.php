<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use ReflectionClass;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\MarshalledPayload;
use ZeroBoiler\Analytics\DTO\ProviderCapability;
use ZeroBoiler\Analytics\DTO\ProviderCapabilityProfile;
use ZeroBoiler\Analytics\Services\ProviderCapabilityMatrixService;
use ZeroBoiler\Analytics\Services\EventPayloadMarshallerService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsCapabilityCommand;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;

// ─── DTO Quality ──────────────────────────────────────────────────────

test('V215: ProviderCapability DTO is final readonly', function (): void {
    $r = new ReflectionClass(ProviderCapability::class);
    expect($r->isFinal())->toBeTrue();
    expect($r->isReadOnly())->toBeTrue();
});

test('V215: ProviderCapability DTO round-trip serialization', function (): void {
    $cap = new ProviderCapability(
        name: 'batch_api',
        type: 'feature',
        supported: true,
        value: 100,
        description: 'Supports batch API',
    );

    $arr = $cap->toArray();
    expect($arr)->toHaveKeys(['name', 'type', 'supported', 'value', 'description']);
    expect($arr['name'])->toBe('batch_api');
    expect($arr['type'])->toBe('feature');
    expect($arr['supported'])->toBeTrue();
    expect($arr['value'])->toBe(100);

    $restored = ProviderCapability::fromArray($arr);
    expect($restored->name)->toBe($cap->name);
    expect($restored->supported)->toBe($cap->supported);
    expect($restored->value)->toBe($cap->value);
});

test('V215: ProviderCapabilityProfile DTO is final readonly', function (): void {
    $r = new ReflectionClass(ProviderCapabilityProfile::class);
    expect($r->isFinal())->toBeTrue();
    expect($r->isReadOnly())->toBeTrue();
});

test('V215: ProviderCapabilityProfile supports() and getCapabilityValue()', function (): void {
    $profile = new ProviderCapabilityProfile(
        provider: 'ga4',
        displayName: 'Google Analytics 4',
        providerType: 'hybrid',
        capabilities: [
            'batch_api' => new ProviderCapability('batch_api', 'feature', true, 25, 'Batch API'),
            'user_aliasing' => new ProviderCapability('user_aliasing', 'feature', false, null, 'User aliasing'),
            'max_batch_size' => new ProviderCapability('max_batch_size', 'limit', true, 25, 'Max batch'),
        ],
        supportedCount: 2,
        totalCapabilities: 3,
        coveragePercent: 66.7,
        missingCapabilities: ['user_aliasing'],
        limitations: ['max_batch_size' => 25],
        computedAt: '2026-08-17T00:00:00+00:00',
    );

    expect($profile->supports('batch_api'))->toBeTrue();
    expect($profile->supports('user_aliasing'))->toBeFalse();
    expect($profile->supports('nonexistent'))->toBeFalse();
    expect($profile->getCapabilityValue('max_batch_size'))->toBe(25);
    expect($profile->getCapabilityValue('batch_api'))->toBeNull();
    expect($profile->getCapabilityValue('user_aliasing'))->toBeNull();
});

test('V215: ProviderCapabilityProfile toArray() structure', function (): void {
    $profile = new ProviderCapabilityProfile(
        provider: 'webhook',
        displayName: 'Generic Webhook',
        providerType: 'server',
        capabilities: [],
        supportedCount: 0,
        totalCapabilities: 0,
        coveragePercent: 0.0,
        missingCapabilities: [],
        limitations: [],
        computedAt: '2026-08-17T00:00:00+00:00',
    );

    $arr = $profile->toArray();
    expect($arr)->toHaveKeys(['provider', 'display_name', 'provider_type', 'capabilities', 'supported_count', 'total_capabilities', 'coverage_percent', 'missing_capabilities', 'limitations', 'computed_at']);
});

test('V215: MarshalledPayload DTO is final readonly', function (): void {
    $r = new ReflectionClass(MarshalledPayload::class);
    expect($r->isFinal())->toBeTrue();
    expect($r->isReadOnly())->toBeTrue();
});

test('V215: MarshalledPayload::success() factory', function (): void {
    $result = MarshalledPayload::success(
        payload: ['item_id' => 'SKU-001', 'price' => 29.99],
        coercedFields: ['price'],
        unknownFields: ['source'],
        eventName: 'view_item',
    );

    expect($result->valid)->toBeTrue();
    expect($result->payload)->toBe(['item_id' => 'SKU-001', 'price' => 29.99]);
    expect($result->coercedFields)->toBe(['price']);
    expect($result->unknownFields)->toBe(['source']);
    expect($result->eventName)->toBe('view_item');
    expect($result->missingRequired)->toBeEmpty();
});

test('V215: MarshalledPayload::failure() factory', function (): void {
    $result = MarshalledPayload::failure(
        missingRequired: ['transaction_id', 'value'],
        messages: [['field' => 'transaction_id', 'message' => 'Required', 'severity' => 'error']],
    );

    expect($result->valid)->toBeFalse();
    expect($result->missingRequired)->toBe(['transaction_id', 'value']);
});

test('V215: MarshalledPayload toArray() structure', function (): void {
    $result = MarshalledPayload::success(payload: ['test' => true]);
    $arr = $result->toArray();
    expect($arr)->toHaveKeys(['valid', 'payload', 'messages', 'coerced_fields', 'missing_required', 'unknown_fields', 'event_name', 'schema_version', 'marshalled_at']);
});

// ─── Service File Quality ─────────────────────────────────────────────

test('V215: ProviderCapabilityMatrixService file quality', function (): void {
    $content = file_get_contents(__DIR__ . '/../src/Services/ProviderCapabilityMatrixService.php');
    expect($content)->toContain('declare(strict_types=1)');
    expect($content)->toContain('This file is part of ZeroBoiler');
    expect($content)->toContain('@since 215.0.0');

    $r = new ReflectionClass(ProviderCapabilityMatrixService::class);
    expect($r->isFinal())->toBeTrue();
    expect($content)->toContain('final class ProviderCapabilityMatrixService');
});

test('V215: EventPayloadMarshallerService file quality', function (): void {
    $content = file_get_contents(__DIR__ . '/../src/Services/EventPayloadMarshallerService.php');
    expect($content)->toContain('declare(strict_types=1)');
    expect($content)->toContain('This file is part of ZeroBoiler');
    expect($content)->toContain('@since 215.0.0');

    $r = new ReflectionClass(EventPayloadMarshallerService::class);
    expect($r->isFinal())->toBeTrue();
    expect($content)->toContain('final class EventPayloadMarshallerService');
});

test('V215: AnalyticsCapabilityCommand file quality', function (): void {
    $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsCapabilityCommand.php');
    expect($content)->toContain('declare(strict_types=1)');
    expect($content)->toContain('This file is part of ZeroBoiler');
    expect($content)->toContain('@since 215.0.0');

    $r = new ReflectionClass(AnalyticsCapabilityCommand::class);
    expect($r->isFinal())->toBeTrue();
    expect($content)->toContain('final class AnalyticsCapabilityCommand');
});

// ─── Service API Surface ───────────────────────────────────────────────

test('V215: ProviderCapabilityMatrixService has correct public methods', function (): void {
    $r = new ReflectionClass(ProviderCapabilityMatrixService::class);
    $methods = array_map(
        static fn (\ReflectionMethod $m): string => $m->getName(),
        $r->getMethods(\ReflectionMethod::IS_PUBLIC),
    );

    $expected = [
        'getProfile', 'supports', 'getCapabilityValue',
        'getAllProfiles', 'getCapabilityDefinitions', 'compare',
        'findProvidersSupporting', 'findProvidersMissing',
        'coverageRanking', 'coverageSummary', 'getProviders',
        'getCapabilityCount', 'matrixTable',
    ];

    foreach ($expected as $method) {
        expect(in_array($method, $methods, true), "Missing method: {$method}")->toBeTrue();
    }

    // All public methods have return type declarations
    foreach ($r->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
        if (str_starts_with($method->getName(), '__')) {
            continue;
        }
        expect($method->hasReturnType(), "ProviderCapabilityMatrixService::{$method->getName()}() missing return type")->toBeTrue();
    }
});

test('V215: EventPayloadMarshallerService has correct public methods', function (): void {
    $r = new ReflectionClass(EventPayloadMarshallerService::class);
    $methods = array_map(
        static fn (\ReflectionMethod $m): string => $m->getName(),
        $r->getMethods(\ReflectionMethod::IS_PUBLIC),
    );

    $expected = ['marshal', 'marshalBatch', 'getConfig'];

    foreach ($expected as $method) {
        expect(in_array($method, $methods, true), "Missing method: {$method}")->toBeTrue();
    }

    // All public methods have return type declarations
    foreach ($r->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
        if (str_starts_with($method->getName(), '__')) {
            continue;
        }
        expect($method->hasReturnType(), "EventPayloadMarshallerService::{$method->getName()}() missing return type")->toBeTrue();
    }
});

test('V215: ProviderCapabilityMatrixService covers 10 providers', function (): void {
    // Check the constant
    $r = new ReflectionClass(ProviderCapabilityMatrixService::class);
    $constants = $r->getConstants();
    expect(isset($constants['ALL_PROVIDERS']))->toBeTrue();

    $providers = ProviderCapabilityMatrixService::ALL_PROVIDERS; // This is a private const, check via reflection
    $reflProviders = $r->getReflectionConstant('ALL_PROVIDERS');
    expect($reflProviders)->not->toBeFalse();
    expect($reflProviders->getValue())->toHaveCount(10);
});

test('V215: ProviderCapabilityMatrixService has 33 capability definitions', function (): void {
    $r = new ReflectionClass(ProviderCapabilityMatrixService::class);
    $reflDefs = $r->getReflectionConstant('CAPABILITY_DEFINITIONS');
    expect($reflDefs)->not->toBeFalse();
    expect(count($reflDefs->getValue()))->toBe(33);
});

// ─── Command Signature ────────────────────────────────────────────────

test('V215: AnalyticsCapabilityCommand has correct signature', function (): void {
    $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsCapabilityCommand.php');

    expect($content)->toContain("analytics:capability");
    expect($content)->toContain('handle(ProviderCapabilityMatrixService');
    expect($content)->toContain('#[\\Override]');
});

// ─── ServiceProvider Registration ─────────────────────────────────────

test('V215: ProviderCapabilityMatrixService registered in ServiceProvider', function (): void {
    $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect($content)->toContain('ProviderCapabilityMatrixService');
});

test('V215: EventPayloadMarshallerService registered in ServiceProvider', function (): void {
    $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect($content)->toContain('EventPayloadMarshallerService');
});

test('V215: AnalyticsCapabilityCommand registered in ServiceProvider', function (): void {
    $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect($content)->toContain('AnalyticsCapabilityCommand');
});

// ─── Routes ────────────────────────────────────────────────────────────

test('V215: capability routes registered', function (): void {
    $content = file_get_contents(__DIR__ . '/../routes/analytics.php');
    expect($content)->toContain('capabilities/ranking');
    expect($content)->toContain('capabilities/summary');
    expect($content)->toContain('capabilities/providers');
    expect($content)->toContain('capabilities/profile/{provider}');
    expect($content)->toContain('capabilities/compare');
    expect($content)->toContain('capabilities/check');
    expect($content)->toContain('capabilities/definitions');
    expect($content)->toContain('capabilities/matrix');
});

test('V215: marshaller routes registered', function (): void {
    $content = file_get_contents(__DIR__ . '/../routes/analytics.php');
    expect($content)->toContain('marshaller/marshal');
    expect($content)->toContain('marshaller/batch');
    expect($content)->toContain('marshaller/config');
});

// ─── Config ────────────────────────────────────────────────────────────

test('V215: config has provider_capabilities section', function (): void {
    $config = include __DIR__ . '/../config/zeroboiler.php';
    expect(array_key_exists('provider_capabilities', $config['analytics']))->toBeTrue();

    $pc = $config['analytics']['provider_capabilities'];
    expect($pc)->toHaveKeys(['enabled', 'cache_ttl']);
});

test('V215: config has marshaller section', function (): void {
    $config = include __DIR__ . '/../config/zeroboiler.php';
    expect(array_key_exists('marshaller', $config['analytics']))->toBeTrue();

    $m = $config['analytics']['marshaller'];
    expect($m)->toHaveKeys(['strict', 'strip_unknown', 'detect_pii', 'populate_defaults', 'global_defaults']);
});

// ─── Controller Methods ───────────────────────────────────────────────

test('V215: controller has capability action methods', function (): void {
    $content = file_get_contents(__DIR__ . '/../src/Http/Controllers/AnalyticsEventController.php');
    expect($content)->toContain('function capabilityRanking');
    expect($content)->toContain('function capabilitySummary');
    expect($content)->toContain('function capabilityProviders');
    expect($content)->toContain('function capabilityProfile');
    expect($content)->toContain('function capabilityCompare');
    expect($content)->toContain('function capabilityCheck');
    expect($content)->toContain('function capabilityDefinitions');
    expect($content)->toContain('function capabilityMatrix');
});

test('V215: controller has marshaller action methods', function (): void {
    $content = file_get_contents(__DIR__ . '/../src/Http/Controllers/AnalyticsEventController.php');
    expect($content)->toContain('function marshallerMarshal');
    expect($content)->toContain('function marshallerBatch');
    expect($content)->toContain('function marshallerConfig');
});

// ─── Version Consistency ───────────────────────────────────────────────

test('V215: new files have @since 215.0.0', function (): void {
    $files = [
        __DIR__ . '/../src/DTO/ProviderCapability.php',
        __DIR__ . '/../src/DTO/ProviderCapabilityProfile.php',
        __DIR__ . '/../src/DTO/MarshalledPayload.php',
        __DIR__ . '/../src/Services/ProviderCapabilityMatrixService.php',
        __DIR__ . '/../src/Services/EventPayloadMarshallerService.php',
        __DIR__ . '/../src/Console/Commands/AnalyticsCapabilityCommand.php',
    ];

    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content, "@since 215.0.0 missing in {$file}")->toContain('@since 215.0.0');
    }
});
