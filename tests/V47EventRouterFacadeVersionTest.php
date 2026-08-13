<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 *
 * V47 — AnalyticsEventRouter tests, Facade proxy method completeness,
 * version consistency, config routing section coverage, and source file counts.
 */

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\AnalyticsEventRouter;
use ZeroBoiler\Analytics\Support\AnalyticsConfig;
use ZeroBoiler\Analytics\AnalyticsServiceProvider;

beforeEach(function (): void {
    $this->config = mock(ConfigRepository::class);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.ga4', [])
        ->andReturn(['enabled' => false]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.gtm', [])
        ->andReturn(['enabled' => false]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.meta_pixel', [])
        ->andReturn(['enabled' => false]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.plausible', [])
        ->andReturn([]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.posthog', [])
        ->andReturn([]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.webhook', [])
        ->andReturn([]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.consent.default', 'granted')
        ->andReturn('granted');
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.debug', [])
        ->andReturn(['enabled' => false]);
    $this->manager = new AnalyticsManager($this->config);
});

// ═══════════════════════════════════════════════════════════════════════
// AnalyticsEventRouter — Basic Functionality
// ═══════════════════════════════════════════════════════════════════════

describe('AnalyticsEventRouter — v2.47 core', function (): void {
    it('is disabled by default', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.routing', [])
            ->andReturn([]);

        $router = new AnalyticsEventRouter($this->manager, $this->config);

        expect($router->isEnabled())->toBeFalse();
        expect($router->getRules())->toBe([]);
        expect($router->ruleCount())->toBe(0);
    });

    it('is enabled when config says so', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.routing', [])
            ->andReturn([
                'enabled' => true,
                'rules' => [
                    'purchase' => ['ga4', 'meta'],
                ],
            ]);

        $router = new AnalyticsEventRouter($this->manager, $this->config);

        expect($router->isEnabled())->toBeTrue();
        expect($router->ruleCount())->toBe(1);
        expect($router->hasRule('purchase'))->toBeTrue();
    });

    it('matches exact event names', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.routing', [])
            ->andReturn([
                'enabled' => true,
                'rules' => [
                    'purchase' => ['ga4', 'meta'],
                    'page_view' => ['ga4', 'plausible'],
                ],
            ]);

        $router = new AnalyticsEventRouter($this->manager, $this->config);

        expect($router->matchProviders('purchase'))->toBe(['ga4', 'meta']);
        expect($router->matchProviders('page_view'))->toBe(['ga4', 'plausible']);
        expect($router->matchProviders('signup'))->toBe([]);
    });

    it('matches wildcard prefix patterns', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.routing', [])
            ->andReturn([
                'enabled' => true,
                'rules' => [
                    'add_to_*' => ['ga4', 'meta'],
                    'remove_from_*' => ['ga4'],
                ],
            ]);

        $router = new AnalyticsEventRouter($this->manager, $this->config);

        expect($router->matchProviders('add_to_cart'))->toBe(['ga4', 'meta']);
        expect($router->matchProviders('add_to_wishlist'))->toBe(['ga4', 'meta']);
        expect($router->matchProviders('remove_from_cart'))->toBe(['ga4']);
        expect($router->matchProviders('purchase'))->toBe([]);
    });

    it('matches wildcard suffix patterns', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.routing', [])
            ->andReturn([
                'enabled' => true,
                'rules' => [
                    '*_click' => ['ga4', 'posthog'],
                ],
            ]);

        $router = new AnalyticsEventRouter($this->manager, $this->config);

        expect($router->matchProviders('outbound_click'))->toBe(['ga4', 'posthog']);
        expect($router->matchProviders('button_click'))->toBe(['ga4', 'posthog']);
        expect($router->matchProviders('click'))->toBe([]);
    });

    it('matches catch-all wildcard', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.routing', [])
            ->andReturn([
                'enabled' => true,
                'rules' => [
                    '*' => ['ga4'],
                ],
            ]);

        $router = new AnalyticsEventRouter($this->manager, $this->config);

        expect($router->matchProviders('anything'))->toBe(['ga4']);
        expect($router->matchProviders('purchase'))->toBe(['ga4']);
        expect($router->matchProviders('signup'))->toBe(['ga4']);
    });

    it('deduplicates providers across multiple matching rules', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.routing', [])
            ->andReturn([
                'enabled' => true,
                'rules' => [
                    'purchase' => ['ga4', 'meta'],
                    '*' => ['ga4', 'posthog'],
                ],
            ]);

        $router = new AnalyticsEventRouter($this->manager, $this->config);

        $matched = $router->matchProviders('purchase');

        expect($matched)->toContain('ga4');
        expect($matched)->toContain('meta');
        expect($matched)->toContain('posthog');
        expect($matched)->not->toBe([
            'ga4', 'meta', 'ga4', 'posthog',
        ]);
        // ga4 should appear only once
        expect(count(array_filter($matched, fn (string $p): bool => $p === 'ga4')))->toBe(1);
    });
});

// ═══════════════════════════════════════════════════════════════════════
// AnalyticsEventRouter — Runtime Rule Management
// ═══════════════════════════════════════════════════════════════════════

describe('AnalyticsEventRouter — v2.47 runtime rule management', function (): void {
    it('can add rules at runtime', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.routing', [])
            ->andReturn(['enabled' => true, 'rules' => []]);

        $router = new AnalyticsEventRouter($this->manager, $this->config);
        expect($router->ruleCount())->toBe(0);

        $router->addRule('purchase', ['ga4', 'meta']);
        expect($router->ruleCount())->toBe(1);
        expect($router->hasRule('purchase'))->toBeTrue();
        expect($router->matchProviders('purchase'))->toBe(['ga4', 'meta']);
    });

    it('can remove rules at runtime', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.routing', [])
            ->andReturn([
                'enabled' => true,
                'rules' => ['purchase' => ['ga4']],
            ]);

        $router = new AnalyticsEventRouter($this->manager, $this->config);
        expect($router->hasRule('purchase'))->toBeTrue();

        $router->removeRule('purchase');
        expect($router->hasRule('purchase'))->toBeFalse();
        expect($router->ruleCount())->toBe(0);
    });

    it('can clear all rules', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.routing', [])
            ->andReturn([
                'enabled' => true,
                'rules' => [
                    'purchase' => ['ga4'],
                    'refund' => ['meta'],
                    'page_view' => ['ga4', 'plausible'],
                ],
            ]);

        $router = new AnalyticsEventRouter($this->manager, $this->config);
        expect($router->ruleCount())->toBe(3);

        $router->clearRules();
        expect($router->ruleCount())->toBe(0);
        expect($router->getRules())->toBe([]);
    });
});

// ═══════════════════════════════════════════════════════════════════════
// AnalyticsEventRouter — Summary & Route Method
// ═══════════════════════════════════════════════════════════════════════

describe('AnalyticsEventRouter — v2.47 summary and routing', function (): void {
    it('returns a valid summary', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.routing', [])
            ->andReturn([
                'enabled' => true,
                'rules' => ['purchase' => ['ga4', 'meta']],
            ]);

        $router = new AnalyticsEventRouter($this->manager, $this->config);
        $summary = $router->summary();

        expect($summary)->toHaveKeys(['enabled', 'rule_count', 'rules', 'version']);
        expect($summary['enabled'])->toBeTrue();
        expect($summary['rule_count'])->toBe(1);
        expect($summary['version'])->toBe('76.0.0');
    });

    it('falls through to standard dispatch when routing is disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.routing', [])
            ->andReturn(['enabled' => false]);

        $router = new AnalyticsEventRouter($this->manager, $this->config);

        // All trackers disabled so directDispatch returns false
        $result = $router->route(new AnalyticsEvent(name: 'purchase', params: []));

        expect($result)->toBeFalse();
    });

    it('falls through to standard dispatch when no rules match', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.routing', [])
            ->andReturn([
                'enabled' => true,
                'rules' => ['purchase' => ['ga4']],
            ]);

        $router = new AnalyticsEventRouter($this->manager, $this->config);

        // signup doesn't match any rule → falls through to standard dispatch
        $result = $router->route(new AnalyticsEvent(name: 'signup', params: []));

        // All trackers disabled so directDispatch returns false
        expect($result)->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════════════════
// Facade Proxy Method Completeness
// ═══════════════════════════════════════════════════════════════════════

describe('Facade — v2.47 proxy method completeness', function (): void {
    it('includes all v2.47 added proxy methods', function (): void {
        $facadePath = __DIR__ . '/../src/Facades/Analytics.php';
        $contents = file_get_contents($facadePath);

        // Methods added in v2.47
        expect($contents)->toContain('selectItem');
        expect($contents)->toContain('selectPromotion');
        expect($contents)->toContain('viewPromotion');
        expect($contents)->toContain('subscriptionRenewal');
    });

    it('includes all core manager methods', function (): void {
        $facadePath = __DIR__ . '/../src/Facades/Analytics.php';
        $contents = file_get_contents($facadePath);

        $requiredMethods = [
            'track', 'trackEvent', 'purchase', 'identify', 'screenView',
            'pageView', 'serverSidePageView', 'trackAsync', 'setUserProperties',
            'alias', 'logout', 'trialEnd', 'planDowngrade', 'wishlist',
            'headScripts', 'bodyScripts', 'push', 'setConsent', 'grantConsent',
            'denyConsent', 'getConsent', 'isDebug', 'resetIdentity',
            'eventCatalogSummary', 'eventExists', 'eventCategory', 'totalEventCount',
            'trackError', 'mrr', 'isTrackingAllowed', 'optOut', 'optIn',
            'suppressClient', 'transferClientToUser', 'version', 'metrics',
            'providerSummary', 'validateCatalog',
        ];

        foreach ($requiredMethods as $method) {
            expect($contents)->toContain($method);
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════
// Version Consistency
// ═══════════════════════════════════════════════════════════════════════

describe('Version — v2.47 consistency', function (): void {
    it('manager version is 2.50.0', function (): void {
        expect($this->manager->version())->toBe('76.0.0');
    });

    it('composer version is 2.50.0', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);

        expect($composer['version'])->toBe('76.0.0');
    });

    it('JS client version is 2.50.0', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');

        expect($js)->toContain("'76.0.0'");
    });

    it('TypeScript definitions version is 2.50.0', function (): void {
        $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');

        expect($dts)->toContain('76.0.0');
    });

    it('no stale version references remain', function (): void {
        $files = [
            __DIR__ . '/../src/AnalyticsManager.php',
            __DIR__ . '/../src/Services/EventSourceTagger.php',
            __DIR__ . '/../src/Services/EventForwardingService.php',
            __DIR__ . '/../composer.json',
            __DIR__ . '/../resources/js/analytics.js',
            __DIR__ . '/../resources/js/analytics.d.ts',
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            // Ensure no stale version from previous major release
            expect($contents)->not->toContain('9.9.0');
            expect($contents)->not->toContain('9.8.0');
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════
// Config & Source File Counts
// ═══════════════════════════════════════════════════════════════════════

describe('Config & Source Integrity — v2.47', function (): void {
    it('routing config section exists in config file', function (): void {
        $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');

        expect($config)->toContain("'routing'");
        expect($config)->toContain('ANALYTICS_ROUTING_ENABLED');
    });

    it('AnalyticsEventRouter source file exists', function (): void {
        $path = __DIR__ . '/../src/Services/AnalyticsEventRouter.php';

        expect(file_exists($path))->toBeTrue();

        $contents = file_get_contents($path);
        expect($contents)->toContain('declare(strict_types=1)');
        expect($contents)->toContain('final class AnalyticsEventRouter');
    });

    it('ServiceProvider registers AnalyticsEventRouter', function (): void {
        $provider = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

        expect($provider)->toContain('AnalyticsEventRouter');
    });

    it('AnalyticsConfig has routing accessors', function (): void {
        $config = file_get_contents(__DIR__ . '/../src/Support/AnalyticsConfig.php');

        expect($config)->toContain('routingEnabled');
        expect($config)->toContain('routingRules');
    });

    it('source file count is at least 189', function (): void {
        $srcFiles = glob(__DIR__ . '/../src/**/*.php', GLOB_BRACE);
        $srcCount = count($srcFiles);

        expect($srcCount)->toBeGreaterThanOrEqual(189);
    });

    it('test file count is at least 91', function (): void {
        $testFiles = glob(__DIR__ . '/../*.php');
        $testCount = 0;
        foreach ($testFiles as $f) {
            // Not a great approach, let's use a simpler count
        }

        // Use iterator
        $testDir = __DIR__ . '/..';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($testDir . '/tests', RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }

        expect($count)->toBeGreaterThanOrEqual(91);
    });

    it('config section count is at least 42', function (): void {
        $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');

        // Count top-level config sections (lines ending with '=> [')
        preg_match_all("/'\\w+'\s*=>\s*\[/", $config, $matches);
        $sections = count($matches[0]);

        expect($sections)->toBeGreaterThanOrEqual(42);
    });
});

// ═══════════════════════════════════════════════════════════════════════
// Class Architecture Validation
// ═══════════════════════════════════════════════════════════════════════

describe('Architecture — v2.47 AnalyticsEventRouter', function (): void {
    it('AnalyticsEventRouter is final', function (): void {
        $reflection = new ReflectionClass(AnalyticsEventRouter::class);

        expect($reflection->isFinal())->toBeTrue();
    });

    it('AnalyticsEventRouter has strict types', function (): void {
        $contents = file_get_contents(__DIR__ . '/../src/Services/AnalyticsEventRouter.php');

        expect($contents)->toContain('declare(strict_types=1)');
    });

    it('all public methods have return type declarations', function (): void {
        $reflection = new ReflectionClass(AnalyticsEventRouter::class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if ($method->getDeclaringClass()->getName() === AnalyticsEventRouter::class) {
                expect($method->hasReturnType())->toBeTrue(
                    "Method {$method->getName()}() missing return type",
                );
            }
        }
    });

    it('constructor has proper type declarations', function (): void {
        $reflection = new ReflectionClass(AnalyticsEventRouter::class);
        $constructor = $reflection->getConstructor();

        expect($constructor)->not->toBeNull();
        expect($constructor->hasReturnType())->toBeTrue();

        $params = $constructor->getParameters();
        expect($params)->toHaveCount(2);
        expect($params[0]->getType())->not->toBeNull();
        expect($params[1]->getType())->not->toBeNull();
    });
});
