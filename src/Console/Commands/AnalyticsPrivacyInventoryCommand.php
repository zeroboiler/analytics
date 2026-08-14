<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Privacy Inventory Command — comprehensive data processing inventory for GDPR Article 30.
 *
 * Generates a structured inventory of all personal data processed by the
 * ZeroBoiler Analytics pipeline, covering:
 *
 * - Event catalog privacy classification (PII, behavioral, financial, technical)
 * - Provider data sharing assessment (GA4, GTM, Meta, Plausible, PostHog, Mixpanel, Amplitude, TikTok, LinkedIn, Webhook)
 * - Consent state verification (Consent Mode v2, granular purposes)
 * - Data retention configuration per category
 * - Right-to-erasure capability verification
 * - Cross-border data transfer assessment
 * - Legal basis mapping per event category
 *
 * Output modes:
 * - Default: Formatted table in console
 * - `--json`: JSON output for CI/CD pipelines
 * - `--detailed`: Include per-event processing records
 *
 * @since 107.0.0
 */
final class AnalyticsPrivacyInventoryCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:privacy-inventory
        {--json : Output as JSON}
        {--detailed : Include per-event processing records}';

    /** @var string */
    protected $description = 'Generate GDPR Article 30 data processing inventory for all analytics events';

    /**
     * Execute the privacy inventory command.
     */
    public function handle(ConfigRepository $config): int
    {
        $analyticsConfig = $config->get('zeroboiler.analytics', []);
        $consentConfig = $analyticsConfig['consent'] ?? [];
        $privacyManifestConfig = $analyticsConfig['privacy_manifest'] ?? [];
        $retentionConfig = $analyticsConfig['data_retention'] ?? [];
        $gdprConfig = $analyticsConfig['gdpr'] ?? [];

        $inventory = [
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'package_version' => AnalyticsEvent::VERSION,
            'total_events' => EventCatalog::count(),
            'categories' => $this->classifyCategories(),
            'provider_sharing' => $this->assessProviderSharing($analyticsConfig),
            'consent_state' => $this->assessConsentState($consentConfig, $gdprConfig),
            'retention_policies' => $this->assessRetentionPolicies($retentionConfig),
            'erasure_capability' => $this->assessErasureCapability($analyticsConfig),
            'legal_bases' => $this->mapLegalBases($privacyManifestConfig),
            'cross_border' => $this->assessCrossBorder($analyticsConfig),
            'high_risk_events' => $this->identifyHighRiskEvents(),
            'recommendations' => $this->generateRecommendations($analyticsConfig),
        ];

        if ($this->option('detailed')) {
            $inventory['processing_records'] = $this->generateProcessingRecords();
        }

        if ($this->option('json')) {
            $this->line(json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->displaySummary($inventory);
        $this->displayConsentAssessment($inventory['consent_state']);
        $this->displayProviderSharing($inventory['provider_sharing']);
        $this->displayRetentionPolicies($inventory['retention_policies']);
        $this->displayHighRiskEvents($inventory['high_risk_events']);
        $this->displayRecommendations($inventory['recommendations']);

        return self::SUCCESS;
    }

    /**
     * Classify event categories by data sensitivity.
     *
     * @return list<array{category: string, count: int, sensitivity: string, data_types: list<string>}>
     */
    private function classifyCategories(): array
    {
        $sensitivityMap = [
            'ecommerce' => ['high', ['financial', 'transactional', 'behavioral']],
            'saas' => ['medium', ['identifier', 'behavioral', 'contractual', 'financial']],
            'engagement' => ['low', ['behavioral', 'technical']],
            'security' => ['high', ['identifier', 'technical', 'legal']],
            'uptime' => ['low', ['technical']],
            'infrastructure' => ['low', ['technical']],
        ];

        $categories = EventCatalog::byCategory();
        $result = [];

        foreach ($categories as $name => $events) {
            $sensitivity = $sensitivityMap[$name] ?? ['unknown', ['unknown']];
            $result[] = [
                'category' => $name,
                'count' => count($events),
                'sensitivity' => $sensitivity[0],
                'data_types' => $sensitivity[1],
            ];
        }

        return $result;
    }

    /**
     * Assess data sharing with third-party providers.
     *
     * @param  array<string, mixed>  $analyticsConfig
     * @return list<array{provider: string, enabled: bool, data_shared: list<string>, jurisdiction: string, risk: string}>
     */
    private function assessProviderSharing(array $analyticsConfig): array
    {
        $providers = [
            'ga4' => ['Google Analytics 4', 'US (Adequacy Decision)', 'medium', ['events', 'user_id', 'client_id', 'page_url', 'device_info']],
            'gtm' => ['Google Tag Manager', 'US (Adequacy Decision)', 'medium', ['events', 'user_id', 'page_url', 'device_info']],
            'meta_pixel' => ['Meta (Facebook) Pixel', 'US (SCCs / Adequacy)', 'high', ['events', 'user_data', 'email_hash', 'phone_hash', 'page_url']],
            'plausible' => ['Plausible Analytics', 'EU (Germany)', 'low', ['events', 'page_url', 'referrer', 'device_info']],
            'posthog' => ['PostHog', 'US (SCCs)', 'medium', ['events', 'user_id', 'session_data', 'page_url']],
            'mixpanel' => ['Mixpanel', 'US (SCCs)', 'medium', ['events', 'user_id', 'device_info']],
            'amplitude' => ['Amplitude', 'US (SCCs)', 'medium', ['events', 'user_id', 'device_info', 'session_data']],
            'tiktok' => ['TikTok Events API', 'China (via Ireland)', 'high', ['events', 'user_data', 'email_hash', 'phone_hash', 'page_url']],
            'linkedin' => ['LinkedIn Insight', 'US (SCCs)', 'medium', ['events', 'user_data', 'company_info']],
            'webhook' => ['Custom Webhook', 'Configurable', 'varies', ['events', 'user_id', 'custom_payload']],
        ];

        $result = [];

        foreach ($providers as $key => [$name, $jurisdiction, $risk, $dataShared]) {
            $configSection = $analyticsConfig[$key] ?? [];
            $enabled = (bool) ($configSection['enabled'] ?? false);

            $result[] = [
                'provider' => $name,
                'enabled' => $enabled,
                'data_shared' => $dataShared,
                'jurisdiction' => $jurisdiction,
                'risk' => $enabled ? $risk : 'none (disabled)',
            ];
        }

        return $result;
    }

    /**
     * Assess the consent state configuration.
     *
     * @param  array<string, mixed>  $consentConfig
     * @param  array<string, mixed>  $gdprConfig
     * @return array{consent_mode_v2: bool, default_state: string, granular_purposes: int, consent_logging: bool, ip_anonymization: bool, score: float}
     */
    private function assessConsentState(array $consentConfig, array $gdprConfig): array
    {
        $consentModeV2 = ($gdprConfig['consent_mode_v2'] ?? true) === true;
        $defaultState = (string) ($consentConfig['default'] ?? 'granted');
        $purposes = (array) ($consentConfig['purposes'] ?? []);
        $consentLogging = (bool) ($consentConfig['log_enabled'] ?? false);
        $ipAnonymization = ($gdprConfig['anonymize_ip'] ?? true) === true;

        $score = 0.0;
        if ($consentModeV2) {
            $score += 25.0;
        }
        if ($defaultState === 'denied') {
            $score += 25.0;
        } elseif ($defaultState === 'granted') {
            $score += 10.0;
        }
        $score += min(count($purposes) * 5.0, 20.0);
        if ($consentLogging) {
            $score += 15.0;
        }
        if ($ipAnonymization) {
            $score += 15.0;
        }

        return [
            'consent_mode_v2' => $consentModeV2,
            'default_state' => $defaultState,
            'granular_purposes' => count($purposes),
            'consent_logging' => $consentLogging,
            'ip_anonymization' => $ipAnonymization,
            'score' => min($score, 100.0),
        ];
    }

    /**
     * Assess data retention policies.
     *
     * @param  array<string, mixed>  $retentionConfig
     * @return array{configured: bool, category_policies: array<string, int|null>, recommended_defaults: array<string, int>}
     */
    private function assessRetentionPolicies(array $retentionConfig): array
    {
        $categoryPolicies = [];
        $categories = ['ecommerce', 'saas', 'engagement', 'security', 'uptime', 'infrastructure'];

        foreach ($categories as $category) {
            $categoryPolicies[$category] = ($retentionConfig[$category]['retention_days'] ?? null);
        }

        return [
            'configured' => count(array_filter($categoryPolicies)) > 0,
            'category_policies' => $categoryPolicies,
            'recommended_defaults' => [
                'ecommerce' => 90,
                'saas' => 180,
                'engagement' => 30,
                'security' => 365,
                'uptime' => 30,
                'infrastructure' => 30,
            ],
        ];
    }

    /**
     * Assess right-to-erasure capability.
     *
     * @param  array<string, mixed>  $analyticsConfig
     * @return array{gdpr_export_endpoint: bool, erasure_service: bool, data_retention_purge: bool, user_identity_tracker: bool, score: float}
     */
    private function assessErasureCapability(array $analyticsConfig): array
    {
        $gdprExportEndpoint = ($analyticsConfig['api']['enabled'] ?? false) === true;
        $erasureService = class_exists(\ZeroBoiler\Analytics\Services\AnalyticsDataRetentionService::class);
        $dataRetentionPurge = isset($analyticsConfig['data_retention']['enabled']);
        $userIdentityTracker = class_exists(\ZeroBoiler\Analytics\Tracking\UserIdentityTracker::class);

        $score = 0.0;
        if ($gdprExportEndpoint) {
            $score += 25.0;
        }
        if ($erasureService) {
            $score += 25.0;
        }
        if ($dataRetentionPurge) {
            $score += 25.0;
        }
        if ($userIdentityTracker) {
            $score += 25.0;
        }

        return [
            'gdpr_export_endpoint' => $gdprExportEndpoint,
            'erasure_service' => $erasureService,
            'data_retention_purge' => $dataRetentionPurge,
            'user_identity_tracker' => $userIdentityTracker,
            'score' => $score,
        ];
    }

    /**
     * Map legal bases per event category.
     *
     * @param  array<string, mixed>  $privacyManifestConfig
     * @return array<string, string>
     */
    private function mapLegalBases(array $privacyManifestConfig): array
    {
        $defaults = (array) ($privacyManifestConfig['legal_basis_defaults'] ?? []);

        return [
            'ecommerce' => $defaults['ecommerce'] ?? 'contract',
            'saas' => $defaults['saas'] ?? 'contract',
            'engagement' => $defaults['engagement'] ?? 'consent',
            'security' => $defaults['security'] ?? 'legitimate_interest',
            'uptime' => $defaults['uptime'] ?? 'legitimate_interest',
            'infrastructure' => $defaults['infrastructure'] ?? 'legitimate_interest',
        ];
    }

    /**
     * Assess cross-border data transfer risks.
     *
     * @param  array<string, mixed>  $analyticsConfig
     * @return array{eu_providers: list<string>, non_eu_providers: list<string>, safeguards: list<string>, risk_level: string}
     */
    private function assessCrossBorder(array $analyticsConfig): array
    {
        $euProviders = [];
        $nonEuProviders = [];

        $euProviderKeys = ['plausible'];
        $allProviderKeys = ['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];

        foreach ($allProviderKeys as $key) {
            $enabled = (bool) (($analyticsConfig[$key] ?? [])['enabled'] ?? false);
            if (! $enabled) {
                continue;
            }
            if (in_array($key, $euProviderKeys, true)) {
                $euProviders[] = $key;
            } else {
                $nonEuProviders[] = $key;
            }
        }

        $riskLevel = 'low';
        if (count($nonEuProviders) > 0) {
            $riskLevel = count($nonEuProviders) <= 2 ? 'medium' : 'high';
        }

        return [
            'eu_providers' => $euProviders,
            'non_eu_providers' => $nonEuProviders,
            'safeguards' => $nonEuProviders !== []
                ? ['Standard Contractual Clauses (SCCs)', 'Data Processing Agreement (DPA)', 'Consent where required']
                : [],
            'risk_level' => $riskLevel,
        ];
    }

    /**
     * Identify high-risk events that contain PII or sensitive data.
     *
     * @return list<array{name: string, category: string, risk_factors: list<string>}>
     */
    private function identifyHighRiskEvents(): array
    {
        $allEvents = EventCatalog::all();
        $highRiskPatterns = [
            'sign_up', 'login', 'register', 'password', 'email',
            'payment', 'billing', 'invoice', 'subscription', 'plan',
            'team', 'invite', 'workspace', 'account',
            'gdpr', 'erasure', 'consent', 'data_subject',
        ];

        $result = [];

        foreach ($allEvents as $name => $entry) {
            $riskFactors = [];

            foreach ($highRiskPatterns as $pattern) {
                if (str_contains($name, $pattern)) {
                    $riskFactors[] = $pattern;
                }
            }

            if ($riskFactors !== []) {
                $result[] = [
                    'name' => $name,
                    'category' => $entry['category'],
                    'risk_factors' => $riskFactors,
                ];
            }
        }

        return $result;
    }

    /**
     * Generate actionable privacy recommendations.
     *
     * @param  array<string, mixed>  $analyticsConfig
     * @return list<array{severity: string, finding: string, recommendation: string}>
     */
    private function generateRecommendations(array $analyticsConfig): array
    {
        $recommendations = [];

        // Check consent default
        $consentDefault = ($analyticsConfig['consent']['default'] ?? 'granted');
        if ($consentDefault === 'granted') {
            $recommendations[] = [
                'severity' => 'high',
                'finding' => 'Consent default is "granted" — non-compliant for GDPR opt-in model',
                'recommendation' => 'Set ANALYTICS_CONSENT_DEFAULT=denied for GDPR-safe default (users must explicitly opt-in)',
            ];
        }

        // Check consent logging
        if (! ($analyticsConfig['consent']['log_enabled'] ?? false)) {
            $recommendations[] = [
                'severity' => 'medium',
                'finding' => 'Consent logging is disabled — no audit trail for consent changes',
                'recommendation' => 'Enable ANALYTICS_CONSENT_LOG_ENABLED=true to maintain consent audit records',
            ];
        }

        // Check IP anonymization
        if (! (($analyticsConfig['gdpr']['anonymize_ip'] ?? true))) {
            $recommendations[] = [
                'severity' => 'high',
                'finding' => 'IP anonymization is disabled — storing full IP addresses',
                'recommendation' => 'Enable GDPR IP anonymization to truncate last octet of IP addresses',
            ];
        }

        // Check retention policies
        if (! isset($analyticsConfig['data_retention']['enabled'])) {
            $recommendations[] = [
                'severity' => 'medium',
                'finding' => 'Data retention policies not configured — events stored indefinitely',
                'recommendation' => 'Configure per-category retention periods in data_retention config section',
            ];
        }

        // Check enabled high-risk providers
        $highRiskProviders = ['meta_pixel', 'tiktok', 'linkedin'];
        foreach ($highRiskProviders as $provider) {
            if (($analyticsConfig[$provider]['enabled'] ?? false)) {
                $providerNames = ['meta_pixel' => 'Meta Pixel', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn Insight'];
                $recommendations[] = [
                    'severity' => 'medium',
                    'finding' => "{$providerNames[$provider]} is enabled — shares user data outside EU",
                    'recommendation' => "Ensure DPA and SCCs are in place with {$providerNames[$provider]}. Consider disabling if not required.",
                ];
            }
        }

        return $recommendations;
    }

    /**
     * Generate per-event processing records (Article 30 RoPA).
     *
     * @return list<array{event: string, category: string, purpose: string, legal_basis: string, data_types: list<string>, retention: string, providers: list<string>}>
     */
    private function generateProcessingRecords(): array
    {
        $allEvents = EventCatalog::all();
        $records = [];

        $purposeMap = [
            'ecommerce' => 'Transaction processing and analytics',
            'saas' => 'Service delivery and user management',
            'engagement' => 'User behavior analysis and optimization',
            'security' => 'Security monitoring and audit trail',
            'uptime' => 'System reliability monitoring',
            'infrastructure' => 'Infrastructure performance monitoring',
        ];

        $basisMap = [
            'ecommerce' => 'Contractual necessity (Article 6(1)(b))',
            'saas' => 'Contractual necessity (Article 6(1)(b))',
            'engagement' => 'Consent (Article 6(1)(a))',
            'security' => 'Legitimate interest (Article 6(1)(f))',
            'uptime' => 'Legitimate interest (Article 6(1)(f))',
            'infrastructure' => 'Legitimate interest (Article 6(1)(f))',
        ];

        $retentionMap = [
            'ecommerce' => '90 days',
            'saas' => '180 days',
            'engagement' => '30 days',
            'security' => '365 days',
            'uptime' => '30 days',
            'infrastructure' => '30 days',
        ];

        foreach ($allEvents as $name => $entry) {
            $category = $entry['category'];
            $providers = [];

            foreach (['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'] as $provider) {
                if (isset($entry[$provider]) && $entry[$provider] !== null) {
                    $providers[] = $provider;
                }
            }

            $records[] = [
                'event' => $name,
                'category' => $category,
                'purpose' => $purposeMap[$category] ?? 'Analytics',
                'legal_basis' => $basisMap[$category] ?? 'Consent (Article 6(1)(a))',
                'data_types' => $this->dataTypesForEvent($name, $category),
                'retention' => $retentionMap[$category] ?? '30 days',
                'providers' => $providers,
            ];
        }

        return $records;
    }

    /**
     * Determine data types for an event.
     *
     * @return list<string>
     */
    private function dataTypesForEvent(string $name, string $category): array
    {
        $types = ['event_name', 'timestamp', 'client_id'];

        if (in_array($category, ['ecommerce', 'saas'], true)) {
            $types[] = 'user_id';
        }

        if ($category === 'ecommerce') {
            $types[] = 'transaction_value';
            $types[] = 'currency';
        }

        if (str_contains($name, 'login') || str_contains($name, 'sign_up') || str_contains($name, 'register')) {
            $types[] = 'auth_method';
        }

        if (str_contains($name, 'payment') || str_contains($name, 'billing') || str_contains($name, 'invoice')) {
            $types[] = 'financial_data';
        }

        if (str_contains($name, 'team') || str_contains($name, 'invite') || str_contains($name, 'workspace')) {
            $types[] = 'team_data';
        }

        if (str_contains($name, 'gdpr') || str_contains($name, 'consent') || str_contains($name, 'erasure')) {
            $types[] = 'legal_data';
        }

        $types[] = 'page_url';
        $types[] = 'device_context';

        return array_unique($types);
    }

    /**
     * Display summary table.
     *
     * @param  array<string, mixed>  $inventory
     */
    private function displaySummary(array $inventory): void
    {
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║  ZeroBoiler Analytics — GDPR Article 30 Privacy Inventory    ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();
        $this->line("  Generated: {$inventory['generated_at']}");
        $this->line("  Package Version: {$inventory['package_version']}");
        $this->line("  Total Events Tracked: {$inventory['total_events']}");
        $this->newLine();

        $this->info('  Event Categories:');
        $this->table(
            ['Category', 'Events', 'Sensitivity', 'Data Types'],
            array_map(
                fn (array $cat): array => [
                    $cat['category'],
                    $cat['count'],
                    strtoupper($cat['sensitivity']),
                    implode(', ', $cat['data_types']),
                ],
                $inventory['categories'],
            ),
        );
    }

    /**
     * Display consent assessment.
     *
     * @param  array<string, mixed>  $consentState
     */
    private function displayConsentAssessment(array $consentState): void
    {
        $this->newLine();
        $this->info('  Consent State Assessment:');
        $rows = [
            ['Consent Mode v2', $consentState['consent_mode_v2'] ? '✅ Enabled' : '❌ Disabled'],
            ['Default State', $consentState['default_state'] === 'denied' ? '✅ Denied (GDPR-safe)' : '⚠️ Granted (non-compliant)'],
            ['Granular Purposes', (string) $consentState['granular_purposes']],
            ['Consent Logging', $consentState['consent_logging'] ? '✅ Enabled' : '❌ Disabled'],
            ['IP Anonymization', $consentState['ip_anonymization'] ? '✅ Enabled' : '❌ Disabled'],
            ['Consent Score', $consentState['score'] . '/100'],
        ];
        $this->table(['Check', 'Status'], $rows);
    }

    /**
     * Display provider sharing assessment.
     *
     * @param  list<array<string, mixed>>  $providerSharing
     */
    private function displayProviderSharing(array $providerSharing): void
    {
        $this->newLine();
        $this->info('  Provider Data Sharing:');
        $rows = array_map(
            fn (array $p): array => [
                $p['provider'],
                $p['enabled'] ? '✅ Active' : '⬜ Disabled',
                $p['jurisdiction'],
                strtoupper($p['risk']),
            ],
            $providerSharing,
        );
        $this->table(['Provider', 'Status', 'Jurisdiction', 'Risk'], $rows);
    }

    /**
     * Display retention policies.
     *
     * @param  array<string, mixed>  $retentionPolicies
     */
    private function displayRetentionPolicies(array $retentionPolicies): void
    {
        $this->newLine();
        $this->info('  Data Retention Policies:');
        $rows = array_map(
            fn (string $category, ?int $days): array => [
                $category,
                $days !== null ? "{$days} days" : '❌ Not configured',
            ],
            array_keys($retentionPolicies['category_policies']),
            $retentionPolicies['category_policies'],
        );
        $this->table(['Category', 'Retention'], $rows);
    }

    /**
     * Display high-risk events.
     *
     * @param  list<array<string, mixed>>  $highRiskEvents
     */
    private function displayHighRiskEvents(array $highRiskEvents): void
    {
        $this->newLine();
        $this->info('  High-Risk Events (PII / Sensitive Data):');
        $count = count($highRiskEvents);
        $this->line("  Total: {$count} events with potential PII exposure");

        if ($count > 20) {
            $displayEvents = array_slice($highRiskEvents, 0, 20);
            $this->comment("  Showing first 20 of {$count}. Use --detailed for full list.");
        } else {
            $displayEvents = $highRiskEvents;
        }

        $rows = array_map(
            fn (array $e): array => [
                $e['name'],
                $e['category'],
                implode(', ', $e['risk_factors']),
            ],
            $displayEvents,
        );

        if ($rows !== []) {
            $this->table(['Event', 'Category', 'Risk Factors'], $rows);
        }
    }

    /**
     * Display recommendations.
     *
     * @param  list<array<string, mixed>>  $recommendations
     */
    private function displayRecommendations(array $recommendations): void
    {
        $this->newLine();
        $this->info('  Recommendations:');

        if ($recommendations === []) {
            $this->line('  ✅ No critical privacy gaps found — configuration looks good.');
            $this->newLine();

            return;
        }

        $rows = array_map(
            fn (array $r): array => [
                strtoupper($r['severity']),
                $r['finding'],
                $r['recommendation'],
            ],
            $recommendations,
        );
        $this->table(['Severity', 'Finding', 'Recommendation'], $rows);
    }
}
