<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests\Exceptions;

use ZeroBoiler\Analytics\Exceptions\AnalyticsException;
use ZeroBoiler\Analytics\Exceptions\AnalyticsRuntimeException;
use ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException;
use Exception;

test('AnalyticsException extends Exception', function (): void {
    $exception = new class('test') extends AnalyticsException {};

    expect($exception)
        ->toBeInstanceOf(Exception::class)
        ->toBeInstanceOf(AnalyticsException::class)
        ->and($exception->getMessage())->toBe('test')
        ->and($exception->getCode())->toBe(0);
});

test('AnalyticsException accepts code and previous', function (): void {
    $previous = new Exception('root cause');
    $exception = new class('wrapper', 42, $previous) extends AnalyticsException {};

    expect($exception->getMessage())->toBe('wrapper')
        ->and($exception->getCode())->toBe(42)
        ->and($exception->getPrevious())->toBe($previous);
});

test('InvalidAnalyticsArgumentException extends AnalyticsException', function (): void {
    $exception = new InvalidAnalyticsArgumentException('bad value');

    expect($exception)
        ->toBeInstanceOf(AnalyticsException::class)
        ->toBeInstanceOf(Exception::class)
        ->and($exception->getMessage())->toBe('bad value');
});

test('AnalyticsRuntimeException extends AnalyticsException', function (): void {
    $exception = new AnalyticsRuntimeException('processing failed');

    expect($exception)
        ->toBeInstanceOf(AnalyticsException::class)
        ->toBeInstanceOf(Exception::class)
        ->and($exception->getMessage())->toBe('processing failed');
});

test('AnalyticsRuntimeException can chain previous exception', function (): void {
    $previous = new Exception('cURL error');
    $exception = new AnalyticsRuntimeException('Export failed', 0, $previous);

    expect($exception->getPrevious())->toBe($previous)
        ->and($exception->getPrevious()->getMessage())->toBe('cURL error');
});

test('AnalyticsException hierarchy catchable by base type', function (): void {
    $exceptions = [
        new InvalidAnalyticsArgumentException('arg'),
        new AnalyticsRuntimeException('runtime'),
    ];

    foreach ($exceptions as $exception) {
        expect($exception)->toBeInstanceOf(AnalyticsException::class);
    }

    // Catching all analytics exceptions in one block works
    $caught = false;
    try {
        throw new AnalyticsRuntimeException('catch me');
    } catch (AnalyticsException $e) {
        $caught = true;
        expect($e->getMessage())->toBe('catch me');
    }

    expect($caught)->toBeTrue();
});
