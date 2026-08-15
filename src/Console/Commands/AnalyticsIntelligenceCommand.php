<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\AnalyticsIntelligenceGateway;

/**
 * Analytics Intelligence Dashboard command.
 *
 * Displays a comprehensive real-time health overview of the entire
 * analytics pipeline, including provider health, catalog coverage,
 * anomaly status, funnel health, churn signals, and privacy compliance.
 *
 * Designed for ops teams and SaaS monitoring dashboards.
 *
 * @since 71.0.0
 */
final class AnalyticsIntelligenceCommand extends Command
{
    protected $signature = 'zb:analytics:intelligence
        {--json : Output full dashboard as JSON}
        {--sections=* : Include only specified sections}
        {--exclude=* : Exclude specified sections}
        {--heartbeat : Output lightweight heartbeat only}
        {--watch : Continuous monitoring (updates every 30s)}';

    protected $description = 'Real-time SaaS analytics intelligence dashboard';

    private AnalyticsIntelligenceGateway $gateway;

    public function __construct(AnalyticsIntelligenceGateway $gateway): void
    {
        parent::__construct();
        $this->gateway = $gateway;
    }

    #[Override]
    public function handle(): int
    {
        $outputJson = (bool) $this->option('json');
        $heartbeat = (bool) $this->option('heartbeat');
        $watch = (bool) $this->option('watch');
        $sections = $this->option('sections');
        $exclude = $this->option('exclude');

        if ($heartbeat) {
            return $this->renderHeartbeat($outputJson);
        }

        $options = [];
        if (is_array($sections) && $sections !== []) {
            $options['include'] = $sections;
        }
        if (is_array($exclude) && $exclude !== []) {
            $options['exclude'] = $exclude;
        }

        if ($watch) {
            return $this->watchDashboard($options, $outputJson);
        }

        $dashboard = $this->gateway->dashboard($options);

        if ($outputJson) {
            $this->line(json_encode($dashboard, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->renderDashboard($dashboard);

        return self::SUCCESS;
    }

    /**
     * Render the full intelligence dashboard to the console.
     *
     * @param  array<string, mixed>  $dashboard
     */
    private function renderDashboard(array $dashboard): void
    {
        $this->info('🧠 ZeroBoiler Analytics Intelligence Dashboard');
        $this->line('   Version: <info>' . $dashboard['version'] . '</info>');
        $this->line('   Time: <info>' . $dashboard['timestamp'] . '</info>');
        $this->line('   Overall: <info>' . $dashboard['overall_score'] . '/100</info> — <comment>' . $dashboard['overall_grade'] . '</comment>');
        $this->newLine();

        // ── Provider Health ─────────────────────────────────────
        $providers = $dashboard['provider_health'] ?? [];
        $this->info('📡 Provider Health');
        $this->table(
            ['Provider', 'Status', 'Configured', 'Healthy'],
            $this->formatProviderTable($providers),
        );
        $this->newLine();

        // ── Catalog Coverage ──────────────────────────────────────
        $coverage = $dashboard['catalog_coverage'] ?? [];
        $this->info('📦 Catalog Coverage');
        $this->line('   Total events: <info>' . ($coverage['total'] ?? 0) . '</info>');
        $this->line('   Industry standard: <info>' . ($coverage['industry_standard_coverage'] ?? 0) . '%</info>');
        $this->line('   Starter coverage: <info>' . ($coverage['starter_coverage'] ?? 0) . '%</info>');
        $this->line('   Essential coverage: <info>' . ($coverage['essential_coverage'] ?? 0) . '%</info>');
        if (($coverage['gap_count'] ?? 0) > 0) {
            $this->line('   Gaps: <comment>' . ($coverage['gap_count'] ?? 0) . '</comment> event(s)');
        }
        $this->newLine();

        // ── Anomaly Summary ───────────────────────────────────────
        $anomaly = $dashboard['anomaly_summary'] ?? [];
        $anomalyIcon = ($anomaly['status'] ?? '') === 'nominal' ? '✅' : (($anomaly['status'] ?? '') === 'alerting' ? '🚨' : '⚪');
        $this->info('🔍 Anomaly Detection');
        $this->line("   {$anomalyIcon} Status: <info>" . ($anomaly['status'] ?? 'not_configured') . '</info>');
        $this->line('   Recent anomalies: <info>' . ($anomaly['recent_anomalies'] ?? 0) . '</info>');
        $severity = $anomaly['severity_breakdown'] ?? [];
        if ($severity !== []) {
            $this->line('   Severity: critical=' . ($severity['critical'] ?? 0) . ', warning=' . ($severity['warning'] ?? 0) . ', info=' . ($severity['info'] ?? 0));
        }
        $this->newLine();

        // ── Funnel Health ─────────────────────────────────────────
        $funnel = $dashboard['funnel_health'] ?? [];
        $this->info('📈 Funnel Health');
        $this->line('   Signup → Trial: <info>' . $this->formatPercent($funnel['signup_to_trial'] ?? null) . '</info>');
        $this->line('   Trial → Paid: <info>' . $this->formatPercent($funnel['trial_to_paid'] ?? null) . '</info>');
        $this->line('   Signup → Paid: <info>' . $this->formatPercent($funnel['signup_to_paid'] ?? null) . '</info>');
        $this->newLine();

        // ── Pipeline Health ────────────────────────────────────────
        $pipeline = $dashboard['pipeline_health'] ?? [];
        $pipelineIcon = ($pipeline['status'] ?? '') === 'healthy' ? '✅' : '⚠️';
        $this->info('⚙️ Pipeline Health');
        $this->line("   {$pipelineIcon} Status: <info>" . ($pipeline['status'] ?? 'unknown') . '</info>');
        $this->line('   Queue: <info>' . (($pipeline['queue_enabled'] ?? false) ? 'enabled' : 'disabled') . '</info>');
        $this->line('   Auto UTM: <info>' . (($pipeline['auto_utm'] ?? false) ? 'yes' : 'no') . '</info>');
        $this->line('   PII: <info>' . (($pipeline['pii_enabled'] ?? false) ? 'enabled' : 'disabled') . '</info>');
        $this->line('   Sampling: <info>' . (($pipeline['sampling_enabled'] ?? false) ? (($pipeline['sampling_rate'] ?? 1.0) * 100) . '%' : 'disabled') . '</info>');
        $this->newLine();

        // ── Privacy Compliance ────────────────────────────────────
        $privacy = $dashboard['privacy_compliance'] ?? [];
        $privacyIcon = ($privacy['status'] ?? '') === 'compliant' ? '✅' : '⚠️';
        $this->info('🔒 Privacy Compliance');
        $this->line("   {$privacyIcon} Status: <info>" . ($privacy['status'] ?? 'unknown') . '</info>');
        $this->line('   Consent default: <info>' . ($privacy['consent_default'] ?? 'unknown') . '</info>');
        $this->line('   Consent log: <info>' . (($privacy['consent_log_enabled'] ?? false) ? 'enabled' : 'disabled') . '</info>');
        $this->line('   GDPR: <info>' . (($privacy['gdpr_compliant'] ?? false) ? 'compliant' : 'non-compliant') . '</info>');
        $this->newLine();

        // ── Alerts ────────────────────────────────────────────────
        $alerts = $dashboard['alerts'] ?? [];
        if ($alerts !== []) {
            $this->warn('⚠️  Active Alerts');
            foreach ($alerts as $alert) {
                $icon = ($alert['severity'] ?? '') === 'critical' ? '🚨' : '⚠️';
                $this->line("   {$icon} [" . strtoupper($alert['severity'] ?? 'unknown') . '] ' . ($alert['source'] ?? '') . ': ' . ($alert['message'] ?? ''));
            }
        } else {
            $this->info('✅ No active alerts');
        }
    }

    /**
     * Render the lightweight heartbeat.
     */
    private function renderHeartbeat(bool $outputJson): int
    {
        $heartbeat = $this->gateway->heartbeat();

        if ($outputJson) {
            $this->line(json_encode($heartbeat, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $statusColor = ($heartbeat['status'] ?? '') === 'healthy' ? 'info'
            : (($heartbeat['status'] ?? '') === 'degraded' ? 'comment' : 'error');

        $this->line('💓 ' . $heartbeat['status']);
        $this->line('   Score: <info>' . $heartbeat['score'] . '</info>/100 (' . $heartbeat['grade'] . ')');
        $this->line('   Providers: <info>' . $heartbeat['enabled_providers'] . '</info>/' . $heartbeat['total_providers']);
        $this->line('   Events: <info>' . $heartbeat['catalog_events'] . '</info>');

        return ($heartbeat['status'] ?? '') === 'healthy' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Watch mode — continuously render dashboard at 30s intervals.
     *
     * @param  array<string, mixed>  $options
     */
    private function watchDashboard(array $options, bool $outputJson): int
    {
        $this->info('🔄 Watch mode — refreshing every 30s (Ctrl+C to stop)');
        $this->newLine();

        while (true) {
            $dashboard = $this->gateway->dashboard($options);

            if ($outputJson) {
                $this->line(json_encode($dashboard, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } else {
                // Clear screen
                $this->output->write("\033\143");
                $this->renderDashboard($dashboard);
            }

            sleep(30);
        }
    }

    /**
     * Format the provider health data as a table array.
     *
     * @param  array<string, mixed>  $providerHealth
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function formatProviderTable(array $providerHealth): array
    {
        $rows = [];
        $providers = $providerHealth['providers'] ?? [];

        $labels = [
            'ga4' => 'GA4',
            'gtm' => 'GTM',
            'meta_pixel' => 'Meta Pixel',
            'plausible' => 'Plausible',
            'posthog' => 'PostHog',
            'mixpanel' => 'Mixpanel',
            'amplitude' => 'Amplitude',
            'tiktok' => 'TikTok',
            'linkedin' => 'LinkedIn',
        ];

        foreach ($labels as $key => $label) {
            $p = $providers[$key] ?? [];
            $rows[] = [
                $label,
                ($p['enabled'] ?? false) ? '<fg=green>ON</>' : '<fg=yellow>OFF</>',
                ($p['configured'] ?? false) ? '✅' : '❌',
                ($p['healthy'] ?? false) ? '✅' : '⚠️',
            ];
        }

        return $rows;
    }

    /**
     * Format a percentage value for display.
     */
    private function formatPercent(?float $value): string
    {
        if ($value === null) {
            return 'N/A';
        }

        return number_format($value * 100, 1) . '%';
    }
}
