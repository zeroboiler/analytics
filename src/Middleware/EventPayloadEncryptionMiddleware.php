<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Middleware;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventPayloadEncryptionService;

/**
 * Event payload encryption middleware.
 *
 * Automatically encrypts sensitive event parameters before they are
 * dispatched to analytics providers. Uses the EventPayloadEncryptionService
 * to determine which fields to encrypt based on global and per-event rules.
 *
 * This middleware runs after PII sanitization (lower priority = runs first)
 * so encrypted values are not double-processed. Typically placed at priority 45,
 * after PiiSanitizationMiddleware (50) has already handled removal/hashing.
 *
 * @see \ZeroBoiler\Analytics\Middleware\AnalyticsMiddlewareInterface
 * @see \ZeroBoiler\Analytics\Services\EventPayloadEncryptionService
 *
 * @since 53.0.0
 */
final class EventPayloadEncryptionMiddleware implements AnalyticsMiddlewareInterface
{
    private const PRIORITY = 45;

    private readonly EventPayloadEncryptionService $encryptionService;

    /**
     * @param  EventPayloadEncryptionService  $encryptionService  Field-level encryption service
     */
    public function __construct(EventPayloadEncryptionService $encryptionService)
    {
        $this->encryptionService = $encryptionService;
    }

    #[\Override]
    public function process(AnalyticsEvent $event): ?AnalyticsEvent
    {
        if (! $this->encryptionService->isEnabled()) {
            return $event;
        }

        if (empty($event->params)) {
            return $event;
        }

        $encryptedParams = $this->encryptionService->encryptParams(
            $event->params,
            $event->name,
        );

        return new AnalyticsEvent(
            name: $event->name,
            params: $encryptedParams,
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $event->source,
        );
    }

    #[\Override]
    public function priority(): int
    {
        return self::PRIORITY;
    }

    #[\Override]
    public function name(): string
    {
        return 'EventPayloadEncryption';
    }
}
