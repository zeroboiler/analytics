<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Validates and sanitizes analytics events before dispatch.
 *
 * Provides event name validation against a whitelist,
 * parameter sanitization, and event deduplication.
 *
 * @since 1.0.0
 */
final class EventValidationService
{
    /** @var array<string, true> */
    private array $eventNameWhitelist;

    private bool $strictMode;

    private int $maxEventNameLength;

    private int $maxParamKeyLength;

    /** @var array<string, int> */
    private array $recentEvents = [];

    private int $deduplicationWindowSeconds;

    private int $maxRecentEvents;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(ConfigRepository $config)
    {
        $validationConfig = $config->get('zeroboiler.analytics.validation', []);
        /** @var array{strict?: bool, whitelist?: list<string>, max_event_name_length?: int, max_param_key_length?: int, deduplication_window?: int, max_recent_events?: int} $validationConfig */

        $this->strictMode = (bool) ($validationConfig['strict'] ?? false);

        $whitelist = $validationConfig['whitelist'] ?? [];
        $this->eventNameWhitelist = [];
        foreach ($whitelist as $name) {
            if (is_string($name) && $name !== '') {
                $this->eventNameWhitelist[$name] = true;
            }
        }

        $this->maxEventNameLength = (int) ($validationConfig['max_event_name_length'] ?? 100);
        $this->maxParamKeyLength = (int) ($validationConfig['max_param_key_length'] ?? 100);
        $this->deduplicationWindowSeconds = (int) ($validationConfig['deduplication_window'] ?? 10);
        $this->maxRecentEvents = (int) ($validationConfig['max_recent_events'] ?? 500);
    }

    /**
     * Validate an analytics event.
     *
     * @return array{valid: bool, event: AnalyticsEvent, errors: array<int, string>}
     */
    public function validate(AnalyticsEvent $event): array
    {
        $errors = [];

        if ($this->strictMode && ! $this->isWhitelisted($event->name)) {
            $errors[] = "Event name '{$event->name}' is not in the whitelist (strict mode enabled)";
        }

        if (strlen($event->name) > $this->maxEventNameLength) {
            $errors[] = "Event name exceeds max length of {$this->maxEventNameLength} characters";
        }

        if (! $this->isValidEventName($event->name)) {
            $errors[] = "Event name contains invalid characters (only lowercase letters, numbers, underscores)";
        }

        if ($this->isDuplicate($event)) {
            $errors[] = "Duplicate event detected within deduplication window";
        }

        return [
            'valid' => empty($errors),
            'event' => $this->sanitize($event),
            'errors' => $errors,
        ];
    }

    /**
     * Check if an event name is in the whitelist.
     */
    public function isWhitelisted(string $name): bool
    {
        if (empty($this->eventNameWhitelist)) {
            return true; // No whitelist = allow all
        }

        return isset($this->eventNameWhitelist[$name]);
    }

    /**
     * Validate event name format.
     */
    public function isValidEventName(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*$/', $name) === 1;
    }

    /**
     * Check for duplicate events within the deduplication window.
     */
    public function isDuplicate(AnalyticsEvent $event): bool
    {
        $key = $this->buildDeduplicationKey($event);
        $now = time();

        if (isset($this->recentEvents[$key])) {
            if (($now - $this->recentEvents[$key]) < $this->deduplicationWindowSeconds) {
                return true;
            }

            unset($this->recentEvents[$key]);
        }

        $this->recentEvents[$key] = $now;

        // Prevent memory leak — prune old entries
        if (count($this->recentEvents) > $this->maxRecentEvents) {
            $this->pruneRecentEvents();
        }

        return false;
    }

    /**
     * Sanitize event parameters.
     */
    public function sanitize(AnalyticsEvent $event): AnalyticsEvent
    {
        $sanitizedParams = [];

        foreach ($event->params as $key => $value) {
            if (! is_string($key)) {
                $key = (string) $key;
            }

            if (strlen($key) > $this->maxParamKeyLength) {
                $key = substr($key, 0, $this->maxParamKeyLength);
            }

            // Strip null bytes and control characters from keys
            $key = preg_replace('/[\x00-\x1F]/', '', $key) ?? $key;

            $sanitizedParams[$key] = $this->sanitizeValue($value);
        }

        return new AnalyticsEvent(
            name: $this->sanitizeEventName($event->name),
            params: $sanitizedParams,
            clientId: $event->clientId,
            userId: $event->userId,
        );
    }

    /**
     * Sanitize a parameter value recursively.
     *
     * @param  mixed  $value  The value to sanitize (string, array, or scalar)
     * @return mixed The sanitized value
     */
    private function sanitizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            // Strip null bytes and control characters (except newline, tab)
            return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? $value;
        }

        if (is_array($value)) {
            return array_map(fn (mixed $v): mixed => $this->sanitizeValue($v), $value);
        }

        if (is_int($value) || is_float($value) || is_bool($value) || is_null($value)) {
            return $value;
        }

        return null;
    }

    /**
     * Sanitize event name.
     */
    private function sanitizeEventName(string $name): string
    {
        // Remove spaces, special chars — keep only lowercase alphanumeric and underscores
        $sanitized = preg_replace('/[^a-z0-9_]/', '', strtolower($name));

        return $sanitized !== '' && $sanitized !== '0' ? $sanitized : $name;
    }

    /**
     * Build a deduplication key from the event.
     */
    private function buildDeduplicationKey(AnalyticsEvent $event): string
    {
        return md5($event->name . ':' . ($event->clientId ?? '') . ':' . json_encode($event->params));
    }

    /**
     * Prune old events from the deduplication cache.
     */
    private function pruneRecentEvents(): void
    {
        $cutoff = time() - $this->deduplicationWindowSeconds;
        $this->recentEvents = array_filter(
            $this->recentEvents,
            fn (int $timestamp): bool => $timestamp > $cutoff,
        );
    }

    /**
     * Clear the deduplication cache.
     */
    public function clearCache(): void
    {
        $this->recentEvents = [];
    }

    /**
     * Get the number of events in the deduplication cache.
     */
    public function getCacheSize(): int
    {
        return count($this->recentEvents);
    }
}
