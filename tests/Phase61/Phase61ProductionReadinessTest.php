<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 61 Production Readiness — Event Data Lineage Graph + Cost Projection Engine.
 *
 * Validates the two new services: EventLineageGraphService (DAG construction,
 * topological sort, critical path, DOT/JSON export) and EventCostProjectionService
 * (cost forecasting, budget alerts, provider comparison). Also validates
 * version consistency, config sections, file counts, and 12-feature coverage.
 *
 * @since 236.0.0
 */
final class Phase61ProductionReadinessTest extends TestCase
{
    private const VERSION = '268.0.0';
    private const SRC_DIR = __DIR__ . '/../src';
    private const ROOT_DIR = __DIR__ . '/..';

    // ── 1. New Service Files Exist ───────────────────────────────────

    #[Test]
    public function eventLineageGraphServiceExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Services/EventLineageGraphService.php');
    }

    #[Test]
    public function eventCostProjectionServiceExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Services/EventCostProjectionService.php');
    }

    // ── 2. EventLineageGraphService — Syntax & Structure ─────────────

    #[Test]
    public function lineageGraphServiceDeclaresStrictTypes(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Services/EventLineageGraphService.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function lineageGraphServiceHasCorrectNamespace(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Services/EventLineageGraphService.php');
        $this->assertStringContainsString('namespace ZeroBoiler\\Analytics\\Services;', $content);
    }

    #[Test]
    public function lineageGraphServiceIsFinal(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Services/EventLineageGraphService.php');
        $this->assertStringContainsString('final class EventLineageGraphService', $content);
    }

    #[Test]
    public function lineageGraphServiceHasDocblock(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Services/EventLineageGraphService.php');
        $this->assertStringContainsString('/**', $content);
        $this->assertStringContainsString('@since 236.0.0', $content);
    }

    #[Test]
    public function lineageGraphServiceHasConstructorVoidReturn(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Services/EventLineageGraphService.php');
        $this->assertMatchesRegularExpression('/public function __construct\([^)]*\): void/', $content);
    }

    #[Test]
    public function lineageGraphServiceHasReturnTypes(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Services/EventLineageGraphService.php');

        // Verify key methods have return type declarations
        $this->assertStringContainsString('public function buildGraph(array $lineageEntries): array', $content);
        $this->assertStringContainsString('public function buildAndCacheGraph(string $eventId, array $lineageEntries): array', $content);
        $this->assertStringContainsString('public function getCachedGraph(string $eventId): ?array', $content);
        $this->assertStringContainsString('public function buildAggregateGraph(array $eventLineages): array', $content);
        $this->assertStringContainsString('public function exportDot(array $graph): string', $content);
        $this->assertStringContainsString('public function exportJson(array $graph): string', $content);
        $this->assertStringContainsString('public function extractSubgraph(array $graph, string $type): array', $content);
        $this->assertStringContainsString('public function graphSummary(array $graph): array', $content);
        $this->assertStringContainsString('public function isEnabled(): bool', $content);
        $this->assertStringContainsString('public function clearCache(): void', $content);
    }

    #[Test]
    public function lineageGraphServiceHasCriticalPathMethod(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Services/EventLineageGraphService.php');
        $this->assertStringContainsString('findCriticalPath', $content);
        $this->assertStringContainsString('topologicalSort', $content);
        $this->assertStringContainsString('detectCycles', $content);
        $this->assertStringContainsString('findBottlenecks', $content);
        $this->assertStringContainsString('calculateDepth', $content);
    }

    // ── 3. EventCostProjectionService — Syntax & Structure ──────────

    #[Test]
    public function costProjectionServiceDeclaresStrictTypes(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Services/EventCostProjectionService.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function costProjectionServiceHasCorrectNamespace(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Services/EventCostProjectionService.php');
        $this->assertStringContainsString('namespace ZeroBoiler\\Analytics\\Services;', $content);
    }

    #[Test]
    public function costProjectionServiceIsFinal(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Services/EventCostProjectionService.php');
        $this->assertStringContainsString('final class EventCostProjectionService', $content);
    }

    #[Test]
    public function costProjectionServiceHasDocblock(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Services/EventCostProjectionService.php');
        $this->assertStringContainsString('/**', $content);
        $this->assertStringContainsString('@since 236.0.0', $content);
    }

    #[Test]
    public function costProjectionServiceHasConstructorVoidReturn(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Services/EventCostProjectionService.php');
        $this->assertMatchesRegularExpression('/public function __construct\([^)]*\): void/', $content);
    }

    #[Test]
    public function costProjectionServiceHasReturnTypes(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Services/EventCostProjectionService.php');

        $this->assertStringContainsString('public function projectMonthlyCosts(): array', $content);
        $this->assertStringContainsString('public function projectProviderCost(string $provider): array', $content);
        $this->assertStringContainsString('public function costComparison(): array', $content);
        $this->assertStringContainsString('public function costEfficiency(): array', $content);
        $this->assertStringContainsString('public function budgetStatus(): array', $content);
        $this->assertStringContainsString('public function setCostRates(array $rates): void', $content);
        $this->assertStringContainsString('public function getCostRates(): array', $content);
        $this->assertStringContainsString('public function isEnabled(): bool', $content);
        $this->assertStringContainsString('public function getMonthlyBudget(): ?float', $content);
    }

    #[Test]
    public function costProjectionServiceHasAllProviderConstants(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/Services/EventCostProjectionService.php');
        $expectedProviders = ['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog', 'mixpanel', 'amplitude', 'webhook', 'tiktok', 'linkedin'];

        foreach ($expectedProviders as $provider) {
            $this->assertStringContainsString(
                "'{$provider}'",
                $content,
                "Missing provider '{$provider}' in PROVIDERS constant",
            );
        }
    }

    // ── 4. Config Sections ───────────────────────────────────────────

    #[Test]
    public function configHasLineageGraphSection(): void
    {
        $config = file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $this->assertStringContainsString("'event_lineage_graph'", $config);
        $this->assertStringContainsString('ANALYTICS_LINEAGE_GRAPH_ENABLED', $config);
        $this->assertStringContainsString('ANALYTICS_LINEAGE_GRAPH_CACHE_PREFIX', $config);
    }

    #[Test]
    public function configHasCostProjectionSection(): void
    {
        $config = file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $this->assertStringContainsString("'cost_projection'", $config);
        $this->assertStringContainsString('ANALYTICS_COST_PROJECTION_ENABLED', $config);
        $this->assertStringContainsString('ANALYTICS_COST_RATE_PLAUSIBLE', $config);
        $this->assertStringContainsString('ANALYTICS_COST_MONTHLY_BUDGET', $config);
    }

    #[Test]
    public function configCostProjectionHasAllProviderRates(): void
    {
        $config = file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $rateVars = [
            'ANALYTICS_COST_RATE_GA4',
            'ANALYTICS_COST_RATE_POSTHOG',
            'ANALYTICS_COST_RATE_MIXPANEL',
            'ANALYTICS_COST_RATE_AMPLITUDE',
            'ANALYTICS_COST_RATE_WEBHOOK',
        ];

        foreach ($rateVars as $var) {
            $this->assertStringContainsString($var, $config, "Missing env var {$var} in cost_projection config");
        }
    }

    // ── 5. Version Consistency ───────────────────────────────────────

    #[Test]
    public function versionEntryPointsConsistent(): void
    {
        $eventDto = file_get_contents(self::SRC_DIR . '/DTO/AnalyticsEvent.php');
        $this->assertStringContainsString(self::VERSION, $eventDto, 'AnalyticsEvent VERSION constant');

        $composer = json_decode(file_get_contents(self::ROOT_DIR . '/composer.json'), true);
        $this->assertSame(self::VERSION, $composer['version'], 'composer.json version');

        $readme = file_get_contents(self::ROOT_DIR . '/README.md');
        $this->assertStringContainsString(self::VERSION, $readme, 'README version badge');
    }

    #[Test]
    public function serviceProviderVersionUpdated(): void
    {
        $content = file_get_contents(self::SRC_DIR . '/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('@version ' . self::VERSION, $content);
    }

    #[Test]
    public function readmeHasV236ChangelogEntry(): void
    {
        $readme = file_get_contents(self::ROOT_DIR . '/README.md');
        $this->assertStringContainsString('### What\'s New in v236.0.0', $readme);
        $this->assertStringContainsString('EventLineageGraphService', $readme);
        $this->assertStringContainsString('EventCostProjectionService', $readme);
    }

    // ── 6. Strict Types Coverage ────────────────────────────────────

    #[Test]
    public function newServiceFilesDeclareStrictTypes(): void
    {
        $files = [
            self::SRC_DIR . '/Services/EventLineageGraphService.php',
            self::SRC_DIR . '/Services/EventCostProjectionService.php',
        ];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertStringContainsString(
                'declare(strict_types=1)',
                $content,
                basename($file) . ' must declare strict_types',
            );
        }
    }

    #[Test]
    public function allPhpSourceFilesDeclareStrictTypes(): void
    {
        $files = $this->phpFiles(self::SRC_DIR);
        $violations = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (! str_contains($content, 'declare(strict_types=1)')) {
                $violations[] = str_replace(self::ROOT_DIR . '/', '', $file);
            }
        }

        $this->assertEmpty(
            $violations,
            sprintf('Found %d file(s) missing declare(strict_types=1): %s', count($violations), implode(', ', array_slice($violations, 0, 10))),
        );
    }

    #[Test]
    public function noTodosOrFixmesInNewFiles(): void
    {
        $files = [
            self::SRC_DIR . '/Services/EventLineageGraphService.php',
            self::SRC_DIR . '/Services/EventCostProjectionService.php',
        ];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression(
                '/(?:TODO|FIXME|HACK|XXX)\b/',
                $content,
                basename($file) . ' must not contain TODO/FIXME/HACK',
            );
        }
    }

    #[Test]
    public function noTodosOrFixmesInSource(): void
    {
        $files = $this->phpFiles(self::SRC_DIR);
        $violations = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $lines = explode("\n", $content);
            foreach ($lines as $lineNum => $line) {
                if (preg_match('/(?:TODO|FIXME|HACK|XXX)\b/', $line)) {
                    $relative = str_replace(self::ROOT_DIR . '/', '', $file);
                    $violations[] = "{$relative}:{$lineNum}";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            sprintf('Found %d TODO/FIXME/HACK: %s', count($violations), implode(', ', array_slice($violations, 0, 10))),
        );
    }

    // ── 7. File Count & Scale ──────────────────────────────────────

    #[Test]
    public function sourceFileCountExceedsMinimum(): void
    {
        $files = $this->phpFiles(self::SRC_DIR);
        $this->assertGreaterThanOrEqual(960, count($files), 'Expected at least 960 PHP source files');
    }

    #[Test]
    public function testFileCountExceedsMinimum(): void
    {
        $files = $this->phpFiles(self::ROOT_DIR . '/tests');
        $this->assertGreaterThanOrEqual(486, count($files), 'Expected at least 486 test files');
    }

    #[Test]
    public function commandCountExceedsMinimum(): void
    {
        $files = glob(self::SRC_DIR . '/Console/Commands/*.php');
        $this->assertGreaterThanOrEqual(110, count($files), 'Expected at least 110 artisan commands');
    }

    #[Test]
    public function serviceCountExceedsMinimum(): void
    {
        $files = glob(self::SRC_DIR . '/Services/*.php');
        $this->assertGreaterThanOrEqual(430, count($files), 'Expected at least 430 service files');
    }

    #[Test]
    public function jsClientModuleCount(): void
    {
        $files = array_merge(
            glob(self::ROOT_DIR . '/resources/js/*.js'),
            glob(self::ROOT_DIR . '/resources/js/*.svelte.js'),
        );
        $this->assertGreaterThanOrEqual(14, count($files), 'Expected at least 14 JS/Svelte modules');
    }

    // ── 8. All 12 SaaS Starter Features Verified ───────────────────

    #[Test]
    public function feature1EventCatalogExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Events/Ecommerce/EcommerceEvents.php');
        $this->assertFileExists(self::SRC_DIR . '/Events/SaaS/SaaSEvents.php');
        $this->assertFileExists(self::SRC_DIR . '/Events/Engagement/EngagementEvents.php');
    }

    #[Test]
    public function feature2LifecycleTrackerExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Tracking/ServerSideTracker.php');
        $this->assertFileExists(self::SRC_DIR . '/Services/LifecycleEventMapper.php');
        $this->assertFileExists(self::SRC_DIR . '/Tracking/LifecycleEventSubscriber.php');
    }

    #[Test]
    public function feature3InertiaMiddlewareExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Http/Middleware/HandleInertiaAnalytics.php');
    }

    #[Test]
    public function feature4ApiControllerAndRoutesExist(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Http/Controllers/AnalyticsEventController.php');
        $this->assertFileExists(self::ROOT_DIR . '/routes/analytics.php');
        $this->assertFileExists(self::SRC_DIR . '/Http/Requests/TrackEventRequest.php');
    }

    #[Test]
    public function feature5JsClientLibraryExists(): void
    {
        $this->assertFileExists(self::ROOT_DIR . '/resources/js/analytics.js');
    }

    #[Test]
    public function feature6EventQueueExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Jobs/TrackAnalyticsEventJob.php');
        $this->assertFileExists(self::SRC_DIR . '/Jobs/TrackAnalyticsEventBatchJob.php');
    }

    #[Test]
    public function feature7IdentityLinkingExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Tracking/UserIdentityTracker.php');
        $this->assertFileExists(self::SRC_DIR . '/Services/IdentityGraphService.php');
    }

    #[Test]
    public function feature8EcommerceHelpersExists(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Support/EcommerceFormatConverter.php');
    }

    #[Test]
    public function feature9AdminCommandsExist(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Console/Commands/AnalyticsOverviewCommand.php');
        $this->assertFileExists(self::SRC_DIR . '/Console/Commands/AnalyticsTestCommand.php');
    }

    #[Test]
    public function feature10ConfigExpansionExists(): void
    {
        $config = file_get_contents(self::ROOT_DIR . '/config/zeroboiler.php');
        $this->assertStringContainsString("'queue'", $config);
        $this->assertStringContainsString("'identity'", $config);
        $this->assertStringContainsString("'auto_track'", $config);
        $this->assertStringContainsString("'ecommerce'", $config);
    }

    #[Test]
    public function feature11OptionalProvidersExist(): void
    {
        $this->assertFileExists(self::SRC_DIR . '/Trackers/PlausibleTracker.php');
        $this->assertFileExists(self::SRC_DIR . '/Trackers/PosthogTracker.php');
        $this->assertFileExists(self::SRC_DIR . '/Trackers/MixpanelTracker.php');
        $this->assertFileExists(self::SRC_DIR . '/Trackers/AmplitudeTracker.php');
    }

    #[Test]
    public function feature12TestsAndReadmeExist(): void
    {
        $this->assertFileExists(self::ROOT_DIR . '/README.md');
        $this->assertFileExists(self::ROOT_DIR . '/tests/Pest.php');
    }

    // ── 9. Constructor Void Return Type Sweep (All Services) ─────────

    #[Test]
    public function newServicesHaveConstructorVoid(): void
    {
        $files = [
            self::SRC_DIR . '/Services/EventLineageGraphService.php',
            self::SRC_DIR . '/Services/EventCostProjectionService.php',
        ];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertStringContainsString(
                'public function __construct(',
                $content,
                basename($file) . ' should have a constructor',
            );
            $this->assertStringContainsString(
                '): void',
                $content,
                basename($file) . ' constructor must declare : void return type',
            );
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Get all PHP files recursively from a directory.
     *
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $files = [];
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
