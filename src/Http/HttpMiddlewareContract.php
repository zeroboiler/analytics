<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Contract for HTTP middleware components.
 *
 * @since 1.0.0
 */
interface HttpMiddlewareContract
{
    /**
     * Handle an incoming request and return a response.
     *
     * @param  Request  $request  Incoming HTTP request
     * @param  Closure  $next  Next middleware handler
     * @return Response  Response object
     */
    public function handle(Request $request, Closure $next): Response;
}
