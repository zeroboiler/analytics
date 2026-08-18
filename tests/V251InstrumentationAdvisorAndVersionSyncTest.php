<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\SaaSStarterEvents;

// ── Version Consistency Across All Entry Points ──────────────────────────

describe('V251 Version Consistency', function () {
    it('PHP DTO VERSION matches composer.json', function () {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        $composerVersion = $composer['version'];
        $phpVersion = AnalyticsEvent::VERSION;

        expect($phpVersion)->toBe($composerVersion);
        expect($phpVersion)->toBe('251.0.0');
    });

    it('package.json version matches composer.json', function () {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        $package = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);

        expect($composer['version'])->toBe($package['version']);
    });

    it('analytics.js getVersion returns correct version', function () {
        $jsContent = file_get_contents(__DIR__ . '/../resources/js/analytics.js');

        // Check getVersion() return value
        expect($jsContent)->toContain("return '251.0.0'");

        // Check JSDoc version
        expect($jsContent)->toContain('@version 251.0.0');
    });

    it('all Svelte composables have updated version', function () {
        $composableFiles = glob(__DIR__ . '/../resources/js/use*.svelte.js');
        expect($composableFiles)->not->toBeEmpty();

        foreach ($composableFiles as $file) {
            $content = file_get_contents($file);
            expect($content)->toContain('@version 251.0.0');
        }
    });

    it('analytics.constants.js has updated version', function () {
        $content = file_get_contents(__DIR__ . '/../resources/js/analytics.constants.js');
        expect($content)->toContain('@version 251.0.0');
    });

    it('analytics.d.ts has updated version', function () {
        $content = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
        expect($content)->toContain('@version 251.0.0');
    });
});

// ── SaaSStarterEvents::instrumentationPayload() ──────────────────────────

describe('SaaSStarterEvents instrumentationPayload', function () {
    it('returns all required top-level keys', function () {
        $payload = SaaSStarterEvents::instrumentationPayload();

        expect($payload)->toHaveKeys([
            'total',
            'coverage',
            'categories',
            'events',
            'gaps',
            'gapCount',
            'priorityOrder',
        ]);
    });

    it('has correct total event count (20)', function () {
        $payload = SaaSStarterEvents::instrumentationPayload();

        expect($payload['total'])->toBe(20);
        expect(count($payload['events']))->toBe(20);
    });

    it('priority order has 20 entries matching SaaSStarterEvents::names()', function () {
        $payload = SaaSStarterEvents::instrumentationPayload();

        expect(count($payload['priorityOrder']))->toBe(20);
        expect($payload['priorityOrder'])->toBe(SaaSStarterEvents::names());
    });

    it('each event has priority_index and is_gap keys', function () {
        $payload = SaaSStarterEvents::instrumentationPayload();

        foreach ($payload['events'] as $event) {
            expect($event)->toHaveKey('name');
            expect($event)->toHaveKey('label');
            expect($event)->toHaveKey('category');
            expect($event)->toHaveKey('hint');
            expect($event)->toHaveKey('priority_index');
            expect($event)->toHaveKey('is_gap');
            expect(is_int($event['priority_index']))->toBeTrue();
            expect(is_bool($event['is_gap']))->toBeTrue();
        }
    });

    it('priority_index is 0 for sign_up (first in priority order)', function () {
        $payload = SaaSStarterEvents::instrumentationPayload();

        $signUp = array_filter(
            $payload['events'],
            fn (array $e): bool => $e['name'] === 'sign_up',
        );
        $signUp = reset($signUp);

        expect($signUp)->not->toBeFalse();
        expect($signUp['priority_index'])->toBe(0);
    });

    it('gapCount matches gaps array length', function () {
        $payload = SaaSStarterEvents::instrumentationPayload();

        expect($payload['gapCount'])->toBe(count($payload['gaps']));
    });

    it('gaps are a subset of total events', function () {
        $payload = SaaSStarterEvents::instrumentationPayload();
        $allNames = array_column($payload['events'], 'name');

        foreach ($payload['gaps'] as $gap) {
            expect(in_array($gap, $allNames, true))->toBeTrue();
        }
    });

    it('categories contain saas, ecommerce, and engagement counts', function () {
        $payload = SaaSStarterEvents::instrumentationPayload();

        expect($payload['categories'])->toHaveKeys(['saas', 'ecommerce', 'engagement']);
        expect($payload['categories']['saas'])->toBeGreaterThan(0);
        expect($payload['categories']['ecommerce'])->toBeGreaterThan(0);
        expect($payload['categories']['engagement'])->toBeGreaterThan(0);
    });

    it('events with is_gap=true correspond to gaps array', function () {
        $payload = SaaSStarterEvents::instrumentationPayload();

        $gapEvents = array_filter(
            $payload['events'],
            fn (array $e): bool => $e['is_gap'] === true,
        );
        $gapEventNames = array_column($gapEvents, 'name');
        sort($gapEventNames);
        $gapsSorted = $payload['gaps'];
        sort($gapsSorted);

        expect($gapEventNames)->toBe($gapsSorted);
    });

    it('is serializable to JSON without errors', function () {
        $payload = SaaSStarterEvents::instrumentationPayload();

        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        expect($json)->toBeString();
        expect(strlen($json))->toBeGreaterThan(100);
    });

    it('clientSummary still works independently', function () {
        $summary = SaaSStarterEvents::clientSummary();

        expect($summary)->toHaveKeys(['total', 'coverage', 'categories', 'events']);
        // clientSummary should NOT have the instrumentation-specific keys
        expect($summary)->not->toHaveKey('gaps');
        expect($summary)->not->toHaveKey('gapCount');
        expect($summary)->not->toHaveKey('priorityOrder');
    });

    it('is_gap is false for events present in EventCatalog', function () {
        $payload = SaaSStarterEvents::instrumentationPayload();

        foreach ($payload['events'] as $event) {
            $inCatalog = \ZeroBoiler\Analytics\Events\EventCatalog::has($event['name']);
            // is_gap should be the opposite of catalog presence
            expect($event['is_gap'])->toBe(! $inCatalog);
        }
    });
});

// ── useInstrumentationAdvisor.svelte.js Exists & Structure ────────────────

describe('useInstrumentationAdvisor composable', function () {
    it('composable file exists', function () {
        expect(file_exists(__DIR__ . '/../resources/js/useInstrumentationAdvisor.svelte.js'))->toBeTrue();
    });

    it('exports useInstrumentationAdvisor function', function () {
        $content = file_get_contents(__DIR__ . '/../resources/js/useInstrumentationAdvisor.svelte.js');
        expect($content)->toContain('export function useInstrumentationAdvisor');
    });

    it('exports getAdvisorSnapshot function', function () {
        $content = file_get_contents(__DIR__ . '/../resources/js/useInstrumentationAdvisor.svelte.js');
        expect($content)->toContain('export function getAdvisorSnapshot');
    });

    it('exports all required stores', function () {
        $content = file_get_contents(__DIR__ . '/../resources/js/useInstrumentationAdvisor.svelte.js');

        $requiredStores = [
            'maturityScore',
            'maturityGrade',
            'maturityLabel',
            'maturityColor',
            'gaps',
            'onboardingCompletion',
            'recommendedEvents',
            'funnelReadiness',
            'suggestions',
            'criticalGapCount',
            'isProductionReady',
            'summary',
        ];

        foreach ($requiredStores as $store) {
            expect($content)->toContain("export const {$store}");
        }
    });

    it('has correct version tag', function () {
        $content = file_get_contents(__DIR__ . '/../resources/js/useInstrumentationAdvisor.svelte.js');
        expect($content)->toContain('@version 251.0.0');
    });

    it('references Inertia page store', function () {
        $content = file_get_contents(__DIR__ . '/../resources/js/useInstrumentationAdvisor.svelte.js');
        expect($content)->toContain("from '@inertiajs/svelte'");
        expect($content)->toContain('page.subscribe');
    });

    it('reads zbAnalytics props', function () {
        $content = file_get_contents(__DIR__ . '/../resources/js/useInstrumentationAdvisor.svelte.js');
        expect($content)->toContain('zbAnalytics');
    });

    it('defines grade thresholds A+ through F', function () {
        $content = file_get_contents(__DIR__ . '/../resources/js/useInstrumentationAdvisor.svelte.js');

        $grades = ['A+', 'A', 'B+', 'B', 'C+', 'C', 'D', 'F'];

        foreach ($grades as $grade) {
            expect($content)->toContain("'{$grade}'");
        }
    });

    it('defines all 20 SaaS starter event hints', function () {
        $content = file_get_contents(__DIR__ . '/../resources/js/useInstrumentationAdvisor.svelte.js');

        $starterEvents = [
            'sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade',
            'cancellation', 'feature_used', 'page_view', 'scroll_depth', 'click',
            'form_start', 'form_submit', 'search', 'share', 'error',
            'view_item', 'add_to_cart', 'purchase', 'refund', 'trial_converted',
        ];

        foreach ($starterEvents as $event) {
            expect($content)->toContain("'{$event}'");
        }
    });

    it('defines instrumentation tiers', function () {
        $content = file_get_contents(__DIR__ . '/../resources/js/useInstrumentationAdvisor.svelte.js');

        expect($content)->toContain('Identity (Must Track)');
        expect($content)->toContain('Activation');
        expect($content)->toContain('Revenue');
        expect($content)->toContain('Engagement & Retention');
    });

    it('implements getTierSummary method', function () {
        $content = file_get_contents(__DIR__ . '/../resources/js/useInstrumentationAdvisor.svelte.js');
        expect($content)->toContain('function getTierSummary()');
    });

    it('implements getGrade method', function () {
        $content = file_get_contents(__DIR__ . '/../resources/js/useInstrumentationAdvisor.svelte.js');
        expect($content)->toContain('function getGrade(score)');
    });

    it('is valid JavaScript (no syntax errors)', function () {
        // Use Node.js to check syntax
        $exitCode = 0;
        $output = '';
        exec(
            'node --check ' . escapeshellarg(__DIR__ . '/../resources/js/useInstrumentationAdvisor.svelte.js') . ' 2>&1',
            $output,
            $exitCode,
        );

        expect($exitCode)->toBe(0);
    });
});

// ── package.json Exports ────────────────────────────────────────────────

describe('package.json exports', function () {
    it('includes advisor export path', function () {
        $package = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);

        expect(isset($package['exports']['./advisor']))->toBeTrue();
    });

    it('advisor export points to the new composable', function () {
        $package = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);

        $advisorExport = $package['exports']['./advisor'];
        expect($advisorExport['import'])->toContain('useInstrumentationAdvisor.svelte.js');
    });

    it('files array includes resources/js/', function () {
        $package = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);

        expect(in_array('resources/js/', $package['files'], true))->toBeTrue();
    });
});
