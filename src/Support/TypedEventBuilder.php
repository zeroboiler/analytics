<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Fluent, catalog-aware event builder for type-safe analytics dispatch.
 *
 * Provides a chainable API for constructing analytics events with:
 * - Catalog validation (warns if event name is not in the catalog)
 * - Automatic category inference from the event catalog
 * - Type coercion for known parameter types
 * - Identity (client ID / user ID) binding
 * - Priority and source tagging
 * - Direct dispatch or event object return
 *
 * Usage:
 *   Analytics::typedEvent('purchase')
 *       ->param('transaction_id', 'TXN-123')
 *       ->param('value', 99.99)
 *       ->param('currency', 'USD')
 *       ->param('items', $items)
 *       ->user($userId)
 *       ->dispatch();
 *
 *   // Or for catalog events (auto-validates against catalog schema):
 *   Analytics::typedCatalogEvent('sign_up')
 *       ->param('method', 'email')
 *       ->client($clientId)
 *       ->dispatch();
 *
 * @since 250.0.0
 */
final class TypedEventBuilder
{
    /** @var array<string, mixed> Accumulated event parameters */
    private array $params = [];

    /** @var string|null Client ID for anonymous tracking */
    private ?string $clientId = null;

    /** @var string|null User ID for authenticated tracking */
    private ?string $userId = null;

    /** @var string|null Session ID */
    private ?string $sessionId = null;

    /** @var int Event priority (0-100, default 50) */
    private int $priority = 50;

    /** @var string Event source label */
    private string $source = 'server';

    /** @var string|null Inferred category from catalog */
    private ?string $category = null;

    /** @var bool Whether to validate the event name against the catalog */
    private bool $catalogStrict;

    /** @var list<string> Validation warnings collected during build */
    private array $warnings = [];

    /**
     * @param  string  $eventName  The analytics event name to build
     * @param  bool  $catalogStrict  Whether to warn on unknown event names
     */
    public function __construct(
        private readonly string $eventName,
        bool $catalogStrict = false,
    ) {
        $this->catalogStrict = $catalogStrict;

        // Auto-infer category from catalog
        $entry = EventCatalog::get($this->eventName);
        if ($entry !== null) {
            $this->category = $entry['category'] ?? null;
        } elseif ($this->catalogStrict) {
            $this->warnings[] = "Event '{$this->eventName}' is not in the event catalog.";
        }
    }

    /**
     * Set a parameter on the event.
     *
     * Values are type-coerced based on common analytics conventions:
     * - Numeric strings → float/int
     * - Boolean strings ('true'/'false') → bool
     * - Empty strings → skipped (not set)
     *
     * @param  string  $key  Parameter name
     * @param  mixed  $value  Parameter value
     * @return $this
     */
    public function param(string $key, mixed $value): self
    {
        // Skip empty strings — they add noise to analytics payloads
        if (is_string($value) && $value === '') {
            return $this;
        }

        $this->params[$key] = $this->coerce($key, $value);

        return $this;
    }

    /**
     * Set multiple parameters at once.
     *
     * @param  array<string, mixed>  $params  Key-value pairs
     * @return $this
     */
    public function params(array $params): self
    {
        foreach ($params as $key => $value) {
            $this->param($key, $value);
        }

        return $this;
    }

    /**
     * Set the client (anonymous) ID.
     *
     * @param  string  $clientId  Client-side tracking ID (from cookie)
     * @return $this
     */
    public function client(string $clientId): self
    {
        $this->clientId = $clientId;

        return $this;
    }

    /**
     * Set the authenticated user ID.
     *
     * @param  int|string  $userId  User ID
     * @return $this
     */
    public function user(int|string|null $userId): self
    {
        $this->userId = $userId !== null ? (string) $userId : null;

        return $this;
    }

    /**
     * Set the session ID.
     *
     * @param  string  $sessionId  Session identifier
     * @return $this
     */
    public function session(string $sessionId): self
    {
        $this->sessionId = $sessionId;

        return $this;
    }

    /**
     * Set the event priority (0-100).
     *
     * Higher priority events are dispatched first in batch operations.
     *
     * @param  int  $priority  Priority value (0-100)
     * @return $this
     */
    public function priority(int $priority): self
    {
        $this->priority = max(0, min(100, $priority));

        return $this;
    }

    /**
     * Set the event source label.
     *
     * Useful for distinguishing server-dispatched vs client-dispatched events.
     *
     * @param  string  $source  Source label (e.g., 'server', 'client', 'cron')
     * @return $this
     */
    public function source(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    /**
     * Override the auto-inferred event category.
     *
     * @param  string  $category  Category name (e.g., 'ecommerce', 'saas', 'engagement')
     * @return $this
     */
    public function category(string $category): self
    {
        $this->category = $category;

        return $this;
    }

    /**
     * Build the AnalyticsEvent DTO without dispatching.
     *
     * @return AnalyticsEvent
     */
    public function build(): AnalyticsEvent
    {
        return new AnalyticsEvent(
            name: $this->eventName,
            params: $this->params,
            clientId: $this->clientId,
            userId: $this->userId,
            sessionId: $this->sessionId,
            priority: $this->priority,
            source: $this->source,
            category: $this->category,
        );
    }

    /**
     * Build and dispatch the event through the analytics manager.
     *
     * @param  AnalyticsManager|null  $manager  Optional manager (auto-resolved if null)
     */
    public function dispatch(?AnalyticsManager $manager = null): void
    {
        $manager ??= $this->resolveManager();
        $manager->trackEvent($this->build());
    }

    /**
     * Build and queue the event for async dispatch.
     *
     * @param  AnalyticsManager|null  $manager  Optional manager (auto-resolved if null)
     */
    public function dispatchAsync(?AnalyticsManager $manager = null): void
    {
        $manager ??= $this->resolveManager();
        $manager->trackAsync($this->eventName, $this->params);
    }

    /**
     * Get the event name being built.
     */
    public function name(): string
    {
        return $this->eventName;
    }

    /**
     * Get all parameters set on this builder.
     *
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Get the inferred or overridden category.
     */
    public function getCategory(): ?string
    {
        return $this->category;
    }

    /**
     * Get any validation warnings collected during building.
     *
     * @return list<string>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Check if the event name exists in the catalog.
     */
    public function isInCatalog(): bool
    {
        return EventCatalog::get($this->eventName) !== null;
    }

    /**
     * Get the catalog entry for this event, or null.
     *
     * @return array{name: string, class: class-string<AnalyticsEvent>, ga4: string, meta: string|null, category: string}|null
     */
    public function catalogEntry(): ?array
    {
        return EventCatalog::get($this->eventName);
    }

    /**
     * Coerce a value to the appropriate type based on the parameter key.
     *
     * Applies conventions used across GA4, Meta, and PostHog:
     * - Keys ending in '_id', 'id' → string
     * - Keys like 'value', 'price', 'amount', 'revenue' → float
     * - Keys like 'quantity', 'count', 'days', 'step' → int
     * - Keys like 'enabled', 'required', 'success' → bool
     * - Keys like 'items', 'products' → array
     *
     * @param  string  $key  Parameter name
     * @param  mixed  $value  Raw value
     * @return mixed Coerced value
     */
    private function coerce(string $key, mixed $value): mixed
    {
        // Don't coerce nulls or already-correct types for complex values
        if ($value === null || is_array($value) || is_object($value)) {
            return $value;
        }

        // String coercion targets
        $stringKeys = ['transaction_id', 'order_id', 'item_id', 'user_id',
            'client_id', 'session_id', 'currency', 'method', 'reason',
            'plan', 'category', 'label', 'name', 'email', 'phone',
            'url', 'referrer', 'page_title', 'page_location', 'page_referrer',
            'search_term', 'form_id', 'form_name', 'feature_name',
            'feature_category', 'error_message', 'error_code', 'source',
            'medium', 'campaign', 'content', 'creative_name', 'creative_slot',
            'promotion_id', 'promotion_name', 'item_list_id', 'item_list_name',
            'item_name', 'item_brand', 'item_variant', 'item_category',
            'payment_type', 'shipping_tier', 'coupon', 'affiliation',
            'billing_cycle', 'role', 'guard', 'integration',
            'endpoint', 'provider', 'type', 'status', 'grade',
        ];

        if (in_array($key, $stringKeys, true)) {
            return is_string($value) ? $value : (string) $value;
        }

        // Float coercion targets
        $floatKeys = ['value', 'price', 'amount', 'revenue', 'tax',
            'shipping', 'total', 'score', 'percent', 'rate', 'duration',
            'timeout', 'threshold', 'weight',
        ];

        if (in_array($key, $floatKeys, true)) {
            if (is_float($value)) {
                return $value;
            }
            if (is_numeric($value)) {
                return (float) $value;
            }

            return $value;
        }

        // Integer coercion targets
        $intKeys = ['quantity', 'count', 'days', 'step', 'step_number',
            'total_steps', 'results_count', 'num_items', 'duration_seconds',
            'attempt', 'attempt_number', 'limit', 'max_retries',
        ];

        if (in_array($key, $intKeys, true)) {
            if (is_int($value)) {
                return $value;
            }
            if (is_numeric($value)) {
                return (int) $value;
            }

            return $value;
        }

        // Boolean coercion targets
        $boolKeys = ['enabled', 'required', 'success', 'fatal', 'is_new',
            'authenticated', 'anonymous',
        ];

        if (in_array($key, $boolKeys, true)) {
            if (is_bool($value)) {
                return $value;
            }
            if (is_string($value)) {
                $lower = strtolower($value);
                if ($lower === 'true' || $lower === '1') {
                    return true;
                }
                if ($lower === 'false' || $lower === '0') {
                    return false;
                }
            }

            return $value;
        }

        return $value;
    }

    /**
     * Resolve the AnalyticsManager from the container.
     */
    private function resolveManager(): AnalyticsManager
    {
        /** @var AnalyticsManager $manager */
        $manager = app('zeroboiler.analytics');

        return $manager;
    }
}
