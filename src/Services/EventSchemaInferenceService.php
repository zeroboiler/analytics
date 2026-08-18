<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Schema\EventPropertySchema;

/**
 * Event Schema Inference Service.
 *
 * Automatically infers event parameter schemas from existing event class
 * constructor signatures and catalog entries. Generates EventPropertySchema
 * definitions that can be registered in the EventSchemaRegistry.
 *
 * Useful for bootstrapping schema validation when migrating from untyped
 * event tracking to schema-validated tracking.
 *
 * @since 1.0.0
 */
final class EventSchemaInferenceService
{
    private EventPropertySchema $schemaBuilder;

    /** @var array<string, array<string, mixed>> */
    private array $inferredSchemas = [];

    /** @var list<string> */
    private array $errors = [];

    public function __construct(EventPropertySchema $schemaBuilder): void
    {
        $this->schemaBuilder = $schemaBuilder;
    }

    /**
     * Infer schemas for all catalog events.
     *
     * Scans event class constructors to extract parameter names and types,
     * then generates typed schema definitions.
     *
     * @return array{schemas: array<string, array<string, mixed>>, inferred_count: int, errors: list<string>}
     */
    public function inferAll(): array
    {
        $this->inferredSchemas = [];
        $this->errors = [];

        foreach (EventCatalog::all() as $name => $entry) {
            $className = $entry['class'] ?? null;

            if ($className === null || !class_exists($className)) {
                continue;
            }

            $schema = $this->inferSchema($name, $className);

            if ($schema !== null) {
                $this->inferredSchemas[$name] = $schema;
            }
        }

        return [
            'schemas' => $this->inferredSchemas,
            'inferred_count' => count($this->inferredSchemas),
            'errors' => $this->errors,
        ];
    }

    /**
     * Infer a schema for a single event class.
     *
     * @param  string  $eventName  Canonical event name
     * @param  class-string  $className  Event class FQCN
     * @return array<string, mixed>|null  Inferred schema or null on failure
     */
    public function inferSchema(string $eventName, string $className): ?array
    {
        try {
            $reflection = new \ReflectionClass($className);
            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                return [
                    'event' => $eventName,
                    'category' => EventCatalog::getCategory($eventName),
                    'params' => [],
                    'source' => 'inferred',
                ];
            }

            $params = [];
            $required = [];

            foreach ($constructor->getParameters() as $param) {
                $paramName = $this->toSnakeCase($param->getName());
                $paramType = $this->resolveParamType($param);
                $hasDefault = $param->isDefaultValueAvailable();
                $defaultValue = $hasDefault ? $param->getDefaultValue() : null;

                $params[$paramName] = [
                    'type' => $paramType,
                    'required' => ! $hasDefault && ! $param->isOptional(),
                    'default' => $defaultValue,
                    'php_type' => $param->getType()?->getName() ?? 'mixed',
                ];

                if (! $hasDefault && ! $param->isOptional()) {
                    $required[] = $paramName;
                }

                // Infer enum values from promoted constructor defaults or docblocks
                $enumValues = $this->inferEnumValues($param);
                if ($enumValues !== null) {
                    $params[$paramName]['enum'] = $enumValues;
                }

                // Infer max length for string params
                if ($paramType === 'string') {
                    $params[$paramName]['max_length'] = 500;
                }

                // Infer range for numeric params
                if ($paramType === 'number') {
                    $params[$paramName]['min'] = null;
                    $params[$paramName]['max'] = null;
                }
            }

            // Add common params that all events should have
            $commonParams = $this->commonParams();
            $mergedParams = array_merge($commonParams, $params);

            return [
                'event' => $eventName,
                'category' => EventCatalog::getCategory($eventName),
                'params' => $mergedParams,
                'required' => array_merge(['name'], $required),
                'source' => 'inferred',
            ];
        } catch (\Throwable $e) {
            $this->errors[] = "Failed to infer schema for '{$eventName}': {$e->getMessage()}";

            return null;
        }
    }

    /**
     * Get inferred schemas.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getSchemas(): array
    {
        return $this->inferredSchemas;
    }

    /**
     * Get inference errors.
     *
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Generate a PHPStan-style PHPDoc for an inferred schema.
     *
     * @return string
     */
    public function generatePhpDoc(string $eventName): string
    {
        $schema = $this->inferredSchemas[$eventName] ?? null;

        if ($schema === null) {
            return "/**\n * Event: {$eventName}\n * Schema: not inferred\n */";
        }

        $lines = ["/**", " * Event: {$eventName}", " * Category: " . ($schema['category'] ?? 'unknown'), ' *'];

        foreach ($schema['params'] ?? [] as $paramName => $paramDef) {
            $type = $paramDef['type'] ?? 'mixed';
            $required = $paramDef['required'] ?? false;
            $description = $paramDef['php_type'] ?? $type;
            $optional = $required ? '' : '|null';
            $lines[] = " * @param {$type}{$optional} \${$paramName} {$description}";
        }

        $lines[] = ' */';

        return implode("\n", $lines);
    }

    /**
     * Infer the analytics param type from a reflection parameter.
     */
    private function resolveParamType(\ReflectionParameter $param): string
    {
        $type = $param->getType();

        if ($type === null) {
            return 'string';
        }

        $typeName = $type->getName();

        if ($typeName === 'int' || $typeName === 'float') {
            return 'number';
        }

        if ($typeName === 'bool') {
            return 'boolean';
        }

        if ($typeName === 'array') {
            return 'array';
        }

        if ($typeName === 'string' || str_contains($typeName, 'string')) {
            return 'string';
        }

        // Named types that might be enums
        if (enum_exists($typeName)) {
            return 'string';
        }

        return 'string';
    }

    /**
     * Infer enum values from a constructor parameter.
     *
     * @return list<string>|null
     */
    private function inferEnumValues(\ReflectionParameter $param): ?array
    {
        $type = $param->getType();

        if ($type === null) {
            return null;
        }

        $typeName = $type->getName();

        if (enum_exists($typeName)) {
            $cases = [];

            try {
                $reflection = new \ReflectionEnum($typeName);

                foreach ($reflection->getCases() as $case) {
                    $cases[] = strtolower($case->getName());
                }
            } catch (\Throwable) {
                return null;
            }

            return count($cases) > 0 ? $cases : null;
        }

        // Check docblock for @param enum hints
        $doc = $param->getDeclaringFunction()->getDocComment();

        if ($doc === false) {
            return null;
        }

        $paramName = $param->getName();

        if (preg_match("/@param\s+\S+\s+\${$paramName}\s+.*?(?:enum|one of|values?):\s*\[?([^\]]+)\]?/i", $doc, $matches)) {
            $values = array_map('trim', explode(',', $matches[1]));

            return array_filter($values, fn (string $v): bool => $v !== '');
        }

        return null;
    }

    /**
     * Convert camelCase to snake_case.
     */
    private function toSnakeCase(string $input): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $input) ?? $input);
    }

    /**
     * Get common params shared by all events.
     *
     * @return array<string, array<string, mixed>>
     */
    private function commonParams(): array
    {
        return [
            'name' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Event name',
            ],
            'timestamp' => [
                'type' => 'string',
                'required' => false,
                'description' => 'ISO 8601 timestamp',
            ],
            'client_id' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Client tracking ID',
            ],
            'user_id' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Authenticated user ID',
            ],
            'session_id' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Session identifier',
            ],
            'page_location' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Page URL',
                'max_length' => 2048,
            ],
            'page_referrer' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Referrer URL',
                'max_length' => 2048,
            ],
        ];
    }
}
