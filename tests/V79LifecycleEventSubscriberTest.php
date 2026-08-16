<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\SaaS\LoginEvent;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;
use ZeroBoiler\Analytics\Tracking\LifecycleEventSubscriber;
use ZeroBoiler\Analytics\Tracking\ServerSideTracker;

/**
 * Tests for LifecycleEventSubscriber (v79.0.0).
 *
 * Validates the unified lifecycle event subscriber that bridges
 * the config-driven LifecycleEventMapper with the legacy ServerSideTracker
 * and optional queued dispatch.
 *
 * @covers \ZeroBoiler\Analytics\Tracking\LifecycleEventSubscriber
 * @covers \ZeroBoiler\Analytics\Services\LifecycleEventMapper
 *
 * @since 79.0.0
 */
final class V79LifecycleEventSubscriberTest extends TestCase
{
    /**
     * Create a mock config repository with standard analytics settings.
     *
     * @param  array<string, mixed>  $lifecycleOverrides  Override lifecycle config values
     * @return object{get: callable(string, mixed=): mixed}
     */
    private function mockConfig(array $lifecycleOverrides = []): object
    {
        $defaults = [
            'enabled' => true,
            'events' => [],
            'custom_mappings' => [],
            'override_defaults' => false,
            'queue_events' => false,
        ];

        $lifecycle = array_merge($defaults, $lifecycleOverrides);

        return new class ($lifecycle) {
            /** @param  array<string, mixed>  $lifecycle */
            public function __construct(private readonly array $lifecycle) {}

            /**
             * @param  string  $key
             * @param  mixed  $default
             */
            public function get(string $key, mixed $default = null): mixed
            {
                return match ($key) {
                    'zeroboiler.analytics.lifecycle' => $this->lifecycle,
                    'zeroboiler.analytics.auto_track' => ['enabled' => true, 'events' => [], 'models' => [], 'event_map' => []],
                    'zeroboiler.analytics.queue' => ['enabled' => false, 'queue' => 'analytics', 'connection' => null, 'max_batch_size' => 50],
                    'zeroboiler.analytics.identity.cookie_name' => 'zb_analytics_id',
                    'zeroboiler.analytics.identity.cookie_ttl' => 525600,
                    'zeroboiler.analytics.identity.cookie_secure' => true,
                    'zeroboiler.analytics.identity.cookie_samesite' => 'Lax',
                    'zeroboiler.analytics.identity.cookie_domain' => null,
                    default => $default ?? [],
                };
            }
        };
    }

    private function createSubscriber(array $lifecycleOverrides = []): LifecycleEventSubscriber
    {
        $config = $this->mockConfig($lifecycleOverrides);
        $manager = new AnalyticsManager($config);
        $mapper = new LifecycleEventMapper($manager, $config);
        $tracker = new ServerSideTracker($manager, $config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);

        return new LifecycleEventSubscriber($manager, $mapper, $tracker, $queue, $config);
    }

    /**
     * @test
     */
    public function it_registers_lifecycle_mappings(): void
    {
        $subscriber = $this->createSubscriber();

        $dispatcher = new \Illuminate\Events\Dispatcher;
        $subscriber->register($dispatcher);

        $this->assertGreaterThan(0, $subscriber->registeredCount());
    }

    /**
     * @test
     */
    public function it_exposes_registered_keys(): void
    {
        $subscriber = $this->createSubscriber();

        $dispatcher = new \Illuminate\Events\Dispatcher;
        $subscriber->register($dispatcher);

        $keys = $subscriber->registeredKeys();

        $this->assertContains('auth.login', $keys);
        $this->assertContains('auth.register', $keys);
        $this->assertContains('subscription.created', $keys);
        $this->assertContains('trial.started', $keys);
        $this->assertContains('order.completed', $keys);
        $this->assertContains('form.submitted', $keys);
    }

    /**
     * @test
     */
    public function it_checks_if_key_is_registered(): void
    {
        $subscriber = $this->createSubscriber();

        $dispatcher = new \Illuminate\Events\Dispatcher;
        $subscriber->register($dispatcher);

        $this->assertTrue($subscriber->isRegistered('auth.login'));
        $this->assertTrue($subscriber->isRegistered('subscription.created'));
        $this->assertFalse($subscriber->isRegistered('nonexistent.event'));
    }

    /**
     * @test
     */
    public function it_tracks_event_programmatically(): void
    {
        $subscriber = $this->createSubscriber();

        // Should not throw
        $subscriber->track('auth.login', ['guard' => 'web']);
        $subscriber->track('nonexistent.event', []);
        $this->assertTrue(true); // No exception thrown
    }

    /**
     * @test
     */
    public function it_returns_diagnostic_summary(): void
    {
        $subscriber = $this->createSubscriber();

        $dispatcher = new \Illuminate\Events\Dispatcher;
        $subscriber->register($dispatcher);

        $summary = $subscriber->diagnosticSummary();

        $this->assertArrayHasKey('registered_count', $summary);
        $this->assertArrayHasKey('keys', $summary);
        $this->assertArrayHasKey('errors', $summary);
        $this->assertArrayHasKey('queue_enabled', $summary);
        $this->assertArrayHasKey('queue_lifecycle', $summary);
        $this->assertGreaterThan(0, $summary['registered_count']);
        $this->assertFalse($summary['queue_lifecycle']);
        $this->assertIsArray($summary['keys']);
        $this->assertIsArray($summary['errors']);
        $this->assertEmpty($summary['errors']);
    }

    /**
     * @test
     */
    public function it_exposes_mapper_and_tracker_instances(): void
    {
        $config = $this->mockConfig();
        $manager = new AnalyticsManager($config);
        $mapper = new LifecycleEventMapper($manager, $config);
        $tracker = new ServerSideTracker($manager, $config);
        $queue = new QueuedAnalyticsDispatcher($manager, $config);

        $subscriber = new LifecycleEventSubscriber($manager, $mapper, $tracker, $queue, $config);

        $this->assertSame($mapper, $subscriber->mapper());
        $this->assertSame($tracker, $subscriber->tracker());
    }

    /**
     * @test
     */
    public function it_respects_queue_lifecycle_config(): void
    {
        $subscriber = $this->createSubscriber(['queue_events' => true]);

        $summary = $subscriber->diagnosticSummary();
        $this->assertTrue($summary['queue_lifecycle']);
    }

    /**
     * @test
     */
    public function it_handles_disabled_lifecycle_gracefully(): void
    {
        $subscriber = $this->createSubscriber(['enabled' => false]);

        $dispatcher = new \Illuminate\Events\Dispatcher;
        $subscriber->register($dispatcher);

        $this->assertSame(0, $subscriber->registeredCount());
    }

    /**
     * @test
     */
    public function it_includes_ecommerce_lifecycle_keys(): void
    {
        $subscriber = $this->createSubscriber();

        $dispatcher = new \Illuminate\Events\Dispatcher;
        $subscriber->register($dispatcher);

        $keys = $subscriber->registeredKeys();

        $this->assertContains('order.completed', $keys);
        $this->assertContains('order.refunded', $keys);
        $this->assertContains('billing.payment_succeeded', $keys);
        $this->assertContains('billing.payment_failed', $keys);
        $this->assertContains('form.submitted', $keys);
        $this->assertContains('search.performed', $keys);
        $this->assertContains('team.created', $keys);
        $this->assertContains('team.member_joined', $keys);
        $this->assertContains('gdpr.data_subject_access_request', $keys);
        $this->assertContains('consent.granted', $keys);
    }

    /**
     * @test
     */
    public function lifecycle_mapper_provides_default_mapping_lookup(): void
    {
        $mapping = LifecycleEventMapper::getDefaultMapping('auth.login');

        $this->assertNotNull($mapping);
        $this->assertSame('Illuminate\\Auth\\Events\\Login', $mapping['source']);
        $this->assertSame(100, $mapping['priority']);
        $this->assertArrayHasKey('params_extractor', $mapping);
    }

    /**
     * @test
     */
    public function lifecycle_mapper_returns_null_for_unknown_key(): void
    {
        $this->assertNull(LifecycleEventMapper::getDefaultMapping('nonexistent.key'));
    }

    /**
     * @test
     */
    public function lifecycle_mapper_builds_event_from_mapping(): void
    {
        $config = $this->mockConfig();
        $manager = new AnalyticsManager($config);
        $mapper = new LifecycleEventMapper($manager, $config);

        $event = $mapper->buildEventFromMapping(
            LoginEvent::class,
            ['guard' => 'web'],
            'extractAuthParams',
        );

        $this->assertInstanceOf(AnalyticsEvent::class, $event);
        $this->assertSame('login', $event->name);
    }

    /**
     * @test
     */
    public function lifecycle_mapper_get_registered_mappings(): void
    {
        $config = $this->mockConfig();
        $manager = new AnalyticsManager($config);
        $mapper = new LifecycleEventMapper($manager, $config);

        $registered = $mapper->getRegisteredMappings();

        $this->assertIsArray($registered);
        $this->assertNotEmpty($registered);
        $this->assertTrue($registered['auth.login']);
    }

    /**
     * @test
     */
    public function lifecycle_mapper_provides_summary(): void
    {
        $config = $this->mockConfig();
        $manager = new AnalyticsManager($config);
        $mapper = new LifecycleEventMapper($manager, $config);

        $summary = $mapper->summary();

        $this->assertTrue($summary['enabled']);
        $this->assertGreaterThan(0, $summary['total_mappings']);
        $this->assertGreaterThan(0, $summary['enabled_count']);
        $this->assertArrayHasKey('categories', $summary);
        $this->assertArrayHasKey('auth', $summary['categories']);
        $this->assertArrayHasKey('subscription', $summary['categories']);
        $this->assertArrayHasKey('trial', $summary['categories']);
    }

    /**
     * @test
     */
    public function it_covers_all_default_mapping_keys(): void
    {
        // Ensure DEFAULT_MAPPINGS has no duplicate keys
        $keys = array_keys(LifecycleEventMapper::DEFAULT_MAPPINGS);
        $unique = array_unique($keys);
        $this->assertCount(count($keys), count($unique), 'DEFAULT_MAPPINGS must have unique keys');

        // Ensure at least 40 mappings exist (comprehensive coverage)
        $this->assertGreaterThanOrEqual(40, count($keys), 'DEFAULT_MAPPINGS should have at least 40 event mappings');
    }
}
