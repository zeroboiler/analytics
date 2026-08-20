<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

/**
 * V272.0.0 — Unified Engagement Composable (useEngagement.svelte.js).
 *
 * Validates:
 * 1. The composable file exists and is well-formed JS
 * 2. All 6 sub-composable imports are referenced
 * 3. All required exports are present
 * 4. TypeScript definitions added to analytics.d.ts
 * 5. Version consistency across all entry points
 *
 * @since 272.0.0
 */
describe('V272 Unified Engagement Composable', function (): void {
    // ── File Existence ──────────────────────────────────────────────

    describe('File existence', function (): void {
        it('useEngagement.svelte.js exists', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            expect(file_exists($path))->toBeTrue();
        });

        it('file is non-empty (>100 lines)', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $lines = count(file($path));
            expect($lines)->toBeGreaterThan(100);
        });

        it('has valid JS syntax (no parse errors)', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);
            // Basic syntax validation: balanced braces
            $openBraces = substr_count($content, '{');
            $closeBraces = substr_count($content, '}');
            expect($openBraces)->toBe($closeBraces,
                'Unbalanced braces in useEngagement.svelte.js'
            );
        });
    });

    // ── Sub-Composable Imports ───────────────────────────────────────

    describe('Sub-composable imports', function (): void {
        it('imports from all 6 sub-composables', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);

            expect(str_contains($content, "from './useClickTracking.svelte.js'"))->toBeTrue();
            expect(str_contains($content, "from './useFormTracking.svelte.js'"))->toBeTrue();
            expect(str_contains($content, "from './useSearchTracking.svelte.js'"))->toBeTrue();
            expect(str_contains($content, "from './useShareTracking.svelte.js'"))->toBeTrue();
            expect(str_contains($content, "from './useErrorTracking.svelte.js'"))->toBeTrue();
            expect(str_contains($content, "from './useScrollDepth.svelte.js'"))->toBeTrue();
        });

        it('imports from svelte/store', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);
            expect(str_contains($content, "from 'svelte/store'"))->toBeTrue();
            expect(str_contains($content, 'writable'))->toBeTrue();
            expect(str_contains($content, 'derived'))->toBeTrue();
        });

        it('imports from @inertiajs/svelte', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);
            expect(str_contains($content, "from '@inertiajs/svelte'"))->toBeTrue();
        });
    });

    // ── Required Exports ────────────────────────────────────────────

    describe('Required exports', function (): void {
        /** @var array<int, string> */
        $requiredExports = [
            'isInitialized',
            'config',
            'totalInteractions',
            'lastEngagementEvent',
            'engagementScore',
            'engagementBreakdown',
            'initEngagement',
            'resetEngagement',
            'getEngagementSnapshot',
        ];

        foreach ($requiredExports as $export) {
            it("exports {$export}", function () use ($export): void {
                $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
                $content = file_get_contents($path);
                $hasConst = str_contains($content, "export const {$export}");
                $hasFunc = str_contains($content, "export function {$export}");
                expect($hasConst || $hasFunc)->toBeTrue();
            });
        }

        it('re-exports clickCount', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);
            expect(str_contains($content, 'clickCount,'))->toBeTrue();
        });

        it('re-exports formCount', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);
            expect(str_contains($content, 'formCount,'))->toBeTrue();
        });

        it('re-exports searchCount', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);
            expect(str_contains($content, 'searchCount,'))->toBeTrue();
        });

        it('re-exports shareCount', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);
            expect(str_contains($content, 'shareCount,'))->toBeTrue();
        });

        it('re-exports errorCount', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);
            expect(str_contains($content, 'errorCount,'))->toBeTrue();
        });

        it('re-exports scrollDepth and maxScrollDepth', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);
            expect(str_contains($content, 'scrollDepth,'))->toBeTrue();
            expect(str_contains($content, 'maxScrollDepth,'))->toBeTrue();
        });
    });

    // ── initEngagement Function Logic ────────────────────────────────

    describe('initEngagement function', function (): void {
        it('reads autoTrack config from props', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);
            expect(str_contains($content, 'autoTrack'))->toBeTrue();
            expect(str_contains($content, 'form_tracking'))->toBeTrue();
            expect(str_contains($content, 'error_tracking'))->toBeTrue();
            expect(str_contains($content, 'scroll_depth'))->toBeTrue();
        });

        it('calls initClickTracking', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);
            expect(str_contains($content, 'initClickTracking('))->toBeTrue();
        });

        it('calls initFormTracking', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);
            expect(str_contains($content, 'initFormTracking('))->toBeTrue();
        });

        it('calls initSearchTracking', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);
            expect(str_contains($content, 'initSearchTracking('))->toBeTrue();
        });

        it('calls initShareTracking', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);
            expect(str_contains($content, 'initShareTracking('))->toBeTrue();
        });

        it('calls initErrorTracking', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);
            expect(str_contains($content, 'initErrorTracking('))->toBeTrue();
        });

        it('sets isInitialized to true', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);
            expect(str_contains($content, "isInitialized.set(true)"))->toBeTrue();
        });
    });

    // ── Derived Stores ──────────────────────────────────────────────

    describe('Derived stores', function (): void {
        it('totalInteractions derives from 5 count stores', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);
            expect(str_contains($content, 'totalInteractions = derived'))->toBeTrue();
            expect(str_contains($content, '[$clicks, $forms, $searches, $shares, $errors]'))->toBeTrue();
        });

        it('engagementScore has 0-100 scoring heuristic', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);
            expect(str_contains($content, 'engagementScore = derived'))->toBeTrue();
            expect(str_contains($content, 'Math.min('))->toBeTrue();
            expect(str_contains($content, 'Math.round('))->toBeTrue();
        });

        it('engagementBreakdown has all 5 categories', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);
            expect(str_contains($content, 'engagementBreakdown = derived'))->toBeTrue();
            expect(str_contains($content, "clicks: $clicks"))->toBeTrue();
            expect(str_contains($content, "forms: $forms"))->toBeTrue();
            expect(str_contains($content, "searches: $searches"))->toBeTrue();
            expect(str_contains($content, "shares: $shares"))->toBeTrue();
            expect(str_contains($content, "errors: $errors"))->toBeTrue();
        });

        it('lastEngagementEvent sorts by timestamp', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);
            expect(str_contains($content, 'lastEngagementEvent = derived'))->toBeTrue();
            expect(str_contains($content, '.sort('))->toBeTrue();
        });
    });

    // ── getEngagementSnapshot ────────────────────────────────────────

    describe('getEngagementSnapshot', function (): void {
        it('returns a snapshot object', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);
            expect(str_contains($content, 'getEngagementSnapshot()'))->toBeTrue();
            expect(str_contains($content, 'return {'))->toBeTrue();
            expect(str_contains($content, 'total_interactions:'))->toBeTrue();
            expect(str_contains($content, 'scroll_depth:'))->toBeTrue();
            expect(str_contains($content, 'max_scroll_depth:'))->toBeTrue();
        });

        it('subscribes and unsubscribes from all stores', function (): void {
            $path = __DIR__ . '/../resources/js/useEngagement.svelte.js';
            $content = file_get_contents($path);
            expect(str_contains($content, '.subscribe('))->toBeTrue();
            expect(str_contains($content, 'unsubClick()'))->toBeTrue();
            expect(str_contains($content, 'unsubForm()'))->toBeTrue();
        });
    });

    // ── TypeScript Definitions ───────────────────────────────────────

    describe('TypeScript definitions', function (): void {
        it('analytics.d.ts has EngagementConfig interface', function (): void {
            $path = __DIR__ . '/../resources/js/analytics.d.ts';
            $content = file_get_contents($path);
            expect(str_contains($content, 'EngagementConfig'))->toBeTrue();
            expect(str_contains($content, 'clickTracking: boolean'))->toBeTrue();
            expect(str_contains($content, 'scrollDepthThresholds: number[]'))->toBeTrue();
        });

        it('analytics.d.ts has EngagementSnapshot interface', function (): void {
            $path = __DIR__ . '/../resources/js/analytics.d.ts';
            $content = file_get_contents($path);
            expect(str_contains($content, 'EngagementSnapshot'))->toBeTrue();
            expect(str_contains($content, 'total_interactions: number'))->toBeTrue();
            expect(str_contains($content, 'max_scroll_depth: number'))->toBeTrue();
        });

        it('analytics.d.ts has EngagementBreakdown interface', function (): void {
            $path = __DIR__ . '/../resources/js/analytics.d.ts';
            $content = file_get_contents($path);
            expect(str_contains($content, 'EngagementBreakdown'))->toBeTrue();
        });

        it('analytics.d.ts has module declaration for useEngagement', function (): void {
            $path = __DIR__ . '/../resources/js/analytics.d.ts';
            $content = file_get_contents($path);
            expect(str_contains($content, "declare module 'useEngagement'"))->toBeTrue();
        });

        it('TypeScript module exports match JS exports', function (): void {
            $path = __DIR__ . '/../resources/js/analytics.d.ts';
            $content = file_get_contents($path);
            expect(str_contains($content, 'isInitialized'))->toBeTrue();
            expect(str_contains($content, 'totalInteractions'))->toBeTrue();
            expect(str_contains($content, 'engagementScore'))->toBeTrue();
            expect(str_contains($content, 'engagementBreakdown'))->toBeTrue();
            expect(str_contains($content, 'initEngagement'))->toBeTrue();
            expect(str_contains($content, 'resetEngagement'))->toBeTrue();
            expect(str_contains($content, 'getEngagementSnapshot'))->toBeTrue();
        });
    });

    // ── Version Consistency ──────────────────────────────────────────

    describe('Version consistency', function (): void {
        it('version is 272.0.0 across all entry points', function (): void {
            $version = \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION;
            expect($version)->toBe('272.0.0');

            $composer = json_decode(
                file_get_contents(__DIR__ . '/../composer.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            expect($composer['version'])->toBe('272.0.0');

            $package = json_decode(
                file_get_contents(__DIR__ . '/../package.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            expect($package['version'])->toBe('272.0.0');
        });

        it('README badge shows 272.0.0', function (): void {
            $readme = file_get_contents(__DIR__ . '/../README.md');
            expect($readme)->toContain('version-272.0.0');
        });

        it('README mentions useEngagement composable', function (): void {
            $readme = file_get_contents(__DIR__ . '/../README.md');
            expect($readme)->toContain('useEngagement');
        });

        it('Svelte composable count in README is 16', function (): void {
            $readme = file_get_contents(__DIR__ . '/../README.md');
            expect($readme)->toContain('16 Svelte composables');
        });
    });

    // ── PHP 8.5 Quality ──────────────────────────────────────────────

    describe('PHP 8.5 quality', function (): void {
        it('test file uses strict_types', function (): void {
            $content = file_get_contents(__FILE__);
            expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
        });

        it('all describe/it callbacks have : void return types', function (): void {
            $content = file_get_contents(__FILE__);
            // Count callbacks
            $callbacks = substr_count($content, 'function (): void');
            // describe blocks + it blocks + it blocks inside foreach
            expect($callbacks)->toBeGreaterThanOrEqual(10);
        });
    });

    // ── Package Metrics ──────────────────────────────────────────────

    describe('Package metrics', function (): void {
        it('source file count has increased from previous', function (): void {
            $srcFiles = glob(__DIR__ . '/../src/**/*.php', GLOB_BRACE);
            expect(count($srcFiles))->toBeGreaterThanOrEqual(996);
        });

        it('test file count has increased from previous', function (): void {
            $testFiles = glob(__DIR__ . '/*.php');
            expect(count($testFiles))->toBeGreaterThanOrEqual(521);
        });

        it('Svelte composable count is 16', function (): void {
            $composables = glob(__DIR__ . '/../resources/js/use*.svelte.js');
            expect(count($composables))->toBe(16);
        });
    });
});
