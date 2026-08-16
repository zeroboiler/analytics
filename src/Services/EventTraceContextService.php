<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\TraceContext;

/**
 * Event Trace Context Service.
 *
 * Propagates W3C Trace Context headers (traceparent, tracestate) through
 * the analytics event pipeline. Enables distributed tracing correlation
 * so analytics events can be linked to request traces in APM tools like
 * Datadog, Honeycomb, Jaeger, or OpenTelemetry collectors.
 *
 * When enabled, every analytics event automatically receives:
 * - `trace_id`: 32-character hex trace identifier
 * - `span_id`: 16-character hex span identifier
 * - `trace_flags`: W3C trace flags (e.g., '01' for sampled)
 *
 * The service extracts trace context from incoming HTTP headers (W3C format)
 * or generates new trace IDs when no context is present. Trace IDs are
 * consistent across all events within a single request.
 *
 * Inspired by OpenTelemetry's W3C Trace Context specification and
 * Segment's integration with APM tracing systems.
 *
 * Configuration: `zeroboiler.analytics.trace_context`
 *
 * @see \ZeroBoiler\Analytics\DTO\TraceContext
 * @see \ZeroBoiler\Analytics\Pipeline\TimestampEnricher
 *
 * @since 188.0.0
 */
final class EventTraceContextService
{
    /** @var string W3C traceparent header name */
    private const TRACEPARENT_HEADER = 'traceparent';

    /** @var string W3C tracestate header name */
    private const TRACESTATE_HEADER = 'tracestate';

    /** @var string W3C traceparent format: version-traceid-spanid-traceflags */
    private const TRACEPARENT_PATTERN = '/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/i';

    /** @var int Trace ID length in hex characters */
    private const TRACE_ID_LENGTH = 32;

    /** @var int Span ID length in hex characters */
    private const SPAN_ID_LENGTH = 16;

    private bool $enabled;

    /** @var bool Whether to validate incoming traceparent headers strictly */
    private bool $strictMode;

    /** @var bool Whether to enrich events automatically via pipeline integration */
    private bool $autoEnrich;

    /** @var string|null Current request's trace ID (set once per request) */
    private ?string $currentTraceId = null;

    /** @var string|null Current request's span ID */
    private ?string $currentSpanId = null;

    /** @var string|null Current request's trace flags */
    private ?string $currentTraceFlags = null;

    /** @var string|null Incoming tracestate header value */
    private ?string $currentTraceState = null;

    /**
     * @param  bool  $enabled  Whether trace context propagation is enabled
     * @param  bool  $strictMode  Whether to validate traceparent strictly
     * @param  bool  $autoEnrich  Whether to auto-enrich events
     */
    public function __construct(
        bool $enabled = true,
        bool $strictMode = true,
        bool $autoEnrich = true,
    ): void {
        $this->enabled = $enabled;
        $this->strictMode = $strictMode;
        $this->autoEnrich = $autoEnrich;
    }

    /**
     * Extract and parse trace context from an HTTP request.
     *
     * Reads the W3C traceparent and tracestate headers from the incoming
     * request. If a valid traceparent is found, the trace ID and span ID
     * are extracted. Otherwise, new ones are generated.
     *
     * @param  Request  $request  The incoming HTTP request
     * @return void
     */
    public function extractFromRequest(Request $request): void
    {
        if (! $this->enabled) {
            return;
        }

        $traceparent = $request->header(self::TRACEPARENT_HEADER);
        $this->currentTraceState = $request->header(self::TRACESTATE_HEADER);

        if (is_string($traceparent) && $traceparent !== '') {
            $parsed = $this->parseTraceparent($traceparent);
            if ($parsed !== null) {
                $this->currentTraceId = $parsed['trace_id'];
                $this->currentSpanId = $parsed['span_id'];
                $this->currentTraceFlags = $parsed['trace_flags'];

                return;
            }

            // In non-strict mode, generate new context if parsing failed
            if (! $this->strictMode) {
                $this->generateNewContext();

                return;
            }
        }

        $this->generateNewContext();
    }

    /**
     * Enrich an analytics event with trace context parameters.
     *
     * Adds trace_id, span_id, and trace_flags to the event's params.
     * Only enriches if trace context is available and enabled.
     *
     * @param  AnalyticsEvent  $event  The event to enrich
     * @return AnalyticsEvent  The enriched event (new instance with merged params)
     */
    public function enrichEvent(AnalyticsEvent $event): AnalyticsEvent
    {
        if (! $this->enabled || ! $this->autoEnrich) {
            return $event;
        }

        if ($this->currentTraceId === null) {
            return $event;
        }

        return new AnalyticsEvent(
            name: $event->name,
            params: array_merge($event->params, $this->traceParams()),
            category: $event->category,
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
        );
    }

    /**
     * Get the current trace context as a TraceContext DTO.
     *
     * Returns a TraceContext with the current trace/span IDs.
     * The traceFlags and traceState are stored in the service's
     * traceParams() output since the DTO doesn't natively support them.
     *
     * @return TraceContext
     */
    public function getTraceContext(): TraceContext
    {
        return new TraceContext(
            traceId: $this->currentTraceId ?? '',
            spanId: $this->currentSpanId ?? '',
            source: 'w3c_trace_context',
        );
    }

    /**
     * Get the current trace context parameters as an associative array.
     *
     * Suitable for merging into event params or logging context.
     *
     * @return array{trace_id: string, span_id: string, trace_flags: string}
     */
    public function traceParams(): array
    {
        return [
            'trace_id' => $this->currentTraceId ?? '',
            'span_id' => $this->currentSpanId ?? '',
            'trace_flags' => $this->currentTraceFlags ?? '01',
        ];
    }

    /**
     * Create a child span ID for downstream event dispatch.
     *
     * Generates a new 16-character hex span ID while keeping the same
     * trace ID. Use this when dispatching events to different providers
     * to create a span tree.
     *
     * @return string  16-character hex span ID
     */
    public function createChildSpan(): string
    {
        return Str::random(self::SPAN_ID_LENGTH / 2); // 8 bytes = 16 hex chars
    }

    /**
     * Generate a W3C-compliant traceparent header value.
     *
     * @return string  traceparent value (e.g., "00-abc123...-def456...-01")
     */
    public function toTraceparentHeader(): string
    {
        return sprintf(
            '00-%s-%s-%s',
            $this->currentTraceId ?? str_repeat('0', self::TRACE_ID_LENGTH),
            $this->currentSpanId ?? str_repeat('0', self::SPAN_ID_LENGTH),
            $this->currentTraceFlags ?? '01',
        );
    }

    /**
     * Check if trace context propagation is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if auto-enrichment is enabled.
     */
    public function isAutoEnrichEnabled(): bool
    {
        return $this->autoEnrich;
    }

    /**
     * Check if the current request has an active trace context.
     */
    public function hasActiveTrace(): bool
    {
        return $this->currentTraceId !== null && $this->currentTraceId !== '';
    }

    /**
     * Parse a W3C traceparent header value.
     *
     * @param  string  $traceparent  Raw traceparent value
     * @return array{trace_id: string, span_id: string, trace_flags: string}|null  Parsed components or null if invalid
     */
    private function parseTraceparent(string $traceparent): ?array
    {
        // Strip any whitespace or prefix (e.g., "00-")
        $traceparent = trim($traceparent);

        if (preg_match(self::TRACEPARENT_PATTERN, $traceparent, $matches)) {
            return [
                'trace_id' => strtolower($matches[2]),
                'span_id' => strtolower($matches[3]),
                'trace_flags' => strtolower($matches[4]),
            ];
        }

        return null;
    }

    /**
     * Generate a new trace context (trace ID + span ID).
     *
     * @return void
     */
    private function generateNewContext(): void
    {
        $this->currentTraceId = Str::random(self::TRACE_ID_LENGTH / 2); // 16 bytes = 32 hex chars
        $this->currentSpanId = Str::random(self::SPAN_ID_LENGTH / 2); // 8 bytes = 16 hex chars
        $this->currentTraceFlags = '01'; // sampled
    }
}
