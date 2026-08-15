<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Http\HttpMiddlewareContract;

/**
 * HTTP middleware that automatically tracks request lifecycle events.
 *
 * Tracks API endpoint calls, response times, and error rates as analytics
 * events. Useful for monitoring API health, identifying slow endpoints,
 * and tracking user interaction patterns at the HTTP level.
 *
 * Events tracked:
 * - api_request: Every matching request (with method, path, status, duration)
 * - api_error: Requests returning 4xx/5xx status codes
 *
 * Configuration:
 *   'request_tracking' => [
 *       'enabled' => true,
 *       'track_success' => true,    // Track 2xx responses
 *       'track_client_errors' => true,  // Track 4xx responses
 *       'track_server_errors' => true,  // Track 5xx responses
 *       'slow_threshold_ms' => 1000,    // Log as slow_api_request above this
 *       'exclude_paths' => ['api/analytics/health', 'api/analytics/ping'],
 *       'exclude_methods' => ['OPTIONS', 'HEAD'],
 *       'max_param_length' => 100,
 *   ],
 *
 * @since 168.0.0
 */
final class AnalyticsRequestTrackerMiddleware implements HttpMiddlewareContract
{
    private AnalyticsManager $manager;

    /** @var array{enabled?: bool, track_success?: bool, track_client_errors?: bool, track_server_errors?: bool, slow_threshold_ms?: int, exclude_paths?: list<string>, exclude_methods?: list<string>, max_param_length?: int} */
    private array $config;

    private float $startTime;

    /**
     * @param  AnalyticsManager|null  $manager
     * @param  array<string, mixed>|null  $config
     */
    public function __construct(?AnalyticsManager $manager = null, ?array $config = null): void
    {
        $this->manager = $manager ?? app(AnalyticsManager::class);

        $repo = app(\Illuminate\Contracts\Config\Repository::class);
        $this->config = $config ?? $repo->get('zeroboiler.analytics.request_tracking', []);
    }

    #[\Override]
    public function handle(Request $request, Closure $next): Response
    {
        $this->startTime = microtime(true);

        $response = $next($request);

        $this->trackRequest($request, $response);

        return $response;
    }

    /**
     * Track the request as an analytics event.
     */
    private function trackRequest(Request $request, Response $response): void
    {
        if (! $this->shouldTrack($request, $response)) {
            return;
        }

        $durationMs = round((microtime(true) - $this->startTime) * 1000, 2);
        $status = $response->getStatusCode();
        $path = $request->path();
        $method = $request->method();

        $isError = $status >= 400;
        $isSlow = $durationMs >= ($this->config['slow_threshold_ms'] ?? 1000);

        $eventName = $isError ? 'api_error' : ($isSlow ? 'api_slow_request' : 'api_request');
        $category = $isError ? 'uptime' : 'engagement';

        $params = [
            'method' => $method,
            'path' => $this->truncate($path, 200),
            'status' => $status,
            'duration_ms' => $durationMs,
            'is_error' => $isError,
            'is_slow' => $isSlow,
        ];

        // Add query params (truncated for privacy)
        $query = $request->query();
        if ($query !== []) {
            $params['query_params_count'] = count($query);
        }

        try {
            $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
                name: $eventName,
                params: $params,
                category: $category,
                source: 'middleware',
            );
            $this->manager->trackEvent($event);
        } catch (\Throwable $e) {
            // Silent fail — middleware should never throw
        }
    }

    /**
     * Determine if this request should be tracked.
     */
    private function shouldTrack(Request $request, Response $response): bool
    {
        if (! ($this->config['enabled'] ?? false)) {
            return false;
        }

        $status = $response->getStatusCode();
        $isClientError = $status >= 400 && $status < 500;
        $isServerError = $status >= 500;

        if ($status >= 200 && $status < 300 && ! ($this->config['track_success'] ?? true)) {
            return false;
        }

        if ($isClientError && ! ($this->config['track_client_errors'] ?? true)) {
            return false;
        }

        if ($isServerError && ! ($this->config['track_server_errors'] ?? true)) {
            return false;
        }

        // Check excluded paths
        $excludePaths = $this->config['exclude_paths'] ?? [];
        if (in_array($request->path(), $excludePaths, true)) {
            return false;
        }

        // Check excluded methods
        $excludeMethods = $this->config['exclude_methods'] ?? ['OPTIONS', 'HEAD'];
        if (in_array($request->method(), $excludeMethods, true)) {
            return false;
        }

        // Don't track analytics API calls (prevent infinite loops)
        $analyticsPrefix = 'api/analytics';
        if (str_starts_with($request->path(), $analyticsPrefix)) {
            return false;
        }

        return true;
    }

    /**
     * Truncate a string to a maximum length.
     */
    private function truncate(string $value, int $maxLength): string
    {
        return mb_strlen($value) > $maxLength ? mb_substr($value, 0, $maxLength) : $value;
    }
}
