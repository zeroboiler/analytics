<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\SaaSStarterEvents;
use ZeroBoiler\Analytics\Services\LifecycleMappingValidator;

describe('v271 — Inertia Lifecycle Health & Starter Events Props', function (): void {
    test('LifecycleMappingValidator override_defaults=true clears default source registry', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn([
                'custom_mappings' => [
                    'auth.login' => [
                        'source' => 'Illuminate\Auth\Events\Login',
                        'target' => \ZeroBoiler\Analytics\Events\SaaS\LoginEvent::class,
                    ],
                ],
                'override_defaults' => true,
            ]);

        $validator = new LifecycleMappingValidator($config);

        // With override_defaults=true, the default auth.login source should NOT
        // trigger a duplicate warning since defaults were cleared
        $warnings = array_filter(
            $validator->getIssues(),
            fn (array $issue): bool => $issue['severity'] === 'warning' && str_contains($issue['message'], 'already mapped'),
        );

        expect(count($warnings))->toBe(0);
    });

    test('LifecycleMappingValidator override_defaults=false keeps default sources', function (): void {
        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn([
                'custom_mappings' => [
                    'auth.login' => [
                        'source' => 'Illuminate\Auth\Events\Login',
                        'target' => \ZeroBoiler\Analytics\Events\SaaS\LoginEvent::class,
                    ],
                ],
                'override_defaults' => false,
            ]);

        $validator = new LifecycleMappingValidator($config);

        // With override_defaults=false, the custom auth.login should clash with
        // the built-in default auth.login mapping, producing a duplicate warning
        $duplicateWarnings = array_filter(
            $validator->getIssues(),
            fn (array $issue): bool => $issue['severity'] === 'warning'
                && str_contains($issue['message'], 'already mapped'),
        );

        expect(count($duplicateWarnings))->toBeGreaterThanOrEqual(1);
    });

    test('SaaSStarterEvents::instrumentationPayload returns correct structure', function (): void {
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

        expect($payload['total'])->toBe(20);
        expect($payload['coverage'])->toBeGreaterThanOrEqual(0.0);
        expect($payload['coverage'])->toBeLessThanOrEqual(100.0);
        expect($payload['categories'])->toHaveKeys(['saas', 'ecommerce', 'engagement']);
        expect(count($payload['events']))->toBe(20);
        expect(count($payload['priorityOrder']))->toBe(20);
        expect(is_list($payload['gaps']))->toBeTrue();
        expect(is_int($payload['gapCount']))->toBeTrue();
    });

    test('SaaSStarterEvents::instrumentationPayload events include priority_index and is_gap', function (): void {
        $payload = SaaSStarterEvents::instrumentationPayload();

        foreach ($payload['events'] as $event) {
            expect($event)->toHaveKeys(['name', 'label', 'category', 'hint', 'priority_index', 'is_gap']);
            expect($event['priority_index'])->toBeInt();
            expect($event['is_gap'])->toBeBool();
        }
    });

    test('SaaSStarterEvents::instrumentationPayload priority_order matches priorityOrder array', function (): void {
        $payload = SaaSStarterEvents::instrumentationPayload();

        $standalone = SaaSStarterEvents::priorityOrder();

        expect($payload['priorityOrder'])->toBe($standalone);
    });

    test('SaaSStarterEvents::instrumentationPayload gapCount equals gaps array length', function (): void {
        $payload = SaaSStarterEvents::instrumentationPayload();

        expect($payload['gapCount'])->toBe(count($payload['gaps']));
    });

    test('SaaSStarterEvents categories sum to total', function (): void {
        $payload = SaaSStarterEvents::instrumentationPayload();

        $sum = $payload['categories']['saas']
            + $payload['categories']['ecommerce']
            + $payload['categories']['engagement'];

        expect($sum)->toBe($payload['total']);
    });

    test('LifecycleMappingValidator::validateMapping static method rejects non-AnalyticsEvent targets', function (): void {
        $issues = LifecycleMappingValidator::validateMapping(
            'test.event',
            [
                'source' => 'test.event',
                'target' => \stdClass::class,
            ],
        );

        expect($issues)->not->toBeEmpty();
        $hasExtendError = false;
        foreach ($issues as $issue) {
            if (str_contains($issue['message'], 'extend AnalyticsEvent')) {
                $hasExtendError = true;
                break;
            }
        }
        expect($hasExtendError)->toBeTrue();
    });

    test('LifecycleMappingValidator static validates empty key', function (): void {
        $issues = LifecycleMappingValidator::validateMapping('', []);
        expect($issues)->not->toBeEmpty();
        expect($issues[0]['severity'])->toBe('error');
    });

    test('AnalyticsEvent::VERSION is 271.0.0', function (): void {
        expect(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION)->toBe('271.0.0');
    });

    test('useStarterEvents.svelte.js composable file exists with correct exports', function (): void {
        $path = __DIR__ . '/../resources/js/useStarterEvents.svelte.js';
        expect(file_exists($path))->toBeTrue();

        $content = file_get_contents($path);

        expect($content)->toContain('export const starterEvents');
        expect($content)->toContain('export const lifecycleHealth');
        expect($content)->toContain('export const sortedEvents');
        expect($content)->toContain('export const gapEvents');
        expect($content)->toContain('export const coverage');
        expect($content)->toContain('export const gapCount');
        expect($content)->toContain('export const isFullyCovered');
        expect($content)->toContain('export const isLifecycleValid');
        expect($content)->toContain('export function initStarterEvents');
        expect($content)->toContain('export function resetStarterEvents');
        expect($content)->toContain('271.0.0');
    });

    test('analytics.js exports getLifecycleHealth and getStarterEvents', function (): void {
        $path = __DIR__ . '/../resources/js/analytics.js';
        $content = file_get_contents($path);

        expect($content)->toContain('export function getLifecycleHealth()');
        expect($content)->toContain('export function getStarterEvents()');
        expect($content)->toContain('config?.lifecycleHealth');
        expect($content)->toContain('config?.starterEvents');
    });

    test('analytics.d.ts defines LifecycleHealth and StarterEventsPayload', function (): void {
        $path = __DIR__ . '/../resources/js/analytics.d.ts';
        $content = file_get_contents($path);

        expect($content)->toContain('export interface LifecycleHealth');
        expect($content)->toContain('export interface StarterEventEntry');
        expect($content)->toContain('export interface StarterEventsPayload');
        expect($content)->toContain('lifecycleHealth: LifecycleHealth');
        expect($content)->toContain('starterEvents: StarterEventsPayload');
    });

    test('HandleInertiaAnalytics injects lifecycleHealth and starterEvents props', function (): void {
        $path = __DIR__ . '/../src/Inertia/HandleInertiaAnalytics.php';
        $content = file_get_contents($path);

        expect($content)->toContain("'lifecycleHealth'");
        expect($content)->toContain("'starterEvents'");
        expect($content)->toContain('getLifecycleHealth');
        expect($content)->toContain('SaaSStarterEvents::instrumentationPayload');
        expect($content)->toContain('@since 271.0.0');
    });

    test('composer.json version is 271.0.0', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        expect($composer['version'])->toBe('271.0.0');
    });
});
