<?php

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

        // Available features
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
            'Server-side lifecycle tracker',
            'Inertia middleware (prop injection)',
            'API endpoints (track, batch, identify, consent)',
            'JS client library (Svelte/Inertia)',
            'Queued async dispatch',
            'User identity tracking',
            'Ecommerce analytics service',
            'Admin commands (test, overview)',
        ];
        foreach ($features as $feature) {
            $this->line("  ✅ {$feature}");
        }

        $this->newLine();
        $this->info('✨ Overview complete.');

        return self::SUCCESS;
    }
}
