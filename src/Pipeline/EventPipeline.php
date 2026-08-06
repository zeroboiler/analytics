<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Middleware pipeline for analytics event processing.
 *
 * Provides a chain of responsibility pattern for filtering, enriching,
 * and transforming analytics events before they are dispatched to providers.
 *
 * Usage:
 *   $pipeline = app(EventPipeline::class);
 *   $pipeline
 *       ->pipe(new UtmEnricher($request->query->all()))
 *       ->pipe(new UserContextEnricher(['user_id' => '123']))
 *       ->pipe(new ConsentFilter(true))
 *       ->pipe(new TimestampEnricher($sessionId))
 *       ->process($event);
 */
class EventPipeline
{
    /** @var array<int, callable(AnalyticsEvent): AnalyticsEvent|null> */
    private array $pipes = [];

    /**
     * Add a pipe (middleware) to the pipeline.
     *
     * Each pipe receives an AnalyticsEvent and must return:
     * - An AnalyticsEvent (possibly modified) to continue the chain
     * - null to abort the pipeline (event is dropped)
     *
     * @param  callable(AnalyticsEvent): AnalyticsEvent|null  $pipe
     */
    public function pipe(callable $pipe): self
    {
        $this->pipes[] = $pipe;

        return $this;
    }

    /**
     * Process an event through the pipeline.
     *
     * If any pipe returns null, the event is dropped and this returns null.
     *
     * @return AnalyticsEvent|null The processed event, or null if dropped
     */
    public function process(AnalyticsEvent $event): ?AnalyticsEvent
    {
        $current = $event;

        foreach ($this->pipes as $pipe) {
            $result = $pipe($current);

            if ($result === null) {
                return null; // Event was filtered out
            }

            $current = $result;
        }

        return $current;
    }

    /**
     * Get the number of pipes registered.
     */
    public function pipeCount(): int
    {
        return count($this->pipes);
    }

    /**
     * Remove all pipes.
     */
    public function flush(): self
    {
        $this->pipes = [];

        return $this;
    }

    /**
     * Create a pipeline with common SaaS enrichers pre-configured.
     *
     * Adds consent filtering, UTM enrichment, user context enrichment,
     * and timestamp enrichment by default.
     *
     * @param  array<string, mixed>  $context  Request context (UTM params, user info, etc.)
     * @param  bool  $analyticsGranted  Whether analytics consent is granted
     * @param  string|null  $sessionId  Optional session identifier for timestamp enrichment
     */
    public static function withDefaults(
        array $context = [],
        bool $analyticsGranted = true,
        ?string $sessionId = null,
    ): self {
        return (new self)
            ->pipe(new ConsentFilter($analyticsGranted))
            ->pipe(new UtmEnricher($context))
            ->pipe(new UserContextEnricher($context))
            ->pipe(new TimestampEnricher($sessionId));
    }
}
