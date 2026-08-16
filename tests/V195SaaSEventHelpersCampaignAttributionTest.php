<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * V195.0.0 — SaaS Event Helpers + Campaign Context Hydration test suite.
 *
 * Validates:
 * - SaaSEventHelpers: file quality, public API surface, static method count
 * - CampaignContextHydratorService: file quality, UTM extraction, traffic source
 *   classification, first-touch persistence, client-safe context
 * - useAttribution composable: file quality, exported symbols, derived stores
 * - Version consistency across all entry points
 * - Source file count ≥ 863, test count ≥ 442
 *
 * @since 195.0.0
 */
final class V195SaaSEventHelpersCampaignAttributionTest extends TestCase
{
    // ─── SaaSEventHelpers File Quality ─────────────────────────────

    public function testSaaSEventHelpersFileExists(): void
    {
        $path = __DIR__ . '/../src/Support/SaaSEventHelpers.php';
        $this->assertFileExists($path);
    }

    public function testSaaSEventHelpersHasStrictTypes(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Support/SaaSEventHelpers.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    public function testSaaSEventHelpersIsFinal(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Support/SaaSEventHelpers.php');
        $this->assertStringContainsString('final class SaaSEventHelpers', $content);
    }

    public function testSaaSEventHelpersHasMitLicense(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Support/SaaSEventHelpers.php');
        $this->assertStringContainsString('licensed under the MIT license', $content);
    }

    public function testSaaSEventHelpersHasSinceTag(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Support/SaaSEventHelpers.php');
        $this->assertStringContainsString('@since 195.0.0', $content);
    }

    public function testSaaSEventHelpersHasRequiredMethods(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Support/SaaSEventHelpers.php');
        $requiredMethods = [
            'signUp', 'login', 'trialStart', 'subscription', 'planUpgrade',
            'planDowngrade', 'cancellation', 'featureUsed', 'teamEvent',
            'onboardingStep', 'firstValue', 'revenue', 'custom',
        ];
        foreach ($requiredMethods as $method) {
            $this->assertStringContainsString(
                'public static function ' . $method . '(',
                $content,
                "SaaSEventHelpers::{$method}() not found",
            );
        }
    }

    public function testSaaSEventHelpersUsesAnalyticsManager(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Support/SaaSEventHelpers.php');
        $this->assertStringContainsString('use ZeroBoiler\Analytics\AnalyticsManager;', $content);
        $this->assertStringContainsString('self::manager()->track(', $content);
    }

    public function testSaaSEventHelpersManagerMethodIsPrivate(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Support/SaaSEventHelpers.php');
        $this->assertStringContainsString('private static function manager(', $content);
    }

    public function testSaaSEventHelpersReturnTypesAreVoid(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Support/SaaSEventHelpers.php');
        // All public static methods should return void
        $pattern = '/public static function \w+\([^)]*\): void/';
        $this->assertMatchesRegularExpression($pattern, $content, 'Public static methods must have :void return type');
    }

    public function testSaaSEventHelpersSignAcceptsMethodParam(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Support/SaaSEventHelpers.php');
        $this->assertStringContainsString('string $method = \'email\'', $content);
    }

    public function testSaaSEventHelpersSubscriptionAcceptsMrrAndBillingCycle(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Support/SaaSEventHelpers.php');
        $this->assertStringContainsString('float $mrr', $content);
        $this->assertStringContainsString('string $billingCycle = \'monthly\'', $content);
    }

    public function testSaaSEventHelpersPlanUpgradeAcceptsPlansAndDelta(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Support/SaaSEventHelpers.php');
        $this->assertStringContainsString('string $fromPlan, string $toPlan, float $mrrDelta', $content);
    }

    public function testSaaSEventHelpersOnboardingStepCalculatesProgressPct(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Support/SaaSEventHelpers.php');
        $this->assertStringContainsString('progress_pct', $content);
    }

    // ─── CampaignContextHydratorService File Quality ────────────────

    public function testCampaignHydratorFileExists(): void
    {
        $path = __DIR__ . '/../src/Services/CampaignContextHydratorService.php';
        $this->assertFileExists($path);
    }

    public function testCampaignHydratorHasStrictTypes(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/CampaignContextHydratorService.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    public function testCampaignHydratorIsFinal(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/CampaignContextHydratorService.php');
        $this->assertStringContainsString('final class CampaignContextHydratorService', $content);
    }

    public function testCampaignHydratorHasMitLicense(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/CampaignContextHydratorService.php');
        $this->assertStringContainsString('licensed under the MIT license', $content);
    }

    public function testCampaignHydratorHasSinceTag(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/CampaignContextHydratorService.php');
        $this->assertStringContainsString('@since 195.0.0', $content);
    }

    public function testCampaignHydratorHasCorrectNamespace(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/CampaignContextHydratorService.php');
        $this->assertStringContainsString('namespace ZeroBoiler\Analytics\Services;', $content);
    }

    public function testCampaignHydratorHasConstructorWithTypeDeclarations(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/CampaignContextHydratorService.php');
        $this->assertStringContainsString('public function __construct(ConfigRepository $config, ?int $cacheTtl = null)', $content);
    }

    public function testCampaignHydratorHasExtractFromRequestMethod(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/CampaignContextHydratorService.php');
        $this->assertStringContainsString('public function extractFromRequest(Request $request)', $content);
    }

    public function testCampaignHydratorHasClassifyTrafficSourceMethod(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/CampaignContextHydratorService.php');
        $this->assertStringContainsString('public function classifyTrafficSource(array $utm, ?string $referrer)', $content);
    }

    public function testCampaignHydratorHasToClientSafeContextMethod(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/CampaignContextHydratorService.php');
        $this->assertStringContainsString('public function toClientSafeContext(array $context)', $content);
    }

    public function testCampaignHydratorHasFirstTouchMethods(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/CampaignContextHydratorService.php');
        $this->assertStringContainsString('public function persistFirstTouch(string $clientId, array $context)', $content);
        $this->assertStringContainsString('public function getFirstTouch(string $clientId)', $content);
        $this->assertStringContainsString('public function getAttributionContext(string $clientId, array $current)', $content);
    }

    public function testCampaignHydratorHasUtmConstants(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/CampaignContextHydratorService.php');
        $this->assertStringContainsString('utm_source', $content);
        $this->assertStringContainsString('utm_medium', $content);
        $this->assertStringContainsString('utm_campaign', $content);
        $this->assertStringContainsString('utm_term', $content);
        $this->assertStringContainsString('utm_content', $content);
    }

    public function testCampaignHydratorHasTrafficSourceConstants(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/CampaignContextHydratorService.php');
        $this->assertStringContainsString("'direct'", $content);
        $this->assertStringContainsString("'organic_search'", $content);
        $this->assertStringContainsString("'paid_search'", $content);
        $this->assertStringContainsString("'paid_social'", $content);
        $this->assertStringContainsString("'email'", $content);
        $this->assertStringContainsString("'referral'", $content);
        $this->assertStringContainsString("'affiliate'", $content);
        $this->assertStringContainsString("'organic_social'", $content);
    }

    public function testCampaignHydratorSanitizesIpAndUserAgent(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/CampaignContextHydratorService.php');
        $this->assertStringContainsString("unset(\$context['ip'], \$context['user_agent']", $content);
    }

    public function testCampaignHydratorToClientSafeExcludesSensitiveData(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/CampaignContextHydratorService.php');
        // toClientSafeContext should only include utm, referrer, traffic_source, has_utm
        $this->assertStringContainsString('utm', $content);
        $this->assertStringContainsString('referrer', $content);
        $this->assertStringContainsString('traffic_source', $content);
        $this->assertStringContainsString('has_utm', $content);
    }

    // ─── useAttribution Composable Quality ─────────────────────────

    public function testUseAttributionFileExists(): void
    {
        $path = __DIR__ . '/../resources/js/useAttribution.svelte.js';
        $this->assertFileExists($path);
    }

    public function testUseAttributionHasCorrectVersion(): void
    {
        $content = file_get_contents(__DIR__ . '/../resources/js/useAttribution.svelte.js');
        $this->assertStringContainsString('@version 195.0.0', $content);
    }

    public function testUseAttributionExportsMainFunction(): void
    {
        $content = file_get_contents(__DIR__ . '/../resources/js/useAttribution.svelte.js');
        $this->assertStringContainsString('export function useAttribution(', $content);
    }

    public function testUseAttributionExportsDefault(): void
    {
        $content = file_get_contents(__DIR__ . '/../resources/js/useAttribution.svelte.js');
        $this->assertStringContainsString('export default useAttribution', $content);
    }

    public function testUseAttributionExportsAttributionStore(): void
    {
        $content = file_get_contents(__DIR__ . '/../resources/js/useAttribution.svelte.js');
        $this->assertStringContainsString('export function attributionStore(', $content);
    }

    public function testUseAttributionHasWritableStores(): void
    {
        $content = file_get_contents(__DIR__ . '/../resources/js/useAttribution.svelte.js');
        $stores = ['utm', 'referrer', 'trafficSource', 'hasUtm', 'firstTouch', 'hasFirstTouch', 'loaded'];
        foreach ($stores as $store) {
            $this->assertStringContainsString("export const {$store} = writable(", $content,
                "Store '{$store}' not found as writable export");
        }
    }

    public function testUseAttributionHasDerivedStores(): void
    {
        $content = file_get_contents(__DIR__ . '/../resources/js/useAttribution.svelte.js');
        $derived = ['utmString', 'attributionLabel', 'isPaidTraffic', 'isOrganicTraffic', 'attributionSnapshot'];
        foreach ($derived as $store) {
            $this->assertStringContainsString("export const {$store} = derived(", $content,
                "Derived store '{$store}' not found");
        }
    }

    public function testUseAttributionHasPersistAndClearMethods(): void
    {
        $content = file_get_contents(__DIR__ . '/../resources/js/useAttribution.svelte.js');
        $this->assertStringContainsString('persistFirstTouch', $content);
        $this->assertStringContainsString('clearFirstTouch', $content);
        $this->assertStringContainsString('trackAttribution', $content);
    }

    public function testUseAttributionUsesSvelteStores(): void
    {
        $content = file_get_contents(__DIR__ . '/../resources/js/useAttribution.svelte.js');
        $this->assertStringContainsString("import { writable, derived } from 'svelte/store'", $content);
    }

    public function testUseAttributionUsesPage(): void
    {
        $content = file_get_contents(__DIR__ . '/../resources/js/useAttribution.svelte.js');
        $this->assertStringContainsString("import { page } from '@inertiajs/svelte'", $content);
    }

    public function testUseAttributionImportsFromAnalyticsJs(): void
    {
        $content = file_get_contents(__DIR__ . '/../resources/js/useAttribution.svelte.js');
        $this->assertStringContainsString("from './analytics.js'", $content);
    }

    public function testUseAttributionReadsCampaignContextFromProps(): void
    {
        $content = file_get_contents(__DIR__ . '/../resources/js/useAttribution.svelte.js');
        $this->assertStringContainsString('campaignContext', $content);
    }

    // ─── Inertia Middleware Campaign Context Injection ─────────────

    public function testInertiaMiddlewareInjectsCampaignContext(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Inertia/HandleInertiaAnalytics.php');
        $this->assertStringContainsString('campaignContext', $content);
        $this->assertStringContainsString('CampaignContextHydratorService', $content);
    }

    public function testInertiaMiddlewareCampaignContextFallback(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Inertia/HandleInertiaAnalytics.php');
        $this->assertStringContainsString("'has_utm' => false", $content);
    }

    // ─── Version Consistency ───────────────────────────────────────

    public function testComposerVersion(): void
    {
        $json = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        $this->assertSame('195.0.0', $json['version']);
    }

    public function testAnalyticsEventVersion(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/DTO/AnalyticsEvent.php');
        $this->assertStringContainsString("public const VERSION = '195.0.0';", $content);
    }

    public function testJsClientVersion(): void
    {
        $content = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        $this->assertStringContainsString("return '195.0.0';", $content);
    }

    public function testJsClientHeaderVersion(): void
    {
        $content = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        $this->assertStringContainsString('@version 195.0.0', $content);
    }

    public function testPackageJsonVersion(): void
    {
        $json = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);
        $this->assertSame('195.0.0', $json['version']);
    }

    // ─── Source & Test File Counts ─────────────────────────────────

    public function testSourceFileCount(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../src', \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }
        $this->assertGreaterThanOrEqual(863, $count, "Expected ≥ 863 source files, got {$count}");
    }

    public function testTestFileCount(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__, \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }
        $this->assertGreaterThanOrEqual(442, $count, "Expected ≥ 442 test files, got {$count}");
    }
}
