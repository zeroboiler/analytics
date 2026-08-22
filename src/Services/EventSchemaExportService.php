<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Schema\EventParameterSchemas;

/**
 * Event schema export service — generates industry-standard schema artifacts
 * from the event catalog for downstream consumers.
 *
 * Produces:
 * - JSON Schema (Draft 2020-12) for event validation
 * - TypeScript type definitions for JS/TS consumers
 * - OpenAPI 3.1 operation definitions for API documentation
 *
 * @since 9.8.0
 */
final class EventSchemaExportService
{
    private EventParameterSchemas $parameterSchemas;

    public function __construct(EventParameterSchemas $parameterSchemas){
        $this->parameterSchemas = $parameterSchemas;
    }

    /**
     * Export the full event catalog as JSON Schema.
     *
     * Returns a Draft 2020-12 JSON Schema that validates event payloads
     * against the event catalog. Includes definitions for each event type
     * with required/optional parameters and their types.
     *
     * @return array<string, mixed>
     */
    public function exportJsonSchema(): array
    {
        $allEvents = EventCatalog::all();
        $definitions = [];
        $eventNames = [];

        foreach ($allEvents as $name => $entry) {
            $schema = $this->parameterSchemas->getSchema($name);

            if ($schema !== null) {
                $definitions[$this->toDefinitionName($name)] = $this->buildSchemaDefinition($name, $schema);
            } else {
                $definitions[$this->toDefinitionName($name)] = $this->buildGenericDefinition($name, $entry);
            }

            $eventNames[] = $name;
        }

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://zeroboiler.dev/schemas/analytics-events.json',
            'title' => 'ZeroBoiler Analytics Events',
            'description' => 'JSON Schema validation for ZeroBoiler Analytics event payloads. Generated from the event catalog.',
            'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'enum' => $eventNames,
                    'description' => 'The event name from the ZeroBoiler Analytics catalog.',
                ],
                'params' => [
                    'type' => 'object',
                    'description' => 'Event parameters. Schema depends on the event name.',
                    'oneOf' => array_values(array_map(
                        fn (string $name): array => [
                            '$ref' => '#/$defs/' . $this->toDefinitionName($name),
                        ],
                        $eventNames,
                    )),
                ],
                'client_id' => [
                    'type' => ['string', 'null'],
                    'description' => 'Server-generated client identifier (UUID).',
                ],
                'user_id' => [
                    'type' => ['string', 'null'],
                    'description' => 'Authenticated user identifier.',
                ],
                'timestamp' => [
                    'type' => ['string', 'null'],
                    'format' => 'date-time',
                    'description' => 'ISO 8601 event timestamp.',
                ],
                'priority' => [
                    'type' => 'string',
                    'enum' => ['critical', 'normal', 'low', 'background'],
                    'default' => 'normal',
                    'description' => 'Event priority for dispatch ordering.',
                ],
            ],
            'required' => ['name'],
            'additionalProperties' => true,
            '$defs' => $definitions,
        ];
    }

    /**
     * Export the full event catalog as TypeScript type definitions.
     *
     * Generates a .d.ts-compatible TypeScript file with interfaces for
     * every event in the catalog, a union type for event names, and
     * helper types for common event patterns.
     *
     * @return string TypeScript source code
     */
    public function exportTypeScript(): string
    {
        $lines = [
            '/**',
            ' * ZeroBoiler Analytics — Auto-Generated Event Type Definitions',
            ' *',
            ' * Generated from the ZeroBoiler Analytics event catalog.',
            ' * DO NOT EDIT — regenerate with EventSchemaExportService::exportTypeScript()',
            ' *',
            ' * @version ' . \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
            ' */',
            '',
        ];

        // Event name union type
        $allEvents = EventCatalog::all();
        $eventNames = array_keys($allEvents);
        $nameLiterals = array_map(
            fn (string $name): string => "    '{$name}'",
            $eventNames,
        );

        $lines[] = '/** All event names in the ZeroBoiler Analytics catalog. */';
        $lines[] = 'export type ZbEventName =';
        $lines[] = implode(" |\n", $nameLiterals) . ';';
        $lines[] = '';

        // Event name by category
        $byCategory = EventCatalog::byCategory();
        foreach ($byCategory as $category => $events) {
            $categoryName = ucfirst($category);
            $catLiterals = array_map(
                fn (string $name): string => "    '{$name}'",
                array_keys($events),
            );

            $lines[] = "/** {$categoryName} event names. */";
            $lines[] = "export type Zb{$categoryName}EventName =";
            $lines[] = implode(" |\n", $catLiterals) . ';';
            $lines[] = '';
        }

        // Event interfaces
        $lines[] = '/** Base analytics event payload. */';
        $lines[] = 'export interface ZbEvent {';
        $lines[] = '  name: ZbEventName;';
        $lines[] = '  params?: Record<string, unknown>;';
        $lines[] = '  client_id?: string | null;';
        $lines[] = '  user_id?: string | null;';
        $lines[] = '  timestamp?: string | null;';
        $lines[] = '  priority?: ZbEventPriority;';
        $lines[] = '}';
        $lines[] = '';

        $lines[] = "export type ZbEventPriority = 'critical' | 'normal' | 'low' | 'background';";
        $lines[] = '';

        // Per-event interfaces with typed params
        foreach ($allEvents as $name => $entry) {
            $schema = $this->parameterSchemas->getSchema($name);
            $interfaceName = $this->toTypeName($name);

            $lines[] = "/**";
            $lines[] = " * {$entry['ga4']} event — category: {$entry['category']}";
            $lines[] = " * GA4: {$entry['ga4']}";
            if (! empty($entry['meta'])) {
                $lines[] = " * Meta Pixel: {$entry['meta']}";
            }
            if (! empty($entry['posthog'])) {
                $lines[] = " * PostHog: {$entry['posthog']}";
            }
            $lines[] = ' */';
            $lines[] = "export interface Zb{$interfaceName}Event extends ZbEvent {";
            $lines[] = "  name: '{$name}';";

            if ($schema !== null) {
                $lines[] = '  params?: {';
                foreach ($schema as $paramName => $paramDef) {
                    $tsType = $this->toTsType($paramDef);
                    $optional = ($paramDef['required'] ?? false) ? '' : '?';
                    $lines[] = "    {$paramName}{$optional}: {$tsType};";
                }
                $lines[] = '  };';
            }

            $lines[] = '}';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Export OpenAPI 3.1 operation definitions for the analytics event endpoints.
     *
     * Returns an array of OpenAPI operation objects that can be merged into
     * an existing OpenAPI specification for API documentation.
     *
     * @return array<string, array<string, mixed>>
     */
    public function exportOpenApi(): array
    {
        $operations = [];
        $allEvents = EventCatalog::all();
        $eventNames = array_keys($allEvents);

        // POST /api/analytics/events — Track a single event
        $operations['post_/api/analytics/events'] = [
            'operationId' => 'trackAnalyticsEvent',
            'summary' => 'Track a single analytics event',
            'tags' => ['Analytics Events'],
            'security' => [['sanctum' => []]],
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['name'],
                            'properties' => [
                                'name' => [
                                    'type' => 'string',
                                    'enum' => $eventNames,
                                    'description' => 'Event name from the catalog.',
                                ],
                                'params' => [
                                    'type' => 'object',
                                    'description' => 'Event parameters.',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'Event tracked successfully.',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'status' => ['type' => 'string', 'example' => 'ok'],
                                    'event' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
                '422' => ['$ref' => '#/components/responses/Validation'],
            ],
        ];

        // POST /api/analytics/batch — Track multiple events
        $operations['post_/api/analytics/batch'] = [
            'operationId' => 'batchAnalyticsEvents',
            'summary' => 'Track multiple analytics events in a batch',
            'tags' => ['Analytics Events'],
            'security' => [['sanctum' => []]],
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'required' => ['events'],
                            'properties' => [
                                'events' => [
                                    'type' => 'array',
                                    'maxItems' => 50,
                                    'items' => [
                                        'type' => 'object',
                                        'required' => ['name'],
                                        'properties' => [
                                            'name' => [
                                                'type' => 'string',
                                                'enum' => $eventNames,
                                            ],
                                            'params' => ['type' => 'object'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'Batch tracked successfully.',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'status' => ['type' => 'string', 'example' => 'ok'],
                                    'count' => ['type' => 'integer'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // GET /api/analytics/catalog — Event catalog
        $operations['get_/api/analytics/catalog'] = [
            'operationId' => 'getEventCatalog',
            'summary' => 'Get the full analytics event catalog',
            'tags' => ['Analytics Events'],
            'responses' => [
                '200' => [
                    'description' => 'Event catalog with all categories and provider mappings.',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'total' => ['type' => 'integer', 'example' => count($allEvents)],
                                    'categories' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'ecommerce' => ['type' => 'object'],
                                            'saas' => ['type' => 'object'],
                                            'engagement' => ['type' => 'object'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return $operations;
    }

    /**
     * Convert an event name to a JSON Schema definition name.
     */
    private function toDefinitionName(string $name): string
    {
        return str_replace('.', '_', $name) . '_event';
    }

    /**
     * Convert an event name to a PascalCase TypeScript type name.
     */
    private function toTypeName(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
    }

    /**
     * Build a JSON Schema definition from parameter schema.
     *
     * @param  string  $eventName
     * @param  array<string, array{type?: string, required?: bool, description?: string}>  $schema
     * @return array<string, mixed>
     */
    private function buildSchemaDefinition(string $eventName, array $schema): array
    {
        $properties = [];
        $required = [];

        foreach ($schema as $paramName => $paramDef) {
            $properties[$paramName] = $this->buildPropertySchema($paramName, $paramDef);

            if (($paramDef['required'] ?? false)) {
                $required[] = $paramName;
            }
        }

        $definition = [
            'type' => 'object',
            'description' => "Parameters for the '{$eventName}' event.",
            'properties' => $properties,
        ];

        if ($required !== []) {
            $definition['required'] = $required;
        }

        return $definition;
    }

    /**
     * Build a generic schema definition from a catalog entry (no parameter schema).
     *
     * @param  string  $name
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function buildGenericDefinition(string $name, array $entry): array
    {
        return [
            'type' => 'object',
            'description' => "Parameters for the '{$name}' event ({$entry['ga4']}, category: {$entry['category']}).",
            'properties' => [],
        ];
    }

    /**
     * Build a JSON Schema property definition.
     *
     * @param  string  $name
     * @param  array{type?: string, description?: string}  $def
     * @return array<string, mixed>
     */
    private function buildPropertySchema(string $name, array $def): array
    {
        $type = $def['type'] ?? 'string';
        $jsonType = match ($type) {
            'int', 'integer' => 'integer',
            'float', 'double', 'number' => 'number',
            'bool', 'boolean' => 'boolean',
            'array' => 'array',
            default => 'string',
        };

        $property = ['type' => $jsonType];

        if (isset($def['description']) && $def['description'] !== '') {
            $property['description'] = $def['description'];
        }

        return $property;
    }

    /**
     * Convert a parameter schema type to TypeScript type.
     *
     * @param  array{type?: string, required?: bool}  $def
     */
    private function toTsType(array $def): string
    {
        $type = $def['type'] ?? 'string';

        return match ($type) {
            'int', 'integer', 'float', 'double', 'number' => 'number',
            'bool', 'boolean' => 'boolean',
            'array' => 'unknown[]',
            default => 'string',
        };
    }
}
