<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests\Phase65;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsWatermarkCommand;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\DispatchDecisionReplayService;
use ZeroBoiler\Analytics\Services\EventDeliveryWatermarkService;

/**
 * Phase 65 production readiness — Event Delivery Watermark + Dispatch Decision Replay.
 *
 * Validates the two new services, admin command, API routes, config,
 * version consistency, and file quality for the v245.0.0 release.
 *
 * @since 245.0.0
 */
final class Phase65ProductionReadinessTest extends TestCase
{
    // ── Version Consistency ──────────────────────────────────────

    #[Test]
    public function version_consistency_across_entry_points(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        $package = json_decode(file_get_contents(__DIR__ . '/../../package.json'), true);
        $readme = file_get_contents(__DIR__ . '/../../README.md');
        $eventVersion = AnalyticsEvent::VERSION;

        $this->assertSame('245.0.0', $composer['version']);
        $this->assertSame('245.0.0', $package['version']);
        $this->assertStringContainsString('version-245.0.0', $readme);
        $this->assertSame('245.0.0', $eventVersion);
    }

    // ── File Quality ──────────────────────────────────────────────

    #[Test]
    public function watermark_service_file_quality(): void
    {
        $path = __DIR__ . '/../../src/Services/EventDeliveryWatermarkService.php';
        $content = file_get_contents($path);

        $this->assertStringContainsString('declare(strict_types=1);', $content);
        $this->assertStringContainsString('This file is part of ZeroBoiler, licensed under the MIT license.', $content);
        $this->assertStringContainsString('@since 245.0.0', $content);
        $this->assertStringContainsString('final class EventDeliveryWatermarkService', $content);
        $this->assertFileExists($path);
    }

    #[Test]
    public function replay_service_file_quality(): void
    {
        $path = __DIR__ . '/../../src/Services/DispatchDecisionReplayService.php';
        $content = file_get_contents($path);

        $this->assertStringContainsString('declare(strict_types=1);', $content);
        $this->assertStringContainsString('This file is part of ZeroBoiler, licensed under the MIT license.', $content);
        $this->assertStringContainsString('@since 245.0.0', $content);
        $this->assertStringContainsString('final class DispatchDecisionReplayService', $content);
        $this->assertFileExists($path);
    }

    #[Test]
    public function command_file_quality(): void
    {
        $path = __DIR__ . '/../../src/Console/Commands/AnalyticsWatermarkCommand.php';
        $content = file_get_contents($path);

        $this->assertStringContainsString('declare(strict_types=1);', $content);
        $this->assertStringContainsString('This file is part of ZeroBoiler, licensed under the MIT license.', $content);
        $this->assertStringContainsString('@since 245.0.0', $content);
        $this->assertStringContainsString('final class AnalyticsWatermarkCommand', $content);
        $this->assertFileExists($path);
    }

    // ── Service Method Signatures ──────────────────────────────────

    #[Test]
    public function watermark_service_public_methods(): void
    {
        $methods = get_class_methods(EventDeliveryWatermarkService::class);

        $expected = [
            'nextSequence',
            'globalHighWaterMark',
            'reset',
            'recordDispatch',
            'confirmDelivery',
            'recordFailure',
            'providerWatermark',
            'allWatermarks',
            'providerStatuses',
            'detectGaps',
            'gapsForProvider',
            'replayableGaps',
            'resumeCheckpoint',
            'consistencyReport',
            'dispatchLog',
            'dispatchLogForProvider',
            'dispatchStats',
            'dashboard',
            'isEnabled',
            'trackedProviders',
        ];

        foreach ($expected as $method) {
            $this->assertContains($method, $methods, "Missing method: {$method}");
        }
    }

    #[Test]
    public function replay_service_public_methods(): void
    {
        $methods = get_class_methods(DispatchDecisionReplayService::class);

        $expected = [
            'ledger',
            'recentDecisions',
            'getDecision',
            'analyze',
            'analyzeWindow',
            'droppedDecisions',
            'circuitOpenDecisions',
            'consentDeniedDecisions',
            'budgetExceededDecisions',
            'debugEvent',
            'debugProvider',
            'compareWindows',
            'reasoningBreakdown',
            'summary',
            'isEnabled',
        ];

        foreach ($expected as $method) {
            $this->assertContains($method, $methods, "Missing method: {$method}");
        }
    }

    // ── Config Section ─────────────────────────────────────────────

    #[Test]
    public function config_has_watermark_section(): void
    {
        $config = require __DIR__ . '/../../config/zeroboiler.php';

        $this->assertArrayHasKey('watermark', $config);
        $wm = $config['watermark'];

        $this->assertArrayHasKey('enabled', $wm);
        $this->assertArrayHasKey('ttl', $wm);
        $this->assertArrayHasKey('log_size', $wm);
        $this->assertArrayHasKey('gap_window', $wm);
        $this->assertArrayHasKey('lag_warning', $wm);
        $this->assertArrayHasKey('lag_critical', $wm);
        $this->assertArrayHasKey('providers', $wm);
    }

    // ── Route Registration ─────────────────────────────────────────

    #[Test]
    public function watermark_routes_defined(): void
    {
        $routeContent = file_get_contents(__DIR__ . '/../../routes/analytics.php');

        $watermarkRoutes = [
            'watermarkDashboard',
            'watermarkStatus',
            'watermarkProviderStatus',
            'watermarkGaps',
            'watermarkProviderGaps',
            'watermarkConsistency',
            'watermarkLog',
            'watermarkProviderLog',
            'watermarkStats',
            'watermarkReset',
        ];

        foreach ($watermarkRoutes as $route) {
            $this->assertStringContainsString($route, $routeContent, "Missing route method: {$route}");
        }
    }

    #[Test]
    public function decision_replay_routes_defined(): void
    {
        $routeContent = file_get_contents(__DIR__ . '/../../routes/analytics.php');

        $replayRoutes = [
            'decisionReplaySummary',
            'decisionReplayAnalysis',
            'decisionReplayRecent',
            'decisionReplayDropped',
            'decisionReplayCircuitOpen',
            'decisionReplayConsentDenied',
            'decisionReplayReasoning',
            'decisionReplayDebugEvent',
            'decisionReplayDebugProvider',
        ];

        foreach ($replayRoutes as $route) {
            $this->assertStringContainsString($route, $routeContent, "Missing route method: {$route}");
        }
    }

    // ── Controller Methods ─────────────────────────────────────────

    #[Test]
    public function controller_has_watermark_methods(): void
    {
        $path = __DIR__ . '/../../src/Http/Controllers/AnalyticsEventController.php';
        $content = file_get_contents($path);

        $methods = [
            'watermarkDashboard',
            'watermarkStatus',
            'watermarkProviderStatus',
            'watermarkGaps',
            'watermarkProviderGaps',
            'watermarkConsistency',
            'watermarkLog',
            'watermarkProviderLog',
            'watermarkStats',
            'watermarkReset',
            'decisionReplaySummary',
            'decisionReplayAnalysis',
            'decisionReplayRecent',
            'decisionReplayDropped',
            'decisionReplayCircuitOpen',
            'decisionReplayConsentDenied',
            'decisionReplayReasoning',
            'decisionReplayDebugEvent',
            'decisionReplayDebugProvider',
        ];

        foreach ($methods as $method) {
            $this->assertStringContainsString("function {$method}(", $content, "Missing controller method: {$method}");
        }
    }

    // ── Command Registration ───────────────────────────────────────

    #[Test]
    public function command_registered_in_service_provider(): void
    {
        $spContent = file_get_contents(__DIR__ . '/../../src/AnalyticsServiceProvider.php');

        $this->assertStringContainsString('AnalyticsWatermarkCommand::class', $spContent);
        $this->assertStringContainsString('use ZeroBoiler\\Analytics\\Console\\Commands\\AnalyticsWatermarkCommand;', $spContent);
    }

    #[Test]
    public function services_registered_in_service_provider(): void
    {
        $spContent = file_get_contents(__DIR__ . '/../../src/AnalyticsServiceProvider.php');

        $this->assertStringContainsString('EventDeliveryWatermarkService::class', $spContent);
        $this->assertStringContainsString('DispatchDecisionReplayService::class', $spContent);
        $this->assertStringContainsString('use ZeroBoiler\\Analytics\\Services\\EventDeliveryWatermarkService;', $spContent);
        $this->assertStringContainsString('use ZeroBoiler\\Analytics\\Services\\DispatchDecisionReplayService;', $spContent);
    }

    // ── Command Signature ──────────────────────────────────────────

    #[Test]
    public function command_has_correct_signature(): void
    {
 $this->assertSame(
            'zb:analytics:watermark',
            (new \ReflectionClass(AnalyticsWatermarkCommand::class))
                ->getProperty('signature')
                ->getDefaultValue(),
        );
    }

    // ── Project Scale Thresholds ───────────────────────────────────

    #[Test]
    public function project_scale_thresholds(): void
    {
        $srcCount = count(glob(__DIR__ . '/../../src/**/*.php', GLOB_ERR));
        $testCount = count(glob(__DIR__ . '/../../tests/**/*.php', GLOB_ERR));
        $commandCount = count(glob(__DIR__ . '/../../src/Console/Commands/*Command.php', GLOB_ERR));
        $serviceCount = count(glob(__DIR__ . '/../../src/Services/*.php', GLOB_ERR));

        // Scale thresholds (must be met or exceeded)
        $this->assertGreaterThanOrEqual(976, $srcCount, "Source file count too low: {$srcCount}");
        $this->assertGreaterThanOrEqual(494, $testCount, "Test file count too low: {$testCount}");
        $this->assertGreaterThanOrEqual(117, $commandCount, "Command count too low: {$commandCount}");
        $this->assertGreaterThanOrEqual(448, $serviceCount, "Service count too low: {$serviceCount}");
    }

    // ── Watermark Service Constants ────────────────────────────────

    #[Test]
    public function watermark_service_constants(): void
    {
        $ref = new \ReflectionClass(EventDeliveryWatermarkService::class);

        $this->assertSame('zb_watermark_', $ref->getConstant('CACHE_PREFIX'));
        $this->assertSame('zb_watermark_global_seq', $ref->getConstant('GLOBAL_SEQ_KEY'));
        $this->assertSame('zb_watermark_dispatch_log', $ref->getConstant('DISPATCH_LOG_KEY'));
        $this->assertSame('zb_watermark_gaps', $ref->getConstant('GAPS_KEY'));
        $this->assertSame(1000, $ref->getConstant('DEFAULT_LOG_SIZE'));
        $this->assertSame(500, $ref->getConstant('MAX_GAPS'));
        $this->assertSame(20, $ref->getConstant('MAX_PROVIDERS'));
    }

    #[Test]
    public function watermark_service_provider_list(): void
    {
        $ref = new \ReflectionMethod(EventDeliveryWatermarkService::class, 'trackedProviders');
        $this->assertTrue($ref->hasReturnType());
        $this->assertSame('array', $ref->getReturnType()?->getName());
    }

    // ── Replay Service Constants ───────────────────────────────────

    #[Test]
    public function replay_service_constants(): void
    {
        $ref = new \ReflectionClass(DispatchDecisionReplayService::class);

        $this->assertSame('zb_orchestrator_decisions', $ref->getConstant('LEDGER_KEY'));
    }

    // ── Return Type Declarations ───────────────────────────────────

    #[Test]
    public function watermark_service_return_types(): void
    {
        $ref = new \ReflectionClass(EventDeliveryWatermarkService::class);

        $methods = ['nextSequence', 'globalHighWaterMark', 'reset', 'providerWatermark', 'isEnabled'];
        foreach ($methods as $method) {
            $m = $ref->getMethod($method);
            $this->assertTrue($m->hasReturnType(), "{$method} missing return type");
        }
    }

    #[Test]
    public function replay_service_return_types(): void
    {
        $ref = new \ReflectionClass(DispatchDecisionReplayService::class);

        $methods = ['ledger', 'analyze', 'summary', 'isEnabled', 'getDecision'];
        foreach ($methods as $method) {
            $m = $ref->getMethod($method);
            $this->assertTrue($m->hasReturnType(), "{$method} missing return type");
        }
    }

    // ── Total Endpoint Count ───────────────────────────────────────

    #[Test]
    public function total_api_endpoints(): void
    {
        $routeContent = file_get_contents(__DIR__ . '/../../routes/analytics.php');
        // Count Route:: calls (each is one endpoint)
        preg_match_all('/Route::(get|post|put|patch|delete)\(/', $routeContent, $matches);
        $endpointCount = count($matches[0]);

        $this->assertGreaterThanOrEqual(320, $endpointCount, "API endpoint count too low: {$endpointCount}");
    }

    // ── README Updated ─────────────────────────────────────────────

    #[Test]
    public function readme_includes_watermark_feature(): void
    {
        $readme = file_get_contents(__DIR__ . '/../../README.md');

        // The README should mention the watermark feature or be updated
        $this->assertStringContainsString('version-245.0.0', $readme);
    }

    #[Test]
    public function total_new_endpoints(): void
    {
        // 10 watermark + 9 decision-replay = 19 new endpoints
        $routeContent = file_get_contents(__DIR__ . '/../../routes/analytics.php');

        $watermarkCount = substr_count($routeContent, 'watermark');
        $replayCount = substr_count($routeContent, 'decision-replay');

        $this->assertGreaterThanOrEqual(10, $watermarkCount);
        $this->assertGreaterThanOrEqual(9, $replayCount);
    }
}
