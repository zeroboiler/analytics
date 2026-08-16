<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use ZeroBoiler\Analytics\Services\AnalyticsConsentComplianceService;

/**
 * GDPR Consent Mode v2 compliance service tests.
 *
 * Validates the consent compliance check suite, audit report generation,
 * and consent signal configuration for GDPR Article 7 compliance.
 *
 * @since 11.0.0
 */
describe('Analytics Consent Compliance Service', function (): void {
    beforeEach(function (): void {
        // Reset config to known state
        Config::set('zeroboiler.analytics.consent', [
            'default' => 'denied',
            'purposes' => [
                'necessary' => ['label' => 'Necessary', 'required' => true, 'default' => true],
                'analytics' => ['label' => 'Analytics', 'required' => false, 'default' => true],
                'marketing' => ['label' => 'Marketing', 'required' => false, 'default' => false],
                'functional' => ['label' => 'Functional', 'required' => false, 'default' => true],
            ],
            'log_enabled' => true,
            'log_ttl' => 7776000, // 90 days
        ]);
        Config::set('zeroboiler.analytics.regional_consent', [
            'enabled' => true,
            'gdpr_default' => 'denied',
        ]);
        Config::set('zeroboiler.analytics.identity', [
            'cookie_secure' => true,
            'cookie_samesite' => 'Lax',
        ]);
    });

    function createService(): AnalyticsConsentComplianceService
    {
        return new AnalyticsConsentComplianceService(
            config(),
            app('cache.store'),
        );
    }

    test('full compliance check returns valid structure', function (): void {
        $service = createService();
        $result = $service->complianceCheck();

        expect($result)->toHaveKeys(['compliant', 'score', 'max_score', 'checks', 'violations', 'recommendations']);
        expect($result['score'])->toBeInt();
        expect($result['max_score'])->toBeInt();
        expect($result['score'])->toBeLessThanOrEqual($result['max_score']);
        expect($result['checks'])->toBeArray();
        expect($result['violations'])->toBeArray();
        expect($result['recommendations'])->toBeArray();
    });

    test('compliance score returns percentage', function (): void {
        $service = createService();
        $score = $service->complianceScore();

        expect($score)->toBeFloat();
        expect($score)->toBeGreaterThanOrEqual(0.0);
        expect($score)->toBeLessThanOrEqual(100.0);
    });

    test('GDPR-safe defaults score high', function (): void {
        $service = createService();
        $score = $service->complianceScore();

        // With GDPR-safe config, should score >= 80%
        expect($score)->toBeGreaterThanOrEqual(80.0);
    });

    test('audit report returns GDPR Article 30 structure', function (): void {
        $service = createService();
        $report = $service->auditReport();

        expect($report)->toHaveKey('generated_at');
        expect($report)->toHaveKey('processing_activities');
        expect($report)->toHaveKey('consent_signals');
        expect($report)->toHaveKey('compliance_score');
        expect($report)->toHaveKey('compliant');
        expect($report)->toHaveKey('violations');
        expect($report)->toHaveKey('recommendations');
        expect($report['processing_activities'])->toBeArray();
        expect($report['processing_activities'])->not->toBeEmpty();
    });

    test('consent_mode_v2_signals check passes with all purposes', function (): void {
        Config::set('zeroboiler.analytics.consent.purposes', [
            'analytics_storage' => ['label' => 'Analytics', 'required' => false, 'default' => true],
            'ad_storage' => ['label' => 'Ads', 'required' => false, 'default' => false],
            'ad_user_data' => ['label' => 'Ad User Data', 'required' => false, 'default' => false],
            'ad_personalization' => ['label' => 'Ad Personalization', 'required' => false, 'default' => false],
            'functionality_storage' => ['label' => 'Functionality', 'required' => false, 'default' => true],
            'personalization_storage' => ['label' => 'Personalization', 'required' => false, 'default' => true],
            'security_storage' => ['label' => 'Security', 'required' => false, 'default' => true],
        ]);

        $service = createService();
        $result = $service->complianceCheck();

        $signalCheck = array_filter(
            $result['checks'],
            static fn (array $c): bool => $c['check'] === 'consent_mode_v2_signals',
        );
        $signalCheck = array_values($signalCheck);

        expect($signalCheck[0]['status'])->toBe('pass');
    });

    test('default consent denied passes GDPR-safe check', function (): void {
        Config::set('zeroboiler.analytics.consent.default', 'denied');

        $service = createService();
        $result = $service->complianceCheck();

        $defaultCheck = array_filter(
            $result['checks'],
            static fn (array $c): bool => $c['check'] === 'default_consent_state',
        );
        $defaultCheck = array_values($defaultCheck);

        expect($defaultCheck[0]['status'])->toBe('pass');
    });

    test('default consent granted produces warning', function (): void {
        Config::set('zeroboiler.analytics.consent.default', 'granted');

        $service = createService();
        $result = $service->complianceCheck();

        $defaultCheck = array_filter(
            $result['checks'],
            static fn (array $c): bool => $c['check'] === 'default_consent_state',
        );
        $defaultCheck = array_values($defaultCheck);

        expect($defaultCheck[0]['status'])->toBe('warn');
    });

    test('consent logging enabled passes audit trail check', function (): void {
        Config::set('zeroboiler.analytics.consent.log_enabled', true);

        $service = createService();
        $result = $service->complianceCheck();

        $logCheck = array_filter(
            $result['checks'],
            static fn (array $c): bool => $c['check'] === 'consent_logging',
        );
        $logCheck = array_values($logCheck);

        expect($logCheck[0]['status'])->toBe('pass');
    });

    test('consent logging disabled produces warning', function (): void {
        Config::set('zeroboiler.analytics.consent.log_enabled', false);

        $service = createService();
        $result = $service->complianceCheck();

        $logCheck = array_filter(
            $result['checks'],
            static fn (array $c): bool => $c['check'] === 'consent_logging',
        );
        $logCheck = array_values($logCheck);

        expect($logCheck[0]['status'])->toBe('warn');
    });

    test('consent TTL meets 90-day minimum', function (): void {
        Config::set('zeroboiler.analytics.consent.log_ttl', 7776000);

        $service = createService();
        $result = $service->complianceCheck();

        $ttlCheck = array_filter(
            $result['checks'],
            static fn (array $c): bool => $c['check'] === 'consent_ttl',
        );
        $ttlCheck = array_values($ttlCheck);

        expect($ttlCheck[0]['status'])->toBe('pass');
    });

    test('consent TTL below 90 days produces warning', function (): void {
        Config::set('zeroboiler.analytics.consent.log_ttl', 86400); // 1 day

        $service = createService();
        $result = $service->complianceCheck();

        $ttlCheck = array_filter(
            $result['checks'],
            static fn (array $c): bool => $c['check'] === 'consent_ttl',
        );
        $ttlCheck = array_values($ttlCheck);

        expect($ttlCheck[0]['status'])->toBe('warn');
    });

    test('regional consent enabled passes check', function (): void {
        Config::set('zeroboiler.analytics.regional_consent.enabled', true);

        $service = createService();
        $result = $service->complianceCheck();

        $regionalCheck = array_filter(
            $result['checks'],
            static fn (array $c): bool => $c['check'] === 'regional_consent',
        );
        $regionalCheck = array_values($regionalCheck);

        expect($regionalCheck[0]['status'])->toBe('pass');
    });

    test('provider consent gating check validates middleware classes', function (): void {
        $service = createService();
        $result = $service->complianceCheck();

        $gatingCheck = array_filter(
            $result['checks'],
            static fn (array $c): bool => $c['check'] === 'provider_consent_gating',
        );
        $gatingCheck = array_values($gatingCheck);

        expect($gatingCheck[0]['status'])->toBe('pass');
    });

    test('invalid cache invalidation clears results', function (): void {
        $service = createService();

        // Run once to cache
        $result1 = $service->complianceCheck();

        // Invalidate
        $service->invalidateCache();

        // Should still work (recomputed)
        $result2 = $service->complianceCheck();

        expect($result2['score'])->toBe($result1['score']);
    });

    test('each check has required fields', function (): void {
        $service = createService();
        $result = $service->complianceCheck();

        foreach ($result['checks'] as $check) {
            expect($check)->toHaveKeys(['check', 'status', 'message', 'severity']);
            expect($check['status'])->toBeIn(['pass', 'warn', 'fail']);
            expect($check['severity'])->toBeIn(['info', 'warning', 'error']);
        }
    });
});
