<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests\Feature\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Services\AnalyticsAnonymizationService;
use Mockery;
use Mockery\MockInterface;

/**
 * @covers \ZeroBoiler\Analytics\Services\AnalyticsAnonymizationService
 */
final class AnalyticsAnonymizationServiceTest extends \PHPUnit\Framework\TestCase
{
    private ConfigRepository&MockInterface $config;

    private AnalyticsAnonymizationService $service;

    protected function setUp(): void
    {
        $this->config = Mockery::mock(ConfigRepository::class);

        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.anonymization', [])
            ->andReturn([
                'enabled' => true,
                'salt' => 'test_salt',
                'global_fields' => ['email', 'phone', 'ip_address', 'user_agent', 'full_name', 'credit_card'],
                'event_rules' => [],
                'category_rules' => [],
            ]);

        $this->service = new AnalyticsAnonymizationService($this->config);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testAnonymizeIdReturnsConsistentHash(): void
    {
        $id1 = $this->service->anonymizeId('user_12345');
        $id2 = $this->service->anonymizeId('user_12345');

        $this->assertSame($id1, $id2);
        $this->assertStringStartsWith('anon_', $id1);
        $this->assertSame(21, strlen($id1)); // 'anon_' (5) + 16 hex chars
    }

    public function testAnonymizeIdProducesDifferentHashes(): void
    {
        $id1 = $this->service->anonymizeId('user_12345');
        $id2 = $this->service->anonymizeId('user_67890');

        $this->assertNotSame($id1, $id2);
    }

    public function testAnonymizeIdHandlesEmptyString(): void
    {
        $result = $this->service->anonymizeId('');

        $this->assertSame('anon_0000000000000000', $result);
    }

    public function testMaskValuePreservesPrefix(): void
    {
        $result = $this->service->maskValue('john@example.com', 3);

        $this->assertSame('joh*********************', $result);
    }

    public function testMaskValueShortValue(): void
    {
        $result = $this->service->maskValue('ab', 3);

        $this->assertSame('ab', $result);
    }

    public function testAnonymizeFieldValueEmail(): void
    {
        $result = $this->service->anonymizeFieldValue('john.doe@example.com');

        $this->assertSame('jo*****************@example.com', $result);
    }

    public function testAnonymizeFieldValuePhone(): void
    {
        $result = $this->service->anonymizeFieldValue('+1 555 123 4567');

        // Phone digits are extracted: 15551234567 (11 digits)
        // Should mask middle: first 2 + stars + last 2
        $this->assertStringContainsString('*', $result);
        $this->assertStringNotContainsString('5551234567', $result);
    }

    public function testAnonymizeFieldValueIpAddress(): void
    {
        $result = $this->service->anonymizeFieldValue('192.168.1.100');

        $this->assertSame('192.168.1.0', $result);
    }

    public function testAnonymizeFieldValueUuid(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $result = $this->service->anonymizeFieldValue($uuid);

        // UUIDs should be deterministically anonymized
        $this->assertStringStartsWith('anon_', $result);
    }

    public function testAnonymizeFieldValueGeneralString(): void
    {
        $result = $this->service->anonymizeFieldValue('Hello World');

        $this->assertSame('Hel********', $result);
    }

    public function testAnonymizeParamsAnonymizesMatchingFields(): void
    {
        $params = [
            'email' => 'john@example.com',
            'action' => 'click',
            'ip_address' => '192.168.1.100',
            'count' => 42,
        ];

        $result = $this->service->anonymizeParams($params, 'sign_up');

        $this->assertStringContainsString('***', $result['email']);
        $this->assertSame('click', $result['action']);
        $this->assertSame('192.168.1.0', $result['ip_address']);
        $this->assertSame(42, $result['count']);
    }

    public function testAnonymizeParamsSkipsWhenDisabled(): void
    {
        $this->config->shouldReceive('get')
            ->andReturn(['enabled' => false]);

        $disabledService = new AnalyticsAnonymizationService($this->config);

        $params = [
            'email' => 'john@example.com',
            'phone' => '555-1234',
        ];

        $result = $disabledService->anonymizeParams($params, 'sign_up');

        $this->assertSame('john@example.com', $result['email']);
        $this->assertSame('555-1234', $result['phone']);
    }

    public function testAnonymizeParamsSkipsNonStringValues(): void
    {
        $params = [
            'email' => 'john@example.com',
            'count' => 42,
            'active' => true,
            'tags' => ['a', 'b'],
        ];

        $result = $this->service->anonymizeParams($params, 'sign_up');

        $this->assertStringContainsString('***', $result['email']);
        $this->assertSame(42, $result['count']);
        $this->assertTrue($result['active']);
        $this->assertSame(['a', 'b'], $result['tags']);
    }

    public function testAnonymizeParamsWithEventRules(): void
    {
        $this->config->shouldReceive('get')
            ->andReturn([
                'enabled' => true,
                'salt' => 'test_salt',
                'global_fields' => ['email'],
                'event_rules' => [
                    'custom_event' => ['secret_field'],
                ],
                'category_rules' => [],
            ]);

        $eventService = new AnalyticsAnonymizationService($this->config);

        $params = [
            'email' => 'john@example.com',
            'secret_field' => 'hidden_value',
            'public_field' => 'visible_value',
        ];

        $result = $eventService->anonymizeParams($params, 'custom_event');

        $this->assertStringContainsString('***', $result['email']);
        $this->assertStringContainsString('***', $result['secret_field']);
        $this->assertSame('visible_value', $result['public_field']);
    }

    public function testAnonymizeParamsWithCategoryRules(): void
    {
        $this->config->shouldReceive('get')
            ->andReturn([
                'enabled' => true,
                'salt' => 'test_salt',
                'global_fields' => [],
                'event_rules' => [],
                'category_rules' => [
                    'saas' => ['email', 'name'],
                ],
            ]);

        $categoryService = new AnalyticsAnonymizationService($this->config);

        $params = [
            'email' => 'john@example.com',
            'name' => 'John Doe',
            'feature' => 'export',
        ];

        $result = $categoryService->anonymizeParams($params, 'sign_up', 'saas');

        $this->assertStringContainsString('***', $result['email']);
        $this->assertStringContainsString('***', $result['name']);
        $this->assertSame('export', $result['feature']);
    }

    public function testAuditTrailReturnsAffectedFields(): void
    {
        $params = [
            'email' => 'john@example.com',
            'action' => 'click',
            'ip_address' => '192.168.1.100',
        ];

        $trail = $this->service->auditTrail($params, 'sign_up');

        $this->assertCount(2, $trail);
        $this->assertSame('email', $trail[0]['field']);
        $this->assertSame('john@example.com', $trail[0]['original']);
        $this->assertStringContainsString('***', $trail[0]['anonymized']);
        $this->assertSame('ip_address', $trail[1]['field']);
    }

    public function testAuditTrailEmptyWhenNoMatchingFields(): void
    {
        $params = [
            'action' => 'click',
            'count' => 5,
        ];

        $trail = $this->service->auditTrail($params, 'sign_up');

        $this->assertEmpty($trail);
    }

    public function testIsEnabledReturnsTrue(): void
    {
        $this->assertTrue($this->service->isEnabled());
    }

    public function testGetGlobalFields(): void
    {
        $fields = $this->service->getGlobalFields();

        $this->assertContains('email', $fields);
        $this->assertContains('phone', $fields);
        $this->assertContains('ip_address', $fields);
    }

    public function testDifferentSaltProducesDifferentHash(): void
    {
        $result1 = $this->service->anonymizeId('user_1');

        $this->config->shouldReceive('get')
            ->andReturn([
                'enabled' => true,
                'salt' => 'different_salt',
                'global_fields' => [],
                'event_rules' => [],
                'category_rules' => [],
            ]);

        $differentSaltService = new AnalyticsAnonymizationService($this->config);
        $result2 = $differentSaltService->anonymizeId('user_1');

        $this->assertNotSame($result1, $result2);
    }
}
