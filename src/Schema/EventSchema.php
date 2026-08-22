<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Schema;

/**
 * Defines the schema for a single analytics event.
 *
 * Schemas declare required and optional parameters, their types,
 * and maximum string lengths. Used by EventSchemaRegistry for
 * automatic event validation and sanitization.
 *
 * @since 1.0.0
 */
final readonly class EventSchema
{
    /**
     * @param  string  $name  Event name (e.g. 'purchase', 'sign_up')
     * @param  string  $category  Event category (ecommerce, saas, engagement, custom)
     * @param  array<string, EventParam>  $requiredParams  Parameters that must be present
     * @param  array<string, EventParam>  $optionalParams  Parameters that may be present
     * @param  string  $description  Human-readable description
     * @param  array<string, mixed>  $providerMapping  Map of provider => event name override
     */
    public function __construct(
        public string $name,
        public string $category,
        public array $requiredParams = [],
        public array $optionalParams = [],
        public string $description = '',
        public array $providerMapping = [],
    ) {}

    /**
     * Validate an event payload against this schema.
     *
     * @param  array<string, mixed>  $params  Event parameters to validate
     * @return array{valid: bool, errors: array<int, string>, sanitized: array<string, mixed>}
     */
    public function validate(array $params): array
    {
        $errors = [];
        $sanitized = [];

        // Check required params
        foreach ($this->requiredParams as $paramName => $paramSchema) {
            $value = $params[$paramName] ?? null;

            if ($value === null || $value === '') {
                $errors[] = "Missing required parameter: {$paramName}";
                continue;
            }

            $typeCheck = $paramSchema->validateType($value);
            if ($typeCheck !== null) {
                $errors[] = $typeCheck;
            }

            $sanitized[$paramName] = $paramSchema->sanitize($value);
        }

        // Sanitize optional params
        foreach ($this->optionalParams as $paramName => $paramSchema) {
            if (! array_key_exists($paramName, $params)) {
                continue;
            }

            $value = $params[$paramName];

            if ($value !== null) {
                $typeCheck = $paramSchema->validateType($value);
                if ($typeCheck !== null) {
                    $errors[] = $typeCheck;
                }

                $sanitized[$paramName] = $paramSchema->sanitize($value);
            }
        }

        // Pass through any extra params not in schema (for extensibility)
        foreach ($params as $key => $value) {
            if (! isset($sanitized[$key]) && ! isset($this->requiredParams[$key])) {
                $sanitized[$key] = $value;
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'sanitized' => $sanitized,
        ];
    }

    /**
     * Get the provider-specific event name (fallback to schema name).
     */
    public function getProviderEventName(string $provider): string
    {
        return $this->providerMapping[$provider] ?? $this->name;
    }

    /**
     * Get all parameter names (required + optional).
     *
     * @return array<int, string>
     */
    public function getAllParamNames(): array
    {
        return array_merge(
            array_keys($this->requiredParams),
            array_keys($this->optionalParams),
        );
    }
}
