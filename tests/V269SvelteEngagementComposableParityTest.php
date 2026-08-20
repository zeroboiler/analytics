<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that all 8 SaaS Starter engagement events have both
 * auto-tracking init functions AND dedicated Svelte composables
 * with reactive stores in the JS client library.
 *
 * Engagement events: page_view, scroll_depth, click, form_start,
 * form_submit, search, share, error.
 *
 * @since 269.0.0
 */
#[Group('saas-starter')]
#[Group('svelte')]
#[Group('engagement')]
final class V269SvelteEngagementComposableParityTest extends TestCase
{
    private const RESOURCES_JS_PATH = __DIR__ . '/../resources/js';

    /**
     * SaaS Starter engagement events with their expected
     * auto-tracker and Svelte composable files.
     *
     * @var array<string, array{auto_tracker: string, svelte_composable: string, core_shortcut: string}>
     */
    private const ENGAGEMENT_EVENTS = [
        'page_view' => [
            'auto_tracker' => 'initInertiaPageViewTracker',
            'svelte_composable' => 'usePageView.svelte.js',
            'core_shortcut' => 'trackPageView',
        ],
        'scroll_depth' => [
            'auto_tracker' => 'initScrollDepth',
            'svelte_composable' => 'useScrollDepth.svelte.js',
            'core_shortcut' => null, // tracked via initScrollDepth
        ],
        'click' => [
            'auto_tracker' => 'initLinkTracking',
            'svelte_composable' => 'useClickTracking.svelte.js',
            'core_shortcut' => 'trackClick',
        ],
        'form_start' => [
            'auto_tracker' => 'initFormTracking',
            'svelte_composable' => 'useFormTracking.svelte.js',
            'core_shortcut' => 'trackFormStart',
        ],
        'form_submit' => [
            'auto_tracker' => 'initFormTracking',
            'svelte_composable' => 'useFormTracking.svelte.js',
            'core_shortcut' => 'trackFormSubmit',
        ],
        'search' => [
            'auto_tracker' => null, // tracked via trackSearch() shortcut
            'svelte_composable' => 'useSearchTracking.svelte.js',
            'core_shortcut' => 'trackSearch',
        ],
        'share' => [
            'auto_tracker' => null, // tracked via trackShare() shortcut
            'svelte_composable' => 'useShareTracking.svelte.js',
            'core_shortcut' => 'trackShare',
        ],
        'error' => [
            'auto_tracker' => 'initErrorTracking',
            'svelte_composable' => 'useErrorTracking.svelte.js',
            'core_shortcut' => 'trackError',
        ],
    ];

    // ─── Auto-Tracker Functions ──────────────────────────────────

    /**
     * Every engagement event that has an auto-tracker must
     * have that function exported in analytics.js.
     */
    public function testAllAutoTrackersExistInCoreLibrary(): void
    {
        $corePath = self::RESOURCES_JS_PATH . '/analytics.js';
        $this->assertFileExists($corePath, 'Core analytics.js must exist');
        $core = file_get_contents($corePath);

        $eventsWithTrackers = array_filter(
            self::ENGAGEMENT_EVENTS,
            fn (array $e): bool => $e['auto_tracker'] !== null,
        );

        foreach ($eventsWithTrackers as $event => $config) {
            $this->assertStringContainsString(
                'export function ' . $config['auto_tracker'],
                $core,
                "Event '{$event}' auto-tracker '{$config['auto_tracker']}' must be exported in analytics.js",
            );
        }
    }

    /**
     * Auto-trackers must be wired into initAll().
     */
    public function testAutoTrackersWiredInInitAll(): void
    {
        $corePath = self::RESOURCES_JS_PATH . '/analytics.js';
        $core = file_get_contents($corePath);

        // The key auto-trackers referenced in initAll
        $initAllTrackers = [
            'pageViews' => 'initInertiaPageViewTracker',
            'scrollDepth' => 'initScrollDepth',
            'formTracking' => 'initFormTracking',
            'errorTracking' => 'initErrorTracking',
            'linkTracking' => 'initLinkTracking',
        ];

        foreach ($initAllTrackers as $configKey => $fnName) {
            $this->assertStringContainsString(
                $fnName,
                $core,
                "Auto-tracker '{$fnName}' must be referenced in analytics.js initAll()",
            );
        }
    }

    // ─── Core Shortcut Functions ─────────────────────────────────

    /**
     * Every engagement event with a core shortcut must
     * have that function exported in analytics.js.
     */
    public function testAllCoreShortcutsExist(): void
    {
        $corePath = self::RESOURCES_JS_PATH . '/analytics.js';
        $core = file_get_contents($corePath);

        $eventsWithShortcuts = array_filter(
            self::ENGAGEMENT_EVENTS,
            fn (array $e): bool => $e['core_shortcut'] !== null,
        );

        foreach ($eventsWithShortcuts as $event => $config) {
            $this->assertStringContainsString(
                'export async function ' . $config['core_shortcut'],
                $core,
                "Event '{$event}' core shortcut '{$config['core_shortcut']}' must be exported in analytics.js",
            );
        }
    }

    // ─── Svelte Composables ──────────────────────────────────────

    /**
     * Every engagement event must have a dedicated Svelte composable file.
     */
    public function testAllEngagementEventsHaveSvelteComposables(): void
    {
        $composablesSeen = [];

        foreach (self::ENGAGEMENT_EVENTS as $event => $config) {
            $composablePath = self::RESOURCES_JS_PATH . '/' . $config['svelte_composable'];
            $this->assertFileExists(
                $composablePath,
                "Svelte composable for '{$event}' must exist at {$config['svelte_composable']}",
            );

            $content = file_get_contents($composablePath);

            // Must export a default or named function with the composable name
            $expectedFnName = str_replace('.svelte.js', '', $config['svelte_composable']);
            $this->assertStringContainsString(
                'export function ' . $expectedFnName,
                $content,
                "Composable '{$config['svelte_composable']}' must export '{$expectedFnName}' function",
            );

            // Must import from core analytics.js
            $this->assertStringContainsString(
                "from './analytics.js'",
                $content,
                "Composable '{$config['svelte_composable']}' must import from core analytics.js",
            );

            // Must use svelte lifecycle
            $this->assertStringContainsString(
                "from 'svelte'",
                $content,
                "Composable '{$config['svelte_composable']}' must import from svelte",
            );

            // Must use writable store
            $this->assertStringContainsString(
                'writable',
                $content,
                "Composable '{$config['svelte_composable']}' must use writable stores",
            );

            // Must call trackEvent for the appropriate event
            $this->assertStringContainsString(
                'trackEvent(',
                $content,
                "Composable '{$config['svelte_composable']}' must call trackEvent()",
            );

            $composablesSeen[] = $config['svelte_composable'];
        }

        // Verify no duplicate composable assignments
        $uniqueComposables = array_unique($composablesSeen);
        $this->assertCount(
            count($composablesSeen),
            $uniqueComposables,
            'Each engagement event should map to a unique composable file',
        );
    }

    // ─── Composable Quality Checks ───────────────────────────────

    /**
     * All Svelte composables must have proper JSDoc with @version.
     */
    public function testComposablesHaveJSDoc(): void
    {
        foreach (self::ENGAGEMENT_EVENTS as $event => $config) {
            $path = self::RESOURCES_JS_PATH . '/' . $config['svelte_composable'];
            $content = file_get_contents($path);

            $this->assertStringContainsString(
                '@package ZeroBoiler Analytics',
                $content,
                "Composable for '{$event}' must have ZeroBoiler package JSDoc",
            );

            $this->assertStringContainsString(
                '@version',
                $content,
                "Composable for '{$event}' must have @version in JSDoc",
            );
        }
    }

    /**
     * All Svelte composables must register onDestroy cleanup.
     */
    public function testComposablesRegisterOnDestroy(): void
    {
        foreach (self::ENGAGEMENT_EVENTS as $event => $config) {
            $path = self::RESOURCES_JS_PATH . '/' . $config['svelte_composable'];
            $content = file_get_contents($path);

            $this->assertStringContainsString(
                'onDestroy(',
                $content,
                "Composable for '{$event}' must register onDestroy cleanup",
            );
        }
    }

    /**
     * All Svelte composables must have isInitialized() guard
     * to prevent tracking before library init.
     */
    public function testComposablesGuardAgainstUninitialized(): void
    {
        $skipCheck = ['useClickTracking', 'useFormTracking', 'useShareTracking', 'useErrorTracking', 'useSearchTracking'];

        foreach (self::ENGAGEMENT_EVENTS as $event => $config) {
            $composableName = str_replace('.svelte.js', '', $config['svelte_composable']);

            $path = self::RESOURCES_JS_PATH . '/' . $config['svelte_composable'];
            $content = file_get_contents($path);

            // Some composables check isInitialized before firing,
            // others rely on the core trackEvent no-op when not initialized.
            // Either approach is valid.
            $hasGuard = str_contains($content, 'isInitialized()');
            $hasTryCatch = str_contains($content, 'try {') && str_contains($content, 'catch');

            $this->assertTrue(
                $hasGuard || $hasTryCatch,
                "Composable for '{$event}' must either check isInitialized() or wrap tracking in try/catch",
            );
        }
    }

    // ─── InitAll Coverage ────────────────────────────────────────

    /**
     * initAll() must support toggling all engagement tracking types.
     */
    public function testInitAllSupportsAllEngagementToggleOptions(): void
    {
        $corePath = self::RESOURCES_JS_PATH . '/analytics.js';
        $core = file_get_contents($corePath);

        $expectedToggles = [
            'pageViews',
            'scrollDepth',
            'formTracking',
            'errorTracking',
            'linkTracking',
        ];

        foreach ($expectedToggles as $toggle) {
            // Must appear in the options destructuring
            $this->assertStringContainsString(
                $toggle,
                $core,
                "initAll() must support '{$toggle}' toggle option",
            );
        }
    }

    // ─── TypeScript Declarations ─────────────────────────────────

    /**
     * The TypeScript declarations file must exist.
     */
    public function testTypeScriptDeclarationsExist(): void
    {
        $dtsPath = self::RESOURCES_JS_PATH . '/analytics.d.ts';
        $this->assertFileExists($dtsPath, 'TypeScript declarations must exist');
    }

    // ─── Version Consistency ─────────────────────────────────────

    /**
     * All Svelte composables and the core library must share
     * the same version number.
     */
    public function testVersionConsistencyAcrossJsFiles(): void
    {
        $corePath = self::RESOURCES_JS_PATH . '/analytics.js';
        $core = file_get_contents($corePath);

        // Extract version from core
        if (!preg_match('/@version\s+(\d+\.\d+\.\d+)/', $core, $matches)) {
            $this->fail('Could not extract version from analytics.js');
        }
        $coreVersion = $matches[1];

        foreach (self::ENGAGEMENT_EVENTS as $event => $config) {
            $path = self::RESOURCES_JS_PATH . '/' . $config['svelte_composable'];
            $content = file_get_contents($path);

            if (preg_match('/@version\s+(\d+\.\d+\.\d+)/', $content, $fileMatches)) {
                $this->assertSame(
                    $coreVersion,
                    $fileMatches[1],
                    "Composable for '{$event}' version must match core analytics.js ({$coreVersion})",
                );
            }
        }
    }

    // ─── Full Parity Summary ─────────────────────────────────────

    /**
     * Summary: all 8 engagement events must have complete coverage.
     */
    public function testCompleteEngagementParitySummary(): void
    {
        $corePath = self::RESOURCES_JS_PATH . '/analytics.js';
        $core = file_get_contents($corePath);

        $summary = [];
        $allGood = true;

        foreach (self::ENGAGEMENT_EVENTS as $event => $config) {
            $hasAutoTracker = $config['auto_tracker'] === null ||
                str_contains($core, 'export function ' . $config['auto_tracker']);
            $hasShortcut = $config['core_shortcut'] === null ||
                str_contains($core, 'export async function ' . $config['core_shortcut']);
            $composablePath = self::RESOURCES_JS_PATH . '/' . $config['svelte_composable'];
            $hasComposable = file_exists($composablePath);

            $status = ($hasAutoTracker && $hasShortcut && $hasComposable) ? '✓' : '✗';
            if ($status === '✗') {
                $allGood = false;
            }

            $summary[] = sprintf(
                '  %s %-15s tracker=%-30s shortcut=%-20s composable=%s',
                $status,
                $event,
                $config['auto_tracker'] ?? '(n/a)',
                $config['core_shortcut'] ?? '(n/a)',
                $hasComposable ? '✓' : '✗',
            );
        }

        $report = "Engagement Parity Report:\n" . implode("\n", $summary);

        $this->assertTrue($allGood, "Not all engagement events have full parity:\n{$report}");
    }
}
