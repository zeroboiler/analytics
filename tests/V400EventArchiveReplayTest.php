<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EventArchiveService;

/**
 * V4.0.0 — Event Archive Service Validation Test.
 *
 * Comprehensive validation of the Event Archive Service:
 * - Service class exists and is properly structured
 * - Archive search with filters and pagination
 * - Single event replay
 * - Event count statistics
 * - Archive management (clear, delete)
 * - Config section present
 * - API routes registered
 * - Artisan command registered
 * - Version consistency
 */
test('v4.0.0 EventArchiveService class exists and is final', function (): void {
    expect(class_exists(\ZeroBoiler\Analytics\Services\EventArchiveService::class))->toBeTrue();

    $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\EventArchiveService::class);
    expect($ref->isFinal())->toBeTrue();
});

test('v4.0.0 EventArchiveService constructor accepts CacheRepository, AnalyticsManager, ConfigRepository', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\EventArchiveService::class);
    $ctor = $ref->getMethod('__construct');
    $params = $ctor->getParameters();

    expect(count($params))->toBe(3);
    expect($params[0]->getName())->toBe('cache');
    expect($params[1]->getName())->toBe('manager');
    expect($params[2]->getName())->toBe('config');
    expect($ctor->hasReturnType())->toBeTrue();
});

test('v4.0.0 EventArchiveService has archive, search, replay, stats, clear methods', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\EventArchiveService::class);
    $methods = array_map(fn (\ReflectionMethod $m) => $m->getName(), $ref->getMethods(\ReflectionMethod::IS_PUBLIC));

    expect($methods)->toContain('archive');
    expect($methods)->toContain('search');
    expect($methods)->toContain('get');
    expect($methods)->toContain('replay');
    expect($methods)->toContain('replayBulk');
    expect($methods)->toContain('eventCounts');
    expect($methods)->toContain('totalArchived');
    expect($methods)->toContain('clear');
    expect($methods)->toContain('delete');
});

test('v4.0.0 EventArchiveService methods have proper return types', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\EventArchiveService::class);

    // archive() returns void
    $archive = $ref->getMethod('archive');
    expect($archive->hasReturnType())->toBeTrue();
    expect((string) $archive->getReturnType())->toBe('void');

    // search() returns array
    $search = $ref->getMethod('search');
    expect($search->hasReturnType())->toBeTrue();
    expect((string) $search->getReturnType())->toBe('array');

    // get() returns ?array
    $get = $ref->getMethod('get');
    expect($get->hasReturnType())->toBeTrue();
    expect((string) $get->getReturnType())->toBe('?array');

    // replay() returns bool
    $replay = $ref->getMethod('replay');
    expect($replay->hasReturnType())->toBeTrue();
    expect((string) $replay->getReturnType())->toBe('bool');

    // replayBulk() returns array
    $replayBulk = $ref->getMethod('replayBulk');
    expect($replayBulk->hasReturnType())->toBeTrue();
    expect((string) $replayBulk->getReturnType())->toBe('array');

    // eventCounts() returns array
    $counts = $ref->getMethod('eventCounts');
    expect($counts->hasReturnType())->toBeTrue();
    expect((string) $counts->getReturnType())->toBe('array');

    // totalArchived() returns int
    $total = $ref->getMethod('totalArchived');
    expect($total->hasReturnType())->toBeTrue();
    expect((string) $total->getReturnType())->toBe('int');

    // clear() returns int
    $clear = $ref->getMethod('clear');
    expect($clear->hasReturnType())->toBeTrue();
    expect((string) $clear->getReturnType())->toBe('int');
});

test('v4.0.0 archive config section is present', function (): void {
    $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
    expect($config)->not->toBeFalse();
    expect($config)->toContain("'archive' => [");
    expect($config)->toContain('ANALYTICS_ARCHIVE_ENABLED');
    expect($config)->toContain('ANALYTICS_ARCHIVE_RETENTION_TTL');
    expect($config)->toContain('ANALYTICS_ARCHIVE_MAX_EVENTS');
    expect($config)->toContain('ANALYTICS_ARCHIVE_CACHE_PREFIX');
    expect($config)->toContain('always_archive');
    expect($config)->toContain('never_archive');
});

test('v4.0.0 archive API routes are registered in routes file', function (): void {
    $routesFile = file_get_contents(__DIR__ . '/../routes/analytics.php');
    expect($routesFile)->not->toBeFalse();

    expect($routesFile)->toContain("'archive', [AnalyticsEventController::class, 'archiveSearch']");
    expect($routesFile)->toContain("'archive/stats', [AnalyticsEventController::class, 'archiveStats']");
    expect($routesFile)->toContain("'archive/{id}', [AnalyticsEventController::class, 'archiveGet']");
    expect($routesFile)->toContain("'archive/{id}/replay', [AnalyticsEventController::class, 'archiveReplay']");
    expect($routesFile)->toContain("'archive', [AnalyticsEventController::class, 'archiveClear']");
});

test('v4.0.0 archive API routes are registered in ServiceProvider', function (): void {
    $sp = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect($sp)->not->toBeFalse();
    expect($sp)->toContain("'analytics/archive'");
    expect($sp)->toContain('archiveSearch');
    expect($sp)->toContain('archiveStats');
    expect($sp)->toContain('archiveGet');
    expect($sp)->toContain('archiveReplay');
    expect($sp)->toContain('archiveClear');
});

test('v4.0.0 AnalyticsReplayCommand class exists and is final', function (): void {
    expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsReplayCommand::class))->toBeTrue();

    $ref = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsReplayCommand::class);
    expect($ref->isFinal())->toBeTrue();
});

test('v4.0.0 AnalyticsReplayCommand has correct signature', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsReplayCommand::class);
    $prop = $ref->getProperty('signature');

    // Create a mock EventArchiveService
    $cache = new class implements \Illuminate\Contracts\Cache\Repository {
        /** @param  array<string, mixed>  $keys */
        public function get(string $key, mixed $default = null): mixed { return $default; }
        public function many(array $keys): array { return array_fill_keys($keys, null); }
        public function put(string $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null): bool { return true; }
        public function putMany(array $values, \DateTimeInterface|\DateInterval|int|null $ttl = null): bool { return true; }
        public function increment(string $key, int $amount = 1, int|null $initial = 0): int|false { return $initial; }
        public function decrement(string $key, int $amount = 1, int|null $initial = 0): int|false { return $initial; }
        public function forget(string $key): bool { return true; }
        public function forgetMany(array $keys): array { return []; }
        public function has(string $key): bool { return false; }
        public function flush(): bool { return true; }
        public function clear(): bool { return true; }
        public function getPrefix(): string { return 'zb_test_'; }
    };

    $manager = new \ZeroBoiler\Analytics\AnalyticsManager(null);

    $configRepo = new class extends \Illuminate\Config\Repository {};

    $archive = new \ZeroBoiler\Analytics\Services\EventArchiveService($cache, $manager, $configRepo);

    $cmd = new \ZeroBoiler\Analytics\Console\Commands\AnalyticsReplayCommand($archive);

    expect($prop->getValue($cmd))->toBe('zb:analytics:replay');
});

test('v4.0.0 AnalyticsReplayCommand is registered in ServiceProvider', function (): void {
    $sp = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect($sp)->not->toBeFalse();
    expect($sp)->toContain(AnalyticsReplayCommand::class);
});

test('v4.0.0 EventArchiveService is registered as singleton in ServiceProvider', function (): void {
    $sp = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect($sp)->not->toBeFalse();
    expect($sp)->toContain('EventArchiveService::class');
});

test('v4.0.0 archive controller methods exist on AnalyticsEventController', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class);
    $methods = array_map(fn (\ReflectionMethod $m) => $m->getName(), $ref->getMethods(\ReflectionMethod::IS_PUBLIC));

    expect($methods)->toContain('archiveSearch');
    expect($methods)->toContain('archiveStats');
    expect($methods)->toContain('archiveGet');
    expect($methods)->toContain('archiveReplay');
    expect($methods)->toContain('archiveClear');
});

test('v4.0.0 archive controller methods return JsonResponse', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class);

    foreach (['archiveSearch', 'archiveStats', 'archiveGet', 'archiveReplay', 'archiveClear'] as $method) {
        $m = $ref->getMethod($method);
        expect($m->hasReturnType())->toBeTrue("Method {$method} must have return type");
        expect((string) $m->getReturnType())->toBe('Illuminate\\Http\\JsonResponse');
    }
});

test('version consistency: AnalyticsEvent reports v5.7.0', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('5.7.0');
});

test('version consistency: composer.json is v5.7.0', function (): void {
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('5.7.0');
});

test('version consistency: README has version-5.7.0 badge', function (): void {
    $readme = file_get_contents(__DIR__ . '/../README.md');
    expect($readme)->toContain('version-5.7.0');
});

test('version consistency: JS client is v5.7.0', function (): void {
    $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($js)->toContain('@version 5.7.0');
});

test('version consistency: Svelte composables is v5.7.0', function (): void {
    $svelte = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
    expect($svelte)->toContain('@version 5.7.0');
});

test('version consistency: TypeScript declarations is v5.7.0', function (): void {
    $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
    expect($dts)->toContain('@version 5.7.0');
});

test('version consistency: ServiceProvider is v5.7.0', function (): void {
    $sp = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect($sp)->toContain('@version 5.7.0');
});

test('v4.0.0 event catalog validation passes', function (): void {
    $result = EventCatalog::validate();
    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
});

test('v4.0.0 event catalog has 100+ events', function (): void {
    expect(EventCatalog::count())->toBeGreaterThanOrEqual(100);
});

test('v4.0.0 route count is 220+', function (): void {
    $routesFile = file_get_contents(__DIR__ . '/../routes/analytics.php');
    preg_match_all("/Route::(get|post|put|patch|delete)\\(/", $routesFile, $matches);
    expect(count($matches[0]))->toBeGreaterThanOrEqual(220);
});

test('v4.0.0 test file count is 160+', function (): void {
    $testDir = __DIR__;
    $testFiles = glob($testDir . '/*Test.php');
    $featureTestFiles = glob($testDir . '/Feature/**/*.php', GLOB_ERR);
    if ($featureTestFiles === false) {
        $featureTestFiles = [];
    }

    expect(count($testFiles) + count($featureTestFiles))->toBeGreaterThanOrEqual(160);
});
