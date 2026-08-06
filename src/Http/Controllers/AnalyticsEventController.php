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
use ZeroBoiler\Analytics\Services\FunnelDataBuilderService;

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
    ) {
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
            'version' => '2.24.0',
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
            'version' => '2.24.0',
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
            'version' => '2.24.0',
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
}
