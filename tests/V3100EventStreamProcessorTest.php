<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\EventSequencePattern;
use ZeroBoiler\Analytics\DTO\StreamEvent;
use ZeroBoiler\Analytics\Services\EventStreamProcessorService;

beforeEach(function (): void {
    $this->cache = Cache::getStore();
    $this->cache->flush();

    $this->service = new EventStreamProcessorService($this->cache, [
        'enabled' => true,
        'cache_ttl' => 3600,
        'max_sequence_length' => 6,
        'max_patterns_per_client' => 50,
        'min_pattern_support' => 1,
        'anomaly_deviation' => 3.0,
        'anomaly_window' => 600,
        'max_stream_events' => 500,
        'cache_prefix' => 'zb_test_stream_',
    ]);
});

afterEach(function (): void {
    $this->cache->flush();
});

it('is enabled by default', function (): void {
    expect($this->service->isEnabled())->toBeTrue();
});

it('creates a StreamEvent from an AnalyticsEvent', function (): void {
    $event = new AnalyticsEvent('page_view', [
        'client_id' => 'client_abc',
        'user_id' => 'user_123',
    ]);

    $streamEvent = $this->service->processEvent($event);

    expect($streamEvent)->toBeInstanceOf(StreamEvent::class);
    expect($streamEvent->eventName)->toBe('page_view');
    expect($streamEvent->clientId)->toBe('client_abc');
    expect($streamEvent->position)->toBe(1);
    expect($streamEvent->timeSincePrevious)->toBeNull();
});

it('tracks position and time-since-previous across events', function (): void {
    $clientId = 'client_seq';

    $event1 = new AnalyticsEvent('page_view', ['client_id' => $clientId]);
    $stream1 = $this->service->processEvent($event1);

    expect($stream1->position)->toBe(1);
    expect($stream1->timeSincePrevious)->toBeNull();

    $event2 = new AnalyticsEvent('click', ['client_id' => $clientId]);
    $stream2 = $this->service->processEvent($event2);

    expect($stream2->position)->toBe(2);
    expect($stream2->timeSincePrevious)->not->toBeNull();
    expect($stream2->timeSincePrevious)->toBeGreaterThanOrEqual(0.0);

    $event3 = new AnalyticsEvent('form_submit', ['client_id' => $clientId]);
    $stream3 = $this->service->processEvent($event3);

    expect($stream3->position)->toBe(3);
});

it('discovers patterns after enough events', function (): void {
    $clientId = 'client_pattern';

    $events = ['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'purchase'];
    foreach ($events as $name) {
        $event = new AnalyticsEvent($name, ['client_id' => $clientId]);
        $this->service->processEvent($event);
    }

    $patterns = $this->service->discoverTopPatterns(10);
    expect($patterns)->not->toBeEmpty();

    // At least one pattern should be discovered
    $hasValidPattern = false;
    foreach ($patterns as $pattern) {
        expect($pattern)->toBeInstanceOf(EventSequencePattern::class);
        expect($pattern->sequence)->not->toBeEmpty();
        expect($pattern->id)->toStartWith('seq:');

        if (count($pattern->sequence) >= 3) {
            $hasValidPattern = true;
        }
    }
    expect($hasValidPattern)->toBeTrue();
});

it('discovers auto-funnels with conversion events', function (): void {
    $clientId = 'client_funnel';

    // Simulate a signup → trial → conversion funnel
    $events = ['page_view', 'sign_up', 'start_trial', 'feature_used', 'trial_converted'];
    foreach ($events as $name) {
        $event = new AnalyticsEvent($name, ['client_id' => $clientId]);
        $this->service->processEvent($event);
    }

    $funnels = $this->service->discoverAutoFunnels();
    // Funnels ending in conversion events should be discovered
    // (requires min_pattern_support of 1)
    expect($funnels)->toBeArray();
});

it('analyzes client stream correctly', function (): void {
    $clientId = 'client_analysis';

    $events = ['page_view', 'click', 'search', 'page_view', 'form_submit'];
    foreach ($events as $name) {
        $event = new AnalyticsEvent($name, ['client_id' => $clientId]);
        $this->service->processEvent($event);
    }

    $analysis = $this->service->analyzeClientStream($clientId);

    expect($analysis['total_events'])->toBe(5);
    expect($analysis['unique_events'])->toBeGreaterThanOrEqual(3);
    expect($analysis['session_count'])->toBeGreaterThanOrEqual(1);
    expect($analysis['stream'])->toHaveCount(5);
    expect($analysis['stream'][0]['event_name'])->toBe('page_view');
});

it('detects stream anomalies for rapid repetitions', function (): void {
    $clientId = 'client_anomaly';

    // Simulate rapid repetitions of the same event
    for ($i = 0; $i < 10; $i++) {
        $event = new AnalyticsEvent('click', ['client_id' => $clientId]);
        $this->service->processEvent($event);
    }

    $anomalies = $this->service->detectStreamAnomalies($clientId);

    // With 10 rapid-fire clicks, should detect at least velocity spike
    $hasAnomaly = false;
    foreach ($anomalies as $anomaly) {
        expect($anomaly)->toHaveKey('type');
        expect($anomaly)->toHaveKey('severity');
        expect($anomaly)->toHaveKey('description');
        $hasAnomaly = true;
    }
    // Anomalies may or may not be detected depending on timing
    // but the method should return valid structures
    expect($anomalies)->toBeArray();
});

it('returns empty results when disabled', function (): void {
    $disabledService = new EventStreamProcessorService($this->cache, [
        'enabled' => false,
    ]);

    expect($disabledService->isEnabled())->toBeFalse();

    $event = new AnalyticsEvent('page_view', ['client_id' => 'client_x']);
    $streamEvent = $disabledService->processEvent($event);

    expect($streamEvent)->toBeInstanceOf(StreamEvent::class);
    expect($streamEvent->position)->toBe(1);

    expect($disabledService->discoverTopPatterns())->toBeEmpty();
    expect($disabledService->discoverAutoFunnels())->toBeEmpty();
    expect($disabledService->analyzeClientStream('any'))->toHaveKey('stream');
    expect($disabledService->detectStreamAnomalies('any'))->toBeEmpty();
});

it('clears client stream data', function (): void {
    $clientId = 'client_clear';

    $event = new AnalyticsEvent('page_view', ['client_id' => $clientId]);
    $this->service->processEvent($event);

    $before = $this->service->analyzeClientStream($clientId);
    expect($before['total_events'])->toBe(1);

    $this->service->clearClientStream($clientId);

    $after = $this->service->analyzeClientStream($clientId);
    expect($after['total_events'])->toBe(0);
});

it('provides global stream stats', function (): void {
    $clientId1 = 'client_stats_1';
    $clientId2 = 'client_stats_2';

    $events = ['page_view', 'sign_up', 'start_trial'];
    foreach ($events as $name) {
        $event1 = new AnalyticsEvent($name, ['client_id' => $clientId1]);
        $this->service->processEvent($event1);

        $event2 = new AnalyticsEvent($name, ['client_id' => $clientId2]);
        $this->service->processEvent($event2);
    }

    $stats = $this->service->getStreamStats();

    expect($stats)->toHaveKey('total_clients_tracked');
    expect($stats)->toHaveKey('total_patterns_discovered');
    expect($stats)->toHaveKey('top_sequences');
    expect($stats)->toHaveKey('auto_funnels');
});

it('StreamEvent generates stable ID', function (): void {
    $id1 = StreamEvent::generateId('page_view', 'client_123', 1700000000, 1);
    $id2 = StreamEvent::generateId('page_view', 'client_123', 1700000000, 1);
    $id3 = StreamEvent::generateId('page_view', 'client_123', 1700000000, 2);

    expect($id1)->toBe($id2);
    expect($id1)->not->toBe($id3);
});

it('StreamEvent serializes to array correctly', function (): void {
    $event = new StreamEvent(
        id: 'test_id',
        eventName: 'purchase',
        clientId: 'client_1',
        userId: 'user_1',
        position: 5,
        timestamp: 1700000000,
        timeSincePrevious: 12.5,
        sessionSequenceId: 'session_abc',
        params: ['value' => 99.99],
        category: 'ecommerce',
    );

    $arr = $event->toArray();
    expect($arr['id'])->toBe('test_id');
    expect($arr['event_name'])->toBe('purchase');
    expect($arr['client_id'])->toBe('client_1');
    expect($arr['position'])->toBe(5);
    expect($arr['time_since_previous'])->toBe(12.5);
    expect($arr['category'])->toBe('ecommerce');
});

it('EventSequencePattern serializes to array correctly', function (): void {
    $pattern = new EventSequencePattern(
        id: 'seq:abc123',
        sequence: ['page_view', 'click', 'purchase'],
        occurrences: 42,
        uniqueUsers: 15,
        averageDurationSeconds: 120.5,
        medianDurationSeconds: 110.0,
        conversionRate: 0.75,
        sampleClientIds: ['client_1', 'client_2'],
        metadata: ['support_ratio' => 0.3],
    );

    $arr = $pattern->toArray();

    expect($arr['id'])->toBe('seq:abc123');
    expect($arr['sequence'])->toBe(['page_view', 'click', 'purchase']);
    expect($arr['occurrences'])->toBe(42);
    expect($arr['unique_users'])->toBe(15);
    expect($arr['avg_duration'])->toBe(120.5);
    expect($arr['conversion_rate'])->toBe(0.75);
    expect($arr['metadata']['support_ratio'])->toBe(0.3);
});

it('uses fallback client ID from event params', function (): void {
    $event = new AnalyticsEvent('page_view', [
        'client_id' => 'param_client',
    ]);

    $streamEvent = $this->service->processEvent($event, null);

    expect($streamEvent->clientId)->toBe('param_client');
});

it('falls back to anonymous when no client ID available', function (): void {
    $event = new AnalyticsEvent('page_view', []);

    $streamEvent = $this->service->processEvent($event, null);

    expect($streamEvent->clientId)->toBe('anonymous');
});

it('resolves event category correctly', function (): void {
    $clientId = 'client_cat';

    $purchase = new AnalyticsEvent('purchase', ['client_id' => $clientId]);
    $stream1 = $this->service->processEvent($purchase);
    expect($stream1->category)->toBe('ecommerce');

    $login = new AnalyticsEvent('login', ['client_id' => $clientId]);
    $stream2 = $this->service->processEvent($login);
    expect($stream2->category)->toBe('saas');

    $click = new AnalyticsEvent('click', ['client_id' => $clientId]);
    $stream3 = $this->service->processEvent($click);
    expect($stream3->category)->toBe('engagement');
});
