<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Pipeline filter that enriches events with automatic metadata.
 *
 * Attaches server-side metadata that is commonly needed by analytics
 * providers but not always available from the client. Includes session ID,
 * page URL, referrer, and ISO-8601 timestamp.
 *
 * Only adds metadata if the key doesn't already exist on the event,
 * allowing client-sent values to take precedence.
 */
final class EventMetadataEnricher
{
    /** @var array<string, mixed> Metadata to attach */
    private readonly array $metadata;

    /**
     * @param  string|null  $sessionId  Current session ID
     * @param  string|null  $pageUrl  Current page URL
     * @param  string|null  $referrer  Page referrer
     * @param  bool  $includeTimestamp  Whether to add server timestamp
     * @param  array<string, mixed>  $extra  Additional metadata to merge
     */
    public function __construct(
        ?string $sessionId = null,
        ?string $pageUrl = null,
        ?string $referrer = null,
        bool $includeTimestamp = true,
        array $extra = [],
    ): void {
        $this->metadata = array_merge(array_filter([
            '_session_id' => $sessionId,
            '_page_url' => $pageUrl,
            '_referrer' => $referrer,
            '_timestamp' => $includeTimestamp ? now()->toIso8601String() : null,
        ]), $extra);
    }

    /**
     * Enrich the event with automatic metadata.
     *
     * Metadata keys prefixed with `_` to avoid collision with user params.
     * Existing keys are not overwritten.
     *
     * @return AnalyticsEvent|null The enriched event, or null if filtered
     */
    public function __invoke(AnalyticsEvent $event): ?AnalyticsEvent
    {
        if (empty($this->metadata)) {
            return $event;
        }

        $mergedParams = array_merge($this->metadata, $event->params);

        return new AnalyticsEvent(
            name: $event->name,
            params: $mergedParams,
            clientId: $event->clientId,
            userId: $event->userId,
        );
    }
}
