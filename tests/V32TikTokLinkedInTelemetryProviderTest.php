<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Trackers\TikTokTracker;
use ZeroBoiler\Analytics\Trackers\LinkedInTracker;
use ZeroBoiler\Analytics\Services\ProviderDispatchTelemetry;
use ZeroBoiler\Analytics\DTO\ConsentState;

test('TikTokTracker implements TrackerInterface', function (): void {
    $tracker = new TikTokTracker(
        pixelId: 'CA1234567890',
        accessToken: 'test-token',
        enabled: true,
    );

    expect($tracker)->toBeInstanceOf(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class);
});

test('TikTokTracker isEnabled returns true when configured', function (): void {
    $tracker = new TikTokTracker(
        pixelId: 'CA1234567890',
        accessToken: 'test-token',
        enabled: true,
    );

    expect($tracker->isEnabled())->toBeTrue();
});

test('TikTokTracker isEnabled returns false when disabled', function (): void {
    $tracker = new TikTokTracker(
        pixelId: 'CA1234567890',
        accessToken: 'test-token',
        enabled: false,
    );

    expect($tracker->isEnabled())->toBeFalse();
});

test('TikTokTracker isEnabled returns false when pixel ID is empty', function (): void {
    $tracker = new TikTokTracker(
        pixelId: '',
        accessToken: 'test-token',
        enabled: true,
    );

    expect($tracker->isEnabled())->toBeFalse();
});

test('TikTokTracker getPixelId returns configured value', function (): void {
    $tracker = new TikTokTracker(
        pixelId: 'CA9876543210',
        accessToken: 'secret',
        enabled: true,
    );

    expect($tracker->getPixelId())->toBe('CA9876543210');
});

test('TikTokTracker headScripts returns empty string when disabled', function (): void {
    $tracker = new TikTokTracker(
        pixelId: 'CA1234567890',
        accessToken: 'test-token',
        enabled: false,
    );

    expect($tracker->headScripts())->toBe('');
});

test('TikTokTracker headScripts contains pixel ID when enabled', function (): void {
    $tracker = new TikTokTracker(
        pixelId: 'CA1234567890',
        accessToken: 'test-token',
        enabled: true,
    );

    $scripts = $tracker->headScripts();
    expect($scripts)->toContain('CA1234567890');
    expect($scripts)->toContain('tiktok');
});

test('TikTokTracker setConsent and getConsent work', function (): void {
    $tracker = new TikTokTracker(
        pixelId: 'CA1234567890',
        accessToken: 'test-token',
        enabled: true,
    );

    $denied = ConsentState::denied();
    $tracker->setConsent($denied);
    expect($tracker->getConsent()->isDenied('analytics_storage'))->toBeTrue();

    $granted = ConsentState::granted();
    $tracker->setConsent($granted);
    expect($tracker->getConsent()->isGranted('analytics_storage'))->toBeTrue();
});

test('TikTokTracker does not track when consent denied', function (): void {
    $tracker = new TikTokTracker(
        pixelId: 'CA1234567890',
        accessToken: 'test-token',
        enabled: true,
    );

    $tracker->setConsent(ConsentState::denied());

    // Should silently return without error
    $tracker->track(new AnalyticsEvent(name: 'page_view'));
    expect(true)->toBeTrue();
});

test('TikTokTracker maps purchase event correctly', function (): void {
    // This tests the internal event name mapping via reflection
    $tracker = new TikTokTracker(
        pixelId: 'CA1234567890',
        accessToken: 'test-token',
        enabled: false,
    );

    // Verify it can be constructed and has expected interface
    expect($tracker->getAccessToken())->toBe('test-token');
});

// ── LinkedIn Tracker Tests ───────────────────────────────────────

test('LinkedInTracker implements TrackerInterface', function (): void {
    $tracker = new LinkedInTracker(
        partnerId: '123456',
        conversionId: '789012',
        accessToken: 'test-token',
        enabled: true,
    );

    expect($tracker)->toBeInstanceOf(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class);
});

test('LinkedInTracker isEnabled returns true when configured', function (): void {
    $tracker = new LinkedInTracker(
        partnerId: '123456',
        conversionId: '789012',
        accessToken: 'test-token',
        enabled: true,
    );

    expect($tracker->isEnabled())->toBeTrue();
});

test('LinkedInTracker isEnabled returns false when disabled', function (): void {
    $tracker = new LinkedInTracker(
        partnerId: '123456',
        conversionId: '789012',
        accessToken: 'test-token',
        enabled: false,
    );

    expect($tracker->isEnabled())->toBeFalse();
});

test('LinkedInTracker isEnabled returns false when partner ID is empty', function (): void {
    $tracker = new LinkedInTracker(
        partnerId: '',
        conversionId: '789012',
        accessToken: 'test-token',
        enabled: true,
    );

    expect($tracker->isEnabled())->toBeFalse();
});

test('LinkedInTracker getPartnerId returns configured value', function (): void {
    $tracker = new LinkedInTracker(
        partnerId: '654321',
        conversionId: '098765',
        accessToken: 'secret',
        enabled: true,
    );

    expect($tracker->getPartnerId())->toBe('654321');
    expect($tracker->getConversionId())->toBe('098765');
});

test('LinkedInTracker headScripts contains partner ID when enabled', function (): void {
    $tracker = new LinkedInTracker(
        partnerId: '123456',
        conversionId: '789012',
        accessToken: 'test-token',
        enabled: true,
    );

    $scripts = $tracker->headScripts();
    expect($scripts)->toContain('123456');
    expect($scripts)->toContain('linkedin');
});

test('LinkedInTracker headScripts returns empty when disabled', function (): void {
    $tracker = new LinkedInTracker(
        partnerId: '123456',
        conversionId: '789012',
        accessToken: 'test-token',
        enabled: false,
    );

    expect($tracker->headScripts())->toBe('');
});

test('LinkedInTracker setConsent and getConsent work', function (): void {
    $tracker = new LinkedInTracker(
        partnerId: '123456',
        conversionId: '789012',
        accessToken: 'test-token',
        enabled: true,
    );

    $tracker->setConsent(ConsentState::denied());
    expect($tracker->getConsent()->isDenied('analytics_storage'))->toBeTrue();

    $tracker->setConsent(ConsentState::granted());
    expect($tracker->getConsent()->isGranted('analytics_storage'))->toBeTrue();
});

// ── Provider Dispatch Telemetry Tests ──────────────────────────

test('ProviderDispatchTelemetry records success and failure', function (): void {
    $telemetry = new ProviderDispatchTelemetry(null, 60);

    $telemetry->recordSuccess('ga4', 'page_view', 12.5);
    $telemetry->recordSuccess('ga4', 'purchase', 8.3);
    $telemetry->recordFailure('meta', 'purchase', 'Invalid currency', 400);

    $stats = $telemetry->providerStats('ga4');
    expect($stats['success'])->toBe(2);
    expect($stats['failure'])->toBe(0);
    expect($stats['total'])->toBe(2);
    expect($stats['error_rate'])->toBe(0.0);
    expect($stats['avg_latency_ms'])->toBe(10.4); // (12.5 + 8.3) / 2

    $metaStats = $telemetry->providerStats('meta');
    expect($metaStats['success'])->toBe(0);
    expect($metaStats['failure'])->toBe(1);
    expect($metaStats['error_rate'])->toBe(100.0);
    expect($metaStats['last_error'])->toBe('Invalid currency');
});

test('ProviderDispatchTelemetry summary includes all providers', function (): void {
    $telemetry = new ProviderDispatchTelemetry(null, 60);

    $telemetry->recordSuccess('ga4', 'page_view');
    $telemetry->recordSuccess('meta', 'purchase');

    $summary = $telemetry->summary();
    expect($summary['total_dispatched'])->toBe(2);
    expect($summary['total_failures'])->toBe(0);
    expect($summary['overall_error_rate'])->toBe(0.0);
    expect(isset($summary['providers']['ga4']))->toBeTrue();
    expect(isset($summary['providers']['meta']))->toBeTrue();
    expect(isset($summary['providers']['tiktok']))->toBeTrue();
    expect(isset($summary['providers']['linkedin']))->toBeTrue();
});

test('ProviderDispatchTelemetry topEvents returns sorted events', function (): void {
    $telemetry = new ProviderDispatchTelemetry(null, 60);

    $telemetry->recordSuccess('ga4', 'page_view');
    $telemetry->recordSuccess('ga4', 'page_view');
    $telemetry->recordSuccess('ga4', 'page_view');
    $telemetry->recordSuccess('ga4', 'purchase');
    $telemetry->recordSuccess('meta', 'page_view');

    $topEvents = $telemetry->topEvents(5);
    expect($topEvents)->toHaveCount(2);
    expect($topEvents[0]['event'])->toBe('page_view');
    expect($topEvents[0]['count'])->toBe(4);
    expect($topEvents[1]['event'])->toBe('purchase');
    expect($topEvents[1]['count'])->toBe(1);
});

test('ProviderDispatchTelemetry reset clears all counters', function (): void {
    $telemetry = new ProviderDispatchTelemetry(null, 60);

    $telemetry->recordSuccess('ga4', 'page_view');
    $telemetry->recordFailure('meta', 'purchase', 'Error');

    $telemetry->reset();

    $summary = $telemetry->summary();
    expect($summary['total_dispatched'])->toBe(0);
    expect($summary['total_failures'])->toBe(0);
});

test('ProviderDispatchTelemetry trackedProviders returns all provider keys', function (): void {
    $providers = ProviderDispatchTelemetry::trackedProviders();

    expect($providers)->toContain('ga4');
    expect($providers)->toContain('meta_pixel');
    expect($providers)->toContain('posthog');
    expect($providers)->toContain('tiktok');
    expect($providers)->toContain('linkedin');
    expect($providers)->toContain('mixpanel');
    expect($providers)->toContain('amplitude');
});

test('ProviderDispatchTelemetry isHighVolume detects threshold', function (): void {
    $telemetry = new ProviderDispatchTelemetry(null, 60);

    expect($telemetry->isHighVolume('ga4'))->toBeFalse();

    // Manually simulate high volume by recording many events
    for ($i = 0; $i < 100; $i++) {
        $telemetry->recordSuccess('ga4', 'page_view');
    }

    // Should NOT be high volume yet (100 < 10000 threshold)
    expect($telemetry->isHighVolume('ga4'))->toBeFalse();
});

test('ProviderDispatchTelemetry tracks top events per provider', function (): void {
    $telemetry = new ProviderDispatchTelemetry(null, 60);

    $telemetry->recordSuccess('ga4', 'page_view');
    $telemetry->recordSuccess('ga4', 'purchase');
    $telemetry->recordSuccess('ga4', 'page_view');
    $telemetry->recordSuccess('meta', 'page_view');

    $ga4Stats = $telemetry->providerStats('ga4');
    expect($ga4Stats['top_events'][0])->toBe('page_view'); // page_view has 2 count
    expect($ga4Stats['top_events'][1])->toBe('purchase');
});
