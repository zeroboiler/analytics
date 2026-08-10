<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Http\Controllers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    public function lifecycle(): JsonResponse
    {
        if ($this->lifecycleMapper === null) {
            return response()->json(['error' => 'Lifecycle mapper not available'], 503);
        }

        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'mapper' => $this->lifecycleMapper->summary(),
            'mappings' => $this->lifecycleMapper->getMappings(),
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

        $metrics = $this->gatherGuardRailsMetrics($request);

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

        $metrics = $this->gatherGuardRailsMetrics($request);

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
        $metrics = $this->gatherGuardRailsMetrics($request);

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

        $metrics = $this->gatherGuardRailsMetrics($request);
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
    private function gatherGuardRailsMetrics(Request $request): array
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
}
