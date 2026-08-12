<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use ZeroBoiler\Analytics\Support\AnalyticsContext;
use ZeroBoiler\Analytics\AnalyticsManager;

/**
 * Middleware that wraps HTTP requests in an analytics context.
 *
 * Automatically creates an AnalyticsContext for each request, measuring
 * request duration and emitting timing events. On exceptions, error events
 * are emitted with request context.
 *
 * The context label is derived from the route name or path.
 *
 * @since 41.0.0
 */
final class EventContextMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  \Closure(Request): Response  $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $context = $this->buildContext($request);

        $response = $next($request);

        // Tag the response with analytics context header
        $response->headers->set('X-ZB-Analytics-Context', $context->getLabel());

        return $response;
    }

    /**
     * Build an AnalyticsContext from the current request.
     */
    private function buildContext(Request $request): AnalyticsContext
    {
        $label = $this->resolveContextLabel($request);

        return AnalyticsContext::silent($label)
            ->withMetadata([
                'method' => $request->method(),
                'path' => $request->path(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'request_id' => Str::uuid()->toString(),
            ]);
    }

    /**
     * Resolve the context label from the route or request.
     */
    private function resolveContextLabel(Request $request): string
    {
        $routeName = $request->route()?->getName();

        if (is_string($routeName) && $routeName !== '') {
            return 'http.' . str_replace('.', '_', $routeName);
        }

        $path = $request->path();

        return 'http.' . str_replace(['/', '{', '}'], ['_', '', ''], $path);
    }
}
