<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);
use PHPUnit\Framework\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is appended to the base
| test case (PHPUnit\Framework\TestCase).
*/

uses(TestCase::class)->in('.');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet
| certain conditions. The "expect()" function gives you access to a set
| of expectation helpers. We've provided a few for you here.
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing
| code that is shared between tests. Use helper functions to keep your
| test files clean.
|
*/

function config_set(array $config): void
{
    foreach ($config as $key => $value) {
        config()->set($key, $value);
    }
}
