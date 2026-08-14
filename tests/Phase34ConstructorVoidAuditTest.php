<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════════════════════
// Phase 34 — Constructor :void Compliance Audit
// ═══════════════════════════════════════════════════════════════════════════════

test('all constructors have :void return type (PHP 8.5)', function (): void {
    $violations = [];
    foreach (glob(__DIR__.'/../src/**/*.php') as $file) {
        $content = file_get_contents($file);
        preg_match_all('/public\s+function\s+__construct\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $match) {
            $offset = $match[1] + strlen($match[0]);
            $depth = 0;
            for ($i = $offset; $i < strlen($content); $i++) {
                if ($content[$i] === '(') {
                    $depth++;
                } elseif ($content[$i] === ')') {
                    if ($depth === 0) {
                        $rest = ltrim(substr($content, $i + 1, 20));
                        if (! str_starts_with($rest, ': void')) {
                            $line = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                            $violations[] = basename($file).':'.$line;
                        }
                        break;
                    }
                    $depth--;
                }
            }
        }
    }
    expect($violations)->toBeEmpty()->and($violations)->toHaveCount(0);
});

test('AnalyticsMacroBuilder constructor has :void', function (): void {
    $ref = new ReflectionMethod(\ZeroBoiler\Analytics\Macros\AnalyticsMacroBuilder::class, '__construct');
    $returnType = $ref->getReturnType();
    expect($returnType)->not()->toBeNull();
    expect($returnType->getName())->toBe('void');
});

test('AnalyticsMacro constructor has :void', function (): void {
    $ref = new ReflectionMethod(\ZeroBoiler\Analytics\Macros\AnalyticsMacro::class, '__construct');
    $returnType = $ref->getReturnType();
    expect($returnType)->not()->toBeNull();
    expect($returnType->getName())->toBe('void');
});

test('EventSchemaDefinition constructor has :void', function (): void {
    $ref = new ReflectionMethod(\ZeroBoiler\Analytics\Schema\EventSchemaDefinition::class, '__construct');
    $returnType = $ref->getReturnType();
    expect($returnType)->not()->toBeNull();
    expect($returnType->getName())->toBe('void');
});

test('PropertyDefinition constructor has :void', function (): void {
    $ref = new ReflectionMethod(\ZeroBoiler\Analytics\Schema\PropertyDefinition::class, '__construct');
    $returnType = $ref->getReturnType();
    expect($returnType)->not()->toBeNull();
    expect($returnType->getName())->toBe('void');
});

test('EventSchemaRegistryExtended constructor has :void', function (): void {
    $ref = new ReflectionMethod(\ZeroBoiler\Analytics\Schema\EventSchemaRegistryExtended::class, '__construct');
    $returnType = $ref->getReturnType();
    expect($returnType)->not()->toBeNull();
    expect($returnType->getName())->toBe('void');
});

test('AnalyticsReplayAuditor constructor has :void', function (): void {
    $ref = new ReflectionMethod(\ZeroBoiler\Analytics\Services\AnalyticsReplayAuditor::class, '__construct');
    $returnType = $ref->getReturnType();
    expect($returnType)->not()->toBeNull();
    expect($returnType->getName())->toBe('void');
});

test('all 694 source files declare strict_types=1', function (): void {
    $srcFiles = glob(__DIR__.'/../src/**/*.php');
    $count = is_array($srcFiles) ? count($srcFiles) : 0;
    expect($count)->toBe(694);

    $violations = [];
    foreach ($srcFiles as $file) {
        if (! str_contains(file_get_contents($file), 'declare(strict_types=1)')) {
            $violations[] = basename($file);
        }
    }
    expect($violations)->toBeEmpty();
});

test('analytics exception hierarchy is intact', function (): void {
    $ref = new ReflectionClass(\ZeroBoiler\Analytics\Exceptions\AnalyticsException::class);
    expect($ref->isAbstract())->toBeTrue();
    expect($ref->isSubclassOf(\Throwable::class))->toBeTrue();
});
