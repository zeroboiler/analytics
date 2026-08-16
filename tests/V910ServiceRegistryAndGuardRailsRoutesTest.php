<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\Attributes\Test;
use ZeroBoiler\Analytics\Support\AnalyticsServiceRegistry;
use Illuminate\Container\Container;

/**
 * Tests for the AnalyticsServiceRegistry — v9.1.0.
 *
 * Verifies lazy resolution, null fallback for unbound services,
 * key listing, and resolved count tracking.
 *
 * @covers \ZeroBoiler\Analytics\Support\AnalyticsServiceRegistry
 */
final class AnalyticsServiceRegistryTest extends \PHPUnit\Framework\TestCase
{
    private Container $container;

    private AnalyticsServiceRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container;
        $this->registry = new AnalyticsServiceRegistry($this->container);
    }

    #[Test]
    public function it_returns_null_for_unbound_service(): void
    {
        $result = $this->registry->get('validator');

        $this->assertNull($result);
    }

    #[Test]
    public function it_resolves_bound_service(): void
    {
        $service = new \stdClass;
        $service->name = 'test';

        $this->container->bind(
            \ZeroBoiler\Analytics\Services\EventValidationService::class,
            fn (): \stdClass => $service,
        );

        $result = $this->registry->get('validator');

        $this->assertNotNull($result);
        $this->assertSame('test', $result->name);
    }

    #[Test]
    public function it_caches_resolved_service(): void
    {
        $count = 0;
        $this->container->bind(
            \ZeroBoiler\Analytics\Services\EventStreamService::class,
            function () use (&$count): \stdClass {
                $count++;

                return new \stdClass;
            },
        );

        $this->registry->get('streamService');
        $this->registry->get('streamService');
        $this->registry->get('streamService');

        $this->assertSame(1, $count, 'Service should be resolved only once from container');
    }

    #[Test]
    public function has_returns_true_for_bound_service(): void
    {
        $this->container->singleton(
            \ZeroBoiler\Analytics\Services\EventStreamService::class,
            fn (): \stdClass => new \stdClass,
        );

        $this->assertTrue($this->registry->has('streamService'));
        $this->assertFalse($this->registry->has('validator'));
    }

    #[Test]
    public function keys_returns_all_registered_keys(): void
    {
        $keys = $this->registry->keys();

        $this->assertContains('validator', $keys);
        $this->assertContains('streamService', $keys);
        $this->assertContains('guardRailsService', $keys);
        $this->assertContains('deliveryConfirmationService', $keys);
        $this->assertGreaterThan(40, count($keys));
    }

    #[Test]
    public function count_returns_total_registered_services(): void
    {
        $this->assertGreaterThan(40, $this->registry->count());
    }

    #[Test]
    public function resolved_returns_all_resolved_services(): void
    {
        $this->container->singleton(
            \ZeroBoiler\Analytics\Services\EventValidationService::class,
            fn (): \stdClass => new \stdClass,
        );
        $this->container->singleton(
            \ZeroBoiler\Analytics\Services\EventStreamService::class,
            fn (): \stdClass => new \stdClass,
        );

        $resolved = $this->registry->resolved();

        $this->assertArrayHasKey('validator', $resolved);
        $this->assertNotNull($resolved['validator']);
        $this->assertArrayHasKey('streamService', $resolved);
        $this->assertNotNull($resolved['streamService']);
        $this->assertArrayHasKey('dlqService', $resolved);
        $this->assertNull($resolved['dlqService']);
    }

    #[Test]
    public function resolved_count_reports_correctly(): void
    {
        $this->container->singleton(
            \ZeroBoiler\Analytics\Services\EventValidationService::class,
            fn (): \stdClass => new \stdClass,
        );

        $this->assertSame(1, $this->registry->resolvedCount());
    }

    #[Test]
    public function it_returns_null_for_unknown_key(): void
    {
        $result = $this->registry->get('nonexistent_service');

        $this->assertNull($result);
    }

    #[Test]
    public function it_resolves_guard_rails_service(): void
    {
        $this->container->singleton(
            \ZeroBoiler\Analytics\Services\TrackingGuardRailsService::class,
            fn (): \stdClass => new \stdClass,
        );

        $result = $this->registry->get('guardRailsService');

        $this->assertNotNull($result);
    }

    #[Test]
    public function it_resolves_delivery_confirmation_service(): void
    {
        $this->container->singleton(
            \ZeroBoiler\Analytics\Services\EventDeliveryConfirmationService::class,
            fn (): \stdClass => new \stdClass,
        );

        $result = $this->registry->get('deliveryConfirmationService');

        $this->assertNotNull($result);
    }
}
