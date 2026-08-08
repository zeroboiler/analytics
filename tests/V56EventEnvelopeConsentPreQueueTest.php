<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\DTO\EventContextEvent;
use ZeroBoiler\Analytics\Pipeline\ConsentAwareFilter;
use ZeroBoiler\Analytics\Services\ConsentLogService;
use ZeroBoiler\Analytics\Services\EventEnvelopeService;

beforeEach(function (): void {
    $this->cache = app('cache');
    $this->cache->flush();
});

// ── EventContextEvent DTO ────────────────────────────────────────

test('EventContextEvent class exists and is readonly', function (): void {
    expect(class_exists(EventContextEvent::class))->toBeTrue();

    $reflection = new ReflectionClass(EventContextEvent::class);
    expect($reflection->isReadOnly())->toBeTrue();
    expect($reflection->isFinal())->toBeTrue();
});

test('EventContextEvent fromEvent shorthand', function (): void {
    $event = new AnalyticsEvent(name: 'page_view', params: ['url' => '/home']);
    $ctx = EventContextEvent::fromEvent($event, [
        'device' => ['browser' => 'Chrome'],
        'utm' => ['source' => 'google'],
    ]);

    expect($ctx->event->name)->toBe('page_view');
    expect($ctx->device)->toBe(['browser' => 'Chrome']);
    expect($ctx->utm)->toBe(['source' => 'google']);
    expect($ctx->session)->toBeEmpty();
    expect($ctx->geo)->toBeEmpty();
});

test('EventContextEvent toArray serialization', function (): void {
    $event = new AnalyticsEvent(
        name: 'purchase',
        params: ['value' => 99.99],
        clientId: 'client-1',
        userId: 'user-1',
    );

    $ctx = new EventContextEvent(
        event: $event,
        session: ['id' => 'sess-1'],
        device: ['browser' => 'Firefox'],
        identity: ['user_id' => 'user-1', 'is_authenticated' => true],
    );

    $arr = $ctx->toArray();
    expect($arr['event']['name'])->toBe('purchase');
    expect($arr['session']['id'])->toBe('sess-1');
    expect($arr['device']['browser'])->toBe('Firefox');
    expect($arr['identity']['user_id'])->toBe('user-1');
});

test('EventContextEvent flattenedParams with underscore prefix', function (): void {
    $event = new AnalyticsEvent(
        name: 'sign_up',
        params: ['method' => 'email'],
        clientId: 'c1',
    );

    $ctx = new EventContextEvent(
        event: $event,
        device: ['browser' => 'Safari'],
        utm: ['source' => 'twitter'],
    );

    $flat = $ctx->flattenedParams();
    expect($flat)->toHaveKey('method');
    expect($flat)->toHaveKey('_device_browser');
    expect($flat['_device_browser'])->toBe('Safari');
    expect($flat)->toHaveKey('_utm_source');
    expect($flat['_utm_source'])->toBe('twitter');
});

test('EventContextEvent hasContext check', function (): void {
    $event = new AnalyticsEvent(name: 'click', params: []);
    $ctx = new EventContextEvent(
        event: $event,
        device: ['browser' => 'Chrome'],
    );

    expect($ctx->hasContext('device'))->toBeTrue();
    expect($ctx->hasContext('geo'))->toBeFalse();
    expect($ctx->hasContext('session'))->toBeFalse();
});

test('EventContextEvent hasFullIdentity', function (): void {
    $eventFull = new AnalyticsEvent(name: 'test', params: [], clientId: 'c1', userId: 'u1');
    $ctxFull = new EventContextEvent(
        event: $eventFull,
        identity: ['user_id' => 'u1', 'client_id' => 'c1', 'is_authenticated' => true],
    );
    expect($ctxFull->hasFullIdentity())->toBeTrue();

    $eventPartial = new AnalyticsEvent(name: 'test', params: [], clientId: 'c1');
    $ctxPartial = new EventContextEvent(event: $eventPartial);
    expect($ctxPartial->hasFullIdentity())->toBeFalse();
});

// ── EventEnvelopeService ────────────────────────────────────────

test('EventEnvelopeService class exists', function (): void {
    expect(class_exists(EventEnvelopeService::class))->toBeTrue();
});

test('EventEnvelopeService builds from event without request', function (): void {
    $config = mock(Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.envelope', [])->andReturn([
        'enabled' => true,
        'session' => true,
        'device' => true,
        'geo' => false,
        'identity' => true,
        'utm' => true,
        'referrer' => true,
        'consent' => true,
        'metadata' => true,
    ]);

    $service = new EventEnvelopeService($this->cache, $config);

    $event = new AnalyticsEvent(name: 'login', params: ['method' => 'google'], userId: 'u1', clientId: 'c1');
    $envelope = $service->buildFromEvent($event);

    expect($envelope)->toBeInstanceOf(EventContextEvent::class);
    expect($envelope->event->name)->toBe('login');
    expect($envelope->identity['user_id'])->toBe('u1');
    expect($envelope->identity['client_id'])->toBe('c1');
    expect($envelope->identity['is_authenticated'])->toBeTrue();
    expect($envelope->metadata['source'])->toBe('server');
});

test('EventEnvelopeService disabled returns plain event', function (): void {
    $config = mock(Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.envelope', [])->andReturn([
        'enabled' => false,
    ]);

    $service = new EventEnvelopeService($this->cache, $config);

    $event = new AnalyticsEvent(name: 'test', params: []);
    $envelope = $service->buildFromEvent($event);

    expect($envelope->event->name)->toBe('test');
    expect($envelope->identity)->toBeEmpty();
    expect($envelope->device)->toBeEmpty();
});

test('EventEnvelopeService activeSections', function (): void {
    $config = mock(Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.envelope', [])->andReturn([
        'enabled' => true,
        'session' => true,
        'device' => false,
        'geo' => false,
        'identity' => true,
        'utm' => true,
        'referrer' => false,
        'consent' => false,
        'metadata' => true,
    ]);

    $service = new EventEnvelopeService($this->cache, $config);
    $sections = $service->activeSections();

    expect($sections)->toContain('session');
    expect($sections)->toContain('identity');
    expect($sections)->toContain('utm');
    expect($sections)->toContain('metadata');
    expect($sections)->not->toContain('device');
    expect($sections)->not->toContain('geo');
});

test('EventEnvelopeService summary', function (): void {
    $config = mock(Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.envelope', [])->andReturn([
        'enabled' => true,
    ]);

    $service = new EventEnvelopeService($this->cache, $config);
    $summary = $service->summary();

    expect($summary['enabled'])->toBeTrue();
    expect($summary['version'])->toBe('2.89.0');
    expect($summary['sections'])->toBeArray();
});

// ── ConsentAwareFilter ──────────────────────────────────────────

test('ConsentAwareFilter class exists', function (): void {
    expect(class_exists(ConsentAwareFilter::class))->toBeTrue();
});

test('ConsentAwareFilter allows all events when disabled', function (): void {
    $filter = new ConsentAwareFilter(enabled: false);
    $event = new AnalyticsEvent(name: 'purchase', params: []);

    $result = $filter->process($event);
    expect($result)->not->toBeNull();
    expect($result->name)->toBe('purchase');
});

test('ConsentAwareFilter allows necessary-only events', function (): void {
    $filter = new ConsentAwareFilter(enabled: true);
    $event = new AnalyticsEvent(name: 'error', params: ['message' => 'test']);

    $result = $filter->process($event);
    expect($result)->not->toBeNull();
    expect($result->name)->toBe('error');
});

test('ConsentAwareFilter allows identify events', function (): void {
    $filter = new ConsentAwareFilter(enabled: true);
    $event = new AnalyticsEvent(name: 'identify', params: ['user_id' => 'u1']);

    $result = $filter->process($event);
    expect($result)->not->BeNull();
});

test('ConsentAwareFilter drops analytics event when consent denied', function (): void {
    $filter = new ConsentAwareFilter(enabled: true);
    $event = new AnalyticsEvent(name: 'page_view', params: []);
    $consentState = ConsentState::denied();

    $result = $filter->process($event, $consentState);
    expect($result)->toBeNull();
});

test('ConsentAwareFilter allows analytics event when consent granted', function (): void {
    $filter = new ConsentAwareFilter(enabled: true);
    $event = new AnalyticsEvent(name: 'page_view', params: []);
    $consentState = ConsentState::granted();

    $result = $filter->process($event, $consentState);
    expect($result)->not->toBeNull();
    expect($result->name)->toBe('page_view');
});

test('ConsentAwareFilter drops ecommerce event when only analytics granted', function (): void {
    $filter = new ConsentAwareFilter(enabled: true);
    $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99]);

    // Grant analytics_storage but deny functionality_storage
    $consentState = new ConsentState([
        'analytics_storage' => 'granted',
        'functionality_storage' => 'denied',
    ]);

    $result = $filter->process($event, $consentState);
    // purchase requires analytics + functional; functional is denied
    expect($result)->toBeNull();
});

test('ConsentAwareFilter allows ecommerce event when both granted', function (): void {
    $filter = new ConsentAwareFilter(enabled: true);
    $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99]);
    $consentState = ConsentState::granted();

    $result = $filter->process($event, $consentState);
    expect($result)->not->toBeNull();
});

test('ConsentAwareFilter drops marketing event when ad_storage denied', function (): void {
    $filter = new ConsentAwareFilter(enabled: true);
    $event = new AnalyticsEvent(name: 'select_promotion', params: []);

    // Grant analytics but deny ad_storage (marketing)
    $consentState = new ConsentState([
        'analytics_storage' => 'granted',
        'ad_storage' => 'denied',
    ]);

    $result = $filter->process($event, $consentState);
    // select_promotion requires marketing + analytics
    expect($result)->toBeNull();
});

test('ConsentAwareFilter fail-open when no consent state', function (): void {
    $filter = new ConsentAwareFilter(enabled: true);
    $event = new AnalyticsEvent(name: 'page_view', params: []);

    // No consent state — fail open
    $result = $filter->process($event);
    expect($result)->not->toBeNull();
});

test('ConsentAwareFilter purposeToSignalMap', function (): void {
    $map = ConsentAwareFilter::purposeToSignalMap();
    expect($map)->toHaveKey('analytics');
    expect($map['analytics'])->toBe('analytics_storage');
    expect($map['functional'])->toBe('functionality_storage');
    expect($map['marketing'])->toBe('ad_storage');
    expect($map['necessary'])->toBe('security_storage');
});

test('ConsentAwareFilter getRequiredPurposes', function (): void {
    $filter = new ConsentAwareFilter(enabled: true);

    expect($filter->getRequiredPurposes('purchase'))->toBe(['analytics', 'functional']);
    expect($filter->getRequiredPurposes('page_view'))->toBe(['analytics']);
    expect($filter->getRequiredPurposes('error'))->toBe(['necessary']);
    expect($filter->getRequiredPurposes('unknown_event'))->toBe(['analytics']);
});

test('ConsentAwareFilter isPermitted check', function (): void {
    $filter = new ConsentAwareFilter(enabled: true);
    $granted = ConsentState::granted();

    expect($filter->isPermitted('page_view', $granted))->toBeTrue();
    expect($filter->isPermitted('purchase', $granted))->toBeTrue();

    $denied = ConsentState::denied();
    expect($filter->isPermitted('page_view', $denied))->toBeFalse();
    expect($filter->isPermitted('error', $denied))->toBeTrue(); // necessary always allowed
});

test('ConsentAwareFilter setPurposeMapping', function (): void {
    $filter = new ConsentAwareFilter(enabled: true);
    $filter->setPurposeMapping('custom_event', ['marketing']);

    expect($filter->getRequiredPurposes('custom_event'))->toBe(['marketing']);
});

test('ConsentAwareFilter getPurposeMap excludes default', function (): void {
    $filter = new ConsentAwareFilter(enabled: true);
    $map = $filter->getPurposeMap();

    expect($map)->not->toHaveKey('_default');
    expect($map)->toHaveKey('page_view');
    expect($map)->toHaveKey('purchase');
    expect($map)->toHaveKey('error');
});

test('ConsentAwareFilter with per-user consent lookup', function (): void {
    $consentLog = new ConsentLogService($this->cache, 3600);
    $consentLog->recordConsent('client-1', [
        'analytics' => true,
        'functional' => false,
        'marketing' => false,
        'necessary' => true,
    ], 'banner');

    $filter = new ConsentAwareFilter(enabled: true, consentLogService: $consentLog);

    // page_view requires only analytics — granted
    $event1 = new AnalyticsEvent(name: 'page_view', params: []);
    expect($filter->process($event1, identifier: 'client-1'))->not->toBeNull();

    // purchase requires analytics + functional — functional denied
    $event2 = new AnalyticsEvent(name: 'purchase', params: []);
    expect($filter->process($event2, identifier: 'client-1'))->toBeNull();

    // error requires only necessary — always granted
    $event3 = new AnalyticsEvent(name: 'error', params: []);
    expect($filter->process($event3, identifier: 'client-1'))->not->toBeNull();
});

// ── Config Integrity ─────────────────────────────────────────────

test('config has envelope section', function (): void {
    $config = include base_path('config/zeroboiler.php');
    expect($config['analytics'])->toHaveKey('envelope');
    expect($config['analytics']['envelope'])->toHaveKey('enabled');
    expect($config['analytics']['envelope'])->toHaveKey('session');
    expect($config['analytics']['envelope'])->toHaveKey('device');
    expect($config['analytics']['envelope'])->toHaveKey('geo');
    expect($config['analytics']['envelope'])->toHaveKey('identity');
    expect($config['analytics']['envelope'])->toHaveKey('utm');
    expect($config['analytics']['envelope'])->toHaveKey('referrer');
    expect($config['analytics']['envelope'])->toHaveKey('consent');
    expect($config['analytics']['envelope'])->toHaveKey('metadata');
});

test('config has consent_purposes section', function (): void {
    $config = include base_path('config/zeroboiler.php');
    expect($config['analytics'])->toHaveKey('consent_purposes');
    expect($config['analytics']['consent_purposes'])->toHaveKey('enabled');
    expect($config['analytics']['consent_purposes'])->toHaveKey('strict');
});

// ── Version Consistency ─────────────────────────────────────────

test('composer.json version is 2.57.0', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true);
    expect($composer['version'])->toBe('2.89.0');
});

test('AnalyticsManager version is 2.57.0', function (): void {
    $manager = new \ZeroBoiler\Analytics\AnalyticsManager;
    expect($manager->version())->toBe('2.89.0');
});

test('JS client version is 2.57.0', function (): void {
    $content = file_get_contents(base_path('resources/js/analytics.js'));
    expect($content)->toContain("'2.89.0'");
    expect($content)->toContain('@version 2.57.0');
});

test('TypeScript definitions version is 2.57.0', function (): void {
    $content = file_get_contents(base_path('resources/js/analytics.d.ts'));
    expect($content)->toContain('@version 2.57.0');
});

test('EventSourceTagger version is 2.57.0', function (): void {
    $content = file_get_contents(base_path('src/Services/EventSourceTagger.php'));
    expect($content)->toContain('2.89.0');
});

// ── Route Registration ─────────────────────────────────────────

test('routes file contains consent purposes endpoint', function (): void {
    $content = file_get_contents(base_path('routes/analytics.php'));
    // Check via the ServiceProvider registered routes
    $providerContent = file_get_contents(base_path('src/AnalyticsServiceProvider.php'));
    expect($providerContent)->toContain('consentPurposes');
    expect($providerContent)->toContain('envelopeInfo');
    expect($providerContent)->toContain('consentHistory');
});

// ── Filesystem Integrity ───────────────────────────────────────

test('new files exist', function (): void {
    expect(file_exists(base_path('src/DTO/EventContextEvent.php')))->toBeTrue();
    expect(file_exists(base_path('src/Services/EventEnvelopeService.php')))->toBeTrue();
    expect(file_exists(base_path('src/Pipeline/ConsentAwareFilter.php')))->toBeTrue();
});

test('new source files declare strict types', function (): void {
    $files = [
        'src/DTO/EventContextEvent.php',
        'src/Services/EventEnvelopeService.php',
        'src/Pipeline/ConsentAwareFilter.php',
    ];

    foreach ($files as $file) {
        $content = file_get_contents(base_path($file));
        expect($content)->toContain('declare(strict_types=1)');
        expect($content)->toContain('namespace ZeroBoiler\\Analytics\\');
    }
});

test('new source files have docblocks', function (): void {
    $files = [
        'src/DTO/EventContextEvent.php',
        'src/Services/EventEnvelopeService.php',
        'src/Pipeline/ConsentAwareFilter.php',
    ];

    foreach ($files as $file) {
        $content = file_get_contents(base_path($file));
        expect($content)->toContain('/**');
    }
});

test('new classes are final', function (): void {
    expect((new ReflectionClass(EventContextEvent::class))->isFinal())->toBeTrue();
    expect((new ReflectionClass(EventEnvelopeService::class))->isFinal())->toBeTrue();
    expect((new ReflectionClass(ConsentAwareFilter::class))->isFinal())->toBeTrue();
});

// ── JS Client Consent Exports ───────────────────────────────────

test('JS client exports consent functions', function (): void {
    $content = file_get_contents(base_path('resources/js/analytics.js'));
    expect($content)->toContain('export function consentGranted');
    expect($content)->toContain('export function consentDenied');
    expect($content)->toContain('export function getConsentState');
    expect($content)->toContain('export function getConsentPreQueueCount');
    expect($content)->toContain('export function resetConsentState');
});

test('JS client consent pre-queue uses MAX_CONSENT_PRE_QUEUE constant', function (): void {
    $content = file_get_contents(base_path('resources/js/analytics.js'));
    expect($content)->toContain('MAX_CONSENT_PRE_QUEUE');
    expect($content)->toContain('queueBeforeConsent');
    expect($content)->toContain('replayConsentPreQueue');
    expect($content)->toContain('discardConsentPreQueue');
});

// ── TypeScript Definitions ──────────────────────────────────────

test('TypeScript definitions include consent types', function (): void {
    $content = file_get_contents(base_path('resources/js/analytics.d.ts'));
    expect($content)->toContain('consentGranted');
    expect($content)->toContain('consentDenied');
    expect($content)->toContain('getConsentState');
    expect($content)->toContain('getConsentPreQueueCount');
    expect($content)->toContain('resetConsentState');
});
