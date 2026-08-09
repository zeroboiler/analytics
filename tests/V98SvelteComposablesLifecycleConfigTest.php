<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;

test('v3.3.1 version is set correctly', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('3.3.1');
});

test('lifecycle config contains all DEFAULT_MAPPINGS event toggles', function (): void {
    $config = include __DIR__.'/../config/zeroboiler.php';
    $lifecycleEvents = $config['analytics']['lifecycle']['events'] ?? [];

    // Expected event keys from LifecycleEventMapper::DEFAULT_MAPPINGS
    $expectedKeys = [
        'auth.login',
        'auth.register',
        'auth.logout',
        'subscription.created',
        'subscription.upgraded',
        'subscription.downgraded',
        'subscription.cancelled',
        'subscription.renewal',
        'trial.started',
        'trial.ended',
        'feature.used',
        'feature.limit_reached',
        'order.completed',
        'order.refunded',
        'form.submitted',
        'search.performed',
        'error.occurred',
        'account.activated',
        'account.deactivated',
        'account.email_verified',
        'account.password_changed',
        'account.password_reset',
        'account.profile_updated',
        'team.created',
        'team.member_joined',
        'team.member_removed',
        'team.role_changed',
        'team.invite_sent',
        'billing.payment_succeeded',
        'billing.payment_failed',
        'billing.payment_method_added',
        'billing.invoice_generated',
        'billing.credit_applied',
        'integration.connected',
        'integration.failed',
        'account.deleted',
        'consent.granted',
        'consent.withdrawn',
        'gdpr.data_subject_access_request',
        'gdpr.data_erasure_completed',
        'plan.changed',
        'billing.payment_method_updated',
        'subscription.created_new',
        'subscription.cancelled_new',
        'subscription.resumed',
        'trial.expired',
        'trial.converted',
        'subscription.value_changed',
        'usage.quota_reached',
        'billing.retry',
        'subscription.paused',
        'workspace.created',
        'milestone.reached',
        'team.invite_accepted',
        'subscription.trial_end_reminder',
    ];

    foreach ($expectedKeys as $key) {
        expect(array_key_exists($key, $lifecycleEvents))
            ->toBe(true, "Missing lifecycle event toggle: {$key}");
        expect(is_bool($lifecycleEvents[$key]))
            ->toBe(true, "Lifecycle event toggle '{$key}' must be boolean");
    }
});

test('lifecycle config toggle count matches expected total', function (): void {
    $config = include __DIR__.'/../config/zeroboiler.php';
    $lifecycleEvents = $config['analytics']['lifecycle']['events'] ?? [];

    // 50 event toggles expected
    expect(count($lifecycleEvents))->toBeGreaterThanOrEqual(50);
});

test('event catalog is valid', function (): void {
    $result = EventCatalog::validate();

    expect($result['valid'])->toBe(true, 'Event catalog validation failed: '.implode(', ', $result['errors']));
    expect($result['errors'])->toBeEmpty();
});

test('event catalog has revenue events', function (): void {
    $revenueEvents = EventCatalog::revenueEvents();

    expect($revenueEvents)->not->toBeEmpty();
    $names = array_map(fn (array $e): string => $e['name'], $revenueEvents);

    expect($names)->toContain('purchase');
    expect($names)->toContain('subscribe');
    expect($names)->toContain('revenue_tracked');
});

test('event catalog has core SaaS events', function (): void {
    $coreEvents = EventCatalog::coreSaaS();

    expect($coreEvents)->not->toBeEmpty();
    $names = array_map(fn (array $e): string => $e['name'], $coreEvents);

    expect($names)->toContain('sign_up');
    expect($names)->toContain('login');
    expect($names)->toContain('start_trial');
    expect($names)->toContain('subscribe');
    expect($names)->toContain('plan_upgrade');
});

test('event catalog has GDPR events', function (): void {
    $gdprEvents = EventCatalog::gdprEvents();

    expect($gdprEvents)->not->toBeEmpty();
    $names = array_map(fn (array $e): string => $e['name'], $gdprEvents);

    expect($names)->toContain('sign_up');
    expect($names)->toContain('account_deleted');
    expect($names)->toContain('consent_granted');
    expect($names)->toContain('consent_withdrawn');
    expect($names)->toContain('data_subject_access_request');
    expect($names)->toContain('data_erasure_completed');
});

test('event catalog has Plausible and PostHog mappings', function (): void {
    $allPlausible = EventCatalog::allPlausibleNames();
    $allPosthog = EventCatalog::allPosthogNames();

    expect($allPlausible)->not->toBeEmpty();
    expect($allPosthog)->not->toBeEmpty();

    // At least pageview should be supported everywhere
    expect($allPlausible)->toContain('pageview');
    expect($allPosthog)->toContain('$pageview');
});

test('Svelte composables file exists and is valid JS', function (): void {
    $path = __DIR__.'/../resources/js/useAnalytics.svelte.js';
    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);
    expect($content)->toContain('export function useAnalytics');
    expect($content)->toContain('export function useTrackEvents');
    expect($content)->toContain('export function useEcommerce');
    expect($content)->toContain('export function useSaaSLifecycle');
    expect($content)->toContain('export function useConsent');
    expect($content)->toContain('export function useAnalyticsDebug');
    expect($content)->toContain('export function cleanupAnalytics');
    expect($content)->toContain('@version 3.3.1');
});

test('Svelte composables use $state and $derived runes', function (): void {
    $content = file_get_contents(__DIR__.'/../resources/js/useAnalytics.svelte.js');

    expect($content)->toContain('$state(');
    expect($content)->toContain('$derived(');
    expect($content)->toContain('$effect(');
});

test('JS client version is 3.3.1', function (): void {
    $content = file_get_contents(__DIR__.'/../resources/js/analytics.js');

    expect($content)->toContain("'3.3.1'");
});

test('composer.json version is 3.3.1', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($composer['version'])->toBe('3.3.1');
});
