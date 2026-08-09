<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Http\Controllers\AnalyticsSSEController;
use ZeroBoiler\Analytics\Services\EventStreamService;
use ZeroBoiler\Analytics\Services\EventWindowAggregator;
use ZeroBoiler\Analytics\Services\FeatureAdoptionTracker;
use ZeroBoiler\Analytics\Services\AnalyticsApiGuard;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

describe('v2.95.0 — SSE, Windowed Aggregation, Feature Adoption, API Guard', function (): void {

    describe('AnalyticsSSEController', function (): void {
        it('has stream, info, and health methods with correct signatures', function (): void {
            $reflection = new ReflectionClass(AnalyticsSSEController::class);

            expect($reflection->hasMethod('stream'))->toBeTrue();
            expect($reflection->hasMethod('info'))->toBeTrue();
            expect($reflection->hasMethod('health'))->toBeTrue();

            $stream = $reflection->getMethod('stream');
            expect($stream->getNumberOfParameters())->toBe(1);
            expect($stream->getParameters()[0]->getName())->toBe('request');

            $info = $reflection->getMethod('info');
            expect($info->getNumberOfParameters())->toBe(0);

            $health = $reflection->getMethod('health');
            expect($health->getNumberOfParameters())->toBe(0);
        });

        it('is final and uses correct namespace', function (): void {
            $reflection = new ReflectionClass(AnalyticsSSEController::class);
            expect($reflection->isFinal())->toBeTrue();
            expect($reflection->getNamespaceName())->toBe('ZeroBoiler\\Analytics\\Http\\Controllers');
        });

        it('constructs with EventStreamService', function (): void {
            $constructor = (new ReflectionClass(AnalyticsSSEController::class))->getConstructor();
            expect($constructor)->not->toBeNull();
            expect($constructor->getNumberOfParameters())->toBe(1);
            $param = $constructor->getParameters()[0];
            expect($param->getName())->toBe('streamService');
            expect($param->getType()?->getName())->toBe(EventStreamService::class);
        });
    });

    describe('EventStreamService — SSE support methods', function (): void {
        it('has getEventsSince, getBufferSize, getCurrentCount, getCurrentCursor, getBufferUtilization', function (): void {
            $reflection = new ReflectionClass(EventStreamService::class);

            $methods = ['getEventsSince', 'getBufferSize', 'getCurrentCount', 'getCurrentCursor', 'getBufferUtilization'];
            foreach ($methods as $method) {
                expect($reflection->hasMethod($method))->toBeTrue("Missing method: {$method}");
            }
        });

        it('getBufferUtilization returns float type', function (): void {
            $method = new ReflectionMethod(EventStreamService::class, 'getBufferUtilization');
            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull();
            expect($returnType->getName())->toBe('float');
        });

        it('getBufferSize returns int type', function (): void {
            $method = new ReflectionMethod(EventStreamService::class, 'getBufferSize');
            expect($method->getReturnType()?->getName())->toBe('int');
        });

        it('getCurrentCount returns int type', function (): void {
            $method = new ReflectionMethod(EventStreamService::class, 'getCurrentCount');
            expect($method->getReturnType()?->getName())->toBe('int');
        });

        it('getEventsSince returns array type', function (): void {
            $method = new ReflectionMethod(EventStreamService::class, 'getEventsSince');
            expect($method->getReturnType()?->getName())->toBe('array');
        });
    });

    describe('EventWindowAggregator', function (): void {
        it('exists in correct namespace with proper structure', function (): void {
            expect(class_exists(\ZeroBoiler\Analytics\Services\EventWindowAggregator::class))->toBeTrue();

            $reflection = new ReflectionClass(\ZeroBoiler\Analytics\Services\EventWindowAggregator::class);
            expect($reflection->isFinal())->toBeTrue();
            expect($reflection->getNamespaceName())->toBe('ZeroBoiler\\Analytics\\Services');
        });

        it('has required public methods with return types', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Analytics\Services\EventWindowAggregator::class);

            $methods = [
                'record' => 'void',
                'currentMinuteCount' => 'int',
                'currentHourCount' => 'int',
                'currentDayCount' => 'int',
                'lastNMinutes' => 'array',
                'lastNHours' => 'array',
                'lastNDays' => 'array',
                'windowSummary' => 'array',
                'allTimeCount' => 'int',
                'topEvents' => 'array',
                'clear' => 'void',
            ];

            foreach ($methods as $method => $returnType) {
                expect($reflection->hasMethod($method))->toBeTrue("Missing method: {$method}");
                $m = $reflection->getMethod($method);
                expect($m->getReturnType()?->getName())->toBe($returnType);
            }
        });

        it('constructor accepts CacheRepository and ConfigRepository', function (): void {
            $constructor = (new ReflectionClass(\ZeroBoiler\Analytics\Services\EventWindowAggregator::class))->getConstructor();
            expect($constructor)->not->toBeNull();
            expect($constructor->getNumberOfParameters())->toBe(2);
        });
    });

    describe('FeatureAdoptionTracker', function (): void {
        it('exists in correct namespace and is final', function (): void {
            expect(class_exists(\ZeroBoiler\Analytics\Services\FeatureAdoptionTracker::class))->toBeTrue();

            $reflection = new ReflectionClass(\ZeroBoiler\Analytics\Services\FeatureAdoptionTracker::class);
            expect($reflection->isFinal())->toBeTrue();
            expect($reflection->getNamespaceName())->toBe('ZeroBoiler\\Analytics\\Services');
        });

        it('has required public methods with correct return types', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Analytics\Services\FeatureAdoptionTracker::class);

            $methods = [
                'recordAdoption' => 'void',
                'getProfile' => 'array',
                'hasAdopted' => 'bool',
                'getStreak' => 'int',
                'adoptionFunnel' => 'array',
                'adoptionCount' => 'int',
                'recentFeatures' => 'array',
                'clearProfile' => 'void',
                'clearAll' => 'void',
            ];

            foreach ($methods as $method => $returnType) {
                expect($reflection->hasMethod($method))->toBeTrue("Missing method: {$method}");
                $m = $reflection->getMethod($method);
                expect($m->getReturnType()?->getName())->toBe($returnType);
            }
        });

        it('recordAdoption accepts userId, featureName, and optional context', function (): void {
            $method = new ReflectionMethod(\ZeroBoiler\Analytics\Services\FeatureAdoptionTracker::class, 'recordAdoption');
            expect($method->getNumberOfParameters())->toBe(3);
            expect($method->getParameters()[2]->getName())->toBe('context');
            expect($method->getParameters()[2]->isDefaultValueAvailable())->toBeTrue();
            expect($method->getParameters()[2]->getDefaultValue())->toBe([]);
        });

        it('adoptionFunnel accepts featureNames and userIds arrays', function (): void {
            $method = new ReflectionMethod(\ZeroBoiler\Analytics\Services\FeatureAdoptionTracker::class, 'adoptionFunnel');
            expect($method->getNumberOfParameters())->toBe(2);

            $params = $method->getParameters();
            expect($params[0]->getName())->toBe('featureNames');
            expect($params[0]->getType()?->getName())->toBe('array');
            expect($params[1]->getName())->toBe('userIds');
            expect($params[1]->getType()?->getName())->toBe('array');
        });
    });

    describe('AnalyticsApiGuard', function (): void {
        it('exists in correct namespace and is final', function (): void {
            expect(class_exists(\ZeroBoiler\Analytics\Services\AnalyticsApiGuard::class))->toBeTrue();

            $reflection = new ReflectionClass(\ZeroBoiler\Analytics\Services\AnalyticsApiGuard::class);
            expect($reflection->isFinal())->toBeTrue();
            expect($reflection->getNamespaceName())->toBe('ZeroBoiler\\Analytics\\Services');
        });

        it('has required public methods with correct return types', function (): void {
            $reflection = new ReflectionClass(\ZeroBoiler\Analytics\Services\AnalyticsApiGuard::class);

            $methods = [
                'validate' => 'array',
                'validateBatch' => 'array',
                'checkRateLimit' => 'array',
                'recordSuccess' => 'void',
                'recordRejection' => 'void',
                'isEnabled' => 'bool',
                'getThrottle' => 'int',
                'getBatchMax' => 'int',
                'getRateLimitStatus' => 'array',
            ];

            foreach ($methods as $method => $returnType) {
                expect($reflection->hasMethod($method))->toBeTrue("Missing method: {$method}");
                $m = $reflection->getMethod($method);
                expect($m->getReturnType()?->getName())->toBe($returnType);
            }
        });

        it('validate returns array with valid and remaining keys', function (): void {
            $reflection = new ReflectionMethod(\ZeroBoiler\Analytics\Services\AnalyticsApiGuard::class, 'validate');
            $docComment = $reflection->getDocComment();
            expect($docComment)->not->toBeFalse();
            expect($docComment)->toContain('valid');
            expect($docComment)->toContain('remaining');
        });
    });

    describe('Config — new v2.95.0 sections', function (): void {
        it('config file contains sse section', function (): void {
            $config = include __DIR__ . '/../../config/zeroboiler.php';
            expect(isset($config['analytics']['sse']))->toBeTrue();
            expect(isset($config['analytics']['sse']['enabled']))->toBeTrue();
            expect(isset($config['analytics']['sse']['max_connection_seconds']))->toBeTrue();
            expect(isset($config['analytics']['sse']['heartbeat_seconds']))->toBeTrue();
            expect(isset($config['analytics']['sse']['poll_interval_ms']))->toBeTrue();
        });

        it('config file contains windowed_aggregation section', function (): void {
            $config = include __DIR__ . '/../../config/zeroboiler.php';
            expect(isset($config['analytics']['windowed_aggregation']))->toBeTrue();
            expect(isset($config['analytics']['windowed_aggregation']['enabled']))->toBeTrue();
            expect(isset($config['analytics']['windowed_aggregation']['minute_ttl']))->toBeTrue();
            expect(isset($config['analytics']['windowed_aggregation']['hour_ttl']))->toBeTrue();
            expect(isset($config['analytics']['windowed_aggregation']['day_ttl']))->toBeTrue();
        });

        it('config file contains feature_adoption section', function (): void {
            $config = include __DIR__ . '/../../config/zeroboiler.php';
            expect(isset($config['analytics']['feature_adoption']))->toBeTrue();
            expect(isset($config['analytics']['feature_adoption']['enabled']))->toBeTrue();
            expect(isset($config['analytics']['feature_adoption']['cache_ttl']))->toBeTrue();
            expect(isset($config['analytics']['feature_adoption']['streak_window_days']))->toBeTrue();
        });

        it('config file contains api_guard section', function (): void {
            $config = include __DIR__ . '/../../config/zeroboiler.php';
            expect(isset($config['analytics']['api_guard']))->toBeTrue();
            expect(isset($config['analytics']['api_guard']['enabled']))->toBeTrue();
            expect(isset($config['analytics']['api_guard']['batch_max']))->toBeTrue();
            expect(isset($config['analytics']['api_guard']['max_payload_bytes']))->toBeTrue();
            expect(isset($config['analytics']['api_guard']['max_event_name_length']))->toBeTrue();
            expect(isset($config['analytics']['api_guard']['rate_window']))->toBeTrue();
        });
    });

    describe('Version consistency', function (): void {
        it('AnalyticsEvent VERSION is 2.95.0', function (): void {
            expect(AnalyticsEvent::VERSION)->toBe('5.9.0');
        });

        it('composer.json version is 2.95.0', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
            expect($composer['version'])->toBe('5.9.0');
        });

        it('config schema_versioning.catalog_version is 2.95.0', function (): void {
            $config = include __DIR__ . '/../../config/zeroboiler.php';
            expect($config['analytics']['schema_versioning']['catalog_version'])->toBe('5.9.0');
        });

        it('no stale 2.94.0 references in source files', function (): void {
            $sourceFiles = glob(__DIR__ . '/../../src/**/*.php');
            $stale = [];
            foreach ($sourceFiles as $file) {
                $content = file_get_contents($file);
                if (str_contains($content, '2.94.0')) {
                    $stale[] = str_replace(__DIR__ . '/../../', '', $file);
                }
            }
            expect($stale)->toBeEmpty('Stale 2.94.0 references found in: ' . implode(', ', $stale));
        });

        it('no stale 2.94.0 references in JS/TS files', function (): void {
            $jsFiles = glob(__DIR__ . '/../../resources/js/*.{js,d.ts}', GLOB_BRACE);
            $stale = [];
            foreach ($jsFiles as $file) {
                $content = file_get_contents($file);
                if (str_contains($content, '2.94.0')) {
                    $stale[] = str_replace(__DIR__ . '/../../', '', $file);
                }
            }
            expect($stale)->toBeEmpty('Stale 2.94.0 references found in: ' . implode(', ', $stale));
        });

        it('no stale 2.94.0 in test files', function (): void {
            $testFiles = glob(__DIR__ . '/../**/*Test.php');
            $stale = [];
            foreach ($testFiles as $file) {
                $content = file_get_contents($file);
                if (str_contains($content, '2.94.0')) {
                    $stale[] = str_replace(__DIR__ . '/../../', '', $file);
                }
            }
            expect($stale)->toBeEmpty('Stale 2.94.0 in tests: ' . implode(', ', $stale));
        });
    });

    describe('ServiceProvider registration', function (): void {
        it('registers EventWindowAggregator as singleton', function (): void {
            $provider = new \ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class);
            $method = $provider->getMethod('register');
            $content = file_get_contents($provider->getFileName());

            expect($content)->toContain('EventWindowAggregator::class');
            expect($content)->toContain('FeatureAdoptionTracker::class');
            expect($content)->toContain('AnalyticsApiGuard::class');
            expect($content)->toContain('AnalyticsSSEController::class');
        });
    });

    describe('SSE routes registration', function (): void {
        it('SSE routes are registered in ServiceProvider', function (): void {
            $providerFile = (new ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class))->getFileName();
            $content = file_get_contents($providerFile);

            expect($content)->toContain("Route::get('analytics/sse'");
            expect($content)->toContain("Route::get('analytics/sse/info'");
            expect($content)->toContain("Route::get('analytics/sse/health'");
        });
    });

    describe('JS client SSE functions', function (): void {
        it('analytics.js contains fetchSSEInfo function', function (): void {
            $content = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
            expect($content)->toContain('export async function fetchSSEInfo');
            expect($content)->toContain('export async function fetchSSEHealth');
            expect($content)->toContain('export function connectSSE');
        });

        it('analytics.js connectSSE handles EventSource', function (): void {
            $content = file_get_contents(__DIR__ . '/../../resources/js/analytics.js');
            expect($content)->toContain('new EventSource(url)');
            expect($content)->toContain('addEventListener(\'event\'');
            expect($content)->toContain('addEventListener(\'heartbeat\'');
            expect($content)->toContain('addEventListener(\'close\'');
        });
    });

    describe('TypeScript definitions for v2.95.0', function (): void {
        it('analytics.d.ts contains SSE types', function (): void {
            $content = file_get_contents(__DIR__ . '/../../resources/js/analytics.d.ts');
            expect($content)->toContain('SSEInfo');
            expect($content)->toContain('SSEHealth');
            expect($content)->toContain('SSEEventData');
            expect($content)->toContain('SSEHeartbeat');
            expect($content)->toContain('SSEClose');
            expect($content)->toContain('SSEConnection');
            expect($content)->toContain('SSEConnectOptions');
            expect($content)->toContain('fetchSSEInfo');
            expect($content)->toContain('fetchSSEHealth');
            expect($content)->toContain('connectSSE');
        });

        it('analytics.d.ts contains Feature Adoption types', function (): void {
            $content = file_get_contents(__DIR__ . '/../../resources/js/analytics.d.ts');
            expect($content)->toContain('FeatureAdoptionProfile');
            expect($content)->toContain('FeatureAdoptionFunnelStep');
        });
    });

    describe('EventCatalog integrity', function (): void {
        it('has all three categories', function (): void {
            $byCategory = EventCatalog::byCategory();
            expect(array_keys($byCategory))->toEqual(['ecommerce', 'saas', 'engagement']);
        });

        it('total count is positive', function (): void {
            expect(EventCatalog::count())->toBeGreaterThan(0);
        });

        it('all categories have events', function (): void {
            $byCategory = EventCatalog::byCategory();
            foreach ($byCategory as $category => $events) {
                expect(count($events))->toBeGreaterThan(0, "Category {$category} has no events");
            }
        });
    });

    describe('Code quality — strict types and docblocks', function (): void {
        it('all new PHP files declare strict types', function (): void {
            $files = [
                'src/Http/Controllers/AnalyticsSSEController.php',
                'src/Services/EventWindowAggregator.php',
                'src/Services/FeatureAdoptionTracker.php',
                'src/Services/AnalyticsApiGuard.php',
            ];

            foreach ($files as $file) {
                $path = __DIR__ . '/../../' . $file;
                $content = file_get_contents($path);
                expect($content)->toContain('declare(strict_types=1)', "Missing strict types in {$file}");
            }
        });

        it('all new PHP files have license header', function (): void {
            $files = [
                'src/Http/Controllers/AnalyticsSSEController.php',
                'src/Services/EventWindowAggregator.php',
                'src/Services/FeatureAdoptionTracker.php',
                'src/Services/AnalyticsApiGuard.php',
            ];

            foreach ($files as $file) {
                $path = __DIR__ . '/../../' . $file;
                $content = file_get_contents($path);
                expect($content)->toContain('This file is part of ZeroBoiler', "Missing license header in {$file}");
            }
        });

        it('all new services have docblocks with @version', function (): void {
            $files = [
                'src/Http/Controllers/AnalyticsSSEController.php',
                'src/Services/EventWindowAggregator.php',
                'src/Services/FeatureAdoptionTracker.php',
                'src/Services/AnalyticsApiGuard.php',
            ];

            foreach ($files as $file) {
                $path = __DIR__ . '/../../' . $file;
                $reflection = new ReflectionClass($file === 'src/Http/Controllers/AnalyticsSSEController.php'
                    ? \ZeroBoiler\Analytics\Http\Controllers\AnalyticsSSEController::class
                    : \ZeroBoiler\Analytics\Services::class);

                // Check docblock exists via file content (reflection strips it)
                $content = file_get_contents($path);
                $lines = explode("\n", $content);
                $hasDocblock = false;
                foreach ($lines as $line) {
                    if (str_contains($line, '/**')) {
                        $hasDocblock = true;
                        break;
                    }
                }
                expect($hasDocblock)->toBeTrue("Missing docblock in {$file}");
            }
        });
    });
});
