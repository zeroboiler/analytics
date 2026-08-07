<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing UTM campaign attribution data.
 *
 * Captures UTM parameters from incoming requests and provides
 * a structured object for campaign attribution across events.
 *
 * @phpstan-type UtmData array{
 *     utm_source?: string,
 *     utm_medium?: string,
 *     utm_campaign?: string,
 *     utm_term?: string,
 *     utm_content?: string,
 *     first_touch?: bool,
 *     timestamp?: string,
 * }
 */
final readonly class UtmAttribution
{
    /**
     * @param  string|null  $source  Traffic source (e.g. 'google', 'newsletter')
     * @param  string|null  $medium  Marketing medium (e.g. 'cpc', 'email', 'organic')
     * @param  string|null  $campaign  Campaign name (e.g. 'spring_sale_2026')
     * @param  string|null  $term  Paid search keyword
     * @param  string|null  $content  Ad content variant (A/B test, creative ID)
     * @param  bool  $firstTouch  Whether this is the user's first visit (first-touch attribution)
     * @param  string|null  $timestamp  ISO 8601 timestamp when UTM was captured
     * @param  string|null  $referrer  Referrer URL when UTM was captured
     * @param  string|null  $landingPage  Landing page URL when UTM was captured
     */
    public function __construct(
        public ?string $source = null,
        public ?string $medium = null,
        public ?string $campaign = null,
        public ?string $term = null,
        public ?string $content = null,
        public bool $firstTouch = false,
        public ?string $timestamp = null,
        public ?string $referrer = null,
        public ?string $landingPage = null,
    ): void {}

    /**
     * Create UtmAttribution from a request parameter array.
     *
     * @param  array<string, mixed>  $params  Typically from $request->only([...utm keys...])
     * @param  bool  $firstTouch  Whether this is the first visit
     * @param  string|null  $referrer  Referrer URL
     * @param  string|null  $landingPage  Landing page URL
     */
    public static function fromRequest(
        array $params,
        bool $firstTouch = false,
        ?string $referrer = null,
        ?string $landingPage = null,
    ): self {
        return new self(
            source: is_string($params['utm_source'] ?? null) && $params['utm_source'] !== ''
                ? $params['utm_source'] : null,
            medium: is_string($params['utm_medium'] ?? null) && $params['utm_medium'] !== ''
                ? $params['utm_medium'] : null,
            campaign: is_string($params['utm_campaign'] ?? null) && $params['utm_campaign'] !== ''
                ? $params['utm_campaign'] : null,
            term: is_string($params['utm_term'] ?? null) && $params['utm_term'] !== ''
                ? $params['utm_term'] : null,
            content: is_string($params['utm_content'] ?? null) && $params['utm_content'] !== ''
                ? $params['utm_content'] : null,
            firstTouch: $firstTouch,
            timestamp: (\function_exists('now') ? now() : new \DateTimeImmutable())->format('c'),
            referrer: $referrer,
            landingPage: $landingPage,
        );
    }

    /**
     * Check if any UTM parameters are present.
     */
    public function hasAttribution(): bool
    {
        return $this->source !== null
            || $this->medium !== null
            || $this->campaign !== null
            || $this->term !== null
            || $this->content !== null;
    }

    /**
     * Convert to array representation for event parameters.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'utm_source' => $this->source,
            'utm_medium' => $this->medium,
            'utm_campaign' => $this->campaign,
            'utm_term' => $this->term,
            'utm_content' => $this->content,
            'utm_first_touch' => $this->firstTouch,
            'utm_timestamp' => $this->timestamp,
            'utm_referrer' => $this->referrer,
            'utm_landing_page' => $this->landingPage,
        ], fn (mixed $v): bool => $v !== null && $v !== false);
    }

    /**
     * Convert to a serialized string for storage (session/cache).
     */
    public function toString(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * Restore from serialized string.
     */
    public static function fromString(string $serialized): self
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($serialized, true, 512, JSON_THROW_ON_ERROR);

            return new self(
                source: is_string($data['utm_source'] ?? null) ? $data['utm_source'] : null,
                medium: is_string($data['utm_medium'] ?? null) ? $data['utm_medium'] : null,
                campaign: is_string($data['utm_campaign'] ?? null) ? $data['utm_campaign'] : null,
                term: is_string($data['utm_term'] ?? null) ? $data['utm_term'] : null,
                content: is_string($data['utm_content'] ?? null) ? $data['utm_content'] : null,
                firstTouch: (bool) ($data['utm_first_touch'] ?? false),
                timestamp: is_string($data['utm_timestamp'] ?? null) ? $data['utm_timestamp'] : null,
                referrer: is_string($data['utm_referrer'] ?? null) ? $data['utm_referrer'] : null,
                landingPage: is_string($data['utm_landing_page'] ?? null) ? $data['utm_landing_page'] : null,
            );
        } catch (\Throwable) {
            return new self;
        }
    }

    /**
     * Get a human-readable attribution string.
     */
    public function describe(): string
    {
        if (! $this->hasAttribution()) {
            return 'direct / none';
        }

        $parts = array_filter([
            $this->source,
            $this->medium !== null ? "/{$this->medium}" : null,
            $this->campaign !== null ? " ({$this->campaign})" : null,
        ]);

        return implode('', $parts);
    }
}
