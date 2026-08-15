<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ZeroBoiler\Analytics\Blade\Directives\AnalyticsDirectives;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsCoverageCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsDebugCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsPipelineValidateCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsRevenueAttributionCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsTransformCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsConsoleCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsRevenueWaterfallCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsEventHealthCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsDeployGateCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsSnapshotCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsGovernanceCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsCommandCenterCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsExportCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsPrivacyInventoryCommand;
use ZeroBoiler\Analytics\Console\Commands\RevenueReportCommand;
use ZeroBoiler\Analytics\Http\Middleware\InjectAnalyticsScripts;
use ZeroBoiler\Analytics\Http\Middleware\AutoPageViewMiddleware;
use ZeroBoiler\Analytics\Http\Middleware\VerifySdkToken;
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
use ZeroBoiler\Analytics\Schema\EventSchemaRegistryExtended;
use ZeroBoiler\Analytics\Tracking\AnonymousIdTracker;
use ZeroBoiler\Analytics\Services\EventValidationService;
use ZeroBoiler\Analytics\Services\FunnelProgressTracker;
use ZeroBoiler\Analytics\Services\FunnelAnalyticsService;
use ZeroBoiler\Analytics\Services\OnboardingCompletionService;
use ZeroBoiler\Analytics\Services\GoogleTagManagerService;
use ZeroBoiler\Analytics\Services\MetaPixelService;
use ZeroBoiler\Analytics\Services\RevenueAnalyticsService;
use ZeroBoiler\Analytics\Services\RevenueAttributionService;
use ZeroBoiler\Analytics\Services\RevenueAttributionDashboardService;
use ZeroBoiler\Analytics\Services\CustomerSuccessAnalyticsService;
use ZeroBoiler\Analytics\Services\FeatureGatingAnalyticsService;
use ZeroBoiler\Analytics\Services\AnalyticsPipelineProfilerService;
use ZeroBoiler\Analytics\Services\AnalyticsEventReliabilityService;
use ZeroBoiler\Analytics\Services\AnalyticsEventBuffer;
use ZeroBoiler\Analytics\Services\SaaSAnalyticsService;
use ZeroBoiler\Analytics\Tracking\ServerSideTracker;
use ZeroBoiler\Analytics\Tracking\SessionTracker;
use ZeroBoiler\Analytics\Tracking\UserIdentityTracker;
use ZeroBoiler\Analytics\Trackers\PlausibleTracker;
use ZeroBoiler\Analytics\Trackers\PosthogTracker;
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
use ZeroBoiler\Analytics\Console\Commands\AnalyticsReadinessGateCommand;
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
use ZeroBoiler\Analytics\Services\EventQueryEngine;
use ZeroBoiler\Analytics\Services\AnalyticsQueryBuilder;
use ZeroBoiler\Analytics\Services\EventAlertRulesService;
use ZeroBoiler\Analytics\Services\EventCorrelationService;
use ZeroBoiler\Analytics\Services\FunnelDataBuilderService;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;
use ZeroBoiler\Analytics\Tracking\LifecycleEventSubscriber;
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
use ZeroBoiler\Analytics\Services\CrossPlatformAttributionService;
use ZeroBoiler\Analytics\Services\AnalyticsDailyHealthReportService;
use ZeroBoiler\Analytics\Services\AnalyticsReplayAuditor;
use ZeroBoiler\Analytics\Macros\AnalyticsMacroRegistry;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsMacrosCommand;
use ZeroBoiler\Analytics\Services\UTMAttributionService;
use ZeroBoiler\Analytics\Pipeline\GeolocationEnricher;
use ZeroBoiler\Analytics\Services\AnalyticsEventRouter;
use ZeroBoiler\Analytics\Services\EventAliasResolver;
use ZeroBoiler\Analytics\Services\EventCacheService;
use ZeroBoiler\Analytics\Services\EventBucketsService;
use ZeroBoiler\Analytics\Services\SaaSHealthScoreService;
use ZeroBoiler\Analytics\Services\SaaSCoverageReportService;
use ZeroBoiler\Analytics\Services\WebVitalsAggregatorService;
use ZeroBoiler\Analytics\Services\EventInspectorService;
use ZeroBoiler\Analytics\Services\AnalyticsHealthCheckService;
use ZeroBoiler\Analytics\Services\EventEnvelopeService;
use ZeroBoiler\Analytics\Services\CampaignRoiService;
use ZeroBoiler\Analytics\Services\DataMinimizationService;
use ZeroBoiler\Analytics\Pipeline\ConsentAwareFilter;
use ZeroBoiler\Analytics\Services\AnalyticsTelemetryService;
use ZeroBoiler\Analytics\Services\EventPriorityGate;
use ZeroBoiler\Analytics\Services\SaaSConversionService;
use ZeroBoiler\Analytics\Services\ProviderCircuitBreaker;
use ZeroBoiler\Analytics\Services\ProviderFailoverService;
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
use ZeroBoiler\Analytics\Services\CheckoutFlowTracker;
use ZeroBoiler\Analytics\Services\SaaSKpiCalculatorService;
use ZeroBoiler\Analytics\Services\ProviderEventValidator;
use ZeroBoiler\Analytics\Services\EventAffinityService;
use ZeroBoiler\Analytics\Services\SchemaDrivenEventBuilder;
use ZeroBoiler\Analytics\Services\SchemaDiffReporter;
use ZeroBoiler\Analytics\Services\AdvancedPIIDetector;
use ZeroBoiler\Analytics\Services\SessionReplayService;
use ZeroBoiler\Analytics\Support\EventBuilder;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsSchemaExportCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsSchemaCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsBehavioralCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsArchetypeDriftCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsReplayCommand;
use ZeroBoiler\Analytics\Services\OnboardingWizardService;
use ZeroBoiler\Analytics\Services\GrowthMetricsService;
use ZeroBoiler\Analytics\Services\WeeklyDigestService;
use ZeroBoiler\Analytics\Services\EventOrchestrationService;
use ZeroBoiler\Analytics\Services\AnalyticsInsightAggregator;
use ZeroBoiler\Analytics\Services\EventContextResolver;
use ZeroBoiler\Analytics\Services\EventRulesEngine;
use ZeroBoiler\Analytics\Services\UserPropertiesStore;
use ZeroBoiler\Analytics\Services\RetentionCalculator;
use ZeroBoiler\Analytics\Services\BehavioralCohortBuilder;
use ZeroBoiler\Analytics\Services\IdentityResolutionService;
use ZeroBoiler\Analytics\Services\IdentityGraphService;
use ZeroBoiler\Analytics\Services\DeviceFingerprintService;
use ZeroBoiler\Analytics\Services\SessionFingerprintService;
use ZeroBoiler\Analytics\Services\EventContextSnapshotService;
use ZeroBoiler\Analytics\Services\UserJourneyReconstructionService;
use ZeroBoiler\Analytics\Services\EventDebounceService;
use ZeroBoiler\Analytics\Bus\AnalyticsEventDispatcher;
use ZeroBoiler\Analytics\Services\EventEnrichmentService;
use ZeroBoiler\Analytics\Services\SubscriptionLifecycleService;
use ZeroBoiler\Analytics\Services\RevenueIntelligenceService;
use ZeroBoiler\Analytics\Services\EventTraceService;
use ZeroBoiler\Analytics\Services\EventArchetypeService;
use ZeroBoiler\Analytics\Services\ConfigDriftDetectionService;
use ZeroBoiler\Analytics\Services\EventAnonymizationAggregationService;
use ZeroBoiler\Analytics\Services\EventArchiveService;
use ZeroBoiler\Analytics\Services\EventGovernanceService;
use ZeroBoiler\Analytics\Services\EventCostTracker;
use ZeroBoiler\Analytics\Services\NotificationWebhookService;
use ZeroBoiler\Analytics\Services\AnalyticsConfigAuditService;
use ZeroBoiler\Analytics\Services\EventCatalogValidator;
use ZeroBoiler\Analytics\Events\EventPluginRegistry;
use ZeroBoiler\Analytics\Services\AnalyticsDataResidencyService;
use ZeroBoiler\Analytics\Services\EventConsistencyValidatorService;
use ZeroBoiler\Analytics\Services\EventTemplateEngine;
use ZeroBoiler\Analytics\Enrichment\EventEnrichmentPlugin;
use ZeroBoiler\Analytics\Enrichment\EventEnrichmentRegistry;
use ZeroBoiler\Analytics\Enrichment\EventEnrichmentOrchestrator;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsIntegrityCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsReportCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsCostReportCommand;
use ZeroBoiler\Analytics\Services\AnalyticsAIService;
use ZeroBoiler\Analytics\Services\EventExperimentTracker;
use ZeroBoiler\Analytics\Services\SaaSQuickStartService;
use ZeroBoiler\Analytics\Services\EventCostEstimator;
use ZeroBoiler\Analytics\Services\SaaSOnboardingFunnelTracker;
use ZeroBoiler\Analytics\Services\SaaSReadinessAssessment;
use ZeroBoiler\Analytics\Services\AnalyticsDataService;
use ZeroBoiler\Analytics\Services\AnonymousEventAggregationService;
use ZeroBoiler\Analytics\Services\FunnelLeakDetectionService;
use ZeroBoiler\Analytics\Services\FirstPartyDataService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsFunnelLeakCommand;
use ZeroBoiler\Analytics\Services\EventTaxonomyService;
use ZeroBoiler\Analytics\Tracking\TenantAnalyticsContext;
use ZeroBoiler\Analytics\Services\EventSchemaJsonGenerator;
use ZeroBoiler\Analytics\Bus\AnalyticsEventBus;
use ZeroBoiler\Analytics\Services\RegionalConsentService;
use ZeroBoiler\Analytics\Services\PLGScoringService;
use ZeroBoiler\Analytics\Services\RevenueWaterfallService;
use ZeroBoiler\Analytics\Services\FeatureFlagAnalyticsService;
use ZeroBoiler\Analytics\Services\SaaSGrowthMetricsService;
use ZeroBoiler\Analytics\Services\EventHealthScoringEngine;
use ZeroBoiler\Analytics\Services\RevenueForecastService;
use ZeroBoiler\Analytics\Services\RevenueSignalDetector;
use ZeroBoiler\Analytics\Services\ConversionPathDiscoveryService;
use ZeroBoiler\Analytics\Services\ChurnPredictionService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsForecastCommand;
use ZeroBoiler\Analytics\Services\AnalyticsDeployGate;
use ZeroBoiler\Analytics\Services\EventTimeSeriesService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsPLGScoreCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsTimeSeriesCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsQuickSetupCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsFailoverCommand;
use ZeroBoiler\Analytics\Services\AARRRFrameworkService;
use ZeroBoiler\Analytics\Services\AnalyticsConfigExportService;
use ZeroBoiler\Analytics\Support\AnalyticsServiceRegistry;
use ZeroBoiler\Analytics\Services\CohortRevenueAttributionService;
use ZeroBoiler\Analytics\Services\SaaSEventTemplateService;
use ZeroBoiler\Analytics\Services\EventDataMartService;
use ZeroBoiler\Analytics\Services\AnalyticsInsightEngineService;
use ZeroBoiler\Analytics\Services\EventRecommendationService;
use ZeroBoiler\Analytics\Services\ProviderGapAnalyzer;
use ZeroBoiler\Analytics\Services\EventSparklineService;
use ZeroBoiler\Analytics\Services\EventCooccurrenceService;
use ZeroBoiler\Analytics\Services\DashboardWidgetService;
use ZeroBoiler\Analytics\Services\EventFingerprintService;
use ZeroBoiler\Analytics\Services\AlertNotificationService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsInsightsCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsSignalIntelligenceCommand;
use ZeroBoiler\Analytics\Services\CohortWaterfallService;
use ZeroBoiler\Analytics\Services\FunnelDropoffIntelligenceService;
use ZeroBoiler\Analytics\Services\EventSignalIntelligenceService;
use ZeroBoiler\Analytics\Services\CohortBehaviorProfilerService;
use ZeroBoiler\Analytics\Services\EventPredictiveScoringService;
use ZeroBoiler\Analytics\Services\EventSchemaValidationService;
use ZeroBoiler\Analytics\Services\BotDetectionService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsCohortIntelligenceCommand;
use ZeroBoiler\Analytics\Services\EventCorrelationHeatmapService;
use ZeroBoiler\Analytics\Services\AnalyticsHealthMonitorService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsHealthMonitorCommand;
use ZeroBoiler\Analytics\Services\TrackingGuardRailsService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsGuardRailsCommand;
use ZeroBoiler\Analytics\Services\EventDeliveryConfirmationService;
use ZeroBoiler\Analytics\Services\EventIdempotencyService;
use ZeroBoiler\Analytics\Services\PrivacyManifestService;
use ZeroBoiler\Analytics\Services\EventAnnotationService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsDeliveryCommand;
use ZeroBoiler\Analytics\Services\ProviderFallbackService;
use ZeroBoiler\Analytics\Services\ProviderSLAMonitor;
use ZeroBoiler\Analytics\Services\AnalyticsCostForecastService;
use ZeroBoiler\Analytics\Services\EventPolicyEngine;
use ZeroBoiler\Analytics\Services\SaaSFeatureUsageTrackerService;
use ZeroBoiler\Analytics\Services\EventBudgetOptimizerService;
use ZeroBoiler\Analytics\Services\TenantAnalyticsDashboardService;
use ZeroBoiler\Analytics\DTO\ProviderSLARecord;
use ZeroBoiler\Analytics\DTO\CostForecastProjection;
use ZeroBoiler\Analytics\DTO\PolicyViolation;
use ZeroBoiler\Analytics\Services\GroupAnalyticsService;
use ZeroBoiler\Analytics\Services\EventImpactScoreService;
use ZeroBoiler\Analytics\Services\ProviderAnalyticsIntelligenceService;
use ZeroBoiler\Analytics\Context\AnalyticsContextBus;
use ZeroBoiler\Analytics\Services\EventFlushingService;
use ZeroBoiler\Analytics\Services\AnalyticsInstrumentationAdvisor;
use ZeroBoiler\Analytics\Services\EventTimelineService;
use ZeroBoiler\Analytics\Services\EventStreamProcessorService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsTimelineCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsDiagnosticCommand;
use ZeroBoiler\Analytics\Services\EventNormalizationService;
use ZeroBoiler\Analytics\Services\AnalyticsConsistencyService;
use ZeroBoiler\Analytics\Services\AnalyticsEventSanitizer;
use ZeroBoiler\Analytics\Services\EventBudgetService;
use ZeroBoiler\Analytics\Services\AnalyticsApiGuard;
use ZeroBoiler\Analytics\Services\AnalyticsObservabilityService;
use ZeroBoiler\Analytics\Services\EventDeconflictionService;
use ZeroBoiler\Analytics\Services\SaaSOnboardingFunnelService;
use ZeroBoiler\Analytics\Services\EventTransportService;
use ZeroBoiler\Analytics\Services\EventCorrelationMatrixService;
use ZeroBoiler\Analytics\Services\DataLakeExportService;
use ZeroBoiler\Analytics\Services\SdkScopeTokenService;
use ZeroBoiler\Analytics\Services\EventSchemaRuntimeValidator;
use ZeroBoiler\Analytics\Services\ComposableEnrichmentPipeline;
use ZeroBoiler\Analytics\Services\AnalyticsAuditLogService;
use ZeroBoiler\Analytics\Services\ProviderEventCompatibilityMatrix;
use ZeroBoiler\Analytics\Services\AnalyticsDataQualityScorer;
use ZeroBoiler\Analytics\Services\EventClassificationService;
use ZeroBoiler\Analytics\Services\AnalyticsFeatureFlagService;
use ZeroBoiler\Analytics\Services\AnalyticsJourneyOrchestrator;
use ZeroBoiler\Analytics\Services\AnalyticsDashboardService;
use ZeroBoiler\Analytics\Services\EventIdempotencyKeyService;
use ZeroBoiler\Analytics\Services\WebhookEventSubscriptionService;
use ZeroBoiler\Analytics\Services\PerformanceScoreService;
use ZeroBoiler\Analytics\Services\UniversalEventNormalizer;
use ZeroBoiler\Analytics\Services\EventSchemaMigrationService;
use ZeroBoiler\Analytics\Services\ConsentBannerService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsDlqCommand;
use ZeroBoiler\Analytics\Console\Commands\SaaSMetricsCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsIngestionCommand;
use ZeroBoiler\Analytics\Services\EventIngestionService;
use ZeroBoiler\Analytics\Services\AnalyticsCommandScheduler;
use ZeroBoiler\Analytics\Services\EventRouterService;
use ZeroBoiler\Analytics\Services\AnalyticsWorkspaceService;
use ZeroBoiler\Analytics\Services\CustomerProfileUnificationService;
use ZeroBoiler\Analytics\Services\ComputedTraitsService;
use ZeroBoiler\Analytics\Services\PrivacyReportGeneratorService;
use ZeroBoiler\Analytics\Services\EventDebugCaptureService;
use ZeroBoiler\Analytics\Services\UserEngagementScoringService;
use ZeroBoiler\Analytics\Services\OTLPExportService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsOTLPCommand;
use ZeroBoiler\Analytics\Services\EventReplayAuditService;
use ZeroBoiler\Analytics\Services\AnalyticsDataRetentionService;
use ZeroBoiler\Analytics\Services\EventDependencyGraphService;
use ZeroBoiler\Analytics\Services\EventAuditTrailService;
use ZeroBoiler\Analytics\Services\EventAttributionTrailService;
use ZeroBoiler\Analytics\Services\MultiCurrencyRevenueNormalizer;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsDependencyGraphCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsReplayAuditCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsSnippetCommand;
use ZeroBoiler\Analytics\Services\AnalyticsSnippetService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsSimulationCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsSamplingCommand;
use ZeroBoiler\Analytics\Services\EventSamplingStrategyService;
use ZeroBoiler\Analytics\Services\DifferentialPrivacyService;
use ZeroBoiler\Analytics\Services\EventTtlService;
use ZeroBoiler\Analytics\Services\ReferralTrackingService;
use ZeroBoiler\Analytics\Services\TrafficSpikeShield;
use ZeroBoiler\Analytics\Services\EventReplaySimulator;
use ZeroBoiler\Analytics\Services\EventDeprecationService;
use ZeroBoiler\Analytics\Services\EventVersioningService;
use ZeroBoiler\Analytics\Services\EventFlowAnalysisService;
use ZeroBoiler\Analytics\Services\AnalyticsDataQualityFirewall;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsFlowCommand;

use ZeroBoiler\Analytics\Services\EventFraudDetectionService;
use ZeroBoiler\Analytics\Services\FirstValueDetectorService;
use ZeroBoiler\Analytics\Services\ProductMarketFitScoringService;
use ZeroBoiler\Analytics\Services\UnifiedHealthEndpointService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsFraudCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsHealthSummaryCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsDailyHealthReportCommand;
use ZeroBoiler\Analytics\Services\EventCorrelationEngineService;
use ZeroBoiler\Analytics\Services\AnomalyRootCauseAnalyzer;
use ZeroBoiler\Analytics\Services\AnalyticsSelfHealingService;
use ZeroBoiler\Analytics\Services\EventLineageTrackerService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsSelfHealCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsLineageCommand;
use ZeroBoiler\Analytics\Services\AutoInstrumentationEngine;
use ZeroBoiler\Analytics\Services\AnalyticsRollupService;
use ZeroBoiler\Analytics\Services\EventPayloadEncryptionService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsRollupCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsEncryptionCommand;
use ZeroBoiler\Analytics\Middleware\EventPayloadEncryptionMiddleware;
use ZeroBoiler\Analytics\Services\UtmParameterManager;
use ZeroBoiler\Analytics\Services\CohortFunnelMatrixService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsUtmCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsCohortFunnelCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsFunnelPrivacyCommand;
use ZeroBoiler\Analytics\Services\DeclarativeFunnelService;
use ZeroBoiler\Analytics\Services\PrivacyCollectionService;
use ZeroBoiler\Analytics\Services\EventTrendForecastService;
use ZeroBoiler\Analytics\Services\AnalyticsDataExplorerService;
use ZeroBoiler\Analytics\Services\EventCorrelationAnalyzerService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsTrendForecastCommand;
use ZeroBoiler\Analytics\Services\EventSessionContextService;
use ZeroBoiler\Analytics\Services\ProviderDispatchDedupService;
use ZeroBoiler\Analytics\Services\GeographicAnalyticsService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsGeoCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsExperimentCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsContractCommand;
use ZeroBoiler\Analytics\Services\EventContractTestService;
use ZeroBoiler\Analytics\Services\ExperimentAnalysisEngine;
use ZeroBoiler\Analytics\Services\EventBroadcastService;
use ZeroBoiler\Analytics\Services\AnalyticsHeartbeatMonitor;
use ZeroBoiler\Analytics\Services\SaaSFeatureFlagObserver;
use ZeroBoiler\Analytics\Services\SaaSBundleEventService;
use ZeroBoiler\Analytics\Services\EventCompactSerializer;
use ZeroBoiler\Analytics\Services\AnalyticsSdkTelemetryCollector;
use ZeroBoiler\Analytics\Services\ProjectionRegistry;
use ZeroBoiler\Analytics\Services\MetricProjectionEngine;
use ZeroBoiler\Analytics\Services\EventMaterializer;
use ZeroBoiler\Analytics\Services\EventCardinalityLimiter;
use ZeroBoiler\Analytics\Services\EventDeliverySlaMonitor;
use ZeroBoiler\Analytics\Services\StructuredEventLogger;
use ZeroBoiler\Analytics\Tracking\LifecycleAttributionEnricher;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsProjectionsCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsTranslationMatrixCommand;
use ZeroBoiler\Analytics\Services\CrossProviderTranslationMatrix;
use ZeroBoiler\Analytics\Services\RevenueHealthScoreService;

/**
 * Laravel service provider for the ZeroBoiler Analytics package.
 *
 * Registers the analytics manager, tracker services, pipeline,
 * schema registry, Blade directives, middleware, and API routes.
 *
 * @version 153.0.0
 *
 * @since 1.0.0
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

        // Unified event dispatcher (v3.5.0) — consent/priority/sampling/queue-aware
        $this->app->singleton(AnalyticsEventDispatcher::class, function (Application $app): AnalyticsEventDispatcher {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsEventDispatcher($manager, $queue, $config);
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

        // Customer Success Analytics (v135.0.0)
        $this->app->singleton(CustomerSuccessAnalyticsService::class, function (Application $app): CustomerSuccessAnalyticsService {
            return new CustomerSuccessAnalyticsService(
                cache: $app->make(CacheRepository::class),
            );
        });

        // Feature Gating Analytics (v135.0.0)
        $this->app->singleton(FeatureGatingAnalyticsService::class, function (Application $app): FeatureGatingAnalyticsService {
            return new FeatureGatingAnalyticsService(
                config: $app->make(ConfigRepository::class),
                cache: $app->make(CacheRepository::class),
            );
        });

        // Pipeline Profiler (v137.0.0)
        $this->app->singleton(AnalyticsPipelineProfilerService::class, function (Application $app): AnalyticsPipelineProfilerService {
            $profilerConfig = $app->make(ConfigRepository::class)->get('zeroboiler.analytics.pipeline_profiler', []);
            /** @var array{slow_threshold_ms?: float, critical_threshold_ms?: float, cache_ttl?: int, max_samples?: int} $profilerConfig */

            return new AnalyticsPipelineProfilerService(
                manager: $app->make(AnalyticsManager::class),
                cache: $app->make(CacheRepository::class),
                config: $profilerConfig,
            );
        });

        // Event Reliability (v137.0.0)
        $this->app->singleton(AnalyticsEventReliabilityService::class, function (Application $app): AnalyticsEventReliabilityService {
            $reliabilityConfig = $app->make(ConfigRepository::class)->get('zeroboiler.analytics.event_reliability', []);
            /** @var array{warning_threshold?: float, critical_threshold?: float, window_seconds?: int} $reliabilityConfig */

            return new AnalyticsEventReliabilityService(
                cache: $app->make(CacheRepository::class),
                config: $reliabilityConfig,
            );
        });

        // Event Buffer (v141.0.0) — debounce and dedup support for trackDebounced/trackOnce
        $this->app->singleton(AnalyticsEventBuffer::class, function (Application $app): AnalyticsEventBuffer {
            $bufferConfig = $app->make(ConfigRepository::class)->get('zeroboiler.analytics.event_buffer', []);
            /** @var array{max_capacity?: int, ttl_seconds?: int, dedup_window_seconds?: int} $bufferConfig */

            return new AnalyticsEventBuffer(
                maxCapacity: (int) ($bufferConfig['max_capacity'] ?? 100),
                ttlSeconds: (int) ($bufferConfig['ttl_seconds'] ?? 3600),
                dedupWindowSeconds: (int) ($bufferConfig['dedup_window_seconds'] ?? 10),
            );
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

        // Extended Schema Registry with EventSchemaBuilder integration (v118.0.0)
        $this->app->singleton(EventSchemaRegistryExtended::class, function (Application $app): EventSchemaRegistryExtended {
            /** @var CacheRepository $cache */
            $cache = $app->make(CacheRepository::class);

            return new EventSchemaRegistryExtended($cache);
        });
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

        // PLG scoring engine (v6.0.0)
        $this->app->singleton(PLGScoringService::class, function (Application $app): PLGScoringService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new PLGScoringService($manager, $cache, $config);
        });

        // Auto-instrumentation engine (v50.0.0) — config-driven Eloquent model event tracking
        $this->app->singleton(AutoInstrumentationEngine::class, function (Application $app): AutoInstrumentationEngine {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var \Illuminate\Contracts\Events\Dispatcher $dispatcher */
            $dispatcher = $app->make('events');

            return new AutoInstrumentationEngine($config, $manager, $dispatcher);
        });

        // Pre-computed analytics rollup engine (v52.0.0)
        $this->app->singleton(AnalyticsRollupService::class, function (Application $app): AnalyticsRollupService {
            return new AnalyticsRollupService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Event payload encryption service (v54.0.0)
        $this->app->singleton(EventPayloadEncryptionService::class, function (Application $app): EventPayloadEncryptionService {
            return new EventPayloadEncryptionService(
                $app->make(Encrypter::class),
                $app->make(ConfigRepository::class),
            );
        });

        // Event payload encryption middleware (v54.0.0)
        $this->app->singleton(EventPayloadEncryptionMiddleware::class, function (Application $app): EventPayloadEncryptionMiddleware {
            return new EventPayloadEncryptionMiddleware(
                $app->make(EventPayloadEncryptionService::class),
            );
        });

        // UTM Parameter Manager (v55.0.0)
        $this->app->singleton(UtmParameterManager::class, function (Application $app): UtmParameterManager {
            return new UtmParameterManager(
                $app->make(ConfigRepository::class),
            );
        });

        // Cohort × Funnel Matrix Engine (v56.0.0)
        $this->app->singleton(CohortFunnelMatrixService::class, function (Application $app): CohortFunnelMatrixService {
            return new CohortFunnelMatrixService(
                $app->make(CacheRepository::class),
                $app->make(ConfigRepository::class),
            );
        });

        // Declarative Funnel Service (v58.0.0) — config-driven funnel definitions
        $this->app->singleton(DeclarativeFunnelService::class, function (Application $app): DeclarativeFunnelService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new DeclarativeFunnelService($manager, $queue, $cache, $config);
        });

        // Privacy-Preserving Cookieless Collection (v58.0.0)
        $this->app->singleton(PrivacyCollectionService::class, function (Application $app): PrivacyCollectionService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new PrivacyCollectionService($manager, $queue, $cache, $config);
        });

        // Event time-series aggregation engine (v6.0.0)
        $this->app->singleton(EventTimeSeriesService::class, function (Application $app): EventTimeSeriesService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventTimeSeriesService($manager, $cache, $config);
        });

        // Event trend forecasting engine (v59.0.0) — linear regression, Holt's smoothing, seasonal decomposition
        $this->app->singleton(EventTrendForecastService::class, function (Application $app): EventTrendForecastService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventTrendForecastService($manager, $cache, $config);
        });

        // Analytics Data Explorer (v60.0.0) — ad-hoc querying, drill-down, funnel, comparison
        $this->app->singleton(AnalyticsDataExplorerService::class, function (Application $app): AnalyticsDataExplorerService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsDataExplorerService($cache, $config);
        });

        // Event Correlation Analyzer (v60.0.0) — time-lagged Pearson correlation, CCF, transitions
        $this->app->singleton(EventCorrelationAnalyzerService::class, function (Application $app): EventCorrelationAnalyzerService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventCorrelationAnalyzerService($cache, $config);
        });

        // Event Session Context Service (v63.0.0)
        $this->app->singleton(EventSessionContextService::class, function (Application $app): EventSessionContextService {
            /** @var CacheRepository $cache */
            $cache = $app->make(CacheRepository::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventSessionContextService($cache, $config);
        });

        // Provider Dispatch Deduplication Service (v63.0.0)
        $this->app->singleton(ProviderDispatchDedupService::class, function (Application $app): ProviderDispatchDedupService {
            /** @var CacheRepository $cache */
            $cache = $app->make(CacheRepository::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new ProviderDispatchDedupService($cache, $config);
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

        // Request-scoped analytics context bus (v17.0.0)
        $this->app->scoped(AnalyticsContextBus::class, function (Application $app): AnalyticsContextBus {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsContextBus($config);
        });

        // Event flushing service (v17.0.0)
        $this->app->singleton(EventFlushingService::class, function (Application $app): EventFlushingService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventFlushingService($manager, $config);
        });

        // Event budget service (v17.0.0) — config-driven singleton
        $this->app->singleton(EventBudgetService::class, function (Application $app): EventBudgetService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $budgetConfig = $config->get('zeroboiler.analytics.budget', []);
            /** @var array{enabled?: bool, client_limit?: int, user_limit?: int, global_limit?: int, window_seconds?: int, overflow_policy?: string, sample_rate?: float, cache_ttl?: int, use_cache?: bool} $budgetConfig */

            return new EventBudgetService(
                $cache,
                (int) ($budgetConfig['client_limit'] ?? 1000),
                (int) ($budgetConfig['user_limit'] ?? 500),
                (int) ($budgetConfig['global_limit'] ?? 100000),
                (int) ($budgetConfig['window_seconds'] ?? 3600),
                (string) ($budgetConfig['overflow_policy'] ?? 'reject'),
                (float) ($budgetConfig['sample_rate'] ?? 0.1),
                (int) ($budgetConfig['cache_ttl'] ?? 3600),
                (bool) ($budgetConfig['use_cache'] ?? true),
            );
        });

        // Analytics API guard (v17.0.0) — config-driven singleton
        $this->app->singleton(AnalyticsApiGuard::class, function (Application $app): AnalyticsApiGuard {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsApiGuard($cache, $config);
        });

        // Analytics observability service (v18.0.0) — dispatch-level monitoring
        $this->app->singleton(AnalyticsObservabilityService::class, function (Application $app): AnalyticsObservabilityService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsObservabilityService($cache, $config);
        });

        // Event Cardinality Limiter (v153.0.0) — prevents high-cardinality dimension explosion
        $this->app->singleton(EventCardinalityLimiter::class, function (Application $app): EventCardinalityLimiter {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventCardinalityLimiter($cache, $config);
        });

        // Structured Event Logger (v153.0.0) — unified structured logging for dispatches
        $this->app->singleton(StructuredEventLogger::class, function (Application $app): StructuredEventLogger {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new StructuredEventLogger($config);
        });

        // Event Delivery SLA Monitor (v153.0.0) — proactive per-provider SLA tracking
        $this->app->singleton(EventDeliverySlaMonitor::class, function (Application $app): EventDeliverySlaMonitor {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventDeliverySlaMonitor($cache, $config);
        });

        // Event deconfliction service (v17.0.0) — singleton for multi-provider analysis
        $this->app->singleton(EventDeconflictionService::class, function (Application $app): EventDeconflictionService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');

            return new EventDeconflictionService($manager);
        });

        // SaaS Onboarding Funnel service (v19.0.0)
        $this->app->singleton(SaaSOnboardingFunnelService::class, function (Application $app): SaaSOnboardingFunnelService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new SaaSOnboardingFunnelService($manager, $cache, $config);
        });

        // Event Transport Service (v20.0.0)
        $this->app->singleton(EventTransportService::class, function (Application $app): EventTransportService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventTransportService($cache, $config);
        });

        // Event Correlation Matrix Service (v20.0.0)
        $this->app->singleton(EventCorrelationMatrixService::class, function (Application $app): EventCorrelationMatrixService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventCorrelationMatrixService($cache, $config);
        });

        // Data Lake Export Service (v20.0.0)
        $this->app->singleton(DataLakeExportService::class, function (Application $app): DataLakeExportService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new DataLakeExportService($cache, $config);
        });

        // SDK Scope Token Service (v20.0.0)
        $this->app->singleton(SdkScopeTokenService::class, function (Application $app): SdkScopeTokenService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new SdkScopeTokenService($cache, $config);
        });

        // Event Schema Runtime Validator (v21.0.0)
        $this->app->singleton(EventSchemaRuntimeValidator::class, function (Application $app): EventSchemaRuntimeValidator {
            /** @var EventSchemaRegistry $registry */
            $registry = $app->make(EventSchemaRegistry::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            $schemaValidationConfig = $config->get('zeroboiler.analytics.schema_validation', []);
            /** @var array<string, mixed> $schemaValidationConfig */

            return new EventSchemaRuntimeValidator($registry, $schemaValidationConfig);
        });

        // Composable Enrichment Pipeline (v21.0.0)
        $this->app->singleton(ComposableEnrichmentPipeline::class, function (Application $app): ComposableEnrichmentPipeline {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new ComposableEnrichmentPipeline($config);
        });

        // Analytics Audit Log Service (v21.0.0)
        $this->app->singleton(AnalyticsAuditLogService::class, function (Application $app): AnalyticsAuditLogService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            $auditConfig = $config->get('zeroboiler.analytics.audit_log', []);
            /** @var array<string, mixed> $auditConfig */

            return new AnalyticsAuditLogService($cache, $auditConfig);
        });

        // Provider Event Compatibility Matrix (v21.0.0)
        $this->app->singleton(ProviderEventCompatibilityMatrix::class, function (Application $app): ProviderEventCompatibilityMatrix {
            return new ProviderEventCompatibilityMatrix;
        });

        // Analytics Data Quality Scorer (v21.0.0)
        $this->app->singleton(AnalyticsDataQualityScorer::class, function (Application $app): AnalyticsDataQualityScorer {
            return new AnalyticsDataQualityScorer;
        });

        // Event Classification Service (v21.0.0)
        $this->app->singleton(EventClassificationService::class, function (Application $app): EventClassificationService {
            return new EventClassificationService;
        });

        // Feature Flag Analytics Service (v22.0.0)
        $this->app->singleton(AnalyticsFeatureFlagService::class, function (Application $app): AnalyticsFeatureFlagService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsFeatureFlagService(
                $manager,
                $config,
                $app->make('cache'),
            );
        });

        // Feature Flag Analytics Service — variant distribution & conversion tracking (v78.0.0)
        $this->app->singleton(FeatureFlagAnalyticsService::class, function (Application $app): FeatureFlagAnalyticsService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new FeatureFlagAnalyticsService(
                $app->make('cache'),
                $manager,
                $config,
            );
        });

        // Revenue Waterfall Service — MRR movement tracking (v78.0.0)
        $this->app->singleton(RevenueWaterfallService::class, function (Application $app): RevenueWaterfallService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new RevenueWaterfallService(
                $app->make('cache'),
                $manager,
                $config,
            );
        });

        // Event Health Scoring Engine (v80.0.0) — per-event health monitoring
        $this->app->singleton(EventHealthScoringEngine::class, function (Application $app): EventHealthScoringEngine {
            return new EventHealthScoringEngine(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Analytics Deploy Gate (v80.0.0) — pre-deployment CI/CD validation
        $this->app->singleton(AnalyticsDeployGate::class, function (Application $app): AnalyticsDeployGate {
            return new AnalyticsDeployGate(
                $app->make(ConfigRepository::class),
                $app->make('cache'),
                $app->make(EventHealthScoringEngine::class),
            );
        });

        // Revenue Forecast Service (v81.0.0) — MRR forecasting, LTV, runway
        $this->app->singleton(RevenueForecastService::class, function (Application $app): RevenueForecastService {
            return new RevenueForecastService(
                $app->make(ConfigRepository::class),
            );
        });

        // Churn Prediction Service (v81.0.0) — weighted risk signal scoring
        $this->app->singleton(ChurnPredictionService::class, function (Application $app): ChurnPredictionService {
            return new ChurnPredictionService(
                $app->make(ConfigRepository::class),
            );
        });

        // Funnel Velocity Analyzer (v83.0.0) — real-time funnel step timing analytics
        $this->app->singleton(FunnelVelocityAnalyzer::class, function (Application $app): FunnelVelocityAnalyzer {
            return new FunnelVelocityAnalyzer(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Privacy-Aware Event Router (v83.0.0) — privacy zone-based event routing
        $this->app->singleton(PrivacyAwareEventRouter::class, function (Application $app): PrivacyAwareEventRouter {
            $routerConfig = $app->make(ConfigRepository::class)->get('zeroboiler.analytics.privacy_router', []);
            /** @var array<string, mixed> $routerConfig */

            return new PrivacyAwareEventRouter($routerConfig);
        });

        // Revenue Signal Detector (v83.0.0) — churn/expansion signal detection
        $this->app->singleton(RevenueSignalDetector::class, function (Application $app): RevenueSignalDetector {
            return new RevenueSignalDetector(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Conversion Path Discovery Service (v83.0.0) — multi-step conversion path analysis
        $this->app->singleton(ConversionPathDiscoveryService::class, function (Application $app): ConversionPathDiscoveryService {
            return new ConversionPathDiscoveryService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // SaaS Growth Metrics Service — activation, stickiness, virality, retention (v78.0.0)
        $this->app->singleton(SaaSGrowthMetricsService::class, function (Application $app): SaaSGrowthMetricsService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new SaaSGrowthMetricsService(
                $app->make('cache'),
                $manager,
                $config,
            );
        });

        // User Journey Orchestration Service (v22.0.0)
        $this->app->singleton(AnalyticsJourneyOrchestrator::class, function (Application $app): AnalyticsJourneyOrchestrator {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsJourneyOrchestrator(
                $manager,
                $config,
                $app->make('cache'),
            );
        });

        // v23.0.0 — Analytics Dashboard Service
        $this->app->singleton(AnalyticsDashboardService::class, function (Application $app): AnalyticsDashboardService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            /** @var AnalyticsMetrics $metrics */
            $metrics = $app->make('zeroboiler.analytics')->metrics();

            return new AnalyticsDashboardService(
                $app->make('cache'),
                $config,
                $metrics,
            );
        });

        // v23.0.0 — Event Idempotency Key Service
        $this->app->singleton(EventIdempotencyKeyService::class, function (Application $app): EventIdempotencyKeyService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventIdempotencyKeyService(
                $app->make('cache'),
                $config,
            );
        });

        // v23.0.0 — Webhook Event Subscription Service
        $this->app->singleton(WebhookEventSubscriptionService::class, function (Application $app): WebhookEventSubscriptionService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new WebhookEventSubscriptionService($config);
        });

        // v24.0.0 — Performance Score Service
        $this->app->singleton(PerformanceScoreService::class, function (Application $app): PerformanceScoreService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new PerformanceScoreService($app->make('cache'), $config);
        });

        // v24.0.0 — Cookie Consent Banner Service
        $this->app->singleton(ConsentBannerService::class, function (Application $app): ConsentBannerService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new ConsentBannerService($config);
        });

        // v28.0.0 — Universal Event Normalizer
        $this->app->singleton(UniversalEventNormalizer::class, function (Application $app): UniversalEventNormalizer {
            return new UniversalEventNormalizer;
        });

        // v28.0.0 — Event Schema Migration Service
        $this->app->singleton(EventSchemaMigrationService::class, function (Application $app): EventSchemaMigrationService {
            return new EventSchemaMigrationService(
                cache: $app->make('cache'),
            );
        });

        // v29.0.0 — Customer Profile Unification Service (CDP)
        $this->app->singleton(CustomerProfileUnificationService::class, function (Application $app): CustomerProfileUnificationService {
            return new CustomerProfileUnificationService(
                cache: $app->make('cache'),
                config: $app->make(ConfigRepository::class),
                propertiesStore: $app->make(UserPropertiesStore::class),
                identityResolution: $app->make(IdentityResolutionService::class),
            );
        });

        // v29.0.0 — Computed Traits Engine
        $this->app->singleton(ComputedTraitsService::class, function (Application $app): ComputedTraitsService {
            return new ComputedTraitsService(
                cache: $app->make('cache'),
                config: $app->make(ConfigRepository::class),
                propertiesStore: $app->make(UserPropertiesStore::class),
            );
        });

        // v29.0.0 — Privacy Report Generator
        $this->app->singleton(PrivacyReportGeneratorService::class, function (Application $app): PrivacyReportGeneratorService {
            return new PrivacyReportGeneratorService(
                cache: $app->make('cache'),
                config: $app->make(ConfigRepository::class),
                propertiesStore: $app->make(UserPropertiesStore::class),
                identityResolution: $app->make(IdentityResolutionService::class),
                cdp: $app->make(CustomerProfileUnificationService::class),
            );
        });

        // v29.0.0 — Event Debug Capture Service
        $this->app->singleton(EventDebugCaptureService::class, function (Application $app): EventDebugCaptureService {
            return new EventDebugCaptureService(
                cache: $app->make('cache'),
                config: $app->make(ConfigRepository::class),
            );
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

        // Cohort revenue attribution service (v6.6.0)
        $this->app->singleton(CohortRevenueAttributionService::class, function (Application $app): CohortRevenueAttributionService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var AnalyticsMetrics $metrics */
            $metrics = $manager->metrics();
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new CohortRevenueAttributionService($manager, $metrics, $cache, $config);
        });

        // SaaS Event Template Service (v6.9.0)
        $this->app->singleton(SaaSEventTemplateService::class, function (Application $app): SaaSEventTemplateService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');

            return new SaaSEventTemplateService($manager);
        });

        // Analytics Insight Engine (v7.0.0) — automated insight generation
        $this->app->singleton(AnalyticsInsightEngineService::class, function (Application $app): AnalyticsInsightEngineService {
            return new AnalyticsInsightEngineService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Event Recommendation Engine (v7.1.0) — gap analysis & instrumentation guidance
        $this->app->singleton(EventRecommendationService::class, function (Application $app): EventRecommendationService {
            return new EventRecommendationService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Provider Gap Analyzer (v7.1.0) — cross-provider coverage analysis
        $this->app->singleton(ProviderGapAnalyzer::class, function (Application $app): ProviderGapAnalyzer {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');

            return new ProviderGapAnalyzer(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
                $manager,
            );
        });

        // Event Sparkline Service (v7.2.0) — dashboard mini-chart data
        $this->app->singleton(EventSparklineService::class, function (Application $app): EventSparklineService {
            return new EventSparklineService(
                $app->make('cache'),
                $app->make(AnalyticsMetrics::class),
            );
        });

        // Event Co-occurrence Matrix Service (v7.2.0) — event correlation analysis
        $this->app->singleton(EventCooccurrenceService::class, function (Application $app): EventCooccurrenceService {
            return new EventCooccurrenceService(
                $app->make('cache'),
                $app->make(AnalyticsMetrics::class),
            );
        });

        // Event Fingerprinting Service (v8.2.0) — content-addressed event identity
        $this->app->singleton(EventFingerprintService::class, function (Application $app): EventFingerprintService {
            $fpConfig = $app->make(ConfigRepository::class)->get('zeroboiler.analytics.fingerprint', []);
            /** @var array{cache_prefix?: string, ttl?: int, time_bucket?: string, exclude_timestamp?: bool, exclude_params?: bool} $fpConfig */

            return new EventFingerprintService(
                $app->make('cache'),
                $fpConfig,
            );
        });

        // Dashboard Widget Service (v8.3.0) — cache-backed dashboard data widgets
        $this->app->singleton(DashboardWidgetService::class, function (Application $app): DashboardWidgetService {
            $widgetConfig = $app->make(ConfigRepository::class)->get('zeroboiler.analytics.dashboard_widgets', []);
            /** @var array{enabled?: bool, cache_ttl?: int, max_top_events?: int, timeline_points?: int, widgets?: list<string>} $widgetConfig */

            $streamService = $app->make(EventStreamService::class);

            return new DashboardWidgetService(
                $app->make('cache'),
                $app->make(AnalyticsMetrics::class),
                $widgetConfig,
                $streamService,
            );
        });

        // Alert Notification Dispatcher (v7.3.0) — external channel notifications
        $this->app->singleton(AlertNotificationService::class, function (Application $app): AlertNotificationService {
            return new AlertNotificationService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
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

        // Event rules engine for behavioral automation (v3.1.0)
        $this->app->singleton(EventRulesEngine::class, function (Application $app): EventRulesEngine {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventRulesEngine($manager, $cache, $config);
        });

        // User properties store for identity enrichment (v3.1.0)
        $this->app->singleton(UserPropertiesStore::class, function (Application $app): UserPropertiesStore {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new UserPropertiesStore($cache, $config);
        });

        // N-Day retention & stickiness calculator (v3.1.0)
        $this->app->singleton(RetentionCalculator::class, function (Application $app): RetentionCalculator {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new RetentionCalculator($cache, $config);
        });

        // Behavioral cohort builder for user segmentation (v3.1.0)
        $this->app->singleton(BehavioralCohortBuilder::class, function (Application $app): BehavioralCohortBuilder {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new BehavioralCohortBuilder($cache, $config);
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

        // Lifecycle event subscriber — bridges mapper + tracker + queue (v79.0.0)
        $this->app->singleton(LifecycleEventSubscriber::class, function (Application $app): LifecycleEventSubscriber {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var LifecycleEventMapper $mapper */
            $mapper = $app->make(LifecycleEventMapper::class);
            /** @var ServerSideTracker $tracker */
            $tracker = $app->make(ServerSideTracker::class);
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new LifecycleEventSubscriber($manager, $mapper, $tracker, $queue, $config);
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

        // Cross-platform attribution service with 5 attribution models
        $this->app->singleton(CrossPlatformAttributionService::class, function (Application $app): CrossPlatformAttributionService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new CrossPlatformAttributionService($cache, $config);
        });

        // Daily health report — unified health aggregation for SaaS operators (v116.0.0)
        $this->app->singleton(AnalyticsDailyHealthReportService::class, function (Application $app): AnalyticsDailyHealthReportService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsDailyHealthReportService($cache, $config);
        });

        // Replay auditor — event replay audit for data integrity (v118.0.0)
        $this->app->singleton(AnalyticsReplayAuditor::class, function (Application $app): AnalyticsReplayAuditor {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsReplayAuditor($cache, $config);
        });

        // Macro registry — load config-based macro definitions (v118.0.0)
        $this->app->afterResolving(function (ConfigRepository $config): void {
            $macrosConfig = $config->get('zeroboiler.analytics.macros', []);
            $enabled = (bool) ($macrosConfig['enabled'] ?? true);

            if ($enabled) {
                AnalyticsMacroRegistry::loadFromConfig($macrosConfig);
            }
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

        // AARRR framework service (v6.2.0) — unified SaaS growth metrics
        $this->app->singleton(AARRRFrameworkService::class, function (Application $app): AARRRFrameworkService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AARRRFrameworkService($manager, $cache, $config);
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

        // Provider Auto-Failover Orchestration (v145.0.0)
        $this->app->singleton(ProviderFailoverService::class, function (Application $app): ProviderFailoverService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new ProviderFailoverService($app->make('cache'), $config);
        });

        // Revenue Attribution Dashboard Service (v148.0.0)
        $this->app->singleton(RevenueAttributionDashboardService::class, function (Application $app): RevenueAttributionDashboardService {
            return new RevenueAttributionDashboardService(
                $app->make('zeroboiler.analytics'),
                $app->make(AnalyticsMetrics::class),
                $app->make(EventStoreManager::class),
                $app->make(ConfigRepository::class),
            );
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

        // SaaS Coverage Report Service (v67.0.0)
        $this->app->singleton(SaaSCoverageReportService::class, function (Application $app): SaaSCoverageReportService {
            return new SaaSCoverageReportService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Web Vitals Aggregator Service — RUM (v68.0.0)
        $this->app->singleton(WebVitalsAggregatorService::class, function (Application $app): WebVitalsAggregatorService {
            $config = $app->make(AnalyticsConfig::class);

            return new WebVitalsAggregatorService(
                $app->make('cache'),
                maxSamples: $config->rumMaxSamples(),
                ttl: $config->rumTtl(),
                window: $config->rumWindow(),
                enabled: $config->rumEnabled(),
                alertingEnabled: $config->rumAlertingEnabled(),
            );
        });

        // Event Inspector Service (v68.0.0)
        $this->app->singleton(EventInspectorService::class, function (Application $app): EventInspectorService {
            $config = $app->make(AnalyticsConfig::class);

            return new EventInspectorService(
                $app->make('cache'),
                enabled: $config->inspectorEnabled(),
                maxTraces: $config->inspectorMaxTraces(),
                ttl: $config->inspectorTtl(),
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

        // Checkout Flow Tracker (v6.8.0)
        $this->app->singleton(CheckoutFlowTracker::class, function (Application $app): CheckoutFlowTracker {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');

            return new CheckoutFlowTracker(
                $manager,
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // SaaS KPI Calculator Service (v6.8.0)
        $this->app->singleton(SaaSKpiCalculatorService::class, function (Application $app): SaaSKpiCalculatorService {
            return new SaaSKpiCalculatorService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Provider Event Validator (v6.8.0)
        $this->app->singleton(ProviderEventValidator::class);

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

        // Identity Resolution Service (v3.2.0)
        $this->app->singleton(IdentityResolutionService::class, function (Application $app): IdentityResolutionService {
            return new IdentityResolutionService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Identity Graph Service — Cross-Device Identity Resolution (v8.7.0)
        $this->app->singleton(IdentityGraphService::class, function (Application $app): IdentityGraphService {
            return new IdentityGraphService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Device Fingerprint Service (v8.7.0)
        $this->app->singleton(DeviceFingerprintService::class, function (Application $app): DeviceFingerprintService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $fpConfig = $config->get('zeroboiler.analytics.device_fingerprint', []);
            /** @var array{enabled?: bool, hash_algo?: string, include_ip?: bool, components?: list<string>} $fpConfig */

            return new DeviceFingerprintService($fpConfig);
        });

        // Session Fingerprint Service (v25.0.0)
        $this->app->singleton(SessionFingerprintService::class, function (Application $app): SessionFingerprintService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $fpConfig = $config->get('zeroboiler.analytics.session_fingerprint', []);

            return new SessionFingerprintService($app->make('cache'), $fpConfig);
        });

        // Event Context Snapshot Service (v8.5.0)
        $this->app->singleton(EventContextSnapshotService::class, function (Application $app): EventContextSnapshotService {
            return new EventContextSnapshotService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // User Journey Reconstruction Service (v8.5.0)
        $this->app->singleton(UserJourneyReconstructionService::class, function (Application $app): UserJourneyReconstructionService {
            return new UserJourneyReconstructionService(
                $app->make('cache'),
                $app->make(AnalyticsMetrics::class),
                $app->make(ConfigRepository::class),
            );
        });

        // Event Debounce Service (v3.2.0)
        $this->app->singleton(EventDebounceService::class, function (Application $app): EventDebounceService {
            return new EventDebounceService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Onboarding Wizard Service (v3.6.0)
        $this->app->singleton(OnboardingWizardService::class, function (Application $app): OnboardingWizardService {
            return new OnboardingWizardService(
                $app->make(ConfigRepository::class),
            );
        });

        // Growth Metrics Service (v3.6.0)
        $this->app->singleton(GrowthMetricsService::class, function (Application $app): GrowthMetricsService {
            return new GrowthMetricsService(
                $app->make(ConfigRepository::class),
            );
        });

        // Weekly Digest Service (v3.6.0)
        $this->app->singleton(WeeklyDigestService::class, function (Application $app): WeeklyDigestService {
            return new WeeklyDigestService(
                $app->make(ConfigRepository::class),
            );
        });

        // Event Enrichment Service (v3.7.0)
        $this->app->singleton(EventEnrichmentService::class, function (Application $app): EventEnrichmentService {
            return new EventEnrichmentService(
                $app->make(ConfigRepository::class),
            );
        });

        // Subscription Lifecycle Service (v3.7.0)
        $this->app->singleton(SubscriptionLifecycleService::class, function (Application $app): SubscriptionLifecycleService {
            return new SubscriptionLifecycleService(
                $app->make(ConfigRepository::class),
            );
        });

        // Revenue Intelligence Service (v3.7.0)
        $this->app->singleton(RevenueIntelligenceService::class, function (Application $app): RevenueIntelligenceService {
            return new RevenueIntelligenceService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Event Trace Service (v3.8.0)
        $this->app->singleton(EventTraceService::class, function (Application $app): EventTraceService {
            return new EventTraceService(
                $app->make(ConfigRepository::class),
            );
        });

        // Event Archetype Service (v3.9.0)
        $this->app->singleton(EventArchetypeService::class, function (Application $app): EventArchetypeService {
            return new EventArchetypeService(
                $app->make(CacheRepository::class),
                $app->make(ConfigRepository::class),
            );
        });

        // Config Drift Detection Service (v3.9.0)
        $this->app->singleton(ConfigDriftDetectionService::class, function (Application $app): ConfigDriftDetectionService {
            return new ConfigDriftDetectionService(
                $app->make(CacheRepository::class),
                $app->make(ConfigRepository::class),
            );
        });

        // Event Anonymization Aggregation Service (v3.9.0)
        $this->app->singleton(EventAnonymizationAggregationService::class, function (Application $app): EventAnonymizationAggregationService {
            return new EventAnonymizationAggregationService(
                $app->make(CacheRepository::class),
                $app->make(ConfigRepository::class),
                $app->make(AnalyticsMetrics::class),
            );
        });

        // Event Archive Service (v4.0.0) — persistent dispatched event archive
        $this->app->singleton(EventArchiveService::class, function (Application $app): EventArchiveService {
            return new EventArchiveService(
                $app->make(CacheRepository::class),
                $app->make('zeroboiler.analytics'),
                $app->make(ConfigRepository::class),
            );
        });

        // Event Template Engine (v140.0.0)
        $this->app->singleton(EventTemplateEngine::class, function (Application $app): EventTemplateEngine {
            return new EventTemplateEngine(
                $app->make(ConfigRepository::class),
            );
        });

        // Event Governance (v4.1.0)
        $this->app->singleton(EventGovernanceService::class, function (Application $app): EventGovernanceService {
            return new EventGovernanceService(
                $app->make(CacheRepository::class),
                $app->make(ConfigRepository::class),
            );
        });

        // Analytics Data Residency (v134.0.0)
        $this->app->singleton(AnalyticsDataResidencyService::class, function (Application $app): AnalyticsDataResidencyService {
            return new AnalyticsDataResidencyService(
                $app->make(CacheRepository::class),
                $app->make(ConfigRepository::class),
            );
        });

        // Event Consistency Validator (v134.0.0)
        $this->app->singleton(EventConsistencyValidatorService::class, function (Application $app): EventConsistencyValidatorService {
            return new EventConsistencyValidatorService(
                $app->make(CacheRepository::class),
                $app->make(ConfigRepository::class),
            );
        });

        // Event Cost Tracker (v4.4.0) — per-provider cost estimation
        $this->app->singleton(EventCostTracker::class, function (Application $app): EventCostTracker {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var AnalyticsMetrics $metrics */
            $metrics = $manager->metrics();
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventCostTracker($manager, $metrics, $cache, $config);
        });

        // Notification Webhook Service (v4.4.0) — alert notifications to Slack/Discord/webhook
        $this->app->singleton(NotificationWebhookService::class, function (Application $app): NotificationWebhookService {
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new NotificationWebhookService($cache, $config);
        });

        // Analytics Config Audit Service (v4.5.0) — masked config dump, diff, snapshot
        $this->app->singleton(AnalyticsConfigAuditService::class, function (Application $app): AnalyticsConfigAuditService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsConfigAuditService($config);
        });

        // Event Catalog Validator (v4.5.0) — catalog-aware event validation
        $this->app->singleton(EventCatalogValidator::class);

        // Event Plugin Registry (v7.8.0) — third-party package event discovery
        $this->app->singleton(EventPluginRegistry::class, function (Application $app): EventPluginRegistry {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventPluginRegistry($config);
        });

        // Event Enrichment Plugin System (v57.0.0) — third-party event enrichment plugins
        $this->app->singleton(EventEnrichmentRegistry::class, function (Application $app): EventEnrichmentRegistry {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventEnrichmentRegistry($config);
        });

        $this->app->singleton(EventEnrichmentOrchestrator::class, function (Application $app): EventEnrichmentOrchestrator {
            $registry = $app->make(EventEnrichmentRegistry::class);
            $metrics = $app->make(AnalyticsMetrics::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $debug = (bool) ($config->get('zeroboiler.analytics.enrichment_plugins.debug', false));

            return new EventEnrichmentOrchestrator($registry, $metrics, $debug);
        });

        // AI-Powered Analytics Intelligence Service (v5.0.0)
        $this->app->singleton(AnalyticsAIService::class, function (Application $app): AnalyticsAIService {
            return new AnalyticsAIService(
                $app->make(ConfigRepository::class),
                $app->make('cache'),
            );
        });

        // Event Experiment Tracker — A/B test tracking & significance (v5.0.0)
        $this->app->singleton(EventExperimentTracker::class, function (Application $app): EventExperimentTracker {
            return new EventExperimentTracker(
                $app->make(ConfigRepository::class),
                $app->make('cache'),
            );
        });

        // SaaS QuickStart Service (v5.0.0)
        $this->app->singleton(SaaSQuickStartService::class, function (Application $app): SaaSQuickStartService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');

            return new SaaSQuickStartService($manager);
        });

        // Event Cost Estimator (v139.0.0)
        $this->app->singleton(EventCostEstimator::class, function (Application $app): EventCostEstimator {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');

            return new EventCostEstimator($config, $manager, $cache);
        });

        // SaaS Onboarding Funnel Tracker (v139.0.0)
        $this->app->singleton(SaaSOnboardingFunnelTracker::class, function (Application $app): SaaSOnboardingFunnelTracker {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');

            return new SaaSOnboardingFunnelTracker($config, $manager, $cache);
        });

        // Anonymous Event Aggregation (v148.0.0)
        $this->app->singleton(AnonymousEventAggregationService::class, function (Application $app): AnonymousEventAggregationService {
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnonymousEventAggregationService($cache, $config);
        });

        // Funnel Leak Detection (v148.0.0)
        $this->app->singleton(FunnelLeakDetectionService::class, function (Application $app): FunnelLeakDetectionService {
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new FunnelLeakDetectionService($cache, $config);
        });

        // First-Party Data Service (v148.0.0)
        $this->app->singleton(FirstPartyDataService::class, function (Application $app): FirstPartyDataService {
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new FirstPartyDataService($cache, $config);
        });

        // SaaS Funnel Definitions (v101.0.0) — stateless, no DI needed
        // SaaSReadinessAssessment is instantiated per-request with tracked events;
        // registered as scoped so each request gets its own instance when resolved.
        $this->app->scoped(SaaSReadinessAssessment::class, function (Application $app): SaaSReadinessAssessment {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            $analyticsConfig = $config->get('zeroboiler.analytics', []);
            /** @var array{providers?: array<string, array{enabled?: bool}>, queue?: array{enabled?: bool}, api?: array{enabled?: bool}, identity?: array{enabled?: bool}, lifecycle?: array{enabled?: bool}, auto_track?: array{enabled?: bool}, ecommerce?: array{enabled?: bool}, consent?: array{enabled?: bool}} $analyticsConfig */

            // Derive enabled providers from config
            $enabledProviders = [];
            $providersConfig = $analyticsConfig['providers'] ?? [];
            foreach (['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'] as $provider) {
                $enabledProviders[$provider] = ($providersConfig[$provider]['enabled'] ?? false) === true;
            }

            // Derive config flags
            $configFlags = [
                'identity' => ($analyticsConfig['identity']['enabled'] ?? true) === true,
                'queue' => ($analyticsConfig['queue']['enabled'] ?? false) === true,
                'auto_track' => ($analyticsConfig['auto_track']['enabled'] ?? false) === true,
                'ecommerce' => ($analyticsConfig['ecommerce']['enabled'] ?? true) === true,
                'api' => ($analyticsConfig['api']['enabled'] ?? false) === true,
                'lifecycle' => ($analyticsConfig['lifecycle']['enabled'] ?? false) === true,
                'consent' => ($analyticsConfig['consent']['enabled'] ?? true) === true,
            ];

            return new SaaSReadinessAssessment(
                trackedEvents: [],
                enabledProviders: $enabledProviders,
                configFlags: $configFlags,
            );
        });

        // Analytics Data Service (v5.0.0) — cache-backed dashboard queries
        $this->app->singleton(AnalyticsDataService::class, function (Application $app): AnalyticsDataService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            $dataConfig = $config->get('zeroboiler.analytics.data_service', []);
            /** @var array{ttl?: int, daily_ttl?: int} $dataConfig */

            return new AnalyticsDataService(
                $app->make('cache'),
                $manager->metrics(),
                (int) ($dataConfig['ttl'] ?? 3600),
                (int) ($dataConfig['daily_ttl'] ?? 86400),
            );
        });

        // Event Query Engine (v5.9.0) — structured analytics data queries
        $this->app->singleton(EventQueryEngine::class, function (Application $app): EventQueryEngine {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            $dataConfig = $config->get('zeroboiler.analytics.data_service', []);
            /** @var array{ttl?: int} $dataConfig */

            return new EventQueryEngine(
                $app->make('cache'),
                $manager->metrics(),
                (int) ($dataConfig['ttl'] ?? 300),
            );
        });

        // Analytics Query Builder (v5.9.0) — fluent query DSL
        $this->app->bind(AnalyticsQueryBuilder::class);

        // Event Taxonomy Service (v5.0.0) — tag-based event classification
        $this->app->singleton(EventTaxonomyService::class, function (Application $app): EventTaxonomyService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            $taxonomyConfig = $config->get('zeroboiler.analytics.taxonomy', []);
            /** @var array{ttl?: int, tags?: array<string, list<string>>} $taxonomyConfig */

            return new EventTaxonomyService(
                $app->make('cache'),
                (array) ($taxonomyConfig['tags'] ?? []),
                (int) ($taxonomyConfig['ttl'] ?? 3600),
            );
        });

        // Multi-Tenant Analytics Context (v5.0.0) — workspace-aware tracking
        $this->app->singleton(TenantAnalyticsContext::class, function (Application $app): TenantAnalyticsContext {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            $tenantConfig = $config->get('zeroboiler.analytics.tenant', []);
            /** @var array{ttl?: int} $tenantConfig */

            return new TenantAnalyticsContext(
                $app->make('cache'),
                (int) ($tenantConfig['ttl'] ?? 3600),
            );
        });

        // Event Schema JSON Generator (v5.9.0) — frontend validation schemas
        $this->app->singleton(EventSchemaJsonGenerator::class);

        // Analytics Event Bus (v5.9.0) — in-process pub/sub for decoupled event processing
        $this->app->singleton(AnalyticsEventBus::class);

        // Regional Consent Service (v5.9.0) — GDPR-region-aware consent defaults
        $this->app->singleton(RegionalConsentService::class, function (Application $app): RegionalConsentService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new RegionalConsentService($config);
        });

        // Config Export Service (v6.5.0) — safe redacted config snapshots for debugging
        $this->app->singleton(AnalyticsConfigExportService::class, function (Application $app): AnalyticsConfigExportService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsConfigExportService($config);
        });

        // Event Data Mart Service (v7.0.0) — OLAP-style pre-aggregated event rollup cubes
        $this->app->singleton(EventDataMartService::class, function (Application $app): EventDataMartService {
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventDataMartService($cache, $config);
        });

        // Analytics Service Registry (v9.1.0) — lazy service locator for controller dependencies
        $this->app->singleton(AnalyticsServiceRegistry::class, function (Application $app): AnalyticsServiceRegistry {
            return new AnalyticsServiceRegistry($app);
        });

        // Cohort Waterfall Analysis Service (v7.5.0)
        $this->app->singleton(CohortWaterfallService::class, function (Application $app): CohortWaterfallService {
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new CohortWaterfallService($cache, $config);
        });

        // Funnel Drop-off Intelligence Service (v7.5.0)
        $this->app->singleton(FunnelDropoffIntelligenceService::class, function (Application $app): FunnelDropoffIntelligenceService {
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new FunnelDropoffIntelligenceService($cache, $config);
        });

        $this->app->singleton(EventSignalIntelligenceService::class, function (Application $app): EventSignalIntelligenceService {
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            /** @var AnalyticsMetrics $metrics */
            $metrics = $app->make(AnalyticsMetrics::class);

            return new EventSignalIntelligenceService($cache, $config, $metrics);
        });

        // Cohort Intelligence (v8.1.0)
        $this->app->singleton(CohortBehaviorProfilerService::class, function (Application $app): CohortBehaviorProfilerService {
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            $cohortConfig = $config->get('zeroboiler.analytics.cohort_intelligence', []);

            return new CohortBehaviorProfilerService($cache, [
                'enabled' => $cohortConfig['enabled'] ?? true,
                'cache_ttl' => $cohortConfig['profiler_cache_ttl'] ?? 300,
                'lookback_days' => $cohortConfig['lookback_days'] ?? 30,
                'min_events_for_profiling' => $cohortConfig['min_events_for_profiling'] ?? 3,
            ]);
        });

        $this->app->singleton(EventPredictiveScoringService::class, function (Application $app): EventPredictiveScoringService {
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            $cohortConfig = $config->get('zeroboiler.analytics.cohort_intelligence', []);

            return new EventPredictiveScoringService($cache, [
                'enabled' => $cohortConfig['enabled'] ?? true,
                'cache_ttl' => $cohortConfig['scoring_cache_ttl'] ?? 600,
                'lookback_days' => $cohortConfig['lookback_days'] ?? 30,
                'decay_factor' => $cohortConfig['decay_factor'] ?? 0.95,
            ]);
        });

        // Event Schema Validation (v8.4.0)
        $this->app->singleton(EventSchemaValidationService::class, function (Application $app): EventSchemaValidationService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventSchemaValidationService($config);
        });

        // Bot Detection (v8.4.0)
        $this->app->singleton(BotDetectionService::class, function (Application $app): BotDetectionService {
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new BotDetectionService($cache, $config);
        });

        // Event Correlation Heatmap (v8.8.0)
        $this->app->singleton(EventCorrelationHeatmapService::class, function (Application $app): EventCorrelationHeatmapService {
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventCorrelationHeatmapService($cache, $config);
        });

        // Health Monitor Dashboard (v8.8.0)
        $this->app->singleton(AnalyticsHealthMonitorService::class, function (Application $app): AnalyticsHealthMonitorService {
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsHealthMonitorService($cache, $config);
        });

        $this->app->singleton(TrackingGuardRailsService::class, function (Application $app): TrackingGuardRailsService {
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            /** @var AnalyticsManager $manager */
            $manager = $app->make(AnalyticsManager::class);

            return new TrackingGuardRailsService($cache, $config, $manager);
        });

        // Event Delivery Confirmation (v9.0.0)
        $this->app->singleton(EventDeliveryConfirmationService::class, function (Application $app): EventDeliveryConfirmationService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make(AnalyticsManager::class);
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventDeliveryConfirmationService($manager, $cache, $config);
        });

        // Event Idempotency Service (v9.3.0) — deduplicate analytics event dispatches
        $this->app->singleton(EventIdempotencyService::class, function (Application $app): EventIdempotencyService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventIdempotencyService($cache, $config);
        });

        // Privacy Manifest Service (v9.3.0) — GDPR Article 30 processing records
        $this->app->singleton(PrivacyManifestService::class, function (Application $app): PrivacyManifestService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new PrivacyManifestService($cache, $config);
        });

        // Event Annotation Service (v9.3.0) — deployment markers and event tagging
        $this->app->singleton(EventAnnotationService::class, function (Application $app): EventAnnotationService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventAnnotationService($cache, $config);
        });

        // Provider Fallback Service (v9.4.0) — multi-provider failover with circuit breaker
        $this->app->singleton(ProviderFallbackService::class, function (Application $app): ProviderFallbackService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new ProviderFallbackService($cache, $config);
        });

        // Provider SLA Monitor (v84.0.0) — uptime, latency, and breach tracking
        $this->app->singleton(ProviderSLAMonitor::class, function (Application $app): ProviderSLAMonitor {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new ProviderSLAMonitor($cache, $config);
        });

        // Analytics Cost Forecast Service (v84.0.0) — provider cost projections
        $this->app->singleton(AnalyticsCostForecastService::class, function (Application $app): AnalyticsCostForecastService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsCostForecastService($cache, $config);
        });

        // Event Policy Engine (v84.0.0) — governance compliance rules
        $this->app->singleton(EventPolicyEngine::class, function (Application $app): EventPolicyEngine {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventPolicyEngine($cache, $config);
        });

        // B2B Group Analytics Service (v9.5.0) — account/company-level analytics
        $this->app->singleton(GroupAnalyticsService::class, function (Application $app): GroupAnalyticsService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new GroupAnalyticsService($cache, $config);
        });

        // SaaS Feature Usage Tracker (v85.0.0) — DAU/WAU/MAU, feature streaks, adoption
        $this->app->singleton(SaaSFeatureUsageTrackerService::class, function (Application $app): SaaSFeatureUsageTrackerService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $ttl = (int) $config->get('zeroboiler.feature_usage.cache_ttl', 86400);

            return new SaaSFeatureUsageTrackerService($cache, $ttl);
        });

        // Event Budget Optimizer (v85.0.0) — cost-aware intelligent event routing
        $this->app->singleton(EventBudgetOptimizerService::class, function (Application $app): EventBudgetOptimizerService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $budgetConfig = $config->get('zeroboiler.budget_optimizer', []);
            /** @var array{cache_ttl?: int, cost_per_event?: array<string, float>, monthly_budgets?: array<string, float>} $budgetConfig */

            return new EventBudgetOptimizerService(
                cache: $cache,
                ttl: (int) ($budgetConfig['cache_ttl'] ?? 86400),
                costPerEvent: (array) ($budgetConfig['cost_per_event'] ?? []),
                budgets: (array) ($budgetConfig['monthly_budgets'] ?? []),
            );
        });

        // Tenant Analytics Dashboard (v85.0.0) — multi-tenant analytics aggregation
        $this->app->singleton(TenantAnalyticsDashboardService::class, function (Application $app): TenantAnalyticsDashboardService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $ttl = (int) $config->get('zeroboiler.tenant_dashboard.cache_ttl', 86400);

            return new TenantAnalyticsDashboardService($cache, $ttl);
        });

        // Event Sequence Prediction Service (v86.0.0) — Markov chain next-event prediction
        $this->app->singleton(\ZeroBoiler\Analytics\Services\EventSequencePredictionService::class, function (Application $app): \ZeroBoiler\Analytics\Services\EventSequencePredictionService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            return new \ZeroBoiler\Analytics\Services\EventSequencePredictionService($cache, $config);
        });

        // Event Cost Ledger Service (v86.0.0) — per-event dispatch cost tracking
        $this->app->singleton(\ZeroBoiler\Analytics\Services\EventCostLedgerService::class, function (Application $app): \ZeroBoiler\Analytics\Services\EventCostLedgerService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            return new \ZeroBoiler\Analytics\Services\EventCostLedgerService($cache, $config);
        });

        // Analytics Compliance Report Service (v86.0.0) — GDPR/CCPA/SOC2 compliance reports
        $this->app->singleton(\ZeroBoiler\Analytics\Services\AnalyticsComplianceReportService::class, function (Application $app): \ZeroBoiler\Analytics\Services\AnalyticsComplianceReportService {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            return new \ZeroBoiler\Analytics\Services\AnalyticsComplianceReportService($config);
        });

        // Data-Driven Attribution Service (v87.0.0) — Shapley-value multi-touch attribution
        $this->app->singleton(\ZeroBoiler\Analytics\Services\DataDrivenAttributionService::class, function (Application $app): \ZeroBoiler\Analytics\Services\DataDrivenAttributionService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');

            return new \ZeroBoiler\Analytics\Services\DataDrivenAttributionService($cache, $config);
        });

        // Unit Economics Service (v87.0.0) — LTV, CAC, LTV:CAC, Magic Number
        $this->app->singleton(\ZeroBoiler\Analytics\Services\UnitEconomicsService::class, function (Application $app): \ZeroBoiler\Analytics\Services\UnitEconomicsService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');

            return new \ZeroBoiler\Analytics\Services\UnitEconomicsService($cache, $config);
        });

        // Product Analytics Maturity Service (v87.0.0) — maturity model assessment
        $this->app->singleton(\ZeroBoiler\Analytics\Services\ProductAnalyticsMaturityService::class, function (): \ZeroBoiler\Analytics\Services\ProductAnalyticsMaturityService {
            return new \ZeroBoiler\Analytics\Services\ProductAnalyticsMaturityService;
        });

        // Event Impact Score Service (v9.6.0) — composite event value scoring
        $this->app->singleton(EventImpactScoreService::class, function (Application $app): EventImpactScoreService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $impactTtl = (int) $config->get('zeroboiler.analytics.impact.cache_ttl', 300);

            return new EventImpactScoreService(
                cache: $cache,
                cacheTtl: $impactTtl,
            );
        });

        // Provider Analytics Intelligence Service (v9.6.0) — multi-provider coverage analysis
        $this->app->singleton(ProviderAnalyticsIntelligenceService::class, function (Application $app): ProviderAnalyticsIntelligenceService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $intelligenceTtl = (int) $config->get('zeroboiler.analytics.provider_intelligence.cache_ttl', 300);

            return new ProviderAnalyticsIntelligenceService(
                cache: $cache,
                cacheTtl: $intelligenceTtl,
            );
        });

        // Analytics Instrumentation Advisor (v9.7.0) — code-snippet level guidance
        $this->app->singleton(AnalyticsInstrumentationAdvisor::class);

        // Event Stream Processor Service (v31.0.0) — sequential event analysis & pattern discovery
        $this->app->singleton(EventStreamProcessorService::class, function (Application $app): EventStreamProcessorService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $streamConfig = $config->get('zeroboiler.analytics.stream_processing', []);

            return new EventStreamProcessorService($cache, $streamConfig);
        });

        // Event Timeline Service (v75.0.0) — chronological event timelines
        $this->app->singleton(EventTimelineService::class, function (Application $app): EventTimelineService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            /** @var IdentityResolutionService $identityService */
            $identityService = $app->make(IdentityResolutionService::class);
            $timelineConfig = $config->get('zeroboiler.analytics.timeline', []);

            return new EventTimelineService($cache, $identityService, $timelineConfig);
        });

        // Event Normalization Service (v10.5.0) — provider-agnostic event normalization
        $this->app->singleton(EventNormalizationService::class, function (Application $app): EventNormalizationService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');

            $enabledProviders = [
                'ga4' => $manager->ga4()->isEnabled(),
                'gtm' => $manager->gtm()->isEnabled(),
                'meta' => $manager->meta()->isEnabled(),
                'posthog' => $manager->posthog()->isEnabled(),
                'plausible' => $manager->plausible()->isEnabled(),
                'mixpanel' => $manager->mixpanel()->isEnabled(),
                'amplitude' => $manager->amplitude()->isEnabled(),
                'webhook' => $manager->webhook()->isEnabled(),
            ];

            /** @var \Illuminate\Http\Request|null $request */
            $request = $app->make('request', []);

            return new EventNormalizationService($config, $cache, $enabledProviders, 300, $request);
        });

        // Analytics Consistency Service (v10.5.0) — cross-provider event consistency checker
        $this->app->singleton(AnalyticsConsistencyService::class, function (Application $app): AnalyticsConsistencyService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');

            return new AnalyticsConsistencyService($manager, $config, $cache);
        });

        // Plausible Analytics tracker (v14.0.0) — config-driven singleton
        $this->app->singleton(PlausibleTracker::class, function (Application $app): PlausibleTracker {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $plausibleConfig = $config->get('zeroboiler.analytics.plausible', []);
            /** @var array{enabled?: bool, domain?: string, api_key?: string, base_url?: string, custom_script_url?: string} $plausibleConfig */

            return new PlausibleTracker(
                domain: (string) ($plausibleConfig['domain'] ?? ''),
                apiKey: (string) ($plausibleConfig['api_key'] ?? ''),
                baseUrl: (string) ($plausibleConfig['base_url'] ?? 'https://plausible.io/api/event'),
                enabled: (bool) ($plausibleConfig['enabled'] ?? false),
                customScriptUrl: isset($plausibleConfig['custom_script_url']) ? (string) $plausibleConfig['custom_script_url'] : null,
            );
        });

        // PostHog Analytics tracker (v14.0.0) — config-driven singleton
        $this->app->singleton(PosthogTracker::class, function (Application $app): PosthogTracker {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $posthogConfig = $config->get('zeroboiler.analytics.posthog', []);
            /** @var array{enabled?: bool, api_key?: string, host?: string, project_id?: string, capi_enabled?: bool, capture_path?: string} $posthogConfig */

            return new PosthogTracker(
                apiKey: (string) ($posthogConfig['api_key'] ?? ''),
                host: (string) ($posthogConfig['host'] ?? 'https://eu.posthog.com'),
                projectId: (string) ($posthogConfig['project_id'] ?? ''),
                enabled: (bool) ($posthogConfig['enabled'] ?? false),
                capiEnabled: (bool) ($posthogConfig['capi_enabled'] ?? true),
                capturePath: (string) ($posthogConfig['capture_path'] ?? '/capture/'),
            );
        });

        // Event Sanitization (v13.0.0)
        $this->app->singleton(AnalyticsEventSanitizer::class, function (Application $app): AnalyticsEventSanitizer {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsEventSanitizer($config);
        });

        // Event Store — Persistent Event Storage (v30.0.0)
        $this->app->singleton(\ZeroBoiler\Analytics\Contracts\AnalyticsEventStoreInterface::class, function (Application $app): \ZeroBoiler\Analytics\Contracts\AnalyticsEventStoreInterface {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $storeConfig = $config->get('zeroboiler.analytics.event_store', []);
            /** @var array{enabled?: bool, driver?: string} $storeConfig */

            if (! ($storeConfig['enabled'] ?? false)) {
                return new \ZeroBoiler\Analytics\Store\NullEventStore;
            }

            return new \ZeroBoiler\Analytics\Store\EventStoreManager($config);
        });

        $this->app->alias(\ZeroBoiler\Analytics\Contracts\AnalyticsEventStoreInterface::class, 'zeroboiler.analytics.event_store');

        // User Engagement Scoring Service (v34.0.0) — composite engagement scoring
        $this->app->singleton(UserEngagementScoringService::class, function (Application $app): UserEngagementScoringService {
            return new UserEngagementScoringService(
                $app->make('cache'),
                $app->make('zeroboiler.analytics')->metrics(),
                $app->make(ConfigRepository::class),
            );
        });

        // Event Ingestion Service (v36.0.0) — centralized event ingestion pipeline
        $this->app->singleton(EventIngestionService::class, function (Application $app): EventIngestionService {
            return new EventIngestionService(
                $app->make('zeroboiler.analytics'),
                $app->make(ConfigRepository::class),
                $app->make('cache'),
            );
        });

        // Analytics Command Scheduler (v36.0.0) — config-driven task scheduling
        $this->app->singleton(AnalyticsCommandScheduler::class, function (Application $app): AnalyticsCommandScheduler {
            return new AnalyticsCommandScheduler(
                $app->make(ConfigRepository::class),
                $app->make('cache'),
            );
        });

        // Event Router Service (v37.0.0) — provider-aware destination routing
        $this->app->singleton(EventRouterService::class, function (Application $app): EventRouterService {
            return new EventRouterService(
                $app->make(ConfigRepository::class),
                $app->make('cache'),
            );
        });

        // Analytics Workspace Service (v37.0.0) — multi-tenant workspace KPI rollups
        $this->app->singleton(AnalyticsWorkspaceService::class, function (Application $app): AnalyticsWorkspaceService {
            return new AnalyticsWorkspaceService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // OpenTelemetry (OTLP) Export Service (v38.0.0) — unchanged
        $this->app->singleton(OTLPExportService::class, function (Application $app): OTLPExportService {
            return new OTLPExportService(
                $app->make(ConfigRepository::class),
                $app->make('cache'),
            );
        });

        // Event Replay Audit Service (v39.0.0)
        $this->app->singleton(EventReplayAuditService::class, function (Application $app): EventReplayAuditService {
            return new EventReplayAuditService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Event Audit Trail Service (v72.0.0)
        $this->app->singleton(EventAuditTrailService::class, function (Application $app): EventAuditTrailService {
            return new EventAuditTrailService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Event Attribution Trail Service (v72.0.0)
        $this->app->singleton(EventAttributionTrailService::class, function (Application $app): EventAttributionTrailService {
            return new EventAttributionTrailService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Analytics Data Retention Service (v39.0.0)
        $this->app->singleton(AnalyticsDataRetentionService::class, function (Application $app): AnalyticsDataRetentionService {
            return new AnalyticsDataRetentionService(
                $app->make('cache'),
                $app->make(EventArchiveService::class),
                $app->make(ConfigRepository::class),
            );
        });

        // Event Dependency Graph Service (v40.0.0)
        $this->app->singleton(EventDependencyGraphService::class, function (Application $app): EventDependencyGraphService {
            return new EventDependencyGraphService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Multi-Currency Revenue Normalizer (v40.0.0)
        $this->app->singleton(MultiCurrencyRevenueNormalizer::class, function (Application $app): MultiCurrencyRevenueNormalizer {
            return new MultiCurrencyRevenueNormalizer(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Analytics Snippet Generator (v42.0.0)
        $this->app->singleton(AnalyticsSnippetService::class, function (Application $app): AnalyticsSnippetService {
            return new AnalyticsSnippetService(
                $app->make(ConfigRepository::class),
            );
        });

        // Differential Privacy Service (v42.0.0)
        $this->app->singleton(DifferentialPrivacyService::class, function (Application $app): DifferentialPrivacyService {
            return new DifferentialPrivacyService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Event TTL Service (v43.0.0)
        $this->app->singleton(EventTtlService::class, function (Application $app): EventTtlService {
            $config = $app->make(ConfigRepository::class);
            $ttlConfig = $config->get('zeroboiler.analytics.event_ttl', []);
            return new EventTtlService(
                $app->make('cache'),
                (int) ($ttlConfig['default_ttl_seconds'] ?? 86400),
                (array) ($ttlConfig['event_overrides'] ?? []),
                (array) ($ttlConfig['category_overrides'] ?? []),
                (bool) ($ttlConfig['drop_expired'] ?? false),
                true,
                (int) ($ttlConfig['metrics_ttl'] ?? 3600),
            );
        });

        // Referral Tracking Service (v43.0.0)
        $this->app->singleton(ReferralTrackingService::class, function (Application $app): ReferralTrackingService {
            $config = $app->make(ConfigRepository::class);
            $referralConfig = $config->get('zeroboiler.analytics.referral', []);
            return new ReferralTrackingService(
                $app->make('cache'),
                (int) ($referralConfig['code_length'] ?? 8),
                (int) ($referralConfig['attribution_ttl'] ?? 2592000),
                (int) ($referralConfig['metrics_ttl'] ?? 3600),
            );
        });

        // Traffic Spike Shield (v43.0.0)
        $this->app->singleton(TrafficSpikeShield::class, function (Application $app): TrafficSpikeShield {
            $config = $app->make(ConfigRepository::class);
            $shieldConfig = $config->get('zeroboiler.analytics.spike_shield', []);
            return new TrafficSpikeShield(
                $app->make('cache'),
                (int) ($shieldConfig['normal_threshold'] ?? 1000),
                (int) ($shieldConfig['spike_threshold'] ?? 5000),
                (int) ($shieldConfig['window_size'] ?? 60),
                (int) ($shieldConfig['cooldown'] ?? 30),
                (bool) ($shieldConfig['enabled'] ?? false),
                (float) ($shieldConfig['throttle_ratio'] ?? 0.1),
                (array) ($shieldConfig['event_overrides'] ?? []),
                (int) ($shieldConfig['metrics_ttl'] ?? 3600),
            );
        });

        // Event Replay Simulator (v43.0.0)
        $this->app->singleton(EventReplaySimulator::class, function (Application $app): EventReplaySimulator {
            $config = $app->make(ConfigRepository::class);
            $simConfig = $config->get('zeroboiler.analytics.simulator', []);
            return new EventReplaySimulator(
                $app->make('cache'),
                [],
                (int) ($simConfig['batch_size'] ?? 100),
                (int) ($simConfig['rate_limit'] ?? 50),
                (bool) ($simConfig['dry_run'] ?? true),
                3600,
            );
        });

        // Event Deprecation Service (v44.0.0) — lifecycle management, deprecation warnings
        $this->app->singleton(EventDeprecationService::class, function (Application $app): EventDeprecationService {
            return new EventDeprecationService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Event Versioning Service (v44.0.0) — catalog-level version metadata
        $this->app->singleton(EventVersioningService::class);

        // Event Sampling Strategy Service (v46.0.0) — config-driven sampling with 3 strategies
        $this->app->singleton(EventSamplingStrategyService::class, function (Application $app): EventSamplingStrategyService {
            return new EventSamplingStrategyService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Event Flow Analysis Service (v46.0.0) — real-time user flow/journey analysis
        $this->app->singleton(EventFlowAnalysisService::class, function (Application $app): EventFlowAnalysisService {
            return new EventFlowAnalysisService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Analytics Data Quality Firewall (v46.0.0) — pre-dispatch quality scoring
        $this->app->singleton(AnalyticsDataQualityFirewall::class, function (Application $app): AnalyticsDataQualityFirewall {
            return new AnalyticsDataQualityFirewall(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Event Fraud Detection Service (v47.0.0) — spam, bot flood, injection detection
        $this->app->singleton(EventFraudDetectionService::class, function (Application $app): EventFraudDetectionService {
            return new EventFraudDetectionService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Product-Market Fit Scoring Service (v47.0.0) — Sean Ellis, retention, activation
        $this->app->singleton(ProductMarketFitScoringService::class, function (Application $app): ProductMarketFitScoringService {
            return new ProductMarketFitScoringService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // First-Value Detection Service (v61.0.0) — "aha moment" milestone tracking
        $this->app->singleton(FirstValueDetectorService::class, function (Application $app): FirstValueDetectorService {
            return new FirstValueDetectorService(
                $app->make('cache'),
                $app->make(ConfigRepository::class)->get('zeroboiler.analytics.first_value', []),
            );
        });

        // Unified Health Endpoint Service (v47.0.0) — composite health for probes
        $this->app->singleton(UnifiedHealthEndpointService::class, function (Application $app): UnifiedHealthEndpointService {
            return new UnifiedHealthEndpointService(
                $app->make(AnalyticsHealthService::class),
                $app->make(AnalyticsHealthCheckService::class),
                $app->make(AnalyticsHealthMonitorService::class),
                $app->make(AnalyticsDataQualityFirewall::class),
                $app->make(EventFraudDetectionService::class),
                $app->make(ProductMarketFitScoringService::class),
            );
        });

        // Event Correlation Engine Service (v48.0.0) — causal event correlation analysis
        $this->app->singleton(EventCorrelationEngineService::class, function (Application $app): EventCorrelationEngineService {
            return new EventCorrelationEngineService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Anomaly Root Cause Analyzer (v48.0.0) — trace anomalies to root causes
        $this->app->singleton(AnomalyRootCauseAnalyzer::class, function (Application $app): AnomalyRootCauseAnalyzer {
            return new AnomalyRootCauseAnalyzer(
                $app->make('cache'),
                $app->make(EventCorrelationEngineService::class),
                $app->make(ConfigRepository::class),
            );
        });

        // Analytics Self-Healing Service (v48.0.0) — automatic pipeline recovery
        $this->app->singleton(AnalyticsSelfHealingService::class, function (Application $app): AnalyticsSelfHealingService {
            $dlqService = $app->bound(DeadLetterQueueService::class) ? $app->make(DeadLetterQueueService::class) : null;
            return new AnalyticsSelfHealingService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
                $app->make(UnifiedHealthEndpointService::class),
                $dlqService,
            );
        });

        // Event Lineage Tracker Service (v49.0.0) — event lifecycle tracing
        $this->app->singleton(EventLineageTrackerService::class, function (Application $app): EventLineageTrackerService {
            return new EventLineageTrackerService(
                $app->make('cache'),
                $app->make(ConfigRepository::class),
            );
        });

        // Event Payload Transformation Engine (v70.0.0) — provider-specific field mapping
        $this->app->singleton(\ZeroBoiler\Analytics\Services\EventTransformationEngine::class, function (Application $app): \ZeroBoiler\Analytics\Services\EventTransformationEngine {
            return new \ZeroBoiler\Analytics\Services\EventTransformationEngine(
                $app->make(ConfigRepository::class),
                $app->make('cache'),
            );
        });

        // Geographic Analytics (v73.0.0)
        $this->app->singleton(GeographicAnalyticsService::class, function (Application $app): GeographicAnalyticsService {
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            /** @var AnalyticsMetrics $metrics */
            $metrics = $app->make(AnalyticsMetrics::class);

            return new GeographicAnalyticsService($cache, $config, $metrics);
        });

        // Experiment Analysis Engine (v75.0.0) — Bayesian + Frequentist hypothesis testing
        $this->app->singleton(ExperimentAnalysisEngine::class, function (Application $app): ExperimentAnalysisEngine {
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new ExperimentAnalysisEngine($cache, $config);
        });

        // Event Contract Testing Engine (v76.0.0) — provider-specific contract validation
        $this->app->singleton(EventContractTestService::class, function (Application $app): EventContractTestService {
            /** @var CacheRepository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventContractTestService($cache, $config);
        });

        // Event Broadcasting Service (v92.0.0) — WebSocket event streaming
        $this->app->singleton(EventBroadcastService::class, function (Application $app): EventBroadcastService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            $broadcaster = $app->bound(\Illuminate\Contracts\Broadcasting\Broadcaster::class)
                ? $app->make(\Illuminate\Contracts\Broadcasting\Broadcaster::class)
                : null;

            return new EventBroadcastService($config, $broadcaster);
        });

        // Heartbeat Monitor (v120.0.0)
        $this->app->singleton(AnalyticsHeartbeatMonitor::class, function (Application $app): AnalyticsHeartbeatMonitor {
            /** @var CacheRepository $cache */
            $cache = $app->make(CacheRepository::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsHeartbeatMonitor($cache, $config);
        });

        // Feature Flag Observer (v120.0.0)
        $this->app->singleton(SaaSFeatureFlagObserver::class, function (Application $app): SaaSFeatureFlagObserver {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new SaaSFeatureFlagObserver($manager, $config);
        });

        // Bundle Event Service (v120.0.0)
        $this->app->singleton(SaaSBundleEventService::class, function (Application $app): SaaSBundleEventService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');

            return new SaaSBundleEventService($manager);
        });

        // Event Compact Serializer (v122.0.0)
        $this->app->singleton(EventCompactSerializer::class, function (Application $app): EventCompactSerializer {
            return new EventCompactSerializer;
        });

        // SDK Telemetry Collector (v122.0.0)
        $this->app->singleton(AnalyticsSdkTelemetryCollector::class, function (Application $app): AnalyticsSdkTelemetryCollector {
            /** @var CacheRepository $cache */
            $cache = $app->make(CacheRepository::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsSdkTelemetryCollector($cache, $config);
        });

        // Projection Registry (v129.0.0) — metric projection definitions
        $this->app->singleton(ProjectionRegistry::class, function (Application $app): ProjectionRegistry {
            /** @var CacheRepository $cache */
            $cache = $app->make(CacheRepository::class);

            return new ProjectionRegistry($cache);
        });

        // Metric Projection Engine (v129.0.0) — evaluates projections against event store
        $this->app->singleton(MetricProjectionEngine::class, function (Application $app): MetricProjectionEngine {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            /** @var CacheRepository $cache */
            $cache = $app->make(CacheRepository::class);
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ProjectionRegistry $registry */
            $registry = $app->make(ProjectionRegistry::class);

            return new MetricProjectionEngine($config, $cache, $manager, $registry);
        });

        // Event Materializer (v129.0.0) — cache-backed materialized views of projected metrics
        $this->app->singleton(EventMaterializer::class, function (Application $app): EventMaterializer {
            /** @var MetricProjectionEngine $engine */
            $engine = $app->make(MetricProjectionEngine::class);
            /** @var ProjectionRegistry $registry */
            $registry = $app->make(ProjectionRegistry::class);

            return new EventMaterializer($engine, $registry);
        });

        // Event Cardinality Limiter (v153.0.0) — prevents high-cardinality dimension explosion
        $this->app->singleton(EventCardinalityLimiter::class, function (Application $app): EventCardinalityLimiter {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventCardinalityLimiter($cache, $config);
        });

        // Event Delivery SLA Monitor (v153.0.0) — proactive per-provider SLA tracking
        $this->app->singleton(EventDeliverySlaMonitor::class, function (Application $app): EventDeliverySlaMonitor {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventDeliverySlaMonitor($cache, $config);
        });

        // Structured Event Logger (v153.0.0) — unified structured logging for analytics
        $this->app->singleton(StructuredEventLogger::class, function (Application $app): StructuredEventLogger {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new StructuredEventLogger($config);
        });

        // Lifecycle Attribution Enricher (v153.0.0) — auto-enrich SaaS lifecycle events
        $this->app->singleton(LifecycleAttributionEnricher::class, function (Application $app): LifecycleAttributionEnricher {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new LifecycleAttributionEnricher($config);
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
                AnalyticsRevenueAttributionCommand::class,
                AnalyticsExportCommand::class,
                RevenueReportCommand::class,
                AnalyticsHealthCommand::class,
                AnalyticsDashboardCommand::class,
                AnalyticsScheduledReportCommand::class,
                AnalyticsReadinessCommand::class,
                AnalyticsSchemaExportCommand::class,
                AnalyticsSchemaCommand::class,
                AnalyticsBehavioralCommand::class,
                AnalyticsArchetypeDriftCommand::class,
                AnalyticsReplayCommand::class,
                AnalyticsCostReportCommand::class,
                AnalyticsPLGScoreCommand::class,
                AnalyticsTimeSeriesCommand::class,
                AnalyticsQuickSetupCommand::class,
                AnalyticsFailoverCommand::class,
                AnalyticsInsightsCommand::class,
                AnalyticsSignalIntelligenceCommand::class,
                AnalyticsIntegrityCommand::class,
                AnalyticsReportCommand::class,
                AnalyticsCohortIntelligenceCommand::class,
                AnalyticsSnapshotCommand::class,
                AnalyticsHealthMonitorCommand::class,
                AnalyticsGuardRailsCommand::class,
                AnalyticsDeliveryCommand::class,
                AnalyticsTimelineCommand::class,
                AnalyticsDiagnosticCommand::class,
                AnalyticsDlqCommand::class,
                SaaSMetricsCommand::class,
                AnalyticsIngestionCommand::class,
                AnalyticsOTLPCommand::class,
                AnalyticsReplayAuditCommand::class,
                AnalyticsMacrosCommand::class,
                AnalyticsDependencyGraphCommand::class,
                AnalyticsSnippetCommand::class,
                AnalyticsSimulationCommand::class,
                AnalyticsSamplingCommand::class,
                AnalyticsFlowCommand::class,
                AnalyticsFraudCommand::class,
                AnalyticsHealthSummaryCommand::class,
                AnalyticsSelfHealCommand::class,
                AnalyticsLineageCommand::class,
                AnalyticsGeoCommand::class,
                AnalyticsRollupCommand::class,
                AnalyticsEncryptionCommand::class,
                AnalyticsUtmCommand::class,
                AnalyticsCohortFunnelCommand::class,
                AnalyticsCoverageCommand::class,
                AnalyticsDebugCommand::class,
                AnalyticsFunnelPrivacyCommand::class,
                AnalyticsTrendForecastCommand::class,
                AnalyticsPipelineValidateCommand::class,
                AnalyticsTransformCommand::class,
                AnalyticsDailyHealthReportCommand::class,
                AnalyticsConsoleCommand::class,
                AnalyticsExperimentCommand::class,
                AnalyticsContractCommand::class,
                AnalyticsRevenueWaterfallCommand::class,
                AnalyticsEventHealthCommand::class,
                AnalyticsDeployGateCommand::class,
                AnalyticsForecastCommand::class,
                AnalyticsGovernanceCommand::class,
                AnalyticsFunnelLeakCommand::class,
                AnalyticsCommandCenterCommand::class,
                AnalyticsReadinessGateCommand::class,
                AnalyticsPrivacyInventoryCommand::class,
                AnalyticsProjectionsCommand::class,
                AnalyticsTranslationMatrixCommand::class,
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
            $router->aliasMiddleware(
                'analytics.first-touch',
                \ZeroBoiler\Analytics\Middleware\FirstTouchUTMMiddleware::class,
            );
            $router->aliasMiddleware(
                'analytics.sdk',
                VerifySdkToken::class,
            );
            $router->aliasMiddleware(
                'analytics.pageview',
                AutoPageViewMiddleware::class,
            );
        }
    }

    /**
     * Register server-side auto-tracking of Laravel events.
     */
    private function registerAutoTracking(): void
    {
        // Register the unified lifecycle event subscriber (v79.0.0)
        // This bridges the config-driven LifecycleEventMapper with the
        // legacy ServerSideTracker and optional queued dispatch.
        try {
            $subscriber = $this->app->make(LifecycleEventSubscriber::class);
            /** @var \Illuminate\Contracts\Events\Dispatcher $dispatcher */
            $dispatcher = $this->app->make('events');
            $subscriber->register($dispatcher);
        } catch (\Throwable) {
            // Fallback: register only the legacy tracker
            $tracker = $this->app->make(ServerSideTracker::class);
            /** @var \Illuminate\Contracts\Events\Dispatcher $dispatcher */
            $dispatcher = $this->app->make('events');
            $tracker->register($dispatcher);
        }

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

        // Boot auto-instrumentation engine (v50.0.0)
        try {
            $engine = $this->app->make(AutoInstrumentationEngine::class);
            $engine->boot();
        } catch (\Throwable) {
            // Non-critical — auto-instrumentation is optional
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

                // Fraud detection endpoint (v47.0.0)
                Route::get('analytics/fraud/metrics', [$controller, 'fraudMetrics']);
                Route::get('analytics/fraud/status', [$controller, 'fraudStatus']);

                // PMF scoring endpoint (v47.0.0)
                Route::get('analytics/pmf/score', [$controller, 'pmfScore']);
                Route::get('analytics/pmf/grade', [$controller, 'pmfGrade']);

                // Unified health endpoint (v47.0.0)
                Route::get('analytics/health/unified', [$controller, 'unifiedHealth']);
                Route::get('analytics/health/liveness', [$controller, 'unifiedLiveness']);
                Route::get('analytics/health/readiness', [$controller, 'unifiedReadiness']);

                // Correlation engine endpoint (v48.0.0)
                Route::get('analytics/correlation/engine/summary', [$controller, 'correlationEngineSummary']);
                Route::get('analytics/correlation/engine/top', [$controller, 'correlationEngineTop']);

                // Root cause analysis endpoint (v48.0.0)
                Route::get('analytics/root-cause/analyze', [$controller, 'rootCauseAnalyze']);
                Route::get('analytics/root-cause/history', [$controller, 'rootCauseHistory']);

                // Self-healing endpoint (v48.0.0)
                Route::get('analytics/self-heal/summary', [$controller, 'selfHealSummary']);
                Route::get('analytics/self-heal/history', [$controller, 'selfHealHistory']);
                Route::post('analytics/self-heal/execute', [$controller, 'selfHealExecute']);
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

                // Lifecycle subscriber diagnostic endpoint (v79.0.0)
                Route::get('analytics/lifecycle/subscriber', [$controller, 'lifecycleSubscriber']);

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

                // Event Data Mart (v7.0.0)
                Route::get('analytics/data-mart/summary', [$controller, 'dataMartSummary']);
                Route::get('analytics/data-mart/top/{dimension}', [$controller, 'dataMartTop']);
                Route::get('analytics/data-mart/by-category', [$controller, 'dataMartByCategory']);
                Route::get('analytics/data-mart/by-event', [$controller, 'dataMartByEvent']);
                Route::get('analytics/data-mart/by-provider', [$controller, 'dataMartByProvider']);
                Route::get('analytics/data-mart/export', [$controller, 'dataMartExport']);
                Route::get('analytics/data-mart/compare', [$controller, 'dataMartCompare']);
                Route::delete('analytics/data-mart', [$controller, 'dataMartClear']);

                // Insight Engine (v7.0.0)
                Route::get('analytics/insights', [$controller, 'insightReport']);
                Route::get('analytics/insights/latest', [$controller, 'insightLatest']);
                Route::get('analytics/insights/health', [$controller, 'insightHealth']);
                Route::get('analytics/insights/severity/{severity}', [$controller, 'insightBySeverity']);

                // SSE Streaming (v2.95.0)
                $sseController = \ZeroBoiler\Analytics\Http\Controllers\AnalyticsSSEController::class;
                Route::get('analytics/sse', [$sseController, 'stream']);
                Route::get('analytics/sse/info', [$sseController, 'info']);
                Route::get('analytics/sse/health', [$sseController, 'health']);

                // Cohort Waterfall Analysis (v7.5.0)
                Route::post('analytics/cohort-waterfall', [$controller, 'cohortWaterfall']);
                Route::post('analytics/cohort-waterfall/summary', [$controller, 'cohortWaterfallSummary']);
                Route::post('analytics/cohort-waterfall/compare', [$controller, 'cohortWaterfallCompare']);
                Route::get('analytics/cohort-waterfall/stages', [$controller, 'cohortWaterfallStages']);

                // Funnel Drop-off Intelligence (v7.5.0)
                Route::post('analytics/funnel-intelligence', [$controller, 'funnelIntelligence']);
                Route::post('analytics/funnel-intelligence/compare', [$controller, 'funnelIntelligenceCompare']);

                // Event Archive (v4.0.0)
                Route::get('analytics/archive', [$controller, 'archiveSearch']);
                Route::get('analytics/archive/stats', [$controller, 'archiveStats']);
                Route::get('analytics/archive/{id}', [$controller, 'archiveGet']);
                Route::post('analytics/archive/{id}/replay', [$controller, 'archiveReplay']);
                Route::delete('analytics/archive', [$controller, 'archiveClear']);

                // Event Governance (v4.1.0)
                Route::get('analytics/governance', [$controller, 'governanceReport']);
                Route::get('analytics/governance/events', [$controller, 'governanceRegistrations']);
                Route::get('analytics/governance/attention', [$controller, 'governanceAttention']);
                Route::get('analytics/governance/naming', [$controller, 'governanceNaming']);
                Route::get('analytics/governance/quality', [$controller, 'governanceQuality']);
                Route::get('analytics/governance/deprecations', [$controller, 'governanceDeprecations']);

                // Guard Rails (v8.9.0)
                Route::get('analytics/guard-rails', [$controller, 'guardRailsCheck']);
                Route::get('analytics/guard-rails/score', [$controller, 'guardRailsScore']);
                Route::get('analytics/guard-rails/violations', [$controller, 'guardRailsViolations']);
                Route::get('analytics/guard-rails/coverage', [$controller, 'guardRailsCoverage']);
                Route::get('analytics/guard-rails/validate-name', [$controller, 'guardRailsValidateName']);

                // Event Router (v37.0.0)
                Route::get('analytics/router/summary', [$controller, 'eventRouterSummary']);
                Route::get('analytics/router/validate', [$controller, 'eventRouterValidate']);
                Route::get('analytics/router/providers', [$controller, 'eventRouterProviders']);

                // Workspace Analytics (v37.0.0)
                Route::get('analytics/workspace/{workspaceId}', [$controller, 'workspaceOverview']);
                Route::get('analytics/workspace/{workspaceId}/active-users', [$controller, 'workspaceActiveUsers']);
                Route::get('analytics/workspace/{workspaceId}/top-events', [$controller, 'workspaceTopEvents']);
                Route::get('analytics/workspace/{workspaceId}/funnels', [$controller, 'workspaceFunnels']);
                Route::get('analytics/workspace/{workspaceId}/revenue', [$controller, 'workspaceRevenue']);
                Route::post('analytics/workspace/compare', [$controller, 'workspaceCompare']);

                // Analytics Rollups (v52.0.0)
                Route::get('analytics/rollup', [$controller, 'rollupQuery']);
                Route::get('analytics/rollup/summary', [$controller, 'rollupSummary']);
                Route::get('analytics/rollup/trend', [$controller, 'rollupTrend']);
                Route::get('analytics/rollup/stats', [$controller, 'rollupStats']);

                // RUM — Real User Monitoring / Web Vitals (v68.0.0)
                Route::post('analytics/vitals', [$controller, 'ingestVitals']);
                Route::get('analytics/vitals/summary', [$controller, 'vitalsSummary']);
                Route::get('analytics/vitals/metric/{metric}', [$controller, 'vitalsMetric']);
                Route::get('analytics/vitals/assessment', [$controller, 'vitalsAssessment']);
                Route::get('analytics/vitals/pages', [$controller, 'vitalsPages']);

                // Event Inspector (v68.0.0)
                Route::get('analytics/inspector/summary', [$controller, 'inspectorSummary']);
                Route::get('analytics/inspector/traces', [$controller, 'inspectorRecentTraces']);
                Route::get('analytics/inspector/trace/{eventId}', [$controller, 'inspectorTrace']);

                // Geographic Analytics (v73.0.0)
                Route::get('analytics/geo/summary', [$controller, 'geoSummary']);
                Route::get('analytics/geo/countries', [$controller, 'geoCountries']);
                Route::get('analytics/geo/regions', [$controller, 'geoRegions']);
                Route::get('analytics/geo/cities', [$controller, 'geoCities']);
                Route::get('analytics/geo/timezones', [$controller, 'geoTimezones']);
                Route::get('analytics/geo/engagement', [$controller, 'geoEngagement']);
                Route::get('analytics/geo/funnel', [$controller, 'geoFunnel']);
                Route::get('analytics/geo/top-events', [$controller, 'geoTopEvents']);
                Route::get('analytics/geo/anomalies', [$controller, 'geoAnomalies']);
                Route::get('analytics/geo/continents', [$controller, 'geoContinents']);

                // Event Contract Testing (v76.0.0)
                Route::get('analytics/contracts', [$controller, 'contractList']);
                Route::get('analytics/contracts/catalog', [$controller, 'contractCatalog']);
                Route::get('analytics/contracts/coverage/{provider}', [$controller, 'contractProviderCoverage']);
                Route::post('analytics/contracts/validate', [$controller, 'contractValidateEvent']);

                // Metric Projections (v129.0.0)
                Route::get('analytics/projections', [$controller, 'projectionList']);
                Route::get('analytics/projections/summary', [$controller, 'projectionSummary']);
                Route::get('analytics/projections/dashboard', [$controller, 'projectionDashboard']);
                Route::get('analytics/projections/{name}', [$controller, 'projectionEvaluate']);
                Route::get('analytics/projections/{name}/history', [$controller, 'projectionHistory']);
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

                // Event Governance management (v4.1.0)
                Route::post('analytics/governance/register', [$controller, 'governanceRegister']);
                Route::post('analytics/governance/activate', [$controller, 'governanceActivate']);
                Route::post('analytics/governance/deprecate', [$controller, 'governanceDeprecate']);
                Route::post('analytics/governance/retire', [$controller, 'governanceRetire']);

                // Event Impact Analytics (v4.2.0)
                Route::post('analytics/impact/calculate', [$controller, 'eventImpactCalculate']);
                Route::post('analytics/impact/conversion-drivers', [$controller, 'eventImpactConversionDrivers']);
                Route::post('analytics/impact/retention-drivers', [$controller, 'eventImpactRetentionDrivers']);

                // Feature Adoption Analytics (v4.2.0)
                Route::get('analytics/adoption/profile/{userId}', [$controller, 'featureAdoptionProfile']);
                Route::post('analytics/adoption/record', [$controller, 'featureAdoptionRecord']);
                Route::post('analytics/adoption/funnel', [$controller, 'featureAdoptionFunnel']);
                Route::get('analytics/adoption/recent/{userId}', [$controller, 'featureAdoptionRecent']);
                Route::get('analytics/adoption/streak/{userId}/{featureName}', [$controller, 'featureAdoptionStreak']);
                Route::delete('analytics/adoption/profile/{userId}', [$controller, 'featureAdoptionClear']);

                // Event Health Scoring (v80.0.0)
                Route::get('analytics/health/event/{eventName}', [$controller, 'eventHealthScore']);
                Route::get('analytics/health/system', [$controller, 'eventHealthSystem']);
                Route::get('analytics/health/events', [$controller, 'eventHealthAll']);
                Route::get('analytics/health/degrading', [$controller, 'eventHealthDegrading']);
                Route::get('analytics/health/alerts', [$controller, 'eventHealthAlerts']);

                // Deploy Gate (v80.0.0)
                Route::post('analytics/deploy-gate', [$controller, 'deployGateEvaluate']);
                Route::get('analytics/deploy-gate/quick', [$controller, 'deployGateQuick']);

                // Funnel Velocity Analyzer (v83.0.0)
                Route::get('analytics/funnel-velocity/{funnelName}', [$controller, 'funnelVelocityReport']);
                Route::get('analytics/funnel-velocity/{funnelName}/{fromStep}/{toStep}', [$controller, 'funnelStepVelocity']);
                Route::get('analytics/funnel-velocity/{funnelName}/dropout', [$controller, 'funnelDropoutAnalysis']);
                Route::get('analytics/funnel-velocity/{funnelName}/predict/{step}', [$controller, 'funnelPredictCompletion']);

                // Privacy-Aware Event Router (v83.0.0)
                Route::post('analytics/privacy/route', [$controller, 'privacyRouteEvent']);
                Route::post('analytics/privacy/route-batch', [$controller, 'privacyRouteBatch']);
                Route::get('analytics/privacy/zones', [$controller, 'privacyZones']);
                Route::get('analytics/privacy/zone/{zone}/blocked-fields', [$controller, 'privacyBlockedFields']);
                Route::get('analytics/privacy/zone/{zone}/providers', [$controller, 'privacyAllowedProviders']);

                // Revenue Signal Detector (v83.0.0)
                Route::get('analytics/signals/churn/{userId}', [$controller, 'revenueChurnScore']);
                Route::get('analytics/signals/expansion/{userId}', [$controller, 'revenueExpansionScore']);
                Route::get('analytics/signals/report/{userId}', [$controller, 'revenueSignalReport']);
                Route::get('analytics/signals/top-at-risk', [$controller, 'revenueTopAtRisk']);
                Route::get('analytics/signals/top-expansion', [$controller, 'revenueTopExpansion']);

                // Conversion Path Discovery (v83.0.0)
                Route::get('analytics/conversion-paths/{funnelName}/top', [$controller, 'conversionPathTop']);
                Route::get('analytics/conversion-paths/{funnelName}/drop-offs', [$controller, 'conversionPathDropOffs']);
                Route::get('analytics/conversion-paths/{funnelName}/steps', [$controller, 'conversionPathSteps']);
                Route::get('analytics/conversion-paths/{funnelName}/summary', [$controller, 'conversionPathSummary']);
                Route::post('analytics/conversion-paths/compare', [$controller, 'conversionPathCompare']);
                Route::post('analytics/conversion-paths/step', [$controller, 'conversionPathRecordStep']);
                Route::post('analytics/conversion-paths/convert', [$controller, 'conversionPathConvert']);
                Route::post('analytics/conversion-paths/abandon', [$controller, 'conversionPathAbandon']);
            });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return list<string>
     */
    #[\Override]
    public function provides(): array
    {
        return [
            'zeroboiler.analytics',
            AnalyticsManager::class,
            AnalyticsConfig::class,
            EventSchemaRegistryExtended::class,
        ];
    }
}
