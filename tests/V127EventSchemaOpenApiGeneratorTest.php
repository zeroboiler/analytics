<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventSchemaOpenApiGenerator;

test('openapi generator produces valid openapi 3.0 structure', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.openapi', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.api.base_url', '/api/analytics')
        ->andReturn('/api/analytics');

    $generator = new EventSchemaOpenApiGenerator($config);
    $spec = $generator->generate();

    // Root-level keys
    expect($spec)->toHaveKey('openapi')
        ->and($spec['openapi'])->toBe('3.0.3')
        ->and($spec)->toHaveKey('info')
        ->and($spec)->toHaveKey('servers')
        ->and($spec)->toHaveKey('paths')
        ->and($spec)->toHaveKey('components')
        ->and($spec)->toHaveKey('tags');
});

test('openapi info section contains required fields', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.openapi', [])
        ->andReturn([
            'title' => 'My Analytics API',
            'description' => 'Custom description',
            'version' => '2.0.0',
            'contact' => ['name' => 'Acme'],
        ]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.api.base_url', '/api/analytics')
        ->andReturn('/api/v1/analytics');

    $generator = new EventSchemaOpenApiGenerator($config);
    $spec = $generator->generate();

    expect($spec['info']['title'])->toBe('My Analytics API')
        ->and($spec['info']['description'])->toBe('Custom description')
        ->and($spec['info']['version'])->toBe('2.0.0')
        ->and($spec['info']['contact']['name'])->toBe('Acme');

    expect($spec['servers'][0]['url'])->toBe('/api/v1/analytics');
});

test('openapi paths include all core endpoints', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.openapi', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.api.base_url', '/api/analytics')
        ->andReturn('/api/analytics');

    $generator = new EventSchemaOpenApiGenerator($config);
    $spec = $generator->generate();

    // Core event tracking endpoints
    expect($spec['paths'])->toHaveKey('/events')
        ->and($spec['paths'])->toHaveKey('/batch')
        ->and($spec['paths'])->toHaveKey('/identify')
        ->and($spec['paths'])->toHaveKey('/pageview')
        ->and($spec['paths'])->toHaveKey('/consent');

    // Health endpoints
    expect($spec['paths'])->toHaveKey('/health')
        ->and($spec['paths'])->toHaveKey('/ping')
        ->and($spec['paths'])->toHaveKey('/health-check');

    // Catalog endpoints
    expect($spec['paths'])->toHaveKey('/catalog')
        ->and($spec['paths'])->toHaveKey('/catalog/validate')
        ->and($spec['paths'])->toHaveKey('/catalog/stats');

    // Schema endpoints
    expect($spec['paths'])->toHaveKey('/schemas')
        ->and($spec['paths'])->toHaveKey('/schemas/{eventName}')
        ->and($spec['paths'])->toHaveKey('/schemas/validate');

    // Identity endpoints
    expect($spec['paths'])->toHaveKey('/identity/{clientId}')
        ->and($spec['paths'])->toHaveKey('/identity/user/{userId}')
        ->and($spec['paths'])->toHaveKey('/identity/resolve');

    // GDPR endpoints
    expect($spec['paths'])->toHaveKey('/gdpr/export')
        ->and($spec['paths'])->toHaveKey('/data');

    // OpenAPI export endpoints
    expect($spec['paths'])->toHaveKey('/openapi-spec')
        ->and($spec['paths'])->toHaveKey('/openapi.yaml')
        ->and($spec['paths'])->toHaveKey('/openapi/endpoints');
});

test('openapi POST endpoints have request body schema', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.openapi', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.api.base_url', '/api/analytics')
        ->andReturn('/api/analytics');

    $generator = new EventSchemaOpenApiGenerator($config);
    $spec = $generator->generate();

    // POST /events should have requestBody
    expect($spec['paths']['/events']['post'])->toHaveKey('requestBody')
        ->and($spec['paths']['/events']['post']['requestBody']['required'])->toBeTrue();

    // GET /health should NOT have requestBody
    expect($spec['paths']['/health']['get'])->not->toHaveKey('requestBody');
});

test('openapi components include security schemes and error response', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.openapi', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.api.base_url', '/api/analytics')
        ->andReturn('/api/analytics');

    $generator = new EventSchemaOpenApiGenerator($config);
    $spec = $generator->generate();

    $components = $spec['components'];

    expect($components['securitySchemes'])->toHaveKey('bearerAuth')
        ->and($components['securitySchemes'])->toHaveKey('sdkTokenAuth')
        ->and($components['securitySchemes']['bearerAuth']['type'])->toBe('http')
        ->and($components['securitySchemes']['bearerAuth']['scheme'])->toBe('bearer')
        ->and($components['securitySchemes']['sdkTokenAuth']['type'])->toBe('apiKey')
        ->and($components['securitySchemes']['sdkTokenAuth']['in'])->toBe('header');

    // Error response schema
    expect($components['schemas'])->toHaveKey('ErrorResponse');
    expect($components['schemas']['ErrorResponse']['required'])->toContain('error');
});

test('openapi track event request schema has correct structure', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.openapi', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.api.base_url', '/api/analytics')
        ->andReturn('/api/analytics');

    $generator = new EventSchemaOpenApiGenerator($config);
    $spec = $generator->generate();

    $requestSchema = $spec['components']['schemas']['TrackEventRequest'];

    expect($requestSchema['type'])->toBe('object')
        ->and($requestSchema['required'])->toContain('name')
        ->and($requestSchema['properties'])->toHaveKey('name')
        ->and($requestSchema['properties'])->toHaveKey('params')
        ->and($requestSchema['properties'])->toHaveKey('client_id')
        ->and($requestSchema['properties'])->toHaveKey('user_id')
        ->and($requestSchema['properties'])->toHaveKey('timestamp');
});

test('openapi batch event request schema references track event', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.openapi', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.api.base_url', '/api/analytics')
        ->andReturn('/api/analytics');

    $generator = new EventSchemaOpenApiGenerator($config);
    $spec = $generator->generate();

    $batchSchema = $spec['components']['schemas']['BatchEventRequest'];

    expect($batchSchema['required'])->toContain('events')
        ->and($batchSchema['properties']['events']['maxItems'])->toBe(25);
});

test('openapi toJson returns valid json', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.openapi', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.api.base_url', '/api/analytics')
        ->andReturn('/api/analytics');

    $generator = new EventSchemaOpenApiGenerator($config);
    $json = $generator->toJson();

    $decoded = json_decode($json, true);
    expect($decoded)->toBeArray()
        ->and($decoded['openapi'])->toBe('3.0.3')
        ->and($decoded['info']['title'])->toBeString();
});

test('openapi toYaml returns non-empty yaml string', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.openapi', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.api.base_url', '/api/analytics')
        ->andReturn('/api/analytics');

    $generator = new EventSchemaOpenApiGenerator($config);
    $yaml = $generator->toYaml();

    expect($yaml)->toBeString()
        ->and($yaml)->toContain('openapi:')
        ->and($yaml)->toContain('3.0.3')
        ->and($yaml)->toContain('info:')
        ->and($yaml)->toContain('paths:')
        ->and($yaml)->toContain('components:');
});

test('openapi endpoint summary returns flat list with required fields', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.openapi', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.api.base_url', '/api/analytics')
        ->andReturn('/api/analytics');

    $generator = new EventSchemaOpenApiGenerator($config);
    $summary = $generator->endpointSummary();

    expect($summary)->toBeArray()
        ->and($summary)->not->toBeEmpty();

    // Each endpoint should have method, path, description, tags
    foreach ($summary as $endpoint) {
        expect($endpoint)->toHaveKey('method')
            ->and($endpoint)->toHaveKey('path')
            ->and($endpoint)->toHaveKey('description')
            ->and($endpoint)->toHaveKey('tags')
            ->and($endpoint['method'])->toBeIn(['GET', 'POST', 'DELETE'])
            ->and($endpoint['tags'])->toBeArray();
    }
});

test('openapi tags include all expected groups', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.openapi', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.api.base_url', '/api/analytics')
        ->andReturn('/api/analytics');

    $generator = new EventSchemaOpenApiGenerator($config);
    $spec = $generator->generate();

    $tagNames = array_column($spec['tags'], 'name');

    expect($tagNames)->toContain('Events')
        ->and($tagNames)->toContain('Identity')
        ->and($tagNames)->toContain('Consent')
        ->and($tagNames)->toContain('Catalog')
        ->and($tagNames)->toContain('Health')
        ->and($tagNames)->toContain('Reporting')
        ->and($tagNames)->toContain('Schema')
        ->and($tagNames)->toContain('GDPR')
        ->and($tagNames)->toContain('OpenAPI')
        ->and($tagNames)->toContain('Identity Resolution')
        ->and($tagNames)->toContain('Cohorts')
        ->and($tagNames)->toContain('Growth');
});

test('openapi GET endpoints have proper response codes', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.openapi', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.api.base_url', '/api/analytics')
        ->andReturn('/api/analytics');

    $generator = new EventSchemaOpenApiGenerator($config);
    $spec = $generator->generate();

    // GET /health
    $healthGet = $spec['paths']['/health']['get'];
    expect($healthGet['responses'])->toHaveKey('200')
        ->and($healthGet['responses'])->toHaveKey('401')
        ->and($healthGet['responses'])->toHaveKey('429')
        ->and($healthGet['responses'])->toHaveKey('500');

    // POST /events
    $eventsPost = $spec['paths']['/events']['post'];
    expect($eventsPost['responses'])->toHaveKey('200')
        ->and($eventsPost['responses'])->toHaveKey('400')
        ->and($eventsPost['responses'])->toHaveKey('401')
        ->and($eventsPost['responses'])->toHaveKey('422')
        ->and($eventsPost['responses'])->toHaveKey('429')
        ->and($eventsPost['responses'])->toHaveKey('500');
});

test('openapi identify request requires user_id', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.openapi', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.api.base_url', '/api/analytics')
        ->andReturn('/api/analytics');

    $generator = new EventSchemaOpenApiGenerator($config);
    $spec = $generator->generate();

    $identifySchema = $spec['components']['schemas']['IdentifyRequest'];

    expect($identifySchema['required'])->toContain('user_id')
        ->and($identifySchema['properties'])->toHaveKey('user_id')
        ->and($identifySchema['properties'])->toHaveKey('client_id')
        ->and($identifySchema['properties'])->toHaveKey('traits');
});

test('openapi consent request has consent object with purposes', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.openapi', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.api.base_url', '/api/analytics')
        ->andReturn('/api/analytics');

    $generator = new EventSchemaOpenApiGenerator($config);
    $spec = $generator->generate();

    $consentSchema = $spec['components']['schemas']['UpdateConsentRequest'];

    expect($consentSchema['required'])->toContain('consent')
        ->and($consentSchema['properties']['consent']['type'])->toBe('object')
        ->and($consentSchema['properties']['consent']['properties'])->toHaveKey('analytics')
        ->and($consentSchema['properties']['consent']['properties'])->toHaveKey('marketing')
        ->and($consentSchema['properties']['consent']['properties'])->toHaveKey('functional');
});

test('openapi delete data endpoint has 204 response', function (): void {
    $config = mock(ConfigRepository::class);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.openapi', [])
        ->andReturn([]);
    $config->shouldReceive('get')
        ->with('zeroboiler.analytics.api.base_url', '/api/analytics')
        ->andReturn('/api/analytics');

    $generator = new EventSchemaOpenApiGenerator($config);
    $spec = $generator->generate();

    expect($spec['paths']['/data']['delete']['responses'])->toHaveKey('204')
        ->and($spec['paths']['/data']['delete']['responses'])->toHaveKey('401')
        ->and($spec['paths']['/data']['delete']['responses'])->toHaveKey('404');
});
