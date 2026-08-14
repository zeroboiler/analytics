<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Phase 30 production audit — constructor :void return type and final class compliance.
 *
 * Verifies that ALL source files with public constructors have the PHP 8.5
 * `: void` return type declaration, and all leaf classes are properly final.
 *
 * @since 100.3.0
 */
final class Phase30ConstructorVoidFinalityAuditTest extends TestCase
{
    /**
     * @test
     */
    public function all_public_constructors_have_void_return_type(): void
    {
        $sourceDir = __DIR__ . '/../src';
        $violations = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getRealPath());
            if ($content === false || ! str_contains($content, 'public function __construct')) {
                continue;
            }

            // Extract constructor signature from __construct to opening brace
            $idx = strpos($content, 'public function __construct');
            $braceIdx = strpos($content, '{', $idx);

            if ($braceIdx === false) {
                continue;
            }

            $signature = substr($content, $idx, $braceIdx - $idx);

            if (! str_contains($signature, ': void')) {
                $relative = str_replace($sourceDir . '/', '', $file->getRealPath());
                $violations[] = $relative;
            }
        }

        $this->assertEmpty(
            $violations,
            sprintf(
                'Constructors missing :void return type in %d file(s): %s',
                count($violations),
                implode(', ', $violations),
            ),
        );
    }

    /**
     * @test
     */
    public function all_leaf_classes_are_final_or_abstract_or_interface_or_trait(): void
    {
        $sourceDir = __DIR__ . '/../src';
        $violations = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getRealPath();
            $relative = str_replace($sourceDir . '/', '', $path);

            // Skip contracts, interfaces, traits
            if (str_contains($relative, 'Contracts/')
                || str_contains($relative, 'Interfaces/')
                || str_contains($relative, 'Traits/')
                || str_contains($relative, 'Concerns/')) {
                continue;
            }

            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }

            if (! preg_match('/\bclass\s+\w+/', $content)) {
                continue;
            }

            // Check if file already has final, abstract, trait, interface, or enum
            if (preg_match('/\b(final\s+|abstract\s+|trait\s+|interface\s+|enum\s+)/', $content)) {
                continue;
            }

            $violations[] = $relative;
        }

        $this->assertEmpty(
            $violations,
            sprintf(
                'Leaf classes missing final keyword in %d file(s): %s',
                count($violations),
                implode(', ', $violations),
            ),
        );
    }

    /**
     * @test
     */
    public function all_source_files_have_strict_types(): void
    {
        $sourceDir = __DIR__ . '/../src';
        $violations = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getRealPath());
            if ($content === false) {
                continue;
            }

            if (! str_contains($content, 'declare(strict_types=1)')) {
                $relative = str_replace($sourceDir . '/', '', $file->getRealPath());
                $violations[] = $relative;
            }
        }

        $this->assertEmpty(
            $violations,
            sprintf(
                'Files missing declare(strict_types=1) in %d file(s): %s',
                count($violations),
                implode(', ', $violations),
            ),
        );
    }

    /**
     * @test
     */
    public function exception_base_classes_are_intentionally_non_final(): void
    {
        // Base exceptions must be non-final to allow subclassing
        $baseExceptions = [
            'src/Exceptions/AnalyticsException.php',
        ];

        foreach ($baseExceptions as $relative) {
            $path = __DIR__ . '/../' . $relative;
            $this->assertFileExists($path, "Base exception file must exist: {$relative}");

            $content = file_get_contents($path);
            $this->assertNotFalse($content);
            $this->assertStringContainsString(
                'abstract class',
                $content,
                "Base exception {$relative} must be abstract (non-final) to allow subclassing",
            );
        }
    }

    /**
     * @test
     */
    public function constructor_void_fixes_are_applied(): void
    {
        // Verify the 5 specific files that were fixed in Phase 30
        $fixedFiles = [
            'src/Events/Engagement/ClientErrorEvent.php',
            'src/Events/SaaS/ActivationEvent.php',
            'src/Events/SaaS/RetentionCohortEvent.php',
            'src/Services/SaaSReadinessAssessment.php',
            'src/Support/SaaSEventHelpers.php',
        ];

        foreach ($fixedFiles as $relative) {
            $path = __DIR__ . '/../' . $relative;
            $this->assertFileExists($path, "Fixed file must exist: {$relative}");

            $content = file_get_contents($path);
            $this->assertNotFalse($content);

            // Find __construct signature
            $idx = strpos($content, 'public function __construct');
            $this->assertNotFalse($idx, "File must have __construct: {$relative}");

            $braceIdx = strpos($content, '{', $idx);
            $this->assertNotFalse($braceIdx, "Constructor must have body: {$relative}");

            $signature = substr($content, $idx, $braceIdx - $idx);

            $this->assertStringContainsString(
                ': void',
                $signature,
                "Constructor in {$relative} must have :void return type",
            );
        }
    }

    /**
     * @test
     */
    public function governance_command_is_final(): void
    {
        $path = __DIR__ . '/../src/Console/Commands/AnalyticsGovernanceCommand.php';
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertNotFalse($content);

        $this->assertStringContainsString(
            'final class AnalyticsGovernanceCommand',
            $content,
            'AnalyticsGovernanceCommand must be final',
        );
    }
}
