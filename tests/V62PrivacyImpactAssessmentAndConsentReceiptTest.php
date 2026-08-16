<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Services\PrivacyImpactAssessmentService;
use ZeroBoiler\Analytics\Services\ConsentReceiptRegistry;

beforeEach(function (): void {
    $this->cache = Mockery::mock(CacheRepository::class);
    $this->config = Mockery::mock(ConfigRepository::class);
});

afterEach(function (): void {
    Mockery::close();
});

describe('PrivacyImpactAssessmentService', function (): void {
    beforeEach(function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.privacy_impact_assessment', [])
            ->andReturn([
                'enabled' => true,
                'cache_ttl' => 86400,
                'auto_assess' => true,
                'required_for_new_events' => false,
                'reviewer_email' => 'reviewer@example.com',
                'dpo_email' => 'dpo@example.com',
                'assessment_frequency_days' => 365,
                'high_risk_categories' => ['security', 'ecommerce'],
                'processing_purposes' => ['analytics', 'improvement', 'security'],
                'retention_review_days' => 90,
                'cross_border_transfers' => ['US', 'EU'],
            ]);

        $this->pia = new PrivacyImpactAssessmentService($this->cache, $this->config);
    });

    test('constructs with correct defaults', function (): void {
        expect($this->pia->isEnabled())->toBeTrue();
    });

    test('assessEvent returns valid assessment structure for engagement event', function (): void {
        $this->cache->shouldReceive('put')->once();

        $result = $this->pia->assessEvent('page_view');

        expect($result)->toHaveKeys([
            'id', 'event', 'timestamp', 'overall_risk', 'overall_score',
            'triggers_dpia', 'sections', 'recommendations', 'mitigations',
            'review_due', 'reviewer_email', 'dpo_email',
        ]);

        expect($result['event'])->toBe('page_view');
        expect($result['overall_score'])->toBeInt();
        expect($result['overall_score'])->toBeGreaterThanOrEqual(0);
        expect($result['overall_score'])->toBeLessThanOrEqual(100);
        expect($result['overall_risk'])->toBeIn(['none', 'low', 'medium', 'high', 'critical']);
        expect($result['triggers_dpia'])->toBeBool();
    });

    test('assessEvent returns valid assessment for ecommerce event', function (): void {
        $this->cache->shouldReceive('put')->once();

        $result = $this->pia->assessEvent('purchase');

        expect($result['event'])->toBe('purchase');
        expect($result['sections'])->toHaveKey('sensitivity');
        expect($result['sections'])->toHaveKey('operations');
        expect($result['sections'])->toHaveKey('legal_basis');
        expect($result['sections']['sensitivity']['data_categories'])->toContain('financial');
    });

    test('assessEvent returns valid assessment for security event', function (): void {
        $this->cache->shouldReceive('put')->once();

        $result = $this->pia->assessEvent('login_attempt');

        expect($result['event'])->toBe('login_attempt');
        expect($result['sections']['sensitivity']['pii_risk'])->toBe('high');
        expect($result['triggers_dpia'])->toBeTrue(); // security is in high_risk_categories
    });

    test('assessEvent caches results', function (): void {
        $this->cache->shouldReceive('put')
            ->once()
            ->withArgs(fn (string $key, array $assessment): bool =>
                str_contains($key, 'zb_pia_') && str_contains($key, 'click')
            );

        $this->pia->assessEvent('click');
    });

    test('assessSystemWide returns valid structure', function (): void {
        // Allow cache puts for each event assessment
        $this->cache->shouldReceive('put')->zeroOrMoreTimes();

        $result = $this->pia->assessSystemWide();

        expect($result)->toHaveKeys([
            'assessed_at', 'total_events', 'by_risk', 'high_risk_events',
            'overall_compliance_score', 'recommendations', 'requires_dpa_review',
        ]);

        expect($result['total_events'])->toBeGreaterThan(0);
        expect($result['by_risk'])->toHaveKeys(['none', 'low', 'medium', 'high', 'critical']);
        expect($result['overall_compliance_score'])->toBeFloat();
        expect($result['requires_dpa_review'])->toBeBool();
    });

    test('requiresDpia returns true for security events', function (): void {
        expect($this->pia->requiresDpia('login_attempt'))->toBeTrue();
    });

    test('requiresDpia returns true for ecommerce events', function (): void {
        expect($this->pia->requiresDpia('purchase'))->toBeTrue();
    });

    test('getTriggerCriteria returns valid structure', function (): void {
        $criteria = $this->pia->getTriggerCriteria();

        expect($criteria)->toHaveKeys(['event_categories', 'score_threshold', 'criteria']);
        expect($criteria['event_categories'])->toContain('security');
        expect($criteria['event_categories'])->toContain('ecommerce');
        expect($criteria['score_threshold'])->toBe(70);
        expect($criteria['criteria'])->toBeArray();
    });

    test('summaryReport returns valid structure', function (): void {
        $this->cache->shouldReceive('get')
            ->with(Mockery::pattern('/^zb_pia_/'))
            ->zeroOrMoreTimes()
            ->andReturnNull();

        $report = $this->pia->summaryReport();

        expect($report)->toHaveKeys([
            'enabled', 'total_assessments', 'high_risk_count',
            'compliance_score', 'last_assessed', 'review_due', 'dpo_contact',
        ]);

        expect($report['enabled'])->toBeTrue();
        expect($report['dpo_contact'])->toBe('dpo@example.com');
    });

    test('assessment sensitivity detects PII risk in authentication events', function (): void {
        $this->cache->shouldReceive('put')->once();

        $result = $this->pia->assessEvent('sign_up');

        $sensitivity = $result['sections']['sensitivity'];
        expect($sensitivity['pii_risk'])->toBe('high');
        expect($sensitivity['data_categories'])->toContain('identifier');
    });

    test('assessment sensitivity detects PII risk in payment events', function (): void {
        $this->cache->shouldReceive('put')->once();

        $result = $this->pia->assessEvent('payment_failed');

        $sensitivity = $result['sections']['sensitivity'];
        expect($sensitivity['pii_risk'])->toBe('high');
        expect($sensitivity['data_categories'])->toContain('financial');
    });

    test('assessment operations include profile for cohort events', function (): void {
        $this->cache->shouldReceive('put')->once();

        $result = $this->pia->assessEvent('cohort_assigned');

        $operations = $result['sections']['operations'];
        $opNames = array_column($operations['operations'], 'operation');
        expect($opNames)->toContain('profile');
        expect($opNames)->toContain('automated_decision');
    });
});

describe('ConsentReceiptRegistry', function (): void {
    beforeEach(function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.consent_receipt', [])
            ->andReturn([
                'enabled' => true,
                'cache_ttl' => 7776000,
                'retention_days' => 2555,
                'include_hash_chain' => true,
                'include_ip' => true,
                'include_user_agent' => false,
                'require_auth' => false,
                'max_receipts_per_client' => 100,
                'auto_record_consent_changes' => true,
                'purposes' => ['analytics_storage', 'ad_storage', 'ad_user_data', 'ad_personalization', 'functionality_storage', 'personalization_storage'],
            ]);

        $this->registry = new ConsentReceiptRegistry($this->cache, $this->config);
    });

    test('constructs with correct defaults', function (): void {
        expect($this->registry->isEnabled())->toBeTrue();
    });

    test('getConfig returns all config keys', function (): void {
        $config = $this->registry->getConfig();

        expect($config)->toHaveKeys([
            'enabled', 'cache_ttl', 'retention_days', 'include_hash_chain',
            'include_ip', 'max_receipts_per_client', 'purposes',
        ]);

        expect($config['retention_days'])->toBe(2555);
        expect($config['purposes'])->toContain('analytics_storage');
    });

    test('record creates valid receipt structure', function (): void {
        // Mock chain head
        $this->cache->shouldReceive('get')
            ->with('zb_cr_chain_head_test-client')
            ->once()
            ->andReturnNull();

        // Mock existing indices
        $this->cache->shouldReceive('get')
            ->with('zb_cr_index')
            ->once()
            ->andReturn([]);

        // Mock storing the receipt
        $this->cache->shouldReceive('put')
            ->withArgs(fn (string $key, mixed $value): bool => str_starts_with($key, 'zb_cr_'))
            ->zeroOrMoreTimes();

        $receipt = $this->registry->record(
            'test-client',
            ['analytics_storage' => 'granted', 'ad_storage' => 'denied'],
            'grant',
            ['ip' => '192.168.1.1'],
        );

        expect($receipt)->toHaveKeys([
            'id', 'timestamp', 'client_id', 'action', 'consent_state',
            'purposes', 'previous_hash', 'receipt_hash',
        ]);

        expect($receipt['client_id'])->toBe('test-client');
        expect($receipt['action'])->toBe('grant');
        expect($receipt['consent_state']['analytics_storage'])->toBe('granted');
        expect($receipt['consent_state']['ad_storage'])->toBe('denied');
        expect($receipt['purposes'])->toContain('analytics_storage');
        expect($receipt['ip'])->toBe('192.168.1.1');
        expect($receipt['receipt_hash'])->toBeString();
        expect(strlen($receipt['receipt_hash']))->toBe(64); // SHA-256
    });

    test('record normalizes invalid consent values', function (): void {
        $this->cache->shouldReceive('get')
            ->with('zb_cr_chain_head_test-client')
            ->once()
            ->andReturnNull();
        $this->cache->shouldReceive('get')
            ->with('zb_cr_index')
            ->once()
            ->andReturn([]);
        $this->cache->shouldReceive('put')->zeroOrMoreTimes();

        $receipt = $this->registry->record(
            'test-client',
            ['analytics_storage' => 'yes', 'ad_storage' => 'maybe', 'functionality_storage' => 'granted'],
            'update',
        );

        expect($receipt['consent_state']['analytics_storage'])->toBe('denied');
        expect($receipt['consent_state']['ad_storage'])->toBe('denied');
        expect($receipt['consent_state']['functionality_storage'])->toBe('granted');
    });

    test('record includes user_id from metadata', function (): void {
        $this->cache->shouldReceive('get')
            ->with('zb_cr_chain_head_test-client')
            ->once()
            ->andReturnNull();
        $this->cache->shouldReceive('get')
            ->with('zb_cr_index')
            ->once()
            ->andReturn([]);
        $this->cache->shouldReceive('put')->zeroOrMoreTimes();

        $receipt = $this->registry->record(
            'test-client',
            ['analytics_storage' => 'granted'],
            'grant',
            ['user_id' => 42],
        );

        expect($receipt['user_id'])->toBe('42');
    });

    test('record validates action types', function (): void {
        $this->cache->shouldReceive('get')
            ->with('zb_cr_chain_head_test-client')
            ->once()
            ->andReturnNull();
        $this->cache->shouldReceive('get')
            ->with('zb_cr_index')
            ->once()
            ->andReturn([]);
        $this->cache->shouldReceive('put')->zeroOrMoreTimes();

        $receipt = $this->registry->record(
            'test-client',
            ['analytics_storage' => 'granted'],
            'invalid_action',
        );

        expect($receipt['action'])->toBe('update');
    });

    test('getHistory returns empty array when no receipts exist', function (): void {
        $this->cache->shouldReceive('get')
            ->with('zb_cr_index')
            ->once()
            ->andReturn([]);

        expect($this->registry->getHistory('unknown-client'))->toBe([]);
    });

    test('getLatest returns null when no receipts exist', function (): void {
        $this->cache->shouldReceive('get')
            ->with('zb_cr_index')
            ->once()
            ->andReturn([]);

        expect($this->registry->getLatest('unknown-client'))->toBeNull();
    });

    test('verifyChain returns valid for empty chain', function (): void {
        $this->cache->shouldReceive('get')
            ->with('zb_cr_index')
            ->once()
            ->andReturn([]);

        $result = $this->registry->verifyChain('unknown-client');

        expect($result['valid'])->toBeTrue();
        expect($result['total_receipts'])->toBe(0);
        expect($result['issues'])->toBe([]);
    });

    test('metrics returns valid structure', function (): void {
        $this->cache->shouldReceive('get')
            ->with('zb_cr_index')
            ->once()
            ->andReturn([]);

        $metrics = $this->registry->metrics();

        expect($metrics)->toHaveKeys([
            'total_clients', 'total_receipts', 'by_action', 'by_purpose',
            'average_receipts_per_client', 'retention_compliance',
        ]);

        expect($metrics['total_clients'])->toBe(0);
        expect($metrics['retention_compliance']['retention_days'])->toBe(2555);
    });

    test('exportForAudit returns valid export structure', function (): void {
        $this->cache->shouldReceive('get')
            ->with('zb_cr_index')
            ->once()
            ->andReturn([]);

        $export = $this->registry->exportForAudit('test-client');

        expect($export)->toHaveKeys([
            'client_id', 'exported_at', 'receipt_count',
            'chain_verification', 'receipts',
        ]);

        expect($export['client_id'])->toBe('test-client');
        expect($export['receipts'])->toBe([]);
    });
});
