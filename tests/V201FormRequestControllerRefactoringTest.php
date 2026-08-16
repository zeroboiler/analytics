<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController;
use ZeroBoiler\Analytics\Http\Requests\BatchEventRequest;
use ZeroBoiler\Analytics\Http\Requests\IdentifyRequest;
use ZeroBoiler\Analytics\Http\Requests\OptInRequest;
use ZeroBoiler\Analytics\Http\Requests\OptOutRequest;
use ZeroBoiler\Analytics\Http\Requests\PageViewRequest;
use ZeroBoiler\Analytics\Http\Requests\TrackEventRequest;
use ZeroBoiler\Analytics\Http\Requests\UpdateConsentRequest;
use ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics;

test('v201: FormRequest classes exist', function (): void {
    expect(class_exists(TrackEventRequest::class))->toBeTrue();
    expect(class_exists(BatchEventRequest::class))->toBeTrue();
    expect(class_exists(IdentifyRequest::class))->toBeTrue();
    expect(class_exists(PageViewRequest::class))->toBeTrue();
    expect(class_exists(UpdateConsentRequest::class))->toBeTrue();
    expect(class_exists(OptOutRequest::class))->toBeTrue();
    expect(class_exists(OptInRequest::class))->toBeTrue();
});

test('v201: FormRequest classes are final', function (): void {
    $reflections = [
        new ReflectionClass(TrackEventRequest::class),
        new ReflectionClass(BatchEventRequest::class),
        new ReflectionClass(IdentifyRequest::class),
        new ReflectionClass(PageViewRequest::class),
        new ReflectionClass(UpdateConsentRequest::class),
        new ReflectionClass(OptOutRequest::class),
        new ReflectionClass(OptInRequest::class),
    ];

    foreach ($reflections as $reflection) {
        expect($reflection->isFinal())->toBeTrue("{$reflection->getShortName()} must be final");
    }
});

test('v201: All FormRequests extend FormRequest', function (): void {
    $requests = [
        TrackEventRequest::class,
        BatchEventRequest::class,
        IdentifyRequest::class,
        PageViewRequest::class,
        UpdateConsentRequest::class,
        OptOutRequest::class,
        OptInRequest::class,
    ];

    foreach ($requests as $request) {
        expect(is_subclass_of($request, \Illuminate\Foundation\Http\FormRequest::class))
            ->toBeTrue("{$request} must extend FormRequest");
    }
});

test('v201: FormRequests have authorize() method with return type', function (): void {
    $requests = [
        TrackEventRequest::class,
        BatchEventRequest::class,
        IdentifyRequest::class,
        PageViewRequest::class,
        UpdateConsentRequest::class,
        OptOutRequest::class,
        OptInRequest::class,
    ];

    foreach ($requests as $request) {
        $method = new ReflectionMethod($request, 'authorize');
        expect($method->hasReturnType())->toBeTrue("{$request}::authorize() must have a return type");
        $returnType = $method->getReturnType();
        expect($returnType->getName())->toBe('bool');
    }
});

test('v201: FormRequests have rules() method with return type', function (): void {
    $requests = [
        TrackEventRequest::class,
        BatchEventRequest::class,
        IdentifyRequest::class,
        PageViewRequest::class,
        UpdateConsentRequest::class,
        OptOutRequest::class,
        OptInRequest::class,
    ];

    foreach ($requests as $request) {
        $method = new ReflectionMethod($request, 'rules');
        expect($method->hasReturnType())->toBeTrue("{$request}::rules() must have a return type");
        $returnType = $method->getReturnType();
        expect($returnType->getName())->toBe('array');
    }
});

test('v201: TrackEventRequest has typed accessor methods', function (): void {
    $reflection = new ReflectionClass(TrackEventRequest::class);

    expect($reflection->hasMethod('eventName'))->toBeTrue();
    expect($reflection->hasMethod('eventParams'))->toBeTrue();
    expect($reflection->hasMethod('clientId'))->toBeTrue();
    expect($reflection->hasMethod('priority'))->toBeTrue();
    expect($reflection->hasMethod('timestamp'))->toBeTrue();

    expect($reflection->getMethod('eventName')->hasReturnType())->toBeTrue();
    expect($reflection->getMethod('eventName')->getReturnType()->getName())->toBe('string');

    expect($reflection->getMethod('eventParams')->hasReturnType())->toBeTrue();
    expect($reflection->getMethod('eventParams')->getReturnType()->getName())->toBe('array');

    expect($reflection->getMethod('clientId')->hasReturnType())->toBeTrue();
    expect($reflection->getMethod('clientId')->getReturnType()->getName())->toBe('string');
    expect($reflection->getMethod('clientId')->getReturnType()->allowsNull())->toBeTrue();
});

test('v201: BatchEventRequest has events() and batchSize() methods', function (): void {
    $reflection = new ReflectionClass(BatchEventRequest::class);

    expect($reflection->hasMethod('events'))->toBeTrue();
    expect($reflection->hasMethod('batchSize'))->toBeTrue();

    expect($reflection->getMethod('events')->hasReturnType())->toBeTrue();
    expect($reflection->getMethod('events')->getReturnType()->getName())->toBe('array');

    expect($reflection->getMethod('batchSize')->hasReturnType())->toBeTrue();
    expect($reflection->getMethod('batchSize')->getReturnType()->getName())->toBe('int');
});

test('v201: IdentifyRequest has clientId(), traits(), userId() methods', function (): void {
    $reflection = new ReflectionClass(IdentifyRequest::class);

    expect($reflection->hasMethod('clientId'))->toBeTrue();
    expect($reflection->hasMethod('traits'))->toBeTrue();
    expect($reflection->hasMethod('userId'))->toBeTrue();

    expect($reflection->getMethod('clientId')->hasReturnType())->toBeTrue();
    expect($reflection->getMethod('clientId')->getReturnType()->getName())->toBe('string');

    expect($reflection->getMethod('traits')->hasReturnType())->toBeTrue();
    expect($reflection->getMethod('traits')->getReturnType()->getName())->toBe('array');

    expect($reflection->getMethod('userId')->hasReturnType())->toBeTrue();
    expect($reflection->getMethod('userId')->getReturnType()->getName())->toBe('string');
    expect($reflection->getMethod('userId')->getReturnType()->allowsNull())->toBeTrue();
});

test('v201: UpdateConsentRequest has signals() and source() methods', function (): void {
    $reflection = new ReflectionClass(UpdateConsentRequest::class);

    expect($reflection->hasMethod('signals'))->toBeTrue();
    expect($reflection->hasMethod('source'))->toBeTrue();

    expect($reflection->getMethod('signals')->hasReturnType())->toBeTrue();
    expect($reflection->getMethod('signals')->getReturnType()->getName())->toBe('array');

    expect($reflection->getMethod('source')->hasReturnType())->toBeTrue();
    expect($reflection->getMethod('source')->getReturnType()->getName())->toBe('string');
    expect($reflection->getMethod('source')->getReturnType()->allowsNull())->toBeTrue();
});

test('v201: PageViewRequest has typed accessor methods', function (): void {
    $reflection = new ReflectionClass(PageViewRequest::class);

    expect($reflection->hasMethod('pageTitle'))->toBeTrue();
    expect($reflection->hasMethod('pageLocation'))->toBeTrue();
    expect($reflection->hasMethod('referrer'))->toBeTrue();
    expect($reflection->hasMethod('path'))->toBeTrue();

    foreach (['pageTitle', 'pageLocation', 'referrer', 'path'] as $method) {
        $m = $reflection->getMethod($method);
        expect($m->hasReturnType())->toBeTrue("PageViewRequest::{$method}() must have a return type");
        expect($m->getReturnType()->getName())->toBe('string');
        expect($m->getReturnType()->allowsNull())->toBeTrue();
    }
});

test('v201: OptOutRequest and OptInRequest have userId() method', function (): void {
    foreach ([OptOutRequest::class, OptInRequest::class] as $class) {
        $reflection = new ReflectionClass($class);
        expect($reflection->hasMethod('userId'))->toBeTrue("{$reflection->getShortName()} must have userId()");

        $method = $reflection->getMethod('userId');
        expect($method->hasReturnType())->toBeTrue();
        expect($method->getReturnType()->getName())->toBe('string');
        expect($method->getReturnType()->allowsNull())->toBeTrue();
    }
});

test('v201: Controller uses FormRequest DI for track method', function (): void {
    $method = new ReflectionMethod(AnalyticsEventController::class, 'track');
    $parameters = $method->getParameters();

    expect(count($parameters))->toBe(1);
    $param = $parameters[0];
    $type = $param->getType();

    expect($type)->not->toBeNull();
    expect($type instanceof \ReflectionNamedType)->toBeTrue();
    expect($type->getName())->toBe(TrackEventRequest::class);
});

test('v201: Controller uses FormRequest DI for batch method', function (): void {
    $method = new ReflectionMethod(AnalyticsEventController::class, 'batch');
    $parameters = $method->getParameters();

    expect(count($parameters))->toBe(1);
    $param = $parameters[0];
    $type = $param->getType();

    expect($type)->not->toBeNull();
    expect($type instanceof \ReflectionNamedType)->toBeTrue();
    expect($type->getName())->toBe(BatchEventRequest::class);
});

test('v201: Controller uses FormRequest DI for identify method', function (): void {
    $method = new ReflectionMethod(AnalyticsEventController::class, 'identify');
    $parameters = $method->getParameters();

    expect(count($parameters))->toBe(1);
    $param = $parameters[0];
    $type = $param->getType();

    expect($type)->not->toBeNull();
    expect($type instanceof \ReflectionNamedType)->toBeTrue();
    expect($type->getName())->toBe(IdentifyRequest::class);
});

test('v201: Controller uses FormRequest DI for pageview method', function (): void {
    $method = new ReflectionMethod(AnalyticsEventController::class, 'pageview');
    $parameters = $method->getParameters();

    expect(count($parameters))->toBe(1);
    $param = $parameters[0];
    $type = $param->getType();

    expect($type)->not->toBeNull();
    expect($type instanceof \ReflectionNamedType)->toBeTrue();
    expect($type->getName())->toBe(PageViewRequest::class);
});

test('v201: Controller uses FormRequest DI for updateConsent method', function (): void {
    $method = new ReflectionMethod(AnalyticsEventController::class, 'updateConsent');
    $parameters = $method->getParameters();

    expect(count($parameters))->toBe(1);
    $param = $parameters[0];
    $type = $param->getType();

    expect($type)->not->toBeNull();
    expect($type instanceof \ReflectionNamedType)->toBeTrue();
    expect($type->getName())->toBe(UpdateConsentRequest::class);
});

test('v201: Controller uses FormRequest DI for optOut method', function (): void {
    $method = new ReflectionMethod(AnalyticsEventController::class, 'optOut');
    $parameters = $method->getParameters();

    expect(count($parameters))->toBe(1);
    $param = $parameters[0];
    $type = $param->getType();

    expect($type)->not->toBeNull();
    expect($type instanceof \ReflectionNamedType)->toBeTrue();
    expect($type->getName())->toBe(OptOutRequest::class);
});

test('v201: Controller uses FormRequest DI for optIn method', function (): void {
    $method = new ReflectionMethod(AnalyticsEventController::class, 'optIn');
    $parameters = $method->getParameters();

    expect(count($parameters))->toBe(1);
    $param = $parameters[0];
    $type = $param->getType();

    expect($type)->not->toBeNull();
    expect($type instanceof \ReflectionNamedType)->toBeTrue();
    expect($type->getName())->toBe(OptInRequest::class);
});

test('v201: Version consistency — all entry points at 201.0.0', function (): void {
    $version = '201.0.0';

    // DTO version constant
    expect(AnalyticsEvent::VERSION)->toBe($version);

    // Composer.json version
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($composer['version'])->toBe($version);

    // Package.json version
    $package = json_decode(file_get_contents(__DIR__ . '/../package.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($package['version'])->toBe($version);
});

test('v201: Source file count integrity', function (): void {
    $srcDir = __DIR__ . '/../src';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    $phpFiles = 0;
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $phpFiles++;
        }
    }

    // Should be at least 881 (879 original + 2 new FormRequests)
    expect($phpFiles)->toBeGreaterThanOrEqual(881);
});

test('v201: FormRequest count — 7 FormRequests in Http/Requests', function (): void {
    $requestsDir = __DIR__ . '/../src/Http/Requests';
    $files = glob($requestsDir . '/*.php');

    expect($files)->not->toBeEmpty();
    expect(count($files))->toBeGreaterThanOrEqual(7);

    $expectedNames = [
        'TrackEventRequest.php',
        'BatchEventRequest.php',
        'IdentifyRequest.php',
        'PageViewRequest.php',
        'UpdateConsentRequest.php',
        'OptOutRequest.php',
        'OptInRequest.php',
    ];

    $actualNames = array_map(static fn (string $path): string => basename($path), $files);

    foreach ($expectedNames as $name) {
        expect(in_array($name, $actualNames, true))->toBeTrue("Missing FormRequest: {$name}");
    }
});

test('v201: All FormRequests have strict_types=1', function (): void {
    $requests = [
        TrackEventRequest::class,
        BatchEventRequest::class,
        IdentifyRequest::class,
        PageViewRequest::class,
        UpdateConsentRequest::class,
        OptOutRequest::class,
        OptInRequest::class,
    ];

    foreach ($requests as $class) {
        $file = (new ReflectionClass($class))->getFileName();
        expect($file)->not->toBeFalse();

        $contents = file_get_contents($file);
        expect($contents)->toContain('declare(strict_types=1)');
    }
});

test('v201: All FormRequests have MIT license header', function (): void {
    $requests = [
        TrackEventRequest::class,
        BatchEventRequest::class,
        IdentifyRequest::class,
        PageViewRequest::class,
        UpdateConsentRequest::class,
        OptOutRequest::class,
        OptInRequest::class,
    ];

    foreach ($requests as $class) {
        $file = (new ReflectionClass($class))->getFileName();
        expect($file)->not->toBeFalse();

        $contents = file_get_contents($file);
        expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
    }
});

test('v201: Controller is final class', function (): void {
    $reflection = new ReflectionClass(AnalyticsEventController::class);
    expect($reflection->isFinal())->toBeTrue('AnalyticsEventController must be final');
});

test('v201: Inertia middleware is final class', function (): void {
    $reflection = new ReflectionClass(HandleInertiaAnalytics::class);
    expect($reflection->isFinal())->toBeTrue('HandleInertiaAnalytics must be final');
});

test('v201: TrackEventRequest has withValidator for strict catalog validation', function (): void {
    $reflection = new ReflectionClass(TrackEventRequest::class);
    expect($reflection->hasMethod('withValidator'))->toBeTrue();

    $method = $reflection->getMethod('withValidator');
    expect($method->hasReturnType())->toBeTrue();
    expect($method->getReturnType()->getName())->toBe('void');
});

test('v201: TrackEventRequest has custom attributes() and messages() methods', function (): void {
    $reflection = new ReflectionClass(TrackEventRequest::class);

    expect($reflection->hasMethod('attributes'))->toBeTrue();
    expect($reflection->hasMethod('messages'))->toBeTrue();

    expect($reflection->getMethod('attributes')->hasReturnType())->toBeTrue();
    expect($reflection->getMethod('attributes')->getReturnType()->getName())->toBe('array');

    expect($reflection->getMethod('messages')->hasReturnType())->toBeTrue();
    expect($reflection->getMethod('messages')->getReturnType()->getName())->toBe('array');
});

test('v201: BatchEventRequest has MAX_BATCH_SIZE constant', function (): void {
    $reflection = new ReflectionClass(BatchEventRequest::class);
    expect($reflection->hasConstant('MAX_BATCH_SIZE'))->toBeTrue();
    expect($reflection->getConstant('MAX_BATCH_SIZE'))->toBe(25);
});

test('v201: IdentifyRequest authorize() requires user', function (): void {
    $reflection = new ReflectionClass(IdentifyRequest::class);
    $contents = file_get_contents($reflection->getFileName());

    expect($contents)->toContain('$this->user() !== null');
});

test('v201: OptOutRequest authorize() requires user', function (): void {
    $reflection = new ReflectionClass(OptOutRequest::class);
    $contents = file_get_contents($reflection->getFileName());

    expect($contents)->toContain('$this->user() !== null');
});

test('v201: OptInRequest authorize() requires user', function (): void {
    $reflection = new ReflectionClass(OptInRequest::class);
    $contents = file_get_contents($reflection->getFileName());

    expect($contents)->toContain('$this->user() !== null');
});
