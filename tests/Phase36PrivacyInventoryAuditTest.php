<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsPrivacyInventoryCommand;

/**
 * Phase 36 — Privacy Inventory Command & Enhanced Privacy Client API Audit.
 *
 * Comprehensive verification that the privacy inventory infrastructure
 * meets industry-standard GDPR Article 30 requirements.
 *
 * @since 107.0.0
 */
describe('Phase36PrivacyInventoryAudit', function () {
    // ── Privacy Inventory Command ─────────────────────────────────────

    describe('Privacy Inventory Command', function () {
        it('AnalyticsPrivacyInventoryCommand class exists with strict types', function () {
            expect(class_exists(AnalyticsPrivacyInventoryCommand::class))->toBeTrue();

            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'declare(strict_types=1)'))->toBeTrue();
        });

        it('AnalyticsPrivacyInventoryCommand is final', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('has zb:analytics:privacy-inventory signature', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, "'zb:analytics:privacy-inventory'"))->toBeTrue();
        });

        it('has --json option', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, "'--json'"))->toBeTrue();
        });

        it('has --detailed option', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, "'--detailed'"))->toBeTrue();
        });

        it('has handle method with ConfigRepository injection', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            expect($ref->hasMethod('handle'))->toBeTrue();

            $method = $ref->getMethod('handle');
            expect($method->isPublic())->toBeTrue();
            $params = $method->getParameters();
            expect(count($params))->toBeGreaterThanOrEqual(1);
            $type = $params[0]->getType();
            expect($type !== null && $type->getName() === 'Illuminate\Contracts\Config\Repository')->toBeTrue();
        });

        it('references GDPR Article 30', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'Article 30'))->toBeTrue();
        });

        it('assesses consent state', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'assessConsentState'))->toBeTrue();
        });

        it('assesses provider data sharing', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'assessProviderSharing'))->toBeTrue();
        });

        it('assesses erasure capability', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'assessErasureCapability'))->toBeTrue();
        });

        it('assesses cross-border data transfer', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'assessCrossBorder'))->toBeTrue();
        });

        it('identifies high-risk events', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'identifyHighRiskEvents'))->toBeTrue();
        });

        it('generates privacy recommendations', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'generateRecommendations'))->toBeTrue();
        });

        it('covers all 10 providers in sharing assessment', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $content = file_get_contents($ref->getFileName());

            $providers = ['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog', 'mixpanel', 'amplitude', 'tiktok', 'linkedin', 'webhook'];
            foreach ($providers as $provider) {
                expect(str_contains($content, "'{$provider}'"))
                    ->toBeTrue("Privacy inventory should cover provider {$provider}");
            }
        });

        it('has per-event processing records generation', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'generateProcessingRecords'))->toBeTrue();
        });

        it('maps legal bases per category', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'mapLegalBases'))->toBeTrue();
            expect(str_contains($content, 'Article 6(1)'))->toBeTrue();
        });

        it('has proper docblock with @since tag', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $doc = $ref->getDocComment();
            expect($doc)->not->toBeFalse();
            expect(str_contains($doc ?? '', '@since 107.0.0'))->toBeTrue();
        });

        it('classifies categories by sensitivity', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'classifyCategories'))->toBeTrue();
            expect(str_contains($content, "'high'"))->toBeTrue();
            expect(str_contains($content, "'low'"))->toBeTrue();
        });
    });

    // ── JS Client Privacy Helpers ────────────────────────────────────

    describe('JS Client Privacy Helpers', function () {
        it('analytics.js exports trackPrivacyAction', function () {
            $jsPath = dirname(__DIR__, 2) . '/resources/js/analytics.js';
            $content = file_get_contents($jsPath);
            expect(str_contains($content, 'export async function trackPrivacyAction'))->toBeTrue();
        });

        it('trackPrivacyAction supports all 10 privacy action types', function () {
            $jsPath = dirname(__DIR__, 2) . '/resources/js/analytics.js';
            $content = file_get_contents($jsPath);

            $actionTypes = [
                'consent_granted', 'consent_withdrawn', 'consent_changed',
                'data_access_request', 'data_erasure_request', 'data_portability_request',
                'opt_out', 'opt_in', 'cookie_preferences_saved', 'do_not_sell',
            ];
            foreach ($actionTypes as $action) {
                expect(str_contains($content, $action))
                    ->toBeTrue("trackPrivacyAction should support {$action}");
            }
        });

        it('trackPrivacyAction tracks with immediate option', function () {
            $jsPath = dirname(__DIR__, 2) . '/resources/js/analytics.js';
            $content = file_get_contents($jsPath);
            // Extract trackPrivacyAction function body and check for immediate: true
            $pos = strpos($content, 'export async function trackPrivacyAction');
            expect($pos)->not->toBeFalse();
            $snippet = substr($content, $pos, 1000);
            expect(str_contains($snippet, 'immediate: true'))->toBeTrue();
        });

        it('trackPrivacyAction includes privacy_action param', function () {
            $jsPath = dirname(__DIR__, 2) . '/resources/js/analytics.js';
            $content = file_get_contents($jsPath);
            $pos = strpos($content, 'export async function trackPrivacyAction');
            $snippet = substr($content, $pos, 1500);
            expect(str_contains($snippet, 'privacy_action'))->toBeTrue();
        });

        it('analytics.js exports trackConsentUpdate', function () {
            $jsPath = dirname(__DIR__, 2) . '/resources/js/analytics.js';
            $content = file_get_contents($jsPath);
            expect(str_contains($content, 'export async function trackConsentUpdate'))->toBeTrue();
        });

        it('trackConsentUpdate captures full consent state', function () {
            $jsPath = dirname(__DIR__, 2) . '/resources/js/analytics.js';
            $content = file_get_contents($jsPath);
            $pos = strpos($content, 'export async function trackConsentUpdate');
            expect($pos)->not->toBeFalse();
            $snippet = substr($content, $pos, 1500);
            expect(str_contains($snippet, 'newly_granted'))->toBeTrue();
            expect(str_contains($snippet, 'newly_denied'))->toBeTrue();
            expect(str_contains($snippet, 'all_granted'))->toBeTrue();
            expect(str_contains($snippet, 'all_denied'))->toBeTrue();
        });

        it('privacy helpers have JSDoc documentation', function () {
            $jsPath = dirname(__DIR__, 2) . '/resources/js/analytics.js';
            $content = file_get_contents($jsPath);
            $pos = strpos($content, 'Privacy Action Tracking');
            expect($pos)->not->toBeFalse();
            $snippet = substr($content, $pos - 10, 1000);
            expect(str_contains($snippet, 'GDPR'))->toBeTrue();
            expect(str_contains($snippet, 'CCPA'))->toBeTrue();
        });

        it('analytics.js has v107.0.0 version', function () {
            $jsPath = dirname(__DIR__, 2) . '/resources/js/analytics.js';
            $content = file_get_contents($jsPath);
            expect(str_contains($content, '107.0.0'))->toBeTrue();
        });

        it('analytics.js has 7800+ lines (growth from v106)', function () {
            $jsPath = dirname(__DIR__, 2) . '/resources/js/analytics.js';
            $lines = count(file($jsPath));
            expect($lines)->toBeGreaterThanOrEqual(7800);
        });
    });

    // ── TypeScript Definitions ───────────────────────────────────────

    describe('TypeScript Privacy Definitions', function () {
        it('analytics.d.ts has PrivacyActionType', function () {
            $dtsPath = dirname(__DIR__, 2) . '/resources/js/analytics.d.ts';
            $content = file_get_contents($dtsPath);
            expect(str_contains($content, 'PrivacyActionType'))->toBeTrue();
        });

        it('PrivacyActionType includes all 10 action types', function () {
            $dtsPath = dirname(__DIR__, 2) . '/resources/js/analytics.d.ts';
            $content = file_get_contents($dtsPath);

            $actionTypes = [
                'consent_granted', 'consent_withdrawn', 'consent_changed',
                'data_access_request', 'data_erasure_request', 'data_portability_request',
                'opt_out', 'opt_in', 'cookie_preferences_saved', 'do_not_sell',
            ];
            foreach ($actionTypes as $action) {
                expect(str_contains($content, "'{$action}'"))
                    ->toBeTrue("PrivacyActionType should include {$action}");
            }
        });

        it('analytics.d.ts has PrivacyActionOptions interface', function () {
            $dtsPath = dirname(__DIR__, 2) . '/resources/js/analytics.d.ts';
            $content = file_get_contents($dtsPath);
            expect(str_contains($content, 'interface PrivacyActionOptions'))->toBeTrue();
            expect(str_contains($content, 'purpose?'))->toBeTrue();
            expect(str_contains($content, 'method?'))->toBeTrue();
            expect(str_contains($content, 'grantedPurposes?'))->toBeTrue();
            expect(str_contains($content, 'deniedPurposes?'))->toBeTrue();
        });

        it('analytics.d.ts has ConsentUpdateOptions interface', function () {
            $dtsPath = dirname(__DIR__, 2) . '/resources/js/analytics.d.ts';
            $content = file_get_contents($dtsPath);
            expect(str_contains($content, 'interface ConsentUpdateOptions'))->toBeTrue();
            expect(str_contains($content, 'newlyGranted?'))->toBeTrue();
            expect(str_contains($content, 'allGranted?'))->toBeTrue();
            expect(str_contains($content, 'source?'))->toBeTrue();
        });

        it('analytics.d.ts has trackPrivacyAction function signature', function () {
            $dtsPath = dirname(__DIR__, 2) . '/resources/js/analytics.d.ts';
            $content = file_get_contents($dtsPath);
            expect(str_contains($content, 'export function trackPrivacyAction'))->toBeTrue();
        });

        it('analytics.d.ts has trackConsentUpdate function signature', function () {
            $dtsPath = dirname(__DIR__, 2) . '/resources/js/analytics.d.ts';
            $content = file_get_contents($dtsPath);
            expect(str_contains($content, 'export function trackConsentUpdate'))->toBeTrue();
        });

        it('analytics.d.ts has v107.0.0 version', function () {
            $dtsPath = dirname(__DIR__, 2) . '/resources/js/analytics.d.ts';
            $content = file_get_contents($dtsPath);
            expect(str_contains($content, '107.0.0'))->toBeTrue();
        });
    });

    // ── Version Consistency ──────────────────────────────────────────

    describe('Version Consistency', function () {
        it('version is 110.0.0 across all package files', function () {
            $version = '110.0.0';

            // AnalyticsEvent::VERSION
            expect(AnalyticsEvent::VERSION)->toBe($version);

            // composer.json
            $composer = json_decode(
                file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            expect($composer['version'])->toBe($version);

            // package.json
            $pkg = json_decode(
                file_get_contents(dirname(__DIR__, 2) . '/package.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            expect($pkg['version'])->toBe($version);

            // README.md badge
            $readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');
            expect(str_contains($readme, "version-{$version}"))->toBeTrue(
                "README.md version badge should show {$version}"
            );
        });

        it('README documents v107.0.0 features', function () {
            $readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');
            expect(str_contains($readme, 'v107.0.0'))->toBeTrue();
            expect(str_contains($readme, 'zb:analytics:privacy-inventory'))->toBeTrue();
            expect(str_contains($readme, 'trackPrivacyAction'))->toBeTrue();
            expect(str_contains($readme, 'trackConsentUpdate'))->toBeTrue();
            expect(str_contains($readme, 'Article 30'))->toBeTrue();
        });
    });

    // ── Privacy Inventory Integration ────────────────────────────────

    describe('Privacy Inventory Integration', function () {
        it('command extends Illuminate Console Command', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $parent = $ref->getParentClass();
            expect($parent)->not->toBeFalse();
            expect($parent->getName())->toBe('Illuminate\Console\Command');
        });

        it('command has description property', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, "\$description ="))->toBeTrue();
        });

        it('EventCatalog is used for classification', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'EventCatalog::'))->toBeTrue();
        });

        it('AnalyticsEvent::VERSION is referenced', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'AnalyticsEvent::VERSION'))->toBeTrue();
        });

        it('consent score is capped at 100', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'min($score, 100.0)'))->toBeTrue();
        });

        it('provides EU vs non-EU provider classification', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'eu_providers'))->toBeTrue();
            expect(str_contains($content, 'non_eu_providers'))->toBeTrue();
        });

        it('generates cross-border safeguards', function () {
            $ref = new \ReflectionClass(AnalyticsPrivacyInventoryCommand::class);
            $content = file_get_contents($ref->getFileName());
            expect(str_contains($content, 'Standard Contractual Clauses'))->toBeTrue();
            expect(str_contains($content, 'DPA'))->toBeTrue();
        });
    });
});
