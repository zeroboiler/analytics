<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;

/**
 * Display an overview of the analytics configuration and status.
 *
 * Shows which providers are enabled, consent state, config values,
 * and registered event types — useful for debugging and monitoring.
 */
class AnalyticsOverviewCommand extends Command
{
    protected $signature = 'zb:analytics:overview';

    protected $description = 'Display analytics configuration overview and status';

    #[\Override]
    public function handle(): int
    {
        $this->info('📊 ZeroBoiler Analytics Overview');
        $this->newLine();

        /** @var array<string, mixed> $config */
        $config = config('zeroboiler.analytics', []);

        // Providers
        $this->line('<fg=cyan;options=bold>PROVIDERS</>');
        $this->line('─────────────────────────────');

        $providerDetails = [
            'ga4' => function (array $c): void {
                $this->line('    Measurement ID: '.($c['measurement_id'] ?? '—'));
                $this->line('    API Secret: '.((($c['api_secret'] ?? '') !== '') ? substr($c['api_secret'], 0, 8).'...' : '—'));
            },
            'gtm' => function (array $c): void {
                $this->line('    Container ID: '.($c['container_id'] ?? '—'));
            },
            'meta_pixel' => function (array $c): void {
                $this->line('    Pixel ID: '.($c['id'] ?? '—'));
                $this->line('    Access Token: '.((($c['access_token'] ?? '') !== '') ? substr($c['access_token'], 0, 8).'...' : '—'));
            },
            'plausible' => function (array $c): void {
                $this->line('    Domain: '.($c['domain'] ?? '—'));
                $this->line('    API Key: '.((($c['api_key'] ?? '') !== '') ? substr($c['api_key'], 0, 8).'...' : '—'));
            },
            'posthog' => function (array $c): void {
                $this->line('    API Key: '.((($c['api_key'] ?? '') !== '') ? substr($c['api_key'], 0, 8).'...' : '—'));
                $this->line('    Host: '.($c['host'] ?? '—'));
            },
            'webhook' => function (array $c): void {
                $this->line('    URL: '.($c['url'] ?? '—'));
                $this->line('    Timeout: '.($c['timeout'] ?? 5).'s');
                $this->line('    Sign Payloads: '.(($c['sign'] ?? false) ? '✅' : '🚫'));
            },
        ];

        foreach (array_keys($providerDetails) as $provider) {
            /** @var array<string, mixed> $providerConfig */
            $providerConfig = $config[$provider] ?? [];
            $enabled = (bool) ($providerConfig['enabled'] ?? false);
            $status = $enabled ? '<fg=green>●</> enabled' : '<fg=yellow>○</> disabled';
            $this->line("  {$status}  {$provider}");

            if ($enabled) {
                $providerDetails[$provider]($providerConfig);
            }
        }

        // Consent
        $this->newLine();
        $this->line('<fg=cyan;options=bold>CONSENT</>');
        $this->line('─────────────────────────────');

        $consentDefault = $config['consent']['default'] ?? 'granted';
        $this->line('  Default: '.$consentDefault);

        // Auto-Track
        $this->newLine();
        $this->line('<fg=cyan;options=bold>AUTO-TRACK</>');
        $this->line('─────────────────────────────');

        $autoTrack = $config['auto_track'] ?? [];
        $autoEnabled = (bool) ($autoTrack['enabled'] ?? true);
        $this->line('  Enabled: '.($autoEnabled ? '✅' : '🚫'));

        $events = $autoTrack['events'] ?? [];
        foreach ($events as $event => $toggle) {
            $icon = $toggle ? '✅' : '🚫';
            $this->line("  {$icon} {$event}");
        }

        // Queue
        $this->newLine();
        $this->line('<fg=cyan;options=bold>QUEUE</>');
        $this->line('─────────────────────────────');

        $queue = $config['queue'] ?? [];
        $queueEnabled = (bool) ($queue['enabled'] ?? true);
        $this->line('  Enabled: '.($queueEnabled ? '✅' : '🚫'));
        $this->line('  Queue: '.($queue['queue'] ?? 'analytics'));
        $connection = $queue['connection'] ?? 'default';
        $this->line('  Connection: '.$connection);

        // Replay Queue
        $this->newLine();
        $this->line('<fg=cyan;options=bold>REPLAY QUEUE</>');
        $this->line('─────────────────────────────');

        $replay = $config['replay'] ?? [];
        $replayEnabled = (bool) ($replay['enabled'] ?? true);
        $this->line('  Enabled: '.($replayEnabled ? '✅' : '🚫'));
        $this->line('  Max Attempts: '.($replay['max_attempts'] ?? 3));
        $this->line('  Base Delay: '.($replay['base_delay'] ?? 1.0).'s');
        $this->line('  Max Delay: '.($replay['max_delay'] ?? 60.0).'s');
        $this->line('  Jitter: '.(($replay['jitter'] ?? 0.2) * 100).'%');

        // Identity
        $this->newLine();
        $this->line('<fg=cyan;options=bold>IDENTITY</>');
        $this->line('─────────────────────────────');

        $identity = $config['identity'] ?? [];
        $this->line('  Cookie: '.($identity['cookie_name'] ?? 'zb_analytics_id'));
        $this->line('  TTL: '.($identity['cookie_ttl'] ?? 525600).' minutes');
        $this->line('  Secure: '.(($identity['cookie_secure'] ?? true) ? '✅' : '🚫'));
        $this->line('  SameSite: '.($identity['cookie_samesite'] ?? 'Lax'));

        // Ecommerce
        $this->newLine();
        $this->line('<fg=cyan;options=bold>ECOMMERCE</>');
        $this->line('─────────────────────────────');

        $ecommerce = $config['ecommerce'] ?? [];
        $this->line('  Currency: '.($ecommerce['currency'] ?? 'USD'));
        $this->line('  Brand: '.($ecommerce['brand'] ?? '(none)'));

        // Track Links
        $this->newLine();
        $this->line('<fg=cyan;options=bold>AUTO-TRACK LINKS</>');
        $this->line('─────────────────────────────');

        $trackLinks = $config['track_links'] ?? [];
        $linksEnabled = (bool) ($trackLinks['enabled'] ?? false);
        $this->line('  Enabled: '.($linksEnabled ? '✅' : '🚫'));
        $this->line('  External: '.(($trackLinks['track_external'] ?? true) ? '✅' : '🚫'));
        $this->line('  Internal: '.(($trackLinks['track_internal'] ?? false) ? '✅' : '🚫'));
        $this->line('  Prefix: '.($trackLinks['external_prefix'] ?? 'outbound'));

        // Available features
        $this->newLine();
        $this->line('<fg=cyan;options=bold>EVENT CATALOG</>');
        $this->line('─────────────────────────────');

        $catalogSummary = \ZeroBoiler\Analytics\Events\EventCatalog::byCategory();
        foreach ($catalogSummary as $category => $events) {
            $count = count($events);
            $names = array_map(fn (array $e): string => $e['name'], $events);
            $this->line("  <fg=green>{$count}</> {$category}");
            foreach (array_chunk($names, 5) as $chunk) {
                $this->line('    '.implode(', ', $chunk));
            }
        }
        $this->line('  <fg=green;options=bold>'.\ZeroBoiler\Analytics\Events\EventCatalog::count().'</> total events');

        // Provider summary
        $this->newLine();
        $this->line('<fg=cyan;options=bold>PROVIDER SUMMARY</>');
        $this->line('─────────────────────────────');

        try {
            $providerSummary = \ZeroBoiler\Analytics\Facades\Analytics::providerSummary();
            foreach ($providerSummary as $name => $info) {
                $status = $info['enabled'] ? '<fg=green>● enabled</>' : '<fg=yellow>○ disabled</>';
                $detail = $info['id'] ?? '—';
                $this->line("  {$status}  {$name}: {$detail}");
            }
        } catch (\Throwable) {
            $this->line('  <fg=yellow>(unavailable — run within Laravel app context)</>');
        }

        // Registered features
        $this->newLine();
        $this->line('<fg=cyan;options=bold>REGISTERED FEATURES</>');
        $this->line('─────────────────────────────');
        $features = [
            'GA4 Measurement Protocol (server-side)',
            'GTM dataLayer push (server-side)',
            'Meta Pixel CAPI (server-side)',
            'Plausible Analytics (server-side)',
            'PostHog Analytics (server-side)',
            'Consent Mode v2 (GDPR)',
            'Blade directives',
            'Auto-inject middleware',
            'Event catalog (ecommerce, SaaS, engagement, custom)',
            'Event schema registry (50+ typed schemas)',
            'Middleware stack (priority-ordered, composable)',
            'Event context builder (auto-collect request context)',
            'Server-side lifecycle tracker',
            'Inertia middleware (prop injection)',
            'API endpoints (track, batch, identify, pageview, consent, health)',
            'JS client library (Svelte/Inertia)',
            'JS batch queue + auto flush',
            'JS screen view tracking (SPA navigation)',
            'JS A/B test exposure tracking',
            'JS notification tracking',
            'JS auto form tracking',
            'JS auto error tracking',
            'JS performance / Web Vitals tracking',
            'JS auto link click tracking',
            'JS user properties + identity alias',
            'JS server-side page view (ad-blocker resistant)',
            'Queued async dispatch + trackAsync() facade',
            'User identity tracking',
            'Session & funnel tracking',
            'Ecommerce analytics service',
            'SaaS analytics service',
            'Revenue analytics service',
            'Event validation & deduplication',
            'Debug mode',
            'Admin commands (test, overview)',
            'Event Catalog (static catalogs + unified registry)',
            'GDPR identity reset (GA4 + PostHog)',
            'Analytics DataBus (conditional event routing)',
            'directDispatch() — bypass DataBus routing',
            'Webhook tracker (generic HTTP backend with HMAC signing)',
            'Audit log middleware (event audit trail)',
            'Wishlist e-commerce event (GA4 + Meta)',
            'GET /api/analytics/catalog endpoint',
            'Revenue report command (zb:analytics:revenue-report)',
            'Export catalog command (zb:analytics:export)',
            'CohortAnalyticsService (retention, churn, conversion, migration)',
            'Event replay queue (exponential backoff retry)',
            'Health check (metrics, replay, catalog summary)',
            'Session analytics service (session recording, summaries, end-of-session dispatch)',
            'Event aggregation service (real-time counting, top events, health diagnostics)',
            'Health diagnostic command (zb:analytics:health)',
        ];
        foreach ($features as $feature) {
            $this->line("  ✅ {$feature}");
        }

        $this->newLine();
        $this->info('✨ Overview complete.');

        return self::SUCCESS;
    }
}
