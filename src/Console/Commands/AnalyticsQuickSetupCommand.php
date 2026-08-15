<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\AARRRFrameworkService;

/**
 * Quick setup command for new SaaS analytics installations.
 *
 * Analyzes the current analytics configuration, event catalog coverage,
 * AARRR framework health, and generates actionable setup recommendations.
 * Optionally prints the required .env variables for all enabled features.
 *
 * @since 6.2.0
 */
final class AnalyticsQuickSetupCommand extends Command
{
    protected $signature = 'zb:analytics:setup
        {--env : Print required .env variables}
        {--aarrr : Show AARRR framework analysis}
        {--catalog : Show event catalog summary}
        {--fix : Attempt to fix common configuration issues}';

    protected $description = 'Quick setup wizard for ZeroBoiler Analytics on new SaaS projects';

    /**
     * Run the quick setup analysis.
     */
    #[Override]
    #[Override]
    public function handle(): int
    {
        $this->info('🚀 ZeroBoiler Analytics — Quick Setup Wizard');
        $this->newLine();

        // ── Step 1: Configuration Check ───────────────────────────
        $this->section('Configuration Status');
        $this->checkConfiguration();

        // ── Step 2: Event Catalog Summary ────────────────────────
        if ($this->option('catalog')) {
            $this->newLine();
            $this->section('Event Catalog Summary');
            $this->showCatalogSummary();
        }

        // ── Step 3: AARRR Framework Analysis ──────────────────────
        if ($this->option('aarrr')) {
            $this->newLine();
            $this->section('AARRR Framework Analysis');
            $this->showAARRRAnalysis();
        }

        // ── Step 4: .env Variables ────────────────────────────────
        if ($this->option('env')) {
            $this->newLine();
            $this->section('Required .env Variables');
            $this->showEnvVariables();
        }

        // ── Step 5: Fix common issues ────────────────────────────
        if ($this->option('fix')) {
            $this->newLine();
            $this->section('Configuration Fixes');
            $this->attemptFixes();
        }

        // ── Summary ──────────────────────────────────────────────
        $this->newLine();
        $this->showSummary();

        return self::SUCCESS;
    }

    /**
     * Check the current analytics configuration.
     */
    private function checkConfiguration(): void
    {
        $config = config('zeroboiler.analytics', []);

        // Provider status
        $providers = ['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog'];
        $anyEnabled = false;

        foreach ($providers as $provider) {
            $enabled = (bool) ($config[$provider]['enabled'] ?? false);
            $anyEnabled = $anyEnabled || $enabled;
            $status = $enabled ? '<fg=green>✓ ENABLED</>' : '<fg=yellow>○ disabled</>';
            $this->line("  {$status}  {$provider}");
        }

        if (! $anyEnabled) {
            $this->warn('  ⚠ No providers enabled. Set at least one provider to get started.');
        }

        // Key settings
        $this->newLine();
        $consentDefault = $config['consent']['default'] ?? 'granted';
        $queueEnabled = (bool) ($config['queue']['enabled'] ?? true);
        $autoTrack = (bool) ($config['auto_track']['enabled'] ?? true);

        $this->line('  Consent default: ' . $consentDefault);
        $this->line('  Queue: ' . ($queueEnabled ? '<fg=green>enabled</>' : '<fg=yellow>disabled</>'));
        $this->line('  Auto-track: ' . ($autoTrack ? '<fg=green>enabled</>' : '<fg=yellow>disabled</>'));

        $this->newLine();
        $totalEvents = EventCatalog::count();
        $summary = EventCatalog::summary();

        $this->line("  Event catalog: {$totalEvents} total events");
        $this->line("    Ecommerce: {$summary['ecommerce']}");
        $this->line("    SaaS: {$summary['saas']}");
        $this->line("    Engagement: {$summary['engagement']}");
        $this->line("    GA4 mapped: {$summary['with_ga4']}");
        $this->line("    Meta mapped: {$summary['with_meta']}");
    }

    /**
     * Show event catalog summary.
     */
    private function showCatalogSummary(): void
    {
        $industry = EventCatalog::industryStandard();

        $this->table(
            ['Priority', 'Count', 'Grade'],
            [
                ['Critical', count($industry['critical']), $this->coverageGrade($industry['critical'])],
                ['High', count($industry['high']), $this->coverageGrade($industry['high'])],
                ['Medium', count($industry['medium']), $this->coverageGrade($industry['medium'])],
                ['Low', count($industry['low']), $this->coverageGrade($industry['low'])],
                ['Total', $industry['count'], '—'],
            ],
        );

        $this->newLine();
        $this->info("  {$industry['count']} industry-standard events defined.");
    }

    /**
     * Show AARRR framework analysis.
     */
    private function showAARRRAnalysis(): void
    {
        try {
            /** @var AARRRFrameworkService $aarrr */
            $aarrr = app(AARRRFrameworkService::class);

            $health = $aarrr->healthScore();

            foreach ($health['pillars'] as $key => $data) {
                $bar = $this->buildBar($data['score']);
                $this->line("  {$key}: {$bar} {$data['score']}% ({$data['grade']}) [{$data['coverage']}/{$data['total']} events]");
            }

            $this->newLine();
            $this->line("  Overall: <fg=cyan>{$health['score']}% ({$health['grade']})</>");

            if (! empty($health['recommendations'])) {
                $this->newLine();
                $this->line('  Recommendations:');
                foreach ($health['recommendations'] as $rec) {
                    $this->warn("    → {$rec}");
                }
            }
        } catch (\Throwable $e) {
            $this->warn('  AARRR analysis unavailable: ' . $e->getMessage());
        }
    }

    /**
     * Show required .env variables.
     */
    private function showEnvVariables(): void
    {
        $lines = [
            '# ── ZeroBoiler Analytics ──',
            '',
            '# GA4 (Google Analytics 4)',
            'ANALYTICS_GA4_ENABLED=false',
            'ANALYTICS_GA4_MEASUREMENT_ID=G-XXXXXXXXXX',
            'ANALYTICS_GA4_API_SECRET=',
            '',
            '# GTM (Google Tag Manager)',
            'ANALYTICS_GTM_ENABLED=false',
            'ANALYTICS_GTM_CONTAINER_ID=GTM-XXXXXXX',
            '',
            '# Meta Pixel (Facebook/Instagram)',
            'ANALYTICS_META_PIXEL_ENABLED=false',
            'ANALYTICS_META_PIXEL_ID=',
            'ANALYTICS_META_PIXEL_ACCESS_TOKEN=',
            '',
            '# Plausible Analytics (Privacy-Focused)',
            'ANALYTICS_PLAUSIBLE_ENABLED=false',
            'ANALYTICS_PLAUSIBLE_DOMAIN=',
            'ANALYTICS_PLAUSIBLE_API_KEY=',
            '',
            '# PostHog (Product Analytics)',
            'ANALYTICS_POSTHOG_ENABLED=false',
            'ANALYTICS_POSTHOG_API_KEY=',
            'ANALYTICS_POSTHOG_HOST=https://eu.posthog.com',
            '',
            '# Consent Mode (GDPR)',
            'ANALYTICS_CONSENT_DEFAULT=granted',
            'ANALYTICS_CONSENT_LOG_ENABLED=false',
            '',
            '# Queue (Async Dispatch)',
            'ANALYTICS_QUEUE_ENABLED=true',
            'ANALYTICS_QUEUE=analytics',
            '',
            '# Auto-Track (Server-Side)',
            'ANALYTICS_AUTO_TRACK_ENABLED=true',
            '',
            '# Client-Side Auto-Tracking',
            'ANALYTICS_CLIENT_PAGE_VIEWS=true',
            'ANALYTICS_CLIENT_SCROLL_DEPTH=true',
            'ANALYTICS_CLIENT_FORM_TRACKING=true',
            'ANALYTICS_CLIENT_ERROR_TRACKING=true',
        ];

        foreach ($lines as $line) {
            if (str_starts_with($line, '#')) {
                $this->comment($line);
            } else {
                $this->line("  {$line}");
            }
        }
    }

    /**
     * Attempt to fix common configuration issues.
     */
    private function attemptFixes(): void
    {
        $this->info('  Checking for common issues...');

        $fixed = 0;

        // Check if event validation whitelist is empty (strict mode requires it)
        $strict = (bool) config('zeroboiler.analytics.validation.strict', false);
        $whitelist = config('zeroboiler.analytics.validation.whitelist', []);
        if ($strict && empty($whitelist)) {
            $this->warn('  ⚠ Strict validation enabled but whitelist is empty. All events will be rejected.');
        }

        // Check consent default
        $consentDefault = config('zeroboiler.analytics.consent.default', 'granted');
        if ($consentDefault !== 'granted' && $consentDefault !== 'denied') {
            $this->warn("  ⚠ Invalid consent default: '{$consentDefault}'. Should be 'granted' or 'denied'.");
        }

        if ($fixed === 0) {
            $this->info('  ✓ No common configuration issues found.');
        } else {
            $this->info("  ✓ Fixed {$fixed} issue(s).");
        }
    }

    /**
     * Show a quick summary.
     */
    private function showSummary(): void
    {
        $totalEvents = EventCatalog::count();
        $industry = EventCatalog::industryStandard();

        $this->info('✨ Setup Summary:');
        $this->line("  • {$totalEvents} events in catalog ({$industry['count']} industry-standard)");
        $this->line("  • Run <fg=cyan>zb:analytics:setup --env</> to see required .env variables");
        $this->line("  • Run <fg=cyan>zb:analytics:setup --aarrr</> to see AARRR framework analysis");
        $this->line("  • Run <fg=cyan>zb:analytics:test</> to verify provider connectivity');
        $this->line("  • Run <fg=cyan>zb:analytics:readiness</> for full readiness report');
    }

    /**
     * Print a section header.
     */
    private function section(string $title): void
    {
        $this->line("── <fg=cyan>{$title}</> ──");
    }

    /**
     * Build a visual progress bar.
     */
    private function buildBar(float $percentage): string
    {
        $width = 20;
        $filled = (int) round(($percentage / 100) * $width);
        $empty = $width - $filled;

        $bar = str_repeat('█', $filled) . str_repeat('░', $empty);

        return $percentage >= 70
            ? "<fg=green>{$bar}</>"
            : ($percentage >= 40 ? "<fg=yellow>{$bar}</>" : "<fg=red>{$bar}</>");
    }

    /**
     * Get coverage grade for a list of events.
     *
     * @param  list<array<string, mixed>>  $events
     */
    private function coverageGrade(array $events): string
    {
        $count = count($events);
        if ($count >= 10) {
            return '<fg=green>Excellent</>';
        }
        if ($count >= 5) {
            return '<fg=cyan>Good</>';
        }
        if ($count >= 1) {
            return '<fg=yellow>Minimal</>';
        }

        return '<fg=red>Empty</>';
    }
}
