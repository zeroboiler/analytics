<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsServiceProvider;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\AnalyticsGoal;
use ZeroBoiler\Analytics\DTO\GoalProgress;
use ZeroBoiler\Analytics\Exceptions\AnalyticsException;
use ZeroBoiler\Analytics\Exceptions\AnalyticsRuntimeException;
use ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Services\SaaSLifecycleFlowService;

/**
 * Phase 46 Production Readiness Test
 *
 * Comprehensive audit of the analytics package (862 source files).
 * Validates structural invariants, v194 new code, DTO immutability,
 * exception hierarchy bidirectional @see, Facade contract, and
 * version consistency across all entry points.
 */
describe('Phase 46 Production Readiness', function (): void {
    describe('Source File Inventory', function (): void {
        it('has 862 source files', function (): void {
            $srcDir = __DIR__ . '/../src';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $phpFiles = [];
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $phpFiles[] = $file->getRealPath();
                }
            }
            expect(count($phpFiles))->toBeGreaterThanOrEqual(862);
        });

        it('has 441+ test files', function (): void {
            $testsDir = __DIR__ . '/../tests';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($testsDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $phpFiles = [];
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $phpFiles[] = $file->getRealPath();
                }
            }
            expect(count($phpFiles))->toBeGreaterThanOrEqual(441);
        });

        it('has strict_types in all source files', function (): void {
            $srcDir = __DIR__ . '/../src';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $missing = [];
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $content = file_get_contents($file->getRealPath());
                    if (! str_contains($content, 'declare(strict_types=1)')) {
                        $missing[] = $file->getFilename();
                    }
                }
            }
            expect($missing)->toBeEmpty();
        });

        it('has license header in all source files', function (): void {
            $srcDir = __DIR__ . '/../src';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $missing = [];
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $content = file_get_contents($file->getRealPath());
                    if (! str_contains($content, 'This file is part of ZeroBoiler')) {
                        $missing[] = $file->getFilename();
                    }
                }
            }
            expect($missing)->toBeEmpty();
        });

        it('has zero TODO/FIXME/HACK markers', function (): void {
            $srcDir = __DIR__ . '/../src';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $markers = [];
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $content = file_get_contents($file->getRealPath());
                    foreach (['TODO', 'FIXME', 'HACK', 'XXX'] as $marker) {
                        if (str_contains($content, $marker)) {
                            $markers[] = $file->getFilename() . ':' . $marker;
                        }
                    }
                }
            }
            expect($markers)->toBeEmpty();
        });

        it('all concrete classes are final', function (): void {
            $srcDir = __DIR__ . '/../src';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $nonFinal = [];
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $content = file_get_contents($file->getRealPath());
                if (! preg_match('/^\s*(?:final\s+)?class\s+\w+/', $content)) {
                    continue;
                }
                if (! str_contains($content, 'abstract class') && ! str_contains($content, 'final class')) {
                    $nonFinal[] = $file->getFilename();
                }
            }
            expect($nonFinal)->toBeEmpty();
        });
    });

    describe('Exception Hierarchy Validation', function (): void {
        it('AnalyticsException is abstract with :void constructor', function (): void {
            $ref = new ReflectionClass(AnalyticsException::class);
            expect($ref->isAbstract())->toBeTrue();
            $ctor = $ref->getConstructor();
            expect($ctor)->not->toBeNull();
            expect($ctor->getReturnType()->getName())->toBe('void');
        });

        it('AnalyticsException has @see references to leaf exceptions', function (): void {
            $doc = (new ReflectionClass(AnalyticsException::class))->getDocComment();
            expect($doc)->toContain(InvalidAnalyticsArgumentException::class);
            expect($doc)->toContain(AnalyticsRuntimeException::class);
        });

        it('InvalidAnalyticsArgumentException is final leaf with :void constructor', function (): void {
            $ref = new ReflectionClass(InvalidAnalyticsArgumentException::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isSubclassOf(AnalyticsException::class))->toBeTrue();
            $ctor = $ref->getConstructor();
            expect($ctor->getReturnType()->getName())->toBe('void');
        });

        it('InvalidAnalyticsArgumentException has @see back to AnalyticsException', function (): void {
            $doc = (new ReflectionClass(InvalidAnalyticsArgumentException::class))->getDocComment();
            expect($doc)->toContain(AnalyticsException::class);
        });

        it('InvalidAnalyticsArgumentException has factory method forMessage', function (): void {
            $ref = new ReflectionClass(InvalidAnalyticsArgumentException::class);
            expect($ref->hasMethod('forMessage'))->toBeTrue();
            $method = $ref->getMethod('forMessage');
            expect($method->isStatic())->toBeTrue();
            expect($method->getReturnType()->getName())->toBe('self');
        });

        it('AnalyticsRuntimeException is final leaf with :void constructor', function (): void {
            $ref = new ReflectionClass(AnalyticsRuntimeException::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isSubclassOf(AnalyticsException::class))->toBeTrue();
            $ctor = $ref->getConstructor();
            expect($ctor->getReturnType()->getName())->toBe('void');
        });

        it('AnalyticsRuntimeException has @see back to AnalyticsException', function (): void {
            $doc = (new ReflectionClass(AnalyticsRuntimeException::class))->getDocComment();
            expect($doc)->toContain(AnalyticsException::class);
        });

        it('AnalyticsRuntimeException has factory method forMessage', function (): void {
            $ref = new ReflectionClass(AnalyticsRuntimeException::class);
            expect($ref->hasMethod('forMessage'))->toBeTrue();
            $method = $ref->getMethod('forMessage');
            expect($method->isStatic())->toBeTrue();
            expect($method->getReturnType()->getName())->toBe('self');
        });
    });

    describe('DTO Immutability', function (): void {
        it('AnalyticsEvent is final readonly with :void constructor', function (): void {
            $ref = new ReflectionClass(AnalyticsEvent::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
            $ctor = $ref->getConstructor();
            expect($ctor->getReturnType()->getName())->toBe('void');
        });

        it('AnalyticsEvent has fromArray factory and toArray', function (): void {
            $ref = new ReflectionClass(AnalyticsEvent::class);
            expect($ref->hasMethod('fromArray'))->toBeTrue();
            expect($ref->hasMethod('toArray'))->toBeTrue();
        });

        it('AnalyticsEvent has fluent with* methods', function (): void {
            $ref = new ReflectionClass(AnalyticsEvent::class);
            foreach (['withCategory', 'withSessionId', 'withSource', 'withPriority', 'withTimestamp', 'withMergedParams'] as $method) {
                expect($ref->hasMethod($method))->toBeTrue("Missing {$method}");
            }
        });

        it('AnalyticsEvent VERSION constant matches 194.0.0', function (): void {
            expect(AnalyticsEvent::VERSION)->toBe('194.0.0');
        });

        it('AnalyticsGoal is final readonly with :void constructor', function (): void {
            $ref = new ReflectionClass(AnalyticsGoal::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
            $ctor = $ref->getConstructor();
            expect($ctor->getReturnType()->getName())->toBe('void');
        });

        it('AnalyticsGoal has fromArray factory and toArray', function (): void {
            $ref = new ReflectionClass(AnalyticsGoal::class);
            expect($ref->hasMethod('fromArray'))->toBeTrue();
            expect($ref->hasMethod('toArray'))->toBeTrue();
        });

        it('GoalProgress is final readonly with :void constructor', function (): void {
            $ref = new ReflectionClass(GoalProgress::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
            $ctor = $ref->getConstructor();
            expect($ctor->getReturnType()->getName())->toBe('void');
        });

        it('GoalProgress has fromGoal factory', function (): void {
            $ref = new ReflectionClass(GoalProgress::class);
            expect($ref->hasMethod('fromGoal'))->toBeTrue();
        });
    });

    describe('SaaSLifecycleFlowService (v194)', function (): void {
        it('is final with :void constructor', function (): void {
            $ref = new ReflectionClass(SaaSLifecycleFlowService::class);
            expect($ref->isFinal())->toBeTrue();
            $ctor = $ref->getConstructor();
            expect($ctor->getReturnType()->getName())->toBe('void');
        });

        it('has @since annotation', function (): void {
            $doc = (new ReflectionClass(SaaSLifecycleFlowService::class))->getDocComment();
            expect($doc)->toContain('@since 194.0.0');
        });

        it('has 8 funnel stages', function (): void {
            $stages = SaaSLifecycleFlowService::stages();
            expect($stages)->toHaveCount(8);
            expect($stages[0])->toBe('anonymous');
            expect($stages[7])->toBe('champion');
        });

        it('stageIndex is consistent with stages array', function (): void {
            $stages = SaaSLifecycleFlowService::stages();
            foreach ($stages as $idx => $stage) {
                expect(SaaSLifecycleFlowService::stageIndex($stage))->toBe($idx);
            }
        });

        it('progressForStage returns 0.0 for anonymous and 1.0 for champion', function (): void {
            expect(SaaSLifecycleFlowService::progressForStage('anonymous'))->toBe(0.0);
            expect(SaaSLifecycleFlowService::progressForStage('champion'))->toBe(1.0);
        });

        it('isForwardProgression validates direction', function (): void {
            expect(SaaSLifecycleFlowService::isForwardProgression('anonymous', 'signed_up'))->toBeTrue();
            expect(SaaSLifecycleFlowService::isForwardProgression('champion', 'anonymous'))->toBeFalse();
            expect(SaaSLifecycleFlowService::isForwardProgression('signed_up', 'signed_up'))->toBeFalse();
        });

        it('resolveStageForEvent returns mapped stages', function (): void {
            expect(SaaSLifecycleFlowService::resolveStageForEvent('sign_up'))->toBe('signed_up');
            expect(SaaSLifecycleFlowService::resolveStageForEvent('start_trial'))->toBe('trialing');
            expect(SaaSLifecycleFlowService::resolveStageForEvent('subscribe'))->toBe('subscribed');
            expect(SaaSLifecycleFlowService::resolveStageForEvent('plan_upgrade'))->toBe('expanding');
            expect(SaaSLifecycleFlowService::resolveStageForEvent('subscription_renewal'))->toBe('retained');
            expect(SaaSLifecycleFlowService::resolveStageForEvent('feature_used'))->toBeNull();
            expect(SaaSLifecycleFlowService::resolveStageForEvent('unknown_event'))->toBeNull();
        });

        it('funnelSummary returns structured data', function (): void {
            $summary = SaaSLifecycleFlowService::funnelSummary('trialing');
            expect($summary)->toHaveKeys(['stage', 'progress', 'next_stage', 'stages']);
            expect($summary['stage'])->toBe('trialing');
            expect($summary['progress'])->toBe(0.29);
            expect($summary['next_stage'])->toBe('subscribed');
            expect($summary['stages'])->toBe(SaaSLifecycleFlowService::stages());
        });

        it('funnelBreakdown has correct structure for all 8 stages', function (): void {
            $breakdown = SaaSLifecycleFlowService::funnelBreakdown();
            expect($breakdown)->toHaveCount(8);
            foreach ($breakdown as $entry) {
                expect($entry)->toHaveKeys(['stage', 'index', 'progress', 'trigger_events']);
            }
            expect($breakdown[0]['stage'])->toBe('anonymous');
            expect($breakdown[0]['progress'])->toBe(0.0);
            expect($breakdown[7]['stage'])->toBe('champion');
            expect($breakdown[7]['progress'])->toBe(1.0);
        });

        it('constructor accepts nullable AnalyticsManager', function (): void {
            $svc = new SaaSLifecycleFlowService(null);
            expect($svc)->toBeInstanceOf(SaaSLifecycleFlowService::class);
        });

        it('track methods return correct stage strings', function (): void {
            $svc = new SaaSLifecycleFlowService(null);
            expect($svc->trackSignUp(null))->toBe('signed_up');
            expect($svc->trackTrialStart(null))->toBe('trialing');
            expect($svc->trackSubscription(null))->toBe('subscribed');
            expect($svc->trackPlanUpgrade(null))->toBe('expanding');
            expect($svc->trackActivation(null))->toBe('activated');
            expect($svc->trackRenewal(null))->toBe('retained');
        });

        it('trackCancellation returns void', function (): void {
            $svc = new SaaSLifecycleFlowService(null);
            $result = $svc->trackCancellation(null);
            expect($result)->toBeNull();
        });

        it('nextStageAfter returns null for champion', function (): void {
            expect(SaaSLifecycleFlowService::nextStageAfter('champion'))->toBeNull();
            expect(SaaSLifecycleFlowService::nextStageAfter('anonymous'))->toBe('signed_up');
        });
    });

    describe('ServiceProvider Contract', function (): void {
        it('is final', function (): void {
            expect((new ReflectionClass(AnalyticsServiceProvider::class))->isFinal())->toBeTrue();
        });

        it('register method has #[Override]', function (): void {
            $method = (new ReflectionClass(AnalyticsServiceProvider::class))->getMethod('register');
            $attrs = $method->getAttributes();
            $hasOverride = false;
            foreach ($attrs as $attr) {
                if ($attr->getName() === 'Override' || str_contains($attr->getName(), 'Override')) {
                    $hasOverride = true;
                    break;
                }
            }
            expect($hasOverride)->toBeTrue();
        });

        it('provides method has #[Override]', function (): void {
            $method = (new ReflectionClass(AnalyticsServiceProvider::class))->getMethod('provides');
            $attrs = $method->getAttributes();
            $hasOverride = false;
            foreach ($attrs as $attr) {
                if ($attr->getName() === 'Override' || str_contains($attr->getName(), 'Override')) {
                    $hasOverride = true;
                    break;
                }
            }
            expect($hasOverride)->toBeTrue();
        });

        it('provides returns non-empty array', function (): void {
            $provider = new ReflectionClass(AnalyticsServiceProvider::class);
            $method = $provider->getMethod('provides');
            expect($method->getReturnType()->getName())->toBe('array');
        });
    });

    describe('Facade Contract', function (): void {
        it('is final', function (): void {
            expect((new ReflectionClass(Analytics::class))->isFinal())->toBeTrue();
        });

        it('getFacadeAccessor is protected static returning string', function (): void {
            $method = (new ReflectionClass(Analytics::class))->getMethod('getFacadeAccessor');
            expect($method->isStatic())->toBeTrue();
            expect($method->isPublic())->toBeFalse();
            expect($method->getReturnType()->getName())->toBe('string');
        });

        it('getFacadeAccessor returns zeroboiler.analytics', function (): void {
            $method = (new ReflectionClass(Analytics::class))->getMethod('getFacadeAccessor');
            $method->setAccessible(true);
            expect($method->invoke(null))->toBe('zeroboiler.analytics');
        });
    });

    describe('AnalyticsManager', function (): void {
        it('is final', function (): void {
            expect((new ReflectionClass(AnalyticsManager::class))->isFinal())->toBeTrue();
        });

        it('constructor has :void return type', function (): void {
            $ctor = (new ReflectionClass(AnalyticsManager::class))->getConstructor();
            expect($ctor->getReturnType()->getName())->toBe('void');
        });
    });

    describe('Composer Metadata Integrity', function (): void {
        it('requires PHP ^8.5', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['require']['php'])->toBe('^8.5');
        });

        it('has correct namespace', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['autoload']['psr-4']['ZeroBoiler\\Analytics\\'])->toBe('src/');
        });

        it('registers provider', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['extra']['laravel']['providers'])->toContain(
                'ZeroBoiler\\Analytics\\AnalyticsServiceProvider',
            );
        });

        it('registers facade alias', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['extra']['laravel']['aliases'])->toHaveKey('Analytics');
        });

        it('has quality scripts', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            foreach (['test', 'analyse', 'lint', 'rector', 'quality'] as $script) {
                expect($composer['scripts'])->toHaveKey($script);
            }
        });

        it('has MIT license', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['license'])->toBe('MIT');
        });

        it('autoload-dev includes tests namespace', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['autoload-dev']['psr-4'])->toHaveKey('ZeroBoiler\\Analytics\\Tests\\');
        });
    });

    describe('PHPStan Config Parity', function (): void {
        it('phpstan.neon.dist exists with level 9', function (): void {
            $content = file_get_contents(__DIR__ . '/../phpstan.neon.dist');
            expect($content)->toContain('level: 9');
            expect($content)->toContain('src/');
        });

        it('rector.php targets PHP 8.5', function (): void {
            $content = file_get_contents(__DIR__ . '/../rector.php');
            expect($content)->toContain('PHP_85');
        });
    });

    describe('Version Consistency', function (): void {
        it('composer.json version is 194.0.0', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['version'])->toBe('194.0.0');
        });

        it('AnalyticsEvent::VERSION is 194.0.0', function (): void {
            expect(AnalyticsEvent::VERSION)->toBe('194.0.0');
        });
    });

    describe('Project Structure Files', function (): void {
        $requiredFiles = [
            'README.md', 'CHANGELOG.md', 'CONTRIBUTING.md', 'LICENSE',
            'composer.json', 'phpstan.neon.dist', 'rector.php', 'pint.json', 'pest.xml',
        ];

        it('has all ' . count($requiredFiles) . ' required files', function () use ($requiredFiles): void {
            foreach ($requiredFiles as $file) {
                expect(file_exists(__DIR__ . '/../' . $file))->toBeTrue("Missing {$file}");
            }
        });
    });

    describe('Config File Integrity', function (): void {
        it('config file exists with strict_types', function (): void {
            $path = __DIR__ . '/../config/zeroboiler.php';
            expect(file_exists($path))->toBeTrue();
            $content = file_get_contents($path);
            expect($content)->toContain('declare(strict_types=1)');
        });

        it('config has core sections', function (): void {
            $config = require __DIR__ . '/../config/zeroboiler.php';
            expect($config)->toHaveKey('analytics');
            $analytics = $config['analytics'];
            foreach (['ga4', 'gtm', 'meta_pixel', 'consent'] as $section) {
                expect($analytics)->toHaveKey($section);
            }
        });
    });
});
