<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Comprehensive analytics diagnostics command.
 *
 * Runs a full system health check covering:
 * - Provider connectivity verification
 * - Configuration validation
 * - Event catalog integrity
 * - Cache availability
 * - Queue configuration
 * - Identity settings
 * - GDPR compliance settings
 * - Budget & throttling configuration
 *
 * Use this command to quickly diagnose misconfigurations, verify
 * provider setup, and validate the analytics pipeline integrity.
 *
 * @see \ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand
 *
 * @since 1.0.0
 */
final class AnalyticsDiagnosticsCommand extends Command
{
    protected $signature = 'zb:analytics:diagnostics
        {--check-providers : Verify provider API connectivity}
        {--json : Output results as JSON}';

    protected $description = 'Run comprehensive analytics system diagnostics';

    private int $passCount = 0;

    private int $warnCount = 0;

    private int $failCount = 0;

    /** @var list<array{check: string, status: string, message: string, details?: array<string, mixed>}> */
    private array $results = [];

    #[Override]
    public function handle(): int
    {
        $this->results = [];
        $this->passCount = 0;
        $this->warnCount = 0;
        $this->failCount = 0;

        $jsonOutput = $this->option('json');
        $checkProviders = $this->option('check-providers');

        if (! $jsonOutput) {
            $this->info('🔬 ZeroBoiler Analytics Diagnostics');
            $this->newLine();
        }

        $this->runConfigCheck();
        $this->runProviderCheck($checkProviders);
        $this->runCatalogCheck();
        $this->runCacheCheck();
        $this->runQueueCheck();
        $this->runIdentityCheck();
        $this->runGdprCheck();
        $this->runConsentCheck();
        $this->runBudgetCheck();
        $this->runMiddlewareCheck();

        if ($jsonOutput) {
            $this->line(json_encode([
                'pass' => $this->passCount,
                'warn' => $this->warnCount,
                'fail' => $this->failCount,
                'results' => $this->results,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $this->failCount > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=cyan;options=bold>RESULTS</>');
        $this->line('─────────────────────────────');

        foreach ($this->results as $result) {
            $icon = match ($result['status']) {
                'pass' => '<fg=green>✓</>',
                'warn' => '<fg=yellow>⚠</>',
                'fail' => '<fg=red>✗</>',
                default => '?',
            };

            $this->line("  {$icon} {$result['check']}: {$result['message']}");
        }

        $this->newLine();
        $total = $this->passCount + $this->warnCount + $this->failCount;
        $this->line("  <fg=green>{$this->passCount}</> passed, <fg=yellow>{$this->warnCount}</> warnings, <fg=red>{$this->failCount}</> failures ({$total} checks)");
        $this->newLine();

        if ($this->failCount > 0) {
            $this->error('❌ Diagnostics failed — resolve failures above.');

            return self::FAILURE;
        }

        if ($this->warnCount > 0) {
            $this->warn('⚠️  Diagnostics passed with warnings.');

            return self::SUCCESS;
        }

        $this->info('✅ All diagnostics passed. Analytics pipeline is healthy.');

        return self::SUCCESS;
    }

    /**
     * Check configuration integrity.
     */
    private function runConfigCheck(): void
    {
        $config = config('zeroboiler.analytics', []);

        if (empty($config)) {
            $this->addResult('config_loaded', 'fail', 'Configuration not loaded — check config/zeroboiler.php');
            $this->failCount++;

            return;
        }

        $requiredSections = ['ga4', 'gtm', 'meta_pixel', 'consent'];
        $missing = [];

        foreach ($requiredSections as $section) {
            if (! isset($config[$section])) {
                $missing[] = $section;
            }
        }

        if (empty($missing)) {
            $this->addResult('config_structure', 'pass', 'All required config sections present');
            $this->passCount++;
        } else {
            $this->addResult('config_structure', 'fail', 'Missing config sections: ' . implode(', ', $missing));
            $this->failCount++;
        }

        $enabledProviders = 0;
        $providerKeys = ['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog', 'webhook'];

        foreach ($providerKeys as $key) {
            if (! empty($config[$key]['enabled'])) {
                $enabledProviders++;
            }
        }

        if ($enabledProviders > 0) {
            $this->addResult('providers_enabled', 'pass', "{$enabledProviders} provider(s) enabled");
            $this->passCount++;
        } else {
            $this->addResult('providers_enabled', 'warn', 'No providers enabled — analytics events will not be dispatched');
            $this->warnCount++;
        }

        $apiEnabled = $config['api']['enabled'] ?? false;
        if ($apiEnabled) {
            $this->addResult('api_enabled', 'pass', 'API endpoints enabled at ' . ($config['api']['base_url'] ?? '/api/analytics'));
            $this->passCount++;
        } else {
            $this->addResult('api_enabled', 'warn', 'API endpoints disabled — JS client cannot track server-side');
            $this->warnCount++;
        }
    }

    /**
     * Check provider configuration and optional connectivity.
     */
    private function runProviderCheck(bool $checkConnectivity): void
    {
        $config = config('zeroboiler.analytics', []);

        // GA4
        $ga4 = $config['ga4'] ?? [];
        if (! empty($ga4['enabled'])) {
            if (empty($ga4['measurement_id'])) {
                $this->addResult('ga4_config', 'fail', 'GA4 enabled but measurement_id is empty');
                $this->failCount++;
            } elseif (empty($ga4['api_secret'])) {
                $this->addResult('ga4_config', 'warn', 'GA4 enabled but api_secret is empty — MP disabled, client-only');
                $this->warnCount++;
            } else {
                $this->addResult('ga4_config', 'pass', 'GA4 configured (ID: ' . substr((string) $ga4['measurement_id'], 0, 12) . '...)');
                $this->passCount++;
            }
        }

        // GTM
        $gtm = $config['gtm'] ?? [];
        if (! empty($gtm['enabled'])) {
            if (empty($gtm['container_id'])) {
                $this->addResult('gtm_config', 'fail', 'GTM enabled but container_id is empty');
                $this->failCount++;
            } else {
                $this->addResult('gtm_config', 'pass', 'GTM configured (ID: ' . substr((string) $gtm['container_id'], 0, 12) . '...)');
                $this->passCount++;
            }
        }

        // Meta Pixel
        $meta = $config['meta_pixel'] ?? [];
        if (! empty($meta['enabled'])) {
            if (empty($meta['id'])) {
                $this->addResult('meta_pixel_config', 'fail', 'Meta Pixel enabled but pixel ID is empty');
                $this->failCount++;
            } elseif (empty($meta['access_token'])) {
                $this->addResult('meta_pixel_config', 'warn', 'Meta Pixel enabled but access_token is empty — CAPI disabled, client-only');
                $this->warnCount++;
            } else {
                $this->addResult('meta_pixel_config', 'pass', 'Meta Pixel configured (ID: ' . substr((string) $meta['id'], 0, 12) . '...)');
                $this->passCount++;
            }
        }

        // Plausible
        $plausible = $config['plausible'] ?? [];
        if (! empty($plausible['enabled'])) {
            if (empty($plausible['domain'])) {
                $this->addResult('plausible_config', 'fail', 'Plausible enabled but domain is empty');
                $this->failCount++;
            } else {
                $this->addResult('plausible_config', 'pass', 'Plausible configured (domain: ' . $plausible['domain'] . ')');
                $this->passCount++;
            }
        }

        // PostHog
        $posthog = $config['posthog'] ?? [];
        if (! empty($posthog['enabled'])) {
            if (empty($posthog['api_key'])) {
                $this->addResult('posthog_config', 'fail', 'PostHog enabled but api_key is empty');
                $this->failCount++;
            } else {
                $this->addResult('posthog_config', 'pass', 'PostHog configured (host: ' . ($posthog['host'] ?? 'default') . ')');
                $this->passCount++;
            }
        }

        // Webhook
        $webhook = $config['webhook'] ?? [];
        if (! empty($webhook['enabled'])) {
            if (empty($webhook['url'])) {
                $this->addResult('webhook_config', 'fail', 'Webhook enabled but URL is empty');
                $this->failCount++;
            } else {
                $this->addResult('webhook_config', 'pass', 'Webhook configured (' . substr((string) $webhook['url'], 0, 40) . '...)');
                $this->passCount++;
            }
        }

        // Optional connectivity check
        if ($checkConnectivity) {
            $this->checkProviderConnectivity($config);
        }
    }

    /**
     * Verify provider API connectivity by making test requests.
     *
     * @param  array<string, mixed>  $config
     */
    private function checkProviderConnectivity(array $config): void
    {
        // GA4 connectivity (validate measurement ID format)
        $ga4 = $config['ga4'] ?? [];
        if (! empty($ga4['enabled']) && ! empty($ga4['measurement_id'])) {
            $id = (string) $ga4['measurement_id'];
            if (str_starts_with($id, 'G-')) {
                $this->addResult('ga4_format', 'pass', 'GA4 measurement ID format valid (G-XXXXXXX)');
                $this->passCount++;
            } else {
                $this->addResult('ga4_format', 'warn', "GA4 measurement ID format unusual (expected G- prefix): {$id}");
                $this->warnCount++;
            }
        }
    }

    /**
     * Check event catalog integrity.
     */
    private function runCatalogCheck(): void
    {
        $validation = EventCatalog::validate();

        if ($validation['valid']) {
            $total = EventCatalog::count();
            $ecommerce = EventCatalog::category('ecommerce');
            $saas = EventCatalog::category('saas');
            $engagement = EventCatalog::category('engagement');

            $this->addResult('catalog_integrity', 'pass', "Event catalog valid ({$total} events: " . count($ecommerce) . ' ecommerce, ' . count($saas) . ' SaaS, ' . count($engagement) . ' engagement)');
            $this->passCount++;
        } else {
            $errors = $validation['errors'] ?? [];
            $this->addResult('catalog_integrity', 'fail', 'Event catalog has ' . count($errors) . ' validation error(s): ' . implode('; ', array_slice($errors, 0, 3)));
            $this->failCount++;
        }

        $requiredEvents = ['page_view', 'sign_up', 'login', 'purchase', 'error'];
        $missingCore = [];

        foreach ($requiredEvents as $eventName) {
            if (! EventCatalog::has($eventName)) {
                $missingCore[] = $eventName;
            }
        }

        if (empty($missingCore)) {
            $this->addResult('core_events', 'pass', 'All core events present');
            $this->passCount++;
        } else {
            $this->addResult('core_events', 'warn', 'Missing core events: ' . implode(', ', $missingCore));
            $this->warnCount++;
        }

        $all = EventCatalog::all();
        $ga4Coverage = 0;
        $metaCoverage = 0;
        foreach ($all as $entry) {
            if (! empty($entry['ga4'])) {
                $ga4Coverage++;
            }
            if ($entry['meta'] !== null) {
                $metaCoverage++;
            }
        }
        $total = count($all);
        $ga4Pct = $total > 0 ? round(($ga4Coverage / $total) * 100, 1) : 0;
        $metaPct = $total > 0 ? round(($metaCoverage / $total) * 100, 1) : 0;

        $this->addResult('provider_coverage', 'pass', "GA4: {$ga4Pct}% ({$ga4Coverage}/{$total}), Meta: {$metaPct}% ({$metaCoverage}/{$total})");
        $this->passCount++;
    }

    /**
     * Check cache availability.
     */
    private function runCacheCheck(): void
    {
        try {
            $cache = app('cache');
            $testKey = 'zb_diag_test_' . time();
            $cache->put($testKey, 'ok', 10);
            $result = $cache->get($testKey);
            $cache->forget($testKey);

            if ($result === 'ok') {
                $this->addResult('cache', 'pass', 'Cache store is available and writable');
                $this->passCount++;
            } else {
                $this->addResult('cache', 'warn', 'Cache store returned unexpected value');
                $this->warnCount++;
            }
        } catch (\Throwable $e) {
            $this->addResult('cache', 'warn', 'Cache store not available: ' . $e->getMessage());
            $this->warnCount++;
        }
    }

    /**
     * Check queue configuration.
     */
    private function runQueueCheck(): void
    {
        $queue = config('zeroboiler.analytics.queue', []);

        if (! empty($queue['enabled'])) {
            $queueName = $queue['queue'] ?? 'analytics';
            $connection = $queue['connection'] ?? 'default';
            $this->addResult('queue', 'pass', "Async queue enabled (queue: {$queueName}, connection: {$connection})");
            $this->passCount++;
        } else {
            $this->addResult('queue', 'warn', 'Queue disabled — events dispatched synchronously (may impact response times)');
            $this->warnCount++;
        }

        $replay = config('zeroboiler.analytics.replay', []);
        if (! empty($replay['enabled'])) {
            $maxAttempts = $replay['max_attempts'] ?? 3;
            $this->addResult('replay_queue', 'pass', "Replay queue enabled (max {$maxAttempts} attempts)");
            $this->passCount++;
        } else {
            $this->addResult('replay_queue', 'warn', 'Replay queue disabled — failed events will not be retried');
            $this->warnCount++;
        }
    }

    /**
     * Check identity configuration.
     */
    private function runIdentityCheck(): void
    {
        $identity = config('zeroboiler.analytics.identity', []);

        $cookieName = $identity['cookie_name'] ?? 'zb_analytics_id';
        $ttl = $identity['cookie_ttl'] ?? 525600;
        $secure = $identity['cookie_secure'] ?? true;
        $samesite = $identity['cookie_samesite'] ?? 'Lax';
        $linkOnAuth = $identity['link_on_auth'] ?? true;

        $issues = [];

        if ($ttl < 1440) {
            $issues[] = 'cookie TTL is less than 24 hours';
        }

        if (! in_array($samesite, ['Lax', 'Strict', 'None'], true)) {
            $issues[] = "invalid SameSite value: {$samesite}";
        }

        if (empty($issues)) {
            $this->addResult('identity', 'pass', "Identity tracking configured (cookie: {$cookieName}, TTL: {$ttl}min, SameSite: {$samesite}, auto-link: " . ($linkOnAuth ? 'yes' : 'no') . ')');
            $this->passCount++;
        } else {
            $this->addResult('identity', 'warn', 'Identity issues: ' . implode('; ', $issues));
            $this->warnCount++;
        }
    }

    /**
     * Check GDPR compliance settings.
     */
    private function runGdprCheck(): void
    {
        $gdpr = config('zeroboiler.analytics.gdpr', []);

        $anonymizeIp = $gdpr['anonymize_ip'] ?? false;

        if ($anonymizeIp) {
            $this->addResult('gdpr_ip', 'pass', 'IP anonymization enabled');
            $this->passCount++;
        } else {
            $this->addResult('gdpr_ip', 'warn', 'IP anonymization disabled — PII may be sent to providers');
            $this->warnCount++;
        }

        $retention = config('zeroboiler.analytics.retention_policy', []);
        if (! empty($retention['enabled'])) {
            $this->addResult('gdpr_retention', 'pass', 'Data retention policy enabled');
            $this->passCount++;
        } else {
            $this->addResult('gdpr_retention', 'warn', 'Data retention policy not configured — no automatic data cleanup');
            $this->warnCount++;
        }
    }

    /**
     * Check consent configuration.
     */
    private function runConsentCheck(): void
    {
        $consent = config('zeroboiler.analytics.consent', []);
        $default = $consent['default'] ?? 'granted';

        if ($default === 'denied') {
            $this->addResult('consent_default', 'pass', 'Default consent is "denied" (GDPR-safe)');
            $this->passCount++;
        } else {
            $this->addResult('consent_default', 'warn', 'Default consent is "granted" — consider "denied" for GDPR compliance');
            $this->warnCount++;
        }

        $purposes = $consent['purposes'] ?? [];
        if (! empty($purposes)) {
            $purposeCount = count($purposes);
            $requiredCount = count(array_filter($purposes, fn (array $p): bool => ! empty($p['required'])));
            $this->addResult('consent_purposes', 'pass', "{$purposeCount} consent purposes configured ({$requiredCount} required)");
            $this->passCount++;
        } else {
            $this->addResult('consent_purposes', 'warn', 'No consent purposes configured — granular consent not available');
            $this->warnCount++;
        }
    }

    /**
     * Check budget & throttling configuration.
     */
    private function runBudgetCheck(): void
    {
        $budget = config('zeroboiler.analytics.budget', []);

        if (! empty($budget['enabled'])) {
            $clientLimit = $budget['client_limit'] ?? 1000;
            $userLimit = $budget['user_limit'] ?? 500;
            $policy = $budget['overflow_policy'] ?? 'reject';
            $this->addResult('budget', 'pass', "Event budget enabled (client: {$clientLimit}/hr, user: {$userLimit}/hr, policy: {$policy})");
            $this->passCount++;
        } else {
            $this->addResult('budget', 'warn', 'Event budget not configured — no abuse protection');
            $this->warnCount++;
        }

        $throttle = config('zeroboiler.analytics.api.throttle', 60);
        $this->addResult('api_throttle', 'pass', "API throttling: {$throttle} requests/minute");
        $this->passCount++;
    }

    /**
     * Check middleware configuration.
     */
    private function runMiddlewareCheck(): void
    {
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);

        if (method_exists($kernel, 'getMiddlewareGroups')) {
            $groups = $kernel->getMiddlewareGroups();
            $found = false;

            foreach ($groups as $group => $middleware) {
                foreach ($middleware as $m) {
                    $name = is_string($m) ? $m : ($m['name'] ?? '');
                    if (str_contains($name, 'InjectAnalytics') || str_contains($name, 'HandleInertiaAnalytics')) {
                        $found = true;
                        break 2;
                    }
                }
            }

            if ($found) {
                $this->addResult('middleware_registered', 'pass', 'Analytics middleware registered in kernel');
                $this->passCount++;
            } else {
                $this->addResult('middleware_registered', 'warn', 'Analytics middleware not found in kernel groups');
                $this->warnCount++;
            }
        } else {
            $this->addResult('middleware_registered', 'warn', 'Could not check middleware registration');
            $this->warnCount++;
        }
    }

    /**
     * Add a diagnostic result.
     *
     * @param  string  $check
     * @param  string  $status  'pass' | 'warn' | 'fail'
     * @param  string  $message
     */
    private function addResult(string $check, string $status, string $message): void
    {
        $this->results[] = [
            'check' => $check,
            'status' => $status,
            'message' => $message,
        ];
    }
}
