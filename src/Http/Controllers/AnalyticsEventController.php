<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Http\Controllers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventValidationService;

/**
 * API controller for frontend event tracking.
 *
 * Receives events from the JS client library and dispatches them
 * through the analytics pipeline to all configured providers.
 */
class AnalyticsEventController extends Controller
{
    private AnalyticsManager $manager;

    private string $cookieName;

    private ?EventValidationService $validator;

    private const MAX_BATCH_SIZE = 25;

    private const MAX_EVENT_NAME_LENGTH = 100;

    /**
     * @param  AnalyticsManager  $manager
     * @param  ConfigRepository  $config
     * @param  EventValidationService|null  $validator  Optional event validator (injected when available)
     */
    public function __construct(AnalyticsManager $manager, ConfigRepository $config, ?EventValidationService $validator = null)
    {
        $this->manager = $manager;
        $cookieName = $config->get('zeroboiler.analytics.identity.cookie_name', 'zb_analytics_id');
        $this->cookieName = is_string($cookieName) ? $cookieName : 'zb_analytics_id';
        $this->validator = $validator;
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

        $this->manager->trackEvent($event);

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

        foreach ($events as $eventData) {
            $event = new AnalyticsEvent(
                name: $eventData['name'],
                params: $eventData['params'] ?? [],
                clientId: $clientId,
                userId: $userIdStr,
            );

            // Validate and sanitize each event
            $event = $this->validateEvent($event);

            $this->manager->trackEvent($event);
        }

        return response()->json([
            'status' => 'ok',
            'count' => count((array) $events),
        ]);
    }

    /**
     * Link a client ID to an authenticated user.
     *
     * POST /api/analytics/identify
     *
     * Body: { "client_id": "uuid-..." }
     */
    public function identify(Request $request): JsonResponse
    {
        $request->validate([
            'client_id' => 'required|string',
        ]);

        $clientId = is_string($request->input('client_id')) ? $request->input('client_id') : null;
        $user = $request->user();

        if ($user === null) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $userId = $user->getKey();
        $userIdStr = is_int($userId) || is_string($userId) ? (string) $userId : null;

        // Send identify event to all providers
        $event = new AnalyticsEvent(
            name: 'identify',
            params: [
                'user_id' => $userIdStr,
                'client_id' => $clientId,
            ],
            clientId: $clientId,
            userId: $userIdStr,
        );

        $this->manager->trackEvent($event);

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

        return response()->json([
            'status' => 'ok',
            'version' => '1.2.0',
            'providers' => $providers,
            'consent' => $this->manager->getConsent()->toArray(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Validate the event name format.
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
}
