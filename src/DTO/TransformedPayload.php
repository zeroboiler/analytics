<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Result of an event transformation operation.
 *
 * Contains the transformed payload, the resolved event name, and
 * a list of applied transformations for debugging and auditing.
 *
 * @since 70.0.0
 */
final readonly class TransformedPayload
{
    /**
     * @param  string  $eventName  Resolved event name (may be overridden for the target provider)
     * @param  array<string, mixed>  $params  Transformed parameter payload
     * @param  string  $provider  Target provider identifier
     * @param  list<array{rule: string, field: string, action: string}>  $applied  List of applied transformations for audit trail
     * @param  bool  $dropped  Whether the event should be dropped (not sent to this provider)
     * @param  list<string>  $warnings  Non-fatal warnings encountered during transformation
     */
    public function __construct(
        public string $eventName,
        public array $params,
        public string $provider,
        public array $applied = [],
        public bool $dropped = false,
        public array $warnings = [],
    ) {}

    /**
     * Create a "dropped" result — event should not be sent to this provider.
     */
    public static function dropped(string $eventName, string $provider, string $reason): self
    {
        return new self(
            eventName: $eventName,
            params: [],
            provider: $provider,
            applied: [['rule' => 'drop', 'field' => '*', 'action' => $reason]],
            dropped: true,
        );
    }

    /**
     * Create a passthrough result — no transformation rules matched.
     */
    public static function passthrough(AnalyticsEvent $event, string $provider): self
    {
        return new self(
            eventName: $event->name,
            params: $event->params,
            provider: $provider,
        );
    }

    /**
     * Serialize to array.
     *
     * @return array{event_name: string, params: array<string, mixed>, provider: string, applied: list<array{rule: string, field: string, action: string}>, dropped: bool, warnings: list<string>}
     */
    public function toArray(): array
    {
        return [
            'event_name' => $this->eventName,
            'params' => $this->params,
            'provider' => $this->provider,
            'applied' => $this->applied,
            'dropped' => $this->dropped,
            'warnings' => $this->warnings,
        ];
    }
}
