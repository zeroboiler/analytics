<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Enriches analytics events with user context information.
 *
 * Attaches authenticated user properties (id, email, name, plan) to events
 * when available, enabling user-level segmentation in analytics providers.
 *
 * @since 1.0.0
 */
final readonly class UserContextEnricher
{
    /** @var array<string, mixed> */
    private array $context;

    /**
     * @param  array<string, mixed>  $context  User context data
     *     Typical keys: user_id, user_email, user_name, user_plan, user_role, user_created_at
     */
    public function __construct(array $context = []){
        $this->context = $context;
    }

    /**
     * Enrich the event with user context.
     *
     * Only attaches properties that are present in context.
     *
     * @return AnalyticsEvent|null
     */
    public function __invoke(AnalyticsEvent $event): ?AnalyticsEvent
    {
        $userParams = $this->extractUserParams();

        if (empty($userParams)) {
            return $event;
        }

        return new AnalyticsEvent(
            name: $event->name,
            params: array_merge($event->params, $userParams),
            clientId: $event->clientId,
            userId: $event->userId ?? $userParams['user_id'] ?? null,
        );
    }

    /**
     * Extract user context parameters.
     *
     * @return array<string, mixed>
     */
    private function extractUserParams(): array
    {
        $userKeys = [
            'user_id' => 'string',
            'user_email' => 'string',
            'user_name' => 'string',
            'user_plan' => 'string',
            'user_role' => 'string',
            'user_created_at' => 'string',
        ];

        $params = [];

        foreach ($userKeys as $key => $type) {
            $value = $this->context[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $params[$key] = $value;
            } elseif (is_int($value) || is_float($value)) {
                $params[$key] = (string) $value;
            }
        }

        return $params;
    }
}
