<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

use Illuminate\Contracts\Container\Container;

/**
 * Lightweight service locator for analytics controller dependencies.
 *
 * Instead of injecting 80+ nullable services into the AnalyticsEventController
 * constructor, this registry resolves services lazily from the container
 * on first access. Services that are not bound in the container return null.
 *
 * This keeps the controller constructor clean while maintaining full
 * testability — tests can bind mock services into the container before
 * resolving the controller.
 *
 * @since 9.1.0
 */
final class AnalyticsServiceRegistry
{
    /** @var array<string, object|null> */
    private array $resolved = [];

    /** @var array<string, class-string> */
    private static array $serviceMap = [
        // Event processing
        'validator' => \ZeroBoiler\Analytics\Services\EventValidationService::class,
        'streamService' => \ZeroBoiler\Analytics\Services\EventStreamService::class,
        'exportService' => \ZeroBoiler\Analytics\Services\ExportService::class,
        'profileService' => \ZeroBoiler\Analytics\Services\AnalyticsProfileService::class,
        'gdprErasureService' => \ZeroBoiler\Analytics\Services\GdprErasureService::class,
        'statsService' => \ZeroBoiler\Analytics\Services\AnalyticsStatsService::class,
        'inboundWebhookService' => \ZeroBoiler\Analytics\Services\InboundWebhookService::class,
        'schemaRegistry' => \ZeroBoiler\Analytics\Schema\EventSchemaRegistry::class,
        'alertRulesService' => \ZeroBoiler\Analytics\Services\EventAlertRulesService::class,
        'funnelDataBuilderService' => \ZeroBoiler\Analytics\Services\FunnelDataBuilderService::class,
        'lifecycleMapper' => \ZeroBoiler\Analytics\Services\LifecycleEventMapper::class,
        'lifecycleSubscriber' => \ZeroBoiler\Analytics\Tracking\LifecycleEventSubscriber::class,
        'correlationService' => \ZeroBoiler\Analytics\Services\EventCorrelationService::class,
        'configValidator' => \ZeroBoiler\Analytics\Services\AnalyticsConfigValidator::class,
        'sourceTagger' => \ZeroBoiler\Analytics\Services\EventSourceTagger::class,
        'referrerTrackingService' => \ZeroBoiler\Analytics\Services\ReferrerTrackingService::class,
        'broadcasterService' => \ZeroBoiler\Analytics\Services\EventBroadcasterService::class,
        'tenantService' => \ZeroBoiler\Analytics\Services\TenantIsolationService::class,
        'retentionService' => \ZeroBoiler\Analytics\Services\DataRetentionPolicyService::class,
        'gateService' => \ZeroBoiler\Analytics\Services\AnalyticsGateService::class,
        'reportingService' => \ZeroBoiler\Analytics\Services\EventReportingService::class,
        'dlqService' => \ZeroBoiler\Analytics\Services\DeadLetterQueueService::class,
        'realtimeService' => \ZeroBoiler\Analytics\Services\RealTimeAggregationService::class,
        'abTestService' => \ZeroBoiler\Analytics\Services\ABTestAnalyticsService::class,
        'snapshotService' => \ZeroBoiler\Analytics\Services\AnalyticsSnapshotService::class,
        'kpiTracker' => \ZeroBoiler\Analytics\Services\SaasKpiTracker::class,
        'utmAggregation' => \ZeroBoiler\Analytics\Services\UtmAggregationService::class,
        'forwardingService' => \ZeroBoiler\Analytics\Services\EventForwardingService::class,
        'performanceBudgetService' => \ZeroBoiler\Analytics\Services\PerformanceBudgetService::class,
        'attributionService' => \ZeroBoiler\Analytics\Services\UTMAttributionService::class,
        'taxonomyService' => \ZeroBoiler\Analytics\Services\EventTaxonomyService::class,
        'bucketsService' => \ZeroBoiler\Analytics\Services\EventBucketsService::class,
        'healthScoreService' => \ZeroBoiler\Analytics\Services\SaaSHealthScoreService::class,
        'journeyService' => \ZeroBoiler\Analytics\Services\UserJourneyService::class,
        'conversionService' => \ZeroBoiler\Analytics\Services\SaaSConversionService::class,
        'governanceService' => \ZeroBoiler\Analytics\Services\EventGovernanceService::class,
        'eventImpactService' => \ZeroBoiler\Analytics\Services\EventImpactService::class,
        'featureAdoptionTracker' => \ZeroBoiler\Analytics\Services\FeatureAdoptionTracker::class,
        'budgetService' => \ZeroBoiler\Analytics\Services\EventBudgetService::class,
        'configAuditService' => \ZeroBoiler\Analytics\Services\AnalyticsConfigAuditService::class,
        'catalogValidator' => \ZeroBoiler\Analytics\Services\EventCatalogValidator::class,
        'identityGraphService' => \ZeroBoiler\Analytics\Services\IdentityGraphService::class,
        'deviceFingerprintService' => \ZeroBoiler\Analytics\Services\DeviceFingerprintService::class,
        'guardRailsService' => \ZeroBoiler\Analytics\Services\TrackingGuardRailsService::class,
        'deliveryConfirmationService' => \ZeroBoiler\Analytics\Services\EventDeliveryConfirmationService::class,
        'cardinalityLimiter' => \ZeroBoiler\Analytics\Services\EventCardinalityLimiter::class,
        'structuredEventLogger' => \ZeroBoiler\Analytics\Services\StructuredEventLogger::class,
        'slaMonitor' => \ZeroBoiler\Analytics\Services\EventDeliverySlaMonitor::class,
    ];

    /**
     * Create a new service registry.
     *
     * @param  Container  $container  The Laravel application container
     */
    public function __construct(
        private readonly Container $container,
    ){}

    /**
     * Resolve a service by key.
     *
     * Returns the service instance if it is bound in the container,
     * or null if it is not registered.
     *
     * @template T
     * @param  string  $key  Service key from the registry map
     * @return T|null
     */
    public function get(string $key): ?object
    {
        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        $concrete = self::$serviceMap[$key] ?? null;

        if ($concrete === null) {
            $this->resolved[$key] = null;

            return null;
        }

        if ($this->container->bound($concrete)) {
            $this->resolved[$key] = $this->container->make($concrete);
        } else {
            $this->resolved[$key] = null;
        }

        return $this->resolved[$key];
    }

    /**
     * Check if a service is available (bound in the container).
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Get all registered service keys.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys(self::$serviceMap);
    }

    /**
     * Get the number of registered services.
     */
    public function count(): int
    {
        return count(self::$serviceMap);
    }

    /**
     * Get all resolved service instances.
     *
     * @return array<string, object|null>
     */
    public function resolved(): array
    {
        // Force-resolve all services
        foreach (array_keys(self::$serviceMap) as $key) {
            $this->get($key);
        }

        return $this->resolved;
    }

    /**
     * Get the number of successfully resolved services.
     */
    public function resolvedCount(): int
    {
        $this->resolved();

        return count(array_filter($this->resolved, fn (?object $s): bool => $s !== null));
    }
}
