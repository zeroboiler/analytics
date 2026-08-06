<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Enriches analytics events with UTM campaign parameters.
 *
 * Extracts standard UTM parameters (source, medium, campaign, term, content)
 * from the provided context and attaches them as event parameters.
 *
 * These are attached as `utm_source`, `utm_medium`, `utm_campaign`,
 * `utm_term`, and `utm_content` parameters on every event.
 */
final readonly class UtmEnricher
{
    /** @var array<string, mixed> */
    private array $context;

    /**
     * @param  array<string, mixed>  $context  Typically from $request->query->all() or Inertia page props
     */
    public function __construct(array $context = [])
    {
        $this->context = $context;
    }

    /**
     * Enrich the event with UTM parameters from context.
     *
     * @return AnalyticsEvent|null
     */
    public function __invoke(AnalyticsEvent $event): ?AnalyticsEvent
    {
        $utmParams = $this->extractUtmParams();

        if (empty($utmParams)) {
            return $event;
        }

        return new AnalyticsEvent(
            name: $event->name,
            params: array_merge($event->params, $utmParams),
            clientId: $event->clientId,
            userId: $event->userId,
        );
    }

    /**
     * Extract UTM parameters from context.
     *
     * @return array<string, string>
     */
    private function extractUtmParams(): array
    {
        $utmKeys = [
            'utm_source' => 'string',
            'utm_medium' => 'string',
            'utm_campaign' => 'string',
            'utm_term' => 'string',
            'utm_content' => 'string',
        ];

        $params = [];

        foreach ($utmKeys as $key => $type) {
            $value = $this->context[$key] ?? $this->context[strtoupper($key)] ?? null;

            if (is_string($value) && $value !== '') {
                $params[$key] = $value;
            }
        }

        return $params;
    }
}
