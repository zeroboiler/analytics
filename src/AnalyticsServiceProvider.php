<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ZeroBoiler\Analytics\Blade\Directives\AnalyticsDirectives;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsExportCommand;
use ZeroBoiler\Analytics\Console\Commands\RevenueReportCommand;
use ZeroBoiler\Analytics\Http\Middleware\InjectAnalyticsScripts;
use ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Pipeline\EventPipeline;
use ZeroBoiler\Analytics\Services\EcommerceAnalyticsService;
use ZeroBoiler\Analytics\Bus\AnalyticsDataBus;
use ZeroBoiler\Analytics\Context\EventContextBuilder;
use ZeroBoiler\Analytics\Middleware\AnalyticsMiddlewareStack;
use ZeroBoiler\Analytics\Middleware\PiiSanitizationMiddleware;
use ZeroBoiler\Analytics\Pipeline\SamplingFilter;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;
use ZeroBoiler\Analytics\Tracking\AnonymousIdTracker;
use ZeroBoiler\Analytics\Services\EventValidationService;
use ZeroBoiler\Analytics\Services\FunnelProgressTracker;
use ZeroBoiler\Analytics\Services\FunnelAnalyticsService;
use ZeroBoiler\Analytics\Services\OnboardingCompletionService;
use ZeroBoiler\Analytics\Services\GoogleTagManagerService;
use ZeroBoiler\Analytics\Services\MetaPixelService;
use ZeroBoiler\Analytics\Services\RevenueAnalyticsService;
use ZeroBoiler\Analytics\Services\RevenueAttributionService;
use ZeroBoiler\Analytics\Services\SaaSAnalyticsService;
use ZeroBoiler\Analytics\Tracking\ServerSideTracker;
use ZeroBoiler\Analytics\Tracking\SessionTracker;
use ZeroBoiler\Analytics\Tracking\UserIdentityTracker;
use ZeroBoiler\Analytics\Queue\EventReplayQueue;
use ZeroBoiler\Analytics\Services\CohortAnalyticsService;
use ZeroBoiler\Analytics\Services\EventAggregationService;
use ZeroBoiler\Analytics\Services\SessionAnalyticsService;
use ZeroBoiler\Analytics\Services\UserJourneyService;
use ZeroBoiler\Analytics\Services\AnomalyDetectionService;
use ZeroBoiler\Analytics\Services\EventStreamService;
use ZeroBoiler\Analytics\Services\ExportService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsHealthCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsDashboardCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsScheduledReportCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsReadinessCommand;
use ZeroBoiler\Analytics\Services\AnalyticsHealthService;
use ZeroBoiler\Analytics\Support\AnalyticsConfig;
use ZeroBoiler\Analytics\Services\TrackingPreferenceService;
use ZeroBoiler\Analytics\Services\SaaSJourneyService;
use ZeroBoiler\Analytics\Services\AnalyticsAnonymizationService;
use ZeroBoiler\Analytics\Services\EventDeduplicationService;
use ZeroBoiler\Analytics\Services\DeviceContextService;
use ZeroBoiler\Analytics\Services\IpAnonymizationService;
use ZeroBoiler\Analytics\Services\SaasFunnelService;
use ZeroBoiler\Analytics\Services\AnalyticsProfileService;
use ZeroBoiler\Analytics\Services\AttributionService;
use ZeroBoiler\Analytics\Services\GdprErasureService;
use ZeroBoiler\Analytics\Services\AnalyticsStatsService;
use ZeroBoiler\Analytics\Services\InboundWebhookService;
use ZeroBoiler\Analytics\Services\EventAlertRulesService;
use ZeroBoiler\Analytics\Services\EventCorrelationService;
use ZeroBoiler\Analytics\Services\FunnelDataBuilderService;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;
use ZeroBoiler\Analytics\Services\AnalyticsConfigValidator;
use ZeroBoiler\Analytics\Services\EventSourceTagger;
use ZeroBoiler\Analytics\Services\ReferrerTrackingService;
use ZeroBoiler\Analytics\Services\EventBroadcasterService;
use ZeroBoiler\Analytics\Services\TenantIsolationService;
use ZeroBoiler\Analytics\Services\DataRetentionPolicyService;
use ZeroBoiler\Analytics\Services\AnalyticsGateService;
use ZeroBoiler\Analytics\Services\EventReportingService;
use ZeroBoiler\Analytics\Services\DeadLetterQueueService;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;
use ZeroBoiler\Analytics\Services\RealTimeAggregationService;
use ZeroBoiler\Analytics\Services\DataWarehouseExportService;
use ZeroBoiler\Analytics\Schema\EventPropertySchema;
use ZeroBoiler\Analytics\Services\AnalyticsDashboardDataProvider;
use ZeroBoiler\Analytics\Services\ABTestAnalyticsService;
use ZeroBoiler\Analytics\Services\AnalyticsSnapshotService;
use ZeroBoiler\Analytics\Services\SaasKpiTracker;
use ZeroBoiler\Analytics\Services\UtmAggregationService;
use ZeroBoiler\Analytics\Services\ConsentLogService;
use ZeroBoiler\Analytics\Services\EventForwardingService;
use ZeroBoiler\Analytics\Services\PerformanceBudgetService;
use ZeroBoiler\Analytics\Services\UTMAttributionService;
use ZeroBoiler\Analytics\Pipeline\GeolocationEnricher;
use ZeroBoiler\Analytics\Services\AnalyticsEventRouter;
use ZeroBoiler\Analytics\Services\EventAliasResolver;
use ZeroBoiler\Analytics\Services\EventCacheService;
use ZeroBoiler\Analytics\Services\EventBucketsService;
use ZeroBoiler\Analytics\Services\SaaSHealthScoreService;
use ZeroBoiler\Analytics\Services\AnalyticsHealthCheckService;
use ZeroBoiler\Analytics\Services\EventEnvelopeService;
use ZeroBoiler\Analytics\Services\CampaignRoiService;
use ZeroBoiler\Analytics\Services\DataMinimizationService;
use ZeroBoiler\Analytics\Pipeline\ConsentAwareFilter;
use ZeroBoiler\Analytics\Services\AnalyticsTelemetryService;
use ZeroBoiler\Analytics\Services\EventPriorityGate;
use ZeroBoiler\Analytics\Services\SaaSConversionService;
use ZeroBoiler\Analytics\Services\ProviderCircuitBreaker;
use ZeroBoiler\Analytics\Services\EventComplianceService;
use ZeroBoiler\Analytics\Services\AnalyticsRecoveryService;
use ZeroBoiler\Analytics\Services\AnalyticsSandboxService;
use ZeroBoiler\Analytics\Services\ProviderRateLimitService;
use ZeroBoiler\Analytics\Services\EventSchemaVersioningService;
use ZeroBoiler\Analytics\Services\AnalyticsReadinessService;
use ZeroBoiler\Analytics\Services\AnalyticsInsightsService;
use ZeroBoiler\Analytics\Services\FunnelVelocityService;
use ZeroBoiler\Analytics\Services\EventImpactService;
use ZeroBoiler\Analytics\Services\SaaSMetricsBenchmarkService;
use ZeroBoiler\Analytics\Services\PrivacySandboxService;
use ZeroBoiler\Analytics\Services\CartStateManager;
use ZeroBoiler\Analytics\Services\EventAffinityService;
use ZeroBoiler\Analytics\Services\SchemaDrivenEventBuilder;
use ZeroBoiler\Analytics\Services\SchemaDiffReporter;
use ZeroBoiler\Analytics\Services\AdvancedPIIDetector;
use ZeroBoiler\Analytics\Services\SessionReplayService;
use ZeroBoiler\Analytics\Support\EventBuilder;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsSchemaExportCommand;
use ZeroBoiler\Analytics\Services\EventOrchestrationService;
use ZeroBoiler\Analytics\Services\AnalyticsInsightAggregator;
use ZeroBoiler\Analytics\Services\EventContextResolver;

/**
 * @version 3.0.0
 */

/**
 * Laravel service provider for the ZeroBoiler Analytics package.
 *
 * Registers the analytics manager, tracker services, pipeline,
 * schema registry, Blade directives, middleware, and API routes.
 *
 * @version 3.0.0
 */
final class AnalyticsServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/zeroboiler.php',
            'zeroboiler',
        );

        $this->app->singleton('zeroboiler.analytics', function (Application $app): AnalyticsManager {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsManager($config);
        });

        $this->app->alias('zeroboiler.analytics', AnalyticsManager::class);

        // Bind convenience service wrappers
        $this->app->singleton(GoogleAnalyticsService::class, function (Application $app): GoogleAnalyticsService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');

            return new GoogleAnalyticsService($manager->ga4());
        });

        $this->app->singleton(GoogleTagManagerService::class, function (Application $app): GoogleTagManagerService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');

            return new GoogleTagManagerService($manager->gtm());
        });

        $this->app->singleton(MetaPixelService::class, function (Application $app): MetaPixelService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');

            return new MetaPixelService($manager->meta());
        });

        $this->app->singleton(ServerSideTracker::class, function (Application $app): ServerSideTracker {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new ServerSideTracker($manager, $config);
        });

        $this->app->singleton(QueuedAnalyticsDispatcher::class, function (Application $app): QueuedAnalyticsDispatcher {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new QueuedAnalyticsDispatcher($manager, $config);
        });

        $this->app->singleton(UserIdentityTracker::class, function (Application $app): UserIdentityTracker {
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $cookieName = $config->get('zeroboiler.analytics.identity.cookie_name', 'zb_analytics_id');

            return new UserIdentityTracker($queue, $cookieName);
        });

        $this->app->singleton(EcommerceAnalyticsService::class, function (Application $app): EcommerceAnalyticsService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EcommerceAnalyticsService($manager, $config);
        });

        $this->app->singleton(SaaSAnalyticsService::class, function (Application $app): SaaSAnalyticsService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');

            return new SaaSAnalyticsService($manager);
        });

        // PII sanitization middleware (configurable strategy)
        $this->app->bind(PiiSanitizationMiddleware::class, function (Application $app): PiiSanitizationMiddleware {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $piiConfig = $config->get('zeroboiler.analytics.pii_sanitization', []);
            /** @var array{enabled?: bool, strategy?: string, custom_fields?: list<string>} $piiConfig */

            $strategy = $piiConfig['strategy'] ?? PiiSanitizationMiddleware::STRATEGY_HASH;
            $customFields = $piiConfig['custom_fields'] ?? null;

            return new PiiSanitizationMiddleware(
                piiFields: $customFields,
                strategy: $strategy,
            );
        });

        // Event sampling filter for high-traffic applications
        $this->app->bind(SamplingFilter::class, function (Application $app): SamplingFilter {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $samplingConfig = $config->get('zeroboiler.analytics.sampling', []);
            /** @var array{enabled?: bool, rate?: float, deterministic?: bool} $samplingConfig */

            $rate = (float) ($samplingConfig['rate'] ?? 1.0);
            $deterministic = (bool) ($samplingConfig['deterministic'] ?? true);

            return new SamplingFilter(
                sampleRate: $rate,
                deterministic: $deterministic,
            );
        });

        // Anonymous ID tracker for persistent client-side identifiers
        $this->app->singleton(AnonymousIdTracker::class, function (Application $app): AnonymousIdTracker {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnonymousIdTracker($config);
        });

        $this->app->singleton(EventValidationService::class, function (Application $app): EventValidationService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventValidationService($config);
        });

        $this->app->singleton(SessionTracker::class, function (Application $app): SessionTracker {
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');

            return new SessionTracker($queue, $manager);
        });

        $this->app->singleton(RevenueAnalyticsService::class, function (Application $app): RevenueAnalyticsService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $ecommerce = $config->get('zeroboiler.analytics.ecommerce', []);
            /** @var array{currency?: string} $ecommerce */

            return new RevenueAnalyticsService($manager, $ecommerce['currency'] ?? 'USD');
        });

        $this->app->bind(EventPipeline::class);

        $this->app->singleton(EventSchemaRegistry::class);
        $this->app->bind(AnalyticsMiddlewareStack::class);
        $this->app->bind(EventContextBuilder::class);

        // GDPR consent log service with configurable TTL
        $this->app->singleton(ConsentLogService::class, function (Application $app): ConsentLogService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $logTtl = (int) $config->get('zeroboiler.analytics.consent.log_ttl', 7776000);

            return new ConsentLogService(
                cache: $app->make('cache'),
                ttl: $logTtl,
            );
        });

        // Funnel analytics service
        $this->app->singleton(FunnelAnalyticsService::class, function (Application $app): FunnelAnalyticsService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new FunnelAnalyticsService($manager, $queue, $config);
        });

        // Funnel progress tracker (cache-persisted state)
        $this->app->singleton(FunnelProgressTracker::class, function (Application $app): FunnelProgressTracker {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            $funnelConfig = $config->get('zeroboiler.analytics.funnels', []);
            /** @var array{cache_ttl?: int} $funnelConfig */

            return new FunnelProgressTracker(
                manager: $manager,
                cache: $cache,
                defaultTtl: $funnelConfig['cache_ttl'] ?? 86400,
            );
        });

        // Revenue attribution service
        $this->app->singleton(RevenueAttributionService::class, function (Application $app): RevenueAttributionService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            $ecommerce = $config->get('zeroboiler.analytics.ecommerce', []);
            /** @var array{currency?: string} $ecommerce */
            $queueConfig = $config->get('zeroboiler.analytics.queue', []);
            /** @var array{enabled?: bool} $queueConfig */

            return new RevenueAttributionService(
                $manager,
                $queue,
                $ecommerce['currency'] ?? 'USD',
                (bool) ($queueConfig['enabled'] ?? true),
            );
        });

        // Analytics data bus for conditional routing
        $this->app->singleton(AnalyticsDataBus::class, function (Application $app): AnalyticsDataBus {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            $queueConfig = $config->get('zeroboiler.analytics.queue', []);
            /** @var array{enabled?: bool} $queueConfig */

            return new AnalyticsDataBus(
                $manager,
                $queue,
                (bool) ($queueConfig['enabled'] ?? true),
            );
        });

        // Event replay queue for failed event retry with backoff
        $this->app->singleton(EventReplayQueue::class, function (Application $app): EventReplayQueue {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventReplayQueue($manager, $manager->metrics(), $config);
        });

        // Session analytics service
        $this->app->singleton(SessionAnalyticsService::class, function (Application $app): SessionAnalyticsService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            $queueConfig = $config->get('zeroboiler.analytics.queue', []);
            /** @var array{enabled?: bool} $queueConfig */

            return new SessionAnalyticsService(
                $manager,
                $queue,
                (bool) ($queueConfig['enabled'] ?? true),
            );
        });

        // Event aggregation service
        $this->app->singleton(EventAggregationService::class, function (Application $app): EventAggregationService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var AnalyticsMetrics $metrics */
            $metrics = $manager->metrics();
            /** @var EventReplayQueue $replayQueue */
            $replayQueue = $app->make(EventReplayQueue::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventAggregationService($manager, $metrics, $replayQueue, $config);
        });

        // Cohort analytics service
        $this->app->singleton(CohortAnalyticsService::class, function (Application $app): CohortAnalyticsService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            $queueConfig = $config->get('zeroboiler.analytics.queue', []);
            /** @var array{enabled?: bool} $queueConfig */

            return new CohortAnalyticsService(
                $manager,
                $queue,
                (bool) ($queueConfig['enabled'] ?? true),
            );
        });

        // User journey mapping service
        $this->app->singleton(UserJourneyService::class, function (Application $app): UserJourneyService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            $queueConfig = $config->get('zeroboiler.analytics.queue', []);
            /** @var array{enabled?: bool} $queueConfig */

            return new UserJourneyService(
                $manager,
                $queue,
                (bool) ($queueConfig['enabled'] ?? true),
            );
        });

        // Anomaly detection service
        $this->app->singleton(AnomalyDetectionService::class, function (Application $app): AnomalyDetectionService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var AnalyticsMetrics $metrics */
            $metrics = $manager->metrics();
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);

            return new AnomalyDetectionService($manager, $metrics, $queue);
        });

        // Event stream service for real-time dashboards
        $this->app->singleton(EventStreamService::class, function (Application $app): EventStreamService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var AnalyticsMetrics $metrics */
            $metrics = $manager->metrics();

            $config = $app->make(ConfigRepository::class);
            $streamConfig = $config->get('zeroboiler.analytics.stream', []);
            /** @var array{buffer_size?: int} $streamConfig */
            $bufferSize = (int) ($streamConfig['buffer_size'] ?? 1000);

            return new EventStreamService($manager, $metrics, $bufferSize);
        });

        // Export service
        $this->app->singleton(ExportService::class, function (Application $app): ExportService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var AnalyticsMetrics $metrics */
            $metrics = $manager->metrics();
            /** @var EventStreamService $stream */
            $stream = $app->make(EventStreamService::class);

            return new ExportService($manager, $metrics, $stream);
        });

        // Analytics health service for programmatic health checks
        $this->app->singleton(AnalyticsHealthService::class, function (Application $app): AnalyticsHealthService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var AnalyticsMetrics $metrics */
            $metrics = $manager->metrics();
            /** @var EventReplayQueue $replayQueue */
            $replayQueue = $app->make(EventReplayQueue::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsHealthService($manager, $metrics, $replayQueue, $config);
        });

        // Type-safe analytics config accessor
        $this->app->singleton(AnalyticsConfig::class, function (Application $app): AnalyticsConfig {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsConfig($config);
        });

        // Tracking preference service for per-user GDPR opt-out
        $this->app->singleton(TrackingPreferenceService::class, function (Application $app): TrackingPreferenceService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $trackingConfig = $app->make(ConfigRepository::class)->get('zeroboiler.analytics.tracking_preference', []);
            /** @var array{ttl?: int} $trackingConfig */

            return new TrackingPreferenceService(
                $cache,
                isset($trackingConfig['ttl']) ? (int) $trackingConfig['ttl'] : null,
            );
        });

        // Event deduplication service (cache-based fingerprint tracking)
        $this->app->singleton(EventDeduplicationService::class, function (Application $app): EventDeduplicationService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');

            return new EventDeduplicationService($config, $cache);
        });

        // Device context service (User-Agent parsing)
        $this->app->singleton(DeviceContextService::class);

        // IP anonymization service (GDPR compliance)
        $this->app->singleton(IpAnonymizationService::class, function (Application $app): IpAnonymizationService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new IpAnonymizationService($config);
        });

        // SaaS lifecycle funnel service
        $this->app->singleton(SaasFunnelService::class, function (Application $app): SaasFunnelService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new SaasFunnelService($manager, $queue, $config);
        });

        // Analytics profile aggregation service
        $this->app->singleton(AnalyticsProfileService::class, function (Application $app): AnalyticsProfileService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $profileConfig = $config->get('zeroboiler.analytics.profile', []);
            /** @var array{enabled?: bool, ttl?: int} $profileConfig */

            return new AnalyticsProfileService(
                $manager,
                $cache,
                isset($profileConfig['ttl']) ? (int) $profileConfig['ttl'] : null,
            );
        });

        // Attribution tracking service (first-touch / multi-touch UTM)
        $this->app->singleton(AttributionService::class, function (Application $app): AttributionService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AttributionService($cache, $config);
        });

        // Analytics stats service for dashboard aggregations
        $this->app->singleton(AnalyticsStatsService::class, function (Application $app): AnalyticsStatsService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var AnalyticsMetrics $metrics */
            $metrics = $manager->metrics();
            /** @var EventAggregationService $aggregation */
            $aggregation = $app->make(EventAggregationService::class);
            /** @var EventReplayQueue $replayQueue */
            $replayQueue = $app->make(EventReplayQueue::class);

            return new AnalyticsStatsService($manager, $metrics, $aggregation, $replayQueue);
        });

        // Inbound webhook service for receiving external events
        $this->app->singleton(InboundWebhookService::class, function (Application $app): InboundWebhookService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new InboundWebhookService($manager, $config);
        });

        // GDPR erasure service
        $this->app->singleton(GdprErasureService::class, function (Application $app): GdprErasureService {
            /** @var AnalyticsProfileService $profileService */
            $profileService = $app->make(AnalyticsProfileService::class);
            /** @var AttributionService $attributionService */
            $attributionService = $app->make(AttributionService::class);
            /** @var TrackingPreferenceService $preferenceService */
            $preferenceService = $app->make(TrackingPreferenceService::class);

            return new GdprErasureService($profileService, $attributionService, $preferenceService);
        });

        // SaaS Journey Milestone tracking service
        $this->app->singleton(SaaSJourneyService::class, function (Application $app): SaaSJourneyService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new SaaSJourneyService($manager, $cache, $config);
        });

        // Analytics data anonymization service (GDPR PII masking)
        $this->app->singleton(AnalyticsAnonymizationService::class, function (Application $app): AnalyticsAnonymizationService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsAnonymizationService($config);
        });

        // Advanced PII detection with regex patterns (v2.98.0)
        $this->app->singleton(AdvancedPIIDetector::class, function (Application $app): AdvancedPIIDetector {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $piiConfig = $config->get('zeroboiler.analytics.pii_detection', []);
            /** @var array{enabled?: bool, confidence_threshold?: float, custom_patterns?: array<string, array{pattern: string, confidence?: float, description?: string}>} $piiConfig */

            $threshold = (float) ($piiConfig['confidence_threshold'] ?? 0.5);
            $customPatterns = $piiConfig['custom_patterns'] ?? [];

            return new AdvancedPIIDetector($threshold, $customPatterns);
        });

        // Event orchestration service for multi-step lifecycle pipelines (v2.99.0)
        $this->app->singleton(EventOrchestrationService::class, function (Application $app): EventOrchestrationService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventOrchestrationService($manager, $config);
        });

        // Event context resolver for centralized request → context extraction (v3.0.0)
        $this->app->singleton(EventContextResolver::class, function (Application $app): EventContextResolver {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventContextResolver($config);
        });

        // Analytics insight aggregator for automated event intelligence (v2.99.0)
        $this->app->singleton(AnalyticsInsightAggregator::class, function (Application $app): AnalyticsInsightAggregator {
            /** @var EventStreamService $stream */
            $stream = $app->make(EventStreamService::class);
            /** @var EventAggregationService $aggregation */
            $aggregation = $app->make(EventAggregationService::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $insightsConfig = $config->get('zeroboiler.analytics.insights', []);
            /** @var array<string, mixed> $insightsConfig */

            return new AnalyticsInsightAggregator($stream, $aggregation, $insightsConfig);
        });

        // Session replay service for user journey reconstruction (v2.98.0)
        $this->app->singleton(SessionReplayService::class, function (Application $app): SessionReplayService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $replayConfig = $config->get('zeroboiler.analytics.session_replay', []);
            /** @var array{max_events?: int, ttl?: int} $replayConfig */

            $maxEvents = (int) ($replayConfig['max_events'] ?? 200);
            $ttl = (int) ($replayConfig['ttl'] ?? 3600);

            return new SessionReplayService($cache, $maxEvents, $ttl);
        });

        // Event alert rules service for threshold-based alerting
        $this->app->singleton(EventAlertRulesService::class, function (Application $app): EventAlertRulesService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var AnalyticsMetrics $metrics */
            $metrics = $manager->metrics();
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventAlertRulesService($manager, $metrics, $queue, $cache, $config);
        });

        // Funnel data builder service for API-ready visualization responses
        $this->app->singleton(FunnelDataBuilderService::class, function (Application $app): FunnelDataBuilderService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var AnalyticsMetrics $metrics */
            $metrics = $manager->metrics();
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new FunnelDataBuilderService($manager, $metrics, $cache, $config);
        });

        // Lifecycle event mapper for config-driven event mapping
        $this->app->singleton(LifecycleEventMapper::class, function (Application $app): LifecycleEventMapper {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new LifecycleEventMapper($manager, $config);
        });

        // Event correlation service for pattern detection and predictive analytics
        $this->app->singleton(EventCorrelationService::class, function (Application $app): EventCorrelationService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var AnalyticsMetrics $metrics */
            $metrics = $manager->metrics();
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $correlationConfig = $config->get('zeroboiler.analytics.correlation', []);
            /** @var array{enabled?: bool, cache_ttl?: int, max_pattern_length?: int, max_journeys_per_user?: int} $correlationConfig */

            return new EventCorrelationService(
                $metrics,
                $cache,
                (int) ($correlationConfig['cache_ttl'] ?? 300),
                (int) ($correlationConfig['max_pattern_length'] ?? 5),
                (int) ($correlationConfig['max_journeys_per_user'] ?? 100),
                (bool) ($correlationConfig['cache_enabled'] ?? true),
            );
        });

        // Analytics config validator for boot-time validation
        $this->app->singleton(AnalyticsConfigValidator::class, function (Application $app): AnalyticsConfigValidator {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsConfigValidator($config);
        });

        // Event source tagger for automatic source metadata
        $this->app->singleton(EventSourceTagger::class);

        // Referrer tracking service for conversion attribution
        $this->app->singleton(ReferrerTrackingService::class);

        // Event broadcaster for real-time analytics via Laravel Echo
        $this->app->singleton(EventBroadcasterService::class, function (Application $app): EventBroadcasterService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventBroadcasterService($config);
        });

        // Tenant isolation service for multi-tenant SaaS analytics
        $this->app->singleton(TenantIsolationService::class, function (Application $app): TenantIsolationService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new TenantIsolationService($cache, $config);
        });

        // Data retention policy service for GDPR compliance
        $this->app->singleton(DataRetentionPolicyService::class, function (Application $app): DataRetentionPolicyService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new DataRetentionPolicyService($cache, $config);
        });

        // Analytics gate service for per-plan feature access control
        $this->app->singleton(AnalyticsGateService::class, function (Application $app): AnalyticsGateService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsGateService($cache, $config);
        });

        // Event reporting service for periodic analytics summaries
        $this->app->singleton(EventReportingService::class, function (Application $app): EventReportingService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventReportingService($manager->metrics(), $cache, $config);
        });

        // Dead letter queue service for permanently failed events
        $this->app->singleton(DeadLetterQueueService::class, function (Application $app): DeadLetterQueueService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new DeadLetterQueueService($config);
        });

        // E-commerce format converter (stateless, bind as shared)
        $this->app->singleton(EcommerceFormatConverter::class);

        // Real-time aggregation service for live dashboards
        $this->app->singleton(RealTimeAggregationService::class, function (Application $app): RealTimeAggregationService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new RealTimeAggregationService($manager->metrics(), $cache, $config);
        });

        // A/B test analytics service
        $this->app->singleton(ABTestAnalyticsService::class, function (Application $app): ABTestAnalyticsService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new ABTestAnalyticsService($manager, $cache, $config);
        });

        // Analytics snapshot service for trend comparisons
        $this->app->singleton(AnalyticsSnapshotService::class, function (Application $app): AnalyticsSnapshotService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsSnapshotService($manager->metrics(), $cache, $config);
        });

        // SaaS KPI tracker for business metrics
        $this->app->singleton(SaasKpiTracker::class, function (Application $app): SaasKpiTracker {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new SaasKpiTracker($manager, $cache, $config);
        });

        // UTM aggregation service for marketing attribution
        $this->app->singleton(UtmAggregationService::class, function (Application $app): UtmAggregationService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new UtmAggregationService($cache, $config);
        });

        // Geolocation enricher (pipeline stage, stateful with IP cache)
        $this->app->bind(GeolocationEnricher::class, function (Application $app): GeolocationEnricher {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $geoConfig = $config->get('zeroboiler.analytics.geolocation', []);
            /** @var array{enabled?: bool, strategy?: string, country_header?: string, region_header?: string, city_header?: string} $geoConfig */

            return new GeolocationEnricher(
                strategy: (string) ($geoConfig['strategy'] ?? 'header'),
                countryHeader: (string) ($geoConfig['country_header'] ?? 'CF-IPCountry'),
                regionHeader: (string) ($geoConfig['region_header'] ?? ''),
                cityHeader: (string) ($geoConfig['city_header'] ?? ''),
                enabled: (bool) ($geoConfig['enabled'] ?? true),
            );
        });

        // Event forwarding service for external platforms (Segment, Mixpanel, Amplitude, custom)
        $this->app->singleton(EventForwardingService::class, function (Application $app): EventForwardingService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventForwardingService($cache, $config);
        });

        // Performance budget service for analytics payload and rate management
        $this->app->singleton(PerformanceBudgetService::class, function (Application $app): PerformanceBudgetService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new PerformanceBudgetService($config);
        });

        // UTM attribution service with first-touch, last-touch, and multi-touch models
        $this->app->singleton(UTMAttributionService::class, function (Application $app): UTMAttributionService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new UTMAttributionService($cache, $config);
        });

        // Event router for provider-specific event routing
        $this->app->singleton(AnalyticsEventRouter::class, function (Application $app): AnalyticsEventRouter {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsEventRouter($manager, $config);
        });

        // Event alias resolver — normalizes event name variations to canonical names
        $this->app->singleton(EventAliasResolver::class, function (Application $app): EventAliasResolver {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventAliasResolver($config);
        });

        // Event cache service — L1 memory + L2 Laravel cache for high-performance lookups
        $this->app->singleton(EventCacheService::class, function (Application $app): EventCacheService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventCacheService($cache, $config);
        });

        // Event buckets service — time-binned event aggregation for dashboards
        $this->app->singleton(EventBucketsService::class, function (Application $app): EventBucketsService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventBucketsService($cache, $config);
        });

        // SaaS health score service — composite health scoring from KPIs
        $this->app->singleton(SaaSHealthScoreService::class, function (Application $app): SaaSHealthScoreService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            /** @var SaasKpiTracker $kpiTracker */
            $kpiTracker = $app->make(SaasKpiTracker::class);

            return new SaaSHealthScoreService($cache, $config, $kpiTracker);
        });

        // Analytics health check service — comprehensive diagnostic
        $this->app->singleton(AnalyticsHealthCheckService::class, function (Application $app): AnalyticsHealthCheckService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsHealthCheckService($config);
        });

        // Event envelope service — context-rich event building from HTTP requests
        $this->app->singleton(EventEnvelopeService::class, function (Application $app): EventEnvelopeService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventEnvelopeService(
                $cache,
                $config,
                $app->make(DeviceContextService::class),
                $app->make(GeolocationEnricher::class),
                $app->make(ReferrerTrackingService::class),
                $app->make(AttributionService::class),
                $app->make(TrackingPreferenceService::class),
                $app->make(ConsentLogService::class),
            );
        });

        // Consent-aware pipeline filter — granular purpose-based event filtering
        $this->app->singleton(ConsentAwareFilter::class, function (Application $app): ConsentAwareFilter {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $consentPurposeConfig = $config->get('zeroboiler.analytics.consent_purposes', []);
            /** @var array{enabled?: bool, strict?: bool} $consentPurposeConfig */

            return new ConsentAwareFilter(
                enabled: (bool) ($consentPurposeConfig['enabled'] ?? false),
                consentLogService: $app->make(ConsentLogService::class),
            );
        });

        // Provider telemetry service — self-monitoring connectivity probes
        $this->app->singleton(AnalyticsTelemetryService::class, function (Application $app): AnalyticsTelemetryService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsTelemetryService($manager, $cache, $config);
        });

        // Campaign ROI analytics service (v2.62.0)
        $this->app->singleton(CampaignRoiService::class, function (Application $app): CampaignRoiService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new CampaignRoiService($cache, $config);
        });

        // Data minimization service (v2.62.0)
        $this->app->bind(DataMinimizationService::class, function (Application $app): DataMinimizationService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new DataMinimizationService($config);
        });

        // Event priority gate service (v2.66.0)
        $this->app->singleton(EventPriorityGate::class, function (Application $app): EventPriorityGate {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventPriorityGate($cache, $config);
        });

        // SaaS conversion analytics service (v2.66.0)
        $this->app->singleton(SaaSConversionService::class, function (Application $app): SaaSConversionService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new SaaSConversionService($manager, $cache, $config);
        });

        // Data warehouse export service (v2.67.0)
        $this->app->singleton(DataWarehouseExportService::class, function (Application $app): DataWarehouseExportService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new DataWarehouseExportService($config);
        });

        // Event property schema validation service (v2.67.0)
        $this->app->singleton(EventPropertySchema::class, function (Application $app): EventPropertySchema {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $schemaConfig = $config->get('zeroboiler.analytics.property_schema', []);
            /** @var array{enabled?: bool, register_builtins?: bool} $schemaConfig */

            $schema = new EventPropertySchema();

            if ((bool) ($schemaConfig['register_builtins'] ?? true)) {
                $schema->registerBuiltInSchemas();
            }

            return $schema;
        });

        // Analytics dashboard data provider (v2.67.0)
        $this->app->singleton(AnalyticsDashboardDataProvider::class, function (Application $app): AnalyticsDashboardDataProvider {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');

            return new AnalyticsDashboardDataProvider($manager);
        });

        // Provider circuit breaker (v2.70.0)
        $this->app->singleton(ProviderCircuitBreaker::class, function (Application $app): ProviderCircuitBreaker {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new ProviderCircuitBreaker($config);
        });

        // Event compliance service (v2.70.0)
        $this->app->singleton(EventComplianceService::class, function (Application $app): EventComplianceService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventComplianceService($config);
        });

        // Analytics recovery service (v2.70.0)
        $this->app->singleton(AnalyticsRecoveryService::class, function (Application $app): AnalyticsRecoveryService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            /** @var DeadLetterQueueService|null $dlq */
            $dlq = $app->make(DeadLetterQueueService::class);

            return new AnalyticsRecoveryService($manager, $config, $dlq);
        });

        // Analytics Sandbox for non-production environments (v2.71.0)
        $this->app->singleton(AnalyticsSandboxService::class, function (Application $app): AnalyticsSandboxService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsSandboxService($app->make('cache'), $config);
        });

        // Per-Provider Rate Limiting (v2.71.0)
        $this->app->singleton(ProviderRateLimitService::class, function (Application $app): ProviderRateLimitService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new ProviderRateLimitService($app->make('cache'), $config);
        });

        // Event Schema Versioning (v2.71.0)
        $this->app->singleton(EventSchemaVersioningService::class, function (Application $app): EventSchemaVersioningService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventSchemaVersioningService($config, $app->make(EventSchemaRegistry::class));
        });

        // SaaS Starter Readiness Validator (v2.71.0)
        $this->app->singleton(AnalyticsReadinessService::class, function (Application $app): AnalyticsReadinessService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsReadinessService($app->make('cache'), $config);
        });

        // Analytics Insights Service (v2.82.0)
        $this->app->singleton(AnalyticsInsightsService::class, function (Application $app): AnalyticsInsightsService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsInsightsService($config);
        });

        // Funnel Velocity Service (v2.82.0)
        $this->app->singleton(FunnelVelocityService::class, function (Application $app): FunnelVelocityService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new FunnelVelocityService($config);
        });

        // Event Impact Service (v2.82.0)
        $this->app->singleton(EventImpactService::class, function (Application $app): EventImpactService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventImpactService($config);
        });

        // SaaS Metrics Benchmark Service (v2.87.0)
        $this->app->singleton(SaaSMetricsBenchmarkService::class, function (Application $app): SaaSMetricsBenchmarkService {
            return new SaaSMetricsBenchmarkService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Privacy Sandbox Service (v2.93.0)
        $this->app->singleton(PrivacySandboxService::class, function (Application $app): PrivacySandboxService {
            return new PrivacySandboxService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Cart State Manager (v2.93.0)
        $this->app->singleton(CartStateManager::class, function (Application $app): CartStateManager {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');

            return new CartStateManager(
                $manager,
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Event Affinity Service (v2.93.0)
        $this->app->singleton(EventAffinityService::class, function (Application $app): EventAffinityService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');

            return new EventAffinityService(
                $app->make('cache'),
                $manager->metrics(),
                $app->make(ConfigRepository::class),
            );
        });

        // Funnel Progress Tracker (v2.93.0)
        $this->app->singleton(FunnelProgressTracker::class, function (Application $app): FunnelProgressTracker {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');

            return new FunnelProgressTracker(
                $manager,
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Onboarding Completion Service (v2.93.0)
        $this->app->singleton(OnboardingCompletionService::class, function (Application $app): OnboardingCompletionService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);

            return new OnboardingCompletionService(
                $manager,
                $queue,
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Schema-Driven Event Builder (v2.94.0)
        $this->app->singleton(SchemaDrivenEventBuilder::class, function (Application $app): SchemaDrivenEventBuilder {
            /** @var EventPropertySchema $propertySchema */
            $propertySchema = $app->make(EventPropertySchema::class);
            /** @var EventSchemaRegistry $schemaRegistry */
            $schemaRegistry = $app->make(EventSchemaRegistry::class);

            return new SchemaDrivenEventBuilder($propertySchema, $schemaRegistry, false);
        });

        // Schema Diff Reporter (v2.94.0)
        $this->app->singleton(SchemaDiffReporter::class);

        // SSE Controller (v2.95.0)
        $this->app->singleton(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsSSEController::class, function (Application $app): \ZeroBoiler\Analytics\Http\Controllers\AnalyticsSSEController {
            /** @var EventStreamService $streamService */
            $streamService = $app->make(EventStreamService::class);

            return new \ZeroBoiler\Analytics\Http\Controllers\AnalyticsSSEController($streamService);
        });

        // Event Window Aggregator (v2.95.0)
        $this->app->singleton(EventWindowAggregator::class, function (Application $app): EventWindowAggregator {
            return new EventWindowAggregator(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Feature Adoption Tracker (v2.95.0)
        $this->app->singleton(FeatureAdoptionTracker::class, function (Application $app): FeatureAdoptionTracker {
            return new FeatureAdoptionTracker(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Analytics API Guard (v2.95.0)
        $this->app->singleton(AnalyticsApiGuard::class, function (Application $app): AnalyticsApiGuard {
            return new AnalyticsApiGuard(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    #[\Override]
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes(
                [__DIR__.'/../config/zeroboiler.php' => $this->app->configPath('zeroboiler.php')],
                'zeroboiler-analytics-config',
            );

            $this->commands([
                AnalyticsTestCommand::class,
                AnalyticsOverviewCommand::class,
                AnalyticsExportCommand::class,
                RevenueReportCommand::class,
                AnalyticsHealthCommand::class,
                AnalyticsDashboardCommand::class,
                AnalyticsScheduledReportCommand::class,
                AnalyticsReadinessCommand::class,
                AnalyticsSchemaExportCommand::class,
            ]);
        }

        $this->registerBladeDirectives();
        $this->registerMiddleware();
        $this->registerAutoTracking();
        $this->registerRoutes();
    }

    /**
     * Register Blade directives for analytics.
     */
    private function registerBladeDirectives(): void
    {
        $this->app->afterResolving('blade.compiler', function (): void {
            AnalyticsDirectives::register();
        });
    }

    /**
     * Register the analytics middleware as a scoped alias.
     */
    private function registerMiddleware(): void
    {
        $router = $this->app->make('router');

        if (is_object($router) && method_exists($router, 'aliasMiddleware')) {
            $router->aliasMiddleware(
                'analytics.scripts',
                InjectAnalyticsScripts::class,
            );
            $router->aliasMiddleware(
                'analytics.inertia',
                HandleInertiaAnalytics::class,
            );
            $router->aliasMiddleware(
                'analytics.referrer',
                \ZeroBoiler\Analytics\Middleware\AnalyticsReferrerMiddleware::class,
            );
        }
    }

    /**
     * Register server-side auto-tracking of Laravel events.
     */
    private function registerAutoTracking(): void
    {
        $tracker = $this->app->make(ServerSideTracker::class);
        /** @var \Illuminate\Contracts\Events\Dispatcher $dispatcher */
        $dispatcher = $this->app->make('events');

        $tracker->register($dispatcher);

        // Register custom application events from config
        $config = $this->app->make(ConfigRepository::class);
        $autoTrack = $config->get('zeroboiler.analytics.auto_track', []);
        /** @var array{events?: array<string, bool>, models?: array<class-string, array<int, string>>} $autoTrack */
        $customEvents = $autoTrack['events'] ?? [];

        foreach (array_keys($customEvents) as $eventName) {
            if (str_starts_with($eventName, 'auth.')) {
                continue; // Laravel framework events, already registered
            }

            $tracker->listen($eventName, $dispatcher);
        }

        // Register Eloquent model listeners
        /** @var array<class-string, array<int, string>> $modelEvents */
        $modelEvents = $autoTrack['models'] ?? [];
        if (! empty($modelEvents)) {
            $tracker->registerModelListeners($modelEvents);
        }
    }

    /**
     * Register analytics API routes.
     */
    private function registerRoutes(): void
    {
        if ($this->app instanceof \Illuminate\Foundation\Application && $this->app->routesAreCached()) {
            return;
        }

        $controller = \ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class;

        // Health + Catalog + Stream + Export + Stats endpoints — no auth required
        Route::prefix('api')
            ->middleware(['throttle:120,1'])
            ->group(function () use ($controller): void {
                Route::get('analytics/health', [$controller, 'health']);
                Route::get('analytics/catalog', [$controller, 'catalog']);
                Route::get('analytics/stream', [$controller, 'stream']);
                Route::get('analytics/stream/stats', [$controller, 'streamStats']);
                Route::get('analytics/export', [$controller, 'export']);
                Route::get('analytics/stats', [$controller, 'stats']);
                Route::post('analytics/webhook/inbound', [$controller, 'inboundWebhook']);
                Route::post('analytics/alerts/evaluate', [$controller, 'evaluateAlerts']);
                Route::get('analytics/alerts', [$controller, 'alerts']);
                Route::get('analytics/funnels', [$controller, 'funnelData']);
                Route::post('analytics/funnels/compare', [$controller, 'funnelCompare']);
                Route::get('analytics/funnels/drop-off', [$controller, 'funnelDropOff']);
                Route::get('analytics/funnels/chart', [$controller, 'funnelChart']);

                // Lifecycle mapping endpoint
                Route::get('analytics/lifecycle', [$controller, 'lifecycle']);

                // Event correlation endpoints
                Route::get('analytics/correlation/patterns', [$controller, 'correlationPatterns']);
                Route::get('analytics/correlation/transitions', [$controller, 'correlationTransitions']);
                Route::get('analytics/correlation/predict', [$controller, 'correlationPredict']);
                Route::get('analytics/correlation/summary', [$controller, 'correlationSummary']);

                // Config validation, device context, referrer endpoints
                Route::get('analytics/config/validate', [$controller, 'validateConfig']);
                Route::get('analytics/device', [$controller, 'deviceContext']);
                Route::get('analytics/referrer', [$controller, 'referrerInfo']);

                // Broadcast, tenant, retention, gate endpoints
                Route::get('analytics/broadcast', [$controller, 'broadcastInfo']);
                Route::get('analytics/tenant', [$controller, 'tenantInfo']);
                Route::get('analytics/retention', [$controller, 'retentionInfo']);
                Route::get('analytics/gate', [$controller, 'gateInfo']);
                Route::get('analytics/gate/definitions', [$controller, 'gateDefinitions']);

                // Reporting endpoints
                Route::get('analytics/report', [$controller, 'report']);
                Route::get('analytics/report/summary', [$controller, 'reportSummary']);
                Route::get('analytics/report/top-events', [$controller, 'reportTopEvents']);
                Route::get('analytics/report/trending', [$controller, 'reportTrending']);
                Route::get('analytics/report/provider-stats', [$controller, 'reportProviderStats']);

                // Dead letter queue endpoints
                Route::get('analytics/dlq', [$controller, 'dlqList']);
                Route::delete('analytics/dlq', [$controller, 'dlqClear']);
                Route::delete('analytics/dlq/{offset}', [$controller, 'dlqRemove']);
                Route::get('analytics/dlq/summary', [$controller, 'dlqSummary']);
                Route::post('analytics/dlq/replay', [$controller, 'dlqReplayAll']);
                Route::post('analytics/dlq/replay/{offset}', [$controller, 'dlqReplaySingle']);

                // Real-time aggregation endpoints
                Route::get('analytics/realtime', [$controller, 'realtimeSnapshot']);
                Route::get('analytics/realtime/top-events', [$controller, 'realtimeTopEvents']);

                // A/B test analytics endpoints
                Route::get('analytics/ab-tests/{experimentId}', [$controller, 'abTestResults']);
                Route::post('analytics/ab-tests/{experimentId}/exposure', [$controller, 'abTestRecordExposure']);
                Route::post('analytics/ab-tests/{experimentId}/conversion', [$controller, 'abTestRecordConversion']);
                Route::delete('analytics/ab-tests/{experimentId}', [$controller, 'abTestDelete']);

                // Snapshot endpoints
                Route::get('analytics/snapshots/daily', [$controller, 'dailySnapshot']);
                Route::get('analytics/snapshots/hourly', [$controller, 'hourlySnapshot']);
                Route::get('analytics/snapshots/comparison', [$controller, 'dailyComparison']);

                // SaaS KPI endpoints
                Route::get('analytics/kpi', [$controller, 'saasKpiSummary']);
                Route::get('analytics/kpi/mrr-history', [$controller, 'saasKpiMrrHistory']);

                // UTM aggregation endpoints
                Route::get('analytics/utm/sources', [$controller, 'utmTopSources']);
                Route::get('analytics/utm/campaigns', [$controller, 'utmTopCampaigns']);
                Route::get('analytics/utm/breakdown', [$controller, 'utmBreakdown']);

                // Event forwarding endpoints
                Route::get('analytics/forwarding', [$controller, 'forwardingInfo']);
                Route::get('analytics/forwarding/stats', [$controller, 'forwardingStats']);
                Route::post('analytics/forwarding/test/{forwarder}', [$controller, 'forwardingTest']);

                // Performance budget endpoints
                Route::get('analytics/performance-budget', [$controller, 'performanceBudgetInfo']);
                Route::post('analytics/performance-budget/validate', [$controller, 'performanceBudgetValidate']);

                // UTM attribution endpoints
                Route::get('analytics/attribution/{identifier}', [$controller, 'attributionInfo']);
                Route::get('analytics/attribution/{identifier}/touchpoints', [$controller, 'attributionTouchpoints']);
                Route::get('analytics/attribution/{identifier}/first-touch', [$controller, 'attributionFirstTouch']);
                Route::get('analytics/attribution/{identifier}/last-touch', [$controller, 'attributionLastTouch']);

                // Consent purpose endpoints
                Route::get('analytics/consent/purposes', [$controller, 'consentPurposes']);
                Route::get('analytics/consent/envelope-info', [$controller, 'envelopeInfo']);

                // Event Schema Validation API endpoints
                Route::get('analytics/schemas', [$controller, 'schemaList']);
                Route::get('analytics/schemas/summary', [$controller, 'schemaSummary']);
                Route::post('analytics/schemas/validate', [$controller, 'schemaValidate']);
                Route::get('analytics/schemas/{eventName}', [$controller, 'schemaDetail']);

                // Event Deconfliction (v2.69.0)
                Route::get('analytics/deconfliction', [$controller, 'deconfliction']);

                // Event Schema Inference (v2.69.0)
                Route::get('analytics/schemas/infer', [$controller, 'schemaInfer']);

                // Click Heatmap data endpoints (v2.69.0)
                Route::post('analytics/heatmap/click', [$controller, 'heatmapClick']);
                Route::get('analytics/heatmap/data', [$controller, 'heatmapData']);
                Route::get('analytics/heatmap/urls', [$controller, 'heatmapUrls']);

                // Rate Limit Dashboard (v2.69.0)
                Route::get('analytics/rate-limits', [$controller, 'rateLimitDashboard']);
                Route::get('analytics/rate-limits/{clientId}', [$controller, 'rateLimitClientStatus']);

                // Circuit Breaker Dashboard (v2.70.0)
                Route::get('analytics/circuit-breaker', [$controller, 'circuitBreakerDashboard']);
                Route::get('analytics/circuit-breaker/summary', [$controller, 'circuitBreakerSummary']);

                // Compliance Audit Report (v2.70.0)
                Route::get('analytics/compliance', [$controller, 'complianceReport']);
                Route::get('analytics/compliance/score', [$controller, 'complianceScore']);
                Route::post('analytics/compliance/invalidate', [$controller, 'complianceInvalidateCache']);

                // Recovery Service (v2.70.0)
                Route::get('analytics/recovery/budget', [$controller, 'recoveryBudget']);
                Route::get('analytics/recovery/health', [$controller, 'recoveryHealth']);
                Route::get('analytics/recovery/history', [$controller, 'recoveryHistory']);

                // Analytics Sandbox (v2.71.0)
                Route::get('analytics/sandbox', [$controller, 'sandboxStatus']);
                Route::get('analytics/sandbox/events', [$controller, 'sandboxEvents']);
                Route::get('analytics/sandbox/replay-log', [$controller, 'sandboxReplayLog']);

                // Per-Provider Rate Limits (v2.71.0)
                Route::get('analytics/provider-rate-limits', [$controller, 'providerRateLimits']);

                // Schema Versioning (v2.71.0)
                Route::get('analytics/schema-versions', [$controller, 'schemaVersions']);

                // SaaS Starter Readiness (v2.71.0)
                Route::get('analytics/readiness', [$controller, 'readiness']);

                // SaaS Metrics Benchmarks (v2.87.0)
                Route::get('analytics/benchmarks', [$controller, 'benchmarksList']);
                Route::get('analytics/benchmarks/{metric}', [$controller, 'benchmarksGet']);
                Route::get('analytics/benchmarks/compare', [$controller, 'benchmarksCompare']);
                Route::get('analytics/benchmarks/report-card', [$controller, 'benchmarksReportCard']);
                Route::get('analytics/benchmarks/quick-start', [$controller, 'benchmarksQuickStart']);

                // SSE Streaming (v2.95.0)
                $sseController = \ZeroBoiler\Analytics\Http\Controllers\AnalyticsSSEController::class;
                Route::get('analytics/sse', [$sseController, 'stream']);
                Route::get('analytics/sse/info', [$sseController, 'info']);
                Route::get('analytics/sse/health', [$sseController, 'health']);
            });

        // Authenticated endpoints
        Route::prefix('api')
            ->middleware(['auth:sanctum', 'throttle:60,1'])
            ->group(function () use ($controller): void {
                Route::post('analytics/events', [$controller, 'track']);
                Route::post('analytics/batch', [$controller, 'batch']);
                Route::post('analytics/identify', [$controller, 'identify']);
                Route::post('analytics/pageview', [$controller, 'pageview']);
                Route::post('analytics/consent', [$controller, 'updateConsent']);
                Route::post('analytics/opt-out', [$controller, 'optOut']);
                Route::post('analytics/opt-in', [$controller, 'optIn']);
                Route::get('analytics/preference', [$controller, 'preference']);
                Route::get('analytics/profile', [$controller, 'profile']);
                Route::delete('analytics/data', [$controller, 'eraseData']);
                Route::get('analytics/gdpr/export', [$controller, 'gdprExport']);
                Route::post('analytics/tenant/config', [$controller, 'updateTenantConfig']);
                Route::post('analytics/attribution/record', [$controller, 'attributionRecord']);
                Route::delete('analytics/attribution/{identifier}', [$controller, 'attributionClear']);
                Route::post('analytics/forwarding/reset-stats', [$controller, 'forwardingResetStats']);
                Route::get('analytics/consent/history', [$controller, 'consentHistory']);

                // SaaS Journey Milestone tracking
                Route::post('analytics/journeys/{journey}/milestone', [$controller, 'journeyHitMilestone']);
                Route::get('analytics/journeys/{journey}/progress', [$controller, 'journeyGetProgress']);
                Route::get('analytics/journeys', [$controller, 'journeyListAll']);
                Route::delete('analytics/journeys/{journey}', [$controller, 'journeyResetProgress']);

                // Provider Telemetry (v2.62.0)
                Route::get('analytics/telemetry', [$controller, 'telemetry']);
                Route::post('analytics/telemetry/probe', [$controller, 'telemetryProbe']);

                // Campaign ROI (v2.62.0)
                Route::get('analytics/campaigns/roi', [$controller, 'campaignRoiSummary']);
                Route::get('analytics/campaigns/{campaign}/roi', [$controller, 'campaignRoi']);
                Route::post('analytics/campaigns/spend', [$controller, 'campaignRegisterSpend']);

                // Data Minimization / Privacy (v2.62.0)
                Route::get('analytics/privacy/minimization', [$controller, 'dataMinimizationStatus']);
                Route::post('analytics/privacy/minimization/preview', [$controller, 'dataMinimizationPreview']);

                // Heatmap clear + Rate Limit reset (v2.69.0)
                Route::delete('analytics/heatmap/data', [$controller, 'heatmapClear']);
                Route::delete('analytics/rate-limits/{clientId}', [$controller, 'rateLimitResetClient']);

                // Circuit Breaker control (v2.70.0)
                Route::post('analytics/circuit-breaker/{provider}/reset', [$controller, 'circuitBreakerReset']);
                Route::post('analytics/circuit-breaker/{provider}/trip', [$controller, 'circuitBreakerTrip']);

                // Recovery batch (v2.70.0)
                Route::post('analytics/recovery/batch', [$controller, 'recoveryBatch']);

                // Sandbox clear (v2.71.0)
                Route::delete('analytics/sandbox/events', [$controller, 'sandboxClear']);

                // Provider Rate Limits reset (v2.71.0)
                Route::post('analytics/provider-rate-limits/reset', [$controller, 'providerRateLimitsReset']);
            });
    }
}
