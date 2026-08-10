<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\EventContext;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EventContextSnapshotService;
use ZeroBoiler\Analytics\Services\UserJourneyReconstructionService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;

/**
 * V8.5.0 — Event Context Snapshot & User Journey Reconstruction Test Suite.
 *
 * Tests the two new v8.5.0 services:
 * 1. EventContextSnapshotService — point-in-time context capture, caching, replay, GDPR erasure
 * 2. UserJourneyReconstructionService — journey recording, finalization, funnel analysis, GDPR erasure
 */
beforeEach(function (): void {
    $this->cache = app(CacheRepository::class);
    $this->config = app(ConfigRepository::class);

    $this->config->set('zeroboiler.analytics.context_snapshot', [
        'cache_prefix' => 'test_ctx_snap_',
        'snapshot_ttl' => 3600,
        'max_snapshots_per_client' => 10,
    ]);

    $this->config->set('zeroboiler.analytics.journey_reconstruction', [
        'cache_prefix' => 'test_journey_',
        'cache_ttl' => 3600,
        'max_journeys_per_user' => 5,
        'max_steps_per_journey' => 50,
    ]);

    $this->config->set('zeroboiler.analytics.gdpr', [
        'anonymize_ip' => true,
        'ip_mask_v4' => 2,
        'ip_mask_v6' => 48,
    ]);
});

afterEach(function (): void {
    // Clean up test cache keys
    try {
        // This is a no-op in array cache; Redis would need explicit cleanup
    } catch (\Throwable) {
        // Ignore
    }
});

// ─── EventContextSnapshotService Tests ──────────────────────────────────

test('EventContextSnapshotService: captures snapshot with all expected fields', function (): void {
    $service = new EventContextSnapshotService($this->cache, $this->config);

    $context = new EventContext(
        clientId: 'client_abc123',
        userId: 'user_456',
        sessionId: 'sess_789',
        ip: '192.168.1.100',
        userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X)',
        path: '/dashboard',
        referrer: 'https://google.com',
        locale: 'en-US',
        country: 'US',
        consentGranted: true,
    );

    $snapshot = $service->capture($context, 'page_view', 5);

    expect($snapshot)->toHaveKeys([
        'snapshot_id',
        'event_name',
        'captured_at',
        'device',
        'session',
        'geographic',
        'behavioral',
        'consent',
        'client_id',
        'user_id',
    ]);

    expect($snapshot['event_name'])->toBe('page_view');
    expect($snapshot['client_id'])->toBe('client_abc123');
    expect($snapshot['user_id'])->toBe('user_456');
});

test('EventContextSnapshotService: device snapshot contains fingerprint and type', function (): void {
    $service = new EventContextSnapshotService($this->cache, $this->config);

    $context = new EventContext(
        clientId: 'client_dev',
        userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X)',
        device: [
            'type' => 'mobile',
            'browser' => 'Safari',
            'os' => 'iOS',
        ],
    );

    $snapshot = $service->capture($context, 'click');

    expect($snapshot['device']['type'])->toBe('mobile');
    expect($snapshot['device']['browser'])->toBe('Safari');
    expect($snapshot['device']['os'])->toBe('iOS');
    expect($snapshot['device']['fingerprint'])->toBeString();
    expect(strlen($snapshot['device']['fingerprint']))->toBe(12);
});

test('EventContextSnapshotService: geographic snapshot anonymizes IP when enabled', function (): void {
    $service = new EventContextSnapshotService($this->cache, $this->config);

    $context = new EventContext(
        clientId: 'client_ip',
        ip: '192.168.1.100',
        country: 'DE',
        locale: 'de-DE',
    );

    $snapshot = $service->capture($context, 'page_view');

    expect($snapshot['geographic']['ip'])->toBe('192.168.1.100');
    expect($snapshot['geographic']['ip_anonymized'])->toBe('192.168.0.0');
    expect($snapshot['geographic']['country'])->toBe('DE');
    expect($snapshot['geographic']['locale'])->toBe('de-DE');
});

test('EventContextSnapshotService: behavioral snapshot calculates velocity score', function (): void {
    $service = new EventContextSnapshotService($this->cache, $this->config);

    $context = new EventContext(clientId: 'client_vel');

    // Low velocity
    $snapshot = $service->capture($context, 'page_view', 1);
    expect($snapshot['behavioral']['velocity_score'])->toBe('low');
    expect($snapshot['behavioral']['engagement_signal'])->toBe('active');

    // High velocity
    $snapshot = $service->capture($context, 'click', 50);
    expect($snapshot['behavioral']['velocity_score'])->toBe('high');
    expect($snapshot['behavioral']['engagement_signal'])->toBe('power_user');
});

test('EventContextSnapshotService: consent snapshot reflects context state', function (): void {
    $service = new EventContextSnapshotService($this->cache, $this->config);

    // With consent
    $context = new EventContext(
        clientId: 'client_con',
        userId: 'user_1',
        consentGranted: true,
    );
    $snapshot = $service->capture($context, 'page_view');

    expect($snapshot['consent']['granted'])->toBeTrue();
    expect($snapshot['consent']['has_user'])->toBeTrue();
    expect($snapshot['consent']['has_client'])->toBeTrue();

    // Without consent
    $contextNoConsent = new EventContext(
        clientId: 'client_nocon',
        consentGranted: false,
    );
    $snapshot = $service->capture($contextNoConsent, 'page_view');

    expect($snapshot['consent']['granted'])->toBeFalse();
    expect($snapshot['consent']['has_user'])->toBeFalse();
});

test('EventContextSnapshotService: session snapshot hashes session ID', function (): void {
    $service = new EventContextSnapshotService($this->cache, $this->config);

    $context = new EventContext(
        clientId: 'client_sess',
        sessionId: 'session_secret_value',
        path: '/pricing',
        referrer: 'https://example.com/landing',
    );

    $snapshot = $service->capture($context, 'page_view');

    // Session ID should be hashed, not raw
    expect($snapshot['session']['session_id'])->toBeString();
    expect($snapshot['session']['session_id'])->not->toBe('session_secret_value');
    expect($snapshot['session']['path'])->toBe('/pricing');
    expect($snapshot['session']['referrer'])->toBe('https://example.com/landing');
});

test('EventContextSnapshotService: captures snapshot without client ID gracefully', function (): void {
    $service = new EventContextSnapshotService($this->cache, $this->config);

    $context = new EventContext(
        ip: '10.0.0.1',
        userAgent: 'curl/7.68.0',
    );

    $snapshot = $service->capture($context, 'api_call', 0);

    expect($snapshot['event_name'])->toBe('api_call');
    expect($snapshot['client_id'])->toBeNull();
    expect($snapshot['user_id'])->toBeNull();
    expect($snapshot['device']['type'])->toBe('Unknown');
});

test('EventContextSnapshotService: IPv6 anonymization works correctly', function (): void {
    $service = new EventContextSnapshotService($this->cache, $this->config);

    $context = new EventContext(
        clientId: 'client_v6',
        ip: '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
    );

    $snapshot = $service->capture($context, 'page_view');

    // With 48-bit mask (3 groups), the first 3 groups are preserved
    expect($snapshot['geographic']['ip_anonymized'])->toBeString();
    expect($snapshot['geographic']['ip_anonymized'])->toStartWith('2001:db8:85a3:');
});

test('EventContextSnapshotService: stats returns expected structure', function (): void {
    $service = new EventContextSnapshotService($this->cache, $this->config);

    $stats = $service->stats();

    expect($stats)->toHaveKeys([
        'total_cached_snapshots',
        'clients_with_snapshots',
        'cache_prefix',
        'ttl',
    ]);
    expect($stats['cache_prefix'])->toBe('test_ctx_snap_');
    expect($stats['ttl'])->toBe(3600);
});

// ─── UserJourneyReconstructionService Tests ────────────────────────────

test('UserJourneyReconstructionService: records first step and starts journey', function (): void {
    $metrics = app(AnalyticsMetrics::class);
    $service = new UserJourneyReconstructionService($this->cache, $metrics, $this->config);

    $event = new AnalyticsEvent('page_view', ['path' => '/home']);

    $service->recordStep($event, 'user_1', 'client_a', 'sess_1');

    $journey = $service->getActiveJourney('user_1');

    expect($journey)->not->toBeEmpty();
    expect($journey['user_id'])->toBe('user_1');
    expect($journey['client_id'])->toBe('client_a');
    expect($journey['status'])->toBe('active');
    expect($journey['event_count'])->toBe(1);
    expect($journey['steps'][0]['event'])->toBe('page_view');
});

test('UserJourneyReconstructionService: appends steps to active journey', function (): void {
    $metrics = app(AnalyticsMetrics::class);
    $service = new UserJourneyReconstructionService($this->cache, $metrics, $this->config);

    $service->recordStep(new AnalyticsEvent('page_view'), 'user_2', 'client_b');
    $service->recordStep(new AnalyticsEvent('click'), 'user_2', 'client_b');
    $service->recordStep(new AnalyticsEvent('sign_up'), 'user_2', 'client_b');

    $journey = $service->getActiveJourney('user_2');

    expect($journey['event_count'])->toBe(3);
    expect($journey['steps'][0]['event'])->toBe('page_view');
    expect($journey['steps'][1]['event'])->toBe('click');
    expect($journey['steps'][2]['event'])->toBe('sign_up');
});

test('UserJourneyReconstructionService: finalizes journey and starts new one', function (): void {
    $metrics = app(AnalyticsMetrics::class);
    $service = new UserJourneyReconstructionService($this->cache, $metrics, $this->config);

    $service->recordStep(new AnalyticsEvent('login'), 'user_3', 'client_c');
    $service->recordStep(new AnalyticsEvent('page_view'), 'user_3', 'client_c');

    $result = $service->finalizeJourney('user_3');

    expect($result['completed'])->toBeTrue();
    expect($result['journey']['status'])->toBe('completed');
    expect($result['journey']['ended_at'])->not->toBeNull();
    expect($result['journey']['duration_seconds'])->toBeGreaterThanOrEqual(0);

    // New journey should be empty after finalization
    $active = $service->getActiveJourney('user_3');
    expect($active)->toBeEmpty();
});

test('UserJourneyReconstructionService: completed journeys are retrievable', function (): void {
    $metrics = app(AnalyticsMetrics::class);
    $service = new UserJourneyReconstructionService($this->cache, $metrics, $this->config);

    $service->recordStep(new AnalyticsEvent('page_view'), 'user_4');
    $service->finalizeJourney('user_4');

    $completed = $service->getCompletedJourneys('user_4');

    expect($completed)->not->toBeEmpty();
    expect($completed[0]['status'])->toBe('completed');
    expect($completed[0]['event_count'])->toBe(1);
});

test('UserJourneyReconstructionService: analyzes funnel progress', function (): void {
    $metrics = app(AnalyticsMetrics::class);
    $service = new UserJourneyReconstructionService($this->cache, $metrics, $this->config);

    // Record steps that match a signup → trial → subscribe funnel
    $service->recordStep(new AnalyticsEvent('sign_up'), 'user_5');
    $service->recordStep(new AnalyticsEvent('start_trial'), 'user_5');
    $service->recordStep(new AnalyticsEvent('subscribe'), 'user_5');

    $analysis = $service->analyzeFunnelProgress(
        ['sign_up', 'start_trial', 'subscribe'],
        'user_5',
    );

    expect($analysis['completed_steps'])->toBe(3);
    expect($analysis['total_steps'])->toBe(3);
    expect($analysis['completion_rate'])->toBe(100.0);
    expect($analysis['current_step'])->toBe('subscribe');
    expect($analysis['next_expected'])->toBeNull();
});

test('UserJourneyReconstructionService: funnel progress shows partial completion', function (): void {
    $metrics = app(AnalyticsMetrics::class);
    $service = new UserJourneyReconstructionService($this->cache, $metrics, $this->config);

    $service->recordStep(new AnalyticsEvent('sign_up'), 'user_6');
    $service->recordStep(new AnalyticsEvent('page_view'), 'user_6');
    // Missing: start_trial and subscribe

    $analysis = $service->analyzeFunnelProgress(
        ['sign_up', 'start_trial', 'subscribe'],
        'user_6',
    );

    expect($analysis['completed_steps'])->toBe(1);
    expect($analysis['completion_rate'])->toBe(33.33);
    expect($analysis['current_step'])->toBe('sign_up');
    expect($analysis['next_expected'])->toBe('start_trial');
});

test('UserJourneyReconstructionService: handles empty funnel gracefully', function (): void {
    $metrics = app(AnalyticsMetrics::class);
    $service = new UserJourneyReconstructionService($this->cache, $metrics, $this->config);

    $analysis = $service->analyzeFunnelProgress([], 'user_7');

    expect($analysis['completed_steps'])->toBe(0);
    expect($analysis['total_steps'])->toBe(0);
    expect($analysis['completion_rate'])->toBe(0.0);
});

test('UserJourneyReconstructionService: sanitizes sensitive params in journey steps', function (): void {
    $metrics = app(AnalyticsMetrics::class);
    $service = new UserJourneyReconstructionService($this->cache, $metrics, $this->config);

    $event = new AnalyticsEvent('login', [
        'email' => 'user@example.com',
        'password' => 'secret123',
        'api_key' => 'sk_live_abc',
        'method' => 'email',
    ]);

    $service->recordStep($event, 'user_8');

    $journey = $service->getActiveJourney('user_8');
    $params = $journey['steps'][0]['params'];

    // Sensitive keys should be stripped
    expect($params)->toHaveKey('email');
    expect($params)->not->toHaveKey('password');
    expect($params)->not->toHaveKey('api_key');
    expect($params)->toHaveKey('method');
});

test('UserJourneyReconstructionService: records without identity gracefully', function (): void {
    $metrics = app(AnalyticsMetrics::class);
    $service = new UserJourneyReconstructionService($this->cache, $metrics, $this->config);

    $event = new AnalyticsEvent('page_view');

    // Should not throw, just silently skip
    $service->recordStep($event, null, null, null);

    $journey = $service->getActiveJourney(null, null);
    expect($journey)->toBeEmpty();
});

test('UserJourneyReconstructionService: eraseUser removes all journey data', function (): void {
    $metrics = app(AnalyticsMetrics::class);
    $service = new UserJourneyReconstructionService($this->cache, $metrics, $this->config);

    $service->recordStep(new AnalyticsEvent('sign_up'), 'user_erase');
    $service->recordStep(new AnalyticsEvent('login'), 'user_erase');
    $service->finalizeJourney('user_erase');

    // Start a new journey
    $service->recordStep(new AnalyticsEvent('page_view'), 'user_erase');

    $erased = $service->eraseUser('user_erase');

    expect($erased)->toBeGreaterThanOrEqual(1); // At least the new active journey

    $active = $service->getActiveJourney('user_erase');
    expect($active)->toBeEmpty();

    $completed = $service->getCompletedJourneys('user_erase');
    expect($completed)->toBeEmpty();
});

test('UserJourneyReconstructionService: stats returns expected structure', function (): void {
    $metrics = app(AnalyticsMetrics::class);
    $service = new UserJourneyReconstructionService($this->cache, $metrics, $this->config);

    $stats = $service->stats();

    expect($stats)->toHaveKeys([
        'total_active_journeys',
        'cache_prefix',
        'ttl',
    ]);
    expect($stats['cache_prefix'])->toBe('test_journey_');
});

// ─── Integration Tests ──────────────────────────────────────────────────

test('Integration: snapshot captures context from same identity as journey', function (): void {
    $snapshotService = new EventContextSnapshotService($this->cache, $this->config);
    $metrics = app(AnalyticsMetrics::class);
    $journeyService = new UserJourneyReconstructionService($this->cache, $metrics, $this->config);

    $context = new EventContext(
        clientId: 'client_int',
        userId: 'user_int',
        ip: '203.0.113.50',
        path: '/signup',
        consentGranted: true,
    );

    $event = new AnalyticsEvent('sign_up', ['method' => 'email']);

    // Record in both services
    $snapshot = $snapshotService->capture($context, 'sign_up', 2);
    $journeyService->recordStep($event, 'user_int', 'client_int');

    // Verify consistency
    expect($snapshot['client_id'])->toBe('client_int');
    expect($snapshot['user_id'])->toBe('user_int');

    $journey = $journeyService->getActiveJourney('user_int');
    expect($journey['user_id'])->toBe('user_int');
    expect($journey['client_id'])->toBe('client_int');
    expect($journey['steps'][0]['event'])->toBe('sign_up');
});

test('Integration: journey funnel analysis matches catalog events', function (): void {
    $metrics = app(AnalyticsMetrics::class);
    $journeyService = new UserJourneyReconstructionService($this->cache, $metrics, $this->config);

    // Verify the funnel events exist in the catalog
    expect(EventCatalog::has('sign_up'))->toBeTrue();
    expect(EventCatalog::has('start_trial'))->toBeTrue();
    expect(EventCatalog::has('subscribe'))->toBeTrue();

    // Record the funnel
    $journeyService->recordStep(new AnalyticsEvent('sign_up'), 'user_cat');
    $journeyService->recordStep(new AnalyticsEvent('start_trial'), 'user_cat');
    $journeyService->recordStep(new AnalyticsEvent('subscribe'), 'user_cat');

    $analysis = $journeyService->analyzeFunnelProgress(
        ['sign_up', 'start_trial', 'subscribe'],
        'user_cat',
    );

    expect($analysis['completion_rate'])->toBe(100.0);

    // Verify catalog metadata matches
    $signUp = EventCatalog::get('sign_up');
    expect($signUp['category'])->toBe('saas');

    $subscribe = EventCatalog::get('subscribe');
    expect($subscribe['ga4'])->toBe('purchase');
});
