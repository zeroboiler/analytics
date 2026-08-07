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

/**
 * API controller for frontend event tracking.
 *
 * Receives events from the JS client library and dispatches them
 * through the analytics pipeline to all configured providers.
 */
final class AnalyticsEventController extends Controller
{
    private AnalyticsManager $manager;

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
    ): void {
        $this->manager = $manager;
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
        return response()->json([
            'status' => 'ok',
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
                'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
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
            'version' => '2.45.0',
            'message' => 'Attribution data cleared',
        ]);
    }
}
