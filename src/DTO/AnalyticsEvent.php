<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing an analytics event to be tracked.
 *
 * Includes optional category and session_id fields for industry-standard
 * event context enrichment (v141.0.0). Category is auto-resolved from
 * EventCatalog when not explicitly provided. Session ID enables per-session
 * event grouping for cohort and funnel analytics.
 *
 * @since 1.0.0
 */
final readonly class AnalyticsEvent
{
    /**
     * Package version for schema versioning.
     */
    public const VERSION = '160.0.0';

    /**
     * @param  string  $name  Event name (e.g. 'page_view', 'purchase')
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string|null  $clientId  GA4 client ID (optional, generated if null)
     * @param  string|null  $userId  Authenticated user ID (optional)
     * @param  \DateTimeImmutable|null  $timestamp  Event timestamp (optional, defaults to now)
     * @param  string|null  $priority  Event priority level (critical|normal|low|background), used by EventPriorityGate
     * @param  string|null  $source  Event origin source (api|server|client|webhook|replay|batch), used by EventSourceTagger
     * @param  string|null  $category  Event category (ecommerce|saas|engagement|security|uptime|infrastructure|marketing|customer_success), auto-resolved when null
     * @param  string|null  $sessionId  Session identifier for per-session event grouping (v141.0.0)
     */
    public function __construct(
        public string $name,
        public array $params = [],
        public ?string $clientId = null,
        public ?string $userId = null,
        public ?\DateTimeImmutable $timestamp = null,
        public ?string $priority = null,
        public ?string $source = null,
        public ?string $category = null,
        public ?string $sessionId = null,
    ): void {}

    /**
     * Create an AnalyticsEvent from an array.
     *
     * @param  array{name?: string, params?: array<string, mixed>, client_id?: string|null, user_id?: string|null, priority?: string|null, source?: string|null, category?: string|null, session_id?: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            params: is_array($data['params'] ?? null) ? $data['params'] : [],
            clientId: is_string($data['client_id'] ?? null) ? $data['client_id'] : null,
            userId: is_string($data['user_id'] ?? null) ? $data['user_id'] : null,
            priority: is_string($data['priority'] ?? null) ? $data['priority'] : null,
            source: is_string($data['source'] ?? null) ? $data['source'] : null,
            category: is_string($data['category'] ?? null) ? $data['category'] : null,
            sessionId: is_string($data['session_id'] ?? null) ? $data['session_id'] : null,
        );
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'params' => $this->params,
            'client_id' => $this->clientId,
            'user_id' => $this->userId,
            'timestamp' => $this->timestamp?->getTimestamp(),
            'priority' => $this->priority,
            'source' => $this->source,
            'category' => $this->category,
            'session_id' => $this->sessionId,
        ];
    }

    /**
     * Create a copy of this event with an overridden category.
     *
     * Useful for pipeline enrichment stages that resolve category from EventCatalog.
     *
     * @return self
     */
    public function withCategory(string $category): self
    {
        return new self(
            name: $this->name,
            params: $this->params,
            clientId: $this->clientId,
            userId: $this->userId,
            timestamp: $this->timestamp,
            priority: $this->priority,
            source: $this->source,
            category: $category,
            sessionId: $this->sessionId,
        );
    }

    /**
     * Create a copy of this event with an overridden session ID.
     *
     * Useful for session stitching and identity resolution.
     *
     * @return self
     */
    public function withSessionId(string $sessionId): self
    {
        return new self(
            name: $this->name,
            params: $this->params,
            clientId: $this->clientId,
            userId: $this->userId,
            timestamp: $this->timestamp,
            priority: $this->priority,
            source: $this->source,
            category: $this->category,
            sessionId: $sessionId,
        );
    }

    /**
     * Create a copy of this event with merged parameters.
     *
     * Later values override earlier ones. Useful for pipeline enrichment.
     *
     * @param  array<string, mixed>  $additionalParams
     * @return self
     */
    public function withMergedParams(array $additionalParams): self
    {
        return new self(
            name: $this->name,
            params: array_merge($this->params, $additionalParams),
            clientId: $this->clientId,
            userId: $this->userId,
            timestamp: $this->timestamp,
            priority: $this->priority,
            source: $this->source,
            category: $this->category,
            sessionId: $this->sessionId,
        );
    }
}
