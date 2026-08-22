<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

use ZeroBoiler\Analytics\AnalyticsManager;
/**
 * Scoped analytics context for automatic source tagging, timing, and error handling.
 *
 * Wraps a closure in an analytics context that automatically:
 * - Tags all events with a source and context label
 * - Measures execution duration and emits timing events
 * - Captures exceptions and emits error events
 * - Attaches consistent metadata to all events dispatched within the scope
 *
 * Inspired by OpenTelemetry span semantics and Sentry's startSpan pattern.
 *
 * @since 41.0.0
 *
 * @example
 * ```php
 * $result = AnalyticsContext::measure($manager, 'checkout.process', function () use ($cart) {
 *     $order = $cart->checkout();
 *     Analytics::track('checkout_completed', ['order_id' => $order->id]);
 *     return $order;
 * });
 * // Automatically tracks: checkout.process.start, checkout.process.complete (with duration),
 * // or checkout.process.error on exception
 * ```
 */
final class AnalyticsContext
{
    /** @var array<string, mixed> Context metadata attached to all events within scope */
    private array $metadata = [];

    /** @var string|null Source label for events dispatched within this context */
    private ?string $source;

    /** @var string Context label (used as event prefix and timing suffix) */
    private string $label;

    /** @var float|null Start time (microtime true) for duration measurement */
    private ?float $startedAt = null;

    /** @var bool Whether timing events should be emitted */
    private bool $emitTiming;

    /** @var bool Whether error events should be emitted on exceptions */
    private bool $emitErrors;

    /** @var bool Whether start/complete lifecycle events should be emitted */
    private bool $emitLifecycle;

    /** @var string|null Override client ID for all events in this context */
    private ?string $clientId;

    /** @var string|null Override user ID for all events in this context */
    private ?string $userId;

    /** @var string|null Event priority for all events in this context */
    private ?string $priority;

    private function __construct(
        string $label,
        ?string $source = null,
        bool $emitTiming = true,
        bool $emitErrors = true,
        bool $emitLifecycle = false,
    ){
        $this->label = $label;
        $this->source = $source;
        $this->emitTiming = $emitTiming;
        $this->emitErrors = $emitErrors;
        $this->emitLifecycle = $emitLifecycle;
    }

    /**
     * Create a new analytics context.
     *
     * @param  string  $label  Context label (e.g., 'checkout.process', 'api.sync')
     * @param  string|null  $source  Source override for events within this context
     * @return self
     */
    public static function create(string $label, ?string $source = null): self
    {
        return new self(label: $label, source: $source);
    }

    /**
     * Create a silent context — no timing, error, or lifecycle events emitted.
     *
     * Only metadata is attached to events dispatched within the scope.
     *
     * @param  string  $label  Context label
     * @return self
     */
    public static function silent(string $label): self
    {
        return new self(
            label: $label,
            source: null,
            emitTiming: false,
            emitErrors: false,
            emitLifecycle: false,
        );
    }

    /**
     * Measure a closure within an analytics context.
     *
     * Creates a context, runs the closure, emits timing events,
     * and returns the result. Exceptions are caught and emitted as error events,
     * then re-thrown.
     *
     * @param  AnalyticsManager  $manager  Analytics manager instance
     * @param  string  $label  Context label
     * @param  \Closure(): T  $callback  Closure to execute within context
     * @return T Result of the closure
     *
     * @template T
     */
    public static function measure(AnalyticsManager $manager, string $label, \Closure $callback): mixed
    {
        return self::create($label)->run($manager, $callback);
    }

    /**
     * Attach metadata to all events dispatched within this context.
     *
     * @param  array<string, mixed>  $metadata  Key-value pairs to attach
     * @return self
     */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = array_merge($this->metadata, $metadata);

        return $this;
    }

    /**
     * Set the client ID override for all events in this context.
     *
     * @return self
     */
    public function withClientId(string $clientId): self
    {
        $this->clientId = $clientId;

        return $this;
    }

    /**
     * Set the user ID override for all events in this context.
     *
     * @return self
     */
    public function withUserId(string $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    /**
     * Set the event priority for all events in this context.
     *
     * @return self
     */
    public function withPriority(string $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * Enable or disable timing event emission.
     *
     * @return self
     */
    public function withTiming(bool $enabled): self
    {
        $this->emitTiming = $enabled;

        return $this;
    }

    /**
     * Enable or disable error event emission.
     *
     * @return self
     */
    public function withErrorCapture(bool $enabled): self
    {
        $this->emitErrors = $enabled;

        return $this;
    }

    /**
     * Enable or disable lifecycle event emission (start/complete).
     *
     * @return self
     */
    public function withLifecycle(bool $enabled): self
    {
        $this->emitLifecycle = $enabled;

        return $this;
    }

    /**
     * Run a closure within this analytics context.
     *
     * @param  AnalyticsManager  $manager  Analytics manager instance
     * @param  \Closure(): T  $callback  Closure to execute
     * @return T Result of the closure
     *
     * @template T
     *
     * @throws \Throwable Re-throws any exception from the closure after emitting error event
     */
    public function run(AnalyticsManager $manager, \Closure $callback): mixed
    {
        $this->startedAt = microtime(true);

        if ($this->emitLifecycle) {
            $manager->track($this->buildEventName('start'), $this->buildParams());
        }

        try {
            $result = $callback();

            $this->complete($manager);

            return $result;
        } catch (\Throwable $e) {
            $this->error($manager, $e);

            throw $e;
        }
    }

    /**
     * Manually mark the context as completed.
     *
     * Call this if you are not using run() but managing the lifecycle yourself.
     */
    public function complete(AnalyticsManager $manager): void
    {
        if ($this->startedAt === null) {
            return;
        }

        $duration = (microtime(true) - $this->startedAt) * 1000; // milliseconds

        $params = array_merge($this->buildParams(), [
            'duration_ms' => round($duration, 2),
        ]);

        if ($this->emitLifecycle) {
            $manager->track($this->buildEventName('complete'), $params);
        }

        if ($this->emitTiming) {
            $manager->track(
                "{$this->label}.timing",
                array_merge($params, [
                    'context' => $this->label,
                ]),
            );
        }

        $this->startedAt = null;
    }

    /**
     * Manually record an error within this context.
     *
     * Call this if you catch errors outside of run().
     */
    public function error(AnalyticsManager $manager, \Throwable $e): void
    {
        if (! $this->emitErrors) {
            return;
        }

        $duration = $this->startedAt !== null
            ? round((microtime(true) - $this->startedAt) * 1000, 2)
            : null;

        $manager->track(
            $this->buildEventName('error'),
            array_merge($this->buildParams(), [
                'error_type' => (new \ReflectionClass($e))->getShortName(),
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'duration_ms' => $duration,
            ]),
        );
    }

    /**
     * Get the context label.
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * Get the context metadata.
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Check if the context has started.
     */
    public function isStarted(): bool
    {
        return $this->startedAt !== null;
    }

    /**
     * Get the elapsed time in milliseconds since context start.
     */
    public function elapsedMs(): ?float
    {
        if ($this->startedAt === null) {
            return null;
        }

        return round((microtime(true) - $this->startedAt) * 1000, 2);
    }

    /**
     * Build a contextualized event name.
     */
    private function buildEventName(string $suffix): string
    {
        return "{$this->label}.{$suffix}";
    }

    /**
     * Build the base params with metadata, source, client ID, user ID, and priority.
     *
     * @return array<string, mixed>
     */
    private function buildParams(): array
    {
        $params = $this->metadata;

        if ($this->source !== null) {
            $params['_context_source'] = $this->source;
        }

        $params['_context_label'] = $this->label;

        return $params;
    }

    /**
     * Get the client ID override.
     */
    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    /**
     * Get the user ID override.
     */
    public function getUserId(): ?string
    {
        return $this->userId;
    }

    /**
     * Get the priority override.
     */
    public function getPriority(): ?string
    {
        return $this->priority;
    }

    /**
     * Get the source override.
     */
    public function getSource(): ?string
    {
        return $this->source;
    }
}
