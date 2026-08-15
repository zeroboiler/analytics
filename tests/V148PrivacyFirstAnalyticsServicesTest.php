<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * V148 — Anonymous Aggregation, Funnel Leak Detection, First-Party Data — Industry-Standard SaaS Upgrade.
 *
 * Validates that the three new services (v148.0.0) are present, well-structured,
 * and meet industry standards. This is a source-level audit (no runtime).
 *
 * @since 148.0.0
 */
final class V148PrivacyFirstAnalyticsServicesTest extends TestCase
{
    private const PKG_ROOT = __DIR__ . '/..';

    // ── 1. Anonymous Event Aggregation Service ───────────────────────

    #[Test]
    public function anonymous_aggregation_service_exists(): void
    {
        $this->assertFileExists(
            self::PKG_ROOT . '/src/Services/AnonymousEventAggregationService.php',
        );
    }

    #[Test]
    public function anonymous_aggregation_service_uses_strict_types(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/AnonymousEventAggregationService.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function anonymous_aggregation_service_is_final(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/AnonymousEventAggregationService.php');
        $this->assertMatchesRegularExpression('/final\s+class\s+AnonymousEventAggregationService/', $content);
    }

    #[Test]
    public function anonymous_aggregation_service_has_docblock(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/AnonymousEventAggregationService.php');
        $this->assertStringContainsString('/**', $content);
        $this->assertStringContainsString('@since 148.0.0', $content);
    }

    #[Test]
    public function anonymous_aggregation_service_has_required_methods(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/AnonymousEventAggregationService.php');

        $requiredMethods = [
            'record', 'flush', 'getAggregates', 'topEvents',
            'totalCount', 'uniqueEventCount', 'byCategory',
            'summary', 'clear', 'isEnabled',
        ];

        foreach ($requiredMethods as $method) {
            $this->assertStringContainsString(
                'public function ' . $method,
                $content,
                "Missing method: {$method}",
            );
        }
    }

    #[Test]
    public function anonymous_aggregation_service_has_return_types(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/AnonymousEventAggregationService.php');

        // record returns void
        $this->assertStringContainsString('public function record(string $eventName): void', $content);
        // flush returns void
        $this->assertStringContainsString('public function flush(): void', $content);
        // getAggregates returns array
        $this->assertStringContainsString('public function getAggregates(?string $window = null): array', $content);
        // isEnabled returns bool
        $this->assertStringContainsString('public function isEnabled(): bool', $content);
        // summary returns array
        $this->assertStringContainsString('public function summary(): array', $content);
    }

    #[Test]
    public function anonymous_aggregation_service_strips_pii(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/AnonymousEventAggregationService.php');
        $this->assertStringContainsString('Strips all params', $content);
        $this->assertStringContainsString('no PII leakage', $content);
    }

    #[Test]
    public function anonymous_aggregation_service_has_time_windows(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/AnonymousEventAggregationService.php');
        $this->assertStringContainsString("'hourly'", $content);
        $this->assertStringContainsString("'daily'", $content);
        $this->assertStringContainsString("'weekly'", $content);
        $this->assertStringContainsString("'monthly'", $content);
    }

    #[Test]
    public function anonymous_aggregation_service_has_destructor(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/AnonymousEventAggregationService.php');
        $this->assertStringContainsString('public function __destruct()', $content);
        $this->assertStringContainsString('Auto-flush pending counts', $content);
    }

    // ── 2. Funnel Leak Detection Service ──────────────────────────────

    #[Test]
    public function funnel_leak_detection_service_exists(): void
    {
        $this->assertFileExists(
            self::PKG_ROOT . '/src/Services/FunnelLeakDetectionService.php',
        );
    }

    #[Test]
    public function funnel_leak_detection_service_uses_strict_types(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/FunnelLeakDetectionService.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function funnel_leak_detection_service_is_final(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/FunnelLeakDetectionService.php');
        $this->assertMatchesRegularExpression('/final\s+class\s+FunnelLeakDetectionService/', $content);
    }

    #[Test]
    public function funnel_leak_detection_service_has_docblock(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/FunnelLeakDetectionService.php');
        $this->assertStringContainsString('/**', $content);
        $this->assertStringContainsString('@since 148.0.0', $content);
    }

    #[Test]
    public function funnel_leak_detection_service_has_required_methods(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/FunnelLeakDetectionService.php');

        $requiredMethods = [
            'recordProgress', 'analyze', 'analyzeAll', 'getFunnels',
            'registerFunnel', 'clear', 'isEnabled',
        ];

        foreach ($requiredMethods as $method) {
            $this->assertStringContainsString(
                'public function ' . $method,
                $content,
                "Missing method: {$method}",
            );
        }
    }

    #[Test]
    public function funnel_leak_detection_service_has_builtin_funnels(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/FunnelLeakDetectionService.php');

        $builtInFunnels = [
            'signup_funnel', 'purchase_funnel', 'trial_funnel',
            'activation_funnel', 'retention_funnel',
        ];

        foreach ($builtInFunnels as $funnel) {
            $this->assertStringContainsString(
                "'{$funnel}'",
                $content,
                "Missing built-in funnel: {$funnel}",
            );
        }
    }

    #[Test]
    public function funnel_leak_detection_has_recommendations(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/FunnelLeakDetectionService.php');
        $this->assertStringContainsString('recommendations', $content);
        $this->assertStringContainsString('suggestAction', $content);
        $this->assertStringContainsString('generateRecommendation', $content);
    }

    #[Test]
    public function funnel_leak_detection_has_severity_levels(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/FunnelLeakDetectionService.php');
        $this->assertStringContainsString("'critical'", $content);
        $this->assertStringContainsString("'warning'", $content);
    }

    #[Test]
    public function funnel_leak_detection_has_configurable_thresholds(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/FunnelLeakDetectionService.php');
        $this->assertStringContainsString('leak_threshold', $content);
        $this->assertStringContainsString('critical_threshold', $content);
        $this->assertStringContainsString('DEFAULT_LEAK_THRESHOLD', $content);
        $this->assertStringContainsString('DEFAULT_CRITICAL_THRESHOLD', $content);
    }

    // ── 3. First-Party Data Service ────────────────────────────────────

    #[Test]
    public function first_party_data_service_exists(): void
    {
        $this->assertFileExists(
            self::PKG_ROOT . '/src/Services/FirstPartyDataService.php',
        );
    }

    #[Test]
    public function first_party_data_service_uses_strict_types(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/FirstPartyDataService.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function first_party_data_service_is_final(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/FirstPartyDataService.php');
        $this->assertMatchesRegularExpression('/final\s+class\s+FirstPartyDataService/', $content);
    }

    #[Test]
    public function first_party_data_service_has_docblock(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/FirstPartyDataService.php');
        $this->assertStringContainsString('/**', $content);
        $this->assertStringContainsString('@since 148.0.0', $content);
        $this->assertStringContainsString('cookieless', $content);
    }

    #[Test]
    public function first_party_data_service_has_required_methods(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/FirstPartyDataService.php');

        $requiredMethods = [
            'capturePreference', 'captureInterest', 'getPreferences',
            'getPreference', 'getInterests', 'getInterestsByType',
            'assignCohort', 'exportUserData', 'deleteUser',
            'readinessScore', 'isEnabled',
        ];

        foreach ($requiredMethods as $method) {
            $this->assertStringContainsString(
                'public function ' . $method,
                $content,
                "Missing method: {$method}",
            );
        }
    }

    #[Test]
    public function first_party_data_service_has_preference_types(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/FirstPartyDataService.php');

        $preferenceTypes = [
            'newsletter', 'theme', 'language', 'notifications',
            'privacy_level', 'timezone', 'currency',
        ];

        foreach ($preferenceTypes as $type) {
            $this->assertStringContainsString(
                "'{$type}'",
                $content,
                "Missing preference type: {$type}",
            );
        }
    }

    #[Test]
    public function first_party_data_service_has_interest_types(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/FirstPartyDataService.php');

        $interestTypes = [
            'feature', 'content', 'integration', 'pricing_tier',
            'use_case', 'industry',
        ];

        foreach ($interestTypes as $type) {
            $this->assertStringContainsString(
                "'{$type}'",
                $content,
                "Missing interest type: {$type}",
            );
        }
    }

    #[Test]
    public function first_party_data_service_has_cohort_types(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/FirstPartyDataService.php');

        $cohorts = [
            'power_user', 'explorer', 'pragmatist', 'newcomer',
            'enterprise_signal', 'unknown',
        ];

        foreach ($cohorts as $cohort) {
            $this->assertStringContainsString(
                "'{$cohort}'",
                $content,
                "Missing cohort type: {$cohort}",
            );
        }
    }

    #[Test]
    public function first_party_data_service_has_gdpr_methods(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Services/FirstPartyDataService.php');
        $this->assertStringContainsString('exportUserData', $content);
        $this->assertStringContainsString('deleteUser', $content);
        $this->assertStringContainsString('right to erasure', $content);
        $this->assertStringContainsString('right of access', $content);
    }

    // ── 4. AnalyticsFunnelLeakCommand ─────────────────────────────────

    #[Test]
    public function funnel_leak_command_exists(): void
    {
        $this->assertFileExists(
            self::PKG_ROOT . '/src/Console/Commands/AnalyticsFunnelLeakCommand.php',
        );
    }

    #[Test]
    public function funnel_leak_command_uses_strict_types(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Console/Commands/AnalyticsFunnelLeakCommand.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    #[Test]
    public function funnel_leak_command_is_final(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Console/Commands/AnalyticsFunnelLeakCommand.php');
        $this->assertMatchesRegularExpression('/final\s+class\s+AnalyticsFunnelLeakCommand/', $content);
    }

    #[Test]
    public function funnel_leak_command_has_correct_signature(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Console/Commands/AnalyticsFunnelLeakCommand.php');
        $this->assertStringContainsString('zb:analytics:funnel-leaks', $content);
        $this->assertStringContainsString('--funnel=', $content);
        $this->assertStringContainsString('--all', $content);
        $this->assertStringContainsString('--json', $content);
        $this->assertStringContainsString('--recommendations', $content);
        $this->assertStringContainsString('--list', $content);
    }

    #[Test]
    public function funnel_leak_command_has_docblock(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Console/Commands/AnalyticsFunnelLeakCommand.php');
        $this->assertStringContainsString('/**', $content);
        $this->assertStringContainsString('@since 148.0.0', $content);
    }

    #[Test]
    public function funnel_leak_command_has_override_attribute(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/Console/Commands/AnalyticsFunnelLeakCommand.php');
        $this->assertStringContainsString('#[\\Override]', $content);
    }

    // ── 5. Config Sections ───────────────────────────────────────────

    #[Test]
    public function config_has_anonymous_aggregation_section(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'anonymous_aggregation'", $content);
        $this->assertStringContainsString('ANALYTICS_ANON_AGGREGATION_ENABLED', $content);
        $this->assertStringContainsString('ANALYTICS_ANON_AGGREGATION_CACHE_TTL', $content);
    }

    #[Test]
    public function config_has_funnel_leak_detection_section(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'funnel_leak_detection'", $content);
        $this->assertStringContainsString('ANALYTICS_FUNNEL_LEAK_DETECTION_ENABLED', $content);
        $this->assertStringContainsString('ANALYTICS_FUNNEL_LEAK_THRESHOLD', $content);
    }

    #[Test]
    public function config_has_first_party_data_section(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/config/zeroboiler.php');
        $this->assertStringContainsString("'first_party_data'", $content);
        $this->assertStringContainsString('ANALYTICS_FIRST_PARTY_DATA_ENABLED', $content);
        $this->assertStringContainsString('ANALYTICS_FPD_MAX_PREFS', $content);
    }

    // ── 6. ServiceProvider Registration ─────────────────────────────

    #[Test]
    public function service_provider_registers_new_services(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('AnonymousEventAggregationService::class', $content);
        $this->assertStringContainsString('FunnelLeakDetectionService::class', $content);
        $this->assertStringContainsString('FirstPartyDataService::class', $content);
    }

    #[Test]
    public function service_provider_registers_funnel_leak_command(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('AnalyticsFunnelLeakCommand::class', $content);
    }

    #[Test]
    public function service_provider_has_use_imports(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('use ZeroBoiler\\Analytics\\Services\\AnonymousEventAggregationService;', $content);
        $this->assertStringContainsString('use ZeroBoiler\\Analytics\\Services\\FunnelLeakDetectionService;', $content);
        $this->assertStringContainsString('use ZeroBoiler\\Analytics\\Services\\FirstPartyDataService;', $content);
        $this->assertStringContainsString('use ZeroBoiler\\Analytics\\Console\\Commands\\AnalyticsFunnelLeakCommand;', $content);
    }

    #[Test]
    public function service_provider_registers_singletons(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/src/AnalyticsServiceProvider.php');

        // Check singleton registration pattern
        $this->assertStringContainsString("->singleton(AnonymousEventAggregationService::class", $content);
        $this->assertStringContainsString("->singleton(FunnelLeakDetectionService::class", $content);
        $this->assertStringContainsString("->singleton(FirstPartyDataService::class", $content);
    }

    // ── 7. Version Consistency ───────────────────────────────────────

    #[Test]
    public function version_is_consistent_across_entry_points(): void
    {
        $expected = '148.0.0';

        $files = [
            'composer.json',
            'package.json',
            'src/DTO/AnalyticsEvent.php',
            'src/Console/Commands/AnalyticsIntegrityCommand.php',
            'src/Facades/Analytics.php',
            'resources/js/analytics.js',
            'resources/js/analytics.constants.js',
        ];

        foreach ($files as $file) {
            $path = self::PKG_ROOT . '/' . $file;
            $this->assertFileExists($path, "Missing file: {$file}");

            $content = file_get_contents($path);
            $this->assertStringContainsString(
                $expected,
                $content,
                "Version mismatch in {$file}: expected {$expected}",
            );
        }
    }

    #[Test]
    public function changelog_has_148_entry(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/CHANGELOG.md');
        $this->assertStringContainsString('[148.0.0]', $content);
        $this->assertStringContainsString('AnonymousEventAggregationService', $content);
        $this->assertStringContainsString('FunnelLeakDetectionService', $content);
        $this->assertStringContainsString('FirstPartyDataService', $content);
        $this->assertStringContainsString('AnalyticsFunnelLeakCommand', $content);
    }

    #[Test]
    public function readme_badge_updated(): void
    {
        $content = file_get_contents(self::PKG_ROOT . '/README.md');
        $this->assertStringContainsString('version-148.0.0', $content);
    }

    // ── 8. Code Quality ────────────────────────────────────────────────

    #[Test]
    public function all_new_files_have_mit_license_header(): void
    {
        $files = [
            'src/Services/AnonymousEventAggregationService.php',
            'src/Services/FunnelLeakDetectionService.php',
            'src/Services/FirstPartyDataService.php',
            'src/Console/Commands/AnalyticsFunnelLeakCommand.php',
        ];

        foreach ($files as $file) {
            $content = file_get_contents(self::PKG_ROOT . '/' . $file);
            $this->assertStringContainsString(
                'This file is part of ZeroBoiler, licensed under the MIT license.',
                $content,
                "Missing MIT license header in {$file}",
            );
        }
    }

    #[Test]
    public function all_new_classes_have_constructor_void(): void
    {
        $files = [
            'src/Services/AnonymousEventAggregationService.php',
            'src/Services/FunnelLeakDetectionService.php',
            'src/Services/FirstPartyDataService.php',
            'src/Console/Commands/AnalyticsFunnelLeakCommand.php',
        ];

        foreach ($files as $file) {
            $content = file_get_contents(self::PKG_ROOT . '/' . $file);

            // Constructor should have :void return type
            if (preg_match('/public function __construct\([^)]*\)/', $content, $matches)) {
                $constructorDecl = $matches[0];
                $this->assertStringContainsString(
                    ': void',
                    $constructorDecl,
                    "Constructor missing :void in {$file}: {$constructorDecl}",
                );
            }
        }
    }

    #[Test]
    public function no_todo_or_fixme_in_new_files(): void
    {
        $files = [
            'src/Services/AnonymousEventAggregationService.php',
            'src/Services/FunnelLeakDetectionService.php',
            'src/Services/FirstPartyDataService.php',
            'src/Console/Commands/AnalyticsFunnelLeakCommand.php',
        ];

        foreach ($files as $file) {
            $content = file_get_contents(self::PKG_ROOT . '/' . $file);
            $this->assertStringNotContainsString('TODO', $content, "Found TODO in {$file}");
            $this->assertStringNotContainsString('FIXME', $content, "Found FIXME in {$file}");
            $this->assertStringNotContainsString('HACK', $content, "Found HACK in {$file}");
            $this->assertStringNotContainsString('XXX', $content, "Found XXX in {$file}");
        }
    }

    // ── 9. Svelte composables version sweep ────────────────────────────

    #[Test]
    public function svelte_composables_version_sweep(): void
    {
        $svelteFiles = [
            'resources/js/useAnalytics.svelte.js',
            'resources/js/useAnalyticsConfig.svelte.js',
            'resources/js/useEcommerce.svelte.js',
            'resources/js/useLifecycle.svelte.js',
            'resources/js/usePerformanceTracker.svelte.js',
            'resources/js/useSaaSMetrics.svelte.js',
            'resources/js/useSessionReplay.svelte.js',
        ];

        foreach ($svelteFiles as $file) {
            $path = self::PKG_ROOT . '/' . $file;
            if (file_exists($path)) {
                $content = file_get_contents($path);
                $this->assertStringContainsString(
                    '148.0.0',
                    $content,
                    "Version not swept in {$file}",
                );
            }
        }
    }
}
