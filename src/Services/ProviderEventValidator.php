<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Provider-specific event parameter validator.
 *
 * Validates analytics event parameters against provider-specific
 * schema requirements before dispatch. Catches malformed data
 * early to prevent provider API rejections and data quality issues.
 *
 * Supported providers:
 *   - GA4: item schema (item_id, price, quantity required), max 25 items
 *   - Meta Pixel: content_ids, num_items, currency validation
 *   - PostHog: reserved $properties, distinct_id format
 *   - Plausible: custom event name validation (no spaces)
 *
 * Returns detailed validation results with per-field error messages.
 *
 * @see \ZeroBoiler\Analytics\Services\EventValidationService
 *
 * @since 6.8.0
 */
final class ProviderEventValidator
{
    /** @var array<string, list<string>> Provider-specific reserved property names */
    private const POSTHOG_RESERVED_PROPERTIES = [
        '$device_id', '$session_id', '$window_id',
        '$distinct_id', '$user_id', '$anonymous_id',
        '$ip', '$geoip_disable', '$time',
        '$set', '$set_once', '$unset',
    ];

    /** @var array<string, list<string>> GA4 item fields that must exist in each item */
    private const GA4_REQUIRED_ITEM_FIELDS = ['item_id', 'price'];

    /** @var int GA4 max items per event */
    private const GA4_MAX_ITEMS = 25;

    /** @var int Max event name length for Plausible */
    private const PLAUSIBLE_MAX_EVENT_NAME_LENGTH = 200;

    /**
     * Validate event parameters for GA4 provider.
     *
     * Checks item schema, currency format, and value constraints.
     *
     * @param  AnalyticsEvent  $event
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public function validateGa4(AnalyticsEvent $event): array
    {
        $errors = [];
        $warnings = [];
        $params = $event->params;

        // Validate items array
        $items = $params['items'] ?? [];

        if (! is_array($items)) {
            $errors[] = "'items' parameter must be an array, got " . gettype($items);

            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        if (count($items) > self::GA4_MAX_ITEMS) {
            $errors[] = "GA4 events support max " . self::GA4_MAX_ITEMS . " items, got " . count($items);
        }

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                $errors[] = "Item at index {$index} must be an array, got " . gettype($item);
                continue;
            }

            // Check required fields
            foreach (self::GA4_REQUIRED_ITEM_FIELDS as $field) {
                if (! array_key_exists($field, $item)) {
                    $errors[] = "Item at index {$index} is missing required field '{$field}'";
                }
            }

            // Validate price is numeric
            if (isset($item['price']) && ! is_numeric($item['price'])) {
                $errors[] = "Item at index {$index} has non-numeric 'price': " . var_export($item['price'], true);
            }

            // Validate quantity is integer-ish
            if (isset($item['quantity']) && ! is_int($item['quantity']) && ! is_numeric($item['quantity'])) {
                $errors[] = "Item at index {$index} has non-numeric 'quantity': " . var_export($item['quantity'], true);
            }

            // Validate item_name length
            if (isset($item['item_name']) && is_string($item['item_name']) && mb_strlen($item['item_name']) > 100) {
                $warnings[] = "Item at index {$index} has 'item_name' exceeding 100 characters";
            }
        }

        // Validate currency if present
        if (isset($params['currency']) && is_string($params['currency'])) {
            if (! preg_match('/^[A-Z]{3}$/', $params['currency'])) {
                $errors[] = "Invalid ISO 4217 currency code: '{$params['currency']}'";
            }
        }

        // Validate value is numeric if present
        if (isset($params['value']) && ! is_numeric($params['value'])) {
            $errors[] = "'value' parameter must be numeric, got " . gettype($params['value']);
        }

        // Warn if transaction_id is missing for purchase/refund events
        if (in_array($event->name, ['purchase', 'refund'], true) && ! isset($params['transaction_id'])) {
            $warnings[] = "Event '{$event->name}' is missing 'transaction_id' parameter";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate event parameters for Meta Pixel provider.
     *
     * Checks content_ids array, num_items, currency, and value format.
     *
     * @param  AnalyticsEvent  $event
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public function validateMeta(AnalyticsEvent $event): array
    {
        $errors = [];
        $warnings = [];
        $params = $event->params;

        // Validate currency
        if (isset($params['currency']) && is_string($params['currency'])) {
            if (! preg_match('/^[A-Z]{3}$/', $params['currency'])) {
                $errors[] = "Invalid ISO 4217 currency code: '{$params['currency']}'";
            }
        }

        // Validate value is numeric
        if (isset($params['value']) && ! is_numeric($params['value'])) {
            $errors[] = "'value' parameter must be numeric, got " . gettype($params['value']);
        }

        // Validate content_ids if present
        if (isset($params['content_ids'])) {
            if (! is_array($params['content_ids'])) {
                $errors[] = "'content_ids' must be an array, got " . gettype($params['content_ids']);
            } else {
                foreach ($params['content_ids'] as $idx => $contentId) {
                    if (! is_string($contentId) && ! is_int($contentId)) {
                        $errors[] = "content_id at index {$idx} must be a string or int, got " . gettype($contentId);
                    }
                }
            }
        }

        // Validate num_items consistency
        if (isset($params['contents']) && is_array($params['contents'])) {
            $contentCount = count($params['contents']);
            if (isset($params['num_items']) && (int) $params['num_items'] !== $contentCount) {
                $warnings[] = "'num_items' ({$params['num_items']}) does not match contents count ({$contentCount})";
            }
        }

        // Warn on missing content_type for e-commerce events
        $ecommerceEvents = ['AddToCart', 'Purchase', 'InitiateCheckout', 'ViewContent', 'AddToWishlist'];
        if (in_array($event->name, $ecommerceEvents, true) && ! isset($params['content_type'])) {
            $warnings[] = "E-commerce event '{$event->name}' is missing 'content_type' parameter";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate event parameters for PostHog provider.
     *
     * Checks for reserved $properties conflicts and distinct_id format.
     *
     * @param  AnalyticsEvent  $event
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public function validatePosthog(AnalyticsEvent $event): array
    {
        $errors = [];
        $warnings = [];
        $params = $event->params;

        // Check for reserved $properties in user params
        foreach ($params as $key => $value) {
            if (str_starts_with($key, '$') && in_array($key, self::POSTHOG_RESERVED_PROPERTIES, true)) {
                $errors[] = "Parameter '{$key}' is a reserved PostHog property and cannot be set manually";
            }
        }

        // Validate currency is in $currency format
        if (isset($params['currency']) && is_string($params['currency']) && ! str_starts_with($params['currency'], '$')) {
            $warnings[] = "PostHog expects currency as '\$currency', not 'currency'";
        }

        // Warn if event properties exceed 100 keys
        if (count($params) > 100) {
            $warnings[] = "PostHog events with >100 properties may be truncated (got " . count($params) . ')';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate event parameters for Plausible provider.
     *
     * Plausible is minimal — mainly validates event name format.
     *
     * @param  AnalyticsEvent  $event
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public function validatePlausible(AnalyticsEvent $event): array
    {
        $errors = [];
        $warnings = [];

        // Plausible custom event names cannot contain spaces
        if (str_contains($event->name, ' ')) {
            $errors[] = "Plausible event names cannot contain spaces: '{$event->name}'";
        }

        // Max event name length
        if (mb_strlen($event->name) > self::PLAUSIBLE_MAX_EVENT_NAME_LENGTH) {
            $errors[] = "Plausible event name exceeds max length (" . self::PLAUSIBLE_MAX_EVENT_NAME_LENGTH . " chars)";
        }

        // Plausible doesn't use params, so warn if significant params are attached
        if (! empty($event->params)) {
            $warnings[] = 'Plausible does not support custom event properties — params will be ignored';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate event for all enabled providers simultaneously.
     *
     * Returns per-provider validation results and an overall status.
     *
     * @param  AnalyticsEvent  $event
     * @param  list<'ga4'|'meta'|'posthog'|'plausible'>  $providers  Providers to validate against
     * @return array{valid: bool, providers: array<string, array{valid: bool, errors: list<string>, warnings: list<string>}>}
     */
    public function validateAll(AnalyticsEvent $event, array $providers = ['ga4', 'meta', 'posthog', 'plausible']): array
    {
        $results = [];
        $overallValid = true;

        foreach ($providers as $provider) {
            $result = match ($provider) {
                'ga4' => $this->validateGa4($event),
                'meta' => $this->validateMeta($event),
                'posthog' => $this->validatePosthog($event),
                'plausible' => $this->validatePlausible($event),
                default => ['valid' => true, 'errors' => ["Unknown provider: {$provider}"], 'warnings' => []],
            };

            $results[$provider] = $result;

            if (! $result['valid']) {
                $overallValid = false;
            }
        }

        return [
            'valid' => $overallValid,
            'providers' => $results,
        ];
    }

    /**
     * Get GA4 item schema requirements.
     *
     * @return list<string> Required item field names
     */
    public static function ga4RequiredItemFields(): array
    {
        return self::GA4_REQUIRED_ITEM_FIELDS;
    }

    /**
     * Get GA4 max items per event.
     */
    public static function ga4MaxItems(): int
    {
        return self::GA4_MAX_ITEMS;
    }

    /**
     * Get PostHog reserved property names.
     *
     * @return list<string>
     */
    public static function posthogReservedProperties(): array
    {
        return self::POSTHOG_RESERVED_PROPERTIES;
    }
}
