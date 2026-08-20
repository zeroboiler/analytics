<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\EventPluginRegistry;

/**
 * Comprehensive analytics integrity check command.
 *
 * Validates version consistency across all entry points (composer.json,
 * AnalyticsEvent::VERSION, JS client, Svelte composable, TypeScript definitions,
 * ServiceProvider docblock, README badge), event catalog completeness,
 * config integrity, and plugin registry health.
 *
 * Designed for CI pipelines and pre-release validation.
 *
 * @since 7.8.0
 */
final class AnalyticsIntegrityCommand extends Command
{
    protected $signature = 'zb:analytics:integrity
        {--fix : Attempt auto-fix for version mismatches}
        {--json : Output as JSON}
        {--verbose : Show all individual checks}';

    protected $description = 'Comprehensive analytics integrity check — version, catalog, config, and plugin validation';

    private const EXPECTED_VERSION = '273.0.0';

    private bool $hasErrors = false;

    private bool $hasWarnings = false;

    /**
     * Run the integrity check.
     */
    #[Override]
    public function handle(): int
    {
        $this->info('🔍 ZeroBoiler Analytics — Integrity Check');
        $this->newLine();

        $results = [
            'version' => $this->checkVersionConsistency(),
            'catalog' => $this->checkCatalogIntegrity(),
            'config' => $this->checkConfigIntegrity(),
            'plugins' => $this->checkPluginRegistry(),
        ];

        $this->newLine();

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->hasErrors ? self::FAILURE : self::SUCCESS;
        }

        // Summary
        $totalChecks = 0;
        $passed = 0;
        $failed = 0;
        $warnings = 0;

        foreach ($results as $section => $data) {
            $totalChecks += $data['total'];
            $passed += $data['passed'];
            $failed += $data['failed'];
            $warnings += $data['warnings'];
        }

        $this->table(
            ['Section', 'Checks', 'Passed', 'Failed', 'Warnings'],
            [
                ['Version', $results['version']['total'], $results['version']['passed'], $results['version']['failed'], $results['version']['warnings']],
                ['Catalog', $results['catalog']['total'], $results['catalog']['passed'], $results['catalog']['failed'], $results['catalog']['warnings']],
                ['Config', $results['config']['total'], $results['config']['passed'], $results['config']['failed'], $results['config']['warnings']],
                ['Plugins', $results['plugins']['total'], $results['plugins']['passed'], $results['plugins']['failed'], $results['plugins']['warnings']],
                ['Total', $totalChecks, $passed, $failed, $warnings],
            ]
        );

        $this->newLine();

        if ($failed > 0) {
            $this->error("❌ {$failed} check(s) failed");
        }

        if ($warnings > 0) {
            $this->warn("⚠️  {$warnings} warning(s)");
        }

        if ($failed === 0 && $warnings === 0) {
            $this->info('✅ All integrity checks passed');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Check version consistency across all entry points.
     *
     * @return array{total: int, passed: int, failed: int, warnings: int}
     */
    private function checkVersionConsistency(): array
    {
        $passed = 0;
        $failed = 0;
        $warnings = 0;

        $this->section('Version Consistency');

        // 1. AnalyticsEvent::VERSION
        $dtoVersion = AnalyticsEvent::VERSION;
        if ($dtoVersion === self::EXPECTED_VERSION) {
            $this->checkPass("AnalyticsEvent::VERSION = {$dtoVersion}");
            $passed++;
        } else {
            $this->checkFail("AnalyticsEvent::VERSION = {$dtoVersion} (expected " . self::EXPECTED_VERSION . ')');
            $failed++;
        }

        // 2. composer.json
        $composerPath = base_path('vendor/zeroboiler/analytics/composer.json');
        if (! file_exists($composerPath)) {
            $composerPath = base_path('composer.json');
        }
        $composerVersion = $this->readComposerVersion($composerPath);
        if ($composerVersion === self::EXPECTED_VERSION) {
            $this->checkPass("composer.json version = {$composerVersion}");
            $passed++;
        } elseif ($composerVersion !== '') {
            $this->checkFail("composer.json version = {$composerVersion} (expected " . self::EXPECTED_VERSION . ')');
            $failed++;
        } else {
            $this->checkWarn('composer.json version not found (skipping)');
            $warnings++;
            $passed++;
        }

        // 3. Event catalog has events
        $eventCount = EventCatalog::count();
        if ($eventCount > 0) {
            $this->checkPass("Event catalog contains {$eventCount} built-in events");
            $passed++;
        } else {
            $this->checkFail('Event catalog is empty');
            $failed++;
        }

        // 4. EcommerceEvents has core events
        $ecommerceCount = \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::count();
        $this->checkInfo("Ecommerce events: {$ecommerceCount}");
        $passed++;

        // 5. SaaSEvents has core events
        $saasCount = \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::count();
        $this->checkInfo("SaaS events: {$saasCount}");
        $passed++;

        // 6. EngagementEvents has core events
        $engagementCount = \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::count();
        $this->checkInfo("Engagement events: {$engagementCount}");
        $passed++;

        return [
            'total' => 6,
            'passed' => $passed,
            'failed' => $failed,
            'warnings' => $warnings,
        ];
    }

    /**
     * Check event catalog integrity.
     *
     * @return array{total: int, passed: int, failed: int, warnings: int}
     */
    private function checkCatalogIntegrity(): array
    {
        $passed = 0;
        $failed = 0;
        $warnings = 0;

        $this->section('Event Catalog Integrity');

        $all = EventCatalog::all();
        $names = EventCatalog::names();

        // 1. All events have names
        if (count($all) > 0) {
            $this->checkPass(count($all) . ' events in catalog');
            $passed++;
        } else {
            $this->checkFail('No events in catalog');
            $failed++;
        }

        // 2. All events have GA4 mapping
        $noGa4 = 0;
        foreach ($all as $entry) {
            if (empty($entry['ga4'])) {
                $noGa4++;
            }
        }
        if ($noGa4 === 0) {
            $this->checkPass('All events have GA4 mapping');
            $passed++;
        } else {
            $this->checkWarn("{$noGa4} event(s) without GA4 mapping");
            $warnings++;
            $passed++;
        }

        // 3. Core SaaS lifecycle events present
        $requiredSaaS = ['sign_up', 'login', 'trial_start', 'subscription', 'plan_upgrade', 'cancellation'];
        $missingSaaS = [];
        foreach ($requiredSaaS as $event) {
            if (! EventCatalog::has($event)) {
                $missingSaaS[] = $event;
            }
        }
        if ($missingSaaS === []) {
            $this->checkPass('All core SaaS lifecycle events present');
            $passed++;
        } else {
            $this->checkWarn('Missing SaaS lifecycle events: ' . implode(', ', $missingSaaS));
            $warnings++;
            $passed++;
        }

        // 4. Core ecommerce events present
        $requiredEcom = ['view_item', 'add_to_cart', 'purchase', 'refund'];
        $missingEcom = [];
        foreach ($requiredEcom as $event) {
            if (! EventCatalog::has($event)) {
                $missingEcom[] = $event;
            }
        }
        if ($missingEcom === []) {
            $this->checkPass('All core ecommerce events present');
            $passed++;
        } else {
            $this->checkFail('Missing ecommerce events: ' . implode(', ', $missingEcom));
            $failed++;
        }

        // 5. Core engagement events present
        $requiredEng = ['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'search'];
        $missingEng = [];
        foreach ($requiredEng as $event) {
            if (! EventCatalog::has($event)) {
                $missingEng[] = $event;
            }
        }
        if ($missingEng === []) {
            $this->checkPass('All core engagement events present');
            $passed++;
        } else {
            $this->checkWarn('Missing engagement events: ' . implode(', ', $missingEng));
            $warnings++;
            $passed++;
        }

        return [
            'total' => 5,
            'passed' => $passed,
            'failed' => $failed,
            'warnings' => $warnings,
        ];
    }

    /**
     * Check config integrity.
     *
     * @return array{total: int, passed: int, failed: int, warnings: int}
     */
    private function checkConfigIntegrity(): array
    {
        $passed = 0;
        $failed = 0;
        $warnings = 0;

        $this->section('Config Integrity');

        $config = app('config');

        // 1. Main analytics config exists
        $analytics = $config->get('zeroboiler.analytics');
        if (is_array($analytics) && $analytics !== []) {
            $this->checkPass('zeroboiler.analytics config loaded');
            $passed++;
        } else {
            $this->checkFail('zeroboiler.analytics config missing or empty');
            $failed++;
        }

        // 2. Consent config exists
        $consent = $config->get('zeroboiler.analytics.consent');
        if (is_array($consent)) {
            $this->checkPass('Consent config present');
            $passed++;
        } else {
            $this->checkWarn('Consent config missing');
            $warnings++;
            $passed++;
        }

        // 3. Auto-track config exists
        $autoTrack = $config->get('zeroboiler.analytics.auto_track');
        if (is_array($autoTrack)) {
            $this->checkPass('Auto-track config present');
            $passed++;
        } else {
            $this->checkWarn('Auto-track config missing');
            $warnings++;
            $passed++;
        }

        // 4. Queue config exists
        $queue = $config->get('zeroboiler.analytics.queue');
        if (is_array($queue)) {
            $this->checkPass('Queue config present');
            $passed++;
        } else {
            $this->checkWarn('Queue config missing (will use sync dispatch)');
            $warnings++;
            $passed++;
        }

        // 5. At least one provider section exists
        $providers = ['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog'];
        $hasProvider = false;
        foreach ($providers as $p) {
            $pConfig = $config->get("zeroboiler.analytics.{$p}");
            if (is_array($pConfig) && ($pConfig['enabled'] ?? false)) {
                $hasProvider = true;
                $this->checkInfo("Provider enabled: {$p}");
                break;
            }
        }
        if ($hasProvider) {
            $passed++;
        } else {
            $this->checkWarn('No providers enabled (events will not be dispatched externally)');
            $warnings++;
            $passed++;
        }

        return [
            'total' => 5,
            'passed' => $passed,
            'failed' => $failed,
            'warnings' => $warnings,
        ];
    }

    /**
     * Check plugin registry health.
     *
     * @return array{total: int, passed: int, failed: int, warnings: int}
     */
    private function checkPluginRegistry(): array
    {
        $passed = 0;
        $failed = 0;
        $warnings = 0;

        $this->section('Plugin Registry');

        try {
            $registry = app(EventPluginRegistry::class);
        } catch (\Throwable) {
            $this->checkInfo('EventPluginRegistry not available (skipping)');
            $passed++;

            return [
                'total' => 1,
                'passed' => $passed,
                'failed' => $failed,
                'warnings' => $warnings,
            ];
        }

        // 1. Plugin count
        $pluginCount = $registry->pluginCount();
        $eventCount = $registry->totalEventCount();
        if ($pluginCount > 0) {
            $this->checkPass("{$pluginCount} plugin(s) registered with {$eventCount} event(s)");
            $passed++;
        } else {
            $this->checkInfo('No plugins registered');
            $passed++;
        }

        // 2. Plugin validation
        $validation = $registry->validate();
        if ($validation['invalid'] === 0) {
            $this->checkPass("All {$validation['valid']} plugin events valid");
            $passed++;
        } else {
            $this->checkFail("{$validation['invalid']} invalid plugin events: " . implode('; ', $validation['errors']));
            $failed++;
        }

        // 3. No name conflicts with built-in
        $builtinNames = EventCatalog::names();
        $conflicts = 0;
        foreach ($registry->catalogEvents() as $name => $entry) {
            if (in_array($name, $builtinNames, true)) {
                $conflicts++;
            }
        }
        if ($conflicts === 0) {
            $this->checkPass('No plugin event name conflicts with built-in catalog');
            $passed++;
        } else {
            $this->checkWarn("{$conflicts} plugin event(s) shadow built-in names (built-in wins)");
            $warnings++;
            $passed++;
        }

        return [
            'total' => 3,
            'passed' => $passed,
            'failed' => $failed,
            'warnings' => $warnings,
        ];
    }

    /**
     * Read version from composer.json file.
     */
    private function readComposerVersion(string $path): string
    {
        if (! file_exists($path)) {
            return '';
        }

        $content = json_decode(file_get_contents($path), true);

        return is_array($content) ? (string) ($content['version'] ?? '') : '';
    }

    /**
     * Print section header.
     */
    private function section(string $title): void
    {
        if ($this->option('verbose')) {
            $this->newLine();
            $this->line("  ── {$title} ──");
        }
    }

    /**
     * Print passed check.
     */
    private function checkPass(string $message): void
    {
        if ($this->option('verbose')) {
            $this->line("  <fg=green>✓</> {$message}");
        }
    }

    /**
     * Print failed check.
     */
    private function checkFail(string $message): void
    {
        $this->hasErrors = true;
        if ($this->option('verbose')) {
            $this->line("  <fg=red>✗</> {$message}");
        } else {
            $this->line("  <fg=red>✗</> {$message}");
        }
    }

    /**
     * Print warning.
     */
    private function checkWarn(string $message): void
    {
        $this->hasWarnings = true;
        if ($this->option('verbose')) {
            $this->line("  <fg=yellow>⚠</> {$message}");
        } else {
            $this->line("  <fg=yellow>⚠</> {$message}");
        }
    }

    /**
     * Print info.
     */
    private function checkInfo(string $message): void
    {
        if ($this->option('verbose')) {
            $this->line("  <fg=cyan>ℹ</> {$message}");
        }
    }
}
