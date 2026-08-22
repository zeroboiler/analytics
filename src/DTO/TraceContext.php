<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

use Illuminate\Support\Str;

/**
 * Trace context for end-to-end event tracking correlation.
 *
 * Provides a unique trace ID and span ID for each analytics event,
 * enabling event tracing through the full pipeline: client → API → queue → provider.
 *
 * Trace IDs are propagated through event parameters as `_trace_id` and `_span_id`,
 * using the `_` prefix convention to avoid collision with user-provided params.
 *
 * Usage:
 *   $trace = TraceContext::generate();
 *   $event->params['_trace_id'] = $trace->traceId();
 *
 * @see \ZeroBoiler\Analytics\Services\EventTraceService
 *
 * @since 1.0.0
 */
final readonly class TraceContext
{
    /**
     * Create a new TraceContext with pre-generated IDs.
     *
     * @param  string  $traceId  Root trace ID (32 hex chars — ULID format)
     * @param  string  $spanId  Current span ID (16 hex chars)
     * @param  string|null  $parentSpanId  Parent span ID for nested operations
     * @param  string  $source  Origin of the trace (api, server, queue, client)
     */
    public function __construct(
        private string $traceId,
        private string $spanId,
        private ?string $parentSpanId = null,
        private string $source = 'server',
    ){}

    /**
     * Generate a new TraceContext with random IDs.
     *
     * @param  string  $source  Origin of the trace
     */
    public static function generate(string $source = 'server'): self
    {
        return new self(
            traceId: self::generateTraceId(),
            spanId: self::generateSpanId(),
            source: $source,
        );
    }

    /**
     * Create a child span from this trace context.
     *
     * Inherits the trace ID and sets the current span as the parent.
     */
    public function childSpan(string $source = 'server'): self
    {
        return new self(
            traceId: $this->traceId,
            spanId: self::generateSpanId(),
            parentSpanId: $this->spanId,
            source: $source,
        );
    }

    /**
     * Extract trace context from event parameters if present.
     *
     * Looks for `_trace_id` and `_span_id` keys in the event params.
     * Returns null if no trace context is found.
     *
     * @param  array<string, mixed>  $params  Event parameters
     */
    public static function fromParams(array $params): ?self
    {
        $traceId = $params['_trace_id'] ?? null;
        $spanId = $params['_span_id'] ?? null;

        if (! is_string($traceId) || ! is_string($spanId)) {
            return null;
        }

        $parentSpanId = $params['_parent_span_id'] ?? null;
        $source = $params['_trace_source'] ?? 'unknown';

        return new self(
            traceId: $traceId,
            spanId: $spanId,
            parentSpanId: is_string($parentSpanId) ? $parentSpanId : null,
            source: is_string($source) ? $source : 'unknown',
        );
    }

    /**
     * Get the root trace ID.
     */
    public function traceId(): string
    {
        return $this->traceId;
    }

    /**
     * Get the current span ID.
     */
    public function spanId(): string
    {
        return $this->spanId;
    }

    /**
     * Get the parent span ID.
     */
    public function parentSpanId(): ?string
    {
        return $this->parentSpanId;
    }

    /**
     * Get the trace source.
     */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * Convert the trace context to an array for injection into event params.
     *
     * Uses the `_` prefix convention to distinguish trace metadata
     * from user-provided event parameters.
     *
     * @return array{_trace_id: string, _span_id: string, _parent_span_id: string|null, _trace_source: string}
     */
    public function toParams(): array
    {
        return [
            '_trace_id' => $this->traceId,
            '_span_id' => $this->spanId,
            '_parent_span_id' => $this->parentSpanId,
            '_trace_source' => $this->source,
        ];
    }

    /**
     * Generate a ULID-format trace ID (32 hex characters).
     */
    private static function generateTraceId(): string
    {
        return bin2hex(Str::uuid()->getBytes());
    }

    /**
     * Generate a short span ID (16 hex characters).
     */
    private static function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Convert to string representation for logging.
     */
    public function toString(): string
    {
        $str = $this->traceId.'/'.$this->spanId;

        if ($this->parentSpanId !== null) {
            $str .= ' (parent: '.$this->parentSpanId.')';
        }

        $str .= ' ['.$this->source.']';

        return $str;
    }
}
