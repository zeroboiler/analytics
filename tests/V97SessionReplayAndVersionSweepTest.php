<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;

beforeEach(function (): void {
    // Reset static catalogs by accessing them (lazy init pattern)
});

describe('V97 Session Replay Composable Integration', function (): void {
    test('useSessionReplay composable file exists and is valid JS', function (): void {
        $path = __DIR__ . '/../../resources/js/useSessionReplay.svelte.js';

        expect(file_exists($path))->toBeTrue();

        $content = file_get_contents($path);
        expect($content)->not->toBeFalse();
        expect(str_contains($content, 'export function useSessionReplay'))->toBeTrue();
        expect(str_contains($content, 'export function sessionReplay'))->toBeTrue();
        expect(str_contains($content, 'export default useSessionReplay'))->toBeTrue();
    });

    test('composable exports all required reactive stores', function (): void {
        $content = file_get_contents(__DIR__ . '/../../resources/js/useSessionReplay.svelte.js');

        // Reactive stores
        expect(str_contains($content, 'export const recordingActive'))->toBeTrue();
        expect(str_contains($content, 'export const recordingSessionId'))->toBeTrue();
        expect(str_contains($content, 'export const recordingProvider'))->toBeTrue();
        expect(str_contains($content, 'export const recordingSettings'))->toBeTrue();
        expect(str_contains($content, 'export const eventCount'))->toBeTrue();
        expect(str_contains($content, 'export const sessionReplayAvailable'))->toBeTrue();
        expect(str_contains($content, 'export const recordingError'))->toBeTrue();
    });

    test('composable provides start/stop/pause/resume lifecycle methods', function (): void {
        $content = file_get_contents(__DIR__ . '/../../resources/js/useSessionReplay.svelte.js');

        expect(str_contains($content, 'function start()'))->toBeTrue();
        expect(str_contains($content, 'function stop()'))->toBeTrue();
        expect(str_contains($content, 'function pause()'))->toBeTrue();
        expect(str_contains($content, 'function resume()'))->toBeTrue();
        expect(str_contains($content, 'function captureSnapshot()'))->toBeTrue();
        expect(str_contains($content, 'function captureEvent('))->toBeTrue();
    });

    test('composable sanitizes sensitive elements in DOM snapshots', function (): void {
        $content = file_get_contents(__DIR__ . '/../../resources/js/useSessionReplay.svelte.js');

        // Sensitive selectors
        expect(str_contains($content, '[type="password"]'))->toBeTrue();
        expect(str_contains($content, '[data-sensitive="true"]'))->toBeTrue();
        expect(str_contains($content, '.zb-no-record'))->toBeTrue();
        expect(str_contains($content, 'input[name*="card"]'))->toBeTrue();
        expect(str_contains($content, 'input[name*="ssn"]'))->toBeTrue();

        // Redaction logic
        expect(str_contains($content, "'[redacted]'"))->toBeTrue();
    });

    test('composable uses analytics pipeline for session replay events', function (): void {
        $content = file_get_contents(__DIR__ . '/../../resources/js/useSessionReplay.svelte.js');

        expect(str_contains($content, "'session_replay_start'"))->toBeTrue();
        expect(str_contains($content, "'session_replay_stop'"))->toBeTrue();
        expect(str_contains($content, "'session_replay_snapshot'"))->toBeTrue();
        expect(str_contains($content, "'session_replay_event'"))->toBeTrue();
        expect(str_contains($content, "'session_replay_pause'"))->toBeTrue();
        expect(str_contains($content, "'session_replay_resume'"))->toBeTrue();
        expect(str_contains($content, 'trackEvent('))->toBeTrue();
    });

    test('composable has privacy-safe selector generation', function (): void {
        $content = file_get_contents(__DIR__ . '/../../resources/js/useSessionReplay.svelte.js');

        expect(str_contains($content, 'function getMinimalSelector('))->toBeTrue();
        expect(str_contains($content, 'data-testid'))->toBeTrue();
    });

    test('composable imports from analytics.js core module', function (): void {
        $content = file_get_contents(__DIR__ . '/../../resources/js/useSessionReplay.svelte.js');

        expect(str_contains($content, "from './analytics.js'"))->toBeTrue();
        expect(str_contains($content, 'trackEvent'))->toBeTrue();
        expect(str_contains($content, 'isInitialized'))->toBeTrue();
    });

    test('composable uses Svelte writable and derived stores', function (): void {
        $content = file_get_contents(__DIR__ . '/../../resources/js/useSessionReplay.svelte.js');

        expect(str_contains($content, "import { writable, derived } from 'svelte/store'"))->toBeTrue();
    });

    test('composable has auto-start and duration limit support', function (): void {
        $content = file_get_contents(__DIR__ . '/../../resources/js/useSessionReplay.svelte.js');

        expect(str_contains($content, 'maxDuration'))->toBeTrue();
        expect(str_contains($content, 'autoStart'))->toBeTrue();
    });
});

describe('V97 Version Consistency Sweep (legacy — archived)', function (): void {
    test('all source files no longer reference version 96.0.0', function (): void {
        $files = [
            __DIR__ . '/../../composer.json',
            __DIR__ . '/../../src/DTO/AnalyticsEvent.php',
            __DIR__ . '/../../resources/js/analytics.js',
            __DIR__ . '/../../resources/js/analytics.d.ts',
            __DIR__ . '/../../resources/js/useAnalytics.svelte.js',
            __DIR__ . '/../../resources/js/useAnalyticsConfig.svelte.js',
            __DIR__ . '/../../resources/js/useLifecycle.svelte.js',
            __DIR__ . '/../../resources/js/usePerformanceTracker.svelte.js',
            __DIR__ . '/../../resources/js/useSessionReplay.svelte.js',
        ];

        foreach ($files as $file) {
            if (! file_exists($file)) {
                continue;
            }

            $content = file_get_contents($file);

            // Old versions should not be present
            expect(str_contains($content, '96.0.0'))->toBeFalse("Old version 96.0.0 still in: {$file}");
        }
    });

    test('README contains v97.0.0 release notes', function (): void {
        $readme = file_get_contents(__DIR__ . '/../../README.md');
        expect(str_contains($readme, 'Version 97.0.0'))->toBeTrue();
        expect(str_contains($readme, 'useSessionReplay'))->toBeTrue();
        expect(str_contains($readme, 'Session Replay Svelte Composable'))->toBeTrue();
    });
});

describe('V97 Event Catalog Coverage Validation', function (): void {
    test('EcommerceEvents catalog contains all required events', function (): void {
        $required = ['view_item', 'add_to_cart', 'purchase', 'refund'];
        $names = EcommerceEvents::names();

        foreach ($required as $event) {
            expect(EcommerceEvents::has($event))->toBeTrue("Missing ecommerce event: {$event}");
            expect(in_array($event, $names, true))->toBeTrue();
        }
    });

    test('SaaSEvents catalog contains all core SaaS lifecycle events', function (): void {
        $required = ['sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade', 'cancellation'];
        $names = SaaSEvents::names();

        foreach ($required as $event) {
            expect(SaaSEvents::has($event))->toBeTrue("Missing SaaS event: {$event}");
            expect(in_array($event, $names, true))->toBeTrue();
        }
    });

    test('EngagementEvents catalog contains all core engagement events', function (): void {
        $required = ['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'search', 'share', 'error'];
        $names = EngagementEvents::names();

        foreach ($required as $event) {
            expect(EngagementEvents::has($event))->toBeTrue("Missing engagement event: {$event}");
            expect(in_array($event, $names, true))->toBeTrue();
        }
    });

    test('each catalog event has a valid class reference', function (): void {
        $catalogs = [
            EcommerceEvents::class => EcommerceEvents::all(),
            SaaSEvents::class => SaaSEvents::all(),
            EngagementEvents::class => EngagementEvents::all(),
        ];

        foreach ($catalogs as $catalogClass => $entries) {
            foreach ($entries as $name => $entry) {
                expect(isset($entry['class']))->toBeTrue("Missing class in {$catalogClass}::{$name}");
                expect($entry['class'])->toBeString();
                expect(class_exists($entry['class']))->toBeTrue("Class {$entry['class']} does not exist for {$catalogClass}::{$name}");
            }
        }
    });

    test('each catalog event has GA4 and PostHog provider names', function (): void {
        $catalogs = [
            EcommerceEvents::all(),
            SaaSEvents::all(),
            EngagementEvents::all(),
        ];

        foreach ($catalogs as $entries) {
            foreach ($entries as $name => $entry) {
                expect(isset($entry['ga4']))->toBeTrue("Missing ga4 in {$name}");
                expect(isset($entry['posthog']))->toBeTrue("Missing posthog in {$name}");
            }
        }
    });

    test('total event count across catalogs is 100+', function (): void {
        $total = EcommerceEvents::count()
            + SaaSEvents::count()
            + EngagementEvents::count();

        expect($total)->toBeGreaterThanOrEqual(100);
    });
});
