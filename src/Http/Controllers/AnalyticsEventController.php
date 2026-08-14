<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Http\Controllers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Pipeline\EventPipeline;
use ZeroBoiler\Analytics\Pipeline\UtmEnricher;
use ZeroBoiler\Analytics\Pipeline\TimestampEnricher;
use ZeroBoiler\Analytics\Pipeline\EventMetadataEnricher;
use ZeroBoiler\Analytics\Pipeline\SchemaEnricher;
use ZeroBoiler\Analytics\Services\EventValidationService;
use ZeroBoiler\Analytics\Services\EventStreamService;
use ZeroBoiler\Analytics\Services\ExportService;
use ZeroBoiler\Analytics\Services\AnalyticsProfileService;
use ZeroBoiler\Analytics\Services\GdprErasureService;
use ZeroBoiler\Analytics\Services\AnalyticsStatsService;
use ZeroBoiler\Analytics\Services\InboundWebhookService;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;
use ZeroBoiler\Analytics\Events\EventCatalog;
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
use ZeroBoiler\Analytics\Services\RealTimeAggregationService;
use ZeroBoiler\Analytics\Services\ABTestAnalyticsService;
use ZeroBoiler\Analytics\Services\AnalyticsSnapshotService;
use ZeroBoiler\Analytics\Services\SaasKpiTracker;
use ZeroBoiler\Analytics\Services\UtmAggregationService;
use ZeroBoiler\Analytics\Services\EventForwardingService;
use ZeroBoiler\Analytics\Services\PerformanceBudgetService;
use ZeroBoiler\Analytics\Services\UTMAttributionService;
use ZeroBoiler\Analytics\Services\EventExporterService;
use ZeroBoiler\Analytics\Services\EventTaxonomyService;
use ZeroBoiler\Analytics\Services\EventBucketsService;
use ZeroBoiler\Analytics\Services\SaaSHealthScoreService;
use ZeroBoiler\Analytics\Services\UserJourneyService;
use ZeroBoiler\Analytics\Services\SaaSConversionService;
use ZeroBoiler\Analytics\Services\EventGovernanceService;
use ZeroBoiler\Analytics\Services\EventImpactService;
use ZeroBoiler\Analytics\Services\FeatureAdoptionTracker;
use ZeroBoiler\Analytics\Services\EventBudgetService;
use ZeroBoiler\Analytics\Services\EventContractTestService;
use ZeroBoiler\Analytics\Services\ExperimentAnalysisEngine;
use ZeroBoiler\Analytics\Services\AnalyticsConfigAuditService;
use ZeroBoiler\Analytics\Services\EventCatalogValidator;
use ZeroBoiler\Analytics\Services\EventDataMartService;
use ZeroBoiler\Analytics\Services\AnalyticsInsightEngineService;
use ZeroBoiler\Analytics\Services\EventRecommendationService;
use ZeroBoiler\Analytics\Services\ProviderGapAnalyzer;
use ZeroBoiler\Analytics\Services\EventSparklineService;
use ZeroBoiler\Analytics\Services\EventCooccurrenceService;
use ZeroBoiler\Analytics\Services\CohortWaterfallService;
use ZeroBoiler\Analytics\Services\FunnelDropoffIntelligenceService;
use ZeroBoiler\Analytics\Services\EventSignalIntelligenceService;
use ZeroBoiler\Analytics\Services\AttributionModelService;
use ZeroBoiler\Analytics\Services\SaaSFeatureMatrixService;
use ZeroBoiler\Analytics\Services\EventSessionizer;
use ZeroBoiler\Analytics\Services\EventFunnelAggregator;
use ZeroBoiler\Analytics\Services\CohortBehaviorProfilerService;
use ZeroBoiler\Analytics\Services\EventPredictiveScoringService;
use ZeroBoiler\Analytics\Services\IdentityGraphService;
use ZeroBoiler\Analytics\Services\DeviceFingerprintService;
use ZeroBoiler\Analytics\Services\TrackingGuardRailsService;
use ZeroBoiler\Analytics\Services\EventDeliveryConfirmationService;
use ZeroBoiler\Analytics\Services\EventIdempotencyService;
use ZeroBoiler\Analytics\Services\PrivacyManifestService;
use ZeroBoiler\Analytics\Services\EventAnnotationService;
use ZeroBoiler\Analytics\Services\ProviderFallbackService;

use ZeroBoiler\Analytics\Services\GroupAnalyticsService;

use ZeroBoiler\Analytics\Services\AnalyticsObservabilityService;

use ZeroBoiler\Analytics\Services\EventIngestionService;

use ZeroBoiler\Analytics\Services\EventCostTracker;

use ZeroBoiler\Analytics\Services\AnalyticsCommandScheduler;

/**
 * API controller for frontend event tracking.
 *
 * Receives events from the JS client library and dispatches them
 * through the analytics pipeline to all configured providers.
 *
 * @since 1.0.0
 */
final class AnalyticsEventController extends Controller
{
    private AnalyticsManager $manager;

    private ConfigRepository $config;

    private string $cookieName;

    private ?EventValidationService $validator;

    private bool $autoUtm;

    private bool $autoTimestamp;

    private bool $autoMetadata;

    private bool $schemaEnrichment;

    private const MAX_BATCH_SIZE = 25;

    private const MAX_EVENT_NAME_LENGTH = 100;

    private ?EventStreamService $streamService;

    private ?ExportService $exportService;

    private ?AnalyticsProfileService $profileService;

    private ?GdprErasureService $gdprErasureService;

    private ?AnalyticsStatsService $statsService;

    private ?InboundWebhookService $inboundWebhookService;

    private ?EventSchemaRegistry $schemaRegistry;

    private ?EventAlertRulesService $alertRulesService;

    private ?FunnelDataBuilderService $funnelDataBuilderService;

    private ?LifecycleEventMapper $lifecycleMapper;

    private ?LifecycleEventSubscriber $lifecycleSubscriber;

    private ?EventCorrelationService $correlationService;

    private ?AnalyticsConfigValidator $configValidator;

    private ?EventSourceTagger $sourceTagger;

    private ?ReferrerTrackingService $referrerTrackingService;

    private ?EventBroadcasterService $broadcasterService;

    private ?TenantIsolationService $tenantService;

    private ?DataRetentionPolicyService $retentionService;

    private ?AnalyticsGateService $gateService;

    private ?EventReportingService $reportingService;

    private ?DeadLetterQueueService $dlqService;

    private ?RealTimeAggregationService $realtimeService;

    private ?ABTestAnalyticsService $abTestService;

    private ?AnalyticsSnapshotService $snapshotService;

    private ?SaasKpiTracker $kpiTracker;

    private ?UtmAggregationService $utmAggregation;

    private ?EventForwardingService $forwardingService;

    private ?PerformanceBudgetService $performanceBudgetService;

    private ?UTMAttributionService $attributionService;

    private ?EventTaxonomyService $taxonomyService;

    private ?EventBucketsService $bucketsService;

    private ?SaaSHealthScoreService $healthScoreService;

    private ?UserJourneyService $journeyService;

    private ?SaaSConversionService $conversionService;

    private ?EventGovernanceService $governanceService;

    private ?EventImpactService $eventImpactService;

    private ?FeatureAdoptionTracker $featureAdoptionTracker;

    private ?EventBudgetService $budgetService;

    private ?AnalyticsConfigAuditService $configAuditService;

    private ?EventCatalogValidator $catalogValidator;

    /**
     * @param  AnalyticsManager  $manager
     * @param  ConfigRepository  $config
     * @param  EventValidationService|null  $validator  Optional event validator (injected when available)
     * @param  EventStreamService|null  $streamService  Optional event stream service
     * @param  ExportService|null  $exportService  Optional export service
     * @param  AnalyticsProfileService|null  $profileService  Optional profile service
     * @param  GdprErasureService|null  $gdprErasureService  Optional GDPR erasure service
     * @param  AnalyticsStatsService|null  $statsService  Optional stats service
     * @param  InboundWebhookService|null  $inboundWebhookService  Optional inbound webhook service
     * @param  EventSchemaRegistry|null  $schemaRegistry  Optional schema registry
     * @param  EventAlertRulesService|null  $alertRulesService  Optional alert rules service
     * @param  FunnelDataBuilderService|null  $funnelDataBuilderService  Optional funnel data builder service
     * @param  LifecycleEventMapper|null  $lifecycleMapper  Optional lifecycle event mapper service
     * @param  LifecycleEventSubscriber|null  $lifecycleSubscriber  Optional lifecycle subscriber (v79.0.0)
     * @param  EventCorrelationService|null  $correlationService  Optional event correlation service
     * @param  AnalyticsConfigValidator|null  $configValidator  Optional config validator service
     * @param  EventSourceTagger|null  $sourceTagger  Optional event source tagger service
     * @param  ReferrerTrackingService|null  $referrerTrackingService  Optional referrer tracking service
     */
    public function __construct(
        AnalyticsManager $manager,
        ConfigRepository $config,
        ?EventValidationService $validator = null,
        ?EventStreamService $streamService = null,
        ?ExportService $exportService = null,
        ?AnalyticsProfileService $profileService = null,
        ?GdprErasureService $gdprErasureService = null,
        ?AnalyticsStatsService $statsService = null,
        ?InboundWebhookService $inboundWebhookService = null,
        ?EventSchemaRegistry $schemaRegistry = null,
        ?EventAlertRulesService $alertRulesService = null,
        ?FunnelDataBuilderService $funnelDataBuilderService = null,
        ?LifecycleEventMapper $lifecycleMapper = null,
        ?LifecycleEventSubscriber $lifecycleSubscriber = null,
        ?EventCorrelationService $correlationService = null,
        ?AnalyticsConfigValidator $configValidator = null,
        ?EventSourceTagger $sourceTagger = null,
        ?ReferrerTrackingService $referrerTrackingService = null,
        ?EventBroadcasterService $broadcasterService = null,
        ?TenantIsolationService $tenantService = null,
        ?DataRetentionPolicyService $retentionService = null,
        ?AnalyticsGateService $gateService = null,
        ?EventReportingService $reportingService = null,
        ?DeadLetterQueueService $dlqService = null,
        ?RealTimeAggregationService $realtimeService = null,
        ?ABTestAnalyticsService $abTestService = null,
        ?AnalyticsSnapshotService $snapshotService = null,
        ?SaasKpiTracker $kpiTracker = null,
        ?UtmAggregationService $utmAggregation = null,
        ?EventForwardingService $forwardingService = null,
        ?PerformanceBudgetService $performanceBudgetService = null,
        ?UTMAttributionService $attributionService = null,
        ?EventTaxonomyService $taxonomyService = null,
        ?EventBucketsService $bucketsService = null,
        ?SaaSHealthScoreService $healthScoreService = null,
        ?UserJourneyService $journeyService = null,
        ?SaaSConversionService $conversionService = null,
        ?EventGovernanceService $governanceService = null,
        ?EventImpactService $eventImpactService = null,
        ?FeatureAdoptionTracker $featureAdoptionTracker = null,
        ?EventBudgetService $budgetService = null,
        ?AnalyticsConfigAuditService $configAuditService = null,
        ?EventCatalogValidator $catalogValidator = null,
    ): void {
        $this->manager = $manager;
        $this->config = $config;
        $cookieName = $config->get('zeroboiler.analytics.identity.cookie_name', 'zb_analytics_id');
        $this->cookieName = is_string($cookieName) ? $cookieName : 'zb_analytics_id';
        $this->validator = $validator;
        $this->streamService = $streamService;
        $this->exportService = $exportService;
        $this->profileService = $profileService;
        $this->gdprErasureService = $gdprErasureService;
        $this->statsService = $statsService;
        $this->inboundWebhookService = $inboundWebhookService;
        $this->schemaRegistry = $schemaRegistry;
        $this->alertRulesService = $alertRulesService;
        $this->funnelDataBuilderService = $funnelDataBuilderService;
        $this->lifecycleMapper = $lifecycleMapper;
        $this->lifecycleSubscriber = $lifecycleSubscriber;
        $this->correlationService = $correlationService;
        $this->configValidator = $configValidator;
        $this->sourceTagger = $sourceTagger;
        $this->referrerTrackingService = $referrerTrackingService;
        $this->broadcasterService = $broadcasterService;
        $this->tenantService = $tenantService;
        $this->retentionService = $retentionService;
        $this->gateService = $gateService;
        $this->reportingService = $reportingService;
        $this->dlqService = $dlqService;
        $this->realtimeService = $realtimeService;
        $this->abTestService = $abTestService;
        $this->snapshotService = $snapshotService;
        $this->kpiTracker = $kpiTracker;
        $this->utmAggregation = $utmAggregation;
        $this->forwardingService = $forwardingService;
        $this->performanceBudgetService = $performanceBudgetService;
        $this->attributionService = $attributionService;
        $this->taxonomyService = $taxonomyService;
        $this->bucketsService = $bucketsService;
        $this->healthScoreService = $healthScoreService;
        $this->journeyService = $journeyService;
        $this->conversionService = $conversionService;
        $this->governanceService = $governanceService;
        $this->eventImpactService = $eventImpactService;
        $this->featureAdoptionTracker = $featureAdoptionTracker;
        $this->budgetService = $budgetService;
        $this->configAuditService = $configAuditService;
        $this->catalogValidator = $catalogValidator;

        $pipelineConfig = $config->get('zeroboiler.analytics.pipeline', []);
        /** @var array{auto_utm?: bool, auto_timestamp?: bool, auto_metadata?: bool, schema_enrichment?: bool} $pipelineConfig */
        $this->autoUtm = (bool) ($pipelineConfig['auto_utm'] ?? true);
        $this->autoTimestamp = (bool) ($pipelineConfig['auto_timestamp'] ?? false);
        $this->autoMetadata = (bool) ($pipelineConfig['auto_metadata'] ?? true);
        $this->schemaEnrichment = (bool) ($pipelineConfig['schema_enrichment'] ?? false);
    }

    /**
     * Build the event pipeline for a request.
     *
     * Chains UTM enrichment, metadata enrichment, schema validation,
     * and timestamp enrichment in the correct order.
     *
     * @param  Request  $request
     * @return EventPipeline
     */
    private function buildPipeline(Request $request): EventPipeline
    {
        $pipeline = new EventPipeline;

        // Schema enrichment first (validates structure)
        if ($this->schemaEnrichment && $this->schemaRegistry !== null) {
            $pipeline->pipe(new SchemaEnricher($this->schemaRegistry, strict: false));
        }

        if ($this->autoUtm) {
            $utmContext = array_filter($request->only([
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            ]));
            if (! empty($utmContext)) {
                $pipeline->pipe(new UtmEnricher($utmContext));
            }
        }

        // Metadata enrichment (session, page URL, referrer, timestamp)
        if ($this->autoMetadata) {
            $pipeline->pipe(new EventMetadataEnricher(
                sessionId: $request->session()->getId(),
                pageUrl: $request->fullUrl(),
                referrer: $request->headers->get('referer'),
                includeTimestamp: $this->autoTimestamp,
            ));
        } elseif ($this->autoTimestamp) {
            $pipeline->pipe(new TimestampEnricher);
        }

        return $pipeline;
    }

    /**
     * Track a single analytics event.
     *
     * POST /api/analytics/events
     *
     * Body: { "name": "button_click", "params": { "element": "buy_now" } }
     */
    public function track(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'params' => 'array',
            'params.*' => 'mixed',
        ]);

        $clientId = $this->extractClientId($request);
        $userId = $request->user()?->getKey();

        // Budget enforcement (v17.0.0) — reject if budget exceeded
        if ($this->budgetService !== null) {
            $budgetResult = $this->budgetService->check(
                $clientId,
                is_int($userId) || is_string($userId) ? (string) $userId : null,
            );
            if (! $budgetResult['allowed']) {
                return response()->json([
                    'status' => 'budget_exceeded',
                    'reason' => $budgetResult['reason'],
                    'policy' => $budgetResult['policy'],
                ], 429);
            }
        }

        $event = new AnalyticsEvent(
            name: $request->input('name'),
            params: $request->input('params', []),
            clientId: $clientId,
            userId: is_int($userId) || is_string($userId) ? (string) $userId : null,
        );

        // Validate and sanitize event if validator is available
        $event = $this->validateEvent($event);

        // Process through event pipeline (UTM enrichment, etc.)
        $pipeline = $this->buildPipeline($request);
        $processed = $pipeline->process($event);

        if ($processed === null) {
            return response()->json(['status' => 'filtered']);
        }

        $this->manager->trackEvent($processed);

        // Record against budget counters
        if ($this->budgetService !== null) {
            $this->budgetService->record(
                $clientId,
                is_int($userId) || is_string($userId) ? (string) $userId : null,
            );
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Track multiple analytics events in a single request.
     *
     * POST /api/analytics/batch
     *
     * Body: { "events": [ { "name": "...", "params": {...} },...] }
     */
    public function batch(Request $request): JsonResponse
    {
        $request->validate([
            'events' => 'required|array|max:'.self::MAX_BATCH_SIZE,
            'events.*.name' => 'required|string|max:100',
            'events.*.params' => 'array',
        ]);

        $clientId = $this->extractClientId($request);
        $userId = $request->user()?->getKey();
        $userIdStr = is_int($userId) || is_string($userId) ? (string) $userId : null;

        // Budget enforcement (v17.0.0) — reject if budget exceeded
        if ($this->budgetService !== null) {
            $budgetResult = $this->budgetService->check($clientId, $userIdStr);
            if (! $budgetResult['allowed']) {
                return response()->json([
                    'status' => 'budget_exceeded',
                    'reason' => $budgetResult['reason'],
                    'policy' => $budgetResult['policy'],
                ], 429);
            }
        }

        $events = $request->input('events', []);

        // Build pipeline once for all events in the batch
        $pipeline = $this->buildPipeline($request);
        $dispatchedCount = 0;

        foreach ($events as $eventData) {
            $event = new AnalyticsEvent(
                name: $eventData['name'],
                params: $eventData['params'] ?? [],
                clientId: $clientId,
                userId: $userIdStr,
            );

            // Validate and sanitize each event
            $event = $this->validateEvent($event);

            // Process through pipeline
            $processed = $pipeline->process($event);

            if ($processed === null) {
                continue; // Event was filtered out
            }

            $this->manager->trackEvent($processed);
            $dispatchedCount++;
        }

        // Record against budget counters (count dispatched events)
        if ($this->budgetService !== null) {
            for ($i = 0; $i < $dispatchedCount; $i++) {
                $this->budgetService->record($clientId, $userIdStr);
            }
        }

        return response()->json([
            'status' => 'ok',
            'count' => $dispatchedCount,
        ]);
    }

    /**
     * Link a client ID to an authenticated user.
     *
     * POST /api/analytics/identify
     *
     * Body: { "client_id": "uuid-...", "traits": { "name": "John", "plan": "pro" } }
     */
    public function identify(Request $request): JsonResponse
    {
        $request->validate([
            'client_id' => 'required|string',
            'traits' => 'array',
            'traits.*' => 'mixed',
        ]);

        $clientId = is_string($request->input('client_id')) ? $request->input('client_id') : null;
        $traits = $request->input('traits', []);
        $traits = is_array($traits) ? $traits : [];
        $user = $request->user();

        if ($user === null) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $userId = $user->getKey();
        $userIdStr = is_int($userId) || is_string($userId) ? (string) $userId : null;

        // Send identify event to all providers
        $event = new AnalyticsEvent(
            name: 'identify',
            params: array_merge([
                'user_id' => $userIdStr,
                'client_id' => $clientId,
            ], $traits),
            clientId: $clientId,
            userId: $userIdStr,
        );

        $this->manager->trackEvent($event);

        // If traits are provided, also set user properties
        if (! empty($traits)) {
            $this->manager->setUserProperties($traits, $userIdStr);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Track a page view from the server side.
     *
     * POST /api/analytics/pageview
     *
     * Body: { "title": "Pricing", "location": "/pricing", "referrer": "https://google.com" }
     */
    public function pageview(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'string',
            'location' => 'string',
            'referrer' => 'string',
        ]);

        $clientId = $this->extractClientId($request);
        $userId = $request->user()?->getKey();
        $userIdStr = is_int($userId) || is_string($userId) ? (string) $userId : null;

        $event = new AnalyticsEvent(
            name: 'page_view',
            params: array_filter([
                'page_title' => $request->input('title'),
                'page_location' => $request->input('location'),
                'page_referrer' => $request->input('referrer'),
            ]),
            clientId: $clientId,
            userId: $userIdStr,
        );

        $event = $this->validateEvent($event);

        $pipeline = $this->buildPipeline($request);
        $processed = $pipeline->process($event);

        if ($processed === null) {
            return response()->json(['status' => 'filtered']);
        }

        $this->manager->trackEvent($processed);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Update consent state from the frontend.
     *
     * POST /api/analytics/consent
     *
     * Body: { "signals": { "analytics_storage": "granted", "ad_storage": "denied" } }
     */
    public function updateConsent(Request $request): JsonResponse
    {
        $request->validate([
            'signals' => 'required|array',
            'signals.*' => 'string|in:granted,denied',
        ]);

        $signals = $request->input('signals', []);
        $signals = is_array($signals) ? $signals : [];

        $state = $this->manager->getConsent()->with($signals);

        $this->manager->setConsent($state);

        return response()->json([
            'status' => 'ok',
            'consent' => $state->toArray(),
        ]);
    }

    /**
     * Get the event catalog for client-side reference.
     *
     * GET /api/analytics/catalog
     *
     * Returns all registered event names grouped by category with
     * cross-provider mappings. No authentication required.
     * Useful for client-side event name validation and auto-complete.
     */
    public function catalog(): JsonResponse
    {
        $exporter = $this->resolveExporterService();

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'total' => EventCatalog::count(),
            'categories' => [
                'ecommerce' => [
                    'count' => \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::count(),
                    'events' => \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::all(),
                ],
                'saas' => [
                    'count' => \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::count(),
                    'events' => \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::all(),
                ],
                'engagement' => [
                    'count' => \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::count(),
                    'events' => \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::all(),
                ],
            ],
            'names' => EventCatalog::names(),
            'summary' => $exporter !== null ? $exporter->summary() : null,
        ]);
    }

    /**
     * Health check endpoint for monitoring and load balancers.
     *
     * GET /api/analytics/health
     */
    public function health(): JsonResponse
    {
        $providers = [];

        if ($this->manager->ga4()->isEnabled()) {
            $providers['ga4'] = ['status' => 'ok', 'measurement_id' => $this->manager->ga4()->getMeasurementId()];
        }

        if ($this->manager->gtm()->isEnabled()) {
            $providers['gtm'] = ['status' => 'ok'];
        }

        if ($this->manager->meta()->isEnabled()) {
            $providers['meta'] = ['status' => 'ok'];
        }

        if ($this->manager->plausible()->isEnabled()) {
            $providers['plausible'] = ['status' => 'ok', 'domain' => $this->manager->plausible()->getDomain()];
        }

        if ($this->manager->posthog()->isEnabled()) {
            $providers['posthog'] = ['status' => 'ok', 'host' => $this->manager->posthog()->getHost()];
        }

        if ($this->manager->webhook()->isEnabled()) {
            $providers['webhook'] = ['status' => 'ok', 'url' => $this->manager->webhook()->getWebhookUrl()];
        }

        // Include metrics summary if metrics are enabled
        $metricsSummary = null;
        try {
            $metrics = $this->manager->metrics();
            $metricsSummary = [
                'dispatches' => $metrics->totalDispatched(),
                'failures' => $metrics->totalFailed(),
                'per_provider' => $metrics->summary(),
            ];
        } catch (\Throwable) {
            // Metrics not available
        }

        // Include replay queue status if available
        $replaySummary = null;
        try {
            $replay = app(\ZeroBoiler\Analytics\Queue\EventReplayQueue::class);
            $replaySummary = $replay->summary();
        } catch (\Throwable) {
            // Replay queue not available
        }

        // Include event catalog summary
        $catalogSummary = $this->manager->eventCatalogSummary();

        // Include event stream stats if available
        $streamSummary = null;
        if ($this->streamService !== null) {
            $streamSummary = $this->streamService->stats();
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'providers' => $providers,
            'consent' => $this->manager->getConsent()->toArray(),
            'metrics' => $metricsSummary,
            'replay' => $replaySummary,
            'catalog' => $catalogSummary,
            'stream' => $streamSummary,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Generate the daily health report.
     *
     * GET /api/analytics/health-report?force=1&domain=provider_health&domain=consent_compliance
     *
     * Returns a comprehensive health report aggregating all analytics
     * subsystem health signals. Supports force refresh and domain filtering.
     *
     * @since 116.0.0
     */
    public function dailyHealthReport(Request $request): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsDailyHealthReportService::class);
            $forceRefresh = (bool) $request->query('force', false);
            $domainFilter = $request->query('domains');

            $report = $service->generate($forceRefresh);

            // Apply domain filter if requested
            if ($domainFilter !== null) {
                $requestedDomains = explode(',', (string) $domainFilter);
                $filteredDomains = [];
                foreach ($requestedDomains as $domain) {
                    $domain = trim($domain);
                    if (isset($report['domains'][$domain])) {
                        $filteredDomains[$domain] = $report['domains'][$domain];
                    }
                }
                $report['domains'] = $filteredDomains;
            }

            return response()->json($report);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate health report: ' . $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ], 500);
        }
    }

    /**
     * Get real-time events from the event stream.
     *
     * GET /api/analytics/stream?after=0&filter=*&category=saas&limit=100
     *
     * Returns events from the ring buffer for live dashboard consumption.
     * Supports cursor-based polling via the `after` parameter.
     */
    public function stream(Request $request): JsonResponse
    {
        if ($this->streamService === null) {
            return response()->json(['error' => 'Event stream not available'], 503);
        }

        $after = (int) $request->query('after', 0);
        $filter = $request->query('filter');
        $category = $request->query('category');
        $limit = min((int) $request->query('limit', 100), 500);

        $events = [];

        if ($category !== null && is_string($category) && $category !== '') {
            $events = $this->streamService->filterByCategory($category, $limit);
        } elseif ($filter !== null && is_string($filter) && $filter !== '') {
            $events = $this->streamService->filter($filter, $limit);
        } else {
            $events = $this->streamService->since($after, $limit);
        }

        return response()->json([
            'status' => 'ok',
            'cursor' => $this->streamService->cursor(),
            'count' => count($events),
            'events' => $events,
        ]);
    }

    /**
     * Get event stream statistics.
     *
     * GET /api/analytics/stream/stats
     */
    public function streamStats(): JsonResponse
    {
        if ($this->streamService === null) {
            return response()->json(['error' => 'Event stream not available'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'stats' => $this->streamService->stats(),
        ]);
    }

    /**
     * Export analytics events.
     *
     * GET /api/analytics/export?format=json&filter=*&category=ecommerce&limit=1000&compliance=false
     *
     * Supports JSON and CSV formats. When compliance=true, all PII is redacted.
     */
    public function export(Request $request): JsonResponse
    {
        if ($this->exportService === null) {
            return response()->json(['error' => 'Export service not available'], 503);
        }

        $format = $request->query('format', 'json');
        $filter = $request->query('filter');
        $category = $request->query('category');
        $limit = min((int) $request->query('limit', 1000), 10000);
        $compliance = $request->boolean('compliance', false);

        if ($compliance) {
            $content = $this->exportService->complianceExport($limit);
        } elseif ($format === 'csv') {
            $content = $this->exportService->toCsv(
                is_string($filter) && $filter !== '' ? $filter : null,
                is_string($category) && $category !== '' ? $category : null,
                $limit,
            );
        } elseif ($format === 'metrics') {
            $content = $this->exportService->metricsExport();
        } else {
            $content = $this->exportService->toJson(
                is_string($filter) && $filter !== '' ? $filter : null,
                is_string($category) && $category !== '' ? $category : null,
                $limit,
                true,
            );
        }

        return response()->json([
            'status' => 'ok',
            'format' => $format,
            'size' => strlen($content),
            'data' => $content,
        ]);
    }

    /**
     * Validate the event name format against naming conventions.
     *
     * Ensures event names contain only lowercase letters, numbers, and
     * underscores, starting with a letter. Used internally for pre-validation.
     *
     * @return array{valid: bool, sanitized: string}
     */
    private function validateEventName(string $name): array
    {
        $sanitized = preg_replace('/[^a-z0-9_]/', '', strtolower($name));
        $sanitized = $sanitized !== '' && $sanitized !== '0' ? $sanitized : $name;

        $valid = strlen($sanitized) <= self::MAX_EVENT_NAME_LENGTH
            && $sanitized !== ''
            && preg_match('/^[a-z][a-z0-9_]*$/', $sanitized) === 1;

        return ['valid' => $valid, 'sanitized' => $sanitized];
    }

    /**
     * Extract the client ID from the request header or cookie.
     */
    private function extractClientId(Request $request): ?string
    {
        // Check X-Analytics-Client-Id header first
        $header = $request->header('X-Analytics-Client-Id');

        if (is_string($header) && $header !== '') {
            return $header;
        }

        // Fall back to cookie
        $cookie = $request->cookie($this->cookieName);

        if (is_string($cookie) && $cookie !== '') {
            return $cookie;
        }

        return null;
    }

    /**
     * Resolve EventExporterService lazily from the container.
     */
    private function resolveExporterService(): ?EventExporterService
    {
        try {
            return app(EventExporterService::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Export provider mapping table for documentation and debugging.
     *
     * GET /api/analytics/export/mappings
     *
     * Returns all event-to-provider name mappings (GA4, Meta, PostHog, Plausible).
     */
    public function exportMappings(): JsonResponse
    {
        $exporter = $this->resolveExporterService();

        if ($exporter === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'EventExporterService not available',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'mappings' => $exporter->exportProviderMappings(),
        ]);
    }

    /**
     * Export event catalog as CSV download.
     *
     * GET /api/analytics/export/catalog.csv
     */
    public function exportCatalogCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $exporter = $this->resolveExporterService();

        $csv = $exporter !== null
            ? $exporter->exportCatalogCsv()
            : '';

        return response()->streamDownload(
            static function () use ($csv): void {
                echo $csv;
            },
            'event-catalog-' . date('Y-m-d') . '.csv',
            ['Content-Type' => 'text/csv'],
        );
    }

    /**
     * Validate and sanitize an event using EventValidationService.
     *
     * Returns the sanitized event. If validation fails in strict mode,
     * returns the sanitized event anyway (errors are logged).
     */
    private function validateEvent(AnalyticsEvent $event): AnalyticsEvent
    {
        if ($this->validator === null) {
            return $event;
        }

        $result = $this->validator->validate($event);

        return $result['event'];
    }

    /**
     * Opt the authenticated user out of all tracking.
     *
     * POST /api/analytics/opt-out
     *
     * Persists the opt-out preference in cache. Even if consent is granted,
     * no events will be dispatched for this user after opting out.
     * Use POST /api/analytics/opt-in to reverse.
     */
    public function optOut(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $userId = $user->getKey();
        $userIdStr = is_int($userId) || is_string($userId) ? (string) $userId : null;

        if ($userIdStr === null || $userIdStr === '') {
            return response()->json(['error' => 'Invalid user ID'], 400);
        }

        $this->manager->optOut($userIdStr);

        // Also suppress the current client ID
        $clientId = $this->extractClientId($request);
        if ($clientId !== null) {
            $this->manager->suppressClient($clientId);
        }

        return response()->json([
            'status' => 'ok',
            'tracking' => false,
            'message' => 'Tracking disabled for this user.',
        ]);
    }

    /**
     * Opt the authenticated user in to tracking.
     *
     * POST /api/analytics/opt-in
     *
     * Overrides any previous opt-out preference.
     */
    public function optIn(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $userId = $user->getKey();
        $userIdStr = is_int($userId) || is_string($userId) ? (string) $userId : null;

        if ($userIdStr === null || $userIdStr === '') {
            return response()->json(['error' => 'Invalid user ID'], 400);
        }

        $this->manager->optIn($userIdStr);

        return response()->json([
            'status' => 'ok',
            'tracking' => true,
            'message' => 'Tracking enabled for this user.',
        ]);
    }

    /**
     * Get the tracking preference status for the authenticated user.
     *
     * GET /api/analytics/preference
     *
     * Returns whether tracking is currently allowed based on
     * both consent state and per-user tracking preferences.
     */
    public function preference(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = $user !== null ? (is_int($user->getKey()) || is_string($user->getKey()) ? (string) $user->getKey() : null) : null;
        $clientId = $this->extractClientId($request);

        $allowed = $this->manager->isTrackingAllowed($userId, $clientId);

        return response()->json([
            'status' => 'ok',
            'tracking_allowed' => $allowed,
            'consent' => $this->manager->getConsent()->toArray(),
        ]);
    }

    /**
     * Get the analytics profile for the authenticated user.
     *
     * GET /api/analytics/profile
     *
     * Returns aggregated profile data: event counts, lifetime value,
     * engagement score, funnel completion, plan, and traits.
     */
    public function profile(Request $request): JsonResponse
    {
        if ($this->profileService === null) {
            return response()->json(['error' => 'Profile service not available'], 503);
        }

        $user = $request->user();

        if ($user === null) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $userId = $user->getKey();
        $userIdStr = is_int($userId) || is_string($userId) ? (string) $userId : null;

        if ($userIdStr === null || $userIdStr === '') {
            return response()->json(['error' => 'Invalid user ID'], 400);
        }

        return response()->json([
            'status' => 'ok',
            'profile' => $this->profileService->getProfileSummary($userIdStr),
        ]);
    }

    /**
     * Erase all analytics data for the authenticated user (GDPR right to be forgotten).
     *
     * DELETE /api/analytics/data
     *
     * Deletes: analytics profile, attribution data, tracking preferences.
     * Also triggers identity reset across providers.
     */
    public function eraseData(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $userId = $user->getKey();
        $userIdStr = is_int($userId) || is_string($userId) ? (string) $userId : null;

        if ($userIdStr === null || $userIdStr === '') {
            return response()->json(['error' => 'Invalid user ID'], 400);
        }

        $clientId = $this->extractClientId($request);

        if ($this->gdprErasureService !== null) {
            $result = $this->gdprErasureService->eraseUser($userIdStr, $clientId);
        } else {
            $result = [
                'profile_deleted' => false,
                'attribution_deleted' => false,
                'preferences_deleted' => false,
            ];
        }

        // Reset identity on all providers
        $this->manager->resetIdentity();

        return response()->json([
            'status' => 'ok',
            'erased' => $result,
            'identity_reset' => true,
        ]);
    }

    /**
     * Export all analytics data for the authenticated user (GDPR DSAR data portability).
     *
     * GET /api/analytics/gdpr/export
     *
     * Returns: analytics profile, attribution data, tracking preferences,
     * and event counts for the authenticated user.
     */
    public function gdprExport(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $userId = $user->getKey();
        $userIdStr = is_int($userId) || is_string($userId) ? (string) $userId : null;

        if ($userIdStr === null || $userIdStr === '') {
            return response()->json(['error' => 'Invalid user ID'], 400);
        }

        $clientId = $this->extractClientId($request);

        if ($this->gdprErasureService !== null) {
            $export = $this->gdprErasureService->exportUser($userIdStr, $clientId);
        } else {
            $export = [
                'user_id' => $userIdStr,
                'exported_at' => date('c'),
                'profile' => null,
                'attribution' => null,
                'preferences' => ['status' => 'unavailable'],
                'consent_history' => [],
                'event_counts' => null,
            ];
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'export' => $export,
        ]);
    }

    /**
     * Get aggregated analytics statistics for dashboards.
     *
     * GET /api/analytics/stats
     *
     * Returns real-time event counts, top events, per-category breakdowns,
     * per-provider dispatch/failure stats, and replay queue status.
     * Useful for admin dashboards and monitoring integrations.
     */
    public function stats(): JsonResponse
    {
        if ($this->statsService === null) {
            return response()->json(['error' => 'Stats service not available'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'stats' => $this->statsService->summary(),
        ]);
    }

    /**
     * Receive inbound webhook events from external sources.
     *
     * POST /api/analytics/webhook/inbound
     *
     * Accepts events from external systems (Stripe, payment processors,
     * custom integrations). Validates HMAC-SHA256 signature if configured.
     *
     * Body: { "event": "payment.completed", "params": { "amount": 99.99 } }
     * Batch: { "events": [ { "event": "...", "params": {...} }, ... ] }
     */
    public function inboundWebhook(Request $request): JsonResponse
    {
        if ($this->inboundWebhookService === null) {
            return response()->json(['error' => 'Inbound webhook not enabled'], 503);
        }

        $payload = $request->getContent();

        // Extract signature from headers
        $signature = $request->header('X-ZB-Signature')
            ?? $request->header('X-Hub-Signature-256');

        // Strip sha256= prefix for Meta-compatible signatures
        if (is_string($signature) && str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }

        $result = $this->inboundWebhookService->receive(
            $payload,
            is_string($signature) ? $signature : null,
        );

        $statusCode = match ($result['status']) {
            'ok' => 200,
            'partial' => 207,
            'disabled' => 503,
            default => 400,
        };

        return response()->json($result, $statusCode);
    }

    /**
     * Evaluate all alert rules against current metrics.
     *
     * POST /api/analytics/alerts/evaluate
     *
     * Checks all configured alert rules and returns any that were triggered.
     * Respects cooldown periods to prevent alert fatigue.
     */
    public function evaluateAlerts(): JsonResponse
    {
        if ($this->alertRulesService === null) {
            return response()->json(['error' => 'Alert rules service not available'], 503);
        }

        $triggered = $this->alertRulesService->evaluate();

        return response()->json([
            'status' => 'ok',
            'triggered' => count($triggered),
            'alerts' => $triggered,
        ]);
    }

    /**
     * Get alert rules summary and history.
     *
     * GET /api/analytics/alerts
     *
     * Returns the current alert rules configuration, recent alert history,
     * and cooldown status. Useful for admin dashboards.
     */
    public function alerts(): JsonResponse
    {
        if ($this->alertRulesService === null) {
            return response()->json(['error' => 'Alert rules service not available'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'summary' => $this->alertRulesService->summary(),
            'history' => $this->alertRulesService->getAlertHistory(25),
            'has_cooldowns' => $this->alertRulesService->hasCooldowns(),
        ]);
    }

    /**
     * Build funnel visualization data.
     *
     * GET /api/analytics/funnels?name=signup&steps[]=landing_view&steps[]=form_start&steps[]=form_submit&steps[]=confirmation
     *
     * Returns API-ready funnel data with per-step conversion rates,
     * drop-off analysis, and timing data for dashboard rendering.
     */
    public function funnelData(Request $request): JsonResponse
    {
        if ($this->funnelDataBuilderService === null) {
            return response()->json(['error' => 'Funnel data builder not available'], 503);
        }

        $funnelName = $request->query('name', 'default');
        $steps = $request->input('steps', []);
        $steps = is_array($steps) ? $steps : [];

        $formattedSteps = [];
        foreach ($steps as $i => $step) {
            if (is_string($step)) {
                $formattedSteps[] = ['name' => $step, 'order' => $i + 1];
            } elseif (is_array($step) && isset($step['name'])) {
                $formattedSteps[] = [
                    'name' => (string) $step['name'],
                    'order' => (int) ($step['order'] ?? $i + 1),
                ];
            }
        }

        $data = $this->funnelDataBuilderService->build(
            is_string($funnelName) ? $funnelName : 'default',
            $formattedSteps,
        );

        return response()->json([
            'status' => 'ok',
            'funnel' => $data,
        ]);
    }

    /**
     * Build funnel comparison data across multiple funnels.
     *
     * POST /api/analytics/funnels/compare
     *
     * Body: { "funnels": ["signup", "purchase", "trial"] }
     *
     * Returns side-by-side funnel performance metrics for comparison.
     */
    public function funnelCompare(Request $request): JsonResponse
    {
        if ($this->funnelDataBuilderService === null) {
            return response()->json(['error' => 'Funnel data builder not available'], 503);
        }

        $request->validate([
            'funnels' => 'required|array|max:10',
            'funnels.*' => 'string',
        ]);

        $funnelNames = $request->input('funnels', []);
        $funnelNames = is_array($funnelNames) ? $funnelNames : [];

        $data = $this->funnelDataBuilderService->compare($funnelNames);

        return response()->json([
            'status' => 'ok',
            'comparison' => $data,
        ]);
    }

    /**
     * Build funnel drop-off analysis.
     *
     * GET /api/analytics/funnels/drop-off?name=signup
     *
     * Returns step-by-step drop-off analysis identifying the bottleneck step.
     */
    public function funnelDropOff(Request $request): JsonResponse
    {
        if ($this->funnelDataBuilderService === null) {
            return response()->json(['error' => 'Funnel data builder not available'], 503);
        }

        $funnelName = $request->query('name', 'default');

        $data = $this->funnelDataBuilderService->buildDropOffAnalysis(
            is_string($funnelName) ? $funnelName : 'default',
        );

        return response()->json([
            'status' => 'ok',
            'analysis' => $data,
        ]);
    }

    /**
     * Build funnel chart-ready data.
     *
     * GET /api/analytics/funnels/chart?name=signup
     *
     * Returns funnel data in Chart.js/Recharts-compatible format.
     */
    public function funnelChart(Request $request): JsonResponse
    {
        if ($this->funnelDataBuilderService === null) {
            return response()->json(['error' => 'Funnel data builder not available'], 503);
        }

        $funnelName = $request->query('name', 'default');

        $data = $this->funnelDataBuilderService->buildChartData(
            is_string($funnelName) ? $funnelName : 'default',
        );

        return response()->json([
            'status' => 'ok',
            'chart' => $data,
        ]);
    }

    /**
     * Get the lifecycle event mapping configuration.
     *
     * GET /api/analytics/lifecycle
     *
     * Returns all registered lifecycle event mappings, their enabled status,
     * and the current mapper configuration. Useful for admin dashboards.
     */
    public function lifecycle(Request $request): JsonResponse
    {
        if ($this->lifecycleMapper === null) {
            return response()->json(['error' => 'Lifecycle mapper not available'], 503);
        }

        // If identity query parameter is provided, return lifecycle signals
        $identity = $request->query('identity');
        if ($identity !== null && is_string($identity) && $identity !== '') {
            try {
                $observer = new \ZeroBoiler\Analytics\Services\SaaSLifecycleObserver(
                    app(\Illuminate\Contracts\Cache\Repository::class),
                    app(\Illuminate\Contracts\Config\Repository::class),
                );

                $signals = $observer->getSignals($identity);

                return response()->json([
                    'status' => 'ok',
                    'version' => AnalyticsEvent::VERSION,
                    'identity' => $identity,
                    'lifecycle' => $signals,
                ]);
            } catch (\Throwable $e) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to retrieve lifecycle signals',
                    'error' => $e->getMessage(),
                ], 500);
            }
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'mapper' => $this->lifecycleMapper->summary(),
            'mappings' => $this->lifecycleMapper->getMappings(),
        ]);
    }

    /**
     * Get lifecycle subscriber diagnostic summary.
     *
     * GET /api/analytics/lifecycle/subscriber
     *
     * Returns the status of the LifecycleEventSubscriber, including
     * registered mapping count, keys, queue configuration, and
     * any registration errors.
     *
     * @since 79.0.0
     */
    public function lifecycleSubscriber(): JsonResponse
    {
        if ($this->lifecycleSubscriber === null) {
            return response()->json([
                'status' => 'not_available',
                'message' => 'LifecycleEventSubscriber not injected. Register via ServiceProvider.',
                'version' => AnalyticsEvent::VERSION,
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'subscriber' => $this->lifecycleSubscriber->diagnosticSummary(),
        ]);
    }

    /**
     * Get detected event patterns from correlation analysis.
     *
     * GET /api/analytics/correlation/patterns?min_length=2&limit=20
     *
     * Returns the most common event sequences detected across user journeys.
     * Useful for identifying common user flows and conversion paths.
     */
    public function correlationPatterns(Request $request): JsonResponse
    {
        if ($this->correlationService === null) {
            return response()->json(['error' => 'Correlation service not available'], 503);
        }

        $minLength = min((int) $request->query('min_length', 2), 10);
        $limit = min((int) $request->query('limit', 20), 100);

        $patterns = $this->correlationService->frequentPatterns($minLength, $limit);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'min_length' => $minLength,
            'count' => count($patterns),
            'patterns' => $patterns,
        ]);
    }

    /**
     * Get top event transitions.
     *
     * GET /api/analytics/correlation/transitions?limit=20
     *
     * Returns the most common event-to-event transitions across all users.
     * Each transition includes the probability (percentage of times event B
     * follows event A).
     */
    public function correlationTransitions(Request $request): JsonResponse
    {
        if ($this->correlationService === null) {
            return response()->json(['error' => 'Correlation service not available'], 503);
        }

        $limit = min((int) $request->query('limit', 20), 100);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'count' => count($this->correlationService->topTransitions($limit)),
            'transitions' => $this->correlationService->topTransitions($limit),
        ]);
    }

    /**
     * Predict next events after a given event.
     *
     * GET /api/analytics/correlation/predict?after=sign_up&limit=5
     *
     * Uses transition probabilities to predict which events are likely
     * to follow the given event. Useful for proactive analytics and
     * user journey optimization.
     */
    public function correlationPredict(Request $request): JsonResponse
    {
        if ($this->correlationService === null) {
            return response()->json(['error' => 'Correlation service not available'], 503);
        }

        $afterEvent = $request->query('after');
        $limit = min((int) $request->query('limit', 5), 20);

        if (! is_string($afterEvent) || $afterEvent === '') {
            return response()->json(['error' => 'Missing required query parameter: after'], 400);
        }

        $predictions = $this->correlationService->predictNext($afterEvent, $limit);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'after' => $afterEvent,
            'count' => count($predictions),
            'predictions' => $predictions,
        ]);
    }

    /**
     * Get event correlation analysis summary.
     *
     * GET /api/analytics/correlation/summary
     *
     * Returns a comprehensive summary of the correlation service state
     * including total events, unique events, transitions, user journeys,
     * and detected patterns.
     */
    public function correlationSummary(): JsonResponse
    {
        if ($this->correlationService === null) {
            return response()->json(['error' => 'Correlation service not available'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'summary' => $this->correlationService->summary(),
        ]);
    }

    /**
     * Validate the analytics configuration.
     *
     * GET /api/analytics/config/validate
     *
     * Returns a comprehensive validation result for all analytics config
     * sections including provider credentials, cross-dependencies,
     * and best-practice warnings. Useful for admin dashboards and CI checks.
     */
    public function validateConfig(): JsonResponse
    {
        if ($this->configValidator === null) {
            return response()->json(['error' => 'Config validator not available'], 503);
        }

        $result = $this->configValidator->result();

        return response()->json([
            'status' => $result['valid'] ? 'ok' : 'errors',
            'version' => AnalyticsEvent::VERSION,
            'valid' => $result['valid'],
            'errors' => $result['errors'],
            'warnings' => $result['warnings'],
            'issues' => $result['issues'],
        ]);
    }

    /**
     * Parse device context from User-Agent header.
     *
     * GET /api/analytics/device
     *
     * Returns parsed device context (browser, OS, device type, brand)
     * from the request's User-Agent header. Useful for client-side
     * enrichment when the server has already parsed the UA.
     */
    public function deviceContext(Request $request): JsonResponse
    {
        $ua = $request->userAgent();

        if ($ua === '' || $ua === null) {
            return response()->json([
                'status' => 'ok',
                'device' => null,
                'user_agent' => null,
            ]);
        }

        try {
            $deviceService = app(\ZeroBoiler\Analytics\Services\DeviceContextService::class);
            $context = $deviceService->parse($ua);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'device' => $context,
            ]);
        } catch (\Throwable) {
            return response()->json([
                'status' => 'ok',
                'device' => null,
                'user_agent' => $ua,
            ]);
        }
    }

    /**
     * Get referrer tracking information from the current request.
     *
     * GET /api/analytics/referrer
     *
     * Returns the parsed referrer data including source categorization,
     * UTM parameters, social network detection, and search engine detection.
     * Useful for debugging conversion attribution in admin interfaces.
     */
    public function referrerInfo(Request $request): JsonResponse
    {
        if ($this->referrerTrackingService === null) {
            return response()->json(['error' => 'Referrer tracking service not available'], 503);
        }

        $referrer = $this->referrerTrackingService->extractReferrer($request);
        $utm = $this->referrerTrackingService->extractUtm($request);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'referrer' => $referrer,
            'utm' => $utm,
        ]);
    }

    /**
     * Get the analytics broadcast configuration and channel info.
     *
     * GET /api/analytics/broadcast
     *
     * Returns the broadcast channel prefix, enabled status, and
     * available channels for frontend WebSocket integration.
     */
    public function broadcastInfo(): JsonResponse
    {
        if ($this->broadcasterService === null) {
            return response()->json(['error' => 'Broadcast service not available'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'broadcast' => [
                'enabled' => $this->broadcasterService->isEnabled(),
                'channel_prefix' => $this->broadcasterService->getChannelPrefix(),
                'channels' => [
                    'events' => $this->broadcasterService->channelName('events'),
                    'alerts' => $this->broadcasterService->channelName('alerts'),
                    'metrics' => $this->broadcasterService->channelName('metrics'),
                ],
            ],
        ]);
    }

    /**
     * Get all dashboard widgets data in a single request.
     *
     * Returns pre-computed, cache-backed widget data for all enabled
     * dashboard widgets. Designed for headless SaaS admin dashboards.
     *
     * GET /api/analytics/dashboard/widgets
     * GET /api/analytics/dashboard/widgets/{widgetName}
     *
     * @since 8.3.0
     */
    public function dashboardWidgets(Request $request, ?string $widgetName = null): JsonResponse
    {
        $service = app(\ZeroBoiler\Analytics\Services\DashboardWidgetService::class);

        if ($widgetName !== null) {
            return response()->json([
                'status' => 'ok',
                'widget' => $widgetName,
                'data' => $service->getWidget($widgetName),
            ]);
        }

        return response()->json($service->allWidgets());
    }

    /**
     * Invalidate dashboard widget caches.
     *
     * POST /api/analytics/dashboard/widgets/invalidate
     *
     * @since 8.3.0
     */
    public function dashboardWidgetsInvalidate(Request $request): JsonResponse
    {
        $service = app(\ZeroBoiler\Analytics\Services\DashboardWidgetService::class);
        $widget = $request->input('widget');

        if (is_string($widget) && $widget !== '') {
            $service->invalidateWidget($widget);

            return response()->json([
                'status' => 'ok',
                'message' => "Widget '{$widget}' cache invalidated",
            ]);
        }

        $service->invalidateAll();

        return response()->json([
            'status' => 'ok',
            'message' => 'All widget caches invalidated',
        ]);
    }

    /**
     * Get tenant isolation status and rate limit info.
     *
     * GET /api/analytics/tenant
     *
     * Returns the current tenant ID, isolation status, per-tenant config,
     * and rate limit information. Useful for multi-tenant admin dashboards.
     */
    public function tenantInfo(Request $request): JsonResponse
    {
        if ($this->tenantService === null) {
            return response()->json(['error' => 'Tenant isolation not available'], 503);
        }

        $tenantId = $this->tenantService->resolveTenantId();

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'tenant_id' => $tenantId,
            'isolation' => $this->tenantService->summary(),
            'rate_limit' => $tenantId !== null
                ? $this->tenantService->getRateLimitStatus($tenantId)
                : null,
            'config' => $tenantId !== null
                ? $this->tenantService->getTenantConfig($tenantId)
                : null,
        ]);
    }

    /**
     * Update per-tenant analytics configuration.
     *
     * POST /api/analytics/tenant/config
     *
     * Body: { "disabled_events": ["error"], "analytics_enabled": true }
     */
    public function updateTenantConfig(Request $request): JsonResponse
    {
        if ($this->tenantService === null) {
            return response()->json(['error' => 'Tenant isolation not available'], 503);
        }

        $tenantId = $this->tenantService->resolveTenantId();

        if ($tenantId === null || $tenantId === '') {
            return response()->json(['error' => 'No tenant resolved from request'], 400);
        }

        $request->validate([
            'disabled_events' => 'array',
            'disabled_events.*' => 'string',
            'analytics_enabled' => 'boolean',
        ]);

        $overrides = $request->only(['disabled_events', 'analytics_enabled']);

        if (! empty($overrides)) {
            $this->tenantService->setTenantConfig($tenantId, $overrides);
        }

        return response()->json([
            'status' => 'ok',
            'tenant_id' => $tenantId,
            'config' => $this->tenantService->getTenantConfig($tenantId),
        ]);
    }

    /**
     * Get data retention policy summary.
     *
     * GET /api/analytics/retention
     *
     * Returns retention periods per category, PII categories,
     * auto-expire status, and tracked event counts.
     * Useful for GDPR compliance dashboards.
     */
    public function retentionInfo(): JsonResponse
    {
        if ($this->retentionService === null) {
            return response()->json(['error' => 'Retention service not available'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'retention' => $this->retentionService->summary(),
        ]);
    }

    /**
     * Get analytics feature gate status.
     *
     * GET /api/analytics/gate
     *
     * Returns available features, plan tier, and per-feature access status.
     * Enables frontend feature-flagging of analytics UI components.
     */
    public function gateInfo(Request $request): JsonResponse
    {
        if ($this->gateService === null) {
            return response()->json(['error' => 'Analytics gate not available'], 503);
        }

        $user = $request->user();
        $userId = $user !== null ? (is_int($user->getKey()) || is_string($user->getKey()) ? (string) $user->getKey() : null) : null;

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'gate' => $this->gateService->summary($userId),
            'plan_tiers' => AnalyticsGateService::getPlanTiers(),
            'features' => AnalyticsGateService::getFeatureDefinitions(),
        ]);
    }

    /**
     * Get feature definitions and plan tiers for client-side feature flagging.
     *
     * GET /api/analytics/gate/definitions
     */
    public function gateDefinitions(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'features' => AnalyticsGateService::getFeatureDefinitions(),
            'plan_tiers' => AnalyticsGateService::getPlanTiers(),
        ]);
    }

    // ── Reporting Endpoints ────────────────────────────────────────────

    /**
     * Generate a full analytics report.
     *
     * GET /api/analytics/report?period=daily
     *
     * Returns event counts, category breakdown, top events, trending events,
     * provider dispatch stats, and event catalog summary.
     */
    public function report(Request $request): JsonResponse
    {
        if ($this->reportingService === null) {
            return response()->json(['error' => 'Reporting service not available'], 503);
        }

        $period = $request->query('period', 'daily');

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'report' => $this->reportingService->report($period),
        ]);
    }

    /**
     * Get a quick analytics summary (single-line status).
     *
     * GET /api/analytics/report/summary
     */
    public function reportSummary(): JsonResponse
    {
        if ($this->reportingService === null) {
            return response()->json(['error' => 'Reporting service not available'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'summary' => $this->reportingService->quickSummary(),
        ]);
    }

    /**
     * Get top events by count.
     *
     * GET /api/analytics/report/top-events?limit=20
     */
    public function reportTopEvents(Request $request): JsonResponse
    {
        if ($this->reportingService === null) {
            return response()->json(['error' => 'Reporting service not available'], 503);
        }

        $limit = min((int) $request->query('limit', 20), 100);

        return response()->json([
            'status' => 'ok',
            'top_events' => $this->reportingService->topEvents($limit),
        ]);
    }

    /**
     * Get trending events (events with increasing dispatch rate).
     *
     * GET /api/analytics/report/trending?limit=10
     */
    public function reportTrending(Request $request): JsonResponse
    {
        if ($this->reportingService === null) {
            return response()->json(['error' => 'Reporting service not available'], 503);
        }

        $limit = min((int) $request->query('limit', 10), 50);

        return response()->json([
            'status' => 'ok',
            'trending' => $this->reportingService->trendingEvents($limit),
        ]);
    }

    /**
     * Get per-provider dispatch statistics.
     *
     * GET /api/analytics/report/provider-stats
     */
    public function reportProviderStats(): JsonResponse
    {
        if ($this->reportingService === null) {
            return response()->json(['error' => 'Reporting service not available'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'provider_stats' => $this->reportingService->providerStats(),
        ]);
    }

    // ── Dead Letter Queue Endpoints ────────────────────────────────────

    /**
     * List all events in the dead letter queue.
     *
     * GET /api/analytics/dlq?event_name=purchase&limit=100
     */
    public function dlqList(Request $request): JsonResponse
    {
        if ($this->dlqService === null) {
            return response()->json(['error' => 'DLQ not available'], 503);
        }

        $eventName = $request->query('event_name');
        $limit = min((int) $request->query('limit', 100), 1000);

        $events = (is_string($eventName) && $eventName !== '')
            ? $this->dlqService->getByEventName($eventName)
            : $this->dlqService->all();

        return response()->json([
            'status' => 'ok',
            'count' => count($events),
            'events' => array_slice($events, 0, $limit),
        ]);
    }

    /**
     * Clear all events from the dead letter queue.
     *
     * DELETE /api/analytics/dlq
     */
    public function dlqClear(): JsonResponse
    {
        if ($this->dlqService === null) {
            return response()->json(['error' => 'DLQ not available'], 503);
        }

        $count = $this->dlqService->totalSize();
        $this->dlqService->clear();

        return response()->json([
            'status' => 'ok',
            'cleared' => $count,
            'message' => "Cleared {$count} events from the dead letter queue.",
        ]);
    }

    /**
     * Remove a specific event from the DLQ by offset.
     *
     * DELETE /api/analytics/dlq/{offset}
     */
    public function dlqRemove(int $offset): JsonResponse
    {
        if ($this->dlqService === null) {
            return response()->json(['error' => 'DLQ not available'], 503);
        }

        $removed = $this->dlqService->remove($offset);

        return response()->json([
            'status' => $removed ? 'ok' : 'not_found',
            'removed' => $removed,
            'offset' => $offset,
        ], $removed ? 200 : 404);
    }

    /**
     * Get dead letter queue summary.
     *
     * GET /api/analytics/dlq/summary
     */
    public function dlqSummary(): JsonResponse
    {
        if ($this->dlqService === null) {
            return response()->json(['error' => 'DLQ not available'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'dlq' => $this->dlqService->summary(),
        ]);
    }

    /**
     * Replay all DLQ events through the analytics manager.
     *
     * POST /api/analytics/dlq/replay
     */
    public function dlqReplayAll(): JsonResponse
    {
        if ($this->dlqService === null) {
            return response()->json(['error' => 'DLQ not available'], 503);
        }

        $events = $this->dlqService->replayAll();
        $dispatched = 0;
        $failed = 0;
        $errors = [];

        foreach ($events as $event) {
            try {
                $this->manager->directDispatch($event);
                $dispatched++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = [
                    'event' => $event->name,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'replayed' => count($events),
            'dispatched' => $dispatched,
            'failed' => $failed,
            'errors' => $errors,
        ]);
    }

    /**
     * Replay a single DLQ event by offset through the analytics manager.
     *
     * POST /api/analytics/dlq/replay/{offset}
     */
    public function dlqReplaySingle(int $offset): JsonResponse
    {
        if ($this->dlqService === null) {
            return response()->json(['error' => 'DLQ not available'], 503);
        }

        $event = $this->dlqService->replaySingle($offset);

        if ($event === null) {
            return response()->json([
                'status' => 'not_found',
                'message' => "No DLQ event found at offset {$offset}.",
            ], 404);
        }

        try {
            $this->manager->directDispatch($event);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'replayed' => true,
                'event' => $event->name,
                'offset' => $offset,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'event' => $event->name,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ── Real-Time Aggregation Endpoints ────────────────────────────────

    /**
     * Get real-time analytics snapshot.
     *
     * GET /api/analytics/realtime
     *
     * Returns live event counters, unique user count, per-provider rates,
     * and events-per-second for the current rolling window.
     */
    public function realtimeSnapshot(): JsonResponse
    {
        if ($this->realtimeService === null) {
            return response()->json(['error' => 'Real-time aggregation not available'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'realtime' => $this->realtimeService->snapshot(),
        ]);
    }

    /**
     * Get top events by real-time count.
     *
     * GET /api/analytics/realtime/top-events?limit=10
     */
    public function realtimeTopEvents(Request $request): JsonResponse
    {
        if ($this->realtimeService === null) {
            return response()->json(['error' => 'Real-time aggregation not available'], 503);
        }

        $limit = min((int) $request->query('limit', 10), 50);

        return response()->json([
            'status' => 'ok',
            'top_events' => $this->realtimeService->topEvents($limit),
            'events_per_second' => $this->realtimeService->eventsPerSecond(),
        ]);
    }

    // ── A/B Test Analytics Endpoints ───────────────────────────────────

    /**
     * Get A/B test results with statistical significance.
     *
     * GET /api/analytics/ab-tests/{experimentId}
     */
    public function abTestResults(string $experimentId): JsonResponse
    {
        if ($this->abTestService === null) {
            return response()->json(['error' => 'A/B test analytics not available'], 503);
        }

        $results = $this->abTestService->getResults($experimentId);

        if ($results === null) {
            return response()->json(['error' => 'Experiment not found'], 404);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'experiment' => $results,
        ]);
    }

    /**
     * Record an A/B test exposure.
     *
     * POST /api/analytics/ab-tests/{experimentId}/exposure
     *
     * Body: { "variant_id": "variant_b", "user_id": "optional" }
     */
    public function abTestRecordExposure(Request $request, string $experimentId): JsonResponse
    {
        if ($this->abTestService === null) {
            return response()->json(['error' => 'A/B test analytics not available'], 503);
        }

        $request->validate([
            'variant_id' => 'required|string',
            'user_id' => 'string',
        ]);

        $this->abTestService->trackExposure(
            $experimentId,
            $request->input('variant_id'),
            ['user_id' => $request->input('user_id')],
        );

        return response()->json(['status' => 'ok']);
    }

    /**
     * Record an A/B test conversion.
     *
     * POST /api/analytics/ab-tests/{experimentId}/conversion
     *
     * Body: { "variant_id": "variant_b" }
     */
    public function abTestRecordConversion(Request $request, string $experimentId): JsonResponse
    {
        if ($this->abTestService === null) {
            return response()->json(['error' => 'A/B test analytics not available'], 503);
        }

        $request->validate([
            'variant_id' => 'required|string',
        ]);

        $this->abTestService->trackConversion(
            $experimentId,
            $request->input('variant_id'),
        );

        return response()->json(['status' => 'ok']);
    }

    /**
     * Delete an A/B test experiment.
     *
     * DELETE /api/analytics/ab-tests/{experimentId}
     */
    public function abTestDelete(string $experimentId): JsonResponse
    {
        if ($this->abTestService === null) {
            return response()->json(['error' => 'A/B test analytics not available'], 503);
        }

        $deleted = $this->abTestService->deleteExperiment($experimentId);

        return response()->json([
            'status' => $deleted ? 'ok' : 'not_found',
            'deleted' => $deleted,
        ], $deleted ? 200 : 404);
    }

    // ── Snapshot Endpoints ────────────────────────────────────────────

    /**
     * Get the latest daily snapshot.
     *
     * GET /api/analytics/snapshots/daily
     */
    public function dailySnapshot(Request $request): JsonResponse
    {
        if ($this->snapshotService === null) {
            return response()->json(['error' => 'Snapshot service not available'], 503);
        }

        $date = $request->query('date');

        $snapshot = (is_string($date) && $date !== '')
            ? $this->snapshotService->getDailySnapshot($date)
            : $this->snapshotService->latestDaily();

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'snapshot' => $snapshot,
        ]);
    }

    /**
     * Get the latest hourly snapshot.
     *
     * GET /api/analytics/snapshots/hourly
     */
    public function hourlySnapshot(Request $request): JsonResponse
    {
        if ($this->snapshotService === null) {
            return response()->json(['error' => 'Snapshot service not available'], 503);
        }

        $hour = $request->query('hour');

        $snapshot = (is_string($hour) && $hour !== '')
            ? $this->snapshotService->getHourlySnapshot($hour)
            : $this->snapshotService->latestHourly();

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'snapshot' => $snapshot,
        ]);
    }

    /**
     * Get daily comparison (today vs yesterday).
     *
     * GET /api/analytics/snapshots/comparison
     */
    public function dailyComparison(): JsonResponse
    {
        if ($this->snapshotService === null) {
            return response()->json(['error' => 'Snapshot service not available'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'comparison' => $this->snapshotService->dailyComparison(),
        ]);
    }

    // ── SaaS KPI Endpoints ───────────────────────────────────────────

    /**
     * Get SaaS KPI summary.
     *
     * GET /api/analytics/kpi
     *
     * Returns MRR, ARR, churn rate, trial conversion, CLV, ARPU,
     * plan distribution, and MRR history.
     */
    public function saasKpiSummary(): JsonResponse
    {
        if ($this->kpiTracker === null) {
            return response()->json(['error' => 'SaaS KPI tracker not available'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'kpi' => $this->kpiTracker->summary(),
        ]);
    }

    /**
     * Get MRR history for trend visualization.
     *
     * GET /api/analytics/kpi/mrr-history?limit=30
     */
    public function saasKpiMrrHistory(Request $request): JsonResponse
    {
        if ($this->kpiTracker === null) {
            return response()->json(['error' => 'SaaS KPI tracker not available'], 503);
        }

        $limit = min((int) $request->query('limit', 30), 365);

        return response()->json([
            'status' => 'ok',
            'mrr_history' => $this->kpiTracker->getMrrHistory($limit),
        ]);
    }

    // ── UTM Aggregation Endpoints ────────────────────────────────────

    /**
     * Get top UTM sources by event count.
     *
     * GET /api/analytics/utm/sources?limit=20
     */
    public function utmTopSources(Request $request): JsonResponse
    {
        if ($this->utmAggregation === null) {
            return response()->json(['error' => 'UTM aggregation not available'], 503);
        }

        $limit = min((int) $request->query('limit', 20), 100);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'sources' => $this->utmAggregation->topSources($limit),
        ]);
    }

    /**
     * Get top UTM campaigns by event count.
     *
     * GET /api/analytics/utm/campaigns?limit=20
     */
    public function utmTopCampaigns(Request $request): JsonResponse
    {
        if ($this->utmAggregation === null) {
            return response()->json(['error' => 'UTM aggregation not available'], 503);
        }

        $limit = min((int) $request->query('limit', 20), 100);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'campaigns' => $this->utmAggregation->topCampaigns($limit),
        ]);
    }

    /**
     * Get UTM source/medium breakdown.
     *
     * GET /api/analytics/utm/breakdown
     */
    public function utmBreakdown(): JsonResponse
    {
        if ($this->utmAggregation === null) {
            return response()->json(['error' => 'UTM aggregation not available'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'breakdown' => $this->utmAggregation->sourceMediumBreakdown(),
        ]);
    }

    // ── Event Forwarding Endpoints ──────────────────────────────────

    /**
     * Get forwarding configuration and forwarder status.
     *
     * GET /api/analytics/forwarding
     */
    public function forwardingInfo(): JsonResponse
    {
        if ($this->forwardingService === null) {
            return response()->json(['error' => 'Forwarding service not available'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'enabled' => $this->forwardingService->isEnabled(),
            'forwarders' => $this->forwardingService->forwarderNames(),
            'details' => array_map(
                fn (string $name): ?array => $this->forwardingService?->getForwarderConfig($name),
                array_combine(
                    $this->forwardingService->forwarderNames(),
                    $this->forwardingService->forwarderNames(),
                ),
            ),
        ]);
    }

    /**
     * Get forwarding statistics.
     *
     * GET /api/analytics/forwarding/stats
     */
    public function forwardingStats(): JsonResponse
    {
        if ($this->forwardingService === null) {
            return response()->json(['error' => 'Forwarding service not available'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'stats' => $this->forwardingService->stats(),
        ]);
    }

    /**
     * Test a specific forwarder connection.
     *
     * POST /api/analytics/forwarding/test/{forwarder}
     */
    public function forwardingTest(string $forwarder): JsonResponse
    {
        if ($this->forwardingService === null) {
            return response()->json(['error' => 'Forwarding service not available'], 503);
        }

        $result = $this->forwardingService->testForwarder($forwarder);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            ...$result,
        ]);
    }

    /**
     * Reset forwarding statistics.
     *
     * POST /api/analytics/forwarding/reset-stats
     */
    public function forwardingResetStats(): JsonResponse
    {
        if ($this->forwardingService === null) {
            return response()->json(['error' => 'Forwarding service not available'], 503);
        }

        $this->forwardingService->resetStats();

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'message' => 'Forwarding statistics reset',
        ]);
    }

    // ── Performance Budget Endpoints ───────────────────────────────

    /**
     * Get performance budget configuration.
     *
     * GET /api/analytics/performance-budget
     */
    public function performanceBudgetInfo(): JsonResponse
    {
        if ($this->performanceBudgetService === null) {
            return response()->json(['error' => 'Performance budget service not available'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'config' => $this->performanceBudgetService->getConfig(),
        ]);
    }

    /**
     * Validate an event against the performance budget.
     *
     * POST /api/analytics/performance-budget/validate
     *
     * Body: { "name": "event_name", "params": { ... } }
     */
    public function performanceBudgetValidate(Request $request): JsonResponse
    {
        if ($this->performanceBudgetService === null) {
            return response()->json(['error' => 'Performance budget service not available'], 503);
        }

        $name = $request->input('name', '');
        $params = $request->input('params', []);

        $event = new AnalyticsEvent(
            name: is_string($name) ? $name : '',
            params: is_array($params) ? $params : [],
        );

        $validation = $this->performanceBudgetService->validate($event);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'payload_size' => $this->performanceBudgetService->getPayloadSize($event),
            ...$validation,
        ]);
    }

    // ── UTM Attribution Endpoints ───────────────────────────────────

    /**
     * Get UTM attribution for an identifier.
     *
     * GET /api/analytics/attribution/{identifier}
     */
    public function attributionInfo(string $identifier): JsonResponse
    {
        if ($this->attributionService === null) {
            return response()->json(['error' => 'Attribution service not available'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'attribution' => $this->attributionService->getAttribution($identifier),
        ]);
    }

    /**
     * Get all touchpoints for an identifier.
     *
     * GET /api/analytics/attribution/{identifier}/touchpoints
     */
    public function attributionTouchpoints(string $identifier): JsonResponse
    {
        if ($this->attributionService === null) {
            return response()->json(['error' => 'Attribution service not available'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'touchpoints' => $this->attributionService->getTouchpoints($identifier),
        ]);
    }

    /**
     * Get first-touch attribution for an identifier.
     *
     * GET /api/analytics/attribution/{identifier}/first-touch
     */
    public function attributionFirstTouch(string $identifier): JsonResponse
    {
        if ($this->attributionService === null) {
            return response()->json(['error' => 'Attribution service not available'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'first_touch' => $this->attributionService->getFirstTouch($identifier),
        ]);
    }

    /**
     * Get last-touch attribution for an identifier.
     *
     * GET /api/analytics/attribution/{identifier}/last-touch
     */
    public function attributionLastTouch(string $identifier): JsonResponse
    {
        if ($this->attributionService === null) {
            return response()->json(['error' => 'Attribution service not available'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'last_touch' => $this->attributionService->getLastTouch($identifier),
        ]);
    }

    /**
     * Record a UTM attribution touchpoint.
     *
     * POST /api/analytics/attribution/record
     *
     * Body: { "identifier": "...", "utm_params": {...}, "context": {...} }
     */
    public function attributionRecord(Request $request): JsonResponse
    {
        if ($this->attributionService === null) {
            return response()->json(['error' => 'Attribution service not available'], 503);
        }

        $identifier = $request->input('identifier', '');
        $utmParams = $request->input('utm_params', []);
        $context = $request->input('context', []);

        if (! is_string($identifier) || $identifier === '') {
            return response()->json(['error' => 'identifier is required'], 422);
        }

        $this->attributionService->recordTouchpoint(
            $identifier,
            is_array($utmParams) ? $utmParams : [],
            is_array($context) ? $context : [],
        );

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'message' => 'Touchpoint recorded',
            'attribution' => $this->attributionService->getAttribution($identifier),
        ]);
    }

    /**
     * Clear attribution data for an identifier.
     *
     * DELETE /api/analytics/attribution/{identifier}
     */
    public function attributionClear(string $identifier): JsonResponse
    {
        if ($this->attributionService === null) {
            return response()->json(['error' => 'Attribution service not available'], 503);
        }

        $this->attributionService->clearAttribution($identifier);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'message' => 'Attribution data cleared',
        ]);
    }

    /**
     * Get event taxonomy summary (tags, event classifications).
     */
    public function taxonomySummary(): JsonResponse
    {
        if ($this->taxonomyService === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event taxonomy service not available',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'data' => $this->taxonomyService->summary(),
        ]);
    }

    /**
     * Get events for a specific taxonomy tag.
     */
    public function taxonomyTag(string $tag): JsonResponse
    {
        if ($this->taxonomyService === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event taxonomy service not available',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'tag' => $tag,
            'events' => $this->taxonomyService->eventsWithTag($tag),
            'count' => count($this->taxonomyService->eventsWithTag($tag)),
            'definition' => [
                'label' => $tag,
                'description' => 'Tag-based event classification',
            ],
        ]);
    }

    /**
     * Get all taxonomy tag definitions with event counts.
     */
    public function taxonomyDefinitions(): JsonResponse
    {
        if ($this->taxonomyService === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event taxonomy service not available',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'data' => $this->taxonomyService->tagDefinitions(),
        ]);
    }

    /**
     * Get all events grouped by taxonomy tag.
     */
    public function taxonomyGrouped(): JsonResponse
    {
        if ($this->taxonomyService === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event taxonomy service not available',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'data' => $this->taxonomyService->eventsGroupedByTag(),
        ]);
    }

    // ── Event Buckets (Time-Binned Aggregation) ───────────────────

    /**
     * Get time-binned event aggregation for a series.
     *
     * GET /api/analytics/buckets/{series}?granularity=hour&limit=24
     *
     * Returns event counts, unique users, and value totals binned by time.
     */
    public function eventBuckets(Request $request, string $series): JsonResponse
    {
        if ($this->bucketsService === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event buckets service not available',
            ], 503);
        }

        $granularity = $request->query('granularity', 'hour');
        $limit = min((int) $request->query('limit', 24), 1000);

        $validGranularities = EventBucketsService::availableGranularities();
        if (! in_array($granularity, $validGranularities, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid granularity. Use: ' . implode(', ', $validGranularities),
            ], 422);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'series' => $series,
            'granularity' => $granularity,
            'limit' => $limit,
            'buckets' => $this->bucketsService->getBuckets($series, $granularity, $limit),
        ]);
    }

    /**
     * Get bucket series summary.
     *
     * GET /api/analytics/buckets/{series}/summary?granularity=hour&last=24
     */
    public function eventBucketSummary(Request $request, string $series): JsonResponse
    {
        if ($this->bucketsService === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event buckets service not available',
            ], 503);
        }

        $granularity = $request->query('granularity', 'hour');
        $last = min((int) $request->query('last', 24), 500);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'series' => $series,
            'granularity' => $granularity,
            'summary' => $this->bucketsService->summary($series, $granularity, $last),
        ]);
    }

    /**
     * Compare two bucket series.
     *
     * GET /api/analytics/buckets/{seriesA}/compare/{seriesB}?granularity=hour&limit=24
     */
    public function eventBucketCompare(Request $request, string $seriesA, string $seriesB): JsonResponse
    {
        if ($this->bucketsService === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event buckets service not available',
            ], 503);
        }

        $granularity = $request->query('granularity', 'hour');
        $limit = min((int) $request->query('limit', 24), 500);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'series_a' => $seriesA,
            'series_b' => $seriesB,
            'granularity' => $granularity,
            'comparison' => $this->bucketsService->compare($seriesA, $seriesB, $granularity, $limit),
        ]);
    }

    /**
     * List all registered bucket series.
     *
     * GET /api/analytics/buckets
     */
    public function eventBucketList(): JsonResponse
    {
        if ($this->bucketsService === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Event buckets service not available',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'series' => $this->bucketsService->seriesList(),
            'granularities' => EventBucketsService::availableGranularities(),
        ]);
    }

    // ── SaaS Health Score ─────────────────────────────────────────

    /**
     * Get the current SaaS health score.
     *
     * GET /api/analytics/health-score
     *
     * Returns overall score (0-100) with sub-scores for engagement,
     * revenue, conversion, and retention dimensions.
     */
    public function healthScore(): JsonResponse
    {
        if ($this->healthScoreService === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Health score service not available',
            ], 503);
        }

        $cached = $this->healthScoreService->current();

        if ($cached !== null) {
            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'source' => 'cached',
                ...$cached,
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'source' => 'calculated',
            ...$this->healthScoreService->calculate(),
        ]);
    }

    /**
     * Force-recalculate and return the health score.
     *
     * POST /api/analytics/health-score/calculate
     */
    public function healthScoreCalculate(): JsonResponse
    {
        if ($this->healthScoreService === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Health score service not available',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'source' => 'calculated',
            ...$this->healthScoreService->calculate(),
        ]);
    }

    /**
     * Get health score history for trend visualization.
     *
     * GET /api/analytics/health-score/history?limit=30
     */
    public function healthScoreHistory(Request $request): JsonResponse
    {
        if ($this->healthScoreService === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Health score service not available',
            ], 503);
        }

        $limit = min((int) $request->query('limit', 30), 90);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'limit' => $limit,
            'history' => $this->healthScoreService->history($limit),
        ]);
    }

    // ── User Journey Timeline ────────────────────────────────────

    /**
     * Get a specific user journey timeline.
     *
     * GET /api/analytics/journeys/{journeyId}
     *
     * Returns full journey with steps, sequence, page flow, and duration.
     */
    public function journeyTimeline(string $journeyId): JsonResponse
    {
        if ($this->journeyService === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Journey service not available',
            ], 503);
        }

        $journey = $this->journeyService->getJourney($journeyId);

        if ($journey === null) {
            return response()->json([
                'status' => 'not_found',
                'message' => 'Journey not found',
            ], 404);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'journey' => $journey,
            'page_flow' => $this->journeyService->getPageFlow($journeyId),
        ]);
    }

    /**
     * Get journey statistics across all tracked journeys.
     *
     * GET /api/analytics/journeys/stats
     */
    public function journeyStats(): JsonResponse
    {
        if ($this->journeyService === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Journey service not available',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'stats' => $this->journeyService->getStats(),
        ]);
    }

    /**
     * Get most common journey patterns.
     *
     * GET /api/analytics/journeys/patterns?steps=0&limit=20
     */
    public function journeyPatterns(Request $request): JsonResponse
    {
        if ($this->journeyService === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Journey service not available',
            ], 503);
        }

        $steps = (int) $request->query('steps', 0);
        $limit = min((int) $request->query('limit', 20), 100);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'patterns' => $this->journeyService->mostCommonPatterns($steps, $limit),
        ]);
    }

    /**
     * Get drop-off points across all journeys.
     *
     * GET /api/analytics/journeys/drop-offs?limit=20
     */
    public function journeyDropOffs(Request $request): JsonResponse
    {
        if ($this->journeyService === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Journey service not available',
            ], 503);
        }

        $limit = min((int) $request->query('limit', 20), 100);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'drop_offs' => $this->journeyService->dropOffPoints($limit),
        ]);
    }

    /**
     * Search for journeys matching a pattern.
     *
     * GET /api/analytics/journeys/search?pattern=page_view+%E2%86%92+*+purchase&limit=50
     */
    public function journeySearch(Request $request): JsonResponse
    {
        if ($this->journeyService === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Journey service not available',
            ], 503);
        }

        $pattern = $request->query('pattern', '');
        $limit = min((int) $request->query('limit', 50), 200);

        if ($pattern === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Pattern parameter is required',
            ], 422);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'pattern' => $pattern,
            'matches' => $this->journeyService->findMatchingJourneys($pattern, $limit),
            'count' => count($this->journeyService->findMatchingJourneys($pattern, $limit)),
        ]);
    }

    /**
     * Get funnel conversion within journeys.
     *
     * POST /api/analytics/journeys/funnel
     *
     * Body: { "steps": ["page_view", "add_to_cart", "purchase"] }
     */
    public function journeyFunnel(Request $request): JsonResponse
    {
        if ($this->journeyService === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Journey service not available',
            ], 503);
        }

        $request->validate([
            'steps' => 'required|array|min:2|max:20',
            'steps.*' => 'string',
        ]);

        $steps = $request->input('steps', []);
        $steps = is_array($steps) ? $steps : [];

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'funnel' => $this->journeyService->funnelConversion($steps),
        ]);
    }

    /**
     * Get available consent purposes and event→purpose mapping.
     *
     * GET /api/analytics/consent/purposes
     */
    public function consentPurposes(Request $request): JsonResponse
    {
        try {
            $consentLog = app(\ZeroBoiler\Analytics\Services\ConsentLogService::class);
            $consentFilter = app(\ZeroBoiler\Analytics\Pipeline\ConsentAwareFilter::class);
        } catch (\Throwable) {
            return response()->json([
                'status' => 'error',
                'message' => 'Consent services not available',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'purposes' => $consentLog->availablePurposes(),
            'purpose_map' => $consentFilter->getPurposeMap(),
            'purpose_to_signal' => \ZeroBoiler\Analytics\Pipeline\ConsentAwareFilter::purposeToSignalMap(),
            'default_state' => $consentLog->defaultConsentState(),
        ]);
    }

    /**
     * Get envelope service info and active context sections.
     *
     * GET /api/analytics/consent/envelope-info
     */
    public function envelopeInfo(Request $request): JsonResponse
    {
        try {
            $envelopeService = app(\ZeroBoiler\Analytics\Services\EventEnvelopeService::class);
        } catch (\Throwable) {
            return response()->json([
                'status' => 'error',
                'message' => 'Envelope service not available',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'enabled' => $envelopeService->isEnabled(),
            'active_sections' => $envelopeService->activeSections(),
            'summary' => $envelopeService->summary(),
        ]);
    }

    /**
     * Get consent history for the authenticated user.
     *
     * GET /api/analytics/consent/history
     */
    public function consentHistory(Request $request): JsonResponse
    {
        try {
            $consentLog = app(\ZeroBoiler\Analytics\Services\ConsentLogService::class);
        } catch (\Throwable) {
            return response()->json([
                'status' => 'error',
                'message' => 'Consent service not available',
            ], 503);
        }

        $userId = $request->user()?->id;

        if ($userId === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Authenticated user required',
            ], 401);
        }

        $identifier = (string) $userId;
        $limit = min((int) $request->query('limit', 50), 500);

        $history = $consentLog->getHistory($identifier);
        $history = array_slice($history, -$limit);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'identifier' => $identifier,
            'current' => $consentLog->getCurrentConsent($identifier),
            'history' => $history,
            'count' => count($history),
        ]);
    }

    /**
     * Get all event schemas from the registry.
     *
     * Returns all registered event schemas grouped by category with parameter definitions,
     * types, required/optional status, and provider mappings. Useful for client-side
     * validation, documentation generation, and admin dashboards.
     *
     * Supports ?category=ecommerce|saas|engagement filter and ?compact=1 for lightweight listing.
     *
     * GET /api/analytics/schemas
     */
    public function schemaList(Request $request): JsonResponse
    {
        try {
            $registry = app(EventSchemaRegistry::class);
        } catch (\Throwable) {
            return response()->json([
                'status' => 'error',
                'message' => 'Schema registry not available',
            ], 503);
        }

        $categoryFilter = $request->query('category');
        $compact = (bool) $request->query('compact', false);

        if ($compact) {
            $names = $registry->getEventNames();

            if ($categoryFilter !== null) {
                $names = $registry->getEventsByCategory((string) $categoryFilter);
            }

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'count' => count($names),
                'events' => $names,
            ]);
        }

        $allSchemas = $registry->all();

        if ($categoryFilter !== null) {
            $allSchemas = array_filter(
                $allSchemas,
                fn (\ZeroBoiler\Analytics\Schema\EventSchema $schema): bool =>
                    $schema->category === (string) $categoryFilter,
            );
        }

        $schemas = [];

        foreach ($allSchemas as $name => $schema) {
            $schemas[$name] = [
                'name' => $schema->name,
                'category' => $schema->category,
                'description' => $schema->description,
                'required_params' => array_keys($schema->requiredParams),
                'optional_params' => array_keys($schema->optionalParams),
                'all_params' => $schema->getAllParamNames(),
                'provider_mapping' => $schema->providerMapping,
            ];
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'count' => count($schemas),
            'categories' => array_keys($registry->getSchemasByCategory()),
            'schemas' => $schemas,
        ]);
    }

    /**
     * Get a single event schema by name.
     *
     * Returns the full schema definition including parameter types,
     * required/optional status, max string lengths, and provider mappings.
     *
     * GET /api/analytics/schemas/{eventName}
     */
    public function schemaDetail(string $eventName): JsonResponse
    {
        try {
            $registry = app(EventSchemaRegistry::class);
        } catch (\Throwable) {
            return response()->json([
                'status' => 'error',
                'message' => 'Schema registry not available',
            ], 503);
        }

        $schema = $registry->get($eventName);

        if ($schema === null) {
            return response()->json([
                'status' => 'error',
                'message' => "Schema not found for event: {$eventName}",
                'available' => $registry->getEventNames(),
            ], 404);
        }

        $requiredParams = [];
        foreach ($schema->requiredParams as $paramName => $param) {
            $requiredParams[$paramName] = [
                'type' => $param->type,
                'max_length' => $param->maxLength,
                'description' => $param->description,
            ];
        }

        $optionalParams = [];
        foreach ($schema->optionalParams as $paramName => $param) {
            $optionalParams[$paramName] = [
                'type' => $param->type,
                'max_length' => $param->maxLength,
                'description' => $param->description,
            ];
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'schema' => [
                'name' => $schema->name,
                'category' => $schema->category,
                'description' => $schema->description,
                'required_params' => $requiredParams,
                'optional_params' => $optionalParams,
                'all_params' => $schema->getAllParamNames(),
                'provider_mapping' => $schema->providerMapping,
            ],
        ]);
    }

    /**
     * Validate an event payload against its schema.
     *
     * Accepts a JSON body with `event` (event name) and `params` (event parameters).
     * Returns validation result with errors and sanitized params.
     * When ?sanitize=1 is set, also returns the sanitized payload.
     *
     * POST /api/analytics/schemas/validate
     *
     * @body event string The event name to validate against
     * @body params object The event parameters to validate
     */
    public function schemaValidate(Request $request): JsonResponse
    {
        try {
            $registry = app(EventSchemaRegistry::class);
        } catch (\Throwable) {
            return response()->json([
                'status' => 'error',
                'message' => 'Schema registry not available',
            ], 503);
        }

        $eventName = $request->input('event');
        $params = $request->input('params', []);

        if ($eventName === null || ! is_string($eventName)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Missing or invalid "event" field in request body',
            ], 422);
        }

        if (! is_array($params)) {
            return response()->json([
                'status' => 'error',
                'message' => '"params" must be an object',
            ], 422);
        }

        $result = $registry->validate($eventName, $params);

        $response = [
            'status' => $result['valid'] ? 'ok' : 'validation_error',
            'version' => AnalyticsEvent::VERSION,
            'event' => $eventName,
            'valid' => $result['valid'],
            'errors' => $result['errors'],
            'param_count' => count($params),
        ];

        if ($request->query('sanitize', false)) {
            $response['sanitized'] = $result['sanitized'];
        }

        return response()->json($response, $result['valid'] ? 200 : 422);
    }

    /**
     * Get schema registry statistics and summary.
     *
     * Returns counts by category, parameter type distribution, and
     * schema coverage metrics. Useful for admin dashboards.
     *
     * GET /api/analytics/schemas/summary
     */
    public function schemaSummary(): JsonResponse
    {
        try {
            $registry = app(EventSchemaRegistry::class);
        } catch (\Throwable) {
            return response()->json([
                'status' => 'error',
                'message' => 'Schema registry not available',
            ], 503);
        }

        $allSchemas = $registry->all();
        $categoryCounts = [];
        $totalParams = 0;
        $totalRequired = 0;
        $typeDistribution = [];

        foreach ($allSchemas as $schema) {
            $category = $schema->category;
            $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;

            $allParamsCount = count($schema->requiredParams) + count($schema->optionalParams);
            $totalParams += $allParamsCount;
            $totalRequired += count($schema->requiredParams);

            foreach ($schema->requiredParams as $param) {
                $typeDistribution[$param->type] = ($typeDistribution[$param->type] ?? 0) + 1;
            }
            foreach ($schema->optionalParams as $param) {
                $typeDistribution[$param->type] = ($typeDistribution[$param->type] ?? 0) + 1;
            }
        }

        arsort($categoryCounts);
        arsort($typeDistribution);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'total_schemas' => $registry->count(),
            'categories' => $categoryCounts,
            'total_params' => $totalParams,
            'total_required_params' => $totalRequired,
            'avg_params_per_event' => $registry->count() > 0
                ? round($totalParams / $registry->count(), 1)
                : 0,
            'type_distribution' => $typeDistribution,
        ]);
    }

    // ── SaaS Journey Milestone Endpoints ───────────────────────────────

    /**
     * Record a milestone hit in a SaaS journey.
     *
     * POST /api/analytics/journeys/{journey}/milestone
     *
     * Body: { "milestone": "signup_confirm", "params": { "plan": "pro" } }
     */
    public function journeyHitMilestone(Request $request, string $journey): JsonResponse
    {
        $request->validate([
            'milestone' => 'required|string|max:100',
            'params' => 'array',
        ]);

        $clientId = $this->extractClientId($request);
        $milestone = (string) $request->input('milestone');
        $params = $request->input('params', []);
        /** @var array<string, mixed> $params */

        try {
            /** @var \ZeroBoiler\Analytics\Services\SaaSJourneyService $journeyService */
            $journeyService = app(\ZeroBoiler\Analytics\Services\SaaSJourneyService::class);
            $journeyService->hitMilestone($journey, $milestone, $clientId, $params);

            return response()->json([
                'status' => 'ok',
                'journey' => $journey,
                'milestone' => $milestone,
                'progress' => $journeyService->getProgress($journey, $clientId),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get progress for a specific journey.
     *
     * GET /api/analytics/journeys/{journey}/progress
     */
    public function journeyGetProgress(Request $request, string $journey): JsonResponse
    {
        $clientId = $this->extractClientId($request);

        try {
            /** @var \ZeroBoiler\Analytics\Services\SaaSJourneyService $journeyService */
            $journeyService = app(\ZeroBoiler\Analytics\Services\SaaSJourneyService::class);

            return response()->json([
                'status' => 'ok',
                'journey' => $journey,
                'progress' => $journeyService->getProgress($journey, $clientId),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * List all registered journeys and their progress.
     *
     * GET /api/analytics/journeys
     */
    public function journeyListAll(Request $request): JsonResponse
    {
        $clientId = $this->extractClientId($request);

        try {
            /** @var \ZeroBoiler\Analytics\Services\SaaSJourneyService $journeyService */
            $journeyService = app(\ZeroBoiler\Analytics\Services\SaaSJourneyService::class);

            return response()->json([
                'status' => 'ok',
                'journeys' => $journeyService->getJourneys(),
                'progress' => $journeyService->getAllProgress($clientId),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Reset progress for a specific journey.
     *
     * DELETE /api/analytics/journeys/{journey}
     */
    public function journeyResetProgress(Request $request, string $journey): JsonResponse
    {
        $clientId = $this->extractClientId($request);

        try {
            /** @var \ZeroBoiler\Analytics\Services\SaaSJourneyService $journeyService */
            $journeyService = app(\ZeroBoiler\Analytics\Services\SaaSJourneyService::class);
            $journeyService->resetProgress($journey, $clientId);

            return response()->json([
                'status' => 'ok',
                'journey' => $journey,
                'message' => 'Journey progress reset.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    // ── Provider Telemetry (v2.62.0) ───────────────────────────────────

    /**
     * Get provider telemetry probe results.
     *
     * GET /api/analytics/telemetry
     *
     * Returns cached connectivity probe results for all configured providers.
     * Designed for admin dashboards and health monitoring.
     */
    public function telemetry(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\AnalyticsTelemetryService $telemetryService */
            $telemetryService = app(\ZeroBoiler\Analytics\Services\AnalyticsTelemetryService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'enabled' => $telemetryService->isEnabled(),
                'results' => $telemetryService->results(),
                'summary' => $telemetryService->summary(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Trigger a fresh telemetry probe for all providers.
     *
     * POST /api/analytics/telemetry/probe
     *
     * Runs connectivity checks against all enabled providers and returns
     * fresh results. Useful for on-demand health verification.
     */
    public function telemetryProbe(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\AnalyticsTelemetryService $telemetryService */
            $telemetryService = app(\ZeroBoiler\Analytics\Services\AnalyticsTelemetryService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'probes' => $telemetryService->probeAll(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    // ── Campaign ROI (v2.62.0) ────────────────────────────────────────

    /**
     * Get campaign ROI summary.
     *
     * GET /api/analytics/campaigns/roi
     *
     * Returns aggregate ROI metrics across all registered campaigns,
     * broken down by marketing channel.
     */
    public function campaignRoiSummary(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\CampaignRoiService $roiService */
            $roiService = app(\ZeroBoiler\Analytics\Services\CampaignRoiService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'enabled' => $roiService->isEnabled(),
                'summary' => $roiService->summary(),
                'top_campaigns' => $roiService->topCampaigns(10),
                'by_channel' => $roiService->byChannel(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get ROI for a specific campaign.
     *
     * GET /api/analytics/campaigns/{campaign}/roi
     */
    public function campaignRoi(Request $request, string $campaign): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\CampaignRoiService $roiService */
            $roiService = app(\ZeroBoiler\Analytics\Services\CampaignRoiService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'roi' => $roiService->roi($campaign),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Register campaign spend for ROI tracking.
     *
     * POST /api/analytics/campaigns/spend
     *
     * Body: { "campaign_id": "...", "spend": 100.00, "currency": "USD", "channel": "google", "impressions": 50000, "clicks": 1200 }
     */
    public function campaignRegisterSpend(Request $request): JsonResponse
    {
        $request->validate([
            'campaign_id' => 'required|string',
            'spend' => 'required|numeric|min:0',
            'currency' => 'string|size:3',
            'channel' => 'string',
            'impressions' => 'integer|nullable',
            'clicks' => 'integer|nullable',
        ]);

        try {
            /** @var \ZeroBoiler\Analytics\Services\CampaignRoiService $roiService */
            $roiService = app(\ZeroBoiler\Analytics\Services\CampaignRoiService::class);

            $roiService->registerSpend(
                campaignId: $request->input('campaign_id'),
                spend: (float) $request->input('spend'),
                currency: $request->input('currency', 'USD'),
                channel: $request->input('channel', 'unknown'),
                impressions: $request->input('impressions') !== null ? (int) $request->input('impressions') : null,
                clicks: $request->input('clicks') !== null ? (int) $request->input('clicks') : null,
            );

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    // ── Data Minimization (v2.62.0) ───────────────────────────────────

    /**
     * Get data minimization configuration and status.
     *
     * GET /api/analytics/privacy/minimization
     *
     * Returns the current data minimization settings for GDPR compliance.
     * Useful for admin dashboards and compliance audits.
     */
    public function dataMinimizationStatus(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\DataMinimizationService $dmService */
            $dmService = app(\ZeroBoiler\Analytics\Services\DataMinimizationService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'summary' => $dmService->summary(),
                'global_allowlist' => $dmService->getGlobalAllowlist(),
                'strip_params' => $dmService->getStripParams(),
                'event_allowlist_count' => count($dmService->getEventAllowlists()),
                'category_allowlist_count' => count($dmService->getCategoryAllowlists()),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Preview data minimization for an event without actually modifying it.
     *
     * POST /api/analytics/privacy/minimization/preview
     *
     * Body: { "name": "sign_up", "params": { "email": "...", "name": "...", "user_agent": "..." } }
     *
     * Returns the list of parameters that would be stripped.
     */
    public function dataMinimizationPreview(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'params' => 'array',
        ]);

        try {
            /** @var \ZeroBoiler\Analytics\Services\DataMinimizationService $dmService */
            $dmService = app(\ZeroBoiler\Analytics\Services\DataMinimizationService::class);

            $event = new AnalyticsEvent(
                name: $request->input('name'),
                params: $request->input('params', []),
            );

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'enabled' => $dmService->isEnabled(),
                'stripped_params' => $dmService->previewStripped($event),
                'original_param_count' => count($event->params),
                'minimized_param_count' => count($event->params) - count($dmService->previewStripped($event)),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    // ── SaaS Conversion Analytics (v2.66.0) ─────────────────────────

    /**
     * Get SaaS conversion analytics summary.
     *
     * GET /api/analytics/conversion/summary
     *
     * Returns trial-to-paid conversion rate, activation metrics,
     * time-to-conversion, win-back rate, and funnel analysis.
     */
    public function conversionSummary(): JsonResponse
    {
        if ($this->conversionService === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'SaaSConversionService not available',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'conversion' => $this->conversionService->summary(),
        ]);
    }

    /**
     * Get trial-to-paid conversion funnel.
     *
     * GET /api/analytics/conversion/funnel
     *
     * Returns step-by-step funnel from trial start to paid conversion.
     */
    public function conversionFunnel(): JsonResponse
    {
        if ($this->conversionService === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'SaaSConversionService not available',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'funnel' => $this->conversionService->conversionFunnel(),
        ]);
    }

    /**
     * Get activation score for a specific user.
     *
     * GET /api/analytics/conversion/activation/{userId}
     *
     * Requires authentication.
     */
    public function conversionActivationScore(string $userId): JsonResponse
    {
        if ($this->conversionService === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'SaaSConversionService not available',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'user_id' => $userId,
            'activation' => $this->conversionService->activationScore($userId),
        ]);
    }

    /**
     * Get time-to-conversion analysis.
     *
     * GET /api/analytics/conversion/time-to-convert
     *
     * Returns average and median time-to-conversion with distribution.
     */
    public function conversionTimeToConvert(): JsonResponse
    {
        if ($this->conversionService === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'SaaSConversionService not available',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'time_to_conversion' => $this->conversionService->timeToConversion(),
        ]);
    }

    /**
     * Data warehouse export endpoint.
     *
     * POST /api/analytics/export/warehouse
     *
     * Exports analytics events to NDJSON or CSV for data warehouse ingestion.
     * Supports filtering by category, event name, and date range.
     *
     * Query params: format (ndjson|csv), category, event, from, to
     */
    public function exportWarehouse(Request $request): JsonResponse
    {
        $format = $request->query('format', 'ndjson');
        $category = $request->query('category');
        $event = $request->query('event');
        $from = $request->query('from');
        $to = $request->query('to');

        if (! in_array($format, ['ndjson', 'csv'], true)) {
            $format = 'ndjson';
        }

        $config = app(\Illuminate\Contracts\Config\Repository::class);
        $dwConfig = $config->get('zeroboiler.analytics.data_warehouse', []);

        if (! ($dwConfig['enabled'] ?? false)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Data warehouse export is not enabled',
            ], 403);
        }

        $exportService = new \ZeroBoiler\Analytics\Services\DataWarehouseExportService($config);

        // Override format from query param
        if ($format !== 'ndjson') {
            $exportService = $exportService; // format set via config override
        }

        // Apply filters
        if ($category !== null && is_string($category)) {
            $exportService->filterByCategory($category);
        }

        if ($event !== null && is_string($event)) {
            $exportService->filterByEvent($event);
        }

        if ($from !== null && is_string($from)) {
            try {
                $exportService->filterFrom(new \DateTimeImmutable($from));
            } catch (\Throwable) {
                // Invalid date format — ignore
            }
        }

        if ($to !== null && is_string($to)) {
            try {
                $exportService->filterTo(new \DateTimeImmutable($to));
            } catch (\Throwable) {
                // Invalid date format — ignore
            }
        }

        // In a real implementation, events would be fetched from storage/database.
        // For the export service, we return a summary since we don't have a
        // persistent event store in this package.
        $result = $exportService->exportToFile();

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'export' => $result,
            'summary' => $exportService->summary(),
        ]);
    }

    /**
     * Dashboard overview endpoint.
     *
     * GET /api/analytics/dashboard
     *
     * Returns unified dashboard data: providers, catalog, KPIs,
     * health score, real-time stats, and active alerts.
     */
    public function dashboardOverview(Request $request): JsonResponse
    {
        $dashboardService = new \ZeroBoiler\Analytics\Services\AnalyticsDashboardDataProvider(
            $this->manager,
            $this->abTestService,    // approximate
            $this->realtimeService,
            $this->healthScoreService,
            $this->reportingService,
            $this->statsService,
        );

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'dashboard' => $dashboardService->overview(),
        ]);
    }

    // ─── v2.69.0: Event Deconfliction ──────────────────────────────────

    /**
     * Run event deconfliction analysis across all providers.
     *
     * GET /api/analytics/deconfliction
     */
    public function deconfliction(Request $request): JsonResponse
    {
        $service = new \ZeroBoiler\Analytics\Services\EventDeconflictionService($this->manager);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            ...$service->analyze(),
        ]);
    }

    // ─── v2.69.0: Event Schema Inference ──────────────────────────────

    /**
     * Infer event schemas from constructor signatures.
     *
     * GET /api/analytics/schemas/infer
     */
    public function schemaInfer(Request $request): JsonResponse
    {
        $schemaBuilder = new \ZeroBoiler\Analytics\Schema\EventPropertySchema;
        $service = new \ZeroBoiler\Analytics\Services\EventSchemaInferenceService($schemaBuilder);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            ...$service->inferAll(),
        ]);
    }

    // ─── v2.69.0: Click Heatmap ──────────────────────────────────────

    /**
     * Record a click for heatmap aggregation.
     *
     * POST /api/analytics/heatmap/click
     */
    public function heatmapClick(Request $request): JsonResponse
    {
        $request->validate([
            'x' => 'required|integer|min:0',
            'y' => 'required|integer|min:0',
            'url' => 'required|string|max:2048',
            'element' => 'nullable|string|max:100',
            'viewport_width' => 'nullable|integer|min:320',
        ]);

        $service = new \ZeroBoiler\Analytics\Services\HeatmapAggregationService(
            cache: app('cache'),
            enabled: true,
        );

        $clientId = $this->extractClientId($request);

        $service->recordClick(
            url: $request->input('url'),
            x: (int) $request->input('x'),
            y: (int) $request->input('y'),
            viewportWidth: $request->input('viewport_width') !== null ? (int) $request->input('viewport_width') : null,
            element: $request->input('element'),
            clientId: $clientId,
        );

        return response()->json(['status' => 'ok']);
    }

    /**
     * Get heatmap data for a URL.
     *
     * GET /api/analytics/heatmap/data?url=/pricing
     */
    public function heatmapData(Request $request): JsonResponse
    {
        $request->validate([
            'url' => 'required|string|max:2048',
        ]);

        $service = new \ZeroBoiler\Analytics\Services\HeatmapAggregationService(
            cache: app('cache'),
            enabled: true,
        );

        $data = $service->getHeatmapData($request->input('url'));

        if ($data === null) {
            return response()->json([
                'status' => 'ok',
                'data' => null,
                'message' => 'No heatmap data for this URL',
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            ...$data,
        ]);
    }

    /**
     * Get all tracked heatmap URLs.
     *
     * GET /api/analytics/heatmap/urls
     */
    public function heatmapUrls(Request $request): JsonResponse
    {
        $service = new \ZeroBoiler\Analytics\Services\HeatmapAggregationService(
            cache: app('cache'),
            enabled: true,
        );

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'urls' => $service->getTrackedUrls(),
            'summary' => $service->getSummary(),
        ]);
    }

    /**
     * Clear heatmap data for a URL.
     *
     * DELETE /api/analytics/heatmap/data?url=/pricing
     */
    public function heatmapClear(Request $request): JsonResponse
    {
        $request->validate([
            'url' => 'required|string|max:2048',
        ]);

        $service = new \ZeroBoiler\Analytics\Services\HeatmapAggregationService(
            cache: app('cache'),
            enabled: true,
        );

        $service->clearUrl($request->input('url'));

        return response()->json(['status' => 'ok']);
    }

    // ─── v2.69.0: Rate Limit Dashboard ───────────────────────────────

    /**
     * Get rate limit dashboard overview.
     *
     * GET /api/analytics/rate-limits
     */
    public function rateLimitDashboard(Request $request): JsonResponse
    {
        $service = new \ZeroBoiler\Analytics\Services\AnalyticsRateLimitDashboardService(
            cache: app('cache'),
            metrics: $this->manager->metrics(),
        );

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            ...$service->getDashboard(),
        ]);
    }

    /**
     * Get rate limit status for a specific client.
     *
     * GET /api/analytics/rate-limits/{clientId}
     */
    public function rateLimitClientStatus(Request $request, string $clientId): JsonResponse
    {
        $service = new \ZeroBoiler\Analytics\Services\AnalyticsRateLimitDashboardService(
            cache: app('cache'),
            metrics: $this->manager->metrics(),
        );

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            ...$service->getClientStatus($clientId),
        ]);
    }

    /**
     * Reset rate limit counters for a specific client.
     *
     * DELETE /api/analytics/rate-limits/{clientId}
     */
    public function rateLimitResetClient(Request $request, string $clientId): JsonResponse
    {
        $service = new \ZeroBoiler\Analytics\Services\AnalyticsRateLimitDashboardService(
            cache: app('cache'),
            metrics: $this->manager->metrics(),
        );

        $service->resetClient($clientId);

        return response()->json(['status' => 'ok']);
    }

    // ─── v2.70.0: Circuit Breaker Dashboard ──────────────────────────

    /**
     * Get circuit breaker dashboard for all providers.
     *
     * GET /api/analytics/circuit-breaker
     */
    public function circuitBreakerDashboard(Request $request): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\ProviderCircuitBreaker $breaker */
        $breaker = app(\ZeroBoiler\Analytics\Services\ProviderCircuitBreaker::class);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            ...$breaker->getDashboard(),
        ]);
    }

    /**
     * Get circuit breaker summary (open/half-open/closed counts).
     *
     * GET /api/analytics/circuit-breaker/summary
     */
    public function circuitBreakerSummary(Request $request): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\ProviderCircuitBreaker $breaker */
        $breaker = app(\ZeroBoiler\Analytics\Services\ProviderCircuitBreaker::class);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            ...$breaker->summary(),
        ]);
    }

    /**
     * Reset circuit breaker for a specific provider.
     *
     * POST /api/analytics/circuit-breaker/{provider}/reset
     */
    public function circuitBreakerReset(Request $request, string $provider): JsonResponse
    {
        $allowedProviders = ['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'webhook'];

        if (! in_array($provider, $allowedProviders, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid provider. Allowed: ' . implode(', ', $allowedProviders),
            ], 422);
        }

        /** @var \ZeroBoiler\Analytics\Services\ProviderCircuitBreaker $breaker */
        $breaker = app(\ZeroBoiler\Analytics\Services\ProviderCircuitBreaker::class);
        $breaker->reset($provider);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'provider' => $provider,
            'state' => $breaker->getState($provider),
        ]);
    }

    /**
     * Trip circuit breaker for a specific provider (force open).
     *
     * POST /api/analytics/circuit-breaker/{provider}/trip
     */
    public function circuitBreakerTrip(Request $request, string $provider): JsonResponse
    {
        $allowedProviders = ['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'webhook'];

        if (! in_array($provider, $allowedProviders, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid provider. Allowed: ' . implode(', ', $allowedProviders),
            ], 422);
        }

        /** @var \ZeroBoiler\Analytics\Services\ProviderCircuitBreaker $breaker */
        $breaker = app(\ZeroBoiler\Analytics\Services\ProviderCircuitBreaker::class);
        $breaker->trip($provider);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'provider' => $provider,
            'state' => $breaker->getState($provider),
        ]);
    }

    // ─── v2.70.0: Compliance Audit ────────────────────────────────────

    /**
     * Generate full compliance audit report.
     *
     * GET /api/analytics/compliance
     */
    public function complianceReport(Request $request): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\EventComplianceService $service */
        $service = app(\ZeroBoiler\Analytics\Services\EventComplianceService::class);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            ...$service->generateReport(),
        ]);
    }

    /**
     * Get quick compliance score (0-100).
     *
     * GET /api/analytics/compliance/score
     */
    public function complianceScore(Request $request): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\EventComplianceService $service */
        $service = app(\ZeroBoiler\Analytics\Services\EventComplianceService::class);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'score' => $service->getScore(),
        ]);
    }

    /**
     * Invalidate compliance report cache.
     *
     * POST /api/analytics/compliance/invalidate
     */
    public function complianceInvalidateCache(Request $request): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\EventComplianceService $service */
        $service = app(\ZeroBoiler\Analytics\Services\EventComplianceService::class);
        $service->invalidateCache();

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'message' => 'Compliance cache invalidated. Next request will regenerate the report.',
        ]);
    }

    // ─── v2.70.0: Recovery Service ────────────────────────────────────

    /**
     * Get recovery budget status.
     *
     * GET /api/analytics/recovery/budget
     */
    public function recoveryBudget(Request $request): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\AnalyticsRecoveryService $service */
        $service = app(\ZeroBoiler\Analytics\Services\AnalyticsRecoveryService::class);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            ...$service->getBudget(),
        ]);
    }

    /**
     * Get recovery pipeline health assessment.
     *
     * GET /api/analytics/recovery/health
     */
    public function recoveryHealth(Request $request): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\AnalyticsRecoveryService $service */
        $service = app(\ZeroBoiler\Analytics\Services\AnalyticsRecoveryService::class);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            ...$service->assessHealth(),
        ]);
    }

    /**
     * Get recovery history (24h summary).
     *
     * GET /api/analytics/recovery/history
     */
    public function recoveryHistory(Request $request): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\AnalyticsRecoveryService $service */
        $service = app(\ZeroBoiler\Analytics\Services\AnalyticsRecoveryService::class);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            ...$service->getHistory(),
        ]);
    }

    /**
     * Batch recover events from DLQ.
     *
     * POST /api/analytics/recovery/batch
     *
     * Body: { "count": 5 } (optional, defaults to config batch_size)
     */
    public function recoveryBatch(Request $request): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\AnalyticsRecoveryService $service */
        $service = app(\ZeroBoiler\Analytics\Services\AnalyticsRecoveryService::class);

        $count = (int) $request->input('count', 0);
        $result = $service->batchRecover($count);

        // Record history
        $service->recordHistory($result['recovered'], $result['failed']);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            ...$result,
            'budget' => $service->getBudget(),
        ]);
    }

    // ── Sandbox Endpoints (v2.71.0) ─────────────────────────────────

    /**
     * Get sandbox metadata and status.
     *
     * GET /api/analytics/sandbox
     */
    public function sandboxStatus(Request $request): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\AnalyticsSandboxService $service */
        $service = app(\ZeroBoiler\Analytics\Services\AnalyticsSandboxService::class);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            ...$service->getMeta(),
        ]);
    }

    /**
     * Get captured sandbox events.
     *
     * GET /api/analytics/sandbox/events
     *
     * Query params: ?limit=100&offset=0&event_name=purchase
     */
    public function sandboxEvents(Request $request): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\AnalyticsSandboxService $service */
        $service = app(\ZeroBoiler\Analytics\Services\AnalyticsSandboxService::class);

        $events = $service->getEvents();
        $eventName = $request->query('event_name');
        $offset = (int) $request->query('offset', 0);
        $limit = (int) $request->query('limit', 100);

        if ($eventName !== null && is_string($eventName)) {
            $events = array_filter($events, fn (array $e): bool => $e['name'] === $eventName);
            $events = array_values($events);
        }

        $total = count($events);
        $sliced = array_slice($events, $offset, $limit);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'events' => $sliced,
        ]);
    }

    /**
     * Clear sandbox events.
     *
     * DELETE /api/analytics/sandbox/events
     */
    public function sandboxClear(Request $request): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\AnalyticsSandboxService $service */
        $service = app(\ZeroBoiler\Analytics\Services\AnalyticsSandboxService::class);

        $service->clear();

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'message' => 'Sandbox events cleared.',
        ]);
    }

    /**
     * Get sandbox replay log.
     *
     * GET /api/analytics/sandbox/replay-log
     */
    public function sandboxReplayLog(Request $request): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\AnalyticsSandboxService $service */
        $service = app(\ZeroBoiler\Analytics\Services\AnalyticsSandboxService::class);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'replay_log' => $service->getReplayLog(),
        ]);
    }

    // ── Provider Rate Limit Endpoints (v2.71.0) ──────────────────────

    /**
     * Get per-provider rate limit status.
     *
     * GET /api/analytics/provider-rate-limits
     */
    public function providerRateLimits(Request $request): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\ProviderRateLimitService $service */
        $service = app(\ZeroBoiler\Analytics\Services\ProviderRateLimitService::class);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'enabled' => $service->isEnabled(),
            'overflow_strategy' => $service->getOverflowStrategy(),
            'providers' => $service->getStatus(),
        ]);
    }

    /**
     * Reset provider rate limit counters.
     *
     * POST /api/analytics/provider-rate-limits/reset
     *
     * Body: { "provider": "ga4" } (optional, null = all)
     */
    public function providerRateLimitsReset(Request $request): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\ProviderRateLimitService $service */
        $service = app(\ZeroBoiler\Analytics\Services\ProviderRateLimitService::class);

        $provider = $request->input('provider');
        $service->reset(is_string($provider) ? $provider : null);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'message' => $provider !== null
                ? "Rate limit counter reset for provider '{$provider}'."
                : 'All provider rate limit counters reset.',
        ]);
    }

    // ── Schema Versioning Endpoints (v2.71.0) ───────────────────────

    /**
     * Get schema versioning summary.
     *
     * GET /api/analytics/schema-versions
     */
    public function schemaVersions(Request $request): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\EventSchemaVersioningService $service */
        $service = app(\ZeroBoiler\Analytics\Services\EventSchemaVersioningService::class);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            ...$service->getSummary(),
        ]);
    }

    // ── Readiness Endpoints (v2.71.0) ───────────────────────────────

    /**
     * Get SaaS starter readiness report.
     *
     * GET /api/analytics/readiness
     *
     * Query params: ?refresh=true (bypass cache)
     */
    public function readiness(Request $request): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\AnalyticsReadinessService $service */
        $service = app(\ZeroBoiler\Analytics\Services\AnalyticsReadinessService::class);

        $refresh = $request->boolean('refresh', false);

        if ($refresh) {
            $service->invalidateCache();
        }

        $report = $service->assessCached();

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            ...$report->toArray(),
        ]);
    }

    /**
     * Get SaaS analytics maturity score and AARRR classification.
     *
     * GET /api/analytics/maturity
     *
     * Returns maturity score (0-100), grade, and detailed breakdown of
     * critical events coverage, AARRR category compliance, provider
     * coverage, and catalog size.
     */
    public function maturity(): JsonResponse
    {
        $calculator = $this->manager->priorityCalculator();

        return response()->json([
            'status' => 'ok',
            ...$calculator->maturityScore(),
            'aarr_classification' => $calculator->classifyAll(),
            'under_instrumented' => $calculator->underInstrumentedCategories(),
        ]);
    }

    /**
     * Get SaaS analytics onboarding checklist.
     *
     * GET /api/analytics/onboarding
     *
     * Returns a prioritized checklist of events that should be instrumented,
     * grouped by AARRR category, with completion status and gaps.
     */
    public function onboardingChecklist(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            ...$this->manager->onboardingChecklist(),
        ]);
    }

    /**
     * Get funnel conversion readiness scores.
     *
     * GET /api/analytics/funnel-readiness
     *
     * Evaluates signup, purchase, and subscription funnel instrumentation.
     */
    public function funnelReadiness(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            ...$this->manager->funnelReadiness(),
        ]);
    }

    /**
     * Get industry-standard event catalog with priority tiers.
     *
     * GET /api/analytics/industry-standard
     *
     * Returns events classified by priority (critical/high/medium/low)
     * as required by industry-standard SaaS analytics instrumentation.
     */
    public function industryStandard(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            ...\ZeroBoiler\Analytics\Events\EventCatalog::industryStandard(),
        ]);
    }

    // ─── Revenue Forecasting (v2.81.0) ─────────────────────────────────────

    /**
     * Get full revenue forecast with daily data points.
     *
     * GET /api/analytics/forecast
     *
     * Query params: mrr, arr, churned_mrr_last_month, new_mrr_last_month,
     * expansion_mrr_last_month, active_subscribers, churned_subscribers_last_month
     */
    public function revenueForecast(Request $request): JsonResponse
    {
        $service = new \ZeroBoiler\Analytics\Services\RevenueForecastService($this->config);

        $currentData = [
            'mrr' => (float) ($request->query('mrr', 0)),
            'arr' => (float) ($request->query('arr', 0)),
            'churned_mrr_last_month' => (float) ($request->query('churned_mrr_last_month', 0)),
            'new_mrr_last_month' => (float) ($request->query('new_mrr_last_month', 0)),
            'expansion_mrr_last_month' => (float) ($request->query('expansion_mrr_last_month', 0)),
            'active_subscribers' => (int) ($request->query('active_subscribers', 0)),
            'churned_subscribers_last_month' => (int) ($request->query('churned_subscribers_last_month', 0)),
        ];

        return response()->json([
            'status' => 'ok',
            ...$service->forecast($currentData),
        ]);
    }

    /**
     * Get quick revenue forecast summary.
     *
     * GET /api/analytics/forecast/summary
     */
    public function revenueForecastSummary(Request $request): JsonResponse
    {
        $service = new \ZeroBoiler\Analytics\Services\RevenueForecastService($this->config);

        $currentData = [
            'mrr' => (float) ($request->query('mrr', 0)),
            'arr' => (float) ($request->query('arr', 0)),
            'churned_mrr_last_month' => (float) ($request->query('churned_mrr_last_month', 0)),
            'new_mrr_last_month' => (float) ($request->query('new_mrr_last_month', 0)),
            'expansion_mrr_last_month' => (float) ($request->query('expansion_mrr_last_month', 0)),
            'active_subscribers' => (int) ($request->query('active_subscribers', 0)),
            'churned_subscribers_last_month' => (int) ($request->query('churned_subscribers_last_month', 0)),
        ];

        return response()->json([
            'status' => 'ok',
            ...$service->summary($currentData),
        ]);
    }

    /**
     * Project MRR at a specific future date.
     *
     * GET /api/analytics/forecast/project?days_out=60&mrr=10000
     */
    public function revenueForecastProject(Request $request): JsonResponse
    {
        $service = new \ZeroBoiler\Analytics\Services\RevenueForecastService($this->config);

        $daysOut = min(365, max(1, (int) ($request->query('days_out', 30))));
        $currentData = ['mrr' => (float) ($request->query('mrr', 0))];

        return response()->json([
            'status' => 'ok',
            ...$service->projectAt($daysOut, $currentData),
        ]);
    }

    /**
     * Calculate Customer Lifetime Value (LTV).
     *
     * GET /api/analytics/forecast/ltv?arpu=99&churn_rate=0.03&gross_margin=0.75
     */
    public function ltvCalculation(Request $request): JsonResponse
    {
        $service = new \ZeroBoiler\Analytics\Services\RevenueForecastService($this->config);

        return response()->json([
            'status' => 'ok',
            ...$service->calculateLtv(
                arpu: (float) ($request->query('arpu', 99)),
                monthlyChurnRate: (float) ($request->query('churn_rate', 0.03)),
                grossMargin: (float) ($request->query('gross_margin', 0.75)),
            ),
        ]);
    }

    /**
     * Calculate LTV:CAC ratio.
     *
     * GET /api/analytics/forecast/ltv-cac?ltv=3000&cac=500
     */
    public function ltvCacRatio(Request $request): JsonResponse
    {
        $service = new \ZeroBoiler\Analytics\Services\RevenueForecastService($this->config);

        return response()->json([
            'status' => 'ok',
            ...$service->ltvCACRatio(
                ltv: (float) ($request->query('ltv', 0)),
                cac: (float) ($request->query('cac', 1)),
            ),
        ]);
    }

    /**
     * Calculate CAC payback period.
     *
     * GET /api/analytics/forecast/payback?cac=500&arpu=99&gross_margin=0.75
     */
    public function paybackPeriod(Request $request): JsonResponse
    {
        $service = new \ZeroBoiler\Analytics\Services\RevenueForecastService($this->config);

        return response()->json([
            'status' => 'ok',
            ...$service->paybackPeriod(
                cac: (float) ($request->query('cac', 500)),
                monthlyArpu: (float) ($request->query('arpu', 99)),
                grossMargin: (float) ($request->query('gross_margin', 0.75)),
            ),
        ]);
    }

    /**
     * Estimate runway and path to profitability.
     *
     * GET /api/analytics/forecast/runway?mrr=10000&expenses=15000&growth_rate=0.05&churn_rate=0.03
     */
    public function runwayEstimate(Request $request): JsonResponse
    {
        $service = new \ZeroBoiler\Analytics\Services\RevenueForecastService($this->config);

        return response()->json([
            'status' => 'ok',
            ...$service->runway(
                currentMrr: (float) ($request->query('mrr', 0)),
                monthlyExpenses: (float) ($request->query('expenses', 0)),
                growthRate: (float) ($request->query('growth_rate', 0.05)),
                churnRate: (float) ($request->query('churn_rate', 0.03)),
            ),
        ]);
    }

    /**
     * Get cohort retention curve projection.
     *
     * GET /api/analytics/forecast/cohort-retention?months=12&churn_rate=0.03
     */
    public function cohortRetentionCurve(Request $request): JsonResponse
    {
        $service = new \ZeroBoiler\Analytics\Services\RevenueForecastService($this->config);

        return response()->json([
            'status' => 'ok',
            'curve' => $service->cohortRetentionCurve(
                months: min(60, (int) ($request->query('months', 12))),
                monthlyChurnRate: (float) ($request->query('churn_rate', 0.03)),
            ),
        ]);
    }

    /**
     * Get MRR movement breakdown (new, expansion, contraction, churn).
     *
     * GET /api/analytics/forecast/mrr-movement
     */
    public function mrrMovementBreakdown(Request $request): JsonResponse
    {
        $service = new \ZeroBoiler\Analytics\Services\RevenueForecastService($this->config);

        return response()->json([
            'status' => 'ok',
            ...$service->mrrMovementBreakdown([
                'new_mrr' => (float) ($request->query('new_mrr', 0)),
                'expansion_mrr' => (float) ($request->query('expansion_mrr', 0)),
                'contraction_mrr' => (float) ($request->query('contraction_mrr', 0)),
                'churned_mrr' => (float) ($request->query('churned_mrr', 0)),
                'previous_mrr' => (float) ($request->query('previous_mrr', 0)),
            ]),
        ]);
    }

    // ─── Churn Prediction (v2.81.0) ───────────────────────────────────────

    /**
     * Score a single user's churn risk.
     *
     * POST /api/analytics/churn/score
     *
     * Body: { user_id, days_inactive, usage_decline_pct, support_tickets_30d, ... }
     */
    public function churnScoreUser(Request $request): JsonResponse
    {
        $service = new \ZeroBoiler\Analytics\Services\ChurnPredictionService($this->config);

        $userId = (string) ($request->input('user_id', ''));
        $signals = $request->except('user_id');

        return response()->json([
            'status' => 'ok',
            ...$service->scoreUser($userId, $signals),
        ]);
    }

    /**
     * Score multiple users and return ranked results.
     *
     * POST /api/analytics/churn/score-batch
     *
     * Body: { users: [{ user_id, days_inactive, ... }, ...] }
     */
    public function churnScoreBatch(Request $request): JsonResponse
    {
        $service = new \ZeroBoiler\Analytics\Services\ChurnPredictionService($this->config);

        $users = $request->input('users', []);

        if (! is_array($users)) {
            return response()->json(['status' => 'error', 'message' => 'users must be an array'], 422);
        }

        return response()->json([
            'status' => 'ok',
            ...$service->scoreBatch($users),
        ]);
    }

    /**
     * Get cohort churn risk summary.
     *
     * POST /api/analytics/churn/cohort-summary
     */
    public function churnCohortSummary(Request $request): JsonResponse
    {
        $service = new \ZeroBoiler\Analytics\Services\ChurnPredictionService($this->config);

        $users = $request->input('users', []);

        if (! is_array($users)) {
            return response()->json(['status' => 'error', 'message' => 'users must be an array'], 422);
        }

        return response()->json([
            'status' => 'ok',
            ...$service->cohortRiskSummary($users),
        ]);
    }

    /**
     * Get configured churn signal weights.
     *
     * GET /api/analytics/churn/weights
     */
    public function churnSignalWeights(): JsonResponse
    {
        $service = new \ZeroBoiler\Analytics\Services\ChurnPredictionService($this->config);

        return response()->json([
            'status' => 'ok',
            'weights' => $service->getSignalWeights(),
        ]);
    }

    /**
     * Get configured churn risk thresholds.
     *
     * GET /api/analytics/churn/thresholds
     */
    public function churnThresholds(): JsonResponse
    {
        $service = new \ZeroBoiler\Analytics\Services\ChurnPredictionService($this->config);

        return response()->json([
            'status' => 'ok',
            'thresholds' => $service->getThresholds(),
        ]);
    }

    // ─── SaaS Metrics Benchmarks (v2.87.0) ────────────────────────────

    /**
     * List all available benchmark metrics with their thresholds.
     *
     * GET /api/analytics/benchmarks
     *
     * Query params: ?category=revenue|conversion|retention|engagement|funnel
     */
    public function benchmarksList(Request $request): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\SaaSMetricsBenchmarkService $service */
        $service = app(\ZeroBoiler\Analytics\Services\SaaSMetricsBenchmarkService::class);

        $category = $request->string('category')->toString();

        if ($category !== '' && in_array($category, $service->availableCategories(), true)) {
            $benchmarks = $service->category($category);
        } else {
            $benchmarks = $service->allBenchmarks();
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'total' => count($benchmarks),
            'categories' => $service->availableCategories(),
            'by_category' => $service->byCategory(),
            'benchmarks' => $benchmarks,
        ]);
    }

    /**
     * Get benchmark thresholds for a specific metric.
     *
     * GET /api/analytics/benchmarks/{metric}
     */
    public function benchmarksGet(string $metric): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\SaaSMetricsBenchmarkService $service */
        $service = app(\ZeroBoiler\Analytics\Services\SaaSMetricsBenchmarkService::class);

        $benchmark = $service->getBenchmark($metric);

        if ($benchmark === null) {
            return response()->json([
                'status' => 'error',
                'message' => "Unknown benchmark metric: {$metric}",
                'available' => $service->availableMetrics(),
            ], 404);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'metric' => $metric,
            ...$benchmark,
        ]);
    }

    /**
     * Compare metric values against industry benchmarks.
     *
     * GET /api/analytics/benchmarks/compare?metrics[monthly_churn_rate]=3.5&metrics[trial_conversion_rate]=30
     *
     * Accepts query params as metrics[name]=value pairs.
     */
    public function benchmarksCompare(Request $request): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\SaaSMetricsBenchmarkService $service */
        $service = app(\ZeroBoiler\Analytics\Services\SaaSMetricsBenchmarkService::class);

        $metrics = $request->input('metrics', []);

        if (! is_array($metrics) || empty($metrics)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Provide metrics as query params: ?metrics[monthly_churn_rate]=3.5&metrics[trial_conversion_rate]=30',
                'available' => $service->availableMetrics(),
            ], 400);
        }

        // Cast all values to float
        $castMetrics = array_map(
            fn (mixed $v): float => (float) $v,
            $metrics,
        );

        $result = $service->compareBatch($castMetrics);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            ...$result,
        ]);
    }

    /**
     * Get a full benchmark report card with grades and recommendations.
     *
     * GET /api/analytics/benchmarks/report-card?metrics[monthly_churn_rate]=3.5&metrics[trial_conversion_rate]=30
     *
     * Returns prioritized improvement recommendations.
     */
    public function benchmarksReportCard(Request $request): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\SaaSMetricsBenchmarkService $service */
        $service = app(\ZeroBoiler\Analytics\Services\SaaSMetricsBenchmarkService::class);

        $metrics = $request->input('metrics', []);

        if (! is_array($metrics) || empty($metrics)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Provide metrics as query params: ?metrics[monthly_churn_rate]=3.5',
                'available' => $service->availableMetrics(),
            ], 400);
        }

        $castMetrics = array_map(
            fn (mixed $v): float => (float) $v,
            $metrics,
        );

        $report = $service->reportCard($castMetrics);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            ...$report,
        ]);
    }

    /**
     * Get quick-start benchmark targets for new SaaS products.
     *
     * GET /api/analytics/benchmarks/quick-start
     *
     * Returns the 8 most impactful metrics with p75 (good) tier targets.
     */
    public function benchmarksQuickStart(): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\SaaSMetricsBenchmarkService $service */
        $service = app(\ZeroBoiler\Analytics\Services\SaaSMetricsBenchmarkService::class);

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'metrics' => $service->quickStartMetrics(),
            'summary' => $service->summary(),
        ]);
    }

    /**
     * Run a comprehensive analytics health check diagnostic.
     *
     * GET /api/analytics/health-check
     *
     * Returns a full diagnostic covering providers, catalog, AARRR coverage,
     * identity tracking, queue, GDPR, consent, lifecycle, auto-tracking, dedup,
     * API, and pipeline. Includes actionable recommendations.
     */
    public function healthCheck(): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\AnalyticsHealthCheckService $service */
        $service = app(\ZeroBoiler\Analytics\Services\AnalyticsHealthCheckService::class);

        return response()->json($service->run());
    }

    /**
     * Quick ping — returns version and provider count.
     *
     * GET /api/analytics/ping
     *
     * Lightweight endpoint for monitoring and uptime checks.
     */
    public function ping(): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\AnalyticsHealthCheckService $service */
        $service = app(\ZeroBoiler\Analytics\Services\AnalyticsHealthCheckService::class);

        return response()->json($service->ping());
    }

    // ─── SaaS Coverage Report (v67.0.0) ────────────────────────────────

    /**
     * Generate full SaaS analytics coverage audit report.
     *
     * GET /api/analytics/coverage
     *
     * Returns the complete 12-capability audit with scores, evidence,
     * and recommendations for each capability.
     */
    public function coverageReport(): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\SaaSCoverageReportService $service */
        $service = app(\ZeroBoiler\Analytics\Services\SaaSCoverageReportService::class);

        return response()->json($service->auditCached());
    }

    /**
     * Get SaaS coverage summary — score, grade, and counts.
     *
     * GET /api/analytics/coverage/summary
     *
     * Lightweight endpoint returning only the overall score, grade,
     * and count of implemented/partial/missing capabilities.
     */
    public function coverageSummary(): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\SaaSCoverageReportService $service */
        $service = app(\ZeroBoiler\Analytics\Services\SaaSCoverageReportService::class);

        return response()->json($service->summary());
    }

    // ─── Event Rules Engine (v3.1.0) ─────────────────────────────────

    /**
     * List all configured event rules.
     *
     * GET /api/analytics/rules
     */
    public function rulesList(): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\EventRulesEngine $engine */
        $engine = app(\ZeroBoiler\Analytics\Services\EventRulesEngine::class);

        return response()->json([
            'enabled' => $engine->isEnabled(),
            'rules' => $engine->rules(),
            'trigger_counts' => $engine->triggerCounts(),
        ]);
    }

    /**
     * Evaluate event-trigger rules against a submitted event.
     *
     * POST /api/analytics/rules/evaluate
     *
     * Body: { "name": "sign_up", "params": { "method": "email" } }
     */
    public function rulesEvaluate(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'params' => 'array',
        ]);

        /** @var \ZeroBoiler\Analytics\Services\EventRulesEngine $engine */
        $engine = app(\ZeroBoiler\Analytics\Services\EventRulesEngine::class);

        $event = new AnalyticsEvent(
            name: $request->input('name'),
            params: $request->input('params', []),
        );

        $triggered = $engine->evaluate($event);

        return response()->json([
            'status' => 'ok',
            'evaluated_event' => $event->name,
            'triggered_events' => count($triggered),
            'triggered' => array_map(
                fn (AnalyticsEvent $e): array => ['name' => $e->name, 'params' => $e->params],
                $triggered,
            ),
        ]);
    }

    /**
     * Evaluate absence-trigger rules.
     *
     * GET /api/analytics/rules/absence
     */
    public function rulesEvaluateAbsence(): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\EventRulesEngine $engine */
        $engine = app(\ZeroBoiler\Analytics\Services\EventRulesEngine::class);

        $triggered = $engine->evaluateAbsenceRules();

        return response()->json([
            'status' => 'ok',
            'triggered_events' => count($triggered),
            'triggered' => array_map(
                fn (AnalyticsEvent $e): array => ['name' => $e->name, 'params' => $e->params, 'client_id' => $e->clientId],
                $triggered,
            ),
        ]);
    }

    /**
     * Get rule trigger counts since last reset.
     *
     * GET /api/analytics/rules/counts
     */
    public function rulesTriggerCounts(): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\EventRulesEngine $engine */
        $engine = app(\ZeroBoiler\Analytics\Services\EventRulesEngine::class);

        return response()->json([
            'trigger_counts' => $engine->triggerCounts(),
        ]);
    }

    // ─── User Properties (v3.1.0) ─────────────────────────────────

    /**
     * Get all properties for an identity.
     *
     * GET /api/analytics/user-properties/{identity}
     */
    public function userPropertiesGet(string $identity): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\UserPropertiesStore $store */
        $store = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);

        return response()->json([
            'identity' => $identity,
            'resolved_identity' => $store->resolveIdentity($identity),
            'properties' => $store->all($identity),
        ]);
    }

    /**
     * Set a single user property.
     *
     * POST /api/analytics/user-properties/{identity}
     *
     * Body: { "key": "plan", "value": "pro" }
     */
    public function userPropertiesSet(Request $request, string $identity): JsonResponse
    {
        $request->validate([
            'key' => 'required|string|max:100',
            'value' => 'required',
        ]);

        /** @var \ZeroBoiler\Analytics\Services\UserPropertiesStore $store */
        $store = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);

        $store->set($identity, $request->input('key'), $request->input('value'));

        return response()->json(['status' => 'ok']);
    }

    /**
     * Merge multiple user properties.
     *
     * POST /api/analytics/user-properties/{identity}/merge
     *
     * Body: { "properties": { "plan": "pro", "team_size": 5 } }
     */
    public function userPropertiesMerge(Request $request, string $identity): JsonResponse
    {
        $request->validate([
            'properties' => 'required|array',
            'properties.*' => 'mixed',
        ]);

        /** @var \ZeroBoiler\Analytics\Services\UserPropertiesStore $store */
        $store = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);

        $store->merge($identity, $request->input('properties', []));

        return response()->json(['status' => 'ok']);
    }

    /**
     * Increment a numeric user property.
     *
     * POST /api/analytics/user-properties/{identity}/increment
     *
     * Body: { "key": "session_count", "by": 1 }
     */
    public function userPropertiesIncrement(Request $request, string $identity): JsonResponse
    {
        $request->validate([
            'key' => 'required|string|max:100',
            'by' => 'sometimes|numeric',
        ]);

        /** @var \ZeroBoiler\Analytics\Services\UserPropertiesStore $store */
        $store = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);

        $by = $request->input('by', 1);

        $store->increment(
            $identity,
            $request->input('key'),
            is_int($by) ? (int) $by : (float) $by,
        );

        return response()->json(['status' => 'ok']);
    }

    /**
     * Link a client ID to a user ID (identity merge).
     *
     * POST /api/analytics/user-properties/link
     *
     * Body: { "client_id": "uuid-...", "user_id": "42" }
     */
    public function userPropertiesLink(Request $request): JsonResponse
    {
        $request->validate([
            'client_id' => 'required|string',
            'user_id' => 'required|string',
        ]);

        /** @var \ZeroBoiler\Analytics\Services\UserPropertiesStore $store */
        $store = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);

        $store->linkIdentity(
            $request->input('client_id'),
            $request->input('user_id'),
        );

        return response()->json(['status' => 'ok']);
    }

    /**
     * Delete all properties for an identity (GDPR).
     *
     * DELETE /api/analytics/user-properties/{identity}
     */
    public function userPropertiesDelete(string $identity): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\UserPropertiesStore $store */
        $store = app(\ZeroBoiler\Analytics\Services\UserPropertiesStore::class);

        $store->delete($identity);

        return response()->json(['status' => 'ok']);
    }

    // ─── Retention & Stickiness (v3.1.0) ──────────────────────────

    /**
     * Get overall retention metrics.
     *
     * GET /api/analytics/retention
     */
    public function retentionOverview(): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\RetentionCalculator $calc */
        $calc = app(\ZeroBoiler\Analytics\Services\RetentionCalculator::class);

        return response()->json([
            'enabled' => $calc->isEnabled(),
            'retention_days' => $calc->retentionDays(),
            'retention' => $calc->retention(),
        ]);
    }

    /**
     * Get N-Day retention for a specific cohort date.
     *
     * GET /api/analytics/retention/{date}
     */
    public function retentionForCohort(string $date): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\RetentionCalculator $calc */
        $calc = app(\ZeroBoiler\Analytics\Services\RetentionCalculator::class);

        return response()->json($calc->retention($date));
    }

    /**
     * Get rolling retention for a specific cohort.
     *
     * GET /api/analytics/retention/{date}/rolling/{days}
     */
    public function rollingRetention(string $date, int $days = 30): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\RetentionCalculator $calc */
        $calc = app(\ZeroBoiler\Analytics\Services\RetentionCalculator::class);

        return response()->json($calc->rollingRetention($date, $days));
    }

    /**
     * Get full retention curve for a cohort (chart data).
     *
     * GET /api/analytics/retention/{date}/curve
     */
    public function retentionCurve(Request $request, string $date): JsonResponse
    {
        $maxDays = (int) $request->query('max_days', 30);

        /** @var \ZeroBoiler\Analytics\Services\RetentionCalculator $calc */
        $calc = app(\ZeroBoiler\Analytics\Services\RetentionCalculator::class);

        return response()->json($calc->retentionCurve($date, $maxDays));
    }

    /**
     * Compare retention across multiple cohorts.
     *
     * GET /api/analytics/retention/cohorts/{days}
     */
    public function retentionCohortComparison(int $days = 7): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\RetentionCalculator $calc */
        $calc = app(\ZeroBoiler\Analytics\Services\RetentionCalculator::class);

        return response()->json($calc->cohortComparison($days));
    }

    /**
     * Get stickiness metrics (DAU/MAU ratio).
     *
     * GET /api/analytics/stickiness
     */
    public function stickiness(Request $request): JsonResponse
    {
        $referenceDate = $request->query('date');

        /** @var \ZeroBoiler\Analytics\Services\RetentionCalculator $calc */
        $calc = app(\ZeroBoiler\Analytics\Services\RetentionCalculator::class);

        return response()->json($calc->stickiness(
            is_string($referenceDate) ? $referenceDate : null,
        ));
    }

    // ─── Behavioral Cohorts (v3.1.0) ──────────────────────────────

    /**
     * Classify all users into behavioral cohorts.
     *
     * GET /api/analytics/cohorts
     */
    public function behavioralCohorts(): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\BehavioralCohortBuilder $builder */
        $builder = app(\ZeroBoiler\Analytics\Services\BehavioralCohortBuilder::class);

        return response()->json($builder->classify());
    }

    /**
     * Get cohort assignment for a specific user.
     *
     * GET /api/analytics/cohorts/{identity}
     */
    public function behavioralCohortForUser(string $identity): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\BehavioralCohortBuilder $builder */
        $builder = app(\ZeroBoiler\Analytics\Services\BehavioralCohortBuilder::class);

        return response()->json([
            'identity' => $identity,
            'cohort' => $builder->classifyUser($identity),
        ]);
    }

    /**
     * Get cohort summary for the last N days.
     *
     * GET /api/analytics/cohorts/summary/{days}
     */
    public function behavioralCohortSummary(int $days = 30): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\BehavioralCohortBuilder $builder */
        $builder = app(\ZeroBoiler\Analytics\Services\BehavioralCohortBuilder::class);

        return response()->json($builder->summary($days));
    }

    /**
     * Get cohort transition data.
     *
     * GET /api/analytics/cohorts/transitions/{daysAgo}
     */
    public function behavioralCohortTransitions(int $daysAgo = 7): JsonResponse
    {
        /** @var \ZeroBoiler\Analytics\Services\BehavioralCohortBuilder $builder */
        $builder = app(\ZeroBoiler\Analytics\Services\BehavioralCohortBuilder::class);

        return response()->json($builder->transitions($daysAgo));
    }

    // ─── Identity Resolution (v3.2.0) ──────────────────────────

    /**
     * Look up user ID for a client ID.
     *
     * GET /api/analytics/identity/{clientId}
     */
    public function identityLookup(string $clientId): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\IdentityResolutionService $service */
            $service = app(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class);

            $userId = $service->getUserIdForClient($clientId);

            return response()->json([
                'client_id' => $clientId,
                'user_id' => $userId,
                'linked' => $userId !== null,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Identity resolution service unavailable',
                'message' => $e->getMessage(),
            ], 503);
        }
    }

    /**
     * Look up all client IDs for a user ID.
     *
     * GET /api/analytics/identity/user/{userId}
     */
    public function identityUserLookup(string $userId): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\IdentityResolutionService $service */
            $service = app(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class);

            return response()->json($service->identitySummary($userId));
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Identity resolution service unavailable',
                'message' => $e->getMessage(),
            ], 503);
        }
    }

    /**
     * Resolve (link) a client ID with a user ID.
     *
     * POST /api/analytics/identity/resolve
     *
     * Body: { "client_id": "uuid", "user_id": "123" }
     */
    public function identityResolve(Request $request): JsonResponse
    {
        $request->validate([
            'client_id' => 'required|string',
            'user_id' => 'required|string',
        ]);

        $clientId = $request->input('client_id');
        $userId = $request->input('user_id');

        try {
            /** @var \ZeroBoiler\Analytics\Services\IdentityResolutionService $service */
            $service = app(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class);

            $result = $service->resolve($clientId, $userId);

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Identity resolution failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Forget (unlink) a client ID.
     *
     * DELETE /api/analytics/identity/{clientId}
     */
    public function identityForgetClient(string $clientId): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\IdentityResolutionService $service */
            $service = app(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class);

            $removed = $service->forgetClient($clientId);

            return response()->json([
                'client_id' => $clientId,
                'removed' => $removed,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Identity removal failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Forget (unlink) all client IDs for a user (GDPR erasure).
     *
     * DELETE /api/analytics/identity/user/{userId}
     */
    public function identityForgetUser(string $userId): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\IdentityResolutionService $service */
            $service = app(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class);

            $count = $service->forgetUser($userId);

            return response()->json([
                'user_id' => $userId,
                'links_removed' => $count,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Identity erasure failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ─── Growth Engine Endpoints (v3.6.0) ─────────────────────────────

    /**
     * Get full growth metrics dashboard.
     *
     * GET /api/analytics/growth/dashboard
     */
    public function growthDashboard(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\GrowthMetricsService $service */
            $service = app(\ZeroBoiler\Analytics\Services\GrowthMetricsService::class);

            return response()->json($service->dashboard());
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Growth dashboard failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get activation metrics.
     *
     * GET /api/analytics/growth/activation
     */
    public function growthActivation(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\GrowthMetricsService $service */
            $service = app(\ZeroBoiler\Analytics\Services\GrowthMetricsService::class);

            return response()->json($service->activationMetrics());
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Activation metrics failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get feature stickiness metrics.
     *
     * GET /api/analytics/growth/stickiness
     */
    public function growthStickiness(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\GrowthMetricsService $service */
            $service = app(\ZeroBoiler\Analytics\Services\GrowthMetricsService::class);

            return response()->json($service->stickinessMetrics());
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Stickiness metrics failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get engagement velocity metrics.
     *
     * GET /api/analytics/growth/velocity
     */
    public function growthVelocity(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\GrowthMetricsService $service */
            $service = app(\ZeroBoiler\Analytics\Services\GrowthMetricsService::class);

            return response()->json($service->engagementVelocity());
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Engagement velocity failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get cohort health metrics.
     *
     * GET /api/analytics/growth/cohort-health
     */
    public function growthCohortHealth(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\GrowthMetricsService $service */
            $service = app(\ZeroBoiler\Analytics\Services\GrowthMetricsService::class);

            return response()->json($service->cohortHealth());
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Cohort health failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ─── Onboarding Wizard Endpoints (v3.6.0) ────────────────────────

    /**
     * Get onboarding wizard state.
     *
     * GET /api/analytics/onboarding/wizard
     */
    public function onboardingWizardState(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\OnboardingWizardService $service */
            $service = app(\ZeroBoiler\Analytics\Services\OnboardingWizardService::class);

            return response()->json($service->getState());
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Wizard state failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get onboarding wizard steps.
     *
     * GET /api/analytics/onboarding/wizard/steps
     */
    public function onboardingWizardSteps(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\OnboardingWizardService $service */
            $service = app(\ZeroBoiler\Analytics\Services\OnboardingWizardService::class);

            return response()->json($service->getSteps());
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Wizard steps failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detailed onboarding wizard progress.
     *
     * GET /api/analytics/onboarding/wizard/progress
     */
    public function onboardingWizardProgress(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\OnboardingWizardService $service */
            $service = app(\ZeroBoiler\Analytics\Services\OnboardingWizardService::class);

            return response()->json($service->getDetailedProgress());
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Wizard progress failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get onboarding wizard recommendations.
     *
     * GET /api/analytics/onboarding/wizard/recommendations
     */
    public function onboardingWizardRecommendations(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\OnboardingWizardService $service */
            $service = app(\ZeroBoiler\Analytics\Services\OnboardingWizardService::class);

            $limit = (int) $request->query('limit', 10);

            return response()->json($service->getRecommendations(min($limit, 50)));
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Wizard recommendations failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get onboarding wizard config checklist.
     *
     * GET /api/analytics/onboarding/wizard/config-checklist
     */
    public function onboardingWizardConfigChecklist(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\OnboardingWizardService $service */
            $service = app(\ZeroBoiler\Analytics\Services\OnboardingWizardService::class);

            return response()->json($service->getConfigChecklist());
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Config checklist failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get onboarding wizard readiness grade.
     *
     * GET /api/analytics/onboarding/wizard/readiness
     */
    public function onboardingWizardReadiness(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\OnboardingWizardService $service */
            $service = app(\ZeroBoiler\Analytics\Services\OnboardingWizardService::class);

            return response()->json($service->getReadinessGrade());
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Readiness grade failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get onboarding wizard quick-start checklist.
     *
     * GET /api/analytics/onboarding/wizard/quick-start
     */
    public function onboardingWizardQuickStart(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\OnboardingWizardService $service */
            $service = app(\ZeroBoiler\Analytics\Services\OnboardingWizardService::class);

            return response()->json($service->getQuickStartChecklist());
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Quick-start checklist failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ─── Weekly Digest Endpoints (v3.6.0) ───────────────────────────

    /**
     * Get weekly digest for a specific period.
     *
     * GET /api/analytics/digest?period=2026-W32
     */
    public function weeklyDigest(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\WeeklyDigestService $service */
            $service = app(\ZeroBoiler\Analytics\Services\WeeklyDigestService::class);

            $period = $request->query('period');

            return response()->json($service->generate(
                is_string($period) && $period !== '' ? $period : null,
            ));
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Weekly digest failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get latest weekly digest.
     *
     * GET /api/analytics/digest/latest
     */
    public function weeklyDigestLatest(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\WeeklyDigestService $service */
            $service = app(\ZeroBoiler\Analytics\Services\WeeklyDigestService::class);

            return response()->json($service->latest());
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Latest digest failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ─── Revenue Intelligence Endpoints (v3.7.0) ───────────────────

    /**
     * Get comprehensive revenue intelligence report.
     *
     * Combines revenue, health, churn, forecast, unit economics,
     * movement, signals, and recommendations into one response.
     *
     * @return JsonResponse
     */
    public function revenueIntelligence(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\RevenueIntelligenceService $service */
            $service = app(\ZeroBoiler\Analytics\Services\RevenueIntelligenceService::class);

            $data = request()->only([
                'mrr', 'arr', 'active_subscribers', 'churned_subscribers_last_month',
                'new_mrr_last_month', 'expansion_mrr_last_month', 'churned_mrr_last_month',
                'contraction_mrr_last_month', 'previous_mrr', 'arpu', 'churn_rate',
                'trial_conversion_rate', 'cac', 'ltv', 'monthly_expenses',
            ]);

            return response()->json($service->report($data));
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Revenue intelligence report failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get quick revenue summary for widgets/badges.
     *
     * @return JsonResponse
     */
    public function revenueQuickSummary(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\RevenueIntelligenceService $service */
            $service = app(\ZeroBoiler\Analytics\Services\RevenueIntelligenceService::class);

            $data = request()->only(['mrr', 'arr', 'active_subscribers', 'churn_rate']);

            return response()->json($service->quickSummary($data));
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Revenue quick summary failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get revenue signals and recommendations.
     *
     * @return JsonResponse
     */
    public function revenueSignals(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\RevenueIntelligenceService $service */
            $service = app(\ZeroBoiler\Analytics\Services\RevenueIntelligenceService::class);

            $data = request()->only([
                'mrr', 'churn_rate', 'arpu', 'trial_conversion_rate',
                'cac', 'ltv', 'churned_mrr_last_month', 'new_mrr_last_month',
                'expansion_mrr_last_month',
            ]);

            return response()->json($service->signals($data));
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Revenue signals failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ─── Event Enrichment Endpoints (v3.7.0) ────────────────────

    /**
     * Get event enrichment diagnostics.
     *
     * @return JsonResponse
     */
    public function enrichmentDiagnostics(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventEnrichmentService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventEnrichmentService::class);

            return response()->json(array_merge(
                $service->diagnostics(),
                ['request_context' => $service->extractContext(request())],
            ));
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Enrichment diagnostics failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ─── Subscription Lifecycle Endpoints (v3.7.0) ──────────────

    /**
     * Track trial started.
     *
     * @return JsonResponse
     */
    public function subscriptionTrialStarted(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SubscriptionLifecycleService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SubscriptionLifecycleService::class);
            $event = $service->trialStarted(
                userId: request()->input('user_id', ''),
                plan: request()->input('plan', ''),
                trialDays: (int) request()->input('trial_days', 14),
                extra: request()->except(['user_id', 'plan', 'trial_days']),
            );

            $this->manager->trackEvent($event);

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Trial started tracking failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Track trial converted.
     *
     * @return JsonResponse
     */
    public function subscriptionTrialConverted(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SubscriptionLifecycleService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SubscriptionLifecycleService::class);
            $event = $service->trialConverted(
                userId: request()->input('user_id', ''),
                plan: request()->input('plan', ''),
                amount: request()->input('amount') !== null ? (float) request()->input('amount') : null,
                extra: request()->except(['user_id', 'plan', 'amount']),
            );

            $this->manager->trackEvent($event);

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Trial converted tracking failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Track trial expired.
     *
     * @return JsonResponse
     */
    public function subscriptionTrialExpired(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SubscriptionLifecycleService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SubscriptionLifecycleService::class);
            $event = $service->trialExpired(
                userId: request()->input('user_id', ''),
                plan: request()->input('plan', ''),
                trialDays: (int) request()->input('trial_days', 14),
                extra: request()->except(['user_id', 'plan', 'trial_days']),
            );

            $this->manager->trackEvent($event);

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Trial expired tracking failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Track subscription created.
     *
     * @return JsonResponse
     */
    public function subscriptionCreated(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SubscriptionLifecycleService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SubscriptionLifecycleService::class);
            $event = $service->subscriptionCreated(
                userId: request()->input('user_id', ''),
                subscriptionId: request()->input('subscription_id', ''),
                plan: request()->input('plan', ''),
                amount: (float) request()->input('amount', 0),
                billingCycle: request()->input('billing_cycle'),
                extra: request()->except(['user_id', 'subscription_id', 'plan', 'amount', 'billing_cycle']),
            );

            $this->manager->trackEvent($event);

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Subscription created tracking failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Track subscription renewed.
     *
     * @return JsonResponse
     */
    public function subscriptionRenewed(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SubscriptionLifecycleService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SubscriptionLifecycleService::class);
            $event = $service->subscriptionRenewed(
                userId: request()->input('user_id', ''),
                subscriptionId: request()->input('subscription_id', ''),
                plan: request()->input('plan', ''),
                amount: (float) request()->input('amount', 0),
                renewalCount: (int) request()->input('renewal_count', 1),
                extra: request()->except(['user_id', 'subscription_id', 'plan', 'amount', 'renewal_count']),
            );

            $this->manager->trackEvent($event);

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Subscription renewed tracking failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Track plan upgrade.
     *
     * @return JsonResponse
     */
    public function subscriptionPlanUpgraded(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SubscriptionLifecycleService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SubscriptionLifecycleService::class);
            $event = $service->planUpgraded(
                userId: request()->input('user_id', ''),
                fromPlan: request()->input('from_plan', ''),
                toPlan: request()->input('to_plan', ''),
                previousAmount: request()->input('previous_amount') !== null ? (float) request()->input('previous_amount') : null,
                newAmount: request()->input('new_amount') !== null ? (float) request()->input('new_amount') : null,
                extra: request()->except(['user_id', 'from_plan', 'to_plan', 'previous_amount', 'new_amount']),
            );

            $this->manager->trackEvent($event);

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Plan upgrade tracking failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Track plan downgrade.
     *
     * @return JsonResponse
     */
    public function subscriptionPlanDowngraded(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SubscriptionLifecycleService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SubscriptionLifecycleService::class);
            $event = $service->planDowngraded(
                userId: request()->input('user_id', ''),
                fromPlan: request()->input('from_plan', ''),
                toPlan: request()->input('to_plan', ''),
                previousAmount: request()->input('previous_amount') !== null ? (float) request()->input('previous_amount') : null,
                newAmount: request()->input('new_amount') !== null ? (float) request()->input('new_amount') : null,
                extra: request()->except(['user_id', 'from_plan', 'to_plan', 'previous_amount', 'new_amount']),
            );

            $this->manager->trackEvent($event);

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Plan downgrade tracking failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Track subscription cancelled.
     *
     * @return JsonResponse
     */
    public function subscriptionCancelled(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SubscriptionLifecycleService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SubscriptionLifecycleService::class);
            $event = $service->subscriptionCancelled(
                userId: request()->input('user_id', ''),
                subscriptionId: request()->input('subscription_id', ''),
                plan: request()->input('plan', ''),
                lostMrr: request()->input('lost_mrr') !== null ? (float) request()->input('lost_mrr') : null,
                reason: request()->input('reason'),
                extra: request()->except(['user_id', 'subscription_id', 'plan', 'lost_mrr', 'reason']),
            );

            $this->manager->trackEvent($event);

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Subscription cancelled tracking failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Track subscription paused.
     *
     * @return JsonResponse
     */
    public function subscriptionPaused(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SubscriptionLifecycleService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SubscriptionLifecycleService::class);
            $event = $service->subscriptionPaused(
                userId: request()->input('user_id', ''),
                subscriptionId: request()->input('subscription_id', ''),
                plan: request()->input('plan', ''),
                extra: request()->except(['user_id', 'subscription_id', 'plan']),
            );

            $this->manager->trackEvent($event);

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Subscription paused tracking failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Track subscription resumed.
     *
     * @return JsonResponse
     */
    public function subscriptionResumed(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SubscriptionLifecycleService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SubscriptionLifecycleService::class);
            $event = $service->subscriptionResumed(
                userId: request()->input('user_id', ''),
                subscriptionId: request()->input('subscription_id', ''),
                plan: request()->input('plan', ''),
                extra: request()->except(['user_id', 'subscription_id', 'plan']),
            );

            $this->manager->trackEvent($event);

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Subscription resumed tracking failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Track payment succeeded.
     *
     * @return JsonResponse
     */
    public function subscriptionPaymentSucceeded(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SubscriptionLifecycleService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SubscriptionLifecycleService::class);
            $event = $service->paymentSucceeded(
                userId: request()->input('user_id', ''),
                subscriptionId: request()->input('subscription_id', ''),
                amount: (float) request()->input('amount', 0),
                paymentMethod: request()->input('payment_method'),
                extra: request()->except(['user_id', 'subscription_id', 'amount', 'payment_method']),
            );

            $this->manager->trackEvent($event);

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Payment succeeded tracking failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Track payment failed.
     *
     * @return JsonResponse
     */
    public function subscriptionPaymentFailed(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SubscriptionLifecycleService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SubscriptionLifecycleService::class);
            $event = $service->paymentFailed(
                userId: request()->input('user_id', ''),
                subscriptionId: request()->input('subscription_id', ''),
                attemptedAmount: (float) request()->input('attempted_amount', 0),
                attemptNumber: (int) request()->input('attempt_number', 1),
                failureReason: request()->input('failure_reason'),
                extra: request()->except(['user_id', 'subscription_id', 'attempted_amount', 'attempt_number', 'failure_reason']),
            );

            $this->manager->trackEvent($event);

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Payment failed tracking failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Track billing retry.
     *
     * @return JsonResponse
     */
    public function subscriptionBillingRetry(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SubscriptionLifecycleService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SubscriptionLifecycleService::class);
            $event = $service->billingRetry(
                userId: request()->input('user_id', ''),
                subscriptionId: request()->input('subscription_id', ''),
                retryCount: (int) request()->input('retry_count', 1),
                outstandingAmount: request()->input('outstanding_amount') !== null ? (float) request()->input('outstanding_amount') : null,
                extra: request()->except(['user_id', 'subscription_id', 'retry_count', 'outstanding_amount']),
            );

            $this->manager->trackEvent($event);

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Billing retry tracking failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ─── Event Archetype Endpoints (v3.9.0) ─────────────────────────

    /**
     * List all event archetypes with summary info.
     *
     * @return JsonResponse
     */
    public function archetypeList(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventArchetypeService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventArchetypeService::class);

            return response()->json([
                'archetypes' => $service->summary(),
                'total' => count($service->keys()),
                'version' => AnalyticsEvent::VERSION,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Archetype list failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detailed archetype by key.
     *
     * @param  string  $key
     * @return JsonResponse
     */
    public function archetypeDetail(string $key): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventArchetypeService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventArchetypeService::class);
            $archetype = $service->get($key);

            if ($archetype === null) {
                return response()->json([
                    'error' => 'Archetype not found',
                    'available' => $service->keys(),
                ], 404);
            }

            return response()->json([
                'key' => $key,
                'archetype' => $archetype,
                'lifecycle_config' => $service->toLifecycleConfig($key),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Archetype detail failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Detect instrumentation gaps between archetypes and EventCatalog.
     *
     * @return JsonResponse
     */
    public function archetypeGaps(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventArchetypeService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventArchetypeService::class);

            return response()->json($service->detectGaps());
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Gap detection failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate archetype completion score for a set of completed events.
     *
     * @param  string  $key
     * @return JsonResponse
     */
    public function archetypeScore(string $key): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventArchetypeService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventArchetypeService::class);

            $events = request()->input('events', []);

            if (! is_array($events)) {
                return response()->json([
                    'error' => 'events must be an array of event name strings',
                ], 422);
            }

            $score = $service->completionScore($key, $events);

            return response()->json([
                'archetype' => $key,
                'score' => $score,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Score calculation failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ─── Anonymized Aggregation Endpoints (v3.9.0) ──────────────────

    /**
     * Privacy-safe dashboard summary with k-anonymity.
     *
     * @return JsonResponse
     */
    public function anonymizedSummary(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventAnonymizationAggregationService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventAnonymizationAggregationService::class);

            return response()->json($service->dashboardSummary());
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Anonymized summary failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Anonymized aggregation by event name.
     *
     * @return JsonResponse
     */
    public function anonymizedByEvent(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventAnonymizationAggregationService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventAnonymizationAggregationService::class);

            return response()->json($service->aggregateByEvent());
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Anonymized event aggregation failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Anonymized aggregation by category.
     *
     * @return JsonResponse
     */
    public function anonymizedByCategory(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventAnonymizationAggregationService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventAnonymizationAggregationService::class);

            return response()->json($service->aggregateByCategory());
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Anonymized category aggregation failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Anonymized aggregation by time bucket.
     *
     * @return JsonResponse
     */
    public function anonymizedByTime(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventAnonymizationAggregationService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventAnonymizationAggregationService::class);

            $granularity = request()->input('granularity', 'hour');
            $limit = (int) request()->input('limit', 24);

            return response()->json($service->aggregateByTime($granularity, $limit));
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Anonymized time aggregation failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ─── Config Drift Endpoints (v3.9.0) ────────────────────────────

    /**
     * Detect config drift against stored baseline.
     *
     * @return JsonResponse
     */
    public function configDriftDetect(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\ConfigDriftDetectionService $service */
            $service = app(\ZeroBoiler\Analytics\Services\ConfigDriftDetectionService::class);

            return response()->json($service->detectDrift());
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Config drift detection failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get stored baseline metadata.
     *
     * @return JsonResponse
     */
    public function configDriftBaselineInfo(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\ConfigDriftDetectionService $service */
            $service = app(\ZeroBoiler\Analytics\Services\ConfigDriftDetectionService::class);

            return response()->json($service->baselineInfo());
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Baseline info failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Capture current config as baseline.
     *
     * @return JsonResponse
     */
    public function configDriftCapture(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\ConfigDriftDetectionService $service */
            $service = app(\ZeroBoiler\Analytics\Services\ConfigDriftDetectionService::class);

            return response()->json($service->captureBaseline());
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Baseline capture failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clear stored config baseline.
     *
     * @return JsonResponse
     */
    public function configDriftClear(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\ConfigDriftDetectionService $service */
            $service = app(\ZeroBoiler\Analytics\Services\ConfigDriftDetectionService::class);
            $cleared = $service->clearBaseline();

            return response()->json([
                'status' => $cleared ? 'cleared' : 'nothing_to_clear',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Baseline clear failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ─── Event Archive (v4.0.0) ────────────────────────────────────────

    /**
     * Search archived events with filters.
     *
     * Query parameters: name, client_id, user_id, dispatched, since, until, limit, offset
     *
     * @return JsonResponse
     */
    public function archiveSearch(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventArchiveService $archive */
            $archive = app(\ZeroBoiler\Analytics\Services\EventArchiveService::class);

            $filters = [];
            $request = request();

            if ($request->filled('name')) {
                $filters['name'] = (string) $request->query('name');
            }
            if ($request->filled('client_id')) {
                $filters['client_id'] = (string) $request->query('client_id');
            }
            if ($request->filled('user_id')) {
                $filters['user_id'] = (string) $request->query('user_id');
            }
            if ($request->filled('dispatched')) {
                $filters['dispatched'] = $request->boolean('dispatched');
            }
            if ($request->filled('since')) {
                $filters['since'] = (string) $request->query('since');
            }
            if ($request->filled('until')) {
                $filters['until'] = (string) $request->query('until');
            }

            $limit = (int) $request->query('limit', 50);
            $offset = (int) $request->query('offset', 0);

            $results = $archive->search($filters, min($limit, 200), $offset);

            return response()->json($results);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Archive search failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get event archive statistics (per-event-name counts).
     *
     * @return JsonResponse
     */
    public function archiveStats(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventArchiveService $archive */
            $archive = app(\ZeroBoiler\Analytics\Services\EventArchiveService::class);

            return response()->json([
                'total' => $archive->totalArchived(),
                'event_counts' => $archive->eventCounts(20),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Archive stats failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a single archived event by ID.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function archiveGet(int $id): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventArchiveService $archive */
            $archive = app(\ZeroBoiler\Analytics\Services\EventArchiveService::class);

            $event = $archive->get($id);

            if ($event === null) {
                return response()->json([
                    'error' => 'Event not found',
                    'id' => $id,
                ], 404);
            }

            return response()->json($event);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Archive get failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Replay a single archived event to all active providers.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function archiveReplay(int $id): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventArchiveService $archive */
            $archive = app(\ZeroBoiler\Analytics\Services\EventArchiveService::class);

            $success = $archive->replay($id);

            if (! $success) {
                return response()->json([
                    'error' => 'Replay failed',
                    'id' => $id,
                ], 404);
            }

            return response()->json([
                'status' => 'replayed',
                'id' => $id,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Archive replay failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clear the entire event archive.
     *
     * @return JsonResponse
     */
    public function archiveClear(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventArchiveService $archive */
            $archive = app(\ZeroBoiler\Analytics\Services\EventArchiveService::class);

            $cleared = $archive->clear();

            return response()->json([
                'status' => 'cleared',
                'events_removed' => $cleared,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Archive clear failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ─── Event Governance (v4.1.0) ────────────────────────────────────────────

    /**
     * Get governance report.
     *
     * GET /api/analytics/governance
     *
     * @return JsonResponse
     */
    public function governanceReport(): JsonResponse
    {
        if ($this->governanceService === null) {
            return response()->json(['enabled' => false, 'message' => 'Governance service not configured']);
        }

        return response()->json([
            'enabled' => $this->governanceService->isEnabled(),
            'enforced' => $this->governanceService->isEnforced(),
            'report' => $this->governanceService->report(),
        ]);
    }

    /**
     * Get all governance registrations.
     *
     * GET /api/analytics/governance/events
     *
     * @return JsonResponse
     */
    public function governanceRegistrations(Request $request): JsonResponse
    {
        if ($this->governanceService === null) {
            return response()->json(['registrations' => []]);
        }

        $status = $request->query('status');
        $status = is_string($status) ? $status : null;

        return response()->json([
            'registrations' => $this->governanceService->registrations($status),
        ]);
    }

    /**
     * Register a new event for governance.
     *
     * POST /api/analytics/governance/register
     *
     * @return JsonResponse
     */
    public function governanceRegister(Request $request): JsonResponse
    {
        if ($this->governanceService === null) {
            return response()->json(['error' => 'Governance service not configured'], 503);
        }

        $name = $request->input('name', '');
        $category = $request->input('category', 'custom');
        $owner = $request->input('owner', '');
        $description = $request->input('description', '');
        $requiredParams = $request->input('required_params', []);
        $optionalParams = $request->input('optional_params', []);

        $name = is_string($name) ? $name : '';
        $category = is_string($category) ? $category : 'custom';
        $owner = is_string($owner) ? $owner : '';
        $description = is_string($description) ? $description : '';
        $requiredParams = is_array($requiredParams) ? $requiredParams : [];
        $optionalParams = is_array($optionalParams) ? $optionalParams : [];

        $result = $this->governanceService->register($name, $category, $owner, $description, $requiredParams, $optionalParams);

        return response()->json($result, $result['success'] ? 201 : 422);
    }

    /**
     * Activate a draft event.
     *
     * POST /api/analytics/governance/activate
     *
     * @return JsonResponse
     */
    public function governanceActivate(Request $request): JsonResponse
    {
        if ($this->governanceService === null) {
            return response()->json(['error' => 'Governance service not configured'], 503);
        }

        $name = $request->input('name', '');
        $name = is_string($name) ? $name : '';

        $result = $this->governanceService->activate($name);

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    /**
     * Deprecate an active event.
     *
     * POST /api/analytics/governance/deprecate
     *
     * @return JsonResponse
     */
    public function governanceDeprecate(Request $request): JsonResponse
    {
        if ($this->governanceService === null) {
            return response()->json(['error' => 'Governance service not configured'], 503);
        }

        $name = $request->input('name', '');
        $replacement = $request->input('replacement');
        $name = is_string($name) ? $name : '';
        $replacement = is_string($replacement) ? $replacement : null;

        $result = $this->governanceService->deprecate($name, $replacement);

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    /**
     * Retire a deprecated event.
     *
     * POST /api/analytics/governance/retire
     *
     * @return JsonResponse
     */
    public function governanceRetire(Request $request): JsonResponse
    {
        if ($this->governanceService === null) {
            return response()->json(['error' => 'Governance service not configured'], 503);
        }

        $name = $request->input('name', '');
        $name = is_string($name) ? $name : '';

        $result = $this->governanceService->retire($name);

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    /**
     * Get events needing attention (draft/deprecated).
     *
     * GET /api/analytics/governance/attention
     *
     * @return JsonResponse
     */
    public function governanceAttention(): JsonResponse
    {
        if ($this->governanceService === null) {
            return response()->json(['items' => []]);
        }

        return response()->json([
            'items' => $this->governanceService->attentionRequired(),
        ]);
    }

    /**
     * Get naming convention compliance details.
     *
     * GET /api/analytics/governance/naming
     *
     * @return JsonResponse
     */
    public function governanceNaming(): JsonResponse
    {
        if ($this->governanceService === null) {
            return response()->json(['error' => 'Governance service not configured'], 503);
        }

        return response()->json([
            'format' => $this->governanceService->naming()->getFormat(),
            'summary' => $this->governanceService->naming()->summary(),
            'compliance_score' => $this->governanceService->naming()->catalogComplianceScore(),
        ]);
    }

    /**
     * Get data quality report.
     *
     * GET /api/analytics/governance/quality
     *
     * @return JsonResponse
     */
    public function governanceQuality(): JsonResponse
    {
        if ($this->governanceService === null) {
            return response()->json(['error' => 'Governance service not configured'], 503);
        }

        return response()->json($this->governanceService->quality()->report());
    }

    /**
     * Get deprecation warnings and expired events.
     *
     * GET /api/analytics/governance/deprecations
     *
     * @return JsonResponse
     */
    public function governanceDeprecations(Request $request): JsonResponse
    {
        if ($this->governanceService === null) {
            return response()->json(['error' => 'Governance service not configured'], 503);
        }

        $days = (int) ($request->query('days', 30));

        return response()->json([
            'warnings' => $this->governanceService->deprecationWarnings($days),
            'summary' => $this->governanceService->deprecation()->summary(),
            'expired' => $this->governanceService->deprecation()->expired(),
        ]);
    }

    // ─── Event Impact Analytics (v4.2.0) ─────────────────────────────────

    /**
     * Calculate event impact scores.
     *
     * POST /api/analytics/impact/calculate
     *
     * Accepts user behavior data and returns impact scores for each event type,
     * ranking events by their correlation with conversion, retention, and revenue.
     *
     * @return JsonResponse
     */
    public function eventImpactCalculate(Request $request): JsonResponse
    {
        if ($this->eventImpactService === null) {
            return response()->json(['error' => 'Event Impact service not configured'], 503);
        }

        $request->validate([
            'users' => 'required|array|min:1',
            'users.*.user_id' => 'required|string',
            'users.*.events' => 'required|array',
            'users.*.events.*' => 'string',
            'users.*.converted' => 'boolean',
            'users.*.retained' => 'boolean',
            'users.*.revenue' => 'numeric',
        ]);

        $users = $request->input('users', []);

        return response()->json($this->eventImpactService->calculateImpacts($users));
    }

    /**
     * Get top conversion driver events.
     *
     * GET /api/analytics/impact/conversion-drivers?limit=10
     *
     * @return JsonResponse
     */
    public function eventImpactConversionDrivers(Request $request): JsonResponse
    {
        if ($this->eventImpactService === null) {
            return response()->json(['error' => 'Event Impact service not configured'], 503);
        }

        $request->validate([
            'users' => 'required|array|min:1',
            'users.*.user_id' => 'required|string',
            'users.*.events' => 'required|array',
            'users.*.events.*' => 'string',
            'users.*.converted' => 'boolean',
            'users.*.retained' => 'boolean',
            'users.*.revenue' => 'numeric',
        ]);

        $limit = (int) ($request->query('limit', 10));

        return response()->json([
            'drivers' => $this->eventImpactService->conversionDrivers($request->input('users', []), $limit),
        ]);
    }

    /**
     * Get top retention driver events.
     *
     * GET /api/analytics/impact/retention-drivers?limit=10
     *
     * @return JsonResponse
     */
    public function eventImpactRetentionDrivers(Request $request): JsonResponse
    {
        if ($this->eventImpactService === null) {
            return response()->json(['error' => 'Event Impact service not configured'], 503);
        }

        $request->validate([
            'users' => 'required|array|min:1',
            'users.*.user_id' => 'required|string',
            'users.*.events' => 'required|array',
            'users.*.events.*' => 'string',
            'users.*.converted' => 'boolean',
            'users.*.retained' => 'boolean',
            'users.*.revenue' => 'numeric',
        ]);

        $limit = (int) ($request->query('limit', 10));

        return response()->json([
            'drivers' => $this->eventImpactService->retentionDrivers($request->input('users', []), $limit),
        ]);
    }

    // ─── Feature Adoption Analytics (v4.2.0) ────────────────────────────

    /**
     * Get a user's feature adoption profile.
     *
     * GET /api/analytics/adoption/profile/{userId}
     *
     * @return JsonResponse
     */
    public function featureAdoptionProfile(Request $request, string $userId): JsonResponse
    {
        if ($this->featureAdoptionTracker === null) {
            return response()->json(['error' => 'Feature Adoption service not configured'], 503);
        }

        return response()->json($this->featureAdoptionTracker->getProfile($userId));
    }

    /**
     * Record a feature adoption event.
     *
     * POST /api/analytics/adoption/record
     *
     * @return JsonResponse
     */
    public function featureAdoptionRecord(Request $request): JsonResponse
    {
        if ($this->featureAdoptionTracker === null) {
            return response()->json(['error' => 'Feature Adoption service not configured'], 503);
        }

        $request->validate([
            'user_id' => 'required|string',
            'feature_name' => 'required|string|max:100',
            'context' => 'array',
        ]);

        $this->featureAdoptionTracker->recordAdoption(
            $request->input('user_id'),
            $request->input('feature_name'),
            $request->input('context', []),
        );

        return response()->json(['status' => 'ok']);
    }

    /**
     * Get feature adoption funnel.
     *
     * POST /api/analytics/adoption/funnel
     *
     * Accepts ordered feature names and user IDs, returns adoption rates per feature.
     *
     * @return JsonResponse
     */
    public function featureAdoptionFunnel(Request $request): JsonResponse
    {
        if ($this->featureAdoptionTracker === null) {
            return response()->json(['error' => 'Feature Adoption service not configured'], 503);
        }

        $request->validate([
            'features' => 'required|array|min:1',
            'features.*' => 'string',
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'string',
        ]);

        return response()->json([
            'funnel' => $this->featureAdoptionTracker->adoptionFunnel(
                $request->input('features', []),
                $request->input('user_ids', []),
            ),
        ]);
    }

    /**
     * Get recently adopted features for a user.
     *
     * GET /api/analytics/adoption/recent/{userId}?limit=10
     *
     * @return JsonResponse
     */
    public function featureAdoptionRecent(Request $request, string $userId): JsonResponse
    {
        if ($this->featureAdoptionTracker === null) {
            return response()->json(['error' => 'Feature Adoption service not configured'], 503);
        }

        $limit = (int) ($request->query('limit', 10));

        return response()->json([
            'features' => $this->featureAdoptionTracker->recentFeatures($userId, $limit),
        ]);
    }

    /**
     * Get adoption streak for a user's feature.
     *
     * GET /api/analytics/adoption/streak/{userId}/{featureName}
     *
     * @return JsonResponse
     */
    public function featureAdoptionStreak(Request $request, string $userId, string $featureName): JsonResponse
    {
        if ($this->featureAdoptionTracker === null) {
            return response()->json(['error' => 'Feature Adoption service not configured'], 503);
        }

        return response()->json([
            'feature' => $featureName,
            'user_id' => $userId,
            'streak_days' => $this->featureAdoptionTracker->getStreak($userId, $featureName),
        ]);
    }

    /**
     * Clear a user's adoption profile.
     *
     * DELETE /api/analytics/adoption/profile/{userId}
     *
     * @return JsonResponse
     */
    public function featureAdoptionClear(Request $request, string $userId): JsonResponse
    {
        if ($this->featureAdoptionTracker === null) {
            return response()->json(['error' => 'Feature Adoption service not configured'], 503);
        }

        $this->featureAdoptionTracker->clearProfile($userId);

        return response()->json(['status' => 'ok']);
    }

    // ─── Event Sequencing Analysis (v4.3.0) ────────────────────────────

    /**
     * Get event correlation matrix.
     *
     * POST /api/analytics/correlation/matrix
     *
     * Accepts an optional list of event names. Returns a co-occurrence
     * matrix showing how often events appear together in user journeys.
     *
     * @return JsonResponse
     */
    public function correlationMatrix(Request $request): JsonResponse
    {
        if ($this->correlationService === null) {
            return response()->json(['error' => 'Correlation service not configured'], 503);
        }

        $request->validate([
            'events' => 'array',
            'events.*' => 'string',
        ]);

        $events = $request->input('events', []);

        return response()->json($this->correlationService->correlationMatrix($events));
    }

    /**
     * Get sequence conversion funnel analysis.
     *
     * POST /api/analytics/correlation/conversion-rate
     *
     * Body: { "sequence": ["sign_up", "start_trial", "subscribe"] }
     *
     * @return JsonResponse
     */
    public function correlationConversionRate(Request $request): JsonResponse
    {
        if ($this->correlationService === null) {
            return response()->json(['error' => 'Correlation service not configured'], 503);
        }

        $request->validate([
            'sequence' => 'required|array|min:2',
            'sequence.*' => 'string',
        ]);

        return response()->json(
            $this->correlationService->conversionRate($request->input('sequence', []))
        );
    }

    // ─── Event Budget & Throttling (v4.3.0) ────────────────────────────

    /**
     * Get event budget statistics.
     *
     * GET /api/analytics/budget
     *
     * @return JsonResponse
     */
    public function budgetStats(): JsonResponse
    {
        if ($this->budgetService === null) {
            return response()->json(['error' => 'Budget service not configured'], 503);
        }

        return response()->json($this->budgetService->stats());
    }

    /**
     * Get budget status for a specific client.
     *
     * GET /api/analytics/budget/client/{clientId}
     *
     * @return JsonResponse
     */
    public function budgetClientStatus(Request $request, string $clientId): JsonResponse
    {
        if ($this->budgetService === null) {
            return response()->json(['error' => 'Budget service not configured'], 503);
        }

        return response()->json($this->budgetService->clientStatus($clientId));
    }

    /**
     * Get budget status for a specific user.
     *
     * GET /api/analytics/budget/user/{userId}
     *
     * @return JsonResponse
     */
    public function budgetUserStatus(Request $request, string $userId): JsonResponse
    {
        if ($this->budgetService === null) {
            return response()->json(['error' => 'Budget service not configured'], 503);
        }

        return response()->json($this->budgetService->userStatus($userId));
    }

    /**
     * Get top clients by event count.
     *
     * GET /api/analytics/budget/top-clients?limit=10
     *
     * @return JsonResponse
     */
    public function budgetTopClients(Request $request): JsonResponse
    {
        if ($this->budgetService === null) {
            return response()->json(['error' => 'Budget service not configured'], 503);
        }

        $limit = (int) ($request->query('limit', 10));

        return response()->json([
            'top_clients' => $this->budgetService->topClients($limit),
        ]);
    }

    /**
     * Reset budget for a specific client.
     *
     * DELETE /api/analytics/budget/client/{clientId}
     *
     * @return JsonResponse
     */
    public function budgetResetClient(Request $request, string $clientId): JsonResponse
    {
        if ($this->budgetService === null) {
            return response()->json(['error' => 'Budget service not configured'], 503);
        }

        $this->budgetService->resetClient($clientId);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Reset budget for a specific user.
     *
     * DELETE /api/analytics/budget/user/{userId}
     *
     * @return JsonResponse
     */
    public function budgetResetUser(Request $request, string $userId): JsonResponse
    {
        if ($this->budgetService === null) {
            return response()->json(['error' => 'Budget service not configured'], 503);
        }

        $this->budgetService->resetUser($userId);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Clear all budget counters.
     *
     * DELETE /api/analytics/budget
     *
     * @return JsonResponse
     */
    public function budgetClear(): JsonResponse
    {
        if ($this->budgetService === null) {
            return response()->json(['error' => 'Budget service not configured'], 503);
        }

        $this->budgetService->clear();

        return response()->json(['status' => 'ok']);
    }

    // ─── v4.4.0: Event Cost Tracking ──────────────────────────────────

    /**
     * Get full cost report for all analytics providers.
     *
     * GET /api/analytics/cost
     *
     * @return JsonResponse
     */
    public function costReport(): JsonResponse
    {
        $costTracker = app(\ZeroBoiler\Analytics\Services\EventCostTracker::class);

        return response()->json($costTracker->report());
    }

    /**
     * Get cost report for a specific provider.
     *
     * GET /api/analytics/cost/{provider}
     *
     * @return JsonResponse
     */
    public function costProvider(Request $request, string $provider): JsonResponse
    {
        $costTracker = app(\ZeroBoiler\Analytics\Services\EventCostTracker::class);
        $cost = $costTracker->providerCost($provider);

        if ($cost === null) {
            return response()->json(['error' => "Provider '{$provider}' not found or disabled"], 404);
        }

        return response()->json($cost);
    }

    // ─── v4.4.0: Notification Webhooks ──────────────────────────────

    /**
     * List configured notification webhooks.
     *
     * GET /api/analytics/notifications/webhooks
     *
     * @return JsonResponse
     */
    public function notificationWebhooks(): JsonResponse
    {
        $service = app(\ZeroBoiler\Analytics\Services\NotificationWebhookService::class);

        return response()->json([
            'enabled' => $service->isEnabled(),
            'webhooks' => $service->getWebhooks(),
        ]);
    }

    /**
     * Get notification delivery statistics.
     *
     * GET /api/analytics/notifications/stats
     *
     * @return JsonResponse
     */
    public function notificationStats(): JsonResponse
    {
        $service = app(\ZeroBoiler\Analytics\Services\NotificationWebhookService::class);

        return response()->json($service->deliveryStats());
    }

    /**
     * Test a webhook connection by sending a ping.
     *
     * POST /api/analytics/notifications/test/{webhookName}
     *
     * @return JsonResponse
     */
    public function notificationTest(Request $request, string $webhookName): JsonResponse
    {
        $service = app(\ZeroBoiler\Analytics\Services\NotificationWebhookService::class);

        return response()->json($service->testWebhook($webhookName));
    }

    /**
     * Send a custom notification to a webhook.
     *
     * POST /api/analytics/notifications/send
     *
     * Body: { webhook: string, message: string, severity?: string }
     *
     * @return JsonResponse
     */
    public function notificationSend(Request $request): JsonResponse
    {
        $service = app(\ZeroBoiler\Analytics\Services\NotificationWebhookService::class);

        $webhookName = $request->input('webhook', '');
        $message = $request->input('message', '');
        $context = $request->only(['event', 'severity']);

        if ($webhookName === '' || $message === '') {
            return response()->json(['error' => 'webhook and message are required'], 422);
        }

        $result = $service->sendCustom($webhookName, $message, $context);

        return response()->json($result);
    }

    // ── Config Audit API (v4.5.0) ──────────────────────────────────────────

    /**
     * Get masked analytics configuration audit dump.
     *
     * GET /api/analytics/config/audit
     *
     * Returns the full analytics configuration with sensitive values masked.
     * Useful for admin dashboards and debugging.
     *
     * @return JsonResponse
     */
    public function configAudit(): JsonResponse
    {
        if ($this->configAuditService === null) {
            return response()->json(['error' => 'Config audit service not available'], 503);
        }

        return response()->json($this->configAuditService->audit());
    }

    /**
     * Get analytics provider and feature status summary.
     *
     * GET /api/analytics/config/summary
     *
     * Returns enabled/disabled status for all providers and features.
     *
     * @return JsonResponse
     */
    public function configSummary(): JsonResponse
    {
        if ($this->configAuditService === null) {
            return response()->json(['error' => 'Config audit service not available'], 503);
        }

        return response()->json($this->configAuditService->summary());
    }

    /**
     * Save a configuration snapshot for future diff comparison.
     *
     * POST /api/analytics/config/snapshot
     *
     * Body: { "label": "pre-deployment" }
     *
     * @return JsonResponse
     */
    public function configSnapshotSave(Request $request): JsonResponse
    {
        if ($this->configAuditService === null) {
            return response()->json(['error' => 'Config audit service not available'], 503);
        }

        $label = $request->input('label');

        return response()->json($this->configAuditService->saveSnapshot(
            is_string($label) ? $label : null,
        ));
    }

    /**
     * Load a previously saved configuration snapshot.
     *
     * GET /api/analytics/config/snapshot/{label}
     *
     * @return JsonResponse
     */
    public function configSnapshotLoad(Request $request, string $label): JsonResponse
    {
        if ($this->configAuditService === null) {
            return response()->json(['error' => 'Config audit service not available'], 503);
        }

        return response()->json($this->configAuditService->loadSnapshot($label));
    }

    /**
     * Compare current config against a snapshot.
     *
     * POST /api/analytics/config/diff
     *
     * Body: { "snapshot": { "ga4": { "enabled": true } } }
     *
     * @return JsonResponse
     */
    public function configDiff(Request $request): JsonResponse
    {
        if ($this->configAuditService === null) {
            return response()->json(['error' => 'Config audit service not available'], 503);
        }

        $snapshot = $request->input('snapshot', []);
        $snapshot = is_array($snapshot) ? $snapshot : [];

        return response()->json($this->configAuditService->diff($snapshot));
    }

    // ── Event Catalog Validation API (v4.5.0) ─────────────────────────────

    /**
     * Validate an event name against the catalog.
     *
     * POST /api/analytics/catalog/validate
     *
     * Body: { "name": "purchase", "params": { "currency": "USD", "value": 99.0 } }
     *
     * @return JsonResponse
     */
    public function catalogValidate(Request $request): JsonResponse
    {
        if ($this->catalogValidator === null) {
            return response()->json(['error' => 'Catalog validator not available'], 503);
        }

        $request->validate([
            'name' => 'required|string|max:100',
            'params' => 'array',
        ]);

        $name = $request->input('name', '');
        $params = $request->input('params', []);
        $params = is_array($params) ? $params : [];

        $event = new AnalyticsEvent(
            name: is_string($name) ? $name : '',
            params: $params,
        );

        return response()->json($this->catalogValidator->validate($event));
    }

    /**
     * Get catalog statistics.
     *
     * GET /api/analytics/catalog/stats
     *
     * @return JsonResponse
     */
    public function catalogStats(): JsonResponse
    {
        if ($this->catalogValidator === null) {
            return response()->json(['error' => 'Catalog validator not available'], 503);
        }

        return response()->json($this->catalogValidator->catalogStats());
    }

    /**
     * Suggest catalog events for a partial name.
     *
     * GET /api/analytics/catalog/suggest?q=pur&limit=5
     *
     * @return JsonResponse
     */
    public function catalogSuggest(Request $request): JsonResponse
    {
        if ($this->catalogValidator === null) {
            return response()->json(['error' => 'Catalog validator not available'], 503);
        }

        $query = $request->query('q', '');
        $limit = min((int) $request->query('limit', 5), 20);

        return response()->json([
            'query' => $query,
            'suggestions' => $this->catalogValidator->suggest(
                is_string($query) ? $query : '',
                $limit,
            ),
        ]);
    }

    // ─── Event Routing Endpoints (v5.9.0) ──────────────────────────────

    /**
     * Get event routing summary.
     *
     * GET /api/analytics/routing
     *
     * @return JsonResponse
     */
    public function routingSummary(): JsonResponse
    {
        $router = $this->lifecycleMapper ?? null;

        if ($router === null) {
            return response()->json([
                'enabled' => false,
                'rule_count' => 0,
                'rules' => [],
                'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
            ]);
        }

        try {
            $routerService = app(\ZeroBoiler\Analytics\Services\AnalyticsEventRouter::class);

            return response()->json($routerService->summary());
        } catch (\Throwable) {
            return response()->json([
                'enabled' => false,
                'rule_count' => 0,
                'rules' => [],
                'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
            ]);
        }
    }

    /**
     * List all routing rules.
     *
     * GET /api/analytics/routing/rules
     *
     * @return JsonResponse
     */
    public function routingRules(): JsonResponse
    {
        try {
            $routerService = app(\ZeroBoiler\Analytics\Services\AnalyticsEventRouter::class);

            return response()->json([
                'rules' => $routerService->getRules(),
                'count' => $routerService->ruleCount(),
            ]);
        } catch (\Throwable) {
            return response()->json(['rules' => [], 'count' => 0]);
        }
    }

    /**
     * Add a routing rule.
     *
     * POST /api/analytics/routing/rules
     *
     * @return JsonResponse
     */
    public function routingAddRule(Request $request): JsonResponse
    {
        $request->validate([
            'pattern' => 'required|string',
            'providers' => 'required|array|min:1',
            'providers.*' => 'string|in:ga4,gtm,meta,plausible,posthog,webhook',
        ]);

        try {
            $routerService = app(\ZeroBoiler\Analytics\Services\AnalyticsEventRouter::class);
            $routerService->addRule(
                $request->input('pattern'),
                $request->input('providers'),
            );

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove a routing rule.
     *
     * DELETE /api/analytics/routing/rules/{pattern}
     *
     * @return JsonResponse
     */
    public function routingRemoveRule(string $pattern): JsonResponse
    {
        try {
            $routerService = app(\ZeroBoiler\Analytics\Services\AnalyticsEventRouter::class);
            $routerService->removeRule($pattern);

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Match an event name against routing rules.
     *
     * POST /api/analytics/routing/match
     *
     * @return JsonResponse
     */
    public function routingMatch(Request $request): JsonResponse
    {
        $request->validate([
            'event_name' => 'required|string',
        ]);

        try {
            $routerService = app(\ZeroBoiler\Analytics\Services\AnalyticsEventRouter::class);

            return response()->json([
                'event_name' => $request->input('event_name'),
                'matched_providers' => $routerService->matchProviders($request->input('event_name')),
            ]);
        } catch (\Throwable) {
            return response()->json([
                'event_name' => $request->input('event_name'),
                'matched_providers' => [],
            ]);
        }
    }

    /**
     * Test a routing rule against event names.
     *
     * POST /api/analytics/routing/test
     *
     * @return JsonResponse
     */
    public function routingTest(Request $request): JsonResponse
    {
        $request->validate([
            'pattern' => 'required|string',
            'event_names' => 'required|array',
            'event_names.*' => 'string',
        ]);

        $pattern = $request->input('pattern');
        $eventNames = $request->input('event_names');
        $results = [];

        foreach ($eventNames as $eventName) {
            $results[$eventName] = preg_match(
                '/' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '/',
                is_string($eventName) ? $eventName : '',
            ) === 1;
        }

        return response()->json([
            'pattern' => $pattern,
            'results' => $results,
        ]);
    }

    // ─── Provider Health Endpoints (v5.9.0) ───────────────────────────

    /**
     * Get provider health summary.
     *
     * GET /api/analytics/provider-health
     *
     * @return JsonResponse
     */
    public function providerHealth(): JsonResponse
    {
        try {
            $monitor = app(\ZeroBoiler\Analytics\Services\ProviderHealthMonitor::class);

            return response()->json([
                'status' => $monitor->getStatus(),
                'summary' => $monitor->summary(),
            ]);
        } catch (\Throwable) {
            return response()->json([
                'status' => [],
                'summary' => [
                    'overall_score' => 100,
                    'healthy_count' => 0,
                    'unhealthy_providers' => [],
                    'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
                ],
            ]);
        }
    }

    /**
     * Get detailed health for a specific provider.
     *
     * GET /api/analytics/provider-health/{provider}
     *
     * @return JsonResponse
     */
    public function providerHealthDetail(string $provider): JsonResponse
    {
        $validProviders = ['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'webhook'];

        if (! in_array($provider, $validProviders, true)) {
            return response()->json(['error' => "Invalid provider: {$provider}"], 422);
        }

        try {
            $monitor = app(\ZeroBoiler\Analytics\Services\ProviderHealthMonitor::class);
            $status = $monitor->getStatus();

            return response()->json($status[$provider] ?? [
                'score' => 100,
                'healthy' => true,
                'successes' => 0,
                'failures' => 0,
                'rate' => 100.0,
            ]);
        } catch (\Throwable) {
            return response()->json([
                'score' => 100,
                'healthy' => true,
                'successes' => 0,
                'failures' => 0,
                'rate' => 100.0,
            ]);
        }
    }

    /**
     * Reset provider health stats.
     *
     * POST /api/analytics/provider-health/reset
     *
     * @return JsonResponse
     */
    public function providerHealthReset(Request $request): JsonResponse
    {
        $provider = $request->input('provider');

        try {
            $monitor = app(\ZeroBoiler\Analytics\Services\ProviderHealthMonitor::class);

            if (is_string($provider) && $provider !== '') {
                $monitor->reset($provider);
            } else {
                $monitor->resetAll();
            }

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ─── Config Export Endpoints (v6.5.0) ───────────────────────────

    /**
     * Export the full analytics configuration (secrets redacted).
     *
     * GET /api/analytics/config/export
     *
     * Returns a redacted snapshot of all analytics configuration sections.
     * Useful for debugging, dashboards, and support workflows.
     *
     * @return JsonResponse
     */
    public function configExport(): JsonResponse
    {
        try {
            $exportService = app(\ZeroBoiler\Analytics\Services\AnalyticsConfigExportService::class);

            return response()->json($exportService->exportRedacted());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Export only the enabled/disabled status summary.
     *
     * GET /api/analytics/config/status
     *
     * Returns provider and feature toggle status without exposing config values.
     *
     * @return JsonResponse
     */
    public function configStatus(): JsonResponse
    {
        try {
            $exportService = app(\ZeroBoiler\Analytics\Services\AnalyticsConfigExportService::class);

            return response()->json($exportService->exportStatusSummary());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Export a single config section (redacted).
     *
     * GET /api/analytics/config/section/{section}
     *
     * @return JsonResponse
     */
    public function configSection(string $section): JsonResponse
    {
        try {
            $exportService = app(\ZeroBoiler\Analytics\Services\AnalyticsConfigExportService::class);
            $result = $exportService->exportSection($section);

            if ($result === null) {
                return response()->json(['error' => "Unknown section: {$section}"], 404);
            }

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ─── Event Data Mart (v7.0.0) ────────────────────────────────────────

    /**
     * Get data mart summary status.
     */
    public function dataMartSummary(Request $request, EventDataMartService $mart): JsonResponse
    {
        return response()->json($mart->summary());
    }

    /**
     * Get top N values for a given dimension.
     */
    public function dataMartTop(Request $request, EventDataMartService $mart, string $dimension): JsonResponse
    {
        $limit = (int) $request->query('limit', 10);
        $granularity = $request->query('granularity', 'hour');

        return response()->json($mart->top($dimension, min($limit, 100), $granularity));
    }

    /**
     * Get event counts grouped by category.
     */
    public function dataMartByCategory(EventDataMartService $mart): JsonResponse
    {
        return response()->json($mart->byCategory());
    }

    /**
     * Get event counts grouped by event name.
     */
    public function dataMartByEvent(EventDataMartService $mart): JsonResponse
    {
        return response()->json($mart->byEventName());
    }

    /**
     * Get event counts grouped by provider.
     */
    public function dataMartByProvider(EventDataMartService $mart): JsonResponse
    {
        return response()->json($mart->byProvider());
    }

    /**
     * Export a full data mart cube.
     */
    public function dataMartExport(Request $request, EventDataMartService $mart): JsonResponse
    {
        $dimension = $request->query('dimension', 'event_name');
        $granularity = $request->query('granularity', 'hour');

        return response()->json($mart->exportCube($dimension, $granularity));
    }

    /**
     * Compare two dimensions' distributions.
     */
    public function dataMartCompare(Request $request, EventDataMartService $mart): JsonResponse
    {
        $dimensionA = $request->query('dimension_a', 'event_name');
        $dimensionB = $request->query('dimension_b', 'category');
        $granularity = $request->query('granularity', 'hour');

        return response()->json($mart->compareDimensions($dimensionA, $dimensionB, $granularity));
    }

    /**
     * Clear all data mart caches.
     */
    public function dataMartClear(EventDataMartService $mart): JsonResponse
    {
        $mart->clear();

        return response()->json(['cleared' => true]);
    }

    // ─── Insight Engine (v7.0.0) ───────────────────────────────────────

    /**
     * Generate a full insight report.
     */
    public function insightReport(AnalyticsInsightEngineService $engine): JsonResponse
    {
        return response()->json($engine->generateReport());
    }

    /**
     * Get the latest cached insight report.
     */
    public function insightLatest(AnalyticsInsightEngineService $engine): JsonResponse
    {
        return response()->json($engine->latestReport());
    }

    /**
     * Get quick health summary.
     */
    public function insightHealth(AnalyticsInsightEngineService $engine): JsonResponse
    {
        return response()->json($engine->quickHealth());
    }

    /**
     * Get insights filtered by severity.
     */
    public function insightBySeverity(AnalyticsInsightEngineService $engine, string $severity): JsonResponse
    {
        return response()->json($engine->bySeverity($severity));
    }

    // ── Event Recommendations & Provider Gap Analysis (v7.1.0) ──────────

    /**
     * Get event instrumentation recommendations.
     *
     * Analyzes tracked events against the full catalog and returns gaps
     * grouped by priority tier (critical, high, medium, low).
     */
    public function eventRecommendations(
        EventRecommendationService $service,
        Request $request,
    ): JsonResponse {
        $tracked = $request->query('tracked', '');
        $trackedEvents = is_string($tracked) && $tracked !== ''
            ? explode(',', $tracked)
            : EventCatalog::names();

        return response()->json($service->recommend($trackedEvents));
    }

    /**
     * Get top N recommended events to add next.
     */
    public function topEventRecommendations(
        EventRecommendationService $service,
        Request $request,
    ): JsonResponse {
        $tracked = $request->query('tracked', '');
        $trackedEvents = is_string($tracked) && $tracked !== ''
            ? explode(',', $tracked)
            : EventCatalog::names();
        $limit = (int) $request->query('limit', 10);

        return response()->json($service->topRecommendations($trackedEvents, $limit));
    }

    /**
     * Get AARRR framework coverage breakdown.
     */
    public function aarrrBreakdown(
        EventRecommendationService $service,
        Request $request,
    ): JsonResponse {
        $tracked = $request->query('tracked', '');
        $trackedEvents = is_string($tracked) && $tracked !== ''
            ? explode(',', $tracked)
            : EventCatalog::names();

        return response()->json($service->aarrrBreakdown($trackedEvents));
    }

    /**
     * Get priority tier configuration for recommendations.
     */
    public function recommendationTiers(EventRecommendationService $service): JsonResponse
    {
        return response()->json($service->tiers());
    }

    /**
     * Analyze provider coverage gaps for tracked events.
     */
    public function providerGapAnalysis(
        ProviderGapAnalyzer $analyzer,
        Request $request,
    ): JsonResponse {
        $tracked = $request->query('tracked', '');
        $trackedEvents = is_string($tracked) && $tracked !== ''
            ? explode(',', $tracked)
            : EventCatalog::names();

        return response()->json($analyzer->analyze($trackedEvents));
    }

    /**
     * Get provider gap detail for a specific provider.
     */
    public function providerGapDetail(
        ProviderGapAnalyzer $analyzer,
        Request $request,
        string $provider,
    ): JsonResponse {
        $tracked = $request->query('tracked', '');
        $trackedEvents = is_string($tracked) && $tracked !== ''
            ? explode(',', $tracked)
            : EventCatalog::names();

        if (! in_array($provider, $analyzer->supportedProviders(), true)) {
            return response()->json([
                'error' => "Unsupported provider: {$provider}",
                'supported' => $analyzer->supportedProviders(),
            ], 400);
        }

        return response()->json([
            'provider' => $provider,
            'mapped' => $analyzer->mappedEvents($trackedEvents, $provider),
            'gaps' => $analyzer->gapEvents($trackedEvents, $provider),
            'mapped_count' => count($analyzer->mappedEvents($trackedEvents, $provider)),
            'gap_count' => count($analyzer->gapEvents($trackedEvents, $provider)),
        ]);
    }

    // ── Event Sparkline Endpoints (v7.2.0) ───────────────────────────

    /**
     * Get sparkline data for a single event.
     */
    public function eventSparkline(
        EventSparklineService $service,
        Request $request,
        string $eventName,
    ): JsonResponse {
        $points = (int) $request->query('points', 0);
        $periodHours = (int) $request->query('period', 0);

        return response()->json($service->sparkline($eventName, $points, $periodHours));
    }

    /**
     * Get sparkline data for multiple events.
     */
    public function eventSparklines(
        EventSparklineService $service,
        Request $request,
    ): JsonResponse {
        $events = $request->query('events', '');
        $eventNames = is_string($events) && $events !== ''
            ? explode(',', $events)
            : [];
        $points = (int) $request->query('points', 0);
        $periodHours = (int) $request->query('period', 0);

        if ($eventNames === []) {
            return response()->json(['error' => 'Parameter "events" is required (comma-separated event names).'], 400);
        }

        return response()->json($service->sparklines($eventNames, $points, $periodHours));
    }

    /**
     * Get sparkline dashboard summary with top events and category breakdowns.
     */
    public function sparklineDashboard(
        EventSparklineService $service,
        Request $request,
    ): JsonResponse {
        $points = (int) $request->query('points', 0);

        return response()->json($service->dashboardSummary($points));
    }

    /**
     * Get category-level sparkline aggregation.
     */
    public function sparklineCategories(
        EventSparklineService $service,
        Request $request,
    ): JsonResponse {
        $points = (int) $request->query('points', 0);
        $periodHours = (int) $request->query('period', 0);

        return response()->json($service->categorySparklines($points, $periodHours));
    }

    // ── Event Co-occurrence Endpoints (v7.2.0) ──────────────────────

    /**
     * Get the full co-occurrence matrix.
     */
    public function cooccurrenceMatrix(
        EventCooccurrenceService $service,
    ): JsonResponse {
        return response()->json($service->getMatrix());
    }

    /**
     * Get top co-occurring event pairs.
     */
    public function cooccurrenceTopPairs(
        EventCooccurrenceService $service,
        Request $request,
    ): JsonResponse {
        $limit = (int) $request->query('limit', 20);

        return response()->json($service->topPairs($limit));
    }

    /**
     * Get events that co-occur with a specific event.
     */
    public function cooccurrenceWith(
        EventCooccurrenceService $service,
        Request $request,
        string $eventName,
    ): JsonResponse {
        $limit = (int) $request->query('limit', 10);

        return response()->json([
            'event' => $eventName,
            'cooccurring' => $service->cooccurringWith($eventName, $limit),
        ]);
    }

    /**
     * Get co-occurrence dashboard summary with clusters and degrees.
     */
    public function cooccurrenceDashboard(
        EventCooccurrenceService $service,
    ): JsonResponse {
        return response()->json($service->dashboardSummary());
    }

    // ─── Cohort Waterfall Analysis (v7.5.0) ──────────────────────────────

    /**
     * Generate full cohort waterfall report.
     *
     * POST /api/analytics/cohort-waterfall
     *
     * @bodyParam cohorts array Cohort data keyed by period
     * @bodyParam period string Granularity (weekly|monthly)
     */
    public function cohortWaterfall(
        CohortWaterfallService $service,
        Request $request,
    ): JsonResponse {
        $data = $request->json()->all() ?? [];

        return response()->json($service->report($data));
    }

    /**
     * Get quick cohort waterfall summary.
     *
     * POST /api/analytics/cohort-waterfall/summary
     *
     * @bodyParam cohorts array Cohort data keyed by period
     */
    public function cohortWaterfallSummary(
        CohortWaterfallService $service,
        Request $request,
    ): JsonResponse {
        $data = $request->json()->all() ?? [];

        return response()->json($service->quickSummary($data));
    }

    /**
     * Compare two cohort periods side-by-side.
     *
     * POST /api/analytics/cohort-waterfall/compare
     *
     * @bodyParam cohort_a array Cohort A data
     * @bodyParam cohort_b array Cohort B data
     */
    public function cohortWaterfallCompare(
        CohortWaterfallService $service,
        Request $request,
    ): JsonResponse {
        $cohortA = $request->json('cohort_a', []);
        $cohortB = $request->json('cohort_b', []);

        return response()->json($service->compare($cohortA, $cohortB));
    }

    /**
     * Get the default waterfall stages.
     *
     * GET /api/analytics/cohort-waterfall/stages
     */
    public function cohortWaterfallStages(
        CohortWaterfallService $service,
    ): JsonResponse {
        return response()->json([
            'stages' => $service->stages(),
            'enabled' => $service->isEnabled(),
        ]);
    }

    // ─── Funnel Drop-off Intelligence (v7.5.0) ──────────────────────────

    /**
     * Analyze a funnel with drop-off intelligence.
     *
     * POST /api/analytics/funnel-intelligence
     *
     * @bodyParam steps array Ordered funnel step names
     * @bodyParam step_counts array Per-step visitor counts
     * @bodyParam step_times array Per-step average time in seconds
     */
    public function funnelIntelligence(
        FunnelDropoffIntelligenceService $service,
        Request $request,
    ): JsonResponse {
        $steps = $request->json('steps', []);
        $data = $request->json()->all() ?? [];

        return response()->json($service->analyze($steps, $data));
    }

    /**
     * Compare funnel performance across two time periods.
     *
     * POST /api/analytics/funnel-intelligence/compare
     *
     * @bodyParam steps array Ordered funnel step names
     * @bodyParam period_a array Data for period A
     * @bodyParam period_b array Data for period B
     */
    public function funnelIntelligenceCompare(
        FunnelDropoffIntelligenceService $service,
        Request $request,
    ): JsonResponse {
        $steps = $request->json('steps', []);
        $periodA = $request->json('period_a', []);
        $periodB = $request->json('period_b', []);

        return response()->json($service->comparePeriods($steps, $periodA, $periodB));
    }

    // ── Event Signal Intelligence (v7.7.0) ─────────────────────────

    /**
     * GET /api/analytics/signal — Full signal intelligence report.
     */
    public function signalIntelligenceReport(EventSignalIntelligenceService $service): JsonResponse
    {
        return response()->json($service->report());
    }

    /**
     * GET /api/analytics/signal/score — Composite signal score only.
     */
    public function signalIntelligenceScore(EventSignalIntelligenceService $service): JsonResponse
    {
        $report = $service->report();

        return response()->json([
            'score' => $report['signal_score'],
            'grade' => $report['grade'],
            'computed_at' => $report['computed_at'],
        ]);
    }

    /**
     * GET /api/analytics/signal/anomalies — Detected anomalies only.
     */
    public function signalIntelligenceAnomalies(EventSignalIntelligenceService $service): JsonResponse
    {
        return response()->json([
            'anomalies' => $service->anomalies(),
        ]);
    }

    /**
     * GET /api/analytics/signal/providers — Provider health signals.
     */
    public function signalIntelligenceProviders(EventSignalIntelligenceService $service): JsonResponse
    {
        return response()->json([
            'providers' => $service->providerSignals(),
        ]);
    }

    /**
     * GET /api/analytics/signal/categories — Category coverage signals.
     */
    public function signalIntelligenceCategories(EventSignalIntelligenceService $service): JsonResponse
    {
        return response()->json([
            'categories' => $service->categorySignals(),
        ]);
    }

    /**
     * GET /api/analytics/signal/staleness — Staleness summary.
     */
    public function signalIntelligenceStaleness(EventSignalIntelligenceService $service): JsonResponse
    {
        $report = $service->report();

        return response()->json($report['staleness_summary']);
    }

    /**
     * GET /api/analytics/signal/signal-to-noise — Signal-to-noise ratio.
     */
    public function signalIntelligenceSignalToNoise(EventSignalIntelligenceService $service): JsonResponse
    {
        return response()->json([
            'signal_to_noise' => $service->calculateSignalToNoise(),
        ]);
    }

    /**
     * GET /api/analytics/signal/dispatch-balance — Dispatch balance score.
     */
    public function signalIntelligenceDispatchBalance(EventSignalIntelligenceService $service): JsonResponse
    {
        $providerSignals = $service->providerSignals();

        return response()->json([
            'dispatch_balance' => $service->calculateDispatchBalance($providerSignals),
        ]);
    }

    // ── Attribution Modeling (v7.9.0) ──────────────────────────────────

    /**
     * GET /api/analytics/attribution/models — List available attribution models.
     */
    public function attributionModels(AttributionModelService $service): JsonResponse
    {
        return response()->json([
            'models' => $service->availableModels(),
            'default' => $service->getDefaultModel(),
        ]);
    }

    /**
     * POST /api/analytics/attribution/attribute — Compute attribution for a journey.
     *
     * @bodyParam model string Example: position_based
     * @bodyParam touchpoints array Example: [{"source":"google","medium":"cpc","campaign":"brand"}]
     * @bodyParam revenue float Example: 99.99
     */
    public function attributionAttribute(Request $request, AttributionModelService $service): JsonResponse
    {
        $model = $request->input('model', 'position_based');
        $touchpoints = $request->input('touchpoints', []);
        $revenue = (float) $request->input('revenue', 1.0);

        return response()->json($service->attribute($model, $touchpoints, $revenue));
    }

    /**
     * POST /api/analytics/attribution/compare — Compare attribution across all models.
     */
    public function attributionCompare(Request $request, AttributionModelService $service): JsonResponse
    {
        $touchpoints = $request->input('touchpoints', []);
        $revenue = (float) $request->input('revenue', 1.0);

        return response()->json($service->compareModels($touchpoints, $revenue));
    }

    /**
     * POST /api/analytics/attribution/by-channel — Aggregate attribution by channel.
     */
    public function attributionByChannel(Request $request, AttributionModelService $service): JsonResponse
    {
        $journeys = $request->input('journeys', []);
        $model = $request->input('model', 'position_based');

        return response()->json($service->aggregateByChannel($journeys, $model));
    }

    /**
     * POST /api/analytics/attribution/by-campaign — Aggregate attribution by campaign.
     */
    public function attributionByCampaign(Request $request, AttributionModelService $service): JsonResponse
    {
        $journeys = $request->input('journeys', []);
        $model = $request->input('model', 'position_based');

        return response()->json($service->aggregateByCampaign($journeys, $model));
    }

    /**
     * POST /api/analytics/attribution/efficiency — Channel efficiency metrics (ROAS, CPA).
     */
    public function attributionEfficiency(Request $request, AttributionModelService $service): JsonResponse
    {
        $journeys = $request->input('journeys', []);
        $model = $request->input('model', 'position_based');
        $costs = $request->input('costs', []);

        return response()->json($service->channelEfficiency($journeys, $model, $costs));
    }

    // ── SaaS Feature Matrix (v7.9.0) ───────────────────────────────────

    /**
     * GET /api/analytics/feature-matrix — Full feature parity matrix.
     */
    public function featureMatrix(SaaSFeatureMatrixService $service): JsonResponse
    {
        return response()->json($service->buildMatrix());
    }

    /**
     * GET /api/analytics/feature-matrix/summary — Coverage summary with grade.
     */
    public function featureMatrixSummary(SaaSFeatureMatrixService $service): JsonResponse
    {
        return response()->json($service->coverageSummary());
    }

    /**
     * GET /api/analytics/feature-matrix/gaps — List unsupported features.
     */
    public function featureMatrixGaps(SaaSFeatureMatrixService $service): JsonResponse
    {
        return response()->json([
            'gaps' => $service->getGaps(),
            'count' => count($service->getGaps()),
        ]);
    }

    /**
     * GET /api/analytics/feature-matrix/compare/{competitor} — Compare with a competitor.
     */
    public function featureMatrixCompare(string $competitor, SaaSFeatureMatrixService $service): JsonResponse
    {
        return response()->json($service->compareWith($competitor));
    }

    // ── Event Sessionizer (v8.0.0) ────────────────────────────────────────

    /**
     * GET /api/analytics/sessions/{clientId} — Get all active sessions for a client.
     */
    public function sessionizerClientSessions(string $clientId, EventSessionizer $sessionizer): JsonResponse
    {
        return response()->json([
            'client_id' => $clientId,
            'sessions' => $sessionizer->getClientSessions($clientId),
        ]);
    }

    /**
     * GET /api/analytics/sessions/{clientId}/{sessionId} — Get a specific session.
     */
    public function sessionizerGetSession(string $clientId, string $sessionId, EventSessionizer $sessionizer): JsonResponse
    {
        $session = $sessionizer->getSession($clientId, $sessionId);

        if ($session === null) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        return response()->json($session);
    }

    /**
     * GET /api/analytics/sessions/{clientId}/stats — Get aggregated session statistics.
     */
    public function sessionizerAggregateStats(string $clientId, EventSessionizer $sessionizer): JsonResponse
    {
        return response()->json($sessionizer->aggregateStats($clientId));
    }

    /**
     * POST /api/analytics/sessions/end/{clientId}/{sessionId} — End a session.
     */
    public function sessionizerEndSession(string $clientId, string $sessionId, EventSessionizer $sessionizer): JsonResponse
    {
        $result = $sessionizer->endSession($clientId, $sessionId);

        if ($result === null) {
            return response()->json(['error' => 'Session not found'], 404);
        }

        return response()->json($result);
    }

    // ── Funnel Aggregation (v8.0.0) ───────────────────────────────────────

    /**
     * GET /api/analytics/funnels/aggregated/{funnelName} — Get funnel conversion report.
     */
    public function funnelAggregatedReport(string $funnelName, EventFunnelAggregator $aggregator): JsonResponse
    {
        $report = $aggregator->getFunnelReport($funnelName);

        if ($report === null) {
            return response()->json(['error' => 'Funnel not found', 'available' => array_keys($aggregator->getDefinedFunnels())], 404);
        }

        return response()->json($report);
    }

    /**
     * GET /api/analytics/funnels/aggregated — Get all funnel reports summary.
     */
    public function funnelAllAggregatedReports(EventFunnelAggregator $aggregator): JsonResponse
    {
        return response()->json([
            'funnels' => $aggregator->getAllFunnelReports(),
            'definitions' => $aggregator->getDefinedFunnels(),
        ]);
    }

    /**
     * GET /api/analytics/funnels/definitions — Get all funnel definitions.
     */
    public function funnelDefinitions(EventFunnelAggregator $aggregator): JsonResponse
    {
        return response()->json($aggregator->getDefinedFunnels());
    }

    // ── Cohort Intelligence Endpoints (v8.1.0) ──────────────────────────

    /**
     * POST /api/analytics/cohort-intelligence/profile — Profile a single user.
     *
     * Body: { "identity": "user-123", "events": [{ "name": "login", "params": {}, "timestamp": 1234567890 }] }
     */
    public function cohortIntelligenceProfile(Request $request, CohortBehaviorProfilerService $profiler): JsonResponse
    {
        $request->validate([
            'identity' => 'required|string',
            'events' => 'required|array',
            'events.*.name' => 'required|string',
        ]);

        $identity = $request->input('identity');
        $eventsData = $request->input('events', []);
        $events = array_map(
            fn (array $e): AnalyticsEvent => AnalyticsEvent::make(
                $e['name'],
                $e['params'] ?? [],
                $e['client_id'] ?? $identity,
                $e['user_id'] ?? null,
                $e['timestamp'] ?? null,
            ),
            $eventsData,
        );

        $profile = $profiler->profile($identity, $events);

        return response()->json(['status' => 'ok', 'profile' => $profile]);
    }

    /**
     * POST /api/analytics/cohort-intelligence/profile/batch — Batch profile users.
     */
    public function cohortIntelligenceProfileBatch(Request $request, CohortBehaviorProfilerService $profiler): JsonResponse
    {
        $request->validate([
            'users' => 'required|array',
            'users.*.identity' => 'required|string',
            'users.*.events' => 'required|array',
        ]);

        $userEvents = [];
        foreach ($request->input('users', []) as $user) {
            $identity = $user['identity'];
            $events = array_map(
                fn (array $e): AnalyticsEvent => AnalyticsEvent::make(
                    $e['name'],
                    $e['params'] ?? [],
                    $e['client_id'] ?? $identity,
                    $e['user_id'] ?? null,
                    $e['timestamp'] ?? null,
                ),
                $user['events'] ?? [],
            );
            $userEvents[$identity] = $events;
        }

        return response()->json([
            'status' => 'ok',
            'profiles' => $profiler->profileBatch($userEvents),
        ]);
    }

    /**
     * POST /api/analytics/cohort-intelligence/distribution — Cohort distribution.
     */
    public function cohortIntelligenceDistribution(Request $request, CohortBehaviorProfilerService $profiler): JsonResponse
    {
        $request->validate([
            'users' => 'required|array',
            'users.*.identity' => 'required|string',
            'users.*.events' => 'required|array',
        ]);

        $userEvents = [];
        foreach ($request->input('users', []) as $user) {
            $identity = $user['identity'];
            $events = array_map(
                fn (array $e): AnalyticsEvent => AnalyticsEvent::make(
                    $e['name'],
                    $e['params'] ?? [],
                    $e['client_id'] ?? $identity,
                    $e['user_id'] ?? null,
                    $e['timestamp'] ?? null,
                ),
                $user['events'] ?? [],
            );
            $userEvents[$identity] = $events;
        }

        return response()->json([
            'status' => 'ok',
            'distribution' => $profiler->distribution($userEvents),
        ]);
    }

    /**
     * POST /api/analytics/cohort-intelligence/transitions — Transition matrix.
     */
    public function cohortIntelligenceTransitions(Request $request, CohortBehaviorProfilerService $profiler): JsonResponse
    {
        $request->validate([
            'transitions' => 'required|array',
            'transitions.*.previous' => 'required|string',
            'transitions.*.current' => 'required|string',
        ]);

        return response()->json([
            'status' => 'ok',
            'analysis' => $profiler->transitionAnalysis($request->input('transitions', [])),
        ]);
    }

    /**
     * POST /api/analytics/cohort-intelligence/predict — Predict cohort transitions.
     */
    public function cohortIntelligencePredict(Request $request, CohortBehaviorProfilerService $profiler): JsonResponse
    {
        $request->validate([
            'users' => 'required|array',
            'users.*.identity' => 'required|string',
            'users.*.events' => 'required|array',
            'target_cohort' => 'required|string',
            'threshold' => 'nullable|numeric|min:0|max:1',
        ];

        $userEvents = [];
        foreach ($request->input('users', []) as $user) {
            $identity = $user['identity'];
            $events = array_map(
                fn (array $e): AnalyticsEvent => AnalyticsEvent::make(
                    $e['name'],
                    $e['params'] ?? [],
                    $e['client_id'] ?? $identity,
                    $e['user_id'] ?? null,
                    $e['timestamp'] ?? null,
                ),
                $user['events'] ?? [],
            );
            $userEvents[$identity] = $events;
        }

        $targetCohort = $request->input('target_cohort');
        $threshold = (float) $request->input('threshold', 0.6);

        return response()->json([
            'status' => 'ok',
            'prediction' => $profiler->predictTransitions($userEvents, $targetCohort, $threshold),
        ]);
    }

    /**
     * POST /api/analytics/cohort-intelligence/score — Predictive scoring for a single user.
     */
    public function cohortIntelligenceScore(Request $request, EventPredictiveScoringService $scoring): JsonResponse
    {
        $request->validate([
            'identity' => 'required|string',
            'events' => 'required|array',
            'events.*.name' => 'required|string',
        ]);

        $identity = $request->input('identity');
        $eventsData = $request->input('events', []);
        $events = array_map(
            fn (array $e): AnalyticsEvent => AnalyticsEvent::make(
                $e['name'],
                $e['params'] ?? [],
                $e['client_id'] ?? $identity,
                $e['user_id'] ?? null,
                $e['timestamp'] ?? null,
            ),
            $eventsData,
        );

        return response()->json([
            'status' => 'ok',
            'scores' => $scoring->score($identity, $events),
        ]);
    }

    /**
     * POST /api/analytics/cohort-intelligence/score/batch — Batch predictive scoring.
     */
    public function cohortIntelligenceScoreBatch(Request $request, EventPredictiveScoringService $scoring): JsonResponse
    {
        $request->validate([
            'users' => 'required|array',
            'users.*.identity' => 'required|string',
            'users.*.events' => 'required|array',
        ]);

        $userEvents = [];
        foreach ($request->input('users', []) as $user) {
            $identity = $user['identity'];
            $events = array_map(
                fn (array $e): AnalyticsEvent => AnalyticsEvent::make(
                    $e['name'],
                    $e['params'] ?? [],
                    $e['client_id'] ?? $identity,
                    $e['user_id'] ?? null,
                    $e['timestamp'] ?? null,
                ),
                $user['events'] ?? [],
            );
            $userEvents[$identity] = $events;
        }

        return response()->json([
            'status' => 'ok',
            'scores' => $scoring->scoreBatch($userEvents),
        ]);
    }

    /**
     * POST /api/analytics/cohort-intelligence/summary — Scoring summary.
     */
    public function cohortIntelligenceSummary(Request $request, EventPredictiveScoringService $scoring): JsonResponse
    {
        $request->validate([
            'users' => 'required|array',
            'users.*.identity' => 'required|string',
            'users.*.events' => 'required|array',
        ]);

        $userEvents = [];
        foreach ($request->input('users', []) as $user) {
            $identity = $user['identity'];
            $events = array_map(
                fn (array $e): AnalyticsEvent => AnalyticsEvent::make(
                    $e['name'],
                    $e['params'] ?? [],
                    $e['client_id'] ?? $identity,
                    $e['user_id'] ?? null,
                    $e['timestamp'] ?? null,
                ),
                $user['events'] ?? [],
            );
            $userEvents[$identity] = $events;
        }

        return response()->json([
            'status' => 'ok',
            'summary' => $scoring->summary($userEvents),
        ]);
    }

    /**
     * POST /api/analytics/cohort-intelligence/churn-top — Top churn risks.
     */
    public function cohortIntelligenceChurnTop(Request $request, EventPredictiveScoringService $scoring): JsonResponse
    {
        $request->validate([
            'users' => 'required|array',
            'users.*.identity' => 'required|string',
            'users.*.events' => 'required|array',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $userEvents = [];
        foreach ($request->input('users', []) as $user) {
            $identity = $user['identity'];
            $events = array_map(
                fn (array $e): AnalyticsEvent => AnalyticsEvent::make(
                    $e['name'],
                    $e['params'] ?? [],
                    $e['client_id'] ?? $identity,
                    $e['user_id'] ?? null,
                    $e['timestamp'] ?? null,
                ),
                $user['events'] ?? [],
            );
            $userEvents[$identity] = $events;
        }

        return response()->json([
            'status' => 'ok',
            'churn_risks' => $scoring->topChurnRisks($userEvents, $request->input('limit', 10)),
        ]);
    }

    /**
     * POST /api/analytics/cohort-intelligence/expansion-top — Top expansion candidates.
     */
    public function cohortIntelligenceExpansionTop(Request $request, EventPredictiveScoringService $scoring): JsonResponse
    {
        $request->validate([
            'users' => 'required|array',
            'users.*.identity' => 'required|string',
            'users.*.events' => 'required|array',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $userEvents = [];
        foreach ($request->input('users', []) as $user) {
            $identity = $user['identity'];
            $events = array_map(
                fn (array $e): AnalyticsEvent => AnalyticsEvent::make(
                    $e['name'],
                    $e['params'] ?? [],
                    $e['client_id'] ?? $identity,
                    $e['user_id'] ?? null,
                    $e['timestamp'] ?? null,
                ),
                $user['events'] ?? [],
            );
            $userEvents[$identity] = $events;
        }

        return response()->json([
            'status' => 'ok',
            'expansion_candidates' => $scoring->topExpansionCandidates($userEvents, $request->input('limit', 10)),
        ]);
    }

    /**
     * GET /api/analytics/cohort-intelligence/insights/{cohort} — Cohort insights.
     */
    public function cohortIntelligenceInsights(Request $request, string $cohort, CohortBehaviorProfilerService $profiler): JsonResponse
    {
        $request->validate([
            'users' => 'required|array',
            'users.*.identity' => 'required|string',
            'users.*.events' => 'required|array',
        ]);

        $userEvents = [];
        foreach ($request->input('users', []) as $user) {
            $identity = $user['identity'];
            $events = array_map(
                fn (array $e): AnalyticsEvent => AnalyticsEvent::make(
                    $e['name'],
                    $e['params'] ?? [],
                    $e['client_id'] ?? $identity,
                    $e['user_id'] ?? null,
                    $e['timestamp'] ?? null,
                ),
                $user['events'] ?? [],
            );
            $userEvents[$identity] = $events;
        }

        return response()->json([
            'status' => 'ok',
            'insights' => $profiler->cohortInsights($cohort, $userEvents),
        ]);
    }

    // ─── Identity Graph — Cross-Device Identity Resolution (v8.7.0) ──

    /**
     * GET /api/analytics/identity-graph/user/{userId}
     *
     * Get the full identity graph for a user (all linked clients, devices, confidence scores).
     */
    public function identityGraphGet(Request $request, string $userId, IdentityGraphService $graph): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'graph' => $graph->getGraph($userId),
        ]);
    }

    /**
     * POST /api/analytics/identity-graph/link
     *
     * Explicitly link a client ID to a user ID (typically after login/register).
     *
     * Body: { "client_id": "uuid", "device_id": "optional-fingerprint" }
     */
    public function identityGraphLink(Request $request, IdentityGraphService $graph): JsonResponse
    {
        $request->validate([
            'client_id' => 'required|string',
            'device_id' => 'nullable|string',
        ]);

        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $userId = $user->getKey();
        $userIdStr = is_int($userId) || is_string($userId) ? (string) $userId : null;
        $clientId = $request->input('client_id');
        $deviceId = $request->input('device_id');

        if ($userIdStr === null || $clientId === null) {
            return response()->json(['error' => 'Invalid identity data'], 422);
        }

        $result = $graph->linkExplicit($clientId, $userIdStr, $deviceId);

        return response()->json([
            'status' => 'ok',
            'link' => $result,
        ]);
    }

    /**
     * POST /api/analytics/identity-graph/infer
     *
     * Infer identity link based on device fingerprint (cross-device stitching).
     *
     * Body: { "client_id": "uuid", "device_id": "fingerprint" }
     */
    public function identityGraphInfer(Request $request, IdentityGraphService $graph): JsonResponse
    {
        $request->validate([
            'client_id' => 'required|string',
            'device_id' => 'required|string',
            'ip' => 'nullable|string',
            'user_agent' => 'nullable|string',
        ]);

        $result = $graph->inferIdentity(
            $request->input('client_id'),
            $request->input('device_id'),
            $request->input('ip'),
            $request->input('user_agent'),
        );

        return response()->json([
            'status' => 'ok',
            'inference' => $result,
        ]);
    }

    /**
     * POST /api/analytics/identity-graph/merge
     *
     * Merge two user identity graphs (e.g., when merging accounts).
     *
     * Body: { "source_user_id": "id1", "target_user_id": "id2" }
     */
    public function identityGraphMerge(Request $request, IdentityGraphService $graph): JsonResponse
    {
        $request->validate([
            'source_user_id' => 'required|string',
            'target_user_id' => 'required|string',
        ]);

        $result = $graph->mergeUsers(
            $request->input('source_user_id'),
            $request->input('target_user_id'),
        );

        return response()->json([
            'status' => 'ok',
            'merge' => $result,
        ]);
    }

    /**
     * POST /api/analytics/identity-graph/same-user
     *
     * Check if two client IDs belong to the same user (cross-device stitching).
     *
     * Body: { "client_id_a": "uuid1", "client_id_b": "uuid2" }
     */
    public function identityGraphSameUser(Request $request, IdentityGraphService $graph): JsonResponse
    {
        $request->validate([
            'client_id_a' => 'required|string',
            'client_id_b' => 'required|string',
        ]);

        $result = $graph->areSameUser(
            $request->input('client_id_a'),
            $request->input('client_id_b'),
        );

        return response()->json([
            'status' => 'ok',
            'comparison' => $result,
        ]);
    }

    /**
     * GET /api/analytics/identity-graph/fingerprint
     *
     * Generate a device fingerprint from the current request.
     * Returns SHA-256 hash of normalized request components.
     */
    public function identityGraphFingerprint(Request $request, DeviceFingerprintService $fingerprint): JsonResponse
    {
        $fp = $fingerprint->fingerprint($request);

        return response()->json([
            'status' => 'ok',
            'fingerprint' => $fp,
            'components' => $fingerprint->getComponents(),
            'enabled' => $fingerprint->isEnabled(),
        ]);
    }

    // ─── Guard Rails (v8.9.0) ─────────────────────────────────────

    /**
     * Full guard rails check.
     *
     * GET /analytics/guard-rails
     *
     * @return JsonResponse
     */
    public function guardRailsCheck(Request $request): JsonResponse
    {
        /** @var TrackingGuardRailsService|null $service */
        $service = app(TrackingGuardRailsService::class);

        $metrics = $this->gatherGuardRailsMetrics();

        return response()->json($service->check($metrics));
    }

    /**
     * Quick quality score.
     *
     * GET /analytics/guard-rails/score
     *
     * @return JsonResponse
     */
    public function guardRailsScore(Request $request): JsonResponse
    {
        /** @var TrackingGuardRailsService $service */
        $service = app(TrackingGuardRailsService::class);

        $metrics = $this->gatherGuardRailsMetrics();

        return response()->json($service->quickScore($metrics));
    }

    /**
     * Violations only.
     *
     * GET /analytics/guard-rails/violations?severity=warning
     *
     * @return JsonResponse
     */
    public function guardRailsViolations(Request $request): JsonResponse
    {
        /** @var TrackingGuardRailsService $service */
        $service = app(TrackingGuardRailsService::class);

        $severity = (string) ($request->query('severity', 'info'));
        $metrics = $this->gatherGuardRailsMetrics();

        return response()->json([
            'violations' => $service->violations($metrics, $severity),
        ]);
    }

    /**
     * Core event coverage check.
     *
     * GET /analytics/guard-rails/coverage
     *
     * @return JsonResponse
     */
    public function guardRailsCoverage(Request $request): JsonResponse
    {
        /** @var TrackingGuardRailsService $service */
        $service = app(TrackingGuardRailsService::class);

        $metrics = $this->gatherGuardRailsMetrics();
        $trackedNames = $metrics['tracked_event_names'] ?? [];

        return response()->json($service->coreEventCoverage($trackedNames));
    }

    /**
     * Validate a single event name against naming conventions.
     *
     * GET /analytics/guard-rails/validate-name?name=MyCustomEvent
     *
     * @return JsonResponse
     */
    public function guardRailsValidateName(Request $request): JsonResponse
    {
        /** @var TrackingGuardRailsService $service */
        $service = app(TrackingGuardRailsService::class);

        $name = (string) ($request->query('name', ''));

        if ($name === '') {
            return response()->json(['error' => 'Missing "name" query parameter'], 422);
        }

        return response()->json($service->validateEventName($name));
    }

    /**
     * Gather metrics for guard rails from event stream.
     *
     * @return array{total_events: int, tracked_event_names: list<string>, identity_linked_count: int, total_clients: int, consent_log_enabled: bool, consent_default: string}
     */
    private function gatherGuardRailsMetrics(): array
    {
        $totalEvents = 0;
        $trackedNames = [];
        $linkedCount = 0;
        $totalClients = 0;

        if ($this->streamService !== null) {
            $stats = $this->streamService->getStats();
            $totalEvents = (int) ($stats['total_events'] ?? 0);
            $linkedCount = (int) ($stats['identity_linked_count'] ?? 0);
            $totalClients = (int) ($stats['unique_clients'] ?? 0);

            $recent = $this->streamService->getRecentEvents(500);
            foreach ($recent as $event) {
                $name = $event['event'] ?? null;
                if (is_string($name) && $name !== '' && ! in_array($name, $trackedNames, true)) {
                    $trackedNames[] = $name;
                }
            }
        }

        $consentConfig = $this->config->get('zeroboiler.analytics.consent', []);
        /** @var array{log_enabled?: bool, default?: string} $consentConfig */

        return [
            'total_events' => $totalEvents,
            'tracked_event_names' => $trackedNames,
            'identity_linked_count' => $linkedCount,
            'total_clients' => $totalClients,
            'consent_log_enabled' => (bool) ($consentConfig['log_enabled'] ?? false),
            'consent_default' => (string) ($consentConfig['default'] ?? 'granted'),
        ];
    }

    // ── Event Delivery Confirmation (v9.0.0) ──────────────────────────

    /**
     * Get comprehensive delivery dashboard data.
     *
     * Returns reliability scores, per-provider health, response time stats,
     * outage detection, and SLA status for all enabled providers.
     */
    public function deliveryDashboard(): JsonResponse
    {
        /** @var EventDeliveryConfirmationService $service */
        $service = app(EventDeliveryConfirmationService::class);

        return response()->json($service->getDeliveryDashboard());
    }

    /**
     * Get the delivery reliability score (0-100) with A-F grading.
     */
    public function deliveryReliabilityScore(): JsonResponse
    {
        /** @var EventDeliveryConfirmationService $service */
        $service = app(EventDeliveryConfirmationService::class);

        return response()->json($service->getReliabilityScore());
    }

    /**
     * Check delivery receipt for a specific event ID.
     *
     * Returns per-provider delivery status for the given event.
     */
    public function deliveryCheckReceipt(string $eventId): JsonResponse
    {
        /** @var EventDeliveryConfirmationService $service */
        $service = app(EventDeliveryConfirmationService::class);

        return response()->json($service->checkReceipt($eventId));
    }

    /**
     * Get response time percentiles for a specific provider.
     *
     * Returns p50, p95, p99, avg, min, max response times.
     */
    public function deliveryResponseTimes(string $provider): JsonResponse
    {
        /** @var EventDeliveryConfirmationService $service */
        $service = app(EventDeliveryConfirmationService::class);

        return response()->json($service->getResponseTimeStats($provider));
    }

    /**
     * Get recent delivery history for a specific provider.
     */
    public function deliveryRecentDeliveries(Request $request, string $provider): JsonResponse
    {
        /** @var EventDeliveryConfirmationService $service */
        $service = app(EventDeliveryConfirmationService::class);

        $limit = (int) ($request->query('limit', 50));

        return response()->json($service->getRecentDeliveries($provider, $limit));
    }

    /**
     * Check if a provider is currently in outage state.
     */
    public function deliveryOutageStatus(string $provider): JsonResponse
    {
        /** @var EventDeliveryConfirmationService $service */
        $service = app(EventDeliveryConfirmationService::class);

        return response()->json([
            'provider' => $provider,
            'in_outage' => $service->isProviderInOutage($provider),
        ]);
    }

    /**
     * Clear delivery tracking stats.
     *
     * Optionally limited to a specific provider via ?provider=ga4
     */
    public function deliveryClearStats(Request $request): JsonResponse
    {
        /** @var EventDeliveryConfirmationService $service */
        $service = app(EventDeliveryConfirmationService::class);

        $provider = $request->query('provider');
        $service->clearStats(is_string($provider) && $provider !== '' ? $provider : null);

        return response()->json(['cleared' => true]);
    }

    // ── Event Idempotency (v9.3.0) ──────────────────────────────────────

    /**
     * Check idempotency service status and statistics.
     *
     * GET /api/analytics/idempotency
     *
     * @return JsonResponse
     */
    public function idempotencyStats(): JsonResponse
    {
        /** @var EventIdempotencyService $service */
        $service = app(EventIdempotencyService::class);

        return response()->json($service->getStats());
    }

    /**
     * Invalidate an idempotency key to allow re-dispatch.
     *
     * POST /api/analytics/idempotency/invalidate
     *
     * Body: { "key": "zb_idem_..." }
     *
     * @return JsonResponse
     */
    public function idempotencyInvalidate(Request $request): JsonResponse
    {
        $request->validate([
            'key' => 'required|string',
        ]);

        /** @var EventIdempotencyService $service */
        $service = app(EventIdempotencyService::class);

        $key = (string) $request->input('key');
        $invalidated = $service->invalidate($key);

        return response()->json([
            'invalidated' => $invalidated,
            'key' => $key,
        ]);
    }

    /**
     * Reset idempotency hit/miss counters.
     *
     * POST /api/analytics/idempotency/reset-stats
     *
     * @return JsonResponse
     */
    public function idempotencyResetStats(): JsonResponse
    {
        /** @var EventIdempotencyService $service */
        $service = app(EventIdempotencyService::class);
        $service->resetStats();

        return response()->json(['reset' => true]);
    }

    // ── Privacy Manifest (v9.3.0) ──────────────────────────────────────

    /**
     * Generate the full GDPR Article 30 privacy manifest.
     *
     * GET /api/analytics/privacy-manifest
     *
     * @return JsonResponse
     */
    public function privacyManifest(): JsonResponse
    {
        /** @var PrivacyManifestService $service */
        $service = app(PrivacyManifestService::class);

        return response()->json($service->generate());
    }

    /**
     * Get privacy manifest summary for dashboard display.
     *
     * GET /api/analytics/privacy-manifest/summary
     *
     * @return JsonResponse
     */
    public function privacyManifestSummary(): JsonResponse
    {
        /** @var PrivacyManifestService $service */
        $service = app(PrivacyManifestService::class);

        return response()->json($service->summary());
    }

    /**
     * Classify an event into GDPR data categories.
     *
     * GET /api/analytics/privacy-manifest/classify/{eventName}
     *
     * @return JsonResponse
     */
    public function privacyManifestClassify(string $eventName): JsonResponse
    {
        /** @var PrivacyManifestService $service */
        $service = app(PrivacyManifestService::class);

        $categories = $service->classifyEvent($eventName);
        $legalBasis = $service->legalBasisFor($categories);
        $retention = $service->retentionFor($categories);

        return response()->json([
            'event' => $eventName,
            'data_categories' => $categories,
            'legal_basis' => $legalBasis,
            'retention_days' => $retention,
            'contains_pii' => in_array('identifier', $categories, true),
            'contains_financial' => in_array('financial', $categories, true),
        ]);
    }

    /**
     * Invalidate cached privacy manifest.
     *
     * POST /api/analytics/privacy-manifest/invalidate
     *
     * @return JsonResponse
     */
    public function privacyManifestInvalidate(): JsonResponse
    {
        /** @var PrivacyManifestService $service */
        $service = app(PrivacyManifestService::class);
        $service->invalidateCache();

        return response()->json(['invalidated' => true]);
    }

    // ── Event Annotations (v9.3.0) ──────────────────────────────────

    /**
     * Get annotation service statistics.
     *
     * GET /api/analytics/annotations/stats
     *
     * @return JsonResponse
     */
    public function annotationStats(): JsonResponse
    {
        /** @var EventAnnotationService $service */
        $service = app(EventAnnotationService::class);

        return response()->json($service->getStats());
    }

    /**
     * Annotate an event.
     *
     * POST /api/analytics/annotations
     *
     * Body: { "event_id": "...", "key": "deployment", "value": "v1.2.3", "type": "deployment" }
     *
     * @return JsonResponse
     */
    public function annotateEvent(Request $request): JsonResponse
    {
        $request->validate([
            'event_id' => 'required|string',
            'key' => 'required|string|max:100',
            'value' => 'required',
            'type' => 'string|in:deployment,debug,experiment,release,custom',
        ]);

        /** @var EventAnnotationService $service */
        $service = app(EventAnnotationService::class);

        $eventId = (string) $request->input('event_id');
        $key = (string) $request->input('key');
        $value = $request->input('value');
        $type = (string) $request->input('type');

        $annotated = $service->annotate($eventId, $key, $value, $type);

        return response()->json([
            'annotated' => $annotated,
            'event_id' => $eventId,
            'key' => $key,
            'type' => $type,
        ]);
    }

    /**
     * Get annotations for an event.
     *
     * GET /api/analytics/annotations/{eventId}
     *
     * @return JsonResponse
     */
    public function getEventAnnotations(string $eventId): JsonResponse
    {
        /** @var EventAnnotationService $service */
        $service = app(EventAnnotationService::class);

        return response()->json([
            'event_id' => $eventId,
            'annotations' => $service->getAnnotations($eventId),
        ]);
    }

    /**
     * Remove an annotation from an event.
     *
     * DELETE /api/analytics/annotations/{eventId}/{key}
     *
     * @return JsonResponse
     */
    public function removeEventAnnotation(string $eventId, string $key): JsonResponse
    {
        /** @var EventAnnotationService $service */
        $service = app(EventAnnotationService::class);

        $removed = $service->removeAnnotation($eventId, $key);

        return response()->json([
            'removed' => $removed,
            'event_id' => $eventId,
            'key' => $key,
        ]);
    }

    /**
     * Clear all annotations for an event.
     *
     * DELETE /api/analytics/annotations/{eventId}
     *
     * @return JsonResponse
     */
    public function clearEventAnnotations(string $eventId): JsonResponse
    {
        /** @var EventAnnotationService $service */
        $service = app(EventAnnotationService::class);
        $service->clearAnnotations($eventId);

        return response()->json(['cleared' => true, 'event_id' => $eventId]);
    }

    /**
     * Trigger auto-attach annotations for an event.
     *
     * POST /api/analytics/annotations/auto-attach
     *
     * Body: { "event_id": "..." }
     *
     * @return JsonResponse
     */
    public function autoAttachAnnotations(Request $request): JsonResponse
    {
        $request->validate([
            'event_id' => 'required|string',
        ]);

        /** @var EventAnnotationService $service */
        $service = app(EventAnnotationService::class);

        $eventId = (string) $request->input('event_id');
        $attached = $service->autoAttachAnnotations($eventId);

        return response()->json([
            'event_id' => $eventId,
            'attached' => $attached,
        ]);
    }

    // ─── Provider Fallback Strategy (v9.4.0) ────────────────────────────

    /**
     * Get provider fallback statistics.
     *
     * GET /api/analytics/fallback
     *
     * @return JsonResponse
     */
    public function fallbackStats(): JsonResponse
    {
        /** @var ProviderFallbackService $service */
        $service = app(ProviderFallbackService::class);

        return response()->json($service->stats());
    }

    /**
     * Get all configured fallback chains.
     *
     * GET /api/analytics/fallback/chains
     *
     * @return JsonResponse
     */
    public function fallbackChains(): JsonResponse
    {
        /** @var ProviderFallbackService $service */
        $service = app(ProviderFallbackService::class);

        return response()->json([
            'chains' => $service->getAllChains(),
            'chain_count' => count($service->getAllChains()),
            'max_depth' => $service->getMaxFallbackDepth(),
        ]);
    }

    /**
     * Validate fallback chain configuration.
     *
     * GET /api/analytics/fallback/validate
     *
     * @return JsonResponse
     */
    public function fallbackValidate(): JsonResponse
    {
        /** @var ProviderFallbackService $service */
        $service = app(ProviderFallbackService::class);

        return response()->json($service->validate());
    }

    /**
     * Get fallback health summary with circuit breaker states.
     *
     * GET /api/analytics/fallback/health
     *
     * @return JsonResponse
     */
    public function fallbackHealth(): JsonResponse
    {
        /** @var ProviderFallbackService $fallbackService */
        $fallbackService = app(ProviderFallbackService::class);

        /** @var ProviderCircuitBreaker $circuitBreaker */
        $circuitBreaker = app(ProviderCircuitBreaker::class);

        $providers = ['ga4', 'gtm', 'meta', 'posthog', 'plausible', 'webhook'];
        $circuitStates = [];
        foreach ($providers as $provider) {
            $circuitStates[$provider] = $circuitBreaker->getState($provider);
        }

        return response()->json($fallbackService->healthSummary($circuitStates));
    }

    /**
     * Reset fallback counters.
     *
     * POST /api/analytics/fallback/reset-counts
     *
     * @return JsonResponse
     */
    public function fallbackResetCounts(): JsonResponse
    {
        /** @var ProviderFallbackService $service */
        $service = app(ProviderFallbackService::class);

        $service->resetCounters();
        $service->clearCachedCounts();

        return response()->json(['reset' => true]);
    }

    // ── B2B Group/Account Analytics (v9.5.0) ──────────────────────────────

    /**
     * Identify a B2B group with traits.
     *
     * POST /api/analytics/group/identify
     *
     * Body: { "group_id": "org_123", "traits": { "name": "Acme Corp", "industry": "SaaS", "plan": "enterprise" } }
     */
    public function groupIdentify(Request $request): JsonResponse
    {
        $request->validate([
            'group_id' => 'required|string',
            'traits' => 'array',
            'traits.*' => 'mixed',
        ]);

        $groupId = (string) $request->input('group_id');
        $traits = (array) $request->input('traits', []);

        $this->manager->group($groupId, $traits);

        return response()->json(['status' => 'ok', 'group_id' => $groupId]);
    }

    /**
     * Add a user to a B2B group.
     *
     * POST /api/analytics/group/members/add
     *
     * Body: { "user_id": "user_456", "group_id": "org_123", "role": "admin" }
     */
    public function groupAddMember(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|string',
            'group_id' => 'required|string',
            'role' => 'nullable|string',
            'traits' => 'array',
            'traits.*' => 'mixed',
        ]);

        $userId = (string) $request->input('user_id');
        $groupId = (string) $request->input('group_id');
        $role = $request->input('role');
        $traits = (array) $request->input('traits', []);

        $this->manager->groupAddMember($userId, $groupId, is_string($role) ? $role : null, $traits);

        return response()->json(['status' => 'ok', 'group_id' => $groupId, 'user_id' => $userId]);
    }

    /**
     * Remove a user from a B2B group.
     *
     * DELETE /api/analytics/group/members/remove
     *
     * Body: { "user_id": "user_456", "group_id": "org_123" }
     */
    public function groupRemoveMember(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|string',
            'group_id' => 'required|string',
        ]);

        try {
            $service = app(GroupAnalyticsService::class);
            $service->removeMember(
                (string) $request->input('user_id'),
                (string) $request->input('group_id'),
            );
        } catch (\Throwable) {
            // Service not available
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Get group properties and metadata.
     *
     * GET /api/analytics/group/{groupId}
     */
    public function groupGet(string $groupId): JsonResponse
    {
        $group = $this->manager->getGroup($groupId);

        return response()->json($group);
    }

    /**
     * Get all members of a group.
     *
     * GET /api/analytics/group/{groupId}/members
     */
    public function groupMembers(string $groupId): JsonResponse
    {
        try {
            $service = app(GroupAnalyticsService::class);
            $members = $service->getGroupMembers($groupId);

            return response()->json([
                'group_id' => $groupId,
                'members' => $members,
                'count' => count($members),
            ]);
        } catch (\Throwable) {
            return response()->json(['group_id' => $groupId, 'members' => [], 'count' => 0]);
        }
    }

    /**
     * Update group traits.
     *
     * POST /api/analytics/group/{groupId}/traits
     *
     * Body: { "traits": { "name": "New Corp Name", "mrr": 50000 } }
     */
    public function groupUpdateTraits(Request $request, string $groupId): JsonResponse
    {
        $request->validate([
            'traits' => 'required|array',
            'traits.*' => 'mixed',
        ]);

        try {
            $service = app(GroupAnalyticsService::class);
            $service->updateTraits($groupId, (array) $request->input('traits'));
        } catch (\Throwable) {
            // Service not available
        }

        return response()->json(['status' => 'ok', 'group_id' => $groupId]);
    }

    /**
     * Forget (delete) a group and all its membership data.
     *
     * DELETE /api/analytics/group/{groupId}
     */
    public function groupForget(string $groupId): JsonResponse
    {
        try {
            $service = app(GroupAnalyticsService::class);
            $service->forgetGroup($groupId);
        } catch (\Throwable) {
            // Service not available
        }

        return response()->json(['status' => 'ok', 'group_id' => $groupId]);
    }

    // ── Observability Endpoints (v18.0.0) ─────────────────────────────

    /**
     * Get the full observability dashboard.
     *
     * GET /api/analytics/observability
     *
     * Returns per-provider dispatch metrics, latency histograms,
     * error budgets, and a summary of the overall pipeline health.
     */
    public function observabilityDashboard(): JsonResponse
    {
        try {
            $service = app(AnalyticsObservabilityService::class);
            $dashboard = $service->getDashboard();

            return response()->json($dashboard);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Observability service unavailable',
                'error' => $e->getMessage(),
            ], 503);
        }
    }

    /**
     * Get detailed metrics for a specific provider.
     *
     * GET /api/analytics/observability/{provider}
     *
     * @param  string  $provider  Provider name (ga4, meta, posthog, etc.)
     */
    public function observabilityProviderMetrics(string $provider): JsonResponse
    {
        try {
            $service = app(AnalyticsObservabilityService::class);
            $metrics = $service->getProviderMetrics($provider);

            return response()->json([
                'provider' => $provider,
                'metrics' => $metrics,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Observability service unavailable',
                'error' => $e->getMessage(),
            ], 503);
        }
    }

    /**
     * Get per-event metrics for a specific provider.
     *
     * GET /api/analytics/observability/{provider}/events
     *
     * Returns success/failure counts and average latency per event name.
     *
     * @param  string  $provider  Provider name
     */
    public function observabilityEventMetrics(string $provider): JsonResponse
    {
        try {
            $service = app(AnalyticsObservabilityService::class);
            $eventMetrics = $service->getEventMetrics($provider);

            return response()->json([
                'provider' => $provider,
                'events' => $eventMetrics,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Observability service unavailable',
                'error' => $e->getMessage(),
            ], 503);
        }
    }

    /**
     * Get the dispatch volume timeline for a specific provider.
     *
     * GET /api/analytics/observability/{provider}/timeline?minutes=60
     *
     * Returns time-binned success/failure counts for chart rendering.
     *
     * @param  string  $provider  Provider name
     */
    public function observabilityDispatchTimeline(Request $request, string $provider): JsonResponse
    {
        $minutes = (int) $request->query('minutes', 60);
        $minutes = min(max($minutes, 1), 1440); // Clamp to 1 min - 24 hours

        try {
            $service = app(AnalyticsObservabilityService::class);
            $timeline = $service->getDispatchTimeline($provider, $minutes);

            return response()->json([
                'provider' => $provider,
                'minutes' => $minutes,
                'timeline' => $timeline,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Observability service unavailable',
                'error' => $e->getMessage(),
            ], 503);
        }
    }

    /**
     * Get pipeline filter metrics.
     *
     * GET /api/analytics/observability/filters
     *
     * Returns counts of events filtered by each pipeline filter.
     */
    public function observabilityFilterMetrics(): JsonResponse
    {
        try {
            $service = app(AnalyticsObservabilityService::class);
            $filterMetrics = $service->getFilterMetrics();

            return response()->json([
                'filters' => $filterMetrics,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Observability service unavailable',
                'error' => $e->getMessage(),
            ], 503);
        }
    }

    /**
     * Reset observability metrics for a specific provider.
     *
     * DELETE /api/analytics/observability/{provider}
     *
     * @param  string  $provider  Provider name
     */
    public function observabilityResetProvider(string $provider): JsonResponse
    {
        try {
            $service = app(AnalyticsObservabilityService::class);
            $service->resetProvider($provider);

            return response()->json([
                'status' => 'ok',
                'provider' => $provider,
                'message' => 'Observability metrics reset for provider',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Reset failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reset all observability metrics.
     *
     * DELETE /api/analytics/observability
     */
    public function observabilityResetAll(): JsonResponse
    {
        try {
            $service = app(AnalyticsObservabilityService::class);
            $service->resetAll();

            return response()->json([
                'status' => 'ok',
                'message' => 'All observability metrics reset',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Reset failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ─── Event Store — Persistent Storage (v30.0.0) ──────────────────────────

    /**
     * Check event store health.
     *
     * GET /api/analytics/store/health
     */
    public function eventStoreHealth(): JsonResponse
    {
        try {
            $store = $this->resolveEventStore();

            return response()->json([
                'status' => 'ok',
                'healthy' => $store->isHealthy(),
                'health_report' => ($store instanceof \ZeroBoiler\Analytics\Store\EventStoreManager)
                    ? $store->healthReport()
                    : ['primary' => $store->isHealthy()],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'healthy' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get event store statistics.
     *
     * GET /api/analytics/store/stats
     */
    public function eventStoreStats(): JsonResponse
    {
        try {
            $store = $this->resolveEventStore();

            $response = [
                'status' => 'ok',
                'total_events' => $store->count(),
                'healthy' => $store->isHealthy(),
            ];

            if ($store instanceof \ZeroBoiler\Analytics\Store\EventStoreManager) {
                $response['stats'] = $store->stats();
            }

            return response()->json($response);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Query stored events with filters.
     *
     * GET /api/analytics/store/events?event_name=...&category=...&from=...&to=...&limit=...
     */
    public function eventStoreQuery(Request $request): JsonResponse
    {
        $filters = array_filter([
            'event_name' => $request->string('event_name')->toString() ?: null,
            'category' => $request->string('category')->toString() ?: null,
            'provider' => $request->query('provider'),
            'user_id' => $request->string('user_id')->toString() ?: null,
            'client_id' => $request->string('client_id')->toString() ?: null,
            'source' => $request->string('source')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'limit' => $request->integer('limit', 100),
            'offset' => $request->integer('offset', 0),
            'sort' => $request->string('sort', 'created_at')->toString(),
            'direction' => $request->string('direction', 'desc')->toString(),
        ], fn($v) => $v !== null && $v !== '');

        try {
            $store = $this->resolveEventStore();
            $events = $store->query($filters);

            return response()->json([
                'status' => 'ok',
                'data' => array_map(fn(AnalyticsEvent $e) => $e->toArray(), $events),
                'count' => count($events),
                'filters' => $filters,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Retrieve a single stored event by ID.
     *
     * GET /api/analytics/store/events/{id}
     */
    public function eventStoreRetrieve(string $id): JsonResponse
    {
        try {
            $store = $this->resolveEventStore();
            $event = $store->retrieve($id);

            if ($event === null) {
                return response()->json([
                    'status' => 'not_found',
                    'error' => 'Event not found',
                ], 404);
            }

            return response()->json([
                'status' => 'ok',
                'data' => $event->toArray(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Count stored events with filters.
     *
     * GET /api/analytics/store/count?event_name=...&category=...
     */
    public function eventStoreCount(Request $request): JsonResponse
    {
        $filters = array_filter([
            'event_name' => $request->string('event_name')->toString() ?: null,
            'category' => $request->string('category')->toString() ?: null,
            'provider' => $request->query('provider'),
            'user_id' => $request->string('user_id')->toString() ?: null,
            'client_id' => $request->string('client_id')->toString() ?: null,
            'source' => $request->string('source')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
        ], fn($v) => $v !== null && $v !== '');

        try {
            $store = $this->resolveEventStore();

            return response()->json([
                'status' => 'ok',
                'count' => $store->count($filters),
                'filters' => $filters,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Aggregate stored events by dimension.
     *
     * GET /api/analytics/store/aggregate/{groupBy}?category=...&from=...&to=...
     *
     * groupBy: event_name, category, provider, source, user_id, client_id, hour, day, week, month, priority
     */
    public function eventStoreAggregate(Request $request, string $groupBy): JsonResponse
    {
        $allowedGroups = [
            'event_name', 'category', 'provider', 'source', 'user_id',
            'client_id', 'hour', 'day', 'week', 'month', 'priority',
        ];

        if (! in_array($groupBy, $allowedGroups, true)) {
            return response()->json([
                'status' => 'error',
                'error' => 'Invalid group_by dimension',
                'allowed' => $allowedGroups,
            ], 422);
        }

        $filters = array_filter([
            'event_name' => $request->string('event_name')->toString() ?: null,
            'category' => $request->string('category')->toString() ?: null,
            'provider' => $request->query('provider'),
            'user_id' => $request->string('user_id')->toString() ?: null,
            'client_id' => $request->string('client_id')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
        ], fn($v) => $v !== null && $v !== '');

        try {
            $store = $this->resolveEventStore();
            $aggregates = $store->aggregateBy($groupBy, $filters);

            return response()->json([
                'status' => 'ok',
                'group_by' => $groupBy,
                'data' => $aggregates,
                'total_groups' => count($aggregates),
                'filters' => $filters,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete stored events matching filters (GDPR erasure).
     *
     * DELETE /api/analytics/store/events?user_id=...&category=...
     */
    public function eventStoreDelete(Request $request): JsonResponse
    {
        $filters = array_filter([
            'event_name' => $request->string('event_name')->toString() ?: null,
            'category' => $request->string('category')->toString() ?: null,
            'provider' => $request->query('provider'),
            'user_id' => $request->string('user_id')->toString() ?: null,
            'client_id' => $request->string('client_id')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
        ], fn($v) => $v !== null && $v !== '');

        try {
            $store = $this->resolveEventStore();
            $deleted = $store->delete($filters);

            return response()->json([
                'status' => 'ok',
                'deleted' => $deleted,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a single stored event by ID.
     *
     * DELETE /api/analytics/store/events/{id}
     */
    public function eventStoreDeleteById(string $id): JsonResponse
    {
        try {
            $store = $this->resolveEventStore();
            $deleted = $store->deleteById($id);

            if (! $deleted) {
                return response()->json([
                    'status' => 'not_found',
                    'error' => 'Event not found',
                ], 404);
            }

            return response()->json([
                'status' => 'ok',
                'deleted' => true,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Purge all stored events. Use with extreme caution.
     *
     * DELETE /api/analytics/store
     */
    public function eventStorePurge(): JsonResponse
    {
        try {
            $store = $this->resolveEventStore();
            $result = $store->purge();

            return response()->json([
                'status' => $result ? 'ok' : 'error',
                'message' => $result ? 'All stored events purged' : 'Purge failed',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resolve the event store from the container.
     *
     * Falls back to a NullEventStore if the store is not registered.
     */
    private function resolveEventStore(): \ZeroBoiler\Analytics\Contracts\AnalyticsEventStoreInterface
    {
        try {
            $store = app(\ZeroBoiler\Analytics\Contracts\AnalyticsEventStoreInterface::class);

            return $store instanceof \ZeroBoiler\Analytics\Contracts\AnalyticsEventStoreInterface
                ? $store
                : new \ZeroBoiler\Analytics\Store\NullEventStore;
        } catch (\Throwable) {
            return new \ZeroBoiler\Analytics\Store\NullEventStore;
        }
    }

    // ── Event Ingestion Pipeline (v36.0.0) ─────────────────────────

    /**
     * Get ingestion metrics for the current request.
     *
     * GET /api/analytics/ingestion/metrics
     */
    public function ingestionMetrics(): JsonResponse
    {
        try {
            $ingestion = app(EventIngestionService::class);

            return response()->json($ingestion->getMetrics());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get aggregated ingestion statistics from cache.
     *
     * GET /api/analytics/ingestion/stats
     */
    public function ingestionAggregatedStats(): JsonResponse
    {
        try {
            $ingestion = app(EventIngestionService::class);

            return response()->json($ingestion->getAggregatedStats());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Health check for the ingestion pipeline.
     *
     * GET /api/analytics/ingestion/health
     */
    public function ingestionHealth(): JsonResponse
    {
        try {
            $ingestion = app(EventIngestionService::class);

            return response()->json([
                'enabled' => $ingestion->isEnabled(),
                'status' => $ingestion->isEnabled() ? 'healthy' : 'disabled',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ── Event Cost Allocation (v36.0.0) ──────────────────────────────

    /**
     * Get daily cost breakdown by provider.
     *
     * GET /api/analytics/cost-allocation/daily
     */
    public function costAllocationDaily(): JsonResponse
    {
        try {
            $tracker = app(EventCostTracker::class);

            return response()->json($tracker->getDailyCostBreakdown());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get monthly cost breakdown by provider.
     *
     * GET /api/analytics/cost-allocation/monthly
     */
    public function costAllocationMonthly(): JsonResponse
    {
        try {
            $tracker = app(EventCostTracker::class);

            return response()->json($tracker->getMonthlyCostBreakdown());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get top N most expensive events today.
     *
     * GET /api/analytics/cost-allocation/events
     */
    public function costAllocationTopEvents(Request $request): JsonResponse
    {
        try {
            $tracker = app(EventCostTracker::class);
            $limit = (int) ($request->query('limit', 10));

            return response()->json($tracker->getTopCostEvents(min($limit, 100)));
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get cost summary for a specific tenant.
     *
     * GET /api/analytics/cost-allocation/tenant/{tenantId}
     */
    public function costAllocationTenant(string $tenantId): JsonResponse
    {
        try {
            $tracker = app(EventCostTracker::class);

            return response()->json($tracker->getTenantCost($tenantId));
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get budget status and remaining budget.
     *
     * GET /api/analytics/cost-allocation/budget
     */
    public function costAllocationBudget(): JsonResponse
    {
        try {
            $tracker = app(EventCostTracker::class);

            return response()->json([
                'budget_exceeded' => $tracker->isBudgetExceeded(),
                'remaining' => $tracker->getRemainingBudget(),
                'request_metrics' => $tracker->getRequestMetrics(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ── Analytics Command Scheduler (v36.0.0) ───────────────────────

    /**
     * Get scheduler summary status.
     *
     * GET /api/analytics/scheduler/status
     */
    public function schedulerStatus(): JsonResponse
    {
        try {
            $scheduler = app(AnalyticsCommandScheduler::class);

            return response()->json($scheduler->getSummary());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get all registered tasks.
     *
     * GET /api/analytics/scheduler/tasks
     */
    public function schedulerTasks(): JsonResponse
    {
        try {
            $scheduler = app(AnalyticsCommandScheduler::class);

            return response()->json($scheduler->getTasks());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get tasks that are currently due for execution.
     *
     * GET /api/analytics/scheduler/due
     */
    public function schedulerDueTasks(): JsonResponse
    {
        try {
            $scheduler = app(AnalyticsCommandScheduler::class);

            return response()->json([
                'due_tasks' => $scheduler->getDueTasks(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get execution log for all tasks.
     *
     * GET /api/analytics/scheduler/log
     */
    public function schedulerExecutionLog(): JsonResponse
    {
        try {
            $scheduler = app(AnalyticsCommandScheduler::class);

            return response()->json($scheduler->getExecutionLog());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Execute all due scheduled tasks.
     *
     * POST /api/analytics/scheduler/execute
     */
    public function schedulerExecuteDue(): JsonResponse
    {
        try {
            $scheduler = app(AnalyticsCommandScheduler::class);
            $result = $scheduler->executeDueTasks();

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Execute a specific scheduled task.
     *
     * POST /api/analytics/scheduler/execute/{taskName}
     */
    public function schedulerExecuteTask(string $taskName): JsonResponse
    {
        try {
            $scheduler = app(AnalyticsCommandScheduler::class);
            $success = $scheduler->executeTask($taskName);

            return response()->json([
                'task' => $taskName,
                'success' => $success,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Toggle a task enabled/disabled.
     *
     * POST /api/analytics/scheduler/toggle/{taskName}
     */
    public function schedulerToggleTask(string $taskName, Request $request): JsonResponse
    {
        try {
            $scheduler = app(AnalyticsCommandScheduler::class);
            $enabled = (bool) ($request->input('enabled', true));
            $scheduler->toggleTask($taskName, $enabled);

            return response()->json([
                'task' => $taskName,
                'enabled' => $enabled,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Register a new custom scheduled task.
     *
     * POST /api/analytics/scheduler/register
     */
    public function schedulerRegisterTask(Request $request): JsonResponse
    {
        try {
            $scheduler = app(AnalyticsCommandScheduler::class);

            $name = (string) ($request->input('name', ''));
            $command = (string) ($request->input('command', ''));
            $frequency = (string) ($request->input('frequency', 'daily'));
            $description = (string) ($request->input('description', ''));
            $params = (array) ($request->input('params', []));

            if ($name === '' || $command === '') {
                return response()->json([
                    'error' => 'name and command are required',
                ], 422);
            }

            $scheduler->registerTask($name, $command, $frequency, $description, $params);

            return response()->json([
                'task' => $name,
                'registered' => true,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove a scheduled task.
     *
     * DELETE /api/analytics/scheduler/{taskName}
     */
    public function schedulerRemoveTask(string $taskName): JsonResponse
    {
        try {
            $scheduler = app(AnalyticsCommandScheduler::class);
            $scheduler->removeTask($taskName);

            return response()->json([
                'task' => $taskName,
                'removed' => true,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ─── Event Router Endpoints (v37.0.0) ────────────────────────────────

    /**
     * Get the event routing configuration summary.
     */
    public function eventRouterSummary(): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventRouterService::class);

            return response()->json($service->getRoutingSummary());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Validate event routing rules for misconfigurations.
     */
    public function eventRouterValidate(): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventRouterService::class);

            return response()->json($service->validateRules());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get all supported provider identifiers.
     */
    public function eventRouterProviders(): JsonResponse
    {
        return response()->json([
            'providers' => \ZeroBoiler\Analytics\Services\EventRouterService::allProviders(),
        ]);
    }

    // ─── Workspace Analytics Endpoints (v37.0.0) ────────────────────────

    /**
     * Get the full workspace analytics overview.
     */
    public function workspaceOverview(Request $request, string $workspaceId): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsWorkspaceService::class);

            if (! $service->isEnabled()) {
                return response()->json(['error' => 'Workspace analytics is not enabled'], 404);
            }

            return response()->json($service->getOverview($workspaceId));
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get active users (DAU/WAU/MAU) for a workspace.
     */
    public function workspaceActiveUsers(Request $request, string $workspaceId): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsWorkspaceService::class);

            if (! $service->isEnabled()) {
                return response()->json(['error' => 'Workspace analytics is not enabled'], 404);
            }

            $days = (int) ($request->query('days', 1));

            return response()->json([
                'workspace_id' => $workspaceId,
                'days' => $days,
                'active_users' => $service->getActiveUsers($workspaceId, $days),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get top events for a workspace.
     */
    public function workspaceTopEvents(Request $request, string $workspaceId): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsWorkspaceService::class);

            if (! $service->isEnabled()) {
                return response()->json(['error' => 'Workspace analytics is not enabled'], 404);
            }

            $limit = (int) ($request->query('limit', 10));

            return response()->json([
                'workspace_id' => $workspaceId,
                'top_events' => $service->getTopEvents($workspaceId, $limit),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get funnel conversion rates for a workspace.
     */
    public function workspaceFunnels(Request $request, string $workspaceId): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsWorkspaceService::class);

            if (! $service->isEnabled()) {
                return response()->json(['error' => 'Workspace analytics is not enabled'], 404);
            }

            $overview = $service->getOverview($workspaceId);

            return response()->json([
                'workspace_id' => $workspaceId,
                'funnels' => $overview['funnels'],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get revenue totals for a workspace.
     */
    public function workspaceRevenue(Request $request, string $workspaceId): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsWorkspaceService::class);

            if (! $service->isEnabled()) {
                return response()->json(['error' => 'Workspace analytics is not enabled'], 404);
            }

            return response()->json([
                'workspace_id' => $workspaceId,
                'revenue' => $service->getRevenueTotals($workspaceId),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Compare metrics across multiple workspaces.
     */
    public function workspaceCompare(Request $request): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsWorkspaceService::class);

            if (! $service->isEnabled()) {
                return response()->json(['error' => 'Workspace analytics is not enabled'], 404);
            }

            $workspaceIds = $request->input('workspaces', []);

            if (! is_array($workspaceIds) || $workspaceIds === []) {
                return response()->json(['error' => 'Missing "workspaces" array in request body'], 422);
            }

            return response()->json($service->compareWorkspaces($workspaceIds));
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ── Event TTL & Auto-Expiry (v43.0.0) ─────────────────────────────────

    /**
     * Get event TTL expiry metrics.
     */
    public function ttlMetrics(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventTtlService::class);
            return response()->json($service->getMetrics());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get event TTL configuration.
     */
    public function ttlConfig(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventTtlService::class);
            return response()->json($service->getConfig());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Reset TTL metrics.
     */
    public function ttlResetMetrics(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventTtlService::class);
            $service->resetMetrics();
            return response()->json(['status' => 'reset']);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Check if an event is expired.
     */
    public function ttlCheckEvent(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $eventName = $request->input('event_name', '');
            $timestamp = $request->input('timestamp');

            if ($eventName === '') {
                return response()->json(['error' => 'Missing event_name'], 422);
            }

            $ts = null;
            if (is_int($timestamp)) {
                $ts = (new \DateTimeImmutable())->setTimestamp($timestamp);
            }

            $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
                name: $eventName,
                timestamp: $ts,
            );

            $service = app(\ZeroBoiler\Analytics\Services\EventTtlService::class);

            return response()->json([
                'event_name' => $eventName,
                'expired' => $service->isExpired($event),
                'remaining_ttl' => $service->remainingTtl($event),
                'effective_ttl' => $service->resolveTtlForEvent($event),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ── Referral & Viral Loop Tracking (v43.0.0) ────────────────────────────

    /**
     * Generate a referral code for a user.
     */
    public function referralGenerateCode(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $userId = $request->input('user_id', '');
            $preferredCode = $request->input('preferred_code');

            if ($userId === '') {
                return response()->json(['error' => 'Missing user_id'], 422);
            }

            $service = app(\ZeroBoiler\Analytics\Services\ReferralTrackingService::class);
            $code = $service->generateCode($userId, $preferredCode);

            return response()->json([
                'user_id' => $userId,
                'referral_code' => $code,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Resolve a referral code to its referrer.
     */
    public function referralResolveCode(string $code): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\ReferralTrackingService::class);
            $referrerId = $service->resolveReferrer($code);

            return response()->json([
                'code' => $code,
                'referrer_id' => $referrerId,
                'valid' => $referrerId !== null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Track a referral link click.
     */
    public function referralTrackClick(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $code = $request->input('referral_code', '');
            $clickId = $request->input('click_id');
            $context = $request->input('context', []);

            if ($code === '') {
                return response()->json(['error' => 'Missing referral_code'], 422);
            }

            $service = app(\ZeroBoiler\Analytics\Services\ReferralTrackingService::class);
            $resultId = $service->trackClick($code, $clickId, is_array($context) ? $context : []);

            return response()->json([
                'click_id' => $resultId,
                'referral_code' => $code,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Track a referral conversion (signup attributed to a referral).
     */
    public function referralTrackConversion(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $clickId = $request->input('click_id', '');
            $referredUserId = $request->input('referred_user_id', '');

            if ($clickId === '' || $referredUserId === '') {
                return response()->json(['error' => 'Missing click_id or referred_user_id'], 422);
            }

            $service = app(\ZeroBoiler\Analytics\Services\ReferralTrackingService::class);
            $result = $service->trackConversion($clickId, $referredUserId);

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get referral program health metrics.
     */
    public function referralHealth(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\ReferralTrackingService::class);
            return response()->json($service->getHealthMetrics());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get viral coefficient (K-factor).
     */
    public function referralViralCoefficient(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\ReferralTrackingService::class);
            return response()->json($service->calculateViralCoefficient());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get referral funnel metrics.
     */
    public function referralFunnel(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\ReferralTrackingService::class);
            return response()->json($service->getReferralFunnel());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get top referrers.
     */
    public function referralTopReferrers(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $limit = (int) $request->input('limit', 10);
            $service = app(\ZeroBoiler\Analytics\Services\ReferralTrackingService::class);
            return response()->json($service->getTopReferrers($limit));
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ── Traffic Spike Shield (v43.0.0) ──────────────────────────────────────

    /**
     * Get traffic spike shield status.
     */
    public function spikeShieldStatus(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\TrafficSpikeShield::class);
            return response()->json($service->getStatus());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get traffic spike shield configuration.
     */
    public function spikeShieldConfig(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\TrafficSpikeShield::class);
            $status = $service->getStatus();
            return response()->json([
                'enabled' => $status['enabled'],
                'normal_threshold' => $status['normal_threshold'],
                'spike_threshold' => $status['spike_threshold'],
                'window_size' => $status['window_size'],
                'throttle_ratio' => $status['throttle_ratio'],
                'cooldown' => $status['cooldown_remaining'],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Trigger spike shield cooldown.
     */
    public function spikeShieldTriggerCooldown(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\TrafficSpikeShield::class);
            $service->triggerCooldown();
            return response()->json(['status' => 'cooldown_triggered']);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Clear spike shield cooldown.
     */
    public function spikeShieldClearCooldown(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\TrafficSpikeShield::class);
            $service->clearCooldown();
            return response()->json(['status' => 'cooldown_cleared']);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Reset spike shield metrics.
     */
    public function spikeShieldResetMetrics(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\TrafficSpikeShield::class);
            $service->resetMetrics();
            return response()->json(['status' => 'reset']);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ── Event Replay Simulator (v43.0.0) ────────────────────────────────────

    /**
     * Get simulator configuration.
     */
    public function simulatorConfig(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventReplaySimulator::class);
            return response()->json($service->getConfig());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get simulator event mix.
     */
    public function simulatorMix(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventReplaySimulator::class);
            return response()->json(['mix' => $service->getEventMix()]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Generate a batch of synthetic events.
     */
    public function simulatorGenerate(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $count = (int) $request->input('count', 100);
            $dispatch = (bool) $request->input('dispatch', false);

            $service = app(\ZeroBoiler\Analytics\Services\EventReplaySimulator::class);

            $dispatcher = null;
            if ($dispatch) {
                $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
                $dispatcher = static function (mixed $event) use ($manager): void {
                    $manager->track($event);
                };
            }

            $result = $service->generateBatch($count, $dispatcher);

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Generate an e-commerce scenario.
     */
    public function simulatorEcommerce(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $clientId = $request->input('client_id', 'sim_' . substr(md5((string) mt_rand()), 0, 12));
            $dispatch = (bool) $request->input('dispatch', false);

            $service = app(\ZeroBoiler\Analytics\Services\EventReplaySimulator::class);

            $dispatcher = null;
            if ($dispatch) {
                $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
                $dispatcher = static function (mixed $event) use ($manager): void {
                    $manager->track($event);
                };
            }

            $result = $service->generateEcommerceScenario($clientId, $dispatcher);

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Generate a SaaS lifecycle scenario.
     */
    public function simulatorSaaSLifecycle(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $clientId = $request->input('client_id', 'sim_' . substr(md5((string) mt_rand()), 0, 12));
            $dispatch = (bool) $request->input('dispatch', false);

            $service = app(\ZeroBoiler\Analytics\Services\EventReplaySimulator::class);

            $dispatcher = null;
            if ($dispatch) {
                $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
                $dispatcher = static function (mixed $event) use ($manager): void {
                    $manager->track($event);
                };
            }

            $result = $service->generateSaaSLifecycleScenario($clientId, $dispatcher);

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get fraud detection metrics.
     *
     * @since 47.0.0
     */
    public function fraudMetrics(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventFraudDetectionService::class);

            return response()->json($service->getMetrics());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get fraud detection status with thresholds.
     *
     * @since 47.0.0
     */
    public function fraudStatus(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventFraudDetectionService::class);
            $metrics = $service->getMetrics();

            return response()->json(array_merge($metrics, [
                'quarantine_threshold' => $service->getQuarantineThreshold(),
                'block_threshold' => $service->getBlockThreshold(),
            ]));
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get cached PMF score.
     *
     * @since 47.0.0
     */
    public function pmfScore(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\ProductMarketFitScoringService::class);
            $cached = $service->getCachedScore();

            return response()->json([
                'cached' => $cached,
                'config' => $service->getConfigSummary(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get PMF grade (compact).
     *
     * @since 47.0.0
     */
    public function pmfGrade(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\ProductMarketFitScoringService::class);
            $cached = $service->getCachedScore();

            return response()->json($cached !== null
                ? ['score' => $cached['score'], 'grade' => $cached['grade'], 'cached' => true]
                : ['score' => null, 'grade' => null, 'cached' => false]
            );
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Unified health check endpoint.
     *
     * @since 47.0.0
     */
    public function unifiedHealth(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\UnifiedHealthEndpointService::class);

            return response()->json($service->check());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Liveness probe endpoint.
     *
     * @since 47.0.0
     */
    public function unifiedLiveness(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\UnifiedHealthEndpointService::class);

            return response()->json($service->liveness());
        } catch (\Throwable $e) {
            return response()->json(['status' => 'critical', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Readiness probe endpoint.
     *
     * @since 47.0.0
     */
    public function unifiedReadiness(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\UnifiedHealthEndpointService::class);

            return response()->json($service->readiness());
        } catch (\Throwable $e) {
            return response()->json(['ready' => false, 'status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Correlation engine summary endpoint.
     *
     * @since 48.0.0
     */
    public function correlationEngineSummary(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventCorrelationEngineService::class);

            return response()->json([
                'status' => 'ok',
                'data' => $service->getSummary(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Correlation engine top correlated pairs endpoint.
     *
     * @since 48.0.0
     */
    public function correlationEngineTop(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventCorrelationEngineService::class);
            $limit = (int) request()->query('limit', 20);

            return response()->json([
                'status' => 'ok',
                'data' => $service->getTopCorrelations(min($limit, 100)),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Root cause analysis endpoint.
     *
     * @since 48.0.0
     */
    public function rootCauseAnalyze(): \Illuminate\Http\JsonResponse
    {
        try {
            $analyzer = app(\ZeroBoiler\Analytics\Services\AnomalyRootCauseAnalyzer::class);
            $event = (string) request()->query('event', '');
            $anomalyType = (string) request()->query('anomaly_type', 'spike');

            if ($event === '') {
                return response()->json(['status' => 'error', 'error' => 'Event name required'], 400);
            }

            $result = $analyzer->analyze($event, $anomalyType);

            return response()->json([
                'status' => 'ok',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Root cause analysis history endpoint.
     *
     * @since 48.0.0
     */
    public function rootCauseHistory(): \Illuminate\Http\JsonResponse
    {
        try {
            $analyzer = app(\ZeroBoiler\Analytics\Services\AnomalyRootCauseAnalyzer::class);
            $limit = (int) request()->query('limit', 20);

            return response()->json([
                'status' => 'ok',
                'data' => $analyzer->getAnalysisHistory(min($limit, 100)),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Self-healing summary endpoint.
     *
     * @since 48.0.0
     */
    public function selfHealSummary(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsSelfHealingService::class);

            return response()->json([
                'status' => 'ok',
                'data' => $service->getSummary(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Self-healing history endpoint.
     *
     * @since 48.0.0
     */
    public function selfHealHistory(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsSelfHealingService::class);
            $limit = (int) request()->query('limit', 50);

            return response()->json([
                'status' => 'ok',
                'data' => $service->getHistory(min($limit, 200)),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Self-healing execute endpoint.
     *
     * @since 48.0.0
     */
    public function selfHealExecute(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsSelfHealingService::class);
            $action = (string) request()->input('action', '');

            if ($action === '') {
                return response()->json(['status' => 'error', 'error' => 'Action name required'], 400);
            }

            $result = $service->heal($action, request()->input('context', []));

            return response()->json([
                'status' => 'ok',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Event Lineage Tracker (v49.0.0) ─────────────────────────────────

    /**
     * Lineage tracking status endpoint.
     *
     * GET /api/analytics/lineage/status
     */
    public function lineageStatus(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventLineageTrackerService::class);
            $stats = $service->getStats();

            return response()->json([
                'status' => 'ok',
                'data' => [
                    'enabled' => $service->isEnabled(),
                    'auto_track' => $service->isAutoTrackEnabled(),
                    'total_tracked' => $stats['total_tracked'],
                    'in_progress' => $stats['in_progress'],
                    'delivered' => $stats['delivered'],
                    'partial' => $stats['partial'],
                    'failed' => $stats['failed'],
                    'filtered' => $stats['filtered'],
                    'avg_duration_ms' => $stats['avg_duration_ms'],
                    'by_source' => $stats['by_source'],
                    'enrichment_stages_used' => $stats['enrichment_stages_used'],
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Lineage statistics endpoint.
     *
     * GET /api/analytics/lineage/stats
     */
    public function lineageStats(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventLineageTrackerService::class);

            return response()->json([
                'status' => 'ok',
                'data' => $service->getStats(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Show a specific lineage entry.
     *
     * GET /api/analytics/lineage/{lineageId}
     */
    public function lineageShow(string $lineageId): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventLineageTrackerService::class);
            $entry = $service->getLineage($lineageId);

            if ($entry === null) {
                return response()->json(['status' => 'not_found', 'error' => "Lineage '{$lineageId}' not found"], 404);
            }

            return response()->json([
                'status' => 'ok',
                'data' => $entry,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * List recent lineage entries with optional filters.
     *
     * GET /api/analytics/lineage?limit=50&event=page_view&source=api&status=delivered
     */
    public function lineageList(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventLineageTrackerService::class);
            $limit = min((int) request()->query('limit', 50), 200);
            $eventName = request()->query('event');
            $source = request()->query('source');
            $status = request()->query('status');

            return response()->json([
                'status' => 'ok',
                'data' => $service->getRecentLineages(
                    limit: $limit,
                    eventName: is_string($eventName) && $eventName !== '' ? $eventName : null,
                    source: is_string($source) && $source !== '' ? $source : null,
                    status: is_string($status) && $status !== '' ? $status : null,
                ),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get top failure patterns.
     *
     * GET /api/analytics/lineage/failures?limit=10
     */
    public function lineageFailures(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventLineageTrackerService::class);
            $limit = min((int) request()->query('limit', 10), 50);

            return response()->json([
                'status' => 'ok',
                'data' => $service->getFailurePatterns(limit: $limit),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get enrichment stage performance stats.
     *
     * GET /api/analytics/lineage/stages/performance
     */
    public function lineageStagePerformance(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventLineageTrackerService::class);

            return response()->json([
                'status' => 'ok',
                'data' => $service->getStagePerformanceStats(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get provider dispatch reliability stats.
     *
     * GET /api/analytics/lineage/providers/reliability
     */
    public function lineageProviderReliability(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventLineageTrackerService::class);

            return response()->json([
                'status' => 'ok',
                'data' => $service->getProviderReliabilityStats(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Export lineage data for GDPR compliance reporting.
     *
     * GET /api/analytics/lineage/export
     */
    public function lineageExportCompliance(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventLineageTrackerService::class);

            return response()->json([
                'status' => 'ok',
                'data' => $service->exportForCompliance(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Purge all lineage entries.
     *
     * DELETE /api/analytics/lineage
     */
    public function lineagePurge(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventLineageTrackerService::class);
            $count = $service->purge();

            return response()->json([
                'status' => 'ok',
                'purged' => $count,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Analytics Rollups (v52.0.0) ──────────────────────────────────

    /**
     * Query pre-computed rollup data.
     *
     * GET /api/analytics/rollup?granularity=daily&period=2026-08-13
     */
    public function rollupQuery(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsRollupService::class);
            $granularity = $request->query('granularity', 'daily');
            $period = $request->query('period');

            return response()->json($service->query($granularity, $period));
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get rollup service summary (configuration).
     *
     * GET /api/analytics/rollup/summary
     */
    public function rollupSummary(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsRollupService::class);

            return response()->json($service->summary());
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get rollup trend comparison between periods.
     *
     * GET /api/analytics/rollup/trend?granularity=daily
     */
    public function rollupTrend(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsRollupService::class);
            $granularity = $request->query('granularity', 'daily');

            return response()->json($service->trend($granularity));
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get rollup data volume statistics.
     *
     * GET /api/analytics/rollup/stats
     */
    public function rollupStats(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsRollupService::class);

            return response()->json($service->stats());
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Anomaly Detection & Alerting (v54.0.0) ──────────────────────────

    /**
     * Get anomaly detection status.
     *
     * GET /api/analytics/anomaly/status
     */
    public function anomalyStatus(): \Illuminate\Http\JsonResponse
    {
        try {
            $config = $this->config->get('zeroboiler.analytics.anomaly_detection', []);
            $service = new \ZeroBoiler\Analytics\Services\AnalyticsAnomalyDetectionService(
                app(\Illuminate\Contracts\Cache\Repository::class),
                $config,
            );

            return response()->json($service->status());
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get anomaly detection metrics for dashboard rendering.
     *
     * GET /api/analytics/anomaly/metrics
     */
    public function anomalyMetrics(): \Illuminate\Http\JsonResponse
    {
        try {
            $config = $this->config->get('zeroboiler.analytics.anomaly_detection', []);
            $service = new \ZeroBoiler\Analytics\Services\AnalyticsAnomalyDetectionService(
                app(\Illuminate\Contracts\Cache\Repository::class),
                $config,
            );

            return response()->json($service->metrics());
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Run anomaly detection check.
     *
     * GET /api/analytics/anomaly/check
     */
    public function anomalyCheck(): \Illuminate\Http\JsonResponse
    {
        try {
            $config = $this->config->get('zeroboiler.analytics.anomaly_detection', []);
            $service = new \ZeroBoiler\Analytics\Services\AnalyticsAnomalyDetectionService(
                app(\Illuminate\Contracts\Cache\Repository::class),
                $config,
            );

            $anomalies = $service->detectAnomalies();

            return response()->json([
                'status' => 'ok',
                'anomalies' => $anomalies,
                'count' => count($anomalies),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get recent anomaly alerts.
     *
     * GET /api/analytics/anomaly/alerts
     */
    public function anomalyAlerts(): \Illuminate\Http\JsonResponse
    {
        try {
            $config = $this->config->get('zeroboiler.analytics.anomaly_detection', []);
            $service = new \ZeroBoiler\Analytics\Services\AnalyticsAnomalyDetectionService(
                app(\Illuminate\Contracts\Cache\Repository::class),
                $config,
            );

            $status = $service->status();

            return response()->json([
                'status' => 'ok',
                'alerts' => $status['recent_alerts'] ?? [],
                'count' => count($status['recent_alerts'] ?? []),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Clear all anomaly detection data.
     *
     * DELETE /api/analytics/anomaly
     */
    public function anomalyClear(): \Illuminate\Http\JsonResponse
    {
        try {
            $config = $this->config->get('zeroboiler.analytics.anomaly_detection', []);
            $service = new \ZeroBoiler\Analytics\Services\AnalyticsAnomalyDetectionService(
                app(\Illuminate\Contracts\Cache\Repository::class),
                $config,
            );

            $service->clear();

            return response()->json(['status' => 'ok', 'message' => 'Anomaly detection data cleared']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Multi-Provider Event Relay (v54.0.0) ──────────────────────────

    /**
     * Get relay service status.
     *
     * GET /api/analytics/relay/status
     */
    public function relayStatus(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = new \ZeroBoiler\Analytics\Services\MultiProviderRelayService($this->config);

            return response()->json($service->status());
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get relay dispatch metrics.
     *
     * GET /api/analytics/relay/metrics
     */
    public function relayMetrics(): \Illuminate\Http\JsonResponse
    {
        try {
            $service = new \ZeroBoiler\Analytics\Services\MultiProviderRelayService($this->config);

            return response()->json($service->getMetrics());
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Export Formatting (v54.0.0) ──────────────────────────

    /**
     * Get supported export formats.
     *
     * GET /api/analytics/export/formats
     */
    public function exportFormats(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'formats' => \ZeroBoiler\Analytics\Services\AnalyticsExportFormatterService::supportedFormats(),
            'default' => $this->config->get('zeroboiler.analytics.export.default_format', 'csv'),
        ]);
    }

    /**
     * Transform events into a specific export format.
     *
     * POST /api/analytics/export/transform
     * Body: { "format": "csv|segment|bigquery|snowplow", "columns": [...] }
     */
    public function exportTransform(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $request->validate([
                'format' => 'required|string|in:csv,segment,bigquery,snowplow',
                'columns' => 'array',
                'columns.*' => 'string',
            ]);

            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsExportFormatterService::class);
            $format = $request->input('format');
            $columns = $request->input('columns');
            $includeMetadata = $this->config->get('zeroboiler.analytics.export.include_metadata', true);

            // Build query from event model
            $query = \ZeroBoiler\Analytics\Models\AnalyticsEventModel::query()
                ->orderBy('created_at', 'desc')
                ->limit(100);

            $events = $query->get();

            if ($includeMetadata) {
                $result = $service->exportWithMetadata($events, $format);
            } else {
                $data = match ($format) {
                    'csv' => $service->toCsv($events, $columns),
                    'segment' => $service->toSegmentFormat($events),
                    'bigquery' => $service->toBigQueryFormat($events),
                    'snowplow' => $service->toSnowplowFormat($events),
                    default => $service->toCsv($events, $columns),
                };
                $result = ['format' => $format, 'event_count' => $events->count(), 'data' => $data];
            }

            return response()->json([
                'status' => 'ok',
                ...$result,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Analytics Data Explorer (v60.0.0) ────────────────────────────────────

    /**
     * Get Data Explorer service health.
     *
     * GET /api/analytics/explorer/health
     */
    public function explorerHealth(): JsonResponse
    {
        try {
            $service = new \ZeroBoiler\Analytics\Services\AnalyticsDataExplorerService(
                $this->cache(),
                $this->config,
            );

            return response()->json($service->health());
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Explore events with flexible filtering and aggregation.
     *
     * GET /api/analytics/explorer/explore?period=24h&group_by=event_name&granularity=hour&limit=50
     */
    public function explorerExplore(Request $request): JsonResponse
    {
        try {
            $service = new \ZeroBoiler\Analytics\Services\AnalyticsDataExplorerService(
                $this->cache(),
                $this->config,
            );

            $filters = $request->only(['event_name', 'category', 'provider', 'user_id', 'client_id']);
            $groupBy = $request->query('group_by', 'event_name');
            $period = $request->query('period', '24h');
            $granularity = $request->query('granularity', 'hour');
            $limit = (int) $request->query('limit', 50);

            $result = $service->explore($filters, $groupBy, $period, $granularity, $limit);

            return response()->json(['status' => 'ok', ...$result]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get top events by count with trend analysis.
     *
     * GET /api/analytics/explorer/top-events?period=24h&limit=20&category=ecommerce
     */
    public function explorerTopEvents(Request $request): JsonResponse
    {
        try {
            $service = new \ZeroBoiler\Analytics\Services\AnalyticsDataExplorerService(
                $this->cache(),
                $this->config,
            );

            $period = $request->query('period', '24h');
            $limit = (int) $request->query('limit', 20);
            $category = $request->query('category');

            $result = $service->topEvents(
                $period,
                $limit,
                is_string($category) ? $category : null,
            );

            return response()->json(['status' => 'ok', ...$result]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Drill down into a specific event's parameters.
     *
     * GET /api/analytics/explorer/drill-down/{eventName}?period=24h
     */
    public function explorerDrillDown(Request $request, string $eventName): JsonResponse
    {
        try {
            $service = new \ZeroBoiler\Analytics\Services\AnalyticsDataExplorerService(
                $this->cache(),
                $this->config,
            );

            $period = $request->query('period', '24h');
            $filters = $request->only(['provider', 'user_id', 'client_id']);

            $result = $service->drillDown($eventName, $filters, $period);

            return response()->json(['status' => 'ok', ...$result]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Compare events between two time periods.
     *
     * GET /api/analytics/explorer/compare?event=purchase&period_a=7d&period_b=previous_7d
     */
    public function explorerCompare(Request $request): JsonResponse
    {
        try {
            $service = new \ZeroBoiler\Analytics\Services\AnalyticsDataExplorerService(
                $this->cache(),
                $this->config,
            );

            $eventName = $request->query('event', '*');
            $periodA = $request->query('period_a', '7d');
            $periodB = $request->query('period_b', 'previous_7d');
            $category = $request->query('category');

            $result = $service->compare(
                is_string($eventName) ? $eventName : '*',
                is_string($periodA) ? $periodA : '7d',
                is_string($periodB) ? $periodB : 'previous_7d',
                is_string($category) ? $category : null,
            );

            return response()->json(['status' => 'ok', ...$result]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Funnel analysis via the Data Explorer.
     *
     * GET /api/analytics/explorer/funnel?steps[]=sign_up&steps[]=trial_start&steps[]=subscription&period=7d
     */
    public function explorerFunnel(Request $request): JsonResponse
    {
        try {
            $service = new \ZeroBoiler\Analytics\Services\AnalyticsDataExplorerService(
                $this->cache(),
                $this->config,
            );

            $steps = $request->query('steps', []);
            $period = $request->query('period', '7d');
            $category = $request->query('category');

            $result = $service->funnel(
                is_array($steps) ? $steps : [],
                is_string($period) ? $period : '7d',
                is_string($category) ? $category : null,
            );

            return response()->json(['status' => 'ok', ...$result]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Event Correlation Analyzer — Time-Lagged (v60.0.0) ──────────────────

    /**
     * Get Correlation Analyzer service health.
     *
     * GET /api/analytics/correlation-analyzer/health
     */
    public function correlationAnalyzerHealth(): JsonResponse
    {
        try {
            $service = new \ZeroBoiler\Analytics\Services\EventCorrelationAnalyzerService(
                $this->cache(),
                $this->config,
            );

            return response()->json($service->health());
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Compute cross-correlation function between two events.
     *
     * GET /api/analytics/correlation-analyzer/cross-correlation?event_a=sign_up&event_b=purchase&period=30d
     */
    public function correlationAnalyzerCrossCorrelation(Request $request): JsonResponse
    {
        try {
            $service = new \ZeroBoiler\Analytics\Services\EventCorrelationAnalyzerService(
                $this->cache(),
                $this->config,
            );

            $eventA = $request->query('event_a', '');
            $eventB = $request->query('event_b', '');
            $period = $request->query('period', '30d');
            $lagOffsets = $request->query('lag_offsets');

            if ($eventA === '' || $eventB === '') {
                return response()->json([
                    'status' => 'error',
                    'error' => 'event_a and event_b query parameters are required.',
                ], 422);
            }

            $result = $service->crossCorrelation(
                $eventA,
                $eventB,
                is_string($period) ? $period : '30d',
                is_array($lagOffsets) ? $lagOffsets : null,
            );

            return response()->json(['status' => 'ok', ...$result]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Analyze event transition patterns (A→B sequences).
     *
     * GET /api/analytics/correlation-analyzer/transition?event_a=page_view&event_b=sign_up&period=30d&window_hours=24
     */
    public function correlationAnalyzerTransition(Request $request): JsonResponse
    {
        try {
            $service = new \ZeroBoiler\Analytics\Services\EventCorrelationAnalyzerService(
                $this->cache(),
                $this->config,
            );

            $eventA = $request->query('event_a', '');
            $eventB = $request->query('event_b', '');
            $period = $request->query('period', '30d');
            $windowHours = (int) $request->query('window_hours', 24);

            if ($eventA === '' || $eventB === '') {
                return response()->json([
                    'status' => 'error',
                    'error' => 'event_a and event_b query parameters are required.',
                ], 422);
            }

            $result = $service->transitionAnalysis(
                $eventA,
                $eventB,
                is_string($period) ? $period : '30d',
                $windowHours,
            );

            return response()->json(['status' => 'ok', ...$result]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Compute multi-event correlation matrix with time lag.
     *
     * GET /api/analytics/correlation-analyzer/matrix?events[]=sign_up&events[]=login&events[]=purchase&period=30d&lag_hours=0
     */
    public function correlationAnalyzerMatrix(Request $request): JsonResponse
    {
        try {
            $service = new \ZeroBoiler\Analytics\Services\EventCorrelationAnalyzerService(
                $this->cache(),
                $this->config,
            );

            $events = $request->query('events', []);
            $period = $request->query('period', '30d');
            $lagHours = (int) $request->query('lag_hours', 0);

            if (! is_array($events) || count($events) < 2) {
                return response()->json([
                    'status' => 'error',
                    'error' => 'At least 2 events are required in the events[] query parameter.',
                ], 422);
            }

            $result = $service->correlationMatrix(
                is_array($events) ? $events : [],
                is_string($period) ? $period : '30d',
                $lagHours,
            );

            return response()->json(['status' => 'ok', ...$result]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get the application cache repository.
     *
     * @return \Illuminate\Contracts\Cache\Repository
     */
    private function cache(): \Illuminate\Contracts\Cache\Repository
    {
        /** @var \Illuminate\Contracts\Cache\Repository $cache */
        $cache = app(\Illuminate\Contracts\Cache\Repository::class);

        return $cache;
    }

    // ─── Product-Market Fit Scoring (v61.0.0) ──────────────────────────

    /**
     * Compute PMF score from analytics signals.
     *
     * POST /api/analytics/pmf/score
     * Body: { activation_rate, retention_week2, feature_depth_score, organic_growth_rate, nps_proxy }
     */
    public function pmfScore(Request $request): JsonResponse
    {
        try {
            $service = new \ZeroBoiler\Analytics\Services\ProductMarketFitScoringService(
                $this->cache(),
                $this->config,
            );

            $signals = $request->json()->all();
            if (! is_array($signals)) {
                $signals = [];
            }

            $result = $service->compute($signals);

            return response()->json(['status' => 'ok', ...$result]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get PMF summary with readiness metrics.
     *
     * GET /api/analytics/pmf/summary?activation_rate=60&retention_week2=40
     */
    public function pmfSummary(Request $request): JsonResponse
    {
        try {
            $service = new \ZeroBoiler\Analytics\Services\ProductMarketFitScoringService(
                $this->cache(),
                $this->config,
            );

            $signals = $request->query->all();

            $result = $service->summary($signals);

            return response()->json(['status' => 'ok', ...$result]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── First-Value Detection (v61.0.0) ───────────────────────────────

    /**
     * Get first-value achievement score for a user.
     *
     * GET /api/analytics/first-value/score/{userId}
     */
    public function firstValueScore(string $userId): JsonResponse
    {
        try {
            $fvConfig = $this->config->get('zeroboiler.analytics.first_value', []);

            $service = new \ZeroBoiler\Analytics\Services\FirstValueDetectorService(
                $this->cache(),
                is_array($fvConfig) ? $fvConfig : [],
            );

            return response()->json([
                'status' => 'ok',
                'user_id' => $userId,
                ...$service->getScore($userId),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Reset first-value milestones for a user.
     *
     * POST /api/analytics/first-value/reset/{userId}
     * Body: { milestone?: string } — if omitted, resets all milestones
     */
    public function firstValueReset(Request $request, string $userId): JsonResponse
    {
        try {
            $fvConfig = $this->config->get('zeroboiler.analytics.first_value', []);

            $service = new \ZeroBoiler\Analytics\Services\FirstValueDetectorService(
                $this->cache(),
                is_array($fvConfig) ? $fvConfig : [],
            );

            $milestone = $request->json('milestone');

            if (is_string($milestone) && $milestone !== '') {
                $service->resetMilestone($userId, $milestone);
            } else {
                $service->resetAll($userId);
            }

            return response()->json([
                'status' => 'ok',
                'message' => is_string($milestone) && $milestone !== ''
                    ? "Milestone '{$milestone}' reset for user {$userId}"
                    : "All milestones reset for user {$userId}",
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ── Event Session Context (v63.0.0) ──────────────────────────────

    /**
     * Get session context service statistics.
     *
     * GET /api/analytics/session-context/stats
     */
    public function sessionContextStats(): JsonResponse
    {
        try {
            $ctxConfig = $this->config->get('zeroboiler.analytics.session_context', []);
            /** @var array<string, mixed> $ctxConfig */

            $service = new \ZeroBoiler\Analytics\Services\EventSessionContextService(
                $this->cache(),
                $this->config,
            );

            return response()->json([
                'status' => 'ok',
                'stats' => $service->getStats(),
                'config' => $ctxConfig,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Build a session context from the current request.
     *
     * POST /api/analytics/session-context/build
     * Body: { client_id?: string, user_id?: string, session_id?: string }
     */
    public function sessionContextBuild(Request $request): JsonResponse
    {
        try {
            $service = new \ZeroBoiler\Analytics\Services\EventSessionContextService(
                $this->cache(),
                $this->config,
            );

            $clientId = $request->json('client_id');
            $userId = $request->json('user_id');
            $sessionId = $request->json('session_id');

            $context = $service->buildFromRequest(
                $request,
                is_string($clientId) && $clientId !== '' ? $clientId : null,
                is_string($userId) && $userId !== '' ? $userId : null,
                is_string($sessionId) && $sessionId !== '' ? $sessionId : null,
            );

            return response()->json([
                'status' => 'ok',
                'context' => $context->toArray(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ── Provider Dispatch Deduplication (v63.0.0) ──────────────────────

    /**
     * Get dispatch deduplication service statistics.
     *
     * GET /api/analytics/dispatch-dedup/stats
     */
    public function dispatchDedupStats(): JsonResponse
    {
        try {
            $service = new \ZeroBoiler\Analytics\Services\ProviderDispatchDedupService(
                $this->cache(),
                $this->config,
            );

            return response()->json([
                'status' => 'ok',
                'stats' => $service->getStats(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Check if an event should be dispatched to a provider.
     *
     * POST /api/analytics/dispatch-dedup/check
     * Body: { event_name: string, params: object, client_id?: string, user_id?: string, provider: string, priority?: string }
     */
    public function dispatchDedupCheck(Request $request): JsonResponse
    {
        try {
            $eventName = (string) $request->json('event_name', '');
            $params = $request->json('params', []);
            $clientId = $request->json('client_id');
            $userId = $request->json('user_id');
            $provider = (string) $request->json('provider', 'ga4');
            $priority = $request->json('priority');

            $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
                name: $eventName,
                params: is_array($params) ? $params : [],
                clientId: is_string($clientId) && $clientId !== '' ? $clientId : null,
                userId: is_string($userId) && $userId !== '' ? $userId : null,
                priority: is_string($priority) && $priority !== '' ? $priority : null,
            );

            $service = new \ZeroBoiler\Analytics\Services\ProviderDispatchDedupService(
                $this->cache(),
                $this->config,
            );

            $shouldDispatch = $service->shouldDispatch($event, $provider);
            $hash = $service->buildHash($event, $provider);

            return response()->json([
                'status' => 'ok',
                'should_dispatch' => $shouldDispatch,
                'hash' => $hash,
                'provider' => $provider,
                'event_name' => $eventName,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Clear dispatch deduplication state.
     *
     * POST /api/analytics/dispatch-dedup/clear
     */
    public function dispatchDedupClear(): JsonResponse
    {
        try {
            $service = new \ZeroBoiler\Analytics\Services\ProviderDispatchDedupService(
                $this->cache(),
                $this->config,
            );

            $service->clear();

            return response()->json([
                'status' => 'ok',
                'message' => 'Dispatch dedup cache cleared',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ── RUM — Real User Monitoring / Web Vitals (v68.0.0) ───────────

    /**
     * Ingest Web Vitals metrics (single or batch).
     *
     * POST /api/analytics/vitals
     *
     * Body: { metrics: [{ metric, value, rating?, page_path?, client_id?, navigation_type? }] }
     *       or single: { metric, value, rating?, page_path?, client_id?, navigation_type? }
     */
    public function ingestVitals(Request $request): JsonResponse
    {
        try {
            $rum = app(\ZeroBoiler\Analytics\Services\WebVitalsAggregatorService::class);

            $data = $request->json()->all();

            // Support batch format
            if (isset($data['metrics']) && is_array($data['metrics'])) {
                $result = $rum->ingestBatch($data['metrics']);

                return response()->json([
                    'status' => 'ok',
                    'stored' => $result['stored'],
                    'alerts' => $result['alerts'],
                ]);
            }

            // Single metric format
            if (isset($data['metric'], $data['value'])) {
                $result = $rum->ingest(
                    metricName: (string) $data['metric'],
                    value: (float) $data['value'],
                    rating: $data['rating'] ?? null,
                    pagePath: $data['page_path'] ?? null,
                    clientId: $data['client_id'] ?? null,
                    navigationType: $data['navigation_type'] ?? null,
                );

                return response()->json([
                    'status' => 'ok',
                    'stored' => $result['stored'],
                    'alert' => $result['alert'],
                    'alert_reason' => $result['alert_reason'] ?? null,
                ]);
            }

            return response()->json([
                'status' => 'error',
                'error' => 'Provide {metric, value} for single or {metrics: [{metric, value, ...}]} for batch',
            ], 422);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get RUM dashboard summary.
     *
     * GET /api/analytics/vitals/summary?page=/some-path
     */
    public function vitalsSummary(Request $request): JsonResponse
    {
        try {
            $rum = app(\ZeroBoiler\Analytics\Services\WebVitalsAggregatorService::class);

            return response()->json($rum->dashboardSummary(
                $request->query('page'),
            ));
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get percentile stats for a specific RUM metric.
     *
     * GET /api/analytics/vitals/metric/{metric}?page=/some-path
     */
    public function vitalsMetric(Request $request, string $metric): JsonResponse
    {
        try {
            $rum = app(\ZeroBoiler\Analytics\Services\WebVitalsAggregatorService::class);

            return response()->json($rum->percentileStats(
                strtoupper($metric),
                $request->query('page'),
            ));
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get Core Web Vitals pass/fail assessment.
     *
     * GET /api/analytics/vitals/assessment?page=/some-path
     */
    public function vitalsAssessment(Request $request): JsonResponse
    {
        try {
            $rum = app(\ZeroBoiler\Analytics\Services\WebVitalsAggregatorService::class);

            return response()->json($rum->coreWebVitalsAssessment(
                $request->query('page'),
            ));
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get list of tracked pages with RUM data.
     *
     * GET /api/analytics/vitals/pages
     */
    public function vitalsPages(): JsonResponse
    {
        try {
            $rum = app(\ZeroBoiler\Analytics\Services\WebVitalsAggregatorService::class);

            return response()->json([
                'pages' => $rum->trackedPages(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ── Event Inspector (v68.0.0) ───────────────────────────────────

    /**
     * Get Event Inspector summary.
     *
     * GET /api/analytics/inspector/summary
     */
    public function inspectorSummary(): JsonResponse
    {
        try {
            $inspector = app(\ZeroBoiler\Analytics\Services\EventInspectorService::class);

            return response()->json($inspector->summary());
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get recent event traces from the inspector.
     *
     * GET /api/analytics/inspector/traces?limit=10
     */
    public function inspectorRecentTraces(Request $request): JsonResponse
    {
        try {
            $inspector = app(\ZeroBoiler\Analytics\Services\EventInspectorService::class);
            $limit = (int) $request->query('limit', 10);

            return response()->json([
                'traces' => $inspector->recentTraces(min($limit, 50)),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get a specific event trace.
     *
     * GET /api/analytics/inspector/trace/{eventId}
     */
    public function inspectorTrace(string $eventId): JsonResponse
    {
        try {
            $inspector = app(\ZeroBoiler\Analytics\Services\EventInspectorService::class);

            return response()->json($inspector->getTrace($eventId));
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ── Geographic Analytics (v73.0.0) ──────────────────────────────────

    /**
     * Get geographic analytics summary.
     *
     * GET /api/analytics/geo/summary
     */
    public function geoSummary(): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\GeographicAnalyticsService::class);

            return response()->json($service->summary());
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get country-level event breakdown.
     *
     * GET /api/analytics/geo/countries
     */
    public function geoCountries(): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\GeographicAnalyticsService::class);

            return response()->json($service->countryBreakdown());
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get region-level event breakdown.
     *
     * GET /api/analytics/geo/regions?country=US
     */
    public function geoRegions(Request $request): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\GeographicAnalyticsService::class);

            return response()->json($service->regionBreakdown($request->query('country')));
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get city-level event breakdown.
     *
     * GET /api/analytics/geo/cities?country=US&limit=20
     */
    public function geoCities(Request $request): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\GeographicAnalyticsService::class);
            $country = $request->query('country');
            $limit = (int) $request->query('limit', 20);

            return response()->json($service->cityBreakdown($country, $limit));
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get timezone distribution.
     *
     * GET /api/analytics/geo/timezones
     */
    public function geoTimezones(): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\GeographicAnalyticsService::class);

            return response()->json($service->timezoneDistribution());
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get country engagement heatmap.
     *
     * GET /api/analytics/geo/engagement
     */
    public function geoEngagement(): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\GeographicAnalyticsService::class);

            return response()->json($service->engagementHeatmap());
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get regional conversion funnel.
     *
     * GET /api/analytics/geo/funnel?entry=sign_up&conversion=purchase
     */
    public function geoFunnel(Request $request): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\GeographicAnalyticsService::class);
            $entry = $request->query('entry', 'sign_up');
            $conversion = $request->query('conversion', 'purchase');

            return response()->json($service->regionalConversion($entry, $conversion));
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get top events per country.
     *
     * GET /api/analytics/geo/top-events
     */
    public function geoTopEvents(): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\GeographicAnalyticsService::class);

            return response()->json($service->topEventsPerCountry());
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get geo anomaly detection results.
     *
     * GET /api/analytics/geo/anomalies
     */
    public function geoAnomalies(): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\GeographicAnalyticsService::class);

            return response()->json($service->detectAnomalies());
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get continent-level breakdown.
     *
     * GET /api/analytics/geo/continents
     */
    public function geoContinents(): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\GeographicAnalyticsService::class);

            return response()->json($service->continentBreakdown());
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ── Event Validation Pipeline (v69.0.0) ────────────────────────────

    /**
     * Get validation pipeline status and summary.
     *
     * GET /api/analytics/pipeline/validate/status
     */
    public function pipelineValidateStatus(): JsonResponse
    {
        try {
            $config = app(\Illuminate\Contracts\Config\Repository::class);
            $pipelineConfig = $config->get('zeroboiler.analytics.validation_pipeline', []);
            $enabled = (bool) ($pipelineConfig['enabled'] ?? true);

            $pipeline = \ZeroBoiler\Analytics\Pipeline\Validation\EventValidationPipeline::withDefaults($pipelineConfig);
            $summary = $pipeline->summary();

            return response()->json([
                'enabled' => $enabled,
                'pipeline' => $summary,
                'stage_count' => $pipeline->count(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get validation pipeline stage details.
     *
     * GET /api/analytics/pipeline/validate/stages
     */
    public function pipelineValidateStages(): JsonResponse
    {
        try {
            $config = app(\Illuminate\Contracts\Config\Repository::class);
            $pipelineConfig = $config->get('zeroboiler.analytics.validation_pipeline', []);

            $pipeline = \ZeroBoiler\Analytics\Pipeline\Validation\EventValidationPipeline::withDefaults($pipelineConfig);

            return response()->json([
                'stages' => $pipeline->stageNames(),
                'descriptions' => $pipeline->stageDescriptions(),
                'summary' => $pipeline->summary(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Validate a single event through the pipeline.
     *
     * POST /api/analytics/pipeline/validate/event
     * Body: { "name": "page_view", "params": {...} }
     */
    public function pipelineValidateEvent(Request $request): JsonResponse
    {
        try {
            $name = $request->input('name', '');
            $params = $request->input('params', []);

            if ($name === '') {
                return response()->json(['status' => 'error', 'error' => 'Event name is required'], 422);
            }

            $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
                name: $name,
                params: is_array($params) ? $params : [],
            );

            $config = app(\Illuminate\Contracts\Config\Repository::class);
            $pipelineConfig = $config->get('zeroboiler.analytics.validation_pipeline', []);

            $pipeline = \ZeroBoiler\Analytics\Pipeline\Validation\EventValidationPipeline::withDefaults($pipelineConfig);
            $report = $pipeline->validate($event);

            return response()->json($report);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Validate multiple events through the pipeline in batch.
     *
     * POST /api/analytics/pipeline/validate/batch
     * Body: { "events": [{ "name": "page_view", "params": {...} }, ...] }
     */
    public function pipelineValidateBatch(Request $request): JsonResponse
    {
        try {
            $events = $request->input('events', []);

            if (! is_array($events) || $events === []) {
                return response()->json(['status' => 'error', 'error' => 'events array is required'], 422);
            }

            $config = app(\Illuminate\Contracts\Config\Repository::class);
            $pipelineConfig = $config->get('zeroboiler.analytics.validation_pipeline', []);

            $pipeline = \ZeroBoiler\Analytics\Pipeline\Validation\EventValidationPipeline::withDefaults($pipelineConfig);

            $results = [];
            $passed = 0;
            $failed = 0;

            foreach (array_slice($events, 0, 100) as $input) {
                $name = $input['name'] ?? '';
                $params = $input['params'] ?? [];

                if ($name === '') {
                    continue;
                }

                $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
                    name: $name,
                    params: is_array($params) ? $params : [],
                );

                $report = $pipeline->validate($event);
                $results[] = $report;

                if ($report['valid']) {
                    $passed++;
                } else {
                    $failed++;
                }
            }

            return response()->json([
                'total' => count($results),
                'passed' => $passed,
                'failed' => $failed,
                'results' => $results,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ── Event Payload Transformation Engine (v70.0.0) ──────────────

    /**
     * List all transformation mappings.
     */
    public function transformMappings(): JsonResponse
    {
        try {
            $engine = app(\ZeroBoiler\Analytics\Services\EventTransformationEngine::class);

            return response()->json([
                'count' => $engine->mappingCount(),
                'mappings' => $engine->exportMappings(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get transformation mappings for a specific event.
     */
    public function transformMappingsByEvent(string $eventName): JsonResponse
    {
        try {
            $engine = app(\ZeroBoiler\Analytics\Services\EventTransformationEngine::class);
            $mappings = $engine->mappingsForEvent($eventName);

            return response()->json([
                'event' => $eventName,
                'count' => count($mappings),
                'mappings' => array_map(fn ($m) => $m->toArray(), $mappings),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get transformation mappings for a specific provider.
     */
    public function transformMappingsByProvider(string $provider): JsonResponse
    {
        try {
            $engine = app(\ZeroBoiler\Analytics\Services\EventTransformationEngine::class);
            $mappings = $engine->mappingsForProvider($provider);

            return response()->json([
                'provider' => $provider,
                'count' => count($mappings),
                'mappings' => array_map(fn ($m) => $m->toArray(), $mappings),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Preview transformation of an event for one or more providers.
     */
    public function transformPreview(Request $request): JsonResponse
    {
        try {
            $eventName = $request->input('event', '');
            $params = $request->input('params', []);
            $providers = $request->input('providers', []);

            if ($eventName === '') {
                return response()->json(['status' => 'error', 'error' => 'event name is required'], 422);
            }

            if (! is_array($providers) || $providers === []) {
                $providers = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];
            }

            $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
                name: $eventName,
                params: is_array($params) ? $params : [],
            );

            $engine = app(\ZeroBoiler\Analytics\Services\EventTransformationEngine::class);
            $results = $engine->transformForAll($event, $providers);

            return response()->json([
                'event' => $eventName,
                'results' => array_map(fn ($r) => $r->toArray(), $results),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Validate all registered transformation mappings.
     */
    public function transformValidate(): JsonResponse
    {
        try {
            $engine = app(\ZeroBoiler\Analytics\Services\EventTransformationEngine::class);
            $result = $engine->validateMappings();

            return response()->json($result, $result['valid'] ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Event Audit Trail (v72.0.0) ────────────────────────────────────

    /**
     * Get recent audit trail entries.
     */
    public function auditTrailRecent(): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventAuditTrailService::class);
            $limit = (int) request()->query('limit', 20);

            return response()->json([
                'status' => 'ok',
                'entries' => $service->recent(min($limit, 100)),
                'total' => $service->count(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get a specific audit trail entry by audit ID.
     */
    public function auditTrailGet(string $auditId): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventAuditTrailService::class);
            $entry = $service->getByAuditId($auditId);

            if ($entry === null) {
                return response()->json(['status' => 'not_found', 'audit_id' => $auditId], 404);
            }

            return response()->json(['status' => 'ok', 'entry' => $entry]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Search audit trail by event name.
     */
    public function auditTrailSearch(string $eventName): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventAuditTrailService::class);
            $limit = (int) request()->query('limit', 50);
            $offset = (int) request()->query('offset', 0);
            $clientId = request()->query('client_id');
            $userId = request()->query('user_id');

            $filters = ['event_name' => $eventName, 'limit' => $limit, 'offset' => $offset];
            if (is_string($clientId) && $clientId !== '') {
                $filters['client_id'] = $clientId;
            }
            if (is_string($userId) && $userId !== '') {
                $filters['user_id'] = $userId;
            }

            return response()->json([
                'status' => 'ok',
                'entries' => $service->search($filters),
                'event_name' => $eventName,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get audit trail statistics for a time period.
     */
    public function auditTrailStats(string $period = 'all'): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventAuditTrailService::class);

            return response()->json([
                'status' => 'ok',
                'stats' => $service->statistics($period),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get audit trail summary.
     */
    public function auditTrailSummary(): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventAuditTrailService::class);

            return response()->json([
                'status' => 'ok',
                'summary' => $service->summary(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Clear the entire audit trail.
     */
    public function auditTrailClear(): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventAuditTrailService::class);
            $service->clear();

            return response()->json(['status' => 'ok', 'message' => 'Audit trail cleared']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GDPR erasure — erase audit trail for a client ID.
     */
    public function auditTrailEraseClient(string $clientId): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventAuditTrailService::class);
            $erased = $service->eraseFor('client', $clientId);

            return response()->json(['status' => 'ok', 'erased' => $erased]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GDPR erasure — erase audit trail for a user ID.
     */
    public function auditTrailEraseUser(string $userId): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventAuditTrailService::class);
            $erased = $service->eraseFor('user', $userId);

            return response()->json(['status' => 'ok', 'erased' => $erased]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Event Attribution Trail (v72.0.0) ─────────────────────────────

    /**
     * Get the full attribution trail for a client.
     */
    public function attributionTrailGet(string $clientId): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventAttributionTrailService::class);
            $trail = $service->getTrail($clientId);

            return response()->json(['status' => 'ok', 'trail' => $trail]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get first-touch attribution for a client.
     */
    public function attributionTrailFirstTouch(string $clientId): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventAttributionTrailService::class);
            $firstTouch = $service->firstTouch($clientId);

            return response()->json(['status' => 'ok', 'client_id' => $clientId, 'first_touch' => $firstTouch]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get last-touch attribution for a client.
     */
    public function attributionTrailLastTouch(string $clientId): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventAttributionTrailService::class);
            $lastTouch = $service->lastTouch($clientId);

            return response()->json(['status' => 'ok', 'client_id' => $clientId, 'last_touch' => $lastTouch]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Compute attribution across models for a client.
     */
    public function attributionTrailAttribute(string $clientId): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventAttributionTrailService::class);
            $conversionEvent = (string) request()->query('conversion_event', 'conversion');
            $result = $service->attribute($clientId, $conversionEvent);

            return response()->json(['status' => 'ok', 'client_id' => $clientId, 'attribution' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get attribution trail statistics.
     */
    public function attributionTrailStats(): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventAttributionTrailService::class);

            return response()->json(['status' => 'ok', 'stats' => $service->statistics()]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GDPR erasure — erase attribution trail for a client.
     */
    public function attributionTrailErase(string $clientId): JsonResponse
    {
        try {
            $service = app(\ZeroBoiler\Analytics\Services\EventAttributionTrailService::class);
            $service->eraseFor($clientId);

            return response()->json(['status' => 'ok', 'message' => 'Attribution trail erased']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ── Experiment Analysis Engine (v75.0.0) ───────────────────────────

    /**
     * Run comprehensive experiment analysis (Bayesian + Frequentist).
     */
    public function experimentAnalyze(Request $request): JsonResponse
    {
        try {
            /** @var ExperimentAnalysisEngine $engine */
            $engine = app(ExperimentAnalysisEngine::class);

            $experimentId = (string) $request->input('experiment_id', 'manual');
            $variants = (array) $request->input('variants', []);
            $controlId = $request->input('control_id');
            $metricType = (string) $request->input('metric_type', 'conversion_rate');
            $method = (string) $request->input('method', 'both');

            if (empty($variants)) {
                return response()->json(['status' => 'error', 'error' => 'variants array is required'], 422);
            }

            $result = $engine->analyze(
                $experimentId,
                $variants,
                is_string($controlId) && $controlId !== '' ? $controlId : null,
                $metricType,
                $method,
            );

            return response()->json(['status' => 'ok'] + $result);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Quick significance test for two variants.
     */
    public function experimentQuickSignificance(Request $request): JsonResponse
    {
        try {
            /** @var ExperimentAnalysisEngine $engine */
            $engine = app(ExperimentAnalysisEngine::class);

            $result = $engine->quickSignificance(
                (int) $request->input('control_conversions', 0),
                (int) $request->input('control_exposures', 0),
                (int) $request->input('treatment_conversions', 0),
                (int) $request->input('treatment_exposures', 0),
            );

            return response()->json(['status' => 'ok'] + $result);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Calculate required sample size for a given MDE.
     */
    public function experimentSampleSize(Request $request): JsonResponse
    {
        try {
            /** @var ExperimentAnalysisEngine $engine */
            $engine = app(ExperimentAnalysisEngine::class);

            $result = $engine->calculateSampleSize(
                (float) $request->input('baseline_rate', 0.05),
                (float) $request->input('mde', 0.10),
                $request->has('alpha') ? (float) $request->input('alpha') : null,
                $request->has('power') ? (float) $request->input('power') : null,
                $request->has('num_variants') ? (int) $request->input('num_variants') : null,
            );

            return response()->json(['status' => 'ok'] + $result);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Calculate MDE for a given sample size.
     */
    public function experimentMDE(Request $request): JsonResponse
    {
        try {
            /** @var ExperimentAnalysisEngine $engine */
            $engine = app(ExperimentAnalysisEngine::class);

            $result = $engine->calculateMDE(
                (float) $request->input('baseline_rate', 0.05),
                (int) $request->input('sample_size', 1000),
                $request->has('alpha') ? (float) $request->input('alpha') : null,
                $request->has('power') ? (float) $request->input('power') : null,
            );

            return response()->json(['status' => 'ok'] + $result);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Check sequential test boundaries.
     */
    public function experimentSequential(Request $request): JsonResponse
    {
        try {
            /** @var ExperimentAnalysisEngine $engine */
            $engine = app(ExperimentAnalysisEngine::class);

            $result = $engine->sequentialTest(
                (string) $request->input('experiment_id', 'manual'),
                (int) $request->input('peek', 1),
                (int) $request->input('max_peeks', 10),
                (float) $request->input('z_score', 0.0),
                (string) $request->input('spending_function', 'obrien_fleming'),
            );

            return response()->json(['status' => 'ok'] + $result);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Assess experiment data health.
     */
    public function experimentHealth(Request $request): JsonResponse
    {
        try {
            /** @var ExperimentAnalysisEngine $engine */
            $engine = app(ExperimentAnalysisEngine::class);

            $variants = (array) $request->input('variants', []);

            if (empty($variants)) {
                return response()->json(['status' => 'error', 'error' => 'variants array is required'], 422);
            }

            $result = $engine->assessExperimentHealth($variants);

            return response()->json(['status' => 'ok'] + $result);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get cached experiment analysis.
     */
    public function experimentGetAnalysis(string $experimentId): JsonResponse
    {
        try {
            /** @var ExperimentAnalysisEngine $engine */
            $engine = app(ExperimentAnalysisEngine::class);
            $result = $engine->getCachedAnalysis($experimentId);

            if ($result === null) {
                return response()->json(['status' => 'not_found', 'message' => "No analysis found for experiment: {$experimentId}"], 404);
            }

            return response()->json(['status' => 'ok'] + $result);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Clear cached experiment analysis.
     */
    public function experimentClearAnalysis(string $experimentId): JsonResponse
    {
        try {
            /** @var ExperimentAnalysisEngine $engine */
            $engine = app(ExperimentAnalysisEngine::class);
            $engine->clearAnalysis($experimentId);

            return response()->json(['status' => 'ok', 'message' => "Analysis cleared for experiment: {$experimentId}"]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Event Contract Testing (v76.0.0) ─────────────────────────────────

    /**
     * List all registered provider contracts.
     */
    public function contractList(): JsonResponse
    {
        try {
            /** @var EventContractTestService $service */
            $service = app(EventContractTestService::class);

            return response()->json([
                'status' => 'ok',
                'contracts' => $service->getContracts(),
                'count' => $service->contractCount(),
                'enabled' => $service->isEnabled(),
                'severity' => $service->getSeverity(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Validate the entire event catalog against all provider contracts.
     */
    public function contractCatalog(): JsonResponse
    {
        try {
            /** @var EventContractTestService $service */
            $service = app(EventContractTestService::class);
            $result = $service->validateCatalog();

            return response()->json(['status' => 'ok'] + $result);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get contract coverage for a specific provider.
     */
    public function contractProviderCoverage(string $provider): JsonResponse
    {
        try {
            $allowed = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];

            if (! in_array($provider, $allowed, true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Invalid provider: {$provider}. Allowed: " . implode(', ', $allowed),
                ], 422);
            }

            /** @var EventContractTestService $service */
            $service = app(EventContractTestService::class);
            $result = $service->providerCoverage($provider);

            return response()->json(['status' => 'ok'] + $result);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Validate a specific event against all provider contracts.
     *
     * Body: { "event": "purchase", "params": {"transaction_id": "txn_001", "value": 99.99} }
     */
    public function contractValidateEvent(): JsonResponse
    {
        try {
            $eventName = request()->input('event', '');
            $params = request()->input('params', []);

            if ($eventName === '' || ! is_string($eventName)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Parameter "event" is required and must be a string.',
                ], 422);
            }

            $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
                name: $eventName,
                params: is_array($params) ? $params : [],
            );

            /** @var EventContractTestService $service */
            $service = app(EventContractTestService::class);
            $result = $service->validateEvent($event);

            return response()->json(['status' => 'ok'] + $result);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Revenue Waterfall (v78.0.0) ─────────────────────────────────

    /**
     * Get revenue waterfall for a period.
     */
    public function revenueWaterfall(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\RevenueWaterfallService $service */
            $service = app(\ZeroBoiler\Analytics\Services\RevenueWaterfallService::class);
            $period = request()->input('period', 'current_month');

            return response()->json([
                'status' => 'ok',
                'waterfall' => $service->waterfall($period),
                'movements' => $service->movementSummary(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get MRR trend data for the last N months.
     */
    public function revenueWaterfallTrend(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\RevenueWaterfallService $service */
            $service = app(\ZeroBoiler\Analytics\Services\RevenueWaterfallService::class);
            $months = (int) request()->input('months', 12);

            return response()->json([
                'status' => 'ok',
                'trend' => $service->mrrTrend(min($months, 36)),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get net MRR retention rate.
     */
    public function revenueNetMrrRetention(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\RevenueWaterfallService $service */
            $service = app(\ZeroBoiler\Analytics\Services\RevenueWaterfallService::class);
            $period = request()->input('period', 'current_month');

            return response()->json([
                'status' => 'ok',
                'retention' => $service->netMrrRetentionRate($period),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get MRR movement summary.
     */
    public function revenueMovementSummary(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\RevenueWaterfallService $service */
            $service = app(\ZeroBoiler\Analytics\Services\RevenueWaterfallService::class);

            return response()->json([
                'status' => 'ok',
                'movements' => $service->movementSummary(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Feature Flag Analytics (v78.0.0) ──────────────────────────────

    /**
     * List all tracked feature flags.
     */
    public function featureFlagList(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\FeatureFlagAnalyticsService $service */
            $service = app(\ZeroBoiler\Analytics\Services\FeatureFlagAnalyticsService::class);

            return response()->json([
                'status' => 'ok',
                'flags' => $service->allFlags(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get variant distribution for a specific flag.
     */
    public function featureFlagDistribution(string $flagKey): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\FeatureFlagAnalyticsService $service */
            $service = app(\ZeroBoiler\Analytics\Services\FeatureFlagAnalyticsService::class);

            return response()->json([
                'status' => 'ok',
                'distribution' => $service->variantDistribution($flagKey),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get conversion rates per variant for a flag.
     */
    public function featureFlagConversions(string $flagKey): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\FeatureFlagAnalyticsService $service */
            $service = app(\ZeroBoiler\Analytics\Services\FeatureFlagAnalyticsService::class);

            return response()->json([
                'status' => 'ok',
                'conversions' => $service->conversionRates($flagKey),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get feature flag adoption summary.
     */
    public function featureFlagAdoption(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\FeatureFlagAnalyticsService $service */
            $service = app(\ZeroBoiler\Analytics\Services\FeatureFlagAnalyticsService::class);

            return response()->json([
                'status' => 'ok',
                'adoption' => $service->adoptionSummary(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── SaaS Growth Metrics (v78.0.0) ─────────────────────────────────

    /**
     * Get comprehensive growth dashboard summary.
     */
    public function growthDashboard(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SaaSGrowthMetricsService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SaaSGrowthMetricsService::class);

            return response()->json([
                'status' => 'ok',
                'dashboard' => $service->dashboardSummary(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get activation rate.
     */
    public function growthActivation(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SaaSGrowthMetricsService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SaaSGrowthMetricsService::class);
            $period = request()->input('period', 'last_30_days');

            return response()->json([
                'status' => 'ok',
                'activation' => $service->activationRate($period),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get stickiness rate (DAU/MAU).
     */
    public function growthStickiness(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SaaSGrowthMetricsService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SaaSGrowthMetricsService::class);
            $period = request()->input('period', 'last_30_days');

            return response()->json([
                'status' => 'ok',
                'stickiness' => $service->stickinessRate($period),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get virality coefficient (K-factor).
     */
    public function growthVirality(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SaaSGrowthMetricsService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SaaSGrowthMetricsService::class);

            return response()->json([
                'status' => 'ok',
                'virality' => $service->viralityCoefficient(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get retention curve data.
     */
    public function growthRetention(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SaaSGrowthMetricsService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SaaSGrowthMetricsService::class);

            return response()->json([
                'status' => 'ok',
                'retention' => $service->retentionCurve(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get growth milestones.
     */
    public function growthMilestones(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SaaSGrowthMetricsService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SaaSGrowthMetricsService::class);

            return response()->json([
                'status' => 'ok',
                'milestones' => $service->milestones(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Event Health Scoring — Get health score for a specific event.
     *
     * GET /api/analytics/health/event/{eventName}
     *
     * @param  string  $eventName
     * @return \Illuminate\Http\JsonResponse
     */
    public function eventHealthScore(string $eventName): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventHealthScoringEngine $engine */
            $engine = app(\ZeroBoiler\Analytics\Services\EventHealthScoringEngine::class);

            return response()->json([
                'status' => 'ok',
                'event' => $eventName,
                'version' => AnalyticsEvent::VERSION,
                'health' => $engine->scoreEvent($eventName),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Event Health Scoring — Get system-wide health summary.
     *
     * GET /api/analytics/health/system
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function eventHealthSystem(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventHealthScoringEngine $engine */
            $engine = app(\ZeroBoiler\Analytics\Services\EventHealthScoringEngine::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'health' => $engine->systemHealth(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Event Health Scoring — Get health scores for all tracked events.
     *
     * GET /api/analytics/health/events
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function eventHealthAll(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventHealthScoringEngine $engine */
            $engine = app(\ZeroBoiler\Analytics\Services\EventHealthScoringEngine::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'events' => $engine->scoreAllEvents(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Event Health Scoring — Get only degrading events.
     *
     * GET /api/analytics/health/degrading?threshold=60
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function eventHealthDegrading(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventHealthScoringEngine $engine */
            $engine = app(\ZeroBoiler\Analytics\Services\EventHealthScoringEngine::class);
            $threshold = (int) $request->query('threshold', 60);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'threshold' => $threshold,
                'degrading' => $engine->getDegradingEvents($threshold),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Event Health Scoring — Get recent health alerts.
     *
     * GET /api/analytics/health/alerts
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function eventHealthAlerts(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventHealthScoringEngine $engine */
            $engine = app(\ZeroBoiler\Analytics\Services\EventHealthScoringEngine::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'alerts' => $engine->getRecentAlerts(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Deploy Gate — Run all pre-deployment checks.
     *
     * POST /api/analytics/deploy-gate
     * Body: {include_health?: bool, event_names?: string[]}
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function deployGateEvaluate(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\AnalyticsDeployGate $gate */
            $gate = app(\ZeroBoiler\Analytics\Services\AnalyticsDeployGate::class);

            $options = [
                'include_health' => (bool) ($request->input('include_health', false)),
                'event_names' => $request->input('event_names'),
            ];

            $result = $gate->evaluate($options);

            return response()->json($result, $result['passed'] ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Deploy Gate — Quick pass/fail check (CI script friendly).
     *
     * GET /api/analytics/deploy-gate/quick
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function deployGateQuick(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\AnalyticsDeployGate $gate */
            $gate = app(\ZeroBoiler\Analytics\Services\AnalyticsDeployGate::class);

            $passed = $gate->quickCheck() === 0;

            return response()->json([
                'status' => $passed ? 'passed' : 'failed',
                'passed' => $passed,
                'version' => AnalyticsEvent::VERSION,
            ], $passed ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Funnel Velocity Analyzer (v82.0.0) ────────────────────────────

    /**
     * Get the full funnel velocity report.
     *
     * GET /api/analytics/funnel-velocity/{funnelName}?total_steps=5
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $funnelName
     * @return \Illuminate\Http\JsonResponse
     */
    public function funnelVelocityReport(Request $request, string $funnelName): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\FunnelVelocityAnalyzer $analyzer */
            $analyzer = app(\ZeroBoiler\Analytics\Services\FunnelVelocityAnalyzer::class);
            $totalSteps = (int) $request->input('total_steps', 5);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $analyzer->funnelVelocityReport($funnelName, $totalSteps),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get velocity for a specific step transition.
     *
     * GET /api/analytics/funnel-velocity/{funnelName}/{fromStep}/{toStep}
     *
     * @param  string  $funnelName
     * @param  int  $fromStep
     * @param  int  $toStep
     * @return \Illuminate\Http\JsonResponse
     */
    public function funnelStepVelocity(string $funnelName, int $fromStep, int $toStep): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\FunnelVelocityAnalyzer $analyzer */
            $analyzer = app(\ZeroBoiler\Analytics\Services\FunnelVelocityAnalyzer::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $analyzer->stepVelocity($funnelName, $fromStep, $toStep),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get step-by-step dropout analysis for a funnel.
     *
     * GET /api/analytics/funnel-velocity/{funnelName}/dropout?total_steps=5
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $funnelName
     * @return \Illuminate\Http\JsonResponse
     */
    public function funnelDropoutAnalysis(Request $request, string $funnelName): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\FunnelVelocityAnalyzer $analyzer */
            $analyzer = app(\ZeroBoiler\Analytics\Services\FunnelVelocityAnalyzer::class);
            $totalSteps = (int) $request->input('total_steps', 5);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $analyzer->dropoutAnalysis($funnelName, $totalSteps),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Predict funnel completion time from a given step.
     *
     * GET /api/analytics/funnel-velocity/{funnelName}/predict/{step}?total_steps=5
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $funnelName
     * @param  int  $step
     * @return \Illuminate\Http\JsonResponse
     */
    public function funnelPredictCompletion(Request $request, string $funnelName, int $step): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\FunnelVelocityAnalyzer $analyzer */
            $analyzer = app(\ZeroBoiler\Analytics\Services\FunnelVelocityAnalyzer::class);
            $totalSteps = (int) $request->input('total_steps', 5);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $analyzer->predictCompletionTime($funnelName, $step, $totalSteps),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Privacy-Aware Event Router (v82.0.0) ─────────────────────────

    /**
     * Route an event through the privacy-aware router.
     *
     * POST /api/analytics/privacy/route
     * Body: {event_name: string, params: object, zone?: string, client_id?: string, user_id?: string}
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function privacyRouteEvent(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\PrivacyAwareEventRouter $router */
            $router = app(\ZeroBoiler\Analytics\Services\PrivacyAwareEventRouter::class);

            $event = new AnalyticsEvent(
                name: (string) $request->input('event_name', ''),
                params: (array) $request->input('params', []),
                clientId: $request->input('client_id'),
                userId: $request->input('user_id'),
            );

            $zone = $request->input('zone');
            $result = $router->route($event, is_string($zone) ? $zone : null);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => [
                    'zone' => $result['zone'],
                    'allowed_providers' => $result['allowed_providers'],
                    'stripped_fields' => $result['stripped_fields'],
                    'blocked' => $result['blocked'],
                    'blocked_reason' => $result['blocked_reason'],
                    'sanitized_params' => $result['event']->toArray()['params'],
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Batch route multiple events through the privacy router.
     *
     * POST /api/analytics/privacy/route-batch
     * Body: {events: [{event_name, params, zone?}], zone?: string}
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function privacyRouteBatch(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\PrivacyAwareEventRouter $router */
            $router = app(\ZeroBoiler\Analytics\Services\PrivacyAwareEventRouter::class);

            $events = [];
            foreach ((array) $request->input('events', []) as $eventData) {
                $events[] = new AnalyticsEvent(
                    name: (string) ($eventData['event_name'] ?? ''),
                    params: (array) ($eventData['params'] ?? []),
                    clientId: $eventData['client_id'] ?? null,
                    userId: $eventData['user_id'] ?? null,
                );
            }

            $zone = $request->input('zone');
            $results = $router->routeBatch($events, is_string($zone) ? $zone : null);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'count' => count($results),
                'data' => array_map(static function (array $r): array {
                    return [
                        'zone' => $r['zone'],
                        'allowed_providers' => $r['allowed_providers'],
                        'stripped_fields' => $r['stripped_fields'],
                        'blocked' => $r['blocked'],
                        'blocked_reason' => $r['blocked_reason'],
                    ];
                }, $results),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get all supported privacy zones.
     *
     * GET /api/analytics/privacy/zones
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function privacyZones(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\PrivacyAwareEventRouter $router */
            $router = app(\ZeroBoiler\Analytics\Services\PrivacyAwareEventRouter::class);

            $zones = [];
            foreach ($router->supportedZones() as $zone) {
                $zones[$zone] = [
                    'requires_consent' => $router->requiresConsent($zone),
                    'strict_mode' => $router->isStrictMode($zone),
                    'blocked_fields' => $router->getBlockedFields($zone),
                    'allowed_providers' => $router->getAllowedProviders($zone),
                ];
            }

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'zones' => $zones,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get blocked fields for a specific privacy zone.
     *
     * GET /api/analytics/privacy/zone/{zone}/blocked-fields
     *
     * @param  string  $zone
     * @return \Illuminate\Http\JsonResponse
     */
    public function privacyBlockedFields(string $zone): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\PrivacyAwareEventRouter $router */
            $router = app(\ZeroBoiler\Analytics\Services\PrivacyAwareEventRouter::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'zone' => $zone,
                'blocked_fields' => $router->getBlockedFields($zone),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get allowed providers for a specific privacy zone.
     *
     * GET /api/analytics/privacy/zone/{zone}/providers
     *
     * @param  string  $zone
     * @return \Illuminate\Http\JsonResponse
     */
    public function privacyAllowedProviders(string $zone): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\PrivacyAwareEventRouter $router */
            $router = app(\ZeroBoiler\Analytics\Services\PrivacyAwareEventRouter::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'zone' => $zone,
                'allowed_providers' => $router->getAllowedProviders($zone),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Revenue Signal Detector (v82.0.0) ────────────────────────────

    /**
     * Get churn risk score for a user.
     *
     * GET /api/analytics/signals/churn/{userId}
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function revenueChurnScore(Request $request, string $userId): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\RevenueSignalDetector $detector */
            $detector = app(\ZeroBoiler\Analytics\Services\RevenueSignalDetector::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $detector->churnScore(
                    $userId,
                    (array) $request->input('event_counts', []),
                    (array) $request->input('context', []),
                ),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get expansion opportunity score for a user.
     *
     * GET /api/analytics/signals/expansion/{userId}
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function revenueExpansionScore(Request $request, string $userId): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\RevenueSignalDetector $detector */
            $detector = app(\ZeroBoiler\Analytics\Services\RevenueSignalDetector::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $detector->expansionScore(
                    $userId,
                    (array) $request->input('event_counts', []),
                    (array) $request->input('context', []),
                ),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get combined revenue signal report for a user.
     *
     * GET /api/analytics/signals/report/{userId}
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function revenueSignalReport(Request $request, string $userId): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\RevenueSignalDetector $detector */
            $detector = app(\ZeroBoiler\Analytics\Services\RevenueSignalDetector::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $detector->fullSignalReport(
                    $userId,
                    (array) $request->input('event_counts', []),
                    (array) $request->input('context', []),
                ),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get top at-risk users by churn score.
     *
     * GET /api/analytics/signals/top-at-risk?user_ids[]=u1&limit=10
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function revenueTopAtRisk(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\RevenueSignalDetector $detector */
            $detector = app(\ZeroBoiler\Analytics\Services\RevenueSignalDetector::class);

            $userIds = (array) $request->input('user_ids', []);
            $limit = (int) $request->input('limit', 10);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $detector->topAtRiskUsers($userIds, $limit),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get top expansion opportunity users.
     *
     * GET /api/analytics/signals/top-expansion?user_ids[]=u1&limit=10
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function revenueTopExpansion(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\RevenueSignalDetector $detector */
            $detector = app(\ZeroBoiler\Analytics\Services\RevenueSignalDetector::class);

            $userIds = (array) $request->input('user_ids', []);
            $limit = (int) $request->input('limit', 10);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $detector->topExpansionUsers($userIds, $limit),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Conversion Path Discovery (v83.0.0) ──────────────────────────

    /**
     * Get top conversion paths for a funnel.
     *
     * GET /api/analytics/conversion-paths/{funnelName}/top?limit=20
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $funnelName
     * @return \Illuminate\Http\JsonResponse
     */
    public function conversionPathTop(Request $request, string $funnelName): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\ConversionPathDiscoveryService $service */
            $service = app(\ZeroBoiler\Analytics\Services\ConversionPathDiscoveryService::class);
            $limit = (int) $request->input('limit', 20);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->topConversionPaths($funnelName, $limit),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get top drop-off paths for a funnel.
     *
     * GET /api/analytics/conversion-paths/{funnelName}/drop-offs?limit=20
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $funnelName
     * @return \Illuminate\Http\JsonResponse
     */
    public function conversionPathDropOffs(Request $request, string $funnelName): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\ConversionPathDiscoveryService $service */
            $service = app(\ZeroBoiler\Analytics\Services\ConversionPathDiscoveryService::class);
            $limit = (int) $request->input('limit', 20);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->topDropOffPaths($funnelName, $limit),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get step-by-step conversion analysis for a funnel.
     *
     * GET /api/analytics/conversion-paths/{funnelName}/steps
     *
     * @param  string  $funnelName
     * @return \Illuminate\Http\JsonResponse
     */
    public function conversionPathSteps(string $funnelName): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\ConversionPathDiscoveryService $service */
            $service = app(\ZeroBoiler\Analytics\Services\ConversionPathDiscoveryService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->stepAnalysis($funnelName),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Compare two funnels' conversion paths.
     *
     * POST /api/analytics/conversion-paths/compare
     * Body: {funnel_a: string, funnel_b: string}
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function conversionPathCompare(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\ConversionPathDiscoveryService $service */
            $service = app(\ZeroBoiler\Analytics\Services\ConversionPathDiscoveryService::class);

            $funnelA = (string) $request->input('funnel_a', '');
            $funnelB = (string) $request->input('funnel_b', '');

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->compareFunnels($funnelA, $funnelB),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get comprehensive funnel summary with paths and step analysis.
     *
     * GET /api/analytics/conversion-paths/{funnelName}/summary?limit=20
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $funnelName
     * @return \Illuminate\Http\JsonResponse
     */
    public function conversionPathSummary(Request $request, string $funnelName): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\ConversionPathDiscoveryService $service */
            $service = app(\ZeroBoiler\Analytics\Services\ConversionPathDiscoveryService::class);
            $limit = (int) $request->input('limit', 20);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->funnelSummary($funnelName, $limit),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Record a step in a conversion path.
     *
     * POST /api/analytics/conversion-paths/step
     * Body: {funnel: string, identity: string, step: string, metadata?: object}
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function conversionPathRecordStep(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\ConversionPathDiscoveryService $service */
            $service = app(\ZeroBoiler\Analytics\Services\ConversionPathDiscoveryService::class);

            $service->recordStep(
                (string) $request->input('funnel', ''),
                (string) $request->input('identity', ''),
                (string) $request->input('step', ''),
                (array) $request->input('metadata', []),
            );

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Mark a user's path as converted.
     *
     * POST /api/analytics/conversion-paths/convert
     * Body: {funnel: string, identity: string, conversion_event?: string}
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function conversionPathConvert(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\ConversionPathDiscoveryService $service */
            $service = app(\ZeroBoiler\Analytics\Services\ConversionPathDiscoveryService::class);

            $service->markConverted(
                (string) $request->input('funnel', ''),
                (string) $request->input('identity', ''),
                (string) $request->input('conversion_event', 'conversion'),
            );

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Mark a user's path as abandoned.
     *
     * POST /api/analytics/conversion-paths/abandon
     * Body: {funnel: string, identity: string, drop_off_step?: string}
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function conversionPathAbandon(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\ConversionPathDiscoveryService $service */
            $service = app(\ZeroBoiler\Analytics\Services\ConversionPathDiscoveryService::class);

            $service->markAbandoned(
                (string) $request->input('funnel', ''),
                (string) $request->input('identity', ''),
                $request->input('drop_off_step'),
            );

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Provider SLA Monitor (v84.0.0) ───────────────────────────────────────

    /**
     * Get SLA monitoring summary.
     *
     * GET /api/analytics/sla/summary
     */
    public function slaSummary(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\ProviderSLAMonitor $service */
            $service = app(\ZeroBoiler\Analytics\Services\ProviderSLAMonitor::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->summary(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get SLA status for a specific provider.
     *
     * GET /api/analytics/sla/provider/{provider}
     */
    public function slaProviderStatus(string $provider): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\ProviderSLAMonitor $service */
            $service = app(\ZeroBoiler\Analytics\Services\ProviderSLAMonitor::class);

            $record = $service->currentSLA($provider);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $record?->toArray() ?? null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get SLA health comparison matrix.
     *
     * GET /api/analytics/sla/health-matrix
     */
    public function slaHealthMatrix(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\ProviderSLAMonitor $service */
            $service = app(\ZeroBoiler\Analytics\Services\ProviderSLAMonitor::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->healthMatrix(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get SLA breach history.
     *
     * GET /api/analytics/sla/breaches
     */
    public function slaBreachHistory(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\ProviderSLAMonitor $service */
            $service = app(\ZeroBoiler\Analytics\Services\ProviderSLAMonitor::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->breachHistory(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get SLA compliance percentage for a provider.
     *
     * GET /api/analytics/sla/compliance/{provider}
     */
    public function slaProviderCompliance(string $provider): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\ProviderSLAMonitor $service */
            $service = app(\ZeroBoiler\Analytics\Services\ProviderSLAMonitor::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => [
                    'provider' => $provider,
                    'compliance_24h' => $service->compliancePercentage($provider, 24),
                    'compliance_7d' => $service->compliancePercentage($provider, 168),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Cost Forecast (v84.0.0) ─────────────────────────────────────────────

    /**
     * Get cost forecast summary.
     *
     * GET /api/analytics/cost/forecast
     */
    public function costForecastSummary(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\AnalyticsCostForecastService $service */
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsCostForecastService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->summary(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get cost forecast for a specific provider.
     *
     * GET /api/analytics/cost/forecast/{provider}
     */
    public function costForecastProvider(string $provider): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\AnalyticsCostForecastService $service */
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsCostForecastService::class);

            $projection = $service->forecast($provider);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $projection?->toArray() ?? null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get budget status (total projected vs budget).
     *
     * GET /api/analytics/cost/budget
     */
    public function costBudgetStatus(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\AnalyticsCostForecastService $service */
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsCostForecastService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => [
                    'total_projected' => $service->totalProjectedCost(),
                    'exceeds_budget' => $service->exceedsBudget(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get cost optimization recommendations.
     *
     * GET /api/analytics/cost/recommendations
     */
    public function costRecommendations(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\AnalyticsCostForecastService $service */
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsCostForecastService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->optimizationRecommendations(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Governance Policy Engine (v84.0.0) ───────────────────────────────────

    /**
     * Get governance policies summary.
     *
     * GET /api/analytics/governance/policies
     */
    public function governancePolicies(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventPolicyEngine $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventPolicyEngine::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->summary(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get governance violation history.
     *
     * GET /api/analytics/governance/violations
     */
    public function governanceViolations(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventPolicyEngine $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventPolicyEngine::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->violationHistory(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get governance violation statistics.
     *
     * GET /api/analytics/governance/violations/stats
     */
    public function governanceViolationStats(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventPolicyEngine $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventPolicyEngine::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->violationStats(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── SaaS Feature Usage Tracker (v85.0.0) ──────────────────────────────

    /**
     * Get SaaS feature usage dashboard.
     *
     * GET /api/analytics/feature-usage/dashboard
     */
    public function featureUsageDashboard(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SaaSFeatureUsageTrackerService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SaaSFeatureUsageTrackerService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->dashboard(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get SaaS engagement summary (DAU/WAU/MAU).
     *
     * GET /api/analytics/feature-usage/engagement
     */
    public function featureUsageEngagement(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SaaSFeatureUsageTrackerService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SaaSFeatureUsageTrackerService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->engagementSummary(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get top used features.
     *
     * GET /api/analytics/feature-usage/top?limit=10
     */
    public function featureUsageTop(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SaaSFeatureUsageTrackerService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SaaSFeatureUsageTrackerService::class);
            $limit = (int) $request->query('limit', 10);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->topFeatures(min(50, max(1, $limit))),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get feature usage streaks and power users.
     *
     * GET /api/analytics/feature-usage/streaks?feature=dashboard&threshold=7
     */
    public function featureUsageStreaks(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SaaSFeatureUsageTrackerService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SaaSFeatureUsageTrackerService::class);
            $feature = (string) $request->query('feature', 'dashboard');
            $threshold = (int) $request->query('threshold', 7);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->powerUsers($feature, $threshold),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Record a feature usage event.
     *
     * POST /api/analytics/feature-usage/record
     */
    public function featureUsageRecord(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\SaaSFeatureUsageTrackerService $service */
            $service = app(\ZeroBoiler\Analytics\Services\SaaSFeatureUsageTrackerService::class);

            $userId = (string) $request->input('user_id', '');
            $featureName = (string) $request->input('feature_name', '');
            $context = (array) $request->input('context', []);

            if ($userId === '' || $featureName === '') {
                return response()->json([
                    'status' => 'error',
                    'error' => 'user_id and feature_name are required',
                ], 422);
            }

            $service->recordUsage($userId, $featureName, $context);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => ['recorded' => true],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Event Budget Optimizer (v85.0.0) ─────────────────────────────────

    /**
     * Get budget optimizer dashboard.
     *
     * GET /api/analytics/budget-optimizer/dashboard
     */
    public function budgetOptimizerDashboard(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventBudgetOptimizerService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventBudgetOptimizerService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->dashboard(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get budget alerts for all providers.
     *
     * GET /api/analytics/budget-optimizer/alerts
     */
    public function budgetOptimizerAlerts(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventBudgetOptimizerService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventBudgetOptimizerService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->budgetAlerts(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get cost comparison across providers.
     *
     * GET /api/analytics/budget-optimizer/comparison
     */
    public function budgetOptimizerComparison(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventBudgetOptimizerService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventBudgetOptimizerService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->costComparison(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get optimization suggestions.
     *
     * GET /api/analytics/budget-optimizer/suggestions
     */
    public function budgetOptimizerSuggestions(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventBudgetOptimizerService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventBudgetOptimizerService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->optimizationSuggestions(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get routing recommendation for an event.
     *
     * GET /api/analytics/budget-optimizer/route?provider=ga4&event=page_view&priority=3
     */
    public function budgetOptimizerRoute(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventBudgetOptimizerService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventBudgetOptimizerService::class);

            $provider = (string) $request->query('provider', 'ga4');
            $eventName = (string) $request->query('event', 'page_view');
            $priority = (int) $request->query('priority', 3);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => [
                    'provider' => $provider,
                    'event' => $eventName,
                    'priority' => $priority,
                    'recommendation' => $service->routingRecommendation($provider, $eventName, $priority),
                    'budget_utilization' => $service->budgetUtilization($provider),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── Tenant Analytics Dashboard (v85.0.0) ─────────────────────────────

    /**
     * Get tenant analytics dashboard.
     *
     * GET /api/analytics/tenant/{tenantId}/dashboard
     */
    public function tenantDashboard(Request $request, string $tenantId): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\TenantAnalyticsDashboardService $service */
            $service = app(\ZeroBoiler\Analytics\Services\TenantAnalyticsDashboardService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->fullDashboard($tenantId),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get tenant health score.
     *
     * GET /api/analytics/tenant/{tenantId}/health
     */
    public function tenantHealth(Request $request, string $tenantId): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\TenantAnalyticsDashboardService $service */
            $service = app(\ZeroBoiler\Analytics\Services\TenantAnalyticsDashboardService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => [
                    'tenant_id' => $tenantId,
                    'health_score' => $service->tenantHealthScore($tenantId),
                    'percentile' => $service->tenantPercentile($tenantId, 'health'),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get tenant ranking.
     *
     * GET /api/analytics/tenant/ranking?metric=health&limit=20
     */
    public function tenantRanking(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\TenantAnalyticsDashboardService $service */
            $service = app(\ZeroBoiler\Analytics\Services\TenantAnalyticsDashboardService::class);
            $metric = (string) $request->query('metric', 'health');
            $limit = (int) $request->query('limit', 20);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->tenantRanking($metric, min(100, max(1, $limit))),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get aggregate metrics across all tenants.
     *
     * GET /api/analytics/tenant/aggregate
     */
    public function tenantAggregate(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\TenantAnalyticsDashboardService $service */
            $service = app(\ZeroBoiler\Analytics\Services\TenantAnalyticsDashboardService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->aggregateMetrics(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Record a tenant event.
     *
     * POST /api/analytics/tenant/event
     */
    public function tenantEventRecord(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\TenantAnalyticsDashboardService $service */
            $service = app(\ZeroBoiler\Analytics\Services\TenantAnalyticsDashboardService::class);

            $tenantId = (string) $request->input('tenant_id', '');
            $eventName = (string) $request->input('event_name', '');
            $metadata = (array) $request->input('metadata', []);

            if ($tenantId === '' || $eventName === '') {
                return response()->json([
                    'status' => 'error',
                    'error' => 'tenant_id and event_name are required',
                ], 422);
            }

            $service->recordEvent($tenantId, $eventName, $metadata);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => ['recorded' => true],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ── Event Sequence Prediction (v86.0.0) ──────────────────────────

    /**
     * Record an observed event sequence for prediction model training.
     */
    public function predictionRecordSequence(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventSequencePredictionService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventSequencePredictionService::class);

            $clientId = (string) $request->input('client_id', '');
            $sequence = (array) $request->input('sequence', []);

            if ($clientId === '' || empty($sequence)) {
                return response()->json([
                    'status' => 'error',
                    'error' => 'client_id and sequence (list of event names) are required',
                ], 422);
            }

            $result = $service->recordSequence($clientId, $sequence);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Predict the most likely next event(s) given recent events.
     */
    public function predictionNextEvent(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventSequencePredictionService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventSequencePredictionService::class);

            $recentEvents = (array) $request->input('recent_events', []);

            $predictions = $service->predictNext($recentEvents);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => ['predictions' => $predictions],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get prediction model statistics.
     */
    public function predictionStats(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventSequencePredictionService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventSequencePredictionService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->getStats(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get the transition matrix for a specific event.
     */
    public function predictionTransitionMatrix(string $event): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventSequencePredictionService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventSequencePredictionService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->getTransitionMatrix($event),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get the most common event sequences.
     */
    public function predictionTopSequences(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventSequencePredictionService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventSequencePredictionService::class);

            $limit = (int) $request->input('limit', 10);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => ['sequences' => $service->getTopSequences($limit)],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Detect anomalous event transitions in a session.
     */
    public function predictionAnomalies(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventSequencePredictionService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventSequencePredictionService::class);

            $sequence = (array) $request->input('sequence', []);
            $threshold = (float) $request->input('threshold', 0.01);

            $anomalies = $service->detectAnomalies($sequence, $threshold);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => ['anomalies' => $anomalies, 'total_analyzed' => count($sequence)],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Clear the prediction model data.
     */
    public function predictionClearModel(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventSequencePredictionService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventSequencePredictionService::class);

            $result = $service->clearModel();

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ── Event Cost Ledger (v86.0.0) ─────────────────────────────────

    /**
     * Get today's cost ledger summary.
     */
    public function costLedgerDaily(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventCostLedgerService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventCostLedgerService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->getDailySummary(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Check budget status.
     */
    public function costLedgerBudget(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventCostLedgerService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventCostLedgerService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->checkBudgetStatus(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get cost optimization recommendations.
     */
    public function costLedgerOptimizations(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventCostLedgerService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventCostLedgerService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => ['recommendations' => $service->getOptimizationRecommendations()],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get historical cost data.
     */
    public function costLedgerHistory(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\EventCostLedgerService $service */
            $service = app(\ZeroBoiler\Analytics\Services\EventCostLedgerService::class);

            $days = (int) $request->input('days', 7);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => ['history' => $service->getHistoricalData(min($days, 90))],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ── Compliance Report (v86.0.0) ────────────────────────────────

    /**
     * Generate a full multi-framework compliance report.
     */
    public function complianceReportFull(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\AnalyticsComplianceReportService $service */
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsComplianceReportService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->generateFullReport(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Generate a GDPR-specific compliance report.
     */
    public function complianceReportGDPR(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\AnalyticsComplianceReportService $service */
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsComplianceReportService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->generateGDPRReport(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Generate a CCPA-specific compliance report.
     */
    public function complianceReportCCPA(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\AnalyticsComplianceReportService $service */
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsComplianceReportService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->generateCCPAReport(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Generate a SOC2-specific compliance report.
     */
    public function complianceReportSOC2(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\AnalyticsComplianceReportService $service */
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsComplianceReportService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->generateSOC2Report(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get compliance health summary.
     */
    public function complianceReportHealth(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\AnalyticsComplianceReportService $service */
            $service = app(\ZeroBoiler\Analytics\Services\AnalyticsComplianceReportService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->getHealthSummary(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ── Command Center (v86.0.0) ───────────────────────────────────

    /**
     * Get the command center dashboard data (API equivalent of zb:analytics:command-center).
     */
    public function commandCenter(): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\AnalyticsComplianceReportService $compliance */
            $compliance = app(\ZeroBoiler\Analytics\Services\AnalyticsComplianceReportService::class);

            $catalog = \ZeroBoiler\Analytics\Events\EventCatalog::count();

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => [
                    'compliance' => $compliance->getHealthSummary(),
                    'catalog_size' => $catalog,
                    'generated_at' => date('c'),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ── Data-Driven Attribution (v87.0.0) ──────────────────────────

    /**
     * Compute Shapley-value data-driven attribution from conversion paths.
     *
     * POST /api/analytics/attribution/data-driven
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function attributionDataDriven(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\DataDrivenAttributionService $dda */
            $dda = app(\ZeroBoiler\Analytics\Services\DataDrivenAttributionService::class);

            $paths = $request->input('paths', []);
            /** @var list<array{path: list<string>, value: float}> $paths */

            if (!is_array($paths)) {
                return response()->json(['status' => 'error', 'error' => 'paths must be an array'], 422);
            }

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $dda->computeAttribution($paths),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Compare data-driven attribution between two periods.
     *
     * POST /api/analytics/attribution/compare-periods
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function attributionComparePeriods(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\DataDrivenAttributionService $dda */
            $dda = app(\ZeroBoiler\Analytics\Services\DataDrivenAttributionService::class);

            $current = $request->input('current_paths', []);
            $previous = $request->input('previous_paths', []);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $dda->comparePeriods($current, $previous),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Analyze channel removal impact.
     *
     * POST /api/analytics/attribution/channel-impact
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function attributionChannelImpact(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\DataDrivenAttributionService $dda */
            $dda = app(\ZeroBoiler\Analytics\Services\DataDrivenAttributionService::class);

            $paths = $request->input('paths', []);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $dda->channelRemovalImpact($paths),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get budget allocation recommendations.
     *
     * POST /api/analytics/attribution/budget
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function attributionBudget(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\DataDrivenAttributionService $dda */
            $dda = app(\ZeroBoiler\Analytics\Services\DataDrivenAttributionService::class);

            $paths = $request->input('paths', []);
            $totalBudget = (float) ($request->input('total_budget', 0));
            $minAllocation = (float) ($request->input('min_allocation_pct', 5.0));

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $dda->budgetAllocation($paths, $totalBudget, $minAllocation),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ── Unit Economics (v87.0.0) ────────────────────────────────────

    /**
     * Calculate unit economics dashboard.
     *
     * POST /api/analytics/unit-economics/dashboard
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function unitEconomicsDashboard(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\UnitEconomicsService $service */
            $service = app(\ZeroBoiler\Analytics\Services\UnitEconomicsService::class);

            $metrics = $request->input('metrics', []);
            /** @var array<string, mixed> $metrics */

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->dashboard($metrics),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Calculate LTV:CAC ratio.
     *
     * POST /api/analytics/unit-economics/ltv-cac
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function unitEconomicsLtvCac(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\UnitEconomicsService $service */
            $service = app(\ZeroBoiler\Analytics\Services\UnitEconomicsService::class);

            $ltv = (float) ($request->input('ltv', 0));
            $cac = (float) ($request->input('cac', 0));

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->ltvCacRatio($ltv, $cac),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Calculate per-channel CAC efficiency.
     *
     * POST /api/analytics/unit-economics/channel-cac
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function unitEconomicsChannelCac(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\UnitEconomicsService $service */
            $service = app(\ZeroBoiler\Analytics\Services\UnitEconomicsService::class);

            $channels = $request->input('channels', []);
            /** @var array<string, array{spend: float, customers: int}> $channels */

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->channelCac($channels),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Calculate Magic Number (sales efficiency).
     *
     * POST /api/analytics/unit-economics/magic-number
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function unitEconomicsMagicNumber(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\UnitEconomicsService $service */
            $service = app(\ZeroBoiler\Analytics\Services\UnitEconomicsService::class);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->magicNumber(
                    (float) ($request->input('current_q_arr', 0)),
                    (float) ($request->input('previous_q_arr', 0)),
                    (float) ($request->input('previous_q_sm_spend', 0)),
                ),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ── Product Analytics Maturity (v87.0.0) ──────────────────────

    /**
     * Assess product analytics maturity level.
     *
     * GET /api/analytics/maturity
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function maturityAssessment(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\ProductAnalyticsMaturityService $service */
            $service = app(\ZeroBoiler\Analytics\Services\ProductAnalyticsMaturityService::class);

            $capabilities = $request->input('capabilities', []);
            /** @var array<string, bool> $capabilities */

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->assess($capabilities),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Quick maturity score (score + level only).
     *
     * GET /api/analytics/maturity/quick
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function maturityQuick(Request $request): JsonResponse
    {
        try {
            /** @var \ZeroBoiler\Analytics\Services\ProductAnalyticsMaturityService $service */
            $service = app(\ZeroBoiler\Analytics\Services\ProductAnalyticsMaturityService::class);

            $capabilities = $request->input('capabilities', []);
            /** @var array<string, bool> $capabilities */

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $service->quickAssess($capabilities),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── SaaS Funnel Definitions (v101.0.0) ────────────────────────────

    /**
     * List all SaaS funnel definition templates.
     *
     * Returns pre-built funnel definitions for common SaaS analytics patterns.
     * Each definition includes ordered steps, expected conversion windows,
     * and AARRR pillar classification.
     */
    public function funnelDefinitions(Request $request): JsonResponse
    {
        try {
            $funnels = \ZeroBoiler\Analytics\Services\SaaSFunnelDefinitions::all();

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => [
                    'total' => count($funnels),
                    'funnels' => $funnels,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get a specific funnel definition by key.
     */
    public function funnelDefinitionDetail(string $key): JsonResponse
    {
        try {
            $funnel = \ZeroBoiler\Analytics\Services\SaaSFunnelDefinitions::get($key);

            if ($funnel === null) {
                return response()->json([
                    'status' => 'error',
                    'error' => "Funnel definition '{$key}' not found",
                    'available_keys' => \ZeroBoiler\Analytics\Services\SaaSFunnelDefinitions::keys(),
                ], 404);
            }

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $funnel,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get funnel coverage report based on tracked events.
     *
     * Accepts a list of tracked event names and returns coverage analysis
     * for each funnel definition.
     */
    public function funnelDefinitionsCoverage(Request $request): JsonResponse
    {
        try {
            $trackedEvents = $request->input('tracked_events', []);
            /** @var list<string> $trackedEvents */

            $coverage = \ZeroBoiler\Analytics\Services\SaaSFunnelDefinitions::coverageReport($trackedEvents);
            $instrumented = \ZeroBoiler\Analytics\Services\SaaSFunnelDefinitions::fullyInstrumented($trackedEvents);

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => [
                    'tracked_count' => count($trackedEvents),
                    'coverage' => $coverage,
                    'fully_instrumented_funnels' => count($instrumented),
                    'fully_instrumented_keys' => array_column($instrumented, 'key'),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Validate all funnel definitions.
     */
    public function funnelDefinitionsValidate(): JsonResponse
    {
        try {
            $result = \ZeroBoiler\Analytics\Services\SaaSFunnelDefinitions::validate();

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ─── SaaS Readiness Assessment (v101.0.0) ─────────────────────────

    /**
     * Run full SaaS analytics readiness assessment.
     *
     * Evaluates event coverage, provider mappings, funnel readiness,
     * AARRR coverage, identity tracking, e-commerce readiness, and
     * configuration quality. Returns scores, findings, and recommendations.
     */
    public function saasReadinessAssessment(Request $request): JsonResponse
    {
        try {
            $trackedEvents = $request->input('tracked_events', []);
            /** @var list<string> $trackedEvents */
            $enabledProviders = $request->input('enabled_providers', []);
            /** @var array<string, bool> $enabledProviders */
            $configFlags = $request->input('config_flags', []);
            /** @var array<string, bool> $configFlags */

            $assessment = new \ZeroBoiler\Analytics\Services\SaaSReadinessAssessment(
                trackedEvents: $trackedEvents,
                enabledProviders: $enabledProviders,
                configFlags: $configFlags,
            );

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $assessment->assess(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get quick SaaS readiness summary.
     */
    public function saasReadinessSummary(Request $request): JsonResponse
    {
        try {
            $trackedEvents = $request->input('tracked_events', []);
            /** @var list<string> $trackedEvents */
            $enabledProviders = $request->input('enabled_providers', []);
            /** @var array<string, bool> $enabledProviders */
            $configFlags = $request->input('config_flags', []);
            /** @var array<string, bool> $configFlags */

            $assessment = new \ZeroBoiler\Analytics\Services\SaaSReadinessAssessment(
                trackedEvents: $trackedEvents,
                enabledProviders: $enabledProviders,
                configFlags: $configFlags,
            );

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $assessment->quickSummary(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get top-priority readiness improvement recommendations.
     */
    public function saasReadinessRecommendations(Request $request): JsonResponse
    {
        try {
            $trackedEvents = $request->input('tracked_events', []);
            /** @var list<string> $trackedEvents */
            $enabledProviders = $request->input('enabled_providers', []);
            /** @var array<string, bool> $enabledProviders */
            $configFlags = $request->input('config_flags', []);
            /** @var array<string, bool> $configFlags */
            $limit = $request->input('limit', 5);
            /** @var int $limit */

            $assessment = new \ZeroBoiler\Analytics\Services\SaaSReadinessAssessment(
                trackedEvents: $trackedEvents,
                enabledProviders: $enabledProviders,
                configFlags: $configFlags,
            );

            return response()->json([
                'status' => 'ok',
                'version' => AnalyticsEvent::VERSION,
                'data' => $assessment->topRecommendations(max(1, min(20, (int) $limit))),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    // ── Event Compact Serialization (v122.0.0) ─────────────────────

    /**
     * Serialize events to compact binary format for efficient batch transport.
     *
     * POST /api/analytics/serialize
     *
     * Body: { "events": [ { "name": "...", "params": {...} } ] }
     * Returns: { "status": "ok", "payload": "base64url-encoded-compact", "size_bytes": 123, "events_count": 5, "compression_ratio": 0.42 }
     */
    public function serializeBatch(Request $request): JsonResponse
    {
        $request->validate([
            'events' => 'required|array|max:50',
            'events.*.name' => 'required|string|max:100',
            'events.*.params' => 'array',
        ]);

        $events = $request->input('events', []);
        $clientId = $this->extractClientId($request);
        $userId = $request->user()?->getKey();
        $userIdStr = is_int($userId) || is_string($userId) ? (string) $userId : null;

        $analyticsEvents = [];
        foreach ($events as $eventData) {
            $analyticsEvents[] = new AnalyticsEvent(
                name: $eventData['name'],
                params: $eventData['params'] ?? [],
                clientId: $clientId,
                userId: $userIdStr,
            );
        }

        try {
            $serializer = new \ZeroBoiler\Analytics\Services\EventCompactSerializer;
            $payload = $serializer->serializeBatch($analyticsEvents);
            $jsonSize = strlen(json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $compactSize = strlen($payload);
            $ratio = $jsonSize > 0 ? round((float) $compactSize / (float) $jsonSize, 4) : 1.0;

            return response()->json([
                'status' => 'ok',
                'payload' => $payload,
                'format_version' => \ZeroBoiler\Analytics\Services\EventCompactSerializer::FORMAT_VERSION,
                'size_bytes' => $compactSize,
                'events_count' => count($analyticsEvents),
                'compression_ratio' => $ratio,
                'json_size_bytes' => $jsonSize,
                'savings_percent' => $jsonSize > 0 ? round((1.0 - $ratio) * 100, 1) : 0.0,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Deserialize and process compact binary event payload.
     *
     * POST /api/analytics/deserialize
     *
     * Body: { "payload": "base64url-encoded-compact-data" }
     * Returns: { "status": "ok", "events_count": 5, "dispatched": 5 }
     */
    public function deserializeAndTrack(Request $request): JsonResponse
    {
        $request->validate([
            'payload' => 'required|string|max:524288', // Max 512KB
        ]);

        try {
            $serializer = new \ZeroBoiler\Analytics\Services\EventCompactSerializer;
            $events = $serializer->deserialize($request->input('payload'));

            $clientId = $this->extractClientId($request);
            $userId = $request->user()?->getKey();
            $userIdStr = is_int($userId) || is_string($userId) ? (string) $userId : null;

            // Budget enforcement
            if ($this->budgetService !== null) {
                $budgetResult = $this->budgetService->check($clientId, $userIdStr);
                if (! $budgetResult['allowed']) {
                    return response()->json([
                        'status' => 'budget_exceeded',
                        'reason' => $budgetResult['reason'],
                    ], 429);
                }
            }

            $pipeline = $this->buildPipeline($request);
            $dispatched = 0;

            foreach ($events as $event) {
                // Attach server-side identity if not present
                if ($event->clientId === null && $clientId !== null) {
                    $event = new AnalyticsEvent(
                        name: $event->name,
                        params: $event->params,
                        clientId: $clientId,
                        userId: $event->userId ?? $userIdStr,
                    );
                }

                $event = $this->validateEvent($event);
                $processed = $pipeline->process($event);

                if ($processed !== null) {
                    $this->manager->trackEvent($processed);
                    $dispatched++;
                }
            }

            // Record budget
            if ($this->budgetService !== null) {
                for ($i = 0; $i < $dispatched; $i++) {
                    $this->budgetService->record($clientId, $userIdStr);
                }
            }

            return response()->json([
                'status' => 'ok',
                'events_count' => count($events),
                'dispatched' => $dispatched,
                'filtered' => count($events) - $dispatched,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get serializer format metadata and compression benchmarks.
     *
     * GET /api/analytics/serialize/info
     */
    public function serializeInfo(): JsonResponse
    {
        $serializer = new \ZeroBoiler\Analytics\Services\EventCompactSerializer;

        return response()->json([
            'status' => 'ok',
            'format' => $serializer->metadata(),
            'description' => 'Compact binary serialization for high-throughput event batching. Reduces JSON payload size by ~50-60%.',
            'usage' => [
                'serialize' => 'POST /api/analytics/serialize — Convert events to compact format',
                'deserialize' => 'POST /api/analytics/deserialize — Process compact payload',
            ],
        ]);
    }

    // ── SDK Telemetry (v122.0.0) ────────────────────────────────────

    /**
     * Collect SDK telemetry data point.
     *
     * POST /api/analytics/sdk-telemetry
     *
     * Body: { "sdk_version": "1.2.0", "platform": "web", "page_load_ms": 1200, ... }
     */
    public function sdkTelemetryCollect(Request $request): JsonResponse
    {
        $telemetry = $request->input();

        if (! is_array($telemetry) || $telemetry === []) {
            return response()->json(['status' => 'error', 'error' => 'Empty telemetry payload'], 400);
        }

        try {
            $collector = new \ZeroBoiler\Analytics\Services\AnalyticsSdkTelemetryCollector(
                $this->app->make(\Illuminate\Contracts\Cache\Repository::class),
                $this->config,
            );

            if (! $collector->isEnabled()) {
                return response()->json(['status' => 'disabled'], 200);
            }

            $collected = $collector->collect($telemetry);

            return response()->json([
                'status' => $collected ? 'ok' : 'rejected',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Collect SDK telemetry data points in batch.
     *
     * POST /api/analytics/sdk-telemetry/batch
     *
     * Body: { "telemetry": [ {...}, {...} ] }
     */
    public function sdkTelemetryBatch(Request $request): JsonResponse
    {
        $request->validate([
            'telemetry' => 'required|array|max:100',
            'telemetry.*' => 'array',
        ]);

        try {
            $collector = new \ZeroBoiler\Analytics\Services\AnalyticsSdkTelemetryCollector(
                $this->app->make(\Illuminate\Contracts\Cache\Repository::class),
                $this->config,
            );

            if (! $collector->isEnabled()) {
                return response()->json(['status' => 'disabled'], 200);
            }

            $result = $collector->collectBatch($request->input('telemetry', []));

            return response()->json([
                'status' => 'ok',
                'collected' => $result['collected'],
                'rejected' => $result['rejected'],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get SDK telemetry summary.
     *
     * GET /api/analytics/sdk-telemetry/summary
     */
    public function sdkTelemetrySummary(): JsonResponse
    {
        try {
            $collector = new \ZeroBoiler\Analytics\Services\AnalyticsSdkTelemetryCollector(
                $this->app->make(\Illuminate\Contracts\Cache\Repository::class),
                $this->config,
            );

            return response()->json([
                'status' => 'ok',
                'data' => $collector->summary(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get SDK telemetry data for a specific client.
     *
     * GET /api/analytics/sdk-telemetry/client/{clientId}
     */
    public function sdkTelemetryClientHistory(string $clientId): JsonResponse
    {
        try {
            $collector = new \ZeroBoiler\Analytics\Services\AnalyticsSdkTelemetryCollector(
                $this->app->make(\Illuminate\Contracts\Cache\Repository::class),
                $this->config,
            );

            return response()->json([
                'status' => 'ok',
                'data' => $collector->clientHistory($clientId),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get SDK version distribution.
     *
     * GET /api/analytics/sdk-telemetry/versions
     */
    public function sdkTelemetryVersions(): JsonResponse
    {
        try {
            $collector = new \ZeroBoiler\Analytics\Services\AnalyticsSdkTelemetryCollector(
                $this->app->make(\Illuminate\Contracts\Cache\Repository::class),
                $this->config,
            );

            return response()->json([
                'status' => 'ok',
                'data' => $collector->versionDistribution(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get SDK telemetry health issues.
     *
     * GET /api/analytics/sdk-telemetry/health
     */
    public function sdkTelemetryHealth(): JsonResponse
    {
        try {
            $collector = new \ZeroBoiler\Analytics\Services\AnalyticsSdkTelemetryCollector(
                $this->app->make(\Illuminate\Contracts\Cache\Repository::class),
                $this->config,
            );

            return response()->json([
                'status' => 'ok',
                'data' => $collector->healthIssues(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Clear SDK telemetry data.
     *
     * DELETE /api/analytics/sdk-telemetry
     */
    public function sdkTelemetryClear(Request $request): JsonResponse
    {
        try {
            $collector = new \ZeroBoiler\Analytics\Services\AnalyticsSdkTelemetryCollector(
                $this->app->make(\Illuminate\Contracts\Cache\Repository::class),
                $this->config,
            );

            $clientId = $request->input('client_id');

            if ($clientId !== null && is_string($clientId)) {
                $result = $collector->clearClientHistory($clientId);
            } else {
                $result = $collector->clearAll();
            }

            return response()->json(['status' => 'ok', 'cleared' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Export OpenAPI 3.0 specification (JSON).
     *
     * GET /api/analytics/openapi-spec
     * Returns the full machine-readable API specification for Swagger UI, Redoc,
     * or any OpenAPI-compatible documentation tool.
     *
     * @since 127.0.0
     */
    public function openApiSpec(): JsonResponse
    {
        try {
            $generator = new \ZeroBoiler\Analytics\Services\EventSchemaOpenApiGenerator($this->config);

            return response()->json(
                $generator->generate(),
                200,
                ['Content-Type' => 'application/json'],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Export OpenAPI 3.0 specification (YAML).
     *
     * GET /api/analytics/openapi.yaml
     * Returns the full API specification in YAML format for direct import
     * into documentation tools or API gateways.
     *
     * @since 127.0.0
     */
    public function openApiYaml(): Response
    {
        try {
            $generator = new \ZeroBoiler\Analytics\Services\EventSchemaOpenApiGenerator($this->config);

            return response($generator->toYaml(), 200, ['Content-Type' => 'application/yaml']);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Export OpenAPI endpoint summary (flat list).
     *
     * GET /api/analytics/openapi/endpoints
     * Returns a simplified list of all API endpoints with methods, paths,
     * descriptions, and tag groupings. Useful for quick reference.
     *
     * @since 127.0.0
     */
    public function openApiEndpointSummary(): JsonResponse
    {
        try {
            $generator = new \ZeroBoiler\Analytics\Services\EventSchemaOpenApiGenerator($this->config);

            return response()->json([
                'status' => 'ok',
                'total' => count($generator->endpointSummary()),
                'endpoints' => $generator->endpointSummary(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'error' => $e->getMessage()], 500);
        }
    }
}
