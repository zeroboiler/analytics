<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

/**
 * Laravel helper function stubs for PHPStan analysis.
 * These are not executed at runtime — they exist purely so PHPStan
 * can resolve the symbols in a package that doesn't depend on
 * the full Laravel framework.
 */

namespace {
    if (! function_exists('app')) {
        /**
         * @param  string|null  $abstract
         * @return mixed
         */
        function app($abstract = null, array $parameters = [])
        {
            return null;
        }
    }

    if (! function_exists('config')) {
        /**
         * @param  string|null  $key
         * @param  mixed  $default
         * @return mixed
         */
        function config($key = null, $default = null)
        {
            return null;
        }
    }

    if (! function_exists('config_path')) {
        function config_path(string $path = ''): string
        {
            return '';
        }
    }

    if (! function_exists('request')) {
        function request(): mixed
        {
            return null;
        }
    }
}

namespace Illuminate\Routing {
    if (! class_exists(Router::class)) {
        class Router
        {
            public function aliasMiddleware(string $alias, string $class): void {}
        }
    }
}
