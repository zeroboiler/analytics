<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsServiceProvider;
use ZeroBoiler\Analytics\DTO\AnalyticsGoal;
use ZeroBoiler\Analytics\DTO\GoalProgress;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\AnalyticsGoalTracker;
use ZeroBoiler\Analytics\Services\RollingWindowAnalyticsEngine;
use ZeroBoiler\Analytics\Services\SaaSQuickInsightsService;
use ZeroBoiler\Analytics\Services\SaaSOnboardingWizardService;
use ZeroBoiler\Analytics\Services\EventValueAttributionService;
use ZeroBoiler\Analytics\Services\SaaSMomentumService;
use ZeroBoiler\Analytics\Services\SaasBenchmarkCalibrationService;
use ZeroBoiler\Analytics\Services\EventInstrumentationAdvisor;
use ZeroBoiler\Analytics\Services\AnalyticsConfigValidationService;

describe('Phase 42 — Post-v174-v177 Production Readiness', function (): void {

    // ─── Version Consistency (14 entry points) ──────────────────────────

    it('composer.json version is 178.0.0', function (): void {
        $composer = json_decode(
            file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true,
        );
        expect($composer['version'])->toBe('178.0.0');
    });

    it('package.json version is 178.0.0', function (): void {
        $pkg = json_decode(
            file_get_contents(dirname(__DIR__, 2) . '/package.json'),
            true,
        );
        expect($pkg['version'])->toBe('178.0.0');
    });

    it('AnalyticsEvent::VERSION is 178.0.0', function (): void {
        expect(AnalyticsEvent::VERSION)->toBe('178.0.0');
    });

    it('AnalyticsIntegrityCommand::EXPECTED_VERSION is 178.0.0', function (): void {
        $const = (new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsIntegrityCommand::class))
            ->getConstant('EXPECTED_VERSION');
        expect($const)->toBe('178.0.0');
    });

    it('ServiceProvider @version is 178.0.0', function (): void {
        $doc = (new ReflectionClass(AnalyticsServiceProvider::class))->getDocComment();
        expect($doc)->not->toBeFalse();
        expect($doc)->toContain('@version 178.0.0');
    });

    it('README version badge matches', function (): void {
        $readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');
        expect($readme)->toContain('version-178.0.0');
    });

    it('analytics.js header + getVersion returns 178.0.0', function (): void {
        $js = file_get_contents(dirname(__DIR__, 2) . '/resources/js/analytics.js');
        expect($js)->toContain('@version 178.0.0');
        expect($js)->toContain("'178.0.0'");
    });

    // ─── Core Service Class Finality ────────────────────────────────────

    it('AnalyticsManager is final + has void constructor', function (): void {
        $ref = new ReflectionClass(AnalyticsManager::class);
        expect($ref->isFinal())->toBeTrue();
        $ctor = $ref->getMethod('__construct');
        expect($ctor->getReturnType()?->getName())->toBe('void');
    });

    it('AnalyticsServiceProvider is final + has register/boot/provides', function (): void {
        $ref = new ReflectionClass(AnalyticsServiceProvider::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->hasMethod('register'))->toBeTrue();
        expect($ref->hasMethod('boot'))->toBeTrue();
        expect($ref->hasMethod('provides'))->toBeTrue();
    });

    // ─── New v174-v177 Service Classes ──────────────────────────────────

    it('AnalyticsGoalTracker is final with void constructor', function (): void {
        $ref = new ReflectionClass(AnalyticsGoalTracker::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->getMethod('__construct')->getReturnType()?->getName())->toBe('void');
    });

    it('RollingWindowAnalyticsEngine is final with void constructor', function (): void {
        $ref = new ReflectionClass(RollingWindowAnalyticsEngine::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->getMethod('__construct')->getReturnType()?->getName())->toBe('void');
    });

    it('SaaSQuickInsightsService is final with void constructor', function (): void {
        $ref = new ReflectionClass(SaaSQuickInsightsService::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->getMethod('__construct')->getReturnType()?->getName())->toBe('void');
    });

    it('SaaSOnboardingWizardService is final with void constructor', function (): void {
        $ref = new ReflectionClass(SaaSOnboardingWizardService::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->getMethod('__construct')->getReturnType()?->getName())->toBe('void');
    });

    it('EventValueAttributionService is final with void constructor', function (): void {
        $ref = new ReflectionClass(EventValueAttributionService::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->getMethod('__construct')->getReturnType()?->getName())->toBe('void');
    });

    it('SaaSMomentumService is final with void constructor', function (): void {
        $ref = new ReflectionClass(SaaSMomentumService::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->getMethod('__construct')->getReturnType()?->getName())->toBe('void');
    });

    it('SaasBenchmarkCalibrationService is final with void constructor', function (): void {
        $ref = new ReflectionClass(SaasBenchmarkCalibrationService::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->getMethod('__construct')->getReturnType()?->getName())->toBe('void');
    });

    it('EventInstrumentationAdvisor is final with void constructor', function (): void {
        $ref = new ReflectionClass(EventInstrumentationAdvisor::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->getMethod('__construct')->getReturnType()?->getName())->toBe('void');
    });

    it('AnalyticsConfigValidationService is final with void constructor', function (): void {
        $ref = new ReflectionClass(AnalyticsConfigValidationService::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->getMethod('__construct')->getReturnType()?->getName())->toBe('void');
    });

    // ─── New DTO Classes ────────────────────────────────────────────────

    it('AnalyticsGoal is final readonly with void constructor', function (): void {
        $ref = new ReflectionClass(AnalyticsGoal::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->isReadOnly())->toBeTrue();
        expect($ref->getMethod('__construct')->getReturnType()?->getName())->toBe('void');
        expect($ref->hasMethod('fromArray'))->toBeTrue();
        expect($ref->hasMethod('toArray'))->toBeTrue();
    });

    it('GoalProgress is final readonly with void constructor', function (): void {
        $ref = new ReflectionClass(GoalProgress::class);
        expect($ref->isFinal())->toBeTrue();
        expect($ref->isReadOnly())->toBeTrue();
        expect($ref->getMethod('__construct')->getReturnType()?->getName())->toBe('void');
        expect($ref->hasMethod('fromGoal'))->toBeTrue();
        expect($ref->hasMethod('toArray'))->toBeTrue();
    });

    // ─── SaaS Events (v174.0.0) ────────────────────────────────────────

    it('v174 SaaS events are all final', function (): void {
        $events = [
            \ZeroBoiler\Analytics\Events\SaaS\ArrMilestoneEvent::class,
            \ZeroBoiler\Analytics\Events\SaaS\BurnMultipleEvent::class,
            \ZeroBoiler\Analytics\Events\SaaS\ContractionRevenueEvent::class,
            \ZeroBoiler\Analytics\Events\SaaS\NetRevenueRetentionEvent::class,
            \ZeroBoiler\Analytics\Events\SaaS\PaybackPeriodEvent::class,
        ];
        foreach ($events as $eventClass) {
            $ref = new ReflectionClass($eventClass);
            expect($ref->isFinal())->toBeTrue("{$eventClass} must be final");
            expect($ref->getMethod('__construct')->getReturnType()?->getName())->toBe('void');
            expect($ref->getDocComment())->toContain('@since 174.0.0');
        }
    });

    // ─── Service Class API Surfaces ─────────────────────────────────────

    it('AnalyticsGoalTracker has expected public methods', function (): void {
        $ref = new ReflectionClass(AnalyticsGoalTracker::class);
        $methods = array_map(fn (ReflectionMethod $m) => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));
        expect($methods)->toContain('registerGoal');
        expect($methods)->toContain('removeGoal');
        expect($methods)->toContain('getGoal');
        expect($methods)->toContain('allGoals');
        expect($methods)->toContain('activeGoals');
        expect($methods)->toContain('progress');
        expect($methods)->toContain('allProgress');
        expect($methods)->toContain('dashboard');
        expect($methods)->toContain('attentionNeeded');
        expect($methods)->toContain('achievedGoals');
        expect($methods)->toContain('invalidateGoal');
        expect($methods)->toContain('invalidateAll');
    });

    it('RollingWindowAnalyticsEngine has expected public methods', function (): void {
        $ref = new ReflectionClass(RollingWindowAnalyticsEngine::class);
        $methods = array_map(fn (ReflectionMethod $m) => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));
        expect($methods)->toContain('sma');
        expect($methods)->toContain('ema');
        expect($methods)->toContain('wma');
        expect($methods)->toContain('allMovingAverages');
        expect($methods)->toContain('detectTrend');
        expect($methods)->toContain('volatility');
        expect($methods)->toContain('smoothSeries');
        expect($methods)->toContain('profile');
        expect($methods)->toContain('invalidateCache');
    });

    it('SaaSQuickInsightsService has expected public methods', function (): void {
        $ref = new ReflectionClass(SaaSQuickInsightsService::class);
        $methods = array_map(fn (ReflectionMethod $m) => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));
        expect($methods)->toContain('registerSeries');
        expect($methods)->toContain('generateInsights');
        expect($methods)->toContain('summary');
        expect($methods)->toContain('invalidateCache');
    });

    it('SaaSOnboardingWizardService has expected public methods', function (): void {
        $ref = new ReflectionClass(SaaSOnboardingWizardService::class);
        $methods = array_map(fn (ReflectionMethod $m) => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));
        expect($methods)->toContain('getState');
        expect($methods)->toContain('summary');
        expect($methods)->toContain('gaps');
        expect($methods)->toContain('nextAction');
        expect($methods)->toContain('grade');
        expect($methods)->toContain('invalidateCache');
        expect($methods)->toContain('isStepCompleted');
        expect($methods)->toContain('categoryBreakdown');
    });

    it('EventValueAttributionService has expected public methods', function (): void {
        $ref = new ReflectionClass(EventValueAttributionService::class);
        $methods = array_map(fn (ReflectionMethod $m) => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));
        expect($methods)->toContain('valueOf');
        expect($methods)->toContain('valueOfMany');
        expect($methods)->toContain('report');
        expect($methods)->toContain('valueJourney');
        expect($methods)->toContain('getFunnelPaths');
        expect($methods)->toContain('getBaseValues');
        expect($methods)->toContain('isEnabled');
    });

    it('SaaSMomentumService has expected public methods', function (): void {
        $ref = new ReflectionClass(SaaSMomentumService::class);
        $methods = array_map(fn (ReflectionMethod $m) => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));
        expect($methods)->toContain('calculateMetricMomentum');
        expect($methods)->toContain('compositeScore');
        expect($methods)->toContain('quickSummary');
        expect($methods)->toContain('availableMetrics');
        expect($methods)->toContain('isEnabled');
    });

    it('SaasBenchmarkCalibrationService has expected public methods', function (): void {
        $ref = new ReflectionClass(SaasBenchmarkCalibrationService::class);
        $methods = array_map(fn (ReflectionMethod $m) => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));
        expect($methods)->toContain('benchmarks');
        expect($methods)->toContain('calibrate');
        expect($methods)->toContain('overallScore');
        expect($methods)->toContain('arrTiers');
        expect($methods)->toContain('metricNames');
        expect($methods)->toContain('resolveTier');
        expect($methods)->toContain('gapAnalysis');
        expect($methods)->toContain('cachedReport');
    });

    it('AnalyticsConfigValidationService has expected public methods', function (): void {
        $ref = new ReflectionClass(AnalyticsConfigValidationService::class);
        $methods = array_map(fn (ReflectionMethod $m) => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));
        expect($methods)->toContain('validate');
    });

    it('EventInstrumentationAdvisor has expected public methods', function (): void {
        $ref = new ReflectionClass(EventInstrumentationAdvisor::class);
        $methods = array_map(fn (ReflectionMethod $m) => $m->getName(), $ref->getMethods(ReflectionMethod::IS_PUBLIC));
        expect($methods)->toContain('getReport');
        expect($methods)->toContain('summary');
        expect($methods)->toContain('gaps');
        expect($methods)->toContain('quickWins');
        expect($methods)->toContain('priorityMatrix');
        expect($methods)->toContain('stageCoverage');
        expect($methods)->toContain('invalidateCache');
    });

    // ─── Config Integrity ───────────────────────────────────────────────

    it('config has goals section', function (): void {
        $config = file_get_contents(dirname(__DIR__, 2) . '/config/zeroboiler.php');
        expect($config)->toContain("'goals' => [");
        expect($config)->toContain('ANALYTICS_GOALS_CACHE_TTL');
        expect($config)->toContain('ANALYTICS_GOALS_WARNING');
        expect($config)->toContain('ANALYTICS_GOALS_CRITICAL');
    });

    it('config has rolling_window section', function (): void {
        $config = file_get_contents(dirname(__DIR__, 2) . '/config/zeroboiler.php');
        expect($config)->toContain("'rolling_window' => [");
        expect($config)->toContain('ANALYTICS_ROLLING_WINDOW');
        expect($config)->toContain('ANALYTICS_ROLLING_EMA_ALPHA');
        expect($config)->toContain('ANALYTICS_ROLLING_VOLATILITY_WINDOW');
    });

    it('config has quick_insights section', function (): void {
        $config = file_get_contents(dirname(__DIR__, 2) . '/config/zeroboiler.php');
        expect($config)->toContain("'quick_insights' => [");
        expect($config)->toContain('ANALYTICS_QUICK_INSIGHTS_ENABLED');
        expect($config)->toContain('ANALYTICS_QUICK_INSIGHTS_SPIKE');
        expect($config)->toContain('ANALYTICS_QUICK_INSIGHTS_DROP');
    });

    it('config has momentum section', function (): void {
        $config = file_get_contents(dirname(__DIR__, 2) . '/config/zeroboiler.php');
        expect($config)->toContain("'momentum' => [");
        expect($config)->toContain('ANALYTICS_MOMENTUM_ENABLED');
        expect($config)->toContain('ANALYTICS_MOMENTUM_CACHE_TTL');
    });

    it('config has event_value_attribution section', function (): void {
        $config = file_get_contents(dirname(__DIR__, 2) . '/config/zeroboiler.php');
        expect($config)->toContain("'event_value_attribution' => [");
        expect($config)->toContain('ANALYTICS_EVT_VALUE_ENABLED');
        expect($config)->toContain('ANALYTICS_EVT_VALUE_MODEL');
        expect($config)->toContain('ANALYTICS_EVT_VALUE_DECAY');
    });

    // ─── ServiceProvider Registration ────────────────────────────────────

    it('ServiceProvider registers all 9 new services', function (): void {
        $sp = new ReflectionClass(AnalyticsServiceProvider::class);
        $content = file_get_contents($sp->getFileName());

        expect($content)->toContain('AnalyticsGoalTracker');
        expect($content)->toContain('RollingWindowAnalyticsEngine');
        expect($content)->toContain('SaaSQuickInsightsService');
        expect($content)->toContain('SaaSOnboardingWizardService');
        expect($content)->toContain('EventValueAttributionService');
        expect($content)->toContain('SaaSMomentumService');
        expect($content)->toContain('SaasBenchmarkCalibrationService');
        expect($content)->toContain('EventInstrumentationAdvisor');
        expect($content)->toContain('AnalyticsConfigValidationService');
    });

    // ─── File Counts ────────────────────────────────────────────────────

    it('source file count is at least 833', function (): void {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $files = glob($srcDir . '/**/*.php');
        expect(count($files))->toBeGreaterThanOrEqual(833);
    });

    it('test file count is at least 418', function (): void {
        $testDir = dirname(__DIR__, 2) . '/tests';
        $files = glob($testDir . '/**/*.php');
        expect(count($files))->toBeGreaterThanOrEqual(418);
    });

    // ─── Strict Types Coverage ────────────────────────────────────────────

    it('all v174-v177 source files have strict_types', function (): void {
        $newFiles = [
            'Services/AnalyticsGoalTracker.php',
            'Services/RollingWindowAnalyticsEngine.php',
            'Services/SaaSQuickInsightsService.php',
            'Services/SaaSOnboardingWizardService.php',
            'Services/EventValueAttributionService.php',
            'Services/SaaSMomentumService.php',
            'Services/SaasBenchmarkCalibrationService.php',
            'Services/EventInstrumentationAdvisor.php',
            'Services/AnalyticsConfigValidationService.php',
            'DTO/AnalyticsGoal.php',
            'DTO/GoalProgress.php',
            'Events/SaaS/ArrMilestoneEvent.php',
            'Events/SaaS/BurnMultipleEvent.php',
            'Events/SaaS/ContractionRevenueEvent.php',
            'Events/SaaS/NetRevenueRetentionEvent.php',
            'Events/SaaS/PaybackPeriodEvent.php',
        ];
        $srcDir = dirname(__DIR__, 2) . '/src';
        foreach ($newFiles as $file) {
            $content = file_get_contents($srcDir . '/' . $file);
            expect($content)->toContain('declare(strict_types=1)');
        }
    });

    // ─── License Headers ─────────────────────────────────────────────────

    it('all new service files have MIT license header', function (): void {
        $newFiles = [
            'Services/AnalyticsGoalTracker.php',
            'Services/RollingWindowAnalyticsEngine.php',
            'Services/SaaSQuickInsightsService.php',
            'Services/SaaSOnboardingWizardService.php',
            'Services/EventValueAttributionService.php',
            'Services/SaaSMomentumService.php',
            'Services/SaasBenchmarkCalibrationService.php',
            'Services/EventInstrumentationAdvisor.php',
            'Services/AnalyticsConfigValidationService.php',
            'DTO/AnalyticsGoal.php',
            'DTO/GoalProgress.php',
        ];
        $srcDir = dirname(__DIR__, 2) . '/src';
        foreach ($newFiles as $file) {
            $content = file_get_contents($srcDir . '/' . $file);
            expect($content)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
        }
    });

    // ─── Return Type Declarations ───────────────────────────────────────

    it('AnalyticsGoalTracker public methods have return types', function (): void {
        $ref = new ReflectionClass(AnalyticsGoalTracker::class);
        $methods = ['registerGoal', 'removeGoal', 'getGoal', 'allGoals', 'activeGoals',
            'progress', 'allProgress', 'progressByCategory', 'dashboard', 'attentionNeeded',
            'achievedGoals', 'invalidateGoal', 'invalidateAll'];
        foreach ($methods as $method) {
            $m = $ref->getMethod($method);
            expect($m->getReturnType())->not->toBeNull("AnalyticsGoalTracker::{$method}() missing return type");
        }
    });

    it('RollingWindowAnalyticsEngine public methods have return types', function (): void {
        $ref = new ReflectionClass(RollingWindowAnalyticsEngine::class);
        $methods = ['sma', 'ema', 'wma', 'allMovingAverages', 'detectTrend',
            'volatility', 'smoothSeries', 'profile', 'invalidateCache'];
        foreach ($methods as $method) {
            $m = $ref->getMethod($method);
            expect($m->getReturnType())->not->toBeNull("RollingWindowAnalyticsEngine::{$method}() missing return type");
        }
    });

    it('SaaSQuickInsightsService public methods have return types', function (): void {
        $ref = new ReflectionClass(SaaSQuickInsightsService::class);
        $methods = ['registerSeries', 'generateInsights', 'summary', 'invalidateCache'];
        foreach ($methods as $method) {
            $m = $ref->getMethod($method);
            expect($m->getReturnType())->not->toBeNull("SaaSQuickInsightsService::{$method}() missing return type");
        }
    });

    it('SaaSMomentumService public methods have return types', function (): void {
        $ref = new ReflectionClass(SaaSMomentumService::class);
        $methods = ['calculateMetricMomentum', 'compositeScore', 'quickSummary',
            'availableMetrics', 'isEnabled'];
        foreach ($methods as $method) {
            $m = $ref->getMethod($method);
            expect($m->getReturnType())->not->toBeNull("SaaSMomentumService::{$method}() missing return type");
        }
    });

    // ─── Subdirectory Cross-Reference ────────────────────────────────────

    it('src subdirectories exist for key domains', function (): void {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $domains = ['Trackers', 'Events', 'Enrichment', 'Services', 'Commands', 'Jobs', 'DTO'];
        foreach ($domains as $domain) {
            expect(is_dir($srcDir . '/' . $domain))->toBeTrue("Missing src/{$domain} directory");
        }
    });

    // ─── @since Annotations ───────────────────────────────────────────────

    it('all new service classes have @since annotations', function (): void {
        $classes = [
            AnalyticsGoalTracker::class => '177.0.0',
            RollingWindowAnalyticsEngine::class => '177.0.0',
            SaaSQuickInsightsService::class => '177.0.0',
            SaaSOnboardingWizardService::class => '177.0.0',
            EventValueAttributionService::class => '175.0.0',
            SaaSMomentumService::class => '175.0.0',
            SaasBenchmarkCalibrationService::class => '174.0.0',
            EventInstrumentationAdvisor::class => '177.0.0',
            AnalyticsConfigValidationService::class => '177.0.0',
        ];
        foreach ($classes as $class => $expectedSince) {
            $doc = (new ReflectionClass($class))->getDocComment();
            expect($doc)->not->toBeFalse("{$class} missing docblock");
            expect($doc)->toContain("@since {$expectedSince}");
        }
    });
});
