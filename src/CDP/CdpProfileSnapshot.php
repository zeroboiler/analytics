<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\CDP;

/**
 * Immutable snapshot of a CDP user profile at a point in time.
 *
 * Contains all user traits (static + computed), segment memberships,
 * identity metadata, and computed engagement/revenue scores.
 *
 * Used as the canonical representation of a user's analytics profile
 * for export to providers (GA4 user properties, PostHog person traits,
 * Mixpanel user profiles) and for segment evaluation.
 *
 * @since 196.0.0
 */
final readonly class CdpProfileSnapshot
{
    /**
     * Create a new profile snapshot.
     *
     * @param  string  $userId  Authenticated user ID
     * @param  string|null  $anonymousId  Browser/device fingerprint
     * @param  string|null  $email  User email (for provider matching)
     * @param  array<string, mixed>  $traits  All user traits (static + computed)
     * @param  list<string>  $segments  Active segment membership names
     * @param  int|null  $createdAt  Profile creation timestamp
     * @param  int|null  $updatedAt  Last update timestamp
     * @param  int|null  $lastEventAt  Last tracked event timestamp
     * @param  int  $totalEvents  Total events tracked for this user
     * @param  int  $totalSessions  Total sessions for this user
     */
    public function __construct(
        public string $userId,
        public ?string $anonymousId = null,
        public ?string $email = null,
        public array $traits = [],
        public array $segments = [],
        public ?int $createdAt = null,
        public ?int $updatedAt = null,
        public ?int $lastEventAt = null,
        public int $totalEvents = 0,
        public int $totalSessions = 0,
    ): void {}

    /**
     * Get a trait value with fallback to default.
     *
     * @param  string  $name  Trait name
     * @param  mixed|null  $default  Default if trait doesn't exist
     * @return mixed
     */
    public function getTrait(string $name, mixed $default = null): mixed
    {
        return $this->traits[$name] ?? $default;
    }

    /**
     * Check if user is a member of a segment.
     *
     * @param  string  $segmentName  Segment name
     * @return bool
     */
    public function isInSegment(string $segmentName): bool
    {
        return in_array($segmentName, $this->segments, true);
    }

    /**
     * Get the days since first activity (profile age).
     *
     * @return int|null  Days since creation, or null if no creation time
     */
    public function daysSinceCreation(): ?int
    {
        if ($this->createdAt === null) {
            return null;
        }

        return (int) floor((time() - $this->createdAt) / 86400);
    }

    /**
     * Get the days since last activity.
     *
     * @return int|null  Days since last event, or null if no events
     */
    public function daysSinceLastActivity(): ?int
    {
        if ($this->lastEventAt === null) {
            return null;
        }

        return (int) floor((time() - $this->lastEventAt) / 86400);
    }

    /**
     * Get the engagement score (events per day since creation).
     *
     * @return float|null  Engagement score, or null if insufficient data
     */
    public function engagementScore(): ?float
    {
        $days = $this->daysSinceCreation();
        if ($days === null || $days < 1) {
            return null;
        }

        return round($this->totalEvents / $days, 2);
    }

    /**
     * Serialize to array for export/provider sync.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'anonymous_id' => $this->anonymousId,
            'email' => $this->email,
            'traits' => $this->traits,
            'segments' => $this->segments,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'last_event_at' => $this->lastEventAt,
            'total_events' => $this->totalEvents,
            'total_sessions' => $this->totalSessions,
            'engagement_score' => $this->engagementScore(),
            'days_since_creation' => $this->daysSinceCreation(),
            'days_since_last_activity' => $this->daysSinceLastActivity(),
        ];
    }

    /**
     * Export traits only for provider user property sync.
     *
     * Returns a flat key-value map suitable for GA4 user_properties,
     * PostHog $set, Mixpanel people.set.
     *
     * @return array<string, mixed>
     */
    public function toProviderTraits(): array
    {
        return $this->traits;
    }

    /**
     * Create from stored array data.
     *
     * @param  array<string, mixed>  $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            userId: (string) ($data['user_id'] ?? ''),
            anonymousId: isset($data['anonymous_id']) ? (string) $data['anonymous_id'] : null,
            email: isset($data['email']) ? (string) $data['email'] : null,
            traits: (array) ($data['traits'] ?? []),
            segments: (array) ($data['segments'] ?? []),
            createdAt: isset($data['created_at']) ? (int) $data['created_at'] : null,
            updatedAt: isset($data['updated_at']) ? (int) $data['updated_at'] : null,
            lastEventAt: isset($data['last_event_at']) ? (int) $data['last_event_at'] : null,
            totalEvents: (int) ($data['total_events'] ?? 0),
            totalSessions: (int) ($data['total_sessions'] ?? 0),
        );
    }
}
