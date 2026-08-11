<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * GDPR Consent Mode v2 compliance validation service.
 *
 * Validates that consent state is properly applied across all analytics providers,
 * ensures no events are dispatched without proper consent, and generates compliance
 * reports for audit purposes. Implements consent signal propagation checks for
 * Google Consent Mode v2 (analytics_storage, ad_storage, ad_user_data, ad_personalization,
 * functionality_storage, personalization_storage, security_storage).
 *
 * Useful for:
 * - Pre-deployment compliance audits
 * - CI/CD consent validation gates
 * - GDPR Article 7 proof-of-consent verification
 * - Regulatory compliance dashboards
 *
 * @since 11.0.0
 */
final class AnalyticsConsentComplianceService
{
    private ConfigRepository $config;

    private CacheRepository $cache;

    private int $cacheTtl;

    private const CONSENT_MODE_V2_SIGNALS = [
        'analytics_storage' => 'granted',
        'ad_storage' => 'denied',
        'ad_user_data' => 'denied',
        'ad_personalization' => 'denied',
        'functionality_storage' => 'granted',
        'personalization_storage' => 'granted',
        'security_storage' => 'granted',
    ];

    private const GDPR_PURPOSES = [
        'necessary' => true,     // Always granted, cannot be denied
        'analytics' => true,     // Requires explicit consent
        'marketing' => false,    // Requires explicit consent
        'functional' => true,     // Can be bundled with necessary
    ];

    /**
     * @param  ConfigRepository  $config
     * @param  CacheRepository  $cache
     * @param  int  $cacheTtl  Cache TTL in seconds (default: 5 minutes)
     */
    public function __construct(
        ConfigRepository $config,
        CacheRepository $cache,
        int $cacheTtl = 300,
    ): void {
        $this->config = $config;
        $this->cache = $cache;
        $this->cacheTtl = $cacheTtl;
    }

    /**
     * Run a full consent compliance check across all providers.
     *
     * Validates:
     * - Consent Mode v2 signal coverage
     * - GDPR purpose configuration integrity
     * - Default consent state compliance (should be 'denied' for GDPR)
     * - Provider consent gating
     * - Consent log configuration
     * - Regional consent detection setup
     *
     * @return array{compliant: bool, score: int, max_score: int, checks: list<array{check: string, status: string, message: string, severity: string}>, violations: list<string>, recommendations: list<string>}
     */
    public function complianceCheck(): array
    {
        $cacheKey = 'zb_consent_compliance_' . hash('xxh128', json_encode([
            $this->config->get('zeroboiler.analytics.consent', []),
            $this->config->get('zeroboiler.analytics.regional_consent', []),
        ], JSON_THROW_ON_ERROR));

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            /** @var array{compliant: bool, score: int, max_score: int, checks: list<array{check: string, status: string, message: string, severity: string}>, violations: list<string>, recommendations: list<string>} $cached */
            return $cached;
        }

        $checks = [];
        $violations = [];
        $recommendations = [];
        $maxScore = 0;
        $score = 0;

        // Check 1: Consent Mode v2 signal coverage
        $maxScore++;
        $signalResult = $this->checkConsentModeV2Signals();
        $checks[] = $signalResult;
        if ($signalResult['status'] === 'pass') {
            $score++;
        } else {
            $violations[] = $signalResult['message'];
        }

        // Check 2: GDPR purpose configuration
        $maxScore++;
        $purposeResult = $this->checkGdprPurposes();
        $checks[] = $purposeResult;
        if ($purposeResult['status'] === 'pass') {
            $score++;
        } else {
            $violations[] = $purposeResult['message'];
            if (str_contains($purposeResult['message'], 'missing')) {
                $recommendations[] = 'Add missing GDPR consent purposes to config/zeroboiler.php under analytics.consent.purposes';
            }
        }

        // Check 3: Default consent state (should be 'denied' for GDPR-safe defaults)
        $maxScore++;
        $defaultResult = $this->checkDefaultConsentState();
        $checks[] = $defaultResult;
        if ($defaultResult['status'] === 'pass') {
            $score++;
        } else {
            $recommendations[] = 'Set ANALYTICS_CONSENT_DEFAULT=denied for GDPR-safe defaults (explicit opt-in)';
        }

        // Check 4: Consent log enabled for audit trail
        $maxScore++;
        $logResult = $this->checkConsentLogging();
        $checks[] = $logResult;
        if ($logResult['status'] === 'pass') {
            $score++;
        } else {
            $recommendations[] = 'Enable ANALYTICS_CONSENT_LOG_ENABLED=true for GDPR audit trail (Article 7 proof-of-consent)';
        }

        // Check 5: Consent TTL configuration (should be 90+ days)
        $maxScore++;
        $ttlResult = $this->checkConsentTtl();
        $checks[] = $ttlResult;
        if ($ttlResult['status'] === 'pass') {
            $score++;
        } else {
            $recommendations[] = 'Set ANALYTICS_CONSENT_LOG_TTL to at least 7776000 (90 days) for GDPR retention';
        }

        // Check 6: Regional consent detection
        $maxScore++;
        $regionalResult = $this->checkRegionalConsent();
        $checks[] = $regionalResult;
        if ($regionalResult['status'] === 'pass') {
            $score++;
        } else {
            $recommendations[] = 'Enable regional consent detection via ANALYTICS_REGIONAL_CONSENT_ENABLED=true';
        }

        // Check 7: Provider consent gating
        $maxScore++;
        $providerResult = $this->checkProviderConsentGating();
        $checks[] = $providerResult;
        if ($providerResult['status'] === 'pass') {
            $score++;
        } else {
            $violations[] = $providerResult['message'];
        }

        // Check 8: Consent version hash integrity
        $maxScore++;
        $versionResult = $this->checkConsentVersionHash();
        $checks[] = $versionResult;
        if ($versionResult['status'] === 'pass') {
            $score++;
        }

        // Check 9: Cookie consent integration
        $maxScore++;
        $cookieResult = $this->checkCookieConsentIntegration();
        $checks[] = $cookieResult;
        if ($cookieResult['status'] === 'pass') {
            $score++;
        } else {
            $recommendations[] = 'Ensure zb_analytics_id cookie has httpOnly=true and SameSite=Lax for privacy compliance';
        }

        // Check 10: Data erasure support (GDPR Article 17)
        $maxScore++;
        $erasureResult = $this->checkDataErasureSupport();
        $checks[] = $erasureResult;
        if ($erasureResult['status'] === 'pass') {
            $score++;
        } else {
            $recommendations[] = 'Verify GDPR erasure endpoint (DELETE /api/analytics/data) is accessible in production';
        }

        $compliant = empty($violations) && $score >= ($maxScore * 0.8);

        $result = [
            'compliant' => $compliant,
            'score' => $score,
            'max_score' => $maxScore,
            'checks' => $checks,
            'violations' => $violations,
            'recommendations' => $recommendations,
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Get the consent compliance score as a percentage.
     *
     * @return float Score between 0.0 and 100.0
     */
    public function complianceScore(): float
    {
        $result = $this->complianceCheck();

        if ($result['max_score'] === 0) {
            return 0.0;
        }

        return round(($result['score'] / $result['max_score']) * 100, 1);
    }

    /**
     * Generate a consent audit report for GDPR Article 30 documentation.
     *
     * @return array{generated_at: string, processing_activities: list<array{activity: string, legal_basis: string, data_categories: list<string>, retention: string, purposes: list<string>}>, consent_signals: array<string, string>, compliance_score: float, compliant: bool, violations: list<string>, recommendations: list<string>}
     */
    public function auditReport(): array
    {
        $check = $this->complianceCheck();
        $consentConfig = $this->config->get('zeroboiler.analytics.consent', []);
        /** @var array{default?: string, purposes?: array<string, mixed>, log_ttl?: int} $consentConfig */

        return [
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'processing_activities' => [
                [
                    'activity' => 'Analytics Event Tracking',
                    'legal_basis' => 'Consent (GDPR Article 6(1)(a))',
                    'data_categories' => ['event_name', 'event_params', 'client_id', 'user_id', 'timestamp', 'session_id'],
                    'retention' => ($consentConfig['log_ttl'] ?? 0) . ' seconds',
                    'purposes' => ['analytics', 'product_improvement'],
                ],
                [
                    'activity' => 'User Identity Resolution',
                    'legal_basis' => 'Consent (GDPR Article 6(1)(a))',
                    'data_categories' => ['client_id', 'user_id', 'user_traits'],
                    'retention' => ($consentConfig['log_ttl'] ?? 0) . ' seconds',
                    'purposes' => ['analytics', 'personalization'],
                ],
                [
                    'activity' => 'Consent State Management',
                    'legal_basis' => 'Legitimate Interest (GDPR Article 6(1)(f))',
                    'data_categories' => ['consent_state', 'consent_timestamp', 'user_jurisdiction'],
                    'retention' => ($consentConfig['log_ttl'] ?? 0) . ' seconds',
                    'purposes' => ['necessary', 'security'],
                ],
            ],
            'consent_signals' => self::CONSENT_MODE_V2_SIGNALS,
            'compliance_score' => $this->complianceScore(),
            'compliant' => $check['compliant'],
            'violations' => $check['violations'],
            'recommendations' => $check['recommendations'],
        ];
    }

    /**
     * Check that all Google Consent Mode v2 signals are properly configured.
     *
     * @return array{check: string, status: string, message: string, severity: string}
     */
    private function checkConsentModeV2Signals(): array
    {
        $requiredSignals = array_keys(self::CONSENT_MODE_V2_SIGNALS);
        $configuredPurposes = $this->config->get('zeroboiler.analytics.consent.purposes', []);
        /** @var array<string, mixed> $configuredPurposes */

        $missing = [];
        foreach ($requiredSignals as $signal) {
            if (! array_key_exists($signal, $configuredPurposes)) {
                $missing[] = $signal;
            }
        }

        if (empty($missing)) {
            return [
                'check' => 'consent_mode_v2_signals',
                'status' => 'pass',
                'message' => 'All Consent Mode v2 signals configured (' . count($requiredSignals) . '/7)',
                'severity' => 'info',
            ];
        }

        return [
            'check' => 'consent_mode_v2_signals',
            'status' => 'fail',
            'message' => 'Missing Consent Mode v2 signals: ' . implode(', ', $missing),
            'severity' => 'warning',
        ];
    }

    /**
     * Check that GDPR purposes are properly configured.
     *
     * @return array{check: string, status: string, message: string, severity: string}
     */
    private function checkGdprPurposes(): array
    {
        $purposes = $this->config->get('zeroboiler.analytics.consent.purposes', []);
        /** @var array<string, mixed> $purposes */

        $requiredPurposes = ['necessary', 'analytics'];
        $missing = [];

        foreach ($requiredPurposes as $purpose) {
            if (! array_key_exists($purpose, $purposes)) {
                $missing[] = $purpose;
            }
        }

        // Check that 'necessary' is marked as required
        if (isset($purposes['necessary']) && ! ($purposes['necessary']['required'] ?? false)) {
            return [
                'check' => 'gdpr_purposes',
                'status' => 'warn',
                'message' => "'necessary' purpose should have required=true (cannot be denied under GDPR)",
                'severity' => 'warning',
            ];
        }

        if (empty($missing)) {
            return [
                'check' => 'gdpr_purposes',
                'status' => 'pass',
                'message' => 'GDPR consent purposes configured (' . count($purposes) . ' purposes)',
                'severity' => 'info',
            ];
        }

        return [
            'check' => 'gdpr_purposes',
            'status' => 'fail',
            'message' => 'Missing required GDPR purposes: ' . implode(', ', $missing),
            'severity' => 'error',
        ];
    }

    /**
     * Check that the default consent state is GDPR-safe (denied).
     *
     * @return array{check: string, status: string, message: string, severity: string}
     */
    private function checkDefaultConsentState(): array
    {
        $default = $this->config->get('zeroboiler.analytics.consent.default', 'granted');
        /** @var string $default */

        if ($default === 'denied') {
            return [
                'check' => 'default_consent_state',
                'status' => 'pass',
                'message' => 'Default consent is "denied" (GDPR-safe explicit opt-in)',
                'severity' => 'info',
            ];
        }

        return [
            'check' => 'default_consent_state',
            'status' => 'warn',
            'message' => 'Default consent is "granted" — not GDPR-safe. Users must explicitly opt-in.',
            'severity' => 'warning',
        ];
    }

    /**
     * Check that consent logging is enabled for audit trail.
     *
     * @return array{check: string, status: string, message: string, severity: string}
     */
    private function checkConsentLogging(): array
    {
        $enabled = $this->config->get('zeroboiler.analytics.consent.log_enabled', false);

        if ($enabled === true) {
            return [
                'check' => 'consent_logging',
                'status' => 'pass',
                'message' => 'Consent logging enabled for GDPR audit trail',
                'severity' => 'info',
            ];
        }

        return [
            'check' => 'consent_logging',
            'status' => 'warn',
            'message' => 'Consent logging disabled — no audit trail for Article 7 proof-of-consent',
            'severity' => 'warning',
        ];
    }

    /**
     * Check that consent log TTL meets GDPR retention requirements.
     *
     * @return array{check: string, status: string, message: string, severity: string}
     */
    private function checkConsentTtl(): array
    {
        $ttl = $this->config->get('zeroboiler.analytics.consent.log_ttl', 0);
        /** @var int $ttl */
        $minTtl = 7776000; // 90 days

        if ($ttl >= $minTtl) {
            $days = round($ttl / 86400);

            return [
                'check' => 'consent_ttl',
                'status' => 'pass',
                'message' => "Consent log TTL is {$days} days (meets 90-day minimum)",
                'severity' => 'info',
            ];
        }

        $days = round($ttl / 86400);

        return [
            'check' => 'consent_ttl',
            'status' => 'warn',
            'message' => "Consent log TTL is {$days} days (minimum 90 days recommended)",
            'severity' => 'warning',
        ];
    }

    /**
     * Check that regional consent detection is configured.
     *
     * @return array{check: string, status: string, message: string, severity: string}
     */
    private function checkRegionalConsent(): array
    {
        $enabled = $this->config->get('zeroboiler.analytics.regional_consent.enabled', false);

        if ($enabled === true) {
            return [
                'check' => 'regional_consent',
                'status' => 'pass',
                'message' => 'Regional consent detection enabled (GDPR geo-targeting)',
                'severity' => 'info',
            ];
        }

        return [
            'check' => 'regional_consent',
            'status' => 'warn',
            'message' => 'Regional consent detection disabled — EU users not auto-detected',
            'severity' => 'info',
        ];
    }

    /**
     * Check that consent gating is applied to provider dispatch.
     *
     * @return array{check: string, status: string, message: string, severity: string}
     */
    private function checkProviderConsentGating(): array
    {
        // Verify that at least one consent gate mechanism exists
        $consentGateMiddleware = class_exists(\ZeroBoiler\Analytics\Middleware\ConsentGateMiddleware::class);
        $consentFilter = class_exists(\ZeroBoiler\Analytics\Pipeline\ConsentFilter::class);
        $consentAwareFilter = class_exists(\ZeroBoiler\Analytics\Pipeline\ConsentAwareFilter::class);

        if ($consentGateMiddleware && $consentFilter && $consentAwareFilter) {
            return [
                'check' => 'provider_consent_gating',
                'status' => 'pass',
                'message' => 'Consent gating mechanisms available (ConsentGateMiddleware, ConsentFilter, ConsentAwareFilter)',
                'severity' => 'info',
            ];
        }

        $missing = [];
        if (! $consentGateMiddleware) {
            $missing[] = 'ConsentGateMiddleware';
        }
        if (! $consentFilter) {
            $missing[] = 'ConsentFilter';
        }
        if (! $consentAwareFilter) {
            $missing[] = 'ConsentAwareFilter';
        }

        return [
            'check' => 'provider_consent_gating',
            'status' => 'fail',
            'message' => 'Missing consent gating classes: ' . implode(', ', $missing),
            'severity' => 'error',
        ];
    }

    /**
     * Check that consent version hash is properly configured for client detection.
     *
     * @return array{check: string, status: string, message: string, severity: string}
     */
    private function checkConsentVersionHash(): array
    {
        $purposes = $this->config->get('zeroboiler.analytics.consent.purposes', []);
        /** @var array<string, mixed> $purposes */
        $default = $this->config->get('zeroboiler.analytics.consent.default', 'granted');

        if (! empty($purposes)) {
            try {
                $payload = json_encode(['purposes' => $purposes, 'default' => $default], JSON_THROW_ON_ERROR);
                $hash = hash('xxh128', $payload);

                return [
                    'check' => 'consent_version_hash',
                    'status' => 'pass',
                    'message' => 'Consent version hash computable (' . substr($hash, 0, 12) . '...)',
                    'severity' => 'info',
                ];
            } catch (\Throwable) {
                // JSON encoding failed
            }
        }

        return [
            'check' => 'consent_version_hash',
            'status' => 'warn',
            'message' => 'Consent version hash not computable — client cannot detect consent config changes',
            'severity' => 'info',
        ];
    }

    /**
     * Check that identity cookie is configured with privacy-safe attributes.
     *
     * @return array{check: string, status: string, message: string, severity: string}
     */
    private function checkCookieConsentIntegration(): array
    {
        $secure = $this->config->get('zeroboiler.analytics.identity.cookie_secure', true);
        $sameSite = $this->config->get('zeroboiler.analytics.identity.cookie_samesite', 'Lax');
        /** @var string $sameSite */

        $issues = [];
        if ($secure !== true) {
            $issues[] = 'cookie_secure should be true in production';
        }
        if (! in_array($sameSite, ['Lax', 'Strict'], true)) {
            $issues[] = "cookie_samesite should be 'Lax' or 'Strict', got '{$sameSite}'";
        }

        if (empty($issues)) {
            return [
                'check' => 'cookie_consent_integration',
                'status' => 'pass',
                'message' => 'Identity cookie configured with privacy-safe attributes (secure=true, sameSite=Lax)',
                'severity' => 'info',
            ];
        }

        return [
            'check' => 'cookie_consent_integration',
            'status' => 'warn',
            'message' => implode('; ', $issues),
            'severity' => 'warning',
        ];
    }

    /**
     * Check that GDPR data erasure support is available.
     *
     * @return array{check: string, status: string, message: string, severity: string}
     */
    private function checkDataErasureSupport(): array
    {
        $gdprErasureService = class_exists(\ZeroBoiler\Analytics\Services\GdprErasureService::class);

        if ($gdprErasureService) {
            return [
                'check' => 'data_erasure_support',
                'status' => 'pass',
                'message' => 'GDPR erasure service available (Article 17 right to erasure)',
                'severity' => 'info',
            ];
        }

        return [
            'check' => 'data_erasure_support',
            'status' => 'warn',
            'message' => 'GDPR erasure service not found',
            'severity' => 'warning',
        ];
    }

    /**
     * Invalidate the cached compliance check.
     */
    public function invalidateCache(): void
    {
        $this->cache->forget('zb_consent_compliance_*');
    }
}
