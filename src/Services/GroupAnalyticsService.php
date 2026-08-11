<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * B2B Group/Account Analytics Service.
 *
 * Implements the industry-standard "group" analytics pattern used by
 * Segment, Mixpanel, and Amplitude. In B2B SaaS applications, users
 * belong to organizations/accounts/companies, and analytics events need
 * to be associated with both the individual user AND the group.
 *
 * This service manages:
 * - Group identification (company/organization properties)
 * - User ↔ Group membership mapping
 * - Group-level event aggregation
 * - Per-group trait/property storage
 * - Account-level revenue and usage metrics
 *
 * All data is stored in the Laravel cache with configurable TTL.
 *
 * Inspired by Segment's Group spec:
 * https://segment.com/docs/connections/spec/group/
 *
 * @since 9.5.0
 */
final class GroupAnalyticsService
{
    private const CACHE_PREFIX = 'zb_group_';
    private const MEMBERSHIP_PREFIX = 'zb_group_members_';

    private CacheRepository $cache;

    private int $ttl;

    private int $maxMembersPerGroup;

    private int $maxGroupsPerUser;

    private bool $enabled;

    /**
     * @param  CacheRepository  $cache  Laravel cache repository
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config)
    {
        $this->cache = $cache;
        $groupConfig = $config->get('zeroboiler.analytics.group', []);
        /** @var array{enabled?: bool, ttl?: int, max_members_per_group?: int, max_groups_per_user?: int} $groupConfig */
        $this->enabled = (bool) ($groupConfig['enabled'] ?? true);
        $this->ttl = (int) ($groupConfig['ttl'] ?? 7776000); // 90 days
        $this->maxMembersPerGroup = (int) ($groupConfig['max_members_per_group'] ?? 1000);
        $this->maxGroupsPerUser = (int) ($groupConfig['max_groups_per_user'] ?? 10);
    }

    /**
     * Identify a group with traits/properties.
     *
     * Associates a group (company, organization, account) with its properties
     * such as name, plan, industry, employee count, MRR, etc.
     *
     * @param  string  $groupId  Unique group identifier (e.g. company ID, workspace ID)
     * @param  array<string, mixed>  $traits  Group properties (name, industry, plan, mrr, employee_count, etc.)
     * @param  array<string, mixed>  $context  Additional context (source, timestamp, etc.)
     */
    public function identify(string $groupId, array $traits = [], array $context = []): void
    {
        if (! $this->enabled) {
            return;
        }

        $key = self::CACHE_PREFIX . $groupId;
        $existing = $this->getGroup($groupId);

        $data = [
            'group_id' => $groupId,
            'traits' => array_merge($existing['traits'] ?? [], $traits),
            'context' => $context,
            'updated_at' => now()->toIso8601String(),
            'created_at' => $existing['created_at'] ?? now()->toIso8601String(),
            'member_count' => $existing['member_count'] ?? 0,
        ];

        $this->cache->put($key, $data, $this->ttl);
    }

    /**
     * Add a user to a group.
     *
     * Creates a bidirectional membership mapping between a user and a group.
     * Useful for B2B analytics where you need to query "all events for Acme Corp"
     * or "which groups does this user belong to".
     *
     * @param  string  $userId  User identifier
     * @param  string  $groupId  Group identifier
     * @param  string|null  $role  User's role in the group (admin, member, viewer, owner)
     * @param  array<string, mixed>  $traits  Additional membership traits (department, title, etc.)
     */
    public function addMember(string $userId, string $groupId, ?string $role = null, array $traits = []): void
    {
        if (! $this->enabled) {
            return;
        }

        // Add to group's member list
        $membersKey = self::MEMBERSHIP_PREFIX . 'group_' . $groupId;
        $members = $this->cache->get($membersKey, []);
        /** @var array<string, array{role: string|null, traits: array<string, mixed>, joined_at: string}> $members */

        if (! isset($members[$userId])) {
            if (count($members) >= $this->maxMembersPerGroup) {
                return; // Max capacity
            }

            $members[$userId] = [
                'role' => $role,
                'traits' => $traits,
                'joined_at' => now()->toIso8601String(),
            ];
            $this->cache->put($membersKey, $members, $this->ttl);

            // Increment member count
            $groupKey = self::CACHE_PREFIX . $groupId;
            $group = $this->cache->get($groupKey);
            if ($group !== null) {
                $group['member_count'] = count($members);
                $this->cache->put($groupKey, $group, $this->ttl);
            }
        }

        // Add to user's group list
        $userGroupsKey = self::MEMBERSHIP_PREFIX . 'user_' . $userId;
        $userGroups = $this->cache->get($userGroupsKey, []);
        /** @var array<string, array{role: string|null, traits: array<string, mixed>, joined_at: string}> $userGroups */

        if (! isset($userGroups[$groupId]) && count($userGroups) < $this->maxGroupsPerUser) {
            $userGroups[$groupId] = [
                'role' => $role,
                'traits' => $traits,
                'joined_at' => now()->toIso8601String(),
            ];
            $this->cache->put($userGroupsKey, $userGroups, $this->ttl);
        }
    }

    /**
     * Remove a user from a group.
     */
    public function removeMember(string $userId, string $groupId): void
    {
        // Remove from group's member list
        $membersKey = self::MEMBERSHIP_PREFIX . 'group_' . $groupId;
        $members = $this->cache->get($membersKey, []);
        /** @var array<string, mixed> $members */
        unset($members[$userId]);
        $this->cache->put($membersKey, $members, $this->ttl);

        // Decrement member count
        $groupKey = self::CACHE_PREFIX . $groupId;
        $group = $this->cache->get($groupKey);
        if ($group !== null) {
            $group['member_count'] = count($members);
            $this->cache->put($groupKey, $group, $this->ttl);
        }

        // Remove from user's group list
        $userGroupsKey = self::MEMBERSHIP_PREFIX . 'user_' . $userId;
        $userGroups = $this->cache->get($userGroupsKey, []);
        /** @var array<string, mixed> $userGroups */
        unset($userGroups[$groupId]);
        $this->cache->put($userGroupsKey, $userGroups, $this->ttl);
    }

    /**
     * Get group properties and metadata.
     *
     * @param  string  $groupId  Group identifier
     * @return array{group_id: string, traits: array<string, mixed>, context: array<string, mixed>, updated_at: string|null, created_at: string|null, member_count: int}
     */
    public function getGroup(string $groupId): array
    {
        /** @var array{group_id?: string, traits?: array<string, mixed>, context?: array<string, mixed>, updated_at?: string, created_at?: string, member_count?: int}|null $data */
        $data = $this->cache->get(self::CACHE_PREFIX . $groupId);

        if ($data === null) {
            return [
                'group_id' => $groupId,
                'traits' => [],
                'context' => [],
                'updated_at' => null,
                'created_at' => null,
                'member_count' => 0,
            ];
        }

        return $data;
    }

    /**
     * Get all groups a user belongs to.
     *
     * @param  string  $userId  User identifier
     * @return array<string, array{role: string|null, traits: array<string, mixed>, joined_at: string}>
     */
    public function getUserGroups(string $userId): array
    {
        /** @var array<string, array{role: string|null, traits: array<string, mixed>, joined_at: string}>|null $groups */
        $groups = $this->cache->get(self::MEMBERSHIP_PREFIX . 'user_' . $userId);

        return $groups ?? [];
    }

    /**
     * Get all members of a group.
     *
     * @param  string  $groupId  Group identifier
     * @return array<string, array{role: string|null, traits: array<string, mixed>, joined_at: string}>
     */
    public function getGroupMembers(string $groupId): array
    {
        /** @var array<string, array{role: string|null, traits: array<string, mixed>, joined_at: string}>|null $members */
        $members = $this->cache->get(self::MEMBERSHIP_PREFIX . 'group_' . $groupId);

        return $members ?? [];
    }

    /**
     * Get the primary group for a user (first group joined).
     *
     * @return string|null Group ID or null if no groups
     */
    public function getPrimaryGroup(string $userId): ?string
    {
        $groups = $this->getUserGroups($userId);

        if (empty($groups)) {
            return null;
        }

        // Return the first group (oldest membership)
        return array_key_first($groups);
    }

    /**
     * Update group traits (merge with existing).
     *
     * Unlike identify() which also handles context, this only updates traits.
     *
     * @param  string  $groupId  Group identifier
     * @param  array<string, mixed>  $traits  New/updated traits to merge
     */
    public function updateTraits(string $groupId, array $traits): void
    {
        $group = $this->getGroup($groupId);
        $group['traits'] = array_merge($group['traits'], $traits);
        $group['updated_at'] = now()->toIso8601String();

        $this->cache->put(self::CACHE_PREFIX . $groupId, $group, $this->ttl);
    }

    /**
     * Set a single group trait (overwrite).
     *
     * @param  string  $groupId  Group identifier
     * @param  string  $key  Trait key
     * @param  mixed  $value  Trait value
     */
    public function setTrait(string $groupId, string $key, mixed $value): void
    {
        $group = $this->getGroup($groupId);
        $group['traits'][$key] = $value;
        $group['updated_at'] = now()->toIso8601String();

        $this->cache->put(self::CACHE_PREFIX . $groupId, $group, $this->ttl);
    }

    /**
     * Increment a numeric group trait.
     *
     * Useful for tracking aggregate metrics like total_logins, total_revenue,
     * api_calls, etc. at the group/account level.
     *
     * @param  string  $groupId  Group identifier
     * @param  string  $key  Trait key (must be numeric)
     * @param  int|float  $amount  Amount to increment (default: 1)
     */
    public function incrementTrait(string $groupId, string $key, int|float $amount = 1): void
    {
        $group = $this->getGroup($groupId);
        $current = $group['traits'][$key] ?? 0;
        $group['traits'][$key] = $current + $amount;
        $group['updated_at'] = now()->toIso8601String();

        $this->cache->put(self::CACHE_PREFIX . $groupId, $group, $this->ttl);
    }

    /**
     * Remove a group and all its membership data.
     */
    public function forgetGroup(string $groupId): void
    {
        $this->cache->forget(self::CACHE_PREFIX . $groupId);

        // Remove all member references
        $members = $this->getGroupMembers($groupId);
        foreach (array_keys($members) as $userId) {
            $this->removeMember($userId, $groupId);
        }

        $this->cache->forget(self::MEMBERSHIP_PREFIX . 'group_' . $groupId);
    }

    /**
     * Remove all group memberships for a user.
     */
    public function forgetUserGroups(string $userId): void
    {
        $groups = $this->getUserGroups($userId);
        foreach (array_keys($groups) as $groupId) {
            $this->removeMember($userId, $groupId);
        }

        $this->cache->forget(self::MEMBERSHIP_PREFIX . 'user_' . $userId);
    }

    /**
     * Get a summary of group analytics state.
     *
     * @return array{enabled: bool, ttl: int, max_members_per_group: int, max_groups_per_user: int}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'ttl' => $this->ttl,
            'max_members_per_group' => $this->maxMembersPerGroup,
            'max_groups_per_user' => $this->maxGroupsPerUser,
        ];
    }
}
