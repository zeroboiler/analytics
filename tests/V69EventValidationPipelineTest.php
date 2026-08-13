<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Pipeline\Validation\CatalogMembershipStage;
use ZeroBoiler\Analytics\Pipeline\Validation\ComplianceValidationStage;
use ZeroBoiler\Analytics\Pipeline\Validation\DataQualityStage;
use ZeroBoiler\Analytics\Pipeline\Validation\EventValidationPipeline;
use ZeroBoiler\Analytics\Pipeline\Validation\PiiScanningStage;
use ZeroBoiler\Analytics\Pipeline\Validation\SchemaValidationStage;

describe('Event Validation Pipeline (v69.0.0)', function (): void {

    describe('EventValidationPipeline', function (): void {
        test('withDefaults creates pipeline with 5 stages', function (): void {
            $pipeline = EventValidationPipeline::withDefaults();

            expect($pipeline->count())->toBe(5);
            expect($pipeline->stageNames())->toBe([
                'catalog_membership',
                'schema_validation',
                'pii_scanning',
                'data_quality',
                'compliance',
            ]);
        });

        test('withFailFast creates pipeline with fail-fast enabled', function (): void {
            $pipeline = EventValidationPipeline::withFailFast();

            expect($pipeline->count())->toBe(5);
            expect($pipeline->summary()['fail_fast'])->toBeTrue();
        });

        test('summary returns pipeline status', function (): void {
            $pipeline = EventValidationPipeline::withDefaults();
            $summary = $pipeline->summary();

            expect($summary)->toHaveKey('stages');
            expect($summary)->toHaveKey('enabled_stages');
            expect($summary)->toHaveKey('disabled_stages');
            expect($summary)->toHaveKey('fail_fast');
            expect($summary['fail_fast'])->toBeFalse();
        });

        test('stageDescriptions returns all descriptions', function (): void {
            $pipeline = EventValidationPipeline::withDefaults();
            $descriptions = $pipeline->stageDescriptions();

            expect($descriptions)->toHaveKey('catalog_membership');
            expect($descriptions)->toHaveKey('schema_validation');
            expect($descriptions)->toHaveKey('pii_scanning');
            expect($descriptions)->toHaveKey('data_quality');
            expect($descriptions)->toHaveKey('compliance');
        });

        test('addStage and removeStage work correctly', function (): void {
            $pipeline = new EventValidationPipeline;
            $pipeline->addStage(new CatalogMembershipStage);

            expect($pipeline->count())->toBe(1);

            $pipeline->removeStage('catalog_membership');

            expect($pipeline->count())->toBe(0);
        });

        test('stages are sorted by priority', function (): void {
            $pipeline = new EventValidationPipeline;
            $pipeline->addStage(new ComplianceValidationStage);  // priority 50
            $pipeline->addStage(new CatalogMembershipStage);      // priority 10

            expect($pipeline->stageNames())->toBe([
                'catalog_membership',
                'compliance',
            ]);
        });
    });

    describe('CatalogMembershipStage', function (): void {
        test('valid catalog event passes', function (): void {
            $stage = new CatalogMembershipStage();
            $event = new AnalyticsEvent(name: 'page_view', params: ['url' => '/test']);

            $result = $stage->validate($event);

            expect($result['passed'])->toBeTrue();
            expect($result['metrics']['checked'])->toBe(3);
            expect($result['metrics']['failed'])->toBe(0);
        });

        test('unknown event fails when membership enforced', function (): void {
            $stage = new CatalogMembershipStage(['enforce_membership' => true]);
            $event = new AnalyticsEvent(name: 'nonexistent_custom_event', params: []);

            $result = $stage->validate($event);

            expect($result['passed'])->toBeFalse();
            expect($result['errors'])->toHaveCount(1);
            expect($result['errors'][0]['code'])->toBe('catalog_not_found');
            expect($result['errors'][0]['severity'])->toBe('error');
        });

        test('unknown event passes when membership not enforced', function (): void {
            $stage = new CatalogMembershipStage(['enforce_membership' => false]);
            $event = new AnalyticsEvent(name: 'custom_tracking_event', params: []);

            $result = $stage->validate($event);

            expect($result['passed'])->toBeTrue();
        });

        test('too long event name fails', function (): void {
            $stage = new CatalogMembershipStage(['max_name_length' => 10]);
            $longName = str_repeat('a', 100);
            $event = new AnalyticsEvent(name: $longName, params: []);

            $result = $stage->validate($event);

            expect($result['passed'])->toBeFalse();
            expect($result['errors'])->toContain(fn (array $e): bool => $e['code'] === 'name_too_long');
        });

        test('non-snake-case name generates warning', function (): void {
            $stage = new CatalogMembershipStage(['enforce_snake_case' => true, 'enforce_membership' => false]);
            $event = new AnalyticsEvent(name: 'MyCustomEvent', params: []);

            $result = $stage->validate($event);

            expect($result['passed'])->toBeFalse();
            expect($result['errors'])->toContain(fn (array $e): bool => $e['code'] === 'invalid_naming');
        });

        test('stage metadata is correct', function (): void {
            $stage = new CatalogMembershipStage;

            expect($stage->name())->toBe('catalog_membership');
            expect($stage->priority())->toBe(10);
            expect($stage->enabled())->toBeTrue();
            expect($stage->description())->toBeString();
        });
    });

    describe('SchemaValidationStage', function (): void {
        test('disabled stage skips validation', function (): void {
            $stage = new SchemaValidationStage(['enabled' => false]);
            $event = new AnalyticsEvent(name: 'test', params: []);

            $result = $stage->validate($event);

            expect($result['passed'])->toBeTrue();
            expect($result['metrics']['skipped'])->toBe(1);
        });

        test('enabled stage validates params count', function (): void {
            $stage = new SchemaValidationStage([
                'enabled' => true,
                'max_param_count' => 5,
            ]);
            $params = array_fill(0, 10, 'value');
            $event = new AnalyticsEvent(name: 'test_event', params: $params);

            $result = $stage->validate($event);

            expect($result['passed'])->toBeFalse();
            expect($result['errors'])->toContain(fn (array $e): bool => $e['code'] === 'excessive_params');
        });

        test('stage metadata is correct', function (): void {
            $stage = new SchemaValidationStage;

            expect($stage->name())->toBe('schema_validation');
            expect($stage->priority())->toBe(20);
            expect($stage->enabled())->toBeFalse();
        });
    });

    describe('PiiScanningStage', function (): void {
        test('disabled stage skips validation', function (): void {
            $stage = new PiiScanningStage(['enabled' => false]);
            $event = new AnalyticsEvent(name: 'test', params: ['password' => 'secret123']);

            $result = $stage->validate($event);

            expect($result['passed'])->toBeTrue();
            expect($result['metrics']['skipped'])->toBe(1);
        });

        test('detects disallowed keys', function (): void {
            $stage = new PiiScanningStage(['enabled' => true]);
            $event = new AnalyticsEvent(name: 'test', params: [
                'user_email' => 'test@example.com',
                'password' => 'secret123',
                'api_key' => 'key-12345',
            ]);

            $result = $stage->validate($event);

            expect($result['passed'])->toBeFalse();
            $disallowedErrors = array_filter(
                $result['errors'],
                fn (array $e): bool => $e['code'] === 'pii_disallowed_key',
            );
            expect(count($disallowedErrors))->toBe(2);
        });

        test('detects email PII patterns', function (): void {
            $stage = new PiiScanningStage(['enabled' => true]);
            $event = new AnalyticsEvent(name: 'test', params: [
                'contact_info' => 'user@example.com',
            ]);

            $result = $stage->validate($event);

            $piiErrors = array_filter(
                $result['errors'],
                fn (array $e): bool => $e['code'] === 'pii_detected',
            );
            expect(count($piiErrors))->toBeGreaterThanOrEqual(1);
        });

        test('skip patterns exclude known-safe fields', function (): void {
            $stage = new PiiScanningStage([
                'enabled' => true,
                'skip_patterns' => ['email_address'],
            ]);
            $event = new AnalyticsEvent(name: 'test', params: [
                'email_address' => 'user@example.com',
            ]);

            $result = $stage->validate($event);

            $piiErrors = array_filter(
                $result['errors'],
                fn (array $e): bool => $e['code'] === 'pii_detected',
            );
            expect(count($piiErrors))->toBe(0);
        });

        test('detects SSN patterns', function (): void {
            $stage = new PiiScanningStage(['enabled' => true]);
            $event = new AnalyticsEvent(name: 'test', params: [
                'id_number' => '123-45-6789',
            ]);

            $result = $stage->validate($event);

            $piiErrors = array_filter(
                $result['errors'],
                fn (array $e): bool => $e['code'] === 'pii_detected',
            );
            expect(count($piiErrors))->toBeGreaterThanOrEqual(1);
        });

        test('stage metadata is correct', function (): void {
            $stage = new PiiScanningStage;

            expect($stage->name())->toBe('pii_scanning');
            expect($stage->priority())->toBe(30);
            expect($stage->enabled())->toBeTrue();
        });
    });

    describe('DataQualityStage', function (): void {
        test('disabled stage skips validation', function (): void {
            $stage = new DataQualityStage(['enabled' => false]);
            $event = new AnalyticsEvent(name: 'test', params: []);

            $result = $stage->validate($event);

            expect($result['passed'])->toBeTrue();
            expect($result['metrics']['skipped'])->toBe(1);
        });

        test('detects excessive empty params', function (): void {
            $stage = new DataQualityStage([
                'enabled' => true,
                'max_empty_params' => 3,
            ]);
            $params = array_fill(0, 5, '');
            $event = new AnalyticsEvent(name: 'test', params: $params);

            $result = $stage->validate($event);

            expect($result['passed'])->toBeFalse();
            expect($result['errors'])->toContain(fn (array $e): bool => $e['code'] === 'excessive_empty_params');
        });

        test('detects HTML content', function (): void {
            $stage = new DataQualityStage(['enabled' => true]);
            $event = new AnalyticsEvent(name: 'test', params: [
                'content' => '<b>bold text</b>',
            ]);

            $result = $stage->validate($event);

            expect($result['passed'])->toBeFalse();
            expect($result['errors'])->toContain(fn (array $e): bool => $e['code'] === 'html_detected');
        });

        test('clean params pass validation', function (): void {
            $stage = new DataQualityStage(['enabled' => true]);
            $event = new AnalyticsEvent(name: 'test', params: [
                'name' => 'John Doe',
                'value' => 42,
                'active' => true,
            ]);

            $result = $stage->validate($event);

            expect($result['passed'])->toBeTrue();
            expect($result['metrics']['checked'])->toBe(3);
            expect($result['metrics']['failed'])->toBe(0);
        });

        test('stage metadata is correct', function (): void {
            $stage = new DataQualityStage;

            expect($stage->name())->toBe('data_quality');
            expect($stage->priority())->toBe(40);
            expect($stage->enabled())->toBeTrue();
        });
    });

    describe('ComplianceValidationStage', function (): void {
        test('disabled stage skips validation', function (): void {
            $stage = new ComplianceValidationStage(['enabled' => false]);
            $event = new AnalyticsEvent(name: 'sign_up', params: []);

            $result = $stage->validate($event);

            expect($result['passed'])->toBeTrue();
            expect($result['metrics']['skipped'])->toBe(1);
        });

        test('PII event without consent fails', function (): void {
            $stage = new ComplianceValidationStage([
                'enabled' => true,
                'require_consent_for_pii' => true,
            ]);
            $event = new AnalyticsEvent(name: 'sign_up', params: []);

            $result = $stage->validate($event);

            expect($result['passed'])->toBeFalse();
            expect($result['errors'])->toContain(fn (array $e): bool => $e['code'] === 'pii_without_consent');
        });

        test('PII event with consent passes', function (): void {
            $stage = new ComplianceValidationStage([
                'enabled' => true,
                'require_consent_for_pii' => true,
            ]);
            $event = new AnalyticsEvent(name: 'sign_up', params: [
                '_zb_consent_granted' => true,
            ]);

            $result = $stage->validate($event);

            expect($result['passed'])->toBeTrue();
        });

        test('non-PII event passes without consent', function (): void {
            $stage = new ComplianceValidationStage([
                'enabled' => true,
                'require_consent_for_pii' => true,
            ]);
            $event = new AnalyticsEvent(name: 'page_view', params: ['url' => '/test']);

            $result = $stage->validate($event);

            expect($result['passed'])->toBeTrue();
        });

        test('stage metadata is correct', function (): void {
            $stage = new ComplianceValidationStage;

            expect($stage->name())->toBe('compliance');
            expect($stage->priority())->toBe(50);
            expect($stage->enabled())->toBeTrue();
        });
    });

    describe('Full Pipeline Integration', function (): void {
        test('valid page_view event passes all stages', function (): void {
            $pipeline = EventValidationPipeline::withDefaults();
            $event = new AnalyticsEvent(name: 'page_view', params: [
                'page_url' => '/dashboard',
                'page_title' => 'Dashboard',
                'referrer' => 'https://google.com',
            ]);

            $report = $pipeline->validate($event);

            expect($report['valid'])->toBeTrue();
            expect($report['event_name'])->toBe('page_view');
            expect($report['stage_count'])->toBe(5);
            expect($report['score'])->toBeGreaterThanOrEqual(0.8);
            expect($report['total_errors'])->toBe(0);
        });

        test('PII event without consent fails compliance stage', function (): void {
            $pipeline = EventValidationPipeline::withDefaults([
                'compliance' => ['enabled' => true, 'require_consent_for_pii' => true],
                'pii_scanning' => ['enabled' => true],
            ]);
            $event = new AnalyticsEvent(name: 'sign_up', params: [
                'email' => 'user@example.com',
            ]);

            $report = $pipeline->validate($event);

            expect($report['valid'])->toBeFalse();
            expect($report['total_errors'])->toBeGreaterThanOrEqual(1);
        });

        test('event with disallowed key fails PII scanning', function (): void {
            $pipeline = EventValidationPipeline::withDefaults([
                'pii_scanning' => ['enabled' => true],
                'catalog_membership' => ['enforce_membership' => false],
            ]);
            $event = new AnalyticsEvent(name: 'custom_event', params: [
                'password' => 'secret',
            ]);

            $report = $pipeline->validate($event);

            expect($report['valid'])->toBeFalse();
            $piiErrors = array_filter(
                $report['errors'],
                fn (array $e): bool => $e['code'] === 'pii_disallowed_key',
            );
            expect(count($piiErrors))->toBeGreaterThanOrEqual(1);
        });

        test('report structure contains all required keys', function (): void {
            $pipeline = EventValidationPipeline::withDefaults();
            $event = new AnalyticsEvent(name: 'page_view', params: []);
            $report = $pipeline->validate($event);

            $requiredKeys = [
                'valid', 'event_name', 'score', 'stage_count',
                'passed_count', 'failed_count', 'skipped_count',
                'total_errors', 'total_warnings', 'stages', 'errors',
            ];

            foreach ($requiredKeys as $key) {
                expect($report)->toHaveKey($key);
            }

            // Each stage should have required keys
            foreach ($report['stages'] as $stageName => $stageResult) {
                expect($stageResult)->toHaveKey('passed');
                expect($stageResult)->toHaveKey('errors');
                expect($stageResult)->toHaveKey('metrics');
                expect($stageResult)->toHaveKey('duration_ms');
            }

            // Each error should have required keys
            foreach ($report['errors'] as $error) {
                expect($error)->toHaveKey('stage');
                expect($error)->toHaveKey('code');
                expect($error)->toHaveKey('message');
                expect($error)->toHaveKey('severity');
            }
        });

        test('score is between 0 and 1', function (): void {
            $pipeline = EventValidationPipeline::withDefaults();
            $event = new AnalyticsEvent(name: 'page_view', params: ['url' => '/test']);

            $report = $pipeline->validate($event);

            expect($report['score'])->toBeGreaterThanOrEqual(0.0);
            expect($report['score'])->toBeLessThanOrEqual(1.0);
        });

        test('purchase event with clean ecommerce params passes', function (): void {
            $pipeline = EventValidationPipeline::withDefaults([
                'catalog_membership' => ['enforce_membership' => true],
                'pii_scanning' => ['enabled' => true],
                'compliance' => ['enabled' => false],
            ]);
            $event = new AnalyticsEvent(name: 'purchase', params: [
                'transaction_id' => 'txn-12345',
                'value' => 49.99,
                'currency' => 'USD',
                'items' => 1,
            ]);

            $report = $pipeline->validate($event);

            expect($report['valid'])->toBeTrue();
            expect($report['score'])->toBeGreaterThanOrEqual(0.8);
        });

        test('start_trial SaaS event passes with consent', function (): void {
            $pipeline = EventValidationPipeline::withDefaults([
                'pii_scanning' => ['enabled' => true],
                'compliance' => ['enabled' => true, 'require_consent_for_pii' => true],
            ]);
            $event = new AnalyticsEvent(name: 'start_trial', params: [
                '_zb_consent_granted' => true,
                'plan' => 'pro',
            ]);

            $report = $pipeline->validate($event);

            expect($report['valid'])->toBeTrue();
        });

        test('error event with HTML content fails data quality', function (): void {
            $pipeline = EventValidationPipeline::withDefaults([
                'data_quality' => ['enabled' => true],
                'catalog_membership' => ['enforce_membership' => false],
            ]);
            $event = new AnalyticsEvent(name: 'error', params: [
                'message' => '<script>alert("xss")</script>Something broke',
            ]);

            $report = $pipeline->validate($event);

            expect($report['total_warnings'])->toBeGreaterThanOrEqual(1);
        });
    });
});
