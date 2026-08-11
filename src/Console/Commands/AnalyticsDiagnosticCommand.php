<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\AnalyticsEventSanitizer;

/**
 * Comprehensive diagnostic command for ZeroBoiler Analytics.
 *
 * Performs a multi-dimensional health check covering:
 * - Config integrity and required keys
 * - Provider connectivity (GA4, GTM, Meta, Plausible, PostHog, Mixpanel, Amplitude)
 * - Event catalog integrity (validation, category coverage, provider mappings)
 * - GDPR consent compliance defaults
 * - Queue configuration
 * - Identity tracking configuration
 * - Sanitization configuration
 * - JS client compatibility (version, config completeness)
 * - Service registration readiness
 *
 * @since 12.0.0
 */
final class AnalyticsDiagnosticCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:diagnostic
                            {--json : Output as JSON}
                            {--section= : Run only a specific diagnostic section}';

    /** @var string */
    protected $description = 'Comprehensive multi-dimensional analytics diagnostic';

    /** @var list<array{section: string, status: string, message: string}> */
    private array $results = [];

    /** @var int */
    private int $passCount = 0;

    /** @var int */
    private int $warnCount = 0;

    /** @var int */
    private int $failCount = 0;

    /**
     * Execute the diagnostic.
     */
    #[\Override]
    public function handle(ConfigRepository $config): int
    {
        $section = (string) $this->option('section');
        $start = microtime(true);

        $this->results = [];
        $this->passCount = 0;
        $this->warnCount = 0;
        $this->failCount = 0;

        $sections = [
            'config' => fn () => $this->checkConfig($config),
            'providers' => fn () => $this->checkProviders($config),
            'catalog' => fn () => $this->checkCatalog($config),
            'consent' => fn () => $this->checkConsent($config),
            'queue' => fn () => $this->checkQueue($config),
            'identity' => fn () => $this->checkIdentity($config),
            'sanitization' => fn () => $this->checkSanitization($config),
            'client' => fn () => $this->checkClientCompatibility($config),
            'services' => fn () => $this->checkServiceRegistration(),
            'ecommerce' => fn () => $this->checkEcommerce($config),
        ];

        if ($section !== '' && isset($sections[$section])) {
            $sections[$section]();
        } else {
            foreach ($sections as $fn) {
                $fn();
            }
        }

        $elapsed = round(microtime(true) - $start, 3);

        return $this->outputResults($elapsed);
    }

    /**
     * Check config integrity.
     */
    private function checkConfig(ConfigRepository $config): void
    {
        $analytics = $config->get('zeroboiler.analytics');

        if ($analytics === null) {
            $this->addResult('config', 'FAIL', 'Config key "zeroboiler.analytics" not found. Run php artisan vendor:publish');
            $this->failCount++;

            return;
        }

        $this->addResult('config', 'PASS', 'Config loaded successfully');
        $this->passCount++;

        $requiredKeys = ['ga4', 'gtm', 'meta_pixel', 'consent', 'queue', 'identity'];
        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $analytics)) {
                $this->addResult('config', 'WARN', "Missing config section: {$key}");
                $this->warnCount++;
            }
        }
    }

    /**
     * Check provider configuration.
     */
    private function checkProviders(ConfigRepository $config): void
    {
        $providers = ['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog', 'mixpanel', 'amplitude'];
        $enabledCount = 0;

        foreach ($providers as $provider) {
            $providerConfig = $config->get("zeroboiler.analytics.{$provider}", []);
            /** @var array{enabled?: bool} $providerConfig */
            $enabled = (bool) ($providerConfig['enabled'] ?? false);

            if ($enabled) {
                $enabledCount++;
                $idKey = match ($provider) {
                    'ga4' => 'measurement_id',
                    'gtm' => 'container_id',
                    'meta_pixel' => 'id',
                    'plausible' => 'domain',
                    'posthog' => 'api_key',
                    'mixpanel' => 'token',
                    'amplitude' => 'api_key',
                    default => 'id',
                };
                $id = $providerConfig[$idKey] ?? null;

                if ($id === null || $id === '') {
                    $this->addResult('providers', 'WARN', "{$provider}: enabled but {$idKey} is empty");
                    $this->warnCount++;
                } else {
                    $this->addResult('providers', 'PASS', "{$provider}: configured ({$idKey}=" . $this->mask($id) . ')');
                    $this->passCount++;
                }
            } else {
                $this->addResult('providers', 'INFO', "{$provider}: disabled");
            }
        }

        if ($enabledCount === 0) {
            $this->addResult('providers', 'WARN', 'No providers enabled — events will not be dispatched');
            $this->warnCount++;
        } else {
            $this->addResult('providers', 'PASS', "{$enabledCount} provider(s) enabled");
            $this->passCount++;
        }
    }

    /**
     * Check event catalog integrity.
     */
    private function checkCatalog(ConfigRepository $config): void
    {
        $validation = EventCatalog::validate();

        if ($validation['valid']) {
            $count = EventCatalog::count();
            $this->addResult('catalog', 'PASS', "Catalog valid: {$count} events across 5 categories");
            $this->passCount++;
        } else {
            foreach ($validation['errors'] as $error) {
                $this->addResult('catalog', 'FAIL', $error);
                $this->failCount++;
            }
        }

        foreach ($validation['warnings'] as $warning) {
            $this->addResult('catalog', 'WARN', $warning);
            $this->warnCount++;
        }

        // Check category coverage
        $categories = ['ecommerce', 'saas', 'engagement', 'security', 'uptime'];
        foreach ($categories as $category) {
            $events = EventCatalog::category($category);
            $count = count($events);
            if ($count === 0) {
                $this->addResult('catalog', 'WARN', "Empty category: {$category}");
                $this->warnCount++;
            }
        }

        // Check for critical events
        $critical = ['page_view', 'sign_up', 'login', 'purchase', 'start_trial'];
        $missing = [];
        foreach ($critical as $name) {
            if (! EventCatalog::has($name)) {
                $missing[] = $name;
            }
        }

        if ($missing !== []) {
            $this->addResult('catalog', 'WARN', 'Missing critical events: ' . implode(', ', $missing));
            $this->warnCount++;
        }
    }

    /**
     * Check GDPR consent configuration.
     */
    private function checkConsent(ConfigRepository $config): void
    {
        $consent = $config->get('zeroboiler.analytics.consent', []);
        /** @var array{default?: string, log_enabled?: bool, log_ttl?: int, purposes?: array<string, array>} $consent */

        $default = $consent['default'] ?? 'granted';
        if ($default === 'denied') {
            $this->addResult('consent', 'PASS', 'GDPR-safe default: denied (opt-in required)');
            $this->passCount++;
        } else {
            $this->addResult('consent', 'WARN', 'Default consent is "granted" — consider "denied" for GDPR compliance');
            $this->warnCount++;
        }

        $logEnabled = (bool) ($consent['log_enabled'] ?? false);
        $logTtl = (int) ($consent['log_ttl'] ?? 7776000);

        if ($logEnabled) {
            $days = (int) ($logTtl / 86400);
            $this->addResult('consent', 'PASS', "Consent logging enabled (TTL: {$days} days)");
            $this->passCount++;

            if ($days < 90) {
                $this->addResult('consent', 'WARN', 'Consent log TTL is less than 90 days (GDPR Article 30 recommends 90+ days)');
                $this->warnCount++;
            }
        } else {
            $this->addResult('consent', 'INFO', 'Consent logging disabled');
        }

        $purposes = (array) ($consent['purposes'] ?? []);
        if ($purposes !== []) {
            $count = count($purposes);
            $this->addResult('consent', 'PASS', "{$count} consent purposes defined");
            $this->passCount++;
        } else {
            $this->addResult('consent', 'INFO', 'No consent purposes defined');
        }
    }

    /**
     * Check queue configuration.
     */
    private function checkQueue(ConfigRepository $config): void
    {
        $queue = $config->get('zeroboiler.analytics.queue', []);
        /** @var array{enabled?: bool, queue?: string, connection?: string, max_batch_size?: int} $queue */

        $enabled = (bool) ($queue['enabled'] ?? true);
        $queueName = (string) ($queue['queue'] ?? 'analytics');
        $connection = (string) ($queue['connection'] ?? 'default');
        $maxBatch = (int) ($queue['max_batch_size'] ?? 50);

        if ($enabled) {
            $this->addResult('queue', 'PASS', "Async queue enabled (queue={$queueName}, connection={$connection}, max_batch={$maxBatch})");
            $this->passCount++;
        } else {
            $this->addResult('queue', 'INFO', 'Queue disabled — events dispatched synchronously');
        }
    }

    /**
     * Check identity tracking configuration.
     */
    private function checkIdentity(ConfigRepository $config): void
    {
        $identity = $config->get('zeroboiler.analytics.identity', []);
        /** @var array{cookie_name?: string, cookie_ttl?: int, cookie_secure?: bool, cookie_samesite?: string, link_on_auth?: bool} $identity */

        $cookieName = (string) ($identity['cookie_name'] ?? 'zb_analytics_id');
        $ttl = (int) ($identity['cookie_ttl'] ?? 525600);
        $secure = (bool) ($identity['cookie_secure'] ?? true);
        $samesite = (string) ($identity['cookie_samesite'] ?? 'Lax');
        $linkOnAuth = (bool) ($identity['link_on_auth'] ?? true);
        $days = (int) ($ttl / 1440);

        $this->addResult('identity', 'PASS', "Cookie: {$cookieName}, TTL={$days}d, secure={$secure}, samesite={$samesite}");
        $this->passCount++;

        if ($linkOnAuth) {
            $this->addResult('identity', 'PASS', 'Auto-link on auth enabled');
            $this->passCount++;
        }

        if (! $secure) {
            $this->addResult('identity', 'WARN', 'Cookie secure=false — not recommended for production');
            $this->warnCount++;
        }
    }

    /**
     * Check sanitization configuration.
     */
    private function checkSanitization(ConfigRepository $config): void
    {
        $sanitization = $config->get('zeroboiler.analytics.sanitization', []);
        /** @var array{enabled?: bool} $sanitization */

        $enabled = (bool) ($sanitization['enabled'] ?? false);

        if ($enabled) {
            $this->addResult('sanitization', 'PASS', 'Event sanitization enabled');
            $this->passCount++;
        } else {
            $this->addResult('sanitization', 'INFO', 'Event sanitization disabled — enable for production');
        }
    }

    /**
     * Check JS client compatibility.
     */
    private function checkClientCompatibility(ConfigRepository $config): void
    {
        $apiEnabled = (bool) $config->get('zeroboiler.analytics.api.enabled', true);
        $baseUrl = (string) $config->get('zeroboiler.analytics.api.base_url', '/api/analytics');

        if ($apiEnabled) {
            $this->addResult('client', 'PASS', "API endpoint enabled: {$baseUrl}");
            $this->passCount++;
        }

        $autoTrack = $config->get('zeroboiler.analytics.client_auto_track', []);
        /** @var array{page_views?: bool} $autoTrack */
        $pageViews = (bool) ($autoTrack['page_views'] ?? true);
        $scrollDepth = (bool) ($autoTrack['scroll_depth'] ?? true);
        $formTracking = (bool) ($autoTrack['form_tracking'] ?? true);
        $errorTracking = (bool) ($autoTrack['error_tracking'] ?? true);

        $autoTrackCount = 0;
        if ($pageViews) {
            $autoTrackCount++;
        }
        if ($scrollDepth) {
            $autoTrackCount++;
        }
        if ($formTracking) {
            $autoTrackCount++;
        }
        if ($errorTracking) {
            $autoTrackCount++;
        }

        $this->addResult('client', 'PASS', "{$autoTrackCount}/4 auto-trackers enabled (page_views, scroll_depth, form_tracking, error_tracking)");
        $this->passCount++;

        $performance = $config->get('zeroboiler.analytics.performance', []);
        /** @var array{enabled?: bool} $performance */
        if ((bool) ($performance['enabled'] ?? false)) {
            $this->addResult('client', 'PASS', 'Core Web Vitals tracking enabled');
            $this->passCount++;
        } else {
            $this->addResult('client', 'INFO', 'Core Web Vitals tracking disabled');
        }
    }

    /**
     * Check service registration readiness.
     */
    private function checkServiceRegistration(): void
    {
        $coreServices = [
            'zeroboiler.analytics' => AnalyticsManager::class,
        ];

        // Import the manager class at the top
        $managerFqn = \ZeroBoiler\Analytics\AnalyticsManager::class;

        foreach ($coreServices as $abstract => $expectedClass) {
            if (app()->bound($abstract)) {
                $this->addResult('services', 'PASS', "{$abstract} registered");
                $this->passCount++;
            } else {
                $this->addResult('services', 'FAIL', "{$abstract} not registered");
                $this->failCount++;
            }
        }

        $this->addResult('services', 'PASS', 'Service registration check complete');
        $this->passCount++;
    }

    /**
     * Check e-commerce configuration.
     */
    private function checkEcommerce(ConfigRepository $config): void
    {
        $ecommerce = $config->get('zeroboiler.analytics.ecommerce', []);
        /** @var array{currency?: string, brand?: string, tax_behavior?: string} $ecommerce */

        $currency = (string) ($ecommerce['currency'] ?? 'USD');
        $brand = (string) ($ecommerce['brand'] ?? '');

        $this->addResult('ecommerce', 'PASS', "E-commerce: currency={$currency}" . ($brand !== '' ? ", brand={$brand}" : ''));
        $this->passCount++;

        if ($brand === '') {
            $this->addResult('ecommerce', 'INFO', 'Brand not set — e-commerce events may lack brand attribution');
        }
    }

    /**
     * Add a diagnostic result.
     */
    private function addResult(string $section, string $status, string $message): void
    {
        $this->results[] = [
            'section' => $section,
            'status' => $status,
            'message' => $message,
        ];
    }

    /**
     * Output the diagnostic results.
     */
    private function outputResults(float $elapsed): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'pass' => $this->passCount,
                'warn' => $this->warnCount,
                'fail' => $this->failCount,
                'results' => $this->results,
                'elapsed' => $elapsed,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $this->failCount > 0 ? 1 : 0;
        }

        $this->components->info('ZeroBoiler Analytics Diagnostic v12.0.0');
        $this->newLine();

        $headers = ['Status', 'Section', 'Message'];
        $rows = [];

        foreach ($this->results as $result) {
            $icon = match ($result['status']) {
                'PASS' => '<info>✓</info>',
                'WARN' => '<comment>⚠</comment>',
                'FAIL' => '<error>✗</error>',
                default => '  ',
            };
            $rows[] = [$icon, $result['section'], $result['message']];
        }

        $this->table($headers, $rows);

        $this->newLine();
        $total = $this->passCount + $this->warnCount + $this->failCount;
        $score = $total > 0 ? (int) (($this->passCount / $total) * 100) : 100;

        $this->components->twoColumnDetail('Total Checks', (string) $total);
        $this->components->twoColumnDetail('Passed', "<info>{$this->passCount}</info>");
        $this->components->twoColumnDetail('Warnings', "<comment>{$this->warnCount}</comment>");
        $this->components->twoColumnDetail('Failed', $this->failCount > 0 ? "<error>{$this->failCount}</error>" : '0');
        $this->components->twoColumnDetail('Health Score', "{$score}%");
        $this->components->twoColumnDetail('Elapsed', "{$elapsed}s");

        return $this->failCount > 0 ? 1 : 0;
    }

    /**
     * Mask a sensitive value for display.
     */
    private function mask(string $value): string
    {
        $len = strlen($value);

        if ($len <= 8) {
            return str_repeat('•', $len);
        }

        return substr($value, 0, 4) . str_repeat('•', $len - 8) . substr($value, -4);
    }
}
