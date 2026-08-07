<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Pipeline\EventMetadataEnricher;
use ZeroBoiler\Analytics\Pipeline\SchemaEnricher;
use ZeroBoiler\Analytics\Pipeline\EventPipeline;
use ZeroBoiler\Analytics\Services\AnalyticsStatsService;
use ZeroBoiler\Analytics\Services\InboundWebhookService;
use ZeroBoiler\Analytics\Services\EventAggregationService;
use ZeroBoiler\Analytics\Queue\EventReplayQueue;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;
use ZeroBoiler\Analytics\Support\WebhookSignatureValidator;

// ── EventMetadataEnricher Tests ────────────────────────────────────

describe('v2.23 — EventMetadataEnricher', function () {
    it('attaches session ID and page URL to events', function () {
        $enricher = new EventMetadataEnricher(
            sessionId: 'sess-123',
            pageUrl: 'https://example.com/pricing',
            referrer: 'https://google.com',
        );

        $event = new AnalyticsEvent(name: 'page_view', params: ['page_title' => 'Pricing']);
        $result = $enricher($event);

        expect($result)->not->toBeNull();
        expect($result->params['_session_id'])->toBe('sess-123');
        expect($result->params['_page_url'])->toBe('https://example.com/pricing');
        expect($result->params['_referrer'])->toBe('https://google.com');
        expect($result->params['_timestamp'])->toBeString();
        expect($result->name)->toBe('page_view');
        expect($result->params['page_title'])->toBe('Pricing');
    });

    it('does not overwrite existing params', function () {
        $enricher = new EventMetadataEnricher(
            sessionId: 'sess-override',
            pageUrl: 'https://override.com',
        );

        $event = new AnalyticsEvent(name: 'click', params: [
            '_session_id' => 'original-session',
            'element' => 'button',
        ]);
        $result = $enricher($event);

        expect($result->params['_session_id'])->toBe('original-session');
        expect($result->params['_page_url'])->toBe('https://override.com');
    });

    it('skips timestamp when disabled', function () {
        $enricher = new EventMetadataEnricher(
            sessionId: 'sess-456',
            includeTimestamp: false,
        );

        $event = new AnalyticsEvent(name: 'click', params: []);
        $result = $enricher($event);

        expect($result->params)->toHaveKey('_session_id');
        expect($result->params)->not->toHaveKey('_timestamp');
    });

    it('passes through event unchanged when metadata is empty', function () {
        $enricher = new EventMetadataEnricher(
            sessionId: null,
            pageUrl: null,
            referrer: null,
            includeTimestamp: false,
        );

        $event = new AnalyticsEvent(name: 'click', params: ['element' => 'btn']);
        $result = $enricher($event);

        expect($result->params)->toEqual(['element' => 'btn']);
    });

    it('supports extra metadata fields', function () {
        $enricher = new EventMetadataEnricher(
            sessionId: 'sess-789',
            extra: ['_app_version' => '2.23.0', '_locale' => 'en-US'],
        );

        $event = new AnalyticsEvent(name: 'page_view', params: []);
        $result = $enricher($event);

        expect($result->params['_app_version'])->toBe('2.23.0');
        expect($result->params['_locale'])->toBe('en-US');
    });
});

// ── SchemaEnricher Tests ────────────────────────────────────────────

describe('v2.23 — SchemaEnricher', function () {
    it('passes through events with no schema', function () {
        $registry = new EventSchemaRegistry;
        $enricher = new SchemaEnricher($registry, strict: false);

        $event = new AnalyticsEvent(name: 'custom_unknown_event', params: ['key' => 'value']);
        $result = $enricher($event);

        expect($result)->not->toBeNull();
        expect($result->name)->toBe('custom_unknown_event');
        expect($result->params)->not->toHaveKey('_schema_valid');
    });

    it('attaches schema valid flag for known events with valid params', function () {
        $registry = new EventSchemaRegistry;
        $enricher = new SchemaEnricher($registry, strict: false);

        $event = new AnalyticsEvent(name: 'page_view', params: ['page_title' => 'Home']);
        $result = $enricher($event);

        expect($result)->not->toBeNull();
        expect($result->params['_schema_valid'])->toBe(true);
        expect($result->name)->toBe('page_view');
    });

    it('attaches schema valid flag even when validation fails in non-strict mode', function () {
        $registry = new EventSchemaRegistry;
        $enricher = new SchemaEnricher($registry, strict: false);

        $event = new AnalyticsEvent(name: 'purchase', params: []);
        $result = $enricher($event);

        expect($result)->not->toBeNull();
        // purchase has required params, so missing them may result in false
        expect($result->params)->toHaveKey('_schema_valid');
    });

    it('drops events with schema violations in strict mode', function () {
        $registry = new EventSchemaRegistry;
        $enricher = new SchemaEnricher($registry, strict: true);

        $event = new AnalyticsEvent(name: 'purchase', params: []);
        $result = $enricher($event);

        // purchase requires transaction_id and value — should be null in strict mode
        // (depends on whether the schema marks them as required)
        // If the schema is permissive (no required params), it may pass through
        expect($result)->toBeNull();
    });

    it('preserves event identity through enrichment', function () {
        $registry = new EventSchemaRegistry;
        $enricher = new SchemaEnricher($registry, strict: false);

        $event = new AnalyticsEvent(
            name: 'click',
            params: ['element' => 'button'],
            clientId: 'client-uuid',
            userId: 'user-42',
        );
        $result = $enricher($event);

        expect($result->clientId)->toBe('client-uuid');
        expect($result->userId)->toBe('user-42');
    });
});

// ── AnalyticsStatsService Tests ──────────────────────────────────────

describe('v2.23 — AnalyticsStatsService', function () {
    it('returns summary with zero values when no events tracked', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST'],
                    'replay' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $metrics = $manager->metrics();
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $replay = new EventReplayQueue($manager, $metrics, $config);
        $aggregation = new EventAggregationService($manager, $metrics, $replay, $config);
        $stats = new AnalyticsStatsService($manager, $metrics, $aggregation, $replay);

        $summary = $stats->summary();

        expect($summary['total_tracked'])->toBe(0);
        expect($summary['unique_events'])->toBe(0);
        expect($summary['top_events'])->toEqual([]);
        expect($summary['categories'])->toEqual([]);
        expect($summary['version'])->toBe('2.34.0');
        expect($summary['catalog'])->toHaveKey('ecommerce');
        expect($summary['catalog'])->toHaveKey('saas');
        expect($summary['catalog'])->toHaveKey('engagement');
        expect($summary['catalog'])->toHaveKey('total');
    });

    it('returns aggregate data after tracking events', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST'],
                    'replay' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $metrics = $manager->metrics();
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $replay = new EventReplayQueue($manager, $metrics, $config);
        $aggregation = new EventAggregationService($manager, $metrics, $replay, $config);
        $stats = new AnalyticsStatsService($manager, $metrics, $aggregation, $replay);

        // Track some events manually
        $aggregation->record('page_view', 'engagement');
        $aggregation->record('page_view', 'engagement');
        $aggregation->record('click', 'engagement');
        $aggregation->record('purchase', 'ecommerce');

        $summary = $stats->summary();

        expect($summary['total_tracked'])->toBe(4);
        expect($summary['unique_events'])->toBe(3);
        expect($summary['top_events'][0]['event'])->toBe('page_view');
        expect($summary['top_events'][0]['count'])->toBe(2);
    });

    it('returns per-category breakdown', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST'],
                    'replay' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $metrics = $manager->metrics();
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $replay = new EventReplayQueue($manager, $metrics, $config);
        $aggregation = new EventAggregationService($manager, $metrics, $replay, $config);
        $stats = new AnalyticsStatsService($manager, $metrics, $aggregation, $replay);

        $aggregation->record('page_view', 'engagement');
        $aggregation->record('purchase', 'ecommerce');
        $aggregation->record('sign_up', 'saas');

        $byCategory = $stats->byCategory();

        expect($byCategory)->toHaveKey('engagement');
        expect($byCategory)->toHaveKey('ecommerce');
        expect($byCategory)->toHaveKey('saas');
        expect($byCategory['engagement']['total'])->toBe(1);
        expect($byCategory['ecommerce']['total'])->toBe(1);
        expect($byCategory['saas']['total'])->toBe(1);
    });

    it('returns per-provider stats with success rate', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST'],
                    'replay' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $metrics = $manager->metrics();
        $queue = new QueuedAnalyticsDispatcher($manager, $config);
        $replay = new EventReplayQueue($manager, $metrics, $config);
        $aggregation = new EventAggregationService($manager, $metrics, $replay, $config);
        $stats = new AnalyticsStatsService($manager, $metrics, $aggregation, $replay);

        $byProvider = $stats->byProvider();

        expect($byProvider)->toHaveKey('gtm');
        expect($byProvider['gtm'])->toHaveKey('dispatched');
        expect($byProvider['gtm'])->toHaveKey('failed');
        expect($byProvider['gtm'])->toHaveKey('success_rate');
    });
});

// ── InboundWebhookService Tests ───────────────────────────────────

describe('v2.23 — InboundWebhookService', function () {
    it('returns disabled when inbound webhook is not enabled', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'inbound_webhook' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new InboundWebhookService($manager, $config);

        $result = $service->receive('{"event":"test"}');

        expect($result['status'])->toBe('disabled');
        expect($result['dispatched'])->toBe(0);
        expect($service->isEnabled())->toBeFalse();
    });

    it('receives and dispatches a single event', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST'],
                    'inbound_webhook' => ['enabled' => true, 'require_signature' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new InboundWebhookService($manager, $config);

        $payload = json_encode([
            'event' => 'payment.completed',
            'params' => ['amount' => 99.99, 'currency' => 'USD'],
        ]);

        $result = $service->receive($payload);

        expect($result['status'])->toBe('ok');
        expect($result['dispatched'])->toBe(1);
        expect($result['errors'])->toBeEmpty();
    });

    it('receives and dispatches a batch of events', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST'],
                    'inbound_webhook' => ['enabled' => true, 'require_signature' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new InboundWebhookService($manager, $config);

        $payload = json_encode([
            'events' => [
                ['event' => 'payment.completed', 'params' => ['amount' => 99.99]],
                ['event' => 'subscription.created', 'params' => ['plan' => 'pro']],
                ['event' => 'trial.started', 'params' => ['plan' => 'pro', 'days' => 14]],
            ],
        ]);

        $result = $service->receive($payload);

        expect($result['status'])->toBe('ok');
        expect($result['dispatched'])->toBe(3);
        expect($result['errors'])->toBeEmpty();
    });

    it('validates signature when required', function () {
        $secret = 'test-secret-12345';
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST'],
                    'inbound_webhook' => [
                        'enabled' => true,
                        'require_signature' => true,
                        'secret' => $secret,
                    ],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new InboundWebhookService($manager, $config);

        $payload = json_encode(['event' => 'test', 'params' => []]);
        $signature = hash_hmac('sha256', $payload, $secret);

        $result = $service->receive($payload, $signature);

        expect($result['status'])->toBe('ok');
        expect($result['dispatched'])->toBe(1);
    });

    it('rejects invalid signature', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST'],
                    'inbound_webhook' => [
                        'enabled' => true,
                        'require_signature' => true,
                        'secret' => 'correct-secret',
                    ],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new InboundWebhookService($manager, $config);

        $payload = json_encode(['event' => 'test', 'params' => []]);

        $result = $service->receive($payload, 'invalid-signature');

        expect($result['status'])->toBe('error');
        expect($result['dispatched'])->toBe(0);
        expect($result['errors'][0])->toBe('Invalid signature');
    });

    it('rejects missing signature when required', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST'],
                    'inbound_webhook' => [
                        'enabled' => true,
                        'require_signature' => true,
                        'secret' => 'secret',
                    ],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new InboundWebhookService($manager, $config);

        $result = $service->receive('{"event":"test"}');

        expect($result['status'])->toBe('error');
        expect($result['errors'][0])->toBe('Missing signature header');
    });

    it('rejects invalid JSON payload', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST'],
                    'inbound_webhook' => ['enabled' => true, 'require_signature' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new InboundWebhookService($manager, $config);

        $result = $service->receive('not-valid-json');

        expect($result['status'])->toBe('error');
        expect($result['errors'][0])->toBe('Invalid JSON payload');
    });

    it('rejects payload without event or events key', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST'],
                    'inbound_webhook' => ['enabled' => true, 'require_signature' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new InboundWebhookService($manager, $config);

        $result = $service->receive('{"data": "something"}');

        expect($result['status'])->toBe('error');
        expect($result['errors'][0])->toContain('event');
    });

    it('enforces batch size limit', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST'],
                    'inbound_webhook' => ['enabled' => true, 'require_signature' => false, 'max_events' => 2],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new InboundWebhookService($manager, $config);

        $events = [];
        for ($i = 0; $i < 5; $i++) {
            $events[] = ['event' => "test_{$i}", 'params' => []];
        }

        $result = $service->receive(json_encode(['events' => $events]));

        expect($result['status'])->toBe('error');
        expect($result['errors'][0])->toContain('maximum event count');
    });

    it('enforces payload size limit', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST'],
                    'inbound_webhook' => ['enabled' => true, 'require_signature' => false, 'max_payload_size' => 100],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new InboundWebhookService($manager, $config);

        $largePayload = str_repeat('x', 200);
        $result = $service->receive($largePayload);

        expect($result['status'])->toBe('error');
        expect($result['errors'][0])->toBe('Payload exceeds maximum size');
    });

    it('marks events with _source webhook_inbound', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST'],
                    'inbound_webhook' => ['enabled' => true, 'require_signature' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new InboundWebhookService($manager, $config);

        $payload = json_encode(['event' => 'stripe.payment', 'params' => ['amount' => 50]]);
        $service->receive($payload);

        $layer = $manager->gtm()->getDataLayer();
        $lastEvent = end($layer);

        expect($lastEvent['event'])->toBe('stripe.payment');
        expect($lastEvent['_source'])->toBe('webhook_inbound');
    });

    it('returns partial status for batch with some errors', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST'],
                    'inbound_webhook' => ['enabled' => true, 'require_signature' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new InboundWebhookService($manager, $config);

        $payload = json_encode([
            'events' => [
                ['event' => 'valid_event', 'params' => []],
                ['not_an_event_key' => 'invalid'],
                ['event' => 'another_valid', 'params' => []],
            ],
        ]);

        $result = $service->receive($payload);

        expect($result['status'])->toBe('partial');
        expect($result['dispatched'])->toBe(2);
        expect($result['errors'])->toHaveCount(1);
    });

    it('supports user_id and client_id in events', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST'],
                    'inbound_webhook' => ['enabled' => true, 'require_signature' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);
        $service = new InboundWebhookService($manager, $config);

        $payload = json_encode([
            'event' => 'identify',
            'user_id' => '42',
            'client_id' => 'client-uuid',
            'params' => ['name' => 'John'],
        ]);

        $result = $service->receive($payload);

        expect($result['status'])->toBe('ok');
        expect($result['dispatched'])->toBe(1);
    });
});

// ── Pipeline Integration Tests ─────────────────────────────────────

describe('v2.23 — Pipeline Integration', function () {
    it('EventMetadataEnricher works in EventPipeline', function () {
        $pipeline = new EventPipeline;
        $pipeline->pipe(new EventMetadataEnricher(
            sessionId: 'sess-int-1',
            pageUrl: 'https://app.example.com/dashboard',
            referrer: 'https://google.com',
        ));

        $event = new AnalyticsEvent(name: 'page_view', params: ['title' => 'Dashboard']);
        $result = $pipeline->process($event);

        expect($result)->not->toBeNull();
        expect($result->params['_session_id'])->toBe('sess-int-1');
        expect($result->params['_page_url'])->toBe('https://app.example.com/dashboard');
    });

    it('SchemaEnricher and EventMetadataEnricher chain correctly', function () {
        $registry = new EventSchemaRegistry;
        $pipeline = new EventPipeline;
        $pipeline->pipe(new SchemaEnricher($registry, strict: false));
        $pipeline->pipe(new EventMetadataEnricher(sessionId: 'sess-chain'));

        $event = new AnalyticsEvent(name: 'page_view', params: []);
        $result = $pipeline->process($event);

        expect($result)->not->toBeNull();
        expect($result->params['_session_id'])->toBe('sess-chain');
    });

    it('strict SchemaEnricher drops invalid events before metadata enrichment', function () {
        $registry = new EventSchemaRegistry;
        $pipeline = new EventPipeline;
        $pipeline->pipe(new SchemaEnricher($registry, strict: true));
        $pipeline->pipe(new EventMetadataEnricher(sessionId: 'sess-strict'));

        // purchase without required params should be dropped in strict mode
        $event = new AnalyticsEvent(name: 'purchase', params: []);
        $result = $pipeline->process($event);

        expect($result)->toBeNull();
    });

    it('empty pipeline returns event unchanged', function () {
        $pipeline = new EventPipeline;
        $event = new AnalyticsEvent(name: 'test', params: ['key' => 'value']);

        $result = $pipeline->process($event);

        expect($result->name)->toBe('test');
        expect($result->params)->toEqual(['key' => 'value']);
    });
});

// ── Version Consistency Tests ──────────────────────────────────────

describe('v2.23 — Version Consistency', function () {
    it('all version strings are 2.23.0', function () {
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                    'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST'],
                    'replay' => ['enabled' => false],
                ],
            ],
        ]);

        $manager = new AnalyticsManager($config);

        expect($manager->version())->toBe('2.34.0');
    });

    it('composer.json version matches', function () {
        $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
        expect($composer['version'])->toBe('2.34.0');
    });

    it('JS client version matches', function () {
        $js = file_get_contents(__DIR__.'/../resources/js/analytics.js');
        expect($js)->toContain('@version 2.34.0');
    });
});

// ── Config Tests ───────────────────────────────────────────────────

describe('v2.23 — Config Expansion', function () {
    it('inbound_webhook config section exists', function () {
        $config = include __DIR__.'/../config/zeroboiler.php';
        expect($config['analytics'])->toHaveKey('inbound_webhook');
        expect($config['analytics']['inbound_webhook'])->toHaveKey('enabled');
        expect($config['analytics']['inbound_webhook'])->toHaveKey('secret');
        expect($config['analytics']['inbound_webhook'])->toHaveKey('require_signature');
        expect($config['analytics']['inbound_webhook'])->toHaveKey('max_payload_size');
        expect($config['analytics']['inbound_webhook'])->toHaveKey('max_events');
    });

    it('pipeline config includes auto_metadata and schema_enrichment', function () {
        $config = include __DIR__.'/../config/zeroboiler.php';
        expect($config['analytics']['pipeline'])->toHaveKey('auto_metadata');
        expect($config['analytics']['pipeline'])->toHaveKey('schema_enrichment');
        expect($config['analytics']['pipeline']['auto_metadata'])->toBeTrue();
        expect($config['analytics']['pipeline']['schema_enrichment'])->toBeFalse();
    });
});
