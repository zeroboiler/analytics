<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Http;

use Closure;

/**
 * Contract for HTTP middleware components.
 *
 * Enables PHP 8.4+ #[\Override] attribute resolution on handle() methods
 * without requiring the full Illuminate framework to be present.
 * This interface mirrors Illuminate\Contracts\Http\Middleware but
 * uses simple types so standalone syntax checking succeeds.
 *
 * @since 1.0.0
 */
interface HttpMiddlewareContract
{
    /**
     * Handle an incoming request and return a response.
     *
     * @param  object  $request  Request object (Illuminate\Http\Request in Laravel context)
     * @param  Closure  $next  Next middleware handler
     * @return object  Response object
     */
    public function handle(object $request, Closure $next): object;
}
