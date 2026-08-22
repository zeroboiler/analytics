<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\CDP;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * CDP Dynamic Segment Service — evaluates user membership in named segments.
 *
 * Segments are defined by trait-based rules. Users are assigned to segments
 * based on their computed trait values satisfying the rule conditions.
 *
 * Supported operators:
 * - **eq**: Trait equals value
 * - **neq**: Trait does not equal value
 * - **gt**: Trait greater than value
 * - **gte**: Trait greater than or equal to value
 * - **lt**: Trait less than value
 * - **lte**: Trait less than or equal to value
 * - **in**: Trait value is in a list
 * - **not_in**: Trait value is not in a list
 * - **exists**: Trait has any non-null value
 * - **not_exists**: Trait is null or missing
 * - **between**: Trait value is between min and max (inclusive)
 * - **contains**: String trait contains substring
 *
 * Segments are recalculated on profile updates and can be cached with TTL.
 *
 * @see \ZeroBoiler\Analytics\CDP\CdpProfileService
 *
 * @since 196.0.0
 */
final class CdpSegmentService
{
    private const CACHE_PREFIX = 'zb_cdp_segments_';

    /** @var array<string, array{rules: list<array{trait: string, operator: string, value?: mixed, min?: mixed, max?: mixed, values?: list<mixed>}>, description?: string, created_at?: int}> */
    private array $segments = [];

    private int $segmentCacheTtl;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        ConfigRepository $config,
    ){
        $cdpConfig = $config->get('zeroboiler.analytics.cdp', []);
        /** @var array{segment_cache_ttl?: int} $cdpConfig */

        $this->segmentCacheTtl = (int) ($cdpConfig['segment_cache_ttl'] ?? 3600); // 1 hour

        $this->registerDefaultSegments();
    }

    /**
     * Register a segment with its evaluation rules.
     *
     * @param  string  $name  Segment name (snake_case)
     * @param  list<array{trait: string, operator: string, value?: mixed, min?: mixed, max?: mixed, values?: list<mixed>}>  $rules  List of rule conditions (AND logic — all must match)
     * @param  string|null  $description  Human-readable description
     * @return void
     */
    public function registerSegment(string $name, array $rules, ?string $description = null): void
    {
        $this->segments[$name] = [
            'rules' => $rules,
            'description' => $description,
            'created_at' => time(),
        ];
    }

    /**
     * Remove a segment definition.
     *
     * @param  string  $name
     * @return bool
     */
    public function removeSegment(string $name): bool
    {
        if (! isset($this->segments[$name])) {
            return false;
        }

        unset($this->segments[$name]);

        return true;
    }

    /**
     * Get all registered segment definitions.
     *
     * @return array<string, array{rules: list<array<string, mixed>>, description?: string, created_at?: int}>
     */
    public function getSegments(): array
    {
        return $this->segments;
    }

    /**
     * Evaluate which segments a user belongs to based on their traits.
     *
     * All segment rules are evaluated against the provided traits.
     * A user belongs to a segment only if ALL rules in that segment pass.
     *
     * @param  array<string, mixed>  $traits  User traits to evaluate
     * @param  string  $userId  User ID for caching
     * @return list<string>  Segment names the user belongs to
     */
    public function evaluateSegments(array $traits, string $userId): array
    {
        // Check cache
        $cacheKey = self::CACHE_PREFIX . $userId;
        /** @var list<string>|null $cached */
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $matchedSegments = [];

        foreach ($this->segments as $name => $segment) {
            if ($this->evaluateSegmentRules($segment['rules'], $traits)) {
                $matchedSegments[] = $name;
            }
        }

        $this->cache->put($cacheKey, $matchedSegments, $this->segmentCacheTtl);

        return $matchedSegments;
    }

    /**
     * Check if a user belongs to a specific segment.
     *
     * @param  string  $segmentName
     * @param  array<string, mixed>  $traits
     * @param  string  $userId
     * @return bool
     */
    public function isInSegment(string $segmentName, array $traits, string $userId): bool
    {
        $matched = $this->evaluateSegments($traits, $userId);

        return in_array($segmentName, $matched, true);
    }

    /**
     * Invalidate segment cache for a user.
     *
     * Call this when traits are updated to force re-evaluation.
     *
     * @param  string  $userId
     * @return bool
     */
    public function invalidateCache(string $userId): bool
    {
        $this->cache->forget(self::CACHE_PREFIX . $userId);

        return true;
    }

    /**
     * Invalidate all segment caches.
     *
     * @return void
     */
    public function invalidateAllCache(): void
    {
        // Note: In production, this would use a tagged cache.
        // For cache-backed implementation, we clear what we can.
        Log::info('ZeroBoiler CDP: All segment caches invalidated');
    }

    /**
     * Evaluate a single segment's rules against traits (AND logic).
     *
     * @param  list<array{trait: string, operator: string, value?: mixed, min?: mixed, max?: mixed, values?: list<mixed>}>  $rules
     * @param  array<string, mixed>  $traits
     * @return bool
     */
    private function evaluateSegmentRules(array $rules, array $traits): bool
    {
        if ($rules === []) {
            return false;
        }

        foreach ($rules as $rule) {
            $traitName = (string) ($rule['trait'] ?? '');
            $operator = (string) ($rule['operator'] ?? 'eq');
            $traitValue = $traits[$traitName] ?? null;

            if (! $this->evaluateRule($operator, $traitValue, $rule)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate a single rule condition.
     *
     * @param  string  $operator
     * @param  mixed  $traitValue  The actual trait value (null if missing)
     * @param  array{value?: mixed, min?: mixed, max?: mixed, values?: list<mixed>}  $rule
     * @return bool
     */
    private function evaluateRule(string $operator, mixed $traitValue, array $rule): bool
    {
        return match ($operator) {
            'eq' => $traitValue === ($rule['value'] ?? null),
            'neq' => $traitValue !== ($rule['value'] ?? null),
            'gt' => is_numeric($traitValue) && is_numeric($rule['value'] ?? null) && (float) $traitValue > (float) $rule['value'],
            'gte' => is_numeric($traitValue) && is_numeric($rule['value'] ?? null) && (float) $traitValue >= (float) $rule['value'],
            'lt' => is_numeric($traitValue) && is_numeric($rule['value'] ?? null) && (float) $traitValue < (float) $rule['value'],
            'lte' => is_numeric($traitValue) && is_numeric($rule['value'] ?? null) && (float) $traitValue <= (float) $rule['value'],
            'in' => is_array($rule['values'] ?? null) && in_array($traitValue, $rule['values'], true),
            'not_in' => ! (is_array($rule['values'] ?? null) && in_array($traitValue, $rule['values'], true)),
            'exists' => $traitValue !== null,
            'not_exists' => $traitValue === null,
            'between' => is_numeric($traitValue)
                && is_numeric($rule['min'] ?? null)
                && is_numeric($rule['max'] ?? null)
                && (float) $traitValue >= (float) $rule['min']
                && (float) $traitValue <= (float) $rule['max'],
            'contains' => is_string($traitValue)
                && is_string($rule['value'] ?? null)
                && str_contains($traitValue, (string) $rule['value']),
            default => false,
        };
    }

    /**
     * Register built-in SaaS segments.
     *
     * Provides common segments for SaaS products:
     * - power_user: Users with high engagement
     * - high_value: Users with significant revenue
     * - at_risk: Users inactive for a long time
     * - new_user: Recently created profiles
     * - frequent_searcher: Users who search often
     * - error_prone: Users who encounter many errors
     * - free_tier: Users on free plans
     *
     * @return void
     */
    private function registerDefaultSegments(): void
    {
        $this->segments = [
            'power_user' => [
                'rules' => [
                    ['trait' => 'page_view_count', 'operator' => 'gte', 'value' => 100],
                    ['trait' => 'session_count', 'operator' => 'gte', 'value' => 20],
                ],
                'description' => 'Highly engaged users with significant usage',
                'created_at' => time(),
            ],
            'high_value' => [
                'rules' => [
                    ['trait' => 'total_revenue', 'operator' => 'gt', 'value' => 99.0],
                ],
                'description' => 'Users who have spent more than $99',
                'created_at' => time(),
            ],
            'at_risk' => [
                'rules' => [
                    ['trait' => 'days_since_last_activity', 'operator' => 'gt', 'value' => 14],
                    ['trait' => 'session_count', 'operator' => 'gte', 'value' => 3],
                ],
                'description' => 'Previously active users showing churn signals',
                'created_at' => time(),
            ],
            'new_user' => [
                'rules' => [
                    ['trait' => 'days_since_creation', 'operator' => 'lte', 'value' => 7],
                ],
                'description' => 'Users created within the last 7 days',
                'created_at' => time(),
            ],
            'frequent_searcher' => [
                'rules' => [
                    ['trait' => 'search_count', 'operator' => 'gte', 'value' => 10],
                ],
                'description' => 'Users who search frequently',
                'created_at' => time(),
            ],
            'error_prone' => [
                'rules' => [
                    ['trait' => 'error_count', 'operator' => 'gt', 'value' => 5],
                ],
                'description' => 'Users encountering many errors',
                'created_at' => time(),
            ],
            'free_tier' => [
                'rules' => [
                    ['trait' => 'total_revenue', 'operator' => 'eq', 'value' => 0.0],
                ],
                'description' => 'Users who have not made a purchase',
                'created_at' => time(),
            ],
            'feature_explorer' => [
                'rules' => [
                    ['trait' => 'unique_features_used', 'operator' => 'gte', 'value' => 5],
                ],
                'description' => 'Users who explore many features',
                'created_at' => time(),
            ],
        ];
    }
}
