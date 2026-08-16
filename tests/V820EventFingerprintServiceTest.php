<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventFingerprintService;

beforeEach(function (): void {
    $store = [];

    $this->cache = mock(CacheRepository::class);
    $this->cache->shouldReceive('get')
        ->andReturnUsing(function (string $key) use (&$store): mixed {
            return $store[$key] ?? null;
        });
    $this->cache->shouldReceive('put')
        ->andReturnUsing(function (string $key, mixed $value, int $ttl) use (&$store): bool {
            $store[$key] = $value;

            return true;
        });

    $this->service = new EventFingerprintService($this->cache);
});

describe('EventFingerprintService', function (): void {
    test('fingerprint returns 64-char hex string', function (): void {
        $event = new AnalyticsEvent(
            name: 'page_view',
            params: ['url' => '/home'],
            clientId: 'abc123',
        );

        $fp = $this->service->fingerprint($event);

        expect($fp)->toBeString()
            ->toBeLength(64)
            ->toMatch('/^[0-9a-f]{64}$/');
    });

    test('identical events produce identical fingerprints', function (): void {
        $event1 = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 29.99, 'currency' => 'USD'],
            clientId: 'client_1',
            userId: 'user_1',
        );

        $event2 = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 29.99, 'currency' => 'USD'],
            clientId: 'client_1',
            userId: 'user_1',
        );

        expect($this->service->fingerprint($event1))
            ->toBe($this->service->fingerprint($event2));
    });

    test('different event names produce different fingerprints', function (): void {
        $eventA = new AnalyticsEvent(name: 'login', clientId: 'c1');
        $eventB = new AnalyticsEvent(name: 'logout', clientId: 'c1');

        expect($this->service->fingerprint($eventA))
            ->not->toBe($this->service->fingerprint($eventB));
    });

    test('different client IDs produce different fingerprints', function (): void {
        $eventA = new AnalyticsEvent(name: 'page_view', clientId: 'client_A');
        $eventB = new AnalyticsEvent(name: 'page_view', clientId: 'client_B');

        expect($this->service->fingerprint($eventA))
            ->not->toBe($this->service->fingerprint($eventB));
    });

    test('null values in params are excluded from fingerprint', function (): void {
        $event1 = new AnalyticsEvent(
            name: 'form_submit',
            params: ['field' => 'email', 'value' => 'test@example.com', 'extra' => null],
            clientId: 'c1',
        );

        $event2 = new AnalyticsEvent(
            name: 'form_submit',
            params: ['field' => 'email', 'value' => 'test@example.com'],
            clientId: 'c1',
        );

        expect($this->service->fingerprint($event1))
            ->toBe($this->service->fingerprint($event2));
    });

    test('param key order does not affect fingerprint', function (): void {
        $event1 = new AnalyticsEvent(
            name: 'search',
            params: ['query' => 'laravel', 'results' => 42],
            clientId: 'c1',
        );

        $event2 = new AnalyticsEvent(
            name: 'search',
            params: ['results' => 42, 'query' => 'laravel'],
            clientId: 'c1',
        );

        expect($this->service->fingerprint($event1))
            ->toBe($this->service->fingerprint($event2));
    });

    test('batchFingerprint is stable for identical batches', function (): void {
        $events1 = [
            new AnalyticsEvent(name: 'page_view', clientId: 'c1'),
            new AnalyticsEvent(name: 'click', clientId: 'c1'),
        ];

        $events2 = [
            new AnalyticsEvent(name: 'page_view', clientId: 'c1'),
            new AnalyticsEvent(name: 'click', clientId: 'c1'),
        ];

        expect($this->service->batchFingerprint($events1))
            ->toBe($this->service->batchFingerprint($events2));
    });

    test('batchFingerprint differs for different batch content', function (): void {
        $events1 = [
            new AnalyticsEvent(name: 'page_view', clientId: 'c1'),
            new AnalyticsEvent(name: 'click', clientId: 'c1'),
        ];

        $events2 = [
            new AnalyticsEvent(name: 'page_view', clientId: 'c1'),
            new AnalyticsEvent(name: 'scroll_depth', clientId: 'c1'),
        ];

        expect($this->service->batchFingerprint($events1))
            ->not->toBe($this->service->batchFingerprint($events2));
    });

    test('checkAndMark returns not duplicate on first call', function (): void {
        $event = new AnalyticsEvent(name: 'purchase', clientId: 'c1');

        $result = $this->service->checkAndMark($event);

        expect($result)->toBe([
            'is_duplicate' => false,
            'fingerprint' => $this->service->fingerprint($event),
        ]);
    });

    test('checkAndMark returns duplicate on second call within TTL', function (): void {
        $event = new AnalyticsEvent(name: 'purchase', clientId: 'c1');

        $result1 = $this->service->checkAndMark($event);
        $result2 = $this->service->checkAndMark($event);

        expect($result1['is_duplicate'])->toBeFalse();
        expect($result2['is_duplicate'])->toBeTrue();
        expect($result1['fingerprint'])->toBe($result2['fingerprint']);
    });

    test('markSeen returns fingerprint string', function (): void {
        $event = new AnalyticsEvent(name: 'signup', clientId: 'c1', userId: 'u1');

        $fp = $this->service->markSeen($event);

        expect($fp)->toBeString()->toBeLength(64);
    });

    test('hasSeen returns false for unseen event', function (): void {
        $event = new AnalyticsEvent(name: 'login', clientId: 'new_client');

        expect($this->service->hasSeen($event))->toBeFalse();
    });

    test('hasSeen returns true after markSeen', function (): void {
        $event = new AnalyticsEvent(name: 'login', clientId: 'c1');

        $this->service->markSeen($event);

        expect($this->service->hasSeen($event))->toBeTrue();
    });

    test('stats returns configuration summary', function (): void {
        $stats = $this->service->stats();

        expect($stats)->toHaveKeys([
            'ttl', 'time_bucket_seconds', 'exclude_timestamp',
            'exclude_params', 'cache_prefix',
        ]);
        expect($stats['ttl'])->toBe(86400);
        expect($stats['time_bucket_seconds'])->toBe(60);
        expect($stats['cache_prefix'])->toBe('zb_fp_');
    });

    test('custom time bucket configuration is respected', function (): void {
        $service = new EventFingerprintService($this->cache, [
            'time_bucket' => 'hour',
            'ttl' => 3600,
        ]);

        $stats = $service->stats();

        expect($stats['time_bucket_seconds'])->toBe(3600);
        expect($stats['ttl'])->toBe(3600);
    });

    test('exclude_params mode ignores parameters', function (): void {
        $service = new EventFingerprintService($this->cache, [
            'exclude_params' => true,
        ]);

        $event1 = new AnalyticsEvent(name: 'click', params: ['x' => 1, 'y' => 2], clientId: 'c1');
        $event2 = new AnalyticsEvent(name: 'click', params: ['a' => 'b'], clientId: 'c1');

        expect($service->fingerprint($event1))
            ->toBe($service->fingerprint($event2));
    });

    test('different events have different fingerprints even in same time bucket', function (): void {
        // Create two events at the same second
        $ts = new \DateTimeImmutable();

        $event1 = new AnalyticsEvent(name: 'add_to_cart', clientId: 'c1', timestamp: $ts);
        $event2 = new AnalyticsEvent(name: 'remove_from_cart', clientId: 'c1', timestamp: $ts);

        expect($this->service->fingerprint($event1))
            ->not->toBe($this->service->fingerprint($event2));
    });

    test('hasSeenBatch and markBatchSeen work together', function (): void {
        $events = [
            new AnalyticsEvent(name: 'page_view', clientId: 'c1'),
            new AnalyticsEvent(name: 'click', clientId: 'c1'),
        ];

        expect($this->service->hasSeenBatch($events))->toBeFalse();

        $fp = $this->service->markBatchSeen($events);

        expect($fp)->toBeString()->toBeLength(64);
        expect($this->service->hasSeenBatch($events))->toBeTrue();
    });
});
