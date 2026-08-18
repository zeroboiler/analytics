<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Console\Commands\AnalyticsSaaSQuickAuditCommand;
use ZeroBoiler\Analytics\Services\SaaSStarterQuickAuditService;

beforeEach(function (): void {
    // Mock config repository
    $this->config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);

    // Return sensible defaults for all config reads
    $this->config->shouldReceive('get')
        ->andReturnUsing(function (string $key, mixed $default = null) {
            $defaults = [
                'zeroboiler.analytics.lifecycle' => [
                    'enabled' => true,
                    'queue_events' => false,
                    'enrich_attribution' => true,
                    'custom_mappings' => [],
                ],
                'zeroboiler.analytics.auto_track' => [
                    'enabled' => true,
                    'events' => [
                        'auth.login' => true,
                        'auth.register' => true,
                        'auth.logout' => false,
                        'subscription.created' => true,
                        'subscription.upgraded' => true,
                        'subscription.cancelled' => true,
                        'trial.started' => true,
                        'trial.ended' => false,
                        'feature.used' => false,
                    ],
                    'event_map' => [],
                ],
                'zeroboiler.analytics.identity' => [
                    'cookie_name' => 'zb_analytics_id',
                    'cookie_ttl' => 525600,
                    'link_on_auth' => true,
                    'auto_link' => true,
                    'cache_prefix' => 'zb_identity_',
                    'link_ttl' => 7776000,
                ],
                'zeroboiler.analytics.consent' => [
                    'default' => 'granted',
                    'purposes' => [
                        'necessary' => ['label' => 'Necessary', 'required' => true],
                        'analytics' => ['label' => 'Analytics', 'required' => false],
                        'marketing' => ['label' => 'Marketing', 'required' => false],
                        'functional' => ['label' => 'Functional', 'required' => false],
                    ],
                ],
                'zeroboiler.analytics.client_auto_track' => [
                    'page_views' => true,
                    'scroll_depth' => true,
                    'form_tracking' => true,
                    'error_tracking' => true,
                    'link_tracking' => false,
                    'session_tracking' => true,
                    'idle_timeout' => 1800,
                    'error_ignore_patterns' => [],
                ],
                'zeroboiler.analytics.api' => [
                    'enabled' => true,
                    'rate_limit' => 120,
                    'batch_max_size' => 25,
                    'require_auth' => true,
                    'sdk_token' => null,
                ],
                'zeroboiler.analytics.queue' => [
                    'enabled' => true,
                    'queue' => 'analytics',
                    'connection' => null,
                    'max_batch_size' => 50,
                ],
                'zeroboiler.analytics.ecommerce' => [
                    'currency' => 'USD',
                    'tax_behavior' => 'inclusive',
                    'shipping_default' => 0.0,
                ],
                'zeroboiler.analytics.checkout_tracking' => [
                    'enabled' => true,
                ],
                'zeroboiler.analytics.analytics' => [],
            ];

            return $defaults[$key] ?? $default;
        });

    $this->service = new SaaSStarterQuickAuditService($this->config);
});

test('service is final class with MIT header', function (): void {
    $reflector = new ReflectionClass(SaaSStarterQuickAuditService::class);
    expect($reflector->isFinal())->toBeTrue();
    expect($reflector->getDocComment())->toContain('This file is part of ZeroBoiler');
});

test('service constructor has void return type', function (): void {
    $reflector = new ReflectionClass(SaaSStarterQuickAuditService::class);
    $ctor = $reflector->getConstructor();
    expect($ctor)->not->toBeNull();
    expect($ctor->getReturnType()?->getName())->toBe('void');
});

test('audit returns all required keys', function (): void {
    $result = $this->service->audit();

    expect($result)->toHaveKeys([
        'score', 'grade', 'features', 'gaps', 'summary',
    ]);
});

test('audit returns 12 features', function (): void {
    $result = $this->service->audit();

    expect(count($result['features']))->toBe(12);
});

test('audit features have correct structure', function (): void {
    $result = $this->service->audit();

    foreach ($result['features'] as $key => $feature) {
        expect($feature)->toHaveKeys(['label', 'score', 'max', 'weight', 'checks']);
        expect($feature['max'])->toBe(10);
        expect($feature['weight'])->toBeGreaterThan(0);
        expect(count($feature['checks']))->toBeGreaterThan(0);
        foreach ($feature['checks'] as $check) {
            expect($check)->toHaveKeys(['pass', 'description']);
            expect(is_bool($check['pass']))->toBeTrue();
            expect(is_string($check['description']))->toBeTrue();
        }
    }
});

test('audit score is between 0 and 100', function (): void {
    $result = $this->service->audit();

    expect($result['score'])->toBeGreaterThanOrEqual(0.0);
    expect($result['score'])->toBeLessThanOrEqual(100.0);
});

test('audit grade is a valid letter grade', function (): void {
    $result = $this->service->audit();

    $validGrades = ['A+', 'A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D+', 'D', 'D-', 'F'];
    expect(in_array($result['grade'], $validGrades, true))->toBeTrue();
});

test('audit summary has correct structure', function (): void {
    $result = $this->service->audit();
    $s = $result['summary'];

    expect($s)->toHaveKeys([
        'total_checks', 'passed', 'failed', 'warnings',
        'feature_count', 'catalog_events', 'starter_coverage',
    ]);
    expect($s['feature_count'])->toBe(12);
    expect($s['total_checks'])->toBeGreaterThan(0);
    expect($s['passed'] + $s['failed'])->toBe($s['total_checks']);
    expect($s['catalog_events'])->toBeGreaterThan(0);
});

test('gaps have correct structure', function (): void {
    $result = $this->service->audit();

    foreach ($result['gaps'] as $gap) {
        expect($gap)->toHaveKeys(['feature', 'severity', 'finding', 'remediation']);
        expect(in_array($gap['severity'], ['critical', 'warning', 'info'], true))->toBeTrue();
    }
});

test('quickScore returns score and grade', function (): void {
    $quick = $this->service->quickScore();

    expect($quick)->toHaveKeys(['score', 'grade']);
    expect($quick['score'])->toBeGreaterThanOrEqual(0.0);
    expect($quick['score'])->toBeLessThanOrEqual(100.0);
    expect(strlen($quick['grade']))->toBeGreaterThanOrEqual(1);
});

test('quickScore and audit return same score', function (): void {
    $audit = $this->service->audit();
    $quick = $this->service->quickScore();

    expect($quick['score'])->toBe($audit['score']);
    expect($quick['grade'])->toBe($audit['grade']);
});

test('feature definitions total weight equals 1.0', function (): void {
    $total = SaaSStarterQuickAuditService::totalWeight();

    expect(abs($total - 1.0))->toBeLessThan(0.001);
});

test('feature definitions have 12 entries', function (): void {
    $defs = SaaSStarterQuickAuditService::featureDefinitions();

    expect(count($defs))->toBe(12);
    foreach ($defs as $key => $def) {
        expect($def)->toHaveKeys(['label', 'weight', 'max']);
        expect($def['max'])->toBe(10);
        expect($def['weight'])->toBeGreaterThan(0);
        expect(is_string($def['label']))->toBeTrue();
    }
});

test('command class exists and is final', function (): void {
    expect(class_exists(AnalyticsSaaSQuickAuditCommand::class))->toBeTrue();
    $reflector = new ReflectionClass(AnalyticsSaaSQuickAuditCommand::class);
    expect($reflector->isFinal())->toBeTrue();
});

test('command signature contains expected options', function (): void {
    $reflector = new ReflectionClass(AnalyticsSaaSQuickAuditCommand::class);
    $props = $reflector->getProperty('signature');
    $signature = $props->getValue(new AnalyticsSaaSQuickAuditCommand(
        Mockery::mock(SaaSStarterQuickAuditService::class)
    ));

    expect($signature)->toContain('zb:analytics:saas-audit');
    expect($signature)->toContain('--json');
    expect($signature)->toContain('--gaps');
    expect($signature)->toContain('--score');
    expect($signature)->toContain('--gates');
});

test('event catalog audit checks work', function (): void {
    $result = $this->service->audit();
    $catalog = $result['features']['event_catalog'];

    expect($catalog['label'])->toBe('Event Catalog');
    expect($catalog['weight'])->toBe(0.12);
    expect(count($catalog['checks']))->toBe(5);
});

test('starter coverage in summary matches SaaSStarterEvents', function (): void {
    $result = $this->service->audit();
    $expected = \ZeroBoiler\Analytics\Events\SaaSStarterEvents::coveragePercent();

    expect($result['summary']['starter_coverage'])->toBe($expected);
});

test('config with everything disabled scores lower', function (): void {
    // Config that returns empty arrays for everything
    $emptyConfig = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
    $emptyConfig->shouldReceive('get')->andReturn([]);

    $strictService = new SaaSStarterQuickAuditService($emptyConfig);
    $result = $strictService->audit();

    // With empty config, score should be lower (some checks still pass from class/file existence)
    expect($result['score'])->toBeLessThan($this->service->audit()['score']);
});

test('isProductionReady returns bool', function (): void {
    $ready = $this->service->isProductionReady();

    expect(is_bool($ready))->toBeTrue();
});

test('source file counts are above minimums', function (): void {
    $srcFiles = glob(__DIR__ . '/../src/**/*.php', GLOB_BRACE);
    $testFiles = glob(__DIR__ . '/../tests/*Test.php');

    expect(is_array($srcFiles) ? count($srcFiles) : 0)->toBeGreaterThanOrEqual(980);
    expect(is_array($testFiles) ? count($testFiles) : 0)->toBeGreaterThanOrEqual(498);
});

test('version consistency at 253.0.0', function (): void {
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    $dtoVersion = \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION;
    $jsContent = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    $pkg = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);

    expect($composer['version'])->toBe('253.0.0');
    expect($dtoVersion)->toBe('253.0.0');
    expect($pkg['version'])->toBe('253.0.0');
    expect(str_contains($jsContent, '@version 253.0.0'))->toBeTrue();
});
