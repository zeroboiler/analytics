<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Generic product analytics event for domain-specific tracking.
 *
 * Wraps custom product actions into a typed event with structured metadata.
 * Unlike generic `trackEvent()`, this enforces product analytics conventions:
 * - Category-based grouping (feature, workflow, integration)
 * - Action classification (create, read, update, delete, share)
 * - Object identification (what was acted upon)
 *
 * Inspired by the product analytics patterns used by Amplitude, Mixpanel,
 * and Heap for structured event tracking.
 *
 * @since 22.0.0
 */
final readonly class ProductAnalyticsEvent extends AnalyticsEvent
{
    /**
     * Create a structured product analytics event.
     *
     * @param  string  $category  Product area: feature, workflow, integration, report, api
     * @param  string  $action  CRUD action or custom action name
     * @param  string  $objectName  The object/feature being acted upon
     * @param  array<string, mixed>  $params  Additional parameters
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     */
    public function __construct(
        public string $category = '',
        public string $action = '',
        public string $objectName = '',
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
    ){
        parent::__construct(
            name: 'product_analytics',
            params: array_merge($params, [
                'product_category' => $category,
                'product_action' => $action,
                'product_object' => $objectName,
                'event_full_name' => "{$category}.{$action}.{$objectName}",
            ]),
            clientId: $clientId,
            userId: $userId,
            priority: 'normal',
            source: 'server',
        );
    }
}
