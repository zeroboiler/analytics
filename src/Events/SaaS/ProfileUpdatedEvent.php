<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a user profile update.
 *
 * GA4: profile_updated (custom)
 * Meta: ProfileUpdated (custom)
 */
final readonly class ProfileUpdatedEvent extends AnalyticsEvent
{
    /**
     * @param  list<string>  $fields  List of updated field names
     * @param  array<string, mixed>  $metadata  Additional context
     */
    public function __construct(array $fields = [], array $metadata = []): void
: void {
        parent::__construct('profile_updated', array_filter([
            'fields' => $fields,
            'fields_count' => count($fields),
            ...$metadata,
        ], fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []));
    }
}
