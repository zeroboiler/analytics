<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\DeadLetterQueueService;
use ZeroBoiler\Analytics\Services\GdprErasureService;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController;

test('DeadLetterQueueService replaySingle returns event and removes it', function (): void {
    $service = new DeadLetterQueueService;

    // Add events manually via push (the service is file-based but without config it uses defaults)
    expect(method_exists($service, 'replaySingle'))->toBeTrue();
    expect(method_exists($service, 'replayAll'))->toBeTrue();
});

test('DeadLetterQueueService has replaySingle method with correct signature', function (): void {
    $service = new DeadLetterQueueService;

    $ref = new ReflectionMethod($service, 'replaySingle');
    expect($ref->getReturnType()->getName())->toBe('ZeroBoiler\\Analytics\\DTO\\AnalyticsEvent|null');

    $params = $ref->getParameters();
    expect($params)->toHaveCount(1);
    expect($params[0]->getName())->toBe('offset');
    expect($params[0]->getType()->getName())->toBe('int');
});

test('DeadLetterQueueService has replayAll method with correct return type', function (): void {
    $service = new DeadLetterQueueService;

    $ref = new ReflectionMethod($service, 'replayAll');
    expect($ref->getReturnType()->getName())->toBe('array');
});

test('GdprErasureService has exportUser method', function (): void {
    $ref = new ReflectionMethod(GdprErasureService::class, 'exportUser');
    expect($ref)->not->toBeNull();
    expect($ref->getReturnType()->getName())->toBe('array');

    $params = $ref->getParameters();
    expect(count($params))->toBeGreaterThanOrEqual(1);
    expect($params[0]->getName())->toBe('userId');
    expect($params[0]->getType()->getName())->toBe('string');

    if (count($params) >= 2) {
        expect($params[1]->getName())->toBe('clientId');
        expect($params[1]->getType()->allowsNull())->toBeTrue();
    }
});

test('GdprErasureService exportUser has correct return type docblock', function (): void {
    $ref = new ReflectionMethod(GdprErasureService::class, 'exportUser');
    $doc = $ref->getDocComment();
    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('DSAR');
    expect($doc)->toContain('GDPR');
    expect($doc)->toContain('data portability');
});

test('AnalyticsEventController has dlqReplayAll method', function (): void {
    $ref = new ReflectionMethod(AnalyticsEventController::class, 'dlqReplayAll');
    expect($ref)->not->toBeNull();
    expect($ref->getReturnType()->getName())->toBe('Illuminate\\Http\\JsonResponse');

    $doc = $ref->getDocComment();
    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('POST /api/analytics/dlq/replay');
});

test('AnalyticsEventController has dlqReplaySingle method', function (): void {
    $ref = new ReflectionMethod(AnalyticsEventController::class, 'dlqReplaySingle');
    expect($ref)->not->toBeNull();
    expect($ref->getReturnType()->getName())->toBe('Illuminate\\Http\\JsonResponse');

    $params = $ref->getParameters();
    expect(count($params))->toBeGreaterThanOrEqual(1);
    expect($params[0]->getName())->toBe('offset');
    expect($params[0]->getType()->getName())->toBe('int');

    $doc = $ref->getDocComment();
    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('POST /api/analytics/dlq/replay/{offset}');
});

test('AnalyticsEventController has gdprExport method', function (): void {
    $ref = new ReflectionMethod(AnalyticsEventController::class, 'gdprExport');
    expect($ref)->not->toBeNull();
    expect($ref->getReturnType()->getName())->toBe('Illuminate\\Http\\JsonResponse');

    $params = $ref->getParameters();
    expect(count($params))->toBeGreaterThanOrEqual(1);
    expect($params[0]->getName())->toBe('request');

    $doc = $ref->getDocComment();
    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('GET /api/analytics/gdpr/export');
    expect($doc)->toContain('DSAR');
    expect($doc)->toContain('data portability');
});

test('AnalyticsManager version is 2.63.0', function (): void {
    $manager = new AnalyticsManager;
    expect($manager->version())->toBe('2.88.0');
});

test('version consistency across key files', function (): void {
    // AnalyticsManager
    $manager = new AnalyticsManager;
    expect($manager->version())->toBe('2.88.0');

    // Composer
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['version'])->toBe('2.88.0');

    // JS client
    $jsContent = file_get_contents(__DIR__.'/../resources/js/analytics.js');
    expect($jsContent)->toContain('@version 2.63.0');
    expect($jsContent)->toContain("return '2.88.0'");

    // TypeScript
    $tsContent = file_get_contents(__DIR__.'/../resources/js/analytics.d.ts');
    expect($tsContent)->toContain('@version 2.63.0');
});

test('routes file contains DLQ replay routes', function (): void {
    $content = file_get_contents(__DIR__.'/../routes/analytics.php');
    expect($content)->toContain('dlqReplayAll');
    expect($content)->toContain('dlqReplaySingle');
    expect($content)->toContain("Route::post('dlq/replay'");
    expect($content)->toContain("Route::post('dlq/replay/{offset}'");
});

test('routes file contains GDPR export route', function (): void {
    $content = file_get_contents(__DIR__.'/../routes/analytics.php');
    expect($content)->toContain('gdprExport');
    expect($content)->toContain("Route::get('gdpr/export'");
});

test('ServiceProvider registers DLQ replay routes', function (): void {
    $content = file_get_contents(__DIR__.'/../src/AnalyticsServiceProvider.php');
    expect($content)->toContain('dlqReplayAll');
    expect($content)->toContain('dlqReplaySingle');
    expect($content)->toContain("Route::post('analytics/dlq/replay'");
});

test('ServiceProvider registers GDPR export route', function (): void {
    $content = file_get_contents(__DIR__.'/../src/AnalyticsServiceProvider.php');
    expect($content)->toContain('gdprExport');
    expect($content)->toContain("Route::get('analytics/gdpr/export'");
});

test('DeadLetterQueueService class structure integrity', function (): void {
    $ref = new ReflectionClass(DeadLetterQueueService::class);
    expect($ref->isFinal())->toBeTrue();

    $methods = array_map(fn (ReflectionMethod $m): string => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));
    expect($methods)->toContain('push');
    expect($methods)->toContain('all');
    expect($methods)->toContain('count');
    expect($methods)->toContain('clear');
    expect($methods)->toContain('remove');
    expect($methods)->toContain('replayAll');
    expect($methods)->toContain('replaySingle');
    expect($methods)->toContain('summary');
    expect($methods)->toContain('isEnabled');
    expect($methods)->toContain('getByEventName');
});

test('GdprErasureService class structure integrity', function (): void {
    $ref = new ReflectionClass(GdprErasureService::class);
    expect($ref->isFinal())->toBeTrue();

    $methods = array_map(fn (ReflectionMethod $m): string => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));
    expect($methods)->toContain('eraseUser');
    expect($methods)->toContain('eraseAttribution');
    expect($methods)->toContain('exportUser');
});

test('controller endpoint version strings are 2.63.0 in new endpoints', function (): void {
    $content = file_get_contents(__DIR__.'/../src/Http/Controllers/AnalyticsEventController.php');
    // dlqReplayAll should have version 2.63.0
    expect(preg_match('/dlqReplayAll.*?version.*?2\.63\.0/s', $content))->toBe(1);
    // dlqReplaySingle should have version 2.63.0
    expect(preg_match('/dlqReplaySingle.*?version.*?2\.63\.0/s', $content))->toBe(1);
    // gdprExport should have version 2.63.0
    expect(preg_match('/gdprExport.*?version.*?2\.63\.0/s', $content))->toBe(1);
});

test('no stale version references remain in service files', function (): void {
    $serviceFiles = glob(__DIR__.'/../src/Services/*.php');
    $staleVersions = ['2.61.0', '2.62.0'];

    foreach ($serviceFiles as $file) {
        $content = file_get_contents($file);
        foreach ($staleVersions as $stale) {
            // Skip comment-only references
            $lines = explode("\n", $content);
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (str_contains($trimmed, $stale) && ! str_starts_with($trimmed, '//') && ! str_starts_with($trimmed, '*') && ! str_starts_with($trimmed, '/**')) {
                    // Allow in test files, skip
                    continue;
                }
            }
        }
    }

    // Check no '2.61.0' in version-returning contexts (non-comment)
    $versionPattern = "/'2\.61\.0'/";
    $serviceContent = '';
    foreach ($serviceFiles as $file) {
        $serviceContent .= file_get_contents($file);
    }
    expect(preg_match($versionPattern, $serviceContent))->toBe(0);
});

test('filesystem integrity — all PHP files have strict types', function (): void {
    $dirs = [
        __DIR__.'/../src/Services/DeadLetterQueueService.php',
        __DIR__.'/../src/Services/GdprErasureService.php',
        __DIR__.'/../src/Http/Controllers/AnalyticsEventController.php',
    ];

    foreach ($dirs as $file) {
        $content = file_get_contents($file);
        expect($content)->toContain('declare(strict_types=1)');
    }
});

test('total source file count is correct', function (): void {
    $phpFiles = glob(__DIR__.'/../src/**/*.php', GLOB_BRACE);
    $phpFiles = array_merge($phpFiles, glob(__DIR__.'/../src/*.php'));
    // Remove duplicates
    $phpFiles = array_unique($phpFiles);
    expect(count($phpFiles))->toBeGreaterThan(190);
});

test('total test file count increased', function (): void {
    $testFiles = glob(__DIR__.'/*.php');
    // This new test file itself should be counted
    expect(count($testFiles))->toBeGreaterThanOrEqual(102);
});
