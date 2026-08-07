<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Real-time event stream collector for SSE (Server-Sent Events) broadcasting.
 *
 * Collects dispatched events in a ring buffer and provides methods
 * for the SSE controller to stream new events to connected dashboard clients.
 * Supports filtering by event name, category, and provider.
 *
 * Zero-dependency: works without Redis/WebSocket by using an in-memory
 * ring buffer with cursor-based consumption.
 *
 * @see \ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController
 */
final class EventStreamService
{
    /** @var int Maximum events to keep in the ring buffer */
    private int $bufferSize;

    /** @var list<array{id: int, event: string, params: array<string, mixed>, client_id: string|null, user_id: string|null, provider: string|null, timestamp: string, dispatched: bool}> */
    private array $buffer = [];

    /** @var int Auto-incrementing event cursor */
    private int $cursor = 0;

    /** @var int Last seen cursor per client connection (for resume) */
    private int $lastDispatchCursor = 0;

    private AnalyticsManager $manager;

    private AnalyticsMetrics $metrics;

    /**
     * @param  AnalyticsManager  $manager
     * @param  AnalyticsMetrics  $metrics
     * @param  int  $bufferSize  Ring buffer size (default: 1000)
     */
    public function __construct(
        AnalyticsManager $manager,
        AnalyticsMetrics $metrics,
        int $bufferSize = 1000,
    ): void {
        $this->manager = $manager;
        $this->metrics = $metrics;
        $this->bufferSize = $bufferSize;
    }

    /**
     * Record an event dispatch in the stream buffer.
     *
     * Called by the event pipeline after successful dispatch.
     * Events beyond the buffer size are automatically evicted (FIFO).
     *
     * @param  string  $eventName  Event name
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  User ID
     * @param  string|null  $provider  Provider that dispatched (ga4, gtm, meta, etc.)
     */
    public function push(
        string $eventName,
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
        ?string $provider = null,
    ): void {
        $this->cursor++;

        $this->buffer[] = [
            'id' => $this->cursor,
            'event' => $eventName,
            'params' => $this->sanitizeParams($params),
            'client_id' => $clientId,
            'user_id' => $userId,
            'provider' => $provider,
            'timestamp' => date('c'),
            'dispatched' => true,
        ];

        // Evict oldest if buffer is full
        if (count($this->buffer) > $this->bufferSize) {
            $this->buffer = array_slice($this->buffer, -$this->bufferSize);
        }
    }

    /**
     * Get events newer than the given cursor ID.
     *
     * Used by SSE clients to poll for new events since their last check.
     * Returns events in ascending cursor order.
     *
     * @param  int  $afterCursor  Last seen cursor ID (0 = all buffered)
     * @param  int  $limit  Maximum events to return
     * @return list<array{id: int, event: string, params: array<string, mixed>, client_id: string|null, user_id: string|null, provider: string|null, timestamp: string, dispatched: bool}>
     */
    public function since(int $afterCursor = 0, int $limit = 100): array
    {
        if ($afterCursor >= $this->cursor) {
            return [];
        }

        $events = [];
        $count = 0;

        foreach ($this->buffer as $entry) {
            if ($entry['id'] > $afterCursor) {
                $events[] = $entry;
                $count++;

                if ($count >= $limit) {
                    break;
                }
            }
        }

        return $events;
    }

    /**
     * Get events filtered by event name or pattern.
     *
     * Supports exact match or glob-style '*' wildcard.
     *
     * @param  string  $filter  Event name filter (e.g. 'purchase', 'page_*', '*')
     * @param  int  $limit  Maximum events to return
     * @return list<array{id: int, event: string, params: array<string, mixed>, client_id: string|null, user_id: string|null, provider: string|null, timestamp: string, dispatched: bool}>
     */
    public function filter(string $filter, int $limit = 100): array
    {
        if ($filter === '*') {
            return array_slice($this->buffer, -$limit);
        }

        $isGlob = str_contains($filter, '*');

        if (! $isGlob) {
            // Exact match
            $matched = array_filter(
                $this->buffer,
                fn (array $e): bool => $e['event'] === $filter,
            );
        } else {
            // Glob match: convert * to regex
            $regex = '/^' . str_replace('\*', '.*', preg_quote($filter, '/')) . '$/';
            $matched = array_filter(
                $this->buffer,
                fn (array $e): bool => preg_match($regex, $e['event']) === 1,
            );
        }

        return array_slice(array_values($matched), -$limit);
    }

    /**
     * Get events filtered by event category.
     *
     * Uses the EventCatalog to determine event category.
     *
     * @param  string  $category  Category name (ecommerce, saas, engagement)
     * @param  int  $limit  Maximum events to return
     * @return list<array{id: int, event: string, params: array<string, mixed>, client_id: string|null, user_id: string|null, provider: string|null, timestamp: string, dispatched: bool}>
     */
    public function filterByCategory(string $category, int $limit = 100): array
    {
        $matched = array_filter(
            $this->buffer,
            function (array $entry) use ($category): bool {
                $entryCategory = $this->manager->eventCategory($entry['event']);

                return $entryCategory === $category;
            },
        );

        return array_slice(array_values($matched), -$limit);
    }

    /**
     * Get the current cursor position.
     *
     * Clients should store this and use it with `since()` for polling.
     */
    public function cursor(): int
    {
        return $this->cursor;
    }

    /**
     * Get the buffer size configuration.
     */
    public function bufferSize(): int
    {
        return $this->bufferSize;
    }

    /**
     * Get the number of events currently in the buffer.
     */
    public function bufferedCount(): int
    {
        return count($this->buffer);
    }

    /**
     * Get aggregated stream statistics.
     *
     * @return array{cursor: int, buffered: int, buffer_size: int, event_types: int, top_events: list<array{event: string, count: int}>, by_provider: array<string, int>, by_category: array<string, int>}
     */
    public function stats(): array
    {
        $eventCounts = [];
        $providerCounts = [];
        $categoryCounts = [];

        foreach ($this->buffer as $entry) {
            $name = $entry['event'];
            $eventCounts[$name] = ($eventCounts[$name] ?? 0) + 1;

            $provider = $entry['provider'];
            if ($provider !== null) {
                $providerCounts[$provider] = ($providerCounts[$provider] ?? 0) + 1;
            }

            $category = $this->manager->eventCategory($name);
            if ($category !== null) {
                $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
            }
        }

        arsort($eventCounts);

        $topEvents = [];
        $count = 0;
        foreach ($eventCounts as $event => $cnt) {
            $topEvents[] = ['event' => $event, 'count' => $cnt];
            $count++;
            if ($count >= 10) {
                break;
            }
        }

        arsort($providerCounts);

        return [
            'cursor' => $this->cursor,
            'buffered' => count($this->buffer),
            'buffer_size' => $this->bufferSize,
            'event_types' => count($eventCounts),
            'top_events' => $topEvents,
            'by_provider' => $providerCounts,
            'by_category' => $categoryCounts,
        ];
    }

    /**
     * Clear the event stream buffer.
     */
    public function flush(): void
    {
        $this->buffer = [];
        $this->cursor = 0;
        $this->lastDispatchCursor = 0;
    }

    /**
     * Sanitize event parameters for safe streaming.
     *
     * Removes sensitive keys and truncates large values to prevent
     * excessive memory usage in the stream buffer.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function sanitizeParams(array $params): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'api_key', 'credit_card', 'ssn'];

        $sanitized = [];

        foreach ($params as $key => $value) {
            // Skip sensitive keys
            if (in_array(strtolower($key), $sensitiveKeys, true)) {
                $sanitized[$key] = '[REDACTED]';

                continue;
            }

            // Truncate strings longer than 500 chars
            if (is_string($value) && strlen($value) > 500) {
                $sanitized[$key] = substr($value, 0, 500) . '…';

                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }
}
