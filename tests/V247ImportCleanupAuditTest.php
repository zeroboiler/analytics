<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

tests\Pest::describe('V247 — Unused Import Cleanup Audit', function (): void {
    tests\Pest::it('has zero unused imports across all 980 source files', function (): void {
        $srcDir = __DIR__ . '/../src';
        $violations = [];
        $totalFiles = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $totalFiles++;
            $content = file_get_contents($file->getPathname());

            // Find all class import use statements
            if (!preg_match_all('/^use\s+[A-Za-z_][\\A-Za-z0-9_]*?(?:\s+as\s+(\w+))?\s*;\s*$/m', $content, $matches, PREG_SET_ORDER)) {
                continue;
            }

            // Find end of use block
            $lastUseEnd = 0;
            foreach ($matches as $m) {
                $pos = strpos($content, $m[0], (int) $lastUseEnd);
                if ($pos !== false) {
                    $lastUseEnd = $pos + strlen($m[0]);
                }
            }

            $afterUses = substr($content, (int) $lastUseEnd);

            foreach ($matches as $m) {
                // Determine the imported class name
                $fullUse = $m[0];
                if (str_contains($fullUse, 'function ') || str_contains($fullUse, 'const ')) {
                    continue;
                }

                $className = $m[1] ?? null;
                if ($className === null) {
                    $parts = explode('\\', $fullUse);
                    $className = end($parts);
                    $className = str_replace(';', '', trim($className));
                }

                if ($className === '' || $className[0] === strtolower($className[0])) {
                    continue;
                }

                // Check if used after the use block
                if (!preg_match('/\b' . preg_quote($className, '/') . '\b/', $afterUses)) {
                    $relPath = str_replace($srcDir . '/', '', $file->getPathname());
                    $violations[] = "{$relPath}: unused import {$className}";
                }
            }
        }

        tests\Pest::expect($violations)->toBeEmpty(
            "Found " . count($violations) . " unused imports across {$totalFiles} files.\n"
            . "Files with violations: " . implode(', ', array_slice($violations, 0, 10))
            . (count($violations) > 10 ? " and " . (count($violations) - 10) . " more" : ''),
        );
    });

    tests\Pest::it('all source files retain strict_types declaration', function (): void {
        $srcDir = __DIR__ . '/../src';
        $violations = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());

            if (!str_contains($content, 'declare(strict_types=1)')) {
                $relPath = str_replace($srcDir . '/', '', $file->getPathname());
                $violations[] = $relPath;
            }
        }

        tests\Pest::expect($violations)->toBeEmpty(
            count($violations) . ' files missing declare(strict_types=1)',
        );
    });

    tests\Pest::it('all source files retain MIT license header', function (): void {
        $srcDir = __DIR__ . '/../src';
        $violations = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());

            if (!str_contains($content, 'ZeroBoiler, licensed under the MIT')) {
                $relPath = str_replace($srcDir . '/', '', $file->getPathname());
                $violations[] = $relPath;
            }
        }

        tests\Pest::expect($violations)->toBeEmpty(
            count($violations) . ' files missing MIT header',
        );
    });

    tests\Pest::it('no excessive blank lines after cleanup', function (): void {
        $srcDir = __DIR__ . '/../src';
        $violations = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());

            if (str_contains($content, "\n\n\n\n")) {
                $relPath = str_replace($srcDir . '/', '', $file->getPathname());
                $violations[] = $relPath;
            }
        }

        tests\Pest::expect($violations)->toBeEmpty(
            count($violations) . ' files with 4+ consecutive blank lines',
        );
    });

    tests\Pest::it('version consistency across all 5 entry points', function (): void {
        $base = __DIR__ . '/..';
        $expected = '247.0.0';

        // 1. composer.json
        $composer = json_decode(file_get_contents($base . '/composer.json'), true);
        tests\Pest::expect($composer['version'])->toBe($expected, 'composer.json version mismatch');

        // 2. package.json
        $pkg = json_decode(file_get_contents($base . '/package.json'), true);
        tests\Pest::expect($pkg['version'])->toBe($expected, 'package.json version mismatch');

        // 3. AnalyticsEvent::VERSION
        $eventFile = file_get_contents($base . '/src/DTO/AnalyticsEvent.php');
        tests\Pest::expect($eventFile)->toContain("const VERSION = '{$expected}'", 'AnalyticsEvent::VERSION mismatch');

        // 4. AnalyticsServiceProvider @version
        $spFile = file_get_contents($base . '/src/AnalyticsServiceProvider.php');
        tests\Pest::expect($spFile)->toContain("@version {$expected}", 'ServiceProvider @version mismatch');

        // 5. README badge
        $readme = file_get_contents($base . '/README.md');
        tests\Pest::expect($readme)->toContain("version-{$expected}", 'README version badge mismatch');
    });

    tests\Pest::it('all public methods have return type declarations', function (): void {
        $srcDir = __DIR__ . '/../src';
        $violations = [];
        $magicMethods = ['__construct', '__destruct', '__clone', '__toString', '__invoke',
            '__debugInfo', '__serialize', '__unserialize', '__sleep', '__wakeup',
        ];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());

            // Skip interfaces and traits
            $firstBrace = strpos($content, '{');
            if ($firstBrace !== false) {
                $beforeBrace = substr($content, 0, $firstBrace);
                if (str_contains($beforeBrace, 'interface ') || str_contains($beforeBrace, 'trait ')) {
                    continue;
                }
            }

            if (!preg_match_all(
                '/^\s*(public|protected|private)\s+(static\s+)?function\s+(\w+)\s*\([^)]*\)\s*(?::\s*\S+)?.*$/m',
                $content,
                $methodMatches,
                PREG_SET_ORDER,
            )) {
                continue;
            }

            foreach ($methodMatches as $mm) {
                $funcName = $mm[3];
                if (in_array($funcName, $magicMethods, true)) {
                    continue;
                }
                // Check if return type is present (after closing paren)
                $fullLine = $mm[0];
                $parenEnd = strpos($fullLine, ')');
                if ($parenEnd === false) {
                    continue;
                }
                $afterParen = trim(substr($fullLine, $parenEnd + 1));
                if (!str_starts_with($afterParen, ':')) {
                    $relPath = str_replace($srcDir . '/', '', $file->getPathname());
                    $lineNum = substr_count(substr($content, 0, strpos($content, $fullLine)), "\n") + 1;
                    $violations[] = "{$relPath}:{$lineNum} {$funcName}()";
                }
            }
        }

        tests\Pest::expect($violations)->toBeEmpty(
            count($violations) . ' methods missing return type: ' . implode(', ', array_slice($violations, 0, 10)),
        );
    });

    tests\Pest::it('all classes with class keyword are final or abstract', function (): void {
        $srcDir = __DIR__ . '/../src';
        $violations = [];

        // Classes allowed to be non-final (extended by framework or users)
        $exempt = [
            'AnalyticsManager.php',
            'AnalyticsEventController.php',
            'AnalyticsEventModel.php',
        ];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (in_array($file->getBasename(), $exempt, true)) {
                continue;
            }

            $content = file_get_contents($file->getPathname());

            if (!preg_match_all('/^class\s+(\w+)/m', $content, $classMatches)) {
                continue;
            }

            foreach ($classMatches[1] as $className) {
                // Check if preceded by 'final ' or 'abstract '
                $pos = strpos($content, 'class ' . $className);
                if ($pos === false) {
                    continue;
                }
                $before = substr($content, max(0, $pos - 20), 20);
                if (str_contains($before, 'final ') || str_contains($before, 'abstract ')) {
                    continue;
                }

                // Check if it extends something
                $linePattern = '/class\s+' . preg_quote($className, '/') . '\s+extends/m';
                if (preg_match($linePattern, $content)) {
                    continue;
                }

                $relPath = str_replace($srcDir . '/', '', $file->getPathname());
                $violations[] = "{$relPath}: class {$className} is not final";
            }
        }

        tests\Pest::expect($violations)->toBeEmpty(
            count($violations) . ' non-final classes: ' . implode(', ', $violations),
        );
    });

    tests\Pest::it('maintains source file scale thresholds', function (): void {
        $srcDir = __DIR__ . '/../src';
        $testDir = __DIR__;

        $srcCount = 0;
        $testCount = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $srcCount++;
            }
        }

        $testIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($testDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($testIterator as $file) {
            if ($file->getExtension() === 'php') {
                $testCount++;
            }
        }

        tests\Pest::expect($srcCount)->toBeGreaterThanOrEqual(980, "Source file count dropped below 980 (got {$srcCount})");
        tests\Pest::expect($testCount)->toBeGreaterThanOrEqual(497, "Test file count dropped below 497 (got {$testCount})");
    });
});
