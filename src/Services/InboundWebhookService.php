<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Support\WebhookSignatureValidator;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * Inbound webhook receiver for external analytics events.
 *
 * Accepts events from external sources (Stripe webhooks, payment processors,
 * custom integrations) and dispatches them through the analytics pipeline.
 * Supports HMAC-SHA256 signature verification for secure ingestion.
 *
 * Typical use case: receiving payment events from Stripe, subscription events
 * from billing systems, or custom events from partner integrations.
 *
 * @since 1.0.0
 */
final class InboundWebhookService
{
    private AnalyticsManager $manager;

    private ConfigRepository $config;

    private bool $enabled;

    private string $secret;

    private bool $requireSignature;

    private int $maxPayloadSize;

    private int $maxEventsPerPayload;

    /**
     * @param  AnalyticsManager  $manager
     * @param  ConfigRepository  $config
     */
    public function __construct(AnalyticsManager $manager, ConfigRepository $config){
        $this->manager = $manager;
        $this->config = $config;

        $inboundConfig = $config->get('zeroboiler.analytics.inbound_webhook', []);
        /** @var array{enabled?: bool, secret?: string, require_signature?: bool, max_payload_size?: int, max_events?: int} $inboundConfig */
        $this->enabled = (bool) ($inboundConfig['enabled'] ?? false);
        $this->secret = (string) ($inboundConfig['secret'] ?? '');
        $this->requireSignature = (bool) ($inboundConfig['require_signature'] ?? true);
        $this->maxPayloadSize = (int) ($inboundConfig['max_payload_size'] ?? 65536); // 64KB
        $this->maxEventsPerPayload = (int) ($inboundConfig['max_events'] ?? 50);
    }

    /**
     * Process an inbound webhook payload.
     *
     * Accepts either a single event or a batch of events.
     * Validates the signature if required, then dispatches through
     * the analytics manager.
     *
     * Expected payload format (single):
     * ```json
     * {
     *   "event": "payment.completed",
     *   "params": { "amount": 99.99, "currency": "USD" }
     * }
     * ```
     *
     * Expected payload format (batch):
     * ```json
     * {
     *   "events": [
     *     { "event": "payment.completed", "params": {...} },
     *     { "event": "subscription.created", "params": {...} }
     *   ]
     * }
     * ```
     *
     * @param  string  $payload  Raw JSON payload
     * @param  string|null  $signature  HMAC-SHA256 signature from X-ZB-Signature or X-Hub-Signature-256 header
     * @return array{status: string, dispatched: int, errors: list<string>}
     */
    public function receive(string $payload, ?string $signature = null): array
    {
        if (! $this->enabled) {
            return [
                'status' => 'disabled',
                'dispatched' => 0,
                'errors' => ['Inbound webhook is disabled'],
            ];
        }

        if (strlen($payload) > $this->maxPayloadSize) {
            return [
                'status' => 'error',
                'dispatched' => 0,
                'errors' => ['Payload exceeds maximum size'],
            ];
        }

        if ($this->requireSignature) {
            if ($signature === null || $signature === '') {
                return [
                    'status' => 'error',
                    'dispatched' => 0,
                    'errors' => ['Missing signature header'],
                ];
            }

            if (! WebhookSignatureValidator::valid($payload, $signature, $this->secret)) {
                return [
                    'status' => 'error',
                    'dispatched' => 0,
                    'errors' => ['Invalid signature'],
                ];
            }
        }

        $data = json_decode($payload, true);

        if (! is_array($data)) {
            return [
                'status' => 'error',
                'dispatched' => 0,
                'errors' => ['Invalid JSON payload'],
            ];
        }

        if (isset($data['events']) && is_array($data['events'])) {
            return $this->receiveBatch($data['events']);
        }

        if (isset($data['event']) && is_string($data['event'])) {
            return $this->receiveSingle($data);
        }

        return [
            'status' => 'error',
            'dispatched' => 0,
            'errors' => ['Payload must contain "event" or "events" key'],
        ];
    }

    /**
     * Receive and dispatch a single inbound event.
     *
     * @param  array{event: string, params?: array<string, mixed>, user_id?: string, client_id?: string}  $data
     * @return array{status: string, dispatched: int, errors: list<string>}
     */
    private function receiveSingle(array $data): array
    {
        $eventName = $data['event'];
        $params = (array) ($data['params'] ?? []);
        $userId = isset($data['user_id']) && (is_string($data['user_id']) || is_int($data['user_id']))
            ? (string) $data['user_id']
            : null;
        $clientId = isset($data['client_id']) && is_string($data['client_id'])
            ? $data['client_id']
            : null;

        $params['_source'] = 'webhook_inbound';

        $event = new AnalyticsEvent(
            name: $eventName,
            params: $params,
            clientId: $clientId,
            userId: $userId,
        );

        try {
            $this->manager->trackEvent($event);

            Log::info('ZeroBoiler Analytics: inbound webhook event dispatched', [
                'event' => $eventName,
                'source' => 'inbound_webhook',
            ]);

            return [
                'status' => 'ok',
                'dispatched' => 1,
                'errors' => [],
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'dispatched' => 0,
                'errors' => [$e->getMessage()],
            ];
        }
    }

    /**
     * Receive and dispatch a batch of inbound events.
     *
     * @param  array<int, array{event: string, params?: array<string, mixed>}>  $events
     * @return array{status: string, dispatched: int, errors: list<string>}
     */
    private function receiveBatch(array $events): array
    {
        if (count($events) > $this->maxEventsPerPayload) {
            return [
                'status' => 'error',
                'dispatched' => 0,
                'errors' => ['Batch exceeds maximum event count ('.$this->maxEventsPerPayload.')'],
            ];
        }

        $dispatched = 0;
        $errors = [];

        foreach ($events as $index => $eventData) {
            if (! is_array($eventData) || ! isset($eventData['event']) || ! is_string($eventData['event'])) {
                $errors[] = "Invalid event at index {$index}: missing 'event' string";

                continue;
            }

            $params = (array) ($eventData['params'] ?? []);
            $userId = isset($eventData['user_id']) && (is_string($eventData['user_id']) || is_int($eventData['user_id']))
                ? (string) $eventData['user_id']
                : null;
            $clientId = isset($eventData['client_id']) && is_string($eventData['client_id'])
                ? $eventData['client_id']
                : null;

            $params['_source'] = 'webhook_inbound';

            $event = new AnalyticsEvent(
                name: $eventData['event'],
                params: $params,
                clientId: $clientId,
                userId: $userId,
            );

            try {
                $this->manager->trackEvent($event);
                $dispatched++;
            } catch (\Throwable $e) {
                $errors[] = "Event '{$eventData['event']}' at index {$index}: {$e->getMessage()}";
            }
        }

        Log::info('ZeroBoiler Analytics: inbound webhook batch processed', [
            'dispatched' => $dispatched,
            'errors' => count($errors),
        ]);

        return [
            'status' => $errors === [] ? 'ok' : 'partial',
            'dispatched' => $dispatched,
            'errors' => $errors,
        ];
    }

    /**
     * Check if inbound webhook is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the maximum events allowed per batch payload.
     */
    public function maxEventsPerPayload(): int
    {
        return $this->maxEventsPerPayload;
    }

    /**
     * Get the maximum payload size in bytes.
     */
    public function maxPayloadSize(): int
    {
        return $this->maxPayloadSize;
    }
}
