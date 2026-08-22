<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\EventTags;

/**
 * Advanced event catalog explorer with fuzzy search and intelligent recommendations.
 *
 * Provides a developer-friendly search engine for the analytics event catalog.
 * Goes beyond basic string matching with:
 *
 * - **Fuzzy search**: finds events even with typos or partial matches using
 *   Levenshtein-inspired similarity scoring
 * - **Tag-based filtering**: search by AARRR stage, GDPR relevance, revenue impact
 * - **Provider coverage analysis**: find events mapped to specific providers
 * - **Category-aware ranking**: boost results by category relevance
 * - **Developer recommendations**: suggest events based on use case descriptions
 * - **Similar event discovery**: find events related to a given event name
 *
 * Use cases:
 * - "What events should I track for signups?" → SaaS lifecycle events
 * - "Which events does Meta Pixel support?" → provider-filtered results
 * - "Show me revenue-related events" → tag-filtered results
 * - "Events similar to purchase" → similarity-based discovery
 *
 * Configuration: `zeroboiler.analytics.catalog_explorer`
 *
 * @see \ZeroBoiler\Analytics\Events\EventCatalog
 * @see \ZeroBoiler\Analytics\Events\EventTags
 *
 * @since 190.0.0
 */
final class EventCatalogExplorerService
{
    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'zb_catalog_explorer_';

    /** @var int Default cache TTL (30 minutes) */
    private const DEFAULT_TTL = 1800;

    /** @var int Maximum Levenshtein distance for fuzzy matching */
    private const MAX_FUZZY_DISTANCE = 3;

    /** @var int Minimum similarity score (0-100) for fuzzy results */
    private const MIN_SIMILARITY = 50;

    /** @var array<string, list<string>> Use-case keyword → relevant event keywords */
    private const USE_CASE_KEYWORDS = [
        'signup' => ['sign_up', 'registration', 'account_activated', 'email_verified', 'onboarding'],
        'login' => ['login', 'logout', 'session_start', 'session_end', 'auth'],
        'trial' => ['start_trial', 'trial_converted', 'trial_expired', 'trial_end'],
        'subscription' => ['subscribe', 'subscription_created', 'subscription_renewal', 'plan_upgrade', 'plan_downgrade', 'cancellation'],
        'payment' => ['payment_succeeded', 'payment_failed', 'payment_method_added', 'add_payment_info', 'invoice_generated'],
        'revenue' => ['revenue_tracked', 'purchase', 'subscription_created', 'plan_upgrade', 'expansion_revenue', 'contraction_revenue'],
        'engagement' => ['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit', 'search', 'share'],
        'retention' => ['session_start', 'feature_used', 'retention_cohort', 'retention_risk', 'health_score_changed'],
        'referral' => ['share', 'invite_sent', 'invite_accepted', 'referral_conversion'],
        'funnel' => ['view_item', 'add_to_cart', 'begin_checkout', 'purchase', 'sign_up', 'start_trial', 'subscribe'],
        'ecommerce' => ['view_item', 'add_to_cart', 'view_cart', 'begin_checkout', 'purchase', 'refund', 'remove_from_cart'],
        'checkout' => ['view_cart', 'begin_checkout', 'add_payment_info', 'purchase', 'checkout_step', 'checkout_abandon'],
        'gdpr' => ['consent_granted', 'consent_withdrawn', 'data_subject_access_request', 'data_erasure_completed'],
        'performance' => ['web_vitals', 'performance_score', 'timing', 'js_error', 'client_error'],
        'onboarding' => ['onboarding_started', 'onboarding_step', 'onboarding_completed', 'first_value', 'activation'],
        'churn' => ['cancellation', 'retention_risk', 'churn_interview', 'subscription_cancelled', 'trial_expired'],
        'team' => ['team_created', 'team_member_joined', 'team_member_removed', 'role_changed', 'invite_sent'],
        'email' => ['email_opened', 'email_clicked', 'email_sent', 'email_bounced', 'email_delivered', 'email_unsubscribed'],
        'error' => ['error', 'js_error', 'client_error', 'api_rate_limited', 'suspicious_activity', 'payment_failed'],
    ];

    private bool $enabled;
    private int $cacheTtl;
    private int $maxResults;
    private int $fuzzySensitivity;
    private CacheRepository $cache;
    private ConfigRepository $config;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;
        $this->config = $config;

        $cfg = $config->get('zeroboiler.analytics.catalog_explorer', []);

        /** @var array{enabled?: bool, cache_ttl?: int, max_results?: int, fuzzy_sensitivity?: int} $cfg */

        $this->enabled = (bool) ($cfg['enabled'] ?? true);
        $this->cacheTtl = (int) ($cfg['cache_ttl'] ?? self::DEFAULT_TTL);
        $this->maxResults = (int) ($cfg['max_results'] ?? 50);
        $this->fuzzySensitivity = (int) ($cfg['fuzzy_sensitivity'] ?? self::MAX_FUZZY_DISTANCE);
    }

    /**
     * Search events by query string with fuzzy matching.
     *
     * Performs a ranked search across event names, returning results
     * sorted by relevance. Supports:
     * - Exact matches (highest rank)
     * - Prefix matches (high rank)
     * - Contains matches (medium rank)
     * - Fuzzy/Levenshtein matches (lower rank, configurable threshold)
     *
     * @param  string  $query  Search query (event name, keyword, or description)
     * @param  array{category?: string, provider?: string, tags?: list<string>, min_similarity?: int, limit?: int}  $options
     * @return array{query: string, results: list<array{name: string, category: string, similarity: float, match_type: string, providers: array<string, string|null>, tags: list<string>}>, total: int, filters: array<string, mixed>}
     */
    public function search(string $query, array $options = []): array
    {
        $category = $options['category'] ?? null;
        $provider = $options['provider'] ?? null;
        $tags = $options['tags'] ?? [];
        $minSimilarity = $options['min_similarity'] ?? self::MIN_SIMILARITY;
        $limit = $options['limit'] ?? $this->maxResults;

        $query = strtolower(trim($query));
        $catalog = EventCatalog::all();
        $results = [];

        foreach ($catalog as $name => $entry) {
            // Category filter
            if ($category !== null && ($entry['category'] ?? '') !== $category) {
                continue;
            }

            // Tag filter
            if ($tags !== []) {
                $eventTags = EventTags::for($name);
                $hasTag = false;

                foreach ($tags as $tag) {
                    if (in_array($tag, $eventTags, true)) {
                        $hasTag = true;
                        break;
                    }
                }

                if (! $hasTag) {
                    continue;
                }
            }

            // Provider filter
            if ($provider !== null) {
                $mapped = $entry[$provider] ?? null;
                if ($mapped === null || $mapped === '') {
                    continue;
                }
            }

            $similarity = $this->computeSimilarity($name, $query);
            $matchType = $this->matchType($name, $query, $similarity);

            if ($similarity < $minSimilarity) {
                continue;
            }

            $providerMappings = [];
            foreach (['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'] as $p) {
                $providerMappings[$p] = $entry[$p] ?? null;
            }

            $results[] = [
                'name' => $name,
                'category' => $entry['category'] ?? 'unknown',
                'similarity' => round($similarity, 1),
                'match_type' => $matchType,
                'providers' => $providerMappings,
                'tags' => EventTags::for($name),
            ];
        }

        usort($results, fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        return [
            'query' => $query,
            'results' => array_slice($results, 0, $limit),
            'total' => count($results),
            'filters' => array_filter([
                'category' => $category,
                'provider' => $provider,
                'tags' => $tags,
                'min_similarity' => $minSimilarity,
            ]),
        ];
    }

    /**
     * Get recommended events for a specific use case.
     *
     * Maps high-level use case descriptions to relevant events
     * using predefined keyword mappings. Supports natural language
     * descriptions like "track user signups", "ecommerce checkout",
     * "measure churn risk".
     *
     * @param  string  $useCase  Use case description or keyword
     * @param  int  $limit  Maximum events to return
     * @return array{use_case: string, events: list<array{name: string, category: string, priority: int, tags: list<string>, relevance: string}>, matched_keywords: list<string>}
     */
    public function recommend(string $useCase, int $limit = 20): array
    {
        $normalizedUseCase = strtolower(trim($useCase));
        $matchedKeywords = [];
        $allEventScores = [];

        // Match use case against predefined keywords
        foreach (self::USE_CASE_KEYWORDS as $case => $keywords) {
            if (str_contains($normalizedUseCase, $case) || str_contains($case, $normalizedUseCase)) {
                $matchedKeywords[] = $case;

                foreach ($keywords as $keyword) {
                    $entry = EventCatalog::get($keyword);

                    if ($entry !== null) {
                        $allEventScores[$keyword] = ($allEventScores[$keyword] ?? 0) + 2;
                    }
                }
            }
        }

        // Also search individual keywords in the use case
        $words = preg_split('/[\s,]+/', $normalizedUseCase) ?: [];
        foreach ($words as $word) {
            if (strlen($word) < 2) {
                continue;
            }

            foreach (EventCatalog::all() as $name => $entry) {
                if (str_contains($name, $word)) {
                    $allEventScores[$name] = ($allEventScores[$name] ?? 0) + 1;
                }
            }
        }

        $events = [];
        foreach ($allEventScores as $name => $score) {
            $entry = EventCatalog::get($name);
            if ($entry === null) {
                continue;
            }

            $priority = EventCatalog::eventPriorityScore($name);
            $tags = EventTags::for($name);
            $relevance = $score >= 2 ? 'primary' : 'related';

            $events[] = [
                'name' => $name,
                'category' => $entry['category'] ?? 'unknown',
                'priority' => $priority,
                'tags' => $tags,
                'relevance' => $relevance,
                'score' => $score,
            ];
        }

        usort($events, function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            return $b['priority'] <=> $a['priority'];
        });

        return [
            'use_case' => $normalizedUseCase,
            'events' => array_slice($events, 0, $limit),
            'matched_keywords' => array_values(array_unique($matchedKeywords)),
        ];
    }

    /**
     * Find events similar to a given event name.
     *
     * Uses name similarity and catalog relationships (same category,
     * same funnel path, shared tags) to find related events.
     *
     * @param  string  $eventName  Canonical event name
     * @param  int  $limit  Maximum similar events to return
     * @return array{event: string, category: string, similar: list<array{name: string, category: string, similarity: float, shared_tags: list<string>, relationship: string}>}
     */
    public function similar(string $eventName, int $limit = 10): array
    {
        $entry = EventCatalog::get($eventName);

        if ($entry === null) {
            return [
                'event' => $eventName,
                'category' => 'unknown',
                'similar' => [],
            ];
        }

        $category = $entry['category'] ?? 'unknown';
        $sourceTags = EventTags::for($eventName);
        $ancestors = EventCatalog::causalAncestors($eventName, 2);
        $descendants = EventCatalog::causalDescendants($eventName, 2);

        $similar = [];
        foreach (EventCatalog::all() as $name => $otherEntry) {
            if ($name === $eventName) {
                continue;
            }

            $otherCategory = $otherEntry['category'] ?? 'unknown';
            $otherTags = EventTags::for($name);

            $score = 0.0;
            $relationship = '';

            // Same category bonus
            if ($otherCategory === $category) {
                $score += 30.0;
                $relationship = 'same_category';
            }

            // Shared tags bonus
            $sharedTags = array_values(array_intersect($sourceTags, $otherTags));
            $score += count($sharedTags) * 10.0;

            // Causal relationship bonus
            if (in_array($name, $ancestors, true)) {
                $score += 40.0;
                $relationship = $relationship === '' ? 'causal_ancestor' : $relationship . '+ancestor';
            } elseif (in_array($name, $descendants, true)) {
                $score += 40.0;
                $relationship = $relationship === '' ? 'causal_descendant' : $relationship . '+descendant';
            }

            // Name similarity bonus
            $nameSimilarity = $this->computeSimilarity($name, $eventName);
            $score += $nameSimilarity * 0.2;

            if ($score < 20.0) {
                continue;
            }

            $similar[] = [
                'name' => $name,
                'category' => $otherCategory,
                'similarity' => round(min(100.0, $score), 1),
                'shared_tags' => $sharedTags,
                'relationship' => $relationship,
            ];
        }

        usort($similar, fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        return [
            'event' => $eventName,
            'category' => $category,
            'similar' => array_slice($similar, 0, $limit),
        ];
    }

    /**
     * Get provider coverage analysis for a specific provider.
     *
     * Shows which events are mapped to a given provider, grouped
     * by category, with coverage statistics.
     *
     * @param  string  $provider  Provider name (ga4, meta, posthog, etc.)
     * @return array{provider: string, total_mapped: int, total_events: int, coverage_percent: float, by_category: array<string, array{mapped: int, total: int, coverage: float, events: list<string>}>, unmapped_events: list<string>}
     */
    public function providerCoverage(string $provider): array
    {
        $catalogKey = $this->mapCatalogKey($provider);
        $catalog = EventCatalog::all();
        $totalEvents = count($catalog);
        $mapped = [];
        $unmapped = [];
        $byCategory = [];

        foreach ($catalog as $name => $entry) {
            $category = $entry['category'] ?? 'unknown';
            $value = $entry[$catalogKey] ?? null;

            if ($value !== null && $value !== '') {
                $mapped[] = $name;

                if (! isset($byCategory[$category])) {
                    $byCategory[$category] = ['mapped' => 0, 'total' => 0, 'coverage' => 0.0, 'events' => []];
                }
                $byCategory[$category]['mapped']++;
                $byCategory[$category]['events'][] = $name;
            } else {
                $unmapped[] = $name;
            }

            if (! isset($byCategory[$category])) {
                $byCategory[$category] = ['mapped' => 0, 'total' => 0, 'coverage' => 0.0, 'events' => []];
            }
            $byCategory[$category]['total']++;
        }

        // Compute coverage per category
        foreach ($byCategory as $cat => &$data) {
            $data['coverage'] = $data['total'] > 0
                ? round(($data['mapped'] / $data['total']) * 100, 1)
                : 0.0;
        }

        return [
            'provider' => $provider,
            'total_mapped' => count($mapped),
            'total_events' => $totalEvents,
            'coverage_percent' => $totalEvents > 0
                ? round((count($mapped) / $totalEvents) * 100, 1)
                : 0.0,
            'by_category' => $byCategory,
            'unmapped_events' => $unmapped,
        ];
    }

    /**
     * Get a tag-based overview of the event catalog.
     *
     * Groups all events by their assigned tags, providing a tag-level
     * view of instrumentation coverage.
     *
     * @return array{tags: array<string, list<array{name: string, category: string, priority: int}>>, total_tags: int, most_common: list<string>, least_common: list<string>}
     */
    public function tagOverview(): array
    {
        $tagGroups = [];
        $tagCounts = [];

        foreach (EventCatalog::all() as $name => $entry) {
            $tags = EventTags::for($name);
            $priority = EventCatalog::eventPriorityScore($name);

            foreach ($tags as $tag) {
                $tagGroups[$tag][] = [
                    'name' => $name,
                    'category' => $entry['category'] ?? 'unknown',
                    'priority' => $priority,
                ];
                $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
            }
        }

        arsort($tagCounts);
        $allTags = array_keys($tagCounts);
        $mostCommon = array_slice($allTags, 0, 10);
        $leastCommon = array_slice($allTags, -10);

        return [
            'tags' => $tagGroups,
            'total_tags' => count($allTags),
            'most_common' => $mostCommon,
            'least_common' => $leastCommon,
        ];
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get statistics about the catalog explorer.
     *
     * @return array{enabled: bool, total_events: int, total_categories: int, total_tags: int, use_cases_supported: int, max_results: int, fuzzy_sensitivity: int}
     */
    public function stats(): array
    {
        return [
            'enabled' => $this->enabled,
            'total_events' => EventCatalog::count(),
            'total_categories' => count(EventCatalog::byCategory()),
            'total_tags' => count(EventTags::allTags()),
            'use_cases_supported' => count(self::USE_CASE_KEYWORDS),
            'max_results' => $this->maxResults,
            'fuzzy_sensitivity' => $this->fuzzySensitivity,
        ];
    }

    /**
     * Compute similarity score between two strings (0-100).
     *
     * Uses a combination of exact match, prefix match, contains match,
     * and Levenshtein distance for fuzzy matching.
     */
    private function computeSimilarity(string $subject, string $query): float
    {
        // Exact match → 100
        if ($subject === $query) {
            return 100.0;
        }

        // Prefix match → 90-99
        if (str_starts_with($subject, $query)) {
            return 90.0 + (strlen($query) / strlen($subject)) * 10.0;
        }

        // Contains match → 70-89
        if (str_contains($subject, $query)) {
            return 70.0 + (strlen($query) / strlen($subject)) * 20.0;
        }

        // Word-level match (query words found in subject words)
        $queryWords = explode('_', $query);
        $subjectWords = explode('_', $subject);
        $matchedWords = 0;

        foreach ($queryWords as $qw) {
            foreach ($subjectWords as $sw) {
                if ($sw === $qw || str_contains($sw, $qw)) {
                    $matchedWords++;
                    break;
                }
            }
        }

        if ($matchedWords > 0 && count($queryWords) > 0) {
            return 50.0 + ($matchedWords / count($queryWords)) * 40.0;
        }

        // Levenshtein distance for fuzzy matching
        $distance = $this->levenshtein($subject, $query);
        $maxLen = max(strlen($subject), strlen($query), 1);

        if ($distance <= $this->fuzzySensitivity) {
            return max(0.0, 100.0 - (($distance / $maxLen) * 100.0));
        }

        return 0.0;
    }

    /**
     * Determine the match type for display purposes.
     */
    private function matchType(string $subject, string $query, float $similarity): string
    {
        if ($subject === $query) {
            return 'exact';
        }

        if (str_starts_with($subject, $query)) {
            return 'prefix';
        }

        if (str_contains($subject, $query)) {
            return 'contains';
        }

        if ($similarity >= 70.0) {
            return 'fuzzy';
        }

        return 'word_match';
    }

    /**
     * Compute Levenshtein distance between two strings.
     *
     * Optimized implementation using only two rows for memory efficiency.
     */
    private function levenshtein(string $a, string $b): int
    {
        $lenA = strlen($a);
        $lenB = strlen($b);

        if ($lenA === 0) {
            return $lenB;
        }

        if ($lenB === 0) {
            return $lenA;
        }

        $prevRow = range(0, $lenB);

        for ($i = 0; $i < $lenA; $i++) {
            $currentRow = [$i + 1];

            for ($j = 0; $j < $lenB; $j++) {
                $cost = ($a[$i] === $b[$j]) ? 0 : 1;
                $currentRow[] = min(
                    $currentRow[$j] + 1,       // insertion
                    $prevRow[$j + 1] + 1,      // deletion
                    $prevRow[$j] + $cost,       // substitution
                );
            }

            $prevRow = $currentRow;
        }

        return $prevRow[$lenB];
    }

    /**
     * Map provider name to EventCatalog key.
     */
    private function mapCatalogKey(string $provider): string
    {
        return match ($provider) {
            'meta_pixel' => 'meta',
            default => $provider,
        };
    }

    /**
     * Clear cached catalog exploration results.
     */
    public function clearCache(): void
    {
        $keys = ['search', 'recommend', 'similar', 'coverage', 'tags'];
        foreach ($keys as $key) {
            $this->cache->forget(self::CACHE_PREFIX . $key);
        }
    }
}
