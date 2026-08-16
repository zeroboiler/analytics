<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Phase 31 production audit — client-side sampling engine, debug logger,
 * JS client export coverage, and version consistency.
 *
 * @since 102.0.0
 */
final class Phase31SamplingDebugAuditTest extends TestCase
{
    /**
     * @test
     */
    public function js_client_exports_sampling_decision_function(): void
    {
        $jsPath = __DIR__ . '/../resources/js/analytics.js';
        $this->assertFileExists($jsPath);
        $content = file_get_contents($jsPath);
        $this->assertNotFalse($content);

        $this->assertStringContainsString(
            'export function getSamplingDecision',
            $content,
            'JS client must export getSamplingDecision function',
        );
    }

    /**
     * @test
     */
    public function js_client_exports_debug_event_log_functions(): void
    {
        $jsPath = __DIR__ . '/../resources/js/analytics.js';
        $content = file_get_contents($jsPath);
        $this->assertNotFalse($content);

        $requiredExports = [
            'export function getDebugEventLog',
            'export function clearDebugEventLog',
            'export function getDebugEventLogStats',
        ];

        foreach ($requiredExports as $export) {
            $this->assertStringContainsString(
                $export,
                $content,
                "JS client must export {$export}",
            );
        }
    }

    /**
     * @test
     */
    public function js_client_implements_should_sample_event(): void
    {
        $jsPath = __DIR__ . '/../resources/js/analytics.js';
        $content = file_get_contents($jsPath);
        $this->assertNotFalse($content);

        $this->assertStringContainsString(
            'function shouldSampleEvent',
            $content,
            'JS client must have private shouldSampleEvent function',
        );

        // Verify deterministic hash-based sampling
        $this->assertStringContainsString(
            'deterministic',
            $content,
            'Sampling must support deterministic mode',
        );

        $this->assertStringContainsString(
            'trackingId',
            $content,
            'Deterministic sampling must use trackingId for hash seed',
        );
    }

    /**
     * @test
     */
    public function js_client_applies_sampling_gate_in_track_event(): void
    {
        $jsPath = __DIR__ . '/../resources/js/analytics.js';
        $content = file_get_contents($jsPath);
        $this->assertNotFalse($content);

        // Verify sampling gate is called before event processing
        $trackEventIdx = strpos($content, 'export async function trackEvent');
        $this->assertNotFalse($trackEventIdx, 'trackEvent function must exist');

        $nextBlock = substr($content, $trackEventIdx, 500);

        $this->assertStringContainsString(
            'shouldSampleEvent',
            $nextBlock,
            'trackEvent must call shouldSampleEvent before processing',
        );

        $this->assertStringContainsString(
            'sampled_out',
            $nextBlock,
            'trackEvent must handle sampled_out action in debug log',
        );
    }

    /**
     * @test
     */
    public function js_client_debug_log_ring_buffer_has_size_limit(): void
    {
        $jsPath = __DIR__ . '/../resources/js/analytics.js';
        $content = file_get_contents($jsPath);
        $this->assertNotFalse($content);

        $this->assertStringContainsString(
            'MAX_DEBUG_LOG_SIZE',
            $content,
            'Debug log must have a maximum size constant',
        );

        $this->assertStringContainsString(
            'debugEventLog.shift()',
            $content,
            'Debug log must trim oldest entries (ring buffer)',
        );
    }

    /**
     * @test
     */
    public function js_client_debug_log_action_types_are_comprehensive(): void
    {
        $jsPath = __DIR__ . '/../resources/js/analytics.js';
        $content = file_get_contents($jsPath);
        $this->assertNotFalse($content);

        $actions = ['queued', 'immediate', 'sampled_out', 'consent_blocked'];

        foreach ($actions as $action) {
            $this->assertStringContainsString(
                $action,
                $content,
                "Debug log must support action type: {$action}",
            );
        }
    }

    /**
     * @test
     */
    public function ts_types_define_debug_interfaces(): void
    {
        $tsPath = __DIR__ . '/../resources/js/analytics.d.ts';
        $content = file_get_contents($tsPath);
        $this->assertNotFalse($content);

        $this->assertStringContainsString(
            'DebugEventLogEntry',
            $content,
            'TypeScript definitions must export DebugEventLogEntry interface',
        );

        $this->assertStringContainsString(
            'DebugEventLogStats',
            $content,
            'TypeScript definitions must export DebugEventLogStats interface',
        );
    }

    /**
     * @test
     */
    public function ts_types_define_sampling_decision(): void
    {
        $tsPath = __DIR__ . '/../resources/js/analytics.d.ts';
        $content = file_get_contents($tsPath);
        $this->assertNotFalse($content);

        $this->assertStringContainsString(
            'getSamplingDecision',
            $content,
            'TypeScript definitions must include getSamplingDecision',
        );

        $this->assertStringContainsString(
            'sampled: boolean',
            $content,
            'Sampling decision must return sampled: boolean',
        );
    }

    /**
     * @test
     */
    public function version_consistency_across_all_package_files(): void
    {
        $version = '130.0.0';

        // PHP DTO
        $dto = file_get_contents(__DIR__ . '/../src/DTO/AnalyticsEvent.php');
        $this->assertNotFalse($dto);
        $this->assertStringContainsString("= '{$version}'", $dto, 'AnalyticsEvent VERSION must be ' . $version);

        // composer.json
        $composer = file_get_contents(__DIR__ . '/../composer.json');
        $this->assertNotFalse($composer);
        $this->assertStringContainsString("\"{$version}\"", $composer, 'composer.json version must be ' . $version);

        // package.json
        $pkg = file_get_contents(__DIR__ . '/../package.json');
        $this->assertNotFalse($pkg);
        $this->assertStringContainsString("\"{$version}\"", $pkg, 'package.json version must be ' . $version);

        // JS client
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        $this->assertNotFalse($js);
        $this->assertStringContainsString("@version {$version}", $js, 'JS client @version must be ' . $version);

        // TypeScript declarations
        $ts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
        $this->assertNotFalse($ts);
        $this->assertStringContainsString("@version {$version}", $ts, 'TypeScript @version must be ' . $version);
    }

    /**
     * @test
     */
    public function js_client_exports_saas_shorthand_functions(): void
    {
        $jsPath = __DIR__ . '/../resources/js/analytics.js';
        $content = file_get_contents($jsPath);
        $this->assertNotFalse($content);

        $requiredShorthands = [
            'export async function trackSignUp',
            'export async function trackTrialStart',
            'export async function trackSubscription',
            'export async function trackPlanUpgrade',
            'export async function trackCancellation',
            'export async function trackFeatureUsed',
            'export async function trackAccountActivated',
            'export async function trackAccountDeactivated',
            'export async function trackEmailVerified',
            'export async function trackAccountDeleted',
            'export async function trackFirstValue',
            'export async function trackGrowthMilestone',
        ];

        foreach ($requiredShorthands as $fn) {
            $this->assertStringContainsString(
                $fn,
                $content,
                "JS client must export {$fn}",
            );
        }
    }

    /**
     * @test
     */
    public function js_client_exports_core_analytics_functions(): void
    {
        $jsPath = __DIR__ . '/../resources/js/analytics.js';
        $content = file_get_contents($jsPath);
        $this->assertNotFalse($content);

        $coreFunctions = [
            'export function init(',
            'export function initFullStack(',
            'export function destroy()',
            'export function isInitialized()',
            'export async function trackEvent(',
            'export async function trackPageView(',
            'export async function identify(',
            'export async function updateConsent(',
            'export function flushQueue()',
            'export function initScrollDepth()',
            'export function initSessionTracking(',
            'export function initInertiaPageViewTrackerLegacy()',
            'export function getConsentState()',
            'export function resetConsentState()',
            'export function getVersion()',
            'export function getTrackingId()',
            'export function getApiBaseUrl()',
        ];

        foreach ($coreFunctions as $fn) {
            $this->assertStringContainsString(
                $fn,
                $content,
                "JS client must export {$fn}",
            );
        }
    }

    /**
     * @test
     */
    public function js_client_has_offline_buffer_support(): void
    {
        $jsPath = __DIR__ . '/../resources/js/analytics.js';
        $content = file_get_contents($jsPath);
        $this->assertNotFalse($content);

        $offlineFunctions = [
            'export function isOffline()',
            'export function saveToOfflineBuffer(',
            'export function loadOfflineBuffer()',
            'export function clearOfflineBuffer()',
            'export function offlineBufferStatus()',
            'export async function flushOfflineBuffer()',
            'export function enableOfflineRecovery()',
        ];

        foreach ($offlineFunctions as $fn) {
            $this->assertStringContainsString(
                $fn,
                $content,
                "JS client must export {$fn}",
            );
        }
    }

    /**
     * @test
     */
    public function js_client_has_automatic_flush_on_unload(): void
    {
        $jsPath = __DIR__ . '/../resources/js/analytics.js';
        $content = file_get_contents($jsPath);
        $this->assertNotFalse($content);

        $this->assertStringContainsString(
            'beforeunload',
            $content,
            'JS client must listen for beforeunload event',
        );

        $this->assertStringContainsString(
            'navigator.sendBeacon',
            $content,
            'JS client must use sendBeacon for unload delivery',
        );
    }

    /**
     * @test
     */
    public function all_source_files_have_strict_types_and_constructors_have_void(): void
    {
        $sourceDir = __DIR__ . '/../src';
        $violations = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getRealPath());
            if ($content === false) {
                continue;
            }

            $relative = str_replace($sourceDir . '/', '', $file->getRealPath());

            // Check strict_types
            if (! str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = "{$relative}: missing strict_types";
            }

            // Check constructor void
            if (str_contains($content, 'public function __construct')) {
                $idx = strpos($content, 'public function __construct');
                $braceIdx = strpos($content, '{', $idx);
                if ($braceIdx !== false) {
                    $sig = substr($content, $idx, $braceIdx - $idx);
                    if (! str_contains($sig, ': void')) {
                        $violations[] = "{$relative}: constructor missing :void";
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            sprintf('Phase 31 violations (%d): %s', count($violations), implode('; ', $violations)),
        );
    }
}
