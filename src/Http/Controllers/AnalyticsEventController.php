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

/**
 * API controller for frontend event tracking.
 *
 * Receives events from the JS client library and dispatches them
 * through the analytics pipeline to all configured providers.
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
                'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
                'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
                'version' => '2.86.0',
                'source' => 'cached',
                ...$cached,
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
                'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
                'version' => '2.86.0',
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
                'version' => '2.86.0',
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
                'version' => '2.86.0',
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
                'version' => '2.86.0',
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
                'version' => '2.86.0',
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
                'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.86.0',
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
            'version' => '2.87.0',
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
            'version' => '2.87.0',
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
            'version' => '2.87.0',
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
            'version' => '2.87.0',
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
            'version' => '2.87.0',
            'metrics' => $service->quickStartMetrics(),
            'summary' => $service->summary(),
        ]);
    }
}
