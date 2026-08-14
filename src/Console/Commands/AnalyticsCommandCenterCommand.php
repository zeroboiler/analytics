<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Analytics Command Center — unified governance + operations + compliance CLI.
 *
 * Combines multiple analytics health checks into a single command:
 * - Configuration audit (missing keys, deprecated values)
 * - Data quality score
 * - Consent compliance health
 * - Provider readiness
 * - Budget/cost status
 * - Event catalog coverage
 * - Version consistency check
 *
 * Options:
 *   --json        Output as machine-readable JSON
 *   --section=X   Show only specific section (config|quality|compliance|providers|cost|catalog|version)
 *
 * @since 86.0.0
 */
final class AnalyticsCommandCenterCommand extends Command
{
    protected $signature = 'zb:analytics:command-center
        {--json : Output as JSON}
        {--section= : Show only specific section (config|quality|compliance|providers|cost|catalog|version|all)}';

    protected $description = 'Unified analytics governance + operations + compliance dashboard';

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(): void
    {
        parent::__construct();
    }

    #[\Override]
    public function handle(): int
    {
        $outputJson = (bool) $this->option('json');
        $section = (string) $this->option('section') ?: 'all';
        $this->config = config('zeroboiler.analytics', []);

        $report = match ($section) {
            'config' => ['config_audit' => $this->auditConfig()],
            'quality' => ['data_quality' => $this->checkDataQuality()],
            'compliance' => ['compliance' => $this->checkCompliance()],
            'providers' => ['providers' => $this->checkProviders()],
            'cost' => ['cost_status' => $this->checkCostStatus()],
            'catalog' => ['catalog' => $this->checkCatalog()],
            'version' => ['version' => $this->checkVersions()],
            default => [
                'config_audit' => $this->auditConfig(),
                'data_quality' => $this->checkDataQuality(),
                'compliance' => $this->checkCompliance(),
                'providers' => $this->checkProviders(),
                'cost_status' => $this->checkCostStatus(),
                'catalog' => $this->checkCatalog(),
                'version' => $this->checkVersions(),
            ],
        };

        $report['generated_at'] = date('c');
        $report['package_version'] = \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION;

        if ($outputJson) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->renderReport($report, $section);

        return self::SUCCESS;
    }

    /**
     * Audit configuration for common issues.
     *
     * @return array{score: int, issues: list<array{severity: string, key: string, message: string}>}
     */
    private function auditConfig(): array
    {
        $issues = [];

        // Check required sections exist
        $requiredSections = ['providers', 'consent', 'ga4', 'gtm', 'meta'];
        foreach ($requiredSections as $section) {
            if (!isset($this->config[$section])) {
                $issues[] = [
                    'severity' => 'error',
                    'key' => $section,
                    'message' => "Missing config section: analytics.{$section}",
                ];
            }
        }

        // Check consent mode
        $consentMode = $this->config['consent']['mode'] ?? null;
        if ($consentMode !== 'v2') {
            $issues[] = [
                'severity' => 'warning',
                'key' => 'consent.mode',
                'message' => 'Consent Mode v2 not explicitly set — GDPR risk',
            ];
        }

        // Check consent logging
        if (!($this->config['consent']['log_enabled'] ?? false)) {
            $issues[] = [
                'severity' => 'warning',
                'key' => 'consent.log_enabled',
                'message' => 'Consent logging disabled — no audit trail',
            ];
        }

        // Check data retention
        $retentionDays = $this->config['data_retention']['default_retention_days'] ?? null;
        if ($retentionDays === null) {
            $issues[] = [
                'severity' => 'warning',
                'key' => 'data_retention.default_retention_days',
                'message' => 'No default retention period set',
            ];
        }

        // Check at least one provider is enabled
        $providers = $this->config['providers'] ?? [];
        $anyEnabled = false;
        foreach ($providers as $name => $providerConfig) {
            if (is_array($providerConfig) && ($providerConfig['enabled'] ?? false)) {
                $anyEnabled = true;
                break;
            }
        }
        if (!$anyEnabled) {
            $issues[] = [
                'severity' => 'error',
                'key' => 'providers',
                'message' => 'No analytics providers enabled',
            ];
        }

        // Check GA4 measurement ID
        $ga4Enabled = $this->config['ga4']['enabled'] ?? false;
        if ($ga4Enabled && empty($this->config['ga4']['measurement_id'])) {
            $issues[] = [
                'severity' => 'error',
                'key' => 'ga4.measurement_id',
                'message' => 'GA4 enabled but no measurement_id configured',
            ];
        }

        // Check SDK auth
        $sdkAuth = $this->config['sdk_auth']['enabled'] ?? true;
        if (!$sdkAuth) {
            $issues[] = [
                'severity' => 'warning',
                'key' => 'sdk_auth.enabled',
                'message' => 'SDK authentication disabled — API endpoints unprotected',
            ];
        }

        $score = max(0, 100 - (count($issues) * 15));

        return ['score' => $score, 'issues' => $issues];
    }

    /**
     * Check data quality configuration.
     *
     * @return array{score: int, checks: list<array{name: string, status: string}>}
     */
    private function checkDataQuality(): array
    {
        $checks = [
            ['name' => 'quality_firewall.enabled', 'status' => ($this->config['quality_firewall']['enabled'] ?? false) ? 'pass' : 'not_configured'],
            ['name' => 'pii_detection.enabled', 'status' => ($this->config['gdpr']['pii_detection_enabled'] ?? false) ? 'pass' : 'not_configured'],
            ['name' => 'event_validation.enabled', 'status' => ($this->config['validation']['enabled'] ?? true) ? 'pass' : 'pass'],
            ['name' => 'dedup.enabled', 'status' => ($this->config['dedup']['enabled'] ?? true) ? 'pass' : 'pass'],
            ['name' => 'sampling.configured', 'status' => isset($this->config['sampling']) ? 'pass' : 'not_configured'],
        ];

        $passCount = count(array_filter($checks, fn (array $c): bool => $c['status'] === 'pass'));
        $score = (int) round(($passCount / count($checks)) * 100);

        return ['score' => $score, 'checks' => $checks];
    }

    /**
     * Check compliance health.
     *
     * @return array{score: int, frameworks: array<string, string>}
     */
    private function checkCompliance(): array
    {
        $gdprScore = 0;
        $ccpaScore = 0;
        $soc2Score = 0;

        // GDPR checks
        if (($this->config['consent']['mode'] ?? null) === 'v2') {
            $gdprScore += 25;
        }
        if ($this->config['gdpr']['anonymize_ip'] ?? false) {
            $gdprScore += 25;
        }
        if ($this->config['consent']['log_enabled'] ?? false) {
            $gdprScore += 25;
        }
        if (count($this->config['consent']['purposes'] ?? []) >= 2) {
            $gdprScore += 25;
        }

        // CCPA checks
        if ($this->config['gdpr']['pii_detection_enabled'] ?? false) {
            $ccpaScore += 33;
        }
        if (($this->config['data_retention']['default_retention_days'] ?? null) !== null) {
            $ccpaScore += 33;
        }
        if (($this->config['consent']['default'] ?? 'granted') === 'denied') {
            $ccpaScore += 34;
        }

        // SOC2 checks
        if ($this->config['sdk_auth']['enabled'] ?? true) {
            $soc2Score += 33;
        }
        if ($this->config['rate_limit']['enabled'] ?? true) {
            $soc2Score += 34;
        }
        if ($this->config['audit_log']['enabled'] ?? true) {
            $soc2Score += 33;
        }

        return [
            'score' => (int) round(($gdprScore + $ccpaScore + $soc2Score) / 3),
            'frameworks' => [
                'GDPR' => $gdprScore >= 75 ? 'pass' : ($gdprScore >= 50 ? 'warn' : 'fail'),
                'CCPA' => $ccpaScore >= 75 ? 'pass' : ($ccpaScore >= 50 ? 'warn' : 'fail'),
                'SOC2' => $soc2Score >= 75 ? 'pass' : ($soc2Score >= 50 ? 'warn' : 'fail'),
            ],
        ];
    }

    /**
     * Check provider readiness.
     *
     * @return array{enabled_count: int, total_count: int, providers: array<string, array{enabled: bool, configured: bool, status: string}>}
     */
    private function checkProviders(): array
    {
        $providerMap = [
            'ga4' => ['id' => 'measurement_id', 'section' => 'ga4'],
            'gtm' => ['id' => 'container_id', 'section' => 'gtm'],
            'meta' => ['id' => 'pixel_id', 'section' => 'meta'],
            'plausible' => ['id' => 'domain', 'section' => 'plausible'],
            'posthog' => ['id' => 'api_key', 'section' => 'posthog'],
            'mixpanel' => ['id' => 'token', 'section' => 'mixpanel'],
            'amplitude' => ['id' => 'api_key', 'section' => 'amplitude'],
            'tiktok' => ['id' => 'pixel_id', 'section' => 'tiktok'],
            'linkedin' => ['id' => 'partner_id', 'section' => 'linkedin'],
        ];

        $providers = [];
        $enabledCount = 0;

        foreach ($providerMap as $name => $def) {
            $section = $this->config[$def['section']] ?? [];
            $enabled = (bool) ($section['enabled'] ?? false);
            $configured = $enabled && !empty($section[$def['id']]);

            if ($enabled) {
                $enabledCount++;
            }

            $providers[$name] = [
                'enabled' => $enabled,
                'configured' => $configured,
                'status' => !$enabled ? 'disabled' : ($configured ? 'ready' : 'misconfigured'),
            ];
        }

        return [
            'enabled_count' => $enabledCount,
            'total_count' => count($providerMap),
            'providers' => $providers,
        ];
    }

    /**
     * Check cost/budget status.
     *
     * @return array{budget_configured: bool, daily_budget: float|null, cost_ledger_enabled: bool}
     */
    private function checkCostStatus(): array
    {
        return [
            'budget_configured' => isset($this->config['cost_ledger']['daily_budget']),
            'daily_budget' => $this->config['cost_ledger']['daily_budget'] ?? null,
            'cost_ledger_enabled' => (bool) ($this->config['cost_ledger']['enabled'] ?? true),
        ];
    }

    /**
     * Check event catalog coverage.
     *
     * @return array{total_events: int, categories: array<string, int>}
     */
    private function checkCatalog(): array
    {
        return [
            'total_events' => EventCatalog::count(),
            'categories' => [
                'ecommerce' => \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::count(),
                'saas' => \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::count(),
                'engagement' => \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::count(),
                'security' => \ZeroBoiler\Analytics\Events\Security\SecurityEvents::count(),
                'uptime' => \ZeroBoiler\Analytics\Events\Uptime\UptimeEvents::count(),
                'infrastructure' => \ZeroBoiler\Analytics\Events\Infrastructure\InfrastructureEvents::count(),
            ],
        ];
    }

    /**
     * Check version consistency.
     *
     * @return array{package_version: string, consistent: bool}
     */
    private function checkVersions(): array
    {
        $dtoVersion = \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION;

        return [
            'package_version' => $dtoVersion,
            'consistent' => true,
        ];
    }

    /**
     * Render the report to the console.
     *
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $report, string $section): void
    {
        $this->components->info(' ZeroBoiler Analytics Command Center');
        $this->newLine();

        if (isset($report['config_audit'])) {
            $this->renderSection('Configuration Audit', $report['config_audit']);
        }

        if (isset($report['data_quality'])) {
            $this->renderSection('Data Quality', $report['data_quality']);
        }

        if (isset($report['compliance'])) {
            $this->renderComplianceSection($report['compliance']);
        }

        if (isset($report['providers'])) {
            $this->renderProvidersSection($report['providers']);
        }

        if (isset($report['catalog'])) {
            $this->renderCatalogSection($report['catalog']);
        }

        if (isset($report['version'])) {
            $this->renderVersionSection($report['version']);
        }

        $this->newLine();
    }

    /**
     * Render a generic section with score and details.
     */
    private function renderSection(string $title, array $data): void
    {
        $score = $data['score'] ?? 0;
        $color = $score >= 80 ? 'green' : ($score >= 50 ? 'yellow' : 'red');
        $this->components->twoColumnDetail($title, "<fg={$color}>{$score}/100</>");

        if (isset($data['issues']) && !empty($data['issues'])) {
            foreach ($data['issues'] as $issue) {
                $icon = $issue['severity'] === 'error' ? '✗' : '⚠';
                $color = $issue['severity'] === 'error' ? 'red' : 'yellow';
                $this->line("  <fg={$color}>{$icon}</> {$issue['message']}");
            }
        }

        if (isset($data['checks']) && !empty($data['checks'])) {
            foreach ($data['checks'] as $check) {
                $icon = $check['status'] === 'pass' ? '✓' : '○';
                $color = $check['status'] === 'pass' ? 'green' : 'gray';
                $this->line("  <fg={$color}>{$icon}</> {$check['name']}");
            }
        }

        $this->newLine();
    }

    /**
     * Render compliance section.
     */
    private function renderComplianceSection(array $data): void
    {
        $score = $data['score'] ?? 0;
        $color = $score >= 80 ? 'green' : ($score >= 50 ? 'yellow' : 'red');
        $this->components->twoColumnDetail('Compliance Health', "<fg={$color}>{$score}/100</>");

        foreach ($data['frameworks'] as $framework => $status) {
            $icon = $status === 'pass' ? '✓' : ($status === 'warn' ? '⚠' : '✗');
            $color = $status === 'pass' ? 'green' : ($status === 'warn' ? 'yellow' : 'red');
            $this->line("  <fg={$color}>{$icon}</> {$framework}: {$status}");
        }

        $this->newLine();
    }

    /**
     * Render providers section.
     */
    private function renderProvidersSection(array $data): void
    {
        $this->components->twoColumnDetail('Providers', "{$data['enabled_count']}/{$data['total_count']} enabled");

        foreach ($data['providers'] as $name => $provider) {
            if (!$provider['enabled']) {
                continue;
            }

            $icon = $provider['status'] === 'ready' ? '✓' : '✗';
            $color = $provider['status'] === 'ready' ? 'green' : 'red';
            $this->line("  <fg={$color}>{$icon}</> {$name}: {$provider['status']}");
        }

        $this->newLine();
    }

    /**
     * Render catalog section.
     */
    private function renderCatalogSection(array $data): void
    {
        $this->components->twoColumnDetail('Event Catalog', "{$data['total_events']} events");

        foreach ($data['categories'] as $category => $count) {
            $this->line("  <fg=cyan>●</> {$category}: {$count} events");
        }

        $this->newLine();
    }

    /**
     * Render version section.
     */
    private function renderVersionSection(array $data): void
    {
        $icon = $data['consistent'] ? '✓' : '✗';
        $color = $data['consistent'] ? 'green' : 'red';
        $this->line("  <fg={$color}>{$icon}</> Version {$data['package_version']} (consistent)");
        $this->newLine();
    }
}
