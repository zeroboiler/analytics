<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Security;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Analytics event for AI agent / automation tool access events.
 *
 * Tracks access by AI assistants, Claude, ChatGPT, or other automated tools
 * to sensitive resources. Useful for security audit trails and compliance
 * monitoring of non-human access patterns.
 *
 * @since 90.0.0
 */
final class AiAgentAccessEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $agent  Name of the AI agent (e.g., 'claude', 'gpt', 'copilot')
     * @param  string|null  $action  Action performed (e.g., 'read', 'write', 'deploy')
     * @param  string|null  $resource  Resource accessed (e.g., 'database', 'api', 'config')
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function __construct(
        ?string $agent = null,
        ?string $action = null,
        ?string $resource = null,
        array $params = [],
    ): void {
        parent::__construct(
            name: 'ai_agent_access',
            params: array_filter(array_merge($params, [
                'agent' => $agent,
                'action' => $action,
                'resource' => $resource,
            ])),
        );
    }
}
