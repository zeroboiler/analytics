<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\TraceContext;

/**
 * Event trace service for end-to-end event correlation.
 *
 * Injects and propagates trace context (trace ID, span ID) through
 * analytics events as they flow through the pipeline. This enables
 * event tracing for debugging, monitoring, and audit purposes.
 *
 * Features:
 * - Automatic trace ID injection on API events
 * - Child span creation for nested operations (batch → individual events)
 * - Trace context extraction from existing events
 * - Configurable enable/disable
 * - Trace ID preservation across queue boundaries
 *
 * Trace IDs are stored in event params with the `_trace_id` prefix
 * to avoid collision with user-provided parameters.
 *
 * @see \ZeroBoiler\Analytics\DTO\TraceContext
 *
 * @since 1.0.0
 */
final class EventTraceService
{
    private bool $enabled;

    private string $source;

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config){
        $traceConfig = $config->get('zeroboiler.analytics.tracing', []);
        /** @var array{enabled?: bool, source?: string} $traceConfig */
        $this->enabled = (bool) ($traceConfig['enabled'] ?? true);
        $this->source = (string) ($traceConfig['source'] ?? 'server');
    }

    /**
     * Inject trace context into an analytics event.
     *
     * If the event already has a trace context (e.g., passed from the client),
     * the existing context is preserved. Otherwise, a new one is generated.
     *
     * @param  AnalyticsEvent  $event  The event to trace
     * @param  string|null  $source  Override the trace source (defaults to config)
     * @return AnalyticsEvent  New event with trace context injected
     */
    public function inject(AnalyticsEvent $event, ?string $source = null): AnalyticsEvent
    {
        if (! $this->enabled) {
            return $event;
        }

        // Preserve existing trace context if present
        $existing = TraceContext::fromParams($event->params);
        $trace = $existing?->childSpan($source ?? $this->source)
            ?? TraceContext::generate($source ?? $this->source);

        $mergedParams = array_merge($event->params, $trace->toParams());

        return new AnalyticsEvent(
            name: $event->name,
            params: $mergedParams,
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
        );
    }

    /**
     * Inject a shared trace context into multiple events (batch tracing).
     *
     * All events in the batch share the same trace ID but get unique span IDs.
     * This allows correlating all events from a single batch request.
     *
     * @param  array<int, AnalyticsEvent>  $events  Batch of events
     * @param  string|null  $source  Trace source override
     * @return array<int, AnalyticsEvent>  Events with trace context injected
     */
    public function injectBatch(array $events, ?string $source = null): array
    {
        if (! $this->enabled || $events === []) {
            return $events;
        }

        $parentTrace = TraceContext::generate($source ?? $this->source);
        $source = $source ?? $this->source;

        return array_map(static function (AnalyticsEvent $event) use ($parentTrace, $source): AnalyticsEvent {
            $childTrace = $parentTrace->childSpan($source);
            $mergedParams = array_merge($event->params, $childTrace->toParams());

            return new AnalyticsEvent(
                name: $event->name,
                params: $mergedParams,
                clientId: $event->clientId,
                userId: $event->userId,
                timestamp: $event->timestamp,
            );
        }, $events);
    }

    /**
     * Extract trace context from an event.
     *
     * @param  AnalyticsEvent  $event  The event to extract from
     * @return TraceContext|null  Trace context or null if not present
     */
    public function extract(AnalyticsEvent $event): ?TraceContext
    {
        return TraceContext::fromParams($event->params);
    }

    /**
     * Strip trace context metadata from event parameters.
     *
     * Useful when forwarding events to external providers that should
     * not receive internal tracing metadata.
     *
     * @param  array<string, mixed>  $params  Event parameters
     * @return array<string, mixed>  Cleaned parameters without trace metadata
     */
    public function strip(array $params): array
    {
        return array_filter(
            $params,
            static fn (string $key): bool => ! str_starts_with($key, '_trace_') && ! str_starts_with($key, '_span_') && ! str_starts_with($key, '_parent_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Check if tracing is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Generate a new trace context (for manual injection).
     *
     * @param  string|null  $source  Trace source override
     */
    public function createTrace(?string $source = null): TraceContext
    {
        return TraceContext::generate($source ?? $this->source);
    }
}
