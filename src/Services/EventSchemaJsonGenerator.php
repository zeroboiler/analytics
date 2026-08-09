<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Generates JSON Schema representations of the event catalog.
 *
 * Produces machine-readable schemas that frontend clients can use for
 * runtime validation of event payloads before dispatch. Supports
 * generating schemas for individual events, categories, or the entire catalog.
 *
 * Output follows JSON Schema Draft 2020-12 format.
 *
 * @see https://json-schema.org/draft/2020-12/json-schema-validation.html
 * @version 5.9.0
 *
 * @since 1.0.0
 */
final class EventSchemaJsonGenerator
{
    /**
     * Generate a JSON Schema for the entire event catalog.
     *
     * Produces a schema with a `oneOf` array containing schemas for each
     * registered event, allowing frontend validation of any event.
     *
     * @return array<string, mixed>
     */
    public function generateCatalogSchema(): array
    {
        $eventSchemas = [];

        foreach (EventCatalog::all() as $name => $entry) {
            $eventSchemas[] = $this->generateEventSchema($name);
        }

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://zeroboiler.dev/analytics/event-catalog.schema.json',
            'title' => 'ZeroBoiler Analytics Event Catalog',
            'description' => 'JSON Schema for validating ZeroBoiler analytics events',
            'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
            'type' => 'object',
            'required' => ['name', 'params'],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Canonical event name from the ZeroBoiler catalog',
                    'enum' => EventCatalog::names(),
                ],
                'params' => [
                    'type' => 'object',
                    'description' => 'Event parameters (validated per-event)',
                ],
                'client_id' => [
                    'type' => ['string', 'null'],
                    'description' => 'Client-side tracking ID (UUID v4)',
                    'format' => 'uuid',
                ],
                'user_id' => [
                    'type' => ['string', 'null'],
                    'description' => 'Authenticated user ID',
                ],
                'timestamp' => [
                    'type' => ['integer', 'null'],
                    'description' => 'Unix timestamp of the event',
                ],
                'priority' => [
                    'type' => ['string', 'null'],
                    'description' => 'Event dispatch priority',
                    'enum' => ['critical', 'normal', 'low', 'background'],
                ],
            ],
            'additionalProperties' => true,
            'definitions' => $this->buildDefinitions(),
        ];
    }

    /**
     * Generate a JSON Schema for a specific event by name.
     *
     * Includes provider mappings, category, and typed parameter hints
     * based on the event's role in the catalog.
     *
     * @param  string  $eventName  Canonical event name
     * @return array<string, mixed> JSON Schema for this event
     */
    public function generateEventSchema(string $eventName): array
    {
        $entry = EventCatalog::get($eventName);

        if ($entry === null) {
            return [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'description' => "Unknown event: {$eventName}",
                'properties' => [
                    'name' => ['const' => $eventName],
                    'params' => ['type' => 'object'],
                ],
            ];
        }

        $category = $entry['category'] ?? 'unknown';

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => $eventName,
            'description' => "{$category} analytics event",
            'type' => 'object',
            'required' => ['name', 'params'],
            'properties' => [
                'name' => ['const' => $eventName],
                'params' => [
                    'type' => 'object',
                    'description' => "Parameters for {$eventName}",
                    'properties' => $this->inferParamProperties($eventName, $category),
                    'additionalProperties' => true,
                ],
                'client_id' => [
                    'type' => ['string', 'null'],
                    'format' => 'uuid',
                ],
                'user_id' => [
                    'type' => ['string', 'null'],
                ],
            ],
            '_zb' => [
                'ga4' => $entry['ga4'] ?? null,
                'meta' => $entry['meta'] ?? null,
                'posthog' => $entry['posthog'] ?? null,
                'plausible' => $entry['plausible'] ?? null,
                'category' => $category,
            ],
        ];
    }

    /**
     * Generate JSON Schema for all events in a specific category.
     *
     * @param  'ecommerce'|'saas'|'engagement'  $category
     * @return array<string, mixed>
     */
    public function generateCategorySchema(string $category): array
    {
        $events = EventCatalog::category($category);
        $eventNames = array_keys($events);

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => ucfirst($category) . ' Events',
            'description' => "All {$category} analytics events in the ZeroBoiler catalog",
            'type' => 'object',
            'required' => ['name', 'params'],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'enum' => $eventNames,
                    'description' => "Event name (must be one of: " . implode(', ', $eventNames) . ')',
                ],
                'params' => [
                    'type' => 'object',
                    'description' => 'Event parameters',
                ],
            ],
            'definitions' => $this->buildDefinitions(),
        ];
    }

    /**
     * Generate a minimal schema for client-side event name validation.
     *
     * Lightweight schema containing only event name enums — useful for
     * client-side type checking without parameter validation overhead.
     *
     * @return array<string, mixed>
     */
    public function generateEventNamesSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'ZeroBoiler Event Names',
            'description' => 'Valid event names for ZeroBoiler Analytics',
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'enum' => EventCatalog::names(),
                ],
            ],
        ];
    }

    /**
     * Generate a provider mapping table as a structured array.
     *
     * Returns a mapping of every catalog event to its provider-specific
     * event names, suitable for client-side event name translation.
     *
     * @return array{ga4: array<string, string>, meta: array<string, string|null>, posthog: array<string, string>, plausible: array<string, string|null>}
     */
    public function generateProviderMappingTable(): array
    {
        $ga4 = [];
        $meta = [];
        $posthog = [];
        $plausible = [];

        foreach (EventCatalog::all() as $name => $entry) {
            $ga4[$name] = $entry['ga4'] ?? $name;
            $meta[$name] = $entry['meta'] ?? null;
            $posthog[$name] = $entry['posthog'] ?? $name;
            $plausible[$name] = $entry['plausible'] ?? null;
        }

        return [
            'ga4' => $ga4,
            'meta' => $meta,
            'posthog' => $posthog,
            'plausible' => $plausible,
        ];
    }

    /**
     * Export the catalog schema as a JSON string.
     *
     * @param  int  $flags  json_encode flags (default: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
     * @return string
     */
    public function toJson(int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES): string
    {
        return json_encode($this->generateCatalogSchema(), $flags | JSON_THROW_ON_ERROR);
    }

    /**
     * Export a single event schema as JSON string.
     *
     * @param  string  $eventName
     * @param  int  $flags
     * @return string
     */
    public function eventToJson(string $eventName, int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES): string
    {
        return json_encode($this->generateEventSchema($eventName), $flags | JSON_THROW_ON_ERROR);
    }

    /**
     * Infer parameter property schemas based on event name and category.
     *
     * Provides typed hints for well-known events (purchase, sign_up, etc.)
     * based on the event's semantic role. Unknown events get a generic schema.
     *
     * @param  string  $eventName
     * @param  string  $category
     * @return array<string, array<string, mixed>>
     */
    private function inferParamProperties(string $eventName, string $category): array
    {
        $params = match ($eventName) {
            // E-commerce events
            'purchase' => [
                'transaction_id' => ['type' => 'string', 'description' => 'Unique transaction identifier'],
                'value' => ['type' => 'number', 'minimum' => 0, 'description' => 'Total revenue'],
                'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$', 'description' => 'ISO 4217 currency code'],
                'tax' => ['type' => 'number', 'minimum' => 0, 'description' => 'Tax amount'],
                'shipping' => ['type' => 'number', 'minimum' => 0, 'description' => 'Shipping cost'],
                'coupon' => ['type' => 'string', 'description' => 'Coupon code used'],
                'affiliation' => ['type' => 'string', 'description' => 'Store affiliation'],
                'items' => ['type' => 'array', 'description' => 'Line items', 'items' => ['$ref' => '#/definitions/item']],
            ],
            'refund' => [
                'transaction_id' => ['type' => 'string', 'description' => 'Original transaction ID'],
                'value' => ['type' => 'number', 'minimum' => 0, 'description' => 'Refund amount'],
                'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'],
                'items' => ['type' => 'array', 'items' => ['$ref' => '#/definitions/item']],
            ],
            'add_to_cart' => [
                'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'],
                'value' => ['type' => 'number', 'minimum' => 0],
                'items' => ['type' => 'array', 'items' => ['$ref' => '#/definitions/item']],
            ],
            'view_item' => [
                'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'],
                'value' => ['type' => 'number', 'minimum' => 0],
                'items' => ['type' => 'array', 'items' => ['$ref' => '#/definitions/item']],
            ],
            // SaaS events
            'sign_up' => [
                'method' => ['type' => 'string', 'description' => 'Registration method (email, google, github)'],
                'referral' => ['type' => 'string', 'description' => 'Referral source'],
                'plan' => ['type' => 'string', 'description' => 'Selected plan'],
            ],
            'login' => [
                'method' => ['type' => 'string', 'description' => 'Login method'],
                'success' => ['type' => 'boolean', 'description' => 'Whether login succeeded'],
            ],
            'start_trial' => [
                'plan' => ['type' => 'string', 'description' => 'Trial plan name'],
                'trial_days' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Trial duration in days'],
            ],
            'subscribe', 'subscription_created' => [
                'plan' => ['type' => 'string', 'description' => 'Subscription plan'],
                'revenue' => ['type' => 'number', 'minimum' => 0, 'description' => 'Monthly revenue'],
                'billing_cycle' => ['type' => 'string', 'enum' => ['monthly', 'yearly', 'lifetime'], 'description' => 'Billing frequency'],
            ],
            'plan_upgrade' => [
                'from_plan' => ['type' => 'string', 'description' => 'Previous plan'],
                'to_plan' => ['type' => 'string', 'description' => 'New plan'],
            ],
            'cancellation' => [
                'reason' => ['type' => 'string', 'description' => 'Cancellation reason'],
                'plan' => ['type' => 'string', 'description' => 'Cancelled plan'],
            ],
            // Engagement events
            'page_view' => [
                'page_title' => ['type' => 'string', 'description' => 'Page title'],
                'page_location' => ['type' => 'string', 'format' => 'uri', 'description' => 'Page URL'],
                'page_referrer' => ['type' => 'string', 'format' => 'uri', 'description' => 'Referrer URL'],
            ],
            'scroll_depth' => [
                'percent' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'description' => 'Scroll percentage'],
                'page_location' => ['type' => 'string', 'format' => 'uri'],
            ],
            'search' => [
                'search_term' => ['type' => 'string', 'description' => 'Search query'],
                'results_count' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Number of results'],
            ],
            'form_submit' => [
                'form_id' => ['type' => 'string', 'description' => 'Form identifier'],
                'form_name' => ['type' => 'string', 'description' => 'Form name'],
            ],
            'error' => [
                'error_message' => ['type' => 'string', 'description' => 'Error message'],
                'error_code' => ['type' => 'string', 'description' => 'Error code'],
                'fatal' => ['type' => 'boolean', 'description' => 'Whether the error is fatal'],
            ],
            default => [],
        };

        // Add common params based on category
        if ($category === 'saas' && ! isset($params['user_id'])) {
            $params['user_id'] = ['type' => 'string', 'description' => 'User ID (when available from server)'];
        }

        return $params;
    }

    /**
     * Build shared definitions referenced by event schemas.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildDefinitions(): array
    {
        return [
            'item' => [
                'type' => 'object',
                'description' => 'E-commerce line item (GA4 format)',
                'required' => ['item_id', 'price', 'quantity'],
                'properties' => [
                    'item_id' => ['type' => 'string', 'description' => 'Product/SKU ID'],
                    'item_name' => ['type' => 'string', 'description' => 'Product name'],
                    'item_category' => ['type' => 'string', 'description' => 'Product category'],
                    'item_variant' => ['type' => 'string', 'description' => 'Product variant'],
                    'item_brand' => ['type' => 'string', 'description' => 'Product brand'],
                    'price' => ['type' => 'number', 'minimum' => 0, 'description' => 'Unit price'],
                    'quantity' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Quantity'],
                    'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$', 'description' => 'Currency code'],
                ],
                'additionalProperties' => true,
            ],
            'consent_state' => [
                'type' => 'object',
                'description' => 'GDPR consent state',
                'properties' => [
                    'ad_storage' => ['type' => 'string', 'enum' => ['granted', 'denied']],
                    'analytics_storage' => ['type' => 'string', 'enum' => ['granted', 'denied']],
                    'ad_user_data' => ['type' => 'string', 'enum' => ['granted', 'denied']],
                    'ad_personalization' => ['type' => 'string', 'enum' => ['granted', 'denied']],
                    'functionality_storage' => ['type' => 'string', 'enum' => ['granted', 'denied']],
                    'personalization_storage' => ['type' => 'string', 'enum' => ['granted', 'denied']],
                    'security_storage' => ['type' => 'string', 'enum' => ['granted', 'denied']],
                ],
            ],
            'utm' => [
                'type' => 'object',
                'description' => 'UTM attribution parameters',
                'properties' => [
                    'source' => ['type' => 'string'],
                    'medium' => ['type' => 'string'],
                    'campaign' => ['type' => 'string'],
                    'term' => ['type' => 'string'],
                    'content' => ['type' => 'string'],
                ],
            ],
        ];
    }
}
