<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Phase 52 — Event Volume Anomaly Detection + Provider Resilience (v214.0.0)
 *
 * Validates source file quality, service instantiation, public API surface,
 * DTO structure, ServiceProvider registration, config sections, command
 * registration, and version consistency.
 *
 * @since 214.0.0
 */
final class Phase52AnomalyResilienceTest extends TestCase
{
    // ── Source File Existence ────────────────────────────────────────────

    public function test_anomaly_detection_service_file_exists(): void
    {
        $path = __DIR__ . '/../src/Services/EventVolumeAnomalyDetectionService.php';
        $this->assertFileExists($path);
    }

    public function test_provider_resilience_service_file_exists(): void
    {
        $path = __DIR__ . '/../src/Services/ProviderResilienceService.php';
        $this->assertFileExists($path);
    }

    public function test_resilience_command_file_exists(): void
    {
        $path = __DIR__ . '/../src/Console/Commands/AnalyticsResilienceCommand.php';
        $this->assertFileExists($path);
    }

    // ── File Quality Checks ─────────────────────────────────────────────

    public function test_anomaly_service_declares_strict_types(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/EventVolumeAnomalyDetectionService.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    public function test_resilience_service_declares_strict_types(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/ProviderResilienceService.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    public function test_resilience_command_declares_strict_types(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsResilienceCommand.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
    }

    public function test_anomaly_service_has_docblock(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/EventVolumeAnomalyDetectionService.php');
        $this->assertStringContainsString('@since 214.0.0', $content);
    }

    public function test_resilience_service_has_docblock(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/ProviderResilienceService.php');
        $this->assertStringContainsString('@since 214.0.0', $content);
    }

    public function test_resilience_command_has_docblock(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsResilienceCommand.php');
        $this->assertStringContainsString('@since 214.0.0', $content);
    }

    public function test_anomaly_service_has_mit_license_header(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/EventVolumeAnomalyDetectionService.php');
        $this->assertStringContainsString('This file is part of ZeroBoiler, licensed under the MIT license', $content);
    }

    public function test_resilience_service_has_mit_license_header(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/ProviderResilienceService.php');
        $this->assertStringContainsString('This file is part of ZeroBoiler, licensed under the MIT license', $content);
    }

    // ── Class Structure ──────────────────────────────────────────────────

    public function test_anomaly_service_is_final_class(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\EventVolumeAnomalyDetectionService::class);
        $this->assertTrue($ref->isFinal());
    }

    public function test_resilience_service_is_final_class(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\ProviderResilienceService::class);
        $this->assertTrue($ref->isFinal());
    }

    public function test_resilience_command_is_final_class(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsResilienceCommand::class);
        $this->assertTrue($ref->isFinal());
    }

    public function test_anomaly_record_is_final_readonly_class(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\AnomalyRecord::class);
        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->isReadOnly());
    }

    public function test_window_snapshot_is_final_readonly_class(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\WindowSnapshot::class);
        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->isReadOnly());
    }

    public function test_circuit_breaker_state_is_final_readonly_class(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\CircuitBreakerState::class);
        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->isReadOnly());
    }

    // ── Public API Surface ──────────────────────────────────────────────

    public function test_anomaly_service_has_record_event_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\EventVolumeAnomalyDetectionService::class);
        $method = $ref->getMethod('recordEvent');
        $this->assertTrue($method->isPublic());
        $this->assertStringContainsString('void', $method->getReturnType()->getName());
    }

    public function test_anomaly_service_has_record_batch_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\EventVolumeAnomalyDetectionService::class);
        $method = $ref->getMethod('recordBatch');
        $this->assertTrue($method->isPublic());
        $this->assertStringContainsString('void', $method->getReturnType()->getName());
    }

    public function test_anomaly_service_has_detect_anomalies_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\EventVolumeAnomalyDetectionService::class);
        $method = $ref->getMethod('detectAnomalies');
        $this->assertTrue($method->isPublic());
        $this->assertStringContainsString('array', $method->getReturnType()->getName());
    }

    public function test_anomaly_service_has_detect_anomalies_for_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\EventVolumeAnomalyDetectionService::class);
        $method = $ref->getMethod('detectAnomaliesFor');
        $this->assertTrue($method->isPublic());
    }

    public function test_anomaly_service_has_get_window_snapshot_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\EventVolumeAnomalyDetectionService::class);
        $method = $ref->getMethod('getWindowSnapshot');
        $this->assertTrue($method->isPublic());
    }

    public function test_anomaly_service_has_get_history_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\EventVolumeAnomalyDetectionService::class);
        $method = $ref->getMethod('getHistory');
        $this->assertTrue($method->isPublic());
    }

    public function test_anomaly_service_has_flush_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\EventVolumeAnomalyDetectionService::class);
        $method = $ref->getMethod('flush');
        $this->assertTrue($method->isPublic());
    }

    public function test_anomaly_service_has_is_enabled_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\EventVolumeAnomalyDetectionService::class);
        $method = $ref->getMethod('isEnabled');
        $this->assertTrue($method->isPublic());
        $this->assertStringContainsString('bool', $method->getReturnType()->getName());
    }

    public function test_anomaly_service_has_get_config_summary_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\EventVolumeAnomalyDetectionService::class);
        $method = $ref->getMethod('getConfigSummary');
        $this->assertTrue($method->isPublic());
        $this->assertStringContainsString('array', $method->getReturnType()->getName());
    }

    public function test_anomaly_service_public_method_count(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\EventVolumeAnomalyDetectionService::class);
        $publicMethods = array_filter(
            $ref->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $m): bool => ! $m->isStatic(),
        );
        $this->assertGreaterThanOrEqual(10, count($publicMethods));
    }

    public function test_resilience_service_has_record_success_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\ProviderResilienceService::class);
        $method = $ref->getMethod('recordSuccess');
        $this->assertTrue($method->isPublic());
        $this->assertStringContainsString('void', $method->getReturnType()->getName());
    }

    public function test_resilience_service_has_record_failure_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\ProviderResilienceService::class);
        $method = $ref->getMethod('recordFailure');
        $this->assertTrue($method->isPublic());
        $this->assertStringContainsString('void', $method->getReturnType()->getName());
    }

    public function test_resilience_service_has_is_available_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\ProviderResilienceService::class);
        $method = $ref->getMethod('isAvailable');
        $this->assertTrue($method->isPublic());
        $this->assertStringContainsString('bool', $method->getReturnType()->getName());
    }

    public function test_resilience_service_has_get_available_fallbacks_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\ProviderResilienceService::class);
        $method = $ref->getMethod('getAvailableFallbacks');
        $this->assertTrue($method->isPublic());
    }

    public function test_resilience_service_has_get_state_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\ProviderResilienceService::class);
        $method = $ref->getMethod('getState');
        $this->assertTrue($method->isPublic());
    }

    public function test_resilience_service_has_get_all_states_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\ProviderResilienceService::class);
        $method = $ref->getMethod('getAllStates');
        $this->assertTrue($method->isPublic());
    }

    public function test_resilience_service_has_get_status_summary_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\ProviderResilienceService::class);
        $method = $ref->getMethod('getStatusSummary');
        $this->assertTrue($method->isPublic());
    }

    public function test_resilience_service_has_reset_provider_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\ProviderResilienceService::class);
        $method = $ref->getMethod('resetProvider');
        $this->assertTrue($method->isPublic());
    }

    public function test_resilience_service_has_reset_all_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\ProviderResilienceService::class);
        $method = $ref->getMethod('resetAll');
        $this->assertTrue($method->isPublic());
    }

    public function test_resilience_service_public_method_count(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\ProviderResilienceService::class);
        $publicMethods = array_filter(
            $ref->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $m): bool => ! $m->isStatic(),
        );
        $this->assertGreaterThanOrEqual(10, count($publicMethods));
    }

    // ── DTO Structure ────────────────────────────────────────────────────

    public function test_anomaly_record_has_to_array_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\AnomalyRecord::class);
        $this->assertTrue($ref->hasMethod('toArray'));
        $this->assertTrue($ref->getMethod('toArray')->isPublic());
    }

    public function test_window_snapshot_has_to_array_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\WindowSnapshot::class);
        $this->assertTrue($ref->hasMethod('toArray'));
        $this->assertTrue($ref->getMethod('toArray')->isPublic());
    }

    public function test_circuit_breaker_state_has_to_array_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\CircuitBreakerState::class);
        $this->assertTrue($ref->hasMethod('toArray'));
        $this->assertTrue($ref->getMethod('toArray')->isPublic());
    }

    public function test_anomaly_record_properties(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\AnomalyRecord::class);
        $props = array_map(static fn ($p) => $p->getName(), $ref->getProperties());
        $this->assertContains('scope', $props);
        $this->assertContains('type', $props);
        $this->assertContains('severity', $props);
        $this->assertContains('zScore', $props);
        $this->assertContains('current', $props);
        $this->assertContains('expected', $props);
        $this->assertContains('timestamp', $props);
        $this->assertContains('message', $props);
    }

    public function test_circuit_breaker_state_properties(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\CircuitBreakerState::class);
        $props = array_map(static fn ($p) => $p->getName(), $ref->getProperties());
        $this->assertContains('status', $props);
        $this->assertContains('failureCount', $props);
        $this->assertContains('openedAt', $props);
        $this->assertContains('cooldownEnd', $props);
        $this->assertContains('cooldownLevel', $props);
        $this->assertContains('totalFailures', $props);
        $this->assertContains('totalSuccesses', $props);
    }

    // ── Command Structure ───────────────────────────────────────────────

    public function test_resilience_command_has_handle_method(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsResilienceCommand::class);
        $this->assertTrue($ref->hasMethod('handle'));
        $method = $ref->getMethod('handle');
        $this->assertTrue($method->isPublic());
        $this->assertStringContainsString('int', $method->getReturnType()->getName());
    }

    public function test_resilience_command_has_override_attribute(): void
    {
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsResilienceCommand::class);
        $attrs = $ref->getMethod('handle')->getAttributes();
        $attrNames = array_map(static fn ($a) => $a->getName(), $attrs);
        $this->assertContains('Override', $attrNames);
    }

    public function test_resilience_command_has_correct_signature(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsResilienceCommand.php');
        $this->assertStringContainsString('zb:analytics:resilience', $content);
        $this->assertStringContainsString('Monitor provider resilience', $content);
    }

    public function test_resilience_command_has_all_option_flags(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsResilienceCommand.php');
        $this->assertStringContainsString('--anomaly', $content);
        $this->assertStringContainsString('--snapshot=', $content);
        $this->assertStringContainsString('--history', $content);
        $this->assertStringContainsString('--reset=', $content);
        $this->assertStringContainsString('--reset-all', $content);
        $this->assertStringContainsString('--json', $content);
    }

    // ── Config Section ───────────────────────────────────────────────────

    public function test_config_has_anomaly_detection_section(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $this->assertArrayHasKey('anomaly_detection', $config['analytics']);
        $anomaly = $config['analytics']['anomaly_detection'];
        $this->assertArrayHasKey('enabled', $anomaly);
        $this->assertArrayHasKey('window_size', $anomaly);
        $this->assertArrayHasKey('bucket_interval', $anomaly);
        $this->assertArrayHasKey('zscore_threshold', $anomaly);
        $this->assertArrayHasKey('min_data_points', $anomaly);
        $this->assertArrayHasKey('cache_ttl', $anomaly);
        $this->assertArrayHasKey('log_anomalies', $anomaly);
    }

    public function test_config_has_provider_resilience_section(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $this->assertArrayHasKey('provider_resilience', $config['analytics']);
        $resilience = $config['analytics']['provider_resilience'];
        $this->assertArrayHasKey('enabled', $resilience);
        $this->assertArrayHasKey('failure_threshold', $resilience);
        $this->assertArrayHasKey('base_cooldown', $resilience);
        $this->assertArrayHasKey('cooldown_multiplier', $resilience);
        $this->assertArrayHasKey('max_cooldown', $resilience);
        $this->assertArrayHasKey('failure_window', $resilience);
        $this->assertArrayHasKey('log_failures', $resilience);
        $this->assertArrayHasKey('cache_ttl', $resilience);
        $this->assertArrayHasKey('fallback_chains', $resilience);
    }

    // ── ServiceProvider Registration ─────────────────────────────────────

    public function test_service_provider_references_anomaly_service(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('EventVolumeAnomalyDetectionService', $content);
    }

    public function test_service_provider_references_resilience_service(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('ProviderResilienceService', $content);
    }

    public function test_service_provider_references_resilience_command(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('AnalyticsResilienceCommand', $content);
    }

    public function test_service_provider_registers_anomaly_singleton(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('singleton(EventVolumeAnomalyDetectionService::class', $content);
    }

    public function test_service_provider_registers_resilience_singleton(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('singleton(ProviderResilienceService::class', $content);
    }

    public function test_service_provider_registers_command_in_list(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('AnalyticsResilienceCommand::class,', $content);
    }

    // ── Version Consistency ─────────────────────────────────────────────

    public function test_version_consistency_across_files(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        $package = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);
        $ref = new ReflectionClass(\ZeroBoiler\Analytics\DTO\AnalyticsEvent::class);
        $version = $ref->getConstant('VERSION');

        $this->assertSame('214.0.0', $version);
        $this->assertSame('214.0.0', $composer['version']);
        $this->assertSame('214.0.0', $package['version']);
    }

    public function test_readme_badge_version(): void
    {
        $readme = file_get_contents(__DIR__ . '/../README.md');
        $this->assertStringContainsString('version-214.0.0-blue', $readme);
    }

    public function test_readme_has_v214_whats_new(): void
    {
        $readme = file_get_contents(__DIR__ . '/../README.md');
        $this->assertStringContainsString('What\'s New in v214.0.0', $readme);
        $this->assertStringContainsString('EventVolumeAnomalyDetectionService', $readme);
        $this->assertStringContainsString('ProviderResilienceService', $readme);
        $this->assertStringContainsString('AnalyticsResilienceCommand', $readme);
        $this->assertStringContainsString('CircuitBreakerState', $readme);
        $this->assertStringContainsString('anomaly_detection', $readme);
        $this->assertStringContainsString('provider_resilience', $readme);
    }

    // ── Source File Count Baselines ──────────────────────────────────────

    public function test_source_file_count_baseline(): void
    {
        $srcFiles = glob(__DIR__ . '/../src/**/*.php');
        $this->assertGreaterThanOrEqual(908, count($srcFiles));
    }

    public function test_service_count_baseline(): void
    {
        $serviceFiles = glob(__DIR__ . '/../src/Services/*.php');
        $this->assertGreaterThanOrEqual(419, count($serviceFiles));
    }

    public function test_command_count_baseline(): void
    {
        $commandFiles = glob(__DIR__ . '/../src/Console/Commands/*.php');
        $this->assertGreaterThanOrEqual(98, count($commandFiles));
    }

    public function test_test_count_baseline(): void
    {
        $testFiles = glob(__DIR__ . '/*.php');
        $this->assertGreaterThanOrEqual(464, count($testFiles));
    }
}
