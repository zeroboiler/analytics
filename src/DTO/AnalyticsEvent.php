<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing an analytics event to be tracked.
 */
readonly class AnalyticsEvent
{
    /**
     * @param  string  $name  Event name (e.g. 'page_view', 'purchase')
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string|null  $clientId  GA4 client ID (optional, generated if null)
     * @param  string|null  $userId  Authenticated user ID (optional)
     */
    public function __construct(
        public string $name,
        public array $params = [],
        public ?string $clientId = null,
        public ?string $userId = null,
    ) {}

    /**
     * Create an AnalyticsEvent from an array.
     *
     * @param  array{name?: string, params?: array<string, mixed>, client_id?: string|null, user_id?: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            params: is_array($data['params'] ?? null) ? $data['params'] : [],
            clientId: is_string($data['client_id'] ?? null) ? $data['client_id'] : null,
            userId: is_string($data['user_id'] ?? null) ? $data['user_id'] : null,
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
        ];
    }
}
