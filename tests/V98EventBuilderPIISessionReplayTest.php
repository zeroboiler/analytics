<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\AdvancedPIIDetector;
use ZeroBoiler\Analytics\Services\SessionReplayService;
use ZeroBoiler\Analytics\Support\EventBuilder;

// ─── EventBuilder Tests ────────────────────────────────────────────────────────

test('EventBuilder::make creates builder with name', function (): void {
    $builder = EventBuilder::make('test_event');

    expect($builder->getName())->toBe('test_event');
});

test('EventBuilder::make builds AnalyticsEvent DTO', function (): void {
    $event = EventBuilder::make('custom_event')
        ->param('key1', 'value1')
        ->param('key2', 42)
        ->client('client-123')
        ->user('user-456')
        ->priority('high')
        ->now()
        ->build();

    expect($event)
        ->toBeInstanceOf(AnalyticsEvent::class)
        ->and($event->name)->toBe('custom_event')
        ->and($event->params)->toBe([
            'key1' => 'value1',
            'key2' => 42,
        ])
        ->and($event->clientId)->toBe('client-123')
        ->and($event->userId)->toBe('user-456')
        ->and($event->priority)->toBe('high')
        ->and($event->timestamp)->toBeInstanceOf(\DateTimeImmutable::class);
});

test('EventBuilder::params merges arrays', function (): void {
    $event = EventBuilder::make('merge_test')
        ->param('a', 1)
        ->params(['b' => 2, 'c' => 3])
        ->build();

    expect($event->params)->toBe(['a' => 1, 'b' => 2, 'c' => 3]);
});

test('EventBuilder::items stores and merges into params', function (): void {
    $items = [
        ['item_id' => 'SKU-001', 'item_name' => 'Product A', 'price' => 29.99],
        ['item_id' => 'SKU-002', 'item_name' => 'Product B', 'price' => 49.99],
    ];

    $event = EventBuilder::make('purchase')
        ->param('transaction_id', 'TXN-001')
        ->items($items)
        ->build();

    expect($event->params)
        ->toHaveKey('items')
        ->and($event->params['items'])->toHaveCount(2)
        ->and($event->params['items'][0]['item_id'])->toBe('SKU-001');
});

test('EventBuilder::item appends single item', function (): void {
    $event = EventBuilder::make('add_to_cart')
        ->item(['item_id' => 'SKU-001', 'price' => 10.00])
        ->item(['item_id' => 'SKU-002', 'price' => 20.00])
        ->build();

    expect($event->params['items'])->toHaveCount(2);
});

test('EventBuilder::purchase factory sets required params', function (): void {
    $event = EventBuilder::purchase('TXN-999', 149.99, 'EUR')
        ->client('client-abc')
        ->build();

    expect($event->name)->toBe('purchase');
    expect($event->params['transaction_id'])->toBe('TXN-999');
    expect($event->params['value'])->toBe(149.99);
    expect($event->params['currency'])->toBe('EUR');
    expect($event->priority)->toBe('critical');
});

test('EventBuilder::signUp factory', function (): void {
    $event = EventBuilder::signUp('google')
        ->user('user-123')
        ->build();

    expect($event->name)->toBe('sign_up');
    expect($event->params['signup_method'])->toBe('google');
    expect($event->priority)->toBe('critical');
});

test('EventBuilder::pageView factory', function (): void {
    $event = EventBuilder::pageView('Home Page', 'https://example.com/', 'https://google.com')
        ->build();

    expect($event->name)->toBe('page_view');
    expect($event->params['page_title'])->toBe('Home Page');
    expect($event->params['page_location'])->toBe('https://example.com/');
    expect($event->params['page_referrer'])->toBe('https://google.com');
});

test('EventBuilder::fromCatalog returns null for unknown event', function (): void {
    $builder = EventBuilder::fromCatalog('nonexistent_event_xyz');

    expect($builder)->toBeNull();
});

test('EventBuilder::fromCatalog returns builder for known event', function (): void {
    // page_view should be in the catalog
    $builder = EventBuilder::fromCatalog('page_view');

    expect($builder)->not->toBeNull();
    expect($builder->getName())->toBe('page_view');
    expect($builder->isInCatalog())->toBeTrue();
});

test('EventBuilder::build throws on empty name', function (): void {
    EventBuilder::make('');
})->throws(\InvalidArgumentException::class, 'Event name cannot be empty');

test('EventBuilder::validate(false) disables catalog check', function (): void {
    // This should not throw even though 'random_unknown_event' is not in catalog
    $event = EventBuilder::make('random_unknown_event')
        ->validate(false)
        ->build();

    expect($event->name)->toBe('random_unknown_event');
});

test('EventBuilder::getProviderNames returns mapping', function (): void {
    $builder = EventBuilder::make('page_view');
    $providerNames = $builder->getProviderNames();

    expect($providerNames)
        ->toHaveKeys(['ga4', 'meta', 'posthog', 'plausible']);
});

test('EventBuilder::getParams and getItems return current state', function (): void {
    $builder = EventBuilder::make('test')
        ->param('a', 'b')
        ->item(['item_id' => 'x']);

    expect($builder->getParams())->toBe(['a' => 'b']);
    expect($builder->getItems())->toHaveCount(1);
});

// ─── AdvancedPIIDetector Tests ─────────────────────────────────────────────────

test('AdvancedPIIDetector::scan detects email', function (): void {
    $detector = new AdvancedPIIDetector(threshold: 0.5);
    $results = $detector->scan('Contact us at user@example.com for help');

    expect($results)->not->toBeEmpty();
    expect($results[0]['type'])->toBe('email');
    expect($results[0]['match'])->toBe('user@example.com');
    expect($results[0]['confidence'])->toBe(1.0);
});

test('AdvancedPIIDetector::scan detects phone numbers', function (): void {
    $detector = new AdvancedPIIDetector(threshold: 0.5);
    $results = $detector->scan('Call me at +1-555-123-4567 please');

    expect($results)->not->toBeEmpty();
    $types = array_column($results, 'type');
    expect($types)->toContain('phone_intl');
});

test('AdvancedPIIDetector::scan detects credit card numbers', function (): void {
    $detector = new AdvancedPIIDetector(threshold: 0.5);
    $results = $detector->scan('Card: 4111111111111111');

    expect($results)->not->toBeEmpty();
    $types = array_column($results, 'type');
    expect($types)->toContain('credit_card_visa');
});

test('AdvancedPIIDetector::scan detects JWT tokens', function (): void {
    $detector = new AdvancedPIIDetector(threshold: 0.5);
    $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.abc123def456';
    $results = $detector->scan("Token: {$jwt}");

    expect($results)->not->toBeEmpty();
    $types = array_column($results, 'type');
    expect($types)->toContain('jwt_token');
});

test('AdvancedPIIDetector::scan empty string returns empty', function (): void {
    $detector = new AdvancedPIIDetector(threshold: 0.5);
    $results = $detector->scan('');

    expect($results)->toBeEmpty();
});

test('AdvancedPIIDetector::containsPII returns boolean', function (): void {
    $detector = new AdvancedPIIDetector(threshold: 0.5);

    expect($detector->containsPII('test@example.com'))->toBeTrue();
    expect($detector->containsPII('hello world'))->toBeFalse();
});

test('AdvancedPIIDetector::isPIIField detects PII field names', function (): void {
    $detector = new AdvancedPIIDetector(threshold: 0.5);

    expect($detector->isPIIField('email')['is_pii'])->toBeTrue();
    expect($detector->isPIIField('user_email')['is_pii'])->toBeTrue();
    expect($detector->isPIIField('phone_number')['is_pii'])->toBeTrue();
    expect($detector->isPIIField('first_name')['is_pii'])->toBeTrue();
    expect($detector->isPIIField('page_title')['is_pii'])->toBeFalse();
    expect($detector->isPIIField('event_count')['is_pii'])->toBeFalse();
});

test('AdvancedPIIDetector::scanParams scans entire event params', function (): void {
    $detector = new AdvancedPIIDetector(threshold: 0.5);
    $result = $detector->scanParams([
        'page_title' => 'Home',
        'email' => 'john@example.com',
        'user_phone' => '+1-555-987-6543',
    ]);

    expect($result['has_pii'])->toBeTrue();
    expect($result['pii_fields'])->toContain('email');
    expect($result['pii_fields'])->toContain('user_phone');
    expect($result['pii_values'])->toHaveKey('email');
    expect($result['total_detections'])->toBeGreaterThanOrEqual(1);
});

test('AdvancedPIIDetector::scanParams clean params returns no PII', function (): void {
    $detector = new AdvancedPIIDetector(threshold: 0.5);
    $result = $detector->scanParams([
        'page_title' => 'Home',
        'event_count' => 42,
        'button_label' => 'Submit',
    ]);

    expect($result['has_pii'])->toBeFalse();
    expect($result['total_detections'])->toBe(0);
});

test('AdvancedPIIDetector::redact masks PII with asterisks', function (): void {
    $detector = new AdvancedPIIDetector(threshold: 0.5);
    $redacted = $detector->redact('user@example.com');

    // Should preserve first and last char, mask middle
    expect($redacted)->not->toBe('user@example.com');
    expect(str_starts_with($redacted, 'u'))->toBeTrue();
    expect(str_contains($redacted, '*'))->toBeTrue();
});

test('AdvancedPIIDetector::redact clean string returns unchanged', function (): void {
    $detector = new AdvancedPIIDetector(threshold: 0.5);
    $result = $detector->redact('hello world');

    expect($result)->toBe('hello world');
});

test('AdvancedPIIDetector::getPatterns returns all patterns', function (): void {
    $detector = new AdvancedPIIDetector(threshold: 0.5);
    $patterns = $detector->getPatterns();

    expect($patterns)->toHaveKey('email');
    expect($patterns)->toHaveKey('phone_us');
    expect($patterns)->toHaveKey('jwt_token');
    expect($patterns['email']['source'])->toBe('builtin');
});

test('AdvancedPIIDetector::custom patterns', function (): void {
    $detector = new AdvancedPIIDetector(
        threshold: 0.3,
        customPatterns: [
            'slack_id' => [
                'pattern' => '/U[A-Z0-9]{8,}/',
                'confidence' => 0.9,
                'description' => 'Slack user ID',
            ],
        ],
    );

    $results = $detector->scan('User: U01ABCDEF123');

    expect($results)->not->toBeEmpty();
    $types = array_column($results, 'type');
    expect($types)->toContain('slack_id');
    expect($detector->getPatterns()['slack_id']['source'])->toBe('custom');
});

test('AdvancedPIIDetector::high threshold filters low confidence', function (): void {
    // ZIP codes have confidence 0.3, should be filtered at 0.5 threshold
    $detector = new AdvancedPIIDetector(threshold: 0.5);
    $results = $detector->scan('ZIP: 12345');

    // ZIP code should be filtered out
    $types = array_column($results, 'type');
    expect($types)->not->toContain('zip_code_us');
});

test('AdvancedPIIDetector::getFieldPatterns returns known patterns', function (): void {
    $detector = new AdvancedPIIDetector();
    $fields = $detector->getFieldPatterns();

    expect($fields)->toContain('email');
    expect($fields)->toContain('password');
    expect($fields)->toContain('ssn');
});

// ─── SessionReplayService Tests ────────────────────────────────────────────────

test('SessionReplayService::record stores events', function (): void {
    $cache = new \Illuminate\Cache\ArrayStore;
    $cacheRepo = new \Illuminate\Cache\Repository($cache);
    $service = new SessionReplayService($cacheRepo, maxEvents: 10, ttl: 60);

    $service->record('session-abc', [
        'name' => 'page_view',
        'params' => ['page_title' => 'Home'],
        'client_id' => 'client-1',
    ]);

    $events = $service->getSessionEvents('session-abc');
    expect($events)->toHaveCount(1);
    expect($events[0]['name'])->toBe('page_view');
    expect($events[0]['_seq'])->toBe(0);
});

test('SessionReplayService::record respects ring buffer', function (): void {
    $cache = new \Illuminate\Cache\ArrayStore;
    $cacheRepo = new \Illuminate\Cache\Repository($cache);
    $service = new SessionReplayService($cacheRepo, maxEvents: 3, ttl: 60);

    for ($i = 0; $i < 5; $i++) {
        $service->record('session-ring', [
            'name' => "event_{$i}",
        ]);
    }

    $events = $service->getSessionEvents('session-ring');
    expect($events)->toHaveCount(3);
    // Should keep the last 3 events
    expect($events[0]['name'])->toBe('event_2');
    expect($events[2]['name'])->toBe('event_4');
});

test('SessionReplayService::getTimeline returns full timeline', function (): void {
    $cache = new \Illuminate\Cache\ArrayStore;
    $cacheRepo = new \Illuminate\Cache\Repository($cache);
    $service = new SessionReplayService($cacheRepo, maxEvents: 10, ttl: 60);

    $service->record('session-timeline', ['name' => 'page_view', 'timestamp' => 1000]);
    $service->record('session-timeline', ['name' => 'add_to_cart', 'timestamp' => 1005]);
    $service->record('session-timeline', ['name' => 'purchase', 'timestamp' => 1020]);

    $timeline = $service->getTimeline('session-timeline');

    expect($timeline)
        ->toHaveKey('session_id')
        ->toHaveKey('event_count')
        ->toHaveKey('duration_seconds')
        ->toHaveKey('events')
        ->toHaveKey('summary')
        ->and($timeline['session_id'])->toBe('session-timeline')
        ->and($timeline['event_count'])->toBe(3)
        ->and($timeline['duration_seconds'])->toBe(20)
        ->and($timeline['summary']['purchase'])->toBe(1)
        ->and($timeline['summary']['page_view'])->toBe(1);
});

test('SessionReplayService::getSessionSummary detects revenue and error events', function (): void {
    $cache = new \Illuminate\Cache\ArrayStore;
    $cacheRepo = new \Illuminate\Cache\Repository($cache);
    $service = new SessionReplayService($cacheRepo, maxEvents: 10, ttl: 60);

    $service->record('session-summary', ['name' => 'page_view']);
    $service->record('session-summary', ['name' => 'purchase']);
    $service->record('session-summary', ['name' => 'js_error']);

    $summary = $service->getSessionSummary('session-summary');

    expect($summary['event_count'])->toBe(3);
    expect($summary['has_revenue_events'])->toBeTrue();
    expect($summary['has_error_events'])->toBeTrue();
    expect($summary['top_events'])->toHaveCount(3);
});

test('SessionReplayService::getSessionSummary clean session', function (): void {
    $cache = new \Illuminate\Cache\ArrayStore;
    $cacheRepo = new \Illuminate\Cache\Repository($cache);
    $service = new SessionReplayService($cacheRepo, maxEvents: 10, ttl: 60);

    $service->record('session-clean', ['name' => 'page_view']);
    $service->record('session-clean', ['name' => 'scroll']);

    $summary = $service->getSessionSummary('session-clean');

    expect($summary['has_revenue_events'])->toBeFalse();
    expect($summary['has_error_events'])->toBeFalse();
});

test('SessionReplayService::indexSessionForUser and getUserSessions', function (): void {
    $cache = new \Illuminate\Cache\ArrayStore;
    $cacheRepo = new \Illuminate\Cache\Repository($cache);
    $service = new SessionReplayService($cacheRepo, maxEvents: 10, ttl: 60);

    $service->record('session-1', ['name' => 'page_view']);
    $service->record('session-2', ['name' => 'login']);
    $service->indexSessionForUser('user-123', 'session-1');
    $service->indexSessionForUser('user-123', 'session-2');

    $sessions = $service->getUserSessions('user-123');

    expect($sessions)->toHaveCount(2);
    expect($sessions[0]['session_id'])->toBe('session-1');
    expect($sessions[1]['session_id'])->toBe('session-2');
});

test('SessionReplayService::clearSession removes data', function (): void {
    $cache = new \Illuminate\Cache\ArrayStore;
    $cacheRepo = new \Illuminate\Cache\Repository($cache);
    $service = new SessionReplayService($cacheRepo, maxEvents: 10, ttl: 60);

    $service->record('session-clear', ['name' => 'page_view']);
    expect($service->getSessionEvents('session-clear'))->toHaveCount(1);

    $service->clearSession('session-clear');
    expect($service->getSessionEvents('session-clear'))->toBeEmpty();
});

test('SessionReplayService::clearUserSessions removes all user data', function (): void {
    $cache = new \Illuminate\Cache\ArrayStore;
    $cacheRepo = new \Illuminate\Cache\Repository($cache);
    $service = new SessionReplayService($cacheRepo, maxEvents: 10, ttl: 60);

    $service->record('session-a', ['name' => 'page_view']);
    $service->record('session-b', ['name' => 'login']);
    $service->indexSessionForUser('user-x', 'session-a');
    $service->indexSessionForUser('user-x', 'session-b');

    $service->clearUserSessions('user-x');
    $sessions = $service->getUserSessions('user-x');

    expect($sessions)->toBeEmpty();
});

test('SessionReplayService::record auto-adds timestamp', function (): void {
    $cache = new \Illuminate\Cache\ArrayStore;
    $cacheRepo = new \Illuminate\Cache\Repository($cache);
    $service = new SessionReplayService($cacheRepo, maxEvents: 10, ttl: 60);

    $before = time();
    $service->record('session-ts', ['name' => 'page_view']);
    $after = time();

    $events = $service->getSessionEvents('session-ts');
    expect($events[0]['timestamp'])
        ->toBeGreaterThanOrEqual($before)
        ->toBeLessThanOrEqual($after);
});

test('SessionReplayService::empty session returns empty timeline', function (): void {
    $cache = new \Illuminate\Cache\ArrayStore;
    $cacheRepo = new \Illuminate\Cache\Repository($cache);
    $service = new SessionReplayService($cacheRepo, maxEvents: 10, ttl: 60);

    $timeline = $service->getTimeline('nonexistent-session');

    expect($timeline['event_count'])->toBe(0);
    expect($timeline['events'])->toBeEmpty();
    expect($timeline['duration_seconds'])->toBeNull();
});

// ─── Version Consistency ───────────────────────────────────────────────────────

test('version is 2.98.0', function (): void {
    $manager = new \ZeroBoiler\Analytics\AnalyticsManager;

    expect($manager->version())->toBe('4.6.0');
    expect(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION)->toBe('4.6.0');
});

test('EventBuilder VERSION matches package version', function (): void {
    expect(\ZeroBoiler\Analytics\Support\EventBuilder::class)->toBeString(); // Class exists
});

test('SessionReplayService VERSION matches package version', function (): void {
    expect(SessionReplayService::VERSION)->toBe('4.6.0');
});

test('AdvancedPIIDetector VERSION matches package version', function (): void {
    expect(AdvancedPIIDetector::VERSION)->toBe('4.6.0');
});
