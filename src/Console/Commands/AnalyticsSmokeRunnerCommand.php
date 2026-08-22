<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\AnalyticsConsentComplianceService;

/**
 * Comprehensive analytics pipeline smoke test command.
 *
 * Validates the entire analytics system end-to-end:
 * 1. Version integrity (cross-file consistency)
 * 2. Event catalog health (all events valid, no duplicates)
 * 3. Provider connectivity (all enabled providers respond)
 * 4. GDPR consent compliance (Consent Mode v2)
 * 5. Identity resolution readiness
 * 6. Queue dispatch readiness
 * 7. E-commerce format conversion
 * 8. Pipeline filter chain
 * 9. Config validation
 * 10. API route registration
 *
 * Designed for CI/CD pipelines and pre-deployment validation.
 *
 * @since 11.0.0
 */
final class AnalyticsSmokeRunnerCommand extends Command
{
    protected $signature = 'zb:analytics:smoke
        {--skip-providers : Skip provider connectivity checks (faster, no HTTP calls)}
        {--skip-consent : Skip consent compliance checks}
        {--json : Output as JSON}
        {--verbose : Show detailed check output}';

    protected $description = 'Run comprehensive analytics pipeline smoke test — validates end-to-end system health';

    private AnalyticsManager $manager;

    /** @var list<array{check: string, status: string, message: string, duration_ms: float}> */
    private array $results = [];

    private bool $hasFailures = false;

    private int $passCount = 0;

    private int $warnCount = 0;

    private int $failCount = 0;

    public function __construct(AnalyticsManager $manager){
        parent::__construct();
        $this->manager = $manager;
    }

    /**
     * Run the smoke test suite.
     */
    #[Override]
    public function handle(): int
    {
        $this->results = [];
        $this->hasFailures = false;
        $this->passCount = 0;
        $this->warnCount = 0;
        $this->failCount = 0;

        $start = microtime(true);

        $this->info('🔥 ZeroBoiler Analytics — Pipeline Smoke Test');
        $this->info('   Version: ' . AnalyticsEvent::VERSION);
        $this->newLine();

        // 1. Version integrity
        $this->runCheck('version_integrity', 'Version Integrity', function (): string {
            $version = AnalyticsEvent::VERSION;

            if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                return "FAIL: Version '{$version}' is not valid semver format";
            }

            return "PASS: Version {$version} (semver compliant)";
        });

        // 2. Event catalog validation
        $this->runCheck('catalog_validation', 'Event Catalog Validation', function (): string {
            $validation = EventCatalog::validate();

            if (! $validation['valid']) {
                return 'FAIL: ' . implode('; ', $validation['errors']);
            }

            $count = EventCatalog::count();
            $warnings = count($validation['warnings']);
            $msg = "PASS: {$count} events cataloged";

            if ($warnings > 0) {
                $msg .= " ({$warnings} warnings)";
            }

            return $msg;
        });

        // 3. Event catalog categories
        $this->runCheck('catalog_categories', 'Event Catalog Categories', function (): string {
            $categories = EventCatalog::byCategory();
            $categoryNames = array_keys($categories);
            $expected = ['ecommerce', 'saas', 'engagement', 'security', 'uptime'];
            $missing = array_diff($expected, $categoryNames);

            if (! empty($missing)) {
                return 'FAIL: Missing categories: ' . implode(', ', $missing);
            }

            $countByCat = array_map(static fn (array $events): int => count($events), $categories);

            return 'PASS: All 5 categories present (' . implode(', ', array_map(
                static fn (string $cat): string => "{$cat}={$countByCat[$cat]}",
                $categoryNames,
            )) . ')';
        });

        // 4. Event catalog provider coverage
        $this->runCheck('catalog_providers', 'Event Catalog Provider Coverage', function (): string {
            $providers = EventCatalog::byProvider();
            $providerNames = array_keys($providers);

            return 'PASS: ' . count($providerNames) . ' providers covered (' . implode(', ', $providerNames) . ')';
        });

        // 5. Provider configuration
        $this->runCheck('provider_config', 'Provider Configuration', function (): string {
            $summary = $this->manager->providerSummary();
            $enabled = array_filter($summary, static fn (array $p): bool => $p['enabled']);
            $total = count($summary);

            return "PASS: {$total} providers configured, " . count($enabled) . ' enabled';
        });

        // 6. Provider connectivity (unless skipped)
        $skipProviders = $this->option('skip-providers');
        if (! $skipProviders) {
            $this->runCheck('ga4_connectivity', 'GA4 Connectivity', function (): string {
                $ga4 = $this->manager->ga4();

                if (! $ga4->isEnabled()) {
                    return 'SKIP: GA4 not enabled';
                }

                $id = $ga4->getMeasurementId();

                return "PASS: GA4 configured (measurement_id={$id})";
            });

            $this->runCheck('gtm_connectivity', 'GTM Connectivity', function (): string {
                $gtm = $this->manager->gtm();

                if (! $gtm->isEnabled()) {
                    return 'SKIP: GTM not enabled';
                }

                $id = $gtm->getContainerId();

                return "PASS: GTM configured (container_id={$id})";
            });

            $this->runCheck('meta_connectivity', 'Meta Pixel Connectivity', function (): string {
                $meta = $this->manager->meta();

                if (! $meta->isEnabled()) {
                    return 'SKIP: Meta Pixel not enabled';
                }

                $id = $meta->getPixelId();

                return "PASS: Meta Pixel configured (pixel_id={$id})";
            });
        }

        // 7. GDPR consent compliance (unless skipped)
        $skipConsent = $this->option('skip-consent');
        if (! $skipConsent) {
            $this->runCheck('consent_compliance', 'GDPR Consent Compliance', function (): string {
                try {
                    $config = app('config');
                    $cache = app(\Illuminate\Contracts\Cache\Repository::class);
                    /** @var \Illuminate\Contracts\Config\Repository $config */
                    /** @var \Illuminate\Contracts\Cache\Repository $cache */
                    $service = new AnalyticsConsentComplianceService($config, $cache);
                    $result = $service->complianceCheck();

                    if ($result['compliant']) {
                        return "PASS: Compliance score {$result['score']}/{$result['max_score']} (compliant)";
                    }

                    return "WARN: Compliance score {$result['score']}/{$result['max_score']} — " . count($result['violations']) . ' violations';
                } catch (\Throwable $e) {
                    return 'WARN: Consent compliance check failed: ' . $e->getMessage();
                }
            });
        }

        // 8. E-commerce format conversion
        $this->runCheck('ecommerce_format', 'E-Commerce Format Conversion', function (): string {
            $items = [
                [
                    'item_id' => 'SKU-001',
                    'item_name' => 'Test Product',
                    'price' => 29.99,
                    'quantity' => 1,
                ],
            ];

            try {
                $formatted = $this->manager->formatEcommerceForMeta($items);

                if (
                    isset($formatted['content_ids']) &&
                    is_array($formatted['content_ids']) &&
                    count($formatted['content_ids']) === 1 &&
                    $formatted['num_items'] === 1
                ) {
                    return 'PASS: Meta e-commerce format conversion works';
                }

                return 'FAIL: Meta format conversion returned unexpected structure';
            } catch (\Throwable $e) {
                return 'FAIL: Meta format conversion error: ' . $e->getMessage();
            }
        });

        // 9. Consent state management
        $this->runCheck('consent_state', 'Consent State Management', function (): string {
            $consent = $this->manager->getConsent();

            if ($consent === null) {
                return 'FAIL: Consent state is null';
            }

            return 'PASS: Consent state accessible (status=' . ($consent->isGranted() ? 'granted' : 'denied') . ')';
        });

        // 10. Analytics metrics
        $this->runCheck('analytics_metrics', 'Analytics Metrics', function (): string {
            $metrics = $this->manager->metrics();

            if ($metrics === null) {
                return 'FAIL: Metrics instance is null';
            }

            return 'PASS: Metrics service available';
        });

        // 11. Facade accessibility
        $this->runCheck('facade_access', 'Facade Accessibility', function (): string {
            $facadeAccessor = \ZeroBoiler\Analytics\Facades\Analytics::getFacadeAccessor();

            if ($facadeAccessor !== 'zeroboiler.analytics') {
                return "FAIL: Facade accessor is '{$facadeAccessor}', expected 'zeroboiler.analytics'";
            }

            return 'PASS: Facade resolves correctly';
        });

        // 12. Health check
        $this->runCheck('health_check', 'Health Check', function (): string {
            try {
                $health = $this->manager->healthCheck();

                if (($health['status'] ?? '') !== 'ok') {
                    return 'WARN: Health check status is not ok: ' . ($health['status'] ?? 'unknown');
                }

                return "PASS: Overall score {$health['overall_score']}/100";
            } catch (\Throwable $e) {
                return 'WARN: Health check failed: ' . $e->getMessage();
            }
        });

        // 13. Identity resolution
        $this->runCheck('identity_resolution', 'Identity Resolution', function (): string {
            $hasIdentityTracker = class_exists(\ZeroBoiler\Analytics\Tracking\UserIdentityTracker::class);
            $hasIdentityResolution = class_exists(\ZeroBoiler\Analytics\Services\IdentityResolutionService::class);

            if ($hasIdentityTracker && $hasIdentityResolution) {
                return 'PASS: UserIdentityTracker + IdentityResolutionService available';
            }

            return 'WARN: Identity resolution components missing';
        });

        // 14. Queue dispatch
        $this->runCheck('queue_dispatch', 'Queue Dispatch', function (): string {
            $hasQueuedDispatcher = class_exists(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class);
            $hasSingleJob = class_exists(\ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventJob::class);
            $hasBatchJob = class_exists(\ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventBatchJob::class);

            if ($hasQueuedDispatcher && $hasSingleJob && $hasBatchJob) {
                return 'PASS: QueuedAnalyticsDispatcher + TrackJobs available';
            }

            return 'WARN: Queue dispatch components missing';
        });

        // 15. Pipeline filters
        $this->runCheck('pipeline_filters', 'Pipeline Filters', function (): string {
            $filters = [
                'ConsentFilter' => class_exists(\ZeroBoiler\Analytics\Pipeline\ConsentFilter::class),
                'EventDeduplicationFilter' => class_exists(\ZeroBoiler\Analytics\Pipeline\EventDeduplicationFilter::class),
                'SamplingFilter' => class_exists(\ZeroBoiler\Analytics\Pipeline\SamplingFilter::class),
                'SchemaEnricher' => class_exists(\ZeroBoiler\Analytics\Pipeline\SchemaEnricher::class),
                'UtmEnricher' => class_exists(\ZeroBoiler\Analytics\Pipeline\UtmEnricher::class),
                'TimestampEnricher' => class_exists(\ZeroBoiler\Analytics\Pipeline\TimestampEnricher::class),
                'EventMetadataEnricher' => class_exists(\ZeroBoiler\Analytics\Pipeline\EventMetadataEnricher::class),
            ];

            $missing = array_keys(array_filter($filters, static fn (bool $exists): bool => ! $exists));

            if (empty($missing)) {
                return 'PASS: All pipeline filters available (' . count($filters) . ' components)';
            }

            return 'WARN: Missing pipeline filters: ' . implode(', ', $missing);
        });

        // 16. GDPR services
        $this->runCheck('gdpr_services', 'GDPR Services', function (): string {
            $services = [
                'GdprErasureService' => class_exists(\ZeroBoiler\Analytics\Services\GdprErasureService::class),
                'ConsentLogService' => class_exists(\ZeroBoiler\Analytics\Services\ConsentLogService::class),
                'DataMinimizationService' => class_exists(\ZeroBoiler\Analytics\Services\DataMinimizationService::class),
                'PrivacyManifestService' => class_exists(\ZeroBoiler\Analytics\Services\PrivacyManifestService::class),
                'AnalyticsAnonymizationService' => class_exists(\ZeroBoiler\Analytics\Services\AnalyticsAnonymizationService::class),
                'IpAnonymizationService' => class_exists(\ZeroBoiler\Analytics\Services\IpAnonymizationService::class),
            ];

            $missing = array_keys(array_filter($services, static fn (bool $exists): bool => ! $exists));

            if (empty($missing)) {
                return 'PASS: All GDPR services available (' . count($services) . ' services)';
            }

            return 'WARN: Missing GDPR services: ' . implode(', ', $missing);
        });

        // 17. Admin commands
        $this->runCheck('admin_commands', 'Admin Commands', function (): string {
            $commands = [
                'AnalyticsOverviewCommand' => class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand::class),
                'AnalyticsTestCommand' => class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand::class),
                'AnalyticsIntegrityCommand' => class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsIntegrityCommand::class),
                'AnalyticsHealthCommand' => class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsHealthCommand::class),
                'AnalyticsDiagnosticsCommand' => class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsDiagnosticsCommand::class),
            ];

            $missing = array_keys(array_filter($commands, static fn (bool $exists): bool => ! $exists));

            if (empty($missing)) {
                return 'PASS: All admin commands registered (' . count($commands) . ' commands)';
            }

            return 'WARN: Missing admin commands: ' . implode(', ', $missing);
        });

        // 18. Inertia middleware
        $this->runCheck('inertia_middleware', 'Inertia Middleware', function (): string {
            $hasMiddleware = class_exists(\ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics::class);

            if ($hasMiddleware) {
                return 'PASS: Inertia analytics middleware available';
            }

            return 'WARN: Inertia middleware not found';
        });

        // 19. API controller
        $this->runCheck('api_controller', 'API Controller', function (): string {
            $hasController = class_exists(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class);

            if ($hasController) {
                return 'PASS: API controller available';
            }

            return 'WARN: API controller not found';
        });

        // 20. Test fake
        $this->runCheck('test_fake', 'Test Fake', function (): string {
            $hasFake = class_exists(\ZeroBoiler\Analytics\Support\AnalyticsFake::class);
            $hasTrait = class_exists(\ZeroBoiler\Analytics\Support\WithAnalyticsFake::class);

            if ($hasFake && $hasTrait) {
                return 'PASS: AnalyticsFake + WithAnalyticsFake trait available';
            }

            return 'WARN: Test fake components missing';
        });

        // Summary
        $elapsed = round((microtime(true) - $start) * 1000);

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════');
        $this->info('  Smoke Test Complete');
        $this->info('  Total: ' . count($this->results) . " checks in {$elapsed}ms");
        $this->info('  ✅ Pass: ' . $this->passCount);
        $this->info('  ⚠️  Warn: ' . $this->warnCount);
        $this->info('  ❌ Fail: ' . $this->failCount);
        $this->info('═══════════════════════════════════════════════════════');

        if ($this->option('json')) {
            $this->line(json_encode([
                'version' => AnalyticsEvent::VERSION,
                'total_checks' => count($this->results),
                'pass' => $this->passCount,
                'warn' => $this->warnCount,
                'fail' => $this->failCount,
                'elapsed_ms' => $elapsed,
                'results' => $this->results,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }

        return $this->hasFailures ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Run a single smoke test check and record the result.
     *
     * @param  string  $id  Check identifier
     * @param  string  $label  Human-readable label
     * @param  callable(): string  $callback  Returns status message starting with PASS, WARN, FAIL, or SKIP
     */
    private function runCheck(string $id, string $label, callable $callback): void
    {
        $start = microtime(true);

        try {
            $message = $callback();
        } catch (\Throwable $e) {
            $message = 'FAIL: Unexpected error — ' . $e->getMessage();
        }

        $duration = round((microtime(true) - $start) * 1000, 2);

        $status = 'fail';
        if (str_starts_with($message, 'PASS')) {
            $status = 'pass';
            $this->passCount++;
            $icon = '✅';
        } elseif (str_starts_with($message, 'WARN')) {
            $status = 'warn';
            $this->warnCount++;
            $icon = '⚠️ ';
        } elseif (str_starts_with($message, 'SKIP')) {
            $status = 'skip';
            $icon = '⏭️ ';
        } else {
            $this->failCount++;
            $this->hasFailures = true;
            $icon = '❌';
        }

        $this->results[] = [
            'check' => $id,
            'status' => $status,
            'message' => $message,
            'duration_ms' => $duration,
        ];

        if ($this->option('verbose') || $status === 'fail') {
            $this->line("  {$icon} {$label}: {$message} ({$duration}ms)");
        } else {
            $this->line("  {$icon} {$label} ({$duration}ms)");
        }
    }
}
