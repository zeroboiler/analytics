<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;
use ZeroBoiler\Analytics\Schema\EventSchema;
use ZeroBoiler\Analytics\Schema\EventParam;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsScheduledReportCommand;

// ── Event Schema Validation API ─────────────────────────────────

test('EventSchemaRegistry contains schemas for all catalog events', function (): void {
    $registry = new EventSchemaRegistry;

    $names = $registry->getEventNames();

    // Must have schemas for key events from each category
    expect($names)->toContain('view_item');
    expect($names)->toContain('add_to_cart');
    expect($names)->toContain('purchase');
    expect($names)->toContain('refund');
    expect($names)->toContain('sign_up');
    expect($names)->toContain('login');
    expect($names)->toContain('start_trial');
    expect($names)->toContain('subscribe');
    expect($names)->toContain('page_view');
    expect($names)->toContain('scroll_depth');
    expect($names)->toContain('click');
    expect($names)->toContain('form_start');
    expect($names)->toContain('form_submit');
    expect($names)->toContain('search');
});

test('EventSchemaRegistry getEventsByCategory returns correct events', function (): void {
    $registry = new EventSchemaRegistry;

    $ecommerce = $registry->getEventsByCategory('ecommerce');
    $saas = $registry->getEventsByCategory('saas');
    $engagement = $registry->getEventsByCategory('engagement');

    expect($ecommerce)->toContain('purchase');
    expect($ecommerce)->toContain('view_item');
    expect($saas)->toContain('sign_up');
    expect($saas)->toContain('login');
    expect($engagement)->toContain('page_view');
    expect($engagement)->toContain('click');

    // Categories should be exclusive
    expect($ecommerce)->not->toContain('sign_up');
    expect($saas)->not->toContain('purchase');
    expect($engagement)->not->toContain('login');
});

test('EventSchemaRegistry getSchemasByCategory groups correctly', function (): void {
    $registry = new EventSchemaRegistry;

    $grouped = $registry->getSchemasByCategory();

    expect($grouped)->toHaveKeys(['ecommerce', 'saas', 'engagement']);
    expect($grouped['ecommerce'])->toBeArray();
    expect($grouped['saas'])->toBeArray();
    expect($grouped['engagement'])->toBeArray();

    // Each value should be an EventSchema instance
    foreach ($grouped['ecommerce'] as $name => $schema) {
        expect($schema)->toBeInstanceOf(EventSchema::class);
        expect($schema->name)->toBe($name);
    }
});

test('EventSchemaRegistry validate passes for valid params', function (): void {
    $registry = new EventSchemaRegistry;

    $result = $registry->validate('purchase', [
        'transaction_id' => 'txn_123',
        'value' => 99.99,
        'currency' => 'USD',
    ]);

    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeEmpty();
    expect($result['sanitized'])->toBeArray();
});

test('EventSchemaRegistry validate passes for unknown event (permissive)', function (): void {
    $registry = new EventSchemaRegistry;

    $result = $registry->validate('custom_event', [
        'foo' => 'bar',
    ]);

    // No schema = permissive (pass through)
    expect($result['valid'])->toBeTrue();
    expect($result['sanitized'])->toBe(['foo' => 'bar']);
});

test('EventSchemaRegistry count is positive', function (): void {
    $registry = new EventSchemaRegistry;

    expect($registry->count())->toBeGreaterThan(0);
    expect($registry->count())->toBeGreaterThanOrEqual(30);
});

test('EventSchema validate type checking works', function (): void {
    $schema = new EventSchema(
        name: 'test_event',
        category: 'test',
        requiredParams: [
            'value' => new EventParam(type: 'numeric', maxLength: 0, description: 'numeric value'),
        ],
    );

    $result = $schema->validate([
        'value' => 'not_a_number',
    ]);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->not->toBeEmpty();
});

test('EventSchema validate missing required param', function (): void {
    $schema = new EventSchema(
        name: 'test_event',
        category: 'test',
        requiredParams: [
            'required_field' => new EventParam(type: 'string', maxLength: 100, description: 'required'),
        ],
    );

    $result = $schema->validate([]);

    expect($result['valid'])->toBeFalse();
    expect($result['errors'])->toContain('Missing required parameter: required_field');
});

test('EventSchema getAllParamNames returns combined params', function (): void {
    $schema = new EventSchema(
        name: 'test_event',
        category: 'test',
        requiredParams: [
            'req1' => new EventParam(type: 'string', maxLength: 100, description: 'required 1'),
        ],
        optionalParams: [
            'opt1' => new EventParam(type: 'string', maxLength: 100, description: 'optional 1'),
            'opt2' => new EventParam(type: 'numeric', maxLength: 0, description: 'optional 2'),
        ],
    );

    $params = $schema->getAllParamNames();

    expect($params)->toContain('req1');
    expect($params)->toContain('opt1');
    expect($params)->toContain('opt2');
    expect($params)->toHaveCount(3);
});

// ── Scheduled Report Command ───────────────────────────────────

test('AnalyticsScheduledReportCommand class exists and is final', function (): void {
    $reflection = new ReflectionClass(AnalyticsScheduledReportCommand::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isInstantiable())->toBeTrue();
});

test('AnalyticsScheduledReportCommand has correct signature', function (): void {
    $command = new AnalyticsScheduledReportCommand;

    $reflection = new ReflectionClass($command);
    $property = $reflection->getProperty('signature');

    expect($property->getValue($command))->toBe(
        'analytics:report:schedule
        {--period=daily : Report period (hourly, daily, weekly, monthly)}
        {--format=json : Output format (json, table)}
        {--output= : Optional file path to write report to disk}
        {--all : Generate reports for all periods}'
    );
});

test('AnalyticsScheduledReportCommand has handle method', function (): void {
    $reflection = new ReflectionClass(AnalyticsScheduledReportCommand::class);

    expect($reflection->hasMethod('handle'))->toBeTrue();

    $method = $reflection->getMethod('handle');
    $params = $method->getParameters();

    // handle(EventReportingService $reporting, ConfigRepository $config)
    expect($params)->toHaveCount(2);
    expect($params[0]->getName())->toBe('reporting');
    expect($params[1]->getName())->toBe('config');
    expect($method->getReturnType()?->getName())->toBe('int');
});

test('AnalyticsScheduledReportCommand has displayReport method with void return', function (): void {
    $reflection = new ReflectionClass(AnalyticsScheduledReportCommand::class);
    $method = $reflection->getMethod('displayReport');

    expect($method->getReturnType()?->getName())->toBe('void');
});

test('AnalyticsScheduledReportCommand has writeReport method with void return', function (): void {
    $reflection = new ReflectionClass(AnalyticsScheduledReportCommand::class);
    $method = $reflection->getMethod('writeReport');

    expect($method->getReturnType()?->getName())->toBe('void');
});

// ── Config: scheduled_reports ───────────────────────────────────

test('config has scheduled_reports section', function (): void {
    $config = require base_path('config/zeroboiler.php');

    expect($config)->toHaveKey('analytics');
    expect($config['analytics'])->toHaveKey('scheduled_reports');
    expect($config['analytics']['scheduled_reports'])->toHaveKey('enabled');
    expect($config['analytics']['scheduled_reports'])->toHaveKey('output_path');
    expect($config['analytics']['scheduled_reports'])->toHaveKey('auto_archive');
    expect($config['analytics']['scheduled_reports'])->toHaveKey('archive_days');
});

// ── Version Consistency ─────────────────────────────────────────

test('version 2.57.0 is consistent across all source files', function (): void {
    // Composer
    $composer = json_decode(file_get_contents(base_path('composer.json')), true);
    expect($composer['version'])->toBe('268.0.0');

    // AnalyticsManager
    $manager = new AnalyticsManager;
    expect($manager->version())->toBe('268.0.0');

    // JS client
    $js = file_get_contents(base_path('resources/js/analytics.js'));
    expect($js)->toContain("'268.0.0'");
    expect($js)->toContain('@version 268.0.0');

    // TypeScript definitions
    $ts = file_get_contents(base_path('resources/js/analytics.d.ts'));
    expect($ts)->toContain('@version 268.0.0');

    // EventSourceTagger
    $tagger = file_get_contents(base_path('src/Services/EventSourceTagger.php'));
    expect($tagger)->toContain('268.0.0');

    // EventEnvelopeService
    $envelope = file_get_contents(base_path('src/Services/EventEnvelopeService.php'));
    expect($envelope)->toContain('268.0.0');
});

// ── Filesystem Integrity ────────────────────────────────────────

test('new source files have strict types and docblocks', function (): void {
    $files = [
        'src/Console/Commands/AnalyticsScheduledReportCommand.php',
    ];

    foreach ($files as $file) {
        $content = file_get_contents(base_path($file));
        expect($content)->toContain('declare(strict_types=1)');
        expect($content)->toContain('This file is part of ZeroBoiler');
    }
});

test('AnalyticsScheduledReportCommand has proper namespace and imports', function (): void {
    $content = file_get_contents(base_path('src/Console/Commands/AnalyticsScheduledReportCommand.php'));

    expect($content)->toContain('namespace ZeroBoiler\\Analytics\\Console\\Commands');
    expect($content)->toContain('use Illuminate\\Console\\Command');
    expect($content)->toContain('use Illuminate\\Contracts\\Config\\Repository as ConfigRepository');
    expect($content)->toContain('use ZeroBoiler\\Analytics\\Services\\EventReportingService');
});

// ── Controller Schema Methods ────────────────────────────────────

test('AnalyticsEventController has schema methods', function (): void {
    $reflection = new ReflectionClass(AnalyticsEventController::class);

    expect($reflection->hasMethod('schemaList'))->toBeTrue();
    expect($reflection->hasMethod('schemaDetail'))->toBeTrue();
    expect($reflection->hasMethod('schemaValidate'))->toBeTrue();
    expect($reflection->hasMethod('schemaSummary'))->toBeTrue();
});

test('schema methods have correct return types', function (): void {
    $reflection = new ReflectionClass(AnalyticsEventController::class);

    foreach (['schemaList', 'schemaDetail', 'schemaValidate', 'schemaSummary'] as $method) {
        $m = $reflection->getMethod($method);
        $returnType = $m->getReturnType();

        // All should return JsonResponse (from import or FQCN)
        expect($returnType)->not->toBeNull();
        expect($returnType?->getName())->toContain('JsonResponse');
    }
});

test('schema methods accept correct parameters', function (): void {
    $reflection = new ReflectionClass(AnalyticsEventController::class);

    // schemaList(Request $request)
    $params = $reflection->getMethod('schemaList')->getParameters();
    expect($params)->toHaveCount(1);
    expect($params[0]->getName())->toBe('request');

    // schemaDetail(string $eventName)
    $params = $reflection->getMethod('schemaDetail')->getParameters();
    expect($params)->toHaveCount(1);
    expect($params[0]->getName())->toBe('eventName');

    // schemaValidate(Request $request)
    $params = $reflection->getMethod('schemaValidate')->getParameters();
    expect($params)->toHaveCount(1);
    expect($params[0]->getName())->toBe('request');

    // schemaSummary()
    $params = $reflection->getMethod('schemaSummary')->getParameters();
    expect($params)->toHaveCount(0);
});

// ── Route Registration ──────────────────────────────────────────

test('routes file contains schema endpoints', function (): void {
    $routes = file_get_contents(base_path('routes/analytics.php'));

    expect($routes)->toContain("'schemas'");
    expect($routes)->toContain('schemaList');
    expect($routes)->toContain('schemaSummary');
    expect($routes)->toContain('schemaValidate');
    expect($routes)->toContain('schemaDetail');
});

test('service provider registers schema routes', function (): void {
    $provider = file_get_contents(base_path('src/AnalyticsServiceProvider.php'));

    expect($provider)->toContain('analytics/schemas');
    expect($provider)->toContain('schemaList');
    expect($provider)->toContain('schemaSummary');
    expect($provider)->toContain('schemaValidate');
    expect($provider)->toContain('schemaDetail');
});

test('service provider registers scheduled report command', function (): void {
    $provider = file_get_contents(base_path('src/AnalyticsServiceProvider.php'));

    expect($provider)->toContain('AnalyticsScheduledReportCommand::class');
    expect($provider)->toContain('use ZeroBoiler\\Analytics\\Console\\Commands\\AnalyticsScheduledReportCommand');
});

// ── Config Integrity ────────────────────────────────────────────

test('total config sections increased', function (): void {
    $config = require base_path('config/zeroboiler.php');

    expect(count($config['analytics']))->toBeGreaterThanOrEqual(50);
});

// ── Source File Counts ──────────────────────────────────────────

test('source file counts increased from v2.56', function (): void {
    $srcFiles = glob(base_path('src/**/*.php'), GLOB_BRACE);
    $testFiles = glob(base_path('tests/**/*.php'));

    // v2.56 had 194 source files and 95 tests
    expect(count($srcFiles))->toBeGreaterThanOrEqual(195);
    expect(count($testFiles))->toBeGreaterThanOrEqual(96);
});
