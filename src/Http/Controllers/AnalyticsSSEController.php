<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventStreamService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server-Sent Events (SSE) controller for real-time analytics streaming.
 *
 * Provides a persistent HTTP connection that pushes analytics events
 * to connected dashboard clients as they occur. Supports cursor-based
 * resume, event filtering, and automatic reconnection.
 *
 * Unlike the polling-based stream endpoint, SSE provides true push
 * notifications without client-side polling overhead.
 *
 * @see \ZeroBoiler\Analytics\Services\EventStreamService
 */
final class AnalyticsSSEController extends Controller
{
    private EventStreamService $streamService;

    public function __construct(EventStreamService $streamService): void
    {
        $this->streamService = $streamService;
    }

    /**
     * Stream analytics events via Server-Sent Events.
     *
     * GET /api/analytics/sse
     *
     * Query params:
     *   cursor — resume from cursor (default: 0)
     *   filter — event name filter (supports * wildcard)
     *   category — category filter (ecommerce|saas|engagement)
     *   heartbeat — heartbeat interval in seconds (default: 30)
     *
     * @return StreamedResponse
     */
    public function stream(Request $request): StreamedResponse
    {
        $cursor = (int) ($request->query('cursor', 0));
        $filter = $request->query('filter');
        $category = $request->query('category');
        $heartbeatInterval = min(60, max(5, (int) ($request->query('heartbeat', 30))));
        $filter = is_string($filter) ? $filter : null;
        $category = is_string($category) ? $category : null;

        $response = new StreamedResponse(function () use ($cursor, $filter, $category, $heartbeatInterval): void {
            // Set SSE headers
            if (! headers_sent()) {
                header('Content-Type: text/event-stream');
                header('Cache-Control: no-cache');
                header('Connection: keep-alive');
                header('X-Accel-Buffering: no');
            }

            $lastCursor = $cursor;
            $lastHeartbeat = time();
            $maxRuntime = 300; // 5 minutes max connection lifetime
            $startTime = time();

            // Send initial cursor for resume support
            $this->sendSSE('cursor', ['cursor' => $lastCursor]);

            while (true) {
                // Check max runtime
                if ((time() - $startTime) > $maxRuntime) {
                    $this->sendSSE('close', ['reason' => 'max_runtime', 'cursor' => $lastCursor]);
                    break;
                }

                // Check connection aborted
                if (connection_aborted()) {
                    break;
                }

                // Fetch new events since last cursor
                $events = $this->streamService->getEventsSince($lastCursor);

                foreach ($events as $eventData) {
                    // Apply filters
                    if ($filter !== null && !$this->matchesFilter($eventData['event'] ?? '', $filter)) {
                        $lastCursor = $eventData['id'] ?? $lastCursor;
                        continue;
                    }

                    if ($category !== null && !$this->matchesCategory($eventData['event'] ?? '', $category)) {
                        $lastCursor = $eventData['id'] ?? $lastCursor;
                        continue;
                    }

                    $this->sendSSE('event', $eventData);
                    $lastCursor = $eventData['id'] ?? $lastCursor;
                }

                // Send heartbeat to keep connection alive
                $now = time();
                if (($now - $lastHeartbeat) >= $heartbeatInterval) {
                    $this->sendSSE('heartbeat', [
                        'cursor' => $lastCursor,
                        'timestamp' => date('c'),
                    ]);
                    $lastHeartbeat = $now;
                }

                // Wait before next poll (reduces CPU usage)
                usleep(500000); // 500ms
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * Get SSE connection metadata and server capability info.
     *
     * GET /api/analytics/sse/info
     */
    public function info(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'sse' => [
                'supported' => true,
                'endpoint' => '/api/analytics/sse',
                'max_connection_seconds' => 300,
                'min_heartbeat_seconds' => 5,
                'default_heartbeat_seconds' => 30,
                'max_heartbeat_seconds' => 60,
                'supports_cursor_resume' => true,
                'supports_filtering' => true,
                'supports_category_filter' => true,
            ],
            'buffer' => [
                'size' => $this->streamService->getBufferSize(),
                'current_count' => $this->streamService->getCurrentCount(),
                'cursor' => $this->streamService->getCurrentCursor(),
            ],
        ]);
    }

    /**
     * Check if the SSE endpoint is available and the stream buffer is active.
     *
     * GET /api/analytics/sse/health
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'sse' => 'active',
            'buffer_utilization' => $this->streamService->getBufferUtilization(),
        ]);
    }

    /**
     * Send a Server-Sent Event message.
     *
     * @param  string  $type  SSE event type
     * @param  array<string, mixed>  $data  Event payload
     */
    private function sendSSE(string $type, array $data): void
    {
        if (connection_aborted()) {
            return;
        }

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return;
        }

        echo "event: {$type}\n";
        echo "data: {$json}\n";
        echo "\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    /**
     * Check if an event name matches a filter pattern.
     *
     * Supports exact match and wildcard patterns (e.g., 'purchase*' matches 'purchase', 'purchase_refund').
     *
     * @param  string  $eventName  The actual event name
     * @param  string  $pattern  The filter pattern (may contain * wildcard)
     */
    private function matchesFilter(string $eventName, string $pattern): bool
    {
        if ($pattern === '*') {
            return true;
        }

        if (str_contains($pattern, '*')) {
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';

            return (bool) preg_match($regex, $eventName);
        }

        return strtolower($eventName) === strtolower($pattern);
    }

    /**
     * Check if an event name belongs to a specific category.
     *
     * Uses the EventCatalog for accurate category lookup when possible,
     * with a fast fallback heuristic based on common event name prefixes.
     *
     * @param  string  $eventName  The event name to check
     * @param  string  $category  The target category (ecommerce|saas|engagement)
     */
    private function matchesCategory(string $eventName, string $category): bool
    {
        $catalog = \ZeroBoiler\Analytics\Events\EventCatalog::byCategory();

        foreach ($catalog[strtolower($category)] ?? [] as $entry) {
            if ($entry['name'] === $eventName) {
                return true;
            }
        }

        return false;
    }
}
