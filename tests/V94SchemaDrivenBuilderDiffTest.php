<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Services\SchemaDrivenEventBuilder;
use ZeroBoiler\Analytics\Services\SchemaDiffReporter;
use ZeroBoiler\Analytics\Schema\EventPropertySchema;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

// ─── SchemaDrivenEventBuilder: Basic Structure ──────────────────────────

test('SchemaDrivenEventBuilder has correct namespace, strict types, and final', function (): void {
    $reflection = new ReflectionClass(SchemaDrivenEventBuilder::class);

    expect($reflection->getNamespaceName())->toBe('ZeroBoiler\\Analytics\\Services');
    expect($reflection->isFinal())->toBeTrue();

    $file = file_get_contents($reflection->getFileName());
    expect($file)->toContain('declare(strict_types=1)');
    expect($file)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
});

test('SchemaDrivenEventBuilder has version 2.95.0 in docblock', function (): void {
    $reflection = new ReflectionClass(SchemaDrivenEventBuilder::class);
    $doc = $reflection->getDocComment();

    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('5.7.0');
});

test('SchemaDrivenEventBuilder constructor accepts nullable parameters', function (): void {
    $reflection = new ReflectionClass(SchemaDrivenEventBuilder::class);
    $constructor = $reflection->getConstructor();

    expect($constructor)->not->BeNull();

    $params = $constructor->getParameters();
    expect($params)->toHaveCount(3);

    // propertySchema — nullable
    expect($params[0]->getName())->toBe('propertySchema');
    expect($params[0]->getType())->not->BeNull();
    expect($params[0]->allowsNull())->toBeTrue();

    // schemaRegistry — nullable
    expect($params[1]->getName())->toBe('schemaRegistry');
    expect($params[1]->getType())->not->BeNull();
    expect($params[1]->allowsNull())->toBeTrue();

    // strictMode — bool with default
    expect($params[2]->getName())->toBe('strictMode');
    expect($params[2]->getType()->getName())->toBe('bool');
});

// ─── SchemaDrivenEventBuilder: build() Method ────────────────────────────

test('SchemaDrivenEventBuilder builds event without schema validation', function (): void {
    $builder = new SchemaDrivenEventBuilder;

    $event = $builder->build('custom_event', ['key' => 'value']);

    expect($event)->not->toBeNull();
    expect($event)->toBeInstanceOf(AnalyticsEvent::class);
    expect($event->name)->toBe('custom_event');
    expect($event->params)->toBe(['key' => 'value']);
});

test('SchemaDrivenEventBuilder builds event with client ID and user ID', function (): void {
    $builder = new SchemaDrivenEventBuilder;

    $event = $builder->build('page_view', [], 'client-123', 'user-456');

    expect($event)->not->BeNull();
    expect($event->clientId)->toBe('client-123');
    expect($event->userId)->toBe('user-456');
    expect($event->params['client_id'])->toBe('client-123');
    expect($event->params['user_id'])->toBe('user-456');
});

test('SchemaDrivenEventBuilder validates with property schema in strict mode', function (): void {
    $propertySchema = new EventPropertySchema;
    $propertySchema->registerBuiltInSchemas();

    $builder = new SchemaDrivenEventBuilder($propertySchema, null, true);

    // purchase requires transaction_id, value, currency — missing all
    expect(fn () => $builder->build('purchase', ['transaction_id' => 'TXN-1']))
        ->toThrow(\InvalidArgumentException::class);
});

test('SchemaDrivenEventBuilder returns null on validation failure (non-strict)', function (): void {
    $propertySchema = new EventPropertySchema;
    $propertySchema->registerBuiltInSchemas();

    $builder = new SchemaDrivenEventBuilder($propertySchema, null, false);

    // Missing required fields
    $event = $builder->build('purchase', ['transaction_id' => 'TXN-1']);

    expect($event)->toBeNull();
});

test('SchemaDrivenEventBuilder passes valid event through property schema', function (): void {
    $propertySchema = new EventPropertySchema;
    $propertySchema->registerBuiltInSchemas();

    $builder = new SchemaDrivenEventBuilder($propertySchema, null, false);

    $event = $builder->build('purchase', [
        'transaction_id' => 'TXN-123',
        'value' => 99.99,
        'currency' => 'USD',
    ]);

    expect($event)->not->BeNull();
    expect($event->name)->toBe('purchase');
    expect($event->params['transaction_id'])->toBe('TXN-123');
    expect($event->params['value'])->toBe(99.99);
});

test('SchemaDrivenEventBuilder validates with enum constraint', function (): void {
    $propertySchema = new EventPropertySchema;
    $propertySchema->registerBuiltInSchemas();

    $builder = new SchemaDrivenEventBuilder($propertySchema, null, false);

    // sign_up method must be in enum list
    $event = $builder->build('sign_up', ['method' => 'invalid_provider']);

    expect($event)->toBeNull();

    // Valid enum value
    $event = $builder->build('sign_up', ['method' => 'google']);
    expect($event)->not->BeNull();
});

test('SchemaDrivenEventBuilder validates with format constraint (currency)', function (): void {
    $propertySchema = new EventPropertySchema;
    $propertySchema->registerBuiltInSchemas();

    $builder = new SchemaDrivenEventBuilder($propertySchema, null, false);

    // Invalid currency format
    $event = $builder->build('purchase', [
        'transaction_id' => 'TXN-1',
        'value' => 10,
        'currency' => 'US',
    ]);

    expect($event)->toBeNull();
});

// ─── SchemaDrivenEventBuilder: Type Coercion ────────────────────────────

test('SchemaDrivenEventBuilder coerces numeric string to number', function (): void {
    $propertySchema = new EventPropertySchema;
    $propertySchema->registerBuiltInSchemas();

    $builder = new SchemaDrivenEventBuilder($propertySchema, null, false);

    $event = $builder->build('purchase', [
        'transaction_id' => 'TXN-1',
        'value' => '99.99', // string
        'currency' => 'USD',
    ]);

    expect($event)->not->BeNull();
    expect($event->params['value'])->toBe(99.99);
});

test('SchemaDrivenEventBuilder coerces string int to integer', function (): void {
    $propertySchema = new EventPropertySchema;
    $propertySchema->registerBuiltInSchemas();

    $builder = new SchemaDrivenEventBuilder($propertySchema, null, false);

    $event = $builder->build('start_trial', [
        'plan' => 'pro',
        'trial_days' => '14', // string
    ]);

    expect($event)->not->BeNull();
    expect($event->params['trial_days'])->toBe(14);
});

test('SchemaDrivenEventBuilder coerceParams preserves unknown params', function (): void {
    $builder = new SchemaDrivenEventBuilder;

    $coerced = $builder->coerceParams('custom_event', ['key' => 'value', 'count' => '5']);

    expect($coerced)->toBe(['key' => 'value', 'count' => '5']);
});

// ─── SchemaDrivenEventBuilder: buildBatch() ──────────────────────────────

test('SchemaDrivenEventBuilder buildBatch processes valid and invalid events', function (): void {
    $propertySchema = new EventPropertySchema;
    $propertySchema->registerBuiltInSchemas();

    $builder = new SchemaDrivenEventBuilder($propertySchema, null, false);

    $result = $builder->buildBatch('purchase', [
        ['transaction_id' => 'TXN-1', 'value' => 10, 'currency' => 'USD'],
        ['transaction_id' => 'TXN-2', 'value' => 20, 'currency' => 'USD'],
        ['value' => 30], // Missing transaction_id
    ]);

    expect($result['events'])->toHaveCount(2);
    expect($result['errors'])->toHaveCount(1);
    expect($result['errors'][0]['index'])->toBe(2);
});

test('SchemaDrivenEventBuilder buildBatch returns empty for all invalid', function (): void {
    $propertySchema = new EventPropertySchema;
    $propertySchema->registerBuiltInSchemas();

    $builder = new SchemaDrivenEventBuilder($propertySchema, null, false);

    $result = $builder->buildBatch('purchase', [
        ['value' => 10],
        [],
    ]);

    expect($result['events'])->toHaveCount(0);
    expect($result['errors'])->toHaveCount(2);
});

// ─── SchemaDrivenEventBuilder: validateOnly() ──────────────────────────

test('SchemaDrivenEventBuilder validateOnly returns combined results', function (): void {
    $propertySchema = new EventPropertySchema;
    $propertySchema->registerBuiltInSchemas();

    $builder = new SchemaDrivenEventBuilder($propertySchema, null, false);

    $result = $builder->validateOnly('purchase', [
        'transaction_id' => 'TXN-1',
        'value' => 10,
        'currency' => 'USD',
    ]);

    expect($result['valid'])->toBeTrue();
    expect($result['property_errors'])->toBe([]);
    expect($result['registry_errors'])->toBe([]);
});

test('SchemaDrivenEventBuilder validateOnly detects missing required fields', function (): void {
    $propertySchema = new EventPropertySchema;
    $propertySchema->registerBuiltInSchemas();

    $builder = new SchemaDrivenEventBuilder($propertySchema, null, false);

    $result = $builder->validateOnly('purchase', ['value' => 10]);

    expect($result['valid'])->toBeFalse();
    expect($result['property_errors'])->not->toBeEmpty();
});

// ─── SchemaDrivenEventBuilder: hasSchema() / getSchemaInfo() ────────────

test('SchemaDrivenEventBuilder hasSchema detects registered schemas', function (): void {
    $propertySchema = new EventPropertySchema;
    $propertySchema->registerBuiltInSchemas();

    $builder = new SchemaDrivenEventBuilder($propertySchema, null, false);

    expect($builder->hasSchema('purchase'))->toBeTrue();
    expect($builder->hasSchema('nonexistent_event'))->toBeFalse();
});

test('SchemaDrivenEventBuilder getSchemaInfo returns combined info', function (): void {
    $propertySchema = new EventPropertySchema;
    $propertySchema->registerBuiltInSchemas();
    $schemaRegistry = new EventSchemaRegistry;

    $builder = new SchemaDrivenEventBuilder($propertySchema, $schemaRegistry, false);

    $info = $builder->getSchemaInfo('purchase');

    expect($info)->toHaveKey('property_schema');
    expect($info)->toHaveKey('registry_schema');
    expect($info['property_schema'])->not->toBeEmpty();
    expect($info['registry_schema'])->not->toBeNull();
    expect($info['registry_schema']['name'])->toBe('purchase');
    expect($info['registry_schema']['category'])->toBe('ecommerce');
});

test('SchemaDrivenEventBuilder getSchemaInfo returns empty for unknown event', function (): void {
    $builder = new SchemaDrivenEventBuilder;

    $info = $builder->getSchemaInfo('unknown_event');

    expect($info['property_schema'])->toBeEmpty();
    expect($info['registry_schema'])->toBeNull();
});

// ─── SchemaDrivenEventBuilder: setStrict() ──────────────────────────────

test('SchemaDrivenEventBuilder setStrict toggles mode', function (): void {
    $builder = new SchemaDrivenEventBuilder;

    expect($builder->isStrict())->toBeFalse();

    $builder->setStrict(true);
    expect($builder->isStrict())->toBeTrue();

    $builder->setStrict(false);
    expect($builder->isStrict())->toBeFalse();
});

// ─── SchemaDiffReporter: Basic Structure ───────────────────────────────

test('SchemaDiffReporter has correct namespace, strict types, and final', function (): void {
    $reflection = new ReflectionClass(SchemaDiffReporter::class);

    expect($reflection->getNamespaceName())->toBe('ZeroBoiler\\Analytics\\Services');
    expect($reflection->isFinal())->toBeTrue();

    $file = file_get_contents($reflection->getFileName());
    expect($file)->toContain('declare(strict_types=1)');
    expect($file)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
});

test('SchemaDiffReporter has version 2.95.0 in docblock', function (): void {
    $reflection = new ReflectionClass(SchemaDiffReporter::class);
    $doc = $reflection->getDocComment();

    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('5.7.0');
});

// ─── SchemaDiffReporter: report() ────────────────────────────────────────

test('SchemaDiffReporter report returns correct structure', function (): void {
    $reporter = new SchemaDiffReporter;
    $propertySchema = new EventPropertySchema;
    $propertySchema->registerBuiltInSchemas();
    $schemaRegistry = new EventSchemaRegistry;

    $report = $reporter->report($propertySchema, $schemaRegistry);

    expect($report)->toHaveKeys([
        'total_catalog',
        'total_property',
        'total_registry',
        'full_coverage',
        'partial_coverage',
        'catalog_only',
        'property_only',
        'registry_only',
        'full_coverage_events',
        'coverage_pct',
    ]);
});

test('SchemaDiffReporter report calculates coverage percentage', function (): void {
    $reporter = new SchemaDiffReporter;
    $propertySchema = new EventPropertySchema;
    $propertySchema->registerBuiltInSchemas();
    $schemaRegistry = new EventSchemaRegistry;

    $report = $reporter->report($propertySchema, $schemaRegistry);

    expect($report['coverage_pct'])->toBeGreaterThanOrEqual(0);
    expect($report['coverage_pct'])->toBeLessThanOrEqual(100);
    expect($report['total_catalog'])->toBeGreaterThan(0);
});

test('SchemaDiffReporter report identifies full coverage events', function (): void {
    $reporter = new SchemaDiffReporter;
    $propertySchema = new EventPropertySchema;
    $propertySchema->registerBuiltInSchemas();
    $schemaRegistry = new EventSchemaRegistry;

    $report = $reporter->report($propertySchema, $schemaRegistry);

    expect($report['full_coverage'])->toBeGreaterThan(0);
    expect($report['full_coverage_events'])->toContain('purchase');
    expect($report['full_coverage_events'])->toContain('sign_up');
    expect($report['full_coverage_events'])->toContain('page_view');
});

test('SchemaDiffReporter report works with null schemas', function (): void {
    $reporter = new SchemaDiffReporter;

    $report = $reporter->report(null, null);

    expect($report['total_catalog'])->toBeGreaterThan(0);
    expect($report['total_property'])->toBe(0);
    expect($report['total_registry'])->toBe(0);
    expect($report['coverage_pct'])->toBe(0.0);
    expect($report['catalog_only'])->not->toBeEmpty();
});

// ─── SchemaDiffReporter: summary() ──────────────────────────────────────

test('SchemaDiffReporter summary returns human-readable string', function (): void {
    $reporter = new SchemaDiffReporter;
    $propertySchema = new EventPropertySchema;
    $propertySchema->registerBuiltInSchemas();
    $schemaRegistry = new EventSchemaRegistry;

    $summary = $reporter->summary($propertySchema, $schemaRegistry);

    expect($summary)->toBeString();
    expect($summary)->toContain('ZeroBoiler Analytics — Schema Coverage Report');
    expect($summary)->toContain('Catalog events:');
    expect($summary)->toContain('Property schemas:');
    expect($summary)->toContain('Registry schemas:');
    expect($summary)->toContain('Full coverage');
});

// ─── SchemaDiffReporter: meetsThreshold() ──────────────────────────────

test('SchemaDiffReporter meetsThreshold checks minimum coverage', function (): void {
    $reporter = new SchemaDiffReporter;
    $propertySchema = new EventPropertySchema;
    $propertySchema->registerBuiltInSchemas();
    $schemaRegistry = new EventSchemaRegistry;

    expect($reporter->meetsThreshold(0, $propertySchema, $schemaRegistry))->toBeTrue();
    expect($reporter->meetsThreshold(100, $propertySchema, $schemaRegistry))->toBeFalse();
});

// ─── SchemaDiffReporter: reportByCategory() ───────────────────────────

test('SchemaDiffReporter reportByCategory returns per-category breakdown', function (): void {
    $reporter = new SchemaDiffReporter;
    $propertySchema = new EventPropertySchema;
    $propertySchema->registerBuiltInSchemas();
    $schemaRegistry = new EventSchemaRegistry;

    $report = $reporter->reportByCategory($propertySchema, $schemaRegistry);

    expect($report)->toHaveKeys(['ecommerce', 'saas', 'engagement']);

    foreach ($report as $category => $data) {
        expect($data)->toHaveKeys(['total', 'full', 'partial', 'missing', 'coverage_pct']);
        expect($data['total'])->toBeGreaterThan(0);
    }
});

test('SchemaDiffReporter reportByCategory ecommerce has high coverage', function (): void {
    $reporter = new SchemaDiffReporter;
    $propertySchema = new EventPropertySchema;
    $propertySchema->registerBuiltInSchemas();
    $schemaRegistry = new EventSchemaRegistry;

    $report = $reporter->reportByCategory($propertySchema, $schemaRegistry);

    expect($report['ecommerce']['coverage_pct'])->toBeGreaterThanOrEqual(80);
    expect($report['ecommerce']['full'])->toBeGreaterThan(0);
});

// ─── EventPropertySchema: Expanded Coverage ─────────────────────────────

test('EventPropertySchema registerBuiltInSchemas covers all core events', function (): void {
    $schema = new EventPropertySchema;
    $schema->registerBuiltInSchemas();

    // Core e-commerce
    expect($schema->hasSchema('purchase'))->toBeTrue();
    expect($schema->hasSchema('refund'))->toBeTrue();
    expect($schema->hasSchema('add_to_cart'))->toBeTrue();
    expect($schema->hasSchema('view_item'))->toBeTrue();
    expect($schema->hasSchema('begin_checkout'))->toBeTrue();
    expect($schema->hasSchema('abandoned_cart'))->toBeTrue();

    // Core SaaS
    expect($schema->hasSchema('sign_up'))->toBeTrue();
    expect($schema->hasSchema('login'))->toBeTrue();
    expect($schema->hasSchema('subscribe'))->toBeTrue();
    expect($schema->hasSchema('plan_upgrade'))->toBeTrue();
    expect($schema->hasSchema('cancellation'))->toBeTrue();
    expect($schema->hasSchema('start_trial'))->toBeTrue();

    // Core engagement
    expect($schema->hasSchema('page_view'))->toBeTrue();
    expect($schema->hasSchema('search'))->toBeTrue();
    expect($schema->hasSchema('error'))->toBeTrue();
    expect($schema->hasSchema('scroll_depth'))->toBeTrue();
    expect($schema->hasSchema('share'))->toBeTrue();
});

test('EventPropertySchema registerBuiltInSchemas covers billing events', function (): void {
    $schema = new EventPropertySchema;
    $schema->registerBuiltInSchemas();

    expect($schema->hasSchema('payment_succeeded'))->toBeTrue();
    expect($schema->hasSchema('payment_failed'))->toBeTrue();
    expect($schema->hasSchema('invoice_generated'))->toBeTrue();
    expect($schema->hasSchema('credit_applied'))->toBeTrue();
    expect($schema->hasSchema('billing_retry'))->toBeTrue();
});

test('EventPropertySchema registerBuiltInSchemas covers account lifecycle', function (): void {
    $schema = new EventPropertySchema;
    $schema->registerBuiltInSchemas();

    expect($schema->hasSchema('account_activated'))->toBeTrue();
    expect($schema->hasSchema('account_deactivated'))->toBeTrue();
    expect($schema->hasSchema('account_deleted'))->toBeTrue();
    expect($schema->hasSchema('email_verified'))->toBeTrue();
    expect($schema->hasSchema('password_changed'))->toBeTrue();
    expect($schema->hasSchema('profile_updated'))->toBeTrue();
});

test('EventPropertySchema registerBuiltInSchemas covers GDPR events', function (): void {
    $schema = new EventPropertySchema;
    $schema->registerBuiltInSchemas();

    expect($schema->hasSchema('consent_granted'))->toBeTrue();
    expect($schema->hasSchema('consent_withdrawn'))->toBeTrue();
    expect($schema->hasSchema('data_subject_access_request'))->toBeTrue();
    expect($schema->hasSchema('data_erasure_completed'))->toBeTrue();
});

test('EventPropertySchema registerBuiltInSchemas covers cohort events', function (): void {
    $schema = new EventPropertySchema;
    $schema->registerBuiltInSchemas();

    expect($schema->hasSchema('cohort_assigned'))->toBeTrue();
    expect($schema->hasSchema('cohort_retention'))->toBeTrue();
    expect($schema->hasSchema('cohort_churn'))->toBeTrue();
    expect($schema->hasSchema('cohort_conversion'))->toBeTrue();
    expect($schema->hasSchema('cohort_migration'))->toBeTrue();
    expect($schema->hasSchema('cohort_engagement'))->toBeTrue();
});

test('EventPropertySchema expanded schema count is significantly larger than before', function (): void {
    $schema = new EventPropertySchema;
    $schema->registerBuiltInSchemas();

    // v2.93.0 had ~13 schemas. v2.95.0 should have 60+.
    expect($schema->schemaCount())->toBeGreaterThanOrEqual(60);
});

test('EventPropertySchema global rules include timestamp and source', function (): void {
    $schema = new EventPropertySchema;
    $schema->registerBuiltInSchemas();

    // All events should validate against global rules
    $event = new AnalyticsEvent(name: 'custom', params: [
        'user_id' => 'u1',
        'client_id' => 'c1',
        'session_id' => 's1',
        'timestamp' => '2026-08-08T00:00:00Z',
        'source' => 'server',
    ]);

    $result = $schema->validate($event);
    expect($result['valid'])->toBeTrue();
});

// ─── Version Consistency ────────────────────────────────────────────────

test('Version 2.95.0 is consistent across key files', function (): void {
    $managerReflection = new ReflectionClass(\ZeroBoiler\Analytics\AnalyticsManager::class);
    $managerFile = file_get_contents($managerReflection->getFileName());

    $spReflection = new ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class);
    $spFile = file_get_contents($spReflection->getFileName());

    expect($managerFile)->toContain('5.7.0');
    expect($spFile)->toContain('5.7.0');
});
