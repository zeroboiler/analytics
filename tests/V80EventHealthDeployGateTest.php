<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventHealthScoringEngine;
use ZeroBoiler\Analytics\Services\AnalyticsDeployGate;

/**
 * V80 Event Health Scoring Engine + Analytics Deploy Gate test suite.
 *
 * Tests per-event health scoring (freshness, volume, schema, delivery, quality),
 * system health aggregation, deploy gate checks, and version consistency.
 *
 * @since 80.0.0
 */
describe('V81 Event Health + Deploy Gate + Forecast', function (): void {
    beforeAll(function (): void {
        // Verify version consistency
        expect(AnalyticsEvent::VERSION)->toBe('81.0.0');
    });

    describe('EventHealthScoringEngine', function (): void {
        it('has correct class structure', function (): void {
            expect(class_exists(EventHealthScoringEngine::class))->toBeTrue();
            expect((new ReflectionClass(EventHealthScoringEngine::class))->isFinal())->toBeTrue();
        });

        it('declares strict types', function (): void {
            $contents = file_get_contents(dirname(__DIR__, 2) . '/src/Services/EventHealthScoringEngine.php');
            expect($contents)->toContain('declare(strict_types=1)');
        });

        it('has recordDispatch method with proper signature', function (): void {
            $method = new ReflectionMethod(EventHealthScoringEngine::class, 'recordDispatch');
            expect($method->isPublic())->toBeTrue();
            $params = $method->getParameters();
            expect(count($params))->toBe(4);
            expect($params[0]->getName())->toBe('eventName');
            expect($params[0]->getType()?->__toString())->toBe('string');
            expect($params[1]->getName())->toBe('schemaValid');
            expect($params[1]->getType()?->__toString())->toBe('bool');
            expect($params[2]->getName())->toBe('providerResults');
            expect($params[2]->getType()?->__toString())->toBe('array');
            expect($params[3]->getName())->toBe('qualityScore');
            expect($params[3]->getType()?->__toString())->toBe('int');
        });

        it('has scoreEvent method returning typed array', function (): void {
            $method = new ReflectionMethod(EventHealthScoringEngine::class, 'scoreEvent');
            expect($method->isPublic())->toBeTrue();
            $returnType = $method->getReturnType();
            expect($returnType)->not->toBeNull();
            expect($returnType->__toString())->toBe('array');
        });

        it('has scoreAllEvents method', function (): void {
            $method = new ReflectionMethod(EventHealthScoringEngine::class, 'scoreAllEvents');
            expect($method->isPublic())->toBeTrue();
        });

        it('has getDegradingEvents method with threshold parameter', function (): void {
            $method = new ReflectionMethod(EventHealthScoringEngine::class, 'getDegradingEvents');
            expect($method->isPublic())->toBeTrue();
            $params = $method->getParameters();
            expect(count($params))->toBe(1);
            expect($params[0]->getName())->toBe('threshold');
            expect($params[0]->getType()?->__toString())->toBe('int');
        });

        it('has systemHealth method', function (): void {
            $method = new ReflectionMethod(EventHealthScoringEngine::class, 'systemHealth');
            expect($method->isPublic())->toBeTrue();
        });

        it('has clearEventStats and clearAllStats methods', function (): void {
            expect(method_exists(EventHealthScoringEngine::class, 'clearEventStats'))->toBeTrue();
            expect(method_exists(EventHealthScoringEngine::class, 'clearAllStats'))->toBeTrue();
        });

        it('has getRecentAlerts method', function (): void {
            $method = new ReflectionMethod(EventHealthScoringEngine::class, 'getRecentAlerts');
            expect($method->isPublic())->toBeTrue();
        });

        it('uses correct dimension weights', function (): void {
            $weights = (new ReflectionClass(EventHealthScoringEngine::class))->getConstant('DIMENSION_WEIGHTS');
            expect($weights)->toBeArray();
            expect(array_sum($weights))->toBe(100);
            expect($weights['freshness'])->toBe(20);
            expect($weights['volume'])->toBe(20);
            expect($weights['schema'])->toBe(25);
            expect($weights['delivery'])->toBe(25);
            expect($weights['quality'])->toBe(10);
        });

        it('has correct cache prefix constants', function (): void {
            $ref = new ReflectionClass(EventHealthScoringEngine::class);
            expect($ref->getConstant('CACHE_PREFIX'))->toBe('zb_event_health_');
            expect($ref->getConstant('ALERT_CACHE_PREFIX'))->toBe('zb_event_health_alert_');
        });

        it('constructor has typed parameters', function (): void {
            $constructor = new ReflectionMethod(EventHealthScoringEngine::class, '__construct');
            $params = $constructor->getParameters();
            expect(count($params))->toBe(2);
            expect($params[0]->getType()?->__toString())->toContain('CacheRepository');
            expect($params[1]->getType()?->__toString())->toContain('ConfigRepository');
        });

        it('has docblocks on public methods', function (): void {
            $publicMethods = ['recordDispatch', 'scoreEvent', 'scoreAllEvents', 'getDegradingEvents', 'systemHealth', 'clearEventStats', 'clearAllStats', 'getRecentAlerts'];
            $ref = new ReflectionClass(EventHealthScoringEngine::class);

            foreach ($publicMethods as $methodName) {
                $method = $ref->getMethod($methodName);
                $doc = $method->getDocComment();
                expect($doc)->not->toBeFalse("Method {$methodName} is missing a docblock");
            }
        });
    });

    describe('AnalyticsDeployGate', function (): void {
        it('has correct class structure', function (): void {
            expect(class_exists(AnalyticsDeployGate::class))->toBeTrue();
            expect((new ReflectionClass(AnalyticsDeployGate::class))->isFinal())->toBeTrue();
        });

        it('declares strict types', function (): void {
            $contents = file_get_contents(dirname(__DIR__, 2) . '/src/Services/AnalyticsDeployGate.php');
            expect($contents)->toContain('declare(strict_types=1)');
        });

        it('has evaluate method', function (): void {
            $method = new ReflectionMethod(AnalyticsDeployGate::class, 'evaluate');
            expect($method->isPublic())->toBeTrue();
            $params = $method->getParameters();
            expect(count($params))->toBe(1);
            expect($params[0]->getName())->toBe('options');
            expect($params[0]->getType()?->__toString())->toBe('array');
        });

        it('has quickCheck method returning int', function (): void {
            $method = new ReflectionMethod(AnalyticsDeployGate::class, 'quickCheck');
            expect($method->isPublic())->toBeTrue();
            $returnType = $method->getReturnType();
            expect($returnType?->__toString())->toBe('int');
        });

        it('has status constants', function (): void {
            $ref = new ReflectionClass(AnalyticsDeployGate::class);
            expect($ref->getConstant('STATUS_PASS'))->toBe('pass');
            expect($ref->getConstant('STATUS_FAIL'))->toBe('fail');
            expect($ref->getConstant('STATUS_WARN'))->toBe('warn');
            expect($ref->getConstant('STATUS_SKIP'))->toBe('skip');
        });

        it('constructor has correct parameters', function (): void {
            $constructor = new ReflectionMethod(AnalyticsDeployGate::class, '__construct');
            $params = $constructor->getParameters();
            expect(count($params))->toBe(3);
            expect($params[0]->getType()?->__toString())->toContain('ConfigRepository');
            expect($params[1]->getType()?->__toString())->toContain('CacheRepository');
            expect($params[2]->getType()?->__toString())->toBe(EventHealthScoringEngine::class);
        });

        it('has docblocks on public methods', function (): void {
            $publicMethods = ['evaluate', 'quickCheck'];
            $ref = new ReflectionClass(AnalyticsDeployGate::class);

            foreach ($publicMethods as $methodName) {
                $method = $ref->getMethod($methodName);
                $doc = $method->getDocComment();
                expect($doc)->not->toBeFalse("Method {$methodName} is missing a docblock");
            }
        });
    });

    describe('CLI Commands', function (): void {
        it('AnalyticsEventHealthCommand exists and is final', function (): void {
            expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsEventHealthCommand::class))->toBeTrue();
        });

        it('AnalyticsDeployGateCommand exists and is final', function (): void {
            expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsDeployGateCommand::class))->toBeTrue();
        });

        it('AnalyticsForecastCommand exists and is final', function (): void {
            expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsForecastCommand::class))->toBeTrue();
        });

        it('EventHealthCommand has correct signature', function (): void {
            $command = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsEventHealthCommand::class);
            $prop = $command->getProperty('signature');
            $signature = $prop->getDefaultValue();
            expect($signature)->toContain('zb:analytics:event-health');
            expect($signature)->toContain('--event=');
            expect($signature)->toContain('--degrading');
            expect($signature)->toContain('--system');
            expect($signature)->toContain('--json');
            expect($signature)->toContain('--clear');
            expect($signature)->toContain('--alerts');
        });

        it('DeployGateCommand has correct signature', function (): void {
            $command = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsDeployGateCommand::class);
            $prop = $command->getProperty('signature');
            $signature = $prop->getDefaultValue();
            expect($signature)->toContain('zb:analytics:deploy-gate');
            expect($signature)->toContain('--include-health');
            expect($signature)->toContain('--json');
            expect($signature)->toContain('--strict');
            expect($signature)->toContain('--clear-health');
        });

        it('EventHealthCommand declares strict types', function (): void {
            $contents = file_get_contents(dirname(__DIR__, 2) . '/src/Console/Commands/AnalyticsEventHealthCommand.php');
            expect($contents)->toContain('declare(strict_types=1)');
        });

        it('DeployGateCommand declares strict types', function (): void {
            $contents = file_get_contents(dirname(__DIR__, 2) . '/src/Console/Commands/AnalyticsDeployGateCommand.php');
            expect($contents)->toContain('declare(strict_types=1)');
        });

        it('AnalyticsForecastCommand has correct signature', function (): void {
            $command = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsForecastCommand::class);
            $prop = $command->getProperty('signature');
            $signature = $prop->getDefaultValue();
            expect($signature)->toContain('zb:analytics:forecast');
            expect($signature)->toContain('mrr');
            expect($signature)->toContain('ltv');
            expect($signature)->toContain('runway');
            expect($signature)->toContain('churn-score');
            expect($signature)->toContain('--json');
            expect($signature)->toContain('--months=');
        });

        it('AnalyticsForecastCommand declares strict types', function (): void {
            $contents = file_get_contents(dirname(__DIR__, 2) . '/src/Console/Commands/AnalyticsForecastCommand.php');
            expect($contents)->toContain('declare(strict_types=1)');
        });
    });

    describe('API Endpoints', function (): void {
        it('controller has eventHealthScore method', function (): void {
            $controller = new ReflectionClass(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class);
            expect($controller->hasMethod('eventHealthScore'))->toBeTrue();
            expect($controller->hasMethod('eventHealthSystem'))->toBeTrue();
            expect($controller->hasMethod('eventHealthAll'))->toBeTrue();
            expect($controller->hasMethod('eventHealthDegrading'))->toBeTrue();
            expect($controller->hasMethod('eventHealthAlerts'))->toBeTrue();
        });

        it('controller has deployGate methods', function (): void {
            $controller = new ReflectionClass(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class);
            expect($controller->hasMethod('deployGateEvaluate'))->toBeTrue();
            expect($controller->hasMethod('deployGateQuick'))->toBeTrue();
        });

        it('controller methods return JsonResponse', function (): void {
            $controller = new ReflectionClass(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class);
            $methods = ['eventHealthScore', 'eventHealthSystem', 'eventHealthAll', 'eventHealthDegrading', 'eventHealthAlerts', 'deployGateEvaluate', 'deployGateQuick'];

            foreach ($methods as $methodName) {
                $method = $controller->getMethod($methodName);
                $returnType = $method->getReturnType()?->getName();
                expect($returnType)->toBe('Illuminate\\Http\\JsonResponse', "Method {$methodName} should return JsonResponse");
            }
        });
    });

    describe('Config', function (): void {
        it('event_health config section exists', function (): void {
            $config = include dirname(__DIR__, 2) . '/config/zeroboiler.php';
            expect(isset($config['analytics']['event_health']))->toBeTrue();
            $health = $config['analytics']['event_health'];
            expect($health['enabled'])->toBeTrue();
            expect($health['freshness_threshold'])->toBe(3600);
            expect($health['volume_drop_threshold'])->toBe(0.3);
            expect($health['volume_spike_multiplier'])->toBe(5.0);
            expect($health['min_volume_sample'])->toBe(10);
        });

        it('deploy_gate config section exists', function (): void {
            $config = include dirname(__DIR__, 2) . '/config/zeroboiler.php';
            expect(isset($config['analytics']['deploy_gate']))->toBeTrue();
            $gate = $config['analytics']['deploy_gate'];
            expect($gate['block_on_warnings'])->toBeFalse();
            expect($gate['min_health_score'])->toBe(40);
            expect($gate['skip_events'])->toBeArray();
        });
    });

    describe('ServiceProvider Registration', function (): void {
        it('registers EventHealthScoringEngine singleton', function (): void {
            $contents = file_get_contents(dirname(__DIR__, 2) . '/src/AnalyticsServiceProvider.php');
            expect($contents)->toContain('EventHealthScoringEngine::class');
            expect($contents)->toContain('singleton(EventHealthScoringEngine::class');
        });

        it('registers AnalyticsDeployGate singleton', function (): void {
            $contents = file_get_contents(dirname(__DIR__, 2) . '/src/AnalyticsServiceProvider.php');
            expect($contents)->toContain('AnalyticsDeployGate::class');
            expect($contents)->toContain('singleton(AnalyticsDeployGate::class');
        });

        it('registers RevenueForecastService singleton', function (): void {
            $contents = file_get_contents(dirname(__DIR__, 2) . '/src/AnalyticsServiceProvider.php');
            expect($contents)->toContain('RevenueForecastService::class');
            expect($contents)->toContain('singleton(RevenueForecastService::class');
        });

        it('registers ChurnPredictionService singleton', function (): void {
            $contents = file_get_contents(dirname(__DIR__, 2) . '/src/AnalyticsServiceProvider.php');
            expect($contents)->toContain('ChurnPredictionService::class');
            expect($contents)->toContain('singleton(ChurnPredictionService::class');
        });

        it('registers CLI commands', function (): void {
            $contents = file_get_contents(dirname(__DIR__, 2) . '/src/AnalyticsServiceProvider.php');
            expect($contents)->toContain('AnalyticsEventHealthCommand::class');
            expect($contents)->toContain('AnalyticsDeployGateCommand::class');
            expect($contents)->toContain('AnalyticsForecastCommand::class');
        });

        it('registers routes', function (): void {
            $contents = file_get_contents(dirname(__DIR__, 2) . '/src/AnalyticsServiceProvider.php');
            expect($contents)->toContain('eventHealthScore');
            expect($contents)->toContain('eventHealthSystem');
            expect($contents)->toContain('eventHealthAll');
            expect($contents)->toContain('eventHealthDegrading');
            expect($contents)->toContain('eventHealthAlerts');
            expect($contents)->toContain('deployGateEvaluate');
            expect($contents)->toContain('deployGateQuick');
        });
    });

    describe('Version Consistency', function (): void {
        it('VERSION is 81.0.0 across all files', function (): void {
            // Check AnalyticsEvent
            expect(AnalyticsEvent::VERSION)->toBe('81.0.0');

            // Check IntegrityCommand
            $integrity = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsIntegrityCommand::class);
            expect($integrity->getConstant('EXPECTED_VERSION'))->toBe('81.0.0');

            // Check composer.json
            $composer = json_decode(file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
            expect($composer['version'])->toBe('81.0.0');

            // Check package.json
            $pkg = json_decode(file_get_contents(dirname(__DIR__, 2) . '/package.json'), true);
            expect($pkg['version'])->toBe('81.0.0');
        });

        it('JS client version matches', function (): void {
            $js = file_get_contents(dirname(__DIR__, 2) . '/resources/js/analytics.js');
            expect($js)->toContain("'81.0.0'");
            // Should not have old version
            expect($js)->not->toContain("'80.0.0'");
        });

        it('README badge version matches', function (): void {
            $readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');
            expect($readme)->toContain('version-81.0.0');
        });
    });
});
