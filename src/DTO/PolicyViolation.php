<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Governance policy violation record.
 *
 * Emitted when an event violates a configured governance policy rule.
 * Tracks the violated rule, the event that triggered it, the policy action
 * taken (block, warn, sanitize, transform), and contextual metadata.
 *
 * @since 84.0.0
 *
 * @see \ZeroBoiler\Analytics\Services\EventPolicyEngine
 */
final class PolicyViolation
{
    /**
     * Policy action types.
     */
    public const ACTION_BLOCK = 'block';
    public const ACTION_WARN = 'warn';
    public const ACTION_SANITIZE = 'sanitize';
    public const ACTION_TRANSFORM = 'transform';
    public const ACTION_ALLOW = 'allow';

    /**
     * Policy severity levels.
     */
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW = 'low';
    public const SEVERITY_INFO = 'info';

    /**
     * Create a new policy violation.
     *
     * @param  string  $ruleId  The policy rule identifier that was violated
     * @param  string  $eventName  The event name that triggered the violation
     * @param  string  $action  Action taken (block, warn, sanitize, transform, allow)
     * @param  string  $severity  Severity level (critical, high, medium, low, info)
     * @param  string  $reason  Human-readable reason for the violation
     * @param  array<string, mixed>  $eventSnapshot  Snapshot of the event payload at time of evaluation
     * @param  array<string, mixed>  $context  Additional context (user_id, client_id, ip, etc.)
     * @param  string|null  $resolvedBy  Rule name or policy engine component that resolved this
     */
    public function __construct(
        public readonly string $ruleId,
        public readonly string $eventName,
        public readonly string $action,
        public readonly string $severity,
        public readonly string $reason,
        public readonly array $eventSnapshot = [],
        public readonly array $context = [],
        public readonly ?string $resolvedBy = null,
    ): void {}

    /**
     * Check if this violation blocked the event from being dispatched.
     */
    public function isBlocked(): bool
    {
        return $this->action === self::ACTION_BLOCK;
    }

    /**
     * Check if this violation requires immediate attention.
     */
    public function isCritical(): bool
    {
        return $this->severity === self::SEVERITY_CRITICAL;
    }

    /**
     * Convert to array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'event_name' => $this->eventName,
            'action' => $this->action,
            'severity' => $this->severity,
            'reason' => $this->reason,
            'is_blocked' => $this->isBlocked(),
            'is_critical' => $this->isCritical(),
            'event_snapshot' => $this->eventSnapshot,
            'context' => $this->context,
            'resolved_by' => $this->resolvedBy,
        ];
    }
}
