<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * OpenAPI 3.0 specification generator from the analytics event catalog.
 *
 * Generates machine-readable API documentation describing all analytics
 * API endpoints, event catalog entries (with parameter schemas), request/response
 * formats, authentication methods, and error responses.
 *
 * Supports:
 * - Full OpenAPI 3.0.3 spec output
 * - JSON and YAML export formats
 * - Customizable info/metadata from config
 * - Event parameter schemas from EventSchemaRegistry
 * - Tag-based grouping by endpoint category
 * - Security scheme definitions (Sanctum Bearer, SDK Token)
 *
 * Configuration: `zeroboiler.analytics.openapi`
 *
 * @since 127.0.0
 */
final class EventSchemaOpenApiGenerator
{
    /** @var string OpenAPI specification version */
    private const OPENAPI_VERSION = '3.0.3';

    private string $title;

    private string $description;

    private string $version;

    private string $apiBaseUrl;

    /** @var array<string, mixed> Additional contact/server/externalDocs info */
    private array $info;

    /**
     * @param  ConfigRepository  $config  Analytics configuration
     */
    public function __construct(ConfigRepository $config): void
    {
        $openApiConfig = $config->get('zeroboiler.analytics.openapi', []);
        /** @var array{title?: string, description?: string, version?: string, contact?: array<string, mixed>, license?: array<string, mixed>, external_docs?: array<string, mixed>} $openApiConfig */

        $this->title = (string) ($openApiConfig['title'] ?? 'ZeroBoiler Analytics API');
        $this->description = (string) ($openApiConfig['description'] ?? 'Industry-standard SaaS analytics API for Laravel — event tracking, identity resolution, consent management, and reporting.');
        $this->version = (string) ($openApiConfig['version'] ?? AnalyticsEvent::VERSION);
        $this->apiBaseUrl = (string) ($config->get('zeroboiler.analytics.api.base_url', '/api/analytics'));
        $this->info = [
            'contact' => $openApiConfig['contact'] ?? ['name' => 'ZeroBoiler', 'url' => 'https://zeroboiler.dev'],
            'license' => $openApiConfig['license'] ?? ['name' => 'MIT', 'url' => 'https://opensource.org/licenses/MIT'],
            'externalDocs' => $openApiConfig['external_docs'] ?? null,
        ];
    }

    /**
     * Generate the full OpenAPI 3.0 specification as an associative array.
     *
     * Includes all analytics API endpoints, event schemas, authentication
     * schemes, error response definitions, and tag groupings.
     *
     * @return array<string, mixed> OpenAPI specification array
     */
    public function generate(): array
    {
        return [
            'openapi' => self::OPENAPI_VERSION,
            'info' => $this->buildInfo(),
            'servers' => $this->buildServers(),
            'tags' => $this->buildTags(),
            'paths' => $this->buildPaths(),
            'components' => $this->buildComponents(),
        ];
    }

    /**
     * Generate the OpenAPI spec as a JSON string.
     *
     * @return string JSON-encoded OpenAPI specification
     */
    public function toJson(): string
    {
        return json_encode($this->generate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Generate the OpenAPI spec as a YAML string.
     *
     * Simple YAML serializer — does not require ext-yaml.
     * Handles strings, integers, floats, booleans, null, arrays, and nested objects.
     *
     * @return string YAML-formatted OpenAPI specification
     */
    public function toYaml(): string
    {
        return $this->arrayToYaml($this->generate(), 0);
    }

    /**
     * Generate a simplified API summary (endpoint list with methods and descriptions).
     *
     * Useful for quick documentation overviews, CLI help text, or
     * embedded in admin dashboards.
     *
     * @return list<array{method: string, path: string, description: string, tags: list<string>}>
     */
    public function endpointSummary(): array
    {
        $summary = [];
        $paths = $this->buildPaths();

        foreach ($paths as $path => $methods) {
            /** @var array<string, array<string, mixed>> $methods */
            foreach ($methods as $method => $details) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $summary[] = [
                        'method' => strtoupper($method),
                        'path' => $path,
                        'description' => (string) ($details['description'] ?? $details['summary'] ?? ''),
                        'tags' => (array) ($details['tags'] ?? []),
                    ];
                }
            }
        }

        return $summary;
    }

    // ── Info ───────────────────────────────────────────────────────────

    /**
     * Build the OpenAPI info object.
     *
     * @return array<string, mixed>
     */
    private function buildInfo(): array
    {
        $info = [
            'title' => $this->title,
            'description' => $this->description,
            'version' => $this->version,
        ];

        if (isset($this->info['contact']) && is_array($this->info['contact'])) {
            $info['contact'] = $this->info['contact'];
        }

        if (isset($this->info['license']) && is_array($this->info['license'])) {
            $info['license'] = $this->info['license'];
        }

        return $info;
    }

    // ── Servers ────────────────────────────────────────────────────────

    /**
     * Build the servers array.
     *
     * @return list<array<string, mixed>>
     */
    private function buildServers(): array
    {
        return [
            [
                'url' => $this->apiBaseUrl,
                'description' => 'Analytics API (configured base URL)',
            ],
        ];
    }

    // ── Tags ────────────────────────────────────────────────────────────

    /**
     * Build tag definitions for endpoint grouping.
     *
     * @return list<array<string, mixed>>
     */
    private function buildTags(): array
    {
        return [
            ['name' => 'Events', 'description' => 'Event tracking (track, batch, pageview)'],
            ['name' => 'Identity', 'description' => 'User identity management (identify, link, resolve)'],
            ['name' => 'Consent', 'description' => 'GDPR consent management'],
            ['name' => 'Catalog', 'description' => 'Event catalog and schema documentation'],
            ['name' => 'Health', 'description' => 'Health checks and system status'],
            ['name' => 'Reporting', 'description' => 'Analytics reports, stats, and dashboards'],
            ['name' => 'Funnel', 'description' => 'Funnel analysis and conversion tracking'],
            ['name' => 'DLQ', 'description' => 'Dead letter queue management'],
            ['name' => 'Realtime', 'description' => 'Real-time event aggregation'],
            ['name' => 'KPI', 'description' => 'SaaS key performance indicators'],
            ['name' => 'UTM', 'description' => 'UTM attribution and campaign tracking'],
            ['name' => 'Schema', 'description' => 'Event schema validation and management'],
            ['name' => 'Preferences', 'description' => 'Tracking preferences and opt-in/opt-out'],
            ['name' => 'GDPR', 'description' => 'Data erasure, export, and compliance'],
            ['name' => 'Journey', 'description' => 'User journey timeline and milestones'],
            ['name' => 'Retention', 'description' => 'Retention cohort analysis and stickiness'],
            ['name' => 'Growth', 'description' => 'Growth engine, activation, and velocity metrics'],
            ['name' => 'Revenue', 'description' => 'Revenue intelligence, forecasting, and waterfall'],
            ['name' => 'Benchmarks', 'description' => 'SaaS metrics benchmarks and comparisons'],
            ['name' => 'Governance', 'description' => 'Event governance, naming, quality, and deprecation'],
            ['name' => 'Identity Resolution', 'description' => 'Client ID ↔ user ID identity resolution'],
            ['name' => 'Cohorts', 'description' => 'Behavioral cohort analysis and transitions'],
            ['name' => 'Export', 'description' => 'Data export, warehouse, and CSV'],
            ['name' => 'OpenAPI', 'description' => 'API specification export'],
        ];
    }

    // ── Paths ──────────────────────────────────────────────────────────

    /**
     * Build all API path definitions.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildPaths(): array
    {
        $paths = [];

        // ── Core Event Tracking ───────────────────────────────────
        $paths['/events'] = $this->postPath(
            'Track a single analytics event',
            'Dispatches an analytics event to all configured providers. Requires authentication.',
            ['Events'],
            'TrackEventRequest',
            'TrackEventResponse',
        );

        $paths['/batch'] = $this->postPath(
            'Track multiple analytics events in batch',
            'Dispatches up to 25 events in a single request. Events are processed asynchronously.',
            ['Events'],
            'BatchEventRequest',
            'BatchEventResponse',
        );

        $paths['/identify'] = $this->postPath(
            'Identify or link a user identity',
            'Associates a client tracking ID with an authenticated user ID. Links are persisted in cache.',
            ['Identity'],
            'IdentifyRequest',
            'IdentifyResponse',
        );

        $paths['/pageview'] = $this->postPath(
            'Track a server-side page view',
            'Records a page view event with URL, referrer, and title context.',
            ['Events'],
            'PageViewRequest',
            'TrackEventResponse',
        );

        $paths['/consent'] = $this->postPath(
            'Update user consent preferences',
            'Updates the consent state for analytics purposes. Affects which events are dispatched.',
            ['Consent'],
            'UpdateConsentRequest',
            'ConsentResponse',
        );

        // ── Health & Status ───────────────────────────────────────
        $paths['/health'] = $this->getPath(
            'Health check',
            'Returns the current health status of the analytics pipeline including provider connectivity.',
            ['Health'],
            null,
            'HealthResponse',
        );

        $paths['/ping'] = $this->getPath(
            'Ping (liveness probe)',
            'Simple liveness probe. Returns 200 if the analytics service is running.',
            ['Health'],
            null,
            'PingResponse',
        );

        $paths['/health-check'] = $this->getPath(
            'Comprehensive health check',
            'Deep health analysis including circuit breaker states, queue depth, provider SLA, and readiness score.',
            ['Health'],
            null,
            'HealthCheckResponse',
        );

        // ── Catalog ────────────────────────────────────────────────
        $paths['/catalog'] = $this->getPath(
            'List all events in the catalog',
            'Returns all registered analytics events grouped by category with provider mappings.',
            ['Catalog'],
            null,
            'EventCatalogResponse',
        );

        $paths['/catalog/validate'] = $this->postPath(
            'Validate an event against the catalog',
            'Checks whether an event name exists in the catalog and returns its schema.',
            ['Catalog'],
            'CatalogValidateRequest',
            'CatalogValidateResponse',
        );

        $paths['/catalog/stats'] = $this->getPath(
            'Event catalog statistics',
            'Returns per-category event counts and total catalog size.',
            ['Catalog'],
            null,
            'CatalogStatsResponse',
        );

        // ── Schemas ───────────────────────────────────────────────
        $paths['/schemas'] = $this->getPath(
            'List all event schemas',
            'Returns all registered event parameter schemas.',
            ['Schema'],
            null,
            'SchemaListResponse',
        );

        $paths['/schemas/{eventName}'] = $this->getPath(
            'Get event schema detail',
            'Returns the full parameter schema for a specific event.',
            ['Schema'],
            null,
            'SchemaDetailResponse',
        );

        $paths['/schemas/validate'] = $this->postPath(
            'Validate event parameters against schema',
            'Validates event parameters against the registered schema for an event.',
            ['Schema'],
            'SchemaValidateRequest',
            'SchemaValidateResponse',
        );

        // ── Stats & Reporting ─────────────────────────────────────
        $paths['/stats'] = $this->getPath(
            'Analytics statistics summary',
            'Returns event dispatch counts, provider stats, and recent event activity.',
            ['Reporting'],
            null,
            'StatsResponse',
        );

        $paths['/report'] = $this->getPath(
            'Generate analytics report',
            'Returns a comprehensive analytics report with event counts, trends, and provider breakdown.',
            ['Reporting'],
            null,
            'ReportResponse',
        );

        $paths['/report/summary'] = $this->getPath(
            'Report summary',
            'Returns a condensed analytics report summary.',
            ['Reporting'],
            null,
            'ReportSummaryResponse',
        );

        // ── Identity Resolution ───────────────────────────────────
        $paths['/identity/{clientId}'] = $this->getPath(
            'Look up identity by client ID',
            'Resolves all user IDs linked to a given client tracking ID.',
            ['Identity Resolution'],
            null,
            'IdentityLookupResponse',
        );

        $paths['/identity/user/{userId}'] = $this->getPath(
            'Look up identity by user ID',
            'Resolves all client IDs linked to a given user ID.',
            ['Identity Resolution'],
            null,
            'IdentityUserLookupResponse',
        );

        $paths['/identity/resolve'] = $this->postPath(
            'Resolve identity bidirectionally',
            'Resolves identity links in both directions (client→user and user→client).',
            ['Identity Resolution'],
            'IdentityResolveRequest',
            'IdentityResolveResponse',
        );

        // ── Preferences ───────────────────────────────────────────
        $paths['/preference'] = $this->getPath(
            'Get tracking preferences',
            'Returns the current user tracking preference state.',
            ['Preferences'],
            null,
            'PreferenceResponse',
        );

        $paths['/opt-out'] = $this->postPath(
            'Opt out of analytics tracking',
            'Disables all analytics tracking for the authenticated user.',
            ['Preferences'],
            null,
            'PreferenceResponse',
        );

        $paths['/opt-in'] = $this->postPath(
            'Opt in to analytics tracking',
            'Re-enables analytics tracking for the authenticated user.',
            ['Preferences'],
            null,
            'PreferenceResponse',
        );

        // ── GDPR ─────────────────────────────────────────────────
        $paths['/gdpr/export'] = $this->getPath(
            'GDPR data export (DSAR)',
            'Exports all analytics data associated with the authenticated user.',
            ['GDPR'],
            null,
            'GdprExportResponse',
        );

        $paths['/data'] = $this->deletePath(
            'GDPR data erasure (Article 17)',
            'Permanently deletes all analytics data for the authenticated user.',
            ['GDPR'],
            null,
            'GdprErasureResponse',
        );

        // ── OpenAPI Spec Export ───────────────────────────────────
        $paths['/openapi-spec'] = $this->getPath(
            'Export OpenAPI specification (JSON)',
            'Returns the full OpenAPI 3.0 specification for this API in JSON format.',
            ['OpenAPI'],
            null,
            'OpenApiSpecResponse',
        );

        $paths['/openapi.yaml'] = $this->getPath(
            'Export OpenAPI specification (YAML)',
            'Returns the full OpenAPI 3.0 specification for this API in YAML format.',
            ['OpenAPI'],
            null,
            'OpenApiYamlResponse',
        );

        return $paths;
    }

    // ── Path Builders ──────────────────────────────────────────────────

    /**
     * Build a GET path definition.
     *
     * @param  string  $summary  Operation summary
     * @param  string  $description  Operation description
     * @param  list<string>  $tags  Tag names
     * @param  string|null  $requestBodyRef  Request body schema reference (null = no body)
     * @param  string  $responseRef  Successful response schema reference
     * @return array<string, mixed>
     */
    private function getPath(string $summary, string $description, array $tags, ?string $requestBodyRef, string $responseRef): array
    {
        $path = [
            'get' => [
                'summary' => $summary,
                'description' => $description,
                'tags' => $tags,
                'operationId' => $this->operationId('get', $summary),
                'responses' => [
                    '200' => $this->successResponse($responseRef),
                    '401' => $this->errorResponse('Unauthorized'),
                    '429' => $this->errorResponse('Rate Limited'),
                    '500' => $this->errorResponse('Internal Server Error'),
                ],
            ],
        ];

        return $path;
    }

    /**
     * Build a POST path definition.
     *
     * @param  string  $summary  Operation summary
     * @param  string  $description  Operation description
     * @param  list<string>  $tags  Tag names
     * @param  string|null  $requestBodyRef  Request body schema reference (null = no body)
     * @param  string  $responseRef  Successful response schema reference
     * @return array<string, mixed>
     */
    private function postPath(string $summary, string $description, array $tags, ?string $requestBodyRef, string $responseRef): array
    {
        $path = [
            'post' => [
                'summary' => $summary,
                'description' => $description,
                'tags' => $tags,
                'operationId' => $this->operationId('post', $summary),
                'responses' => [
                    '200' => $this->successResponse($responseRef),
                    '201' => $this->successResponse($responseRef),
                    '400' => $this->errorResponse('Bad Request'),
                    '401' => $this->errorResponse('Unauthorized'),
                    '422' => $this->errorResponse('Validation Error'),
                    '429' => $this->errorResponse('Rate Limited'),
                    '500' => $this->errorResponse('Internal Server Error'),
                ],
            ],
        ];

        if ($requestBodyRef !== null) {
            $path['post']['requestBody'] = [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => "#/components/schemas/{$requestBodyRef}"],
                    ],
                ],
            ];
        }

        return $path;
    }

    /**
     * Build a DELETE path definition.
     *
     * @param  string  $summary  Operation summary
     * @param  string  $description  Operation description
     * @param  list<string>  $tags  Tag names
     * @param  string|null  $requestBodyRef  Request body schema reference (null = no body)
     * @param  string  $responseRef  Successful response schema reference
     * @return array<string, mixed>
     */
    private function deletePath(string $summary, string $description, array $tags, ?string $requestBodyRef, string $responseRef): array
    {
        $path = [
            'delete' => [
                'summary' => $summary,
                'description' => $description,
                'tags' => $tags,
                'operationId' => $this->operationId('delete', $summary),
                'responses' => [
                    '200' => $this->successResponse($responseRef),
                    '204' => ['description' => 'Deleted successfully'],
                    '401' => $this->errorResponse('Unauthorized'),
                    '404' => $this->errorResponse('Not Found'),
                    '500' => $this->errorResponse('Internal Server Error'),
                ],
            ],
        ];

        return $path;
    }

    // ── Response Builders ───────────────────────────────────────────────

    /**
     * Build a successful response reference.
     *
     * @param  string  $ref  Schema reference name
     * @return array<string, mixed>
     */
    private function successResponse(string $ref): array
    {
        return [
            'description' => 'Successful response',
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => "#/components/schemas/{$ref}"],
                ],
            ],
        ];
    }

    /**
     * Build an error response.
     *
     * @param  string  $description  Error description
     * @return array<string, mixed>
     */
    private function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                ],
            ],
        ];
    }

    // ── Operation ID ────────────────────────────────────────────────────

    /**
     * Generate a unique operation ID from method and summary.
     *
     * @param  string  $method  HTTP method
     * @param  string  $summary  Operation summary
     * @return string
     */
    private function operationId(string $method, string $summary): string
    {
        $camel = preg_replace('/[^a-zA-Z0-9]/', '', ucwords($summary));

        return strtolower($method) . ($camel !== null ? $camel : $summary);
    }

    // ── Components (Schemas) ──────────────────────────────────────────

    /**
     * Build the components/schemas section of the OpenAPI spec.
     *
     * Includes request/response schemas for all API endpoints.
     *
     * @return array<string, mixed>
     */
    private function buildComponents(): array
    {
        $components = [
            'securitySchemes' => [
                'bearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'JWT',
                    'description' => 'Laravel Sanctum Bearer token',
                ],
                'sdkTokenAuth' => [
                    'type' => 'apiKey',
                    'in' => 'header',
                    'name' => 'X-ZB-SDK-Token',
                    'description' => 'ZeroBoiler Analytics SDK scope token',
                ],
            ],
        ];

        // Request schemas
        $components['schemas']['TrackEventRequest'] = [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Event name (e.g., purchase, sign_up, page_view)',
                    'maxLength' => 100,
                    'example' => 'purchase',
                ],
                'params' => [
                    'type' => 'object',
                    'description' => 'Event parameters (key-value pairs)',
                    'additionalProperties' => ['type' => 'string'],
                    'example' => ['transaction_id' => 'TXN-123', 'value' => '99.99', 'currency' => 'USD'],
                ],
                'client_id' => [
                    'type' => 'string',
                    'description' => 'Client-side tracking ID (from zb_analytics_id cookie)',
                ],
                'user_id' => [
                    'type' => 'string',
                    'description' => 'Authenticated user ID (auto-populated from auth)',
                ],
                'timestamp' => [
                    'type' => 'string',
                    'format' => 'date-time',
                    'description' => 'Event timestamp (ISO 8601, auto-generated if omitted)',
                ],
            ],
        ];

        $components['schemas']['BatchEventRequest'] = [
            'type' => 'object',
            'required' => ['events'],
            'properties' => [
                'events' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/components/schemas/TrackEventRequest'],
                    'maxItems' => 25,
                    'description' => 'Array of events to dispatch (max 25 per batch)',
                ],
            ],
        ];

        $components['schemas']['IdentifyRequest'] = [
            'type' => 'object',
            'required' => ['user_id'],
            'properties' => [
                'user_id' => [
                    'type' => 'string',
                    'description' => 'Authenticated user ID to identify',
                ],
                'client_id' => [
                    'type' => 'string',
                    'description' => 'Client-side tracking ID to link with user ID',
                ],
                'traits' => [
                    'type' => 'object',
                    'description' => 'Additional user traits (email_hash, name, plan, etc.)',
                    'additionalProperties' => ['type' => 'string'],
                ],
            ],
        ];

        $components['schemas']['PageViewRequest'] = [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'format' => 'uri',
                    'description' => 'Page URL',
                ],
                'referrer' => [
                    'type' => 'string',
                    'format' => 'uri',
                    'description' => 'Referrer URL',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Page title',
                ],
            ],
        ];

        $components['schemas']['UpdateConsentRequest'] = [
            'type' => 'object',
            'required' => ['consent'],
            'properties' => [
                'consent' => [
                    'type' => 'object',
                    'description' => 'Consent state per purpose',
                    'properties' => [
                        'analytics' => ['type' => 'boolean'],
                        'marketing' => ['type' => 'boolean'],
                        'functional' => ['type' => 'boolean'],
                    ],
                ],
            ],
        ];

        $components['schemas']['CatalogValidateRequest'] = [
            'type' => 'object',
            'required' => ['event_name'],
            'properties' => [
                'event_name' => [
                    'type' => 'string',
                    'description' => 'Event name to validate',
                ],
                'params' => [
                    'type' => 'object',
                    'description' => 'Optional event parameters to validate against schema',
                    'additionalProperties' => true,
                ],
            ],
        ];

        $components['schemas']['SchemaValidateRequest'] = [
            'type' => 'object',
            'required' => ['event_name', 'params'],
            'properties' => [
                'event_name' => ['type' => 'string'],
                'params' => ['type' => 'object', 'additionalProperties' => true],
            ],
        ];

        $components['schemas']['IdentityResolveRequest'] = [
            'type' => 'object',
            'properties' => [
                'client_id' => ['type' => 'string'],
                'user_id' => ['type' => 'string'],
            ],
        ];

        // ── Response schemas ────────────────────────────────────
        $components['schemas']['TrackEventResponse'] = $this->baseResponseSchema('Event tracked successfully', [
            'event_name' => ['type' => 'string'],
            'dispatched' => ['type' => 'boolean'],
            'queued' => ['type' => 'boolean'],
        ]);

        $components['schemas']['BatchEventResponse'] = $this->baseResponseSchema('Batch tracked successfully', [
            'count' => ['type' => 'integer'],
            'dispatched' => ['type' => 'integer'],
            'failed' => ['type' => 'integer'],
            'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]);

        $components['schemas']['IdentifyResponse'] = $this->baseResponseSchema('Identity linked successfully', [
            'user_id' => ['type' => 'string'],
            'client_id' => ['type' => 'string'],
            'linked' => ['type' => 'boolean'],
        ]);

        $components['schemas']['ConsentResponse'] = $this->baseResponseSchema('Consent updated successfully', [
            'consent' => ['type' => 'object', 'additionalProperties' => ['type' => 'boolean']],
        ]);

        $components['schemas']['HealthResponse'] = $this->baseResponseSchema('Analytics health status', [
            'status' => ['type' => 'string', 'enum' => ['ok', 'degraded', 'down']],
            'providers' => ['type' => 'object', 'additionalProperties' => ['type' => 'object']],
            'timestamp' => ['type' => 'string', 'format' => 'date-time'],
        ]);

        $components['schemas']['PingResponse'] = [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'example' => 'ok'],
                'timestamp' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ];

        $components['schemas']['HealthCheckResponse'] = $this->baseResponseSchema('Comprehensive health check', [
            'readiness_score' => ['type' => 'number', 'format' => 'float'],
            'circuit_breaker' => ['type' => 'object'],
            'queue_depth' => ['type' => 'integer'],
            'provider_sla' => ['type' => 'object'],
        ]);

        $components['schemas']['EventCatalogResponse'] = $this->baseResponseSchema('Event catalog', [
            'total' => ['type' => 'integer', 'description' => 'Total event count'],
            'categories' => [
                'type' => 'object',
                'description' => 'Events grouped by category',
                'additionalProperties' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'ga4' => ['type' => 'string'],
                            'meta' => ['type' => 'string', 'nullable' => true],
                        ],
                    ],
                ],
            ],
        ]);

        $components['schemas']['CatalogValidateResponse'] = $this->baseResponseSchema('Catalog validation result', [
            'valid' => ['type' => 'boolean'],
            'event_name' => ['type' => 'string'],
            'category' => ['type' => 'string', 'nullable' => true],
            'schema' => ['type' => 'object', 'nullable' => true],
        ]);

        $components['schemas']['CatalogStatsResponse'] = $this->baseResponseSchema('Catalog statistics', [
            'total' => ['type' => 'integer'],
            'by_category' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
        ]);

        $components['schemas']['SchemaListResponse'] = $this->baseResponseSchema('Schema list', [
            'schemas' => ['type' => 'array', 'items' => ['type' => 'object']],
        ]);

        $components['schemas']['SchemaDetailResponse'] = $this->baseResponseSchema('Schema detail', [
            'event_name' => ['type' => 'string'],
            'parameters' => ['type' => 'object'],
        ]);

        $components['schemas']['SchemaValidateResponse'] = $this->baseResponseSchema('Schema validation result', [
            'valid' => ['type' => 'boolean'],
            'errors' => ['type' => 'array', 'items' => ['type' => 'object']],
        ]);

        $components['schemas']['StatsResponse'] = $this->baseResponseSchema('Analytics statistics', [
            'total_events' => ['type' => 'integer'],
            'by_provider' => ['type' => 'object'],
            'by_category' => ['type' => 'object'],
        ]);

        $components['schemas']['ReportResponse'] = $this->baseResponseSchema('Analytics report', [
            'period' => ['type' => 'string'],
            'total_events' => ['type' => 'integer'],
            'top_events' => ['type' => 'array'],
            'provider_breakdown' => ['type' => 'object'],
        ]);

        $components['schemas']['ReportSummaryResponse'] = $this->baseResponseSchema('Report summary', [
            'total_events' => ['type' => 'integer'],
            'unique_users' => ['type' => 'integer'],
            'conversion_rate' => ['type' => 'number'],
        ]);

        $components['schemas']['IdentityLookupResponse'] = $this->baseResponseSchema('Identity lookup', [
            'client_id' => ['type' => 'string'],
            'user_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]);

        $components['schemas']['IdentityUserLookupResponse'] = $this->baseResponseSchema('User identity lookup', [
            'user_id' => ['type' => 'string'],
            'client_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]);

        $components['schemas']['IdentityResolveResponse'] = $this->baseResponseSchema('Identity resolution', [
            'user_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
            'client_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]);

        $components['schemas']['PreferenceResponse'] = $this->baseResponseSchema('Tracking preference', [
            'tracking_enabled' => ['type' => 'boolean'],
        ]);

        $components['schemas']['GdprExportResponse'] = $this->baseResponseSchema('GDPR data export', [
            'events' => ['type' => 'array'],
            'total_records' => ['type' => 'integer'],
        ]);

        $components['schemas']['GdprErasureResponse'] = $this->baseResponseSchema('GDPR data erasure', [
            'deleted' => ['type' => 'boolean'],
            'records_affected' => ['type' => 'integer'],
        ]);

        $components['schemas']['OpenApiSpecResponse'] = [
            'type' => 'object',
            'description' => 'OpenAPI 3.0 specification (JSON)',
        ];

        $components['schemas']['OpenApiYamlResponse'] = [
            'type' => 'string',
            'description' => 'OpenAPI 3.0 specification (YAML)',
        ];

        // ── Error Response ────────────────────────────────────────
        $components['schemas']['ErrorResponse'] = [
            'type' => 'object',
            'required' => ['error'],
            'properties' => [
                'error' => [
                    'type' => 'object',
                    'properties' => [
                        'code' => ['type' => 'string'],
                        'message' => ['type' => 'string'],
                        'details' => [
                            'type' => 'array',
                            'items' => ['type' => 'object', 'additionalProperties' => true],
                        ],
                    ],
                ],
            ],
        ];

        return $components;
    }

    /**
     * Build a standard base response schema.
     *
     * @param  string  $description  Response description
     * @param  array<string, array<string, mixed>>  $extraProps  Additional response properties
     * @return array<string, mixed>
     */
    private function baseResponseSchema(string $description, array $extraProps = []): array
    {
        $schema = [
            'type' => 'object',
            'description' => $description,
            'properties' => [
                'success' => ['type' => 'boolean'],
                'data' => [
                    'type' => 'object',
                    'properties' => $extraProps,
                ],
            ],
        ];

        return $schema;
    }

    // ── YAML Serializer ───────────────────────────────────────────────

    /**
     * Convert a PHP array to a YAML string (simple serializer).
     *
     * Handles strings, integers, floats, booleans, null, arrays (indexed/associative).
     * Does not support multi-line strings, anchors, or tags.
     *
     * @param  mixed  $data  Data to serialize
     * @param  int  $indent  Current indentation level
     * @return string YAML string
     */
    private function arrayToYaml(mixed $data, int $indent): string
    {
        $prefix = str_repeat('  ', $indent);

        if ($data === null) {
            return 'null';
        }

        if (is_bool($data)) {
            return $data ? 'true' : 'false';
        }

        if (is_int($data) || is_float($data)) {
            return (string) $data;
        }

        if (is_string($data)) {
            // Quote strings that contain special characters
            if (preg_match('/[:#{}[\],&*?|>!%@`\'"]/', $data) || $data === '' || $data === 'true' || $data === 'false' || $data === 'null') {
                $escaped = str_replace("'", "''", $data);

                return "'{$escaped}'";
            }

            return $data;
        }

        if (is_array($data)) {
            $lines = [];
            $isSequential = array_is_list($data);

            if ($isSequential && count($data) === 0) {
                return '[]';
            }

            foreach ($data as $key => $value) {
                if ($isSequential) {
                    $lines[] = $prefix . '- ' . $this->arrayToYaml($value, $indent + 1);
                } else {
                    $keyStr = is_int($key) ? (string) $key : $key;

                    if (is_array($value) && ! empty($value)) {
                        $lines[] = $prefix . $keyStr . ':';
                        $lines[] = $this->arrayToYaml($value, $indent + 1);
                    } elseif (is_array($value) && empty($value)) {
                        $lines[] = $prefix . $keyStr . ': []';
                    } else {
                        $lines[] = $prefix . $keyStr . ': ' . $this->arrayToYaml($value, $indent + 1);
                    }
                }
            }

            return implode("\n", $lines);
        }

        return var_export($data, true);
    }
}
