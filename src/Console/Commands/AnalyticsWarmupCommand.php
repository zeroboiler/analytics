<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\AnalyticsProviderTagManager;
use ZeroBoiler\Analytics\Services\EventComplianceScoringService;
use ZeroBoiler\Analytics\Services\EventPriorityCalculator;

/**
 * Analytics warmup command — validates configuration and pre-populates caches.
 *
 * Run after deployment to ensure analytics infrastructure is ready:
 * - Validates config structure and provider credentials
 * - Pre-populates event catalog caches
 * - Runs compliance scoring cache
 * - Checks provider connectivity
 * - Reports system readiness
 *
 * Intended for deployment pipelines: `php artisan analytics:warmup`
 *
 * @since 171.0.0
 */
final class AnalyticsWarmupCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:warmup
        {--skip-health : Skip provider health checks}
        {--skip-compliance : Skip compliance scoring cache}
        {--verbose : Show detailed output}';

    /** @var string */
    protected $description = 'Validate analytics config and warm up caches (post-deploy)';

    private int $warnings = 0;

    private int $errors = 0;

    /**
     * Execute the warmup command.
     */
    public function handle(
        ConfigRepository $config,
        CacheRepository $cache,
        AnalyticsManager $manager,
    ): int
    {
        $this->info('╔══════════════════════════════════════════════╗');
        $this->info('║   ZeroBoiler Analytics — Warmup v' . AnalyticsEvent::VERSION . '        ║');
        $this->info('╚══════════════════════════════════════════════╝');
        $this->newLine();

        $start = microtime(true);

        // 1. Config validation
        $this->section('Configuration Validation');
        $this->validateConfig($config);

        // 2. Event catalog warmup
        $this->section('Event Catalog Cache');
        $this->warmupEventCatalog();

        // 3. Provider readiness
        $this->section('Provider Readiness');
        $this->checkProviderReadiness($config, $manager);

        // 4. Compliance scoring (optional)
        if (! $this->option('skip-compliance')) {
            $this->section('Compliance Scoring Cache');
            $this->warmupComplianceCache($config, $cache);
        }

        // 5. Health check (optional)
        if (! $this->option('skip-health')) {
            $this->section('Provider Health Checks');
            $this->runHealthChecks($config, $cache, $manager);
        }

        // 6. Cache stats
        $this->section('Cache Summary');
        $this->displayCacheStats($cache);

        // Summary
        $elapsed = round(microtime(true) - $start, 3);
        $this->newLine();

        if ($this->errors > 0) {
            $this->error("Warmup completed with {$this->errors} error(s) and {$this->warnings} warning(s) in {$elapsed}s");

            return self::FAILURE;
        }

        if ($this->warnings > 0) {
            $this->warn("Warmup completed with {$this->warnings} warning(s) in {$elapsed}s");

            return self::SUCCESS;
        }

        $this->info("Warmup completed successfully in {$elapsed}s — all systems operational.");

        return self::SUCCESS;
    }

    // ── Steps ────────────────────────────────────────────────────────

    /**
     * Validate analytics configuration structure.
     */
    private function validateConfig(ConfigRepository $config): void
    {
        $analytics = $config->get('zeroboiler.analytics');

        if (! is_array($analytics)) {
            $this->error('  ✗ zeroboiler.analytics config missing or invalid');
            $this->errors++;

            return;
        }

        $this->check('  ✓ Config file loaded', true);

        // Required sections
        $required = ['ga4', 'gtm', 'meta_pixel', 'consent'];
        foreach ($required as $section) {
            if (isset($analytics[$section]) && is_array($analytics[$section])) {
                $this->check("  ✓ Section '{$section}' present", true);
            } else {
                $this->check("  ✗ Section '{$section}' missing", false);
                $this->warnings++;
            }
        }

        $providers = ['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog', 'amplitude', 'mixpanel', 'tiktok', 'linkedin'];
        $enabledCount = 0;
        foreach ($providers as $provider) {
            $enabled = (bool) ($analytics[$provider]['enabled'] ?? false);
            if ($enabled) {
                $enabledCount++;
                $this->check("  ✓ Provider '{$provider}' enabled", true);
            }
        }

        if ($enabledCount === 0) {
            $this->warn('  ⚠ No providers enabled — analytics will not dispatch events');
            $this->warnings++;
        } else {
            $this->check("  ✓ {$enabledCount} provider(s) enabled", true);
        }

        // Queue config
        $queueEnabled = (bool) ($analytics['queue']['enabled'] ?? true);
        $this->check('  ✓ Queue dispatch ' . ($queueEnabled ? 'enabled' : 'disabled (synchronous)'), true);

        // API config
        $apiEnabled = (bool) ($analytics['api']['enabled'] ?? true);
        $this->check('  ✓ API endpoints ' . ($apiEnabled ? 'enabled' : 'disabled'), true);

        // Consent config
        $consentDefault = $analytics['consent']['default'] ?? 'granted';
        $consentOk = in_array($consentDefault, ['granted', 'denied'], true);
        $this->check('  ✓ Consent default: ' . $consentDefault, $consentOk);
        if (! $consentOk) {
            $this->errors++;
        }

        // Version consistency
        $composerVersion = $config->get('zeroboiler.analytics.version', $config->get('analytics.version'));
        $dtoVersion = AnalyticsEvent::VERSION;
        $versionMatch = $composerVersion === $dtoVersion || $composerVersion === null;
        $this->check("  ✓ Version: {$dtoVersion}" . ($versionMatch ? '' : ' (composer mismatch)'), $versionMatch);
        if (! $versionMatch) {
            $this->warnings++;
        }
    }

    /**
     * Warm up the event catalog caches.
     */
    private function warmupEventCatalog(): void
    {
        try {
            $byCategory = EventCatalog::byCategory();
            $totalEvents = count(EventCatalog::names());

            $this->check("  ✓ Event catalog loaded: {$totalEvents} events across " . count($byCategory) . ' categories', true);

            // Warm up each category
            foreach ($byCategory as $category => $events) {
                $this->check("    ✓ {$category}: " . count($events) . ' events', true);
            }
        } catch (\Throwable $e) {
            $this->check("  ✗ Event catalog error: {$e->getMessage()}", false);
            $this->errors++;
        }

        // Maturity score
        try {
            $calculator = new EventPriorityCalculator;
            $maturity = $calculator->maturityScore();
            $this->check("  ✓ Maturity score: {$maturity['score']} ({$maturity['grade']})", true);
        } catch (\Throwable $e) {
            $this->check('  ⚠ Maturity score unavailable', false);
            $this->warnings++;
        }
    }

    /**
     * Check provider readiness and credential validity.
     */
    private function checkProviderReadiness(ConfigRepository $config, AnalyticsManager $manager): void
    {
        $providers = [
            'ga4' => ['measurement_id', 'api_secret'],
            'gtm' => ['container_id'],
            'meta_pixel' => ['id'],
            'plausible' => ['domain'],
            'posthog' => ['host', 'api_key'],
        ];

        foreach ($providers as $name => $requiredFields) {
            $providerConfig = $config->get("zeroboiler.analytics.{$name}", []);
            $enabled = (bool) ($providerConfig['enabled'] ?? false);

            if (! $enabled) {
                $this->check("  ○ {$name}: disabled", true);
                continue;
            }

            $missingFields = [];
            foreach ($requiredFields as $field) {
                $value = $providerConfig[$field] ?? '';
                if (! is_string($value) || $value === '') {
                    $missingFields[] = $field;
                }
            }

            if (count($missingFields) > 0) {
                $this->check("  ✗ {$name}: missing credentials — " . implode(', ', $missingFields), false);
                $this->errors++;
            } else {
                $this->check("  ✓ {$name}: credentials present", true);
            }
        }
    }

    /**
     * Pre-populate compliance scoring cache.
     */
    private function warmupComplianceCache(ConfigRepository $config, CacheRepository $cache): void
    {
        try {
            $service = new EventComplianceScoringService($cache, $config);

            if (! $service->isEnabled()) {
                $this->check('  ○ Compliance scoring disabled', true);

                return;
            }

            $health = $service->quickHealth();
            $this->check("  ✓ Compliance score: {$health['score']} ({$health['grade']})", $health['compliant']);
            $this->check("  ✓ Events scored: {$health['events_scored']}", true);

            if (! $health['compliant']) {
                $this->warnings++;
            }
        } catch (\Throwable $e) {
            $this->check("  ⚠ Compliance scoring error: {$e->getMessage()}", false);
            $this->warnings++;
        }
    }

    /**
     * Run provider health checks via TagManager.
     */
    private function runHealthChecks(ConfigRepository $config, CacheRepository $cache, AnalyticsManager $manager): void
    {
        try {
            $tagManager = new AnalyticsProviderTagManager($cache, $config, $manager);

            if (! $tagManager->isEnabled()) {
                $this->check('  ○ Tag Manager disabled — health checks skipped', true);

                return;
            }

            $health = $tagManager->getAllHealth();
            $issues = 0;

            foreach ($health as $provider => $status) {
                $active = $tagManager->isProviderActive($provider);
                $label = $active ? '✓' : '○';
                $statusLabel = $status['status'];

                if (in_array($statusLabel, ['degraded', 'down'], true)) {
                    $label = '✗';
                    $issues++;
                }

                $this->check("  {$label} {$provider}: {$statusLabel} (failures: {$status['consecutive_failures']})", true);
            }

            if ($issues > 0) {
                $this->warn("  ⚠ {$issues} provider(s) with health issues");
                $this->warnings += $issues;
            }
        } catch (\Throwable $e) {
            $this->check("  ⚠ Tag Manager error: {$e->getMessage()}", false);
            $this->warnings++;
        }
    }

    /**
     * Display cache statistics.
     */
    private function displayCacheStats(CacheRepository $cache): void
    {
        $this->check('  ✓ Cache store: ' . $cache::class, true);

        $prefixes = ['zb_', 'analytics_'];
        $this->check('  ✓ Cache prefix(es): ' . implode(', ', $prefixes), true);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Print a check item with optional detail.
     */
    private function check(string $message, bool $passed): void
    {
        if ($this->option('verbose') || ! $passed) {
            $passed ? $this->line($message) : $this->warn($message);
        }
    }

    /**
     * Print a section header.
     */
    private function section(string $title): void
    {
        $this->newLine();
        $this->info("┌─ {$title}");
    }
}
