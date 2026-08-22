<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\OutboundWebhookRelay;
use ZeroBoiler\Analytics\Services\SLOService;

/**
 * Tests for SLO Service and Outbound Webhook Relay (v157.0.0).
 *
 * @covers \ZeroBoiler\Analytics\Services\SLOService
 * @covers \ZeroBoiler\Analytics\Services\OutboundWebhookRelay
 * @covers \ZeroBoiler\Analytics\Console\Commands\AnalyticsSLOCommand
 * @covers \ZeroBoiler\Analytics\Console\Commands\AnalyticsWebhookRelayCommand
 *
 * @since 157.0.0
 */
final class V157SLOWebhookRelayTest extends TestCase
{
    // ─── SLOService Structural Tests ───────────────────────────────────

    public function test_slo_service_is_final(): void
    {
        $reflection = new \ReflectionClass(SLOService::class);

        $this->assertTrue($reflection->isFinal());
    }

    public function test_slo_service_has_strict_types(): void
    {
        $file = file_get_contents((new \ReflectionClass(SLOService::class))->getFileName());

        $this->assertStringContainsString('declare(strict_types=1)', $file);
    }

    public function test_slo_service_constructor_has_void_return(): void
    {
        $constructor = new \ReflectionMethod(SLOService::class, '__construct');

        $this->assertEmpty($constructor->getReturnType()?->getName());
        $this->assertSame('void', (string) $constructor->getReturnType());
    }

    public function test_slo_service_has_docblock_since_tag(): void
    {
        $doc = (new \ReflectionClass(SLOService::class))->getDocComment();

        $this->assertNotFalse($doc);
        $this->assertStringContainsString('@since 157.0.0', $doc);
    }

    public function test_slo_service_record_success_has_correct_signature(): void
    {
        $method = new \ReflectionMethod(SLOService::class, 'recordSuccess');

        $this->assertTrue($method->isPublic());
        $this->assertSame('void', (string) $method->getReturnType());

        $params = $method->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('objective', $params[0]->getName());
        $this->assertSame('string', (string) $params[0]->getType());
        $this->assertSame('provider', $params[1]->getName());
        $this->assertSame('?string', (string) $params[1]->getType());
    }

    public function test_slo_service_record_error_has_correct_signature(): void
    {
        $method = new \ReflectionMethod(SLOService::class, 'recordError');

        $this->assertTrue($method->isPublic());
        $this->assertSame('void', (string) $method->getReturnType());

        $params = $method->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame('objective', $params[0]->getName());
        $this->assertSame('provider', $params[1]->getName());
        $this->assertSame('reason', $params[2]->getName());
    }

    public function test_slo_service_get_error_budget_returns_array(): void
    {
        $method = new \ReflectionMethod(SLOService::class, 'getErrorBudget');

        $this->assertSame('array', (string) $method->getReturnType());
    }

    public function test_slo_service_get_compliance_returns_float(): void
    {
        $method = new \ReflectionMethod(SLOService::class, 'getCompliance');

        $this->assertSame('float', (string) $method->getReturnType());
    }

    public function test_slo_service_calculate_burn_rate_returns_float(): void
    {
        $method = new \ReflectionMethod(SLOService::class, 'calculateBurnRate');

        $this->assertSame('float', (string) $method->getReturnType());
    }

    public function test_slo_service_project_budget_returns_array(): void
    {
        $method = new \ReflectionMethod(SLOService::class, 'projectBudget');

        $this->assertSame('array', (string) $method->getReturnType());
    }

    public function test_slo_service_check_burn_rate_threshold_returns_array(): void
    {
        $method = new \ReflectionMethod(SLOService::class, 'checkBurnRateThreshold');

        $this->assertSame('array', (string) $method->getReturnType());
    }

    public function test_slo_service_dashboard_returns_array(): void
    {
        $method = new \ReflectionMethod(SLOService::class, 'dashboard');

        $this->assertSame('array', (string) $method->getReturnType());
    }

    public function test_slo_service_record_compliance_history_is_public_void(): void
    {
        $method = new \ReflectionMethod(SLOService::class, 'recordComplianceHistory');

        $this->assertTrue($method->isPublic());
        $this->assertSame('void', (string) $method->getReturnType());
    }

    public function test_slo_service_get_compliance_history_returns_array(): void
    {
        $method = new \ReflectionMethod(SLOService::class, 'getComplianceHistory');

        $this->assertSame('array', (string) $method->getReturnType());
    }

    public function test_slo_service_rolling_compliance_returns_float(): void
    {
        $method = new \ReflectionMethod(SLOService::class, 'rollingCompliance');

        $this->assertSame('float', (string) $method->getReturnType());
    }

    public function test_slo_service_reset_is_public_void(): void
    {
        $method = new \ReflectionMethod(SLOService::class, 'reset');

        $this->assertTrue($method->isPublic());
        $this->assertSame('void', (string) $method->getReturnType());
    }

    public function test_slo_service_is_enabled_returns_bool(): void
    {
        $method = new \ReflectionMethod(SLOService::class, 'isEnabled');

        $this->assertSame('bool', (string) $method->getReturnType());
    }

    public function test_slo_service_get_target_returns_float(): void
    {
        $method = new \ReflectionMethod(SLOService::class, 'getTarget');

        $this->assertSame('float', (string) $method->getReturnType());
    }

    public function test_slo_service_get_all_objective_names_returns_array(): void
    {
        $method = new \ReflectionMethod(SLOService::class, 'getAllObjectiveNames');

        $this->assertSame('array', (string) $method->getReturnType());
    }

    public function test_slo_service_has_required_imports(): void
    {
        $file = file_get_contents((new \ReflectionClass(SLOService::class))->getFileName());

        $this->assertStringContainsString('use Illuminate\Contracts\Cache\Repository as CacheRepository', $file);
        $this->assertStringContainsString('use Illuminate\Contracts\Config\Repository as ConfigRepository', $file);
    }

    public function test_slo_service_namespace_is_correct(): void
    {
        $this->assertSame('ZeroBoiler\\Analytics\\Services', (new \ReflectionClass(SLOService::class))->getNamespaceName());
    }

    // ─── OutboundWebhookRelay Structural Tests ──────────────────────────

    public function test_webhook_relay_is_final(): void
    {
        $reflection = new \ReflectionClass(OutboundWebhookRelay::class);

        $this->assertTrue($reflection->isFinal());
    }

    public function test_webhook_relay_has_strict_types(): void
    {
        $file = file_get_contents((new \ReflectionClass(OutboundWebhookRelay::class))->getFileName());

        $this->assertStringContainsString('declare(strict_types=1)', $file);
    }

    public function test_webhook_relay_has_docblock_since_tag(): void
    {
        $doc = (new \ReflectionClass(OutboundWebhookRelay::class))->getDocComment();

        $this->assertNotFalse($doc);
        $this->assertStringContainsString('@since 157.0.0', $doc);
    }

    public function test_webhook_relay_relay_is_public_void(): void
    {
        $method = new \ReflectionMethod(OutboundWebhookRelay::class, 'relay');

        $this->assertTrue($method->isPublic());
        $this->assertSame('void', (string) $method->getReturnType());

        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('event', $params[0]->getName());
        $this->assertSame(AnalyticsEvent::class, (string) $params[0]->getType());
    }

    public function test_webhook_relay_relay_batch_is_public_void(): void
    {
        $method = new \ReflectionMethod(OutboundWebhookRelay::class, 'relayBatch');

        $this->assertTrue($method->isPublic());
        $this->assertSame('void', (string) $method->getReturnType());
    }

    public function test_webhook_relay_stats_returns_array(): void
    {
        $method = new \ReflectionMethod(OutboundWebhookRelay::class, 'stats');

        $this->assertSame('array', (string) $method->getReturnType());
    }

    public function test_webhook_relay_delivery_log_returns_array(): void
    {
        $method = new \ReflectionMethod(OutboundWebhookRelay::class, 'deliveryLog');

        $this->assertSame('array', (string) $method->getReturnType());
    }

    public function test_webhook_relay_clear_delivery_log_is_public_void(): void
    {
        $method = new \ReflectionMethod(OutboundWebhookRelay::class, 'clearDeliveryLog');

        $this->assertTrue($method->isPublic());
        $this->assertSame('void', (string) $method->getReturnType());
    }

    public function test_webhook_relay_reset_rate_limit_is_public_void(): void
    {
        $method = new \ReflectionMethod(OutboundWebhookRelay::class, 'resetRateLimit');

        $this->assertTrue($method->isPublic());
        $this->assertSame('void', (string) $method->getReturnType());
    }

    public function test_webhook_relay_test_destination_returns_array(): void
    {
        $method = new \ReflectionMethod(OutboundWebhookRelay::class, 'testDestination');

        $this->assertSame('array', (string) $method->getReturnType());
    }

    public function test_webhook_relay_get_destination_names_returns_array(): void
    {
        $method = new \ReflectionMethod(OutboundWebhookRelay::class, 'getDestinationNames');

        $this->assertSame('array', (string) $method->getReturnType());
    }

    public function test_webhook_relay_is_destination_configured_returns_bool(): void
    {
        $method = new \ReflectionMethod(OutboundWebhookRelay::class, 'isDestinationConfigured');

        $this->assertSame('bool', (string) $method->getReturnType());
    }

    public function test_webhook_relay_is_enabled_returns_bool(): void
    {
        $method = new \ReflectionMethod(OutboundWebhookRelay::class, 'isEnabled');

        $this->assertSame('bool', (string) $method->getReturnType());
    }

    public function test_webhook_relay_has_required_imports(): void
    {
        $file = file_get_contents((new \ReflectionClass(OutboundWebhookRelay::class))->getFileName());

        $this->assertStringContainsString('use ZeroBoiler\\Analytics\\DTO\\AnalyticsEvent', $file);
        $this->assertStringContainsString('use Illuminate\\Support\\Facades\\Http', $file);
    }

    // ─── AnalyticsSLOCommand Structural Tests ───────────────────────────

    public function test_slo_command_is_final(): void
    {
        $reflection = new \ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsSLOCommand::class);

        $this->assertTrue($reflection->isFinal());
    }

    public function test_slo_command_has_strict_types(): void
    {
        $file = file_get_contents((new \ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsSLOCommand::class))->getFileName());

        $this->assertStringContainsString('declare(strict_types=1)', $file);
    }

    public function test_slo_command_has_override_attribute(): void
    {
        $method = new \ReflectionMethod(\ZeroBoiler\Analytics\Console\Commands\AnalyticsSLOCommand::class, 'handle');

        $attributes = $method->getAttributes();

        $this->assertCount(1, $attributes);
        $this->assertSame(\Override::class, $attributes[0]->getName());
    }

    public function test_slo_command_handle_returns_int(): void
    {
        $method = new \ReflectionMethod(\ZeroBoiler\Analytics\Console\Commands\AnalyticsSLOCommand::class, 'handle');

        $this->assertSame('int', (string) $method->getReturnType());
    }

    public function test_slo_command_constructor_accepts_slo_service(): void
    {
        $constructor = new \ReflectionMethod(\ZeroBoiler\Analytics\Console\Commands\AnalyticsSLOCommand::class, '__construct');

        $params = $constructor->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame(SLOService::class, (string) $params[0]->getType());
    }

    // ─── AnalyticsWebhookRelayCommand Structural Tests ────────────────

    public function test_webhook_relay_command_is_final(): void
    {
        $reflection = new \ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsWebhookRelayCommand::class);

        $this->assertTrue($reflection->isFinal());
    }

    public function test_webhook_relay_command_has_strict_types(): void
    {
        $file = file_get_contents((new \ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsWebhookRelayCommand::class))->getFileName());

        $this->assertStringContainsString('declare(strict_types=1)', $file);
    }

    public function test_webhook_relay_command_has_override_attribute(): void
    {
        $method = new \ReflectionMethod(\ZeroBoiler\Analytics\Console\Commands\AnalyticsWebhookRelayCommand::class, 'handle');

        $attributes = $method->getAttributes();

        $this->assertCount(1, $attributes);
        $this->assertSame(\Override::class, $attributes[0]->getName());
    }

    public function test_webhook_relay_command_handle_returns_int(): void
    {
        $method = new \ReflectionMethod(\ZeroBoiler\Analytics\Console\Commands\AnalyticsWebhookRelayCommand::class, 'handle');

        $this->assertSame('int', (string) $method->getReturnType());
    }

    public function test_webhook_relay_command_constructor_accepts_relay(): void
    {
        $constructor = new \ReflectionMethod(\ZeroBoiler\Analytics\Console\Commands\AnalyticsWebhookRelayCommand::class, '__construct');

        $params = $constructor->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame(OutboundWebhookRelay::class, (string) $params[0]->getType());
    }

    // ─── Cross-Reference Tests ──────────────────────────────────────────

    public function test_slo_service_see_references_are_valid(): void
    {
        $doc = (new \ReflectionClass(SLOService::class))->getDocComment();

        $this->assertNotFalse($doc);
        $this->assertStringContainsString('@see \\ZeroBoiler\\Analytics\\Services\\ProviderSLAMonitor', $doc);
        $this->assertStringContainsString('@see \\ZeroBoiler\\Analytics\\Services\\AlertNotificationService', $doc);
    }

    public function test_webhook_relay_see_references_are_valid(): void
    {
        $doc = (new \ReflectionClass(OutboundWebhookRelay::class))->getDocComment();

        $this->assertNotFalse($doc);
        $this->assertStringContainsString('@see \\ZeroBoiler\\Analytics\\Services\\DeadLetterQueueService', $doc);
        $this->assertStringContainsString('@see \\ZeroBoiler\\Analytics\\Services\\EventForwardingService', $doc);
    }

    public function test_new_classes_exist_in_correct_namespaces(): void
    {
        $this->assertTrue(class_exists(SLOService::class));
        $this->assertTrue(class_exists(OutboundWebhookRelay::class));
        $this->assertTrue(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsSLOCommand::class));
        $this->assertTrue(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsWebhookRelayCommand::class));
    }

    public function test_slo_service_file_has_license_header(): void
    {
        $file = file_get_contents((new \ReflectionClass(SLOService::class))->getFileName());

        $this->assertStringContainsString('This file is part of ZeroBoiler, licensed under the MIT license', $file);
    }

    public function test_webhook_relay_file_has_license_header(): void
    {
        $file = file_get_contents((new \ReflectionClass(OutboundWebhookRelay::class))->getFileName());

        $this->assertStringContainsString('This file is part of ZeroBoiler, licensed under the MIT license', $file);
    }

    public function test_slo_command_file_has_license_header(): void
    {
        $file = file_get_contents((new \ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsSLOCommand::class))->getFileName());

        $this->assertStringContainsString('This file is part of ZeroBoiler, licensed under the MIT license', $file);
    }

    public function test_webhook_relay_command_file_has_license_header(): void
    {
        $file = file_get_contents((new \ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsWebhookRelayCommand::class))->getFileName());

        $this->assertStringContainsString('This file is part of ZeroBoiler, licensed under the MIT license', $file);
    }
}
