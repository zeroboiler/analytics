<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Enrichment\EventEnrichmentOrchestrator;
use ZeroBoiler\Analytics\Enrichment\EventEnrichmentPlugin;
use ZeroBoiler\Analytics\Enrichment\EventEnrichmentRegistry;

// ── Test Doubles ──────────────────────────────────────────────────────

/** Simple enrichment plugin that adds a tag to event params. */
final class TagEnrichmentPlugin implements EventEnrichmentPlugin
{
    public function __construct(
        private readonly string $tag = 'enriched',
        private readonly int $priority = 0,
    ) {}

    public function name(): string
    {
        return 'tag_enrichment';
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function shouldEnrich(AnalyticsEvent $event): bool
    {
        return true;
    }

    public function enrich(AnalyticsEvent $event): ?AnalyticsEvent
    {
        return new AnalyticsEvent(
            name: $event->name,
            params: array_merge($event->params, [$this->tag => true]),
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $event->source,
        );
    }
}

/** Plugin that drops events with specific names. */
final class DropFilterPlugin implements EventEnrichmentPlugin
{
    public function __construct(
        private readonly string $dropEventName = 'drop_me',
    ) {}

    public function name(): string
    {
        return 'drop_filter';
    }

    public function priority(): int
    {
        return 100;
    }

    public function shouldEnrich(AnalyticsEvent $event): bool
    {
        return $event->name === $this->dropEventName;
    }

    public function enrich(AnalyticsEvent $event): ?AnalyticsEvent
    {
        return null; // Drop the event
    }
}

/** Plugin that only enriches events starting with 'saas_'. */
final class SaaSOnlyPlugin implements EventEnrichmentPlugin
{
    public function name(): string
    {
        return 'saas_only';
    }

    public function priority(): int
    {
        return 50;
    }

    public function shouldEnrich(AnalyticsEvent $event): bool
    {
        return str_starts_with($event->name, 'saas_');
    }

    public function enrich(AnalyticsEvent $event): ?AnalyticsEvent
    {
        return new AnalyticsEvent(
            name: $event->name,
            params: array_merge($event->params, ['saas_tagged' => true]),
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $event->source,
        );
    }
}

/** Plugin that throws an exception. */
final class BrokenPlugin implements EventEnrichmentPlugin
{
    public function name(): string
    {
        return 'broken';
    }

    public function priority(): int
    {
        return 0;
    }

    public function shouldEnrich(AnalyticsEvent $event): bool
    {
        return true;
    }

    public function enrich(AnalyticsEvent $event): ?AnalyticsEvent
    {
        throw new \RuntimeException('Plugin crashed');
    }
}

// ── EventEnrichmentRegistry Tests ─────────────────────────────────────

describe('EventEnrichmentRegistry', function () {
    it('creates an empty registry with no plugins in config', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'enrichment_plugins' => [
                        'enabled' => true,
                        'disabled' => [],
                        'plugins' => [],
                        'debug' => false,
                    ],
                ],
            ],
        ]);

        $registry = new EventEnrichmentRegistry($config);

        expect($registry->count())->toBe(0)
            ->and($registry->isEnabled())->toBeTrue()
            ->and($registry->names())->toBe([])
            ->and($registry->all())->toBe([]);
    });

    it('registers a plugin programmatically', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'enrichment_plugins' => [
                        'enabled' => true,
                        'disabled' => [],
                        'plugins' => [],
                    ],
                ],
            ],
        ]);

        $registry = new EventEnrichmentRegistry($config);
        $plugin = new TagEnrichmentPlugin('my_tag', 10);
        $registry->register($plugin);

        expect($registry->count())->toBe(1)
            ->and($registry->has('tag_enrichment'))->toBeTrue()
            ->and($registry->get('tag_enrichment'))->toBe($plugin);
    });

    it('replaces a plugin with the same name', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'enrichment_plugins' => [
                        'enabled' => true,
                        'disabled' => [],
                        'plugins' => [],
                        'debug' => true,
                    ],
                ],
            ],
        ]);

        $registry = new EventEnrichmentRegistry($config);
        $registry->register(new TagEnrichmentPlugin('first', 10));
        $registry->register(new TagEnrichmentPlugin('second', 20));

        expect($registry->count())->toBe(1)
            ->and($registry->get('tag_enrichment')->name())->toBe('tag_enrichment');
    });

    it('sorts plugins by priority (highest first)', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'enrichment_plugins' => [
                        'enabled' => true,
                        'disabled' => [],
                        'plugins' => [],
                    ],
                ],
            ],
        ]);

        $registry = new EventEnrichmentRegistry($config);
        $registry->register(new TagEnrichmentPlugin('low', 0));
        $registry->register(new SaaSOnlyPlugin); // priority 50
        $registry->register(new DropFilterPlugin); // priority 100

        $all = $registry->all();

        expect($all)->toHaveCount(3)
            ->and($all[0]->name())->toBe('drop_filter')
            ->and($all[1]->name())->toBe('saas_only')
            ->and($all[2]->name())->toBe('tag_enrichment');
    });

    it('disables and enables individual plugins', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'enrichment_plugins' => [
                        'enabled' => true,
                        'disabled' => [],
                        'plugins' => [],
                    ],
                ],
            ],
        ]);

        $registry = new EventEnrichmentRegistry($config);
        $registry->register(new TagEnrichmentPlugin);
        $registry->register(new SaaSOnlyPlugin);

        expect($registry->isEnabled('tag_enrichment'))->toBeTrue()
            ->and($registry->isEnabled('saas_only'))->toBeTrue();

        $registry->disable('tag_enrichment');

        expect($registry->isEnabled('tag_enrichment'))->toBeFalse()
            ->and($registry->activeCount())->toBe(1);

        $registry->enable('tag_enrichment');

        expect($registry->isEnabled('tag_enrichment'))->toBeTrue()
            ->and($registry->activeCount())->toBe(2);
    });

    it('removes a plugin from the registry', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'enrichment_plugins' => [
                        'enabled' => true,
                        'disabled' => [],
                        'plugins' => [],
                    ],
                ],
            ],
        ]);

        $registry = new EventEnrichmentRegistry($config);
        $registry->register(new TagEnrichmentPlugin);
        $registry->register(new SaaSOnlyPlugin);

        $registry->remove('tag_enrichment');

        expect($registry->has('tag_enrichment'))->toBeFalse()
            ->and($registry->count())->toBe(1);
    });

    it('returns null for unknown plugin', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'enrichment_plugins' => [
                        'enabled' => true,
                        'disabled' => [],
                        'plugins' => [],
                    ],
                ],
            ],
        ]);

        $registry = new EventEnrichmentRegistry($config);

        expect($registry->get('nonexistent'))->toBeNull()
            ->and($registry->has('nonexistent'))->toBeFalse();
    });

    it('can be disabled globally via config', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'enrichment_plugins' => [
                        'enabled' => false,
                        'disabled' => [],
                        'plugins' => [],
                    ],
                ],
            ],
        ]);

        $registry = new EventEnrichmentRegistry($config);

        expect($registry->isEnabled())->toBeFalse();
    });

    it('returns a diagnostic summary', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'enrichment_plugins' => [
                        'enabled' => true,
                        'disabled' => [],
                        'plugins' => [],
                    ],
                ],
            ],
        ]);

        $registry = new EventEnrichmentRegistry($config);
        $registry->register(new TagEnrichmentPlugin('test', 50));
        $registry->register(new SaaSOnlyPlugin);
        $registry->disable('saas_only');

        $summary = $registry->summary();

        expect($summary)->toHaveKey('enabled')
            ->and($summary['enabled'])->toBeTrue()
            ->and($summary['total'])->toBe(2)
            ->and($summary['active'])->toBe(1)
            ->and($summary['disabled'])->toBe(['saas_only'])
            ->and($summary['plugins'])->toHaveCount(2);
    });

    it('loads plugins from config class list', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'enrichment_plugins' => [
                        'enabled' => true,
                        'disabled' => [],
                        'plugins' => [
                            TagEnrichmentPlugin::class,
                            SaaSOnlyPlugin::class,
                        ],
                    ],
                ],
            ],
        ]);

        $registry = new EventEnrichmentRegistry($config);

        expect($registry->count())->toBe(2)
            ->and($registry->has('tag_enrichment'))->toBeTrue()
            ->and($registry->has('saas_only'))->toBeTrue();
    });
});

// ── EventEnrichmentOrchestrator Tests ─────────────────────────────────

describe('EventEnrichmentOrchestrator', function () {
    it('returns event unchanged when no plugins are registered', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'enrichment_plugins' => [
                        'enabled' => true,
                        'disabled' => [],
                        'plugins' => [],
                    ],
                ],
            ],
        ]);

        $registry = new EventEnrichmentRegistry($config);
        $metrics = new AnalyticsMetrics;
        $orchestrator = new EventEnrichmentOrchestrator($registry, $metrics, false);

        $event = new AnalyticsEvent(name: 'page_view', params: ['url' => '/home']);
        $result = $orchestrator->enrich($event);

        expect($result)->not->toBeNull()
            ->and($result->name)->toBe('page_view')
            ->and($result->params)->toBe(['url' => '/home']);
    });

    it('enriches event through a single plugin', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'enrichment_plugins' => [
                        'enabled' => true,
                        'disabled' => [],
                        'plugins' => [],
                    ],
                ],
            ],
        ]);

        $registry = new EventEnrichmentRegistry($config);
        $registry->register(new TagEnrichmentPlugin('custom_tag'));

        $metrics = new AnalyticsMetrics;
        $orchestrator = new EventEnrichmentOrchestrator($registry, $metrics, false);

        $event = new AnalyticsEvent(name: 'page_view', params: ['url' => '/home']);
        $result = $orchestrator->enrich($event);

        expect($result)->not->toBeNull()
            ->and($result->name)->toBe('page_view')
            ->and($result->params)->toBe(['url' => '/home', 'custom_tag' => true]);
    });

    it('enriches event through multiple plugins in priority order', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'enrichment_plugins' => [
                        'enabled' => true,
                        'disabled' => [],
                        'plugins' => [],
                    ],
                ],
            ],
        ]);

        $registry = new EventEnrichmentRegistry($config);
        $registry->register(new TagEnrichmentPlugin('first_tag', 10));
        $registry->register(new TagEnrichmentPlugin('second_tag', 20));

        $metrics = new AnalyticsMetrics;
        $orchestrator = new EventEnrichmentOrchestrator($registry, $metrics, false);

        $event = new AnalyticsEvent(name: 'test_event');
        $result = $orchestrator->enrich($event);

        expect($result)->not->toBeNull()
            ->and($result->params)->toBe([
                'second_tag' => true, // priority 20 runs first
                'first_tag' => true,
            ]);
    });

    it('drops event when plugin returns null', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'enrichment_plugins' => [
                        'enabled' => true,
                        'disabled' => [],
                        'plugins' => [],
                    ],
                ],
            ],
        ]);

        $registry = new EventEnrichmentRegistry($config);
        $registry->register(new DropFilterPlugin('drop_me'));

        $metrics = new AnalyticsMetrics;
        $orchestrator = new EventEnrichmentOrchestrator($registry, $metrics, false);

        $event = new AnalyticsEvent(name: 'drop_me', params: ['key' => 'value']);
        $result = $orchestrator->enrich($event);

        expect($result)->toBeNull();
    });

    it('does not drop events that do not match drop condition', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'enrichment_plugins' => [
                        'enabled' => true,
                        'disabled' => [],
                        'plugins' => [],
                    ],
                ],
            ],
        ]);

        $registry = new EventEnrichmentRegistry($config);
        $registry->register(new DropFilterPlugin('drop_me'));

        $metrics = new AnalyticsMetrics;
        $orchestrator = new EventEnrichmentOrchestrator($registry, $metrics, false);

        $event = new AnalyticsEvent(name: 'keep_me', params: ['key' => 'value']);
        $result = $orchestrator->enrich($event);

        expect($result)->not->toBeNull()
            ->and($result->name)->toBe('keep_me');
    });

    it('respects shouldEnrich to skip irrelevant events', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'enrichment_plugins' => [
                        'enabled' => true,
                        'disabled' => [],
                        'plugins' => [],
                    ],
                ],
            ],
        ]);

        $registry = new EventEnrichmentRegistry($config);
        $registry->register(new SaaSOnlyPlugin);

        $metrics = new AnalyticsMetrics;
        $orchestrator = new EventEnrichmentOrchestrator($registry, $metrics, false);

        // Non-SaaS event — plugin should not enrich
        $event = new AnalyticsEvent(name: 'page_view', params: ['url' => '/']);
        $result = $orchestrator->enrich($event);

        expect($result)->not->toBeNull()
            ->and($result->params)->not->toHaveKey('saas_tagged');

        // SaaS event — plugin should enrich
        $saasEvent = new AnalyticsEvent(name: 'saas_signup', params: []);
        $saasResult = $orchestrator->enrich($saasEvent);

        expect($saasResult)->not->toBeNull()
            ->and($saasResult->params)->toHaveKey('saas_tagged')
            ->and($saasResult->params['saas_tagged'])->toBeTrue();
    });

    it('continues to next plugin when one throws exception', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'enrichment_plugins' => [
                        'enabled' => true,
                        'disabled' => [],
                        'plugins' => [],
                    ],
                ],
            ],
        ]);

        $registry = new EventEnrichmentRegistry($config);
        $registry->register(new BrokenPlugin);
        $registry->register(new TagEnrichmentPlugin('safe_tag', 100));

        $metrics = new AnalyticsMetrics;
        $orchestrator = new EventEnrichmentOrchestrator($registry, $metrics, false);

        $event = new AnalyticsEvent(name: 'test_event', params: []);
        $result = $orchestrator->enrich($event);

        // BrokenPlugin throws, TagEnrichmentPlugin still runs
        expect($result)->not->toBeNull()
            ->and($result->params)->toHaveKey('safe_tag');
    });

    it('bypasses all plugins when registry is disabled', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'enrichment_plugins' => [
                        'enabled' => false,
                        'disabled' => [],
                        'plugins' => [],
                    ],
                ],
            ],
        ]);

        $registry = new EventEnrichmentRegistry($config);
        $registry->register(new TagEnrichmentPlugin('tag'));

        $metrics = new AnalyticsMetrics;
        $orchestrator = new EventEnrichmentOrchestrator($registry, $metrics, false);

        $event = new AnalyticsEvent(name: 'test_event', params: []);
        $result = $orchestrator->enrich($event);

        expect($result)->not->toBeNull()
            ->and($result->params)->not->toHaveKey('tag');
    });

    it('tracks enrichment metrics correctly', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'enrichment_plugins' => [
                        'enabled' => true,
                        'disabled' => [],
                        'plugins' => [],
                    ],
                ],
            ],
        ]);

        $registry = new EventEnrichmentRegistry($config);
        $registry->register(new TagEnrichmentPlugin('tag1'));
        $registry->register(new SaaSOnlyPlugin);

        $metrics = new AnalyticsMetrics;
        $orchestrator = new EventEnrichmentOrchestrator($registry, $metrics, false);

        // 1 enriched event
        $orchestrator->enrich(new AnalyticsEvent(name: 'page_view', params: []));
        // 1 enriched event (SaaS event gets tagged by both plugins)
        $orchestrator->enrich(new AnalyticsEvent(name: 'saas_signup', params: []));

        $m = $orchestrator->metrics();

        expect($m['total_processed'])->toBe(2)
            ->and($m['total_enriched'])->toBe(2)
            ->and($m['total_dropped'])->toBe(0)
            ->and($m['total_passed'])->toBe(0)
            ->and($m['plugins'])->toHaveCount(2);
    });

    it('tracks dropped event metrics', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'enrichment_plugins' => [
                        'enabled' => true,
                        'disabled' => [],
                        'plugins' => [],
                    ],
                ],
            ],
        ]);

        $registry = new EventEnrichmentRegistry($config);
        $registry->register(new DropFilterPlugin('drop_me'));

        $metrics = new AnalyticsMetrics;
        $orchestrator = new EventEnrichmentOrchestrator($registry, $metrics, false);

        $orchestrator->enrich(new AnalyticsEvent(name: 'drop_me', params: []));
        $orchestrator->enrich(new AnalyticsEvent(name: 'keep_me', params: []));

        $m = $orchestrator->metrics();

        expect($m['total_processed'])->toBe(2)
            ->and($m['total_dropped'])->toBe(1)
            ->and($m['total_passed'])->toBe(1);
    });

    it('resets metrics correctly', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'enrichment_plugins' => [
                        'enabled' => true,
                        'disabled' => [],
                        'plugins' => [],
                    ],
                ],
            ],
        ]);

        $registry = new EventEnrichmentRegistry($config);
        $registry->register(new TagEnrichmentPlugin);

        $metrics = new AnalyticsMetrics;
        $orchestrator = new EventEnrichmentOrchestrator($registry, $metrics, false);

        $orchestrator->enrich(new AnalyticsEvent(name: 'test', params: []));
        $orchestrator->reset();

        $m = $orchestrator->metrics();

        expect($m['total_processed'])->toBe(0)
            ->and($m['total_enriched'])->toBe(0)
            ->and($m['total_dropped'])->toBe(0)
            ->and($m['total_passed'])->toBe(0);
    });

    it('provides access to the underlying registry', function () {
        $config = new \Illuminate\Config\Repository([
            'zeroboiler' => [
                'analytics' => [
                    'enrichment_plugins' => [
                        'enabled' => true,
                        'disabled' => [],
                        'plugins' => [],
                    ],
                ],
            ],
        ]);

        $registry = new EventEnrichmentRegistry($config);
        $metrics = new AnalyticsMetrics;
        $orchestrator = new EventEnrichmentOrchestrator($registry, $metrics, false);

        expect($orchestrator->registry())->toBe($registry);
    });
});

// ── AnalyticsMetrics Extension Tests ───────────────────────────────────

describe('AnalyticsMetrics increment/counter extension', function () {
    it('increments generic counters', function () {
        $metrics = new AnalyticsMetrics;
        $metrics->setEnabled(true);

        $metrics->increment('enrichment.enriched');
        $metrics->increment('enrichment.enriched');
        $metrics->increment('enrichment.dropped');

        expect($metrics->counter('enrichment.enriched'))->toBe(2)
            ->and($metrics->counter('enrichment.dropped'))->toBe(1)
            ->and($metrics->counter('nonexistent'))->toBe(0);
    });

    it('increments by custom amount', function () {
        $metrics = new AnalyticsMetrics;
        $metrics->setEnabled(true);

        $metrics->increment('custom', 5);
        $metrics->increment('custom', 3);

        expect($metrics->counter('custom'))->toBe(8);
    });

    it('returns all counters', function () {
        $metrics = new AnalyticsMetrics;
        $metrics->setEnabled(true);

        $metrics->increment('a');
        $metrics->increment('b');

        expect($metrics->counters())->toBe([
            'a' => 1,
            'b' => 1,
        ]);
    });

    it('does not increment when disabled', function () {
        $metrics = new AnalyticsMetrics;
        $metrics->setEnabled(false);

        $metrics->increment('test');

        expect($metrics->counter('test'))->toBe(0);
    });

    it('includes counters in summary', function () {
        $metrics = new AnalyticsMetrics;
        $metrics->setEnabled(true);

        $metrics->increment('enrichment.enriched', 3);
        $metrics->recordDispatch('ga4');

        $summary = $metrics->summary();

        expect($summary)->toHaveKey('counters')
            ->and($summary['counters']['enrichment.enriched'])->toBe(3);
    });

    it('resets counters on flush', function () {
        $metrics = new AnalyticsMetrics;
        $metrics->setEnabled(true);

        $metrics->increment('test');
        $metrics->flush();

        expect($metrics->counter('test'))->toBe(0)
            ->and($metrics->counters())->toBe([]);
    });
});
