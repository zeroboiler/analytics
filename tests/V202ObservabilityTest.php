<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;

/**
 * V202.0.0 — Observability Dashboard + Event Flow Analyzer test suite.
 *
 * Validates:
 * - AnalyticsObservabilityCommand file quality (strict_types, MIT header, final, @since, return types)
 * - EventFlowAnalyzerService file quality (strict_types, MIT header, final, @since, return types, methods)
 * - Config section `flow_analyzer` presence
 * - Version consistency across composer.json, package.json, AnalyticsEvent::VERSION
 * - Source file count >= 881 (previous baseline)
 * - Test file count >= 449 (previous baseline)
 * - ServiceProvider registration of new service and command
 *
 * @since 202.0.0
 */
final class V202ObservabilityTest extends TestCase
{
    /**
     * Test AnalyticsObservabilityCommand file exists and has quality markers.
     */
    public function testObservabilityCommandFileQuality(): void
    {
        $path = __DIR__ . '/../src/Console/Commands/AnalyticsObservabilityCommand.php';
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        // MIT license header
        $this->assertStringContainsString('This file is part of ZeroBoiler, licensed under the MIT license', $contents);

        // strict_types
        $this->assertStringContainsString('declare(strict_types=1)', $contents);

        // Final class
        $this->assertStringContainsString('final class AnalyticsObservabilityCommand', $contents);

        // @since annotation
        $this->assertStringContainsString('@since 202.0.0', $contents);

        // Command signature
        $this->assertStringContainsString("protected \$signature = 'zb:analytics:observability", $contents);

        // handle() method with return type
        $this->assertStringContainsString('public function handle(): int', $contents);

        // Key method: buildDashboard with return type
        $this->assertStringContainsString('private function buildDashboard(): array', $contents);

        // Key method: providerHealth with return type
        $this->assertStringContainsString('private function providerHealth(): array', $contents);

        // Key method: eventVolume with return type
        $this->assertStringContainsString('private function eventVolume(): array', $contents);

        // Key method: renderDashboard with void return
        $this->assertStringContainsString('private function renderDashboard(array $dashboard): void', $contents);

        // Uses AnalyticsManager
        $this->assertStringContainsString('use ZeroBoiler\\Analytics\\AnalyticsManager;', $contents);

        // Uses ConfigRepository
        $this->assertStringContainsString('use Illuminate\\Contracts\\Config\\Repository as ConfigRepository;', $contents);

        // Uses EventCatalog
        $this->assertStringContainsString('use ZeroBoiler\\Analytics\\Events\\EventCatalog;', $contents);

        // Constructor is :void
        $this->assertStringContainsString('public function __construct(AnalyticsManager $manager, ConfigRepository $config)', $contents);

        // --json option
        $this->assertStringContainsString("'--json'", $contents);

        // --category option
        $this->assertStringContainsString("'--category='", $contents);
    }

    /**
     * Test EventFlowAnalyzerService file exists and has quality markers.
     */
    public function testEventFlowAnalyzerServiceFileQuality(): void
    {
        $path = __DIR__ . '/../src/Services/EventFlowAnalyzerService.php';
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        // MIT license header
        $this->assertStringContainsString('This file is part of ZeroBoiler, licensed under the MIT license', $contents);

        // strict_types
        $this->assertStringContainsString('declare(strict_types=1)', $contents);

        // Final class
        $this->assertStringContainsString('final class EventFlowAnalyzerService', $contents);

        // @since annotation
        $this->assertStringContainsString('@since 202.0.0', $contents);

        // Namespace
        $this->assertStringContainsString('namespace ZeroBoiler\\Analytics\\Services;', $contents);

        // Constructor is :void
        $this->assertStringContainsString('public function __construct(CacheRepository $cache, ConfigRepository $config)', $contents);

        // isEnabled() method
        $this->assertStringContainsString('public function isEnabled(): bool', $contents);

        // analyze() method
        $this->assertStringContainsString('public function analyze(): array', $contents);

        // graphMetrics() method
        $this->assertStringContainsString('public function graphMetrics(): array', $contents);

        // detectBottlenecks() method
        $this->assertStringContainsString('public function detectBottlenecks(): array', $contents);

        // detectBridges() method
        $this->assertStringContainsString('public function detectBridges(): array', $contents);

        // detectClusters() method
        $this->assertStringContainsString('public function detectClusters(): array', $contents);

        // criticalPathsReport() method
        $this->assertStringContainsString('public function criticalPathsReport(): array', $contents);

        // flowHealthScore() method
        $this->assertStringContainsString('public function flowHealthScore(): array', $contents);

        // ancestorsOf() method
        $this->assertStringContainsString('public function ancestorsOf(string $event, int $depth = 1): array', $contents);

        // descendantsOf() method
        $this->assertStringContainsString('public function descendantsOf(string $event, int $depth = 1): array', $contents);

        // pathsBetween() method
        $this->assertStringContainsString('public function pathsBetween(string $from, string $to, int $maxDepth = 8): array', $contents);

        // clearCache() method
        $this->assertStringContainsString('public function clearCache(): void', $contents);

        // Uses EventCatalog
        $this->assertStringContainsString('use ZeroBoiler\\Analytics\\Events\\EventCatalog;', $contents);

        // Uses CacheRepository
        $this->assertStringContainsString('use Illuminate\\Contracts\\Cache\\Repository as CacheRepository;', $contents);

        // Cache prefix constant
        $this->assertStringContainsString("private const CACHE_PREFIX = 'zb_flow_analyzer:';", $contents);

        // Bottleneck detection uses score threshold
        $this->assertStringContainsString('bottleneck_score', $contents);

        // Flow health score grade computation (A-F)
        $this->assertStringContainsString("'A'", $contents);
        $this->assertStringContainsString("'F'", $contents);
    }

    /**
     * Test config flow_analyzer section exists.
     */
    public function testConfigFlowAnalyzerSection(): void
    {
        $path = __DIR__ . '/../config/zeroboiler.php';
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        $this->assertStringContainsString("'flow_analyzer'", $contents);
        $this->assertStringContainsString('ANALYTICS_FLOW_ANALYZER_ENABLED', $contents);
        $this->assertStringContainsString('ANALYTICS_FLOW_ANALYZER_CACHE_TTL', $contents);
    }

    /**
     * Test version consistency across all version sources.
     */
    public function testVersionConsistency(): void
    {
        $expectedVersion = '202.0.0';

        // AnalyticsEvent::VERSION
        $eventContents = file_get_contents(__DIR__ . '/../src/DTO/AnalyticsEvent.php');
        $this->assertNotFalse($eventContents);
        $this->assertStringContainsString("public const VERSION = '{$expectedVersion}';", $eventContents);

        // composer.json
        $composerContents = file_get_contents(__DIR__ . '/../composer.json');
        $this->assertNotFalse($composerContents);
        $composer = json_decode($composerContents, true);
        $this->assertIsArray($composer);
        $this->assertSame($expectedVersion, $composer['version']);

        // package.json
        $packageContents = file_get_contents(__DIR__ . '/../package.json');
        $this->assertNotFalse($packageContents);
        $package = json_decode($packageContents, true);
        $this->assertIsArray($package);
        $this->assertSame($expectedVersion, $package['version']);

        // README badge
        $readmeContents = file_get_contents(__DIR__ . '/../README.md');
        $this->assertNotFalse($readmeContents);
        $this->assertStringContainsString("version-{$expectedVersion}", $readmeContents);
    }

    /**
     * Test ServiceProvider registers new service and command.
     */
    public function testServiceProviderRegistration(): void
    {
        $path = __DIR__ . '/../src/AnalyticsServiceProvider.php';
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        // Import AnalyticsObservabilityCommand
        $this->assertStringContainsString('use ZeroBoiler\\Analytics\\Console\\Commands\\AnalyticsObservabilityCommand;', $contents);

        // Command registration
        $this->assertStringContainsString('AnalyticsObservabilityCommand::class,', $contents);

        // Service registration
        $this->assertStringContainsString('EventFlowAnalyzerService::class', $contents);
        $this->assertStringContainsString('new \\ZeroBoiler\\Analytics\\Services\\EventFlowAnalyzerService(', $contents);
    }

    /**
     * Test source file count is above baseline.
     */
    public function testSourceFileCountBaseline(): void
    {
        $sourceFiles = glob(__DIR__ . '/../src/**/*.php', GLOB_BRACE);
        $count = is_array($sourceFiles) ? count($sourceFiles) : 0;

        $this->assertGreaterThanOrEqual(881, $count, "Source file count ({$count}) is below baseline of 881");
    }

    /**
     * Test test file count is above baseline.
     */
    public function testTestFileCountBaseline(): void
    {
        $testFiles = glob(__DIR__ . '/../*.php', GLOB_BRACE);
        $testCount = is_array($testFiles) ? count($testFiles) : 0;

        $this->assertGreaterThanOrEqual(449, $testCount, "Test file count ({$testCount}) is below baseline of 449");
    }

    /**
     * Test README has v202.0.0 changelog entry.
     */
    public function testReadmeChangelogEntry(): void
    {
        $path = __DIR__ . '/../README.md';
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        $this->assertStringContainsString('What\'s New in v202.0.0', $contents);
        $this->assertStringContainsString('AnalyticsObservabilityCommand', $contents);
        $this->assertStringContainsString('EventFlowAnalyzerService', $contents);
        $this->assertStringContainsString('flow_analyzer', $contents);
        $this->assertStringContainsString('observability dashboard', $contents);
    }

    /**
     * Test observability command has all required dashboard sections.
     */
    public function testObservabilityCommandDashboardSections(): void
    {
        $path = __DIR__ . '/../src/Console/Commands/AnalyticsObservabilityCommand.php';
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        // All required dashboard sections
        $requiredSections = [
            'provider_health',
            'event_volume',
            'catalog_summary',
            'queue_health',
            'dedup_stats',
            'consent_coverage',
            'identity_stats',
            'pipeline_config',
            'flow_patterns',
            'anomaly_status',
            'alert_status',
        ];

        foreach ($requiredSections as $section) {
            $this->assertStringContainsString(
                "'{$section}'",
                $contents,
                "Missing dashboard section: {$section}"
            );
        }
    }

    /**
     * Test flow analyzer service has proper docblocks for key methods.
     */
    public function testFlowAnalyzerDocblocks(): void
    {
        $path = __DIR__ . '/../src/Services/EventFlowAnalyzerService.php';
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        // Class-level docblock references
        $this->assertStringContainsString('EventCatalog::causalEdges', $contents);
        $this->assertStringContainsString('EventCatalog::eventDependencyGraph', $contents);

        // Key metric names in docblocks
        $this->assertStringContainsString('bottleneck', $contents);
        $this->assertStringContainsString('bridge', $contents);
        $this->assertStringContainsString('cluster', $contents);
        $this->assertStringContainsString('health score', $contents);
    }
}
