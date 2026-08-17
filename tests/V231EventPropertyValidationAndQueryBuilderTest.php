<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Schema\EventParam;
use ZeroBoiler\Analytics\Schema\EventSchema;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;
use ZeroBoiler\Analytics\Services\EventPropertyTypeValidator;
use ZeroBoiler\Analytics\Services\EventQueryBuilder;
use ZeroBoiler\Analytics\Services\PropertyValidationResult;
use ZeroBoiler\Analytics\Services\PropertyViolation;

/**
 * v231.0.0 — Event Property Type Validator + Event Query Builder tests.
 *
 * Tests runtime parameter type validation against EventSchemaRegistry schemas,
 * the PropertyViolation and PropertyValidationResult DTOs, and the fluent
 * EventQueryBuilder API.
 *
 * @covers \ZeroBoiler\Analytics\Services\EventPropertyTypeValidator
 * @covers \ZeroBoiler\Analytics\Services\PropertyViolation
 * @covers \ZeroBoiler\Analytics\Services\PropertyValidationResult
 * @covers \ZeroBoiler\Analytics\Services\EventQueryBuilder
 *
 * @since 231.0.0
 */
final class V231EventPropertyValidationAndQueryBuilderTest extends TestCase
{
    // ── File Quality Assertions ────────────────────────────────────

    public function test_event_property_type_validator_has_strict_types(): void
    {
        $reflection = new \ReflectionClass(EventPropertyTypeValidator::class);
        $contents = file_get_contents($reflection->getFileName());
        $this->assertStringContainsString('declare(strict_types=1)', $contents);
    }

    public function test_event_property_type_validator_has_mit_license(): void
    {
        $reflection = new \ReflectionClass(EventPropertyTypeValidator::class);
        $contents = file_get_contents($reflection->getFileName());
        $this->assertStringContainsString('MIT', $contents);
    }

    public function test_event_property_type_validator_is_final(): void
    {
        $reflection = new \ReflectionClass(EventPropertyTypeValidator::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function test_event_property_type_validator_has_since_tag(): void
    {
        $reflection = new \ReflectionClass(EventPropertyTypeValidator::class);
        $doc = $reflection->getDocComment();
        $this->assertNotFalse($doc);
        $this->assertStringContainsString('@since 231.0.0', $doc);
    }

    public function test_property_violation_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(PropertyViolation::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_property_validation_result_is_final_readonly(): void
    {
        $reflection = new \ReflectionClass(PropertyValidationResult::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_event_query_builder_is_final(): void
    {
        $reflection = new \ReflectionClass(EventQueryBuilder::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function test_event_query_builder_has_since_tag(): void
    {
        $reflection = new \ReflectionClass(EventQueryBuilder::class);
        $doc = $reflection->getDocComment();
        $this->assertNotFalse($doc);
        $this->assertStringContainsString('@since 231.0.0', $doc);
    }

    // ── PropertyViolation DTO Tests ────────────────────────────────

    public function test_property_violation_construction(): void
    {
        $violation = new PropertyViolation(
            code: 'type_mismatch',
            message: 'Expected string, got int.',
            severity: 'error',
            param: 'value',
            expected: 'string',
            actual: 'int',
        );

        $this->assertSame('type_mismatch', $violation->code);
        $this->assertSame('Expected string, got int.', $violation->message);
        $this->assertSame('error', $violation->severity);
        $this->assertSame('value', $violation->param);
        $this->assertSame('string', $violation->expected);
        $this->assertSame('int', $violation->actual);
    }

    public function test_property_violation_is_error(): void
    {
        $error = new PropertyViolation(code: 'x', message: 'err', severity: 'error');
        $warning = new PropertyViolation(code: 'x', message: 'warn', severity: 'warning');
        $info = new PropertyViolation(code: 'x', message: 'info', severity: 'info');

        $this->assertTrue($error->isError());
        $this->assertFalse($error->isWarning());

        $this->assertFalse($warning->isError());
        $this->assertTrue($warning->isWarning());

        $this->assertFalse($info->isError());
        $this->assertFalse($info->isWarning());
    }

    public function test_property_violation_to_array_round_trip(): void
    {
        $original = new PropertyViolation(
            code: 'type_mismatch',
            message: 'Test',
            severity: 'warning',
            param: 'foo',
        );

        $array = $original->toArray();
        $restored = PropertyViolation::fromArray($array);

        $this->assertSame($original->code, $restored->code);
        $this->assertSame($original->message, $restored->message);
        $this->assertSame($original->severity, $restored->severity);
        $this->assertSame($original->param, $restored->param);
    }

    public function test_property_violation_from_array_defaults(): void
    {
        $violation = PropertyViolation::fromArray(['code' => 'test', 'message' => 'hello']);
        $this->assertSame('test', $violation->code);
        $this->assertSame('hello', $violation->message);
        $this->assertSame('error', $violation->severity);
        $this->assertNull($violation->param);
        $this->assertNull($violation->expected);
        $this->assertNull($violation->actual);
    }

    // ── PropertyValidationResult DTO Tests ────────────────────────

    public function test_property_validation_result_passed(): void
    {
        $result = new PropertyValidationResult(eventName: 'purchase', valid: true);
        $this->assertTrue($result->passed());
        $this->assertFalse($result->failed());
        $this->assertSame(0, $result->violationCount());
        $this->assertSame(0, $result->warningCount());
        $this->assertSame(0, $result->issueCount());
    }

    public function test_property_validation_result_with_violations(): void
    {
        $violations = [
            new PropertyViolation(code: 'missing_required', message: 'Missing transaction_id', severity: 'error', param: 'transaction_id'),
        ];
        $warnings = [
            new PropertyViolation(code: 'unknown_param', message: 'Unknown param extra', severity: 'warning', param: 'extra'),
        ];

        $result = new PropertyValidationResult(
            eventName: 'purchase',
            valid: false,
            violations: $violations,
            warnings: $warnings,
        );

        $this->assertFalse($result->passed());
        $this->assertTrue($result->failed());
        $this->assertSame(1, $result->violationCount());
        $this->assertSame(1, $result->warningCount());
        $this->assertSame(2, $result->issueCount());
        $this->assertTrue($result->hasMissingRequired());
        $this->assertFalse($result->hasTypeMismatches());
    }

    public function test_property_validation_result_violations_for_param(): void
    {
        $violations = [
            new PropertyViolation(code: 'type_mismatch', message: 'Type error', severity: 'error', param: 'value'),
            new PropertyViolation(code: 'range_violation', message: 'Range error', severity: 'error', param: 'quantity'),
            new PropertyViolation(code: 'length_exceeded', message: 'Length error', severity: 'error', param: 'value'),
        ];

        $result = new PropertyValidationResult(eventName: 'test', valid: false, violations: $violations);
        $valueViolations = $result->violationsForParam('value');

        $this->assertCount(2, $valueViolations);
        $this->assertCount(0, $result->violationsForParam('nonexistent'));
    }

    public function test_property_validation_result_to_array_round_trip(): void
    {
        $original = new PropertyValidationResult(
            eventName: 'purchase',
            valid: true,
            violations: [],
            warnings: [
                new PropertyViolation(code: 'unknown_param', message: 'warn', severity: 'warning', param: 'x'),
            ],
        );

        $array = $original->toArray();
        $restored = PropertyValidationResult::fromArray($array);

        $this->assertSame($original->eventName, $restored->eventName);
        $this->assertSame($original->valid, $restored->valid);
        $this->assertCount(1, $restored->warnings);
    }

    public function test_property_validation_result_errors_only(): void
    {
        $result = new PropertyValidationResult(
            eventName: 'test',
            valid: false,
            violations: [
                new PropertyViolation(code: 'x', message: 'error', severity: 'error'),
                new PropertyViolation(code: 'y', message: 'warning', severity: 'warning'),
            ],
        );

        $this->assertCount(1, $result->errorsOnly());
    }

    // ── EventPropertyTypeValidator Tests ──────────────────────────

    public function test_validator_creation_with_defaults(): void
    {
        $registry = new EventSchemaRegistry();
        $validator = new EventPropertyTypeValidator($registry);

        $this->assertFalse($validator->isStrictTypes());
        $this->assertSame($registry, $validator->getSchemaRegistry());
    }

    public function test_validator_creation_strict_mode(): void
    {
        $registry = new EventSchemaRegistry();
        $validator = new EventPropertyTypeValidator($registry, strictTypes: true);

        $this->assertTrue($validator->isStrictTypes());
    }

    public function test_validate_event_without_schema(): void
    {
        $registry = new EventSchemaRegistry();
        $validator = new EventPropertyTypeValidator($registry);

        $event = new AnalyticsEvent(name: 'custom_unknown_event', params: ['foo' => 'bar']);
        $result = $validator->validate($event);

        $this->assertTrue($result->passed());
        $this->assertSame(1, $result->warningCount());
        $this->assertSame(EventPropertyTypeValidator::CODE_NO_SCHEMA, $result->warnings[0]->code);
    }

    public function test_validate_known_event_with_valid_params(): void
    {
        $registry = new EventSchemaRegistry();
        $validator = new EventPropertyTypeValidator($registry);

        $event = new AnalyticsEvent(name: 'page_view', params: [
            'page_title' => 'Homepage',
            'page_location' => 'https://example.com',
        ]);

        $result = $validator->validate($event);

        // page_view schema should accept these params without violations
        $this->assertTrue($result->passed());
    }

    public function test_validate_param_count_exceeded(): void
    {
        $registry = new EventSchemaRegistry();
        $validator = new EventPropertyTypeValidator(
            $registry,
            maxParamCount: 2,
        );

        $params = [];
        for ($i = 0; $i < 5; $i++) {
            $params["param_{$i}"] = "value_{$i}";
        }

        $event = new AnalyticsEvent(name: 'page_view', params: $params);
        $result = $validator->validate($event);

        $this->assertFalse($result->passed());
        $found = false;
        foreach ($result->violations as $v) {
            if ($v->code === 'param_count_exceeded') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected param_count_exceeded violation');
    }

    public function test_validate_params_method(): void
    {
        $registry = new EventSchemaRegistry();
        $validator = new EventPropertyTypeValidator($registry);

        $result = $validator->validateParams('page_view', ['page_title' => 'Test']);

        $this->assertNotNull($result);
        $this->assertSame('page_view', $result->eventName);
    }

    public function test_validate_single_param(): void
    {
        $registry = new EventSchemaRegistry();
        $validator = new EventPropertyTypeValidator($registry);

        $paramDef = new EventParam(type: 'int', min: 1, max: 100);
        $result = $validator->validateSingleParam('quantity', 50, $paramDef);

        $this->assertTrue($result->passed());
    }

    public function test_validate_single_param_type_mismatch(): void
    {
        $registry = new EventSchemaRegistry();
        $validator = new EventPropertyTypeValidator($registry);

        $paramDef = new EventParam(type: 'int');
        $result = $validator->validateSingleParam('age', 'not_a_number', $paramDef);

        $this->assertFalse($result->passed());
        $this->assertSame(1, $result->violationCount());
        $this->assertSame(EventPropertyTypeValidator::CODE_TYPE_MISMATCH, $result->violations[0]->code);
    }

    public function test_validate_single_param_range_violation(): void
    {
        $registry = new EventSchemaRegistry();
        $validator = new EventPropertyTypeValidator($registry);

        $paramDef = new EventParam(type: 'int', min: 1, max: 10);
        $result = $validator->validateSingleParam('quantity', 50, $paramDef);

        $this->assertFalse($result->passed());
        $this->assertSame(EventPropertyTypeValidator::CODE_RANGE_VIOLATION, $result->violations[0]->code);
    }

    public function test_validator_constants(): void
    {
        $this->assertSame('error', EventPropertyTypeValidator::SEVERITY_ERROR);
        $this->assertSame('warning', EventPropertyTypeValidator::SEVERITY_WARNING);
        $this->assertSame('info', EventPropertyTypeValidator::SEVERITY_INFO);
        $this->assertSame('missing_required', EventPropertyTypeValidator::CODE_MISSING_REQUIRED);
        $this->assertSame('type_mismatch', EventPropertyTypeValidator::CODE_TYPE_MISMATCH);
        $this->assertSame('range_violation', EventPropertyTypeValidator::CODE_RANGE_VIOLATION);
        $this->assertSame('length_exceeded', EventPropertyTypeValidator::CODE_LENGTH_EXCEEDED);
        $this->assertSame('unknown_param', EventPropertyTypeValidator::CODE_UNKNOWN_PARAM);
        $this->assertSame('no_schema', EventPropertyTypeValidator::CODE_NO_SCHEMA);
    }

    // ── EventQueryBuilder Tests ──────────────────────────────────

    public function test_query_builder_make(): void
    {
        $builder = EventQueryBuilder::make();
        $this->assertInstanceOf(EventQueryBuilder::class, $builder);
    }

    public function test_query_builder_name_filter(): void
    {
        $builder = EventQueryBuilder::make()->name('purchase');
        $desc = $builder->buildQueryDescription();
        $this->assertSame(['purchase'], $desc['event_names']);
    }

    public function test_query_builder_multiple_names(): void
    {
        $builder = EventQueryBuilder::make()->name(['purchase', 'refund']);
        $desc = $builder->buildQueryDescription();
        $this->assertSame(['purchase', 'refund'], $desc['event_names']);
    }

    public function test_query_builder_category_filter(): void
    {
        $builder = EventQueryBuilder::make()->category('ecommerce');
        $desc = $builder->buildQueryDescription();
        $this->assertSame(['ecommerce'], $desc['categories']);
    }

    public function test_query_builder_param_filter(): void
    {
        $builder = EventQueryBuilder::make()->param('currency', 'USD');
        $desc = $builder->buildQueryDescription();
        $this->assertSame(['currency' => 'USD'], $desc['param_filters']);
    }

    public function test_query_builder_client_id_filter(): void
    {
        $builder = EventQueryBuilder::make()->clientId('abc-123');
        $desc = $builder->buildQueryDescription();
        $this->assertSame('abc-123', $desc['client_id']);
    }

    public function test_query_builder_user_id_filter(): void
    {
        $builder = EventQueryBuilder::make()->userId('user-42');
        $desc = $builder->buildQueryDescription();
        $this->assertSame('user-42', $desc['user_id']);
    }

    public function test_query_builder_since_until(): void
    {
        $since = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $until = new \DateTimeImmutable('2026-01-31T23:59:59+00:00');

        $builder = EventQueryBuilder::make()->since($since)->until($until);
        $desc = $builder->buildQueryDescription();

        $this->assertSame('2026-01-01T00:00:00Z', $desc['since']);
        $this->assertSame('2026-01-31T23:59:59Z', $desc['until']);
    }

    public function test_query_builder_order_by(): void
    {
        $builder = EventQueryBuilder::make()->orderBy('timestamp', 'asc');
        $desc = $builder->buildQueryDescription();
        $this->assertSame(['timestamp' => 'asc'], $desc['order_by']);
    }

    public function test_query_builder_limit_offset(): void
    {
        $builder = EventQueryBuilder::make()->limit(50)->offset(100);
        $desc = $builder->buildQueryDescription();
        $this->assertSame(50, $desc['limit']);
        $this->assertSame(100, $desc['offset']);
    }

    public function test_query_builder_limit_clamped(): void
    {
        $builder = EventQueryBuilder::make()->limit(0);
        $desc = $builder->buildQueryDescription();
        $this->assertSame(1, $desc['limit']);

        $builder2 = EventQueryBuilder::make()->limit(99999);
        $desc2 = $builder2->buildQueryDescription();
        $this->assertSame(10000, $desc2['limit']);
    }

    public function test_query_builder_source_filter(): void
    {
        $builder = EventQueryBuilder::make()->source('api');
        $desc = $builder->buildQueryDescription();
        $this->assertSame('api', $desc['source']);
    }

    public function test_query_builder_priority_filter(): void
    {
        $builder = EventQueryBuilder::make()->priority('critical');
        $desc = $builder->buildQueryDescription();
        $this->assertSame('critical', $desc['priority']);
    }

    public function test_query_builder_session_id_filter(): void
    {
        $builder = EventQueryBuilder::make()->sessionId('sess-abc');
        $desc = $builder->buildQueryDescription();
        $this->assertSame('sess-abc', $desc['session_id']);
    }

    public function test_query_builder_with_schema_flag(): void
    {
        $builder = EventQueryBuilder::make()->withSchema();
        $desc = $builder->buildQueryDescription();
        $this->assertTrue($desc['with_schema']);
    }

    public function test_query_builder_to_filters(): void
    {
        $builder = EventQueryBuilder::make()
            ->name('purchase')
            ->clientId('c1')
            ->source('api');

        $filters = $builder->toFilters();
        $this->assertSame(['purchase'], $filters['event_names']);
        $this->assertSame('c1', $filters['client_id']);
        $this->assertSame('api', $filters['source']);
        $this->assertArrayNotHasKey('user_id', $filters);
    }

    public function test_query_builder_to_filters_empty(): void
    {
        $builder = EventQueryBuilder::make();
        $filters = $builder->toFilters();
        $this->assertEmpty($filters);
    }

    public function test_query_builder_get_returns_structure(): void
    {
        $builder = EventQueryBuilder::make()->name('purchase')->limit(10);
        $result = $builder->get();

        $this->assertArrayHasKey('query', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertIsArray($result['results']);
    }

    public function test_query_builder_count_returns_int(): void
    {
        $builder = EventQueryBuilder::make()->name('purchase');
        $count = $builder->count();
        $this->assertIsInt($count);
    }

    public function test_query_builder_first_returns_nullable(): void
    {
        $builder = EventQueryBuilder::make()->name('nonexistent');
        $first = $builder->first();
        $this->assertNull($first);
    }

    public function test_query_builder_chained(): void
    {
        $builder = EventQueryBuilder::make()
            ->name('purchase')
            ->category('ecommerce')
            ->param('currency', 'USD')
            ->clientId('c1')
            ->userId('u1')
            ->source('server')
            ->priority('critical')
            ->limit(25)
            ->offset(50)
            ->orderBy('timestamp', 'desc')
            ->withSchema();

        $desc = $builder->buildQueryDescription();

        $this->assertSame(['purchase'], $desc['event_names']);
        $this->assertSame(['ecommerce'], $desc['categories']);
        $this->assertSame(['currency' => 'USD'], $desc['param_filters']);
        $this->assertSame('c1', $desc['client_id']);
        $this->assertSame('u1', $desc['user_id']);
        $this->assertSame('server', $desc['source']);
        $this->assertSame('critical', $desc['priority']);
        $this->assertSame(25, $desc['limit']);
        $this->assertSame(50, $desc['offset']);
        $this->assertSame(['timestamp' => 'desc'], $desc['order_by']);
        $this->assertTrue($desc['with_schema']);
    }

    public function test_query_builder_offset_clamped(): void
    {
        $builder = EventQueryBuilder::make()->offset(-5);
        $desc = $builder->buildQueryDescription();
        $this->assertSame(0, $desc['offset']);
    }

    // ── Config Registration Assertions ───────────────────────────

    public function test_config_has_property_validation_section(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $this->assertArrayHasKey('property_validation', $config['analytics']);
        $pv = $config['analytics']['property_validation'];
        $this->assertArrayHasKey('enabled', $pv);
        $this->assertArrayHasKey('strict_types', $pv);
        $this->assertArrayHasKey('allow_unknown_params', $pv);
        $this->assertArrayHasKey('enforce_required', $pv);
        $this->assertArrayHasKey('max_param_count', $pv);
        $this->assertArrayHasKey('max_key_length', $pv);
        $this->assertArrayHasKey('max_string_length', $pv);
    }

    public function test_config_has_event_query_section(): void
    {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $this->assertArrayHasKey('event_query', $config['analytics']);
        $eq = $config['analytics']['event_query'];
        $this->assertArrayHasKey('default_limit', $eq);
        $this->assertArrayHasKey('max_limit', $eq);
        $this->assertArrayHasKey('allowed_sort_fields', $eq);
        $this->assertArrayHasKey('cache_results', $eq);
        $this->assertArrayHasKey('cache_ttl', $eq);
    }

    // ── ServiceProvider Registration Assertions ───────────────────

    public function test_service_provider_imports_event_property_type_validator(): void
    {
        $contents = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('use ZeroBoiler\\Analytics\\Services\\EventPropertyTypeValidator;', $contents);
    }

    public function test_service_provider_registers_event_property_type_validator(): void
    {
        $contents = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
        $this->assertStringContainsString('EventPropertyTypeValidator::class', $contents);
        $this->assertStringContainsString('singleton', $contents);
    }

    // ── Route Assertions ──────────────────────────────────────────

    public function test_routes_include_property_validation(): void
    {
        $contents = file_get_contents(__DIR__ . '/../routes/analytics.php');
        $this->assertStringContainsString('property-validation/config', $contents);
        $this->assertStringContainsString('property-validation/validate', $contents);
        $this->assertStringContainsString('property-validation/validate-event', $contents);
    }

    public function test_routes_include_event_query(): void
    {
        $contents = file_get_contents(__DIR__ . '/../routes/analytics.php');
        $this->assertStringContainsString('eventQuery', $contents);
        $this->assertStringContainsString('eventQuerySchema', $contents);
    }

    // ── Namespace & File Location Assertions ─────────────────────

    public function test_event_property_type_validator_namespace(): void
    {
        $reflection = new \ReflectionClass(EventPropertyTypeValidator::class);
        $this->assertSame('ZeroBoiler\\Analytics\\Services', $reflection->getNamespaceName());
    }

    public function test_property_violation_namespace(): void
    {
        $reflection = new \ReflectionClass(PropertyViolation::class);
        $this->assertSame('ZeroBoiler\\Analytics\\Services', $reflection->getNamespaceName());
    }

    public function test_property_validation_result_namespace(): void
    {
        $reflection = new \ReflectionClass(PropertyValidationResult::class);
        $this->assertSame('ZeroBoiler\\Analytics\\Services', $reflection->getNamespaceName());
    }

    public function test_event_query_builder_namespace(): void
    {
        $reflection = new \ReflectionClass(EventQueryBuilder::class);
        $this->assertSame('ZeroBoiler\\Analytics\\Services', $reflection->getNamespaceName());
    }

    public function test_files_in_correct_locations(): void
    {
        $this->assertFileExists(__DIR__ . '/../src/Services/EventPropertyTypeValidator.php');
        $this->assertFileExists(__DIR__ . '/../src/Services/PropertyViolation.php');
        $this->assertFileExists(__DIR__ . '/../src/Services/PropertyValidationResult.php');
        $this->assertFileExists(__DIR__ . '/../src/Services/EventQueryBuilder.php');
    }
}
