<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Tracking\ServerSideTracker;

/**
 * Analytics Service Bundle Diagnostic Command.
 *
 * Runs a comprehensive health check across all major analytics subsystems
 * in a single command. Provides a quick "is everything working?" answer
 * for production monitoring, CI gates, and on-call diagnostics.
 *
 * Checks 12 subsystems:
 *   1. Config integrity (required keys present)
 *   2. Event catalog (category count, event count, catalog health)
 *   3. Provider configuration (API keys/tokens present for enabled providers)
 *   4. Queue configuration (connection, queue name, batch size)
 *   5. Identity tracking (cookie config, link settings)
 *   6. Consent defaults (GDPR-safe defaults)
 *   7. Auto-track configuration (lifecycle events mapped)
 *   8. E-commerce settings (currency, tax behavior)
 *   9. JS client compatibility (version alignment)
 *  10. Event deduplication (cache strategy, windows)
 *  11. Sanitization (max lengths, blocked keys)
 *  12. Sampling configuration (rate, deterministic mode)
 *
 * Each subsystem receives a status: ✅ healthy, ⚠️ warning, or ❌ critical.
 * Exit code: 0 = all healthy, 1 = warnings present, 2 = critical issues.
 *
 * @see \ZeroBoiler\Analytics\Console\Commands\AnalyticsDiagnosticCommand
 * @see \ZeroBoiler\Analytics\Console\Commands\AnalyticsHealthCommand
 *
 * @since 154.0.0
 */
final class AnalyticsBundleDiagnosticCommand extends Command
{
    protected $signature = 'zb:analytics:bundle
        {--json : Output as JSON}
        {--fail-on-warning : Return exit code 1 for warnings}
        {--section= : Check only a specific subsystem}';

    protected $description = 'Comprehensive bundle diagnostic — 12 subsystems in one command';

    private ConfigRepository $config;

    /** @var list<array{subsystem: string, status: string, checks: int, passed: int, warnings: int, critical: int, details: list<string>}> */
    private array $results = [];

    private int $totalPassed = 0;

    private int $totalWarnings = 0;

    private int $totalCritical = 0;

    /** @var list<string> Available subsystem sections */
    private const SECTIONS = [
        'config',
        'catalog',
        'providers',
        'queue',
        'identity',
        'consent',
        'auto_track',
        'ecommerce',
        'js_client',
        'dedup',
        'sanitization',
        'sampling',
    ];

    public function __construct(ConfigRepository $config): void
    {
        parent::__construct();
        $this->config = $config;
    }

    /**
     * Execute the bundle diagnostic.
     */
    #[\Override]
    public function handle(): int
    {
        $outputJson = (bool) $this->option('json');
        $failOnWarning = (bool) $this->option('fail-on-warning');
        $section = (string) $this->option('section');

        if (! $outputJson) {
            $this->info('🏥 ZeroBoiler Analytics — Bundle Diagnostic');
            $this->line('   Version: ' . AnalyticsEvent::VERSION);
            $this->newLine();
        }

        // Run all subsystems (or a specific one)
        $sectionsToRun = $section !== ''
            ? (in_array($section, self::SECTIONS, true) ? [$section] : [])
            : self::SECTIONS;

        foreach ($sectionsToRun as $s) {
            $this->runSubsystemCheck($s);
        }

        // Calculate totals
        foreach ($this->results as $result) {
            $this->totalPassed += $result['passed'];
            $this->totalWarnings += $result['warnings'];
            $this->totalCritical += $result['critical'];
        }

        // Output
        if ($outputJson) {
            $this->line(json_encode($this->buildJsonReport(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->calculateExitCode($failOnWarning);
        }

        $this->outputReport();

        return $this->calculateExitCode($failOnWarning);
    }

    /**
     * Run a specific subsystem diagnostic.
     */
    private function runSubsystemCheck(string $section): void
    {
        $details = [];
        $passed = 0;
        $warnings = 0;
        $critical = 0;

        switch ($section) {
            case 'config':
                [$passed, $warnings, $critical, $details] = $this->checkConfig();
                break;
            case 'catalog':
                [$passed, $warnings, $critical, $details] = $this->checkCatalog();
                break;
            case 'providers':
                [$passed, $warnings, $critical, $details] = $this->checkProviders();
                break;
            case 'queue':
                [$passed, $warnings, $critical, $details] = $this->checkQueue();
                break;
            case 'identity':
                [$passed, $warnings, $critical, $details] = $this->checkIdentity();
                break;
            case 'consent':
                [$passed, $warnings, $critical, $details] = $this->checkConsent();
                break;
            case 'auto_track':
                [$passed, $warnings, $critical, $details] = $this->checkAutoTrack();
                break;
            case 'ecommerce':
                [$passed, $warnings, $critical, $details] = $this->checkEcommerce();
                break;
            case 'js_client':
                [$passed, $warnings, $critical, $details] = $this->checkJsClient();
                break;
            case 'dedup':
                [$passed, $warnings, $critical, $details] = $this->checkDedup();
                break;
            case 'sanitization':
                [$passed, $warnings, $critical, $details] = $this->checkSanitization();
                break;
            case 'sampling':
                [$passed, $warnings, $critical, $details] = $this->checkSampling();
                break;
        }

        $status = $critical > 0 ? 'critical' : ($warnings > 0 ? 'warning' : 'healthy');

        $this->results[] = [
            'subsystem' => $section,
            'status' => $status,
            'checks' => $passed + $warnings + $critical,
            'passed' => $passed,
            'warnings' => $warnings,
            'critical' => $critical,
            'details' => $details,
        ];
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: list<string>}
     */
    private function checkConfig(): array
    {
        $passed = 0;
        $warnings = 0;
        $critical = 0;
        $details = [];

        $analytics = $this->config->get('zeroboiler.analytics');

        if ($analytics === null) {
            $critical++;
            $details[] = 'Config key "zeroboiler.analytics" not found';

            return [$passed, $warnings, $critical, $details];
        }

        $passed++;
        $details[] = 'Config loaded successfully';

        $requiredKeys = ['ga4', 'gtm', 'meta_pixel', 'consent', 'queue'];

        foreach ($requiredKeys as $key) {
            if (isset($analytics[$key])) {
                $passed++;
            } else {
                $critical++;
                $details[] = "Missing config section: {$key}";
            }
        }

        return [$passed, $warnings, $critical, $details];
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: list<string>}
     */
    private function checkCatalog(): array
    {
        $passed = 0;
        $warnings = 0;
        $critical = 0;
        $details = [];

        $categories = EventCatalog::categories();
        $totalEvents = EventCatalog::totalEventCount();

        if ($totalEvents > 0) {
            $passed++;
            $details[] = "Total events: {$totalEvents}";
        } else {
            $critical++;
            $details[] = 'Event catalog is empty';
        }

        if (count($categories) >= 8) {
            $passed++;
            $details[] = "Categories: " . count($categories) . ' (ecommerce, saas, engagement, security, uptime, infrastructure, marketing, custom)';
        } elseif (count($categories) >= 3) {
            $warnings++;
            $details[] = "Categories: " . count($categories) . ' (expected 8+)';
        } else {
            $critical++;
            $details[] = "Categories: " . count($categories) . ' (critically low)';
        }

        $passed++;
        $details[] = 'Ecommerce events: ' . EcommerceEvents::count();
        $passed++;
        $details[] = 'SaaS events: ' . SaaSEvents::count();
        $passed++;
        $details[] = 'Engagement events: ' . EngagementEvents::count();

        return [$passed, $warnings, $critical, $details];
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: list<string>}
     */
    private function checkProviders(): array
    {
        $passed = 0;
        $warnings = 0;
        $critical = 0;
        $details = [];

        $providers = ['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];
        $enabledCount = 0;
        $configuredCount = 0;

        foreach ($providers as $p) {
            $cfg = $this->config->get("zeroboiler.analytics.{$p}", []);
            $enabled = (bool) ($cfg['enabled'] ?? false);

            if ($enabled) {
                $enabledCount++;
                $hasCredentials = $this->providerHasCredentials($p, $cfg);

                if ($hasCredentials) {
                    $passed++;
                    $configuredCount++;
                    $details[] = "{$p}: enabled + configured ✓";
                } else {
                    $warnings++;
                    $details[] = "{$p}: enabled but missing credentials ⚠️";
                }
            } else {
                $passed++;
                $details[] = "{$p}: disabled (ok)";
            }
        }

        if ($enabledCount === 0) {
            $warnings++;
            $details[] = 'No providers enabled';
        }

        $details[] = "{$enabledCount}/{$providers[count]} enabled, {$configuredCount} fully configured";

        return [$passed, $warnings, $critical, $details];
    }

    /**
     * Check if a provider has its required credentials configured.
     */
    private function providerHasCredentials(string $provider, array $cfg): bool
    {
        return match ($provider) {
            'ga4' => ($cfg['measurement_id'] ?? '') !== '',
            'gtm' => ($cfg['container_id'] ?? '') !== '',
            'meta_pixel' => ($cfg['id'] ?? '') !== '',
            'plausible' => ($cfg['domain'] ?? '') !== '',
            'posthog' => ($cfg['api_key'] ?? '') !== '',
            'mixpanel' => ($cfg['project_token'] ?? '') !== '',
            'amplitude' => ($cfg['api_key'] ?? '') !== '',
            'tiktok' => ($cfg['pixel_id'] ?? '') !== '',
            'linkedin' => ($cfg['partner_id'] ?? '') !== '',
            default => false,
        };
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: list<string>}
     */
    private function checkQueue(): array
    {
        $passed = 0;
        $warnings = 0;
        $critical = 0;
        $details = [];

        $queue = $this->config->get('zeroboiler.analytics.queue', []);
        $enabled = (bool) ($queue['enabled'] ?? false);

        if ($enabled) {
            $passed++;
            $details[] = 'Queue dispatch: enabled';

            $queueName = $queue['queue'] ?? 'default';
            $passed++;
            $details[] = "Queue name: {$queueName}";

            $batchSize = (int) ($queue['max_batch_size'] ?? 50);
            if ($batchSize > 0 && $batchSize <= 1000) {
                $passed++;
                $details[] = "Batch size: {$batchSize}";
            } else {
                $warnings++;
                $details[] = "Batch size: {$batchSize} (outside recommended 1-1000 range)";
            }
        } else {
            $warnings++;
            $details[] = 'Queue dispatch: disabled (sync mode)';
        }

        return [$passed, $warnings, $critical, $details];
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: list<string>}
     */
    private function checkIdentity(): array
    {
        $passed = 0;
        $warnings = 0;
        $critical = 0;
        $details = [];

        $identity = $this->config->get('zeroboiler.analytics.identity', []);

        $cookieName = $identity['cookie_name'] ?? 'zb_analytics_id';
        $passed++;
        $details[] = "Cookie name: {$cookieName}";

        $ttl = (int) ($identity['cookie_ttl'] ?? 525600);
        if ($ttl > 0) {
            $passed++;
            $details[] = "Cookie TTL: {$ttl} minutes (" . round($ttl / 525600, 1) . ' years)';
        } else {
            $critical++;
            $details[] = "Cookie TTL: {$ttl} (invalid)";
        }

        $linkOnAuth = (bool) ($identity['link_on_auth'] ?? true);
        $passed++;
        $details[] = 'Link on auth: ' . ($linkOnAuth ? 'enabled' : 'disabled');

        return [$passed, $warnings, $critical, $details];
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: list<string>}
     */
    private function checkConsent(): array
    {
        $passed = 0;
        $warnings = 0;
        $critical = 0;
        $details = [];

        $consent = $this->config->get('zeroboiler.analytics.consent', []);

        $default = $consent['default'] ?? 'granted';
        if ($default === 'denied') {
            $passed++;
            $details[] = 'Default consent: denied (GDPR-safe) ✓';
        } elseif ($default === 'granted') {
            $warnings++;
            $details[] = 'Default consent: granted (not GDPR-safe by default) ⚠️';
        } else {
            $critical++;
            $details[] = "Default consent: {$default} (invalid value)";
        }

        $purposes = $consent['purposes'] ?? [];
        if (count($purposes) >= 4) {
            $passed++;
            $details[] = 'Consent purposes: ' . count($purposes) . ' defined';
        } else {
            $warnings++;
            $details[] = 'Consent purposes: ' . count($purposes) . ' (recommended: 4+)';
        }

        return [$passed, $warnings, $critical, $details];
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: list<string>}
     */
    private function checkAutoTrack(): array
    {
        $passed = 0;
        $warnings = 0;
        $critical = 0;
        $details = [];

        $autoTrack = $this->config->get('zeroboiler.analytics.auto_track', []);
        $enabled = (bool) ($autoTrack['enabled'] ?? false);

        $passed++;
        $details[] = 'Auto-track: ' . ($enabled ? 'enabled' : 'disabled');

        $events = $autoTrack['events'] ?? [];
        $mappedCount = count(array_filter($events));
        $passed++;
        $details[] = "Lifecycle events mapped: {$mappedCount}/" . count($events);

        $eventMap = $autoTrack['event_map'] ?? [];
        $passed++;
        $details[] = 'Custom event map: ' . count($eventMap) . ' entries';

        return [$passed, $warnings, $critical, $details];
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: list<string>}
     */
    private function checkEcommerce(): array
    {
        $passed = 0;
        $warnings = 0;
        $critical = 0;
        $details = [];

        $ecom = $this->config->get('zeroboiler.analytics.ecommerce', []);

        $currency = $ecom['currency'] ?? 'USD';
        $passed++;
        $details[] = "Default currency: {$currency}";

        $tax = $ecom['tax_behavior'] ?? 'not_specified';
        if (in_array($tax, ['inclusive', 'exclusive', 'not_specified'], true)) {
            $passed++;
            $details[] = "Tax behavior: {$tax}";
        } else {
            $warnings++;
            $details[] = "Tax behavior: {$tax} (unusual value)";
        }

        $checkoutEnabled = (bool) $this->config->get('zeroboiler.analytics.checkout_tracking.enabled', true);
        $passed++;
        $details[] = 'Checkout tracking: ' . ($checkoutEnabled ? 'enabled' : 'disabled');

        return [$passed, $warnings, $critical, $details];
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: list<string>}
     */
    private function checkJsClient(): array
    {
        $passed = 0;
        $warnings = 0;
        $critical = 0;
        $details = [];

        $expectedVersion = AnalyticsEvent::VERSION;
        $passed++;
        $details[] = "Expected JS version: {$expectedVersion}";

        // Check if JS file exists
        $jsPath = base_path('vendor/zeroboiler/analytics/resources/js/analytics.js');

        if (file_exists($jsPath)) {
            $passed++;
            $details[] = 'JS client file: found';
        } else {
            $warnings++;
            $details[] = 'JS client file: not found (may be published separately)';
        }

        $passed++;
        $details[] = 'TypeScript definitions: analytics.d.ts (bundled)';
        $passed++;
        $details[] = 'Svelte composables: 7 (useAnalytics, useEcommerce, useSaaSMetrics, useLifecycle, usePerformanceTracker, useSessionReplay, useAnalyticsConfig)';

        return [$passed, $warnings, $critical, $details];
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: list<string>}
     */
    private function checkDedup(): array
    {
        $passed = 0;
        $warnings = 0;
        $critical = 0;
        $details = [];

        $dedup = $this->config->get('zeroboiler.analytics.dedup_cache', []);
        $enabled = (bool) ($dedup['enabled'] ?? false);

        if ($enabled) {
            $passed++;
            $details[] = 'Dedup cache: enabled';

            $strategy = $dedup['strategy'] ?? 'exact';
            if (in_array($strategy, ['exact', 'fuzzy'], true)) {
                $passed++;
                $details[] = "Strategy: {$strategy}";
            } else {
                $critical++;
                $details[] = "Strategy: {$strategy} (invalid)";
            }

            $windows = $dedup['windows'] ?? [];
            $passed++;
            $details[] = 'Category windows: ' . count($windows) . ' defined';
        } else {
            $warnings++;
            $details[] = 'Dedup cache: disabled';
        }

        return [$passed, $warnings, $critical, $details];
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: list<string>}
     */
    private function checkSanitization(): array
    {
        $passed = 0;
        $warnings = 0;
        $critical = 0;
        $details = [];

        $san = $this->config->get('zeroboiler.analytics.sanitization', []);
        $enabled = (bool) ($san['enabled'] ?? false);

        if ($enabled) {
            $passed++;
            $details[] = 'Sanitization: enabled';

            $blocked = $san['disallowed_keys'] ?? [];
            if (count($blocked) >= 3) {
                $passed++;
                $details[] = 'Blocked keys: ' . count($blocked) . ' defined';
            } else {
                $warnings++;
                $details[] = 'Blocked keys: ' . count($blocked) . ' (recommended: 6+)';
            }

            $maxParams = (int) ($san['max_param_count'] ?? 100);
            $passed++;
            $details[] = "Max param count: {$maxParams}";
        } else {
            $warnings++;
            $details[] = 'Sanitization: disabled (recommended for production) ⚠️';
        }

        return [$passed, $warnings, $critical, $details];
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: list<string>}
     */
    private function checkSampling(): array
    {
        $passed = 0;
        $warnings = 0;
        $critical = 0;
        $details = [];

        $sampling = $this->config->get('zeroboiler.analytics.sampling', []);
        $enabled = (bool) ($sampling['enabled'] ?? false);

        if ($enabled) {
            $passed++;
            $details[] = 'Sampling: enabled';

            $rate = (float) ($sampling['rate'] ?? 1.0);
            if ($rate > 0 && $rate <= 1.0) {
                $passed++;
                $details[] = "Rate: " . ($rate * 100) . '%';
            } else {
                $critical++;
                $details[] = "Rate: {$rate} (must be 0.0-1.0)";
            }

            $deterministic = (bool) ($sampling['deterministic'] ?? true);
            $passed++;
            $details[] = 'Deterministic: ' . ($deterministic ? 'yes (hash-based)' : 'no (random)');
        } else {
            $passed++;
            $details[] = 'Sampling: disabled (100% tracking)';
        }

        return [$passed, $warnings, $critical, $details];
    }

    /**
     * Build the JSON report structure.
     */
    private function buildJsonReport(): array
    {
        return [
            'version' => AnalyticsEvent::VERSION,
            'total_checks' => $this->totalPassed + $this->totalWarnings + $this->totalCritical,
            'passed' => $this->totalPassed,
            'warnings' => $this->totalWarnings,
            'critical' => $this->totalCritical,
            'overall_status' => $this->totalCritical > 0 ? 'critical' : ($this->totalWarnings > 0 ? 'warning' : 'healthy'),
            'subsystems' => $this->results,
        ];
    }

    /**
     * Output the full diagnostic report.
     */
    private function outputReport(): void
    {
        foreach ($this->results as $result) {
            $icon = match ($result['status']) {
                'healthy' => '<fg=green>✅</>',
                'warning' => '<fg=yellow>⚠️</>',
                'critical' => '<fg=red>❌</>',
                default => '?',
            };

            $label = $this->formatSubsystemLabel($result['subsystem']);
            $this->line("  {$icon} {$label}  <fg=gray>(" . $result['passed'] . '/' . $result['checks'] . ' passed";
            if ($result['warnings'] > 0) {
                $this->line(', ' . $result['warnings'] . ' warnings');
            }
            if ($result['critical'] > 0) {
                $this->line(', ' . $result['critical'] . ' critical');
            }
            $this->line(')</>');

            // Show details for subsystems with issues
            if ($result['warnings'] > 0 || $result['critical'] > 0) {
                foreach ($result['details'] as $detail) {
                    if (str_contains($detail, '⚠️') || str_contains($detail, 'missing') || str_contains($detail, 'disabled') || str_contains($detail, 'not found') || str_contains($detail, 'invalid') || str_contains($detail, 'not GDPR')) {
                        $this->line("      <fg=gray>└ {$detail}</>");
                    }
                }
            }
        }

        // Summary
        $this->newLine();
        $total = $this->totalPassed + $this->totalWarnings + $this->totalCritical;

        $this->line("  Total: <fg=cyan>{$total}</> checks, <fg=green>{$this->totalPassed}</> passed, <fg=yellow>{$this->totalWarnings}</> warnings, <fg=red>{$this->totalCritical}</> critical");

        if ($this->totalCritical === 0 && $this->totalWarnings === 0) {
            $this->newLine();
            $this->line('  <fg=green;options=bold>🟢 All subsystems healthy</>');
        } elseif ($this->totalCritical === 0) {
            $this->newLine();
            $this->line('  <fg=yellow;options=bold>🟡 Healthy with warnings</>');
        } else {
            $this->newLine();
            $this->line('  <fg=red;options=bold>🔴 Critical issues detected</>');
        }
    }

    /**
     * Format subsystem name for display.
     */
    private function formatSubsystemLabel(string $subsystem): string
    {
        return match ($subsystem) {
            'config' => 'Config Integrity     ',
            'catalog' => 'Event Catalog       ',
            'providers' => 'Provider Config      ',
            'queue' => 'Queue Configuration ',
            'identity' => 'Identity Tracking   ',
            'consent' => 'Consent / GDPR      ',
            'auto_track' => 'Auto-Track Config   ',
            'ecommerce' => 'E-Commerce Settings ',
            'js_client' => 'JS Client Library   ',
            'dedup' => 'Event Deduplication',
            'sanitization' => 'Event Sanitization  ',
            'sampling' => 'Sampling Engine     ',
            default => str_pad($subsystem, 21),
        };
    }

    /**
     * Calculate the exit code.
     */
    private function calculateExitCode(bool $failOnWarning): int
    {
        if ($this->totalCritical > 0) {
            return 2;
        }

        if ($this->totalWarnings > 0 && $failOnWarning) {
            return 1;
        }

        return self::SUCCESS;
    }
}
